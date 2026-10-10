<?php
/**
 * Activity Logger and Token/Cost Usage Tracker.
 *
 * Records operational events in `avandwp_activity_log` and real token consumption
 * in `avandwp_usage`. Never fabricates numbers.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvandWP_Logger {

	/**
	 * Record an operational activity log entry.
	 *
	 * @param string $action  Short action identifier (e.g. 'studio.article', 'woo.optimize').
	 * @param string $message Human-readable Persian description.
	 * @param string $level   'info' | 'success' | 'warning' | 'error'.
	 * @param array  $context Additional metadata stored as JSON.
	 * @return int|false Inserted row ID or false.
	 */
	public static function log( $action, $message, $level = 'info', $context = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'avandwp_activity_log';

		$allowed_levels = array( 'info', 'success', 'warning', 'error' );
		if ( ! in_array( $level, $allowed_levels, true ) ) {
			$level = 'info';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert(
			$table,
			array(
				'user_id'    => get_current_user_id(),
				'action'     => sanitize_text_field( substr( (string) $action, 0, 100 ) ),
				'level'      => $level,
				'message'    => sanitize_text_field( (string) $message ),
				'context'    => ! empty( $context ) ? wp_json_encode( $context, JSON_UNESCAPED_UNICODE ) : null,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Record real API token usage.
	 *
	 * @param string $provider  Provider key (e.g. 'gapgpt', 'openai', 'gemini', 'claude').
	 * @param string $model     Model identifier.
	 * @param int    $tokens_in Prompt tokens.
	 * @param int    $tokens_out Completion tokens.
	 * @param string $feature   Feature slug (e.g. 'studio', 'woo', 'chat').
	 * @param int    $job_id    Optional background job ID.
	 * @return int|false
	 */
	public static function record_usage( $provider, $model, $tokens_in, $tokens_out, $feature = 'general', $job_id = 0 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'avandwp_usage';

		$tokens_in   = max( 0, (int) $tokens_in );
		$tokens_out  = max( 0, (int) $tokens_out );
		$total       = $tokens_in + $tokens_out;
		$cost_usd    = self::estimate_cost_usd( $model, $tokens_in, $tokens_out );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert(
			$table,
			array(
				'user_id'      => get_current_user_id(),
				'job_id'       => max( 0, (int) $job_id ),
				'provider'     => sanitize_key( $provider ),
				'model'        => sanitize_text_field( (string) $model ),
				'feature'      => sanitize_key( $feature ),
				'tokens_in'    => $tokens_in,
				'tokens_out'   => $tokens_out,
				'total_tokens' => $total,
				'cost_usd'     => $cost_usd,
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%f', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Estimate USD cost based on standard published per-1M-token rates.
	 *
	 * @param string $model      Model name.
	 * @param int    $tokens_in  Input tokens.
	 * @param int    $tokens_out Output tokens.
	 * @return float
	 */
	public static function estimate_cost_usd( $model, $tokens_in, $tokens_out ) {
		$m = strtolower( (string) $model );
		// Default mini-tier rate ($0.15 / 1M in, $0.60 / 1M out)
		$in_rate  = 0.15;
		$out_rate = 0.60;

		if ( false !== strpos( $m, 'gpt-4o' ) && false === strpos( $m, 'mini' ) ) {
			$in_rate  = 2.50;
			$out_rate = 10.00;
		} elseif ( false !== strpos( $m, 'claude-3-5-sonnet' ) || false !== strpos( $m, 'claude-3-7-sonnet' ) ) {
			$in_rate  = 3.00;
			$out_rate = 15.00;
		} elseif ( false !== strpos( $m, 'gemini-1.5-pro' ) ) {
			$in_rate  = 1.25;
			$out_rate = 5.00;
		}

		$cost = ( ( $tokens_in / 1000000 ) * $in_rate ) + ( ( $tokens_out / 1000000 ) * $out_rate );
		return round( $cost, 6 );
	}

	/**
	 * Fetch recent activity logs.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function get_recent_logs( $limit = 30 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'avandwp_activity_log';
		$limit = max( 1, min( 200, (int) $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get aggregated usage summary for the last N days.
	 *
	 * @param int $days Window in days.
	 * @return array
	 */
	public static function get_usage_summary( $days = 30 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'avandwp_usage';
		$days  = max( 1, min( 365, (int) $days ) );
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS requests,
				        COALESCE(SUM(tokens_in), 0) AS tokens_in,
				        COALESCE(SUM(tokens_out), 0) AS tokens_out,
				        COALESCE(SUM(total_tokens), 0) AS total_tokens,
				        COALESCE(SUM(cost_usd), 0) AS cost_usd
				 FROM `{$table}`
				 WHERE created_at >= %s",
				$since
			),
			ARRAY_A
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$by_provider = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT provider, model, COUNT(*) AS requests, SUM(total_tokens) AS total_tokens, SUM(cost_usd) AS cost_usd
				 FROM `{$table}`
				 WHERE created_at >= %s
				 GROUP BY provider, model
				 ORDER BY total_tokens DESC",
				$since
			),
			ARRAY_A
		);

		return array(
			'requests'     => isset( $totals['requests'] ) ? (int) $totals['requests'] : 0,
			'tokens_in'    => isset( $totals['tokens_in'] ) ? (int) $totals['tokens_in'] : 0,
			'tokens_out'   => isset( $totals['tokens_out'] ) ? (int) $totals['tokens_out'] : 0,
			'total_tokens' => isset( $totals['total_tokens'] ) ? (int) $totals['total_tokens'] : 0,
			'cost_usd'     => isset( $totals['cost_usd'] ) ? round( (float) $totals['cost_usd'], 4 ) : 0.0,
			'by_provider'  => is_array( $by_provider ) ? $by_provider : array(),
		);
	}
}
