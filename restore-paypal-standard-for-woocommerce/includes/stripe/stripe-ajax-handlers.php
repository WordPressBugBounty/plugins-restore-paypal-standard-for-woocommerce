<?php
/**
 * Stripe AJAX Handlers
 *
 * Handles AJAX requests for Stripe webhook management and payment processing
 *
 * @package RestorePayPalStandard
 */

defined( 'ABSPATH' ) || exit;

/**
 * AJAX handler to create payment intent
 */
add_action( 'wp_ajax_rpsfw_stripe_create_payment_intent', 'rpsfw_stripe_ajax_create_payment_intent' );
add_action( 'wp_ajax_nopriv_rpsfw_stripe_create_payment_intent', 'rpsfw_stripe_ajax_create_payment_intent' );
function rpsfw_stripe_ajax_create_payment_intent() {
    check_ajax_referer( 'rpsfw-stripe-create-intent', 'nonce' );

    // Get the cart or order
    $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
    
    if ( $order_id ) {
        // Creating payment intent for existing order (order-pay page)
        $order = wc_get_order( $order_id );
        
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => __( 'Invalid order.', 'restore-paypal-standard-for-woocommerce' ) ) );
        }
        
        // Verify the current user can pay for this order
        if ( ! current_user_can( 'pay_for_order', $order_id ) && $order->get_customer_id() !== get_current_user_id() ) {
            wp_send_json_error( array( 'message' => __( 'You do not have permission to pay for this order.', 'restore-paypal-standard-for-woocommerce' ) ) );
        }
        
        $amount = $order->get_total();
        $currency = $order->get_currency();
        
        // Get coupon information from order
        $coupon_codes = $order->get_coupon_codes();
        $discount_total = $order->get_total_discount();
    } else {
        // Creating payment intent for cart (checkout page)
        if ( ! WC()->cart || WC()->cart->is_empty() ) {
            wp_send_json_error( array( 'message' => __( 'Cart is empty.', 'restore-paypal-standard-for-woocommerce' ) ) );
        }

        // Subscription carts use the dedicated rpsfw_stripe_create_subscription
        // endpoint instead, so the front end never reaches this for a
        // subscription. Refuse defensively in case JS misroutes.
        if ( class_exists( 'RPSFW_Stripe_Subscriptions' ) && RPSFW_Stripe_Subscriptions::cart_contains_subscription() ) {
            wp_send_json_error( array( 'message' => __( 'Subscription carts must use the subscription endpoint.', 'restore-paypal-standard-for-woocommerce' ) ) );
        }

        $amount = WC()->cart->total;
        $currency = get_woocommerce_currency();
        
        // Get coupon information from cart
        $coupon_codes = WC()->cart->get_applied_coupons();
        $discount_total = WC()->cart->get_discount_total();
    }
    
    // Validate amount is positive
    if ( $amount <= 0 ) {
        wp_send_json_error( array( 'message' => __( 'Invalid payment amount.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    // Build metadata: identity + consistent line-item detail (items with unit
    // price, count, shipping, tax, discount, coupons). Shared with the
    // subscription flow so both carry the same breakdown. Prefer the order
    // (order-pay page); otherwise read the cart.
    $metadata = array_merge(
        array(
            'order_id' => $order_id,
            'site_url' => get_site_url(),
        ),
        RPSFW_Stripe_API::build_line_item_metadata(
            WC()->cart,
            ( $order_id && $order ) ? $order : null
        )
    );

    if ( ! empty( $metadata['coupon_codes'] ) ) {
        RPSFW_Gateway_Stripe::log( 'Creating payment intent with coupons: ' . $metadata['coupon_codes'] );
    }

    // Create payment intent
    $intent = RPSFW_Stripe_API::create_payment_intent( $amount, $currency, array(
        'metadata' => $metadata,
    ) );

    if ( is_wp_error( $intent ) ) {
        wp_send_json_error( array( 'message' => $intent->get_error_message() ) );
    }

    wp_send_json_success( array(
        'client_secret' => $intent->client_secret,
        'payment_intent_id' => $intent->id,
    ) );
}

/**
 * AJAX handler to check connection status
 */
add_action( 'wp_ajax_rpsfw_stripe_check_connection_status', 'rpsfw_stripe_ajax_check_connection_status' );
function rpsfw_stripe_ajax_check_connection_status() {
    check_ajax_referer( 'rpsfw-stripe-connection', 'nonce' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    $status = rpsfw_stripe_connection_status();
    
    wp_send_json_success( array(
        'completed' => ! empty( $status ),
        'status' => $status,
    ) );
}

/**
 * AJAX handler: try to create a Stripe webhook on the connected
 * account using the merchant's OAuth access token. Same call our
 * competitor woo-stripe-payment makes. Stripe rejects this on some
 * Connect platforms; if so, the merchant can fall back to manual
 * paste of the signing secret.
 */
add_action( 'wp_ajax_rpsfw_stripe_create_webhook', 'rpsfw_stripe_ajax_create_webhook' );
function rpsfw_stripe_ajax_create_webhook() {
    check_ajax_referer( 'rpsfw-stripe-webhook', 'nonce' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    $options  = get_option( 'woocommerce_rpsfw_stripe_settings', array() );
    $testmode = isset( $options['testmode'] ) && $options['testmode'] === 'yes';
    $mode     = $testmode ? 'test' : 'live';

    $status = rpsfw_stripe_connection_status();
    if ( empty( $status ) ) {
        wp_send_json_error( array( 'message' => __( 'Please connect to Stripe first.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    $webhook_secret_key = $mode === 'live' ? 'webhook_secret_live' : 'webhook_secret_test';
    if ( ! empty( $options[ $webhook_secret_key ] ) ) {
        wp_send_json_error( array( 'message' => __( 'Webhook signing secret already saved. Clear it first to create a new one.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    $webhook_url = home_url( '/wc-api/rpsfw_stripe_webhook/' );
    $webhook     = RPSFW_Stripe_API::create_webhook( $webhook_url );

    if ( is_wp_error( $webhook ) ) {
        wp_send_json_error( array( 'message' => $webhook->get_error_message() ) );
    }

    $webhook_id_key = $mode === 'live' ? 'webhook_id_live' : 'webhook_id_test';
    $options[ $webhook_id_key ]     = $webhook->id;
    $options[ $webhook_secret_key ] = $webhook->secret;
    update_option( 'woocommerce_rpsfw_stripe_settings', $options );

    RPSFW_Gateway_Stripe::log( 'Webhook created via AJAX. ID: ' . $webhook->id );

    wp_send_json_success( array(
        'message'    => __( 'Webhook created.', 'restore-paypal-standard-for-woocommerce' ),
        'webhook_id' => $webhook->id,
    ) );
}

/**
 * AJAX handler: save the manually-entered webhook signing secret.
 *
 * Stripe Connect Standard does not let the platform create webhook
 * endpoints on a connected account, so the merchant creates the
 * endpoint in their own Stripe dashboard and pastes the signing secret
 * into the gateway settings page.
 */
add_action( 'wp_ajax_rpsfw_stripe_save_webhook_secret', 'rpsfw_stripe_ajax_save_webhook_secret' );
function rpsfw_stripe_ajax_save_webhook_secret() {
    check_ajax_referer( 'rpsfw-stripe-webhook', 'nonce' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    $mode   = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : '';
    $secret = isset( $_POST['secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['secret'] ) ) ) : '';

    if ( ! in_array( $mode, array( 'test', 'live' ), true ) ) {
        wp_send_json_error( array( 'message' => __( 'Invalid mode.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    if ( empty( $secret ) ) {
        wp_send_json_error( array( 'message' => __( 'Please paste a signing secret.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    if ( strpos( $secret, 'whsec_' ) !== 0 ) {
        wp_send_json_error( array( 'message' => __( 'That does not look like a Stripe signing secret. It should start with "whsec_".', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    $options = get_option( 'woocommerce_rpsfw_stripe_settings', array() );
    $key     = $mode === 'live' ? 'webhook_secret_live' : 'webhook_secret_test';
    $options[ $key ] = $secret;
    update_option( 'woocommerce_rpsfw_stripe_settings', $options );

    RPSFW_Gateway_Stripe::log( 'Webhook signing secret saved for ' . $mode . ' mode.' );

    wp_send_json_success( array(
        'message' => __( 'Signing secret saved.', 'restore-paypal-standard-for-woocommerce' ),
    ) );
}

/**
 * AJAX handler: clear the stored webhook signing secret.
 */
add_action( 'wp_ajax_rpsfw_stripe_clear_webhook_secret', 'rpsfw_stripe_ajax_clear_webhook_secret' );
function rpsfw_stripe_ajax_clear_webhook_secret() {
    check_ajax_referer( 'rpsfw-stripe-webhook', 'nonce' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    $mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : '';

    if ( ! in_array( $mode, array( 'test', 'live' ), true ) ) {
        wp_send_json_error( array( 'message' => __( 'Invalid mode.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    $options = get_option( 'woocommerce_rpsfw_stripe_settings', array() );
    $secret_key = $mode === 'live' ? 'webhook_secret_live' : 'webhook_secret_test';
    $id_key     = $mode === 'live' ? 'webhook_id_live' : 'webhook_id_test';
    $options[ $secret_key ] = '';
    $options[ $id_key ]     = '';
    update_option( 'woocommerce_rpsfw_stripe_settings', $options );

    RPSFW_Gateway_Stripe::log( 'Webhook signing secret cleared for ' . $mode . ' mode.' );

    wp_send_json_success( array(
        'message' => __( 'Signing secret cleared.', 'restore-paypal-standard-for-woocommerce' ),
    ) );
}


/**
 * AJAX handler: return whether the current cart contains a subscription.
 * Called from the Stripe checkout JS after `updated_checkout` so it can
 * re-create the correct type of intent (PaymentIntent vs. Subscription)
 * if the customer added or removed a subscription product.
 */
add_action( 'wp_ajax_rpsfw_stripe_cart_is_subscription', 'rpsfw_stripe_ajax_cart_is_subscription' );
add_action( 'wp_ajax_nopriv_rpsfw_stripe_cart_is_subscription', 'rpsfw_stripe_ajax_cart_is_subscription' );
function rpsfw_stripe_ajax_cart_is_subscription() {
    check_ajax_referer( 'rpsfw-stripe-create-intent', 'nonce' );

    $is_subscription = class_exists( 'RPSFW_Stripe_Subscriptions' )
        && RPSFW_Stripe_Subscriptions::cart_contains_subscription();

    wp_send_json_success( array( 'is_subscription' => $is_subscription ) );
}

/**
 * AJAX handler: return freshly-formatted debug totals for the block checkout.
 *
 * The block injects the debug panel from a static snapshot taken at page load
 * (getSetting('rpsfw_stripe_data')), so it does not reflect a coupon added or
 * removed after the payment form mounted. The block re-fetches this on cart
 * change so the "Due today / Recurring" summary stays in sync with what Stripe
 * will actually bill (the billing itself is re-created separately). Test mode
 * + "Checkout Debug Totals" setting only; returns empty otherwise.
 */
/**
 * Return the deferred Payment Element mount params { mode, amount, currency }
 * for the current subscription cart, so the checkout JS can update the mounted
 * Element on cart change (coupon / shipping / address). Always on (unlike the
 * debug-totals endpoint, which is test-mode only) because the deferred flow
 * relies on it to keep the Element's amount matching the real charge.
 */
add_action( 'wp_ajax_rpsfw_stripe_get_mount_params', 'rpsfw_stripe_ajax_get_mount_params' );
add_action( 'wp_ajax_nopriv_rpsfw_stripe_get_mount_params', 'rpsfw_stripe_ajax_get_mount_params' );
function rpsfw_stripe_ajax_get_mount_params() {
    check_ajax_referer( 'rpsfw-stripe-create-intent', 'nonce' );

    $payment_gateways = WC()->payment_gateways()->payment_gateways();
    $gateway          = isset( $payment_gateways['rpsfw_stripe'] ) ? $payment_gateways['rpsfw_stripe'] : null;

    if ( ! $gateway
        || ! method_exists( $gateway, 'get_deferred_mount_params' )
        || ! class_exists( 'RPSFW_Stripe_Subscriptions' )
        || ! RPSFW_Stripe_Subscriptions::cart_contains_subscription()
    ) {
        wp_send_json_success( array( 'mount' => null ) );
    }

    wp_send_json_success( array( 'mount' => $gateway->get_deferred_mount_params() ) );
}

add_action( 'wp_ajax_rpsfw_stripe_get_debug_totals', 'rpsfw_stripe_ajax_get_debug_totals' );
add_action( 'wp_ajax_nopriv_rpsfw_stripe_get_debug_totals', 'rpsfw_stripe_ajax_get_debug_totals' );
function rpsfw_stripe_ajax_get_debug_totals() {
    check_ajax_referer( 'rpsfw-stripe-create-intent', 'nonce' );

    $payment_gateways = WC()->payment_gateways()->payment_gateways();
    $gateway          = isset( $payment_gateways['rpsfw_stripe'] ) ? $payment_gateways['rpsfw_stripe'] : null;

    if ( ! $gateway || ! method_exists( $gateway, 'get_debug_totals_data' ) ) {
        wp_send_json_success( array( 'debugTotals' => null ) );
    }

    $options  = get_option( 'woocommerce_rpsfw_stripe_settings', array() );
    $testmode = isset( $options['testmode'] ) && $options['testmode'] === 'yes';

    if ( ! $testmode
        || ! class_exists( 'RPSFW_Stripe_Subscriptions' )
        || ! RPSFW_Stripe_Subscriptions::cart_contains_subscription()
    ) {
        wp_send_json_success( array( 'debugTotals' => null ) );
    }

    $totals = $gateway->get_debug_totals_data();
    if ( ! $totals ) {
        wp_send_json_success( array( 'debugTotals' => null ) );
    }

    // Format identically to RPSFW_Gateway_Stripe_Blocks_Support::get_payment_method_data().
    $recurring_lines = array();
    if ( ! empty( $totals['recurring_lines'] ) ) {
        foreach ( $totals['recurring_lines'] as $line ) {
            if ( $line['amount'] > 0 ) {
                $recurring_lines[] = wc_price( $line['amount'], array( 'currency' => $totals['currency'] ) )
                    . ( $line['label'] ? ' ' . esc_html( $line['label'] ) : '' );
            } else {
                $recurring_lines[] = esc_html__( '$0.00 (free trial or fully discounted)', 'restore-paypal-standard-for-woocommerce' )
                    . ( $line['label'] ? ' ' . esc_html( $line['label'] ) : '' );
            }
        }
    }

    wp_send_json_success( array(
        'debugTotals' => array(
            'dueToday'       => wc_price( $totals['today'], array( 'currency' => $totals['currency'] ) ),
            'recurringLines' => $recurring_lines,
        ),
    ) );
}
