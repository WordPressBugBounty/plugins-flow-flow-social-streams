<?php
namespace la\core;
if (!defined('WPINC'))
    die;

use Exception;
use la\core\cache\LAFacebookCacheManager;
use la\core\db\LADBManager;

/**
 * FlowFlow.
 *
 * @property array | null $context
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>
 *
 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
abstract class LAAdminBase
{
    /** @var LADBManager $db */
    protected $db = null;
    protected $context = null;
    protected $plugin_slug = null;

    public function __construct($context)
    {
        $this->context = $context;
        $this->plugin_slug = LAUtils::slug($context);
        $this->db = LAUtils::dbm($context);

        // Load admin style sheet and JavaScript.
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

        // Add the options page and menu item.
        add_action('admin_menu', [$this, 'add_social_stream_admin_menu']);
        add_action('admin_menu', [$this, 'add_plugin_admin_menu']);
        add_action('admin_menu', [$this, 'remove_duplicate_submenu'], 999);

        $plugin_basename = isset($context['plugin_basename']) ? $context['plugin_basename'] : ($context['plugin_dir_name'] . '/' . LAUtils::slug($context) . '.php');
        add_filter('plugin_action_links_' . $plugin_basename, [$this, 'add_action_links']);

        foreach (['date_format', 'time_format', 'timezone_string', 'WPLANG'] as $option) {
            add_action("update_option_{$option}", [$this->db, 'update_wp_date_format_hook'], 10, 3);
        }
    }

    public function getPluginSlug()
    {
        return $this->plugin_slug;
    }

    /**
     * Register the administration menu for this plugin into the WordPress Dashboard menu.
     *
     * @since    1.0.0
     */
    public final function add_social_stream_admin_menu()
    {
        $this->addPluginAdminMenu([$this, 'display_plugin_admin_page']);
    }

    /**
     * Register the administration menu for this plugin into the WordPress Dashboard menu.
     *
     * @since    1.0.0
     */
    public final function add_plugin_admin_menu()
    {
        $this->addPluginAdminSubMenu([$this, 'display_plugin_admin_subpage']);
    }

    /**
     * Remove the duplicate "Social Apps" submenu item.
     *
     * @since    1.0.0
     */
    public final function remove_duplicate_submenu()
    {
        remove_submenu_page('flow-' . 'flow', 'flow-' . 'flow');
    }

    /**
     * Register and enqueue admin-specific style sheet and JavaScript.
     *
     * @param $hook
     *
     * @since     1.0.0
     */
    public final function enqueue_admin_scripts($hook)
    {
        $screen_id = 'social-apps_page_' . $this->getPluginSlug() . '-admin';
        $plugin_directory = $this->context['plugin_url'] . $this->context['plugin_dir_name'] . '/';
        $this->enqueueAdminStylesAlways($plugin_directory);
        $this->enqueueAdminScriptsAlways($plugin_directory);
        do_action('flow_flow_enqueue_admin_resources');

        if ($hook == 'toplevel_page_flow-' . 'flow') {
            $this->enqueueAdminStylesOnlyAtNewsPage($plugin_directory);
            $this->enqueueAdminScriptsOnlyAtNewsPage($plugin_directory);
        } else if ($screen_id == $hook) {
            $this->initPluginAdminPage();
            $this->enqueueAdminStylesOnlyAtPluginPage($plugin_directory);
            $this->enqueueAdminScriptsOnlyAtPluginPage($plugin_directory);
            do_action('flow_flow_enqueue_admin_resources_only_at_plugin_page');
        }
    }

    /**
     * Render the settings page for this plugin.
     *
     * @throws Exception
     * @since    1.0.0
     */
    public final function display_plugin_admin_page()
    {
        if (FF_USE_WP) {
            if (!current_user_can('manage_options')) {
                wp_die('You do not have sufficient permissions to access this page.');
            }
            $this->context['admin_page_title'] = esc_html(get_admin_page_title());
        } else {
            if (!isset($this->context['admin_page_title']))
                $this->context['admin_page_title'] = 'Flow-Flow - Social Streams Plugin';
        }
        $this->displayPluginAdminPage();
    }

    /**
     * Add settings action link to the plugins page.
     *
     * @since    1.0.0
     *
     * @param $links
     *
     * @return array
     */
    public final function add_action_links($links)
    {
        return array_merge($this->addActionLinks(), $links);
    }

    /**
     * Render the settings page for this plugin.
     * @throws Exception
     * @since 1.0.0
     */
    public final function display_plugin_admin_subpage()
    {
        $context = $this->contextForAdminPage();
        /** @noinspection PhpIncludeInspection */
        include_once($context['root'] . 'views/admin.php');
    }

    protected abstract function initPluginAdminPage();
    protected abstract function addPluginAdminSubMenu($displayAdminPageFunction);

    protected abstract function enqueueAdminStylesAlways($plugin_directory);
    protected abstract function enqueueAdminScriptsAlways($plugin_directory);
    protected function enqueueAdminStylesOnlyAtNewsPage($plugin_directory)
    {
        wp_enqueue_style('social-streams-news-styles', $plugin_directory . 'css/news.css', [], '1.0');
    }
    protected function enqueueAdminScriptsOnlyAtNewsPage($plugin_directory)
    {
        wp_enqueue_script('social-streams-news', $plugin_directory . 'js/news.js', ['jquery', 'underscore'], '1.0', false);
        wp_localize_script('social-streams-news', 'FFIADMIN', [
            'assets_url' => $this->context['plugin_url'] . '/' . $this->context['slug'],
            'plugins' => $this->getPluginsState(),
            'requirements' => [
                'php_status' => version_compare(phpversion(), '5.3', '>='),
                'php' => preg_replace("(-.+)", '', phpversion()),
                'wp_status' => (float) get_bloginfo('version') > 4,
                'wp' => get_bloginfo('version'),
                'memory_status' => preg_replace('/[^0-9]/', '', ini_get('memory_limit')) >= 32,
                'memory' => ini_get('memory_limit'),
                'upload_status' => preg_replace('/[^0-9]/', '', ini_get('upload_max_filesize')) >= 64,
                'upload' => ini_get('upload_max_filesize')
            ]
        ]);
    }
    protected abstract function enqueueAdminStylesOnlyAtPluginPage($plugin_directory);
    protected function enqueueAdminScriptsOnlyAtPluginPage($plugin_directory)
    {
        // Enqueue scripts
        wp_enqueue_script($this->getPluginSlug() . '-streams-script', $plugin_directory . 'js/streams.js', ['jquery'], LAUtils::version($this->context), false);
        wp_enqueue_script($this->getPluginSlug() . '-admin-script', $plugin_directory . 'js/admin.js', ['jquery', 'backbone', 'underscore'], LAUtils::version($this->context), false);
        wp_enqueue_script($this->getPluginSlug() . '-tinycolor', $plugin_directory . 'js/tinycolor.js', ['jquery'], LAUtils::version($this->context), false);
        wp_enqueue_script($this->getPluginSlug() . '-colorpickersliders', $plugin_directory . 'js/jquery.colorpickersliders.js', ['jquery'], LAUtils::version($this->context), false);

        wp_localize_script($this->getPluginSlug() . '-admin-script', 'WP_FF_admin', []);

        // old
//        wp_localize_script($this->getPluginSlug() . '-admin-script', '_nonce', wp_create_nonce('flow_flow_nonce'));
//        wp_localize_script($this->getPluginSlug() . '-admin-script', 'isWordpress', (string)FF_USE_WP);
//        wp_localize_script($this->getPluginSlug() . '-admin-script', '_ajaxurl', (string)$this->context['ajax_url']);//???
//        wp_localize_script($this->getPluginSlug() . '-admin-script', '_siteurl', site_url());
//        wp_localize_script($this->getPluginSlug() . '-admin-script', 'la_plugin_slug_down', LAUtils::slug_down($this->context));
        wp_localize_script($this->getPluginSlug() . '-admin-script', 'la_plugin', array('slug_down' => LAUtils::slug_down($this->context)));
    }

    protected function addActionLinks()
    {
        $links['settings'] = '<a href="' . admin_url('admin.php?page=' . $this->getPluginSlug() . '-admin') . '">' . 'Settings' . '</a>';
        $slug = LAUtils::slug($this->context);
        $is_lite = ($slug === 'flow-flow-lite' || $slug === 'flow-flow-social-streams');
        if ($is_lite) {
            $links['upgrade'] = '<a class="ff-upgrade-link" style="color:#3db634; font-weight:bold;" target="_blank" href="https://codecanyon.net/item/-flowflow-wordpress-social-stream-plugin/9319434?ref=looks_awesome&utm_source=Flow+Admin+Free&utm_medium=Get+Pro+Button&utm_campaign=Admin+Flow+Free+Get+Pro">' . 'Buy PRO' . '</a>';
        } else {
            $links['docs'] = '<a target="_blank" href="' . $this->context['faq_url'] . '">' . 'Docs' . '</a>';
        }
        return $links;
    }

    /**
     * States:
     * 0 - not installer
     * 1 - installed
     * 2 - activated
     */
    private function getPluginsState()
    {
        $plugins = [
            'flow-flow' => [
                'flow-flow/flow-flow.php',
                'flow-flow',
            ],
            'insta-flow' => [
                'insta-flow/insta-flow.php',
                'insta-flow-admin',
            ],
            'social-stacks' => [
                'social-stacks/social-stacks.php',
                'social-stacks-admin',
            ]
        ];

        $result = [];
        foreach ($plugins as $k => $v) {
            $state = 0;
            if (file_exists(WP_PLUGIN_DIR . '/' . $v[0])) {
                $state = 1;
            }
            if (is_plugin_active($v[0])) {
                $state = 2;
            }
            $result[$k] = [
                'state' => $state,
                'plugin_page_slug' => $v[1]
            ];
        }
        return $result;
    }

    private function addPluginAdminMenu($displayAdminPageFunction)
    {
        $plugin_directory = $this->context['plugin_url'] . $this->context['plugin_dir_name'];

        $wp_version = (float) get_bloginfo('version');
        if ($wp_version > 3.8) { // From 3.8 WP supports SVG icons
            $icon = $plugin_directory . '/assets/social-streams-icon.svg';
        } else {
            $icon = 'dashicons-networking';
        }

        if (empty($GLOBALS['admin_page_hooks']['flow-' . 'flow'])) {
            add_menu_page(
                'Social Apps',
                'Social Apps',
                'manage_options',
                'flow-' . 'flow',
                $displayAdminPageFunction,
                $icon
            );
        }
    }

    /**
     * @throws Exception
     */
    private function displayPluginAdminPage()
    {
        if (FF_USE_WP) {
            if (!current_user_can('manage_options')) {
                wp_die('You do not have sufficient permissions to access this page.');
            }
        }
        $context = $this->context;
        $slug = LAUtils::slug($this->context);
        $is_lite = ($slug === 'flow-flow-lite' || $slug === 'flow-flow-social-streams');
        $activated = $is_lite ? false : $this->db->registrationCheck();
        $this->db->dataInit();
        $context['activated'] = $activated;
        /** @noinspection PhpIncludeInspection */
        include_once(LAUtils::root($context) . 'views/news.php');
    }

    /**
     * @return array|null
     * @throws Exception
     */
    protected function contextForAdminPage()
    {
        $context = $this->context;
        $this->db->dataInit();

        /** @var LAFacebookCacheManager $facebookCache */
        $facebookCache = $context['facebook_cache'];
        $slug = LAUtils::slug($this->context);
        $is_lite = ($slug === 'flow-flow-lite' || $slug === 'flow-flow-social-streams');
        $context['activated'] = $is_lite ? false : $this->db->registrationCheck();
        $context['admin_page_title'] = esc_html(get_admin_page_title());
        $context['options'] = $this->db->getOption('options', true);
        $context['auth_options'] = $this->db->getOption('fb_auth_options', true);
        $context['extended_facebook_access_token'] = $facebookCache->getAccessToken();
        $context['extended_facebook_access_token_error'] = $facebookCache->getError();
        $context['streams'] = $this->db->streamsWithStatus();
        $context['sources'] = $this->db->sources();
        return $context;
    }
}
