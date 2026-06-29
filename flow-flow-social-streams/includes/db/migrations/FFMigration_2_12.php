<?php namespace flow\db\migrations;
use la\core\db\LADDLUtils;
use la\core\db\migrations\ILADBMigration;

if ( ! defined( 'WPINC' ) ) die;
/**
 * FlowFlow.
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>
 *
 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
class FFMigration_2_12 implements ILADBMigration{

	public function version() {
		return '2.12';
	}

	public function execute($conn, $manager) {
		if (LADDLUtils::existColumn($conn, $manager->streams_table_name, 'status')){
            LADDLUtils::dropColumn($conn, $manager->streams_table_name, 'status');
		}
	}
}