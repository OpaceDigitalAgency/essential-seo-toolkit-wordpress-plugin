<?php
/**
 * Page audit plumbing: front-end runner activation, REST crawl endpoint,
 * robots.txt and sitemap discovery, transient cache and the panel container.
 *
 * @package Opace_Essential_SEO_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server side of the page audit.
 *
 * The audit itself runs in the editor's browser: the panel loads the audited
 * page in a same-origin iframe with ?opace_eseot_audit=1&_wpnonce=… and the
 * runner script posts its findings back. This class decides when that runner
 * may load, serves the one REST call the panel makes (a crawl check of status,
 * headers, robots.txt and sitemaps) and renders the panel container.
 */
final class Opace_ESEOT_Audit {

	const NONCE_ACTION = 'opace_eseot_audit';
	const QUERY_ARG    = 'opace_eseot_audit';
	const POST_ARG     = 'opace_eseot_post';

	const REST_NAMESPACE = 'opace-eseot/v1';
	const REST_ROUTE     = '/crawl';

	const CACHE_PREFIX = 'opace_eseot_crawl_';
	const CACHE_TTL    = 60;

	const RUNNER_TIMEOUT_MS = 15000;
	const PANEL_TIMEOUT_MS  = 20000;

	const BODY_CLASS = 'opace-eseot-auditing';

	/**
	 * Post meta holding the last stored audit summary (written by the summary
	 * REST route in Opace_ESEOT_Audit_Store, read here for the panel).
	 */
	const SUMMARY_META_KEY = '_opace_eseot_audit_summary';

	/**
	 * Response headers the crawl check reports, in output order.
	 *
	 * @var string[]
	 */
	private const REPORTED_HEADERS = array(
		'content-type',
		'cache-control',
		'x-robots-tag',
		'strict-transport-security',
		'content-security-policy',
		'x-content-type-options',
		'x-frame-options',
		'referrer-policy',
		'permissions-policy',
		'server',
	);

	/**
	 * Settings access for autorun and engine switches.
	 *
	 * @var Opace_ESEOT_Settings
	 */
	private Opace_ESEOT_Settings $settings;

	/**
	 * Error message of the first connection failure during the current crawl.
	 * Once the origin cannot be reached, the remaining probes are skipped so a
	 * blocked loopback costs one HEAD/GET pair (16 s at most), not a minute.
	 *
	 * @var string|null
	 */
	private ?string $unreachable = null;

	/**
	 * Constructor.
	 *
	 * @param Opace_ESEOT_Settings $settings Settings object.
	 */
	public function __construct( Opace_ESEOT_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Wire hooks. Called once from Opace_ESEOT_Plugin.
	 */
	public function register_hooks(): void {
		// 'wp' fires before _wp_admin_bar_init() decides on the admin bar at
		// template_redirect priority 0, so the show_admin_bar filter still counts.
		add_action( 'wp', array( $this, 'maybe_activate_runner' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	/* ---------------------------------------------------------------------
	 * URLs and targets
	 * ------------------------------------------------------------------ */

	/**
	 * The URL the panel should audit for a post: the permalink once published,
	 * otherwise the preview link so drafts, pending and scheduled posts work.
	 *
	 * @param int|WP_Post|null $post Post.
	 * @return string Empty when the post has no address yet.
	 */
	public static function audit_target_for_post( $post ): string {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post || 'auto-draft' === $post->post_status ) {
			return '';
		}

		if ( 'publish' === $post->post_status ) {
			$permalink = get_permalink( $post );
			return is_string( $permalink ) ? $permalink : '';
		}

		$preview = get_preview_post_link( $post );

		return is_string( $preview ) ? $preview : '';
	}

	/**
	 * Add the runner activation arguments to a URL.
	 *
	 * The nonce is tied to the current user, so this must only be called on
	 * admin screens for the person who will run the audit.
	 *
	 * @param string $url     Same-site URL to audit.
	 * @param int    $post_id Post the panel expects to land on, 0 when unknown.
	 * @return string
	 */
	public static function build_audit_url( string $url, int $post_id = 0 ): string {
		$args = array(
			self::QUERY_ARG => '1',
			'_wpnonce'      => wp_create_nonce( self::NONCE_ACTION ),
		);
		if ( $post_id > 0 ) {
			$args[ self::POST_ARG ] = (string) $post_id;
		}

		return add_query_arg( $args, $url );
	}

	/**
	 * True when the URL is absolute http(s) and its host is this site's host
	 * (home_url(), or site_url() when WordPress lives in its own directory).
	 * Ports are ignored; the HTTP layer rejects anything unsafe.
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	public static function is_same_site_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}

		return in_array( strtolower( $parts['host'] ), self::site_hosts(), true );
	}

	/**
	 * Hosts that count as this site.
	 *
	 * @return string[]
	 */
	private static function site_hosts(): array {
		$hosts = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				$hosts[] = strtolower( $host );
			}
		}

		return array_values( array_unique( $hosts ) );
	}

	/**
	 * scheme://host[:port] of a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function origin_of( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$origin = strtolower( $parts['scheme'] ) . '://' . strtolower( $parts['host'] );
		if ( ! empty( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}

		return $origin;
	}

	/**
	 * Origin of the admin, which the runner uses to address postMessage.
	 *
	 * @return string
	 */
	public static function admin_origin(): string {
		return self::origin_of( admin_url() );
	}

	/**
	 * Path plus query of a URL, the part robots.txt rules match against.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function path_of( string $url ): string {
		$parts = wp_parse_url( $url );
		$path  = ( is_array( $parts ) && ! empty( $parts['path'] ) ) ? $parts['path'] : '/';
		if ( is_array( $parts ) && isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$path .= '?' . $parts['query'];
		}

		return $path;
	}

	/* ---------------------------------------------------------------------
	 * Front-end runner activation
	 * ------------------------------------------------------------------ */

	/**
	 * True when this front-end request carries valid audit arguments from a
	 * logged-in user who can edit posts. Nothing is trusted from the query
	 * string until the nonce verifies.
	 *
	 * @return bool
	 */
	public function is_runner_request(): bool {
		if ( ! isset( $_GET[ self::QUERY_ARG ], $_GET['_wpnonce'] ) ) {
			return false;
		}
		if ( '1' !== sanitize_text_field( wp_unslash( $_GET[ self::QUERY_ARG ] ) ) ) {
			return false;
		}
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			return false;
		}

		$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );

		return (bool) wp_verify_nonce( $nonce, self::NONCE_ACTION );
	}

	/**
	 * Prepare the page for an audit run. Hooked to wp.
	 *
	 * Caches are told to stay away, robots are told not to index (via a
	 * response header, so the page markup stays untouched), the admin
	 * bar is hidden so counts match what visitors see, and the runner plus
	 * its two libraries are enqueued.
	 */
	public function maybe_activate_runner(): void {
		if ( is_admin() || ! $this->is_runner_request() ) {
			return;
		}

		nocache_headers();
		add_filter( 'show_admin_bar', '__return_false' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- DONOTCACHEPAGE is the constant page-cache plugins read.
			define( 'DONOTCACHEPAGE', true );
		}

		add_action( 'template_redirect', array( $this, 'send_robots_header' ), 1 );
		add_filter( 'body_class', array( $this, 'add_body_class' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_runner_assets' ) );
	}

	/**
	 * Robots directive for audit runs.
	 *
	 * Sent as an X-Robots-Tag response header rather than a meta tag so the
	 * audited document is unchanged and the "Indexing directive" check reads
	 * the page's real robots meta, not one the plugin added.
	 *
	 * @return string
	 */
	public static function robots_header_value(): string {
		return 'noindex, nofollow';
	}

	/**
	 * Send the X-Robots-Tag header for an audit run. Hooked to template_redirect.
	 */
	public function send_robots_header(): void {
		if ( ! headers_sent() ) {
			header( 'X-Robots-Tag: ' . self::robots_header_value(), true );
		}
	}

	/**
	 * Mark the body so themes and analytics snippets can tell an audit run
	 * from a real visit (for example, skip page-view tracking when the body
	 * carries the opace-eseot-auditing class).
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public function add_body_class( $classes ): array {
		$classes   = is_array( $classes ) ? $classes : array();
		$classes[] = self::BODY_CLASS;

		return $classes;
	}

	/**
	 * Enqueue web-vitals (head, blocking, so its observers register before
	 * the page paints), axe-core (footer) and the runner (footer).
	 *
	 * A library switched off in Essential SEO Toolkit → Settings → Audit is not loaded at all; the
	 * runner learns which engines are on from window.opaceEseotAudit.engines.
	 */
	public function enqueue_runner_assets(): void {
		$audit   = $this->settings->get_audit_settings();
		$engines = array(
			'axe'    => ! empty( $audit['engine_axe'] ),
			'vitals' => ! empty( $audit['engine_vitals'] ),
		);

		$deps = array();
		if ( $engines['vitals'] ) {
			wp_enqueue_script( 'opace-eseot-web-vitals', OPACE_ESEOT_URL . 'vendor/web-vitals/web-vitals.iife.js', array(), OPACE_ESEOT_VERSION, false );
			$deps[] = 'opace-eseot-web-vitals';
		}
		if ( $engines['axe'] ) {
			wp_enqueue_script( 'opace-eseot-axe', OPACE_ESEOT_URL . 'vendor/axe-core/axe.min.js', array(), OPACE_ESEOT_VERSION, true );
			$deps[] = 'opace-eseot-axe';
		}

		wp_enqueue_script( 'opace-eseot-audit-runner', OPACE_ESEOT_URL . 'assets/js/opace-eseot-audit-runner.js', $deps, OPACE_ESEOT_VERSION, true );

		$config = array(
			'origin'         => self::admin_origin(),
			'targetUrl'      => $this->current_url_without_audit_args(),
			'timeoutMs'      => self::RUNNER_TIMEOUT_MS,
			'expectedPostId' => $this->expected_post_id(),
			'engines'        => $engines,
		);

		wp_add_inline_script( 'opace-eseot-audit-runner', 'window.opaceEseotAudit = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Post id the panel asked for (opace_eseot_post), 0 when it did not say.
	 *
	 * @return int
	 */
	private function expected_post_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only reached after is_runner_request() verified the nonce.
		return isset( $_GET[ self::POST_ARG ] ) ? absint( wp_unslash( $_GET[ self::POST_ARG ] ) ) : 0;
	}

	/**
	 * The URL being served, minus the audit arguments, so the runner can
	 * report which address it audited.
	 *
	 * @return string
	 */
	private function current_url_without_audit_args(): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		if ( '' === $request_uri || '/' !== $request_uri[0] ) {
			$request_uri = '/' . ltrim( $request_uri, '/' );
		}

		$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$home_port = wp_parse_url( home_url(), PHP_URL_PORT );
		$host      = $home_host . ( $home_port ? ':' . (int) $home_port : '' );

		if ( isset( $_SERVER['HTTP_HOST'] ) ) {
			$request_host = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) );
			$bare_host    = preg_replace( '/:\d+$/', '', $request_host );
			if ( in_array( $bare_host, self::site_hosts(), true ) ) {
				$host = $request_host;
			}
		}

		$url = ( is_ssl() ? 'https://' : 'http://' ) . $host . $request_uri;

		return remove_query_arg( array( self::QUERY_ARG, '_wpnonce', self::POST_ARG ), $url );
	}

	/* ---------------------------------------------------------------------
	 * REST: GET /opace-eseot/v1/crawl?url=…[&fresh=1]
	 * ------------------------------------------------------------------ */

	/**
	 * Register the crawl route. Hooked to rest_api_init.
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_crawl' ),
				'permission_callback' => array( $this, 'rest_permission' ),
				'args'                => array(
					'url'   => array(
						'description'       => __( 'Absolute address of a page on this site.', 'opace-essential-seo-toolkit' ),
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'esc_url_raw',
						'validate_callback' => array( $this, 'rest_validate_url' ),
					),
					'fresh' => array(
						'description' => __( 'Skip the 60 second cache.', 'opace-essential-seo-toolkit' ),
						'type'        => 'boolean',
						'default'     => false,
					),
				),
			)
		);
	}

	/**
	 * Logged in with edit_posts. The REST cookie nonce (X-WP-Nonce) is checked
	 * by core before this runs.
	 *
	 * @return true|WP_Error
	 */
	public function rest_permission() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'opace_eseot_rest_login_required',
				__( 'You must be logged in to run a crawl check.', 'opace-essential-seo-toolkit' ),
				array( 'status' => 401 )
			);
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'opace_eseot_rest_forbidden',
				__( 'Sorry, you are not allowed to run a crawl check.', 'opace-essential-seo-toolkit' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * The url argument must be an absolute http(s) address on this site.
	 *
	 * @param mixed $value Raw value.
	 * @return true|WP_Error
	 */
	public function rest_validate_url( $value ) {
		$raw = is_string( $value ) ? trim( $value ) : '';
		$url = preg_match( '#^https?://#i', $raw ) ? esc_url_raw( $raw ) : '';

		if ( '' === $url || ! self::is_same_site_url( $url ) ) {
			return new WP_Error(
				'opace_eseot_rest_invalid_url',
				__( 'Enter an absolute http or https address on this site.', 'opace-essential-seo-toolkit' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Crawl check callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_crawl( WP_REST_Request $request ) {
		$url   = (string) $request->get_param( 'url' );
		$fresh = (bool) $request->get_param( 'fresh' );

		return rest_ensure_response( $this->crawl( $url, $fresh ) );
	}

	/* ---------------------------------------------------------------------
	 * Crawl check
	 * ------------------------------------------------------------------ */

	/**
	 * Status, headers, robots.txt verdict and sitemap discovery for a URL.
	 *
	 * The page fetch is cached per URL and the robots/sitemap probes per
	 * origin, both for 60 seconds, under the opace_eseot_crawl_ transient
	 * prefix that uninstall.php clears. Failed fetches are not cached so
	 * "Run again" really retries, and once a connection fails the remaining
	 * probes for that crawl are skipped.
	 *
	 * @param string $url   Same-site URL.
	 * @param bool   $fresh Skip and refill the cache.
	 * @return array
	 */
	public function crawl( string $url, bool $fresh = false ): array {
		$this->unreachable = null;

		$page_key = self::cache_key( $url );
		$cached   = true;

		$page = $fresh ? false : get_transient( $page_key );
		if ( ! is_array( $page ) ) {
			$cached = false;
			$page   = $this->fetch_page( $url );
			if ( $page['status'] > 0 ) {
				set_transient( $page_key, $page, self::CACHE_TTL );
			}
		}

		$origin   = self::origin_of( $url );
		$site_key = self::cache_key( 'site:' . $origin );

		$site = $fresh ? false : get_transient( $site_key );
		if ( ! is_array( $site ) ) {
			$site = $this->fetch_site( $origin );
			if ( null === $this->unreachable ) {
				set_transient( $site_key, $site, self::CACHE_TTL );
			}
		}

		$robots = self::parse_robots( (string) $site['robots']['body'], self::path_of( $url ) );

		$page['robots_txt'] = array(
			'status'              => (int) $site['robots']['status'],
			'found'               => (bool) $site['robots']['found'],
			'sitemaps'            => $robots['sitemaps'],
			'disallowed_for_path' => $robots['disallowed'],
			'matched_rule'        => $robots['matched_rule'],
		);
		$page['sitemaps']   = $site['sitemaps'];
		$page['cached']     = $cached;

		return $page;
	}

	/**
	 * Transient key for a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function cache_key( string $url ): string {
		return self::CACHE_PREFIX . md5( $url );
	}

	/**
	 * HEAD the page, falling back to GET when HEAD is refused (405, 501),
	 * fails, returns no headers, or ends on a redirect status.
	 *
	 * @param string $url URL.
	 * @return array
	 */
	private function fetch_page( string $url ): array {
		$probe = $this->probe( $url );

		$headers = array();
		foreach ( self::REPORTED_HEADERS as $name ) {
			$headers[ $name ] = isset( $probe['headers'][ $name ] ) ? $probe['headers'][ $name ] : null;
		}

		$length = null;
		if ( isset( $probe['headers']['content-length'] ) && is_numeric( $probe['headers']['content-length'] ) ) {
			$length = (int) $probe['headers']['content-length'];
		} elseif ( 'GET' === $probe['method'] && '' !== $probe['body'] ) {
			$length = strlen( $probe['body'] );
		}

		return array(
			'url'            => $url,
			'final_url'      => $probe['final_url'],
			'status'         => $probe['status'],
			'redirected'     => $probe['redirected'],
			'method'         => $probe['method'],
			'headers'        => $headers,
			'content_length' => $length,
			'response_ms'    => $probe['response_ms'],
			'error'          => $probe['error'],
			'fetched_at'     => gmdate( 'c' ),
		);
	}

	/**
	 * robots.txt body and sitemap probes for an origin.
	 *
	 * @param string $origin scheme://host[:port].
	 * @return array{robots: array, sitemaps: array}
	 */
	private function fetch_site( string $origin ): array {
		$robots = array(
			'status' => 0,
			'found'  => false,
			'body'   => '',
			'error'  => null,
		);

		if ( null !== $this->unreachable ) {
			$robots['error'] = $this->unreachable;
		} elseif ( '' !== $origin ) {
			$response = wp_safe_remote_get( $origin . '/robots.txt', $this->request_args() );
			if ( is_wp_error( $response ) ) {
				$robots['error']   = $response->get_error_message();
				$this->unreachable = $robots['error'];
			} else {
				$robots['status'] = (int) wp_remote_retrieve_response_code( $response );
				$type             = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
				$robots['found']  = 200 === $robots['status'] && false === strpos( $type, 'text/html' );
				if ( $robots['found'] ) {
					// Plenty for any real robots.txt; keeps the transient small.
					$robots['body'] = substr( (string) wp_remote_retrieve_body( $response ), 0, 65536 );
				}
			}
		}

		$parsed = self::parse_robots( $robots['body'], '/' );

		return array(
			'robots'   => $robots,
			'sitemaps' => $this->discover_sitemaps( $origin, $parsed['sitemaps'] ),
		);
	}

	/**
	 * Probe sitemap locations in order and stop at the first that exists:
	 * every Sitemap: line from robots.txt, then /wp-sitemap.xml, then the
	 * common guesses /sitemap.xml and /sitemap_index.xml. Every probe made is
	 * reported so the panel can show what was tried.
	 *
	 * @param string   $origin         scheme://host[:port].
	 * @param string[] $robots_sitemaps Sitemap URLs listed in robots.txt.
	 * @return array<int, array{url: string, status: int, found: bool, source: string, final_url: string}>
	 */
	private function discover_sitemaps( string $origin, array $robots_sitemaps ): array {
		if ( null !== $this->unreachable ) {
			return array();
		}

		$candidates = array();
		foreach ( $robots_sitemaps as $sitemap ) {
			$candidates[] = array( $sitemap, 'robots' );
		}
		if ( '' !== $origin ) {
			$candidates[] = array( $origin . '/wp-sitemap.xml', 'wp-sitemap' );
			$candidates[] = array( $origin . '/sitemap.xml', 'guess' );
			$candidates[] = array( $origin . '/sitemap_index.xml', 'guess' );
		}

		$results = array();
		$seen    = array();
		foreach ( $candidates as $candidate ) {
			list( $url, $source ) = $candidate;
			if ( isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;

			$probe = $this->probe( $url );
			$type  = isset( $probe['headers']['content-type'] ) ? strtolower( (string) $probe['headers']['content-type'] ) : '';
			$found = 200 === $probe['status'] && false === strpos( $type, 'text/html' );

			$results[] = array(
				'url'       => $url,
				'status'    => $probe['status'],
				'found'     => $found,
				'source'    => $source,
				'final_url' => $probe['final_url'],
			);

			if ( $found ) {
				break;
			}
		}

		return $results;
	}

	/**
	 * HEAD a URL, falling back to GET when HEAD is refused (405, 501), errors,
	 * returns no headers, or stops on a redirect status.
	 *
	 * @param string $url URL.
	 * @return array{status: int, headers: array, final_url: string, redirected: bool, method: string, error: ?string, body: string, response_ms: int}
	 */
	private function probe( string $url ): array {
		if ( null !== $this->unreachable ) {
			return array(
				'status'      => 0,
				'headers'     => array(),
				'final_url'   => $url,
				'redirected'  => false,
				'method'      => 'HEAD',
				'error'       => $this->unreachable,
				'body'        => '',
				'response_ms' => 0,
			);
		}

		$args  = $this->request_args();
		$start = microtime( true );

		$method   = 'HEAD';
		$response = wp_safe_remote_head( $url, $args );
		$status   = (int) wp_remote_retrieve_response_code( $response );
		$headers  = $this->normalise_headers( $response );

		if ( is_wp_error( $response ) || in_array( $status, array( 405, 501 ), true ) || ( $status >= 300 && $status < 400 ) || empty( $headers ) ) {
			$method   = 'GET';
			$response = wp_safe_remote_get( $url, $args );
			$status   = (int) wp_remote_retrieve_response_code( $response );
			$headers  = $this->normalise_headers( $response );
		}

		$result = array(
			'status'      => $status,
			'headers'     => $headers,
			'final_url'   => $url,
			'redirected'  => false,
			'method'      => $method,
			'error'       => null,
			'body'        => '',
			'response_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ),
		);

		if ( is_wp_error( $response ) ) {
			$result['status']  = 0;
			$result['error']   = $response->get_error_message();
			$this->unreachable = $result['error'];
			return $result;
		}

		if ( isset( $response['http_response'] ) && $response['http_response'] instanceof WP_HTTP_Requests_Response ) {
			$object = $response['http_response']->get_response_object();
			if ( is_object( $object ) ) {
				if ( ! empty( $object->url ) && is_string( $object->url ) ) {
					$result['final_url'] = $object->url;
				}
				$result['redirected'] = ! empty( $object->history ) || $result['final_url'] !== $url;
			}
		}

		$result['body'] = (string) wp_remote_retrieve_body( $response );

		return $result;
	}

	/**
	 * Header names lower-cased, multi-value headers joined with a comma.
	 *
	 * @param array|WP_Error $response HTTP API response.
	 * @return array<string, string>
	 */
	private function normalise_headers( $response ): array {
		if ( is_wp_error( $response ) ) {
			return array();
		}

		$raw = wp_remote_retrieve_headers( $response );
		if ( is_object( $raw ) && method_exists( $raw, 'getAll' ) ) {
			$raw = $raw->getAll();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$headers = array();
		foreach ( $raw as $name => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}
			if ( is_scalar( $value ) ) {
				$headers[ strtolower( (string) $name ) ] = (string) $value;
			}
		}

		return $headers;
	}

	/**
	 * Arguments shared by every crawl request.
	 *
	 * @return array
	 */
	private function request_args(): array {
		return array(
			'timeout'             => 8,
			'redirection'         => 3,
			'sslverify'           => true,
			'user-agent'          => $this->user_agent(),
			'limit_response_size' => 524288,
		);
	}

	/**
	 * WordPress's default user agent with the toolkit appended.
	 *
	 * @return string
	 */
	private function user_agent(): string {
		return sprintf(
			'WordPress/%s; %s OpaceEssentialSEOToolkit/%s (+https://opace.agency/tools/browser/)',
			get_bloginfo( 'version' ),
			get_bloginfo( 'url' ),
			OPACE_ESEOT_VERSION
		);
	}

	/* ---------------------------------------------------------------------
	 * robots.txt
	 * ------------------------------------------------------------------ */

	/**
	 * Parse a robots.txt body and decide whether a path is disallowed.
	 *
	 * Rules applied, following Google's documented interpretation of RFC 9309:
	 * - Groups are formed by consecutive User-agent lines followed by rules.
	 *   Only groups for the token "googlebot" matter; when none exists, the
	 *   "*" groups apply. Other product tokens are ignored. Tokens compare
	 *   case-insensitively.
	 * - Within the applicable groups the rule with the longest path pattern
	 *   that matches wins. A tie between Allow and Disallow goes to Allow.
	 * - "*" in a pattern matches any run of characters and a trailing "$"
	 *   anchors the end. Matching is case-sensitive. Empty Disallow/Allow
	 *   values match nothing.
	 * - Sitemap lines are collected regardless of group.
	 *
	 * @param string $body robots.txt body.
	 * @param string $path Path plus query to test.
	 * @return array{sitemaps: string[], disallowed: bool, matched_rule: ?string, agent: ?string}
	 */
	public static function parse_robots( string $body, string $path ): array {
		$sitemaps = array();
		$groups   = array();
		$current  = -1;
		$in_ua    = false;

		$body  = preg_replace( '/^\xEF\xBB\xBF/', '', $body );
		$lines = preg_split( '/\r\n|\r|\n/', (string) $body );

		foreach ( $lines as $line ) {
			$hash = strpos( $line, '#' );
			if ( false !== $hash ) {
				$line = substr( $line, 0, $hash );
			}
			$line = trim( $line );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}

			list( $field, $value ) = explode( ':', $line, 2 );
			$field = strtolower( trim( $field ) );
			$value = trim( $value );

			switch ( $field ) {
				case 'user-agent':
					if ( ! $in_ua ) {
						$groups[] = array(
							'agents' => array(),
							'rules'  => array(),
						);
						++$current;
						$in_ua = true;
					}
					$groups[ $current ]['agents'][] = strtolower( $value );
					break;

				case 'allow':
				case 'disallow':
					$in_ua = false;
					if ( $current < 0 || '' === $value ) {
						break;
					}
					$groups[ $current ]['rules'][] = array(
						'type' => $field,
						'path' => $value,
					);
					break;

				case 'sitemap':
					$in_ua = false;
					if ( preg_match( '#^https?://#i', $value ) ) {
						$sitemaps[] = $value;
					}
					break;

				default:
					$in_ua = false;
					break;
			}
		}

		$agent = null;
		$rules = array();
		foreach ( array( 'googlebot', '*' ) as $token ) {
			foreach ( $groups as $group ) {
				if ( in_array( $token, $group['agents'], true ) ) {
					$agent = $token;
					$rules = array_merge( $rules, $group['rules'] );
				}
			}
			if ( null !== $agent ) {
				break;
			}
		}

		$best       = null;
		$best_score = -1;
		foreach ( $rules as $rule ) {
			if ( ! self::rule_matches( $rule['path'], $path ) ) {
				continue;
			}
			$score = strlen( $rule['path'] );
			if ( $score > $best_score || ( $score === $best_score && 'allow' === $rule['type'] && null !== $best && 'disallow' === $best['type'] ) ) {
				$best       = $rule;
				$best_score = $score;
			}
		}

		return array(
			'sitemaps'     => array_values( array_unique( $sitemaps ) ),
			'disallowed'   => null !== $best && 'disallow' === $best['type'],
			'matched_rule' => null === $best ? null : ucfirst( $best['type'] ) . ': ' . $best['path'],
			'agent'        => $agent,
		);
	}

	/**
	 * Match a robots.txt path pattern (with * and $) against a path.
	 *
	 * @param string $pattern Rule path.
	 * @param string $path    Path plus query.
	 * @return bool
	 */
	private static function rule_matches( string $pattern, string $path ): bool {
		$anchored = '$' === substr( $pattern, -1 );
		if ( $anchored ) {
			$pattern = substr( $pattern, 0, -1 );
		}

		$pieces = array_map(
			static function ( $piece ) {
				return preg_quote( $piece, '#' );
			},
			explode( '*', $pattern )
		);

		$regex = '#^' . implode( '.*', $pieces ) . ( $anchored ? '$' : '' ) . '#';

		return 1 === preg_match( $regex, $path );
	}

	/* ---------------------------------------------------------------------
	 * Panel container and admin assets
	 * ------------------------------------------------------------------ */

	/**
	 * The last stored audit summary for a post, or null.
	 *
	 * @param int $post_id Post id.
	 * @return array|null
	 */
	public static function last_summary_for( int $post_id ): ?array {
		if ( $post_id <= 0 ) {
			return null;
		}
		$summary = get_post_meta( $post_id, self::SUMMARY_META_KEY, true );

		return ( is_array( $summary ) && ! empty( $summary ) ) ? $summary : null;
	}

	/**
	 * Print the panel container. The panel script builds everything inside.
	 *
	 * @param array $args {
	 *     @type string $target_url Address to audit ('' when the post has none yet).
	 *     @type int    $post_id    Post id, 0 for arbitrary URLs.
	 *     @type bool   $autorun    Start as soon as the panel loads.
	 *     @type string $view       'audit' or 'tools' (open the Saved tools view first).
	 *     @type string $mode       'single' or 'bulk'.
	 *     @type array  $targets    Bulk mode: list of {post_id, title, url}.
	 *     @type string $layout     'full' (default) or 'compact': the editor meta box
	 *                              shows a slim card until the user runs the audit.
	 * }
	 */
	public function render_container( array $args = array() ): void {
		$args = wp_parse_args(
			$args,
			array(
				'target_url' => '',
				'post_id'    => 0,
				'autorun'    => null,
				'view'       => 'audit',
				'mode'       => 'single',
				'targets'    => array(),
				'layout'     => 'full',
			)
		);

		$audit   = $this->settings->get_audit_settings();
		$autorun = null === $args['autorun'] ? ! empty( $audit['autorun'] ) : (bool) $args['autorun'];
		$post_id = absint( $args['post_id'] );
		$target  = (string) $args['target_url'];
		$targets = array();

		foreach ( (array) $args['targets'] as $item ) {
			if ( empty( $item['url'] ) ) {
				continue;
			}
			$item_id   = isset( $item['post_id'] ) ? absint( $item['post_id'] ) : 0;
			$targets[] = array(
				'post_id'   => $item_id,
				'title'     => isset( $item['title'] ) ? (string) $item['title'] : '',
				'url'       => (string) $item['url'],
				'nonce_url' => self::build_audit_url( (string) $item['url'], $item_id ),
			);
		}

		if ( 'bulk' === $args['mode'] && '' === $target && ! empty( $targets ) ) {
			$target  = $targets[0]['url'];
			$post_id = $targets[0]['post_id'];
		}

		$tools = array(
			'sources'      => $this->settings->get_sources(),
			'categories'   => (object) $this->settings->get_categories(),
			'placeholders' => Opace_ESEOT_Link_Resolver::placeholders(),
		);
		?>
		<div class="opace-eseot-audit"
			data-target-url="<?php echo esc_url( $target ); ?>"
			data-nonce-url="<?php echo '' !== $target ? esc_url( self::build_audit_url( $target, $post_id ) ) : ''; ?>"
			data-rest-url="<?php echo esc_url( rest_url( self::REST_NAMESPACE . self::REST_ROUTE ) ); ?>"
			data-summary-url="<?php echo esc_url( rest_url( self::REST_NAMESPACE . '/summary' ) ); ?>"
			data-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
			data-autorun="<?php echo $autorun ? '1' : '0'; ?>"
			data-post-id="<?php echo (int) $post_id; ?>"
			data-view="<?php echo esc_attr( 'tools' === $args['view'] ? 'tools' : 'audit' ); ?>"
			data-mode="<?php echo esc_attr( 'bulk' === $args['mode'] ? 'bulk' : 'single' ); ?>"
			data-layout="<?php echo esc_attr( 'compact' === $args['layout'] ? 'compact' : 'full' ); ?>"
			<?php if ( 'bulk' === $args['mode'] ) : ?>
				data-target-urls="<?php echo esc_attr( wp_json_encode( $targets ) ); ?>"
			<?php endif; ?>
			data-engines="<?php echo esc_attr( wp_json_encode( array( 'axe' => ! empty( $audit['engine_axe'] ), 'vitals' => ! empty( $audit['engine_vitals'] ) ) ) ); ?>"
			data-last-summary="<?php echo esc_attr( wp_json_encode( self::last_summary_for( $post_id ) ) ); ?>">
			<noscript><p><?php esc_html_e( 'The page audit needs JavaScript.', 'opace-essential-seo-toolkit' ); ?></p></noscript>
			<?php if ( '' === $target && 'bulk' !== $args['mode'] ) : ?>
				<p class="opace-eseot-audit__no-target"><?php esc_html_e( 'Save the post to get an address to audit.', 'opace-essential-seo-toolkit' ); ?></p>
			<?php endif; ?>
			<script type="application/json" class="opace-eseot-audit__tools"><?php echo wp_json_encode( $tools, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES ); ?></script>
		</div>
		<?php
	}

	/**
	 * Enqueue the panel script and style plus its strings and config.
	 * Called from the editor and Page audit screen enqueues.
	 *
	 * @param int $post_id Post being edited, 0 on the Page audit screen.
	 */
	public function enqueue_panel_assets( int $post_id = 0 ): void {
		$audit = $this->settings->get_audit_settings();

		wp_enqueue_style( 'opace-eseot-audit', OPACE_ESEOT_URL . 'assets/css/opace-eseot-audit.css', array( 'dashicons' ), OPACE_ESEOT_VERSION );
		wp_register_script( 'opace-eseot-audit-resolver', OPACE_ESEOT_URL . 'assets/js/opace-eseot-audit-resolver.js', array(), OPACE_ESEOT_VERSION, true );
		wp_enqueue_script( 'opace-eseot-audit', OPACE_ESEOT_URL . 'assets/js/opace-eseot-audit-panel.js', array( 'opace-eseot-audit-resolver' ), OPACE_ESEOT_VERSION, true );

		$config = array(
			'restUrl'         => esc_url_raw( rest_url( self::REST_NAMESPACE . self::REST_ROUTE ) ),
			'summaryUrl'      => esc_url_raw( rest_url( self::REST_NAMESPACE . '/summary' ) ),
			'restNonce'       => wp_create_nonce( 'wp_rest' ),
			'adminOrigin'     => self::admin_origin(),
			'homeUrl'         => home_url( '/' ),
			'pluginUrl'       => OPACE_ESEOT_URL,
			'settingsUrl'     => $this->settings->page_url( 'audit' ),
			'toolsPageUrl'    => class_exists( 'Opace_ESEOT_Audit_Page' ) ? Opace_ESEOT_Audit_Page::page_url() : '',
			'postId'          => $post_id,
			'lastSummary'     => self::last_summary_for( $post_id ),
			'timeoutMs'       => self::PANEL_TIMEOUT_MS,
			'runnerTimeoutMs' => self::RUNNER_TIMEOUT_MS,
			'autorun'         => ! empty( $audit['autorun'] ),
			'engines'         => array(
				'axe'    => ! empty( $audit['engine_axe'] ),
				'vitals' => ! empty( $audit['engine_vitals'] ),
			),
			'sources'         => $this->settings->get_sources(),
			'categories'      => (object) $this->settings->get_categories(),
			'placeholders'    => Opace_ESEOT_Link_Resolver::placeholders(),
			'version'         => OPACE_ESEOT_VERSION,
		);

		wp_add_inline_script( 'opace-eseot-audit', 'window.opaceEseotAuditConfig = ' . wp_json_encode( $config ) . ';', 'before' );

		wp_localize_script(
			'opace-eseot-audit',
			'opaceEseotAuditL10n',
			array(
				'runAudit'    => __( 'Run audit', 'opace-essential-seo-toolkit' ),
				'running'     => __( 'Auditing…', 'opace-essential-seo-toolkit' ),
				'rerun'       => __( 'Run again', 'opace-essential-seo-toolkit' ),
				'copySummary' => __( 'Copy summary', 'opace-essential-seo-toolkit' ),
				'copied'      => __( 'Copied', 'opace-essential-seo-toolkit' ),
				'collapse'    => __( 'Collapse', 'opace-essential-seo-toolkit' ),
				'pageAudit'   => __( 'Page audit', 'opace-essential-seo-toolkit' ),
				'notAudited'  => __( 'Not audited yet', 'opace-essential-seo-toolkit' ),
				'openReport'  => __( 'Open full report', 'opace-essential-seo-toolkit' ),
				'settings'    => __( 'Settings', 'opace-essential-seo-toolkit' ),
				'tabs'        => array(
					'overview' => __( 'Overview', 'opace-essential-seo-toolkit' ),
					'details'  => __( 'All details', 'opace-essential-seo-toolkit' ),
					'engines'  => __( 'Local engines', 'opace-essential-seo-toolkit' ),
					'crawl'    => __( 'Crawl', 'opace-essential-seo-toolkit' ),
					'tools'    => __( 'Saved tools', 'opace-essential-seo-toolkit' ),
				),
				'statuses'    => array(
					'pass'   => __( 'Pass', 'opace-essential-seo-toolkit' ),
					'review' => __( 'Review', 'opace-essential-seo-toolkit' ),
					'info'   => __( 'Info', 'opace-essential-seo-toolkit' ),
				),
				'openInTab'   => __( 'Open the page in a new tab to run the audit', 'opace-essential-seo-toolkit' ),
				'timeout'     => __( 'The page did not report back. It may block framing or have a script error.', 'opace-essential-seo-toolkit' ),
				'noPermalink' => __( 'Save the post to get an address to audit.', 'opace-essential-seo-toolkit' ),
				'crawlError'  => __( 'Crawl check unavailable.', 'opace-essential-seo-toolkit' ),
				/* translators: %s: name of a saved tool. */
				'verifyWith'  => __( 'Verify with %s', 'opace-essential-seo-toolkit' ),
				'noTools'     => __( 'No saved tools match this finding.', 'opace-essential-seo-toolkit' ),
				/* translators: %s: date and time of the audit. */
				'auditedAt'   => __( 'Audited %s', 'opace-essential-seo-toolkit' ),
			)
		);
	}
}
