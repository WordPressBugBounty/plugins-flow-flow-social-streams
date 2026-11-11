<?php namespace flow\db\migrations;
if (!defined('WPINC')) die;

use la\core\db\LADDLUtils;
use la\core\db\migrations\ILADBMigration;

/** Reconcile schema omitted by historical fresh-install initialization. */
class FFMigration_5_2 implements ILADBMigration {
    public function version() { return '5.2'; }

    public function execute($conn, $manager) {
        $columns = [
            [$manager->cache_table_name, 'error_timestamp', 'INT DEFAULT 0'],
            [$manager->posts_table_name, 'is_pinned', 'TINYINT(1) DEFAULT 0'],
            [$manager->posts_table_name, 'pinned_order', 'INT DEFAULT 0'],
        ];
        foreach ($columns as $column) {
            list($table, $name, $definition) = $column;
            if (!LADDLUtils::existColumn($conn, $table, $name)
                && false === LADDLUtils::addColumn($conn, $table, $name, $definition)) {
                throw new \RuntimeException('Unable to add schema column ' . $name);
            }
        }

        $posts = $manager->posts_table_name;
        $indexes = $conn->getAll("SHOW INDEX FROM ?n WHERE Key_name = 'idx_pinned'", $posts);
        if (!$indexes && false === $conn->query('ALTER TABLE ?n ADD INDEX idx_pinned (`is_pinned`, `pinned_order`)', $posts)) {
            throw new \RuntimeException('Unable to add idx_pinned index');
        }

        $additional = $conn->getRow("SHOW COLUMNS FROM ?n WHERE Field = 'post_additional'", $posts);
        if (!$additional) {
            // Legacy tables stamped past 2.13 never received the column. Add it
            // here: forced repair replays this migration before 2.13 would.
            if (false === LADDLUtils::addColumn($conn, $posts, 'post_additional', 'TEXT')) {
                throw new \RuntimeException('Unable to add schema column post_additional');
            }
        } elseif (stripos($additional['Type'], 'varchar') === 0
            && false === $conn->query('ALTER TABLE ?n MODIFY COLUMN `post_additional` TEXT', $posts)) {
            throw new \RuntimeException('Unable to widen post_additional');
        }
    }
}
