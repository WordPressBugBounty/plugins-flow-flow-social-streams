<?php namespace flow\db\migrations;
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
class FFMigration_2_15 implements ILADBMigration{

	public function version() {
		return '2.15';
	}

	public function execute($conn, $manager) {
		$streams = $this->streams($conn, $manager->streams_table_name);
		foreach ( $streams as $stream ) {
			$stream = $this->getStream($conn, $manager->streams_table_name, $stream['id']);
			$manager->generateCss($stream);
		}
	}

	private function streams($conn, $table_name){
		if (false !== ($result = $conn->getAll('SELECT `id`, `name`, `value` FROM ?n ORDER BY `id`',
				$table_name))){
			return $result;
		}
		return [];
	}

	private function getStream($conn, $table_name, $id){
		if (false !== ($row = $conn->getRow('select `value`, `feeds` from ?n where `id`=?s', $table_name, $id))) {
			if ($row != null){
				$options = unserialize($row['value']);
				$options->feeds = $row['feeds'];
				return $options;
			}
		}
		return null;
	}
}