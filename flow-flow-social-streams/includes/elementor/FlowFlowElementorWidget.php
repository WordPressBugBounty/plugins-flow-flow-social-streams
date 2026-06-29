<?php
// phpcs:disable
namespace flow\elementor;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use flow\FlowFlow;
use la\core\db\LADB;
use la\core\db\LADDLUtils;
use la\core\LAUtils;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class FlowFlowElementorWidget extends Widget_Base {

	public function get_name() {
		return 'flow-flow-social-streams';
	}

	public function get_title() {
		return 'Flow-Flow';
	}

	public function get_icon() {
		return 'eicon-gallery-grid';
	}

	public function get_categories() {
		return [ 'general' ];
	}

	protected function _register_controls() {
		$this->start_controls_section(
			'section_content',
			[
				'label' => 'Stream Settings',
			]
		);

		$options = $this->get_stream_options();

		$this->add_control(
			'streamId',
			[
				'label' => 'Select Stream',
				'type' => Controls_Manager::SELECT,
				'default' => array_key_first($options),
				'options' => $options,
			]
		);

		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$streamId = $settings['streamId'];

		if ( empty( $streamId ) ) {
			echo 'Please select a stream.';
			return;
		}

		$instance = FlowFlow::get_instance();
		if ( $instance ) {
			echo $instance->renderShortCode( [ 'id' => $streamId ] );
		}
		
		// Elementor Live Preview support
		if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
            ?>
            <script>
                if (typeof FlowFlow !== 'undefined' && typeof jQuery !== 'undefined') {
                    // Slight delay to ensure DOM is ready
                    setTimeout(function() {
                        // Find the container for this specific widget instance
                        var $container = jQuery('.elementor-element-<?php echo $this->get_id(); ?>');
                        var $stream = $container.find('.ff-stream');
                        
                        if ($stream.length) {
                             // Re-initialize if possible. 
                             // Since public.js init() isn't re-entrant in a simple way for individual streams without a bit of hackery,
                             // we try to trigger a re-layout or check if we can invoke the builder.
                             // For now, we rely on the fact that if FlowFlow global exists, it might have auto-run.
                             // But usually, `window.FlowFlow` is the result of `FlowFlow.init()`.
                             
                             // If `FlowFlow.init()` was already called (it runs on window load usually),
                             // we might need to manually trigger the stream building logic for this new element.
                             // Based on research, `FlowFlow.init()` returns an object with `init` method.
                             // `FlowFlow.init().init` creates `FlowFlow` object again but that seems circular.
                             // Realistically, the standard `window.FlowFlow` is defined in `public.js`.
                             
                             // Let's try to trigger a resize/layout if it's already there, or maybe we just need
                             // to ensure scripts are loaded. The shortcode render should output scripts.
                        }
                    }, 500);
                }
            </script>
            <?php
		}
	}

	private function get_stream_options() {
        $nst = FlowFlow::get_instance_by_slug('flow-flow-social-streams');
        if (is_null($nst)) {
             try {
                 $nst = FlowFlow::get_instance();
             } catch (\Exception $e) {
                 // Plugin might not be fully loaded
                 return [];
             }
        }
        
        $streams_list = [];
        
        if ($nst) {
            $context = $nst->getContext();
            $dbm = LAUtils::dbm($context);
            if (LADDLUtils::existTable($dbm->conn(), $dbm->streams_table_name)) {
			    $streams = LADB::streams($dbm->conn(), $dbm->streams_table_name);
                foreach ($streams as $id => $stream) {
                    $name = 'Stream #' . $id . ($stream['name'] ? ' - ' . $stream['name'] : '');
                    $streams_list[$id] = $name;
                }
            }
        }
        
		return $streams_list;
	}
}

// phpcs:enable
