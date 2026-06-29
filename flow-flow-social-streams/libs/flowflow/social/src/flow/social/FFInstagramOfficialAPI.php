<?php
namespace flow\social;

use Cake\Cache\SimpleCacheEngine;
use Exception;
use flow\social\cache\FFImageSizeCacheBase;
use flow\social\cache\LAFacebookCacheManager;
use stdClass;

/**
 *
 * @author    navdeykin <navdeykin@gmail.com>
 * @copyright 2014-2020 Looks Awesome
 */
class FFInstagramOfficialAPI implements FFInstagramAPI
{
    private static $version = 'v24.0';

    private $size = 0;
    private $pagination = true;
    private $count = 0;
    private $url;
    private $base_url;
    /** @var LAFacebookCacheManager */
    private $facebookCache;
    /** @var SimpleCacheEngine */
    private $cache;
    /** @var FFImageSizeCacheBase */
    private $imageCache;

    public function init($context, $feed)
    {
        // Auto-resolve IG user id if not provided
        if (empty($this->ig_user_id)) {
            $token = isset($this->accessToken) ? $this->accessToken : (isset($this->token) ? $this->token : null);
            $hint = isset($this->content) ? $this->content : null;
            if (!empty($token)) {
                $resolved = $this->resolveInstagramIdFromToken($token, $hint);
                if (!empty($resolved))
                    $this->ig_user_id = $resolved;
            }
        }

        $this->facebookCache = $context['facebook_cache'];
        $this->cache = FFFeedUtils::getCache($context);
        $this->imageCache = $context['image_size_cache'];
    }

    /**
     * @param $feed
     * @param $count
     *
     * @throws LASocialException
     */
    public function deferredInit($feed, $count)
    {
        $this->count = $count;
        $accessToken = $this->facebookCache->getAccessToken();
        if (isset($feed->{'timeline-type'})) {
            $version = self::$version;
            switch ($feed->{'timeline-type'}) {
                case 'user_timeline':
                    $content = FFFeedUtils::preparePrefixContent($feed->content, '@');
                    $page = $this->getPageId();
                    $fields = "website,followers_count,follows_count,media_count,username,name,profile_picture_url,biography,media.limit({$count}){comments_count,like_count,media_type,caption,children{media_url,media_type,thumbnail_url},permalink,timestamp,media_url,thumbnail_url}";
                    if (!empty($content)) {
                        $fields = "business_discovery.username({$content}){{$fields}}";
                    }
                    $fields = urlencode($fields);
                    $this->url = "https://graph.facebook.com/{$version}/{$page}?fields={$fields}&access_token={$accessToken}";
                    break;
                case 'tag':
                    $content = FFFeedUtils::preparePrefixContent($feed->content, '#');
                    $page = $this->getPageId();
                    $hashtagId = $this->getHashtagId($content, $page);
                    // Use recent_media by default for backwards compatibility
                    $hashtagType = isset($feed->{'hashtag-type'}) ? $feed->{'hashtag-type'} : 'recent';
                    $edge = $hashtagType === 'top' ? 'top_media' : 'recent_media';
                    $fields = urlencode('caption,children{media_url,media_type,thumbnail_url},comments_count,id,like_count,media_type,media_url,permalink,timestamp');
                    $this->url = "https://graph.facebook.com/{$version}/{$hashtagId}/{$edge}?user_id={$page}&fields={$fields}&access_token={$accessToken}";
                    break;
            }
            $this->base_url = $this->url;
        }
    }

    public function onePagePosts()
    {
        $result = [];
        $isHashtagFeed = strpos($this->url, '/top_media') !== false || strpos($this->url, '/recent_media') !== false;

        if (defined('FF_LOG_FILE_DEST')) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            @error_log('FFInstagramOfficialAPI request' . ($isHashtagFeed ? ' [hashtag]' : '') . ': ' . $this->url . PHP_EOL, 3, FF_LOG_FILE_DEST);
        }
        $data = FFFeedUtils::getFeedDataWithThrowException($this->url);
        if (isset($data['response']) && is_string($data['response'])) {
            $response = $data['response'];
            if (defined('FF_LOG_FILE_DEST')) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                @error_log('FFInstagramOfficialAPI response' . ($isHashtagFeed ? ' [hashtag]' : '') . ': ' . $response . PHP_EOL, 3, FF_LOG_FILE_DEST);
            }
            //fix malformed
            //http://stackoverflow.com/questions/19981442/decoding-instagram-reply-php
            //In case of a problem, comment out this line
            $response = html_entity_decode($response);
            $page = json_decode($response);

            // Check for API error response
            if (isset($page->error)) {
                $errorMsg = isset($page->error->message) ? $page->error->message : 'Unknown Instagram API error';
                if (defined('FF_LOG_FILE_DEST')) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    @error_log('FFInstagramOfficialAPI API error: ' . $errorMsg . PHP_EOL, 3, FF_LOG_FILE_DEST);
                }
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new LASocialException(function_exists('esc_html') ? esc_html($errorMsg) : htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8'));
            }

            $userMeta = null;
            $with_user_meta = false;
            if (isset($page->business_discovery)) {
                $userMeta = $this->fillUser($page->business_discovery);
                $page = $page->business_discovery->media;
                $with_user_meta = true;
            }

            // Hashtag feeds don't support cursor-based pagination (max 50 results, no paging)
            if ($isHashtagFeed) {
                $this->pagination = false;
            } else if (isset($page->paging->cursors->after)) {
                $this->url = str_replace('media.limit', urlencode("media.after({$page->paging->cursors->after}).limit"), $this->base_url);
            } else {
                $this->pagination = false;
            }

            if (!isset($page->data) || !is_array($page->data)) {
                if (defined('FF_LOG_FILE_DEST')) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    @error_log('FFInstagramOfficialAPI: No data array in response.' . ($isHashtagFeed ? ' Hashtag recent_media only returns posts from last 24h.' : '') . PHP_EOL, 3, FF_LOG_FILE_DEST);
                }
                return $result;
            }

            foreach ($page->data as $item) {
                // Filter out reels from hashtag feeds
                if ($isHashtagFeed) {
                    if (isset($item->permalink) && strpos($item->permalink, '/reel/') !== false) {
                        continue;
                    }
                }

                $item->user = $with_user_meta ? $userMeta : $this->getUser($item);
                $post = $this->parsePost($item, $isHashtagFeed);

                if ($with_user_meta) {
                    $post->userMeta = $userMeta;
                }
                $result[] = $post;
            }

            if ($isHashtagFeed && empty($result) && defined('FF_LOG_FILE_DEST')) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                @error_log('FFInstagramOfficialAPI: Hashtag feed returned 0 posts. Note: recent_media only returns posts from last 24 hours. Consider using "top" hashtag type instead.' . PHP_EOL, 3, FF_LOG_FILE_DEST);
            }
        } else {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new LASocialException('FFInstagram has returned the empty data.', ['url' => $this->url]);
        }
        return $result;
    }

    public function nextPage($result)
    {
        if ($this->pagination) {
            $size = sizeof($result);
            if ($size == $this->size) {
                return false;
            } else {
                $this->size = $size;
                return $this->count > $size;
            }
        }
        return false;
    }

    /**
     * @param $item
     *
     * @return array
     */
    public function getComments($item)
    {
        return [];
    }

    private function parsePost($post, $isHashtagFeed = false)
    {
        $tc = new stdClass();
        $tc->id = (string) $post->id;
        $tc->header = '';
        $tc->nickname = (string) $post->user->username;
        $tc->screenname = FFFeedUtils::removeEmoji((string) $post->user->full_name);
        // Use htmlspecialchars instead of deprecated mb_convert_encoding for HTML entity encoding
        $tc->screenname = htmlspecialchars($tc->screenname, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tc->userpic = (string) $post->user->profile_picture;
        $tc->system_timestamp = isset($post->timestamp) ? strtotime($post->timestamp) : time();
        $tc->text = isset($post->caption) ? FFFeedUtils::hashtagLinks($post->caption) : '';
        $tc->userlink = 'http://instagram.com/' . ($tc->nickname ?: 'instagram');
        $tc->permalink = $post->permalink;
        $tc->location = '';
        $tc->additional = ['likes' => (string) (isset($post->like_count) ? $post->like_count : 0), 'comments' => (string) (isset($post->comments_count) ? $post->comments_count : 0)];

        $tc->carousel = [];
        $image_thumbnail_url = '';
        $width = $height = '300';

        // Hashtag API does NOT return media_url — must use thumbnail_url or oEmbed fallback
        $hasMediaUrl = isset($post->media_url) && !empty($post->media_url);

        if ($post->media_type == 'VIDEO') {
            if (isset($post->thumbnail_url)) {
                $image_thumbnail_url = $post->thumbnail_url;
                $s = $this->imageCache->size($image_thumbnail_url);
                $width = $s['width'];
                $height = $s['height'];
            } else {
                // Video without thumbnail - fetch it via oEmbed API
                $thumbnailUrl = $this->getVideoThumbnail($post->permalink);
                if ($thumbnailUrl) {
                    $image_thumbnail_url = $thumbnailUrl;
                    $s = $this->imageCache->size($image_thumbnail_url);
                    $width = $s['width'];
                    $height = $s['height'];
                } else if ($hasMediaUrl) {
                    $image_thumbnail_url = $post->media_url;
                    $width = '640';
                    $height = '640';
                } else {
                    // Hashtag feed video with no thumbnail or media_url — use oEmbed or placeholder
                    $image_thumbnail_url = '';
                    $width = '640';
                    $height = '640';
                }
            }
        } else if ($post->media_type == 'CAROUSEL_ALBUM') {
            if (isset($post->children) && isset($post->children->data)) {
                $tc->carousel = $this->getCarousel($post, 600, FFFeedUtils::getScaleHeight(600, $width, $height));
                foreach ($tc->carousel as $item) {
                    if ($item['type'] === 'image') {
                        $image_thumbnail_url = $item['url'];
                        $s = $this->imageCache->size($image_thumbnail_url);
                        $width = $s['width'];
                        $height = $s['height'];
                        break;
                    }
                }
            }
            // Fallback: if carousel had no usable images, try media_url
            if (empty($image_thumbnail_url) && $hasMediaUrl) {
                $image_thumbnail_url = $post->media_url;
                $s = $this->imageCache->size($image_thumbnail_url);
                $width = $s['width'];
                $height = $s['height'];
            }
        } else {
            if ($hasMediaUrl) {
                $image_thumbnail_url = $post->media_url;
                $s = $this->imageCache->size($image_thumbnail_url);
                $width = $s['width'];
                $height = $s['height'];
            } else if (isset($post->thumbnail_url)) {
                // Hashtag feed fallback: use thumbnail_url if available
                $image_thumbnail_url = $post->thumbnail_url;
                $s = $this->imageCache->size($image_thumbnail_url);
                $width = $s['width'];
                $height = $s['height'];
            } else {
                // Hashtag feed: no media_url, try oEmbed
                $thumbnailUrl = $this->getVideoThumbnail($post->permalink);
                if ($thumbnailUrl) {
                    $image_thumbnail_url = $thumbnailUrl;
                    $s = $this->imageCache->size($image_thumbnail_url);
                    $width = $s['width'];
                    $height = $s['height'];
                }
            }
        }
        $tc->img = ['url' => $image_thumbnail_url, 'width' => 300, 'height' => FFFeedUtils::getScaleHeight(300, $width, $height)];

        // Build media content — hashtag feeds may not have media_url
        if ($hasMediaUrl) {
            $media_post = $post;
        } else if (isset($post->children) && isset($post->children->data) && !empty($post->children->data)) {
            $media_post = $post->children->data[0];
        } else {
            // Create a minimal media post object with the thumbnail
            $media_post = new stdClass();
            $media_post->media_type = isset($post->media_type) ? $post->media_type : 'IMAGE';
            $media_post->media_url = $image_thumbnail_url;
        }
        $tc->media = $this->getMediaContent($media_post, 600, FFFeedUtils::getScaleHeight(600, $width, $height));

        return $tc;
    }

    private function getCarousel($post, $width, $height)
    {
        $carousel = [];
        foreach ($post->children->data as $item) {
            $carousel[] = $this->getMediaContent($item, $width, $height);
        }
        return $carousel;
    }

    private function getMediaContent($item, $width = 600, $height = 600)
    {
        if (isset($item->media_type) && $item->media_type == 'VIDEO') {
            return [
                'type' => 'video/mp4',
                'url' => $item->media_url,
                'width' => $width,
                'height' => $height
            ];
        } else {
            return ['type' => 'image', 'url' => $item->media_url, 'width' => $width, 'height' => $height];
        }
    }

    private function fillUser($post)
    {
        $result = new stdClass();
        $result->username = $post->username;
        $result->full_name = isset($post->name) ? $post->name : '';
        $result->id = $post->id;
        $result->bio = isset($post->biography) ? $post->biography : '';
        $result->website = isset($post->website) ? $post->website : '';
        $result->counts = new stdClass();
        $result->counts->media = $post->media_count;
        $result->counts->follows = $post->follows_count;
        $result->counts->followed_by = $post->followers_count;
        $result->profile_picture = $post->profile_picture_url;
        return $result;
    }

    /**
     * @return mixed
     * @throws LASocialException
     */
    private function getPageId()
    {
        $version = self::$version;
        $accessToken = $this->facebookCache->getAccessToken();

        try {
            // update 31/01/2023, making one request instead two

            //            $url = "https://graph.facebook.com/{$version}/me/accounts?access_token={$accessToken}";
//            $facebookPageId = $this->cache->get(md5($url));

            $fields = urlencode('id,name,access_token,instagram_business_account{id}');
            $url = "https://graph.facebook.com/{$version}/me/accounts?fields={$fields}&access_token={$accessToken}";

            $facebookPageId = $this->cache->get(md5('facebookPageId'));
            $instagram_business_account = $this->cache->get(md5('instagram_business_account'));

            if (is_null($facebookPageId) || is_null($instagram_business_account)) {
                if (defined('FF_LOG_FILE_DEST')) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_var_export
                    @error_log('FFInstagramOfficialAPI getPageId cache miss. FB Page ID: ' . var_export($facebookPageId, true) . ', IG ID: ' . var_export($instagram_business_account, true) . PHP_EOL, 3, FF_LOG_FILE_DEST);
                }
                $request = FFFeedUtils::getFeedDataWithThrowException($url);
                $json = json_decode($request['response']);
                
                $instagram_business_account = null;
                
                if (isset($json->data)) {
                    foreach ($json->data as $item) {
                        if (!isset($item->access_token) || !isset($item->instagram_business_account)) {
                            continue;
                        }
                        
                        $pageName = isset($item->name) ? $item->name : 'Unknown';
                        $pageId = isset($item->id) ? $item->id : 'Unknown';
     
                        $facebookPageId = $item->id;
                        $this->cache->set(md5('facebookPageId'), $facebookPageId, 60 * 60 * 24);//one day
                        $instagram_business_account = $item->instagram_business_account->id;
                        if (defined('FF_LOG_FILE_DEST')) {
                            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_print_r
                            @error_log('FFInstagramOfficialAPI getPageId setting cache. Found IG ID on page: ' . $pageName . ' (' . $pageId . '). Item: ' . print_r($item, true) . PHP_EOL, 3, FF_LOG_FILE_DEST);
                        }
                        $this->cache->set(md5('instagram_business_account'), $instagram_business_account, 60 * 60 * 24);//one day
                        break;
                    }
                }
                
                if (empty($instagram_business_account)) {
                     $msg = 'Could not find an Instagram Business Account connected to any of your Facebook Pages. Please ensure you have converted your Instagram account to a Professional/Business account and connected it to a Facebook Page.';
                     if (defined('FF_LOG_FILE_DEST')) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_print_r
                        @error_log('FFInstagramOfficialAPI Error: ' . $msg . ' Response: ' . print_r($json, true) . PHP_EOL, 3, FF_LOG_FILE_DEST);
                     }
                     // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                     throw new LASocialException($msg);
                }
            } else {
            }
            /*
                        $url = "https://graph.facebook.com/{$version}/{$facebookPageId}?fields=instagram_business_account&access_token={$accessToken}";
                        $instagram_business_account = $this->cache->get(md5($url));
                        if (is_null($instagram_business_account)){
                            $request = FFFeedUtils::getFeedDataWithThrowException($url);
                            $json = json_decode($request['response']);
                            $instagram_business_account = isset($json->instagram_business_account->id) ? $json->instagram_business_account->id : $json->id;
                            $this->cache->set(md5($url), $instagram_business_account, 60 * 60 * 24);//one day
                        }*/
            return $instagram_business_account;
        } catch (LASocialException $e) {
            throw $e;
        } catch (Exception $exception) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new LASocialException('Failed to get instagram business account id', [], $exception);
        }
    }

    /**
     * @param $hashtag
     * @param $page
     *
     * @return
     * @throws LASocialException
     * @throws LASocialRequestException
     */
    private function getHashtagId($hashtag, $page)
    {
        $version = self::$version;
        $accessToken = $this->facebookCache->getAccessToken();
        $url = "https://graph.facebook.com/{$version}/ig_hashtag_search?user_id={$page}&q={$hashtag}&access_token={$accessToken}";
        $hashtagId = $this->cache->get(md5($url));
        if (is_null($hashtagId)) {
            $request = FFFeedUtils::getFeedDataWithThrowException($url);
            $json = json_decode($request['response']);
            if (isset($json->data)) {
                foreach ($json->data as $item) {
                    if (isset($item->id) && !empty($item->id)) {
                        $this->cache->set(md5($url), $item->id, 60 * 60 * 24 * 7);//week
                        return $item->id;
                    }
                }
            }
            throw new LASocialException('This tag does not exists or it has been hidden by Instagram');
        }
        return $hashtagId;
    }

    /**
     * Fetch thumbnail_url for a video media item using oEmbed endpoint
     * 
     * @param string $permalink Post URL
     * @return string|null
     */
    private function getVideoThumbnail($permalink)
    {
        $version = self::$version;
        $accessToken = $this->facebookCache->getAccessToken();
        $encodedUrl = urlencode($permalink);
        $url = "https://graph.facebook.com/{$version}/instagram_oembed?url={$encodedUrl}&maxwidth=320&fields=thumbnail_url,author_name,provider_name,provider_url&access_token={$accessToken}";

        // Cache the result for 24 hours
        $cacheKey = md5('video_thumbnail_' . $permalink);
        $cachedThumbnail = $this->cache->get($cacheKey);

        if (!is_null($cachedThumbnail)) {
            return $cachedThumbnail;
        }

        try {
            $request = FFFeedUtils::getFeedDataWithThrowException($url);
            $json = json_decode($request['response']);

            if (isset($json->thumbnail_url)) {
                $thumbnailUrl = $json->thumbnail_url;
                $this->cache->set($cacheKey, $thumbnailUrl, 60 * 60 * 24); // Cache for 24 hours
                return $thumbnailUrl;
            }

            return null;
        } catch (Exception $e) {
            return null;
        }
    }

    private function getUser($post)
    {
        $user = new stdClass();
        $user->username = '';
        $user->full_name = '';
        $user->id = '';
        $user->bio = '';
        $user->website = '';
        $user->profile_picture = '';
        return $user;
    }

    /** Resolve IG business account id (1784...) from a user token by matching page name or username. */
    private function resolveInstagramIdFromToken($accessToken, $hint = null)
    {
        if (defined('FF_LOG_FILE_DEST')) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            @error_log('resolveInstagramIdFromToken: ' . $hint . PHP_EOL, 3, FF_LOG_FILE_DEST);
        }
        $endpoint = 'https://graph.facebook.com/v19.0/me/accounts?fields=id,name,instagram_business_account&access_token=' . urlencode($accessToken);
        $data = FFFeedUtils::getFeedData($endpoint);
        $json = json_decode(isset($data['response']) ? $data['response'] : '');
        if (!$json || !isset($json->data) || !is_array($json->data))
            return null;
        $igId = null;
        foreach ($json->data as $page) {
            if (isset($page->instagram_business_account)) {
                if ($hint && isset($page->name) && strcasecmp($page->name, $hint) === 0) {
                    return $page->instagram_business_account->id;
                }
                if ($igId === null)
                    $igId = $page->instagram_business_account->id;
            }
        }
        return $igId;
    }
}
