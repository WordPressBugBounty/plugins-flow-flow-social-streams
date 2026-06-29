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
class FFMigration_2_5 implements ILADBMigration{

	public function version() {
		return '2.5';
	}

	public function execute($conn, $manager) {
        LADDLUtils::addColumnIfNotExist($conn, $manager->posts_table_name, 'post_timestamp', 'INT');
        if (LADDLUtils::existColumn($conn, $manager->posts_table_name, 'post_date')){
            LADDLUtils::dropColumn($conn, $manager->posts_table_name, 'post_date');
        }

        // REMOVED: $conn->query('DELETE FROM ?n', $manager->cache_table_name);
        // The DELETE statement was causing data loss when repairDB() force-ran all migrations
        // Since all operations here are now idempotent (check before modify), this migration can safely be re-run
	}
}