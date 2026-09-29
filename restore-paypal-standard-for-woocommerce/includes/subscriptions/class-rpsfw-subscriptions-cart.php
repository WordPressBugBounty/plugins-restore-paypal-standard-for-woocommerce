<?php
/**
 * Cart and checkout handling for native subscriptions.
 *
 * Responsibilities:
 *  - Detect subscription carts (the signal consumed by the PayPal Commerce
 *    and Stripe gateways to switch into subscription mode).
 *  - Enforce cart rules: one subscription per order; optionally allow
 *    mixing with one-time products (mixed checkout setting).
 *  - Free trials: the subscription line costs $0 today (the processor
 *    starts billing after the trial) and ships nothing today.
 *  - Sign-up fees: added as a cart fee line so totals and taxes are
 *    computed by WooCommerce.
 *  - Build the "cart signature" (recurring amount, initial total, trial,
 *    schedule) that both gateway glues use to create the processor-side
 *    subscription. Works on classic and block checkout — both call the
 *    same AJAX endpoints that consume this signature server-side.
 *  - Display: renewal info on cart line items (classic + blocks via
 *    woocommerce_get_item_data), recurring total rows on classic
 *    cart/checkout totals, place-order button text.
 *  - Restrict available gateways to PayPal Commerce and Stripe for
 *    subscription carts (PayPal Standard and others cannot bill them).
 *
 * Pricing model (documented behaviour):
 *  - The product price is the recurring price. Renewals bill the recurring
 *    total = product price (incl. tax) × qty + renewal shipping (when the
 *    product ships; rated for the subscriptions alone, see
 *    get_cart_renewal_shipping()), less any recurring coupon
 *    (RPSFW_Subscriptions_Coupons). Standard coupons apply to the FIRST
 *    payment only.
 *
 * @package Restore_PayPal_Standard_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RPSFW_Subscriptions_Cart
 */
class RPSFW_Subscriptions_Cart {

	/**
	 * Order meta key holding the signature snapshot taken at checkout.
	 */
	const ORDER_SIGNATURE_META = '_rpsfw_sub_signature';

	/**
	 * Wire up. Only called in native mode.
	 */
	public static function init() {
		// Cart rules.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 10, 4 );

		// Trial products cost nothing today.
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'zero_trial_prices' ), 99 );

		// Sign-up fees as a fee line.
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'add_signup_fee' ) );

		// Free trials ship nothing today; renewals pay their own shipping.
		add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'remove_trial_items_from_packages' ), 5 );
		add_filter( 'woocommerce_shipping_free_shipping_is_available', array( __CLASS__, 'renewal_free_shipping_is_available' ), 20, 3 );

		// Line item + totals display.
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_cart_totals_after_order_total', array( __CLASS__, 'render_recurring_totals_row' ) );
		add_action( 'woocommerce_review_order_after_order_total', array( __CLASS__, 'render_recurring_totals_row' ) );

		// Place order button text (classic checkout).
		add_filter( 'woocommerce_order_button_text', array( __CLASS__, 'order_button_text' ), 20 );

		// Only PayPal Commerce and Stripe can bill native subscriptions.
		// PayPal Commerce additionally hides itself (silently) for carts
		// with more than one subscription — Stripe handles those.
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'filter_available_gateways' ), 20 );

		// A free trial with no sign-up fee costs nothing TODAY, so WooCommerce
		// sees a zero-total cart, decides no payment is needed and renders no
		// gateways at all — on both block and classic checkout. A payment
		// method still has to be collected now to bill the renewals, so say the
		// cart needs payment. Same for the order, which is what the pay page
		// and the order-received flow test.
		add_filter( 'woocommerce_cart_needs_payment', array( __CLASS__, 'cart_needs_payment' ), 10, 2 );
		add_filter( 'woocommerce_order_needs_payment', array( __CLASS__, 'order_needs_payment' ), 10, 3 );

		// Subscriptions need a customer account (the subscription is owned
		// by the customer and managed from My Account). Force account
		// creation / login at checkout for subscription carts, exactly like
		// the WooCommerce Subscriptions plugin does — even when the store
		// otherwise allows guest checkout.
		add_filter( 'woocommerce_checkout_registration_required', array( __CLASS__, 'require_registration' ) );
		add_filter( 'woocommerce_checkout_registration_enabled', array( __CLASS__, 'enable_registration' ) );

		// Guests buying a subscription with an email that already belongs to
		// an account must log in — a subscription is owned by an account and
		// can't be tied to a guest identity that shadows an existing one.
		// WooCommerce's own account-creation path is supposed to raise this,
		// but whether it does depends on the checkout type (classic vs block)
		// and the store's account settings, so we validate it explicitly on
		// BOTH surfaces for consistent behaviour.
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_checkout_account' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'validate_store_api_account' ), 10, 2 );

		// Renewal shipping: the renewals must be shippable to the customer's
		// address, and on block checkout a cart that ships nothing today still
		// needs a chosen shipping method.
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_checkout_renewal_shipping' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'prepare_store_api_renewal_shipping' ), 5, 1 );

		// Snapshot the signature onto the order at checkout so finalization
		// and record creation don't depend on the (volatile) cart/session.
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'stamp_order_signature' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'stamp_order_signature_store_api' ), 10, 1 );
	}

	// -----------------------------------------------------------------
	// Detection.
	// -----------------------------------------------------------------

	/**
	 * Whether the current cart contains a native subscription product.
	 *
	 * @return bool
	 */
	public static function cart_contains_subscription() {
		if ( ! rpsfw_native_subscriptions_active() ) {
			return false;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( isset( $cart_item['data'] ) && RPSFW_Subscription_Product::is_subscription( $cart_item['data'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get the first subscription cart item, or false.
	 *
	 * @return array|false
	 */
	public static function get_subscription_cart_item() {
		$items = self::get_subscription_cart_items();
		return $items ? reset( $items ) : false;
	}

	/**
	 * Get ALL subscription cart items (Stripe supports several per order).
	 *
	 * @return array[] Cart items.
	 */
	public static function get_subscription_cart_items() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array();
		}
		$items = array();
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( isset( $cart_item['data'] ) && RPSFW_Subscription_Product::is_subscription( $cart_item['data'] ) ) {
				$items[] = $cart_item;
			}
		}
		return $items;
	}

	/**
	 * Whether the cart contains MORE than one subscription product.
	 *
	 * Stripe handles these (one Stripe subscription per product); PayPal
	 * Commerce cannot (its Subscriptions API bills one plan per approval),
	 * so its gateway hides itself at checkout for such carts.
	 *
	 * @return bool
	 */
	public static function cart_contains_multiple_subscriptions() {
		if ( ! rpsfw_native_subscriptions_active() ) {
			return false;
		}
		return count( self::get_subscription_cart_items() ) > 1;
	}

	// -----------------------------------------------------------------
	// Cart rules.
	// -----------------------------------------------------------------

	/**
	 * Cart rules. Multiple subscriptions per order are supported (Stripe
	 * creates one Stripe subscription per product; PayPal Commerce hides
	 * itself for multi-subscription carts). The mixed-checkout setting only
	 * controls whether subscriptions and one-time products can share an
	 * order.
	 *
	 * @param bool $passed       Validation state.
	 * @param int  $product_id   Product id.
	 * @param int  $quantity     Quantity.
	 * @param int  $variation_id Variation id.
	 * @return bool
	 */
	public static function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0 ) {
		if ( ! $passed || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $passed;
		}

		$mixed_allowed = 'yes' === rpsfw_subscriptions_get_setting( 'mixed_checkout' );
		if ( $mixed_allowed ) {
			return $passed;
		}

		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product ) {
			return $passed;
		}

		$adding_subscription = RPSFW_Subscription_Product::is_subscription( $product );
		$cart_has_sub        = self::cart_contains_subscription();
		$cart_has_one_off    = false;
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( isset( $cart_item['data'] ) && ! RPSFW_Subscription_Product::is_subscription( $cart_item['data'] ) ) {
				$cart_has_one_off = true;
				break;
			}
		}

		if ( $adding_subscription && $cart_has_one_off ) {
			wc_add_notice( __( 'Subscriptions must be purchased separately from one-time products. Please complete or empty your current cart first.', 'restore-paypal-standard-for-woocommerce' ), 'error' );
			return false;
		}
		if ( ! $adding_subscription && $cart_has_sub ) {
			wc_add_notice( __( 'Your cart contains a subscription, which must be purchased separately from one-time products. Please complete that purchase first.', 'restore-paypal-standard-for-woocommerce' ), 'error' );
			return false;
		}

		return $passed;
	}

	/**
	 * Free trial: the subscription line costs $0 today. The processor
	 * begins billing the recurring price when the trial ends.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function zero_trial_prices( $cart ) {
		if ( ! $cart || ! is_object( $cart ) ) {
			return;
		}
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! isset( $cart_item['data'] ) ) {
				continue;
			}
			$product = $cart_item['data'];
			if ( RPSFW_Subscription_Product::is_subscription( $product ) && RPSFW_Subscription_Product::get_trial_length( $product ) > 0 ) {
				$product->set_price( 0 );
			}
		}
	}

	/**
	 * Add the sign-up fee as a cart fee line.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function add_signup_fee( $cart ) {
		if ( ! $cart || ! is_object( $cart ) ) {
			return;
		}

		$fee_total = 0.0;
		$taxable   = false;
		$tax_class = '';

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! isset( $cart_item['data'] ) ) {
				continue;
			}
			$product = $cart_item['data'];
			if ( ! RPSFW_Subscription_Product::is_subscription( $product ) ) {
				continue;
			}
			$signup_fee = RPSFW_Subscription_Product::get_signup_fee( $product );
			if ( $signup_fee > 0 ) {
				$qty        = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;
				$fee_total += $signup_fee * $qty;
				$taxable    = $taxable || $product->is_taxable();
				$tax_class  = $product->get_tax_class();
			}
		}

		if ( $fee_total > 0 ) {
			$cart->add_fee( __( 'Sign-up fee', 'restore-paypal-standard-for-woocommerce' ), $fee_total, $taxable, $tax_class );
		}
	}

	// -----------------------------------------------------------------
	// Shipping.
	// -----------------------------------------------------------------

	/**
	 * A free-trial subscription ships nothing today, so it comes out of
	 * today's shipping packages and only what ships now is charged for. Its
	 * shipping is charged with the renewals instead, as part of the recurring
	 * amount (see get_cart_renewal_shipping()). The cart still needs
	 * shipping, so the shipping address is still collected.
	 *
	 * @param array $packages Shipping packages.
	 * @return array
	 */
	public static function remove_trial_items_from_packages( $packages ) {
		if ( ! is_array( $packages ) ) {
			return $packages;
		}
		$changed = false;
		foreach ( $packages as $index => $package ) {
			if ( empty( $package['contents'] ) || ! is_array( $package['contents'] ) ) {
				continue;
			}
			foreach ( $package['contents'] as $key => $item ) {
				if ( ! isset( $item['data'] ) || ! RPSFW_Subscription_Product::is_subscription( $item['data'] )
					|| RPSFW_Subscription_Product::get_trial_length( $item['data'] ) < 1 ) {
					continue;
				}
				if ( isset( $packages[ $index ]['contents_cost'], $item['line_total'] ) ) {
					$packages[ $index ]['contents_cost'] -= (float) $item['line_total'];
				}
				unset( $packages[ $index ]['contents'][ $key ] );
				$changed = true;
			}
			if ( empty( $packages[ $index ]['contents'] ) ) {
				unset( $packages[ $index ] );
			}
		}
		return $changed ? array_values( $packages ) : $packages;
	}

	/**
	 * What each subscription in the cart pays for shipping on its renewals.
	 *
	 * Subscriptions renewing on the same schedule ship together, so each such
	 * group is rated as one package of its own: the subscriptions at their
	 * recurring price (after recurring coupons), to the customer's shipping
	 * address. Its method is the one the customer chose at checkout when that
	 * one also ships the renewals, otherwise the store's first method (local
	 * pickup only when nothing else is offered). The group's cost, including
	 * shipping tax, is split across its items in proportion to their
	 * recurring lines, the remainder going to the last one.
	 *
	 * @param WC_Cart $cart Cart.
	 * @return array[] Cart item key => array {
	 *     @type float  $amount  Renewal shipping incl. tax.
	 *     @type float  $display The same as the cart shows shipping (excl. tax when it shows prices excl. tax).
	 *     @type string $method  Rate id, e.g. "flat_rate:3".
	 *     @type string $label   Method name, e.g. "Flat rate".
	 * }
	 */
	public static function get_cart_renewal_shipping( $cart ) {
		if ( ! $cart instanceof WC_Cart || ! function_exists( 'wc_shipping_enabled' ) || ! wc_shipping_enabled() || ! WC()->shipping() ) {
			return array();
		}

		$customer    = WC()->customer;
		$destination = array(
			'country'   => $customer ? $customer->get_shipping_country() : '',
			'state'     => $customer ? $customer->get_shipping_state() : '',
			'postcode'  => $customer ? $customer->get_shipping_postcode() : '',
			'city'      => $customer ? $customer->get_shipping_city() : '',
			'address'   => $customer ? $customer->get_shipping_address() : '',
			'address_1' => $customer ? $customer->get_shipping_address() : '',
			'address_2' => $customer ? $customer->get_shipping_address_2() : '',
		);

		// Recurring coupons reach the renewals. One that also gives free
		// shipping and applies to every payment makes renewal shipping free;
		// renewal shipping is set at checkout and never changes, so a coupon
		// that runs out after some payments can't.
		$coupon_codes  = array();
		$free_shipping = false;
		foreach ( $cart->get_coupons() as $code => $coupon ) {
			if ( ! RPSFW_Subscriptions_Coupons::is_recurring_coupon( $coupon ) ) {
				continue;
			}
			$coupon_codes[] = (string) $code;
			if ( $coupon->get_free_shipping() && RPSFW_Subscriptions_Coupons::get_payment_limit( $coupon ) < 1 ) {
				$free_shipping = true;
			}
		}

		$discounts   = RPSFW_Subscriptions_Coupons::get_cart_recurring_discounts( $cart );
		$display_inc = $cart->display_prices_including_tax();
		$groups      = array();
		foreach ( $cart->get_cart() as $key => $cart_item ) {
			if ( empty( $cart_item['data'] ) || ! RPSFW_Subscription_Product::is_subscription( $cart_item['data'] ) ) {
				continue;
			}
			// A fresh product object: the cart's is zero-priced during a trial.
			$product_id = ! empty( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : ( isset( $cart_item['product_id'] ) ? $cart_item['product_id'] : 0 );
			$product    = $product_id ? wc_get_product( $product_id ) : false;
			if ( ! $product || ! $product->needs_shipping() ) {
				continue;
			}

			$qty      = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;
			$price    = (float) $product->get_price();
			$discount = isset( $discounts[ $key ] ) ? (float) $discounts[ $key ]['discount'] : 0.0;
			$unit     = max( 0.0, ( $price * $qty - $discount ) / $qty );

			$subtotal_ex  = (float) wc_get_price_excluding_tax( $product, array( 'qty' => $qty, 'price' => $price ) );
			$subtotal_inc = (float) wc_get_price_including_tax( $product, array( 'qty' => $qty, 'price' => $price ) );
			$total_ex     = (float) wc_get_price_excluding_tax( $product, array( 'qty' => $qty, 'price' => $unit ) );
			$total_inc    = (float) wc_get_price_including_tax( $product, array( 'qty' => $qty, 'price' => $unit ) );

			$group = implode(
				'|',
				array(
					RPSFW_Subscription_Product::get_period( $product ),
					RPSFW_Subscription_Product::get_interval( $product ),
					RPSFW_Subscription_Product::get_trial_length( $product ),
					RPSFW_Subscription_Product::get_trial_period( $product ),
				)
			);

			$groups[ $group ]['contents'][ $key ] = array_merge(
				$cart_item,
				array(
					'data'              => $product,
					'line_subtotal'     => $subtotal_ex,
					'line_subtotal_tax' => $subtotal_inc - $subtotal_ex,
					'line_total'        => $total_ex,
					'line_tax'          => $total_inc - $total_ex,
				)
			);
			$groups[ $group ]['weights'][ $key ] = $subtotal_inc;
			$groups[ $group ]['subtotal']        = ( isset( $groups[ $group ]['subtotal'] ) ? $groups[ $group ]['subtotal'] : 0.0 ) + ( $display_inc ? $subtotal_inc : $subtotal_ex );
			$groups[ $group ]['total']           = ( isset( $groups[ $group ]['total'] ) ? $groups[ $group ]['total'] : 0.0 ) + ( $display_inc ? $total_inc : $total_ex );
		}
		if ( empty( $groups ) ) {
			return array();
		}

		$result = array();
		foreach ( $groups as $group => $data ) {
			$package = array(
				'contents'        => $data['contents'],
				'contents_cost'   => array_sum( wp_list_pluck( $data['contents'], 'line_total' ) ),
				'applied_coupons' => $coupon_codes,
				'user'            => array( 'ID' => get_current_user_id() ),
				'destination'     => $destination,
				'cart_subtotal'   => $data['subtotal'],
				// Marks a renewal package (see renewal_free_shipping_is_available()).
				'rpsfw_renewal'   => array(
					'subtotal'      => round( $data['subtotal'], 2 ),
					'total'         => round( $data['total'], 2 ),
					'free_shipping' => $free_shipping,
				),
			);

			// Its own session key, so today's cached rates are left alone.
			$rated = WC()->shipping()->calculate_shipping_for_package( $package, 'rpsfw_renewal_' . md5( $group ) );
			$rate  = self::pick_renewal_rate( is_array( $rated ) && ! empty( $rated['rates'] ) ? $rated['rates'] : array(), array_keys( $data['contents'] ) );
			if ( ! $rate ) {
				continue;
			}

			$amount  = round( (float) $rate->get_cost() + array_sum( array_map( 'floatval', (array) $rate->get_taxes() ) ), 2 );
			$display = $display_inc ? $amount : round( (float) $rate->get_cost(), 2 );
			$shares  = self::split_amount( $amount, $data['weights'] );
			$shown   = self::split_amount( $display, $data['weights'] );
			foreach ( $shares as $key => $share ) {
				$result[ $key ] = array(
					'amount'  => $share,
					'display' => $shown[ $key ],
					'method'  => (string) $rate->get_id(),
					'label'   => wp_strip_all_tags( (string) $rate->get_label() ),
				);
			}
		}
		return $result;
	}

	/**
	 * Split an amount in proportion to weights, the remainder going to the
	 * last key so the shares always add up to the amount.
	 *
	 * @param float   $amount  Amount.
	 * @param float[] $weights Key => weight.
	 * @return float[] Key => share.
	 */
	private static function split_amount( $amount, $weights ) {
		$total     = array_sum( $weights );
		$allocated = 0.0;
		$keys      = array_keys( $weights );
		$last_key  = end( $keys );
		$shares    = array();
		foreach ( $weights as $key => $weight ) {
			if ( $key === $last_key ) {
				$share = round( $amount - $allocated, 2 );
			} else {
				$share      = $total > 0 ? round( $amount * ( $weight / $total ), 2 ) : 0.0;
				$allocated += $share;
			}
			$shares[ $key ] = max( 0.0, $share );
		}
		return $shares;
	}

	/**
	 * The renewal rate: the method chosen at checkout for the shipment holding
	 * these items (or for today's first shipment, when they ship nothing
	 * today) when it is offered for the renewals too, otherwise the first
	 * method that isn't local pickup, otherwise the first one.
	 *
	 * @param WC_Shipping_Rate[] $rates     Renewal rates, keyed by rate id.
	 * @param string[]           $item_keys Cart item keys in the renewal package.
	 * @return WC_Shipping_Rate|null
	 */
	private static function pick_renewal_rate( $rates, $item_keys ) {
		if ( empty( $rates ) ) {
			return null;
		}

		// Only today's shipments count: the session keeps choices made for
		// packages the cart no longer has.
		$chosen = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods', array() ) : array();
		$pick   = '';
		foreach ( WC()->shipping()->get_packages() as $index => $package ) {
			if ( empty( $chosen[ $index ] ) ) {
				continue;
			}
			if ( '' === $pick ) {
				$pick = (string) $chosen[ $index ];
			}
			if ( ! empty( $package['contents'] ) && array_intersect_key( $package['contents'], array_flip( $item_keys ) ) ) {
				$pick = (string) $chosen[ $index ];
				break;
			}
		}
		if ( '' !== $pick && isset( $rates[ $pick ] ) ) {
			return $rates[ $pick ];
		}

		foreach ( $rates as $rate ) {
			if ( ! in_array( $rate->get_method_id(), array( 'local_pickup', 'pickup_location' ), true ) ) {
				return $rate;
			}
		}
		return reset( $rates );
	}

	/**
	 * Why the renewals can't be shipped to the customer's address, or '' when
	 * they can. WooCommerce only checks shipping for what ships today, which
	 * leaves out a subscription on a free trial.
	 *
	 * @return string
	 */
	public static function renewal_shipping_error() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! self::cart_contains_subscription() || ! WC()->cart->needs_shipping() ) {
			return '';
		}
		$renewal = self::get_cart_renewal_shipping( WC()->cart );
		foreach ( WC()->cart->get_cart() as $key => $cart_item ) {
			if ( empty( $cart_item['data'] ) || ! RPSFW_Subscription_Product::is_subscription( $cart_item['data'] ) || ! $cart_item['data']->needs_shipping() ) {
				continue;
			}
			if ( ! isset( $renewal[ $key ] ) ) {
				return __( 'No shipping method is available for the renewals of your subscription. Please double check your address, or contact us if you need any help.', 'restore-paypal-standard-for-woocommerce' );
			}
		}
		return '';
	}

	/**
	 * Classic checkout: the renewals must be shippable to the address.
	 *
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Validation errors collector.
	 */
	public static function validate_checkout_renewal_shipping( $data, $errors ) {
		// PayPal Commerce checks this itself, before its approval window opens.
		if ( isset( $data['payment_method'] ) && 'rpsfw_paypal_commerce' === $data['payment_method'] ) {
			return;
		}
		// WooCommerce already refused shipping to this address for today.
		if ( ! is_wp_error( $errors ) || $errors->get_error_messages( 'shipping' ) ) {
			return;
		}
		$message = self::renewal_shipping_error();
		if ( $message ) {
			$errors->add( 'shipping', $message );
		}
	}

	/**
	 * Block (Store API) checkout: the renewals must be shippable to the
	 * address, and a cart that ships nothing today (free trials only) takes
	 * the renewals' method as its chosen shipping method — WooCommerce
	 * refuses an order that needs shipping without one. It is stored under a
	 * key of its own, apart from today's shipments. Runs before WooCommerce
	 * validates the order.
	 *
	 * @param WC_Order $order Draft order built from the request.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the renewals can't be shipped.
	 * @throws Exception Fallback when the Store API exception class is unavailable.
	 */
	public static function prepare_store_api_renewal_shipping( $order ) {
		if ( ! self::cart_contains_subscription() || ! WC()->session || ! WC()->cart->needs_shipping() ) {
			return;
		}

		// PayPal Commerce checks this itself, before its approval window opens.
		$payment_method = ( $order instanceof WC_Order ) ? $order->get_payment_method() : '';
		$message        = 'rpsfw_paypal_commerce' === $payment_method ? '' : self::renewal_shipping_error();
		if ( $message ) {
			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'rpsfw_sub_no_renewal_shipping', esc_html( $message ), 400 );
			}
			throw new Exception( esc_html( $message ) );
		}

		$chosen = (array) WC()->session->get( 'chosen_shipping_methods', array() );
		if ( ! empty( $chosen ) || WC()->shipping()->get_packages() ) {
			return;
		}
		$renewal = self::get_cart_renewal_shipping( WC()->cart );
		$first   = $renewal ? reset( $renewal ) : null;
		WC()->session->set( 'chosen_shipping_methods', array( 'rpsfw_renewal' => $first ? $first['method'] : 'rpsfw_renewal' ) );
	}

	/**
	 * Free shipping for a renewal package: WooCommerce judges its minimum
	 * amount and coupon rule against the cart (today's order), so judge them
	 * against the renewal instead.
	 *
	 * @param bool                      $is_available Availability for today's order.
	 * @param array                     $package      Shipping package.
	 * @param WC_Shipping_Free_Shipping $method       Free shipping method.
	 * @return bool
	 */
	public static function renewal_free_shipping_is_available( $is_available, $package, $method = null ) {
		if ( empty( $package['rpsfw_renewal'] ) || ! is_array( $package['rpsfw_renewal'] ) || ! is_object( $method ) ) {
			return $is_available;
		}
		$renewal = $package['rpsfw_renewal'];

		$requires   = isset( $method->requires ) ? (string) $method->requires : '';
		$min_amount = isset( $method->min_amount ) ? (float) $method->min_amount : 0.0;
		$amount     = ( isset( $method->ignore_discounts ) && 'yes' === $method->ignore_discounts ) ? $renewal['subtotal'] : $renewal['total'];
		$has_min    = $amount >= $min_amount;
		$has_coupon = ! empty( $renewal['free_shipping'] );

		switch ( $requires ) {
			case 'min_amount':
				return $has_min;
			case 'coupon':
				return $has_coupon;
			case 'both':
				return $has_min && $has_coupon;
			case 'either':
				return $has_min || $has_coupon;
			default:
				return true;
		}
	}

	// -----------------------------------------------------------------
	// Display.
	// -----------------------------------------------------------------

	/**
	 * Add renewal details to the cart line item (shows on classic cart,
	 * classic checkout review, block cart and block checkout).
	 *
	 * @param array $item_data Existing rows.
	 * @param array $cart_item Cart item.
	 * @return array
	 */
	public static function cart_item_data( $item_data, $cart_item ) {
		if ( ! isset( $cart_item['data'] ) || ! RPSFW_Subscription_Product::is_subscription( $cart_item['data'] ) ) {
			return $item_data;
		}

		$product = $cart_item['data'];
		$qty     = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;

		// Recurring price for display: the product's real price (the cart
		// price may have been zeroed for a trial), less any recurring coupon.
		// This line is also the only renewal summary block checkout shows, so
		// it carries the coupon's effect too.
		$real_product = wc_get_product( $product->get_id() );
		$unit_price   = $real_product ? (float) $real_product->get_price() : 0.0;
		$line_display = function ( $line_discount ) use ( $real_product, $qty, $unit_price ) {
			$price = max( 0.0, ( $unit_price * $qty - $line_discount ) / $qty );
			return function_exists( 'wc_get_price_to_display' ) && $real_product
				? wc_get_price_to_display( $real_product, array( 'qty' => $qty, 'price' => $price, 'display_context' => 'cart' ) )
				: $price * $qty;
		};

		$discounts = RPSFW_Subscriptions_Coupons::get_cart_recurring_discounts();
		$discount  = ( isset( $cart_item['key'] ) && isset( $discounts[ $cart_item['key'] ] ) ) ? $discounts[ $cart_item['key'] ] : null;
		$now       = $line_display( $discount ? $discount['discount'] : 0.0 );
		$after     = $line_display( $discount ? $discount['discount_after'] : 0.0 );
		$schedule  = RPSFW_Subscriptions_Coupons::get_discount_schedule(
			( $discount && abs( $after - $now ) >= 0.01 ) ? $discount['limit'] : 0,
			RPSFW_Subscription_Product::get_trial_length( $product ) > 0,
			RPSFW_Subscription_Product::get_length( $product )
		);

		$value = rpsfw_format_recurring_amount(
			$schedule['starts_full'] ? $after : $now,
			get_woocommerce_currency(),
			RPSFW_Subscription_Product::get_interval( $product ),
			RPSFW_Subscription_Product::get_period( $product ),
			$schedule['renewals'],
			$after,
			true
		);

		// Renewal shipping (on block checkout this is the only place it shows).
		$shipping = ( ! empty( $cart_item['key'] ) && WC()->cart ) ? self::get_cart_renewal_shipping( WC()->cart ) : array();
		if ( ! empty( $shipping[ $cart_item['key'] ] ) && $shipping[ $cart_item['key'] ]['display'] > 0 ) {
			$value .= ', ' . sprintf(
				/* translators: %s: shipping amount */
				__( 'plus %s shipping each renewal', 'restore-paypal-standard-for-woocommerce' ),
				wp_strip_all_tags( wc_price( $shipping[ $cart_item['key'] ]['display'] ) )
			);
		}

		$trial = rpsfw_format_subscription_trial( RPSFW_Subscription_Product::get_trial_length( $product ), RPSFW_Subscription_Product::get_trial_period( $product ) );
		if ( $trial ) {
			$value .= ', ' . $trial;
		}

		$length = RPSFW_Subscription_Product::get_length( $product );
		if ( $length > 0 ) {
			$value .= ', ' . sprintf(
				/* translators: %d: total number of payments, including the first one */
				_n( '%d payment in total', '%d payments in total', $length, 'restore-paypal-standard-for-woocommerce' ),
				$length
			);
		}

		$item_data[] = array(
			'key'   => __( 'Renews', 'restore-paypal-standard-for-woocommerce' ),
			'value' => $value,
		);

		return $item_data;
	}

	/**
	 * Recurring total rows on classic cart/checkout totals tables — one
	 * row per subscription in the cart.
	 */
	public static function render_recurring_totals_row() {
		if ( ! self::cart_contains_subscription() ) {
			return;
		}
		$signature = self::build_cart_signature();
		if ( ! $signature || empty( $signature['items'] ) ) {
			return;
		}

		$multiple = count( $signature['items'] ) > 1;

		foreach ( $signature['items'] as $item ) {
			?>
			<tr class="rpsfw-recurring-total">
				<th><?php esc_html_e( 'Recurring total', 'restore-paypal-standard-for-woocommerce' ); ?></th>
				<td data-title="<?php esc_attr_e( 'Recurring total', 'restore-paypal-standard-for-woocommerce' ); ?>">
					<?php
					if ( $multiple ) {
						echo esc_html( $item['plan_name'] ) . ': ';
					}
					echo wp_kses_post(
						rpsfw_format_recurring_amount(
							$item['recurring_amount'],
							$signature['currency'],
							$item['interval'],
							$item['period'],
							isset( $item['discounted_renewals'] ) ? $item['discounted_renewals'] : 0,
							isset( $item['recurring_amount_full'] ) ? $item['recurring_amount_full'] : $item['recurring_amount']
						)
					);
					if ( ! empty( $item['recurring_shipping'] ) && $item['recurring_shipping'] > 0 ) {
						$shipping = wp_strip_all_tags( wc_price( $item['recurring_shipping'], array( 'currency' => $signature['currency'] ) ) );
						echo '<br><small>' . esc_html(
							! empty( $item['recurring_shipping_label'] )
								? sprintf(
									/* translators: 1: shipping amount, 2: shipping method name e.g. "Flat rate" */
									__( 'Includes %1$s shipping (%2$s).', 'restore-paypal-standard-for-woocommerce' ),
									$shipping,
									$item['recurring_shipping_label']
								)
								: sprintf(
									/* translators: %s: shipping amount */
									__( 'Includes %s shipping.', 'restore-paypal-standard-for-woocommerce' ),
									$shipping
								)
						) . '</small>';
					}
					if ( $item['trial_length'] > 0 ) {
						echo '<br><small>' . esc_html(
							sprintf(
								/* translators: %s: trial description e.g. "14-day free trial" */
								__( 'First payment after your %s.', 'restore-paypal-standard-for-woocommerce' ),
								rpsfw_format_subscription_trial( $item['trial_length'], $item['trial_period'] )
							)
						) . '</small>';
					}
					?>
				</td>
			</tr>
			<?php
		}
	}

	/**
	 * Place order button text for subscription carts.
	 *
	 * @param string $text Button text.
	 * @return string
	 */
	public static function order_button_text( $text ) {
		if ( ! self::cart_contains_subscription() ) {
			return $text;
		}
		$custom = rpsfw_subscriptions_get_setting( 'place_order_button_text' );
		return $custom ? __( $custom, 'restore-paypal-standard-for-woocommerce' ) : $text; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
	}

	/**
	 * Only PayPal Commerce and Stripe can bill native subscription carts.
	 *
	 * @param array $gateways Available gateways.
	 * @return array
	 */
	public static function filter_available_gateways( $gateways ) {
		if ( is_admin() || ! self::cart_contains_subscription() ) {
			return $gateways;
		}

		$supported = apply_filters(
			'rpsfw_subscription_supported_gateways',
			array( 'rpsfw_paypal_commerce', 'rpsfw_stripe' )
		);

		foreach ( $gateways as $gateway_id => $gateway ) {
			if ( ! in_array( $gateway_id, $supported, true ) ) {
				unset( $gateways[ $gateway_id ] );
			}
		}

		return $gateways;
	}

	/**
	 * Whether anything will be charged AFTER today.
	 *
	 * A subscription priced at 0 with no sign-up fee never bills, so it truly
	 * needs no payment method; anything else does, even when today's total is 0.
	 *
	 * @return bool
	 */
	public static function cart_has_future_payment() {
		foreach ( self::get_subscription_cart_items() as $cart_item ) {
			// Read the price from the STORED product, not from $cart_item['data']:
			// zero_trial_prices() sets that object's price to 0 for trial items,
			// which is precisely the case this method has to see through.
			$product_id = ! empty( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : ( isset( $cart_item['product_id'] ) ? $cart_item['product_id'] : 0 );
			if ( ! $product_id ) {
				continue;
			}
			if ( (float) RPSFW_Subscription_Product::get_recurring_price( $product_id ) > 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Force payment collection on a zero-total subscription cart.
	 *
	 * WC_Cart::needs_payment() is just "total > 0", and checkout — block and
	 * classic alike — renders no payment methods when it is false. A free trial
	 * with no sign-up fee hits exactly that case, leaving the customer a
	 * checkout with no way to pay and no way to subscribe.
	 *
	 * @param bool    $needs_payment Existing flag.
	 * @param WC_Cart $cart          Cart.
	 * @return bool
	 */
	public static function cart_needs_payment( $needs_payment, $cart = null ) {
		if ( $needs_payment || ! self::cart_contains_subscription() ) {
			return $needs_payment;
		}

		return self::cart_has_future_payment();
	}

	/**
	 * The order-level counterpart, consulted by the pay page and by
	 * WC_Order::needs_payment() once the order exists.
	 *
	 * @param bool     $needs_payment         Existing flag.
	 * @param WC_Order $order                 Order.
	 * @param array    $valid_order_statuses  Statuses WooCommerce considers payable.
	 * @return bool
	 */
	public static function order_needs_payment( $needs_payment, $order, $valid_order_statuses = array() ) {
		if ( $needs_payment || ! $order instanceof WC_Order ) {
			return $needs_payment;
		}

		// Only for orders still awaiting payment, so a completed zero-total
		// order is never dragged back into a payable state.
		if ( ! empty( $valid_order_statuses ) && ! $order->has_status( $valid_order_statuses ) ) {
			return $needs_payment;
		}

		if ( (float) $order->get_total() > 0 || ! self::order_contains_subscription( $order ) ) {
			return $needs_payment;
		}

		return true;
	}

	/**
	 * Guests must create an account to buy a subscription (classic and
	 * block checkout both consult this via
	 * WC_Checkout::is_registration_required()).
	 *
	 * @param bool $required Whether registration is required.
	 * @return bool
	 */
	public static function require_registration( $required ) {
		if ( ! is_user_logged_in() && self::cart_contains_subscription() ) {
			return true;
		}
		return $required;
	}

	/**
	 * Make sure the account-creation fields are available at checkout for
	 * subscription carts even when the store has "create an account during
	 * checkout" disabled.
	 *
	 * @param bool $enabled Whether registration is enabled.
	 * @return bool
	 */
	public static function enable_registration( $enabled ) {
		if ( self::cart_contains_subscription() ) {
			return true;
		}
		return $enabled;
	}

	/**
	 * Resolve the shopper's checkout email at the native gateway AJAX entry
	 * point (which runs before any WC order exists). Tries the posted field
	 * first (classic serializes the form; the block posts the address), then
	 * falls back to the customer session (kept up to date as block/classic
	 * fields change).
	 *
	 * @return string
	 */
	public static function get_checkout_email() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		foreach ( array( 'billing_email', 'email' ) as $key ) {
			if ( isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ) {
				$email = sanitize_email( wp_unslash( $_POST[ $key ] ) );
				if ( $email ) {
					return $email;
				}
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( function_exists( 'WC' ) && WC()->customer ) {
			return (string) WC()->customer->get_billing_email();
		}
		return '';
	}

	/**
	 * Guard for the native gateway checkout endpoints: a guest buying a
	 * subscription with an email that already belongs to an account must log
	 * in first. Runs BEFORE the processor subscription is created / payment is
	 * taken. Returns the message to show (and exit on), or '' when fine.
	 *
	 * Native subscription carts always require an account (see
	 * require_registration), so this is enforced unconditionally for guests —
	 * unlike WooCommerce's own post-payment account-creation error.
	 *
	 * @return string
	 */
	public static function guest_subscription_email_conflict() {
		if ( is_user_logged_in() || ! self::cart_contains_subscription() ) {
			return '';
		}
		$email    = self::get_checkout_email();
		$existing = self::existing_account_email( $email );
		return $existing ? self::account_exists_message( $existing ) : '';
	}

	/**
	 * Whether a checkout email belongs to an existing account that the
	 * current (guest) shopper would need to log into. Returns the email when
	 * it does, '' otherwise.
	 *
	 * @param string $email Billing/account email entered at checkout.
	 * @return string
	 */
	protected static function existing_account_email( $email ) {
		$email = is_string( $email ) ? trim( $email ) : '';
		if ( $email && is_email( $email ) && email_exists( $email ) ) {
			return $email;
		}
		return '';
	}

	/**
	 * The "please log in" message shown when a guest tries to buy a
	 * subscription with an existing account's email.
	 *
	 * @param string $email     The existing account email.
	 * @param bool   $with_link Whether to include a link to My Account (safe
	 *                          for classic notices; block checkout renders the
	 *                          message as plain text so pass false there).
	 * @return string
	 */
	protected static function account_exists_message( $email ) {
		// Match RPSFW_Checkout_Validator / WooCommerce core wording exactly so
		// every checkout path shows the SAME message (and identical notices
		// dedupe rather than stack).
		return sprintf(
			/* translators: %s: email address. */
			__( 'An account is already registered with %s. Please log in or use a different email address.', 'restore-paypal-standard-for-woocommerce' ),
			$email
		);
	}

	/**
	 * Classic checkout: block a guest from buying a subscription with an
	 * email that already belongs to an account. Runs during checkout
	 * validation (before the order is created) so the shopper sees the
	 * message on the checkout page.
	 *
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Validation errors collector.
	 */
	public static function validate_checkout_account( $data, $errors ) {
		// PayPal Commerce validates the existing-email case itself, before its
		// approval window opens (via RPSFW_Checkout_Validator). Skip here so it
		// isn't raised twice for a PayPal checkout.
		if ( isset( $data['payment_method'] ) && 'rpsfw_paypal_commerce' === $data['payment_method'] ) {
			return;
		}

		if ( is_user_logged_in() || ! self::cart_contains_subscription() ) {
			return;
		}
		$email    = isset( $data['billing_email'] ) ? $data['billing_email'] : '';
		$existing = self::existing_account_email( $email );
		if ( $existing && is_wp_error( $errors ) ) {
			$errors->add( 'rpsfw_sub_account_exists', self::account_exists_message( $existing ) );
		}
	}

	/**
	 * Block (Store API) checkout: same guard. Throws so the block checkout
	 * stops and surfaces the message before payment is taken.
	 *
	 * @param WC_Order         $order   Draft order built from the request.
	 * @param WP_REST_Request  $request The checkout request (unused).
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the email belongs to an existing account.
	 * @throws Exception Fallback when the Store API exception class is unavailable.
	 */
	public static function validate_store_api_account( $order, $request ) {
		// PayPal Commerce validates the existing-email case itself, before its
		// approval window opens. Skip here so it isn't raised twice for a
		// PayPal block checkout.
		$payment_method = ( $order instanceof WC_Order ) ? $order->get_payment_method() : '';
		if ( 'rpsfw_paypal_commerce' === $payment_method ) {
			return;
		}

		// Check the ORDER, not the cart: at the Store API stage the cart state
		// is unreliable, but the draft order already carries the subscription
		// signature/items, so order_contains_subscription() is authoritative.
		$has_sub = ( $order instanceof WC_Order ) && self::order_contains_subscription( $order );
		if ( is_user_logged_in() || ! $has_sub ) {
			return;
		}
		$email    = $order->get_billing_email();
		$existing = self::existing_account_email( $email );
		if ( ! $existing ) {
			return;
		}
		$message = self::account_exists_message( $existing );
		if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'rpsfw_sub_account_exists', esc_html( $message ), 400 );
		}
		throw new Exception( esc_html( $message ) );
	}

	// -----------------------------------------------------------------
	// Cart signature.
	// -----------------------------------------------------------------

	/**
	 * Build the subscription signature from the current cart. Consumed by
	 * both gateway glues to create the processor-side subscription(s), and
	 * snapshotted onto the order at checkout.
	 *
	 * Multi-subscription aware: the signature carries an `items` array with
	 * one entry per subscription product (Stripe creates one Stripe
	 * subscription per entry). For backward compatibility the top level
	 * also carries the FIRST item's fields flat — the only shape PayPal
	 * Commerce consumes, since it is limited to single-subscription carts.
	 *
	 * Amounts:
	 *  - Per item, recurring_amount: what that subscription's first renewal
	 *    bills — its line (incl. tax, less recurring coupons) + its renewal
	 *    shipping (incl. shipping tax) when the product ships. Standard
	 *    coupons do NOT recur.
	 *  - Per item, recurring_shipping / recurring_shipping_method /
	 *    recurring_shipping_label: that renewal shipping, its rate id and
	 *    method name (see get_cart_renewal_shipping()).
	 *  - Per item, discounted_renewals / recurring_amount_full: when a
	 *    recurring coupon is limited to a number of payments, renewals bill
	 *    recurring_amount for discounted_renewals renewals and
	 *    recurring_amount_full after that. discounted_renewals is 0 when
	 *    the amount never changes after checkout (recurring_amount_full is
	 *    then equal to recurring_amount).
	 *  - Per item, recurring_discount / recurring_discount_full: the coupon
	 *    discount (incl. tax) inside those two amounts; recurring_coupons:
	 *    code => payment limit (0 = every payment) of the coupons involved.
	 *  - initial_total: the cart grand total (what WooCommerce charges
	 *    today) — includes sign-up fee lines, trial zeroing, coupons,
	 *    shipping and taxes.
	 *  - non_subscription_amount: line totals (incl. tax) of one-time
	 *    products in a mixed cart.
	 *  - expected_sub_initial: what Stripe's first invoices would bill for
	 *    the subscriptions alone before reconciliation (each non-trial
	 *    item's recurring_amount plus every sign-up fee) — used to compute
	 *    the initial reconciliation discount.
	 *
	 * @param bool $recalculate Recalculate cart totals first. Pass false from
	 *                          contexts that may run inside a totals
	 *                          calculation (gateway availability checks); the
	 *                          last calculated totals are used then.
	 * @return array|false
	 */
	public static function build_cart_signature( $recalculate = true ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return false;
		}

		$cart = WC()->cart;
		if ( $recalculate ) {
			$cart->calculate_totals();
		}

		$sub_cart_items = self::get_subscription_cart_items();
		if ( empty( $sub_cart_items ) ) {
			return false;
		}

		// First pass: per-item recurring lines (fresh product objects so the
		// trial-zeroed cart price doesn't leak into the recurring amount),
		// before and after recurring coupons.
		$items               = array();
		$discounted_lines    = array();
		$cart_keys           = array();
		$recurring_discounts = RPSFW_Subscriptions_Coupons::get_cart_recurring_discounts( $cart );

		foreach ( $sub_cart_items as $cart_item ) {
			$qty          = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;
			$product_id   = (int) $cart_item['product_id'];
			$variation_id = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;

			$product = wc_get_product( $variation_id ? $variation_id : $product_id );
			if ( ! $product ) {
				continue;
			}

			$unit_price     = (float) $product->get_price();
			$recurring_line = function_exists( 'wc_get_price_including_tax' )
				? (float) wc_get_price_including_tax( $product, array( 'qty' => $qty, 'price' => $unit_price ) )
				: $unit_price * $qty;
			$recurring_line = round( $recurring_line, 2 );

			$trial_length = RPSFW_Subscription_Product::get_trial_length( $product );
			$trial_period = RPSFW_Subscription_Product::get_trial_period( $product );

			$item = array(
				'product_id'     => $product_id,
				'variation_id'   => $variation_id,
				'quantity'       => $qty,
				'plan_name'      => wp_strip_all_tags( $cart_item['data']->get_name() ),
				'recurring_line' => $recurring_line,
				'needs_shipping' => (bool) $product->needs_shipping(),
				'signup_fee'     => round( RPSFW_Subscription_Product::get_signup_fee( $product ) * $qty, 2 ),
				'period'         => RPSFW_Subscription_Product::get_period( $product ),
				'interval'       => RPSFW_Subscription_Product::get_interval( $product ),
				'length'         => RPSFW_Subscription_Product::get_length( $product ),
				'trial_length'   => $trial_length,
				'trial_period'   => $trial_period,
				'trial_days'     => self::trial_to_days( $trial_length, $trial_period ),
			);

			$cart_key = isset( $cart_item['key'] ) ? $cart_item['key'] : '';
			$discount = ( $cart_key && isset( $recurring_discounts[ $cart_key ] ) ) ? $recurring_discounts[ $cart_key ] : null;

			$discounted_lines[] = $discount
				? array(
					'now'     => RPSFW_Subscriptions_Coupons::discounted_line_including_tax( $product, $qty, $unit_price, $discount['discount'] ),
					'after'   => RPSFW_Subscriptions_Coupons::discounted_line_including_tax( $product, $qty, $unit_price, $discount['discount_after'] ),
					'limit'   => $discount['limit'],
					'coupons' => $discount['coupons'],
				)
				: array(
					'now'     => $recurring_line,
					'after'   => $recurring_line,
					'limit'   => 0,
					'coupons' => array(),
				);

			$items[]     = $item;
			$cart_keys[] = $cart_key;
		}

		if ( empty( $items ) ) {
			return false;
		}

		// Second pass: each item's renewal shipping (incl. shipping tax),
		// rated for the subscriptions alone — not today's shipping, which a
		// free trial doesn't have and one-time products add to.
		$renewal_shipping = self::get_cart_renewal_shipping( $cart );
		foreach ( $items as $key => $item ) {
			$shipping = ( $cart_keys[ $key ] && isset( $renewal_shipping[ $cart_keys[ $key ] ] ) ) ? $renewal_shipping[ $cart_keys[ $key ] ] : null;
			$share    = $shipping ? (float) $shipping['amount'] : 0.0;

			// Recurring coupons: the amount while every coupon applies, and the
			// amount once any limited ones have run out.
			$lines        = $discounted_lines[ $key ];
			$undiscounted = round( $item['recurring_line'] + $share, 2 );
			$now          = round( $lines['now'] + $share, 2 );
			$after        = round( $lines['after'] + $share, 2 );
			$schedule     = RPSFW_Subscriptions_Coupons::get_discount_schedule(
				abs( $after - $now ) >= 0.01 ? $lines['limit'] : 0,
				$item['trial_length'] > 0,
				$item['length']
			);
			$next         = $schedule['starts_full'] ? $after : $now;
			$full         = $schedule['renewals'] > 0 ? $after : $next;
			$coupons      = $lines['coupons'];
			if ( ! $schedule['renewals'] && ! $schedule['starts_full'] ) {
				// No price change after checkout: any payment limit covers
				// every payment this subscription makes.
				$coupons = array_map( '__return_zero', $coupons );
			}

			$items[ $key ]['recurring_shipping']        = $share;
			$items[ $key ]['recurring_shipping_method'] = $shipping ? $shipping['method'] : '';
			$items[ $key ]['recurring_shipping_label']  = $shipping ? $shipping['label'] : '';
			$items[ $key ]['recurring_amount']          = $next;
			$items[ $key ]['recurring_amount_full']     = $full;
			$items[ $key ]['discounted_renewals']       = $schedule['renewals'];
			$items[ $key ]['recurring_discount']        = max( 0.0, round( $undiscounted - $next, 2 ) );
			$items[ $key ]['recurring_discount_full']   = max( 0.0, round( $undiscounted - $full, 2 ) );
			$items[ $key ]['recurring_coupons']         = $coupons;
		}

		// One-time portion of a mixed cart (line totals incl. tax).
		$non_subscription_amount = 0.0;
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! isset( $cart_item['data'] ) || RPSFW_Subscription_Product::is_subscription( $cart_item['data'] ) ) {
				continue;
			}
			$non_subscription_amount += (float) $cart_item['line_total'] + (float) $cart_item['line_tax'];
		}
		$non_subscription_amount = round( $non_subscription_amount, 2 );

		// What Stripe's first invoices bill for the subscriptions before
		// reconciliation: each non-trial item's recurring price (its first
		// renewal amount) plus all sign-up fees. The gap to what WooCommerce
		// charges today becomes a one-time discount (standard coupons, a
		// recurring coupon limited to the first payment) or charge (shipping
		// for one-time products, for example).
		$expected_sub_initial = 0.0;
		foreach ( $items as $item ) {
			if ( $item['trial_length'] < 1 ) {
				$expected_sub_initial += $item['recurring_amount'];
			}
			$expected_sub_initial += $item['signup_fee'];
		}

		$initial_total = round( (float) $cart->get_total( 'edit' ), 2 );

		// Flat fields = first item (backward compatible shape; the only one
		// PayPal Commerce consumes, and identical to the full picture for
		// single-subscription carts).
		$first = $items[0];

		$signature = array(
			'product_id'                => $first['product_id'],
			'variation_id'              => $first['variation_id'],
			'quantity'                  => $first['quantity'],
			'plan_name'                 => $first['plan_name'],
			'currency'                  => get_woocommerce_currency(),
			'recurring_amount'          => $first['recurring_amount'],
			'recurring_amount_full'     => $first['recurring_amount_full'],
			'discounted_renewals'       => $first['discounted_renewals'],
			'recurring_discount'        => $first['recurring_discount'],
			'recurring_discount_full'   => $first['recurring_discount_full'],
			'recurring_coupons'         => $first['recurring_coupons'],
			'recurring_shipping'        => $first['recurring_shipping'],
			'recurring_shipping_method' => $first['recurring_shipping_method'],
			'recurring_shipping_label'  => $first['recurring_shipping_label'],
			'initial_total'             => $initial_total,
			'non_subscription_amount'   => $non_subscription_amount,
			'signup_fee'                => $first['signup_fee'],
			'period'                    => $first['period'],
			'interval'                  => $first['interval'],
			'length'                    => $first['length'],
			'trial_length'              => $first['trial_length'],
			'trial_period'              => $first['trial_period'],
			'trial_days'                => $first['trial_days'],
			'sub_count'                 => count( $items ),
			'expected_sub_initial'      => round( $expected_sub_initial, 2 ),
			'items'                     => $items,
		);

		return apply_filters( 'rpsfw_subscription_cart_signature', $signature, $cart );
	}

	/**
	 * Convert a trial length/period to days (used by Stripe's trial_days).
	 *
	 * @param int    $length Trial length.
	 * @param string $period day|week|month|year.
	 * @return int
	 */
	public static function trial_to_days( $length, $period ) {
		$length = max( 0, (int) $length );
		if ( ! $length ) {
			return 0;
		}
		switch ( $period ) {
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

	// -----------------------------------------------------------------
	// Order snapshot.
	// -----------------------------------------------------------------

	/**
	 * Stamp the cart signature on the order during classic checkout.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  Posted data (unused).
	 */
	public static function stamp_order_signature( $order, $data = array() ) {
		if ( ! self::cart_contains_subscription() ) {
			return;
		}
		$signature = self::build_cart_signature();
		if ( $signature ) {
			$order->update_meta_data( self::ORDER_SIGNATURE_META, $signature );
		}
	}

	/**
	 * Stamp the cart signature on the order during block (Store API) checkout.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function stamp_order_signature_store_api( $order ) {
		self::stamp_order_signature( $order );
	}

	/**
	 * Read the signature snapshot from an order (falling back to a live
	 * cart signature when absent).
	 *
	 * @param WC_Order $order Order.
	 * @return array|false
	 */
	public static function get_order_signature( $order ) {
		if ( ! $order ) {
			return false;
		}
		$signature = $order->get_meta( self::ORDER_SIGNATURE_META );
		// isset, not empty: a 100% recurring coupon makes the amount 0.
		if ( is_array( $signature ) && isset( $signature['recurring_amount'] ) && ! empty( $signature['product_id'] ) ) {
			return $signature;
		}
		return self::build_cart_signature();
	}

	/**
	 * Whether an order contains a native subscription product.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function order_contains_subscription( $order ) {
		if ( ! $order ) {
			return false;
		}
		$signature = $order->get_meta( self::ORDER_SIGNATURE_META );
		if ( is_array( $signature ) && ! empty( $signature['product_id'] ) ) {
			return true;
		}
		foreach ( $order->get_items() as $item ) {
			if ( ! is_callable( array( $item, 'get_product' ) ) ) {
				continue;
			}
			$product = $item->get_product();
			if ( $product && RPSFW_Subscription_Product::is_subscription( $product ) ) {
				return true;
			}
		}
		return false;
	}
}
