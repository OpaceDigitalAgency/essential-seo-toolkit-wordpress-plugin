<?php
/**
 * Tests for the meta box markup contract (PLAN.md "Admin markup contract").
 *
 * @package Opace_ESEOT_Tests
 */

class MetaboxRenderTest extends WP_UnitTestCase {
	use Opace_ESEOT_Test_Helpers;

	/**
	 * @var WP_Post
	 */
	protected $post;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'Opace_ESEOT_Plugin' ) ) {
			$this->fail( 'Opace_ESEOT_Plugin is not loaded. WS-1 has not landed yet or the plugin failed to bootstrap.' );
		}
		require_once ABSPATH . 'wp-admin/includes/template.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/post.php';

		$this->eseot_delete_options();
		Opace_ESEOT_Plugin::activate();
		update_option( 'eseot_post_types', array( 'post' => 1 ) );
		wp_cache_flush();

		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
		$this->post = self::factory()->post->create_and_get( array( 'post_title' => 'Metabox test post', 'post_status' => 'publish' ) );
	}

	public function tear_down() {
		global $wp_meta_boxes;
		$wp_meta_boxes = array();
		set_current_screen( 'front' );
		$this->eseot_delete_options();
		parent::tear_down();
	}

	/**
	 * Register meta boxes for a screen the way edit-form-advanced.php does
	 * and return the plugin's box (id, title, callback, args).
	 *
	 * @param string $post_type Post type.
	 * @return array|null
	 */
	protected function locate_plugin_metabox( $post_type ) {
		global $wp_meta_boxes;
		$wp_meta_boxes = array();
		set_current_screen( $post_type );
		$screen = get_current_screen();
		$screen->post_type = $post_type;

		do_action( 'add_meta_boxes', $post_type, $this->post );
		do_action( "add_meta_boxes_{$post_type}", $this->post );
		foreach ( array( 'normal', 'advanced', 'side' ) as $context ) {
			do_action( 'do_meta_boxes', $post_type, $context, $this->post );
		}

		if ( empty( $wp_meta_boxes[ $post_type ] ) ) {
			return null;
		}
		foreach ( $wp_meta_boxes[ $post_type ] as $context => $priorities ) {
			foreach ( $priorities as $priority => $boxes ) {
				foreach ( (array) $boxes as $id => $box ) {
					if ( ! is_array( $box ) || empty( $box['callback'] ) ) {
						continue;
					}
					$cb = $box['callback'];
					$is_ours = ( false !== stripos( (string) $id, 'eseot' ) )
						|| ( is_array( $cb ) && is_object( $cb[0] ) && 0 === strpos( get_class( $cb[0] ), 'Opace_ESEOT' ) )
						|| ( is_array( $cb ) && is_string( $cb[0] ) && 0 === strpos( $cb[0], 'Opace_ESEOT' ) );
					if ( $is_ours ) {
						return $box + array( 'context' => $context, 'priority' => $priority );
					}
				}
			}
		}
		return null;
	}

	protected function render_metabox() {
		$box = $this->locate_plugin_metabox( 'post' );
		$this->assertNotNull( $box, 'The plugin did not register a meta box for the enabled "post" type.' );
		$this->assertSame( 'side', $box['context'] );
		$this->assertSame( 'low', $box['priority'] );

		ob_start();
		call_user_func( $box['callback'], $this->post, $box );
		return ob_get_clean();
	}

	public function test_metabox_not_registered_for_disabled_post_type() {
		$this->assertNull( $this->locate_plugin_metabox( 'page' ), 'No meta box should be registered for a post type that is not enabled.' );
	}

	public function test_metabox_markup_contract() {
		$html = $this->render_metabox();
		$this->assertNotEmpty( $html );

		$this->assertMatchesRegularExpression( '/<div\b[^>]*\bclass="[^"]*\bopace-eseot-metabox\b[^"]*"/', $html, 'Wrapper div with class opace-eseot-metabox.' );
		$this->assertStringContainsString( 'opace-eseot-metabox__header', $html );
		$this->assertStringContainsString( 'opace-eseot-metabox__intro', $html );
		$this->assertMatchesRegularExpression( '/<img\b[^>]*\bclass="[^"]*\bopace-eseot-metabox__mark\b[^"]*"[^>]*\balt=""/', $html, 'Decorative mark image with empty alt.' );
		$this->assertMatchesRegularExpression( '/<img\b[^>]*opace-eseot-mark\.svg/', $html );

		$this->assertMatchesRegularExpression( '/<label\b[^>]*\bclass="screen-reader-text"[^>]*\bfor="opace-eseot-search"/', $html, 'Screen-reader label for the search input.' );
		$this->assertMatchesRegularExpression( '/<input\b[^>]*\btype="search"[^>]*\bid="opace-eseot-search"|<input\b[^>]*\bid="opace-eseot-search"[^>]*\btype="search"/', $html, 'Search input #opace-eseot-search of type search.' );

		$this->assertMatchesRegularExpression( '/<ul\b[^>]*\bclass="[^"]*\bopace-eseot-accordion\b[^"]*"/', $html );

		$categories = get_option( 'eseot_categories' );
		preg_match_all( '/<button\b[^>]*\bclass="[^"]*\bopace-eseot-toggle\b[^"]*"[^>]*>/', $html, $toggles );
		$this->assertCount( count( $categories ), $toggles[0], 'Exactly one toggle button per category.' );
		foreach ( $toggles[0] as $button ) {
			$this->assertStringContainsString( 'type="button"', $button );
			$this->assertStringContainsString( 'aria-expanded="false"', $button );
			$this->assertMatchesRegularExpression( '/aria-controls="opace-eseot-cat-\d+"/', $button );
		}
		foreach ( $categories as $id => $name ) {
			$this->assertStringContainsString( 'data-category="' . $id . '"', $html );
			$this->assertStringContainsString( 'id="opace-eseot-cat-' . $id . '"', $html );
			$this->assertStringContainsString( esc_html( $name ), $html );
		}

		preg_match_all( '/<li\b[^>]*\bclass="[^"]*\bopace-eseot-source\b[^"]*"/', $html, $items );
		$this->assertCount( count( get_option( 'eseot_sources' ) ), $items[0], 'One list item per source.' );

		preg_match_all( '/<a\b[^>]*>/', $html, $anchors );
		$this->assertNotEmpty( $anchors[0] );
		foreach ( $anchors[0] as $anchor ) {
			if ( false === strpos( $anchor, 'target="_blank"' ) ) {
				continue;
			}
			$this->assertMatchesRegularExpression( '/\brel="[^"]*\bnoopener\b[^"]*"/', $anchor, "Missing rel=noopener: {$anchor}" );
			$this->assertMatchesRegularExpression( '/\brel="[^"]*\bnoreferrer\b[^"]*"/', $anchor, "Missing rel=noreferrer: {$anchor}" );
		}
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $html );

		$permalink = get_permalink( $this->post );
		$this->assertStringContainsString( 'href="' . esc_url( $permalink ) . '"', $html, 'Intro links to the post permalink.' );
		$this->assertStringContainsString( 'Metabox test post', $html );

		$expected_psi = 'https://pagespeed.web.dev/analysis?url=' . rawurlencode( $permalink );
		$this->assertStringContainsString( esc_url( $expected_psi ), $html, 'PageSpeed link must carry the rawurlencoded permalink.' );
		$this->assertStringContainsString( rawurlencode( $permalink ), $html );

		$this->assertMatchesRegularExpression( '/<p\b[^>]*\bclass="[^"]*\bopace-eseot-empty\b[^"]*"[^>]*\bhidden/', $html, 'Hidden empty-state paragraph.' );
		$this->assertStringContainsString( 'opace-eseot-metabox__note', $html );
		$this->assertStringNotContainsString( '[%', $html, 'No unresolved placeholders may leak into the markup.' );
	}

	public function test_metabox_renders_on_page_when_enabled() {
		update_option( 'eseot_post_types', array( 'post' => 1, 'page' => 1 ) );
		$this->post = self::factory()->post->create_and_get( array( 'post_type' => 'page', 'post_title' => 'Metabox test page', 'post_status' => 'publish' ) );
		$box = $this->locate_plugin_metabox( 'page' );
		$this->assertNotNull( $box );
		ob_start();
		call_user_func( $box['callback'], $this->post, $box );
		$html = ob_get_clean();
		$this->assertStringContainsString( 'opace-eseot-metabox', $html );
		$this->assertStringContainsString( rawurlencode( get_permalink( $this->post ) ), $html );
	}

	public function test_metabox_assets_enqueued_only_on_supported_screens() {
		set_current_screen( 'post' );
		get_current_screen()->post_type = 'post';
		do_action( 'admin_enqueue_scripts', 'post.php' );
		$this->assertTrue( wp_script_is( 'opace-eseot-metabox', 'enqueued' ), 'Meta box script should be enqueued on post.php for an enabled type.' );
		$this->assertTrue( wp_style_is( 'opace-eseot-metabox', 'enqueued' ), 'Meta box style should be enqueued on post.php for an enabled type.' );

		$script = wp_scripts()->registered['opace-eseot-metabox'];
		$this->assertSame( OPACE_ESEOT_VERSION, $script->ver, 'Cache busting uses OPACE_ESEOT_VERSION.' );
		$this->assertNotEmpty( $script->extra['group'] ?? null, 'Script is loaded in the footer (in_footer => true sets group 1).' );

		wp_dequeue_script( 'opace-eseot-metabox' );
		wp_dequeue_style( 'opace-eseot-metabox' );
		set_current_screen( 'edit-page' );
		get_current_screen()->post_type = 'page';
		do_action( 'admin_enqueue_scripts', 'edit.php' );
		$this->assertFalse( wp_script_is( 'opace-eseot-metabox', 'enqueued' ), 'No meta box script on a disabled type list screen.' );
	}
}
