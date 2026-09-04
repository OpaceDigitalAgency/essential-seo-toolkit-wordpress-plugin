<?php
/**
 * Tests for activation seeding, maybe_upgrade() and uninstall.php.
 *
 * Contract (PLAN.md): Opace_ESEOT_Plugin::activate(): void, ::instance()->maybe_upgrade(): void,
 * OPACE_ESEOT_VERSION === '2.0.0', uninstall.php deletes the four eseot_* options.
 *
 * @package Opace_ESEOT_Tests
 */

class ActivationUpgradeTest extends WP_UnitTestCase {
	use Opace_ESEOT_Test_Helpers;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'Opace_ESEOT_Plugin' ) ) {
			$this->fail( 'Opace_ESEOT_Plugin is not loaded. WS-1 has not landed yet or the plugin failed to bootstrap.' );
		}
		$this->eseot_delete_options();
	}

	public function tear_down() {
		$this->eseot_delete_options();
		parent::tear_down();
	}

	public function test_constants() {
		$this->assertTrue( defined( 'OPACE_ESEOT_VERSION' ) );
		$this->assertSame( '2.0.0', OPACE_ESEOT_VERSION );
		$this->assertTrue( defined( 'OPACE_ESEOT_FILE' ) );
		$this->assertFileExists( OPACE_ESEOT_FILE );
		$this->assertSame( 'opace-essential-seo-toolkit.php', basename( OPACE_ESEOT_FILE ) );
	}

	public function test_instance_is_singleton() {
		$this->assertInstanceOf( 'Opace_ESEOT_Plugin', Opace_ESEOT_Plugin::instance() );
		$this->assertSame( Opace_ESEOT_Plugin::instance(), Opace_ESEOT_Plugin::instance() );
	}

	public function test_activate_on_clean_db_seeds_defaults_and_version() {
		$this->assertFalse( get_option( 'eseot_sources' ) );

		Opace_ESEOT_Plugin::activate();

		$this->assertSame( '2.0.0', get_option( 'eseot_version' ) );
		$this->assertSame( Opace_ESEOT_Defaults::sources(), get_option( 'eseot_sources' ) );
		$this->eseot_assert_default_categories_only( get_option( 'eseot_categories' ) );

		$post_types = get_option( 'eseot_post_types' );
		$this->assertIsArray( $post_types );
		$this->assertSame( 1, $post_types['post'] ?? null, 'post should be enabled by default.' );
		$this->assertSame( 1, $post_types['page'] ?? null, 'page should be enabled by default.' );
	}

	public function test_activate_does_not_overwrite_existing_data() {
		$sources    = Opace_ESEOT_Defaults::sources();
		$sources[]  = array( 'name' => 'My tool', 'url' => 'https://example.com/?u=[%url%]', 'cat' => 6 );
		$categories = Opace_ESEOT_Defaults::categories() + array( 12 => 'Mine' );
		update_option( 'eseot_sources', $sources );
		update_option( 'eseot_categories', $categories );
		update_option( 'eseot_post_types', array( 'page' => 1 ) );
		update_option( 'eseot_version', '2.0.0' );

		Opace_ESEOT_Plugin::activate();

		$this->assertSame( $sources, get_option( 'eseot_sources' ) );
		$this->assertSame( $categories, get_option( 'eseot_categories' ) );
		$this->assertSame( array( 'page' => 1 ), get_option( 'eseot_post_types' ) );
		$this->assertSame( '2.0.0', get_option( 'eseot_version' ) );
	}

	public function test_maybe_upgrade_migrates_126_data_and_bumps_version() {
		$fixture   = $this->eseot_fixture_126();
		$sources   = $fixture['sources'];
		$sources[] = $this->eseot_custom_source();
		update_option( 'eseot_sources', $sources );
		update_option( 'eseot_categories', $fixture['categories'] );
		update_option( 'eseot_post_types', $fixture['post_types'] );
		update_option( 'eseot_version', '1.2.6' );

		Opace_ESEOT_Plugin::instance()->maybe_upgrade();

		$this->assertSame( '2.0.0', get_option( 'eseot_version' ) );
		$migrated_sources    = get_option( 'eseot_sources' );
		$migrated_categories = get_option( 'eseot_categories' );

		$this->eseot_assert_no_legacy_urls( $migrated_sources );
		$this->eseot_assert_all_new_defaults_present( $migrated_sources );
		$this->eseot_assert_default_categories_only( $migrated_categories );
		$this->eseot_assert_no_orphan_sources( $migrated_sources, $migrated_categories );
		$custom = $this->eseot_find_source( $migrated_sources, 'My tool' );
		$this->assertNotNull( $custom );
		$this->assertSame( 'Accessibility', $migrated_categories[ (int) $custom['cat'] ] );

		// Post types are untouched by the migration.
		$this->assertSame( $fixture['post_types'], get_option( 'eseot_post_types' ) );

		// A second call is a no-op.
		Opace_ESEOT_Plugin::instance()->maybe_upgrade();
		$this->assertSame( $migrated_sources, get_option( 'eseot_sources' ) );
		$this->assertSame( $migrated_categories, get_option( 'eseot_categories' ) );
	}

	public function test_maybe_upgrade_with_missing_version_option_migrates() {
		$fixture = $this->eseot_fixture_126();
		update_option( 'eseot_sources', $fixture['sources'] );
		update_option( 'eseot_categories', $fixture['categories'] );
		// No eseot_version at all (1.0 - 1.2.5 installs never wrote it).

		Opace_ESEOT_Plugin::instance()->maybe_upgrade();

		$this->assertSame( '2.0.0', get_option( 'eseot_version' ) );
		$this->eseot_assert_no_legacy_urls( get_option( 'eseot_sources' ) );
		$this->eseot_assert_all_new_defaults_present( get_option( 'eseot_sources' ) );
	}

	public function test_maybe_upgrade_is_noop_at_current_version() {
		$sources    = Opace_ESEOT_Defaults::sources();
		$sources[]  = array( 'name' => 'My tool', 'url' => 'https://example.com/?u=[%url%]', 'cat' => 6 );
		$categories = Opace_ESEOT_Defaults::categories();
		// Deliberately leave a legacy URL in place: at 2.0.0 the migration must NOT run again.
		$sources[] = array( 'name' => 'Old but mine', 'url' => 'https://tools.pingdom.com/', 'cat' => 2 );
		update_option( 'eseot_sources', $sources );
		update_option( 'eseot_categories', $categories );
		update_option( 'eseot_version', '2.0.0' );

		Opace_ESEOT_Plugin::instance()->maybe_upgrade();

		$this->assertSame( $sources, get_option( 'eseot_sources' ) );
		$this->assertSame( $categories, get_option( 'eseot_categories' ) );
	}

	public function test_uninstall_deletes_all_options() {
		Opace_ESEOT_Plugin::activate();
		$this->assertNotFalse( get_option( 'eseot_sources' ) );

		$uninstall = dirname( OPACE_ESEOT_FILE ) . '/uninstall.php';
		$this->assertFileExists( $uninstall );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'opace-essential-seo-toolkit/opace-essential-seo-toolkit.php' );
		}
		include $uninstall;
		wp_cache_flush();

		foreach ( self::$eseot_options as $name ) {
			$this->assertFalse( get_option( $name ), "uninstall.php must delete {$name}." );
		}
	}

	public function test_get_supported_post_types_respects_option() {
		update_option( 'eseot_post_types', array( 'page' => 1 ) );
		$types = Opace_ESEOT_Plugin::instance()->get_supported_post_types();
		$this->assertContains( 'page', $types );
		$this->assertNotContains( 'post', $types );
	}
}
