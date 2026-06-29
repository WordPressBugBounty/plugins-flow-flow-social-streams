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
class FFMigration_2_3 implements ILADBMigration{

	public function version() {
		return '2.3';
	}

	public function execute($conn, $manager) {
		if (!LADDLUtils::existTable($conn, $manager->image_cache_table_name)){
			$charset_collate = '';
			$charset = LADDLUtils::charset();
			if ( !empty( $charset ) ) {
				$charset_collate = "DEFAULT CHARACTER SET {$charset}";
			}
			$collate = LADDLUtils::collate();
			if ( !empty( $collate ) ) {
				$charset_collate .= " COLLATE {$collate}";
			}

			$sql = "CREATE TABLE ?n ( `url` VARCHAR(50) NOT NULL, `width` INT, `height` INT, `creation_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`url`) ) $charset_collate";
			$conn->query($sql, $manager->image_cache_table_name);
		}

        LADDLUtils::addColumnIfNotExist($conn, $manager->table_prefix . 'snapshots', 'dump', 'BLOB NULL');
	}
}