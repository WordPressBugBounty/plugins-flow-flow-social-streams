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
}