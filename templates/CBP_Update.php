<?php
/**
 * Admin View: Notice - Update
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div id="message" class="updated woocommerce-message wc-connect">
	<p><strong><?php _e( 'WooCommerce Customer Bought Product data update', WC_CBT_DOMAIN ); ?></strong> &#8211; <?php _e( 'We need to sync our helper tables with your site\'s data.', WC_CBT_DOMAIN ); ?></p>
	<p class="submit"><a href="<?php echo esc_url( add_query_arg( 'do_update_wc_customer_bought_product', 'true' ) ); ?>" class="wc-update-now button-primary"><?php _e( 'Begin synchronisation', 'woocommerce' ); ?></a></p>
</div>
<script type="text/javascript">
	jQuery( '.wc-update-now' ).click( 'click', function() {
		return window.confirm( '<?php echo esc_js( __( 'It is strongly recommended that you backup your database before proceeding. Are you sure you wish to run the updater now?', 'woocommerce' ) ); ?>' ); // jshint ignore:line
	});
</script>
