<?php
// phpcs:disable
 namespace la\core\snapshots;
if ( ! defined( 'WPINC' ) ) die;

use Exception;
use la\core\db\LADB;
use la\core\db\LADBManager;
use la\core\LAUtils;
use stdClass;

/**
 * Flow-Flow
 *
 * Plugin class. This class should ideally be used to work with the
 * public-facing side of the WordPress site.
 *
 * If you're interested in introducing administrative or dashboard
 * functionality, then refer to `FlowFlowAdmin.php`
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>

 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
class LASnapshotManager {
    const VERSION = '2.10';

    private $context;

    public function __construct($context) {
        $this->context = $context;

        // secured endpoints
        add_action('wp_ajax_create_backup',  [ $this, 'processAjaxRequest' ] );
        add_action('wp_ajax_restore_backup', [ $this, 'processAjaxRequest' ] );
        add_action('wp_ajax_delete_backup',  [ $this, 'processAjaxRequest' ] );
    }

    public function getSnapshots(){
        $result = [];
        $dbm = LAUtils::dbm($this->context);
        $rows = $dbm->conn()->getAll('SELECT * FROM ?n ORDER BY `creation_time` DESC', $dbm->snapshot_table_name);

        foreach ( $rows as $row ) {
            $sn = new stdClass();
            $sn->id = $row['id'];
            $sn->description = $row['description'];
            $sn->creation_time = $row['creation_time'];
            $sn->settings = $row['settings'];
            $sn->version = $row['version'];
            $sn->outdated = version_compare(self::VERSION, $row['version'], '>=');
            $result[] = $sn;
        }
        return $result;
    }

    /**
     * @throws Exception
     */
    public function processAjaxRequest() {
        $result = [];

        if (FF_USE_WP) {
            if (!current_user_can('manage_options') && !check_ajax_referer( 'flow_flow_nonce', 'security', false ) ) {
                die( json_encode( [ 'error' => 'not_allowed' ] ) );
            }
        }

        if (isset($_REQUEST['action'])){
            $dbm = LAUtils::dbm($this->context);
            $dbm->dataInit();
            $conn = $dbm->conn();

            try{
                if (false === $conn->beginTransaction()) throw new Exception('Don`t started transaction');

                switch ($_REQUEST['action']){
                    case 'create_backup':
                        $result = $this->createBackup($dbm);
                        break;
                    case 'restore_backup':
                        $result = $this->restoreBackup($dbm);
                        break;
                    case 'delete_backup':
                        $result = $this->deleteBackup($dbm);
                        break;
                }
                $conn->commit();
            }
            catch ( Exception $e){
                $conn->rollbackAndClose();
                error_log($e->getMessage());
                error_log($e->getTraceAsString());

                switch ($_REQUEST['action']){
                    case 'create_backup':
                        $result = [ 'backed_up' => false ];
                        break;
                    case 'restore_backup':
                        $result = [ 'restore' => false ];
                        break;
                    case 'delete_backup':
                        $result = [ 'deleted' => false ];
                        break;
                }
            }

        }
        echo json_encode($result);
        die();
    }

    /**
     * @param LADBManager $dbm
     *
     * @return array
     * @throws Exception
     */
    public function createBackup ($dbm) {
        $all = [];
        $description = '';//TODO add description for snapshot

        $conn = $dbm->conn();
        $options = $conn->getAll('SELECT `id`, `value` FROM ?n', $dbm->option_table_name);
        foreach ( $options as $option ) {
            $all[$option['id']] = $option['value'];
        }
        $all['streams'] = $dbm->streams();
        $all['sources'] = $dbm->sources();
        $result = gzcompress(serialize($all), 6);

        $conn->query("INSERT INTO ?n (`description`, `settings`, `dump`, `version`) VALUES(?s, ?s, ?s, ?s)", $dbm->snapshot_table_name, $description, '', $result, $this->context['version']);

        return [ 'backed_up' => true, 'result' => $conn->affectedRows() ];
    }

    /**
     * @param LADBManager $dbm
     *
     * @return array
     * @throws Exception
     */
    public function restoreBackup ($dbm) {
        $conn = $dbm->conn();
        if (false !== ($dump = $conn->getOne('SELECT `dump` FROM ?n WHERE id=?s', $dbm->snapshot_table_name, $_REQUEST['id']))){
            $all = gzuncompress($dump);
            $all = unserialize($all);
            unset($dump);

            foreach ( $dbm->sources() as $id => $source ) {
                $dbm->deleteFeed($id);
            }

            foreach ( $all['sources'] as $source ) {
                $dbm->modifySource($source);
            }
            unset($all['sources']);

            $dbm->dataInit();

            foreach ( $dbm->streams() as $stream ) {
                LADB::deleteStream($conn, $dbm->streams_table_name, $dbm->streams_sources_table_name, $stream['id']);
            }

            foreach ( $all['streams'] as $stream ) {
                $obj = (object)$stream;
                LADB::setStream($conn, $dbm->streams_table_name, $dbm->streams_sources_table_name, $obj->id, $obj);
                $dbm->generateCss($obj);
            }
            unset($all['streams']);

            foreach ( $all as $key => $value ) {
                $key = strpos($key, 'flow_flow_') === 0 ? str_replace('flow_flow_', '', $key) : $key;
                $dbm->setOption($key, $value);
            }
            $dbm->clean();
            return [ 'restore' => true ];
        }
        else {
            return [ 'found' => false ];
        }
    }

    /**
     * @param LADBManager $dbm
     * @return array
     */
    public function deleteBackup ($dbm) {
        $op = $dbm->conn()->query ('DELETE FROM ?n WHERE `id`=?s', $dbm->snapshot_table_name, $_REQUEST['id']);
        return [ 'deleted' => ( false !== $op) ];
    }
}
// phpcs:enable
