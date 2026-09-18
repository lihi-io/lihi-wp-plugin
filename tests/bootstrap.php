<?php

$vendor_dir = getenv('LIHI_VENDOR_DIR') ?: dirname(__DIR__) . '/vendor';
$vendor_dir = rtrim($vendor_dir, '/\\');

require_once $vendor_dir . '/antecedent/patchwork/Patchwork.php';
require_once $vendor_dir . '/autoload.php';

$_tests_dir = getenv('WP_TESTS_DIR') ?: getenv('WP_PHPUNIT__DIR');

if (!$_tests_dir) {
    $_tests_dir = $vendor_dir . '/wp-phpunit/wp-phpunit';
}

$_tests_dir = rtrim($_tests_dir, '/\\');

if (!file_exists($_tests_dir . '/includes/functions.php')) {
    exit("Error: functions.php not found in {$_tests_dir}/includes/functions.php\n");
}

if (!file_exists($_tests_dir . '/includes/bootstrap.php')) {
    exit("Error: bootstrap.php not found in {$_tests_dir}/includes/bootstrap.php\n");
}

require_once $_tests_dir . '/includes/functions.php';

function _manually_load_plugin(): void
{
    update_option(
        'lihi_auth_epoch',
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        false
    );

    // A complete authenticated credential bundle must exist before plugin load
    // so the conditional UI-hook registration in add-shorturl-column.php fires.
    update_option( 'lihi_auth_tokens', [
        'email'         => 'test@example.com',
        'uuid'          => '2df6f4f1-2a75-4d0e-9ce0-7c70e8d7bb9e',
        'access_token'  => 'header.payload.signature',
        'refresh_token' => 'refresh.payload.signature',
    ], false );
    require_once dirname(__DIR__) . '/lihi-short-url/lihi-short-url.php';
}

tests_add_filter('muplugins_loaded', '_manually_load_plugin');

require $_tests_dir . '/includes/bootstrap.php';
