<?php
// phpcs:disable
namespace la\core\cache;
if (!defined('WPINC')) die;

/** Anonymous response cache. Generation changes retire entries in every WP backend. */
class LATransientCache {
    // Retired transient rows await WordPress expired-transient cleanup; generation
    // options are non-autoloaded to avoid loading every stream's key on all requests.
    const CACHE_PREFIX = 'ff_posts_v2_';
    const DEFAULT_TTL = 60;
    const ISSUED_HASHES = 10;
    const ISSUED_HASH_TTL = 3600;

    public static function generateCacheKey($streamId, $page = 0, $hash = null, $isRecent = false, $variants = []) {
        $parts = [
            (int)$streamId, (int)$page, (string)$hash, (bool)$isRecent, $variants,
            get_option('ff_posts_generation', '0'),
            get_option(self::streamGenerationOption($streamId), '0')
        ];
        return self::CACHE_PREFIX . hash('sha256', serialize($parts));
    }

    public static function getByKey($key) {
        return get_transient($key);
    }

    // Reuse the key captured before rendering: late writes stay in their old generation.
    public static function setByKey($key, $jsonResponse, $ttl = self::DEFAULT_TTL) {
        return set_transient($key, $jsonResponse, $ttl);
    }

    public static function get($streamId, $page = 0, $hash = null, $isRecent = false) {
        return self::getByKey(self::generateCacheKey($streamId, $page, $hash, $isRecent));
    }

    public static function set($streamId, $jsonResponse, $page = 0, $hash = null, $isRecent = false, $ttl = self::DEFAULT_TTL) {
        return self::setByKey(self::generateCacheKey($streamId, $page, $hash, $isRecent), $jsonResponse, $ttl);
    }

    /** Records a hash served in a cached first page so its later pages may be cached. */
    public static function issueHash($streamId, $hash) {
        if (!is_string($hash) || $hash === '') return false;
        $key = self::issuedHashesKey($streamId);
        $hashes = get_transient($key);
        $hashes = is_array($hashes) ? $hashes : [];
        if (in_array($hash, $hashes, true)) return true;
        $hashes[] = $hash;
        return set_transient($key, array_slice($hashes, -self::ISSUED_HASHES), self::ISSUED_HASH_TTL);
    }

    public static function isIssuedHash($streamId, $hash) {
        $hashes = get_transient(self::issuedHashesKey($streamId));
        return is_array($hashes) && in_array($hash, $hashes, true);
    }

    private static function issuedHashesKey($streamId) {
        return self::CACHE_PREFIX . 'hashes_' . hash('sha256', serialize([
            (int)$streamId,
            get_option('ff_posts_generation', '0'),
            get_option(self::streamGenerationOption($streamId), '0')
        ]));
    }

    public static function invalidateStream($streamId) {
        return update_option(self::streamGenerationOption($streamId), wp_generate_uuid4(), false);
    }

    public static function invalidateAll() {
        return update_option('ff_posts_generation', wp_generate_uuid4(), false);
    }

    private static function streamGenerationOption($streamId) {
        return 'ff_posts_stream_generation_' . md5((string)(int)$streamId);
    }
}
// phpcs:enable
