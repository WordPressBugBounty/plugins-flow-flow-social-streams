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
class FFMigration_3_17 implements ILADBMigration{

    public function version() {
        return '3.17';
    }

    public function execute($conn, $manager) {
        $options = $manager->getOption('options', true);
        if ($options === false) $options = array();
        
        // LinkedIn OAuth fields
        if (!isset($options['linkedin_access_token'])) $options['linkedin_access_token'] = '';
        if (!isset($options['linkedin_refresh_token'])) $options['linkedin_refresh_token'] = '';
        if (!isset($options['linkedin_expires_in'])) $options['linkedin_expires_in'] = '';
        if (!isset($options['linkedin_username'])) $options['linkedin_username'] = '';
        if (!isset($options['linkedin_display_name'])) $options['linkedin_display_name'] = '';
        if (!isset($options['linkedin_member_id'])) $options['linkedin_member_id'] = '';
        if (!isset($options['linkedin_userpic'])) $options['linkedin_userpic'] = '';
        
        $manager->setOption('options', $options, true);
    }
}
