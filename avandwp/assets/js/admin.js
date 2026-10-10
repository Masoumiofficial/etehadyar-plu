/**
 * AvandWP Admin Controller (`assets/js/admin.js`).
 *
 * Strictly sanitizes all dynamic strings with `escHtml()` before DOM insertion
 * to prevent DOM-based XSS vulnerabilities.
 */
(function ($) {
	'use strict';

	/**
	 * Escape HTML entities to prevent XSS in dynamic templates.
	 *
	 * @param {string} str Raw input string.
	 * @return {string} Escaped string.
	 */
	function escHtml(str) {
		if (str === null || str === undefined) {
			return '';
		}
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	/**
	 * Show non-blocking toast notification.
	 */
	function showToast(message, type) {
		var $toast = $('#avandwp-toast');
		if (!$toast.length) {
			return;
		}
		$toast
			.removeClass('is-error is-success')
			.addClass(type === 'error' ? 'is-error' : 'is-success')
			.text(message)
			.prop('hidden', false);

		setTimeout(function () {
			$toast.prop('hidden', true);
		}, 4500);
	}

	/**
	 * Generic AJAX helper with nonce.
	 */
	function postAjax(action, data, $btn) {
		var payload = $.extend({}, data, {
			action: 'avandwp_' + action,
			nonce: AvandWPAdmin.nonce
		});

		var origText = $btn ? $btn.text() : '';
		if ($btn) {
			$btn.prop('disabled', true).text('در حال پردازش...');
		}

		return $.post(AvandWPAdmin.ajaxUrl, payload).always(function () {
			if ($btn) {
				$btn.prop('disabled', false).text(origText);
			}
		});
	}

	/**
	 * Poll a background job until completion.
	 */
	function pollJob(jobId, onComplete, onError) {
		var timer = setInterval(function () {
			$.post(AvandWPAdmin.ajaxUrl, {
				action: 'avandwp_poll_job',
				nonce: AvandWPAdmin.nonce,
				job_id: jobId
			}).done(function (res) {
				if (!res || !res.success || !res.data || !res.data.job) {
					clearInterval(timer);
					if (onError) onError('خطا در دریافت وضعیت صف.');
					return;
				}
				var job = res.data.job;
				if (job.status === 'completed') {
					clearInterval(timer);
					if (onComplete) onComplete(job.result_data || {});
				} else if (job.status === 'failed') {
					clearInterval(timer);
					if (onError) onError(job.error_message || 'خطا در اجرای کار پس‌زمینه.');
				}
			});
		}, 3500);
	}

	/**
	 * Render generated article result card safely.
	 */
	function renderArticleResult(article) {
		var html = '<div class="avandwp-article-summary">';
		html += '<h3>' + escHtml(article.title) + '</h3>';
		html += '<p><span class="avandwp-badge avandwp-badge-success">' + escHtml(article.word_count) + ' کلمه فارسی</span> ';
		html += '<span class="avandwp-badge avandwp-badge-info">مدل: ' + escHtml(article.model || '') + '</span></p>';
		html += '<p><strong>عنوان سئو:</strong> ' + escHtml(article.seo_title) + '</p>';
		html += '<p><strong>توضیحات متا:</strong> ' + escHtml(article.meta_description) + '</p>';

		if (article.image && article.image.url) {
			html += '<p><img src="' + escHtml(article.image.url) + '" alt="' + escHtml(article.title) + '" style="max-width:100%;border-radius:8px;max-height:220px;object-fit:cover;"></p>';
		}
		if (article.audio && article.audio.url) {
			html += '<p><audio controls src="' + escHtml(article.audio.url) + '" style="width:100%;"></audio></p>';
		}

		html += '<div style="display:flex;gap:8px;margin:12px 0;">';
		if (article.edit_url) {
			html += '<a href="' + escHtml(article.edit_url) + '" target="_blank" rel="noopener" class="avandwp-btn avandwp-btn-primary avandwp-btn-sm">ویرایش پیش‌نویس در وردپرس</a>';
		}
		if (article.view_url) {
			html += '<a href="' + escHtml(article.view_url) + '" target="_blank" rel="noopener" class="avandwp-btn avandwp-btn-secondary avandwp-btn-sm">پیش‌نمایش نوشته</a>';
		}
		html += '</div>';
		html += '</div>';

		$('#avandwp-article-result').html(html);
	}

	$(function () {
		// Tab Switching
		$('.avandwp-tab-btn').on('click', function () {
			var $btn = $(this);
			var target = $btn.data('tab-target');
			var $container = $btn.closest('.avandwp-wrap');

			$btn.siblings('.avandwp-tab-btn').removeClass('is-active');
			$btn.addClass('is-active');

			$container.find('.avandwp-tab-panel').removeClass('is-active').prop('hidden', true);
			$('#' + target).addClass('is-active').prop('hidden', false);
		});

		// Dashboard: Process jobs now
		$('#avandwp-run-jobs-btn').on('click', function () {
			var $btn = $(this);
			postAjax('process_jobs_now', {}, $btn).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
					setTimeout(function () { window.location.reload(); }, 1000);
				} else {
					showToast((res.data && res.data.message) || 'خطا', 'error');
				}
			});
		});

		// Studio Tab 1: Generate Article
		$('#avandwp-article-form').on('submit', function (e) {
			e.preventDefault();
			var $form = $(this);
			var $btn = $('#avandwp-article-submit');

			var data = {
				topic: $form.find('[name="topic"]').val(),
				keyword: $form.find('[name="keyword"]').val(),
				tone: $form.find('[name="tone"]').val(),
				target_words: $form.find('[name="target_words"]').val(),
				category_id: $form.find('[name="category_id"]').val(),
				post_status: $form.find('[name="post_status"]').val(),
				use_knowledge: $form.find('[name="use_knowledge"]').is(':checked') ? 1 : 0,
				generate_image: $form.find('[name="generate_image"]').is(':checked') ? 1 : 0,
				generate_audio: $form.find('[name="generate_audio"]').is(':checked') ? 1 : 0,
				background: $form.find('[name="background"]').is(':checked') ? 1 : 0
			};

			postAjax('generate_article', data, $btn).done(function (res) {
				if (!res.success) {
					showToast((res.data && res.data.message) || 'خطا در تولید مقاله', 'error');
					return;
				}
				showToast(res.data.message, 'success');
				if (res.data.queued && res.data.job_id) {
					$('#avandwp-article-result').html(
						'<div class="avandwp-empty-state"><p>در حال تولید مقاله در صف پس‌زمینه (کار #' + escHtml(res.data.job_id) + ')...</p></div>'
					);
					pollJob(
						res.data.job_id,
						function (resultData) {
							showToast('مقاله پس‌زمینه با موفقیت آماده شد.', 'success');
							renderArticleResult(resultData);
						},
						function (errMsg) {
							showToast(errMsg, 'error');
						}
					);
				} else if (res.data.article) {
					renderArticleResult(res.data.article);
				}
			});
		});

		// Studio Tab 2: Generate Image
		$('#avandwp-image-form').on('submit', function (e) {
			e.preventDefault();
			var $form = $(this);
			var $btn = $form.find('button[type="submit"]');
			var data = {
				prompt: $form.find('[name="prompt"]').val(),
				alt_text: $form.find('[name="alt_text"]').val(),
				size: $form.find('[name="size"]').val(),
				provider: $form.find('[name="provider"]').val()
			};

			postAjax('generate_image', data, $btn).done(function (res) {
				if (!res.success) {
					showToast((res.data && res.data.message) || 'خطا در تولید تصویر', 'error');
					return;
				}
				showToast(res.data.message, 'success');
				var img = res.data.image;
				$('#avandwp-image-result').html(
					'<div style="text-align:center;">' +
					'<img src="' + escHtml(img.url) + '" style="max-width:100%;border-radius:10px;margin-bottom:10px;">' +
					'<p><span class="avandwp-badge avandwp-badge-success">ذخیره‌شده در رسانه (#' + escHtml(img.attachment_id) + ')</span></p>' +
					'</div>'
				);
			});
		});

		// Studio Tab 3: Generate TTS Speech
		$('#avandwp-tts-form').on('submit', function (e) {
			e.preventDefault();
			var $form = $(this);
			var $btn = $form.find('button[type="submit"]');
			var data = {
				text: $form.find('[name="text"]').val(),
				voice: $form.find('[name="voice"]').val()
			};

			postAjax('generate_speech', data, $btn).done(function (res) {
				if (!res.success) {
					showToast((res.data && res.data.message) || 'خطا در تولید صوت', 'error');
					return;
				}
				showToast(res.data.message, 'success');
				var audio = res.data.audio;
				$('#avandwp-tts-result').html(
					'<div>' +
					'<audio controls src="' + escHtml(audio.url) + '" style="width:100%;margin-bottom:12px;"></audio>' +
					'<p><input type="text" dir="ltr" readonly value="' + escHtml(audio.url) + '" style="width:100%;"></p>' +
					'</div>'
				);
			});
		});

		// WooCommerce: Select All & Single/Bulk Optimize
		$('#avandwp-woo-select-all').on('change', function () {
			$('.avandwp-woo-item-cb').prop('checked', $(this).is(':checked'));
		});

		$(document).on('click', '.avandwp-woo-opt-single', function () {
			var $btn = $(this);
			var pid = $btn.data('id');
			postAjax('woo_optimize', { product_id: pid }, $btn).done(function (res) {
				if (!res.success) {
					showToast((res.data && res.data.message) || 'خطا در بهینه‌سازی محصول', 'error');
					return;
				}
				showToast(res.data.message, 'success');
				$btn.closest('tr').fadeOut(300);
			});
		});

		$('#avandwp-woo-bulk-btn').on('click', function () {
			var ids = [];
			$('.avandwp-woo-item-cb:checked').each(function () {
				ids.push($(this).val());
			});
			if (!ids.length) {
				showToast('لطفاً حداقل یک محصول را انتخاب کنید.', 'error');
				return;
			}
			postAjax('woo_optimize', { product_ids: ids }, $(this)).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
				} else {
					showToast((res.data && res.data.message) || 'خطا', 'error');
				}
			});
		});

		$('#avandwp-woo-create-form').on('submit', function (e) {
			e.preventDefault();
			var $form = $(this);
			var $btn = $form.find('button[type="submit"]');
			var data = {
				name: $form.find('[name="name"]').val(),
				price: $form.find('[name="price"]').val(),
				brief: $form.find('[name="brief"]').val()
			};

			postAjax('woo_create_draft', data, $btn).done(function (res) {
				if (!res.success) {
					showToast((res.data && res.data.message) || 'خطا در ساخت محصول', 'error');
					return;
				}
				showToast(res.data.message, 'success');
				var p = res.data.product;
				$('#avandwp-woo-create-result').html(
					'<div>' +
					'<h3>' + escHtml(p.title) + '</h3>' +
					'<p><span class="avandwp-badge avandwp-badge-success">' + escHtml(p.word_count) + ' کلمه توضیحات</span></p>' +
					'<p><strong>عنوان سئو:</strong> ' + escHtml(p.seo_title) + '</p>' +
					'<p><a href="' + escHtml(p.edit_url) + '" target="_blank" rel="noopener" class="avandwp-btn avandwp-btn-primary avandwp-btn-sm">مشاهده و ویرایش محصول در ووکامرس</a></p>' +
					'</div>'
				);
			});
		});

		// Support Desk: Save Ticket Reply & Status
		$(document).on('click', '.avandwp-save-ticket-btn', function () {
			var $btn = $(this);
			var $box = $btn.closest('.avandwp-ticket-action-box');
			var data = {
				ticket_id: $box.data('ticket-id'),
				status: $box.find('.avandwp-ticket-status').val(),
				admin_reply: $box.find('.avandwp-ticket-reply').val()
			};
			postAjax('update_ticket', data, $btn).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
				} else {
					showToast((res.data && res.data.message) || 'خطا', 'error');
				}
			});
		});

		// Support Desk: Add & Save FAQs
		$('#avandwp-add-faq-row').on('click', function () {
			$('#avandwp-faqs-list').append(
				'<div class="avandwp-faq-row">' +
				'<input type="text" name="faq_q[]" placeholder="عنوان پرسش متداول">' +
				'<textarea name="faq_a[]" rows="2" placeholder="پاسخ دقیق و رسمی"></textarea>' +
				'</div>'
			);
		});

		$('.avandwp-promote-faq-btn').on('click', function () {
			var q = $(this).data('q');
			var a = $(this).data('a');
			$('#avandwp-faqs-list').append(
				'<div class="avandwp-faq-row">' +
				'<input type="text" name="faq_q[]" value="' + escHtml(q) + '">' +
				'<textarea name="faq_a[]" rows="2">' + escHtml(a) + '</textarea>' +
				'</div>'
			);
			showToast('پرسش به لیست FAQ اضافه شد؛ پاسخ را بازبینی و ذخیره کنید.', 'success');
		});

		$('#avandwp-faqs-form').on('submit', function (e) {
			e.preventDefault();
			var faqs = [];
			$('#avandwp-faqs-list .avandwp-faq-row').each(function () {
				var q = $(this).find('[name="faq_q[]"]').val();
				var a = $(this).find('[name="faq_a[]"]').val();
				if (q && a) {
					faqs.push({ question: q, answer: a });
				}
			});
			postAjax('save_faqs', { faqs: faqs }, $(this).find('button[type="submit"]')).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
				} else {
					showToast((res.data && res.data.message) || 'خطا', 'error');
				}
			});
		});

		$('#avandwp-sync-kb-btn').on('click', function () {
			postAjax('sync_knowledge', {}, $(this)).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
				} else {
					showToast((res.data && res.data.message) || 'خطا', 'error');
				}
			});
		});

		// Chat Widget Settings
		$('#avandwp-chat-settings-form').on('submit', function (e) {
			e.preventDefault();
			var $form = $(this);
			var data = {
				enabled: $form.find('[name="enabled"]').is(':checked') ? 1 : 0,
				enable_knowledge: $form.find('[name="enable_knowledge"]').is(':checked') ? 1 : 0,
				enable_voice_input: $form.find('[name="enable_voice_input"]').is(':checked') ? 1 : 0,
				enable_handoff: $form.find('[name="enable_handoff"]').is(':checked') ? 1 : 0,
				load_vazirmatn: $form.find('[name="load_vazirmatn"]').is(':checked') ? 1 : 0,
				title: $form.find('[name="title"]').val(),
				subtitle: $form.find('[name="subtitle"]').val(),
				primary_color: $form.find('[name="primary_color"]').val(),
				position: $form.find('[name="position"]').val(),
				welcome_message: $form.find('[name="welcome_message"]').val(),
				system_prompt: $form.find('[name="system_prompt"]').val()
			};
			postAjax('save_chat_settings', data, $form.find('button[type="submit"]')).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
				} else {
					showToast((res.data && res.data.message) || 'خطا', 'error');
				}
			});
		});

		// Telegram Settings, Diagnostics & Publish
		$('#avandwp-telegram-form').on('submit', function (e) {
			e.preventDefault();
			var $form = $(this);
			var data = {
				bot_token: $form.find('[name="bot_token"]').val(),
				chat_id: $form.find('[name="chat_id"]').val(),
				proxy_url: $form.find('[name="proxy_url"]').val(),
				auto_publish_posts: $form.find('[name="auto_publish_posts"]').is(':checked') ? 1 : 0,
				notify_woo_orders: $form.find('[name="notify_woo_orders"]').is(':checked') ? 1 : 0,
				notify_support: $form.find('[name="notify_support"]').is(':checked') ? 1 : 0
			};
			postAjax('save_telegram', data, $form.find('button[type="submit"]')).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
				} else {
					showToast((res.data && res.data.message) || 'خطا', 'error');
				}
			});
		});

		$('#avandwp-test-tg-btn').on('click', function () {
			postAjax('test_telegram', { send_test_message: 1 }, $(this)).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
					var d = res.data.diagnostics || {};
					$('#avandwp-tg-diag-result').html(
						'<div class="avandwp-badge avandwp-badge-success">ربات @' + escHtml(d.bot_username || '') + ' تأیید شد.</div>'
					);
				} else {
					showToast((res.data && res.data.message) || 'خطا در اتصال تلگرام', 'error');
				}
			});
		});

		$('#avandwp-tg-publish-form').on('submit', function (e) {
			e.preventDefault();
			var postId = $(this).find('[name="post_id"]').val();
			postAjax('publish_post_telegram', { post_id: postId }, $(this).find('button[type="submit"]')).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
				} else {
					showToast((res.data && res.data.message) || 'خطا', 'error');
				}
			});
		});

		// Non-destructive Content Auditor
		$('#avandwp-run-audit-btn').on('click', function () {
			postAjax('run_content_audit', {}, $(this)).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
					setTimeout(function () { window.location.reload(); }, 900);
				} else {
					showToast((res.data && res.data.message) || 'خطا', 'error');
				}
			});
		});

		$(document).on('click', '.avandwp-fix-seo-btn', function () {
			var $btn = $(this);
			var postId = $btn.data('post-id');
			postAjax('fix_post_seo', { post_id: postId }, $btn).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
					$btn.replaceWith('<span class="avandwp-badge avandwp-badge-success">تکمیل شد</span>');
				} else {
					showToast((res.data && res.data.message) || 'خطا', 'error');
				}
			});
		});

		// Settings & Vault
		$('#avandwp-settings-form').on('submit', function (e) {
			e.preventDefault();
			var $form = $(this);
			var serialized = $form.serializeArray();
			var data = {};
			$.each(serialized, function (_, field) {
				data[field.name] = field.value;
			});
			postAjax('save_settings', data, $form.find('button[type="submit"]')).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
				} else {
					showToast((res.data && res.data.message) || 'خطا', 'error');
				}
			});
		});

		$('.avandwp-test-provider-btn').on('click', function () {
			var $btn = $(this);
			var provider = $btn.data('provider');
			postAjax('test_provider', { provider: provider }, $btn).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
				} else {
					showToast((res.data && res.data.message) || 'خطا در اتصال', 'error');
				}
			});
		});

		$('#avandwp-repair-btn').on('click', function () {
			postAjax('repair_system', {}, $(this)).done(function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
					setTimeout(function () { window.location.reload(); }, 900);
				} else {
					showToast((res.data && res.data.message) || 'خطا', 'error');
				}
			});
		});
	});
})(jQuery);
