<?php
/**
 * Essential SEO Toolkit → Page audit: audit any address on this site, one or many.
 *
 * @package Opace_Essential_SEO_Toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * admin.php?page=opace-eseot, the screen the top-level menu item opens.
 * Before 2.0.0 this was tools.php?page=opace-eseot-audit, which redirects here.
 *
 * Anyone who can edit_posts may open it (the row actions, bulk action and
 * dashboard button all lead here); bulk lists only include posts the current
 * user can edit_post.
 *
 * Query arguments (all optional, all read-only):
 * - url=…        absolute same-site URL or a path such as /services/
 * - autorun=1    start the audit as soon as the panel loads
 * - view=tools   open the Saved tools view first
 * - ids=1,2,3    bulk audit of posts the user can edit (first 25)
 * - truncated=1  show that a longer selection was cut to 25
 */
final class Opace_ESEOT_Audit_Page {

	/**
	 * Same as the top-level menu slug: the parent item opens this screen.
	 */
	const MENU_SLUG = 'opace-eseot';

	/**
	 * Slug before 2.0.0 (tools.php?page=opace-eseot-audit).
	 */
	const LEGACY_MENU_SLUG = 'opace-eseot-audit';

	const MAX_BULK = 25;

	/**
	 * Hook suffix of the top-level page (toplevel_page_opace-eseot).
	 *
	 * @var string
	 */
	private string $page_hook = '';

	/**
	 * Audit plumbing (container rendering, targets).
	 *
	 * @var Opace_ESEOT_Audit
	 */
	private Opace_ESEOT_Audit $audit;

	/**
	 * Constructor.
	 *
	 * @param Opace_ESEOT_Audit $audit Audit object.
	 */
	public function __construct( Opace_ESEOT_Audit $audit ) {
		$this->audit = $audit;
	}

	/**
	 * Register "Page audit" as the first submenu of the top-level menu. Called
	 * by Opace_ESEOT_Settings::register_menu() right after add_menu_page().
	 *
	 * The submenu reuses the parent slug and callback, so WordPress shows one
	 * entry and fires the render callback once.
	 *
	 * @param string $parent_slug Top-level menu slug.
	 * @param string $parent_hook Hook suffix returned by add_menu_page().
	 */
	public function register_menu( string $parent_slug, string $parent_hook = '' ): void {
		$hook = add_submenu_page(
			$parent_slug,
			__( 'Page audit', 'opace-essential-seo-toolkit' ),
			__( 'Page audit', 'opace-essential-seo-toolkit' ),
			'edit_posts',
			$parent_slug,
			array( $this, 'render_page' )
		);

		$this->page_hook = is_string( $hook ) && '' !== $hook ? $hook : $parent_hook;
	}

	/**
	 * Hook suffix of the Page audit screen, for targeted enqueues.
	 */
	public function page_hook(): string {
		return '' !== $this->page_hook ? $this->page_hook : 'toplevel_page_' . self::MENU_SLUG;
	}

	/**
	 * URL of the Page audit screen with optional arguments (url, autorun, view, ids, truncated).
	 * Values are encoded, so a full URL can be passed as url.
	 *
	 * @param array<string, scalar> $args Query arguments.
	 * @return string
	 */
	public static function page_url( array $args = array() ): string {
		$query = array( 'page' => self::MENU_SLUG );
		foreach ( $args as $key => $value ) {
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$query[ sanitize_key( (string) $key ) ] = rawurlencode( (string) $value );
			}
		}

		return add_query_arg( $query, admin_url( 'admin.php' ) );
	}

	/**
	 * Read one query argument as a plain string. The page only chooses which
	 * same-site address the panel audits; nothing is written, so no nonce.
	 *
	 * @param string $key Argument name.
	 * @return string
	 */
	private function query_arg( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: selects what the panel shows, nothing is stored.
		return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
	}

	/**
	 * Turn user input into an absolute same-site URL, or '' when it is not one.
	 *
	 * @param string $input URL or path.
	 * @return string
	 */
	public static function normalise_url( string $input ): string {
		$input = trim( $input );
		if ( '' === $input ) {
			return '';
		}
		if ( '/' === $input[0] && ( ! isset( $input[1] ) || '/' !== $input[1] ) ) {
			$input = home_url( $input );
		}

		$url = esc_url_raw( $input );

		return ( '' !== $url && Opace_ESEOT_Audit::is_same_site_url( $url ) ) ? $url : '';
	}

	/**
	 * Render the page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'opace-essential-seo-toolkit' ) );
		}

		$raw_url   = $this->query_arg( 'url' );
		$autorun   = '1' === $this->query_arg( 'autorun' );
		$view      = 'tools' === $this->query_arg( 'view' ) ? 'tools' : 'audit';
		$truncated = '1' === $this->query_arg( 'truncated' );
		$ids       = $this->parse_ids( $this->query_arg( 'ids' ) );
		$mark      = plugins_url( 'assets/images/opace-eseot-mark.svg', OPACE_ESEOT_FILE );

		$target  = '';
		$targets = array();
		$mode    = 'single';

		if ( ! empty( $ids ) ) {
			$mode    = 'bulk';
			$targets = $this->bulk_targets( $ids );
			if ( empty( $targets ) ) {
				add_settings_error( 'opace_eseot_audit_page', 'opace_eseot_audit_no_targets', __( 'None of the selected posts can be audited yet. Publish or save them first.', 'opace-essential-seo-toolkit' ), 'error' );
				$mode = 'single';
			}
		} elseif ( '' !== $raw_url ) {
			$target = self::normalise_url( $raw_url );
			if ( '' === $target ) {
				add_settings_error(
					'opace_eseot_audit_page',
					'opace_eseot_audit_invalid_url',
					sprintf(
						/* translators: %s: this site's home address. */
						__( 'Enter an address on this site, for example %s. Other sites cannot be audited from here.', 'opace-essential-seo-toolkit' ),
						home_url( '/' )
					),
					'error'
				);
				$autorun = false;
			}
		} else {
			$target = home_url( '/' );
		}
		?>
		<div class="wrap opace-eseot-audit-page">
			<h1 class="opace-eseot-settings__title">
				<img class="opace-eseot-settings__mark" src="<?php echo esc_url( $mark ); ?>" alt="" width="32" height="32">
				<?php esc_html_e( 'Page audit', 'opace-essential-seo-toolkit' ); ?>
			</h1>
			<p class="opace-eseot-intro"><?php esc_html_e( 'Audit any address on this site from your own browser: the page is loaded here, checked locally, and nothing is sent anywhere else.', 'opace-essential-seo-toolkit' ); ?></p>

			<?php settings_errors( 'opace_eseot_audit_page' ); ?>

			<?php if ( $truncated ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php
						printf(
							/* translators: %d: maximum number of posts in one bulk audit. */
							esc_html__( 'Only the first %d selected posts were kept for this audit.', 'opace-essential-seo-toolkit' ),
							(int) self::MAX_BULK
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="opace-eseot-card opace-eseot-audit-page__card">
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="opace-eseot-audit-page__form">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
					<input type="hidden" name="autorun" value="1">
					<label for="opace-eseot-audit-url"><?php esc_html_e( 'Address to audit', 'opace-essential-seo-toolkit' ); ?></label>
					<input type="url" id="opace-eseot-audit-url" name="url" class="regular-text code" value="<?php echo esc_attr( '' !== $target && 'bulk' !== $mode ? $target : $raw_url ); ?>" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" spellcheck="false" aria-describedby="opace-eseot-audit-url-help">
					<?php submit_button( __( 'Audit', 'opace-essential-seo-toolkit' ), 'primary', 'submit', false ); ?>
					<p class="description" id="opace-eseot-audit-url-help"><?php esc_html_e( 'Any address on this site. Drafts use their preview link.', 'opace-essential-seo-toolkit' ); ?></p>
				</form>
			</div>

			<?php
			if ( 'bulk' === $mode ) {
				$this->audit->render_container(
					array(
						'mode'    => 'bulk',
						'targets' => $targets,
						'autorun' => true,
						'view'    => $view,
					)
				);
			} elseif ( '' !== $target ) {
				$this->audit->render_container(
					array(
						'target_url' => $target,
						'post_id'    => (int) url_to_postid( $target ),
						'autorun'    => $autorun,
						'view'       => $view,
					)
				);
			}
			?>
		</div>
		<?php
	}

	/**
	 * ids=1,2,3 → unique positive ints, first 25.
	 *
	 * @param string $raw Comma-separated ids.
	 * @return int[]
	 */
	private function parse_ids( string $raw ): array {
		if ( '' === $raw ) {
			return array();
		}

		$ids = array();
		foreach ( explode( ',', $raw ) as $piece ) {
			$id = absint( trim( $piece ) );
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return array_slice( $ids, 0, self::MAX_BULK );
	}

	/**
	 * Audit targets for posts the current user can edit.
	 *
	 * @param int[] $ids Post ids.
	 * @return array<int, array{post_id: int, title: string, url: string}>
	 */
	private function bulk_targets( array $ids ): array {
		$targets = array();
		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$url = Opace_ESEOT_Audit::audit_target_for_post( $id );
			if ( '' === $url ) {
				continue;
			}
			$title     = get_the_title( $id );
			$targets[] = array(
				'post_id' => $id,
				'title'   => '' !== trim( (string) $title ) ? $title : __( '(no title)', 'opace-essential-seo-toolkit' ),
				'url'     => $url,
			);
		}

		return $targets;
	}
}
