<?php
/**
 * Plugin Name:     WooCommerce Customer Bought Product Experimental Plugin
 * Plugin URI:      https://javorszky.co.uk
 * Description:     Experimental feature plugin to make the wc_customer_bought_email function be a lot faster
 * Author:          Gabor Javorszky <gabor@javorszky.co.uk>
 * Author URI:      https://javorszky.co.uk
 * Text Domain:     wc-customer-bought-product-plugin
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         Wc_Customer_Bought_Product_Plugin
 */
namespace Javorszky\WooCommerce;
use WP_Async_Request;
use WP_Background_Process;

require_once 'inc/CustomerBoughtProductInterface.php';
require_once 'inc/CustomerBoughtProductDataStore.php';
require_once 'inc/CustomerBoughtProduct.php';
require_once 'inc/CustomerBoughtBackgroundUpdater.php';

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_Async_Request', false ) ) {

    include_once( dirname( __FILE__ ) . '/libraries/wp-async-request.php' );
}

if ( ! class_exists( 'WP_Background_Process', false ) ) {
    include_once( dirname( __FILE__ ) . '/libraries/wp-background-process.php' );
}

final class CustomerBoughtProductFactory {
    public static function create() {
        static $plugin = null;

        if ( null === $plugin ) {
            $plugin = new CustomerBoughtProduct( new CustomerBoughtProductDataStore( 'wc_customer_bought_product' ) );
        }

        return $plugin;
    }
}

function cmb() {
    return CustomerBoughtProductFactory::create();
}

cmb()->init();
