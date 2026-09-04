<?php
/**
 * Plugin bootstrap: hooks, upgrade routine, meta boxes and admin assets.
 *
 * @package Opace_Essential_SEO_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single instance created from the main plugin file.
 */
final class Opace_ESEOT_Plugin {

	const META_BOX_ID = 'opace-eseot-toolkit';

	const AUDIT_META_BOX_ID = 'opace-eseot-audit';

	/**
	 * Option set to 1 after an upgrade migration, cleared when the notice is dismissed
	 * or the settings screen is visited.
	 */
	const OPTION_UPGRADE_NOTICE = 'eseot_show_upgrade_notice';

	const DISMISS_ACTION = 'opace_eseot_dismiss_upgrade_notice';

	/**
	 * Singleton.
	 *
	 * @var Opace_ESEOT_Plugin|null
	 */
	private static ?Opace_ESEOT_Plugin $instance = null;

	/**
	 * Settings screen and option access.
	 *
	 * @var Opace_ESEOT_Settings
	 */
	private Opace_ESEOT_Settings $settings;

	/**
	 * Page audit plumbing: runner activation, REST crawl check, panel container.
	 *
	 * @var Opace_ESEOT_Audit
	 */
	private Opace_ESEOT_Audit $audit;

	/**
	 * Essential SEO Toolkit → Page audit screen.
	 *
	 * @var Opace_ESEOT_Audit_Page
	 */
	private Opace_ESEOT_Audit_Page $audit_page;

	/**
	 * Get or create the instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire hooks. Private: use instance().
	 */
	private function __construct() {
		$this->settings   = new Opace_ESEOT_Settings();
		$this->audit      = new Opace_ESEOT_Audit( $this->settings );
		$this->audit_page = new Opace_ESEOT_Audit_Page( $this->audit );
		$this->settings->set_audit_page( $this->audit_page );

		$this->audit->register_hooks();

		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ) );
		add_action( 'admin_menu', array( $this->settings, 'register_menu' ) );
		add_action( 'admin_page_access_denied', array( $this->settings, 'redirect_legacy_urls' ) );
		add_action( 'admin_init', array( $this->settings, 'register_settings' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_notices', array( $this, 'render_upgrade_notice' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'handle_dismiss_upgrade_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OPACE_ESEOT_FILE ), array( $this->settings, 'add_settings_link' ) );

		// Stored summaries and the list-table, admin-bar and dashboard surfaces
		// live in their own classes and are optional at load time.
		if ( class_exists( 'Opace_ESEOT_Audit_Store' ) && is_callable( array( 'Opace_ESEOT_Audit_Store', 'init' ) ) ) {
			Opace_ESEOT_Audit_Store::init();
		}
		if ( class_exists( 'Opace_ESEOT_Integrations' ) && is_callable( array( 'Opace_ESEOT_Integrations', 'init' ) ) ) {
			Opace_ESEOT_Integrations::init();
		}
		if ( class_exists( 'Opace_ESEOT_Help' ) && is_callable( array( 'Opace_ESEOT_Help', 'init' ) ) ) {
			Opace_ESEOT_Help::init();
		}
	}

	/**
	 * One-off notice after migrating from a version below 2.0.0.
	 */
	public function render_upgrade_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! get_option( self::OPTION_UPGRADE_NOTICE ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen instanceof WP_Screen && $screen->id === $this->settings->page_hook() ) {
			return;
		}

		$dismiss_url = wp_nonce_url(
			add_query_arg( 'action', self::DISMISS_ACTION, admin_url( 'admin-post.php' ) ),
			self::DISMISS_ACTION
		);
		?>
		<div class="notice notice-info is-dismissible opace-eseot-upgrade-notice">
			<p>
				<?php esc_html_e( 'Essential SEO Toolkit 2.0.0 replaced its default tools with the current set used by the Chrome extension. Your own links were kept.', 'opace-essential-seo-toolkit' ); ?>
				<a href="<?php echo esc_url( $this->settings->page_url() ); ?>"><?php esc_html_e( 'Review tools', 'opace-essential-seo-toolkit' ); ?></a>
				<span aria-hidden="true">|</span>
				<a href="<?php echo esc_url( $dismiss_url ); ?>"><?php esc_html_e( 'Dismiss this notice', 'opace-essential-seo-toolkit' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Nonce-protected dismiss handler (admin-post.php?action=opace_eseot_dismiss_upgrade_notice).
	 */
	public function handle_dismiss_upgrade_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'opace-essential-seo-toolkit' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::DISMISS_ACTION );

		delete_option( self::OPTION_UPGRADE_NOTICE );

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}

	/**
	 * Settings object.
	 */
	public function settings(): Opace_ESEOT_Settings {
		return $this->settings;
	}

	/**
	 * Audit object.
	 */
	public function audit(): Opace_ESEOT_Audit {
		return $this->audit;
	}

	/**
	 * Page audit screen object.
	 */
	public function audit_page(): Opace_ESEOT_Audit_Page {
		return $this->audit_page;
	}

	/**
	 * Activation: seed defaults on a fresh site, or migrate an existing one.
	 *
	 * Does not write eseot_version before migrating, otherwise re-activating on
	 * a 1.x site would skip the upgrade.
	 */
	public static function activate(): void {
		self::instance()->maybe_upgrade();
	}

	/**
	 * Seed or upgrade stored options. Hooked to plugins_loaded; cheap when nothing to do.
	 */
	public function maybe_upgrade(): void {
		$version = get_option( 'eseot_version', '' );
		$version = is_string( $version ) ? trim( $version ) : '';

		$sources    = get_option( 'eseot_sources' );
		$categories = get_option( 'eseot_categories' );

		// Fresh install, or options deleted while eseot_version survived: seed and stop.
		if ( false === $sources && false === $categories ) {
			$this->seed_defaults();
			update_option( 'eseot_version', OPACE_ESEOT_VERSION );
			return;
		}

		if ( false === get_option( 'eseot_post_types' ) ) {
			add_option( 'eseot_post_types', Opace_ESEOT_Defaults::post_types() );
		}

		$sources    = is_array( $sources ) ? $sources : array();
		$categories = is_array( $categories ) ? $categories : array();

		if ( '' !== $version && version_compare( $version, Opace_ESEOT_Defaults::DATA_VERSION, '>=' ) ) {
			if ( OPACE_ESEOT_VERSION !== $version ) {
				update_option( 'eseot_version', OPACE_ESEOT_VERSION );
			}
			return;
		}

		$result = Opace_ESEOT_Defaults::migrate( $sources, $categories, $version );

		if ( $result['changed'] ) {
			update_option( 'eseot_sources', $result['sources'] );
			update_option( 'eseot_categories', $result['categories'] );
		}

		// Only a real upgrade from 1.x earns the notice; a site with data but no
		// version is treated the same way because 1.x always wrote eseot_version.
		update_option( self::OPTION_UPGRADE_NOTICE, 1, false );

		update_option( 'eseot_version', OPACE_ESEOT_VERSION );

		/**
		 * Fires after stored data has been migrated to the current version.
		 *
		 * @param string $version Previously stored version ('' when unknown).
		 * @param array  $result  Migration result: sources, categories, changed.
		 */
		do_action( 'opace_eseot_upgraded', $version, $result );
	}

	/**
	 * Add any missing option with its default value.
	 */
	private function seed_defaults(): void {
		$defaults = array(
			'eseot_sources'    => Opace_ESEOT_Defaults::sources(),
			'eseot_categories' => Opace_ESEOT_Defaults::categories(),
			'eseot_post_types' => Opace_ESEOT_Defaults::post_types(),
		);

		foreach ( $defaults as $option => $value ) {
			if ( false === get_option( $option ) ) {
				add_option( $option, $value );
			}
		}
	}

	/**
	 * Enabled post types that are still registered.
	 *
	 * @return string[]
	 */
	public function get_supported_post_types(): array {
		$registered = Opace_ESEOT_Settings::get_post_types();
		$enabled    = get_option( 'eseot_post_types' );
		if ( ! is_array( $enabled ) ) {
			$enabled = Opace_ESEOT_Defaults::post_types();
		}

		$types = array();
		foreach ( $registered as $slug ) {
			if ( ! empty( $enabled[ $slug ] ) ) {
				$types[] = $slug;
			}
		}

		$types = apply_filters( 'opace_eseot_supported_post_types', $types );

		return is_array( $types ) ? array_values( $types ) : array();
	}

	/**
	 * Register the launcher (side) and the page audit panel (normal, high) for
	 * supported post types. Hooked to add_meta_boxes.
	 *
	 * @param string       $post_type Post type being edited (core passes 'comment' too).
	 * @param WP_Post|null $post      Post being edited.
	 */
	public function register_meta_box( $post_type = '', $post = null ): void {
		$post_type = is_string( $post_type ) ? $post_type : '';
		if ( '' === $post_type || ! in_array( $post_type, $this->get_supported_post_types(), true ) ) {
			return;
		}

		add_meta_box(
			self::META_BOX_ID,
			__( 'Essential SEO Toolkit', 'opace-essential-seo-toolkit' ),
			array( $this, 'render_meta_box' ),
			$post_type,
			'side',
			'low'
		);

		add_meta_box(
			self::AUDIT_META_BOX_ID,
			__( 'Essential SEO Toolkit page audit', 'opace-essential-seo-toolkit' ),
			array( $this, 'render_audit_meta_box' ),
			$post_type,
			'normal',
			'high'
		);

		// Closed by default: WordPress only stores closedpostboxes_{post_type} once
		// the user collapses or expands a box on that screen, so the user option is
		// false until then. Answering that first read with our id keeps the audit
		// panel collapsed for newcomers while every later choice the user makes
		// (the option becomes an array) is respected untouched.
		add_filter( 'get_user_option_closedpostboxes_' . $post_type, array( $this, 'close_audit_box_by_default' ) );
	}

	/**
	 * Collapse the audit panel for users who have never toggled a meta box on
	 * this screen. Filter callback for get_user_option_closedpostboxes_{post_type}.
	 *
	 * @param mixed $closed Stored list of closed box ids, or false when never saved.
	 * @return mixed
	 */
	public function close_audit_box_by_default( $closed ) {
		if ( false === $closed ) {
			return array( self::AUDIT_META_BOX_ID );
		}

		return $closed;
	}

	/**
	 * Page audit panel container. The panel script builds the rest.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render_audit_meta_box( $post ): void {
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$this->audit->render_container(
			array(
				'target_url' => Opace_ESEOT_Audit::audit_target_for_post( $post ),
				'post_id'    => (int) $post->ID,
				'layout'     => 'compact',
			)
		);
	}

	/**
	 * Meta box contents.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public function render_meta_box( $post ): void {
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$permalink = get_permalink( $post );
		$title     = get_the_title( $post );
		if ( '' === trim( (string) $title ) ) {
			$title = __( '(no title)', 'opace-essential-seo-toolkit' );
		}
		$mark = plugins_url( 'assets/images/opace-eseot-mark.svg', OPACE_ESEOT_FILE );
		?>
		<div class="opace-eseot-metabox">
			<div class="opace-eseot-metabox__header">
				<img class="opace-eseot-metabox__mark" src="<?php echo esc_url( $mark ); ?>" alt="" width="28" height="28">
				<?php if ( is_string( $permalink ) && '' !== $permalink ) : ?>
					<p class="opace-eseot-metabox__intro">
						<?php
						$link = sprintf(
							'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
							esc_url( $permalink ),
							esc_html( $title )
						);
						/* translators: %s: link to the post being edited. */
						echo wp_kses_post( sprintf( __( 'Open %s with:', 'opace-essential-seo-toolkit' ), $link ) );
						?>
					</p>
				<?php else : ?>
					<p class="opace-eseot-metabox__intro"><?php esc_html_e( 'Save the post to get an address you can open with your saved tools.', 'opace-essential-seo-toolkit' ); ?></p>
				<?php endif; ?>
			</div>
			<?php if ( is_string( $permalink ) && '' !== $permalink ) : ?>
				<?php $this->render_tool_list( $permalink ); ?>
			<?php endif; ?>
			<div class="opace-eseot-metabox__footer">
				<p class="opace-eseot-metabox__audit">
					<a class="opace-eseot-metabox__audit-link" href="<?php echo esc_attr( '#' . self::AUDIT_META_BOX_ID ); ?>"><?php esc_html_e( 'Run a page audit', 'opace-essential-seo-toolkit' ); ?></a>
				</p>
				<p class="opace-eseot-metabox__note"><?php esc_html_e( 'Links open independent third-party services in a new tab. Some need an account or may not accept a URL automatically.', 'opace-essential-seo-toolkit' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Search field and category accordion.
	 *
	 * Sources whose category no longer exists are listed under "Other tools".
	 * Sources whose template cannot be resolved for this permalink are skipped.
	 *
	 * @param string $permalink Permalink of the post.
	 */
	private function render_tool_list( string $permalink ): void {
		$categories = $this->settings->get_categories();
		$groups     = array();

		foreach ( $this->settings->get_sources() as $source ) {
			$href = Opace_ESEOT_Link_Resolver::resolve( $source['url'], $permalink );
			if ( false === $href ) {
				continue;
			}
			$key              = isset( $categories[ $source['cat'] ] ) ? (string) $source['cat'] : 'other';
			$groups[ $key ][] = array(
				'name' => $source['name'],
				'href' => $href,
			);
		}

		$sections = array();
		foreach ( $categories as $id => $name ) {
			if ( ! empty( $groups[ (string) $id ] ) ) {
				$sections[ (string) $id ] = $name;
			}
		}
		if ( ! empty( $groups['other'] ) ) {
			$sections['other'] = __( 'Other tools', 'opace-essential-seo-toolkit' );
		}
		?>
		<label class="screen-reader-text" for="opace-eseot-search"><?php esc_html_e( 'Search saved tools', 'opace-essential-seo-toolkit' ); ?></label>
		<input type="search" id="opace-eseot-search" class="opace-eseot-search" placeholder="<?php esc_attr_e( 'Search saved tools', 'opace-essential-seo-toolkit' ); ?>" autocomplete="off">
		<ul class="opace-eseot-accordion">
			<?php foreach ( $sections as $key => $name ) : ?>
				<li class="opace-eseot-category" data-category="<?php echo esc_attr( $key ); ?>">
					<button type="button" class="opace-eseot-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( 'opace-eseot-cat-' . $key ); ?>"><?php echo esc_html( $name ); ?></button>
					<ul class="opace-eseot-sources" id="<?php echo esc_attr( 'opace-eseot-cat-' . $key ); ?>" hidden>
						<?php foreach ( $groups[ $key ] as $tool ) : ?>
							<li class="opace-eseot-source"><a href="<?php echo esc_url( $tool['href'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $tool['name'] ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( empty( $sections ) ) : ?>
			<p class="opace-eseot-metabox__none">
				<?php
				printf(
					/* translators: %s: link to the settings screen. */
					esc_html__( 'No saved tools yet. Add some under %s.', 'opace-essential-seo-toolkit' ),
					'<a href="' . esc_url( $this->settings->page_url() ) . '">' . esc_html__( 'Settings', 'opace-essential-seo-toolkit' ) . '</a>'
				);
				?>
			</p>
		<?php endif; ?>
		<p class="opace-eseot-empty" hidden><?php esc_html_e( 'No saved tools match your search.', 'opace-essential-seo-toolkit' ); ?></p>
		<?php
	}

	/**
	 * Enqueue assets on the settings screen and on supported edit screens only.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook_suffix = '' ): void {
		$hook_suffix = is_string( $hook_suffix ) ? $hook_suffix : '';
		$base        = plugin_dir_url( OPACE_ESEOT_FILE );

		if ( $hook_suffix === $this->settings->page_hook() ) {
			wp_enqueue_style( 'opace-eseot-settings', $base . 'assets/css/opace-eseot-settings.css', array( 'dashicons' ), OPACE_ESEOT_VERSION );
			wp_enqueue_script( 'opace-eseot-settings', $base . 'assets/js/opace-eseot-settings.js', array(), OPACE_ESEOT_VERSION, true );
			return;
		}

		if ( $hook_suffix === $this->audit_page->page_hook() ) {
			wp_enqueue_style( 'opace-eseot-settings', $base . 'assets/css/opace-eseot-settings.css', array( 'dashicons' ), OPACE_ESEOT_VERSION );
			$this->audit->enqueue_panel_assets( 0 );
			return;
		}

		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof WP_Screen || ! in_array( (string) $screen->post_type, $this->get_supported_post_types(), true ) ) {
			return;
		}

		wp_enqueue_style( 'opace-eseot-metabox', $base . 'assets/css/opace-eseot-metabox.css', array( 'dashicons' ), OPACE_ESEOT_VERSION );
		wp_enqueue_script( 'opace-eseot-metabox', $base . 'assets/js/opace-eseot-metabox.js', array(), OPACE_ESEOT_VERSION, true );

		$post = get_post();
		$this->audit->enqueue_panel_assets( $post instanceof WP_Post ? (int) $post->ID : 0 );
	}
}
