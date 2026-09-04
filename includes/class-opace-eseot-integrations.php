<?php
/**
 * WordPress-native surfaces: list-table row actions, bulk action and column,
 * admin bar node and dashboard widget.
 *
 * @package Opace_Essential_SEO_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything here links into Essential SEO Toolkit → Page audit (admin.php?page=opace-eseot) or
 * reads stored summaries from Opace_ESEOT_Audit_Store. Nothing renders for
 * users without edit_posts.
 */
final class Opace_ESEOT_Integrations {

	const TOOLS_PAGE_SLUG = 'opace-eseot';

	const COLUMN_KEY  = 'opace_eseot_audit';
	const BULK_ACTION = 'opace_eseot_audit';
	const ORDERBY     = 'opace_eseot_audit';

	const ADMIN_BAR_ID = 'opace-eseot';
	const WIDGET_ID    = 'opace_eseot_dashboard';
	const STYLE_HANDLE = 'opace-eseot-admin';

	const MAX_BULK_IDS        = 25;
	const MAX_ADMIN_BAR_TOOLS = 20;

	/**
	 * Whether init() has run.
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * Register hooks. Safe to call more than once.
	 */
	public static function init(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;

		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( 'page_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );

		if ( did_action( 'admin_init' ) ) {
			self::register_list_table_hooks();
		} else {
			add_action( 'admin_init', array( __CLASS__, 'register_list_table_hooks' ) );
		}
		add_action( 'pre_get_posts', array( __CLASS__, 'sort_by_audit_time' ) );

		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar_menu' ), 90 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_admin_bar_style' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_bar_style' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );

		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register_dashboard_widget' ) );
	}

	/* ---------------------------------------------------------------------
	 * Shared helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Post types the toolkit is enabled for.
	 *
	 * @return string[]
	 */
	private static function supported_types(): array {
		if ( ! class_exists( 'Opace_ESEOT_Plugin' ) ) {
			return array();
		}

		return Opace_ESEOT_Plugin::instance()->get_supported_post_types();
	}

	/**
	 * URL of Essential SEO Toolkit → Page audit with optional query arguments.
	 *
	 * A url argument is rawurlencoded; other arguments (ids, autorun, view,
	 * truncated) are passed through as given.
	 *
	 * @param array $args Query arguments.
	 * @return string
	 */
	public static function tools_url( array $args = array() ): string {
		$query = array( 'page' => self::TOOLS_PAGE_SLUG );

		if ( isset( $args['url'] ) ) {
			$query['url'] = rawurlencode( (string) $args['url'] );
			unset( $args['url'] );
		}

		foreach ( $args as $key => $value ) {
			$query[ sanitize_key( (string) $key ) ] = is_scalar( $value ) ? (string) $value : '';
		}

		return add_query_arg( $query, admin_url( 'admin.php' ) );
	}

	/**
	 * The URL the audit should load for a post: the permalink when published,
	 * otherwise the preview link. Empty when neither is available.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function audit_target( WP_Post $post ): string {
		if ( 'auto-draft' === $post->post_status ) {
			return '';
		}

		$link = ( 'publish' === $post->post_status ) ? get_permalink( $post ) : get_preview_post_link( $post );

		return is_string( $link ) ? $link : '';
	}

	/**
	 * Post title with a fallback for untitled posts.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function post_label( WP_Post $post ): string {
		$title = get_the_title( $post );
		if ( ! is_string( $title ) || '' === trim( $title ) ) {
			return __( '(no title)', 'opace-essential-seo-toolkit' );
		}

		return $title;
	}

	/**
	 * "2 hours ago" for a GMT unix time, or an empty string.
	 *
	 * @param int $time GMT unix time.
	 * @return string
	 */
	private static function time_ago( int $time ): string {
		if ( $time <= 0 ) {
			return '';
		}

		/* translators: %s: human-readable time difference, for example "2 hours". */
		return sprintf( __( '%s ago', 'opace-essential-seo-toolkit' ), human_time_diff( $time, time() ) );
	}

	/**
	 * Absolute date and time in the site's format and timezone.
	 *
	 * @param int $time GMT unix time.
	 * @return string
	 */
	private static function absolute_time( int $time ): string {
		if ( $time <= 0 ) {
			return '';
		}
		$format = trim( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ) );
		$date   = wp_date( '' !== $format ? $format : 'Y-m-d H:i', $time );

		return is_string( $date ) ? $date : '';
	}

	/* ---------------------------------------------------------------------
	 * Row actions
	 * ------------------------------------------------------------------ */

	/**
	 * "Audit page" and "SEO tools" row actions on enabled list tables.
	 *
	 * @param array   $actions Existing row actions.
	 * @param WP_Post $post    Post for the row.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( ! is_array( $actions ) || ! $post instanceof WP_Post ) {
			return $actions;
		}
		if ( 'trash' === $post->post_status || ! in_array( $post->post_type, self::supported_types(), true ) ) {
			return $actions;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$target = self::audit_target( $post );
		if ( '' === $target ) {
			return $actions;
		}

		$label = self::post_label( $post );

		$actions['opace_eseot_audit'] = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url(
				self::tools_url(
					array(
						'url'     => $target,
						'autorun' => 1,
					)
				)
			),
			/* translators: %s: post title. */
			esc_attr( sprintf( __( 'Run a page audit for %s', 'opace-essential-seo-toolkit' ), $label ) ),
			esc_html__( 'Audit page', 'opace-essential-seo-toolkit' )
		);

		$actions['opace_eseot_tools'] = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url(
				self::tools_url(
					array(
						'url'  => $target,
						'view' => 'tools',
					)
				)
			),
			/* translators: %s: post title. */
			esc_attr( sprintf( __( 'Open saved SEO tools for %s', 'opace-essential-seo-toolkit' ), $label ) ),
			esc_html__( 'SEO tools', 'opace-essential-seo-toolkit' )
		);

		return $actions;
	}

	/* ---------------------------------------------------------------------
	 * Bulk action and column (per post type)
	 * ------------------------------------------------------------------ */

	/**
	 * Attach the per-post-type list-table hooks. Hooked to admin_init.
	 */
	public static function register_list_table_hooks(): void {
		foreach ( self::supported_types() as $type ) {
			add_filter( 'bulk_actions-edit-' . $type, array( __CLASS__, 'add_bulk_action' ) );
			add_filter( 'handle_bulk_actions-edit-' . $type, array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
			add_filter( 'manage_' . $type . '_posts_columns', array( __CLASS__, 'add_column' ) );
			add_action( 'manage_' . $type . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
			add_filter( 'manage_edit-' . $type . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
		}
	}

	/**
	 * Add "Run page audit" to the bulk actions dropdown.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public static function add_bulk_action( $actions ) {
		if ( ! is_array( $actions ) ) {
			return $actions;
		}
		$actions[ self::BULK_ACTION ] = __( 'Run page audit', 'opace-essential-seo-toolkit' );

		return $actions;
	}

	/**
	 * Send the selected, editable posts to the Page audit screen as ids=1,2,3.
	 *
	 * More than 25 ids are dropped and truncated=1 is added.
	 *
	 * @param string $redirect_to Redirect URL chosen by WordPress.
	 * @param string $doaction    Bulk action slug.
	 * @param array  $post_ids    Selected post IDs.
	 * @return string
	 */
	public static function handle_bulk_action( $redirect_to, $doaction, $post_ids ) {
		if ( self::BULK_ACTION !== $doaction || ! is_array( $post_ids ) ) {
			return $redirect_to;
		}

		$supported = self::supported_types();
		$ids       = array();

		foreach ( $post_ids as $id ) {
			$id = absint( $id );
			if ( 0 === $id || in_array( $id, $ids, true ) ) {
				continue;
			}
			$post = get_post( $id );
			if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, $supported, true ) ) {
				continue;
			}
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$ids[] = $id;
		}

		if ( empty( $ids ) ) {
			return $redirect_to;
		}

		$args = array();
		if ( count( $ids ) > self::MAX_BULK_IDS ) {
			$ids               = array_slice( $ids, 0, self::MAX_BULK_IDS );
			$args['truncated'] = 1;
		}
		$args['ids'] = implode( ',', $ids );

		return self::tools_url( $args );
	}

	/**
	 * Insert the "SEO audit" column after the title.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		if ( ! is_array( $columns ) || isset( $columns[ self::COLUMN_KEY ] ) ) {
			return $columns;
		}

		$label    = __( 'SEO audit', 'opace-essential-seo-toolkit' );
		$output   = array();
		$inserted = false;

		foreach ( $columns as $key => $value ) {
			$output[ $key ] = $value;
			if ( 'title' === $key ) {
				$output[ self::COLUMN_KEY ] = $label;
				$inserted                   = true;
			}
		}
		if ( ! $inserted ) {
			$output[ self::COLUMN_KEY ] = $label;
		}

		return $output;
	}

	/**
	 * Column cell: counts and time ago, or "Not audited" with an "Audit now" link.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function render_column( $column, $post_id ): void {
		if ( self::COLUMN_KEY !== $column ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$summary = Opace_ESEOT_Audit_Store::get_summary( (int) $post->ID );

		if ( null === $summary ) {
			echo '<span class="opace-eseot-audit-col opace-eseot-audit-col--none">' . esc_html__( 'Not audited', 'opace-essential-seo-toolkit' ) . '</span>';

			$target = self::audit_target( $post );
			if ( '' !== $target && current_user_can( 'edit_post', $post->ID ) ) {
				printf(
					'<br><a class="opace-eseot-audit-col__link" href="%s">%s</a>',
					esc_url(
						self::tools_url(
							array(
								'url'     => $target,
								'autorun' => 1,
							)
						)
					),
					esc_html__( 'Audit now', 'opace-essential-seo-toolkit' )
				);
			}
			return;
		}

		$time = Opace_ESEOT_Audit_Store::get_time( (int) $post->ID );

		printf(
			'<span class="opace-eseot-audit-col opace-eseot-audit-col--done">%1$s</span><br><span class="opace-eseot-audit-col__time" title="%2$s">%3$s</span>',
			Opace_ESEOT_Audit_Store::format_counts_html( $summary ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside format_counts_html().
			esc_attr( self::absolute_time( $time ) ),
			esc_html( self::time_ago( $time ) )
		);
	}

	/**
	 * Make the column sortable (newest audit first on the first click).
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public static function sortable_columns( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}
		$columns[ self::COLUMN_KEY ] = array( self::ORDERBY, true );

		return $columns;
	}

	/**
	 * Order an enabled list table by audit time. Posts that were never audited
	 * stay in the list (NOT EXISTS clause) and sort last when descending.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function sort_by_audit_time( $query ): void {
		if ( ! $query instanceof WP_Query || ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( self::ORDERBY !== $query->get( 'orderby' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen instanceof WP_Screen || 'edit' !== $screen->base ) {
			return;
		}
		if ( ! in_array( (string) $screen->post_type, self::supported_types(), true ) ) {
			return;
		}

		$ours = array(
			'relation'                => 'OR',
			'opace_eseot_audit_time'  => array(
				'key'     => Opace_ESEOT_Audit_Store::TIME_META_KEY,
				'compare' => 'EXISTS',
				'type'    => 'NUMERIC',
			),
			'opace_eseot_audit_never' => array(
				'key'     => Opace_ESEOT_Audit_Store::TIME_META_KEY,
				'compare' => 'NOT EXISTS',
			),
		);

		$existing = $query->get( 'meta_query' );
		if ( is_array( $existing ) && ! empty( $existing ) ) {
			$ours = array(
				'relation' => 'AND',
				$existing,
				$ours,
			);
		}

		$query->set( 'meta_query', $ours ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- only when the user sorts an admin list table by this column.
		$query->set( 'orderby', 'opace_eseot_audit_time' );
	}

	/* ---------------------------------------------------------------------
	 * Admin bar
	 * ------------------------------------------------------------------ */

	/**
	 * "SEO Toolkit" node with "Audit this page" and the saved tools for the current URL.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar.
	 */
	public static function admin_bar_menu( $wp_admin_bar ): void {
		if ( ! $wp_admin_bar instanceof WP_Admin_Bar || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$mark  = plugins_url( 'assets/images/opace-eseot-mark.svg', OPACE_ESEOT_FILE );
		$title = sprintf(
			'<img class="opace-eseot-adminbar__mark" src="%s" alt="" width="16" height="16" aria-hidden="true"><span class="ab-label">%s</span>',
			esc_url( $mark ),
			esc_html__( 'SEO Toolkit', 'opace-essential-seo-toolkit' )
		);

		$wp_admin_bar->add_node(
			array(
				'id'    => self::ADMIN_BAR_ID,
				'title' => $title,
				'href'  => self::tools_url(),
				'meta'  => array(
					'class' => 'opace-eseot-adminbar',
					'title' => __( 'Essential SEO Toolkit', 'opace-essential-seo-toolkit' ),
				),
			)
		);

		$url = self::current_audit_url();
		if ( '' === $url ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'     => self::ADMIN_BAR_ID . '-audit',
				'parent' => self::ADMIN_BAR_ID,
				'title'  => esc_html__( 'Audit this page', 'opace-essential-seo-toolkit' ),
				'href'   => self::tools_url(
					array(
						'url'     => $url,
						'autorun' => 1,
					)
				),
			)
		);

		$groups = self::resolved_tools( $url );
		if ( empty( $groups ) ) {
			return;
		}

		$wp_admin_bar->add_group(
			array(
				'id'     => self::ADMIN_BAR_ID . '-tools',
				'parent' => self::ADMIN_BAR_ID,
				'meta'   => array( 'class' => 'ab-sub-secondary' ),
			)
		);

		$count = 0;
		foreach ( $groups as $key => $group ) {
			if ( $count >= self::MAX_ADMIN_BAR_TOOLS ) {
				break;
			}
			$category_id = self::ADMIN_BAR_ID . '-cat-' . $key;
			$wp_admin_bar->add_node(
				array(
					'id'     => $category_id,
					'parent' => self::ADMIN_BAR_ID . '-tools',
					'title'  => esc_html( $group['name'] ),
				)
			);
			foreach ( $group['tools'] as $index => $tool ) {
				if ( $count >= self::MAX_ADMIN_BAR_TOOLS ) {
					break;
				}
				$wp_admin_bar->add_node(
					array(
						'id'     => $category_id . '-' . (int) $index,
						'parent' => $category_id,
						'title'  => esc_html( $tool['name'] ),
						'href'   => $tool['href'],
						'meta'   => array(
							'target' => '_blank',
							'rel'    => 'noopener noreferrer',
						),
					)
				);
				++$count;
			}
		}
	}

	/**
	 * The URL "Audit this page" should target for the current request.
	 *
	 * Front end: the requested URL, when it is on this site. Admin post.php:
	 * the post's audit target. Anywhere else: empty.
	 *
	 * @return string
	 */
	private static function current_audit_url(): string {
		if ( is_admin() ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! $screen instanceof WP_Screen || 'post' !== $screen->base ) {
				return '';
			}
			$post = get_post();
			if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, self::supported_types(), true ) ) {
				return '';
			}
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				return '';
			}

			return self::audit_target( $post );
		}

		if ( empty( $_SERVER['HTTP_HOST'] ) || empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$host = sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) );
		$path = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		if ( '' === $host || '' === $path || '/' !== substr( $path, 0, 1 ) ) {
			return '';
		}

		$url = ( is_ssl() ? 'https://' : 'http://' ) . $host . $path;

		return Opace_ESEOT_Audit_Store::is_site_url( $url ) ? esc_url_raw( $url ) : '';
	}

	/**
	 * Saved tools resolved for a URL, grouped by category in settings order.
	 *
	 * @param string $url URL to resolve against.
	 * @return array<string, array{name: string, tools: array<int, array{name: string, href: string}>}>
	 */
	private static function resolved_tools( string $url ): array {
		if ( ! class_exists( 'Opace_ESEOT_Plugin' ) || ! class_exists( 'Opace_ESEOT_Link_Resolver' ) ) {
			return array();
		}

		$settings   = Opace_ESEOT_Plugin::instance()->settings();
		$categories = $settings->get_categories();
		$by_cat     = array();

		foreach ( $settings->get_sources() as $source ) {
			$href = Opace_ESEOT_Link_Resolver::resolve( $source['url'], $url );
			if ( false === $href ) {
				continue;
			}
			$key              = isset( $categories[ $source['cat'] ] ) ? (string) $source['cat'] : 'other';
			$by_cat[ $key ][] = array(
				'name' => $source['name'],
				'href' => $href,
			);
		}

		$groups = array();
		foreach ( $categories as $id => $name ) {
			if ( ! empty( $by_cat[ (string) $id ] ) ) {
				$groups[ (string) $id ] = array(
					'name'  => $name,
					'tools' => $by_cat[ (string) $id ],
				);
			}
		}
		if ( ! empty( $by_cat['other'] ) ) {
			$groups['other'] = array(
				'name'  => __( 'Other tools', 'opace-essential-seo-toolkit' ),
				'tools' => $by_cat['other'],
			);
		}

		return $groups;
	}

	/**
	 * Inline rule for the mark inside the admin bar node (front end and admin).
	 */
	public static function enqueue_admin_bar_style(): void {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		wp_add_inline_style(
			'admin-bar',
			'#wpadminbar .opace-eseot-adminbar__mark{display:inline-block;width:16px;height:16px;margin:-2px 6px 0 0;vertical-align:middle}'
		);
	}

	/* ---------------------------------------------------------------------
	 * Dashboard widget and admin styles
	 * ------------------------------------------------------------------ */

	/**
	 * Register the widget. Hooked to wp_dashboard_setup.
	 */
	public static function register_dashboard_widget(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'Essential SEO Toolkit', 'opace-essential-seo-toolkit' ),
			array( __CLASS__, 'render_dashboard_widget' )
		);
	}

	/**
	 * Last five audited pages and an "Audit home page" button.
	 */
	public static function render_dashboard_widget(): void {
		$rows = Opace_ESEOT_Audit_Store::recent( 5 );
		?>
		<div class="opace-eseot-dashboard">
			<?php if ( empty( $rows ) ) : ?>
				<p class="opace-eseot-dashboard__empty"><?php esc_html_e( 'No pages audited yet. Open a post and run the page audit, or audit the home page.', 'opace-essential-seo-toolkit' ); ?></p>
			<?php else : ?>
				<ul class="opace-eseot-dashboard__list">
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$label = '' !== $row['title'] ? $row['title'] : $row['url'];
						if ( $row['post_id'] > 0 && '' === $row['title'] ) {
							$label = __( '(no title)', 'opace-essential-seo-toolkit' );
						}

						$href = '';
						if ( $row['post_id'] > 0 && current_user_can( 'edit_post', $row['post_id'] ) ) {
							$href = (string) get_edit_post_link( $row['post_id'], 'raw' );
						}
						if ( '' === $href ) {
							$href = self::tools_url( array( 'url' => $row['url'] ) );
						}
						?>
						<li class="opace-eseot-dashboard__row">
							<a class="opace-eseot-dashboard__title" href="<?php echo esc_url( $href ); ?>"><?php echo esc_html( $label ); ?></a>
							<span class="opace-eseot-dashboard__counts"><?php echo Opace_ESEOT_Audit_Store::format_counts_html( $row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by format_counts_html(). ?></span>
							<?php if ( $row['time'] > 0 ) : ?>
								<span class="opace-eseot-dashboard__time" title="<?php echo esc_attr( self::absolute_time( (int) $row['time'] ) ); ?>"><?php echo esc_html( self::time_ago( (int) $row['time'] ) ); ?></span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<p class="opace-eseot-dashboard__actions">
				<a class="button button-primary" href="<?php echo esc_url( self::tools_url( array( 'url' => home_url( '/' ), 'autorun' => 1 ) ) ); ?>"><?php esc_html_e( 'Audit home page', 'opace-essential-seo-toolkit' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Column and widget styles, only on enabled list tables and the dashboard.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_admin_assets( $hook_suffix = '' ): void {
		$hook_suffix = is_string( $hook_suffix ) ? $hook_suffix : '';
		$load        = false;

		if ( 'index.php' === $hook_suffix ) {
			$load = true;
		} elseif ( 'edit.php' === $hook_suffix ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$load   = $screen instanceof WP_Screen && in_array( (string) $screen->post_type, self::supported_types(), true );
		}

		if ( ! $load ) {
			return;
		}

		wp_enqueue_style(
			self::STYLE_HANDLE,
			plugins_url( 'assets/css/opace-eseot-admin.css', OPACE_ESEOT_FILE ),
			array(),
			OPACE_ESEOT_VERSION
		);
	}
}
