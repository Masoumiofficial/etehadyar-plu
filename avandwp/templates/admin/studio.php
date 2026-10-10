<?php
/**
 * Admin Page 2: Content & Media Studio (`templates/admin/studio.php`).
 *
 * Unified 3-tab studio:
 * 1) SEO Article Writer (with featured image, FAQ schema, audio, and background queue support).
 * 2) AI Image Generator (DALL-E 3, FAL Flux, Stability SD3).
 * 3) Podcast Audio Synthesizer (TTS MP3).
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$categories = get_categories( array( 'hide_empty' => false ) );
?>
<div class="wrap avandwp-wrap" dir="rtl">
	<?php include AVANDWP_PATH . 'templates/admin/partials/header.php'; ?>

	<div class="avandwp-tabs" data-tabs="studio">
		<button type="button" class="avandwp-tab-btn is-active" data-tab-target="tab-article">۱. نگارش مقاله سئوشده</button>
		<button type="button" class="avandwp-tab-btn" data-tab-target="tab-image">۲. کارگاه تصویر هوشمند</button>
		<button type="button" class="avandwp-tab-btn" data-tab-target="tab-audio">۳. تبدیل متن به پادکست صوتی (TTS)</button>
	</div>

	<!-- TAB 1: Article Generator -->
	<div id="tab-article" class="avandwp-tab-panel is-active">
		<div class="avandwp-grid avandwp-grid-2">
			<div class="avandwp-card">
				<div class="avandwp-card-header">
					<h2>مشخصات مقاله جدید</h2>
				</div>
				<form id="avandwp-article-form" class="avandwp-form">
					<div class="avandwp-field">
						<label for="avandwp-topic">موضوع یا عنوان پیشنهادی مقاله <span class="required">*</span></label>
						<input type="text" id="avandwp-topic" name="topic" required placeholder="مثال: راهنمای جامع انتخاب هاست مناسب برای فروشگاه ووکامرس">
					</div>

					<div class="avandwp-grid avandwp-grid-2">
						<div class="avandwp-field">
							<label for="avandwp-keyword">کلمه کلیدی کانونی (سئو)</label>
							<input type="text" id="avandwp-keyword" name="keyword" placeholder="مثال: خرید هاست ووکامرس">
						</div>
						<div class="avandwp-field">
							<label for="avandwp-tone">لحن نگارش</label>
							<select id="avandwp-tone" name="tone">
								<option value="حرفه‌ای، کاربردی و روان">حرفه‌ای، کاربردی و روان</option>
								<option value="آموزشی و گام‌به‌گام">آموزشی و گام‌به‌گام</option>
								<option value="تحلیلی و تخصصی">تحلیلی و تخصصی</option>
								<option value="صمیمی و محاوره‌ای استاندارد">صمیمی و روان</option>
							</select>
						</div>
					</div>

					<div class="avandwp-grid avandwp-grid-3">
						<div class="avandwp-field">
							<label for="avandwp-target-words">طول هدف (کلمه)</label>
							<select id="avandwp-target-words" name="target_words">
								<option value="600">کوتاه (~۶۰۰ کلمه)</option>
								<option value="1000" selected>استاندارد (~۱۰۰۰ کلمه)</option>
								<option value="1600">جامع (~۱۶۰۰ کلمه)</option>
							</select>
						</div>
						<div class="avandwp-field">
							<label for="avandwp-category">دسته‌بندی نوشته</label>
							<select id="avandwp-category" name="category_id">
								<option value="0">— پیش‌فرض سایت —</option>
								<?php foreach ( $categories as $cat ) : ?>
									<option value="<?php echo (int) $cat->term_id; ?>"><?php echo esc_html( $cat->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="avandwp-field">
							<label for="avandwp-post-status">وضعیت ذخیره</label>
							<select id="avandwp-post-status" name="post_status">
								<option value="draft" selected>پیش‌نویس (توصیه‌شده برای بازبینی)</option>
								<option value="pending">در انتظار بازبینی</option>
								<option value="publish">انتشار مستقیم</option>
							</select>
						</div>
					</div>

					<div class="avandwp-checkbox-group">
						<label class="avandwp-checkbox">
							<input type="checkbox" name="use_knowledge" value="1" checked>
							<span>استفاده از پایگاه دانش سایت برای لینک‌سازی داخلی واقعی</span>
						</label>
						<label class="avandwp-checkbox">
							<input type="checkbox" name="generate_image" value="1">
							<span>ساخت و الصاق خودکار تصویر شاخص مرتبط</span>
						</label>
						<label class="avandwp-checkbox">
							<input type="checkbox" name="generate_audio" value="1">
							<span>تولید خلاصه صوتی (پادکست MP3) برای مقاله</span>
						</label>
						<label class="avandwp-checkbox">
							<input type="checkbox" name="background" value="1">
							<span>اجرا در صف پردازش پس‌زمینه (مناسب هاست‌های اشتراکی با محدودیت Timeout)</span>
						</label>
					</div>

					<div class="avandwp-form-actions">
						<button type="submit" class="avandwp-btn avandwp-btn-primary" id="avandwp-article-submit">
							تولید مقاله و ذخیره در وردپرس
						</button>
					</div>
				</form>
			</div>

			<div class="avandwp-card">
				<div class="avandwp-card-header">
					<h2>پیش‌نمایش و خروجی مقاله</h2>
				</div>
				<div id="avandwp-article-result" class="avandwp-result-container">
					<div class="avandwp-empty-state">
						<p>هنوز مقاله‌ای در این نشست تولید نشده است.</p>
						<span class="avandwp-text-muted">پس از تکمیل فرم روبه‌رو، عنوان سئو، تعداد دقیق کلمات فارسی، لینک ویرایش پیش‌نویس، کپشن تلگرام و پیش‌نمایش مقاله در این کادر نمایش داده می‌شود.</span>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- TAB 2: Image Studio -->
	<div id="tab-image" class="avandwp-tab-panel" hidden>
		<div class="avandwp-grid avandwp-grid-2">
			<div class="avandwp-card">
				<div class="avandwp-card-header">
					<h2>تولید تصویر برای رسانه وردپرس</h2>
				</div>
				<form id="avandwp-image-form" class="avandwp-form">
					<div class="avandwp-field">
						<label for="avandwp-img-prompt">توضیح تصویر (پرامپت فارسی یا انگلیسی) <span class="required">*</span></label>
						<textarea id="avandwp-img-prompt" name="prompt" rows="4" required placeholder="مثال: A clean minimalist workspace with a laptop showing e-commerce analytics charts, warm natural lighting"></textarea>
					</div>

					<div class="avandwp-field">
						<label for="avandwp-img-alt">متن جایگزین سئو (Alt Text فارسی)</label>
						<input type="text" id="avandwp-img-alt" name="alt_text" placeholder="مثال: میز کار مدیریت فروشگاه اینترنتی ووکامرس">
					</div>

					<div class="avandwp-grid avandwp-grid-2">
						<div class="avandwp-field">
							<label for="avandwp-img-size">ابعاد تصویر</label>
							<select id="avandwp-img-size" name="size">
								<option value="1792x1024">افقی عریض (1792×1024 — مناسب تصویر شاخص مقاله)</option>
								<option value="1024x1024" selected>مربع (1024×1024 — مناسب محصول و شبکه اجتماعی)</option>
								<option value="1024x1792">عمودی (1024×1792 — مناسب استوری)</option>
							</select>
						</div>
						<div class="avandwp-field">
							<label for="avandwp-img-provider">سرویس تصویرساز</label>
							<select id="avandwp-img-provider" name="provider">
								<option value="gapgpt">GapGPT (DALL-E 3)</option>
								<option value="openai">OpenAI مستقیم (DALL-E 3)</option>
								<option value="fal_flux">FAL.ai (Flux Schnell)</option>
								<option value="stability">Stability AI (SD3)</option>
							</select>
						</div>
					</div>

					<div class="avandwp-form-actions">
						<button type="submit" class="avandwp-btn avandwp-btn-primary">ساخت تصویر و افزودن به کتابخانه رسانه</button>
					</div>
				</form>
			</div>

			<div class="avandwp-card">
				<div class="avandwp-card-header">
					<h2>تصویر تولیدشده</h2>
				</div>
				<div id="avandwp-image-result">
					<div class="avandwp-empty-state">
						<p>تصویر ساخته‌شده بلافاصله در کتابخانه رسانه وردپرس ذخیره شده و در این بخش نمایش داده می‌شود.</p>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- TAB 3: Audio TTS -->
	<div id="tab-audio" class="avandwp-tab-panel" hidden>
		<div class="avandwp-grid avandwp-grid-2">
			<div class="avandwp-card">
				<div class="avandwp-card-header">
					<h2>تبدیل متن به گفتار (پادکست MP3)</h2>
				</div>
				<form id="avandwp-tts-form" class="avandwp-form">
					<div class="avandwp-field">
						<label for="avandwp-tts-text">متن ورودی برای خوانش <span class="required">*</span></label>
						<textarea id="avandwp-tts-text" name="text" rows="5" required placeholder="متن خلاصه مقاله یا معرفی محصول را وارد کنید..."></textarea>
					</div>
					<div class="avandwp-field">
						<label for="avandwp-tts-voice">انتخاب لحن و صدای گوینده</label>
						<select id="avandwp-tts-voice" name="voice">
							<option value="nova" selected>Nova (شفاف و طبیعی — پیشنهادی)</option>
							<option value="alloy">Alloy (متعادل و رسمی)</option>
							<option value="onyx">Onyx (بم و خبری)</option>
							<option value="shimmer">Shimmer (ملایم و صمیمی)</option>
						</select>
					</div>
					<div class="avandwp-form-actions">
						<button type="submit" class="avandwp-btn avandwp-btn-primary">ساخت فایل صوتی MP3</button>
					</div>
				</form>
			</div>

			<div class="avandwp-card">
				<div class="avandwp-card-header">
					<h2>پخش‌کننده و لینک فایل صوتی</h2>
				</div>
				<div id="avandwp-tts-result">
					<div class="avandwp-empty-state">
						<p>فایل MP3 تولیدشده در کتابخانه رسانه ذخیره شده و کد کوتاه پخش‌کننده آن در اینجا قرار می‌گیرد.</p>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
