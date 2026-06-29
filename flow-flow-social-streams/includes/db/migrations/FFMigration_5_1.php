<?php
// phpcs:disable
namespace flow\db\migrations;

use la\core\db\migrations\ILADBMigration;

if (!defined('WPINC'))
    die;

/**
 * Migration 5.1 - Initialize "Always disable cache" setting
 */
class FFMigration_5_1 implements ILADBMigration
{

    public function version()
    {
        return '5.1';
    }

    public function execute($conn, $manager)
    {
        $log = defined('FF_LOG_FILE_DEST') ? FF_LOG_FILE_DEST : null;

        if ($log)
            error_log('[Flow-Flow Migration 5.1] Initializing "Always disable cache" setting' . PHP_EOL, 3, $log);

        $options = $manager->getOption('options', true);
        if ($options !== false) {
            if (!isset($options['general-settings-disable-cache'])) {
                if ($log)
                    error_log('[Flow-Flow Migration 5.1] Setting general-settings-disable-cache to nope' . PHP_EOL, 3, $log);

                $options['general-settings-disable-cache'] = 'nope';
                $manager->setOption('options', $options, true);

                if ($log)
                    error_log('[Flow-Flow Migration 5.1] Successfully initialized "Always disable cache" setting' . PHP_EOL, 3, $log);
            } else {
                if ($log)
                    error_log('[Flow-Flow Migration 5.1] general-settings-disable-cache already exists' . PHP_EOL, 3, $log);
            }
        }

        if ($log) {
            error_log('[Flow-Flow Migration 5.1] Migration completed successfully' . PHP_EOL, 3, $log);
        }
    }
}

// phpcs:enable
