<?php namespace flow\db\migrations;
use la\core\db\LADDLUtils;
use la\core\db\migrations\ILADBMigration;

if ( ! defined( 'WPINC' ) ) die;
/**
 * Flow-Flow.
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>
 *
 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
class FFMigration_2_18 implements ILADBMigration{

	public function version() {
		return '2.18';
	}

	public function execute( $conn, $manager ) {
        LADDLUtils::addColumnIfNotExist($conn, $manager->cache_table_name, 'system_enabled', 'TINYINT(1) DEFAULT 1');
	}
}