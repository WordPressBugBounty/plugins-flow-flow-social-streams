<?php namespace la\core\db\migrations;
use la\core\db\LADBManager;
use la\core\db\LASafeMySQL;

if ( ! defined( 'WPINC' ) ) die;

/**
 * Flow-Flow
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>

 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
interface ILADBMigration {
    /**
     * @return string
     */
    public function version();
    /**
     * @param LASafeMySQL $conn
     * @param LADBManager $manager
     */
    public function execute( $conn, $manager );
}