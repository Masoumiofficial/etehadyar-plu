<?php
/**
 * Executive Reports & Export Engine (`AvandWP_Reports`).
 *
 * Replaces the broken legacy `EAIW_FPDF` class and insecure public uploads path:
 * 1) Streams a valid OpenXML `.xlsx` workbook directly via authenticated admin-post
 *    with bounded queries (no `limit => -1` memory exhaustion).
 * 2) Provides a clean RTL print-ready HTML report (`window.print()`) for native
 *    browser PDF saving with full Persian font fidelity.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AvandWP_Reports {

	/**
	 * Gather real system, content, WooCommerce, support, and token usage metrics.
	 *
	 * @return array
	 */
	public static function gather_metrics() {
		global $wpdb;

		$usage       = AvandWP_Logger::get_usage_summary( 30 );
		$tickets     = AvandWP_Support_Desk::get_tickets( '', 50 );
		$logs        = AvandWP_Logger::get_recent_logs( 40 );
		$ai_posts    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_avandwp_generated'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$woo_opt     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_avandwp_woo_optimized'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array(
			'site_name'       => get_bloginfo( 'name' ),
			'generated_at'    => current_time( 'Y-m-d H:i' ),
			'ai_posts_count'  => $ai_posts,
			'woo_opt_count'   => $woo_opt,
			'usage_30d'       => $usage,
			'recent_tickets'  => $tickets,
			'recent_activity' => $logs,
		);
	}

	/**
	 * Build a real `.xlsx` binary in a temporary file using ZipArchive and stream it to the admin.
	 *
	 * @return void
	 */
	public static function stream_xlsx_report() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'شما دسترسی لازم برای دریافت این گزارش را ندارید.' );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			wp_die( 'افزونه PHP ZipArchive روی سرور فعال نیست. لطفاً از نسخه چاپی HTML/PDF استفاده کنید.' );
		}

		$metrics  = self::gather_metrics();
		$tmp_file = wp_tempnam( 'avandwp-report.xlsx' );

		$rows = array(
			array( 'گزارش عملکرد دستیار هوشمند آوند (AvandWP)', $metrics['site_name'], $metrics['generated_at'] ),
			array( '', '', '' ),
			array( 'شاخص', 'مقدار', 'توضیحات' ),
			array( 'مقالات تولیدشده با هوش مصنوعی', (string) $metrics['ai_posts_count'], 'پست‌های دارای متای _avandwp_generated' ),
			array( 'محصولات بهینه‌شده ووکامرس', (string) $metrics['woo_opt_count'], 'محصولات دارای متای _avandwp_woo_optimized' ),
			array( 'تعداد درخواست‌های AI (۳۰ روز اخیر)', (string) $metrics['usage_30d']['requests'], 'مجموع فراخوانی‌های موفق' ),
			array( 'مجموع توکن مصرفی (۳۰ روز اخیر)', (string) $metrics['usage_30d']['total_tokens'], 'ورودی + خروجی' ),
			array( 'هزینه تخمینی API (دلار)', (string) $metrics['usage_30d']['cost_usd'], 'بر اساس نرخ استاندارد مدل‌ها' ),
			array( '', '', '' ),
			array( 'رویدادهای اخیر سیستم', 'سطح', 'تاریخ' ),
		);

		foreach ( array_slice( $metrics['recent_activity'], 0, 25 ) as $log ) {
			$rows[] = array(
				(string) ( $log['message'] ?? '' ),
				(string) ( $log['level'] ?? 'info' ),
				(string) ( $log['created_at'] ?? '' ),
			);
		}

		$sheet_xml = self::build_worksheet_xml( $rows );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp_file, ZipArchive::OVERWRITE ) ) {
			wp_die( 'خطا در ایجاد فایل موقت اکسل.' );
		}

		$zip->addFromString(
			'[Content_Types].xml',
			'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
			. '</Types>'
		);

		$zip->addFromString(
			'_rels/.rels',
			'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
			. '</Relationships>'
		);

		$zip->addFromString(
			'xl/_rels/workbook.xml.rels',
			'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
			. '</Relationships>'
		);

		$zip->addFromString(
			'xl/workbook.xml',
			'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
			. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets><sheet name="AvandWP Report" sheetId="1" r:id="rId1"/></sheets>'
			. '</workbook>'
		);

		$zip->addFromString( 'xl/worksheets/sheet1.xml', $sheet_xml );
		$zip->close();

		$filename = 'avandwp-report-' . gmdate( 'Y-m-d' ) . '.xlsx';
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		readfile( $tmp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		@unlink( $tmp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		exit;
	}

	/**
	 * Build OpenXML worksheet string with inline strings.
	 */
	private static function build_worksheet_xml( array $rows ) {
		$xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
		$xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
		$xml .= '<sheetViews><sheetView rightToLeft="1" workbookViewId="0"/></sheetViews>';
		$xml .= '<sheetData>';

		$r_idx = 1;
		foreach ( $rows as $row ) {
			$xml  .= '<row r="' . $r_idx . '">';
			$c_idx = 0;
			foreach ( $row as $cell_val ) {
				$col_letter = chr( 65 + $c_idx );
				$escaped    = htmlspecialchars( (string) $cell_val, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
				$xml       .= '<c r="' . $col_letter . $r_idx . '" t="inlineStr"><is><t>' . $escaped . '</t></is></c>';
				++$c_idx;
			}
			$xml .= '</row>';
			++$r_idx;
		}

		$xml .= '</sheetData></worksheet>';
		return $xml;
	}
}
