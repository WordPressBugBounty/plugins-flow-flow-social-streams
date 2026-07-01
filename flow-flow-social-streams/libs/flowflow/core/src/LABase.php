<?php namespace la\core;

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'FF_USE_WP' ) || FF_USE_WP ) {
		exit;
	}
}

use Exception;
use flow\social\cache\LAFacebookCacheManager;
use flow\social\FFFeed;
use flow\social\FFRemoteFeed;
use flow\social\LAFeedWithComments;
use la\core\cache\LACacheAdapter;
use la\core\cache\LAImageSizeCacheManager;
use la\core\cache\LATransientCache;
use la\core\db\LADB;
use la\core\settings\LAGeneralSettings;
use la\core\settings\LASettingsUtils;
use la\core\settings\LAStreamSettings;
use ReflectionClass;
use ReflectionException;

if ( ! defined('FF_BY_DATE_ORDER'))   define('FF_BY_DATE_ORDER', 'compareByTime');
if ( ! defined('FF_RANDOM_ORDER'))    define('FF_RANDOM_ORDER',  'randomCompare');
if ( ! defined('FF_SMART_ORDER'))     define('FF_SMART_ORDER',   'smartCompare');

abstract class LABase {
	protected static $instance = [];
	
	/**
	 * @param $context
	 *
	 * @return LABase|null
	 */
	public static function get_instance($context = null) {
		$slug = is_null($context) ? 'flow-flow' : LAUtils::slug($context);
		if (!array_key_exists($slug, self::$instance)) {
			$slug_down = is_null($context) ? 'flow_flow' : LAUtils::slug_down($context);
			$class = get_called_class();
			self::$instance[$slug] = new $class($context, $slug, $slug_down);
		}
		return self::$instance[$slug];
	}
	
	public static function get_instance_by_slug($slug) {
		return (array_key_exists($slug, self::$instance)) ? self::$instance[$slug] : null;
	}
	
// 	public static function registry($slug, $instance){
// 		if (!array_key_exists($slug, self::$instance)) {
// 			self::$instance[$slug] = $instance;
// 		}
// 	}

	/**
	 * @deprecated 
	 * @var LAGeneralSettings
	 */
	protected $generalSettings;
	
	/** @var array */
	protected $context;
	protected $slug;
	protected $slug_down;

    /**
     * Initialize the plugin by setting localization and loading public scripts
     * and styles.
     *
     * @since     1.0.0
     *
     * @param array $context
     * @param $slug
     * @param $slug_down
     */
	protected function __construct($context, $slug, $slug_down) {
		$this->context = $context;
		$this->slug = $slug;
		$this->slug_down = $slug_down;
		
		/**
		 * Default filter for result before send response.
		 * Use wp filter engine because need to customize result from addons. 
		 */
		add_filter('flow_flow_build_public_response', [ $this, 'buildResponse' ], 1, 8);
	}
	
	public final function register_shortcodes()
	{
		add_shortcode($this->getShortcodePrefix(), [ $this, 'renderShortCode' ] );
	}
	
	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    1.0.0
	 */
	public final function load_plugin_textdomain() {
		$domain = $this->slug;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$locale = apply_filters( 'plugin_locale', get_locale(), $domain );
		$path = LAUtils::root($this->context) . 'languages/';
		load_textdomain( $domain,  $path . $domain . '-' . $locale . '.mo' );
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound
		load_plugin_textdomain( $domain, false, $path );
	}
	
	/**
	 * Fired when a new site is activated with a WPMU environment.
	 *
	 * @since    1.0.0
	 *
	 * @param    int    $blog_id    ID of the new blog.
	 *
	 * @noinspection PhpUnused
     */
	public final function activate_new_site( $blog_id ) {
		if ( 1 !== did_action( 'wpmu_new_blog' ) )  return;
		switch_to_blog( $blog_id );
		restore_current_blog();
	}
	
	/**
	 * Register and enqueue public-facing style sheet.
	 *
	 * @since    1.0.0
	 */
	public final function enqueue_styles() {
		$this->enqueueStyles();
	}
	
	/**
	 * Register and enqueues public-facing JavaScript files.
	 *
	 * @since    1.0.0
	 */
	public final function enqueue_scripts() {
	    // Customization 16.08.18, JS opts added in public.php instead
//         $this->enqueueScripts();
        // make sure jQuery is always on page
        wp_enqueue_script('jquery');
	}

    /**
     * @throws Exception
     */
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    public final function processAjaxRequest() {
        if (defined('FF_LOG_FILE_DEST')) {
            $timestamp = gmdate('Y-m-d H:i:s');
            $msg = "[$timestamp] [LABase] processAjaxRequest called (fetch_posts)";
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            @error_log($msg . "\n-----------------------------------\n", 3, FF_LOG_FILE_DEST);
        }

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
        if(!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        if(!headers_sent()) {
            nocache_headers();
            header('Cache-Control: no-cache, no-store, must-revalidate'); // HTTP 1.1.
            header('Pragma: no-cache'); // HTTP 1.0.
            header('Expires: 0'); // Proxies.
        }
        
        // Extract request parameters for caching
        $streamId = LAUtils::get_request_var('stream-id', 'request', 'text');
        $page = LAUtils::get_request_var('page', 'request', 'int', 0);
        $hash = LAUtils::get_request_var('hash', 'request', 'text');
        $isRecent = LAUtils::get_request_var('recent', 'request', 'raw', null) !== null;

        $raw_force = LAUtils::get_request_var('force', 'request', 'text');
        $isForce = $raw_force && ($raw_force !== 'false');

        $raw_preview = LAUtils::get_request_var('preview', 'request', 'text');
        $isPreview = $raw_preview && ($raw_preview !== 'false');

        $raw_disable_cache = LAUtils::get_request_var('disable-cache', 'request', 'text');
        $disableCache = (bool)$raw_disable_cache;
        
        // Determine if we can use transient cache
        $canUseCache = !$isForce && !$isPreview && !$disableCache;
        
        // Check transient cache first (before DB operations)
        if ($canUseCache && $streamId) {
            $cachedResponse = LATransientCache::get($streamId, $page, $hash, $isRecent);
            if ($cachedResponse !== false) {
                header('Content-Type: application/json');
                header('X-FF-Cache: HIT');
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo $cachedResponse;
                die();
            }
        }
        
        if ($streamId && $this->prepareProcess()) {
            $dbm = LAUtils::dbm($this->context);
            $raw_boosted = LAUtils::get_request_var('boosted', 'request', 'text');
            $boosted = (bool)((int)$raw_boosted);
            $dbm->dataInit(true, false, $boosted);
            $stream = $dbm->getStream($streamId);
            if (isset($stream)) {
                header('Content-Type: application/json');
                
                $jsonResponse = $this->process( [ $stream ], $disableCache);
                
                // Store in transient cache for future requests
                if ($canUseCache && !empty($jsonResponse)) {
                    LATransientCache::set($streamId, $jsonResponse, $page, $hash, $isRecent);
                    header('X-FF-Cache: MISS');
                }
                
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo $jsonResponse;
            }
        }
        die();
    }

    /**
     * @throws Exception
     */
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    public final function moderation_apply( ){
        if (defined('FF_LOG_FILE_DEST')) {
            $timestamp = gmdate('Y-m-d H:i:s');
            $msg = "[$timestamp] [LABase] moderation_apply called";
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            @error_log($msg . "\n-----------------------------------\n", 3, FF_LOG_FILE_DEST);
        }
        $stream_param = LAUtils::get_request_var('stream', 'request', 'text');
		if ($stream_param && $this->prepareProcess()) {
            $dbm = LAUtils::dbm($this->context);
			$dbm->dataInit();
			$stream = $dbm->getStream($stream_param);
			if (isset($stream)) {
                $cache = new LACacheAdapter($this->context, false);
                $cache->setStream(new LAStreamSettings($stream), true);
                $cache->moderate();
			}
		}
	}

    /**
     * @param bool $only_enable
     * @param bool $remote
     * @throws Exception
     */
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    public final function processAjaxRequestBackground($only_enable = true, $remote = true) {
        if (defined('FF_LOG_FILE_DEST')) {
            $timestamp = gmdate('Y-m-d H:i:s');
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r, WordPress.Security.NonceVerification.Recommended
            $msg = "[$timestamp] [LABase] processAjaxRequestBackground called\nData: " . print_r($_REQUEST, true);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            @error_log($msg . "\n-----------------------------------\n", 3, FF_LOG_FILE_DEST);
        }

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
        if(!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        if(!headers_sent()) {
            nocache_headers();
        }
		if ($this->prepareProcess()) {
			$dbm = LAUtils::dbm($this->context);
			$dbm->dataInit($only_enable, false, $remote);
			
            $feedId = LAUtils::get_request_var('feed_id', 'request', 'text');

			if ($feedId){
				$sources = $dbm->sources();
				if (isset($sources[$feedId])){
                    $this->process4feeds( [ $sources[$feedId] ], false, true);
				}
			}
            
            $streamId = LAUtils::get_request_var('stream_id', 'request', 'text');

			if ($streamId){
				$stream = $dbm->getStream($streamId);
				if (isset($stream)) {
					$this->process4feeds( [ $stream ], false, true);
				}
			}
		}
	}


    /**
     * @return array|false|string
     * @throws Exception
     */
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    public final function processRequest(){
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
        if(!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        if(!headers_sent()) {
            nocache_headers();
        }
        $streamId = LAUtils::get_request_var('stream-id', 'request', 'text');

		if ($streamId && $this->prepareProcess()) {
            $dbm = LAUtils::dbm($this->context);
            $dbm->dataInit(true);
			$stream = $dbm->getStream($streamId);
			if (isset($stream)) {
                $disable_cache = LAUtils::get_request_var('disable-cache', 'request', 'raw', null) !== null;
				return $this->process( [ $stream ], $disable_cache);
			}
		}
		return '';
	}
	
	public final function refreshCache($streamId = null, $force = false, $withDisabled = false) {
		if (!defined('WP_DEBUG') || !WP_DEBUG) {
            $this->log('Flow-Flow Debug: refreshCache called with streamId=' . ($streamId ?? 'null') . ', force=' . ($force ? 'true' : 'false') . ', withDisabled=' . ($withDisabled ? 'true' : 'false'));
        }

        $start_time = microtime(true);
        $debug_info = [
            'method' => 'refreshCache',
            'stream_id' => $streamId,
            'force' => $force,
            'with_disabled' => $withDisabled,
            'feeds_processed' => 0,
            'execution_time' => 0,
            'feeds' => []
        ];

        try {
            if ($this->prepareProcess()) {
                $dbm = LAUtils::dbm($this->context);
                $conn = $dbm->conn();
                
                $enabled = $withDisabled 
                    ? $conn->parse('`cach`.system_enabled = 0 AND `cach`.boosted != "yep"') 
                    : $conn->parse('`cach`.enabled = 1 AND `cach`.system_enabled = 1 AND `cach`.boosted != "yep"');
                
                if (empty($streamId)) {
                    $sql = $conn->parse('SELECT `cach`.`feed_id` FROM ?n `cach` WHERE ?p AND (`cach`.last_update + `cach`.cache_lifetime * 60) < UNIX_TIMESTAMP() ORDER BY `cach`.last_update', 
                        $dbm->cache_table_name, $enabled);
                    if (!defined('WP_DEBUG') || !WP_DEBUG) {
                        $this->log('Flow-Flow Debug: Refreshing all feeds with expired cache');
                    }
                } else {
                    $sql = $conn->parse('SELECT `cach`.`feed_id` FROM ?n `cach` INNER JOIN ?n `ss` ON `ss`.feed_id = `cach`.feed_id WHERE ?p AND `ss`.stream_id = ?s AND (`cach`.last_update + `cach`.cache_lifetime * 60) < UNIX_TIMESTAMP() ORDER BY `cach`.last_update',
                        $dbm->cache_table_name, $dbm->streams_sources_table_name, $enabled, $streamId);
                    if (!defined('WP_DEBUG') || !WP_DEBUG) {
                        $this->log(sprintf('Flow-Flow Debug: Refreshing feeds for stream %s with expired cache', $streamId));
                    }
                }
                
                if (false !== ($feeds = $conn->getCol($sql))) {
                    $useIpv4 = $dbm->getGeneralSettings()->useIPv4();
                    $use = $dbm->getGeneralSettings()->useCurlFollowLocation();
                    $debug_info['feeds_found'] = count($feeds);
                    
                    if (!defined('WP_DEBUG') || !WP_DEBUG) {
                        $this->log(sprintf('Flow-Flow Debug: Found %d feeds to refresh', count($feeds)));
                    }
                    
                    $batch_size = (count($feeds) < 4) ? count($feeds) : 8;
                    $debug_info['batch_size'] = $batch_size;
                    
                    for ($i = 0; $i < $batch_size; $i++) {
                        if (isset($feeds[$i])) {
                            $feed_id = $feeds[$i];
                            $debug_info['feeds_processed']++;
                            $feed_index = count($debug_info['feeds']);
                            $debug_info['feeds'][] = [
                                'feed_id' => $feed_id,
                                'processing_method' => FF_USE_DIRECT_WP_CRON ? 'direct' : 'remote',
                                'start_time' => microtime(true)
                            ];
                            
                            $_REQUEST['feed_id'] = $feed_id;
                            
                            try {
                                if (FF_USE_DIRECT_WP_CRON) {
                                    if (defined('FF_LOG_FILE_DEST')) {
                                        $timestamp = gmdate('Y-m-d H:i:s');
                                        $msg = "[$timestamp] [LABase] Processing feed $feed_id directly (FF_USE_DIRECT_WP_CRON)";
                                        $this->log($msg . "\n-----------------------------------\n", 3, FF_LOG_FILE_DEST);
                                    }
                                    $this->processAjaxRequestBackground(!$withDisabled, false);
                                } else {
                                    // Direct invocation instead of HTTP request to self
                                    if (defined('FF_LOG_FILE_DEST')) {
                                        $timestamp = gmdate('Y-m-d H:i:s');
                                        $msg = "[$timestamp] [LABase] Processing feed $feed_id directly (bypassing HTTP)";
                                        $this->log($msg . "\n-----------------------------------\n", 3, FF_LOG_FILE_DEST);
                                    }
                                    
                                    try {
                                        // Set up request parameters that would normally come from AJAX
                                        $_REQUEST['feed_id'] = $feed_id;
                                        $_REQUEST['force'] = $force;
                                        
                                        $this->processAjaxRequestBackground(!$withDisabled, false);
                                        
                                        // Clean up request parameters
                                        unset($_REQUEST['feed_id']);
                                        unset($_REQUEST['force']);
                                    } catch (Exception $direct_exception) {
                                        // Fallback to HTTP request if direct call fails
                                        if (defined('FF_LOG_FILE_DEST')) {
                                            $timestamp = gmdate('Y-m-d H:i:s');
                                            $msg = "[$timestamp] [LABase] Direct invocation failed, falling back to HTTP\nError: " . $direct_exception->getMessage();
                                            $this->log($msg . "\n-----------------------------------\n", 3, FF_LOG_FILE_DEST);
                                        }
                                        
                                        $url = $dbm->getLoadCacheUrl($feed_id, $force);
                                        if (defined('FF_LOG_FILE_DEST')) {
                                            $timestamp = gmdate('Y-m-d H:i:s');
                                            $msg = "[$timestamp] [LABase] Fetching feed $feed_id from URL: $url";
                                            $this->log($msg . "\n-----------------------------------\n", 3, FF_LOG_FILE_DEST);
                                        }
                                        LASettingsUtils::get($url, 1, false, false, $use, $useIpv4);
                                    }
                                }
                                $debug_info['feeds'][$feed_index]['status'] = 'success';
                            } catch (Exception $feed_exception) {
                                $error_message = sprintf(
                                    'Flow-Flow Error processing feed %s: %s',
                                    $feed_id,
                                    $feed_exception->getMessage()
                                );
                                if (defined('FF_LOG_FILE_DEST')) {
                                    $timestamp = gmdate('Y-m-d H:i:s');
                                    $msg = "[$timestamp] [LABase] Error processing feed $feed_id: " . $feed_exception->getMessage();
                                    $this->log($msg . "\n-----------------------------------\n", 3, FF_LOG_FILE_DEST);
                                }
                                $debug_info['feeds'][$feed_index]['status'] = 'error';
                                $debug_info['feeds'][$feed_index]['error'] = $feed_exception->getMessage();
                            }
                            
                            $debug_info['feeds'][$feed_index]['end_time'] = microtime(true);
                            $debug_info['feeds'][$feed_index]['duration'] = 
                                $debug_info['feeds'][$feed_index]['end_time'] - 
                                $debug_info['feeds'][$feed_index]['start_time'];
                            
                            if (!defined('WP_DEBUG') || !WP_DEBUG) {
                                $this->log(sprintf('Flow-Flow Debug: Completed processing feed %s in %.4f seconds', 
                                    $feed_id, 
                                    $debug_info['feeds'][$feed_index]['duration']
                                ));
                            }
                        }
                    }
                } else {
                    if (!defined('WP_DEBUG') || !WP_DEBUG) {
                        $this->log('Flow-Flow Debug: No feeds require refreshing at this time');
                    }
                }
                
                $debug_info['execution_time'] = microtime(true) - $start_time;
                
                if (!defined('WP_DEBUG') || !WP_DEBUG) {
                    $this->log('Flow-Flow Debug: refreshCache completed in ' . number_format($debug_info['execution_time'], 4) . ' seconds');
                    $this->log('Flow-Flow Debug: ' . json_encode($debug_info, JSON_PRETTY_PRINT));
                }
            } else {
                $this->log('Flow-Flow Debug: prepareProcess() returned false, cannot refresh cache');
            }
        } catch (Exception $e) {
            $error_message = sprintf(
                'Flow-Flow Error in refreshCache: %s\nStack Trace:\n%s',
                $e->getMessage(),
                $e->getTraceAsString()
            );
            $this->log($error_message);
            
            if (!defined('WP_DEBUG') || !WP_DEBUG) {
                $debug_info['error'] = [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ];
                $debug_info['execution_time'] = microtime(true) - $start_time;
                $this->log('Flow-Flow Debug: ' . json_encode($debug_info, JSON_PRETTY_PRINT));
            }
            
            throw $e;
        }
    }

    public final function refreshCache4Disabled() {
        $this->refreshCache(null, false, true);
    }

	public final function emailNotification () {
		$dbm = LAUtils::dbm($this->context);
		$settings = $dbm->getGeneralSettings();
		if ($settings->enabledEmailNotification()){
			$dbm->email_notification();
		}
	}

    public final function checkFacebookToken() {
        $dbm = LAUtils::dbm($this->context);
        if ($dbm->getOption('boosts_email') != false){
            /** @var LAFacebookCacheManager $facebookCache */
            $facebookCache = $this->context['facebook_cache'];
            $facebookCache->getAccessToken();
        }
    }

    /**
     * @param $attr
     *
     * @return false|string|string[]
     * @throws Exception
     */
    public function renderShortCode ($attr) {
		if (isset($attr['id'])){
			if ($this->prepareProcess()) {
                $dbm = LAUtils::dbm($this->context);
				$dbm->dataInit(false, false, false);
				$stream = (object)$dbm->getStream($attr['id']);
				if (isset($stream)) {
					$stream->preview = (isset($attr['preview']) && $attr['preview']);
					$stream->gallery = $stream->preview ? LASettingsUtils::NOPE : ( isset($stream->gallery) ? $stream->gallery : LASettingsUtils::NOPE );
					$output = $this->renderStream($stream, $this->getPublicContext($stream, $this->context));

					/* workaround for extra P tags issue and possibly &&, set to true */
					if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || LASettingsUtils::notYepNope2ClassicStyleSafe(LAGeneralSettings::get()->original(), 'general-render-alt')){
						/* added 8.06.20 */
						remove_filter('the_content', 'wptexturize');
						remove_filter('the_content', 'wpautop');
						/* */
						return $output;
					}
					else {
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo $output;
					}
				}
			} else {
				echo '<p>Flow-Flow message: Stream with specified ID not found or no feeds were added to stream</p>';
			}
		}
		return '';
	}
	
	/**
	 * @param $result
	 * @param $all
	 * @param $context
	 * @param $errors
	 * @param $oldHash
	 * @param $page
	 * @param $status
	 * @param LAStreamSettings $stream
	 *
	 * @return array
     * @noinspection PhpUnusedParameterInspection
     */
	public function buildResponse ($result, $all, $context, $errors, $oldHash, $page, $status, $stream) {
		$streamId = (int) $stream->getId();
		$countOfPages = LAUtils::get_request_var('countOfPages', 'request', 'text', '0');
		$result = [
            'id'   => $streamId, 'items' => $all, 'errors' => $errors,
            'hash' => $oldHash, 'page' => $page, 'countOfPages' => $countOfPages, 'status' => $status
        ];
		return $result;
	}

    /**
     * @throws ReflectionException
     * @throws Exception
     */
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    public function loadCommentsAndCarousel(){
		$result = [];

        $post_id = LAUtils::get_request_var('post_id', 'request', 'text');
        $feed_id = LAUtils::get_request_var('feed_id4post', 'request', 'text');

        if ($this->prepareProcess()) {
            $dbm = LAUtils::dbm($this->context);
            $dbm->dataInit(true);

            $result['comments'] = $this->process4comments($post_id, $feed_id);
            $result['carousel'] = $dbm->getCarousel($feed_id, $post_id);

            wp_send_json($result);
        }
	}

	protected function enqueueStyles() {}
	protected function enqueueScripts() {}
	protected abstract function getShortcodePrefix();
	protected abstract function getNameJSOptions();

    protected function getPublicContext($stream, $context){
        $context['moderation'] = false;
        if (isset($stream->feeds) && !empty($stream->feeds)){
            foreach ( $stream->feeds as $source ) {
                if (LASettingsUtils::YepNope2ClassicStyleSafe($source, 'mod', false)){
                    $context['moderation'] = true;
                }
            }
        }

        $cache = new LACacheAdapter($context);
        $cache->setStream(new LAStreamSettings($stream), $context['moderation']);
        $context['stream'] = $stream;
        $context['hashOfStream'] = $cache->transientHash($stream->id);
        $context['seo'] = false;////$this->generalSettings->isSEOMode();
        $context['can_moderate'] = FF_USE_WP ? $this->generalSettings->canModerate() : ff_user_can_moderate();
        return $context;
    }

	protected function prepareProcess() {
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if (isset($_REQUEST['stream-id'])) {
			$_REQUEST['stream-id'] = @filter_var( trim( LAUtils::get_request_var('stream-id', 'request', 'raw') ), FILTER_SANITIZE_NUMBER_INT);
		}
		if (isset($_REQUEST['feed_id'])) {
			$_REQUEST['feed_id'] = @filter_var( trim( LAUtils::get_request_var('feed_id', 'request', 'raw') ), FILTER_SANITIZE_STRING );
		}
		if (isset($_REQUEST['action'])) {
			$_REQUEST['action'] = @filter_var( trim( LAUtils::get_request_var('action', 'request', 'raw') ), FILTER_SANITIZE_STRING );
		}
		if (isset($_REQUEST['page'])) {
			$_REQUEST['page'] = filter_var( trim( LAUtils::get_request_var('page', 'request', 'raw') ), FILTER_SANITIZE_NUMBER_INT);
		}
		if (isset($_REQUEST['countOfPages'])) {
			$_REQUEST['countOfPages'] = filter_var( trim( LAUtils::get_request_var('countOfPages', 'request', 'raw') ), FILTER_SANITIZE_NUMBER_INT);
		}
		$raw_hash = LAUtils::get_request_var('hash', 'request', 'raw', '');
		if (!empty($raw_hash)) {
			$hash = filter_var( $raw_hash, FILTER_VALIDATE_REGEXP, [ "options" => [ 'regexp' => '/^\d{10}[.]\w{96}$/' ] ] );
			if (false === $hash){
				status_header(400);
				exit;
			}
		}
		if (isset($_REQUEST['disable-cache']) && !empty($_REQUEST['disable-cache'])){
			$_REQUEST['disable-cache'] = filter_var( trim( LAUtils::get_request_var('disable-cache', 'request', 'raw') ), FILTER_SANITIZE_NUMBER_INT);
		}
		if (isset($_REQUEST['preview']) && !empty($_REQUEST['preview'])){
			$_REQUEST['preview'] = filter_var( trim( LAUtils::get_request_var('preview', 'request', 'raw') ), FILTER_SANITIZE_NUMBER_INT);
		}
		// phpcs:enable

        $dbm = LAUtils::dbm($this->context);
		if ($dbm->countFeeds() > 0) {
			$this->generalSettings = $dbm->getGeneralSettings();
			return true;
		}
		return false;
	}

	protected function renderStream($stream, $context){
		$settings = new LAStreamSettings($stream);
		if ($settings->isPossibleToShow()){
			if ( ! in_array( 'curl', get_loaded_extensions() ) ) {
				echo "<p style='background: indianred;padding: 15px;color: white;'>Flow-Flow admin info: Your server doesn't have cURL module installed. Please ask your hosting to check this.</p>";
				return '';
			}
			
			if (!isset($stream->layout) || empty($stream->layout)) {
				echo "<p style='background: indianred;padding: 15px;color: white;'>Flow-Flow admin info: Please choose stream layout on options page.</p>";
				return '';
			}
			
			ob_start();
			$css_version = isset($stream->last_changes) ? $stream->last_changes : '1.0';
			$url = content_url() . '/resources/' . LAUtils::slug($context) . '/css/stream-id' . $stream->id . '.css';
			if (!is_main_site()){
				$url = content_url() . '/resources/' . LAUtils::slug($context) . '/css/stream-id' . $stream->id . '-'. get_current_blog_id() . '.css';
			}
			
			$escaped_url = function_exists('esc_url') ? esc_url($url) : htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
			$escaped_ver = function_exists('esc_attr') ? esc_attr($css_version) : htmlspecialchars($css_version, ENT_QUOTES, 'UTF-8');
			$escaped_id = (int)$stream->id;
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet, WordPress.Security.EscapeOutput.OutputNotEscaped
			echo "<link rel='stylesheet' id='ff-dynamic-css{$escaped_id}' type='text/css' href='{$escaped_url}?ver={$escaped_ver}'/>";

			/** @noinspection PhpIncludeInspection */
			include(LAUtils::root($context)  . 'views/public.php');
			$output = ob_get_clean();
			$output = str_replace("\r\n", '', $output);

			return $output;
		}
		else
			return '';
	}
	
	protected function process($streams, $disableCache = false, $background = false) {
		foreach ($streams as $stream) {
			try {
				$moderation = false;
				foreach ( LAUtils::dbm($this->context)->sources() as $source ) {
					$moderation = LASettingsUtils::YepNope2ClassicStyleSafe($source, 'mod', false);
					if ($moderation){
						break;
					}
				}

                $settings = new LAStreamSettings($stream);
				$cache = new LACacheAdapter($this->context);
				$cache->setStream($settings, $moderation);
				$instances = $this->createFeedInstances($settings->getAllFeeds());
				$result = $cache->posts($instances, $disableCache);
				unset($instances);
				if ($background) return $result;
				$errors = $cache->errors();
				$hash = $cache->hash();
				return $this->prepareResult($result, $errors, $hash, $settings);
			} catch ( Exception $e) {
				$this->log($e->getMessage());
				$this->log($e->getTraceAsString());
			}
		}
		return '';
	}

    protected function initContextBeforeCreateFeedInstances(){
        $this->context['image_size_cache'] = new LAImageSizeCacheManager($this->context);
    }

	private function process4feeds($feeds, $disableCache = false, $background = false) {
        if (defined('FF_LOG_FILE_DEST')) {
            $timestamp = gmdate('Y-m-d H:i:s');
            $msg = "[$timestamp] [LABase] process4feeds called. Count: " . count($feeds);
            $this->log($msg . "\n-----------------------------------\n", 3, FF_LOG_FILE_DEST);
        }
		try {
			$instances = $this->createFeedInstances($feeds);
			$cache = new LACacheAdapter($this->context, true);
			$result = $cache->posts($instances, $disableCache);
			unset($instances);
			if ($background) return $result;
			$errors = $cache->errors();
			$hash = $cache->hash();
			return $this->prepareResult($result, $errors, $hash);
		} catch ( Exception $e) {
			$this->log($e->getMessage());
			$this->log($e->getTraceAsString());
		}
		return '';
	}

    /**
     * Rework code, delete the reference to the database and the logic of expiration life time
     *
     * @param $post_id
     * @param $feed_id
     *
     * @return array
     * @throws ReflectionException
     * @throws Exception
     */
	private function process4comments($post_id, $feed_id){
        $dbm = LAUtils::dbm($this->context);
        $conn = $dbm->conn();
		$time = time();
		$comments = $conn->getAll('SELECT * FROM ?n WHERE `post_id` = ?s', $dbm->comments_table_name, $post_id);
		$expiration = $time - 3600; // 1 hour
		
		// if no comments or comments are outdated
		if(count($comments) === 0 || ($comments[0]["updated_time"] < $expiration) ){
			$sources = $dbm->sources();
			$this->initContextBeforeCreateFeedInstances();
			/** @var LAFeedWithComments $instance */
			$instance = $this->createFeedInstance($sources[$feed_id]);
			if ($instance instanceof LAFeedWithComments){
				try {
					$comments = $instance->getComments($post_id);
					
					// Save comments to DB
					if (sizeof($comments) > 0 && $conn->beginTransaction()){
                        $dbm->removeComments($post_id);
						foreach ( $comments as $comment ) {
							$comment->updated_time = $time;
							if (is_object($comment->from)) $comment->from = json_encode($comment->from);
                            $dbm->addComments($post_id, $comment);
						}
                        $conn->commit();
					}
				} catch ( Exception $e) {
                    $conn->rollbackAndClose();
					$this->log($e->getMessage());
					$this->log($e);
				}
			}
		}
		return $comments;
	}

    /**
     * @param $feeds
     *
     * @return array
     * @throws ReflectionException
     */
    private function createFeedInstances($feeds) {
		$this->initContextBeforeCreateFeedInstances();
		$result = [];
		if (is_array($feeds)) {
			foreach ($feeds as $feed) {
				$feed = (object)$feed;
				$instance = $this->createFeedInstance($feed);
				if ($instance !== null) {
					$result[$feed->id] = $instance;
				}
			}
		}
		return $result;
	}

	/**
	 * @param $feed
	 *
	 * @return object
	 * @throws ReflectionException
	 */
	private function createFeedInstance($feed) {
        if (defined('FF_LOG_FILE_DEST')) {
            $timestamp = gmdate('Y-m-d H:i:s');
            $msg = "[$timestamp] [LABase] createFeedInstance called for feed type: " . $feed->type;
            $this->log($msg . "\n-----------------------------------\n", 3, FF_LOG_FILE_DEST);
        }
		$feed = (object)$feed;
		$wpt = 'type';
		if ($feed->type == 'linkedin') {
			$feed->type = 'linkedIn';
		}
		if (FF_USE_WP && $feed->type == 'wordpress'){
			$wpt = 'wordpress-type';
		}
		
		// Special case for TikTok to ensure correct case
		$className = ($feed->$wpt === 'tiktok') ? 'TikTok' : ucfirst($feed->$wpt);
		$clazzName = 'flow\\social\\FF' . $className;
		if (!class_exists($clazzName)) {
			return null;
		}
		$clazz = new ReflectionClass($clazzName);
        /** @var FFFeed $instance */
		$instance = $clazz->newInstance();
		$feed = $this->prepareFeed($feed, $this->generalSettings);

		if (LASettingsUtils::YepNope2ClassicStyle($feed->boosted, false)){
			$instance = new FFRemoteFeed($instance);
		}

		$instance->init($this->context, $feed);
		return $instance;
	}

	/**
	 * @param $feed
	 * @param $options LAGeneralSettings
	 *
	 * @return mixed
	 */
	protected function prepareFeed($feed, $options){
		$feed->{'use-excerpt'} = LASettingsUtils::YepNope2ClassicStyleSafe($feed, 'use-excerpt');
		$feed->{'include-post-title'} = LASettingsUtils::YepNope2ClassicStyleSafe($feed, 'include-post-title');
		$feed->{'only-text'} = LASettingsUtils::YepNope2ClassicStyleSafe($feed, 'only-text');
		$feed->{'rich-text'} = LASettingsUtils::YepNope2ClassicStyleSafe($feed, 'rich-text');
		$feed->{'hide-caption'} = LASettingsUtils::YepNope2ClassicStyleSafe($feed, 'hide-caption');
		$feed->{'playlist-order'} = LASettingsUtils::YepNope2ClassicStyleSafe($feed, 'playlist-order');
		$feed->replies = LASettingsUtils::notYepNope2ClassicStyleSafe($feed, 'replies');
		$feed->retweets = LASettingsUtils::YepNope2ClassicStyleSafe($feed, 'retweets');
		$feed->{'use-geo'} = LASettingsUtils::YepNope2ClassicStyleSafe($feed, 'use-geo');
		//$feed->boosted = FFSettingsUtils::YepNope2ClassicStyleSafe($feed, 'boosted');

		$original = $options->original();
		$feed->linkedin_access_token    = @$original['linkedin_access_token'];
		$feed->dribbble_access_token    = @$original['dribbble_access_token'];
		$feed->foursquare_access_token  = @$original['foursquare_access_token'];
		$feed->foursquare_client_id     = @$original['foursquare_client_id'];
		$feed->foursquare_client_secret = @$original['foursquare_client_secret'];
		$feed->google_api_key           = @$original['google_api_key'];
		$feed->instagram_access_token   = @$original['instagram_access_token'];
		$feed->instagram_login          = @$original['instagram_login'];
		$feed->instagram_password       = @$original['instagram_pass'];
		$feed->soundcloud_api_key       = @$original['soundcloud_api_key'];
		$feed->tiktok_access_token     = @$original['tiktok_access_token'];
		$feed->twitter_access_settings = [
			'oauth_access_token' => @$original['oauth_access_token'],
			'oauth_access_token_secret' => @$original['oauth_access_token_secret'],
			'consumer_key' => @$original['consumer_key'],
			'consumer_secret' => @$original['consumer_secret']
        ];

		$feed->use_curl_follow_location = $options->useCurlFollowLocation();
		$feed->use_ipv4 = $options->useIPv4();
		return $feed;
	}

    /**
     * @param array $all
     * @param $errors
     * @param $hash
     * @param LAStreamSettings|null $stream
     *
     * @return false|string
     * @throws Exception
     * @noinspection PhpUnusedParameterInspection
     */
    private function prepareResult(array $all, $errors, $hash, $stream = null) {
		$page = LAUtils::get_request_var('page', 'request', 'int', 0);
		$oldHash = LAUtils::get_request_var('hash', 'request', 'text', $hash);

		if (LAUtils::get_request_var('recent', 'request', 'raw', null) !== null && $hash != null){
			$oldHash = $hash;
		}
		list($status, $errors) = $this->status($stream);
		$result = FF_USE_WP ? apply_filters('flow_flow_build_public_response', [], $all, $this->context, $errors, $oldHash, $page, $status, $stream) :
		$this->buildResponse( [], $all, $this->context, $errors, $oldHash, $page, $status, $stream);
		if (($result === false) && (JSON_ERROR_UTF8 === json_last_error())){
			foreach ( $all as $item ) {
				json_encode($item);
				if (JSON_ERROR_UTF8 === json_last_error()){
					$item->text = mb_convert_encoding($item->text, "UTF-8", "auto");
				}
			}
			$result = FF_USE_WP ? apply_filters('flow_flow_build_public_response', $result, $all, $this->context, $errors, $oldHash, $page, $status, $stream) :
			$this->buildResponse($result, $all, $this->context, $errors, $oldHash, $page, $status, $stream);
		}

		$raw_boosted = LAUtils::get_request_var('boosted', 'request', 'int', 0);
		$result['server_time'] = (defined('FF_USE_WP') && FF_USE_WP) ? current_time('timestamp', $raw_boosted) : time();

		$json = json_encode($result);
		if ($json === false){
			$errors = [];
			switch (json_last_error()) {
				case JSON_ERROR_NONE:
					echo ' - No errors';
					break;
				case JSON_ERROR_DEPTH:
					$errors[] = 'Json encoding error: Maximum stack depth exceeded';
					break;
				case JSON_ERROR_STATE_MISMATCH:
					$errors[] = 'Json encoding error: Underflow or the modes mismatch';
					break;
				case JSON_ERROR_CTRL_CHAR:
					$errors[] = 'Json encoding error: Unexpected control character found';
					break;
				case JSON_ERROR_SYNTAX:
					$errors[] = 'Json encoding error: Syntax error, malformed JSON';
					break;
				case JSON_ERROR_UTF8:
					for ( $i = 0; sizeof( $result['items'] ) > $i; $i++ ) {
						if (function_exists('mb_convert_encoding'))
							$result['items'][$i]->text = mb_convert_encoding($result['items'][$i]->text, "UTF-8", "auto");
					}
					$json = json_encode($result);
					if ($json === false){
						$errors[] = 'Json encoding error:  Malformed UTF-8 characters, possibly incorrectly encoded';
					}
					else {
						return $json;
					}
					break;
				default:
					$errors[] = 'Json encoding error';
					break;
			}
			$result = FF_USE_WP ? apply_filters('flow_flow_build_public_response', [], [], $this->context, $errors, $oldHash, $page, 'errors', $stream) :
			$this->buildResponse($result, $all, $this->context, $errors, $oldHash, $page, 'errors', $stream);
			$json = json_encode($result);
		}
		return $json;
	}

    /**
     * @param LAStreamSettings $stream
     *
     * @return array
     * @throws Exception
     */
    private function status($stream) {
        $dbm = LAUtils::dbm($this->context);
		$status_info = LADB::getStatusInfo($dbm->conn(), $dbm->cache_table_name, $dbm->streams_sources_table_name, (int)$stream->getId(), false);
		if ($status_info['status'] == '0'){
			return [ 'errors', isset($status_info['error']) ? $status_info['error'] : '' ];
		}
		if ($status_info['status'] == '1'){
			$feed_count = sizeof($stream->getAllFeeds());
			$status = ($feed_count == (int)$status_info['feeds_count']) ? 'get' : 'building';
			return [ $status, [] ];
		}
		throw new Exception('Was received the unknown status');
	}

    private function log($msg, $type = 0, $destination = '') {
        $error_log_func = 'error_log';
        if ($type === 3) {
            @$error_log_func($msg, 3, $destination);
        } else {
            $error_log_func($msg);
        }
    }
}