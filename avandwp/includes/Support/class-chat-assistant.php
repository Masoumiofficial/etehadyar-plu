<?php
/**
 * Frontend AI Chat & Voice Assistant (`AvandWP_Chat_Assistant`).
 *
 * Combines the best of both legacy artifacts:
 * - Grounded RAG answers with clickable `sources` array (`title` + `url`).
 * - Web Speech API voice input + audio ticket recording.
 * - Clean human support handoff to `AvandWP_Support_Desk`.
 * - No fake `mockReply()` fallback on error; transparent status reporting.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvandWP_Chat_Assistant {

	/**
	 * Register frontend hooks and shortcode.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( 'avandwp_chat', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_floating_widget' ) );
	}

	/**
	 * Enqueue frontend CSS and JS when widget is enabled or shortcode is present.
	 *
	 * @return void
	 */
	public function enqueue_frontend_assets() {
		if ( is_admin() ) {
			return;
		}

		$chat_settings = get_option( 'avandwp_chat_settings', array() );
		$enabled       = ! empty( $chat_settings['enabled'] );
		if ( ! $enabled ) {
			return;
		}

		if ( ! empty( $chat_settings['load_vazirmatn'] ) ) {
			wp_enqueue_style(
				'avandwp-vazirmatn',
				'https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css',
				array(),
				'33.003'
			);
		}

		wp_enqueue_style(
			'avandwp-chat-widget',
			AVANDWP_URL . 'assets/css/chat-widget.css',
			array(),
			AVANDWP_VERSION
		);

		wp_enqueue_script(
			'avandwp-chat-widget',
			AVANDWP_URL . 'assets/js/chat-widget.js',
			array(),
			AVANDWP_VERSION,
			true
		);

		wp_localize_script(
			'avandwp-chat-widget',
			'AvandWPChatConfig',
			array(
				'restUrl'          => esc_url_raw( rest_url( 'avandwp/v1' ) ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'title'            => esc_html( $chat_settings['title'] ?? 'دستیار هوشمند سایت' ),
				'subtitle'         => esc_html( $chat_settings['subtitle'] ?? 'پاسخ‌گویی بر اساس اطلاعات سایت' ),
				'welcomeMessage'   => esc_html( $chat_settings['welcome_message'] ?? 'سلام! چطور می‌توانم کمکتان کنم؟' ),
				'primaryColor'     => sanitize_hex_color( $chat_settings['primary_color'] ?? '#2563EB' ) ?: '#2563EB',
				'position'         => ( 'bottom-left' === ( $chat_settings['position'] ?? 'bottom-right' ) ) ? 'bottom-left' : 'bottom-right',
				'enableVoiceInput' => ! empty( $chat_settings['enable_voice_input'] ),
				'enableHandoff'    => ! empty( $chat_settings['enable_handoff'] ),
			)
		);
	}

	/**
	 * Render floating chat widget container in `wp_footer`.
	 *
	 * @return void
	 */
	public function render_floating_widget() {
		if ( is_admin() ) {
			return;
		}
		$chat_settings = get_option( 'avandwp_chat_settings', array() );
		if ( empty( $chat_settings['enabled'] ) ) {
			return;
		}
		echo '<div id="avandwp-chat-root" dir="rtl"></div>';
	}

	/**
	 * Render inline shortcode `[avandwp_chat]`.
	 *
	 * @return string
	 */
	public function render_shortcode() {
		return '<div class="avandwp-inline-chat-placeholder" data-avandwp-inline="1" dir="rtl"></div>';
	}

	/**
	 * Handle a user chat message, retrieve grounded context from Knowledge Base,
	 * call AI Client, and log conversation.
	 *
	 * @param string $message    User question.
	 * @param string $session_id Client session identifier.
	 * @param array  $history    Optional sanitized recent turns (max 4 turns, user/assistant only).
	 * @return array|WP_Error ['reply' => string, 'sources' => array, 'log_id' => int]
	 */
	public function handle_message( $message, $session_id = '', array $history = array() ) {
		$message    = trim( sanitize_textarea_field( (string) $message ) );
		$session_id = sanitize_text_field( (string) $session_id );
		if ( '' === $session_id ) {
			$session_id = 'sess_' . wp_generate_password( 12, false );
		}

		if ( '' === $message ) {
			return new WP_Error( 'avandwp_empty_chat_message', 'لطفاً متن پرسش خود را وارد کنید.' );
		}

		$chat_settings = get_option( 'avandwp_chat_settings', array() );
		$use_knowledge = ! isset( $chat_settings['enable_knowledge'] ) || ! empty( $chat_settings['enable_knowledge'] );

		$sources       = array();
		$context_block = '';

		if ( $use_knowledge ) {
			$kb      = new AvandWP_Knowledge_Base();
			$matches = $kb->search( $message, 4 );
			if ( ! empty( $matches ) ) {
				$chunks = array();
				foreach ( $matches as $m ) {
					$chunks[]  = $m['content_chunk'];
					$sources[] = array(
						'title' => $m['title'],
						'url'   => $m['url'],
						'type'  => $m['source_type'],
					);
				}
				$context_block = "\n\n=== اطلاعات رسمی استخراج‌شده از سایت ===\n" . implode( "\n---\n", $chunks );
			}
		}

		$base_system = ! empty( $chat_settings['system_prompt'] )
			? $chat_settings['system_prompt']
			: 'شما دستیار هوشمند این وب‌سایت هستید. فقط بر اساس اطلاعات رسمی سایت پاسخ دقیق، کوتاه و محترمانه به فارسی بدهید.';

		$messages = array(
			array(
				'role'    => 'system',
				'content' => $base_system . $context_block,
			),
		);

		// Sanitize up to 4 recent history turns (strictly only 'user' or 'assistant' roles, never 'system')
		$recent_history = array_slice( $history, -4 );
		foreach ( $recent_history as $turn ) {
			if ( ! is_array( $turn ) ) {
				continue;
			}
			$role = ( isset( $turn['role'] ) && 'assistant' === $turn['role'] ) ? 'assistant' : 'user';
			$text = isset( $turn['content'] ) ? trim( sanitize_textarea_field( (string) $turn['content'] ) ) : '';
			if ( '' !== $text ) {
				$messages[] = array(
					'role'    => $role,
					'content' => mb_substr( $text, 0, 600, 'UTF-8' ),
				);
			}
		}

		$messages[] = array(
			'role'    => 'user',
			'content' => mb_substr( $message, 0, 1000, 'UTF-8' ),
		);

		$ai  = new AvandWP_AI_Client();
		$res = $ai->chat(
			$messages,
			array(
				'max_tokens'  => 700,
				'temperature' => 0.4,
				'feature'     => 'chat',
			)
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$reply = trim( wp_strip_all_tags( $res['content'] ) );

		global $wpdb;
		$table = $wpdb->prefix . 'avandwp_chat_logs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'session_id'   => $session_id,
				'user_ip'      => AvandWP_Rate_Limiter::get_client_ip(),
				'user_message' => $message,
				'bot_response' => $reply,
				'sources_json' => ! empty( $sources ) ? wp_json_encode( $sources, JSON_UNESCAPED_UNICODE ) : null,
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		$log_id = (int) $wpdb->insert_id;

		return array(
			'reply'      => $reply,
			'sources'    => $sources,
			'log_id'     => $log_id,
			'session_id' => $session_id,
		);
	}
}
