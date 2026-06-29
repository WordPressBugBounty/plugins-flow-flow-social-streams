<?php namespace flow\db\migrations;
if ( ! defined( 'WPINC' ) ) die;

use la\core\db\migrations\LADBMigrationBase;

/**
 * Flow-Flow.
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>
 *
 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
class FFMigration_init extends LADBMigrationBase{
    protected function create_posts_table( $conn, $table_name ) {
        $charset = $this->charset();
        $collate = $this->collate();
        $sql = "CREATE TABLE ?n
			(
				`feed_id` VARCHAR(20) NOT NULL,
				`post_id` VARCHAR(50) NOT NULL,
				`post_type` VARCHAR(10) NOT NULL,
				`post_text` BLOB,
				`post_permalink` VARCHAR(300),
				`post_header` VARCHAR(200){$collate},
				`user_nickname` VARCHAR(100){$collate},
				`user_screenname` VARCHAR(200){$collate},
				`user_pic` VARCHAR(700) NOT NULL,
				`user_link` VARCHAR(300),
				`rand_order` REAL,
				`creation_index` INT NOT NULL DEFAULT 0,
				`image_url` TEXT,
				`image_width` INT,
				`image_height` INT,
				`media_url` TEXT,
				`media_width` INT,
				`media_height` INT,
				`media_type` VARCHAR(100),
				`post_timestamp` INT,
				`smart_order` INT,
				`post_status` VARCHAR(15),
				`post_source` VARCHAR(300),
				`post_additional` VARCHAR(300),
				`user_bio` TEXT,
				`user_counts_media` INT,
				`user_counts_follows` INT,
				`user_counts_followed_by` INT,
				`location` TEXT,
				`carousel_size` INT,
				`post_content` BLOB DEFAULT NULL,
				PRIMARY KEY (`post_id`, `post_type`, `feed_id`)
			) ?p";
        $conn->query($sql, $table_name, $charset);
    }

    protected function create_cache_table( $conn, $table_name ) {
        $sql = "CREATE TABLE ?n
			(
				`feed_id` VARCHAR(20) NOT NULL,
				`last_update` INT NOT NULL,
				`status` INT NOT NULL DEFAULT 0,
				`errors` BLOB,
				`settings` BLOB,
				`enabled` TINYINT(1) DEFAULT 0,
				`system_enabled` TINYINT(1) DEFAULT 1,
				`changed_time` INT DEFAULT 0,
				`cache_lifetime` INT DEFAULT 60,
				`send_email` TINYINT(1) DEFAULT 0,
				`boosted` VARCHAR(4) DEFAULT 'nope',
				PRIMARY KEY (`feed_id`)
			) ?p";
        $conn->query($sql, $table_name, $this->charset());
    }
}