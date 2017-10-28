<?php
/**
 * Admin View: Notice - Updated
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

?>
<div id="message" class="updated woocommerce-message wc-connect woocommerce-message--success">
    <p><?php _e( 'WooCommerce Customer Bought Product data update complete. Thank you for updating to the latest version!', WC_CBT_DOMAIN ); ?> <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'dismiss_wc_customer_bought_product_notice', 'update', remove_query_arg( 'do_update_wc_customer_bought_product' ) ), 'dismiss_wc_customer_bought_product_notice_nonce', '_wc_cbp_notice_nonce' ) ); ?>">
        <?php _e( 'Dismiss this', 'woocommerce' ); ?>
    </a></p>
</div>
