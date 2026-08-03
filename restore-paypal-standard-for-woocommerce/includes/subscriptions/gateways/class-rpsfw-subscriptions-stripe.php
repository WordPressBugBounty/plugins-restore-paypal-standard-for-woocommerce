<?php
/**
 * Stripe glue for native subscriptions.
 *
 * Mirrors the flow of the WooCommerce Subscriptions integration
 * (class-stripe-subscriptions.php) but reads schedules from this module's
 * own product meta and stores state in rpsfw_subscription records. Stripe
 * owns the schedule: we create a Stripe Subscription (Price + Customer) at
 * checkout with payment_behavior=default_incomplete, the customer
 * authenticates the first invoice's PaymentIntent with the Payment
 * Element, and renewals flow back through invoice.* and
 * customer.subscription.* webhooks.
 *
 * The checkout surfaces register the SAME AJAX action names and consume
 * the SAME hook points as the WCS integration, so the existing checkout JS
 * (classic + blocks) works unchanged. They are only registered in native
 * mode (WCS absent), so there is never a double registration.
 *
 * Order meta uses the same keys as the WCS integration so existing
 * refund/dispute webhook resolution and admin deep links work for native
 * records too.
 *
 * @package Restore_PayPal_Standard_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RPSFW_Subscriptions_Stripe
 */
class RPSFW_Subscriptions_Stripe {

	const META_CUSTOMER_ID     = '_rpsfw_stripe_customer_id';
	const META_SUBSCRIPTION_ID = '_rpsfw_stripe_subscription_id';
	const META_PRICE_ID        = '_rpsfw_stripe_price_id';
	const META_INVOICE_ID      = '_rpsfw_stripe_invoice_id';
	const META_PAYMENT_INTENT  = '_rpsfw_stripe_payment_intent_id';
	const META_CHARGE_ID       = '_rpsfw_stripe_charge_id';
	const META_CHARGE_AMOUNT   = '_rpsfw_stripe_charge_amount';
	const META_CHARGE_CURRENCY = '_rpsfw_stripe_charge_currency';
	const META_PROCESSED_INV   = '_rpsfw_stripe_processed_invoices';

	/**
	 * Register checkout surfaces. Native mode only (loader-gated).
	 */
	public static function init_checkout() {
		add_action( 'wp_ajax_rpsfw_stripe_create_subscription', array( __CLASS__, 'ajax_create_subscription' ) );
		add_action( 'wp_ajax_nopriv_rpsfw_stripe_create_subscription', array( __CLASS__, 'ajax_create_subscription' ) );

		add_filter( 'rpsfw_stripe_process_payment_override', array( __CLASS__, 'maybe_override_process_payment' ), 10, 2 );
		add_filter( 'rpsfw_stripe_finalize_order_override', array( __CLASS__, 'maybe_finalize_order' ), 10, 2 );
	}

	/**
	 * Register webhook servicing. Handlers self-scope to native records so
	 * they coexist with the WCS integration.
	 */
	public static function init_webhooks() {
		add_action( 'rpsfw_stripe_webhook_invoice_payment_succeeded', array( __CLASS__, 'webhook_invoice_payment_succeeded' ), 20, 2 );
		add_action( 'rpsfw_stripe_webhook_invoice_payment_failed', array( __CLASS__, 'webhook_invoice_payment_failed' ), 20, 2 );
		add_action( 'rpsfw_stripe_webhook_invoice_payment_action_required', array( __CLASS__, 'webhook_invoice_payment_action_required' ), 20, 2 );
		add_action( 'rpsfw_stripe_webhook_customer_subscription_deleted', array( __CLASS__, 'webhook_subscription_deleted' ), 20, 2 );
		add_action( 'rpsfw_stripe_webhook_customer_subscription_updated', array( __CLASS__, 'webhook_subscription_updated' ), 20, 2 );
		add_action( 'rpsfw_stripe_webhook_customer_subscription_paused', array( __CLASS__, 'webhook_subscription_paused' ), 20, 2 );
		add_action( 'rpsfw_stripe_webhook_customer_subscription_resumed', array( __CLASS__, 'webhook_subscription_resumed' ), 20, 2 );
	}

	/**
	 * Log through the gateway logger.
	 *
	 * @param string $message Message.
	 * @param string $level   Level.
	 */
	private static function log( $message, $level = 'info' ) {
		if ( class_exists( 'RPSFW_Gateway_Stripe' ) ) {
			RPSFW_Gateway_Stripe::log( 'Native subscriptions: ' . $message, $level );
		}
	}

	/**
	 * Get the Stripe gateway instance.
	 *
	 * @return RPSFW_Gateway_Stripe|false
	 */
	private static function get_gateway() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return false;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways['rpsfw_stripe'] ) ? $gateways['rpsfw_stripe'] : false;
	}

	// -----------------------------------------------------------------
	// Checkout: create the Stripe subscription.
	// -----------------------------------------------------------------

	/**
	 * AJAX: create (or reuse) a draft Stripe Subscription for the current
	 * native cart and return the client secret the Payment Element needs.
	 * Response shape matches the WCS integration's endpoint.
	 */
	public static function ajax_create_subscription() {
		check_ajax_referer( 'rpsfw-stripe-create-subscription', 'nonce' );

		try {
			if ( ! WC()->cart || WC()->cart->is_empty() ) {
				throw new Exception( __( 'Your cart is empty.', 'restore-paypal-standard-for-woocommerce' ) );
			}
			if ( ! RPSFW_Subscriptions_Cart::cart_contains_subscription() ) {
				throw new Exception( __( 'No subscription in cart.', 'restore-paypal-standard-for-woocommerce' ) );
			}

			// NOTE: the existing-account-email guard is intentionally NOT here.
			// This endpoint runs when the Payment Element MOUNTS (page load),
			// not when Place Order is clicked, so blocking here would surface
			// the notice prematurely (and would stop the element mounting,
			// which in turn blocks the deferred order-first submit). The check
			// runs at Place Order time instead: the deferred checkout submit
			// goes through WooCommerce's own validation, where
			// RPSFW_Subscriptions_Cart::validate_checkout_account() (classic)
			// and validate_store_api_account() (block) raise the notice.

			WC()->cart->calculate_totals();

			$signature = RPSFW_Subscriptions_Cart::build_cart_signature();
			if ( ! $signature ) {
				throw new Exception( __( 'Could not derive subscription details from the cart.', 'restore-paypal-standard-for-woocommerce' ) );
			}

			// Multiple subscription products: authenticate the card once
			// with a SetupIntent; the individual Stripe subscriptions are
			// created off-session in process_payment, so no incomplete
			// drafts are ever orphaned. Sends the response and exits.
			if ( ! empty( $signature['sub_count'] ) && $signature['sub_count'] > 1 ) {
				self::create_multi_subscription_setup( $signature );
				return;
			}

			// Single-subscription flow below; drop any stale multi-cart
			// session state from a previous cart composition.
			if ( WC()->session ) {
				WC()->session->__unset( 'rpsfw_stripe_pending_native_multi' );
			}

			$signature_hash = md5( (string) wp_json_encode( $signature ) );

			// Draft reuse: the front end may send back the subscription id it
			// already holds (survives across page loads); the cart signature
			// in the draft's metadata decides reuse vs replace.
			//
			// The Stripe customer id is NEVER trusted from the client. For a
			// guest it is taken only from the server-side session, or derived
			// below from the customer attached to a signature-matched draft as
			// reported by Stripe. This prevents a guest from binding checkout
			// to an arbitrary Stripe customer via a posted cus_ id.
			$posted_existing_sub = isset( $_POST['existing_subscription_id'] ) ? sanitize_text_field( wp_unslash( $_POST['existing_subscription_id'] ) ) : '';

			$session_sub = WC()->session ? (string) WC()->session->get( 'rpsfw_stripe_pending_subscription_id' ) : '';
			$session_cus = WC()->session ? (string) WC()->session->get( 'rpsfw_stripe_pending_customer_id' ) : '';

			$candidate_sub     = ( $posted_existing_sub && 0 === strpos( $posted_existing_sub, 'sub_' ) ) ? $posted_existing_sub : $session_sub;
			$reuse_customer_id = ( $session_cus && 0 === strpos( $session_cus, 'cus_' ) ) ? $session_cus : '';

			if ( $candidate_sub ) {
				$existing = RPSFW_Stripe_API::retrieve_subscription(
					$candidate_sub,
					array( 'expand' => array( 'latest_invoice.confirmation_secret', 'pending_setup_intent' ) )
				);
				if ( ! is_wp_error( $existing ) ) {
					$existing_sig = ( isset( $existing->metadata ) && isset( $existing->metadata->rpsfw_cart_signature ) )
						? (string) $existing->metadata->rpsfw_cart_signature
						: '';
					$existing_cus = is_object( $existing->customer ) ? $existing->customer->id : (string) $existing->customer;

					if ( in_array( $existing->status, array( 'incomplete', 'trialing' ), true ) && '' !== $existing_sig && $existing_sig === $signature_hash ) {
						self::log( 'Reusing draft subscription ' . $candidate_sub . ' (cart unchanged).' );
						if ( WC()->session ) {
							WC()->session->set( 'rpsfw_stripe_pending_subscription_id', $existing->id );
							WC()->session->set( 'rpsfw_stripe_pending_customer_id', $existing_cus );
						}
						self::send_subscription_intent_response( $existing, $existing_cus );
						return; // send_subscription_intent_response() exits.
					}

					if ( 'incomplete' === $existing->status ) {
						self::log( 'Cart changed — cancelling stale draft subscription ' . $candidate_sub . '.' );
						RPSFW_Stripe_API::cancel_subscription( $candidate_sub );
					}
					if ( '' === $reuse_customer_id && $existing_cus && 0 === strpos( $existing_cus, 'cus_' ) ) {
						$reuse_customer_id = $existing_cus;
					}
				}
			}

			if ( WC()->session ) {
				WC()->session->__unset( 'rpsfw_stripe_pending_subscription_id' );
			}

			// Stripe customer.
			$customer = WC()->customer;
			$billing  = array(
				'email' => $customer ? $customer->get_billing_email() : '',
				'name'  => $customer ? trim( $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() ) : '',
				'phone' => $customer ? $customer->get_billing_phone() : '',
			);
			if ( $customer ) {
				$use_shipping        = ( '' !== $customer->get_shipping_address_1() && '' !== $customer->get_shipping_country() );
				$billing['shipping'] = $use_shipping
					? array(
						'name'      => trim( $customer->get_shipping_first_name() . ' ' . $customer->get_shipping_last_name() ),
						'address_1' => $customer->get_shipping_address_1(),
						'address_2' => $customer->get_shipping_address_2(),
						'city'      => $customer->get_shipping_city(),
						'state'     => $customer->get_shipping_state(),
						'postcode'  => $customer->get_shipping_postcode(),
						'country'   => $customer->get_shipping_country(),
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
				$customer_id = $reuse_customer_id;
			} else {
				$customer_id = RPSFW_Stripe_API::get_or_create_customer( get_current_user_id(), $billing );
				if ( is_wp_error( $customer_id ) ) {
					throw new Exception( $customer_id->get_error_message() );
				}
			}

			// Reusable Price for the recurring schedule.
			$price_id = RPSFW_Stripe_API::find_or_create_price(
				array(
					'amount'         => $signature['recurring_amount'],
					'currency'       => $signature['currency'],
					'interval'       => $signature['period'],
					'interval_count' => $signature['interval'],
					'product_name'   => $signature['plan_name'],
				)
			);
			if ( is_wp_error( $price_id ) ) {
				throw new Exception( $price_id->get_error_message() );
			}

			// Reconcile the first invoice to WooCommerce's "due today" total:
			// Stripe would bill (recurring + sign-up fee) or just the fee on a
			// trial; coupons may make WooCommerce charge less today.
			$non_subscription_amount = (float) $signature['non_subscription_amount'];
			$expected_first          = ( $signature['trial_days'] > 0 ) ? 0.0 : (float) $signature['recurring_amount'];
			$expected_first         += (float) $signature['signup_fee'];
			$initial_discount        = round( $expected_first - ( (float) $signature['initial_total'] - $non_subscription_amount ), 2 );
			if ( $initial_discount < 0 ) {
				$initial_discount = 0.0;
			}

			$sub = RPSFW_Stripe_API::create_subscription(
				array(
					'customer'                => $customer_id,
					'price_id'                => $price_id,
					'trial_days'              => $signature['trial_days'],
					'signup_fee'              => $signature['signup_fee'],
					'signup_currency'         => $signature['currency'],
					'signup_label'            => sprintf(
						/* translators: %s: subscription product name */
						__( 'Sign-up fee for %s', 'restore-paypal-standard-for-woocommerce' ),
						$signature['plan_name']
					),
					'initial_discount'        => $initial_discount,
					'non_subscription_amount' => $non_subscription_amount,
					'non_subscription_label'  => __( 'One-time product(s)', 'restore-paypal-standard-for-woocommerce' ),
					'metadata'                => array_merge(
						array(
							'site_url'             => get_site_url(),
							'rpsfw_cart_signature' => $signature_hash,
							'rpsfw_native'         => 'yes',
						),
						RPSFW_Stripe_API::build_line_item_metadata( WC()->cart, null )
					),
				)
			);
			if ( is_wp_error( $sub ) ) {
				throw new Exception( $sub->get_error_message() );
			}

			WC()->session->set( 'rpsfw_stripe_pending_subscription_id', $sub->id );
			WC()->session->set( 'rpsfw_stripe_pending_customer_id', $customer_id );
			WC()->session->set( 'rpsfw_stripe_pending_price_id', $price_id );

			self::send_subscription_intent_response( $sub, $customer_id );
		} catch ( Exception $e ) {
			self::log( 'ajax_create_subscription error: ' . $e->getMessage(), 'error' );
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * Send the AJAX response carrying the client secret for the Payment
	 * Element. Same shape as the WCS integration. Exits.
	 *
	 * @param \Stripe\Subscription $sub         Subscription (latest_invoice.confirmation_secret + pending_setup_intent expanded).
	 * @param string               $customer_id Stripe customer id.
	 * @throws Exception When a fallback SetupIntent cannot be created.
	 */
	private static function send_subscription_intent_response( $sub, $customer_id ) {
		$response = array(
			'subscription_id' => $sub->id,
			'customer_id'     => $customer_id,
		);

		$invoice_client_secret = RPSFW_Stripe_API::get_first_invoice_client_secret( $sub );
		if ( ! empty( $invoice_client_secret ) ) {
			$response['client_secret']     = $invoice_client_secret;
			$response['payment_intent_id'] = '';
			$response['mode']              = 'payment';
		} elseif ( ! empty( $sub->pending_setup_intent ) ) {
			$response['client_secret']   = $sub->pending_setup_intent->client_secret;
			$response['setup_intent_id'] = $sub->pending_setup_intent->id;
			$response['mode']            = 'setup';
		} else {
			$si = RPSFW_Stripe_API::create_setup_intent(
				$customer_id,
				array(
					// create_setup_intent() defaults to card-only (it was written
					// for the change-payment-method flow). This is a subscription
					// checkout, so offer what the subscription itself accepts —
					// card plus Link when enabled. An intent that omits 'link'
					// hides Link in the Payment Element entirely.
					'payment_method_types' => RPSFW_Stripe_API::subscription_payment_method_types(),
					'metadata'             => array_merge(
						array(
							'stripe_subscription_id' => $sub->id,
							'site_url'               => get_site_url(),
						),
						RPSFW_Stripe_API::get_relay_metadata()
					),
				)
			);
			if ( is_wp_error( $si ) ) {
				throw new Exception( $si->get_error_message() );
			}
			$response['client_secret']   = $si->client_secret;
			$response['setup_intent_id'] = $si->id;
			$response['mode']            = 'setup';
		}

		wp_send_json_success( $response );
	}

	/**
	 * Multi-subscription carts: collect + verify the card once with a
	 * SetupIntent (the documented Stripe pattern for many subscriptions
	 * from one checkout). The subscriptions themselves are created
	 * off-session in process_payment after the order is placed.
	 *
	 * Sends the JSON response itself; throws on error (caught by caller).
	 *
	 * @param array $signature Multi-item cart signature.
	 * @throws Exception
	 */
	private static function create_multi_subscription_setup( $signature ) {
		// Cancel any stale single-subscription draft left over from an
		// earlier cart composition so it never lingers as an orphan.
		if ( WC()->session ) {
			$stale = WC()->session->get( 'rpsfw_stripe_pending_subscription_id' );
			if ( $stale ) {
				RPSFW_Stripe_API::cancel_subscription( $stale );
				WC()->session->__unset( 'rpsfw_stripe_pending_subscription_id' );
			}
		}

		$customer    = function_exists( 'WC' ) ? WC()->customer : null;
		$billing     = array(
			'email' => $customer ? $customer->get_billing_email() : '',
			'name'  => $customer ? trim( $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name() ) : '',
			'phone' => $customer ? $customer->get_billing_phone() : '',
		);
		$customer_id = RPSFW_Stripe_API::get_or_create_customer( get_current_user_id(), $billing );
		if ( is_wp_error( $customer_id ) ) {
			throw new Exception( $customer_id->get_error_message() );
		}

		$si = RPSFW_Stripe_API::create_setup_intent(
			$customer_id,
			array(
				// Multi-subscription carts authenticate once here and the
				// subscriptions are created off-session at finalize. The
				// subscriptions themselves already accept Link (see
				// payment_settings in RPSFW_Stripe_API::create_subscription), so
				// the intent that COLLECTS the method has to offer it too —
				// otherwise Link is invisible on 2+ subscription carts while
				// working everywhere else.
				'payment_method_types' => RPSFW_Stripe_API::subscription_payment_method_types(),
				'metadata'             => array_merge(
					array(
						'rpsfw_native_multi_subs' => '1',
						'site_url'                => get_site_url(),
					),
					RPSFW_Stripe_API::get_relay_metadata()
				),
			)
		);
		if ( is_wp_error( $si ) ) {
			throw new Exception( $si->get_error_message() );
		}

		if ( WC()->session ) {
			WC()->session->set( 'rpsfw_stripe_pending_customer_id', $customer_id );
			WC()->session->set( 'rpsfw_stripe_pending_native_multi', $signature );
			WC()->session->set( 'rpsfw_stripe_pending_setup_intent_id', $si->id );
		}

		wp_send_json_success(
			array(
				'client_secret'   => $si->client_secret,
				'setup_intent_id' => $si->id,
				'customer_id'     => $customer_id,
				'mode'            => 'setup',
			)
		);
	}

	/**
	 * Finalize a multi-subscription order: create one Stripe subscription
	 * per subscription product, off-session, against the authenticated
	 * payment method, then create one local record per Stripe
	 * subscription.
	 *
	 * @param WC_Order          $order   Order.
	 * @param RPSFW_Gateway_Stripe $gateway Gateway.
	 * @return array process_payment result.
	 * @throws Exception On any failure (created subs are rolled back).
	 */
	private static function finalize_multi_subscriptions( $order, $gateway ) {
		// The order's snapshot (stamped at order creation) is authoritative;
		// the session copy is only a fallback for flows without a snapshot.
		$signature   = RPSFW_Subscriptions_Cart::get_order_signature( $order );
		$customer_id = WC()->session ? WC()->session->get( 'rpsfw_stripe_pending_customer_id' ) : '';
		if ( empty( $signature ) || empty( $signature['items'] ) ) {
			$signature = WC()->session ? WC()->session->get( 'rpsfw_stripe_pending_native_multi' ) : null;
		}
		if ( empty( $signature ) || empty( $signature['items'] ) ) {
			throw new Exception( __( 'Could not derive subscription details from the cart.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		// Resolve the authenticated payment method from the SetupIntent.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce checkout request.
		$setup_intent_id = ! empty( $_POST['rpsfw_stripe_setup_intent_id'] )
			? sanitize_text_field( wp_unslash( $_POST['rpsfw_stripe_setup_intent_id'] ) )
			: ( WC()->session ? WC()->session->get( 'rpsfw_stripe_pending_setup_intent_id' ) : '' );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( empty( $setup_intent_id ) ) {
			throw new Exception( __( 'Card was not authenticated. Please try again.', 'restore-paypal-standard-for-woocommerce' ) );
		}
		$si = RPSFW_Stripe_API::retrieve_setup_intent( $setup_intent_id );
		if ( is_wp_error( $si ) ) {
			throw new Exception( $si->get_error_message() );
		}
		$payment_method_id = ! empty( $si->payment_method )
			? ( is_object( $si->payment_method ) ? $si->payment_method->id : (string) $si->payment_method )
			: '';
		if ( empty( $payment_method_id ) ) {
			throw new Exception( __( 'No payment method was saved during authentication. Please try again.', 'restore-paypal-standard-for-woocommerce' ) );
		}
		if ( empty( $customer_id ) && ! empty( $si->customer ) ) {
			$customer_id = is_object( $si->customer ) ? $si->customer->id : (string) $si->customer;
		}

		// Cart-wide first-charge reconciliation: coupons/discounts on the
		// first subscription's invoice so the aggregate "due today" matches
		// WooCommerce.
		$non_sub          = isset( $signature['non_subscription_amount'] ) ? (float) $signature['non_subscription_amount'] : 0.0;
		$expected_initial = isset( $signature['expected_sub_initial'] ) ? (float) $signature['expected_sub_initial'] : 0.0;
		$initial_discount = round( $expected_initial - ( (float) $signature['initial_total'] - $non_sub ), 2 );
		if ( $initial_discount < 0 ) {
			$initial_discount = 0.0;
		}

		$created  = array(); // [ [ 'sub' => obj, 'item' => signature item ], ... ]
		$is_first = true;
		try {
			foreach ( $signature['items'] as $item ) {
				$price_id = RPSFW_Stripe_API::find_or_create_price(
					array(
						'amount'         => $item['recurring_amount'],
						'currency'       => $signature['currency'],
						'interval'       => $item['period'],
						'interval_count' => $item['interval'],
						'product_name'   => $item['plan_name'],
					)
				);
				if ( is_wp_error( $price_id ) ) {
					throw new Exception( $price_id->get_error_message() );
				}

				$sub_args = array(
					'customer'               => $customer_id,
					'price_id'               => $price_id,
					'trial_days'             => $item['trial_days'],
					'signup_fee'             => $item['signup_fee'],
					'signup_currency'        => $signature['currency'],
					'signup_label'           => sprintf(
						/* translators: %s: subscription product name */
						__( 'Sign-up fee for %s', 'restore-paypal-standard-for-woocommerce' ),
						$item['plan_name']
					),
					// Charge the first invoice immediately with the
					// already-authenticated card; fail loudly otherwise.
					'default_payment_method' => $payment_method_id,
					'off_session'            => true,
					'payment_behavior'       => 'error_if_incomplete',
					'metadata'               => array(
						'site_url'     => get_site_url(),
						'rpsfw_native' => 'yes',
					),
				);

				// One-time products + the cart-wide reconciliation discount
				// ride on the FIRST subscription's first invoice only.
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
				$created[] = array(
					'sub'  => $sub,
					'item' => $item,
				);
			}
		} catch ( Exception $e ) {
			// Roll back so the buyer never pays for a partial order.
			foreach ( $created as $c ) {
				if ( ! empty( $c['sub']->id ) ) {
					RPSFW_Stripe_API::cancel_subscription( $c['sub']->id );
				}
			}
			throw $e;
		}

		// Parent order meta: primary subscription + customer; stamp every
		// initial PaymentIntent with the order id for refund resolution.
		$primary = $created[0]['sub'];
		$order->update_meta_data( self::META_SUBSCRIPTION_ID, $primary->id );
		$order->update_meta_data( self::META_CUSTOMER_ID, $customer_id );
		$order->set_transaction_id( $primary->id );

		// One-time sync of the Stripe customer's name/email/phone from the
		// now-final order billing details — the draft customer created
		// earlier (before checkout fields were filled in) may still be
		// "Unnamed". Done once here rather than on every draft recreation.
		self::sync_customer_from_order( $customer_id, $order );

		$processed      = (array) $order->get_meta( self::META_PROCESSED_INV );
		$refs_by_sub    = array();
		$amounts_by_sub = array();
		foreach ( $created as $c ) {
			$invoice = ( ! empty( $c['sub']->latest_invoice ) && is_object( $c['sub']->latest_invoice ) ) ? $c['sub']->latest_invoice : null;
			$invoice_id = $invoice && isset( $invoice->id )
				? $invoice->id
				: ( ! empty( $c['sub']->latest_invoice ) && is_string( $c['sub']->latest_invoice ) ? $c['sub']->latest_invoice : '' );
			if ( ! $invoice_id ) {
				continue;
			}
			if ( ! in_array( $invoice_id, $processed, true ) ) {
				$processed[] = $invoice_id;
			}

			// Capture the amount actually charged for this subscription's first
			// invoice straight from the (already expanded) invoice object, so
			// the admin refund panel can render the row from local data without
			// a Stripe API round-trip.
			if ( $invoice ) {
				$inv_currency = isset( $invoice->currency ) ? strtoupper( $invoice->currency ) : strtoupper( get_woocommerce_currency() );
				$inv_minor    = isset( $invoice->amount_paid ) ? (int) $invoice->amount_paid
					: ( isset( $invoice->total ) ? (int) $invoice->total : ( isset( $invoice->amount_due ) ? (int) $invoice->amount_due : 0 ) );
				$amounts_by_sub[ $c['sub']->id ] = array(
					'amount'   => (float) RPSFW_Stripe_API::format_stripe_amount( $inv_minor, $inv_currency ),
					'currency' => $inv_currency,
				);
			}

			$refs = RPSFW_Stripe_API::get_invoice_payment_refs( $invoice_id );
			$refs_by_sub[ $c['sub']->id ] = $refs;
			if ( ! empty( $refs['payment_intent'] ) ) {
				RPSFW_Stripe_API::stamp_order_on_payment_intent( $refs['payment_intent'], $order );
			}
		}
		$order->update_meta_data( self::META_PROCESSED_INV, $processed );
		$order->save();

		$sub_ids = array();
		foreach ( $created as $c ) {
			$sub_ids[] = $c['sub']->id;
		}
		// See the note in maybe_override_process_payment(): re-check the stored
		// status so a webhook that already completed the order does not get a
		// second transition (and a second customer email) out of this call.
		if ( rpsfw_order_still_needs_payment( $order ) ) {
			$order->payment_complete( $primary->id );
			$order->add_order_note(
				sprintf(
					/* translators: 1: number of subscriptions, 2: comma-separated Stripe subscription ids */
					__( 'Created %1$d Stripe subscriptions: %2$s.', 'restore-paypal-standard-for-woocommerce' ),
					count( $sub_ids ),
					implode( ', ', $sub_ids )
				)
			);
		} else {
			self::log( 'Order #' . $order->get_id() . ' was already completed (webhook) — skipping duplicate payment_complete for ' . implode( ', ', $sub_ids ) . '.' );
		}

		// One local record per Stripe subscription.
		foreach ( $created as $c ) {
			$record = RPSFW_Subscriptions_Manager::create_for_order(
				$order,
				array(
					'gateway_id'          => 'rpsfw_stripe',
					'gateway_sub_id'      => $c['sub']->id,
					'gateway_customer_id' => $customer_id,
					'status'              => 'active',
					'next_payment'        => self::extract_period_end( $c['sub'] ),
					'signature'           => array_merge( $signature, $c['item'], array( 'items' => null ) ),
				)
			);
			if ( is_wp_error( $record ) ) {
				self::log( 'Could not create subscription record for ' . $c['sub']->id . ': ' . $record->get_error_message(), 'error' );
				$order->add_order_note(
					sprintf(
						/* translators: 1: Stripe subscription id, 2: error message */
						__( 'Warning: the record for Stripe subscription %1$s could not be created (%2$s). The subscription itself is active.', 'restore-paypal-standard-for-woocommerce' ),
						$c['sub']->id,
						$record->get_error_message()
					)
				);
				continue;
			}

			// Store this subscription's first charge/PaymentIntent AND the
			// captured amount on the record so the admin refund panel resolves
			// entirely from local data (no live Stripe lookups).
			if ( isset( $refs_by_sub[ $c['sub']->id ] ) ) {
				$refs = $refs_by_sub[ $c['sub']->id ];
				if ( ! empty( $refs['charge'] ) ) {
					update_post_meta( $record->get_id(), self::META_CHARGE_ID, $refs['charge'] );
				}
				if ( ! empty( $refs['payment_intent'] ) ) {
					update_post_meta( $record->get_id(), self::META_PAYMENT_INTENT, $refs['payment_intent'] );
				}
			}
			if ( isset( $amounts_by_sub[ $c['sub']->id ] ) ) {
				update_post_meta( $record->get_id(), self::META_CHARGE_AMOUNT, $amounts_by_sub[ $c['sub']->id ]['amount'] );
				update_post_meta( $record->get_id(), self::META_CHARGE_CURRENCY, $amounts_by_sub[ $c['sub']->id ]['currency'] );
			}

			self::maybe_schedule_cancel_at( $record );
		}

		if ( WC()->session ) {
			WC()->session->__unset( 'rpsfw_stripe_pending_native_multi' );
			WC()->session->__unset( 'rpsfw_stripe_pending_setup_intent_id' );
			WC()->session->__unset( 'rpsfw_stripe_pending_customer_id' );
		}

		return array(
			'result'   => 'success',
			'redirect' => $gateway->get_return_url( $order ),
		);
	}

	// -----------------------------------------------------------------
	// Checkout: finalize.
	// -----------------------------------------------------------------

	/**
	 * Finalize a native subscription order after the customer confirmed
	 * the first invoice's PaymentIntent in the browser (deferred /
	 * order-first flow). Re-enters maybe_override_process_payment.
	 *
	 * @param array|WP_Error|null $result Prior override result.
	 * @param WC_Order            $order  Order being finalized.
	 * @return array|WP_Error|null
	 */
	public static function maybe_finalize_order( $result, $order ) {
		if ( null !== $result || ! $order instanceof WC_Order ) {
			return $result;
		}
		if ( ! RPSFW_Subscriptions_Cart::order_contains_subscription( $order ) ) {
			return $result;
		}
		if ( rpsfw_order_is_subscription_renewal( $order ) ) {
			return $result;
		}

		$outcome = self::maybe_override_process_payment( null, $order->get_id() );

		if ( is_array( $outcome ) && ! empty( $outcome['rpsfw_stripe_confirm'] ) ) {
			return new WP_Error( 'rpsfw_stripe_not_confirmed', __( 'Your payment was not completed. Please try again.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		if ( is_array( $outcome ) && isset( $outcome['result'] ) && 'success' === $outcome['result'] ) {
			if ( WC()->cart ) {
				WC()->cart->empty_cart();
			}
			return array(
				'redirect' => isset( $outcome['redirect'] ) ? $outcome['redirect'] : $order->get_checkout_order_received_url(),
			);
		}

		return new WP_Error( 'rpsfw_stripe_finalize_failed', __( 'We could not complete your subscription. Please try again.', 'restore-paypal-standard-for-woocommerce' ) );
	}

	/**
	 * Route process_payment for native subscription orders.
	 *
	 * @param array|null $result   Prior override result.
	 * @param int        $order_id Order id.
	 * @return array|null
	 */
	public static function maybe_override_process_payment( $result, $order_id ) {
		if ( null !== $result ) {
			return $result;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! RPSFW_Subscriptions_Cart::order_contains_subscription( $order ) ) {
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
			// Multi-subscription orders: the card was authenticated once via
			// a SetupIntent; create the separate Stripe subscriptions
			// off-session now. The ORDER's own signature snapshot is the
			// authority — a stale multi-cart session key from an abandoned
			// earlier cart must never reroute a single-subscription order.
			$order_signature = RPSFW_Subscriptions_Cart::get_order_signature( $order );
			$is_multi        = ! empty( $order_signature['sub_count'] ) && (int) $order_signature['sub_count'] > 1;
			if ( ! $is_multi && empty( $order_signature )
				&& WC()->session && WC()->session->get( 'rpsfw_stripe_pending_native_multi' ) ) {
				$is_multi = true; // No snapshot at all — fall back to the session signal.
			}
			if ( $is_multi ) {
				return self::finalize_multi_subscriptions( $order, $gateway );
			}

			$subscription_id = '';
			$customer_id     = '';
			// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce checkout request.
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

			$sub = RPSFW_Stripe_API::retrieve_subscription(
				$subscription_id,
				array( 'expand' => array( 'latest_invoice.confirmation_secret', 'pending_setup_intent', 'default_payment_method' ) )
			);
			if ( is_wp_error( $sub ) ) {
				throw new Exception( $sub->get_error_message() );
			}

			$status = isset( $sub->status ) ? $sub->status : '';
			if ( ! in_array( $status, array( 'active', 'trialing', 'past_due', 'incomplete' ), true ) ) {
				throw new Exception(
					sprintf(
						/* translators: %s: Stripe subscription status */
						__( 'Stripe subscription is not active (status: %s).', 'restore-paypal-standard-for-woocommerce' ),
						$status
					)
				);
			}

			$stripe_customer = ! empty( $sub->customer ) ? ( is_object( $sub->customer ) ? $sub->customer->id : (string) $sub->customer ) : $customer_id;

			// Deferred confirmation (order-first flow): nothing charged yet.
			// Stamp link meta, keep the order pending, and hand back the
			// marker so the checkout JS confirms the PaymentIntent and then
			// calls the finalize endpoint (which re-enters this method).
			if ( 'incomplete' === $status ) {
				$order->update_meta_data( self::META_SUBSCRIPTION_ID, $sub->id );
				if ( $stripe_customer ) {
					$order->update_meta_data( self::META_CUSTOMER_ID, $stripe_customer );
				}
				$order->set_transaction_id( $sub->id );
				$order->update_status( 'pending', __( 'Awaiting Stripe payment confirmation.', 'restore-paypal-standard-for-woocommerce' ) );
				$order->save();

				return array(
					'result'                 => 'success',
					'rpsfw_stripe_confirm'   => 'payment',
					'rpsfw_stripe_order_id'  => $order->get_id(),
					'rpsfw_stripe_order_key' => $order->get_order_key(),
					'redirect'               => $gateway->get_return_url( $order ),
				);
			}

			// Paid (or trialing) — complete the order and create the record.
			$payment_intent_id = ! empty( $_POST['rpsfw_stripe_payment_intent_id'] )
				? sanitize_text_field( wp_unslash( $_POST['rpsfw_stripe_payment_intent_id'] ) )
				: '';
			// phpcs:enable WordPress.Security.NonceVerification.Missing

			$invoice_id = ( ! empty( $sub->latest_invoice ) && isset( $sub->latest_invoice->id ) ) ? $sub->latest_invoice->id : '';

			$order->update_meta_data( self::META_SUBSCRIPTION_ID, $sub->id );
			if ( $stripe_customer ) {
				$order->update_meta_data( self::META_CUSTOMER_ID, $stripe_customer );
				// One-time sync from the now-final order billing details — see
				// the comment on sync_customer_from_order().
				self::sync_customer_from_order( $stripe_customer, $order );
			}
			if ( $payment_intent_id ) {
				$order->update_meta_data( self::META_PAYMENT_INTENT, $payment_intent_id );
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

			// A Stripe webhook may have completed this order while the calls
			// above were in flight; completing it again from our now-stale
			// object would re-fire the customer "order processing" email.
			if ( rpsfw_order_still_needs_payment( $order ) ) {
				$order->payment_complete( $sub->id );
				$order->add_order_note(
					sprintf(
						/* translators: %s: Stripe subscription id */
						__( 'Stripe subscription created (Subscription ID: %s).', 'restore-paypal-standard-for-woocommerce' ),
						$sub->id
					)
				);
			} else {
				self::log( 'Order #' . $order->get_id() . ' was already completed (webhook) — skipping duplicate payment_complete for ' . $sub->id . '.' );
			}

			$record = self::create_record_for_order( $order, $sub, $stripe_customer );

			// Record the first invoice's charge/PaymentIntent on the order
			// AND the subscription record so the admin refund panel (and
			// Stripe-side refund webhooks) resolve without live lookups.
			if ( $invoice_id ) {
				$refs = RPSFW_Stripe_API::get_invoice_payment_refs( $invoice_id );
				if ( ! empty( $refs['charge'] ) ) {
					$order->update_meta_data( self::META_CHARGE_ID, $refs['charge'] );
					if ( $record instanceof RPSFW_Subscription ) {
						update_post_meta( $record->get_id(), self::META_CHARGE_ID, $refs['charge'] );
					}
				}
				if ( ! empty( $refs['payment_intent'] ) ) {
					if ( ! $order->get_meta( self::META_PAYMENT_INTENT ) ) {
						$order->update_meta_data( self::META_PAYMENT_INTENT, $refs['payment_intent'] );
					}
					if ( $record instanceof RPSFW_Subscription ) {
						update_post_meta( $record->get_id(), self::META_PAYMENT_INTENT, $refs['payment_intent'] );
					}
				}
				if ( ! empty( $refs['charge'] ) || ! empty( $refs['payment_intent'] ) ) {
					$order->save();
				}
			}

			if ( WC()->session ) {
				WC()->session->__unset( 'rpsfw_stripe_pending_subscription_id' );
				WC()->session->__unset( 'rpsfw_stripe_pending_customer_id' );
				WC()->session->__unset( 'rpsfw_stripe_pending_price_id' );
			}

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
	 * One-time sync of a Stripe customer's name/email/phone from the now-final
	 * order billing details, called once per checkout from a finalize path.
	 *
	 * The Stripe customer for this checkout was created earlier (when the
	 * draft subscription was set up for the Payment Element, before the buyer
	 * had necessarily filled in the checkout form), so it may still have a
	 * blank name/email at this point. Order billing is authoritative and
	 * final here, so this is the right (and only) place to backfill it —
	 * see get_or_create_customer()'s $sync_details param for why this isn't
	 * done on every draft (re)creation instead.
	 *
	 * @param string   $customer_id Stripe customer id.
	 * @param WC_Order $order       Finalized order.
	 */
	private static function sync_customer_from_order( $customer_id, $order ) {
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
	 * Create the native subscription record for a finalized order.
	 *
	 * @param WC_Order             $order           Order.
	 * @param \Stripe\Subscription $sub             Stripe subscription.
	 * @param string               $stripe_customer Stripe customer id.
	 */
	private static function create_record_for_order( $order, $sub, $stripe_customer ) {
		$record = RPSFW_Subscriptions_Manager::create_for_order(
			$order,
			array(
				'gateway_id'          => 'rpsfw_stripe',
				'gateway_sub_id'      => $sub->id,
				'gateway_customer_id' => $stripe_customer,
				'status'              => 'active',
				'next_payment'        => self::extract_period_end( $sub ),
			)
		);
		if ( is_wp_error( $record ) ) {
			self::log( 'Could not create subscription record for order #' . $order->get_id() . ': ' . $record->get_error_message(), 'error' );
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message */
					__( 'Warning: the subscription record could not be created (%s). The Stripe subscription itself is active.', 'restore-paypal-standard-for-woocommerce' ),
					$record->get_error_message()
				)
			);
			return null;
		}

		self::maybe_schedule_cancel_at( $record );

		return $record;
	}

	/**
	 * Payment-limited subscriptions: stamp cancel_at on the Stripe
	 * subscription so the end date is visible and enforced on Stripe's own
	 * dashboard ("Cancels on ..."). Stripe has no native payment-count
	 * limit, so the date is computed as trial + (number of payments ×
	 * billing interval), minus a small safety margin so Stripe cancels
	 * before it would generate one invoice too many. The local payment
	 * counter remains the backstop either way.
	 *
	 * @param RPSFW_Subscription $record Subscription record.
	 */
	private static function maybe_schedule_cancel_at( $record ) {
		if ( $record->get_length() < 1 || 'rpsfw_stripe' !== $record->get_gateway_id() ) {
			return;
		}
		// Idempotent: create_for_order can return an existing record.
		if ( get_post_meta( $record->get_id(), '_rpsfw_stripe_cancel_at', true ) ) {
			return;
		}

		$cancel_at = time();
		if ( $record->get_trial_length() > 0 ) {
			$cancel_at = strtotime( '+' . $record->get_trial_length() . ' ' . $record->get_trial_period() . 's', $cancel_at );
		}
		$cancel_at = strtotime( '+' . ( $record->get_length() * $record->get_interval() ) . ' ' . $record->get_period() . 's', $cancel_at );
		$cancel_at = $cancel_at - HOUR_IN_SECONDS;

		$result = RPSFW_Stripe_API::update_subscription( $record->get_gateway_sub_id(), array( 'cancel_at' => $cancel_at ) );
		if ( is_wp_error( $result ) ) {
			self::log( 'Could not set cancel_at on ' . $record->get_gateway_sub_id() . ': ' . $result->get_error_message(), 'warning' );
			return;
		}

		update_post_meta( $record->get_id(), '_rpsfw_stripe_cancel_at', $cancel_at );
		$record->add_note(
			sprintf(
				/* translators: 1: number of payments, 2: formatted end date */
				__( 'This subscription is limited to %1$d payments. Stripe will end it automatically around %2$s.', 'restore-paypal-standard-for-woocommerce' ),
				$record->get_length(),
				date_i18n( wc_date_format(), $cancel_at + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) )
			)
		);
	}

	/**
	 * Extract the current period end (= next billing time) from a Stripe
	 * subscription, handling both legacy (top-level current_period_end)
	 * and modern basil+ (items.data[0].current_period_end) shapes.
	 *
	 * @param \Stripe\Subscription|array $sub Subscription.
	 * @return string GMT MySQL datetime, or '' when unavailable.
	 */
	private static function extract_period_end( $sub ) {
		$sub = is_object( $sub ) && method_exists( $sub, 'toArray' ) ? $sub->toArray() : (array) $sub;

		$timestamp = 0;
		if ( ! empty( $sub['current_period_end'] ) ) {
			$timestamp = (int) $sub['current_period_end'];
		} elseif ( ! empty( $sub['items']['data'][0]['current_period_end'] ) ) {
			$timestamp = (int) $sub['items']['data'][0]['current_period_end'];
		}

		return ( $timestamp > time() ) ? gmdate( 'Y-m-d H:i:s', $timestamp ) : '';
	}

	// -----------------------------------------------------------------
	// Webhooks.
	// -----------------------------------------------------------------

	/**
	 * Resolve the Stripe subscription id from an Invoice payload (legacy
	 * top-level field or basil+ parent.subscription_details).
	 *
	 * @param array $invoice Invoice as array.
	 * @return string
	 */
	private static function get_subscription_id_from_invoice( $invoice ) {
		if ( ! empty( $invoice['subscription'] ) ) {
			$sub = $invoice['subscription'];
			return is_array( $sub ) ? ( isset( $sub['id'] ) ? $sub['id'] : '' ) : (string) $sub;
		}
		if ( ! empty( $invoice['parent']['subscription_details']['subscription'] ) ) {
			$sub = $invoice['parent']['subscription_details']['subscription'];
			return is_array( $sub ) ? ( isset( $sub['id'] ) ? $sub['id'] : '' ) : (string) $sub;
		}
		return '';
	}

	/**
	 * Safety net for the order-first flow: if the browser never called the
	 * finalize endpoint, the parent order is still pending even though
	 * Stripe activated the subscription. Complete it and create the record.
	 *
	 * @param string $stripe_sub_id Stripe subscription id.
	 */
	private static function reconcile_pending_parent( $stripe_sub_id ) {
		if ( ! $stripe_sub_id || rpsfw_get_subscription_by_gateway_id( $stripe_sub_id ) ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'status'     => array( 'pending', 'failed' ),
				'meta_key'   => self::META_SUBSCRIPTION_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $stripe_sub_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		if ( empty( $orders ) ) {
			return;
		}
		$order = $orders[0];
		if ( ! RPSFW_Subscriptions_Cart::order_contains_subscription( $order ) ) {
			return;
		}
		// Never complete an order from the other mode's account.
		if ( ! rpsfw_webhook_may_touch_order( $order ) ) {
			self::log( 'Refusing ' . rpsfw_current_webhook_mode() . ' mode event for order #' . $order->get_id() . ', which was paid in ' . rpsfw_get_order_payment_mode( $order ) . ' mode.', 'warning' );
			return;
		}

		$sub = RPSFW_Stripe_API::retrieve_subscription( $stripe_sub_id );
		if ( is_wp_error( $sub ) || ! in_array( $sub->status, array( 'active', 'trialing', 'past_due' ), true ) ) {
			return;
		}

		self::log( 'Reconciling pending parent order #' . $order->get_id() . ' for ' . $stripe_sub_id . ' via webhook.' );

		// The query above ran before the retrieve_subscription() round trip, and
		// sibling events (invoice.payment_succeeded, customer.subscription.updated)
		// reach this method in parallel requests. Re-check before completing —
		// but still fall through to the record creation below, which is
		// idempotent and must not be lost if another request only got as far as
		// completing the order.
		if ( rpsfw_order_still_needs_payment( $order ) ) {
			$order->payment_complete( $stripe_sub_id );
			$order->add_order_note( __( 'Order completed via Stripe webhook (the checkout confirmation did not reach the site).', 'restore-paypal-standard-for-woocommerce' ) );
		} else {
			self::log( 'Order #' . $order->get_id() . ' was completed by another request — skipping duplicate payment_complete.' );
		}

		$stripe_customer = ! empty( $sub->customer ) ? ( is_object( $sub->customer ) ? $sub->customer->id : (string) $sub->customer ) : '';
		self::create_record_for_order( $order, $sub, $stripe_customer );
	}

	/**
	 * invoice.payment_succeeded: renewals create paid renewal orders. The
	 * initial invoice (billing_reason=subscription_create) belongs to the
	 * parent checkout order.
	 *
	 * @param array $invoice Invoice payload.
	 * @param array $event   Full event.
	 */
	public static function webhook_invoice_payment_succeeded( $invoice, $event ) {
		$stripe_sub_id = self::get_subscription_id_from_invoice( $invoice );
		$invoice_id    = isset( $invoice['id'] ) ? $invoice['id'] : '';
		if ( ! $stripe_sub_id || ! $invoice_id ) {
			return;
		}

		$billing_reason = isset( $invoice['billing_reason'] ) ? $invoice['billing_reason'] : '';
		if ( 'subscription_create' === $billing_reason ) {
			// Initial invoice — the parent order covers it. Make sure the
			// parent got completed (order-first safety net).
			self::reconcile_pending_parent( $stripe_sub_id );
			return;
		}

		$subscription = rpsfw_get_subscription_by_gateway_id( $stripe_sub_id );
		if ( ! $subscription ) {
			return; // Not one of ours (possibly a WCS-integration subscription).
		}

		// Skip invoices already accounted for by the parent order.
		$parent = $subscription->get_parent_order();
		if ( $parent ) {
			// A renewal must come from the same account the original payment
			// was taken in.
			if ( ! rpsfw_webhook_may_touch_order( $parent ) ) {
				self::log( 'Refusing ' . rpsfw_current_webhook_mode() . ' mode renewal for subscription #' . $subscription->get_id() . '; its order was paid in ' . rpsfw_get_order_payment_mode( $parent ) . ' mode.', 'warning' );
				return;
			}
			$processed = (array) $parent->get_meta( self::META_PROCESSED_INV );
			if ( in_array( $invoice_id, $processed, true ) ) {
				return;
			}
		}

		$currency = isset( $invoice['currency'] ) ? strtoupper( $invoice['currency'] ) : $subscription->get_currency();
		$amount   = isset( $invoice['amount_paid'] )
			? (float) RPSFW_Stripe_API::format_stripe_amount( $invoice['amount_paid'], $currency )
			: $subscription->get_recurring_amount();

		$next_payment = '';
		if ( ! empty( $invoice['period_end'] ) && (int) $invoice['period_end'] > time() ) {
			$next_payment = gmdate( 'Y-m-d H:i:s', (int) $invoice['period_end'] );
		}

		$order = RPSFW_Subscriptions_Manager::record_renewal( $subscription, $amount, $invoice_id, $next_payment );

		// Stamp the renewal charge/PaymentIntent so Stripe-side refunds of
		// this renewal resolve directly to the renewal order.
		if ( $order instanceof WC_Order ) {
			$refs = RPSFW_Stripe_API::get_invoice_payment_refs( $invoice_id );
			if ( ! empty( $refs['payment_intent'] ) ) {
				$order->update_meta_data( self::META_PAYMENT_INTENT, $refs['payment_intent'] );
			}
			if ( ! empty( $refs['charge'] ) ) {
				$order->update_meta_data( self::META_CHARGE_ID, $refs['charge'] );
			}
			$order->save();
			if ( ! empty( $refs['payment_intent'] ) ) {
				RPSFW_Stripe_API::stamp_order_on_payment_intent( $refs['payment_intent'], $order );
			}
		}
	}

	/**
	 * invoice.payment_failed: note + notification. Stripe Smart Retries
	 * re-attempt automatically.
	 *
	 * @param array $invoice Invoice payload.
	 * @param array $event   Full event.
	 */
	public static function webhook_invoice_payment_failed( $invoice, $event ) {
		$subscription = rpsfw_get_subscription_by_gateway_id( self::get_subscription_id_from_invoice( $invoice ) );
		if ( ! $subscription ) {
			return;
		}
		$subscription->add_note(
			sprintf(
				/* translators: %s: Stripe invoice id */
				__( 'Stripe reported a failed renewal payment (invoice %s). Stripe will retry automatically.', 'restore-paypal-standard-for-woocommerce' ),
				isset( $invoice['id'] ) ? $invoice['id'] : ''
			)
		);
		do_action( 'rpsfw_subscription_payment_failed', $subscription );
	}

	/**
	 * invoice.payment_action_required: the renewal needs SCA. Stripe
	 * emails the customer a hosted link by default.
	 *
	 * @param array $invoice Invoice payload.
	 * @param array $event   Full event.
	 */
	public static function webhook_invoice_payment_action_required( $invoice, $event ) {
		$subscription = rpsfw_get_subscription_by_gateway_id( self::get_subscription_id_from_invoice( $invoice ) );
		if ( ! $subscription ) {
			return;
		}
		$subscription->add_note( __( 'A renewal payment requires customer authentication. Stripe will email the customer a link to complete it.', 'restore-paypal-standard-for-woocommerce' ) );
	}

	/**
	 * customer.subscription.deleted: the subscription ended on Stripe.
	 *
	 * @param array $sub   Subscription payload.
	 * @param array $event Full event.
	 */
	public static function webhook_subscription_deleted( $sub, $event ) {
		$subscription = rpsfw_get_subscription_by_gateway_id( isset( $sub['id'] ) ? $sub['id'] : '' );
		if ( ! $subscription || $subscription->has_status( array( 'cancelled', 'expired' ) ) ) {
			return;
		}
		// A payment-limited subscription ending after collecting all its
		// payments completed normally — that's "expired", not "cancelled".
		if ( $subscription->get_length() > 0 && $subscription->get_payment_count() >= $subscription->get_length() ) {
			$subscription->update_status( 'expired', __( 'All scheduled payments have been collected; the subscription has ended.', 'restore-paypal-standard-for-woocommerce' ) );
			return;
		}
		$subscription->update_status( 'cancelled', __( 'Stripe reported the subscription as cancelled.', 'restore-paypal-standard-for-woocommerce' ) );
	}

	/**
	 * customer.subscription.updated: mirror status + next payment date.
	 *
	 * @param array $sub   Subscription payload.
	 * @param array $event Full event.
	 */
	public static function webhook_subscription_updated( $sub, $event ) {
		$stripe_sub_id = isset( $sub['id'] ) ? $sub['id'] : '';
		$status        = isset( $sub['status'] ) ? $sub['status'] : '';

		// Order-first safety net when the subscription just became active.
		if ( in_array( $status, array( 'active', 'trialing' ), true ) ) {
			self::reconcile_pending_parent( $stripe_sub_id );
		}

		$subscription = rpsfw_get_subscription_by_gateway_id( $stripe_sub_id );
		if ( ! $subscription ) {
			return;
		}

		// Cancelled and expired are final - no later event may move the record
		// out of them, and a cancelled subscription has no next payment date to
		// sync either.
		if ( $subscription->has_terminal_status() ) {
			return;
		}

		// Keep the next payment date in sync.
		$period_end = self::extract_period_end( $sub );
		if ( $period_end && ! in_array( $status, array( 'canceled', 'incomplete_expired' ), true ) ) {
			$subscription->set_next_payment_date( $period_end );
		}

		$cancel_at_period_end = ! empty( $sub['cancel_at_period_end'] );

		if ( 'canceled' === $status ) {
			if ( ! $subscription->has_status( array( 'cancelled', 'expired' ) ) ) {
				if ( $subscription->get_length() > 0 && $subscription->get_payment_count() >= $subscription->get_length() ) {
					$subscription->update_status( 'expired', __( 'All scheduled payments have been collected; the subscription has ended.', 'restore-paypal-standard-for-woocommerce' ) );
				} else {
					$subscription->update_status( 'cancelled', __( 'Stripe reported the subscription as cancelled.', 'restore-paypal-standard-for-woocommerce' ) );
				}
			}
			return;
		}

		// A paused-collection subscription still reports status 'active' on
		// Stripe (pause_collection stops invoicing without changing the
		// status). Without this, the customer.subscription.updated event that
		// Stripe fires right after we pause would flip the record straight
		// back to active.
		$collection_paused = ! empty( $sub['pause_collection'] );

		if ( in_array( $status, array( 'active', 'trialing' ), true ) ) {
			if ( $collection_paused ) {
				if ( ! $subscription->has_status( 'on-hold' ) ) {
					$subscription->update_status( 'on-hold', __( 'Stripe reported the subscription as paused.', 'restore-paypal-standard-for-woocommerce' ) );
				}
			} elseif ( $cancel_at_period_end ) {
				if ( ! $subscription->has_status( 'pending-cancel' ) ) {
					$subscription->update_status( 'pending-cancel', __( 'Stripe reported the subscription will end at the close of the paid period.', 'restore-paypal-standard-for-woocommerce' ) );
				}
			} elseif ( $subscription->has_status( array( 'pending', 'on-hold', 'pending-cancel' ) ) ) {
				$subscription->update_status( 'active', __( 'Stripe reported the subscription as active.', 'restore-paypal-standard-for-woocommerce' ) );
			}
			return;
		}

		if ( 'paused' === $status && ! $subscription->has_status( 'on-hold' ) ) {
			$subscription->update_status( 'on-hold', __( 'Stripe reported the subscription as paused.', 'restore-paypal-standard-for-woocommerce' ) );
			return;
		}

		if ( 'unpaid' === $status && ! $subscription->has_status( 'on-hold' ) ) {
			$subscription->update_status( 'on-hold', __( 'Stripe reported the subscription as unpaid after exhausting payment retries.', 'restore-paypal-standard-for-woocommerce' ) );
		}
	}

	/**
	 * customer.subscription.paused.
	 *
	 * @param array $sub   Subscription payload.
	 * @param array $event Full event.
	 */
	public static function webhook_subscription_paused( $sub, $event ) {
		$subscription = rpsfw_get_subscription_by_gateway_id( isset( $sub['id'] ) ? $sub['id'] : '' );
		if ( ! $subscription || $subscription->has_status( 'on-hold' ) ) {
			return;
		}
		// Cancelled and expired are final - see has_terminal_status().
		if ( $subscription->has_terminal_status() ) {
			return;
		}
		$subscription->update_status( 'on-hold', __( 'Stripe reported the subscription as paused.', 'restore-paypal-standard-for-woocommerce' ) );
	}

	/**
	 * customer.subscription.resumed.
	 *
	 * @param array $sub   Subscription payload.
	 * @param array $event Full event.
	 */
	public static function webhook_subscription_resumed( $sub, $event ) {
		$subscription = rpsfw_get_subscription_by_gateway_id( isset( $sub['id'] ) ? $sub['id'] : '' );
		if ( ! $subscription || $subscription->has_status( 'active' ) ) {
			return;
		}
		// Cancelled and expired are final - see has_terminal_status().
		if ( $subscription->has_terminal_status() ) {
			return;
		}
		// Ignore the resumed event when collection is still paused (it can
		// fire for other transitions while pause_collection is in effect).
		if ( ! empty( $sub['pause_collection'] ) ) {
			return;
		}
		$subscription->update_status( 'active', __( 'Stripe reported the subscription as resumed.', 'restore-paypal-standard-for-woocommerce' ) );
	}

	// -----------------------------------------------------------------
	// Remote lifecycle actions (called by the manager).
	// -----------------------------------------------------------------

	/**
	 * Cancel the Stripe subscription — immediately, or at the end of the
	 * paid period.
	 *
	 * @param RPSFW_Subscription $subscription  Subscription.
	 * @param bool               $at_period_end Cancel at period end instead of now.
	 * @return true|WP_Error
	 */
	public static function remote_cancel( $subscription, $at_period_end = false ) {
		$stripe_sub_id = $subscription->get_gateway_sub_id();
		if ( ! $stripe_sub_id ) {
			return new WP_Error( 'rpsfw_stripe_no_sub_id', __( 'No Stripe subscription is linked to this record.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		if ( $at_period_end ) {
			$result = RPSFW_Stripe_API::update_subscription( $stripe_sub_id, array( 'cancel_at_period_end' => true ) );
		} else {
			$result = RPSFW_Stripe_API::cancel_subscription( $stripe_sub_id );
		}

		if ( is_wp_error( $result ) ) {
			// Cancelling an already-cancelled subscription is fine.
			if ( false !== stripos( $result->get_error_message(), 'canceled' ) ) {
				return true;
			}
			return $result;
		}
		return true;
	}

	/**
	 * Pause the Stripe subscription (pause_collection).
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 * @return true|WP_Error
	 */
	public static function remote_suspend( $subscription ) {
		$stripe_sub_id = $subscription->get_gateway_sub_id();
		if ( ! $stripe_sub_id ) {
			return new WP_Error( 'rpsfw_stripe_no_sub_id', __( 'No Stripe subscription is linked to this record.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		// Already paused on Stripe (either mechanism)? Nothing to do —
		// setting pause_collection on a status-paused subscription errors.
		$current = RPSFW_Stripe_API::retrieve_subscription( $stripe_sub_id );
		if ( ! is_wp_error( $current ) ) {
			$status = isset( $current->status ) ? $current->status : '';
			if ( 'paused' === $status || ! empty( $current->pause_collection ) ) {
				return true;
			}
		}

		$result = RPSFW_Stripe_API::pause_subscription( $stripe_sub_id );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Resume the Stripe subscription. Handles all three paused shapes:
	 * a scheduled cancellation (pending-cancel → clear
	 * cancel_at_period_end), Stripe's full-pause state (status `paused`,
	 * e.g. paused from the Stripe dashboard → dedicated resume endpoint),
	 * and paused collection (pause_collection → cleared).
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 * @return true|WP_Error
	 */
	public static function remote_resume( $subscription ) {
		$stripe_sub_id = $subscription->get_gateway_sub_id();
		if ( ! $stripe_sub_id ) {
			return new WP_Error( 'rpsfw_stripe_no_sub_id', __( 'No Stripe subscription is linked to this record.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		if ( $subscription->has_status( 'pending-cancel' ) ) {
			$result = RPSFW_Stripe_API::update_subscription( $stripe_sub_id, array( 'cancel_at_period_end' => false ) );
			return is_wp_error( $result ) ? $result : true;
		}

		// Look at Stripe's actual state to pick the right resume call.
		$current = RPSFW_Stripe_API::retrieve_subscription( $stripe_sub_id );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$status = isset( $current->status ) ? $current->status : '';

		if ( 'paused' === $status ) {
			$result = RPSFW_Stripe_API::resume_paused_subscription( $stripe_sub_id );
		} elseif ( ! empty( $current->pause_collection ) ) {
			$result = RPSFW_Stripe_API::resume_subscription( $stripe_sub_id );
		} else {
			// Nothing is paused on Stripe — treat as already resumed.
			return true;
		}
		return is_wp_error( $result ) ? $result : true;
	}
}
