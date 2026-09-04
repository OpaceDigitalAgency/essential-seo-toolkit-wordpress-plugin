<?php
/**
 * Exact 1.2.6 default option values.
 *
 * Copied verbatim from trunk/includes/class-settings-page.php
 * (update_categories() and update_sources()) at SVN r2894924 on
 * 3 September 2026, before the 2.0.0 rewrite replaced that file.
 * Category names were stored with HTML entities in 1.2.6; keep them
 * that way here so the migration's entity-decoding is exercised.
 *
 * @return array{categories: array<int,string>, sources: array<int,array{name:string,url:string,cat:int}>, post_types: array<string,int>, version: string}
 */
return array(
	'version'    => '1.2.6',
	'post_types' => array(
		'post'       => 1,
		'page'       => 1,
		'attachment' => 0,
	),
	'categories' => array(
		0 => 'SEO &amp; Traffic Analysis',
		1 => 'Speed &amp; Performance Analysis',
		2 => 'Website &amp; SEO Auditing',
		3 => 'Backlink Analysis',
		4 => 'Social Signals',
		5 => 'User Experience',
		6 => 'Technical SEO',
	),
	'sources'    => array(
		array(
			'name' => 'SEMRush',
			'url'  => 'https://www.semrush.com/info/[%scheme%][%host%]',
			'cat'  => 0,
		),
		array(
			'name' => 'Ubersuggest Traffic Analyzer',
			'url'  => 'https://app.neilpatel.com/en/traffic_analyzer/overview?lang=en&[%host%]',
			'cat'  => 0,
		),
		array(
			'name' => 'SpyFu',
			'url'  => 'https://www.spyfu.com/overview/domain?query=[%host%][%path%]',
			'cat'  => 0,
		),
		array(
			'name' => 'Google Trends',
			'url'  => 'https://trends.google.com/trends/explore?[%host%]',
			'cat'  => 0,
		),
		array(
			'name' => 'Pingdom Tools',
			'url'  => 'https://tools.pingdom.com/',
			'cat'  => 1,
		),
		array(
			'name' => 'GT Metrix',
			'url'  => 'https://gtmetrix.com/?url=[%scheme%][%host%][%path%]',
			'cat'  => 1,
		),
		array(
			'name' => 'Google PageSpeed Insights',
			'url'  => 'https://developers.google.com/speed/pagespeed/insights/?url=[%scheme%][%host%][%path%]',
			'cat'  => 1,
		),
		array(
			'name' => 'WooRank',
			'url'  => 'https://www.woorank.com/en/www/[%host%]',
			'cat'  => 2,
		),
		array(
			'name' => 'Nibbler',
			'url'  => 'https://nibbler.insites.com/en/progress/[%host%]',
			'cat'  => 2,
		),
		array(
			'name' => 'BrowseSEO',
			'url'  => 'https://www.browseo.net/?url=[%scheme%][%host%][%path%]',
			'cat'  => 2,
		),
		array(
			'name' => 'Wordtracker',
			'url'  => 'https://www.wordtracker.com/inspect?query=[%host%][%path%]',
			'cat'  => 2,
		),
		array(
			'name' => 'Majestic',
			'url'  => 'https://majestic.com/reports/site-explorer?folder=&q=[%scheme%][%host%][%path%]&IndexDataSource=F',
			'cat'  => 3,
		),
		array(
			'name' => 'Moz Link Explorer',
			'url'  => 'https://analytics.moz.com/pro/link-explorer/overview?site=[%scheme%][%host%]&target=domain',
			'cat'  => 3,
		),
		array(
			'name' => 'Share Score',
			'url'  => 'https://www.sharescore.com/?url=[%scheme%][%host%][%path%]',
			'cat'  => 4,
		),
		array(
			'name' => 'Social Searcher',
			'url'  => 'https://www.social-searcher.com/social-buzz/?q5=[%host%]',
			'cat'  => 4,
		),
		array(
			'name' => 'Google Mobile-Friendly Test',
			'url'  => 'https://search.google.com/test/mobile-friendly?url=[%scheme%][%host%][%path%]',
			'cat'  => 5,
		),
		array(
			'name' => 'Responsinator',
			'url'  => 'https://www.responsinator.com/?url=[%scheme%][%host%][%path%]',
			'cat'  => 5,
		),
		array(
			'name' => 'XML Sitemap Generator',
			'url'  => 'https://www.xml-sitemaps.com/',
			'cat'  => 6,
		),
		array(
			'name' => 'Copyscape',
			'url'  => 'https://www.copyscape.com/?q=[%scheme%][%host%][%path%]',
			'cat'  => 6,
		),
		array(
			'name' => 'Siteliner',
			'url'  => 'https://www.siteliner.com/[%host%]?siteliner=site-dashboard&siteliner-sort=scan_time&siteliner-from=1&siteliner-message=',
			'cat'  => 6,
		),
		array(
			'name' => 'Google Rich Results Tool',
			'url'  => 'https://search.google.com/test/rich-results?url=[%scheme%][%host%][%path%]',
			'cat'  => 6,
		),
		array(
			'name' => 'Open Graph Meta Tag Checker',
			'url'  => 'https://www.opengraph.xyz/url/[%scheme%][%host%][%path%]',
			'cat'  => 6,
		),
	),
);
