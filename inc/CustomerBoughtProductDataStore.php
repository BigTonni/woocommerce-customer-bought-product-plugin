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

    }

    public function query() {

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
            variation_id BIGINT(20) UNSIGNED NOT NULL,
            order_id BIGINT(20) UNSIGNED NOT NULL,
            order_status VARCHAR(20) NOT NULL,
            customer_id BIGINT(20) UNSIGNED NOT NULL,
            customer_email VARCHAR(100),
            customer_billing_email VARCHAR(100),
            PRIMARY KEY (id),
            INDEX search (product_id, variation_id, order_status, customer_id, customer_email, customer_billing_email),
            INDEX order_id (order_id),
            FOREIGN KEY (product_id)
                REFERENCES {$wpdb->posts}(ID)
                ON DELETE CASCADE,
            FOREIGN KEY (variation_id)
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