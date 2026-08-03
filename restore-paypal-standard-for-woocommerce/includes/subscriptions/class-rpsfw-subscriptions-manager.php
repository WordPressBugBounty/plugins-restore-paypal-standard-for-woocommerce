<?php
/**
 * Subscription orchestration.
 *
 * Central place for:
 *  - Creating a subscription record when a checkout completes.
 *  - Recording gateway-reported renewals (creates + pays a WC renewal
 *    order, updates dates/counters, ends finite subscriptions).
 *  - Lifecycle actions initiated in WordPress (admin screen or the
 *    customer's My Account page): cancel / suspend / resume, which are
 *    propagated to the payment processor FIRST and only recorded locally
 *    when the processor accepted the change.
 *
 * Webhook-initiated status changes do NOT go through the lifecycle
 * methods here — the gateway glue updates the record directly (the
 * processor is the source of truth, no propagation needed).
 *
 * @package Restore_PayPal_Standard_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RPSFW_Subscriptions_Manager
 */
class RPSFW_Subscriptions_Manager {

	/**
	 * Create (or return the existing) subscription record for a paid
	 * checkout order.
	 *
	 * @param WC_Order $order        Parent order.
	 * @param array    $gateway_data {
	 *     @type string $gateway_id          rpsfw_paypal_commerce | rpsfw_stripe.
	 *     @type string $gateway_sub_id      Processor subscription id.
	 *     @type string $gateway_customer_id Processor customer id (optional).
	 *     @type string $status              Initial status. Default 'active'.
	 *     @type string $next_payment        GMT MySQL datetime (optional).
	 * }
	 * @return RPSFW_Subscription|WP_Error
	 */
	public static function create_for_order( $order, $gateway_data ) {
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'rpsfw_sub_no_order', __( 'Invalid order.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		$gateway_sub_id = isset( $gateway_data['gateway_sub_id'] ) ? $gateway_data['gateway_sub_id'] : '';

		// Serialize creation per processor subscription id. The browser
		// "finalize" call and the webhook reconciler can both reach this method
		// at the same moment for the same subscription; without a lock both pass
		// the idempotency check and create duplicate records. A DB named lock is
		// atomic across the separate concurrent requests/connections.
		$lock = self::acquire_creation_lock( $gateway_sub_id );

		try {
			// Idempotency: one record per processor subscription id (re-checked
			// inside the lock so the loser of a race returns the winner's record).
			if ( $gateway_sub_id ) {
				$existing = rpsfw_get_subscription_by_gateway_id( $gateway_sub_id );
				if ( $existing ) {
					return $existing;
				}
			}

			return self::insert_subscription_for_order( $order, $gateway_data, $gateway_sub_id );
		} finally {
			self::release_creation_lock( $lock );
		}
	}

	/**
	 * Insert the subscription record (and order cross-links) for a finalized
	 * order. Must only be called from create_for_order() while holding the
	 * per-subscription creation lock.
	 *
	 * @param WC_Order $order          Order.
	 * @param array    $gateway_data   Gateway data.
	 * @param string   $gateway_sub_id Processor subscription id.
	 * @return RPSFW_Subscription|WP_Error
	 */
	private static function insert_subscription_for_order( $order, $gateway_data, $gateway_sub_id ) {
		// Multi-subscription orders pass the per-item signature directly
		// (the order-level snapshot describes the whole cart; each record
		// only covers one subscription product).
		$signature = isset( $gateway_data['signature'] ) && is_array( $gateway_data['signature'] )
			? $gateway_data['signature']
			: RPSFW_Subscriptions_Cart::get_order_signature( $order );
		if ( ! $signature ) {
			// Last resort: derive from the order's items.
			$signature = self::build_signature_from_order( $order );
		}
		if ( ! $signature ) {
			return new WP_Error( 'rpsfw_sub_no_signature', __( 'Could not determine subscription details from the order.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		$status = isset( $gateway_data['status'] ) ? $gateway_data['status'] : 'active';

		$subscription = RPSFW_Subscription::create(
			array(
				'status'              => $status,
				'customer_id'         => $order->get_customer_id(),
				'parent_order_id'     => $order->get_id(),
				'gateway_id'          => isset( $gateway_data['gateway_id'] ) ? $gateway_data['gateway_id'] : $order->get_payment_method(),
				'gateway_sub_id'      => $gateway_sub_id,
				'gateway_customer_id' => isset( $gateway_data['gateway_customer_id'] ) ? $gateway_data['gateway_customer_id'] : '',
				'product_id'          => $signature['product_id'],
				'variation_id'        => $signature['variation_id'],
				'quantity'            => $signature['quantity'],
				'item_name'           => $signature['plan_name'],
				'recurring_amount'    => $signature['recurring_amount'],
				'currency'            => $signature['currency'],
				'period'              => $signature['period'],
				'interval'            => $signature['interval'],
				'length'              => $signature['length'],
				'trial_length'        => $signature['trial_length'],
				'trial_period'        => $signature['trial_period'],
				'signup_fee'          => $signature['signup_fee'],
			)
		);

		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		}

		// Shipping portion of the recurring amount (used to compose
		// renewal orders).
		if ( isset( $signature['recurring_shipping'] ) ) {
			update_post_meta( $subscription->get_id(), '_rpsfw_recurring_shipping', wc_format_decimal( $signature['recurring_shipping'] ) );
		}

		// The initial payment counts toward finite lengths (unless a free
		// trial deferred it).
		if ( $signature['trial_length'] < 1 ) {
			$subscription->increment_payment_count();
		}

		// Next payment: processor-reported when available, otherwise an
		// estimate (trial end, or one billing cycle from now).
		if ( ! empty( $gateway_data['next_payment'] ) ) {
			$subscription->set_next_payment_date( $gateway_data['next_payment'] );
		} elseif ( $signature['trial_length'] > 0 ) {
			$subscription->set_next_payment_date(
				rpsfw_calculate_next_payment_date( $signature['trial_length'], $signature['trial_period'] )
			);
		} else {
			$subscription->set_next_payment_date(
				rpsfw_calculate_next_payment_date( $signature['interval'], $signature['period'] )
			);
		}

		// Cross-link on the order (append — an order can back several
		// subscriptions when the cart held multiple subscription products).
		$linked   = (array) $order->get_meta( '_rpsfw_subscription_ids' );
		$linked[] = $subscription->get_id();
		$order->update_meta_data( '_rpsfw_subscription_ids', array_values( array_unique( array_map( 'intval', array_filter( $linked ) ) ) ) );
		$order->save();
		$order->add_order_note(
			sprintf(
				/* translators: 1: subscription id, 2: processor subscription id */
				__( 'Subscription #%1$s created (processor ID: %2$s).', 'restore-paypal-standard-for-woocommerce' ),
				$subscription->get_id(),
				$gateway_sub_id
			)
		);

		$subscription->add_note(
			sprintf(
				/* translators: 1: order number, 2: processor subscription id */
				__( 'Subscription created from order #%1$s. Processor subscription ID: %2$s. Billing is managed by the payment processor.', 'restore-paypal-standard-for-woocommerce' ),
				$order->get_order_number(),
				$gateway_sub_id
			)
		);

		do_action( 'rpsfw_subscription_activated_for_order', $subscription, $order );

		return $subscription;
	}

	/**
	 * Acquire a DB named lock that serializes subscription-record creation for a
	 * given processor subscription id, preventing the browser finalize call and
	 * the webhook reconciler from both creating a record at once.
	 *
	 * Returns the lock name to release later, or '' when no lock was taken
	 * (empty id, or the DB did not grant it within the timeout — in which case
	 * the idempotency re-check still guards against most duplicates).
	 *
	 * @param string $gateway_sub_id Processor subscription id.
	 * @return string Lock name held (or '' if none).
	 */
	private static function acquire_creation_lock( $gateway_sub_id ) {
		if ( empty( $gateway_sub_id ) ) {
			return '';
		}
		global $wpdb;
		// MySQL named locks must be <= 64 chars; hash to a fixed-length key.
		$lock = 'rpsfw_sub_' . md5( $gateway_sub_id );
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, 10 ) );
		return ( '1' === (string) $got ) ? $lock : '';
	}

	/**
	 * Release a lock previously taken by acquire_creation_lock().
	 *
	 * @param string $lock Lock name (or '' for none).
	 */
	private static function release_creation_lock( $lock ) {
		if ( empty( $lock ) ) {
			return;
		}
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}

	/**
	 * Fallback signature builder from an order's line items (used if the
	 * checkout snapshot is missing, e.g. express cart flows).
	 *
	 * @param WC_Order $order Order.
	 * @return array|false
	 */
	public static function build_signature_from_order( $order ) {
		$items                     = array();
		$shippable_recurring_total = 0.0;

		foreach ( $order->get_items() as $order_item ) {
			if ( ! is_callable( array( $order_item, 'get_product' ) ) ) {
				continue;
			}
			$product = $order_item->get_product();
			if ( ! $product || ! RPSFW_Subscription_Product::is_subscription( $product ) ) {
				continue;
			}

			$qty        = max( 1, (int) $order_item->get_quantity() );
			$unit_price = (float) $product->get_price();
			$line       = function_exists( 'wc_get_price_including_tax' )
				? (float) wc_get_price_including_tax( $product, array( 'qty' => $qty, 'price' => $unit_price ) )
				: $unit_price * $qty;
			$line       = round( $line, 2 );

			$trial_length = RPSFW_Subscription_Product::get_trial_length( $product );
			$trial_period = RPSFW_Subscription_Product::get_trial_period( $product );

			$item = array(
				'product_id'     => $order_item->get_product_id(),
				'variation_id'   => $order_item->get_variation_id(),
				'quantity'       => $qty,
				'plan_name'      => wp_strip_all_tags( $order_item->get_name() ),
				'recurring_line' => $line,
				'needs_shipping' => (bool) $product->needs_shipping(),
				'signup_fee'     => round( RPSFW_Subscription_Product::get_signup_fee( $product ) * $qty, 2 ),
				'period'         => RPSFW_Subscription_Product::get_period( $product ),
				'interval'       => RPSFW_Subscription_Product::get_interval( $product ),
				'length'         => RPSFW_Subscription_Product::get_length( $product ),
				'trial_length'   => $trial_length,
				'trial_period'   => $trial_period,
				'trial_days'     => RPSFW_Subscriptions_Cart::trial_to_days( $trial_length, $trial_period ),
			);

			if ( $item['needs_shipping'] ) {
				$shippable_recurring_total += $line;
			}
			$items[] = $item;
		}

		if ( empty( $items ) ) {
			return false;
		}

		// Split the order's shipping (incl. tax) across shippable
		// subscription items, remainder on the last one (same rule the
		// cart signature uses).
		$order_shipping     = round( (float) $order->get_shipping_total() + (float) $order->get_shipping_tax(), 2 );
		$allocated          = 0.0;
		$last_shippable_key = null;
		foreach ( $items as $key => $item ) {
			if ( $item['needs_shipping'] ) {
				$last_shippable_key = $key;
			}
		}
		foreach ( $items as $key => $item ) {
			$share = 0.0;
			if ( $item['needs_shipping'] && $order_shipping > 0 ) {
				if ( $key === $last_shippable_key ) {
					$share = round( $order_shipping - $allocated, 2 );
				} elseif ( $shippable_recurring_total > 0 ) {
					$share      = round( $order_shipping * ( $item['recurring_line'] / $shippable_recurring_total ), 2 );
					$allocated += $share;
				}
			}
			$items[ $key ]['recurring_shipping'] = max( 0.0, $share );
			$items[ $key ]['recurring_amount']   = round( $item['recurring_line'] + $items[ $key ]['recurring_shipping'], 2 );
		}

		$expected_sub_initial = 0.0;
		foreach ( $items as $item ) {
			if ( $item['trial_length'] < 1 ) {
				$expected_sub_initial += $item['recurring_amount'];
			}
			$expected_sub_initial += $item['signup_fee'];
		}

		$first = $items[0];

		return array(
			'product_id'              => $first['product_id'],
			'variation_id'            => $first['variation_id'],
			'quantity'                => $first['quantity'],
			'plan_name'               => $first['plan_name'],
			'currency'                => $order->get_currency(),
			'recurring_amount'        => $first['recurring_amount'],
			'recurring_shipping'      => $first['recurring_shipping'],
			'initial_total'           => (float) $order->get_total(),
			'non_subscription_amount' => 0.0,
			'signup_fee'              => $first['signup_fee'],
			'period'                  => $first['period'],
			'interval'                => $first['interval'],
			'length'                  => $first['length'],
			'trial_length'            => $first['trial_length'],
			'trial_period'            => $first['trial_period'],
			'trial_days'              => $first['trial_days'],
			'sub_count'               => count( $items ),
			'expected_sub_initial'    => round( $expected_sub_initial, 2 ),
			'items'                   => $items,
		);
	}

	// -----------------------------------------------------------------
	// Renewals (webhook-driven).
	// -----------------------------------------------------------------

	/**
	 * Record a successful renewal payment reported by the processor.
	 *
	 * Creates a paid WC renewal order, updates counters/dates, and ends
	 * finite subscriptions that have collected all their payments.
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 * @param float              $amount       Amount charged.
	 * @param string             $txn_id       Processor transaction id.
	 * @param string             $next_payment Optional processor-reported next payment (GMT MySQL).
	 * @return WC_Order|WP_Error|null Order on success, null when skipped (duplicate).
	 */
	public static function record_renewal( $subscription, $amount, $txn_id, $next_payment = '' ) {
		// Idempotency: skip when this transaction was already recorded.
		if ( $txn_id ) {
			if ( $subscription->get_last_transaction_id() === $txn_id ) {
				return null;
			}
			$existing = wc_get_orders(
				array(
					'limit'          => 1,
					'transaction_id' => $txn_id,
				)
			);
			if ( ! empty( $existing ) ) {
				return null;
			}
		}

		$order = self::create_renewal_order( $subscription, $amount );
		if ( is_wp_error( $order ) ) {
			$subscription->add_note(
				sprintf(
					/* translators: 1: transaction id, 2: error message */
					__( 'A renewal payment was received (transaction %1$s) but the renewal order could not be created: %2$s', 'restore-paypal-standard-for-woocommerce' ),
					$txn_id,
					$order->get_error_message()
				)
			);
			return $order;
		}

		$order->payment_complete( $txn_id );
		$order->add_order_note(
			sprintf(
				/* translators: 1: transaction id, 2: subscription id */
				__( 'Subscription renewal payment received. Transaction: %1$s. Subscription: #%2$s.', 'restore-paypal-standard-for-woocommerce' ),
				$txn_id,
				$subscription->get_id()
			)
		);

		$subscription->add_renewal_order_id( $order->get_id() );
		$subscription->increment_payment_count();
		$subscription->set_last_transaction_id( $txn_id );
		$subscription->set_next_payment_date(
			$next_payment ? $next_payment : rpsfw_calculate_next_payment_date( $subscription->get_interval(), $subscription->get_period() )
		);
		$subscription->add_note(
			sprintf(
				/* translators: 1: formatted amount, 2: order number */
				__( 'Renewal payment received: %1$s (order #%2$s).', 'restore-paypal-standard-for-woocommerce' ),
				wp_strip_all_tags( wc_price( $amount, array( 'currency' => $subscription->get_currency() ) ) ),
				$order->get_order_number()
			)
		);

		// A renewal means the processor considers the subscription active.
		if ( $subscription->has_status( array( 'pending', 'on-hold' ) ) ) {
			$subscription->update_status( 'active', __( 'Renewal payment received.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		do_action( 'rpsfw_subscription_renewal_recorded', $subscription, $order, $amount, $txn_id );

		// Finite subscriptions: end once all payments are collected.
		self::maybe_complete( $subscription );

		return $order;
	}

	/**
	 * Create a WC order representing one renewal cycle.
	 *
	 * The subscription product is added at the recurring price (minus the
	 * shipping portion, which becomes a shipping line). Amounts are
	 * gateway-charged totals and already include tax, so no tax lines are
	 * added; if the composed total still differs from the charged amount,
	 * the order total is corrected to match the actual charge.
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 * @param float              $amount       Amount actually charged.
	 * @return WC_Order|WP_Error
	 */
	public static function create_renewal_order( $subscription, $amount ) {
		$parent = $subscription->get_parent_order();

		$order = wc_create_order(
			array(
				'customer_id' => $subscription->get_customer_id(),
				'created_via' => 'rpsfw_subscription',
				'status'      => 'pending',
			)
		);
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		// Addresses from the parent order.
		if ( $parent ) {
			$order->set_address( $parent->get_address( 'billing' ), 'billing' );
			$order->set_address( $parent->get_address( 'shipping' ), 'shipping' );
		}

		$shipping_portion = (float) get_post_meta( $subscription->get_id(), '_rpsfw_recurring_shipping', true );
		$shipping_portion = max( 0.0, min( $shipping_portion, (float) $amount ) );
		$product_portion  = max( 0.0, (float) $amount - $shipping_portion );

		$product = $subscription->get_product();
		if ( $product ) {
			$item = new WC_Order_Item_Product();
			$item->set_product( $product );
			$item->set_quantity( $subscription->get_quantity() );
			$item->set_subtotal( $product_portion );
			$item->set_total( $product_portion );
			$item->set_name( $subscription->get_item_name() ? $subscription->get_item_name() : $product->get_name() );
			$order->add_item( $item );
		} else {
			// Product deleted since purchase — bill a plain line.
			$item = new WC_Order_Item_Product();
			$item->set_name( $subscription->get_item_name() ? $subscription->get_item_name() : __( 'Subscription renewal', 'restore-paypal-standard-for-woocommerce' ) );
			$item->set_quantity( $subscription->get_quantity() );
			$item->set_subtotal( $product_portion );
			$item->set_total( $product_portion );
			$order->add_item( $item );
		}

		if ( $shipping_portion > 0 ) {
			$shipping_item = new WC_Order_Item_Shipping();
			$shipping_item->set_method_title( __( 'Shipping', 'restore-paypal-standard-for-woocommerce' ) );
			$shipping_item->set_total( $shipping_portion );
			$order->add_item( $shipping_item );
		}

		$order->set_currency( $subscription->get_currency() );

		$gateway_id = $subscription->get_gateway_id();
		$gateways   = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		if ( isset( $gateways[ $gateway_id ] ) ) {
			$order->set_payment_method( $gateways[ $gateway_id ] );
		} else {
			$order->set_payment_method( $gateway_id );
			$order->set_payment_method_title( $subscription->get_gateway_title() );
		}

		$order->update_meta_data( '_rpsfw_subscription_renewal', $subscription->get_id() );

		// A renewal belongs to the same account as the original payment, not to
		// whichever mode the store happens to be in when the renewal lands.
		rpsfw_set_order_payment_mode(
			$order,
			$parent ? rpsfw_get_order_payment_mode( $parent ) : rpsfw_get_gateway_mode( $subscription->get_gateway_id() ),
			false
		);
		$order->calculate_totals( false );

		// Trust the processor-charged amount over our composition.
		if ( abs( (float) $order->get_total() - (float) $amount ) >= 0.01 ) {
			$order->set_total( (float) $amount );
			$order->add_order_note(
				__( 'Order total set to the amount charged by the payment processor (includes any tax and shipping).', 'restore-paypal-standard-for-woocommerce' )
			);
		}

		$order->save();

		return $order;
	}

	/**
	 * End a finite subscription that has collected all its payments.
	 *
	 * PayPal plans carry total_cycles, so PayPal stops billing on its own
	 * (and sends BILLING.SUBSCRIPTION.EXPIRED). Stripe has no native
	 * payment-count limit, so we cancel the Stripe subscription remotely
	 * when the count is reached.
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 */
	public static function maybe_complete( $subscription ) {
		$length = $subscription->get_length();
		if ( $length < 1 || $subscription->get_payment_count() < $length ) {
			return;
		}
		if ( ! $subscription->has_status( array( 'active', 'on-hold', 'pending-cancel' ) ) ) {
			return;
		}

		if ( 'rpsfw_stripe' === $subscription->get_gateway_id() ) {
			// When cancel_at was stamped on the Stripe subscription at
			// checkout, Stripe ends it on its own at the close of the final
			// paid period ("Cancels on ..." in the Stripe dashboard). Keep
			// the record active until the deletion webhook arrives, which
			// marks it expired.
			if ( get_post_meta( $subscription->get_id(), '_rpsfw_stripe_cancel_at', true ) ) {
				$subscription->set_next_payment_date( '' );
				$subscription->add_note( __( 'All scheduled payments have been collected. Stripe will end the subscription automatically at the close of the final paid period.', 'restore-paypal-standard-for-woocommerce' ) );
				return;
			}

			// No cancel_at (older records): end the Stripe subscription now.
			$result = RPSFW_Subscriptions_Stripe::remote_cancel( $subscription );
			if ( is_wp_error( $result ) ) {
				$subscription->add_note(
					sprintf(
						/* translators: %s: error message */
						__( 'All payments collected but the Stripe subscription could not be ended automatically: %s. Please cancel it from your Stripe dashboard.', 'restore-paypal-standard-for-woocommerce' ),
						$result->get_error_message()
					)
				);
				return;
			}
		}

		$subscription->update_status( 'expired', __( 'All scheduled payments have been collected.', 'restore-paypal-standard-for-woocommerce' ) );
	}

	// -----------------------------------------------------------------
	// Lifecycle actions (WordPress-initiated).
	// -----------------------------------------------------------------

	/**
	 * Cancel a subscription (admin or customer initiated). Propagates to
	 * the processor first.
	 *
	 * Stripe customer-initiated cancellations end at the paid period's end
	 * (pending-cancel); admin cancellations and PayPal cancellations are
	 * immediate (PayPal has no cancel-at-period-end).
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 * @param string             $initiator    'customer'|'admin'.
	 * @return true|WP_Error
	 */
	public static function cancel( $subscription, $initiator = 'admin' ) {
		if ( ! $subscription->can_be_cancelled() ) {
			return new WP_Error( 'rpsfw_sub_invalid_state', __( 'This subscription cannot be cancelled.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		$gateway_id    = $subscription->get_gateway_id();
		$at_period_end = ( 'customer' === $initiator && 'rpsfw_stripe' === $gateway_id && $subscription->has_status( 'active' ) );

		if ( 'rpsfw_paypal_commerce' === $gateway_id ) {
			$result = RPSFW_Subscriptions_PayPal_Commerce::remote_cancel( $subscription, self::initiator_note( $initiator ) );
		} elseif ( 'rpsfw_stripe' === $gateway_id ) {
			$result = RPSFW_Subscriptions_Stripe::remote_cancel( $subscription, $at_period_end );
		} else {
			$result = new WP_Error( 'rpsfw_sub_unknown_gateway', __( 'Unknown payment gateway for this subscription.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $at_period_end ) {
			$subscription->update_status(
				'pending-cancel',
				'customer' === $initiator
					? __( 'Cancelled by the customer. The subscription remains active until the end of the paid period.', 'restore-paypal-standard-for-woocommerce' )
					: __( 'Cancellation scheduled for the end of the paid period.', 'restore-paypal-standard-for-woocommerce' )
			);
		} else {
			$subscription->update_status( 'cancelled', self::initiator_note( $initiator ) );
		}

		return true;
	}

	/**
	 * Suspend (pause) a subscription. Propagates to the processor first.
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 * @param string             $initiator    'customer'|'admin'.
	 * @return true|WP_Error
	 */
	public static function suspend( $subscription, $initiator = 'admin' ) {
		if ( ! $subscription->can_be_suspended() ) {
			return new WP_Error( 'rpsfw_sub_invalid_state', __( 'This subscription cannot be paused.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		$gateway_id = $subscription->get_gateway_id();
		if ( 'rpsfw_paypal_commerce' === $gateway_id ) {
			$result = RPSFW_Subscriptions_PayPal_Commerce::remote_suspend( $subscription, self::initiator_note( $initiator ) );
		} elseif ( 'rpsfw_stripe' === $gateway_id ) {
			$result = RPSFW_Subscriptions_Stripe::remote_suspend( $subscription );
		} else {
			$result = new WP_Error( 'rpsfw_sub_unknown_gateway', __( 'Unknown payment gateway for this subscription.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$subscription->update_status( 'on-hold', self::initiator_note( $initiator ) );
		return true;
	}

	/**
	 * Resume a paused subscription. Propagates to the processor first.
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 * @param string             $initiator    'customer'|'admin'.
	 * @return true|WP_Error
	 */
	public static function resume( $subscription, $initiator = 'admin' ) {
		if ( ! $subscription->can_be_resumed() ) {
			return new WP_Error( 'rpsfw_sub_invalid_state', __( 'This subscription cannot be resumed.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		$gateway_id = $subscription->get_gateway_id();
		if ( 'rpsfw_paypal_commerce' === $gateway_id ) {
			$result = RPSFW_Subscriptions_PayPal_Commerce::remote_resume( $subscription, self::initiator_note( $initiator ) );
		} elseif ( 'rpsfw_stripe' === $gateway_id ) {
			$result = RPSFW_Subscriptions_Stripe::remote_resume( $subscription );
		} else {
			$result = new WP_Error( 'rpsfw_sub_unknown_gateway', __( 'Unknown payment gateway for this subscription.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$subscription->update_status( 'active', self::initiator_note( $initiator ) );
		return true;
	}

	/**
	 * Standard note text for an initiator.
	 *
	 * @param string $initiator 'customer'|'admin'.
	 * @return string
	 */
	private static function initiator_note( $initiator ) {
		return 'customer' === $initiator
			? __( 'Requested by the customer from their account page.', 'restore-paypal-standard-for-woocommerce' )
			: __( 'Requested by the store admin.', 'restore-paypal-standard-for-woocommerce' );
	}
}
