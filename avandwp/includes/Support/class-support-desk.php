<?php
/**
 * Support Desk & Feedback Manager (`AvandWP_Support_Desk`).
 *
 * Manages customer support requests (`avandwp_support_requests`), audit history
 * (`avandwp_support_history`), and chat feedback (`avandwp_chat_feedback`).
 * Fixes the database schema mismatch that broke ticket creation in the legacy plugin.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvandWP_Support_Desk {

	/**
	 * Create a new support ticket from the frontend chat assistant or REST API.
	 *
	 * @param array $data Keys: session_id, user_name, user_contact, subject, message, priority, audio_url, transcript, page_url.
	 * @return int|WP_Error Inserted ticket ID or WP_Error.
	 */
	public static function create_ticket( array $data ) {
		global $wpdb;
		$table = $wpdb->prefix . 'avandwp_support_requests';

		$user_name    = sanitize_text_field( $data['user_name'] ?? '' );
		$user_contact = sanitize_text_field( $data['user_contact'] ?? '' );
		$subject      = sanitize_text_field( $data['subject'] ?? 'درخواست پشتیبانی از چت‌بات' );
		$message      = sanitize_textarea_field( $data['message'] ?? '' );
		$session_id   = sanitize_text_field( $data['session_id'] ?? '' );
		$priority     = sanitize_key( $data['priority'] ?? 'normal' );
		$audio_url    = ! empty( $data['audio_url'] ) ? esc_url_raw( $data['audio_url'] ) : null;
		$transcript   = ! empty( $data['transcript'] ) ? sanitize_textarea_field( $data['transcript'] ) : null;
		$page_url     = ! empty( $data['page_url'] ) ? esc_url_raw( $data['page_url'] ) : null;

		if ( ! in_array( $priority, array( 'low', 'normal', 'high', 'urgent' ), true ) ) {
			$priority = 'normal';
		}

		if ( '' === $user_contact || ( '' === $message && empty( $audio_url ) ) ) {
			return new WP_Error( 'avandwp_invalid_ticket', 'لطفاً شماره تماس/ایمیل و متن پیام خود را وارد کنید.' );
		}

		if ( '' === $user_name ) {
			$user_name = 'کاربر مهمان';
		}

		$now = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert(
			$table,
			array(
				'session_id'   => $session_id,
				'user_name'    => $user_name,
				'user_contact' => $user_contact,
				'subject'      => $subject,
				'message'      => $message,
				'priority'     => $priority,
				'status'       => 'new',
				'audio_url'    => $audio_url,
				'transcript'   => $transcript,
				'page_url'     => $page_url,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( ! $ok ) {
			return new WP_Error( 'avandwp_ticket_db_error', 'خطا در ثبت تیکت در پایگاه داده.' );
		}

		$ticket_id = (int) $wpdb->insert_id;
		self::add_history( $ticket_id, 'created', 'تیکت پشتیبانی توسط کاربر ثبت شد.' );

		AvandWP_Logger::log(
			'support.ticket_created',
			sprintf( 'تیکت جدید #%d از طرف «%s» (%s) ثبت شد.', $ticket_id, $user_name, $user_contact ),
			'info'
		);

		// Notify via Telegram if enabled
		$tg_settings = get_option( 'avandwp_telegram_settings', array() );
		if ( ! empty( $tg_settings['notify_support'] ) ) {
			$tg = new AvandWP_Telegram();
			$tg->notify_support_ticket(
				$ticket_id,
				array(
					'user_name'    => $user_name,
					'user_contact' => $user_contact,
					'subject'      => $subject,
					'message'      => $message,
					'priority'     => $priority,
				)
			);
		}

		return $ticket_id;
	}

	/**
	 * Update an existing ticket's transcript after Whisper audio processing.
	 *
	 * @param int    $ticket_id  Ticket ID.
	 * @param string $transcript Transcribed text.
	 * @return bool
	 */
	public static function update_transcript( $ticket_id, $transcript ) {
		global $wpdb;
		$table = $wpdb->prefix . 'avandwp_support_requests';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$table,
			array(
				'transcript' => sanitize_textarea_field( (string) $transcript ),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $ticket_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return false !== $updated;
	}

	/**
	 * Reply to or update status of a support ticket from the admin panel.
	 *
	 * @param int    $ticket_id   Ticket ID.
	 * @param string $status      'new' | 'in_progress' | 'answered' | 'closed'.
	 * @param string $admin_reply Optional reply text.
	 * @param string $priority    Optional priority.
	 * @return bool|WP_Error
	 */
	public static function update_ticket_admin( $ticket_id, $status, $admin_reply = '', $priority = '' ) {
		global $wpdb;
		$table     = $wpdb->prefix . 'avandwp_support_requests';
		$ticket_id = (int) $ticket_id;

		$allowed_statuses = array( 'new', 'in_progress', 'answered', 'closed' );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'in_progress';
		}

		$fields  = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql' ),
		);
		$formats = array( '%s', '%s' );

		if ( '' !== trim( $admin_reply ) ) {
			$fields['admin_reply'] = sanitize_textarea_field( $admin_reply );
			$formats[]             = '%s';
		}
		if ( in_array( $priority, array( 'low', 'normal', 'high', 'urgent' ), true ) ) {
			$fields['priority'] = $priority;
			$formats[]          = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $wpdb->update( $table, $fields, array( 'id' => $ticket_id ), $formats, array( '%d' ) );
		if ( false === $ok ) {
			return new WP_Error( 'avandwp_ticket_update_failed', 'خطا در به‌روزرسانی تیکت.' );
		}

		self::add_history( $ticket_id, 'status_' . $status, '' !== trim( $admin_reply ) ? 'پاسخ کارشناس ثبت شد: ' . $admin_reply : 'وضعیت تیکت تغییر کرد به: ' . $status );

		// If contact is an email address and reply is provided, send email notification.
		if ( '' !== trim( $admin_reply ) ) {
			$ticket = self::get_ticket( $ticket_id );
			if ( $ticket && is_email( $ticket['user_contact'] ) ) {
				wp_mail(
					$ticket['user_contact'],
					sprintf( 'پاسخ به درخواست پشتیبانی #%d — %s', $ticket_id, get_bloginfo( 'name' ) ),
					sprintf( "سلام %s عزیز،\n\nپاسخ کارشناس به درخواست شما:\n%s\n\nبا احترام،\n%s", $ticket['user_name'], $admin_reply, get_bloginfo( 'name' ) )
				);
			}
		}

		return true;
	}

	/**
	 * Fetch a single ticket with its history.
	 *
	 * @param int $ticket_id Ticket ID.
	 * @return array|null
	 */
	public static function get_ticket( $ticket_id ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'avandwp_support_requests';
		$h_table = $wpdb->prefix . 'avandwp_support_history';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", (int) $ticket_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$history        = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$h_table}` WHERE request_id = %d ORDER BY id ASC", (int) $ticket_id ), ARRAY_A );
		$row['history'] = is_array( $history ) ? $history : array();
		return $row;
	}

	/**
	 * List tickets with optional status filter.
	 *
	 * @param string $status Optional status filter.
	 * @param int    $limit  Max rows.
	 * @return array
	 */
	public static function get_tickets( $status = '', $limit = 50 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'avandwp_support_requests';
		$limit = max( 1, min( 200, (int) $limit ) );

		if ( '' !== $status && in_array( $status, array( 'new', 'in_progress', 'answered', 'closed' ), true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM `{$table}` WHERE status = %s ORDER BY id DESC LIMIT %d", $status, $limit ),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d", $limit ),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Record an audit log entry in `avandwp_support_history`.
	 */
	public static function add_history( $request_id, $action_type, $note ) {
		global $wpdb;
		$table = $wpdb->prefix . 'avandwp_support_history';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$table,
			array(
				'request_id'  => (int) $request_id,
				'actor_id'    => get_current_user_id(),
				'action_type' => sanitize_key( $action_type ),
				'note'        => sanitize_textarea_field( (string) $note ),
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Record user feedback ('up' or 'down') on a chat response.
	 *
	 * @param array $data Keys: log_id, session_id, rating, question, answer, comment.
	 * @return int|false
	 */
	public static function record_feedback( array $data ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'avandwp_chat_feedback';
		$rating = ( isset( $data['rating'] ) && 'down' === $data['rating'] ) ? 'down' : 'up';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ok = $wpdb->insert(
			$table,
			array(
				'log_id'     => isset( $data['log_id'] ) ? (int) $data['log_id'] : 0,
				'session_id' => sanitize_text_field( $data['session_id'] ?? '' ),
				'rating'     => $rating,
				'question'   => sanitize_textarea_field( $data['question'] ?? '' ),
				'answer'     => sanitize_textarea_field( $data['answer'] ?? '' ),
				'comment'    => ! empty( $data['comment'] ) ? sanitize_textarea_field( $data['comment'] ) : null,
				'created_at' => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Fetch recent chat feedback items.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function get_feedback_list( $limit = 40 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'avandwp_chat_feedback';
		$limit = max( 1, min( 150, (int) $limit ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}
}
