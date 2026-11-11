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
    /** @var bool set once close() has run, so later calls stay no-ops */
    private $closed = false;
    private $transactionActive = false;
    private $autoCommitEnabled = true;

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

    public function inTransaction() {
        return $this->transactionActive;
    }

    protected function rawQuery($query) {
        // COMMIT leaves autocommit disabled. The next query can start another
        // transaction, which must also remain on this physical connection.
        if (!$this->autoCommitEnabled) $this->transactionActive = true;
        return parent::rawQuery($query);
    }

    /**
     * @param bool $mode
     * @return bool
     */
    public function autocommit($mode){
        if (!$this->isOpen()) return false;
        $result = $this->conn->autocommit($mode);
        if ($result) {
            $this->autoCommitEnabled = (bool)$mode;
            $this->transactionActive = !$mode;
        }
        return $result;
    }

    public function commit() {
        if (!$this->isOpen()) return false;
        $result = $this->conn->commit();
        if ($result) $this->transactionActive = false;
        return $result;
    }

    public function rollback() {
        try {
            if (!$this->isOpen()) return false;
            if ($this->conn->rollback() && $this->autocommit(true)) return true;
        } catch (\Throwable $error) {
            // A disconnected transaction cannot be resumed. Closing releases
            // the guard so the caller can record failure on a new connection.
            try { $this->close(); } catch (\Throwable $ignored) {}
            throw $error;
        }
        $this->close();
        return false;
    }

    public function rollbackAndClose() {
        try {
            return $this->rollback();
        } finally {
            $this->close();
        }
    }

    public function close() {
        $open = $this->isOpen();
        $this->closed = true;
        $this->transactionActive = false;
        $this->autoCommitEnabled = true;
        if (!$open) return false;
        return $this->conn->close();
    }

    /**
     * Whether the underlying handle is still usable. Never reads a property of a
     * closed mysqli: PHP 8 throws an Error there, which @ does not suppress.
     *
     * @return bool
     */
    private function isOpen() {
        return !$this->closed && $this->conn instanceof \mysqli;
    }

    public function getError() {
        return $this->conn->error;
    }
}
