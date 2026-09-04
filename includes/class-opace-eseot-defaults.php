<?php
/**
 * Default sources and categories, plus the upgrade migration.
 *
 * @package Opace_Essential_SEO_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mirrors the Chrome extension's defaults.js (VERSION 6).
 *
 * migrate() is a pure function: no WordPress calls, so it can be unit tested
 * without a WordPress bootstrap.
 */
final class Opace_ESEOT_Defaults {

	const DATA_VERSION = '2.0.0';

	/**
	 * Default categories, keyed by the Chrome category id.
	 *
	 * @return array<int, string>
	 */
	public static function categories(): array {
		return array(
			2 => 'Performance & UX',
			5 => 'Search appearance & markup',
			6 => 'Accessibility',
			7 => 'Technical & DNS',
		);
	}

	/**
	 * Default sources.
	 *
	 * @return array<int, array{name: string, url: string, cat: int}>
	 */
	public static function sources(): array {
		return array(
			array(
				'name' => 'Google PageSpeed Insights',
				'url'  => 'https://pagespeed.web.dev/analysis?url=[%url_encoded%]',
				'cat'  => 2,
			),
			array(
				'name' => 'Google Rich Results Test',
				'url'  => 'https://search.google.com/test/rich-results?url=[%url_encoded%]',
				'cat'  => 5,
			),
			array(
				'name' => 'Schema Markup Validator',
				'url'  => 'https://validator.schema.org/#url=[%url_encoded%]',
				'cat'  => 5,
			),
			array(
				'name' => 'WAVE Accessibility Evaluation',
				'url'  => 'https://wave.webaim.org/report#/[%url%]',
				'cat'  => 6,
			),
			array(
				'name' => 'Security Headers',
				'url'  => 'https://securityheaders.com/?q=[%host_encoded%]&followRedirects=on',
				'cat'  => 7,
			),
			array(
				'name' => 'Google Admin Toolbox Dig',
				'url'  => 'https://toolbox.googleapps.com/apps/dig/#A/[%host_encoded%]',
				'cat'  => 7,
			),
		);
	}

	/**
	 * Default enabled post types.
	 *
	 * @return array<string, int>
	 */
	public static function post_types(): array {
		return array(
			'post' => 1,
			'page' => 1,
		);
	}

	/**
	 * Every URL ever shipped as a default, in any WordPress release (1.0 to 1.2.6)
	 * or in the Chrome extension's legacyUrls list.
	 *
	 * A saved source is removed on upgrade only when its URL is in this list.
	 *
	 * @return string[]
	 */
	public static function legacy_urls(): array {
		$urls = array(
			// WordPress 1.0 / 1.0.1 / 1.2.0 / 1.2.5.
			'https://www.alexa.com/siteinfo/[%host%]',
			'https://online.seranking.com/login.html',
			'https://www.semrush.com/info/[%scheme%][%host%]',
			'https://tools.pingdom.com/',
			'https://gtmetrix.com/?url=[%scheme%][%host%][%path%]',
			'https://developers.google.com/speed/pagespeed/insights/?url=[%scheme%][%host%][%path%]',
			'https://search.google.com/test/mobile-friendly?url=[%scheme%][%host%][%path%]',
			'https://search.google.com/structured-data/testing-tool/u/0/#url=[%scheme%][%host%][%path%]',
			'https://search.google.com/structured-data/testing-tool#url=[%scheme%][%host%][%path%]',
			'https://www.woorank.com/en/www/[%host%]',
			'http://nibbler.silktide.com',
			'https://www.seoptimer.com/[%host%]',
			'https://sitechecker.pro/seo-report/[%scheme%][%host%][%path%]',
			'https://majestic.com/reports/site-explorer?folder=&q=[%scheme%][%host%][%path%]&IndexDataSource=F',
			'https://ahrefs.com/site-explorer',
			'https://analytics.moz.com/pro/link-explorer/overview?site=[%scheme%][%host%]&target=domain',
			'https://sharescount.com/',
			'http://countchecker.com/',
			'https://www.thinkwithgoogle.com/intl/en-gb/feature/testmysite/',
			'https://www.responsinator.com/?url=[%scheme%][%host%][%path%]',
			'https://www.xml-sitemaps.com/',
			'https://www.copyscape.com/?q=[%scheme%][%host%][%path%]',
			'http://www.siteliner.com/',
			// WordPress 1.2.6 (trunk).
			'https://app.neilpatel.com/en/traffic_analyzer/overview?lang=en&[%host%]',
			'https://www.spyfu.com/overview/domain?query=[%host%][%path%]',
			'https://trends.google.com/trends/explore?[%host%]',
			'https://nibbler.insites.com/en/progress/[%host%]',
			'https://www.browseo.net/?url=[%scheme%][%host%][%path%]',
			'https://www.wordtracker.com/inspect?query=[%host%][%path%]',
			'https://www.sharescore.com/?url=[%scheme%][%host%][%path%]',
			'https://www.social-searcher.com/social-buzz/?q5=[%host%]',
			'https://search.google.com/test/rich-results?url=[%scheme%][%host%][%path%]',
			'https://www.opengraph.xyz/url/[%scheme%][%host%][%path%]',
			'https://www.siteliner.com/[%host%]?siteliner=site-dashboard&siteliner-sort=scan_time&siteliner-from=1&siteliner-message=',
			// Chrome extension legacyUrls.
			'https://www.semrush.com/info/[%url%]',
			'https://www.semrush.com/analytics/overview/?q=[%host%]&searchType=domain',
			'https://tools.pingdom.com',
			'https://gtmetrix.com/?url=[%url%]',
			'https://developers.google.com/speed/pagespeed/insights/?url=[%url%]',
			'https://pagespeed.web.dev/analysis?url=[%url%]',
			'https://nibbler.silktide.com/',
			'https://sitechecker.pro/seo-report/[%url%]',
			'https://seositecheckup.com/seo-audit/[%host%]',
			'https://majestic.com/reports/site-explorer?folder=&q=[%url%]&IndexDataSource=F',
			'https://analytics.moz.com/pro/link-explorer/overview?site=[%url%]&target=domain',
			'https://search.google.com/test/mobile-friendly?url=[%url%]',
			'https://www.responsinator.com/?url=[%url%]',
			'https://www.browserstack.com/responsive?url=[%url%]',
			'https://www.copyscape.com/?q=[%url%]',
			'https://www.siteliner.com/',
			'https://search.google.com/structured-data/testing-tool#url=[%url%]',
			'https://search.google.com/test/rich-results?url=[%url%]',
			'https://validator.schema.org/#url=[%url%]',
			'https://securityheaders.com/?q=[%url%]&followRedirects=on',
		);

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Bring stored sources and categories up to DATA_VERSION.
	 *
	 * Steps (only when $from_version is below DATA_VERSION):
	 *  1. Remove sources whose URL is a retired default.
	 *  2. Re-key categories onto the Chrome ids by matching names, renaming
	 *     old names as it goes, and re-point sources accordingly.
	 *  3. Add each default source missing by name.
	 *  4. Make sure the four default categories exist.
	 *  5. Delete retired categories that no source uses.
	 *  6. Move any source with a missing category to the first category.
	 *
	 * @param array  $sources      Stored sources (list of name/url/cat).
	 * @param array  $categories   Stored categories (id => name).
	 * @param string $from_version Stored eseot_version ('' or '0' for unknown).
	 * @return array{sources: array, categories: array, changed: bool}
	 */
	public static function migrate( array $sources, array $categories, string $from_version ): array {
		$in_sources    = $sources;
		$in_categories = $categories;

		$sources    = self::normalise_sources( $sources );
		$categories = self::normalise_categories( $categories );

		$needs_upgrade = version_compare( '' === $from_version ? '0' : $from_version, self::DATA_VERSION, '<' );

		if ( empty( $categories ) ) {
			$categories = self::categories();
		}

		if ( $needs_upgrade ) {
			$sources = self::remove_legacy_sources( $sources );

			list( $categories, $id_map ) = self::rekey_categories( $categories );

			foreach ( $sources as &$source ) {
				if ( isset( $id_map[ $source['cat'] ] ) ) {
					$source['cat'] = $id_map[ $source['cat'] ];
				}
			}
			unset( $source );

			$sources = self::add_missing_defaults( $sources );

			foreach ( self::categories() as $id => $name ) {
				if ( ! isset( $categories[ $id ] ) ) {
					$categories[ $id ] = $name;
				}
			}

			$categories = self::remove_retired_categories( $categories, $sources );

			ksort( $categories );
			$first = array_key_first( $categories );
			foreach ( $sources as &$source ) {
				if ( ! isset( $categories[ $source['cat'] ] ) ) {
					$source['cat'] = $first;
				}
			}
			unset( $source );
		}

		$changed = $needs_upgrade || $sources !== $in_sources || $categories !== $in_categories;

		return array(
			'sources'    => array_values( $sources ),
			'categories' => $categories,
			'changed'    => $changed,
		);
	}

	/**
	 * Old category names that map onto each default category id.
	 *
	 * @return array<int, string[]>
	 */
	private static function old_names(): array {
		return array(
			2 => array( 'Speed & Performance Analysis', 'Performance' ),
			5 => array( 'Social Signals', 'Content & Structured Data', 'Search Appearance' ),
			6 => array( 'User Experience', 'Accessibility & Mobile UX' ),
			7 => array( 'Technical SEO', 'Technical Checks' ),
		);
	}

	/**
	 * Category names that are deleted on upgrade when nothing uses them.
	 *
	 * @return string[]
	 */
	private static function retired_names(): array {
		return array(
			'SEO & Traffic Analysis',
			'Traffic & Competitive Research',
			'Research & Monitoring',
			'Website & SEO Auditing',
			'Website Auditing',
			'Backlink Analysis',
			'Backlinks & Authority',
		);
	}

	/**
	 * Decode entities (legacy names were stored as "Speed &amp; Performance Analysis"),
	 * trim and collapse whitespace.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	private static function normalise_name( string $name ): string {
		$name = html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$name = preg_replace( '/\s+/u', ' ', $name );

		return trim( (string) $name );
	}

	/**
	 * Case-insensitive name lookup.
	 *
	 * @param string   $name       Normalised name.
	 * @param string[] $candidates Names to compare with.
	 * @return bool
	 */
	private static function name_in( string $name, array $candidates ): bool {
		foreach ( $candidates as $candidate ) {
			if ( 0 === strcasecmp( $name, $candidate ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Keep only well-shaped sources; cast cat to int.
	 *
	 * @param array $sources Raw sources.
	 * @return array
	 */
	private static function normalise_sources( array $sources ): array {
		$clean = array();

		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) || ! isset( $source['url'] ) || ! is_string( $source['url'] ) ) {
				continue;
			}

			$url = trim( $source['url'] );
			if ( '' === $url ) {
				continue;
			}

			$clean[] = array(
				'name' => isset( $source['name'] ) ? trim( (string) $source['name'] ) : '',
				'url'  => $url,
				'cat'  => isset( $source['cat'] ) ? (int) $source['cat'] : 0,
			);
		}

		return $clean;
	}

	/**
	 * Keep only integer-keyed, non-empty, entity-decoded category names.
	 *
	 * @param array $categories Raw categories.
	 * @return array<int, string>
	 */
	private static function normalise_categories( array $categories ): array {
		$clean = array();

		foreach ( $categories as $id => $name ) {
			if ( ! is_int( $id ) && ! ctype_digit( (string) $id ) ) {
				continue;
			}

			$name = self::normalise_name( (string) $name );
			if ( '' === $name ) {
				continue;
			}

			$clean[ (int) $id ] = $name;
		}

		return $clean;
	}

	/**
	 * Drop sources whose URL exactly matches a retired default.
	 *
	 * @param array $sources Normalised sources.
	 * @return array
	 */
	private static function remove_legacy_sources( array $sources ): array {
		$legacy = self::legacy_urls();
		$kept   = array();

		foreach ( $sources as $source ) {
			$url = html_entity_decode( $source['url'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( in_array( $source['url'], $legacy, true ) || in_array( $url, $legacy, true ) ) {
				continue;
			}
			$kept[] = $source;
		}

		return $kept;
	}

	/**
	 * Map stored categories onto the Chrome ids by name.
	 *
	 * A category whose name is a default name or one of its old names takes the
	 * default id and name. Any other category keeps its id unless that id is one
	 * of the default ids, in which case it moves to the next free id.
	 *
	 * @param array<int, string> $categories Normalised categories.
	 * @return array{0: array<int, string>, 1: array<int, int>} New categories and old id => new id map.
	 */
	private static function rekey_categories( array $categories ): array {
		$defaults  = self::categories();
		$old_names = self::old_names();
		$new       = array();
		$map       = array();
		$unmatched = array();

		foreach ( $categories as $old_id => $name ) {
			$target = null;
			foreach ( $defaults as $default_id => $default_name ) {
				if ( self::name_in( $name, array_merge( array( $default_name ), $old_names[ $default_id ] ) ) ) {
					$target = $default_id;
					break;
				}
			}

			if ( null === $target ) {
				$unmatched[ $old_id ] = $name;
				continue;
			}

			$map[ $old_id ] = $target;
			$new[ $target ] = $defaults[ $target ];
		}

		// Unmatched categories keep their id when it does not collide with a default id.
		$moved = array();
		foreach ( $unmatched as $old_id => $name ) {
			if ( isset( $defaults[ $old_id ] ) ) {
				$moved[ $old_id ] = $name;
				continue;
			}
			$new[ $old_id ] = $name;
			$map[ $old_id ] = $old_id;
		}

		foreach ( $moved as $old_id => $name ) {
			$new_id         = self::next_free_id( array_merge( array_keys( $new ), array_keys( $defaults ) ) );
			$new[ $new_id ] = $name;
			$map[ $old_id ] = $new_id;
		}

		return array( $new, $map );
	}

	/**
	 * Next id above every id in use.
	 *
	 * @param int[] $ids Ids in use.
	 * @return int
	 */
	private static function next_free_id( array $ids ): int {
		return empty( $ids ) ? 1 : max( $ids ) + 1;
	}

	/**
	 * Append each default source whose name is not already saved.
	 *
	 * @param array $sources Sources.
	 * @return array
	 */
	private static function add_missing_defaults( array $sources ): array {
		$names = array();
		foreach ( $sources as $source ) {
			$names[] = self::normalise_name( $source['name'] );
		}

		foreach ( self::sources() as $default ) {
			if ( ! self::name_in( $default['name'], $names ) ) {
				$sources[] = $default;
			}
		}

		return $sources;
	}

	/**
	 * Remove retired categories that no source points at.
	 *
	 * @param array<int, string> $categories Categories.
	 * @param array              $sources    Sources.
	 * @return array<int, string>
	 */
	private static function remove_retired_categories( array $categories, array $sources ): array {
		$retired = self::retired_names();
		$used    = array();
		foreach ( $sources as $source ) {
			$used[ $source['cat'] ] = true;
		}

		foreach ( $categories as $id => $name ) {
			if ( self::name_in( $name, $retired ) && ! isset( $used[ $id ] ) ) {
				unset( $categories[ $id ] );
			}
		}

		return $categories;
	}
}
