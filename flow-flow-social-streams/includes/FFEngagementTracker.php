<?php
// phpcs:disable
namespace flow;

if (!defined('WPINC')) die;

use la\core\LAUtils;

/**
 * Tracks visitor engagement metrics for stream posts.
 *
 * Metrics tracked (stored in post_additional JSON under "engagement" key):
 *  - lightbox_opens:  user opened the lightbox/slideshow
 *  - outbound_clicks: user clicked an outbound link (permalink)
 *  - profile_visits:  user clicked the author username / profile link
 *
 * Also renders an admin-bar notification with a Pinterest-style engagement
 * summary lightbox (top 20 posts by total engagement).
 *
 * @package FlowFlow
 */
class FFEngagementTracker
{
    private $context;

    public function __construct(array $context)
    {
        $this->context = $context;
    }

    public function register()
    {
        // Public tracking endpoint
        add_action('wp_ajax_ff_track_engagement', [$this, 'handleRequest']);
        add_action('wp_ajax_nopriv_ff_track_engagement', [$this, 'handleRequest']);

        // Admin: top posts endpoint
        add_action('wp_ajax_ff_engagement_top_posts', [$this, 'handleTopPosts']);
        add_action('wp_ajax_ff_engagement_mark_seen', [$this, 'handleMarkSeen']);
        add_action('wp_ajax_ff_engagement_reset', [$this, 'handleReset']);
        add_action('wp_ajax_ff_engagement_ai_summary', [$this, 'handleAiSummary']);

        // Admin bar + assets (fires on both admin and front-end for admins)
        add_action('admin_bar_menu', [$this, 'adminBarNode'], 999);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAdminAssets']);
    }

    // ───────────── Public tracking endpoint ─────────────

    public function handleRequest()
    {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE || empty($payload['events'])) {
            $payload = $_POST;
        }

        $nonce = isset($payload['_nonce']) ? $payload['_nonce'] : (isset($_REQUEST['_nonce']) ? $_REQUEST['_nonce'] : '');
        if (!wp_verify_nonce($nonce, 'ff_engagement')) {
            wp_send_json_error(['message' => 'Invalid nonce'], 403);
            return;
        }

        // Rate limit
        $ip = $this->getClientIP();
        $transient_key = 'ff_eng_' . md5($ip);
        $count = (int) get_transient($transient_key);
        if ($count >= 30) {
            wp_send_json_error(['message' => 'Rate limited'], 429);
            return;
        }
        set_transient($transient_key, $count + 1, 60);

        $events = isset($payload['events']) ? $payload['events'] : [];
        if (!is_array($events) || count($events) === 0) {
            wp_send_json_error(['message' => 'No events'], 400);
            return;
        }

        $events = array_slice($events, 0, 200);
        $allowedTypes = ['lightbox_open', 'outbound_click', 'profile_visit', 'post_view'];

        $aggregated = [];
        foreach ($events as $evt) {
            $postId = isset($evt['post_id']) ? sanitize_text_field($evt['post_id']) : '';
            $feedId = isset($evt['feed_id']) ? sanitize_text_field($evt['feed_id']) : '';
            $type   = isset($evt['type'])    ? sanitize_text_field($evt['type'])    : '';

            if ($postId === '' || $feedId === '' || !in_array($type, $allowedTypes, true)) continue;

            $key = $feedId . '|' . $postId;
            if (!isset($aggregated[$key])) {
                $aggregated[$key] = ['feed_id' => $feedId, 'post_id' => $postId, 'counts' => []];
            }
            if (!isset($aggregated[$key]['counts'][$type])) {
                $aggregated[$key]['counts'][$type] = 0;
            }
            $aggregated[$key]['counts'][$type]++;
        }

        if (empty($aggregated)) {
            wp_send_json_success(['updated' => 0]);
            return;
        }

        $dbm = LAUtils::dbm($this->context);
        $conn = $dbm->conn(true);
        $postsTable = $dbm->posts_table_name;
        $updated = 0;

        $typeMap = [
            'lightbox_open'  => 'lightbox_opens',
            'outbound_click' => 'outbound_clicks',
            'profile_visit'  => 'profile_visits',
            'post_view'      => 'post_views',
        ];

        // Optimized batch processing:
        // 1. Fetch all existing engagement data in one query
        $whereClauses = [];
        $params = [];
        foreach ($aggregated as $item) {
            $whereClauses[] = '(post_id = ?s AND feed_id = ?s)';
            $params[] = $item['post_id'];
            $params[] = $item['feed_id'];
        }

        $sql = 'SELECT `post_id`, `feed_id`, `post_additional` FROM ?n WHERE ' . implode(' OR ', $whereClauses);
        array_unshift($params, $postsTable);
        
        try {
            $existingRows = call_user_func_array([$conn, 'getAll'], array_merge([$sql], $params));
            $rowMap = [];
            foreach ($existingRows as $r) $rowMap[$r['feed_id'] . '|' . $r['post_id']] = $r['post_additional'];

            // 2. Start transaction for atomic updates
            $conn->beginTransaction();

            foreach ($aggregated as $key => $item) {
                if (!isset($rowMap[$key])) continue;

                $additional = !empty($rowMap[$key]) ? json_decode($rowMap[$key], true) : [];
                if (!is_array($additional)) $additional = [];

                if (!isset($additional['engagement']) || !is_array($additional['engagement'])) {
                    $additional['engagement'] = [
                        'lightbox_opens' => 0, 
                        'outbound_clicks' => 0, 
                        'profile_visits' => 0,
                        'post_views' => 0
                    ];
                }

                foreach ($item['counts'] as $type => $delta) {
                    $engKey = isset($typeMap[$type]) ? $typeMap[$type] : null;
                    if ($engKey) {
                        $additional['engagement'][$engKey] =
                            (int) (isset($additional['engagement'][$engKey]) ? $additional['engagement'][$engKey] : 0) + $delta;
                    }
                }

                $conn->query(
                    'UPDATE ?n SET `post_additional` = ?s WHERE `post_id` = ?s AND `feed_id` = ?s',
                    $postsTable, json_encode($additional), $item['post_id'], $item['feed_id']
                );
                $updated++;
            }

            $conn->commit();
        } catch (\Throwable $e) {
            if (isset($conn)) $conn->rollback();
            // silently continue or log if needed
        }

        if ($updated > 0) {
            // Increment persistent totals in the plugin's own options table
            try {
                $currentTotals = $dbm->getOption('engagement_totals', true);
                if (!is_array($currentTotals)) {
                    // First time or corrupted — seed from table scan
                    $currentTotals = $this->scanPostsForTotals($dbm);
                }
                foreach ($aggregated as $item) {
                    if (!isset($rowMap[$item['feed_id'] . '|' . $item['post_id']])) continue;
                    foreach ($item['counts'] as $type => $delta) {
                        $engKey = isset($typeMap[$type]) ? $typeMap[$type] : null;
                        if ($engKey) {
                            $currentTotals[$engKey] = (isset($currentTotals[$engKey]) ? (int)$currentTotals[$engKey] : 0) + $delta;
                            $currentTotals['total'] = (isset($currentTotals['total']) ? (int)$currentTotals['total'] : 0) + $delta;
                            if ($engKey !== 'post_views') {
                                $currentTotals['total_no_views'] = (isset($currentTotals['total_no_views']) ? (int)$currentTotals['total_no_views'] : 0) + $delta;
                            }
                        }
                    }
                }
                $dbm->setOption('engagement_totals', $currentTotals, true);
            } catch (\Throwable $e) {
                // Non-critical — totals will self-heal on next getOverallStats call
                error_log('Flow-Flow engagement totals update failed: ' . $e->getMessage());
            }
            delete_transient('ff_engagement_total_stats');
        }

        wp_send_json_success(['updated' => $updated]);
    }

    // ───────────── Admin bar ─────────────

    /**
     * Get overall engagement stats across all posts (cached 10 min).
     * Always computes from actual per-post data as the single source of truth.
     *
     * @param bool $fresh  When true, skip the transient cache and compute from DB.
     */
    private function getOverallStats($fresh = false)
    {
        if (!$fresh) {
            $cached = get_transient('ff_engagement_total_stats');
            if ($cached !== false && is_array($cached)) return $cached;
        }

        try {
            $dbm = LAUtils::dbm($this->context);
            // Always scan posts — they are the source of truth
            $totals = $this->scanPostsForTotals($dbm);
            $dbm->setOption('engagement_totals', $totals, true);

            set_transient('ff_engagement_total_stats', $totals, 600); // 10 min
            return $totals;
        } catch (\Throwable $e) {
            return $this->emptyTotals();
        }
    }

    /**
     * Scan all posts for engagement data and compute totals.
     * Used for one-time migration and as a fallback.
     */
    private function scanPostsForTotals($dbm)
    {
        $totals = $this->emptyTotals();
        try {
            $conn = $dbm->conn(true);
            $rows = $conn->getAll(
                'SELECT `post_additional` FROM ?n WHERE `post_additional` LIKE ?s',
                $dbm->posts_table_name, '%engagement%'
            );
            foreach ($rows as $row) {
                $add = json_decode($row['post_additional'], true);
                if (isset($add['engagement']) && is_array($add['engagement'])) {
                    foreach ($add['engagement'] as $k => $v) {
                        if (isset($totals[$k])) {
                            $totals[$k] += (int) $v;
                        }
                        $totals['total'] += (int) $v;
                        if ($k !== 'post_views') {
                            $totals['total_no_views'] += (int) $v;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // return whatever we have
        }
        return $totals;
    }

    private function emptyTotals()
    {
        return [
            'total' => 0,
            'total_no_views' => 0,
            'lightbox_opens' => 0,
            'outbound_clicks' => 0,
            'profile_visits' => 0,
            'post_views' => 0,
        ];
    }

    /**
     * Admin bar node.
     */
    public function adminBarNode($wp_admin_bar)
    {
        if (!current_user_can('manage_options')) return;

        $stats = $this->getOverallStats();
        $total_no_views = isset($stats['total_no_views']) ? (int) $stats['total_no_views'] : 0;

        $user_id = get_current_user_id();
        $last_seen = (int) get_user_meta($user_id, 'ff_engagement_last_seen', true);

        $new_events = max(0, $total_no_views - $last_seen);
        
        $badge = $new_events > 0 ? '<span class="ff-engage-badge">' . number_format($new_events) . '</span>' : '';

        $wp_admin_bar->add_node([
            'id'    => 'ff-engagement',
            'title' => '<span class="ab-icon dashicons dashicons-chart-bar"></span><span class="ab-label">Social Feed</span>' . $badge,
            'href'  => '#',
            'meta'  => [
                'class' => 'ff-engage-admin-bar',
                'title' => 'Flow-Flow Engagement',
            ],
        ]);
    }

    // ───────────── Top posts AJAX (admin only) ─────────────

    private function getTopPostsList()
    {
        $dbm = LAUtils::dbm($this->context);
        $conn = $dbm->conn(true);

        $useAlt = defined('FF_ALTERNATIVE_POST_STORAGE') && FF_ALTERNATIVE_POST_STORAGE;
        $textCol = $useAlt ? 'post_content' : 'post_header`, `user_screenname';

        $rows = $conn->getAll(
            'SELECT `post_id`, `feed_id`, `post_type`, `post_additional`, `image_url`, '
            . '`user_pic`, `user_nickname`, `post_permalink`, `post_text`, `' . $textCol . '` '
            . 'FROM ?n WHERE `post_additional` LIKE ?s ORDER BY `post_timestamp` DESC LIMIT 500',
            $dbm->posts_table_name, '%engagement%'
        );

        // Score & sort
        $posts = [];
        foreach ($rows as $row) {
            $add = json_decode($row['post_additional'], true);
            if (!isset($add['engagement']) || !is_array($add['engagement'])) continue;

            $eng = $add['engagement'];
            $score = 0;
            foreach ($eng as $v) $score += (int) $v;
            if ($score === 0) continue;

            // Extract title and screenname
            $title = '';
            $screenname = isset($row['user_screenname']) ? $row['user_screenname'] : '';
            if ($useAlt && !empty($row['post_content'])) {
                $pc = json_decode($row['post_content'], true);
                $title = isset($pc['post_header']) ? $pc['post_header'] : '';
                if (isset($pc['user_screenname'])) {
                    $screenname = $pc['user_screenname'];
                }
            } else {
                $title = isset($row['post_header']) ? $row['post_header'] : '';
            }

            $nickname = $row['user_nickname'];
            if (empty($nickname)) {
                $nickname = $screenname;
            }

            $posts[] = [
                'post_id'     => $row['post_id'],
                'feed_id'     => $row['feed_id'],
                'type'        => $row['post_type'],
                'title'       => wp_strip_all_tags(stripslashes($title)),
                'description' => wp_strip_all_tags(stripslashes(isset($row['post_text']) ? $row['post_text'] : '')),
                'image'       => $row['image_url'],
                'userpic'     => $row['user_pic'],
                'nickname'    => $nickname,
                'permalink'   => $row['post_permalink'],
                'engagement'  => [
                    'lightbox_opens'  => (int) (isset($eng['lightbox_opens'])  ? $eng['lightbox_opens']  : 0),
                    'outbound_clicks' => (int) (isset($eng['outbound_clicks']) ? $eng['outbound_clicks'] : 0),
                    'profile_visits'  => (int) (isset($eng['profile_visits'])  ? $eng['profile_visits']  : 0),
                    'post_views'      => (int) (isset($eng['post_views'])      ? $eng['post_views']      : 0),
                    'total'           => $score,
                ],
            ];
        }

        // Sort by total desc, take top 20
        usort($posts, function ($a, $b) {
            return $b['engagement']['total'] - $a['engagement']['total'];
        });
        return array_slice($posts, 0, 20);
    }

    public function handleTopPosts()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
            return;
        }

        check_ajax_referer('ff_engagement_admin', '_nonce');

        try {
            $posts = $this->getTopPostsList();
            $stats = $this->getOverallStats();
            wp_send_json_success(['posts' => $posts, 'overall' => $stats]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }
    }

    public function handleAiSummary()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
            return;
        }

        check_ajax_referer('ff_engagement_admin', '_nonce');

        if (!function_exists('wp_ai_client_prompt')) {
            wp_send_json_error(['message' => 'AI Client is not available. Requires WordPress 7.0+.']);
            return;
        }

        try {
            $posts = $this->getTopPostsList();
            $overall = $this->getOverallStats();

            if (empty($posts)) {
                wp_send_json_success(['summary' => '<p>No sufficient engagement data available yet to generate an AI summary. Keep driving traffic to your social feeds!</p>']);
                return;
            }

            // Build structured data for prompt
            $postsStr = "";
            foreach ($posts as $idx => $p) {
                $rank = $idx + 1;
                $title = !empty($p['title']) ? $p['title'] : (!empty($p['description']) ? mb_substr($p['description'], 0, 60) . '...' : '(No Title/Content)');
                $type = $p['type'];
                $e = $p['engagement'];
                $postsStr .= "#{$rank} [Network: {$type}] \"{$title}\"\n";
                $postsStr .= "   - Views: {$e['post_views']}, Lightbox Opens: {$e['lightbox_opens']}, Outbound Clicks: {$e['outbound_clicks']}, Profile Visits: {$e['profile_visits']} (Total score: {$e['total']})\n\n";
            }

            $overallStr = "Total Engagement Events: {$overall['total']}\n";
            $overallStr .= "Overall Views: {$overall['post_views']}\n";
            $overallStr .= "Overall Lightbox Opens: {$overall['lightbox_opens']}\n";
            $overallStr .= "Overall Outbound Clicks: {$overall['outbound_clicks']}\n";
            $overallStr .= "Overall Profile Visits: {$overall['profile_visits']}\n";

            $chunk = isset($_POST['chunk']) ? sanitize_key($_POST['chunk']) : 'analysis';

            if ($chunk === 'recommendations') {
                $full_prompt = "Here is the visitor engagement metrics for our top social media posts:\n\n"
                    . "Overall Stats:\n" . $overallStr . "\n"
                    . "Top Engaging Posts:\n" . $postsStr . "\n"
                    . "Generate specific, actionable recommendations to boost engagement and conversions based on these metrics.";

                $system = "You are a professional social media content strategist and data analyst. "
                    . "Analyze the provided post engagement metrics and write a stunning, premium, and actionable set of recommendations (practical steps to increase views, clicks, lightboxes, and profile visits). "
                    . "Format your output strictly in clean HTML using tags like <h4>, <h5>, <p>, <ul>, and <li>. Do NOT use markdown (like **, ### or `), and do NOT include <html> or <body> tags. Use elegant phrasing, keeping paragraphs concise and bullet points impact-focused. Do not mention that this data is simulated or fake; treat it as real, live analytics data. Do not include any performance analysis or summary in this response.";
            } else {
                $full_prompt = "Here is the visitor engagement metrics for our top social media posts:\n\n"
                    . "Overall Stats:\n" . $overallStr . "\n"
                    . "Top Engaging Posts:\n" . $postsStr . "\n"
                    . "Generate a professional, concise, and actionable AI analysis of these posts' performance, highlighting which networks or content types perform best.";

                $system = "You are a professional social media content strategist and data analyst. "
                    . "Analyze the provided post engagement metrics and write a stunning, premium, and actionable performance analysis (what stands out, which networks, content types, or specific posts are winning, and why). "
                    . "Format your output strictly in clean HTML using tags like <h4>, <h5>, <p>, <ul>, and <li>. Do NOT use markdown (like **, ### or `), and do NOT include <html> or <body> tags. Use elegant phrasing, keeping paragraphs concise and bullet points impact-focused. Do not mention that this data is simulated or fake; treat it as real, live analytics data. Do not include any recommendations in this response.";
            }

            $builder = call_user_func('wp_ai_client_prompt', $full_prompt)
                ->using_system_instruction($system)
                ->using_temperature(0.7)
                ->using_max_tokens(1500);

            $summary = $builder->generate_text();
            if (is_wp_error($summary)) {
                wp_send_json_error(['message' => $summary->get_error_message()]);
                return;
            }

            wp_send_json_success(['summary' => $summary]);
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => 'AI Summary generation failed: ' . $e->getMessage()], 500);
        }
    }

    public function handleMarkSeen()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
            return;
        }

        check_ajax_referer('ff_engagement_admin', '_nonce');

        $stats = $this->getOverallStats();
        $total_no_views = isset($stats['total_no_views']) ? (int) $stats['total_no_views'] : 0;
        update_user_meta(get_current_user_id(), 'ff_engagement_last_seen', $total_no_views);
        wp_send_json_success();
    }

    public function handleReset()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
            return;
        }

        check_ajax_referer('ff_engagement_admin', '_nonce');

        try {
            $dbm = LAUtils::dbm($this->context);

            // 1. Reset persistent totals in the plugin options table
            $dbm->setOption('engagement_totals', $this->emptyTotals(), true);
            delete_transient('ff_engagement_total_stats');

            // 2. Reset badge counter for current user
            update_user_meta(get_current_user_id(), 'ff_engagement_last_seen', 0);

            // 3. Clear per-post engagement data from the posts table
            $conn = $dbm->conn(true);
            $rows = $conn->getAll(
                'SELECT `post_id`, `feed_id`, `post_additional` FROM ?n WHERE `post_additional` LIKE ?s',
                $dbm->posts_table_name, '%engagement%'
            );

            if (!empty($rows)) {
                $conn->beginTransaction();
                foreach ($rows as $row) {
                    $additional = json_decode($row['post_additional'], true);
                    if (isset($additional['engagement'])) {
                        unset($additional['engagement']);
                        $conn->query(
                            'UPDATE ?n SET `post_additional` = ?s WHERE `post_id` = ?s AND `feed_id` = ?s',
                            $dbm->posts_table_name, json_encode($additional), $row['post_id'], $row['feed_id']
                        );
                    }
                }
                $conn->commit();
            }

            wp_send_json_success();
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 500);
        }
    }

    // ───────────── Admin assets (inline CSS + JS) ─────────────

    public function enqueueAdminAssets()
    {
        if (!current_user_can('manage_options')) return;

        if (!is_admin() && (!function_exists('is_admin_bar_showing') || !is_admin_bar_showing())) {
            return;
        }

        $nonce = wp_create_nonce('ff_engagement_admin');
        $ajaxurl = admin_url('admin-ajax.php');

        // Inline CSS
        $css = $this->getAdminCSS();
        wp_register_style('ff-engage-admin', false);
        wp_enqueue_style('ff-engage-admin');
        wp_add_inline_style('ff-engage-admin', $css);

        $ai_available = function_exists('wp_ai_client_prompt');

        // Inline JS
        $js = $this->getAdminJS($ajaxurl, $nonce, $ai_available);
        wp_register_script('ff-engage-admin', false);
        wp_enqueue_script('ff-engage-admin');
        wp_add_inline_script('ff-engage-admin', $js);
    }

    private function getAdminCSS()
    {
        return <<<'CSS'
/* Admin bar */
#wp-admin-bar-ff-engagement .ab-icon.dashicons { font-family: dashicons !important; font-size: 20px; line-height: 1; position: relative; top: 3px; }
#wp-admin-bar-ff-engagement .ff-engage-badge { display: inline-block; background: #ef4444; color: #fff; font-size: 9px; font-weight: 800; line-height: 1; padding: 2px 6px; border-radius: 3px; margin-left: 6px; vertical-align: middle; text-transform: uppercase; letter-spacing: 0.6px; }

.ff-beta-label {
    display: inline-block;
    font-size: 9px;
    font-weight: 800;
    text-transform: uppercase;
    color: #818cf8;
    padding: 2px 6px;
    border-radius: 4px;
    margin-left: 8px;
    vertical-align: middle;
    letter-spacing: 0.5px;
    line-height: 1;
    border: 1px solid rgba(129, 140, 248, 0.3);
    position: relative;
    top: -8px;
}

/* Lightbox overlay */
.ff-engage-overlay { display:none; position:fixed; inset:0; z-index:999999; background:rgba(10,10,12,.7); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); overflow-y:auto; }
.ff-engage-overlay.ff-open { display:flex; justify-content:center; align-items:flex-start; padding:40px 16px; }

/* Modal */
.ff-engage-modal { 
    background: rgba(20, 20, 24, 0.9); 
    backdrop-filter: blur(25px);
    -webkit-backdrop-filter: blur(25px);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 16px; 
    width: 100%; 
    max-width: 680px; 
    box-shadow: 0 30px 80px rgba(0,0,0,.5); 
    color: #e3e4e8;
    font-family: 'Outfit', sans-serif;
    animation: ffEngFadeIn .25s ease; 
}
@keyframes ffEngFadeIn { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }

.ff-engage-modal-header { display:flex; align-items:center; justify-content:space-between; padding:20px 24px 12px; border-bottom:1px solid rgba(255,255,255,0.06); }
.ff-engage-modal-header h3 { 
    margin: 0; 
    font-size: 18px; 
    font-weight: 600; 
    background: linear-gradient(135deg, #ffffff 30%, #a5b4fc 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    letter-spacing: -0.3px;
}
.ff-engage-header-actions { display:flex; align-items:center; gap:8px; }
.ff-engage-sort-wrap { display:flex; align-items:center; gap:8px; }
.ff-engage-sort-wrap label { font-size:11px; font-weight:600; color:#9ca3af; text-transform:uppercase; letter-spacing:.5px; white-space:nowrap; }
.ff-engage-sort-wrap select { 
    appearance: none; 
    -webkit-appearance: none; 
    background: rgba(255,255,255,0.06) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%239ca3af'/%3E%3C/svg%3E") no-repeat right 10px center; 
    border: 1px solid rgba(255,255,255,0.08); 
    border-radius: 6px; 
    padding: 6px 28px 6px 12px; 
    font-size: 11px; 
    font-weight: 700; 
    color: #e3e4e8; 
    cursor: pointer; 
    transition: all .15s; 
    outline: none; 
}
.ff-engage-sort-wrap select:hover { background-color: rgba(255,255,255,0.1); border-color: rgba(255,255,255,0.15); }
.ff-engage-sort-wrap select:focus { box-shadow: 0 0 0 2px rgba(129,140,248,.3); }

.ff-engage-reset { 
    background: rgba(255,255,255,0.06); 
    border: 1px solid rgba(255,255,255,0.08); 
    border-radius: 6px; 
    padding: 6px 14px; 
    font-size: 11px; 
    font-weight: 700; 
    color: #cbd5e1; 
    cursor: pointer; 
    transition: all .15s; 
}
.ff-engage-reset:hover { background: rgba(239, 68, 68, 0.15); border-color: rgba(239, 68, 68, 0.3); color: #ef4444; }

.ff-engage-close { 
    cursor: pointer; 
    width: 32px; 
    height: 32px; 
    padding: 0; 
    border-radius: 50%; 
    border: 1px solid rgba(255,255,255,0.08); 
    background: rgba(255,255,255,0.05); 
    font-size: 18px; 
    line-height: 1; 
    display: inline-flex; 
    align-items: center; 
    justify-content: center; 
    color: #9ca3af; 
    transition: all .15s; 
}
.ff-engage-close:hover { background: rgba(239, 68, 68, 0.15); border-color: rgba(239, 68, 68, 0.3); color: #ef4444; transform: rotate(90deg); }

/* All time stats */
.ff-engage-all-time { display:flex; justify-content:space-around; padding:18px 24px; background:rgba(255,255,255,0.01); border-bottom:1px solid rgba(255,255,255,0.06); text-align:center; }
.ff-engage-all-time-stat { display:flex; flex-direction:column; align-items:center; }
.ff-engage-all-time-stat .ff-label { font-size:11px; color:#94a3b8; text-transform:uppercase; letter-spacing:.5px; margin-bottom:6px; font-weight:700; }
.ff-engage-all-time-stat .ff-value { font-size: 20px; font-weight:800; color:#ffffff; display:flex; align-items:center; gap:6px; }
.ff-engage-all-time-stat .ff-value .dashicons { color:#818cf8; font-size:18px; width:18px; height:18px; }

.ff-engage-list { list-style:none; margin:0; padding:12px 0; }
.ff-engage-empty { padding:40px 24px; text-align:center; color:#9ca3af; font-size:14px; }

/* Card row — Pinterest style */
.ff-engage-card { display:flex; align-items:center; gap:14px; padding:12px 24px; border-bottom:1px solid rgba(255,255,255,0.03); transition:background .12s; }
.ff-engage-card:last-child { border-bottom:none; }
.ff-engage-card:hover { background:rgba(255,255,255,0.02); }

.ff-engage-thumb { width:56px; height:56px; border-radius:10px; object-fit:cover; background:rgba(255,255,255,0.05); flex-shrink:0; border: 1px solid rgba(255,255,255,0.08); }
.ff-engage-thumb-placeholder { width:56px; height:56px; border-radius:10px; background:linear-gradient(135deg,rgba(255,255,255,0.03),rgba(255,255,255,0.08)); flex-shrink:0; display:flex; align-items:center; justify-content:center; border: 1px solid rgba(255,255,255,0.08); }
.ff-engage-thumb-placeholder .dashicons { font-size:24px; color:#4b5563; }

.ff-engage-info { flex:1; min-width:0; }
.ff-engage-title { font-size:13px; font-weight:600; color:#ffffff; margin:0 0 4px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.ff-engage-title a { color:inherit; text-decoration:none; transition: color 0.15s; }
.ff-engage-title a:hover { color:#818cf8; }
.ff-engage-author { font-size:11px; color:#9ca3af; margin:0 0 6px; display:flex; align-items:center; gap:6px; }
.ff-engage-author img { width:18px; height:18px; border-radius:50%; }
.ff-engage-type-badge { display:inline-block; font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.5px; padding:1px 6px; border-radius:4px; background:rgba(255,255,255,0.08); color:#cbd5e1; }

/* Stat pills row */
.ff-engage-stats { display:flex; gap:8px; flex-wrap:wrap; }
.ff-engage-stat { display:inline-flex; align-items:center; gap:4px; font-size:11px; color:#cbd5e1; background:rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.06); padding:3px 8px; border-radius:6px; white-space:nowrap; }
.ff-engage-stat .dashicons { font-size:14px; width:14px; height:14px; line-height:14px; color:#9ca3af; }
.ff-engage-stat strong { font-weight:700; color:#ffffff; }
.ff-engage-stat-total { background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%); border: none; color:#fff; font-weight:700; box-shadow: 0 2px 8px rgba(79, 70, 229, 0.3); }
.ff-engage-stat-total .dashicons { color:#fff; }
.ff-engage-stat-total strong { color:#fff; }

/* Rank number */
.ff-engage-rank { width:26px; text-align:center; font-size:13px; font-weight:800; color:rgba(255,255,255,0.2); flex-shrink:0; }
.ff-engage-card:nth-child(-n+3) .ff-engage-rank { color:#818cf8; }

/* Loading */
.ff-engage-loading { padding:60px 24px; text-align:center; color:#9ca3af; }
.ff-engage-spinner { display:inline-block; width:28px; height:28px; border:3px solid rgba(255,255,255,0.1); border-top-color:#818cf8; border-radius:50%; animation:ffEngSpin .6s linear infinite; margin-bottom:12px; }
@keyframes ffEngSpin { to { transform:rotate(360deg); } }

/* AI Summary Section */
.ff-engage-ai-container { padding: 16px 24px; border-bottom: 1px solid rgba(255,255,255,0.06); background: rgba(129, 140, 248, 0.02); }
.ff-engage-ai-banner { display: flex; align-items: center; justify-content: space-between; background: linear-gradient(135deg, rgba(79, 70, 229, 0.08) 0%, rgba(124, 58, 237, 0.08) 100%); border: 1px solid rgba(129, 140, 248, 0.15); border-radius: 12px; padding: 14px 20px; gap: 16px; }
.ff-engage-ai-icon { font-size: 24px; animation: ffSparkle 2s ease-in-out infinite; }
@keyframes ffSparkle { 0%, 100% { transform: scale(1); filter: drop-shadow(0 0 2px rgba(129,140,248,0.3)); } 50% { transform: scale(1.15); filter: drop-shadow(0 0 6px rgba(129,140,248,0.7)); } }
.ff-engage-ai-content { flex: 1; }
.ff-engage-ai-content h4 { margin: 0 0 4px; font-size: 14px; font-weight: 700; color: #a5b4fc; }
.ff-engage-ai-content p { margin: 0; font-size: 12px; color: #cbd5e1; }
.ff-engage-ai-btn { background: linear-gradient(135deg, #4f46e5, #7c3aed); border: none; border-radius: 8px; padding: 8px 16px; color: #fff; font-size: 12px; font-weight: 700; cursor: pointer; transition: all 0.2s ease; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3); outline: none; display: flex; align-items: center; justify-content: center; }
.ff-engage-ai-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(79, 70, 229, 0.45); }
.ff-engage-ai-btn:active { transform: translateY(1px); }

/* AI Loading */
.ff-engage-ai-loading { display: flex; align-items: center; justify-content: center; gap: 12px; padding: 20px; font-size: 13px; font-weight: 600; color: #a5b4fc; }
.ff-engage-ai-spinner, .ff-engage-ai-spinner-small { display: inline-block; border-radius: 50%; animation: ffEngSpin .6s linear infinite; }
.ff-engage-ai-spinner { width: 20px; height: 20px; border: 2.5px solid rgba(129, 140, 248, 0.1); border-top-color: #818cf8; }
.ff-engage-ai-spinner-small { width: 14px; height: 14px; border: 2.0px solid rgba(129, 140, 248, 0.1); border-top-color: #818cf8; vertical-align: middle; margin-right: 8px; }

/* AI Card */
.ff-engage-ai-card { background: rgba(255,255,255,0.02); border: 1px solid rgba(129, 140, 248, 0.15); border-radius: 12px; padding: 20px; box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2); animation: ffEngFadeIn 0.3s ease; }
.ff-engage-ai-card-header { display: flex; align-items: center; gap: 10px; border-bottom: 1px dashed rgba(129, 140, 248, 0.15); padding-bottom: 12px; margin-bottom: 16px; }
.ff-engage-ai-card-header h4 { margin: 0; font-size: 15px; font-weight: 800; color: #ffffff; }
.ff-engage-ai-card-content { font-size: 13px; line-height: 1.6; color: #e2e8f0; }
.ff-engage-ai-card-content h4 { font-size: 14px; font-weight: 800; color: #a5b4fc; margin: 16px 0 8px; }
.ff-engage-ai-card-content h5 { font-size: 13px; font-weight: 700; color: #c084fc; margin: 12px 0 6px; }
.ff-engage-ai-card-content p { margin: 0 0 10px; }
.ff-engage-ai-card-content ul { margin: 0 0 12px; padding-left: 18px; list-style-type: disc; }
.ff-engage-ai-card-content li { margin-bottom: 6px; }
.ff-engage-ai-regenerate-btn { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.08); border-radius: 6px; padding: 6px 14px; font-size: 10px; font-weight: 700; color: #a5b4fc; cursor: pointer; transition: all 0.15s; margin-top: 14px; display: inline-flex; align-items: center; }
.ff-engage-ai-regenerate-btn:hover { background: rgba(255,255,255,0.1); }

/* AI Errors */
.ff-engage-ai-error { background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: 12px; padding: 14px 20px; color: #fca5a5; font-size: 12px; font-weight: 600; display: flex; align-items: center; justify-content: space-between; }
.ff-engage-ai-retry-btn { background: #ef4444; color: #fff; border: none; border-radius: 6px; padding: 5px 14px; cursor: pointer; transition: all 0.15s; font-size: 11px; font-weight: 700; }
.ff-engage-ai-retry-btn:hover { background: #dc2626; }
CSS;
    }

    private function getAdminJS($ajaxurl, $nonce, $aiAvailable = false)
    {
        $ajaxurl = esc_js($ajaxurl);
        $nonce   = esc_js($nonce);
        $aiAvailableJS = $aiAvailable ? 'true' : 'false';

        return <<<JS
(function(){
    var loaded = false, overlay;
    var cachedPosts = [], cachedOverall = null, currentSort = 'total';
    var aiAvailable = {$aiAvailableJS};

    function createOverlay(){
        if(overlay) return;
        overlay = document.createElement('div');
        overlay.className = 'ff-engage-overlay';
        overlay.innerHTML =
            '<div class="ff-engage-modal">' +
                '<div class="ff-engage-modal-header">' +
                    '<h3>\u{1F4CA} Engagement Summary <span class="ff-beta-label">BETA</span></h3>' +
                    '<div class="ff-engage-header-actions">' +
                        '<div class="ff-engage-sort-wrap">' +
                            '<label for="ff-engage-sort">Sort by</label>' +
                            '<select id="ff-engage-sort">' +
                                '<option value="total" selected>Total</option>' +
                                '<option value="post_views">Views</option>' +
                                '<option value="lightbox_opens">Lightbox</option>' +
                                '<option value="outbound_clicks">Clicks</option>' +
                                '<option value="profile_visits">Profiles</option>' +
                            '</select>' +
                        '</div>' +
                        '<button class="ff-engage-reset" title="Reset all engagement statistics">\u21BB Reset</button>' +
                        '<button class="ff-engage-close">&times;</button>' +
                    '</div>' +
                '</div>' +
                '<div class="ff-engage-overall"></div>' +
                '<div class="ff-engage-ai-container"></div>' +
                '<div class="ff-engage-body">' +
                    '<div class="ff-engage-loading"><div class="ff-engage-spinner"></div><br>Loading top posts…</div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(overlay);

        overlay.querySelector('.ff-engage-close').addEventListener('click', close);
        overlay.querySelector('.ff-engage-reset').addEventListener('click', resetStats);
        overlay.querySelector('#ff-engage-sort').addEventListener('change', function(){ reSort(this.value); });
        overlay.addEventListener('click', function(e){ if(e.target === overlay) close(); });
        document.addEventListener('keydown', function(e){ if(e.key === 'Escape') close(); });
    }

    function open(){
        createOverlay();
        overlay.classList.add('ff-open');
        document.body.style.overflow = 'hidden';
        if(!loaded) fetchData();
        markSeen();
    }

    function close(){
        if(!overlay) return;
        overlay.classList.remove('ff-open');
        document.body.style.overflow = '';
    }

    function resetStats(){
        if(!confirm('Are you sure you want to reset all engagement statistics? This cannot be undone.')) return;
        var btn = overlay.querySelector('.ff-engage-reset');
        var orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = 'Resetting\u2026';
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '{$ajaxurl}', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function(){
            btn.disabled = false;
            btn.innerHTML = orig;
            loaded = false;
            cachedPosts = [];
            cachedOverall = null;
            var sortSel = overlay.querySelector('#ff-engage-sort');
            if(sortSel) { sortSel.value = 'total'; currentSort = 'total'; }
            fetchData();
            var badge = document.querySelector('.ff-engage-badge');
            if(badge) badge.style.display = 'none';
        };
        xhr.send('action=ff_engagement_reset&_nonce={$nonce}');
    }

    function markSeen(){
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '{$ajaxurl}', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.send('action=ff_engagement_mark_seen&_nonce={$nonce}');
        
        var badge = document.querySelector('.ff-engage-badge');
        if (badge) badge.style.display = 'none';
    }

    function fetchData(){
        var body = overlay.querySelector('.ff-engage-body');
        body.innerHTML = '<div class="ff-engage-loading"><div class="ff-engage-spinner"></div><br>Loading top posts\u2026</div>';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '{$ajaxurl}', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function(){
            try {
                var res = JSON.parse(xhr.responseText);
                if(res.success && res.data){
                    var fetchedPosts = res.data.posts || [];
                    if(currentSort !== 'total'){
                        var sk = currentSort;
                        fetchedPosts.sort(function(a,b){ return (b.engagement[sk]||0) - (a.engagement[sk]||0); });
                    }
                    renderPosts(fetchedPosts, res.data.overall, body);
                    loaded = true;
                } else {
                    body.innerHTML = '<div class="ff-engage-empty">No engagement data yet.</div>';
                }
            } catch(e){
                body.innerHTML = '<div class="ff-engage-empty">Error loading data.</div>';
            }
        };
        xhr.onerror = function(){ body.innerHTML = '<div class="ff-engage-empty">Network error.</div>'; };
        xhr.send('action=ff_engagement_top_posts&_nonce={$nonce}');
    }

    function reSort(key){
        currentSort = key;
        var sorted = cachedPosts.slice().sort(function(a, b){
            var va = key === 'total' ? (b.engagement.total - a.engagement.total) : ((b.engagement[key]||0) - (a.engagement[key]||0));
            return va;
        });
        var body = overlay.querySelector('.ff-engage-body');
        if(body) renderPosts(sorted, cachedOverall, body, true);
    }

    function renderAiSection(overall, posts){
        var container = overlay.querySelector('.ff-engage-ai-container');
        if(!container) return;
        if(!aiAvailable) {
            container.style.display = 'none';
            return;
        }

        if(!posts || !posts.length){
            container.innerHTML = '';
            return;
        }

        container.innerHTML =
            '<div class="ff-engage-ai-banner">' +
                '<div class="ff-engage-ai-icon">✨</div>' +
                '<div class="ff-engage-ai-content">' +
                    '<h4>Need performance recommendations?</h4>' +
                    '<p>Get instant, AI-powered analysis of your top posts and engagement trends.</p>' +
                '</div>' +
                '<button class="ff-engage-ai-btn">✨ Generate AI Summary</button>' +
            '</div>' +
            '<div class="ff-engage-ai-results" style="display: none;"></div>';

        container.querySelector('.ff-engage-ai-btn').addEventListener('click', generateAiSummary);
    }

    function generateAiSummary(){
        var container = overlay.querySelector('.ff-engage-ai-container');
        var banner = container.querySelector('.ff-engage-ai-banner');
        var results = container.querySelector('.ff-engage-ai-results');
        
        banner.style.display = 'none';
        results.style.display = 'block';
        results.innerHTML = '<div class="ff-engage-ai-loading"><div class="ff-engage-ai-spinner"></div><span>✨ Analyzing post engagement & performance (Part 1/2)...</span></div>';
        
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '{$ajaxurl}', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function(){
            try {
                var res = JSON.parse(xhr.responseText);
                if(res.success && res.data && res.data.summary){
                    results.innerHTML = 
                        '<div class="ff-engage-ai-card">' +
                            '<div class="ff-engage-ai-card-header">' +
                                '<div class="ff-engage-ai-icon">✨</div>' +
                                '<h4>AI Performance Summary & Recommendations</h4>' +
                            '</div>' +
                            '<div class="ff-engage-ai-card-content">' +
                                '<div class="ff-engage-ai-analysis">' + res.data.summary + '</div>' +
                                '<div class="ff-engage-ai-loading-chunk-2" style="margin-top: 16px; font-weight: 600; color: #a5b4fc; display: flex; align-items: center;">' +
                                    '<div class="ff-engage-ai-spinner-small"></div><span>✨ Brainstorming recommendations (Part 2/2)...</span>' +
                                '</div>' +
                                '<div class="ff-engage-ai-recommendations" style="display: none; margin-top: 16px;"></div>' +
                            '</div>' +
                            '<button class="ff-engage-ai-regenerate-btn" style="display: none;">\u21BB Regenerate</button>' +
                        '</div>';
                    
                    var xhr2 = new XMLHttpRequest();
                    xhr2.open('POST', '{$ajaxurl}', true);
                    xhr2.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr2.onload = function(){
                        try {
                            var res2 = JSON.parse(xhr2.responseText);
                            if(res2.success && res2.data && res2.data.summary){
                                var loading2 = results.querySelector('.ff-engage-ai-loading-chunk-2');
                                if(loading2) loading2.style.display = 'none';
                                
                                var recs = results.querySelector('.ff-engage-ai-recommendations');
                                if(recs) {
                                    recs.innerHTML = res2.data.summary;
                                    recs.style.display = 'block';
                                }
                                
                                var regenBtn = results.querySelector('.ff-engage-ai-regenerate-btn');
                                if(regenBtn) {
                                    regenBtn.style.display = 'inline-flex';
                                    regenBtn.addEventListener('click', generateAiSummary);
                                }
                            } else {
                                var msg2 = res2.data && res2.data.message ? res2.data.message : 'Recommendations generation failed.';
                                showSecondChunkError(msg2);
                            }
                        } catch(e){
                            showSecondChunkError('Error parsing recommendations response.');
                        }
                    };
                    xhr2.onerror = function(){
                        showSecondChunkError('Network error occurred during recommendations generation.');
                    };
                    xhr2.send('action=ff_engagement_ai_summary&chunk=recommendations&_nonce={$nonce}');
                } else {
                    var msg = res.data && res.data.message ? res.data.message : 'AI generation failed.';
                    showError(msg);
                }
            } catch(e){
                showError('Error parsing server response.');
            }
        };
        xhr.onerror = function(){
            showError('Network error occurred.');
        };
        xhr.send('action=ff_engagement_ai_summary&chunk=analysis&_nonce={$nonce}');

        function showError(msg) {
            results.innerHTML = '<div class="ff-engage-ai-error">Oops! ' + esc(msg) + ' <button class="ff-engage-ai-retry-btn">Retry</button></div>';
            results.querySelector('.ff-engage-ai-retry-btn').addEventListener('click', generateAiSummary);
        }

        function showSecondChunkError(msg) {
            var loading2 = results.querySelector('.ff-engage-ai-loading-chunk-2');
            if(loading2) {
                loading2.innerHTML = '<span style="color: #fca5a5;">⚠️ ' + esc(msg) + ' </span><button class="ff-engage-ai-retry-btn" style="margin-left: 10px;">Retry</button>';
                loading2.querySelector('.ff-engage-ai-retry-btn').addEventListener('click', generateAiSummary);
            }
        }
    }

    function renderPosts(posts, overall, container, skipCache){
        if(!skipCache){
            cachedPosts = posts;
            cachedOverall = overall;
            renderAiSection(overall, posts);
        }
        var overallContainer = overlay.querySelector('.ff-engage-overall');
        if (overallContainer && overall) {
            overallContainer.innerHTML = 
                '<div class="ff-engage-all-time">' +
                    '<div class="ff-engage-all-time-stat"><span class="ff-label">Overall Stats</span><span class="ff-value"><span class="dashicons dashicons-chart-bar"></span>' + fmt(overall.total) + '</span></div>' +
                    '<div class="ff-engage-all-time-stat"><span class="ff-label">Views</span><span class="ff-value"><span class="dashicons dashicons-visibility"></span>' + fmt(overall.post_views || 0) + '</span></div>' +
                    '<div class="ff-engage-all-time-stat"><span class="ff-label">Lightbox</span><span class="ff-value"><span class="dashicons dashicons-format-image"></span>' + fmt(overall.lightbox_opens) + '</span></div>' +
                    '<div class="ff-engage-all-time-stat"><span class="ff-label">Clicks</span><span class="ff-value"><span class="dashicons dashicons-external"></span>' + fmt(overall.outbound_clicks) + '</span></div>' +
                    '<div class="ff-engage-all-time-stat"><span class="ff-label">Profiles</span><span class="ff-value"><span class="dashicons dashicons-admin-users"></span>' + fmt(overall.profile_visits) + '</span></div>' +
                '</div>';
        }

        if(!posts.length){ container.innerHTML = '<div class="ff-engage-empty">No engagement data yet.</div>'; return; }
        var html = '<ul class="ff-engage-list">';
        for(var i = 0; i < posts.length; i++){
            var p = posts[i], e = p.engagement;
            var thumb = p.image
                ? '<img class="ff-engage-thumb" src="' + esc(p.image) + '" alt="">'
                : '<div class="ff-engage-thumb-placeholder"><span class="dashicons dashicons-format-image"></span></div>';
            var author = '';
            if(p.userpic || p.nickname){
                author = '<p class="ff-engage-author">';
                if(p.userpic) author += '<img src="' + esc(p.userpic) + '" alt="">';
                author += esc(p.nickname || 'Unknown') + ' <span class="ff-engage-type-badge">' + esc(p.type) + '</span></p>';
            }
            var rawTitle = p.title;
            if(!rawTitle && p.description) rawTitle = p.description.substring(0, 100) + (p.description.length > 100 ? '\u2026' : '');
            if(!rawTitle) rawTitle = '(no title)';
            var title;
            if(p.permalink) title = '<a href="' + esc(p.permalink) + '" target="_blank">' + esc(rawTitle) + '</a>';
            else title = esc(rawTitle);

            html += '<li class="ff-engage-card">' +
                '<span class="ff-engage-rank">' + (i+1) + '</span>' +
                thumb +
                '<div class="ff-engage-info">' +
                    '<p class="ff-engage-title">' + title + '</p>' +
                    author +
                    '<div class="ff-engage-stats">' +
                        '<span class="ff-engage-stat ff-engage-stat-total"><span class="dashicons dashicons-chart-bar"></span> <strong>' + fmt(e.total) + '</strong></span>' +
                        '<span class="ff-engage-stat"><span class="dashicons dashicons-visibility"></span> <strong>' + fmt(e.post_views || 0) + '</strong> views</span>' +
                        '<span class="ff-engage-stat"><span class="dashicons dashicons-format-image"></span> <strong>' + fmt(e.lightbox_opens) + '</strong> lightbox</span>' +
                        '<span class="ff-engage-stat"><span class="dashicons dashicons-external"></span> <strong>' + fmt(e.outbound_clicks) + '</strong> clicks</span>' +
                        '<span class="ff-engage-stat"><span class="dashicons dashicons-admin-users"></span> <strong>' + fmt(e.profile_visits) + '</strong> profiles</span>' +
                    '</div>' +
                '</div>' +
            '</li>';
        }
        html += '</ul>';
        container.innerHTML = html;
    }

    function fmt(n){ return n >= 1000 ? (n/1000).toFixed(1) + 'k' : String(n); }
    function esc(s){ var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    // Bind admin bar click
    document.addEventListener('click', function(e){
        var bar = e.target.closest('#wp-admin-bar-ff-engagement');
        if(bar){ e.preventDefault(); open(); }
    });
})();
JS;
    }

    // ───────────── Helpers ─────────────

    private function getClientIP()
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = $_SERVER[$h];
                if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    }
}


// phpcs:enable
