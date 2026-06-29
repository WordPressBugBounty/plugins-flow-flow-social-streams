<?php
// phpcs:disable
 namespace flow\db\migrations;
use Exception;
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
class FFMigration_2_8 implements ILADBMigration{

	public function version() {
		return '2.8';
	}

	public function execute($conn, $manager) {
        LADDLUtils::addColumnIfNotExist($conn, $manager->posts_table_name, 'smart_order', 'INT NULL');

		if (false !== ($feeds = $conn->getCol('SELECT DISTINCT `feed_id` FROM ?n', $manager->posts_table_name))){
			foreach ( $feeds as $feed ) {
				if (false === ($posts = $conn->getCol('SELECT `post_id` FROM ?n WHERE `feed_id` = ?s ORDER BY post_timestamp DESC', $manager->posts_table_name, $feed))){
					throw new Exception($conn->getError());
				}
				$index = 0;
				foreach ( $posts as $post ) {
					if (false === $conn->query('UPDATE ?n SET `smart_order` = ?i WHERE `feed_id` = ?s AND `post_id` = ?s',
							$manager->posts_table_name, $index, $feed, $post)){
						throw new Exception($conn->getError());
					}
					$index++;
				}
			}
		}
	}
}
// phpcs:enable
