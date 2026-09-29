<?php
/**
 * Coupons for native subscriptions.
 *
 * Adds two coupon types that discount subscriptions on every payment. They
 * are stored under the same type slugs and payment-limit meta key the
 * WooCommerce Subscriptions plugin uses (data identifiers only — no code from
 * that plugin), so a coupon keeps working when a store moves between that
 * plugin and the built-in module:
 *
 *  - "Fixed subscription discount" (recurring_fee) and "Percentage
 *    subscription discount" (recurring_percent) take an amount or a
 *    percentage off the subscription price on every payment: the one
 *    charged at checkout (unless a free trial defers it) and every renewal.
 *    The discounted amount is what the PayPal plan / Stripe price is created
 *    with.
 *  - "Number of payments to discount" (stored as _wcs_number_payments)
 *    limits such a coupon to the first x subscription payments. The payment
 *    at checkout counts as one unless a free trial defers it. PayPal plans
 *    carry the discounted payments as a paid trial cycle; Stripe
 *    subscriptions are switched to the full price once the last discounted
 *    renewal has been recorded (see maybe_end_limited_discount()).
 *
 * Standard WooCommerce coupons keep discounting only what is charged at
 * checkout. They are refused when nothing is charged for products at
 * checkout (every subscription in the cart starts with a free trial), since
 * they could never take anything off.
 *
 * @package Restore_PayPal_Standard_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RPSFW_Subscriptions_Coupons
 */
class RPSFW_Subscriptions_Coupons {

	/**
	 * Fixed amount off the subscription price on every payment.
	 */
	const TYPE_FIXED = 'recurring_fee';

	/**
	 * Percentage off the subscription price on every payment.
	 */
	const TYPE_PERCENT = 'recurring_percent';

	/**
	 * Coupon meta: number of subscription payments a recurring coupon applies
	 * to (empty or 0 = every payment). Same key as WooCommerce Subscriptions,
	 * so the limit carries over between the two.
	 */
	const META_LIMIT = '_wcs_number_payments';

	/**
	 * Form field for META_LIMIT on the coupon edit screen.
	 */
	const FIELD_LIMIT = 'rpsfw_payment_limit';

	/**
	 * Per-request cache for get_cart_recurring_discounts().
	 *
	 * @var array
	 */
	private static $discount_cache = array();

	/**
	 * Keep the coupon types registered whenever WooCommerce Subscriptions is
	 * not active (that plugin registers the same slugs itself), including
	 * while built-in subscriptions are switched off.
	 *
	 * WooCommerce reads a coupon whose type isn't registered as its default
	 * type, "Fixed cart discount": without this, switching the module off
	 * would turn a 20% subscription coupon into $20 off any order. While the
	 * module is off these coupons are refused instead.
	 */
	public static function init_types() {
		add_filter( 'woocommerce_coupon_discount_types', array( __CLASS__, 'add_discount_types' ) );
		add_filter( 'woocommerce_product_coupon_types', array( __CLASS__, 'add_product_coupon_types' ) );
		if ( ! rpsfw_native_subscriptions_active() ) {
			add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'refuse_while_disabled' ), 10, 2 );
		}
	}

	/**
	 * Discount maths, validation and display. Native mode only (loader-gated),
	 * like the other purchase surfaces.
	 */
	public static function init() {
		add_filter( 'woocommerce_coupon_get_discount_amount', array( __CLASS__, 'calculate_discount_amount' ), 10, 5 );
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( __CLASS__, 'is_valid_for_product' ), 10, 3 );
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'validate_coupon' ), 10, 3 );
		add_filter( 'woocommerce_cart_totals_coupon_html', array( __CLASS__, 'coupon_row_html' ), 10, 3 );

		if ( is_admin() ) {
			add_action( 'woocommerce_coupon_options', array( __CLASS__, 'render_limit_field' ), 10, 2 );
			add_action( 'woocommerce_coupon_options_save', array( __CLASS__, 'save_limit_field' ), 10, 2 );
		}
	}

	/**
	 * Record servicing: end limited discounts as renewals come in.
	 */
	public static function init_servicing() {
		add_action( 'rpsfw_subscription_renewal_recorded', array( __CLASS__, 'maybe_end_limited_discount' ), 10, 1 );
	}

	// -----------------------------------------------------------------
	// Coupon types.
	// -----------------------------------------------------------------

	/**
	 * Whether a coupon is one of the recurring types.
	 *
	 * @param WC_Coupon|mixed $coupon Coupon.
	 * @return bool
	 */
	public static function is_recurring_coupon( $coupon ) {
		return $coupon instanceof WC_Coupon && $coupon->is_type( array( self::TYPE_FIXED, self::TYPE_PERCENT ) );
	}

	/**
	 * Number of subscription payments a recurring coupon applies to.
	 *
	 * @param WC_Coupon $coupon Coupon.
	 * @return int 0 = every payment.
	 */
	public static function get_payment_limit( $coupon ) {
		if ( ! self::is_recurring_coupon( $coupon ) ) {
			return 0;
		}
		return max( 0, (int) $coupon->get_meta( self::META_LIMIT ) );
	}

	/**
	 * Register the recurring types in the coupon type dropdown.
	 *
	 * @param array $types Coupon types.
	 * @return array
	 */
	public static function add_discount_types( $types ) {
		$types[ self::TYPE_FIXED ]   = __( 'Fixed subscription discount', 'restore-paypal-standard-for-woocommerce' );
		$types[ self::TYPE_PERCENT ] = __( 'Percentage subscription discount', 'restore-paypal-standard-for-woocommerce' );
		return $types;
	}

	/**
	 * The recurring types discount individual products, so WooCommerce applies
	 * its per-product rules (product and category restrictions, excluded sale
	 * items, "Limit usage to X items") to them.
	 *
	 * @param array $types Product coupon types.
	 * @return array
	 */
	public static function add_product_coupon_types( $types ) {
		if ( ! is_array( $types ) ) {
			return $types;
		}
		return array_values( array_unique( array_merge( $types, array( self::TYPE_FIXED, self::TYPE_PERCENT ) ) ) );
	}

	/**
	 * Recurring coupons only discount subscription products.
	 *
	 * @param bool       $valid   Whether WooCommerce considers the product valid.
	 * @param WC_Product $product Product.
	 * @param WC_Coupon  $coupon  Coupon.
	 * @return bool
	 */
	public static function is_valid_for_product( $valid, $product, $coupon ) {
		if ( $valid && self::is_recurring_coupon( $coupon ) && ! RPSFW_Subscription_Product::is_subscription( $product ) ) {
			return false;
		}
		return $valid;
	}

	/**
	 * Discount maths for the recurring types. WooCommerce passes the price of
	 * one unit ($single) and multiplies by the quantity it applies to, so both
	 * the checkout cart and get_cart_recurring_discounts() share this.
	 *
	 * A free trial line is priced at 0 in the cart, so a recurring coupon
	 * takes nothing off at checkout and starts with the first paid payment.
	 *
	 * @param float                             $discount   Discount so far.
	 * @param float                             $price      Price being discounted.
	 * @param array|WC_Order_Item_Product|null  $line       Cart item or order item.
	 * @param bool                              $per_unit   Whether $price is for one unit.
	 * @param WC_Coupon                         $coupon     Coupon.
	 * @return float
	 */
	public static function calculate_discount_amount( $discount, $price, $line, $per_unit, $coupon ) {
		if ( ! self::is_recurring_coupon( $coupon ) ) {
			return $discount;
		}

		// WooCommerce before 8.x ran this filter a second time for the whole
		// line, with the discount already worked out (and already limited to
		// "Limit usage to X items"). Keep that figure.
		if ( ! $per_unit && $discount > 0 ) {
			return $discount;
		}

		$product = self::get_item_product( $line );
		if ( ! $product || ! RPSFW_Subscription_Product::is_subscription( $product ) ) {
			return $discount;
		}

		$price  = max( 0.0, (float) $price );
		$amount = max( 0.0, (float) $coupon->get_amount() );

		if ( $coupon->is_type( self::TYPE_PERCENT ) ) {
			$discount = $price * min( 100.0, $amount ) / 100;
		} else {
			$discount = $amount * ( $per_unit ? 1 : self::get_item_quantity( $line ) );
		}

		return round( min( $discount, $price ), wc_get_rounding_precision() );
	}

	/**
	 * Product behind a cart item or order item.
	 *
	 * @param array|WC_Order_Item_Product|null $item Item.
	 * @return WC_Product|null
	 */
	private static function get_item_product( $item ) {
		if ( $item instanceof WC_Order_Item_Product ) {
			$product = $item->get_product();
			return $product ? $product : null;
		}
		if ( is_array( $item ) && isset( $item['data'] ) && $item['data'] instanceof WC_Product ) {
			return $item['data'];
		}
		return null;
	}

	/**
	 * Quantity of a cart item or order item.
	 *
	 * @param array|WC_Order_Item_Product|null $item Item.
	 * @return int
	 */
	private static function get_item_quantity( $item ) {
		if ( $item instanceof WC_Order_Item_Product ) {
			return max( 1, (int) $item->get_quantity() );
		}
		return ( is_array( $item ) && isset( $item['quantity'] ) ) ? max( 1, (int) $item['quantity'] ) : 1;
	}

	// -----------------------------------------------------------------
	// Validation.
	// -----------------------------------------------------------------

	/**
	 * While built-in subscriptions are switched off, subscription coupons have
	 * nothing to discount: refuse them with a clear message.
	 *
	 * @param bool      $valid  Whether the coupon is valid so far.
	 * @param WC_Coupon $coupon Coupon.
	 * @return bool
	 * @throws Exception When the coupon is a subscription coupon.
	 */
	public static function refuse_while_disabled( $valid, $coupon ) {
		if ( $valid && self::is_recurring_coupon( $coupon ) ) {
			throw new Exception( esc_html__( 'Sorry, this coupon is for subscriptions, which are not available right now.', 'restore-paypal-standard-for-woocommerce' ), WC_Coupon::E_WC_COUPON_INVALID_FILTERED );
		}
		return $valid;
	}

	/**
	 * Cart rules for coupons on subscription carts. Throwing inside this
	 * filter is how WooCommerce expects a custom error message (it catches
	 * the exception and shows the message to the shopper).
	 *
	 * @param bool              $valid     Whether the coupon is valid so far.
	 * @param WC_Coupon         $coupon    Coupon.
	 * @param WC_Discounts|null $discounts Discounts object (holds the cart or an order).
	 * @return bool
	 * @throws Exception When the coupon cannot be used with this cart.
	 */
	public static function validate_coupon( $valid, $coupon, $discounts = null ) {
		if ( ! $valid || ! $coupon instanceof WC_Coupon ) {
			return $valid;
		}

		// Cart only. Coupons an admin applies to an order are left alone.
		if ( $discounts instanceof WC_Discounts && ! ( $discounts->get_object() instanceof WC_Cart ) ) {
			return $valid;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $valid;
		}

		$has_subscription = RPSFW_Subscriptions_Cart::cart_contains_subscription();

		if ( self::is_recurring_coupon( $coupon ) ) {
			if ( ! $has_subscription ) {
				throw new Exception( esc_html__( 'Sorry, this coupon can only be used when your cart contains a subscription.', 'restore-paypal-standard-for-woocommerce' ), WC_Coupon::E_WC_COUPON_INVALID_FILTERED );
			}

			// One limit per cart: coupons that run out after different numbers
			// of payments would need more than one price change, and a
			// subscription here changes price at most once.
			$limit = self::get_payment_limit( $coupon );
			if ( $limit > 0 ) {
				foreach ( WC()->cart->get_coupons() as $code => $other ) {
					// (string): a numeric code comes back as an integer array key.
					if ( (string) $code === $coupon->get_code() ) {
						continue;
					}
					$other_limit = self::get_payment_limit( $other );
					if ( $other_limit > 0 && $other_limit !== $limit ) {
						throw new Exception(
							esc_html(
								sprintf(
									/* translators: %s: code of the coupon already in the cart */
									__( 'Sorry, this coupon cannot be combined with coupon %s, because the two discount a different number of payments.', 'restore-paypal-standard-for-woocommerce' ),
									$other->get_code()
								)
							),
							WC_Coupon::E_WC_COUPON_INVALID_FILTERED
						);
					}
				}
			}
			return $valid;
		}

		// Standard coupons only discount what is charged for products at
		// checkout. With every subscription on a free trial (and nothing else
		// in the cart to pay for), there is nothing for them to take off.
		if ( $has_subscription && ! self::cart_charges_products_today() ) {
			throw new Exception( esc_html__( 'Sorry, this coupon only discounts products paid for today, and your subscription is not charged until its free trial ends.', 'restore-paypal-standard-for-woocommerce' ), WC_Coupon::E_WC_COUPON_INVALID_FILTERED );
		}

		return $valid;
	}

	/**
	 * Whether any product in the cart is charged at checkout. Subscriptions on
	 * a free trial are not (the sign-up fee is a fee line, which coupons never
	 * discount).
	 *
	 * Reads prices from the product meta rather than the cart line, because
	 * the cart zeroes trial lines only once totals have been calculated.
	 *
	 * @return bool
	 */
	private static function cart_charges_products_today() {
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
				continue;
			}
			$product = $cart_item['data'];
			if ( RPSFW_Subscription_Product::is_subscription( $product ) && RPSFW_Subscription_Product::get_trial_length( $product ) > 0 ) {
				continue;
			}
			if ( (float) $product->get_price() > 0 ) {
				return true;
			}
		}
		return false;
	}

	// -----------------------------------------------------------------
	// Recurring discount maths.
	// -----------------------------------------------------------------

	/**
	 * Recurring coupon discounts for each subscription line in the cart.
	 *
	 * Runs WooCommerce's own discount engine over the subscription lines at
	 * their recurring price (the cart line itself is zeroed during a free
	 * trial), so product restrictions, "Limit usage to X items" and the
	 * sequential-discount setting behave as they do at checkout.
	 *
	 * @param WC_Cart|null $cart Cart. Defaults to the session cart.
	 * @return array[] Keyed by cart item key: {
	 *     @type float $discount       Line discount (prices as entered) while every recurring coupon applies.
	 *     @type float $discount_after Line discount once the limited coupons have run out.
	 *     @type int   $limit          Payments the limited coupons cover (0 = no limited coupon on this line).
	 *     @type array $coupons        Coupons discounting this line: code => payment limit (0 = every payment).
	 * }
	 */
	public static function get_cart_recurring_discounts( $cart = null ) {
		if ( ! $cart ) {
			$cart = function_exists( 'WC' ) ? WC()->cart : null;
		}
		if ( ! $cart instanceof WC_Cart ) {
			return array();
		}

		$coupons = array();
		foreach ( $cart->get_coupons() as $code => $coupon ) {
			if ( self::is_recurring_coupon( $coupon ) ) {
				$coupons[ $code ] = $coupon;
			}
		}
		if ( empty( $coupons ) ) {
			return array();
		}

		// One discount item per subscription line, priced from a fresh
		// product object so a trial-zeroed cart price never leaks in.
		$items     = array();
		$cache_key = array( get_option( 'woocommerce_calc_discounts_sequentially', 'no' ) );
		foreach ( $cart->get_cart() as $key => $cart_item ) {
			if ( empty( $cart_item['data'] ) || ! RPSFW_Subscription_Product::is_subscription( $cart_item['data'] ) ) {
				continue;
			}
			$product_id = ! empty( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : ( isset( $cart_item['product_id'] ) ? $cart_item['product_id'] : 0 );
			$product    = $product_id ? wc_get_product( $product_id ) : false;
			if ( ! $product ) {
				continue;
			}
			$qty = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;

			$item           = new stdClass();
			$item->key      = $key;
			$item->object   = $cart_item;
			$item->product  = $product;
			$item->quantity = $qty;
			$item->price    = wc_add_number_precision_deep( (float) $product->get_price() * $qty );
			$items[ $key ]  = $item;

			$cache_key[] = array( $key, $product_id, $qty, $item->price );
		}
		if ( empty( $items ) ) {
			return array();
		}

		$limited = array();
		foreach ( $coupons as $code => $coupon ) {
			$cache_key[] = array( $code, $coupon->get_discount_type(), $coupon->get_amount(), self::get_payment_limit( $coupon ) );
			if ( self::get_payment_limit( $coupon ) > 0 ) {
				$limited[ $code ] = self::get_payment_limit( $coupon );
			}
		}

		$cache_key = md5( (string) wp_json_encode( $cache_key ) );
		if ( isset( self::$discount_cache[ $cache_key ] ) ) {
			return self::$discount_cache[ $cache_key ];
		}

		$all   = self::run_discounts( $items, $coupons );
		$after = $limited ? self::run_discounts( $items, array_diff_key( $coupons, $limited ) ) : $all;

		$result = array();
		foreach ( $items as $key => $item ) {
			$line_coupons = array();
			$discount     = 0.0;
			foreach ( $all as $code => $by_item ) {
				$amount = isset( $by_item[ $key ] ) ? (float) $by_item[ $key ] : 0.0;
				if ( $amount > 0 ) {
					$line_coupons[ $code ] = isset( $limited[ $code ] ) ? $limited[ $code ] : 0;
					$discount             += $amount;
				}
			}

			$discount_after = 0.0;
			foreach ( $after as $code => $by_item ) {
				$discount_after += isset( $by_item[ $key ] ) ? (float) $by_item[ $key ] : 0.0;
			}

			// Validation keeps every limited coupon in a cart on the same
			// limit; should two limits still meet here, the discount ends at
			// the first one rather than running longer than a coupon allows.
			$limits = array_filter( $line_coupons );

			$result[ $key ] = array(
				'discount'       => $discount,
				'discount_after' => $limits ? $discount_after : $discount,
				'limit'          => $limits ? (int) min( $limits ) : 0,
				'coupons'        => $line_coupons,
			);
		}

		self::$discount_cache[ $cache_key ] = $result;
		return $result;
	}

	/**
	 * Apply coupons to discount items with WooCommerce's engine.
	 *
	 * @param object[]    $items   WC_Discounts items.
	 * @param WC_Coupon[] $coupons Coupons.
	 * @return array code => array( item key => discount ), prices as entered.
	 */
	private static function run_discounts( $items, $coupons ) {
		if ( empty( $coupons ) ) {
			return array();
		}
		$discounts = new WC_Discounts();
		$discounts->set_items( $items );
		foreach ( $coupons as $coupon ) {
			$discounts->apply_coupon( $coupon, false );
		}
		return $discounts->get_discounts();
	}

	/**
	 * Line total including tax after a line discount, rounded like the
	 * undiscounted recurring line in the cart signature.
	 *
	 * @param WC_Product $product       Product (fresh object, real price).
	 * @param int        $qty           Quantity.
	 * @param float      $unit_price    Unit price as entered.
	 * @param float      $line_discount Discount on the whole line, prices as entered.
	 * @return float
	 */
	public static function discounted_line_including_tax( $product, $qty, $unit_price, $line_discount ) {
		$qty  = max( 1, (int) $qty );
		$unit = max( 0.0, ( (float) $unit_price * $qty - (float) $line_discount ) / $qty );
		$line = function_exists( 'wc_get_price_including_tax' )
			? (float) wc_get_price_including_tax( $product, array( 'qty' => $qty, 'price' => $unit ) )
			: $unit * $qty;
		return round( $line, 2 );
	}

	/**
	 * How a limited discount plays out after checkout.
	 *
	 * @param int  $limit     Payments the limited coupons cover (0 = none).
	 * @param bool $has_trial Whether a free trial defers the first payment.
	 * @param int  $length    Total number of payments (0 = until cancelled).
	 * @return array {
	 *     @type int  $renewals    Renewals billed at the discounted amount before the full amount
	 *                             applies (0 = no change after checkout).
	 *     @type bool $starts_full Whether renewals are at the full amount from the first one (the
	 *                             only discounted payment is the one at checkout).
	 * }
	 */
	public static function get_discount_schedule( $limit, $has_trial, $length ) {
		$limit  = max( 0, (int) $limit );
		$length = max( 0, (int) $length );

		if ( $limit < 1 ) {
			return array( 'renewals' => 0, 'starts_full' => false );
		}
		if ( ! $has_trial && 1 === $limit ) {
			return array( 'renewals' => 0, 'starts_full' => true );
		}

		// Without a trial the payment at checkout is the first of the limit.
		$renewals = $has_trial ? $limit : $limit - 1;

		// A limit that covers every payment of a fixed-length subscription
		// never changes the price.
		if ( $length > 0 && $renewals >= ( $has_trial ? $length : $length - 1 ) ) {
			return array( 'renewals' => 0, 'starts_full' => false );
		}

		return array( 'renewals' => $renewals, 'starts_full' => false );
	}

	// -----------------------------------------------------------------
	// Display.
	// -----------------------------------------------------------------

	/**
	 * A recurring coupon on a free-trial line takes nothing off at checkout.
	 * Say what it does instead of showing "-$0.00" (classic cart/checkout).
	 *
	 * @param string    $coupon_html          Coupon row HTML (amount + remove link).
	 * @param WC_Coupon $coupon               Coupon.
	 * @param string    $discount_amount_html Amount HTML inside $coupon_html.
	 * @return string
	 */
	public static function coupon_row_html( $coupon_html, $coupon, $discount_amount_html = '' ) {
		if ( ! self::is_recurring_coupon( $coupon ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $coupon_html;
		}
		if ( WC()->cart->get_coupon_discount_amount( $coupon->get_code(), WC()->cart->display_cart_ex_tax ) > 0 ) {
			return $coupon_html;
		}

		$note = '<span class="rpsfw-recurring-coupon-note">' . esc_html__( 'Applies to renewals', 'restore-paypal-standard-for-woocommerce' ) . '</span>';
		if ( $discount_amount_html && false !== strpos( $coupon_html, $discount_amount_html ) ) {
			return str_replace( $discount_amount_html, $note, $coupon_html );
		}
		return $note . ' ' . $coupon_html;
	}

	/**
	 * Codes of the recurring coupons discounting a subscription's renewals
	 * right now (a limited coupon drops out once it has run out).
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 * @return string[]
	 */
	public static function get_renewal_coupon_codes( $subscription ) {
		$limited_running = $subscription->has_pending_discount_change();
		$codes           = array();
		foreach ( $subscription->get_recurring_coupons() as $code => $limit ) {
			if ( 0 === $limit || $limited_running ) {
				$codes[] = (string) $code;
			}
		}
		return $codes;
	}

	/**
	 * Coupon codes on a subscription record, e.g. "SAVE20 (every payment)".
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 * @return string Plain text, '' when none.
	 */
	public static function describe_record_coupons( $subscription ) {
		$parts = array();
		foreach ( $subscription->get_recurring_coupons() as $code => $limit ) {
			$parts[] = $limit > 0
				? sprintf(
					/* translators: 1: coupon code, 2: number of payments */
					_n( '%1$s (first %2$d payment)', '%1$s (first %2$d payments)', $limit, 'restore-paypal-standard-for-woocommerce' ),
					$code,
					$limit
				)
				: sprintf(
					/* translators: %s: coupon code */
					__( '%s (every payment)', 'restore-paypal-standard-for-woocommerce' ),
					$code
				);
		}
		return implode( ', ', $parts );
	}

	// -----------------------------------------------------------------
	// Admin: "Number of payments to discount".
	// -----------------------------------------------------------------

	/**
	 * Coupon General tab field, shown for the recurring types only.
	 *
	 * @param int            $coupon_id Coupon id.
	 * @param WC_Coupon|null $coupon    Coupon.
	 */
	public static function render_limit_field( $coupon_id, $coupon = null ) {
		if ( ! $coupon instanceof WC_Coupon ) {
			$coupon = new WC_Coupon( $coupon_id );
		}
		$limit = max( 0, (int) $coupon->get_meta( self::META_LIMIT ) );

		woocommerce_wp_text_input(
			array(
				'id'                => self::FIELD_LIMIT,
				'label'             => __( 'Number of payments to discount', 'restore-paypal-standard-for-woocommerce' ),
				'placeholder'       => __( 'Every payment', 'restore-paypal-standard-for-woocommerce' ),
				'description'       => __( 'How many subscription payments get the discount. After that, renewals go back to the full price. The first payment counts as one, unless the subscription starts with a free trial. Leave blank to discount every payment.', 'restore-paypal-standard-for-woocommerce' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '1',
					'step' => '1',
				),
				'value'             => $limit > 0 ? (string) $limit : '',
			)
		);
		?>
		<script>
			jQuery( function ( $ ) {
				var $type = $( '#discount_type' );
				function rpsfwTogglePaymentLimit() {
					$( '.<?php echo esc_js( self::FIELD_LIMIT ); ?>_field' ).toggle( -1 !== $.inArray( $type.val(), [ '<?php echo esc_js( self::TYPE_FIXED ); ?>', '<?php echo esc_js( self::TYPE_PERCENT ); ?>' ] ) );
				}
				$type.on( 'change', rpsfwTogglePaymentLimit );
				rpsfwTogglePaymentLimit();
			} );
		</script>
		<?php
	}

	/**
	 * Save "Number of payments to discount".
	 *
	 * @param int            $post_id Coupon id.
	 * @param WC_Coupon|null $coupon  Coupon.
	 */
	public static function save_limit_field( $post_id, $coupon = null ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified its coupon meta box nonce before firing this action.
		$raw   = isset( $_POST[ self::FIELD_LIMIT ] ) ? wc_clean( wp_unslash( $_POST[ self::FIELD_LIMIT ] ) ) : '';
		$limit = '' === $raw ? 0 : absint( $raw );

		if ( ! $coupon instanceof WC_Coupon ) {
			$coupon = new WC_Coupon( $post_id );
		}
		if ( $limit > 0 ) {
			$coupon->update_meta_data( self::META_LIMIT, $limit );
		} else {
			$coupon->delete_meta_data( self::META_LIMIT );
		}
		$coupon->save_meta_data();
	}

	// -----------------------------------------------------------------
	// Records.
	// -----------------------------------------------------------------

	/**
	 * Store a subscription's coupon discount from its signature.
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 * @param array              $signature    Signature (one subscription's fields).
	 */
	public static function store_record_discount( $subscription, $signature ) {
		$coupons = isset( $signature['recurring_coupons'] ) && is_array( $signature['recurring_coupons'] ) ? $signature['recurring_coupons'] : array();
		if ( empty( $coupons ) ) {
			return;
		}

		$amount   = isset( $signature['recurring_amount'] ) ? (float) $signature['recurring_amount'] : $subscription->get_recurring_amount();
		$full     = isset( $signature['recurring_amount_full'] ) ? (float) $signature['recurring_amount_full'] : $amount;
		$renewals = isset( $signature['discounted_renewals'] ) ? max( 0, (int) $signature['discounted_renewals'] ) : 0;

		$subscription->set_discount(
			array(
				'coupons'       => $coupons,
				'amount_full'   => $full,
				'renewals'      => $renewals,
				'discount'      => isset( $signature['recurring_discount'] ) ? (float) $signature['recurring_discount'] : 0.0,
				'discount_full' => isset( $signature['recurring_discount_full'] ) ? (float) $signature['recurring_discount_full'] : 0.0,
			)
		);

		$subscription->add_note(
			sprintf(
				/* translators: 1: coupon code(s) with their payment limits, 2: recurring amount with schedule */
				__( 'Recurring coupon applied: %1$s. Renewals: %2$s.', 'restore-paypal-standard-for-woocommerce' ),
				self::describe_record_coupons( $subscription ),
				rpsfw_format_recurring_amount( $amount, $subscription->get_currency(), $subscription->get_interval(), $subscription->get_period(), $renewals, $full, true )
			)
		);
	}

	/**
	 * After the last discounted renewal, move the subscription to its full
	 * price. PayPal plans and Stripe subscription schedules (set up at
	 * checkout, see RPSFW_Subscriptions_Stripe::apply_discount_schedule())
	 * already carry the price change, so only the record is updated for them.
	 * Only a Stripe subscription without a schedule (Stripe refused it) gets
	 * its price switched here.
	 *
	 * Fired after every recorded renewal, so a failed Stripe update is retried
	 * with the next one.
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 */
	public static function maybe_end_limited_discount( $subscription ) {
		if ( ! $subscription instanceof RPSFW_Subscription || ! $subscription->has_pending_discount_change() ) {
			return;
		}
		if ( count( $subscription->get_renewal_order_ids() ) < $subscription->get_discounted_renewals() ) {
			return;
		}

		$full      = $subscription->get_recurring_amount_full();
		$formatted = wp_strip_all_tags( wc_price( $full, array( 'currency' => $subscription->get_currency() ) ) );

		if ( 'rpsfw_stripe' === $subscription->get_gateway_id()
			&& ! get_post_meta( $subscription->get_id(), '_rpsfw_stripe_schedule_id', true ) ) {
			$result = RPSFW_Subscriptions_Stripe::remote_set_recurring_amount( $subscription, $full );
			if ( is_wp_error( $result ) ) {
				$subscription->add_note(
					sprintf(
						/* translators: 1: full recurring amount, 2: error message */
						__( 'The coupon discount has run out, but the Stripe subscription could not be moved to the full price of %1$s: %2$s. This is retried after the next renewal; to avoid another discounted payment, change the subscription\'s price in your Stripe dashboard.', 'restore-paypal-standard-for-woocommerce' ),
						$formatted,
						$result->get_error_message()
					)
				);
				return;
			}
		}

		$subscription->end_discount();
		$subscription->add_note(
			sprintf(
				/* translators: %s: full recurring amount */
				__( 'The coupon discount has run out. Renewals now bill %s.', 'restore-paypal-standard-for-woocommerce' ),
				$formatted
			)
		);
	}
}
