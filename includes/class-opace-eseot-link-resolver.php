<?php
/**
 * Turns a saved URL template into a link for a given permalink.
 *
 * @package Opace_Essential_SEO_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Placeholder substitution and template validation.
 *
 * Supported placeholders mirror the Chrome extension ([%url%], [%url_encoded%],
 * [%host%], [%host_encoded%]) plus the two the WordPress plugin has always had
 * ([%scheme%], [%path%]).
 */
final class Opace_ESEOT_Link_Resolver {

	/**
	 * Permalink parts, keyed by permalink, so a meta box with many sources parses once.
	 *
	 * @var array<string, array|false>
	 */
	private static array $parts_cache = array();

	/**
	 * Placeholder names without the [% %] wrapper.
	 *
	 * @return string[]
	 */
	public static function placeholders(): array {
		return array( 'url', 'url_encoded', 'host', 'host_encoded', 'scheme', 'path' );
	}

	/**
	 * Resolve a template against a permalink.
	 *
	 * @param string $template  Saved URL template.
	 * @param string $permalink Permalink of the current post.
	 * @return string|false Resolved URL, or false when the template or permalink is unusable.
	 */
	public static function resolve( string $template, string $permalink ) {
		$template = trim( $template );

		if ( ! self::is_well_formed_template( $template ) ) {
			return false;
		}

		$parts = self::permalink_parts( $permalink );
		if ( false === $parts ) {
			return false;
		}

		$link   = self::substitute( $template, $parts );
		$parsed = wp_parse_url( $link );

		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) || empty( $parsed['scheme'] ) ) {
			return false;
		}

		if ( ! in_array( strtolower( $parsed['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}

		return $link;
	}

	/**
	 * Full validation used when a template is saved.
	 *
	 * Requires an http(s) scheme, only known placeholders, and a URL that
	 * passes wp_http_validate_url() once sample values are substituted.
	 *
	 * @param string $template URL template.
	 * @return bool
	 */
	public static function is_valid_template( string $template ): bool {
		$template = trim( $template );

		if ( ! self::is_well_formed_template( $template ) ) {
			return false;
		}

		$sample = self::substitute( $template, self::sample_parts() );

		return (bool) wp_http_validate_url( $sample );
	}

	/**
	 * Cheap structural check: http(s) scheme, known placeholders only, parseable host.
	 *
	 * No network access. Used for templates that are already stored, and as the
	 * first step of is_valid_template().
	 *
	 * @param string $template URL template.
	 * @return bool
	 */
	public static function is_well_formed_template( string $template ): bool {
		$template = trim( $template );

		if ( '' === $template || ! preg_match( '#^https?://#i', $template ) ) {
			return false;
		}

		if ( ! self::has_known_placeholders_only( $template ) ) {
			return false;
		}

		$parsed = wp_parse_url( self::substitute( $template, self::sample_parts() ) );

		return is_array( $parsed ) && ! empty( $parsed['host'] );
	}

	/**
	 * Strip characters that cannot appear in a URL, keeping the [% %] brackets.
	 *
	 * esc_url_raw() cannot be used for templates: it rewrites [ and ] outside the
	 * host as %5B and %5D, which destroys every placeholder. This applies only the
	 * character filter esc_url() starts with; validation happens separately.
	 *
	 * @param string $template Raw template.
	 * @return string
	 */
	public static function sanitize_template( string $template ): string {
		$template = trim( $template );
		$template = preg_replace( '/[\x00-\x1F\x7F]+/', '', $template );
		$template = preg_replace( '|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\\x80-\\xff]|i', '', (string) $template );

		return (string) $template;
	}

	/**
	 * True when every [%...%] token in the template is a supported placeholder.
	 *
	 * @param string $template URL template.
	 * @return bool
	 */
	private static function has_known_placeholders_only( string $template ): bool {
		$known    = implode( '|', array_map( 'preg_quote', self::placeholders() ) );
		$stripped = preg_replace( '/\[%(' . $known . ')%\]/', '', $template );

		return ! preg_match( '/\[%[^%]*%\]/', (string) $stripped );
	}

	/**
	 * Replace placeholders in one pass so substituted values are never re-scanned.
	 *
	 * @param string $template URL template.
	 * @param array  $parts    Values keyed by placeholder name.
	 * @return string
	 */
	private static function substitute( string $template, array $parts ): string {
		$map = array();
		foreach ( self::placeholders() as $name ) {
			$map[ '[%' . $name . '%]' ] = isset( $parts[ $name ] ) ? (string) $parts[ $name ] : '';
		}

		return strtr( $template, $map );
	}

	/**
	 * Placeholder values for a permalink, or false when the permalink is not a usable URL.
	 *
	 * @param string $permalink Permalink.
	 * @return array|false
	 */
	private static function permalink_parts( string $permalink ) {
		$permalink = trim( $permalink );

		if ( array_key_exists( $permalink, self::$parts_cache ) ) {
			return self::$parts_cache[ $permalink ];
		}

		self::$parts_cache[ $permalink ] = self::build_parts( $permalink );

		return self::$parts_cache[ $permalink ];
	}

	/**
	 * Parse a permalink into placeholder values.
	 *
	 * @param string $permalink Permalink.
	 * @return array|false
	 */
	private static function build_parts( string $permalink ) {
		$clean = esc_url_raw( $permalink );

		if ( '' === $clean || ! wp_http_validate_url( $clean ) ) {
			return false;
		}

		$parsed = wp_parse_url( $clean );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) || empty( $parsed['scheme'] ) ) {
			return false;
		}

		// Chrome strips a leading www. from the host, so the WordPress side does too.
		$host = preg_replace( '/^www\./i', '', strtolower( $parsed['host'] ) );

		$path = ( isset( $parsed['path'] ) && '' !== $parsed['path'] ) ? $parsed['path'] : '/';
		if ( isset( $parsed['query'] ) && '' !== $parsed['query'] ) {
			$path .= '?' . $parsed['query'];
		}

		return array(
			'url'          => $clean,
			'url_encoded'  => rawurlencode( $clean ),
			'host'         => $host,
			'host_encoded' => rawurlencode( $host ),
			'scheme'       => strtolower( $parsed['scheme'] ) . '://',
			'path'         => $path,
		);
	}

	/**
	 * Fixed sample values used for validation.
	 *
	 * @return array
	 */
	private static function sample_parts(): array {
		return array(
			'url'          => 'https://example.com/',
			'url_encoded'  => rawurlencode( 'https://example.com/' ),
			'host'         => 'example.com',
			'host_encoded' => 'example.com',
			'scheme'       => 'https://',
			'path'         => '/',
		);
	}
}
