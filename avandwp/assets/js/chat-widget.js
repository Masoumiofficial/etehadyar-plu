/**
 * AvandWP Frontend Chat & Voice Widget (`assets/js/chat-widget.js`).
 *
 * Features:
 * - Grounded AI chat with verified clickable `sources` citations.
 * - Persian Web Speech API (`fa-IR`) voice input.
 * - Thumbs up/down response feedback (`/avandwp/v1/feedback`).
 * - Direct human support ticket handoff (`/avandwp/v1/support`).
 * - Strictly escapes all dynamic strings with `escHtml()` (zero DOM XSS).
 * - Never fabricates simulated/mock answers on error.
 */
(function () {
	'use strict';

	if (typeof window.AvandWPChatConfig === 'undefined') {
		return;
	}

	var cfg = window.AvandWPChatConfig;
	var sessionId = 'avand_' + Math.random().toString(36).substring(2, 12);
	var chatHistory = [];

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

	function initWidget() {
		var root = document.getElementById('avandwp-chat-root');
		if (!root) {
			return;
		}

		root.style.setProperty('--avandwp-chat-color', cfg.primaryColor || '#2563EB');
		var posClass = cfg.position === 'bottom-left' ? 'is-left' : '';

		var html = '';
		html += '<button type="button" class="avandwp-chat-launcher ' + posClass + '" id="avandwp-launcher-btn">';
		html += '<span>💬 ' + escHtml(cfg.title) + '</span>';
		html += '</button>';

		html += '<div class="avandwp-chat-window ' + posClass + '" id="avandwp-chat-win" hidden>';
		html += '  <div class="avandwp-chat-header">';
		html += '    <div>';
		html += '      <h3 class="avandwp-chat-header-title">' + escHtml(cfg.title) + '</h3>';
		html += '      <p class="avandwp-chat-header-sub">' + escHtml(cfg.subtitle) + '</p>';
		html += '    </div>';
		html += '    <button type="button" class="avandwp-chat-close" id="avandwp-close-btn" aria-label="بستن">×</button>';
		html += '  </div>';

		html += '  <div class="avandwp-chat-messages" id="avandwp-messages">';
		html += '    <div class="avandwp-msg avandwp-msg-bot">' + escHtml(cfg.welcomeMessage) + '</div>';
		html += '  </div>';

		if (cfg.enableHandoff) {
			html += '  <div class="avandwp-ticket-panel" id="avandwp-ticket-panel" hidden>';
			html += '    <strong style="font-size:12px;">ثبت درخواست برای کارشناس پشتیبانی</strong>';
			html += '    <input type="text" id="avandwp-tk-name" placeholder="نام شما">';
			html += '    <input type="text" id="avandwp-tk-contact" placeholder="شماره موبایل یا ایمیل (الزامی)">';
			html += '    <textarea id="avandwp-tk-msg" rows="2" placeholder="شرح درخواست یا سؤال شما..."></textarea>';
			html += '    <div style="display:flex;gap:6px;">';
			html += '      <button type="button" class="avandwp-chat-send-btn" id="avandwp-tk-submit">ارسال تیکت</button>';
			html += '      <button type="button" class="avandwp-chat-mic-btn" id="avandwp-tk-cancel">انصراف</button>';
			html += '    </div>';
			html += '  </div>';
		}

		html += '  <div class="avandwp-chat-footer">';
		html += '    <form class="avandwp-chat-input-row" id="avandwp-chat-form">';
		if (cfg.enableVoiceInput) {
			html += '      <button type="button" class="avandwp-chat-mic-btn" id="avandwp-mic-btn" title="تایپ صوتی فارسی">🎤</button>';
		}
		html += '      <input type="text" class="avandwp-chat-input" id="avandwp-chat-input" placeholder="پرسش خود را بنویسید..." autocomplete="off">';
		html += '      <button type="submit" class="avandwp-chat-send-btn" id="avandwp-send-btn">ارسال</button>';
		html += '    </form>';
		if (cfg.enableHandoff) {
			html += '    <div class="avandwp-chat-handoff-bar">';
			html += '      <button type="button" class="avandwp-handoff-toggle" id="avandwp-open-ticket-btn">نیاز به راهنمایی همکار انسانی دارید؟ ثبت تیکت پشتیبانی</button>';
			html += '    </div>';
		}
		html += '  </div>';
		html += '</div>';

		root.innerHTML = html;
		bindEvents();
	}

	function appendMessage(role, text, sources, logId, originalQuestion) {
		var box = document.getElementById('avandwp-messages');
		if (!box) return;

		var div = document.createElement('div');
		div.className = 'avandwp-msg ' + (role === 'user' ? 'avandwp-msg-user' : 'avandwp-msg-bot');

		var inner = '<div>' + escHtml(text) + '</div>';

		if (role === 'bot' && Array.isArray(sources) && sources.length > 0) {
			inner += '<div class="avandwp-msg-sources">منابع: ';
			for (var i = 0; i < sources.length; i++) {
				var s = sources[i];
				if (s && s.url && s.title) {
					inner += '<a href="' + escHtml(s.url) + '" target="_blank" rel="noopener">🔗 ' + escHtml(s.title) + '</a> ';
				}
			}
			inner += '</div>';
		}

		if (role === 'bot' && logId) {
			inner += '<div class="avandwp-msg-feedback" data-log-id="' + escHtml(logId) + '" data-q="' + escHtml(originalQuestion || '') + '" data-a="' + escHtml(text) + '">';
			inner += '<button type="button" class="avandwp-fb-btn" data-rating="up">👍 مفید بود</button>';
			inner += '<button type="button" class="avandwp-fb-btn" data-rating="down">👎 نیاز به بهبود</button>';
			inner += '</div>';
		}

		div.innerHTML = inner;
		box.appendChild(div);
		box.scrollTop = box.scrollHeight;
	}

	function bindEvents() {
		var launcher = document.getElementById('avandwp-launcher-btn');
		var win = document.getElementById('avandwp-chat-win');
		var closeBtn = document.getElementById('avandwp-close-btn');
		var form = document.getElementById('avandwp-chat-form');
		var input = document.getElementById('avandwp-chat-input');
		var sendBtn = document.getElementById('avandwp-send-btn');

		launcher.addEventListener('click', function () {
			win.hidden = !win.hidden;
			if (!win.hidden) {
				input.focus();
			}
		});

		closeBtn.addEventListener('click', function () {
			win.hidden = true;
		});

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var text = input.value.trim();
			if (!text) return;

			input.value = '';
			appendMessage('user', text);
			sendBtn.disabled = true;

			fetch(cfg.restUrl + '/chat', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce
				},
				body: JSON.stringify({
					message: text,
					session_id: sessionId,
					history: chatHistory.slice(-4)
				})
			})
				.then(function (r) { return r.json(); })
				.then(function (data) {
					sendBtn.disabled = false;
					if (data && data.success && data.reply) {
						appendMessage('bot', data.reply, data.sources || [], data.log_id || 0, text);
						chatHistory.push({ role: 'user', content: text });
						chatHistory.push({ role: 'assistant', content: data.reply });
					} else {
						var errMsg = (data && data.message) ? data.message : 'در حال حاضر ارتباط با سرویس پاسخ‌گویی برقرار نشد. لطفاً از بخش ثبت تیکت پشتیبانی استفاده فرمایید.';
						appendMessage('bot', errMsg);
					}
				})
				.catch(function () {
					sendBtn.disabled = false;
					appendMessage('bot', 'خطا در برقراری ارتباط شبکه. لطفاً در صورت نیاز درخواست خود را به صورت تیکت ثبت کنید.');
				});
		});

		// Feedback clicks
		document.getElementById('avandwp-messages').addEventListener('click', function (e) {
			var btn = e.target.closest('.avandwp-fb-btn');
			if (!btn) return;
			var wrap = btn.closest('.avandwp-msg-feedback');
			if (!wrap) return;

			fetch(cfg.restUrl + '/feedback', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': cfg.nonce
				},
				body: JSON.stringify({
					log_id: wrap.getAttribute('data-log-id'),
					session_id: sessionId,
					rating: btn.getAttribute('data-rating'),
					question: wrap.getAttribute('data-q'),
					answer: wrap.getAttribute('data-a')
				})
			});

			wrap.innerHTML = '<small style="color:#15803D;">از ثبت بازخورد شما سپاسگزاریم.</small>';
		});

		// Support ticket handoff
		var openTkBtn = document.getElementById('avandwp-open-ticket-btn');
		var tkPanel = document.getElementById('avandwp-ticket-panel');
		if (openTkBtn && tkPanel) {
			openTkBtn.addEventListener('click', function () {
				tkPanel.hidden = !tkPanel.hidden;
			});
			document.getElementById('avandwp-tk-cancel').addEventListener('click', function () {
				tkPanel.hidden = true;
			});
			document.getElementById('avandwp-tk-submit').addEventListener('click', function () {
				var name = document.getElementById('avandwp-tk-name').value.trim();
				var contact = document.getElementById('avandwp-tk-contact').value.trim();
				var msg = document.getElementById('avandwp-tk-msg').value.trim();

				if (!contact || !msg) {
					appendMessage('bot', 'لطفاً راه ارتباطی (شماره تماس یا ایمیل) و متن پیام خود را وارد کنید.');
					return;
				}

				fetch(cfg.restUrl + '/support', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': cfg.nonce
					},
					body: JSON.stringify({
						session_id: sessionId,
						user_name: name,
						user_contact: contact,
						message: msg,
						page_url: window.location.href
					})
				})
					.then(function (r) { return r.json(); })
					.then(function (res) {
						tkPanel.hidden = true;
						document.getElementById('avandwp-tk-msg').value = '';
						appendMessage('bot', (res && res.message) ? res.message : 'تیکت شما ثبت شد.');
					});
			});
		}

		// Web Speech API Persian voice recognition
		var micBtn = document.getElementById('avandwp-mic-btn');
		var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
		if (micBtn && SpeechRecognition) {
			var recognition = new SpeechRecognition();
			recognition.lang = 'fa-IR';
			recognition.interimResults = false;

			micBtn.addEventListener('click', function () {
				try {
					micBtn.classList.add('is-listening');
					recognition.start();
				} catch (err) {
					micBtn.classList.remove('is-listening');
				}
			});

			recognition.onresult = function (event) {
				micBtn.classList.remove('is-listening');
				if (event.results && event.results[0] && event.results[0][0]) {
					input.value = event.results[0][0].transcript;
					input.focus();
				}
			};

			recognition.onerror = function () {
				micBtn.classList.remove('is-listening');
			};
			recognition.onend = function () {
				micBtn.classList.remove('is-listening');
			};
		} else if (micBtn) {
			micBtn.style.display = 'none';
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initWidget);
	} else {
		initWidget();
	}
})();
