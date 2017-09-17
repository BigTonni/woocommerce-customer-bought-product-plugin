<?php
namespace Javorszky\WooCommerce;

class CustomerBoughtProductDataStore implements CustomerBoughtProductInterface {
    private $table_name = null;
    private $temp_table_name = null;

    public function __construct( $table_name ) {
        if ( ! is_string( $table_name ) || '' === $table_name ) {
            throw new InvalidArgumentException( 'Table name specified was not a string, or was an empty string' );
        }

        $this->table_name = $table_name;
        $this->temp_table_name = 'temp_' . $table_name;
    }

    public function setup() {
        global $wpdb;
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        dbDelta( $this->get_schema() );
    }

    public function sync_data() {
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
        $insert_into_temp = "
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
            AND pm2.meta_key = '_billing_email';
        ";

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
        $l = wc_get_logger();
        $source = array( array( 'source' => 'wc_customer_bought_product' ) );

        $l->info( 'Starting syncing...', $source );
        $wpdb->query( $truncate_temp );
        $l->info( 'Truncated temp table', $source );
        $wpdb->query( $truncate );
        $l->info( 'Truncated final table', $source );
        $wpdb->query( $insert_into_temp );
        $l->info( sprintf( 'Inserted data into temp table. Took %s sec.', microtime( true ) - $start ), $source );
        $start = microtime( true );
        $wpdb->query( $fix_product_id );
        $l->info( sprintf( 'Fixed product ids. Took %s sec.', microtime( true ) - $start ), $source );
        $start = microtime( true );
        $wpdb->query( $fix_variation_id );
        $l->info( sprintf( 'Fixed variation ids. Took %s sec.', microtime( true ) - $start ), $source );
        $start = microtime( true );

        $wpdb->query( $fix_customer_id );
        $l->info( sprintf( 'Fixed product ids. Took %s sec.', microtime( true ) - $start ), $source );
        $start = microtime( true );
        $wpdb->query( $migrate_data );
        $l->info( sprintf( 'Inserted data into final table. Took %s sec.', microtime( true ) - $start ), $source );
        $wpdb->query( $truncate_temp );
        $end = microtime( true );
        wc_get_logger()->info( sprintf( 'Migrating all data on this site took %s seconds.', $end - $super_start ), array( 'source' => 'wc_customer_bought_product' ) );
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

        // order statuses
        foreach ( $statuses as $status ) {
            $status_qry_part[] = '%s';
            $where[] = 'wc-' . $status;
        }
        $status_qry = implode( ', ', $status_qry_part );

        // emails
        foreach ( $emails as $email ) {
            $email_qry_part[] = '%s';
            $where[] = $email;
        }
        $email_qry = implode( ', ', $email_qry_part );

        $where[] = $user_id;

        // and now, the query
        $query = $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}{$this->table_name}
            WHERE (product_id = %d OR variation_id = %d)
            AND order_status IN ({$status_qry})
            AND (customer_email IN ({$email_qry}) OR customer_id = %d)
            LIMIT 1",
            $where
        );

        $results = $wpdb->get_results( $query, ARRAY_N );

        return ! empty( $results );
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
