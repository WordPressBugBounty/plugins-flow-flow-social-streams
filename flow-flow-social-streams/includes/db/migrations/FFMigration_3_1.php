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
class FFMigration_3_1 implements ILADBMigration{
	public function version(){
		return '3.1';
	}
	
	public function execute($conn, $manager){
        LADDLUtils::addColumnIfNotExist($conn, $manager->posts_table_name, 'user_bio', 'VARCHAR(200) NULL');
        LADDLUtils::addColumnIfNotExist($conn, $manager->posts_table_name, 'user_counts_media', 'INT NULL');
        LADDLUtils::addColumnIfNotExist($conn, $manager->posts_table_name, 'user_counts_follows', 'INT NULL');
        LADDLUtils::addColumnIfNotExist($conn, $manager->posts_table_name, 'user_counts_followed_by', 'INT NULL');
        LADDLUtils::addColumnIfNotExist($conn, $manager->posts_table_name, 'location', 'VARCHAR(300) NULL');
	}
}