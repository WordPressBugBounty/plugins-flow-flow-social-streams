<?php
if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'WPINC' ) && ( ! defined( 'FF_USE_WP' ) || FF_USE_WP ) ) {
		exit;
	}
}
// phpcs:disable
 return array(
    'root' => array(
        'pretty_version' => 'dev-develop',
        'version' => 'dev-develop',
        'type' => 'library',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'reference' => '4d4532fca9e2846224ed0a68654c9fd6ab15e0cf',
        'name' => '__root__',
        'dev' => true,
    ),
    'versions' => array(
        '__root__' => array(
            'pretty_version' => 'dev-develop',
            'version' => 'dev-develop',
            'type' => 'library',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'reference' => '4d4532fca9e2846224ed0a68654c9fd6ab15e0cf',
            'dev_requirement' => false,
        ),
        'cakephp/cache' => array(
            'pretty_version' => '3.9.0',
            'version' => '3.9.0.0',
            'type' => 'library',
            'install_path' => __DIR__ . '/../cakephp/cache',
            'aliases' => array(),
            'reference' => 'e8ec4e77fb288adda318e08053f5f540870aeb9d',
            'dev_requirement' => false,
        ),
        'cakephp/core' => array(
            'pretty_version' => '3.x-dev',
            'version' => '3.9999999.9999999.9999999-dev',
            'type' => 'library',
            'install_path' => __DIR__ . '/../cakephp/core',
            'aliases' => array(),
            'reference' => '716300a55ac86b7456e52258d3f50545545d2d6b',
            'dev_requirement' => false,
        ),
        'cakephp/utility' => array(
            'pretty_version' => '3.x-dev',
            'version' => '3.9999999.9999999.9999999-dev',
            'type' => 'library',
            'install_path' => __DIR__ . '/../cakephp/utility',
            'aliases' => array(),
            'reference' => '51b0af31af3239f6141006bbd7cbc7b16aba40d6',
            'dev_requirement' => false,
        ),
        'colshrapnel/safemysql' => array(
            'pretty_version' => 'dev-master',
            'version' => 'dev-master',
            'type' => 'library',
            'install_path' => __DIR__ . '/../colshrapnel/safemysql',
            'aliases' => array(
                0 => '9999999-dev',
            ),
            'reference' => 'e64dacc23247b784595aa09d08f8683dc8982eb5',
            'dev_requirement' => false,
        ),
        'flowflow/core' => array(
            'pretty_version' => 'dev-master',
            'version' => 'dev-master',
            'type' => 'library',
            'install_path' => __DIR__ . '/../flowflow/core',
            'aliases' => array(
                0 => '9999999-dev',
            ),
            'reference' => 'bc6e01d454e2abe9f2b8c98ee5c6a6247c480073',
            'dev_requirement' => false,
        ),
        'flowflow/social' => array(
            'pretty_version' => 'dev-remove_open_api',
            'version' => 'dev-remove_open_api',
            'type' => 'library',
            'install_path' => __DIR__ . '/../flowflow/social',
            'aliases' => array(),
            'reference' => '004e39ca2bc8a71fbab53d7ed641d3333583a776',
            'dev_requirement' => false,
        ),
        'mashape/unirest-php' => array(
            'pretty_version' => 'v3.0.4',
            'version' => '3.0.4.0',
            'type' => 'library',
            'install_path' => __DIR__ . '/../mashape/unirest-php',
            'aliases' => array(),
            'reference' => '842c0f242dfaaf85f16b72e217bf7f7c19ab12cb',
            'dev_requirement' => false,
        ),
        'psr/simple-cache' => array(
            'pretty_version' => '1.0.1',
            'version' => '1.0.1.0',
            'type' => 'library',
            'install_path' => __DIR__ . '/../psr/simple-cache',
            'aliases' => array(),
            'reference' => '408d5eafb83c57f6365a3ca330ff23aa4a5fa39b',
            'dev_requirement' => false,
        ),
    ),
);

// phpcs:enable
