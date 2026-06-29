<?php namespace la\core\cache;
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
class LAModerationCacheManager extends LACacheManager{
    function __construct( $context = null, $force = false ) {
        parent::__construct( $context, $force );
    }

    protected function getGetFilters() {
        $args = parent::getGetFilters();
        $args[] = $this->db->conn()->parse('post.post_status = ?s', 'approved');
        return $args;
    }
}