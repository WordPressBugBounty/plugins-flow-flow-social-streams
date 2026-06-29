<?php namespace la\core\cache;
use Exception;
use flow\social\cache\LAFacebookCacheManager as ILAFacebookCacheManager;
use flow\social\LASocialException;
use la\core\settings\LASettingsUtils;

if ( ! defined( 'WPINC' ) ) die;

/**
 * Flow-Flow.
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>

 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
class LAFacebookCacheAdapter implements ILAFacebookCacheManager {
    private $context;

    public function __construct(){
    }

    /** @var $manager ILAFacebookCacheManager */
    private $manager = null;

    public function setContext($context){
        $this->context = $context;
    }

    /**
     * @throws Exception
     */
    public function clean() {
        $this->get()->clean();
    }

    /**
     * @return array|false|mixed|void|null
     * @throws Exception
     */
    public function getAccessToken() {
        return $this->get()->getAccessToken();
    }

    public function getError() {
        return $this->get()->getError();
    }

    /**
     * @param $token
     * @param $expires
     *
     * @throws Exception
     */
    public function save( $token, $expires ) {
        $this->get()->save( $token, $expires );
    }

    /**
     * @return ILAFacebookCacheManager
     */
    private function get(){
        if ($this->manager == null){
            $db = $this->context['db_manager'];
            $auth = $db->getOption('fb_auth_options', true);
            $fb_use_own = LASettingsUtils::YepNope2ClassicStyleSafe($auth, 'facebook_use_own_app', false);
            $this->manager = $fb_use_own ? new LAFacebookCacheManager($this->context) : new LAFacebookCacheManager2($this->context);
        }
        return $this->manager;
    }

    /**
     * @throws LASocialException
     */
    public function startCounter() {
        $this->get()->startCounter();
    }

    /**
     * @throws Exception
     */
    public function stopCounter() {
        $this->get()->stopCounter();
    }

    public function hasLimit() {
        return $this->get()->hasLimit();
    }

    public function addRequest() {
        $this->get()->addRequest();
    }

    public function getIdPosts( $feedId ) {
        return $this->get()->getIdPosts($feedId);
    }
}