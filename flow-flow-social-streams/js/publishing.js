/**
 * Flow-Flow Publishing Page JS
 *
 * Handles the composer UI interactions: character counting, media picker,
 * network selection, publish dispatch, history rendering, and AI content
 * generation via the WordPress 7.0 AI Client.
 */
(function ($) {
	'use strict';

	var config = window.ff_publishing || {};
	var mediaIds = [];
	var aiSupport = config.ai_support || {};

	// ===== Init =====
	$(document).ready(function () {
		initCharCounter();
		initMediaPicker();
		initNetworkToggles();
		initPublishButton();
		initAiFeatures();
		loadHistory();

		$('#ff-refresh-history').on('click', function () {
			loadHistory();
		});
	});

	// ===== Character Counter =====
	function initCharCounter() {
		$('#ff-publish-text').on('input', function () {
			$('#ff-char-current').text(this.value.length);
			updatePublishButtonState();
		});
	}

	// ===== Media Picker (WP Media Library) =====
	function initMediaPicker() {
		var frame;

		$('#ff-add-media').on('click', function (e) {
			e.preventDefault();

			if (frame) {
				frame.open();
				return;
			}

			frame = wp.media({
				title: 'Select Media for Publishing',
				button: { text: 'Attach' },
				multiple: true,
				library: { type: ['image', 'video'] },
			});

			frame.on('select', function () {
				var selection = frame.state().get('selection');
				selection.each(function (attachment) {
					var data = attachment.toJSON();
					if (mediaIds.indexOf(data.id) === -1) {
						mediaIds.push(data.id);
						addMediaThumb(data);
					}
				});
				$('#ff-media-ids').val(mediaIds.join(','));
				updatePublishButtonState();
			});

			frame.open();
		});
	}

	function addMediaThumb(data) {
		var thumbUrl = data.type === 'image'
			? (data.sizes && data.sizes.thumbnail ? data.sizes.thumbnail.url : data.url)
			: (data.icon || '');

		var $thumb = $(
			'<div class="ff-media-thumb" data-id="' + data.id + '">' +
				'<img src="' + thumbUrl + '" alt="" />' +
				'<button type="button" class="ff-media-remove" title="Remove">&times;</button>' +
			'</div>'
		);

		$thumb.find('.ff-media-remove').on('click', function () {
			var id = parseInt($thumb.data('id'), 10);
			mediaIds = mediaIds.filter(function (mid) { return mid !== id; });
			$thumb.remove();
			$('#ff-media-ids').val(mediaIds.join(','));
			updatePublishButtonState();
		});

		$('#ff-media-preview').append($thumb);
	}

	/**
	 * Add AI-generated image thumb from attachment data returned by the server.
	 */
	function addAiMediaThumb(attachment) {
		if (mediaIds.indexOf(attachment.id) !== -1) return;
		mediaIds.push(attachment.id);

		var $thumb = $(
			'<div class="ff-media-thumb ff-media-thumb-ai" data-id="' + attachment.id + '">' +
				'<img src="' + attachment.thumbnail + '" alt="" />' +
				'<span class="ff-ai-badge" title="AI Generated">✨</span>' +
				'<button type="button" class="ff-media-remove" title="Remove">&times;</button>' +
			'</div>'
		);

		$thumb.find('.ff-media-remove').on('click', function () {
			var id = parseInt($thumb.data('id'), 10);
			mediaIds = mediaIds.filter(function (mid) { return mid !== id; });
			$thumb.remove();
			$('#ff-media-ids').val(mediaIds.join(','));
			updatePublishButtonState();
		});

		$('#ff-media-preview').append($thumb);
		$('#ff-media-ids').val(mediaIds.join(','));
		updatePublishButtonState();
	}

	// ===== Network Toggles =====
	function initNetworkToggles() {
		$('.ff-network-toggle input[type="checkbox"]').on('change', function () {
			updatePublishButtonState();
		});
	}

	function getSelectedNetworks() {
		var networks = [];
		$('.ff-network-toggle input[type="checkbox"]:checked').each(function () {
			networks.push($(this).val());
		});
		return networks;
	}

	// ===== Publish Button =====
	function initPublishButton() {
		$('#ff-publish-btn').on('click', function () {
			doPublish();
		});
	}

	function updatePublishButtonState() {
		var hasContent = $('#ff-publish-text').val().trim().length > 0 || mediaIds.length > 0;
		var hasNetwork = getSelectedNetworks().length > 0;
		$('#ff-publish-btn').prop('disabled', !(hasContent && hasNetwork));
	}

	// ===== Publish =====
	function doPublish() {
		var $btn = $('#ff-publish-btn');
		var $status = $('#ff-publish-status');

		$btn.addClass('ff-publishing').prop('disabled', true);
		$status.text('Publishing...').removeClass('ff-success ff-error');

		$.ajax({
			url: config.ajax_url,
			type: 'POST',
			data: {
				action: 'flow_flow_publish',
				nonce: config.nonce,
				text: $('#ff-publish-text').val(),
				link: $('#ff-publish-link').val(),
				networks: getSelectedNetworks(),
				media_ids: mediaIds,
			},
			success: function (resp) {
				$btn.removeClass('ff-publishing');
				updatePublishButtonState();

				if (resp.success) {
					var results = resp.data.results || {};
					var allOk = true;
					var msgs = [];

					for (var net in results) {
						if (results[net].success) {
							msgs.push(net + ': ✓');
						} else {
							allOk = false;
							msgs.push(net + ': ' + (results[net].error || 'Failed'));
						}
					}

					$status
						.html(msgs.join(' &nbsp;|&nbsp; '))
						.addClass(allOk ? 'ff-success' : 'ff-error');

					loadHistory();
				} else {
					$status
						.text(resp.data && resp.data.message ? resp.data.message : 'Publishing failed.')
						.addClass('ff-error');
				}
			},
			error: function () {
				$btn.removeClass('ff-publishing');
				updatePublishButtonState();
				$status.text('Network error. Please try again.').addClass('ff-error');
			},
		});
	}

	// ===========================================================
	// ===== AI Features (WordPress 7.0 AI Client) =====
	// ===========================================================
	function initAiFeatures() {
		if (!aiSupport.available) return;

		// --- Text generation controls ---
		if (aiSupport.text_generation) {
			$('#ff-ai-text-actions').show();

			// Quick-action buttons in the field footer
			$('#ff-ai-caption-btn').on('click', function () {
				$('#ff-ai-text-type').val('caption');
				openAiTextPanel();
			});
			$('#ff-ai-description-btn').on('click', function () {
				$('#ff-ai-text-type').val('description');
				openAiTextPanel();
			});
			$('#ff-ai-hashtags-btn').on('click', function () {
				$('#ff-ai-text-type').val('hashtags');
				openAiTextPanel();
			});

			$('#ff-ai-text-panel-close').on('click', function () {
				$('#ff-ai-text-panel').slideUp(200);
			});

			$('#ff-ai-text-generate').on('click', function () {
				doAiGenerateText();
			});
		}

		// --- Image generation controls ---
		if (aiSupport.image_generation) {
			$('#ff-ai-image-btn').show();

			$('#ff-ai-image-btn').on('click', function () {
				$('#ff-ai-image-panel').slideToggle(200);
			});

			$('#ff-ai-image-panel-close').on('click', function () {
				$('#ff-ai-image-panel').slideUp(200);
			});

			$('#ff-ai-image-generate').on('click', function () {
				doAiGenerateImage();
			});
		}
	}

	function openAiTextPanel() {
		$('#ff-ai-text-panel').slideDown(200);
		$('#ff-ai-text-prompt').focus();
	}

	// ===== AI Text Generation =====
	function doAiGenerateText() {
		var prompt  = $('#ff-ai-text-prompt').val().trim();
		var context = $('#ff-publish-text').val().trim();
		var type    = $('#ff-ai-text-type').val();
		var style   = $('#ff-ai-text-style').val();
		var count   = parseInt($('#ff-ai-text-count').val(), 10);

		if (!prompt && !context) {
			$('#ff-ai-text-prompt').addClass('ff-input-error');
			setTimeout(function () { $('#ff-ai-text-prompt').removeClass('ff-input-error'); }, 1500);
			return;
		}

		var $btn     = $('#ff-ai-text-generate');
		var $loading = $('#ff-ai-text-loading');
		var $results = $('#ff-ai-text-results');

		$btn.prop('disabled', true);
		$results.hide().empty();
		$loading.show();

		$.ajax({
			url: config.ajax_url,
			type: 'POST',
			data: {
				action: 'flow_flow_ai_generate_text',
				nonce: config.nonce,
				prompt: prompt,
				context: context,
				type: type,
				style: style,
				count: count,
			},
			success: function (resp) {
				$loading.hide();
				$btn.prop('disabled', false);

				if (resp.success && resp.data.texts) {
					renderAiTextResults(resp.data.texts, type);
				} else {
					showAiError($results, resp.data && resp.data.message ? resp.data.message : 'Generation failed.');
				}
			},
			error: function () {
				$loading.hide();
				$btn.prop('disabled', false);
				showAiError($results, 'Network error. Please try again.');
			},
		});
	}

	function renderAiTextResults(texts, type) {
		var $results = $('#ff-ai-text-results');
		$results.empty().show();

		if (texts.length === 0) {
			showAiError($results, 'No results generated.');
			return;
		}

		texts.forEach(function (text, idx) {
			var $item = $(
				'<div class="ff-ai-result-item">' +
					'<div class="ff-ai-result-text">' + escapeHtml(text) + '</div>' +
					'<div class="ff-ai-result-actions">' +
						'<button type="button" class="button ff-ai-use-btn" data-idx="' + idx + '" title="Use this text">' +
							'<span class="dashicons dashicons-yes"></span> Use' +
						'</button>' +
						'<button type="button" class="button ff-ai-append-btn" data-idx="' + idx + '" title="Append to post content">' +
							'<span class="dashicons dashicons-plus-alt2"></span> Append' +
						'</button>' +
						'<button type="button" class="button ff-ai-copy-btn" data-idx="' + idx + '" title="Copy to clipboard">' +
							'<span class="dashicons dashicons-clipboard"></span>' +
						'</button>' +
					'</div>' +
				'</div>'
			);

			// Use: replace post content
			$item.find('.ff-ai-use-btn').on('click', function () {
				var $textarea = $('#ff-publish-text');
				if (type === 'hashtags') {
					// Append hashtags to existing content
					var existing = $textarea.val().trim();
					$textarea.val(existing ? existing + '\n\n' + text : text);
				} else {
					$textarea.val(text);
				}
				$textarea.trigger('input');
				$('#ff-ai-text-panel').slideUp(200);
				flashSuccess($(this));
			});

			// Append: add to end of post content
			$item.find('.ff-ai-append-btn').on('click', function () {
				var $textarea = $('#ff-publish-text');
				var existing = $textarea.val().trim();
				$textarea.val(existing ? existing + '\n\n' + text : text);
				$textarea.trigger('input');
				flashSuccess($(this));
			});

			// Copy to clipboard
			$item.find('.ff-ai-copy-btn').on('click', function () {
				copyToClipboard(text);
				flashSuccess($(this));
			});

			$results.append($item);
		});
	}

	// ===== AI Image Generation =====
	function doAiGenerateImage() {
		var prompt      = $('#ff-ai-image-prompt').val().trim();
		var orientation = $('#ff-ai-image-orientation').val();
		var count       = parseInt($('#ff-ai-image-count').val(), 10);

		if (!prompt) {
			$('#ff-ai-image-prompt').addClass('ff-input-error');
			setTimeout(function () { $('#ff-ai-image-prompt').removeClass('ff-input-error'); }, 1500);
			return;
		}

		var $btn     = $('#ff-ai-image-generate');
		var $loading = $('#ff-ai-image-loading');
		var $results = $('#ff-ai-image-results');

		$btn.prop('disabled', true);
		$results.hide().empty();
		$loading.show();

		$.ajax({
			url: config.ajax_url,
			type: 'POST',
			data: {
				action: 'flow_flow_ai_generate_image',
				nonce: config.nonce,
				prompt: prompt,
				orientation: orientation,
				count: count,
			},
			success: function (resp) {
				$loading.hide();
				$btn.prop('disabled', false);

				if (resp.success && resp.data.attachments) {
					renderAiImageResults(resp.data.attachments);
				} else {
					showAiError($results, resp.data && resp.data.message ? resp.data.message : 'Image generation failed.');
				}
			},
			error: function () {
				$loading.hide();
				$btn.prop('disabled', false);
				showAiError($results, 'Network error. Please try again.');
			},
		});
	}

	function renderAiImageResults(attachments) {
		var $results = $('#ff-ai-image-results');
		$results.empty().show();

		if (attachments.length === 0) {
			showAiError($results, 'No images generated.');
			return;
		}

		attachments.forEach(function (att) {
			var isAttached = mediaIds.indexOf(att.id) !== -1;
			var $card = $(
				'<div class="ff-ai-image-card" data-id="' + att.id + '">' +
					'<div class="ff-ai-image-wrapper">' +
						'<img src="' + att.url + '" alt="AI Generated" />' +
					'</div>' +
					'<div class="ff-ai-image-actions">' +
						'<button type="button" class="button button-primary ff-ai-attach-btn" ' +
							(isAttached ? 'disabled' : '') + '>' +
							(isAttached ? '✓ Attached' : '<span class="dashicons dashicons-plus-alt2"></span> Attach') +
						'</button>' +
					'</div>' +
				'</div>'
			);

			$card.find('.ff-ai-attach-btn').on('click', function () {
				var $self = $(this);
				addAiMediaThumb(att);
				$self.html('✓ Attached').prop('disabled', true);
			});

			$results.append($card);
		});
	}

	// ===== AI Helpers =====
	function showAiError($container, message) {
		$container.show().html(
			'<div class="ff-ai-error">' +
				'<span class="dashicons dashicons-warning"></span> ' + escapeHtml(message) +
			'</div>'
		);
	}

	function flashSuccess($btn) {
		$btn.addClass('ff-flash-success');
		setTimeout(function () { $btn.removeClass('ff-flash-success'); }, 800);
	}

	function copyToClipboard(text) {
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text);
		} else {
			var $tmp = $('<textarea>').val(text).appendTo('body').select();
			document.execCommand('copy');
			$tmp.remove();
		}
	}

	// ===== History =====
	function loadHistory() {
		$.ajax({
			url: config.ajax_url,
			type: 'POST',
			data: {
				action: 'flow_flow_publish_history',
				nonce: config.nonce,
			},
			success: function (resp) {
				if (resp.success) {
					renderHistory(resp.data.history || []);
				}
			},
		});
	}

	function renderHistory(items) {
		var $list = $('#ff-history-list');
		$list.empty();

		if (items.length === 0) {
			$list.html('<p class="ff-history-empty">No publishing history yet.</p>');
			return;
		}

		items.forEach(function (item) {
			var date = new Date(item.timestamp * 1000);
			var timeStr = date.toLocaleString();

			var badges = '';
			(item.networks || []).forEach(function (net) {
				badges += '<span class="ff-history-network-badge ff-badge-' + net + '">' + net + '</span>';
			});

			var resultLines = [];
			var results = item.results || {};
			for (var net in results) {
				var cls = results[net].success ? 'ff-result-success' : 'ff-result-error';
				var msg = results[net].success
					? '✓ Published'
					: '✗ ' + (results[net].error || 'Failed');
				resultLines.push(
					'<span class="ff-history-result ' + cls + '">' + net + ': ' + msg + '</span>'
				);
			}

			var html =
				'<div class="ff-history-item">' +
					'<div class="ff-history-meta">' +
						'<span class="ff-history-time">' + timeStr + '</span>' +
						'<div class="ff-history-networks">' + badges + '</div>' +
					'</div>' +
					'<div class="ff-history-text">' + escapeHtml(item.text || '') + '</div>' +
					'<div>' + resultLines.join(' &nbsp; ') + '</div>' +
				'</div>';

			$list.append(html);
		});
	}

	function escapeHtml(str) {
		var div = document.createElement('div');
		div.appendChild(document.createTextNode(str));
		return div.innerHTML;
	}

})(jQuery);
