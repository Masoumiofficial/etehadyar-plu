<?php
/**
 * Uninstall handler for AvandWP.
 *
 * Removes plugin tables, options, transients, and scheduled hooks ONLY if the
 * administrator explicitly enabled "delete_data_on_uninstall" in Settings.
 *
 * @package AvandWP
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'avandwp_settings', array() );
$delete_all = ! empty( $settings['delete_data_on_uninstall'] );

// Always clear scheduled cron hooks.
$cron_hooks = array(
	'avandwp_cron_process_jobs',
	'avandwp_cron_daily_maintenance',
);
foreach ( $cron_hooks as $hook ) {
	$timestamp = wp_next_scheduled( $hook );
	while ( $timestamp ) {
		wp_unschedule_event( $timestamp, $hook );
		$timestamp = wp_next_scheduled( $hook );
	}
}

if ( ! $delete_all ) {
	return;
}

global $wpdb;

$tables = array(
	'avandwp_knowledge',
	'avandwp_jobs',
	'avandwp_chat_logs',
	'avandwp_usage',
	'avandwp_support_requests',
	'avandwp_support_history',
	'avandwp_chat_feedback',
	'avandwp_activity_log',
);

foreach ( $tables as $table ) {
	$full_table = $wpdb->prefix . $table;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS `{$full_table}`" );
}

$options = array(
	'avandwp_version',
	'avandwp_db_version',
	'avandwp_settings',
	'avandwp_vault_keys',
	'avandwp_chat_settings',
	'avandwp_faqs',
	'avandwp_telegram_settings',
	'avandwp_auditor_last_report',
);

foreach ( $options as $opt ) {
	delete_option( $opt );
}
