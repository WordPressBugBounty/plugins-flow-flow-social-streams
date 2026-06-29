<?php
// phpcs:disable
 namespace la\core\cache;
if ( ! defined( 'WPINC' ) ) die;

/**
 * Flow-Flow Transient Cache Layer.
 *
 * Provides a WordPress Transient API-based caching layer for fetch_posts responses.
 * This sits on top of the database cache, caching the final JSON response
 * to avoid repeated SQL queries for identical requests.
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>
 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
class LATransientCache {
    
    /**
     * Cache key prefix for transients
     */
    const CACHE_PREFIX = 'ff_posts_';
    
    /**
     * Default TTL in seconds (60 seconds = 1 minute)
     */
    const DEFAULT_TTL = 60;
    
    /**
     * Generate a cache key for a specific request
     *
     * @param string $streamId Stream ID
     * @param int $page Page number
     * @param string|null $hash Request hash (for pagination isolation)
     * @param bool $isRecent Whether this is a "load new" request
     *
     * @return string Cache key
     */
    public static function generateCacheKey($streamId, $page = 0, $hash = null, $isRecent = false) {
        $key_parts = [
            self::CACHE_PREFIX,
            's' . $streamId,
            'p' . $page
        ];
        
        if ($hash) {
            // Use first 12 chars of hash to keep key length manageable
            $key_parts[] = 'h' . substr(md5($hash), 0, 12);
        }
        
        if ($isRecent) {
            $key_parts[] = 'r';
        }
        
        return implode('_', $key_parts);
    }
    
    /**
     * Get cached response if available and valid
     *
     * @param string $streamId Stream ID
     * @param int $page Page number
     * @param string|null $hash Request hash
     * @param bool $isRecent Whether this is a "load new" request
     *
     * @return string|false Cached JSON response or false if not found/expired
     */
    public static function get($streamId, $page = 0, $hash = null, $isRecent = false) {
        $cache_key = self::generateCacheKey($streamId, $page, $hash, $isRecent);
        $cached = get_transient($cache_key);
        
        if ($cached !== false) {
            if (defined('FF_LOG_FILE_DEST')) {
                $timestamp = date('Y-m-d H:i:s');
                $msg = "[$timestamp] [LATransientCache] Cache HIT for key: $cache_key";
                @error_log($msg . "\n", 3, FF_LOG_FILE_DEST);
            }
            return $cached;
        }
        
        return false;
    }
    
    /**
     * Store response in transient cache
     *
     * @param string $streamId Stream ID
     * @param string $jsonResponse JSON response to cache
     * @param int $page Page number
     * @param string|null $hash Request hash
     * @param bool $isRecent Whether this is a "load new" request
     * @param int $ttl Time-to-live in seconds (default: 60)
     *
     * @return bool Success
     */
    public static function set($streamId, $jsonResponse, $page = 0, $hash = null, $isRecent = false, $ttl = self::DEFAULT_TTL) {
        $cache_key = self::generateCacheKey($streamId, $page, $hash, $isRecent);
        
        $result = set_transient($cache_key, $jsonResponse, $ttl);
        
        if (defined('FF_LOG_FILE_DEST')) {
            $timestamp = date('Y-m-d H:i:s');
            $status = $result ? 'SUCCESS' : 'FAILED';
            $msg = "[$timestamp] [LATransientCache] Cache SET $status for key: $cache_key (TTL: {$ttl}s)";
            @error_log($msg . "\n", 3, FF_LOG_FILE_DEST);
        }
        
        return $result;
    }
    
    /**
     * Delete cached responses for a specific stream
     *
     * @param string $streamId Stream ID
     *
     * @return void
     */
    public static function invalidateStream($streamId) {
        global $wpdb;
        
        // Delete all transients matching this stream
        $pattern = '_transient_' . self::CACHE_PREFIX . 's' . $streamId . '%';
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $pattern
            )
        );
        
        // Also delete timeout transients
        $timeout_pattern = '_transient_timeout_' . self::CACHE_PREFIX . 's' . $streamId . '%';
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $timeout_pattern
            )
        );
        
        if (defined('FF_LOG_FILE_DEST')) {
            $timestamp = date('Y-m-d H:i:s');
            $msg = "[$timestamp] [LATransientCache] Invalidated cache for stream: $streamId";
            @error_log($msg . "\n", 3, FF_LOG_FILE_DEST);
        }
    }
    
    /**
     * Delete all Flow-Flow transient caches
     *
     * @return int Number of deleted transients
     */
    public static function invalidateAll() {
        global $wpdb;
        
        $pattern = '_transient_' . self::CACHE_PREFIX . '%';
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $pattern
            )
        );
        
        // Also delete timeout transients
        $timeout_pattern = '_transient_timeout_' . self::CACHE_PREFIX . '%';
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $timeout_pattern
            )
        );
        
        if (defined('FF_LOG_FILE_DEST')) {
            $timestamp = date('Y-m-d H:i:s');
            $msg = "[$timestamp] [LATransientCache] Invalidated ALL caches. Deleted: $deleted";
            @error_log($msg . "\n", 3, FF_LOG_FILE_DEST);
        }
        
        return $deleted;
    }
}

// phpcs:enable
