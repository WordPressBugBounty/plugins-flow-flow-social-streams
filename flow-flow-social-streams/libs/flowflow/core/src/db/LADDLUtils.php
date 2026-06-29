<?php namespace la\core\db;


/**
 *
 * @author    navdeykin <navdeykin@gmail.com>
 * @copyright 2014-2020 Looks Awesome
 */
class LADDLUtils {
    /**
     * @param LASafeMySQL $conn
     * @param string $table_name
     * @return bool
     */
    public static function existTable($conn, $table_name){
        return $conn->getOne('SHOW TABLES LIKE ?s', $table_name) !== false;
    }

    /**
     * @param LASafeMySQL $conn
     * @param string $table_name
     */
    public static function dropTable($conn, $table_name){
        $conn->query('DROP TABLE ?n', $table_name);
    }

    /**
     * @param LASafeMySQL $conn
     * @param string $table_name
     * @param $column_name
     */
    public static function dropColumn($conn, $table_name, $column_name){
        $conn->query('ALTER TABLE ?n DROP ?n', $table_name, $column_name);
    }

    /**
     * @param LASafeMySQL $conn
     * @param string $table_name
     * @param $column_name
     *
     * @return bool
     */
    public static function existColumn($conn, $table_name, $column_name){
        return $conn->getOne('SHOW COLUMNS FROM ?n LIKE ?s', $table_name, $column_name) !== false;
    }

    /**
     * @param LASafeMySQL $conn
     * @param string $table_name
     * @param string $column_name
     * @param string $type
     *
     * @return bool
     */
    public static function addColumn( $conn, $table_name, $column_name, $type ) {
        $sql = 'ALTER TABLE ?n ADD COLUMN ?n ' . $type;
        return $conn->query($sql, $table_name, $column_name);
    }

    /**
     * @param LASafeMySQL $conn
     * @param string $table_name
     * @param string $column_name
     * @param string $type
     *
     * @return bool
     */
    public static function addColumnIfNotExist( $conn, $table_name, $column_name, $type ) {
        if (!self::existColumn($conn, $table_name, $column_name)){
            self::addColumn($conn, $table_name, $column_name, $type);
        }
    }

    /** @return string */
    public static function charset(){
        if (FF_USE_WP){
            /** @var object $wpdb */
            $wpdb = $GLOBALS['wpdb'];
            return $wpdb->charset;
        }
        return DB_CHARSET; // @codeCoverageIgnore
    }

    /** @return string */
    public static function collate() {
        if (FF_USE_WP){
            /** @var object $wpdb */
            $wpdb = $GLOBALS['wpdb'];
            return $wpdb->collate;
        }
        return DB_COLLATE; // @codeCoverageIgnore
    }
}