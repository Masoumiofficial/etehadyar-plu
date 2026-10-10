<?php
/**
 * Content & Media Studio (`AvandWP_Content_Studio`).
 *
 * Generates SEO-structured Persian articles in clean HTML, attaches AI featured
 * images, populates Yoast SEO and Rank Math meta fields, generates FAQ schema,
 * and builds social media summaries (Telegram/Instagram).
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvandWP_Content_Studio {

	/**
	 * Generate a complete article package and save as a WordPress post (draft or publish).
	 *
	 * @param array $params Input parameters: topic, keyword, tone, target_words, post_status, category_id, generate_image, generate_audio, generate_social, use_knowledge.
	 * @param int   $job_id Optional background job ID for usage tracking.
	 * @return array|WP_Error
	 */
	public function generate_article_package( array $params, $job_id = 0 ) {
		$topic        = sanitize_text_field( $params['topic'] ?? '' );
		$keyword      = sanitize_text_field( $params['keyword'] ?? $topic );
		$tone         = sanitize_text_field( $params['tone'] ?? 'حرفه‌ای، کاربردی و روان' );
		$target_words = max( 300, min( 3000, (int) ( $params['target_words'] ?? 1000 ) ) );
		$post_status  = in_array( $params['post_status'] ?? 'draft', array( 'draft', 'publish', 'pending' ), true ) ? $params['post_status'] : 'draft';
		$category_id  = isset( $params['category_id'] ) ? (int) $params['category_id'] : 0;

		if ( '' === $topic ) {
			return new WP_Error( 'avandwp_empty_topic', 'موضوع مقاله نمی‌تواند خالی باشد.' );
		}

		$ai = new AvandWP_AI_Client();

		// 1. Optional site context from Knowledge Base for internal linking & consistency
		$context_block = '';
		if ( ! empty( $params['use_knowledge'] ) ) {
			$kb      = new AvandWP_Knowledge_Base();
			$matches = $kb->search( $topic . ' ' . $keyword, 3 );
			if ( ! empty( $matches ) ) {
				$links_list = array();
				foreach ( $matches as $m ) {
					$links_list[] = sprintf( '- عنوان: «%s» (لینک: %s)', $m['title'], $m['url'] );
				}
				$context_block = "\n\nمطالب مرتبط موجود در سایت (در صورت تناسب موضوعی، به ۱ یا ۲ مورد از آن‌ها با تگ <a> لینک داخلی طبیعی بدهید):\n" . implode( "\n", $links_list );
			}
		}

		// 2. Prompt AI for structured JSON containing title, seo_title, meta_description, html_content, faq_items, and social_caption
		$system_prompt = 'شما سردبیر ارشد تولید محتوای فارسی و متخصص سئوی وردپرس هستید. خروجی شما باید صرفاً یک شیء JSON معتبر با کلیدهای زیر باشد (بدون هیچ متن اضافی خارج از JSON):
{
  "title": "عنوان جذاب و استاندارد مقاله",
  "seo_title": "عنوان سئو (حداکثر ۶۰ کاراکتر، شامل کلمه کلیدی)",
  "meta_description": "توضیحات متا سئو (حداکثر ۱۵۵ کاراکتر، ترغیب‌کننده و شامل کلمه کلیدی)",
  "html_content": "محتوای کامل مقاله با تگ‌های استاندارد HTML شامل <h2>, <h3>, <p>, <ul>, <li>, <table> (بدون تگ <h1> و بدون مارک‌داون)",
  "faq": [
    {"question": "پرسش متداول ۱؟", "answer": "پاسخ دقیق ۱"}
  ],
  "image_prompt": "پرامپت انگلیسی دقیق و حرفه‌ای برای تولید تصویر شاخص مرتبط با مقاله (بدون متن روی تصویر)",
  "telegram_caption": "خلاصه جذاب مقاله برای انتشار در کانال تلگرام همراه با بولت‌پوینت و هشتگ"
}';

		$user_prompt = sprintf(
			"موضوع مقاله: %s\nکلمه کلیدی کانونی: %s\nلحن نگارش: %s\nطول هدف: حدود %d کلمه فارسی.\nالزامات: محتوا باید کاملاً کاربردی، بدون کلی‌گویی، دارای ساختار هدینگ H2 و H3 منطقی، پاراگراف‌های خوانا و ۳ پرسش متداول در بخش faq باشد.%s",
			$topic,
			$keyword,
			$tone,
			$target_words,
			$context_block
		);

		$res = $ai->chat(
			array(
				array( 'role' => 'system', 'content' => $system_prompt ),
				array( 'role' => 'user', 'content' => $user_prompt ),
			),
			array(
				'max_tokens' => 3500,
				'feature'    => 'studio',
				'job_id'     => $job_id,
			)
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$parsed = AvandWP_Text_Helper::parse_json_response( $res['content'] );
		if ( ! is_array( $parsed ) || empty( $parsed['html_content'] ) ) {
			// Fallback if model returned direct HTML instead of JSON
			$parsed = array(
				'title'            => $topic,
				'seo_title'        => $topic,
				'meta_description' => wp_trim_words( wp_strip_all_tags( $res['content'] ), 25 ),
				'html_content'     => $res['content'],
				'faq'              => array(),
				'image_prompt'     => 'Editorial illustration representing ' . $topic . ', modern clean vector style',
				'telegram_caption' => "📌 " . $topic,
			);
		}

		$title     = sanitize_text_field( $parsed['title'] ?? $topic );
		$seo_title = sanitize_text_field( $parsed['seo_title'] ?? $title );
		$meta_desc = sanitize_textarea_field( $parsed['meta_description'] ?? '' );
		$html_body = AvandWP_Text_Helper::clean_ai_html( $parsed['html_content'] );

		// Append structured FAQ section if returned
		$faq_items = array();
		if ( ! empty( $parsed['faq'] ) && is_array( $parsed['faq'] ) ) {
			$faq_html = '<section class="avandwp-faq-section"><h2>سؤالات متداول</h2>';
			foreach ( $parsed['faq'] as $f ) {
				$q = sanitize_text_field( $f['question'] ?? '' );
				$a = sanitize_textarea_field( $f['answer'] ?? '' );
				if ( '' !== $q && '' !== $a ) {
					$faq_items[] = array( 'question' => $q, 'answer' => $a );
					$faq_html   .= sprintf(
						'<div class="avandwp-faq-item"><h3>%s</h3><p>%s</p></div>',
						esc_html( $q ),
						esc_html( $a )
					);
				}
			}
			$faq_html  .= '</section>';
			$html_body .= "\n\n" . $faq_html;
		}

		$post_args = array(
			'post_title'   => $title,
			'post_content' => $html_body,
			'post_excerpt' => $meta_desc,
			'post_status'  => $post_status,
			'post_type'    => 'post',
			'post_author'  => get_current_user_id() ? get_current_user_id() : 1,
		);
		if ( $category_id > 0 ) {
			$post_args['post_category'] = array( $category_id );
		}

		$post_id = wp_insert_post( $post_args, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Populate Yoast SEO & Rank Math meta fields
		update_post_meta( $post_id, '_yoast_wpseo_title', $seo_title );
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_desc );
		update_post_meta( $post_id, '_yoast_wpseo_focuskw', $keyword );
		update_post_meta( $post_id, 'rank_math_title', $seo_title );
		update_post_meta( $post_id, 'rank_math_description', $meta_desc );
		update_post_meta( $post_id, 'rank_math_focus_keyword', $keyword );

		if ( ! empty( $faq_items ) ) {
			update_post_meta( $post_id, '_avandwp_faq_items', $faq_items );
		}

		// Optional Featured Image generation
		$image_data = null;
		if ( ! empty( $params['generate_image'] ) ) {
			$img_client = new AvandWP_Image_Client();
			$img_prompt = ! empty( $parsed['image_prompt'] ) ? $parsed['image_prompt'] : ( 'Modern editorial illustration for: ' . $title );
			$img_res    = $img_client->generate_and_attach(
				$img_prompt,
				array(
					'alt_text' => $title,
					'post_id'  => $post_id,
					'size'     => '1792x1024',
				)
			);
			if ( ! is_wp_error( $img_res ) && ! empty( $img_res['attachment_id'] ) ) {
				set_post_thumbnail( $post_id, (int) $img_res['attachment_id'] );
				$image_data = $img_res;
			}
		}

		// Optional TTS Audio generation
		$audio_data = null;
		if ( ! empty( $params['generate_audio'] ) ) {
			$summary_for_tts = $title . ". " . $meta_desc . " " . wp_trim_words( wp_strip_all_tags( $html_body ), 180 );
			$tts_res         = $ai->generate_speech( $summary_for_tts, 'nova' );
			if ( ! is_wp_error( $tts_res ) && ! empty( $tts_res['url'] ) ) {
				$audio_data = $tts_res;
				update_post_meta( $post_id, '_avandwp_audio_url', esc_url_raw( $tts_res['url'] ) );
			}
		}

		$word_count       = AvandWP_Text_Helper::word_count( $html_body );
		$telegram_caption = sanitize_textarea_field( $parsed['telegram_caption'] ?? ( "📌 " . $title . "\n\n" . $meta_desc ) );
		update_post_meta( $post_id, '_avandwp_telegram_caption', $telegram_caption );
		update_post_meta( $post_id, '_avandwp_generated', 1 );

		AvandWP_Logger::log(
			'studio.article_created',
			sprintf( 'مقاله «%s» (#%d) با %d کلمه فارسی ایجاد شد.', $title, $post_id, $word_count ),
			'success',
			array( 'post_id' => $post_id, 'word_count' => $word_count )
		);

		return array(
			'post_id'          => (int) $post_id,
			'title'            => $title,
			'seo_title'        => $seo_title,
			'meta_description' => $meta_desc,
			'word_count'       => $word_count,
			'edit_url'         => get_edit_post_link( $post_id, 'raw' ),
			'view_url'         => get_permalink( $post_id ),
			'html_preview'     => $html_body,
			'faq_count'        => count( $faq_items ),
			'image'            => $image_data,
			'audio'            => $audio_data,
			'telegram_caption' => $telegram_caption,
			'provider'         => $res['provider'],
			'model'            => $res['model'],
		);
	}
}
