<?php
/**
 * Stripe API Helper Class
 *
 * Handles all Stripe API interactions
 *
 * @package RestorePayPalStandard
 */

defined( 'ABSPATH' ) || exit;

// Load Stripe PHP library with error handling. Skip the load if another
// plugin already loaded a Stripe SDK — PHP can only have one
// \Stripe\StripeClient class loaded at a time, and re-declaring it would
// fatal-error. We're version-tolerant: even if theirs is older, every
// Stripe SDK shares the same public API surface for the calls we use.
try {
    if ( ! class_exists( '\\Stripe\\StripeClient' ) ) {
        if ( file_exists( RPSFW_PLUGIN_DIR . 'includes/stripe/lib/stripe-php-20.2.0/init.php' ) ) {
            require_once RPSFW_PLUGIN_DIR . 'includes/stripe/lib/stripe-php-20.2.0/init.php';
        } else {
            if ( function_exists( 'wc_get_logger' ) ) {
                $logger = wc_get_logger();
                $logger->error( 'Stripe library init.php not found', array( 'source' => 'rpsfw-stripe' ) );
            }
        }
    }
} catch ( Exception $e ) {
    if ( function_exists( 'wc_get_logger' ) ) {
        $logger = wc_get_logger();
        $logger->error( 'Failed to load Stripe library: ' . $e->getMessage(), array( 'source' => 'rpsfw-stripe' ) );
    }
}

/**
 * RPSFW_Stripe_API Class
 */
class RPSFW_Stripe_API {

    /**
     * Stripe client instance
     *
     * @var \Stripe\StripeClient
     */
    /**
     * Cached clients keyed by mode ('test' | 'live').
     *
     * @var array
     */
    private static $clients = array();

    /**
     * Mode forced for the duration of this request, or '' to follow the
     * gateway setting. Set by the webhook handler from the event's livemode
     * flag so every lookup, refund and enrichment during that request talks to
     * the account the event actually came from.
     *
     * @var string
     */
    private static $request_mode = '';

    /**
     * Force the mode for the remainder of this request.
     *
     * @param string $mode 'test' or 'live'. Pass '' to clear.
     */
    public static function set_request_mode( $mode ) {
        self::$request_mode = ( '' === $mode ) ? '' : rpsfw_normalize_payment_mode( $mode );
    }

    /**
     * The mode API calls should use right now: the request override when one is
     * set, otherwise the gateway's configured mode.
     *
     * @return string 'test' or 'live'.
     */
    public static function get_request_mode() {
        return self::$request_mode ? self::$request_mode : rpsfw_get_gateway_mode( 'rpsfw_stripe' );
    }

    /**
     * Get Stripe client instance
     *
     * @param string|null $mode Mode to build the client for. Defaults to the
     *                          current request mode.
     * @return \Stripe\StripeClient|null
     */
    public static function get_client( $mode = null ) {
        $mode = ( null === $mode ) ? self::get_request_mode() : rpsfw_normalize_payment_mode( $mode );

        if ( ! isset( self::$clients[ $mode ] ) ) {
            $secret_key = self::get_secret_key( $mode );

            if ( empty( $secret_key ) ) {
                RPSFW_Gateway_Stripe::log( 'Stripe API: No secret key available for ' . $mode . ' mode', 'error' );
                return null;
            }

            try {
                // Pin the API version so the webhook payload shape stays
                // consistent with the version we also pin on webhook creation.
                // NOTE: on this version (basil/dahlia era) the legacy
                // PaymentIntent.charges list and Invoice.payment_intent field
                // no longer exist — read latest_charge and
                // latest_invoice.confirmation_secret instead (handled by the
                // helper methods in this class).
                self::$clients[ $mode ] = new \Stripe\StripeClient( array(
                    'api_key'        => $secret_key,
                    'stripe_version' => '2026-05-27.dahlia',
                ) );
            } catch ( Exception $e ) {
                RPSFW_Gateway_Stripe::log( 'Stripe API: Failed to initialize ' . $mode . ' client - ' . $e->getMessage(), 'error' );
                return null;
            }
        }

        return self::$clients[ $mode ];
    }

    /**
     * Whether credentials exist for a mode. Used to decide whether an
     * opposite-mode webhook can be enriched with API lookups.
     *
     * @param string $mode 'test' or 'live'.
     * @return bool
     */
    public static function has_credentials_for_mode( $mode ) {
        return '' !== self::get_secret_key( $mode );
    }

    /**
     * Reset the cached Stripe clients. Call this after a mode or its secret key
     * changes (e.g. after Stripe Connect completion or disconnect) so the next
     * get_client() builds a client with the current credentials.
     */
    public static function reset_client() {
        self::$clients = array();
    }

    /**
     * Build the relay-routing metadata that we stamp onto every Stripe
     * object we create (customers, subscriptions, payment intents).
     *
     * The wpplugin Connect server reads rpsfw_site_url off the event's
     * object to know where to forward the webhook, and rpsfw_shared_secret
     * to sign the forwarded request. The shared secret is the per-merchant
     * random value handed back by the Connect server at OAuth completion
     * and stored locally; stamping it inline lets the relay resolve and
     * sign without a Stripe API round-trip.
     *
     * @return array
     */
    public static function get_relay_metadata() {
        $options  = get_option( 'woocommerce_rpsfw_stripe_settings', array() );
        $testmode = isset( $options['testmode'] ) && 'yes' === $options['testmode'];
        $mode     = $testmode ? 'sandbox' : 'live';

        $secret_key = $testmode ? 'relay_shared_secret_test' : 'relay_shared_secret_live';
        $shared_secret = isset( $options[ $secret_key ] ) ? $options[ $secret_key ] : '';

        $meta = array(
            'rpsfw_site_url' => untrailingslashit( home_url() ),
            'rpsfw_mode'     => $mode,
            'rpsfw_plugin'   => 'rpsfw',
        );

        if ( $shared_secret ) {
            $meta['rpsfw_shared_secret'] = $shared_secret;
        }

        return $meta;
    }

    /**
     * Build consistent line-item metadata for Stripe from a cart or order.
     *
     * Stripe's PaymentIntent has no structured line-item/breakdown field (unlike
     * PayPal's Orders API), so the itemization is expressed as metadata. This
     * builder is shared by the one-off PaymentIntent flow and the subscription
     * flow so both carry the same item / shipping / tax / discount / coupon
     * detail. Prefers the order (most accurate, post-checkout) and falls back to
     * the cart. Respects Stripe's 500-char-per-value limit.
     *
     * @param WC_Cart|null  $cart  Cart to read from (used when no order).
     * @param WC_Order|null $order Order to read from (preferred when available).
     * @return array
     */
    public static function build_line_item_metadata( $cart = null, $order = null ) {
        $metadata       = array();
        $item_summaries = array();
        $item_count     = 0;
        $shipping_total = 0.0;
        $tax_total      = 0.0;
        $discount_total = 0.0;
        $coupon_codes   = array();

        if ( $order ) {
            foreach ( $order->get_items() as $line_item ) {
                $qty          = (int) $line_item->get_quantity();
                $item_count  += $qty;
                $line_gross   = (float) $line_item->get_total() + (float) $line_item->get_total_tax();
                $unit         = $qty > 0 ? $line_gross / $qty : $line_gross;
                $item_summaries[] = $qty . 'x ' . $line_item->get_name() . ' @ ' . number_format( $unit, 2, '.', '' );
            }
            $shipping_total = (float) $order->get_shipping_total();
            $tax_total      = (float) $order->get_total_tax();
            $discount_total = (float) $order->get_total_discount();
            $coupon_codes   = $order->get_coupon_codes();

            // Per-coupon discount breakdown (order only).
            $coupon_details = array();
            foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
                $coupon_details[] = $coupon_item->get_code() . ':' . number_format( (float) $coupon_item->get_discount(), 2, '.', '' );
            }
            if ( ! empty( $coupon_details ) ) {
                $metadata['coupon_details'] = implode( '; ', $coupon_details );
            }
        } elseif ( $cart ) {
            foreach ( $cart->get_cart() as $cart_item ) {
                $product      = isset( $cart_item['data'] ) ? $cart_item['data'] : null;
                $qty          = (int) $cart_item['quantity'];
                $item_count  += $qty;
                $unit         = $product ? (float) $product->get_price() : 0;
                $item_summaries[] = $qty . 'x ' . ( $product ? $product->get_name() : __( 'Item', 'restore-paypal-standard-for-woocommerce' ) ) . ' @ ' . number_format( $unit, 2, '.', '' );
            }
            $shipping_total = (float) $cart->get_shipping_total();
            $tax_total      = (float) $cart->get_total_tax();
            $discount_total = (float) $cart->get_discount_total();
            $coupon_codes   = $cart->get_applied_coupons();
        } else {
            return $metadata;
        }

        if ( ! empty( $item_summaries ) ) {
            $items_string = implode( ', ', $item_summaries );
            if ( strlen( $items_string ) > 500 ) {
                $items_string = substr( $items_string, 0, 497 ) . '...';
            }
            $metadata['items']      = $items_string;
            $metadata['item_count'] = (string) $item_count;
        }
        if ( $shipping_total > 0 ) {
            $metadata['shipping_total'] = number_format( $shipping_total, 2, '.', '' );
        }
        if ( $tax_total > 0 ) {
            $metadata['tax_total'] = number_format( $tax_total, 2, '.', '' );
        }
        if ( $discount_total > 0 ) {
            $metadata['discount_total'] = number_format( $discount_total, 2, '.', '' );
        }
        if ( ! empty( $coupon_codes ) ) {
            $metadata['coupon_codes'] = implode( ', ', $coupon_codes );
        }

        return $metadata;
    }

    /**
     * Get secret key based on mode
     *
     * @param string|null $mode 'test' or 'live'. Defaults to the current
     *                          request mode (gateway setting, or the mode the
     *                          in-flight webhook event came from).
     * @return string
     */
    public static function get_secret_key( $mode = null ) {
        $options = get_option( 'woocommerce_rpsfw_stripe_settings', array() );
        $mode    = ( null === $mode ) ? self::get_request_mode() : rpsfw_normalize_payment_mode( $mode );

        $secret_key_key = ( 'test' === $mode ) ? 'secret_key_test' : 'secret_key_live';

        return isset( $options[$secret_key_key] ) ? $options[$secret_key_key] : '';
    }

    /**
     * Get publishable key based on mode
     *
     * @return string
     */
    public static function get_publishable_key() {
        $options = get_option( 'woocommerce_rpsfw_stripe_settings', array() );
        $testmode = isset( $options['testmode'] ) && $options['testmode'] === 'yes';
        $key = $testmode ? 'publishable_key_test' : 'publishable_key_live';
        
        // Get the platform publishable key
        $publishable_key = isset( $options[$key] ) ? $options[$key] : '';
        
        // Debug logging
        RPSFW_Gateway_Stripe::log( 'get_publishable_key() - testmode: ' . ( $testmode ? 'yes' : 'no' ) . ', key: ' . $key . ', value: ' . $publishable_key );
        
        return $publishable_key;
    }

    /**
     * Create a payment intent
     *
     * @param float  $amount Amount in store currency
     * @param string $currency Currency code
     * @param array  $args Additional arguments
     * @return \Stripe\PaymentIntent|WP_Error
     */
    public static function create_payment_intent( $amount, $currency, $args = array() ) {
        $client = self::get_client();
        
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }

        // Convert amount to cents
        $amount_cents = self::get_stripe_amount( $amount, $currency );

        // Get payment action setting
        $options = get_option( 'woocommerce_rpsfw_stripe_settings', array() );
        $payment_action = isset( $options['payment_action'] ) ? $options['payment_action'] : '';
        
        // Backward compatibility: check old 'capture' checkbox if payment_action not set
        if ( empty( $payment_action ) ) {
            $capture_setting = isset( $options['capture'] ) ? $options['capture'] : 'yes';
            $payment_action = ( 'yes' === $capture_setting ) ? 'capture' : 'authorize';
        }

        // Get enabled payment method types from settings, filtered by amount/currency requirements
        $payment_method_types = self::get_enabled_payment_method_types( $amount, $currency );

        $defaults = array(
            'amount' => $amount_cents,
            'currency' => strtolower( $currency ),
        );

        // Use automatic payment methods if available, otherwise use manual list
        if ( ! empty( $payment_method_types ) ) {
            $defaults['payment_method_types'] = $payment_method_types;
        } else {
            // Fallback to automatic payment methods
            $defaults['automatic_payment_methods'] = array(
                'enabled' => true,
            );
        }

        // Set capture_method based on payment action
        if ( 'authorize' === $payment_action ) {
            $defaults['capture_method'] = 'manual';

            // Request extended authorization if enabled — only meaningful for authorize mode.
            if ( isset( $options['extended_authorization'] ) && 'yes' === $options['extended_authorization'] ) {
                $defaults['payment_method_options'] = array(
                    'card' => array(
                        'request_extended_authorization' => 'if_available',
                    ),
                );
            }
        }

        // ACH (us_bank_account): configure inline bank collection + verification
        // via Financial Connections so the Payment Element can gather and verify
        // the customer's bank account without leaving checkout. Assigned by key
        // so it coexists with any card options set above. Only relevant when ACH
        // is actually in the method list (see maybe_add_ach_payment_method).
        if ( in_array( 'us_bank_account', $payment_method_types, true ) ) {
            $defaults['payment_method_options']['us_bank_account'] = array(
                'verification_method'   => 'automatic',
                'financial_connections' => array(
                    'permissions' => array( 'payment_method' ),
                ),
            );
        }

        // Resolve order ID early — needed for variable substitution in
        // statement descriptors as well as the payment intent args filter below.
        $order_for_filter = null;
        $order_id_for_descriptor = 0;
        if ( isset( $args['metadata']['order_id'] ) && $args['metadata']['order_id'] ) {
            $order_id_for_descriptor = absint( $args['metadata']['order_id'] );
            $order_for_filter        = wc_get_order( $order_id_for_descriptor );
        }

        // Apply statement descriptor settings if configured.
        // For card payments Stripe rejects `statement_descriptor` on the
        // PaymentIntent with a 400 error and requires `statement_descriptor_suffix`
        // instead. This gateway is card-based, so we only ever send the suffix.
        // If the merchant configured a full statement_descriptor but no suffix,
        // use it as the suffix. Supports the {order_id} variable.
        $statement_descriptor        = isset( $options['statement_descriptor'] ) ? sanitize_text_field( $options['statement_descriptor'] ) : '';
        $statement_descriptor_suffix = isset( $options['statement_descriptor_suffix'] ) ? sanitize_text_field( $options['statement_descriptor_suffix'] ) : '';
        if ( empty( $statement_descriptor_suffix ) && ! empty( $statement_descriptor ) ) {
            $statement_descriptor_suffix = $statement_descriptor;
        }
        if ( ! empty( $statement_descriptor_suffix ) ) {
            $statement_descriptor_suffix = str_replace( '{order_id}', $order_id_for_descriptor, $statement_descriptor_suffix );
            $defaults['statement_descriptor_suffix'] = substr( $statement_descriptor_suffix, 0, 22 );
        }

        $params = wp_parse_args( $args, $defaults );

        // Stamp relay-routing metadata so webhook forwarding can resolve
        // the merchant from charge/payment_intent events without a Stripe
        // API call.
        $existing_meta = isset( $params['metadata'] ) && is_array( $params['metadata'] ) ? $params['metadata'] : array();
        $params['metadata'] = array_merge( $existing_meta, self::get_relay_metadata() );

        /**
         * Allow extensions (e.g. WooCommerce Subscriptions integration) to
         * adjust PaymentIntent args. Receives the args, amount, and order if
         * known.
         */
        $params = apply_filters( 'rpsfw_stripe_payment_intent_args', $params, $amount, $order_for_filter );

        try {
            RPSFW_Gateway_Stripe::log( 'Creating payment intent: ' . wp_json_encode( $params ) );
            $intent = $client->paymentIntents->create( $params );
            RPSFW_Gateway_Stripe::log( 'Payment intent created: ' . $intent->id );
            return $intent;
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            // Resilience: optional methods (Link, ACH/us_bank_account) can be
            // rejected by a given connected account — e.g. switched off on the
            // account, or an unsupported country/currency. Stripe then rejects
            // the whole intent. Rather than break checkout, strip the optional
            // methods and retry with what remains (card) so the payment can
            // still go through.
            $optional_methods = array( 'link', 'us_bank_account' );
            if ( ! empty( $params['payment_method_types'] )
                && is_array( $params['payment_method_types'] )
                && array_intersect( $optional_methods, $params['payment_method_types'] ) ) {
                $reduced = array_values( array_diff( $params['payment_method_types'], $optional_methods ) );
                if ( empty( $reduced ) ) {
                    $reduced = array( 'card' );
                }
                RPSFW_Gateway_Stripe::log( 'Payment intent creation failed with optional methods (' . $e->getMessage() . '). Retrying with: ' . implode( ',', $reduced ), 'warning' );
                $params['payment_method_types'] = $reduced;
                // Drop method-specific options that are invalid without the method.
                if ( isset( $params['payment_method_options']['us_bank_account'] ) && ! in_array( 'us_bank_account', $reduced, true ) ) {
                    unset( $params['payment_method_options']['us_bank_account'] );
                }
                try {
                    $intent = $client->paymentIntents->create( $params );
                    RPSFW_Gateway_Stripe::log( 'Payment intent created on retry: ' . $intent->id );
                    return $intent;
                } catch ( \Stripe\Exception\ApiErrorException $e2 ) {
                    RPSFW_Gateway_Stripe::log( 'Payment intent creation failed (retry): ' . $e2->getMessage(), 'error' );
                    return new WP_Error( 'stripe_error', $e2->getMessage() );
                }
            }
            RPSFW_Gateway_Stripe::log( 'Payment intent creation failed: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }

    /**
     * Get enabled payment method types from settings
     *
     * @param float  $amount Optional. Amount to filter methods by requirements.
     * @param string $currency Optional. Currency code to filter methods by support.
     * @return array
     */
    public static function get_enabled_payment_method_types( $amount = null, $currency = null ) {
        $options = get_option( 'woocommerce_rpsfw_stripe_settings', array() );

        // Card-only launch: while the Payment Options tab is hidden, the
        // payment flow must ship card-only regardless of any values still
        // persisted in the settings option (e.g. a stale enable_apple_pay=yes
        // left over from a previous release). This makes the documented intent
        // in stripe-settings.php actually true and prevents invalid types from
        // reaching Stripe. Link is handled separately below because its toggle
        // lives on the always-available Digital Wallets tab, not the (hidden)
        // Payment Options tab.
        if ( class_exists( 'RPSFW_Gateway_Stripe_Settings' ) && ! RPSFW_Gateway_Stripe_Settings::payment_options_tab_enabled() ) {
            $payment_methods = array( 'card' );
            $payment_methods = self::maybe_add_link_payment_method( $payment_methods, $options );
            $payment_methods = self::maybe_add_ach_payment_method( $payment_methods, $options, $currency );
            return $payment_methods;
        }

        $payment_methods = array();

        // Map of setting keys to Stripe payment method types.
        //
        // Only `card` is active for this release. Every other method is kept
        // here but commented out so it can be re-enabled once tested. NOTE:
        // apple_pay and google_pay are NOT valid values for Stripe's
        // payment_method_types param — Stripe surfaces them as wallet
        // presentations of the `card` type via the Payment Element `wallets`
        // config (see RPSFW_Gateway_Stripe::get_wallets_config). Adding them to
        // payment_method_types causes "The payment method type apple_pay is
        // invalid" on intent creation, so leave them commented unless that
        // behavior changes.
        $method_map = array(
            'enable_card'              => 'card',
            // 'enable_apple_pay'         => 'apple_pay',
            // 'enable_google_pay'        => 'google_pay',
            // 'enable_link'              => 'link',
            // 'enable_cashapp'           => 'cashapp',
            // 'enable_klarna'            => 'klarna',
            // 'enable_afterpay'          => 'afterpay_clearpay',
            // 'enable_affirm'            => 'affirm',
            // 'enable_us_bank_account'   => 'us_bank_account',
            // 'enable_sepa_debit'        => 'sepa_debit',
            // 'enable_ideal'             => 'ideal',
            // 'enable_bancontact'        => 'bancontact',
            // 'enable_giropay'           => 'giropay',
            // 'enable_sofort'            => 'sofort',
            // 'enable_alipay'            => 'alipay',
            // 'enable_wechat_pay'        => 'wechat_pay',
        );

        foreach ( $method_map as $setting_key => $stripe_type ) {
            if ( isset( $options[ $setting_key ] ) && 'yes' === $options[ $setting_key ] ) {
                // Check if this payment method meets requirements for the given amount/currency
                if ( self::payment_method_meets_requirements( $stripe_type, $amount, $currency ) ) {
                    $payment_methods[] = $stripe_type;
                }
            }
        }

        // Always include card if nothing is selected or if card is explicitly enabled
        if ( empty( $payment_methods ) || in_array( 'card', $payment_methods, true ) ) {
            if ( ! in_array( 'card', $payment_methods, true ) ) {
                $payment_methods[] = 'card';
            }
        }

        $payment_methods = self::maybe_add_link_payment_method( $payment_methods, $options );
        $payment_methods = self::maybe_add_ach_payment_method( $payment_methods, $options, $currency );

        return $payment_methods;
    }

    /**
     * Append Stripe Link to a payment method type list when the merchant has
     * enabled it via the Digital Wallets "Enable Link" setting.
     *
     * Link ("save my info for faster checkout") rides on the card rails, so the
     * mechanism that makes it both APPEAR and FUNCTION in the Payment Element is
     * including 'link' in the PaymentIntent's payment_method_types. With Link in
     * the list, the customer's card is saved to their Link account at purchase
     * and returning shoppers are recognised by email so they can autofill.
     * Omitting 'link' hides Link entirely.
     *
     * This gives merchants a real, per-store on/off switch that does NOT require
     * changing the connected (or platform) account's Stripe Dashboard settings —
     * important on a Connect platform where merchants share the platform account
     * and each wants independent control.
     *
     * @param array $payment_methods Base payment method types.
     * @param array $options         The gateway settings option array.
     * @return array
     */
    private static function maybe_add_link_payment_method( $payment_methods, $options ) {
        $enable_link = isset( $options['enable_link'] ) ? $options['enable_link'] : 'yes';
        if ( 'yes' === $enable_link && ! in_array( 'link', $payment_methods, true ) ) {
            $payment_methods[] = 'link';
        }
        return $payment_methods;
    }

    /**
     * Payment method types offered for a subscription: card, plus Link when the
     * merchant has it enabled. Link rides on the card rails and supports
     * off-session / subscription reuse, so returning shoppers are recognised by
     * email and can pay a subscription's first invoice with Link (renewals then
     * bill off-session against the saved card).
     *
     * Used in BOTH places that must agree: the deferred Payment Element (so Link
     * is offered) and the Stripe Subscription's payment_settings (so the first
     * invoice actually allows Link). Other methods (ACH etc.) are intentionally
     * excluded — subscriptions bill off-session and only card/Link qualify here.
     *
     * @return array
     */
    public static function subscription_payment_method_types() {
        $options = get_option( 'woocommerce_rpsfw_stripe_settings', array() );
        return self::maybe_add_link_payment_method( array( 'card' ), $options );
    }

    /**
     * Append ACH (us_bank_account) to a payment method type list when the
     * merchant has enabled it and the store currency supports it.
     *
     * ACH direct debit is US-only and only settles in USD, so we never add it
     * for a non-USD store (Stripe would reject the intent). Including
     * 'us_bank_account' makes the Payment Element render the bank-selection UI
     * (via Financial Connections) and the mandate text automatically. ACH funds
     * settle asynchronously, so the resulting PaymentIntent lands in
     * 'processing' at checkout and is completed later by the
     * payment_intent.succeeded webhook.
     *
     * @param array       $payment_methods Base payment method types.
     * @param array       $options         The gateway settings option array.
     * @param string|null $currency        Currency code for the current cart/order.
     * @return array
     */
    private static function maybe_add_ach_payment_method( $payment_methods, $options, $currency = null ) {
        // DISABLED FOR THIS RELEASE — ACH is not shipping yet. Remove this early
        // return (and un-comment the ACH settings field) to re-enable. The rest
        // of the ACH implementation below is left intact.
        return $payment_methods;

        $enable_ach = isset( $options['enable_ach'] ) ? $options['enable_ach'] : 'no';
        if ( 'yes' !== $enable_ach ) {
            return $payment_methods;
        }

        // ACH is USD-only. Fall back to the store currency when none was passed
        // (e.g. admin-side calls that build the display order).
        $resolved_currency = $currency ? $currency : get_woocommerce_currency();
        if ( 'USD' !== strtoupper( (string) $resolved_currency ) ) {
            return $payment_methods;
        }

        if ( ! in_array( 'us_bank_account', $payment_methods, true ) ) {
            $payment_methods[] = 'us_bank_account';
        }
        return $payment_methods;
    }

    /**
     * Check if a payment method meets requirements for the given amount and currency
     *
     * @param string $method Payment method type
     * @param float  $amount Amount in store currency
     * @param string $currency Currency code
     * @return bool
     */
    public static function payment_method_meets_requirements( $method, $amount = null, $currency = null ) {
        // If no amount provided, assume it meets requirements (will be validated by Stripe)
        if ( is_null( $amount ) ) {
            return true;
        }

        $currency = strtoupper( $currency ?: get_woocommerce_currency() );

        // Payment method requirements (minimum amounts in USD equivalent)
        // These are approximate - Stripe handles exact validation
        $requirements = array(
            'affirm' => array(
                'min_amount' => array(
                    'USD' => 50,   // $50 minimum for Affirm in USD
                    'CAD' => 50,   // $50 CAD minimum
                ),
                'max_amount' => array(
                    'USD' => 30000,
                    'CAD' => 30000,
                ),
                'currencies' => array( 'USD', 'CAD' ),
            ),
            'afterpay_clearpay' => array(
                'min_amount' => array(
                    'USD' => 1,
                    'CAD' => 1,
                    'AUD' => 1,
                    'NZD' => 1,
                    'GBP' => 1,
                ),
                'max_amount' => array(
                    'USD' => 4000,
                    'CAD' => 4000,
                    'AUD' => 2000,
                    'NZD' => 2000,
                    'GBP' => 1200,
                ),
                'currencies' => array( 'USD', 'CAD', 'AUD', 'NZD', 'GBP' ),
            ),
            'klarna' => array(
                'min_amount' => array(
                    'USD' => 1,
                    'EUR' => 1,
                    'GBP' => 1,
                    'SEK' => 10,
                    'NOK' => 10,
                    'DKK' => 10,
                ),
                'max_amount' => array(
                    'USD' => 10000,
                    'EUR' => 10000,
                    'GBP' => 10000,
                    'SEK' => 100000,
                    'NOK' => 100000,
                    'DKK' => 100000,
                ),
                'currencies' => array( 'USD', 'EUR', 'GBP', 'SEK', 'NOK', 'DKK', 'CHF', 'PLN', 'CZK', 'AUD', 'NZD', 'CAD' ),
            ),
            'cashapp' => array(
                'currencies' => array( 'USD' ),
            ),
            'ideal' => array(
                'currencies' => array( 'EUR' ),
            ),
            'bancontact' => array(
                'currencies' => array( 'EUR' ),
            ),
            'giropay' => array(
                'currencies' => array( 'EUR' ),
            ),
            'sofort' => array(
                'currencies' => array( 'EUR' ),
            ),
            'sepa_debit' => array(
                'currencies' => array( 'EUR' ),
            ),
            'alipay' => array(
                'currencies' => array( 'CNY', 'AUD', 'CAD', 'EUR', 'GBP', 'HKD', 'JPY', 'SGD', 'MYR', 'NZD', 'USD' ),
            ),
            'wechat_pay' => array(
                'currencies' => array( 'CNY', 'AUD', 'CAD', 'EUR', 'GBP', 'HKD', 'JPY', 'SGD', 'USD' ),
            ),
            'us_bank_account' => array(
                'currencies' => array( 'USD' ),
            ),
        );

        // If no specific requirements defined, allow the method
        if ( ! isset( $requirements[ $method ] ) ) {
            return true;
        }

        $method_reqs = $requirements[ $method ];

        // Check currency support
        if ( isset( $method_reqs['currencies'] ) && ! in_array( $currency, $method_reqs['currencies'], true ) ) {
            RPSFW_Gateway_Stripe::log( sprintf( 'Payment method %s not available for currency %s', $method, $currency ) );
            return false;
        }

        // Check minimum amount
        if ( isset( $method_reqs['min_amount'] ) ) {
            $min = isset( $method_reqs['min_amount'][ $currency ] ) ? $method_reqs['min_amount'][ $currency ] : null;
            if ( $min !== null && $amount < $min ) {
                RPSFW_Gateway_Stripe::log( sprintf( 'Payment method %s requires minimum %s %s, cart is %s', $method, $min, $currency, $amount ) );
                return false;
            }
        }

        // Check maximum amount
        if ( isset( $method_reqs['max_amount'] ) ) {
            $max = isset( $method_reqs['max_amount'][ $currency ] ) ? $method_reqs['max_amount'][ $currency ] : null;
            if ( $max !== null && $amount > $max ) {
                RPSFW_Gateway_Stripe::log( sprintf( 'Payment method %s has maximum %s %s, cart is %s', $method, $max, $currency, $amount ) );
                return false;
            }
        }

        return true;
    }

    /**
     * Retrieve a payment intent
     *
     * @param string $intent_id Payment intent ID
     * @return \Stripe\PaymentIntent|WP_Error
     */
    public static function retrieve_payment_intent( $intent_id ) {
        $client = self::get_client();
        
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }

        try {
            // Expand latest_charge so callers can read the charge id and card
            // details. The legacy PaymentIntent.charges list was removed in
            // modern API versions (replaced by latest_charge).
            return $client->paymentIntents->retrieve( $intent_id, array( 'expand' => array( 'latest_charge' ) ) );
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Failed to retrieve payment intent: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }

    /**
     * Retrieve an Invoice with its payment references expanded so callers can
     * resolve the underlying charge / payment intent (e.g. for refunds on
     * subscription and renewal orders whose transaction id is a sub_/in_ id).
     *
     * @param string $invoice_id Invoice id (in_...).
     * @return \Stripe\Invoice|WP_Error
     */
    public static function retrieve_invoice( $invoice_id ) {
        $client = self::get_client();

        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }

        try {
            return $client->invoices->retrieve( $invoice_id, array( 'expand' => array( 'payments' ) ) );
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Failed to retrieve invoice: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }

    /**
     * Retrieve a SetupIntent (payment_method expanded so callers can read the
     * authenticated card without a second API call).
     *
     * Used by the independent-subscriptions flow: the card is authenticated
     * once via a SetupIntent, then that payment method backs each of the
     * separate subscriptions created off-session.
     *
     * @param string $setup_intent_id SetupIntent id (seti_).
     * @return \Stripe\SetupIntent|WP_Error
     */
    public static function retrieve_setup_intent( $setup_intent_id ) {
        $client = self::get_client();

        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }

        try {
            return $client->setupIntents->retrieve( $setup_intent_id, array( 'expand' => array( 'payment_method' ) ) );
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Failed to retrieve setup intent: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }

    /**
     * Return the Charge object associated with a PaymentIntent, handling both
     * the modern `latest_charge` field (API 2022-11-15+) and the legacy
     * `charges.data[0]` list. Returns the Charge object, or null.
     *
     * For the charge details (id, card brand/last4) to be present, the
     * PaymentIntent must have been retrieved with `latest_charge` expanded
     * (see retrieve_payment_intent).
     *
     * @param object $intent PaymentIntent object.
     * @return object|null
     */
    /**
     * Retrieve a Stripe Charge by id.
     *
     * @param string $charge_id Charge id (ch_...).
     * @return \Stripe\Charge|WP_Error
     */
    public static function retrieve_charge( $charge_id ) {
        $client = self::get_client();
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }
        try {
            return $client->charges->retrieve( $charge_id, array() );
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }

    public static function get_charge_from_intent( $intent ) {
        if ( empty( $intent ) ) {
            return null;
        }
        // Modern: latest_charge (expanded object).
        if ( ! empty( $intent->latest_charge ) && is_object( $intent->latest_charge ) ) {
            return $intent->latest_charge;
        }
        // Legacy: charges.data[0].
        if ( ! empty( $intent->charges ) && ! empty( $intent->charges->data ) && is_object( $intent->charges->data[0] ) ) {
            return $intent->charges->data[0];
        }
        return null;
    }

    /**
     * Extract the first-invoice PaymentIntent client secret from a Subscription.
     *
     * Handles the modern invoice shape (API 2025-03-31.basil and later) where
     * the client secret lives on `latest_invoice.confirmation_secret`, and the
     * legacy shape where it was `latest_invoice.payment_intent.client_secret`.
     * Requires `latest_invoice.confirmation_secret` to be expanded on the
     * subscription request.
     *
     * @param object $sub Subscription object.
     * @return string|null
     */
    public static function get_first_invoice_client_secret( $sub ) {
        if ( empty( $sub ) || empty( $sub->latest_invoice ) ) {
            return null;
        }
        $invoice = $sub->latest_invoice;
        // Modern (basil+): confirmation_secret holds the PaymentIntent client secret.
        if ( ! empty( $invoice->confirmation_secret ) && ! empty( $invoice->confirmation_secret->client_secret ) ) {
            return $invoice->confirmation_secret->client_secret;
        }
        // Legacy (pre-basil): payment_intent object on the invoice.
        if ( ! empty( $invoice->payment_intent ) && is_object( $invoice->payment_intent ) && ! empty( $invoice->payment_intent->client_secret ) ) {
            return $invoice->payment_intent->client_secret;
        }
        return null;
    }

    /**
     * Confirm a payment intent
     *
     * @param string $intent_id Payment intent ID
     * @param array  $args Additional arguments
     * @return \Stripe\PaymentIntent|WP_Error
     */
    public static function confirm_payment_intent( $intent_id, $args = array() ) {
        $client = self::get_client();
        
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }

        try {
            RPSFW_Gateway_Stripe::log( 'Confirming payment intent: ' . $intent_id );
            $intent = $client->paymentIntents->confirm( $intent_id, $args );
            RPSFW_Gateway_Stripe::log( 'Payment intent confirmed: ' . $intent->status );
            return $intent;
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Payment intent confirmation failed: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }

    /**
     * Capture a payment intent
     *
     * @param string $intent_id Payment intent ID
     * @param float  $amount Amount to capture (null for full amount)
     * @return \Stripe\PaymentIntent|WP_Error
     */
    public static function capture_payment_intent( $intent_id, $amount = null ) {
        $client = self::get_client();
        
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }

        $params = array();
        
        if ( ! is_null( $amount ) ) {
            $params['amount_to_capture'] = $amount;
        }

        try {
            RPSFW_Gateway_Stripe::log( 'Capturing payment intent: ' . $intent_id );
            $intent = $client->paymentIntents->capture( $intent_id, $params );
            RPSFW_Gateway_Stripe::log( 'Payment intent captured: ' . $intent->status );
            return $intent;
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Payment intent capture failed: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }

    /**
     * Create a refund
     *
     * @param string $charge_id Charge ID or Payment Intent ID
     * @param float  $amount Amount to refund (null for full refund)
     * @param string $reason Refund reason
     * @return \Stripe\Refund|WP_Error
     */
    public static function create_refund( $charge_id, $amount = null, $reason = '' ) {
        $client = self::get_client();
        
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }

        $params = array();

        // Determine if this is a charge ID or payment intent ID
        if ( strpos( $charge_id, 'pi_' ) === 0 ) {
            $params['payment_intent'] = $charge_id;
        } else {
            $params['charge'] = $charge_id;
        }

        if ( ! is_null( $amount ) ) {
            $params['amount'] = $amount;
        }

        if ( ! empty( $reason ) ) {
            $params['reason'] = 'requested_by_customer';
            $params['metadata'] = array(
                'reason' => $reason,
            );
        }

        try {
            RPSFW_Gateway_Stripe::log( 'Creating refund: ' . wp_json_encode( $params ) );
            $refund = $client->refunds->create( $params );
            RPSFW_Gateway_Stripe::log( 'Refund created: ' . $refund->id );
            return $refund;
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Refund creation failed: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }

    /**
     * Whether a currency has no minor unit (no "cents").
     *
     * Stripe expects amounts in the smallest currency unit, which for these
     * currencies IS the whole unit - ¥100 is sent as 100, not 10000. Getting it
     * wrong is a 100x error in either direction, so the list lives in exactly
     * one place.
     *
     * @param string $currency Currency code.
     * @return bool
     */
    public static function is_zero_decimal_currency( $currency ) {
        $zero_decimal = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

        return in_array( strtoupper( (string) $currency ), $zero_decimal, true );
    }

    /**
     * Get Stripe amount (convert to cents/smallest unit)
     *
     * @param float  $amount Amount in store currency
     * @param string $currency Currency code
     * @return int
     */
    public static function get_stripe_amount( $amount, $currency ) {
        if ( self::is_zero_decimal_currency( $currency ) ) {
            return absint( $amount );
        }

        return absint( wc_format_decimal( ( (float) $amount * 100 ), wc_get_price_decimals() ) );
    }

    /**
     * Format Stripe amount to store amount
     *
     * @param int    $amount Amount in cents
     * @param string $currency Currency code
     * @return float
     */
    public static function format_stripe_amount( $amount, $currency ) {
        if ( self::is_zero_decimal_currency( $currency ) ) {
            return (float) $amount;
        }

        return (float) ( $amount / 100 );
    }

    // -----------------------------------------------------------------------
    //  Stripe Billing helpers (Customer + Price + Subscription).
    //
    //  These power the gateway-driven subscription flow: Stripe owns the
    //  schedule, Stripe runs Smart Retries / SCA recovery, and webhooks
    //  drive WooCommerce subscription state. Mirrors the PayPal Commerce
    //  catalog-product + plan + subscription model.
    // -----------------------------------------------------------------------

    /**
     * Plan / Price cache key. Same pattern as rpsfw_ppcp_plan_cache: hash a
     * signature (currency, amount, interval, trial, mode) → reuse the same
     * Stripe Product + Price for every subscription with that signature so
     * we don't create a new Price row per checkout.
     */
    const PRICE_CACHE_OPTION = 'rpsfw_stripe_price_cache';

    /**
     * Get the existing Stripe Customer id for a WP user, or create one.
     *
     * Per-mode (live/test) since customer ids are scoped to the mode the
     * Connect account is using.
     *
     * @param int        $user_id       WP user id (0 for guests).
     * @param array|null $billing       Optional billing details (email, name).
     * @param string     $existing_id   Optional pre-known customer id (e.g. from order meta).
     * @return string|WP_Error Customer id or error.
     */
    /**
     * Whether a Stripe customer id still resolves in the connected account and
     * current mode. Returns false for a missing (404 / resource_missing) or a
     * deleted customer, so callers can create a fresh one instead of failing
     * with "No such customer".
     *
     * @param \Stripe\StripeClient $client      Stripe client.
     * @param string               $customer_id Customer id to check.
     * @return bool
     */
    private static function stripe_customer_exists( $client, $customer_id ) {
        if ( empty( $customer_id ) || ! $client ) {
            return false;
        }
        try {
            $customer = $client->customers->retrieve( $customer_id, array() );
            // A deleted customer comes back with deleted=true rather than 404.
            if ( isset( $customer->deleted ) && $customer->deleted ) {
                return false;
            }
            return true;
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            // resource_missing (no such customer), wrong-mode key, etc.
            return false;
        }
    }

    /**
     * Whether a Stripe price id still resolves and is usable for new
     * subscriptions in the connected account/mode. Returns false for a missing
     * (404), archived (inactive), or wrong-account price so callers can create
     * a fresh one instead of failing with "No such price".
     *
     * @param \Stripe\StripeClient $client   Stripe client.
     * @param string               $price_id Price id to check.
     * @return bool
     */
    private static function stripe_price_exists( $client, $price_id ) {
        if ( empty( $price_id ) || ! $client ) {
            return false;
        }
        try {
            $price = $client->prices->retrieve( $price_id, array() );
            // An archived price can't be attached to a new subscription.
            if ( isset( $price->active ) && false === $price->active ) {
                return false;
            }
            return true;
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            return false;
        }
    }

    /**
     * @param int        $user_id      WP user id (0 for guests).
     * @param array|null $billing      Billing data.
     * @param string     $existing_id  Pre-known Stripe customer id, if any.
     * @param bool       $sync_details Patch name/email/phone (not just
     *                                 shipping) onto a reused customer. Only
     *                                 pass true from a checkout FINALIZE path
     *                                 (once payment is confirmed and billing
     *                                 data is final) — this is called on every
     *                                 checkout-field change during the early
     *                                 "create a draft subscription for the
     *                                 Payment Element" phase, and repeating a
     *                                 full customer update on each of those
     *                                 would mean several extra Stripe API
     *                                 calls per checkout session for no
     *                                 benefit, since the details are about to
     *                                 be finalized anyway.
     */
    public static function get_or_create_customer( $user_id, $billing = null, $existing_id = '', $sync_details = false ) {
        $client = self::get_client();
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }

        $options  = get_option( 'woocommerce_rpsfw_stripe_settings', array() );
        $testmode = isset( $options['testmode'] ) && 'yes' === $options['testmode'];
        $meta_key = $testmode ? '_rpsfw_stripe_customer_id_test' : '_rpsfw_stripe_customer_id_live';

        // 1) Pre-known id (e.g. on a renewal order or from the subscription).
        //    Verify it still resolves — a customer created under a different
        //    Stripe account, the other mode (test/live), or since-deleted will
        //    otherwise fail later with "No such customer". If it's gone, fall
        //    through and create a fresh one.
        if ( $existing_id ) {
            if ( self::stripe_customer_exists( $client, $existing_id ) ) {
                if ( $sync_details ) {
                    self::maybe_update_customer_details( $client, $existing_id, $billing );
                } else {
                    self::maybe_update_customer_shipping( $client, $existing_id, $billing );
                }
                return $existing_id;
            }
            RPSFW_Gateway_Stripe::log( 'Stripe customer ' . $existing_id . ' no longer exists; creating a new one.', 'warning' );
        }

        // 2) Stored on the WP user.
        if ( $user_id ) {
            $stored = get_user_meta( $user_id, $meta_key, true );
            if ( $stored ) {
                if ( self::stripe_customer_exists( $client, $stored ) ) {
                    if ( $sync_details ) {
                        self::maybe_update_customer_details( $client, $stored, $billing );
                    } else {
                        self::maybe_update_customer_shipping( $client, $stored, $billing );
                    }
                    return $stored;
                }
                // Stale id — clear it so we stop hitting "No such customer".
                delete_user_meta( $user_id, $meta_key );
                RPSFW_Gateway_Stripe::log( 'Stored Stripe customer ' . $stored . ' no longer exists; cleared and creating a new one.', 'warning' );
            }
        }

        // 3) Create.
        $args = array(
            'metadata' => array_merge(
                array(
                    'wp_user_id' => (string) $user_id,
                    'site_url'   => get_site_url(),
                ),
                self::get_relay_metadata()
            ),
        );
        if ( is_array( $billing ) ) {
            if ( ! empty( $billing['email'] ) ) {
                $args['email'] = $billing['email'];
            }
            if ( ! empty( $billing['name'] ) ) {
                $args['name'] = $billing['name'];
            }
            if ( ! empty( $billing['phone'] ) ) {
                $args['phone'] = $billing['phone'];
            }
        }

        // Attach the shipping address so it is recorded on the Stripe customer
        // (and therefore the subscription) for fulfillment. Without it Stripe
        // has no address on file for recurring physical orders.
        $shipping_args = self::build_stripe_shipping( $billing );
        if ( $shipping_args ) {
            $args['shipping'] = $shipping_args;
        }

        try {
            $customer = $client->customers->create( $args );
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Stripe customer create failed: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }

        if ( $user_id ) {
            update_user_meta( $user_id, $meta_key, $customer->id );
        }

        return $customer->id;
    }

    /**
     * Build a Stripe customer `shipping` payload from a billing array that may
     * carry a nested `shipping` address. Returns null when there isn't a usable
     * address (Stripe requires both a name and address.line1).
     *
     * @param array|null $billing Billing data with optional 'shipping' subarray.
     * @return array|null
     */
    private static function build_stripe_shipping( $billing ) {
        if ( ! is_array( $billing ) || empty( $billing['shipping'] ) || ! is_array( $billing['shipping'] ) ) {
            return null;
        }
        $s = $billing['shipping'];
        if ( empty( $s['address_1'] ) || empty( $s['country'] ) ) {
            return null;
        }
        $name = ! empty( $s['name'] ) ? $s['name'] : ( ! empty( $billing['name'] ) ? $billing['name'] : '' );
        if ( '' === trim( (string) $name ) ) {
            return null; // Stripe requires a name on the shipping object.
        }
        return array(
            'name'    => $name,
            'address' => array(
                'line1'       => $s['address_1'],
                'line2'       => ! empty( $s['address_2'] ) ? $s['address_2'] : '',
                'city'        => ! empty( $s['city'] ) ? $s['city'] : '',
                'state'       => ! empty( $s['state'] ) ? $s['state'] : '',
                'postal_code' => ! empty( $s['postcode'] ) ? $s['postcode'] : '',
                'country'     => $s['country'],
            ),
        );
    }

    /**
     * Update an existing Stripe customer's shipping address when we have one.
     * Keeps the address current for reused customers (subscriptions reuse the
     * same customer across orders). Failures are logged, not fatal.
     *
     * Cheap version used on every reuse during the early "draft subscription
     * for the Payment Element" phase (called on each checkout-field change) —
     * see get_or_create_customer()'s $sync_details param for why name/email/
     * phone are NOT patched here.
     *
     * @param \Stripe\StripeClient $client      Stripe client.
     * @param string               $customer_id Stripe customer id.
     * @param array|null           $billing     Billing data with optional shipping.
     * @return void
     */
    private static function maybe_update_customer_shipping( $client, $customer_id, $billing ) {
        $shipping_args = self::build_stripe_shipping( $billing );
        if ( ! $shipping_args || ! $client ) {
            return;
        }
        try {
            $client->customers->update( $customer_id, array( 'shipping' => $shipping_args ) );
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Stripe customer shipping update failed: ' . $e->getMessage(), 'warning' );
        }
    }

    /**
     * Backfill name/email/phone/shipping onto an already-created Stripe
     * customer. Only call with $sync_details=true from get_or_create_customer()
     * — i.e. once, from a checkout FINALIZE path, when billing data is final.
     *
     * A subscription draft is created early (on checkout page load, before
     * the buyer has typed anything) to get a client_secret for the Payment
     * Element, so the Stripe customer it creates often starts out with a
     * blank name/email. Without this backfill the fields would stay blank
     * forever ("Unnamed customer" in the Stripe dashboard) even after
     * checkout completes with full billing details. Only non-empty fields
     * are sent, so a call with incomplete billing never blanks out values
     * already set.
     *
     * @param \Stripe\StripeClient $client      Stripe client.
     * @param string               $customer_id Stripe customer id.
     * @param array|null           $billing     Billing data (see get_or_create_customer()).
     */
    private static function maybe_update_customer_details( $client, $customer_id, $billing ) {
        if ( ! $client ) {
            return;
        }

        $args = array();
        if ( is_array( $billing ) ) {
            if ( ! empty( $billing['name'] ) ) {
                $args['name'] = $billing['name'];
            }
            if ( ! empty( $billing['email'] ) ) {
                $args['email'] = $billing['email'];
            }
            if ( ! empty( $billing['phone'] ) ) {
                $args['phone'] = $billing['phone'];
            }
        }

        $shipping_args = self::build_stripe_shipping( $billing );
        if ( $shipping_args ) {
            $args['shipping'] = $shipping_args;
        }

        if ( ! $args ) {
            return;
        }

        try {
            $client->customers->update( $customer_id, $args );
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Stripe customer details update failed: ' . $e->getMessage(), 'warning' );
        }
    }

    /**
     * Find or create a reusable Stripe Price for a recurring schedule.
     *
     * Cache key is a hash of the subscription's pricing parameters so the
     * same Price row is reused across customers. New parameters (different
     * amount, currency, interval, trial) get a new Price.
     *
     * @param array $args {
     *     Required keys:
     *     @type float  $amount         Recurring amount in store currency.
     *     @type string $currency       3-letter currency code.
     *     @type string $interval       'day'|'week'|'month'|'year'.
     *     @type int    $interval_count Multiplier (e.g. 1 month, 3 months).
     *     @type string $product_name   Display name for the auto-created Stripe Product.
     * }
     * @return string|WP_Error Stripe Price id (price_...).
     */
    public static function find_or_create_price( $args ) {
        $client = self::get_client();
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }

        $options  = get_option( 'woocommerce_rpsfw_stripe_settings', array() );
        $testmode = isset( $options['testmode'] ) && 'yes' === $options['testmode'];
        $env      = $testmode ? 'test' : 'live';

        $amount         = isset( $args['amount'] ) ? (float) $args['amount'] : 0;
        $currency       = isset( $args['currency'] ) ? strtolower( $args['currency'] ) : strtolower( get_woocommerce_currency() );
        $interval       = isset( $args['interval'] ) ? strtolower( $args['interval'] ) : 'month';
        $interval_count = isset( $args['interval_count'] ) ? max( 1, (int) $args['interval_count'] ) : 1;
        $product_name   = isset( $args['product_name'] ) ? wp_strip_all_tags( $args['product_name'] ) : sprintf( '%s subscription', get_bloginfo( 'name' ) );

        $unit_amount = self::get_stripe_amount( $amount, $currency );

        $cache_key = md5( implode( '|', array(
            $env, $currency, $unit_amount, $interval, $interval_count,
        ) ) );

        $cache = get_option( self::PRICE_CACHE_OPTION, array() );
        if ( ! empty( $cache[ $cache_key ]['price_id'] ) ) {
            $cached_price_id = $cache[ $cache_key ]['price_id'];
            // Verify the cached price still resolves. A price created under a
            // different Stripe account (or since archived/deleted) would fail
            // later with "No such price" when creating the subscription. If it's
            // gone, drop ONLY this stale entry (never the whole cache) and
            // recreate it below.
            if ( self::stripe_price_exists( $client, $cached_price_id ) ) {
                return $cached_price_id;
            }
            RPSFW_Gateway_Stripe::log( 'Cached Stripe price ' . $cached_price_id . ' no longer usable; recreating.', 'warning' );
            unset( $cache[ $cache_key ] );
        }

        // Create a Stripe Product and Price. We auto-create the Product
        // because Stripe requires one but the merchant doesn't manage them
        // by hand for our auto-generated subscriptions.
        try {
            $product = $client->products->create( array(
                'name'     => $product_name,
                'metadata' => array( 'site_url' => get_site_url() ),
            ) );
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Stripe product create failed: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }

        try {
            $price = $client->prices->create( array(
                'product'     => $product->id,
                'currency'    => $currency,
                'unit_amount' => $unit_amount,
                'recurring'   => array(
                    'interval'       => $interval,
                    'interval_count' => $interval_count,
                ),
            ) );
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Stripe price create failed: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }

        $cache[ $cache_key ] = array(
            'price_id'   => $price->id,
            'product_id' => $product->id,
            'env'        => $env,
        );
        update_option( self::PRICE_CACHE_OPTION, $cache, false );

        return $price->id;
    }

    /**
     * Create a Stripe Subscription with a deferred PaymentIntent for the
     * first invoice. Returns the latest_invoice.payment_intent so the
     * customer can confirm the first charge with the Payment Element.
     *
     * Sign-up fees are passed via add_invoice_items so they are added to
     * the first invoice only. Trials use trial_period_days.
     *
     * @param array $args {
     *     @type string $customer                 Stripe customer id (cus_).
     *     @type string $price_id                 Stripe price id (price_).
     *     @type int    $trial_days               Optional. Number of trial days.
     *     @type float  $signup_fee               Optional. Sign-up fee in store currency.
     *     @type string $signup_currency          Currency for sign-up fee.
     *     @type string $signup_label             Description for the sign-up line item.
     *     @type float  $non_subscription_amount  Optional. One-time amount for non-subscription
     *                                            products in a mixed cart. Added to first invoice only.
     *     @type string $non_subscription_label   Description for the one-time product line item.
     *     @type float  $initial_discount         Optional. One-time discount in store
     *                                            currency applied to the FIRST invoice
     *                                            only (used to reconcile WooCommerce
     *                                            initial-cart / sign-up-fee coupons
     *                                            that should not recur).
     *     @type array  $metadata                 Optional metadata.
     *     @type int    $quantity                 Optional. Defaults to 1.
     * }
     * @return \Stripe\Subscription|WP_Error
     */
    public static function create_subscription( $args ) {
        $client = self::get_client();
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }

        if ( empty( $args['customer'] ) ) {
            return new WP_Error( 'stripe_error', __( 'Customer is required.', 'restore-paypal-standard-for-woocommerce' ) );
        }

        // Items: accept either a pre-built `items` array (multi-item / mixed
        // interval subscriptions) or a single `price_id` (back-compat).
        if ( ! empty( $args['items'] ) && is_array( $args['items'] ) ) {
            $items = $args['items'];
        } elseif ( ! empty( $args['price_id'] ) ) {
            $items = array(
                array(
                    'price'    => $args['price_id'],
                    'quantity' => isset( $args['quantity'] ) ? max( 1, (int) $args['quantity'] ) : 1,
                ),
            );
        } else {
            return new WP_Error( 'stripe_error', __( 'Customer and price are required.', 'restore-paypal-standard-for-woocommerce' ) );
        }

        $params = array(
            'customer' => $args['customer'],
            'items'    => $items,
            // default_incomplete (default): the first invoice's PaymentIntent
            // is confirmed on-session by the Payment Element. For independent
            // subscriptions created off-session against an already-authenticated
            // card, the caller passes 'error_if_incomplete' so the first invoice
            // is charged immediately and the call fails loudly if it can't be.
            'payment_behavior'        => ! empty( $args['payment_behavior'] ) ? $args['payment_behavior'] : 'default_incomplete',
            'payment_settings'        => array(
                'save_default_payment_method' => 'on_subscription',
                'payment_method_types'        => self::subscription_payment_method_types(),
            ),
            'expand'                  => array( 'latest_invoice.confirmation_secret', 'pending_setup_intent' ),
        );

        // Pre-authenticated payment method (independent off-session flow): the
        // card was authenticated once via a SetupIntent, then each subscription
        // is created with that payment method so Stripe charges the first
        // invoice immediately without another on-session confirmation.
        if ( ! empty( $args['default_payment_method'] ) ) {
            $params['default_payment_method'] = $args['default_payment_method'];
        }
        if ( ! empty( $args['off_session'] ) ) {
            $params['off_session'] = true;
        }

        // Flexible billing mode is required for mixed-interval subscriptions
        // (multiple items with different billing periods on one subscription).
        if ( ! empty( $args['billing_mode'] ) ) {
            $params['billing_mode'] = $args['billing_mode'];
        }

        if ( ! empty( $args['trial_days'] ) ) {
            $params['trial_period_days'] = (int) $args['trial_days'];
        }

        // First-invoice adjustments (sign-up fee, one-time products in a mixed
        // cart, and a reconciliation discount) are added as PENDING invoice
        // items on the customer rather than via the subscription's
        // add_invoice_items parameter. Stripe's add_invoice_items only accepts
        // `price`/`price_data` (not ad-hoc currency/unit_amount/description),
        // which is what caused "Received unknown parameters: description,
        // unit_amount, currency". Pending invoice items are automatically
        // pulled onto the subscription's first invoice.
        $pending_items     = array();
        $default_currency  = ! empty( $args['signup_currency'] ) ? strtolower( $args['signup_currency'] ) : strtolower( get_woocommerce_currency() );

        // Sign-up fee.
        if ( ! empty( $args['signup_fee'] ) && (float) $args['signup_fee'] > 0 ) {
            $pending_items[] = array(
                'amount'      => self::get_stripe_amount( (float) $args['signup_fee'], $default_currency ),
                'currency'    => $default_currency,
                'description' => ! empty( $args['signup_label'] ) ? wp_strip_all_tags( $args['signup_label'] ) : __( 'Sign-up fee', 'restore-paypal-standard-for-woocommerce' ),
            );
        }

        // Non-subscription product amount (mixed cart: one-time items alongside
        // a subscription). Charged once on the first invoice only.
        if ( ! empty( $args['non_subscription_amount'] ) && (float) $args['non_subscription_amount'] > 0 ) {
            $pending_items[] = array(
                'amount'      => self::get_stripe_amount( (float) $args['non_subscription_amount'], $default_currency ),
                'currency'    => $default_currency,
                'description' => ! empty( $args['non_subscription_label'] ) ? wp_strip_all_tags( $args['non_subscription_label'] ) : __( 'One-time product(s)', 'restore-paypal-standard-for-woocommerce' ),
            );
        }

        // One-time discount on the FIRST invoice only, modelled as a negative
        // invoice item. Reconciles the Stripe first charge to WooCommerce's
        // discounted "due today" total for initial-cart and sign-up-fee coupons
        // that must not affect renewals. Stripe allows negative invoice items
        // as long as the invoice total stays >= 0 (the caller clamps this).
        if ( ! empty( $args['initial_discount'] ) && (float) $args['initial_discount'] > 0 ) {
            $pending_items[] = array(
                'amount'      => -1 * self::get_stripe_amount( (float) $args['initial_discount'], $default_currency ),
                'currency'    => $default_currency,
                'description' => __( 'Discount', 'restore-paypal-standard-for-woocommerce' ),
            );
        }

        if ( ! empty( $pending_items ) ) {
            // Remove any leftover pending items WE created on a previous attempt
            // for this customer (e.g. the front end re-requested after a coupon
            // change) so the amounts don't stack onto the first invoice. Only
            // our own items are removed — identified by metadata.
            try {
                $existing = $client->invoiceItems->all( array(
                    'customer' => $args['customer'],
                    'pending'  => true,
                    'limit'    => 100,
                ) );
                foreach ( $existing->data as $existing_item ) {
                    if ( isset( $existing_item->metadata['rpsfw_sub_adjustment'] ) ) {
                        $client->invoiceItems->delete( $existing_item->id );
                    }
                }
            } catch ( \Stripe\Exception\ApiErrorException $e ) {
                RPSFW_Gateway_Stripe::log( 'Could not clean up pending invoice items: ' . $e->getMessage(), 'warning' );
            }

            foreach ( $pending_items as $pending_item ) {
                try {
                    $client->invoiceItems->create( array(
                        'customer'    => $args['customer'],
                        'amount'      => $pending_item['amount'],
                        'currency'    => $pending_item['currency'],
                        'description' => $pending_item['description'],
                        'metadata'    => array( 'rpsfw_sub_adjustment' => '1' ),
                    ) );
                } catch ( \Stripe\Exception\ApiErrorException $e ) {
                    RPSFW_Gateway_Stripe::log( 'Failed to create pending invoice item: ' . $e->getMessage(), 'error' );
                    return new WP_Error( 'stripe_error', $e->getMessage() );
                }
            }
        }

        // Always stamp relay-routing metadata so the webhook forwarder
        // can resolve the merchant from any subscription event without a
        // Stripe API call. Merge with any caller-supplied metadata.
        $caller_meta = ( ! empty( $args['metadata'] ) && is_array( $args['metadata'] ) ) ? $args['metadata'] : array();
        $params['metadata'] = array_merge( $caller_meta, self::get_relay_metadata() );

        try {
            RPSFW_Gateway_Stripe::log( 'Creating Stripe subscription: ' . wp_json_encode( $params ) );
            $sub = $client->subscriptions->create( $params );
            RPSFW_Gateway_Stripe::log( 'Stripe subscription created: ' . $sub->id );
            return $sub;
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Stripe subscription create failed: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }

    /**
     * Resolve the PaymentIntent id and Charge id that paid a given invoice.
     *
     * Handles both the legacy top-level `payment_intent`/`charge` fields and
     * the basil (2025-03-31+) shapes where the link moved to the invoice's
     * `payments` collection / `confirmation_secret`. Returns empty strings for
     * anything that can't be resolved.
     *
     * @param string $invoice_id Stripe invoice id (in_...).
     * @return array{payment_intent:string,charge:string}
     */
    public static function get_invoice_payment_refs( $invoice_id ) {
        $refs   = array( 'payment_intent' => '', 'charge' => '' );
        $client = self::get_client();
        if ( ! $client || empty( $invoice_id ) ) {
            return $refs;
        }

        // Retrieve with the basil `payments` collection expanded (this is where
        // the PaymentIntent/charge link lives in current API versions). Fall
        // back to a plain retrieve if the expand is rejected by an older
        // version.
        $invoice = null;
        try {
            $invoice = $client->invoices->retrieve( $invoice_id, array( 'expand' => array( 'payments' ) ) );
        } catch ( \Exception $e ) {
            try {
                $invoice = $client->invoices->retrieve( $invoice_id, array() );
            } catch ( \Exception $e2 ) {
                RPSFW_Gateway_Stripe::log( 'get_invoice_payment_refs: retrieve failed for ' . $invoice_id . ': ' . $e2->getMessage(), 'warning' );
                return $refs;
            }
        }

        // Legacy (pre-basil) top-level fields.
        if ( ! empty( $invoice->payment_intent ) ) {
            $refs['payment_intent'] = is_object( $invoice->payment_intent ) ? $invoice->payment_intent->id : (string) $invoice->payment_intent;
        }
        if ( ! empty( $invoice->charge ) ) {
            $refs['charge'] = is_object( $invoice->charge ) ? $invoice->charge->id : (string) $invoice->charge;
        }

        // Basil: the payments collection links each InvoicePayment to its
        // PaymentIntent (and/or charge). Scan all entries for the first usable
        // reference.
        if ( ( '' === $refs['payment_intent'] || '' === $refs['charge'] )
            && ! empty( $invoice->payments ) && ! empty( $invoice->payments->data ) ) {
            foreach ( $invoice->payments->data as $inv_payment ) {
                $payment = isset( $inv_payment->payment ) ? $inv_payment->payment : null;
                if ( ! $payment ) {
                    continue;
                }
                if ( '' === $refs['payment_intent'] && ! empty( $payment->payment_intent ) ) {
                    $refs['payment_intent'] = is_object( $payment->payment_intent ) ? $payment->payment_intent->id : (string) $payment->payment_intent;
                }
                if ( '' === $refs['charge'] && ! empty( $payment->charge ) ) {
                    $refs['charge'] = is_object( $payment->charge ) ? $payment->charge->id : (string) $payment->charge;
                }
                if ( '' !== $refs['payment_intent'] && '' !== $refs['charge'] ) {
                    break;
                }
            }
        }

        // Basil: derive the PaymentIntent from confirmation_secret's client
        // secret (format: pi_XXX_secret_YYY) as a last resort.
        if ( '' === $refs['payment_intent'] && ! empty( $invoice->confirmation_secret->client_secret ) ) {
            $cs = (string) $invoice->confirmation_secret->client_secret;
            if ( 0 === strpos( $cs, 'pi_' ) && false !== strpos( $cs, '_secret_' ) ) {
                $refs['payment_intent'] = substr( $cs, 0, strpos( $cs, '_secret_' ) );
            }
        }

        // Fill in whichever id is still missing from the PaymentIntent.
        if ( '' === $refs['charge'] && '' !== $refs['payment_intent'] ) {
            try {
                $pi = $client->paymentIntents->retrieve( $refs['payment_intent'], array() );
                if ( ! empty( $pi->latest_charge ) ) {
                    $refs['charge'] = is_object( $pi->latest_charge ) ? $pi->latest_charge->id : (string) $pi->latest_charge;
                }
            } catch ( \Exception $e ) {
                // ignore
            }
        }

        RPSFW_Gateway_Stripe::log( sprintf(
            'get_invoice_payment_refs(%s): payment_intent=%s, charge=%s',
            $invoice_id,
            '' !== $refs['payment_intent'] ? $refs['payment_intent'] : '(none)',
            '' !== $refs['charge'] ? $refs['charge'] : '(none)'
        ) );

        return $refs;
    }

    /**
     * Stamp the WooCommerce order id (and key) into a PaymentIntent's metadata
     * so refund/dispute webhooks can resolve the order directly, without
     * relying on the charge->invoice->subscription chain (which the basil API
     * no longer exposes on the charge). Best-effort; failures are logged only.
     *
     * @param string   $payment_intent_id PaymentIntent id (pi_...).
     * @param WC_Order  $order            Order to reference.
     * @return void
     */
    public static function stamp_order_on_payment_intent( $payment_intent_id, $order ) {
        $client = self::get_client();
        if ( ! $client || empty( $payment_intent_id ) || ! $order ) {
            return;
        }
        try {
            $client->paymentIntents->update( $payment_intent_id, array(
                'metadata' => array(
                    'rpsfw_order_id'  => (string) $order->get_id(),
                    'rpsfw_order_key' => (string) $order->get_order_key(),
                ),
            ) );
        } catch ( \Exception $e ) {
            RPSFW_Gateway_Stripe::log( 'Could not stamp order id on PaymentIntent ' . $payment_intent_id . ': ' . $e->getMessage(), 'warning' );
        }
    }

    /**
     * Retrieve a Stripe subscription.
     *
     * @param string $subscription_id Subscription id (sub_).
     * @param array  $params          Optional retrieve params (e.g. expand).
     * @return \Stripe\Subscription|WP_Error
     */
    public static function retrieve_subscription( $subscription_id, $params = array() ) {
        $client = self::get_client();
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }

        try {
            return $client->subscriptions->retrieve( $subscription_id, $params );
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Stripe subscription retrieve failed: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }

    /**
     * Update a Stripe subscription (e.g. pause, change default payment method).
     *
     * @param string $subscription_id Subscription id.
     * @param array  $params          Update params.
     * @return \Stripe\Subscription|WP_Error
     */
    public static function update_subscription( $subscription_id, $params = array() ) {
        $client = self::get_client();
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }

        try {
            return $client->subscriptions->update( $subscription_id, $params );
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Stripe subscription update failed: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }

    /**
     * Cancel a Stripe subscription immediately.
     *
     * @param string $subscription_id Subscription id.
     * @return \Stripe\Subscription|WP_Error
     */
    public static function cancel_subscription( $subscription_id ) {
        $client = self::get_client();
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }

        try {
            return $client->subscriptions->cancel( $subscription_id, array() );
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Stripe subscription cancel failed: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }

    /**
     * Create a Subscription Schedule from an existing subscription. This wraps
     * the live subscription so we can automate future price changes (used to
     * step a limited-duration recurring discount back up to full price after N
     * billing cycles — entirely Stripe-managed, no webhooks).
     *
     * @param string $subscription_id Subscription id.
     * @return \Stripe\SubscriptionSchedule|WP_Error
     */
    public static function create_schedule_from_subscription( $subscription_id ) {
        $client = self::get_client();
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }
        try {
            return $client->subscriptionSchedules->create( array(
                'from_subscription' => $subscription_id,
            ) );
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Stripe create_schedule_from_subscription failed: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }

    /**
     * Update a Subscription Schedule's phases.
     *
     * @param string $schedule_id  Schedule id.
     * @param array  $phases       Phases array (see Stripe API).
     * @param string $end_behavior 'release' | 'cancel' | 'none'.
     * @return \Stripe\SubscriptionSchedule|WP_Error
     */
    public static function update_subscription_schedule( $schedule_id, $phases, $end_behavior = 'release' ) {
        $client = self::get_client();
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }
        try {
            return $client->subscriptionSchedules->update( $schedule_id, array(
                'end_behavior' => $end_behavior,
                'phases'       => $phases,
            ) );
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Stripe update_subscription_schedule failed: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }

    /**
     * Set a subscription schedule's default payment method.
     *
     * A schedule created from a subscription copies the subscription's
     * default_payment_method into its default_settings, and Stripe re-applies
     * default_settings to the subscription at each phase transition. So when
     * the customer changes their card, the schedule's default_settings must
     * also be updated or the next phase boundary would revert to the old card.
     *
     * @param string $schedule_id       Subscription schedule id (sub_sched_...).
     * @param string $payment_method_id PaymentMethod id (pm_...).
     * @return \Stripe\SubscriptionSchedule|WP_Error
     */
    public static function update_schedule_default_payment_method( $schedule_id, $payment_method_id ) {
        $client = self::get_client();
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }
        try {
            return $client->subscriptionSchedules->update( $schedule_id, array(
                'default_settings' => array(
                    'default_payment_method' => $payment_method_id,
                ),
            ) );
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            // A released/completed schedule can't be updated — that's fine, the
            // subscription-level default_payment_method then applies.
            RPSFW_Gateway_Stripe::log( 'Stripe update_schedule_default_payment_method failed (likely released schedule): ' . $e->getMessage(), 'warning' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }

    /**
     * Cancel a Subscription Schedule and its associated subscription
     * immediately. Required to cancel a subscription that a schedule manages —
     * canceling the subscription directly errors in that case.
     *
     * @param string $schedule_id Schedule id.
     * @return \Stripe\SubscriptionSchedule|WP_Error
     */
    public static function cancel_schedule( $schedule_id ) {
        $client = self::get_client();
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }
        try {
            return $client->subscriptionSchedules->cancel( $schedule_id );
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Stripe cancel_schedule failed: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }

    /**
     * Remove a single item from a subscription (used to cancel one schedule of
     * a mixed-interval subscription while leaving the others active). No
     * proration credit is issued.
     *
     * @param string $item_id Subscription item id (si_).
     * @return \Stripe\SubscriptionItem|WP_Error
     */
    public static function remove_subscription_item( $item_id ) {
        $client = self::get_client();
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }
        try {
            return $client->subscriptionItems->delete( $item_id, array( 'proration_behavior' => 'none' ) );
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Stripe remove_subscription_item failed: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }

    /**
     * Add an item to an existing subscription (used to resume a single
     * schedule of a mixed-interval subscription that was paused by removing its
     * item). No proration is applied.
     *
     * @param string $subscription_id Subscription id.
     * @param string $price_id        Price id to add.
     * @param int    $quantity        Quantity (default 1).
     * @return \Stripe\SubscriptionItem|WP_Error
     */
    public static function add_subscription_item( $subscription_id, $price_id, $quantity = 1 ) {
        $client = self::get_client();
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }
        try {
            return $client->subscriptionItems->create( array(
                'subscription'       => $subscription_id,
                'price'              => $price_id,
                'quantity'           => max( 1, (int) $quantity ),
                'proration_behavior' => 'none',
            ) );
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Stripe add_subscription_item failed: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }

    /**
     * Pause a Stripe subscription's billing cycle.
     *
     * @param string $subscription_id Subscription id.
     * @return \Stripe\Subscription|WP_Error
     */
    public static function pause_subscription( $subscription_id ) {
        return self::update_subscription( $subscription_id, array(
            'pause_collection' => array( 'behavior' => 'mark_uncollectible' ),
        ) );
    }

    /**
     * Resume a paused Stripe subscription.
     *
     * @param string $subscription_id Subscription id.
     * @return \Stripe\Subscription|WP_Error
     */
    public static function resume_subscription( $subscription_id ) {
        return self::update_subscription( $subscription_id, array(
            'pause_collection' => '',
        ) );
    }

    /**
     * Resume a subscription whose STATUS is `paused` (Stripe's newer
     * full-pause state, e.g. paused from the Stripe dashboard). This state
     * cannot be cleared via pause_collection — it requires the dedicated
     * resume endpoint. Billing restarts immediately (new cycle anchor).
     *
     * @param string $subscription_id Subscription id.
     * @return \Stripe\Subscription|WP_Error
     */
    public static function resume_paused_subscription( $subscription_id ) {
        $client = self::get_client();
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }

        try {
            return $client->subscriptions->resume(
                $subscription_id,
                array(
                    'billing_cycle_anchor' => 'now',
                )
            );
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Stripe subscription resume (paused) failed: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }

    /**
     * Create a SetupIntent for the change-payment-method flow.
     *
     * NOTE the card-only default below. An intent's payment_method_types is
     * exactly what the Payment Element offers, so any caller collecting a method
     * for a SUBSCRIPTION must pass subscription_payment_method_types() instead —
     * otherwise Link is hidden for that flow while working everywhere else. The
     * subscription checkout callers do this; only change-payment-method takes
     * the default.
     *
     * @param string $customer_id Stripe customer id.
     * @param array  $args        Optional extra params.
     * @return \Stripe\SetupIntent|WP_Error
     */
    public static function create_setup_intent( $customer_id, $args = array() ) {
        $client = self::get_client();
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }

        $params = wp_parse_args( $args, array(
            'customer'             => $customer_id,
            'usage'                => 'off_session',
            'payment_method_types' => array( 'card' ),
        ) );

        try {
            return $client->setupIntents->create( $params );
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Stripe setup intent create failed: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }

    /**
     * Register (and enable) a payment method domain on the connected account.
     *
     * Stripe requires the checkout domain to be registered before wallet
     * payment methods — Link, and later Apple Pay / Google Pay — will render in
     * the Payment Element. Doing this automatically at Stripe Connect time means
     * merchants never have to register the domain by hand in their Dashboard.
     *
     * Because API calls run with the connected account's own access token
     * (see get_secret_key), this registers the domain directly on the merchant
     * account. Idempotent: if the domain already exists we re-enable it rather
     * than erroring. Non-fatal — a failure only means Link/wallets won't show
     * until the merchant registers manually.
     *
     * @param string $domain Optional domain (host) to register. Defaults to the
     *                        current site's host.
     * @return \Stripe\PaymentMethodDomain|WP_Error
     */
    public static function register_payment_method_domain( $domain = '' ) {
        $client = self::get_client();
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }

        if ( empty( $domain ) ) {
            $host   = wp_parse_url( home_url(), PHP_URL_HOST );
            $domain = $host ? $host : '';
        }
        if ( empty( $domain ) ) {
            RPSFW_Gateway_Stripe::log( 'register_payment_method_domain: no domain could be derived from home_url().', 'warning' );
            return new WP_Error( 'stripe_error', __( 'No domain available to register.', 'restore-paypal-standard-for-woocommerce' ) );
        }

        RPSFW_Gateway_Stripe::log( 'register_payment_method_domain: starting for domain "' . $domain . '".' );

        // Reuse an existing registration if present (avoids a duplicate error);
        // ensure it's enabled.
        try {
            $existing = $client->paymentMethodDomains->all( array(
                'domain_name' => $domain,
                'limit'       => 1,
            ) );
            if ( ! empty( $existing->data ) ) {
                $dom = $existing->data[0];
                if ( empty( $dom->enabled ) ) {
                    $dom = $client->paymentMethodDomains->update( $dom->id, array( 'enabled' => true ) );
                    RPSFW_Gateway_Stripe::log( 'register_payment_method_domain: re-enabled existing domain ' . $domain . ' (' . $dom->id . ').' );
                }
                // Re-validate so Stripe (re)activates any wallet that now meets
                // requirements. This is the step that flips Apple Pay / Google
                // Pay to "active" automatically — the merchant never has to
                // touch the Stripe Dashboard.
                $dom = self::validate_payment_method_domain( $client, $dom );
                self::log_payment_method_domain_status( $dom, $domain, 'existing' );
                return $dom;
            }
            RPSFW_Gateway_Stripe::log( 'register_payment_method_domain: no existing registration for ' . $domain . '; creating.' );
        } catch ( Exception $e ) {
            // Fall through to create; some accounts/keys may not support list.
            RPSFW_Gateway_Stripe::log( 'register_payment_method_domain: lookup failed (will attempt create): ' . $e->getMessage(), 'warning' );
        }

        try {
            $dom = $client->paymentMethodDomains->create( array(
                'domain_name' => $domain,
                'enabled'     => true,
            ) );
            // Validate immediately so the wallets activate without any manual
            // step (see note above).
            $dom = self::validate_payment_method_domain( $client, $dom );
            self::log_payment_method_domain_status( $dom, $domain, 'created' );
            return $dom;
        } catch ( Exception $e ) {
            RPSFW_Gateway_Stripe::log( 'register_payment_method_domain: registration FAILED for ' . $domain . ': ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }

    /**
     * Validate a payment method domain so Stripe (re)activates the wallets that
     * meet requirements. Returns the validated domain object, or the original
     * on failure (non-fatal).
     *
     * @param \Stripe\StripeClient        $client Stripe client.
     * @param \Stripe\PaymentMethodDomain $dom    Domain object.
     * @return \Stripe\PaymentMethodDomain
     */
    private static function validate_payment_method_domain( $client, $dom ) {
        if ( empty( $dom->id ) ) {
            return $dom;
        }
        try {
            return $client->paymentMethodDomains->validate( $dom->id );
        } catch ( Exception $e ) {
            RPSFW_Gateway_Stripe::log( 'register_payment_method_domain: validate() failed for ' . $dom->id . ': ' . $e->getMessage(), 'warning' );
            return $dom;
        }
    }

    /**
     * Log the per-wallet activation status of a payment method domain.
     *
     * @param \Stripe\PaymentMethodDomain $dom     Domain object.
     * @param string                      $domain  Domain host.
     * @param string                      $context 'existing' or 'created'.
     */
    private static function log_payment_method_domain_status( $dom, $domain, $context ) {
        RPSFW_Gateway_Stripe::log( 'register_payment_method_domain (' . $context . '): ' . $domain . ' (' . ( isset( $dom->id ) ? $dom->id : 'n/a' ) . ') enabled='
            . ( ! empty( $dom->enabled ) ? 'yes' : 'no' )
            . '; Apple Pay: ' . ( isset( $dom->apple_pay->status ) ? $dom->apple_pay->status : 'n/a' )
            . ', Google Pay: ' . ( isset( $dom->google_pay->status ) ? $dom->google_pay->status : 'n/a' )
            . ', Link: ' . ( isset( $dom->link->status ) ? $dom->link->status : 'n/a' ) );
    }

    /**
     * Create a webhook endpoint
     *
     * @param string $url Webhook URL
     * @return \Stripe\WebhookEndpoint|WP_Error
     */
    public static function create_webhook( $url ) {
        $client = self::get_client();
        
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }

        // Webhook events covering one-time payments, refunds, disputes,
        // reviews, and the full Stripe Billing subscription lifecycle. We
        // listen to invoice.* and customer.subscription.* so Stripe drives
        // renewals and dunning, and the plugin reacts via webhooks.
        $events = apply_filters( 'rpsfw_stripe_webhook_events', array(
            'charge.succeeded',
            'charge.failed',
            'charge.pending',
            'charge.refunded',
            'charge.dispute.created',
            'charge.dispute.closed',
            'payment_intent.succeeded',
            'payment_intent.payment_failed',
            'payment_intent.requires_action',
            'payment_intent.canceled',
            'review.opened',
            'review.closed',
            'invoice.payment_succeeded',
            'invoice.payment_failed',
            'invoice.payment_action_required',
            'invoice.upcoming',
            'invoice.finalized',
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
            'customer.subscription.paused',
            'customer.subscription.resumed',
            'customer.subscription.trial_will_end',
        ) );

        try {
            RPSFW_Gateway_Stripe::log( 'Creating webhook endpoint: ' . $url );
            $webhook = $client->webhookEndpoints->create( array(
                'url' => $url,
                'enabled_events' => $events,
                'api_version' => '2026-05-27.dahlia',
                /* translators: %s: site name. */
                'description' => sprintf( __( 'WooCommerce - %s', 'restore-paypal-standard-for-woocommerce' ), get_bloginfo( 'name' ) ),
            ) );
            RPSFW_Gateway_Stripe::log( 'Webhook created: ' . $webhook->id );
            return $webhook;
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Webhook creation failed: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }

    /**
     * Update a webhook endpoint
     *
     * @param string $webhook_id Webhook endpoint ID
     * @param array  $args Update arguments
     * @return \Stripe\WebhookEndpoint|WP_Error
     */
    public static function update_webhook( $webhook_id, $args = array() ) {
        $client = self::get_client();
        
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }

        try {
            RPSFW_Gateway_Stripe::log( 'Updating webhook: ' . $webhook_id );
            $webhook = $client->webhookEndpoints->update( $webhook_id, $args );
            RPSFW_Gateway_Stripe::log( 'Webhook updated: ' . $webhook->id );
            return $webhook;
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Webhook update failed: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }

    /**
     * Delete a webhook endpoint
     *
     * @param string $webhook_id Webhook endpoint ID
     * @return bool|WP_Error
     */
    public static function delete_webhook( $webhook_id ) {
        $client = self::get_client();
        
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }

        try {
            RPSFW_Gateway_Stripe::log( 'Deleting webhook: ' . $webhook_id );
            $client->webhookEndpoints->delete( $webhook_id );
            RPSFW_Gateway_Stripe::log( 'Webhook deleted: ' . $webhook_id );
            return true;
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Webhook deletion failed: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }

    /**
     * Retrieve a webhook endpoint
     *
     * @param string $webhook_id Webhook endpoint ID
     * @return \Stripe\WebhookEndpoint|WP_Error
     */
    public static function retrieve_webhook( $webhook_id ) {
        $client = self::get_client();
        
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }

        try {
            return $client->webhookEndpoints->retrieve( $webhook_id );
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Failed to retrieve webhook: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }

    /**
     * List all webhook endpoints
     *
     * @return array|WP_Error
     */
    public static function list_webhooks() {
        $client = self::get_client();
        
        if ( ! $client ) {
            return new WP_Error( 'stripe_error', __( 'Stripe is not properly configured', 'restore-paypal-standard-for-woocommerce' ) );
        }

        try {
            $webhooks = $client->webhookEndpoints->all( array( 'limit' => 100 ) );
            return $webhooks->data;
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            RPSFW_Gateway_Stripe::log( 'Failed to list webhooks: ' . $e->getMessage(), 'error' );
            return new WP_Error( 'stripe_error', $e->getMessage() );
        }
    }
}
