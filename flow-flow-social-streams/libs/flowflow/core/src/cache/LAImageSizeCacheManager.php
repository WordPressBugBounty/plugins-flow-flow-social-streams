<?php
// phpcs:disable
namespace la\core\cache;
if (!defined('WPINC'))
    die;

use Exception;
use flow\social\cache\FFImageSizeCacheBase;
use la\core\LAUtils;

/**
 * Flow-Flow.
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>

 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
class LAImageSizeCacheManager extends FFImageSizeCacheBase
{
    const FF_IMG_CACHE_SIZE = 1000;

    private $image_cache = null;
    private $new_images = [];
    private $context;

    /**
     * LAImageSizeCacheManager constructor.
     *
     * @param array $context
     */
    public function __construct($context)
    {
        $this->context = $context;
    }

    public function getOriginalUrl($url)
    {
        $this->lazy();

        $h = hash('md5', $url);
        if (array_key_exists($h, $this->image_cache)) {
            return $this->image_cache[$h]['original_url'];
        }
        return '';
    }

    /**
     * @param string $url
     * @param string $original_url
     *
     * @return array
     */
    public function size($url, $original_url = '')
    {
        $this->lazy();

        $h = hash('md5', $url);
        if ($original_url != '')
            $url = $original_url;
        if (!array_key_exists($h, $this->image_cache)) {
            try {
                $time = date("Y-m-d H:i:s", time());
                if (!empty($url)) {
                    if (isset($_REQUEST['debug'])) {
                        ini_set('upload_max_filesize', '16M');
                        ini_set('post_max_size', '16M');
                        ini_set('max_input_time', '60');
                    }
                    @list($width, $height) = getimagesize($url);
                    if (empty($width) || empty($height)) {
                        @list($width, $height) = $this->alternativeGetImageSize($url);
                        if (empty($width) || empty($height)) {
                            $width = -1;
                            $height = -1;
                        }
                    }
                    $data = ['creation_time' => $time, 'width' => $width, 'height' => $height, 'original_url' => $original_url];
                } else
                    $data = ['creation_time' => $time, 'width' => -1, 'height' => -1, 'original_url' => $original_url];
                if ($data['width'] > 0 && $data['height'] > 0) {
                    $this->image_cache[$h] = $data;
                    $this->new_images[$h] = $data;
                }
                return $data;
            } catch (Exception $e) {
                error_log($url);
                error_log($e->getMessage());
                error_log($e->getTraceAsString());
                return ['time' => time(), 'width' => -1, 'height' => -1, 'error' => $e->getMessage()];
            }
        }
        return $this->image_cache[$h];
    }

    /**
     * @return void
     */
    public function save()
    {
        $this->lazy();

        $dbm = LAUtils::dbm($this->context);
        $conn = $dbm->conn();
        if (sizeof($this->new_images) > 0 && $conn->beginTransaction()) {
            foreach ($this->new_images as $url => $image) {
                $conn->query(
                    'INSERT INTO ?n SET `url` = ?s, ?u ON DUPLICATE KEY UPDATE ?u',
                    $dbm->image_cache_table_name,
                    $url,
                    $image,
                    ['creation_time' => $image['creation_time']]
                );
            }
            $conn->commit();
        }
        $conn->rollback();
    }

    private function alternativeGetImageSize($url)
    {
        $raw = $this->ranger($url);
        if ($raw === 'URL signature expired') {
            return [-1, -1];
        }
        $im = imagecreatefromstring($raw);
        // Check if image creation failed (e.g., if URL is a video file)
        if ($im === false) {
            error_log('[LAImageSizeCacheManager] Failed to create image from URL: ' . $url);
            return [-1, -1];
        }
        $width = imagesx($im);
        $height = imagesy($im);
        imagedestroy($im);
        return [$width, $height];
    }

    private function ranger($url)
    {
        $headers = [
            "Range: bytes=0-32768"
        ];

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/46.0.2490.80 Safari/537.36');
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT_MS, 5000);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        $data = curl_exec($curl);
        curl_close($curl);
        return $data;
    }

    private function lazy()
    {
        if ($this->image_cache == null) {
            $dbm = LAUtils::dbm($this->context);
            $sql = "SELECT `url`, `width`, `height`, `original_url`  FROM ?n ORDER BY `creation_time` DESC LIMIT ?i";
            if (false === ($result = $dbm->conn()->getIndCol('url', $sql, $dbm->image_cache_table_name, self::FF_IMG_CACHE_SIZE))) {
                $result = [];
            }
            $this->image_cache = $result;
        }
    }
}
// phpcs:enable
