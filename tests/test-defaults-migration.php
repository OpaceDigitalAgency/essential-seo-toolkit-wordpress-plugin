<?php
/**
 * Tests for Opace_ESEOT_Defaults (2.0.0 defaults and the 1.2.6 -> 2.0.0 migration).
 *
 * Contract (PLAN.md): categories(), sources(), legacy_urls(),
 * migrate( array $sources, array $categories, string $from_version ): array{sources, categories, changed}.
 *
 * @package Opace_ESEOT_Tests
 */

class DefaultsMigrationTest extends WP_UnitTestCase {
	use Opace_ESEOT_Test_Helpers;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'Opace_ESEOT_Defaults' ) ) {
			$this->fail( 'Opace_ESEOT_Defaults is not loaded. WS-1 has not landed yet or the plugin failed to bootstrap.' );
		}
	}

	public function test_default_categories_match_chrome_version_6() {
		$this->assertSame(
			array(
				2 => 'Performance & UX',
				5 => 'Search appearance & markup',
				6 => 'Accessibility',
				7 => 'Technical & DNS',
			),
			Opace_ESEOT_Defaults::categories()
		);
	}

	public function test_default_sources_match_chrome_version_6() {
		$expected = array(
			array( 'name' => 'Google PageSpeed Insights', 'url' => 'https://pagespeed.web.dev/analysis?url=[%url_encoded%]', 'cat' => 2 ),
			array( 'name' => 'Google Rich Results Test', 'url' => 'https://search.google.com/test/rich-results?url=[%url_encoded%]', 'cat' => 5 ),
			array( 'name' => 'Schema Markup Validator', 'url' => 'https://validator.schema.org/#url=[%url_encoded%]', 'cat' => 5 ),
			array( 'name' => 'WAVE Accessibility Evaluation', 'url' => 'https://wave.webaim.org/report#/[%url%]', 'cat' => 6 ),
			array( 'name' => 'Security Headers', 'url' => 'https://securityheaders.com/?q=[%host_encoded%]&followRedirects=on', 'cat' => 7 ),
			array( 'name' => 'Google Admin Toolbox Dig', 'url' => 'https://toolbox.googleapps.com/apps/dig/#A/[%host_encoded%]', 'cat' => 7 ),
		);
		$actual = Opace_ESEOT_Defaults::sources();
		$this->assertCount( 6, $actual );
		foreach ( $expected as $i => $source ) {
			$this->assertSame( $source['name'], $actual[ $i ]['name'] );
			$this->assertSame( $source['url'], $actual[ $i ]['url'] );
			$this->assertSame( $source['cat'], (int) $actual[ $i ]['cat'] );
		}
		$this->assertSame( '2.0.0', Opace_ESEOT_Defaults::DATA_VERSION );
	}

	public function test_legacy_urls_include_every_126_default() {
		$legacy = Opace_ESEOT_Defaults::legacy_urls();
		$this->assertIsArray( $legacy );
		foreach ( $this->eseot_legacy_126_urls() as $url ) {
			$this->assertContains( $url, $legacy, "1.2.6 default URL missing from legacy_urls(): {$url}" );
		}
		foreach ( Opace_ESEOT_Defaults::sources() as $source ) {
			$this->assertNotContains( $source['url'], $legacy, 'A current default URL must not be listed as legacy.' );
		}
	}

	public function test_migrate_from_126_with_custom_source() {
		$fixture   = $this->eseot_fixture_126();
		$sources   = $fixture['sources'];
		$sources[] = $this->eseot_custom_source();

		$result = Opace_ESEOT_Defaults::migrate( $sources, $fixture['categories'], '1.2.6' );

		$this->assertArrayHasKey( 'sources', $result );
		$this->assertArrayHasKey( 'categories', $result );
		$this->assertArrayHasKey( 'changed', $result );
		$this->assertTrue( $result['changed'] );

		$this->eseot_assert_no_legacy_urls( $result['sources'] );
		$this->eseot_assert_all_new_defaults_present( $result['sources'] );
		$this->eseot_assert_default_categories_only( $result['categories'] );
		$this->eseot_assert_no_orphan_sources( $result['sources'], $result['categories'] );

		// Six defaults plus the custom one, and nothing else.
		$this->assertCount( 7, $result['sources'] );
		$this->assertSame( range( 0, 6 ), array_keys( $result['sources'] ), 'Sources should be a re-indexed list.' );

		$custom = $this->eseot_find_source( $result['sources'], 'My tool' );
		$this->assertNotNull( $custom, 'Custom source must survive migration.' );
		$this->assertSame( 'https://example.com/?u=[%url%]', $custom['url'] );
		$this->assertSame( 'Accessibility', $result['categories'][ (int) $custom['cat'] ], 'Custom source in 1.2.6 "User Experience" should now sit in "Accessibility".' );

		foreach ( $result['categories'] as $name ) {
			$this->assertStringNotContainsString( '&amp;', $name, 'Category names must be stored as plain text.' );
		}
	}

	public function test_migrate_is_idempotent_on_200_data() {
		$sources    = Opace_ESEOT_Defaults::sources();
		$sources[]  = array( 'name' => 'My tool', 'url' => 'https://example.com/?u=[%url%]', 'cat' => 6 );
		$categories = Opace_ESEOT_Defaults::categories();

		$result = Opace_ESEOT_Defaults::migrate( $sources, $categories, '2.0.0' );
		$this->assertFalse( $result['changed'] );
		$this->assertSame( $sources, $result['sources'] );
		$this->assertSame( $categories, $result['categories'] );
	}

	public function test_migrate_twice_yields_no_further_change() {
		$fixture   = $this->eseot_fixture_126();
		$sources   = $fixture['sources'];
		$sources[] = $this->eseot_custom_source();
		$first     = Opace_ESEOT_Defaults::migrate( $sources, $fixture['categories'], '1.2.6' );
		$second    = Opace_ESEOT_Defaults::migrate( $first['sources'], $first['categories'], '2.0.0' );
		$this->assertFalse( $second['changed'] );
		$this->assertSame( $first['sources'], $second['sources'] );
		$this->assertSame( $first['categories'], $second['categories'] );
	}

	public function test_migrate_empty_arrays_yields_defaults() {
		$result = Opace_ESEOT_Defaults::migrate( array(), array(), '1.2.6' );
		$this->assertTrue( $result['changed'] );
		$this->eseot_assert_default_categories_only( $result['categories'] );
		$this->assertCount( 6, $result['sources'] );
		$this->eseot_assert_all_new_defaults_present( $result['sources'] );
	}

	public function test_user_edited_default_survives() {
		$fixture = $this->eseot_fixture_126();
		$sources = $fixture['sources'];
		$edited  = 'https://developers.google.com/speed/pagespeed/insights/?url=[%url%]&hl=en';
		foreach ( $sources as &$source ) {
			if ( 'Google PageSpeed Insights' === $source['name'] ) {
				$source['url'] = $edited;
			}
		}
		unset( $source );

		$result = Opace_ESEOT_Defaults::migrate( $sources, $fixture['categories'], '1.2.6' );

		$matches = array_values( array_filter( $result['sources'], static function ( $s ) { return 'Google PageSpeed Insights' === $s['name']; } ) );
		$this->assertCount( 1, $matches, 'A user-edited default keeps its name and must not be duplicated by the new default.' );
		$this->assertSame( $edited, $matches[0]['url'] );
		$this->assertSame( 'Performance & UX', $result['categories'][ (int) $matches[0]['cat'] ], '1.2.6 "Speed & Performance Analysis" should map to "Performance & UX".' );

		// The other five defaults are still added.
		foreach ( Opace_ESEOT_Defaults::sources() as $default ) {
			if ( 'Google PageSpeed Insights' === $default['name'] ) {
				continue;
			}
			$this->assertNotNull( $this->eseot_find_source( $result['sources'], $default['name'] ) );
		}
	}

	public function test_retired_category_is_kept_while_a_source_still_uses_it() {
		$fixture   = $this->eseot_fixture_126();
		$sources   = $fixture['sources'];
		$sources[] = array( 'name' => 'My backlink tool', 'url' => 'https://example.com/links?d=[%host%]', 'cat' => 3 ); // 3 = Backlink Analysis (retired).

		$result = Opace_ESEOT_Defaults::migrate( $sources, $fixture['categories'], '1.2.6' );

		$this->assertContains( 'Backlink Analysis', $result['categories'], 'Retired category still in use must be kept.' );
		foreach ( Opace_ESEOT_Defaults::categories() as $name ) {
			$this->assertContains( $name, $result['categories'] );
		}
		$this->assertCount( 5, $result['categories'] );
		$custom = $this->eseot_find_source( $result['sources'], 'My backlink tool' );
		$this->assertNotNull( $custom );
		$this->assertSame( 'Backlink Analysis', $result['categories'][ (int) $custom['cat'] ] );
		$this->eseot_assert_no_orphan_sources( $result['sources'], $result['categories'] );
	}

	public function test_orphaned_source_is_moved_to_first_remaining_category() {
		$sources    = array( array( 'name' => 'Orphan', 'url' => 'https://example.com/?u=[%url%]', 'cat' => 99 ) );
		$categories = array( 0 => 'SEO &amp; Traffic Analysis' );
		$result     = Opace_ESEOT_Defaults::migrate( $sources, $categories, '1.2.6' );
		$orphan     = $this->eseot_find_source( $result['sources'], 'Orphan' );
		$this->assertNotNull( $orphan );
		$this->assertArrayHasKey( (int) $orphan['cat'], $result['categories'] );
		$this->assertSame( array_key_first( $result['categories'] ), (int) $orphan['cat'] );
	}
}
