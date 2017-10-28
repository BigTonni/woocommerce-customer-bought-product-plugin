<?php
namespace Javorszky\WooCommerce;
use CBPU;

final class CustomerBoughtProduct {
    public $data_store = null;

    public function __construct( CustomerBoughtProductInterface $data_store ) {
        $this->data_store = $data_store;
    }

    public function init() {
        register_activation_hook( __FILE__, array( $this, 'setup_table' ) );

        add_action( 'init', array( $this, 'maybe_start_sync' ) );

        add_filter( 'woocommerce_customer_bought_product', array( $this, 'query' ), 10, 4 );

        add_action( 'admin_notices', array( $this, 'update_notice' ) );

        // when there's a new order

        // when a line item is deleted / added

        // when the post status of a shop order is updated
    }

    public function maybe_start_sync() {
        $classname = __NAMESPACE__ . '\CBPU';
        $updater = new $classname();
    }

    public function setup_table() {
        $this->data_store->setup();
        $this->data_store->sync_data();
    }

    public function query( $bought, $customer_email, $user_id, $product_id ) {
        return $this->data_store->query( $product_id, $user_id, $customer_email );
    }

    /**
     * If we need to update, include a message with the update button.
     */
    public static function update_notice() {
        // wp_die( es_preit( array( 'admin notice' ), true ) );
        if ( ! get_option( 'wc_customer_bought_product_has_synced', false ) ) {
            $classname = __NAMESPACE__ . '\CBPU';
            $updater = new $classname();


            if ( $updater->is_updating() || ! empty( $_GET['do_update_wc_customer_bought_product'] ) ) {
                include WC_CBT_PATH . 'templates/CBP_Updating.php';
            } else {
                include WC_CBT_PATH . 'templates/CBP_Update.php';
            }
        } else {
            include WC_CBT_PATH . 'templates/CBP_Updated.php';
        }
    }
}
