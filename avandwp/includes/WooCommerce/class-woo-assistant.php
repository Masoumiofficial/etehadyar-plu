<?php
/**
 * WooCommerce AI Product Assistant (`AvandWP_Woo_Assistant`).
 *
 * Fixes the critical Fatal PHP Error (`sanitize_textfield` -> `sanitize_text_field`)
 * and the broken Persian word counter (`str_word_count` -> `AvandWP_Text_Helper::word_count`).
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvandWP_Woo_Assistant {

	/**
	 * Scan WooCommerce catalog for products with weak/short descriptions or missing SEO meta.
	 *
	 * @param int $min_words Minimum acceptable Persian word count (default 80).
	 * @param int $limit     Max products to inspect (paginated to protect memory).
	 * @return array List of weak products with accurate diagnostics.
	 */
	public function scan_weak_products( $min_words = 80, $limit = 60 ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array();
		}

		$min_words = max( 20, (int) $min_words );
		$limit     = max( 1, min( 200, (int) $limit ) );

		$posts = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => $limit,
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		);

		$weak_products = array();
		foreach ( $posts as $post ) {
			$word_count = AvandWP_Text_Helper::word_count( $post->post_content );
			$short_wc   = AvandWP_Text_Helper::word_count( $post->post_excerpt );
			$yoast_desc = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
			$rm_desc    = get_post_meta( $post->ID, 'rank_math_description', true );
			$has_seo    = ( '' !== trim( (string) $yoast_desc ) || '' !== trim( (string) $rm_desc ) );

			$issues = array();
			if ( $word_count < $min_words ) {
				$issues[] = sprintf( 'توضیحات کوتاه (%d کلمه از حداقل %d)', $word_count, $min_words );
			}
			if ( $short_wc < 10 ) {
				$issues[] = 'فاقد توضیح کوتاه محصول';
			}
			if ( ! $has_seo ) {
				$issues[] = 'فاقد متای سئو (Yoast/RankMath)';
			}

			if ( ! empty( $issues ) ) {
				$weak_products[] = array(
					'id'         => (int) $post->ID,
					'title'      => get_the_title( $post ),
					'status'     => $post->post_status,
					'word_count' => $word_count,
					'has_seo'    => $has_seo,
					'issues'     => $issues,
					'edit_url'   => get_edit_post_link( $post->ID, 'raw' ),
					'view_url'   => get_permalink( $post->ID ),
				);
			}
		}

		return $weak_products;
	}

	/**
	 * Optimize an existing WooCommerce product with AI (long description, short description,
	 * tags, structured FAQ schema, and Yoast/RankMath SEO meta).
	 *
	 * @param int   $product_id WooCommerce product post ID.
	 * @param array $options    Options: tone, specs_hint, job_id.
	 * @param int   $job_id     Optional job ID.
	 * @return array|WP_Error
	 */
	public function optimize_product( $product_id, array $options = array(), $job_id = 0 ) {
		$product_id = (int) $product_id;
		$post       = get_post( $product_id );
		if ( ! $post || 'product' !== $post->post_type ) {
			return new WP_Error( 'avandwp_invalid_product', 'محصول ووکامرس مورد نظر یافت نشد.' );
		}

		$tone       = sanitize_text_field( $options['tone'] ?? 'مشاور خرید حرفه‌ای، دقیق و صادقانه' );
		$specs_hint = sanitize_textarea_field( $options['specs_hint'] ?? '' );

		$title        = get_the_title( $post );
		$current_desc = wp_strip_all_tags( $post->post_content );
		$price_info   = '';
		$cats_names   = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );
		$cat_str      = is_array( $cats_names ) && ! empty( $cats_names ) ? implode( '، ', $cats_names ) : 'عمومی';

		if ( function_exists( 'wc_get_product' ) ) {
			$wc_prod = wc_get_product( $product_id );
			if ( $wc_prod && $wc_prod->get_price() ) {
				$price_info = $wc_prod->get_price();
			}
		}

		$ai = new AvandWP_AI_Client();

		$system_prompt = 'شما متخصص بهینه‌سازی صفحات محصول ووکامرس و سئوی فروشگاهی هستید. خروجی شما باید صرفاً یک JSON معتبر با ساختار زیر باشد:
{
  "short_description": "معرفی کوتاه و کلیدی محصول در ۲ تا ۳ جمله همراه با ۳ ویژگی شاخص",
  "full_description_html": "توضیحات کامل محصول با تگ‌های استاندارد HTML شامل <h2>, <h3>, <p>, <ul>, <li> (مزایا، کاربردها و راهنمای خرید، بدون تگ <h1>)",
  "seo_title": "عنوان سئو محصول (حداکثر ۶۰ کاراکتر)",
  "meta_description": "توضیحات متا سئو برای نتایج گوگل (حداکثر ۱۵۵ کاراکتر)",
  "tags": ["برچسب ۱", "برچسب ۲", "برچسب ۳"],
  "faq": [
    {"question": "سؤال رایج خریدار ۱؟", "answer": "پاسخ روشن و دقیق ۱"}
  ]
}';

		$user_prompt = sprintf(
			"نام محصول: %s\nدسته‌بندی: %s\nقیمت فعلی: %s\nتوضیحات فعلی: %s\nویژگی‌های تکمیلی: %s\nلحن: %s",
			$title,
			$cat_str,
			$price_info ? $price_info : 'نامشخص',
			'' !== trim( $current_desc ) ? mb_substr( $current_desc, 0, 800, 'UTF-8' ) : 'ندارد',
			$specs_hint,
			$tone
		);

		$res = $ai->chat(
			array(
				array( 'role' => 'system', 'content' => $system_prompt ),
				array( 'role' => 'user', 'content' => $user_prompt ),
			),
			array(
				'max_tokens' => 2600,
				'feature'    => 'woo',
				'job_id'     => $job_id,
			)
		);

		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$parsed = AvandWP_Text_Helper::parse_json_response( $res['content'] );
		if ( ! is_array( $parsed ) || empty( $parsed['full_description_html'] ) ) {
			return new WP_Error( 'avandwp_woo_parse_error', 'پاسخ دریافتی از مدل هوش مصنوعی ساختار معتبری نداشت. دوباره تلاش کنید.' );
		}

		$full_html  = AvandWP_Text_Helper::clean_ai_html( $parsed['full_description_html'] );
		$short_html = AvandWP_Text_Helper::clean_ai_html( $parsed['short_description'] ?? '' );
		$seo_title  = sanitize_text_field( $parsed['seo_title'] ?? $title );
		$meta_desc  = sanitize_textarea_field( $parsed['meta_description'] ?? '' );

		$faq_items = array();
		if ( ! empty( $parsed['faq'] ) && is_array( $parsed['faq'] ) ) {
			$full_html .= "\n\n<h2>سؤالات متداول درباره این محصول</h2>";
			foreach ( $parsed['faq'] as $f ) {
				$q = sanitize_text_field( $f['question'] ?? '' );
				$a = sanitize_textarea_field( $f['answer'] ?? '' );
				if ( '' !== $q && '' !== $a ) {
					$faq_items[] = array( 'question' => $q, 'answer' => $a );
					$full_html  .= sprintf( '<h3>%s</h3><p>%s</p>', esc_html( $q ), esc_html( $a ) );
				}
			}
		}

		wp_update_post(
			array(
				'ID'           => $product_id,
				'post_content' => $full_html,
				'post_excerpt' => $short_html,
			)
		);

		if ( ! empty( $parsed['tags'] ) && is_array( $parsed['tags'] ) ) {
			$clean_tags = array_map( 'sanitize_text_field', $parsed['tags'] );
			wp_set_object_terms( $product_id, $clean_tags, 'product_tag', true );
		}

		update_post_meta( $product_id, '_yoast_wpseo_title', $seo_title );
		update_post_meta( $product_id, '_yoast_wpseo_metadesc', $meta_desc );
		update_post_meta( $product_id, '_yoast_wpseo_focuskw', $title );
		update_post_meta( $product_id, 'rank_math_title', $seo_title );
		update_post_meta( $product_id, 'rank_math_description', $meta_desc );
		update_post_meta( $product_id, 'rank_math_focus_keyword', $title );
		update_post_meta( $product_id, '_avandwp_faq_items', $faq_items );
		update_post_meta( $product_id, '_avandwp_woo_optimized', current_time( 'mysql' ) );

		$new_word_count = AvandWP_Text_Helper::word_count( $full_html );

		AvandWP_Logger::log(
			'woo.product_optimized',
			sprintf( 'محصول «%s» (#%d) با موفقیت بهینه‌سازی شد (%d کلمه).', $title, $product_id, $new_word_count ),
			'success',
			array( 'product_id' => $product_id, 'word_count' => $new_word_count )
		);

		return array(
			'product_id'       => $product_id,
			'title'            => $title,
			'word_count'       => $new_word_count,
			'seo_title'        => $seo_title,
			'meta_description' => $meta_desc,
			'faq_count'        => count( $faq_items ),
			'edit_url'         => get_edit_post_link( $product_id, 'raw' ),
			'view_url'         => get_permalink( $product_id ),
		);
	}

	/**
	 * Create a new draft WooCommerce product from a product name and brief.
	 *
	 * @param string $name  Product title.
	 * @param string $price Optional regular price.
	 * @param string $brief Features/specifications brief.
	 * @return array|WP_Error
	 */
	public function create_product_draft( $name, $price = '', $brief = '' ) {
		$name  = sanitize_text_field( (string) $name );
		$price = sanitize_text_field( (string) $price );
		$brief = sanitize_textarea_field( (string) $brief );

		if ( '' === $name ) {
			return new WP_Error( 'avandwp_empty_product_name', 'نام محصول نمی‌تواند خالی باشد.' );
		}

		$product_id = wp_insert_post(
			array(
				'post_title'  => $name,
				'post_status' => 'draft',
				'post_type'   => 'product',
				'post_author' => get_current_user_id() ? get_current_user_id() : 1,
			),
			true
		);

		if ( is_wp_error( $product_id ) ) {
			return $product_id;
		}

		if ( '' !== $price ) {
			update_post_meta( $product_id, '_regular_price', $price );
			update_post_meta( $product_id, '_price', $price );
		}

		return $this->optimize_product(
			$product_id,
			array(
				'specs_hint' => $brief,
			)
		);
	}
}
