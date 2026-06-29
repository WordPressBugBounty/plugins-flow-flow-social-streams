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
class FFMigration_2_17 implements ILADBMigration{

	public function version() {
		return '2.17';
	}

	public function execute( $conn, $manager ) {
		if (!LADDLUtils::existColumn($conn, $manager->snapshot_table_name, 'version')){
			$sql = "ALTER TABLE ?n ADD COLUMN ?n VARCHAR(25) DEFAULT '2.0'";
			$conn->query($sql, $manager->snapshot_table_name, 'version');
		}
	}
}