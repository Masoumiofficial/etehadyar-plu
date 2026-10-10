<?php
/**
 * Background Job Queue Manager (`avandwp_jobs`).
 *
 * Executes heavy AI tasks (long articles, bulk WooCommerce optimization,
 * knowledge indexing) asynchronously with transient concurrency locks and retries.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvandWP_Job_Queue {

	const LOCK_KEY = 'avandwp_job_queue_lock';

	/**
	 * Enqueue a new background job.
	 *
	 * @param string $job_type Job type ('studio_article', 'woo_optimize', 'knowledge_sync').
	 * @param array  $payload  Input parameters for the job.
	 * @return int|false Job ID or false on DB error.
	 */
	public static function enqueue( $job_type, array $payload = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . 'avandwp_jobs';
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert(
			$table,
			array(
				'job_type'     => sanitize_key( $job_type ),
				'status'       => 'pending',
				'payload'      => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
				'progress'     => 0,
				'attempts'     => 0,
				'max_attempts' => 3,
				'created_by'   => get_current_user_id(),
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
		);

		if ( ! $ok ) {
			return false;
		}

		$job_id = (int) $wpdb->insert_id;
		AvandWP_Logger::log( 'job.enqueue', sprintf( 'کار پس‌زمینه #%d (%s) در صف قرار گرفت.', $job_id, $job_type ), 'info' );
		return $job_id;
	}

	/**
	 * Retrieve a single job by ID.
	 *
	 * @param int $job_id Job ID.
	 * @return array|null
	 */
	public static function get_job( $job_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'avandwp_jobs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", (int) $job_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['payload_data'] = json_decode( (string) $row['payload'], true );
		$row['result_data']  = ! empty( $row['result'] ) ? json_decode( (string) $row['result'], true ) : null;
		return $row;
	}

	/**
	 * Process up to $limit pending jobs safely with a concurrency lock.
	 *
	 * @param int $limit Max jobs to process in one batch.
	 * @param int $specific_job_id Optional specific job ID to process immediately.
	 * @return array Summary of processed jobs.
	 */
	public static function process_batch( $limit = 2, $specific_job_id = 0 ) {
		if ( get_transient( self::LOCK_KEY ) && 0 === (int) $specific_job_id ) {
			return array( 'processed' => 0, 'skipped_locked' => true );
		}

		set_transient( self::LOCK_KEY, 1, 90 );

		global $wpdb;
		$table = $wpdb->prefix . 'avandwp_jobs';
		$limit = max( 1, min( 5, (int) $limit ) );

		if ( $specific_job_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$jobs = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` WHERE id = %d AND status IN ('pending', 'failed') LIMIT 1",
					(int) $specific_job_id
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$jobs = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` WHERE status = 'pending' ORDER BY id ASC LIMIT %d",
					$limit
				),
				ARRAY_A
			);
		}

		$processed = 0;
		if ( is_array( $jobs ) ) {
			foreach ( $jobs as $job ) {
				self::execute_job( $job );
				++$processed;
			}
		}

		delete_transient( self::LOCK_KEY );
		return array( 'processed' => $processed );
	}

	/**
	 * Execute a single job row and update its status/result.
	 *
	 * @param array $job Database row from `avandwp_jobs`.
	 * @return void
	 */
	private static function execute_job( array $job ) {
		global $wpdb;
		$table    = $wpdb->prefix . 'avandwp_jobs';
		$job_id   = (int) $job['id'];
		$attempts = (int) $job['attempts'] + 1;

		// Mark running
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'status'     => 'running',
				'progress'   => 20,
				'attempts'   => $attempts,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%d', '%d', '%s' ),
			array( '%d' )
		);

		$payload = json_decode( (string) $job['payload'], true );
		if ( ! is_array( $payload ) ) {
			$payload = array();
		}

		try {
			$result = null;
			switch ( $job['job_type'] ) {
				case 'studio_article':
					$studio = new AvandWP_Content_Studio();
					$result = $studio->generate_article_package( $payload, $job_id );
					break;

				case 'woo_optimize':
					$woo        = new AvandWP_Woo_Assistant();
					$product_id = isset( $payload['product_id'] ) ? (int) $payload['product_id'] : 0;
					$options    = isset( $payload['options'] ) && is_array( $payload['options'] ) ? $payload['options'] : array();
					$result     = $woo->optimize_product( $product_id, $options, $job_id );
					break;

				case 'knowledge_sync':
					$kb     = new AvandWP_Knowledge_Base();
					$result = $kb->sync_all_content();
					break;

				default:
					$result = new WP_Error( 'unknown_job_type', 'نوع کار پس‌زمینه پشتیبانی نمی‌شود: ' . $job['job_type'] );
					break;
			}

			if ( is_wp_error( $result ) ) {
				self::mark_failed( $job, $result->get_error_message(), $attempts );
				return;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'status'        => 'completed',
					'progress'      => 100,
					'result'        => wp_json_encode( $result, JSON_UNESCAPED_UNICODE ),
					'error_message' => null,
					'updated_at'    => current_time( 'mysql' ),
				),
				array( 'id' => $job_id ),
				array( '%s', '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);

			AvandWP_Logger::log( 'job.completed', sprintf( 'کار پس‌زمینه #%d (%s) با موفقیت تکمیل شد.', $job_id, $job['job_type'] ), 'success' );
		} catch ( Exception $e ) {
			self::mark_failed( $job, $e->getMessage(), $attempts );
		}
	}

	/**
	 * Handle job failure with retry backoff up to max_attempts.
	 *
	 * @param array  $job      Job row.
	 * @param string $error    Error message.
	 * @param int    $attempts Current attempt count.
	 * @return void
	 */
	private static function mark_failed( array $job, $error, $attempts ) {
		global $wpdb;
		$table        = $wpdb->prefix . 'avandwp_jobs';
		$job_id       = (int) $job['id'];
		$max_attempts = max( 1, (int) $job['max_attempts'] );
		$next_status  = ( $attempts < $max_attempts ) ? 'pending' : 'failed';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'status'        => $next_status,
				'error_message' => sanitize_text_field( (string) $error ),
				'updated_at'    => current_time( 'mysql' ),
			),
			array( 'id' => $job_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		AvandWP_Logger::log(
			'job.failed',
			sprintf( 'خطا در کار #%d (تلاش %d از %d): %s', $job_id, $attempts, $max_attempts, $error ),
			'error'
		);
	}

	/**
	 * Fetch recent jobs for admin UI display.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function get_recent_jobs( $limit = 25 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'avandwp_jobs';
		$limit = max( 1, min( 100, (int) $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Cleanup completed/failed jobs older than $days.
	 *
	 * @param int $days Retention days.
	 * @return int Deleted rows count.
	 */
	public static function cleanup_old_jobs( $days = 14 ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'avandwp_jobs';
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, (int) $days ) * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE status IN ('completed', 'failed') AND updated_at < %s",
				$cutoff
			)
		);
		return (int) $deleted;
	}
}
