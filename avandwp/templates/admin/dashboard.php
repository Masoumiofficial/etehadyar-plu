<?php
/**
 * Admin Page 1: Dashboard (`templates/admin/dashboard.php`).
 *
 * Displays 100% real database metrics, a 3-step onboarding checklist,
 * quick workflow shortcuts, and recent jobs/activity logs with clean Empty States.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

$health          = AvandWP_Health::get_report();
$usage_30d       = AvandWP_Logger::get_usage_summary( 30 );
$recent_jobs     = AvandWP_Job_Queue::get_recent_jobs( 6 );
$recent_logs     = AvandWP_Logger::get_recent_logs( 8 );
$open_tickets    = AvandWP_Support_Desk::get_tickets( 'new', 100 );
$ai_posts_count  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_avandwp_generated'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$woo_opt_count   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_avandwp_woo_optimized'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$knowledge_rows  = isset( $health['tables']['avandwp_knowledge']['rows'] ) ? (int) $health['tables']['avandwp_knowledge']['rows'] : 0;

$step1_done = ! empty( $health['has_any_ai_key'] );
$step2_done = $knowledge_rows > 0;
$step3_done = ( $ai_posts_count > 0 || $woo_opt_count > 0 );
?>
<div class="wrap avandwp-wrap" dir="rtl">
	<?php include AVANDWP_PATH . 'templates/admin/partials/header.php'; ?>

	<!-- 3-Step Real Onboarding Checklist -->
	<div class="avandwp-card avandwp-onboarding-card">
		<div class="avandwp-card-header">
			<h2>راهنمای شروع سریع آوند</h2>
			<span class="avandwp-text-muted">وضعیت واقعی راه‌اندازی سایت شما</span>
		</div>
		<div class="avandwp-grid avandwp-grid-3">
			<div class="avandwp-step-box <?php echo $step1_done ? 'is-done' : ''; ?>">
				<span class="avandwp-step-num"><?php echo $step1_done ? '✓' : '۱'; ?></span>
				<div>
					<h3>۱. اتصال کلید هوش مصنوعی</h3>
					<p>ثبت کلید GapGPT (ریالی و بدون تحریم) یا OpenAI/Gemini در گاوصندوق رمزنگاری‌شده.</p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=avandwp-settings' ) ); ?>" class="avandwp-btn avandwp-btn-secondary avandwp-btn-sm">
						<?php echo $step1_done ? 'مدیریت کلیدها' : 'ثبت کلید API'; ?>
					</a>
				</div>
			</div>

			<div class="avandwp-step-box <?php echo $step2_done ? 'is-done' : ''; ?>">
				<span class="avandwp-step-num"><?php echo $step2_done ? '✓' : '۲'; ?></span>
				<div>
					<h3>۲. ساخت شاخص پایگاه دانش سایت</h3>
					<p>شاخص‌گذاری نوشته‌ها، برگه‌ها، محصولات و پرسش‌های متداول برای پاسخ‌گویی مستند چت‌بات.</p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=avandwp-support#tab-knowledge' ) ); ?>" class="avandwp-btn avandwp-btn-secondary avandwp-btn-sm">
						<?php echo $step2_done ? sprintf( 'به‌روزرسانی (%d منبع فعال)', $knowledge_rows ) : 'ساخت شاخص دانش'; ?>
					</a>
				</div>
			</div>

			<div class="avandwp-step-box <?php echo $step3_done ? 'is-done' : ''; ?>">
				<span class="avandwp-step-num"><?php echo $step3_done ? '✓' : '۳'; ?></span>
				<div>
					<h3>۳. تولید اولین محتوا یا بهبود محصول</h3>
					<p>ساخت پیش‌نویس مقاله کامل با تصویر شاخص یا تکمیل سئوی محصولات ووکامرس.</p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=avandwp-studio' ) ); ?>" class="avandwp-btn avandwp-btn-primary avandwp-btn-sm">
						ورود به استودیو محتوا
					</a>
				</div>
			</div>
		</div>
	</div>

	<!-- Real-Time KPI Cards (Zero Fake Numbers) -->
	<div class="avandwp-grid avandwp-grid-4">
		<div class="avandwp-card avandwp-kpi-card">
			<span class="avandwp-kpi-label">مقالات تولیدشده با آوند</span>
			<strong class="avandwp-kpi-value"><?php echo esc_html( number_format_i18n( $ai_posts_count ) ); ?></strong>
			<span class="avandwp-kpi-meta">ذخیره‌شده در نوشته‌های وردپرس</span>
		</div>

		<div class="avandwp-card avandwp-kpi-card">
			<span class="avandwp-kpi-label">محصولات بهینه‌شده ووکامرس</span>
			<strong class="avandwp-kpi-value"><?php echo esc_html( number_format_i18n( $woo_opt_count ) ); ?></strong>
			<span class="avandwp-kpi-meta"><?php echo class_exists( 'WooCommerce' ) ? 'فروشگاه ووکامرس فعال است' : 'ووکامرس نصب نیست'; ?></span>
		</div>

		<div class="avandwp-card avandwp-kpi-card">
			<span class="avandwp-kpi-label">تیکت‌های پشتیبانی جدید</span>
			<strong class="avandwp-kpi-value"><?php echo esc_html( number_format_i18n( count( $open_tickets ) ) ); ?></strong>
			<span class="avandwp-kpi-meta">در انتظار پاسخ کارشناس</span>
		</div>

		<div class="avandwp-card avandwp-kpi-card">
			<span class="avandwp-kpi-label">مصرف توکن (۳۰ روز اخیر)</span>
			<strong class="avandwp-kpi-value"><?php echo esc_html( number_format_i18n( $usage_30d['total_tokens'] ) ); ?></strong>
			<span class="avandwp-kpi-meta"><?php echo esc_html( sprintf( '%d درخواست موفق (~$%s)', $usage_30d['requests'], $usage_30d['cost_usd'] ) ); ?></span>
		</div>
	</div>

	<!-- Recent Jobs & Recent Activity Logs -->
	<div class="avandwp-grid avandwp-grid-2">
		<div class="avandwp-card">
			<div class="avandwp-card-header">
				<h2>آخرین کارهای صف پس‌زمینه</h2>
				<button type="button" class="avandwp-btn avandwp-btn-secondary avandwp-btn-sm" id="avandwp-run-jobs-btn">پردازش فوری صف</button>
			</div>
			<?php if ( empty( $recent_jobs ) ) : ?>
				<div class="avandwp-empty-state">
					<p>هنوز هیچ کاری در صف پس‌زمینه ثبت نشده است.</p>
					<span class="avandwp-text-muted">هنگامی که تولید مقاله یا بهینه‌سازی دسته‌ای محصولات ووکامرس را در حالت پس‌زمینه اجرا کنید، وضعیت پیشرفت آن‌ها در اینجا نمایش داده می‌شود.</span>
				</div>
			<?php else : ?>
				<table class="avandwp-table">
					<thead>
						<tr>
							<th>شناسه</th>
							<th>نوع کار</th>
							<th>وضعیت</th>
							<th>تاریخ ثبت</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent_jobs as $job ) : ?>
							<tr>
								<td>#<?php echo (int) $job['id']; ?></td>
								<td><?php echo esc_html( $job['job_type'] ); ?></td>
								<td>
									<span class="avandwp-badge avandwp-badge-<?php echo 'completed' === $job['status'] ? 'success' : ( 'failed' === $job['status'] ? 'danger' : 'info' ); ?>">
										<?php echo esc_html( $job['status'] ); ?>
									</span>
								</td>
								<td><?php echo esc_html( $job['created_at'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="avandwp-card">
			<div class="avandwp-card-header">
				<h2>آخرین رویدادهای سامانه</h2>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=avandwp-settings#tab-usage' ) ); ?>" class="avandwp-link">مشاهده گزارش کامل ←</a>
			</div>
			<?php if ( empty( $recent_logs ) ) : ?>
				<div class="avandwp-empty-state">
					<p>هنوز رویدادی در سامانه ثبت نشده است.</p>
					<span class="avandwp-text-muted">تمامی عملیات تولید محتوا، بهینه‌سازی محصولات، ارسال تلگرام و تغییرات تنظیمات به‌صورت دقیق در این جدول ثبت می‌شوند.</span>
				</div>
			<?php else : ?>
				<table class="avandwp-table">
					<thead>
						<tr>
							<th>عملیات</th>
							<th>شرح رویداد</th>
							<th>زمان</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent_logs as $log ) : ?>
							<tr>
								<td><code><?php echo esc_html( $log['action'] ); ?></code></td>
								<td><?php echo esc_html( $log['message'] ); ?></td>
								<td><?php echo esc_html( $log['created_at'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>
</div>
