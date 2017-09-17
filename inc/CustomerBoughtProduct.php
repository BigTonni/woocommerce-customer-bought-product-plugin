<?php
namespace Javorszky\WooCommerce;

final class CustomerBoughtProduct {
    private $data_store = null;

    public function __construct( CustomerBoughtProductInterface $data_store ) {
        $this->data_store = $data_store;
    }

    public function init() {
        register_activation_hook( __FILE__, array( $this, 'setup_table' ) );
        add_filter( 'woocommerce_customer_bought_product', array( $this, 'query' ), 10, 4 );

        // when there's a new order

        // when a line item is deleted / added

        // when the post status of a shop order is updated
    }

    public function setup_table() {
        $this->data_store->setup();
        $this->data_store->sync_data();
    }

    public function query( $bought, $customer_email, $user_id, $product_id ) {
        return $this->data_store->query( $product_id, $user_id, $customer_email );
    }
}
