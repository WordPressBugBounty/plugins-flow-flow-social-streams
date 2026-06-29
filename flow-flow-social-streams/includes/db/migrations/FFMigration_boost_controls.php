<?php namespace flow\db\migrations;

use la\core\db\LADDLUtils;
use la\core\db\migrations\ILADBMigration;

if ( ! defined( 'WPINC' ) ) die;

/**
 * Migration to add boost control features: pinned posts and CTA buttons
 * 
 * Adds:
 * - is_pinned column to posts table
 * - pinned_order column to posts table  
 * - Index for pinned posts
 * - Extends post_additional to TEXT for larger CTA data
 */
class FFMigration_boost_controls implements ILADBMigration {
    
    public function version() {
        return '4.9.7';
    }
    
    public function execute($conn, $manager) {
        $table = $manager->posts_table_name;
        
        // Add is_pinned column
        LADDLUtils::addColumnIfNotExist($conn, $table, 'is_pinned', 'TINYINT(1) DEFAULT 0');
        
        // Add pinned_order column
        LADDLUtils::addColumnIfNotExist($conn, $table, 'pinned_order', 'INT DEFAULT 0');
        
        // Add index for performance (check if index exists first)
        $indexes = $conn->getAll("SHOW INDEX FROM ?n WHERE Key_name = 'idx_pinned'", $table);
        if (empty($indexes)) {
            $conn->query("ALTER TABLE ?n ADD INDEX idx_pinned (`is_pinned`, `pinned_order`)", $table);
        }
        
        // Extend post_additional to TEXT for larger CTA data (if it's VARCHAR)
        if (LADDLUtils::existColumn($conn, $table, 'post_additional')) {
            $columnInfo = $conn->getRow("SHOW COLUMNS FROM ?n WHERE Field = 'post_additional'", $table);
            if ($columnInfo && strpos($columnInfo['Type'], 'varchar') !== false) {
                $conn->query("ALTER TABLE ?n MODIFY COLUMN `post_additional` TEXT", $table);
            }
        }
    }
}
