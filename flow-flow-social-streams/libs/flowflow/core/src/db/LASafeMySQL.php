<?php namespace la\core\db;

use mysqli;
use SafeMySQL;

/**
 * @property mysqli $conn
 *
 * @author    navdeykin <navdeykin@gmail.com>
 * @copyright 2014-2020 Looks Awesome
 */
class LASafeMySQL extends SafeMySQL {
    function __construct( $opt = [] ) {
        if (FF_USE_WP && defined('FF_USE_WPDB') && FF_USE_WPDB == true) {
            require_once('LAWPDB.php');
            $wpdb = new LAWPDB($opt['user'], $opt['pass'], $opt['db'], $opt['host']);
            @$this->conn = $wpdb->get_connection();
        }
        else {
            $host = $opt['host'];
            $port_or_socket = strstr( $host, ':' );
            if ( ! empty( $port_or_socket ) ) {
                $opt['host'] = substr( $host, 0, strpos( $host, ':' ) );
                $port_or_socket = substr( $port_or_socket, 1 );
                if ( 0 !== strpos( $port_or_socket, '/' ) ) {
                    $opt['port'] = intval( $port_or_socket );
                    $maybe_socket = strstr( $port_or_socket, ':' );
                    if ( ! empty( $maybe_socket ) ) {
                        $opt['socket'] = substr( $maybe_socket, 1 );
                    }
                } else {
                    $opt['socket'] = $port_or_socket;
                }
            }
            parent::__construct($opt);
        }
    }

    public function getIndMultiRow()
    {
        $args  = func_get_args();
        $index = array_shift($args);
        $query = $this->prepareQuery($args);

        $ret = [];
        if ( $res = $this->rawQuery($query) )
        {
            /** @noinspection PhpParamsInspection */
            while($row = $this->fetch($res))
            {
                if (!isset($ret[$row[$index]])) $ret[$row[$index]] = [];
                $ret[$row[$index]][] = $row;
            }
            $this->free($res);
        }
        return $ret;
    }

    /**
     * @return mysqli
     */
    public function getMySQLi() {
        return $this->conn;
    }

    /**
     * Helper function to get a dictionary-style array right out of query and optional arguments
     *
     * Examples:
     * $data = $db->getIndCol("name", "SELECT name, id FROM cities");
     *
     * @internal param string $index - name of the field which value is used to index resulting array
     * @internal param string $query - an SQL query with placeholders
     * @internal param mixed $arg unlimited number of arguments to match placeholders in the query
     * @return array - associative array contains key=value pairs out of result set. Empty if no rows found.
     */
    public function getIndCol()
    {
        $args  = func_get_args();
        $index = array_shift($args);
        $query = $this->prepareQuery($args);

        $ret = [];
        if ( $res = $this->rawQuery($query) )
        {
            /** @noinspection PhpParamsInspection */
            while($row = $this->fetch($res))
            {
                $key = $row[$index];
                unset($row[$index]);
                $ret[$key] = (is_array($row) && sizeof($row) > 1) ? $row : reset($row);
            }
            $this->free($res);
        }
        return $ret;
    }

    public function beginTransaction() {
        return $this->autocommit(false);
    }

    /**
     * @param bool $mode
     * @return bool
     */
    public function autocommit($mode){
        return $this->conn->autocommit($mode);
    }

    public function commit() {
        return $this->conn->commit();
    }

    public function rollback() {
        $result = $this->conn->rollback();
        $this->autocommit(true);
        return $result;
    }

    public function rollbackAndClose() {
        $result = $this->rollback();
        $this->close();
        return $result;
    }

    public function close() {
        return $this->conn->close();
    }

    public function getError() {
        return $this->conn->error;
    }
}