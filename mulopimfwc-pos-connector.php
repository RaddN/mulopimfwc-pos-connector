<?php
/**
 * Plugin Name: Multi Location POS Connector
 * Plugin URI: https://plugincy.com/multi-location-product-and-inventory-management
 * Description: Connects Multi Location Product & Inventory Management stock and pricing with supported POS systems. OpenPOS is supported in v1.
 * Version: 1.0.0
 * Author: plugincy
 * Author URI: https://plugincy.com/
 * Text Domain: mulopimfwc-pos-connector
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * Requires Plugins: woocommerce, multi-location-product-and-inventory-management-pro
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('MULOPIMFWC_POS_CONNECTOR_FILE')) {
    define('MULOPIMFWC_POS_CONNECTOR_FILE', __FILE__);
}

if (!defined('MULOPIMFWC_POS_CONNECTOR_DIR')) {
    define('MULOPIMFWC_POS_CONNECTOR_DIR', plugin_dir_path(__FILE__));
}

if (!defined('MULOPIMFWC_POS_CONNECTOR_URL')) {
    define('MULOPIMFWC_POS_CONNECTOR_URL', plugin_dir_url(__FILE__));
}

if (!defined('MULOPIMFWC_POS_CONNECTOR_VERSION')) {
    define('MULOPIMFWC_POS_CONNECTOR_VERSION', '1.0.0');
}

require_once MULOPIMFWC_POS_CONNECTOR_DIR . 'includes/class-mulopimfwc-pos-connector.php';

MULOPIMFWC_POS_Connector::instance();
