<?php namespace flow\db;
if ( ! defined( 'WPINC' ) ) die;

use la\core\db\LADBMigrationManager;

/**
 * Insta Flow.
 *
 * @package   Insta_Flow
 * @author    Looks Awesome <email@looks-awesome.com>
 *
 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
class FFDBMigrationManager extends LADBMigrationManager{
    protected function migrations(){
        $result = [];
        foreach ( glob($this->context['root'] . 'includes/db/migrations/FFMigration_*.php') as $filename ) {
            $result[] = 'flow\\db\\migrations\\' . basename($filename, ".php");
        }
        return $result;
    }
}