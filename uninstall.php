<?php
/**
 * Uninstall: removes every option, table and scheduled event the plugin created.
 *
 * @package SendFleet
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes the plugin's data from the current site.
 *
 * @return void
 */
function sendfleet_uninstall_site() {
	global $wpdb;

	foreach ( array( 'sendfleet_settings', 'sendfleet_account', 'sendfleet_account_checked', 'sendfleet_state', 'sendfleet_db_version', 'sendfleet_queue_lock' ) as $option ) {
		delete_option( $option );
	}

	foreach ( array( 'sendfleet_process_queue', 'sendfleet_maintenance', 'sendfleet_refresh_account' ) as $hook ) {
		wp_clear_scheduled_hook( $hook );
	}

	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sendfleet_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own table on uninstall.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sendfleet_suppressions" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own table on uninstall.

	// Per-user flash messages.
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_sendfleet\\_flash\\_%' OR option_name LIKE '\\_transient\\_timeout\\_sendfleet\\_flash\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup of the plugin's own transients.
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $sendfleet_site_id ) {
		switch_to_blog( $sendfleet_site_id );
		sendfleet_uninstall_site();
		restore_current_blog();
	}
} else {
	sendfleet_uninstall_site();
}
