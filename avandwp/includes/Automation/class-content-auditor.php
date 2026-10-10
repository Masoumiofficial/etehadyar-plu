<?php
/**
 * Non-Destructive Content & SEO Auditor (`AvandWP_Content_Auditor`).
 *
 * Replaces the legacy `SEO_Watcher`, `Gardener`, and `Guardian` modules.
 * Crucially, it NEVER overwrites live post meta silently in a background cron;
 * it builds an accurate audit report with internal link suggestions so the
 * site administrator stays in 100% control.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvandWP_Content_Auditor {

	/**
	 * Audit published posts for SEO/content gaps and internal linking opportunities.
	 *
	 * @param int $limit Max posts to inspect.
	 * @return array Audit report.
	 */
	public function run_audit( $limit = 50 ) {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => max( 5, min( 150, (int) $limit ) ),
				'orderby'        => 'modified',
				'order'          => 'ASC',
			)
		);

		$kb    = new AvandWP_Knowledge_Base();
		$items = array();

		$missing_thumb_count = 0;
		$missing_seo_count   = 0;
		$thin_content_count  = 0;

		foreach ( $posts as $post ) {
			$word_count = AvandWP_Text_Helper::word_count( $post->post_content );
			$has_thumb  = has_post_thumbnail( $post->ID );
			$yoast_desc = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
			$rm_desc    = get_post_meta( $post->ID, 'rank_math_description', true );
			$has_seo    = ( '' !== trim( (string) $yoast_desc ) || '' !== trim( (string) $rm_desc ) );

			$issues = array();
			if ( ! $has_thumb ) {
				$issues[] = 'فاقد تصویر شاخص';
				++$missing_thumb_count;
			}
			if ( ! $has_seo ) {
				$issues[] = 'فاقد متای توضیحات سئو';
				++$missing_seo_count;
			}
			if ( $word_count < 300 ) {
				$issues[] = sprintf( 'محتوای کوتاه (%d کلمه)', $word_count );
				++$thin_content_count;
			}

			// Find 1 relevant internal link candidate not already linked
			$suggested_link = null;
			if ( ! empty( $issues ) || $word_count < 600 ) {
				$related = $kb->search( get_the_title( $post ), 3 );
				foreach ( $related as $rel ) {
					if ( 'post' === $rel['source_type'] && (int) $rel['source_id'] !== (int) $post->ID ) {
						if ( false === strpos( $post->post_content, $rel['url'] ) ) {
							$suggested_link = array(
								'title' => $rel['title'],
								'url'   => $rel['url'],
							);
							break;
						}
					}
				}
			}

			if ( ! empty( $issues ) || null !== $suggested_link ) {
				$items[] = array(
					'post_id'        => (int) $post->ID,
					'title'          => get_the_title( $post ),
					'word_count'     => $word_count,
					'has_thumb'      => $has_thumb,
					'has_seo'        => $has_seo,
					'issues'         => $issues,
					'suggested_link' => $suggested_link,
					'modified_date'  => get_the_modified_date( 'Y-m-d', $post ),
					'edit_url'       => get_edit_post_link( $post->ID, 'raw' ),
				);
			}
		}

		$report = array(
			'scanned_count'       => count( $posts ),
			'flagged_count'       => count( $items ),
			'missing_thumb_count' => $missing_thumb_count,
			'missing_seo_count'   => $missing_seo_count,
			'thin_content_count'  => $thin_content_count,
			'items'               => $items,
			'checked_at'          => current_time( 'mysql' ),
		);

		update_option( 'avandwp_auditor_last_report', $report, false );
		AvandWP_Logger::log(
			'auditor.completed',
			sprintf( 'ممیزی محتوا روی %d نوشته انجام شد (%d مورد نیازمند بهبود).', count( $posts ), count( $items ) ),
			'info'
		);

		return $report;
	}

	/**
	 * Generate and save SEO meta (Yoast & Rank Math) for a specific post on explicit admin request.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error
	 */
	public function generate_seo_meta_for_post( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return new WP_Error( 'avandwp_post_not_found', 'نوشته یافت نشد.' );
		}

		$title   = get_the_title( $post );
		$snippet = wp_trim_words( wp_strip_all_tags( $post->post_content ), 150 );

		$ai  = new AvandWP_AI_Client();
		$res = $ai->chat(
			array(
				array(
					'role'    => 'system',
					'content' => 'شما متخصص سئو هستید. فقط یک JSON معتبر با کلیدهای {"seo_title": "...", "meta_description": "..."} برگردانید.',
				),
				array(
					'role'    => 'user',
					'content' => "عنوان نوشته: {$title}\nخلاصه متن: {$snippet}\nیک عنوان سئو (حداکثر ۶۰ کاراکتر) و توضیحات متا (حداکثر ۱۵۵ کاراکتر) به فارسی بنویسید.",
				),
			),
			array(
				'max_tokens' => 300,
				'feature'    => 'auditor',
			)
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$parsed    = AvandWP_Text_Helper::parse_json_response( $res['content'] );
		$seo_title = sanitize_text_field( $parsed['seo_title'] ?? $title );
		$meta_desc = sanitize_textarea_field( $parsed['meta_description'] ?? wp_trim_words( $snippet, 25 ) );

		update_post_meta( $post->ID, '_yoast_wpseo_title', $seo_title );
		update_post_meta( $post->ID, '_yoast_wpseo_metadesc', $meta_desc );
		update_post_meta( $post->ID, 'rank_math_title', $seo_title );
		update_post_meta( $post->ID, 'rank_math_description', $meta_desc );

		AvandWP_Logger::log( 'auditor.seo_fixed', sprintf( 'متای سئوی نوشته #%d («%s») با هوش مصنوعی تکمیل شد.', $post->ID, $title ), 'success' );

		return array(
			'post_id'          => (int) $post->ID,
			'seo_title'        => $seo_title,
			'meta_description' => $meta_desc,
		);
	}
}
