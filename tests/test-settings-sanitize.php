<?php
/**
 * Tests for Opace_ESEOT_Settings sanitisers.
 *
 * Contract (PLAN.md): sanitize_sources( $input ): array, sanitize_categories( $input ): array,
 * sanitize_post_types( $input ): array, get_sources(), get_categories(), static get_post_types().
 *
 * @package Opace_ESEOT_Tests
 */

class SettingsSanitizeTest extends WP_UnitTestCase {
	use Opace_ESEOT_Test_Helpers;

	/**
	 * @var Opace_ESEOT_Settings
	 */
	protected $settings;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'Opace_ESEOT_Settings' ) ) {
			$this->fail( 'Opace_ESEOT_Settings is not loaded. WS-1 has not landed yet or the plugin failed to bootstrap.' );
		}
		$this->settings = new Opace_ESEOT_Settings();
	}

	public function tear_down() {
		unregister_post_type( 'eseot_public' );
		unregister_post_type( 'eseot_private' );
		parent::tear_down();
	}

	/**
	 * Needs outbound DNS: new templates go through is_valid_template() (contract).
	 *
	 * @group dns
	 */
	public function test_sanitize_sources_drops_invalid_and_reindexes() {
		$input = array(
			0 => array( 'name' => 'Good <b>one</b>', 'url' => 'https://example.com/?u=[%url_encoded%]', 'cat' => '5' ),
			1 => array( 'name' => 'Unknown token', 'url' => 'https://example.com/?u=[%foo%]', 'cat' => '2' ),
			2 => array( 'name' => 'Javascript', 'url' => 'javascript:alert(1)', 'cat' => '2' ),
			3 => array( 'name' => 'Relative', 'url' => '/x?u=[%url%]', 'cat' => '2' ),
			7 => array( 'name' => ' Also good ', 'url' => 'https://example.net/[%host%]', 'cat' => 7 ),
		);

		$output = $this->settings->sanitize_sources( $input );

		$this->assertIsArray( $output );
		$this->assertCount( 2, $output );
		$this->assertSame( array( 0, 1 ), array_keys( $output ), 'Sources must be re-indexed from 0.' );
		$this->assertSame( 'Good one', $output[0]['name'], 'Tags must be stripped from names.' );
		$this->assertSame( 'https://example.com/?u=[%url_encoded%]', $output[0]['url'] );
		$this->assertSame( 5, $output[0]['cat'], 'cat must be cast to int.' );
		$this->assertSame( 'Also good', $output[1]['name'], 'Names should be trimmed.' );
		$this->assertSame( 7, $output[1]['cat'] );
	}

	/**
	 * @group dns
	 */
	public function test_sanitize_sources_keeps_all_defaults() {
		$defaults = Opace_ESEOT_Defaults::sources();
		$output   = $this->settings->sanitize_sources( $defaults );
		$this->assertCount( 6, $output );
		foreach ( $output as $i => $source ) {
			$this->assertIsInt( $source['cat'] );
			$this->assertSame( $defaults[ $i ]['url'], $source['url'], 'Saving must not alter a valid template (placeholders and brackets intact).' );
			$this->assertSame( $defaults[ $i ]['name'], $source['name'] );
		}
	}

	public function test_sanitize_sources_handles_non_array_input() {
		$this->assertSame( array(), $this->settings->sanitize_sources( 'nonsense' ) );
		$this->assertSame( array(), $this->settings->sanitize_sources( null ) );
	}

	public function test_sanitize_categories_preserves_ids_assigns_next_free_id_and_drops_empty_names() {
		$input = array(
			2  => 'Performance &amp; UX',
			5  => '<em>Search appearance & markup</em>',
			6  => 'Accessibility',
			7  => 'Technical & DNS',
			9  => '   ',
			'' => 'My new category',
		);

		$output = $this->settings->sanitize_categories( $input );

		$this->assertIsArray( $output );
		$this->assertArrayHasKey( 2, $output );
		$this->assertArrayHasKey( 5, $output );
		$this->assertArrayHasKey( 6, $output );
		$this->assertArrayHasKey( 7, $output );
		$this->assertArrayNotHasKey( 9, $output, 'Entries with an empty name are dropped.' );
		$this->assertArrayNotHasKey( '', $output, 'The blank key must be replaced by a numeric id.' );
		$this->assertSame( 'Search appearance & markup', $output[5], 'Tags are stripped; the ampersand stays plain text.' );
		$this->assertSame( 'Accessibility', $output[6] );
		$this->assertCount( 5, $output );

		$new_ids = array_diff( array_keys( $output ), array( 2, 5, 6, 7 ) );
		$this->assertCount( 1, $new_ids );
		$new_id = (int) reset( $new_ids );
		$this->assertSame( 'My new category', $output[ $new_id ] );
		$this->assertGreaterThan( 7, $new_id, 'New category id must be the next free id (greater than every existing id).' );
		foreach ( array_keys( $output ) as $key ) {
			$this->assertIsInt( $key );
		}
	}

	public function test_sanitize_categories_handles_non_array_input() {
		$this->assertSame( array(), $this->settings->sanitize_categories( 'nonsense' ) );
	}

	public function test_sanitize_post_types_keeps_only_registered_public_types() {
		register_post_type( 'eseot_public', array( 'public' => true, 'show_ui' => true, 'label' => 'ESEOT Public' ) );
		register_post_type( 'eseot_private', array( 'public' => false, 'show_ui' => true, 'label' => 'ESEOT Private' ) );

		$input = array(
			'post'          => '1',
			'page'          => 1,
			'eseot_public'  => 'on',
			'eseot_private' => 1,
			'revision'      => 1,
			'nonexistent'   => 1,
			'nav_menu_item' => 1,
		);

		$output = $this->settings->sanitize_post_types( $input );

		$this->assertSame( array( 'post' => 1, 'page' => 1, 'eseot_public' => 1 ), $output );
		foreach ( $output as $value ) {
			$this->assertSame( 1, $value );
		}
	}

	public function test_get_post_types_returns_public_types() {
		$types = Opace_ESEOT_Settings::get_post_types();
		$this->assertIsArray( $types );
		$this->assertContains( 'post', $types );
		$this->assertContains( 'page', $types );
		$this->assertNotContains( 'revision', $types );
	}

	public function test_getters_fire_legacy_filters() {
		update_option( 'eseot_sources', Opace_ESEOT_Defaults::sources() );
		update_option( 'eseot_categories', Opace_ESEOT_Defaults::categories() );

		$fired = array();
		add_filter( 'eseot-get-sources', function ( $v ) use ( &$fired ) { $fired[] = 'sources'; return $v; } );
		add_filter( 'eseot-get-categories', function ( $v ) use ( &$fired ) { $fired[] = 'categories'; return $v; } );

		$this->assertCount( 6, $this->settings->get_sources() );
		$this->assertCount( 4, $this->settings->get_categories() );
		$this->assertSame( array( 'sources', 'categories' ), $fired, 'Legacy eseot-get-* filters must still fire.' );

		$this->eseot_delete_options();
	}
}
