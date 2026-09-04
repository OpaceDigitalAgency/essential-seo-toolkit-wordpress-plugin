<?php
/**
 * Tests for Opace_ESEOT_Link_Resolver.
 *
 * Contract (PLAN.md): resolve( string $template, string $permalink ): string|false,
 * is_valid_template( string $template ): bool, placeholders(): array.
 * [%host%] strips a leading www. (Chrome behaviour); [%scheme%] is scheme plus "://";
 * [%path%] is path plus ?query when present; *_encoded use rawurlencode.
 *
 * @package Opace_ESEOT_Tests
 */

class LinkResolverTest extends WP_UnitTestCase {
	use Opace_ESEOT_Test_Helpers;

	const PERMALINK = 'https://www.example.co.uk/blog/post/?a=1';

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'Opace_ESEOT_Link_Resolver' ) ) {
			$this->fail( 'Opace_ESEOT_Link_Resolver is not loaded. WS-1 has not landed yet or the plugin failed to bootstrap.' );
		}
		// wp_http_validate_url() resolves any host that is not the site's own
		// via DNS. Permalinks are the site's own URLs in real use, so pin
		// home to the permalink host and keep permalink validation offline.
		// Template hosts below use IANA-reserved names that do resolve
		// (example.com / example.net), because is_valid_template() looks
		// them up by design.
		update_option( 'home', 'https://www.example.co.uk' );
	}

	public function test_placeholders_list_matches_contract() {
		$expected = array( 'url', 'url_encoded', 'host', 'host_encoded', 'scheme', 'path' );
		$actual   = Opace_ESEOT_Link_Resolver::placeholders();
		sort( $expected );
		sort( $actual );
		$this->assertSame( $expected, $actual );
	}

	public function test_host_strips_leading_www() {
		$this->assertSame(
			'https://example.com/example.co.uk',
			Opace_ESEOT_Link_Resolver::resolve( 'https://example.com/[%host%]', self::PERMALINK )
		);
	}

	public function test_host_keeps_other_subdomains() {
		update_option( 'home', 'https://blog.example.co.uk' );
		$this->assertSame(
			'https://example.com/blog.example.co.uk',
			Opace_ESEOT_Link_Resolver::resolve( 'https://example.com/[%host%]', 'https://blog.example.co.uk/x/' )
		);
	}

	public function test_host_encoded_is_rawurlencoded_host() {
		// For a valid ASCII hostname rawurlencode() is the identity, so this
		// also proves the www. strip is applied before encoding.
		$this->assertSame(
			'https://example.com/?q=' . rawurlencode( 'example.co.uk' ),
			Opace_ESEOT_Link_Resolver::resolve( 'https://example.com/?q=[%host_encoded%]', self::PERMALINK )
		);
		$this->assertSame(
			'https://securityheaders.com/?q=example.co.uk&followRedirects=on',
			Opace_ESEOT_Link_Resolver::resolve( 'https://securityheaders.com/?q=[%host_encoded%]&followRedirects=on', self::PERMALINK )
		);
	}

	public function test_url_is_the_full_permalink() {
		$this->assertSame(
			'https://wave.webaim.org/report#/' . self::PERMALINK,
			Opace_ESEOT_Link_Resolver::resolve( 'https://wave.webaim.org/report#/[%url%]', self::PERMALINK )
		);
	}

	public function test_url_encoded_is_rawurlencoded_permalink() {
		$this->assertSame(
			'https://pagespeed.web.dev/analysis?url=' . rawurlencode( self::PERMALINK ),
			Opace_ESEOT_Link_Resolver::resolve( 'https://pagespeed.web.dev/analysis?url=[%url_encoded%]', self::PERMALINK )
		);
		$this->assertStringContainsString( 'https%3A%2F%2Fwww.example.co.uk%2Fblog%2Fpost%2F%3Fa%3D1', Opace_ESEOT_Link_Resolver::resolve( 'https://pagespeed.web.dev/analysis?url=[%url_encoded%]', self::PERMALINK ) );
	}

	public function test_scheme_resolves_to_scheme_and_separator() {
		$this->assertSame(
			'https://example.com/?s=https://example.co.uk',
			Opace_ESEOT_Link_Resolver::resolve( 'https://example.com/?s=[%scheme%][%host%]', self::PERMALINK )
		);
		$this->assertSame(
			'https://example.com/?s=http://example.co.uk',
			Opace_ESEOT_Link_Resolver::resolve( 'https://example.com/?s=[%scheme%][%host%]', 'http://www.example.co.uk/' )
		);
	}

	public function test_path_is_path_plus_query() {
		$this->assertSame(
			'https://example.com/p/blog/post/?a=1',
			Opace_ESEOT_Link_Resolver::resolve( 'https://example.com/p[%path%]', self::PERMALINK )
		);
		$this->assertSame(
			'https://example.com/p/blog/post/',
			Opace_ESEOT_Link_Resolver::resolve( 'https://example.com/p[%path%]', 'https://www.example.co.uk/blog/post/' )
		);
	}

	public function test_all_placeholders_in_one_template() {
		$template = 'https://example.com/[%host%]/[%host_encoded%]/?u=[%url%]&e=[%url_encoded%]&s=[%scheme%]&p=[%path%]';
		$expected = 'https://example.com/example.co.uk/example.co.uk/?u=' . self::PERMALINK . '&e=' . rawurlencode( self::PERMALINK ) . '&s=https://&p=/blog/post/?a=1';
		$this->assertSame( $expected, Opace_ESEOT_Link_Resolver::resolve( $template, self::PERMALINK ) );
	}

	public function test_template_without_placeholders_is_returned_unchanged() {
		$this->assertSame( 'https://tools.pingdom.com/', Opace_ESEOT_Link_Resolver::resolve( 'https://tools.pingdom.com/', self::PERMALINK ) );
		$this->assertTrue( Opace_ESEOT_Link_Resolver::is_valid_template( 'https://tools.pingdom.com/' ) );
	}

	/**
	 * Needs outbound DNS: is_valid_template() resolves the template host (contract).
	 *
	 * @group dns
	 * @dataProvider data_valid_templates
	 */
	public function test_valid_templates( $template ) {
		$this->assertTrue( Opace_ESEOT_Link_Resolver::is_valid_template( $template ), "Expected valid: {$template}" );
		$this->assertIsString( Opace_ESEOT_Link_Resolver::resolve( $template, self::PERMALINK ) );
	}

	public function data_valid_templates() {
		$out = array();
		foreach ( Opace_ESEOT_Defaults::sources() as $source ) {
			$out[ $source['name'] ] = array( $source['url'] );
		}
		$out['legacy scheme+host+path'] = array( 'https://gtmetrix.com/?url=[%scheme%][%host%][%path%]' );
		$out['http scheme']             = array( 'http://example.com/?u=[%url%]' );
		return $out;
	}

	/**
	 * @dataProvider data_invalid_templates
	 */
	public function test_invalid_templates_are_rejected( $template ) {
		$this->assertFalse( Opace_ESEOT_Link_Resolver::is_valid_template( $template ), "Expected invalid: {$template}" );
		$this->assertFalse( Opace_ESEOT_Link_Resolver::resolve( $template, self::PERMALINK ), "Expected resolve() false for: {$template}" );
	}

	public function data_invalid_templates() {
		return array(
			'unknown token'          => array( 'https://example.com/?u=[%foo%]' ),
			'unknown token uppercase' => array( 'https://example.com/?u=[%URL%]' ),
			'javascript scheme'      => array( 'javascript:alert(1)//[%url%]' ),
			'data scheme'            => array( 'data:text/html,[%url%]' ),
			'ftp scheme'             => array( 'ftp://example.com/[%url%]' ),
			'relative path'          => array( '/tools?u=[%url%]' ),
			'scheme-relative'        => array( '//example.com/?u=[%url%]' ),
			'bare words'             => array( 'not a url [%url%]' ),
			'empty'                  => array( '' ),
		);
	}

	/**
	 * @dataProvider data_invalid_permalinks
	 */
	public function test_invalid_permalink_returns_false( $permalink ) {
		$this->assertFalse( Opace_ESEOT_Link_Resolver::resolve( 'https://example.com/?u=[%url%]', $permalink ) );
	}

	public function data_invalid_permalinks() {
		return array(
			'empty'      => array( '' ),
			'not a url'  => array( 'not a url' ),
			'javascript' => array( 'javascript:alert(1)' ),
			'relative'   => array( '/blog/post/' ),
		);
	}
}
