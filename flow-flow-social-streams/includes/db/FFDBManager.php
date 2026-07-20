<?php
// phpcs:disable
namespace flow\db;
use Exception;
use flow\social\cache\LAFacebookCacheManager;
use flow\social\FFFeedUtils;
use la\core\db\LADBManager;
use la\core\LABase;
use la\core\settings\LASettingsUtils;

if (!defined('WPINC'))
    die;

/**
 * FlowFlow.
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>
 *
 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
class FFDBManager extends LADBManager
{
    private $facebook_changed;

    /**
     * FFDBManager constructor.
     *
     * @param array $context
     */
    public function __construct($context)
    {
        parent::__construct($context);

        // Register AJAX handler for token status check
        add_action('wp_ajax_flow_flow_get_token_status', [$this, 'getTokenStatus']);
        add_action('wp_ajax_flow_flow_fetch_linkedin_user_info', [$this, 'fetch_linkedin_user_info']);
    }

    /**
     * AJAX handler for getting token status
     */
    public function getTokenStatus()
    {
        check_ajax_referer('flow_flow_nonce', 'security');

        $platform = isset($_POST['platform']) ? sanitize_text_field($_POST['platform']) : '';
        $response = [
            'success' => false,
            'data' => null,
            'message' => 'Invalid request'
        ];

        try {
            if ($platform === 'tiktok') {
                $options = $this->getOption('options', true);
                $expires = isset($options['tiktok_expires_in']) ? (int) $options['tiktok_expires_in'] : 0;

                $data = [
                    'expires_in' => $expires,
                    'is_valid' => $expires > time()
                ];

                wp_send_json_success($data);
            } else if ($platform === 'linkedin') {
                $options = $this->getOption('options', true);
                $expires = isset($options['linkedin_expires_in']) ? (int) $options['linkedin_expires_in'] : 0;

                $data = [
                    'expires_in' => $expires,
                    'is_valid' => $expires > time()
                ];

                wp_send_json_success($data);
            } else {
                wp_send_json_error(['message' => 'Unsupported platform'], 400);
            }
        } catch (\Exception $e) {
            error_log('[Flow-Flow] Error getting token status: ' . $e->getMessage());
            wp_send_json_error(['message' => 'Internal server error'], 500);
        }
    }

    /**
     * OAuth endpoint
     * @throws Exception
     */
    private function sanitizeField($val) {
        if (function_exists('sanitize_text_field')) {
            return sanitize_text_field($val);
        }
        return is_string($val) ? trim(filter_var($val, FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW)) : '';
    }

    private function sanitizeUrl($val) {
        if (function_exists('esc_url_raw')) {
            return esc_url_raw($val);
        }
        return is_string($val) ? trim(filter_var($val, FILTER_SANITIZE_URL)) : '';
    }


    /**
     * OAuth endpoint
     * @throws Exception
     */
    public final function social_auth()
    {
        if (FF_USE_WP) {
            if (!current_user_can('manage_options')) {
                wp_die(__('You do not have sufficient permissions to access this page.', 'flow-flow-social-streams'), 403);
            }
        }

        if (isset($_REQUEST['type'])) {
            $type = $this->sanitizeField($_REQUEST['type']);
            if ($type == 'facebook') {
                /** @var LAFacebookCacheManager $facebook_cache */
                $facebook_cache = $this->context['facebook_cache'];
                $expires = time() + (isset($_REQUEST['expires']) ? (int) $_REQUEST['expires'] : 0);
                $facebook_access_token = isset($_REQUEST['facebook_access_token']) ? $this->sanitizeField($_REQUEST['facebook_access_token']) : '';
                $facebook_cache->save($facebook_access_token, $expires);

                // Save Facebook token info to options
                $options = $this->getOption('options', true);
                if (!is_array($options))
                    $options = [];

                $options['facebook_access_token'] = $facebook_access_token;
                $options['facebook_expires_in'] = $expires;
                $this->setOption('options', $options, true);

                // Fetch and save user info
                try {
                    $token = $facebook_access_token;
                    $info = $this->requestFacebookUserInfo($token);

                    if (is_array($info)) {
                        $options['facebook_user_id'] = isset($info['id']) ? $this->sanitizeField($info['id']) : '';
                        $options['facebook_user_name'] = isset($info['name']) ? $this->sanitizeField($info['name']) : '';
                        $options['facebook_userpic'] = isset($info['picture']) ? $this->sanitizeUrl($info['picture']) : '';
                        $this->setOption('options', $options, true);
                    }
                } catch (\Exception $e) {
                    error_log('[Flow-Flow] Error fetching Facebook user info: ' . $e->getMessage());
                }

                // Clean cache to ensure new settings take effect
                $this->cleanByFeedType('facebook');
            } else if ($type == 'tiktok') {
                $options = $this->getOption('options', true);
                if (!is_array($options))
                    $options = [];

                // Save TikTok access token and related data
                $token_updated = false;
                if (isset($_REQUEST['tiktok_access_token'])) {
                    $options['tiktok_access_token'] = $this->sanitizeField($_REQUEST['tiktok_access_token']);
                    $token_updated = true;
                }
                if (isset($_REQUEST['tiktok_refresh_token'])) {
                    $options['tiktok_refresh_token'] = $this->sanitizeField($_REQUEST['tiktok_refresh_token']);
                }
                if (isset($_REQUEST['tiktok_username'])) {
                    $options['tiktok_username'] = $this->sanitizeField($_REQUEST['tiktok_username']);
                }
                if (isset($_REQUEST['expires'])) {
                    $expires_in = (int) $_REQUEST['expires'];
                    $expires_timestamp = time() + $expires_in;

                    // Log the expiration values for debugging
                    error_log(sprintf(
                        '[Flow-Flow] Token refresh - expires_in: %d, current time: %s, calculated expiration: %s',
                        $expires_in,
                        date('Y-m-d H:i:s'),
                        date('Y-m-d H:i:s', $expires_timestamp)
                    ));

                    // Store the exact expiration timestamp
                    $options['tiktok_expires_in'] = $expires_timestamp;
                }

                // Save the options
                $this->setOption('options', $options, true);

                // If we have a token, fetch and save user info
                if ($token_updated) {
                    try {
                        $token = trim($options['tiktok_access_token']);
                        if ($token !== '') {
                            $user_info = $this->fetchAndSaveTikTokUserInfo($token);

                            // If we got user info back, save it to options
                            if (is_array($user_info)) {
                                $options['tiktok_username'] = isset($user_info['username']) ? $this->sanitizeField($user_info['username']) : '';
                                $options['tiktok_display_name'] = isset($user_info['display_name']) ? $this->sanitizeField($user_info['display_name']) : '';
                                $options['tiktok_open_id'] = isset($user_info['open_id']) ? $this->sanitizeField($user_info['open_id']) : '';
                                $options['tiktok_userpic'] = isset($user_info['avatar_url']) ? $this->sanitizeUrl($user_info['avatar_url']) : '';
                                $this->setOption('options', $options, true);
                            }
                        }
                    } catch (\Exception $e) {
                        error_log('[Flow-Flow] Error fetching TikTok user info: ' . $e->getMessage());
                    }
                }

                // Clean cache to ensure new settings take effect
                $this->cleanByFeedType('tiktok');
            } else if ($type == 'linkedin') {
                $options = $this->getOption('options', true);
                if (!is_array($options))
                    $options = [];

                // Save LinkedIn access token and related data
                $token_updated = false;
                if (isset($_REQUEST['linkedin_access_token'])) {
                    $options['linkedin_access_token'] = $this->sanitizeField($_REQUEST['linkedin_access_token']);
                    $token_updated = true;
                }
                if (isset($_REQUEST['linkedin_refresh_token'])) {
                    $options['linkedin_refresh_token'] = $this->sanitizeField($_REQUEST['linkedin_refresh_token']);
                }
                if (isset($_REQUEST['linkedin_username'])) {
                    $options['linkedin_username'] = $this->sanitizeField($_REQUEST['linkedin_username']);
                }
                if (isset($_REQUEST['linkedin_display_name'])) {
                    $options['linkedin_display_name'] = $this->sanitizeField($_REQUEST['linkedin_display_name']);
                }
                if (isset($_REQUEST['linkedin_member_id'])) {
                    $options['linkedin_member_id'] = $this->sanitizeField($_REQUEST['linkedin_member_id']);
                }
                if (isset($_REQUEST['linkedin_userpic'])) {
                    $options['linkedin_userpic'] = $this->sanitizeUrl($_REQUEST['linkedin_userpic']);
                }
                if (isset($_REQUEST['linkedin_org_id'])) {
                    $options['linkedin_org_id'] = $this->sanitizeField($_REQUEST['linkedin_org_id']);
                }
                if (isset($_REQUEST['linkedin_org_name'])) {
                    $options['linkedin_org_name'] = $this->sanitizeField($_REQUEST['linkedin_org_name']);
                }
                if (isset($_REQUEST['linkedin_organizations'])) {
                    $orgs = json_decode(wp_unslash($_REQUEST['linkedin_organizations']), true);
                    if (is_array($orgs)) {
                        array_walk_recursive($orgs, function(&$val) {
                            if (is_string($val)) {
                                $val = $this->sanitizeField($val);
                            }
                        });
                        $options['linkedin_organizations'] = $orgs;
                    }
                }
                if (isset($_REQUEST['expires'])) {
                    $expires_in = (int) $_REQUEST['expires'];
                    $expires_timestamp = time() + $expires_in;
                    $options['linkedin_expires_in'] = $expires_timestamp;
                }

                // Save the options
                $this->setOption('options', $options, true);

                // If we have a token, fetch and save user info
                if ($token_updated) {
                    try {
                        $token = trim($options['linkedin_access_token']);
                        if ($token !== '') {
                            $user_info = $this->fetchAndSaveLinkedInUserInfo($token);

                            // If we got user info back, save it to options
                            if (is_array($user_info)) {
                                $options['linkedin_username'] = isset($user_info['username']) ? $this->sanitizeField($user_info['username']) : '';
                                $options['linkedin_display_name'] = isset($user_info['display_name']) ? $this->sanitizeField($user_info['display_name']) : '';
                                $options['linkedin_member_id'] = isset($user_info['member_id']) ? $this->sanitizeField($user_info['member_id']) : '';
                                $options['linkedin_userpic'] = isset($user_info['userpic']) ? $this->sanitizeUrl($user_info['userpic']) : '';
                                $this->setOption('options', $options, true);
                            }
                        }
                    } catch (\Exception $e) {
                        error_log('[Flow-Flow] Error fetching LinkedIn user info: ' . $e->getMessage());
                    }
                }

                // Clean cache to ensure new settings take effect
                $this->cleanByFeedType('linkedin');
            } else {
                // Handle other authentication types
                $fieldName = $type;
                $whitelist = [
                    'foursquare_access_token',
                    'instagram_access_token'
                ];
                if (in_array($fieldName, $whitelist, true)) {
                    $options = $this->getOption('options', true);
                    if (!is_array($options))
                        $options = [];
                    if (isset($_REQUEST[$fieldName])) {
                        $options[$fieldName] = $this->sanitizeField($_REQUEST[$fieldName]);
                        $this->setOption('options', $options, true);
                    }
                }
            }

            // Only redirect if headers haven't been sent yet
            if (!headers_sent()) {
                header('Location: ' . admin_url('admin.php?page=flow-flow-admin'), true, 301);
            }
            exit();
        }
        die();
    }

    /**
     * Secure AJAX handler to fetch Facebook connected user info and save it to options.
     * Expects: action=flow_flow_fetch_facebook_user_info, security (nonce).
     */
    public function fetch_facebook_user_info()
    {
        if (FF_USE_WP) {
            if (!current_user_can('manage_options') || !check_ajax_referer('flow_flow_nonce', 'security', false)) {
                wp_send_json_error(['error' => 'not_allowed'], 403);
            }
        }

        try {
            // Prefer extended token from cache (handles own app vs our app automatically)
            /** @var LAFacebookCacheManager $facebook_cache */
            $facebook_cache = $this->context['facebook_cache'];
            $token = $facebook_cache->getAccessToken();

            if (empty($token)) {
                // fallback to raw fb auth options
                $auth = $this->getOption('fb_auth_options', true);
                $token = isset($auth['facebook_access_token']) ? trim($auth['facebook_access_token']) : '';
            }

            if (empty($token)) {
                wp_send_json_error(['error' => 'Facebook access token is missing'], 400);
            }

            $info = $this->requestFacebookUserInfo($token);
            if (!is_array($info)) {
                error_log('[Flow-Flow Facebook] Failed to parse user info');
                wp_send_json_error(['error' => 'Failed to get Facebook user info'], 500);
            }

            // Persist into options for UI
            $options = $this->getOption('options', true);
            if (!is_array($options))
                $options = [];
            $options['facebook_user_id'] = isset($info['id']) ? $info['id'] : '';
            $options['facebook_user_name'] = isset($info['name']) ? $info['name'] : '';
            $options['facebook_userpic'] = isset($info['picture']) ? $info['picture'] : '';
            $this->setOption('options', $options, true);

            $debug = isset($info['_debug']) ? $info['_debug'] : [];
            wp_send_json_success([
                'data' => [
                    'id' => $info['id'],
                    'name' => $info['name'],
                    'picture' => $info['picture'],
                ],
                'debug' => $debug
            ]);
        } catch (\Exception $e) {
            error_log('[Flow-Flow Facebook] fetch_facebook_user_info error: ' . $e->getMessage());
            wp_send_json_error(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Call Facebook Graph API to fetch current user info
     * @param string $accessToken
     * @return array{id:string,name:string,picture:string}|null
     * @throws \Exception
     */
    private function requestFacebookUserInfo($accessToken)
    {
        // Using unversioned /me endpoint; Facebook will route to default app version
        $url = 'https://graph.facebook.com/me?fields=id,name,picture.width(100).height(100)&access_token=' . urlencode($accessToken);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception('cURL error: ' . $error);
        }
        if ($httpCode !== 200) {
            $snippet = is_string($response) ? substr($response, 0, 256) : '';
            error_log('[Flow-Flow Facebook] API error HTTP ' . $httpCode . ' ' . $snippet);
            throw new \Exception('Facebook API error HTTP ' . $httpCode . ' ' . $snippet);
        }
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('JSON decode failed: ' . json_last_error_msg());
        }
        if (!isset($data['id']))
            return null;
        $pic = '';
        if (isset($data['picture']['data']['url'])) {
            $pic = $data['picture']['data']['url'];
        }
        return [
            'id' => $data['id'],
            'name' => isset($data['name']) ? $data['name'] : '',
            'picture' => $pic,
            '_debug' => [
                'httpCode' => $httpCode,
                'responseSnippet' => is_string($response) ? substr($response, 0, 256) : '',
            ],
        ];
    }

    /**
     * Secure AJAX handler to fetch TikTok user info and save it to options.
     * Expects: action=flow_flow_fetch_tiktok_user_info, security (nonce), optional token override.
     */
    public function fetch_tiktok_user_info()
    {
        // Nonce and capability verification
        $slug_down = $this->context['slug_down'];
        if (!isset($_REQUEST['security']) || !wp_verify_nonce($_REQUEST['security'], $slug_down . '-nonce')) {
            wp_send_json_error(['error' => 'Invalid nonce'], 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'Not allowed'], 403);
        }

        $options = $this->getOption('options', true);
        $token = isset($_REQUEST['tiktok_access_token']) && $_REQUEST['tiktok_access_token'] !== ''
            ? trim($_REQUEST['tiktok_access_token'])
            : (isset($options['tiktok_access_token']) ? trim($options['tiktok_access_token']) : '');

        if ($token === '') {
            wp_send_json_error(['error' => 'TikTok access token is missing'], 400);
        }

        try {
            $info = $this->fetchAndSaveTikTokUserInfo($token);
            if (is_array($info)) {
                wp_send_json_success(['data' => $info]);
            }
            wp_send_json_error(['error' => 'Failed to get TikTok user info']);
        } catch (\Exception $e) {
            wp_send_json_error(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Secure AJAX handler to fetch LinkedIn user info and save it to options.
     * Expects: action=flow_flow_fetch_linkedin_user_info, security (nonce), optional token override.
     */
    public function fetch_linkedin_user_info()
    {
        // Nonce and capability verification
        $slug_down = $this->context['slug_down'];
        if (!isset($_REQUEST['security']) || !wp_verify_nonce($_REQUEST['security'], $slug_down . '-nonce')) {
            wp_send_json_error(['error' => 'Invalid nonce'], 403);
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'Not allowed'], 403);
        }

        $options = $this->getOption('options', true);
        $token = isset($_REQUEST['linkedin_access_token']) && $_REQUEST['linkedin_access_token'] !== ''
            ? trim($_REQUEST['linkedin_access_token'])
            : (isset($options['linkedin_access_token']) ? trim($options['linkedin_access_token']) : '');

        if ($token === '') {
            wp_send_json_error(['error' => 'LinkedIn access token is missing'], 400);
        }

        try {
            $info = $this->fetchAndSaveLinkedInUserInfo($token);
            if (is_array($info)) {
                wp_send_json_success(['data' => $info]);
            }
            wp_send_json_error(['error' => 'Failed to get LinkedIn user info']);
        } catch (\Exception $e) {
            wp_send_json_error(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Fetch LinkedIn user info via LinkedIn /v2/me API and persist to options.
     * Works with Community Management API tokens (no OpenID Connect required).
     * Returns associative array with member_id, username, display_name, userpic on success.
     * @param string $accessToken
     * @return array|null
     * @throws \Exception
     */
    private function fetchAndSaveLinkedInUserInfo($accessToken)
    {
        $url = 'https://api.linkedin.com/v2/me';
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'X-Restli-Protocol-Version: 2.0.0',
        ];

        // Perform request via cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception('cURL error: ' . $error);
        }
        if ($httpCode !== 200) {
            // include small snippet for diagnostics
            $snippet = is_string($response) ? substr($response, 0, 256) : '';
            throw new \Exception('LinkedIn API error HTTP ' . $httpCode . ' ' . $snippet);
        }
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('JSON decode failed: ' . json_last_error_msg());
        }

        // /v2/me returns: id, localizedFirstName, localizedLastName, profilePicture, etc.
        $firstName = isset($data['localizedFirstName']) ? $data['localizedFirstName'] : '';
        $lastName  = isset($data['localizedLastName']) ? $data['localizedLastName'] : '';
        $displayName = trim($firstName . ' ' . $lastName);
        $userpic = '';
        if (isset($data['profilePicture']['displayImage~']['elements'])) {
            $elements = $data['profilePicture']['displayImage~']['elements'];
            $last = end($elements);
            if (isset($last['identifiers'][0]['identifier'])) {
                $userpic = $last['identifiers'][0]['identifier'];
            }
        }

        $info = [
            'member_id' => isset($data['id']) ? $data['id'] : '',
            'username' => $displayName,
            'display_name' => $displayName,
            'userpic' => $userpic,
        ];

        // Persist into options for immediate UI rendering
        $options = $this->getOption('options', true);
        if (!is_array($options))
            $options = [];
        $options['linkedin_username'] = $info['username'];
        $options['linkedin_display_name'] = $info['display_name'];
        $options['linkedin_member_id'] = $info['member_id'];
        $options['linkedin_userpic'] = $info['userpic'];
        $this->setOption('options', $options, true);

        return $info;
    }

    /**
     * Fetch TikTok user info via TikTok Open API and persist to options.
     * Returns associative array with open_id, username, display_name, avatar_url on success.
     * @param string $accessToken
     * @return array|null
     * @throws \Exception
     */
    private function fetchAndSaveTikTokUserInfo($accessToken)
    {
        $url = 'https://open.tiktokapis.com/v2/user/info/?fields=open_id,display_name,username,avatar_url,avatar_url_100,avatar_large_url';
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json'
        ];

        // Perform request via cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception('cURL error: ' . $error);
        }
        if ($httpCode !== 200) {
            // include small snippet for diagnostics
            $snippet = is_string($response) ? substr($response, 0, 256) : '';
            throw new \Exception('TikTok API error HTTP ' . $httpCode . ' ' . $snippet);
        }
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('JSON decode failed: ' . json_last_error_msg());
        }

        if (!isset($data['data']['user']) || !is_array($data['data']['user'])) {
            return null;
        }

        $u = $data['data']['user'];
        $avatar = '';
        if (!empty($u['avatar_url']))
            $avatar = $u['avatar_url'];
        elseif (!empty($u['avatar_url_100']))
            $avatar = $u['avatar_url_100'];
        elseif (!empty($u['avatar_large_url']))
            $avatar = $u['avatar_large_url'];

        $info = [
            'open_id' => isset($u['open_id']) ? $u['open_id'] : '',
            'username' => isset($u['username']) ? $u['username'] : '',
            'display_name' => isset($u['display_name']) ? $u['display_name'] : '',
            'avatar_url' => $avatar,
        ];

        // Persist into options for immediate UI rendering
        $options = $this->getOption('options', true);
        if (!is_array($options))
            $options = [];
        $options['tiktok_username'] = $info['username'];
        $options['tiktok_display_name'] = $info['display_name'];
        $options['tiktok_open_id'] = $info['open_id'];
        $options['tiktok_userpic'] = $info['avatar_url'];
        $this->setOption('options', $options, true);

        return $info;
    }

    /**
     * Public wrapper to update TikTok user info and persist into options.
     * Useful for cron flows (e.g., after token refresh) to sync avatar/username.
     * @param string $accessToken
     * @return array|null
     */
    public function updateTikTokUserInfo($accessToken)
    {
        return $this->fetchAndSaveTikTokUserInfo($accessToken);
    }

    /**
     * Refresh TikTok access token using long-lived refresh token saved in options.
     * Updates: tiktok_access_token, tiktok_refresh_token, tiktok_expires_in and user info.
     * @param bool $force Force refresh regardless of current expiry time
     * @return bool true on success, false otherwise
     * @throws Exception
     */
    public function refreshTikTokAccessToken($force = false)
    {
        error_log('[Flow-Flow] Starting TikTok token refresh (force: ' . ($force ? 'true' : 'false') . ')');

        $options = $this->getOption('options', true);
        if (!is_array($options)) {
            $options = [];
            error_log('[Flow-Flow] No options found, initializing empty array');
        }

        $refresh = isset($options['tiktok_refresh_token']) ? trim($options['tiktok_refresh_token']) : '';
        $expires = isset($options['tiktok_expires_in']) ? (int) $options['tiktok_expires_in'] : 0;
        $now = time();

        error_log(sprintf(
            '[Flow-Flow] Current token - Expires in: %s, Now: %s',
            $expires > 0 ? ($expires - $now) . ' seconds' : 'never',
            date('Y-m-d H:i:s', $now)
        ));

        if ($refresh === '') {
            error_log('[Flow-Flow] No refresh token available, cannot refresh');
            return false;
        }

        if (!$force && $expires > 0) {
            $threshold = 2 * DAY_IN_SECONDS; // refresh if < 2 days left
            $timeLeft = $expires - $now;

            if ($timeLeft > $threshold) {
                error_log(sprintf(
                    '[Flow-Flow] Token still valid for %d days, skipping refresh',
                    ceil($timeLeft / DAY_IN_SECONDS)
                ));
                return false; // not yet time
            }

            error_log(sprintf(
                '[Flow-Flow] Token expires in %d hours, refreshing...',
                ceil($timeLeft / HOUR_IN_SECONDS)
            ));
        } else if ($force) {
            error_log('[Flow-Flow] Forcing token refresh');
        } else {
            error_log('[Flow-Flow] No expiration time set, refreshing token to be safe');
        }

        // Use the same third-party authorization server as AUTH tab to refresh
        $result = $this->triggerTikTokRefreshViaAuthServer($refresh);

        if ($result) {
            // After successful refresh, ensure we have the latest user info
            try {
                // Get the latest options after refresh
                $options = $this->getOption('options', true);
                $accessToken = isset($options['tiktok_access_token']) ? trim($options['tiktok_access_token']) : '';

                if ($accessToken !== '') {
                    error_log('[Flow-Flow] Fetching updated user info after token refresh');

                    // Fetch and save user info with the new token
                    $user_info = $this->fetchAndSaveTikTokUserInfo($accessToken);

                    if (is_array($user_info)) {
                        // Update options with fresh user info
                        $options['tiktok_username'] = $user_info['username'] ?? '';
                        $options['tiktok_display_name'] = $user_info['display_name'] ?? '';
                        $options['tiktok_open_id'] = $user_info['open_id'] ?? '';
                        $options['tiktok_userpic'] = $user_info['avatar_url'] ?? '';
                        $this->setOption('options', $options, true);

                        error_log('[Flow-Flow] Successfully updated user info after token refresh');

                        // Invalidate cache lifetime so feeds re-fetch with the new token.
                        // Do NOT use cleanByFeedType() — it deletes all cached posts.
                        $this->resetCacheLifetimeByFeedType('tiktok');
                    }
                }
            } catch (\Exception $e) {
                error_log('[Flow-Flow] Error updating user info after token refresh: ' . $e->getMessage());
                // Don't fail the token refresh if user info update fails
            }
        } else {
            error_log('[Flow-Flow] Token refresh failed, skipping user info update');
        }

        error_log('[Flow-Flow] Token refresh ' . ($result ? 'succeeded' : 'failed'));
        return $result;
    }

    /**
     * Trigger TikTok refresh via Looks-Awesome auth server, same as AUTH tab flow.
     * The auth server will call back our social_auth endpoint with new tokens.
     * @param string $refreshToken
     * @return bool
     */
    public function triggerTikTokRefreshViaAuthServer($refreshToken)
    {
        error_log('[Flow-Flow] Triggering TikTok token refresh via auth server');

        if (empty($refreshToken)) {
            error_log('[Flow-Flow] Error: Empty refresh token provided');
            return false;
        }

        $slug_down = isset($this->context['slug_down']) ? $this->context['slug_down'] : 'flow_flow';
        $backUrl = (isset($this->context['ajax_url']) ? $this->context['ajax_url'] : $this->context['admin_url']) . '?action=' . $slug_down . '_social_auth';

        $params = [
            'back' => $backUrl,
            'refresh_token' => $refreshToken,
            'cron' => '1' // Indicate this is a background refresh
        ];

        $url = 'https://flow.looks-awesome.com/service/auth/callback/tiktok-auth.php?' . http_build_query($params);

        error_log(sprintf(
            '[Flow-Flow] Sending refresh request to: %s (token length: %d chars)',
            'https://flow.looks-awesome.com/service/auth/callback/tiktok-auth.php?[params]',
            strlen($refreshToken)
        ));

        // Fire-and-forget GET; the auth server will hit our back URL asynchronously
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_USERAGENT => 'Flow-Flow-WordPress/1.0',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-FF-Request-Source: cron'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);

        if ($errno || $error) {
            error_log(sprintf(
                '[Flow-Flow] cURL error during token refresh (code %d): %s',
                $errno,
                $error
            ));
            curl_close($ch);
            return false;
        }

        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        error_log(sprintf(
            '[Flow-Flow] Token refresh response - Status: %d, Content-Type: %s',
            $httpCode,
            $contentType
        ));

        if ($httpCode >= 200 && $httpCode < 300) {
            // For successful responses, check if we got JSON with an error
            if (strpos($contentType, 'application/json') !== false) {
                $json = json_decode($response, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($json['error'])) {
                    error_log(sprintf(
                        '[Flow-Flow] Token refresh API error: %s',
                        $json['error_description'] ?? $json['error']
                    ));
                    return false;
                }
            }
            error_log('[Flow-Flow] Token refresh request successful');
            return true;
        }
        // Accept redirects as success (3xx)
        elseif ($httpCode >= 300 && $httpCode < 400) {
            error_log('[Flow-Flow] Token refresh request redirected, assuming success');
            return true;
        }
        // Handle error responses
        else {
            error_log(sprintf(
                '[Flow-Flow] Token refresh failed with status %d: %s',
                $httpCode,
                substr($response, 0, 500) // Log first 500 chars of response
            ));
            return false;
        }
    }

    /**
     * @param $settings
     *
     * @return mixed
     * @throws Exception
     */
    protected function saveGeneralSettings($settings)
    {
        // Get existing options to preserve fields that aren't in the form (like user info)
        $existing_options = $this->getOption('options', true);
        if (!is_array($existing_options)) {
            $existing_options = [];
        }

        // Merge new settings into existing options
        if (isset($settings['flow_flow_options']) && is_array($settings['flow_flow_options'])) {
            $settings['flow_flow_options'] = array_merge($existing_options, $settings['flow_flow_options']);
        }

        $settings = parent::saveGeneralSettings($settings);
        //TODO move all auth settings from the general setting to other setting
        $this->setOption('fb_auth_options', $settings['flow_flow_fb_auth_options'], true);
        return $settings;
    }

    protected function customizeResponse(&$response)
    {
        /** @var LAFacebookCacheManager $facebookCache */
        $facebookCache = $this->context['facebook_cache'];
        if ($this->facebook_changed) {
            $facebookCache->clean();
        }
        $extendedToken = $facebookCache->getAccessToken();
        $this->conn()->commit();

        $response['fb_extended_token'] = $extendedToken;
    }

    protected function clean_cache($options)
    {
        $facebook_changed = false;
        $force_load_cache = false;
        $general = $options['flow_flow_options'];
        $old = $this->getOption('options', true);

        if (is_array($old) && sizeof($old) > 0) {
            if (
                $general['oauth_access_token'] != $old['oauth_access_token'] ||
                $general['oauth_access_token_secret'] != $old['oauth_access_token_secret'] ||
                $general['consumer_secret'] != $old['consumer_secret'] ||
                $general['consumer_key'] != $old['consumer_key']
            ) {
                $this->cleanByFeedType('twitter');
                $force_load_cache = true;
            }
        } else if (
            trim($general['oauth_access_token']) == '' &&
            trim($general['oauth_access_token_secret']) == '' &&
            trim($general['consumer_secret']) == '' &&
            trim($general['consumer_key']) == ''
        ) {
            $this->cleanByFeedType('twitter');
            $force_load_cache = true;
        }

        if (is_array($old) && sizeof($old) > 0) {
            if (
                $general['foursquare_client_id'] != $old['foursquare_client_id'] ||
                $general['foursquare_client_secret'] != $old['foursquare_client_secret']
            ) {
                $this->cleanByFeedType('foursquare');
                $force_load_cache = true;
            }
        } else if (trim($general['foursquare_client_id']) == '' && trim($general['foursquare_client_secret']) == '') {
            $this->cleanByFeedType('foursquare');
            $force_load_cache = true;
        }

        //		if (is_array($old) && sizeof($old) > 0){
//			if ($general['instagram_access_token'] != $old['instagram_access_token']){
//				$this->cleanByFeedType('instagram');
//				$force_load_cache = true;
//			}
//		} else if (trim($general['instagram_access_token']) == ''){
//			$this->cleanByFeedType('instagram');
//			$force_load_cache = true;
//		}

        if (is_array($old) && sizeof($old) > 0) {
            if ($general['google_api_key'] != $old['google_api_key']) {
                $this->cleanByFeedType('google');
                $force_load_cache = true;
            }
        } else if (trim($general['google_api_key']) == '') {
            $this->cleanByFeedType('google');
            $force_load_cache = true;
        }

        // TikTok: clean cache when global token changes
        if (is_array($old) && sizeof($old) > 0) {
            if (@$general['tiktok_access_token'] != @$old['tiktok_access_token']) {
                $this->cleanByFeedType('tiktok');
                $force_load_cache = true;

                // If token is being cleared, also clear all TikTok user data
                if (trim(@$general['tiktok_access_token']) == '') {
                    $general['tiktok_username'] = '';
                    $general['tiktok_display_name'] = '';
                    $general['tiktok_open_id'] = '';
                    $general['tiktok_userpic'] = '';
                    $general['tiktok_refresh_token'] = '';
                    $general['tiktok_expires_in'] = '';
                    $general['tiktok_scopes'] = '';
                    // Update the options array to reflect the cleared fields
                    $options['flow_flow_options'] = $general;
                }
            }
        } else if (trim(@$general['tiktok_access_token']) == '') {
            $this->cleanByFeedType('tiktok');
            $force_load_cache = true;

            // Clear all TikTok user data
            $general['tiktok_username'] = '';
            $general['tiktok_display_name'] = '';
            $general['tiktok_open_id'] = '';
            $general['tiktok_userpic'] = '';
            $general['tiktok_refresh_token'] = '';
            $general['tiktok_expires_in'] = '';
            $general['tiktok_scopes'] = '';
            // Update the options array to reflect the cleared fields
            $options['flow_flow_options'] = $general;
        }

        // LinkedIn: clean cache when global token changes
        if (is_array($old) && sizeof($old) > 0) {
            if (@$general['linkedin_access_token'] != @$old['linkedin_access_token']) {
                $this->cleanByFeedType('linkedin');
                $force_load_cache = true;

                // If token is being cleared, also clear all LinkedIn user data
                if (trim(@$general['linkedin_access_token']) == '') {
                    $general['linkedin_username'] = '';
                    $general['linkedin_display_name'] = '';
                    $general['linkedin_member_id'] = '';
                    $general['linkedin_userpic'] = '';
                    $general['linkedin_refresh_token'] = '';
                    $general['linkedin_expires_in'] = '';
                    // Update the options array to reflect the cleared fields
                    $options['flow_flow_options'] = $general;
                }
            }
        } else if (trim(@$general['linkedin_access_token']) == '') {
            $this->cleanByFeedType('linkedin');
            $force_load_cache = true;

            // Clear all LinkedIn user data
            $general['linkedin_username'] = '';
            $general['linkedin_display_name'] = '';
            $general['linkedin_member_id'] = '';
            $general['linkedin_userpic'] = '';
            $general['linkedin_refresh_token'] = '';
            $general['linkedin_expires_in'] = '';
            // Update the options array to reflect the cleared fields
            $options['flow_flow_options'] = $general;
        }

        $fb = $options['flow_flow_fb_auth_options'];
        $old = $this->getOption('fb_auth_options', true);
        $fb_use_own = LASettingsUtils::YepNope2ClassicStyleSafe($fb, 'facebook_use_own_app', true);
        $old_use_own = LASettingsUtils::YepNope2ClassicStyleSafe($old, 'facebook_use_own_app', true);
        if (is_array($old) && sizeof($old) > 0) {
            if ($fb_use_own != $old_use_own) {
                //$this->cleanByFeedType('facebook');
                $force_load_cache = true;
                $facebook_changed = true;
            } else {
                if ($fb_use_own) {
                    if (
                        $fb['facebook_access_token'] != $old['facebook_access_token'] ||
                        $fb['facebook_app_id'] != $old['facebook_app_id'] ||
                        $fb['facebook_app_secret'] != $old['facebook_app_secret']
                    ) {
                        //$this->cleanByFeedType('facebook');
                        $force_load_cache = true;
                        $facebook_changed = true;
                    }
                } else {
                    if ($fb['facebook_access_token'] != $old['facebook_access_token']) {
                        //$this->cleanByFeedType('facebook');
                        $force_load_cache = true;
                        $facebook_changed = true;
                    }
                }
            }
        } else {
            if (
                (!$fb_use_own && trim($fb['facebook_access_token']) == '') ||
                ($fb_use_own && trim($fb['facebook_access_token']) == '' && trim($fb['facebook_app_id']) == '' && trim($fb['facebook_app_secret']) == '')
            ) {
                //$this->cleanByFeedType('facebook');
                $force_load_cache = true;
                $facebook_changed = true;

                // Clear Facebook user data when token is cleared
                $general['facebook_user_name'] = '';
                $general['facebook_userpic'] = '';
                // Update the options array to reflect the cleared fields
                $options['flow_flow_options'] = $general;
            }
        }

        // Also check if Facebook token is being explicitly cleared (even if fb_auth_options exist)
        if (is_array($old) && sizeof($old) > 0) {
            if (!empty($old['facebook_access_token']) && trim($fb['facebook_access_token']) == '') {
                // Token was cleared - remove user data
                $general['facebook_user_name'] = '';
                $general['facebook_userpic'] = '';
                // Update the options array to reflect the cleared fields
                $options['flow_flow_options'] = $general;

                // Clear cached IDs
                $cache = FFFeedUtils::getCache($this->context);
                $cache->delete(md5('facebookPageId'));
                $cache->delete(md5('instagram_business_account'));
            }
        }
        $this->facebook_changed = $facebook_changed;
        return $force_load_cache;
    }

    public function repairDB()
    {
        try {
            $mm = new FFDBMigrationManager($this->context);
            $mm->migrate(true);
        } catch (\Exception $e) {
            error_log('[Flow-Flow] Error during repairDB: ' . $e->getMessage());
        }
    }

    protected function refreshCache($streamId, $force_load_cache = false)
    {
        //TODO: anf: refactor
        LABase::get_instance($this->context)->refreshCache($streamId, $force_load_cache);
    }

    /**
     * Public AJAX endpoint: refresh multiple feeds by IDs
     * Accepts POST param 'feed_ids' as array or comma-separated list
     * Security: nonce verification, rate limiting, IP throttling
     */
    public function refresh_feeds()
    {
        $start_time = microtime(true);

        // Security: Verify nonce to prevent CSRF attacks
        if (!isset($_REQUEST['_ff_nonce']) || !wp_verify_nonce($_REQUEST['_ff_nonce'], 'ff_refresh_feeds')) {
            error_log("Flow-Flow refresh_feeds: Invalid or missing nonce");
            wp_send_json_error([
                'message' => 'Security verification failed',
                'code' => 'invalid_nonce'
            ], 403);
            return;
        }

        // Security: Rate limiting per IP address to prevent abuse
        $client_ip = $this->getClientIP();
        $rate_limit_key = 'ff_refresh_feeds_' . md5($client_ip);
        $rate_limit = get_transient($rate_limit_key);

        if ($rate_limit !== false) {
            $attempts = (int) $rate_limit;
            // Allow max 10 requests per minute per IP
            if ($attempts >= 10) {
                error_log("Flow-Flow refresh_feeds: Rate limit exceeded for IP: {$client_ip}");
                wp_send_json_error([
                    'message' => 'Too many requests. Please wait before trying again.',
                    'code' => 'rate_limit_exceeded',
                    'retry_after' => 60
                ], 429);
                return;
            }
            set_transient($rate_limit_key, $attempts + 1, 60);
        } else {
            set_transient($rate_limit_key, 1, 60);
        }

        $ids = isset($_REQUEST['feed_ids']) ?
            (is_array($_REQUEST['feed_ids']) ? $_REQUEST['feed_ids'] : explode(',', $_REQUEST['feed_ids'])) : [];

        // Security: Validate and limit number of feeds that can be refreshed at once
        $ids = array_slice(array_filter(array_map('trim', $ids)), 0, 20);

        if (empty($ids)) {
            wp_send_json_error([
                'message' => 'No valid feed IDs provided',
                'code' => 'missing_feed_ids'
            ], 400);
            return;
        }

        error_log("Flow-Flow refresh_feeds: Starting refresh for feed IDs: " . json_encode($ids));

        $refreshed = 0;
        $feedsPayload = [];

        // Initialize data and Flow-Flow core
        $this->dataInit(true, false);
        $ff = LABase::get_instance($this->context);
        $sources = $this->sources();
        $conn = $this->conn();

        foreach ($ids as $id) {
            $feed_start = microtime(true);
            try {
                $id = trim($id);
                if (empty($id)) {
                    error_log("Flow-Flow refresh_feeds: Skipping empty feed ID");
                    continue;
                }

                error_log("Flow-Flow refresh_feeds: Processing feed ID: {$id}");

                // Get the feed from sources
                if (!isset($sources[$id])) {
                    error_log("Flow-Flow refresh_feeds: Feed not found: {$id}");
                    $feedsPayload[] = [
                        'feed_id' => $id,
                        'status' => 'error',
                        'message' => 'Feed not found'
                    ];
                    continue;
                }

                $feed = (object) $sources[$id];
                error_log("Flow-Flow refresh_feeds: Feed {$id} found, type: " . ($feed->type ?? 'unknown'));

                // Get the stream ID for this feed from the streams_sources table
                $streamId = $conn->getOne(
                    'SELECT `stream_id` FROM ?n WHERE `feed_id` = ?s LIMIT 1',
                    $this->streams_sources_table_name,
                    $id
                );

                if (!$streamId) {
                    error_log("Flow-Flow refresh_feeds: No stream found for feed {$id}");
                    throw new \Exception('No stream associated with this feed');
                }

                error_log("Flow-Flow refresh_feeds: Feed {$id} belongs to stream: {$streamId}");

                // Rebuild cache using same logic as admin UI "Rebuild cache" button
                error_log("Flow-Flow refresh_feeds: Cleaning old cache for feed {$id}");
                $this->cleanFeed($id);

                error_log("Flow-Flow refresh_feeds: Calling refreshCache4Source for feed {$id}");
                $boosted = LASettingsUtils::YepNope2ClassicStyle($feed->boosted ?? 'nope', false);

                // Token fallback: Try to refresh, but don't fail if token is invalid
                try {
                    $this->refreshCache4Source($id, true, $boosted);
                    $refreshed++;
                    error_log("Flow-Flow refresh_feeds: Cache rebuild triggered for feed {$id}");
                } catch (\Exception $tokenException) {
                    // Log token error but continue - use cached data if available
                    error_log("Flow-Flow refresh_feeds: Token error for feed {$id}, using cached data: " . $tokenException->getMessage());

                    // Mark feed status but don't fail completely
                    $feedsPayload[] = [
                        'feed_id' => $id,
                        'status' => 'cached',
                        'message' => 'Using cached content (token refresh failed)',
                        'posts' => $this->getPostsForFeed($id),
                        'post_count' => count($this->getPostsForFeed($id)),
                        'last_update' => time()
                    ];
                    continue; // Skip to next feed
                }

                // Query posts from database after refresh
                $posts = $this->getPostsForFeed($id);
                $post_count = count($posts);

                error_log("Flow-Flow refresh_feeds: Retrieved {$post_count} posts from database for feed {$id}");

                $feedsPayload[] = [
                    'feed_id' => $id,
                    'status' => 'success',
                    'stream_id' => $streamId,
                    'posts' => $posts,
                    'post_count' => $post_count,
                    'last_update' => time()
                ];

                $feed_time = microtime(true) - $feed_start;
                error_log("Flow-Flow refresh_feeds: Feed {$id} refreshed successfully with {$post_count} posts in {$feed_time}s");

            } catch (\Throwable $e) {
                $errorMessage = sprintf(
                    "Flow-Flow Error refreshing feed %s: %s\nStack Trace:\n%s",
                    $id,
                    $e->getMessage(),
                    $e->getTraceAsString()
                );
                error_log($errorMessage);

                // Fallback: Try to return cached posts even on error
                $cachedPosts = $this->getPostsForFeed($id);
                if (!empty($cachedPosts)) {
                    error_log("Flow-Flow refresh_feeds: Using cached data for feed {$id} after error");
                    $feedsPayload[] = [
                        'feed_id' => $id,
                        'status' => 'cached',
                        'message' => 'Using cached content (refresh failed)',
                        'posts' => $cachedPosts,
                        'post_count' => count($cachedPosts),
                        'last_update' => time()
                    ];
                } else {
                    // Add error to response only if no cached data available
                    $feedsPayload[] = [
                        'feed_id' => $id,
                        'status' => 'error',
                        'message' => $e->getMessage()
                    ];
                }
            }
        }

        $total_time = microtime(true) - $start_time;
        $total_requested = count($ids);
        error_log("Flow-Flow refresh_feeds: Completed. Refreshed {$refreshed}/{$total_requested} feeds in {$total_time}s");

        $response = [
            'success' => true,
            'data' => [
                'ok' => $refreshed > 0,
                'refreshed' => $refreshed,
                'total_requested' => count($ids),
                'feeds' => $feedsPayload,
                'debug' => [
                    'memory_usage' => memory_get_usage(true) / 1024 / 1024 . 'MB',
                    'peak_memory' => memory_get_peak_usage(true) / 1024 / 1024 . 'MB',
                    'execution_time' => $total_time . 's'
                ]
            ]
        ];

        wp_send_json_success($response['data']);
    }

    public function getLoadCacheUrl($streamId = null, $force = false)
    {
        $ajax_url = $this->context['admin_url'];
        return $ajax_url . "?action=ff_load_cache&feed_id={$streamId}&force={$force}";
    }

    /**
     * Get posts for a specific feed with media information
     * Matches the format returned by fetch_posts/process()
     * 
     * @param string $feedId
     * @return array
     */
    private function getPostsForFeed($feedId)
    {
        $conn = $this->conn();

        // Get posts from the posts table including image fields
        $posts = $conn->getAll(
            'SELECT `post_id`, `feed_id`, `post_type`, `post_timestamp`, `post_text`, 
                    `post_header`, `post_additional`, `post_permalink`,
                    `image_url`, `image_width`, `image_height`
             FROM ?n 
             WHERE `feed_id` = ?s 
             ORDER BY `post_timestamp` DESC 
             LIMIT 50',
            $this->posts_table_name,
            $feedId
        );

        if (empty($posts)) {
            return [];
        }

        // Get media for all posts in one query
        $postIds = array_column($posts, 'post_id');
        $mediaData = $conn->getAll(
            'SELECT `post_id`, `media_url`, `media_width`, `media_height`, `media_type`
             FROM ?n 
             WHERE `feed_id` = ?s AND `post_id` IN (?a)',
            $this->post_media_table_name,
            $feedId,
            $postIds
        );

        // Group media by post_id (first media item only, matching frontend expectations)
        $mediaByPost = [];
        foreach ($mediaData as $item) {
            if (!isset($mediaByPost[$item['post_id']])) {
                $mediaByPost[$item['post_id']] = [
                    'url' => $item['media_url'],
                    'width' => $item['media_width'],
                    'height' => $item['media_height'],
                    'type' => $item['media_type']
                ];
            }
        }

        // Build the response array matching the fetch_posts format
        $result = [];
        foreach ($posts as $post) {
            $postId = $post['post_id'];
            $additional = !empty($post['post_additional']) ? json_decode($post['post_additional'], true) : null;

            $postItem = [
                'id' => $postId,
                'post_id' => $postId,
                'feed' => $post['feed_id'],
                'feed_id' => $post['feed_id'],
                'type' => $post['post_type'],
                'timestamp' => $post['post_timestamp'],
                'text' => $post['post_text'],
                'header' => $post['post_header'],
                'link' => $post['post_permalink'],
                'permalink' => $post['post_permalink']
            ];

            // Add img property if image_url exists (matches LACacheManager logic)
            if (!empty($post['image_url'])) {
                $postItem['img'] = [
                    'url' => $post['image_url'],
                    'width' => $post['image_width'],
                    'height' => $post['image_height'],
                    'type' => 'image'
                ];
                // Initially set media to img
                $postItem['media'] = $postItem['img'];
            }

            // Override media if media_url exists (matches LACacheManager logic)
            if (isset($mediaByPost[$postId])) {
                $postItem['media'] = $mediaByPost[$postId];
            }

            if ($additional !== null) {
                $postItem['additional'] = $additional;
            }

            $result[] = $postItem;
        }

        return $result;
    }

    /**
     * Get client IP address for rate limiting
     * Handles various proxy configurations
     * 
     * @return string
     */
    private function getClientIP()
    {
        $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // For X-Forwarded-For, get the first IP
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        // Fallback to REMOTE_ADDR (even if private)
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }

    /**
     * AJAX handler to generate custom CSS via WP AI Connector.
     */
    public function ajaxAiGenerateCss()
    {
        // Security checks
        check_ajax_referer('flow_flow_nonce', 'security');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Not allowed'], 403);
        }

        if (!function_exists('wp_ai_client_prompt')) {
            wp_send_json_error(['message' => 'AI Client is not available. Requires WordPress 7.0+.']);
        }

        $prompt = isset($_POST['prompt']) ? sanitize_textarea_field(wp_unslash($_POST['prompt'])) : '';
        $stream_id = isset($_POST['stream_id']) ? sanitize_text_field($_POST['stream_id']) : '';

        if (empty($prompt)) {
            wp_send_json_error(['message' => 'A prompt is required.']);
        }

        $system = 'You are a professional web designer and CSS expert. '
            . 'Generate custom CSS rules based on the user\'s prompt to style a Flow-Flow social feed. '
            . 'Ensure the CSS is clean, valid, and well-structured. '
            . 'All CSS rules MUST target classes or elements under the stream selector wrapper. ';
        if (!empty($stream_id) && $stream_id !== 'new') {
            $system .= 'Target the specific stream using the selector prefix "#ff-stream-' . $stream_id . '" (e.g., "#ff-stream-' . $stream_id . ' .ff-item { ... }"). ';
        } else {
            $system .= 'Target the stream using the class prefix ".ff-stream-wrapper" or similar selectors. ';
        }
        $system .= 'Provide only valid CSS code. Do NOT wrap the CSS in markdown code blocks like ```css ... ``` or include any conversational text/labels. Return ONLY the CSS rules.';

        try {
            $builder = call_user_func('wp_ai_client_prompt', $prompt)
                ->using_system_instruction($system)
                ->using_temperature(0.2)
                ->using_max_tokens(1500);

            $text = $builder->generate_text();
            if (is_wp_error($text)) {
                wp_send_json_error(['message' => $text->get_error_message()]);
            }

            // Clean up markdown wrapping if present
            $text = trim($text);
            if (strpos($text, '```css') === 0) {
                $text = substr($text, 6);
            }
            if (strpos($text, '```') === 0) {
                $text = substr($text, 3);
            }
            if (substr($text, -3) === '```') {
                $text = substr($text, 0, -3);
            }
            $text = trim($text);

            wp_send_json_success(['css' => $text]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => 'AI generation failed: ' . $e->getMessage()]);
        }
    }
}

// phpcs:enable
