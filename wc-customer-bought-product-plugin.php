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

require_once 'inc/CustomerBoughtProductInterface.php';
require_once 'inc/CustomerBoughtProductDataStore.php';



class CustomerBoughtProduct {
    private $table = null;
    private $data_store = null;

    function __construct( CustomerBoughtProductInterface $data_store ) {
        global $wpdb;
        $this->data_store = $data_store;

    }

    function init() {
        register_activation_hook( __FILE__, array( $this, 'setup_table' ) );
        // add_filter( 'woocommerce_customer_bought_product', array( $this, 'query' ), 10, 4 );
    }

    public static function setup_table() {
        $this->data_store->setup();
        $this->data_store->sync_data();
    }

    public static function query( $bought, $customer_email, $user_id, $product_id ) {
        return $bought;
    }
}

$cmb = new \Javorszky\WooCommerce\CustomerBoughtProduct( new CustomerBoughtProductDataStore( 'wc_customer_bought_product' ) );
$cmb->init();