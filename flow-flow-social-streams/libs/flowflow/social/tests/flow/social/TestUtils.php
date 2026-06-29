<?php
// phpcs:disable

use PHPUnit\Framework\TestCase;

class TestUtils {
	public static function check_post($post, $feed_id = null){
		TestCase::assertEquals((string)stdClass::class, get_class($post));
		TestCase::assertObjectHasAttribute('feed_id', $post);
		TestCase::assertTrue(is_string($post->feed_id));
		if ($feed_id) TestCase::assertEquals($feed_id, $post->feed_id);
		TestCase::assertObjectHasAttribute('id', $post);
		TestCase::assertTrue(is_string($post->id));
		TestCase::assertObjectHasAttribute('header', $post);
		TestCase::assertTrue(is_string($post->header));
		TestCase::assertObjectHasAttribute('type', $post);
		TestCase::assertTrue(is_string($post->type));
		TestCase::assertEquals('instagram', $post->type);
		TestCase::assertObjectHasAttribute('nickname', $post);
		TestCase::assertTrue(is_string($post->nickname));
		TestCase::assertObjectHasAttribute('screenname', $post);
		TestCase::assertTrue(is_string($post->type));
		TestCase::assertObjectHasAttribute('userpic', $post);
		TestCase::assertTrue(is_string($post->screenname));
		TestCase::assertObjectHasAttribute('system_timestamp', $post);
		TestCase::assertTrue(is_string($post->system_timestamp) || is_int($post->system_timestamp));
		TestCase::assertObjectHasAttribute('img', $post);
		TestCase::assertTrue(is_array($post->img));
		TestCase::assertObjectHasAttribute('text', $post);
		TestCase::assertTrue(is_string($post->text));
		TestCase::assertObjectHasAttribute('userlink', $post);
		TestCase::assertTrue(is_string($post->userlink));
		TestCase::assertObjectHasAttribute('permalink', $post);
		TestCase::assertTrue(is_string($post->permalink));
		TestCase::assertObjectHasAttribute('location', $post);
		TestCase::assertTrue(is_null($post->location) || is_object($post->location));
		TestCase::assertObjectHasAttribute('additional', $post);
		TestCase::assertTrue(is_array($post->additional));
		TestCase::assertObjectHasAttribute('carousel', $post);
		TestCase::assertTrue(is_array($post->carousel));
		TestCase::assertObjectHasAttribute('media', $post);
		TestCase::assertTrue(is_array($post->media));
		if (isset($post->userMeta)) TestCase::assertTrue(is_object($post->userMeta));
	}
}
// phpcs:enable
