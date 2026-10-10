<?php
/**
 * Admin Page 4: Chat Assistant & Support Desk (`templates/admin/support.php`).
 *
 * 3-tab workspace:
 * 1) Customer Support Tickets (`avandwp_support_requests`) + Audio Playback + Whisper Transcript.
 * 2) Knowledge Base & FAQs (`avandwp_faqs`, `avandwp_knowledge`) + Negative Feedback converter.
 * 3) Frontend Chat Widget Settings (`avandwp_chat_settings`).
 *
 * @package AvandWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tickets       = AvandWP_Support_Desk::get_tickets( '', 60 );
$faqs          = AvandWP_Knowledge_Base::get_faqs();
$feedback_list = AvandWP_Support_Desk::get_feedback_list( 30 );
$chat_settings = get_option( 'avandwp_chat_settings', array() );
$health        = AvandWP_Health::get_report();
$kb_count      = isset( $health['tables']['avandwp_knowledge']['rows'] ) ? (int) $health['tables']['avandwp_knowledge']['rows'] : 0;
?>
<div class="wrap avandwp-wrap" dir="rtl">
	<?php include AVANDWP_PATH . 'templates/admin/partials/header.php'; ?>

	<div class="avandwp-tabs" data-tabs="support">
		<button type="button" class="avandwp-tab-btn is-active" data-tab-target="tab-tickets">۱. صندوق تیکت‌های پشتیبانی (<?php echo count( $tickets ); ?>)</button>
		<button type="button" class="avandwp-tab-btn" data-tab-target="tab-knowledge">۲. پایگاه دانش و سؤالات متداول (<?php echo (int) $kb_count; ?> منبع)</button>
		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<button type="button" class="avandwp-tab-btn" data-tab-target="tab-chat-settings">۳. تنظیمات ویجت چت‌بات سایت</button>
		<?php endif; ?>
	</div>

	<!-- TAB 1: Support Tickets -->
	<div id="tab-tickets" class="avandwp-tab-panel is-active">
		<div class="avandwp-card">
			<div class="avandwp-card-header">
				<h2>تیکت‌های ارجاع‌شده از چت‌بات سایت</h2>
				<span class="avandwp-text-muted">شامل درخواست‌های متنی و پیام‌های صوتی کاربران</span>
			</div>

			<?php if ( empty( $tickets ) ) : ?>
				<div class="avandwp-empty-state">
					<p>صندوق پشتیبانی خالی است و هیچ تیکتی در انتظار بررسی نیست.</p>
					<span class="avandwp-text-muted">هنگامی که کاربران در چت‌بات سایت درخواست ارتباط با کارشناس ثبت کنند یا پیام صوتی بفرستند، تیکت آن‌ها در این جدول نمایش داده می‌شود.</span>
				</div>
			<?php else : ?>
				<table class="avandwp-table">
					<thead>
						<tr>
							<th>کد</th>
							<th>نام کاربر و راه ارتباطی</th>
							<th>پیام / متن پیاده‌سازی‌شده صوت</th>
							<th>وضعیت و اولویت</th>
							<th>پاسخ کارشناس و اقدام</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $tickets as $t ) : ?>
							<tr>
								<td><strong>#<?php echo (int) $t['id']; ?></strong><br><small class="avandwp-text-muted"><?php echo esc_html( $t['created_at'] ); ?></small></td>
								<td>
									<strong><?php echo esc_html( $t['user_name'] ); ?></strong><br>
									<code dir="ltr"><?php echo esc_html( $t['user_contact'] ); ?></code>
								</td>
								<td>
									<p style="margin:0 0 6px;"><?php echo esc_html( $t['message'] ); ?></p>
									<?php if ( ! empty( $t['audio_url'] ) ) : ?>
										<audio controls preload="none" src="<?php echo esc_url( $t['audio_url'] ); ?>" style="max-width:240px;height:32px;"></audio>
									<?php endif; ?>
									<?php if ( ! empty( $t['transcript'] ) ) : ?>
										<div class="avandwp-transcript-box">
											<small><strong>متن صوت (Whisper):</strong> <?php echo esc_html( $t['transcript'] ); ?></small>
										</div>
									<?php endif; ?>
								</td>
								<td>
									<span class="avandwp-badge avandwp-badge-<?php echo 'answered' === $t['status'] || 'closed' === $t['status'] ? 'success' : ( 'new' === $t['status'] ? 'warning' : 'info' ); ?>">
										<?php echo esc_html( $t['status'] ); ?>
									</span>
									<br>
									<small>اولویت: <?php echo esc_html( $t['priority'] ); ?></small>
								</td>
								<td style="min-width:260px;">
									<div class="avandwp-ticket-action-box" data-ticket-id="<?php echo (int) $t['id']; ?>">
										<textarea class="avandwp-ticket-reply" rows="2" placeholder="ثبت پاسخ یا یادداشت کارشناس..."><?php echo esc_textarea( (string) ( $t['admin_reply'] ?? '' ) ); ?></textarea>
										<div style="display:flex;gap:8px;margin-top:6px;">
											<select class="avandwp-ticket-status">
												<option value="new" <?php selected( $t['status'], 'new' ); ?>>جدید</option>
												<option value="in_progress" <?php selected( $t['status'], 'in_progress' ); ?>>در حال بررسی</option>
												<option value="answered" <?php selected( $t['status'], 'answered' ); ?>>پاسخ داده‌شده</option>
												<option value="closed" <?php selected( $t['status'], 'closed' ); ?>>بسته</option>
											</select>
											<button type="button" class="avandwp-btn avandwp-btn-primary avandwp-btn-sm avandwp-save-ticket-btn">ذخیره</button>
										</div>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</div>

	<!-- TAB 2: Knowledge Base & FAQs -->
	<div id="tab-knowledge" class="avandwp-tab-panel" hidden>
		<div class="avandwp-grid avandwp-grid-2">
			<div class="avandwp-card">
				<div class="avandwp-card-header">
					<div>
						<h2>پرسش‌های متداول رسمی سایت (FAQ)</h2>
						<p class="avandwp-text-muted">چت‌بات در پاسخ‌گویی به کاربران، بالاترین اولویت را به این پرسش و پاسخ‌ها می‌دهد.</p>
					</div>
					<button type="button" class="avandwp-btn avandwp-btn-secondary avandwp-btn-sm" id="avandwp-add-faq-row">+ افزودن پرسش جدید</button>
				</div>

				<form id="avandwp-faqs-form" class="avandwp-form">
					<div id="avandwp-faqs-list">
						<?php if ( empty( $faqs ) ) : ?>
							<div class="avandwp-faq-row">
								<input type="text" name="faq_q[]" placeholder="مثال: هزینه و زمان ارسال سفارش‌ها چقدر است؟">
								<textarea name="faq_a[]" rows="2" placeholder="مثال: سفارش‌های تهران ظرف ۲۴ ساعت و شهرستان‌ها ظرف ۲ تا ۴ روز کاری با پست پیشتاز ارسال می‌شوند."></textarea>
							</div>
						<?php else : ?>
							<?php foreach ( $faqs as $f ) : ?>
								<div class="avandwp-faq-row">
									<input type="text" name="faq_q[]" value="<?php echo esc_attr( $f['question'] ); ?>" placeholder="عنوان پرسش">
									<textarea name="faq_a[]" rows="2" placeholder="پاسخ دقیق"><?php echo esc_textarea( $f['answer'] ); ?></textarea>
								</div>
							<?php endforeach; ?>
						<?php endif; ?>
					</div>
					<div class="avandwp-form-actions">
						<button type="submit" class="avandwp-btn avandwp-btn-primary">ذخیره پرسش‌ها و بازسازی پایگاه دانش</button>
						<button type="button" class="avandwp-btn avandwp-btn-secondary" id="avandwp-sync-kb-btn">بازسازی کامل شاخص نوشته‌ها و محصولات</button>
					</div>
				</form>
			</div>

			<div class="avandwp-card">
				<div class="avandwp-card-header">
					<h2>بازخورد کاربران درباره پاسخ‌های چت‌بات</h2>
				</div>
				<?php if ( empty( $feedback_list ) ) : ?>
					<div class="avandwp-empty-state">
						<p>هنوز بازخوردی از طرف کاربران ثبت نشده است.</p>
						<span class="avandwp-text-muted">کاربران می‌توانند زیر هر پاسخ چت‌بات دکمه مفید بودن (👍/👎) را بزنند تا بتوانید پاسخ‌های ناقص را به FAQ تبدیل کنید.</span>
					</div>
				<?php else : ?>
					<table class="avandwp-table">
						<thead>
							<tr>
								<th>امتیاز</th>
								<th>پرسش کاربر</th>
								<th>پاسخ ربات</th>
								<th>تبدیل به FAQ</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $feedback_list as $fb ) : ?>
								<tr>
									<td>
										<span class="avandwp-badge avandwp-badge-<?php echo 'up' === $fb['rating'] ? 'success' : 'danger'; ?>">
											<?php echo 'up' === $fb['rating'] ? 'مفید (👍)' : 'نیازمند اصلاح (👎)'; ?>
										</span>
									</td>
									<td><?php echo esc_html( $fb['question'] ); ?></td>
									<td><?php echo esc_html( wp_trim_words( $fb['answer'], 18 ) ); ?></td>
									<td>
										<button type="button" class="avandwp-btn avandwp-btn-secondary avandwp-btn-sm avandwp-promote-faq-btn"
										        data-q="<?php echo esc_attr( $fb['question'] ); ?>"
										        data-a="<?php echo esc_attr( $fb['answer'] ); ?>">
											افزودن به FAQ
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

	<!-- TAB 3: Chat Widget Settings -->
	<?php if ( current_user_can( 'manage_options' ) ) : ?>
		<div id="tab-chat-settings" class="avandwp-tab-panel" hidden>
			<div class="avandwp-card">
				<div class="avandwp-card-header">
					<h2>تنظیمات ویجت چت‌بات فرانت‌اند</h2>
					<code>شرت‌کد درون‌خطی: [avandwp_chat]</code>
				</div>
				<form id="avandwp-chat-settings-form" class="avandwp-form">
					<div class="avandwp-checkbox-group">
						<label class="avandwp-checkbox">
							<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $chat_settings['enabled'] ) ); ?>>
							<span>نمایش ویجت شناور چت‌بات در تمامی صفحات سایت</span>
						</label>
						<label class="avandwp-checkbox">
							<input type="checkbox" name="enable_knowledge" value="1" <?php checked( ! isset( $chat_settings['enable_knowledge'] ) || ! empty( $chat_settings['enable_knowledge'] ) ); ?>>
							<span>اتصال پاسخ‌ها به پایگاه دانش سایت (نوشته‌ها، محصولات و FAQ) همراه با نمایش لینک منبع</span>
						</label>
						<label class="avandwp-checkbox">
							<input type="checkbox" name="enable_voice_input" value="1" <?php checked( ! empty( $chat_settings['enable_voice_input'] ) ); ?>>
							<span>فعال‌سازی تایپ صوتی فارسی و ارسال پیام صوتی پشتیبانی</span>
						</label>
						<label class="avandwp-checkbox">
							<input type="checkbox" name="enable_handoff" value="1" <?php checked( ! empty( $chat_settings['enable_handoff'] ) ); ?>>
							<span>امکان ثبت تیکت مستقیم برای کارشناس انسانی در داخل چت</span>
						</label>
						<label class="avandwp-checkbox">
							<input type="checkbox" name="load_vazirmatn" value="1" <?php checked( ! empty( $chat_settings['load_vazirmatn'] ) ); ?>>
							<span>بارگذاری فونت وزیرمتن در فرانت‌اند (اگر قالب سایت فونت فارسی استاندارد ندارد فعال کنید)</span>
						</label>
					</div>

					<div class="avandwp-grid avandwp-grid-2">
						<div class="avandwp-field">
							<label for="avandwp-chat-title">عنوان هدر چت‌بات</label>
							<input type="text" id="avandwp-chat-title" name="title" value="<?php echo esc_attr( $chat_settings['title'] ?? 'دستیار هوشمند سایت' ); ?>">
						</div>
						<div class="avandwp-field">
							<label for="avandwp-chat-subtitle">زیرعنوان هدر</label>
							<input type="text" id="avandwp-chat-subtitle" name="subtitle" value="<?php echo esc_attr( $chat_settings['subtitle'] ?? 'پاسخ‌گویی آنی بر اساس اطلاعات سایت' ); ?>">
						</div>
					</div>

					<div class="avandwp-grid avandwp-grid-2">
						<div class="avandwp-field">
							<label for="avandwp-chat-color">رنگ اصلی ویجت</label>
							<input type="color" id="avandwp-chat-color" name="primary_color" value="<?php echo esc_attr( $chat_settings['primary_color'] ?? '#2563EB' ); ?>">
						</div>
						<div class="avandwp-field">
							<label for="avandwp-chat-pos">موقعیت قرارگیری در صفحه</label>
							<select id="avandwp-chat-pos" name="position">
								<option value="bottom-right" <?php selected( $chat_settings['position'] ?? 'bottom-right', 'bottom-right' ); ?>>پایین راست</option>
								<option value="bottom-left" <?php selected( $chat_settings['position'] ?? 'bottom-right', 'bottom-left' ); ?>>پایین چپ</option>
							</select>
						</div>
					</div>

					<div class="avandwp-field">
						<label for="avandwp-chat-welcome">پیام خوش‌آمدگویی اولیه</label>
						<textarea id="avandwp-chat-welcome" name="welcome_message" rows="2"><?php echo esc_textarea( $chat_settings['welcome_message'] ?? '' ); ?></textarea>
					</div>

					<div class="avandwp-field">
						<label for="avandwp-chat-sysprompt">دستورالعمل رفتار و شخصیت دستیار (System Prompt)</label>
						<textarea id="avandwp-chat-sysprompt" name="system_prompt" rows="3"><?php echo esc_textarea( $chat_settings['system_prompt'] ?? '' ); ?></textarea>
					</div>

					<div class="avandwp-form-actions">
						<button type="submit" class="avandwp-btn avandwp-btn-primary">ذخیره تنظیمات چت‌بات</button>
					</div>
				</form>
			</div>
		</div>
	<?php endif; ?>
</div>
