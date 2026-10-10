<?php
/**
 * Admin Page 6: Settings, Vault & System Health (`templates/admin/settings.php`).
 *
 * 3-tab workspace:
 * 1) AES-256-GCM Credential Vault, Provider/Model Selection, and collapsible Advanced Settings.
 * 2) System Health & Live Diagnostics (verifies all 8 DB tables, OpenSSL, WP-Cron, WooCommerce).
 * 3) Job Queue, Real Token Usage Ledger, and Authenticated XLSX / Print-to-PDF Reports.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings    = get_option( 'avandwp_settings', array() );
$health      = AvandWP_Health::get_report();
$usage_30d   = AvandWP_Logger::get_usage_summary( 30 );
$recent_jobs = AvandWP_Job_Queue::get_recent_jobs( 25 );
$xlsx_url    = wp_nonce_url( admin_url( 'admin-post.php?action=avandwp_export_xlsx' ), 'avandwp_export_xlsx_nonce', '_wpnonce' );
?>
<div class="wrap avandwp-wrap" dir="rtl">
	<?php include AVANDWP_PATH . 'templates/admin/partials/header.php'; ?>

	<div class="avandwp-tabs" data-tabs="settings">
		<button type="button" class="avandwp-tab-btn is-active" data-tab-target="tab-vault">۱. کلیدهای API و مدل‌ها</button>
		<button type="button" class="avandwp-tab-btn" data-tab-target="tab-health">۲. سلامت سامانه و عیب‌یاب</button>
		<button type="button" class="avandwp-tab-btn" data-tab-target="tab-usage">۳. صف پردازش، مصرف توکن و گزارش‌ها</button>
	</div>

	<!-- TAB 1: API Keys Vault & Model Settings -->
	<div id="tab-vault" class="avandwp-tab-panel is-active">
		<form id="avandwp-settings-form" class="avandwp-form">
			<div class="avandwp-grid avandwp-grid-2">
				<div class="avandwp-card">
					<div class="avandwp-card-header">
						<div>
							<h2>گاوصندوق رمزنگاری‌شده کلیدهای API</h2>
							<p class="avandwp-text-muted">کلیدها با الگوریتم استاندارد <code>AES-256-GCM</code> در دیتابیس ذخیره می‌شوند و هرگز در کد صفحه افشا نمی‌گردند.</p>
						</div>
					</div>

					<?php
					$ai_slots = array(
						'gapgpt'    => array( 'label' => 'کلید GapGPT (پیشنهادی برای ایران — پرداخت ریالی و بدون تحریم)', 'testable' => true ),
						'openai'    => array( 'label' => 'کلید OpenAI مستقیم', 'testable' => true ),
						'gemini'    => array( 'label' => 'کلید Google Gemini', 'testable' => true ),
						'claude'    => array( 'label' => 'کلید Anthropic Claude', 'testable' => true ),
						'fal_flux'  => array( 'label' => 'کلید FAL.ai (تصویرساز Flux Schnell)', 'testable' => false ),
						'stability' => array( 'label' => 'کلید Stability AI (تصویرساز SD3)', 'testable' => false ),
					);
					foreach ( $ai_slots as $slot => $meta ) :
						$masked = AvandWP_Vault::get_masked_key( $slot );
						?>
						<div class="avandwp-field">
							<label for="avandwp-key-<?php echo esc_attr( $slot ); ?>"><?php echo esc_html( $meta['label'] ); ?></label>
							<div style="display:flex;gap:8px;">
								<input type="text" id="avandwp-key-<?php echo esc_attr( $slot ); ?>"
								       name="keys[<?php echo esc_attr( $slot ); ?>]"
								       dir="ltr"
								       value="<?php echo esc_attr( $masked ); ?>"
								       placeholder="کلید API را وارد کنید...">
								<?php if ( $meta['testable'] ) : ?>
									<button type="button" class="avandwp-btn avandwp-btn-secondary avandwp-btn-sm avandwp-test-provider-btn" data-provider="<?php echo esc_attr( $slot ); ?>">
										تست اتصال
									</button>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="avandwp-card">
					<div class="avandwp-card-header">
						<h2>انتخاب سرویس پیش‌فرض و مدل‌ها</h2>
					</div>

					<div class="avandwp-grid avandwp-grid-2">
						<div class="avandwp-field">
							<label for="avandwp-default-provider">سرویس هوش مصنوعی اصلی</label>
							<select id="avandwp-default-provider" name="default_provider">
								<option value="gapgpt" <?php selected( $settings['default_provider'] ?? 'gapgpt', 'gapgpt' ); ?>>GapGPT (پیشنهادی در ایران)</option>
								<option value="openai" <?php selected( $settings['default_provider'] ?? '', 'openai' ); ?>>OpenAI</option>
								<option value="gemini" <?php selected( $settings['default_provider'] ?? '', 'gemini' ); ?>>Google Gemini</option>
								<option value="claude" <?php selected( $settings['default_provider'] ?? '', 'claude' ); ?>>Anthropic Claude</option>
							</select>
						</div>
						<div class="avandwp-field">
							<label for="avandwp-fallback-provider">سرویس پشتیبان خودکار (Fallback)</label>
							<select id="avandwp-fallback-provider" name="fallback_provider">
								<option value="openai" <?php selected( $settings['fallback_provider'] ?? 'openai', 'openai' ); ?>>OpenAI</option>
								<option value="gapgpt" <?php selected( $settings['fallback_provider'] ?? '', 'gapgpt' ); ?>>GapGPT</option>
								<option value="gemini" <?php selected( $settings['fallback_provider'] ?? '', 'gemini' ); ?>>Google Gemini</option>
								<option value="claude" <?php selected( $settings['fallback_provider'] ?? '', 'claude' ); ?>>Anthropic Claude</option>
							</select>
						</div>
					</div>

					<div class="avandwp-grid avandwp-grid-2">
						<div class="avandwp-field">
							<label for="avandwp-model-gapgpt">مدل GapGPT</label>
							<input type="text" id="avandwp-model-gapgpt" name="model_gapgpt" dir="ltr" value="<?php echo esc_attr( $settings['model_gapgpt'] ?? 'gpt-4o-mini' ); ?>">
						</div>
						<div class="avandwp-field">
							<label for="avandwp-model-openai">مدل OpenAI</label>
							<input type="text" id="avandwp-model-openai" name="model_openai" dir="ltr" value="<?php echo esc_attr( $settings['model_openai'] ?? 'gpt-4o-mini' ); ?>">
						</div>
						<div class="avandwp-field">
							<label for="avandwp-model-gemini">مدل Gemini</label>
							<input type="text" id="avandwp-model-gemini" name="model_gemini" dir="ltr" value="<?php echo esc_attr( $settings['model_gemini'] ?? 'gemini-1.5-flash' ); ?>">
						</div>
						<div class="avandwp-field">
							<label for="avandwp-model-claude">مدل Claude</label>
							<input type="text" id="avandwp-model-claude" name="model_claude" dir="ltr" value="<?php echo esc_attr( $settings['model_claude'] ?? 'claude-3-5-haiku-latest' ); ?>">
						</div>
					</div>

					<!-- Collapsible Advanced Settings (Simplicity Principle) -->
					<details class="avandwp-advanced-details">
						<summary>تنظیمات پیشرفته (Advanced — دما، سقف توکن، Timeout و پاک‌سازی)</summary>
						<div class="avandwp-advanced-body">
							<div class="avandwp-grid avandwp-grid-3">
								<div class="avandwp-field">
									<label for="avandwp-temp">دمای خلاقیت (Temperature)</label>
									<input type="number" step="0.1" min="0" max="1.5" id="avandwp-temp" name="temperature" dir="ltr" value="<?php echo esc_attr( (string) ( $settings['temperature'] ?? 0.7 ) ); ?>">
								</div>
								<div class="avandwp-field">
									<label for="avandwp-max-tokens">سقف توکن خروجی</label>
									<input type="number" min="200" max="4096" id="avandwp-max-tokens" name="max_tokens" dir="ltr" value="<?php echo esc_attr( (string) ( $settings['max_tokens'] ?? 2500 ) ); ?>">
								</div>
								<div class="avandwp-field">
									<label for="avandwp-timeout">زمان انتظار (ثانیه)</label>
									<input type="number" min="15" max="180" id="avandwp-timeout" name="request_timeout" dir="ltr" value="<?php echo esc_attr( (string) ( $settings['request_timeout'] ?? 60 ) ); ?>">
								</div>
							</div>

							<div class="avandwp-checkbox-group">
								<label class="avandwp-checkbox">
									<input type="checkbox" name="enable_true_embeddings" value="1" <?php checked( ! empty( $settings['enable_true_embeddings'] ) ); ?>>
									<span>فعال‌سازی امبدینگ برداری معنایی (<code>text-embedding-3-small</code>) در کنار موتور جستجوی فارسی</span>
								</label>
								<label class="avandwp-checkbox">
									<input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( ! empty( $settings['delete_data_on_uninstall'] ) ); ?>>
									<span>حذف کامل جداول و تنظیمات آوند در صورت پاک‌کردن (Uninstall) افزونه از وردپرس</span>
								</label>
							</div>
						</div>
					</details>

					<div class="avandwp-form-actions">
						<button type="submit" class="avandwp-btn avandwp-btn-primary">ذخیره تمامی تنظیمات و کلیدها</button>
					</div>
				</div>
			</div>
		</form>
	</div>

	<!-- TAB 2: System Health & Diagnostics -->
	<div id="tab-health" class="avandwp-tab-panel" hidden>
		<div class="avandwp-grid avandwp-grid-2">
			<div class="avandwp-card">
				<div class="avandwp-card-header">
					<h2>بررسی وضعیت زیرساخت و محیط سرور</h2>
					<button type="button" class="avandwp-btn avandwp-btn-primary avandwp-btn-sm" id="avandwp-repair-btn">ترمیم خودکار جداول و کرون</button>
				</div>
				<table class="avandwp-table">
					<tbody>
						<tr>
							<td>نسخه افزونه و دیتابیس</td>
							<td><code>v<?php echo esc_html( $health['plugin_version'] ); ?> (DB: <?php echo esc_html( $health['db_version'] ); ?>)</code></td>
						</tr>
						<tr>
							<td>نسخه PHP و وردپرس</td>
							<td><code>PHP <?php echo esc_html( $health['php_version'] ); ?> | WP <?php echo esc_html( $health['wp_version'] ); ?></code></td>
						</tr>
						<tr>
							<td>رمزنگاری سخت‌افزاری OpenSSL (AES-256-GCM)</td>
							<td>
								<span class="avandwp-badge avandwp-badge-<?php echo $health['openssl_aes_gcm'] ? 'success' : 'warning'; ?>">
									<?php echo $health['openssl_aes_gcm'] ? 'فعال و ایمن' : 'غیرفعال (Fallback)'; ?>
								</span>
							</td>
						</tr>
						<tr>
							<td>وضعیت زمان‌بندی پس‌زمینه (WP-Cron)</td>
							<td>
								<span class="avandwp-badge avandwp-badge-<?php echo ! $health['wp_cron_disabled'] ? 'success' : 'warning'; ?>">
									<?php echo ! $health['wp_cron_disabled'] ? 'فعال' : 'DISABLE_WP_CRON فعال است'; ?>
								</span>
							</td>
						</tr>
						<tr>
							<td>ماژول ZipArchive (خروجی اکسل)</td>
							<td>
								<span class="avandwp-badge avandwp-badge-<?php echo $health['ziparchive_available'] ? 'success' : 'warning'; ?>">
									<?php echo $health['ziparchive_available'] ? 'فعال' : 'غیرفعال'; ?>
								</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="avandwp-card">
				<div class="avandwp-card-header">
					<h2>وضعیت ۸ جدول اختصاصی دیتابیس</h2>
				</div>
				<table class="avandwp-table">
					<thead>
						<tr>
							<th>نام جدول</th>
							<th>وضعیت</th>
							<th>تعداد رکورد</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $health['tables'] as $t_name => $t_info ) : ?>
							<tr>
								<td><code><?php echo esc_html( $t_name ); ?></code></td>
								<td>
									<span class="avandwp-badge avandwp-badge-<?php echo $t_info['exists'] ? 'success' : 'danger'; ?>">
										<?php echo $t_info['exists'] ? 'سالم' : 'ایجاد نشده'; ?>
									</span>
								</td>
								<td><?php echo esc_html( number_format_i18n( $t_info['rows'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<!-- TAB 3: Usage, Jobs & Executive Reports -->
	<div id="tab-usage" class="avandwp-tab-panel" hidden>
		<div class="avandwp-card" style="margin-bottom:20px;">
			<div class="avandwp-card-header">
				<div>
					<h2>گزارش مدیریتی و مصرف توکن (۳۰ روز اخیر)</h2>
					<p class="avandwp-text-muted">آمار واقعی ثبت‌شده از فراخوانی‌های موفق API</p>
				</div>
				<div style="display:flex;gap:8px;">
					<a href="<?php echo esc_url( $xlsx_url ); ?>" class="avandwp-btn avandwp-btn-primary avandwp-btn-sm">دانلود گزارش اکسل (.xlsx)</a>
					<button type="button" class="avandwp-btn avandwp-btn-secondary avandwp-btn-sm" onclick="window.print();">چاپ / ذخیره PDF استاندارد</button>
				</div>
			</div>

			<?php if ( empty( $usage_30d['by_provider'] ) ) : ?>
				<div class="avandwp-empty-state">
					<p>هنوز هیچ مصرف توکنی در ۳۰ روز گذشته ثبت نشده است.</p>
				</div>
			<?php else : ?>
				<table class="avandwp-table">
					<thead>
						<tr>
							<th>سرویس‌دهنده</th>
							<th>مدل</th>
							<th>تعداد درخواست</th>
							<th>مجموع توکن</th>
							<th>هزینه تخمینی (USD)</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $usage_30d['by_provider'] as $row ) : ?>
							<tr>
								<td><strong><?php echo esc_html( strtoupper( $row['provider'] ) ); ?></strong></td>
								<td><code><?php echo esc_html( $row['model'] ); ?></code></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row['requests'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $row['total_tokens'] ) ); ?></td>
								<td>$<?php echo esc_html( number_format( (float) $row['cost_usd'], 5 ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>
</div>
