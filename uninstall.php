<?php
/**
 * Runs when the plugin is deleted from the WordPress admin.
 *
 * Removes the plugin's own options, transients and scheduled event.
 * Product meta (the Morabaa item links and last-sync markers on each
 * WooCommerce product) is intentionally kept, so re-installing the
 * plugin does not lose the product links.
 *
 * @package Morabaa_Woo_Sync
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Deletes the plugin data for the current site.
 */
function morabaa_woo_sync_uninstall_site() {
	global $wpdb;

	$options = array(
		'morabaa_woo_api_endpoint',
		'morabaa_woo_app_id',
		'morabaa_woo_app_secret',
		'morabaa_woo_business_id',
		'morabaa_woo_json_mapping',
		'morabaa_woo_auto_sync_enabled',
		'morabaa_woo_last_cron_details',
		'morabaa_woo_last_cron_run',
		'morabaa_woo_last_cron_status',
		'morabaa_woo_last_linked_count',
		'morabaa_woo_last_unlinked_count',
		'morabaa_woo_last_sync_rows',
		'morabaa_woo_last_sync_time',
		'morabaa_woo_last_test_status',
		'morabaa_woo_last_test_time',
		'morabaa_woo_timestamps_migrated_utc',
	);

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	delete_transient( 'morabaa_woo_sync_lock' );
	delete_transient( 'morabaa_woo_overdue_sync_cooldown' );

	// Cached API results use hashed or per-user keys:
	// morabaa_woo_items_*, morabaa_woo_unlinked_*, morabaa_woo_manual_sync_rows_*.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_morabaa_woo_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_morabaa_woo_' ) . '%'
		)
	);

	wp_clear_scheduled_hook( 'morabaa_woo_sync_cron_event' );
}

if ( is_multisite() && function_exists( 'get_sites' ) ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $morabaa_site_id ) {
		switch_to_blog( $morabaa_site_id );
		morabaa_woo_sync_uninstall_site();
		restore_current_blog();
	}
} else {
	morabaa_woo_sync_uninstall_site();
}
