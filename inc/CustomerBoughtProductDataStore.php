<?php
namespace Javorszky\WooCommerce;

class CustomerBoughtProductDataStore implements CustomerBoughtProductInterface {
    private $table_name = null;

    public function __construct( $table_name ) {
        if ( ! is_string( $table_name ) || '' === $table_name ) {
            throw new InvalidArgumentException( 'Table name specified was not a string, or was an empty string' );
        }

        $this->table_name = $table_name;
    }

    public function setup() {
        global $wpdb;
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        dbDelta( $this->get_schema() );
    }

    public function sync_data() {
        global $wpdb;

        // First, truncate the table, we're doing a full resync
        // the insert into query takes around half a second querying about 30k rows that go into this

        $truncate = "TRUNCATE TABLE `{$wpdb->prefix}{$this->table_name}`;";
        $insert_into = "
            INSERT INTO `{$wpdb->prefix}{$this->table_name}` (`product_id`, `variation_id`, `order_id`, `order_status`, `customer_id`, `customer_email`)
            SELECT
            oim.meta_value AS 'product_id',
            oim2.meta_value AS 'variation_id',
            p.ID AS 'order_id',
            p.post_status AS 'order_status',
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

        $wpdb->query( $truncate );
        $wpdb->query( $insert_into );
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

    private function get_schema() {
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
            order_status VARCHAR(20) NOT NULL,
            customer_id BIGINT(20) UNSIGNED NOT NULL,
            customer_email VARCHAR(100) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            INDEX search (product_id, variation_id, order_status, customer_id, customer_email),
            INDEX order_id (order_id),
            FOREIGN KEY (product_id)
                REFERENCES {$wpdb->posts}(ID)
                ON DELETE CASCADE,
            FOREIGN KEY (order_id)
                REFERENCES {$wpdb->posts}(ID)
                ON DELETE CASCADE,
            FOREIGN KEY (customer_id)
                REFERENCES {$wpdb->users}(ID)
                ON DELETE CASCADE
        ) $collate;";

        return $tables;
    }
}