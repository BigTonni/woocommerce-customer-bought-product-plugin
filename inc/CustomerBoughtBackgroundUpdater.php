<?php
/**
 * Background Updater
 *
 * Uses https://github.com/A5hleyRich/wp-background-processing to handle DB
 * updates in the background.
 *
 * @class    WC_Background_Updater
 * @version  2.6.0
 * @package  WooCommerce/Classes
 * @category Class
 * @author   WooThemes
 */
namespace Javorszky\WooCommerce;
use WP_Background_Process;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WC_Background_Updater Class.
 */
class CBPU extends WP_Background_Process {

    /**
     * @var string
     */
    protected $action = 'wc_cbp_updater';

    /**
     * Dispatch updater.
     *
     * Updater will still run via cron job if this fails for any reason.
     */
    public function dispatch() {
        $dispatched = parent::dispatch();
        $logger     = wc_get_logger();

        if ( is_wp_error( $dispatched ) ) {
            $logger->error(
                sprintf( 'Unable to dispatch Customer Bought Product updater: %s', $dispatched->get_error_message() ),
                array( 'source' => 'wc_customer_bought_product' )
            );
        }
    }

    /**
     * Handle cron healthcheck
     *
     * Restart the background process if not already running
     * and data exists in the queue.
     */
    public function handle_cron_healthcheck() {
        if ( $this->is_process_running() ) {
            // Background process already running.
            return;
        }

        if ( $this->is_queue_empty() ) {
            // No data to process.
            $this->clear_scheduled_event();
            return;
        }

        $this->handle();
    }

    /**
     * Schedule fallback event.
     */
    protected function schedule_event() {
        if ( ! wp_next_scheduled( $this->cron_hook_identifier ) ) {
            wp_schedule_event( time() + 10, $this->cron_interval_identifier, $this->cron_hook_identifier );
        }
    }

    /**
     * Is the updater running?
     * @return boolean
     */
    public function is_updating() {
        return false === $this->is_queue_empty();
    }

    /**
     * Task
     *
     * Override this method to perform any actions required on each
     * queue item. Return the modified item for further processing
     * in the next pass through. Or, return false to remove the
     * item from the queue.
     *
     * @param string $callback Update callback function
     * @return mixed
     */
    protected function task( $offset = 0 ) {
        $source = array( 'source' => $this->action );

        wc_get_logger()->debug( 'starting task...', $source );

        $datastore = cmb()->data_store;

        return $datastore->sync_data( $offset, 10 );
    }

    /**
     * Complete
     *
     * Override if applicable, but ensure that the below actions are
     * performed, or, call parent::complete().
     */
    protected function complete() {
        $logger = wc_get_logger();
        $logger->info( 'Data update complete', array( 'source' => $this->action ) );

        parent::complete();
    }
}
