<?php
// phpcs:disable
 namespace la\core;

use stdClass;

/**
 * Flow-Flow.
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>

 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
abstract class LARemoteUpdater{
    protected $context;
    private $info = null;

    function __construct($context) {
        $this->context = $context;

        global $wpdb;
        $table  = LAUtils::dbm($context)->option_table_name;
        $option = LAUtils::slug_down($context) . '_registration_id';
        if (!empty( $wpdb->get_var($wpdb->prepare('select value from ' . $table . ' where id = %s', $option)) )){
            add_filter( 'pre_set_site_' . 'transient_update_plugins', [ $this, 'modify_transient' ], 10, 1 );
            add_filter( 'plugins_api', [ $this, 'plugin_popup' ], 10, 3);
            add_filter( 'upgrader_post_install', [ $this, 'after_install' ], 10, 3 );
        }
    }

    /** @noinspection PhpUnused */
    public final function modify_transient( $transient ) {
		if( isset($transient->checked) && $transient->checked) {
			$info = $this->getInfo();
			if (version_compare($info->plugin['version'], LAUtils::version($this->context)) === 1){
				$plugin = $this->getPluginWithNewVersion($info);
				$transient->response[ $plugin->plugin ] = $plugin;
			}
		}
		return $transient;
	}

    /**
     * @param $result
     * @param $action
     * @param $args
     *
     * @noinspection PhpUnused
     * @noinspection PhpUnusedParameterInspection
     */
    public final function plugin_popup( $result, $action, $args ) {
		if( ! empty( $args->slug ) ) {
			if( $args->slug == LAUtils::slug($this->context) ) {
				return $this->getPlugin($this->getInfo());
			}
		}
		return $result;
	}

    /** @noinspection PhpUnusedParameterInspection */
    public final function after_install( $response, $hook_extra, $result ) {
		global $wp_filesystem; // Get global FS object
		$slug = LAUtils::slug($this->context);
		$destination = WP_PLUGIN_DIR . '/' . $slug .'/';
		$wp_filesystem->move( $result['destination'], $destination );
		$result['destination'] = $destination;
		$result['destination_name'] = $slug;
		return $result;
	}
	
	public final function getInfo(){
		if (is_null($this->info)){
			$this->info = $this->get_repository_info();
		}
		return $this->info;
	}

    protected function getPlugin($info){
        $plugin = [
            'name'              => $info->plugin["name"],
            'slug'              => $info->basename,
            'plugin'            => $info->basename . '/' . $info->basename . '.php',
            'version'           => $info->plugin['version'],
            'author'            => $info->author["name"],
            'author_profile'    => $info->author["url"],
            'last_updated'      => $info->plugin['published_at'],
            'homepage'          => $info->plugin["url"],
            'short_description' => $info->plugin["description"],
            'sections'          => [
                'description'   => $info->plugin["description"],
                'changelog'       => $info->plugin["changelog"],
            ]
        ];
        if (isset($info->plugin['download_url'])) $plugin['download_link'] = $info->plugin['download_url'];
        return (object) $plugin;
    }

	protected abstract function getPluginWithNewVersion($info);
	protected abstract function getUrlToPluginMetafileJson();
	
	private function get_repository_info(){
		$db = LAUtils::dbm($this->context);
		$registration_id = $db->getOption('registration_id');
		
		$result = wp_remote_get($this->getUrlToPluginMetafileJson());
		if (!is_wp_error($result) && isset($result['response']) && isset($result['response']['code']) && $result['response']['code'] == 200) {
			$settings = $db->getGeneralSettings()->original();
			if (is_array($settings) && isset($settings['purchase_code'])){
				$json = json_decode($result['body']);
				$purchase_code = $settings['purchase_code'];
				
				$info = new stdClass();
				$info->basename = LAUtils::slug($this->context);
				
				$info->plugin = [];
				$info->plugin["name"] = $json->item->name;
				$info->plugin['version'] = $json->item->version;
				$info->plugin['published_at'] = $json->item->updated_at;
				$info->plugin["url"] = $json->item->url;
				$info->plugin["description"] = $json->item->description;
				$info->plugin["changelog"] = $json->item->changelog;
				$info->plugin['download_url'] = $json->item->download_url . "?action=la_update&registration_id={$registration_id}&purchase_code={$purchase_code}";
				
				$info->author = [];
				$info->author["url"] = $json->author->url;
				$info->author["name"] = $json->author->name;
				
				return $info;
			}
		}
		return null;
	}
}
// phpcs:enable
