<?php
/**
 * Tests for the Help screen (WS-15): submenu registration, capability gate,
 * page rendering, contextual help tabs and internal link integrity.
 *
 * @package Opace_ESEOT_Tests
 */

class HelpPageTest extends WP_UnitTestCase {
	use Opace_ESEOT_Test_Helpers;

	protected $admin;
	protected $contributor;
	protected $subscriber;

	public function set_up() {
		parent::set_up();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';

		$this->eseot_delete_options();
		Opace_ESEOT_Plugin::activate();

		$this->admin       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$this->subscriber  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->admin );

		$this->reset_menu_globals();
	}

	public function tear_down() {
		$this->reset_menu_globals();
		$GLOBALS['wp_styles'] = null;
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

	protected function render_help() {
		ob_start();
		Opace_ESEOT_Help::render_page();
		return ob_get_clean();
	}

	/* ---------------------------------------------------------------- menu */

	public function test_help_submenu_registered_after_settings_with_edit_posts() {
		global $submenu;
		do_action( 'admin_menu' );

		$this->assertArrayHasKey( 'opace-eseot', $submenu );
		$this->assertCount( 3, $submenu['opace-eseot'] );
		$this->assertSame( array( 'Settings', 'manage_options', 'opace-eseot-settings' ), array_slice( $submenu['opace-eseot'][1], 0, 3 ) );
		$this->assertSame( array( 'Help', 'edit_posts', 'opace-eseot-help' ), array_slice( $submenu['opace-eseot'][2], 0, 3 ), 'Help is the third submenu, after Settings.' );
	}

	public function test_help_page_hook_and_render_callback() {
		do_action( 'admin_menu' );

		$this->assertSame( 'essential-seo-toolkit_page_opace-eseot-help', Opace_ESEOT_Help::page_hook() );
		$this->assertSame( 10, has_action( 'essential-seo-toolkit_page_opace-eseot-help', array( 'Opace_ESEOT_Help', 'render_page' ) ) );
	}

	public function test_init_is_idempotent() {
		Opace_ESEOT_Help::init();
		Opace_ESEOT_Help::init();

		$this->assertCount( 1, $GLOBALS['wp_filter']['current_screen']->callbacks[10], 'init() must not double-register the contextual help hook.' );
		$this->assertSame( 10, has_action( 'current_screen', array( 'Opace_ESEOT_Help', 'add_contextual_help' ) ) );
		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( 'Opace_ESEOT_Help', 'enqueue_assets' ) ) );
	}

	public function test_contributor_sees_help_in_the_menu() {
		global $submenu;
		wp_set_current_user( $this->contributor );
		do_action( 'admin_menu' );

		$labels = array_map( static function ( $item ) { return $item[0]; }, $submenu['opace-eseot'] );
		$this->assertSame( array( 'Page audit', 'Help' ), $labels );
	}

	public function test_page_url_builder() {
		$this->assertSame( admin_url( 'admin.php?page=opace-eseot-help' ), Opace_ESEOT_Help::page_url() );
		$this->assertSame( admin_url( 'admin.php?page=opace-eseot-help' ) . '#opace-eseot-help-troubleshooting', Opace_ESEOT_Help::page_url( 'troubleshooting' ) );
	}

	/* ---------------------------------------------------------------- rendering */

	public function test_page_renders_for_edit_posts_users() {
		wp_set_current_user( $this->contributor );
		$html = $this->render_help();

		$this->assertStringContainsString( 'class="wrap opace-eseot-help"', $html );
		$this->assertStringContainsString( 'opace-eseot-mark.svg', $html, 'The mark sits in the header.' );
		$this->assertStringContainsString( 'On this page', $html );

		foreach ( array( 'what', 'where', 'results', 'engines', 'crawl', 'tools', 'settings', 'privacy', 'troubleshooting' ) as $section ) {
			$this->assertStringContainsString( 'id="opace-eseot-help-' . $section . '"', $html, "Section card {$section} is missing." );
			$this->assertStringContainsString( 'href="#opace-eseot-help-' . $section . '"', $html, "Table of contents entry {$section} is missing." );
		}

		$checks = array( 'Page title', 'Meta description', 'H1 heading', 'Canonical URL', 'Indexing directive', 'HTTPS', 'Document language', 'Mobile viewport', 'Image alt attributes', 'Heading order', 'Page content', 'Structured data', 'Social metadata', 'Links' );
		foreach ( $checks as $label ) {
			$this->assertStringContainsString( '<th scope="row">' . $label . '</th>', $html, "Check {$label} is missing from the table." );
		}

		foreach ( array( '[%url%]', '[%url_encoded%]', '[%host%]', '[%host_encoded%]', '[%scheme%]', '[%path%]' ) as $token ) {
			$this->assertStringContainsString( '<code>' . $token . '</code>', $html );
		}

		$this->assertStringContainsString( 'Main content paint (LCP)', $html );
		$this->assertStringContainsString( 'robots.txt rule for this path', $html );
		$this->assertStringContainsString( 'Google Admin Toolbox Dig', $html );
		$this->assertStringContainsString( Opace_ESEOT_Help::SUPPORT_URL, $html );
		$this->assertStringContainsString( Opace_ESEOT_Help::CHROME_URL, $html );
		$this->assertStringNotContainsString( '<!--', $html, 'No HTML comments in shipped markup.' );
	}

	public function test_page_is_refused_for_subscribers() {
		wp_set_current_user( $this->subscriber );

		$this->expectException( 'WPDieException' );
		$this->expectExceptionMessage( 'Sorry, you are not allowed to access this page.' );
		Opace_ESEOT_Help::render_page();
	}

	public function test_every_internal_link_resolves_to_a_url_builder() {
		$html = $this->render_help();
		$settings = Opace_ESEOT_Plugin::instance()->settings();

		$allowed = array(
			Opace_ESEOT_Audit_Page::page_url(),
			Opace_ESEOT_Integrations::tools_url(),
			Opace_ESEOT_Help::page_url(),
			$settings->page_url(),
			$settings->page_url( 'sources' ),
			$settings->page_url( 'categories' ),
			$settings->page_url( 'post-types' ),
			$settings->page_url( 'audit' ),
			admin_url( 'edit.php' ),
			admin_url( 'edit.php?post_type=page' ),
			admin_url( 'index.php' ),
		);
		$allowed = array_map( 'html_entity_decode', array_map( 'esc_url', $allowed ) );

		preg_match_all( '/href="([^"]+)"/', $html, $matches );
		$this->assertNotEmpty( $matches[1] );

		$internal = 0;
		foreach ( $matches[1] as $href ) {
			$href = html_entity_decode( $href );
			if ( 0 === strpos( $href, '#' ) ) {
				continue;
			}
			if ( 0 !== strpos( $href, admin_url() ) ) {
				$this->assertMatchesRegularExpression( '#^https://#', $href, "External link {$href} must be https." );
				continue;
			}
			$internal++;
			$this->assertContains( $href, $allowed, "Internal link {$href} does not come from a known admin URL builder." );
		}

		$this->assertGreaterThanOrEqual( 6, $internal, 'The page links to the Page audit screen, the settings tabs, the list tables and the dashboard.' );
	}

	public function test_external_links_open_in_a_new_tab_with_noopener() {
		$html = $this->render_help();

		preg_match_all( '/<a href="(https:[^"]+)"([^>]*)>/', $html, $matches, PREG_SET_ORDER );
		$external = 0;
		foreach ( $matches as $match ) {
			if ( 0 === strpos( html_entity_decode( $match[1] ), admin_url() ) ) {
				continue;
			}
			$external++;
			$this->assertStringContainsString( 'target="_blank"', $match[2], $match[1] );
			$this->assertStringContainsString( 'rel="noopener noreferrer"', $match[2], $match[1] );
		}
		$this->assertGreaterThanOrEqual( 4, $external );
	}

	/* ---------------------------------------------------------------- assets */

	public function test_stylesheet_enqueued_on_the_help_hook_only() {
		do_action( 'admin_menu' );

		set_current_screen( 'essential-seo-toolkit_page_opace-eseot-help' );
		do_action( 'admin_enqueue_scripts', 'essential-seo-toolkit_page_opace-eseot-help' );
		$this->assertTrue( wp_style_is( 'opace-eseot-help', 'enqueued' ) );

		$registered = wp_styles()->registered['opace-eseot-help'];
		$this->assertStringEndsWith( 'assets/css/opace-eseot-help.css', $registered->src );
		$this->assertSame( OPACE_ESEOT_VERSION, $registered->ver, 'Cache busting uses the plugin version.' );
		$this->assertFileExists( dirname( OPACE_ESEOT_FILE ) . '/assets/css/opace-eseot-help.css' );

		$GLOBALS['wp_styles'] = null;
		set_current_screen( 'essential-seo-toolkit_page_opace-eseot-settings' );
		do_action( 'admin_enqueue_scripts', 'essential-seo-toolkit_page_opace-eseot-settings' );
		$this->assertFalse( wp_style_is( 'opace-eseot-help', 'enqueued' ) );
	}

	/* ---------------------------------------------------------------- contextual help */

	public function test_contextual_help_tabs_on_audit_settings_and_help_screens() {
		do_action( 'admin_menu' );

		$screens = array(
			'toplevel_page_opace-eseot',
			'essential-seo-toolkit_page_opace-eseot-settings',
			'essential-seo-toolkit_page_opace-eseot-help',
		);

		foreach ( $screens as $id ) {
			set_current_screen( $id );
			$screen = get_current_screen();
			$this->assertSame( $id, $screen->id );

			$tabs = $screen->get_help_tabs();
			$this->assertSame( array( 'opace-eseot-help-overview', 'opace-eseot-help-privacy' ), array_keys( $tabs ), "Two tabs on {$id}." );
			$this->assertSame( 'Overview', $tabs['opace-eseot-help-overview']['title'] );
			$this->assertSame( 'Privacy', $tabs['opace-eseot-help-privacy']['title'] );
			$this->assertNotEmpty( $tabs['opace-eseot-help-overview']['content'] );
			$this->assertStringContainsString( 'Nothing about your pages leaves this site.', $tabs['opace-eseot-help-privacy']['content'] );

			$sidebar = $screen->get_help_sidebar();
			$this->assertStringContainsString( esc_url( Opace_ESEOT_Help::page_url() ), $sidebar );
			$this->assertStringContainsString( Opace_ESEOT_Help::SUPPORT_URL, $sidebar );
		}
	}

	public function test_overview_tab_is_specific_to_each_screen() {
		do_action( 'admin_menu' );

		set_current_screen( 'toplevel_page_opace-eseot' );
		$audit = get_current_screen()->get_help_tabs();
		$this->assertStringContainsString( 'Enter any address on this site', $audit['opace-eseot-help-overview']['content'] );

		set_current_screen( 'essential-seo-toolkit_page_opace-eseot-settings' );
		$settings = get_current_screen()->get_help_tabs();
		$this->assertStringContainsString( 'Sources are the saved tool links', $settings['opace-eseot-help-overview']['content'] );

		set_current_screen( 'essential-seo-toolkit_page_opace-eseot-help' );
		$help = get_current_screen()->get_help_tabs();
		$this->assertStringContainsString( 'Use the list at the top to jump to a section.', $help['opace-eseot-help-overview']['content'] );
	}

	public function test_no_contextual_help_on_other_screens() {
		do_action( 'admin_menu' );

		foreach ( array( 'dashboard', 'edit-post', 'plugins' ) as $id ) {
			set_current_screen( $id );
			$this->assertArrayNotHasKey( 'opace-eseot-help-overview', get_current_screen()->get_help_tabs(), "No plugin help tab on {$id}." );
		}
	}
}
