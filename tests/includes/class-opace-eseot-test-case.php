<?php
/**
 * Shared helpers for the plugin test suite.
 *
 * Declared before the WordPress bootstrap so it must not reference
 * WP_UnitTestCase at file level; the trait is mixed into each test class.
 *
 * @package Opace_ESEOT_Tests
 */

trait Opace_ESEOT_Test_Helpers {

	/**
	 * Option names owned by the plugin.
	 *
	 * @var string[]
	 */
	protected static $eseot_options = array( 'eseot_sources', 'eseot_categories', 'eseot_post_types', 'eseot_version' );

	/**
	 * Delete every plugin option.
	 */
	protected function eseot_delete_options() {
		foreach ( self::$eseot_options as $name ) {
			delete_option( $name );
		}
		wp_cache_flush();
	}

	/**
	 * Exact 1.2.6 defaults captured from trunk r2894924.
	 *
	 * @return array{version:string,post_types:array,categories:array,sources:array}
	 */
	protected function eseot_fixture_126() {
		return include dirname( __DIR__ ) . '/fixtures/options-1.2.6.php';
	}

	/**
	 * The custom, user-added source used across migration tests.
	 * cat 5 was "User Experience" in 1.2.6, which maps to "Accessibility".
	 *
	 * @return array{name:string,url:string,cat:int}
	 */
	protected function eseot_custom_source() {
		return array(
			'name' => 'My tool',
			'url'  => 'https://example.com/?u=[%url%]',
			'cat'  => 5,
		);
	}

	/**
	 * Every URL shipped as a default in 1.2.6.
	 *
	 * @return string[]
	 */
	protected function eseot_legacy_126_urls() {
		return array_values( array_map( static function ( $s ) { return $s['url']; }, $this->eseot_fixture_126()['sources'] ) );
	}

	/**
	 * Find a source by exact name.
	 *
	 * @param array  $sources List of sources.
	 * @param string $name    Name to find.
	 * @return array|null
	 */
	protected function eseot_find_source( array $sources, $name ) {
		foreach ( $sources as $source ) {
			if ( isset( $source['name'] ) && $source['name'] === $name ) {
				return $source;
			}
		}
		return null;
	}

	/**
	 * Assert that the four 2.0.0 default categories are present and nothing else.
	 *
	 * @param array $categories id => name.
	 */
	protected function eseot_assert_default_categories_only( array $categories ) {
		$expected = Opace_ESEOT_Defaults::categories();
		ksort( $expected );
		ksort( $categories );
		$this->assertSame( $expected, $categories, 'Categories should be exactly the four 2.0.0 defaults, keyed by the Chrome ids.' );
	}

	/**
	 * Assert that all six 2.0.0 default sources are present with the exact URL and category.
	 *
	 * @param array $sources List of sources.
	 */
	protected function eseot_assert_all_new_defaults_present( array $sources ) {
		foreach ( Opace_ESEOT_Defaults::sources() as $default ) {
			$found = $this->eseot_find_source( $sources, $default['name'] );
			$this->assertNotNull( $found, "Default source '{$default['name']}' is missing." );
			$this->assertSame( $default['url'], $found['url'], "Default source '{$default['name']}' has the wrong URL." );
			$this->assertSame( (int) $default['cat'], (int) $found['cat'], "Default source '{$default['name']}' is in the wrong category." );
		}
	}

	/**
	 * Assert no source URL equals a retired default URL.
	 *
	 * @param array $sources List of sources.
	 */
	protected function eseot_assert_no_legacy_urls( array $sources ) {
		$legacy = array_merge( $this->eseot_legacy_126_urls(), Opace_ESEOT_Defaults::legacy_urls() );
		foreach ( $sources as $source ) {
			$this->assertNotContains( $source['url'], $legacy, "Legacy default URL still present: {$source['url']}" );
		}
	}

	/**
	 * Assert every source points at an existing category.
	 *
	 * @param array $sources    List of sources.
	 * @param array $categories id => name.
	 */
	protected function eseot_assert_no_orphan_sources( array $sources, array $categories ) {
		foreach ( $sources as $source ) {
			$this->assertArrayHasKey( (int) $source['cat'], $categories, "Source '{$source['name']}' points at a category that does not exist (cat {$source['cat']})." );
		}
	}
}
