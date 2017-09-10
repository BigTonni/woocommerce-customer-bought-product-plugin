<?php
namespace Javorszky\WooCommerce;

interface CustomerBoughtProductInterface {
    public function setup();
    public function sync_data();
    public function query();
}