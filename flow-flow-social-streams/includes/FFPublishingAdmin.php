<?php
namespace flow;
if (!defined('WPINC'))
	die;

use flow\social\publisher\FFPublishDispatcher;
use la\core\LAUtils;

/**
 * Flow-Flow Publishing Admin Page.
 *
 * Registers a standalone "Publishing" submenu under the "Social Apps"
 * top-level menu, enqueues its own CSS/JS, and provides the AJAX
 * endpoints for the composer UI.
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>
 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
class FFPublishingAdmin {

	/** @var array Plugin context. */
	private $context;

	/** @var string */
	private $page_hook = '';

	public function __construct($context) {
		$this->context = $context;

		// Hiding Publishing in UI for now
		// add_action('admin_menu', [$this, 'registerSubmenu'], 20);
		add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);

		// AJAX: publish action
		add_action('wp_ajax_flow_flow_publish', [$this, 'ajaxPublish']);
		// AJAX: get publishing history
		add_action('wp_ajax_flow_flow_publish_history', [$this, 'ajaxHistory']);
		// AJAX: AI content generation
		add_action('wp_ajax_flow_flow_ai_generate_text', [$this, 'ajaxAiGenerateText']);
		add_action('wp_ajax_flow_flow_ai_generate_image', [$this, 'ajaxAiGenerateImage']);
	}

	/**
	 * Register the "Publishing" submenu under Social Apps.
	 */
	public function registerSubmenu() {
		$this->page_hook = add_submenu_page(
			'flow-' . 'flow',           // parent slug (Social Apps top-level menu)
			'Publishing',          // page title
			'Publishing',          // menu title
			'manage_options',      // capability
			'flow-flow-publishing', // menu slug
			[$this, 'renderPage']  // callback
		);
	}

	/**
	 * Enqueue styles and scripts only on the Publishing page.
	 */
	public function enqueueAssets($hook) {
		if ($hook !== $this->page_hook) {
			return;
		}

		$pluginDir = $this->context['plugin_url'] . $this->context['plugin_dir_name'] . '/';

		// Re-use admin icon font and base admin styles
		wp_enqueue_style('ff-admin-icon-styles', $pluginDir . 'css/admin-icon.css', [], LAUtils::version($this->context));
		wp_enqueue_style('ff-publishing-styles', $pluginDir . 'css/publishing.css', [], LAUtils::version($this->context));

		// Google Font
		wp_enqueue_style('ff-publishing-fonts', '//fonts.googleapis.com/css?family=Inter:400,500,600,700', [], LAUtils::version($this->context), 'all');

		// WordPress Media uploader
		wp_enqueue_media();

		wp_enqueue_script('ff-publishing-script', $pluginDir . 'js/publishing.js', ['jquery'], LAUtils::version($this->context), true);

		// Pass config to JS
		$options = LAUtils::dbm($this->context)->getOption('options', true);
		if (!is_array($options)) $options = [];

		// Detect WordPress 7.0 AI Client availability
		$ai_support = $this->detectAiSupport();

		wp_localize_script('ff-publishing-script', 'ff_publishing', [
			'ajax_url'   => admin_url('admin-ajax.php'),
			'nonce'      => wp_create_nonce('ff_publishing_nonce'),
			'accounts'   => $this->getConnectedAccounts($options),
			'ai_support' => $ai_support,
		]);
	}

	/**
	 * Render the Publishing admin page.
	 */
	public function renderPage() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'flow-flow-social-streams'));
		}

		$options = LAUtils::dbm($this->context)->getOption('options', true);
		if (!is_array($options)) $options = [];

		$accounts = $this->getConnectedAccounts($options);

		include_once $this->context['root'] . 'views/publishing.php';
	}

	/**
	 * AJAX handler: publish a post to selected networks.
	 */
	public function ajaxPublish() {
		check_ajax_referer('ff_publishing_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
		}

		$text     = isset($_POST['text']) ? sanitize_textarea_field(wp_unslash($_POST['text'])) : '';
		$link     = isset($_POST['link']) ? esc_url_raw(wp_unslash($_POST['link'])) : '';
		$networks = isset($_POST['networks']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['networks'])) : [];
		$mediaIds = isset($_POST['media_ids']) ? array_map('intval', (array) wp_unslash($_POST['media_ids'])) : [];

		if (empty($text) && empty($mediaIds)) {
			wp_send_json_error(['message' => 'Post content or media is required.']);
		}

		if (empty($networks)) {
			wp_send_json_error(['message' => 'Select at least one network.']);
		}

		// Resolve media attachments from WP Media Library
		$media = [];
		foreach ($mediaIds as $id) {
			$url  = wp_get_attachment_url($id);
			$mime = get_post_mime_type($id);
			$type = (strpos($mime, 'video') !== false) ? 'video' : 'image';
			if ($url) {
				$media[] = ['url' => $url, 'type' => $type, 'mime' => $mime, 'id' => $id];
			}
		}

		// Build targets from connected accounts
		$options = LAUtils::dbm($this->context)->getOption('options', true);
		if (!is_array($options)) $options = [];

		$targets = [];
		foreach ($networks as $network) {
			$target = $this->resolveTarget($network, $options);
			if ($target) {
				$targets[] = $target;
			}
		}

		if (empty($targets)) {
			wp_send_json_error(['message' => 'No valid accounts found for selected networks. Check your Auth settings.']);
		}

		// Dispatch
		$dispatcher = new FFPublishDispatcher();
		$results    = $dispatcher->dispatch([
			'text'  => $text,
			'link'  => $link,
			'media' => $media,
		], $targets);

		// Log to publishing history
		$this->logPublishAttempt($text, $link, $media, $networks, $results);

		wp_send_json_success(['results' => $results]);
	}

	/**
	 * AJAX handler: retrieve publishing history.
	 */
	public function ajaxHistory() {
		check_ajax_referer('ff_publishing_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
		}

		$history = get_option('ff_publish_history', []);
		// Return most recent 50 entries, newest first
		$history = array_slice(array_reverse($history), 0, 50);

		wp_send_json_success(['history' => $history]);
	}

	/**
	 * AJAX handler: AI text generation (captions / descriptions).
	 *
	 * Uses the WordPress 7.0 AI Client API (`wp_ai_client_prompt()`).
	 */
	public function ajaxAiGenerateText() {
		check_ajax_referer('ff_publishing_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
		}

		if (!function_exists('wp_ai_client_prompt')) {
			wp_send_json_error(['message' => 'AI Client is not available. Requires WordPress 7.0+.']);
		}

		$prompt  = isset($_POST['prompt']) ? sanitize_textarea_field(wp_unslash($_POST['prompt'])) : '';
		$context_text = isset($_POST['context']) ? sanitize_textarea_field(wp_unslash($_POST['context'])) : '';
		$type    = isset($_POST['type']) ? sanitize_text_field(wp_unslash($_POST['type'])) : 'caption';
		$style   = isset($_POST['style']) ? sanitize_text_field(wp_unslash($_POST['style'])) : 'standard';
		$count   = isset($_POST['count']) ? intval($_POST['count']) : 1;
		$count   = max(1, min($count, 4)); // 1-4 variations

		if (empty($prompt) && empty($context_text)) {
			wp_send_json_error(['message' => 'A prompt or context is required.']);
		}

		// Build the system instruction based on type
		switch ($type) {
			case 'description':
				$system = 'You are a social media content strategist. Write engaging post descriptions/body copy for social media publishing. '
					. 'Keep descriptions compelling, use appropriate emojis sparingly, and optimize for engagement. '
					. 'Do not include hashtags unless explicitly asked. Return only the description text, no labels or prefixes.';
				break;
			case 'hashtags':
				$system = 'You are a social media hashtag expert. Generate relevant, trending hashtags for the given content. '
					. 'Return only hashtags separated by spaces, starting with #. Aim for 5-10 hashtags.';
				break;
			case 'caption':
			default:
				$system = 'You are a social media content creator. Write short, catchy captions for social media posts. '
					. 'Captions should be concise (1-3 sentences), engaging, and optimized for social media. '
					. 'Do not include hashtags unless explicitly asked. Return only the caption text, no labels or prefixes.';
				break;
		}

		// Apply style specific adjustments to the system prompt
		if ($type !== 'hashtags') {
			switch ($style) {
				case 'marketer':
					$system .= ' Write in the style of a high-converting marketer: persuasive, benefit-driven, action-oriented, utilizes a compelling call-to-action (CTA), and designs for conversions and urgency.';
					break;
				case 'blogger':
					$system .= ' Write in the style of an informative blogger: conversational, authentic, reflective, educational, and engagingly descriptive.';
					break;
				case 'influencer':
					$system .= ' Write in the style of a social media influencer: highly personal, energetic, trendy, relatable, utilizes casual emoji placement, and emphasizes community connection.';
					break;
				case 'storyteller':
					$system .= ' Write in the style of a creative storyteller: narrative-driven, visually descriptive, emotionally resonant, and hooks the reader with a narrative arc.';
					break;
				case 'humorous':
					$system .= ' Write in the style of a clever humorist: lighthearted, witty, playful, uses subtle puns or clever observations, and keeps it highly entertaining.';
					break;
				case 'standard':
				default:
					$system .= ' Write in a standard, balanced, and organic social media voice.';
					break;
			}
		}

		// Build the full prompt
		$full_prompt = $prompt;
		if (!empty($context_text)) {
			$full_prompt = "Context/existing content:\n" . $context_text . "\n\n" . ($prompt ?: 'Generate a ' . $type . ' for this content.');
		}

		try {
			// phpcs:ignore WordPress.WP.DeprecatedFunctions.wp_ai_client_prompt
			$builder = call_user_func('wp_ai_client_prompt', $full_prompt)
				->using_system_instruction($system)
				->using_temperature(0.8)
				->using_max_tokens(1000);

			if ($count > 1) {
				try {
					$texts = $builder->generate_texts($count);
					if (is_wp_error($texts)) {
						throw new \Exception($texts->get_error_message());
					}
				} catch (\Throwable $e) {
					// Fallback: if the model/client doesn't support multiple candidates,
					// we generate them sequentially by calling generate_text() multiple times.
					$texts = [];
					for ($i = 0; $i < $count; $i++) {
						$text = $builder->generate_text();
						if (is_wp_error($text)) {
							wp_send_json_error(['message' => $text->get_error_message()]);
						}
						$texts[] = $text;
					}
				}
				wp_send_json_success(['texts' => $texts]);
			} else {
				$text = $builder->generate_text();
				if (is_wp_error($text)) {
					wp_send_json_error(['message' => $text->get_error_message()]);
				}
				wp_send_json_success(['texts' => [$text]]);
			}
		} catch (\Throwable $e) {
			wp_send_json_error(['message' => 'AI generation failed: ' . $e->getMessage()]);
		}
	}

	/**
	 * AJAX handler: AI image generation.
	 *
	 * Generates images via the WordPress 7.0 AI Client API and saves
	 * them to the WordPress Media Library.
	 */
	public function ajaxAiGenerateImage() {
		check_ajax_referer('ff_publishing_nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
		}

		if (!function_exists('wp_ai_client_prompt')) {
			wp_send_json_error(['message' => 'AI Client is not available. Requires WordPress 7.0+.']);
		}

		$prompt      = isset($_POST['prompt']) ? sanitize_textarea_field(wp_unslash($_POST['prompt'])) : '';
		$count       = isset($_POST['count']) ? intval($_POST['count']) : 1;
		$orientation = isset($_POST['orientation']) ? sanitize_text_field(wp_unslash($_POST['orientation'])) : 'landscape';
		$count       = max(1, min($count, 4)); // 1-4 images

		if (empty($prompt)) {
			wp_send_json_error(['message' => 'An image prompt is required.']);
		}

		try {
			// phpcs:ignore WordPress.WP.DeprecatedFunctions.wp_ai_client_prompt
			$builder = call_user_func('wp_ai_client_prompt', $prompt)
				->using_system_instruction('Generate high-quality, visually striking images suitable for social media posts. Make images vibrant and eye-catching.');

			// Apply orientation if the enum class exists
			if (class_exists('WordPress\\AiClient\\Files\\Enums\\MediaOrientationEnum')) {
				$builder->as_output_media_orientation(
					\WordPress\AiClient\Files\Enums\MediaOrientationEnum::from($orientation)
				);
			}

			if ($count > 1) {
				try {
					$images = $builder->generate_images($count);
					if (is_wp_error($images)) {
						throw new \Exception($images->get_error_message());
					}
				} catch (\Throwable $e) {
					// Fallback: if the model/client doesn't support generating multiple images at once,
					// we generate them sequentially by calling generate_image() multiple times.
					$images = [];
					for ($i = 0; $i < $count; $i++) {
						$image = $builder->generate_image();
						if (is_wp_error($image)) {
							wp_send_json_error(['message' => $image->get_error_message()]);
						}
						$images[] = $image;
					}
				}
			} else {
				$image = $builder->generate_image();
				if (is_wp_error($image)) {
					wp_send_json_error(['message' => $image->get_error_message()]);
				}
				$images = [$image];
			}

			// Save each generated image to the WP Media Library
			$attachments = [];
			foreach ($images as $idx => $image_file) {
				$saved = $this->saveAiImageToMediaLibrary($image_file, $prompt, $idx);
				if (!is_wp_error($saved)) {
					$attachments[] = $saved;
				}
			}

			if (empty($attachments)) {
				wp_send_json_error(['message' => 'Failed to save generated images to Media Library.']);
			}

			wp_send_json_success(['attachments' => $attachments]);
		} catch (\Throwable $e) {
			wp_send_json_error(['message' => 'AI image generation failed: ' . $e->getMessage()]);
		}
	}

	/**
	 * Save an AI-generated image File DTO to the WordPress Media Library.
	 *
	 * @param object $image_file  File DTO from WP AI Client (has getDataUri()).
	 * @param string $prompt      The prompt used (for the attachment title).
	 * @param int    $idx         Index for unique filenames.
	 * @return array|\WP_Error    Attachment data on success.
	 */
	private function saveAiImageToMediaLibrary($image_file, $prompt, $idx = 0) {
		if (!function_exists('wp_upload_bits') || !function_exists('wp_insert_attachment')) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}

		// Extract binary data from the data URI
		$data_uri = $image_file->getDataUri();
		$matches  = [];
		if (!preg_match('/^data:image\/([\w+]+);base64,(.+)$/', $data_uri, $matches)) {
			return new \WP_Error('invalid_data_uri', 'Could not parse image data URI.');
		}

		$ext  = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
		$data = base64_decode($matches[2]);
		if (false === $data) {
			return new \WP_Error('decode_failed', 'Base64 decode failed.');
		}

		// Create a safe filename from the prompt
		$safe_title = sanitize_title(mb_substr($prompt, 0, 60));
		$filename   = 'ff-ai-' . $safe_title . '-' . ($idx + 1) . '-' . time() . '.' . $ext;

		$upload = wp_upload_bits($filename, null, $data);
		if (!empty($upload['error'])) {
			return new \WP_Error('upload_failed', $upload['error']);
		}

		$file_path = $upload['file'];
		$file_type = wp_check_filetype($filename, null);

		$attachment = [
			'post_mime_type' => $file_type['type'],
			'post_title'     => 'AI: ' . mb_substr($prompt, 0, 100),
			'post_content'   => '',
			'post_status'    => 'inherit',
		];

		$attach_id = wp_insert_attachment($attachment, $file_path);
		if (is_wp_error($attach_id)) {
			return $attach_id;
		}

		// Generate attachment metadata (thumbnails, etc.)
		$attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
		wp_update_attachment_metadata($attach_id, $attach_data);

		$thumb_url = wp_get_attachment_image_url($attach_id, 'thumbnail');
		$full_url  = wp_get_attachment_url($attach_id);

		return [
			'id'        => $attach_id,
			'url'       => $full_url,
			'thumbnail' => $thumb_url ?: $full_url,
			'type'      => 'image',
			'mime'      => $file_type['type'],
		];
	}

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Build the list of connected accounts from plugin options.
	 *
	 * @param array $options
	 * @return array
	 */
	private function getConnectedAccounts($options) {
		$accounts = [];

		// Facebook
		$fbToken = '';
		if (isset($this->context['facebook_cache'])) {
			$fbToken = $this->context['facebook_cache']->getAccessToken();
		}
		if (!empty($fbToken)) {
			$accounts[] = [
				'network'      => 'facebook',
				'label'        => 'Facebook',
				'display_name' => isset($options['facebook_user_name']) ? $options['facebook_user_name'] : 'Connected',
				'userpic'      => isset($options['facebook_userpic']) ? $options['facebook_userpic'] : '',
				'connected'    => true,
			];
		}

		// Instagram (shares Facebook token, needs IG user ID)
		// Instagram publish requires instagram_content_publish permission
		// For now, mark as available if Facebook is connected
		if (!empty($fbToken)) {
			$accounts[] = [
				'network'      => 'instagram',
				'label'        => 'Instagram',
				'display_name' => isset($options['facebook_user_name']) ? $options['facebook_user_name'] : 'Connected',
				'userpic'      => '',
				'connected'    => true,
			];
		}

		// LinkedIn
		$liToken = isset($options['linkedin_access_token']) ? trim($options['linkedin_access_token']) : '';
		if (!empty($liToken)) {
			$accounts[] = [
				'network'      => 'linkedin',
				'label'        => 'LinkedIn',
				'display_name' => isset($options['linkedin_display_name']) ? $options['linkedin_display_name'] : 'Connected',
				'userpic'      => isset($options['linkedin_userpic']) ? $options['linkedin_userpic'] : '',
				'connected'    => true,
			];
		}

		// YouTube (uses Google API key — needs separate OAuth for uploading)
		// Placeholder: not connected until OAuth scope is expanded
		$accounts[] = [
			'network'      => 'youtube',
			'label'        => 'YouTube',
			'display_name' => '',
			'userpic'      => '',
			'connected'    => false,
		];

		return $accounts;
	}

	/**
	 * Resolve a network name to a publish target with credentials.
	 *
	 * @param string $network
	 * @param array  $options
	 * @return array|null
	 */
	private function resolveTarget($network, $options) {
		switch ($network) {
			case 'facebook':
				$token = '';
				if (isset($this->context['facebook_cache'])) {
					$token = $this->context['facebook_cache']->getAccessToken();
				}
				$accountId = isset($options['facebook_user_id']) ? $options['facebook_user_id'] : '';
				if (empty($token)) return null;
				return ['network' => 'facebook', 'account_id' => $accountId, 'token' => $token];

			case 'instagram':
				$token = '';
				if (isset($this->context['facebook_cache'])) {
					$token = $this->context['facebook_cache']->getAccessToken();
				}
				if (empty($token)) return null;
				return ['network' => 'instagram', 'account_id' => '', 'token' => $token];

			case 'linkedin':
				$token     = isset($options['linkedin_access_token']) ? trim($options['linkedin_access_token']) : '';
				$accountId = isset($options['linkedin_org_id']) ? $options['linkedin_org_id'] : '';
				if (empty($token)) return null;
				return ['network' => 'linkedin', 'account_id' => $accountId, 'token' => $token];

			case 'youtube':
				// YouTube OAuth not yet implemented
				return null;

			default:
				return null;
		}
	}

	/**
	 * Log a publish attempt to the options table.
	 *
	 * @param string $text
	 * @param string $link
	 * @param array  $media
	 * @param array  $networks
	 * @param array  $results
	 */
	private function logPublishAttempt($text, $link, $media, $networks, $results) {
		$history = get_option('ff_publish_history', []);

		$entry = [
			'timestamp' => time(),
			'text'      => mb_substr($text, 0, 1000),
			'link'      => $link,
			'media'     => count($media),
			'networks'  => $networks,
			'results'   => $results,
		];

		$history[] = $entry;

		// Keep max 200 entries
		if (count($history) > 200) {
			$history = array_slice($history, -200);
		}

		update_option('ff_publish_history', $history, false);
	}

	/**
	 * Detect WordPress 7.0 AI Client support.
	 *
	 * Returns an associative array indicating which AI capabilities
	 * are available on this site (text and/or image generation).
	 *
	 * @return array
	 */
	private function detectAiSupport() {
		$support = [
			'available'       => false,
			'text_generation'  => false,
			'image_generation' => false,
		];

		if (!function_exists('wp_ai_client_prompt')) {
			return $support;
		}

		try {
			// phpcs:ignore WordPress.WP.DeprecatedFunctions.wp_ai_client_prompt
			$builder = call_user_func('wp_ai_client_prompt', 'test');

			if ($builder->is_supported_for_text_generation()) {
				$support['text_generation'] = true;
				$support['available']       = true;
			}

			if ($builder->is_supported_for_image_generation()) {
				$support['image_generation'] = true;
				$support['available']        = true;
			}
		} catch (\Throwable $e) {
			// AI Client not properly configured — leave defaults
		}

		return $support;
	}
}
