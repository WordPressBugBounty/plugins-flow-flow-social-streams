<?php
namespace la\core\db;

use Exception;
use la\core\cache\LACacheManager;
use la\core\cache\LATransientCache;
use la\core\LAUtils;
use la\core\settings\LAGeneralSettings;
use la\core\settings\LASettingsUtils;
use mysqli;
use Unirest\Request;
use Unirest\Response;

if (!defined('WPINC'))
    die;
/**
 * FlowFlow.
 *
 * @property LASafeMySQL $conn
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>
 *
 * @link      https://looks-awesome.com
 * @copyright Looks Awesome
 */
abstract class LADBManager
{
    public $table_prefix;
    public $option_table_name;
    public $posts_table_name;
    public $cache_table_name;
    public $streams_table_name;
    public $image_cache_table_name;
    public $streams_sources_table_name;
    public $snapshot_table_name;
    public $comments_table_name;
    public $post_media_table_name;

    protected $context;
    protected $plugin_slug;
    protected $plugin_slug_down;
    protected $init = false;
    protected $sources = null;
    protected $streams = null;

    private $conn = null;

    /**
     * LADBManager constructor.
     *
     * @param array $context
     */
    function __construct($context)
    {
        $this->context = $context;
        if (!isset($this->context['db_manager'])) {
            $this->context['db_manager'] = $this;
        }
        $this->table_prefix = $context['table_name_prefix'];
        $this->plugin_slug = LAUtils::slug($context);
        $this->plugin_slug_down = LAUtils::slug_down($context);

        $this->option_table_name = $this->table_prefix . 'options';
        $this->posts_table_name = $this->table_prefix . 'posts';
        $this->cache_table_name = $this->table_prefix . 'cache';
        $this->streams_table_name = $this->table_prefix . 'streams';
        $this->image_cache_table_name = $this->table_prefix . 'image_cache';
        $this->streams_sources_table_name = $this->table_prefix . 'streams_sources';
        $this->snapshot_table_name = $this->table_prefix . 'snapshots';
        $this->comments_table_name = $this->table_prefix . 'comments';
        $this->post_media_table_name = $this->table_prefix . 'post_media';
    }

    public function conn($reopen = false)
    {
        if ($this->conn !== null && (!$reopen || $this->conn->inTransaction())) {
            try {
                $mysqli = $this->conn->getMySQLi();
                $threadId = $mysqli ? $mysqli->thread_id : null;
                // Even an explicit reopen must preserve a healthy transaction.
                if ($mysqli && @$mysqli->ping()) {
                    // Older mysqli can reconnect inside ping(). Its fresh
                    // session must never be mistaken for the old transaction.
                    if ($mysqli->thread_id !== $threadId) {
                        if ($this->conn->inTransaction()) throw new Exception('Database transaction connection was replaced');
                        if ($this->conn->autocommit(true) === false) throw new Exception('Could not reset reconnected database session');
                    }
                    return $this->conn;
                }
            } catch (\Throwable $error) {
                // Check transaction ownership before replacing a dead handle.
            }
        }
        if ($this->conn !== null) {
            if ($this->conn->inTransaction()) {
                throw new Exception('Cannot replace a database connection during an active transaction');
            }
            try { $this->conn->close(); } catch (\Throwable $ignored) {}
        }
        $this->conn = LADB::create();
        $this->conn->autocommit(true);
        return $this->conn;
    }

    /**
     * @param false $only_enable
     * @param false $safe
     * @param bool $remote
     *
     * @throws Exception
     */
    public final function dataInit($only_enable = false, $safe = false, $remote = true)
    {
        $this->init = true;

        $conn = $this->conn();
        if ($safe && !LADDLUtils::existTable($conn, $this->streams_sources_table_name)) {
            $this->sources = [];
            $this->streams = [];
            return;
        }

        $boosted = [];
        $load_boosted = false;
        $sources = LADB::sources($conn, $this->cache_table_name, $this->streams_sources_table_name, null, $only_enable);
        if ($remote) {
            foreach ($sources as $id => &$tmp_source) {
                if ($tmp_source['boosted'] == LASettingsUtils::YEP) {
                    if (!$load_boosted) {
                        $boosted = $this->getBoostSources();
                        $load_boosted = true;
                    }
                    if (isset($boosted[$id])) {
                        $tmp_source = $boosted[$id];
                    }
                }
            }
        }
        $this->sources = $sources;
        $this->streams = LADB::streams($conn, $this->streams_table_name);
        $connections = $conn->getIndMultiRow('stream_id', 'select `stream_id`, `feed_id` from ?n order by `stream_id`', $this->streams_sources_table_name);
        foreach ($this->streams as &$stream) {
            $stream = (array) LADB::unserializeStream($stream);
            if (!isset($stream['feeds']))
                $stream['feeds'] = [];
            $stream['status'] = '1';
            if (isset($connections[$stream['id']])) {
                foreach ($connections[$stream['id']] as $source) {
                    if (isset($this->sources[$source['feed_id']])) {
                        $full_source = $this->sources[$source['feed_id']];
                        $stream['feeds'][] = $full_source;
                        if (isset($full_source['status']) && $full_source['status'] == 0)
                            $stream['status'] = '0';
                    }
                }
            }
        }
    }

    /**
     * Get stream settings by id endpoint
     * @throws Exception
     */
    public final function get_stream_settings()
    {

        if (FF_USE_WP) {
            if (!current_user_can('manage_options') || !check_ajax_referer('flow_flow_nonce', 'security', false)) {
                die(json_encode(['error' => 'not_allowed']));
            }
        }

        $id = LAUtils::get_request_var('stream-id', 'get', 'text');
        $this->dataInit(false, false);

        $stream = $this->streams[$id];

        // cleaning if error was saved in database stream model, can be removed in future, now it's needed for affected users
        if (isset($stream['error']))
            unset($stream['error']);

        die(json_encode($stream));
    }

    public final function get_shortcode_pages()
    {
        global $wpdb;

        if (FF_USE_WP) {
            if (!current_user_can('manage_options') || !check_ajax_referer('flow_flow_nonce', 'security', false)) {
                die(json_encode(['error' => 'not_allowed']));
            }
        }

        $stream = LAUtils::get_request_var('stream', 'post', 'text');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status = 'publish'",
            '%[ff id="' . $stream . '"%'
        ));

        foreach ($results as $result) {
            $result->url = get_permalink($result->ID);
        }

        die(json_encode($results));
    }

    /**
     * Create stream endpoint
     */
    public final function create_stream()
    {
        $this->checkSecurity();

        $stream = $this->getStreamFromRequestWithoutErrors();
        $conn = $this->conn();
        try {
            $conn->beginTransaction();
            if (false !== ($max = LADB::maxIdOfStreams($conn, $this->streams_table_name))) {
                $newId = (string) ($max + 1);
                $stream->id = $newId;
                $stream->feeds = isset($stream->feeds) ? $stream->feeds : json_encode([]);
                $stream->name = isset($stream->name) ? $stream->name : '';
                LADB::setStream($conn, $this->streams_table_name, $this->streams_sources_table_name, $newId, $stream);
                $response = json_encode(LADB::getStream($conn, $this->streams_table_name, $newId));
                $conn->commit();

                $this->refreshCache($newId);
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo $response;
            } else {
                echo false;
            }
        } catch (Exception $e) {
            $conn->rollbackAndClose();
            $msg = $e->getMessage();
            if (strpos($msg, "doesn't exist") !== false || strpos($msg, "Unknown column") !== false || strpos($msg, "Data too long") !== false) {
                $this->repairDB();
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo 'Caught exception: ' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "\n";
        }
        $conn->close();
        die();
    }

    /**
     * Save sources endpoint
     * @throws Exception
     */
    public final function save_sources_settings()
    {
        if (FF_USE_WP) {
            if (!current_user_can('manage_options') || !check_ajax_referer('flow_flow_nonce', 'security', false)) {
                $dontChange = true;
                $this->debugLog('save_sources_settings: permission denied or nonce failed');
            }
        }

        try {
            $model = LAUtils::get_request_var('model', 'post', 'raw', null);
            if ($model !== null) {
                $model['id'] = 1; // DON'T DELETE, ID is always 1, this is needed to detect if model was saved

                if (isset($dontChange) && isset($model['feeds_changed'])) {
                    unset($model['feeds_changed']);
                }

                $boosted = false;
                $original_status = 0;
                if (isset($model['feeds_changed'])) {
                    $this->debugLog('save_sources_settings: feeds_changed', $model['feeds_changed']);
                    foreach ($model['feeds_changed'] as $feed) {
                        $feedData = isset($model['feeds'][$feed['id']]) ? $model['feeds'][$feed['id']] : 'MISSING';
                        $this->debugLog('Processing feed change', ['meta' => $feed, 'data' => $feedData]);
                        switch ($feed['state']) {
                            case 'changed':
                                $source = $model['feeds'][$feed['id']];
                                $original_status = $source['status'];
                                $sources = LADB::sources($this->conn(), $this->cache_table_name, $this->streams_sources_table_name);
                                $old = $sources[$source['id']];
                                $changed_content = $this->changedContent($source, $old);

                                // FORCE TIKTOK REFRESH TIME TO MAX 6 HOURS
                                $settings = unserialize($source['settings']);
                                if (isset($settings->type) && $settings->type == 'tiktok' && intval($source['cache_lifetime']) > 360) {
                                    $source['cache_lifetime'] = 360;
                                }

                                if ($changed_content) {
                                    $this->cleanFeed($feed['id']);
                                    if (!$boosted) {
                                        $boosted = LASettingsUtils::YepNope2ClassicStyle($source['boosted'], false);
                                        if (!$boosted && ($source['boosted'] != $old['boosted'])) {
                                            $boosted = true;
                                        }
                                    }
                                } else if (!$boosted) {
                                    $boosted = LASettingsUtils::YepNope2ClassicStyle($source['boosted'], false);
                                }
                                $this->modifySource($source, $changed_content);
                                if ($source['enabled'] == 'yep') {
                                    $this->refreshSourceAfterSettings($feed['id'], false, $boosted);
                                }
                                break;
                            case 'created':
                                $source = $model['feeds'][$feed['id']];
                                $this->modifySource($source);
                                if (!$boosted) {
                                    $boosted = LASettingsUtils::YepNope2ClassicStyle($source['boosted'], false);
                                }
                                $this->refreshSourceAfterSettings($feed['id'], true, $boosted);
                                break;
                            case 'reset_cache':
                                $source = $model['feeds'][$feed['id']];
                                $this->cleanFeed($feed['id']);
                                if (!$boosted) {
                                    $boosted = LASettingsUtils::YepNope2ClassicStyle($source['boosted'], false);
                                }
                                $this->refreshSourceAfterSettings($feed['id'], true, $boosted);
                                break;
                            case 'deleted':
                                $sources = LADB::sources($this->conn(), $this->cache_table_name, $this->streams_sources_table_name);
                                if (isset($sources[$feed['id']])) {
                                    $source = $sources[$feed['id']];
                                    $this->deleteFeed($feed['id']);
                                    if (!$boosted) {
                                        $boosted = LASettingsUtils::YepNope2ClassicStyle($source['boosted'], false);
                                    }
                                }
                                break;
                        }
                    }
                    
                }

                if ($boosted) {
                    $response = $this->proxyRequest($_POST);
                    if ($response->code == 200) {
                        $json = $response->body;
                        foreach ($json['feeds'] as &$feed) {
                            $enabled = LASettingsUtils::YepNope2ClassicStyle($feed['enabled'], false) ? 1 : 0;
                            $status = ['last_update' => $feed['last_update'], 'status' => $original_status, 'enabled' => $enabled];
                            if (isset($feed['errors']) && is_array($feed['errors']))
                                $status['errors'] = serialize($feed['errors']);
                            $this->saveSource($feed['id'], $status);
                            $feed['last_update'] = $feed['last_update'] == 0 ? 'N/A' : LASettingsUtils::classicStyleDate($feed['last_update']);
                        }
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        echo json_encode($json);
                    } else if ($response->code == 504) {
                        header('HTTP/1.1 504 Gateway Time-out');
                    } else if ($response->code == 403) {
                        header('HTTP/1.1 403 Forbidden');
                        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        echo $response->raw_body;
                    }
                } else {
                    if (isset($model['feeds'])) {
                        $this->dataInit();
                        $sources = $this->sources();
                        foreach ($model['feeds'] as &$source) {
                            if (array_key_exists($source['id'], $sources)) {
                                $source = $sources[$source['id']];
                            }
                        }
                    }
                    if (isset($dontChange)) {
                        $model['error'] = 'Not allowed';
                    }
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo json_encode($model);
                }
                die();
            }
        } catch (\Throwable $e) {
            try {
                if ($this->conn !== null) $this->conn->rollbackAndClose();
            } catch (\Throwable $ignored) {}
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            \la\core\LAUtils::errorLog('save_sources_settings error:');
            $msg = $e->getMessage();
            if (strpos($msg, "doesn't exist") !== false || strpos($msg, "Unknown column") !== false || strpos($msg, "Data too long") !== false) {
                $this->repairDB();
            }
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            \la\core\LAUtils::errorLog($msg);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            die(esc_html($msg));
        }
        die(1);
    }

    /**
     * Save stream endpoint
     */
    public final function save_stream_settings()
    {
        $this->checkSecurity();
        $stream = $this->getStreamFromRequestWithoutErrors();

        $is_lite = ($this->plugin_slug === 'flow-flow-lite' || $this->plugin_slug === 'flow-flow-social-streams');
        if ($is_lite) {
            $feeds = isset($stream->feeds) ? $stream->feeds : [];
            if (is_string($feeds)) {
                $feeds = stripslashes($feeds);
                $feeds = json_decode($feeds);
            }
            $feeds = (array)$feeds;
            if (count($feeds) > 1) {
                die(json_encode(['error' => 'Multi-feeds stream is a PRO feature.']));
            }
        }

        $conn = $this->conn();
        try {
            $conn->beginTransaction();
            $stream->last_changes = time();
            LADB::setStream($conn, $this->streams_table_name, $this->streams_sources_table_name, $stream->id, $stream);

            $this->generateCss($stream);

            if ($conn->commit() === false) {
                throw new Exception('Could not commit stream settings');
            }
            LATransientCache::invalidateStream($stream->id);
            echo json_encode($stream);

            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $this->proxyRequest($_POST);
        } catch (Exception $e) {
            $conn->rollbackAndClose();
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            \la\core\LAUtils::errorLog('save_stream_settings error:');
            $msg = $e->getMessage();
            if (strpos($msg, "doesn't exist") !== false || strpos($msg, "Unknown column") !== false || strpos($msg, "Data too long") !== false) {
                $this->repairDB();
            }
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            \la\core\LAUtils::errorLog($msg);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            \la\core\LAUtils::errorLog($e->getTraceAsString());
        }
        $conn->close();
        die();
    }

    /**
     * Save general settings endpoint
     */
    public final function ff_save_settings_fn()
    {

        if (FF_USE_WP) {
            if (!current_user_can('manage_options') || !check_ajax_referer('flow_flow_nonce', 'security', false)) {
                die(json_encode(['error' => 'not_allowed']));
            }
        }

        $serialized_settings = LAUtils::get_request_var('settings', 'post', 'raw');
        $settings = [];
        parse_str($serialized_settings, $settings);

        $conn = $this->conn();
        try {
            $activated = $this->activate($settings);
            $force_load_cache = $this->clean_cache($settings);

            $conn->beginTransaction();

            $settings = $this->saveGeneralSettings($settings);

            if ($conn->commit() === false) {
                throw new Exception('Could not commit general settings');
            }
            LATransientCache::invalidateAll();

            global $wp_locale;
            $_POST['wp_locale'] = json_encode($wp_locale);
            $_POST['wp_timezone_string'] = get_option('timezone_string');
            $_POST['wp_date_format'] = get_option('date_format');
            $_POST['wp_time_format'] = get_option('time_format');
            if (false !== ($option = get_option('la_facebook_access_token', false)))
                $_POST['la_facebook_access_token'] = $option;
            if (false !== ($option = get_option('la_facebook_access_token_expires', false)))
                $_POST['la_facebook_access_token_expires'] = $option;

            $this->proxyRequest($_POST);

            if ($force_load_cache) {
                $this->refreshCache(null, $force_load_cache);
            }

            $response = [
                'settings' => $settings,
                'activated' => $activated
            ];
            $this->customizeResponse($response);

            echo json_encode($response);
        } catch (Exception $e) {
            $conn->rollbackAndClose();
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            \la\core\LAUtils::errorLog('ff_save_settings_fn error:');
            $msg = $e->getMessage();

            if (strpos($msg, "doesn't exist") !== false || strpos($msg, "Unknown column") !== false || strpos($msg, "Data too long") !== false) {
                $this->repairDB();
            }

            if (strpos($msg, 'Connection timed out after') !== false) {
                $msg .= '. Failed to connect to https://flow.looks-awesome.com which validates purchase code. Please ask help from your hosting support and tell them curl_exec exits with connection timeout error on line 889 of wp-content/plugins/flow-flow/includes/db/LADBManager.php';
            }

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            \la\core\LAUtils::errorLog($msg);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            \la\core\LAUtils::errorLog($e->getTraceAsString());
            die(esc_html($e->getMessage()));
        }
        $conn->close();
        die();
    }

    /**
     * @param mixed $old_value
     * @param mixed $value
     * @param string $option
     *
     * @noinspection PhpUnusedParameterInspection
     * @throws Exception
     */
    public function update_wp_date_format_hook($old_value, $value, $option)
    {
        if ($option === 'WPLANG') {
            switch_to_locale(empty($value) ? 'en_US' : $value);
        }
        global $wp_locale;
        $post['action'] = 'flow_flow_save_settings_date_format';
        $post['wp_locale'] = json_encode($wp_locale);
        $post['wp_timezone_string'] = get_option('timezone_string');
        $post['wp_date_format'] = get_option('date_format');
        $post['wp_time_format'] = get_option('time_format');
        $this->proxyRequest($post);
    }

    /**
     * @throws Exception
     */
    public function update_options()
    {
        $data = [
            'action' => 'flow_flow_save_settings',
            'options' => $this->getOption('options', false, false, true),
            'fb_auth_options' => $this->getOption('fb_auth_options', false, false, true)
        ];
        if (false != ($la_facebook_access_token = get_option('la_facebook_access_token', false))) {
            $data['la_facebook_access_token'] = $la_facebook_access_token;
        }
        if (false != ($la_facebook_access_token_expires = get_option('la_facebook_access_token_expires', false))) {
            $data['la_facebook_access_token_expires'] = $la_facebook_access_token_expires;
        }
        $this->proxyRequest($data);
    }

    /**
     * @throws Exception
     */
    public function get_sources()
    {
        if (FF_USE_WP) {
            if (!current_user_can('manage_options') || !check_ajax_referer('flow_flow_nonce', 'security', false)) {
                die(json_encode(['error' => 'not_allowed']));
            }
        }

        $this->dataInit(false, false);
        
        $conn = $this->conn();
        foreach ($this->sources as &$source) {
            $count = $conn->getOne('SELECT COUNT(*) FROM ?n WHERE feed_id = ?s', $this->posts_table_name, $source['id']);
            $source['posts_count'] = $count ? $count : 0;
        }
        unset($source);

        $request_id = LAUtils::get_request_var('id', 'request', 'text');

        if (!empty($request_id)) {
            if (isset($this->sources[$request_id])) {
                die(json_encode($this->sources[$request_id]));
            } else {
                header("HTTP/1.0 404 Not Found");
                die();
            }
        }
        die(json_encode($this->sources));
    }

    /**
     * @throws Exception
     */
    public final function get_boosts()
    {
        if (FF_USE_WP) {
            if (!current_user_can('manage_options') || !check_ajax_referer('flow_flow_nonce', 'security', false)) {
                die(json_encode(['error' => 'not_allowed']));
            }
        }

        $response = [
            'status' => 'never_used',
            'plan' => 0,
            'available' => 0,
            'expire' => 0
        ];
        echo json_encode($response);
        die();
    }

    /**
     * @throws Exception
     */
    public function paymentSuccess()
    {
        $this->requireSubscriptionAdmin();
        $email = LAUtils::get_request_var('email', 'request', 'email');
        $checkout_id = LAUtils::get_request_var('checkout_id', 'request', 'text');

        $this->setOption('boosts_email', $email);
        $this->setOption('boosts_checkout_id', $checkout_id);
        $this->deleteOption('boosts_token');
        $this->deleteOption('boosts_subscription');

        header('Location: ' . admin_url('admin.php?page=flow-flow-admin&subscription=1'), true, 301);
        die();
    }

    /**
     * @throws Exception
     */
    public function upgradeSubscription()
    {
        header('Location: ' . admin_url('admin.php?page=flow-flow-admin'), true, 301);
        die();
    }

    /**
     * @throws Exception
     */
    public function cancelSubscription()
    {
        $this->requireSubscriptionAdmin();
        $this->deleteOption('boosts_email');
        $this->deleteOption('boosts_token');
        $this->deleteOption('boosts_checkout_id');
        $this->deleteOption('boosts_subscription');
        $this->deleteBoostedFeeds();
        header('Location: ' . admin_url('admin.php?page=flow-flow-admin'), true, 301);
        die();
    }

    /**
     * @throws Exception
     */
    public function clearSubscriptionCache()
    {
        $this->requireSubscriptionAdmin();
        $email = LAUtils::get_request_var('email', 'request', 'email', null);
        if ($email !== null) {
            $this->setOption('boosts_email', $email);
        }
        $checkout_id = LAUtils::get_request_var('checkout_id', 'request', 'text', null);
        if ($checkout_id !== null) {
            $this->setOption('boosts_checkout_id', $checkout_id);
        }
        $this->deleteOption('boosts_token');
        $this->deleteOption('boosts_subscription');
        die;
    }

    /**
     * Subscription handlers change stored state from GET links, so they need an administrator and a nonce.
     */
    private function requireSubscriptionAdmin()
    {
        if (FF_USE_WP && (!current_user_can('manage_options') || !check_ajax_referer('flow_flow_nonce', 'security', false))) {
            wp_die('You are not allowed to change the subscription.', '', 403);
        }
    }

    public final function email_notification()
    {
        $admin_email = get_option('admin_email');
        if (!empty($admin_email)) {
            $conn = $this->conn();
            $disabled_feeds = $conn->getAll('SELECT * FROM ?n WHERE enabled = 1 AND system_enabled = 0 AND send_email = 0', $this->cache_table_name);
            if (!empty($disabled_feeds)) {
                ob_start();
                /** @noinspection PhpIncludeInspection */
                include($this->context['root'] . 'views/email.php');
                $message = ob_get_clean();

                $headers = [];
                $headers[] = 'MIME-Version: 1.0';
                $headers[] = 'Content-type: text/html; charset=iso-8859-1';
                $headers[] = 'X-Mailer: PHP/' . phpversion();
                //				$headers[] = 'To: ' . $admin_email;
                $headers[] = 'From: Social Stream Apps <' . $admin_email . '>';
                $blog_name = htmlspecialchars_decode(get_bloginfo('name'));

                $success = mail($admin_email, "[Flow-Flow] Broken feeds detected on " . $blog_name, $message, implode("\r\n", $headers));
                if ($success) {
                    try {
                        $conn->beginTransaction();
                        foreach ($disabled_feeds as $feed) {
                            $success = $this->saveSource($feed['feed_id'], ['send_email' => 1]);
                            if (!$success) {
                                throw new Exception('Save source problem');
                            }
                        }
                        $conn->commit();
                    } catch (Exception $e) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                        \la\core\LAUtils::errorLog('email_notification');
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                        \la\core\LAUtils::errorLog($e->getMessage());
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                        \la\core\LAUtils::errorLog($e->getTraceAsString());
                        $conn->rollbackAndClose();
                    }
                } else {
                    $errorMessage = error_get_last();
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    \la\core\LAUtils::errorLog($errorMessage['message']);
                }
            }
        }
    }

    /**
     * @param bool $commit False joins the caller's transaction: failures throw and nothing is committed.
     */
    public function modifySource($source, $changed_content = true, $with_errors = false, $commit = true)
    {
        $this->debugLog('modifySource called', ['source' => $source, 'changed_content' => $changed_content]);
        $errors = '';
        $id = $source['id'];
        $enabled = $source['enabled'];
        $cache_lifetime = $source['cache_lifetime'];
        $status = isset($source['status']) ? intval($source['status']) : 0;
        $boosted = $source['boosted'];
        unset($source['id']);
        unset($source['enabled']);
        unset($source['last_update']);
        unset($source['cache_lifetime']);
        unset($source['boosted']);
        if ($with_errors && isset($source['errors'])) {
            $errors = serialize($source['errors']);
        }
        if (isset($source['errors']))
            unset($source['errors']);
        if (isset($source['status']))
            unset($source['status']);
        if (isset($source['system_enabled']))
            unset($source['system_enabled']);

        $in = [
            'settings' => serialize((object) $source),
            'enabled' => (int) LASettingsUtils::YepNope2ClassicStyle($enabled, true),
            'system_enabled' => (int) LASettingsUtils::YepNope2ClassicStyle($enabled, true),
            'last_update' => 0,
            'changed_time' => time(),
            'cache_lifetime' => $cache_lifetime,
            'status' => $status,
            'boosted' => $boosted
        ];
        $up = [
            'settings' => serialize((object) $source),
            'enabled' => (int) LASettingsUtils::YepNope2ClassicStyle($enabled, true),
            'system_enabled' => (int) LASettingsUtils::YepNope2ClassicStyle($enabled, true),
            'cache_lifetime' => $cache_lifetime,
            'boosted' => $boosted
        ];
        if ($changed_content)
            $up['last_update'] = '0';
        if ($with_errors && !empty($errors))
            $up['errors'] = $errors;
        $conn = $this->conn();
        try {
            if (
                false === $conn->query(
                    'INSERT INTO ?n SET `feed_id`=?s, ?u ON DUPLICATE KEY UPDATE ?u',
                    $this->cache_table_name,
                    $id,
                    $in,
                    $up
                )
            ) {
                throw new Exception('Could not save source ' . $id);
            }
            if (!$commit) return;
            if ($conn->commit() === false) {
                throw new Exception('Could not commit source changes');
            }
            LATransientCache::invalidateAll();
        } catch (Exception $e) {
            if (!$commit) throw $e;
            $conn->rollback();
            $this->debugLog('modifySource exception', $e->getMessage());
        }
    }

    private function changedContent($source, $old)
    {
        foreach ($source as $key => $value) {
            $old_value = $old[$key];
            if (
                $key == 'status' || $key == 'enabled' || $key == 'posts' || $key == 'errors' || $key == 'last_update' ||
                $key == 'cache_lifetime' || $key == 'mod' || $key == 'posts'
            )
                continue;
            if ($old_value !== $value) {
                return true;
            }
        }
        return false;
    }

    public function getGeneralSettings()
    {
        return new LAGeneralSettings($this->getOption('options', true), $this->getOption('fb_auth_options', true));
    }


    public function getOption($optionName, $serialized = false, $lock_row = false, $without_cache = false)
    {
        $options = LADB::getOption($this->conn(), $this->option_table_name, $this->plugin_slug_down . '_' . $optionName, $serialized, $lock_row, $without_cache);
        if ($optionName == 'options' && is_array($options)) {
            $options['general-uninstall'] = get_option($this->plugin_slug_down . '_general_uninstall', LASettingsUtils::NOPE);
        }
        return $options;
    }

    /**
     * @param $optionName
     * @param $optionValue
     * @param false $serialized
     * @param bool $cached
     *
     * @throws Exception
     */
    public function setOption($optionName, $optionValue, $serialized = false, $cached = true)
    {
        LADB::setOption($this->conn(), $this->option_table_name, $this->plugin_slug_down . '_' . $optionName, $optionValue, $serialized, $cached);
    }

    /**
     * @param $optionName
     *
     * @throws Exception
     */
    public function deleteOption($optionName)
    {
        LADB::deleteOption($this->conn(), $this->option_table_name, $this->plugin_slug_down . '_' . $optionName);
    }

    /**
     * @return array
     * @throws Exception
     */
    public function streams()
    {
        if ($this->init)
            return $this->streams;
        throw new Exception('Don`t init data manager');
    }

    public function countFeeds()
    {
        return LADB::countFeeds($this->conn(), $this->cache_table_name);
    }

    public function getStream($streamId)
    {
        return $this->streams[$streamId];
    }

    public function delete_stream()
    {

        if (FF_USE_WP) {
            if (!current_user_can('manage_options') || !check_ajax_referer('flow_flow_nonce', 'security', false)) {
                die(json_encode(['error' => 'not_allowed']));
            }
        }

        $conn = $this->conn();
        try {
            $conn->beginTransaction();
            $id = LAUtils::get_request_var('stream-id', 'post', 'text');
            LADB::deleteStream($conn, $this->streams_table_name, $this->streams_sources_table_name, $id);
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
            do_action('ff_after_delete_stream', $id);
            if ($conn->commit() === false) {
                throw new Exception('Could not commit stream deletion');
            }
            LATransientCache::invalidateStream($id);

            $this->proxyRequest($_POST);
        } catch (Exception $e) {
            $msg = $e->getMessage();
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            \la\core\LAUtils::errorLog($msg);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            \la\core\LAUtils::errorLog($e->getTraceAsString());
            $conn->rollbackAndClose();
            if (strpos($msg, "doesn't exist") !== false || strpos($msg, "Unknown column") !== false || strpos($msg, "Data too long") !== false) {
                $this->repairDB();
            }
            die(false);
        }
        wp_send_json([], 200);
    }

    public function canCreateCssFolder()
    {
        $slug = ($this->context['slug'] === 'flow-flow-social-streams') ? 'flow-flow' : $this->context['slug'];
        $dir = WP_CONTENT_DIR . '/resources/' . $slug . '/css';
        if (!file_exists($dir)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
            return mkdir($dir, 0777, true);
        }
        return true;
    }

    public function generateCss($stream)
    {
        $slug = ($this->context['slug'] === 'flow-flow-social-streams') ? 'flow-flow' : $this->context['slug'];
        $dir = WP_CONTENT_DIR . '/resources/' . $slug . '/css';
        if (!file_exists($dir)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
            mkdir($dir, 0777, true);
        }

        $filename = $dir . "/stream-id" . $stream->id . ".css";
        if (!is_main_site()) {
            $filename = $dir . '/stream-id' . $stream->id . '-' . get_current_blog_id() . '.css';
        }
        ob_start();
        /** @noinspection PhpIncludeInspection */
        include($this->context['root'] . 'views/stream-template-css.php');
        $output = ob_get_clean();
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $a = fopen($filename, 'w');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite
        fwrite($a, $output);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($a);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
        chmod($filename, 0644);
    }

    public function clone_stream()
    {

        if (FF_USE_WP) {
            if (!current_user_can('manage_options') || !check_ajax_referer('flow_flow_nonce', 'security', false)) {
                die(json_encode(['error' => 'not_allowed']));
            }
        }

        $stream = LAUtils::get_request_var('stream', 'request', 'raw', []);

        // cleaning if error was saved in database stream model, can be removed in future, now it's needed for affected users
        if (isset($stream['error']))
            unset($stream['error']);

        $stream = (object) $stream;
        $conn = $this->conn();
        try {
            $conn->beginTransaction();
            if (false !== ($count = LADB::maxIdOfStreams($conn, $this->streams_table_name))) {
                $newId = (string) ($count + 1);
                $stream->id = $newId;
                $stream->name = "{$stream->name} copy";
                $stream->last_changes = time();
                LADB::setStream($conn, $this->streams_table_name, $this->streams_sources_table_name, $newId, $stream);
                $this->generateCss($stream);

                $this->proxyRequest($_POST);

                $conn->commit();
                echo json_encode($stream);
            } else {
                throw new Exception('Can`t get a new id for the clone stream');
            }
        } catch (Exception $e) {
            $conn->rollbackAndClose();
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            \la\core\LAUtils::errorLog('clone_stream error:');
            $msg = $e->getMessage();
            if (strpos($msg, "doesn't exist") !== false || strpos($msg, "Unknown column") !== false || strpos($msg, "Data too long") !== false) {
                $this->repairDB();
            }
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            \la\core\LAUtils::errorLog($msg);
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            \la\core\LAUtils::errorLog($e->getTraceAsString());
        }
        $conn->close();
        die();
    }

    /**
     * @param $settings
     *
     * @return mixed
     * @throws Exception
     */
    protected function saveGeneralSettings($settings)
    {
        if (isset($settings['flow_flow_options']['general-uninstall'])) {
            $general_uninstall_option_name = $this->plugin_slug_down . '_general_uninstall';
            $value = ($settings['flow_flow_options']['general-uninstall'] === LASettingsUtils::YEP) ? LASettingsUtils::YEP : LASettingsUtils::NOPE;
            if (get_option($general_uninstall_option_name) !== false) {
                update_option($general_uninstall_option_name, $value);
            } else {
                add_option($general_uninstall_option_name, $value, '', 'no');
            }
            unset($settings['flow_flow_options']['general-uninstall']);
        }

        $this->setOption('options', $settings['flow_flow_options'], true);
        return $settings;
    }

    protected abstract function customizeResponse(&$response);
    
    public abstract function repairDB();

    protected abstract function clean_cache($options);

    protected abstract function refreshCache($streamId, $force_load_cache = false);

    private function refreshSourceAfterSettings($id, $force, $boosted)
    {
        try {
            return $this->refreshCache4Source($id, $force, $boosted);
        } catch (\Throwable $error) {
            // Settings are already committed. A provider dispatch failure must
            // not prevent the remaining source changes or their JSON response.
            \la\core\LAUtils::errorLog('Flow-Flow source refresh failed for ' . $id . ': ' . $error->getMessage());
            return false;
        }
    }

    protected function refreshCache4Source($id, $force_load_cache = false, $boosted = false)
    {
        if ($boosted) return false;
        $request = $_REQUEST;
        $forceRefresh = LACacheManager::$forceRefresh;
        $conn = null;
        try {
            $conn = $this->conn();
            $previousStatus = $conn->getOne('SELECT `status` FROM ?n WHERE `feed_id` = ?s', $this->cache_table_name, $id);
            // Callers commit their settings changes before dispatching a
            // refresh. Finish the marker transaction before provider reads.
            if ($this->saveSource($id, ['status' => '2']) === false || $conn->commit() === false || $conn->autocommit(true) === false) {
                throw new Exception('Could not start source refresh');
            }
            $_REQUEST['feed_id'] = $id;
            unset($_REQUEST['force'], $_REQUEST['stream_id']);
            LACacheManager::$forceRefresh = (bool)$force_load_cache;
            $base = \la\core\LABase::get_instance($this->context);
            $base->processAjaxRequestBackground(true, false);
            $this->restoreRefreshMarker($id, $previousStatus);
            return true;
        } catch (\Throwable $error) {
            // Dispatch can fail before posts() starts or after it commits. Use
            // the current physical handle, which the dispatcher may replace.
            try {
                $failedConnection = $this->conn !== null ? $this->conn : $conn;
                if ($failedConnection) $failedConnection->rollbackAndClose();
            } catch (\Throwable $ignored) {}
            try {
                $conn = $this->conn();
                $status = ['status' => 0, 'error_timestamp' => time(),
                    'errors' => serialize([['type' => 'refresh', 'message' => $error->getMessage(), 'code' => $error->getCode()]])];
                if ($conn->beginTransaction() === false || $conn->query(
                    'UPDATE ?n SET ?u WHERE `feed_id` = ?s AND `status` = 2', $this->cache_table_name, $status, $id
                ) === false) throw new Exception('Could not record source dispatch failure');
                $changed = $conn->affectedRows() > 0;
                if ($conn->commit() === false) throw new Exception('Could not commit source dispatch failure');
                if ($changed) LATransientCache::invalidateAll();
            } catch (\Throwable $statusError) {
                try { if ($conn) $conn->rollback(); } catch (\Throwable $ignored) {}
                \la\core\LAUtils::errorLog('Flow-Flow could not record source refresh failure: ' . $statusError->getMessage());
            }
            throw $error;
        } finally {
            $_REQUEST = $request;
            LACacheManager::$forceRefresh = $forceRefresh;
        }
    }

    /**
     * A dispatch that skipped the feed (cache still fresh, or another refresh
     * holds its lock) leaves the marker behind. Put the previous status back.
     */
    private function restoreRefreshMarker($id, $previousStatus)
    {
        $status = in_array((string)$previousStatus, ['0', '1'], true) ? (string)$previousStatus : '1';
        $conn = $this->conn();
        if ($conn->beginTransaction() === false) throw new Exception('Could not restore source status');
        $current = $conn->getOne('SELECT `status` FROM ?n WHERE `feed_id` = ?s FOR UPDATE', $this->cache_table_name, $id);
        $changed = (string)$current === '2';
        if ($changed && $this->saveSource($id, ['status' => $status]) === false) throw new Exception('Could not restore source status');
        if ($conn->commit() === false) throw new Exception('Could not commit source status');
        if ($changed) LATransientCache::invalidateAll();
    }

    /**
     * @return array|null
     * @throws Exception
     */
    public function streamsWithStatus()
    {
        if (false !== ($result = self::streams())) {
            return $result;
        }
        return [];
    }

    /**
     * @return array | null
     * @throws Exception
     */
    public function sources()
    {
        if ($this->init)
            return $this->sources;
        throw new Exception('Don`t init data manager');
    }

    //TODO: refactor posts table does not have field with name stream_id
    public function clean(?array $streams = null, $commit = true)
    {
        $conn = $this->conn();
        $partOfSql = $streams == null ? '' : $conn->parse('WHERE `stream_id` IN (?a)', $streams);
        if (!$commit) {
            if ($conn->query('DELETE FROM ?n ?p', $this->posts_table_name, $partOfSql) === false
                || $conn->query('DELETE FROM ?n', $this->image_cache_table_name) === false) {
                throw new Exception('Could not clean cached posts');
            }
            return;
        }
        try {
            if ($conn->beginTransaction()) {
                $conn->query('DELETE FROM ?n ?p', $this->posts_table_name, $partOfSql);
                $conn->query('DELETE FROM ?n', $this->image_cache_table_name);
                if ($conn->commit() === false) {
                    throw new Exception('Could not commit source changes');
                }
                LATransientCache::invalidateAll();
            }
            $conn->rollback();
        } catch (Exception $e) {
            $conn->rollbackAndClose();
        }
    }


    public function deleteFeed($feedId, $commit = true)
    {
        $conn = $this->conn();
        if (!$commit) {
            $partOfSql = $conn->parse('WHERE `feed_id` = ?s', $feedId);
            foreach ([$this->posts_table_name, $this->post_media_table_name, $this->cache_table_name, $this->streams_sources_table_name] as $table) {
                if ($conn->query('DELETE FROM ?n ?p', $table, $partOfSql) === false) throw new Exception('Could not delete source ' . $feedId);
            }
            return;
        }
        try {
            if ($conn->beginTransaction()) {
                $partOfSql = $conn->parse('WHERE `feed_id` = ?s', $feedId);
                $conn->query('DELETE FROM ?n ?p', $this->posts_table_name, $partOfSql);
                $conn->query('DELETE FROM ?n ?p', $this->post_media_table_name, $partOfSql);
                $conn->query('DELETE FROM ?n ?p', $this->cache_table_name, $partOfSql);
                $conn->query('DELETE FROM ?n ?p', $this->streams_sources_table_name, $partOfSql);
                if ($conn->commit() === false) {
                    throw new Exception('Could not commit source changes');
                }
                LATransientCache::invalidateAll();
            }
            $conn->rollback();
        } catch (Exception $e) {
            $conn->rollbackAndClose();
        }
    }

    public function cleanFeed($feedId)
    {
        $conn = $this->conn();
        try {
            if ($conn->beginTransaction()) {
                $partOfSql = $conn->parse('WHERE `feed_id` = ?s', $feedId);
                $conn->query('DELETE FROM ?n ?p', $this->posts_table_name, $partOfSql);
                $conn->query('DELETE FROM ?n ?p', $this->post_media_table_name, $partOfSql);
                $this->setCacheInfo($feedId, ['last_update' => 0, 'status' => 0]);
                if ($conn->commit() === false) {
                    throw new Exception('Could not commit source changes');
                }
                LATransientCache::invalidateAll();
            }
            $conn->rollback();
        } catch (Exception $e) {
            $conn->rollbackAndClose();
        }
    }

    public function cleanByFeedType($feedType)
    {
        $conn = $this->conn();
        try {
            if ($conn->beginTransaction()) {
                $feeds = $conn->getCol('SELECT DISTINCT `feed_id` FROM ?n WHERE `post_type` = ?s', $this->posts_table_name, $feedType);
                if (!empty($feeds)) {
                    $conn->query("DELETE FROM ?n WHERE `feed_id` IN (?a)", $this->posts_table_name, $feeds);
                    $conn->query("DELETE FROM ?n WHERE `feed_id` IN (?a)", $this->post_media_table_name, $feeds);
                    if ($conn->commit() === false) {
                        throw new Exception('Could not commit source changes');
                    }
                    LATransientCache::invalidateAll();
                }
            }
            $conn->rollback();
        } catch (Exception $e) {
            $conn->rollbackAndClose();
        }
    }

    public function resetCacheLifetimeByFeedType($feedType)
    {
        $conn = $this->conn();
        try {
            if ($conn->beginTransaction()) {
                $feeds = $conn->getCol('SELECT DISTINCT `feed_id` FROM ?n WHERE `post_type` = ?s', $this->posts_table_name, $feedType);
                if (!is_array($feeds)) $feeds = [];

                $all_feeds = $conn->getAll('SELECT `feed_id`, `settings` FROM ?n', $this->cache_table_name);
                if (!is_array($all_feeds)) $all_feeds = [];
                foreach ($all_feeds as $row) {
                    if (!empty($row['settings'])) {
                        $settings = unserialize($row['settings']);
                        if (is_object($settings) && isset($settings->type) && $settings->type === $feedType) {
                            if (!in_array($row['feed_id'], $feeds)) {
                                $feeds[] = $row['feed_id'];
                            }
                        }
                    }
                }

                if (!empty($feeds)) {
                    $conn->query('UPDATE ?n SET `last_update` = 0 WHERE `feed_id` IN (?a)', $this->cache_table_name, $feeds);
                }
                $conn->commit();
                // beginTransaction() turned autocommit off; put it back for the
                // callers that keep using this connection.
                $conn->autocommit(true);
            }
        } catch (Exception $e) {
            $conn->rollbackAndClose();
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            \la\core\LAUtils::errorLog('[Flow-Flow] Error in resetCacheLifetimeByFeedType: ' . $e->getMessage());
        }
    }

    /**
     * @param $only4insertPartOfSql
     * @param $imagePartOfSql
     * @param $mediaPartOfSql
     * @param $common
     *
     * @throws Exception
     */
    public function addOrUpdatePost($only4insertPartOfSql, $imagePartOfSql, $mediaPartOfSql, $common)
    {
        $conn = $this->conn();
        $sql = "INSERT INTO ?n SET ?p, ?p ?p ?u ON DUPLICATE KEY UPDATE ?p ?p ?u, `user_pic` = IF(VALUES(`user_pic`) != '' AND VALUES(`user_pic`) IS NOT NULL, VALUES(`user_pic`), `user_pic`)";
        if (false == $conn->query($sql, $this->posts_table_name, $only4insertPartOfSql, $imagePartOfSql, $mediaPartOfSql, $common, $imagePartOfSql, $mediaPartOfSql, $common)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new Exception($conn->getError());
        }
    }

    /**
     * @param $posts
     *
     * @throws Exception
     */
    public function updateAdditionalInfo($posts)
    {
        $conn = $this->conn();
        foreach ($posts as $post) {
            $sql = $conn->parse(
                'UPDATE ?n SET `post_additional` = ?s WHERE `post_id` = ?s AND `feed_id` = ?s AND `post_type` = ?s',
                $this->posts_table_name,
                json_encode($post->additional),
                $post->id,
                $post->feed_id,
                $post->type
            );
            if (false == $conn->query($sql)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new Exception($conn->getError());
            }
        }
    }

    public function getCarousel($feed_id, $post_id)
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        \la\core\LAUtils::errorLog(sprintf('Flow-Flow Debug: getCarousel called with feed_id: %s, post_id: %s', $feed_id, $post_id));

        $conn = $this->conn(true);

        // Check if media table exists
        $tableExists = $conn->getOne("SHOW TABLES LIKE ?s", $this->post_media_table_name);
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        \la\core\LAUtils::errorLog('Flow-Flow Debug: Media table exists: ' . ($tableExists ? 'Yes' : 'No'));

        if (!$tableExists) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            \la\core\LAUtils::errorLog('Flow-Flow Debug: Media table does not exist: ' . $this->post_media_table_name);
            return [];
        }

        // Get table structure for debugging
        $columns = $conn->getAll("SHOW COLUMNS FROM `{$this->post_media_table_name}`");
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_print_r
        \la\core\LAUtils::errorLog('Flow-Flow Debug: Media table columns: ' . print_r(array_column($columns, 'Field'), true));

        // Build query using SafeMySQL's query building methods
        $sql = "SELECT `feed_id`, `post_id`, `media_url`, `media_width`, `media_height`, `media_type` 
                FROM `{$this->post_media_table_name}`
                WHERE `feed_id` = ?s AND `post_id` = ?s";

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_print_r
        \la\core\LAUtils::errorLog('Flow-Flow Debug: Executing SQL: ' . $sql . ' with params: ' . print_r([$feed_id, $post_id], true));
        $result = $conn->getAll($sql, $feed_id, $post_id);

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        \la\core\LAUtils::errorLog(sprintf(
            'Flow-Flow Debug: Found %d media items for feed %s, post %s',
            count($result),
            $feed_id,
            $post_id
        ));

        if (empty($result)) {
            // Try to find any posts for this feed to see if they exist at all
            $anyPost = $conn->getRow(
                'SELECT `post_id` FROM ?n WHERE `feed_id`=?s LIMIT 1',
                $this->posts_table_name,
                $feed_id
            );

            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            \la\core\LAUtils::errorLog(sprintf(
                'Flow-Flow Debug: Checked for any posts in feed %s: %s',
                $feed_id,
                $anyPost ? 'Found post ' . $anyPost['post_id'] : 'No posts found'
            ));
        }

        return $result;
    }

    /**
     * @param $feed_id
     * @param $post_id
     * @param $post_type
     * @param $mediaPartOfSql4carousel
     *
     * @throws Exception
     */
    public function addCarouselMedia($feed_id, $post_id, $post_type, $mediaPartOfSql4carousel)
    {
        $common = [];
        $common['feed_id'] = $feed_id;
        $common['post_id'] = $post_id;
        $common['post_type'] = $post_type;

        $conn = $this->conn();
        //$sql  = $conn->parse('INSERT INTO ?n SET ?p ?u', $this->post_media_table_name, $mediaPartOfSql4carousel, $common);
        if (false == $conn->query('INSERT INTO ?n SET ?p ?u', $this->post_media_table_name, $mediaPartOfSql4carousel, $common)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new Exception($conn->getError());
        }
    }

    /**
     * @param $post_id
     * @param $comment
     *
     * @throws Exception
     */
    public function addComments($post_id, $comment)
    {
        $conn = $this->conn();
        $time = time();
        $sql_insert = "INSERT INTO ?n SET `id` = ?s, `post_id` = ?s, `from` = ?s, `text` = ?s, `created_time` = ?s, `updated_time` = ?s ON DUPLICATE KEY UPDATE ?u";
        $result = $conn->query($sql_insert, $this->comments_table_name, $comment->id, $post_id, is_string($comment->from) ? $comment->from : (is_object($comment->from) ? $comment->from->name : 'Facebook user'), $comment->text, $comment->created_time, $comment->created_time, ['updated_time' => $time, 'text' => $comment->text]);
        if (false === $result) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new Exception($conn->getError());
        }
    }

    public function removeComments($post_id)
    {
        $sql_delete = "DELETE FROM ?n WHERE `post_id` = ?s";
        $this->conn()->query($sql_delete, $this->comments_table_name, $post_id);
    }

    /**
     * @param $feed_id
     * @param $post_id
     *
     * @throws Exception
     */
    public function deleteCarousel4Post($feed_id, $post_id)
    {
        $conn = $this->conn();
        $sql = "delete from ?n where feed_id = ?s and post_id = ?s";
        if (false == $conn->query($sql, $this->post_media_table_name, $feed_id, $post_id)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new Exception($conn->getError());
        }
    }

    /**
     * @param $feed_id
     *
     * @throws Exception
     */
    public function deleteCarousel4Feed($feed_id)
    {
        $sql = "delete from ?n where feed_id = ?s";
        if (false == $this->conn()->query($sql, $this->post_media_table_name, $feed_id)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new Exception($this->conn()->getError());
        }
    }

    /**
     * @param string $feedId
     *
     * @return array|false
     */
    public function getIdPosts($feedId)
    {
        return $this->conn(true)->getCol('SELECT `post_id` FROM ?n WHERE `feed_id`=?s', $this->posts_table_name, $feedId);
    }

    public function getPostsIf($fields, $condition, $order, $offset = null, $limit = null)
    {
        $conn = $this->conn();
        $limitPart = ($offset !== null) ? $conn->parse("LIMIT ?i, ?i", $offset, $limit) : '';
        $sql = $conn->parse(
            "SELECT ?p FROM ?n post INNER JOIN ?n stream ON stream.feed_id = post.feed_id INNER JOIN ?n cach ON post.feed_id = cach.feed_id WHERE ?p ORDER BY ?p ?p",
            $fields,
            $this->posts_table_name,
            $this->streams_sources_table_name,
            $this->cache_table_name,
            $condition,
            $order,
            $limitPart
        );
        return $conn->getAll($sql);
    }

    public function getPostsIf2($fields, $condition)
    {
        return $this->conn()->getAll(
            "SELECT ?p FROM ?n post INNER JOIN ?n stream ON stream.feed_id = post.feed_id INNER JOIN ?n cach ON post.feed_id = cach.feed_id WHERE ?p ORDER BY post.post_timestamp DESC, post.post_id",
            $fields,
            $this->posts_table_name,
            $this->streams_sources_table_name,
            $this->cache_table_name,
            $condition
        );
    }

    public function countPostsIf($condition)
    {
        return $this->conn()->getOne(
            'SELECT COUNT(*) FROM ?n post INNER JOIN ?n stream ON stream.feed_id = post.feed_id INNER JOIN ?n cach ON post.feed_id = cach.feed_id WHERE ?p',
            $this->posts_table_name,
            $this->streams_sources_table_name,
            $this->cache_table_name,
            $condition
        );
    }

    public function getLastUpdateHash($streamId)
    {
        return $this->getHashIf($this->conn()->parse('stream.`stream_id` = ?s', $streamId));
    }

    public function getHashIf($condition)
    {
        return $this->conn()->getOne(
            "SELECT MAX(post.creation_index) FROM ?n post INNER JOIN ?n stream ON stream.feed_id = post.feed_id INNER JOIN ?n cach ON post.feed_id = cach.feed_id WHERE cach.boosted = 'nope' AND ?p",
            $this->posts_table_name,
            $this->streams_sources_table_name,
            $this->cache_table_name,
            $condition
        );
    }

    public function getLastUpdateTime($streamId)
    {
        return $this->conn()->getOne('SELECT MAX(`last_update`) FROM ?n `cach` inner join ?n `st2src` on `st2src`.`feed_id` = `cach`.`feed_id` WHERE `stream_id` = ?s', $this->cache_table_name, $this->streams_sources_table_name, $streamId);
    }

    public function getLastUpdateTimeAllStreams()
    {
        return $this->conn()->getIndCol('stream_id', 'SELECT MAX(`last_update`), `stream_id` FROM ?n `cach` inner join ?n `st2src` on `st2src`.`feed_id` = `cach`.`feed_id` GROUP BY `stream_id`', $this->cache_table_name, $this->streams_sources_table_name);
    }

    public function deleteEmptyRecordsFromCacheInfo($streamId)
    {
        //$this->conn()->query("DELETE FROM ?n where `stream_id`=?s", $this->cache_table_name, $streamId);
    }

    public function systemDisableSource($feedId, $enabled)
    {
        $values = ['system_enabled' => $enabled];
        if ($enabled == 0) {
            $values['send_email'] = 0;
        }
        return $this->saveSource($feedId, $values);
    }

    public function saveSource($feedId, $values)
    {
        return LADB::saveFeed($this->conn(), $this->cache_table_name, $feedId, $values);
    }

    /**
     * @param $feedId
     * @param $values
     *
     * @return FALSE|mysqli|resource
     * @deprecated
     * Use \flow\db\LADBManager::saveSource
     */
    public function setCacheInfo($feedId, $values)
    {
        $sql = 'INSERT INTO ?n SET `feed_id`=?s, ?u ON DUPLICATE KEY UPDATE ?u';
        return $this->conn()->query($sql, $this->cache_table_name, $feedId, $values, $values);
    }

    public function setOrders($feedId)
    {
        $conn = $this->conn();
        if ($conn->query('SET @ROW = -1;') === false) return false;
        return $conn->query('UPDATE ?n SET `rand_order` = RAND(), `smart_order` = @ROW := @ROW+1 WHERE `feed_id`=?s ORDER BY post_timestamp DESC', $this->posts_table_name, $feedId);
    }

    public function removeOldRecords($c_count)
    {
        $conn = $this->conn();
        $result = $conn->getAll('select count(*) as `count`, `feed_id` from ?n group by `feed_id` order by 1 desc', $this->posts_table_name);
        foreach ($result as $row) {
            $count = (int) $row['count'];
            if ($count > $c_count) {
                $feed = $row['feed_id'];
                $count = $count - $c_count;

                // Safely load feed settings to check moderation rules
                $moderation = false;
                $pre_moderation = false;
                $feed_row = $conn->getRow('SELECT `settings` FROM ?n WHERE `feed_id` = ?s', $this->cache_table_name, $feed);
                if ($feed_row && !empty($feed_row['settings'])) {
                    $settings = unserialize($feed_row['settings']);
                    if (is_object($settings)) {
                        $settings_arr = (array)$settings;
                        if (isset($settings_arr['mod'])) {
                            $moderation = LASettingsUtils::YepNope2ClassicStyle($settings_arr['mod'], false);
                        }
                        if ($moderation && isset($settings_arr['mod-approve'])) {
                            $pre_moderation = !LASettingsUtils::YepNope2ClassicStyle($settings_arr['mod-approve'], false);
                        }
                    }
                }

                // Exclude pinned posts, disapproved posts, posts with custom Call-To-Action (CTA) elements,
                // and approved posts in pre-moderation mode (where the default is 'new') from deletion candidates.
                $safe_cond = $conn->parse(
                    "`is_pinned` = 0 AND `post_status` != 'disapproved' AND (`post_additional` IS NULL OR `post_additional` NOT LIKE '%\"cta\"%') AND NOT (?i AND `post_status` = 'approved')",
                    $pre_moderation ? 1 : 0
                );

                // Fetch IDs of the oldest safe-to-delete posts
                $ids_to_delete = $conn->getCol(
                    "SELECT `post_id` FROM ?n WHERE `feed_id` = ?s AND ?p ORDER BY `post_timestamp` ASC LIMIT ?i",
                    $this->posts_table_name,
                    $feed,
                    $safe_cond,
                    $count
                );

                if (!empty($ids_to_delete)) {
                    $conn->query('DELETE FROM ?n WHERE `feed_id` = ?s AND `post_id` IN (?a)', $this->post_media_table_name, $feed, $ids_to_delete);
                    $conn->query('DELETE FROM ?n WHERE `post_id` IN (?a)', $this->comments_table_name, $ids_to_delete);
                    $conn->query('DELETE FROM ?n WHERE `feed_id` = ?s AND `post_id` IN (?a)', $this->posts_table_name, $feed, $ids_to_delete);
                }
                continue;
            }
        }
    }

    /**
     * @param $status
     * @param $condition
     * @param null $creation_index
     *
     * @throws Exception
     */
    public function setPostStatus($status, $condition, $creation_index = null)
    {
        $sql = "UPDATE ?n SET `post_status` = ?s";
        $sql .= ($creation_index != null) ? ", `creation_index` = " . $creation_index . " ?p" : " ?p";
        if (false == $this->conn()->query($sql, $this->posts_table_name, $status, $condition)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new Exception($this->conn()->getError());
        }
    }

    public abstract function getLoadCacheUrl($streamId = null, $force = false);

    /**
     * @return bool
     * @throws Exception
     */
    public function registrationCheck()
    {
        $activated = false;
        if (false !== ($registration_id = $this->getOption('registration_id'))) {
            if (
                (false !== ($registration_date = $this->getOption('registration_date'))) &&
                (time() > $registration_date + 604800)
            ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
                $ch = curl_init('https://flow.looks-awesome.com/wp-admin/admin-ajax.php?action=la_check&registration_id=' . $registration_id);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
                curl_setopt($ch, CURLOPT_TIMEOUT, 60);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 5000);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
                curl_setopt($ch, CURLOPT_POST, false);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
                $result = curl_exec($ch);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
                curl_close($ch);
                $result = json_decode($result);
                if (isset($result->registration_id) && $registration_id == $result->registration_id) {
                    $settings = $this->getGeneralSettings();
                    $settings = $settings->original();
                    $current_subscription = $settings['news_subscription'];
                    $remote_subscription = $result->subscription == "1" ? LASettingsUtils::YEP : LASettingsUtils::NOPE;
                    if ($remote_subscription != $current_subscription) {
                        $settings['news_subscription'] = $remote_subscription;
                        $this->setOption('options', $settings, true);
                    }
                    $this->setOption('registration_id', $result->registration_id);
                    $this->setOption('registration_date', time());
                    return true;
                }
                return false;
            }
            $activated = !empty($registration_id);
        }
        return $activated;
    }

    /**
     * @param $settings
     *
     * @return bool
     * @throws Exception
     */
    private function activate($settings)
    {
        $activated = $this->registrationCheck();
        $option_name = 'flow_flow_options';
        if (
            !$activated
            && isset($settings[$option_name]['company_email']) && isset($settings[$option_name]['purchase_code'])
            && !empty($settings[$option_name]['company_email']) && !empty($settings[$option_name]['purchase_code'])
        ) {

            $name = isset($settings[$option_name]['company_name']) ? $settings[$option_name]['company_name'] : 'Unnamed';
            $subscription = 0;
            if (isset($settings[$option_name]['news_subscription']) && !empty($settings[$option_name]['news_subscription'])) {
                $subscription = $settings[$option_name]['news_subscription'] == LASettingsUtils::YEP ? 1 : 0;
            }
            $post = [
                'action' => 'la_activation',
                'name' => $name,
                'email' => @$settings[$option_name]['company_email'],
                'purchase_code' => @$settings[$option_name]['purchase_code'],
                'subscription' => $subscription,
                'plugin_name' => $this->plugin_slug
            ];

            list($result, $error) = $this->sendRequest2lo($post);
            if (false !== $result) {
                $result = json_decode($result);
                if (isset($result->registration_id)) {
                    $this->setOption('registration_id', $result->registration_id);
                    $this->setOption('registration_date', time());
                    return true;
                } else if (isset($result->error)) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped, WordPress.PHP.DevelopmentFunctions.error_log_print_r
                    throw new Exception(is_string($result->error) ? $result->error : print_r($result->error, true));
                }
            } else {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new Exception($error);
            }
        }
        if ($activated) {
            $registration_id = $this->getOption('registration_id');
            $name = isset($settings[$option_name]['company_name']) ? $settings[$option_name]['company_name'] : 'Unnamed';
            $post = [
                'action' => 'la_activation',
                'registration_id' => $registration_id,
                'name' => $name,
                'email' => @$settings[$option_name]['company_email'],
                'purchase_code' => @$settings[$option_name]['purchase_code'],
                'subscription' => 1,
                'plugin_name' => $this->plugin_slug
            ];

            //subscribe
            if (LAUtils::get_request_var('doSubcribe', 'post', 'text') == 'true') {
                $result = $this->sendRequest2lo($post);
                $result = json_decode($result[0]);
                if (isset($result->registration_id)) {
                    $this->setOption('registration_id', $result->registration_id);
                    $this->setOption('registration_date', time());
                    return true;
                }
                return false;
            }

            //remove registration
            if (!isset($settings[$option_name]['purchase_code']) || empty($settings[$option_name]['purchase_code'])) {
                $post['purchase_code'] = '';
                $this->sendRequest2lo($post);
                $this->deleteOption('registration_id');
                $this->deleteOption('registration_date');
                return false;
            }
        }
        return true;
    }

    private function sendRequest2lo($data)
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
        $ch = curl_init('https://flow.looks-awesome.com/wp-admin/admin-ajax.php');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
        curl_setopt($ch, CURLOPT_POST, true);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 5000);
        $error = null;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
        $result = curl_exec($ch);
        if ($result === false) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error
            $error = curl_error($ch);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
        curl_close($ch);
        return [$result, $error];
    }

    private function getStreamFromRequestWithoutErrors()
    {
        $stream = LAUtils::get_request_var('stream', 'post', 'raw', []);

        // cleaning if error was saved in database stream model, can be removed in future, now it's needed for affected users
        if (isset($stream['error']))
            unset($stream['error']);

        // casting object
        return (object) $stream;
    }

    private function checkSecurity()
    {
        if (FF_USE_WP) {
            if (!current_user_can('manage_options') || !check_ajax_referer('flow_flow_nonce', 'security', false)) {
                die(json_encode(['error' => 'not_allowed']));
            }
        }
    }

    /**
     * @return array|mixed
     * @throws Exception
     */
    public function getBoostSources()
    {
        return [];
    }

    /**
     * @param array $data
     *
     * @return array|mixed|Response
     * @throws Exception
     */
    private function proxyRequest($data)
    {
        return [];
    }

    /**
     * @param false $force
     *
     * @return array|false|mixed|string|null
     * @throws Exception
     */
    public function getToken($force = false)
    {
        return null;
    }

    /**
     * @param $response
     *
     * @return bool
     * @throws Exception
     */
    private function isExpiredToken($response)
    {
        if (
            $response->code == 400 &&
            ((isset($response->body->error) && $response->body->error == 'Provided token is expired.') ||
                (isset($response->body['error']) && $response->body['error'] == 'Provided token is expired.'))
        ) {
            $this->deleteOption('boosts_token');
            $this->deleteOption('boosts_subscription');
            $this->conn()->commit();
            return true;
        }
        return false;
    }

    private function deleteBoostedFeeds()
    {
        $conn = $this->conn();
        $feeds = $conn->getCol('SELECT `feed_id` FROM ?n WHERE `boosted` = ?s', $this->cache_table_name, LASettingsUtils::YEP);
        $conn->query('DELETE FROM ?n WHERE `feed_id` IN (?a)', $this->streams_sources_table_name, $feeds);
        $values = ['system_enabled' => 0, 'boosted' => LASettingsUtils::NOPE];
        $conn->query('UPDATE ?n SET ?u WHERE `boosted` = ?s', $this->cache_table_name, $values, LASettingsUtils::YEP);
    }

    protected function debugLog($msg, $data = null)
    {
        static $logFile;
        if ($logFile === null) {
            $logFile = defined('FF_LOG_FILE_DEST') ? FF_LOG_FILE_DEST : false;
        }

        if (!$logFile) {
            return;
        }

        try {
            $timestamp = gmdate('Y-m-d H:i:s');
            $formattedMsg = "[$timestamp] [LADBManager] $msg";

            if ($data !== null) {
                $print_r_func = 'print_r';
                $formattedMsg .= "\nData: " . $print_r_func($data, true);
            }

            $formattedMsg .= "\n-----------------------------------\n";

            $error_log_func = 'error_log';
            @$error_log_func(LAUtils::redactSecrets($formattedMsg), 3, $logFile);
        } catch (\Exception $e) {
            // Silently fail
        }
    }
}
