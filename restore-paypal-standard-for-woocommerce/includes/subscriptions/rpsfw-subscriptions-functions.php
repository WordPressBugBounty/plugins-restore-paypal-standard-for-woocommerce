<?php
/**
 * Helper functions for the native subscriptions module.
 *
 * @package Restore_PayPal_Standard_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * All subscription statuses (unprefixed) with labels.
 *
 * @return array status => label
 */
function rpsfw_get_subscription_statuses() {
	return array(
		'pending'        => __( 'Pending', 'restore-paypal-standard-for-woocommerce' ),
		'active'         => __( 'Active', 'restore-paypal-standard-for-woocommerce' ),
		'on-hold'        => __( 'On hold', 'restore-paypal-standard-for-woocommerce' ),
		'pending-cancel' => __( 'Pending cancellation', 'restore-paypal-standard-for-woocommerce' ),
		'cancelled'      => __( 'Cancelled', 'restore-paypal-standard-for-woocommerce' ),
		'expired'        => __( 'Expired', 'restore-paypal-standard-for-woocommerce' ),
	);
}

/**
 * Label for a status.
 *
 * @param string $status Status (unprefixed).
 * @return string
 */
function rpsfw_get_subscription_status_label( $status ) {
	$statuses = rpsfw_get_subscription_statuses();
	return isset( $statuses[ $status ] ) ? $statuses[ $status ] : ucfirst( $status );
}

/**
 * Get a subscription object.
 *
 * @param int|WP_Post|RPSFW_Subscription $subscription Subscription id or post.
 * @return RPSFW_Subscription|false
 */
function rpsfw_get_subscription( $subscription ) {
	$object = new RPSFW_Subscription( $subscription );
	return $object->exists() ? $object : false;
}

/**
 * Query subscriptions.
 *
 * @param array $args {
 *     Optional query args.
 *
 *     @type string       $status      Status (unprefixed) or 'any'.
 *     @type int          $customer_id Limit to a WP user.
 *     @type string       $gateway_sub_id Processor subscription id.
 *     @type int          $limit       Max results (-1 = all). Default -1.
 * }
 * @return RPSFW_Subscription[]
 */
function rpsfw_get_subscriptions( $args = array() ) {
	$defaults = array(
		'status'         => 'any',
		'customer_id'    => 0,
		'gateway_sub_id' => '',
		'limit'          => -1,
	);
	$args = wp_parse_args( $args, $defaults );

	$query_args = array(
		'post_type'      => RPSFW_Subscriptions_Post_Type::POST_TYPE,
		'posts_per_page' => (int) $args['limit'],
		'orderby'        => 'ID',
		'order'          => 'DESC',
		'fields'         => 'ids',
	);

	if ( 'any' === $args['status'] ) {
		$query_args['post_status'] = array_map(
			function ( $status ) {
				return 'rpsfw-' . $status;
			},
			array_keys( rpsfw_get_subscription_statuses() )
		);
	} else {
		$query_args['post_status'] = 'rpsfw-' . str_replace( 'rpsfw-', '', $args['status'] );
	}

	$meta_query = array();
	if ( $args['customer_id'] ) {
		$meta_query[] = array(
			'key'   => '_rpsfw_customer_id',
			'value' => (int) $args['customer_id'],
		);
	}
	if ( $args['gateway_sub_id'] ) {
		$meta_query[] = array(
			'key'   => '_rpsfw_gateway_sub_id',
			'value' => $args['gateway_sub_id'],
		);
	}
	if ( $meta_query ) {
		$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	}

	$ids = get_posts( $query_args );

	$subscriptions = array();
	foreach ( $ids as $id ) {
		$subscription = rpsfw_get_subscription( $id );
		if ( $subscription ) {
			$subscriptions[] = $subscription;
		}
	}
	return $subscriptions;
}

/**
 * Find the subscription record for a processor subscription id
 * (PayPal "I-..." or Stripe "sub_...").
 *
 * This is the primary webhook lookup. It only matches records created by
 * THIS module, so it safely coexists with the WooCommerce Subscriptions
 * plugin integrations (which store their ids on shop_subscription posts).
 *
 * @param string $gateway_sub_id Processor subscription id.
 * @return RPSFW_Subscription|false
 */
function rpsfw_get_subscription_by_gateway_id( $gateway_sub_id ) {
	if ( empty( $gateway_sub_id ) ) {
		return false;
	}
	$found = rpsfw_get_subscriptions(
		array(
			'gateway_sub_id' => $gateway_sub_id,
			'limit'          => 1,
		)
	);
	return $found ? $found[0] : false;
}

/**
 * Get the subscriptions created from a given (parent) order.
 *
 * @param int|WC_Order $order Order or id.
 * @return RPSFW_Subscription[]
 */
function rpsfw_get_subscriptions_for_order( $order ) {
	$order_id = $order instanceof WC_Order ? $order->get_id() : (int) $order;
	if ( ! $order_id ) {
		return array();
	}

	// Note: query the explicit status list rather than 'any'. The subscription
	// post statuses are registered with exclude_from_search => true, and
	// WP_Query's 'any' skips statuses flagged that way — so 'any' would never
	// match a subscription here.
	$statuses = array_map(
		function ( $status ) {
			return 'rpsfw-' . $status;
		},
		array_keys( rpsfw_get_subscription_statuses() )
	);

	$ids = get_posts(
		array(
			'post_type'      => RPSFW_Subscriptions_Post_Type::POST_TYPE,
			'post_status'    => $statuses,
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => '_rpsfw_parent_order_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $order_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	);

	$subscriptions = array();
	foreach ( $ids as $id ) {
		$subscription = rpsfw_get_subscription( $id );
		if ( $subscription ) {
			$subscriptions[] = $subscription;
		}
	}
	return $subscriptions;
}

/**
 * Get all subscriptions belonging to a user.
 *
 * @param int $user_id User id.
 * @return RPSFW_Subscription[]
 */
function rpsfw_get_subscriptions_for_user( $user_id ) {
	if ( ! $user_id ) {
		return array();
	}
	return rpsfw_get_subscriptions( array( 'customer_id' => (int) $user_id ) );
}

/**
 * Whether an order is a subscription RENEWAL order created by this module
 * (as opposed to an initial checkout order). Renewal orders never go
 * through checkout, so gateway checkout glue must ignore them.
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function rpsfw_order_is_subscription_renewal( $order ) {
	return $order instanceof WC_Order && (bool) $order->get_meta( '_rpsfw_subscription_renewal' );
}

/**
 * Human-readable billing schedule string.
 *
 * @param int    $interval Billing interval (>= 1).
 * @param string $period   day|week|month|year.
 * @return string e.g. "every month", "every 2 weeks".
 */
function rpsfw_format_subscription_period( $interval, $period ) {
	$interval = max( 1, (int) $interval );

	if ( 1 === $interval ) {
		switch ( $period ) {
			case 'day':
				return __( 'every day', 'restore-paypal-standard-for-woocommerce' );
			case 'week':
				return __( 'every week', 'restore-paypal-standard-for-woocommerce' );
			case 'year':
				return __( 'every year', 'restore-paypal-standard-for-woocommerce' );
			case 'month':
			default:
				return __( 'every month', 'restore-paypal-standard-for-woocommerce' );
		}
	}

	switch ( $period ) {
		case 'day':
			/* translators: %d: number of days */
			return sprintf( __( 'every %d days', 'restore-paypal-standard-for-woocommerce' ), $interval );
		case 'week':
			/* translators: %d: number of weeks */
			return sprintf( __( 'every %d weeks', 'restore-paypal-standard-for-woocommerce' ), $interval );
		case 'year':
			/* translators: %d: number of years */
			return sprintf( __( 'every %d years', 'restore-paypal-standard-for-woocommerce' ), $interval );
		case 'month':
		default:
			/* translators: %d: number of months */
			return sprintf( __( 'every %d months', 'restore-paypal-standard-for-woocommerce' ), $interval );
	}
}

/**
 * Human-readable trial string, e.g. "14-day free trial".
 *
 * @param int    $length Trial length.
 * @param string $period day|week|month|year.
 * @return string Empty when no trial.
 */
function rpsfw_format_subscription_trial( $length, $period ) {
	$length = (int) $length;
	if ( $length < 1 ) {
		return '';
	}

	switch ( $period ) {
		case 'week':
			/* translators: %d: trial length in weeks */
			return sprintf( _n( '%d-week free trial', '%d-week free trial', $length, 'restore-paypal-standard-for-woocommerce' ), $length );
		case 'month':
			/* translators: %d: trial length in months */
			return sprintf( _n( '%d-month free trial', '%d-month free trial', $length, 'restore-paypal-standard-for-woocommerce' ), $length );
		case 'year':
			/* translators: %d: trial length in years */
			return sprintf( _n( '%d-year free trial', '%d-year free trial', $length, 'restore-paypal-standard-for-woocommerce' ), $length );
		case 'day':
		default:
			/* translators: %d: trial length in days */
			return sprintf( _n( '%d-day free trial', '%d-day free trial', $length, 'restore-paypal-standard-for-woocommerce' ), $length );
	}
}

/**
 * Compute the next payment date from "now" plus one billing cycle.
 *
 * Used as a local estimate; webhook-reported dates always take precedence.
 *
 * @param int    $interval Billing interval.
 * @param string $period   day|week|month|year.
 * @param int    $from     Base timestamp (GMT). Default now.
 * @return string GMT MySQL datetime.
 */
function rpsfw_calculate_next_payment_date( $interval, $period, $from = 0 ) {
	$from     = $from ? (int) $from : time();
	$interval = max( 1, (int) $interval );

	switch ( $period ) {
		case 'day':
			$next = strtotime( '+' . $interval . ' days', $from );
			break;
		case 'week':
			$next = strtotime( '+' . $interval . ' weeks', $from );
			break;
		case 'year':
			$next = strtotime( '+' . $interval . ' years', $from );
			break;
		case 'month':
		default:
			$next = strtotime( '+' . $interval . ' months', $from );
			break;
	}

	return gmdate( 'Y-m-d H:i:s', $next );
}
