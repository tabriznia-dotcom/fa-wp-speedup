<?php
/**
 * Reversible image derivatives and administrator-only image measurements.
 *
 * @package FA_WP_Speedup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Image originals and WordPress attachment metadata are never overwritten. */
final class FAWS_Media {
	/**
	 * Only the first main image receives an explicit priority.
	 *
	 * @var bool
	 */
	private static bool $prioritized = false;

	/**
	 * Register opt-in module hooks.
	 *
	 * @return void
	 */
	public static function boot(): void {
		add_action( 'admin_post_faws_media', array( self::class, 'action' ) );
		add_action( 'faws_media_convert', array( self::class, 'worker' ), 10, 2 );
		add_filter( 'woocommerce_available_variation', array( self::class, 'variation' ), 20, 3 );
		add_filter( 'wp_calculate_image_srcset', array( self::class, 'srcset' ), 20, 5 );
		add_filter( 'wp_get_attachment_image_attributes', array( self::class, 'attributes' ), 20, 3 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'audit_script' ) );
		add_action( 'template_redirect', array( self::class, 'audit_headers' ), 0 );
		add_action( 'wp_ajax_faws_media_audit', array( self::class, 'audit' ) );
		add_action( 'delete_attachment', array( self::class, 'deleted' ) );
	}

	/**
	 * Check whether this request may use public optimizations.
	 *
	 * @return bool
	 */
	public static function eligible(): bool {
		if ( is_admin() || is_user_logged_in() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		foreach ( array( 'is_cart', 'is_checkout', 'is_account_page' ) as $fn ) {
			if ( function_exists( $fn ) && $fn() ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Resolve a writable same-origin derivative directory.
	 *
	 * @throws RuntimeException On invalid local inputs; the worker records failures safely.
	 *
	 * @return array
	 */
	public static function directory(): array {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || wp_parse_url( $uploads['baseurl'], PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			throw new RuntimeException( 'uploads_unavailable_or_offloaded' );
		}
		$dir = $uploads['basedir'] . '/faws-media';
		if ( is_link( $dir ) || ! wp_mkdir_p( $dir ) || ! wp_is_writable( $dir ) ) {
			throw new RuntimeException( 'cache_unavailable' );
		}
		return array( $dir, $uploads['baseurl'] . '/faws-media', $uploads );
	}

	/**
	 * Reject animated or ambiguous PNGs before creating static derivatives.
	 *
	 * @param string $file Validated local PNG source.
	 * @return bool
	 */
	private static function static_png( string $file ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read at most one MiB from an already validated local source, never a URL.
		$head = file_get_contents( $file, false, null, 0, 1048576 );
		if ( ! is_string( $head ) || "\x89PNG\r\n\x1a\n" !== substr( $head, 0, 8 ) ) {
			return false;
		}
		$offset = 8;
		$bytes  = strlen( $head );
		for ( $chunk = 0; $chunk < 64 && $offset + 8 <= $bytes; ++$chunk ) {
			$length = unpack( 'Nlength', substr( $head, $offset, 4 ) )['length'];
			$type   = substr( $head, $offset + 4, 4 );
			if ( 'acTL' === $type ) {
				return false; }
			if ( 'IDAT' === $type ) {
				return true; }
			$offset += 12 + $length;
		}
		return false;
	}

	/**
	 * Validate bounded local source images and existing thumbnail sizes.
	 *
	 * @throws RuntimeException On invalid local inputs; the worker records failures safely.
	 *
	 * @param int $id Attachment identifier.
	 * @return array
	 */
	public static function sources( int $id ): array {
		list( , , $uploads ) = self::directory();
		$meta                = wp_get_attachment_metadata( $id );
		$file                = get_attached_file( $id, true );
		if ( ! is_array( $meta ) || ! $file || ! in_array( get_post_mime_type( $id ), array( 'image/jpeg', 'image/png' ), true ) ) {
			throw new RuntimeException( 'only_local_jpeg_png_supported' );
		}
		$files = array( $file );
		foreach ( array_slice( $meta['sizes'] ?? array(), 0, 11 ) as $size ) {
			if ( ! empty( $size['file'] ) && wp_basename( $size['file'] ) === $size['file'] ) {
				$files[] = dirname( $file ) . '/' . $size['file'];
			}
		}
		$result = array();
		$base   = trailingslashit( wp_normalize_path( (string) realpath( $uploads['basedir'] ) ) );
		foreach ( array_unique( $files ) as $path ) {
			$real = wp_normalize_path( (string) realpath( $path ) );
			if ( ! $real || is_link( $path ) || 0 !== strpos( $real, $base ) || ! is_readable( $real ) || filesize( $real ) > 16777216 ) {
				continue;
			}
			$info = wp_getimagesize( $real );
			if ( ! $info || ! in_array( $info['mime'], array( 'image/jpeg', 'image/png' ), true ) || $info[0] * $info[1] > 12000000 ) {
				continue;
			}
			if ( 'image/png' === $info['mime'] && ! self::static_png( $real ) ) {
				if ( $path === $file ) {
					throw new RuntimeException( 'animated_or_unrecognized_png_not_supported' );
				}
				continue;
			}
			$relative = substr( $real, strlen( $base ) );
			$result[] = array(
				'relative' => $relative,
				'width'    => $info[0],
				'height'   => $info[1],
				'bytes'    => filesize( $real ),
				'mtime'    => filemtime( $real ),
			);
		}
		if ( ! $result ) {
			throw new RuntimeException( 'no_safe_local_sources_under_limits' );
		}
		return $result;
	}

	/**
	 * Schedule a new, explicitly approved conversion generation.
	 *
	 * @param int    $id Attachment identifier.
	 * @param string $format Supported derivative extension.
	 * @param int    $quality Encoding quality, clamped to the supported range.
	 * @return bool
	 */
	public static function queue( int $id, string $format, int $quality ): bool {
		if ( ! in_array( $format, array( 'webp', 'avif' ), true ) || ! wp_image_editor_supports( array( 'mime_type' => 'image/' . $format ) ) ) {
			return false;
		}
		try {
			$sources = self::sources( $id );
		} catch ( Throwable $e ) {
			return false;
		}
		$token    = wp_generate_uuid4();
		$previous = get_post_meta( $id, '_faws_media_consent', true );
		if ( is_array( $previous ) && ! empty( $previous['token'] ) ) {
			wp_clear_scheduled_hook( 'faws_media_convert', array( $id, $previous['token'] ) );
		}
		update_post_meta(
			$id,
			'_faws_media_consent',
			array(
				'token'  => $token,
				'format' => $format,
				'active' => false,
			)
		);
		update_post_meta(
			$id,
			'_faws_media_job',
			array(
				'token'   => $token,
				'format'  => $format,
				'quality' => max( 60, min( 90, $quality ) ),
				'sources' => $sources,
				'cursor'  => 0,
				'status'  => 'queued',
				'files'   => array(),
				'skipped' => 0,
			)
		);
		return (bool) wp_schedule_single_event( time() + 5, 'faws_media_convert', array( $id, $token ) );
	}

	/**
	 * Compare the active administrator approval with a job generation.
	 *
	 * @param int    $id Attachment identifier.
	 * @param string $token Opaque approval generation identifier.
	 * @return bool
	 */
	private static function consent( int $id, string $token ): bool {
		$consent = get_post_meta( $id, '_faws_media_consent', true );
		return is_array( $consent ) && ! empty( $consent['token'] ) && hash_equals( $consent['token'], $token );
	}

	/**
	 * Process at most two images and persist only worker metadata.
	 *
	 * @throws RuntimeException On invalid local inputs; the worker records failures safely.
	 *
	 * @param int    $id Attachment identifier.
	 * @param string $token Opaque approval generation identifier.
	 * @return void
	 */
	public static function worker( int $id, string $token ): void {
		if ( ! self::consent( $id, $token ) ) {
			return;
		}
		$lock = 'faws_media_lock_' . $id;
		if ( (int) get_option( $lock, PHP_INT_MAX ) < time() - 120 ) {
			delete_option( $lock );
		}
		if ( ! add_option( $lock, time(), '', false ) ) {
			wp_schedule_single_event( time() + 130, 'faws_media_convert', array( $id, $token ) );
			return;
		}
		$job     = get_post_meta( $id, '_faws_media_job', true );
		$created = array();
		try {
			if ( ! is_array( $job ) || ( $job['token'] ?? '' ) !== $token ) {
				return;
			}
			list( $dir, , $uploads ) = self::directory();
			$start                   = microtime( true );
			$source_count            = count( $job['sources'] );
			for ( $batch = 0; $batch < 2 && $job['cursor'] < $source_count; ++$batch ) {
				if ( ! self::consent( $id, $token ) ) {
					return;
				}
				$source  = $job['sources'][ $job['cursor'] ];
				$file    = $uploads['basedir'] . '/' . $source['relative'];
				$current = self::sources( $id );
				if ( ! in_array( $source, $current, true ) ) {
					throw new RuntimeException( 'source_changed_queue_again' );
				}
				$memory = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
				if ( $memory > 0 && $source['width'] * $source['height'] * 8 > ( $memory - memory_get_usage( true ) ) / 2 ) {
					throw new RuntimeException( 'insufficient_memory_for_conversion' );
				}
				$editor = wp_get_image_editor( $file );
				if ( is_wp_error( $editor ) ) {
					throw new RuntimeException( 'image_editor_unavailable' );
				}
				$editor->set_quality( $job['quality'] );
				$seed = wp_tempnam( 'faws', trailingslashit( $dir ) );
				if ( ! $seed || wp_normalize_path( dirname( $seed ) ) !== wp_normalize_path( $dir ) ) {
					throw new RuntimeException( 'temporary_file_failed' );
				}
				$tmp = $seed . '.' . $job['format'];
				try {
					$saved      = $editor->save( $tmp, 'image/' . $job['format'] );
					$saved_path = is_array( $saved ) ? ( $saved['path'] ?? '' ) : '';
					if ( is_wp_error( $saved ) || $tmp !== $saved_path ) {
						throw new RuntimeException( 'conversion_failed' );
					}
					$info          = wp_getimagesize( $tmp );
					$expected_mime = 'image/' . $job['format'];
					if ( ! $info || $info['mime'] !== $expected_mime || $info[0] !== $source['width'] || $info[1] !== $source['height'] ) {
						throw new RuntimeException( 'invalid_converted_dimensions_or_mime' );
					}
					if ( filesize( $tmp ) < $source['bytes'] && self::consent( $id, $token ) ) {
						$name = $id . '-' . substr( hash( 'sha256', $token . $source['relative'] ), 0, 32 ) . '.' . $job['format'];
						// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.WP.AlternativeFunctions.rename_rename -- Atomic install within the dedicated local cache.
						if ( ! chmod( $tmp, 0644 ) || ! rename( $tmp, $dir . '/' . $name ) ) {
							throw new RuntimeException( 'atomic_install_failed' );
						}
						$created[]                           = $dir . '/' . $name;
						$source['file']                      = $name;
						$source['hash']                      = hash_file( 'sha256', $dir . '/' . $name );
						$source['converted_bytes']           = filesize( $dir . '/' . $name );
						$job['files'][ $source['relative'] ] = $source;
					} else {
						++$job['skipped'];
					}
				} finally {
					unset( $editor );
					wp_delete_file( $seed );
					if ( is_file( $tmp ) ) {
						wp_delete_file( $tmp );
					}
				}
				++$job['cursor'];
				if ( microtime( true ) - $start > 12 ) {
					break;
				}
			}
			$job['status']  = $job['cursor'] >= count( $job['sources'] ) ? 'complete' : 'queued';
			$job['updated'] = time();
		} catch ( Throwable $e ) {
			$job['status'] = $e->getMessage();
		} finally {
			if ( ! self::consent( $id, $token ) ) {
				foreach ( $created as $path ) {
					wp_delete_file( $path );
				}
			}
			if ( self::consent( $id, $token ) && is_array( $job ) && ( $job['token'] ?? '' ) === $token ) {
				update_post_meta( $id, '_faws_media_job', $job );
				if ( 'queued' === $job['status'] ) {
					wp_schedule_single_event( time() + 5, 'faws_media_convert', array( $id, $token ) );
				}
			}
			delete_option( $lock );
		}
	}

	/**
	 * Approve a completed WebP generation for frontend serving.
	 *
	 * @param int $id Attachment identifier.
	 * @return bool
	 */
	public static function activate( int $id ): bool {
		$consent = get_post_meta( $id, '_faws_media_consent', true );
		$job     = get_post_meta( $id, '_faws_media_job', true );
		if ( ! is_array( $consent ) || ! is_array( $job ) || 'complete' !== ( $job['status'] ?? '' ) || ! self::consent( $id, $job['token'] ) || 'webp' !== $job['format'] || empty( $job['files'] ) ) {
			return false;
		}
		try {
			list( $dir, , $uploads ) = self::directory();
			foreach ( $job['files'] as $entry ) {
				if ( ! self::verified_entry( $id, $entry, $uploads, $dir ) ) {
					return false;
				}
			}
		} catch ( Throwable $e ) {
			return false;
		}
		if ( ! empty( $consent['active'] ) && get_post_meta( $id, '_faws_media_files', true ) === $job['files'] ) {
			return true;
		}
		$old = get_post_meta( $id, '_faws_media_files', true );
		update_post_meta( $id, '_faws_media_previous_files', is_array( $old ) ? $old : array() );
		update_post_meta( $id, '_faws_media_files', $job['files'] );
		$consent['active'] = true;
		update_post_meta( $id, '_faws_media_consent', $consent );
		self::prune( $id );
		return true;
	}

	/**
	 * Remove only recognized derivative files outside the retained generations.
	 *
	 * @param int  $id Attachment identifier.
	 * @param bool $all Whether every recognized owned derivative may be removed.
	 * @return void
	 */
	private static function prune( int $id, bool $all = false ): void {
		try {
			list( $dir ) = self::directory();
		} catch ( Throwable $e ) {
			return;
		}
		$keep = array();
		if ( ! $all ) {
			foreach ( array( '_faws_media_files', '_faws_media_previous_files', '_faws_media_job' ) as $key ) {
				$entries = get_post_meta( $id, $key, true );
				if ( '_faws_media_job' === $key ) {
					$entries = $entries['files'] ?? array();
				}
				foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
					$keep[] = $entry['file'];
				}
			}
		}
		$paths = glob( $dir . '/' . $id . '-*' );
		foreach ( $paths ? $paths : array() as $path ) {
			$name = wp_basename( $path );
			if ( preg_match( '/^' . $id . '-[a-f0-9]{32}\.(?:webp|avif)$/D', $name ) && ! is_link( $path ) && ! in_array( $name, $keep, true ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Revoke consent and optionally remove owned derivatives.
	 *
	 * @param int  $id Attachment identifier.
	 * @param bool $delete Whether owned files and metadata should also be deleted.
	 * @return void
	 */
	public static function revoke( int $id, bool $delete = false ): void {
		$consent = get_post_meta( $id, '_faws_media_consent', true );
		delete_post_meta( $id, '_faws_media_consent' );
		if ( is_array( $consent ) && ! empty( $consent['token'] ) ) {
			wp_clear_scheduled_hook( 'faws_media_convert', array( $id, $consent['token'] ) );
		}
		if ( $delete ) {
			self::prune( $id, true );
			foreach ( array( '_faws_media_job', '_faws_media_files', '_faws_media_previous_files' ) as $key ) {
				delete_post_meta( $id, $key );
			}
		}
	}

	/**
	 * Detect another image optimizer before serving a second image layer.
	 *
	 * @return array
	 */
	public static function conflicts(): array {
		$active = array_merge( get_option( 'active_plugins', array() ), array_keys( get_site_option( 'active_sitewide_plugins', array() ) ) );
		return array_values(
			array_filter(
				$active,
				static function ( $plugin ) {
					return (bool) preg_match( '/imagify|shortpixel|smush|ewww|optimole|webp-converter|converter-for-media/i', $plugin );
				}
			)
		);
	}

	/**
	 * Verify source freshness and exact derivative identity within uploads.
	 *
	 * @param int    $id Attachment identifier.
	 * @param array  $entry Owned derivative manifest entry.
	 * @param array  $uploads Native uploads configuration.
	 * @param string $dir Dedicated cache directory.
	 * @return bool
	 */
	private static function verified_entry( int $id, array $entry, array $uploads, string $dir ): bool {
		if ( ! preg_match( '/^' . $id . '-[a-f0-9]{32}\.(?:webp|avif)$/D', $entry['file'] ?? '' ) ) {
			return false;
		}
		$source = $uploads['basedir'] . '/' . $entry['relative'];
		$file   = $dir . '/' . $entry['file'];
		$real   = wp_normalize_path( (string) realpath( $source ) );
		$root   = trailingslashit( wp_normalize_path( (string) realpath( $uploads['basedir'] ) ) );
		return $real && 0 === strpos( $real, $root ) && ! is_link( $source ) && is_file( $source ) && is_file( $file ) && ! is_link( $file ) && is_readable( $file ) && filemtime( $source ) === $entry['mtime'] && filesize( $source ) === $entry['bytes'] && filesize( $file ) === $entry['converted_bytes'] && hash_equals( $entry['hash'], hash_file( 'sha256', $file ) );
	}

	/**
	 * Return a verified smaller derivative or the original URL.
	 *
	 * @param int    $id Attachment identifier.
	 * @param string $url Original attachment source URL.
	 * @return string
	 */
	private static function replacement( int $id, string $url ): string {
		$options = get_option( 'faws_media_options', array() );
		if ( empty( $options['webp'] ) ) {
			return $url;
		}
		$consent = get_post_meta( $id, '_faws_media_consent', true );
		if ( empty( $options['webp'] ) || empty( $consent['active'] ) || ! self::eligible() || self::conflicts() ) {
			return $url;
		}
		try {
			list( $dir, $base, $uploads ) = self::directory();
			$files                        = get_post_meta( $id, '_faws_media_files', true );
			foreach ( is_array( $files ) ? $files : array() as $entry ) {
				if ( trailingslashit( $uploads['baseurl'] ) . $entry['relative'] !== $url || ! preg_match( '/^' . $id . '-[a-f0-9]{32}\.webp$/D', $entry['file'] ) ) {
					continue;
				}
				if ( self::verified_entry( $id, $entry, $uploads, $dir ) ) {
					return $base . '/' . $entry['file'];
				}
			}
		} catch ( Throwable $e ) {
			return $url;
		}
		return $url;
	}

	/**
	 * Use verified derivatives in initial WooCommerce variation data.
	 *
	 * @param array                $data Native WooCommerce variation data.
	 * @param WC_Product           $product Parent product.
	 * @param WC_Product_Variation $variation Current variation.
	 * @return array
	 */
	public static function variation( array $data, $product, $variation ): array {
		$id = (int) $variation->get_image_id();
		foreach ( array( 'src', 'thumb_src', 'gallery_thumbnail_src' ) as $key ) {
			if ( ! empty( $data['image'][ $key ] ) ) {
				$data['image'][ $key ] = self::replacement( $id, $data['image'][ $key ] );
			}
		}
		return $data;
	}

	/**
	 * Preserve responsive widths while replacing approved source candidates.
	 *
	 * @param array|false  $sources Responsive candidates from WordPress.
	 * @param string|array $size Requested native attachment size.
	 * @param string       $src Native source URL.
	 * @param array        $meta Native attachment metadata.
	 * @param int          $id Attachment identifier.
	 * @return array|false
	 */
	public static function srcset( $sources, $size, $src, $meta, $id ) {
		if ( empty( get_option( 'faws_media_options', array() )['webp'] ) ) {
			return $sources;
		}
		foreach ( is_array( $sources ) ? $sources : array() as $key => $source ) {
			$sources[ $key ]['url'] = self::replacement( (int) $id, $source['url'] );
		}
		return $sources;
	}

	/**
	 * Keep layout dimensions and optionally prioritize the primary image.
	 *
	 * @param array        $attr Native image tag attributes.
	 * @param WP_Post      $attachment Attachment record.
	 * @param string|array $size Requested native attachment size.
	 * @return array
	 */
	public static function attributes( array $attr, $attachment, $size ): array {
		$options = get_option( 'faws_media_options', array() );
		if ( ! empty( $options['audit'] ) && current_user_can( 'manage_options' ) && ! is_admin() ) {
			$attr['data-faws-id'] = (string) $attachment->ID;
		}
		if ( ( empty( $options['webp'] ) && empty( $options['dimensions'] ) && empty( $options['priority'] ) ) || ! self::eligible() ) {
			return $attr;
		}
		if ( ! empty( $options['webp'] ) && ! empty( $attr['src'] ) ) {
			$attr['src'] = self::replacement( (int) $attachment->ID, $attr['src'] );
		}
		if ( ! empty( $options['dimensions'] ) && ( empty( $attr['width'] ) || empty( $attr['height'] ) ) ) {
			$image = wp_get_attachment_image_src( $attachment->ID, $size );
			if ( $image ) {
				$attr['width']  = $image[1];
				$attr['height'] = $image[2];
			}
		}
		if ( ! empty( $options['priority'] ) && ! self::$prioritized && is_singular() && (int) get_post_thumbnail_id( get_queried_object_id() ) === (int) $attachment->ID ) {
			$attr['loading']       = 'eager';
			$attr['fetchpriority'] = 'high';
			self::$prioritized     = true;
		}
		return $attr;
	}

	/**
	 * Keep private image diagnostics out of shared HTML caches.
	 *
	 * @return void
	 */
	public static function audit_headers(): void {
		if ( ! empty( get_option( 'faws_media_options', array() )['audit'] ) && current_user_can( 'manage_options' ) ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
		}
	}

	/**
	 * Load the local, opt-in diagnostic script only for administrators.
	 *
	 * @return void
	 */
	public static function audit_script(): void {
		$options = get_option( 'faws_media_options', array() );
		if ( empty( $options['audit'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_enqueue_script( 'faws-media-audit', plugins_url( 'assets/media-audit.js', FAWS_FILE ), array(), '0.2.0', true );
		wp_localize_script(
			'faws-media-audit',
			'fawsMediaAudit',
			array(
				'url'     => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'faws_media_audit' ),
				'context' => FAWS_Advanced::context(),
			)
		);
	}

	/**
	 * Accept bounded same-site image measurements with permission and nonce checks.
	 *
	 * @return void
	 */
	public static function audit(): void {
		if ( ! current_user_can( 'manage_options' ) || empty( get_option( 'faws_media_options', array() )['audit'] ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'faws_media_audit', 'nonce' );
		$lock = 'faws_media_audit_' . get_current_user_id();
		if ( get_transient( $lock ) ) {
			wp_send_json_error( null, 429 );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Bounded JSON is decoded and each permitted numeric field is validated below.
		$raw = isset( $_POST['measurements'] ) && is_string( $_POST['measurements'] ) ? wp_unslash( $_POST['measurements'] ) : '';
		if ( strlen( $raw ) > 20000 ) {
			wp_send_json_error( null, 400 );
		}
		$items = json_decode( $raw, true );
		if ( ! is_array( $items ) || count( $items ) > 50 ) {
			wp_send_json_error( null, 400 );
		}
		$result = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id = absint( $item['id'] ?? 0 );
			if ( 'attachment' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$row = array( 'id' => $id );
			foreach ( array( 'width', 'height', 'naturalWidth', 'naturalHeight', 'dpr', 'bytes' ) as $key ) {
				$value       = $item[ $key ] ?? 0;
				$row[ $key ] = is_numeric( $value ) ? max( 0, min( 100000000, (float) $value ) ) : 0;
			}
			$row['oversized'] = $row['naturalWidth'] > $row['width'] * max( 1, $row['dpr'] ) * 1.5 && $row['width'] > 0;
			$result[]         = $row;
		}
		$context = isset( $_POST['context'] ) && is_string( $_POST['context'] ) ? sanitize_key( wp_unslash( $_POST['context'] ) ) : 'other';
		$context = in_array( $context, array( 'home', 'shop', 'product', 'page', 'single', 'search', 'other' ), true ) ? $context : 'other';
		set_transient( $lock, true, MINUTE_IN_SECONDS );
		update_option(
			'faws_media_audit',
			array(
				'time'    => time(),
				'images'  => $result,
				'context' => $context,
			),
			false
		);
		wp_send_json_success();
	}

	/**
	 * Validate and dispatch administrator-only settings actions.
	 *
	 * @return void
	 */
	public static function action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'دسترسی مجاز نیست.', 'fa-wp-speedup' ), 403 );
		}
		check_admin_referer( 'faws_media' );
		$op = sanitize_key( is_string( $_POST['op'] ?? null ) ? wp_unslash( $_POST['op'] ) : '' );
		$id = isset( $_POST['id'] ) && is_scalar( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( 'settings' === $op ) {
			update_option( 'faws_media_previous', get_option( 'faws_media_options', array() ), false );
			$options = array();
			foreach ( array( 'webp', 'dimensions', 'priority', 'audit' ) as $key ) {
				$options[ $key ] = ! empty( $_POST[ $key ] );
			}
			update_option( 'faws_media_options', $options, false );
		} elseif ( 'rollback' === $op ) {
			update_option( 'faws_media_options', get_option( 'faws_media_previous', array() ), false );
		} elseif ( 'attachment' === get_post_type( $id ) && current_user_can( 'edit_post', $id ) ) {
			if ( 'queue' === $op ) {
				$format  = is_string( $_POST['format'] ?? null ) ? sanitize_key( wp_unslash( $_POST['format'] ) ) : '';
				$quality = isset( $_POST['quality'] ) && is_scalar( $_POST['quality'] ) ? absint( $_POST['quality'] ) : 80;
				if ( ! self::queue( $id, $format, $quality ) ) {
					set_transient( 'faws_media_notice_' . get_current_user_id(), __( 'تولید زمان‌بندی نشد: فقط JPEG/PNG ثابت محلی، پشتیبانی کدک، سقف ۱۲ مگاپیکسل و ۱۶ مگابایت را بررسی کنید.', 'fa-wp-speedup' ), MINUTE_IN_SECONDS );
				}
			} elseif ( 'activate' === $op ) {
				if ( ! self::activate( $id ) ) {
					set_transient( 'faws_media_notice_' . get_current_user_id(), __( 'فعال‌سازی انجام نشد؛ فقط WebP کامل، کم‌حجم‌تر و سالم قابل فعال‌سازی است.', 'fa-wp-speedup' ), MINUTE_IN_SECONDS );
				}
			} elseif ( 'disable' === $op || 'delete' === $op ) {
				self::revoke( $id, 'delete' === $op );
			}
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=faws#media' ) );
		exit;
	}

	/**
	 * Render the start of a nonce-protected administrator form.
	 *
	 * @param string $op Whitelisted administrator action.
	 * @param int    $id Attachment identifier.
	 * @return void
	 */
	private static function form_start( string $op, int $id = 0 ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:8px 0">';
		wp_nonce_field( 'faws_media' );
		echo '<input type="hidden" name="action" value="faws_media"><input type="hidden" name="op" value="' . esc_attr( $op ) . '"><input type="hidden" name="id" value="' . esc_attr( $id ) . '">';
	}

	/**
	 * Render opt-in controls, status and rollback actions.
	 *
	 * @return void
	 */
	public static function panel(): void {
		$options   = get_option( 'faws_media_options', array() );
		$conflicts = self::conflicts();
		if ( $conflicts ) {
			echo '<p role="alert">' . esc_html__( 'بهینه‌ساز تصویر دیگری فعال است؛ سرو WebP این ماژول متوقف می‌شود تا فقط یک لایه فعال باشد.', 'fa-wp-speedup' ) . ' ' . esc_html( implode( ', ', $conflicts ) ) . '</p>';
		}
		echo '<h2 id="media">' . esc_html__( 'تصاویر و رسانه', 'fa-wp-speedup' ) . '</h2><p>' . esc_html__( 'اصل تصاویر و متادیتای وردپرس حفظ می‌شوند. تولید، فعال‌سازی هر تصویر و کلید عمومی WebP جداگانه‌اند. فقط نسخه‌های کم‌حجم‌تر سرو می‌شوند؛ srcset و ابعاد حفظ می‌شوند. AVIF فقط برای بررسی تولید می‌شود. خاموش‌کردن کلید یا هر تصویر، منبع اصلی را برمی‌گرداند. پس از فعال‌سازی نسل تازه، دو نسل حفظ می‌شود؛ پیش از فعال‌سازی یا حذف، کش HTML/CDN سایت را تخلیه کنید.', 'fa-wp-speedup' ) . '</p>';
		$notice = get_transient( 'faws_media_notice_' . get_current_user_id() );
		if ( $notice ) {
			echo '<p role="alert">' . esc_html( $notice ) . '</p>';
		}
		self::form_start( 'settings' );
		foreach ( array(
			'webp'       => __( 'سرو WebP تأییدشده برای تصاویر عمومی؛ با خاموش‌کردن به اصل برگردید', 'fa-wp-speedup' ),
			'dimensions' => __( 'تکمیل width و height از اندازهٔ واقعی وردپرس، فقط در صورت نبودن', 'fa-wp-speedup' ),
			'priority'   => __( 'eager و fetchpriority بالا فقط برای اولین تصویر شاخص صفحهٔ تکی؛ در قالب سفارشی ابتدا محل تصویر را بررسی کنید', 'fa-wp-speedup' ),
			'audit'      => __( 'اندازه‌گیری تصویر با مرورگر هنگام بازدید مدیر؛ ارسال فقط ID و ابعاد به همین سایت، حداکثر یک بار در دقیقه', 'fa-wp-speedup' ),
		) as $key => $label ) {
			echo '<p><label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . checked( ! empty( $options[ $key ] ), true, false ) . '> ' . esc_html( $label ) . '</label></p>';
		}
		submit_button( __( 'ذخیرهٔ تنظیمات تصویر', 'fa-wp-speedup' ) );
		echo '</form>';
		self::form_start( 'rollback' );
		submit_button( __( 'بازگردانی تنظیمات قبلی تصاویر', 'fa-wp-speedup' ), 'secondary' );
		echo '</form><p dir="ltr">WebP: ' . esc_html( wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ? 'supported' : 'unavailable' ) . ' | AVIF: ' . esc_html( wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ) ? 'supported' : 'unavailable' ) . '</p>';
		$page  = isset( $_GET['faws_media_page'] ) && is_scalar( $_GET['faws_media_page'] ) ? max( 1, absint( $_GET['faws_media_page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png' ),
				'posts_per_page' => 10,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);
		foreach ( $query->posts as $attachment ) {
			if ( ! current_user_can( 'edit_post', $attachment->ID ) ) {
				continue;
			}
			$id      = $attachment->ID;
			$meta    = wp_get_attachment_metadata( $id );
			$job     = get_post_meta( $id, '_faws_media_job', true );
			$consent = get_post_meta( $id, '_faws_media_consent', true );
			echo '<section style="background:#fff;padding:12px;margin:12px 0"><h3>' . esc_html( get_the_title( $id ) ) . ' (#' . esc_html( $id ) . ')</h3><p dir="ltr">' . esc_html( ( $meta['width'] ?? 0 ) . ' × ' . ( $meta['height'] ?? 0 ) ) . '</p><p dir="ltr">' . esc_html( $job['status'] ?? 'not_requested' ) . ' | ' . esc_html( ! empty( $consent['active'] ) ? 'active' : 'original' ) . '</p>';
			foreach ( is_array( $job ) ? ( $job['files'] ?? array() ) : array() as $entry ) {
				try {
					list( $dir, $base, $uploads ) = self::directory();
					$health                       = self::verified_entry( $id, $entry, $uploads, $dir ) ? __( 'معتبر', 'fa-wp-speedup' ) : __( 'منبع تغییر کرده یا نسخهٔ محلی ناسالم است؛ اصل تصویر استفاده می‌شود', 'fa-wp-speedup' );
					echo '<p>' . esc_html( $health ) . '</p>';
					echo '<p dir="ltr"><a href="' . esc_url( $base . '/' . $entry['file'] ) . '">' . esc_html( $entry['width'] . ' × ' . $entry['height'] ) . '</a> ' . esc_html( $entry['bytes'] . ' → ' . $entry['converted_bytes'] . ' bytes | SHA256 ' . $entry['hash'] ) . '</p>';
				} catch ( Throwable $e ) {
					continue;
				}
			}
			self::form_start( 'queue', $id );
			echo '<select name="format"><option value="webp">WebP</option><option value="avif">AVIF</option></select> <label>' . esc_html__( 'کیفیت (۶۰ تا ۹۰)', 'fa-wp-speedup' ) . ' <input type="number" name="quality" min="60" max="90" value="80"></label>';
			submit_button( __( 'تأیید تولید پس‌زمینه با حفظ اصل', 'fa-wp-speedup' ), 'secondary' );
			echo '</form>';
			foreach ( array(
				'activate' => __( 'تأیید فعال‌سازی WebP کامل‌شده', 'fa-wp-speedup' ),
				'disable'  => __( 'بازگشت به اصل و لغو کار زمان‌بندی‌شده', 'fa-wp-speedup' ),
				'delete'   => __( 'حذف فقط نسخه‌های تولیدشده', 'fa-wp-speedup' ),
			) as $op => $label ) {
				self::form_start( $op, $id );
				submit_button( $label, 'secondary' );
				echo '</form>';
			}
			echo '</section>';
		}
		$last_page = min( $query->max_num_pages, $page + 1 );
		for ( $number = max( 1, $page - 1 ); $number <= $last_page; ++$number ) {
			echo '<a style="margin:8px" href="' . esc_url( admin_url( 'options-general.php?page=faws&faws_media_page=' . $number . '#media' ) ) . '">' . esc_html( $number ) . '</a>';
		}
		echo '<h3>' . esc_html__( 'آخرین اندازه‌گیری مرورگر مدیر', 'fa-wp-speedup' ) . '</h3><pre dir="ltr" style="white-space:pre-wrap">' . esc_html( wp_json_encode( get_option( 'faws_media_audit', array() ), JSON_PRETTY_PRINT ) ) . '</pre>';
	}

	/**
	 * Remove owned derivatives when WordPress deletes their attachment.
	 *
	 * @param int $id Attachment being deleted by WordPress.
	 * @return void
	 */
	public static function deleted( int $id ): void {
		if ( get_post_meta( $id, '_faws_media_job', true ) || get_post_meta( $id, '_faws_media_files', true ) ) {
			self::revoke( $id, true );
		}
	}

	/**
	 * Unschedule jobs or delete only module-owned data in bounded batches.
	 *
	 * @param bool $delete Whether owned files and metadata should also be deleted.
	 * @return void
	 */
	public static function cleanup( bool $delete = false ): void {
		foreach ( $delete ? array( '_faws_media_consent', '_faws_media_job' ) : array( '_faws_media_consent' ) as $meta_key ) {
			$page = 1;
			do {
				$ids = get_posts(
					array(
						'post_type'      => 'attachment',
						'post_status'    => array_values( get_post_stati() ),
						'meta_key'       => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only module-owned records, paginated cleanup.
						'fields'         => 'ids',
						'posts_per_page' => 50,
						'paged'          => $page,
						'orderby'        => 'ID',
						'order'          => 'ASC',
					)
				);
				foreach ( $ids as $id ) {
					if ( $delete ) {
						self::revoke( (int) $id, true );
						delete_option( 'faws_media_lock_' . $id );
					} else {
						$consent = get_post_meta( $id, '_faws_media_consent', true );
						wp_clear_scheduled_hook( 'faws_media_convert', array( (int) $id, $consent['token'] ) );
					}
				}
				if ( ! $delete ) {
					++$page;
				}
				$batch_count = count( $ids );
			} while ( 50 === $batch_count );
		}
		if ( $delete ) {
			foreach ( array( 'faws_media_options', 'faws_media_previous', 'faws_media_audit' ) as $key ) {
				delete_option( $key );
			}
		}
	}
}
