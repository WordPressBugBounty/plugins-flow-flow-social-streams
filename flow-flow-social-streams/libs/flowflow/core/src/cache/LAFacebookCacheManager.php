<?php
// phpcs:disable
 namespace la\core\cache;
if ( ! defined( 'WPINC' ) ) die;
if ( ! defined('FF_FACEBOOK_RATE_LIMIT')) define('FF_FACEBOOK_RATE_LIMIT', 200);

use Exception;
use flow\social\cache\LAFacebookCacheManager as ILAFacebookCacheManager;
use flow\social\FFFeedUtils;
use flow\social\LASocialException;
use la\core\db\LADBManager;
use la\core\LAUtils;

/**
 * Flow-Flow.
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>

 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
class LAFacebookCacheManager implements ILAFacebookCacheManager {
    protected static $postfix_at = 'la_facebook_access_token';
    protected static $postfix_at_expires = 'la_facebook_access_token_expires';

    /** @var LADBManager  */
    protected $db = null;
    private $auth = null;
    private $error = null;
    private $access_token = null;

    private $hasHitLimit;
    private $creationTime;
    private $request_count;
    private $global_request_count;
    private $global_request_array;

    public function __construct($context) {
        $this->db = LAUtils::dbm($context);
    }

    public function getError(){
        return $this->error;
    }

    /**
     * @throws Exception
     */
    public function clean(){
        $this->deleteOption($this->getNameExtendedAccessToken());
        $this->deleteOption($this->getNameExtendedAccessToken(true));
        $this->db->deleteOption('facebook_access_token');

        try {
            $cache = FFFeedUtils::getCache();
            $cache->delete(md5('facebookPageId'));
            $cache->delete(md5('instagram_business_account'));
        } catch (Exception $e) {
            if (defined('FF_LOG_FILE_DEST')) {
                @error_log('LAFacebookCacheManager clean cache error: ' . $e->getMessage() . PHP_EOL, 3, FF_LOG_FILE_DEST);
            }
        }
    }

    /**
     * @return array|false|mixed|void|null
     * @throws Exception
     */
    public function getAccessToken(){
        if ($this->access_token != null) return $this->access_token;

        if ($this->isExpiredToken()){
            $this->error = [
                'type'    => 'facebook',
                'message' => 'Access token is expired. Please go to AUTH tab to generate new token.'
            ];
            return null;
        }

        $token = null;
        if (false != ($token = $this->getStoredToken())){
            if ($this->isExpiresToken()){
                list($token, $expires, $error) = $this->refreshToken($token);
                if ($error == null) {
                    $this->save($token, $expires);
                    $this->db->update_options();
                }
                $this->error = $error;
            }
            $this->access_token = $token;
        }
        else {
            $this->error = [
                'type'    => 'facebook',
                'message' => 'Access token is not found. Please go to AUTH tab to generate token.'
            ];
        }
        return $token;
    }

    protected function isExpiresToken() {
        $expires = $this->getOption($this->getNameExtendedAccessToken(true));
        return $expires === false || time() > ($expires - 2629743);
    }

    protected function isExpiredToken() {
        $expires = $this->getOption($this->getNameExtendedAccessToken(true));
        return $expires === false || time() > $expires;
    }

    protected function getStoredToken() {
        $at = $this->getNameExtendedAccessToken();
        if (false !== ($access_token_transient = $this->getOption($at))){
            $access_token = $access_token_transient;
        }
        else{
            $auth = $this->getAuth();
            $access_token = @$auth['facebook_access_token'];
            if(!isset($access_token) || empty($access_token)){
                return false;
            }
        }
        return $access_token;
    }

    protected function refreshToken( $oldToken ) {
        $token_url = $this->getRefreshTokenUrl($oldToken);
        $settings = $this->db->getGeneralSettings();
        $response = FFFeedUtils::getFeedData($token_url, 20, false, true, $settings->useCurlFollowLocation(), $settings->useIPv4());
        if (false !== $response['response']){
            $response = (string)$response['response'];
            $response = (array)json_decode($response);
            $expires = (sizeof($response) > 2) ? (int)$response['expires_in'] : time() + 2629743*2;
            $access_token = $response['access_token'];
            return [ $access_token, $expires, null ];
        }
        else if (isset($response['errors'])) {
            $error = $response['errors'][0];
            return [
                null, null, [
                    'type'    => 'facebook',
                    'message' => $this->filter_error_message($error['msg']),
                    'url' => $token_url
                ]
            ];
        }
        return [ null, null, false ];
    }

    /**
     * @param $token
     * @param $expires
     *
     * @throws Exception
     */
    public function save($token, $expires){
        $this->updateOption($this->getNameExtendedAccessToken(), $token);
        $this->updateOption($this->getNameExtendedAccessToken(true), time() + ( isset($expires) ? $expires : 2629743 ));
    }

    protected function getRefreshTokenUrl($access_token){
        $auth = $this->getAuth();
        $facebookAppId = $auth['facebook_app_id'];
        $facebookAppSecret = $auth['facebook_app_secret'];
        return "https://graph.facebook.com/oauth/access_token?client_id={$facebookAppId}&client_secret={$facebookAppSecret}&grant_type=fb_exchange_token&fb_exchange_token={$access_token}";
    }

    protected function getNameExtendedAccessToken($expires = false){
        $auth = $this->getAuth();
        $facebookAppId = $auth['facebook_app_id'];
        $facebookAppSecret = $auth['facebook_app_secret'];
        $name = $expires ? self::$postfix_at_expires : self::$postfix_at;
        return $name . substr(hash('md5', $facebookAppId . $facebookAppSecret), 0, 6);
    }

    protected function getAuth(){
        if (empty($this->auth)){
            $this->auth = $this->db->getOption('fb_auth_options', true);
        }
        return $this->auth;
    }

    private function getOption($name){
        return FF_USE_WP ? get_option($name) : $this->db->getOption($name);
    }

    /**
     * @param $name
     * @param $value
     *
     * @throws Exception
     */
    private function updateOption($name, $value){
        FF_USE_WP ? update_option($name, $value) : $this->db->setOption($name, $value);
    }

    /**
     * @param $name
     *
     * @throws Exception
     */
    private function deleteOption($name){
        FF_USE_WP ? delete_option($name) : $this->db->deleteOption($name);
    }

    private function filter_error_message($message){
        if (is_array($message)){
            if (sizeof($message) > 0 && isset($message[0]['msg'])){
                return stripslashes(htmlspecialchars($message[0]['msg'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            }
            else {
                return '';
            }
        }
        return stripslashes(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    /**
     * @return bool
     * @throws LASocialException
     * @throws Exception
     */
    public function startCounter() {
        $this->request_count = 0;
        $this->hasHitLimit = false;

        $limit = $this->db->getOption('fb_limit_counter', true, false);
        if ($limit === false){
            $limit = [];
        }

        if (!is_array($limit)){
            // Fallback if option is corrupted, though getOption usually returns false if not found
            $limit = [];
        }

        $this->creationTime = time();
        $this->global_request_count = 0;
        $limitTime = $this->creationTime - 3600;
        $result = [];
        foreach ( $limit as $time => $count ) {
            if ($time > $limitTime) {
                $result[$time] = $count;
                $this->global_request_count += (int)$count;
            }
        }
        $this->global_request_array = $result;

        if ($this->global_request_count + 4 > FF_FACEBOOK_RATE_LIMIT) {
            throw new LASocialException('Your site has hit the Facebook API rate limit. <a href="http://docs.social-streams.com/article/133-facebook-app-request-limit-reached" target="_blank">Troubleshooting</a>.');
        }

        return true;
    }

    /**
     * @throws Exception
     */
    public function stopCounter() {
        if ($this->request_count > 0) {
            $conn = $this->db->conn();
            // Start transaction just for the update to ensure atomicity
            if ($conn->beginTransaction()) {
                try {
                    // Lock the row now
                    $limit = $this->db->getOption('fb_limit_counter', true, true);
                    if (!is_array($limit)) $limit = [];

                    $this->creationTime = time();
                    $limitTime = $this->creationTime - 3600;
                    $updates = [];

                    // Prune old entries
                    foreach ($limit as $time => $count) {
                        if ($time > $limitTime) {
                            $updates[$time] = $count;
                        }
                    }

                    // Add current usage
                    $updates[$this->creationTime] = (isset($updates[$this->creationTime]) ? $updates[$this->creationTime] : 0) + $this->request_count;

                    $serialized = serialize($updates);
                    // Direct UPDATE query to avoid GAP locks from INSERT...ODKU
                    // We assume table prefix logic is standard: flow_flow_fb_limit_counter
                    $conn->query('UPDATE ?n SET `value`=?s WHERE `id`=?s', 
                        $this->db->option_table_name, 
                        $serialized, 
                        'flow_flow_fb_limit_counter'
                    );

                    $conn->commit();
                } catch (Exception $e) {
                    $conn->rollback();
                    // Log error but don't fail the request as data is already fetched
                    error_log('Flow-Flow: Failed to update Facebook rate limit counter: ' . $e->getMessage());
                }
            }
        }
    }

    public function hasLimit() {
        if ($this->hasHitLimit) return false;
        if ($this->global_request_count + $this->request_count + 3 > FF_FACEBOOK_RATE_LIMIT) {
            $this->hasHitLimit = true;
            return false;
        }
        return true;
    }

    public function addRequest() {
        $this->request_count++;
    }

    public function getIdPosts($feedId) {
        return $this->db->getIdPosts($feedId);
    }
}
// phpcs:enable
