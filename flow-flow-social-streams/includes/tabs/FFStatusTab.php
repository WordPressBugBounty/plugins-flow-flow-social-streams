<?php namespace flow\tabs;

use la\core\LAUtils;
use la\core\tabs\LATab;

if ( ! defined( 'WPINC' ) ) die;

class FFStatusTab implements LATab {
    public function __construct() {}

    public function id() {
        return 'status-tab';
    }

    public function flaticon() {
        return 'flaticon-cogwheel';
    }

    public function title() {
        return 'Status';
    }

    public function includeOnce($context) {
        $status = [];

        // Constants
        $status['constants'] = [
            'FF_USE_WP_CRON'   => defined('FF_USE_WP_CRON') ? (bool)FF_USE_WP_CRON : null,
            'DISABLE_WP_CRON'  => defined('DISABLE_WP_CRON') ? (bool)DISABLE_WP_CRON : null,
            'ALTERNATE_WP_CRON'=> defined('ALTERNATE_WP_CRON') ? (bool)ALTERNATE_WP_CRON : null,
        ];

        // Schedules available
        $schedules = function_exists('wp_get_schedules') ? wp_get_schedules() : [];
        $status['schedules'] = [];
        foreach ($schedules as $key => $sch) {
            $status['schedules'][] = [
                'key' => $key,
                'display' => isset($sch['display']) ? $sch['display'] : $key,
                'interval' => isset($sch['interval']) ? (int)$sch['interval'] : null,
            ];
        }

        // Target events to inspect
        $hooks = [
            'flow_flow_load_cache',
            'flow_flow_load_cache_4disabled',
            'flow_flow_email_notification',
            'flow_flow_check_facebook_token',
            'flow_flow_check_tiktok_token',
            'flow_flow_trim_debug_log',
            'flow_flow_cleanup_linkedin_images',
        ];

        $status['events'] = [];
        foreach ($hooks as $hook) {
            $next = wp_next_scheduled($hook);
            $sched = function_exists('wp_get_schedule') ? wp_get_schedule($hook) : false;
            $status['events'][] = [
                'hook' => $hook,
                'next' => $next ?: null,
                'schedule' => $sched ?: null,
            ];
        }

        // Loopback check (best-effort)
        $status['loopback_ok'] = null;
        if (function_exists('wp_remote_post')) {
            $resp = wp_remote_post(site_url('wp-cron.php'), [
                'timeout' => 3,
                'blocking' => false,
                'sslverify' => false,
            ]);
            $status['loopback_ok'] = !is_wp_error($resp);
        }

        // LinkedIn Images API backoff status
        // Same transients FFLinkedIn writes: the backoff end timestamp and its escalation level
        $backoff_ts = function_exists('get_transient') ? get_transient('ff_li_img_backoff_until') : false;
        $is_backoff_active = is_numeric($backoff_ts) && (int)$backoff_ts > time();
        $backoff_remaining = $is_backoff_active ? (int)$backoff_ts - time() : 0;
        $backoff_count = function_exists('get_transient') ? (int) get_transient('ff_li_img_backoff_level') : 0;
        $status['linkedin_backoff'] = [
            'active'       => $is_backoff_active,
            'expires_at'   => $is_backoff_active ? (int)$backoff_ts : null,
            'remaining'    => $backoff_remaining,
            'retry_count'  => $backoff_count,
        ];

        $context['status'] = $status;

        /** @noinspection PhpIncludeInspection */
        include_once(LAUtils::root($context) . 'views/status.php');
    }
}
