<?php
namespace Javorszky\WooCommerce;
use InvalidArgumentException;

class CustomerBoughtProductDataStore implements CustomerBoughtProductInterface {
    private $table_name = null;
    private $temp_table_name = null;
    private $log_source = [ 'source' => 'wc_customer_bought_product' ];
    private $logger = null;

    public function __construct( $table_name ) {
        if ( ! is_string( $table_name ) || '' === $table_name ) {
            throw new InvalidArgumentException( 'Table name specified was not a string, or was an empty string' );
        }

        $this->table_name = $table_name;
        $this->temp_table_name = 'temp_' . $table_name;
        add_action( 'plugins_loaded', [ $this, 'set_logger' ] );
    }

    public function set_logger() {
        $this->logger = wc_get_logger();
    }

    private function log( $message ) {
        $this->logger->info( $message, $this->log_source );
    }

    public function setup() {
        global $wpdb;
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        dbDelta( $this->get_schema() );
    }

    public function reset() {
        global $wpdb;

        delete_option( 'wc_customer_bought_product_has_synced' );
        $truncate = "TRUNCATE TABLE `{$wpdb->prefix}{$this->table_name}`;";

    }

    public function sync_data( $offset = 0, $limit = 15000 ) {
        global $wpdb;

        // First, let's truncate both tables as we're doing a full resync
        $truncate_temp = "TRUNCATE TABLE `{$wpdb->prefix}{$this->temp_table_name}`;";
        $truncate      = "TRUNCATE TABLE `{$wpdb->prefix}{$this->table_name}`;";

        /**
         * Insert into the temp table. Because data sanity isn't great in WordPress, it could happen that
         * the value stored in the postmeta table is empty string, or simply missing. Because the final table
         * has BIGINT(20) column definitions, and MySQL strict mode will reject wanting to insert a '' into a
         * numeric column, and I don't feel like changing the database mode to TRADITIONAL, this is a
         * workaround. Which is still really really fast.
         *
         * @see https://dev.mysql.com/doc/refman/5.7/en/sql-mode.html#sql-mode-strict
         *
         *    "For transactional tables, an error occurs for invalid or missing values in a data-change statement
         *    when either STRICT_ALL_TABLES or STRICT_TRANS_TABLES is enabled. The statement is aborted and rolled back."
         */
        $insert_into_temp = $wpdb->prepare( "
            INSERT INTO `{$wpdb->prefix}{$this->temp_table_name}` (`product_id`, `variation_id`, `order_id`, `customer_id`, `customer_email`)
            SELECT
            oim.meta_value AS 'product_id',
            oim2.meta_value AS 'variation_id',
            p.ID AS 'order_id',
            pm.meta_value AS 'customer_id',
            pm2.meta_value AS 'customer_email'
            FROM wp_posts p
            LEFT JOIN wp_postmeta pm ON p.ID = pm.post_id
            LEFT JOIN wp_postmeta pm2 ON p.ID = pm2.post_id
            LEFT JOIN wp_woocommerce_order_items oi ON p.ID = oi.order_id
            LEFT JOIN wp_woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
            LEFT JOIN wp_woocommerce_order_itemmeta oim2 ON oi.order_item_id = oim2.order_item_id
            WHERE p.post_type = 'shop_order'
            AND oi.order_item_type = 'line_item'
            AND oim.meta_key = '_product_id'
            AND oim2.meta_key = '_variation_id'
            AND pm.meta_key = '_customer_user'
            AND pm2.meta_key = '_billing_email'
            ORDER BY p.ID ASC
            LIMIT %d, %d
        ", $offset, $limit );

        //  Fix the data in the temp table
        $fix_product_id = "
            UPDATE {$wpdb->prefix}{$this->temp_table_name} SET `product_id` = 0 WHERE (`product_id` = '' || `product_id` IS NULL );
        ";

        $fix_variation_id = "
            UPDATE {$wpdb->prefix}{$this->temp_table_name} SET `variation_id` = 0 WHERE (`variation_id` = '' || `variation_id`  IS NULL );
        ";

        $fix_customer_id = "
            UPDATE {$wpdb->prefix}{$this->temp_table_name} SET `customer_id` = 0 WHERE (`customer_id` = '' || `customer_id`  IS NULL );
        ";

        // and finally move all the data over to the other table
        $migrate_data = "
            INSERT IGNORE INTO `{$wpdb->prefix}{$this->table_name}` (`product_id`, `variation_id`, `order_id`, `customer_id`, `customer_email`)
            SELECT
                `product_id`,
                `variation_id`,
                `order_id`,
                `customer_id`,
                `customer_email`
            FROM {$wpdb->prefix}{$this->temp_table_name}
        ";

        // and truncate the temp table

        // timer start
        $start = $super_start = microtime( true );

        // Start
        $this->log( sprintf( PHP_EOL . PHP_EOL . 'Starting syncing with offset %d and limit %d...', $offset, $limit ) );

        // Truncating table
        $wpdb->query( $truncate_temp );
        $this->log( 'Truncated temp table' );
        // $wpdb->query( $truncate );
        // $this->log( 'Truncated final table' );

        // Inserting into temp table
        $wpdb->query( $insert_into_temp );

        /**
         * Important! We're using this to figure out whether we still have work to do. If this is non-zero, then we
         * still have data left.
         * @var integer
         */
        $rows_affected = $wpdb->rows_affected;
        $this->log( sprintf( 'Inserted data into temp table. Took %s sec. Touched %d rows.', microtime( true ) - $start, $rows_affected ) );

        // Fixing product id
        $start = microtime( true );
        $wpdb->query( $fix_product_id );
        $this->log( sprintf( 'Fixed product ids. Took %s sec.', microtime( true ) - $start ) );

        // Fixing variation id
        $start = microtime( true );
        $wpdb->query( $fix_variation_id );
        $this->log( sprintf( 'Fixed variation ids. Took %s sec.', microtime( true ) - $start ) );

        // Fixing customer id
        $start = microtime( true );
        $wpdb->query( $fix_customer_id );
        $this->log( sprintf( 'Fixed product ids. Took %s sec.', microtime( true ) - $start ) );

        // Moving over to final table
        $start = microtime( true );
        $wpdb->query( $migrate_data );
        $this->log( sprintf( 'Inserted data into final table. Took %s sec.', microtime( true ) - $start ) );

        // truncating temp table
        $wpdb->query( $truncate_temp );

        // The end
        $end = microtime( true );
        $this->log( sprintf( 'Migrating all data on this site took %s seconds.', $end - $super_start ) );

        $ret = ( ! $rows_affected ) ? false: $offset + $limit;

        $this->log( sprintf( 'Returning the following data: %s.', PHP_EOL . var_export( [
            'return value' => $ret,
            'offset' => $offset,
            'limit' => $limit,
            'rows affected' => $rows_affected
        ], true ) . PHP_EOL ) );

        return $ret;
    }

    public function query( $product_id, $user_id, $customer_email ) {
        global $wpdb;

        $emails = array();

        if ( $user_id ) {
            $user = get_user_by( 'id', $user_id );

            if ( isset( $user->user_email ) ) {
                $emails[] = $user->user_email;
            }
        }

        if ( is_email( $customer_email ) ) {
            $emails[] = $customer_email;
        }

        // filter out the empty emails as we don't want to accidentally match orders where
        // billing email wasn't given, and user has an empty email
        $emails = array_filter( $emails );

        if ( empty( $emails ) ) {

            // This is needed in case no emails are valid, but we still have a customer id.
            // This will make sure we don't accidentall match an email.
            $emails[] = uniqid( true );
        }

        // in case the passed in email and user's email are the same. We only need it once
        array_unique( $emails );

        $statuses        = wc_get_is_paid_statuses();
        $status_qry_part = array();
        $email_qry_part  = array();
        $where           = array();

        $where[] = $product_id; // product_id
        $where[] = $product_id; // variation_id

        // emails
        foreach ( $emails as $email ) {
            $email_qry_part[] = '%s';
            $where[] = $email;
        }
        $email_qry = implode( ', ', $email_qry_part );

        // order statuses
        foreach ( $statuses as $status ) {
            $status_qry_part[] = '%s';
            $where[] = 'wc-' . $status;
        }
        $status_qry = implode( ', ', $status_qry_part );

        $where[] = $user_id;

        // and now, the query
        $query = $wpdb->prepare( "SELECT cbp.id FROM {$wpdb->prefix}{$this->table_name} cbp
            LEFT JOIN {$wpdb->posts} p on p.ID = cbp.order_id
            WHERE (cbp.product_id = %d OR cbp.variation_id = %d)
            AND (cbp.customer_email IN ({$email_qry}) OR cbp.customer_id = %d)
            AND p.order_status IN ({$status_qry})
            LIMIT 1",
            $where
        );

        $results = $wpdb->get_results( $query, ARRAY_N );

        return ! empty( $results );
    }

    public function remove_entry( $post_id ) {
        global $wpdb;

        $query = $wpdb->prepare( "
            DELETE FROM {$wpdb->prefix}{$this->table_name}
            WHERE `product_id` = %d,
            OR `variation_id` = %d
            OR `order_id` = %d,
            $post_id,
            $post_id,
            $post_id
        " );

        $wpdb->query( $query );
    }

    public function get_schema() {
        $final_table = $this->get_final_schema();
        $temp_table = $this->get_temp_schema();
        return $final_table . $temp_table;
    }

    public function get_final_schema() {
        global $wpdb;

        $collate = '';

        if ( $wpdb->has_cap( 'collation' ) ) {
            $collate = $wpdb->get_charset_collate();
        }

        $tables = "
        CREATE TABLE {$wpdb->prefix}{$this->table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            variation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            customer_id BIGINT(20) UNSIGNED NOT NULL,
            customer_email VARCHAR(100) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            UNIQUE search (product_id, variation_id, customer_id, customer_email, order_id),
            INDEX order_id (order_id)
        ) $collate;";

        return $tables;
    }

    public function get_temp_schema() {
         global $wpdb;

        $collate = '';

        if ( $wpdb->has_cap( 'collation' ) ) {
            $collate = $wpdb->get_charset_collate();
        }

        return  "CREATE TABLE {$wpdb->prefix}{$this->temp_table_name} (
            product_id VARCHAR(20),
            variation_id VARCHAR(20),
            order_id BIGINT(20) UNSIGNED NOT NULL,
            customer_id VARCHAR(20),
            customer_email VARCHAR(100),
            INDEX (product_id),
            -- INDEX product_id (product_id),
            INDEX variation_id (variation_id),
            INDEX customer_id (customer_id)
        ) $collate;";
    }
}
