<?php namespace la\core\db;
if ( ! defined( 'WPINC' ) ) die;

/**
 * FlowFlow.
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>
 *
 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 *
 * @noinspection PhpUndefinedClassInspection
 */
class LAWPDB extends \wpdb{
    /** @noinspection PhpUndefinedFieldInspection */
    public function get_connection(){
        return $this->dbh;
    }
} 