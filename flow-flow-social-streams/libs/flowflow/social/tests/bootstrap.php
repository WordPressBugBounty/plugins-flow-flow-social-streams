<?php
// Test bootstrap: CLI only. The WPINC check the rest of the library uses cannot
// serve as the direct-access guard here, because this file defines WPINC itself.
if ( PHP_SAPI !== 'cli' ) {
	exit;
}
// phpcs:disable
if ( ! defined( 'WPINC' ) ) {
	define('WPINC', true);
}

$path = __DIR__ . '/../src/';

require_once __DIR__ . '/flow/social/TestUtils.php';

require_once $path . 'flow/social/LASocialException.php';
require_once $path . 'flow/social/LASocialRequestException.php';

require_once $path . 'flow/social/cache/FFImageSizeCacheBase.php';
require_once $path . 'flow/social/FFFeedUtils.php';
require_once $path . 'flow/social/FFFeed.php';
require_once $path . 'flow/social/FFBaseFeed.php';
require_once $path . 'flow/social/LAFeedWithComments.php';
require_once $path . 'flow/social/FFHttpRequestFeed.php';
require_once $path . 'flow/social/FFRss.php';
require_once $path . 'flow/social/FFPinterest.php';
require_once $path . 'flow/social/FFInstagram.php';
require_once $path . 'flow/social/FFLinkedIn.php';

$autoload = __DIR__ . '/../vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
	$autoload = __DIR__ . '/../../../../vendor/autoload.php';
}
if ( ! file_exists( $autoload ) ) {
	fwrite(STDERR, "Composer dependencies are missing: run `composer install` in " . realpath(__DIR__ . '/..') . "\n");
	exit(1);
}
require_once $autoload;

// phpcs:enable
