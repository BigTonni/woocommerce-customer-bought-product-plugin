<?php
namespace Javorszky\WooCommerce;
use WP_Background_Process;

final class CustomerBoughtProduct {
    public $data_store = null;
    public $updater    = null;

    public function __construct( CustomerBoughtProductInterface $data_store, WP_Background_Process $updater ) {
        $this->updater = $updater;
        $this->data_store = $data_store;
    }

    public function init() {
        register_activation_hook( __FILE__, array( $this, 'setup_table' ) );

        add_action( 'init', array( $this, 'maybe_start_sync' ) );

        add_filter( 'woocommerce_customer_bought_product', array( $this, 'query' ), 10, 4 );

        add_action( 'admin_notices', array( $this, 'show_notices' ) );
        add_action( 'admin_notices', array( $this, 'hide_notice' ), 6 );

        // when there's a new order
        add_action( 'deleted_post', array( $this, 'remove_entry' ) );

        // when a line item is deleted / added

        // when the post status of a shop order is updated
    }

    public function maybe_start_sync() {
        if ( ! empty( $_GET['do_update_wc_customer_bought_product'] ) && ! get_option( 'wc_customer_bought_product_has_synced', false ) ) {
            $this->updater->push_to_queue( 0 );
            $this->updater->save()->dispatch();
        }
    }

    public function setup_table() {
        $this->data_store->setup();
    }

    public function query( $bought, $customer_email, $user_id, $product_id ) {
        return $this->data_store->query( $product_id, $user_id, $customer_email );
    }

    public function remove_entry( $post_id ) {
        $this->data_store->remove_entry( $post_id );
    }

    /**
     * If we need to update, include a message with the update button.
     */
    public static function show_notices() {
        if ( ! get_option( 'wc_customer_bought_product_has_synced', false ) ) {
            if ( $this->updater->is_updating() || ! empty( $_GET['do_update_wc_customer_bought_product'] ) ) {
                include WC_CBT_PATH . 'templates/CBP_Updating.php';
            } else {
                include WC_CBT_PATH . 'templates/CBP_Update.php';
            }
        } elseif ( ! get_option( 'wc_customer_bought_product_complete_dismissed', false ) ) {
            include WC_CBT_PATH . 'templates/CBP_Updated.php';
        }
    }

    public static function hide_notice() {
        if ( isset( $_GET['dismiss_wc_customer_bought_product_notice'] ) && isset( $_GET['_wc_cbp_notice_nonce'] ) ) {
            if ( ! wp_verify_nonce( $_GET['_wc_cbp_notice_nonce'], 'dismiss_wc_customer_bought_product_notice_nonce' ) ) {
                wp_die( __( 'Action failed. Please refresh the page and retry.', 'woocommerce' ) );
            }

            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                wp_die( __( 'Cheatin&#8217; huh?', 'woocommerce' ) );
            }
            update_option( 'wc_customer_bought_product_complete_dismissed', true );
        }
    }
}
