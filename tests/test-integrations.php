<?php
/**
 * Tests for Opace_ESEOT_Integrations: row actions, bulk action, column and
 * sorting, admin bar, dashboard widget, admin styles.
 *
 * Contract (PLAN-PHASE2-AUDIT.md addendum items 1 to 4 and the WS-9b brief).
 *
 * @package Opace_ESEOT_Tests
 */

if ( defined( 'OPACE_ESEOT_FILE' ) ) {
	if ( ! class_exists( 'Opace_ESEOT_Audit_Store' ) ) {
		require_once dirname( OPACE_ESEOT_FILE ) . '/includes/class-opace-eseot-audit-store.php';
	}
	if ( ! class_exists( 'Opace_ESEOT_Integrations' ) ) {
		require_once dirname( OPACE_ESEOT_FILE ) . '/includes/class-opace-eseot-integrations.php';
	}
}

class IntegrationsTest extends WP_UnitTestCase {
	use Opace_ESEOT_Test_Helpers;

	protected $admin;
	protected $subscriber;
	protected $post;
	protected $draft;
	protected $server_backup = array();

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'Opace_ESEOT_Integrations' ) || ! class_exists( 'Opace_ESEOT_Audit_Store' ) ) {
			$this->fail( 'Opace_ESEOT_Integrations / Opace_ESEOT_Audit_Store are not loaded.' );
		}
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/post.php';
		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';

		$this->eseot_delete_options();
		Opace_ESEOT_Plugin::activate();
		update_option( 'eseot_post_types', array( 'post' => 1 ) );
		wp_cache_flush();

		Opace_ESEOT_Audit_Store::init();
		Opace_ESEOT_Audit_Store::register_meta();
		Opace_ESEOT_Integrations::init();
		Opace_ESEOT_Integrations::register_list_table_hooks();

		$this->server_backup = array(
			'HTTP_HOST'   => isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : null,
			'REQUEST_URI' => isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null,
		);

		$this->admin      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->admin );

		$this->post  = self::factory()->post->create_and_get(
			array(
				'post_title'  => 'Integrations published post',
				'post_status' => 'publish',
				'post_author' => $this->admin,
			)
		);
		$this->draft = self::factory()->post->create_and_get(
			array(
				'post_title'  => 'Integrations draft post',
				'post_status' => 'draft',
				'post_author' => $this->admin,
			)
		);
	}

	public function tear_down() {
		global $wp_meta_boxes, $wp_admin_bar;
		$wp_meta_boxes = array();
		$wp_admin_bar  = null;
		foreach ( $this->server_backup as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}
		set_current_screen( 'front' );
		wp_dequeue_style( Opace_ESEOT_Integrations::STYLE_HANDLE );
		delete_option( Opace_ESEOT_Audit_Store::OPTION_URLS );
		$this->eseot_delete_options();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	protected function tools_page_url() {
		return admin_url( 'admin.php' );
	}

	protected function assert_tools_link( $href, $message = '' ) {
		$href = html_entity_decode( $href );
		$this->assertStringStartsWith( $this->tools_page_url() . '?', $href, $message );
		parse_str( (string) wp_parse_url( $href, PHP_URL_QUERY ), $query );
		$this->assertSame( 'opace-eseot', isset( $query['page'] ) ? $query['page'] : '', $message );
	}

	/* ---------------------------------------------------------------- row actions */

	public function test_row_actions_present_for_capable_user() {
		$actions = apply_filters( 'post_row_actions', array( 'edit' => '<a href="#">Edit</a>' ), $this->post );

		$this->assertArrayHasKey( 'edit', $actions );
		$this->assertArrayHasKey( 'opace_eseot_audit', $actions );
		$this->assertArrayHasKey( 'opace_eseot_tools', $actions );

		preg_match( '/href="([^"]+)"/', $actions['opace_eseot_audit'], $m );
		$this->assert_tools_link( $m[1] );
		$this->assertStringContainsString( 'url=' . rawurlencode( get_permalink( $this->post ) ), $m[1] );
		$this->assertStringContainsString( 'autorun=1', $m[1] );
		$this->assertStringContainsString( '>Audit page<', $actions['opace_eseot_audit'] );

		preg_match( '/href="([^"]+)"/', $actions['opace_eseot_tools'], $m );
		$this->assert_tools_link( $m[1] );
		$this->assertStringContainsString( 'url=' . rawurlencode( get_permalink( $this->post ) ), $m[1] );
		$this->assertStringContainsString( 'view=tools', $m[1] );
		$this->assertStringNotContainsString( 'autorun', $m[1] );
		$this->assertStringContainsString( '>SEO tools<', $actions['opace_eseot_tools'] );
	}

	public function test_row_actions_use_preview_link_for_drafts() {
		$actions = apply_filters( 'post_row_actions', array(), $this->draft );
		$this->assertArrayHasKey( 'opace_eseot_audit', $actions );
		preg_match( '/href="([^"]+)"/', $actions['opace_eseot_audit'], $m );
		$this->assertStringContainsString( 'url=' . rawurlencode( get_preview_post_link( $this->draft ) ), $m[1] );
		$this->assertSame( get_preview_post_link( $this->draft ), Opace_ESEOT_Integrations::audit_target( $this->draft ) );
		$this->assertSame( get_permalink( $this->post ), Opace_ESEOT_Integrations::audit_target( $this->post ) );
	}

	public function test_row_actions_absent_for_incapable_user_and_disabled_type() {
		wp_set_current_user( $this->subscriber );
		$actions = apply_filters( 'post_row_actions', array( 'view' => 'x' ), $this->post );
		$this->assertSame( array( 'view' => 'x' ), $actions );

		wp_set_current_user( $this->admin );
		$page    = self::factory()->post->create_and_get( array( 'post_type' => 'page', 'post_status' => 'publish' ) );
		$actions = apply_filters( 'page_row_actions', array( 'view' => 'x' ), $page );
		$this->assertSame( array( 'view' => 'x' ), $actions, 'page is not an enabled post type in this test.' );

		wp_trash_post( $this->post->ID );
		$actions = apply_filters( 'post_row_actions', array(), get_post( $this->post->ID ) );
		$this->assertArrayNotHasKey( 'opace_eseot_audit', $actions, 'No audit action on trashed rows.' );
	}

	/* ---------------------------------------------------------------- bulk action */

	public function test_bulk_action_registered_for_enabled_type_only() {
		$actions = apply_filters( 'bulk_actions-edit-post', array( 'trash' => 'Move to bin' ) );
		$this->assertArrayHasKey( 'opace_eseot_audit', $actions );
		$this->assertSame( 'Run page audit', $actions['opace_eseot_audit'] );

		$this->assertFalse( has_filter( 'bulk_actions-edit-page', array( 'Opace_ESEOT_Integrations', 'add_bulk_action' ) ) );
	}

	public function test_bulk_handler_redirects_to_tools_page_with_ids() {
		$ids      = array( $this->post->ID, $this->draft->ID );
		$redirect = apply_filters( 'handle_bulk_actions-edit-post', admin_url( 'edit.php' ), 'opace_eseot_audit', $ids );

		$this->assert_tools_link( $redirect );
		$this->assertStringContainsString( 'ids=' . $this->post->ID . ',' . $this->draft->ID, $redirect );
		$this->assertStringNotContainsString( 'truncated', $redirect );

		// Other actions pass through untouched.
		$this->assertSame( admin_url( 'edit.php' ), apply_filters( 'handle_bulk_actions-edit-post', admin_url( 'edit.php' ), 'trash', $ids ) );
	}

	public function test_bulk_handler_caps_at_25_and_flags_truncation() {
		$ids = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$ids[] = self::factory()->post->create( array( 'post_author' => $this->admin ) );
		}
		$redirect = apply_filters( 'handle_bulk_actions-edit-post', admin_url( 'edit.php' ), 'opace_eseot_audit', $ids );

		$this->assertStringContainsString( 'truncated=1', $redirect );
		parse_str( (string) wp_parse_url( html_entity_decode( $redirect ), PHP_URL_QUERY ), $query );
		$this->assertCount( 25, explode( ',', $query['ids'] ) );
		$this->assertSame( array_slice( array_map( 'strval', $ids ), 0, 25 ), explode( ',', $query['ids'] ) );
	}

	public function test_bulk_handler_drops_posts_the_user_cannot_edit() {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$mine   = self::factory()->post->create( array( 'post_author' => $author ) );
		wp_set_current_user( $author );

		$redirect = apply_filters( 'handle_bulk_actions-edit-post', admin_url( 'edit.php' ), 'opace_eseot_audit', array( $this->post->ID, $mine, 0, 'abc' ) );
		$this->assertStringContainsString( 'ids=' . $mine, $redirect );
		$this->assertStringNotContainsString( (string) $this->post->ID, $redirect );

		// Nothing editable: the original redirect comes back.
		wp_set_current_user( $this->subscriber );
		$this->assertSame( admin_url( 'edit.php' ), apply_filters( 'handle_bulk_actions-edit-post', admin_url( 'edit.php' ), 'opace_eseot_audit', array( $this->post->ID ) ) );
	}

	/* ---------------------------------------------------------------- column */

	public function test_column_added_after_title_and_sortable() {
		$columns = apply_filters(
			'manage_post_posts_columns',
			array(
				'cb'    => '<input type="checkbox">',
				'title' => 'Title',
				'date'  => 'Date',
			)
		);
		$this->assertSame( array( 'cb', 'title', 'opace_eseot_audit', 'date' ), array_keys( $columns ) );
		$this->assertSame( 'SEO audit', $columns['opace_eseot_audit'] );

		$sortable = apply_filters( 'manage_edit-post_sortable_columns', array( 'title' => 'title' ) );
		$this->assertArrayHasKey( 'opace_eseot_audit', $sortable );
		$this->assertSame( 'opace_eseot_audit', $sortable['opace_eseot_audit'][0] );

		$this->assertFalse( has_filter( 'manage_page_posts_columns', array( 'Opace_ESEOT_Integrations', 'add_column' ) ) );
	}

	public function test_column_renders_not_audited_then_counts() {
		ob_start();
		do_action( 'manage_post_posts_custom_column', 'opace_eseot_audit', $this->post->ID );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Not audited', $html );
		$this->assertStringContainsString( 'Audit now', $html );
		$this->assertStringContainsString( 'page=opace-eseot&', $html );
		$this->assertStringContainsString( 'url=' . rawurlencode( get_permalink( $this->post ) ), $html );

		Opace_ESEOT_Audit_Store::store( $this->post->ID, get_permalink( $this->post ), array( 'pass' => 9, 'review' => 2, 'info' => 3 ) );

		ob_start();
		do_action( 'manage_post_posts_custom_column', 'opace_eseot_audit', $this->post->ID );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'opace-eseot-count--pass">9 pass<', $html );

		$this->assertStringContainsString( 'opace-eseot-count--review">2 review<', $html );

		$this->assertStringContainsString( 'opace-eseot-count--info">3 info<', $html );
		$this->assertStringContainsString( '<br>', $html );
		$this->assertMatchesRegularExpression( '/\d+ (second|min|mins|hour|hours)s? ago|1 min ago/', $html );
		$this->assertStringNotContainsString( 'Not audited', $html );

		// Other columns are ignored.
		ob_start();
		do_action( 'manage_post_posts_custom_column', 'date', $this->post->ID );
		$this->assertSame( '', ob_get_clean() );

		// Subscribers see the state but no "Audit now" link.
		wp_set_current_user( $this->subscriber );
		ob_start();
		do_action( 'manage_post_posts_custom_column', 'opace_eseot_audit', $this->draft->ID );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Not audited', $html );
		$this->assertStringNotContainsString( 'Audit now', $html );
	}

	public function test_sorting_by_audit_time_keeps_unaudited_posts() {
		$now   = time();
		$never = self::factory()->post->create( array( 'post_title' => 'never audited' ) );
		$old   = self::factory()->post->create( array( 'post_title' => 'old audit' ) );
		$new   = self::factory()->post->create( array( 'post_title' => 'new audit' ) );
		foreach ( array( $old => 500, $new => 50 ) as $id => $age ) {
			Opace_ESEOT_Audit_Store::store( $id, get_permalink( $id ), array( 'pass' => 1, 'review' => 0, 'info' => 0 ) );
			update_post_meta( $id, Opace_ESEOT_Audit_Store::TIME_META_KEY, $now - $age );
		}
		$want = array( $new, $old, $never, $this->post->ID );

		set_current_screen( 'edit-post' );
		$this->assertTrue( is_admin() );

		$backup = $GLOBALS['wp_the_query'];
		$query  = new WP_Query();
		$GLOBALS['wp_the_query'] = $query;
		$desc = $query->query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'post__in'       => $want,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'opace_eseot_audit',
				'order'          => 'DESC',
			)
		);
		$GLOBALS['wp_the_query'] = $backup;

		$this->assertCount( 4, $desc, 'Posts without an audit must stay in the list.' );
		$this->assertSame( $new, $desc[0] );
		$this->assertSame( $old, $desc[1] );
		$this->assertEqualSets( array( $never, $this->post->ID ), array_slice( $desc, 2 ), 'Never-audited posts sort last when descending.' );

		// On a disabled type's screen the query is left alone (orderby falls back to date).
		set_current_screen( 'edit-page' );
		$query  = new WP_Query();
		$GLOBALS['wp_the_query'] = $query;
		$query->query(
			array(
				'post_type' => 'post',
				'fields'    => 'ids',
				'orderby'   => 'opace_eseot_audit',
			)
		);
		$GLOBALS['wp_the_query'] = $backup;
		$this->assertSame( 'opace_eseot_audit', $query->get( 'orderby' ) );
		$this->assertEmpty( $query->get( 'meta_query' ) );
	}

	/* ---------------------------------------------------------------- admin bar */

	protected function build_admin_bar() {
		global $wp_admin_bar;
		$wp_admin_bar = new WP_Admin_Bar();
		$wp_admin_bar->initialize();
		do_action_ref_array( 'admin_bar_menu', array( &$wp_admin_bar ) );

		return $wp_admin_bar;
	}

	public function test_admin_bar_nodes_on_front_end_for_capable_user() {
		set_current_screen( 'front' );
		$_SERVER['HTTP_HOST']   = wp_parse_url( home_url(), PHP_URL_HOST );
		$_SERVER['REQUEST_URI'] = '/hello-world/?utm=1';
		$expected_url           = home_url( '/hello-world/?utm=1' );

		$bar    = $this->build_admin_bar();
		$parent = $bar->get_node( 'opace-eseot' );
		$this->assertNotNull( $parent, 'Parent node missing.' );
		$this->assertStringContainsString( 'opace-eseot-mark.svg', $parent->title );
		$this->assertStringContainsString( 'aria-hidden="true"', $parent->title );
		$this->assertStringContainsString( 'width="16"', $parent->title );
		$this->assertStringContainsString( 'SEO Toolkit', $parent->title );
		$this->assert_tools_link( $parent->href );

		$audit = $bar->get_node( 'opace-eseot-audit' );
		$this->assertNotNull( $audit, 'Audit this page node missing.' );
		$this->assertSame( 'opace-eseot', $audit->parent );
		$this->assertSame( 'Audit this page', $audit->title );
		$this->assertStringContainsString( 'url=' . rawurlencode( $expected_url ), $audit->href );
		$this->assertStringContainsString( 'autorun=1', $audit->href );

		$this->assertNotNull( $bar->get_node( 'opace-eseot-tools' ), 'Saved tools group missing.' );

		$tools = array();
		$cats  = array();
		foreach ( $bar->get_nodes() as $node ) {
			if ( 0 === strpos( (string) $node->id, 'opace-eseot-cat-' ) ) {
				if ( 'opace-eseot-tools' === $node->parent ) {
					$cats[] = $node;
				} else {
					$tools[] = $node;
				}
			}
		}
		$this->assertCount( count( Opace_ESEOT_Defaults::categories() ), $cats, 'One category node per default category.' );
		$this->assertCount( count( Opace_ESEOT_Defaults::sources() ), $tools, 'One node per resolvable saved source.' );
		$this->assertLessThanOrEqual( 20, count( $tools ) );

		$hrefs = array_map( static function ( $n ) { return $n->href; }, $tools );
		$this->assertContains( 'https://pagespeed.web.dev/analysis?url=' . rawurlencode( $expected_url ), $hrefs );
		foreach ( $tools as $tool ) {
			$this->assertSame( '_blank', $tool->meta['target'] );
			$this->assertSame( 'noopener noreferrer', $tool->meta['rel'] );
		}
		$names = array_map( static function ( $n ) { return $n->title; }, $cats );
		$this->assertContains( 'Performance &amp; UX', $names );
	}

	public function test_admin_bar_omits_audit_node_for_foreign_host_and_hides_for_subscribers() {
		set_current_screen( 'front' );
		$_SERVER['HTTP_HOST']   = 'evil.example.com';
		$_SERVER['REQUEST_URI'] = '/hello-world/';

		$bar = $this->build_admin_bar();
		$this->assertNotNull( $bar->get_node( 'opace-eseot' ) );
		$this->assertNull( $bar->get_node( 'opace-eseot-audit' ) );
		$this->assertNull( $bar->get_node( 'opace-eseot-tools' ) );

		wp_set_current_user( $this->subscriber );
		$_SERVER['HTTP_HOST'] = wp_parse_url( home_url(), PHP_URL_HOST );
		$bar                  = $this->build_admin_bar();
		$this->assertNull( $bar->get_node( 'opace-eseot' ), 'Nothing renders for users without edit_posts.' );
	}

	public function test_admin_bar_uses_post_target_on_post_edit_screen() {
		set_current_screen( 'post' );
		$GLOBALS['post'] = $this->draft;
		$this->assertTrue( is_admin() );

		$bar   = $this->build_admin_bar();
		$audit = $bar->get_node( 'opace-eseot-audit' );
		$this->assertNotNull( $audit );
		$this->assertStringContainsString( 'url=' . rawurlencode( get_preview_post_link( $this->draft ) ), $audit->href );

		// Elsewhere in admin: parent only.
		set_current_screen( 'plugins' );
		$bar = $this->build_admin_bar();
		$this->assertNotNull( $bar->get_node( 'opace-eseot' ) );
		$this->assertNull( $bar->get_node( 'opace-eseot-audit' ) );
		unset( $GLOBALS['post'] );
	}

	/* ---------------------------------------------------------------- dashboard */

	protected function dashboard_widget() {
		global $wp_meta_boxes;
		$wp_meta_boxes = array();
		set_current_screen( 'dashboard' );
		do_action( 'wp_dashboard_setup' );

		return isset( $wp_meta_boxes['dashboard']['normal']['core']['opace_eseot_dashboard'] )
			? $wp_meta_boxes['dashboard']['normal']['core']['opace_eseot_dashboard']
			: null;
	}

	public function test_dashboard_widget_registered_and_renders() {
		$widget = $this->dashboard_widget();
		$this->assertNotNull( $widget, 'Dashboard widget not registered.' );
		$this->assertSame( 'Essential SEO Toolkit', $widget['title'] );

		ob_start();
		call_user_func( $widget['callback'], null, $widget );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'No pages audited yet. Open a post and run the page audit, or audit the home page.', $html );
		$this->assertStringContainsString( 'Audit home page', $html );
		$this->assertStringContainsString( 'url=' . rawurlencode( home_url( '/' ) ), $html );
		$this->assertStringContainsString( 'autorun=1', $html );
		$this->assertStringContainsString( 'page=opace-eseot&', $html );

		Opace_ESEOT_Audit_Store::store( $this->post->ID, get_permalink( $this->post ), array( 'pass' => 9, 'review' => 2, 'info' => 3 ) );
		Opace_ESEOT_Audit_Store::store( 0, home_url( '/' ), array( 'pass' => 4, 'review' => 1, 'info' => 0 ) );

		ob_start();
		call_user_func( $widget['callback'], null, $widget );
		$html = ob_get_clean();
		$this->assertStringNotContainsString( 'No pages audited yet', $html );
		$this->assertStringContainsString( 'Integrations published post', $html );
		$this->assertStringContainsString( 'opace-eseot-count--pass">9 pass<', $html );
		$this->assertStringContainsString( 'opace-eseot-count--review">2 review<', $html );
		$this->assertStringContainsString( 'opace-eseot-count--info">3 info<', $html );
		$this->assertStringContainsString( 'opace-eseot-count--pass">4 pass<', $html );
		$this->assertStringContainsString( 'opace-eseot-count--review">1 review<', $html );
		$this->assertStringContainsString( 'opace-eseot-count--info">0 info<', $html );
		$this->assertStringContainsString( esc_url( get_edit_post_link( $this->post->ID, 'raw' ) ), $html );
		$this->assertStringContainsString( esc_html( home_url( '/' ) ), $html );
	}

	public function test_dashboard_widget_hidden_from_subscribers() {
		wp_set_current_user( $this->subscriber );
		$this->assertNull( $this->dashboard_widget() );
	}

	/* ---------------------------------------------------------------- styles */

	public function test_admin_styles_enqueued_only_on_enabled_list_tables_and_dashboard() {
		set_current_screen( 'edit-post' );
		do_action( 'admin_enqueue_scripts', 'edit.php' );
		$this->assertTrue( wp_style_is( 'opace-eseot-admin', 'enqueued' ) );
		$style = wp_styles()->registered['opace-eseot-admin'];
		$this->assertSame( OPACE_ESEOT_VERSION, $style->ver );
		$this->assertStringEndsWith( 'assets/css/opace-eseot-admin.css', $style->src );
		$this->assertFileExists( dirname( OPACE_ESEOT_FILE ) . '/assets/css/opace-eseot-admin.css' );
		wp_dequeue_style( 'opace-eseot-admin' );

		set_current_screen( 'edit-page' );
		do_action( 'admin_enqueue_scripts', 'edit.php' );
		$this->assertFalse( wp_style_is( 'opace-eseot-admin', 'enqueued' ), 'Not on a disabled type list table.' );

		set_current_screen( 'post' );
		do_action( 'admin_enqueue_scripts', 'post.php' );
		$this->assertFalse( wp_style_is( 'opace-eseot-admin', 'enqueued' ), 'Not on the editor.' );

		set_current_screen( 'dashboard' );
		do_action( 'admin_enqueue_scripts', 'index.php' );
		$this->assertTrue( wp_style_is( 'opace-eseot-admin', 'enqueued' ), 'On the dashboard.' );
	}

	public function test_init_is_idempotent() {
		Opace_ESEOT_Integrations::init();
		Opace_ESEOT_Integrations::init();
		$this->assertSame( 90, has_action( 'admin_bar_menu', array( 'Opace_ESEOT_Integrations', 'admin_bar_menu' ) ) );
		$this->assertSame( 10, has_filter( 'post_row_actions', array( 'Opace_ESEOT_Integrations', 'row_actions' ) ) );
		$this->assertSame( 'opace-eseot', Opace_ESEOT_Integrations::TOOLS_PAGE_SLUG );
	}
}
