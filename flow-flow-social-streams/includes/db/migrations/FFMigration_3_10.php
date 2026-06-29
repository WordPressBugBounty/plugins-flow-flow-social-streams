<?php namespace flow\db\migrations;
if ( ! defined( 'WPINC' ) ) die;

use la\core\db\LADDLUtils;
use la\core\db\migrations\ILADBMigration;

/**
 * Flow-Flow
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>
 *
 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
class FFMigration_3_10 implements ILADBMigration {

	public function version() {
		return '3.10';
	}

	public function execute( $conn, $manager ) {
        LADDLUtils::addColumnIfNotExist($conn, $manager->cache_table_name, 'boosted', "VARCHAR(4) DEFAULT 'nope'");
	}
}