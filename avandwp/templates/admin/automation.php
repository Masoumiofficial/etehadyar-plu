<?php
/**
 * Admin Page 5: Automation & Telegram (`templates/admin/automation.php`).
 *
 * 2-tab workspace:
 * 1) Telegram Channel Publisher, SSRF-safe HTTPS Proxy, Live Diagnostics, and WooCommerce Order Alerts.
 * 2) Non-Destructive Content & SEO Auditor (identifies missing featured images, missing SEO meta,
 *    thin content, and internal link suggestions — never modifies posts without admin consent).
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tg_settings  = get_option( 'avandwp_telegram_settings', array() );
$masked_tg    = AvandWP_Vault::get_masked_key( 'telegram_bot' );
$recent_posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 25 ) );
$last_audit   = get_option( 'avandwp_auditor_last_report', array() );
?>
<div class="wrap avandwp-wrap" dir="rtl">
	<?php include AVANDWP_PATH . 'templates/admin/partials/header.php'; ?>

	<div class="avandwp-tabs" data-tabs="automation">
		<button type="button" class="avandwp-tab-btn is-active" data-tab-target="tab-telegram">۱. انتشار و اعلان‌های تلگرام</button>
		<button type="button" class="avandwp-tab-btn" data-tab-target="tab-auditor">۲. ممیزی محتوا و سئو (غیرمخرب)</button>
	</div>

	<!-- TAB 1: Telegram Integration -->
	<div id="tab-telegram" class="avandwp-tab-panel is-active">
		<div class="avandwp-grid avandwp-grid-2">
			<div class="avandwp-card">
				<div class="avandwp-card-header">
					<h2>پیکربندی ربات و کانال تلگرام</h2>
				</div>
				<form id="avandwp-telegram-form" class="avandwp-form">
					<div class="avandwp-field">
						<label for="avandwp-tg-token">توکن ربات تلگرام (BotFather Token)</label>
						<input type="text" id="avandwp-tg-token" name="bot_token" dir="ltr"
						       value="<?php echo esc_attr( $masked_tg ); ?>"
						       placeholder="123456789:ABCdefGHIjklMNOpqrsTUVwxyz">
						<small class="avandwp-text-muted">توکن با رمزنگاری AES-256-GCM در گاوصندوق ذخیره می‌شود.</small>
					</div>

					<div class="avandwp-field">
						<label for="avandwp-tg-chatid">شناسه کانال یا گروه مقصد (Chat ID)</label>
						<input type="text" id="avandwp-tg-chatid" name="chat_id" dir="ltr"
						       value="<?php echo esc_attr( $tg_settings['chat_id'] ?? '' ); ?>"
						       placeholder="@my_channel یا -1001234567890">
					</div>

					<div class="avandwp-field">
						<label for="avandwp-tg-proxy">آدرس پروکسی امن HTTPS (اختیاری — برای هاست‌های داخل ایران)</label>
						<input type="url" id="avandwp-tg-proxy" name="proxy_url" dir="ltr"
						       value="<?php echo esc_attr( $tg_settings['proxy_url'] ?? '' ); ?>"
						       placeholder="https://your-worker.workers.dev">
						<small class="avandwp-text-muted">فقط آدرس‌های معتبر با پروتکل <code>https://</code> پذیرفته می‌شوند.</small>
					</div>

					<div class="avandwp-checkbox-group">
						<label class="avandwp-checkbox">
							<input type="checkbox" name="auto_publish_posts" value="1" <?php checked( ! empty( $tg_settings['auto_publish_posts'] ) ); ?>>
							<span>انتشار خودکار نوشته‌های جدید سایت در کانال تلگرام بلافاصله پس از انتشار</span>
						</label>
						<label class="avandwp-checkbox">
							<input type="checkbox" name="notify_woo_orders" value="1" <?php checked( ! empty( $tg_settings['notify_woo_orders'] ) ); ?>>
							<span>ارسال اعلان فوری سفارش‌های جدید ووکامرس به تلگرام</span>
						</label>
						<label class="avandwp-checkbox">
							<input type="checkbox" name="notify_support" value="1" <?php checked( ! empty( $tg_settings['notify_support'] ) ); ?>>
							<span>ارسال اعلان فوری تیکت‌های جدید پشتیبانی به تلگرام</span>
						</label>
					</div>

					<div class="avandwp-form-actions">
						<button type="submit" class="avandwp-btn avandwp-btn-primary">ذخیره تنظیمات تلگرام</button>
						<button type="button" class="avandwp-btn avandwp-btn-secondary" id="avandwp-test-tg-btn">تست اتصال و ارسال پیام آزمایشی</button>
					</div>
				</form>
				<div id="avandwp-tg-diag-result" style="margin-top:16px;"></div>
			</div>

			<div class="avandwp-card">
				<div class="avandwp-card-header">
					<h2>ارسال دستی نوشته به تلگرام</h2>
				</div>
				<form id="avandwp-tg-publish-form" class="avandwp-form">
					<div class="avandwp-field">
						<label for="avandwp-tg-post-select">انتخاب نوشته منتشرشده</label>
						<select id="avandwp-tg-post-select" name="post_id">
							<?php foreach ( $recent_posts as $rp ) : ?>
								<option value="<?php echo (int) $rp->ID; ?>"><?php echo esc_html( get_the_title( $rp ) ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="avandwp-form-actions">
						<button type="submit" class="avandwp-btn avandwp-btn-primary">ارسال فوری به کانال تلگرام</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<!-- TAB 2: Non-Destructive Content & SEO Auditor -->
	<div id="tab-auditor" class="avandwp-tab-panel" hidden>
		<div class="avandwp-card">
			<div class="avandwp-card-header">
				<div>
					<h2>ممیزی سلامت محتوا، متای سئو و لینک‌سازی داخلی</h2>
					<p class="avandwp-text-muted">این ابزار نوشته‌های سایت را بدون هیچ‌گونه تغییر خودسرانه بررسی کرده و موارد نیازمند بهبود را به شما گزارش می‌دهد.</p>
				</div>
				<button type="button" class="avandwp-btn avandwp-btn-primary" id="avandwp-run-audit-btn">اجرای اسکن ممیزی محتوا</button>
			</div>

			<div id="avandwp-audit-container">
				<?php if ( empty( $last_audit['items'] ) ) : ?>
					<div class="avandwp-empty-state">
						<p>برای بررسی نوشته‌های بدون تصویر شاخص، بدون توضیحات سئو یا نیازمند لینک داخلی، روی دکمه «اجرای اسکن ممیزی محتوا» کلیک کنید.</p>
					</div>
				<?php else : ?>
					<table class="avandwp-table">
						<thead>
							<tr>
								<th>عنوان نوشته</th>
								<th>تعداد کلمات</th>
								<th>موارد شناسایی‌شده</th>
								<th>پیشنهاد لینک داخلی از پایگاه دانش</th>
								<th>عملیات</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $last_audit['items'] as $item ) : ?>
								<tr>
									<td>
										<strong><?php echo esc_html( $item['title'] ); ?></strong>
										<div class="avandwp-row-links">
											<a href="<?php echo esc_url( $item['edit_url'] ); ?>" target="_blank" rel="noopener">ویرایش نوشته</a>
										</div>
									</td>
									<td><?php echo esc_html( number_format_i18n( $item['word_count'] ) ); ?> کلمه</td>
									<td>
										<?php foreach ( $item['issues'] as $iss ) : ?>
											<span class="avandwp-badge avandwp-badge-warning"><?php echo esc_html( $iss ); ?></span>
										<?php endforeach; ?>
									</td>
									<td>
										<?php if ( ! empty( $item['suggested_link'] ) ) : ?>
											<a href="<?php echo esc_url( $item['suggested_link']['url'] ); ?>" target="_blank" rel="noopener">
												<?php echo esc_html( $item['suggested_link']['title'] ); ?>
											</a>
										<?php else : ?>
											<span class="avandwp-text-muted">—</span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( empty( $item['has_seo'] ) ) : ?>
											<button type="button" class="avandwp-btn avandwp-btn-secondary avandwp-btn-sm avandwp-fix-seo-btn" data-post-id="<?php echo (int) $item['post_id']; ?>">
												تولید متای سئو با AI
											</button>
										<?php else : ?>
											<span class="avandwp-badge avandwp-badge-success">متای سئو کامل</span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>
