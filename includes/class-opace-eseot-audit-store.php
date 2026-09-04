<?php
/**
 * Stored page-audit summaries: post meta, a URL-keyed option and the REST route that writes them.
 *
 * @package Opace_Essential_SEO_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the small summary the audit panel posts after each run.
 *
 * Only the counts, up to five Review labels, the audited URL, the time and the
 * Web Vitals numbers are stored. Full results never reach the server.
 *
 * Per-post summaries live in post meta. Summaries for URLs that are not a post
 * (the home page, archives) live in one option capped at 50 entries.
 *
 * Uninstall: call delete_all() once per site. It removes both meta keys and the
 * option for the current site only, so multisite loops over sites first.
 */
final class Opace_ESEOT_Audit_Store {

	const META_KEY = '_opace_eseot_audit_summary';

	/**
	 * Scalar companion to META_KEY (GMT unix time) so list tables can sort by it.
	 */
	const TIME_META_KEY = '_opace_eseot_audit_time';

	const OPTION_URLS = 'eseot_audit_summaries_urls';

	const REST_NAMESPACE = 'opace-eseot/v1';
	const REST_ROUTE     = '/summary';

	const MAX_URL_SUMMARIES = 50;
	const MAX_TOP_REVIEW    = 5;
	const MAX_LABEL_LENGTH  = 120;
	const MAX_COUNT         = 100000;

	/**
	 * Capability needed to store a summary for a URL that is not a post (post_id 0).
	 * Matches the Tools page gate in PLAN-PHASE2-AUDIT.md. Filterable via
	 * opace_eseot_url_summary_capability.
	 */
	const URL_SUMMARY_CAP = 'edit_others_posts';

	/**
	 * Whether init() has run.
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * Register hooks. Safe to call more than once.
	 */
	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;

		if ( did_action( 'init' ) ) {
			self::register_meta();
		} else {
			add_action( 'init', array( __CLASS__, 'register_meta' ) );
		}

		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Count keys in the order they are displayed.
	 *
	 * @return string[]
	 */
	public static function count_keys(): array {
		return array( 'pass', 'review', 'info' );
	}

	/**
	 * Web Vitals metric names accepted from the panel.
	 *
	 * @return string[]
	 */
	public static function vital_keys(): array {
		return array( 'LCP', 'CLS', 'INP', 'FCP', 'TTFB' );
	}

	/* ---------------------------------------------------------------------
	 * Registration
	 * ------------------------------------------------------------------ */

	/**
	 * Register both meta keys for every post type. Hooked to init.
	 *
	 * Neither key is exposed through the REST API or the custom fields box.
	 */
	public static function register_meta(): void {
		register_post_meta(
			'',
			self::META_KEY,
			array(
				'type'          => 'array',
				'description'   => __( 'Last Essential SEO Toolkit page audit summary.', 'opace-essential-seo-toolkit' ),
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => array( __CLASS__, 'meta_auth_callback' ),
			)
		);

		register_post_meta(
			'',
			self::TIME_META_KEY,
			array(
				'type'          => 'integer',
				'description'   => __( 'Time of the last Essential SEO Toolkit page audit (GMT unix time).', 'opace-essential-seo-toolkit' ),
				'single'        => true,
				'show_in_rest'  => false,
				'auth_callback' => array( __CLASS__, 'meta_auth_callback' ),
			)
		);
	}

	/**
	 * Only people who can edit the post may touch its audit meta.
	 *
	 * @param bool   $allowed   Whether the user can add the meta.
	 * @param string $meta_key  Meta key.
	 * @param int    $object_id Post ID.
	 * @param int    $user_id   User ID.
	 * @return bool
	 */
	public static function meta_auth_callback( $allowed, $meta_key, $object_id, $user_id ): bool {
		unset( $allowed, $meta_key );

		return user_can( (int) $user_id, 'edit_post', (int) $object_id );
	}

	/**
	 * Register POST /opace-eseot/v1/summary. Hooked to rest_api_init.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'handle_rest_summary' ),
				'permission_callback' => array( __CLASS__, 'rest_permission' ),
				'args'                => self::rest_args(),
			)
		);
	}

	/**
	 * Request schema. Unknown keys inside counts and vitals are rejected here;
	 * unknown top-level keys are rejected in the handler.
	 *
	 * @return array
	 */
	private static function rest_args(): array {
		$count_schema = array(
			'type'     => 'integer',
			'minimum'  => 0,
			'maximum'  => self::MAX_COUNT,
			'required' => true,
		);
		$counts       = array();
		foreach ( self::count_keys() as $key ) {
			$counts[ $key ] = $count_schema;
		}

		$vitals = array();
		foreach ( self::vital_keys() as $key ) {
			$vitals[ $key ] = array(
				'type'    => 'number',
				'minimum' => 0,
			);
		}

		return array(
			'post_id'    => array(
				'type'              => 'integer',
				'required'          => true,
				'minimum'           => 0,
				'sanitize_callback' => 'absint',
			),
			'url'        => array(
				'type'              => 'string',
				'required'          => true,
				'validate_callback' => array( __CLASS__, 'validate_url_arg' ),
				'sanitize_callback' => 'esc_url_raw',
			),
			'counts'     => array(
				'type'                 => 'object',
				'required'             => true,
				'properties'           => $counts,
				'additionalProperties' => false,
			),
			'top_review' => array(
				'type'    => 'array',
				'default' => array(),
				'items'   => array( 'type' => 'string' ),
			),
			'audited_at' => array(
				'type'     => 'string',
				'required' => true,
				'format'   => 'date-time',
			),
			'vitals'     => array(
				'type'                 => 'object',
				'default'              => array(),
				'properties'           => $vitals,
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * The url argument must be an absolute http(s) address on this site.
	 *
	 * @param mixed $value Raw value.
	 * @return true|WP_Error
	 */
	public static function validate_url_arg( $value ) {
		if ( ! is_string( $value ) || ! self::is_site_url( $value ) ) {
			return new WP_Error(
				'opace_eseot_wrong_host',
				__( 'Only pages on this site can be audited. The URL must be an absolute address on the same host as the site.', 'opace-essential-seo-toolkit' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * True when the URL is absolute, http(s) and on the same host as home_url().
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	public static function is_site_url( string $url ): bool {
		$clean  = esc_url_raw( trim( $url ) );
		$parsed = ( '' !== $clean ) ? wp_parse_url( $clean ) : false;

		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) || empty( $parsed['scheme'] ) ) {
			return false;
		}
		if ( ! in_array( strtolower( $parsed['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}

		$home = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		return is_string( $home ) && strtolower( $parsed['host'] ) === strtolower( $home );
	}

	/**
	 * Logged in, and able to edit the post (or, for post_id 0, URL_SUMMARY_CAP).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function rest_permission( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in to save an audit summary.', 'opace-essential-seo-toolkit' ),
				array( 'status' => 401 )
			);
		}

		$post_id = absint( $request['post_id'] );

		if ( $post_id > 0 ) {
			if ( ! get_post( $post_id ) ) {
				return new WP_Error(
					'rest_post_invalid_id',
					__( 'Invalid post ID.', 'opace-essential-seo-toolkit' ),
					array( 'status' => 404 )
				);
			}
			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error(
					'rest_forbidden',
					__( 'Sorry, you are not allowed to save an audit summary for this post.', 'opace-essential-seo-toolkit' ),
					array( 'status' => 403 )
				);
			}

			return true;
		}

		/**
		 * Filters the capability needed to store a summary for a URL that is not a post.
		 *
		 * @param string $capability Default 'manage_options'.
		 */
		$capability = apply_filters( 'opace_eseot_url_summary_capability', self::URL_SUMMARY_CAP );

		if ( ! is_string( $capability ) || '' === $capability || ! current_user_can( $capability ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to save an audit summary for this address.', 'opace-essential-seo-toolkit' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Store the posted summary and return it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_rest_summary( WP_REST_Request $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) || empty( $params ) ) {
			$params = $request->get_body_params();
		}

		$allowed = array( 'post_id', 'url', 'counts', 'top_review', 'audited_at', 'vitals' );
		$unknown = array_diff( array_keys( (array) $params ), $allowed );
		if ( ! empty( $unknown ) ) {
			return new WP_Error(
				'rest_invalid_param',
				sprintf(
					/* translators: %s: comma-separated list of field names. */
					__( 'Unknown field: %s.', 'opace-essential-seo-toolkit' ),
					implode( ', ', array_map( 'sanitize_key', array_map( 'strval', $unknown ) ) )
				),
				array( 'status' => 400 )
			);
		}

		if ( ! rest_parse_date( (string) $request['audited_at'] ) ) {
			return new WP_Error(
				'rest_invalid_param',
				__( 'audited_at must be an ISO 8601 date and time.', 'opace-essential-seo-toolkit' ),
				array( 'status' => 400 )
			);
		}

		$post_id = absint( $request['post_id'] );
		$url     = esc_url_raw( (string) $request['url'] );

		$summary = self::store(
			$post_id,
			$url,
			is_array( $request['counts'] ) ? $request['counts'] : array(),
			is_array( $request['top_review'] ) ? $request['top_review'] : array(),
			is_array( $request['vitals'] ) ? $request['vitals'] : array()
		);

		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		$summary['post_id'] = $post_id;

		return new WP_REST_Response( $summary, 200 );
	}

	/* ---------------------------------------------------------------------
	 * Writing
	 * ------------------------------------------------------------------ */

	/**
	 * Sanitise and store a summary for a post (post_id > 0) or a URL (post_id 0).
	 *
	 * audited_at is always the server time; user_id is the current user.
	 *
	 * @param int    $post_id    Post ID, or 0 for a URL that is not a post.
	 * @param string $url        Audited URL (must be on this site).
	 * @param array  $counts     pass / review / info counts.
	 * @param array  $top_review Up to five Review labels.
	 * @param array  $vitals     LCP / CLS / INP / FCP / TTFB numbers.
	 * @return array|WP_Error The stored summary.
	 */
	public static function store( int $post_id, string $url, array $counts, array $top_review = array(), array $vitals = array() ) {
		if ( ! self::is_site_url( $url ) ) {
			return new WP_Error(
				'opace_eseot_wrong_host',
				__( 'Only pages on this site can be audited. The URL must be an absolute address on the same host as the site.', 'opace-essential-seo-toolkit' ),
				array( 'status' => 400 )
			);
		}
		if ( $post_id > 0 && ! get_post( $post_id ) ) {
			return new WP_Error(
				'rest_post_invalid_id',
				__( 'Invalid post ID.', 'opace-essential-seo-toolkit' ),
				array( 'status' => 404 )
			);
		}

		$summary = array(
			'url'        => esc_url_raw( trim( $url ) ),
			'counts'     => self::sanitize_counts( $counts ),
			'top_review' => self::sanitize_labels( $top_review ),
			'audited_at' => gmdate( 'c' ),
			'vitals'     => self::sanitize_vitals( $vitals ),
			'user_id'    => get_current_user_id(),
		);

		if ( $post_id > 0 ) {
			update_post_meta( $post_id, self::META_KEY, $summary );
			update_post_meta( $post_id, self::TIME_META_KEY, time() );
		} else {
			self::save_url_summary( $summary );
		}

		/**
		 * Fires after an audit summary has been stored.
		 *
		 * @param int   $post_id Post ID, or 0 for a URL that is not a post.
		 * @param array $summary Stored summary.
		 */
		do_action( 'opace_eseot_audit_summary_stored', $post_id, $summary );

		return $summary;
	}

	/**
	 * Add or replace a URL-keyed summary, keeping the newest 50.
	 *
	 * @param array $summary Sanitised summary with a url key.
	 */
	private static function save_url_summary( array $summary ): void {
		$all = get_option( self::OPTION_URLS, array() );
		$all = is_array( $all ) ? $all : array();
		$key = md5( (string) $summary['url'] );

		unset( $all[ $key ] );
		$all[ $key ] = $summary;

		if ( count( $all ) > self::MAX_URL_SUMMARIES ) {
			$all = array_slice( $all, -self::MAX_URL_SUMMARIES, null, true );
		}

		update_option( self::OPTION_URLS, $all, false );
	}

	/**
	 * Non-negative integer counts for exactly the known keys.
	 *
	 * @param array $raw Raw counts.
	 * @return array<string, int>
	 */
	private static function sanitize_counts( array $raw ): array {
		$counts = array();
		foreach ( self::count_keys() as $key ) {
			$value          = isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ? absint( $raw[ $key ] ) : 0;
			$counts[ $key ] = min( $value, self::MAX_COUNT );
		}

		return $counts;
	}

	/**
	 * Plain-text labels, at most five, each at most 120 characters.
	 *
	 * @param array $raw Raw labels.
	 * @return string[]
	 */
	private static function sanitize_labels( array $raw ): array {
		$labels = array();
		foreach ( $raw as $label ) {
			if ( ! is_scalar( $label ) ) {
				continue;
			}
			$label = trim( sanitize_text_field( (string) $label ) );
			if ( '' === $label ) {
				continue;
			}
			$labels[] = mb_substr( $label, 0, self::MAX_LABEL_LENGTH );
			if ( count( $labels ) >= self::MAX_TOP_REVIEW ) {
				break;
			}
		}

		return $labels;
	}

	/**
	 * Non-negative floats for the known metrics only, rounded to 3 decimals.
	 *
	 * @param array $raw Raw metrics.
	 * @return array<string, float>
	 */
	private static function sanitize_vitals( array $raw ): array {
		$vitals = array();
		foreach ( self::vital_keys() as $key ) {
			if ( isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ) {
				$vitals[ $key ] = round( max( 0.0, (float) $raw[ $key ] ), 3 );
			}
		}

		return $vitals;
	}

	/* ---------------------------------------------------------------------
	 * Reading
	 * ------------------------------------------------------------------ */

	/**
	 * Stored summary for a post, or null when it has not been audited.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function get_summary( int $post_id ): ?array {
		if ( $post_id <= 0 ) {
			return null;
		}
		$value = get_post_meta( $post_id, self::META_KEY, true );

		return self::normalise( $value );
	}

	/**
	 * GMT unix time of a post's last audit, or 0.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	public static function get_time( int $post_id ): int {
		if ( $post_id <= 0 ) {
			return 0;
		}

		return absint( get_post_meta( $post_id, self::TIME_META_KEY, true ) );
	}

	/**
	 * Stored summary for a URL that is not a post, or null.
	 *
	 * @param string $url URL exactly as audited.
	 * @return array|null
	 */
	public static function get_url_summary( string $url ): ?array {
		$all = get_option( self::OPTION_URLS, array() );
		if ( ! is_array( $all ) ) {
			return null;
		}
		$key = md5( esc_url_raw( trim( $url ) ) );

		return isset( $all[ $key ] ) ? self::normalise( $all[ $key ] ) : null;
	}

	/**
	 * Most recent summaries across posts and URLs, newest first.
	 *
	 * Each row: post_id (0 for a URL), title, url, counts, top_review,
	 * audited_at, time (GMT unix), vitals, user_id.
	 *
	 * @param int $limit Maximum rows (1 to 50).
	 * @return array<int, array>
	 */
	public static function recent( int $limit = 5 ): array {
		$limit = max( 1, min( self::MAX_URL_SUMMARIES, $limit ) );
		$rows  = array();

		$query = new WP_Query(
			array(
				'post_type'              => 'any',
				'post_status'            => 'any',
				'posts_per_page'         => $limit,
				'fields'                 => 'ids',
				'meta_key'               => self::TIME_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- indexed scalar key, capped result set for the dashboard widget.
				'orderby'                => 'meta_value_num',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
			)
		);

		foreach ( $query->posts as $post_id ) {
			$post_id = (int) $post_id;
			$summary = self::get_summary( $post_id );
			if ( null === $summary ) {
				continue;
			}
			$rows[] = self::row( $post_id, $summary, self::get_time( $post_id ) );
		}

		$all = get_option( self::OPTION_URLS, array() );
		if ( is_array( $all ) ) {
			foreach ( $all as $stored ) {
				$summary = self::normalise( $stored );
				if ( null === $summary ) {
					continue;
				}
				$time   = strtotime( (string) $summary['audited_at'] );
				$rows[] = self::row( 0, $summary, $time ? (int) $time : 0 );
			}
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return $b['time'] <=> $a['time'];
			}
		);

		return array_slice( $rows, 0, $limit );
	}

	/**
	 * "9 pass · 2 review · 3 info".
	 *
	 * @param array $summary Summary with a counts key.
	 * @return string
	 */
	public static function format_counts( array $summary ): string {
		$counts = isset( $summary['counts'] ) && is_array( $summary['counts'] ) ? $summary['counts'] : array();
		$pass   = isset( $counts['pass'] ) ? absint( $counts['pass'] ) : 0;
		$review = isset( $counts['review'] ) ? absint( $counts['review'] ) : 0;
		$info   = isset( $counts['info'] ) ? absint( $counts['info'] ) : 0;

		$parts = array(
			/* translators: %s: number of checks that passed. */
			sprintf( _n( '%s pass', '%s pass', $pass, 'opace-essential-seo-toolkit' ), number_format_i18n( $pass ) ),
			/* translators: %s: number of checks marked Review. */
			sprintf( _n( '%s review', '%s review', $review, 'opace-essential-seo-toolkit' ), number_format_i18n( $review ) ),
			/* translators: %s: number of checks marked Info. */
			sprintf( _n( '%s info', '%s info', $info, 'opace-essential-seo-toolkit' ), number_format_i18n( $info ) ),
		);

		$separator = _x( '·', 'separator between the pass, review and info counts', 'opace-essential-seo-toolkit' );

		return implode( ' ' . $separator . ' ', $parts );
	}

	/**
	 * Counts as three coloured chips (escaped HTML), for list tables and the dashboard.
	 *
	 * @param array $summary Stored summary.
	 * @return string
	 */
	public static function format_counts_html( array $summary ): string {
		$counts = isset( $summary['counts'] ) && is_array( $summary['counts'] ) ? $summary['counts'] : array();
		$items  = array(
			/* translators: %s: number of checks that passed. */
			'pass'   => sprintf( _n( '%s pass', '%s pass', isset( $counts['pass'] ) ? absint( $counts['pass'] ) : 0, 'opace-essential-seo-toolkit' ), number_format_i18n( isset( $counts['pass'] ) ? absint( $counts['pass'] ) : 0 ) ),
			/* translators: %s: number of checks marked Review. */
			'review' => sprintf( _n( '%s review', '%s review', isset( $counts['review'] ) ? absint( $counts['review'] ) : 0, 'opace-essential-seo-toolkit' ), number_format_i18n( isset( $counts['review'] ) ? absint( $counts['review'] ) : 0 ) ),
			/* translators: %s: number of checks marked Info. */
			'info'   => sprintf( _n( '%s info', '%s info', isset( $counts['info'] ) ? absint( $counts['info'] ) : 0, 'opace-essential-seo-toolkit' ), number_format_i18n( isset( $counts['info'] ) ? absint( $counts['info'] ) : 0 ) ),
		);

		$html = '';
		foreach ( $items as $tone => $label ) {
			$html .= sprintf(
				'<span class="opace-eseot-count opace-eseot-count--%1$s">%2$s</span>',
				esc_attr( $tone ),
				esc_html( $label )
			);
		}

		return '<span class="opace-eseot-counts">' . $html . '</span>';
	}

	/**
	 * Remove every stored summary on the current site. Used by uninstall.php.
	 */
	public static function delete_all(): void {
		delete_post_meta_by_key( self::META_KEY );
		delete_post_meta_by_key( self::TIME_META_KEY );
		delete_option( self::OPTION_URLS );
	}

	/**
	 * Shape a stored value into a complete summary, or null when unusable.
	 *
	 * @param mixed $value Stored value.
	 * @return array|null
	 */
	private static function normalise( $value ): ?array {
		if ( ! is_array( $value ) || empty( $value['url'] ) || ! is_string( $value['url'] ) ) {
			return null;
		}

		return array(
			'url'        => $value['url'],
			'counts'     => self::sanitize_counts( isset( $value['counts'] ) && is_array( $value['counts'] ) ? $value['counts'] : array() ),
			'top_review' => self::sanitize_labels( isset( $value['top_review'] ) && is_array( $value['top_review'] ) ? $value['top_review'] : array() ),
			'audited_at' => isset( $value['audited_at'] ) ? (string) $value['audited_at'] : '',
			'vitals'     => self::sanitize_vitals( isset( $value['vitals'] ) && is_array( $value['vitals'] ) ? $value['vitals'] : array() ),
			'user_id'    => isset( $value['user_id'] ) ? absint( $value['user_id'] ) : 0,
		);
	}

	/**
	 * Row shape used by recent().
	 *
	 * @param int   $post_id Post ID or 0.
	 * @param array $summary Normalised summary.
	 * @param int   $time    GMT unix time.
	 * @return array
	 */
	private static function row( int $post_id, array $summary, int $time ): array {
		$title = $post_id > 0 ? get_the_title( $post_id ) : '';

		return array(
			'post_id'    => $post_id,
			'title'      => is_string( $title ) ? $title : '',
			'url'        => $summary['url'],
			'counts'     => $summary['counts'],
			'top_review' => $summary['top_review'],
			'audited_at' => $summary['audited_at'],
			'time'       => $time,
			'vitals'     => $summary['vitals'],
			'user_id'    => $summary['user_id'],
		);
	}
}
