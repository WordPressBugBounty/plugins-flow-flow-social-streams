<?php

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'FF_USE_WP' ) || FF_USE_WP ) {
		exit;
	}
}

// phpcs:disable
 use la\core\LAUtils;

if (!defined('WPINC'))
    die;
/**
 * Represents the view for the public-facing component of the plugin.
 *
 * This typically includes any information, if any, that is rendered to the
 * frontend of the theme when the plugin is activated.
 *
 * @var array $context
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>
 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
$moderation = $context['moderation'] && $context['can_moderate'];
$stream = $context['stream'];
if (FF_USE_WP)
    $admin = $moderation ? $moderation : function_exists('current_user_can') && current_user_can('manage_options');
else
    $admin = ff_user_can_moderate();
$id = $stream->id;
$domain = $context['domain'];
$public_url = ($context['boosted'] && isset($context['public_url']) && !empty($context['public_url'])) ? $context['public_url'] : $context['ajax_url'];
$hash = $context['hashOfStream'];
$seo = $context['seo'];
$disableCache = isset($_REQUEST['disable-cache']);
$page = isset($_REQUEST['page']) ? $_REQUEST['page'] : '0';

if (isset($context['ads_distrubution'])) {
    $ads = $context['ads_distrubution'];
}

$version = LAUtils::version($this->context);
$opts = LAUtils::dbm($this->context)->getOption('options', true);
$plugin_directory = $this->context['plugin_url'] . $this->context['plugin_dir_name'];
$js_opts = [
    'streams' => new stdClass(),
    'open_in_new' => isset($opts['general-settings-open-links-in-new-window']) ? $opts['general-settings-open-links-in-new-window'] : 'yep',
    'filter_all' => __('All', 'flow-flow-social-streams'),
    'filter_search' => __('Search', 'flow-flow-social-streams'),
    'expand_text' => __('Expand', 'flow-flow-social-streams'),
    'collapse_text' => __('Collapse', 'flow-flow-social-streams'),
    'posted_on' => __('Posted on', 'flow-flow-social-streams'),
    'followers' => __('Followers', 'flow-flow-social-streams'),
    'following' => __('Following', 'flow-flow-social-streams'),
    'posts' => __('Posts', 'flow-flow-social-streams'),
    'show_more' => __('Show more', 'flow-flow-social-streams'),
    'date_style' => isset($opts['general-settings-date-format']) ? $opts['general-settings-date-format'] : 'agoStyleDate',
    'dates' => [
        'Yesterday' => __('Yesterday', 'flow-flow-social-streams'),
        's' => __('s', 'flow-flow-social-streams'),
        'm' => __('m', 'flow-flow-social-streams'),
        'h' => __('h', 'flow-flow-social-streams'),
        'ago' => __('ago', 'flow-flow-social-streams'),
        'months' => [
            __('Jan', 'flow-flow-social-streams'),
            __('Feb', 'flow-flow-social-streams'),
            __('March', 'flow-flow-social-streams'),
            __('April', 'flow-flow-social-streams'),
            __('May', 'flow-flow-social-streams'),
            __('June', 'flow-flow-social-streams'),
            __('July', 'flow-flow-social-streams'),
            __('Aug', 'flow-flow-social-streams'),
            __('Sept', 'flow-flow-social-streams'),
            __('Oct', 'flow-flow-social-streams'),
            __('Nov', 'flow-flow-social-streams'),
            __('Dec', 'flow-flow-social-streams')
        ],
    ],
    'lightbox_navigate' => __('Navigate with arrow keys', 'flow-flow-social-streams'),
    'view_on' => __('View on', 'flow-flow-social-streams'),
    'view_on_site' => __('View on site', 'flow-flow-social-streams'),
    'view_all' => __('View all', 'flow-flow-social-streams'),
    'comments' => __('comments', 'flow-flow-social-streams'),
    'scroll' => __('Scroll for more', 'flow-flow-social-streams'),
    'no_comments' => __('No comments yet.', 'flow-flow-social-streams'),
    'check_comments' => __('Check all comments', 'flow-flow-social-streams'),
    'be_first' => __('Be the first!', 'flow-flow-social-streams'),
    'loading' => __('Loading', 'flow-flow-social-streams'),
    'server_time' => current_time('timestamp', (isset($context['boosted']) && $context['boosted'])),
    'forceHTTPS' => isset($opts['general-settings-https']) ? $opts['general-settings-https'] : 'nope',
    'isAdmin' => function_exists('current_user_can') && current_user_can('manage_options'),
    'ajaxurl' => $public_url,
    'isLog' => isset($_REQUEST['fflog']) && $_REQUEST['fflog'] == 1,
    'plugin_base' => $plugin_directory,
    'plugin_ver' => $this->context['version'],
    'domain' => $domain,
    'refresh_nonce' => wp_create_nonce('ff_refresh_feeds'),
    'engage_nonce' => wp_create_nonce('ff_engagement')
];
if ($admin) {
    $js_opts['flow_flow_nonce'] = wp_create_nonce('flow_flow_nonce');
}
$js_opts['token'] = ($admin && $moderation) ? $context['token'] : '';
?>
<!-- Flow-Flow — Social stream plugin for WordPress -->
<div class="ff-stream" data-plugin="flow_flow" id="ff-stream-<?php echo $id; ?>"><span class="ff-loader"><span
            class="ff-square"></span><span class="ff-square"></span><span class="ff-square ff-last"></span><span
            class="ff-square ff-clear"></span><span class="ff-square"></span><span
            class="ff-square ff-last"></span><span class="ff-square ff-clear"></span><span
            class="ff-square"></span><span class="ff-square ff-last"></span></span></div>
<svg aria-hidden="true" style="position: absolute; width: 0; height: 0; overflow: hidden;" version="1.1">
    <defs>
        <symbol id="ff-icon-heart" viewBox="0 0 48 48">
            <path
                d="M34.6 3.1c-4.5 0-7.9 1.8-10.6 5.6-2.7-3.7-6.1-5.5-10.6-5.5C6 3.1 0 9.6 0 17.6c0 7.3 5.4 12 10.6 16.5.6.5 1.3 1.1 1.9 1.7l2.3 2c4.4 3.9 6.6 5.9 7.6 6.5.5.3 1.1.5 1.6.5s1.1-.2 1.6-.5c1-.6 2.8-2.2 7.8-6.8l2-1.8c.7-.6 1.3-1.2 2-1.7C42.7 29.6 48 25 48 17.6c0-8-6-14.5-13.4-14.5z">
            </path>
        </symbol>
    </defs>
</svg>

<?php
// Prepare JS Config
$js_config = [
    'id' => $id,
    'hash' => $hash,
    'stream' => $stream,
    'ads' => isset($ads) ? $ads : false,
    'js_opts' => $js_opts,
    'domain' => $domain,
    'plugin_directory' => $plugin_directory,
    'plugin_base' => $plugin_directory, // used for extensions
    'version' => $version,
    'ajaxurl' => $public_url,
    'disableCache' => $disableCache,
    'page' => $page,
    'is_preview' => $stream->preview ? true : false,
    'boosted' => $context['boosted'] ? true : false,
    'moderation' => $moderation,
    'admin' => $admin,
    'dependencies' => apply_filters('ff_plugin_dependencies', [])
];
?>

<script>
    window.flow_flow_streams = window.flow_flow_streams || [];
    window.flow_flow_streams.push(<?php echo json_encode($js_config); ?>);
</script>

<?php 
// Only output stream-loader.js once per page to prevent the IIFE from re-processing
// the entire queue when multiple shortcodes are present.
if ( ! defined( 'FF_STREAM_LOADER_ENQUEUED' ) ) {
    define( 'FF_STREAM_LOADER_ENQUEUED', true );
    echo '<script src="' . $plugin_directory . '/js/stream-loader.js?ver=' . $version . '"></script>';
}
?>

<!-- Flow-Flow — Social streams plugin for Wordpress -->
<?php // phpcs:enable ?>
