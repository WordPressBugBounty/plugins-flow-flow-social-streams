<?php
/**
 * Flow-Flow
 *
 * Plugin class. This class should ideally be used to work with the
 * public-facing side of the site.
 *
 * If you're interested in introducing administrative or dashboard
 * functionality, then refer to `FlowFlowAdmin.php`
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>

 * @link      http://looks-awesome.com
 * @copyright 2015 Looks Awesome
 */
session_start();

/** @noinspection PhpIncludeInspection */
require_once( __DIR__ . '/ff-config.php');
/** @noinspection PhpIncludeInspection */
require_once( __DIR__ . '/ff-init.php');
require_once( __DIR__ . '/libs/autoload.php' );

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'FF_USE_WP' ) || FF_USE_WP ) {
		exit;
	}
}

use la\core\db\LADBManager;
use la\core\LAUtils;

/**
 * Get stream settings by id endpoint
 *
 * @param LADBManager $flow_flow_db
 *
 * @throws Exception
 */
function flow_flow_get_stream_settings($flow_flow_db){
    if (FF_USE_WP) {
        if (!current_user_can('manage_options') || !check_ajax_referer( 'flow_flow_nonce', 'security', false ) ) {
            die( json_encode( [ 'error' => 'not_allowed' ] ) );
        }
    }

    $id = \la\core\LAUtils::get_request_var('stream-id', 'get', 'text', '');
    $flow_flow_db->dataInit(false, false);

    $stream = $flow_flow_db->getStream($id);

    // cleaning if error was saved in database stream model, can be removed in future, now it's needed for affected users
    if ( isset( $stream['error'] ) ) unset( $stream['error'] );

    die( json_encode( $stream ) );
}

$flow_flow_action_val = \la\core\LAUtils::get_request_var('action', 'request', 'text', null);
if ($flow_flow_action_val !== null){
	$flow_flow_action = $flow_flow_action_val;

	$flow_flow_context = ff_get_context();
	$flow_flow_db = LAUtils::dbm($flow_flow_context);

	global $flow_flow_facebook_cache;
	$flow_flow_facebook_cache = new la\core\cache\LAFacebookCacheManager($flow_flow_context);

	$flow_flow_ff = flow\FlowFlow::get_instance($flow_flow_context);

	switch ($flow_flow_action) {
		case 'fetch_posts':
			$flow_flow_ff->processAjaxRequest();
			break;
		case 'ff_load_cache':
			$flow_flow_ff->processAjaxRequestBackground();
			break;
		case 'refresh_cache':
			if (false !== ($flow_flow_time = $flow_flow_db->getOption('bg_task_time'))){
				if (time() > $flow_flow_time + 60){
					$flow_flow_ff->refreshCache();
					$flow_flow_time = time();
					$flow_flow_db->setOption('bg_task_time', $flow_flow_time);
					echo 'new cache time: ' . (int)$flow_flow_time;
				}
			} else  {
			    $flow_flow_db->setOption('bg_task_time', time());
            }
			break;
		case 'flow_flow_save_stream_settings':
			$flow_flow_db->save_stream_settings();
			break;
		case 'flow_flow_get_stream_settings':
			flow_flow_get_stream_settings($flow_flow_db);
			break;
		case 'flow_flow_ff_save_settings':
			$flow_flow_db->ff_save_settings_fn();
			break;
		case 'flow_flow_create_stream':
			$flow_flow_db->create_stream();
			break;
		case 'flow_flow_clone_stream':
			$flow_flow_db->clone_stream();
			break;
		case 'flow_flow_delete_stream':
			$flow_flow_db->delete_stream();
			break;
		case 'moderation_apply_action':
			$flow_flow_ff->moderation_apply();
			break;
		case 'flow_flow_social_auth':
			$flow_flow_db->social_auth();
			break;
		default:
			if (strpos($flow_flow_action, "backup") !== false) {
				$flow_flow_snapshot_manager = new la\core\snapshots\LASnapshotManager($flow_flow_context);
				$flow_flow_snapshot_manager->processAjaxRequest();
			}
			break;
	}
}
die;