<?php
/**
 * Asset dependencies for PayPal Pay Later Blocks script.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
return array(
    'dependencies' => array( 'wp-element', 'wp-plugins', 'wp-data', 'wc-settings', 'wc-blocks-checkout' ),
    'version'      => '1.0.0',
);
