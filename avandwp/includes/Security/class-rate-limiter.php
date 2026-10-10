<?php
/**
 * Fixed-window IP Rate Limiter for public REST endpoints.
 *
 * Fixes the bug in legacy implementations where set_transient() reset the TTL
 * on every hit, causing active attackers to stay permanently blocked or windows
 * to drift unpredictably.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvandWP_Rate_Limiter {

	/**
	 * Check and increment the request counter for the current client IP and bucket.
	 *
	 * @param string $bucket         Endpoint identifier (e.g. 'chat', 'support', 'audio').
	 * @param int    $max_requests   Maximum allowed requests within the window.
	 * @param int    $window_seconds Window duration in seconds.
	 * @return true|WP_Error True if allowed, WP_Error (HTTP 429) if rate limit exceeded.
	 */
	public static function check( $bucket, $max_requests = 15, $window_seconds = 60 ) {
		$ip  = self::get_client_ip();
		$key = 'avandwp_rl_' . md5( $bucket . '|' . $ip );
		$now = time();

		$state = get_transient( $key );
		if ( ! is_array( $state ) || empty( $state['expires'] ) || $state['expires'] <= $now ) {
			$state = array(
				'count'   => 1,
				'expires' => $now + max( 1, (int) $window_seconds ),
			);
			set_transient( $key, $state, max( 1, (int) $window_seconds ) );
			return true;
		}

		if ( (int) $state['count'] >= (int) $max_requests ) {
			$retry_after = max( 1, (int) $state['expires'] - $now );
			return new WP_Error(
				'avandwp_rate_limited',
				sprintf(
					/* translators: %d: seconds remaining */
					'تعداد درخواست‌های شما بیش از حد مجاز است. لطفاً %d ثانیه دیگر دوباره تلاش کنید.',
					$retry_after
				),
				array( 'status' => 429 )
			);
		}

		$state['count'] = (int) $state['count'] + 1;
		$remaining_ttl  = max( 1, (int) $state['expires'] - $now );
		set_transient( $key, $state, $remaining_ttl );
		return true;
	}

	/**
	 * Resolve client IP safely.
	 *
	 * @return string
	 */
	public static function get_client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '127.0.0.1';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '127.0.0.1';
	}
}
