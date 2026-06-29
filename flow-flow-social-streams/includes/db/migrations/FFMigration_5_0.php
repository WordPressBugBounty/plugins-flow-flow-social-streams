<?php
// phpcs:disable
namespace flow\db\migrations;

use la\core\db\LADDLUtils;
use la\core\db\migrations\ILADBMigration;

if (!defined('WPINC'))
    die;

/**
 * Migration 5.0 - Combined migration for error tracking and boost controls
 * 
 * This migration combines:
 * 1. Adding error_timestamp column to cache table (from 3.15/3.16)
 * 2. Adding boost control features: pinned posts and CTA buttons (from boost_controls)
 * 
 * Adds to cache table:
 * - error_timestamp column to track when errors occurred
 * 
 * Adds to posts table:
 * - is_pinned column for pinned posts
 * - pinned_order column for pinned posts ordering
 * - Index for pinned posts
 * - Extends post_additional to TEXT for larger CTA data
 */
class FFMigration_5_0 implements ILADBMigration
{

    public function version()
    {
        return '5.0';
    }

    public function execute($conn, $manager)
    {

        $log = defined('FF_LOG_FILE_DEST') ? FF_LOG_FILE_DEST : null;

        if ($log)
            error_log('[Flow-Flow Migration 5.0] Starting combined migration' . PHP_EOL, 3, $log);

        // ========================================
        // Part 1: Add error_timestamp to cache table
        // ========================================
        $cache_table = $manager->cache_table_name;

        if ($log)
            error_log('[Flow-Flow Migration 5.0] Checking error_timestamp column in cache table' . PHP_EOL, 3, $log);

        if (!LADDLUtils::existColumn($conn, $cache_table, 'error_timestamp')) {
            if ($log)
                error_log('[Flow-Flow Migration 5.0] Adding error_timestamp column to cache table' . PHP_EOL, 3, $log);
            try {
                $sql = "ALTER TABLE ?n ADD COLUMN `error_timestamp` INT DEFAULT 0 AFTER `errors`";
                $result = $conn->query($sql, $cache_table);
                if ($result) {
                    if ($log)
                        error_log('[Flow-Flow Migration 5.0] Successfully added error_timestamp column' . PHP_EOL, 3, $log);
                } else {
                    if ($log)
                        error_log('[Flow-Flow Migration 5.0] FAILED to add error_timestamp column' . PHP_EOL, 3, $log);
                }
            } catch (\Exception $e) {
                if ($log)
                    error_log('[Flow-Flow Migration 5.0] EXCEPTION adding error_timestamp: ' . $e->getMessage() . PHP_EOL, 3, $log);
                throw $e;
            }
        } else {
            if ($log)
                error_log('[Flow-Flow Migration 5.0] error_timestamp column already exists' . PHP_EOL, 3, $log);
        }

        // ========================================
        // Part 2: Add boost control features to posts table
        // ========================================
        $posts_table = $manager->posts_table_name;

        if ($log)
            error_log('[Flow-Flow Migration 5.0] Adding boost control features to posts table' . PHP_EOL, 3, $log);

        // Add is_pinned column
        if (!LADDLUtils::existColumn($conn, $posts_table, 'is_pinned')) {
            if ($log)
                error_log('[Flow-Flow Migration 5.0] Adding is_pinned column' . PHP_EOL, 3, $log);
            LADDLUtils::addColumn($conn, $posts_table, 'is_pinned', 'TINYINT(1) DEFAULT 0');
        } else {
            if ($log)
                error_log('[Flow-Flow Migration 5.0] is_pinned column already exists' . PHP_EOL, 3, $log);
        }

        // Add pinned_order column
        if (!LADDLUtils::existColumn($conn, $posts_table, 'pinned_order')) {
            if ($log)
                error_log('[Flow-Flow Migration 5.0] Adding pinned_order column' . PHP_EOL, 3, $log);
            LADDLUtils::addColumn($conn, $posts_table, 'pinned_order', 'INT DEFAULT 0');
        } else {
            if ($log)
                error_log('[Flow-Flow Migration 5.0] pinned_order column already exists' . PHP_EOL, 3, $log);
        }

        // Add index for performance (check if index exists first)
        $indexes = $conn->getAll("SHOW INDEX FROM ?n WHERE Key_name = 'idx_pinned'", $posts_table);
        if (empty($indexes)) {
            if ($log)
                error_log('[Flow-Flow Migration 5.0] Adding idx_pinned index' . PHP_EOL, 3, $log);
            $conn->query("ALTER TABLE ?n ADD INDEX idx_pinned (`is_pinned`, `pinned_order`)", $posts_table);
        } else {
            if ($log)
                error_log('[Flow-Flow Migration 5.0] idx_pinned index already exists' . PHP_EOL, 3, $log);
        }

        // Extend post_additional to TEXT for larger CTA data (if it's VARCHAR)
        if (LADDLUtils::existColumn($conn, $posts_table, 'post_additional')) {
            $columnInfo = $conn->getRow("SHOW COLUMNS FROM ?n WHERE Field = 'post_additional'", $posts_table);
            if ($columnInfo && strpos($columnInfo['Type'], 'varchar') !== false) {
                if ($log)
                    error_log('[Flow-Flow Migration 5.0] Extending post_additional to TEXT' . PHP_EOL, 3, $log);
                $conn->query("ALTER TABLE ?n MODIFY COLUMN `post_additional` TEXT", $posts_table);
            } else {
                if ($log)
                    error_log('[Flow-Flow Migration 5.0] post_additional already TEXT or not varchar' . PHP_EOL, 3, $log);
            }
        }

        // ========================================
        // Part 3: Update disable-proxy-server default to 'yep'
        // ========================================
        if ($log)
            error_log('[Flow-Flow Migration 5.0] Checking disable-proxy-server setting' . PHP_EOL, 3, $log);

        $options = $manager->getOption('options', true);
        if ($options !== false) {
            // If the setting exists and is set to 'nope', update it to 'yep'
            if (
                isset($options['general-settings-disable-proxy-server']) &&
                $options['general-settings-disable-proxy-server'] === 'nope'
            ) {
                if ($log)
                    error_log('[Flow-Flow Migration 5.0] Updating disable-proxy-server from nope to yep' . PHP_EOL, 3, $log);

                $options['general-settings-disable-proxy-server'] = 'yep';
                $manager->setOption('options', $options, true);

                if ($log)
                    error_log('[Flow-Flow Migration 5.0] Successfully updated disable-proxy-server setting' . PHP_EOL, 3, $log);
            } else {
                if ($log) {
                    $current = isset($options['general-settings-disable-proxy-server']) ?
                        $options['general-settings-disable-proxy-server'] : 'not set';
                    error_log('[Flow-Flow Migration 5.0] disable-proxy-server already set to: ' . $current . PHP_EOL, 3, $log);
                }
            }
        }

        if ($log) {
            error_log('[Flow-Flow Migration 5.0] Migration completed successfully' . PHP_EOL, 3, $log);
        }
    }
}

// phpcs:enable
