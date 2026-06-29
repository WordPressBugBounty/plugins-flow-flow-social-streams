<?php

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'FF_USE_WP' ) || FF_USE_WP ) {
		exit;
	}
}

// phpcs:disable
if (!defined('WPINC'))
	die;
/**
 * Publishing page view.
 *
 * @var array $accounts Connected accounts from FFPublishingAdmin::getConnectedAccounts().
 */
?>
<div class="wrap ff-publishing-wrap">

	<h1 class="ff-publishing-title">
		<span class="dashicons dashicons-share"></span>
		Publishing
	</h1>

	<div class="ff-publishing-layout">

		<!-- ===== Composer Panel ===== -->
		<div class="ff-publishing-composer">
			<div class="ff-card">
				<h2>Compose Post</h2>

				<div class="ff-field">
					<label for="ff-publish-text">Post Content</label>
					<textarea id="ff-publish-text" rows="6" placeholder="What would you like to share?"></textarea>
					<div class="ff-field-footer">
						<span class="ff-char-count"><span id="ff-char-current">0</span> characters</span>
						<div class="ff-ai-text-actions" id="ff-ai-text-actions" style="display:none;">
							<button type="button" id="ff-ai-caption-btn" class="button ff-ai-btn" title="Generate AI Caption">
								<span class="ff-ai-icon">✨</span> Caption
							</button>
							<button type="button" id="ff-ai-description-btn" class="button ff-ai-btn" title="Generate AI Description">
								<span class="ff-ai-icon">✨</span> Description
							</button>
							<button type="button" id="ff-ai-hashtags-btn" class="button ff-ai-btn" title="Generate AI Hashtags">
								<span class="ff-ai-icon">#</span> Hashtags
							</button>
						</div>
					</div>
				</div>

				<!-- AI Text Generation Panel -->
				<div class="ff-ai-panel" id="ff-ai-text-panel" style="display:none;">
					<div class="ff-ai-panel-header">
						<span class="ff-ai-panel-icon">✨</span>
						<span class="ff-ai-panel-title">AI Content Generator</span>
						<button type="button" class="ff-ai-panel-close" id="ff-ai-text-panel-close" title="Close">&times;</button>
					</div>
					<div class="ff-ai-panel-body">
						<div class="ff-ai-field">
							<label for="ff-ai-text-prompt">Prompt <span class="ff-optional">(optional — leave blank to use post content as context)</span></label>
							<input type="text" id="ff-ai-text-prompt" placeholder="e.g. Write a playful caption about summer vibes" />
						</div>
						<div class="ff-ai-field-row">
							<div class="ff-ai-field ff-ai-field-inline">
								<label for="ff-ai-text-type">Type</label>
								<select id="ff-ai-text-type">
									<option value="caption">Caption</option>
									<option value="description">Description</option>
									<option value="hashtags">Hashtags</option>
								</select>
							</div>
							<div class="ff-ai-field ff-ai-field-inline">
								<label for="ff-ai-text-style">Style</label>
								<select id="ff-ai-text-style">
									<option value="standard" selected>Standard</option>
									<option value="marketer">Marketer</option>
									<option value="blogger">Blogger</option>
									<option value="influencer">Influencer</option>
									<option value="storyteller">Storyteller</option>
									<option value="humorous">Humorous</option>
								</select>
							</div>
							<div class="ff-ai-field ff-ai-field-inline">
								<label for="ff-ai-text-count">Variations</label>
								<select id="ff-ai-text-count">
									<option value="1">1</option>
									<option value="2">2</option>
									<option value="3" selected>3</option>
									<option value="4">4</option>
								</select>
							</div>
							<button type="button" id="ff-ai-text-generate" class="button button-primary ff-ai-generate-btn">
								<span class="ff-ai-icon">✨</span> Generate
							</button>
						</div>
						<div id="ff-ai-text-results" class="ff-ai-results" style="display:none;"></div>
						<div id="ff-ai-text-loading" class="ff-ai-loading" style="display:none;">
							<span class="ff-ai-spinner"></span>
							<span>Generating content…</span>
						</div>
					</div>
				</div>

				<div class="ff-field">
					<label for="ff-publish-link">Link <span class="ff-optional">(optional)</span></label>
					<input type="url" id="ff-publish-link" placeholder="https://" />
				</div>

				<div class="ff-field">
					<label>Media <span class="ff-optional">(optional)</span></label>
					<div id="ff-media-preview" class="ff-media-preview"></div>
					<div class="ff-media-buttons">
						<button type="button" id="ff-add-media" class="button ff-button-secondary">
							<span class="dashicons dashicons-format-image"></span> Add Media
						</button>
						<button type="button" id="ff-ai-image-btn" class="button ff-button-secondary ff-ai-btn" style="display:none;">
							<span class="ff-ai-icon">✨</span> Generate with AI
						</button>
					</div>
					<input type="hidden" id="ff-media-ids" value="" />
				</div>

				<!-- AI Image Generation Panel -->
				<div class="ff-ai-panel" id="ff-ai-image-panel" style="display:none;">
					<div class="ff-ai-panel-header">
						<span class="ff-ai-panel-icon">🎨</span>
						<span class="ff-ai-panel-title">AI Image Generator</span>
						<button type="button" class="ff-ai-panel-close" id="ff-ai-image-panel-close" title="Close">&times;</button>
					</div>
					<div class="ff-ai-panel-body">
						<div class="ff-ai-field">
							<label for="ff-ai-image-prompt">Describe the image you want</label>
							<input type="text" id="ff-ai-image-prompt" placeholder="e.g. A vibrant sunset over tropical ocean, cinematic lighting" />
						</div>
						<div class="ff-ai-field-row">
							<div class="ff-ai-field ff-ai-field-inline">
								<label for="ff-ai-image-orientation">Orientation</label>
								<select id="ff-ai-image-orientation">
									<option value="landscape">Landscape</option>
									<option value="portrait">Portrait</option>
									<option value="square">Square</option>
								</select>
							</div>
							<div class="ff-ai-field ff-ai-field-inline">
								<label for="ff-ai-image-count">Count</label>
								<select id="ff-ai-image-count">
									<option value="1">1</option>
									<option value="2" selected>2</option>
									<option value="3">3</option>
									<option value="4">4</option>
								</select>
							</div>
							<button type="button" id="ff-ai-image-generate" class="button button-primary ff-ai-generate-btn">
								<span class="ff-ai-icon">🎨</span> Generate
							</button>
						</div>
						<div id="ff-ai-image-results" class="ff-ai-results ff-ai-image-results-grid" style="display:none;"></div>
						<div id="ff-ai-image-loading" class="ff-ai-loading" style="display:none;">
							<span class="ff-ai-spinner"></span>
							<span>Generating images… this may take a moment.</span>
						</div>
					</div>
				</div>

				<div class="ff-field">
					<label>Publish To</label>
					<div class="ff-network-toggles">
						<?php foreach ($accounts as $account): ?>
							<label class="ff-network-toggle <?php echo $account['connected'] ? '' : 'ff-disabled'; ?>">
								<input type="checkbox"
									   name="ff-networks[]"
									   value="<?php echo esc_attr($account['network']); ?>"
									   <?php echo $account['connected'] ? '' : 'disabled'; ?>
								/>
								<span class="ff-toggle-visual">
									<span class="ff-toggle-icon ff-icon-<?php echo esc_attr($account['network']); ?>"></span>
									<span class="ff-toggle-label"><?php echo esc_html($account['label']); ?></span>
									<?php if (!$account['connected']): ?>
										<span class="ff-toggle-status">Not connected</span>
									<?php else: ?>
										<span class="ff-toggle-status ff-connected"><?php echo esc_html($account['display_name']); ?></span>
									<?php endif; ?>
								</span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="ff-field ff-actions">
					<button type="button" id="ff-publish-btn" class="button button-primary ff-button-publish" disabled>
						<span class="dashicons dashicons-share-alt2"></span> Publish Now
					</button>
					<span id="ff-publish-status" class="ff-status-msg"></span>
				</div>
			</div>
		</div>

		<!-- ===== History Panel ===== -->
		<div class="ff-publishing-history">
			<div class="ff-card">
				<h2>
					Publishing History
					<button type="button" id="ff-refresh-history" class="button ff-button-small" title="Refresh">
						<span class="dashicons dashicons-update"></span>
					</button>
				</h2>
				<div id="ff-history-list" class="ff-history-list">
					<p class="ff-history-empty">No publishing history yet.</p>
				</div>
			</div>
		</div>

	</div><!-- .ff-publishing-layout -->

</div><!-- .ff-publishing-wrap -->

<?php // phpcs:enable ?>
