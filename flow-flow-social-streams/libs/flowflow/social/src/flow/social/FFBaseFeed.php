<?php
// phpcs:disable
namespace flow\social;
if (!defined('WPINC'))
    die;

use Exception;
use flow\social\cache\FFImageSizeCacheBase;
use stdClass;

/**
 * Flow-Flow.
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>

 * @link      http://looks-awesome.com
 * @copyright 2014-2016 Looks Awesome
 */
abstract class FFBaseFeed implements FFFeed
{
    /** @var stdClass */
    public $feed;

    private $id;
    /** @var FFImageSizeCacheBase */
    protected $cache;
    private $count;
    private $imageWidth;
    private $type;
    /**
     * Exclude words
     * @var  array $filterByWords */
    private $filterByWords;
    /**
     * Include words
     * @var array $include */
    private $include;
    protected $criticalError = true;
    /** @var array $errors */
    protected $errors;
    protected $context;

    private static $debugLogFile;

    function __construct($type)
    {
        $this->type = $type;
    }

    public function getType()
    {
        return $this->type;
    }

    public function id()
    {
        return $this->id;
    }

    public function getCount()
    {
        return $this->count;
    }

    /**
     * @return int
     */
    public function getImageWidth()
    {
        return $this->imageWidth;
    }

    /**
     * @return int
     */
    public function getAllowableWidth()
    {
        return 200;
    }

    /**
     * @param $context
     * @param $feed
     *
     * @return void
     */
    public function init($context, $feed)
    {
        $this->context = $context;
        $this->errors = [];
        $this->imageWidth = defined('FF_MAX_IMAGE_WIDTH') ? FF_MAX_IMAGE_WIDTH : 300;

        if (!is_null($feed)) {
            $this->cache = $context['image_size_cache'];
            $this->feed = $feed;
            $this->id = $feed->id;
            if ($feed->last_update === 'N/A' && isset($context['count_posts_4init'])) {
                $this->count = $context['count_posts_4init'];
            } else {
                $this->count = isset($feed->posts) ? intval($feed->posts) : 10;
            }
            if (isset($feed->{'include'}) && strlen($feed->{'include'}) > 0) {
                $this->include = explode(',', $feed->{'include'});
                if ($this->include === false)
                    $this->include = array();
            } else {
                $this->include = array();
            }
            if (isset($feed->{'filter-by-words'}) && strlen($feed->{'filter-by-words'}) > 0) {
                $this->filterByWords = explode(',', $feed->{'filter-by-words'});
                if ($this->filterByWords === false)
                    $this->filterByWords = array();
            } else {
                $this->filterByWords = array();
            }
        }
    }

    public final function posts($is_empty_feed)
    {
        $this->debugLog("Starting posts processing", ['is_empty_feed' => $is_empty_feed]);
        $result = [];
        try {
            if ($is_empty_feed) {
                $this->count = defined('FF_FEED_INIT_COUNT_POSTS') ? FF_FEED_INIT_COUNT_POSTS : 50;
            }
            if ($this->beforeProcess()) {
                $this->deferredInit($this->feed);
                if (sizeof($this->errors) == 0) {
                    do {
                        $result += $this->onePagePosts();
                    } while ($this->nextPage($result));
                    $this->debugLog("Finished posts processing", ['count' => count($result)]);
                    return $this->afterProcess($result);
                } else {
                    $this->debugLog("Errors during deferredInit", $this->errors);
                }
            } else {
                $this->debugLog("beforeProcess returned false", $this->errors);
            }
        } catch (LASocialException $se) {
            $error = $se->getSocialError();
            $error['type'] = $this->getType();
            $this->errors[] = $error;
            $this->criticalError = !$this->isRateLimitError($error['message']);
            error_log($se->getMessage());
            $this->debugLog("LASocialException caught", $error);
            //            error_log(print_r($se, true));
        } catch (Exception $e) {
            $message = $e->getMessage();
            if (strpos($message, 'mb_') !== false) {
                $message = 'Fatal Error: There is no required mbstring PHP module.';
            }
            $this->errors[] = [
                'type' => $this->getType(),
                'message' => $message,
            ];
            $this->criticalError = !$this->isRateLimitError($e->getMessage());
            error_log($e->getMessage());
            $this->debugLog("Exception caught", $e->getMessage());
            //            error_log(print_r($e, true));
        } catch (\Throwable $t) {
            $message = $t->getMessage();
            if (strpos($message, 'mb_') !== false) {
                $message = 'Fatal Error: There is no required mbstring PHP module.';
            }
            $this->errors[] = [
                'type' => $this->getType(),
                'message' => $message,
            ];
            $this->criticalError = !$this->isRateLimitError($t->getMessage());
            error_log($t->getMessage());
            $this->debugLog("Throwable caught", $t->getMessage());
        }
        // Error state already set in catch blocks
        return $result;
    }

    /**
     * @param stdClass $feed
     * @return void
     */
    protected abstract function deferredInit($feed);
    protected abstract function onePagePosts();

    /**
     * @return array
     */
    public function errors()
    {
        return $this->errors;
    }

    /**
     * @param $url
     * @param $width
     * @param $height
     * @param bool $scale
     *
     * @return array
     */
    protected function createImage($url, $width = null, $height = null, $scale = true)
    {
        if ($width != -1 && $height != -1) {
            if ($width == null || $height == null) {
                $size = $this->cache->size($url);
                $width = $size['width'];
                $height = $size['height'];
            }
            if ($scale) {
                $tWidth = $this->getImageWidth();
                return array('url' => $url, 'width' => $tWidth, 'height' => FFFeedUtils::getScaleHeight($tWidth, $width, $height));
            }
        }
        return array('url' => $url, 'width' => $width, 'height' => $height);
    }

    protected function createMedia($url, $width = null, $height = null, $type = 'image', $scale = false)
    {
        if ($type == 'html') {
            return array('type' => $type, 'html' => $url);
        }
        if ($width == null || $height == null) {
            $size = $this->cache->size($url);
            $width = $size['width'];
            $height = $size['height'];
        }
        if ($type == 'image' && $scale == true && $width > 600) {
            $height = FFFeedUtils::getScaleHeight(600, $width, $height);
            $width = 600;
        }
        return array('type' => $type, 'url' => $url, 'width' => $width, 'height' => $height);
    }

    /**
     * @param string $link
     * @param string $name
     * @param mixed $image
     * @param mixed $width
     * @param mixed $height
     * @return array
     */
    protected function createAttachment($link, $name, $image = null, $width = null, $height = null)
    {
        if ($image != null) {
            if (is_string($image))
                $image = $this->createImage($image, $width, $height);
            if ($image['width'] > $this->getAllowableWidth())
                return array('type' => 'article', 'url' => $link, 'displayName' => $name, 'image' => $image);
        }
        return array('type' => 'article', 'url' => $link, 'displayName' => $name);
    }

    protected function includePost($post)
    {
        if (count($this->include) == 0)
            return true;

        foreach ($this->include as $word) {
            $word = mb_strtolower($word);
            $firstLetter = mb_substr($word, 0, 1);

            if ($firstLetter !== false) {
                switch ($firstLetter) {
                    case '@':
                        $word = mb_substr($word, 1);
                        if ((mb_strpos(mb_strtolower($post->screenname), $word) !== false) || (mb_strpos(mb_strtolower($post->nickname), $word) !== false)) {
                            return true;
                        }
                        break;
                    case '#':
                        $word = mb_substr($word, 1);
                        if (mb_strpos(mb_strtolower($post->permalink), $word) !== false) {
                            return true;
                        }
                        break;
                    case '$':
                        $word = mb_substr($word, 1);
                        if (!empty($word) && ((mb_strpos(mb_strtolower(strip_tags($post->text)), '#' . $word) !== false) || (isset($post->header) && mb_strpos(mb_strtolower(strip_tags($post->header)), '#' . $word) !== false))) {
                            return true;
                        }
                        break;
                    default:
                        if (!empty($word) && ((mb_strpos(mb_strtolower(strip_tags($post->text)), $word) !== false) || (isset($post->header) && mb_strpos(mb_strtolower(strip_tags($post->header)), $word) !== false))) {
                            return true;
                        }
                        break;
                }
            }
        }

        return false;
    }

    protected function excludePost($post)
    {
        if (count($this->filterByWords) == 0)
            return true;

        foreach ($this->filterByWords as $word) {
            $word = mb_strtolower($word);
            $firstLetter = mb_substr($word, 0, 1);
            if ($firstLetter !== false) {
                switch ($firstLetter) {
                    case '@':
                        $word = mb_substr($word, 1);
                        if ((mb_strpos(mb_strtolower($post->screenname), $word) !== false) || (mb_strpos(mb_strtolower($post->nickname), $word) !== false)) {
                            return false;
                        }
                        break;
                    case '#':
                        $word = mb_substr($word, 1);
                        if (mb_strpos(mb_strtolower($post->permalink), $word) !== false) {
                            return false;
                        }
                        break;
                    default:
                        if (!empty($word) && ((mb_strpos(mb_strtolower($post->text), $word) !== false) || (isset($post->header) && mb_strpos(mb_strtolower($post->header), $word) !== false))) {
                            return false;
                        }
                }
            }
        }

        return true;
    }

    /**
     * @param stdClass $post
     * @return bool
     */
    protected function isSuitablePost($post)
    {
        if ($post == null)
            return false;

        $suitable = $this->includePost($post);
        if ($suitable) {
            $suitable = $this->excludePost($post);
        }

        return $suitable;
    }

    /**
     * @return bool
     */
    protected function beforeProcess()
    {
        return (sizeof($this->errors) == 0);
    }

    /**
     * @param $result array
     * @return array
     */
    protected function afterProcess($result)
    {
        $this->cache->save();
        $this->criticalError = empty($result) && sizeof($this->errors) > 0;
        return $result;
    }

    public function hasCriticalError()
    {
        return $this->criticalError;
    }

    protected function isRateLimitError($message)
    {
        return false;
    }

    /**
     * @param array $result
     * @return bool
     */
    protected function nextPage($result)
    {
        return false;
    }

    /**
     * @param $url
     * @param int $timeout
     * @param bool $header
     * @param bool $log
     *
     * @return array
     * @throws LASocialRequestException
     * @throws Exception
     *
     * @deprecated
     */
    protected function getFeedData($url, $timeout = 60, $header = false, $log = true)
    {
        $this->debugLog("getFeedData called", ['url' => $url, 'timeout' => $timeout]);
        return FFFeedUtils::getFeedDataWithThrowException($url, $timeout, $header, $log, $this->feed->use_curl_follow_location, $this->feed->use_ipv4);
    }

    /**
     * TODO Remove this method. Need to use LASocialException
     *
     * @deprecated
     * @param $message
     * @return string
     */
    protected function filterErrorMessage($message)
    {
        if (is_array($message)) {
            if (sizeof($message) > 0 && isset($message[0]['msg'])) {
                return stripslashes(htmlspecialchars($message[0]['msg'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            } else {
                return '';
            }
        }
        return stripslashes(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }

    protected function print2log($msg)
    {
        error_log($msg);
    }

    protected function debugLog($msg, $data = null)
    {
        if (self::$debugLogFile === null) {
            // Failsafe: Use constant if defined, otherwise do nothing
            self::$debugLogFile = defined('FF_LOG_FILE_DEST') ? FF_LOG_FILE_DEST : false;
        }

        if (!self::$debugLogFile) {
            return;
        }

        try {
            $timestamp = date('Y-m-d H:i:s');
            // Check if getType exists or handle error
            $type = method_exists($this, 'getType') ? $this->getType() : 'unknown';
            $formattedMsg = "[$timestamp] [$type] $msg";

            if ($data !== null) {
                $formattedMsg .= "\nData: " . print_r($data, true);
            }

            $formattedMsg .= "\n-----------------------------------\n";

            // Failsafe: Use @ to suppress warnings if file is not writable
            @error_log($formattedMsg, 3, self::$debugLogFile);
        } catch (\Exception $e) {
            // Silently fail if logging fails
        }
    }
}
// phpcs:enable
