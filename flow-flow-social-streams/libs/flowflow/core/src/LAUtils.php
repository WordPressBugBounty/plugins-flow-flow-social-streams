<?php namespace la\core;

use la\core\db\LADBManager;

/**
 *
 * @author    navdeykin <navdeykin@gmail.com>
 * @copyright 2014-2020 Looks Awesome
 */
class LAUtils {
    /**
     * @param array $context
     * @return LADBManager
     */
    public static function dbm( $context ) {
        return $context['db_manager'];
    }

    /**
     * @param array $context
     * @return string
     */
    public static function root( $context ) {
        return $context['root'];
    }

    /**
     * @param array $context
     * @return string
     */
    public static function slug( $context ) {
        return $context['slug'];
    }

    /**
     * @param array $context
     * @return string
     */
    public static function slug_down( $context ) {
        return $context['slug_down'];
    }

    /**
     * @param array $context
     * @return string
     */
    public static function version( $context ) {
        return $context['version'];
    }

    /**
     * @param array $context
     * @return string
     */
    public static function plugin_url( $context ) {
        return $context['plugin_url'] . $context['plugin_dir_name'];
    }

    /**
     * Safely get a request variable from $_GET, $_POST, or $_REQUEST.
     * Handles unslashing and sanitization according to WordPress standards.
     *
     * @param string $key The key to retrieve
     * @param string $source 'get', 'post', 'request', or 'server'
     * @param string $sanitize_type 'text', 'email', 'int', 'raw'
     * @param mixed $default Default value if not set
     * @return mixed
     */
    public static function get_request_var($key, $source = 'request', $sanitize_type = 'text', $default = '') {
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotValidated
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        // phpcs:disable WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
        
        $val = null;
        if ($source === 'get' && isset($_GET[$key])) {
            $val = $_GET[$key];
        } elseif ($source === 'post' && isset($_POST[$key])) {
            $val = $_POST[$key];
        } elseif ($source === 'request' && isset($_REQUEST[$key])) {
            $val = $_REQUEST[$key];
        } elseif ($source === 'server' && isset($_SERVER[$key])) {
            $val = $_SERVER[$key];
        }
        
        if ($val === null) {
            return $default;
        }

        // 1. Unslash
        if (function_exists('wp_unslash')) {
            $val = wp_unslash($val);
        }

        // 2. Sanitize
        if ($sanitize_type === 'text') {
            if (is_array($val)) {
                $val = array_map(function($v) {
                    return function_exists('sanitize_text_field') ? sanitize_text_field($v) : strip_tags(trim($v));
                }, $val);
            } else {
                $val = function_exists('sanitize_text_field') ? sanitize_text_field($val) : strip_tags(trim($val));
            }
        } elseif ($sanitize_type === 'email') {
            $val = function_exists('sanitize_email') ? sanitize_email($val) : filter_var($val, FILTER_SANITIZE_EMAIL);
        } elseif ($sanitize_type === 'int') {
            if (is_array($val)) {
                $val = array_map('intval', $val);
            } else {
                $val = intval($val);
            }
        }
        
        return $val;
        // phpcs:enable
    }

    /**
     * Reads a boolean request flag. Absent, empty, '0', 'false', 'no' and 'nope' are false.
     *
     * @param string $key
     * @return bool
     */
    public static function request_flag($key) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_REQUEST[$key]) || !is_scalar($_REQUEST[$key])) return false;
        $value = strtolower(trim((string)$_REQUEST[$key]));
        return !in_array($value, ['', '0', 'false', 'no', 'nope'], true);
    }

    /**
     * Client address for rate limiting. Forwarding headers are client controlled, so they
     * are trusted only behind a private-network proxy or when the
     * flow_flow_trust_proxy_headers filter opts in (for example behind Cloudflare).
     *
     * @return string
     */
    public static function client_ip() {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        $behindLocalProxy = filter_var($remote, FILTER_VALIDATE_IP)
            && !filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        $trust = function_exists('apply_filters') ? apply_filters('flow_flow_trust_proxy_headers', $behindLocalProxy) : $behindLocalProxy;
        if ($trust) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $header) {
                if (empty($_SERVER[$header])) continue;
                $ip = trim(explode(',', (string)$_SERVER[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return $remote;
    }

    /**
     * Masks credentials before text is written to the debug log, which may be web readable.
     * Covers query strings, JSON, print_r and var_export output, bearer headers, and bare
     * tokens recognised by their provider prefix (Facebook, Instagram, TikTok, LinkedIn, Google).
     *
     * @param string $text
     * @return string
     */
    public static function redactSecrets($text) {
        if (!is_string($text) || $text === '') {
            return $text;
        }
        $key = '[A-Za-z0-9_]*(?:token|secret|api_?key|_key|verifier)|apikey|appsecret_proof|password|nonce|security';
        $patterns = [
            '/\\b(' . $key . '|code|key)(=|%3D)[^&#\\s"\'<>]+/i' => '$1$2[REDACTED]',
            '/("(?:' . $key . ')"\\s*:\\s*")(?:[^"\\\\]|\\\\.)*"/i' => '$1[REDACTED]"',
            '/(\\[(?:' . $key . ')\\]\\s*=>\\s*)[^\\r\\n]+/i' => '$1[REDACTED]',
            '/(\'(?:' . $key . ')\'\\s*=>\\s*\')(?:[^\'\\\\]|\\\\.)*\'/i' => '$1[REDACTED]\'',
            '/\\b(Bearer\\s+)[^\\s"\'<>,;]+/i' => '$1[REDACTED]',
            // Bare tokens: keep the provider prefix so the log still says which one it was.
            '/\\b(EAA|IGQV|IGAA|AIza|ya29\\.|act\\.|rft\\.)[A-Za-z0-9!*._-]{16,}/' => '$1[REDACTED]',
            '/\\b(AQ)[A-Za-z0-9_-]{60,}/' => '$1[REDACTED]',
        ];
        $redacted = preg_replace(array_keys($patterns), array_values($patterns), $text);
        return $redacted === null ? '[log entry withheld: redaction failed]' : $redacted;
    }

    /**
     * error_log() with redactSecrets() applied, so PHP's error log never receives a credential either.
     *
     * @param mixed $message
     * @param int $type
     * @param string|null $destination
     * @return bool
     */
    public static function errorLog($message, $type = 0, $destination = null) {
        if (is_string($message)) {
            $message = self::redactSecrets($message);
        }
        return $destination === null ? error_log($message, $type) : error_log($message, $type, $destination);
    }
}