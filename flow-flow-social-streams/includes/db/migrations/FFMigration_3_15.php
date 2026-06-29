<?php
// phpcs:disable
namespace flow\db\migrations;
use la\core\db\migrations\ILADBMigration;
use la\core\db\LADDLUtils;
if (!defined('WPINC'))
    die;
/**
 * FlowFlow.
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>
 *
 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
class FFMigration_3_15 implements ILADBMigration
{

    public function version()
    {
        return '3.15';
    }

    public function execute($conn, $manager)
    {
        // Add error_timestamp column to cache table to track when errors occurred
        $cache_table = $manager->cache_table_name;
        $log = defined('FF_LOG_FILE_DEST') ? FF_LOG_FILE_DEST : null;

        if ($log)
            error_log('[Flow-Flow Migration 3.15] Starting migration for error_timestamp column' . PHP_EOL, 3, $log);

        // Check if column already exists
        if (!LADDLUtils::existColumn($conn, $cache_table, 'error_timestamp')) {
            if ($log)
                error_log('[Flow-Flow Migration 3.15] Column error_timestamp does not exist, adding it now' . PHP_EOL, 3, $log);
            $sql = "ALTER TABLE ?n ADD COLUMN `error_timestamp` INT DEFAULT 0 AFTER `errors`";
            $result = $conn->query($sql, $cache_table);
            if ($result) {
                if ($log)
                    error_log('[Flow-Flow Migration 3.15] Successfully added error_timestamp column' . PHP_EOL, 3, $log);
            } else {
                if ($log)
                    error_log('[Flow-Flow Migration 3.15] FAILED to add error_timestamp column' . PHP_EOL, 3, $log);
            }
        } else {
            if ($log)
                error_log('[Flow-Flow Migration 3.15] Column error_timestamp already exists, skipping' . PHP_EOL, 3, $log);
        }
    }
}

// phpcs:enable
