<?php
/**
 * PayPal Commerce Platform AJAX Handlers
 *
 * @package RestorePayPalStandard
 */

defined( 'ABSPATH' ) || exit;

// Shared checkout field validator (validate before opening PayPal).
require_once dirname( __FILE__ ) . '/../class-rpsfw-checkout-validator.php';

// Register AJAX handlers
add_action( 'wp_ajax_rpsfw_ppcp_start_onboarding', 'rpsfw_ppcp_ajax_start_onboarding' );
add_action( 'wp_ajax_rpsfw_ppcp_validate_checkout', 'rpsfw_ppcp_ajax_validate_checkout' );
add_action( 'wp_ajax_nopriv_rpsfw_ppcp_validate_checkout', 'rpsfw_ppcp_ajax_validate_checkout' );
add_action( 'wp_ajax_rpsfw_ppcp_check_onboarding_status', 'rpsfw_ppcp_ajax_check_onboarding_status' );
add_action( 'wp_ajax_rpsfw_ppcp_disconnect', 'rpsfw_ppcp_ajax_disconnect' );
add_action( 'wp_ajax_rpsfw_ppcp_create_order', 'rpsfw_ppcp_ajax_create_order' );
add_action( 'wp_ajax_nopriv_rpsfw_ppcp_create_order', 'rpsfw_ppcp_ajax_create_order' );
add_action( 'wp_ajax_rpsfw_ppcp_approve_order', 'rpsfw_ppcp_ajax_approve_order' );
add_action( 'wp_ajax_nopriv_rpsfw_ppcp_approve_order', 'rpsfw_ppcp_ajax_approve_order' );
add_action( 'wp_ajax_rpsfw_ppcp_cart_approve', 'rpsfw_ppcp_ajax_cart_approve' );
add_action( 'wp_ajax_nopriv_rpsfw_ppcp_cart_approve', 'rpsfw_ppcp_ajax_cart_approve' );
add_action( 'wp_ajax_rpsfw_ppcp_process_cart_payment', 'rpsfw_ppcp_ajax_process_cart_payment' );
add_action( 'wp_ajax_nopriv_rpsfw_ppcp_process_cart_payment', 'rpsfw_ppcp_ajax_process_cart_payment' );
add_action( 'wp_ajax_rpsfw_ppcp_process_cart_subscription', 'rpsfw_ppcp_ajax_process_cart_subscription' );
add_action( 'wp_ajax_nopriv_rpsfw_ppcp_process_cart_subscription', 'rpsfw_ppcp_ajax_process_cart_subscription' );
add_action( 'wp_ajax_rpsfw_ppcp_cart_is_subscription', 'rpsfw_ppcp_ajax_cart_is_subscription' );
add_action( 'wp_ajax_nopriv_rpsfw_ppcp_cart_is_subscription', 'rpsfw_ppcp_ajax_cart_is_subscription' );

/**
 * AJAX handler to return the PayPal SDK URL for the current cart state
 * (one-off mode, not subscription). Used by the cart JS when it needs to
 * dynamically reload the SDK after the last subscription item is removed.
 */
add_action( 'wp_ajax_rpsfw_ppcp_get_sdk_url', 'rpsfw_ppcp_ajax_get_sdk_url' );
add_action( 'wp_ajax_nopriv_rpsfw_ppcp_get_sdk_url', 'rpsfw_ppcp_ajax_get_sdk_url' );

/**
 * Return freshly-minted checkout nonces for the current session.
 *
 * A subscription cart forces account registration, so an order-first payment
 * attempt (e.g. clicking Place Order on Stripe) can create the customer's
 * account and log them in MID-CHECKOUT. That changes the user id, which
 * invalidates every nonce generated on the page — so a subsequent PayPal
 * button click would fail with a 403 / "-1". The button JS calls this to get
 * a fresh nonce and retry, transparently.
 *
 * Intentionally does NOT verify a nonce: minting a nonce for the caller's own
 * current session is not a security-sensitive operation.
 */
add_action( 'wp_ajax_rpsfw_ppcp_refresh_nonce', 'rpsfw_ppcp_ajax_refresh_nonce' );
add_action( 'wp_ajax_nopriv_rpsfw_ppcp_refresh_nonce', 'rpsfw_ppcp_ajax_refresh_nonce' );

/**
 * AJAX: return fresh checkout nonces.
 */
function rpsfw_ppcp_ajax_refresh_nonce() {
    wp_send_json_success( array(
        'create_nonce'       => wp_create_nonce( 'rpsfw-ppcp-create-order' ),
        'subscription_nonce' => wp_create_nonce( 'rpsfw-ppcp-create-subscription' ),
    ) );
}

/**
 * Get gateway instance.
 */
function rpsfw_ppcp_get_gateway() {
    if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
        return false;
    }
    
    $gateways = WC()->payment_gateways()->payment_gateways();
    return isset( $gateways['rpsfw_paypal_commerce'] ) ? $gateways['rpsfw_paypal_commerce'] : false;
}

/**
 * AJAX handler for starting onboarding.
 */
function rpsfw_ppcp_ajax_start_onboarding() {
    check_ajax_referer( 'rpsfw-ppcp-onboarding', 'nonce' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    // Get gateway instance
    $gateway = rpsfw_ppcp_get_gateway();
    if ( ! $gateway ) {
        wp_send_json_error( array( 'message' => __( 'Gateway not found.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    $env = ! empty( $_POST['sandbox'] ) ? 'sandbox' : 'live';
    $return_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=rpsfw_paypal_commerce&ppcp_connected=1' );
    
    $result = $gateway->api->start_onboarding( $env, $return_url );

    if ( ! $result ) {
        wp_send_json_error( array( 'message' => __( 'Failed to start onboarding. Please try again.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    // Save tracking_id
    $onboarding = $gateway->get_option( 'ppcp_onboarding', array() );
    $onboarding[ $env ] = array(
        'timestamp' => time(),
        'tracking_id' => sanitize_text_field( $result['tracking_id'] ),
        'seller_id' => '',
    );
    $gateway->update_option( 'ppcp_onboarding', $onboarding );

    // Update testmode setting
    $gateway->update_option( 'testmode', $env === 'sandbox' ? 'yes' : 'no' );

    WC_Gateway_PayPal_Commerce::log( 'Onboarding started for ' . $env . ' mode. Tracking ID: ' . $result['tracking_id'] );

    wp_send_json_success( array( 'action_url' => $result['action_url'] ) );
}

/**
 * AJAX handler for checking onboarding status.
 */
function rpsfw_ppcp_ajax_check_onboarding_status() {
    check_ajax_referer( 'rpsfw-ppcp-onboarding', 'nonce' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    // Get gateway instance
    $gateway = rpsfw_ppcp_get_gateway();
    if ( ! $gateway ) {
        wp_send_json_error( array( 'message' => __( 'Gateway not found.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    $env = ! empty( $_POST['env'] ) && $_POST['env'] === 'sandbox' ? 'sandbox' : 'live';
    $onboarding = $gateway->get_option( 'ppcp_onboarding', array() );

    // Check if we have onboarding data for this environment
    if ( empty( $onboarding[ $env ] ) ) {
        wp_send_json_success( array( 'completed' => false ) );
    }

    // If we already have a seller_id, onboarding is complete
    if ( ! empty( $onboarding[ $env ]['seller_id'] ) ) {
        wp_send_json_success( array( 
            'completed' => true,
            'seller_id' => $onboarding[ $env ]['seller_id']
        ) );
    }

    // Try to find the seller_id from PayPal
    $result = $gateway->api->find_seller_id( $env, $onboarding[ $env ] );

    if ( $result && ! empty( $result['seller_id'] ) ) {
        // Save the seller_id
        $onboarding[ $env ]['seller_id'] = sanitize_text_field( $result['seller_id'] );
        $gateway->update_option( 'ppcp_onboarding', $onboarding );

        WC_Gateway_PayPal_Commerce::log( 'Onboarding completed for ' . $env . ' mode. Seller ID: ' . $result['seller_id'] );

        // Auto-create the webhook for this env now that we have the seller
        // id. Best-effort: any failure is logged but does not stop the
        // connect flow, since the merchant can still create one manually
        // from the gateway settings page.
        if ( isset( $gateway->webhooks ) && method_exists( $gateway->webhooks, 'auto_create_for_env' ) ) {
            $gateway->webhooks->auto_create_for_env( $env );
        }

        wp_send_json_success( array( 
            'completed' => true,
            'seller_id' => $result['seller_id']
        ) );
    }

    // Not completed yet
    wp_send_json_success( array( 'completed' => false ) );
}

/**
 * AJAX handler for disconnecting.
 */
function rpsfw_ppcp_ajax_disconnect() {
    check_ajax_referer( 'rpsfw-ppcp-disconnect', 'nonce' );

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    // Get gateway instance
    $gateway = rpsfw_ppcp_get_gateway();
    if ( ! $gateway ) {
        wp_send_json_error( array( 'message' => __( 'Gateway not found.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    $env = $gateway->testmode ? 'sandbox' : 'live';
    $onboarding = $gateway->get_option( 'ppcp_onboarding', array() );

    if ( empty( $onboarding[ $env ] ) ) {
        wp_send_json_error( array( 'message' => __( 'No connection found.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    // Best-effort: tear down the webhook for this env before we wipe the
    // credentials. Once the seller id is gone we lose the ability to call
    // the delete-webhook endpoint with auth.
    $webhook_deletion = array(
        'attempted' => false,
        'success'   => false,
        'message'   => 'Webhook handler unavailable.',
        'reason'    => 'no_handler',
    );
    if ( isset( $gateway->webhooks ) && method_exists( $gateway->webhooks, 'auto_delete_for_env' ) ) {
        $webhook_deletion = $gateway->webhooks->auto_delete_for_env( $env );
    }

    WC_Gateway_PayPal_Commerce::log( 'Disconnect webhook deletion result for ' . $env . ': ' . wp_json_encode( $webhook_deletion ) );

    $result = $gateway->api->disconnect( $env, $onboarding[ $env ] );

    if ( ! $result ) {
        wp_send_json_error( array( 'message' => __( 'Failed to disconnect. Please try again.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    // Remove onboarding data
    unset( $onboarding[ $env ] );
    $gateway->update_option( 'ppcp_onboarding', $onboarding );

    WC_Gateway_PayPal_Commerce::log( 'Disconnected from ' . $env . ' mode' );

    wp_send_json_success( array(
        'message'          => __( 'Successfully disconnected.', 'restore-paypal-standard-for-woocommerce' ),
        'webhook_deletion' => $webhook_deletion,
    ) );
}

/**
 * AJAX: validate the checkout fields for the PayPal button's onClick gate.
 *
 * Returns success when the checkout form is valid, or the field error messages
 * when it isn't, so the front end can reject the button click and keep the
 * PayPal window from opening. The button posts the checkout field values (as
 * standard WooCommerce keys) which the validator reads from $_POST.
 */
function rpsfw_ppcp_ajax_validate_checkout() {
    // The PayPal button sends whichever create nonce it holds (order for
    // one-off carts, subscription for subscription carts); accept either.
    $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'rpsfw-ppcp-create-order' ) && ! wp_verify_nonce( $nonce, 'rpsfw-ppcp-create-subscription' ) ) {
        wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh the page and try again.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    $errors = RPSFW_Checkout_Validator::validate();
    if ( $errors->has_errors() ) {
        wp_send_json_error( array(
            'message'  => implode( ' ', $errors->get_error_messages() ),
            'messages' => $errors->get_error_messages(),
        ) );
    }

    wp_send_json_success();
}

/**
 * Collect the buyer's shipping address from the current create-order request.
 *
 * Both the block and classic PayPal checkout flows POST the standard
 * WooCommerce billing and shipping fields. When the shopper chose "ship to a
 * different address" we use the shipping fields; otherwise the billing
 * address is the shipping address. Returns a flat array using WooCommerce field
 * keys (address_1, city, state, postcode, country, ...), or an empty array when
 * no usable address is present.
 *
 * @return array
 */
function rpsfw_ppcp_collect_request_shipping_address() {
    $get = function( $key ) {
        return isset( $_POST[ $key ] ) ? wc_clean( wp_unslash( $_POST[ $key ] ) ) : '';
    };

    // Prefer the dedicated shipping fields only when the shopper opted to ship
    // to a different address AND actually provided a shipping street line.
    $ship_different = ! empty( $_POST['ship_to_different_address'] );
    $prefix         = ( $ship_different && '' !== $get( 'shipping_address_1' ) ) ? 'shipping' : 'billing';

    $address = array(
        'first_name' => $get( $prefix . '_first_name' ),
        'last_name'  => $get( $prefix . '_last_name' ),
        'company'    => $get( $prefix . '_company' ),
        'address_1'  => $get( $prefix . '_address_1' ),
        'address_2'  => $get( $prefix . '_address_2' ),
        'city'       => $get( $prefix . '_city' ),
        'state'      => $get( $prefix . '_state' ),
        'postcode'   => $get( $prefix . '_postcode' ),
        'country'    => $get( $prefix . '_country' ),
    );

    // Fall back to the logged-in customer's stored address if the request did
    // not carry a usable street line (e.g. an express context that does not
    // post the checkout form).
    if ( ( empty( $address['address_1'] ) || empty( $address['country'] ) ) && WC()->customer ) {
        $customer = WC()->customer;
        $address = array(
            'first_name' => $customer->get_shipping_first_name() ? $customer->get_shipping_first_name() : $customer->get_billing_first_name(),
            'last_name'  => $customer->get_shipping_last_name() ? $customer->get_shipping_last_name() : $customer->get_billing_last_name(),
            'company'    => $customer->get_shipping_company() ? $customer->get_shipping_company() : $customer->get_billing_company(),
            'address_1'  => $customer->get_shipping_address_1() ? $customer->get_shipping_address_1() : $customer->get_billing_address_1(),
            'address_2'  => $customer->get_shipping_address_2() ? $customer->get_shipping_address_2() : $customer->get_billing_address_2(),
            'city'       => $customer->get_shipping_city() ? $customer->get_shipping_city() : $customer->get_billing_city(),
            'state'      => $customer->get_shipping_state() ? $customer->get_shipping_state() : $customer->get_billing_state(),
            'postcode'   => $customer->get_shipping_postcode() ? $customer->get_shipping_postcode() : $customer->get_billing_postcode(),
            'country'    => $customer->get_shipping_country() ? $customer->get_shipping_country() : $customer->get_billing_country(),
        );
    }

    // Drop any empty fields so installs that removed checkout fields (company,
    // address 2, state, postcode, etc.) don't send empty strings to PayPal.
    // Kept: values like '0' that are legitimately non-empty.
    $address = array_filter( $address, function( $value ) {
        return '' !== $value && null !== $value;
    } );

    return $address;
}

/**
 * AJAX handler for creating PayPal order.
 */
function rpsfw_ppcp_ajax_create_order() {
    check_ajax_referer( 'rpsfw-ppcp-create-order', 'nonce' );

    // Get gateway instance
    $gateway = rpsfw_ppcp_get_gateway();
    if ( ! $gateway ) {
        wp_send_json_error( array( 'message' => __( 'Gateway not found.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    // Get cart data
    if ( ! WC()->cart ) {
        wp_send_json_error( array( 'message' => __( 'Cart not found.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    $cart = WC()->cart;
    $currency = get_woocommerce_currency();

    // Make sure totals (and shipping) are calculated for this request before
    // we read them, so the breakdown we send reflects the chosen shipping
    // method and isn't built from a stale/uncalculated cart.
    $cart->calculate_totals();

    // Order-first gate: when this request comes from the checkout page, validate
    // the WooCommerce checkout fields BEFORE creating the PayPal order, so a
    // shopper can't complete payment with an empty/invalid form. Cart-page and
    // other express contexts don't send this flag and are unaffected.
    if ( ! empty( $_POST['rpsfw_validate_fields'] ) ) {
        $checkout_errors = RPSFW_Checkout_Validator::validate();
        if ( $checkout_errors->has_errors() ) {
            wp_send_json_error( array(
                'message'          => implode( ' ', $checkout_errors->get_error_messages() ),
                'messages'         => $checkout_errors->get_error_messages(),
                'field_validation' => true,
            ) );
        }
    }

    // Build items array from cart
    $items = array();
    foreach ( $cart->get_cart() as $cart_item ) {
        $product = $cart_item['data'];
        $items[] = array(
            'name'     => $product->get_name(),
            'price'    => floatval( $product->get_price() ),
            'quantity' => intval( $cart_item['quantity'] ),
            'sku'      => $product->get_sku() ? $product->get_sku() : '',
        );
    }

    // Get environment and onboarding data
    $env = $gateway->testmode ? 'sandbox' : 'live';
    $onboarding = $gateway->get_option( 'ppcp_onboarding', array() );

    if ( empty( $onboarding[ $env ]['seller_id'] ) ) {
        wp_send_json_error( array( 'message' => __( 'PayPal connection not found.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    // Get payment action
    $payment_action = $gateway->get_option( 'payment_action', 'capture' );
    $intent = ( $payment_action === 'authorize' ) ? 'authorize' : 'capture';

    // Prepare order data for PayPal backend
    $order_data = array(
        'seller_id' => $onboarding[ $env ]['seller_id'],
        'items'     => $items,
        'currency'  => $currency,
        'intent'    => $intent,
        'is_pro'    => true, // Set to true to avoid platform fees
        'address'   => 1, // 0 = required, 1 = not required, 2 = optional
        // Authoritative grand total from WooCommerce. Already includes cart
        // fees (insurance, handling, COD, gift wrap), third-party adjustments,
        // tax, shipping and rounding that the itemized breakdown below may not
        // capture. The relay charges this exact amount and only attaches the
        // itemized breakdown when it reconciles to it.
        'amount'    => (float) $cart->get_total( 'edit' ),
    );

    // Add shipping if present
    $shipping_total = $cart->get_shipping_total();
    if ( $shipping_total > 0 ) {
        $order_data['shipping'] = floatval( $shipping_total );
    }

    // Add tax if present
    $tax_total = $cart->get_total_tax();
    if ( $tax_total > 0 ) {
        $order_data['tax'] = floatval( $tax_total );
    }

    // Add discount if present
    $discount_total = $cart->get_discount_total();
    if ( $discount_total > 0 ) {
        $order_data['discount_amount'] = floatval( $discount_total );
    }

    // Add coupon codes if present
    $applied_coupons = $cart->get_applied_coupons();
    if ( ! empty( $applied_coupons ) ) {
        $order_data['coupon_codes'] = $applied_coupons;
        
        WC_Gateway_PayPal_Commerce::log( 'Applied coupons: ' . implode( ', ', $applied_coupons ) . ' (Discount: ' . $discount_total . ')' );
    }

    // Pass the buyer's shipping address to PayPal so the transaction carries a
    // shipping address. Without it PayPal shows "Payments without a shipping
    // address are not covered by seller protection". The decision is driven by
    // whether the customer actually gave us an address, NOT by needs_shipping()
    // — the latter returns false when shipping is disabled or no shipping zone
    // matches, even for physical products, which would wrongly suppress the
    // address. If we have a usable address we always pass it
    // (SET_PROVIDED_ADDRESS). If we don't but the cart needs shipping, fall back
    // to GET_FROM_FILE so PayPal supplies one. Otherwise (digital order, no
    // address collected) stay NO_SHIPPING. Both the block and classic checkout
    // POST the standard WooCommerce billing/shipping fields to this handler.
    $shipping_address = rpsfw_ppcp_collect_request_shipping_address();

    if ( ! empty( $shipping_address['address_1'] ) && ! empty( $shipping_address['country'] ) ) {
        $order_data['shipping_address'] = $shipping_address;
        // Maps to SET_PROVIDED_ADDRESS on the relay. The relay also switches to
        // SET_PROVIDED_ADDRESS whenever a valid shipping_address is present.
        $order_data['address'] = 2;
    } elseif ( $cart->needs_shipping() ) {
        // Shippable cart but no usable address in the request: let PayPal supply
        // the buyer's on-file address (GET_FROM_FILE) so the sale is still
        // covered by Seller Protection, instead of NO_SHIPPING.
        $order_data['address'] = 0;
    }

    // Create PayPal order
    WC_Gateway_PayPal_Commerce::log( sprintf(
        'Create-order breakdown sent: items=%d, shipping=%s, tax=%s, discount=%s, amount=%s, shipping_address=%s',
        count( $items ),
        isset( $order_data['shipping'] ) ? $order_data['shipping'] : '0',
        isset( $order_data['tax'] ) ? $order_data['tax'] : '0',
        isset( $order_data['discount_amount'] ) ? $order_data['discount_amount'] : '0',
        $order_data['amount'],
        ! empty( $order_data['shipping_address'] ) ? 'provided' : 'none'
    ) );
    $result = $gateway->api->create_order( $env, $order_data );

    if ( ! $result || empty( $result['order_id'] ) ) {
        $error_message = ! empty( $result['message'] ) ? $result['message'] : __( 'Failed to create PayPal order. Please try again.', 'restore-paypal-standard-for-woocommerce' );
        WC_Gateway_PayPal_Commerce::log( 'Failed to create PayPal order: ' . $error_message, 'error' );
        wp_send_json_error( array( 'message' => $error_message ) );
    }

    WC_Gateway_PayPal_Commerce::log( 'PayPal order created: ' . $result['order_id'] );

    // Store the PayPal order ID in the session for later retrieval
    WC()->session->set( 'paypal_commerce_order_id', $result['order_id'] );

    wp_send_json_success( array( 'order_id' => $result['order_id'] ) );
}

/**
 * AJAX handler for approving PayPal order.
 * This stores the order ID in the session after user approves payment.
 */
function rpsfw_ppcp_ajax_approve_order() {
    WC_Gateway_PayPal_Commerce::log( '========== APPROVE ORDER START ==========' );
    
    check_ajax_referer( 'rpsfw-ppcp-create-order', 'nonce' );

    $paypal_order_id = isset( $_POST['paypal_order_id'] ) ? sanitize_text_field( $_POST['paypal_order_id'] ) : '';
    WC_Gateway_PayPal_Commerce::log( 'Received paypal_order_id: ' . ( $paypal_order_id ? $paypal_order_id : 'EMPTY' ) );

    if ( empty( $paypal_order_id ) ) {
        WC_Gateway_PayPal_Commerce::log( 'ERROR: PayPal order ID is missing' );
        wp_send_json_error( array( 'message' => __( 'PayPal order ID is missing.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    // Store in session
    WC_Gateway_PayPal_Commerce::log( 'WC()->session exists: ' . ( WC()->session ? 'yes' : 'no' ) );
    if ( WC()->session ) {
        WC()->session->set( 'paypal_commerce_order_id', $paypal_order_id );
        WC()->session->set( 'paypal_commerce_order_approved', true );
        WC_Gateway_PayPal_Commerce::log( 'Stored in session' );
        
        // Verify it was stored
        $verify = WC()->session->get( 'paypal_commerce_order_id' );
        WC_Gateway_PayPal_Commerce::log( 'Session verify: ' . ( $verify ? $verify : 'FAILED' ) );
    }
    
    // Store in transient for logged-in users
    $customer_id = get_current_user_id();
    WC_Gateway_PayPal_Commerce::log( 'Customer ID: ' . $customer_id );
    if ( $customer_id ) {
        $transient_key = 'paypal_commerce_order_' . $customer_id;
        set_transient( $transient_key, $paypal_order_id, 600 );
        WC_Gateway_PayPal_Commerce::log( 'Stored in transient: ' . $transient_key );
        
        // Verify
        $verify = get_transient( $transient_key );
        WC_Gateway_PayPal_Commerce::log( 'Transient verify: ' . ( $verify ? $verify : 'FAILED' ) );
    }
    
    // Store in transient for guests
    $guest_id = isset( $_COOKIE['woocommerce_cart_hash'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['woocommerce_cart_hash'] ) ) : '';
    // Validate cart hash format (WooCommerce uses md5 hashes)
    if ( $guest_id && ! preg_match( '/^[a-f0-9]{32}$/', $guest_id ) ) {
        WC_Gateway_PayPal_Commerce::log( 'Invalid cart hash format, ignoring: ' . $guest_id, 'warning' );
        $guest_id = '';
    }
    WC_Gateway_PayPal_Commerce::log( 'Guest cart hash: ' . ( $guest_id ? $guest_id : 'NOT SET' ) );
    if ( $guest_id ) {
        $transient_key = 'paypal_commerce_order_guest_' . $guest_id;
        set_transient( $transient_key, $paypal_order_id, 600 );
        WC_Gateway_PayPal_Commerce::log( 'Stored in guest transient: ' . $transient_key );
    }

    WC_Gateway_PayPal_Commerce::log( '========== APPROVE ORDER END ==========' );

    wp_send_json_success( array( 'message' => 'Order ID stored', 'order_id' => $paypal_order_id ) );
}


/**
 * Hook into WooCommerce Store API to handle payment data from Blocks checkout
 */
add_action( 'woocommerce_store_api_checkout_update_order_from_request', 'rpsfw_ppcp_store_api_update_order', 10, 2 );

/**
 * AJAX handler for cart PayPal approval.
 * This stores the order ID in the session after user approves payment from cart page.
 */
function rpsfw_ppcp_ajax_cart_approve() {
    WC_Gateway_PayPal_Commerce::log( '========== CART APPROVE START ==========' );
    
    check_ajax_referer( 'rpsfw-ppcp-create-order', 'nonce' );

    $paypal_order_id = isset( $_POST['paypal_order_id'] ) ? sanitize_text_field( $_POST['paypal_order_id'] ) : '';
    WC_Gateway_PayPal_Commerce::log( 'Cart approve - PayPal order ID: ' . ( $paypal_order_id ? $paypal_order_id : 'EMPTY' ) );

    if ( empty( $paypal_order_id ) ) {
        WC_Gateway_PayPal_Commerce::log( 'ERROR: PayPal order ID is missing' );
        wp_send_json_error( array( 'message' => __( 'PayPal order ID is missing.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    // Store in session
    if ( WC()->session ) {
        WC()->session->set( 'paypal_commerce_order_id', $paypal_order_id );
        WC()->session->set( 'paypal_commerce_order_approved', true );
        WC()->session->set( 'paypal_commerce_from_cart', true );
        WC_Gateway_PayPal_Commerce::log( 'Stored in session for cart checkout' );
    }
    
    // Store in transient for logged-in users
    $customer_id = get_current_user_id();
    if ( $customer_id ) {
        $transient_key = 'paypal_commerce_order_' . $customer_id;
        set_transient( $transient_key, $paypal_order_id, 600 );
        WC_Gateway_PayPal_Commerce::log( 'Stored in transient: ' . $transient_key );
    }
    
    // Store in transient for guests
    $guest_id = isset( $_COOKIE['woocommerce_cart_hash'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['woocommerce_cart_hash'] ) ) : '';
    // Validate cart hash format (WooCommerce uses md5 hashes)
    if ( $guest_id && ! preg_match( '/^[a-f0-9]{32}$/', $guest_id ) ) {
        WC_Gateway_PayPal_Commerce::log( 'Invalid cart hash format, ignoring: ' . $guest_id, 'warning' );
        $guest_id = '';
    }
    if ( $guest_id ) {
        $transient_key = 'paypal_commerce_order_guest_' . $guest_id;
        set_transient( $transient_key, $paypal_order_id, 600 );
        WC_Gateway_PayPal_Commerce::log( 'Stored in guest transient: ' . $transient_key );
    }

    WC_Gateway_PayPal_Commerce::log( '========== CART APPROVE END ==========' );

    wp_send_json_success( array( 
        'message' => 'Order approved from cart',
        'order_id' => $paypal_order_id,
        'redirect' => wc_get_checkout_url()
    ) );
}

/**
 * Store PayPal order ID from Blocks checkout request
 */
function rpsfw_ppcp_store_api_update_order( $order, $request ) {
    WC_Gateway_PayPal_Commerce::log( '========== STORE API UPDATE ORDER ==========' );
    
    $payment_method = $request->get_param( 'payment_method' );
    WC_Gateway_PayPal_Commerce::log( 'Payment method: ' . $payment_method );
    
    if ( $payment_method !== 'rpsfw_paypal_commerce' ) {
        return;
    }
    
    $payment_data = $request->get_param( 'payment_data' );
    WC_Gateway_PayPal_Commerce::log( 'Payment data: ' . print_r( $payment_data, true ) );
    
    // Payment data comes as array of key/value pairs
    $paypal_order_id = '';
    if ( is_array( $payment_data ) ) {
        foreach ( $payment_data as $data ) {
            if ( isset( $data['key'] ) && $data['key'] === 'paypal_order_id' && ! empty( $data['value'] ) ) {
                $paypal_order_id = sanitize_text_field( $data['value'] );
                break;
            }
        }
    }
    
    WC_Gateway_PayPal_Commerce::log( 'Extracted PayPal order ID: ' . ( $paypal_order_id ? $paypal_order_id : 'EMPTY' ) );
    
    if ( ! empty( $paypal_order_id ) ) {
        // Store in order meta
        $order->update_meta_data( '_paypal_order_id', $paypal_order_id );
        $order->save();
        WC_Gateway_PayPal_Commerce::log( 'Stored PayPal order ID in order meta' );
        
        // Also store in session and transient as backup
        if ( WC()->session ) {
            WC()->session->set( 'paypal_commerce_order_id', $paypal_order_id );
        }
        
        $customer_id = get_current_user_id();
        if ( $customer_id ) {
            set_transient( 'paypal_commerce_order_' . $customer_id, $paypal_order_id, 600 );
        }
    }
    
    WC_Gateway_PayPal_Commerce::log( '===========================================' );
}

/**
 * AJAX handler for processing cart payment.
 * Creates WooCommerce order and captures PayPal payment.
 */
function rpsfw_ppcp_ajax_process_cart_payment() {
    WC_Gateway_PayPal_Commerce::log( '========== PROCESS CART PAYMENT START ==========' );
    
    check_ajax_referer( 'rpsfw-ppcp-create-order', 'nonce' );

    $paypal_order_id = isset( $_POST['paypal_order_id'] ) ? sanitize_text_field( $_POST['paypal_order_id'] ) : '';
    
    if ( empty( $paypal_order_id ) ) {
        WC_Gateway_PayPal_Commerce::log( 'ERROR: PayPal order ID is missing' );
        wp_send_json_error( array( 'message' => __( 'PayPal order ID is missing.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    WC_Gateway_PayPal_Commerce::log( 'PayPal Order ID: ' . $paypal_order_id );

    // Get gateway instance
    $gateway = rpsfw_ppcp_get_gateway();
    if ( ! $gateway ) {
        wp_send_json_error( array( 'message' => __( 'Gateway not found.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    // Get cart
    if ( ! WC()->cart || WC()->cart->is_empty() ) {
        wp_send_json_error( array( 'message' => __( 'Cart is empty.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    // Get environment and onboarding data
    $env = $gateway->testmode ? 'sandbox' : 'live';
    $onboarding = $gateway->get_option( 'ppcp_onboarding', array() );

    if ( empty( $onboarding[ $env ]['seller_id'] ) ) {
        wp_send_json_error( array( 'message' => __( 'PayPal connection not found.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    // Get PayPal order details to extract shipping address
    $paypal_order_details = $gateway->api->get_order_details( $env, $onboarding[ $env ], $paypal_order_id );
    WC_Gateway_PayPal_Commerce::log( 'PayPal order details: ' . print_r( $paypal_order_details, true ) );

    // Create WooCommerce order
    try {
        $order = wc_create_order();

        // The express cart flow builds its own order instead of going through
        // WC_Gateway_PayPal_Commerce::process_payment(), so the mode stamp
        // applied there never runs here. Record it now, before anything reads
        // it: refunds, dashboard links and webhook routing all resolve an
        // order's account from this rather than the gateway's current setting.
        rpsfw_set_order_payment_mode( $order, rpsfw_get_gateway_mode( $gateway->id ) );
        
        // Add cart items to order
        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
            $product = $cart_item['data'];
            $order->add_product( $product, $cart_item['quantity'] );
        }

        // Apply any coupons from the cart so the WooCommerce order total
        // reflects the discount the customer approved on PayPal. Without
        // this the order total would be the undiscounted amount, which
        // would fail the amount validation below (or record the order as
        // underpaid against the discounted PayPal capture).
        foreach ( WC()->cart->get_applied_coupons() as $coupon_code ) {
            $order->apply_coupon( $coupon_code );
        }

        // Set customer info from PayPal if available
        if ( ! empty( $paypal_order_details['payer'] ) ) {
            $payer = $paypal_order_details['payer'];
            
            if ( ! empty( $payer['email_address'] ) ) {
                $order->set_billing_email( $payer['email_address'] );
            }
            
            if ( ! empty( $payer['name'] ) ) {
                if ( ! empty( $payer['name']['given_name'] ) ) {
                    $order->set_billing_first_name( $payer['name']['given_name'] );
                }
                if ( ! empty( $payer['name']['surname'] ) ) {
                    $order->set_billing_last_name( $payer['name']['surname'] );
                }
            }
        }

        // Set shipping address from PayPal if available
        if ( ! empty( $paypal_order_details['purchase_units'][0]['shipping'] ) ) {
            $shipping = $paypal_order_details['purchase_units'][0]['shipping'];
            
            if ( ! empty( $shipping['name']['full_name'] ) ) {
                $name_parts = explode( ' ', $shipping['name']['full_name'], 2 );
                $order->set_shipping_first_name( $name_parts[0] );
                $order->set_shipping_last_name( isset( $name_parts[1] ) ? $name_parts[1] : '' );
            }
            
            if ( ! empty( $shipping['address'] ) ) {
                $address = $shipping['address'];
                $order->set_shipping_address_1( ! empty( $address['address_line_1'] ) ? $address['address_line_1'] : '' );
                $order->set_shipping_address_2( ! empty( $address['address_line_2'] ) ? $address['address_line_2'] : '' );
                $order->set_shipping_city( ! empty( $address['admin_area_2'] ) ? $address['admin_area_2'] : '' );
                $order->set_shipping_state( ! empty( $address['admin_area_1'] ) ? $address['admin_area_1'] : '' );
                $order->set_shipping_postcode( ! empty( $address['postal_code'] ) ? $address['postal_code'] : '' );
                $order->set_shipping_country( ! empty( $address['country_code'] ) ? $address['country_code'] : '' );
                
                // Copy to billing if billing is empty
                if ( empty( $order->get_billing_address_1() ) ) {
                    $order->set_billing_address_1( $order->get_shipping_address_1() );
                    $order->set_billing_address_2( $order->get_shipping_address_2() );
                    $order->set_billing_city( $order->get_shipping_city() );
                    $order->set_billing_state( $order->get_shipping_state() );
                    $order->set_billing_postcode( $order->get_shipping_postcode() );
                    $order->set_billing_country( $order->get_shipping_country() );
                    
                    if ( empty( $order->get_billing_first_name() ) ) {
                        $order->set_billing_first_name( $order->get_shipping_first_name() );
                        $order->set_billing_last_name( $order->get_shipping_last_name() );
                    }
                }
            }
        }

        // Set payment method
        $order->set_payment_method( 'rpsfw_paypal_commerce' );
        $order->set_payment_method_title( $gateway->get_title() );

        // Add shipping line items from the cart. The express flow builds the
        // order manually with wc_create_order(), which (unlike WooCommerce's
        // normal checkout) does NOT carry shipping over from the cart. Without
        // this the order silently drops shipping — under-recording the sale and
        // failing to reconcile with the PayPal amount, which already includes
        // shipping. Mirrors WC_Checkout::create_order_shipping_lines().
        WC()->cart->calculate_shipping();
        $chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods', array() );
        foreach ( WC()->shipping()->get_packages() as $package_key => $package ) {
            $chosen_id = isset( $chosen_shipping_methods[ $package_key ] ) ? $chosen_shipping_methods[ $package_key ] : '';
            if ( '' === $chosen_id || empty( $package['rates'][ $chosen_id ] ) ) {
                continue;
            }
            $rate          = $package['rates'][ $chosen_id ];
            $shipping_item = new WC_Order_Item_Shipping();
            $shipping_item->set_props( array(
                'method_title' => $rate->get_label(),
                'method_id'    => $rate->get_method_id(),
                'instance_id'  => $rate->get_instance_id(),
                'total'        => wc_format_decimal( $rate->get_cost() ),
                'taxes'        => array( 'total' => $rate->get_taxes() ),
            ) );
            foreach ( $rate->get_meta_data() as $meta_key => $meta_value ) {
                $shipping_item->add_meta_data( $meta_key, $meta_value, true );
            }
            $order->add_item( $shipping_item );
            WC_Gateway_PayPal_Commerce::log( 'Added shipping line to express order: ' . $rate->get_label() . ' = ' . $rate->get_cost() );
        }

        // Add cart fee line items (e.g. insurance, handling, gift wrap, COD, or
        // any third-party fee added via the woocommerce_cart_calculate_fees
        // hook). Like shipping, the manual express order does not carry these
        // over from the cart automatically, so without this the recorded order
        // total would not match the amount charged. Mirrors
        // WC_Checkout::create_order_fee_lines().
        foreach ( WC()->cart->get_fees() as $fee ) {
            $fee_item = new WC_Order_Item_Fee();
            $fee_item->set_props( array(
                'name'      => $fee->name,
                'tax_class' => $fee->taxable ? $fee->tax_class : 0,
                'total'     => $fee->amount,
                'total_tax' => $fee->tax,
                'taxes'     => array( 'total' => $fee->tax_data ),
            ) );
            $order->add_item( $fee_item );
            WC_Gateway_PayPal_Commerce::log( 'Added fee line to express order: ' . $fee->name . ' = ' . $fee->amount );
        }

        // Calculate totals
        $order->calculate_totals();

        // Set customer ID if logged in
        if ( is_user_logged_in() ) {
            $order->set_customer_id( get_current_user_id() );
        }

        // Store PayPal order ID
        $order->update_meta_data( '_paypal_order_id', $paypal_order_id );

        $order->save();

        WC_Gateway_PayPal_Commerce::log( 'WooCommerce order created: ' . $order->get_id() );

    } catch ( Exception $e ) {
        WC_Gateway_PayPal_Commerce::log( 'Failed to create WooCommerce order: ' . $e->getMessage(), 'error' );
        wp_send_json_error( array( 'message' => __( 'Failed to create order.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    // Validate PayPal order amount before capture/authorize (if enabled)
    if ( 'yes' === $gateway->get_option( 'validate_order_amount', 'yes' ) ) {
        $expected_amount = $order->get_total();
        $expected_currency = $order->get_currency();
        
        $validation_result = $gateway->api->validate_order_amount(
            $env,
            $onboarding[ $env ],
            $paypal_order_id,
            $expected_amount,
            $expected_currency
        );
        
        if ( ! $validation_result['valid'] ) {
            WC_Gateway_PayPal_Commerce::log( 'SECURITY: Order amount validation failed (Cart Express) - ' . $validation_result['message'], 'error' );
            $order->update_status( 'failed', $validation_result['message'] );
            wp_send_json_error( array( 'message' => $validation_result['message'] ) );
        }
        
        if ( ! empty( $validation_result['skipped'] ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Order amount validation was skipped (Cart Express): ' . $validation_result['message'] );
        }
    }

    // Get payment action
    $payment_action = $gateway->get_option( 'payment_action', 'capture' );
    
    if ( $payment_action === 'capture' ) {
        // Capture the payment
        $capture_result = $gateway->api->capture_order( $env, $onboarding[ $env ], $paypal_order_id );
        WC_Gateway_PayPal_Commerce::log( 'Capture result: ' . print_r( $capture_result, true ) );

        if ( ! $capture_result || empty( $capture_result['status'] ) ) {
            $order->update_status( 'failed', __( 'PayPal capture failed.', 'restore-paypal-standard-for-woocommerce' ) );
            wp_send_json_error( array( 'message' => __( 'Failed to capture payment.', 'restore-paypal-standard-for-woocommerce' ) ) );
        }

        if ( $capture_result['status'] === 'COMPLETED' ) {
            $transaction_id = ! empty( $capture_result['transaction_id'] ) ? $capture_result['transaction_id'] : $paypal_order_id;
            
            // Store capture_id for refunds (use capture_id if available, otherwise use transaction_id)
            $capture_id = ! empty( $capture_result['capture_id'] ) ? $capture_result['capture_id'] : $transaction_id;
            $order->update_meta_data( '_paypal_capture_id', $capture_id );
            $order->save();
            
            $order->payment_complete( $transaction_id );
            /* translators: %s: PayPal transaction ID. */
            $order->add_order_note( sprintf( __( 'PayPal Commerce payment completed (Cart Express Checkout). Transaction ID: %s', 'restore-paypal-standard-for-woocommerce' ), $transaction_id ) );

            WC_Gateway_PayPal_Commerce::log( 'Payment completed. Transaction ID: ' . $transaction_id . ', Capture ID: ' . $capture_id );
        } else {
            /* translators: %s: PayPal payment status. */
            $order->update_status( 'failed', sprintf( __( 'PayPal payment failed. Status: %s', 'restore-paypal-standard-for-woocommerce' ), $capture_result['status'] ) );
            wp_send_json_error( array( 'message' => __( 'Payment could not be completed.', 'restore-paypal-standard-for-woocommerce' ) ) );
        }
    } else {
        // Authorize only
        $authorize_result = $gateway->api->authorize_order( $env, $onboarding[ $env ], $paypal_order_id );
        WC_Gateway_PayPal_Commerce::log( 'Authorize result: ' . print_r( $authorize_result, true ) );

        if ( ! $authorize_result || empty( $authorize_result['status'] ) ) {
            $order->update_status( 'failed', __( 'PayPal authorization failed.', 'restore-paypal-standard-for-woocommerce' ) );
            wp_send_json_error( array( 'message' => __( 'Failed to authorize payment.', 'restore-paypal-standard-for-woocommerce' ) ) );
        }

        if ( $authorize_result['status'] === 'COMPLETED' ) {
            $authorization_id = ! empty( $authorize_result['authorization_id'] ) ? $authorize_result['authorization_id'] : $paypal_order_id;
            
            $order->update_meta_data( '_paypal_authorization_id', $authorization_id );
            $order->set_transaction_id( $authorization_id );
            /* translators: %s: PayPal authorization ID. */
            $order->update_status( 'on-hold', sprintf( __( 'PayPal payment authorized (Cart Express Checkout). Authorization ID: %s. Capture the payment to complete the order.', 'restore-paypal-standard-for-woocommerce' ), $authorization_id ) );
            $order->save();

            WC_Gateway_PayPal_Commerce::log( 'Payment authorized. Authorization ID: ' . $authorization_id );
        } else {
            /* translators: %s: PayPal authorization status. */
            $order->update_status( 'failed', sprintf( __( 'PayPal authorization failed. Status: %s', 'restore-paypal-standard-for-woocommerce' ), $authorize_result['status'] ) );
            wp_send_json_error( array( 'message' => __( 'Payment could not be authorized.', 'restore-paypal-standard-for-woocommerce' ) ) );
        }
    }

    // Clear cart
    WC()->cart->empty_cart();

    // Clear session data
    if ( WC()->session ) {
        WC()->session->__unset( 'paypal_commerce_order_id' );
        WC()->session->__unset( 'paypal_commerce_order_approved' );
        WC()->session->__unset( 'paypal_commerce_from_cart' );
    }

    WC_Gateway_PayPal_Commerce::log( '========== PROCESS CART PAYMENT END ==========' );

    wp_send_json_success( array(
        'redirect' => $order->get_checkout_order_received_url(),
        'order_id' => $order->get_id()
    ) );
}


/**
 * AJAX handler for cart-page express checkout subscription.
 *
 * Triggered after the customer approves the PayPal subscription on the
 * cart page. Creates a WC order from the cart, links it to the
 * pending PayPal subscription id stashed in session, and finalizes via
 * the subscriptions integration's shared finalize routine. Then
 * redirects the customer to the order-received page.
 */
function rpsfw_ppcp_ajax_process_cart_subscription() {
    WC_Gateway_PayPal_Commerce::log( '========== PROCESS CART SUBSCRIPTION START ==========' );

    check_ajax_referer( 'rpsfw-ppcp-create-subscription', 'nonce' );

    // Resolve subscription id: prefer POST, fall back to session.
    $subscription_id = '';
    if ( ! empty( $_POST['paypal_subscription_id'] ) ) {
        $subscription_id = sanitize_text_field( wp_unslash( $_POST['paypal_subscription_id'] ) );
    } elseif ( WC()->session ) {
        $subscription_id = WC()->session->get( 'rpsfw_ppcp_pending_subscription_id' );
    }

    if ( empty( $subscription_id ) ) {
        wp_send_json_error( array( 'message' => __( 'PayPal subscription ID is missing.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    $gateway = rpsfw_ppcp_get_gateway();
    if ( ! $gateway ) {
        wp_send_json_error( array( 'message' => __( 'Gateway not found.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    if ( ! WC()->cart || WC()->cart->is_empty() ) {
        wp_send_json_error( array( 'message' => __( 'Cart is empty.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    // Built-in (native) subscriptions: when the WooCommerce Subscriptions
    // plugin is not active, the native module owns the express-cart flow.
    // Delegates and responds (the call below exits).
    if ( ! function_exists( 'wcs_is_subscription' )
        && function_exists( 'rpsfw_native_subscriptions_active' )
        && rpsfw_native_subscriptions_active()
        && class_exists( 'RPSFW_Subscriptions_PayPal_Commerce' ) ) {
        RPSFW_Subscriptions_PayPal_Commerce::process_cart_subscription( $subscription_id );
        return;
    }

    if ( ! function_exists( 'wcs_is_subscription' ) ) {
        wp_send_json_error( array( 'message' => __( 'WooCommerce Subscriptions is required.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    if ( ! WC_PayPal_Commerce_Subscriptions::cart_contains_subscription() ) {
        wp_send_json_error( array( 'message' => __( 'No subscription in cart.', 'restore-paypal-standard-for-woocommerce' ) ) );
    }

    // Build a WC order from the cart. WC Subscriptions hooks into the
    // order-creation pipeline to spawn the corresponding
    // shop_subscription posts. We use the same line-item builders the
    // standard WC checkout uses so the initial-payment line totals
    // include sign-up fees and trial-aware pricing.
    try {
        WC()->cart->calculate_totals();

        $order = wc_create_order();

        // Stamp the mode for the same reason as the one-off express flow
        // above: this path creates its own order and never reaches
        // WC_Gateway_PayPal_Commerce::process_payment().
        rpsfw_set_order_payment_mode( $order, rpsfw_get_gateway_mode( $gateway->id ) );

        // Use WC's checkout helpers which compute line totals from the
        // cart's already-resolved sign-up + recurring math, instead of
        // $order->add_product() which would use base product prices and
        // miss the sign-up fee.
        if ( is_callable( array( WC()->checkout, 'create_order_line_items' ) ) ) {
            WC()->checkout->create_order_line_items( $order, WC()->cart );
        } else {
            // Fallback for very old WC versions.
            foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
                $product = $cart_item['data'];
                $order->add_product( $product, $cart_item['quantity'] );
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

        // Pull buyer details from the PayPal subscription so the WC
        // order has at least an email + name to show in admin.
        $env        = $gateway->testmode ? 'sandbox' : 'live';
        $onboarding = $gateway->get_option( 'ppcp_onboarding', array() );
        $onboard    = isset( $onboarding[ $env ] ) ? $onboarding[ $env ] : array();
        $details    = $gateway->api->get_subscription( $env, $onboard, $subscription_id );

        if ( ! empty( $details['subscriber'] ) ) {
            $sub = $details['subscriber'];
            if ( ! empty( $sub['email_address'] ) ) {
                $order->set_billing_email( $sub['email_address'] );
            }
            if ( ! empty( $sub['name']['given_name'] ) ) {
                $order->set_billing_first_name( $sub['name']['given_name'] );
            }
            if ( ! empty( $sub['name']['surname'] ) ) {
                $order->set_billing_last_name( $sub['name']['surname'] );
            }
            if ( ! empty( $sub['shipping_address']['address'] ) ) {
                $addr = $sub['shipping_address']['address'];
                $order->set_shipping_address_1( ! empty( $addr['address_line_1'] ) ? $addr['address_line_1'] : '' );
                $order->set_shipping_address_2( ! empty( $addr['address_line_2'] ) ? $addr['address_line_2'] : '' );
                $order->set_shipping_city( ! empty( $addr['admin_area_2'] ) ? $addr['admin_area_2'] : '' );
                $order->set_shipping_state( ! empty( $addr['admin_area_1'] ) ? $addr['admin_area_1'] : '' );
                $order->set_shipping_postcode( ! empty( $addr['postal_code'] ) ? $addr['postal_code'] : '' );
                $order->set_shipping_country( ! empty( $addr['country_code'] ) ? $addr['country_code'] : '' );
                if ( empty( $order->get_billing_address_1() ) ) {
                    $order->set_billing_address_1( $order->get_shipping_address_1() );
                    $order->set_billing_address_2( $order->get_shipping_address_2() );
                    $order->set_billing_city( $order->get_shipping_city() );
                    $order->set_billing_state( $order->get_shipping_state() );
                    $order->set_billing_postcode( $order->get_shipping_postcode() );
                    $order->set_billing_country( $order->get_shipping_country() );
                }
            }
        }

        $order->set_payment_method( 'rpsfw_paypal_commerce' );
        $order->set_payment_method_title( $gateway->get_title() );
        if ( is_user_logged_in() ) {
            $order->set_customer_id( get_current_user_id() );
        }

        // Pull totals from the cart (which already accounts for trial,
        // sign-up fee, and discount logic) instead of recomputing from
        // line items only.
        $order->set_discount_total( WC()->cart->get_discount_total() );
        $order->set_discount_tax( WC()->cart->get_discount_tax() );
        $order->set_cart_tax( WC()->cart->get_cart_contents_tax() + WC()->cart->get_fee_tax() );
        $order->set_shipping_total( WC()->cart->get_shipping_total() );
        $order->set_shipping_tax( WC()->cart->get_shipping_tax() );
        $order->set_total( WC()->cart->get_total( 'edit' ) );

        $order->save();

        WC_Gateway_PayPal_Commerce::log( 'WC order created for cart subscription: ' . $order->get_id() . ' (initial total: ' . $order->get_total() . ')' );

        // Drive the WC Subscriptions checkout pipeline so the
        // shop_subscription post(s) get created and linked to this WC
        // order. Normally this fires from the
        // woocommerce_checkout_order_processed hook during a regular
        // checkout, but the cart-express path bypasses that pipeline.
        if ( class_exists( 'WC_Subscriptions_Checkout' ) ) {
            // Recurring carts are populated on cart->calculate_totals.
            WC()->cart->calculate_totals();
            WC_Subscriptions_Checkout::process_checkout( $order->get_id(), array() );
        }

        // Hand off to the subscriptions integration to verify the PayPal
        // subscription, persist meta on the order + WC subscriptions,
        // and mark the order paid. The static call avoids re-registering
        // the integration's hooks.
        WC_PayPal_Commerce_Subscriptions::finalize_subscription_for_order( $gateway, $order, $subscription_id );
    } catch ( Exception $e ) {
        WC_Gateway_PayPal_Commerce::log( 'Cart subscription finalize failed: ' . $e->getMessage(), 'error' );
        if ( isset( $order ) && $order ) {
            $order->update_status( 'failed', $e->getMessage() );
        }
        wp_send_json_error( array( 'message' => $e->getMessage() ) );
    }

    // Clear cart and session.
    WC()->cart->empty_cart();
    if ( WC()->session ) {
        WC()->session->__unset( 'rpsfw_ppcp_pending_subscription_id' );
        WC()->session->__unset( 'rpsfw_ppcp_pending_plan_id' );
        WC()->session->__unset( 'rpsfw_ppcp_pending_product_id' );
    }

    WC_Gateway_PayPal_Commerce::log( '========== PROCESS CART SUBSCRIPTION END ==========' );

    wp_send_json_success( array(
        'redirect' => $order->get_checkout_order_received_url(),
        'order_id' => $order->get_id(),
    ) );
}

/**
 * AJAX handler to check whether the current cart contains a subscription.
 *
 * Called by the cart-page JS after `updated_cart_totals` so the PayPal
 * buttons can be re-rendered with the correct label (subscribe vs paypal)
 * after items are added or removed from the cart.
 *
 * Also returns the one-off (non-subscription) SDK URL in the same
 * response so the JS can reload the SDK in a single round-trip when the
 * last subscription item is removed — avoiding a second AJAX call and
 * the extra flicker that came with it.
 */
function rpsfw_ppcp_ajax_cart_is_subscription() {
    $is_subscription = class_exists( 'WC_PayPal_Commerce_Subscriptions' )
        && WC_PayPal_Commerce_Subscriptions::cart_contains_subscription();

    // Re-evaluate cart express eligibility on each cart update. Express
    // checkout is only offered for carts that don't require shipping — the
    // shipping address/cost can't be collected before approval. This applies to
    // subscription carts too, so a shippable cart hides the express button (and
    // its "or" separator) and the customer uses the normal checkout instead.
    $is_eligible = ! ( WC()->cart && WC()->cart->needs_shipping() );

    wp_send_json_success( array(
        'is_subscription' => $is_subscription,
        'is_eligible'     => $is_eligible,
        'sdk_url'         => rpsfw_ppcp_build_oneoff_sdk_url(),
    ) );
}

/**
 * AJAX handler: return the PayPal SDK URL for one-off (non-subscription)
 * mode. Kept for backward compatibility / direct callers.
 */
function rpsfw_ppcp_ajax_get_sdk_url() {
    $sdk_url = rpsfw_ppcp_build_oneoff_sdk_url();

    if ( empty( $sdk_url ) ) {
        wp_send_json_error( array( 'message' => 'Could not build SDK URL.' ) );
    }

    wp_send_json_success( array( 'sdk_url' => $sdk_url ) );
}

/**
 * Build the PayPal JS SDK URL for one-off (non-subscription) mode.
 *
 * Mirrors the PHP enqueue code but without intent=subscription / vault
 * additions from the subscriptions integration, so the resulting buttons
 * work for a regular (non-subscription) checkout.
 *
 * @return string The SDK URL, or '' if the gateway is unavailable.
 */
function rpsfw_ppcp_build_oneoff_sdk_url() {
    $gateway = rpsfw_ppcp_get_gateway();

    if ( ! $gateway ) {
        return '';
    }

    $env        = $gateway->testmode ? 'sandbox' : 'live';
    $onboarding = $gateway->get_option( 'ppcp_onboarding', array() );

    if ( empty( $onboarding[ $env ]['seller_id'] ) ) {
        return '';
    }

    $client_id = $gateway->api->get_client_id( $env, $onboarding[ $env ] );

    if ( empty( $client_id ) ) {
        return '';
    }

    $payment_action = $gateway->get_option( 'payment_action', 'capture' );
    $intent         = ( $payment_action === 'authorize' ) ? 'authorize' : 'capture';

    $sdk_args = array(
        'client-id'  => $client_id,
        'currency'   => get_woocommerce_currency(),
        'intent'     => $intent,
        'components' => 'buttons,messages',
    );

    // Sandbox only: eligibility for Venmo / Pay Later is driven by the
    // buyer-country parameter (PayPal ignores the real IP/locale in sandbox).
    // Use the WooCommerce store base country. Never sent in live mode.
    if ( 'sandbox' === $env && function_exists( 'WC' ) && WC()->countries ) {
        $base_country = WC()->countries->get_base_country();
        if ( $base_country ) {
            $sdk_args['buyer-country'] = $base_country;
        }
    }

    // We render each funding source explicitly (per fundingSource) in JS and
    // gate it with isEligible(), so we do NOT build a disable-funding list.
    // Disabling 'paypal' here can make the unbranded Card button ineligible.
    // Hiding is handled by which sources the JS chooses to render.

    // Enable-funding makes the non-default enabled sources eligible.
    $enable_funding = array();
    if ( 'yes' === $gateway->get_option( 'enable_venmo', 'no' ) ) {
        $enable_funding[] = 'venmo';
    }
    if ( 'yes' === $gateway->get_option( 'enable_paylater', 'no' ) ) {
        $enable_funding[] = 'paylater';
    }
    if ( ! empty( $enable_funding ) ) {
        $sdk_args['enable-funding'] = implode( ',', $enable_funding );
    }

    // Intentionally NOT applying the rpsfw_ppcp_sdk_args filter here
    // because the subscriptions integration would add intent=subscription
    // back, which is exactly what we are trying to avoid.

    return add_query_arg( $sdk_args, 'https://www.paypal.com/sdk/js' );
}
