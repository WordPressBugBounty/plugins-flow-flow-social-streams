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
class FFMigration_3_2 implements ILADBMigration{
	public function version(){
		return '3.2';
	}
	
	public function execute($conn, $manager){
        LADDLUtils::addColumnIfNotExist($conn, $manager->posts_table_name, 'carousel_size', 'INT DEFAULT 0 NOT NULL');

		if (!LADDLUtils::existTable($conn, $manager->post_media_table_name)){
			$sql = "CREATE TABLE ?n
			(
				`id` INT NOT NULL AUTO_INCREMENT,
				`feed_id` VARCHAR(20) NOT NULL,
				`post_id` VARCHAR(50) NOT NULL,
				`post_type` VARCHAR(10) NOT NULL,
				`media_url` TEXT,
				`media_width` INT,
				`media_height` INT,
				`media_type` VARCHAR(100),
				PRIMARY KEY (`id`)
			) ?p";
			$conn->query($sql, $manager->post_media_table_name, $this->charset());
		}
	}
	
	private function charset(){
		$charset = LADDLUtils::charset();
		if ( !empty( $charset ) ) {
			$charset = " CHARACTER SET {$charset}";
		}
		return $charset;
	}
}