<?php namespace flow\tabs;

use la\core\LAUtils;
use la\core\snapshots\LASnapshotManager;
use la\core\tabs\LATab;

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
class FFBackupTab implements LATab {
	public function __construct() {
	}

	public function id() {
		return 'backup-tab';
	}

	public function flaticon() {
		return 'flaticon-data';
	}

	public function title() {
		return 'Database';
	}

	public function includeOnce( $context ) {
		$manager            = new LASnapshotManager( $context );
		$context['backups'] = $manager->getSnapshots();
		/** @noinspection PhpIncludeInspection */
		include_once(LAUtils::root($context)  . 'views/backup.php');
	}
}