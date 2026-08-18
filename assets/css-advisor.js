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
		$(document).on('click',   '.rjm-css-goal-toggle',      onGoalToggleClick);
		$(document).on('click',   '.rjm-css-plan-generate-btn', onPlanGenerateClick);
		$(document).on('click',   '.rjm-css-build-action',     onBuildActionClick);
		$(document).on('change',  '.rjm-css-mode-input',       onModeChange);
		$(document).on('click',   '.rjm-css-advisor-tryagain', onTryAgainClick);
		$(document).on('click',   '.rjm-css-advisor-close',    onCloseClick);
		$(document).on('click',   '.rjm-copy-btn',             onCopyClick);
		$(document).on('click',   '.rjm-insert-btn',           onInsertClick);
		// Ctrl/Cmd + Enter inside the goal textarea submits the form.
		$(document).on('keydown', '.rjm-css-goal-input',       onGoalKeydown);
		$(document).on('paste',   '.rjm-css-goal-input',       onGoalPaste);
		$(document).on('click',   '.rjm-css-screenshot-upload-btn', onScreenshotUploadClick);
		$(document).on('change',  '.rjm-css-screenshot-input', onScreenshotInputChange);
		$(document).on('click',   '.rjm-css-screenshot-remove', onScreenshotRemoveClick);
		$(document).on('click',   '.rjm-css-screenshot-clear', onScreenshotClearClick);
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

	function onGoalToggleClick(e) {
		e.preventDefault();
		var $panel = $(e.currentTarget).closest('.rjm-css-advisor-panel');
		setGoalFormExpanded($panel, $panel.find('.rjm-css-goal-form').hasClass('is-collapsed'));
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
		resetModeState($panel);
		updateModeUI($panel);
	}

	function onGoalKeydown(e) {
		// Ctrl+Enter or Cmd+Enter submits the goal.
		if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
			e.preventDefault();
			$(this).closest('.rjm-css-advisor-wrap').find('.rjm-css-generate-btn').trigger('click');
		}
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

		var $goalForm = $panel.find('.rjm-css-goal-form');
		var $loading  = $panel.find('.rjm-css-advisor-loading');
		var $content  = $panel.find('.rjm-css-advisor-content');
		var $actions  = $panel.find('.rjm-css-advisor-actions');

		// Normalise the AJAX URL to the current page's origin so requests are
		// not blocked by CORS when the WordPress siteurl option differs from the
		// actual served hostname (headless / proxy setup).
		var ajaxUrl = (cfg.ajaxUrl || '').replace(/^https?:\/\/[^\/]+/, window.location.origin);

		// Switch to loading state.
		$goalForm.attr('hidden', true);
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
		var sessionId = $panel.data('planSessionId') || '';
		var screenshots = $panel.data('pendingScreenshots') || [];
		var ajaxUrl = normalizeAjaxUrl();

		setLoadingState($panel, cfg.i18n.planning || cfg.i18n.generating);

		$.ajax({
			url: ajaxUrl,
			type: 'POST',
			data: {
				action: 'rjm_plan_css_chat',
				nonce: cfg.nonce,
				layout: reqCtx.layoutName,
				field: reqCtx.fieldName,
				field_key: reqCtx.fieldKey,
				post_id: reqCtx.postId,
				current_css: reqCtx.currentCss,
				is_global: reqCtx.isGlobal ? '1' : '0',
				message: message,
				screenshot_data: screenshots.map(function (screenshot) { return screenshot.data; }),
				screenshot_name: screenshots.map(function (screenshot) { return screenshot.name; }),
				screenshot_type: screenshots.map(function (screenshot) { return screenshot.type; }),
				session_id: sessionId,
				breakpoints: breakpoints,
			},
			success: function (response) {
				clearLoadingState($panel);
				if (!response.success) {
					renderError($panel, response.data && response.data.message ? response.data.message : 'Unknown error.');
					return;
				}

				var data = response.data || {};
				$panel.data('planSessionId', data.session_id || '');
				$panel.data('planReady', Boolean(data.ready_to_generate));
				renderPlanTranscript($panel, data.transcript_html || '', data.ready_to_generate);
				clearPendingScreenshot($panel);
				updateModeUI($panel);
				$panel.find('.rjm-css-goal-input').val('').focus();
			},
			error: function (xhr) {
				clearLoadingState($panel);
				renderError($panel, xhr.statusText || 'Request failed');
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
		setGoalFormExpanded($panel, true);
		setResultsPriorityState($panel, false);
		updateModeUI($panel);
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
		$panel.find('.rjm-css-goal-form').removeAttr('hidden');
		$panel.find('.rjm-css-advisor-loading').attr('hidden', true);
		$panel.find('.rjm-css-advisor-content').html('');
		$panel.find('.rjm-css-advisor-actions').attr('hidden', true);
		$panel.find('.rjm-css-build-actions').attr('hidden', true);
		$panel.find('.rjm-css-plan-generate-btn').attr('hidden', true);
		$panel.find('.rjm-css-insert-status').attr('hidden', true).text('');
		$panel.removeData('planSessionId').removeData('buildSessionId').removeData('planReady');
		setGoalFormExpanded($panel, true);
		setResultsPriorityState($panel, false);
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
		$panel.removeData('planSessionId').removeData('buildSessionId').removeData('planReady');
		setGoalFormExpanded($panel, true);
		setResultsPriorityState($panel, false);
		$wrap.find('.rjm-css-advisor-btn').first().attr('hidden', true);
		$wrap.find('.rjm-css-advisor-btn').first().attr('aria-expanded', 'true');
		updateModeUI($panel);
		$panel.find('.rjm-css-goal-input').focus();
	}

	function getSelectedMode($panel) {
		return $panel.find('.rjm-css-mode-input:checked').val() || 'generate';
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
		};
	}

	function updateModeUI($panel) {
		var mode = getSelectedMode($panel);
		var $button = $panel.find('.rjm-css-generate-btn');
		var isPlanReady = Boolean($panel.data('planReady'));

		if (mode === 'ask') {
			$panel.find('.rjm-css-screenshot-controls').removeAttr('hidden');
			$button.text(cfg.i18n.sendPlanBtn || 'Send message');
			if (isPlanReady) {
				$panel.find('.rjm-css-plan-generate-btn').removeAttr('hidden');
			} else {
				$panel.find('.rjm-css-plan-generate-btn').attr('hidden', true);
			}
			$panel.find('.rjm-css-breakpoints').removeAttr('hidden');
			return;
		}

		if (mode === 'build') {
			$panel.find('.rjm-css-screenshot-controls').attr('hidden', true);
			$button.text(cfg.i18n.startBuildBtn || 'Start build');
			$panel.find('.rjm-css-plan-generate-btn').attr('hidden', true);
			$panel.find('.rjm-css-breakpoints').removeAttr('hidden');
			return;
		}

		$button.text(cfg.i18n.generateBtn || 'Generate CSS ✨');
		$panel.find('.rjm-css-screenshot-controls').attr('hidden', true);
		$panel.find('.rjm-css-plan-generate-btn').attr('hidden', true);
		$panel.find('.rjm-css-breakpoints').removeAttr('hidden');
	}

	function resetModeState($panel) {
		$panel.find('.rjm-css-advisor-content').html('');
		$panel.find('.rjm-css-advisor-actions').attr('hidden', true);
		$panel.find('.rjm-css-build-actions').attr('hidden', true);
		$panel.find('.rjm-css-plan-generate-btn').attr('hidden', true);
		$panel.find('.rjm-css-insert-status').attr('hidden', true).text('');
		$panel.removeData('planSessionId').removeData('buildSessionId').removeData('planReady');
		setResultsPriorityState($panel, false);
	}

	function setGoalFormExpanded($panel, expanded) {
		var $goalForm = $panel.find('.rjm-css-goal-form');
		var $goalBody = $goalForm.find('.rjm-css-goal-body');
		var $toggle = $goalForm.find('.rjm-css-goal-toggle');
		var $toggleIcon = $toggle.find('.rjm-css-goal-toggle-icon');
		var $toggleText = $toggle.find('.rjm-css-goal-toggle-text');

		if (expanded) {
			$goalForm.removeClass('is-collapsed');
			$goalBody.removeAttr('hidden');
			$toggle.attr('aria-expanded', 'true');
			$toggle.attr('aria-label', cfg.i18n.reduceGoalBtn || 'Reduce');
			$toggleIcon.text('▾');
			$toggleText.text(cfg.i18n.reduceGoalBtn || 'Reduce');
			return;
		}

		$goalForm.addClass('is-collapsed');
		$goalBody.attr('hidden', true);
		$toggle.attr('aria-expanded', 'false');
		$toggle.attr('aria-label', cfg.i18n.expandGoalBtn || 'Expand');
		$toggleIcon.text('▸');
		$toggleText.text(cfg.i18n.expandGoalBtn || 'Expand');
	}

	function normalizeAjaxUrl() {
		return (cfg.ajaxUrl || '').replace(/^https?:\/\/[^\/]+/, window.location.origin);
	}

	function setLoadingState($panel, loadingText) {
		var $goalForm = $panel.find('.rjm-css-goal-form');
		var $loading = $panel.find('.rjm-css-advisor-loading');
		var $content = $panel.find('.rjm-css-advisor-content');
		var $actions = $panel.find('.rjm-css-advisor-actions');

		$goalForm.attr('hidden', true);
		$loading.html('<span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>' + escHtml(loadingText || cfg.i18n.generating || 'Generating CSS…')).removeAttr('hidden');
		$content.html('');
		$actions.attr('hidden', true);
		$panel.find('.rjm-css-build-actions').attr('hidden', true);
	}

	function clearLoadingState($panel) {
		$panel.find('.rjm-css-advisor-loading').attr('hidden', true);
		$panel.find('.rjm-css-goal-form').removeAttr('hidden');
	}

	function renderError($panel, message) {
		$panel.find('.rjm-css-advisor-content').html('<p class="rjm-error">' + escHtml(cfg.i18n.errorPrefix) + escHtml(message) + '</p>');
		setResultsPriorityState($panel, true);
		$panel.find('.rjm-css-advisor-actions').removeAttr('hidden');
	}

	function renderPlanTranscript($panel, transcriptHtml, readyToGenerate) {
		var html = '' + transcriptHtml;
		if (readyToGenerate) {
			html += '<p class="rjm-plan-ready">Plan is ready. Click "' + escHtml(cfg.i18n.generatePlanBtn || 'Generate CSS from plan') + '" when you are happy.</p>';
		}
		$panel.find('.rjm-css-advisor-content').html(html);
		setResultsPriorityState($panel, true);
		$panel.find('.rjm-css-advisor-actions').removeAttr('hidden');
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

	function onInsertClick() {
		var $btn  = $(this);
		var code  = decodeHtmlEntities($btn.data('code') || '');
		var $wrap = $btn.closest('.rjm-css-advisor-wrap');
		var $panel = getPanelFromWrap($wrap);
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

