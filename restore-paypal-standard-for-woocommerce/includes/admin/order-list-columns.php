<?php
/**
 * Orders list table: the always-on "Gateway" column.
 *
 * Shows which of THIS plugin's gateways (PayPal Standard, PayPal Commerce,
 * Stripe) processed each order; blank for any other gateway. It is independent
 * of the built-in subscriptions module — it stays available whether or not that
 * module is active (and whether or not WooCommerce Subscriptions is installed).
 *
 * The subscriptions module adds its own "Type" (Initial / Renewal) column
 * separately, gated on that module being active; both columns share the
 * gateway detector below.
 *
 * @package Restore_PayPal_Standard_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin-facing label for one of this plugin's gateways, or an empty string for
 * anything else (another plugin's gateway, or no payment method at all).
 *
 * The single source of truth for these names. Admin surfaces say "PayPal
 * Commerce" rather than "PayPal" because this plugin also ships PayPal
 * Standard, and the short name would not say which one took the money.
 * Customer-facing surfaces deliberately do NOT use this — see
 * RPSFW_Subscription::get_gateway_title(), where shoppers just see "PayPal".
 *
 * @since 4.0.0
 *
 * @param string $gateway_id Gateway id, e.g. 'rpsfw_stripe'.
 * @return string Label, or '' when the gateway is not one of ours.
 */
function rpsfw_gateway_label( $gateway_id ) {
	$gateways = array(
		'restore_paypal_standard' => __( 'PayPal Standard', 'restore-paypal-standard-for-woocommerce' ),
		'rpsfw_paypal_commerce'   => __( 'PayPal Commerce', 'restore-paypal-standard-for-woocommerce' ),
		'rpsfw_stripe'            => __( 'Stripe', 'restore-paypal-standard-for-woocommerce' ),
	);

	return isset( $gateways[ $gateway_id ] ) ? $gateways[ $gateway_id ] : '';
}

/**
 * Map an order's payment method to one of this plugin's gateway labels, or an
 * empty string when the order was paid through anything else. Shared by the
 * Gateway column here and the subscriptions module's Type column.
 *
 * @param WC_Order $order Order.
 * @return string
 */
function rpsfw_order_gateway_label( $order ) {
	if ( ! $order instanceof WC_Order ) {
		return '';
	}

	return rpsfw_gateway_label( $order->get_payment_method() );
}

/**
 * Add the Gateway column to the orders list table, right after Status.
 *
 * Registered at priority 10; the subscriptions module's Type column inserts at
 * priority 20 so the final order is Status | Type | Gateway.
 *
 * @param array $columns Columns.
 * @return array
 */
function rpsfw_add_order_gateway_column( $columns ) {
	if ( ! is_array( $columns ) || isset( $columns['rpsfw_order_gateway'] ) ) {
		return $columns;
	}

	$label   = __( 'Gateway', 'restore-paypal-standard-for-woocommerce' );
	$updated = array();

	foreach ( $columns as $key => $value ) {
		$updated[ $key ] = $value;
		if ( 'order_status' === $key ) {
			$updated['rpsfw_order_gateway'] = $label;
		}
	}

	if ( ! isset( $updated['rpsfw_order_gateway'] ) ) {
		$updated['rpsfw_order_gateway'] = $label;
	}

	return $updated;
}

/**
 * Render the Gateway column. HPOS passes a WC_Order, the legacy posts table
 * passes a post id.
 *
 * @param string       $column      Column id.
 * @param WC_Order|int $order_or_id Order or order id.
 */
function rpsfw_render_order_gateway_column( $column, $order_or_id ) {
	if ( 'rpsfw_order_gateway' !== $column ) {
		return;
	}

	$order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( $order_or_id );
	if ( ! $order instanceof WC_Order ) {
		return;
	}

	echo esc_html( rpsfw_order_gateway_label( $order ) );
}

// HPOS orders screen.
add_filter( 'woocommerce_shop_order_list_table_columns', 'rpsfw_add_order_gateway_column', 10 );
add_action( 'woocommerce_shop_order_list_table_custom_column', 'rpsfw_render_order_gateway_column', 10, 2 );
// Legacy posts-table orders screen.
add_filter( 'manage_edit-shop_order_columns', 'rpsfw_add_order_gateway_column', 10 );
add_action( 'manage_shop_order_posts_custom_column', 'rpsfw_render_order_gateway_column', 10, 2 );
