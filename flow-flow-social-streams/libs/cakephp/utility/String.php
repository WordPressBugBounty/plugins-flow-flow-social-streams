<?php
if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'WPINC' ) && ( ! defined( 'FF_USE_WP' ) || FF_USE_WP ) ) {
		exit;
	}
}
// phpcs:disable
// @deprecated 3.5.0 Backward compatibility with 2.x series
if (PHP_VERSION_ID < 70000) {
    class_alias('Cake\Utility\Text', 'Cake\Utility\String');
    deprecationWarning('Use Cake\Utility\Text instead of Cake\Utility\String.');
}

// phpcs:enable
