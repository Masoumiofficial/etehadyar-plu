<?php
/**
 * Telegram Publisher & Notification Engine (`AvandWP_Telegram`).
 *
 * Includes SSRF-safe HTTPS proxy validation (`wp_http_validate_url`),
 * 3-step live connection diagnostics (`getMe`, `getChat`, `getChatMember`),
 * post publishing, and automatic WooCommerce order & support ticket alerts.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvandWP_Telegram {

	/**
	 * Register WooCommerce order and post transition hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'woocommerce_new_order', array( $this, 'on_woocommerce_new_order' ), 20, 1 );
		add_action( 'transition_post_status', array( $this, 'on_post_published' ), 20, 3 );
	}

	/**
	 * Validate and return base Telegram API URL (direct or via validated HTTPS proxy).
	 *
	 * Prevents SSRF by enforcing https:// and wp_http_validate_url().
	 *
	 * @param string $bot_token Telegram bot token.
	 * @return string|WP_Error
	 */
	private function get_api_base( $bot_token ) {
		$tg_settings = get_option( 'avandwp_telegram_settings', array() );
		$proxy_url   = isset( $tg_settings['proxy_url'] ) ? trim( (string) $tg_settings['proxy_url'] ) : '';

		if ( '' !== $proxy_url ) {
			$validated = wp_http_validate_url( $proxy_url );
			$scheme    = wp_parse_url( $proxy_url, PHP_URL_SCHEME );
			if ( ! $validated || 'https' !== strtolower( (string) $scheme ) ) {
				return new WP_Error(
					'avandwp_invalid_tg_proxy',
					'آدرس پروکسی تلگرام نامعتبر است. فقط آدرس‌های عمومی و امن با پروتکل https:// مجاز هستند.'
				);
			}
			return rtrim( $validated, '/' ) . '/bot' . $bot_token;
		}

		return 'https://api.telegram.org/bot' . $bot_token;
	}

	/**
	 * Perform a Telegram Bot API call.
	 *
	 * @param string $method  Telegram method name (e.g. 'sendMessage', 'getMe').
	 * @param array  $payload Method parameters.
	 * @return array|WP_Error
	 */
	public function call_api( $method, array $payload = array() ) {
		$token = AvandWP_Vault::get_key( 'telegram_bot' );
		if ( '' === $token ) {
			return new WP_Error( 'avandwp_no_tg_token', 'توکن ربات تلگرام در بخش کلیدهای API ثبت نشده است.' );
		}

		$base = $this->get_api_base( $token );
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		$endpoint = $base . '/' . ltrim( $method, '/' );
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 25,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'avandwp_tg_http_error', 'خطای شبکه در ارتباط با تلگرام: ' . $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['ok'] ) ) {
			$desc = $body['description'] ?? 'خطای ناشناخته تلگرام';
			return new WP_Error( 'avandwp_tg_api_error', 'خطای API تلگرام: ' . $desc );
		}

		return $body['result'] ?? array();
	}

	/**
	 * Run 3-step live diagnostics (`getMe` + `getChat`) for admin troubleshooting.
	 *
	 * @return array|WP_Error
	 */
	public function run_diagnostics() {
		$me = $this->call_api( 'getMe' );
		if ( is_wp_error( $me ) ) {
			return $me;
		}

		$tg_settings = get_option( 'avandwp_telegram_settings', array() );
		$chat_id     = trim( (string) ( $tg_settings['chat_id'] ?? '' ) );
		$chat_info   = null;

		if ( '' !== $chat_id ) {
			$chat_res = $this->call_api( 'getChat', array( 'chat_id' => $chat_id ) );
			if ( ! is_wp_error( $chat_res ) ) {
				$chat_info = array(
					'title'    => $chat_res['title'] ?? ( $chat_res['username'] ?? $chat_id ),
					'type'     => $chat_res['type'] ?? 'channel',
					'verified' => true,
				);
			} else {
				$chat_info = array(
					'error'    => $chat_res->get_error_message(),
					'verified' => false,
				);
			}
		}

		return array(
			'bot_username' => $me['username'] ?? '',
			'bot_name'     => $me['first_name'] ?? '',
			'chat_id'      => $chat_id,
			'chat_info'    => $chat_info,
		);
	}

	/**
	 * Send a text message to the configured Telegram channel/group.
	 *
	 * @param string $text    Message text.
	 * @param string $chat_id Optional override chat_id.
	 * @return array|WP_Error
	 */
	public function send_message( $text, $chat_id = '' ) {
		if ( '' === $chat_id ) {
			$tg_settings = get_option( 'avandwp_telegram_settings', array() );
			$chat_id     = trim( (string) ( $tg_settings['chat_id'] ?? '' ) );
		}

		if ( '' === $chat_id ) {
			return new WP_Error( 'avandwp_no_chat_id', 'شناسه کانال یا گروه تلگرام (Chat ID) تنظیم نشده است.' );
		}

		$res = $this->call_api(
			'sendMessage',
			array(
				'chat_id'                  => $chat_id,
				'text'                     => wp_strip_all_tags( (string) $text ),
				'disable_web_page_preview' => false,
			)
		);

		if ( ! is_wp_error( $res ) ) {
			AvandWP_Logger::log( 'telegram.sent', sprintf( 'پیام با موفقیت به تلگرام (%s) ارسال شد.', $chat_id ), 'success' );
		}

		return $res;
	}

	/**
	 * Publish a WordPress post to Telegram.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error
	 */
	public function publish_post( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return new WP_Error( 'avandwp_post_not_found', 'نوشته مورد نظر یافت نشد.' );
		}

		$custom_caption = get_post_meta( $post->ID, '_avandwp_telegram_caption', true );
		$title          = get_the_title( $post );
		$url            = get_permalink( $post );
		$excerpt        = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 35 );

		$text = ! empty( $custom_caption )
			? ( $custom_caption . "\n\n🔗 مطالعه کامل:\n" . $url )
			: sprintf( "📌 %s\n\n%s\n\n🔗 مشاهده در سایت:\n%s", $title, $excerpt, $url );

		return $this->send_message( $text );
	}

	/**
	 * Hook: Notify Telegram on new WooCommerce order.
	 *
	 * @param int $order_id WooCommerce Order ID.
	 * @return void
	 */
	public function on_woocommerce_new_order( $order_id ) {
		$tg_settings = get_option( 'avandwp_telegram_settings', array() );
		if ( empty( $tg_settings['notify_woo_orders'] ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$items_names = array();
		foreach ( $order->get_items() as $item ) {
			$items_names[] = $item->get_name() . ' (×' . $item->get_quantity() . ')';
		}

		$msg = sprintf(
			"🛍 سفارش جدید در فروشگاه ثبت شد!\nشماره سفارش: #%d\nمشتری: %s %s\nمبلغ کل: %s\nاقلام: %s",
			$order->get_id(),
			$order->get_billing_first_name(),
			$order->get_billing_last_name(),
			wp_strip_all_tags( $order->get_formatted_order_total() ),
			implode( '، ', $items_names )
		);

		$this->send_message( $msg );
	}

	/**
	 * Notify Telegram when a new support ticket is created.
	 *
	 * @param int   $ticket_id Ticket ID.
	 * @param array $ticket    Ticket details.
	 * @return void
	 */
	public function notify_support_ticket( $ticket_id, array $ticket ) {
		$msg = sprintf(
			"🎫 تیکت پشتیبانی جدید (#%d)\nنام کاربر: %s\nراه ارتباطی: %s\nاولویت: %s\nپیام:\n%s",
			(int) $ticket_id,
			$ticket['user_name'] ?? '',
			$ticket['user_contact'] ?? '',
			$ticket['priority'] ?? 'normal',
			$ticket['message'] ?? ''
		);
		$this->send_message( $msg );
	}

	/**
	 * Hook: Auto-publish newly published posts if enabled.
	 */
	public function on_post_published( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status || ! is_object( $post ) || 'post' !== $post->post_type ) {
			return;
		}
		$tg_settings = get_option( 'avandwp_telegram_settings', array() );
		if ( empty( $tg_settings['auto_publish_posts'] ) ) {
			return;
		}
		$this->publish_post( $post->ID );
	}
}
