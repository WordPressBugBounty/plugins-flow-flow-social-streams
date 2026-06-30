<?php
namespace flow;

use flow\db\FFDB;
use flow\db\FFDBManager;
use flow\db\FFDBMigrationManager;
use la\core\cache\LAFacebookCacheAdapter;
use la\core\db\LADB;
use la\core\db\LADDLUtils;
use la\core\LAActivatorBase;
use la\core\LAUtils;
use la\core\snapshots\LASnapshotManager;
use ReflectionException;
use flow\elementor\FlowFlowElementorWidget;
use wpdb;

class FlowFlowActivator extends LAActivatorBase
{
    const FORCE_CLEAN_INSTALL = false;

    /**
     * @throws ReflectionException
     */
    protected function checkPlugin()
    {
        /** @var FFDBManager $dbm */
        $dbm = $this->context['db_manager'];
        $conn = $dbm->conn();

        if (self::FORCE_CLEAN_INSTALL) {
            LADDLUtils::dropTable($conn, $dbm->posts_table_name);
            LADDLUtils::dropTable($conn, $dbm->cache_table_name);
            LADDLUtils::dropTable($conn, $dbm->streams_sources_table_name);
            if (isset($dbm->table_prefix)) {
                LADDLUtils::dropTable($conn, $dbm->table_prefix . 'snapshots');
            } else if (isset($this->context['table_name_prefix'])) {
                LADDLUtils::dropTable($conn, $this->context['table_name_prefix'] . 'snapshots');
            }
            
            if (isset($dbm->post_media_table_name)) LADDLUtils::dropTable($conn, $dbm->post_media_table_name);
            if (isset($dbm->comments_table_name)) LADDLUtils::dropTable($conn, $dbm->comments_table_name);
            if (isset($dbm->image_cache_table_name)) LADDLUtils::dropTable($conn, $dbm->image_cache_table_name);

            $dbm->deleteOption('db_version');
        }

        if (!LADDLUtils::existTable($conn, $dbm->posts_table_name) || !LADDLUtils::existTable($conn, $dbm->cache_table_name)) {
            if (LADDLUtils::existTable($conn, $dbm->option_table_name)) {
                $dbm->deleteOption('db_version');
            }
        }

        $mm = new FFDBMigrationManager($this->context);
        $mm->migrate();
        unset($mm);
    }

    /**
     * @param $file
     *
     * @return array
     */
    protected function initContext($file)
    {
        /** @var wpdb $wpdb */
        $wpdb = $GLOBALS['wpdb'];

        $context = [
            'root' => plugin_dir_path($file),
            'slug' => 'flow-flow-social-streams',
            'slug_down' => 'flow_flow',
            'plugin_url' => plugin_dir_url(dirname($file) . '/'),
            'plugin_dir_name' => basename(dirname($file)),
            'plugin_basename' => function_exists('plugin_basename') ? plugin_basename($file) : basename(dirname($file)) . '/' . basename($file),
            'admin_url' => admin_url('admin-ajax.php'),
            'table_name_prefix' => $wpdb->prefix . 'ff_',
            'version' => '5.0.2',
            'faq_url' => 'https://docs.social-streams.com/',
            'count_posts_4init' => 30
        ];
        $adapter = new LAFacebookCacheAdapter();
        $context['facebook_cache'] = $adapter;
        $context['db_manager'] = new FFDBManager($context);
        $adapter->setContext($context);
        return $context;
    }

    protected function checkEnvironment()
    {
        if (version_compare(PHP_VERSION, '5.6.0') == -1) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('<b>Flow-Flow Social Stream</b> plugin requires PHP version 5.6.0 or higher. Pls update your PHP version or ask hosting support to do this for you, you are using old and unsecure one');
        }

        if (!function_exists('curl_version')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('<b>Flow-Flow Social Stream</b> plugin requires curl extension for php. Please install/enable this extension or ask your hosting to help you with this.');
        }

        if (!function_exists('mysqli_connect')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('<b>Flow-Flow Social Stream</b> plugin requires mysqli extension for MySQL. Please install/enable this extension on your server or ask your hosting to help you with this. <a href="http://php.net/manual/en/mysqli.installation.php">Installation guide</a>');
        }
    }

    protected function singleSiteDeactivate()
    {
        wp_clear_scheduled_hook('flow_flow_load_cache');
        wp_clear_scheduled_hook('flow_flow_load_cache_4disabled');
        wp_clear_scheduled_hook('flow_flow_email_notification');
        wp_clear_scheduled_hook('flow_flow_check_facebook_token');
        wp_clear_scheduled_hook('flow_flow_check_tiktok_token');
        wp_clear_scheduled_hook('flow_flow_check_linkedin_token');
        wp_clear_scheduled_hook('flow_flow_trim_debug_log');
    }

    protected function beforePluginLoad()
    {
        add_action('elementor/widgets/register', [$this, 'initElementorIntegration']);

        parent::beforePluginLoad();

        try {
            do_action('flow_flow_addon_loaded', $this->context);
        } catch (\Error $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log($e->getMessage());
        }

        if (!defined('FF_AJAX_URL')) {
            $admin = function_exists('current_user_can') && current_user_can('manage_options');
            if (!$admin && defined('FF_ALTERNATE_GET_DATA') && FF_ALTERNATE_GET_DATA) {
                $this->setContextValue('ajax_url', plugins_url('ff.php', __FILE__));
            } else {
                $this->setContextValue('ajax_url', admin_url('admin-ajax.php'));
            }

            if (defined('FF_BOOST_SERVER') && !empty(FF_BOOST_SERVER)) {
                $this->setContextValue('public_url', FF_BOOST_SERVER . 'flow-flow/ff');
            }
        }

        /** @noinspection PhpExpressionResultUnusedInspection */
        new FlowFlowUpdater($this->context);
    }

    protected function registrationCronActions()
    {
        $this->registerCronActions();
    }

    protected function registerCronActions()
    {
        parent::registerCronActions();

        // Register lightweight callbacks that lazily resolve the instance when the cron actually runs
        add_action('flow_flow_load_cache', function () {
            FlowFlow::get_instance($this->context)->refreshCache();
        });

        add_action('flow_flow_load_cache_4disabled', function () {
            FlowFlow::get_instance($this->context)->refreshCache4Disabled();
        });

        add_action('flow_flow_email_notification', function () {
            FlowFlow::get_instance($this->context)->emailNotification();
        });

        add_action('flow_flow_check_facebook_token', function () {
            FlowFlow::get_instance($this->context)->checkFacebookToken();
        });

        add_action('flow_flow_check_tiktok_token', function () {
            FlowFlow::get_instance($this->context)->checkTikTokToken();
        });

        add_action('flow_flow_check_linkedin_token', function () {
            FlowFlow::get_instance($this->context)->checkLinkedInToken();
        });

        add_action('flow_flow_trim_debug_log', function () {
            $file = defined('FF_LOG_FILE_DEST') ? FF_LOG_FILE_DEST : plugin_dir_path(__DIR__) . 'flow-flow-debug.log';
            $maxSize = apply_filters('flow_flow_log_max_size_cron', 10 * 1024 * 1024); // 10 MB
            $keepSize = apply_filters('flow_flow_log_keep_size', 1 * 1024 * 1024); // 1 MB
            FFLogManager::trimLog($file, $maxSize, $keepSize);
        });

        // Schedule events during init, avoiding cron/ajax/cli contexts and respecting DISABLE_WP_CRON
        add_action('init', function () {
            if (
                (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON)
                || (function_exists('wp_doing_cron') && wp_doing_cron())
                || (defined('DOING_AJAX') && DOING_AJAX)
                || (defined('WP_CLI') && WP_CLI)
            ) {
                return;
            }

            $schedules = function_exists('wp_get_schedules') ? wp_get_schedules() : [];

            $cache_interval = apply_filters('flow_flow_cache_interval', isset($schedules['minute']) ? 'minute' : 'hourly');
            if (false == wp_next_scheduled('flow_flow_load_cache')) {
                wp_schedule_event(time() + 60, $cache_interval, 'flow_flow_load_cache');
            }

            $disabled_interval = isset($schedules['six_hours']) ? 'six_hours' : (isset($schedules['twicedaily']) ? 'twicedaily' : 'daily');
            if (false == wp_next_scheduled('flow_flow_load_cache_4disabled')) {
                wp_schedule_event(time() + 300, $disabled_interval, 'flow_flow_load_cache_4disabled');
            }

            if (false == wp_next_scheduled('flow_flow_email_notification')) {
                wp_schedule_event(time() + 300, 'daily', 'flow_flow_email_notification');
            }

            if (false == wp_next_scheduled('flow_flow_check_facebook_token')) {
                wp_schedule_event(time() + 300, 'daily', 'flow_flow_check_facebook_token');
            }

            $slug = LAUtils::slug($this->context);
            $is_lite = ($slug === 'flow-flow-lite' || $slug === 'flow-flow-social-streams');
            if (!$is_lite) {
                if (wp_get_schedule('flow_flow_check_tiktok_token') !== 'six_hours') {
                    wp_clear_scheduled_hook('flow_flow_check_tiktok_token');
                }
                if (false == wp_next_scheduled('flow_flow_check_tiktok_token')) {
                    wp_schedule_event(time() + 600, 'six_hours', 'flow_flow_check_tiktok_token');
                }
                // LinkedIn token check - daily since tokens last 60 days
                if (false == wp_next_scheduled('flow_flow_check_linkedin_token')) {
                    wp_schedule_event(time() + 700, 'daily', 'flow_flow_check_linkedin_token');
                }
            } else {
                wp_clear_scheduled_hook('flow_flow_check_tiktok_token');
                wp_clear_scheduled_hook('flow_flow_check_linkedin_token');
            }

            if (false == wp_next_scheduled('flow_flow_trim_debug_log')) {
                wp_schedule_event(time() + 86400, 'daily', 'flow_flow_trim_debug_log');
            }
        }, 20);
    }

    protected function registerShutdownActions()
    {
        add_action('shutdown', [$this, 'shutdownAction']);
    }

    /** @noinspection DuplicatedCode */
    protected function registrationAjaxActions()
    {
        $this->registerAjaxActions();
    }

    protected function registerAjaxActions()
    {
        $dbm = LAUtils::dbm($this->context);
        $slug_down = LAUtils::slug_down($this->context);
        $ff = FlowFlow::get_instance($this->context);

        // public endpoints
        add_action('wp_ajax_fetch_posts', [$ff, 'processAjaxRequest']);
        add_action('wp_ajax_nopriv_fetch_posts', [$ff, 'processAjaxRequest']);
        add_action('wp_ajax_ff_load_cache', [$ff, 'processAjaxRequestBackground']);
        add_action('wp_ajax_nopriv_ff_load_cache', [$ff, 'processAjaxRequestBackground']);
        add_action('wp_ajax_' . $slug_down . '_load_comments_and_carousel', [$ff, 'loadCommentsAndCarousel']);
        add_action('wp_ajax_nopriv_' . $slug_down . '_load_comments_and_carousel', [$ff, 'loadCommentsAndCarousel']);
        // Public feed refresh endpoint for expired media handling
        add_action('wp_ajax_' . $slug_down . '_refresh_feeds', [$dbm, 'refresh_feeds']);
        add_action('wp_ajax_nopriv_' . $slug_down . '_refresh_feeds', [$dbm, 'refresh_feeds']);
        // roles detect
        add_action('wp_ajax_' . $slug_down . '_moderation_apply_action', [$ff, 'moderation_apply']);

        // secured endpoints
        add_action('wp_ajax_' . $slug_down . '_sources', [$dbm, 'get_sources']);
        add_action('wp_ajax_' . $slug_down . '_social_auth', [$dbm, 'social_auth']);
        add_action('wp_ajax_' . $slug_down . '_fetch_facebook_user_info', [$dbm, 'fetch_facebook_user_info']);
        add_action('wp_ajax_' . $slug_down . '_fetch_tiktok_user_info', [$dbm, 'fetch_tiktok_user_info']);
        add_action('wp_ajax_' . $slug_down . '_save_sources_settings', [$dbm, 'save_sources_settings']);
        add_action('wp_ajax_' . $slug_down . '_get_stream_settings', [$dbm, 'get_stream_settings']);
        add_action('wp_ajax_' . $slug_down . '_get_shortcode_pages', [$dbm, 'get_shortcode_pages']);
        add_action('wp_ajax_' . $slug_down . '_ff_save_settings', [$dbm, 'ff_save_settings_fn']);
        add_action('wp_ajax_' . $slug_down . '_save_stream_settings', [$dbm, 'save_stream_settings']);
        add_action('wp_ajax_' . $slug_down . '_ai_generate_css', [$dbm, 'ajaxAiGenerateCss']);
        add_action('wp_ajax_' . $slug_down . '_create_stream', [$dbm, 'create_stream']);
        add_action('wp_ajax_' . $slug_down . '_clone_stream', [$dbm, 'clone_stream']);
        add_action('wp_ajax_' . $slug_down . '_delete_stream', [$dbm, 'delete_stream']);

        //boosts
        add_action('wp_ajax_' . $slug_down . '_get_boosts', [$dbm, 'get_boosts']);
        add_action('wp_ajax_' . $slug_down . '_payment_success', [$dbm, 'paymentSuccess']);
        add_action('wp_ajax_' . $slug_down . '_upgrade_subscription', [$dbm, 'upgradeSubscription']);
        add_action('wp_ajax_' . $slug_down . '_cancel_subscription', [$dbm, 'cancelSubscription']);
        add_action('wp_ajax_' . $slug_down . '_clear_subscription', [$dbm, 'clearSubscriptionCache']);

        // Status panel utilities: run cron now and get/clear debug log
        add_action('wp_ajax_' . $slug_down . '_cron_run', function () use ($slug_down) {
            check_ajax_referer('flow_flow_nonce', 'nonce');
            $hook = isset($_POST['hook']) ? sanitize_text_field(wp_unslash($_POST['hook'])) : '';
            $allowed = [
                'flow_flow_load_cache',
                'flow_flow_load_cache_4disabled',
                'flow_flow_email_notification',
                'flow_flow_check_facebook_token',
                'flow_flow_check_tiktok_token',
                'flow_flow_check_linkedin_token',
                'flow_flow_trim_debug_log',
            ];
            if (!in_array($hook, $allowed, true)) {
                wp_send_json_error(['message' => 'Invalid hook']);
            }
            // Execute immediately
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
            do_action($hook);
            wp_send_json_success(['message' => 'Executed ' . $hook]);
        });

        add_action('wp_ajax_' . $slug_down . '_debug_log', function () {
            check_ajax_referer('flow_flow_nonce', 'nonce');
            $action = isset($_POST['subaction']) ? sanitize_text_field(wp_unslash($_POST['subaction'])) : 'get';
            $file = defined('FF_LOG_FILE_DEST') ? FF_LOG_FILE_DEST : plugin_dir_path(__DIR__) . 'flow-flow-debug.log';

            if ('clear' === $action) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
                if (is_writable($file) || (!file_exists($file) && is_writable(dirname($file)))) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite
                    file_put_contents($file, '');
                    wp_send_json_success(['message' => 'Log cleared']);
                }
                wp_send_json_error(['message' => 'Log is not writable']);
            }

            // Emergency trim before reading (safety net if cron fails)
            $emergencyMax = apply_filters('flow_flow_log_max_size_emergency', 20 * 1024 * 1024); // 20 MB
            $keepSize = apply_filters('flow_flow_log_keep_size', 1 * 1024 * 1024); // 1 MB
            FFLogManager::trimLog($file, $emergencyMax, $keepSize);

            // 'get' default: return tail (~100KB) to avoid huge payloads
            $content = '';
            if (file_exists($file) && is_readable($file)) {
                $size = filesize($file);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
                $fp = fopen($file, 'r');
                if ($fp) {
                    $chunk = 102400; // 100 KB
                    if ($size > $chunk) {
                        fseek($fp, -$chunk, SEEK_END);
                    }
                    $content = stream_get_contents($fp);
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                    fclose($fp);
                }
            }
            wp_send_json_success(['content' => $content]);
        });

        new LASnapshotManager($this->context);

        // Ensure publishing actions are registered during AJAX requests
        new FFPublishingAdmin($this->context);

        if (!FF_USE_WP_CRON) {
            add_action('wp_ajax_' . $slug_down . '_refresh_cache', [$ff, 'refreshCache']);
            add_action('wp_ajax_nopriv_' . $slug_down . '_refresh_cache', [$ff, 'refreshCache']);
        }
    }

    protected function renderAdminSide()
    {
        /** @noinspection PhpExpressionResultUnusedInspection */
        new FlowFlowAdmin($this->context);

        // Publishing submenu page (under Social Apps)
        new FFPublishingAdmin($this->context);
    }

    protected function renderPublicSide()
    {
        $ff = FlowFlow::get_instance($this->context);
        add_action('init', [$ff, 'register_shortcodes']);
        add_action('init', [$ff, 'load_plugin_textdomain']);
        add_action('wp_enqueue_scripts', [$ff, 'enqueue_scripts']);
        add_action('wpmu_new_blog', [$ff, 'activate_new_site']);
    }

    public function afterPluginLoad()
    {
        parent::afterPluginLoad();
        
        // Engagement tracking (handles both AJAX endpoints and UI/Admin Bar)
        $engTracker = new FFEngagementTracker($this->context);
        $engTracker->register();
    }

    public function shutdownAction()
    {

        $error = error_get_last();

        if (is_null($error)) {
            return;
        }

        $fatals = [
            E_USER_ERROR => 'Fatal Error',
            E_ERROR => 'Fatal Error',
            E_PARSE => 'Parse Error',
            E_CORE_ERROR => 'Core Error',
            E_CORE_WARNING => 'Core Warning',
            E_COMPILE_ERROR => 'Compile Error',
            E_COMPILE_WARNING => 'Compile Warning'
        ];

        // check if error related to flow-flow
        if (strpos($error['file'], 'flow-flow-social-streams') !== false && isset($fatals[$error['type']])) {

            // error_log(print_r(debug_backtrace(), true));

            $msg = $fatals[$error['type']] . ': ' . $error['message'] . ' in ';
            $msg .= $error['file'] . ' on line ' . $error['line'] . PHP_EOL;

            if (!empty($msg)) {
                $error_log_func = 'error_log';
                $error_log_func($msg, 3, FF_LOG_FILE_DEST);
            }

        }

    }

    /**
     * Use this method fpr old php version
     * @deprecated
     */
    public function initWPWidget()
    {
        if (!defined('FF_ENABLE_WIDGET') || FF_ENABLE_WIDGET) {
            $widget = new FlowFlowWPWidget();
            $widget->setContext($this->context);
            register_widget($widget);
        }
    }

    /**
     * Use this method fpr old php version
     * @deprecated
     */
    public function initVCIntegration()
    {
        $dbm = LAUtils::dbm($this->context);

        //Important!
        //It will be execute before migrations!
        //Need to check exist tables and fields!
        $streams = [];
        if (LADDLUtils::existTable($dbm->conn(), $dbm->streams_table_name)) {
            $streams = LADB::streams($dbm->conn(), $dbm->streams_table_name);
        }

        $stream_options = [];
        if (sizeof($streams)) {
            foreach ($streams as $id => $stream) {
                $stream_options['Stream #' . $id . ($stream['name'] ? ' - ' . $stream['name'] : '')] = $id;
            }
        }

        /** @noinspection PhpUndefinedFunctionInspection */
        vc_map([
            "name" => __("Social Stream", 'flow-flow-social-streams'),
            'admin_enqueue_css' => [LAUtils::plugin_url($this->context) . '/css/admin-icon.css'],
            'front_enqueue_css' => [LAUtils::plugin_url($this->context) . '/css/admin-icon.css'],
            'icon' => 'streams-icon',
            "description" => __("Flow-Flow plugin social stream", 'flow-flow-social-streams'),
            "base" => "ff",
            "category" => __('Social', 'flow-flow-social-streams'),
            "weight" => 0,
            "params" => [
                [
                    'type' => 'dropdown',
                    'class' => '',
                    'admin_label' => true,
                    "holder" => "div",
                    "heading" => __("Choose stream to place on page:", 'flow-flow-social-streams'),
                    "description" => "Please create and edit stream on plugin's page in admin.",
                    "param_name" => "id",
                    "value" => $stream_options,
                    "std" => '--'
                ]
            ]
        ]);
    }
    public function initElementorIntegration($widgets_manager)
    {
        require_once plugin_dir_path(__FILE__) . 'elementor/FlowFlowElementorWidget.php';
        $widgets_manager->register(new FlowFlowElementorWidget());
    }
}