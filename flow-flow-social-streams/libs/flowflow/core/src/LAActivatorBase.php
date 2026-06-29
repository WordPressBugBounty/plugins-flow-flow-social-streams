<?php
// phpcs:disable
 namespace la\core;

/**
 * Flow-Flow.
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>

 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
abstract class LAActivatorBase{
    protected $context;
    private $cron_intervals = [];

    public function __construct($file){
        $context = $this->initContext($file);

        $this->context = $context;
        $main_file = LAUtils::root($context) . LAUtils::slug($context) . '.php';
        register_activation_hook( $main_file, [ $this, 'activate' ] );
        register_deactivation_hook( $main_file, [ $this, 'deactivate' ] );
    }

    public final function slug(){
        return $this->context['slug'];
    }

    /**
     * Fired when the plugin is activated.
     *
     * @since    1.0.0
     *
     * @param    boolean    $network_wide    True if WPMU superadmin uses
     *                                       "Network Activate" action, false if
     *                                       WPMU is disabled or plugin is
     *                                       activated on an individual blog.
     *
     * @noinspection PhpUnused
     */
    public final function activate( $network_wide ){
        $this->checkEnvironment();

        if ( function_exists( 'is_multisite' ) && is_multisite() ) {
            if ( $network_wide  ) {
                // Get all blog ids
                $blog_ids = $this->getBlogIDs();
                foreach ( $blog_ids as $blog_id ) {
                    switch_to_blog( $blog_id );
                    $this->singleSiteActivate();
                    restore_current_blog();
                }
            }
            else $this->singleSiteActivate();
        }
        else $this->singleSiteActivate();
    }

    /**
     * Fired when the plugin is deactivated.
     *
     * @since    1.0.0
     *
     * @param    boolean    $network_wide    True if WPMU superadmin uses
     *                                       "Network Deactivate" action, false if
     *                                       WPMU is disabled or plugin is
     *                                       deactivated on an individual blog.
     *
     * @noinspection PhpUnused
     */
    public final function deactivate( $network_wide ){
        if ( function_exists( 'is_multisite' ) && is_multisite() ) {
            if ( $network_wide ) {
                // Get all blog ids
                $blog_ids = $this->getBlogIDs();
                foreach ( $blog_ids as $blog_id ) {
                    switch_to_blog( $blog_id );
                    $this->singleSiteDeactivate();
                    restore_current_blog();
                }
            }
            else $this->singleSiteDeactivate();
        }
        else $this->singleSiteDeactivate();
    }


    /**
     * Fired when the plugin loaded.
     * Hook 'plugins_loaded'
     */
    public final function loadPlugin(){
        $this->beforePluginLoad();

        $this->registerShutdownActions();

        if (defined('FF_USE_WP_CRON') && FF_USE_WP_CRON){
            $this->registerCronActions();
        }

        if (defined('DOING_AJAX') && DOING_AJAX){
            $this->registerAjaxActions();
        }
        else {
            if (is_admin()){
                $this->checkPlugin();
                $this->renderAdminSide();
            }
            else {
                $this->renderPublicSide();
            }
        }
        $this->afterPluginLoad();
    }

    public final function getCronIntervals($schedules){
        $schedules += $this->cron_intervals;
        return $schedules;
    }

    /** @noinspection PhpUnused */
    public final function init_wp_widget() {
        $this->initWPWidget();
    }

    /** @noinspection PhpUnused */
    public final function init_vc_integration(){
        $this->initVCIntegration();
    }

    protected function beforePluginLoad(){
        $domain = parse_url(get_option('siteurl'), PHP_URL_HOST);
        $this->setContextValue('domain', $domain);
    }

    protected abstract function checkPlugin();

    protected abstract function initContext($file);

    protected function registerCronActions(){
        $this->addCronInterval('minute', [ 'interval' => MINUTE_IN_SECONDS, 'display' => 'Every Minute' ] );
        $this->addCronInterval('six_hours', [ 'interval' => HOUR_IN_SECONDS * 6, 'display' => 'Six hours' ] );
        add_filter('cron_schedules', [ $this, 'getCronIntervals' ] );
    }

    protected abstract function registerAjaxActions();

    protected abstract function registerShutdownActions();

    protected abstract function renderAdminSide();

    protected abstract function renderPublicSide();

    protected abstract function initWPWidget();

    protected abstract function initVCIntegration();

    protected function afterPluginLoad(){
        add_action( 'widgets_init', [ $this, 'init_wp_widget' ] );
        add_action( 'vc_before_init', [ $this, 'init_vc_integration' ] );
    }

    /**
     * Check environment before will fire plugin activate
     */
    protected abstract function checkEnvironment();

    /**
     * Fired for each blog when the plugin is activated.
     */
    protected function singleSiteActivate(){
        $this->checkPlugin();
    }

    /**
     * Fired for each blog when the plugin is deactivated.
     */
    protected abstract function singleSiteDeactivate();

    protected function addCronInterval($key, $value){
        if (!array_key_exists($key, $this->cron_intervals)){
            $this->cron_intervals[$key] = $value;
        }
    }

    protected function setContextValue($key, $value){
        $this->context[$key] = $value;
    }

    /**
     * Get all blog ids of blogs in the current network that are:
     * - not archived
     * - not spam
     * - not deleted
     *
     * @since    1.0.0
     *
     * @return   array|false    The blog ids, false if no matches.
     */
    private function getBlogIDs(){
        global $wpdb;
        $sql = "SELECT blog_id FROM $wpdb->blogs WHERE archived = '0' AND spam = '0' AND deleted = '0'";
        return $wpdb->get_col( $sql );
    }
}

// phpcs:enable
