<?php namespace flow\db\migrations;
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
class FFMigration_3_14 implements ILADBMigration{

    public function version() {
        return '3.14';
    }

    public function execute($conn, $manager) {
        $options = $manager->getOption('options', true);
        if ($options === false) $options = array();
        if (!isset($options['tiktok_access_token'])) $options['tiktok_access_token'] = '';
        $manager->setOption('options', $options, true);
    }
}
