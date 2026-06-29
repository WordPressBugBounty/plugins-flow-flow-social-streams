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
class FFMigration_3_16 implements ILADBMigration
{

    public function version()
    {
        return '3.16';
    }

    public function execute($conn, $manager)
    {
        // Ensure error_timestamp column exists in cache table
        // This migration re-attempts adding the column in case 3.15 migration was skipped
        $cache_table = $manager->cache_table_name;
        $log = defined('FF_LOG_FILE_DEST') ? FF_LOG_FILE_DEST : null;

        if ($log)
            error_log('[Flow-Flow Migration 3.16] Checking for error_timestamp column' . PHP_EOL, 3, $log);

        // Check if column already exists
        if (!LADDLUtils::existColumn($conn, $cache_table, 'error_timestamp')) {
            if ($log)
                error_log('[Flow-Flow Migration 3.16] Column error_timestamp missing, adding it' . PHP_EOL, 3, $log);
            try {
                $sql = "ALTER TABLE ?n ADD COLUMN `error_timestamp` INT DEFAULT 0 AFTER `errors`";
                $result = $conn->query($sql, $cache_table);
                if ($result) {
                    if ($log)
                        error_log('[Flow-Flow Migration 3.16] Successfully added error_timestamp column' . PHP_EOL, 3, $log);
                } else {
                    if ($log)
                        error_log('[Flow-Flow Migration 3.16] FAILED to add error_timestamp column - query returned false' . PHP_EOL, 3, $log);
                }
            } catch (\Exception $e) {
                if ($log)
                    error_log('[Flow-Flow Migration 3.16] EXCEPTION adding error_timestamp column: ' . $e->getMessage() . PHP_EOL, 3, $log);
                throw $e;
            }
        } else {
            if ($log)
                error_log('[Flow-Flow Migration 3.16] Column error_timestamp already exists' . PHP_EOL, 3, $log);
        }
    }
}

// phpcs:enable
