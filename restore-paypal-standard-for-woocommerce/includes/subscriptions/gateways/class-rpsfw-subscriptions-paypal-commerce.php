<?php
/**
 * PayPal Commerce glue for native subscriptions.
 *
 * Mirrors the flow of the WooCommerce Subscriptions integration
 * (class-paypal-commerce-subscriptions.php) but reads schedules from this
 * module's own product meta and stores state in rpsfw_subscription records.
 * PayPal owns the schedule: we create a Catalog Product + Billing Plan +
 * Subscription up-front, the customer approves once, and renewals flow
 * back through PAYMENT.SALE.COMPLETED / BILLING.SUBSCRIPTION.* webhooks.
 *
 * The checkout surfaces register the SAME AJAX action names and consume
 * the SAME hook points as the WCS integration, so the existing checkout
 * JS (classic, blocks and the cart express button) works unchanged. The
 * checkout surfaces are only registered in native mode (WCS absent), so
 * there is never a double registration.
 *
 * Order meta uses the same keys as the WCS integration
 * (_rpsfw_ppcp_subscription_id etc.) so existing admin surfaces —
 * gateway deep links, refund handling — work for native records too.
 *
 * @package Restore_PayPal_Standard_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RPSFW_Subscriptions_PayPal_Commerce
 */
class RPSFW_Subscriptions_PayPal_Commerce {

	const META_PRODUCT_ID      = '_rpsfw_ppcp_product_id';
	const META_PLAN_ID         = '_rpsfw_ppcp_plan_id';
	const META_SUBSCRIPTION_ID = '_rpsfw_ppcp_subscription_id';
	const META_LAST_PAYMENT_ID = '_rpsfw_ppcp_last_payment_id';
	const PLAN_CACHE_OPTION    = 'rpsfw_native_ppcp_plan_cache';

	/**
	 * Register checkout surfaces. Native mode only (loader-gated).
	 */
	public static function init_checkout() {
		// Same AJAX action names the checkout JS already calls.
		add_action( 'wp_ajax_rpsfw_ppcp_create_subscription', array( __CLASS__, 'ajax_create_subscription' ) );
		add_action( 'wp_ajax_nopriv_rpsfw_ppcp_create_subscription', array( __CLASS__, 'ajax_create_subscription' ) );

		// Finalize checkout for native subscription orders.
		add_filter( 'rpsfw_ppcp_process_payment_override', array( __CLASS__, 'maybe_override_process_payment' ), 10, 2 );

		// Flip the PayPal JS SDK into subscription mode for native carts.
		add_filter( 'rpsfw_ppcp_sdk_args', array( __CLASS__, 'filter_sdk_args' ) );
	}

	/**
	 * Register webhook + record servicing. Always registered while the
	 * module is enabled or records exist; handlers self-scope to native
	 * records so they coexist with the WCS integration.
	 */
	public static function init_webhooks() {
		add_action( 'rpsfw_ppcp_webhook_event', array( __CLASS__, 'handle_webhook_event' ), 20, 2 );
	}

	// -----------------------------------------------------------------
	// Gateway access helpers.
	// -----------------------------------------------------------------

	/**
	 * Get the PayPal Commerce gateway instance.
	 *
	 * @return WC_Gateway_PayPal_Commerce|false
	 */
	private static function get_gateway() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return false;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways['rpsfw_paypal_commerce'] ) ? $gateways['rpsfw_paypal_commerce'] : false;
	}

	/**
	 * Get env + onboarding for the gateway.
	 *
	 * @param WC_Gateway_PayPal_Commerce $gateway Gateway.
	 * @return array{0:string,1:array} [ env, onboarding ]
	 */
	private static function get_env_onboard( $gateway ) {
		// While handling a webhook, use the account the event came from rather
		// than whichever mode the store is set to — a sandbox event for a test
		// order must be looked up against the sandbox account even on a live
		// site. Outside a webhook this is the gateway's current mode.
		$mode       = rpsfw_current_webhook_mode();
		$env        = $mode ? rpsfw_payment_mode_to_ppcp_env( $mode ) : ( $gateway->testmode ? 'sandbox' : 'live' );
		$onboarding = $gateway->get_option( 'ppcp_onboarding', array() );
		return array( $env, isset( $onboarding[ $env ] ) ? $onboarding[ $env ] : array() );
	}

	/**
	 * Log through the gateway logger.
	 *
	 * @param string $message Message.
	 * @param string $level   Level.
	 */
	private static function log( $message, $level = 'info' ) {
		if ( class_exists( 'WC_Gateway_PayPal_Commerce' ) ) {
			WC_Gateway_PayPal_Commerce::log( 'Native subscriptions: ' . $message, $level );
		}
	}

	// -----------------------------------------------------------------
	// SDK.
	// -----------------------------------------------------------------

	/**
	 * PayPal requires intent=subscription + vault=true for subscription
	 * buttons, and rejects funding lists in that mode.
	 *
	 * @param array $args SDK args.
	 * @return array
	 */
	public static function filter_sdk_args( $args ) {
		if ( ! RPSFW_Subscriptions_Cart::cart_contains_subscription() ) {
			return $args;
		}
		$args['intent'] = 'subscription';
		$args['vault']  = 'true';
		unset( $args['disable-funding'], $args['enable-funding'] );
		return $args;
	}

	// -----------------------------------------------------------------
	// Checkout: create the PayPal subscription.
	// -----------------------------------------------------------------

	/**
	 * AJAX: create a PayPal subscription for the current native cart and
	 * return its id + approval URL. Response shape matches what the
	 * checkout JS expects from the WCS integration's endpoint.
	 */
	public static function ajax_create_subscription() {
		check_ajax_referer( 'rpsfw-ppcp-create-subscription', 'nonce' );

		try {
			if ( ! WC()->cart || WC()->cart->is_empty() ) {
				throw new Exception( __( 'Your cart is empty.', 'restore-paypal-standard-for-woocommerce' ) );
			}
			if ( ! RPSFW_Subscriptions_Cart::cart_contains_subscription() ) {
				throw new Exception( __( 'No subscription in cart.', 'restore-paypal-standard-for-woocommerce' ) );
			}
			// PayPal's Subscriptions API bills one plan per approval, so a
			// cart with several subscriptions cannot be represented. The
			// gateway hides itself for these carts; this is the backstop.
			if ( RPSFW_Subscriptions_Cart::cart_contains_multiple_subscriptions() ) {
				throw new Exception( __( 'PayPal supports one subscription per order. Please pay with Stripe, or purchase the subscriptions separately.', 'restore-paypal-standard-for-woocommerce' ) );
			}

			// Existing-account-email guard. This endpoint fires on the PayPal
			// BUTTON CLICK — the only pre-payment point for PayPal (approval
			// happens before any WC order exists) — so this is where a guest
			// using an already-registered email is stopped and asked to log in.
			// It is the SINGLE source for PayPal (the WC-validation hooks in the
			// cart class skip PayPal to avoid a duplicate).
			$email_conflict = RPSFW_Subscriptions_Cart::guest_subscription_email_conflict();
			if ( $email_conflict ) {
				throw new Exception( $email_conflict );
			}

			$gateway = self::get_gateway();
			if ( ! $gateway ) {
				throw new Exception( __( 'PayPal Commerce gateway is unavailable.', 'restore-paypal-standard-for-woocommerce' ) );
			}

			// Checkout-page flow: validate the WooCommerce checkout fields
			// before creating the PayPal subscription.
			if ( ! empty( $_POST['rpsfw_validate_fields'] ) ) {
				if ( ! class_exists( 'RPSFW_Checkout_Validator' ) ) {
					require_once RPSFW_PLUGIN_DIR . 'includes/class-rpsfw-checkout-validator.php';
				}
				$checkout_errors = RPSFW_Checkout_Validator::validate();
				if ( $checkout_errors->has_errors() ) {
					throw new Exception( implode( ' ', $checkout_errors->get_error_messages() ) );
				}
			}

			// Sync posted billing fields onto WC()->customer so the PayPal
			// subscriber payload is complete on the classic checkout.
			self::update_customer_from_posted();

			$signature = RPSFW_Subscriptions_Cart::build_cart_signature();
			if ( ! $signature ) {
				throw new Exception( __( 'Could not derive subscription details from the cart.', 'restore-paypal-standard-for-woocommerce' ) );
			}

			$plan = self::ensure_plan( $signature );
			if ( ! $plan ) {
				throw new Exception( __( 'Could not create or retrieve the subscription plan.', 'restore-paypal-standard-for-woocommerce' ) );
			}

			list( $env, $onboard ) = self::get_env_onboard( $gateway );

			$subscriber      = self::build_subscriber();
			$subscriber_hash = md5( (string) wp_json_encode( $subscriber ) );

			// Reuse a still-pending PayPal subscription from this session so
			// repeated button clicks don't create duplicates — unless the
			// plan or the subscriber (shipping address) changed.
			$existing_id   = WC()->session ? WC()->session->get( 'rpsfw_ppcp_pending_subscription_id' ) : '';
			$existing_plan = WC()->session ? WC()->session->get( 'rpsfw_ppcp_pending_plan_id' ) : '';
			$existing_hash = WC()->session ? WC()->session->get( 'rpsfw_ppcp_pending_subscriber_hash' ) : '';
			if ( $existing_id && $existing_plan === $plan['plan_id'] && $existing_hash === $subscriber_hash ) {
				$details = $gateway->api->get_subscription( $env, $onboard, $existing_id );
				if ( ! empty( $details['status'] ) && in_array( $details['status'], array( 'APPROVAL_PENDING', 'APPROVED' ), true ) ) {
					wp_send_json_success( array( 'subscription_id' => $existing_id ) );
				}
			}

			$create_args = array(
				'plan_id'    => $plan['plan_id'],
				'return_url' => add_query_arg( array( 'rpsfw_ppcp_sub_return' => 1 ), wc_get_checkout_url() ),
				'cancel_url' => wc_get_cart_url(),
				'subscriber' => $subscriber,
			);

			$response = $gateway->api->create_subscription( $env, $onboard, $create_args );

			// Self-heal stale cached plans (plan created on a previously
			// connected PayPal account no longer exists).
			if ( is_array( $response ) && ! empty( $response['not_found'] ) ) {
				self::log( 'Cached plan no longer exists on PayPal; recreating and retrying.', 'warning' );
				$plan = self::ensure_plan( $signature, true );
				if ( ! $plan ) {
					throw new Exception( __( 'Could not create or retrieve the subscription plan.', 'restore-paypal-standard-for-woocommerce' ) );
				}
				$create_args['plan_id'] = $plan['plan_id'];
				$response               = $gateway->api->create_subscription( $env, $onboard, $create_args );
			}

			if ( empty( $response['approve_url'] ) || empty( $response['subscription_id'] ) ) {
				throw new Exception( __( 'PayPal did not return an approval URL.', 'restore-paypal-standard-for-woocommerce' ) );
			}

			WC()->session->set( 'rpsfw_ppcp_pending_subscription_id', $response['subscription_id'] );
			WC()->session->set( 'rpsfw_ppcp_pending_plan_id', $plan['plan_id'] );
			WC()->session->set( 'rpsfw_ppcp_pending_product_id', $plan['product_id'] );
			WC()->session->set( 'rpsfw_ppcp_pending_subscriber_hash', $subscriber_hash );

			wp_send_json_success(
				array(
					'subscription_id' => $response['subscription_id'],
					'approve_url'     => $response['approve_url'],
				)
			);
		} catch ( Exception $e ) {
			self::log( 'ajax_create_subscription error: ' . $e->getMessage(), 'error' );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Find or create the PayPal Billing Plan for a cart signature.
	 *
	 * Plan mapping (same canonical mapping as the WCS integration):
	 *  - Free trial: TRIAL cycle 1× $0 for trial_length × trial_period;
	 *    payment_preferences.setup_fee = initial_total (charged today).
	 *  - No trial, first payment differs from recurring (sign-up fee or
	 *    initial-only coupon): one paid intro cycle at initial_total, then
	 *    REGULAR at the recurring amount; finite lengths drop one regular
	 *    cycle to keep the payment count exact.
	 *  - Otherwise: plain REGULAR plan.
	 *
	 * @param array $signature     Cart signature.
	 * @param bool  $force_refresh Recreate even if cached.
	 * @return array{plan_id:string,product_id:string}|false
	 */
	private static function ensure_plan( $signature, $force_refresh = false ) {
		$gateway = self::get_gateway();
		if ( ! $gateway ) {
			return false;
		}
		list( $env, $onboard ) = self::get_env_onboard( $gateway );
		$seller_id             = isset( $onboard['seller_id'] ) ? $onboard['seller_id'] : '';

		$interval_unit  = self::period_to_paypal_unit( $signature['period'] );
		$interval_count = max( 1, (int) $signature['interval'] );
		$total_cycles   = max( 0, (int) $signature['length'] );
		$amount         = number_format( (float) $signature['recurring_amount'], 2, '.', '' );
		$initial_total  = (float) $signature['initial_total'];

		$setup_fee    = '0';
		$trial_amount = '0';
		$trial_unit   = 'DAY';
		$trial_count  = 0;
		$trial_cycles = 0;

		if ( $signature['trial_length'] > 0 ) {
			// Free trial: $0 trial cycle; whatever is due today (sign-up
			// fee, one-time items, shipping) is charged as the setup fee.
			$trial_unit   = self::period_to_paypal_unit( $signature['trial_period'] );
			$trial_count  = (int) $signature['trial_length'];
			$trial_cycles = 1;
			$setup_fee    = number_format( max( 0, $initial_total ), 2, '.', '' );
		} elseif ( abs( $initial_total - (float) $signature['recurring_amount'] ) >= 0.01 ) {
			// Paid intro cycle priced at today's total.
			$trial_unit   = $interval_unit;
			$trial_count  = $interval_count;
			$trial_cycles = 1;
			$trial_amount = number_format( max( 0, $initial_total ), 2, '.', '' );
			if ( $total_cycles > 0 ) {
				$total_cycles = max( 1, $total_cycles - 1 );
			}
		}

		$cache_key = md5(
			implode(
				'|',
				array(
					'native',
					$signature['currency'],
					$amount,
					$setup_fee,
					$interval_unit,
					$interval_count,
					$total_cycles,
					$trial_amount,
					$trial_unit,
					$trial_count,
					$trial_cycles,
					$signature['plan_name'],
					$env,
					$seller_id,
				)
			)
		);

		$cache = get_option( self::PLAN_CACHE_OPTION, array() );
		if ( ! $force_refresh && isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}
		if ( $force_refresh && isset( $cache[ $cache_key ] ) ) {
			unset( $cache[ $cache_key ] );
			update_option( self::PLAN_CACHE_OPTION, $cache, false );
		}

		$product = $gateway->api->create_subscription_product(
			$env,
			$onboard,
			array(
				'name'        => $signature['plan_name'],
				'description' => __( 'Subscription product', 'restore-paypal-standard-for-woocommerce' ),
				'type'        => 'SERVICE',
			)
		);
		if ( empty( $product['product_id'] ) ) {
			return false;
		}

		$plan = $gateway->api->create_subscription_plan(
			$env,
			$onboard,
			array(
				'product_id'     => $product['product_id'],
				'name'           => $signature['plan_name'],
				'description'    => $signature['plan_name'],
				'currency_code'  => $signature['currency'],
				'amount'         => $amount,
				'setup_fee'      => $setup_fee,
				'interval_unit'  => $interval_unit,
				'interval_count' => $interval_count,
				'total_cycles'   => $total_cycles,
				'trial_amount'   => $trial_amount,
				'trial_unit'     => $trial_unit,
				'trial_count'    => $trial_count,
				'trial_cycles'   => $trial_cycles,
			)
		);
		if ( empty( $plan['plan_id'] ) ) {
			return false;
		}

		$cache[ $cache_key ] = array(
			'plan_id'    => $plan['plan_id'],
			'product_id' => $product['product_id'],
			'env'        => $env,
		);
		update_option( self::PLAN_CACHE_OPTION, $cache, false );

		return $cache[ $cache_key ];
	}

	/**
	 * WC period to PayPal interval unit.
	 *
	 * @param string $period day|week|month|year.
	 * @return string
	 */
	private static function period_to_paypal_unit( $period ) {
		switch ( strtolower( (string) $period ) ) {
			case 'day':
				return 'DAY';
			case 'week':
				return 'WEEK';
			case 'year':
				return 'YEAR';
			case 'month':
			default:
				return 'MONTH';
		}
	}

	/**
	 * Build the PayPal subscriber payload (payer + shipping address).
	 *
	 * @return array
	 */
	private static function build_subscriber() {
		$customer = function_exists( 'WC' ) ? WC()->customer : null;
		if ( ! $customer ) {
			return array();
		}

		$out   = array();
		$email = $customer->get_billing_email();
		$first = $customer->get_billing_first_name();
		$last  = $customer->get_billing_last_name();

		if ( $email ) {
			$out['email'] = $email;
		}
		if ( $first || $last ) {
			$out['first_name'] = $first;
			$out['last_name']  = $last;
		}

		// Shipping address via the shared collector (honours "ship to a
		// different address" on the posted checkout fields).
		$shipping = function_exists( 'rpsfw_ppcp_collect_request_shipping_address' )
			? rpsfw_ppcp_collect_request_shipping_address()
			: array();

		if ( empty( $shipping['first_name'] ) && empty( $shipping['last_name'] ) ) {
			if ( $first ) {
				$shipping['first_name'] = $first;
			}
			if ( $last ) {
				$shipping['last_name'] = $last;
			}
		}

		if ( ! empty( $shipping['address_1'] ) && ! empty( $shipping['country'] ) ) {
			$out['shipping_address'] = $shipping;
		}

		return $out;
	}

	/**
	 * Populate WC()->customer billing props from posted checkout fields
	 * (the classic checkout does not sync them during pre-submit AJAX).
	 */
	private static function update_customer_from_posted() {
		$customer = function_exists( 'WC' ) ? WC()->customer : null;
		if ( ! $customer ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce verified by the AJAX caller.
		if ( ! isset( $_POST['billing_email'] ) && ! isset( $_POST['billing_first_name'] ) && ! isset( $_POST['billing_address_1'] ) ) {
			return;
		}

		if ( isset( $_POST['billing_email'] ) ) {
			$customer->set_billing_email( sanitize_email( wp_unslash( $_POST['billing_email'] ) ) );
		}

		$fields = array( 'first_name', 'last_name', 'country', 'state', 'postcode', 'city', 'address_1', 'address_2', 'phone', 'company' );
		$props  = array();
		foreach ( $fields as $field ) {
			$key = 'billing_' . $field;
			if ( isset( $_POST[ $key ] ) ) {
				$props[ 'billing_' . $field ] = wc_clean( wp_unslash( $_POST[ $key ] ) );
			}
		}
		if ( ! empty( $props ) ) {
			$customer->set_props( $props );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	// -----------------------------------------------------------------
	// Checkout: finalize.
	// -----------------------------------------------------------------

	/**
	 * Route process_payment for native subscription orders: verify the
	 * approved PayPal subscription, mark the order paid, create the
	 * subscription record.
	 *
	 * @param array|null $result Prior override result.
	 * @param WC_Order   $order  Order.
	 * @return array|null
	 */
	public static function maybe_override_process_payment( $result, $order ) {
		if ( null !== $result || ! $order instanceof WC_Order ) {
			return $result;
		}
		if ( ! RPSFW_Subscriptions_Cart::order_contains_subscription( $order ) ) {
			return $result;
		}
		// Renewal orders never come through checkout (webhooks pay them);
		// a manual payment of one must use the normal one-off flow.
		if ( rpsfw_order_is_subscription_renewal( $order ) ) {
			return $result;
		}

		$gateway = self::get_gateway();
		if ( ! $gateway ) {
			return $result;
		}

		try {
			$subscription_id = '';
			if ( ! empty( $_POST['rpsfw_ppcp_subscription_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checkout request.
				$subscription_id = sanitize_text_field( wp_unslash( $_POST['rpsfw_ppcp_subscription_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			} elseif ( WC()->session ) {
				$subscription_id = WC()->session->get( 'rpsfw_ppcp_pending_subscription_id' );
			}

			if ( empty( $subscription_id ) ) {
				throw new Exception( __( 'PayPal subscription was not approved. Please click the PayPal button and complete approval.', 'restore-paypal-standard-for-woocommerce' ) );
			}

			self::finalize_for_order( $gateway, $order, $subscription_id );

			return array(
				'result'   => 'success',
				'redirect' => $gateway->get_return_url( $order ),
			);
		} catch ( Exception $e ) {
			self::log( 'process_payment error: ' . $e->getMessage(), 'error' );
			wc_add_notice( $e->getMessage(), 'error' );
			return array( 'result' => 'failure' );
		}
	}

	/**
	 * Verify the PayPal subscription, persist meta, mark the order paid
	 * and create the native subscription record.
	 *
	 * @param WC_Gateway_PayPal_Commerce $gateway         Gateway.
	 * @param WC_Order                   $order           Order.
	 * @param string                     $subscription_id PayPal subscription id.
	 * @throws Exception When the PayPal subscription is missing or unapproved.
	 */
	public static function finalize_for_order( $gateway, $order, $subscription_id ) {
		list( $env, $onboard ) = self::get_env_onboard( $gateway );

		$details = $gateway->api->get_subscription( $env, $onboard, $subscription_id );
		if ( empty( $details ) || empty( $details['status'] ) ) {
			throw new Exception( esc_html__( 'Could not retrieve subscription status from PayPal.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		if ( ! in_array( $details['status'], array( 'ACTIVE', 'APPROVED', 'APPROVAL_PENDING' ), true ) ) {
			throw new Exception(
				esc_html(
					sprintf(
						/* translators: %s: PayPal subscription status */
						__( 'PayPal subscription is not active (status: %s).', 'restore-paypal-standard-for-woocommerce' ),
						$details['status']
					)
				)
			);
		}

		$plan_id    = isset( $details['plan_id'] ) ? $details['plan_id'] : ( WC()->session ? WC()->session->get( 'rpsfw_ppcp_pending_plan_id' ) : '' );
		$product_id = WC()->session ? WC()->session->get( 'rpsfw_ppcp_pending_product_id' ) : '';

		$order->update_meta_data( self::META_SUBSCRIPTION_ID, $subscription_id );
		if ( $plan_id ) {
			$order->update_meta_data( self::META_PLAN_ID, $plan_id );
		}
		if ( $product_id ) {
			$order->update_meta_data( self::META_PRODUCT_ID, $product_id );
		}
		$order->set_transaction_id( $subscription_id );
		$order->save();

		// The get_subscription() call above is a PayPal round trip; a webhook
		// can complete the order while it is in flight. Completing it again from
		// this stale object would re-send the customer "order processing" email.
		if ( rpsfw_order_still_needs_payment( $order ) ) {
			$order->payment_complete( $subscription_id );
			$order->add_order_note(
				sprintf(
					/* translators: %s: PayPal subscription id */
					__( 'PayPal subscription approved (Subscription ID: %s).', 'restore-paypal-standard-for-woocommerce' ),
					$subscription_id
				)
			);
		} else {
			self::log( 'Order #' . $order->get_id() . ' was already completed — skipping duplicate payment_complete for ' . $subscription_id . '.' );
		}

		// Native record. Next payment from PayPal's billing_info when the
		// subscription is already ACTIVE at this point.
		$next_payment = '';
		if ( ! empty( $details['billing_info']['next_billing_time'] ) ) {
			$timestamp = strtotime( $details['billing_info']['next_billing_time'] );
			if ( $timestamp ) {
				$next_payment = gmdate( 'Y-m-d H:i:s', $timestamp );
			}
		}

		$record = RPSFW_Subscriptions_Manager::create_for_order(
			$order,
			array(
				'gateway_id'     => 'rpsfw_paypal_commerce',
				'gateway_sub_id' => $subscription_id,
				'status'         => 'active',
				'next_payment'   => $next_payment,
			)
		);
		if ( is_wp_error( $record ) ) {
			self::log( 'Could not create subscription record for order #' . $order->get_id() . ': ' . $record->get_error_message(), 'error' );
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message */
					__( 'Warning: the subscription record could not be created (%s). The PayPal subscription itself is active.', 'restore-paypal-standard-for-woocommerce' ),
					$record->get_error_message()
				)
			);
		}

		if ( WC()->session ) {
			WC()->session->__unset( 'rpsfw_ppcp_pending_subscription_id' );
			WC()->session->__unset( 'rpsfw_ppcp_pending_plan_id' );
			WC()->session->__unset( 'rpsfw_ppcp_pending_product_id' );
			WC()->session->__unset( 'rpsfw_ppcp_pending_subscriber_hash' );
		}
	}

	/**
	 * Cart express flow (native): create a WC order from the cart, verify
	 * the approved PayPal subscription and finalize. Called from
	 * rpsfw_ppcp_ajax_process_cart_subscription when the WooCommerce
	 * Subscriptions plugin is not active. Sends a JSON response and exits.
	 *
	 * @param string $subscription_id PayPal subscription id.
	 */
	public static function process_cart_subscription( $subscription_id ) {
		$gateway = self::get_gateway();

		try {
			if ( ! $gateway ) {
				throw new Exception( __( 'Gateway not found.', 'restore-paypal-standard-for-woocommerce' ) );
			}
			if ( ! WC()->cart || WC()->cart->is_empty() ) {
				throw new Exception( __( 'Cart is empty.', 'restore-paypal-standard-for-woocommerce' ) );
			}
			if ( ! RPSFW_Subscriptions_Cart::cart_contains_subscription() ) {
				throw new Exception( __( 'No subscription in cart.', 'restore-paypal-standard-for-woocommerce' ) );
			}

			WC()->cart->calculate_totals();
			$signature = RPSFW_Subscriptions_Cart::build_cart_signature();

			$order = wc_create_order();
			if ( is_wp_error( $order ) ) {
				throw new Exception( $order->get_error_message() );
			}

			// The express cart flow builds its own order instead of going
			// through WC_Gateway_PayPal_Commerce::process_payment(), so the
			// mode stamp applied there never runs here. Record it now so
			// refunds, dashboard links and webhook routing resolve this order
			// against the account it was actually paid in, not whichever mode
			// the store happens to be in later.
			rpsfw_set_order_payment_mode( $order, rpsfw_get_gateway_mode( $gateway->id ) );

			if ( is_callable( array( WC()->checkout, 'create_order_line_items' ) ) ) {
				WC()->checkout->create_order_line_items( $order, WC()->cart );
			} else {
				foreach ( WC()->cart->get_cart() as $cart_item ) {
					$order->add_product( $cart_item['data'], $cart_item['quantity'] );
				}
			}
			if ( is_callable( array( WC()->checkout, 'create_order_fee_lines' ) ) ) {
				WC()->checkout->create_order_fee_lines( $order, WC()->cart );
			}
			if ( is_callable( array( WC()->checkout, 'create_order_tax_lines' ) ) ) {
				WC()->checkout->create_order_tax_lines( $order, WC()->cart );
			}
			if ( is_callable( array( WC()->checkout, 'create_order_coupon_lines' ) ) ) {
				WC()->checkout->create_order_coupon_lines( $order, WC()->cart );
			}

			// Buyer details from the PayPal subscription.
			list( $env, $onboard ) = self::get_env_onboard( $gateway );
			$details               = $gateway->api->get_subscription( $env, $onboard, $subscription_id );
			if ( ! empty( $details['subscriber'] ) ) {
				$subscriber = $details['subscriber'];
				if ( ! empty( $subscriber['email_address'] ) ) {
					$order->set_billing_email( $subscriber['email_address'] );
				}
				if ( ! empty( $subscriber['name']['given_name'] ) ) {
					$order->set_billing_first_name( $subscriber['name']['given_name'] );
				}
				if ( ! empty( $subscriber['name']['surname'] ) ) {
					$order->set_billing_last_name( $subscriber['name']['surname'] );
				}
			}

			$order->set_payment_method( 'rpsfw_paypal_commerce' );
			$order->set_payment_method_title( $gateway->get_title() );
			if ( is_user_logged_in() ) {
				$order->set_customer_id( get_current_user_id() );
			}

			$order->set_discount_total( WC()->cart->get_discount_total() );
			$order->set_discount_tax( WC()->cart->get_discount_tax() );
			$order->set_cart_tax( WC()->cart->get_cart_contents_tax() + WC()->cart->get_fee_tax() );
			$order->set_shipping_total( WC()->cart->get_shipping_total() );
			$order->set_shipping_tax( WC()->cart->get_shipping_tax() );
			$order->set_total( WC()->cart->get_total( 'edit' ) );

			// Signature snapshot for record creation.
			if ( $signature ) {
				$order->update_meta_data( RPSFW_Subscriptions_Cart::ORDER_SIGNATURE_META, $signature );
			}

			$order->save();

			self::finalize_for_order( $gateway, $order, $subscription_id );
		} catch ( Exception $e ) {
			self::log( 'Cart subscription finalize failed: ' . $e->getMessage(), 'error' );
			if ( isset( $order ) && $order instanceof WC_Order ) {
				$order->update_status( 'failed', $e->getMessage() );
			}
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}

		WC()->cart->empty_cart();

		wp_send_json_success(
			array(
				'redirect' => $order->get_checkout_order_received_url(),
				'order_id' => $order->get_id(),
			)
		);
	}

	// -----------------------------------------------------------------
	// Webhooks.
	// -----------------------------------------------------------------

	/**
	 * Handle PayPal webhook events for native records. Events whose
	 * subscription id doesn't match a native record are ignored (they may
	 * belong to the WCS integration).
	 *
	 * @param string $event_type Event type.
	 * @param array  $resource   Resource payload.
	 */
	public static function handle_webhook_event( $event_type, $resource ) {
		switch ( $event_type ) {
			case 'PAYMENT.SALE.COMPLETED':
				self::webhook_sale_completed( $resource );
				break;
			case 'BILLING.SUBSCRIPTION.CANCELLED':
				self::webhook_status_change( $resource, 'cancelled', __( 'PayPal reported the subscription as cancelled.', 'restore-paypal-standard-for-woocommerce' ) );
				break;
			case 'BILLING.SUBSCRIPTION.SUSPENDED':
				self::webhook_status_change( $resource, 'on-hold', __( 'PayPal reported the subscription as suspended.', 'restore-paypal-standard-for-woocommerce' ) );
				break;
			case 'BILLING.SUBSCRIPTION.ACTIVATED':
				self::webhook_status_change( $resource, 'active', __( 'PayPal reported the subscription as active.', 'restore-paypal-standard-for-woocommerce' ) );
				break;
			case 'BILLING.SUBSCRIPTION.EXPIRED':
				self::webhook_status_change( $resource, 'expired', __( 'PayPal reported the subscription as expired (all payments collected).', 'restore-paypal-standard-for-woocommerce' ) );
				break;
			case 'BILLING.SUBSCRIPTION.PAYMENT.FAILED':
				self::webhook_payment_failed( $resource );
				break;
		}
	}

	/**
	 * PAYMENT.SALE.COMPLETED: the first sale belongs to the parent order;
	 * later sales create renewal orders.
	 *
	 * @param array $resource Sale resource.
	 */
	private static function webhook_sale_completed( $resource ) {
		$paypal_sub_id = isset( $resource['billing_agreement_id'] ) ? $resource['billing_agreement_id'] : '';
		if ( ! $paypal_sub_id ) {
			return;
		}

		$subscription = rpsfw_get_subscription_by_gateway_id( $paypal_sub_id );
		if ( ! $subscription ) {
			return; // Not one of ours.
		}

		$txn_id = isset( $resource['id'] ) ? $resource['id'] : '';
		$amount = isset( $resource['amount']['total'] ) ? (float) $resource['amount']['total'] : $subscription->get_recurring_amount();

		// First sale: already accounted for by the parent order at
		// checkout. Stash the real transaction id on the parent so admin
		// deep links target the payment rather than the subscription.
		$parent = $subscription->get_parent_order();
		if ( $parent && ! rpsfw_webhook_may_touch_order( $parent ) ) {
			self::log( 'Refusing ' . rpsfw_current_webhook_mode() . ' mode sale for subscription #' . $subscription->get_id() . '; its order was paid in ' . rpsfw_get_order_payment_mode( $parent ) . ' mode.', 'warning' );
			return;
		}
		if ( $parent
			&& $parent->get_transaction_id() === $paypal_sub_id
			&& ! $parent->get_meta( self::META_LAST_PAYMENT_ID ) ) {
			if ( $txn_id ) {
				$parent->update_meta_data( self::META_LAST_PAYMENT_ID, $txn_id );
				$parent->save();
			}
			// Free-trial subscriptions defer the first charge; count it
			// now that it has actually happened.
			if ( 0 === $subscription->get_payment_count() ) {
				$subscription->increment_payment_count();
				$subscription->set_last_transaction_id( $txn_id );
				RPSFW_Subscriptions_Manager::maybe_complete( $subscription );
			}
			return;
		}

		$next_payment = '';
		if ( ! empty( $resource['next_billing_time'] ) ) {
			$timestamp = strtotime( $resource['next_billing_time'] );
			if ( $timestamp ) {
				$next_payment = gmdate( 'Y-m-d H:i:s', $timestamp );
			}
		}

		RPSFW_Subscriptions_Manager::record_renewal( $subscription, $amount, $txn_id, $next_payment );
	}

	/**
	 * Status-change webhooks. The resource is the subscription object
	 * (its id is the PayPal subscription id).
	 *
	 * @param array  $resource Resource.
	 * @param string $status   New local status.
	 * @param string $note     Note.
	 */
	private static function webhook_status_change( $resource, $status, $note ) {
		$subscription = rpsfw_get_subscription_by_gateway_id( isset( $resource['id'] ) ? $resource['id'] : '' );
		if ( ! $subscription || $subscription->has_status( $status ) ) {
			return;
		}

		// Cancelled and expired are final. PayPal fires SUSPENDED and CANCELLED
		// within the same second when a customer pauses and then cancels, and
		// delivery order is not guaranteed - without this, a SUSPENDED event
		// processed last would show the customer's cancelled subscription as
		// on-hold again while PayPal has it cancelled.
		if ( $subscription->has_terminal_status() ) {
			self::log(
				'Ignoring "' . $status . '" event for subscription #' . $subscription->get_id()
				. ' (' . $subscription->get_gateway_sub_id() . '): already ' . $subscription->get_status() . ', which is final.'
			);
			return;
		}

		// pending-cancel is a local nuance Stripe uses; PayPal statuses map
		// directly.
		$subscription->update_status( $status, $note );
	}

	/**
	 * BILLING.SUBSCRIPTION.PAYMENT.FAILED: note + notification. PayPal
	 * retries per its own policy, so no status change.
	 *
	 * @param array $resource Subscription resource.
	 */
	private static function webhook_payment_failed( $resource ) {
		$subscription = rpsfw_get_subscription_by_gateway_id( isset( $resource['id'] ) ? $resource['id'] : '' );
		if ( ! $subscription ) {
			return;
		}
		$subscription->add_note( __( 'PayPal reported a failed renewal payment. PayPal will retry according to its retry policy.', 'restore-paypal-standard-for-woocommerce' ) );
		do_action( 'rpsfw_subscription_payment_failed', $subscription );
	}

	// -----------------------------------------------------------------
	// Remote lifecycle actions (called by the manager).
	// -----------------------------------------------------------------

	/**
	 * Cancel the PayPal subscription.
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 * @param string             $reason       Reason sent to PayPal.
	 * @return true|WP_Error
	 */
	public static function remote_cancel( $subscription, $reason = '' ) {
		return self::remote_action( $subscription, 'cancel_subscription', $reason );
	}

	/**
	 * Suspend the PayPal subscription.
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 * @param string             $reason       Reason sent to PayPal.
	 * @return true|WP_Error
	 */
	public static function remote_suspend( $subscription, $reason = '' ) {
		return self::remote_action( $subscription, 'suspend_subscription', $reason );
	}

	/**
	 * Reactivate the PayPal subscription.
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 * @param string             $reason       Reason sent to PayPal.
	 * @return true|WP_Error
	 */
	public static function remote_resume( $subscription, $reason = '' ) {
		return self::remote_action( $subscription, 'activate_subscription', $reason );
	}

	/**
	 * Shared remote action runner.
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 * @param string             $method       API method name.
	 * @param string             $reason       Reason.
	 * @return true|WP_Error
	 */
	private static function remote_action( $subscription, $method, $reason ) {
		$gateway = self::get_gateway();
		if ( ! $gateway ) {
			return new WP_Error( 'rpsfw_ppcp_unavailable', __( 'PayPal Commerce gateway is unavailable.', 'restore-paypal-standard-for-woocommerce' ) );
		}
		$paypal_sub_id = $subscription->get_gateway_sub_id();
		if ( ! $paypal_sub_id ) {
			return new WP_Error( 'rpsfw_ppcp_no_sub_id', __( 'No PayPal subscription is linked to this record.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		list( $env, $onboard ) = self::get_env_onboard( $gateway );

		$success = $gateway->api->{$method}( $env, $onboard, $paypal_sub_id, $reason ? $reason : __( 'Requested from WooCommerce.', 'restore-paypal-standard-for-woocommerce' ) );
		if ( ! $success ) {
			return new WP_Error(
				'rpsfw_ppcp_action_failed',
				__( 'PayPal did not accept the request. Please try again or manage the subscription from your PayPal dashboard.', 'restore-paypal-standard-for-woocommerce' )
			);
		}
		return true;
	}
}
