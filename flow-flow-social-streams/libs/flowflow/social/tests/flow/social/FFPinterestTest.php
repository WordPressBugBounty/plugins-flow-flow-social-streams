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

class FFPinterestTest extends PHPUnit\Framework\TestCase {
	private $context;

	function test_status_update(){
		$feed = new stdClass();
		$feed->content = 'elainen';
		$feed->id = 'yd85219';
		$feed->type = 'pinterest';
		$feed->{'filter-by-words'} = '';
		$feed->{'hide-text'} = false;
		$feed->last_update = 'N/A';
		$feed->{'rich-text'} = false;
		$feed->{'hide-caption'} = false;
		$feed->use_curl_follow_location = true;
		$feed->use_ipv4 = true;

		//test first init feed
		$is_empty_feed = true;

		$pinterest = new flow\social\FFPinterest;
		$pinterest->init($this->context, $feed);
		$posts = $pinterest->posts($is_empty_feed);
		$this->assertTrue(is_array($posts));
		$this->assertEquals(25, sizeof($posts));
		foreach ( $posts as $post ) {
			$this->assertEquals((string)stdClass::class, get_class($post));
			$this->assertObjectHasAttribute('feed_id', $post);
			$this->assertTrue(is_string($post->feed_id));
			$this->assertEquals($feed->id, $post->feed_id);
			$this->assertObjectHasAttribute('id', $post);
			$this->assertTrue(is_string($post->id));
			$this->assertObjectHasAttribute('header', $post);
			$this->assertTrue(is_string($post->header));
			$this->assertObjectHasAttribute('type', $post);
			$this->assertTrue(is_string($post->type));
			$this->assertEquals('pinterest', $post->type);
			$this->assertObjectHasAttribute('nickname', $post);
			$this->assertTrue(is_string($post->nickname));
			$this->assertObjectHasAttribute('screenname', $post);
			$this->assertTrue(is_string($post->type));
			$this->assertObjectHasAttribute('userpic', $post);
			$this->assertTrue(is_string($post->screenname));
			$this->assertObjectHasAttribute('system_timestamp', $post);
			$this->assertTrue(is_string($post->system_timestamp) || is_int($post->system_timestamp));
			$this->assertObjectHasAttribute('img', $post);
			$this->assertTrue(is_array($post->img));
			$this->assertObjectHasAttribute('text', $post);
			$this->assertTrue(is_string($post->text));
			$this->assertObjectHasAttribute('userlink', $post);
			$this->assertTrue(is_string($post->userlink));
			$this->assertObjectHasAttribute('permalink', $post);
			$this->assertTrue(is_string($post->permalink));
			$this->assertObjectHasAttribute('additional', $post);
			$this->assertTrue(is_array($post->additional));
			$this->assertObjectHasAttribute('carousel', $post);
			$this->assertTrue(is_array($post->carousel));
			$this->assertObjectHasAttribute('media', $post);
			$this->assertTrue(is_array($post->media));
			$this->assertObjectHasAttribute('comments', $post);
			$this->assertTrue(is_array($post->comments));
		}
	}

	function setUp() {
		parent::setUp();

		$this->context = [];
		$this->context['image_size_cache'] = new \flow\social\cache\FFImageSizeCacheBase();
		$this->context['plugin_url'] = '';
		$this->context['slug'] = 'flow-flow';
	}
}
