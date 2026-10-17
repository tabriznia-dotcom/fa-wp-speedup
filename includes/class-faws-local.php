<?php
/**
 * Controlled downloads and local asset storage.
 *
 * @package FA_WP_Speedup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controlled downloads and local asset storage.
 */
final class FAWS_Local {
	/**
	 * Resolve the same-origin uploads cache directory.
	 *
	 * @throws RuntimeException When uploads cannot be used.
	 *
	 * @return array
	 */
	public static function directory(): array {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) || wp_parse_url( $upload['baseurl'], PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			throw new RuntimeException( 'Uploads unavailable' );
		}
		return array( $upload['basedir'] . '/faws-cache', $upload['baseurl'] . '/faws-cache' );
	}

	/**
	 * Build a deterministic, versioned file path and URL.
	 *
	 * @throws RuntimeException When the catalog entry or uploads are unavailable.
	 *
	 * @param string $id Reviewed catalog identifier.
	 * @return array
	 */
	public static function path( string $id ): array {
		$item = FAWS_Catalog::all()[ $id ] ?? null;
		if ( ! $item ) {
			throw new RuntimeException( 'Unknown resource' );
		}
		list( $dir, $url ) = self::directory();
		$name              = $id . '-' . substr( hash( 'sha256', $item['digest'] ), 0, 16 ) . '.' . $item['type'];
		return array( $dir . '/' . $name, $url . '/' . $name );
	}

	/**
	 * Check local file integrity before using its URL.
	 *
	 * @param string $id Reviewed catalog identifier.
	 * @return bool
	 */
	public static function verified( string $id ): bool {
		try {
			list( $file ) = self::path( $id );
			$item         = FAWS_Catalog::all()[ $id ];
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- SRI requires base64 encoding of cryptographic digests.
			return is_file( $file ) && is_readable( $file ) && ! is_link( $file ) && filesize( $file ) <= 1048576 && hash_equals( $item['digest'], base64_encode( hash_file( $item['algorithm'], $file, true ) ) );
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Install one approved resource in the scheduled worker.
	 *
	 * @throws RuntimeException Internally caught and persisted on installation failure.
	 *
	 * @param string $id Reviewed catalog identifier.
	 * @return void
	 */
	public static function download( string $id ): void {
		$item  = FAWS_Catalog::all()[ $id ] ?? null;
		$state = get_option( 'faws_resources', array() );
		if ( ! $item || empty( $state[ $id ]['consent'] ) ) {
			return;
		}
		$lock  = 'faws_lock_' . $id;
		$stamp = (int) get_option( $lock, 0 );
		if ( $stamp && $stamp < time() - 120 ) {
			delete_option( $lock );
		}
		if ( ! add_option( $lock, time(), '', false ) ) {
			return;
		}
		$tmp    = '';
		$start  = microtime( true );
		$result = array();
		try {
			if ( (int) get_option( 'faws_attempt_' . $id, 0 ) > time() - HOUR_IN_SECONDS ) {
				return;
			}
			// Worker metadata must never overwrite administrator consent or activation.
			update_option( 'faws_attempt_' . $id, time(), false );
			// Exact immutable HTTPS URL, no redirects, safe HTTP API rejects private targets.
			$response = wp_safe_remote_get(
				$item['url'],
				array(
					'timeout'             => 8,
					'redirection'         => 0,
					'sslverify'           => true,
					'limit_response_size' => 1048577,
					'cookies'             => array(),
					'headers'             => array( 'Accept' => 'css' === $item['type'] ? 'text/css' : 'application/javascript' ),
				)
			);
			$elapsed  = round( ( microtime( true ) - $start ) * 1000 );
			if ( is_wp_error( $response ) ) {
				throw new RuntimeException( 'network_error_or_timeout' );
			}
			$body = wp_remote_retrieve_body( $response );
			if ( 200 !== wp_remote_retrieve_response_code( $response ) || ! FAWS_Catalog::valid_body( $body, wp_remote_retrieve_header( $response, 'content-type' ), $item ) ) {
				throw new RuntimeException( 'status_mime_size_or_checksum_rejected' );
			}
			list( $file ) = self::path( $id );
			$dir          = dirname( $file );
			if ( is_link( $dir ) || ! wp_mkdir_p( $dir ) || ! wp_is_writable( $dir ) ) {
				throw new RuntimeException( 'cache_not_writable' );
			}
			// No downloaded filename or extension is accepted from the remote server.
			$tmp = tempnam( $dir, '.faws-' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Atomic same-directory local writes; no remote filesystem or user paths.
			if ( false === $tmp || strlen( $body ) !== file_put_contents( $tmp, $body, LOCK_EX ) ) {
				throw new RuntimeException( 'write_failed' );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Atomic same-directory local writes; no remote filesystem or user paths.
			if ( ! chmod( $tmp, 0644 ) ) {
				throw new RuntimeException( 'permissions_failed' );
			}
			if ( ! is_readable( __DIR__ . '/../licenses/bootstrap-MIT.txt' ) || ! is_readable( __DIR__ . '/../licenses/popper-MIT.txt' ) ) {
				throw new RuntimeException( 'license_unavailable' );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read bundled, reviewed license text; no external URL.
			$license = file_get_contents( __DIR__ . '/../licenses/bootstrap-MIT.txt' );
			if ( 'js' === $item['type'] ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read bundled, reviewed license text; no external URL.
				$license .= "\n" . file_get_contents( __DIR__ . '/../licenses/popper-MIT.txt' );
			}
			$license_tmp = tempnam( $dir, '.faws-license-' );
			if ( ! $license_tmp ) {
				throw new RuntimeException( 'license_write_failed' );
			}
			try {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.WP.AlternativeFunctions.rename_rename -- Atomic same-directory local writes; no remote filesystem or user paths.
				if ( strlen( $license ) !== file_put_contents( $license_tmp, $license, LOCK_EX ) || ! chmod( $license_tmp, 0644 ) || ! rename( $license_tmp, $file . '.LICENSE.txt' ) ) {
					throw new RuntimeException( 'license_write_failed' );
				}
			} finally {
				if ( is_file( $license_tmp ) ) {
					wp_delete_file( $license_tmp );
				}
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode,WordPress.WP.AlternativeFunctions.rename_rename -- SRI requires base64 encoding of cryptographic digests.
			if ( ! hash_equals( $item['digest'], base64_encode( hash_file( $item['algorithm'], $tmp, true ) ) ) || ! rename( $tmp, $file ) ) {
				throw new RuntimeException( 'atomic_install_failed' );
			}
			$result = array(
				'status'     => 'verified',
				'downloaded' => time(),
				'ms'         => $elapsed,
				'slow'       => $elapsed >= 1500,
				'checksum'   => $item['digest'],
			);
		} catch ( Throwable $e ) {
			$result = array(
				'status' => $e->getMessage(),
				'ms'     => round( ( microtime( true ) - $start ) * 1000 ),
			);
		} finally {
			if ( $tmp && is_file( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			if ( $result ) {
				// Re-read: a manager may have revoked consent during the HTTP request.
				$fresh = get_option( 'faws_resources', array() );
				update_option( 'faws_download_' . $id, $result, false );
				if ( empty( $fresh[ $id ]['consent'] ) ) {
					self::remove( $id );
				}
			}
			delete_option( $lock );
		}
	}

	/**
	 * Delete only the exact resource and accompanying license.
	 *
	 * @param string $id Reviewed catalog identifier.
	 * @return void
	 */
	public static function remove( string $id ): void {
		try {
			list( $file ) = self::path( $id );
			foreach ( array( $file, $file . '.LICENSE.txt' ) as $target ) {
				if ( is_file( $target ) && ! is_link( $target ) ) {
					wp_delete_file( $target );
				}
			}
		} catch ( Throwable $e ) {
			return;
		}
	}
}
