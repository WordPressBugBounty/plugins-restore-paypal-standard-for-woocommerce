<?php
/**
 * Stripe + WooCommerce Subscriptions integration (gateway-driven billing).
 *
 * Strategy: Stripe owns the schedule. We create a Stripe Customer + Price
 * + Subscription up-front, the customer authenticates the first invoice's
 * PaymentIntent with the Payment Element, and from then on Stripe issues
 * invoices, runs Smart Retries, handles SCA recovery via hosted invoice
 * URLs, and fires invoice.* + customer.subscription.* webhooks. We mirror
 * those into WooCommerce: invoice.payment_succeeded → renewal order,
 * invoice.payment_failed → notification, customer.subscription.deleted →
 * cancel WC subscription, etc.
 *
 * WC Subscriptions runs in `gateway_scheduled_payments` mode for these
 * subs so its scheduler does not double-bill.
 *
 * Loaded only when the WooCommerce Subscriptions plugin is active.
 *
 * @package RestorePayPalStandard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RPSFW_Stripe_Subscriptions
 */
class RPSFW_Stripe_Subscriptions {

	const META_CUSTOMER_ID     = '_rpsfw_stripe_customer_id';
	const META_SUBSCRIPTION_ID = '_rpsfw_stripe_subscription_id';
	const META_PRICE_ID        = '_rpsfw_stripe_price_id';
	const META_INVOICE_ID      = '_rpsfw_stripe_invoice_id';
	const META_PAYMENT_INTENT  = '_rpsfw_stripe_payment_intent_id';
	const META_PAYMENT_METHOD  = '_rpsfw_stripe_payment_method_id';
	const META_PROCESSED_INV   = '_rpsfw_stripe_processed_invoices';
	const META_SCHEDULE_ID     = '_rpsfw_stripe_schedule_id';
	const META_SUB_ITEM_ID     = '_rpsfw_stripe_subscription_item_id';
	const META_PAUSED_PRICE    = '_rpsfw_stripe_paused_price_id';

	/**
	 * The gateway instance.
	 *
	 * @var RPSFW_Gateway_Stripe
	 */
	private $gateway;

	/**
	 * Bootstrap. Returns false when WC Subscriptions is not present.
	 *
	 * @param RPSFW_Gateway_Stripe $gateway Gateway instance.
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
	 * @param RPSFW_Gateway_Stripe $gateway Gateway instance.
	 */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;

		// Subscription support flags. gateway_scheduled_payments tells WC
		// Subscriptions that Stripe owns the renewal cadence — WC's
		// scheduler will not fire the renewal hook for these subscriptions.
		// subscription_amount_changes is declared so that WCS_Limited_Recurring_Coupon_Manager
		// does not filter out this gateway when a limited-payment recurring coupon is applied.
		//
		// multiple_subscriptions IS supported: a cart with more than one
		// distinct billing schedule is created as a single Stripe subscription
		// in flexible billing mode with one item per schedule (mixed-interval
		// subscription). See build_schedules_from_cart / ajax_create_subscription.
		//
		// NOT declared (deliberately):
		//   - subscription_date_changes: next-payment dates are driven FROM
		//     Stripe via invoice webhooks; WC-side date edits would desync.
		//   - subscription_payment_method_change_admin: only the customer-facing
		//     SetupIntent change-payment flow is implemented (no admin card entry).
		$this->gateway->supports = array_merge(
			$this->gateway->supports,
			array(
				'subscriptions',
				'gateway_scheduled_payments',
				'subscription_cancellation',
				'subscription_suspension',
				'subscription_reactivation',
				'multiple_subscriptions',
				'subscription_payment_method_change_customer',
				'subscription_amount_changes',
			)
		);

		// AJAX: create a Stripe Subscription and return its first
		// invoice's PaymentIntent client secret on demand from the
		// front-end checkout.
		add_action( 'wp_ajax_rpsfw_stripe_create_subscription', array( $this, 'ajax_create_subscription' ) );
		add_action( 'wp_ajax_nopriv_rpsfw_stripe_create_subscription', array( $this, 'ajax_create_subscription' ) );

		// AJAX: create a SetupIntent for the change-payment-method flow.
		add_action( 'wp_ajax_rpsfw_stripe_create_setup_intent', array( $this, 'ajax_create_setup_intent' ) );

		// Override process_payment for subscription carts: instead of
		// confirming a one-off PaymentIntent, finalize the Stripe
		// Subscription the customer just authenticated.
		add_filter( 'rpsfw_stripe_process_payment_override', array( $this, 'maybe_override_process_payment' ), 10, 2 );

		// Finalize a subscription order after the customer confirms the first
		// invoice's PaymentIntent in the browser (deferred / order-first flow).
		add_filter( 'rpsfw_stripe_finalize_order_override', array( $this, 'maybe_finalize_order' ), 10, 2 );

		// Subscription lifecycle hooks: propagate WC actions to Stripe.
		add_action( 'woocommerce_subscription_status_cancelled', array( $this, 'cancel_stripe_subscription' ) );
		// "pending-cancel" = customer cancelled but keeps access until the paid
		// period ends. Map to Stripe's cancel_at_period_end so it stops billing
		// at period end instead of charging another cycle. (Stripe owns the
		// schedule for these subscriptions, so without this it would renew.)
		add_action( 'woocommerce_subscription_status_pending-cancel', array( $this, 'schedule_stripe_cancellation' ) );
		add_action( 'woocommerce_subscription_status_on-hold', array( $this, 'pause_stripe_subscription' ) );
		add_action( 'woocommerce_subscription_status_active', array( $this, 'resume_stripe_subscription' ) );

		// Let customers undo a pending cancellation ("Reactivate") from My
		// Account. WC Subscriptions hides that action for gateways that own the
		// schedule (gateway_scheduled_payments), leaving only "Resubscribe"
		// (which forces a new checkout). We can undo it on Stripe by clearing
		// cancel_at_period_end, so we re-enable the active transition here;
		// reactivation then runs through resume_stripe_subscription().
		add_filter( 'woocommerce_can_subscription_be_updated_to_active', array( $this, 'allow_reactivate_pending_cancel' ), 10, 2 );

		// Hide WC Subs admin actions that don't make sense for
		// gateway-driven subscriptions. "Process renewal" and "Create
		// pending renewal" trigger WC's renewal pipeline which our
		// gateway doesn't honour (Stripe owns the schedule). They would
		// silently no-op or leave a stuck pending order.
		add_filter( 'woocommerce_order_actions', array( $this, 'filter_subscription_admin_actions' ), 20, 1 );

		// When the customer changes WC subscription's payment method to a
		// different gateway, cancel the Stripe-side subscription.
		add_action( 'woocommerce_subscriptions_pre_update_payment_method', array( $this, 'on_payment_method_change_away' ), 10, 3 );

		// Stripe webhook events that signal renewals or status changes.
		// The webhook handler dispatches these dynamic actions.
		add_action( 'rpsfw_stripe_webhook_invoice_payment_succeeded', array( $this, 'webhook_invoice_payment_succeeded' ), 10, 2 );
		add_action( 'rpsfw_stripe_webhook_invoice_payment_failed', array( $this, 'webhook_invoice_payment_failed' ), 10, 2 );
		add_action( 'rpsfw_stripe_webhook_invoice_payment_action_required', array( $this, 'webhook_invoice_payment_action_required' ), 10, 2 );
		add_action( 'rpsfw_stripe_webhook_customer_subscription_deleted', array( $this, 'webhook_subscription_deleted' ), 10, 2 );
		add_action( 'rpsfw_stripe_webhook_customer_subscription_updated', array( $this, 'webhook_subscription_updated' ), 10, 2 );
		add_action( 'rpsfw_stripe_webhook_customer_subscription_paused', array( $this, 'webhook_subscription_paused' ), 10, 2 );
		add_action( 'rpsfw_stripe_webhook_customer_subscription_resumed', array( $this, 'webhook_subscription_resumed' ), 10, 2 );

		// Saved-card reuse is out of scope: hide this gateway's payment tokens
		// so WooCommerce never shows a "saved cards" list at checkout. Picking a
		// saved card there bypasses the Payment Element and leaves the
		// subscription's first invoice unpaid.
		add_filter( 'woocommerce_get_customer_payment_tokens', array( $this, 'hide_our_payment_tokens' ), 10, 3 );
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
		// to the product object at the moment of the call.
		if ( class_exists( 'WCS_ATT_Cart' ) ) {
			foreach ( WC()->cart->get_cart() as $cart_item ) {
				// get_subscription_scheme() returns null (no plan data), false
				// (a plan product the customer chose to buy ONE TIME), or a
				// non-empty scheme key (an actual subscription). Only a
				// non-empty key means this is a subscription — treating the
				// one-time (false) selection as a subscription would misroute a
				// one-off purchase into the subscription flow and fail with
				// "Could not derive subscription details from cart."
				$scheme = WCS_ATT_Cart::get_subscription_scheme( $cart_item );
				if ( ! empty( $scheme ) ) {
					return true;
				}
			}
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
	 * Build the recurring schedule + sign-up fee + trial from the cart.
	 *
	 * Recurring price comes from WC_Subscriptions_Product::get_price() (NOT
	 * cart->total — during a free-trial-with-fee cart, cart->total is just
	 * the sign-up fee, which is the wrong number for the recurring price).
	 * Sign-up fees come from get_sign_up_fee() summed across line items.
	 *
	 * Multi-line carts use the dominant interval; carts with multiple
	 * unique recurring schedules get the first one (Stripe limitation —
	 * one Subscription resource per Price recurrence).
	 *
	 * Mixed carts (subscription + non-subscription products): the
	 * non-subscription portion is collected as a one-time invoice item on
	 * the first invoice, so the customer is charged correctly today while
	 * renewals only bill the subscription price.
	 *
	 * @return array|false
	 */
	private function build_signature_from_cart() {
		if ( ! WC()->cart ) {
			return false;
		}
		WC()->cart->calculate_totals();

		$currency           = get_woocommerce_currency();
		$interval           = 'month';
		$interval_count     = 1;
		$trial_days         = 0;
		$plan_name          = sprintf( '%s plan', get_bloginfo( 'name' ) );
		$fallback_recurring = 0.0;
		$signup_fee_total   = 0.0;
		$found_subscription = false;

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

			if ( ! $found_subscription ) {
				$period   = WC_Subscriptions_Product::get_period( $product );
				$interval_value = (int) WC_Subscriptions_Product::get_interval( $product );

				$interval       = $this->wc_period_to_stripe_interval( $period );
				$interval_count = max( 1, $interval_value );

				$trial_length = (int) WC_Subscriptions_Product::get_trial_length( $product );
				if ( $trial_length > 0 ) {
					$trial_period = WC_Subscriptions_Product::get_trial_period( $product );
					$trial_days   = $this->trial_to_days( $trial_length, $trial_period );
				}

				$plan_name          = wp_strip_all_tags( $product->get_name() );
				$found_subscription = true;
			}
		}

		if ( ! $found_subscription ) {
			return false;
		}

		// Recurring amount per renewal, AFTER recurring coupons. WC
		// Subscriptions populates recurring_carts during calculate_totals
		// and applies recurring_fee / recurring_percent coupons (and removes
		// non-recurring coupons) while calculating them. We read
		// get_total('edit') — the FULL recurring total, including recurring
		// shipping, tax and fees (the "Recurring total" shown to the customer)
		// — so the Stripe Price bills exactly what WooCommerce renews instead
		// of silently dropping shipping and tax. Fall back to the contents
		// total, then the raw product price sum, if a total is unavailable.
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

		// Limited-duration recurring coupons ("Active for x payments") can't be
		// expressed by a single fixed Stripe Price (which bills the same amount
		// every cycle). We keep $recurring_amount as the DISCOUNTED per-cycle
		// figure (used for first-invoice reconciliation below, unchanged), and
		// separately compute $recurring_amount_full = the price BEFORE these
		// limited coupons. The Stripe Price is created at the full amount and
		// the discount is applied as a native Stripe coupon for the covered
		// cycles (see ajax_create_subscription). The per-cycle discount used is
		// the exact figure WooCommerce computed, so covered cycles match WC.
		// Forever/unlimited recurring coupons are NOT touched here — they stay
		// baked into $recurring_amount and thus the Price.
		$recurring_amount_full = $recurring_amount;
		$limited_coupons       = array();
		if ( isset( $recurring_cart ) && $recurring_cart && class_exists( 'WCS_Limited_Recurring_Coupon_Manager' ) ) {
			foreach ( $recurring_cart->get_applied_coupons() as $code ) {
				$limit = (int) WCS_Limited_Recurring_Coupon_Manager::get_coupon_limit( $code );
				if ( $limit < 1 ) {
					continue; // unlimited/forever coupon → leave baked into the Price
				}
				$cycle_discount = (float) $recurring_cart->get_coupon_discount_amount( $code, false ); // tax-inclusive
				if ( $cycle_discount <= 0 ) {
					continue;
				}
				$recurring_amount_full += $cycle_discount;
				$limited_coupons[]      = array(
					'code'     => $code,
					'limit'    => $limit,
					'discount' => round( $cycle_discount, 2 ),
				);
			}
		}

		// Amount due today: the FULL cart total including shipping, tax and
		// fees (the "Total" shown to the customer), AFTER all coupons and any
		// folded-in sign-up fee. We reconcile the gateway's first charge to
		// this, and the mixed-cart one-time invoice item below to the gap.
		$initial_total = (float) WC()->cart->get_total( 'edit' );

		// Non-subscription item amount: the portion of initial_total that
		// is NOT covered by the recurring subscription price + sign-up fee.
		// This happens in mixed carts. We add it as a one-time invoice item
		// on the first invoice so the customer pays the correct total today.
		// We estimate the non-sub portion as the gap between initial_total
		// and (recurring_amount + signup_fee_total) when initial_total is
		// larger. When initial_total is smaller (coupon covers part), the
		// initial_discount logic below handles the reconciliation.
		$non_subscription_amount = 0.0;
		$expected_sub_initial    = ( $trial_days > 0 ) ? (float) $signup_fee_total : (float) $recurring_amount + (float) $signup_fee_total;
		if ( $initial_total > $expected_sub_initial + 0.01 ) {
			$non_subscription_amount = round( $initial_total - $expected_sub_initial, 2 );
		}

		return array(
			'recurring_amount'        => round( $recurring_amount, 2 ),
			'recurring_amount_full'   => round( $recurring_amount_full, 2 ),
			'limited_coupons'         => $limited_coupons,
			'signup_fee'              => round( $signup_fee_total, 2 ),
			'initial_total'           => round( $initial_total, 2 ),
			'non_subscription_amount' => $non_subscription_amount,
			'currency'                => $currency,
			'interval'                => $interval,
			'interval_count'          => $interval_count,
			'trial_days'              => $trial_days,
			'plan_name'               => $plan_name,
		);
	}

	/**
	 * Build one schedule spec per distinct recurring cart (billing schedule).
	 *
	 * Used for mixed-interval carts (more than one billing schedule) which are
	 * billed as a single Stripe subscription in flexible billing mode with one
	 * item per schedule. Each spec carries the info needed to create its Stripe
	 * Price and to map it back to the matching WC_Subscription (by billing
	 * period + interval).
	 *
	 * @return array|false {
	 *   schedules[]:            per-recurring-cart specs
	 *   currency, initial_total, non_subscription_amount, signup_fee_total,
	 *   expected_sub_initial, has_limited_coupons
	 * }
	 */
	private function build_schedules_from_cart() {
		if ( ! WC()->cart ) {
			return false;
		}
		WC()->cart->calculate_totals();

		if ( empty( WC()->cart->recurring_carts ) || ! class_exists( 'WC_Subscriptions_Product' ) ) {
			return false;
		}

		$currency          = get_woocommerce_currency();
		$schedules         = array();
		$signup_fee_total  = 0.0;
		$has_limited       = false;

		foreach ( WC()->cart->recurring_carts as $key => $rcart ) {
			$interval       = 'month';
			$interval_count = 1;
			$period         = 'month';
			$trial_days     = 0;
			$plan_name      = sprintf( '%s plan', get_bloginfo( 'name' ) );
			$signup_fee     = 0.0;
			$found          = false;

			foreach ( $rcart->get_cart() as $item ) {
				$product = $item['data'];
				if ( ! WC_Subscriptions_Product::is_subscription( $product ) ) {
					continue;
				}
				$qty         = isset( $item['quantity'] ) ? (int) $item['quantity'] : 1;
				$signup_fee += (float) WC_Subscriptions_Product::get_sign_up_fee( $product ) * max( 1, $qty );
				if ( ! $found ) {
					$period         = WC_Subscriptions_Product::get_period( $product );
					$interval_count = max( 1, (int) WC_Subscriptions_Product::get_interval( $product ) );
					$interval       = $this->wc_period_to_stripe_interval( $period );
					$trial_length   = (int) WC_Subscriptions_Product::get_trial_length( $product );
					if ( $trial_length > 0 ) {
						$trial_days = $this->trial_to_days( $trial_length, WC_Subscriptions_Product::get_trial_period( $product ) );
					}
					$plan_name = wp_strip_all_tags( $product->get_name() );
					$found     = true;
				}
			}
			if ( ! $found ) {
				continue;
			}

			$recurring_amount = (float) $rcart->get_total( 'edit' );
			if ( $recurring_amount <= 0 ) {
				$recurring_amount = (float) $rcart->get_cart_contents_total();
			}

			// Per-schedule limited-duration recurring coupons.
			$recurring_amount_full = $recurring_amount;
			$limited_coupons       = array();
			if ( class_exists( 'WCS_Limited_Recurring_Coupon_Manager' ) ) {
				foreach ( $rcart->get_applied_coupons() as $code ) {
					$limit = (int) WCS_Limited_Recurring_Coupon_Manager::get_coupon_limit( $code );
					if ( $limit < 1 ) {
						continue;
					}
					$cycle_discount = (float) $rcart->get_coupon_discount_amount( $code, false );
					if ( $cycle_discount <= 0 ) {
						continue;
					}
					$recurring_amount_full += $cycle_discount;
					$limited_coupons[]      = array(
						'code'     => $code,
						'limit'    => $limit,
						'discount' => round( $cycle_discount, 2 ),
					);
					$has_limited = true;
				}
			}

			$signup_fee_total += $signup_fee;
			$schedules[]       = array(
				'recurring_cart_key'    => $key,
				'recurring_amount'      => round( $recurring_amount, 2 ),
				'recurring_amount_full' => round( $recurring_amount_full, 2 ),
				'limited_coupons'       => $limited_coupons,
				'signup_fee'            => round( $signup_fee, 2 ),
				'trial_days'            => $trial_days,
				'interval'              => $interval,
				'interval_count'        => $interval_count,
				'period'                => $period,
				'plan_name'             => $plan_name,
			);
		}

		if ( empty( $schedules ) ) {
			return false;
		}

		$initial_total        = (float) WC()->cart->get_total( 'edit' );
		$expected_sub_initial = 0.0;
		foreach ( $schedules as $s ) {
			$expected_sub_initial += ( $s['trial_days'] > 0 ? 0.0 : $s['recurring_amount'] ) + $s['signup_fee'];
		}
		$non_subscription_amount = 0.0;
		if ( $initial_total > $expected_sub_initial + 0.01 ) {
			$non_subscription_amount = round( $initial_total - $expected_sub_initial, 2 );
		}

		return array(
			'schedules'               => $schedules,
			'currency'                => $currency,
			'initial_total'           => round( $initial_total, 2 ),
			'non_subscription_amount' => $non_subscription_amount,
			'signup_fee_total'        => round( $signup_fee_total, 2 ),
			'expected_sub_initial'    => round( $expected_sub_initial, 2 ),
			'has_limited_coupons'     => $has_limited,
		);
	}

	/**
	 * Map WC Subscriptions period to Stripe price.recurring.interval.
	 *
	 * @param string $period day|week|month|year.
	 * @return string
	 */
	private function wc_period_to_stripe_interval( $period ) {
		switch ( strtolower( (string) $period ) ) {
			case 'day':
				return 'day';
			case 'week':
				return 'week';
			case 'year':
				return 'year';
			case 'month':
			default:
				return 'month';
		}
	}

	/**
	 * Convert a WC trial length + period into a number of days for
	 * Stripe's trial_period_days.
	 *
	 * @param int    $length Trial length.
	 * @param string $period day|week|month|year.
	 * @return int
	 */
	private function trial_to_days( $length, $period ) {
		$length = max( 0, (int) $length );
		switch ( strtolower( (string) $period ) ) {
			case 'week':
				return $length * 7;
			case 'month':
				return $length * 30;
			case 'year':
				return $length * 365;
			case 'day':
			default:
				return $length;
		}
	}

	/**
	 * Build the billing/shipping array for get_or_create_customer from the
	 * current cart customer.
	 *
	 * @return array
	 */
	private function build_customer_billing() {
		$customer     = WC()->customer;
		$ship_addr1   = $customer ? $customer->get_shipping_address_1() : '';
		$ship_country = $customer ? $customer->get_shipping_country() : '';
		$use_shipping = ( '' !== $ship_addr1 && '' !== $ship_country );

		$billing = array(
			'email' => $customer ? $customer->get_billing_email() : '',
			'name'  => $customer ? trim( $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() ) : '',
			'phone' => $customer ? $customer->get_billing_phone() : '',
		);
		if ( $customer ) {
			$billing['shipping'] = $use_shipping
				? array(
					'name'      => trim( $customer->get_shipping_first_name() . ' ' . $customer->get_shipping_last_name() ),
					'address_1' => $ship_addr1,
					'address_2' => $customer->get_shipping_address_2(),
					'city'      => $customer->get_shipping_city(),
					'state'     => $customer->get_shipping_state(),
					'postcode'  => $customer->get_shipping_postcode(),
					'country'   => $ship_country,
				)
				: array(
					'name'      => trim( $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() ),
					'address_1' => $customer->get_billing_address_1(),
					'address_2' => $customer->get_billing_address_2(),
					'city'      => $customer->get_billing_city(),
					'state'     => $customer->get_billing_state(),
					'postcode'  => $customer->get_billing_postcode(),
					'country'   => $customer->get_billing_country(),
				);
		}
		return $billing;
	}

	/**
	 * Multi-schedule carts: authenticate the card ONCE with a SetupIntent, then
	 * defer creating the separate subscriptions to process_payment.
	 *
	 * Each distinct billing schedule becomes its own independent Stripe
	 * subscription (created off-session in finalize_independent_subscriptions()
	 * against the payment method authenticated here). This is the documented
	 * Stripe pattern for many subscriptions from one checkout — collect + verify
	 * the card with a SetupIntent, then create each subscription with that
	 * payment method. We only need to authenticate here; the subscriptions are
	 * created after the order is placed so we never leave orphaned incomplete
	 * subscriptions if the buyer abandons.
	 *
	 * Sends the JSON response itself; throws on error (caught by the caller).
	 *
	 * @throws Exception
	 */
	private function create_independent_subscriptions_setup() {
		$data = $this->build_schedules_from_cart();
		if ( ! $data || empty( $data['schedules'] ) ) {
			throw new Exception( esc_html__( 'Could not derive subscription details from cart.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		// Cancel any stale single-schedule draft subscription left over from an
		// earlier request (e.g. the cart changed from one schedule to several).
		if ( WC()->session ) {
			$stale = WC()->session->get( 'rpsfw_stripe_pending_subscription_id' );
			if ( $stale ) {
				RPSFW_Stripe_API::cancel_subscription( $stale );
				WC()->session->__unset( 'rpsfw_stripe_pending_subscription_id' );
			}
		}

		$customer_id = RPSFW_Stripe_API::get_or_create_customer( get_current_user_id(), $this->build_customer_billing() );
		if ( is_wp_error( $customer_id ) ) {
			throw new Exception( esc_html( $customer_id->get_error_message() ) );
		}

		// Authenticate the card once. usage=off_session is implied by the
		// subscription use-case; create_setup_intent already sets it. Its
		// card-only default is overridden here so Link is offered — the
		// subscriptions created from this intent already accept it.
		$si = RPSFW_Stripe_API::create_setup_intent( $customer_id, array(
			'payment_method_types' => RPSFW_Stripe_API::subscription_payment_method_types(),
			'metadata'             => array_merge(
				array(
					'rpsfw_independent_subscriptions' => '1',
					'site_url'                        => get_site_url(),
				),
				RPSFW_Stripe_API::get_relay_metadata()
			),
		) );
		if ( is_wp_error( $si ) ) {
			throw new Exception( esc_html( $si->get_error_message() ) );
		}

		if ( WC()->session ) {
			WC()->session->set( 'rpsfw_stripe_pending_customer_id', $customer_id );
			WC()->session->set( 'rpsfw_stripe_pending_independent_schedules', $data );
			WC()->session->set( 'rpsfw_stripe_pending_setup_intent_id', $si->id );
			// This flow does not use the single-schedule / mixed-interval keys.
			WC()->session->__unset( 'rpsfw_stripe_pending_schedule' );
			WC()->session->__unset( 'rpsfw_stripe_pending_schedule_map' );
		}

		wp_send_json_success( array(
			'client_secret'   => $si->client_secret,
			'setup_intent_id' => $si->id,
			'customer_id'     => $customer_id,
			'mode'            => 'setup',
		) );
	}

	/**
	 * Finalize the independent (multi-schedule) subscriptions after the buyer
	 * has authenticated their card via the SetupIntent. Creates one separate
	 * Stripe subscription per schedule, off-session, against the authenticated
	 * payment method, then maps each to its WooCommerce subscription.
	 *
	 * @param WC_Order $order Parent order.
	 * @return array process_payment result.
	 * @throws Exception
	 */
	private function finalize_independent_subscriptions( $order ) {
		$data        = WC()->session ? WC()->session->get( 'rpsfw_stripe_pending_independent_schedules' ) : null;
		$customer_id = WC()->session ? WC()->session->get( 'rpsfw_stripe_pending_customer_id' ) : '';
		if ( empty( $data ) || empty( $data['schedules'] ) ) {
			throw new Exception( esc_html__( 'Could not derive subscription details from cart.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		// Resolve the authenticated payment method from the SetupIntent.
		$setup_intent_id = ! empty( $_POST['rpsfw_stripe_setup_intent_id'] )
			? sanitize_text_field( wp_unslash( $_POST['rpsfw_stripe_setup_intent_id'] ) )
			: ( WC()->session ? WC()->session->get( 'rpsfw_stripe_pending_setup_intent_id' ) : '' );
		if ( empty( $setup_intent_id ) ) {
			throw new Exception( esc_html__( 'Card was not authenticated. Please try again.', 'restore-paypal-standard-for-woocommerce' ) );
		}
		$si = RPSFW_Stripe_API::retrieve_setup_intent( $setup_intent_id );
		if ( is_wp_error( $si ) ) {
			throw new Exception( esc_html( $si->get_error_message() ) );
		}
		$payment_method_id = ! empty( $si->payment_method )
			? ( is_object( $si->payment_method ) ? $si->payment_method->id : (string) $si->payment_method )
			: '';
		if ( empty( $payment_method_id ) ) {
			throw new Exception( esc_html__( 'No payment method was saved during authentication. Please try again.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		// Cart-wide first-charge reconciliation: put any initial (non-recurring)
		// discount on the first subscription's invoice so the aggregate "due
		// today" across all subscriptions matches WooCommerce.
		$non_sub          = (float) $data['non_subscription_amount'];
		$initial_discount = round( (float) $data['expected_sub_initial'] - ( (float) $data['initial_total'] - $non_sub ), 2 );
		if ( $initial_discount < 0 ) {
			$initial_discount = 0.0;
		}

		$created  = array(); // [ [ 'sub' => obj, 'spec' => schedule, 'price_id' => id ], ... ]
		$is_first = true;
		try {
			foreach ( $data['schedules'] as $s ) {
				$price_id = RPSFW_Stripe_API::find_or_create_price( array(
					'amount'         => $s['recurring_amount'],
					'currency'       => $data['currency'],
					'interval'       => $s['interval'],
					'interval_count' => $s['interval_count'],
					'product_name'   => $s['plan_name'],
				) );
				if ( is_wp_error( $price_id ) ) {
					throw new Exception( $price_id->get_error_message() );
				}

				$sub_args = array(
					'customer'               => $customer_id,
					'price_id'               => $price_id,
					'trial_days'             => $s['trial_days'],
					'signup_fee'             => $s['signup_fee'],
					'signup_currency'        => $data['currency'],
					'signup_label'           => sprintf(
						/* translators: %s: subscription product name */
						__( 'Sign-up fee for %s', 'restore-paypal-standard-for-woocommerce' ),
						$s['plan_name']
					),
					// Charge the first invoice immediately using the
					// already-authenticated card; fail loudly if it can't be.
					'default_payment_method' => $payment_method_id,
					'off_session'            => true,
					'payment_behavior'       => 'error_if_incomplete',
					'metadata'               => array(
						'site_url'                        => get_site_url(),
						'rpsfw_independent_subscriptions' => '1',
					),
				);

				// One-time (non-subscription) products + the cart-wide initial
				// discount ride on the FIRST subscription's first invoice only.
				if ( $is_first ) {
					$sub_args['non_subscription_amount'] = $non_sub;
					$sub_args['non_subscription_label']  = __( 'One-time product(s)', 'restore-paypal-standard-for-woocommerce' );
					$sub_args['initial_discount']        = $initial_discount;
					$is_first                            = false;
				}

				$sub = RPSFW_Stripe_API::create_subscription( $sub_args );
				if ( is_wp_error( $sub ) ) {
					throw new Exception( $sub->get_error_message() );
				}
				$created[] = array( 'sub' => $sub, 'spec' => $s, 'price_id' => $price_id );
			}
		} catch ( Exception $e ) {
			// Roll back any subscriptions already created so the buyer is not
			// left paying for a partial order.
			foreach ( $created as $c ) {
				if ( ! empty( $c['sub']->id ) ) {
					RPSFW_Stripe_API::cancel_subscription( $c['sub']->id );
				}
			}
			throw $e;
		}

		// Map each WC subscription (WCS creates one per recurring cart) to the
		// Stripe subscription with the matching billing period + interval.
		$wc_subs   = wcs_get_subscriptions_for_order( $order );
		$used_subs = array();
		$primary   = reset( $created ); // fallback / for the parent order transaction id

		foreach ( $wc_subs as $wc_sub ) {
			$wc_period   = $wc_sub->get_billing_period();
			$wc_interval = (int) $wc_sub->get_billing_interval();

			$match = null;
			foreach ( $created as $idx => $c ) {
				if ( isset( $used_subs[ $idx ] ) ) {
					continue;
				}
				if ( $c['spec']['period'] === $wc_period && (int) $c['spec']['interval_count'] === $wc_interval ) {
					$match          = $c;
					$used_subs[ $idx ] = true;
					break;
				}
			}
			if ( ! $match ) {
				continue; // no matching schedule (shouldn't happen); leave unmapped
			}

			$stripe_sub = $match['sub'];
			$wc_sub->update_meta_data( self::META_SUBSCRIPTION_ID, $stripe_sub->id );
			$wc_sub->update_meta_data( self::META_CUSTOMER_ID, $customer_id );
			$wc_sub->update_meta_data( self::META_PRICE_ID, $match['price_id'] );
			$wc_sub->update_meta_data( self::META_PAYMENT_METHOD, $payment_method_id );

			// Record the initial invoice's PaymentIntent + charge on this WC
			// subscription so Stripe-side refund/dispute events (which carry the
			// charge + payment_intent, not the subscription id) can resolve back
			// to this subscription's parent order. Each independent subscription
			// has its own charge under the single parent order.
			$initial_invoice_id = isset( $stripe_sub->latest_invoice )
				? ( is_object( $stripe_sub->latest_invoice ) ? $stripe_sub->latest_invoice->id : (string) $stripe_sub->latest_invoice )
				: '';
			if ( $initial_invoice_id ) {
				$refs = RPSFW_Stripe_API::get_invoice_payment_refs( $initial_invoice_id );
				if ( ! empty( $refs['payment_intent'] ) ) {
					$wc_sub->update_meta_data( self::META_PAYMENT_INTENT, $refs['payment_intent'] );
					// Primary resolution path: stamp the parent order id on the
					// PaymentIntent so refund/dispute webhooks resolve directly.
					RPSFW_Stripe_API::stamp_order_on_payment_intent( $refs['payment_intent'], $order );
				}
				if ( ! empty( $refs['charge'] ) ) {
					$wc_sub->update_meta_data( '_rpsfw_stripe_charge_id', $refs['charge'] );
				}
			}

			$wc_sub->set_payment_method( $this->gateway->id );
			$wc_sub->set_payment_method_title( $this->gateway->get_title() );
			$wc_sub->save();

			// If this schedule carries a limited-duration recurring coupon, wrap
			// its subscription in a price-step Subscription Schedule (per-sub).
			if ( ! empty( $match['spec']['limited_coupons'] ) ) {
				$this->apply_limited_coupon_schedule(
					$stripe_sub->id,
					array(
						'recurring_amount'      => $match['spec']['recurring_amount'],
						'recurring_amount_full' => $match['spec']['recurring_amount_full'],
						'limited_coupons'       => $match['spec']['limited_coupons'],
						'interval'              => $match['spec']['interval'],
						'interval_count'        => $match['spec']['interval_count'],
						'currency'              => $data['currency'],
						'plan_name'             => $match['spec']['plan_name'],
						'discounted_price_id'   => $match['price_id'],
					),
					$order,
					$wc_sub
				);
			}
		}

		// Parent order: record the primary subscription + customer, mark paid.
		if ( $primary && ! empty( $primary['sub']->id ) ) {
			$order->update_meta_data( self::META_SUBSCRIPTION_ID, $primary['sub']->id );
			$order->set_transaction_id( $primary['sub']->id );
		}
		$order->update_meta_data( self::META_CUSTOMER_ID, $customer_id );
		$order->update_meta_data( self::META_PAYMENT_METHOD, $payment_method_id );
		$order->save();

		// One-time sync of the Stripe customer's name/email/phone from the
		// now-final order billing details — see sync_customer_from_order().
		$this->sync_customer_from_order( $customer_id, $order );

		$sub_ids = array();
		foreach ( $created as $c ) {
			if ( ! empty( $c['sub']->id ) ) {
				$sub_ids[] = $c['sub']->id;
			}
		}
		// Re-check the stored status: a webhook for one of the subscriptions
		// created above may already have completed this order.
		if ( rpsfw_order_still_needs_payment( $order ) ) {
			$order->payment_complete( $primary && ! empty( $primary['sub']->id ) ? $primary['sub']->id : '' );
			$order->add_order_note( sprintf(
				/* translators: %s: comma-separated Stripe subscription ids */
				__( 'Created %1$d independent Stripe subscriptions: %2$s.', 'restore-paypal-standard-for-woocommerce' ),
				count( $sub_ids ),
				implode( ', ', $sub_ids )
			) );
		} else {
			RPSFW_Gateway_Stripe::log( 'Order #' . $order->get_id() . ' was already completed (webhook) — skipping duplicate payment_complete for ' . implode( ', ', $sub_ids ) . '.' );
		}

		// Save the card as a WC payment token for My Account.
		if ( $order->get_customer_id() && $customer_id ) {
			$this->store_payment_token( $order->get_customer_id(), $payment_method_id, $customer_id );
		}

		// Clean up session.
		if ( WC()->session ) {
			WC()->session->__unset( 'rpsfw_stripe_pending_independent_schedules' );
			WC()->session->__unset( 'rpsfw_stripe_pending_customer_id' );
			WC()->session->__unset( 'rpsfw_stripe_pending_setup_intent_id' );
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->gateway->get_return_url( $order ),
		);
	}

	/**
	 * AJAX: create a Stripe Subscription and return its first invoice's
	 * PaymentIntent client secret so the front end can confirm the
	 * authentication with the Payment Element.
	 */
	public function ajax_create_subscription() {
		check_ajax_referer( 'rpsfw-stripe-create-subscription', 'nonce' );

		try {
			if ( ! WC()->cart || WC()->cart->is_empty() ) {
				throw new Exception( __( 'Your cart is empty.', 'restore-paypal-standard-for-woocommerce' ) );
			}
			if ( ! self::cart_contains_subscription() ) {
				throw new Exception( __( 'No subscription in cart.', 'restore-paypal-standard-for-woocommerce' ) );
			}

			// Populate WC()->cart->recurring_carts BEFORE the routing decision
			// below. WooCommerce Subscriptions builds recurring_carts during
			// calculate_totals; in this AJAX request that hasn't run yet, so
			// without this the count is 0/1 and a genuine multi-schedule cart
			// gets misrouted into the single-schedule flow — creating only one
			// Stripe subscription and leaving the other WC subscription with no
			// Stripe backing (it would never bill).
			WC()->cart->calculate_totals();

			// Multi-schedule carts (more than one distinct billing schedule,
			// e.g. a daily + a weekly product) are created as SEPARATE,
			// independent Stripe subscriptions — one per schedule — so each is
			// its own object on Stripe and can be paused, cancelled and refunded
			// individually. We authenticate the card once with a SetupIntent
			// here; the subscriptions themselves are created off-session in
			// process_payment. The single-schedule flow below is unchanged.
			if ( is_array( WC()->cart->recurring_carts ) && count( WC()->cart->recurring_carts ) > 1 ) {
				$this->create_independent_subscriptions_setup();
				return;
			}

			$signature = $this->build_signature_from_cart();
			if ( ! $signature ) {
				throw new Exception( __( 'Could not derive subscription details from cart.', 'restore-paypal-standard-for-woocommerce' ) );
			}

			// Reuse an existing pending subscription when the cart is
			// unchanged, instead of cancelling + recreating on every checkout
			// refresh. The front end re-requests this endpoint on each
			// updated_checkout; recreating each time raced (WooCommerce persists
			// session changes only at request shutdown, so near-simultaneous
			// refresh calls each created their own subscription), leaving
			// orphaned incomplete subscriptions and a session pointing at the
			// wrong one — which then made the order finalize against an
			// unconfirmed subscription ("Your payment was not completed").
			// Reusing keeps a single stable subscription across refreshes and
			// only recreates when the cart (signature) actually changes.
			$signature_hash = md5( (string) wp_json_encode( $signature ) );

			// Reuse or replace the existing draft subscription WITHOUT relying on
			// the WooCommerce session. The block checkout re-requests this
			// endpoint whenever the cart total changes (e.g. shipping is
			// recalculated after the address is entered). The guest session
			// pointer is not reliably shared across those admin-ajax calls, so
			// depending on it spawned a brand-new Stripe subscription (and, for
			// guests, a new Stripe customer) on every recalculation — leaving
			// orphaned 'incomplete' subscriptions. Instead the front end sends
			// back the subscription/customer it already holds, and we stamp the
			// cart signature onto the subscription's Stripe metadata so we can
			// decide deterministically: same cart -> reuse; changed cart ->
			// cancel the draft and create a fresh one on the SAME customer.
			$posted_existing_sub = isset( $_POST['existing_subscription_id'] ) ? sanitize_text_field( wp_unslash( $_POST['existing_subscription_id'] ) ) : '';
			$posted_existing_cus = isset( $_POST['existing_customer_id'] ) ? sanitize_text_field( wp_unslash( $_POST['existing_customer_id'] ) ) : '';

			$session_sub = WC()->session ? (string) WC()->session->get( 'rpsfw_stripe_pending_subscription_id' ) : '';
			$session_cus = WC()->session ? (string) WC()->session->get( 'rpsfw_stripe_pending_customer_id' ) : '';

			// Prefer the client-provided ids (session-independent); fall back to
			// whatever the session still holds.
			$candidate_sub = ( $posted_existing_sub && 0 === strpos( $posted_existing_sub, 'sub_' ) ) ? $posted_existing_sub : $session_sub;
			$reuse_customer_id = ( $posted_existing_cus && 0 === strpos( $posted_existing_cus, 'cus_' ) ) ? $posted_existing_cus : ( ( $session_cus && 0 === strpos( $session_cus, 'cus_' ) ) ? $session_cus : '' );

			if ( $candidate_sub ) {
				$existing = RPSFW_Stripe_API::retrieve_subscription( $candidate_sub, array(
					'expand' => array( 'latest_invoice.confirmation_secret', 'pending_setup_intent' ),
				) );
				if ( ! is_wp_error( $existing ) ) {
					$existing_sig = ( isset( $existing->metadata ) && isset( $existing->metadata->rpsfw_cart_signature ) )
						? (string) $existing->metadata->rpsfw_cart_signature
						: '';
					$existing_cus = is_object( $existing->customer ) ? $existing->customer->id : (string) $existing->customer;

					// Same cart, still awaiting authentication: reuse it.
					if ( in_array( $existing->status, array( 'incomplete', 'trialing' ), true ) && '' !== $existing_sig && $existing_sig === $signature_hash ) {
						RPSFW_Gateway_Stripe::log( 'Stripe subscriptions: reusing draft subscription ' . $candidate_sub . ' (cart unchanged).' );
						if ( WC()->session ) {
							WC()->session->set( 'rpsfw_stripe_pending_subscription_id', $existing->id );
							WC()->session->set( 'rpsfw_stripe_pending_customer_id', $existing_cus );
							WC()->session->set( 'rpsfw_stripe_pending_signature', $signature_hash );
						}
						$this->send_subscription_intent_response( $existing, $existing_cus );
						return; // send_subscription_intent_response() exits.
					}

					// Cart changed (or draft otherwise unusable): cancel the stale
					// incomplete draft so it never lingers as an orphan, and reuse
					// its customer for the replacement to avoid duplicate customers.
					if ( 'incomplete' === $existing->status ) {
						RPSFW_Gateway_Stripe::log( 'Stripe subscriptions: cart changed — cancelling stale draft subscription ' . $candidate_sub . ' and creating a replacement.' );
						RPSFW_Stripe_API::cancel_subscription( $candidate_sub );
					}
					if ( '' === $reuse_customer_id && $existing_cus && 0 === strpos( $existing_cus, 'cus_' ) ) {
						$reuse_customer_id = $existing_cus;
					}
				} else {
					RPSFW_Gateway_Stripe::log( 'Stripe subscriptions: could not retrieve prior draft ' . $candidate_sub . ' (' . $existing->get_error_message() . '); creating a new one.', 'warning' );
				}
			}

			// Clear any stale session pointer; it is re-set after the (re)create.
			if ( WC()->session ) {
				WC()->session->__unset( 'rpsfw_stripe_pending_subscription_id' );
				WC()->session->__unset( 'rpsfw_stripe_pending_signature' );
			}

			// Get / create Stripe customer for the buyer.
			$customer = WC()->customer;
			$ship_addr1   = $customer ? $customer->get_shipping_address_1() : '';
			$ship_country = $customer ? $customer->get_shipping_country() : '';
			$use_shipping = ( '' !== $ship_addr1 && '' !== $ship_country );
			$billing  = array(
				'email' => $customer ? $customer->get_billing_email() : '',
				'name'  => $customer ? trim( $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() ) : '',
				'phone' => $customer ? $customer->get_billing_phone() : '',
			);
			// Pass the shipping address (falling back to billing) so it is
			// recorded on the Stripe customer/subscription for fulfillment.
			if ( $customer ) {
				$billing['shipping'] = $use_shipping
					? array(
						'name'      => trim( $customer->get_shipping_first_name() . ' ' . $customer->get_shipping_last_name() ),
						'address_1' => $ship_addr1,
						'address_2' => $customer->get_shipping_address_2(),
						'city'      => $customer->get_shipping_city(),
						'state'     => $customer->get_shipping_state(),
						'postcode'  => $customer->get_shipping_postcode(),
						'country'   => $ship_country,
					)
					: array(
						'name'      => trim( $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() ),
						'address_1' => $customer->get_billing_address_1(),
						'address_2' => $customer->get_billing_address_2(),
						'city'      => $customer->get_billing_city(),
						'state'     => $customer->get_billing_state(),
						'postcode'  => $customer->get_billing_postcode(),
						'country'   => $customer->get_billing_country(),
					);
			}

			if ( ! get_current_user_id() && $reuse_customer_id ) {
				// Guest re-request: reuse the Stripe customer created on the first
				// pass so a duplicate customer isn't spawned per recalculation.
				// (Logged-in users always resolve to the same customer via user
				// meta below, so they don't need this.)
				$customer_id = $reuse_customer_id;
			} else {
				$customer_id = RPSFW_Stripe_API::get_or_create_customer( get_current_user_id(), $billing );
				if ( is_wp_error( $customer_id ) ) {
					throw new Exception( $customer_id->get_error_message() );
				}
			}

			// Find / create reusable Price for this recurring schedule. This is
			// the amount charged today and for covered cycles (it already has
			// all recurring coupons applied, including limited-duration ones).
			// When a limited-duration recurring coupon is present, we ALSO wrap
			// the subscription in a Stripe Subscription Schedule after payment
			// (see maybe_override_process_payment / apply_limited_coupon_schedule)
			// that steps the price back up to full after the covered cycles —
			// fully Stripe-managed, no coupons, no webhooks.
			$price_id = RPSFW_Stripe_API::find_or_create_price( array(
				'amount'         => $signature['recurring_amount'],
				'currency'       => $signature['currency'],
				'interval'       => $signature['interval'],
				'interval_count' => $signature['interval_count'],
				'product_name'   => $signature['plan_name'],
			) );
			if ( is_wp_error( $price_id ) ) {
				throw new Exception( $price_id->get_error_message() );
			}

			// If there are limited-duration recurring coupons, remember the data
			// needed to build the price-step Subscription Schedule once payment
			// is confirmed (in maybe_override_process_payment). Only paid, active
			// subscriptions get scheduled, avoiding orphan schedules.
			if ( WC()->session ) {
				if ( ! empty( $signature['limited_coupons'] ) ) {
					WC()->session->set( 'rpsfw_stripe_pending_schedule', array(
						'recurring_amount'      => $signature['recurring_amount'],
						'recurring_amount_full' => $signature['recurring_amount_full'],
						'limited_coupons'       => $signature['limited_coupons'],
						'interval'              => $signature['interval'],
						'interval_count'        => $signature['interval_count'],
						'currency'              => $signature['currency'],
						'plan_name'             => $signature['plan_name'],
						'discounted_price_id'   => $price_id,
					) );
				} else {
					WC()->session->__unset( 'rpsfw_stripe_pending_schedule' );
				}
			}

			// Reconcile the first Stripe invoice to WooCommerce's "due
			// today" total. Stripe's first invoice would otherwise be
			// (recurring price + sign-up fee), or just the sign-up fee when
			// there is a free trial. WooCommerce may charge less today
			// because of initial-cart or sign-up-fee coupons. Apply a
			// one-time discount for the difference so the customer is
			// charged exactly what WooCommerce shows, while renewals keep
			// billing the recurring price.
			//
			// For mixed carts (subscription + non-subscription items),
			// non_subscription_amount is the one-time portion above the
			// expected subscription initial amount. We add it as a
			// one-time invoice item rather than treating it as a discount.
			$non_subscription_amount = isset( $signature['non_subscription_amount'] ) ? (float) $signature['non_subscription_amount'] : 0.0;

			$expected_first = ( $signature['trial_days'] > 0 ) ? 0.0 : (float) $signature['recurring_amount'];
			$expected_first += (float) $signature['signup_fee'];
			// When there is a non-subscription portion, it's already
			// handled via its own invoice item, so we don't count it
			// in the expected_first for discount calculation.
			$initial_discount = round( $expected_first - ( (float) $signature['initial_total'] - $non_subscription_amount ), 2 );
			if ( $initial_discount < 0 ) {
				$initial_discount = 0.0;
			}

			// Create the subscription. payment_behavior=default_incomplete
			// gives us a PaymentIntent on the first invoice that the
			// customer must authenticate with the Payment Element.
			$sub = RPSFW_Stripe_API::create_subscription( array(
				'customer'                   => $customer_id,
				'price_id'                   => $price_id,
				'trial_days'                 => $signature['trial_days'],
				'signup_fee'                 => $signature['signup_fee'],
				'signup_currency'            => $signature['currency'],
				'signup_label'               => sprintf(
					/* translators: %s: subscription product name */
					__( 'Sign-up fee for %s', 'restore-paypal-standard-for-woocommerce' ),
					$signature['plan_name']
				),
				'initial_discount'           => $initial_discount,
				'non_subscription_amount'    => $non_subscription_amount,
				'non_subscription_label'     => __( 'One-time product(s)', 'restore-paypal-standard-for-woocommerce' ),
				'metadata'                   => array_merge(
					array(
						'site_url' => get_site_url(),
						// Cart signature so a later re-request can decide
						// deterministically whether to reuse this draft (same
						// cart) or cancel + replace it (cart changed) — without
						// depending on the flaky WooCommerce guest session.
						'rpsfw_cart_signature' => $signature_hash,
					),
					// Same line-item metadata as the one-off flow (items with
					// unit price, count, shipping, tax, discount, coupons) so
					// both surfaces carry a consistent breakdown. Stripe plans
					// have no per-line breakdown, so this lives in metadata.
					RPSFW_Stripe_API::build_line_item_metadata( WC()->cart, null )
				),
			) );

			if ( is_wp_error( $sub ) ) {
				throw new Exception( $sub->get_error_message() );
			}

			// Stash on session so process_payment can finalize, and record the
			// cart signature so a subsequent refresh reuses this subscription
			// instead of creating another one.
			WC()->session->set( 'rpsfw_stripe_pending_subscription_id', $sub->id );
			WC()->session->set( 'rpsfw_stripe_pending_customer_id', $customer_id );
			WC()->session->set( 'rpsfw_stripe_pending_price_id', $price_id );
			WC()->session->set( 'rpsfw_stripe_pending_signature', $signature_hash );

			$this->send_subscription_intent_response( $sub, $customer_id );
		} catch ( Exception $e ) {
			RPSFW_Gateway_Stripe::log( 'Stripe subscriptions ajax error: ' . $e->getMessage(), 'error' );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Build and send the AJAX response carrying the client secret the Payment
	 * Element needs to authenticate the subscription's first payment.
	 *
	 * Shared by the create path and the reuse path so both return an identical
	 * shape. Exits via wp_send_json_success().
	 *
	 * @param \Stripe\Subscription $sub         Subscription (with latest_invoice.confirmation_secret + pending_setup_intent expanded).
	 * @param string               $customer_id Stripe customer id.
	 * @throws Exception When a fallback SetupIntent cannot be created.
	 */
	private function send_subscription_intent_response( $sub, $customer_id ) {
		$response = array(
			'subscription_id' => $sub->id,
			'customer_id'     => $customer_id,
		);

		// If there is a PaymentIntent on the first invoice, return its client
		// secret so the front end can confirm. If the first invoice is $0 (free
		// trial, no signup fee, or fully discounted first period) Stripe returns
		// a pending_setup_intent instead — we use that to collect the card
		// without charging. Fallback: if neither is available (invoice auto-paid
		// at $0 with no setup intent), create a SetupIntent so the customer can
		// still authenticate their card for future renewals.
		$invoice_client_secret = RPSFW_Stripe_API::get_first_invoice_client_secret( $sub );
		if ( ! empty( $invoice_client_secret ) ) {
			$response['client_secret']     = $invoice_client_secret;
			// The PaymentIntent id is not exposed on the invoice in modern API
			// versions; the front end sends it back after confirmation.
			$response['payment_intent_id'] = '';
			$response['mode']              = 'payment';
		} elseif ( ! empty( $sub->pending_setup_intent ) ) {
			$response['client_secret']   = $sub->pending_setup_intent->client_secret;
			$response['setup_intent_id'] = $sub->pending_setup_intent->id;
			$response['mode']            = 'setup';
		} else {
			RPSFW_Gateway_Stripe::log( 'No invoice payment intent or pending setup intent - creating fallback SetupIntent for fully-discounted first period.' );
			$si = RPSFW_Stripe_API::create_setup_intent( $customer_id, array(
				// Subscription checkout, so override the card-only default and
				// offer what the subscription accepts (card + Link when enabled).
				'payment_method_types' => RPSFW_Stripe_API::subscription_payment_method_types(),
				'metadata'             => array_merge(
					array(
						'stripe_subscription_id' => $sub->id,
						'site_url'               => get_site_url(),
					),
					RPSFW_Stripe_API::get_relay_metadata()
				),
			) );
			if ( is_wp_error( $si ) ) {
				throw new Exception( esc_html( $si->get_error_message() ) );
			}
			$response['client_secret']   = $si->client_secret;
			$response['setup_intent_id'] = $si->id;
			$response['mode']            = 'setup';
		}

		wp_send_json_success( $response );
	}

	/**
	 * Finalize a subscription order after the customer confirmed the first
	 * invoice's PaymentIntent in the browser (deferred / order-first flow).
	 *
	 * Re-enters maybe_override_process_payment() now that the Stripe
	 * subscription is no longer 'incomplete'; that path stamps the remaining
	 * meta, marks the WooCommerce order paid, stores the card token and applies
	 * any limited-coupon price schedule.
	 *
	 * @param array|WP_Error|null $result Prior override result.
	 * @param WC_Order            $order  The order being finalized.
	 * @return array|WP_Error|null Array with a redirect on success, WP_Error on
	 *                             failure, or the untouched $result for
	 *                             non-subscription orders.
	 */
	public function maybe_finalize_order( $result, $order ) {
		if ( ! self::order_contains_subscription( $order ) ) {
			return $result;
		}

		$outcome = $this->maybe_override_process_payment( null, $order->get_id() );

		// Still awaiting confirmation means the first invoice PaymentIntent did
		// not reach a paid state — surface an error so the customer can retry.
		if ( is_array( $outcome ) && ! empty( $outcome['rpsfw_stripe_confirm'] ) ) {
			return new WP_Error( 'rpsfw_stripe_not_confirmed', __( 'Your payment was not completed. Please try again.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		if ( is_array( $outcome ) && isset( $outcome['result'] ) && 'success' === $outcome['result'] ) {
			if ( WC()->cart ) {
				WC()->cart->empty_cart();
			}
			return array(
				'redirect' => isset( $outcome['redirect'] ) ? $outcome['redirect'] : $this->gateway->get_return_url( $order ),
			);
		}

		return new WP_Error( 'rpsfw_stripe_finalize_failed', __( 'We could not complete your subscription. Please try again.', 'restore-paypal-standard-for-woocommerce' ) );
	}

	/**
	 * When the cart has a subscription, finalize through here instead of
	 * the standard one-off PaymentIntent flow.
	 *
	 * The customer authenticated the first invoice's PaymentIntent with
	 * the Payment Element; we just need to verify Stripe's view of it,
	 * mark the order paid, and copy meta onto each WC Subscription.
	 *
	 * @param array|null $result   Override result if non-null.
	 * @param int        $order_id WC order id.
	 * @return array|null
	 */
	public function maybe_override_process_payment( $result, $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return $result;
		}

		// Change-payment-method on My Account: WC Subscriptions posts the
		// subscription id as the "order id" with $0 total. We create a
		// SetupIntent flow: the customer authenticates the new card via
		// the Payment Element (already done by the time we reach here),
		// and we attach + set as default on the Stripe subscription +
		// customer.
		if ( $this->is_change_payment_method_request() ) {
			return $this->process_change_payment_method( $order );
		}

		if ( ! self::order_contains_subscription( $order ) ) {
			return $result;
		}
		// Renewal orders never come through checkout; webhooks drive those.
		if ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) {
			return $result;
		}

		// Independent multi-schedule subscriptions: the card was authenticated
		// once via a SetupIntent; create the separate subscriptions off-session
		// now. Detected by the session payload stashed at the AJAX setup step.
		if ( WC()->session && WC()->session->get( 'rpsfw_stripe_pending_independent_schedules' ) ) {
			try {
				return $this->finalize_independent_subscriptions( $order );
			} catch ( Exception $e ) {
				RPSFW_Gateway_Stripe::log( 'Independent subscriptions finalize error: ' . $e->getMessage(), 'error' );
				wc_add_notice( $e->getMessage(), 'error' );
				return array( 'result' => 'failure' );
			}
		}

		try {
			$subscription_id = '';
			$customer_id     = '';
			if ( ! empty( $_POST['rpsfw_stripe_subscription_id'] ) ) {
				$subscription_id = sanitize_text_field( wp_unslash( $_POST['rpsfw_stripe_subscription_id'] ) );
			} elseif ( WC()->session ) {
				$subscription_id = WC()->session->get( 'rpsfw_stripe_pending_subscription_id' );
			}
			if ( WC()->session ) {
				$customer_id = WC()->session->get( 'rpsfw_stripe_pending_customer_id' );
			}

			if ( empty( $subscription_id ) ) {
				throw new Exception( __( 'Stripe subscription was not created. Please try again.', 'restore-paypal-standard-for-woocommerce' ) );
			}

			$sub = RPSFW_Stripe_API::retrieve_subscription( $subscription_id, array(
				'expand' => array( 'latest_invoice.confirmation_secret', 'pending_setup_intent', 'default_payment_method' ),
			) );
			if ( is_wp_error( $sub ) ) {
				throw new Exception( $sub->get_error_message() );
			}

			$status = isset( $sub->status ) ? $sub->status : '';
			if ( ! in_array( $status, array( 'active', 'trialing', 'past_due', 'incomplete' ), true ) ) {
				throw new Exception( sprintf(
					/* translators: %s: Stripe subscription status */
					__( 'Stripe subscription is not active (status: %s).', 'restore-paypal-standard-for-woocommerce' ),
					$status
				) );
			}

			// Deferred confirmation (order-first flow). A paid subscription's
			// first invoice PaymentIntent is still unconfirmed at this point
			// (status 'incomplete') because, in the order-first flow, the
			// customer confirms AFTER WooCommerce has created and validated the
			// order — so nothing has been charged yet. Stamp the link meta, keep
			// the order pending, and hand back a marker so the checkout JS
			// confirms the PaymentIntent and then calls ajax_finalize_order(),
			// which re-enters this method with the subscription now active and
			// completes the order.
			//
			// Setup-mode flows (free trials / card-save) confirm before submit
			// and therefore arrive here already 'trialing'/'active', so they
			// skip this branch and complete immediately below — unchanged.
			if ( 'incomplete' === $status ) {
				$order->update_meta_data( self::META_SUBSCRIPTION_ID, $sub->id );
				if ( ! empty( $sub->customer ) ) {
					$order->update_meta_data( self::META_CUSTOMER_ID, is_object( $sub->customer ) ? $sub->customer->id : (string) $sub->customer );
				}
				$order->set_transaction_id( $sub->id );
				$order->update_status( 'pending', __( 'Awaiting Stripe payment confirmation.', 'restore-paypal-standard-for-woocommerce' ) );
				$order->save();

				foreach ( wcs_get_subscriptions_for_order( $order ) as $wc_sub ) {
					$wc_sub->update_meta_data( self::META_SUBSCRIPTION_ID, $sub->id );
					$wc_sub->set_payment_method( $this->gateway->id );
					$wc_sub->set_payment_method_title( $this->gateway->get_title() );
					$wc_sub->save();
				}

				return array(
					'result'                 => 'success',
					'rpsfw_stripe_confirm'   => 'payment',
					'rpsfw_stripe_order_id'  => $order->get_id(),
					'rpsfw_stripe_order_key' => $order->get_order_key(),
					'redirect'               => $this->gateway->get_return_url( $order ),
				);
			}

			// Capture details on the parent order + WC subscription.
			// Prefer the subscription's default_payment_method; fall back to
			// the PaymentIntent that the customer confirmed on checkout (its id
			// is posted back by the front end) since the invoice no longer
			// exposes the PaymentIntent directly in modern API versions.
			$payment_method_id = '';
			$payment_intent_id = ! empty( $_POST['rpsfw_stripe_payment_intent_id'] )
				? sanitize_text_field( wp_unslash( $_POST['rpsfw_stripe_payment_intent_id'] ) )
				: '';
			if ( ! empty( $sub->default_payment_method ) ) {
				$payment_method_id = is_object( $sub->default_payment_method ) ? $sub->default_payment_method->id : (string) $sub->default_payment_method;
			} elseif ( ! empty( $payment_intent_id ) ) {
				$pi = RPSFW_Stripe_API::retrieve_payment_intent( $payment_intent_id );
				if ( ! is_wp_error( $pi ) && ! empty( $pi->payment_method ) ) {
					$payment_method_id = is_object( $pi->payment_method ) ? $pi->payment_method->id : (string) $pi->payment_method;
				}
			}

			$invoice_id = ! empty( $sub->latest_invoice ) && isset( $sub->latest_invoice->id ) ? $sub->latest_invoice->id : '';

			$order->update_meta_data( self::META_SUBSCRIPTION_ID, $sub->id );
			if ( $customer_id ) {
				$order->update_meta_data( self::META_CUSTOMER_ID, $customer_id );
			} elseif ( ! empty( $sub->customer ) ) {
				$order->update_meta_data( self::META_CUSTOMER_ID, is_object( $sub->customer ) ? $sub->customer->id : (string) $sub->customer );
			}
			if ( $payment_method_id ) {
				$order->update_meta_data( self::META_PAYMENT_METHOD, $payment_method_id );
			}
			if ( $payment_intent_id ) {
				$order->update_meta_data( self::META_PAYMENT_INTENT, $payment_intent_id );
				// Stamp the order id on the PaymentIntent so refund/dispute
				// webhooks resolve directly (the charge no longer carries the
				// invoice/subscription link in the basil API).
				RPSFW_Stripe_API::stamp_order_on_payment_intent( $payment_intent_id, $order );
			}
			if ( $invoice_id ) {
				$order->update_meta_data( self::META_INVOICE_ID, $invoice_id );
				$processed = (array) $order->get_meta( self::META_PROCESSED_INV );
				if ( ! in_array( $invoice_id, $processed, true ) ) {
					$processed[] = $invoice_id;
					$order->update_meta_data( self::META_PROCESSED_INV, $processed );
				}
			}
			$order->set_transaction_id( $sub->id );
			$order->save();

			// For mixed-interval subscriptions, map each WC subscription to its
			// Stripe subscription item so renewals and cancellation can target
			// the correct schedule. Match by billing period + interval via the
			// price ids we created (stored in the session schedule map).
			$schedule_map  = WC()->session ? WC()->session->get( 'rpsfw_stripe_pending_schedule_map' ) : null;
			$price_to_item = array();
			if ( ! empty( $schedule_map ) && ! empty( $sub->items->data ) ) {
				foreach ( $sub->items->data as $sub_item ) {
					$pid = isset( $sub_item->price ) ? ( is_object( $sub_item->price ) ? $sub_item->price->id : $sub_item->price ) : '';
					if ( $pid ) {
						$price_to_item[ $pid ] = $sub_item->id;
					}
				}
			}

			// One-time sync of the Stripe customer's name/email/phone from the
			// now-final order billing details — see sync_customer_from_order().
			if ( ! empty( $sub->customer ) ) {
				$this->sync_customer_from_order( is_object( $sub->customer ) ? $sub->customer->id : (string) $sub->customer, $order );
			}

			foreach ( wcs_get_subscriptions_for_order( $order ) as $wc_sub ) {
				$wc_sub->update_meta_data( self::META_SUBSCRIPTION_ID, $sub->id );
				if ( ! empty( $sub->customer ) ) {
					$wc_sub->update_meta_data( self::META_CUSTOMER_ID, is_object( $sub->customer ) ? $sub->customer->id : (string) $sub->customer );
				}
				if ( $payment_method_id ) {
					$wc_sub->update_meta_data( self::META_PAYMENT_METHOD, $payment_method_id );
				}

				// Stamp the matching Stripe subscription item id (mixed-interval).
				if ( ! empty( $schedule_map ) && ! empty( $price_to_item ) ) {
					$wc_period   = $wc_sub->get_billing_period();
					$wc_interval = (int) $wc_sub->get_billing_interval();
					foreach ( $schedule_map as $sm ) {
						if ( $sm['period'] === $wc_period && (int) $sm['interval_count'] === $wc_interval && isset( $price_to_item[ $sm['price_id'] ] ) ) {
							$wc_sub->update_meta_data( self::META_SUB_ITEM_ID, $price_to_item[ $sm['price_id'] ] );
							$wc_sub->update_meta_data( self::META_PRICE_ID, $sm['price_id'] );
							break;
						}
					}
				}

				$wc_sub->set_payment_method( $this->gateway->id );
				$wc_sub->set_payment_method_title( $this->gateway->get_title() );
				// gateway_scheduled_payments handles the cadence — WC's
				// scheduler will not fire the renewal hook for this
				// subscription, so we do not need to set
				// _requires_manual_renewal.
				$wc_sub->save();
				RPSFW_Gateway_Stripe::log( 'Finalize: saved ' . self::META_SUBSCRIPTION_ID . '=' . $sub->id . ' on WC subscription #' . $wc_sub->get_id() . ' (readback: ' . $wc_sub->get_meta( self::META_SUBSCRIPTION_ID ) . ')' );
			}

			// Mark the parent order paid. The first invoice.payment_succeeded
			// webhook from Stripe will reconcile if anything is missing — and
			// may already have completed the order while the calls above were in
			// flight, so re-check the stored status first. Completing it twice
			// re-fires the customer "order processing" email.
			if ( rpsfw_order_still_needs_payment( $order ) ) {
				$order->payment_complete( $sub->id );
				$order->add_order_note( sprintf(
					/* translators: %s: Stripe subscription id */
					__( 'Stripe subscription created (Subscription ID: %s).', 'restore-paypal-standard-for-woocommerce' ),
					$sub->id
				) );
			} else {
				RPSFW_Gateway_Stripe::log( 'Order #' . $order->get_id() . ' was already completed (webhook) — skipping duplicate payment_complete for ' . $sub->id . '.' );
			}

			// Save a WC payment token so the saved card appears under
			// My Account → Payment methods.
			if ( $payment_method_id && $order->get_customer_id() && ! empty( $sub->customer ) ) {
				$this->store_payment_token(
					$order->get_customer_id(),
					$payment_method_id,
					is_object( $sub->customer ) ? $sub->customer->id : (string) $sub->customer
				);
			}

			// If a limited-duration recurring coupon was applied, wrap the now
			// active/paid subscription in a Stripe Subscription Schedule that
			// steps the price back up to full after the covered cycles. This is
			// fully Stripe-managed for every interval (day/week/month/year) — no
			// coupons and no webhook removal required.
			if ( WC()->session ) {
				$sched_data = WC()->session->get( 'rpsfw_stripe_pending_schedule' );
				if ( ! empty( $sched_data ) && is_array( $sched_data ) ) {
					$this->apply_limited_coupon_schedule( $sub->id, $sched_data, $order );
					WC()->session->__unset( 'rpsfw_stripe_pending_schedule' );
				}
			}

			if ( WC()->session ) {
				WC()->session->__unset( 'rpsfw_stripe_pending_subscription_id' );
				WC()->session->__unset( 'rpsfw_stripe_pending_customer_id' );
				WC()->session->__unset( 'rpsfw_stripe_pending_price_id' );
				WC()->session->__unset( 'rpsfw_stripe_pending_signature' );
				WC()->session->__unset( 'rpsfw_stripe_pending_schedule_map' );
			}

			return array(
				'result'   => 'success',
				'redirect' => $this->gateway->get_return_url( $order ),
			);
		} catch ( Exception $e ) {
			RPSFW_Gateway_Stripe::log( 'Stripe subscriptions process_payment error: ' . $e->getMessage(), 'error' );
			wc_add_notice( $e->getMessage(), 'error' );
			return array( 'result' => 'failure' );
		}
	}

	/**
	 * Wrap a paid subscription in a Stripe Subscription Schedule that steps the
	 * price up to full after each limited-duration recurring coupon's covered
	 * number of cycles. Fully Stripe-managed for all intervals (day/week/month/
	 * year) — Stripe advances the phases on its own servers, so no webhook or
	 * WP-cron is involved.
	 *
	 * Phase 0 is the current (discounted) price the subscription was created
	 * with. Each subsequent phase adds back the discounts of the coupons that
	 * have reached their payment limit, until the final open-ended phase bills
	 * the full price. Phase lengths use `duration` = { interval, interval_count }
	 * expressed in the subscription's own interval, so cycle counts are exact
	 * for every interval.
	 *
	 * @param string              $subscription_id Stripe subscription id.
	 * @param array               $data            Session-stashed schedule data.
	 * @param WC_Order            $order           Parent order (for notes/meta).
	 * @param WC_Subscription|null $target_wc_sub  Optional. When provided (the
	 *                                             independent multi-schedule
	 *                                             flow), the schedule id is
	 *                                             stamped ONLY on this WC
	 *                                             subscription instead of every
	 *                                             subscription on the order.
	 * @return void
	 */
	private function apply_limited_coupon_schedule( $subscription_id, $data, $order, $target_wc_sub = null ) {
		$limited = isset( $data['limited_coupons'] ) ? $data['limited_coupons'] : array();
		if ( empty( $limited ) ) {
			return;
		}

		$currency       = $data['currency'];
		$interval       = $data['interval'];
		$interval_count = max( 1, (int) $data['interval_count'] );
		$plan_name      = $data['plan_name'];
		$discounted     = (float) $data['recurring_amount'];      // all limited discounts applied
		$full           = (float) $data['recurring_amount_full']; // no limited discounts

		// Build the distinct payment-limit boundaries (ascending). At each
		// boundary, the coupons reaching their limit stop applying, so the price
		// steps up by their discount.
		$by_limit = array();
		foreach ( $limited as $lc ) {
			$limit = max( 1, (int) $lc['limit'] );
			if ( ! isset( $by_limit[ $limit ] ) ) {
				$by_limit[ $limit ] = 0.0;
			}
			$by_limit[ $limit ] += (float) $lc['discount'];
		}
		ksort( $by_limit );

		// Build phases. Phase 0 uses the discounted price the subscription was
		// created with (reuse it). Each phase after a boundary uses a Price at
		// the stepped-up amount. The final phase is open-ended at full price.
		$phases       = array();
		$prev_limit   = 0;
		$current_amt  = $discounted;
		$discounted_price_id = isset( $data['discounted_price_id'] ) ? $data['discounted_price_id'] : '';

		foreach ( $by_limit as $limit => $added_back ) {
			$cycles = (int) $limit - (int) $prev_limit; // length of this phase in cycles
			if ( $cycles < 1 ) {
				continue;
			}
			// Price id for the current amount.
			if ( abs( $current_amt - $discounted ) < 0.005 && $discounted_price_id ) {
				$price_id = $discounted_price_id;
			} else {
				$price_id = RPSFW_Stripe_API::find_or_create_price( array(
					'amount'         => round( $current_amt, 2 ),
					'currency'       => $currency,
					'interval'       => $interval,
					'interval_count' => $interval_count,
					'product_name'   => $plan_name,
				) );
				if ( is_wp_error( $price_id ) ) {
					RPSFW_Gateway_Stripe::log( 'Schedule price create failed: ' . $price_id->get_error_message(), 'error' );
					return;
				}
			}
			$phases[] = array(
				'items'    => array( array( 'price' => $price_id, 'quantity' => 1 ) ),
				'duration' => array(
					'interval'       => $interval,
					'interval_count' => $cycles * $interval_count,
				),
			);
			$current_amt += (float) $added_back;
			$prev_limit   = (int) $limit;
		}

		// Final open-ended phase at full price.
		$full_price_id = RPSFW_Stripe_API::find_or_create_price( array(
			'amount'         => round( $full, 2 ),
			'currency'       => $currency,
			'interval'       => $interval,
			'interval_count' => $interval_count,
			'product_name'   => $plan_name,
		) );
		if ( is_wp_error( $full_price_id ) ) {
			RPSFW_Gateway_Stripe::log( 'Schedule full price create failed: ' . $full_price_id->get_error_message(), 'error' );
			return;
		}
		$phases[] = array(
			'items' => array( array( 'price' => $full_price_id, 'quantity' => 1 ) ),
		);

		// Create a schedule from the live subscription and set the phases. The
		// first phase keeps the subscription's current start so billing is not
		// disrupted; Stripe advances phases automatically thereafter.
		$schedule = RPSFW_Stripe_API::create_schedule_from_subscription( $subscription_id );
		if ( is_wp_error( $schedule ) ) {
			RPSFW_Gateway_Stripe::log( 'Could not create subscription schedule: ' . $schedule->get_error_message(), 'error' );
			return;
		}

		// Preserve the current phase start date so Stripe treats phase 0 as the
		// ongoing period rather than restarting it.
		if ( isset( $schedule->phases[0]->start_date ) ) {
			$phases[0]['start_date'] = $schedule->phases[0]->start_date;
		}

		$result = RPSFW_Stripe_API::update_subscription_schedule( $schedule->id, $phases, 'release' );
		if ( is_wp_error( $result ) ) {
			RPSFW_Gateway_Stripe::log( 'Could not set subscription schedule phases: ' . $result->get_error_message(), 'error' );
			return;
		}

		if ( $target_wc_sub ) {
			// Independent multi-schedule flow: this schedule belongs to exactly
			// one WC subscription — stamp only that one so the cancel handler
			// cancels the right schedule.
			$target_wc_sub->update_meta_data( self::META_SCHEDULE_ID, $schedule->id );
			$target_wc_sub->save();
		} elseif ( $order ) {
			$order->update_meta_data( self::META_SCHEDULE_ID, $schedule->id );
			$order->save();
			// Store on each WC subscription too, so the cancel handler can
			// cancel the schedule (a schedule-managed subscription can't be
			// canceled directly).
			foreach ( wcs_get_subscriptions_for_order( $order ) as $wc_sub ) {
				$wc_sub->update_meta_data( self::META_SCHEDULE_ID, $schedule->id );
				$wc_sub->save();
			}
		}
		RPSFW_Gateway_Stripe::log( sprintf(
			'Applied limited-coupon price schedule %s to subscription %s (%d phase(s)).',
			$schedule->id,
			$subscription_id,
			count( $phases )
		) );
	}

	/**
	 * One-time sync of a Stripe customer's name/email/phone from the now-final
	 * order billing details, called once per checkout from a finalize path.
	 *
	 * The Stripe customer for this checkout was created earlier (when the
	 * draft subscription was set up for the Payment Element, before the buyer
	 * had necessarily filled in the checkout form), so it may still have a
	 * blank name/email at this point. Order billing is authoritative and
	 * final here, so this is the right (and only) place to backfill it — see
	 * RPSFW_Stripe_API::get_or_create_customer()'s $sync_details param for why
	 * this isn't done on every draft (re)creation instead.
	 *
	 * @param string   $customer_id Stripe customer id.
	 * @param WC_Order $order       Finalized order.
	 */
	private function sync_customer_from_order( $customer_id, $order ) {
		if ( ! $customer_id || ! $order instanceof WC_Order ) {
			return;
		}
		$billing = array(
			'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'email' => $order->get_billing_email(),
			'phone' => $order->get_billing_phone(),
		);
		RPSFW_Stripe_API::get_or_create_customer( $order->get_customer_id(), $billing, $customer_id, true );
	}

	/**
	 * Persist the Stripe payment_method id as a WC_Payment_Token_CC.
	 *
	 * @param int    $user_id           WP user id.
	 * @param string $payment_method_id Stripe payment_method id (pm_).
	 * @param string $stripe_customer_id Stripe customer id (cus_).
	 */
	private function store_payment_token( $user_id, $payment_method_id, $stripe_customer_id ) {
		// Intentionally a no-op. Saved-card reuse is out of scope for this
		// release, and storing WC payment tokens caused WooCommerce to render a
		// "saved payment methods" list at checkout. Selecting one of those saved
		// cards bypasses the Stripe Payment Element, so the subscription's first
		// invoice PaymentIntent is never confirmed — the order completes but
		// Stripe reports "the customer hasn't attempted to pay this invoice."
		// Renewals do NOT need a WC token: Stripe bills them off-session using
		// the customer's default payment method (stored Stripe-side via
		// save_default_payment_method). See hide_our_payment_tokens(), which
		// also suppresses any tokens created by earlier builds.
		return;
	}

	/**
	 * Remove this gateway's saved payment tokens everywhere (checkout + My
	 * Account). Saved-card reuse is out of scope, and surfacing tokens lets a
	 * buyer pick a saved card that bypasses the Payment Element, leaving the
	 * subscription invoice unpaid. Filtering here also hides tokens created by
	 * earlier builds without needing to delete them from the database.
	 *
	 * @param array  $tokens      Tokens keyed by id.
	 * @param int    $customer_id Customer/user id.
	 * @param string $gateway_id  Gateway id filter ('' = all gateways).
	 * @return array
	 */
	public function hide_our_payment_tokens( $tokens, $customer_id, $gateway_id ) {
		if ( '' !== $gateway_id && $this->gateway->id !== $gateway_id ) {
			return $tokens;
		}
		foreach ( $tokens as $key => $token ) {
			if ( is_object( $token ) && method_exists( $token, 'get_gateway_id' ) && $this->gateway->id === $token->get_gateway_id() ) {
				unset( $tokens[ $key ] );
			}
		}
		return $tokens;
	}

	// -----------------------------------------------------------------------
	//  Lifecycle hooks: WC subscription status changes -> Stripe API.
	// -----------------------------------------------------------------------

	/**
	 * When the customer changes payment method away from us, cancel the
	 * Stripe subscription so we don't keep billing alongside the new
	 * gateway.
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

		$result = RPSFW_Stripe_API::cancel_subscription( $id );
		if ( ! is_wp_error( $result ) ) {
			$subscription->delete_meta_data( self::META_SUBSCRIPTION_ID );
			$subscription->save();
		}
	}

	/**
	 * Hide WC Subscriptions admin actions that conflict with the
	 * gateway-driven schedule.
	 *
	 * "Process renewal" fires woocommerce_scheduled_subscription_payment,
	 * which we do not handle (Stripe owns the schedule). "Create pending
	 * renewal" creates a renewal order that has no path to be paid because
	 * we never process renewals on the WC side. Both would silently no-op
	 * or get a customer stuck.
	 *
	 * For testing in Stripe, advance time using Stripe's test clock.
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
	 * Cancel the Stripe subscription when the WC one is cancelled.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 */
	public function cancel_stripe_subscription( $subscription ) {
		if ( $subscription->get_payment_method() !== $this->gateway->id ) {
			return;
		}
		// If the subscription is managed by a Stripe Subscription Schedule
		// (used for limited-duration recurring coupon price steps), cancel the
		// schedule — canceling the subscription directly errors in that case.
		// Canceling the schedule also cancels its subscription immediately.
		$schedule_id = $subscription->get_meta( self::META_SCHEDULE_ID );
		if ( $schedule_id ) {
			$result = RPSFW_Stripe_API::cancel_schedule( $schedule_id );
			if ( ! is_wp_error( $result ) ) {
				return;
			}
			// Fall through to a direct cancel if the schedule was already
			// released/completed (e.g. status no longer active).
		}
		$id = $subscription->get_meta( self::META_SUBSCRIPTION_ID );
		if ( ! $id ) {
			return;
		}

		// Mixed-interval: this WC subscription is one item on a shared Stripe
		// subscription. If other items (other schedules) are still active,
		// remove just this item rather than cancelling the whole subscription.
		$item_id = $subscription->get_meta( self::META_SUB_ITEM_ID );
		if ( $item_id ) {
			$stripe_sub = RPSFW_Stripe_API::retrieve_subscription( $id );
			$item_count = ( ! is_wp_error( $stripe_sub ) && ! empty( $stripe_sub->items->data ) ) ? count( $stripe_sub->items->data ) : 0;
			if ( $item_count > 1 ) {
				RPSFW_Stripe_API::remove_subscription_item( $item_id );
				return;
			}
		}

		RPSFW_Stripe_API::cancel_subscription( $id );
	}

	/**
	 * Schedule cancellation on Stripe when the WC subscription enters
	 * "pending-cancel" (customer cancelled but keeps access until the end of the
	 * paid period). We set cancel_at_period_end so Stripe stops billing at
	 * period end rather than charging another cycle. Stripe fully cancels the
	 * subscription at period end; WooCommerce independently transitions it to
	 * "cancelled" at the same time (which triggers cancel_stripe_subscription as
	 * a backstop).
	 *
	 * @param WC_Subscription $subscription Subscription.
	 */
	public function schedule_stripe_cancellation( $subscription ) {
		if ( $subscription->get_payment_method() !== $this->gateway->id ) {
			return;
		}
		$id = $subscription->get_meta( self::META_SUBSCRIPTION_ID );
		if ( ! $id ) {
			return;
		}

		// Mixed-interval: this WC subscription is one item on a shared Stripe
		// subscription. cancel_at_period_end applies to the WHOLE subscription,
		// so it can't end just this schedule. Leave it — the item is removed
		// when WooCommerce transitions this subscription to "cancelled" at the
		// end of its term (handled by cancel_stripe_subscription).
		$item_id = $subscription->get_meta( self::META_SUB_ITEM_ID );
		if ( $item_id ) {
			$stripe_sub = RPSFW_Stripe_API::retrieve_subscription( $id );
			$item_count = ( ! is_wp_error( $stripe_sub ) && ! empty( $stripe_sub->items->data ) ) ? count( $stripe_sub->items->data ) : 0;
			if ( $item_count > 1 ) {
				return;
			}
		}

		$result = RPSFW_Stripe_API::update_subscription( $id, array(
			'cancel_at_period_end' => true,
		) );
		if ( is_wp_error( $result ) ) {
			RPSFW_Gateway_Stripe::log( 'Failed to set cancel_at_period_end on ' . $id . ' for pending cancellation: ' . $result->get_error_message(), 'error' );
		}
	}

	/**
	 * Re-enable the "Reactivate" (undo cancellation) action for a
	 * pending-cancel subscription paid via this gateway.
	 *
	 * WooCommerce Subscriptions disables reactivation for gateways that support
	 * gateway_scheduled_payments, so a customer who cancels only sees
	 * "Resubscribe" (a fresh checkout). Because we can clear Stripe's
	 * cancel_at_period_end, we allow the transition back to active for a
	 * still-in-term pending-cancel subscription. The reactivation itself is
	 * handled by resume_stripe_subscription() on the status change.
	 *
	 * @param bool            $can_be_updated Whether the transition is allowed.
	 * @param WC_Subscription $subscription   Subscription.
	 * @return bool
	 */
	public function allow_reactivate_pending_cancel( $can_be_updated, $subscription ) {
		if ( $can_be_updated || ! is_a( $subscription, 'WC_Subscription' ) ) {
			return $can_be_updated;
		}
		if ( $subscription->get_payment_method() !== $this->gateway->id ) {
			return $can_be_updated;
		}
		// Only a still-in-term pending-cancel subscription with all products
		// still available can be resumed (mirrors WC's own guards).
		if ( $subscription->has_status( 'pending-cancel' )
			&& $subscription->get_time( 'end' ) > gmdate( 'U' )
			&& ! $subscription->contains_unavailable_product() ) {
			return true;
		}
		return $can_be_updated;
	}

	/**
	 * Pause the Stripe subscription when the WC one goes on-hold.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 */
	public function pause_stripe_subscription( $subscription ) {
		if ( $subscription->get_payment_method() !== $this->gateway->id ) {
			return;
		}
		$id = $subscription->get_meta( self::META_SUBSCRIPTION_ID );
		if ( ! $id ) {
			return;
		}

		// Mixed-interval: pause only THIS schedule by removing its item, when
		// other schedules on the shared Stripe subscription are still active.
		// (Stripe's pause_collection is subscription-wide and can't pause a
		// single item.) The removed price is stored so resume can re-add it.
		$item_id  = $subscription->get_meta( self::META_SUB_ITEM_ID );
		$price_id = $subscription->get_meta( self::META_PRICE_ID );
		if ( $item_id && $price_id ) {
			$stripe_sub = RPSFW_Stripe_API::retrieve_subscription( $id );
			$item_count = ( ! is_wp_error( $stripe_sub ) && ! empty( $stripe_sub->items->data ) ) ? count( $stripe_sub->items->data ) : 0;
			if ( $item_count > 1 ) {
				$result = RPSFW_Stripe_API::remove_subscription_item( $item_id );
				if ( ! is_wp_error( $result ) ) {
					$subscription->update_meta_data( self::META_PAUSED_PRICE, $price_id );
					$subscription->delete_meta_data( self::META_SUB_ITEM_ID );
					$subscription->save();
				}
				return;
			}
		}

		// Single-item subscription: pause the whole thing.
		RPSFW_Stripe_API::pause_subscription( $id );
	}

	/**
	 * Resume the Stripe subscription when the WC one is reactivated.
	 *
	 * @param WC_Subscription $subscription Subscription.
	 */
	public function resume_stripe_subscription( $subscription ) {
		if ( $subscription->get_payment_method() !== $this->gateway->id ) {
			return;
		}
		$id = $subscription->get_meta( self::META_SUBSCRIPTION_ID );
		if ( ! $id ) {
			return;
		}

		// Mixed-interval: this schedule was paused by removing its item; re-add
		// it to resume billing for just this schedule.
		$paused_price = $subscription->get_meta( self::META_PAUSED_PRICE );
		if ( $paused_price ) {
			$new_item = RPSFW_Stripe_API::add_subscription_item( $id, $paused_price, 1 );
			if ( ! is_wp_error( $new_item ) && ! empty( $new_item->id ) ) {
				$subscription->update_meta_data( self::META_SUB_ITEM_ID, $new_item->id );
				$subscription->delete_meta_data( self::META_PAUSED_PRICE );
				$subscription->save();
			}
			return;
		}

		// Single-item subscription: clear pause_collection.
		RPSFW_Stripe_API::resume_subscription( $id );

		// If the customer reactivated a subscription that was pending
		// cancellation (cancel_at_period_end set), clear it so Stripe keeps
		// billing. Only issue the update when it's actually set, to avoid an
		// unnecessary write (and errors on schedule-managed subscriptions).
		$stripe_sub = RPSFW_Stripe_API::retrieve_subscription( $id );
		if ( ! is_wp_error( $stripe_sub ) && ! empty( $stripe_sub->cancel_at_period_end ) ) {
			RPSFW_Stripe_API::update_subscription( $id, array(
				'cancel_at_period_end' => false,
			) );
		}
	}

	// -----------------------------------------------------------------------
	//  Webhook handlers: Stripe events -> WC state.
	// -----------------------------------------------------------------------

	/**
	 * Look up a WC Subscription by Stripe subscription id.
	 *
	 * @param string $stripe_subscription_id Stripe subscription id.
	 * @return WC_Subscription|false
	 */
	private function find_wc_subscription_by_stripe_id( $stripe_subscription_id ) {
		if ( ! $stripe_subscription_id ) {
			return false;
		}
		$args = array(
			'limit'      => 1,
			'type'       => 'shop_subscription',
			// Subscriptions use their own statuses (active, on-hold, cancelled,
			// pending-cancel, ...). Without 'any', wc_get_orders defaults to the
			// shop_order statuses and returns nothing for subscriptions.
			'status'     => 'any',
			// Use a proper meta_query — the meta_key/meta_value shorthand is not
			// reliably honored for the shop_subscription order type / HPOS.
			'meta_query' => array(
				array(
					'key'   => self::META_SUBSCRIPTION_ID,
					'value' => $stripe_subscription_id,
				),
			),
		);
		$results = wc_get_orders( $args );
		if ( empty( $results ) ) {
			// Fallback: the Stripe subscription id is always recorded on the
			// parent order (see finalize), but older/edge-case subscriptions
			// may be missing the meta on the WC subscription object itself.
			// Resolve via the parent order and backfill the meta so future
			// lookups (cancel, pause, resume) succeed directly.
			$subs = $this->find_wc_subscriptions_via_parent_order( $stripe_subscription_id );
			if ( ! empty( $subs ) ) {
				RPSFW_Gateway_Stripe::log( 'find_wc_subscription_by_stripe_id: matched WC subscription #' . $subs[0]->get_id() . ' via parent order for ' . $stripe_subscription_id );
				return $subs[0];
			}
			RPSFW_Gateway_Stripe::log( 'find_wc_subscription_by_stripe_id: no WC subscription has meta ' . self::META_SUBSCRIPTION_ID . '=' . $stripe_subscription_id );
			return false;
		}
		RPSFW_Gateway_Stripe::log( 'find_wc_subscription_by_stripe_id: matched WC subscription #' . $results[0]->get_id() . ' for ' . $stripe_subscription_id );
		return $results[0];
	}

	/**
	 * Resolve WC subscriptions for a Stripe subscription id by locating the
	 * parent order that carries the meta, then reading the subscriptions tied
	 * to that order. Backfills the Stripe subscription id onto each WC
	 * subscription so subsequent direct lookups succeed.
	 *
	 * @param string $stripe_subscription_id Stripe subscription id.
	 * @return WC_Subscription[]
	 */
	private function find_wc_subscriptions_via_parent_order( $stripe_subscription_id ) {
		if ( ! $stripe_subscription_id || ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return array();
		}

		$orders = wc_get_orders( array(
			'limit'      => 1,
			'type'       => 'shop_order',
			'status'     => 'any',
			'meta_query' => array(
				array(
					'key'   => self::META_SUBSCRIPTION_ID,
					'value' => $stripe_subscription_id,
				),
			),
		) );

		if ( empty( $orders ) ) {
			return array();
		}

		$subs = wcs_get_subscriptions_for_order( $orders[0], array( 'order_type' => 'parent' ) );
		if ( empty( $subs ) ) {
			return array();
		}

		$resolved = array();
		foreach ( $subs as $wc_sub ) {
			// Backfill the meta so future direct lookups hit the fast path.
			if ( $wc_sub->get_meta( self::META_SUBSCRIPTION_ID ) !== $stripe_subscription_id ) {
				$wc_sub->update_meta_data( self::META_SUBSCRIPTION_ID, $stripe_subscription_id );
				$wc_sub->save();
				RPSFW_Gateway_Stripe::log( 'find_wc_subscriptions_via_parent_order: backfilled ' . self::META_SUBSCRIPTION_ID . '=' . $stripe_subscription_id . ' onto WC subscription #' . $wc_sub->get_id() );
			}
			$resolved[] = $wc_sub;
		}

		return $resolved;
	}

	/**
	 * Look up ALL WC Subscriptions backed by a single Stripe subscription id.
	 * A mixed-interval cart produces multiple WC subscriptions that share one
	 * Stripe subscription.
	 *
	 * @param string $stripe_subscription_id Stripe subscription id.
	 * @return WC_Subscription[]
	 */
	private function find_wc_subscriptions_by_stripe_id( $stripe_subscription_id ) {
		if ( ! $stripe_subscription_id ) {
			return array();
		}
		$results = wc_get_orders( array(
			'limit'      => -1,
			'type'       => 'shop_subscription',
			'status'     => 'any',
			'meta_query' => array(
				array(
					'key'   => self::META_SUBSCRIPTION_ID,
					'value' => $stripe_subscription_id,
				),
			),
		) );
		if ( empty( $results ) ) {
			// Same parent-order fallback as the singular lookup.
			return $this->find_wc_subscriptions_via_parent_order( $stripe_subscription_id );
		}
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Resolve the Stripe subscription id from an Invoice payload, handling
	 * both the legacy top-level `subscription` field and the modern
	 * `parent.subscription_details.subscription` location introduced in the
	 * basil (2025-03-31) API version.
	 *
	 * @param array $invoice Stripe Invoice object as an array.
	 * @return string
	 */
	private function get_subscription_id_from_invoice( $invoice ) {
		// Legacy (pre-basil): top-level subscription field.
		if ( ! empty( $invoice['subscription'] ) ) {
			$sub = $invoice['subscription'];
			if ( is_array( $sub ) ) {
				return isset( $sub['id'] ) ? $sub['id'] : '';
			}
			return (string) $sub;
		}
		// Modern (basil+): parent.subscription_details.subscription.
		if ( ! empty( $invoice['parent']['subscription_details']['subscription'] ) ) {
			$sub = $invoice['parent']['subscription_details']['subscription'];
			if ( is_array( $sub ) ) {
				return isset( $sub['id'] ) ? $sub['id'] : '';
			}
			return (string) $sub;
		}
		return '';
	}

	/**
	 * Handle invoice.payment_succeeded — for renewals, create a renewal
	 * order in WC and mark it paid. The very first invoice of a
	 * subscription is handled by checkout's process_payment, so we dedupe
	 * via the META_PROCESSED_INV list on the parent order.
	 *
	 * @param array $invoice Stripe Invoice object as array.
	 * @param array $event   Full event.
	 */
	/**
	 * Record the refund-resolution links for a subscription's initial invoice
	 * once it has settled: stamp the parent order id onto the invoice's
	 * PaymentIntent, and store the PaymentIntent + charge ids on the WC
	 * subscription(s). This lets Stripe-side refund/dispute webhooks resolve
	 * the order even though the charge no longer carries the invoice or
	 * subscription in the basil API.
	 *
	 * @param string $stripe_subscription_id Stripe subscription id.
	 * @param string $invoice_id             Settled invoice id.
	 * @return void
	 */
	private function backfill_initial_invoice_refs( $stripe_subscription_id, $invoice_id ) {
		if ( ! $stripe_subscription_id || ! $invoice_id ) {
			return;
		}
		$wc_subs = $this->find_wc_subscriptions_by_stripe_id( $stripe_subscription_id );
		if ( empty( $wc_subs ) ) {
			return;
		}

		$refs = RPSFW_Stripe_API::get_invoice_payment_refs( $invoice_id );
		if ( empty( $refs['payment_intent'] ) && empty( $refs['charge'] ) ) {
			RPSFW_Gateway_Stripe::log( 'backfill_initial_invoice_refs: could not resolve PI/charge for invoice ' . $invoice_id . '.', 'warning' );
			return;
		}

		foreach ( $wc_subs as $wc_sub ) {
			$changed = false;
			if ( ! empty( $refs['payment_intent'] ) && $wc_sub->get_meta( self::META_PAYMENT_INTENT ) !== $refs['payment_intent'] ) {
				$wc_sub->update_meta_data( self::META_PAYMENT_INTENT, $refs['payment_intent'] );
				$changed = true;
			}
			if ( ! empty( $refs['charge'] ) && $wc_sub->get_meta( '_rpsfw_stripe_charge_id' ) !== $refs['charge'] ) {
				$wc_sub->update_meta_data( '_rpsfw_stripe_charge_id', $refs['charge'] );
				$changed = true;
			}
			if ( $changed ) {
				$wc_sub->save();
			}

			// Stamp the parent order id on the PaymentIntent (primary refund
			// resolution path).
			$parent = $wc_sub->get_parent();
			if ( $parent && ! empty( $refs['payment_intent'] ) ) {
				RPSFW_Stripe_API::stamp_order_on_payment_intent( $refs['payment_intent'], $parent );
			}
		}
	}

	public function webhook_invoice_payment_succeeded( $invoice, $event ) {
		$stripe_subscription_id = $this->get_subscription_id_from_invoice( $invoice );
		if ( ! $stripe_subscription_id ) {
			return;
		}

		$invoice_id = isset( $invoice['id'] ) ? $invoice['id'] : '';
		if ( ! $invoice_id ) {
			return;
		}

		// Skip the subscription's INITIAL invoice. Stripe marks it with
		// billing_reason = 'subscription_create'; it is already represented by
		// the parent checkout order (single-schedule and independent flows
		// both mark the parent paid at checkout/finalize), so it is never a
		// renewal. Relying on billing_reason fixes the independent
		// multi-schedule flow, which — unlike the single-schedule flow — does
		// not pre-record the first invoice in META_PROCESSED_INV and would
		// otherwise spawn a phantom renewal order for each new subscription.
		// Genuine renewals use 'subscription_cycle'.
		$billing_reason = isset( $invoice['billing_reason'] ) ? $invoice['billing_reason'] : '';
		if ( 'subscription_create' === $billing_reason ) {
			// Not a renewal, but the initial invoice is now paid. Safety net:
			// complete the checkout order in case the browser never called
			// ajax_finalize_order (whichever of this event or
			// customer.subscription.updated arrives first completes it; the
			// other is a no-op). Then record the links that let Stripe-side
			// refunds resolve back to the order (the initial charge/PaymentIntent
			// can be unresolvable at checkout time).
			foreach ( $this->find_wc_subscriptions_by_stripe_id( $stripe_subscription_id ) as $wc_sub ) {
				$this->reconcile_pending_parent_order( $wc_sub, $stripe_subscription_id, array( 'latest_invoice' => $invoice_id ) );
			}
			$this->backfill_initial_invoice_refs( $stripe_subscription_id, $invoice_id );
			RPSFW_Gateway_Stripe::log( 'Renewal webhook: skipping initial (subscription_create) invoice ' . $invoice_id . ' for ' . $stripe_subscription_id . '.' );
			return;
		}

		// A single Stripe subscription may back multiple WC subscriptions
		// (mixed-interval carts). Resolve them all, then figure out which this
		// invoice is for via its line items' subscription items.
		$wc_subs = $this->find_wc_subscriptions_by_stripe_id( $stripe_subscription_id );
		if ( empty( $wc_subs ) ) {
			RPSFW_Gateway_Stripe::log( 'Renewal webhook: no WC subscription found for ' . $stripe_subscription_id );
			return;
		}

		$invoice_refs = $this->get_invoice_line_refs( $invoice );
		$invoice_item_ids  = $invoice_refs['items'];
		$invoice_price_ids = $invoice_refs['prices'];
		$has_refs          = ! empty( $invoice_item_ids ) || ! empty( $invoice_price_ids );
		$is_multi          = count( $wc_subs ) > 1;

		foreach ( $wc_subs as $wc_sub ) {
			// For mixed-interval subs, only create a renewal for the WC
			// subscription whose Stripe item / price appears on this invoice.
			if ( $is_multi && $has_refs ) {
				$item_id  = $wc_sub->get_meta( self::META_SUB_ITEM_ID );
				$price_id = $wc_sub->get_meta( self::META_PRICE_ID );
				$matches  = ( $item_id && in_array( $item_id, $invoice_item_ids, true ) )
					|| ( $price_id && in_array( $price_id, $invoice_price_ids, true ) );
				if ( ! $matches ) {
					continue;
				}
			}

			$parent_order = $wc_sub->get_parent();

			// A renewal must come from the same account the original payment was
			// taken in.
			if ( $parent_order && ! rpsfw_webhook_may_touch_order( $parent_order ) ) {
				RPSFW_Gateway_Stripe::log( 'Refusing ' . rpsfw_current_webhook_mode() . ' mode renewal for subscription #' . $wc_sub->get_id() . '; its order was paid in ' . rpsfw_get_order_payment_mode( $parent_order ) . ' mode.', 'warning' );
				continue;
			}

			// Skip the initial (checkout) invoice — the parent order already
			// covers it (its id is recorded on the parent's processed list).
			if ( $parent_order ) {
				$parent_processed = (array) $parent_order->get_meta( self::META_PROCESSED_INV );
				if ( in_array( $invoice_id, $parent_processed, true ) ) {
					continue;
				}
			}

			// Per-subscription dedupe (a combined invoice can map to several
			// WC subs; each must be processed once).
			$sub_processed = (array) $wc_sub->get_meta( self::META_PROCESSED_INV );
			if ( in_array( $invoice_id, $sub_processed, true ) ) {
				continue;
			}

			// For single-schedule subs keep the original global guard against a
			// duplicate renewal for the same invoice. Use the native
			// transaction_id query arg (HPOS-reliable) rather than the
			// meta_key/meta_value shorthand.
			if ( ! $is_multi ) {
				$existing = wc_get_orders( array(
					'limit'          => 1,
					'status'         => 'any',
					'transaction_id' => $invoice_id,
				) );
				if ( ! empty( $existing ) ) {
					RPSFW_Gateway_Stripe::log( 'Renewal webhook: duplicate invoice ' . $invoice_id . ', skipping.' );
					continue;
				}
			}

			$renewal_order = wcs_create_renewal_order( $wc_sub );
			if ( is_wp_error( $renewal_order ) ) {
				RPSFW_Gateway_Stripe::log( 'Renewal webhook: failed to create renewal order - ' . $renewal_order->get_error_message(), 'error' );
				continue;
			}
			$renewal_order->set_payment_method( $this->gateway->id );
			$renewal_order->set_payment_method_title( $this->gateway->get_title() );
			// The renewal belongs to the same account as the original payment.
			rpsfw_set_order_payment_mode(
				$renewal_order,
				$parent_order ? rpsfw_get_order_payment_mode( $parent_order ) : rpsfw_current_webhook_mode(),
				false
			);
			$renewal_order->payment_complete( $invoice_id );

			// Record the renewal charge + PaymentIntent on the renewal order so
			// a later Stripe-side refund of this renewal resolves directly (the
			// charge webhook carries neither the invoice nor the subscription in
			// the basil API). We have the invoice here, so resolve its refs now.
			$renewal_refs = RPSFW_Stripe_API::get_invoice_payment_refs( $invoice_id );
			if ( ! empty( $renewal_refs['payment_intent'] ) ) {
				$renewal_order->update_meta_data( '_rpsfw_stripe_payment_intent_id', $renewal_refs['payment_intent'] );
			}
			if ( ! empty( $renewal_refs['charge'] ) ) {
				$renewal_order->update_meta_data( '_rpsfw_stripe_charge_id', $renewal_refs['charge'] );
			}
			$renewal_order->save();

			$renewal_order->add_order_note( sprintf(
				/* translators: 1: Stripe invoice id, 2: subscription id */
				__( 'Stripe renewal received. Invoice: %1$s, Subscription: %2$s.', 'restore-paypal-standard-for-woocommerce' ),
				$invoice_id,
				$stripe_subscription_id
			) );

			$sub_processed[] = $invoice_id;
			$wc_sub->update_meta_data( self::META_PROCESSED_INV, $sub_processed );
			$wc_sub->save();

			// Update next-payment date if the invoice tells us when the next
			// period starts.
			if ( ! empty( $invoice['period_end'] ) ) {
				$next = (int) $invoice['period_end'];
				if ( $next > time() ) {
					$wc_sub->update_dates( array( 'next_payment' => gmdate( 'Y-m-d H:i:s', $next ) ) );
				}
			}
		}
	}

	/**
	 * Collect the Stripe subscription-item ids AND price ids referenced by an
	 * invoice's line items, so we can map a renewal invoice to the correct WC
	 * subscription in a mixed-interval subscription. Handles both the modern
	 * (basil) line shape and legacy shapes.
	 *
	 * @param array $invoice Stripe Invoice as array.
	 * @return array { items: string[], prices: string[] }
	 */
	private function get_invoice_line_refs( $invoice ) {
		$items  = array();
		$prices = array();
		if ( empty( $invoice['lines']['data'] ) || ! is_array( $invoice['lines']['data'] ) ) {
			return array( 'items' => $items, 'prices' => $prices );
		}
		foreach ( $invoice['lines']['data'] as $line ) {
			// Subscription item id.
			if ( ! empty( $line['subscription_item'] ) ) {
				$items[] = $line['subscription_item']; // legacy
			} elseif ( ! empty( $line['parent']['subscription_item_details']['subscription_item'] ) ) {
				$items[] = $line['parent']['subscription_item_details']['subscription_item']; // basil+
			}
			// Price id.
			if ( ! empty( $line['price']['id'] ) ) {
				$prices[] = $line['price']['id']; // legacy
			} elseif ( ! empty( $line['pricing']['price_details']['price'] ) ) {
				$prices[] = $line['pricing']['price_details']['price']; // basil+
			}
		}
		return array(
			'items'  => array_values( array_unique( $items ) ),
			'prices' => array_values( array_unique( $prices ) ),
		);
	}

	/**
	 * Handle invoice.payment_failed — note the failure on the WC
	 * subscription. Stripe Smart Retries will re-attempt automatically;
	 * if all retries fail, customer.subscription.deleted (or .updated to
	 * canceled) will arrive and we'll cancel the WC subscription.
	 *
	 * @param array $invoice Invoice payload.
	 * @param array $event   Full event.
	 */
	public function webhook_invoice_payment_failed( $invoice, $event ) {
		$stripe_subscription_id = $this->get_subscription_id_from_invoice( $invoice );
		if ( ! $stripe_subscription_id ) {
			return;
		}
		$wc_sub = $this->find_wc_subscription_by_stripe_id( $stripe_subscription_id );
		if ( ! $wc_sub ) {
			return;
		}

		$invoice_id = isset( $invoice['id'] ) ? $invoice['id'] : '';
		$wc_sub->add_order_note( sprintf(
			/* translators: %s: Stripe invoice id */
			__( 'Stripe reported a renewal payment failure (Invoice: %s). Stripe Smart Retries will re-attempt automatically.', 'restore-paypal-standard-for-woocommerce' ),
			$invoice_id
		) );
	}

	/**
	 * Handle invoice.payment_action_required — the renewal payment needs
	 * SCA. Stripe emails the customer with the hosted invoice URL by
	 * default; we just log and note.
	 *
	 * @param array $invoice Invoice payload.
	 * @param array $event   Full event.
	 */
	public function webhook_invoice_payment_action_required( $invoice, $event ) {
		$stripe_subscription_id = $this->get_subscription_id_from_invoice( $invoice );
		if ( ! $stripe_subscription_id ) {
			return;
		}
		$wc_sub = $this->find_wc_subscription_by_stripe_id( $stripe_subscription_id );
		if ( ! $wc_sub ) {
			return;
		}

		$hosted_url = isset( $invoice['hosted_invoice_url'] ) ? $invoice['hosted_invoice_url'] : '';
		$msg = __( 'Stripe renewal payment requires customer authentication.', 'restore-paypal-standard-for-woocommerce' );
		if ( $hosted_url ) {
			$msg .= ' ' . sprintf(
				/* translators: %s: hosted invoice URL */
				__( 'Stripe will email the customer a link to authenticate: %s', 'restore-paypal-standard-for-woocommerce' ),
				esc_url_raw( $hosted_url )
			);
		}
		$wc_sub->add_order_note( $msg );
	}

	/**
	 * Handle customer.subscription.deleted — Stripe cancelled the
	 * subscription (either through the API, dashboard, or after
	 * exhausting Smart Retries). Reflect that in WC.
	 *
	 * @param array $subscription Stripe subscription resource.
	 * @param array $event        Full event.
	 */
	public function webhook_subscription_deleted( $subscription, $event ) {
		$id = isset( $subscription['id'] ) ? $subscription['id'] : '';
		RPSFW_Gateway_Stripe::log( 'webhook_subscription_deleted: handler fired for Stripe subscription ' . $id );
		$wc_sub = $this->find_wc_subscription_by_stripe_id( $id );
		if ( ! $wc_sub ) {
			RPSFW_Gateway_Stripe::log( 'webhook_subscription_deleted: no matching WC subscription for ' . $id . ' — nothing to cancel.', 'warning' );
			return;
		}
		if ( $wc_sub->has_status( 'cancelled' ) ) {
			RPSFW_Gateway_Stripe::log( 'webhook_subscription_deleted: WC subscription #' . $wc_sub->get_id() . ' is already cancelled.' );
			return;
		}
		// Suppress our own cancel-back-to-Stripe hook to avoid loops.
		remove_action( 'woocommerce_subscription_status_cancelled', array( $this, 'cancel_stripe_subscription' ) );
		$wc_sub->update_status( 'cancelled', __( 'Stripe reported subscription cancelled.', 'restore-paypal-standard-for-woocommerce' ) );
		add_action( 'woocommerce_subscription_status_cancelled', array( $this, 'cancel_stripe_subscription' ) );
		RPSFW_Gateway_Stripe::log( 'webhook_subscription_deleted: cancelled WC subscription #' . $wc_sub->get_id() );
	}

	/**
	 * Handle customer.subscription.updated — generic catch-all for status
	 * flips. We mostly care about transitions to past_due / unpaid /
	 * canceled.
	 *
	 * @param array $subscription Stripe subscription resource.
	 * @param array $event        Full event.
	 */
	public function webhook_subscription_updated( $subscription, $event ) {
		$id = isset( $subscription['id'] ) ? $subscription['id'] : '';
		$status  = isset( $subscription['status'] ) ? $subscription['status'] : '';
		$paused  = ! empty( $subscription['pause_collection'] ) ? 'yes' : 'no';
		RPSFW_Gateway_Stripe::log( 'webhook_subscription_updated: handler fired for ' . $id . ' (status=' . $status . ', pause_collection=' . $paused . ')' );
		$wc_sub = $this->find_wc_subscription_by_stripe_id( $id );
		if ( ! $wc_sub ) {
			RPSFW_Gateway_Stripe::log( 'webhook_subscription_updated: no matching WC subscription for ' . $id . '.', 'warning' );
			return;
		}

		// "Pause payment collection" from the Stripe dashboard/API sets
		// pause_collection on the subscription and fires
		// customer.subscription.updated — the status stays `active`, and NO
		// customer.subscription.paused event is sent (that event only fires for
		// trial status=paused). So detect the pause here and mirror it to WC as
		// on-hold. When pause_collection is later cleared, this handler runs
		// again with pause_collection empty and falls through to the `active`
		// case below, which resumes the WC subscription.
		if ( ! empty( $subscription['pause_collection'] ) ) {
			if ( ! $wc_sub->has_status( 'on-hold' ) ) {
				remove_action( 'woocommerce_subscription_status_on-hold', array( $this, 'pause_stripe_subscription' ) );
				$wc_sub->update_status( 'on-hold', __( 'Stripe reported payment collection paused.', 'restore-paypal-standard-for-woocommerce' ) );
				add_action( 'woocommerce_subscription_status_on-hold', array( $this, 'pause_stripe_subscription' ) );
				RPSFW_Gateway_Stripe::log( 'webhook_subscription_updated: paused (on-hold) WC subscription #' . $wc_sub->get_id() . ' via pause_collection.' );
			} else {
				RPSFW_Gateway_Stripe::log( 'webhook_subscription_updated: WC subscription #' . $wc_sub->get_id() . ' already on-hold.' );
			}
			return;
		}

		switch ( $status ) {
			case 'canceled':
				if ( ! $wc_sub->has_status( 'cancelled' ) ) {
					remove_action( 'woocommerce_subscription_status_cancelled', array( $this, 'cancel_stripe_subscription' ) );
					$wc_sub->update_status( 'cancelled', __( 'Stripe reported subscription canceled.', 'restore-paypal-standard-for-woocommerce' ) );
					add_action( 'woocommerce_subscription_status_cancelled', array( $this, 'cancel_stripe_subscription' ) );
				}
				break;

			case 'unpaid':
			case 'past_due':
				if ( ! $wc_sub->has_status( 'on-hold' ) ) {
					remove_action( 'woocommerce_subscription_status_on-hold', array( $this, 'pause_stripe_subscription' ) );
					$wc_sub->update_status( 'on-hold', sprintf(
						/* translators: %s: Stripe subscription status */
						__( 'Stripe reported subscription status: %s.', 'restore-paypal-standard-for-woocommerce' ),
						$status
					) );
					add_action( 'woocommerce_subscription_status_on-hold', array( $this, 'pause_stripe_subscription' ) );
				}
				break;

			case 'active':
			case 'trialing':
				if ( $wc_sub->has_status( array( 'on-hold', 'pending' ) ) ) {
					remove_action( 'woocommerce_subscription_status_active', array( $this, 'resume_stripe_subscription' ) );
					$wc_sub->update_status( 'active', __( 'Stripe reported subscription active.', 'restore-paypal-standard-for-woocommerce' ) );
					add_action( 'woocommerce_subscription_status_active', array( $this, 'resume_stripe_subscription' ) );
				}
				// Server-side safety net: the subscription is paid/active but the
				// browser may never have called ajax_finalize_order (tab closed,
				// redirect race, expired finalize nonce, network drop), leaving
				// the checkout order stranded 'pending'. Complete it here.
				$this->reconcile_pending_parent_order( $wc_sub, $id, $subscription );
				break;
		}
	}

	/**
	 * Server-side safety net that completes the checkout (parent) order once
	 * Stripe confirms the subscription's first payment, in case the browser
	 * never reached ajax_finalize_order. Without this the Stripe subscription
	 * can be active/paid while the WooCommerce order stays 'pending'.
	 *
	 * Idempotent: only acts when the parent order still needs payment and was
	 * placed through this gateway.
	 *
	 * @param WC_Subscription $wc_sub        Matched WC subscription.
	 * @param string          $stripe_sub_id Stripe subscription id.
	 * @param array           $subscription  Stripe subscription webhook object (array).
	 * @return void
	 */
	private function reconcile_pending_parent_order( $wc_sub, $stripe_sub_id, $subscription = array() ) {
		$parent = $wc_sub ? $wc_sub->get_parent() : false;
		if ( ! $parent ) {
			return;
		}
		// Never complete an order from the other mode's account.
		if ( ! rpsfw_webhook_may_touch_order( $parent ) ) {
			RPSFW_Gateway_Stripe::log( 'Refusing ' . rpsfw_current_webhook_mode() . ' mode event for order #' . $parent->get_id() . ', which was paid in ' . rpsfw_get_order_payment_mode( $parent ) . ' mode.', 'warning' );
			return;
		}
		// Only the initial checkout order left pending by the deferred flow.
		if ( ! $parent->needs_payment() ) {
			return;
		}
		// Guard: only complete orders placed through this gateway.
		if ( $this->gateway && $parent->get_payment_method() && $parent->get_payment_method() !== $this->gateway->id ) {
			return;
		}

		// Resolve the initial invoice id from the webhook payload so Stripe-side
		// refunds/disputes can resolve back to this order.
		$invoice_id = '';
		if ( ! empty( $subscription['latest_invoice'] ) ) {
			$invoice_id = is_array( $subscription['latest_invoice'] )
				? ( isset( $subscription['latest_invoice']['id'] ) ? $subscription['latest_invoice']['id'] : '' )
				: (string) $subscription['latest_invoice'];
		}

		$parent->update_meta_data( self::META_SUBSCRIPTION_ID, $stripe_sub_id );
		if ( ! empty( $subscription['customer'] ) ) {
			$parent->update_meta_data( self::META_CUSTOMER_ID, is_array( $subscription['customer'] ) ? ( isset( $subscription['customer']['id'] ) ? $subscription['customer']['id'] : '' ) : (string) $subscription['customer'] );
		}

		if ( $invoice_id ) {
			$parent->update_meta_data( self::META_INVOICE_ID, $invoice_id );
			$processed = (array) $parent->get_meta( self::META_PROCESSED_INV );
			if ( ! in_array( $invoice_id, $processed, true ) ) {
				$processed[] = $invoice_id;
				$parent->update_meta_data( self::META_PROCESSED_INV, $processed );
			}
			$refs = RPSFW_Stripe_API::get_invoice_payment_refs( $invoice_id );
			if ( ! empty( $refs['payment_intent'] ) ) {
				$parent->update_meta_data( self::META_PAYMENT_INTENT, $refs['payment_intent'] );
				RPSFW_Stripe_API::stamp_order_on_payment_intent( $refs['payment_intent'], $parent );
			}
			if ( ! empty( $refs['charge'] ) ) {
				$parent->update_meta_data( '_rpsfw_stripe_charge_id', $refs['charge'] );
			}
		}

		$parent->set_transaction_id( $stripe_sub_id );
		$parent->save();

		// Mark the order paid (moves pending -> processing/completed, reduces
		// stock, sends emails). Prefer the invoice id as the transaction ref.
		// The needs_payment() test above ran before the invoice round trips, and
		// invoice.payment_succeeded / customer.subscription.updated both land
		// here in parallel requests, so re-check the stored status first.
		if ( ! rpsfw_order_still_needs_payment( $parent ) ) {
			RPSFW_Gateway_Stripe::log( 'Stripe subscriptions: order #' . $parent->get_id() . ' was completed by another request — skipping duplicate payment_complete.' );
			return;
		}

		$parent->payment_complete( $invoice_id ? $invoice_id : $stripe_sub_id );
		$parent->add_order_note( sprintf(
			/* translators: %s: Stripe subscription id */
			__( 'Stripe subscription payment confirmed via webhook; order completed automatically (Subscription ID: %s).', 'restore-paypal-standard-for-woocommerce' ),
			$stripe_sub_id
		) );
		RPSFW_Gateway_Stripe::log( 'Stripe subscriptions: completed order #' . $parent->get_id() . ' from webhook (subscription ' . $stripe_sub_id . ' paid/active).' );
	}

	/**
	 * Handle customer.subscription.paused.
	 *
	 * @param array $subscription Stripe subscription resource.
	 * @param array $event        Full event.
	 */
	public function webhook_subscription_paused( $subscription, $event ) {
		$id = isset( $subscription['id'] ) ? $subscription['id'] : '';
		RPSFW_Gateway_Stripe::log( 'webhook_subscription_paused: handler fired for ' . $id );
		$wc_sub = $this->find_wc_subscription_by_stripe_id( $id );
		if ( ! $wc_sub ) {
			RPSFW_Gateway_Stripe::log( 'webhook_subscription_paused: no matching WC subscription for ' . $id . '.', 'warning' );
			return;
		}
		if ( $wc_sub->has_status( 'on-hold' ) ) {
			RPSFW_Gateway_Stripe::log( 'webhook_subscription_paused: WC subscription #' . $wc_sub->get_id() . ' already on-hold.' );
			return;
		}
		remove_action( 'woocommerce_subscription_status_on-hold', array( $this, 'pause_stripe_subscription' ) );
		$wc_sub->update_status( 'on-hold', __( 'Stripe reported subscription paused.', 'restore-paypal-standard-for-woocommerce' ) );
		add_action( 'woocommerce_subscription_status_on-hold', array( $this, 'pause_stripe_subscription' ) );
		RPSFW_Gateway_Stripe::log( 'webhook_subscription_paused: set WC subscription #' . $wc_sub->get_id() . ' on-hold.' );
	}

	/**
	 * Handle customer.subscription.resumed.
	 *
	 * @param array $subscription Stripe subscription resource.
	 * @param array $event        Full event.
	 */
	public function webhook_subscription_resumed( $subscription, $event ) {
		$id = isset( $subscription['id'] ) ? $subscription['id'] : '';
		RPSFW_Gateway_Stripe::log( 'webhook_subscription_resumed: handler fired for ' . $id );
		$wc_sub = $this->find_wc_subscription_by_stripe_id( $id );
		if ( ! $wc_sub || $wc_sub->has_status( 'active' ) ) {
			return;
		}
		remove_action( 'woocommerce_subscription_status_active', array( $this, 'resume_stripe_subscription' ) );
		$wc_sub->update_status( 'active', __( 'Stripe reported subscription resumed.', 'restore-paypal-standard-for-woocommerce' ) );
		add_action( 'woocommerce_subscription_status_active', array( $this, 'resume_stripe_subscription' ) );
	}

	// -----------------------------------------------------------------------
	//  Change-payment-method flow.
	//
	//  My Account → Subscriptions → Change Payment posts to our gateway's
	//  process_payment with $is_request_to_change_payment = true and a $0
	//  total. We need to:
	//   1. Create a SetupIntent for the existing Stripe customer.
	//   2. JS confirms it with the Payment Element (collects new card,
	//      handles SCA).
	//   3. Server attaches the new pm to the customer, sets it as default
	//      on the Stripe subscription + on customer.invoice_settings, and
	//      writes meta back onto the WC subscription.
	// -----------------------------------------------------------------------

	/**
	 * True when this is a WC Subscriptions "change payment method" request.
	 *
	 * @return bool
	 */
	public static function is_change_payment_method_request() {
		return class_exists( 'WC_Subscriptions_Change_Payment_Gateway' )
			&& ! empty( WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment );
	}

	/**
	 * AJAX: create a SetupIntent for the change-payment-method flow.
	 *
	 * Returns a client secret in setup mode so the JS can mount the
	 * Payment Element with a SetupIntent and collect the new card.
	 */
	public function ajax_create_setup_intent() {
		check_ajax_referer( 'rpsfw-stripe-create-setup-intent', 'nonce' );

		try {
			if ( ! is_user_logged_in() ) {
				throw new Exception( __( 'You must be logged in to change your subscription payment method.', 'restore-paypal-standard-for-woocommerce' ) );
			}

			$subscription_id = isset( $_POST['subscription_id'] ) ? absint( $_POST['subscription_id'] ) : 0;
			if ( ! $subscription_id ) {
				throw new Exception( __( 'Missing subscription id.', 'restore-paypal-standard-for-woocommerce' ) );
			}

			$subscription = function_exists( 'wcs_get_subscription' ) ? wcs_get_subscription( $subscription_id ) : null;
			if ( ! $subscription ) {
				throw new Exception( __( 'Subscription not found.', 'restore-paypal-standard-for-woocommerce' ) );
			}

			// Verify ownership.
			if ( get_current_user_id() !== (int) $subscription->get_customer_id() ) {
				throw new Exception( __( 'Permission denied.', 'restore-paypal-standard-for-woocommerce' ) );
			}

			// Reuse the customer attached to this subscription, but route it
			// through the resolver so a stale/foreign id (different account,
			// mode, or deleted) is verified and self-heals to a fresh customer
			// instead of throwing "No such customer".
			$existing_customer = $subscription->get_meta( self::META_CUSTOMER_ID );
			$user    = get_userdata( get_current_user_id() );
			$billing = $user ? array(
				'email' => $user->user_email,
				'name'  => trim( $user->first_name . ' ' . $user->last_name ),
			) : array();
			$customer_id = RPSFW_Stripe_API::get_or_create_customer( get_current_user_id(), $billing, $existing_customer );
			if ( is_wp_error( $customer_id ) ) {
				throw new Exception( $customer_id->get_error_message() );
			}

			$si = RPSFW_Stripe_API::create_setup_intent( $customer_id, array(
				'metadata' => array(
					'change_payment_for_subscription' => (string) $subscription_id,
					'wp_user_id'                      => (string) get_current_user_id(),
				),
			) );
			if ( is_wp_error( $si ) ) {
				throw new Exception( $si->get_error_message() );
			}

			// Stash on session for the subsequent process_payment call.
			WC()->session->set( 'rpsfw_stripe_change_pm_setup_intent_id', $si->id );
			WC()->session->set( 'rpsfw_stripe_change_pm_customer_id', $customer_id );
			WC()->session->set( 'rpsfw_stripe_change_pm_subscription_id', $subscription_id );

			wp_send_json_success( array(
				'client_secret'  => $si->client_secret,
				'setup_intent_id' => $si->id,
				'mode'           => 'setup',
			) );
		} catch ( Exception $e ) {
			RPSFW_Gateway_Stripe::log( 'Change PM setup-intent ajax error: ' . $e->getMessage(), 'error' );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Finalize the change-payment-method flow once the customer has
	 * authenticated the SetupIntent on the front end.
	 *
	 * @param WC_Order $order Subscription posing as an order in this flow.
	 * @return array
	 */
	private function process_change_payment_method( $order ) {
		try {
			$setup_intent_id = '';
			if ( ! empty( $_POST['rpsfw_stripe_setup_intent_id'] ) ) {
				$setup_intent_id = sanitize_text_field( wp_unslash( $_POST['rpsfw_stripe_setup_intent_id'] ) );
			} elseif ( WC()->session ) {
				$setup_intent_id = WC()->session->get( 'rpsfw_stripe_change_pm_setup_intent_id' );
			}

			if ( ! $setup_intent_id ) {
				throw new Exception( __( 'No SetupIntent found. Please try again.', 'restore-paypal-standard-for-woocommerce' ) );
			}

			$client = RPSFW_Stripe_API::get_client();
			if ( ! $client ) {
				throw new Exception( __( 'Stripe is not configured.', 'restore-paypal-standard-for-woocommerce' ) );
			}

			// Retrieve the SetupIntent and verify it succeeded.
			try {
				$si = $client->setupIntents->retrieve( $setup_intent_id, array(
					'expand' => array( 'payment_method' ),
				) );
			} catch ( Exception $e ) {
				throw new Exception( $e->getMessage() );
			}

			if ( $si->status !== 'succeeded' ) {
				throw new Exception( sprintf(
					/* translators: %s: SetupIntent status */
					__( 'Card setup did not succeed (status: %s).', 'restore-paypal-standard-for-woocommerce' ),
					$si->status
				) );
			}

			$payment_method_id = is_object( $si->payment_method ) ? $si->payment_method->id : (string) $si->payment_method;
			$customer_id       = (string) $si->customer;

			if ( ! $payment_method_id || ! $customer_id ) {
				throw new Exception( __( 'SetupIntent did not produce a payment method.', 'restore-paypal-standard-for-woocommerce' ) );
			}

			// In our flow the order id passed by WC Subs is actually the
			// subscription id. Treat the WC_Order as a subscription.
			$subscription = $order;
			if ( function_exists( 'wcs_is_subscription' ) && ! wcs_is_subscription( $subscription ) ) {
				// Sometimes WC Subs creates a temporary order; fall back
				// to looking up by session.
				$session_sub_id = WC()->session ? WC()->session->get( 'rpsfw_stripe_change_pm_subscription_id' ) : 0;
				if ( $session_sub_id && function_exists( 'wcs_get_subscription' ) ) {
					$subscription = wcs_get_subscription( $session_sub_id );
				}
			}
			if ( ! $subscription ) {
				throw new Exception( __( 'Could not resolve the subscription to update.', 'restore-paypal-standard-for-woocommerce' ) );
			}

			$stripe_subscription_id = $subscription->get_meta( self::META_SUBSCRIPTION_ID );
			if ( ! $stripe_subscription_id ) {
				throw new Exception( __( 'No Stripe subscription is associated with this WC subscription.', 'restore-paypal-standard-for-woocommerce' ) );
			}

			// Attach the pm to the customer (idempotent — Stripe ignores
			// "already attached" attaches that came from a SetupIntent).
			try {
				$client->paymentMethods->attach( $payment_method_id, array( 'customer' => $customer_id ) );
			} catch ( Exception $e ) {
				if ( false === stripos( $e->getMessage(), 'already' ) ) {
					throw $e;
				}
			}

			// Update the customer's default invoice payment method so any
			// invoice-level fallback also uses this card. Belt and braces.
			try {
				$client->customers->update( $customer_id, array(
					'invoice_settings' => array(
						'default_payment_method' => $payment_method_id,
					),
				) );
			} catch ( Exception $e ) {
				RPSFW_Gateway_Stripe::log( 'Change PM: customer update warning - ' . $e->getMessage(), 'warning' );
			}

			// Set the new card as the subscription's default_payment_method.
			$updated = RPSFW_Stripe_API::update_subscription( $stripe_subscription_id, array(
				'default_payment_method' => $payment_method_id,
			) );
			if ( is_wp_error( $updated ) ) {
				throw new Exception( $updated->get_error_message() );
			}

			// Limited-coupon subscriptions are managed by a Subscription
			// Schedule whose default_settings carry the payment method and are
			// re-applied to the subscription at each phase transition. Update
			// the schedule too, or the next phase boundary would revert to the
			// old card. Best-effort: a released schedule rejects the update, in
			// which case the subscription-level default (set above) applies.
			$schedule_id = $subscription->get_meta( self::META_SCHEDULE_ID );
			if ( $schedule_id ) {
				RPSFW_Stripe_API::update_schedule_default_payment_method( $schedule_id, $payment_method_id );
			}

			// Persist on the WC subscription.
			$subscription->update_meta_data( self::META_PAYMENT_METHOD, $payment_method_id );
			$subscription->update_meta_data( self::META_CUSTOMER_ID, $customer_id );
			$subscription->save();

			// Save / update the WC payment token so the saved card UI is
			// consistent.
			if ( $subscription->get_customer_id() ) {
				$this->store_payment_token( $subscription->get_customer_id(), $payment_method_id, $customer_id );
			}

			$subscription->add_order_note( sprintf(
				/* translators: %s: Stripe payment method id */
				__( 'Subscription payment method updated to %s via Stripe SetupIntent.', 'restore-paypal-standard-for-woocommerce' ),
				$payment_method_id
			) );

			if ( WC()->session ) {
				WC()->session->__unset( 'rpsfw_stripe_change_pm_setup_intent_id' );
				WC()->session->__unset( 'rpsfw_stripe_change_pm_customer_id' );
				WC()->session->__unset( 'rpsfw_stripe_change_pm_subscription_id' );
			}

			return array(
				'result'   => 'success',
				'redirect' => $subscription->get_view_order_url(),
			);
		} catch ( Exception $e ) {
			RPSFW_Gateway_Stripe::log( 'Change PM error: ' . $e->getMessage(), 'error' );
			wc_add_notice( $e->getMessage(), 'error' );
			return array( 'result' => 'failure' );
		}
	}
}
