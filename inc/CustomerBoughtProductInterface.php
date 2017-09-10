<?php
namespace Javorszky\WooCommerce;

interface CustomerBoughtProductInterface {
    public function setup();
    public function sync_data();
    public function query( $product_id, $user_id, $customer_email );
}