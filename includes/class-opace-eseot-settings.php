<?php
/**
 * Settings page, option sanitisers and option getters.
 *
 * @package Opace_Essential_SEO_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Essential SEO Toolkit → Settings, and the top-level admin menu it belongs to.
 *
 * The top-level "Essential SEO Toolkit" menu (slug opace-eseot, after Tools)
 * opens the Page audit screen rendered by Opace_ESEOT_Audit_Page; its two
 * submenus are "Page audit" (same slug, edit_posts) and "Settings" (slug
 * opace-eseot-settings, manage_options). Pre-2.0.0 URLs under Settings and
 * Tools redirect to the new locations.
 *
 * Option names are unchanged from 1.x: eseot_sources, eseot_categories,
 * eseot_post_types. Each tab is its own Settings API group posting to options.php.
 */
final class Opace_ESEOT_Settings {

	/**
	 * Top-level menu slug. The Page audit screen lives here.
	 */
	const PARENT_SLUG = 'opace-eseot';

	/**
	 * Settings submenu slug.
	 */
	const MENU_SLUG = 'opace-eseot-settings';

	/**
	 * Settings slug before 2.0.0 (options-general.php?page=essential-seo-toolkit).
	 */
	const LEGACY_MENU_SLUG = 'essential-seo-toolkit';

	/**
	 * Position of the top-level menu: directly after Tools (75).
	 */
	const MENU_POSITION = 76;

	/**
	 * assets/images/opace-eseot-menu-icon.svg, base64-encoded. A single
	 * #a7aaad fill lets WordPress recolour it for the active colour scheme.
	 */
	const MENU_ICON_BASE64 = 'PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCIgd2lkdGg9IjIwIiBoZWlnaHQ9IjIwIj48cGF0aCBmaWxsPSIjYTdhYWFkIiBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0xMCAuOCAxOC40IDUuNnY4LjhMMTAgMTkuMiAxLjYgMTQuNFY1LjZabTMuMyA4LjNhNC4yIDQuMiAwIDEgMC04LjQgMCA0LjIgNC4yIDAgMCAwIDguNCAwWm0tMS43OCAzLjk4IDIuOSAyLjkgMS41Ni0xLjU2LTIuOS0yLjlaTTYuNSA5LjJoMS4zdjIuMkg2LjVabTEuOTUtMS4yaDEuM3YzLjRoLTEuM1ptMS45NS0xLjJoMS4zdjQuNmgtMS4zWiIvPjwvc3ZnPg==';

	const GROUP_SOURCES    = 'opace_eseot_sources';
	const GROUP_CATEGORIES = 'opace_eseot_categories';
	const GROUP_POST_TYPES = 'opace_eseot_post_types';
	const GROUP_AUDIT      = 'opace_eseot_audit';

	/**
	 * Hook suffix returned by add_submenu_page() for the Settings screen.
	 *
	 * @var string
	 */
	private string $page_hook = '';

	/**
	 * Page audit screen, rendered by the top-level menu item. Optional so the
	 * class can be used on its own (sanitisers, option getters).
	 *
	 * @var Opace_ESEOT_Audit_Page|null
	 */
	private ?Opace_ESEOT_Audit_Page $audit_page = null;

	/**
	 * Give the top-level menu its Page audit screen. Called from the plugin bootstrap.
	 *
	 * @param Opace_ESEOT_Audit_Page $audit_page Page audit screen.
	 */
	public function set_audit_page( Opace_ESEOT_Audit_Page $audit_page ): void {
		$this->audit_page = $audit_page;
	}

	/**
	 * Register the top-level menu and its submenus. Hooked to admin_menu.
	 *
	 * The first submenu shares the parent slug and callback, the standard way
	 * to give the top-level item its own label ("Page audit") without a
	 * duplicate entry.
	 */
	public function register_menu(): void {
		$parent_callback = null !== $this->audit_page ? array( $this->audit_page, 'render_page' ) : array( $this, 'render_page' );

		$parent_hook = add_menu_page(
			__( 'Essential SEO Toolkit', 'opace-essential-seo-toolkit' ),
			__( 'Essential SEO Toolkit', 'opace-essential-seo-toolkit' ),
			'edit_posts',
			self::PARENT_SLUG,
			$parent_callback,
			self::menu_icon(),
			self::MENU_POSITION
		);

		if ( null !== $this->audit_page ) {
			$this->audit_page->register_menu( self::PARENT_SLUG, is_string( $parent_hook ) ? $parent_hook : '' );
		}

		$hook = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Opace Essential SEO Toolkit', 'opace-essential-seo-toolkit' ),
			__( 'Settings', 'opace-essential-seo-toolkit' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);

		$this->page_hook = is_string( $hook ) ? $hook : '';

		// Help (edit_posts) sits after Settings so every capable user sees it last.
		if ( class_exists( 'Opace_ESEOT_Help' ) ) {
			Opace_ESEOT_Help::register_menu( self::PARENT_SLUG );
		}
	}

	/**
	 * Data URI for the menu icon.
	 */
	public static function menu_icon(): string {
		return 'data:image/svg+xml;base64,' . self::MENU_ICON_BASE64;
	}

	/**
	 * Hook suffix of the settings screen, for targeted enqueues.
	 *
	 * WordPress builds submenu hooks as {sanitised parent menu title}_page_{slug}.
	 */
	public function page_hook(): string {
		return '' !== $this->page_hook ? $this->page_hook : self::LEGACY_MENU_SLUG . '_page_' . self::MENU_SLUG;
	}

	/**
	 * Send pre-2.0.0 admin URLs to their new home. Hooked to admin_page_access_denied.
	 *
	 * The old pages are no longer registered, so WordPress refuses them in
	 * menu.php before admin_init fires; that refusal is the only chance to
	 * redirect. Read-only and nonce-free: it only translates an old bookmark.
	 */
	public function redirect_legacy_urls(): void {
		global $pagenow;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only: every value is sanitised in legacy_redirect_target().
		$target = $this->legacy_redirect_target( is_string( $pagenow ) ? $pagenow : '', wp_unslash( $_GET ) );
		if ( '' === $target ) {
			return;
		}

		if ( wp_safe_redirect( $target, 301 ) ) {
			exit;
		}
	}

	/**
	 * Where a pre-2.0.0 admin URL should go, or '' when the request is not one.
	 *
	 * Handled: options-general.php?page=essential-seo-toolkit (with tab),
	 * tools.php?page=opace-eseot-audit (with url, autorun, view, ids,
	 * truncated) and the same two slugs on admin.php. Other query arguments
	 * are carried over.
	 *
	 * @param string $pagenow Admin file handling the request, for example options-general.php.
	 * @param array  $query   Unslashed query arguments.
	 * @return string
	 */
	public function legacy_redirect_target( string $pagenow, array $query ): string {
		$page = isset( $query['page'] ) && is_scalar( $query['page'] ) ? sanitize_key( (string) $query['page'] ) : '';
		if ( '' === $page ) {
			return '';
		}

		$to_settings = self::LEGACY_MENU_SLUG === $page && in_array( $pagenow, array( 'options-general.php', 'admin.php' ), true );
		$to_audit    = Opace_ESEOT_Audit_Page::LEGACY_MENU_SLUG === $page && in_array( $pagenow, array( 'tools.php', 'admin.php' ), true );
		if ( ! $to_settings && ! $to_audit ) {
			return '';
		}

		$args = array();
		foreach ( $query as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || 'page' === $key || ! is_scalar( $value ) ) {
				continue;
			}
			$value = sanitize_text_field( (string) $value );
			if ( '' !== $value ) {
				$args[ $key ] = $value;
			}
		}

		if ( $to_audit ) {
			return Opace_ESEOT_Audit_Page::page_url( $args );
		}

		$tab = isset( $args['tab'] ) ? sanitize_key( $args['tab'] ) : '';
		unset( $args['tab'] );

		return add_query_arg( array_map( 'rawurlencode', $args ), $this->page_url( $tab ) );
	}

	/**
	 * Register the four options with the Settings API. Hooked to admin_init.
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP_SOURCES,
			'eseot_sources',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_sources' ),
				'default'           => array(),
			)
		);

		register_setting(
			self::GROUP_CATEGORIES,
			'eseot_categories',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_categories' ),
				'default'           => array(),
			)
		);

		register_setting(
			self::GROUP_POST_TYPES,
			'eseot_post_types',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_post_types' ),
				'default'           => array(),
			)
		);

		register_setting(
			self::GROUP_AUDIT,
			'eseot_audit',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_audit' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function add_settings_link( array $links ): array {
		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $this->page_url() ),
			esc_html__( 'Settings', 'opace-essential-seo-toolkit' )
		);

		return $links;
	}

	/**
	 * URL of the settings screen, optionally for one tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	public function page_url( string $tab = '' ): string {
		$args = array( 'page' => self::MENU_SLUG );
		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/* ---------------------------------------------------------------------
	 * Sanitisers
	 * ------------------------------------------------------------------ */

	/**
	 * Sanitise the sources list.
	 *
	 * Drops rows with an invalid template (and tells the user which URL was
	 * rejected), sanitises names, casts cat to int and re-indexes.
	 *
	 * Templates that are already stored, or that are one of the shipped defaults,
	 * only get the structural check. The full check includes a DNS lookup via
	 * wp_http_validate_url(), and a transient DNS failure must not wipe saved rows.
	 *
	 * @param mixed $input Raw option value.
	 * @return array
	 */
	public function sanitize_sources( $input ): array {
		$output  = array();
		$trusted = array();

		foreach ( $this->get_sources() as $source ) {
			$trusted[ $source['url'] ] = true;
		}
		foreach ( Opace_ESEOT_Defaults::sources() as $source ) {
			$trusted[ $source['url'] ] = true;
		}

		if ( is_array( $input ) ) {
			foreach ( $input as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}

				$name = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';
				$url  = isset( $row['url'] ) ? Opace_ESEOT_Link_Resolver::sanitize_template( (string) $row['url'] ) : '';
				$cat  = isset( $row['cat'] ) ? absint( $row['cat'] ) : 0;

				// The blank "add a source" row.
				if ( '' === $name && '' === $url ) {
					continue;
				}

				$valid = '' !== $url && (
					isset( $trusted[ $url ] )
						? Opace_ESEOT_Link_Resolver::is_well_formed_template( $url )
						: Opace_ESEOT_Link_Resolver::is_valid_template( $url )
				);

				if ( ! $valid ) {
					$this->add_invalid_url_error( $name );
					continue;
				}

				if ( '' === $name ) {
					$this->add_invalid_url_error( '' );
					continue;
				}

				// Stored as validated: esc_url_raw() would encode the placeholder brackets.
				$output[] = array(
					'name' => $name,
					'url'  => $url,
					'cat'  => $cat,
				);
			}
		}

		$output = $this->apply_legacy_validation_filter( $output, $input );
		$output = apply_filters( 'opace_eseot_sanitize_sources', $output, $input );

		return is_array( $output ) ? array_values( $output ) : array();
	}

	/**
	 * Sanitise the categories map.
	 *
	 * Numeric keys are kept as ids. Any other key (the "new" entry) is given the
	 * next free id.
	 *
	 * @param mixed $input Raw option value.
	 * @return array<int, string>
	 */
	public function sanitize_categories( $input ): array {
		$output = array();
		$added  = array();

		if ( is_array( $input ) ) {
			foreach ( $input as $id => $name ) {
				$name = sanitize_text_field( (string) $name );
				if ( '' === $name ) {
					continue;
				}

				if ( is_int( $id ) || ctype_digit( (string) $id ) ) {
					$output[ (int) $id ] = $name;
				} else {
					$added[] = $name;
				}
			}
		}

		ksort( $output );

		foreach ( $added as $name ) {
			$output[ $this->next_category_id( $output ) ] = $name;
		}

		$output = $this->apply_legacy_validation_filter( $output, $input );
		$output = apply_filters( 'opace_eseot_sanitize_categories', $output, $input );

		return is_array( $output ) ? $output : array();
	}

	/**
	 * Sanitise the enabled post types map.
	 *
	 * Only registered public post types survive; each enabled slug maps to 1.
	 *
	 * @param mixed $input Raw option value.
	 * @return array<string, int>
	 */
	public function sanitize_post_types( $input ): array {
		$registered = self::get_post_types();
		$output     = array();

		if ( is_array( $input ) ) {
			foreach ( $input as $slug => $enabled ) {
				$slug = sanitize_key( (string) $slug );
				if ( isset( $registered[ $slug ] ) && ! empty( $enabled ) ) {
					$output[ $slug ] = 1;
				}
			}
		}

		$output = $this->apply_legacy_validation_filter( $output, $input );
		$output = apply_filters( 'opace_eseot_sanitize_post_types', $output, $input );

		return is_array( $output ) ? $output : array();
	}

	/**
	 * Sanitise the audit settings.
	 *
	 * autorun, engine_axe and engine_vitals are checkboxes (absent means off).
	 * column_post_types accepts a list of slugs or a slug => 1 map and keeps
	 * only registered public post types. When the form marks the list as
	 * submitted (column_post_types_set) an empty selection is stored as an
	 * empty list; otherwise the stored list, if any, is kept.
	 *
	 * @param mixed $input Raw option value.
	 * @return array{autorun: int, engine_axe: int, engine_vitals: int, column_post_types?: string[]}
	 */
	public function sanitize_audit( $input ): array {
		$input      = is_array( $input ) ? $input : array();
		$registered = self::get_post_types();

		$output = array(
			'autorun'       => empty( $input['autorun'] ) ? 0 : 1,
			'engine_axe'    => empty( $input['engine_axe'] ) ? 0 : 1,
			'engine_vitals' => empty( $input['engine_vitals'] ) ? 0 : 1,
		);

		if ( isset( $input['column_post_types'] ) || ! empty( $input['column_post_types_set'] ) ) {
			$types = array();
			$raw   = isset( $input['column_post_types'] ) ? (array) $input['column_post_types'] : array();
			foreach ( $raw as $key => $value ) {
				$is_map = is_string( $key ) && ! ctype_digit( $key );
				if ( $is_map && empty( $value ) ) {
					continue;
				}
				$slug = sanitize_key( $is_map ? $key : (string) $value );
				if ( isset( $registered[ $slug ] ) && ! in_array( $slug, $types, true ) ) {
					$types[] = $slug;
				}
			}
			$output['column_post_types'] = $types;
		} else {
			$current = get_option( 'eseot_audit', array() );
			if ( is_array( $current ) && isset( $current['column_post_types'] ) && is_array( $current['column_post_types'] ) ) {
				$output['column_post_types'] = array_values( array_map( 'sanitize_key', $current['column_post_types'] ) );
			}
		}

		$output = $this->apply_legacy_validation_filter( $output, $input );
		$output = apply_filters( 'opace_eseot_sanitize_audit', $output, $input );

		return is_array( $output ) ? $output : array();
	}

	/**
	 * Settings error for a rejected URL template.
	 *
	 * @param string $name Source name, or '' when the row had none.
	 */
	private function add_invalid_url_error( string $name ): void {
		if ( '' === $name ) {
			$message = esc_html__( 'One or more source URLs were not saved. Each URL must start with http:// or https:// and use only the supported placeholders.', 'opace-essential-seo-toolkit' );
		} else {
			$message = sprintf(
				/* translators: %s: source name entered by the user. */
				esc_html__( 'The URL for %s was not saved. It must start with http:// or https:// and use only the supported placeholders.', 'opace-essential-seo-toolkit' ),
				'<strong>' . esc_html( $name ) . '</strong>'
			);
		}

		add_settings_error( 'eseot_sources', 'opace_eseot_source_invalid_url', $message );
	}

	/**
	 * 1.x fired eseot_validate_inputs for every option. Keep doing so.
	 *
	 * @param array $output Sanitised value.
	 * @param mixed $input  Raw value.
	 * @return array
	 */
	private function apply_legacy_validation_filter( array $output, $input ): array {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- legacy hook kept for back-compat.
		$filtered = apply_filters( 'eseot_validate_inputs', $output, $input );

		return is_array( $filtered ) ? $filtered : $output;
	}

	/**
	 * Next id above every category id in use.
	 *
	 * @param array<int, string> $categories Categories.
	 * @return int
	 */
	private function next_category_id( array $categories ): int {
		return empty( $categories ) ? 1 : max( array_keys( $categories ) ) + 1;
	}

	/* ---------------------------------------------------------------------
	 * Getters
	 * ------------------------------------------------------------------ */

	/**
	 * Saved sources, shaped as a list of name/url/cat.
	 *
	 * @return array<int, array{name: string, url: string, cat: int}>
	 */
	public function get_sources(): array {
		$stored = get_option( 'eseot_sources', array() );
		$stored = is_array( $stored ) ? $stored : array();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- legacy hook kept for back-compat.
		$stored = apply_filters( 'eseot-get-sources', $stored );
		$stored = apply_filters( 'opace_eseot_sources', $stored );

		$sources = array();
		if ( is_array( $stored ) ) {
			foreach ( $stored as $source ) {
				if ( ! is_array( $source ) || empty( $source['url'] ) || ! is_string( $source['url'] ) ) {
					continue;
				}
				$sources[] = array(
					'name' => isset( $source['name'] ) ? (string) $source['name'] : '',
					'url'  => $source['url'],
					'cat'  => isset( $source['cat'] ) ? (int) $source['cat'] : 0,
				);
			}
		}

		return $sources;
	}

	/**
	 * Saved categories, id => name.
	 *
	 * @return array<int, string>
	 */
	public function get_categories(): array {
		$stored = get_option( 'eseot_categories', array() );
		$stored = is_array( $stored ) ? $stored : array();

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- legacy hook kept for back-compat.
		$stored = apply_filters( 'eseot-get-categories', $stored );
		$stored = apply_filters( 'opace_eseot_categories', $stored );

		$categories = array();
		if ( is_array( $stored ) ) {
			foreach ( $stored as $id => $name ) {
				if ( ! is_int( $id ) && ! ctype_digit( (string) $id ) ) {
					continue;
				}
				$name = trim( (string) $name );
				if ( '' !== $name ) {
					$categories[ (int) $id ] = $name;
				}
			}
		}

		return $categories;
	}

	/**
	 * Audit settings with defaults applied.
	 *
	 * column_post_types defaults to the post types the meta box is enabled
	 * for, so the list-table column follows the Post types tab until someone
	 * changes it on the Audit tab.
	 *
	 * @return array{autorun: int, engine_axe: int, engine_vitals: int, column_post_types: string[]}
	 */
	public function get_audit_settings(): array {
		$stored = get_option( 'eseot_audit', array() );
		$stored = is_array( $stored ) ? $stored : array();

		$settings = array(
			'autorun'       => empty( $stored['autorun'] ) ? 0 : 1,
			'engine_axe'    => ( isset( $stored['engine_axe'] ) && empty( $stored['engine_axe'] ) ) ? 0 : 1,
			'engine_vitals' => ( isset( $stored['engine_vitals'] ) && empty( $stored['engine_vitals'] ) ) ? 0 : 1,
		);

		if ( isset( $stored['column_post_types'] ) && is_array( $stored['column_post_types'] ) ) {
			$registered = self::get_post_types();
			$types      = array();
			foreach ( $stored['column_post_types'] as $slug ) {
				$slug = sanitize_key( (string) $slug );
				if ( isset( $registered[ $slug ] ) && ! in_array( $slug, $types, true ) ) {
					$types[] = $slug;
				}
			}
			$settings['column_post_types'] = $types;
		} else {
			$settings['column_post_types'] = self::enabled_post_types();
		}

		$settings = apply_filters( 'opace_eseot_audit_settings', $settings );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Post types the meta box is enabled for (registered public types only).
	 *
	 * @return string[]
	 */
	public static function enabled_post_types(): array {
		$enabled = get_option( 'eseot_post_types' );
		if ( ! is_array( $enabled ) ) {
			$enabled = Opace_ESEOT_Defaults::post_types();
		}

		$types = array();
		foreach ( self::get_post_types() as $slug ) {
			if ( ! empty( $enabled[ $slug ] ) ) {
				$types[] = $slug;
			}
		}

		return $types;
	}

	/**
	 * Registered public post types with a UI, slug => slug.
	 *
	 * @return array<string, string>
	 */
	public static function get_post_types(): array {
		$types = get_post_types(
			array(
				'public'  => true,
				'show_ui' => true,
			),
			'names'
		);

		$types = apply_filters( 'opace_eseot_post_types', $types );

		return is_array( $types ) ? $types : array();
	}

	/**
	 * Post type slug => human label for the settings screen.
	 *
	 * @return array<string, string>
	 */
	private function post_type_labels(): array {
		$labels = array();
		foreach ( self::get_post_types() as $slug ) {
			$object          = get_post_type_object( $slug );
			$labels[ $slug ] = ( $object && isset( $object->labels->name ) ) ? (string) $object->labels->name : $slug;
		}

		return $labels;
	}

	/* ---------------------------------------------------------------------
	 * Rendering
	 * ------------------------------------------------------------------ */

	/**
	 * Tab slugs and labels.
	 *
	 * @return array<string, string>
	 */
	private function tabs(): array {
		return array(
			'sources'    => __( 'Sources', 'opace-essential-seo-toolkit' ),
			'categories' => __( 'Categories', 'opace-essential-seo-toolkit' ),
			'post-types' => __( 'Post types', 'opace-essential-seo-toolkit' ),
			'audit'      => __( 'Audit', 'opace-essential-seo-toolkit' ),
		);
	}

	/**
	 * Render the settings screen.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'opace-essential-seo-toolkit' ) );
		}

		// Visiting the settings screen counts as reviewing the migrated tools.
		if ( get_option( Opace_ESEOT_Plugin::OPTION_UPGRADE_NOTICE ) ) {
			delete_option( Opace_ESEOT_Plugin::OPTION_UPGRADE_NOTICE );
		}

		$tabs = $this->tabs();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab switch, nothing is written.
		$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'sources';
		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'sources';
		}

		$mark = plugins_url( 'assets/images/opace-eseot-mark.svg', OPACE_ESEOT_FILE );
		?>
		<div class="wrap opace-eseot-settings">
			<h1 class="opace-eseot-settings__title">
				<img class="opace-eseot-settings__mark" src="<?php echo esc_url( $mark ); ?>" alt="" width="32" height="32">
				<?php esc_html_e( 'Opace Essential SEO Toolkit', 'opace-essential-seo-toolkit' ); ?>
			</h1>
			<p class="opace-eseot-settings__lede"><?php esc_html_e( 'Saved tools, their categories, the post types that get the editor panel and how the page audit runs.', 'opace-essential-seo-toolkit' ); ?></p>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Settings sections', 'opace-essential-seo-toolkit' ); ?>">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( $this->page_url( $slug ) ); ?>"
						class="nav-tab<?php echo ( $slug === $active ) ? ' nav-tab-active' : ''; ?>"
						<?php echo ( $slug === $active ) ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php
			switch ( $active ) {
				case 'categories':
					$this->render_categories_tab();
					break;
				case 'post-types':
					$this->render_post_types_tab();
					break;
				case 'audit':
					$this->render_audit_tab();
					break;
				default:
					$this->render_sources_tab();
					break;
			}
			?>
		</div>
		<?php
	}

	/**
	 * Sources tab.
	 */
	private function render_sources_tab(): void {
		$sources    = $this->get_sources();
		$categories = $this->get_categories();
		?>
		<form method="post" action="options.php" class="opace-eseot-form opace-eseot-form--sources">
			<?php settings_fields( self::GROUP_SOURCES ); ?>

			<div class="opace-eseot-card">
			<h2><?php esc_html_e( 'Saved sources', 'opace-essential-seo-toolkit' ); ?></h2>
			<p class="opace-eseot-intro"><?php esc_html_e( 'Each source is a tool link. Write the URL as a template and put a placeholder where the page address belongs. Links open in a new tab from the editor meta box.', 'opace-essential-seo-toolkit' ); ?></p>

			<div class="opace-eseot-sources-wrapper" data-dismiss-label="<?php esc_attr_e( 'Dismiss this notice', 'opace-essential-seo-toolkit' ); ?>">
				<table class="opace-eseot-table opace-eseot-source-table wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col" class="column-primary column-name"><?php esc_html_e( 'Name', 'opace-essential-seo-toolkit' ); ?></th>
							<th scope="col" class="column-url"><?php esc_html_e( 'URL template', 'opace-essential-seo-toolkit' ); ?></th>
							<th scope="col" class="column-cat"><?php esc_html_e( 'Category', 'opace-essential-seo-toolkit' ); ?></th>
							<th scope="col" class="column-actions"><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'opace-essential-seo-toolkit' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $sources ) ) : ?>
							<tr class="opace-eseot-row opace-eseot-row--empty">
								<td colspan="4"><?php esc_html_e( 'No sources saved yet. Add one below.', 'opace-essential-seo-toolkit' ); ?></td>
							</tr>
						<?php endif; ?>
						<?php foreach ( $sources as $index => $source ) : ?>
							<?php
							$field   = 'eseot_sources[' . (int) $index . ']';
							$name_id = 'opace-eseot-source-name-' . (int) $index;
							$url_id  = 'opace-eseot-source-url-' . (int) $index;
							$cat_id  = 'opace-eseot-source-cat-' . (int) $index;
							?>
							<tr class="opace-eseot-row" data-index="<?php echo (int) $index; ?>">
								<td class="column-primary column-name">
									<label class="screen-reader-text" for="<?php echo esc_attr( $name_id ); ?>">
										<?php
										/* translators: %s: source name. */
										echo esc_html( sprintf( __( 'Name of source %s', 'opace-essential-seo-toolkit' ), $source['name'] ) );
										?>
									</label>
									<input type="text" id="<?php echo esc_attr( $name_id ); ?>" class="regular-text" name="<?php echo esc_attr( $field . '[name]' ); ?>" value="<?php echo esc_attr( $source['name'] ); ?>">
								</td>
								<td class="column-url">
									<label class="screen-reader-text" for="<?php echo esc_attr( $url_id ); ?>">
										<?php
										/* translators: %s: source name. */
										echo esc_html( sprintf( __( 'URL template for %s', 'opace-essential-seo-toolkit' ), $source['name'] ) );
										?>
									</label>
									<input type="url" id="<?php echo esc_attr( $url_id ); ?>" class="opace-eseot-source-url regular-text code" name="<?php echo esc_attr( $field . '[url]' ); ?>" value="<?php echo esc_attr( $source['url'] ); ?>" spellcheck="false">
								</td>
								<td class="column-cat">
									<label class="screen-reader-text" for="<?php echo esc_attr( $cat_id ); ?>">
										<?php
										/* translators: %s: source name. */
										echo esc_html( sprintf( __( 'Category for %s', 'opace-essential-seo-toolkit' ), $source['name'] ) );
										?>
									</label>
									<select id="<?php echo esc_attr( $cat_id ); ?>" name="<?php echo esc_attr( $field . '[cat]' ); ?>">
										<?php $this->category_options( $categories, (int) $source['cat'] ); ?>
									</select>
								</td>
								<td class="column-actions">
									<?php $this->render_remove_button( $source['name'], 'remove-source' ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			</div>

			<div class="opace-eseot-card">
				<h2><?php esc_html_e( 'Add a source', 'opace-essential-seo-toolkit' ); ?></h2>
				<div class="opace-eseot-add-row">
					<div class="opace-eseot-field">
						<label for="opace-eseot-source-name"><?php esc_html_e( 'Name', 'opace-essential-seo-toolkit' ); ?></label>
						<input type="text" id="opace-eseot-source-name" name="eseot_sources[new][name]" class="regular-text" autocomplete="off">
					</div>
					<div class="opace-eseot-field opace-eseot-field--url">
						<label for="opace-eseot-source-url"><?php esc_html_e( 'URL template', 'opace-essential-seo-toolkit' ); ?></label>
						<input type="url" id="opace-eseot-source-url" name="eseot_sources[new][url]" class="opace-eseot-source-url regular-text code" autocomplete="off" spellcheck="false"
							placeholder="https://example.com/check?url=[%url_encoded%]"
							data-preview-label="<?php esc_attr_e( 'Preview', 'opace-essential-seo-toolkit' ); ?>"
							data-invalid-text="<?php esc_attr_e( 'Unknown placeholder', 'opace-essential-seo-toolkit' ); ?>">
					</div>
					<div class="opace-eseot-field opace-eseot-field--cat">
						<label for="opace-eseot-source-cat"><?php esc_html_e( 'Category', 'opace-essential-seo-toolkit' ); ?></label>
						<select id="opace-eseot-source-cat" name="eseot_sources[new][cat]">
							<?php $this->category_options( $categories, array_key_first( $categories ) ); ?>
						</select>
					</div>
					<div class="opace-eseot-field opace-eseot-field--submit">
						<?php submit_button( null, 'primary', 'submit', false ); ?>
					</div>
				</div>
				<p class="description"><?php esc_html_e( 'Saving applies every change on this tab, including edits to the table above.', 'opace-essential-seo-toolkit' ); ?></p>
			</div>

			<div class="opace-eseot-card opace-eseot-card--quiet">
			<h3><?php esc_html_e( 'Placeholders', 'opace-essential-seo-toolkit' ); ?></h3>
			<p class="opace-eseot-intro"><?php esc_html_e( 'Placeholders are replaced with parts of the current permalink, shown here for https://www.example.com/blog/hello-world/', 'opace-essential-seo-toolkit' ); ?></p>
			<dl class="opace-eseot-placeholders">
				<?php foreach ( $this->placeholder_help() as $item ) : ?>
					<dt><code><?php echo esc_html( $item['token'] ); ?></code></dt>
					<dd><?php echo esc_html( $item['text'] ); ?><?php if ( '' !== $item['example'] ) : ?> <code><?php echo esc_html( $item['example'] ); ?></code><?php endif; ?></dd>
				<?php endforeach; ?>
			</dl>
			<p class="description"><?php esc_html_e( 'A URL must start with http:// or https:// and may only use the placeholders above.', 'opace-essential-seo-toolkit' ); ?></p>
			</div>
		</form>
		<?php
	}

	/**
	 * Categories tab.
	 */
	private function render_categories_tab(): void {
		$categories = $this->get_categories();
		$counts     = array();
		foreach ( $this->get_sources() as $source ) {
			$counts[ $source['cat'] ] = isset( $counts[ $source['cat'] ] ) ? $counts[ $source['cat'] ] + 1 : 1;
		}
		?>
		<form method="post" action="options.php" class="opace-eseot-form opace-eseot-form--categories">
			<?php settings_fields( self::GROUP_CATEGORIES ); ?>

			<div class="opace-eseot-card">
			<h2><?php esc_html_e( 'Categories', 'opace-essential-seo-toolkit' ); ?></h2>
			<p class="opace-eseot-intro"><?php esc_html_e( 'Categories group the sources shown in the editor meta box. Add, rename or remove them here, then assign sources to them on the Sources tab.', 'opace-essential-seo-toolkit' ); ?></p>

			<div class="opace-eseot-categories-wrapper" data-dismiss-label="<?php esc_attr_e( 'Dismiss this notice', 'opace-essential-seo-toolkit' ); ?>">
				<table class="opace-eseot-table opace-eseot-category-table wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col" class="column-id"><?php esc_html_e( 'ID', 'opace-essential-seo-toolkit' ); ?></th>
							<th scope="col" class="column-primary column-name"><?php esc_html_e( 'Name', 'opace-essential-seo-toolkit' ); ?></th>
							<th scope="col" class="column-count"><?php esc_html_e( 'Sources', 'opace-essential-seo-toolkit' ); ?></th>
							<th scope="col" class="column-actions"><span class="screen-reader-text"><?php esc_html_e( 'Remove', 'opace-essential-seo-toolkit' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $categories ) ) : ?>
							<tr class="opace-eseot-row opace-eseot-row--empty">
								<td colspan="4"><?php esc_html_e( 'No categories saved yet. Add one below.', 'opace-essential-seo-toolkit' ); ?></td>
							</tr>
						<?php endif; ?>
						<?php foreach ( $categories as $id => $name ) : ?>
							<?php $input_id = 'opace-eseot-category-' . (int) $id; ?>
							<tr class="opace-eseot-row" data-category="<?php echo (int) $id; ?>">
								<td class="column-id" data-label="<?php esc_attr_e( 'ID', 'opace-essential-seo-toolkit' ); ?>"><?php echo (int) $id; ?></td>
								<td class="column-primary column-name">
									<label class="screen-reader-text" for="<?php echo esc_attr( $input_id ); ?>">
										<?php
										/* translators: %s: category name. */
										echo esc_html( sprintf( __( 'Name of category %s', 'opace-essential-seo-toolkit' ), $name ) );
										?>
									</label>
									<input type="text" id="<?php echo esc_attr( $input_id ); ?>" class="regular-text" name="<?php echo esc_attr( 'eseot_categories[' . (int) $id . ']' ); ?>" value="<?php echo esc_attr( $name ); ?>">
								</td>
								<td class="column-count" data-label="<?php esc_attr_e( 'Sources', 'opace-essential-seo-toolkit' ); ?>"><?php echo isset( $counts[ $id ] ) ? (int) $counts[ $id ] : 0; ?></td>
								<td class="column-actions">
									<?php $this->render_remove_button( $name, 'remove-category' ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			</div>

			<div class="opace-eseot-card">
				<h2><?php esc_html_e( 'Add a category', 'opace-essential-seo-toolkit' ); ?></h2>
				<div class="opace-eseot-add-row">
					<div class="opace-eseot-field">
						<label for="opace-eseot-category-name"><?php esc_html_e( 'Name', 'opace-essential-seo-toolkit' ); ?></label>
						<input type="text" id="opace-eseot-category-name" name="eseot_categories[new]" class="regular-text" autocomplete="off">
					</div>
					<div class="opace-eseot-field opace-eseot-field--submit">
						<?php submit_button( null, 'primary', 'submit', false ); ?>
					</div>
				</div>
			</div>
		</form>
		<?php
	}

	/**
	 * Icon-only remove button with the data attributes the settings script reads.
	 *
	 * @param string $name   Item name.
	 * @param string $action remove-source or remove-category.
	 */
	private function render_remove_button( string $name, string $action ): void {
		/* translators: %s: name of the source or category. */
		$notice = sprintf( __( '%s will be removed when you save changes.', 'opace-essential-seo-toolkit' ), $name );
		?>
		<button type="button" class="button-link opace-eseot-remove"
			data-action="<?php echo esc_attr( $action ); ?>"
			data-name="<?php echo esc_attr( $name ); ?>"
			data-notice="<?php echo esc_attr( $notice ); ?>"
			aria-label="<?php echo esc_attr( $this->remove_label( $name ) ); ?>">
			<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
		</button>
		<?php
	}

	/**
	 * Post types tab.
	 */
	private function render_post_types_tab(): void {
		$enabled = get_option( 'eseot_post_types', Opace_ESEOT_Defaults::post_types() );
		$enabled = is_array( $enabled ) ? $enabled : array();
		?>
		<form method="post" action="options.php" class="opace-eseot-form opace-eseot-form--post-types">
			<?php settings_fields( self::GROUP_POST_TYPES ); ?>

			<div class="opace-eseot-card">
			<h2><?php esc_html_e( 'Post types', 'opace-essential-seo-toolkit' ); ?></h2>
			<p class="opace-eseot-intro"><?php esc_html_e( 'Tick the post types that should show the saved-tools launcher and the page audit panel in the editor. Only public post types registered on this site are listed.', 'opace-essential-seo-toolkit' ); ?></p>

			<fieldset class="opace-eseot-post-types opace-eseot-options">
				<legend class="screen-reader-text"><?php esc_html_e( 'Post types', 'opace-essential-seo-toolkit' ); ?></legend>
				<?php foreach ( $this->post_type_labels() as $slug => $label ) : ?>
					<?php $input_id = 'opace-eseot-post-type-' . $slug; ?>
					<div class="opace-eseot-option">
						<input type="checkbox" id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( 'eseot_post_types[' . $slug . ']' ); ?>" value="1" <?php checked( ! empty( $enabled[ $slug ] ) ); ?>>
						<label for="<?php echo esc_attr( $input_id ); ?>" class="opace-eseot-post-type"><?php echo esc_html( $label ); ?> <code><?php echo esc_html( $slug ); ?></code></label>
					</div>
				<?php endforeach; ?>
			</fieldset>

			<?php submit_button(); ?>
			</div>
		</form>
		<?php
	}

	/**
	 * Audit tab: autorun, engines and the list-table column.
	 */
	private function render_audit_tab(): void {
		$audit = $this->get_audit_settings();
		?>
		<form method="post" action="options.php" class="opace-eseot-form opace-eseot-form--audit">
			<?php settings_fields( self::GROUP_AUDIT ); ?>

			<div class="opace-eseot-card">
			<h2><?php esc_html_e( 'Page audit', 'opace-essential-seo-toolkit' ); ?></h2>
			<p class="opace-eseot-intro"><?php esc_html_e( 'How the audit runs in the editor and on the Page audit screen. Everything runs in your own browser against this site.', 'opace-essential-seo-toolkit' ); ?></p>

			<fieldset class="opace-eseot-audit-options opace-eseot-options">
				<legend class="screen-reader-text"><?php esc_html_e( 'Page audit', 'opace-essential-seo-toolkit' ); ?></legend>
				<div class="opace-eseot-option">
					<input type="checkbox" id="opace-eseot-audit-autorun" name="eseot_audit[autorun]" value="1" <?php checked( ! empty( $audit['autorun'] ) ); ?> aria-describedby="opace-eseot-audit-autorun-help">
					<label for="opace-eseot-audit-autorun" class="opace-eseot-audit-option"><?php esc_html_e( 'Run the page audit automatically when a post opens', 'opace-essential-seo-toolkit' ); ?></label>
					<p class="description" id="opace-eseot-audit-autorun-help"><?php esc_html_e( 'Loads the page in a frame as soon as the editor opens. Leave this off to keep editing fast on large pages and run the audit on demand.', 'opace-essential-seo-toolkit' ); ?></p>
				</div>
				<div class="opace-eseot-option">
					<input type="checkbox" id="opace-eseot-audit-engine-axe" name="eseot_audit[engine_axe]" value="1" <?php checked( ! empty( $audit['engine_axe'] ) ); ?> aria-describedby="opace-eseot-audit-engine-axe-help">
					<label for="opace-eseot-audit-engine-axe" class="opace-eseot-audit-option"><?php esc_html_e( 'Run the accessibility engine (axe-core) during audits', 'opace-essential-seo-toolkit' ); ?></label>
					<p class="description" id="opace-eseot-audit-engine-axe-help"><?php esc_html_e( 'Checks the audited page against axe-core rules and lists any violations under Local engines.', 'opace-essential-seo-toolkit' ); ?></p>
				</div>
				<div class="opace-eseot-option">
					<input type="checkbox" id="opace-eseot-audit-engine-vitals" name="eseot_audit[engine_vitals]" value="1" <?php checked( ! empty( $audit['engine_vitals'] ) ); ?> aria-describedby="opace-eseot-audit-engine-vitals-help">
					<label for="opace-eseot-audit-engine-vitals" class="opace-eseot-audit-option"><?php esc_html_e( 'Capture Web Vitals during audits', 'opace-essential-seo-toolkit' ); ?></label>
					<p class="description" id="opace-eseot-audit-engine-vitals-help"><?php esc_html_e( 'Records LCP, CLS, INP, FCP and TTFB for the audit visit. A local snapshot, not field data or a ranking score.', 'opace-essential-seo-toolkit' ); ?></p>
				</div>
			</fieldset>
			</div>

			<div class="opace-eseot-card">
			<h2><?php esc_html_e( 'List tables', 'opace-essential-seo-toolkit' ); ?></h2>
			<p class="opace-eseot-intro"><?php esc_html_e( 'Show the SEO audit column, with the last stored result, on these list tables. Each user can still hide it under Screen Options.', 'opace-essential-seo-toolkit' ); ?></p>

			<fieldset class="opace-eseot-post-types opace-eseot-audit-column-types opace-eseot-options">
				<legend class="screen-reader-text"><?php esc_html_e( 'Show the SEO audit column on these list tables', 'opace-essential-seo-toolkit' ); ?></legend>
				<input type="hidden" name="eseot_audit[column_post_types_set]" value="1">
				<?php foreach ( $this->post_type_labels() as $slug => $label ) : ?>
					<?php $input_id = 'opace-eseot-audit-column-' . $slug; ?>
					<div class="opace-eseot-option">
						<input type="checkbox" id="<?php echo esc_attr( $input_id ); ?>" name="eseot_audit[column_post_types][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $audit['column_post_types'], true ) ); ?>>
						<label for="<?php echo esc_attr( $input_id ); ?>" class="opace-eseot-post-type"><?php echo esc_html( $label ); ?> <code><?php echo esc_html( $slug ); ?></code></label>
					</div>
				<?php endforeach; ?>
			</fieldset>

			<?php submit_button(); ?>
			</div>
		</form>
		<?php
	}

	/**
	 * Echo <option> tags for a category select.
	 *
	 * A source whose category no longer exists keeps its stored id as a selected
	 * "Other tools" option, so saving does not silently move it.
	 *
	 * @param array<int, string> $categories Categories.
	 * @param int|null           $selected   Selected id.
	 */
	private function category_options( array $categories, ?int $selected ): void {
		if ( empty( $categories ) ) {
			printf( '<option value="0">%s</option>', esc_html__( 'No categories yet', 'opace-essential-seo-toolkit' ) );
			return;
		}

		if ( null !== $selected && ! isset( $categories[ $selected ] ) ) {
			printf(
				'<option value="%d" selected>%s</option>',
				(int) $selected,
				esc_html__( 'Other tools (category removed)', 'opace-essential-seo-toolkit' )
			);
		}

		foreach ( $categories as $id => $name ) {
			printf(
				'<option value="%d"%s>%s</option>',
				(int) $id,
				selected( $selected, (int) $id, false ),
				esc_html( $name )
			);
		}
	}

	/**
	 * Accessible label for a remove button.
	 *
	 * @param string $name Item name.
	 * @return string
	 */
	private function remove_label( string $name ): string {
		/* translators: %s: name of the source or category. */
		return sprintf( __( 'Remove %s', 'opace-essential-seo-toolkit' ), $name );
	}

	/**
	 * Placeholder help lines from the copy deck.
	 *
	 * The percent-encoded example is kept out of the translatable string so the
	 * "%2F" sequences are not mistaken for sprintf placeholders.
	 *
	 * @return array<int, array{token: string, text: string, example: string}>
	 */
	private function placeholder_help(): array {
		return array(
			array(
				'token'   => '[%url%]',
				'text'    => __( 'The full permalink, for example https://www.example.com/blog/hello-world/', 'opace-essential-seo-toolkit' ),
				'example' => '',
			),
			array(
				'token'   => '[%url_encoded%]',
				'text'    => __( 'The permalink percent-encoded for use in a query string, for example', 'opace-essential-seo-toolkit' ),
				'example' => 'https%3A%2F%2Fwww.example.com%2Fblog%2Fhello-world%2F',
			),
			array(
				'token'   => '[%host%]',
				'text'    => __( 'The host with any leading www. removed, for example example.com', 'opace-essential-seo-toolkit' ),
				'example' => '',
			),
			array(
				'token'   => '[%host_encoded%]',
				'text'    => __( 'The host percent-encoded for use in a query string', 'opace-essential-seo-toolkit' ),
				'example' => '',
			),
			array(
				'token'   => '[%scheme%]',
				'text'    => __( 'The scheme and separator, for example https://', 'opace-essential-seo-toolkit' ),
				'example' => '',
			),
			array(
				'token'   => '[%path%]',
				'text'    => __( 'The path plus the query string when there is one, for example /blog/hello-world/', 'opace-essential-seo-toolkit' ),
				'example' => '',
			),
		);
	}
}
