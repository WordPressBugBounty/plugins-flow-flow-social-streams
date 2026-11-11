<?php namespace la\core\cache;
if ( ! defined( 'WPINC' ) ) die;

use Exception;

/**
 * FlowFlow.
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>
 *
 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
class LAAdminModerationCacheManager extends LACacheManager {
    function __construct( $context = null, $force = false ) {
        parent::__construct( $context, $force );
    }

    public function moderate() {
        $conn = null;
        $failure = null;
        try {
            $conn = $this->db->conn();
            if ($conn->beginTransaction() === false) throw new Exception('Could not begin moderation changes');
            $hash = \la\core\LAUtils::get_request_var('hash', 'post', 'text', '');
            $action = \la\core\LAUtils::get_request_var('moderation_action', 'post', 'text', '');
            $stream_id = \la\core\LAUtils::get_request_var('stream', 'post', 'text', '');
            $stream = $this->db->getStream($stream_id);
            $feeds = [];
            foreach ($stream['feeds'] as $feed) $feeds[] = $feed['id'];

            $where = $conn->parse("WHERE `feed_id` in (?a) AND `creation_index` <= ?i AND `post_status` = 'new'", $feeds, $this->decodeHash($hash));
            $status = $action == 'new_posts_approve' ? 'approved' : 'disapproved';
            $this->db->setPostStatus($status, $where, time());

            $changed = \la\core\LAUtils::get_request_var('changed', 'post', 'raw', []);
            $feedFilter = $conn->parse('`feed_id` in (?a)', $feeds);
            foreach ($changed as $id => $item) {
                $creation_index = time();
                if (isset($item['approved'])) {
                    $status = ($item['approved'] === 'true' || $item['approved'] === true) ? 'approved' : 'disapproved';
                    $this->db->setPostStatus($status, $conn->parse('WHERE ?p AND `post_id` = ?s', $feedFilter, $id), $creation_index);
                }
                if (isset($item['pinned'])) {
                    $isPinned = ($item['pinned'] === '1' || $item['pinned'] === 1) ? 1 : 0;
                    $pinnedOrder = isset($item['pinned_order']) ? (int)$item['pinned_order'] : time();
                    if ($conn->query(
                        'UPDATE ?n SET `is_pinned` = ?i, `pinned_order` = ?i, `creation_index` = ?i WHERE `post_id` = ?s AND ?p',
                        $this->db->posts_table_name, $isPinned, $pinnedOrder, $creation_index, $id, $feedFilter
                    ) === false) throw new Exception('Could not save pinned status');
                }
                if (!isset($item['cta'])) continue;
                $cta = $item['cta'];
                $ctaData = null;
                if (!empty($cta['text']) || !empty($cta['ltext']) || !empty($cta['url'])) {
                    $ctaData = [
                        'text' => isset($cta['text']) ? sanitize_text_field($cta['text']) : '',
                        'ltext' => isset($cta['ltext']) ? sanitize_text_field($cta['ltext']) : '',
                        'url' => isset($cta['url']) ? esc_url_raw($cta['url']) : '',
                        'color' => isset($cta['color']) ? $this->sanitize_hex_color($cta['color']) : '#e916b7',
                        'new' => isset($cta['new']) ? sanitize_text_field($cta['new']) : 'nope',
                        'open' => isset($cta['open']) ? sanitize_text_field($cta['open']) : 'nope',
                        'likes' => isset($cta['likes']) ? (int)$cta['likes'] : 0
                    ];
                }
                // One displayed post can match several feed/type rows. Lock
                // and merge each independently to preserve its own counters.
                $rows = $conn->getAll(
                    'SELECT `post_id`, `feed_id`, `post_type`, `post_additional` FROM ?n WHERE `post_id` = ?s AND ?p ORDER BY `post_id`, `post_type`, `feed_id` FOR UPDATE',
                    $this->db->posts_table_name, $id, $feedFilter
                );
                if (!is_array($rows)) throw new Exception('Could not read post metadata');
                if (empty($rows)) throw new Exception('Post not found in this stream');
                foreach ($rows as $row) {
                    $additional = [];
                    if ($row['post_additional'] !== null && $row['post_additional'] !== '') {
                        $decoded = json_decode($row['post_additional']);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            throw new Exception('Invalid stored post metadata');
                        }
                        $additional = is_object($decoded) || is_array($decoded) ? (array)$decoded : [];
                    }
                    if ($ctaData === null) unset($additional['cta']);
                    else $additional['cta'] = $ctaData;
                    $jsonData = json_encode($additional);
                    if ($jsonData === false) throw new Exception('Could not encode post metadata');
                    if ($conn->query(
                        'UPDATE ?n SET `post_additional` = ?s, `creation_index` = ?i WHERE `post_id` = ?s AND `feed_id` = ?s AND `post_type` = ?s',
                        $this->db->posts_table_name, $jsonData, $creation_index,
                        $row['post_id'], $row['feed_id'], $row['post_type']
                    ) === false) throw new Exception('Could not save post metadata');
                }
            }
            if ($conn->commit() === false) throw new Exception('Could not commit moderation changes');
        } catch (\Throwable $error) {
            $failure = $error;
            if ($conn) {
                try { $conn->rollback(); } catch (\Throwable $rollbackError) {
                    \flow\FFLogManager::log('Could not roll back moderation changes', ['error' => $rollbackError->getMessage()]);
                }
            }
        } finally {
            if ($conn) {
                try { $conn->close(); } catch (\Throwable $closeError) {
                    \flow\FFLogManager::log('Could not close moderation connection', ['error' => $closeError->getMessage()]);
                }
            }
        }
        if ($failure) {
            if (function_exists('status_header')) status_header(500);
            die(function_exists('esc_html') ? esc_html($failure->getMessage()) : htmlspecialchars($failure->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
        // Posts can belong to several streams through the same feed. Cache
        // failure cannot undo the committed moderation changes.
        try { LATransientCache::invalidateAll(); } catch (\Throwable $cacheError) {
            \flow\FFLogManager::log('Could not invalidate moderation responses', ['error' => $cacheError->getMessage()]);
        }
        die();
    }

    /**
     * Sanitize hex color value
     * @param string $color
     * @return string
     */
    private function sanitize_hex_color($color) {
        if ('' === $color) {
            return '';
        }
        
        // Remove #
        $color = ltrim($color, '#');
        
        // Validate 6-digit hex color
        if (preg_match('/^[a-fA-F0-9]{6}$/', $color)) {
            return '#' . $color;
        }
        
        // Validate 3-digit hex color
        if (preg_match('/^[a-fA-F0-9]{3}$/', $color)) {
            return '#' . $color;
        }
        
        return '#e916b7'; // Default fallback
    }

    protected function getGetFields() {
        $select = parent::getGetFields();
        $select .= ', post.post_status, post.is_pinned, post.pinned_order';
        return $select;
    }

    protected function getGetFilters() {
        $args = parent::getGetFilters();
        $args[] = $this->db->conn()->parse('post.post_status != ?s', 'new');
        return $args;
    }

    protected function buildPost( $row, $moderation = false ) {
        $post = parent::buildPost( $row, $moderation );
        $post->status = $row['post_status'];
        
        // Include pinned status
        if (isset($row['is_pinned'])) {
            $post->pinned = $row['is_pinned'];
        }
        if (isset($row['pinned_order'])) {
            $post->pinned_order = $row['pinned_order'];
        }
        
        // CTA data is in post_additional from parent, extract to top-level for frontend
        if (isset($post->additional)) {
            if (is_object($post->additional) && isset($post->additional->cta)) {
                $post->cta = $post->additional->cta;
            } else if (is_array($post->additional) && isset($post->additional['cta'])) {
                $post->cta = (object)$post->additional['cta'];
            }
        }
        
        return $post;
    }

    protected function getOnlyNew($moderation) {
        $result = parent::getOnlyNew($moderation);
        $filters = parent::getGetFilters();
        $filters[] = $this->db->conn()->parse('post.post_status = ?s', 'new');
        $resultFromDB = $this->db->getPostsIf2($this->getGetFields(), implode(' AND ', $filters));
        if (false === $resultFromDB) $resultFromDB = [];
        foreach ( $resultFromDB as $row ) {
            $result[] = $this->buildPost($row, $moderation[$row['feed_id']]);
        }
        return $result;
    }

    public function hash() {
        if (!$this->stream) {
            return parent::hash();
        }
        $conn = $this->db->conn();
        $feeds = [];
        foreach ( $this->stream->getAllFeeds() as $feed ) {
            $feeds[] = $feed['id'];
        }
        $hash = $conn->getOne("SELECT MAX(`creation_index`) FROM ?n WHERE `feed_id` in (?a)", $this->db->posts_table_name, $feeds);
        return $this->encodeHash($hash ? $hash : time());
    }
}
