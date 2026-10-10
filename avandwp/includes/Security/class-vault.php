<?php
/**
 * AES-256-GCM Credential Vault for storing API keys securely.
 *
 * Uses WordPress AUTH_KEY and SECURE_AUTH_SALT to derive a 256-bit key.
 * Keys are never exposed in plaintext in HTML templates or localized JS.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvandWP_Vault {

	const OPTION_KEY = 'avandwp_vault_keys';
	const CIPHER     = 'aes-256-gcm';

	/**
	 * Supported credential slots.
	 *
	 * @return array<string, string>
	 */
	public static function supported_slots() {
		return array(
			'gapgpt'        => 'کلید GapGPT (درگاه ریالی و بدون تحریم)',
			'openai'        => 'کلید OpenAI (مستقیم)',
			'gemini'        => 'کلید Google Gemini',
			'claude'        => 'کلید Anthropic Claude',
			'fal_flux'      => 'کلید FAL.ai (تصویرساز Flux)',
			'stability'     => 'کلید Stability AI (تصویرساز SD3)',
			'telegram_bot'  => 'توکن ربات تلگرام (Bot Token)',
		);
	}

	/**
	 * Derive 32-byte binary key from WordPress salts.
	 *
	 * @return string
	 */
	private static function derive_key() {
		$auth_key  = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'avandwp-default-auth-key';
		$auth_salt = defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : 'avandwp-default-auth-salt';
		return hash( 'sha256', $auth_key . '|' . $auth_salt . '|avandwp_v1', true );
	}

	/**
	 * Encrypt a plaintext secret.
	 *
	 * @param string $plaintext Secret string.
	 * @return string Encrypted payload (prefixed with v1:).
	 */
	public static function encrypt( $plaintext ) {
		$plaintext = trim( (string) $plaintext );
		if ( '' === $plaintext ) {
			return '';
		}

		if ( function_exists( 'openssl_encrypt' ) && in_array( self::CIPHER, openssl_get_cipher_methods(), true ) ) {
			$iv_len = openssl_cipher_iv_length( self::CIPHER );
			$iv     = random_bytes( $iv_len > 0 ? $iv_len : 12 );
			$tag    = '';
			$cipher = openssl_encrypt(
				$plaintext,
				self::CIPHER,
				self::derive_key(),
				OPENSSL_RAW_DATA,
				$iv,
				$tag,
				'',
				16
			);
			if ( false !== $cipher ) {
				return 'v1:' . base64_encode( $iv . $tag . $cipher );
			}
		}

		return 'b64:' . base64_encode( $plaintext );
	}

	/**
	 * Decrypt an encrypted secret payload.
	 *
	 * @param string $payload Stored payload.
	 * @return string Decrypted plaintext or empty string on failure.
	 */
	public static function decrypt( $payload ) {
		if ( ! is_string( $payload ) || '' === $payload ) {
			return '';
		}

		if ( 0 === strpos( $payload, 'v1:' ) ) {
			if ( ! function_exists( 'openssl_decrypt' ) ) {
				return '';
			}
			$raw = base64_decode( substr( $payload, 3 ), true );
			if ( false === $raw || strlen( $raw ) < 28 ) {
				return '';
			}
			$iv     = substr( $raw, 0, 12 );
			$tag    = substr( $raw, 12, 16 );
			$cipher = substr( $raw, 28 );
			$plain  = openssl_decrypt(
				$cipher,
				self::CIPHER,
				self::derive_key(),
				OPENSSL_RAW_DATA,
				$iv,
				$tag
			);
			return false !== $plain ? $plain : '';
		}

		if ( 0 === strpos( $payload, 'b64:' ) ) {
			$plain = base64_decode( substr( $payload, 4 ), true );
			return false !== $plain ? $plain : '';
		}

		return '';
	}

	/**
	 * Store a single credential in the vault.
	 *
	 * @param string $slot  Provider slot key.
	 * @param string $value Plaintext API key (pass empty string to clear).
	 * @return bool
	 */
	public static function set_key( $slot, $value ) {
		$slot  = sanitize_key( $slot );
		$store = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $store ) ) {
			$store = array();
		}

		$value = trim( (string) $value );
		if ( '' === $value ) {
			unset( $store[ $slot ] );
		} else {
			$store[ $slot ] = self::encrypt( $value );
		}

		return update_option( self::OPTION_KEY, $store, false );
	}

	/**
	 * Retrieve a decrypted credential from the vault.
	 *
	 * @param string $slot Provider slot key.
	 * @return string
	 */
	public static function get_key( $slot ) {
		$slot  = sanitize_key( $slot );
		$store = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $store ) || empty( $store[ $slot ] ) ) {
			return '';
		}
		return self::decrypt( $store[ $slot ] );
	}

	/**
	 * Check whether a slot currently has a valid stored key.
	 *
	 * @param string $slot Provider slot key.
	 * @return bool
	 */
	public static function has_key( $slot ) {
		return '' !== self::get_key( $slot );
	}

	/**
	 * Return a safe masked representation of a stored key (e.g., "sk-p...9a2f") for admin UI display.
	 *
	 * @param string $slot Provider slot key.
	 * @return string Masked string or empty string if not set.
	 */
	public static function get_masked_key( $slot ) {
		$plain = self::get_key( $slot );
		if ( '' === $plain ) {
			return '';
		}
		$len = strlen( $plain );
		if ( $len <= 8 ) {
			return str_repeat( '•', $len );
		}
		return substr( $plain, 0, 4 ) . '••••••••' . substr( $plain, -4 );
	}
}
