<?php
/**
 * PayPal Commerce + WooCommerce Subscriptions integration.
 *
 * Strategy: PayPal owns the schedule. We create a Catalog Product + Billing
 * Plan + Subscription up-front, redirect the customer to approve once, and
 * then sync renewal payments back into WC via webhooks.
 *
 * The gateway declares `gateway_scheduled_payments` so WC Subscriptions
 * delegates the renewal cadence to PayPal — WC's scheduler will not fire
 * the renewal hook for these subscriptions, and PayPal-side billing
 * (including dunning / Smart Retries equivalents) drives the subscription
 * lifecycle. Renewals are mirrored back into WC via the
 * BILLING.SUBSCRIPTION.* and PAYMENT.SALE.COMPLETED webhooks.
 *
 * Loaded only when the WooCommerce Subscriptions plugin is active.
 *
 * @package RestorePayPalStandard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_PayPal_Commerce_Subscriptions
 */
class WC_PayPal_Commerce_Subscriptions {

	const META_PRODUCT_ID      = '_rpsfw_ppcp_product_id';
	const META_PLAN_ID         = '_rpsfw_ppcp_plan_id';
	const META_SUBSCRIPTION_ID = '_rpsfw_ppcp_subscription_id';
	const META_LAST_PAYMENT_ID = '_rpsfw_ppcp_last_payment_id';
	const PLAN_CACHE_OPTION    = 'rpsfw_ppcp_plan_cache';

	/**
	 * The gateway instance.
	 *
	 * @var WC_Gateway_PayPal_Commerce
	 */
	private $gateway;

	/**
	 * Bootstrap. Returns false when WC Subscriptions is not present.
	 *
	 * @param WC_Gateway_PayPal_Commerce $gateway Gateway instance.
	 * @return bool
	 */
	public static function maybe_init( $gateway ) {
		if ( ! function_exists( 'wcs_is_subscription' ) ) {
			return false;
		}
		new self( $gateway );
		return true;
	}

	/**
	 * Constructor.
	 *
	 * @param WC_Gateway_PayPal_Commerce $gateway Gateway instance.
	 */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;

		// Subscription support flags. We do not claim any
		// subscription_payment_method_change_* flags because the customer
		// cannot transfer an existing PayPal-managed subscription to a
		// different one without re-approving on PayPal — the customer must
		// explicitly switch to a different gateway.
		// The key flag here is gateway_scheduled_payments: it tells WC
		// Subscriptions that PayPal owns the renewal cadence, so WC's
		// scheduler will not fire the renewal hook for this subscription.
		// subscription_amount_changes is declared so that WCS_Limited_Recurring_Coupon_Manager
		// does not filter out this gateway when a limited-payment recurring coupon is applied.
		// Although PayPal-managed plans cannot be revised mid-cycle, the plan cache already
		// includes the discounted amount so every plan is effectively created at the discounted
		// price; the coupon is baked into the Price/Plan at creation time.
		//
		// multiple_subscriptions is intentionally NOT declared: PayPal's
		// Subscriptions API is plan-based with a single billing cadence per
		// subscription, so a cart with more than one distinct billing schedule
		// cannot be represented (only one recurring line would bill). By not
		// declaring it, WooCommerce Subscriptions hides PayPal Commerce at
		// checkout when count(recurring_carts) > 1, so those carts use a gateway
		// that supports mixed intervals (Stripe) instead of mis-billing here.
		$this->gateway->supports = array_merge(
			$this->gateway->supports,
			array(
				'subscriptions',
				'gateway_scheduled_payments',
				'subscription_cancellation',
				'subscription_suspension',
				'subscription_reactivation',
				'subscription_date_changes',
				'subscription_amount_changes',
			)
		);

		// AJAX: create a PayPal Subscription approval URL on demand from the
		// front-end button.
		add_action( 'wp_ajax_rpsfw_ppcp_create_subscription', array( $this, 'ajax_create_subscription' ) );
		add_action( 'wp_ajax_nopriv_rpsfw_ppcp_create_subscription', array( $this, 'ajax_create_subscription' ) );

		// Replace process_payment for subscription carts: instead of capturing
		// a one-time order, finalize a PayPal subscription that the customer
		// already approved.
		add_filter( 'rpsfw_ppcp_process_payment_override', array( $this, 'maybe_override_process_payment' ), 10, 2 );

		// Subscription lifecycle hooks: propagate WC actions to PayPal.
		add_action( 'woocommerce_subscription_status_cancelled', array( $this, 'cancel_paypal_subscription' ) );
		add_action( 'woocommerce_subscription_status_on-hold', array( $this, 'suspend_paypal_subscription' ) );
		add_action( 'woocommerce_subscription_status_active', array( $this, 'reactivate_paypal_subscription' ) );

		// Hide WC Subs admin actions that don't make sense for
		// gateway-driven subscriptions. "Process renewal" and "Create
		// pending renewal" trigger WC's renewal pipeline which our
		// gateway doesn't honour (PayPal owns the schedule). The customer
		// would see a confusing no-op or a stuck pending order.
		add_filter( 'woocommerce_order_actions', array( $this, 'filter_subscription_admin_actions' ), 20, 1 );

		// When the customer changes the WC subscription's payment method to
		// something other than ours, cancel the PayPal-side subscription so
		// PayPal does not keep billing alongside the new gateway.
		add_action( 'woocommerce_subscriptions_pre_update_payment_method', array( $this, 'on_payment_method_change_away' ), 10, 3 );

		// PayPal webhook events that signal renewals or status changes.
		add_action( 'rpsfw_ppcp_webhook_event', array( $this, 'handle_webhook_event' ), 10, 2 );

		// Frontend: when cart contains a subscription, swap the PayPal SDK
		// to subscription mode.
		add_filter( 'rpsfw_ppcp_sdk_args', array( $this, 'filter_sdk_args_for_subscription' ) );
	}

	/**
	 * True when the cart currently contains a subscription product.
	 *
	 * @return bool
	 */
	public static function cart_contains_subscription() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		// Built-in (native) subscriptions provided by this plugin — no
		// WooCommerce Subscriptions plugin needed. The check is a no-op
		// when WCS is active (the module's purchase surfaces defer to it).
		if ( class_exists( 'RPSFW_Subscriptions_Cart' ) && RPSFW_Subscriptions_Cart::cart_contains_subscription() ) {
			return true;
		}
		// Native subscription product types.
		if ( class_exists( 'WC_Subscriptions_Cart' ) && WC_Subscriptions_Cart::cart_contains_subscription() ) {
			return true;
		}
		// Plan-based subscriptions (WooCommerce Subscriptions 9.0+ "subscription
		// plans" / All Products for Subscriptions). We read the persisted
		// cart-item scheme (wcsatt_data.active_subscription_scheme) directly via
		// WCS_ATT_Cart, which is reliable in every request context — unlike the
		// product-type check above, which depends on the scheme being re-applied
		// to the product object at the moment of the call and can therefore
		// return false during script/data enqueue for plan carts.
		if ( class_exists( 'WCS_ATT_Cart' ) ) {
			foreach ( WC()->cart->get_cart() as $cart_item ) {
				// WCS_ATT_Cart::get_subscription_scheme() returns:
				//  - null  : product has no subscription plan data,
				//  - false : a plan product the customer chose to buy ONE TIME
				//            (WCS 9.0 "one time" radio; parse_subscription_scheme_key
				//            returns false for an empty/"0" key),
				//  - string: an actual subscription scheme key was selected.
				// Only a non-empty scheme key means this is a subscription, so we
				// must NOT treat the one-time (false) selection as a subscription —
				// otherwise we render "Subscribe" buttons and fail to build a plan
				// for what is really a one-off purchase.
				$scheme = WCS_ATT_Cart::get_subscription_scheme( $cart_item );
				if ( ! empty( $scheme ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * True when the cart contains more than one distinct billing schedule.
	 *
	 * WooCommerce Subscriptions groups cart items by billing schedule into
	 * WC()->cart->recurring_carts; more than one entry means the cart has
	 * multiple distinct schedules (e.g. a daily sub + a weekly sub). PayPal's
	 * Subscriptions API is plan-based with a single billing cadence per
	 * subscription, so such a cart cannot be represented as one PayPal
	 * subscription. Two subscriptions that share the SAME schedule are grouped
	 * into a single recurring cart, so this correctly returns false for those
	 * (they fold into one plan). Used to hide PayPal Commerce at checkout for
	 * mixed-interval carts (relying on the WCS `multiple_subscriptions` support
	 * flag alone proved unreliable in some setups).
	 *
	 * @return bool
	 */
	public static function cart_contains_multiple_subscriptions() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		// Built-in (native) subscriptions: more than one subscription
		// product in the cart. Stripe handles those (one Stripe
		// subscription per product); PayPal cannot, so the gateway hides
		// itself. No-op when the WCS plugin is active.
		if ( class_exists( 'RPSFW_Subscriptions_Cart' ) && RPSFW_Subscriptions_Cart::cart_contains_multiple_subscriptions() ) {
			return true;
		}

		// Prefer WCS's own detector when available (same recurring_carts logic).
		if ( class_exists( 'WC_Subscriptions_Cart' )
			&& method_exists( 'WC_Subscriptions_Cart', 'cart_contains_multiple_subscriptions' ) ) {
			return (bool) WC_Subscriptions_Cart::cart_contains_multiple_subscriptions();
		}

		// Fallback: count recurring carts directly. These are populated by WCS
		// when the cart totals are calculated; ensure they exist first.
		if ( empty( WC()->cart->recurring_carts ) && method_exists( WC()->cart, 'calculate_totals' ) ) {
			WC()->cart->calculate_totals();
		}
		if ( ! empty( WC()->cart->recurring_carts ) ) {
			return count( WC()->cart->recurring_carts ) > 1;
		}
		return false;
	}

	/**
	 * True when an order contains any subscriptions (initial or renewal).
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function order_contains_subscription( $order ) {
		if ( ! function_exists( 'wcs_order_contains_subscription' ) ) {
			return false;
		}
		return wcs_order_contains_subscription( $order ) || wcs_order_contains_renewal( $order );
	}

	/**
	 * Frontend toggles SDK params when cart is a subscription cart.
	 *
	 * PayPal does not accept disable-funding/enable-funding lists alongside
	 * intent=subscription, so we strip those when in subscription mode.
	 *
	 * @param array $args SDK args passed to https://www.paypal.com/sdk/js.
	 * @return array
	 */
	public function filter_sdk_args_for_subscription( $args ) {
		if ( ! self::cart_contains_subscription() ) {
			return $args;
		}
		$args['intent'] = 'subscription';
		// PayPal requires vault=true to render subscription buttons. This is a
		// JS SDK flag, not the Vault API.
		$args['vault']  = 'true';
		unset( $args['disable-funding'], $args['enable-funding'] );
		return $args;
	}

	/**
	 * AJAX: create a PayPal subscription and return its approval URL.
	 *
	 * Builds the catalog product and plan on demand (cached by the recurring
	 * total, currency, interval, and trial), then creates a subscription bound
	 * to that plan.
	 */
	public function ajax_create_subscription() {
		check_ajax_referer( 'rpsfw-ppcp-create-subscription', 'nonce' );

		try {
			if ( ! WC()->cart || WC()->cart->is_empty() ) {
				throw new Exception( __( 'Your cart is empty.', 'restore-paypal-standard-for-woocommerce' ) );
			}
			if ( ! self::cart_contains_subscription() ) {
				throw new Exception( __( 'No subscription in cart.', 'restore-paypal-standard-for-woocommerce' ) );
			}

			// Order-first gate (checkout page only): validate the WooCommerce
			// checkout fields before creating the PayPal subscription so the
			// shopper can't approve payment with an empty/invalid form. The
			// button posts the field values as standard WooCommerce keys.
			if ( ! empty( $_POST['rpsfw_validate_fields'] ) ) {
				if ( ! class_exists( 'RPSFW_Checkout_Validator' ) ) {
					require_once dirname( __FILE__ ) . '/../class-rpsfw-checkout-validator.php';
				}
				$checkout_errors = RPSFW_Checkout_Validator::validate();
				if ( $checkout_errors->has_errors() ) {
					throw new Exception( implode( ' ', $checkout_errors->get_error_messages() ) );
				}
			}

			// Populate the customer from the posted checkout fields (WooCommerce
			// way) so the PayPal subscriber built below is complete — the classic
			// checkout doesn't sync name/email into WC()->customer during this
			// pre-submit AJAX.
			$this->update_customer_from_posted();

			$plan = $this->ensure_plan_for_cart();
			if ( ! $plan ) {
				throw new Exception( __( 'Could not create or retrieve subscription plan.', 'restore-paypal-standard-for-woocommerce' ) );
			}

			$env        = $this->gateway->testmode ? 'sandbox' : 'live';
			$onboarding = $this->gateway->get_option( 'ppcp_onboarding', array() );
			$onboard    = isset( $onboarding[ $env ] ) ? $onboarding[ $env ] : array();

			// If we already created a PayPal subscription for this cart
			// in this session and it's still in APPROVAL_PENDING (i.e.
			// the customer clicked the button but didn't finish), reuse
			// the same subscription id instead of creating a duplicate
			// PayPal Subscription. Avoids "no WC subscription found"
			// errors when PayPal fires PAYMENT.SALE.COMPLETED for an
			// orphan subscription.
			// Build the subscriber (payer + shipping address) once, and key the
			// "reuse pending subscription" cache on it. A PayPal subscription's
			// shipping address is fixed at creation and cannot be edited while
			// it is APPROVAL_PENDING, so if the shopper changes their address
			// and clicks again we MUST create a new subscription rather than
			// reuse the stale one. Reusing was the bug that made recurring
			// checkouts send an old address.
			$subscriber      = $this->build_subscriber_from_session();
			$subscriber_hash = md5( (string) wp_json_encode( $subscriber ) );

			$existing_id   = WC()->session ? WC()->session->get( 'rpsfw_ppcp_pending_subscription_id' ) : '';
			$existing_plan = WC()->session ? WC()->session->get( 'rpsfw_ppcp_pending_plan_id' ) : '';
			$existing_hash = WC()->session ? WC()->session->get( 'rpsfw_ppcp_pending_subscriber_hash' ) : '';
			if ( $existing_id && $existing_plan === $plan['plan_id'] && $existing_hash === $subscriber_hash ) {
				$details = $this->gateway->api->get_subscription( $env, $onboard, $existing_id );
				if ( ! empty( $details['status'] ) && in_array( $details['status'], array( 'APPROVAL_PENDING', 'APPROVED' ), true ) ) {
					wp_send_json_success( array(
						'subscription_id' => $existing_id,
					) );
				}
			}

			$response = $this->gateway->api->create_subscription(
				$env,
				$onboard,
				array(
					'plan_id'    => $plan['plan_id'],
					'return_url' => add_query_arg( array( 'rpsfw_ppcp_sub_return' => 1 ), wc_get_checkout_url() ),
					'cancel_url' => wc_get_cart_url(),
					'subscriber' => $subscriber,
				)
			);

			// Self-heal stale plans: a cached plan_id created on a previously
			// connected PayPal account no longer exists on the current
			// account, so PayPal returns "The specified resource does not
			// exist." Recreate the product/plan once and retry.
			if ( is_array( $response ) && ! empty( $response['not_found'] ) ) {
				WC_Gateway_PayPal_Commerce::log( 'Cached subscription plan no longer exists on PayPal; recreating and retrying.', 'warning' );
				$plan = $this->ensure_plan_for_cart( true );
				if ( ! $plan ) {
					throw new Exception( __( 'Could not create or retrieve subscription plan.', 'restore-paypal-standard-for-woocommerce' ) );
				}
				$response = $this->gateway->api->create_subscription(
					$env,
					$onboard,
					array(
						'plan_id'    => $plan['plan_id'],
						'return_url' => add_query_arg( array( 'rpsfw_ppcp_sub_return' => 1 ), wc_get_checkout_url() ),
						'cancel_url' => wc_get_cart_url(),
						'subscriber' => $subscriber,
					)
				);
			}

			if ( empty( $response['approve_url'] ) || empty( $response['subscription_id'] ) ) {
				throw new Exception( __( 'PayPal did not return an approval URL.', 'restore-paypal-standard-for-woocommerce' ) );
			}

			// Stash the subscription id in session so process_payment can finalize it.
			WC()->session->set( 'rpsfw_ppcp_pending_subscription_id', $response['subscription_id'] );
			WC()->session->set( 'rpsfw_ppcp_pending_plan_id', $plan['plan_id'] );
			WC()->session->set( 'rpsfw_ppcp_pending_product_id', $plan['product_id'] );
			WC()->session->set( 'rpsfw_ppcp_pending_subscriber_hash', $subscriber_hash );

			wp_send_json_success( array(
				'subscription_id' => $response['subscription_id'],
				'approve_url'     => $response['approve_url'],
			) );
		} catch ( Exception $e ) {
			WC_Gateway_PayPal_Commerce::log( 'Subscriptions ajax error: ' . $e->getMessage(), 'error' );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * When the cart has a subscription, route process_payment through here
	 * instead of the standard order-capture flow.
	 *
	 * @param array|null $result Override result (null = do nothing).
	 * @param WC_Order   $order  Order being checked out.
	 * @return array|null
	 */
	public function maybe_override_process_payment( $result, $order ) {
		if ( ! self::order_contains_subscription( $order ) ) {
			return $result;
		}

		// Renewal orders never come through checkout; only initial purchases.
		if ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) {
			return $result;
		}

		try {
			$subscription_id = '';
			if ( ! empty( $_POST['rpsfw_ppcp_subscription_id'] ) ) {
				$subscription_id = sanitize_text_field( wp_unslash( $_POST['rpsfw_ppcp_subscription_id'] ) );
			} elseif ( WC()->session ) {
				$subscription_id = WC()->session->get( 'rpsfw_ppcp_pending_subscription_id' );
			}

			if ( empty( $subscription_id ) ) {
				throw new Exception( __( 'PayPal subscription was not approved. Please click the PayPal button and complete approval.', 'restore-paypal-standard-for-woocommerce' ) );
			}

			self::finalize_subscription_for_order( $this->gateway, $order, $subscription_id );

			return array(
				'result'   => 'success',
				'redirect' => $this->gateway->get_return_url( $order ),
			);
		} catch ( Exception $e ) {
			WC_Gateway_PayPal_Commerce::log( 'Subscriptions process_payment error: ' . $e->getMessage(), 'error' );
			wc_add_notice( $e->getMessage(), 'error' );
			return array( 'result' => 'failure' );
		}
	}

	/**
	 * Verify a PayPal subscription is approved and persist all needed
	 * meta on the WC order + each WC subscription. Marks the parent WC
	 * order as paid.
	 *
	 * Used by both the regular checkout flow (via
	 * maybe_override_process_payment) and the cart-page express flow
	 * (via the rpsfw_ppcp_process_cart_subscription AJAX handler).
	 *
	 * Static so it can be called without re-initializing the
	 * subscriptions integration (which would re-register all hooks).
	 *
	 * @param WC_Gateway_PayPal_Commerce $gateway         Gateway instance.
	 * @param WC_Order                   $order           WC order to finalize.
	 * @param string                     $subscription_id PayPal subscription id (I-...).
	 * @return void
	 * @throws Exception When the PayPal subscription is missing or not in an approved state.
	 */
	public static function finalize_subscription_for_order( $gateway, $order, $subscription_id ) {
		$env        = $gateway->testmode ? 'sandbox' : 'live';
		$onboarding = $gateway->get_option( 'ppcp_onboarding', array() );
		$onboard    = isset( $onboarding[ $env ] ) ? $onboarding[ $env ] : array();

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

		// Persist on the order and on each WC Subscription.
		$order->update_meta_data( self::META_SUBSCRIPTION_ID, $subscription_id );
		if ( $plan_id ) {
			$order->update_meta_data( self::META_PLAN_ID, $plan_id );
		}
		if ( $product_id ) {
			$order->update_meta_data( self::META_PRODUCT_ID, $product_id );
		}
		$order->set_transaction_id( $subscription_id );
		$order->save();

		foreach ( wcs_get_subscriptions_for_order( $order ) as $wc_subscription ) {
			$wc_subscription->update_meta_data( self::META_SUBSCRIPTION_ID, $subscription_id );
			if ( $plan_id ) {
				$wc_subscription->update_meta_data( self::META_PLAN_ID, $plan_id );
			}
			if ( $product_id ) {
				$wc_subscription->update_meta_data( self::META_PRODUCT_ID, $product_id );
			}
			$wc_subscription->set_payment_method( $gateway->id );
			$wc_subscription->set_payment_method_title( $gateway->get_title() );
			// gateway_scheduled_payments handles the cadence — WC's
			// scheduler will not fire the renewal hook for this
			// subscription, so we do not need to set
			// _requires_manual_renewal.
			$wc_subscription->save();
		}

		// Mark the parent order paid. The first PAYMENT.SALE.COMPLETED
		// webhook from PayPal will reconcile the actual transaction — and can
		// arrive while the PayPal calls above are in flight, so re-check the
		// stored status. Completing the order twice re-sends the customer
		// "order processing" email.
		if ( rpsfw_order_still_needs_payment( $order ) ) {
			$order->payment_complete( $subscription_id );
			$order->add_order_note(
				sprintf(
					/* translators: %s: PayPal subscription id */
					__( 'PayPal subscription approved (Subscription ID: %s).', 'restore-paypal-standard-for-woocommerce' ),
					$subscription_id
				)
			);
		}

		if ( WC()->session ) {
			WC()->session->__unset( 'rpsfw_ppcp_pending_subscription_id' );
			WC()->session->__unset( 'rpsfw_ppcp_pending_plan_id' );
			WC()->session->__unset( 'rpsfw_ppcp_pending_product_id' );
		}
	}

	/**
	 * Cache plans by product/price/interval so we do not create a new plan
	 * per checkout.
	 *
	 * Cache key is a hash of (currency, total per cycle, interval unit,
	 * interval count, trial cycles, environment).
	 *
	 * @return array{plan_id:string,product_id:string}|false
	 */
	private function ensure_plan_for_cart( $force_refresh = false ) {
		$signature = $this->build_plan_signature_from_cart();
		if ( ! $signature ) {
			return false;
		}
		$cache_key = $signature['hash'];

		$cache = get_option( self::PLAN_CACHE_OPTION, array() );
		if ( ! $force_refresh && isset( $cache[ $cache_key ] ) ) {
			return $cache[ $cache_key ];
		}

		// Drop any stale entry so a fresh product/plan is created below.
		if ( $force_refresh && isset( $cache[ $cache_key ] ) ) {
			unset( $cache[ $cache_key ] );
			update_option( self::PLAN_CACHE_OPTION, $cache, false );
		}

		$env        = $this->gateway->testmode ? 'sandbox' : 'live';
		$onboarding = $this->gateway->get_option( 'ppcp_onboarding', array() );
		$onboard    = isset( $onboarding[ $env ] ) ? $onboarding[ $env ] : array();

		// Create the catalog product first.
		$product = $this->gateway->api->create_subscription_product(
			$env,
			$onboard,
			array(
				'name'        => sprintf( '%s subscription', get_bloginfo( 'name' ) ),
				'description' => __( 'Subscription product', 'restore-paypal-standard-for-woocommerce' ),
				'type'        => 'SERVICE',
			)
		);

		if ( empty( $product['product_id'] ) ) {
			return false;
		}

		// Then create the plan tied to that product.
		$plan = $this->gateway->api->create_subscription_plan(
			$env,
			$onboard,
			array(
				'product_id'      => $product['product_id'],
				'name'            => $signature['plan_name'],
				'description'     => $signature['plan_name'],
				'currency_code'   => $signature['currency'],
				'amount'          => $signature['amount'],
				'setup_fee'       => $signature['setup_fee'],
				'interval_unit'   => $signature['interval_unit'],
				'interval_count'  => $signature['interval_count'],
				'total_cycles'    => $signature['total_cycles'],
				'trial_amount'    => $signature['trial_amount'],
				'trial_unit'      => $signature['trial_unit'],
				'trial_count'     => $signature['trial_count'],
				'trial_cycles'    => $signature['trial_cycles'],
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
	 * Read the recurring portion of the cart to derive plan parameters.
	 *
	 * The recurring price comes from the subscription product (not from
	 * cart->total, which during a trial-with-fee cart equals only the
	 * sign-up fee). Sign-up fees are summed across subscription line
	 * items and forwarded to PayPal as payment_preferences.setup_fee.
	 *
	 * Mappings (canonical, per PayPal docs):
	 *   - Trial only (no fee):
	 *       TRIAL: 1x $0 for trial_length × trial_period
	 *       REGULAR: $amount per interval
	 *
	 *   - Trial + sign-up fee:
	 *       payment_preferences.setup_fee = $signup_fee (charged today)
	 *       TRIAL: 1x $0 for trial_length × trial_period
	 *       REGULAR: $amount per interval
	 *
	 *   - No trial + sign-up fee:
	 *       payment_preferences.setup_fee = $signup_fee (charged today)
	 *       REGULAR: $amount per interval (first cycle starts today)
	 *
	 *   - No trial, no fee:
	 *       REGULAR: $amount per interval
	 *
	 * Multi-line carts use the dominant interval; carts with more than
	 * one unique recurring schedule will produce one plan per unique
	 * signature.
	 *
	 * @return array|false
	 */
	private function build_plan_signature_from_cart() {
		if ( ! WC()->cart ) {
			return false;
		}
		WC()->cart->calculate_totals();

		$currency       = get_woocommerce_currency();
		$interval_unit  = 'MONTH';
		$interval_count = 1;
		$total_cycles   = 0; // 0 = infinite
		$trial_amount   = '0';
		$trial_unit     = 'DAY';
		$trial_count    = 0;
		$trial_cycles   = 0;
		$plan_name      = sprintf( '%s plan', get_bloginfo( 'name' ) );

		// Fallback recurring price (raw product price sum) used only if the
		// recurring cart is unavailable. The authoritative recurring amount
		// (after recurring coupons, tax and shipping) comes from the
		// recurring cart total below.
		$fallback_recurring = 0.0;
		$signup_fee_total   = 0.0;
		$found_subscription = false;
		$has_free_trial     = false;

		// Inspect cart items for subscription line items.
		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'];
			if ( ! class_exists( 'WC_Subscriptions_Product' ) ) {
				continue;
			}
			if ( ! WC_Subscriptions_Product::is_subscription( $product ) ) {
				continue;
			}

			$qty                 = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
			$fallback_recurring += (float) WC_Subscriptions_Product::get_price( $product ) * max( 1, $qty );
			$signup_fee_total   += (float) WC_Subscriptions_Product::get_sign_up_fee( $product ) * max( 1, $qty );

			// Use the first subscription's schedule as the dominant one.
			if ( ! $found_subscription ) {
				$period   = WC_Subscriptions_Product::get_period( $product );
				$interval = (int) WC_Subscriptions_Product::get_interval( $product );
				$length   = (int) WC_Subscriptions_Product::get_length( $product );

				$interval_unit  = $this->wc_period_to_paypal_unit( $period );
				$interval_count = max( 1, $interval );
				$total_cycles   = max( 0, $length );

				$trial_length = (int) WC_Subscriptions_Product::get_trial_length( $product );
				if ( $trial_length > 0 ) {
					$trial_period = WC_Subscriptions_Product::get_trial_period( $product );
					$trial_unit   = $this->wc_period_to_paypal_unit( $trial_period );
					$trial_count  = $trial_length;
					$trial_cycles = 1;
					$has_free_trial = true;
					// Free trial: trial_amount stays at '0'. The signup
					// fee, if any, is charged via setup_fee, not folded
					// into the trial cycle.
				}

				$plan_name          = wp_strip_all_tags( $product->get_name() );
				$found_subscription = true;
			}
		}

		if ( ! $found_subscription ) {
			return false;
		}

		// ---------------------------------------------------------------
		// WHY SHIPPING/TAX ARE NOT SHOWN AS SEPARATE LINES ON PAYPAL FOR
		// SUBSCRIPTIONS (intentional, documented behaviour):
		//
		// PayPal has two different APIs:
		//   - Orders API (one-off payments) supports an itemized
		//     amount.breakdown (item_total, shipping, tax_total, handling,
		//     discount). That is why NON-subscription physical orders DO show
		//     a separate shipping line on PayPal — see PayPalClient::create_order
		//     in the relay, which builds and reconciles that breakdown.
		//   - Subscriptions API (billing/subscriptions, used here) is
		//     PLAN-based. A plan has a single recurring billing amount per
		//     cycle and has NO per-line breakdown. There is simply no field to
		//     express "product $X + shipping $Y" on a subscription cycle.
		//
		// So for subscriptions we fold the FULL recurring total (products +
		// recurring shipping + tax + fees) into the plan's billing amount. The
		// customer is charged the correct amount ($recurring_amount), but
		// PayPal will only ever display that single number — it cannot itemize
		// shipping. This is a PayPal platform limitation, not a bug.
		// ---------------------------------------------------------------
		//
		// Recurring amount per renewal AFTER recurring coupons. WC
		// Subscriptions exposes the recurring cart on calculate_totals and
		// applies recurring_fee / recurring_percent coupons (and removes
		// non-recurring coupons) while calculating it. We read
		// get_total('edit') — the FULL recurring total, including recurring
		// shipping, tax and fees (the "Recurring total" shown to the customer)
		// — so PayPal bills exactly what WooCommerce renews instead of silently
		// dropping shipping and tax. Fall back to the contents total, then the
		// raw product price sum, if a total isn't available.
		$recurring_amount = 0.0;
		if ( ! empty( WC()->cart->recurring_carts ) ) {
			$recurring_cart   = reset( WC()->cart->recurring_carts );
			$recurring_amount = (float) $recurring_cart->get_total( 'edit' );
			if ( $recurring_amount <= 0 ) {
				$recurring_amount = (float) $recurring_cart->get_cart_contents_total();
			}
		}
		if ( $recurring_amount <= 0 ) {
			$recurring_amount = $fallback_recurring;
		}

		// Amount due today: the FULL cart total including shipping, tax and
		// fees (the "Total" shown to the customer), after all coupons and any
		// folded-in sign-up fee. Using the grand total keeps the first PayPal
		// payment in sync with what WooCommerce charges today.
		$initial_total = (float) WC()->cart->get_total( 'edit' );

		$amount = number_format( $recurring_amount, 2, '.', '' );

		// Reconcile the first PayPal payment to $initial_total.
		//
		//  - Free trial: nothing recurs today; the only "due today" amount
		//    is the (possibly discounted) sign-up fee. PayPal charges
		//    payment_preferences.setup_fee up front, so set it to
		//    $initial_total and keep the $0 trial cycle.
		//
		//  - No free trial: PayPal charges setup_fee + the first billing
		//    cycle today. When the first payment differs from the recurring
		//    price (initial-only discount, or a sign-up fee), model the
		//    first cycle as a single paid intro cycle priced at
		//    $initial_total and drop setup_fee, so renewals still bill the
		//    full recurring price. When they match (no discount, no fee),
		//    skip the intro cycle entirely — identical to the no-coupon
		//    behaviour.
		$setup_fee = '0';
		if ( $has_free_trial ) {
			$setup_fee = number_format( $initial_total, 2, '.', '' );
		} else {
			$needs_intro_cycle = ( abs( $initial_total - $recurring_amount ) >= 0.01 );
			if ( $needs_intro_cycle ) {
				$trial_unit   = $interval_unit;
				$trial_count  = $interval_count;
				$trial_cycles = 1;
				$trial_amount = number_format( max( 0, $initial_total ), 2, '.', '' );

				// For finite-length subscriptions the paid intro cycle is
				// one of the total payments, so drop one regular cycle to
				// keep the overall payment count correct. Infinite plans
				// (total_cycles === 0) stay infinite.
				if ( $total_cycles > 0 ) {
					$total_cycles = max( 1, $total_cycles - 1 );
				}
			}
		}

		// Scope the cache key to the connected PayPal account. Plans live on
		// the seller's PayPal account, so a plan_id cached for one account is
		// invalid after the merchant disconnects and reconnects a different
		// account (PayPal then returns "The specified resource does not
		// exist"). Including the seller_id guarantees a fresh plan per account.
		$env       = $this->gateway->testmode ? 'sandbox' : 'live';
		$onboarding = $this->gateway->get_option( 'ppcp_onboarding', array() );
		$onboard    = isset( $onboarding[ $env ] ) ? $onboarding[ $env ] : array();
		$seller_id  = isset( $onboard['seller_id'] ) ? $onboard['seller_id'] : '';

		$hash = md5(
			implode( '|', array(
				$currency,
				$amount,
				$setup_fee,
				$interval_unit,
				$interval_count,
				$total_cycles,
				$trial_amount,
				$trial_unit,
				$trial_count,
				$trial_cycles,
				$env,
				$seller_id,
			) )
		);

		return array(
			'hash'           => $hash,
			'amount'         => $amount,
			'setup_fee'      => $setup_fee,
			'currency'       => $currency,
			'interval_unit'  => $interval_unit,
			'interval_count' => $interval_count,
			'total_cycles'   => $total_cycles,
			'trial_amount'   => $trial_amount,
			'trial_unit'     => $trial_unit,
			'trial_count'    => $trial_count,
			'trial_cycles'   => $trial_cycles,
			'plan_name'      => $plan_name,
		);
	}

	/**
	 * Map WC Subscriptions period to PayPal interval_unit.
	 *
	 * @param string $period day|week|month|year.
	 * @return string
	 */
	private function wc_period_to_paypal_unit( $period ) {
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
	 * Build the subscriber payload from session billing fields.
	 *
	 * @return array
	 */
	private function build_subscriber_from_session() {
		$customer = WC()->customer;
		if ( ! $customer ) {
			return array();
		}

		$email = $customer->get_billing_email();
		$first = $customer->get_billing_first_name();
		$last  = $customer->get_billing_last_name();

		$out = array();
		if ( $email ) {
			$out['email'] = $email;
		}
		if ( $first || $last ) {
			$out['first_name'] = $first;
			$out['last_name']  = $last;
		}

		// Include the shipping address so PayPal records it on the subscription
		// (needed for seller protection). Build it from the SAME collector the
		// one-off order flow uses: it reads the posted checkout fields and
		// honours "ship to a different address", falling back to WC()->customer
		// (shipping, then billing) for the cart/express flow that posts no
		// fields. Previously this read WC()->customer directly, which on the
		// pre-submit AJAX did not reflect a different shipping address the buyer
		// entered and so wrongly fell back to billing — the bug that only
		// affected subscription (recurring) checkouts.
		$shipping = function_exists( 'rpsfw_ppcp_collect_request_shipping_address' )
			? rpsfw_ppcp_collect_request_shipping_address()
			: array();

		// PayPal wants a name on the shipping address; fall back to the
		// subscriber name if the collected shipping name is blank. (The
		// collector already drops blank fields.)
		if ( empty( $shipping['first_name'] ) && empty( $shipping['last_name'] ) ) {
			if ( $first ) {
				$shipping['first_name'] = $first;
			}
			if ( $last ) {
				$shipping['last_name'] = $last;
			}
		}

		// Only attach the shipping address when the essentials are present.
		if ( ! empty( $shipping['address_1'] ) && ! empty( $shipping['country'] ) ) {
			$out['shipping_address'] = $shipping;
		}

		return $out;
	}

	/**
	 * Populate WC()->customer from the checkout fields posted with the button
	 * request, then let everything downstream read the customer via WooCommerce
	 * getters. This mirrors the standard WooCommerce customer-population pattern
	 * (a generic field loop + WC_Customer::set_props()); we do NOT hardcode a
	 * required-field list and only set the fields that were actually posted, so
	 * a field a store/checkout-editor removed is left untouched.
	 *
	 * Needed because on the classic checkout WC()->customer's name/email are not
	 * synced during this pre-submit AJAX, which otherwise yields an incomplete
	 * PayPal subscriber ("violates schema"). No-op for the cart/express flow,
	 * which posts no checkout fields.
	 *
	 * @return void
	 */
	private function update_customer_from_posted() {
		$customer = WC()->customer;
		if ( ! $customer ) {
			return;
		}

		// Only act when the request carries checkout fields (checkout submit),
		// not the cart/express flow (which posts none) — so we never wipe the
		// customer's existing data.
		if ( ! isset( $_POST['billing_email'] ) && ! isset( $_POST['billing_first_name'] ) && ! isset( $_POST['billing_address_1'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( isset( $_POST['billing_email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$customer->set_billing_email( sanitize_email( wp_unslash( $_POST['billing_email'] ) ) );
		}

		// Same field set WooCommerce uses for an address; only the ones actually
		// posted are applied (isset guard), so removed/disabled fields are
		// skipped. We set BILLING props only — WooCommerce already syncs the
		// shipping address for shipping calculation, so setting shipping here is
		// unnecessary and would make the checkout reveal the "Ship to a
		// different address?" section. build_subscriber_from_session() falls
		// back to billing for the shipping record when needed.
		$fields = array( 'first_name', 'last_name', 'country', 'state', 'postcode', 'city', 'address_1', 'address_2', 'phone', 'company' );
		$props  = array();

		foreach ( $fields as $field ) {
			$key = 'billing_' . $field;
			if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$props[ 'billing_' . $field ] = wc_clean( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
		}

		if ( ! empty( $props ) ) {
			$customer->set_props( $props );
		}
	}

	/**
	 * When a WC subscription's payment method is being changed away from
	 * us, cancel the corresponding PayPal subscription so the customer is
	 * not billed twice.
	 *
	 * @param WC_Subscription $subscription       Subscription.
	 * @param string          $new_payment_method New gateway id.
	 * @param string          $old_payment_method Old gateway id.
	 */
	public function on_payment_method_change_away( $subscription, $new_payment_method, $old_payment_method ) {
		if ( $old_payment_method !== $this->gateway->id ) {
			return;
		}
		if ( $new_payment_method === $this->gateway->id ) {
			return;
		}
		$id = $subscription->get_meta( self::META_SUBSCRIPTION_ID );
		if ( ! $id ) {
			return;
		}

		$env        = $this->gateway->testmode ? 'sandbox' : 'live';
		$onboarding = $this->gateway->get_option( 'ppcp_onboarding', array() );
		$onboard    = isset( $onboarding[ $env ] ) ? $onboarding[ $env ] : array();

		$this->gateway->api->cancel_subscription( $env, $onboard, $id, __( 'Customer switched to a different gateway.', 'restore-paypal-standard-for-woocommerce' ) );

		// Clear our meta now that the PayPal subscription is no longer
		// authoritative for this WC subscription.
		$subscription->delete_meta_data( self::META_SUBSCRIPTION_ID );
		$subscription->save();
	}

	/**
	 * Hide WC Subscriptions admin actions that conflict with the
	 * gateway-driven schedule.
	 *
	 * "Process renewal" fires woocommerce_scheduled_subscription_payment,
	 * which we do not handle (PayPal owns the schedule). "Create pending
	 * renewal" creates a renewal order that has no path to be paid
	 * because we never process renewals on the WC side. Both actions
	 * would silently no-op or get the customer stuck.
	 *
	 * @param array $actions Actions registered for the current order/subscription.
	 * @return array
	 */
	public function filter_subscription_admin_actions( $actions ) {
		global $theorder;

		if ( ! $theorder ) {
			return $actions;
		}
		if ( ! function_exists( 'wcs_is_subscription' ) || ! wcs_is_subscription( $theorder ) ) {
			return $actions;
		}
		if ( $theorder->get_payment_method() !== $this->gateway->id ) {
			return $actions;
		}

		unset( $actions['wcs_process_renewal'] );
		unset( $actions['wcs_create_pending_renewal'] );

		return $actions;
	}

	/**
	 * Cancel the corresponding PayPal subscription when the WC one is cancelled.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 */
	public function cancel_paypal_subscription( $subscription ) {
		if ( $subscription->get_payment_method() !== $this->gateway->id ) {
			return;
		}
		$id = $subscription->get_meta( self::META_SUBSCRIPTION_ID );
		if ( ! $id ) {
			return;
		}

		$env        = $this->gateway->testmode ? 'sandbox' : 'live';
		$onboarding = $this->gateway->get_option( 'ppcp_onboarding', array() );
		$onboard    = isset( $onboarding[ $env ] ) ? $onboarding[ $env ] : array();

		$this->gateway->api->cancel_subscription( $env, $onboard, $id, __( 'Cancelled in WooCommerce.', 'restore-paypal-standard-for-woocommerce' ) );
	}

	/**
	 * Suspend the corresponding PayPal subscription when WC sub goes on-hold.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 */
	public function suspend_paypal_subscription( $subscription ) {
		if ( $subscription->get_payment_method() !== $this->gateway->id ) {
			return;
		}
		$id = $subscription->get_meta( self::META_SUBSCRIPTION_ID );
		if ( ! $id ) {
			return;
		}

		$env        = $this->gateway->testmode ? 'sandbox' : 'live';
		$onboarding = $this->gateway->get_option( 'ppcp_onboarding', array() );
		$onboard    = isset( $onboarding[ $env ] ) ? $onboarding[ $env ] : array();

		$this->gateway->api->suspend_subscription( $env, $onboard, $id, __( 'Suspended in WooCommerce.', 'restore-paypal-standard-for-woocommerce' ) );
	}

	/**
	 * Reactivate the PayPal subscription when WC sub goes back to active.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 */
	public function reactivate_paypal_subscription( $subscription ) {
		if ( $subscription->get_payment_method() !== $this->gateway->id ) {
			return;
		}
		$id = $subscription->get_meta( self::META_SUBSCRIPTION_ID );
		if ( ! $id ) {
			return;
		}

		$env        = $this->gateway->testmode ? 'sandbox' : 'live';
		$onboarding = $this->gateway->get_option( 'ppcp_onboarding', array() );
		$onboard    = isset( $onboarding[ $env ] ) ? $onboarding[ $env ] : array();

		$this->gateway->api->activate_subscription( $env, $onboard, $id, __( 'Reactivated in WooCommerce.', 'restore-paypal-standard-for-woocommerce' ) );
	}

	/**
	 * Process subscription-related PayPal webhook events.
	 *
	 * @param string $event_type PayPal event type.
	 * @param array  $resource   PayPal resource payload.
	 */
	public function handle_webhook_event( $event_type, $resource ) {
		switch ( $event_type ) {
			case 'PAYMENT.SALE.COMPLETED':
				$this->webhook_renewal_completed( $resource );
				break;
			case 'BILLING.SUBSCRIPTION.CANCELLED':
				$this->webhook_subscription_cancelled( $resource );
				break;
			case 'BILLING.SUBSCRIPTION.SUSPENDED':
				$this->webhook_subscription_suspended( $resource );
				break;
			case 'BILLING.SUBSCRIPTION.ACTIVATED':
				$this->webhook_subscription_activated( $resource );
				break;
			case 'BILLING.SUBSCRIPTION.PAYMENT.FAILED':
				$this->webhook_subscription_payment_failed( $resource );
				break;
		}
	}

	/**
	 * Handle PAYMENT.SALE.COMPLETED for a subscription. Creates a renewal
	 * order in WC and marks it paid.
	 *
	 * @param array $resource Sale resource.
	 */
	private function webhook_renewal_completed( $resource ) {
		$paypal_subscription_id = isset( $resource['billing_agreement_id'] ) ? $resource['billing_agreement_id'] : '';
		if ( ! $paypal_subscription_id ) {
			return;
		}
		$wc_subscription = $this->find_wc_subscription_by_paypal_id( $paypal_subscription_id );
		if ( ! $wc_subscription ) {
			WC_Gateway_PayPal_Commerce::log( 'Renewal webhook: no WC subscription found for ' . $paypal_subscription_id, 'warning' );
			return;
		}

		// Skip if this is the very first sale for the subscription
		// (already accounted for at checkout/cart-express). The parent
		// order is created with transaction_id = the PayPal
		// subscription id; the actual PAYMENT.SALE.COMPLETED comes in
		// later. We detect "first sale" by checking whether the parent
		// has had its initial sale id stored yet.
		$last_order = $wc_subscription->get_last_order();
		$last_order_id = $last_order ? $last_order : 0;
		$last_paypal_txn = $last_order_id ? wc_get_order( $last_order_id )->get_transaction_id() : '';
		$is_first_sale_for_parent = $last_order_id
			&& $last_paypal_txn === $paypal_subscription_id
			&& ! wc_get_order( $last_order_id )->get_meta( self::META_LAST_PAYMENT_ID );

		if ( $is_first_sale_for_parent ) {
			// Stash the actual sale id on the parent order so the admin
			// dashboard link can deep-link to the real transaction
			// instead of the subscription id, and so subsequent webhooks
			// know we've already accounted for the first sale.
			if ( ! empty( $resource['id'] ) ) {
				$parent = wc_get_order( $last_order_id );
				if ( $parent ) {
					$parent->update_meta_data( self::META_LAST_PAYMENT_ID, $resource['id'] );
					$parent->save();
				}
			}
			return;
		}

		// Idempotency: skip if a renewal order already exists for this txn id.
		if ( ! empty( $resource['id'] ) ) {
			$existing = wc_get_orders( array(
				'limit'      => 1,
				'meta_key'   => '_transaction_id',
				'meta_value' => $resource['id'],
			) );
			if ( ! empty( $existing ) ) {
				WC_Gateway_PayPal_Commerce::log( 'Renewal webhook: duplicate event for txn ' . $resource['id'] . ', skipping.' );
				return;
			}
		}

		$renewal_order = wcs_create_renewal_order( $wc_subscription );
		if ( is_wp_error( $renewal_order ) ) {
			WC_Gateway_PayPal_Commerce::log( 'Renewal webhook: failed to create renewal order - ' . $renewal_order->get_error_message(), 'error' );
			return;
		}
		$renewal_order->set_payment_method( $this->gateway->id );
		$renewal_order->set_payment_method_title( $this->gateway->get_title() );

		$txn_id = isset( $resource['id'] ) ? $resource['id'] : $paypal_subscription_id;
		$renewal_order->payment_complete( $txn_id );
		$renewal_order->add_order_note(
			sprintf(
				/* translators: 1: PayPal txn id, 2: subscription id */
				__( 'PayPal renewal received. Transaction: %1$s, Subscription: %2$s.', 'restore-paypal-standard-for-woocommerce' ),
				$txn_id,
				$paypal_subscription_id
			)
		);

		// Update WC subscription's next-payment date from the resource if present.
		if ( ! empty( $resource['next_billing_time'] ) ) {
			$next = strtotime( $resource['next_billing_time'] );
			if ( $next ) {
				$wc_subscription->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', $next ) ) );
			}
		}
	}

	/**
	 * Handle BILLING.SUBSCRIPTION.CANCELLED webhook.
	 *
	 * @param array $resource Subscription resource.
	 */
	private function webhook_subscription_cancelled( $resource ) {
		$wc_sub = $this->find_wc_subscription_by_paypal_id( isset( $resource['id'] ) ? $resource['id'] : '' );
		if ( ! $wc_sub || $wc_sub->has_status( 'cancelled' ) ) {
			return;
		}
		// Suppress our own cancel-back-to-PayPal hook to avoid loops.
		remove_action( 'woocommerce_subscription_status_cancelled', array( $this, 'cancel_paypal_subscription' ) );
		$wc_sub->update_status( 'cancelled', __( 'PayPal reported subscription cancelled.', 'restore-paypal-standard-for-woocommerce' ) );
		add_action( 'woocommerce_subscription_status_cancelled', array( $this, 'cancel_paypal_subscription' ) );
	}

	/**
	 * Handle BILLING.SUBSCRIPTION.SUSPENDED webhook.
	 *
	 * @param array $resource Subscription resource.
	 */
	private function webhook_subscription_suspended( $resource ) {
		$wc_sub = $this->find_wc_subscription_by_paypal_id( isset( $resource['id'] ) ? $resource['id'] : '' );
		if ( ! $wc_sub || $wc_sub->has_status( 'on-hold' ) ) {
			return;
		}
		remove_action( 'woocommerce_subscription_status_on-hold', array( $this, 'suspend_paypal_subscription' ) );
		$wc_sub->update_status( 'on-hold', __( 'PayPal reported subscription suspended.', 'restore-paypal-standard-for-woocommerce' ) );
		add_action( 'woocommerce_subscription_status_on-hold', array( $this, 'suspend_paypal_subscription' ) );
	}

	/**
	 * Handle BILLING.SUBSCRIPTION.ACTIVATED webhook.
	 *
	 * @param array $resource Subscription resource.
	 */
	private function webhook_subscription_activated( $resource ) {
		$wc_sub = $this->find_wc_subscription_by_paypal_id( isset( $resource['id'] ) ? $resource['id'] : '' );
		if ( ! $wc_sub || $wc_sub->has_status( 'active' ) ) {
			return;
		}
		remove_action( 'woocommerce_subscription_status_active', array( $this, 'reactivate_paypal_subscription' ) );
		$wc_sub->update_status( 'active', __( 'PayPal reported subscription activated.', 'restore-paypal-standard-for-woocommerce' ) );
		add_action( 'woocommerce_subscription_status_active', array( $this, 'reactivate_paypal_subscription' ) );
	}

	/**
	 * Handle BILLING.SUBSCRIPTION.PAYMENT.FAILED webhook.
	 *
	 * @param array $resource Subscription resource.
	 */
	private function webhook_subscription_payment_failed( $resource ) {
		$wc_sub = $this->find_wc_subscription_by_paypal_id( isset( $resource['id'] ) ? $resource['id'] : '' );
		if ( ! $wc_sub ) {
			return;
		}
		$wc_sub->add_order_note( __( 'PayPal reported a renewal payment failure. PayPal will retry per its retry policy.', 'restore-paypal-standard-for-woocommerce' ) );
	}

	/**
	 * Lookup helper.
	 *
	 * Looks for a shop_subscription with our subscription-id meta. If
	 * none matches (which can happen if a webhook arrives before
	 * caches settle, or the meta wasn't set on the subscription post
	 * for some reason), falls back to looking for the parent shop_order
	 * with the same meta and returning a subscription belonging to it.
	 *
	 * @param string $paypal_subscription_id PayPal subscription id.
	 * @return WC_Subscription|false
	 */
	private function find_wc_subscription_by_paypal_id( $paypal_subscription_id ) {
		if ( ! $paypal_subscription_id ) {
			return false;
		}

		// Primary lookup: shop_subscription with the meta.
		$args = array(
			'limit'      => 1,
			'type'       => 'shop_subscription',
			'meta_key'   => self::META_SUBSCRIPTION_ID,
			'meta_value' => $paypal_subscription_id,
		);
		$results = wc_get_orders( $args );
		if ( ! empty( $results ) ) {
			return $results[0];
		}

		// Fallback 1: look up via the parent shop_order's meta (some
		// flows save the subscription id on the order before/instead
		// of on the shop_subscription).
		$args = array(
			'limit'      => 1,
			'type'       => 'shop_order',
			'meta_key'   => self::META_SUBSCRIPTION_ID,
			'meta_value' => $paypal_subscription_id,
		);
		$results = wc_get_orders( $args );
		if ( ! empty( $results ) && function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			$subs = wcs_get_subscriptions_for_order( $results[0] );
			if ( ! empty( $subs ) ) {
				$wc_sub = reset( $subs );
				// Heal the missing meta so future lookups hit the
				// primary path.
				$wc_sub->update_meta_data( self::META_SUBSCRIPTION_ID, $paypal_subscription_id );
				$wc_sub->save();
				return $wc_sub;
			}
		}

		// Fallback 2: search by transaction id on the parent shop_order.
		// The cart-express finalize path sets transaction_id to the
		// PayPal subscription id, so this catches that case even if
		// _rpsfw_ppcp_subscription_id was not stored.
		$args = array(
			'limit'          => 1,
			'type'           => 'shop_order',
			'transaction_id' => $paypal_subscription_id,
		);
		$results = wc_get_orders( $args );
		if ( ! empty( $results ) && function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			$subs = wcs_get_subscriptions_for_order( $results[0] );
			if ( ! empty( $subs ) ) {
				$wc_sub = reset( $subs );
				$wc_sub->update_meta_data( self::META_SUBSCRIPTION_ID, $paypal_subscription_id );
				$wc_sub->save();
				return $wc_sub;
			}
		}

		return false;
	}
}
