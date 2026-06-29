<?php
if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'WPINC' ) && ( ! defined( 'FF_USE_WP' ) || FF_USE_WP ) ) {
		exit;
	}
}
// phpcs:disable

require_once dirname(__FILE__) . '/Unirest/Exception.php';
require_once dirname(__FILE__) . '/Unirest/Method.php';
require_once dirname(__FILE__) . '/Unirest/Response.php';
require_once dirname(__FILE__) . '/Unirest/Request.php';
require_once dirname(__FILE__) . '/Unirest/Request/Body.php';

// phpcs:enable
