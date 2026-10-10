<?php
/**
 * Plugin Deactivator.
 *
 * Cleanly unschedules background cron events when the plugin is deactivated.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvandWP_Deactivator {

	/**
	 * Unschedule plugin cron hooks.
	 *
	 * @return void
	 */
	public static function deactivate() {
		$hooks = array(
			'avandwp_cron_process_jobs',
			'avandwp_cron_daily_maintenance',
		);

		foreach ( $hooks as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			while ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
				$timestamp = wp_next_scheduled( $hook );
			}
		}
	}
}
