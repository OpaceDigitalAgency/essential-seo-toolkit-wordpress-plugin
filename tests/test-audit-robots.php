<?php
/**
 * Tests for robots.txt parsing and sitemap discovery in Opace_ESEOT_Audit.
 *
 * @package Opace_ESEOT_Tests
 */

require_once __DIR__ . '/includes/trait-opace-eseot-audit-http-mock.php';

class AuditRobotsTest extends WP_UnitTestCase {
	use Opace_ESEOT_Audit_Http_Mock;

	public function set_up() {
		parent::set_up();
		if ( ! class_exists( 'Opace_ESEOT_Audit' ) ) {
			$this->fail( 'Opace_ESEOT_Audit is not loaded. WS-9 has not landed yet or the plugin failed to bootstrap.' );
		}
		$this->eseot_mock_http();
		$this->eseot_delete_crawl_transients();
	}

	public function tear_down() {
		$this->eseot_unmock_http();
		$this->eseot_delete_crawl_transients();
		parent::tear_down();
	}

	protected function audit() {
		return Opace_ESEOT_Plugin::instance()->audit();
	}

	protected function parse( $body, $path ) {
		return Opace_ESEOT_Audit::parse_robots( $body, $path );
	}

	/* ------------------------------------------------------------------
	 * parse_robots()
	 * ---------------------------------------------------------------- */

	public function test_sitemap_lines_are_collected_from_anywhere_and_deduplicated() {
		$body = "Sitemap: https://example.org/a.xml\nUser-agent: *\nDisallow: /x\nSitemap: https://example.org/b.xml\nsitemap: https://example.org/a.xml\nSitemap: /relative.xml\nSitemap: ftp://example.org/c.xml\n";

		$result = $this->parse( $body, '/' );

		$this->assertSame( array( 'https://example.org/a.xml', 'https://example.org/b.xml' ), $result['sitemaps'] );
	}

	public function test_empty_body_allows_everything() {
		$result = $this->parse( '', '/anything/' );

		$this->assertFalse( $result['disallowed'] );
		$this->assertNull( $result['matched_rule'] );
		$this->assertNull( $result['agent'] );
		$this->assertSame( array(), $result['sitemaps'] );
	}

	public function test_disallow_matches_by_prefix() {
		$body = "User-agent: *\nDisallow: /private/\n";

		$hit = $this->parse( $body, '/private/page/' );
		$this->assertTrue( $hit['disallowed'] );
		$this->assertSame( 'Disallow: /private/', $hit['matched_rule'] );
		$this->assertSame( '*', $hit['agent'] );

		$miss = $this->parse( $body, '/public/' );
		$this->assertFalse( $miss['disallowed'] );
		$this->assertNull( $miss['matched_rule'] );

		$this->assertFalse( $this->parse( $body, '/private' )['disallowed'], 'Prefix match: /private does not match /private/.' );
	}

	public function test_longest_match_wins_and_allow_wins_a_tie() {
		$wp_default = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";

		$ajax = $this->parse( $wp_default, '/wp-admin/admin-ajax.php' );
		$this->assertFalse( $ajax['disallowed'] );
		$this->assertSame( 'Allow: /wp-admin/admin-ajax.php', $ajax['matched_rule'] );

		$edit = $this->parse( $wp_default, '/wp-admin/edit.php' );
		$this->assertTrue( $edit['disallowed'] );
		$this->assertSame( 'Disallow: /wp-admin/', $edit['matched_rule'] );

		$tie = $this->parse( "User-agent: *\nDisallow: /a\nAllow: /a\n", '/a' );
		$this->assertFalse( $tie['disallowed'], 'Equal-length Allow and Disallow: Allow wins.' );
		$this->assertSame( 'Allow: /a', $tie['matched_rule'] );

		$tie_reversed = $this->parse( "User-agent: *\nAllow: /a\nDisallow: /a\n", '/a' );
		$this->assertFalse( $tie_reversed['disallowed'], 'Order of the tied rules does not matter.' );
	}

	public function test_wildcard_and_end_anchor() {
		$pdf = "User-agent: *\nDisallow: /*.pdf$\n";
		$this->assertTrue( $this->parse( $pdf, '/files/report.pdf' )['disallowed'] );
		$this->assertFalse( $this->parse( $pdf, '/files/report.pdf?download=1' )['disallowed'], '$ anchors the end; the query is part of the matched path.' );
		$this->assertFalse( $this->parse( $pdf, '/files/report.pdfx' )['disallowed'] );

		$query = "User-agent: *\nDisallow: /*?\n";
		$this->assertTrue( $this->parse( $query, '/page/?s=term' )['disallowed'] );
		$this->assertFalse( $this->parse( $query, '/page/' )['disallowed'] );

		$middle = "User-agent: *\nDisallow: /blog/*/amp/\n";
		$this->assertTrue( $this->parse( $middle, '/blog/hello-world/amp/' )['disallowed'] );
		$this->assertFalse( $this->parse( $middle, '/blog/hello-world/' )['disallowed'] );

		$literal_dollar_inside = "User-agent: *\nDisallow: /a\$b\n";
		$this->assertFalse( $this->parse( $literal_dollar_inside, '/a' )['disallowed'], 'Only a trailing $ is an anchor.' );
	}

	public function test_googlebot_group_takes_precedence_over_star() {
		$body = "User-agent: *\nDisallow: /\n\nUser-agent: Googlebot\nDisallow: /secret/\n";

		$home = $this->parse( $body, '/' );
		$this->assertFalse( $home['disallowed'], 'Googlebot has its own group, so the * group is ignored entirely.' );
		$this->assertSame( 'googlebot', $home['agent'] );

		$secret = $this->parse( $body, '/secret/x' );
		$this->assertTrue( $secret['disallowed'] );
		$this->assertSame( 'Disallow: /secret/', $secret['matched_rule'] );
	}

	public function test_groups_for_other_agents_are_ignored() {
		$body = "User-agent: Bingbot\nDisallow: /\n";

		$result = $this->parse( $body, '/' );
		$this->assertFalse( $result['disallowed'] );
		$this->assertNull( $result['agent'] );

		$mixed = "User-agent: Bingbot\nDisallow: /\n\nUser-agent: *\nDisallow: /tmp/\n";
		$this->assertFalse( $this->parse( $mixed, '/' )['disallowed'] );
		$this->assertTrue( $this->parse( $mixed, '/tmp/x' )['disallowed'] );
	}

	public function test_consecutive_user_agent_lines_form_one_group_and_same_agent_groups_merge() {
		$shared = "User-agent: Bingbot\nUser-agent: *\nDisallow: /x\n";
		$this->assertTrue( $this->parse( $shared, '/x/y' )['disallowed'] );

		$merged = "User-agent: *\nDisallow: /a\n\nUser-agent: *\nDisallow: /b\n";
		$this->assertTrue( $this->parse( $merged, '/a' )['disallowed'] );
		$this->assertTrue( $this->parse( $merged, '/b' )['disallowed'] );
	}

	public function test_comments_blank_values_crlf_and_bom_are_handled() {
		$body = "\xEF\xBB\xBF# Robots for tests\r\nUser-Agent: * # everyone\r\nDisallow:\r\nDisallow: /y # keep out\r\nCrawl-delay: 10\r\n";

		$this->assertFalse( $this->parse( $body, '/' )['disallowed'], 'An empty Disallow matches nothing.' );
		$this->assertTrue( $this->parse( $body, '/y' )['disallowed'] );
		$this->assertSame( 'Disallow: /y', $this->parse( $body, '/y' )['matched_rule'] );
	}

	public function test_path_matching_is_case_sensitive_and_agent_matching_is_not() {
		$body = "USER-AGENT: GOOGLEBOT\nDisallow: /Admin/\n";

		$this->assertTrue( $this->parse( $body, '/Admin/' )['disallowed'] );
		$this->assertFalse( $this->parse( $body, '/admin/' )['disallowed'] );
		$this->assertSame( 'googlebot', $this->parse( $body, '/' )['agent'] );
	}

	public function test_rules_before_any_user_agent_line_are_ignored() {
		$body = "Disallow: /\nUser-agent: *\nDisallow: /only/\n";

		$this->assertFalse( $this->parse( $body, '/' )['disallowed'] );
		$this->assertTrue( $this->parse( $body, '/only/x' )['disallowed'] );
	}

	/* ------------------------------------------------------------------
	 * Sitemap discovery through crawl()
	 * ---------------------------------------------------------------- */

	protected function mock_page_ok( $url ) {
		$this->eseot_route( 'HEAD', $url, $this->eseot_http_response( 200, array( 'Content-Type' => 'text/html' ) ) );
	}

	protected function mock_robots( $body ) {
		$this->eseot_route( 'GET', home_url() . '/robots.txt', $this->eseot_http_response( 200, array( 'Content-Type' => 'text/plain' ), $body ) );
	}

	protected function mock_sitemap( $url, $code = 200, $type = 'application/xml' ) {
		$this->eseot_route( 'HEAD', $url, $this->eseot_http_response( $code, array( 'Content-Type' => $type ) ) );
	}

	public function test_robots_sitemap_lines_are_probed_first_and_discovery_stops_at_the_first_found() {
		$home = home_url();
		$page = $home . '/page/';
		$this->mock_page_ok( $page );
		$this->mock_robots( "User-agent: *\nDisallow: /wp-admin/\nSitemap: {$home}/missing.xml\nSitemap: {$home}/present.xml\n" );
		$this->mock_sitemap( $home . '/missing.xml', 404, 'text/html' );
		$this->mock_sitemap( $home . '/present.xml', 200 );

		$data = $this->audit()->crawl( $page );

		$this->assertSame( array( $home . '/missing.xml', $home . '/present.xml' ), $data['robots_txt']['sitemaps'] );
		$this->assertCount( 2, $data['sitemaps'] );
		$this->assertSame( array( 'url' => $home . '/missing.xml', 'status' => 404, 'found' => false, 'source' => 'robots', 'final_url' => $home . '/missing.xml' ), $data['sitemaps'][0] );
		$this->assertSame( array( 'url' => $home . '/present.xml', 'status' => 200, 'found' => true, 'source' => 'robots', 'final_url' => $home . '/present.xml' ), $data['sitemaps'][1] );
		$this->assertSame( 0, $this->eseot_http_calls( 'wp-sitemap.xml' ), 'Discovery stops once a sitemap is found.' );
		$this->assertSame( 0, $this->eseot_http_calls( '/sitemap.xml' ) );
	}

	public function test_discovery_order_is_wp_sitemap_then_sitemap_xml_then_sitemap_index() {
		$home = home_url();
		$page = $home . '/page/';
		$this->mock_page_ok( $page );
		// No robots.txt at all, /wp-sitemap.xml missing, /sitemap.xml present.
		$this->mock_sitemap( $home . '/sitemap.xml', 200 );

		$data = $this->audit()->crawl( $page );

		$this->assertSame( 404, $data['robots_txt']['status'] );
		$this->assertFalse( $data['robots_txt']['found'] );
		$this->assertSame( array(), $data['robots_txt']['sitemaps'] );

		$this->assertSame( array( 'wp-sitemap', 'guess' ), wp_list_pluck( $data['sitemaps'], 'source' ) );
		$this->assertSame( array( false, true ), wp_list_pluck( $data['sitemaps'], 'found' ) );
		$this->assertSame( $home . '/sitemap.xml', $data['sitemaps'][1]['url'] );

		$this->assertLessThan( $this->eseot_http_first( '/sitemap.xml' ), $this->eseot_http_first( '/wp-sitemap.xml' ), '/wp-sitemap.xml is probed before /sitemap.xml.' );
		$this->assertSame( 0, $this->eseot_http_calls( 'sitemap_index.xml' ), '/sitemap_index.xml is not probed once /sitemap.xml is found.' );
	}

	public function test_discovery_reports_every_miss_when_nothing_is_found() {
		$home = home_url();
		$page = $home . '/page/';
		$this->mock_page_ok( $page );

		$data = $this->audit()->crawl( $page );

		$this->assertSame(
			array( $home . '/wp-sitemap.xml', $home . '/sitemap.xml', $home . '/sitemap_index.xml' ),
			wp_list_pluck( $data['sitemaps'], 'url' )
		);
		$this->assertSame( array( false, false, false ), wp_list_pluck( $data['sitemaps'], 'found' ) );
		$this->assertSame( array( 'wp-sitemap', 'guess', 'guess' ), wp_list_pluck( $data['sitemaps'], 'source' ) );
	}

	public function test_an_html_page_at_a_sitemap_url_does_not_count_as_found() {
		$home = home_url();
		$page = $home . '/page/';
		$this->mock_page_ok( $page );
		$this->mock_sitemap( $home . '/sitemap.xml', 200, 'text/html; charset=UTF-8' );
		$this->mock_sitemap( $home . '/sitemap_index.xml', 200, 'text/xml' );

		$data = $this->audit()->crawl( $page );

		$this->assertSame( array( false, false, true ), wp_list_pluck( $data['sitemaps'], 'found' ) );
		$this->assertSame( $home . '/sitemap_index.xml', $data['sitemaps'][2]['url'] );
	}

	public function test_robots_verdict_uses_the_audited_path_and_query() {
		$home = home_url();
		$this->mock_page_ok( $home . '/?p=5&preview=true' );
		$this->mock_page_ok( $home . '/?p=5' );
		$this->mock_robots( "User-agent: *\nDisallow: /*preview=true\n" );

		$preview = $this->audit()->crawl( $home . '/?p=5&preview=true' );
		$this->assertTrue( $preview['robots_txt']['disallowed_for_path'] );
		$this->assertSame( 'Disallow: /*preview=true', $preview['robots_txt']['matched_rule'] );

		$plain = $this->audit()->crawl( $home . '/?p=5' );
		$this->assertFalse( $plain['robots_txt']['disallowed_for_path'] );
		$this->assertNull( $plain['robots_txt']['matched_rule'] );
	}

	public function test_robots_and_sitemap_probes_are_cached_per_origin() {
		$home = home_url();
		$this->mock_page_ok( $home . '/one/' );
		$this->mock_page_ok( $home . '/two/' );
		$this->mock_robots( "User-agent: *\nDisallow: /wp-admin/\nSitemap: {$home}/wp-sitemap.xml\n" );
		$this->mock_sitemap( $home . '/wp-sitemap.xml' );

		$this->audit()->crawl( $home . '/one/' );
		$this->audit()->crawl( $home . '/two/' );

		$this->assertSame( 1, $this->eseot_http_calls( '/robots.txt' ), 'robots.txt is fetched once per origin within the cache window.' );
		$this->assertSame( 1, $this->eseot_http_calls( '/wp-sitemap.xml' ) );
		$this->assertSame( 1, $this->eseot_http_calls( 'HEAD ' . $home . '/one/' ) );
		$this->assertSame( 1, $this->eseot_http_calls( 'HEAD ' . $home . '/two/' ) );

		$this->audit()->crawl( $home . '/one/', true );
		$this->assertSame( 2, $this->eseot_http_calls( '/robots.txt' ), 'fresh=1 refreshes the origin probes too.' );
	}
}
