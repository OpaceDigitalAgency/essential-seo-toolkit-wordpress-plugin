<?php
/**
 * Removes every option the plugin stores. Runs when the plugin is deleted.
 *
 * @package Opace_Essential_SEO_Toolkit
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'opace_eseot_uninstall_options' ) ) {
	/**
	 * Option names the plugin stores.
	 *
	 * @return string[]
	 */
	function opace_eseot_uninstall_options(): array {
		return array(
			'eseot_sources',
			'eseot_categories',
			'eseot_post_types',
			'eseot_version',
			'eseot_show_upgrade_notice',
			'eseot_audit',
			'eseot_audit_summaries_urls',
		);
	}
}

if ( ! function_exists( 'opace_eseot_uninstall_site' ) ) {
	/**
	 * Delete the plugin's options, audit summaries and crawl transients for the current site.
	 */
	function opace_eseot_uninstall_site(): void {
		global $wpdb;

		foreach ( opace_eseot_uninstall_options() as $option ) {
			delete_option( $option );
		}

		delete_post_meta_by_key( '_opace_eseot_audit_summary' );
		delete_post_meta_by_key( '_opace_eseot_audit_time' );

		// Crawl-check transients are keyed by URL hash, so they can only be found by prefix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup on uninstall; keys are dynamic.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_opace_eseot_crawl_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_opace_eseot_crawl_' ) . '%'
			)
		);

		wp_cache_flush();
	}
}

if ( is_multisite() ) {
	$opace_eseot_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $opace_eseot_site_ids as $opace_eseot_site_id ) {
		switch_to_blog( (int) $opace_eseot_site_id );
		opace_eseot_uninstall_site();
		restore_current_blog();
	}

	// Nothing is stored network-wide, but clear any copies just in case.
	foreach ( opace_eseot_uninstall_options() as $opace_eseot_option ) {
		delete_site_option( $opace_eseot_option );
	}
} else {
	opace_eseot_uninstall_site();
}
