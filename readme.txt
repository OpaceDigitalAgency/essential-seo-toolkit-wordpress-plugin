=== Opace Essential SEO Toolkit ===
Contributors: opacewebdesign
Tags: seo audit, seo plugin, seo tool, seo analysis, on page seo
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 2.0.0
Requires PHP: 7.4
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Donate link: https://opace.agency/get-in-touch/

Run a private WordPress SEO audit with 14 on-page checks, accessibility, Web Vitals, crawl signals and page-aware SEO tools.

== Description ==

Compatibility: version 2.0.0 was tested on WordPress 7.1 and PHP 8.3. Minimums: WordPress 6.0 and PHP 7.4.

Essential SEO Toolkit is a private WordPress SEO audit and SEO tool organiser for posts, pages, custom post types, the home page and archives. It turns the page you are editing into a practical on-page SEO review with clear Pass, Review and Info findings.

The audit runs in your browser against your own site. Page content and full results are not sent to Opace or another audit service.

= New in 2.0: rebuilt from a tool launcher into a private page-audit system =

Version 2.0 is a major rebuild. Version 1 worked mainly as a page-aware launcher for external SEO tools: a useful alternative to keeping the same SEO bookmarks in your browser.

Version 2 keeps that saved-tool workflow and adds a complete local SEO audit inside WordPress. It checks the page itself, explains what needs attention, runs accessibility and Web Vitals engines, inspects crawl signals and puts recent results wherever editors work. The interface, branding, defaults, settings, help and privacy controls have also been rebuilt.

= WordPress SEO audit with 14 on-page checks =

Open a post and select Run audit. The page appears in a viewer inside the admin while the audit runs, then the viewer collapses when the results are ready. The report uses three plain-language states rather than an unexplained SEO score:

* Pass: the common check found nothing to change.
* Review: the result deserves a closer look.
* Info: a descriptive count or observation with no fixed target.

The on-page SEO audit covers:

* Search appearance: page title, meta description and H1 heading.
* Indexing and delivery: canonical URL, indexing directive and HTTPS.
* Content and accessibility: document language, mobile viewport, image alt attributes, heading order and visible page content.
* Markup and navigation: structured data, social metadata and internal, external and nofollow links.

Overview prioritises the findings that need attention. All details explains why each check matters and what to do next. Insights include a search-result preview, social preview, page structure, top terms and phrases. Copy summary creates a plain-text SEO audit report for sharing or saving elsewhere.

= Accessibility and Web Vitals SEO tools =

Two bundled, switchable engines add local evidence to the SEO analysis:

* axe-core reports automated accessibility rule violations and affected elements.
* Web Vitals captures LCP, CLS, INP, FCP and TTFB from that audit visit.

These are current-visit diagnostics, not PageSpeed Insights, CrUX field data or ranking evidence. The page is measured in an admin viewer at desktop width, so paint and layout-shift timings can differ from a real visit. Automated accessibility findings still need human review.

= Technical SEO analysis and crawl signals =

The Crawl tab reports the page status, redirects and response headers. It reads the applicable robots.txt rule and discovers sitemaps from robots.txt, WordPress core and common sitemap locations. Your WordPress site makes these requests to itself and caches the response for 60 seconds.

= An SEO toolkit across WordPress =

The same SEO audit is available throughout the WordPress admin:

* A compact audit card below the block or classic editor expands when an audit runs.
* Essential SEO Toolkit → Page audit checks any address on the same site, including the home page, archives and pages built by other plugins.
* Posts, Pages and enabled custom post type lists show a sortable SEO audit column, Audit page and SEO tools row actions, and a sequential Run page audit bulk action with a results table.
* The WordPress admin bar provides Audit this page and page-aware saved tools.
* A dashboard widget lists the five most recent audits and includes Audit home page.
* Essential SEO Toolkit → Help documents every check, engine, setting and troubleshooting route, with contextual help on the main screens.
* Settings control saved sources, categories, supported post types, automatic auditing, local engines and list-table columns.

= Page-aware SEO bookmarks and saved SEO tools =

Saved tools work like SEO bookmarks that already know the current page address. Findings link to a relevant deeper check, while the Saved tools tab, editor launcher and admin bar list every link resolved for the page.

Six free SEO tools ship by default in four categories:

* Google PageSpeed Insights.
* Google Rich Results Test.
* Schema Markup Validator.
* WAVE Accessibility Evaluation.
* Security Headers.
* Google Admin Toolbox Dig.

Add, edit or remove your own SEO tool links under Essential SEO Toolkit → Settings. URL templates can use `[%url%]`, `[%url_encoded%]`, `[%host%]`, `[%host_encoded%]`, `[%scheme%]` and `[%path%]`. The toolkit previews and validates each template before saving it.

= Private SEO analysis by design =

Nothing is loaded for ordinary visitors. The audit engines run only when a logged-in editor audits a page. Crawl requests go from your server back to your own site. Full reports are not stored; only the counts, leading Review labels, captured metrics and audit time are kept per page for the list column and dashboard widget.

Deeper checks open independent third-party websites only when you select a saved tool. That service then receives the page address under its own terms and privacy policy. Opace is not affiliated with those services and does not promise rankings or traffic.

= Upgrading from Essential SEO Toolkit 1.x =

Version 2.0 replaces the old default tool list with the six current tools and renames the default categories. A one-time migration keeps every tool you added or edited yourself and removes only unchanged legacy defaults. Review Essential SEO Toolkit → Settings after updating to choose the post types, audit behaviour and engines you want.

= Related Opace SEO tools, source code and support =

* [Essential SEO Toolkit for Chrome](https://chromewebstore.google.com/detail/icagkiolfkmndbggheneeamfbnobcdma) runs the same local SEO audit on any public web page.
* Browse the [WordPress plugin source on GitHub](https://github.com/OpaceDigitalAgency/essential-seo-toolkit-wordpress-plugin) and the [Chrome extension source](https://github.com/OpaceDigitalAgency/essential-seo-toolkit-chrome-extension).
* Browse [Opace tool suites](https://opace.agency/tools/suite/), [Opace browser tools](https://opace.agency/tools/browser/) and [Opace SEO services](https://opace.agency/services/seo/).
* Find open-source work from [Opace Digital Agency on GitHub](https://github.com/OpaceDigitalAgency).
* Ask for help in the [Essential SEO Toolkit support forum](https://wordpress.org/support/plugin/opace-essential-seo-toolkit/) or [leave a WordPress.org review](https://wordpress.org/support/plugin/opace-essential-seo-toolkit/reviews/#new-post).

= Third-party libraries =

Two open-source libraries are bundled unmodified, with licence files in the vendor folder and notices in third-party-notices.txt.

* [axe-core 4.13.0](https://github.com/dequelabs/axe-core) by Deque Systems, Mozilla Public License 2.0.
* [web-vitals 6.2.1](https://github.com/GoogleChrome/web-vitals) by Google, Apache License 2.0.

= About Opace =

Essential SEO Toolkit is built and maintained by [Opace](https://opace.agency/), a UK agency offering [SEO](https://opace.agency/services/seo/) and [WordPress development](https://opace.agency/services/web-design/wordpress-development/). See the [privacy policy](https://opace.agency/privacy-policy/) for how Opace handles data.

== Installation ==

1. Go to Plugins → Add New Plugin, search for “Opace Essential SEO Toolkit”, select Install Now, then Activate.
2. Open Essential SEO Toolkit → Settings. Choose the post types that should show the editor audit and select the audit engines and behaviour.
3. Edit a post and select Run audit in the Essential SEO Toolkit page audit card.
4. To audit the home page or another same-site URL, open Essential SEO Toolkit → Page audit.

== Frequently Asked Questions ==

= Is this SEO toolkit free? =

Yes. The plugin is free and GPL licensed. The six default saved tools can be used without an account at the time of writing.

= Does the SEO audit send page data anywhere? =

No. The audit runs in your browser against your own site. Only a short per-page summary is stored in WordPress. A third-party service sees the address only when you choose one of the deeper-check links.

= Can it audit drafts and private posts? =

Yes. Drafts, pending posts and private posts use their WordPress preview address, so save the post first.

= Which WordPress pages can I audit? =

The editor audit supports enabled public post types, including custom post types. Use Essential SEO Toolkit → Page audit for the home page, archives and same-site pages built by other plugins.

= What happens if my site blocks the audit viewer? =

The toolkit offers a new-tab fallback. The page reports its local findings back to the WordPress admin; if it cannot report, the panel explains the failure and still shows any available crawl result.

= Why do results differ from Chrome or PageSpeed Insights? =

The WordPress SEO audit runs while you are logged in and measures a page loaded inside the admin. Timings, layout shift and link counts can therefore differ from a clean public visit. PageSpeed Insights uses different lab and field data.

= Why is INP unavailable? =

Interaction to Next Paint needs a real click or key press. An automated page load may have no interaction to measure.

= Will the SEO plugin slow down visitors? =

No. Audit scripts and engines are loaded only for an authorised, logged-in audit request.

= Can I add my own SEO bookmarks or tools? =

Yes. Add a name, URL template and category on the Sources tab. Your tool then appears in the editor launcher, audit report, admin bar and Saved tools tab.

= Why did the default tools change after updating? =

Version 2.0 retires unchanged legacy defaults and adds the six current tools. Tools you created or edited are kept.

== Screenshots ==

1. WordPress SEO audit Overview with Pass, Review and Info findings and prioritised actions.
2. Detailed on-page SEO analysis explaining why each check matters and what to do.
3. Local accessibility and Web Vitals SEO tools with findings from the audit visit.
4. Page-aware SEO bookmarks and saved tools resolved for the current WordPress page.
5. Posts list with sortable SEO audit results, row actions and the bulk-audit entry point.
6. SEO toolkit settings for sources, categories, post types, audit engines and list columns.

== Changelog ==

= 2.0.0 =
* Major rebuild from an external SEO tool launcher into a private WordPress page-audit system.
* Added 14 on-page checks with Pass, Review and Info results, prioritised actions, detailed guidance, previews, page structure and term insights.
* Added bundled axe-core accessibility checks and web-vitals current-visit metrics, with settings to switch either engine off.
* Added crawl analysis for status, redirects, response headers, robots.txt rules and sitemap discovery.
* Added same-site Page audit, post-list results and row actions, sequential bulk auditing, admin-bar tools, a recent-audits dashboard widget and in-plugin Help.
* Rebuilt the admin interface, settings, compact editor card, branding, icons, banners and screenshot set.
* Added six Chrome-aligned default tools, six URL placeholders and a migration that keeps custom or edited links.
* Added capability, nonce, sanitisation and escaping checks; audit code never runs for ordinary visitors.
* Added full uninstall cleanup. Requires WordPress 6.0 and PHP 7.4; tested with WordPress 7.1 on PHP 8.3.

= 1.2.6 =
* Updated the external tool set, added source search and filtering, and improved input and output sanitisation.

= 1.2.5 =
* Added the Technical SEO category and three tools.

= 1.1.2 =
* Corrected a source URL.

= 1.1.1 =
* Fixed importing updated and new sources.

= 1.1.0 =
* Added User Experience tools, settings fixes and English (UK) translation support.

= 1.0.1 =
* Minor activation and meta-box fixes.

= 1.0 =
* Initial release.

== Upgrade Notice ==

= 2.0.0 =
Major rebuild: adds a private 14-check WordPress SEO audit, accessibility, Web Vitals, crawl analysis and admin-wide results while keeping your custom SEO tools.
