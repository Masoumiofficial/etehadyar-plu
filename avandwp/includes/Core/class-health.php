<?php
/**
 * System Health Checker and Diagnostic Utility.
 *
 * Verifies all 8 database tables (unlike legacy which only checked 7 of 11),
 * OpenSSL encryption availability, WP-Cron status, provider API keys, and
 * WooCommerce presence.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvandWP_Health {

	/**
	 * Run full diagnostic report.
	 *
	 * @return array
	 */
	public static function get_report() {
		global $wpdb;

		$required_tables = AvandWP_Activator::get_required_tables();
		$tables_status   = array();
		$missing_tables  = 0;

		foreach ( $required_tables as $table_slug ) {
			$full_name = $wpdb->prefix . $table_slug;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full_name ) ) === $full_name );
			$count  = 0;
			if ( $exists ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$full_name}`" );
			} else {
				++$missing_tables;
			}
			$tables_status[ $table_slug ] = array(
				'exists' => $exists,
				'rows'   => $count,
			);
		}

		$openssl_ok = function_exists( 'openssl_encrypt' ) && in_array( AvandWP_Vault::CIPHER, openssl_get_cipher_methods(), true );
		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$next_job_cron = wp_next_scheduled( 'avandwp_cron_process_jobs' );

		$providers_configured = array();
		foreach ( array_keys( AvandWP_Vault::supported_slots() ) as $slot ) {
			$providers_configured[ $slot ] = AvandWP_Vault::has_key( $slot );
		}

		$has_any_ai_key = (
			$providers_configured['gapgpt'] ||
			$providers_configured['openai'] ||
			$providers_configured['gemini'] ||
			$providers_configured['claude']
		);

		return array(
			'plugin_version'       => AVANDWP_VERSION,
			'db_version'           => get_option( 'avandwp_db_version', '' ),
			'wp_version'           => get_bloginfo( 'version' ),
			'php_version'          => PHP_VERSION,
			'openssl_aes_gcm'      => $openssl_ok,
			'woocommerce_active'   => class_exists( 'WooCommerce' ),
			'ziparchive_available' => class_exists( 'ZipArchive' ),
			'wp_cron_disabled'     => $cron_disabled,
			'next_job_cron'        => $next_job_cron ? gmdate( 'Y-m-d H:i:s', $next_job_cron ) : null,
			'tables'               => $tables_status,
			'missing_tables_count' => $missing_tables,
			'providers_configured' => $providers_configured,
			'has_any_ai_key'       => $has_any_ai_key,
			'checked_at'           => current_time( 'mysql' ),
		);
	}

	/**
	 * Repair database tables and cron schedules.
	 *
	 * @return array Updated health report.
	 */
	public static function repair_system() {
		AvandWP_Activator::create_tables();
		AvandWP_Activator::schedule_crons();
		AvandWP_Logger::log( 'health.repair', 'ترمیم جداول دیتابیس و زمان‌بندی WP-Cron با موفقیت انجام شد.', 'success' );
		return self::get_report();
	}
}
