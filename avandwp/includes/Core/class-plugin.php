<?php
/**
 * Main Plugin Singleton Controller (`AvandWP_Plugin`).
 *
 * Coordinates REST API routes, authenticated Admin AJAX actions, cron callbacks,
 * database version upgrades, and frontend JSON-LD FAQ schema output.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvandWP_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var AvandWP_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return AvandWP_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->maybe_upgrade_db();
		$this->init_modules();
		$this->register_hooks();
	}

	/**
	 * Ensure database tables are up to date when version changes.
	 *
	 * @return void
	 */
	private function maybe_upgrade_db() {
		$installed_db = (int) get_option( 'avandwp_db_version', 0 );
		if ( $installed_db < (int) AVANDWP_DB_VERSION ) {
			AvandWP_Activator::create_tables();
			AvandWP_Activator::init_default_options();
			update_option( 'avandwp_db_version', AVANDWP_DB_VERSION );
		}
	}

	/**
	 * Initialize admin menu, frontend chat assistant, and Telegram hooks.
	 *
	 * @return void
	 */
	private function init_modules() {
		if ( is_admin() ) {
			$menu = new AvandWP_Admin_Menu();
			$menu->register();
		}

		$chat = new AvandWP_Chat_Assistant();
		$chat->register();

		$tg = new AvandWP_Telegram();
		$tg->register_hooks();
	}

	/**
	 * Register REST API, AJAX, Cron, and Frontend hooks.
	 *
	 * @return void
	 */
	private function register_hooks() {
		// REST API
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Cron jobs
		add_action( 'avandwp_cron_process_jobs', array( 'AvandWP_Job_Queue', 'process_batch' ) );
		add_action( 'avandwp_cron_daily_maintenance', array( $this, 'run_daily_maintenance' ) );

		// FAQ Schema JSON-LD on singular posts & products
		add_action( 'wp_head', array( $this, 'output_faq_schema_jsonld' ) );

		// Authenticated XLSX Export
		add_action( 'admin_post_avandwp_export_xlsx', array( $this, 'handle_export_xlsx' ) );

		// Admin AJAX actions
		$ajax_actions = array(
			'save_settings',
			'test_provider',
			'repair_system',
			'generate_article',
			'generate_image',
			'generate_speech',
			'woo_scan',
			'woo_optimize',
			'woo_create_draft',
			'poll_job',
			'process_jobs_now',
			'update_ticket',
			'save_faqs',
			'sync_knowledge',
			'save_chat_settings',
			'save_telegram',
			'test_telegram',
			'publish_post_telegram',
			'run_content_audit',
			'fix_post_seo',
		);

		foreach ( $ajax_actions as $act ) {
			add_action( 'wp_ajax_avandwp_' . $act, array( $this, 'ajax_' . $act ) );
		}
	}

	/**
	 * Daily maintenance cron: cleanup old finished jobs.
	 *
	 * @return void
	 */
	public function run_daily_maintenance() {
		AvandWP_Job_Queue::cleanup_old_jobs( 14 );
	}

	/**
	 * Output FAQPage JSON-LD Schema in `<head>` for posts/products with `_avandwp_faq_items`.
	 *
	 * @return void
	 */
	public function output_faq_schema_jsonld() {
		if ( ! is_singular( array( 'post', 'product', 'page' ) ) ) {
			return;
		}
		$post_id = get_queried_object_id();
		$faqs    = get_post_meta( $post_id, '_avandwp_faq_items', true );
		if ( empty( $faqs ) || ! is_array( $faqs ) ) {
			return;
		}

		$entities = array();
		foreach ( $faqs as $f ) {
			$q = trim( (string) ( $f['question'] ?? '' ) );
			$a = trim( (string) ( $f['answer'] ?? '' ) );
			if ( '' !== $q && '' !== $a ) {
				$entities[] = array(
					'@type'          => 'Question',
					'name'           => $q,
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => $a,
					),
				);
			}
		}

		if ( empty( $entities ) ) {
			return;
		}

		$schema = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $entities,
		);

		echo "\n" . '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
	}

	/**
	 * Handle authenticated XLSX export download.
	 *
	 * @return void
	 */
	public function handle_export_xlsx() {
		check_admin_referer( 'avandwp_export_xlsx_nonce', '_wpnonce' );
		AvandWP_Reports::stream_xlsx_report();
	}

	/* ==========================================================================
	 * REST API Endpoints (`/wp-json/avandwp/v1/...`)
	 * ========================================================================== */

	/**
	 * Register REST API routes for frontend chat, support tickets, voice upload, and feedback.
	 *
	 * @return void
	 */
	public function register_rest_routes() {
		register_rest_route(
			'avandwp/v1',
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_handle_chat' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'avandwp/v1',
			'/support',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_handle_support' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'avandwp/v1',
			'/support/audio',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_handle_support_audio' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'avandwp/v1',
			'/feedback',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_handle_feedback' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * REST POST `/avandwp/v1/chat`
	 */
	public function rest_handle_chat( WP_REST_Request $request ) {
		$rl = AvandWP_Rate_Limiter::check( 'chat', 15, 60 );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}

		$message    = (string) $request->get_param( 'message' );
		$session_id = (string) $request->get_param( 'session_id' );
		$history    = $request->get_param( 'history' );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$chat = new AvandWP_Chat_Assistant();
		$res  = $chat->handle_message( $message, $session_id, $history );
		if ( is_wp_error( $res ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $res->get_error_message(),
				),
				400
			);
		}

		return new WP_REST_Response(
			array(
				'success'    => true,
				'reply'      => $res['reply'],
				'sources'    => $res['sources'],
				'log_id'     => $res['log_id'],
				'session_id' => $res['session_id'],
			),
			200
		);
	}

	/**
	 * REST POST `/avandwp/v1/support`
	 */
	public function rest_handle_support( WP_REST_Request $request ) {
		$rl = AvandWP_Rate_Limiter::check( 'support', 5, 300 );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}

		$ticket_id = AvandWP_Support_Desk::create_ticket(
			array(
				'session_id'   => (string) $request->get_param( 'session_id' ),
				'user_name'    => (string) $request->get_param( 'user_name' ),
				'user_contact' => (string) $request->get_param( 'user_contact' ),
				'subject'      => (string) $request->get_param( 'subject' ),
				'message'      => (string) $request->get_param( 'message' ),
				'priority'     => (string) $request->get_param( 'priority' ),
				'page_url'     => (string) $request->get_param( 'page_url' ),
			)
		);

		if ( is_wp_error( $ticket_id ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => $ticket_id->get_error_message(),
				),
				400
			);
		}

		return new WP_REST_Response(
			array(
				'success'   => true,
				'ticket_id' => $ticket_id,
				'message'   => sprintf( 'درخواست شما با کد پیگیری #%d ثبت شد. کارشناسان ما به‌زودی با شما تماس می‌گیرند.', $ticket_id ),
			),
			200
		);
	}

	/**
	 * REST POST `/avandwp/v1/support/audio`
	 *
	 * Fixes the bug where $file['tmp_name'] was passed after wp_handle_upload() moved the file.
	 */
	public function rest_handle_support_audio( WP_REST_Request $request ) {
		$rl = AvandWP_Rate_Limiter::check( 'support_audio', 3, 300 );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}

		$files = $request->get_file_params();
		if ( empty( $files['audio'] ) || ! is_array( $files['audio'] ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'فایل صوتی ارسال نشده است.' ), 400 );
		}

		$file = $files['audio'];
		if ( ! empty( $file['size'] ) && (int) $file['size'] > 6 * 1024 * 1024 ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => 'حجم فایل صوتی نباید بیشتر از ۶ مگابایت باشد.' ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$uploaded = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => array(
					'webm' => 'audio/webm',
					'ogg'  => 'audio/ogg',
					'mp3'  => 'audio/mpeg',
					'wav'  => 'audio/wav',
					'm4a'  => 'audio/mp4',
				),
			)
		);

		if ( ! empty( $uploaded['error'] ) || empty( $uploaded['file'] ) || empty( $uploaded['url'] ) ) {
			$err = $uploaded['error'] ?? 'خطا در بارگذاری فایل صوتی.';
			return new WP_REST_Response( array( 'success' => false, 'message' => $err ), 400 );
		}

		// Transcribe using the moved file path ($uploaded['file']) — NOT $file['tmp_name']!
		$ai         = new AvandWP_AI_Client();
		$transcript = $ai->transcribe_audio_file( $uploaded['file'] );
		$trans_text = ! is_wp_error( $transcript ) ? $transcript : '';

		$user_contact = sanitize_text_field( (string) $request->get_param( 'user_contact' ) );
		$user_name    = sanitize_text_field( (string) $request->get_param( 'user_name' ) );
		$ticket_id    = 0;

		if ( '' !== $user_contact ) {
			$created = AvandWP_Support_Desk::create_ticket(
				array(
					'session_id'   => (string) $request->get_param( 'session_id' ),
					'user_name'    => $user_name,
					'user_contact' => $user_contact,
					'subject'      => 'پیام صوتی پشتیبانی',
					'message'      => '' !== $trans_text ? $trans_text : '[پیام صوتی پیوست‌شده]',
					'audio_url'    => $uploaded['url'],
					'transcript'   => $trans_text,
				)
			);
			if ( ! is_wp_error( $created ) ) {
				$ticket_id = (int) $created;
			}
		}

		return new WP_REST_Response(
			array(
				'success'    => true,
				'audio_url'  => $uploaded['url'],
				'transcript' => $trans_text,
				'ticket_id'  => $ticket_id,
			),
			200
		);
	}

	/**
	 * REST POST `/avandwp/v1/feedback`
	 */
	public function rest_handle_feedback( WP_REST_Request $request ) {
		$rl = AvandWP_Rate_Limiter::check( 'feedback', 20, 60 );
		if ( is_wp_error( $rl ) ) {
			return $rl;
		}

		$id = AvandWP_Support_Desk::record_feedback(
			array(
				'log_id'     => (int) $request->get_param( 'log_id' ),
				'session_id' => (string) $request->get_param( 'session_id' ),
				'rating'     => (string) $request->get_param( 'rating' ),
				'question'   => (string) $request->get_param( 'question' ),
				'answer'     => (string) $request->get_param( 'answer' ),
				'comment'    => (string) $request->get_param( 'comment' ),
			)
		);

		return new WP_REST_Response( array( 'success' => (bool) $id ), 200 );
	}

	/* ==========================================================================
	 * Admin AJAX Handlers
	 * ========================================================================== */

	/**
	 * Verify AJAX nonce and required capability.
	 *
	 * @param string $capability WordPress capability.
	 * @return void
	 */
	private function verify_ajax( $capability = 'edit_posts' ) {
		check_ajax_referer( 'avandwp_admin_nonce', 'nonce' );
		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( array( 'message' => 'شما سطح دسترسی لازم برای انجام این عملیات را ندارید.' ), 403 );
		}
	}

	/**
	 * AJAX: Save API keys to Vault and general settings (`manage_options`).
	 */
	public function ajax_save_settings() {
		$this->verify_ajax( 'manage_options' );

		$keys = isset( $_POST['keys'] ) && is_array( $_POST['keys'] ) ? wp_unslash( $_POST['keys'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		foreach ( array_keys( AvandWP_Vault::supported_slots() ) as $slot ) {
			if ( isset( $keys[ $slot ] ) ) {
				$val = trim( sanitize_text_field( (string) $keys[ $slot ] ) );
				// Only update if user typed a new value or explicitly requested clear
				if ( '' !== $val && false === strpos( $val, '••••' ) ) {
					AvandWP_Vault::set_key( $slot, $val );
				} elseif ( isset( $_POST['clear_keys'][ $slot ] ) && '1' === $_POST['clear_keys'][ $slot ] ) {
					AvandWP_Vault::set_key( $slot, '' );
				}
			}
		}

		$settings = get_option( 'avandwp_settings', array() );
		$settings['default_provider']         = sanitize_key( $_POST['default_provider'] ?? 'gapgpt' );
		$settings['fallback_provider']        = sanitize_key( $_POST['fallback_provider'] ?? 'openai' );
		$settings['model_gapgpt']             = sanitize_text_field( wp_unslash( $_POST['model_gapgpt'] ?? 'gpt-4o-mini' ) );
		$settings['model_openai']             = sanitize_text_field( wp_unslash( $_POST['model_openai'] ?? 'gpt-4o-mini' ) );
		$settings['model_gemini']             = sanitize_text_field( wp_unslash( $_POST['model_gemini'] ?? 'gemini-1.5-flash' ) );
		$settings['model_claude']             = sanitize_text_field( wp_unslash( $_POST['model_claude'] ?? 'claude-3-5-haiku-latest' ) );
		$settings['image_provider']           = sanitize_key( $_POST['image_provider'] ?? 'gapgpt' );
		$settings['temperature']              = max( 0.0, min( 1.5, (float) ( $_POST['temperature'] ?? 0.7 ) ) );
		$settings['max_tokens']               = max( 200, min( 4096, (int) ( $_POST['max_tokens'] ?? 2500 ) ) );
		$settings['request_timeout']          = max( 15, min( 180, (int) ( $_POST['request_timeout'] ?? 60 ) ) );
		$settings['enable_true_embeddings']   = ! empty( $_POST['enable_true_embeddings'] ) ? 1 : 0;
		$settings['delete_data_on_uninstall'] = ! empty( $_POST['delete_data_on_uninstall'] ) ? 1 : 0;

		update_option( 'avandwp_settings', $settings );
		AvandWP_Logger::log( 'settings.updated', 'تنظیمات و کلیدهای دسترسی آوند ذخیره شدند.', 'success' );

		wp_send_json_success( array( 'message' => 'تنظیمات و کلیدهای API با رمزنگاری AES-256-GCM ذخیره شدند.' ) );
	}

	/**
	 * AJAX: Test live connection to an AI provider (`manage_options`).
	 */
	public function ajax_test_provider() {
		$this->verify_ajax( 'manage_options' );
		$provider = sanitize_key( $_POST['provider'] ?? 'gapgpt' );

		$ai  = new AvandWP_AI_Client();
		$res = $ai->test_connection( $provider );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => sprintf( 'اتصال به %s (%s) برقرار است — زمان پاسخ: %d میلی‌ثانیه', strtoupper( $res['provider'] ), $res['model'], $res['latency_ms'] ),
				'data'    => $res,
			)
		);
	}

	/**
	 * AJAX: Repair DB tables & WP-Cron (`manage_options`).
	 */
	public function ajax_repair_system() {
		$this->verify_ajax( 'manage_options' );
		$report = AvandWP_Health::repair_system();
		wp_send_json_success(
			array(
				'message' => 'جداول دیتابیس و زمان‌بندی کرون با موفقیت بررسی و ترمیم شدند.',
				'report'  => $report,
			)
		);
	}

	/**
	 * AJAX: Generate article (either directly or queued in background).
	 */
	public function ajax_generate_article() {
		$this->verify_ajax( 'edit_posts' );

		$payload = array(
			'topic'          => sanitize_text_field( wp_unslash( $_POST['topic'] ?? '' ) ),
			'keyword'        => sanitize_text_field( wp_unslash( $_POST['keyword'] ?? '' ) ),
			'tone'           => sanitize_text_field( wp_unslash( $_POST['tone'] ?? 'حرفه‌ای و کاربردی' ) ),
			'target_words'   => (int) ( $_POST['target_words'] ?? 1000 ),
			'post_status'    => sanitize_key( $_POST['post_status'] ?? 'draft' ),
			'category_id'    => (int) ( $_POST['category_id'] ?? 0 ),
			'generate_image' => ! empty( $_POST['generate_image'] ) ? 1 : 0,
			'generate_audio' => ! empty( $_POST['generate_audio'] ) ? 1 : 0,
			'use_knowledge'  => ! empty( $_POST['use_knowledge'] ) ? 1 : 0,
		);

		if ( '' === $payload['topic'] ) {
			wp_send_json_error( array( 'message' => 'لطفاً موضوع مقاله را وارد کنید.' ) );
		}

		$run_background = ! empty( $_POST['background'] );
		if ( $run_background ) {
			$job_id = AvandWP_Job_Queue::enqueue( 'studio_article', $payload );
			if ( ! $job_id ) {
				wp_send_json_error( array( 'message' => 'خطا در ثبت کار در صف پس‌زمینه.' ) );
			}
			wp_send_json_success(
				array(
					'queued'  => true,
					'job_id'  => $job_id,
					'message' => sprintf( 'تولید مقاله در صف پس‌زمینه (#%d) قرار گرفت و اکنون در حال پردازش است.', $job_id ),
				)
			);
		}

		$studio = new AvandWP_Content_Studio();
		$res    = $studio->generate_article_package( $payload );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'queued'  => false,
				'article' => $res,
				'message' => sprintf( 'پیش‌نویس مقاله «%s» (%d کلمه) با موفقیت ساخته شد.', $res['title'], $res['word_count'] ),
			)
		);
	}

	/**
	 * AJAX: Standalone Image Generation in Studio.
	 */
	public function ajax_generate_image() {
		$this->verify_ajax( 'edit_posts' );

		$prompt   = sanitize_textarea_field( wp_unslash( $_POST['prompt'] ?? '' ) );
		$alt_text = sanitize_text_field( wp_unslash( $_POST['alt_text'] ?? $prompt ) );
		$size     = sanitize_text_field( wp_unslash( $_POST['size'] ?? '1024x1024' ) );
		$provider = sanitize_key( $_POST['provider'] ?? '' );

		$client = new AvandWP_Image_Client();
		$res    = $client->generate_and_attach(
			$prompt,
			array(
				'provider' => $provider,
				'size'     => $size,
				'alt_text' => $alt_text,
			)
		);

		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'image'   => $res,
				'message' => 'تصویر با موفقیت تولید و به کتابخانه رسانه وردپرس اضافه شد.',
			)
		);
	}

	/**
	 * AJAX: Standalone Speech (TTS) Generation in Studio.
	 */
	public function ajax_generate_speech() {
		$this->verify_ajax( 'edit_posts' );

		$text  = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );
		$voice = sanitize_key( $_POST['voice'] ?? 'nova' );

		$ai  = new AvandWP_AI_Client();
		$res = $ai->generate_speech( $text, $voice );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'audio'   => $res,
				'message' => 'فایل صوتی MP3 با موفقیت ساخته و در کتابخانه رسانه ذخیره شد.',
			)
		);
	}

	/**
	 * AJAX: Scan weak WooCommerce products.
	 */
	public function ajax_woo_scan() {
		$this->verify_ajax( 'edit_posts' );
		$min_words = max( 20, (int) ( $_POST['min_words'] ?? 80 ) );
		$woo       = new AvandWP_Woo_Assistant();
		$products  = $woo->scan_weak_products( $min_words, 60 );

		wp_send_json_success(
			array(
				'products' => $products,
				'count'    => count( $products ),
			)
		);
	}

	/**
	 * AJAX: Optimize single or bulk WooCommerce products.
	 */
	public function ajax_woo_optimize() {
		$this->verify_ajax( 'edit_posts' );

		$product_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
		$bulk_ids   = isset( $_POST['product_ids'] ) && is_array( $_POST['product_ids'] ) ? array_map( 'intval', $_POST['product_ids'] ) : array();
		$tone       = sanitize_text_field( wp_unslash( $_POST['tone'] ?? 'مشاور خرید حرفه‌ای' ) );
		$specs_hint = sanitize_textarea_field( wp_unslash( $_POST['specs_hint'] ?? '' ) );

		if ( ! empty( $bulk_ids ) ) {
			$queued_jobs = array();
			foreach ( array_slice( $bulk_ids, 0, 20 ) as $pid ) {
				if ( $pid > 0 ) {
					$jid = AvandWP_Job_Queue::enqueue(
						'woo_optimize',
						array(
							'product_id' => $pid,
							'options'    => array( 'tone' => $tone ),
						)
					);
					if ( $jid ) {
						$queued_jobs[] = $jid;
					}
				}
			}
			wp_send_json_success(
				array(
					'queued'  => true,
					'job_ids' => $queued_jobs,
					'message' => sprintf( '%d محصول در صف پردازش پس‌زمینه قرار گرفتند.', count( $queued_jobs ) ),
				)
			);
		}

		$woo = new AvandWP_Woo_Assistant();
		$res = $woo->optimize_product(
			$product_id,
			array(
				'tone'       => $tone,
				'specs_hint' => $specs_hint,
			)
		);

		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'queued'  => false,
				'product' => $res,
				'message' => sprintf( 'محصول «%s» (%d کلمه) با موفقیت بهینه‌سازی شد.', $res['title'], $res['word_count'] ),
			)
		);
	}

	/**
	 * AJAX: Create new draft WooCommerce product from brief.
	 */
	public function ajax_woo_create_draft() {
		$this->verify_ajax( 'edit_posts' );

		$name  = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$price = sanitize_text_field( wp_unslash( $_POST['price'] ?? '' ) );
		$brief = sanitize_textarea_field( wp_unslash( $_POST['brief'] ?? '' ) );

		$woo = new AvandWP_Woo_Assistant();
		$res = $woo->create_product_draft( $name, $price, $brief );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'product' => $res,
				'message' => sprintf( 'پیش‌نویس محصول «%s» با موفقیت ساخته و بهینه‌سازی شد.', $res['title'] ),
			)
		);
	}

	/**
	 * AJAX: Poll status of a background job (and trigger execution if still pending).
	 */
	public function ajax_poll_job() {
		$this->verify_ajax( 'edit_posts' );
		$job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;
		$job    = AvandWP_Job_Queue::get_job( $job_id );

		if ( ! $job ) {
			wp_send_json_error( array( 'message' => 'کار مورد نظر در صف یافت نشد.' ) );
		}

		if ( 'pending' === $job['status'] ) {
			AvandWP_Job_Queue::process_batch( 1, $job_id );
			$job = AvandWP_Job_Queue::get_job( $job_id );
		}

		wp_send_json_success( array( 'job' => $job ) );
	}

	/**
	 * AJAX: Process pending jobs batch immediately.
	 */
	public function ajax_process_jobs_now() {
		$this->verify_ajax( 'edit_posts' );
		$res = AvandWP_Job_Queue::process_batch( 2 );
		wp_send_json_success(
			array(
				'processed' => $res['processed'],
				'message'   => sprintf( '%d کار از صف پس‌زمینه پردازش شد.', $res['processed'] ),
			)
		);
	}

	/**
	 * AJAX: Update support ticket status/reply.
	 */
	public function ajax_update_ticket() {
		$this->verify_ajax( 'edit_posts' );

		$ticket_id = isset( $_POST['ticket_id'] ) ? (int) $_POST['ticket_id'] : 0;
		$status    = sanitize_key( $_POST['status'] ?? 'in_progress' );
		$reply     = sanitize_textarea_field( wp_unslash( $_POST['admin_reply'] ?? '' ) );
		$priority  = sanitize_key( $_POST['priority'] ?? '' );

		$res = AvandWP_Support_Desk::update_ticket_admin( $ticket_id, $status, $reply, $priority );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => 'وضعیت و پاسخ تیکت با موفقیت ذخیره شد.' ) );
	}

	/**
	 * AJAX: Save custom FAQs and rebuild Knowledge Base.
	 */
	public function ajax_save_faqs() {
		$this->verify_ajax( 'edit_posts' );
		$faqs = isset( $_POST['faqs'] ) && is_array( $_POST['faqs'] ) ? wp_unslash( $_POST['faqs'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		AvandWP_Knowledge_Base::save_faqs( $faqs );
		$kb   = new AvandWP_Knowledge_Base();
		$sync = $kb->sync_all_content();

		wp_send_json_success(
			array(
				'message' => sprintf( 'پرسش‌های متداول ذخیره شدند و پایگاه دانش (%d مورد) به‌روزرسانی شد.', $sync['total'] ),
				'sync'    => $sync,
			)
		);
	}

	/**
	 * AJAX: Rebuild Knowledge Base index.
	 */
	public function ajax_sync_knowledge() {
		$this->verify_ajax( 'edit_posts' );
		$kb   = new AvandWP_Knowledge_Base();
		$sync = $kb->sync_all_content();

		wp_send_json_success(
			array(
				'message' => sprintf( 'شاخص پایگاه دانش سایت با موفقیت بازسازی شد (%d نوشته/محصول و %d پرسش متداول).', $sync['indexed_posts'], $sync['indexed_faqs'] ),
				'sync'    => $sync,
			)
		);
	}

	/**
	 * AJAX: Save frontend chat assistant settings (`manage_options`).
	 */
	public function ajax_save_chat_settings() {
		$this->verify_ajax( 'manage_options' );

		$chat_settings = array(
			'enabled'            => ! empty( $_POST['enabled'] ) ? 1 : 0,
			'title'              => sanitize_text_field( wp_unslash( $_POST['title'] ?? 'دستیار هوشمند سایت' ) ),
			'subtitle'           => sanitize_text_field( wp_unslash( $_POST['subtitle'] ?? '' ) ),
			'welcome_message'    => sanitize_textarea_field( wp_unslash( $_POST['welcome_message'] ?? '' ) ),
			'primary_color'      => sanitize_hex_color( wp_unslash( $_POST['primary_color'] ?? '#2563EB' ) ) ?: '#2563EB',
			'position'           => ( 'bottom-left' === ( $_POST['position'] ?? 'bottom-right' ) ) ? 'bottom-left' : 'bottom-right',
			'enable_voice_input' => ! empty( $_POST['enable_voice_input'] ) ? 1 : 0,
			'enable_knowledge'   => ! empty( $_POST['enable_knowledge'] ) ? 1 : 0,
			'enable_handoff'     => ! empty( $_POST['enable_handoff'] ) ? 1 : 0,
			'load_vazirmatn'     => ! empty( $_POST['load_vazirmatn'] ) ? 1 : 0,
			'system_prompt'      => sanitize_textarea_field( wp_unslash( $_POST['system_prompt'] ?? '' ) ),
		);

		update_option( 'avandwp_chat_settings', $chat_settings );
		wp_send_json_success( array( 'message' => 'تنظیمات ویجت چت‌بات ذخیره شد.' ) );
	}

	/**
	 * AJAX: Save Telegram settings (`manage_options`).
	 */
	public function ajax_save_telegram() {
		$this->verify_ajax( 'manage_options' );

		$proxy_url = trim( sanitize_text_field( wp_unslash( $_POST['proxy_url'] ?? '' ) ) );
		if ( '' !== $proxy_url ) {
			$scheme = wp_parse_url( $proxy_url, PHP_URL_SCHEME );
			if ( ! wp_http_validate_url( $proxy_url ) || 'https' !== strtolower( (string) $scheme ) ) {
				wp_send_json_error( array( 'message' => 'آدرس پروکسی باید یک URL معتبر با پروتکل https:// باشد.' ) );
			}
		}

		if ( isset( $_POST['bot_token'] ) ) {
			$token = trim( sanitize_text_field( wp_unslash( $_POST['bot_token'] ) ) );
			if ( '' !== $token && false === strpos( $token, '••••' ) ) {
				AvandWP_Vault::set_key( 'telegram_bot', $token );
			}
		}

		$tg_settings = array(
			'chat_id'            => sanitize_text_field( wp_unslash( $_POST['chat_id'] ?? '' ) ),
			'proxy_url'          => $proxy_url,
			'auto_publish_posts' => ! empty( $_POST['auto_publish_posts'] ) ? 1 : 0,
			'notify_woo_orders'  => ! empty( $_POST['notify_woo_orders'] ) ? 1 : 0,
			'notify_support'     => ! empty( $_POST['notify_support'] ) ? 1 : 0,
		);

		update_option( 'avandwp_telegram_settings', $tg_settings );
		wp_send_json_success( array( 'message' => 'تنظیمات تلگرام با موفقیت ذخیره شد.' ) );
	}

	/**
	 * AJAX: Run Telegram 3-step live diagnostics & send test message (`manage_options`).
	 */
	public function ajax_test_telegram() {
		$this->verify_ajax( 'manage_options' );
		$tg   = new AvandWP_Telegram();
		$diag = $tg->run_diagnostics();
		if ( is_wp_error( $diag ) ) {
			wp_send_json_error( array( 'message' => $diag->get_error_message() ) );
		}

		if ( ! empty( $_POST['send_test_message'] ) ) {
			$sent = $tg->send_message( '✅ پیام آزمایشی از افزونه هوشمند آوند (AvandWP) — اتصال تلگرام برقرار است.' );
			if ( is_wp_error( $sent ) ) {
				wp_send_json_error( array( 'message' => $sent->get_error_message(), 'diagnostics' => $diag ) );
			}
		}

		wp_send_json_success(
			array(
				'message'     => sprintf( 'ربات @%s متصل و آماده ارسال است.', $diag['bot_username'] ),
				'diagnostics' => $diag,
			)
		);
	}

	/**
	 * AJAX: Publish a specific post to Telegram (`edit_posts`).
	 */
	public function ajax_publish_post_telegram() {
		$this->verify_ajax( 'edit_posts' );
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

		$tg  = new AvandWP_Telegram();
		$res = $tg->publish_post( $post_id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => 'نوشته با موفقیت در کانال تلگرام منتشر شد.' ) );
	}

	/**
	 * AJAX: Run non-destructive Content & SEO Audit (`edit_posts`).
	 */
	public function ajax_run_content_audit() {
		$this->verify_ajax( 'edit_posts' );
		$auditor = new AvandWP_Content_Auditor();
		$report  = $auditor->run_audit( 50 );
		wp_send_json_success(
			array(
				'message' => sprintf( '%d نوشته بررسی شد (%d مورد نیازمند تکمیل).', $report['scanned_count'], $report['flagged_count'] ),
				'report'  => $report,
			)
		);
	}

	/**
	 * AJAX: Generate and apply SEO meta for a single audited post (`edit_posts`).
	 */
	public function ajax_fix_post_seo() {
		$this->verify_ajax( 'edit_posts' );
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;

		$auditor = new AvandWP_Content_Auditor();
		$res     = $auditor->generate_seo_meta_for_post( $post_id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => 'عنوان و توضیحات سئو (Yoast/RankMath) با موفقیت تولید و ذخیره شد.',
				'seo'     => $res,
			)
		);
	}
}
