<?php
// phpcs:disable
 namespace la\core\cache;
if ( ! defined( 'WPINC' ) ) die;

use DateTime;
use DateTimeZone;
use Exception;
use flow\social\FFFeed;
use flow\social\LAFeedWithComments;
use la\core\db\LADBManager;
use la\core\LAUtils;
use la\core\settings\LAGeneralSettings;
use la\core\settings\LASettingsUtils;
use la\core\settings\LAStreamSettings;
use stdClass;

use flow\FFLogManager;

/**
 * Flow-Flow.
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>
 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 *
 * @property LADBManager $db
 * @property LAStreamSettings $stream
 */
class LACacheManager implements LACache{
    private $force;
    private $feeds;
    private $stream;
    private $hash = '';
    private $errors = [];

    protected $db;

	function __construct($context = null, $force = false){
		$this->force = $force;
		$this->db = LAUtils::dbm($context);
	}
	
	/**
	 * @param array $feeds
	 * @param bool $disableCache
	 *
	 * @throws Exception
	 * @return array
	 */
	public function posts($feeds, $disableCache){
		if (isset($_REQUEST['clean']) && $_REQUEST['clean']) $this->db->clean();
		if (isset($_REQUEST['clean-stream']) && $_REQUEST['clean-stream']) $this->db->clean( [ $this->stream->getId() ] );
		$this->feeds = $feeds;
		if ($this->force){
            //$conn = $this->db->conn(); // Don't hold a single connection reference
			$hasNewItems = false;
			$this->hash = time();
			$authErrorsToHandle = []; // Collect auth errors to handle after DB closes
			
			/** @var FFFeed $feed */
			foreach ( $feeds as $feed_id => $feed ) {
				// Concurrency Lock
				$lock_key = 'ff_feed_lock_' . $feed_id;
				if (get_transient($lock_key)) {
					continue;
				}
				set_transient($lock_key, 1, 300); // 5 min lock

				try{
                    $status = ['status' => empty($feed->feed->errors) ? '1' : '0', 'errors' => serialize([])];
					if ($this->expiredLifeTime($feed_id)) {
						$exist_feed_ids = $this->db->getIdPosts($feed_id);
						
						// Close connection before API call to prevent "max_user_connections" error
						if ($this->db->conn() != null) {
							$this->db->conn()->close();
						}

						$posts = $feed->posts(empty($exist_feed_ids));
						
						$errors = $feed->errors();
						$countGotPosts = sizeof( $posts );
						$criticalError = ($countGotPosts == 0 && sizeof($errors) > 0 && $feed->hasCriticalError());
						$status = [
							'last_update' => $criticalError ? 0 : time(), 
							'errors' => $this->serializeErrors($errors), 
							'error_timestamp' => $criticalError ? time() : 0,
							'status' => (int)(!$criticalError)
						];
						
						if (!$criticalError){
							list($new_posts, $existed_posts) = $this->separation($exist_feed_ids, $posts);
							$countPosts4Insert = sizeof($new_posts);
							
							// Re-open connection for saving
							$conn = $this->db->conn(true);
							if ($conn->beginTransaction()){
								$this->save( $feed, $posts);

								if ($countPosts4Insert > 0) {
									$this->db->setOrders($feed_id);
									$hasNewItems = true;
								}
							}
						}
						if (isset($feed->feed->system_enabled) && $feed->feed->system_enabled != (int)!$criticalError) {
							$this->db->systemDisableSource($feed_id, (int)!$criticalError);
						}
                    }
                    $this->db->saveSource($feed_id, $status);
                    
                    // Commit if transaction is active or just ensure data is flushed
                    $this->db->conn()->commit();
                }
                catch( Exception $e){
                    $this->db->conn()->rollback();
                    
                    // Check for DB structure errors and attempt repair
                    $msg = $e->getMessage();
                    if (strpos($msg, "doesn't exist") !== false || strpos($msg, "Unknown column") !== false || strpos($msg, "Data too long") !== false) {
                        FFLogManager::log('DB structure error detected, attempting repair...', ['error' => $msg]);
                        $this->db->repairDB();
                    }
                    
					$hasNewItems = false;
					$errors = [];
					$errors[] = [
						'type' => $feed->getType(),
						'message' => $e->getMessage(),
						'code' => $e->getCode()
					];
                    $status = [
                        'last_update' => 0, 
                        'errors' => $this->serializeErrors($errors), 
                        'error_timestamp' => time(),
                        'status' => 0, 
                        'system_enabled' => 0, 
                        'send_email' => 0
                    ];
                    $this->db->saveSource($feed->id(), $status);
                    $this->db->conn()->commit();
                    
                    // Collect auth errors to handle after DB connection closes
                    $authErrorsToHandle[] = ['feed' => $feed, 'exception' => $e];
				}

				delete_transient($lock_key);
			}
			
			if ($hasNewItems){
				$this->removeOldRecords();
                $this->db->conn()->commit();
			}
			
			// Clean up old errors (older than 30 days)
			$this->cleanupOldErrors();
			$this->db->conn()->commit();

            $this->db->conn()->rollbackAndClose();
            
            // Handle auth errors after DB connection is safely closed
            foreach ($authErrorsToHandle as $errorInfo) {
                $this->handleAuthError($errorInfo['feed'], $errorInfo['exception']);
            }
            
			return [];
		} else {
			// When disable-cache setting is ON, skip API entirely and just return DB posts
			if ($disableCache || LAGeneralSettings::get()->isCacheDisabled()) {
				return $this->get();
			}
			
			if (empty($_REQUEST['hash'])){
				$this->force = true;
				$_REQUEST['force'] = true;
				$this->posts($feeds, $disableCache);
				if (isset($_REQUEST['force'])) unset($_REQUEST['force']);
				$_REQUEST['hash'] = $this->hash();
			}
			return $this->get();
		}
	}
	
	public function hash(){
		return $this->encodeHash($this->hash);
	}

	public function transientHash($streamId){
		$hash = $this->db->getLastUpdateHash($streamId);
		return (false !== $hash) ? $this->encodeHash($hash) : '';
	}

	public function errors(){
		return $this->errors;
	}

	public function moderate(){
	}

	/**
	 * @param LAStreamSettings $stream
	 * @param bool $moderation
	 *
	 * @return void
	 */
	public function setStream($stream, $moderation = false) {
		$this->stream = $stream;
	}

	protected function getGetFields(){
		$select  = "post.post_id as id, post.post_type as type, post.user_nickname as nickname, ";
		$select .= "post.user_pic as userpic, ";
		$select .= "post.post_timestamp as system_timestamp, ";
		$select .= "post.location as location, ";
		$select .= "post.user_link as userlink, post.post_permalink as permalink, ";
		$select .= "post.image_url, post.image_width, post.image_height, post.media_url, post.media_type, ";
		$select .= "post.user_counts_media, post.user_counts_follows, post.user_counts_followed_by, ";
		$select .= "post.media_width, post.media_height, post.post_source, post.post_additional, post.feed_id, ";
		$select .= "post.carousel_size ";
		$select .= FF_ALTERNATIVE_POST_STORAGE ? ", post.post_content " : ", post.user_screenname as screenname, post.post_header, post.post_text as text, post.user_bio ";
		return $select;
	}

	protected function getGetFilters(){
        $conn = $this->db->conn();
		$args[] = $conn->parse('stream.stream_id = ?s', $this->stream->getId());
		$args[] = $conn->parse('cach.enabled = 1');
		$args[] = $conn->parse('cach.boosted = \'nope\'');
		if ($this->stream->showOnlyMediaPosts()) $args[] = "post.image_url IS NOT NULL";
		// Skip hash filtering when alternative caching is enabled - return all available posts
		if (!LAGeneralSettings::get()->isCacheDisabled() && isset($_REQUEST['hash']))
			if (isset($_REQUEST['recent'])){
				$args[] = $conn->parse('post.creation_index > ?s', $this->decodeHash($_REQUEST['hash']));
			} else {
				$args[] = $conn->parse('post.creation_index <= ?s', $this->decodeHash($_REQUEST['hash']));
			}
		return $args;
	}

	/** @noinspection PhpUnusedParameterInspection */
	protected function getOnlyNew($moderation){
		return [];
	}

    /**
     * @return mixed
     */
    private function get(){
	    $where = implode(' AND ', $this->getGetFilters());

	    // Prioritize pinned posts first, then apply regular ordering
	    $order = 'post.is_pinned DESC, post.pinned_order ASC, post.smart_order, post.post_timestamp DESC';
	    if ($this->stream->order() == FF_RANDOM_ORDER)  $order = 'post.is_pinned DESC, post.pinned_order ASC, post.rand_order, post.post_id';
	    if ($this->stream->order() == FF_BY_DATE_ORDER) $order = 'post.is_pinned DESC, post.pinned_order ASC, post.post_timestamp DESC, post.post_id';

	    $moderation = [];
	    foreach ( $this->stream->getAllFeeds() as $feed ) {
		    $moderation[$feed['id']] = LASettingsUtils::YepNope2ClassicStyleSafe($feed, 'mod', false);
	    }

	    $limit = null;
	    $offset = null;
	    $result = [];
	    if (!isset($_REQUEST['recent'])){
		    $page = isset($_REQUEST['page']) ? (int) $_REQUEST['page'] : 0;
		    $limit = $this->stream->getCountOfPostsOnPage();
		    $offset = $page * $limit;

		    if ($page == 0){
			    $result = $this->getOnlyNew($moderation);
			    if (!isset($_REQUEST['countOfPages'])){
				    $totalCount = $this->db->countPostsIf($where);
				    if ($totalCount === false) $totalCount = 0;
				    $countOfPages = ($limit > $totalCount) ? 1 : ceil($totalCount / $limit);
				    $_REQUEST['countOfPages'] = $countOfPages;
			    }
		    }
	    }
	    $resultFromDB = $this->db->getPostsIf($this->getGetFields(), $where, $order, $offset, $limit);
	    if (false === $resultFromDB) $resultFromDB = [];

	    foreach ( $resultFromDB as $row ) {
		    $result[] = $this->buildPost($row, $moderation[$row['feed_id']]);
	    }

	    //$this->errors = LADB::getError($this->db->conn, $this->db->cache_table_name, $this->db->streams_sources_table_name, $this->stream->getId());
	    $this->hash = $this->db->getHashIf($where);
	    return $result;
    }

	/**
	 * @param FFFeed $feed
	 * @param $value
	 *
	 * @throws Exception
	 * @return void
	 */
	private function save( $feed, $value ) {
		if (sizeof($value) > 0) {
            $timezone = get_option( 'timezone_string' );
            $conn = $this->db->conn();
            $only4insertPartOfSqlTemplate = $conn->parse('`creation_index`=?i', $this->hash);
            $status = $this->getDefaultStreamStatus($feed);

            // Fetch existing post_additional to merge and preserve engagement/cta/boost data
            $existingAdditionals = [];
            $postIds = [];
            foreach ($value as $post) {
                $postIds[] = $post->id;
            }
            if (!empty($postIds)) {
                try {
                    $rows = $conn->getAll(
                        'SELECT `post_id`, `post_additional` FROM ?n WHERE `feed_id` = ?s AND `post_id` IN (?a)',
                        $this->db->posts_table_name, $feed->id(), $postIds
                    );
                    if (is_array($rows)) {
                        foreach ($rows as $row) {
                            $existingAdditionals[$row['post_id']] = $row['post_additional'];
                        }
                    }
                } catch (\Throwable $e) {
                    // Fail-safe
                }
            }

	        foreach ($value as $id => $post){
		        FFLogManager::log('Saving post to DB', ['feed_id' => $post->feed_id, 'post_id' => $post->id]);
		        $feed_id = $post->feed_id;

		        $imagePartOfSql = (isset($post->img) && sizeof($post->img) == 3) ?
                    $conn->parse('`image_url`=?s, `image_width`=?i, `image_height`=?i,',
			        $post->img['url'], $post->img['width'], $post->img['height']) : '';
		        $mediaPartOfSql = (isset($post->media) && sizeof($post->media) == 4) ?
                    $conn->parse('`media_url`=?s, `media_width`=?i, `media_height`=?i, `media_type`=?s,',
			        $post->media['url'], $post->media['width'], $post->media['height'], $post->media['type']) : '';

                // Extract incoming additional fields
                $incomingAdd = [];
                if (isset($post->additional)) {
                    $incomingAdd = is_object($post->additional) ? (array)$post->additional : (is_array($post->additional) ? $post->additional : []);
                }

                // Extract existing additional fields to merge (precedence to existing)
                $existingAdd = [];
                if (!empty($existingAdditionals[$post->id])) {
                    $decoded = json_decode($existingAdditionals[$post->id], true);
                    if (is_array($decoded)) {
                        $existingAdd = $decoded;
                    }
                }
                $post->additional = array_merge($incomingAdd, $existingAdd);

				if (FF_ALTERNATIVE_POST_STORAGE){
					$post_content = json_encode( [
						'user_screenname' => $post->screenname,
						'post_header' => $this->prepareText( mb_substr( isset( $post->header ) ? $post->header : '', 0, 200 ) ),
						'post_text' => $this->prepareText($post->text),
						'user_bio' => (isset($post->userMeta->bio) ? ( $post->userMeta->bio . (isset($post->userMeta->website) ? ' ' . $post->userMeta->website : '')) : ''),
					]);

					$only4insertPartOfSql = $conn->parse('?p, ?u', $only4insertPartOfSqlTemplate, [
						'feed_id' => $feed_id,
						'post_id' => $post->id,
						'post_type' => $post->type,
						'post_permalink' => $post->permalink,
						'user_nickname' => $post->nickname,
						'user_pic' => $post->userpic,
						'user_counts_media' => isset($post->userMeta->counts->media) ? $post->userMeta->counts->media : 0,
						'user_counts_follows' => isset($post->userMeta->counts->follows) ? $post->userMeta->counts->follows : 0,
						'user_counts_followed_by' => isset($post->userMeta->counts->followed_by) ? $post->userMeta->counts->followed_by : 0,
						'user_link' => $post->userlink,
						'post_source' => isset($post->source) ? $post->source : '',
						'location' => isset($post->location) ? json_encode($post->location) : '',
						'smart_order' => $post->smart_order,
						'post_status' => $status
					]);

					$common = [
						'post_content' => $post_content,
						'post_timestamp' => $this->correctionTimeZone($post->system_timestamp, $timezone),
						'post_additional' => json_encode($post->additional),
						'carousel_size' => 0
					];
				}
				else {
					$only4insertPartOfSql = $conn->parse('?p, ?u', $only4insertPartOfSqlTemplate, [
						'feed_id' => $feed_id,
						'post_id' => $post->id,
						'post_type' => $post->type,
						'post_permalink' => $post->permalink,
						'user_nickname' => $post->nickname,
						'user_screenname' => $post->screenname,
						'user_pic' => $post->userpic,
						'user_bio' => (isset($post->userMeta->bio) ? json_encode( $post->userMeta->bio . (isset($post->userMeta->website) ? ' ' . $post->userMeta->website : '')) : ''),
						'user_counts_media' => isset($post->userMeta->counts->media) ? $post->userMeta->counts->media : 0,
						'user_counts_follows' => isset($post->userMeta->counts->follows) ? $post->userMeta->counts->follows : 0,
						'user_counts_followed_by' => isset($post->userMeta->counts->followed_by) ? $post->userMeta->counts->followed_by : 0,
						'user_link' => $post->userlink,
						'post_source' => isset($post->source) ? $post->source : '',
						'location' => isset($post->location) ? json_encode($post->location) : '',
						'smart_order' => $post->smart_order,
						'post_status' => $status
					]);

					$common = [
						'post_header' => mb_substr(@$conn->getMySQLi()->real_escape_string(trim($post->header)), 0, 200),
						'post_text'   => $this->prepareText($post->text),
						'post_timestamp' => $this->correctionTimeZone($post->system_timestamp, $timezone),
						'post_additional' => json_encode($post->additional),
						'carousel_size' => 0
					];
				}
				
				if (isset($post->carousel) && sizeof($post->carousel) > 1){
					$this->db->deleteCarousel4Post($feed_id, $post->id);
					foreach ($post->carousel as $media){
						$mediaPartOfSql4carousel = $conn->parse('`media_url`=?s, `media_width`=?i, `media_height`=?i, `media_type`=?s,',
							$media['url'], $media['width'], $media['height'], $media['type']);
						$this->db->addCarouselMedia($feed_id, $post->id, $media['type'], $mediaPartOfSql4carousel);
					}
					$common['carousel_size'] = sizeof($post->carousel);
				}
				
				if (isset($post->comments) && sizeof($post->comments) > 1){
					foreach ($post->comments as $comment){
						$this->db->addComments($post->id, $comment);
					}
				}
				
				$this->db->addOrUpdatePost($only4insertPartOfSql, $imagePartOfSql, $mediaPartOfSql, $common);
			}
		}
	}

    /**
     * @param $date
     * @param $timezone
     *
     * @return int
     * @throws Exception
     */
    private function correctionTimeZone($date, $timezone){
        // Create datetime object with desired timezone
        $timezone = empty($timezone) ? 'UTC' : $timezone;
        $local_timezone = new DateTimeZone($timezone);
        $date_time = new DateTime('now', $local_timezone);
        $offset = $date_time->format('P'); // + 05:00

        // Convert offset to number of hours
        $offset = explode(':', $offset);
        $offset2 = '';
        if($offset[1] == 00){ $offset2 = ''; }
        if($offset[1] == 30){ $offset2 = '.5'; }
        if($offset[1] == 45){ $offset2 = '.75'; }
        $hours = floatval($offset[0] . $offset2);

        // Convert hours to seconds
        $seconds = $hours * 3600;

        // Add/Subtract number of seconds from given unix/gmt/utc timestamp
        $result = floor( $date + $seconds );
        return (int) $result;
    }

	/**
	 * @param $feedId
	 *
	 * @return bool
	 */
	private function expiredLifeTime($feedId){
		if (isset($_REQUEST['force']) && $_REQUEST['force']) return true;
		// if (LAGeneralSettings::get()->isCacheDisabled()) return true;
        $conn = $this->db->conn();
		/** @noinspection SqlResolve */
		$sql = $conn->parse('SELECT `cach`.`feed_id` FROM ?n `cach` WHERE `cach`.`feed_id`=?s AND (`cach`.last_update + `cach`.cache_lifetime * 60) < UNIX_TIMESTAMP()', $this->db->cache_table_name, $feedId);
		return (false !== $conn->getOne($sql));
	}

	/**
	 * @param array $row
	 * @param bool $moderation
	 *
	 * @return stdClass
	 */
	protected function buildPost($row, $moderation = false){
		$post = new stdClass();
		$post->id = $row['id'];
		$post->type = $row['type'];
		$post->nickname = $row['nickname'];
		$post->userpic = $row['userpic'];
		$post->system_timestamp = $row['system_timestamp'];
		$post->timestamp = LASettingsUtils::classicStyleDate($row['system_timestamp'], LAGeneralSettings::get()->dateStyle());
		$post->location = json_decode($row['location']);
		$post->userlink = $row['userlink'];
		$post->user_counts_media = $row['user_counts_media'];
		$post->user_counts_follows = $row['user_counts_follows'];
		$post->user_counts_followed_by = $row['user_counts_followed_by'];
		$post->permalink = $row['permalink'];
		if (FF_ALTERNATIVE_POST_STORAGE){
			$post_content = $row['post_content'];
			$post_content = json_decode($post_content, true);

			$post->screenname = $post_content['user_screenname'];
			$post->header = stripslashes($post_content['post_header']);
			$post->text = stripslashes($post_content['post_text']);
			$post->user_bio = $post_content['user_bio'];
		}
		else {
			$post->screenname = $row['screenname'];
			$post->header = stripslashes($row['post_header']);
			$post->text = stripslashes($row['text']);
			$post->user_bio = json_decode($row['user_bio']);
		}

		$post->mod = $moderation;
		$post->feed = $row['feed_id'];
		$post->with_comments = $this->feeds[$post->feed] instanceof LAFeedWithComments;

		if (!empty($row['post_source'])) $post->source = $row['post_source'];
		if ($row['image_url'] != null){
			$url = $row['image_url'];
			$width = $row['image_width'];
			$tWidth = $this->stream->getImageWidth();
			$height = LASettingsUtils::getScaleHeight($tWidth, $width, $row['image_height']);
			if (($post->type != 'posts') && $this->db->getGeneralSettings()->useProxyServer() && ($width + 50) > $tWidth) $url = $this->proxy($url, $tWidth);
			if (($row['image_width'] == '-1') && ($row['image_height'] == '-1')) {
				$post->img = ['url' => $url, 'type' => 'image'];
			}
			else $post->img = ['url' => $url, 'width' => $tWidth, 'height' => $height, 'type' => 'image'];
			$post->media = $post->img;

			if ($post->type == 'twitter') {
				$post->text = str_replace('%WIDTH%', $post->img['width'], $post->text);
				$post->text = str_replace('%HEIGHT%', $post->img['height'], $post->text);
			}
		}
		if ($row['media_url'] != null){
			$post->media = ['url' => $row['media_url'], 'width' => $row['media_width'], 'height' => $row['media_height'], 'type' => $row['media_type']];
		}
		$post->additional = json_decode($row['post_additional']);
		$post->carousel_size = $row['carousel_size'];
		return $post;
	}

	/**
	 * http://carlo.zottmann.org/2013/04/14/google-image-resizer/
	 * @param string $url
	 * @param string $width
	 * @return string
	 */
	public static function proxy($url, $width){
		if (strpos($url, '/www.', 10) > 10) return $url;
		$query = http_build_query([
			'container' => 'focus',
			'resize_w' => $width,
			'refresh' => 86400, //one day
			'url' => $url]);
		return "https://images1-focus-opensocial.googleusercontent.com/gadgets/proxy?".$query;
	}

	private function separation( $exist_feed_ids, $posts ){
		$existed_posts = [];
		foreach ( $exist_feed_ids as $id ) {
			if (isset($posts[$id])) {
				$existed_posts[] = $posts[$id];
				unset($posts[$id]);
			}
		}
		return [$posts, $existed_posts];
	}
	
	private function encodeHash($hash){
		if (!empty($hash)){
			$postfix  = hash('md5', serialize($this->stream->original()));
			$postfix .= hash('md5', serialize(LAGeneralSettings::get()->original()));
			$postfix .= hash('md5', serialize(LAGeneralSettings::get()->originalAuth()));
			return $hash . "." . $postfix;
		}
		return $hash;
	}

	protected function decodeHash($hash){
		$pos = strpos($hash, ".");
		if ($pos === false) return $hash;
		if ($pos == 0) return '';
		return substr($hash, 0, $pos);
	}

    /**
     * @param FFFeed $feed
     *
     * @return string
     */
	private function getDefaultStreamStatus($feed) {
		if ($this->moderation($feed)){
            $use_soft_moderation_policy = LASettingsUtils::YepNope2ClassicStyleSafe($feed->feed, 'mod-approve');
            return $use_soft_moderation_policy ? 'approved' : 'new';
		}
		return 'approved';
	}

	private function moderation($feed) {
		if (isset($feed->feed->mod)){
			return LASettingsUtils::YepNope2ClassicStyle($feed->feed->mod, false);
		}
		return false;
	}

	private function removeOldRecords() {
		$settings = $this->db->getGeneralSettings();
		$this->db->removeOldRecords($settings->getCountOfPostsByFeed());
	}

	private function prepareText( $text ) {
		$text = str_replace("\r\n", "<br>", $text);
		$text = str_replace("\n", "<br>", $text);
		$text = trim($text);
		return @$this->db->conn()->getMySQLi()->real_escape_string($text);
	}

    	private function serializeErrors($errors){
		foreach ( $errors as &$error ) {
            if (isset($error['url'])){
                $error['url'] = $this->prepareString4Serialize($error['url']);
            }
            if (isset($error['message'])){
                $error['message'] = $this->prepareString4Serialize($error['message']);
            }
        }
        return serialize($errors);
    }

    private function prepareString4Serialize( $str ) {
        $str = str_replace('?n', '%3fn', $str);
        $str = str_replace('?s', '%3fs', $str);
        $str = str_replace('?i', '%3fi', $str);
        $str = str_replace('?u', '%3fu', $str);
        $str = str_replace('?a', '%3fa', $str);
        $str = str_replace('?p', '%3fp', $str);
        return $str;
    }
	
	/**
	 * Handle authentication errors by triggering immediate token refresh
	 * @param FFFeed $feed
	 * @param Exception $e
	 */
	private function handleAuthError($feed, $e) {
		$feedType = $feed->getType();
		$errorMsg = $e->getMessage();
		$errorCode = $e->getCode();
		
		// Check if this is an auth-related error (401, 403, or token-related message)
		$isAuthError = false;
		if ($errorCode === 401 || $errorCode === 403) {
			$isAuthError = true;
		} else if (stripos($errorMsg, 'token') !== false || stripos($errorMsg, 'unauthorized') !== false || stripos($errorMsg, 'authentication') !== false) {
			$isAuthError = true;
		}
		
		if (!$isAuthError) {
			return;
		}
		
		// Trigger token refresh for supported platforms
		if ($feedType === 'tiktok') {
			$this->triggerTikTokTokenRefresh();
		} else if ($feedType === 'facebook' || $feedType === 'instagram') {
			$this->triggerFacebookTokenRefresh();
		}
	}
	
	/**
	 * Trigger immediate TikTok token refresh via auth server
	 */
	private function triggerTikTokTokenRefresh() {
		try {
			if (method_exists($this->db, 'refreshTikTokAccessToken')) {
				error_log('[Flow-Flow] TikTok auth error detected, triggering token refresh');
				$this->db->refreshTikTokAccessToken(true); // force refresh
			}
		} catch (\Exception $e) {
			error_log('[Flow-Flow] Failed to trigger TikTok token refresh: ' . $e->getMessage());
		}
	}
	
	/**
	 * Trigger immediate Facebook token refresh
	 */
	private function triggerFacebookTokenRefresh() {
		try {
			// Facebook token refresh is handled by LAFacebookCacheManager
			if (isset($this->context['facebook_cache'])) {
				error_log('[Flow-Flow] Facebook auth error detected, triggering token refresh');
				$facebookCache = $this->context['facebook_cache'];
				if (method_exists($facebookCache, 'getAccessToken')) {
					$facebookCache->getAccessToken(); // This triggers refresh if needed
				}
			}
		} catch (\Exception $e) {
			error_log('[Flow-Flow] Failed to trigger Facebook token refresh: ' . $e->getMessage());
		}
	}
	
	/**
	 * Clean up error entries older than 30 days
	 * Clears errors and error_timestamp for feeds that haven't had errors recently
	 */
	private function cleanupOldErrors() {
		try {
			$thirtyDaysAgo = time() - (30 * DAY_IN_SECONDS);
			$sql = 'UPDATE ?n SET `errors` = NULL, `error_timestamp` = 0 WHERE `error_timestamp` > 0 AND `error_timestamp` < ?i';
			$this->db->conn()->query($sql, $this->db->cache_table_name, $thirtyDaysAgo);
		} catch (\Exception $e) {
			error_log('[Flow-Flow] Failed to cleanup old errors: ' . $e->getMessage());
		}
	}
}
// phpcs:enable
