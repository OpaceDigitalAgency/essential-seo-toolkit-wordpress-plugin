<?php
/**
 * Essential SEO Toolkit → Help: a single reference page plus contextual help tabs.
 *
 * @package Opace_Essential_SEO_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * admin.php?page=opace-eseot-help, the third submenu of the top-level menu.
 *
 * Explains what the toolkit does, where each surface lives, how to read an
 * audit, what the engines and crawl rows mean, saved tools and placeholders,
 * settings, privacy and troubleshooting. Also adds the WordPress "Help" tabs
 * on the Page audit, Settings and Help screens.
 *
 * Everything here is static text: nothing is read from the request and
 * nothing is written. Anyone who can edit_posts may open it, matching the
 * Page audit screen.
 */
final class Opace_ESEOT_Help {

	/**
	 * Submenu slug.
	 */
	const MENU_SLUG = 'opace-eseot-help';

	const STYLE_HANDLE = 'opace-eseot-help';

	/**
	 * Support forum for the plugin on WordPress.org.
	 */
	const SUPPORT_URL = 'https://wordpress.org/support/plugin/opace-essential-seo-toolkit/';

	/**
	 * The Chrome extension this plugin mirrors.
	 */
	const CHROME_URL = 'https://chromewebstore.google.com/detail/icagkiolfkmndbggheneeamfbnobcdma';

	const AXE_URL    = 'https://github.com/dequelabs/axe-core';
	const VITALS_URL = 'https://github.com/GoogleChrome/web-vitals';

	/**
	 * Hook suffix returned by add_submenu_page().
	 *
	 * @var string
	 */
	private static string $page_hook = '';

	/**
	 * Whether init() has run.
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * Register hooks. Safe to call more than once.
	 *
	 * The submenu itself is added by Opace_ESEOT_Settings::register_menu(),
	 * which owns the order of the top-level menu.
	 */
	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;

		add_action( 'current_screen', array( __CLASS__, 'add_contextual_help' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Add "Help" under the top-level menu. Called from Opace_ESEOT_Settings::register_menu().
	 *
	 * @param string $parent_slug Top-level menu slug.
	 */
	public static function register_menu( string $parent_slug ): void {
		$hook = add_submenu_page(
			$parent_slug,
			__( 'Essential SEO Toolkit help', 'opace-essential-seo-toolkit' ),
			__( 'Help', 'opace-essential-seo-toolkit' ),
			'edit_posts',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);

		self::$page_hook = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Hook suffix of the Help screen, for targeted enqueues.
	 *
	 * WordPress builds submenu hooks as {sanitised parent menu title}_page_{slug}.
	 */
	public static function page_hook(): string {
		return '' !== self::$page_hook ? self::$page_hook : Opace_ESEOT_Settings::LEGACY_MENU_SLUG . '_page_' . self::MENU_SLUG;
	}

	/**
	 * URL of the Help screen, optionally at one section.
	 *
	 * @param string $section Section id without the prefix, for example troubleshooting.
	 * @return string
	 */
	public static function page_url( string $section = '' ): string {
		$url = add_query_arg( array( 'page' => self::MENU_SLUG ), admin_url( 'admin.php' ) );
		if ( '' !== $section ) {
			$url .= '#' . self::section_id( $section );
		}

		return $url;
	}

	/**
	 * DOM id of a section card.
	 *
	 * @param string $section Section key.
	 * @return string
	 */
	private static function section_id( string $section ): string {
		return 'opace-eseot-help-' . sanitize_key( $section );
	}

	/**
	 * Stylesheet for the Help screen only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_assets( $hook_suffix = '' ): void {
		if ( ! is_string( $hook_suffix ) || $hook_suffix !== self::page_hook() ) {
			return;
		}

		wp_enqueue_style(
			self::STYLE_HANDLE,
			plugins_url( 'assets/css/opace-eseot-help.css', OPACE_ESEOT_FILE ),
			array( 'dashicons' ),
			OPACE_ESEOT_VERSION
		);
	}

	/* ---------------------------------------------------------------------
	 * Contextual help tabs
	 * ------------------------------------------------------------------ */

	/**
	 * Screen ids that get help tabs: Page audit, Settings and Help.
	 *
	 * @return string[]
	 */
	private static function help_screen_ids(): array {
		$ids = array( self::page_hook() );

		if ( class_exists( 'Opace_ESEOT_Plugin' ) ) {
			$plugin = Opace_ESEOT_Plugin::instance();
			$ids[]  = $plugin->audit_page()->page_hook();
			$ids[]  = $plugin->settings()->page_hook();
		} else {
			$ids[] = 'toplevel_page_' . Opace_ESEOT_Settings::PARENT_SLUG;
			$ids[] = Opace_ESEOT_Settings::LEGACY_MENU_SLUG . '_page_' . Opace_ESEOT_Settings::MENU_SLUG;
		}

		return $ids;
	}

	/**
	 * Add "Overview" and "Privacy" tabs plus a sidebar. Hooked to current_screen.
	 *
	 * @param WP_Screen|null $screen Screen being set up.
	 */
	public static function add_contextual_help( $screen = null ): void {
		if ( ! $screen instanceof WP_Screen || ! in_array( $screen->id, self::help_screen_ids(), true ) ) {
			return;
		}

		$overview = self::overview_tab_content( $screen->id );

		$screen->add_help_tab(
			array(
				'id'      => 'opace-eseot-help-overview',
				'title'   => __( 'Overview', 'opace-essential-seo-toolkit' ),
				'content' => $overview,
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'opace-eseot-help-privacy',
				'title'   => __( 'Privacy', 'opace-essential-seo-toolkit' ),
				'content' => '<p>' . esc_html__( 'Nothing about your pages leaves this site. The audit runs in your own browser against a page served by your own site, the two engines are bundled with the plugin, and the crawl check is your server requesting its own pages. A third-party service only sees an address when you click a deeper check.', 'opace-essential-seo-toolkit' ) . '</p>'
					. '<p>' . esc_html__( 'Settings live in WordPress options. The only thing kept per page is a short summary of the counts, top Review labels and metrics; full results are never stored. Deleting the plugin removes all of it.', 'opace-essential-seo-toolkit' ) . '</p>',
			)
		);

		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__( 'More help', 'opace-essential-seo-toolkit' ) . '</strong></p>'
			. '<p>' . self::link( self::page_url(), __( 'Help page', 'opace-essential-seo-toolkit' ) ) . '</p>'
			. '<p>' . self::link( self::SUPPORT_URL, __( 'Support forum', 'opace-essential-seo-toolkit' ), true ) . '</p>'
		);
	}

	/**
	 * Overview tab HTML for one of the three screens.
	 *
	 * @param string $screen_id Screen id.
	 * @return string
	 */
	private static function overview_tab_content( string $screen_id ): string {
		if ( $screen_id === self::page_hook() ) {
			return '<p>' . esc_html__( 'This page explains what the toolkit does, where to find it, how to read an audit, what the engines and crawl rows mean, saved tools and placeholders, settings, privacy and how to fix common problems. Use the list at the top to jump to a section.', 'opace-essential-seo-toolkit' ) . '</p>';
		}

		if ( class_exists( 'Opace_ESEOT_Plugin' ) && $screen_id === Opace_ESEOT_Plugin::instance()->settings()->page_hook() ) {
			return '<p>' . esc_html__( 'Sources are the saved tool links: write each URL as a template with a placeholder where the page address belongs. Categories group them. Post types choose which editors show the launcher and the audit panel. Audit controls whether audits start automatically, whether the accessibility and Web Vitals engines run, and which list tables show the SEO audit column.', 'opace-essential-seo-toolkit' ) . '</p>'
				. '<p>' . wp_kses_post(
					sprintf(
						/* translators: %s: link to the Help page. */
						__( 'Placeholders and the migration from 1.x are explained on the %s.', 'opace-essential-seo-toolkit' ),
						self::link( self::page_url( 'tools' ), __( 'Help page', 'opace-essential-seo-toolkit' ) )
					)
				) . '</p>';
		}

		return '<p>' . esc_html__( 'Enter any address on this site and click Audit. Published posts are audited at their permalink; drafts, pending and private posts through their preview link. Posts sent here by the Run page audit bulk action are audited one after another and summarised in a table.', 'opace-essential-seo-toolkit' ) . '</p>'
			. '<p>' . esc_html__( 'The page loads in a hidden frame inside this screen and is checked by your own browser. Pass found nothing to change, Review deserves a look, and Info is a descriptive count with no target. The checks are review prompts, not ranking guarantees.', 'opace-essential-seo-toolkit' ) . '</p>';
	}

	/* ---------------------------------------------------------------------
	 * Page
	 * ------------------------------------------------------------------ */

	/**
	 * Section keys and titles, in page order.
	 *
	 * @return array<string, string>
	 */
	private static function sections(): array {
		return array(
			'what'            => __( 'What the toolkit does', 'opace-essential-seo-toolkit' ),
			'where'           => __( 'Where to find it', 'opace-essential-seo-toolkit' ),
			'results'         => __( 'Reading the results', 'opace-essential-seo-toolkit' ),
			'engines'         => __( 'Local engines', 'opace-essential-seo-toolkit' ),
			'crawl'           => __( 'Crawl signals', 'opace-essential-seo-toolkit' ),
			'tools'           => __( 'Saved tools and placeholders', 'opace-essential-seo-toolkit' ),
			'settings'        => __( 'Settings explained', 'opace-essential-seo-toolkit' ),
			'privacy'         => __( 'Privacy and data', 'opace-essential-seo-toolkit' ),
			'troubleshooting' => __( 'Troubleshooting', 'opace-essential-seo-toolkit' ),
		);
	}

	/**
	 * Render the page.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'opace-essential-seo-toolkit' ) );
		}

		$mark     = plugins_url( 'assets/images/opace-eseot-mark.svg', OPACE_ESEOT_FILE );
		$sections = self::sections();
		?>
		<div class="wrap opace-eseot-help">
			<h1 class="opace-eseot-help__title">
				<img class="opace-eseot-help__mark" src="<?php echo esc_url( $mark ); ?>" alt="" width="32" height="32">
				<?php esc_html_e( 'Essential SEO Toolkit help', 'opace-essential-seo-toolkit' ); ?>
			</h1>
			<p class="opace-eseot-help__lead"><?php esc_html_e( 'How the page audit works, what each finding means and where everything lives in WordPress.', 'opace-essential-seo-toolkit' ); ?></p>

			<nav class="opace-eseot-help__toc" aria-labelledby="opace-eseot-help-toc-title">
				<h2 id="opace-eseot-help-toc-title"><?php esc_html_e( 'On this page', 'opace-essential-seo-toolkit' ); ?></h2>
				<ol>
					<?php foreach ( $sections as $key => $title ) : ?>
						<li><a href="<?php echo esc_attr( '#' . self::section_id( $key ) ); ?>"><?php echo esc_html( $title ); ?></a></li>
					<?php endforeach; ?>
				</ol>
			</nav>

			<?php
			foreach ( $sections as $key => $title ) {
				self::card_open( $key, $title );
				call_user_func( array( __CLASS__, 'render_section_' . $key ) );
				self::card_close();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Open a section card.
	 *
	 * @param string $key   Section key.
	 * @param string $title Section title.
	 */
	private static function card_open( string $key, string $title ): void {
		?>
		<section class="opace-eseot-help__card" id="<?php echo esc_attr( self::section_id( $key ) ); ?>" aria-labelledby="<?php echo esc_attr( self::section_id( $key ) . '-title' ); ?>">
			<h2 class="opace-eseot-help__heading" id="<?php echo esc_attr( self::section_id( $key ) . '-title' ); ?>"><?php echo esc_html( $title ); ?></h2>
		<?php
	}

	/**
	 * Close a section card with a "Back to top" link.
	 */
	private static function card_close(): void {
		?>
			<p class="opace-eseot-help__top"><a href="#opace-eseot-help-toc-title"><?php esc_html_e( 'Back to top', 'opace-essential-seo-toolkit' ); ?></a></p>
		</section>
		<?php
	}

	/**
	 * Escaped anchor markup for use inside wp_kses_post() output.
	 *
	 * @param string $url      Destination.
	 * @param string $text     Link text.
	 * @param bool   $external Open in a new tab with rel="noopener noreferrer".
	 * @return string
	 */
	private static function link( string $url, string $text, bool $external = false ): string {
		return sprintf(
			'<a href="%s"%s>%s</a>',
			esc_url( $url ),
			$external ? ' target="_blank" rel="noopener noreferrer"' : '',
			esc_html( $text )
		);
	}

	/**
	 * URL of one settings tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	private static function settings_url( string $tab = '' ): string {
		$settings = class_exists( 'Opace_ESEOT_Plugin' ) ? Opace_ESEOT_Plugin::instance()->settings() : new Opace_ESEOT_Settings();

		return $settings->page_url( $tab );
	}

	/**
	 * URL of the Page audit screen.
	 *
	 * @return string
	 */
	private static function audit_url(): string {
		return Opace_ESEOT_Audit_Page::page_url();
	}

	/**
	 * Print a paragraph of plain text.
	 *
	 * @param string $text Already-translated text.
	 */
	private static function p( string $text ): void {
		echo '<p>' . esc_html( $text ) . '</p>';
	}

	/**
	 * Print a paragraph that contains inline links built with self::link().
	 *
	 * @param string $html Translated text with the links already substituted.
	 */
	private static function p_html( string $html ): void {
		echo '<p>' . wp_kses_post( $html ) . '</p>';
	}

	/**
	 * Print a two-column reference table inside a scrolling wrapper.
	 *
	 * @param string[]                $headings Column headings.
	 * @param array<int, string[]>    $rows     Rows of plain-text cells.
	 * @param string                  $class    Extra class for the table.
	 */
	private static function table( array $headings, array $rows, string $class = '' ): void {
		?>
		<div class="opace-eseot-help__table-wrap">
			<table class="opace-eseot-help__table widefat striped <?php echo esc_attr( $class ); ?>">
				<thead>
					<tr>
						<?php foreach ( $headings as $heading ) : ?>
							<th scope="col"><?php echo esc_html( $heading ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<?php foreach ( array_values( $row ) as $index => $cell ) : ?>
								<?php if ( 0 === $index ) : ?>
									<th scope="row"><?php echo esc_html( $cell ); ?></th>
								<?php else : ?>
									<td><?php echo esc_html( $cell ); ?></td>
								<?php endif; ?>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Print a definition-style list: bold term, then a line of text.
	 *
	 * @param array<int, array{0: string, 1: string}> $items Term and description pairs.
	 */
	private static function terms( array $items ): void {
		echo '<dl class="opace-eseot-help__terms">';
		foreach ( $items as $item ) {
			echo '<dt>' . esc_html( $item[0] ) . '</dt><dd>' . esc_html( $item[1] ) . '</dd>';
		}
		echo '</dl>';
	}

	/* ---------------------------------------------------------------------
	 * Sections
	 * ------------------------------------------------------------------ */

	/**
	 * 1. What the toolkit does.
	 */
	private static function render_section_what(): void {
		self::p( __( 'Essential SEO Toolkit turns the post you are editing into a private, practical on-page review. Click Run audit and the page loads in a hidden frame inside the admin, is checked by your own browser and reports Pass, Review and Info findings instead of an unexplained score. Two bundled engines add accessibility findings and Web Vitals for that load, and a crawl check reports what your server sends back for the address.', 'opace-essential-seo-toolkit' ) );
		self::p( __( 'Alongside the audit, the toolkit keeps a list of saved SEO tools and opens each one for the page you are working on. Nothing about your pages leaves the site: the checks run locally, the engines ship with the plugin, and a third-party service only sees an address when you click a deeper check. The checks are review prompts, not ranking guarantees; context and human judgement still matter.', 'opace-essential-seo-toolkit' ) );
	}

	/**
	 * 2. Where to find it.
	 */
	private static function render_section_where(): void {
		$audit    = self::link( self::audit_url(), __( 'Page audit', 'opace-essential-seo-toolkit' ) );
		$posts    = self::link( admin_url( 'edit.php' ), __( 'Posts', 'opace-essential-seo-toolkit' ) );
		$pages    = self::link( admin_url( 'edit.php?post_type=page' ), __( 'Pages', 'opace-essential-seo-toolkit' ) );
		$dash     = self::link( admin_url( 'index.php' ), __( 'Dashboard', 'opace-essential-seo-toolkit' ) );
		$settings = self::link( self::settings_url( 'post-types' ), __( 'Post types', 'opace-essential-seo-toolkit' ) );
		?>
		<ul class="opace-eseot-help__list">
			<li>
				<strong><?php esc_html_e( 'Editor panel', 'opace-essential-seo-toolkit' ); ?></strong>
				<?php
				self::p_html(
					sprintf(
						/* translators: %s: link to the Post types settings tab. */
						__( 'A compact box below the block or classic editor on every post type ticked under %s. Click Run audit; the results appear in Overview, All details, Local engines, Crawl and Saved tools tabs.', 'opace-essential-seo-toolkit' ),
						$settings
					)
				);
				?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Page audit screen', 'opace-essential-seo-toolkit' ); ?></strong>
				<?php
				self::p_html(
					sprintf(
						/* translators: %s: link to the Page audit screen. */
						__( '%s audits any address on this site, including the home page and archives; drafts are audited through their preview link, and a bulk selection from a list table is audited one page after another.', 'opace-essential-seo-toolkit' ),
						$audit
					)
				);
				?>
			</li>
			<li>
				<strong><?php esc_html_e( 'List tables', 'opace-essential-seo-toolkit' ); ?></strong>
				<?php
				self::p_html(
					sprintf(
						/* translators: 1: link to the Posts list, 2: link to the Pages list. */
						__( 'The %1$s and %2$s lists (and enabled custom post types) show an SEO audit column with the last counts, Audit page and SEO tools row actions, and a Run page audit bulk action.', 'opace-essential-seo-toolkit' ),
						$posts,
						$pages
					)
				);
				?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Admin bar', 'opace-essential-seo-toolkit' ); ?></strong>
				<?php self::p( __( 'An SEO Toolkit menu on the front end and in the admin, for users who can edit posts, with Audit this page and the saved tools resolved for the page you are viewing or editing.', 'opace-essential-seo-toolkit' ) ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Dashboard widget', 'opace-essential-seo-toolkit' ); ?></strong>
				<?php
				self::p_html(
					sprintf(
						/* translators: %s: link to the Dashboard. */
						__( 'The %s lists the last five audited pages with their counts and an Audit home page button.', 'opace-essential-seo-toolkit' ),
						$dash
					)
				);
				?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Sidebar launcher', 'opace-essential-seo-toolkit' ); ?></strong>
				<?php self::p( __( 'The small Essential SEO Toolkit box in the editor sidebar lists the saved tools for the post, with a search field and a Run a page audit link that opens the panel.', 'opace-essential-seo-toolkit' ) ); ?>
			</li>
		</ul>
		<?php
	}

	/**
	 * 3. Reading the results.
	 */
	private static function render_section_results(): void {
		self::terms(
			array(
				array( __( 'Pass', 'opace-essential-seo-toolkit' ), __( 'The check found nothing to change.', 'opace-essential-seo-toolkit' ) ),
				array( __( 'Review', 'opace-essential-seo-toolkit' ), __( 'Something deserves a look. Each Review finding says why it matters and what to do; it is not a fault by itself.', 'opace-essential-seo-toolkit' ) ),
				array( __( 'Info', 'opace-essential-seo-toolkit' ), __( 'A descriptive count with no target, such as the number of visible words or links.', 'opace-essential-seo-toolkit' ) ),
			)
		);
		self::p( __( 'Start here lists the Review findings that most need attention, up to four, with the rest under All details. Copy summary puts the counts and every finding on the clipboard as plain text, ready to paste into a ticket or a message.', 'opace-essential-seo-toolkit' ) );
		self::p( __( 'The 14 checks, in the order they appear, grouped as Search appearance, Indexing & delivery, Content & accessibility and Markup & navigation:', 'opace-essential-seo-toolkit' ) );
		self::table(
			array(
				__( 'Check', 'opace-essential-seo-toolkit' ),
				__( 'What it looks at', 'opace-essential-seo-toolkit' ),
				__( 'When it shows Review', 'opace-essential-seo-toolkit' ),
			),
			self::checks(),
			'opace-eseot-help__table--checks'
		);
	}

	/**
	 * The 14 checks: label, what it looks at, when it shows Review.
	 *
	 * @return array<int, string[]>
	 */
	private static function checks(): array {
		return array(
			array( __( 'Page title', 'opace-essential-seo-toolkit' ), __( 'The length of the document title, a strong cue for search results and browser tabs.', 'opace-essential-seo-toolkit' ), __( 'The title is missing, shorter than 15 characters or longer than 60.', 'opace-essential-seo-toolkit' ) ),
			array( __( 'Meta description', 'opace-essential-seo-toolkit' ), __( 'The length of the meta description, which search engines may use as the result snippet.', 'opace-essential-seo-toolkit' ), __( 'The description is missing, shorter than 70 characters or longer than 160.', 'opace-essential-seo-toolkit' ) ),
			array( __( 'H1 heading', 'opace-essential-seo-toolkit' ), __( 'How many H1 headings the page has.', 'opace-essential-seo-toolkit' ), __( 'There is no H1, or more than one.', 'opace-essential-seo-toolkit' ) ),
			array( __( 'Canonical URL', 'opace-essential-seo-toolkit' ), __( 'The canonical link, which consolidates duplicate URLs around the preferred version.', 'opace-essential-seo-toolkit' ), __( 'No canonical link was detected, or the value is not an absolute URL.', 'opace-essential-seo-toolkit' ) ),
			array( __( 'Indexing directive', 'opace-essential-seo-toolkit' ), __( 'The robots meta tag on the page.', 'opace-essential-seo-toolkit' ), __( 'It contains noindex, which normally keeps the page out of search results.', 'opace-essential-seo-toolkit' ) ),
			array( __( 'HTTPS', 'opace-essential-seo-toolkit' ), __( 'The scheme of the audited address.', 'opace-essential-seo-toolkit' ), __( 'The page is not using HTTPS.', 'opace-essential-seo-toolkit' ) ),
			array( __( 'Document language', 'opace-essential-seo-toolkit' ), __( 'The lang attribute on the html element, which helps assistive technology interpret the page.', 'opace-essential-seo-toolkit' ), __( 'No lang value was detected.', 'opace-essential-seo-toolkit' ) ),
			array( __( 'Mobile viewport', 'opace-essential-seo-toolkit' ), __( 'The viewport meta tag that lets pages render correctly on mobile screens.', 'opace-essential-seo-toolkit' ), __( 'No responsive viewport directive was detected.', 'opace-essential-seo-toolkit' ) ),
			array( __( 'Image alt attributes', 'opace-essential-seo-toolkit' ), __( 'Images with no alt attribute at all. An empty alt counts as present and may be correct for decoration.', 'opace-essential-seo-toolkit' ), __( 'One or more images have no alt attribute.', 'opace-essential-seo-toolkit' ) ),
			array( __( 'Heading order', 'opace-essential-seo-toolkit' ), __( 'Skipped levels in the heading outline.', 'opace-essential-seo-toolkit' ), __( 'The outline skips a level, such as H2 straight to H4.', 'opace-essential-seo-toolkit' ) ),
			array( __( 'Page content', 'opace-essential-seo-toolkit' ), __( 'The number of visible words. This is a descriptive count, not a quality target.', 'opace-essential-seo-toolkit' ), __( 'Never; always Info.', 'opace-essential-seo-toolkit' ) ),
			array( __( 'Structured data', 'opace-essential-seo-toolkit' ), __( 'JSON-LD blocks and the types they declare.', 'opace-essential-seo-toolkit' ), __( 'Never; always Info. Validate every detected type with the linked tools.', 'opace-essential-seo-toolkit' ) ),
			array( __( 'Social metadata', 'opace-essential-seo-toolkit' ), __( 'The Open Graph title, description and image used for shared-link previews.', 'opace-essential-seo-toolkit' ), __( 'Never. Pass when all three are present, otherwise Info.', 'opace-essential-seo-toolkit' ) ),
			array( __( 'Links', 'opace-essential-seo-toolkit' ), __( 'Internal and external link counts and how many use nofollow.', 'opace-essential-seo-toolkit' ), __( 'Never; always Info.', 'opace-essential-seo-toolkit' ) ),
		);
	}

	/**
	 * 4. Local engines.
	 */
	private static function render_section_engines(): void {
		?>
		<h3><?php esc_html_e( 'Accessibility (axe-core)', 'opace-essential-seo-toolkit' ); ?></h3>
		<?php
		self::p_html(
			sprintf(
				/* translators: %s: link to the axe-core project. */
				__( '%s 4.13.0 runs its default rule set against the loaded page, including best-practice rules, so findings such as missing landmarks can appear. Embedded frames are excluded. The panel shows up to five rule violations with up to three example selectors each, plus why each matters and what to do.', 'opace-essential-seo-toolkit' ),
				self::link( self::AXE_URL, 'axe-core', true )
			)
		);
		self::p( __( 'It can find missing alternative text, unlabelled buttons, links and form controls, low colour contrast, missing document language and missing landmarks. It cannot judge whether alt text is accurate, whether the keyboard order makes sense, or whether the content is understandable. Automated checks do not cover every accessibility requirement; findings still need human review.', 'opace-essential-seo-toolkit' ) );
		self::terms(
			array(
				array( __( 'Critical', 'opace-essential-seo-toolkit' ), __( 'Blocks some people from using the content. Fix first.', 'opace-essential-seo-toolkit' ) ),
				array( __( 'Serious', 'opace-essential-seo-toolkit' ), __( 'A significant barrier for some users.', 'opace-essential-seo-toolkit' ) ),
				array( __( 'Moderate', 'opace-essential-seo-toolkit' ), __( 'Makes the page harder to use but does not block it.', 'opace-essential-seo-toolkit' ) ),
				array( __( 'Minor', 'opace-essential-seo-toolkit' ), __( 'A small inconvenience worth tidying.', 'opace-essential-seo-toolkit' ) ),
			)
		);
		?>
		<h3><?php esc_html_e( 'Web Vitals', 'opace-essential-seo-toolkit' ); ?></h3>
		<?php
		self::p_html(
			sprintf(
				/* translators: %s: link to the web-vitals library. */
				__( 'The %s library captures five metrics from the load inside the hidden frame. Ratings use the thresholds built into the library.', 'opace-essential-seo-toolkit' ),
				self::link( self::VITALS_URL, 'web-vitals', true )
			)
		);
		self::table(
			array(
				__( 'Metric', 'opace-essential-seo-toolkit' ),
				__( 'What it measures', 'opace-essential-seo-toolkit' ),
				__( 'Good', 'opace-essential-seo-toolkit' ),
				__( 'Needs improvement', 'opace-essential-seo-toolkit' ),
				__( 'Poor', 'opace-essential-seo-toolkit' ),
			),
			self::vitals(),
			'opace-eseot-help__table--vitals'
		);
		self::p( __( 'Measured inside a hidden frame at desktop width. Layout shift and paint timings differ from a real visit; treat them as indicative. These are current-visit diagnostics, not PageSpeed Insights field data or a ranking score.', 'opace-essential-seo-toolkit' ) );
		self::p( __( 'INP is usually missing because it needs a real click or key press, and nothing interacts with the page during an automated load. The panel shows Use the page first in its place.', 'opace-essential-seo-toolkit' ) );
	}

	/**
	 * Web Vitals rows: metric, meaning, good, needs improvement, poor.
	 *
	 * @return array<int, string[]>
	 */
	private static function vitals(): array {
		return array(
			array( __( 'Main content paint (LCP)', 'opace-essential-seo-toolkit' ), __( 'Time until the largest visible element painted.', 'opace-essential-seo-toolkit' ), __( 'Up to 2,500 ms', 'opace-essential-seo-toolkit' ), __( '2,500 to 4,000 ms', 'opace-essential-seo-toolkit' ), __( 'Over 4,000 ms', 'opace-essential-seo-toolkit' ) ),
			array( __( 'Interaction response (INP)', 'opace-essential-seo-toolkit' ), __( 'Delay between an interaction and the next paint.', 'opace-essential-seo-toolkit' ), __( 'Up to 200 ms', 'opace-essential-seo-toolkit' ), __( '200 to 500 ms', 'opace-essential-seo-toolkit' ), __( 'Over 500 ms', 'opace-essential-seo-toolkit' ) ),
			array( __( 'Visual stability (CLS)', 'opace-essential-seo-toolkit' ), __( 'How much the layout moved while loading.', 'opace-essential-seo-toolkit' ), __( 'Up to 0.1', 'opace-essential-seo-toolkit' ), __( '0.1 to 0.25', 'opace-essential-seo-toolkit' ), __( 'Over 0.25', 'opace-essential-seo-toolkit' ) ),
			array( __( 'First visible content (FCP)', 'opace-essential-seo-toolkit' ), __( 'Time until the first text or image painted.', 'opace-essential-seo-toolkit' ), __( 'Up to 1,800 ms', 'opace-essential-seo-toolkit' ), __( '1,800 to 3,000 ms', 'opace-essential-seo-toolkit' ), __( 'Over 3,000 ms', 'opace-essential-seo-toolkit' ) ),
			array( __( 'Server response (TTFB)', 'opace-essential-seo-toolkit' ), __( 'Time until the first byte of the response arrived.', 'opace-essential-seo-toolkit' ), __( 'Up to 800 ms', 'opace-essential-seo-toolkit' ), __( '800 to 1,800 ms', 'opace-essential-seo-toolkit' ), __( 'Over 1,800 ms', 'opace-essential-seo-toolkit' ) ),
		);
	}

	/**
	 * 5. Crawl signals.
	 */
	private static function render_section_crawl(): void {
		self::p( __( 'The Crawl tab is the one part of the audit that runs on the server: your site requests the address from itself (a HEAD request, then GET when HEAD is refused), follows up to three redirects, waits up to eight seconds and caches the result for one minute. No other server is involved.', 'opace-essential-seo-toolkit' ) );
		self::table(
			array(
				__( 'Row', 'opace-essential-seo-toolkit' ),
				__( 'What it means', 'opace-essential-seo-toolkit' ),
			),
			self::crawl_rows(),
			'opace-eseot-help__table--crawl'
		);
	}

	/**
	 * Crawl rows: label and meaning.
	 *
	 * @return array<int, string[]>
	 */
	private static function crawl_rows(): array {
		return array(
			array( __( 'Page response', 'opace-essential-seo-toolkit' ), __( 'The HTTP status the address returned. A 2xx status shows as good; anything else, or no response, is marked for review.', 'opace-essential-seo-toolkit' ) ),
			array( __( 'Redirect', 'opace-essential-seo-toolkit' ), __( 'When the address redirected, the detail line shows the final URL. Audit the final URL if it is the one visitors land on.', 'opace-essential-seo-toolkit' ) ),
			array( __( 'robots.txt', 'opace-essential-seo-toolkit' ), __( 'Whether robots.txt was found and whether it declares a sitemap. WordPress serves a virtual robots.txt when no file exists.', 'opace-essential-seo-toolkit' ) ),
			array( __( 'robots.txt rule for this path', 'opace-essential-seo-toolkit' ), __( 'Whether a Disallow rule in robots.txt matches the audited path, and which one. Crawlers that honour robots.txt will not fetch a disallowed page.', 'opace-essential-seo-toolkit' ) ),
			array( __( 'Sitemap', 'opace-essential-seo-toolkit' ), __( 'The first sitemap that responded, checking robots.txt declarations, then /wp-sitemap.xml (the WordPress default), then /sitemap.xml.', 'opace-essential-seo-toolkit' ) ),
			array( __( 'Response indexing rule', 'opace-essential-seo-toolkit' ), __( 'The X-Robots-Tag response header, if the server sends one. No header normally means the page relies on its HTML robots setting.', 'opace-essential-seo-toolkit' ) ),
			array( __( 'Browser caching', 'opace-essential-seo-toolkit' ), __( 'Whether a Cache-Control header is present, and its value.', 'opace-essential-seo-toolkit' ) ),
			array( __( 'Security headers', 'opace-essential-seo-toolkit' ), __( 'Whether Content-Security-Policy and Strict-Transport-Security were visible to the request.', 'opace-essential-seo-toolkit' ) ),
			array( __( 'Other response headers', 'opace-essential-seo-toolkit' ), __( 'How many of X-Content-Type-Options, X-Frame-Options, Referrer-Policy and Permissions-Policy are present, plus the content type.', 'opace-essential-seo-toolkit' ) ),
		);
	}

	/**
	 * 6. Saved tools and placeholders.
	 */
	private static function render_section_tools(): void {
		self::p_html(
			sprintf(
				/* translators: 1: link to the Sources settings tab, 2: link to the Categories settings tab. */
				__( 'A saved tool is a link to an independent website with a placeholder where the page address belongs. Six ship by default and none needs an account at the time of writing. Manage them under %1$s and group them under %2$s.', 'opace-essential-seo-toolkit' ),
				self::link( self::settings_url( 'sources' ), __( 'Sources', 'opace-essential-seo-toolkit' ) ),
				self::link( self::settings_url( 'categories' ), __( 'Categories', 'opace-essential-seo-toolkit' ) )
			)
		);
		self::table(
			array(
				__( 'Tool', 'opace-essential-seo-toolkit' ),
				__( 'Category', 'opace-essential-seo-toolkit' ),
				__( 'What it does', 'opace-essential-seo-toolkit' ),
			),
			self::default_tools(),
			'opace-eseot-help__table--tools'
		);
		self::p( __( 'Categories are the headings in the sidebar launcher, the admin bar menu and the Saved tools tab. Rename or remove them on the Categories tab; a tool whose category was removed is listed under Other tools until you give it a new one.', 'opace-essential-seo-toolkit' ) );
		?>
		<h3><?php esc_html_e( 'Adding your own', 'opace-essential-seo-toolkit' ); ?></h3>
		<?php
		self::p( __( 'On the Sources tab enter a name, a URL template and a category. The template must start with http:// or https:// and use only the six placeholders below, shown here for https://www.example.com/blog/hello-world/', 'opace-essential-seo-toolkit' ) );
		?>
		<dl class="opace-eseot-help__placeholders">
			<?php foreach ( self::placeholders() as $item ) : ?>
				<dt><code><?php echo esc_html( $item['token'] ); ?></code></dt>
				<dd><?php echo esc_html( $item['text'] ); ?> <code><?php echo esc_html( $item['example'] ); ?></code></dd>
			<?php endforeach; ?>
		</dl>
		<?php
		self::p( __( 'Example: a checker that takes the address as a query parameter would be saved as', 'opace-essential-seo-toolkit' ) );
		echo '<pre class="opace-eseot-help__code"><code>' . esc_html( 'https://example.com/check?url=[%url_encoded%]' ) . '</code></pre>';
		self::p_html(
			sprintf(
				/* translators: %s: the resolved example URL. */
				__( 'and would open %s for that post. A template with any other bracketed token is rejected when you save.', 'opace-essential-seo-toolkit' ),
				'<code>' . esc_html( 'https://example.com/check?url=https%3A%2F%2Fwww.example.com%2Fblog%2Fhello-world%2F' ) . '</code>'
			)
		);
		self::p( __( 'Links open independent third-party services in a new tab. Some need an account or may not accept a URL automatically, and each runs under its own terms and privacy policy.', 'opace-essential-seo-toolkit' ) );
	}

	/**
	 * The six default tools: name, category, one line.
	 *
	 * @return array<int, string[]>
	 */
	private static function default_tools(): array {
		return array(
			array( 'Google PageSpeed Insights', __( 'Performance & UX', 'opace-essential-seo-toolkit' ), __( 'Lab and field performance data for the page, the check to confirm a slow Web Vital with.', 'opace-essential-seo-toolkit' ) ),
			array( 'Google Rich Results Test', __( 'Search appearance & markup', 'opace-essential-seo-toolkit' ), __( 'Whether the structured data on the page qualifies for enhanced search results.', 'opace-essential-seo-toolkit' ) ),
			array( 'Schema Markup Validator', __( 'Search appearance & markup', 'opace-essential-seo-toolkit' ), __( 'Validates every schema.org type the page declares.', 'opace-essential-seo-toolkit' ) ),
			array( 'WAVE Accessibility Evaluation', __( 'Accessibility', 'opace-essential-seo-toolkit' ), __( 'A visual accessibility report of the page from WebAIM.', 'opace-essential-seo-toolkit' ) ),
			array( 'Security Headers', __( 'Technical & DNS', 'opace-essential-seo-toolkit' ), __( 'Grades the HTTP response security headers of the host.', 'opace-essential-seo-toolkit' ) ),
			array( 'Google Admin Toolbox Dig', __( 'Technical & DNS', 'opace-essential-seo-toolkit' ), __( 'Looks up the DNS records for the host.', 'opace-essential-seo-toolkit' ) ),
		);
	}

	/**
	 * Placeholder reference. Tokens and examples stay out of translatable
	 * strings so the percent signs are never mistaken for sprintf markers.
	 *
	 * @return array<int, array{token: string, text: string, example: string}>
	 */
	private static function placeholders(): array {
		return array(
			array(
				'token'   => '[%url%]',
				'text'    => __( 'The full address:', 'opace-essential-seo-toolkit' ),
				'example' => 'https://www.example.com/blog/hello-world/',
			),
			array(
				'token'   => '[%url_encoded%]',
				'text'    => __( 'The same, percent-encoded for a query string:', 'opace-essential-seo-toolkit' ),
				'example' => 'https%3A%2F%2Fwww.example.com%2Fblog%2Fhello-world%2F',
			),
			array(
				'token'   => '[%host%]',
				'text'    => __( 'The host without a leading www.:', 'opace-essential-seo-toolkit' ),
				'example' => 'example.com',
			),
			array(
				'token'   => '[%host_encoded%]',
				'text'    => __( 'The host, percent-encoded:', 'opace-essential-seo-toolkit' ),
				'example' => 'example.com',
			),
			array(
				'token'   => '[%scheme%]',
				'text'    => __( 'The scheme and separator:', 'opace-essential-seo-toolkit' ),
				'example' => 'https://',
			),
			array(
				'token'   => '[%path%]',
				'text'    => __( 'The path and any query string:', 'opace-essential-seo-toolkit' ),
				'example' => '/blog/hello-world/',
			),
		);
	}

	/**
	 * 7. Settings explained.
	 */
	private static function render_section_settings(): void {
		?>
		<h3><?php echo wp_kses_post( self::link( self::settings_url( 'post-types' ), __( 'Post types', 'opace-essential-seo-toolkit' ) ) ); ?></h3>
		<?php
		self::p( __( 'Tick the post types that should show the sidebar launcher and the audit panel in the editor. Only public post types with an admin UI are listed, so custom types from themes and plugins appear here too. Posts and pages are ticked on a fresh install.', 'opace-essential-seo-toolkit' ) );
		?>
		<h3><?php echo wp_kses_post( self::link( self::settings_url( 'audit' ), __( 'Audit', 'opace-essential-seo-toolkit' ) ) ); ?></h3>
		<?php
		self::terms(
			array(
				array( __( 'Run the page audit automatically when a post opens', 'opace-essential-seo-toolkit' ), __( 'Off by default. Leaving it off keeps editing fast on large pages, because the audit loads a hidden copy of the page and a 580 KB engine each time it runs.', 'opace-essential-seo-toolkit' ) ),
				array( __( 'Run the accessibility engine (axe-core) during audits', 'opace-essential-seo-toolkit' ), __( 'On by default. Untick it to skip the accessibility card.', 'opace-essential-seo-toolkit' ) ),
				array( __( 'Capture Web Vitals during audits', 'opace-essential-seo-toolkit' ), __( 'On by default. Untick it to skip the performance card.', 'opace-essential-seo-toolkit' ) ),
				array( __( 'Show the SEO audit column on these list tables', 'opace-essential-seo-toolkit' ), __( 'Which list tables get the column, the row actions and the bulk action. It follows the Post types tab until you change it here.', 'opace-essential-seo-toolkit' ) ),
			)
		);
		?>
		<h3><?php esc_html_e( 'Updating from 1.x', 'opace-essential-seo-toolkit' ); ?></h3>
		<?php
		self::p( __( 'Version 2.0.0 ran a one-time migration of the saved tools. A saved tool was removed only when its URL was exactly one of the old defaults; anything you added or edited was kept. The six current defaults were added where no tool of that name existed, the old category names were renamed to the current four, and empty retired categories were dropped. A notice pointed you at the Sources tab to review the result.', 'opace-essential-seo-toolkit' ) );
	}

	/**
	 * 8. Privacy and data.
	 */
	private static function render_section_privacy(): void {
		?>
		<h3><?php esc_html_e( 'What is stored', 'opace-essential-seo-toolkit' ); ?></h3>
		<?php
		self::terms(
			array(
				array( __( 'Options', 'opace-essential-seo-toolkit' ), __( 'eseot_sources, eseot_categories, eseot_post_types and eseot_audit hold your settings; eseot_version records the data version; eseot_show_upgrade_notice flags the one-time notice; eseot_audit_summaries_urls keeps the last 50 summaries for addresses that are not posts, such as the home page.', 'opace-essential-seo-toolkit' ) ),
				array( __( 'Post meta', 'opace-essential-seo-toolkit' ), __( '_opace_eseot_audit_summary and _opace_eseot_audit_time hold, per audited post, the Pass, Review and Info counts, up to five top Review labels, the five metrics and the time. This feeds the list column and the dashboard widget. Full results are never stored.', 'opace-essential-seo-toolkit' ) ),
				array( __( 'Transients', 'opace-essential-seo-toolkit' ), __( 'Each crawl result is cached for 60 seconds under a key derived from the address, so repeated audits do not hammer the site.', 'opace-essential-seo-toolkit' ) ),
			)
		);
		?>
		<h3><?php esc_html_e( 'What is sent, and when', 'opace-essential-seo-toolkit' ); ?></h3>
		<?php
		self::p( __( 'Nothing, until you click a deeper check. The audit runs in your browser against a page served by your own site; the two engines are bundled files, not fetched from a CDN; the crawl check is your server talking to itself; and the summary is saved to your own database over the REST API with a nonce and capability check. Opening a saved tool sends that page address or host to the independent service, in a new tab, under its terms and privacy policy. Opace receives nothing.', 'opace-essential-seo-toolkit' ) );
		self::p( __( 'Visitors are never affected. The engines are added to a page only for a logged-in editor with a valid one-off token, and that request is marked noindex and served uncached.', 'opace-essential-seo-toolkit' ) );
		?>
		<h3><?php esc_html_e( 'Uninstall', 'opace-essential-seo-toolkit' ); ?></h3>
		<?php
		self::p( __( 'Deleting the plugin from the Plugins screen removes every option above, both post meta keys on every post and any cached crawl results, on every site of a network. Deactivating leaves them in place so nothing is lost if you reactivate.', 'opace-essential-seo-toolkit' ) );
	}

	/**
	 * 9. Troubleshooting.
	 */
	private static function render_section_troubleshooting(): void {
		self::terms(
			array(
				array(
					__( 'The audit times out or says the page did not report back', 'opace-essential-seo-toolkit' ),
					__( 'The hidden frame could not load or could not message the panel within 20 seconds. Usual causes: a security plugin or host header that blocks framing (X-Frame-Options or a Content-Security-Policy frame-ancestors rule), an admin address on a different origin from the front end, a redirect off-site, or a script error on the page. Click Open the page in a new tab; the audit runs there, reports back and the tab closes itself.', 'opace-essential-seo-toolkit' ),
				),
				array(
					__( 'Metrics are missing', 'opace-essential-seo-toolkit' ),
					__( 'INP needs a real interaction and is expected to be missing. If other metrics show Not observed, the frame was still loading when the two and a half second capture window closed; click Run again. Check that Capture Web Vitals is ticked on the Audit tab.', 'opace-essential-seo-toolkit' ),
				),
				array(
					__( 'Save the post to get an address', 'opace-essential-seo-toolkit' ),
					__( 'A brand-new post has no permalink or preview link yet. Save the draft once and the panel and launcher pick up the preview address.', 'opace-essential-seo-toolkit' ),
				),
				array(
					__( 'Crawl check unavailable', 'opace-essential-seo-toolkit' ),
					__( 'The server could not request its own address. Some hosts block loopback requests, or the site host name does not resolve from inside the server. The rest of the audit is unaffected. Ask your host to allow HTTP requests from the site to itself; the same limit also affects WP-Cron and the Site Health loopback test.', 'opace-essential-seo-toolkit' ),
				),
				array(
					__( 'The panel is squashed in the block editor', 'opace-essential-seo-toolkit' ),
					__( 'The block editor keeps meta boxes in a drawer below the content and remembers the height you drag it to. Drag the top edge of the drawer upwards to give the panel room, or click Run a page audit in the sidebar launcher, which opens the drawer and scrolls to the panel.', 'opace-essential-seo-toolkit' ),
				),
				array(
					__( 'Results differ from the Chrome extension or PageSpeed Insights', 'opace-essential-seo-toolkit' ),
					__( 'The page is audited in a hidden frame while you are logged in, so timings and layout shift differ from a clean visit. PageSpeed Insights reports Google lab and field data, not one visit from your browser.', 'opace-essential-seo-toolkit' ),
				),
			)
		);
		?>
		<h3><?php esc_html_e( 'Support', 'opace-essential-seo-toolkit' ); ?></h3>
		<?php
		self::p_html(
			sprintf(
				/* translators: 1: link to the WordPress.org support forum, 2: link to the Chrome Web Store listing. */
				__( 'Ask on the %1$s with your WordPress and PHP versions and what the panel showed. The same audit and the same default tools are available for any public web page as %2$s.', 'opace-essential-seo-toolkit' ),
				self::link( self::SUPPORT_URL, __( 'WordPress.org support forum', 'opace-essential-seo-toolkit' ), true ),
				self::link( self::CHROME_URL, __( 'Essential SEO Toolkit for Chrome', 'opace-essential-seo-toolkit' ), true )
			)
		);
	}
}
