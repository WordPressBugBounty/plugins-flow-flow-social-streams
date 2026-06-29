<?php namespace la\core\cache;
use Exception;
use la\core\settings\LAStreamSettings;

if ( ! defined( 'WPINC' ) ) die;
/**
 * FlowFlow.
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>
 *
 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
interface LACache {
    /**
     * @param LAStreamSettings $stream
     * @param bool $moderation
     *
     * @return void
     */
    public function setStream($stream, $moderation = false);

    /**
     * @param $feeds
     * @param $disableCache
     *
     * @return array
     * @throws Exception
     */
    public function posts($feeds, $disableCache);
    public function errors();
    public function hash();
    public function transientHash($streamId);
    public function moderate();
}