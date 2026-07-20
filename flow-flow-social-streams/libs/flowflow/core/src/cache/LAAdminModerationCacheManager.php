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
    protected $stream_moderation;

    function __construct( $context = null, $force = false ) {
        parent::__construct( $context, $force );
    }

    public function setStream( $stream, $moderation = false ) {
        parent::setStream($stream, $moderation);
        $this->stream_moderation = $stream;
    }

    public function moderate() {
        $conn = $this->db->conn();
        try {
            if ($conn->beginTransaction()){
                $hash = \la\core\LAUtils::get_request_var('hash', 'post', 'text', '');
                $action = \la\core\LAUtils::get_request_var('moderation_action', 'post', 'text', '');
                $stream_id = \la\core\LAUtils::get_request_var('stream', 'post', 'text', '');

                $feeds = [];
                $stream = $this->db->getStream($stream_id);
                foreach ( $stream['feeds'] as $feed ) {
                    $feeds[] = $feed['id'];
                }

                $commonPartOfSql = $conn->parse("`feed_id` in (?a) AND `creation_index` <= ?i", $feeds, $this->decodeHash($hash));
                $additionalPartOfSql = $conn->parse("`post_status` = 'new'");
                $status = $action == 'new_posts_approve' ? 'approved' : 'disapproved';
                $creation_index = time();
                $this->db->setPostStatus($status, $conn->parse('WHERE ?p AND ?p', $commonPartOfSql, $additionalPartOfSql), $creation_index);

                $changed_val = \la\core\LAUtils::get_request_var('changed', 'post', 'raw', null);
                if ($changed_val !== null){
                    $creation_index = time();
                    $commonPartOfSql = $conn->parse("`feed_id` in (?a)", $feeds);
                    $changed_data = $changed_val;
                    foreach ( $changed_data as $id => $item ) {
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                        error_log('Flow-Flow CTA Debug: Processing post ' . $id);
                        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_print_r
                        error_log('Flow-Flow CTA Debug: Item data: ' . print_r($item, true));
                        
                        // Process approval status (only if approved field is set)
                        if (isset($item['approved'])) {
                            $status = ($item['approved'] === "true" || $item['approved'] === true) ? 'approved' : 'disapproved';
                            $this->db->setPostStatus($status, $conn->parse('WHERE ?p AND `post_id` = ?s', $commonPartOfSql, $id), $creation_index);
                            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                            error_log('Flow-Flow CTA Debug: Set approval status to ' . $status);
                        }
                        
                        // Process pinned status
                        if (isset($item['pinned'])) {
                            $isPinned = ($item['pinned'] === "1" || $item['pinned'] === 1) ? 1 : 0;
                            $pinnedOrder = isset($item['pinned_order']) ? (int)$item['pinned_order'] : time();
                            
                            $conn->query(
                                'UPDATE ?n SET `is_pinned` = ?i, `pinned_order` = ?i, `creation_index` = ?i WHERE `post_id` = ?s AND ?p',
                                $this->db->posts_table_name,
                                $isPinned,
                                $pinnedOrder,
                                $creation_index,
                                $id,
                                $commonPartOfSql
                            );
                            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                            error_log('Flow-Flow CTA Debug: Set pinned status to ' . $isPinned);
                        }
                        
                        // Process CTA data
                        if (isset($item['cta'])) {
                            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                            error_log('Flow-Flow CTA Debug: Processing CTA for post ' . $id);
                            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_print_r
                            error_log('Flow-Flow CTA Debug: CTA data: ' . print_r($item['cta'], true));
                            
                            // Get current post_additional data
                            $current = $conn->getRow(
                                'SELECT `post_additional` FROM ?n WHERE `post_id` = ?s AND ?p',
                                $this->db->posts_table_name,
                                $id,
                                $commonPartOfSql
                            );
                            
                            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_print_r
                            error_log('Flow-Flow CTA Debug: Current post_additional: ' . print_r($current, true));
                            
                            $additional = array();
                            if (!empty($current['post_additional'])) {
                                $additional = json_decode($current['post_additional'], true);
                                if (!is_array($additional)) {
                                    $additional = array();
                                }
                            }
                            
                            // Add/update CTA data
                            $cta = $item['cta'];
                            if (!empty($cta['text']) || !empty($cta['ltext']) || !empty($cta['url'])) {
                                $additional['cta'] = array(
                                    'text' => isset($cta['text']) ? sanitize_text_field($cta['text']) : '',
                                    'ltext' => isset($cta['ltext']) ? sanitize_text_field($cta['ltext']) : '',
                                    'url' => isset($cta['url']) ? esc_url_raw($cta['url']) : '',
                                    'color' => isset($cta['color']) ? $this->sanitize_hex_color($cta['color']) : '#e916b7',
                                    'new' => isset($cta['new']) ? sanitize_text_field($cta['new']) : 'nope',
                                    'open' => isset($cta['open']) ? sanitize_text_field($cta['open']) : 'nope',
                                    'likes' => isset($cta['likes']) ? (int)$cta['likes'] : 0
                                );
                                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_print_r
                                error_log('Flow-Flow CTA Debug: Prepared CTA data: ' . print_r($additional['cta'], true));
                            } else {
                                // Remove CTA if all fields are empty
                                unset($additional['cta']);
                                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                                error_log('Flow-Flow CTA Debug: Removing CTA (all fields empty)');
                            }
                            
                            // Update database
                            $jsonData = json_encode($additional);
                            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                            error_log('Flow-Flow CTA Debug: Saving to DB: ' . $jsonData);
                            
                            $result = $conn->query(
                                'UPDATE ?n SET `post_additional` = ?s, `creation_index` = ?i WHERE `post_id` = ?s AND ?p',
                                $this->db->posts_table_name,
                                $jsonData,
                                $creation_index,
                                $id,
                                $commonPartOfSql
                            );
                            
                            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                            error_log('Flow-Flow CTA Debug: Update result: ' . ($result ? 'SUCCESS' : 'FAILED'));
                        }
                    }
                }
                if (class_exists('\la\core\cache\LATransientCache')) {
                    \la\core\cache\LATransientCache::invalidateStream($stream_id);
                }
                $conn->commit();
                $conn->close();
                die();
            }
            $conn->rollbackAndClose();
            die();
        } catch ( Exception $e ){
            $conn->rollbackAndClose();
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            die(function_exists('esc_html') ? esc_html($e->getMessage()) : htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }
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
        if (!$this->stream_moderation) {
            return parent::hash();
        }
        $conn = $this->db->conn();
        $feeds = [];
        foreach ( $this->stream_moderation->getAllFeeds() as $feed ) {
            $feeds[] = $feed['id'];
        }
        $hash = $conn->getOne("SELECT MAX(`creation_index`) FROM ?n WHERE `feed_id` in (?a)", $this->db->posts_table_name, $feeds);
        return $this->encodeHash($hash ? $hash : time());
    }

    public function transientHash($streamId) {
        $hash = $this->db->getLastUpdateHash($streamId);
        return (false !== $hash) ? $this->encodeHash($hash) : '';
    }

    private function encodeHash($hash){
        if (!empty($hash) && $this->stream_moderation){
            $postfix  = hash('md5', serialize($this->stream_moderation->original()));
            $postfix .= hash('md5', serialize(\la\core\settings\LAGeneralSettings::get()->original()));
            $postfix .= hash('md5', serialize(\la\core\settings\LAGeneralSettings::get()->originalAuth()));
            return $hash . "." . $postfix;
        }
        return $hash;
    }
}