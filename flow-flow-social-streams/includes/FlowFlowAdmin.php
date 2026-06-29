<?php
// phpcs:disable
namespace flow;

use flow\db\FFDBMigrationManager;
use flow\tabs\FFAddonsTab;
use flow\tabs\FFBackupTab;
use flow\tabs\FFModerationTab;
use flow\tabs\FFSourcesTab;
use flow\tabs\FFStreamsTab;
use flow\tabs\FFStatusTab;
use la\core\LAAdminBase;
use la\core\LAUtils;
use la\core\tabs\LAAuthTab;
use la\core\tabs\LAGeneralTab;
use la\core\tabs\LALicenseTab;
use ReflectionException;

if (!defined('WPINC'))
	die;

/**
 * Flow-Flow.
 *
 * Plugin class. This class should ideally be used to work with the
 * administrative side of the WordPress site.
 *
 * If you're interested in introducing public-facing
 * functionality, then refer to `FlowFlow.php`
 *
 * @package   FlowFlowAdmin
 * @author    Looks Awesome <email@looks-awesome.com>
 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
class FlowFlowAdmin extends LAAdminBase
{
	protected function contextForAdminPage()
	{
		$context = parent::contextForAdminPage();
		$tab_prefix = 'ff';
		$context['form-action'] = '';
		$context['tabs'][] = new FFStreamsTab();
		$context['tabs'][] = new FFSourcesTab();

		$context['tabs'][] = new FFModerationTab();

		$context['tabs'][] = new LAGeneralTab($tab_prefix);
		$context['tabs'][] = new LAAuthTab($tab_prefix);
		$context['tabs'][] = new FFBackupTab();
		if (FF_USE_WP) {
			$slug = LAUtils::slug($this->context);
			$is_lite = ($slug === 'flow-flow-lite' || $slug === 'flow-flow-social-streams');
			if (!$is_lite) {
				$context['tabs'][] = new LALicenseTab($tab_prefix, $context['activated']);
			}
			$context['tabs'][] = new FFAddonsTab();
		}
		// Status tab shows cron and environment health for Flow-Flow
		$context['tabs'][] = new FFStatusTab();
		$context['license_subscription_description'] = '<h3>Here you can activate plugin with Envato purchase code. Purchase code can be obtained only through purchasing plugin on its <a href="http://go.social-streams.com/get-flow">CodeCanyon page</a>. Plugin activation unlocks easy updating via WP dashboard. Purchasing plugin license also grants access to <a href="http://go.social-streams.com/help">premium support</a>. You can subscribe to important notifications if you mark checkbox in the form below. These notifications will include announcements about major updates and Flow-Flow extension releases. (*) — Required fields</h3>';
		$context['boosts'] = $this->db->getOption('boosts_email') != false;

		$context['buttons-after-tabs'] = '<li id="request-tab"><span>Save changes</span> <i class="flaticon-paperplane"></i></li>';
		$context = apply_filters('flow_flow_change_context', $context);
		return $context;
	}

	/**
	 * @throws ReflectionException
	 */
	protected function initPluginAdminPage()
	{
		$mm = new FFDBMigrationManager($this->context);
		$mm->migrate();
		unset($mm);
	}

	protected function enqueueAdminStylesAlways($plugin_directory)
	{
		wp_enqueue_style($this->getPluginSlug() . '-admin-icon-styles', $plugin_directory . 'css/admin-icon.css', [], LAUtils::version($this->context));
	}

	protected function enqueueAdminScriptsAlways($plugin_directory)
	{
		wp_enqueue_script($this->getPluginSlug() . '-global-admin-script', $plugin_directory . 'js/global_admin.js', ['jquery', 'backbone', 'underscore'], LAUtils::version($this->context));
	}

	protected function enqueueAdminStylesOnlyAtPluginPage($plugin_directory)
	{
		wp_enqueue_style($this->getPluginSlug() . '-admin-styles', $plugin_directory . 'css/admin.css', [], LAUtils::version($this->context));
		wp_enqueue_style($this->getPluginSlug() . '-colorpickersliders', $plugin_directory . 'css/jquery-colorpickersliders.css', [], LAUtils::version($this->context));

		// Load web font
		wp_register_style('ff-admin-fonts', '//fonts.googleapis.com/css?family=Montserrat:400,600,700|Roboto+Slab|Lato:300,400', [], null, 'all');
		wp_enqueue_style('ff-admin-fonts');

		//for preview
		//TODO move to filter
		FlowFlow::get_instance($this->context)->enqueue_styles();
	}

	protected function enqueueAdminScriptsOnlyAtPluginPage($plugin_directory)
	{
		parent::enqueueAdminScriptsOnlyAtPluginPage($plugin_directory);
		/*
		if (file_exists(plugin_dir_path(__DIR__) . 'env.json')) {
			$env = json_decode( file_get_contents(plugin_dir_path( __DIR__ ) . 'env.json'), true);
		}
		*/

		$slug = LAUtils::slug($this->context);
		$is_lite = ($slug === 'flow-flow-lite' || $slug === 'flow-flow-social-streams');
		$data = array(
			'isWordpress' => (string) FF_USE_WP,
			'ajaxurl' => (string) $this->context['ajax_url'],
			'siteurl' => site_url(),
			'nonce' => wp_create_nonce('flow_flow_nonce'),
			'm' => $is_lite ? ($this->db->getOption('boosts_email') != false ? 'p' : 'l') : 'p',
			'la_plugin_slug_down' => LAUtils::slug_down($this->context),
			'backUrl' => (string) $this->context['ajax_url'] . '?action=' . LAUtils::slug_down($this->context) . '_social_auth'
		);
		wp_localize_script($this->getPluginSlug() . '-admin-script', LAUtils::slug_down($this->context) . '_vars', $data);

		//for preview
		//TODO move to filter
		FlowFlow::get_instance()->enqueue_scripts();
	}

	protected function addPluginAdminSubMenu($displayAdminPageFunction)
	{
		$slug = LAUtils::slug($this->context);
		$is_lite = ($slug === 'flow-flow-lite' || $slug === 'flow-flow-social-streams');
		$title = $is_lite ? 'Flow-Flow Lite' : 'Flow-Flow';
		add_submenu_page(
			'flow-' . 'flow',
			$title,
			$title,
			'manage_options',
			$this->getPluginSlug() . '-admin',
			$displayAdminPageFunction
		);
	}
}
// phpcs:enable
