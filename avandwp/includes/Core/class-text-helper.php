<?php
/**
 * Persian-aware UTF-8 text, HTML sanitization, and token estimation helper.
 *
 * Replaces PHP's ASCII-only str_word_count() which returns 0 on Persian text.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvandWP_Text_Helper {

	/**
	 * Count words accurately across Persian, Arabic, and Latin scripts.
	 *
	 * @param string $text Raw or HTML text.
	 * @return int Number of words.
	 */
	public static function word_count( $text ) {
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return 0;
		}
		$plain = wp_strip_all_tags( $text );
		// Normalize zero-width non-joiners (نیم‌فاصله) so compound words count as one word.
		$plain = str_replace( "\xE2\x80\x8C", '', $plain );
		$plain = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $plain );
		$plain = trim( preg_replace( '/\s+/u', ' ', (string) $plain ) );
		if ( '' === $plain ) {
			return 0;
		}
		$words = preg_split( '/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $words ) ? count( $words ) : 0;
	}

	/**
	 * Normalize Persian and Arabic characters for consistent search & tokenization.
	 *
	 * @param string $text Input text.
	 * @return string Normalized lowercase UTF-8 string.
	 */
	public static function normalize_persian( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return '';
		}
		$text = wp_strip_all_tags( $text );
		$map  = array(
			'ي' => 'ی',
			'ى' => 'ی',
			'ئ' => 'ی',
			'ك' => 'ک',
			'ة' => 'ه',
			'ۀ' => 'ه',
			'أ' => 'ا',
			'إ' => 'ا',
			'آ' => 'ا',
			'ؤ' => 'و',
			"\xE2\x80\x8C" => ' ', // ZWNJ to space for token matching
		);
		$text = strtr( $text, $map );
		if ( function_exists( 'mb_strtolower' ) ) {
			$text = mb_strtolower( $text, 'UTF-8' );
		} else {
			$text = strtolower( $text );
		}
		$text = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $text );
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		return $text;
	}

	/**
	 * Extract meaningful search tokens (excluding common Persian/English stop words).
	 *
	 * @param string $text Input text.
	 * @return array<int, string> Unique tokens of length >= 2.
	 */
	public static function tokenize( $text ) {
		$norm = self::normalize_persian( $text );
		if ( '' === $norm ) {
			return array();
		}
		$parts = preg_split( '/\s+/u', $norm, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $parts ) ) {
			return array();
		}

		$stop_words = array(
			'در', 'به', 'از', 'که', 'می', 'این', 'است', 'را', 'با', 'های',
			'برای', 'آن', 'یک', 'شود', 'شده', 'خود', 'ها', 'کرد', 'بر', 'هم',
			'نیز', 'گفت', 'ما', 'شما', 'او', 'آنها', 'یا', 'اما', 'اگر', 'تا',
			'کند', 'کنند', 'بود', 'باشند', 'باشد', 'دارد', 'دارند', 'چگونه', 'چطور',
			'چیست', 'کدام', 'آیا', 'the', 'and', 'for', 'with', 'that', 'this', 'from',
		);
		$stop_lookup = array_flip( $stop_words );

		$tokens = array();
		foreach ( $parts as $word ) {
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $word, 'UTF-8' ) : strlen( $word );
			if ( $len < 2 || isset( $stop_lookup[ $word ] ) ) {
				continue;
			}
			$tokens[] = $word;
		}
		return array_values( array_unique( $tokens ) );
	}

	/**
	 * Clean AI output (strip markdown code fences, convert basic markdown if needed, and sanitize HTML).
	 *
	 * @param string $raw Raw AI response.
	 * @return string Safe HTML suitable for WordPress post_content.
	 */
	public static function clean_ai_html( $raw ) {
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return '';
		}
		$text = trim( $raw );
		// Remove surrounding ```html ... ``` or ``` ... ``` fences.
		$text = preg_replace( '/^```(?:html|xml|markdown)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```$/', '', (string) $text );

		// Convert stray markdown headings or bold if the model mixed markdown with HTML.
		$text = preg_replace( '/^###\s+(.+)$/m', '<h3>$1</h3>', (string) $text );
		$text = preg_replace( '/^##\s+(.+)$/m', '<h2>$1</h2>', (string) $text );
		$text = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', (string) $text );

		return wp_kses_post( trim( (string) $text ) );
	}

	/**
	 * Parse JSON from AI output safely (tolerating markdown ```json fences).
	 *
	 * @param string $raw Raw AI response.
	 * @return array|null Decoded associative array or null on failure.
	 */
	public static function parse_json_response( $raw ) {
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return null;
		}
		$clean = trim( $raw );
		$clean = preg_replace( '/^```(?:json)?\s*/i', '', $clean );
		$clean = preg_replace( '/\s*```$/', '', (string) $clean );
		$decoded = json_decode( trim( (string) $clean ), true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
		// Attempt to extract the first JSON object {...} or array [...] block.
		if ( preg_match( '/(\{[\s\S]*\}|\[[\s\S]*\])/', $clean, $matches ) ) {
			$decoded = json_decode( $matches[1], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return null;
	}

	/**
	 * Estimate token count for UTF-8 strings when provider does not return usage.
	 *
	 * @param string $text Input text.
	 * @return int Estimated tokens.
	 */
	public static function estimate_tokens( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return 0;
		}
		$chars = function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
		return max( 1, (int) ceil( $chars / 3.2 ) );
	}
}
