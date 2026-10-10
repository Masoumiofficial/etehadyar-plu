<?php
/**
 * Plugin Activator and Database Schema Manager.
 *
 * Creates all 8 required custom tables with consistent utf8mb4 collation,
 * including the complete `avandwp_support_requests` schema (with `priority`,
 * `audio_url`, and `transcript` columns that were missing in the legacy version).
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvandWP_Activator {

	/**
	 * Run activation tasks: create tables, initialize options, schedule crons.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();
		self::init_default_options();
		self::schedule_crons();

		update_option( 'avandwp_version', AVANDWP_VERSION );
		update_option( 'avandwp_db_version', AVANDWP_DB_VERSION );
	}

	/**
	 * List of all required custom tables (without $wpdb->prefix).
	 *
	 * @return array<int, string>
	 */
	public static function get_required_tables() {
		return array(
			'avandwp_knowledge',
			'avandwp_jobs',
			'avandwp_chat_logs',
			'avandwp_usage',
			'avandwp_support_requests',
			'avandwp_support_history',
			'avandwp_chat_feedback',
			'avandwp_activity_log',
		);
	}

	/**
	 * Create or upgrade all plugin database tables via dbDelta.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$p               = $wpdb->prefix;

		$sql = array();

		// 1. Knowledge Index (Real Persian lexical + optional true vector embeddings)
		$sql[] = "CREATE TABLE {$p}avandwp_knowledge (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_type varchar(32) NOT NULL DEFAULT 'post',
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			title text NOT NULL,
			url varchar(500) NOT NULL DEFAULT '',
			content_chunk longtext NOT NULL,
			normalized_tokens longtext NOT NULL,
			embedding_json longtext NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY source_lookup (source_type, source_id)
		) {$charset_collate};";

		// 2. Background Job Queue
		$sql[] = "CREATE TABLE {$p}avandwp_jobs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_type varchar(64) NOT NULL,
			status varchar(24) NOT NULL DEFAULT 'pending',
			payload longtext NOT NULL,
			result longtext NULL,
			progress int(11) NOT NULL DEFAULT 0,
			attempts int(11) NOT NULL DEFAULT 0,
			max_attempts int(11) NOT NULL DEFAULT 3,
			error_message text NULL,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_idx (status),
			KEY type_idx (job_type)
		) {$charset_collate};";

		// 3. Chat Assistant Conversation Logs
		$sql[] = "CREATE TABLE {$p}avandwp_chat_logs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id varchar(64) NOT NULL,
			user_ip varchar(64) NOT NULL DEFAULT '',
			user_message text NOT NULL,
			bot_response longtext NOT NULL,
			sources_json text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY session_idx (session_id),
			KEY created_idx (created_at)
		) {$charset_collate};";

		// 4. Token & Cost Usage Ledger
		$sql[] = "CREATE TABLE {$p}avandwp_usage (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			job_id bigint(20) unsigned NOT NULL DEFAULT 0,
			provider varchar(32) NOT NULL,
			model varchar(64) NOT NULL,
			feature varchar(32) NOT NULL DEFAULT 'general',
			tokens_in int(11) unsigned NOT NULL DEFAULT 0,
			tokens_out int(11) unsigned NOT NULL DEFAULT 0,
			total_tokens int(11) unsigned NOT NULL DEFAULT 0,
			cost_usd decimal(12,6) NOT NULL DEFAULT 0.000000,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY provider_idx (provider),
			KEY created_idx (created_at)
		) {$charset_collate};";

		// 5. Support Requests (Fixed: includes priority, audio_url, transcript)
		$sql[] = "CREATE TABLE {$p}avandwp_support_requests (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id varchar(64) NOT NULL DEFAULT '',
			user_name varchar(120) NOT NULL,
			user_contact varchar(190) NOT NULL,
			subject varchar(255) NOT NULL DEFAULT '',
			message longtext NOT NULL,
			priority varchar(24) NOT NULL DEFAULT 'normal',
			status varchar(24) NOT NULL DEFAULT 'new',
			audio_url varchar(500) NULL,
			transcript longtext NULL,
			admin_reply longtext NULL,
			page_url varchar(500) NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY status_idx (status),
			KEY priority_idx (priority),
			KEY created_idx (created_at)
		) {$charset_collate};";

		// 6. Support Request Audit History
		$sql[] = "CREATE TABLE {$p}avandwp_support_history (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_id bigint(20) unsigned NOT NULL,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action_type varchar(40) NOT NULL,
			note text NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY request_idx (request_id)
		) {$charset_collate};";

		// 7. Chat Response User Feedback
		$sql[] = "CREATE TABLE {$p}avandwp_chat_feedback (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			log_id bigint(20) unsigned NOT NULL DEFAULT 0,
			session_id varchar(64) NOT NULL DEFAULT '',
			rating varchar(16) NOT NULL DEFAULT 'up',
			question text NOT NULL,
			answer longtext NOT NULL,
			comment text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY rating_idx (rating)
		) {$charset_collate};";

		// 8. Operational Activity Log
		$sql[] = "CREATE TABLE {$p}avandwp_activity_log (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(100) NOT NULL,
			level varchar(20) NOT NULL DEFAULT 'info',
			message text NOT NULL,
			context longtext NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY level_idx (level),
			KEY created_idx (created_at)
		) {$charset_collate};";

		foreach ( $sql as $query ) {
			dbDelta( $query );
		}
	}

	/**
	 * Initialize default plugin options if not already present.
	 *
	 * @return void
	 */
	public static function init_default_options() {
		$defaults = array(
			'default_provider'         => 'gapgpt',
			'fallback_provider'        => 'openai',
			'model_gapgpt'             => 'gpt-4o-mini',
			'model_openai'             => 'gpt-4o-mini',
			'model_gemini'             => 'gemini-1.5-flash',
			'model_claude'             => 'claude-3-5-haiku-latest',
			'image_provider'           => 'gapgpt',
			'temperature'              => 0.7,
			'max_tokens'               => 2500,
			'request_timeout'          => 60,
			'enable_true_embeddings'   => 0,
			'delete_data_on_uninstall' => 0,
		);

		$existing = get_option( 'avandwp_settings', false );
		if ( ! is_array( $existing ) ) {
			update_option( 'avandwp_settings', $defaults );
		} else {
			update_option( 'avandwp_settings', wp_parse_args( $existing, $defaults ) );
		}

		$chat_defaults = array(
			'enabled'            => 1,
			'title'              => 'دستیار هوشمند سایت',
			'subtitle'           => 'پاسخ‌گویی آنی بر اساس اطلاعات سایت و ثبت تیکت پشتیبانی',
			'welcome_message'    => 'سلام! چطور می‌توانم در مورد محصولات، خدمات یا مطالب سایت به شما کمک کنم؟',
			'primary_color'      => '#2563EB',
			'position'           => 'bottom-right',
			'enable_voice_input' => 1,
			'enable_knowledge'   => 1,
			'enable_handoff'     => 1,
			'load_vazirmatn'     => 0,
			'system_prompt'      => 'شما دستیار هوشمند و رسمی این وب‌سایت هستید. فقط بر اساس اطلاعات و منابع سایت که در اختیار شما قرار می‌گیرد پاسخ دقیق، کوتاه و محترمانه به زبان فارسی بدهید. اگر پاسخ پرسش کاربر در اطلاعات سایت وجود ندارد، از حدس زدن خودداری کنید و پیشنهاد دهید از دکمه «ثبت درخواست برای کارشناس» استفاده کند.',
		);

		$existing_chat = get_option( 'avandwp_chat_settings', false );
		if ( ! is_array( $existing_chat ) ) {
			update_option( 'avandwp_chat_settings', $chat_defaults );
		}

		$tg_defaults = array(
			'chat_id'             => '',
			'proxy_url'           => '',
			'auto_publish_posts'  => 0,
			'notify_woo_orders'   => 1,
			'notify_support'      => 1,
		);
		if ( false === get_option( 'avandwp_telegram_settings', false ) ) {
			update_option( 'avandwp_telegram_settings', $tg_defaults );
		}
	}

	/**
	 * Register cron schedules and recurring events.
	 *
	 * @return void
	 */
	public static function schedule_crons() {
		if ( ! wp_next_scheduled( 'avandwp_cron_process_jobs' ) ) {
			wp_schedule_event( time() + 60, 'hourly', 'avandwp_cron_process_jobs' );
		}
		if ( ! wp_next_scheduled( 'avandwp_cron_daily_maintenance' ) ) {
			wp_schedule_event( time() + 300, 'daily', 'avandwp_cron_daily_maintenance' );
		}
	}
}
