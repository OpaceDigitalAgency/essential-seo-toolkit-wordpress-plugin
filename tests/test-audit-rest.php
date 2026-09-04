<?php
/**
 * Tests for GET /opace-eseot/v1/crawl (PLAN-PHASE2-AUDIT.md "REST crawl endpoint").
 *
 * @package Opace_ESEOT_Tests
 */

require_once __DIR__ . '/includes/trait-opace-eseot-audit-http-mock.php';

class AuditRestTest extends WP_UnitTestCase {
	use Opace_ESEOT_Test_Helpers;
	use Opace_ESEOT_Audit_Http_Mock;

	const ROUTE = '/opace-eseot/v1/crawl';

	/**
	 * @var int
	 */
	protected static $admin_id;

	/**
	 * @var int
	 */
	protected static $subscriber_id;

	/**
	 * @var Spy_REST_Server
	 */
	protected $server;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin_id      = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'Opace_ESEOT_Audit' ) ) {
			$this->fail( 'Opace_ESEOT_Audit is not loaded. WS-9 has not landed yet or the plugin failed to bootstrap.' );
		}

		global $wp_rest_server;
		$wp_rest_server = new Spy_REST_Server();
		$this->server   = $wp_rest_server;
		do_action( 'rest_api_init', $wp_rest_server );

		$this->eseot_mock_http();
		$this->eseot_delete_crawl_transients();
	}

	public function tear_down() {
		$this->eseot_unmock_http();
		$this->eseot_delete_crawl_transients();

		global $wp_rest_server;
		$wp_rest_server = null;
		unset( $_SERVER['HTTP_X_WP_NONCE'], $GLOBALS['wp_rest_auth_cookie'] );
		$_GET = array();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/* ------------------------------------------------------------------ */

	protected function page_url() {
		return home_url( '/hello-world/' );
	}

	/**
	 * Dispatch a GET to the crawl route.
	 *
	 * @param string $url   url argument.
	 * @param array  $extra Extra query params.
	 * @return WP_REST_Response
	 */
	protected function crawl_request( $url, array $extra = array() ) {
		$request = new WP_REST_Request( 'GET', self::ROUTE );
		$request->set_query_params( array_merge( array( 'url' => $url ), $extra ) );
		return rest_do_request( $request );
	}

	/**
	 * A healthy site: page 200 via HEAD, robots.txt with one Sitemap line, sitemap 200.
	 */
	protected function mock_healthy_site() {
		$home = home_url();
		$this->eseot_route(
			'HEAD',
			$this->page_url(),
			$this->eseot_http_response(
				200,
				array(
					'Content-Type'   => 'text/html; charset=UTF-8',
					'Cache-Control'  => 'max-age=0, no-cache',
					'X-Robots-Tag'   => 'max-image-preview:large',
					'Content-Length' => '12345',
				)
			)
		);
		$this->eseot_route(
			'GET',
			$home . '/robots.txt',
			$this->eseot_http_response(
				200,
				array( 'Content-Type' => 'text/plain; charset=utf-8' ),
				"User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: {$home}/wp-sitemap.xml\n"
			)
		);
		$this->eseot_route(
			'HEAD',
			$home . '/wp-sitemap.xml',
			$this->eseot_http_response( 200, array( 'Content-Type' => 'application/xml; charset=UTF-8' ) )
		);
	}

	/* ------------------------------------------------------------------ */

	public function test_route_is_registered_as_get_with_permission_callback() {
		$routes = $this->server->get_routes();
		$this->assertArrayHasKey( self::ROUTE, $routes );
		$endpoint = $routes[ self::ROUTE ][0];
		$this->assertArrayHasKey( 'GET', $endpoint['methods'] );
		$this->assertNotEmpty( $endpoint['permission_callback'] );
		$this->assertTrue( $endpoint['args']['url']['required'] );
		$this->assertSame( 'boolean', $endpoint['args']['fresh']['type'] );
	}

	public function test_anonymous_request_is_refused_with_401() {
		wp_set_current_user( 0 );
		$this->mock_healthy_site();

		$response = $this->crawl_request( $this->page_url() );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'opace_eseot_rest_login_required', $response->get_data()['code'] );
		$this->assertSame( 0, $this->eseot_http_calls(), 'No HTTP request may be made for a refused caller.' );
	}

	public function test_user_without_edit_posts_is_refused_with_403() {
		wp_set_current_user( self::$subscriber_id );
		$this->mock_healthy_site();

		$response = $this->crawl_request( $this->page_url() );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'opace_eseot_rest_forbidden', $response->get_data()['code'] );
		$this->assertSame( 0, $this->eseot_http_calls() );
	}

	public function test_wrong_rest_nonce_is_refused_with_403() {
		wp_set_current_user( self::$admin_id );
		$this->mock_healthy_site();

		$GLOBALS['wp_rest_auth_cookie'] = true;
		$_SERVER['HTTP_X_WP_NONCE']     = 'not-a-nonce';
		$_SERVER['REQUEST_METHOD']      = 'GET';
		$_GET                           = array( 'url' => $this->page_url() );

		$this->server->serve_request( self::ROUTE );
		$body = $this->server->sent_body;
		$data = json_decode( $body, true );

		$this->assertIsArray( $data, 'Body: ' . $body );
		$this->assertSame( 403, $this->server->status );
		$this->assertSame( 'rest_cookie_invalid_nonce', $data['code'] );
		$this->assertSame( 0, $this->eseot_http_calls() );
	}

	public function test_valid_rest_nonce_over_cookie_auth_succeeds() {
		wp_set_current_user( self::$admin_id );
		$this->mock_healthy_site();

		$GLOBALS['wp_rest_auth_cookie'] = true;
		$_SERVER['HTTP_X_WP_NONCE']     = wp_create_nonce( 'wp_rest' );
		$_SERVER['REQUEST_METHOD']      = 'GET';
		$_GET                           = array( 'url' => $this->page_url() );

		$this->server->serve_request( self::ROUTE );
		$body = $this->server->sent_body;
		$data = json_decode( $body, true );

		$this->assertIsArray( $data, 'Body: ' . $body );
		$this->assertSame( 200, $this->server->status );
		$this->assertSame( $this->page_url(), $data['url'] );
		$this->assertSame( 200, $data['status'] );
	}

	public function test_offsite_url_is_refused_with_400() {
		wp_set_current_user( self::$admin_id );

		$response = $this->crawl_request( 'https://example.net/some/page/' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertArrayHasKey( 'url', $response->get_data()['data']['params'] );
		$this->assertSame( 0, $this->eseot_http_calls() );
	}

	/**
	 * @dataProvider data_bad_schemes
	 */
	public function test_non_http_scheme_or_relative_url_is_refused_with_400( $url ) {
		wp_set_current_user( self::$admin_id );

		$response = $this->crawl_request( $url );

		$this->assertSame( 400, $response->get_status(), "Expected 400 for {$url}" );
		$this->assertSame( 'rest_invalid_param', $response->get_data()['code'] );
		$this->assertSame( 0, $this->eseot_http_calls() );
	}

	public function data_bad_schemes() {
		return array(
			'ftp'         => array( 'ftp://example.org/file.txt' ),
			'javascript'  => array( 'javascript:alert(1)' ),
			'schemeless'  => array( 'example.org/hello-world/' ),
			'relative'    => array( '/hello-world/' ),
			'mailto'      => array( 'mailto:someone@example.org' ),
			'empty'       => array( '' ),
		);
	}

	public function test_missing_url_is_refused_with_400() {
		wp_set_current_user( self::$admin_id );

		$request  = new WP_REST_Request( 'GET', self::ROUTE );
		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_missing_callback_param', $response->get_data()['code'] );
	}

	public function test_valid_request_returns_the_contract_shape() {
		wp_set_current_user( self::$admin_id );
		$this->mock_healthy_site();

		$response = $this->crawl_request( $this->page_url() );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );

		$this->assertSame( $this->page_url(), $data['url'] );
		$this->assertSame( $this->page_url(), $data['final_url'] );
		$this->assertSame( 200, $data['status'] );
		$this->assertFalse( $data['redirected'] );
		$this->assertSame( 'HEAD', $data['method'] );
		$this->assertNull( $data['error'] );
		$this->assertFalse( $data['cached'] );

		$expected_header_keys = array(
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
		$this->assertSame( $expected_header_keys, array_keys( $data['headers'] ), 'Headers are the ten listed names, lower-cased, in order.' );
		$this->assertSame( 'text/html; charset=UTF-8', $data['headers']['content-type'] );
		$this->assertSame( 'max-age=0, no-cache', $data['headers']['cache-control'] );
		$this->assertSame( 'max-image-preview:large', $data['headers']['x-robots-tag'] );
		foreach ( array( 'strict-transport-security', 'content-security-policy', 'x-content-type-options', 'x-frame-options', 'referrer-policy', 'permissions-policy', 'server' ) as $absent ) {
			$this->assertArrayHasKey( $absent, $data['headers'] );
			$this->assertNull( $data['headers'][ $absent ], "Missing header {$absent} must be null." );
		}

		$this->assertSame( 12345, $data['content_length'] );
		$this->assertIsInt( $data['response_ms'] );
		$this->assertGreaterThanOrEqual( 0, $data['response_ms'] );

		$this->assertSame(
			array(
				'status'              => 200,
				'found'               => true,
				'sitemaps'            => array( home_url() . '/wp-sitemap.xml' ),
				'disallowed_for_path' => false,
				'matched_rule'        => null,
			),
			$data['robots_txt']
		);

		$this->assertCount( 1, $data['sitemaps'] );
		$this->assertSame( home_url() . '/wp-sitemap.xml', $data['sitemaps'][0]['url'] );
		$this->assertSame( 200, $data['sitemaps'][0]['status'] );
		$this->assertTrue( $data['sitemaps'][0]['found'] );
		$this->assertSame( 'robots', $data['sitemaps'][0]['source'] );

		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $data['fetched_at'], 'fetched_at is gmdate("c").' );

		$this->assertSame( 1, $this->eseot_http_calls( 'HEAD ' . $this->page_url() ) );
		$this->assertSame( 0, $this->eseot_http_calls( 'GET ' . $this->page_url() ), 'GET is not used when HEAD answered with headers.' );
	}

	public function test_head_refused_falls_back_to_get() {
		wp_set_current_user( self::$admin_id );
		$this->mock_healthy_site();
		$this->eseot_route( 'HEAD', $this->page_url(), $this->eseot_http_response( 405, array( 'Allow' => 'GET' ) ) );
		$this->eseot_route(
			'GET',
			$this->page_url(),
			$this->eseot_http_response( 200, array( 'content-type' => 'text/html', 'X-Frame-Options' => 'SAMEORIGIN' ), str_repeat( 'x', 2048 ) )
		);

		$data = $this->crawl_request( $this->page_url() )->get_data();

		$this->assertSame( 200, $data['status'] );
		$this->assertSame( 'GET', $data['method'] );
		$this->assertSame( 'SAMEORIGIN', $data['headers']['x-frame-options'] );
		$this->assertSame( 2048, $data['content_length'], 'Without Content-Length the GET body length is used.' );
		$this->assertSame( 1, $this->eseot_http_calls( 'HEAD ' . $this->page_url() ) );
		$this->assertSame( 1, $this->eseot_http_calls( 'GET ' . $this->page_url() ) );
	}

	public function test_failed_fetch_reports_error_and_is_not_cached() {
		wp_set_current_user( self::$admin_id );
		$this->mock_healthy_site();
		$this->eseot_route( 'HEAD', $this->page_url(), new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) );
		$this->eseot_route( 'GET', $this->page_url(), new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) );

		$data = $this->crawl_request( $this->page_url() )->get_data();

		$this->assertSame( 0, $data['status'] );
		$this->assertStringContainsString( 'timed out', $data['error'] );
		$this->assertNull( $data['headers']['content-type'] );
		$this->assertFalse( get_transient( Opace_ESEOT_Audit::cache_key( $this->page_url() ) ), 'Failures are not cached so a retry really retries.' );
	}

	public function test_unreachable_origin_stops_after_the_page_probe_pair() {
		wp_set_current_user( self::$admin_id );
		$this->mock_healthy_site();
		$this->eseot_route( 'HEAD', $this->page_url(), new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) );
		$this->eseot_route( 'GET', $this->page_url(), new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) );

		$data = $this->crawl_request( $this->page_url() )->get_data();

		$this->assertSame( 0, $data['status'] );
		$this->assertStringContainsString( 'Failed to connect', $data['error'] );
		$this->assertSame( 2, $this->eseot_http_calls(), 'HEAD and GET on the page only; robots.txt and sitemaps are not probed on a dead origin.' );
		$this->assertSame( 0, $data['robots_txt']['status'] );
		$this->assertFalse( $data['robots_txt']['found'] );
		$this->assertSame( array(), $data['sitemaps'] );
		$this->assertSame( 0, $this->eseot_count_crawl_transients(), 'Nothing about a dead origin is cached.' );

		// The next crawl starts afresh: a reachable page probes the origin again.
		$this->eseot_route( 'HEAD', $this->page_url(), $this->eseot_http_response( 200, array( 'Content-Type' => 'text/html' ) ) );
		$ok = $this->crawl_request( $this->page_url() )->get_data();
		$this->assertSame( 200, $ok['status'] );
		$this->assertTrue( $ok['robots_txt']['found'] );
	}

	public function test_result_is_served_from_transient_until_fresh_is_requested() {
		wp_set_current_user( self::$admin_id );
		$this->mock_healthy_site();

		$first = $this->crawl_request( $this->page_url() )->get_data();
		$calls = $this->eseot_http_calls();
		$this->assertGreaterThan( 0, $calls );
		$this->assertFalse( $first['cached'] );
		$this->assertNotFalse( get_transient( Opace_ESEOT_Audit::cache_key( $this->page_url() ) ) );

		$second = $this->crawl_request( $this->page_url() )->get_data();
		$this->assertTrue( $second['cached'] );
		$this->assertSame( $calls, $this->eseot_http_calls(), 'A second call within 60 s must not hit HTTP.' );
		$this->assertSame( $first['fetched_at'], $second['fetched_at'] );
		$this->assertSame( $first['headers'], $second['headers'] );

		$third = $this->crawl_request( $this->page_url(), array( 'fresh' => '1' ) )->get_data();
		$this->assertFalse( $third['cached'] );
		$this->assertGreaterThan( $calls, $this->eseot_http_calls(), 'fresh=1 bypasses the cache.' );
	}

	public function test_same_site_check_accepts_home_and_site_hosts_case_insensitively() {
		$this->assertTrue( Opace_ESEOT_Audit::is_same_site_url( home_url( '/x/' ) ) );
		$this->assertTrue( Opace_ESEOT_Audit::is_same_site_url( 'HTTP://EXAMPLE.ORG/x/' ) );
		$this->assertTrue( Opace_ESEOT_Audit::is_same_site_url( 'https://example.org:8443/x/' ), 'Ports are ignored; the HTTP layer decides safety.' );
		$this->assertFalse( Opace_ESEOT_Audit::is_same_site_url( 'https://www.example.org/x/' ) );
		$this->assertFalse( Opace_ESEOT_Audit::is_same_site_url( 'https://example.net/' ) );

		$filter = static function () {
			return 'http://wp.example.net';
		};
		add_filter( 'site_url', $filter );
		$this->assertTrue( Opace_ESEOT_Audit::is_same_site_url( 'http://wp.example.net/wp-login.php' ), 'The site_url() host is allowed too.' );
		remove_filter( 'site_url', $filter );
		$this->assertFalse( Opace_ESEOT_Audit::is_same_site_url( 'http://wp.example.net/wp-login.php' ) );
	}
}
