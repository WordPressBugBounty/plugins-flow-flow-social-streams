<?php namespace flow\db\migrations;

use la\core\db\migrations\ILADBMigration;

if ( ! defined( 'WPINC' ) ) die;

/**
 * Flow-Flow
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>
 *
 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
class FFMigration_3_12 implements ILADBMigration{
	public function version() {
		return '3.12';
	}

	public function execute( $conn, $manager ) {
		$conn->query('ALTER TABLE ?n MODIFY ?n VARCHAR(700)', $manager->posts_table_name, 'user_pic');
	}
}