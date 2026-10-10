<?php
/**
 * Multi-Provider AI Client (GapGPT, OpenAI, Google Gemini, Anthropic Claude).
 *
 * Supports chat completions, automatic provider fallback on quota/rate-limit errors,
 * Whisper audio transcription, TTS audio synthesis, and real vector embeddings.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvandWP_AI_Client {

	/**
	 * Plugin settings array.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->settings = get_option( 'avandwp_settings', array() );
	}

	/**
	 * Generate text response from the configured AI provider with automatic fallback.
	 *
	 * @param array $messages Array of ['role' => 'system'|'user'|'assistant', 'content' => '...'].
	 * @param array $args     Optional overrides: provider, model, temperature, max_tokens, feature, job_id.
	 * @return array|WP_Error ['content' => string, 'provider' => string, 'model' => string, 'tokens_in' => int, 'tokens_out' => int]
	 */
	public function chat( array $messages, array $args = array() ) {
		$primary  = ! empty( $args['provider'] ) ? sanitize_key( $args['provider'] ) : ( $this->settings['default_provider'] ?? 'gapgpt' );
		$fallback = ! empty( $this->settings['fallback_provider'] ) ? sanitize_key( $this->settings['fallback_provider'] ) : 'openai';

		$candidates = array_values( array_unique( array_filter( array( $primary, $fallback, 'gapgpt', 'openai', 'gemini', 'claude' ) ) ) );
		$Available  = array();
		foreach ( $candidates as $prov ) {
			if ( AvandWP_Vault::has_key( $prov ) ) {
				$Available[] = $prov;
			}
		}

		if ( empty( $Available ) ) {
			return new WP_Error(
				'avandwp_no_api_key',
				'هیچ کلید API فعالی در «تنظیمات و سلامت ← کلیدهای API» ثبت نشده است. لطفاً ابتدا کلید GapGPT یا OpenAI خود را وارد کنید.'
			);
		}

		$last_error = null;
		foreach ( $Available as $provider ) {
			$res = $this->dispatch_provider( $provider, $messages, $args );
			if ( ! is_wp_error( $res ) ) {
				$feature = ! empty( $args['feature'] ) ? sanitize_key( $args['feature'] ) : 'general';
				$job_id  = isset( $args['job_id'] ) ? (int) $args['job_id'] : 0;

				AvandWP_Logger::record_usage(
					$res['provider'],
					$res['model'],
					$res['tokens_in'],
					$res['tokens_out'],
					$feature,
					$job_id
				);
				return $res;
			}

			$last_error = $res;
			// Only fallback if error is quota/rate-limit/network related or if another provider is available.
			AvandWP_Logger::log(
				'ai.fallback',
				sprintf( 'خطا در سرویس %s (%s)؛ تلاش با سرویس جایگزین...', $provider, $res->get_error_message() ),
				'warning'
			);
		}

		return $last_error ? $last_error : new WP_Error( 'avandwp_ai_failed', 'خطا در ارتباط با سرویس هوش مصنوعی.' );
	}

	/**
	 * Dispatch request to a specific provider.
	 *
	 * @param string $provider Provider slug.
	 * @param array  $messages Messages array.
	 * @param array  $args     Request options.
	 * @return array|WP_Error
	 */
	private function dispatch_provider( $provider, array $messages, array $args ) {
		$key = AvandWP_Vault::get_key( $provider );
		if ( '' === $key ) {
			return new WP_Error( 'avandwp_missing_key', sprintf( 'کلید سرویس %s تنظیم نشده است.', $provider ) );
		}

		$temperature = isset( $args['temperature'] ) ? (float) $args['temperature'] : (float) ( $this->settings['temperature'] ?? 0.7 );
		$max_tokens  = isset( $args['max_tokens'] ) ? (int) $args['max_tokens'] : (int) ( $this->settings['max_tokens'] ?? 2500 );
		$timeout     = isset( $args['timeout'] ) ? (int) $args['timeout'] : (int) ( $this->settings['request_timeout'] ?? 60 );
		$timeout     = max( 15, min( 180, $timeout ) );

		switch ( $provider ) {
			case 'gapgpt':
			case 'openai':
				$base_url = ( 'gapgpt' === $provider ) ? 'https://api.gapgpt.app/v1' : 'https://api.openai.com/v1';
				$default_model = ( 'gapgpt' === $provider )
					? ( $this->settings['model_gapgpt'] ?? 'gpt-4o-mini' )
					: ( $this->settings['model_openai'] ?? 'gpt-4o-mini' );
				$model = ! empty( $args['model'] ) ? sanitize_text_field( $args['model'] ) : $default_model;
				return $this->call_openai_compatible( $provider, $base_url, $key, $model, $messages, $temperature, $max_tokens, $timeout );

			case 'gemini':
				$model = ! empty( $args['model'] ) ? sanitize_text_field( $args['model'] ) : ( $this->settings['model_gemini'] ?? 'gemini-1.5-flash' );
				return $this->call_gemini( $key, $model, $messages, $temperature, $max_tokens, $timeout );

			case 'claude':
				$model = ! empty( $args['model'] ) ? sanitize_text_field( $args['model'] ) : ( $this->settings['model_claude'] ?? 'claude-3-5-haiku-latest' );
				return $this->call_claude( $key, $model, $messages, $temperature, $max_tokens, $timeout );

			default:
				return new WP_Error( 'avandwp_invalid_provider', 'سرویس‌دهنده نامعتبر است.' );
		}
	}

	/**
	 * Call OpenAI / GapGPT chat completions endpoint.
	 */
	private function call_openai_compatible( $provider, $base_url, $key, $model, array $messages, $temperature, $max_tokens, $timeout ) {
		$endpoint = rtrim( $base_url, '/' ) . '/chat/completions';

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => $timeout,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'       => $model,
						'messages'    => $messages,
						'temperature' => $temperature,
						'max_tokens'  => $max_tokens,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'avandwp_http_error', 'خطای شبکه در اتصال به ' . $provider . ': ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $body['choices'][0]['message']['content'] ) ) {
			$err_msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'کد وضعیت HTTP ' . $code;
			return new WP_Error( 'avandwp_api_error', sprintf( 'خطای %s: %s', strtoupper( $provider ), $err_msg ) );
		}

		$content    = (string) $body['choices'][0]['message']['content'];
		$tokens_in  = isset( $body['usage']['prompt_tokens'] ) ? (int) $body['usage']['prompt_tokens'] : AvandWP_Text_Helper::estimate_tokens( wp_json_encode( $messages ) );
		$tokens_out = isset( $body['usage']['completion_tokens'] ) ? (int) $body['usage']['completion_tokens'] : AvandWP_Text_Helper::estimate_tokens( $content );

		return array(
			'content'    => $content,
			'provider'   => $provider,
			'model'      => $model,
			'tokens_in'  => $tokens_in,
			'tokens_out' => $tokens_out,
		);
	}

	/**
	 * Call Google Gemini generateContent endpoint.
	 */
	private function call_gemini( $key, $model, array $messages, $temperature, $max_tokens, $timeout ) {
		$endpoint = sprintf(
			'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
			rawurlencode( $model ),
			rawurlencode( $key )
		);

		$contents          = array();
		$system_parts_text = array();

		foreach ( $messages as $msg ) {
			$role = $msg['role'] ?? 'user';
			$text = (string) ( $msg['content'] ?? '' );
			if ( 'system' === $role ) {
				$system_parts_text[] = $text;
				continue;
			}
			$contents[] = array(
				'role'  => ( 'assistant' === $role ) ? 'model' : 'user',
				'parts' => array( array( 'text' => $text ) ),
			);
		}

		if ( empty( $contents ) ) {
			$contents[] = array(
				'role'  => 'user',
				'parts' => array( array( 'text' => implode( "\n", $system_parts_text ) ) ),
			);
			$system_parts_text = array();
		}

		$payload = array(
			'contents'         => $contents,
			'generationConfig' => array(
				'temperature'     => $temperature,
				'maxOutputTokens' => $max_tokens,
			),
		);

		if ( ! empty( $system_parts_text ) ) {
			$payload['systemInstruction'] = array(
				'parts' => array( array( 'text' => implode( "\n\n", $system_parts_text ) ) ),
			);
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => $timeout,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'avandwp_gemini_http', 'خطای شبکه Gemini: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		$content = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
		if ( $code < 200 || $code >= 300 || '' === $content ) {
			$err_msg = $body['error']['message'] ?? ( 'HTTP ' . $code );
			return new WP_Error( 'avandwp_gemini_error', 'خطای Gemini: ' . $err_msg );
		}

		$tokens_in  = isset( $body['usageMetadata']['promptTokenCount'] ) ? (int) $body['usageMetadata']['promptTokenCount'] : AvandWP_Text_Helper::estimate_tokens( wp_json_encode( $messages ) );
		$tokens_out = isset( $body['usageMetadata']['candidatesTokenCount'] ) ? (int) $body['usageMetadata']['candidatesTokenCount'] : AvandWP_Text_Helper::estimate_tokens( $content );

		return array(
			'content'    => (string) $content,
			'provider'   => 'gemini',
			'model'      => $model,
			'tokens_in'  => $tokens_in,
			'tokens_out' => $tokens_out,
		);
	}

	/**
	 * Call Anthropic Claude messages endpoint.
	 */
	private function call_claude( $key, $model, array $messages, $temperature, $max_tokens, $timeout ) {
		$system_prompt = '';
		$chat_messages = array();

		foreach ( $messages as $msg ) {
			$role = $msg['role'] ?? 'user';
			$text = (string) ( $msg['content'] ?? '' );
			if ( 'system' === $role ) {
				$system_prompt .= ( '' !== $system_prompt ? "\n\n" : '' ) . $text;
			} else {
				$chat_messages[] = array(
					'role'    => ( 'assistant' === $role ) ? 'assistant' : 'user',
					'content' => $text,
				);
			}
		}

		$payload = array(
			'model'       => $model,
			'max_tokens'  => $max_tokens,
			'temperature' => $temperature,
			'messages'    => $chat_messages,
		);
		if ( '' !== $system_prompt ) {
			$payload['system'] = $system_prompt;
		}

		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => $timeout,
				'headers' => array(
					'x-api-key'         => $key,
					'anthropic-version' => '2023-06-01',
					'Content-Type'      => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'avandwp_claude_http', 'خطای شبکه Claude: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		$content = $body['content'][0]['text'] ?? '';
		if ( $code < 200 || $code >= 300 || '' === $content ) {
			$err_msg = $body['error']['message'] ?? ( 'HTTP ' . $code );
			return new WP_Error( 'avandwp_claude_error', 'خطای Claude: ' . $err_msg );
		}

		$tokens_in  = isset( $body['usage']['input_tokens'] ) ? (int) $body['usage']['input_tokens'] : AvandWP_Text_Helper::estimate_tokens( wp_json_encode( $messages ) );
		$tokens_out = isset( $body['usage']['output_tokens'] ) ? (int) $body['usage']['output_tokens'] : AvandWP_Text_Helper::estimate_tokens( $content );

		return array(
			'content'    => (string) $content,
			'provider'   => 'claude',
			'model'      => $model,
			'tokens_in'  => $tokens_in,
			'tokens_out' => $tokens_out,
		);
	}

	/**
	 * Test live connection to a specific AI provider.
	 *
	 * @param string $provider Provider slug.
	 * @return array|WP_Error
	 */
	public function test_connection( $provider ) {
		$provider = sanitize_key( $provider );
		$start    = microtime( true );
		$res      = $this->dispatch_provider(
			$provider,
			array(
				array(
					'role'    => 'user',
					'content' => 'فقط کلمه «متصل» را برگردان.',
				),
			),
			array(
				'max_tokens' => 20,
				'timeout'    => 20,
			)
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$latency_ms = (int) round( ( microtime( true ) - $start ) * 1000 );
		return array(
			'provider'   => $provider,
			'model'      => $res['model'],
			'reply'      => trim( $res['content'] ),
			'latency_ms' => $latency_ms,
		);
	}

	/**
	 * Transcribe an uploaded audio file using Whisper (`whisper-1`).
	 *
	 * Fixes the bug where $file['tmp_name'] was passed after wp_handle_upload()
	 * had already moved the file.
	 *
	 * @param string $file_path Absolute path to the saved audio file (`$uploaded['file']`).
	 * @return string|WP_Error Transcribed text or WP_Error.
	 */
	public function transcribe_audio_file( $file_path ) {
		if ( ! is_string( $file_path ) || '' === $file_path || ! is_readable( $file_path ) ) {
			return new WP_Error( 'avandwp_audio_unreadable', 'فایل صوتی برای تبدیل به متن قابل خواندن نیست.' );
		}

		$provider = AvandWP_Vault::has_key( 'gapgpt' ) ? 'gapgpt' : ( AvandWP_Vault::has_key( 'openai' ) ? 'openai' : '' );
		if ( '' === $provider ) {
			return new WP_Error( 'avandwp_whisper_no_key', 'برای تبدیل صوت به متن، کلید GapGPT یا OpenAI الزامی است.' );
		}

		$key      = AvandWP_Vault::get_key( $provider );
		$endpoint = ( 'gapgpt' === $provider )
			? 'https://api.gapgpt.app/v1/audio/transcriptions'
			: 'https://api.openai.com/v1/audio/transcriptions';

		$boundary      = '----AvandWPBoundary' . wp_generate_password( 16, false );
		$file_contents = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $file_contents ) {
			return new WP_Error( 'avandwp_audio_read_error', 'خطا در خواندن محتوای فایل صوتی.' );
		}

		$filename = basename( $file_path );
		$body     = '';
		$body    .= "--{$boundary}\r\n";
		$body    .= "Content-Disposition: form-data; name=\"model\"\r\n\r\nwhisper-1\r\n";
		$body    .= "--{$boundary}\r\n";
		$body    .= "Content-Disposition: form-data; name=\"language\"\r\n\r\nfa\r\n";
		$body    .= "--{$boundary}\r\n";
		$body    .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
		$body    .= "Content-Type: application/octet-stream\r\n\r\n";
		$body    .= $file_contents . "\r\n";
		$body    .= "--{$boundary}--\r\n";

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $data['text'] ) ) {
			$msg = $data['error']['message'] ?? ( 'HTTP ' . $code );
			return new WP_Error( 'avandwp_whisper_failed', 'خطا در تبدیل صوت به متن: ' . $msg );
		}

		return sanitize_textarea_field( (string) $data['text'] );
	}

	/**
	 * Synthesize speech (TTS) from text and save MP3 to WordPress Media Library.
	 *
	 * @param string $text  Input text (up to 3500 chars).
	 * @param string $voice Voice ID ('alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer').
	 * @return array|WP_Error ['url' => string, 'attachment_id' => int]
	 */
	public function generate_speech( $text, $voice = 'nova' ) {
		$plain = wp_strip_all_tags( (string) $text );
		if ( function_exists( 'mb_substr' ) ) {
			$plain = mb_substr( $plain, 0, 3500, 'UTF-8' );
		} else {
			$plain = substr( $plain, 0, 3500 );
		}

		if ( '' === trim( $plain ) ) {
			return new WP_Error( 'avandwp_tts_empty', 'متن ورودی برای تولید فایل صوتی خالی است.' );
		}

		$allowed_voices = array( 'alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer' );
		if ( ! in_array( $voice, $allowed_voices, true ) ) {
			$voice = 'nova';
		}

		$provider = AvandWP_Vault::has_key( 'gapgpt' ) ? 'gapgpt' : ( AvandWP_Vault::has_key( 'openai' ) ? 'openai' : '' );
		if ( '' === $provider ) {
			return new WP_Error( 'avandwp_tts_no_key', 'برای تولید پادکست صوتی، کلید GapGPT یا OpenAI الزامی است.' );
		}

		$key      = AvandWP_Vault::get_key( $provider );
		$endpoint = ( 'gapgpt' === $provider )
			? 'https://api.gapgpt.app/v1/audio/speech'
			: 'https://api.openai.com/v1/audio/speech';

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'           => 'tts-1',
						'input'           => $plain,
						'voice'           => $voice,
						'response_format' => 'mp3',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code  = (int) wp_remote_retrieve_response_code( $response );
		$bytes = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 || strlen( $bytes ) < 256 ) {
			return new WP_Error( 'avandwp_tts_api_error', 'خطا در دریافت فایل صوتی از سرویس هوش مصنوعی (کد ' . $code . ').' );
		}

		$filename = 'avandwp-audio-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false ) . '.mp3';
		$upload   = wp_upload_bits( $filename, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'avandwp_tts_upload_error', $upload['error'] );
		}

		$attachment = array(
			'post_mime_type' => 'audio/mpeg',
			'post_title'     => 'پادکست صوتی آوند — ' . current_time( 'Y-m-d H:i' ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);
		$attach_id  = wp_insert_attachment( $attachment, $upload['file'] );

		AvandWP_Logger::record_usage( $provider, 'tts-1', AvandWP_Text_Helper::estimate_tokens( $plain ), 0, 'tts' );

		return array(
			'url'           => $upload['url'],
			'attachment_id' => (int) $attach_id,
			'voice'         => $voice,
		);
	}

	/**
	 * Generate a real embedding vector via `/v1/embeddings` (`text-embedding-3-small`).
	 *
	 * @param string $text Input text.
	 * @return array<int, float>|WP_Error Array of floats or WP_Error.
	 */
	public function create_embedding( $text ) {
		$plain = trim( wp_strip_all_tags( (string) $text ) );
		if ( '' === $plain ) {
			return new WP_Error( 'avandwp_embed_empty', 'متن ورودی برای ساخت Embedding خالی است.' );
		}

		$provider = AvandWP_Vault::has_key( 'gapgpt' ) ? 'gapgpt' : ( AvandWP_Vault::has_key( 'openai' ) ? 'openai' : '' );
		if ( '' === $provider ) {
			return new WP_Error( 'avandwp_embed_no_key', 'کلید GapGPT یا OpenAI برای Embeddings تنظیم نشده است.' );
		}

		$key      = AvandWP_Vault::get_key( $provider );
		$endpoint = ( 'gapgpt' === $provider )
			? 'https://api.gapgpt.app/v1/embeddings'
			: 'https://api.openai.com/v1/embeddings';

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model' => 'text-embedding-3-small',
						'input' => function_exists( 'mb_substr' ) ? mb_substr( $plain, 0, 4000, 'UTF-8' ) : substr( $plain, 0, 4000 ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $body['data'][0]['embedding'] ) || ! is_array( $body['data'][0]['embedding'] ) ) {
			return new WP_Error( 'avandwp_embed_failed', 'خطا در دریافت بردار معنایی (Embedding).' );
		}

		$tokens = isset( $body['usage']['total_tokens'] ) ? (int) $body['usage']['total_tokens'] : AvandWP_Text_Helper::estimate_tokens( $plain );
		AvandWP_Logger::record_usage( $provider, 'text-embedding-3-small', $tokens, 0, 'embedding' );

		return array_map( 'floatval', $body['data'][0]['embedding'] );
	}
}
