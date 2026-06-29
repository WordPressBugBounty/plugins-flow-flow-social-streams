<?php namespace flow\social;

use Exception;
use flow\social\timelines\FFCollections;
use flow\social\timelines\FFFavorites;
use flow\social\timelines\FFHomeTimeline;
use flow\social\timelines\FFListTimeline;
use flow\social\timelines\FFSearch;
use flow\social\timelines\FFTimeline;
use flow\social\timelines\FFUserTimeline;
use stdClass;

if ( ! defined( 'WPINC' ) ) die;
/**
 * Flow-Flow.
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>
 * @link      http://looks-awesome.com
 * @copyright 2014-2016 Looks Awesome
 *
 * @noinspection PhpUnused
 */
class FFTwitter extends FFBaseFeed{
	private static $GET = "GET";

	/** @var  FFTimeline */
	private $timeline;
	/** @var FFTwitterAPIExchange */
	private $restService;
	private $image;
	private $media;
	private $carousel;
	private $feed;

	public function __construct() {
		parent::__construct( 'twitter' );
	}

	/**
	 * @param stdClass $feed
	 *
	 * @throws Exception
	 */
	public function deferredInit($feed){
		$this->feed = $feed;
		$this->restService = new FFTwitterAPIExchange($feed->twitter_access_settings);
		$this->timeline = $this->getTimeline($feed);
	}

	/**
	 * Resolves a username/screen name to a Twitter User ID using X API v2.
	 * Results are cached in WordPress transients for 30 days to limit API requests.
	 *
	 * @param string $username
	 * @return string|null
	 */
	private function getUserId($username) {
		$username = ltrim(trim($username), '@');
		if (empty($username)) {
			return null;
		}
		$cache_key = 'ff_twitter_uid_' . md5(strtolower($username));
		$user_id = get_transient($cache_key);
		if (false === $user_id) {
			$url = "https://api.twitter.com/2/users/by/username/{$username}";
			$response = json_decode($this->restService
				->setGetfield('')
				->buildOauth($url, 'GET')
				->performRequest(), true);
			
			if (isset($response['errors'])) {
				foreach ($response['errors'] as $error) {
					$msg = isset($error['detail']) ? $error['detail'] : (isset($error['message']) ? $error['message'] : 'User not found or lookup failed');
					$this->errors[] = array(
						'type'    => 'twitter',
						'message' => $this->filterErrorMessage($msg),
						'url' => $url
					);
				}
				return null;
			}
			if (isset($response['data']['id'])) {
				$user_id = $response['data']['id'];
				set_transient($cache_key, $user_id, 30 * DAY_IN_SECONDS);
			}
		}
		return $user_id;
	}

	/**
	 * Resolves a List Name to a Twitter List ID for a given user using X API v2.
	 * Results are cached in WordPress transients for 30 days to limit API requests.
	 *
	 * @param string $user_id
	 * @param string $list_name
	 * @return string|null
	 */
	private function getListId($user_id, $list_name) {
		$list_name = trim($list_name);
		if (empty($list_name)) {
			return null;
		}
		$cache_key = 'ff_twitter_listid_' . $user_id . '_' . md5(strtolower($list_name));
		$list_id = get_transient($cache_key);
		if (false === $list_id) {
			$url = "https://api.twitter.com/2/users/{$user_id}/owned_lists";
			$response = json_decode($this->restService
				->setGetfield('')
				->buildOauth($url, 'GET')
				->performRequest(), true);
			
			if (isset($response['errors'])) {
				foreach ($response['errors'] as $error) {
					$msg = isset($error['detail']) ? $error['detail'] : (isset($error['message']) ? $error['message'] : 'Owned lists lookup failed');
					$this->errors[] = array(
						'type'    => 'twitter',
						'message' => $this->filterErrorMessage($msg),
						'url' => $url
					);
				}
				return null;
			}
			if (isset($response['data']) && is_array($response['data'])) {
				foreach ($response['data'] as $list) {
					if (strcasecmp($list['name'], $list_name) === 0 || strcasecmp(sanitize_title($list['name']), sanitize_title($list_name)) === 0) {
						$list_id = $list['id'];
						break;
					}
				}
			}
			if ($list_id) {
				set_transient($cache_key, $list_id, 30 * DAY_IN_SECONDS);
			}
		}
		return $list_id;
	}

	/**
	 * @return array
	 * @throws Exception
	 */
	public function onePagePosts(){
		$feed = $this->feed;
		$timeline_type = $feed->{'timeline-type'};
		
		$url = '';
		$params = [
			'tweet.fields' => 'attachments,author_id,created_at,entities,id,text,public_metrics,referenced_tweets',
			'user.fields' => 'id,name,username,profile_image_url',
			'media.fields' => 'duration_ms,height,media_key,preview_image_url,type,url,width,variants',
			'expansions' => 'attachments.media_keys,author_id,referenced_tweets.id'
		];

		if ($timeline_type === 'search') {
			$search_query = $feed->content;
			if (!empty($feed->lang) && $feed->lang !== 'all') {
				$search_query .= ' lang:' . $feed->lang;
			}
			$params['query'] = $search_query;
			$params['max_results'] = max(10, min(100, $this->count));
			$url = "https://api.twitter.com/2/tweets/search/recent";
		} else {
			// Other feeds require user ID resolution
			$username = $feed->content;
			$user_id = $this->getUserId($username);
			if (empty($user_id)) {
				return array();
			}

			if ($timeline_type === 'home_timeline' || $timeline_type === 'user_timeline') {
				$params['max_results'] = max(5, min(100, $this->count));
				$exclude = [];
				if (empty($feed->replies) || $feed->replies === 'nope') {
					$exclude[] = 'replies';
				}
				if (empty($feed->retweets) || $feed->retweets === 'nope') {
					$exclude[] = 'retweets';
				}
				if (!empty($exclude)) {
					// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
					$params['exclude'] = implode(',', $exclude);
				}
				$url = "https://api.twitter.com/2/users/{$user_id}/tweets";
			} elseif ($timeline_type === 'favorites') {
				$params['max_results'] = max(5, min(100, $this->count));
				$url = "https://api.twitter.com/2/users/{$user_id}/liked_tweets";
			} elseif ($timeline_type === 'list_timeline') {
				$list_name = $feed->{'list-name'};
				$list_id = $this->getListId($user_id, $list_name);
				if (empty($list_id)) {
					$this->errors[] = array(
						'type'    => 'twitter',
						'message' => $this->filterErrorMessage("List '{$list_name}' not found for user '{$username}'"),
						'url' => ''
					);
					return array();
				}
				$params['max_results'] = max(5, min(100, $this->count));
				$url = "https://api.twitter.com/2/lists/{$list_id}/tweets";
			} else {
				// collection_timeline is removed
				return array();
			}
		}

		$getfield = '?' . http_build_query($params);
		$json = json_decode($this->restService
			->setGetfield($getfield)
			->buildOauth($url, self::$GET)
			->performRequest(), $assoc = TRUE);

		if (isset($json['errors']) && is_array($json['errors'])) {
			foreach ($json['errors'] as $error) {
				$msg = isset($error['detail']) ? $error['detail'] : (isset($error['message']) ? $error['message'] : 'Unknown Twitter API error');
				$this->errors[] = array(
					'type'    => 'twitter',
					'message' => $this->filterErrorMessage($msg),
					'url' => $url
				);
			}
			throw new Exception();
			return array();
		}
		
		if (isset($json['detail'])) {
			$this->errors[] = array(
				'type'    => 'twitter',
				'message' => $this->filterErrorMessage($json['detail']),
				'url' => $url
			);
			throw new Exception();
			return array();
		}

		return $this->parseRequest($json);
	}

	private function parseRequest($json) {
		$result = [];

		if (isset($json['error']) && !empty($json['error'])){
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new LASocialException(function_exists('esc_html') ? esc_html($json['error']) : htmlspecialchars($json['error'], ENT_QUOTES, 'UTF-8'), ['type' => 'twitter']);
		}

		if (!isset($json['data']) || !is_array($json['data'])) {
			return $result;
		}

		$users = [];
		if (isset($json['includes']['users'])) {
			foreach ($json['includes']['users'] as $u) {
				$users[$u['id']] = $u;
			}
		}

		$media_items = [];
		if (isset($json['includes']['media'])) {
			foreach ($json['includes']['media'] as $m) {
				$media_items[$m['media_key']] = $m;
			}
		}

		foreach ($json['data'] as $t) {
			$this->image = null;
			$this->media = null;
			$this->carousel = [];
			
			$tc = new stdClass();
			$tc->feed_id = $this->id();
			$tc->smart_order = 0;
			$tc->id = $t['id'];
			$tc->type = $this->getType();

			$author_id = $t['author_id'] ?? '';
			if (isset($users[$author_id])) {
				$user = $users[$author_id];
				$tc->nickname = '@' . $user['username'];
				$tc->screenname = (string)$user['name'];
				$profile_pic = isset($user['profile_image_url']) ? $user['profile_image_url'] : '';
				$tc->userpic = str_replace('.jpg', '_200x200.jpg', str_replace('_normal', '', (string)$profile_pic));
				$tc->userlink = 'https://twitter.com/' . $user['username'];
			} else {
				$tc->nickname = '';
				$tc->screenname = '';
				$tc->userpic = '';
				$tc->userlink = 'https://twitter.com';
			}

			$tc->system_timestamp = strtotime($t['created_at']);
			$tc->text = $this->getText($t);
			$tc->permalink = $tc->userlink . '/status/' . $tc->id;
			
			// Extract media and build carousel
			if (isset($t['attachments']['media_keys'])) {
				foreach ($t['attachments']['media_keys'] as $media_key) {
					if (isset($media_items[$media_key])) {
						$m = $media_items[$media_key];
						$type = $m['type'];
						$width = $m['width'] ?? 600;
						$height = $m['height'] ?? 400;

						$scale_width = $width;
						$scale_height = $height;
						if ($scale_width > 600) {
							$scale_height = FFFeedUtils::getScaleHeight(600, $width, $height);
							$scale_width = 600;
						}

						if ($type === 'photo') {
							$media_url = $m['url'] ?? '';
							if ($media_url) {
								if (is_null($this->image)) {
									$this->image = $this->createImage($media_url, $width, $height);
								}
								$this->carousel[] = $this->createMedia($media_url, $scale_width, $scale_height, 'image');
							}
						} elseif ($type === 'video' || $type === 'animated_gif') {
							$preview_url = $m['preview_image_url'] ?? '';
							if (is_null($this->image) && $preview_url) {
								$this->image = $this->createImage($preview_url, $width, $height);
							}

							$video_url = '';
							$content_type = 'video/mp4';
							if (isset($m['variants'])) {
								foreach ($m['variants'] as $variant) {
									if (isset($variant['content_type']) && $variant['content_type'] === 'video/mp4') {
										$video_url = $variant['url'];
										break;
									}
								}
							}
							if (empty($video_url) && isset($m['url'])) {
								$video_url = $m['url'];
							}

							if ($video_url) {
								$this->media = $this->createMedia($video_url, $scale_width, $scale_height, $content_type);
							}
						}
					}
				}
			}

			$tc->media = $this->media;
			$tc->header = '';

			if (!is_null($this->image)) {
				$tc->img = $this->image;
				if (sizeof($this->carousel) > 0 && is_null($tc->media)){
					$tc->media = $this->carousel[0];
				}
				if (is_null($tc->media)) {
					$tc->media = $this->image;
				}
			}
			$tc->carousel = $this->carousel;
			$tc->additional = [];
			if (isset($t['public_metrics'])){
				$tc->additional['shares'] = (string)($t['public_metrics']['retweet_count'] ?? 0);
				$tc->additional['likes'] = (string)($t['public_metrics']['like_count'] ?? 0);
				$tc->additional['comments'] = (string)($t['public_metrics']['reply_count'] ?? 0);
			}

			if ($this->isSuitablePost($tc)) {
				$result[$tc->id] = $tc;
			}
		}

		return $result;
	}

	private function getTimeline($feed){
		$timeline = null;
		switch ($feed->{'timeline-type'}) {
			case 'home_timeline':
				$timeline = new FFHomeTimeline();
				break;
			case 'user_timeline':
				$timeline = new FFUserTimeline();
				break;
			case 'favorites':
				$timeline = new FFFavorites();
				break;
			case 'list_timeline':
				$timeline = new FFListTimeline();
				break;
			case 'collection_timeline':
				$timeline = new FFCollections();
				break;
			default:
				$timeline = new FFSearch();
		}
		$timeline->init($this, $feed);
		return $timeline;
	}

	private function getChAr($text){
		$ChAr = [];
		if (function_exists('mb_detect_encoding')){
			$encoding = mb_detect_encoding($text);
			if ($encoding === false){
				$encoding = mb_internal_encoding();
			}
			// phpcs:ignore wp_function_not_compatible_with_requires_wp
			for ($i = 0; $i < mb_strlen($text, $encoding); $i++) {
				$ch = mb_substr($text, $i, 1, $encoding);
				if ($ch <> "\n") $ChAr[] = $ch; else $ChAr[] = "\n<br/>";
			}
		}
		else {
			for ($i = 0; $i < strlen($text); $i++) {
				$ch = substr($text, $i, 1);
				if ($ch <> "\n") $ChAr[] = $ch; else $ChAr[] = "\n<br/>";
			}
		}
		return $ChAr;
	}

	private function getText($tweet){
		if (!isset($tweet['entities'])){
			return isset($tweet['text']) ? (string) $tweet['text'] : '';
		}
		$text = isset($tweet['text']) ? (string) $tweet['text'] : '';
		$ChAr = $this->getChAr($text);
		$entities = $tweet['entities'];
		
		if (isset($entities['mentions'])) {
			foreach ($entities['mentions'] as $entity) {
				$start = $entity['start'];
				$end = $entity['end'];
				if (isset($ChAr[$start])) {
					$ChAr[$start] = "<a href='https://twitter.com/" . $entity['username'] . "'>" . $ChAr[$start];
					$ChAr[$end - 1] .= "</a>";
				}
			}
		}
		
		if (isset($entities['hashtags'])) {
			foreach ($entities['hashtags'] as $entity) {
				$start = $entity['start'];
				$end = $entity['end'];
				if (isset($ChAr[$start])) {
					$ChAr[$start] = "<a href='https://twitter.com/search?q=%23" . $entity['tag'] . "'>" . $ChAr[$start];
					$ChAr[$end - 1] .= "</a>";
				}
			}
		}
		
		if (isset($entities['urls'])) {
			foreach ($entities['urls'] as $entity) {
				$start = $entity['start'];
				$end = $entity['end'];
				if (isset($ChAr[$start])) {
					$display_url = isset($entity['display_url']) ? $entity['display_url'] : $entity['url'];
					$expanded_url = isset($entity['expanded_url']) ? $entity['expanded_url'] : $entity['url'];
					
					$ChAr[$start] = "<a href='" . $expanded_url . "'>" . $display_url . "</a>";
					for ($i = $start + 1; $i < $end; $i++) {
						if (isset($ChAr[$i])) {
							$ChAr[$i] = '';
						}
					}
				}
			}
		}
		
		return implode('', $ChAr);
	}

	/**
	 * Deprecated v1.1 helper.
	 */
	private function getMedia($tweet){
		return null;
	}
}