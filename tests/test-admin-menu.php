<?php
/**
 * Tests for the top-level admin menu (WS-13): registration, icon, position,
 * submenus and capabilities, page hooks, legacy URL redirects and the
 * Plugins-list Settings link.
 *
 * @package Opace_ESEOT_Tests
 */

class AdminMenuTest extends WP_UnitTestCase {
	use Opace_ESEOT_Test_Helpers;

	protected $admin;
	protected $contributor;
	protected $get_backup;
	protected $pagenow_backup;

	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';

		$this->eseot_delete_options();
		Opace_ESEOT_Plugin::activate();

		$this->admin       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		wp_set_current_user( $this->admin );

		$this->get_backup     = $_GET;
		$this->pagenow_backup = isset( $GLOBALS['pagenow'] ) ? $GLOBALS['pagenow'] : null;
		$this->reset_menu_globals();
	}

	public function tear_down() {
		$_GET = $this->get_backup;
		if ( null === $this->pagenow_backup ) {
			unset( $GLOBALS['pagenow'] );
		} else {
			$GLOBALS['pagenow'] = $this->pagenow_backup;
		}
		$this->reset_menu_globals();
		delete_option( Opace_ESEOT_Plugin::OPTION_UPGRADE_NOTICE );
		set_current_screen( 'front' );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	protected function reset_menu_globals() {
		$GLOBALS['menu']               = array();
		$GLOBALS['submenu']            = array();
		$GLOBALS['admin_page_hooks']   = array();
		$GLOBALS['_registered_pages']  = array();
		$GLOBALS['_parent_pages']      = array();
		$GLOBALS['_wp_menu_nopriv']    = array();
		$GLOBALS['_wp_submenu_nopriv'] = array();
	}

	protected function settings() {
		return Opace_ESEOT_Plugin::instance()->settings();
	}

	/* ---------------------------------------------------------------- menu */

	public function test_top_level_menu_registered_after_tools_with_icon() {
		global $menu, $submenu;
		do_action( 'admin_menu' );

		$this->assertArrayHasKey( 76, $menu, 'Position 76 sits directly after Tools (75).' );
		$item = $menu[76];
		$this->assertSame( 'Essential SEO Toolkit', $item[0] );
		$this->assertSame( 'edit_posts', $item[1] );
		$this->assertSame( 'opace-eseot', $item[2] );
		$this->assertSame( Opace_ESEOT_Settings::menu_icon(), $item[6] );
		$this->assertStringStartsWith( 'data:image/svg+xml;base64,', $item[6] );

		$this->assertArrayHasKey( 'opace-eseot', $submenu );
		$this->assertCount( 3, $submenu['opace-eseot'] );
		$this->assertSame( array( 'Page audit', 'edit_posts', 'opace-eseot' ), array_slice( $submenu['opace-eseot'][0], 0, 3 ) );
		$this->assertSame( array( 'Settings', 'manage_options', 'opace-eseot-settings' ), array_slice( $submenu['opace-eseot'][1], 0, 3 ) );

		$this->assertArrayNotHasKey( 'options-general.php', $submenu, 'Nothing is registered under Settings any more.' );
		$this->assertArrayNotHasKey( 'tools.php', $submenu, 'Nothing is registered under Tools any more.' );
	}

	public function test_menu_icon_matches_the_svg_file_and_is_monochrome() {
		$file = dirname( OPACE_ESEOT_FILE ) . '/assets/images/opace-eseot-menu-icon.svg';
		$this->assertFileExists( $file );

		$svg = file_get_contents( $file ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- local test fixture.
		$this->assertSame( base64_encode( $svg ), Opace_ESEOT_Settings::MENU_ICON_BASE64, 'The inline constant must be the base64 of the shipped SVG.' );
		$this->assertStringContainsString( 'viewBox="0 0 20 20"', $svg );
		$this->assertStringNotContainsString( 'href', $svg, 'No external references.' );
		$this->assertStringNotContainsString( 'url(', $svg, 'No external references.' );

		preg_match_all( '/fill="([^"]+)"/', $svg, $fills );
		$this->assertNotEmpty( $fills[1] );
		$this->assertSame( array( '#a7aaad' ), array_values( array_unique( $fills[1] ) ), 'A single #a7aaad fill so WordPress can recolour it.' );
	}

	public function test_page_hooks_follow_wordpress_naming() {
		do_action( 'admin_menu' );
		$plugin = Opace_ESEOT_Plugin::instance();

		$this->assertSame( 'toplevel_page_opace-eseot', $plugin->audit_page()->page_hook() );
		$this->assertSame( 'essential-seo-toolkit_page_opace-eseot-settings', $plugin->settings()->page_hook() );

		// The parent and its first submenu share one callback, registered once.
		$this->assertSame( 10, has_action( 'toplevel_page_opace-eseot', array( $plugin->audit_page(), 'render_page' ) ) );
		$this->assertCount( 1, $GLOBALS['wp_filter']['toplevel_page_opace-eseot']->callbacks[10], 'The Page audit screen must render once, not twice.' );
		$this->assertSame( 10, has_action( 'essential-seo-toolkit_page_opace-eseot-settings', array( $plugin->settings(), 'render_page' ) ) );
	}

	public function test_fallback_hooks_before_admin_menu_runs() {
		$settings = new Opace_ESEOT_Settings();
		$this->assertSame( 'essential-seo-toolkit_page_opace-eseot-settings', $settings->page_hook() );

		$audit_page = new Opace_ESEOT_Audit_Page( Opace_ESEOT_Plugin::instance()->audit() );
		$this->assertSame( 'toplevel_page_opace-eseot', $audit_page->page_hook() );
	}

	public function test_contributor_gets_page_audit_but_not_settings() {
		global $menu, $submenu;
		wp_set_current_user( $this->contributor );
		do_action( 'admin_menu' );

		$this->assertSame( 'opace-eseot', $menu[76][2] );
		$this->assertCount( 2, $submenu['opace-eseot'], 'Page audit and Help; Settings is refused.' );
		$this->assertSame( 'Page audit', $submenu['opace-eseot'][0][0] );
		$this->assertTrue( isset( $GLOBALS['_wp_submenu_nopriv']['opace-eseot']['opace-eseot-settings'] ), 'Settings is refused for edit_posts-only users.' );
	}

	public function test_settings_assets_enqueued_on_the_settings_hook_only() {
		set_current_screen( 'essential-seo-toolkit_page_opace-eseot-settings' );
		do_action( 'admin_enqueue_scripts', 'essential-seo-toolkit_page_opace-eseot-settings' );
		$this->assertTrue( wp_script_is( 'opace-eseot-settings', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'opace-eseot-audit', 'enqueued' ) );

		$GLOBALS['wp_scripts'] = null;
		set_current_screen( 'settings_page_essential-seo-toolkit' );
		do_action( 'admin_enqueue_scripts', 'settings_page_essential-seo-toolkit' );
		$this->assertFalse( wp_script_is( 'opace-eseot-settings', 'enqueued' ), 'The old Settings hook no longer loads anything.' );
	}

	public function test_upgrade_notice_hidden_on_settings_screen_and_links_to_new_url_elsewhere() {
		update_option( Opace_ESEOT_Plugin::OPTION_UPGRADE_NOTICE, 1 );
		$plugin = Opace_ESEOT_Plugin::instance();

		set_current_screen( 'essential-seo-toolkit_page_opace-eseot-settings' );
		ob_start();
		$plugin->render_upgrade_notice();
		$this->assertSame( '', trim( ob_get_clean() ), 'No notice on the screen that lets you review the tools.' );

		set_current_screen( 'dashboard' );
		ob_start();
		$plugin->render_upgrade_notice();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Review tools', $html );
		$this->assertStringContainsString( esc_url( admin_url( 'admin.php?page=opace-eseot-settings' ) ), $html );
		$this->assertStringNotContainsString( 'options-general.php', $html );
	}

	/* ---------------------------------------------------------------- URLs */

	public function test_url_builders_point_at_the_new_locations() {
		$this->assertSame( admin_url( 'admin.php?page=opace-eseot-settings' ), $this->settings()->page_url() );
		$this->assertSame( admin_url( 'admin.php?page=opace-eseot-settings&tab=categories' ), $this->settings()->page_url( 'categories' ) );
		$this->assertSame( admin_url( 'admin.php?page=opace-eseot' ), Opace_ESEOT_Audit_Page::page_url() );
		$this->assertSame( admin_url( 'admin.php?page=opace-eseot' ), Opace_ESEOT_Integrations::tools_url() );
		$this->assertSame( admin_url( 'admin.php?page=opace-eseot&ids=1%2C2' ), Opace_ESEOT_Audit_Page::page_url( array( 'ids' => '1,2' ) ) );
	}

	public function test_settings_link_on_plugins_list_points_at_the_new_url() {
		$links = apply_filters( 'plugin_action_links_' . plugin_basename( OPACE_ESEOT_FILE ), array( '<a href="#">Deactivate</a>' ) );

		$this->assertCount( 2, $links );
		$this->assertStringContainsString( '>Settings</a>', $links[1] );
		$this->assertStringContainsString( esc_url( admin_url( 'admin.php?page=opace-eseot-settings' ) ), $links[1] );
	}

	public function test_audit_page_form_posts_back_to_admin_php() {
		$_GET = array( 'page' => 'opace-eseot' );
		ob_start();
		Opace_ESEOT_Plugin::instance()->audit_page()->render_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'action="' . esc_url( admin_url( 'admin.php' ) ) . '"', $html );
		$this->assertStringContainsString( '<input type="hidden" name="page" value="opace-eseot">', $html );
	}

	/* ---------------------------------------------------------------- legacy redirects */

	public function test_legacy_settings_url_redirects_with_tab_preserved() {
		$settings = $this->settings();

		$this->assertSame(
			admin_url( 'admin.php?page=opace-eseot-settings' ),
			$settings->legacy_redirect_target( 'options-general.php', array( 'page' => 'essential-seo-toolkit' ) )
		);
		$this->assertSame(
			admin_url( 'admin.php?page=opace-eseot-settings&tab=audit' ),
			$settings->legacy_redirect_target( 'options-general.php', array( 'page' => 'essential-seo-toolkit', 'tab' => 'audit' ) )
		);
		$this->assertSame(
			admin_url( 'admin.php?page=opace-eseot-settings&tab=sources&settings-updated=true' ),
			$settings->legacy_redirect_target( 'options-general.php', array( 'page' => 'essential-seo-toolkit', 'tab' => 'sources', 'settings-updated' => 'true' ) )
		);
		// The old slug is an alias on admin.php as well.
		$this->assertSame(
			admin_url( 'admin.php?page=opace-eseot-settings' ),
			$settings->legacy_redirect_target( 'admin.php', array( 'page' => 'essential-seo-toolkit' ) )
		);
	}

	public function test_legacy_tools_url_redirects_with_arguments_preserved() {
		$settings = $this->settings();
		$args     = array(
			'url'     => 'http://example.org/?p=1&x=2',
			'autorun' => '1',
			'view'    => 'tools',
		);

		$this->assertSame(
			Opace_ESEOT_Audit_Page::page_url(),
			$settings->legacy_redirect_target( 'tools.php', array( 'page' => 'opace-eseot-audit' ) )
		);
		$this->assertSame(
			Opace_ESEOT_Audit_Page::page_url( $args ),
			$settings->legacy_redirect_target( 'tools.php', array_merge( array( 'page' => 'opace-eseot-audit' ), $args ) )
		);
		$this->assertSame(
			Opace_ESEOT_Audit_Page::page_url( array( 'ids' => '1,2,3', 'truncated' => '1' ) ),
			$settings->legacy_redirect_target( 'admin.php', array( 'page' => 'opace-eseot-audit', 'ids' => '1,2,3', 'truncated' => '1' ) )
		);
	}

	public function test_unrelated_requests_are_not_redirected() {
		$settings = $this->settings();

		$this->assertSame( '', $settings->legacy_redirect_target( 'options-general.php', array() ) );
		$this->assertSame( '', $settings->legacy_redirect_target( 'options-general.php', array( 'page' => 'someone-else' ) ) );
		$this->assertSame( '', $settings->legacy_redirect_target( 'tools.php', array( 'page' => 'essential-seo-toolkit' ) ), 'Settings slug under Tools was never valid.' );
		$this->assertSame( '', $settings->legacy_redirect_target( 'edit.php', array( 'page' => 'opace-eseot-audit' ) ) );
		$this->assertSame( '', $settings->legacy_redirect_target( 'admin.php', array( 'page' => 'opace-eseot' ) ), 'The new slug is not redirected.' );
		$this->assertSame( '', $settings->legacy_redirect_target( 'admin.php', array( 'page' => array( 'x' ) ) ) );
	}

	public function test_redirect_hook_sends_a_301_to_the_new_url() {
		$captured = array();
		add_filter(
			'wp_redirect',
			static function ( $location, $status ) use ( &$captured ) {
				$captured = array( $location, $status );
				return false;
			},
			10,
			2
		);

		$GLOBALS['pagenow'] = 'tools.php';
		$_GET               = array(
			'page' => 'opace-eseot-audit',
			'url'  => 'http://example.org/blog/',
			'view' => 'tools',
		);
		$this->assertSame( 10, has_action( 'admin_page_access_denied', array( $this->settings(), 'redirect_legacy_urls' ) ) );
		do_action( 'admin_page_access_denied' );

		$this->assertNotEmpty( $captured, 'A redirect must be issued.' );
		$this->assertSame( 301, $captured[1] );
		$this->assertSame(
			Opace_ESEOT_Audit_Page::page_url( array( 'url' => 'http://example.org/blog/', 'view' => 'tools' ) ),
			$captured[0]
		);

		$captured           = array();
		$GLOBALS['pagenow'] = 'options-general.php';
		$_GET               = array( 'page' => 'essential-seo-toolkit', 'tab' => 'post-types' );
		do_action( 'admin_page_access_denied' );
		$this->assertSame( array( admin_url( 'admin.php?page=opace-eseot-settings&tab=post-types' ), 301 ), $captured );

		$captured           = array();
		$GLOBALS['pagenow'] = 'options-general.php';
		$_GET               = array( 'page' => 'another-plugin' );
		do_action( 'admin_page_access_denied' );
		$this->assertSame( array(), $captured, 'Other plugins\' refused pages are left alone.' );
	}
}
