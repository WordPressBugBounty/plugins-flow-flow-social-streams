<?php namespace flow;
use la\core\LARemoteUpdater;

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
class FlowFlowUpdater extends LARemoteUpdater{

    protected function getUrlToPluginMetafileJson(){
        return 'http://flow.looks-awesome.com/service/update/flow-flow.json';
    }

    protected function getPluginWithNewVersion($info){
        $plugin = [];
        $plugin['url'] = $info->plugin["url"];
        $plugin['slug'] = 'flow-flow-social-streams';
        $plugin['new_version'] = $info->plugin['version'];
        $plugin['plugin'] = 'flow-flow/flow-flow.php';
        if (isset($info->plugin['download_url'])) $plugin['package'] = $info->plugin['download_url'];
        return (object) $plugin;
    }
}