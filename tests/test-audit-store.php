<?php
/**
 * Tests for Opace_ESEOT_Audit_Store: REST permission matrix, storage shape,
 * URL-keyed cap, recent() ordering, format_counts() and delete_all().
 *
 * Contract (PLAN-PHASE2-AUDIT.md addendum item 5 and the WS-9b brief).
 *
 * @package Opace_ESEOT_Tests
 */

if ( ! class_exists( 'Opace_ESEOT_Audit_Store' ) && defined( 'OPACE_ESEOT_FILE' ) ) {
	require_once dirname( OPACE_ESEOT_FILE ) . '/includes/class-opace-eseot-audit-store.php';
}

class AuditStoreTest extends WP_UnitTestCase {
	use Opace_ESEOT_Test_Helpers;

	protected $admin;
	protected $editor;
	protected $contributor;
	protected $post_id;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'Opace_ESEOT_Audit_Store' ) ) {
			$this->fail( 'Opace_ESEOT_Audit_Store is not loaded.' );
		}

		Opace_ESEOT_Audit_Store::init();
		Opace_ESEOT_Audit_Store::register_meta();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		delete_option( Opace_ESEOT_Audit_Store::OPTION_URLS );

		$this->admin       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->editor      = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$this->post_id     = self::factory()->post->create(
			array(
				'post_title'  => 'Audit store post',
				'post_status' => 'publish',
				'post_author' => $this->admin,
			)
		);
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		delete_option( Opace_ESEOT_Audit_Store::OPTION_URLS );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	protected function valid_body( array $overrides = array() ) {
		return array_merge(
			array(
				'post_id'    => $this->post_id,
				'url'        => get_permalink( $this->post_id ),
				'counts'     => array(
					'pass'   => 9,
					'review' => 2,
					'info'   => 3,
				),
				'top_review' => array( 'Missing meta description', 'Image without alt text' ),
				'audited_at' => '2020-01-01T00:00:00.000Z',
				'vitals'     => array(
					'LCP' => 1234.5,
					'CLS' => 0.0123456,
				),
			),
			$overrides
		);
	}

	protected function post_summary( array $body ) {
		$request = new WP_REST_Request( 'POST', '/opace-eseot/v1/summary' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		return rest_get_server()->dispatch( $request );
	}

	public function test_constants_and_meta_registration() {
		$this->assertSame( '_opace_eseot_audit_summary', Opace_ESEOT_Audit_Store::META_KEY );
		$this->assertSame( '_opace_eseot_audit_time', Opace_ESEOT_Audit_Store::TIME_META_KEY );
		$this->assertSame( 'eseot_audit_summaries_urls', Opace_ESEOT_Audit_Store::OPTION_URLS );

		$keys = get_registered_meta_keys( 'post' );
		$this->assertArrayHasKey( Opace_ESEOT_Audit_Store::META_KEY, $keys );
		$this->assertFalse( $keys[ Opace_ESEOT_Audit_Store::META_KEY ]['show_in_rest'] );
		$this->assertTrue( $keys[ Opace_ESEOT_Audit_Store::META_KEY ]['single'] );
		$this->assertSame( 'array', $keys[ Opace_ESEOT_Audit_Store::META_KEY ]['type'] );
		$this->assertArrayHasKey( Opace_ESEOT_Audit_Store::TIME_META_KEY, $keys );
		$this->assertFalse( $keys[ Opace_ESEOT_Audit_Store::TIME_META_KEY ]['show_in_rest'] );

		// Protected key (leading underscore) is hidden from the custom fields box.
		$this->assertTrue( is_protected_meta( Opace_ESEOT_Audit_Store::META_KEY, 'post' ) );

		// The auth callback gates edit_post_meta on edit_post.
		wp_set_current_user( $this->admin );
		$this->assertTrue( current_user_can( 'edit_post_meta', $this->post_id, Opace_ESEOT_Audit_Store::META_KEY ) );
		wp_set_current_user( $this->contributor );
		$this->assertFalse( current_user_can( 'edit_post_meta', $this->post_id, Opace_ESEOT_Audit_Store::META_KEY ) );
	}

	public function test_route_is_registered() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/opace-eseot/v1/summary', $routes );
		$this->assertContains( 'POST', array_keys( $routes['/opace-eseot/v1/summary'][0]['methods'] ) );
	}

	public function test_anonymous_gets_401() {
		wp_set_current_user( 0 );
		$response = $this->post_summary( $this->valid_body() );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( '', get_post_meta( $this->post_id, Opace_ESEOT_Audit_Store::META_KEY, true ) );
	}

	public function test_user_who_cannot_edit_post_gets_403() {
		wp_set_current_user( $this->contributor );
		$response = $this->post_summary( $this->valid_body() );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( '', get_post_meta( $this->post_id, Opace_ESEOT_Audit_Store::META_KEY, true ) );
	}

	public function test_unknown_post_gets_404() {
		wp_set_current_user( $this->admin );
		$response = $this->post_summary( $this->valid_body( array( 'post_id' => 999999 ) ) );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_wrong_host_gets_400() {
		wp_set_current_user( $this->admin );

		$response = $this->post_summary( $this->valid_body( array( 'url' => 'https://evil.example.com/hello-world/' ) ) );
		$this->assertSame( 400, $response->get_status() );

		$response = $this->post_summary( $this->valid_body( array( 'url' => 'ftp://example.org/hello-world/' ) ) );
		$this->assertSame( 400, $response->get_status() );

		$response = $this->post_summary( $this->valid_body( array( 'url' => '/relative/' ) ) );
		$this->assertSame( 400, $response->get_status() );

		$this->assertSame( '', get_post_meta( $this->post_id, Opace_ESEOT_Audit_Store::META_KEY, true ) );
	}

	public function test_unknown_keys_are_rejected() {
		wp_set_current_user( $this->admin );

		$response = $this->post_summary( $this->valid_body( array( 'extra' => 'nope' ) ) );
		$this->assertSame( 400, $response->get_status() );

		$body           = $this->valid_body();
		$body['counts'] = array(
			'pass'   => 1,
			'review' => 1,
			'info'   => 1,
			'bogus'  => 1,
		);
		$response       = $this->post_summary( $body );
		$this->assertSame( 400, $response->get_status() );

		$body           = $this->valid_body();
		$body['vitals'] = array( 'FOO' => 1 );
		$response       = $this->post_summary( $body );
		$this->assertSame( 400, $response->get_status() );

		$body               = $this->valid_body();
		$body['audited_at'] = 'yesterday';
		$response           = $this->post_summary( $body );
		$this->assertSame( 400, $response->get_status() );

		$body           = $this->valid_body();
		$body['counts'] = array(
			'pass'   => -1,
			'review' => 0,
			'info'   => 0,
		);
		$response       = $this->post_summary( $body );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_valid_request_stores_summary_and_time_meta() {
		wp_set_current_user( $this->admin );
		$before = time();

		$long   = str_repeat( 'x', 150 );
		$labels = array( '<b>Missing</b> meta description', $long, 'Three', 'Four', 'Five', 'Six', 'Seven' );
		$body   = $this->valid_body(
			array(
				'top_review' => $labels,
				'vitals'     => array(
					'LCP'  => 1234.5,
					'CLS'  => 0.0123456,
					'INP'  => '210',
					'TTFB' => 80,
				),
			)
		);

		$response = $this->post_summary( $body );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );

		$stored = get_post_meta( $this->post_id, Opace_ESEOT_Audit_Store::META_KEY, true );
		$this->assertIsArray( $stored );
		$this->assertSame( array( 'url', 'counts', 'top_review', 'audited_at', 'vitals', 'user_id' ), array_keys( $stored ) );

		$this->assertSame( get_permalink( $this->post_id ), $stored['url'] );
		$this->assertSame( array( 'pass' => 9, 'review' => 2, 'info' => 3 ), $stored['counts'] );

		$this->assertCount( 5, $stored['top_review'], 'top_review is capped at five labels.' );
		$this->assertSame( 'Missing meta description', $stored['top_review'][0], 'Labels are stripped of tags.' );
		$this->assertSame( 120, strlen( $stored['top_review'][1] ), 'Labels are capped at 120 characters.' );

		$this->assertSame( array( 'LCP' => 1234.5, 'CLS' => 0.012, 'INP' => 210.0, 'TTFB' => 80.0 ), $stored['vitals'] );

		$this->assertSame( $this->admin, $stored['user_id'] );

		// audited_at is server time, not the client value.
		$this->assertNotSame( '2020-01-01T00:00:00.000Z', $stored['audited_at'] );
		$audited = strtotime( $stored['audited_at'] );
		$this->assertGreaterThanOrEqual( $before, $audited );
		$this->assertLessThanOrEqual( time() + 5, $audited );

		$time = (int) get_post_meta( $this->post_id, Opace_ESEOT_Audit_Store::TIME_META_KEY, true );
		$this->assertGreaterThanOrEqual( $before, $time );
		$this->assertLessThanOrEqual( time() + 5, $time );

		// Response echoes the stored summary plus the post id.
		$data = $response->get_data();
		$this->assertSame( $this->post_id, $data['post_id'] );
		unset( $data['post_id'] );
		$this->assertSame( $stored, $data );

		// Getter agrees.
		$this->assertSame( $stored, Opace_ESEOT_Audit_Store::get_summary( $this->post_id ) );
		$this->assertSame( $time, Opace_ESEOT_Audit_Store::get_time( $this->post_id ) );
		$this->assertNull( Opace_ESEOT_Audit_Store::get_summary( 0 ) );
	}

	public function test_editor_can_store_for_own_editable_post() {
		wp_set_current_user( $this->editor );
		$response = $this->post_summary( $this->valid_body() );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $this->editor, Opace_ESEOT_Audit_Store::get_summary( $this->post_id )['user_id'] );
	}

	public function test_url_keyed_storage_requires_edit_others_posts() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );
		$response = $this->post_summary(
			$this->valid_body(
				array(
					'post_id' => 0,
					'url'     => home_url( '/' ),
				)
			)
		);
		$this->assertSame( 403, $response->get_status() );
		$this->assertFalse( get_option( Opace_ESEOT_Audit_Store::OPTION_URLS ) );
	}

	public function test_url_keyed_storage_and_cap() {
		wp_set_current_user( $this->admin );
		$home = home_url( '/' );

		$response = $this->post_summary(
			$this->valid_body(
				array(
					'post_id' => 0,
					'url'     => $home,
				)
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, $response->get_data()['post_id'] );

		$option = get_option( Opace_ESEOT_Audit_Store::OPTION_URLS );
		$this->assertIsArray( $option );
		$this->assertArrayHasKey( md5( $home ), $option );
		$this->assertSame( $home, $option[ md5( $home ) ]['url'] );

		$summary = Opace_ESEOT_Audit_Store::get_url_summary( $home );
		$this->assertNotNull( $summary );
		$this->assertSame( array( 'pass' => 9, 'review' => 2, 'info' => 3 ), $summary['counts'] );
		$this->assertNull( Opace_ESEOT_Audit_Store::get_url_summary( home_url( '/missing/' ) ) );

		// Nothing written to any post.
		$this->assertSame( '', get_post_meta( $this->post_id, Opace_ESEOT_Audit_Store::META_KEY, true ) );

		// Cap at 50: the home page entry (the oldest) is dropped once 50 newer URLs arrive.
		for ( $i = 1; $i <= 50; $i++ ) {
			$result = Opace_ESEOT_Audit_Store::store( 0, home_url( '/archive-' . $i . '/' ), array( 'pass' => $i, 'review' => 0, 'info' => 0 ) );
			$this->assertIsArray( $result );
		}
		$option = get_option( Opace_ESEOT_Audit_Store::OPTION_URLS );
		$this->assertCount( 50, $option );
		$this->assertArrayNotHasKey( md5( $home ), $option, 'Oldest entry is dropped.' );
		$this->assertArrayHasKey( md5( home_url( '/archive-1/' ) ), $option );
		$this->assertArrayHasKey( md5( home_url( '/archive-50/' ) ), $option );

		// Re-storing an existing URL replaces it and moves it to the newest slot without growing the list.
		Opace_ESEOT_Audit_Store::store( 0, home_url( '/archive-1/' ), array( 'pass' => 99, 'review' => 0, 'info' => 0 ) );
		$option = get_option( Opace_ESEOT_Audit_Store::OPTION_URLS );
		$this->assertCount( 50, $option );
		$this->assertSame( md5( home_url( '/archive-1/' ) ), array_key_last( $option ) );
		$this->assertSame( 99, Opace_ESEOT_Audit_Store::get_url_summary( home_url( '/archive-1/' ) )['counts']['pass'] );
	}

	public function test_store_rejects_foreign_host_and_unknown_post() {
		wp_set_current_user( $this->admin );
		$this->assertWPError( Opace_ESEOT_Audit_Store::store( $this->post_id, 'https://evil.example.com/', array() ) );
		$this->assertWPError( Opace_ESEOT_Audit_Store::store( 999999, home_url( '/' ), array() ) );
	}

	public function test_recent_orders_newest_first_across_posts_and_urls() {
		wp_set_current_user( $this->admin );
		$now = time();

		$a = self::factory()->post->create( array( 'post_title' => 'A oldest' ) );
		$b = self::factory()->post->create( array( 'post_title' => 'B newest' ) );
		$c = self::factory()->post->create( array( 'post_title' => 'C middle' ) );

		foreach ( array( $a => 300, $b => 100, $c => 200 ) as $id => $age ) {
			Opace_ESEOT_Audit_Store::store( $id, get_permalink( $id ), array( 'pass' => $age, 'review' => 0, 'info' => 0 ) );
			update_post_meta( $id, Opace_ESEOT_Audit_Store::TIME_META_KEY, $now - $age );
		}

		// A URL summary between B and C.
		Opace_ESEOT_Audit_Store::store( 0, home_url( '/' ), array( 'pass' => 150, 'review' => 0, 'info' => 0 ) );
		$option = get_option( Opace_ESEOT_Audit_Store::OPTION_URLS );
		$option[ md5( home_url( '/' ) ) ]['audited_at'] = gmdate( 'c', $now - 150 );
		update_option( Opace_ESEOT_Audit_Store::OPTION_URLS, $option, false );

		$rows = Opace_ESEOT_Audit_Store::recent( 10 );
		$this->assertSame( array( $b, 0, $c, $a ), array_column( $rows, 'post_id' ) );
		$this->assertSame( 'B newest', $rows[0]['title'] );
		$this->assertSame( home_url( '/' ), $rows[1]['url'] );
		$this->assertSame( '', $rows[1]['title'] );
		foreach ( $rows as $row ) {
			$this->assertSame( array( 'post_id', 'title', 'url', 'counts', 'top_review', 'audited_at', 'time', 'vitals', 'user_id' ), array_keys( $row ) );
		}

		$this->assertSame( array( $b, 0 ), array_column( Opace_ESEOT_Audit_Store::recent( 2 ), 'post_id' ) );
		$this->assertCount( 1, Opace_ESEOT_Audit_Store::recent( 0 ), 'Limit below 1 is clamped to 1, not an error.' );
	}

	public function test_format_counts() {
		$summary = array( 'counts' => array( 'pass' => 9, 'review' => 2, 'info' => 3 ) );
		$this->assertSame( '9 pass · 2 review · 3 info', Opace_ESEOT_Audit_Store::format_counts( $summary ) );
		$this->assertSame( '0 pass · 0 review · 0 info', Opace_ESEOT_Audit_Store::format_counts( array() ) );
		$this->assertSame( '1 pass · 0 review · 0 info', Opace_ESEOT_Audit_Store::format_counts( array( 'counts' => array( 'pass' => '1' ) ) ) );
	}

	public function test_delete_all_removes_meta_and_option() {
		wp_set_current_user( $this->admin );
		Opace_ESEOT_Audit_Store::store( $this->post_id, get_permalink( $this->post_id ), array( 'pass' => 1, 'review' => 0, 'info' => 0 ) );
		Opace_ESEOT_Audit_Store::store( 0, home_url( '/' ), array( 'pass' => 1, 'review' => 0, 'info' => 0 ) );
		$this->assertNotNull( Opace_ESEOT_Audit_Store::get_summary( $this->post_id ) );
		$this->assertNotFalse( get_option( Opace_ESEOT_Audit_Store::OPTION_URLS ) );

		Opace_ESEOT_Audit_Store::delete_all();
		wp_cache_flush();

		$this->assertNull( Opace_ESEOT_Audit_Store::get_summary( $this->post_id ) );
		$this->assertSame( 0, Opace_ESEOT_Audit_Store::get_time( $this->post_id ) );
		$this->assertFalse( get_option( Opace_ESEOT_Audit_Store::OPTION_URLS ) );
	}

	public function test_init_is_idempotent() {
		Opace_ESEOT_Audit_Store::init();
		Opace_ESEOT_Audit_Store::init();
		$this->assertSame( 10, has_action( 'rest_api_init', array( 'Opace_ESEOT_Audit_Store', 'register_routes' ) ) );
	}
}
