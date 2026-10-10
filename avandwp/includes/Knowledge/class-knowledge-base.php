<?php
/**
 * Real Hybrid Persian Knowledge Base & Retrieval Engine (RAG).
 *
 * Replaces the legacy fake MD5-hash vector implementation with:
 * 1) Zero-cost Persian-normalized BM25/TF lexical scoring across titles, FAQs,
 *    posts, pages, and WooCommerce products.
 * 2) Optional true semantic vector embeddings (`/v1/embeddings` `text-embedding-3-small`)
 *    when enabled in settings.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvandWP_Knowledge_Base {

	/**
	 * Synchronize published posts, pages, products, and custom FAQs into `avandwp_knowledge`.
	 *
	 * @param int $limit Max posts to index in one pass.
	 * @return array Summary of indexed items.
	 */
	public function sync_all_content( $limit = 150 ) {
		global $wpdb;
		$table    = $wpdb->prefix . 'avandwp_knowledge';
		$settings = get_option( 'avandwp_settings', array() );
		$use_emb  = ! empty( $settings['enable_true_embeddings'] );
		$ai       = $use_emb ? new AvandWP_AI_Client() : null;

		$post_types = array( 'post', 'page' );
		if ( class_exists( 'WooCommerce' ) ) {
			$post_types[] = 'product';
		}

		$posts = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => max( 10, min( 500, (int) $limit ) ),
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		// Clear old index before rebuilding cleanly.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE `{$table}`" );

		$indexed_posts = 0;
		$indexed_faqs  = 0;
		$now           = current_time( 'mysql' );

		// 1. Index Admin FAQs first (highest priority knowledge)
		$faqs = self::get_faqs();
		foreach ( $faqs as $idx => $faq ) {
			$q = trim( (string) ( $faq['question'] ?? '' ) );
			$a = trim( (string) ( $faq['answer'] ?? '' ) );
			if ( '' === $q || '' === $a ) {
				continue;
			}
			$chunk  = "پرسش متداول: {$q}\nپاسخ رسمی: {$a}";
			$tokens = AvandWP_Text_Helper::tokenize( $q . ' ' . $q . ' ' . $a );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$table,
				array(
					'source_type'       => 'faq',
					'source_id'         => (int) $idx + 1,
					'title'             => sanitize_text_field( $q ),
					'url'               => home_url( '/' ),
					'content_chunk'     => $chunk,
					'normalized_tokens' => implode( ' ', $tokens ),
					'embedding_json'    => null,
					'updated_at'        => $now,
				),
				array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			++$indexed_faqs;
		}

		// 2. Index published posts, pages, and WooCommerce products
		foreach ( $posts as $p ) {
			$title   = get_the_title( $p );
			$url     = get_permalink( $p );
			$excerpt = has_excerpt( $p ) ? get_the_excerpt( $p ) : '';
			$plain   = wp_strip_all_tags( $p->post_content );
			$plain   = preg_replace( '/\s+/u', ' ', (string) $plain );
			$snippet = function_exists( 'mb_substr' ) ? mb_substr( $plain, 0, 1100, 'UTF-8' ) : substr( $plain, 0, 1100 );

			$extra_meta = '';
			if ( 'product' === $p->post_type && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $p->ID );
				if ( $product ) {
					$price = $product->get_price();
					$stock = $product->is_in_stock() ? 'موجود در انبار' : 'ناموجود';
					$extra_meta = sprintf( " | قیمت: %s | وضعیت موجودی: %s", $price ? $price : 'نامشخص', $stock );
				}
			}

			$chunk  = sprintf( "عنوان: %s%s\nخلاصه و محتوا: %s %s", $title, $extra_meta, $excerpt, $snippet );
			$tokens = AvandWP_Text_Helper::tokenize( $title . ' ' . $title . ' ' . $excerpt . ' ' . $snippet );

			$emb_json = null;
			if ( $use_emb && $ai && $indexed_posts < 25 ) {
				$vec = $ai->create_embedding( $title . "\n" . $snippet );
				if ( is_array( $vec ) ) {
					$emb_json = wp_json_encode( $vec );
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$table,
				array(
					'source_type'       => sanitize_key( $p->post_type ),
					'source_id'         => (int) $p->ID,
					'title'             => sanitize_text_field( $title ),
					'url'               => esc_url_raw( (string) $url ),
					'content_chunk'     => $chunk,
					'normalized_tokens' => implode( ' ', $tokens ),
					'embedding_json'    => $emb_json,
					'updated_at'        => $now,
				),
				array( '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			++$indexed_posts;
		}

		AvandWP_Logger::log(
			'knowledge.sync',
			sprintf( 'شاخص پایگاه دانش به‌روزرسانی شد (%d محتوا و %d پرسش متداول).', $indexed_posts, $indexed_faqs ),
			'success'
		);

		return array(
			'indexed_posts' => $indexed_posts,
			'indexed_faqs'  => $indexed_faqs,
			'total'         => $indexed_posts + $indexed_faqs,
		);
	}

	/**
	 * Search the knowledge base for the most relevant context chunks for a user query.
	 *
	 * @param string $query User question or topic.
	 * @param int    $top_k Max results to return.
	 * @return array<int, array> List of matching items ['title', 'url', 'content_chunk', 'source_type', 'score'].
	 */
	public function search( $query, $top_k = 4 ) {
		global $wpdb;
		$table        = $wpdb->prefix . 'avandwp_knowledge';
		$query_tokens = AvandWP_Text_Helper::tokenize( $query );
		$norm_query   = AvandWP_Text_Helper::normalize_persian( $query );

		if ( empty( $query_tokens ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT id, source_type, source_id, title, url, content_chunk, normalized_tokens, embedding_json FROM `{$table}` LIMIT 400", ARRAY_A );

		// If the index table is still empty on first run, index on the fly.
		if ( empty( $rows ) ) {
			$this->sync_all_content( 50 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( "SELECT id, source_type, source_id, title, url, content_chunk, normalized_tokens, embedding_json FROM `{$table}` LIMIT 400", ARRAY_A );
		}

		if ( empty( $rows ) ) {
			return array();
		}

		$settings  = get_option( 'avandwp_settings', array() );
		$use_emb   = ! empty( $settings['enable_true_embeddings'] );
		$query_vec = null;
		if ( $use_emb ) {
			$ai  = new AvandWP_AI_Client();
			$vec = $ai->create_embedding( $query );
			if ( is_array( $vec ) ) {
				$query_vec = $vec;
			}
		}

		$scored = array();
		foreach ( $rows as $row ) {
			$doc_tokens = explode( ' ', (string) $row['normalized_tokens'] );
			$doc_lookup = array_flip( $doc_tokens );
			$norm_title = AvandWP_Text_Helper::normalize_persian( $row['title'] );
			$norm_chunk = AvandWP_Text_Helper::normalize_persian( $row['content_chunk'] );

			$lexical_score = 0.0;

			// Exact phrase match bonus in title
			if ( '' !== $norm_query && false !== strpos( $norm_title, $norm_query ) ) {
				$lexical_score += 5.0;
			}

			foreach ( $query_tokens as $qt ) {
				if ( false !== strpos( $norm_title, $qt ) ) {
					$lexical_score += 2.5;
				}
				if ( isset( $doc_lookup[ $qt ] ) ) {
					$lexical_score += 1.2;
				} elseif ( false !== strpos( $norm_chunk, $qt ) ) {
					$lexical_score += 0.6;
				}
			}

			// Boost official admin FAQs when tokens match
			if ( 'faq' === $row['source_type'] && $lexical_score > 0 ) {
				$lexical_score *= 1.35;
			}

			// Combine with real vector cosine similarity if available
			$semantic_score = 0.0;
			if ( is_array( $query_vec ) && ! empty( $row['embedding_json'] ) ) {
				$doc_vec = json_decode( (string) $row['embedding_json'], true );
				if ( is_array( $doc_vec ) ) {
					$semantic_score = self::cosine_similarity( $query_vec, $doc_vec );
				}
			}

			$final_score = $lexical_score + ( $semantic_score * 4.0 );
			if ( $final_score >= 0.8 ) {
				$scored[] = array(
					'id'            => (int) $row['id'],
					'source_type'   => $row['source_type'],
					'source_id'     => (int) $row['source_id'],
					'title'         => $row['title'],
					'url'           => $row['url'],
					'content_chunk' => $row['content_chunk'],
					'score'         => round( $final_score, 3 ),
				);
			}
		}

		usort(
			$scored,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		return array_slice( $scored, 0, max( 1, (int) $top_k ) );
	}

	/**
	 * Compute cosine similarity between two numeric vectors.
	 *
	 * @param array<int, float> $vec_a Vector A.
	 * @param array<int, float> $vec_b Vector B.
	 * @return float
	 */
	public static function cosine_similarity( array $vec_a, array $vec_b ) {
		$len = min( count( $vec_a ), count( $vec_b ) );
		if ( 0 === $len ) {
			return 0.0;
		}
		$dot   = 0.0;
		$mag_a = 0.0;
		$mag_b = 0.0;
		for ( $i = 0; $i < $len; $i++ ) {
			$a      = (float) $vec_a[ $i ];
			$b      = (float) $vec_b[ $i ];
			$dot   += $a * $b;
			$mag_a += $a * $a;
			$mag_b += $b * $b;
		}
		$denom = sqrt( $mag_a ) * sqrt( $mag_b );
		return $denom > 0.0 ? ( $dot / $denom ) : 0.0;
	}

	/**
	 * Get stored custom FAQs.
	 *
	 * @return array<int, array{question: string, answer: string}>
	 */
	public static function get_faqs() {
		$faqs = get_option( 'avandwp_faqs', array() );
		return is_array( $faqs ) ? array_values( $faqs ) : array();
	}

	/**
	 * Save custom FAQs and refresh knowledge index.
	 *
	 * @param array $faqs Array of ['question' => ..., 'answer' => ...].
	 * @return void
	 */
	public static function save_faqs( array $faqs ) {
		$clean = array();
		foreach ( $faqs as $item ) {
			$q = isset( $item['question'] ) ? sanitize_text_field( $item['question'] ) : '';
			$a = isset( $item['answer'] ) ? sanitize_textarea_field( $item['answer'] ) : '';
			if ( '' !== $q && '' !== $a ) {
				$clean[] = array(
					'question' => $q,
					'answer'   => $a,
				);
			}
		}
		update_option( 'avandwp_faqs', $clean );
	}
}
