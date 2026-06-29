<?php
// phpcs:disable
 namespace flow;
if ( ! defined( 'WPINC' ) ) die;

use Exception;
use la\core\LABase;
use la\core\LAUtils;
use la\core\settings\LASettingsUtils;
use la\core\settings\LAStreamSettings;

/**
 * Flow-Flow
 *
 * Plugin class. This class should ideally be used to work with the
 * public-facing side of the WordPress site.
 *
 * If you're interested in introducing administrative or dashboard
 * functionality, then refer to `FlowFlowAdmin.php`
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>

 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
class FlowFlow extends LABase {

	/**
	 * Log a message to flow-flow-debug.log via FF_LOG_FILE_DEST.
	 *
	 * @param string $message
	 * @param string $context  Optional label (e.g. method name)
	 */
	private static function ff_log($message, $context = 'FlowFlow') {
		if (!defined('FF_LOG_FILE_DEST')) return;
		$timestamp = date('Y-m-d H:i:s');
		@error_log("[$timestamp] [$context] $message\n-----------------------------------\n", 3, FF_LOG_FILE_DEST);
	}

    protected function getShortcodePrefix(){
        return 'ff';
    }

    /**
     * @return array
     */
    public function getContext() {
        return $this->context;
    }

    /**
     * @param $stream
     * @param $context
     *
     * @return mixed|void
     * @throws Exception
     */
    protected function getPublicContext($stream, $context){
        $context['boosted'] = LASettingsUtils::YepNope2ClassicStyleSafe($stream, 'cloud', false);
        $context = parent::getPublicContext($stream, $context);
        $context['token'] = $context['can_moderate'] ? LAUtils::dbm($context)->getToken(true) : '';
        if ($context['boosted'] && FF_USE_WP){
            $context = apply_filters('flow_flow_build_public_context', $context, new LAStreamSettings($stream));
        }
        return $context;
    }

    /**
     * Daily cron: check global TikTok token and refresh it if it is expired or near expiration.
     * Uses global settings fields saved in options: tiktok_access_token, tiktok_refresh_token, tiktok_expires_in, tiktok_username.
     */
    public final function checkTikTokToken() {
        $dbm = LAUtils::dbm($this->context);
        try {
            // Get current options
            $options = $dbm->getOption('options', true);
            if (!is_array($options)) {
                self::ff_log('No options found in checkTikTokToken', 'TikTok');
                return false;
            }

            // Check if we have a refresh token
            $refresh_token = isset($options['tiktok_refresh_token']) ? trim($options['tiktok_refresh_token']) : '';
            if (empty($refresh_token)) {
                return false;
            }
            
            // Check if we need to refresh (token expired or will expire in the next hour)
            $current_time = time();
            $expires_in = isset($options['tiktok_expires_in']) ? (int)$options['tiktok_expires_in'] : 0;
            $time_until_expiry = $expires_in - $current_time;
            
            // If token is still valid for more than 12 hours, no need to refresh yet
            if ($time_until_expiry > 43200) {
                self::ff_log(sprintf('Token still valid for %d seconds, skipping refresh', $time_until_expiry), 'TikTok');
                return true;
            }
            
            self::ff_log(sprintf('Token expired or expiring soon (expires in %d seconds), refreshing...', $time_until_expiry), 'TikTok');
            
            // Build the back URL with proper encoding
            $backUrl = urlencode(admin_url('admin-ajax.php') . '?action=flow_flow_social_auth');
            
            // Build the refresh URL with properly encoded parameters
            $refreshUrl = add_query_arg([
                'back' => $backUrl,
                'refresh_token' => $refresh_token,
                'cron' => '1',
                'type' => 'tiktok'  // Make sure to include the type parameter
            ], 'https://flow.looks-awesome.com/service/auth/callback/tiktok-auth.php');
            
            self::ff_log('Initiating cron token refresh to: ' . $refreshUrl, 'TikTok');
            
            // Make the request to refresh the token
            $response = wp_remote_get($refreshUrl, [
                'timeout' => 30,
                'sslverify' => true,
                'headers' => [
                    'User-Agent' => 'Flow-Flow-WordPress/Cron',
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ]
            ]);
            
            self::ff_log('Token refresh response code: ' . wp_remote_retrieve_response_code($response), 'TikTok');
            self::ff_log('Token refresh response body: ' . wp_remote_retrieve_body($response), 'TikTok');
            
            if (is_wp_error($response)) {
                self::ff_log('Cron token refresh failed: ' . $response->get_error_message(), 'TikTok');
                return false;
            }
            
            $http_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            if ($http_code >= 200 && $http_code < 300) {
                // Parse the JSON response
                $data = json_decode($body, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    self::ff_log('Failed to parse token refresh response: ' . json_last_error_msg(), 'TikTok');
                    return false;
                }
                
                // Update the options with the new token data
                if (isset($data['access_token'])) {
                    $options['tiktok_access_token'] = $data['access_token'];
                    
                    // Update refresh token if a new one was provided
                    if (isset($data['refresh_token'])) {
                        $options['tiktok_refresh_token'] = $data['refresh_token'];
                    }
                    
                    // Calculate and store the expiration time
                    $expires_in = isset($data['expires_in']) ? (int)$data['expires_in'] : 0;
                    if ($expires_in > 0) {
                        $expires_at = $current_time + $expires_in;
                        $options['tiktok_expires_in'] = $expires_at;
                        
                        self::ff_log(sprintf('Token refreshed successfully, expires at %s', date('Y-m-d H:i:s', $expires_at)), 'TikTok');
                    }
                    
                    // Save the updated options
                    $dbm->setOption('options', $options, true);

                    // Fetch and persist latest TikTok user info (avatar, username, etc.)
                    try {
                        $accessToken = isset($options['tiktok_access_token']) ? trim($options['tiktok_access_token']) : '';
                        if ($accessToken !== '') {
                            $dbm->updateTikTokUserInfo($accessToken);
                            self::ff_log('Cron: user info updated after token refresh', 'TikTok');
                        }
                    } catch (\Throwable $e) {
                        self::ff_log('Cron: failed to update user info after token refresh: ' . $e->getMessage(), 'TikTok');
                    }

                    // Invalidate cache lifetime so feeds re-fetch with the new token.
                    // Do NOT use cleanByFeedType() — it deletes all cached posts.
                    $dbm->resetCacheLifetimeByFeedType('tiktok');

                    return true;
                } else {
                    self::ff_log('Token refresh response missing access_token', 'TikTok');
                    return false;
                }
            } else {
                self::ff_log(sprintf('Cron token refresh failed with status %d: %s', $http_code, $body), 'TikTok');
                return false;
            }
            
        } catch (\Throwable $e) {
            self::ff_log('Error in checkTikTokToken: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 'TikTok');
        }
    }

    /**
     * Daily cron: check global LinkedIn token and refresh it if it is expired or near expiration.
     * Uses global settings fields saved in options: linkedin_access_token, linkedin_refresh_token, linkedin_expires_in.
     */
    public final function checkLinkedInToken() {
        $dbm = LAUtils::dbm($this->context);
        try {
            // Get current options
            $options = $dbm->getOption('options', true);
            if (!is_array($options)) {
                self::ff_log('No options found in checkLinkedInToken', 'LinkedIn');
                return false;
            }

            // Check if we have a refresh token
            $refresh_token = isset($options['linkedin_refresh_token']) ? trim($options['linkedin_refresh_token']) : '';
            if (empty($refresh_token)) {
                return false;
            }
            
            // Check if we need to refresh (token expired or will expire soon)
            $current_time = time();
            $expires_in = isset($options['linkedin_expires_in']) ? (int)$options['linkedin_expires_in'] : 0;
            $time_until_expiry = $expires_in - $current_time;
            
            // If token is still valid for more than 7 days, no need to refresh yet
            // LinkedIn tokens typically last 60 days, so we refresh when less than 7 days remain
            if ($time_until_expiry > 604800) { // 7 days in seconds
                self::ff_log(sprintf('Token still valid for %d seconds (%d days), skipping refresh', $time_until_expiry, floor($time_until_expiry / 86400)), 'LinkedIn');
                return true;
            }
            
            self::ff_log(sprintf('Token expired or expiring soon (expires in %d seconds), refreshing...', $time_until_expiry), 'LinkedIn');
            
            // Build the back URL with proper encoding
            $backUrl = urlencode(admin_url('admin-ajax.php') . '?action=flow_flow_social_auth');
            
            // Build the refresh URL with properly encoded parameters
            $refreshUrl = add_query_arg([
                'back' => $backUrl,
                'refresh_token' => $refresh_token,
                'cron' => '1',
                'type' => 'linkedin'
            ], 'https://flow.looks-awesome.com/service/auth/callback/linkedin-auth.php');
            
            self::ff_log('Initiating cron token refresh to: ' . $refreshUrl, 'LinkedIn');
            
            // Make the request to refresh the token
            $response = wp_remote_get($refreshUrl, [
                'timeout' => 30,
                'sslverify' => true,
                'headers' => [
                    'User-Agent' => 'Flow-Flow-WordPress/Cron',
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ]
            ]);
            
            self::ff_log('Token refresh response code: ' . wp_remote_retrieve_response_code($response), 'LinkedIn');
            self::ff_log('Token refresh response body: ' . wp_remote_retrieve_body($response), 'LinkedIn');
            
            if (is_wp_error($response)) {
                self::ff_log('Cron token refresh failed: ' . $response->get_error_message(), 'LinkedIn');
                return false;
            }
            
            $http_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            if ($http_code >= 200 && $http_code < 300) {
                // Parse the JSON response
                $data = json_decode($body, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    self::ff_log('Failed to parse token refresh response: ' . json_last_error_msg(), 'LinkedIn');
                    return false;
                }
                
                // Update the options with the new token data
                if (isset($data['access_token'])) {
                    $options['linkedin_access_token'] = $data['access_token'];
                    
                    // Update refresh token if a new one was provided
                    if (isset($data['refresh_token'])) {
                        $options['linkedin_refresh_token'] = $data['refresh_token'];
                    }
                    
                    // Calculate and store the expiration time
                    $expires_in = isset($data['expires_in']) ? (int)$data['expires_in'] : 0;
                    if ($expires_in > 0) {
                        $expires_at = $current_time + $expires_in;
                        $options['linkedin_expires_in'] = $expires_at;
                        
                        self::ff_log(sprintf('Token refreshed successfully, expires at %s', date('Y-m-d H:i:s', $expires_at)), 'LinkedIn');
                    }
                    
                    // Save the updated options
                    $dbm->setOption('options', $options, true);

                    // Invalidate cache lifetime so feeds re-fetch with the new token.
                    // Do NOT use cleanByFeedType() — it deletes all cached posts.
                    $dbm->resetCacheLifetimeByFeedType('linkedin');

                    return true;
                } else {
                    self::ff_log('Token refresh response missing access_token', 'LinkedIn');
                    return false;
                }
            } else {
                self::ff_log(sprintf('Cron token refresh failed with status %d: %s', $http_code, $body), 'LinkedIn');
                return false;
            }
            
        } catch (\Throwable $e) {
            self::ff_log('Error in checkLinkedInToken: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 'LinkedIn');
        }
    }

    protected function getNameJSOptions(){
        return 'FlowFlowOpts';
    }
}

// phpcs:enable
