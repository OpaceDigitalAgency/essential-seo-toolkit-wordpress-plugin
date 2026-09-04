<?php
/**
 * Tests for front-end runner activation, audit targets, the panel container,
 * the Audit settings and uninstall additions (PLAN-PHASE2-AUDIT.md).
 *
 * @package Opace_ESEOT_Tests
 */

require_once __DIR__ . '/includes/trait-opace-eseot-audit-http-mock.php';

class AuditActivationTest extends WP_UnitTestCase {
	use Opace_ESEOT_Test_Helpers;
	use Opace_ESEOT_Audit_Http_Mock;

	/**
	 * @var int
	 */
	protected static $admin_id;

	/**
	 * @var int
	 */
	protected static $subscriber_id;

	/**
	 * @var WP_Post
	 */
	protected $post;

	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin_id      = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'Opace_ESEOT_Audit' ) || ! class_exists( 'Opace_ESEOT_Audit_Page' ) ) {
			$this->fail( 'Opace_ESEOT_Audit classes are not loaded. WS-9 has not landed yet or the plugin failed to bootstrap.' );
		}
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/post.php';

		$this->eseot_delete_options();
		delete_option( 'eseot_audit' );
		Opace_ESEOT_Plugin::activate();
		update_option( 'eseot_post_types', array( 'post' => 1 ) );
		wp_cache_flush();

		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;

		wp_set_current_user( self::$admin_id );
		$this->post = self::factory()->post->create_and_get(
			array(
				'post_title'  => 'Audit test post',
				'post_status' => 'publish',
			)
		);
	}

	public function tear_down() {
		global $wp_meta_boxes;
		$wp_meta_boxes         = array();
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
		unset( $GLOBALS['post'] );
		$_GET = array();
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
		$this->eseot_delete_options();
		delete_option( 'eseot_audit' );
		parent::tear_down();
	}

	/* ------------------------------------------------------------------ */

	protected function audit() {
		return Opace_ESEOT_Plugin::instance()->audit();
	}

	/**
	 * Load a front-end URL the way WordPress would (go_to() fires the 'wp'
	 * action where activation hooks) and then the head enqueues.
	 *
	 * @param string $url URL.
	 */
	protected function visit( $url ) {
		$this->go_to( $url );
		do_action( 'wp_enqueue_scripts' );
	}

	protected function robots_output() {
		ob_start();
		wp_robots();
		return ob_get_clean();
	}

	/**
	 * Decode the window.opaceEseotAudit config printed before the runner.
	 *
	 * @return array|null
	 */
	protected function runner_config() {
		$scripts = wp_scripts();
		if ( ! isset( $scripts->registered['opace-eseot-audit-runner'] ) ) {
			return null;
		}
		$before = $scripts->get_inline_script_data( 'opace-eseot-audit-runner', 'before' );
		if ( ! preg_match( '/window\.opaceEseotAudit = (\{.*\});/s', $before, $m ) ) {
			return null;
		}
		return json_decode( $m[1], true );
	}

	protected function assert_runner_inactive( $message ) {
		$this->assertFalse( $this->audit()->is_runner_request(), $message . ' (is_runner_request)' );
		$this->assertFalse( wp_script_is( 'opace-eseot-audit-runner', 'enqueued' ), $message . ' (runner enqueued)' );
		$this->assertFalse( wp_script_is( 'opace-eseot-axe', 'enqueued' ), $message . ' (axe enqueued)' );
		$this->assertFalse( wp_script_is( 'opace-eseot-web-vitals', 'enqueued' ), $message . ' (web-vitals enqueued)' );
		$this->assertStringNotContainsString( 'noindex', $this->robots_output(), $message . ' (robots)' );
		$this->assertNotContains( 'opace-eseot-auditing', get_body_class(), $message . ' (body class)' );
	}

	/* ------------------------------------------------------------------
	 * Runner activation gates
	 * ---------------------------------------------------------------- */

	public function test_anonymous_request_does_not_activate_even_with_a_real_nonce() {
		$url = Opace_ESEOT_Audit::build_audit_url( get_permalink( $this->post ), $this->post->ID );
		wp_set_current_user( 0 );

		$this->visit( $url );

		$this->assert_runner_inactive( 'Anonymous visitor' );
	}

	public function test_bad_nonce_does_not_activate() {
		$url = add_query_arg(
			array(
				'opace_eseot_audit' => '1',
				'_wpnonce'          => 'definitely-not-valid',
			),
			get_permalink( $this->post )
		);

		$this->visit( $url );

		$this->assert_runner_inactive( 'Bad nonce' );
	}

	public function test_missing_audit_argument_does_not_activate() {
		$url = add_query_arg( '_wpnonce', wp_create_nonce( 'opace_eseot_audit' ), get_permalink( $this->post ) );

		$this->visit( $url );

		$this->assert_runner_inactive( 'Nonce without opace_eseot_audit=1' );
	}

	public function test_user_without_edit_posts_does_not_activate() {
		wp_set_current_user( self::$subscriber_id );
		$url = Opace_ESEOT_Audit::build_audit_url( get_permalink( $this->post ), $this->post->ID );

		$this->visit( $url );

		$this->assert_runner_inactive( 'Subscriber' );
	}

	public function test_plain_visit_by_admin_does_not_activate() {
		$this->visit( get_permalink( $this->post ) );

		$this->assert_runner_inactive( 'Ordinary page view' );
	}

	public function test_engine_switches_drop_the_matching_library() {
		update_option( 'eseot_audit', array( 'engine_axe' => 0, 'engine_vitals' => 1 ) );
		$url = Opace_ESEOT_Audit::build_audit_url( get_permalink( $this->post ), $this->post->ID );

		$this->visit( $url );

		$this->assertTrue( wp_script_is( 'opace-eseot-audit-runner', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'opace-eseot-web-vitals', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'opace-eseot-axe', 'enqueued' ), 'axe-core is not loaded when its engine is off.' );
		$this->assertSame( array( 'opace-eseot-web-vitals' ), wp_scripts()->registered['opace-eseot-audit-runner']->deps );

		$config = $this->runner_config();
		$this->assertSame( array( 'axe' => false, 'vitals' => true ), $config['engines'] );
	}

	public function test_capable_user_with_valid_nonce_activates_the_runner() {
		$permalink = get_permalink( $this->post );
		$url       = Opace_ESEOT_Audit::build_audit_url( $permalink, $this->post->ID );

		$this->visit( $url );

		$this->assertTrue( $this->audit()->is_runner_request() );

		$scripts = wp_scripts();
		foreach ( array( 'opace-eseot-web-vitals', 'opace-eseot-axe', 'opace-eseot-audit-runner' ) as $handle ) {
			$this->assertTrue( wp_script_is( $handle, 'enqueued' ), "{$handle} should be enqueued." );
			$this->assertSame( OPACE_ESEOT_VERSION, $scripts->registered[ $handle ]->ver, "{$handle} uses OPACE_ESEOT_VERSION." );
		}
		$this->assertStringEndsWith( 'vendor/web-vitals/web-vitals.iife.js', $scripts->registered['opace-eseot-web-vitals']->src );
		$this->assertStringEndsWith( 'vendor/axe-core/axe.min.js', $scripts->registered['opace-eseot-axe']->src );
		$this->assertStringEndsWith( 'assets/js/opace-eseot-audit-runner.js', $scripts->registered['opace-eseot-audit-runner']->src );

		$this->assertEmpty( $scripts->registered['opace-eseot-web-vitals']->extra['group'] ?? null, 'web-vitals loads in the head.' );
		$this->assertSame( 1, $scripts->registered['opace-eseot-axe']->extra['group'] ?? null, 'axe-core loads in the footer.' );
		$this->assertSame( 1, $scripts->registered['opace-eseot-audit-runner']->extra['group'] ?? null, 'The runner loads in the footer.' );
		$this->assertSame( array( 'opace-eseot-web-vitals', 'opace-eseot-axe' ), $scripts->registered['opace-eseot-audit-runner']->deps );

		$config = $this->runner_config();
		$this->assertIsArray( $config, 'window.opaceEseotAudit must be printed before the runner.' );
		$this->assertSame( 'http://example.org', $config['origin'] );
		$this->assertSame( $permalink, $config['targetUrl'], 'targetUrl is the current URL without the audit arguments.' );
		$this->assertStringNotContainsString( 'opace_eseot_audit', $config['targetUrl'] );
		$this->assertStringNotContainsString( '_wpnonce', $config['targetUrl'] );
		$this->assertSame( 15000, $config['timeoutMs'] );
		$this->assertSame( $this->post->ID, $config['expectedPostId'] );
		$this->assertSame( array( 'axe' => true, 'vitals' => true ), $config['engines'] );

		// The page markup must not gain a plugin-added noindex meta (the audit reads the real one);
		// the directive travels as an X-Robots-Tag header instead.
		$this->assertStringNotContainsString( 'noindex', $this->robots_output() );
		$this->assertSame( 'noindex, nofollow', Opace_ESEOT_Audit::robots_header_value() );
		$this->assertSame( 1, has_action( 'template_redirect', array( $this->audit(), 'send_robots_header' ) ) );

		$this->assertContains( 'opace-eseot-auditing', get_body_class() );
		$this->assertFalse( is_admin_bar_showing(), 'The admin bar is hidden on audit runs.' );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
	}

	/* ------------------------------------------------------------------
	 * Targets and URLs
	 * ---------------------------------------------------------------- */

	public function test_audit_target_is_permalink_for_published_and_preview_link_otherwise() {
		$this->assertSame( get_permalink( $this->post ), Opace_ESEOT_Audit::audit_target_for_post( $this->post ) );
		$this->assertSame( get_permalink( $this->post ), Opace_ESEOT_Audit::audit_target_for_post( $this->post->ID ) );

		$draft  = self::factory()->post->create_and_get( array( 'post_status' => 'draft', 'post_title' => 'Draft' ) );
		$target = Opace_ESEOT_Audit::audit_target_for_post( $draft );
		$this->assertSame( get_preview_post_link( $draft ), $target );
		$this->assertStringContainsString( 'preview=true', $target );
		$this->assertStringContainsString( 'p=' . $draft->ID, $target );

		$pending = self::factory()->post->create_and_get( array( 'post_status' => 'pending' ) );
		$this->assertStringContainsString( 'preview=true', Opace_ESEOT_Audit::audit_target_for_post( $pending ) );

		$auto = self::factory()->post->create_and_get( array( 'post_status' => 'auto-draft' ) );
		$this->assertSame( '', Opace_ESEOT_Audit::audit_target_for_post( $auto ), 'An unsaved post has nothing to audit yet.' );
		$this->assertSame( '', Opace_ESEOT_Audit::audit_target_for_post( 0 ) );
	}

	public function test_build_audit_url_carries_a_verifiable_nonce() {
		$url = Opace_ESEOT_Audit::build_audit_url( get_permalink( $this->post ), $this->post->ID );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( '1', $query['opace_eseot_audit'] );
		$this->assertSame( (string) $this->post->ID, $query['opace_eseot_post'] );
		$this->assertNotFalse( wp_verify_nonce( $query['_wpnonce'], 'opace_eseot_audit' ) );

		$no_post = Opace_ESEOT_Audit::build_audit_url( home_url( '/' ) );
		$this->assertStringNotContainsString( 'opace_eseot_post', $no_post );
	}

	/* ------------------------------------------------------------------
	 * Meta box and container
	 * ---------------------------------------------------------------- */

	protected function register_boxes_for( $post_type, $post ) {
		global $wp_meta_boxes;
		$wp_meta_boxes = array();
		set_current_screen( $post_type );
		get_current_screen()->post_type = $post_type;
		do_action( 'add_meta_boxes', $post_type, $post );
	}

	protected function render_box( $post_type, $id, $post ) {
		global $wp_meta_boxes;
		$box = null;
		foreach ( $wp_meta_boxes[ $post_type ] as $contexts ) {
			foreach ( $contexts as $boxes ) {
				if ( isset( $boxes[ $id ] ) && is_array( $boxes[ $id ] ) ) {
					$box = $boxes[ $id ];
				}
			}
		}
		$this->assertNotNull( $box, "Meta box {$id} not registered." );
		ob_start();
		call_user_func( $box['callback'], $post, $box );
		return ob_get_clean();
	}

	/**
	 * Attribute value of the .opace-eseot-audit container, HTML-decoded.
	 */
	protected function attr( $html, $name ) {
		$this->assertMatchesRegularExpression( '/\sdata-' . preg_quote( $name, '/' ) . '="([^"]*)"/', $html, "data-{$name} missing" );
		preg_match( '/\sdata-' . preg_quote( $name, '/' ) . '="([^"]*)"/', $html, $m );
		return html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
	}

	public function test_audit_meta_box_is_registered_normal_high_and_renders_the_container_contract() {
		global $wp_meta_boxes;
		$this->register_boxes_for( 'post', $this->post );

		$this->assertArrayHasKey( 'opace-eseot-audit', $wp_meta_boxes['post']['normal']['high'], 'Audit panel is a normal/high meta box.' );
		$this->assertSame( 'Essential SEO Toolkit page audit', $wp_meta_boxes['post']['normal']['high']['opace-eseot-audit']['title'] );
		$this->assertArrayHasKey( 'opace-eseot-toolkit', $wp_meta_boxes['post']['side']['low'], 'The launcher stays in the sidebar.' );
		$this->assertSame( array( 'side', 'normal' ), array_keys( $wp_meta_boxes['post'] ), 'Sidebar launcher is registered first (phase 1 tests locate the first eseot box).' );

		$html = $this->render_box( 'post', 'opace-eseot-audit', $this->post );

		$this->assertMatchesRegularExpression( '/<div class="opace-eseot-audit"/', $html );
		$this->assertSame( get_permalink( $this->post ), $this->attr( $html, 'target-url' ) );

		$nonce_url = $this->attr( $html, 'nonce-url' );
		$this->assertStringStartsWith( get_permalink( $this->post ), $nonce_url );
		parse_str( (string) wp_parse_url( $nonce_url, PHP_URL_QUERY ), $q );
		$this->assertSame( '1', $q['opace_eseot_audit'] );
		$this->assertNotFalse( wp_verify_nonce( $q['_wpnonce'], 'opace_eseot_audit' ) );
		$this->assertSame( (string) $this->post->ID, $q['opace_eseot_post'] );

		$this->assertSame( rest_url( 'opace-eseot/v1/crawl' ), $this->attr( $html, 'rest-url' ) );
		$this->assertSame( rest_url( 'opace-eseot/v1/summary' ), $this->attr( $html, 'summary-url' ) );
		$this->assertNotFalse( wp_verify_nonce( $this->attr( $html, 'rest-nonce' ), 'wp_rest' ) );
		$this->assertSame( '0', $this->attr( $html, 'autorun' ) );
		$this->assertSame( (string) $this->post->ID, $this->attr( $html, 'post-id' ) );
		$this->assertSame( 'audit', $this->attr( $html, 'view' ) );
		$this->assertSame( 'single', $this->attr( $html, 'mode' ) );
		$this->assertSame( array( 'axe' => true, 'vitals' => true ), json_decode( $this->attr( $html, 'engines' ), true ) );
		$this->assertSame( 'null', $this->attr( $html, 'last-summary' ) );
		$this->assertStringNotContainsString( 'data-target-urls', $html, 'Single mode has no bulk list.' );
		$this->assertStringContainsString( '<noscript>', $html );

		$this->assertMatchesRegularExpression( '#<script type="application/json" class="opace-eseot-audit__tools">(.*?)</script>#s', $html );
		preg_match( '#<script type="application/json" class="opace-eseot-audit__tools">(.*?)</script>#s', $html, $m );
		$tools = json_decode( $m[1], true );
		$this->assertCount( 6, $tools['sources'] );
		$this->assertSame( Opace_ESEOT_Defaults::categories(), array_combine( array_map( 'intval', array_keys( $tools['categories'] ) ), array_values( $tools['categories'] ) ) );
		$this->assertSame( Opace_ESEOT_Link_Resolver::placeholders(), $tools['placeholders'] );
		$this->assertStringNotContainsString( '</script', $m[1], 'JSON is safe inside a script element.' );

		$side = $this->render_box( 'post', 'opace-eseot-toolkit', $this->post );
		$this->assertMatchesRegularExpression( '/<a class="opace-eseot-metabox__audit-link" href="#opace-eseot-audit">Run a page audit<\/a>/', $side );
	}

	public function test_container_for_a_draft_uses_the_preview_link_and_autorun_follows_the_setting() {
		update_option( 'eseot_audit', array( 'autorun' => 1 ) );
		$draft = self::factory()->post->create_and_get( array( 'post_status' => 'draft', 'post_title' => 'Draft' ) );
		$this->register_boxes_for( 'post', $draft );

		$html = $this->render_box( 'post', 'opace-eseot-audit', $draft );

		$this->assertSame( get_preview_post_link( $draft ), $this->attr( $html, 'target-url' ) );
		$this->assertStringContainsString( 'preview=true', $this->attr( $html, 'nonce-url' ) );
		$this->assertSame( '1', $this->attr( $html, 'autorun' ) );
	}

	public function test_container_for_an_unsaved_post_has_no_target_and_says_so() {
		$auto = self::factory()->post->create_and_get( array( 'post_status' => 'auto-draft' ) );
		$this->register_boxes_for( 'post', $auto );

		$html = $this->render_box( 'post', 'opace-eseot-audit', $auto );

		$this->assertSame( '', $this->attr( $html, 'target-url' ) );
		$this->assertSame( '', $this->attr( $html, 'nonce-url' ) );
		$this->assertStringContainsString( 'Save the post to get an address to audit.', $html );
	}

	public function test_last_summary_is_exposed_when_stored() {
		$summary = array(
			'counts'     => array( 'pass' => 9, 'review' => 2, 'info' => 3 ),
			'top_review' => array( 'Missing meta description' ),
			'audited_at' => '2026-09-03T09:00:00+00:00',
			'url'        => get_permalink( $this->post ),
		);
		update_post_meta( $this->post->ID, '_opace_eseot_audit_summary', $summary );
		$this->register_boxes_for( 'post', $this->post );

		$html = $this->render_box( 'post', 'opace-eseot-audit', $this->post );

		$this->assertSame( $summary, json_decode( $this->attr( $html, 'last-summary' ), true ) );
		$this->assertSame( $summary, Opace_ESEOT_Audit::last_summary_for( $this->post->ID ) );
	}

	public function test_no_audit_meta_box_for_a_disabled_post_type() {
		global $wp_meta_boxes;
		$page = self::factory()->post->create_and_get( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$this->register_boxes_for( 'page', $page );

		$this->assertTrue( empty( $wp_meta_boxes['page']['normal']['high']['opace-eseot-audit'] ) );
	}

	/* ------------------------------------------------------------------
	 * Admin assets
	 * ---------------------------------------------------------------- */

	public function test_panel_assets_enqueued_on_post_screen_with_strings_and_config() {
		set_current_screen( 'post' );
		get_current_screen()->post_type = 'post';
		$GLOBALS['post']                = $this->post;

		do_action( 'admin_enqueue_scripts', 'post.php' );

		$this->assertTrue( wp_script_is( 'opace-eseot-audit', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'opace-eseot-audit', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'opace-eseot-audit-resolver', 'registered' ) );
		$this->assertTrue( wp_script_is( 'opace-eseot-metabox', 'enqueued' ), 'Phase 1 launcher assets still load.' );

		$scripts = wp_scripts();
		$panel   = $scripts->registered['opace-eseot-audit'];
		$this->assertStringEndsWith( 'assets/js/opace-eseot-audit-panel.js', $panel->src );
		$this->assertSame( array( 'opace-eseot-audit-resolver' ), $panel->deps );
		$this->assertSame( OPACE_ESEOT_VERSION, $panel->ver );
		$this->assertSame( 1, $panel->extra['group'] ?? null );
		$this->assertStringEndsWith( 'assets/js/opace-eseot-audit-resolver.js', $scripts->registered['opace-eseot-audit-resolver']->src );
		$this->assertStringEndsWith( 'assets/css/opace-eseot-audit.css', wp_styles()->registered['opace-eseot-audit']->src );

		$l10n = $scripts->get_data( 'opace-eseot-audit', 'data' );
		$this->assertStringContainsString( 'var opaceEseotAuditL10n', $l10n );
		preg_match( '/var opaceEseotAuditL10n = (\{.*\});/s', $l10n, $m );
		$strings = json_decode( $m[1], true );
		$this->assertSame( 'Run audit', $strings['runAudit'] );
		$this->assertSame( 'Auditing…', $strings['running'] );
		$this->assertSame( 'Run again', $strings['rerun'] );
		$this->assertSame( 'Copy summary', $strings['copySummary'] );
		$this->assertSame( 'Copied', $strings['copied'] );
		$this->assertSame( array( 'overview' => 'Overview', 'details' => 'All details', 'engines' => 'Local engines', 'crawl' => 'Crawl', 'tools' => 'Saved tools' ), $strings['tabs'] );
		$this->assertSame( array( 'pass' => 'Pass', 'review' => 'Review', 'info' => 'Info' ), $strings['statuses'] );
		$this->assertSame( 'Open the page in a new tab to run the audit', $strings['openInTab'] );
		$this->assertSame( 'The page did not report back. It may block framing or have a script error.', $strings['timeout'] );
		$this->assertSame( 'Save the post to get an address to audit.', $strings['noPermalink'] );
		$this->assertSame( 'Crawl check unavailable.', $strings['crawlError'] );
		$this->assertSame( 'Verify with %s', $strings['verifyWith'] );
		$this->assertSame( 'No saved tools match this finding.', $strings['noTools'] );
		$this->assertSame( 'Audited %s', $strings['auditedAt'] );

		$before = $scripts->get_inline_script_data( 'opace-eseot-audit', 'before' );
		preg_match( '/window\.opaceEseotAuditConfig = (\{.*\});/s', $before, $m );
		$config = json_decode( $m[1], true );
		$this->assertSame( rest_url( 'opace-eseot/v1/crawl' ), $config['restUrl'] );
		$this->assertSame( rest_url( 'opace-eseot/v1/summary' ), $config['summaryUrl'] );
		$this->assertNotFalse( wp_verify_nonce( $config['restNonce'], 'wp_rest' ) );
		$this->assertSame( 'http://example.org', $config['adminOrigin'] );
		$this->assertSame( home_url( '/' ), $config['homeUrl'] );
		$this->assertSame( plugin_dir_url( OPACE_ESEOT_FILE ), $config['pluginUrl'] );
		$this->assertSame( admin_url( 'admin.php?page=opace-eseot-settings&tab=audit' ), $config['settingsUrl'] );
		$this->assertSame( admin_url( 'admin.php?page=opace-eseot' ), $config['toolsPageUrl'] );
		$this->assertSame( $this->post->ID, $config['postId'] );
		$this->assertNull( $config['lastSummary'] );
		$this->assertSame( 20000, $config['timeoutMs'] );
		$this->assertSame( 15000, $config['runnerTimeoutMs'] );
		$this->assertFalse( $config['autorun'] );
		$this->assertSame( array( 'axe' => true, 'vitals' => true ), $config['engines'] );
		$this->assertCount( 6, $config['sources'] );
		$this->assertSame( Opace_ESEOT_Link_Resolver::placeholders(), $config['placeholders'] );
		$this->assertSame( OPACE_ESEOT_VERSION, $config['version'] );
	}

	public function test_panel_assets_enqueued_on_tools_page_only_and_not_elsewhere() {
		set_current_screen( 'toplevel_page_opace-eseot' );
		do_action( 'admin_enqueue_scripts', Opace_ESEOT_Plugin::instance()->audit_page()->page_hook() );
		$this->assertTrue( wp_script_is( 'opace-eseot-audit', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'opace-eseot-metabox', 'enqueued' ), 'The launcher script is not needed on the Tools page.' );

		$GLOBALS['wp_scripts'] = null;
		set_current_screen( 'dashboard' );
		do_action( 'admin_enqueue_scripts', 'index.php' );
		$this->assertFalse( wp_script_is( 'opace-eseot-audit', 'enqueued' ) );
	}

	/* ------------------------------------------------------------------
	 * Tools page
	 * ---------------------------------------------------------------- */

	public function test_tools_page_url_encodes_arguments() {
		$url = Opace_ESEOT_Audit_Page::page_url(
			array(
				'url'     => 'http://example.org/?p=1&x=2',
				'autorun' => 1,
				'view'    => 'tools',
				'ids'     => '1,2,3',
			)
		);

		$this->assertStringStartsWith( admin_url( 'admin.php' ), $url );
		$this->assertStringContainsString( 'page=opace-eseot&', $url );
		$this->assertStringContainsString( 'url=http%3A%2F%2Fexample.org%2F%3Fp%3D1%26x%3D2', $url );
		$this->assertStringContainsString( 'autorun=1', $url );
		$this->assertStringContainsString( 'view=tools', $url );
		$this->assertStringContainsString( 'ids=1%2C2%2C3', $url );
		$this->assertSame( admin_url( 'admin.php?page=opace-eseot' ), Opace_ESEOT_Audit_Page::page_url() );
	}

	public function test_tools_page_normalises_paths_and_rejects_other_hosts() {
		$this->assertSame( home_url( '/services/' ), Opace_ESEOT_Audit_Page::normalise_url( '/services/' ) );
		$this->assertSame( home_url( '/?s=x' ), Opace_ESEOT_Audit_Page::normalise_url( home_url( '/?s=x' ) ) );
		$this->assertSame( '', Opace_ESEOT_Audit_Page::normalise_url( 'https://example.net/' ) );
		$this->assertSame( '', Opace_ESEOT_Audit_Page::normalise_url( '//example.net/' ) );
		$this->assertSame( '', Opace_ESEOT_Audit_Page::normalise_url( 'javascript:alert(1)' ) );
		$this->assertSame( '', Opace_ESEOT_Audit_Page::normalise_url( '' ) );
	}

	protected function render_tools_page( array $get ) {
		$_GET = $get;
		ob_start();
		Opace_ESEOT_Plugin::instance()->audit_page()->render_page();
		return ob_get_clean();
	}

	public function test_tools_page_defaults_to_the_home_page_without_autorun() {
		$html = $this->render_tools_page( array( 'page' => 'opace-eseot' ) );

		$this->assertSame( home_url( '/' ), $this->attr( $html, 'target-url' ) );
		$this->assertSame( '0', $this->attr( $html, 'autorun' ) );
		$this->assertSame( 'single', $this->attr( $html, 'mode' ) );
		$this->assertStringContainsString( 'name="url"', $html );
		$this->assertStringNotContainsString( 'settings-error', $html );
	}

	public function test_tools_page_audits_a_submitted_same_site_url_with_autorun_and_view() {
		$html = $this->render_tools_page(
			array(
				'page'    => 'opace-eseot-audit',
				'url'     => get_permalink( $this->post ),
				'autorun' => '1',
				'view'    => 'tools',
			)
		);

		$this->assertSame( get_permalink( $this->post ), $this->attr( $html, 'target-url' ) );
		$this->assertSame( '1', $this->attr( $html, 'autorun' ) );
		$this->assertSame( 'tools', $this->attr( $html, 'view' ) );
		$this->assertSame( (string) $this->post->ID, $this->attr( $html, 'post-id' ), 'A permalink resolves back to its post id.' );
	}

	public function test_tools_page_rejects_an_offsite_url_with_a_settings_error_and_no_panel() {
		$html = $this->render_tools_page(
			array(
				'page' => 'opace-eseot',
				'url'  => 'https://example.net/',
			)
		);

		$this->assertStringContainsString( 'settings-error', $html );
		$this->assertStringContainsString( 'Enter an address on this site', $html );
		$this->assertStringNotContainsString( 'class="opace-eseot-audit"', $html );
	}

	public function test_tools_page_bulk_mode_lists_editable_posts_and_notes_truncation() {
		$draft  = self::factory()->post->create_and_get( array( 'post_status' => 'draft', 'post_title' => 'Bulk draft' ) );
		$other  = self::factory()->post->create_and_get( array( 'post_status' => 'publish', 'post_author' => self::$subscriber_id, 'post_title' => 'By someone else' ) );
		$missing = 999999;

		$html = $this->render_tools_page(
			array(
				'page'      => 'opace-eseot-audit',
				'ids'       => implode( ',', array( $this->post->ID, $draft->ID, $other->ID, $missing, $this->post->ID ) ),
				'truncated' => '1',
			)
		);

		$this->assertSame( 'bulk', $this->attr( $html, 'mode' ) );
		$this->assertSame( '1', $this->attr( $html, 'autorun' ) );
		$this->assertStringContainsString( 'Only the first 25 selected posts were kept', $html );

		$targets = json_decode( $this->attr( $html, 'target-urls' ), true );
		$this->assertSame( array( $this->post->ID, $draft->ID, $other->ID ), wp_list_pluck( $targets, 'post_id' ), 'Admins can edit all three; the missing id and the duplicate are dropped.' );
		$this->assertSame( get_permalink( $this->post ), $targets[0]['url'] );
		$this->assertSame( 'Audit test post', $targets[0]['title'] );
		$this->assertStringContainsString( 'preview=true', $targets[1]['url'] );
		foreach ( $targets as $target ) {
			parse_str( (string) wp_parse_url( $target['nonce_url'], PHP_URL_QUERY ), $q );
			$this->assertNotFalse( wp_verify_nonce( $q['_wpnonce'], 'opace_eseot_audit' ) );
			$this->assertSame( (string) $target['post_id'], $q['opace_eseot_post'] );
		}
		$this->assertSame( get_permalink( $this->post ), $this->attr( $html, 'target-url' ), 'Single-target attributes point at the first item.' );
	}

	public function test_tools_page_bulk_mode_skips_posts_the_user_cannot_edit() {
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$mine        = self::factory()->post->create_and_get( array( 'post_status' => 'draft', 'post_author' => $contributor, 'post_title' => 'Mine' ) );
		wp_set_current_user( $contributor );

		$html = $this->render_tools_page(
			array(
				'page' => 'opace-eseot',
				'ids'  => $this->post->ID . ',' . $mine->ID,
			)
		);

		$targets = json_decode( $this->attr( $html, 'target-urls' ), true );
		$this->assertSame( array( $mine->ID ), wp_list_pluck( $targets, 'post_id' ), 'Only posts the user can edit_post are audited.' );
	}

	public function test_tools_page_is_open_to_edit_posts_and_closed_to_subscribers() {
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		wp_set_current_user( $contributor );
		$html = $this->render_tools_page( array( 'page' => 'opace-eseot' ) );
		$this->assertStringContainsString( 'class="opace-eseot-audit"', $html, 'A contributor (edit_posts) can use the Tools page.' );

		wp_set_current_user( self::$subscriber_id );
		$level = ob_get_level();
		try {
			$this->render_tools_page( array( 'page' => 'opace-eseot' ) );
			$this->fail( 'A subscriber must be refused with wp_die().' );
		} catch ( WPDieException $e ) {
			$this->assertStringContainsString( 'not allowed', $e->getMessage() );
		} finally {
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}
	}

	/* ------------------------------------------------------------------
	 * Settings
	 * ---------------------------------------------------------------- */

	public function test_sanitize_audit_and_defaults() {
		$settings = new Opace_ESEOT_Settings();

		$this->assertSame(
			array( 'autorun' => 1, 'engine_axe' => 0, 'engine_vitals' => 1, 'column_post_types' => array( 'post' ) ),
			$settings->sanitize_audit(
				array(
					'autorun'               => '1',
					'engine_vitals'         => 'on',
					'column_post_types'     => array( 'post', 'bogus', 'post' ),
					'column_post_types_set' => '1',
				)
			),
			'Absent checkboxes are 0; unknown post types are dropped; duplicates collapse.'
		);

		$this->assertSame(
			array( 'autorun' => 0, 'engine_axe' => 0, 'engine_vitals' => 0, 'column_post_types' => array() ),
			$settings->sanitize_audit( array( 'column_post_types_set' => '1' ) ),
			'Submitting the form with nothing ticked stores an empty column list.'
		);

		$this->assertSame(
			array( 'autorun' => 0, 'engine_axe' => 1, 'engine_vitals' => 1 ),
			$settings->sanitize_audit( array( 'engine_axe' => 1, 'engine_vitals' => 1 ) ),
			'Without the column field the stored list (none yet) is left alone.'
		);

		$this->assertSame( array( 'page' ), $settings->sanitize_audit( array( 'column_post_types' => array( 'page' => 1, 'post' => 0 ) ) )['column_post_types'], 'Map form is accepted.' );
		$this->assertSame( array( 'autorun' => 0, 'engine_axe' => 0, 'engine_vitals' => 0 ), $settings->sanitize_audit( 'garbage' ) );

		delete_option( 'eseot_audit' );
		$defaults = $settings->get_audit_settings();
		$this->assertSame( 0, $defaults['autorun'] );
		$this->assertSame( 1, $defaults['engine_axe'] );
		$this->assertSame( 1, $defaults['engine_vitals'] );
		$this->assertSame( array( 'post' ), $defaults['column_post_types'], 'Column defaults to the post types the meta box is enabled for.' );

		update_option( 'eseot_audit', array( 'autorun' => 1, 'column_post_types' => array( 'page' ) ) );
		$stored = $settings->get_audit_settings();
		$this->assertSame( 1, $stored['autorun'] );
		$this->assertSame( 1, $stored['engine_axe'], 'Engines default on when the key is missing.' );
		$this->assertSame( array( 'page' ), $stored['column_post_types'] );

		update_option( 'eseot_audit', array( 'column_post_types' => array() ) );
		$this->assertSame( array(), $settings->get_audit_settings()['column_post_types'], 'An explicit empty list stays empty.' );
	}

	public function test_audit_option_is_registered_with_the_settings_api() {
		Opace_ESEOT_Plugin::instance()->settings()->register_settings();
		global $wp_registered_settings;
		$this->assertArrayHasKey( 'eseot_audit', $wp_registered_settings );
		$this->assertSame( 'opace_eseot_audit', $wp_registered_settings['eseot_audit']['group'] );
	}

	/* ------------------------------------------------------------------
	 * Uninstall
	 * ---------------------------------------------------------------- */

	public function test_uninstall_removes_audit_option_summaries_and_crawl_transients() {
		update_option( 'eseot_audit', array( 'autorun' => 1 ) );
		update_option( 'eseot_audit_summaries_urls', array( 'x' ) );
		set_transient( 'opace_eseot_crawl_' . md5( 'x' ), array( 'status' => 200 ), 60 );
		set_transient( 'opace_eseot_crawl_site_' . md5( 'y' ), array( 'robots' => array() ), 60 );
		update_post_meta( $this->post->ID, '_opace_eseot_audit_summary', array( 'counts' => array() ) );
		update_post_meta( $this->post->ID, '_opace_eseot_audit_time', '2026-09-03' );
		$this->assertGreaterThanOrEqual( 2, $this->eseot_count_crawl_transients() );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'opace-essential-seo-toolkit/opace-essential-seo-toolkit.php' );
		}
		include dirname( OPACE_ESEOT_FILE ) . '/uninstall.php';
		wp_cache_flush();

		$this->assertFalse( get_option( 'eseot_audit' ) );
		$this->assertFalse( get_option( 'eseot_audit_summaries_urls' ) );
		$this->assertFalse( get_option( 'eseot_sources' ) );
		$this->assertFalse( get_transient( 'opace_eseot_crawl_' . md5( 'x' ) ) );
		$this->assertSame( 0, $this->eseot_count_crawl_transients(), 'Both the value and timeout rows are gone.' );
		$this->assertSame( '', get_post_meta( $this->post->ID, '_opace_eseot_audit_summary', true ) );
		$this->assertSame( '', get_post_meta( $this->post->ID, '_opace_eseot_audit_time', true ) );
	}
}
