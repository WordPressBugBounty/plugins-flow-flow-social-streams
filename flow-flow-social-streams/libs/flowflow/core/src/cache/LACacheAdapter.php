<?php namespace la\core\cache;
if ( ! defined( 'WPINC' ) ) die;

use la\core\settings\LAGeneralSettings;

/**
 * FlowFlow.
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>
 *
 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
class LACacheAdapter implements LACache{
    private $force;
    private $context;
    /**  @var LACache */
    private $cache;
    /** @var  LAGeneralSettings */
    private $generalSettings;

    function __construct($context, $force = false){
        $this->force = $force;
        $this->context = $context;
        $dbm = $this->context['db_manager'];
        $this->generalSettings = $dbm->getGeneralSettings();
        $this->cache = new LACacheManager($this->context, $this->force);
    }

    public function setStream( $stream, $moderation = false ) {
        if ($moderation){
            $this->cache = $this->admin() ?
                new LAAdminModerationCacheManager($this->context, $this->force) : new LAModerationCacheManager($this->context, $this->force);
        }
        $this->cache->setStream($stream);
    }

    public function posts( $feeds, $disableCache ) {
        return $this->cache->posts( $feeds, $disableCache );
    }

    public function errors() {
        return $this->cache->errors();
    }

    public function hash() {
        return $this->cache->hash();
    }

    public function transientHash( $streamId ) {
        return $this->cache->transientHash($streamId);
    }

    public function moderate() {
        $this->cache->moderate();
    }

    /**
     * @return bool
     */
    private function admin(){
        return FF_USE_WP ? $this->generalSettings->canModerate() : ff_user_can_moderate();
    }
}