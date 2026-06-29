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
class FFMigration_2_14 implements ILADBMigration{

	public function version() {
		return '2.14';
	}

	public function execute($conn, $manager) {
		$tableName = $manager->image_cache_table_name;
		LADDLUtils::addColumnIfNotExist($conn, $tableName, 'original_url', 'VARCHAR(300)');

		$tableName = str_replace('ff_image_cache', 'wss_image_cache', $tableName);
		if (LADDLUtils::existTable($conn, $tableName)){
            LADDLUtils::addColumnIfNotExist($conn, $tableName, 'original_url', 'VARCHAR(300)');
		}
	}
}