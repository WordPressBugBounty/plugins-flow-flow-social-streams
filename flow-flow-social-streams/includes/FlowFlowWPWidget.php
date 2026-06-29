<?php
namespace flow;
use la\core\db\LADB;
use la\core\db\LADDLUtils;
use la\core\LAUtils;
use WP_Widget;

if (!defined('WPINC'))
	die;
/**
 * FlowFlow.
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>
 *
 * @link      http://looks-awesome.com
 * @copyright Looks Awesome
 */
class FlowFlowWPWidget extends WP_Widget
{
	private $context;

	public function __construct()
	{
		parent::__construct(
			'ff_widget',
			'Flow-Flow Widget',
			['description' => 'Place your social stream'] // Args
		);
	}

	public function setContext($context)
	{
		$this->context = $context;
	}

	public function widget($args, $instance)
	{
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['before_widget'];

		$nst = FlowFlow::get_instance_by_slug('flow-flow-social-streams');
		if (is_null($nst) && isset($this->context)) {
			$nst = FlowFlow::get_instance($this->context);
		}
		if ($nst && isset($instance['streamId'])) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $nst->renderShortCode(['id' => $instance['streamId']]);
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $args['after_widget'];
	}

	public function form($instance)
	{


		//Important!
		//It will be execute before migrations!
		//Need to check exist tables and fields!
		$dbm = LAUtils::dbm($this->context);
		$streams = [];
		if (LADDLUtils::existTable($dbm->conn(), $dbm->streams_table_name))
			$streams = LADB::streams($dbm->conn(), $dbm->streams_table_name);

		$value = '';
		if (sizeof($streams) > 0) {
			$streamId = null;
			foreach ($streams as $id => $stream) {
				if ($streamId == null)
					$streamId = !empty($instance['streamId']) ? esc_attr($instance['streamId']) : $id;
				$streamName = 'Stream #' . esc_html($id) . ($stream['name'] ? ' - ' . esc_html($stream['name']) : '');
				$selected = ($streamId == $id) ? ' selected' : '';
				$value .= "<option value='" . esc_attr($id) . "' {$selected}>{$streamName}</option>\n";
			}
		}

		?>
		<p>
			<label for="<?php echo esc_attr($this->get_field_id('streamId')); ?>"><?php esc_html_e('Stream:', 'flow-flow-social-streams'); ?></label>
			<select class="widefat" id="<?php echo esc_attr($this->get_field_id('streamId')); ?>"
				name="<?php echo esc_attr($this->get_field_name('streamId')); ?>">
				<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $value; ?>
			</select>
		</p>
		<?php
	}

	public function update($new_instance, $old_instance)
	{
		$instance = [];

		$instance['streamId'] = (!empty($new_instance['streamId'])) ? wp_strip_all_tags($new_instance['streamId']) : '1';

		return $instance;
	}
}