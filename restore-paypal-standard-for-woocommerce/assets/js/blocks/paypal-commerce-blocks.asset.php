<?php
/**
 * Asset file for PayPal Commerce blocks integration
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
return array(
    'dependencies' => array(
        'wc-blocks-registry',
        'wc-settings',
        'wp-element',
        'wp-html-entities',
    ),
    'version' => defined('RPSFW_VERSION') ? RPSFW_VERSION : '3.1.0',
);
