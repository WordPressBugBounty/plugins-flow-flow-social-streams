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

class FFInstagramTest extends PHPUnit\Framework\TestCase {
	private $context;

	function test_posts(){
		$accounts = ['phat_jim', 'natgeotravel', 'nasa', 'instagram'];
		foreach ($accounts as $username){
			$feed = $this->_get_feed($username);

			$instagram = new \flow\social\FFInstagram;
			$instagram->init($this->context, $feed);
			$posts = $instagram->posts(false);
			$this->assertEquals(50, sizeof($posts));
			foreach ( $posts as $post ) TestUtils::check_post($post, $feed->id);
		}

		//feed by token
		$feed = $this->_get_feed('');
		$feed->instagram_access_token = '40466450.94072d7.64c4f1c72c3b4389afed5d034f8d57c4';
		$instagram = new \flow\social\FFInstagram;
		$instagram->init($this->context, $feed);
		$posts = $instagram->posts(false);
		foreach ( $posts as $post ) TestUtils::check_post($post, $feed->id);
	}

	function test_posts_first_init(){
		$feed = $this->_get_feed('phat_jim');
//		$feed = $this->_get_feed('nasa');

		//test pagination
		$instagram = new \flow\social\FFInstagram;
		$instagram->init($this->context, $feed);
		$posts = $instagram->posts(true);
		$this->assertTrue(sizeof($posts) == 50);
		foreach ( $posts as $post ) TestUtils::check_post($post, $feed->id);

		//test custom init count of posts
		define('FF_FEED_INIT_COUNT_POSTS', 31);
		$instagram = new \flow\social\FFInstagram;
		$instagram->init($this->context, $feed);
		$posts = $instagram->posts(true);
		$this->assertEquals(50, sizeof($posts));
		foreach ( $posts as $post ) TestUtils::check_post($post, $feed->id);
	}

	function test_posts_user_not_found(){
		$feed = $this->_get_feed('gregherbertart');//not correct username

		$mockBuilder = $this->getMockBuilder('\flow\social\FFInstagram');
		$mockBuilder->setMethods(array('print2log'));
		$instagram = $mockBuilder->getMock();
		$instagram->init($this->context, $feed);
		$instagram->expects($this->any())->method('print2log');
		$instagram->posts(false);

		$this->assertTrue(is_array($instagram->errors()) && sizeof($instagram->errors()) == 1);
		$this->assertStringStartsWith('Username not found', $instagram->errors()[0]['message']);
	}

	function test_tag_timeline(){
		$feed = $this->_get_feed('#ocean', 'tag');

		$instagram = new \flow\social\FFInstagram;
		$instagram->init($this->context, $feed);
		$posts = $instagram->posts(true);
		$this->assertTrue(sizeof($posts) > 20);
		foreach ( $posts as $post ) TestUtils::check_post($post, $feed->id);
	}


	function test_get_comments() {
		$instagram = new \flow\social\FFInstagram;
		$instagram->init($this->context, $this->_get_feed('phat_jim'));

		$comments = $instagram->getComments(null);
		$this->assertTrue( is_array($comments) );

		//The post doesn't have comments
		//https://www.instagram.com/p/0MGP0Clq7B/
		$comments = $instagram->getComments('940153897162813121');
		$this->assertTrue( is_array($comments) );
		$this->assertEmpty( $comments );

		//The post have many comments
		//https://www.instagram.com/p/BeM8MaGATRY/
		$comments = $instagram->getComments('1696995895054251096_23947096');
		$this->assertTrue( is_array($comments) );
		$this->assertNotEmpty( $comments );
		$this->assertTrue( sizeof($comments) == 5 );

		foreach ( $comments as $comment ) {
			$this->assertObjectHasAttribute('id', $comment);
			$this->assertObjectHasAttribute('from', $comment);
			$this->assertObjectHasAttribute('text', $comment);
			$this->assertObjectHasAttribute('created_time', $comment);
		}
	}

	function setUp() {
		parent::setUp();

		$this->context = [];
		$this->context['image_size_cache'] = new \flow\social\cache\FFImageSizeCacheBase();
		$this->context['plugin_url'] = '';
		$this->context['slug'] = 'flow-flow';
	}

	function _get_feed($content, $timeline = 'user_timeline'){
		$feed = new stdClass();
		$feed->content = $content;
		$feed->id = 'test_feed_123';
		$feed->type = 'instagram';
		$feed->{'filter-by-words'} = '';
		$feed->last_update = 'N/A';
		$feed->use_curl_follow_location = true;
		$feed->use_ipv4 = true;
		$feed->{'timeline-type'} = $timeline;
		$feed->instagram_access_token = '';
		return $feed;
	}
}
