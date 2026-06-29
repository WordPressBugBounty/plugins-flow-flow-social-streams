<?php
/**
 * FlowSocial
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>
 *
 * @link      http://looks-awesome.com
 * @copyright 2014-2018 Looks Awesome
 */

class FFLinkedInTest extends PHPUnit\Framework\TestCase {
	private $context;

	function test_status_update(){
		$linkedIn = new flow\social\FFLinkedIn();
		self::assertNotNull($linkedIn);

		$feed = new stdClass();
		$feed->content = '3642979';
		$feed->{'event-type'} = 'status-update';
		$feed->id = 'yd85219';
		$feed->type = 'linkedIn';
		$feed->{'filter-by-words'} = '';
		$feed->last_update = 'N/A';
		$feed->use_curl_follow_location = true;
		$feed->use_ipv4 = true;
		$feed->linkedin_access_token = '123';

		$is_empty_feed = true;

		$linkedIn->init($this->context, $feed);
		$posts = $linkedIn->posts($is_empty_feed);
		$this->assertTrue(is_array($posts));

//
//		$context = ff_get_context();
//		$stream  = new flow\settings\FFStreamSettings($feed);
//		$options = new flow\settings\FFGeneralSettings(
//			array(
//				'linkedin_access_token' => 'AQWN_y3_PQ0p5yNu0y3GckVx4BfHln1G1MHQfPMxV53yvzUU5GhOFeL38l0MEiBNvMey9Te3k_l3EoJBGcf-AuG3ZopmLxmAZFY9hFZyBmbnppUBlelopurLBDu_gJ5UtcuaG7IJSmGn3l_hO1mjH5P7Drx0kxqkzwFWJuEemVPu2Q23l0U',
//				'general-settings-disable-proxy-server' => true
//			), []);
//
//		$linkedIn->init($context, $options, $stream, $feed);
//		var_dump($linkedIn->posts());
//		var_dump($linkedIn->errors());
//		foreach($linkedIn->errors() as $item){
//			$this->assertTrue(is_array($item));
//			echo '---';
//			var_dump($item);
//		}



//		$feed = (new class() extends \flow\social\FFLinkedIn {
//			public function __construct() {
//				parent::__construct(null);
//			}
//		});
//		$data = file_get_contents('test-social-linkedin.response.json');
//		$posts = $feed->posts();
	}

	function setUp() {
		parent::setUp();

		$this->context = [];
		$this->context['image_size_cache'] = new \flow\social\cache\FFImageSizeCacheBase();
		$this->context['plugin_url'] = '';
		$this->context['slug'] = 'flow-flow';
	}
}
