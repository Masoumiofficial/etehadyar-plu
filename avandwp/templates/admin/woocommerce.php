<?php
/**
 * Admin Page 3: WooCommerce AI Assistant (`templates/admin/woocommerce.php`).
 *
 * Scans products with accurate UTF-8 Persian word counting, performs single or
 * queued bulk optimization, and creates new draft products from an idea.
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$woo_active    = class_exists( 'WooCommerce' );
$woo_assistant = new AvandWP_Woo_Assistant();
$weak_products = $woo_active ? $woo_assistant->scan_weak_products( 80, 40 ) : array();
?>
<div class="wrap avandwp-wrap" dir="rtl">
	<?php include AVANDWP_PATH . 'templates/admin/partials/header.php'; ?>

	<?php if ( ! $woo_active ) : ?>
		<div class="avandwp-card">
			<div class="avandwp-empty-state">
				<h2>افزونه ووکامرس (WooCommerce) روی این سایت فعال نیست</h2>
				<p>برای استفاده از اسکنر محصولات کم‌محتوا، تولید خودکار توضیحات فنی، سؤالات متداول محصول و متای سئو، ابتدا افزونه WooCommerce را نصب و فعال کنید.</p>
			</div>
		</div>
	<?php else : ?>
		<div class="avandwp-tabs" data-tabs="woo">
			<button type="button" class="avandwp-tab-btn is-active" data-tab-target="tab-woo-scanner">۱. اسکنر و بهینه‌ساز محصولات موجود</button>
			<button type="button" class="avandwp-tab-btn" data-tab-target="tab-woo-create">۲. ساخت پیش‌نویس محصول جدید از ایده</button>
		</div>

		<!-- TAB 1: Product Scanner & Optimizer -->
		<div id="tab-woo-scanner" class="avandwp-tab-panel is-active">
			<div class="avandwp-card">
				<div class="avandwp-card-header">
					<div>
						<h2>محصولات نیازمند تکمیل محتوا و سئو</h2>
						<p class="avandwp-text-muted">محصولاتی که کمتر از ۸۰ کلمه فارسی توضیحات دارند یا فاقد متای سئو (Yoast / Rank Math) هستند.</p>
					</div>
					<div class="avandwp-header-actions">
						<button type="button" class="avandwp-btn avandwp-btn-secondary" id="avandwp-woo-rescan-btn">بررسی مجدد کاتالوگ</button>
						<button type="button" class="avandwp-btn avandwp-btn-primary" id="avandwp-woo-bulk-btn">بهینه‌سازی انتخاب‌شده‌ها در صف پس‌زمینه</button>
					</div>
				</div>

				<div id="avandwp-woo-table-wrap">
					<?php if ( empty( $weak_products ) ) : ?>
						<div class="avandwp-empty-state">
							<p>عالی! تمامی محصولات بررسی‌شده دارای توضیحات کامل فارسی و متای سئو هستند.</p>
						</div>
					<?php else : ?>
						<table class="avandwp-table" id="avandwp-woo-products-table">
							<thead>
								<tr>
									<th style="width:36px;"><input type="checkbox" id="avandwp-woo-select-all"></th>
									<th>نام محصول</th>
									<th>تعداد کلمات فعلی</th>
									<th>نواقص شناسایی‌شده</th>
									<th>عملیات</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $weak_products as $prod ) : ?>
									<tr data-product-id="<?php echo (int) $prod['id']; ?>">
										<td><input type="checkbox" class="avandwp-woo-item-cb" value="<?php echo (int) $prod['id']; ?>"></td>
										<td>
											<strong><?php echo esc_html( $prod['title'] ); ?></strong>
											<div class="avandwp-row-links">
												<a href="<?php echo esc_url( $prod['edit_url'] ); ?>" target="_blank" rel="noopener">ویرایش در ووکامرس</a>
											</div>
										</td>
										<td><?php echo esc_html( number_format_i18n( $prod['word_count'] ) ); ?> کلمه</td>
										<td>
											<?php foreach ( $prod['issues'] as $iss ) : ?>
												<span class="avandwp-badge avandwp-badge-warning"><?php echo esc_html( $iss ); ?></span>
											<?php endforeach; ?>
										</td>
										<td>
											<button type="button" class="avandwp-btn avandwp-btn-primary avandwp-btn-sm avandwp-woo-opt-single" data-id="<?php echo (int) $prod['id']; ?>">
												بهینه‌سازی هوشمند
											</button>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<!-- TAB 2: Create Product Draft from Idea -->
		<div id="tab-woo-create" class="avandwp-tab-panel" hidden>
			<div class="avandwp-grid avandwp-grid-2">
				<div class="avandwp-card">
					<div class="avandwp-card-header">
						<h2>ساخت پیش‌نویس کامل محصول با هوش مصنوعی</h2>
					</div>
					<form id="avandwp-woo-create-form" class="avandwp-form">
						<div class="avandwp-field">
							<label for="avandwp-woo-name">نام دقیق کالا یا محصول <span class="required">*</span></label>
							<input type="text" id="avandwp-woo-name" name="name" required placeholder="مثال: هدفون بی‌سیم سونی مدل WH-1000XM5">
						</div>
						<div class="avandwp-field">
							<label for="avandwp-woo-price">قیمت پایه (اختیاری)</label>
							<input type="text" id="avandwp-woo-price" name="price" dir="ltr" placeholder="18500000">
						</div>
						<div class="avandwp-field">
							<label for="avandwp-woo-brief">مشخصات فنی، گارانتی یا مزایای کلیدی</label>
							<textarea id="avandwp-woo-brief" name="brief" rows="4" placeholder="مثال: حذف نویز فعال، ۳۰ ساعت شارژدهی باتری، بلوتوث ۵.۲، گارانتی ۱۸ ماهه شرکتی..."></textarea>
						</div>
						<div class="avandwp-form-actions">
							<button type="submit" class="avandwp-btn avandwp-btn-primary">ساخت پیش‌نویس محصول در ووکامرس</button>
						</div>
					</form>
				</div>

				<div class="avandwp-card">
					<div class="avandwp-card-header">
						<h2>نتیجه ساخت محصول</h2>
					</div>
					<div id="avandwp-woo-create-result">
						<div class="avandwp-empty-state">
							<p>با واردکردن نام و ویژگی‌های کالا، یک پیش‌نویس کامل شامل معرفی کوتاه، بررسی تخصصی، برچسب‌ها، سؤالات متداول (FAQ Schema) و متای سئو در ووکامرس ساخته می‌شود.</p>
						</div>
					</div>
				</div>
			</div>
		</div>
	<?php endif; ?>
</div>
