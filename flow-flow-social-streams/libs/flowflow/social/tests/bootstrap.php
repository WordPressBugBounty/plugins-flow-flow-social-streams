<?php
if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'WPINC' ) && ( ! defined( 'FF_USE_WP' ) || FF_USE_WP ) ) {
		exit;
	}
}
// phpcs:disable
define('WPINC', true);

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

require_once '/Users/navdeykin/Projects/FlowSocial/vendor/autoload.php';

// phpcs:enable
