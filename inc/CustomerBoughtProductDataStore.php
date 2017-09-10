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