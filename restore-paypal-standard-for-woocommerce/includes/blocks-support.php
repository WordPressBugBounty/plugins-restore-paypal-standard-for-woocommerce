<?php
/**
 * WooCommerce Blocks Support
 *
 * @package Restore_PayPal_Standard_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the payment method with WooCommerce Blocks
 */
function rpsfw_register_payment_method_blocks_support() {
    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        return;
    }

    // Only load the class if WooCommerce Blocks is available
    require_once __DIR__ . '/class-wc-gateway-paypal-standard-blocks-support.php';

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function( $payment_method_registry ) {
            $payment_method_registry->register( new WC_Gateway_PayPal_Standard_Blocks_Support() );
        }
    );
}
add_action( 'woocommerce_blocks_loaded', 'rpsfw_register_payment_method_blocks_support' );

