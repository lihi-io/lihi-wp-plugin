<?php
namespace Lihi\ShortUrl;

/**
 * Bootstrap: loads all plugin dependencies in the correct order.
 *
 * Load order: helper → settings → interface → client → service → feature files.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/helper.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/client/lihi-exceptions.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/client/lihi-client-interface.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/client/lihi-client.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/store/lihi-token-store.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/service/lihi-service.php';

require_once plugin_dir_path( __FILE__ ) . 'includes/shorturl-column-ajax.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/add-shorturl-column.php';
