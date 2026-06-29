<?php
// phpcs:disable
namespace la\core\db;
if (!defined('WPINC'))
    die;

use Exception;
use la\core\db\migrations\ILADBMigration;
use la\core\LAUtils;
use ReflectionClass;
use ReflectionException;

/**
 * Flow-Flow.
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>

 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
abstract class LADBMigrationManager
{
    const INIT_MIGRATION = '0.9999';

    protected $context;

    public function __construct($context)
    {
        $this->context = $context;
    }

    /**
     * @param bool $force
     * @throws ReflectionException
     * @throws Exception
     */
    public final function migrate($force = false)
    {
        $log = defined('FF_LOG_FILE_DEST') ? FF_LOG_FILE_DEST : null;
        $version = $this->getDBVersion();
        if ($log) {
            //error_log('[Flow-Flow Migration Manager] Current DB version: ' . $version . PHP_EOL, 3, $log);
        }

        if (!$force && !$this->hasMigrations4Perform($version)) {
            if ($log) {
                //error_log('[Flow-Flow Migration Manager] No migrations to perform' . PHP_EOL, 3, $log);
            }
            return;
        }

        if ($log) {
            error_log('[Flow-Flow Migration Manager] Starting migration process' . PHP_EOL, 3, $log);
        }

        $dbm = LAUtils::dbm($this->context);
        $conn = $this->connection();
        try {
            if ($conn->autocommit(false)) {
                if ($this->needStartInitMigration($version) || $force) {
                    if ($log)
                        error_log('[Flow-Flow Migration Manager] Running' . ($force ? ' FORCED ' : ' ') . 'INIT migration' . PHP_EOL, 3, $log);
                    foreach ($this->getInitMigration() as $max_version => $migration) {
                        $migration->execute($conn, $dbm);
                        if (!$force) $dbm->setOption('db_version', $max_version);
                    }
                    
                    if ($force) {
                        if ($log)
                            error_log('[Flow-Flow Migration Manager] Running FORCED incremental migrations' . PHP_EOL, 3, $log);
                        foreach ($this->getMigrations() as $migration) {
                            $migrationVersion = $migration->version();
                            if ($migrationVersion == self::INIT_MIGRATION) continue;
                            if ($log)
                                error_log('[Flow-Flow Migration Manager] Executing migration ' . $migrationVersion . PHP_EOL, 3, $log);
                            $migration->execute($conn, $dbm);
                        }
                    }
                } else {
                    if ($log)
                        error_log('[Flow-Flow Migration Manager] Running incremental migrations' . PHP_EOL, 3, $log);
                    foreach ($this->getMigrations() as $migration) {
                        $migrationVersion = $migration->version();
                        if ($log)
                            error_log('[Flow-Flow Migration Manager] Checking migration ' . $migrationVersion . PHP_EOL, 3, $log);
                        if ($this->needExecuteMigration($version, $migrationVersion)) {
                            if ($log)
                                error_log('[Flow-Flow Migration Manager] Executing migration ' . $migrationVersion . PHP_EOL, 3, $log);
                            $migration->execute($conn, $dbm);
                            $dbm->setOption('db_version', $migrationVersion);
                            if ($log)
                                error_log('[Flow-Flow Migration Manager] Completed migration ' . $migrationVersion . PHP_EOL, 3, $log);
                        } else {
                            if ($log)
                                error_log('[Flow-Flow Migration Manager] Skipping migration ' . $migrationVersion . PHP_EOL, 3, $log);
                        }
                    }
                }
                $conn->commit();
                if ($log)
                    error_log('[Flow-Flow Migration Manager] Migration process completed successfully' . PHP_EOL, 3, $log);
            }
        } catch (Exception $e) {
            if ($log)
                error_log('[Flow-Flow Migration Manager] EXCEPTION during migration: ' . $e->getMessage() . PHP_EOL, 3, $log);
            if ($log)
                error_log($e->getTraceAsString() . PHP_EOL, 3, $log);
            $conn->rollback();
            $conn->close();
            throw $e;
        }
    }

    /**
     * Return the list of migration
     * @return array
     */
    protected abstract function migrations();

    /**
     * @return LASafeMySQL
     */
    protected function connection()
    {
        return LAUtils::dbm($this->context)->conn();
    }

    /**
     * @return string
     * @throws Exception
     */
    private function getDBVersion()
    {
        global $wpdb;
        $version = self::INIT_MIGRATION;
        $table = LAUtils::dbm($this->context)->option_table_name;
        if (null != $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
            $option = LAUtils::slug_down($this->context) . '_db_version';
            $version = $wpdb->get_var($wpdb->prepare('select value from ' . $table . ' where id = %s', $option));
            if (null == $version) {
                $e = new Exception('Can`t get the db version of plugin');
                error_log($e->getTraceAsString());
                return self::INIT_MIGRATION;
            }
        }
        return $version;
    }

    private function needStartInitMigration($version)
    {
        return self::INIT_MIGRATION == $version || $version === false;
    }

    /**
     * @return array
     * @throws ReflectionException
     */
    private function getInitMigration()
    {
        $migrations = $this->getMigrations();

        $max = self::INIT_MIGRATION;
        foreach ($migrations as $version => $migration) {
            if ($max < $version) {
                $max = $version;
            }
        }
        return [$max => $migrations[self::INIT_MIGRATION]];
    }

    /**
     * @return array
     * @throws ReflectionException
     */
    private function getMigrations()
    {
        $migrations = [];
        foreach ($this->migrations() as $class) {
            $clazz = new ReflectionClass($class);
            /** @var ILADBMigration $migration */
            $migration = $clazz->newInstance();
            $migrations[$migration->version()] = $migration;
        }
        uksort($migrations, 'version_compare');

        return $migrations;
    }

    private function needExecuteMigration($db_version, $migration_version)
    {
        return version_compare($migration_version, $db_version, '>');
    }

    /**
     * @param $version
     *
     * @return bool
     * @throws ReflectionException
     */
    private function hasMigrations4Perform($version)
    {
        if ($this->needStartInitMigration($version)) {
            return true;
        }
        foreach ($this->getMigrations() as $migration) {
            if ($this->needExecuteMigration($version, $migration->version())) {
                return true;
            }
        }
        return false;
    }
}
// phpcs:enable
