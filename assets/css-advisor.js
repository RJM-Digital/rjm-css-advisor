/* global rjmCssAdvisor, jQuery */
/**
 * RJM CSS Advisor — admin JavaScript.
 *
 * Two-step CSS generation flow:
 *  1. User clicks "Generate Custom CSS ✨" → goal-entry form appears
 *  2. User describes their goal → clicks "Generate CSS ✨" → AI generates CSS
 *  3. Generated CSS is shown in a code block with Copy and Insert buttons
 *  4. "Try again" returns to the goal form (pre-filled); "Close" hides everything
 */

(function ($) {
	'use strict';

	var cfg = rjmCssAdvisor || {};

	// -------------------------------------------------------------------------
	// Initialisation
	// -------------------------------------------------------------------------

	$(document).ready(function () {
		$(document).on('click',   '.rjm-css-advisor-btn',      onOpenClick);
		$(document).on('click',   '.rjm-css-generate-btn',     onGenerateClick);
		$(document).on('click',   '.rjm-css-plan-generate-btn', onPlanGenerateClick);
		$(document).on('click',   '.rjm-css-plan-stop-btn',    onPlanStopClick);
		$(document).on('click',   '.rjm-css-build-action',     onBuildActionClick);
		$(document).on('change',  '.rjm-css-mode-input',       onModeChange);
		$(document).on('click',   '.rjm-css-advisor-tryagain', onTryAgainClick);
		$(document).on('click',   '.rjm-css-advisor-close',    onCloseClick);
		$(document).on('click',   '.rjm-copy-btn',             onCopyClick);
		$(document).on('click',   '.rjm-chat-code-copy',       onChatCodeCopyClick);
		$(document).on('click',   '.rjm-insert-btn',           onInsertClick);
		$(document).on('change',  '.rjm-global-checkbox',      onGlobalToggleChange);
		// Ctrl/Cmd + Enter inside the goal textarea submits the form.
		$(document).on('keydown', '.rjm-css-goal-input',       onGoalKeydown);
		$(document).on('paste',   '.rjm-css-goal-input',       onGoalPaste);
		$(document).on('click',   '.rjm-css-screenshot-upload-btn', onScreenshotUploadClick);
		$(document).on('change',  '.rjm-css-screenshot-input', onScreenshotInputChange);
		$(document).on('click',   '.rjm-css-screenshot-remove', onScreenshotRemoveClick);
		$(document).on('click',   '.rjm-css-screenshot-clear', onScreenshotClearClick);
		$(document).on('click',   '.rjm-css-fullscreen-btn',   onFullscreenClick);
		$(document).on('click',   '.rjm-css-history-btn',     onHistoryToggleClick);
		$(document).on('click',   '.rjm-css-history-new',     onHistoryNewClick);
		$(document).on('click',   '.rjm-css-history-open',    onHistoryOpenClick);
		$(document).on('click',   '.rjm-css-history-rename',  onHistoryRenameClick);
		$(document).on('click',   '.rjm-css-history-delete',  onHistoryDeleteClick);
		$(document).on('click',   '.rjm-css-history-clear',   onHistoryClearClick);
		$(document).on('click',   '.rjm-css-example-chip',     onExampleChipClick);
		$(document).on('click',   '.rjm-css-menu-btn',         onMenuButtonClick);
		$(document).on('click',   '.rjm-css-menu-popover',     function (e) { e.stopPropagation(); });
		$(document).on('change',  '.rjm-css-breakpoint-input', onBreakpointChange);
		$(document).on('input',   '.rjm-css-goal-input',       onGoalInput);
		$(document).on('click',   closeAllMenus);
		$(document).on('keydown', onDocumentKeydown);
	});

	// -------------------------------------------------------------------------
	// Step 1 — open panel / toggle
	// -------------------------------------------------------------------------

	function onOpenClick(e) {
		e.preventDefault();
		var $wrap  = $(this).closest('.rjm-css-advisor-wrap');
		var $panel = getPanelFromWrap($wrap);

		// Toggle: if the panel is already visible, close it.
		if (!$panel.attr('hidden')) {
			closePanel($wrap, $panel);
			return;
		}

		showGoalForm($wrap, $panel);
	}

	// -------------------------------------------------------------------------
	// Step 2 — generate CSS
	// -------------------------------------------------------------------------

	function onGenerateClick(e) {
		e.preventDefault();
		var $wrap  = $(this).closest('.rjm-css-advisor-wrap');
		var $panel = getPanelFromWrap($wrap);
		var mode   = getSelectedMode($panel);
		var goal   = $panel.find('.rjm-css-goal-input').val().trim();
		var breakpoints = getSelectedBreakpoints($panel);

		if (!goal) {
			$panel.find('.rjm-css-goal-input').focus();
			return;
		}

		if (mode === 'ask') {
			sendPlanMessage($wrap, $panel, goal, breakpoints);
			return;
		}

		if (mode === 'build') {
			startBuildFlow($wrap, $panel, goal, breakpoints);
			return;
		}

		generateCSS($wrap, $panel, goal, breakpoints);
	}

	function onPlanGenerateClick(e) {
		e.preventDefault();
		var $wrap = $(this).closest('.rjm-css-advisor-wrap');
		var $panel = getPanelFromWrap($wrap);
		generateFromPlan($wrap, $panel);
	}

	function onBuildActionClick(e) {
		e.preventDefault();
		var $wrap = $(this).closest('.rjm-css-advisor-wrap');
		var $panel = getPanelFromWrap($wrap);
		var decision = $(this).data('decision') || '';
		continueBuildFlow($wrap, $panel, decision);
	}

	function onModeChange(e) {
		var $panel = $(e.target).closest('.rjm-css-advisor-panel');
		closeAllMenus();
		resetModeState($panel);
		updateModeUI($panel);
	}

	function onGoalKeydown(e) {
		// Enter sends, Shift+Enter inserts a newline. Ctrl/Cmd+Enter always sends.
		if (e.key !== 'Enter') {
			return;
		}

		if (e.shiftKey && !e.ctrlKey && !e.metaKey) {
			return;
		}

		e.preventDefault();
		$(this).closest('.rjm-css-advisor-wrap').find('.rjm-css-generate-btn').trigger('click');
	}

	function onGoalInput() {
		autoGrowTextarea(this);
	}

	// Grow the composer with its content, up to a scrollable cap.
	function autoGrowTextarea(el) {
		if (!el || !el.offsetParent) {
			return;
		}
		el.style.height = 'auto';
		el.style.height = Math.min(el.scrollHeight, 200) + 'px';
	}

	function onGoalPaste(e) {
		var clipboard = e.originalEvent && e.originalEvent.clipboardData;
		if (!clipboard || !clipboard.items) {
			return;
		}

		var files = [];
		for (var i = 0; i < clipboard.items.length; i++) {
			var item = clipboard.items[i];
			if (item.kind === 'file' && item.type.indexOf('image/') === 0) {
				files.push(item.getAsFile());
			}
		}
		if (files.length) {
			e.preventDefault();
			addPendingScreenshots($(this).closest('.rjm-css-advisor-panel'), files);
		}
	}

	function onScreenshotUploadClick(e) {
		e.preventDefault();
		$(this).siblings('.rjm-css-screenshot-input').trigger('click');
	}

	function onScreenshotInputChange() {
		var $input = $(this);
		addPendingScreenshots($input.closest('.rjm-css-advisor-panel'), Array.prototype.slice.call(this.files || []));
		$input.val('');
	}

	function onScreenshotRemoveClick(e) {
		e.preventDefault();
		removePendingScreenshot($(this).closest('.rjm-css-advisor-panel'), $(this).data('screenshotId'));
	}

	function onScreenshotClearClick(e) {
		e.preventDefault();
		clearPendingScreenshot($(this).closest('.rjm-css-advisor-panel'));
	}

	function addPendingScreenshots($panel, files) {
		var pending = $panel.data('pendingScreenshots') || [];
		var maxCount = 5;
		var maxTotalBytes = 20 * 1024 * 1024;
		var maxBytes = 4 * 1024 * 1024;
		var allowed = [ 'image/png', 'image/jpeg', 'image/webp' ];
		var rejected = [];
		var pendingCount = pending.length;
		var pendingBytes = pending.reduce(function (total, item) { return total + item.size; }, 0);
		var seen = {};
		pending.forEach(function (item) {
			seen[item.name + '|' + item.size + '|' + item.type] = true;
		});
		(files || []).forEach(function (file) {
			var name = file.name || 'screenshot';
			var key = name + '|' + file.size + '|' + file.type;
			if (allowed.indexOf(file.type) === -1 || file.size > maxBytes || seen[key] || pendingCount >= maxCount || pendingBytes + file.size > maxTotalBytes) {
				rejected.push(name);
				return;
			}
			seen[key] = true;
			pendingCount++;
			pendingBytes += file.size;

			var reader = new FileReader();
			reader.onload = function () {
				var current = $panel.data('pendingScreenshots') || [];
				current.push({
					id: 'screenshot-' + Date.now() + '-' + Math.random().toString(36).slice(2),
					data: String(reader.result || ''),
					name: name,
					size: file.size,
					type: file.type,
				});
				$panel.data('pendingScreenshots', current);
				renderScreenshotPreview($panel);
			};
			reader.onerror = function () {
				showScreenshotError($panel, cfg.i18n.screenshotInvalid || 'Unable to read that image.');
			};
			reader.readAsDataURL(file);
		});
		if (rejected.length) {
			showScreenshotError($panel, (cfg.i18n.screenshotLimit || 'Some screenshots could not be attached.') + ' ' + rejected.join(', '));
		}
	}

	function renderScreenshotPreview($panel) {
		var screenshots = $panel.data('pendingScreenshots') || [];
		var $preview = $panel.find('.rjm-css-screenshot-preview');
		var $clear = $panel.find('.rjm-css-screenshot-clear');
		if (!screenshots.length) {
			$preview.attr('hidden', true).empty();
			$clear.attr('hidden', true);
			return;
		}
		var html = screenshots.map(function (screenshot) {
			return '<div class="rjm-css-screenshot-item">' +
				'<img src="' + escHtml(screenshot.data) + '" alt="" />' +
				'<span>' + escHtml(screenshot.name) + '</span>' +
				'<button type="button" class="button-link rjm-css-screenshot-remove" data-screenshot-id="' + escHtml(screenshot.id) + '">' +
				escHtml(cfg.i18n.screenshotRemove || 'Remove screenshot') + '</button></div>';
		}).join('');
		var totalBytes = screenshots.reduce(function (total, item) { return total + item.size; }, 0);
		$preview.html(html + '<span class="rjm-css-screenshot-count">' +
			escHtml(formatScreenshotCount(screenshots.length, totalBytes)) + '</span>').removeAttr('hidden');
		$clear.removeAttr('hidden');
	}

	function showScreenshotError($panel, message) {
		$panel.find('.rjm-css-screenshot-error').text(message).removeAttr('hidden');
	}

	function clearPendingScreenshot($panel) {
		$panel.removeData('pendingScreenshots');
		renderScreenshotPreview($panel);
		$panel.find('.rjm-css-screenshot-error').attr('hidden', true).text('');
	}

	function removePendingScreenshot($panel, id) {
		var screenshots = ($panel.data('pendingScreenshots') || []).filter(function (screenshot) {
			return screenshot.id !== id;
		});
		$panel.data('pendingScreenshots', screenshots);
		renderScreenshotPreview($panel);
	}

	function formatScreenshotCount(count, bytes) {
		var label = cfg.i18n.screenshotCount || '%1$d screenshots, %2$s total';
		return label.replace('%1$d', count).replace('%2$s', formatBytes(bytes));
	}

	function formatBytes(bytes) {
		if (bytes < 1024 * 1024) {
			return Math.max(1, Math.round(bytes / 1024)) + ' KB';
		}
		return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
	}

	function generateCSS($wrap, $panel, goal, breakpoints) {
		var reqCtx = collectRequestContext($wrap);

		var $loading  = $panel.find('.rjm-css-advisor-loading');
		var $content  = $panel.find('.rjm-css-advisor-content');
		var $actions  = $panel.find('.rjm-css-advisor-actions');

		// Normalise the AJAX URL to the current page's origin so requests are
		// not blocked by CORS when the WordPress siteurl option differs from the
		// actual served hostname (headless / proxy setup).
		var ajaxUrl = (cfg.ajaxUrl || '').replace(/^https?:\/\/[^\/]+/, window.location.origin);

		// Switch to loading state.
		$loading.removeAttr('hidden');
		$content.html('');
		$actions.attr('hidden', true);

		$.ajax({
			url:  ajaxUrl,
			type: 'POST',
			data: {
				action:    'rjm_generate_css',
				nonce:     cfg.nonce,
				layout:    reqCtx.layoutName,
				field:     reqCtx.fieldName,
				field_key: reqCtx.fieldKey,
				post_id:   reqCtx.postId,
				current_css: reqCtx.currentCss,
				is_global: reqCtx.isGlobal ? '1' : '0',
				goal:      goal,
				breakpoints: breakpoints,
				native_settings: reqCtx.nativeSettings,
			},
			success: function (response) {
				$loading.attr('hidden', true);

				if (response.success) {
					$content.html(response.data.html);
					setResultsPriorityState($panel, true);
				} else {
					var msg = (response.data && response.data.message) ? response.data.message : 'Unknown error.';
					$content.html('<p class="rjm-error">' + escHtml(cfg.i18n.errorPrefix) + escHtml(msg) + '</p>');
					setResultsPriorityState($panel, true);
				}

				$actions.removeAttr('hidden');
			},
			error: function (xhr) {
				$loading.attr('hidden', true);
				$content.html('<p class="rjm-error">' + escHtml(cfg.i18n.errorPrefix) + escHtml(xhr.statusText || 'Request failed') + '</p>');
				setResultsPriorityState($panel, true);
				$actions.removeAttr('hidden');
			},
		});
	}

	function sendPlanMessage($wrap, $panel, message, breakpoints) {
		var reqCtx = collectRequestContext($wrap);
		var screenshots = $panel.data('pendingScreenshots') || [];

		// Chat mode keeps the composer on screen and appends to the transcript.
		setResultsPriorityState($panel, true);
		appendMessageBubble($panel, 'user', message, screenshots);
		$panel.find('.rjm-css-goal-input').val('').focus();
		autoGrowTextarea($panel.find('.rjm-css-goal-input')[0]);
		clearPendingScreenshot($panel);

		var payload = {
			layout: reqCtx.layoutName,
			field: reqCtx.fieldName,
			field_key: reqCtx.fieldKey,
			post_id: reqCtx.postId,
			current_css: reqCtx.currentCss,
			is_global: reqCtx.isGlobal,
			message: message,
			screenshot_data: screenshots.map(function (screenshot) { return screenshot.data; }),
			screenshot_name: screenshots.map(function (screenshot) { return screenshot.name; }),
			session_id: $panel.data('planSessionId') || '',
			breakpoints: breakpoints,
			native_settings: reqCtx.nativeSettings,
		};

		if (!canStream()) {
			sendPlanMessageBlocking($panel, payload);
			return;
		}

		streamPlanMessage($panel, payload);
	}

	function canStream() {
		return Boolean(cfg.streamUrl && window.fetch && window.AbortController && window.ReadableStream && window.TextDecoder);
	}

	/**
	 * Consume the SSE plan stream, appending tokens to a live assistant bubble.
	 */
	function streamPlanMessage($panel, payload) {
		var controller = new AbortController();
		var $thinking = appendThinkingBubble($panel);
		var $bubble = null;
		var text = '';
		var pending = '';
		var frame = null;
		var settled = false;
		var started = false;

		setStreamingState($panel, true, controller);

		function flush() {
			frame = null;
			if (!pending) {
				return;
			}
			text += pending;
			pending = '';
			if (!$bubble) {
				removeThinkingBubble($thinking);
				$bubble = appendMessageBubble($panel, 'assistant', '');
				$bubble.addClass('is-streaming');
			}
			setBubbleContent($bubble, text);
			scrollTranscript($panel);
		}

		function queue(chunk) {
			pending += chunk;
			if (frame === null) {
				frame = window.requestAnimationFrame(flush);
			}
		}

		function finish() {
			if (frame !== null) {
				window.cancelAnimationFrame(frame);
			}
			flush();
			if ($bubble) {
				$bubble.removeClass('is-streaming');
			}
			removeThinkingBubble($thinking);
			setStreamingState($panel, false, null);
		}

		function fail(message) {
			if (settled) {
				return;
			}
			settled = true;
			finish();
			// Nothing rendered yet means the stream never worked — retry over admin-ajax.
			if (!started) {
				if ($bubble) {
					$bubble.remove();
				}
				sendPlanMessageBlocking($panel, payload);
				return;
			}
			appendNoticeBubble($panel, message);
		}

		window.fetch(normalizeUrl(cfg.streamUrl), {
			method: 'POST',
			credentials: 'same-origin',
			signal: controller.signal,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.restNonce || '',
			},
			body: JSON.stringify(payload),
		}).then(function (response) {
			if (!response.ok || !response.body) {
				throw new Error(response.statusText || 'Request failed');
			}

			var reader = response.body.getReader();
			var decoder = new TextDecoder();
			var buffer = '';

			function read() {
				return reader.read().then(function (result) {
					if (result.done) {
						if (!settled) {
							settled = true;
							finish();
						}
						return;
					}

					buffer += decoder.decode(result.value, { stream: true });

					var split;
					while ((split = buffer.indexOf('\n\n')) !== -1) {
						var raw = buffer.slice(0, split);
						buffer = buffer.slice(split + 2);
						var event = parseSseFrame(raw);
						if (!event) {
							continue;
						}

						if (event.name === 'delta' && typeof event.data.text === 'string') {
							started = true;
							queue(event.data.text);
						} else if (event.name === 'open') {
							$panel.data('planSessionId', event.data.session_id || '');
						} else if (event.name === 'done') {
							settled = true;
							$panel.data('planSessionId', event.data.session_id || '');
							$panel.data('planReady', Boolean(event.data.ready_to_generate));
							finish();
							updateModeUI($panel);
							renderPlanReadyNote($panel, event.data.ready_to_generate);
							refreshHistory(getWrapFromPanel($panel), $panel);
							continue;
						} else if (event.name === 'title') {
							syncHistoryTitle($panel, event.data.session_id || '', event.data.chat_title || '');
							continue;
						} else if (event.name === 'error') {
							settled = true;
							fail(event.data.message || 'Request failed');
							return;
						}
					}

					return read();
				});
			}

			return read();
		}).catch(function (error) {
			if (controller.signal.aborted) {
				return;
			}
			fail(error && error.message ? error.message : 'Request failed');
		});
	}

	function parseSseFrame(raw) {
		var name = 'message';
		var data = '';

		raw.split('\n').forEach(function (line) {
			if (line.indexOf('event:') === 0) {
				name = line.slice(6).trim();
			} else if (line.indexOf('data:') === 0) {
				data += line.slice(5).trim();
			}
		});

		if (!data) {
			return null;
		}

		try {
			return { name: name, data: JSON.parse(data) };
		} catch (e) {
			return null;
		}
	}

	function onPlanStopClick(e) {
		e.preventDefault();
		var $panel = $(this).closest('.rjm-css-advisor-panel');
		var controller = $panel.data('planAbort');
		if (controller) {
			controller.abort();
		}
		var $streaming = $panel.find('.rjm-plan-message.is-streaming');
		$streaming.removeClass('is-streaming').addClass('is-stopped');
		removeThinkingBubble($panel.find('.rjm-plan-message.is-thinking'));
		setStreamingState($panel, false, null);
	}

	function setStreamingState($panel, isStreaming, controller) {
		if (controller) {
			$panel.data('planAbort', controller);
		} else {
			$panel.removeData('planAbort');
		}

		$panel.find('.rjm-css-generate-btn').prop('disabled', isStreaming).attr('hidden', isStreaming || null);
		$panel.find('.rjm-css-plan-stop-btn').attr('hidden', isStreaming ? null : true);
	}

	/**
	 * Non-streaming fallback used when SSE is unavailable or fails before any token arrives.
	 */
	function sendPlanMessageBlocking($panel, payload) {
		var $thinking = appendThinkingBubble($panel);

		$.ajax({
			url: normalizeAjaxUrl(),
			type: 'POST',
			data: $.extend({}, payload, {
				action: 'rjm_plan_css_chat',
				nonce: cfg.nonce,
				is_global: payload.is_global ? '1' : '0',
			}),
			success: function (response) {
				removeThinkingBubble($thinking);
				if (!response.success) {
					appendNoticeBubble($panel, (response.data && response.data.message) || 'Unknown error.');
					return;
				}

				var data = response.data || {};
				$panel.data('planSessionId', data.session_id || '');
				$panel.data('planReady', Boolean(data.ready_to_generate));

				var messages = data.messages || [];
				var last = messages.length ? messages[messages.length - 1] : null;
				if (last && last.role === 'assistant') {
					setBubbleContent(appendMessageBubble($panel, 'assistant', ''), last.content || '');
				}

				updateModeUI($panel);
				renderPlanReadyNote($panel, data.ready_to_generate);
				scrollTranscript($panel);
				refreshHistory(getWrapFromPanel($panel), $panel);
			},
			error: function (xhr) {
				removeThinkingBubble($thinking);
				appendNoticeBubble($panel, xhr.statusText || 'Request failed');
			},
		});
	}

	function generateFromPlan($wrap, $panel) {
		var reqCtx = collectRequestContext($wrap);
		var sessionId = $panel.data('planSessionId') || '';
		if (!sessionId) {
			renderError($panel, 'Plan session not found. Send at least one Ask/Plan message first.');
			return;
		}

		var goalTail = $panel.find('.rjm-css-goal-input').val().trim();
		var ajaxUrl = normalizeAjaxUrl();

		setLoadingState($panel, cfg.i18n.generating);

		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: {
				action: 'rjm_plan_css_generate',
				nonce: cfg.nonce,
				session_id: sessionId,
				field_key: reqCtx.fieldKey,
				post_id: reqCtx.postId,
				current_css: reqCtx.currentCss,
				goal: goalTail,
				native_settings: reqCtx.nativeSettings,
			},
			success: function (response) {
				clearLoadingState($panel);
				if (!response.success) {
					renderError($panel, response.data && response.data.message ? response.data.message : 'Unknown error.');
					return;
				}

				$panel.removeData('planSessionId');
				$panel.removeData('planReady');
				$panel.find('.rjm-css-plan-generate-btn').attr('hidden', true);
				$panel.find('.rjm-css-advisor-content').html(response.data.html || '');
				setResultsPriorityState($panel, true);
				$panel.find('.rjm-css-advisor-actions').removeAttr('hidden');
			},
			error: function (xhr) {
				clearLoadingState($panel);
				renderError($panel, xhr.statusText || 'Request failed');
			},
		});
	}

	function startBuildFlow($wrap, $panel, goal, breakpoints) {
		var reqCtx = collectRequestContext($wrap);
		var ajaxUrl = normalizeAjaxUrl();

		setLoadingState($panel, cfg.i18n.building || cfg.i18n.generating);

		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: {
				action: 'rjm_build_css_start',
				nonce: cfg.nonce,
				layout: reqCtx.layoutName,
				field: reqCtx.fieldName,
				field_key: reqCtx.fieldKey,
				post_id: reqCtx.postId,
				current_css: reqCtx.currentCss,
				is_global: reqCtx.isGlobal ? '1' : '0',
				goal: goal,
				breakpoints: breakpoints,
				native_settings: reqCtx.nativeSettings,
			},
			success: function (response) {
				clearLoadingState($panel);
				if (!response.success) {
					renderError($panel, response.data && response.data.message ? response.data.message : 'Unknown error.');
					return;
				}

				$panel.data('buildSessionId', (response.data && response.data.session_id) || '');
				renderBuildStep($panel, response.data.step || {});
			},
			error: function (xhr) {
				clearLoadingState($panel);
				renderError($panel, xhr.statusText || 'Request failed');
			},
		});
	}

	function continueBuildFlow($wrap, $panel, decision) {
		var reqCtx = collectRequestContext($wrap);
		var sessionId = $panel.data('buildSessionId') || '';
		if (!sessionId) {
			renderError($panel, 'Build session not found. Start Build mode first.');
			return;
		}

		var feedback = $panel.find('.rjm-css-goal-input').val().trim();
		var ajaxUrl = normalizeAjaxUrl();

		setLoadingState($panel, cfg.i18n.building || cfg.i18n.generating);

		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: {
				action: 'rjm_build_css_step',
				nonce: cfg.nonce,
				session_id: sessionId,
				field_key: reqCtx.fieldKey,
				post_id: reqCtx.postId,
				current_css: reqCtx.currentCss,
				decision: decision,
				feedback: feedback,
				native_settings: reqCtx.nativeSettings,
			},
			success: function (response) {
				clearLoadingState($panel);
				if (!response.success) {
					renderError($panel, response.data && response.data.message ? response.data.message : 'Unknown error.');
					return;
				}

				if (response.data && response.data.complete) {
					$panel.removeData('buildSessionId');
					$panel.find('.rjm-css-build-actions').attr('hidden', true);
					$panel.find('.rjm-css-advisor-content').html(response.data.html || '');
					setResultsPriorityState($panel, true);
					$panel.find('.rjm-css-advisor-actions').removeAttr('hidden');
					return;
				}

				renderBuildStep($panel, (response.data && response.data.step) || {});
			},
			error: function (xhr) {
				clearLoadingState($panel);
				renderError($panel, xhr.statusText || 'Request failed');
			},
		});
	}

	// -------------------------------------------------------------------------
	// "Try again" — go back to goal form with previous text intact
	// -------------------------------------------------------------------------

	function onTryAgainClick(e) {
		e.preventDefault();
		var $wrap  = $(this).closest('.rjm-css-advisor-wrap');
		var $panel = getPanelFromWrap($wrap);

		$panel.find('.rjm-css-advisor-actions').attr('hidden', true);
		$panel.find('.rjm-css-advisor-content').html('');
		$panel.find('.rjm-css-insert-status').attr('hidden', true).text('');
		$panel.find('.rjm-css-build-actions').attr('hidden', true);
		$panel.find('.rjm-css-plan-generate-btn').attr('hidden', true);
		$panel.removeData('planSessionId').removeData('buildSessionId').removeData('planReady');
		$panel.find('.rjm-css-goal-form').removeAttr('hidden');
		updateModeUI($panel);
		refreshHistory($wrap, $panel);
		$panel.find('.rjm-css-goal-input').focus();
	}

	// -------------------------------------------------------------------------
	// Close
	// -------------------------------------------------------------------------

	function onCloseClick(e) {
		e.preventDefault();
		var $wrap  = $(this).closest('.rjm-css-advisor-wrap');
		var $panel = getPanelFromWrap($wrap);
		closePanel($wrap, $panel);
	}

	function closePanel($wrap, $panel) {
		$panel.attr('hidden', true);
		// Reset panel to initial state so it opens cleanly next time.
		setFullscreen($panel, false);
		closeAllMenus();
		abortPlanStream($panel);
		$panel.find('.rjm-css-goal-form').removeAttr('hidden');
		$panel.find('.rjm-css-advisor-loading').attr('hidden', true);
		$panel.find('.rjm-css-advisor-content').html('');
		$panel.find('.rjm-css-advisor-actions').attr('hidden', true);
		$panel.find('.rjm-css-build-actions').attr('hidden', true);
		$panel.find('.rjm-css-plan-generate-btn').attr('hidden', true);
		$panel.find('.rjm-css-insert-status').attr('hidden', true).text('');
		$panel.removeData('planSessionId').removeData('buildSessionId').removeData('planReady');
		$wrap.find('.rjm-css-advisor-btn').first()
			.removeAttr('hidden')
			.text(cfg.i18n.buttonLabel)
			.attr('aria-expanded', 'false');
	}

	// -------------------------------------------------------------------------
	// Goal-entry form helpers
	// -------------------------------------------------------------------------

	function showGoalForm($wrap, $panel) {
		$panel.removeAttr('hidden');
		$panel.find('.rjm-css-goal-form').removeAttr('hidden');
		$panel.find('.rjm-css-advisor-loading').attr('hidden', true);
		$panel.find('.rjm-css-advisor-content').html('');
		$panel.find('.rjm-css-advisor-actions').attr('hidden', true);
		$panel.find('.rjm-css-build-actions').attr('hidden', true);
		$panel.find('.rjm-css-plan-generate-btn').attr('hidden', true);
		$panel.find('.rjm-css-insert-status').attr('hidden', true).text('');
		abortPlanStream($panel);
		$panel.removeData('planSessionId').removeData('buildSessionId').removeData('planReady');
		$wrap.find('.rjm-css-advisor-btn').first().attr('hidden', true);
		$wrap.find('.rjm-css-advisor-btn').first().attr('aria-expanded', 'true');
		updateModeUI($panel);
		$panel.find('.rjm-css-goal-input').focus();
		autoGrowTextarea($panel.find('.rjm-css-goal-input')[0]);
		openChat($wrap, $panel, '');
	}

	function getSelectedMode($panel) {
		return $panel.find('.rjm-css-mode-input:checked').val() || 'ask';
	}

	// -------------------------------------------------------------------------
	// Composer menus (mode / breakpoints)
	// -------------------------------------------------------------------------

	function onMenuButtonClick(e) {
		e.preventDefault();
		e.stopPropagation();

		var $menu = $(this).closest('.rjm-css-menu');
		var wasOpen = $menu.hasClass('is-open');

		closeAllMenus();
		if (!wasOpen) {
			$menu.addClass('is-open');
			$menu.find('.rjm-css-menu-btn').attr('aria-expanded', 'true');
			$menu.find('.rjm-css-menu-popover').removeAttr('hidden');
		}
	}

	function closeAllMenus() {
		var $open = $('.rjm-css-menu.is-open');
		if (!$open.length) {
			return;
		}
		$open.removeClass('is-open');
		$open.find('.rjm-css-menu-btn').attr('aria-expanded', 'false');
		$open.find('.rjm-css-menu-popover').attr('hidden', true);
	}

	function onBreakpointChange(e) {
		updateBreakpointMenuLabel($(e.target).closest('.rjm-css-advisor-panel'));
	}

	function onDocumentKeydown(e) {
		if (e.key !== 'Escape') {
			return;
		}

		if ($('.rjm-css-menu.is-open').length) {
			closeAllMenus();
			return;
		}

		var $fullscreen = $('.rjm-css-advisor-panel.is-fullscreen');
		if ($fullscreen.length) {
			setFullscreen($fullscreen, false);
		}
	}

	// -------------------------------------------------------------------------
	// Chat history — per-component, persisted server-side
	// -------------------------------------------------------------------------

	function getWrapFromPanel($panel) {
		return $panel.closest('.rjm-css-advisor-wrap');
	}

	function historyRequest($wrap, action, extra, onSuccess) {
		var reqCtx = collectRequestContext($wrap);

		return $.ajax({
			url: normalizeAjaxUrl(),
			type: 'POST',
			data: $.extend({
				action: action,
				nonce: cfg.nonce,
				layout: reqCtx.layoutName,
				field: reqCtx.fieldName,
				field_key: reqCtx.fieldKey,
				post_id: reqCtx.postId,
				is_global: reqCtx.isGlobal ? '1' : '0',
			}, extra || {}),
			success: function (response) {
				if (response && response.success && onSuccess) {
					onSuccess(response.data || {});
				}
			},
		});
	}

	function refreshHistory($wrap, $panel) {
		historyRequest($wrap, 'rjm_css_chat_list', {}, function (data) {
			renderHistoryList($panel, data.chats || []);
		});
	}

	function renderHistoryList($panel, chats) {
		var $list = $panel.find('.rjm-css-history-list').empty();
		var activeId = $panel.data('planSessionId') || '';

		$panel.data('historyChats', chats);
		$panel.find('.rjm-css-history-clear').attr('hidden', chats.length ? null : true);

		if (!chats.length) {
			$list.append($('<li class="rjm-css-history-empty"></li>').text(cfg.i18n.historyEmpty || 'No saved chats yet.'));
			return;
		}

		chats.forEach(function (chat) {
			var $item = $('<li class="rjm-css-history-item"></li>')
				.attr('data-chat-id', chat.id)
				.toggleClass('is-active', chat.id === activeId);

			var $open = $('<button type="button" class="rjm-css-history-open"></button>')
				.attr('title', chat.preview || '')
				.append($('<span class="rjm-css-history-title"></span>').text(chat.title || cfg.i18n.historyUntitled || 'Untitled chat'))
				.append($('<span class="rjm-css-history-meta"></span>').text(timeAgo(chat.updated_at)));

			var $actions = $('<span class="rjm-css-history-row-actions"></span>')
				.append(
					$('<button type="button" class="rjm-css-icon-btn rjm-css-history-rename"></button>')
						.attr({ title: cfg.i18n.historyRename || 'Rename', 'aria-label': cfg.i18n.historyRename || 'Rename' })
						.append($('<span aria-hidden="true"></span>').text('✎'))
				)
				.append(
					$('<button type="button" class="rjm-css-icon-btn rjm-css-history-delete"></button>')
						.attr({ title: cfg.i18n.historyDelete || 'Delete', 'aria-label': cfg.i18n.historyDelete || 'Delete' })
						.append($('<span aria-hidden="true"></span>').text('🗑'))
				);

			$list.append($item.append($open).append($actions));
		});
	}

	/**
	 * Reflect the newly saved title without a full history round trip.
	 */
	function syncHistoryTitle($panel, chatId, title) {
		if (!chatId || !title) {
			return;
		}

		var $item = $panel.find('.rjm-css-history-item[data-chat-id="' + chatId + '"]');
		if ($item.length) {
			$item.find('.rjm-css-history-title').text(title);
			return;
		}

		refreshHistory(getWrapFromPanel($panel), $panel);
	}

	function onHistoryToggleClick(e) {
		e.preventDefault();
		var $panel = $(this).closest('.rjm-css-advisor-panel');
		var isOpen = !$panel.hasClass('is-history-open');

		$panel.toggleClass('is-history-open', isOpen);
		$(this).attr('aria-pressed', isOpen ? 'true' : 'false');

		if (isOpen) {
			refreshHistory(getWrapFromPanel($panel), $panel);
		}
	}

	function onHistoryNewClick(e) {
		e.preventDefault();
		var $panel = $(this).closest('.rjm-css-advisor-panel');
		var $wrap = getWrapFromPanel($panel);

		resetModeState($panel);
		setResultsPriorityState($panel, false);
		updateModeUI($panel);
		$panel.find('.rjm-css-goal-input').focus();
		refreshHistory($wrap, $panel);
	}

	function onHistoryOpenClick(e) {
		e.preventDefault();
		var $panel = $(this).closest('.rjm-css-advisor-panel');
		var chatId = $(this).closest('.rjm-css-history-item').attr('data-chat-id');

		openChat(getWrapFromPanel($panel), $panel, chatId);
	}

	function onHistoryRenameClick(e) {
		e.preventDefault();
		var $panel = $(this).closest('.rjm-css-advisor-panel');
		var $item = $(this).closest('.rjm-css-history-item');
		var chatId = $item.attr('data-chat-id');
		var current = $item.find('.rjm-css-history-title').text();
		var title = window.prompt(cfg.i18n.historyRenamePrompt || 'Chat name:', current);

		if (title === null || !title.trim()) {
			return;
		}

		historyRequest(getWrapFromPanel($panel), 'rjm_css_chat_rename', { chat_id: chatId, title: title.trim() }, function (data) {
			renderHistoryList($panel, data.chats || []);
		});
	}

	function onHistoryDeleteClick(e) {
		e.preventDefault();
		var $panel = $(this).closest('.rjm-css-advisor-panel');
		var chatId = $(this).closest('.rjm-css-history-item').attr('data-chat-id');

		if (!window.confirm(cfg.i18n.historyDeleteConfirm || 'Delete this chat?')) {
			return;
		}

		historyRequest(getWrapFromPanel($panel), 'rjm_css_chat_delete', { chat_id: chatId }, function (data) {
			if (($panel.data('planSessionId') || '') === chatId) {
				resetModeState($panel);
				setResultsPriorityState($panel, false);
				updateModeUI($panel);
			}
			renderHistoryList($panel, data.chats || []);
		});
	}

	function onHistoryClearClick(e) {
		e.preventDefault();
		var $panel = $(this).closest('.rjm-css-advisor-panel');

		if (!window.confirm(cfg.i18n.historyClearConfirm || 'Delete every saved chat for this component?')) {
			return;
		}

		historyRequest(getWrapFromPanel($panel), 'rjm_css_chat_clear', {}, function (data) {
			resetModeState($panel);
			setResultsPriorityState($panel, false);
			updateModeUI($panel);
			renderHistoryList($panel, data.chats || []);
		});
	}

	/**
	 * Load a saved chat into the panel so it can be continued.
	 *
	 * @param {string} chatId Empty string loads the most recent chat.
	 */
	function openChat($wrap, $panel, chatId) {
		historyRequest($wrap, 'rjm_css_chat_open', { chat_id: chatId || '' }, function (data) {
			var chat = data.chat;
			if (!chat) {
				refreshHistory($wrap, $panel);
				return;
			}

			abortPlanStream($panel);
			$panel.find('.rjm-css-advisor-content').html('');
			$panel.data('planSessionId', chat.id);
			$panel.data('planReady', Boolean(chat.ready_to_generate));
			$panel.find('.rjm-css-mode-input[value="ask"]').prop('checked', true);
			applyBreakpoints($panel, chat.breakpoints || []);

			(chat.messages || []).forEach(function (message) {
				var $bubble = appendMessageBubble($panel, message.role, message.content, message.screenshots || []);
				if (message.missing_screenshots) {
					$bubble.append(
						$('<p class="rjm-plan-screenshot-missing"></p>').text(
							(cfg.i18n.historyScreenshotMissing || '%d screenshot(s) from this chat are no longer available.')
								.replace('%d', message.missing_screenshots)
						)
					);
				}
			});

			setResultsPriorityState($panel, true);
			updateModeUI($panel);
			renderPlanReadyNote($panel, chat.ready_to_generate);
			scrollTranscript($panel, true);
			refreshHistory($wrap, $panel);
		});
	}

	function applyBreakpoints($panel, breakpoints) {
		$panel.find('.rjm-css-breakpoint-input').each(function () {
			$(this).prop('checked', breakpoints.indexOf($(this).val()) !== -1);
		});
		updateBreakpointMenuLabel($panel);
	}

	function timeAgo(timestamp) {
		var seconds = Math.max(0, Math.floor(Date.now() / 1000) - Number(timestamp || 0));

		if (seconds < 60) {
			return cfg.i18n.historyJustNow || 'Just now';
		}

		var units = [
			[31536000, 'year'],
			[2592000, 'month'],
			[604800, 'week'],
			[86400, 'day'],
			[3600, 'hour'],
			[60, 'minute'],
		];

		for (var i = 0; i < units.length; i++) {
			if (seconds >= units[i][0]) {
				var value = Math.floor(seconds / units[i][0]);
				var label = value + ' ' + units[i][1] + (value === 1 ? '' : 's');
				return (cfg.i18n.historyAgo || '%s ago').replace('%s', label);
			}
		}

		return cfg.i18n.historyJustNow || 'Just now';
	}

	// -------------------------------------------------------------------------
	// Fullscreen
	// -------------------------------------------------------------------------

	function onFullscreenClick(e) {
		e.preventDefault();
		var $panel = $(this).closest('.rjm-css-advisor-panel');
		setFullscreen($panel, !$panel.hasClass('is-fullscreen'));
	}

	function setFullscreen($panel, isFullscreen) {
		if (isFullscreen) {
			// Only one panel may own the overlay at a time.
			setFullscreen($('.rjm-css-advisor-panel.is-fullscreen').not($panel), false);
			if (!$('.rjm-css-advisor-backdrop').length) {
				$('<div class="rjm-css-advisor-backdrop"></div>').appendTo('body');
			}
			$('body').addClass('rjm-css-advisor-locked');
		} else {
			$('.rjm-css-advisor-backdrop').remove();
			$('body').removeClass('rjm-css-advisor-locked');
		}

		$panel.toggleClass('is-fullscreen', isFullscreen);
		$panel.find('.rjm-css-fullscreen-btn')
			.attr('aria-pressed', isFullscreen ? 'true' : 'false')
			.find('span').text(isFullscreen ? '⤡' : '⤢');

		// The sidebar has no room outside fullscreen.
		if (!isFullscreen) {
			$panel.removeClass('is-history-open');
			$panel.find('.rjm-css-history-btn').attr('aria-pressed', 'false');
		}

		if ($panel.length) {
			scrollTranscript($panel, true);
			$panel.find('.rjm-css-goal-input').focus();
		}
	}

	function collectRequestContext($wrap) {
		var $textarea = getTargetTextarea($wrap);
		return {
			layoutName: detectLayoutName($wrap),
			fieldName: $wrap.data('field') || 'custom_css',
			fieldKey: $wrap.data('field-key') || '',
			isGlobal: $wrap.data('global') === 1 || $wrap.data('global') === '1',
			postId: Number(cfg.postId || 0),
			currentCss: $textarea.length ? String($textarea.val() || '') : '',
			nativeSettings: String($wrap.attr('data-native-settings') || ''),
		};
	}

	function updateModeUI($panel) {
		var mode = getSelectedMode($panel);
		var $button = $panel.find('.rjm-css-generate-btn');
		var isPlanReady = Boolean($panel.data('planReady'));
		var isChat = mode === 'ask';
		var label;

		if (isChat) {
			label = cfg.i18n.sendPlanBtn || 'Send message';
		} else if (mode === 'build') {
			label = cfg.i18n.startBuildBtn || 'Start build';
		} else {
			label = cfg.i18n.generateBtn || 'Generate CSS';
		}

		// The send button holds an icon, so only its accessible name changes.
		$button.attr({ title: label, 'aria-label': label });
		$panel.find('.rjm-css-mode-menu').attr('data-mode', mode);
		$panel.find('.rjm-css-mode-menu .rjm-css-menu-label').text(getModeLabel(mode));
		updateBreakpointMenuLabel($panel);

		// Screenshots are Ask-only — the generate and build endpoints do not accept them.
		$panel.find('.rjm-css-screenshot-controls').attr('hidden', !isChat || null);
		$panel.find('.rjm-css-plan-generate-btn').attr('hidden', (isChat && isPlanReady) ? null : true);

		// In chat mode the header owns New chat / Close, so the mid-panel bar stays hidden.
		if (isChat) {
			$panel.find('.rjm-css-advisor-actions').attr('hidden', true);
		}

		setResultsPriorityState($panel, true);
		renderEmptyState($panel, mode);
	}

	function renderEmptyState($panel, mode) {
		var $content = $panel.find('.rjm-css-advisor-content');
		if (!$content.length || $.trim($content.html()) !== '') {
			return;
		}

		var copy = getEmptyStateCopy(mode);
		var examples = (cfg.i18n && cfg.i18n.examplePrompts) || [];
		var $empty = $('<div class="rjm-css-chat-empty"></div>');

		$empty.append($('<p class="rjm-css-chat-empty-title"></p>').text(copy.title));
		$empty.append($('<p class="rjm-css-chat-empty-hint"></p>').text(copy.hint));

		if (examples.length) {
			var $chips = $('<div class="rjm-css-chat-examples"></div>');
			examples.forEach(function (example) {
				$('<button type="button" class="rjm-css-example-chip"></button>').text(example).appendTo($chips);
			});
			$empty.append($chips);
		}

		$content.append($empty);
	}

	function getEmptyStateCopy(mode) {
		if (mode === 'generate') {
			return {
				title: cfg.i18n.emptyTitleGenerate || 'Describe the CSS you want',
				hint: cfg.i18n.emptyHintGenerate || 'Write one clear instruction and the CSS is generated in a single pass.',
			};
		}

		if (mode === 'build') {
			return {
				title: cfg.i18n.emptyTitleBuild || 'Describe what to build',
				hint: cfg.i18n.emptyHintBuild || 'The work is split into small steps you can approve, revise, or skip.',
			};
		}

		return {
			title: cfg.i18n.emptyTitle || 'Describe the styling you want',
			hint: cfg.i18n.emptyHint || 'Ask questions and refine the plan before generating CSS.',
		};
	}

	function onExampleChipClick(e) {
		e.preventDefault();
		var $panel = $(this).closest('.rjm-css-advisor-panel');
		var $input = $panel.find('.rjm-css-goal-input');
		$input.val($(this).text()).focus();
		autoGrowTextarea($input[0]);
	}

	function getModeLabel(mode) {
		if (mode === 'ask') {
			return cfg.i18n.modeAsk || 'Ask/Plan';
		}
		if (mode === 'build') {
			return cfg.i18n.modeBuild || 'Build';
		}
		return cfg.i18n.modeGenerate || 'Generate';
	}

	function updateBreakpointMenuLabel($panel) {
		var selected = getSelectedBreakpoints($panel);
		var names = { mobile: cfg.i18n.breakpointMobile || 'Mobile', tablet: cfg.i18n.breakpointTablet || 'Tablet', desktop: cfg.i18n.breakpointDesktop || 'Desktop' };
		var label = selected.length
			? selected.map(function (key) { return names[key] || key; }).join(', ')
			: (cfg.i18n.breakpointsAll || 'All breakpoints');

		$panel.find('.rjm-css-breakpoint-menu .rjm-css-menu-label').text(label);
	}

	function resetModeState($panel) {
		abortPlanStream($panel);
		$panel.find('.rjm-css-advisor-content').html('');
		$panel.find('.rjm-css-advisor-actions').attr('hidden', true);
		$panel.find('.rjm-css-build-actions').attr('hidden', true);
		$panel.find('.rjm-css-plan-generate-btn').attr('hidden', true);
		$panel.find('.rjm-css-insert-status').attr('hidden', true).text('');
		$panel.removeData('planSessionId').removeData('buildSessionId').removeData('planReady');
	}

	function abortPlanStream($panel) {
		var controller = $panel.data('planAbort');
		if (controller) {
			controller.abort();
		}
		removeThinkingBubble($panel.find('.rjm-plan-message.is-thinking'));
		setStreamingState($panel, false, null);
	}

	function normalizeAjaxUrl() {
		return normalizeUrl(cfg.ajaxUrl);
	}

	// Rewrite to the served origin so a mismatched siteurl option does not trip CORS.
	function normalizeUrl(url) {
		return (url || '').replace(/^https?:\/\/[^\/]+/, window.location.origin);
	}

	function setLoadingState($panel, loadingText) {
		var $loading = $panel.find('.rjm-css-advisor-loading');
		var $content = $panel.find('.rjm-css-advisor-content');
		var $actions = $panel.find('.rjm-css-advisor-actions');

		$loading.html('<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>' + escHtml(loadingText || cfg.i18n.generating || 'Generating CSS…')).removeAttr('hidden');
		$content.html('');
		$actions.attr('hidden', true);
		$panel.find('.rjm-css-build-actions').attr('hidden', true);
	}

	function clearLoadingState($panel) {
		$panel.find('.rjm-css-advisor-loading').attr('hidden', true);
	}

	function renderError($panel, message) {
		$panel.find('.rjm-css-advisor-content').html('<p class="rjm-error">' + escHtml(cfg.i18n.errorPrefix) + escHtml(message) + '</p>');
		setResultsPriorityState($panel, true);
		$panel.find('.rjm-css-advisor-actions').removeAttr('hidden');
	}

	// -------------------------------------------------------------------------
	// Ask/Plan transcript — owned by the client so messages can stream in
	// -------------------------------------------------------------------------

	function ensureTranscript($panel) {
		var $content = $panel.find('.rjm-css-advisor-content');
		var $transcript = $content.children('.rjm-plan-transcript');

		if (!$transcript.length) {
			$content.empty();
			$transcript = $('<div class="rjm-plan-transcript"></div>').appendTo($content);			$content.off('scroll.rjmPlan').on('scroll.rjmPlan', function () {
				var el = this;
				$panel.data('planPinned', el.scrollHeight - el.scrollTop - el.clientHeight < 40);
			});
			$panel.data('planPinned', true);
		}

		return $transcript;
	}

	function appendMessageBubble($panel, role, content, screenshots) {
		var $transcript = ensureTranscript($panel);
		var $bubble = $('<div></div>').addClass('rjm-plan-message').addClass(role === 'user' ? 'is-user' : 'is-assistant');

		(screenshots || []).forEach(function (screenshot) {
			$('<div class="rjm-plan-screenshot"></div>')
				.append($('<img alt="" />').attr('src', screenshot.data))
				.appendTo($bubble);
		});

		$bubble.append($('<div class="rjm-plan-message-body"></div>'));
		$transcript.append($bubble);

		if (content) {
			setBubbleContent($bubble, content);
		}

		scrollTranscript($panel, true);
		return $bubble;
	}

	function setBubbleContent($bubble, text) {
		$bubble.children('.rjm-plan-message-body').html(renderMarkdown(text));
	}

	function appendThinkingBubble($panel) {
		var statuses = (cfg.i18n && cfg.i18n.thinkingStatuses) || [cfg.i18n.planning || 'Thinking…'];
		var $bubble = $('<div class="rjm-plan-message is-assistant is-thinking"></div>');
		var $status = $('<span class="rjm-thinking-status"></span>').text(statuses[0]);

		$bubble.append('<span class="rjm-thinking-dots"><span></span><span></span><span></span></span>').append($status);
		ensureTranscript($panel).append($bubble);

		var index = 0;
		var timer = window.setInterval(function () {
			index = (index + 1) % statuses.length;
			$status.text(statuses[index]);
		}, 2600);
		$bubble.data('statusTimer', timer);

		scrollTranscript($panel, true);
		return $bubble;
	}

	function removeThinkingBubble($bubble) {
		if (!$bubble || !$bubble.length) {
			return;
		}
		window.clearInterval($bubble.data('statusTimer'));
		$bubble.remove();
	}

	function appendNoticeBubble($panel, message) {
		ensureTranscript($panel).append(
			$('<p class="rjm-error"></p>').text((cfg.i18n.errorPrefix || 'Error: ') + message)
		);
		scrollTranscript($panel, true);
	}

	function renderPlanReadyNote($panel, readyToGenerate) {
		var $transcript = ensureTranscript($panel);
		$transcript.find('.rjm-plan-ready').remove();
		if (!readyToGenerate) {
			return;
		}
		$transcript.append(
			$('<p class="rjm-plan-ready"></p>').text(
				'Plan is ready. Click "' + (cfg.i18n.generatePlanBtn || 'Generate CSS from plan') + '" when you are happy.'
			)
		);
		scrollTranscript($panel);
	}

	function scrollTranscript($panel, force) {
		if (!force && $panel.data('planPinned') === false) {
			return;
		}
		var content = $panel.find('.rjm-css-advisor-content')[0];
		if (content) {
			content.scrollTop = content.scrollHeight;
		}
		$panel.data('planPinned', true);
	}

	/**
	 * Minimal Markdown renderer. Input is escaped first, so only the tags
	 * produced below can ever reach the DOM.
	 */
	function renderMarkdown(text) {
		var blocks = [];

		// Park fenced code blocks before any inline processing touches them.
		var escaped = escHtml(String(text || '')).replace(/```([a-z]*)\n?([\s\S]*?)```/gi, function (match, lang, code) {
			blocks.push('<div class="rjm-chat-code">' +
				'<button type="button" class="rjm-chat-code-copy">' + escHtml(cfg.i18n.copyBtn || 'Copy') + '</button>' +
				'<pre><code>' + code.replace(/\n$/, '') + '</code></pre></div>');
			return '\u0000BLOCK' + (blocks.length - 1) + '\u0000';
		});

		var html = escaped.split(/\n{2,}/).map(function (chunk) {
			chunk = chunk.trim();
			if (!chunk) {
				return '';
			}

			if (/^\u0000BLOCK\d+\u0000$/.test(chunk)) {
				return chunk;
			}

			var lines = chunk.split('\n');

			if (lines.every(function (line) { return /^\s*[-*]\s+/.test(line); })) {
				return '<ul>' + lines.map(function (line) {
					return '<li>' + inlineMarkdown(line.replace(/^\s*[-*]\s+/, '')) + '</li>';
				}).join('') + '</ul>';
			}

			if (lines.every(function (line) { return /^\s*\d+[.)]\s+/.test(line); })) {
				return '<ol>' + lines.map(function (line) {
					return '<li>' + inlineMarkdown(line.replace(/^\s*\d+[.)]\s+/, '')) + '</li>';
				}).join('') + '</ol>';
			}

			var heading = chunk.match(/^(#{1,4})\s+(.*)$/);
			if (heading) {
				return '<h4>' + inlineMarkdown(heading[2]) + '</h4>';
			}

			return '<p>' + inlineMarkdown(chunk).replace(/\n/g, '<br />') + '</p>';
		}).join('');

		return html.replace(/\u0000BLOCK(\d+)\u0000/g, function (match, index) {
			return blocks[Number(index)] || '';
		});
	}

	function inlineMarkdown(text) {
		return text
			.replace(/`([^`]+)`/g, '<code>$1</code>')
			.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
			.replace(/(^|[\s(])\*([^*\n]+)\*/g, '$1<em>$2</em>');
	}

	function onChatCodeCopyClick(e) {
		e.preventDefault();
		var $btn = $(this);
		var text = $btn.closest('.rjm-chat-code').find('code').text();

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(function () {
				flashCopied($btn);
			}).catch(function () {
				fallbackCopy(text, $btn);
			});
			return;
		}

		fallbackCopy(text, $btn);
	}

	function renderBuildStep($panel, step) {
		var current = Number(step.current_step || 1);
		var total = Number(step.total_steps || 1);
		var stepTitle = escHtml(step.step_title || 'Build step');
		var explanation = escHtml(step.explanation || '');
		var css = escHtml(step.css || '');

		var html = '';
		html += '<div class="rjm-build-step-card">';
		html += '<p class="rjm-build-progress">Step ' + current + ' of ' + total + '</p>';
		html += '<h4 class="rjm-build-title">' + stepTitle + '</h4>';
		if (explanation) {
			html += '<p class="rjm-build-explanation">' + explanation + '</p>';
		}
		html += '<pre class="rjm-code-block"><code>' + css + '</code></pre>';
		html += '</div>';

		$panel.find('.rjm-css-advisor-content').html(html);
		setResultsPriorityState($panel, true);
		$panel.find('.rjm-css-build-actions').removeAttr('hidden');
		$panel.find('.rjm-css-advisor-actions').removeAttr('hidden');
	}

	function setResultsPriorityState($panel, isActive) {
		var $inner = $panel.find('.rjm-css-advisor-panel-inner');
		if (isActive) {
			$inner.addClass('is-results-priority');
			return;
		}

		$inner.removeClass('is-results-priority');
	}

	// -------------------------------------------------------------------------
	// Copy to clipboard
	// -------------------------------------------------------------------------

	function onCopyClick() {
		var $btn     = $(this);
		var targetId = $btn.data('target');
		var $pre     = $('#' + targetId);
		var text     = $pre.find('code').text();

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(function () {
				flashCopied($btn);
			}).catch(function () {
				fallbackCopy(text, $btn);
			});
		} else {
			fallbackCopy(text, $btn);
		}
	}

	function fallbackCopy(text, $btn) {
		var $temp = $('<textarea>').val(text).css({ position: 'fixed', opacity: 0 });
		$('body').append($temp);
		$temp.focus().select();
		try {
			document.execCommand('copy'); // eslint-disable-line no-document-exec-command
			flashCopied($btn);
		} catch (e) {
			// Silent fail — clipboard not available.
		}
		$temp.remove();
	}

	function flashCopied($btn) {
		var original = $btn.text();
		$btn.text(cfg.i18n.copiedBtn).prop('disabled', true);
		setTimeout(function () {
			$btn.text(original).prop('disabled', false);
		}, 2000);
	}

	// -------------------------------------------------------------------------
	// Insert snippet into the adjacent CSS textarea
	// -------------------------------------------------------------------------

	function onGlobalToggleChange() {
		var $checkbox = $(this);
		var $actions  = $checkbox.closest('.rjm-code-actions');
		var $btn      = $actions.find('.rjm-insert-btn');
		var isGlobal  = $checkbox.is(':checked');

		$btn
			.text(isGlobal ? $btn.data('label-global') : $btn.data('label-local'))
			.toggleClass('is-global-target', isGlobal);
	}

	function onInsertClick() {
		var $btn  = $(this);
		var code  = decodeHtmlEntities($btn.data('code') || '');
		var $wrap = $btn.closest('.rjm-css-advisor-wrap');
		var $panel = getPanelFromWrap($wrap);
		var $globalCheckbox = $btn.closest('.rjm-code-actions').find('.rjm-global-checkbox');

		if ($globalCheckbox.length && $globalCheckbox.is(':checked')) {
			saveToGlobalCss($btn, $wrap, $panel, code);
			return;
		}

		var selectedBreakpoints = getSelectedBreakpoints($panel);

		var $textarea = getTargetTextarea($wrap);
		if (!$textarea.length) {
			return;
		}

		var current = $textarea.val();
		var mergeResult = mergeGeneratedCss(current, code, selectedBreakpoints);
		$textarea.val(mergeResult.css).trigger('change').focus();

		if (mergeResult.usedFallback) {
			showInsertStatus($panel, 'Inserted with safe append mode. Existing CSS was preserved because merge confidence was low.', true);
			if (window.console && window.console.warn) {
				window.console.warn('RJM CSS Advisor: append-only fallback used (' + mergeResult.reason + ').');
			}
		} else {
			showInsertStatus($panel, 'Inserted and merged into existing CSS.', false);
		}

		var original = $btn.text();
		$btn.text('✓ Inserted!').prop('disabled', true);
		setTimeout(function () {
			$btn.text(original).prop('disabled', false);
		}, 2000);
	}

	function saveToGlobalCss($btn, $wrap, $panel, code) {
		var originalLabel = $btn.data('label-global');
		var ajaxUrl = (cfg.ajaxUrl || '').replace(/^https?:\/\/[^\/]+/, window.location.origin);
		$btn.text('Saving…').prop('disabled', true);

		$.post(ajaxUrl, {
			action: 'rjm_save_global_css',
			nonce: cfg.nonce,
			code: code,
			layout: $wrap.data('layout') || '',
			field: $wrap.data('field') || '',
		}).done(function (response) {
			if (response && response.success) {
				$btn.text('✓ Saved to Global CSS');
				showInsertStatus($panel, (response.data && response.data.message) || 'Saved to the site-wide Global Custom CSS field.', false);
				setTimeout(function () {
					$btn.text(originalLabel).prop('disabled', false);
				}, 2000);
			} else {
				$btn.text(originalLabel).prop('disabled', false);
				showInsertStatus($panel, (response && response.data && response.data.message) || 'Failed to save to the Global Custom CSS field.', true);
			}
		}).fail(function () {
			$btn.text(originalLabel).prop('disabled', false);
			showInsertStatus($panel, 'Failed to save to the Global Custom CSS field.', true);
		});
	}

	function getTargetTextarea($wrap) {
		var $textarea = $wrap.closest('.acf-input').find('textarea').first();
		if (!$textarea.length) {
			$textarea = $wrap.prev('textarea');
		}

		return $textarea;
	}

	function showInsertStatus($panel, message, isWarning) {
		var $status = $panel.find('.rjm-css-insert-status');
		if (!$status.length) {
			$status = $('<p class="rjm-css-insert-status" role="status" aria-live="polite" hidden></p>');
			$panel.find('.rjm-css-advisor-actions').after($status);
		}

		$status
			.removeClass('is-warning is-success')
			.addClass(isWarning ? 'is-warning' : 'is-success')
			.text(message)
			.removeAttr('hidden');
	}

	function getSelectedBreakpoints($panel) {
		var selected = $panel.find('.rjm-css-breakpoint-input:checked').map(function () {
			return $(this).val();
		}).get();

		return selected;
	}

	function mergeGeneratedCss(currentCss, generatedCss, selectedBreakpoints) {
		var currentText = String(currentCss || '');
		var generatedText = String(generatedCss || '');
		var breakpoints = selectedBreakpoints || [];

		if (!generatedText.trim()) {
			return {
				css: currentText,
				usedFallback: false,
				reason: '',
			};
		}

		var existingValidation = validateCssForSafeMerge(currentText);
		if (!existingValidation.safe) {
			return buildAppendFallbackResult(currentText, generatedText, existingValidation.reason);
		}

		var generatedValidation = validateCssForSafeMerge(generatedText);
		if (!generatedValidation.safe) {
			return buildAppendFallbackResult(currentText, generatedText, 'generated_css_not_mergeable:' + generatedValidation.reason);
		}

		var existingParsed = parseCssBlocksDetailed(currentText);
		if (existingParsed.error || !onlyIgnorableCss(currentText.slice(existingParsed.endIndex))) {
			return buildAppendFallbackResult(currentText, generatedText, 'existing_css_parse_incomplete');
		}

		var generatedParsed = parseCssBlocksDetailed(generatedText);
		if (generatedParsed.error || !onlyIgnorableCss(generatedText.slice(generatedParsed.endIndex))) {
			return buildAppendFallbackResult(currentText, generatedText, 'generated_css_parse_incomplete');
		}

		var existingBlocks = existingParsed.blocks;
		var newBlocks = generatedParsed.blocks;

		newBlocks.forEach(function (block) {
			if (block.type === 'media') {
				if (isSelectedMediaBlock(block.header, breakpoints)) {
					upsertMediaBlock(existingBlocks, block);
				}
				return;
			}

			upsertRuleBlock(existingBlocks, block.selector, block.body);
		});

		var mergedCss = serializeCssBlocks(existingBlocks);
		if (!isMergeOutputSafe(currentText, mergedCss)) {
			return buildAppendFallbackResult(currentText, generatedText, 'low_merge_output_confidence');
		}

		var mergedValidation = validateCssForSafeMerge(mergedCss);
		if (!mergedValidation.safe) {
			return buildAppendFallbackResult(currentText, generatedText, 'merged_css_not_mergeable:' + mergedValidation.reason);
		}

		var mergedParsed = parseCssBlocksDetailed(mergedCss);
		if (mergedParsed.error || !hasAllApplicableGeneratedRules(mergedParsed.blocks, newBlocks, breakpoints)) {
			return buildAppendFallbackResult(currentText, generatedText, 'generated_rules_not_found_after_merge');
		}

		return {
			css: mergedCss,
			usedFallback: false,
			reason: '',
		};
	}

	function buildAppendFallbackResult(currentCss, generatedCss, reason) {
		return {
			css: appendCssSnippet(currentCss, generatedCss),
			usedFallback: true,
			reason: reason || 'unknown',
		};
	}

	function appendCssSnippet(currentCss, generatedCss) {
		var current = String(currentCss || '');
		var snippet = String(generatedCss || '').trim();

		if (!snippet) {
			return current;
		}

		if (!current) {
			return snippet + '\n';
		}

		var spacer = current.slice(-1) !== '\n' ? '\n\n' : '\n';
		return current + spacer + snippet;
	}

	function validateCssForSafeMerge(css) {
		var text = String(css || '');
		if (!text.trim()) {
			return { safe: true, reason: '' };
		}

		if (hasUnclosedComment(text)) {
			return { safe: false, reason: 'unclosed_comment' };
		}

		if (!hasBalancedTopLevelBraces(text)) {
			return { safe: false, reason: 'unbalanced_braces' };
		}

		var parsed = parseCssBlocksDetailed(text);
		if (parsed.error) {
			return { safe: false, reason: parsed.error };
		}

		if (!parsed.blocks.length) {
			return { safe: false, reason: 'no_parseable_blocks' };
		}

		if (!onlyIgnorableCss(text.slice(parsed.endIndex))) {
			return { safe: false, reason: 'unparsed_trailing_css' };
		}

		var roundTrip = serializeCssBlocks(parsed.blocks).trim();
		var ratio = roundTrip.length / Math.max(text.trim().length, 1);
		if (ratio < 0.7) {
			return { safe: false, reason: 'roundtrip_ratio_too_low' };
		}

		return { safe: true, reason: '' };
	}

	function parseCssBlocksDetailed(css) {
		var blocks = [];
		var index = 0;
		var length = css.length;

		while (index < length) {
			index = skipWhitespaceAndComments(css, index);

			if (index >= length) {
				break;
			}

			var braceIndex = css.indexOf('{', index);
			if (braceIndex === -1) {
				return {
					blocks: blocks,
					endIndex: index,
					error: 'missing_open_brace',
				};
			}

			var header = css.slice(index, braceIndex).trim();
			if (isUnsupportedAtRule(header)) {
				return {
					blocks: blocks,
					endIndex: index,
					error: 'unsupported_at_rule',
				};
			}

			var endIndex = findMatchingBrace(css, braceIndex);
			if (endIndex === -1) {
				return {
					blocks: blocks,
					endIndex: index,
					error: 'missing_closing_brace_or_unclosed_string',
				};
			}

			var body = css.slice(braceIndex + 1, endIndex).trim();
			if (isMediaHeader(header)) {
				var mediaRules = parseRuleBlocksDetailed(body);
				if (mediaRules.error) {
					return {
						blocks: blocks,
						endIndex: index,
						error: mediaRules.error,
					};
				}

				blocks.push({
					type: 'media',
					header: header,
					rules: mediaRules.rules,
				});
			} else {
				blocks.push({
					type: 'rule',
					selector: header,
					body: body,
				});
			}

			index = endIndex + 1;
		}

		return {
			blocks: blocks,
			endIndex: index,
			error: '',
		};
	}

	function parseRuleBlocksDetailed(css) {
		var rules = [];
		var index = 0;
		var length = css.length;

		while (index < length) {
			index = skipWhitespaceAndComments(css, index);

			if (index >= length) {
				break;
			}

			var braceIndex = css.indexOf('{', index);
			if (braceIndex === -1) {
				return {
					rules: rules,
					endIndex: index,
					error: 'missing_open_brace_in_media_rule',
				};
			}

			var selector = css.slice(index, braceIndex).trim();
			if (/^@/i.test(selector)) {
				return {
					rules: rules,
					endIndex: index,
					error: 'unsupported_nested_at_rule_in_media',
				};
			}

			var endIndex = findMatchingBrace(css, braceIndex);
			if (endIndex === -1) {
				return {
					rules: rules,
					endIndex: index,
					error: 'missing_closing_brace_in_media_rule',
				};
			}

			rules.push({
				selector: selector,
				body: css.slice(braceIndex + 1, endIndex).trim(),
			});

			index = endIndex + 1;
		}

		return {
			rules: rules,
			endIndex: index,
			error: '',
		};
	}

	function hasUnclosedComment(css) {
		var lastOpen = css.lastIndexOf('/*');
		if (lastOpen === -1) {
			return false;
		}

		return css.indexOf('*/', lastOpen + 2) === -1;
	}

	function hasBalancedTopLevelBraces(css) {
		var depth = 0;
		var inSingle = false;
		var inDouble = false;
		var inComment = false;

		for (var index = 0; index < css.length; index += 1) {
			var char = css.charAt(index);
			var next = css.charAt(index + 1);

			if (inComment) {
				if (char === '*' && next === '/') {
					inComment = false;
					index += 1;
				}
				continue;
			}

			if (inSingle) {
				if (char === '\\') {
					index += 1;
				} else if (char === "'") {
					inSingle = false;
				}
				continue;
			}

			if (inDouble) {
				if (char === '\\') {
					index += 1;
				} else if (char === '"') {
					inDouble = false;
				}
				continue;
			}

			if (char === '/' && next === '*') {
				inComment = true;
				index += 1;
				continue;
			}

			if (char === "'") {
				inSingle = true;
				continue;
			}

			if (char === '"') {
				inDouble = true;
				continue;
			}

			if (char === '{') {
				depth += 1;
			} else if (char === '}') {
				depth -= 1;
				if (depth < 0) {
					return false;
				}
			}
		}

		return depth === 0 && !inComment && !inSingle && !inDouble;
	}

	function onlyIgnorableCss(cssFragment) {
		var fragment = String(cssFragment || '');
		var previousLength = -1;

		while (fragment.length !== previousLength) {
			previousLength = fragment.length;
			fragment = fragment.replace(/^\s+/, '');
			fragment = fragment.replace(/^\/\*[\s\S]*?\*\//, '');
		}

		return fragment.trim() === '';
	}

	function isMergeOutputSafe(currentCss, mergedCss) {
		var currentTrimmed = String(currentCss || '').trim();
		if (!currentTrimmed) {
			return true;
		}

		var mergedTrimmed = String(mergedCss || '').trim();
		if (!mergedTrimmed) {
			return false;
		}

		return mergedTrimmed.length >= Math.floor(currentTrimmed.length * 0.7);
	}

	function isSelectedMediaBlock(header, selectedBreakpoints) {
		var normalizedHeader = normalizeMediaHeader(header);
		var mediaMap = getMediaQueryMap();

		return selectedBreakpoints.some(function (breakpoint) {
			return normalizedHeader === normalizeMediaHeader(mediaMap[breakpoint] || '');
		});
	}

	function getMediaQueryMap() {
		return {
			mobile: '@media (max-width: 767.98px)',
			tablet: '@media (min-width: 768px) and (max-width: 991.98px)',
			desktop: '@media (min-width: 992px)',
		};
	}

	function hasAllApplicableGeneratedRules(mergedBlocks, generatedBlocks, selectedBreakpoints) {
		var mergedRootSelectors = {};
		var mergedMediaSelectors = {};

		mergedBlocks.forEach(function (block) {
			if (block.type === 'rule') {
				mergedRootSelectors[normalizeRuleSelector(block.selector)] = true;
				return;
			}

			if (block.type === 'media') {
				var mediaKey = normalizeMediaHeader(block.header);
				if (!mergedMediaSelectors[mediaKey]) {
					mergedMediaSelectors[mediaKey] = {};
				}

				(block.rules || []).forEach(function (rule) {
					mergedMediaSelectors[mediaKey][normalizeRuleSelector(rule.selector)] = true;
				});
			}
		});

		for (var blockIndex = 0; blockIndex < generatedBlocks.length; blockIndex += 1) {
			var block = generatedBlocks[blockIndex];

			if (block.type === 'rule') {
				if (!mergedRootSelectors[normalizeRuleSelector(block.selector)]) {
					return false;
				}
				continue;
			}

			if (block.type === 'media' && isSelectedMediaBlock(block.header, selectedBreakpoints)) {
				var mediaKey = normalizeMediaHeader(block.header);
				var mergedRules = mergedMediaSelectors[mediaKey] || {};

				for (var ruleIndex = 0; ruleIndex < block.rules.length; ruleIndex += 1) {
					if (!mergedRules[normalizeRuleSelector(block.rules[ruleIndex].selector)]) {
						return false;
					}
				}
			}
		}

		return true;
	}

	function parseCssBlocks(css) {
		var blocks = [];
		var index = 0;
		var length = css.length;

		while (index < length) {
			index = skipWhitespaceAndComments(css, index);

			if (index >= length) {
				break;
			}

			var braceIndex = css.indexOf('{', index);
			if (braceIndex === -1) {
				break;
			}

			var header = css.slice(index, braceIndex).trim();
			var endIndex = findMatchingBrace(css, braceIndex);
			if (endIndex === -1) {
				break;
			}

			var body = css.slice(braceIndex + 1, endIndex).trim();
			if (isMediaHeader(header)) {
				blocks.push({
					type: 'media',
					header: header,
					rules: parseRuleBlocks(body),
				});
			} else {
				blocks.push({
					type: 'rule',
					selector: header,
					body: body,
				});
			}

			index = endIndex + 1;
		}

		return blocks;
	}

	function parseRuleBlocks(css) {
		var rules = [];
		var index = 0;
		var length = css.length;

		while (index < length) {
			index = skipWhitespaceAndComments(css, index);

			if (index >= length) {
				break;
			}

			var braceIndex = css.indexOf('{', index);
			if (braceIndex === -1) {
				break;
			}

			var selector = css.slice(index, braceIndex).trim();
			var endIndex = findMatchingBrace(css, braceIndex);
			if (endIndex === -1) {
				break;
			}

			rules.push({
				selector: selector,
				body: css.slice(braceIndex + 1, endIndex).trim(),
			});

			index = endIndex + 1;
		}

		return rules;
	}

	function skipWhitespaceAndComments(css, startIndex) {
		var index = startIndex;

		while (index < css.length) {
			if (/\s/.test(css.charAt(index))) {
				index += 1;
				continue;
			}

			if (css.charAt(index) === '/' && css.charAt(index + 1) === '*') {
				var commentEnd = css.indexOf('*/', index + 2);
				if (commentEnd === -1) {
					return css.length;
				}
				index = commentEnd + 2;
				continue;
			}

			break;
		}

		return index;
	}

	function findMatchingBrace(css, openIndex) {
		var depth = 0;
		var inSingle = false;
		var inDouble = false;
		var inComment = false;

		for (var index = openIndex; index < css.length; index += 1) {
			var char = css.charAt(index);
			var next = css.charAt(index + 1);

			if (inComment) {
				if (char === '*' && next === '/') {
					inComment = false;
					index += 1;
				}
				continue;
			}

			if (inSingle) {
				if (char === '\\') {
					index += 1;
				} else if (char === "'") {
					inSingle = false;
				}
				continue;
			}

			if (inDouble) {
				if (char === '\\') {
					index += 1;
				} else if (char === '"') {
					inDouble = false;
				}
				continue;
			}

			if (char === '/' && next === '*') {
				inComment = true;
				index += 1;
				continue;
			}

			if (char === "'") {
				inSingle = true;
				continue;
			}

			if (char === '"') {
				inDouble = true;
				continue;
			}

			if (char === '{') {
				depth += 1;
			} else if (char === '}') {
				depth -= 1;
				if (depth === 0) {
					return index;
				}
			}
		}

		return -1;
	}

	function isMediaHeader(header) {
		return /^@media\b/i.test(header);
	}

	function isUnsupportedAtRule(header) {
		var trimmed = String(header || '').trim();
		return /^@/i.test(trimmed) && !isMediaHeader(trimmed);
	}

	function upsertMediaBlock(blocks, generatedMediaBlock) {
		var normalizedHeader = normalizeMediaHeader(generatedMediaBlock.header);
		var existingMediaBlock = null;

		blocks.some(function (block) {
			if (block.type === 'media' && normalizeMediaHeader(block.header) === normalizedHeader) {
				existingMediaBlock = block;
				return true;
			}
			return false;
		});

		if (!existingMediaBlock) {
			blocks.push({
				type: 'media',
				header: generatedMediaBlock.header,
				rules: generatedMediaBlock.rules.slice(),
			});
			return;
		}

		generatedMediaBlock.rules.forEach(function (rule) {
			upsertRuleBlock(existingMediaBlock.rules, rule.selector, rule.body);
		});
	}

	function upsertRuleBlock(blocks, selector, body) {
		var normalizedSelector = normalizeRuleSelector(selector);
		var existingRule = null;

		blocks.some(function (block) {
			if (block.type === 'rule' && normalizeRuleSelector(block.selector) === normalizedSelector) {
				existingRule = block;
				return true;
			}
			return false;
		});

		if (!existingRule) {
			blocks.push({
				type: 'rule',
				selector: selector.trim(),
				body: normalizeCssBody(body),
			});
			return;
		}

		existingRule.body = mergeDeclarations(existingRule.body, body);
	}

	function mergeDeclarations(existingBody, newBody) {
		var currentLines = normalizeCssBody(existingBody).split('\n').filter(function (line) {
			return line.trim() !== '';
		});
		var newLines = normalizeCssBody(newBody).split('\n').filter(function (line) {
			return line.trim() !== '';
		});
		var newProperties = {};

		newLines.forEach(function (line) {
			var match = line.match(/^\s*([a-zA-Z-]+)\s*:/);
			if (match) {
				newProperties[match[1].toLowerCase()] = true;
			}
		});

		var filteredLines = currentLines.filter(function (line) {
			var match = line.match(/^\s*([a-zA-Z-]+)\s*:/);
			if (!match) {
				return true;
			}

			return !newProperties[match[1].toLowerCase()];
		});

		return filteredLines.concat(newLines).join('\n').trim();
	}

	function serializeCssBlocks(blocks) {
		var output = blocks.map(function (block) {
			if (block.type === 'media') {
				return formatMediaBlock(block.header, block.rules);
			}

			return formatRuleBlock(block.selector, block.body);
		}).filter(function (text) {
			return text.trim() !== '';
		}).join('\n\n');

		return output ? output.trim() + '\n' : '';
	}

	function formatMediaBlock(header, rules) {
		var inner = rules.map(function (rule) {
			return formatRuleBlock(rule.selector, rule.body);
		}).filter(function (text) {
			return text.trim() !== '';
		}).join('\n\n');

		if (!inner) {
			return '';
		}

		return header.trim() + ' {\n' + indentCss(inner, '\t') + '\n}';
	}

	function formatRuleBlock(selector, body) {
		var normalizedBody = normalizeCssBody(body);
		if (!selector.trim() || !normalizedBody) {
			return '';
		}

		return selector.trim() + ' {\n' + indentCss(normalizedBody, '\t') + '\n}';
	}

	function indentCss(text, indent) {
		return text.split('\n').map(function (line) {
			return line.trim() ? indent + line : line;
		}).join('\n');
	}

	function normalizeCssBody(body) {
		return String(body || '')
			.replace(/\r\n/g, '\n')
			.replace(/\n{3,}/g, '\n\n')
			.trim();
	}

	function normalizeMediaHeader(header) {
		return String(header || '')
			.replace(/\s+/g, ' ')
			.trim()
			.toLowerCase();
	}

	function normalizeRuleSelector(selector) {
		return String(selector || '')
			.replace(/\s+/g, ' ')
			.trim();
	}

	// -------------------------------------------------------------------------
	// Layout name detection
	// -------------------------------------------------------------------------

	function detectLayoutName($wrap) {
		var fromData = $wrap.data('layout');
		if (fromData) {
			return fromData;
		}

		// Walk up to the nearest ACF flexible-content layout row.
		// Use .parents() (not .closest()) so the wrap element itself — which carries
		// an empty data-layout="" attribute — is not matched first.
		var $row = $wrap.parents('.layout[data-layout]').first();

		// Broader fallback: any ancestor with a non-empty data-layout attribute.
		if (!$row.length || !$row.attr('data-layout')) {
			$row = $wrap.parents('[data-layout]').filter(function () {
				return !!$(this).attr('data-layout');
			}).first();
		}

		if ($row.length) {
			var layout = $row.attr('data-layout') || '';
			if (layout) {
				$wrap.data('layout', layout);
				return layout;
			}
		}

		return '';
	}

	// -------------------------------------------------------------------------
	// Utility
	// -------------------------------------------------------------------------

	function getPanelFromWrap($wrap) {
		var panelId = $wrap.data('panel');
		return $('#' + panelId);
	}

	function escHtml(str) {
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	function decodeHtmlEntities(str) {
		var txt = document.createElement('textarea');
		txt.innerHTML = str;
		return txt.value;
	}

}(jQuery));

