<?php

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'FF_USE_WP' ) || FF_USE_WP ) {
		exit;
	}
}

// phpcs:disable

use la\core\LAUtils;
use la\core\tabs\LATab;

if ( ! defined( 'WPINC' ) ) die;
/** @var array $context */
$dbm = LAUtils::dbm($context);

?>
<style>
    .wrapper {
        display: none !important;
    }

    #background-admin {

    }

    #fade-overlay {
        cursor: default;
        backdrop-filter: blur(3px);
    }

    #fade-overlay i:before {
        content: '\e03a';

    }

    #fade-overlay.loading i, #fade-overlay.loading-copy i {
        -webkit-animation: none;
        animation: none;
    }
    
    #background-admin img {
        width: 100%;
    }
</style>
<div id="background-admin">
    <img src="<?php echo LAUtils::plugin_url($context); ?>/assets/flow-admin.png" alt=""/>
</div>
<div id="fade-overlay" class="">
    <div id="waiting-posts">
        <h1>Flow-Flow servers on maintenance</h1>
        <p> We are making some improvements under the hood, this should not take too long. Thanks for your patience and sorry for inconvenience.</p>
    </div>
    <i class="flaticon-parameters"></i>
</div>

<!-- @TODO: Provide markup for your options page here. -->
<form id="flow_flow_form" method="post" action="<?php echo $context['form-action']; ?>" enctype="multipart/form-data">
    <script id="flow_flow_script">
        var _ajaxurl = '<?php echo $context['admin_url']; ?>';
        var la_plugin_slug_down = '<?php echo LAUtils::slug_down($context); ?>';
        var plugin_url = '<?php echo LAUtils::plugin_url($context) ; ?>';
        var server_time = '<?php echo time() ; ?>';
        var plugin_ver = '<?php echo LAUtils::version($context) ; ?>';
        <?php if (isset($context['js-vars'])) echo $context['js-vars'];?>
    </script>
    <?php
    settings_fields('ff_opts');
    if (isset($context['hidden-inputs'])) echo $context['hidden-inputs'];
    ?>
    <div class="wrapper">
        <?php
        if (FF_USE_WP) {
            echo '<h2>' . $context['admin_page_title'] . (LAUtils::slug($context) == 'flow-flow-social-streams' ? ' Social Stream v. ' : ' Feed Gallery v. ' ) . LAUtils::version($context) . ' <a href="' . $context['faq_url'] . '" target="_blank">Documentation & FAQ</a></h2>';

            echo '<div id="ff-cats">';
            if (FF_USE_WP) {
                wp_dropdown_categories();
            }
            echo '</div>';
        }
        ?>
        <ul class="section-tabs">
            <?php
            /** @var LATab $tab*/
            foreach ( $context['tabs'] as $tab ) {
                echo '<li id="'.$tab->id().'"><i class="'.$tab->flaticon().'"></i> <span>'.$tab->title().'</span></li>';
            }
            if (isset($context['buttons-after-tabs'])) echo $context['buttons-after-tabs'];
            ?>
        </ul>
        <div class="section-contents">
            <?php
            /** @var LATab $tab*/
            foreach ( $context['tabs'] as $tab ) {
                $tab->includeOnce($context);
            }
            ?>
        </div>
    </div>


</form>


<script>
    jQuery( document ).trigger('html_ready');

    jQuery( function () {
        FlowFlowApp.Controller.makeOverlayTo('show', 'posts-loading' );
    })
</script>
<?php // phpcs:enable ?>
