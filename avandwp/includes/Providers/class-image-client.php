<?php
/**
 * Multi-Provider Image Generation Client (DALL-E 3 via GapGPT/OpenAI, FAL Flux, Stability SD3).
 *
 * Fixes the async queue endpoint bug in legacy Flux_Client by calling the synchronous
 * `https://fal.run/fal-ai/flux/schnell` endpoint and transparently reporting the
 * real provider used.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvandWP_Image_Client {

	/**
	 * Generate an image and save it to the WordPress Media Library.
	 *
	 * @param string $prompt            Visual description prompt.
	 * @param array  $args              Optional: provider, size ('1024x1024', '1792x1024', '1024x1792'), alt_text, post_id.
	 * @return array|WP_Error ['attachment_id' => int, 'url' => string, 'provider' => string, 'is_placeholder' => bool]
	 */
	public function generate_and_attach( $prompt, array $args = array() ) {
		$prompt = trim( sanitize_textarea_field( (string) $prompt ) );
		if ( '' === $prompt ) {
			return new WP_Error( 'avandwp_empty_image_prompt', 'توضیح تصویر (پرامپت) نمی‌تواند خالی باشد.' );
		}

		$settings   = get_option( 'avandwp_settings', array() );
		$preferred  = ! empty( $args['provider'] ) ? sanitize_key( $args['provider'] ) : ( $settings['image_provider'] ?? 'gapgpt' );
		$size       = ! empty( $args['size'] ) ? sanitize_text_field( $args['size'] ) : '1024x1024';
		$alt_text   = ! empty( $args['alt_text'] ) ? sanitize_text_field( $args['alt_text'] ) : $prompt;
		$parent_id  = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;

		$order = array_values( array_unique( array( $preferred, 'gapgpt', 'openai', 'fal_flux', 'stability' ) ) );
		$last_error = null;

		foreach ( $order as $provider ) {
			if ( ! AvandWP_Vault::has_key( $provider ) ) {
				continue;
			}

			$res = $this->call_provider( $provider, $prompt, $size );
			if ( ! is_wp_error( $res ) && ! empty( $res['bytes'] ) ) {
				$saved = $this->save_to_media_library(
					$res['bytes'],
					$res['mime'] ?? 'image/png',
					$res['ext'] ?? 'png',
					$alt_text,
					$parent_id
				);
				if ( is_wp_error( $saved ) ) {
					return $saved;
				}

				AvandWP_Logger::record_usage( $provider, $res['model'], 500, 0, 'image' );
				AvandWP_Logger::log( 'image.generated', sprintf( 'تصویر با سرویس %s تولید و در رسانه ذخیره شد (#%d).', $provider, $saved['attachment_id'] ), 'success' );

				return array(
					'attachment_id'  => $saved['attachment_id'],
					'url'            => $saved['url'],
					'provider'       => $provider,
					'model'          => $res['model'],
					'is_placeholder' => false,
				);
			}

			$last_error = $res;
		}

		if ( $last_error instanceof WP_Error ) {
			return $last_error;
		}

		return new WP_Error(
			'avandwp_no_image_key',
			'هیچ کلید فعالی برای تولید تصویر (GapGPT، OpenAI، FAL Flux یا Stability) در گاوصندوق کلیدها ثبت نشده است.'
		);
	}

	/**
	 * Dispatch image generation request to a specific provider.
	 *
	 * @param string $provider Provider key.
	 * @param string $prompt   Image prompt.
	 * @param string $size     Dimensions string.
	 * @return array|WP_Error ['bytes' => string, 'mime' => string, 'ext' => string, 'model' => string]
	 */
	private function call_provider( $provider, $prompt, $size ) {
		$key = AvandWP_Vault::get_key( $provider );
		switch ( $provider ) {
			case 'gapgpt':
			case 'openai':
				return $this->call_dalle( $provider, $key, $prompt, $size );

			case 'fal_flux':
				return $this->call_fal_flux( $key, $prompt, $size );

			case 'stability':
				return $this->call_stability( $key, $prompt );

			default:
				return new WP_Error( 'avandwp_unknown_image_provider', 'سرویس تصویر نامعتبر است.' );
		}
	}

	/**
	 * Call DALL-E 3 via GapGPT or OpenAI.
	 */
	private function call_dalle( $provider, $key, $prompt, $size ) {
		$endpoint = ( 'gapgpt' === $provider )
			? 'https://api.gapgpt.app/v1/images/generations'
			: 'https://api.openai.com/v1/images/generations';

		$allowed_sizes = array( '1024x1024', '1792x1024', '1024x1792' );
		if ( ! in_array( $size, $allowed_sizes, true ) ) {
			$size = '1024x1024';
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 75,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'           => 'dall-e-3',
						'prompt'          => $prompt,
						'n'               => 1,
						'size'            => $size,
						'response_format' => 'b64_json',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = $body['error']['message'] ?? ( 'HTTP ' . $code );
			return new WP_Error( 'avandwp_dalle_error', sprintf( 'خطای تصویرساز %s: %s', strtoupper( $provider ), $msg ) );
		}

		if ( ! empty( $body['data'][0]['b64_json'] ) ) {
			$bytes = base64_decode( $body['data'][0]['b64_json'], true );
			if ( false !== $bytes ) {
				return array(
					'bytes' => $bytes,
					'mime'  => 'image/png',
					'ext'   => 'png',
					'model' => 'dall-e-3',
				);
			}
		}

		if ( ! empty( $body['data'][0]['url'] ) ) {
			return $this->download_image_bytes( $body['data'][0]['url'], 'dall-e-3' );
		}

		return new WP_Error( 'avandwp_dalle_empty', 'پاسخ تصویرساز فاقد داده تصویر بود.' );
	}

	/**
	 * Call FAL.ai Flux Schnell synchronous endpoint (`https://fal.run/fal-ai/flux/schnell`).
	 */
	private function call_fal_flux( $key, $prompt, $size ) {
		$image_size = 'square_hd';
		if ( '1792x1024' === $size ) {
			$image_size = 'landscape_16_9';
		} elseif ( '1024x1792' === $size ) {
			$image_size = 'portrait_16_9';
		}

		$response = wp_remote_post(
			'https://fal.run/fal-ai/flux/schnell',
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Key ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'prompt'              => $prompt,
						'image_size'          => $image_size,
						'num_inference_steps' => 4,
						'num_images'          => 1,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || empty( $body['images'][0]['url'] ) ) {
			$msg = $body['detail'] ?? ( 'HTTP ' . $code );
			return new WP_Error( 'avandwp_flux_error', 'خطای FAL Flux: ' . ( is_string( $msg ) ? $msg : wp_json_encode( $msg ) ) );
		}

		return $this->download_image_bytes( $body['images'][0]['url'], 'flux-schnell' );
	}

	/**
	 * Call Stability AI SD3 endpoint.
	 */
	private function call_stability( $key, $prompt ) {
		$boundary = '----AvandWPStability' . wp_generate_password( 12, false );
		$fields   = array(
			'prompt'        => $prompt,
			'output_format' => 'png',
			'aspect_ratio'  => '16:9',
		);

		$body = '';
		foreach ( $fields as $name => $val ) {
			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$val}\r\n";
		}
		$body .= "--{$boundary}--\r\n";

		$response = wp_remote_post(
			'https://api.stability.ai/v2beta/stable-image/generate/sd3',
			array(
				'timeout' => 60,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Accept'        => 'image/*',
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code  = (int) wp_remote_retrieve_response_code( $response );
		$bytes = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 || strlen( $bytes ) < 512 ) {
			return new WP_Error( 'avandwp_stability_error', 'خطای Stability AI (کد ' . $code . ').' );
		}

		return array(
			'bytes' => $bytes,
			'mime'  => 'image/png',
			'ext'   => 'png',
			'model' => 'sd3',
		);
	}

	/**
	 * Download remote image bytes safely.
	 */
	private function download_image_bytes( $url, $model ) {
		if ( ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'avandwp_invalid_image_url', 'آدرس تصویر دریافتی معتبر نیست.' );
		}

		$res = wp_remote_get( $url, array( 'timeout' => 45 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code  = (int) wp_remote_retrieve_response_code( $res );
		$bytes = wp_remote_retrieve_body( $res );
		if ( $code < 200 || $code >= 300 || strlen( $bytes ) < 256 ) {
			return new WP_Error( 'avandwp_image_download_failed', 'خطا در دانلود تصویر تولیدشده.' );
		}

		$content_type = wp_remote_retrieve_header( $res, 'content-type' );
		$ext          = 'png';
		$mime         = 'image/png';
		if ( is_string( $content_type ) && false !== strpos( $content_type, 'jpeg' ) ) {
			$ext  = 'jpg';
			$mime = 'image/jpeg';
		} elseif ( is_string( $content_type ) && false !== strpos( $content_type, 'webp' ) ) {
			$ext  = 'webp';
			$mime = 'image/webp';
		}

		return array(
			'bytes' => $bytes,
			'mime'  => $mime,
			'ext'   => $ext,
			'model' => $model,
		);
	}

	/**
	 * Save raw image binary to WordPress uploads and insert attachment metadata.
	 */
	private function save_to_media_library( $bytes, $mime, $ext, $alt_text, $parent_post_id = 0 ) {
		$filename = 'avandwp-img-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false ) . '.' . $ext;
		$upload   = wp_upload_bits( $filename, null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'avandwp_upload_error', $upload['error'] );
		}

		$attachment = array(
			'post_mime_type' => $mime,
			'post_title'     => sanitize_text_field( mb_substr( $alt_text, 0, 80, 'UTF-8' ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $upload['file'], (int) $parent_post_id );
		if ( is_wp_error( $attach_id ) || ! $attach_id ) {
			return new WP_Error( 'avandwp_attach_error', 'خطا در ثبت تصویر در کتابخانه رسانه وردپرس.' );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
		wp_update_attachment_metadata( $attach_id, $attach_data );
		update_post_meta( $attach_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );

		return array(
			'attachment_id' => (int) $attach_id,
			'url'           => $upload['url'],
		);
	}
}
