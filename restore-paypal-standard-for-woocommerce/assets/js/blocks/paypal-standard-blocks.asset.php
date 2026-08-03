<?php
/**
 * Asset file for PayPal Standard Blocks integration
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
        'wp-i18n',
    ),
    'version'      => RPSFW_VERSION,
);
