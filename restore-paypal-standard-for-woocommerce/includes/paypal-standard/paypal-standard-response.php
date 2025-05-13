<?php
/**
 * Handle responses from PayPal.
 *
 * @package WooPayPalStandard
 */

defined( 'ABSPATH' ) || exit;

/**
 * rpsfw_Gateway_PayPal_Standard_Response class.
 */
abstract class rpsfw_Gateway_PayPal_Standard_Response {

    /**
     * Sandbox mode.
     *
     * @var bool
     */
    protected $sandbox = false;

    /**
     * Get the order from the PayPal 'Custom' variable.
     *
     * @param  string $raw_custom JSON Data passed back by PayPal.
     * @return bool|WC_Order      Order object or false if the order could not be found.
     */
    protected function get_paypal_order( $raw_custom ) {
        // We have the data in the correct format, so get the order.
        $custom = json_decode( $raw_custom );

        if ( $custom && is_object( $custom ) ) {
            $order_id  = $custom->order_id;
            $order_key = $custom->order_key;
        } else {
            // Nothing was found.
            rpsfw_Gateway_PayPal_Standard::log( 'Order ID and key were not found in "custom".', 'error' );
            return false;
        }

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            // We have an invalid $order_id, probably because invoice_prefix has changed.
            $order_id = wc_get_order_id_by_order_key( $order_key );
            $order    = wc_get_order( $order_id );
        }

        if ( ! $order || ! hash_equals( $order->get_order_key(), $order_key ) ) {
            rpsfw_Gateway_PayPal_Standard::log( 'Order keys do not match.', 'error' );
            return false;
        }

        return $order;
    }
} 