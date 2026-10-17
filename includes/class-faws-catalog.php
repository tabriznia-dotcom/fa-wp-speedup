<?php
/**
 * Immutable source catalog and content verification.
 *
 * @package FA_WP_Speedup
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable source catalog and content verification.
 */
final class FAWS_Catalog {
	/**
	 * Return the immutable, reviewed resource catalog.
	 *
	 * @return array
	 */
	public static function all(): array {
		return array(
			'bootstrap-css-5.3.3' => array(
				'url'         => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
				'type'        => 'css',
				'version'     => '5.3.3',
				'library'     => 'bootstrap',
				'algorithm'   => 'sha384',
				'digest'      => 'QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH',
				'license'     => 'MIT',
				'license_url' => 'https://github.com/twbs/bootstrap/blob/v5.3.3/LICENSE',
				'provenance'  => 'https://getbootstrap.com/docs/5.3/getting-started/introduction/',
			),
			'bootstrap-js-5.3.3'  => array(
				'url'         => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
				'type'        => 'js',
				'version'     => '5.3.3',
				'library'     => 'bootstrap',
				'algorithm'   => 'sha384',
				'digest'      => 'YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz',
				'license'     => 'MIT',
				'license_url' => 'https://github.com/twbs/bootstrap/blob/v5.3.3/LICENSE',
				'provenance'  => 'https://getbootstrap.com/docs/5.3/getting-started/introduction/',
			),
		);
	}

	/**
	 * Match only exact approved source identities.
	 *
	 * @param string $url Source URL, used transiently.
	 * @return ?string
	 */
	public static function match( string $url ): ?string {
		foreach ( self::all() as $id => $item ) {
			if ( $item['url'] === $url || $item['url'] . '?ver=' . $item['version'] === $url ) {
				return $id;
			}
		}
		return null;
	}

	/**
	 * Validate MIME, size, dependencies and official SRI.
	 *
	 * @param string $body Downloaded bytes.
	 * @param string $mime Response content type.
	 * @param array  $item Reviewed catalog entry.
	 * @return bool
	 */
	public static function valid_body( string $body, string $mime, array $item ): bool {
		$allowed = 'css' === $item['type'] ? array( 'text/css' ) : array( 'application/javascript', 'text/javascript', 'application/x-javascript' );
		$mime    = strtolower( trim( explode( ';', $mime )[0] ) );
		if ( ! in_array( $mime, $allowed, true ) || '' === $body || strlen( $body ) > 1048576 ) {
			return false;
		}
		if ( preg_match( '/<\s*(?:!doctype|html|\?php)/i', $body ) ) {
			return false;
		}
		// CSS with external dependencies requires a separately reviewed dependency graph.
		if ( 'css' === $item['type'] ) {
			if ( preg_match( '/@import/i', $body ) ) {
				return false;
			}
			preg_match_all( '/url\((.*?)\)/is', $body, $urls );
			foreach ( $urls[1] as $url ) {
				if ( 0 !== stripos( trim( $url, " \t\n\r\0\x0B\"'" ), 'data:' ) ) {
					return false;
				}
			}
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- SRI requires base64 encoding of cryptographic digests.
		return hash_equals( $item['digest'], base64_encode( hash( $item['algorithm'], $body, true ) ) );
	}
}
