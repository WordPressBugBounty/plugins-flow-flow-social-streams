<?php
// phpcs:disable
/**
 * FlowSocial
 *
 * @package   FlowFlow
 * @author    Looks Awesome <email@looks-awesome.com>
 *
 * @link      http://looks-awesome.com
 * @copyright 2014-2018 Looks Awesome
 */
class FFFacebookTest extends PHPUnit\Framework\TestCase {
	function test_status_update(){
		$fb = new \Facebook\Facebook([
			'app_id' => '819834498139888',
			'app_secret' => 'b2e91d70719c03199ae610cbce5835fb',
			'default_graph_version' => 'v2.10',
			'default_access_token' => 'EAALpopgJRvABABoVSjEZA7ytScJEwAy9QEkBx5Vw09xcGgT8tgSbKsfAlLRZClpwhUMF8Ms6Hi6zO5dffBXJeV72Ib5rRRQdwSJJRglOp4RC1jzOy739jKV5cdOrSD7Q9cTNQbPWpnV79nLi94Xbs3sppYfwc8JUNUuUjCdf4M65ZBFNLbJpbmftNZC5mMMZD', // optional
		]);
		self::assertNotNull($fb);
		//$requestUserName = $fb->request('GET', '/54325985357/feed?fields=id,created_time,from,link,message,name,object_id,picture,full_picture,attachments{media,subattachments},source,status_type,story,type');
//		$response = $fb->get('me?fields=id,name');
		$response = $fb->get('/me/feed?fields=id,created_time,from,link,message,name,object_id,picture,full_picture,attachments{media,subattachments},source,status_type,story,type&amp;limit=5');
//		$response = $fb->get('/54325985357/feed?fields=id,message&amp;limit=5');
		//$response->get();

		try {
			$alb = $fb->get('/1795496597196099/photos');
		} catch ( Exception $e ) {
		}
		try {
			$alb = $fb->get('/1796436317102127/photos');//from gleb
		} catch ( Exception $e ) {
		}

		$acc = $fb->get('/me/accounts');
		$edge = $acc->getGraphEdge();
		/** @var \Facebook\GraphNodes\GraphNode $node */
		foreach ($edge as $node) {
			$page_token = $node->getField('access_token');
			$fb->setDefaultAccessToken($page_token);
			$id = '54325985357';
			$id = $node->getField('id');//'1052432538193115';
			$feeds = $fb->get('/me/posts?fields=id,message&amp;limit=5');
			$feeds = $feeds->getGraphEdge();
			foreach ($feeds as $feedNode) {
				$feedNode;
			}

			//if ($id == '212083862204055')
			{
				try {
					$alb = $fb->get('/1795496597196099/photos');
				} catch ( Exception $e ) {
				}
				try {
					$alb = $fb->get('/1796436317102127/photos');//from gleb
				} catch ( Exception $e ) {
				}

			}
		}
		print_r($acc);
	}
}

// phpcs:enable
