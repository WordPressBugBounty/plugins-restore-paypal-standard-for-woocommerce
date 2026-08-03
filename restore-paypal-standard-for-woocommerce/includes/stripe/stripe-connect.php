<?php
/**
 * Stripe Connect functionality
 *
 * @package RestorePayPalStandard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get Stripe connection status
 *
 * @return array|false Connection status or false if not connected
 */
function rpsfw_stripe_connection_status( $force_refresh = false ) {
    global $rpsfw_stripe_connection_status;

    if ( ! $force_refresh && isset( $rpsfw_stripe_connection_status ) ) {
        return $rpsfw_stripe_connection_status;
    }

    $rpsfw_stripe_connection_status = false;

    $options = get_option( 'woocommerce_rpsfw_stripe_settings', array() );

    // Determine which mode we're in
    $testmode = isset( $options['testmode'] ) && $options['testmode'] === 'yes';

    if ( $testmode ) {
        $account_id_key = 'acct_id_test';
        $stripe_connect_token_key = 'stripe_connect_token_test';
        $publishable_key_key = 'publishable_key_test';
        $mode = 'sandbox';
    } else {
        $account_id_key = 'acct_id_live';
        $stripe_connect_token_key = 'stripe_connect_token_live';
        $publishable_key_key = 'publishable_key_live';
        $mode = 'live';
    }

    $account_id = isset( $options[$account_id_key] ) ? $options[$account_id_key] : '';
    $token = isset( $options[$stripe_connect_token_key] ) ? $options[$stripe_connect_token_key] : '';
    $publishable_key = isset( $options[$publishable_key_key] ) ? $options[$publishable_key_key] : '';

    if ( empty( $account_id ) ) {
        return $rpsfw_stripe_connection_status;
    }

    $cache_key    = 'rpsfw_stripe_conn_' . $mode;
    $lastgood_key = 'rpsfw_stripe_conn_lastgood_' . $mode;

    // Cache-first: the status is checked on every checkout render via
    // is_available(). Without caching that means a synchronous remote request
    // per page load, and any slowness/outage would remove the gateway from
    // checkout. Serve a cached result when fresh unless a refresh is forced
    // (e.g. the admin settings screen).
    if ( ! $force_refresh ) {
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            $rpsfw_stripe_connection_status = $cached;
            return $cached;
        }
    }

    $url = RPSFW_STRIPE_CONNECT_ENDPOINT . '?' . http_build_query(
        array(
            'action'     => 'checkStatus',
            'mode'       => $mode,
            'account_id' => $account_id,
            'token'      => $token,
        )
    );

    $response = wp_remote_get( $url, array( 'timeout' => 15 ) );

    // Check if the request failed
    if ( is_wp_error( $response ) ) {
        // Graceful degradation: fall back to the last known good status so a
        // transient Connect-endpoint outage doesn't silently hide the gateway
        // at checkout for an already-connected merchant.
        $lastgood = get_option( $lastgood_key, false );
        $rpsfw_stripe_connection_status = is_array( $lastgood ) ? $lastgood : false;
        return $rpsfw_stripe_connection_status;
    }

    $account = ! empty( $response['body'] ) ? json_decode( $response['body'], true ) : array();

    if ( ! empty( $account['payouts_enabled'] ) && intval( $account['payouts_enabled'] ) === 1 ) {
        $rpsfw_stripe_connection_status = array(
            'email'           => isset( $account['email'] ) ? $account['email'] : '',
            'display_name'    => isset( $account['display_name'] ) ? $account['display_name'] : '',
            'mode'            => $mode,
            'account_id'      => $account_id,
            'token'           => $token,
            'publishable_key' => isset( $account['publishable_key'] ) ? $account['publishable_key'] : $publishable_key,
        );
        // Cache for short-term reuse and persist as last-known-good for
        // outage fallback.
        set_transient( $cache_key, $rpsfw_stripe_connection_status, 5 * MINUTE_IN_SECONDS );
        update_option( $lastgood_key, $rpsfw_stripe_connection_status, false );
    } else {
        // Server explicitly reports the account is not connected / not
        // payouts-enabled. Clear any stale caches so the gateway hides.
        delete_transient( $cache_key );
        delete_option( $lastgood_key );
    }

    return $rpsfw_stripe_connection_status;
}

/**
 * Clear the cached Stripe connection status (both modes). Called whenever the
 * connection changes (connect completion / disconnect) so the next check
 * re-queries the Connect endpoint.
 */
function rpsfw_stripe_clear_connection_cache() {
    global $rpsfw_stripe_connection_status;
    $rpsfw_stripe_connection_status = null;
    foreach ( array( 'sandbox', 'live' ) as $mode ) {
        delete_transient( 'rpsfw_stripe_conn_' . $mode );
        delete_option( 'rpsfw_stripe_conn_lastgood_' . $mode );
    }
}

/**
 * Get Stripe Connect URL
 *
 * @param string|false $mode Mode to connect in (sandbox or live). False to use current setting.
 * @return string
 */
function rpsfw_stripe_connect_url( $mode = false ) {
    if ( $mode === false ) {
        $options = get_option( 'woocommerce_rpsfw_stripe_settings', array() );
        $testmode = isset( $options['testmode'] ) && $options['testmode'] === 'yes';
        
        if ( $testmode ) {
            $mode = 'sandbox';
        } else {
            $mode = 'live';
        }
    }

    $stripe_connect_url = RPSFW_STRIPE_CONNECT_ENDPOINT . '?' . http_build_query(
        array(
            'action'     => 'connect',
            'mode'       => $mode,
            'return_url' => rpsfw_stripe_connect_tab_url(),
        )
    );

    return $stripe_connect_url;
}

/**
 * Get Stripe disconnect URL
 *
 * @param string $account_id Account ID
 * @param string $token Token
 * @return string
 */
function rpsfw_stripe_disconnect_url( $account_id, $token ) {
    $options = get_option( 'woocommerce_rpsfw_stripe_settings', array() );
    $testmode = isset( $options['testmode'] ) && $options['testmode'] === 'yes';

    $stripe_connect_url = RPSFW_STRIPE_CONNECT_ENDPOINT . '?' . http_build_query(
        array(
            'action'     => 'disconnect',
            'mode'       => $testmode ? 'sandbox' : 'live',
            'return_url' => rpsfw_stripe_connect_tab_url(),
            'account_id' => $account_id,
            'token'      => $token,
        )
    );

    return $stripe_connect_url;
}

/**
 * Get Stripe Connect tab URL
 *
 * @return string
 */
function rpsfw_stripe_connect_tab_url() {
    return add_query_arg(
        array(
            'page'    => 'wc-settings',
            'tab'     => 'checkout',
            'section' => 'rpsfw_stripe',
            'rpsfw_stripe_nonce' => wp_create_nonce( 'rpsfw_stripe_connect' ),
        ),
        admin_url( 'admin.php' )
    );
}

/**
 * Handle Stripe Connect completion
 */
add_action( 'admin_init', 'rpsfw_stripe_connect_completion' );
function rpsfw_stripe_connect_completion() {
    // Check for the completion parameter
    if ( empty( $_GET['rpsfw_stripe_connect_completion'] ) || intval( $_GET['rpsfw_stripe_connect_completion'] ) !== 1 ) {
        return;
    }
    
    if ( empty( $_GET['mode'] ) || empty( $_GET['account_id'] ) || empty( $_GET['token'] ) ) {
        return;
    }

    // Nonce check
    if ( ! isset( $_GET['rpsfw_stripe_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['rpsfw_stripe_nonce'] ) ), 'rpsfw_stripe_connect' ) ) {
        wp_die( esc_html__( 'Security check failed', 'restore-paypal-standard-for-woocommerce' ) );
    }

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }

    $options = get_option( 'woocommerce_rpsfw_stripe_settings', array() );
    
    // Sanitize mode parameter - only allow 'live' or 'sandbox'
    $mode = isset( $_GET['mode'] ) ? sanitize_text_field( $_GET['mode'] ) : '';
    
    if ( $mode === 'live' ) {
        $account_id_key = 'acct_id_live';
        $stripe_connect_token_key = 'stripe_connect_token_live';
        $publishable_key_key = 'publishable_key_live';
        $options['testmode'] = 'no';
    } else {
        $account_id_key = 'acct_id_test';
        $stripe_connect_token_key = 'stripe_connect_token_test';
        $publishable_key_key = 'publishable_key_test';
        $options['testmode'] = 'yes';
    }

    $options[$account_id_key] = sanitize_text_field( $_GET['account_id'] );
    $options[$stripe_connect_token_key] = sanitize_text_field( $_GET['token'] );
    
    // Store platform publishable key if provided
    if ( ! empty( $_GET['platform_publishable_key'] ) ) {
        $options[$publishable_key_key] = sanitize_text_field( $_GET['platform_publishable_key'] );
    }
    
    // Store access token (secret key) if provided
    if ( ! empty( $_GET['access_token'] ) ) {
        $secret_key_key = $mode === 'live' ? 'secret_key_live' : 'secret_key_test';
        $options[$secret_key_key] = sanitize_text_field( $_GET['access_token'] );
    }

    // Store the relay's per-merchant shared secret so the merchant
    // site can verify forwarded webhook events. Sent by the Connect
    // server as webhook_shared_secret in the redirect URL when the
    // server registers the merchant for relay forwarding.
    if ( ! empty( $_GET['webhook_shared_secret'] ) ) {
        $relay_secret_key = $mode === 'live' ? 'relay_shared_secret_live' : 'relay_shared_secret_test';
        $options[ $relay_secret_key ] = sanitize_text_field( $_GET['webhook_shared_secret'] );
        // Also clear any old direct-from-Stripe webhook secret so the
        // verifier picks the relay path.
        $direct_secret_key = $mode === 'live' ? 'webhook_secret_live' : 'webhook_secret_test';
        $options[ $direct_secret_key ] = '';
    }

    update_option( 'woocommerce_rpsfw_stripe_settings', $options );

    // Clear the connection status cache and the cached Stripe API client so
    // the next call uses the fresh secret key.
    global $rpsfw_stripe_connection_status;
    $rpsfw_stripe_connection_status = null;
    if ( function_exists( 'rpsfw_stripe_clear_connection_cache' ) ) {
        rpsfw_stripe_clear_connection_cache();
    }
    if ( class_exists( 'RPSFW_Stripe_API' ) ) {
        RPSFW_Stripe_API::reset_client();
    }

    // Auto-register this site's domain on the just-connected account so wallet
    // payment methods (Stripe Link now; Apple Pay / Google Pay later) render in
    // the Payment Element without the merchant registering the domain by hand.
    // Runs with the connected account's own access token (stored above), so it
    // registers on the merchant account. Non-fatal on failure.
    if ( class_exists( 'RPSFW_Stripe_API' ) && method_exists( 'RPSFW_Stripe_API', 'register_payment_method_domain' ) ) {
        if ( class_exists( 'RPSFW_Gateway_Stripe' ) ) {
            RPSFW_Gateway_Stripe::log( 'Connect completion (' . $mode . '): registering payment method domain for wallets.' );
        }
        $pmd_result = RPSFW_Stripe_API::register_payment_method_domain();
        if ( class_exists( 'RPSFW_Gateway_Stripe' ) ) {
            RPSFW_Gateway_Stripe::log( 'Connect completion (' . $mode . '): domain registration result: '
                . ( is_wp_error( $pmd_result ) ? 'ERROR - ' . $pmd_result->get_error_message() : 'OK' ) );
        }
        // Mark the flag so the settings-page backfill doesn't repeat it.
        if ( ! is_wp_error( $pmd_result ) ) {
            $options[ 'rpsfw_stripe_pmd_registered_' . $mode ] = wp_parse_url( home_url(), PHP_URL_HOST );
            update_option( 'woocommerce_rpsfw_stripe_settings', $options );
        }
    }

    // Webhook delivery happens via the wpplugin Connect server's
    // forwarding relay (it hosts a Connect-level webhook on the
    // platform's Stripe account and forwards each merchant's events
    // to that merchant's site). The shared secret used to sign the
    // forwarded requests is captured above from the redirect URL.
    // We do not auto-create a per-merchant webhook on the connected
    // account here.

    // Redirect to clean URL
    $return_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=rpsfw_stripe' );
    wp_safe_redirect( $return_url );
    exit;
}

/**
 * Ensure the site's payment method domain is registered for the connected
 * account, for merchants who connected BEFORE auto-registration existed.
 *
 * Runs only on the Stripe settings screen (low frequency) and only once per
 * mode after success (guarded by an option flag), so it never hammers the API.
 * On failure it simply retries the next time the merchant views the settings.
 */
add_action( 'admin_init', 'rpsfw_stripe_ensure_domain_registered' );
function rpsfw_stripe_ensure_domain_registered() {
    if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }
    // Only on our Stripe settings section.
    $page    = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
    $section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
    if ( 'wc-settings' !== $page || 'rpsfw_stripe' !== $section ) {
        return;
    }

    $options  = get_option( 'woocommerce_rpsfw_stripe_settings', array() );
    $testmode = isset( $options['testmode'] ) && 'yes' === $options['testmode'];
    $mode     = $testmode ? 'test' : 'live';
    $flag_key = 'rpsfw_stripe_pmd_registered_' . $mode;

    // Only attempt when connected in this mode (a secret key/token is present).
    $secret = $testmode
        ? ( isset( $options['secret_key_test'] ) ? $options['secret_key_test'] : '' )
        : ( isset( $options['secret_key_live'] ) ? $options['secret_key_live'] : '' );
    if ( empty( $secret ) || ! class_exists( 'RPSFW_Stripe_API' ) || ! method_exists( 'RPSFW_Stripe_API', 'register_payment_method_domain' ) ) {
        if ( class_exists( 'RPSFW_Gateway_Stripe' ) ) {
            RPSFW_Gateway_Stripe::log( 'ensure_domain_registered (' . $mode . '): not connected in this mode (no secret key); skipping.' );
        }
        return;
    }

    // Always run the (idempotent) registration when viewing the settings page.
    // This is admin-only and low-frequency, so re-verifying each load is cheap
    // and — importantly — re-logs the current per-wallet activation status
    // (Apple Pay / Google Pay / Link) even after the domain was first
    // registered. That status is the key signal for why wallet buttons may not
    // render at checkout, so we surface it here rather than skipping on a flag.
    if ( class_exists( 'RPSFW_Gateway_Stripe' ) ) {
        RPSFW_Gateway_Stripe::log( 'ensure_domain_registered (' . $mode . '): verifying payment method domain registration.' );
    }
    $result = RPSFW_Stripe_API::register_payment_method_domain();
    $status_key = 'rpsfw_stripe_pmd_status_' . $mode;
    if ( ! is_wp_error( $result ) ) {
        $options[ $flag_key ]   = wp_parse_url( home_url(), PHP_URL_HOST );
        // Cache the per-wallet activation status so the settings screen can
        // show the merchant exactly which wallets are active for their domain
        // (the domain can be registered while a given wallet stays inactive).
        $options[ $status_key ] = array(
            'apple_pay'  => isset( $result->apple_pay->status ) ? $result->apple_pay->status : 'n/a',
            'google_pay' => isset( $result->google_pay->status ) ? $result->google_pay->status : 'n/a',
            'link'       => isset( $result->link->status ) ? $result->link->status : 'n/a',
            'domain'     => isset( $result->domain_name ) ? $result->domain_name : '',
            'error'      => '',
        );
        update_option( 'woocommerce_rpsfw_stripe_settings', $options );
    } else {
        $options[ $status_key ] = array( 'error' => $result->get_error_message() );
        update_option( 'woocommerce_rpsfw_stripe_settings', $options );
        if ( class_exists( 'RPSFW_Gateway_Stripe' ) ) {
            RPSFW_Gateway_Stripe::log( 'ensure_domain_registered (' . $mode . '): failed - ' . $result->get_error_message(), 'warning' );
        }
    }
}

/**
 * Show the Apple Pay / Google Pay activation status for the connected account's
 * domain on the Stripe settings screen. This makes the (otherwise invisible)
 * reason a wallet button doesn't render obvious: the domain can be registered
 * while a specific wallet is still "inactive" on the connected account.
 */
add_action( 'admin_notices', 'rpsfw_stripe_wallet_status_notice' );
function rpsfw_stripe_wallet_status_notice() {
    // DISABLED FOR THIS RELEASE — Apple Pay / Google Pay are not shipping yet,
    // so this wallet-activation notice is suppressed. Remove this early return
    // to re-enable it alongside the wallets.
    return;

    if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }
    $page    = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
    $section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
    if ( 'wc-settings' !== $page || 'rpsfw_stripe' !== $section ) {
        return;
    }

    $options  = get_option( 'woocommerce_rpsfw_stripe_settings', array() );
    $testmode = isset( $options['testmode'] ) && 'yes' === $options['testmode'];
    $mode     = $testmode ? 'test' : 'live';

    $apple_on  = 'yes' === ( isset( $options['enable_apple_pay'] ) ? $options['enable_apple_pay'] : 'no' );
    $google_on = 'yes' === ( isset( $options['enable_google_pay'] ) ? $options['enable_google_pay'] : 'no' );
    if ( ! $apple_on && ! $google_on ) {
        return; // No wallets enabled — nothing to report.
    }

    $status = isset( $options[ 'rpsfw_stripe_pmd_status_' . $mode ] ) ? $options[ 'rpsfw_stripe_pmd_status_' . $mode ] : null;
    if ( empty( $status ) ) {
        return;
    }

    if ( ! empty( $status['error'] ) ) {
        echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Stripe wallets:', 'restore-paypal-standard-for-woocommerce' ) . '</strong> '
            . esc_html__( 'We could not automatically register your checkout domain with Stripe, so Apple Pay / Google Pay can\'t appear yet. The plugin retries automatically; if this persists, reconnecting your Stripe account usually resolves it. Details: ', 'restore-paypal-standard-for-woocommerce' )
            . esc_html( $status['error'] ) . '</p></div>';
        return;
    }

    $issues = array();
    if ( $apple_on && isset( $status['apple_pay'] ) && 'active' !== $status['apple_pay'] ) {
        $issues[] = 'Apple Pay (' . $status['apple_pay'] . ')';
    }
    if ( $google_on && isset( $status['google_pay'] ) && 'active' !== $status['google_pay'] ) {
        $issues[] = 'Google Pay (' . $status['google_pay'] . ')';
    }

    if ( ! empty( $issues ) ) {
        // Informational only — no Stripe Dashboard action is expected from the
        // merchant. Activation is automatic (domain register + validate); a
        // wallet can be briefly inactive right after connecting, or if the
        // connected account's region doesn't support it.
        echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Stripe wallets activating:', 'restore-paypal-standard-for-woocommerce' ) . '</strong> '
            . sprintf(
                /* translators: 1: comma-separated wallet list, 2: mode */
                esc_html__( '%1$s not active for your domain yet (%2$s mode). This is handled automatically — it can take a short time right after connecting, and the plugin re-checks on each visit to this page. No Stripe Dashboard changes are needed. If a wallet stays inactive, your Stripe account or region may not support it.', 'restore-paypal-standard-for-woocommerce' ),
                esc_html( implode( ', ', $issues ) ),
                esc_html( $mode )
            )
            . '</p></div>';
    } else {
        echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'Stripe wallets active:', 'restore-paypal-standard-for-woocommerce' ) . '</strong> '
            . esc_html__( 'Apple Pay / Google Pay are active for your domain. If a button still does not appear, it is browser-specific (e.g. Google Pay needs Chrome; Apple Pay needs Safari with a card in Wallet).', 'restore-paypal-standard-for-woocommerce' )
            . '</p></div>';
    }
}

/**
 * Handle Stripe disconnect
 */
add_action( 'admin_init', 'rpsfw_stripe_disconnected' );
function rpsfw_stripe_disconnected() {
    // The endpoint uses rpsfw prefix for the disconnect parameter
    if ( empty( $_GET['rpsfw_stripe_disconnected'] ) || intval( $_GET['rpsfw_stripe_disconnected'] ) !== 1 ) {
        return;
    }

    if ( empty( $_GET['mode'] ) || empty( $_GET['account_id'] ) ) {
        return;
    }

    // Nonce check
    if ( ! isset( $_GET['rpsfw_stripe_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['rpsfw_stripe_nonce'] ) ), 'rpsfw_stripe_connect' ) ) {
        wp_die( esc_html__( 'Security check failed', 'restore-paypal-standard-for-woocommerce' ) );
    }

    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }

    $options = get_option( 'woocommerce_rpsfw_stripe_settings', array() );
    
    // Sanitize mode and account_id parameters
    $mode = isset( $_GET['mode'] ) ? sanitize_text_field( $_GET['mode'] ) : '';
    $account_id = isset( $_GET['account_id'] ) ? sanitize_text_field( $_GET['account_id'] ) : '';
    
    // Normalize mode - 'sandbox' should be treated as test mode
    if ( $mode === 'sandbox' ) {
        $mode = 'test';
    }
    
    $acct_id_key = $mode === 'live' ? 'acct_id_live' : 'acct_id_test';
    
    if ( isset( $options[$acct_id_key] ) && $options[$acct_id_key] === $account_id ) {
        // We don't try to delete a webhook on the connected account
        // because we never created one (Stripe Connect Standard does
        // not permit the platform to manage webhooks on connected
        // accounts). The merchant configured their own webhook in
        // their Stripe dashboard; they can delete it there if they
        // want. We only clear the locally stored signing secret + id.

        // Clear account ID
        $options[$acct_id_key] = '';
        
        // Clear connect token
        $token_key = $mode === 'live' ? 'stripe_connect_token_live' : 'stripe_connect_token_test';
        $options[$token_key] = '';
        
        // Clear secret key (API key)
        $secret_key = $mode === 'live' ? 'secret_key_live' : 'secret_key_test';
        $options[$secret_key] = '';
        
        // Clear publishable key
        $publishable_key = $mode === 'live' ? 'publishable_key_live' : 'publishable_key_test';
        $options[$publishable_key] = '';

        // Clear webhook fields too. The webhook itself was just deleted on
        // Stripe (or the API call silently failed), so the stored id and
        // secret are no longer valid either way.
        $webhook_id_key = $mode === 'live' ? 'webhook_id_live' : 'webhook_id_test';
        $webhook_secret_key = $mode === 'live' ? 'webhook_secret_live' : 'webhook_secret_test';
        $relay_secret_key = $mode === 'live' ? 'relay_shared_secret_live' : 'relay_shared_secret_test';
        $options[ $webhook_id_key ] = '';
        $options[ $webhook_secret_key ] = '';
        $options[ $relay_secret_key ] = '';

        // Clear the payment-method-domain registration flag so reconnecting
        // re-registers the domain on the (possibly different) account.
        $pmd_flag_key = 'rpsfw_stripe_pmd_registered_' . $mode;
        $options[ $pmd_flag_key ] = '';

        update_option( 'woocommerce_rpsfw_stripe_settings', $options );
    }

    // Clear the connection status cache and the cached Stripe API client.
    global $rpsfw_stripe_connection_status;
    $rpsfw_stripe_connection_status = null;
    if ( function_exists( 'rpsfw_stripe_clear_connection_cache' ) ) {
        rpsfw_stripe_clear_connection_cache();
    }
    if ( class_exists( 'RPSFW_Stripe_API' ) ) {
        RPSFW_Stripe_API::reset_client();
    }

    // Redirect to clean URL
    $return_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=rpsfw_stripe' );
    wp_safe_redirect( $return_url );
    exit;
}

/**
 * Try to create a Stripe webhook for the given mode using the merchant's
 * Connect access token. Idempotent: if a signing secret is already
 * stored for this mode, this is a no-op.
 *
 * Stripe rejects this call on some Connect platforms ("not permitted to
 * configure webhook endpoints on a connected account"). When that
 * happens we just log it; the gateway settings page has a manual
 * paste-secret fallback.
 *
 * @param string $mode 'test' or 'live'.
 */
function rpsfw_stripe_auto_create_webhook( $mode ) {
    if ( ! class_exists( 'RPSFW_Stripe_API' ) ) {
        return;
    }

    $options            = get_option( 'woocommerce_rpsfw_stripe_settings', array() );
    $webhook_id_key     = $mode === 'live' ? 'webhook_id_live' : 'webhook_id_test';
    $webhook_secret_key = $mode === 'live' ? 'webhook_secret_live' : 'webhook_secret_test';

    if ( ! empty( $options[ $webhook_secret_key ] ) ) {
        return;
    }

    $webhook_url = home_url( '/wc-api/rpsfw_stripe_webhook/' );
    $webhook     = RPSFW_Stripe_API::create_webhook( $webhook_url );

    if ( is_wp_error( $webhook ) ) {
        if ( class_exists( 'RPSFW_Gateway_Stripe' ) ) {
            RPSFW_Gateway_Stripe::log( 'Auto-create webhook failed: ' . $webhook->get_error_message(), 'warning' );
        }
        return;
    }

    if ( empty( $webhook->id ) || empty( $webhook->secret ) ) {
        return;
    }

    $options[ $webhook_id_key ]     = $webhook->id;
    $options[ $webhook_secret_key ] = $webhook->secret;
    update_option( 'woocommerce_rpsfw_stripe_settings', $options );

    if ( class_exists( 'RPSFW_Gateway_Stripe' ) ) {
        RPSFW_Gateway_Stripe::log( 'Auto-created webhook for ' . $mode . ' mode. ID: ' . $webhook->id );
    }
}
