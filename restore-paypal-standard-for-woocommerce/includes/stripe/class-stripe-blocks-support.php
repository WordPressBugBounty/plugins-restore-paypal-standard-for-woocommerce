<?php
/**
 * WooCommerce Blocks Payment Method Integration for Stripe
 *
 * @package RestorePayPalStandard
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * Stripe payment method integration for WooCommerce Blocks
 */
final class RPSFW_Gateway_Stripe_Blocks_Support extends AbstractPaymentMethodType {
    /**
     * Payment method name
     *
     * @var string
     */
    protected $name = 'rpsfw_stripe';

    /**
     * Gateway instance
     */
    private $gateway;

    /**
     * Initializes the payment method type.
     */
    public function initialize() {
        $this->settings = get_option( 'woocommerce_rpsfw_stripe_settings', array() );
        
        $payment_gateways = WC()->payment_gateways()->payment_gateways();
        $this->gateway = isset( $payment_gateways['rpsfw_stripe'] ) ? $payment_gateways['rpsfw_stripe'] : null;
    }

    /**
     * Returns if this payment method should be active.
     *
     * @return boolean
     */
    public function is_active() {
        if ( ! $this->gateway ) {
            return false;
        }
        
        if ( 'yes' !== $this->gateway->enabled ) {
            return false;
        }
        
        return $this->gateway->is_available();
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        $script_asset_path = RPSFW_PLUGIN_DIR . 'assets/js/blocks/stripe-blocks.asset.php';
        $script_asset      = file_exists( $script_asset_path )
            ? require $script_asset_path
            : array(
                'dependencies' => array(),
                'version'      => RPSFW_VERSION,
            );
        $script_url        = RPSFW_PLUGIN_URL . 'assets/js/blocks/stripe-blocks.js';
        $script_path       = RPSFW_PLUGIN_DIR . 'assets/js/blocks/stripe-blocks.js';

        // Version the script by its file modification time so edits always
        // bust the browser/WordPress cache (the static asset version doesn't
        // change when we edit the JS directly).
        $script_version = file_exists( $script_path ) ? (string) filemtime( $script_path ) : $script_asset['version'];

        // Register Stripe.js
        if ( ! wp_script_is( 'stripe-js', 'registered' ) ) {
            wp_register_script(
                'stripe-js',
                'https://js.stripe.com/v3/',
                array(),
                '3.0',
                true
            );
        }

        wp_register_script(
            'wc-rpsfw-stripe-blocks',
            $script_url,
            array_merge( $script_asset['dependencies'], array( 'stripe-js' ) ),
            $script_version,
            true
        );

        return array( 'wc-rpsfw-stripe-blocks' );
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data() {
        if ( ! $this->gateway ) {
            return array();
        }

        $options = get_option( 'woocommerce_rpsfw_stripe_settings', array() );
        $testmode = isset( $options['testmode'] ) && $options['testmode'] === 'yes';
        $account_id_key = $testmode ? 'acct_id_test' : 'acct_id_live';
        $account_id = isset( $options[$account_id_key] ) ? $options[$account_id_key] : '';

        $data = array(
            'title'           => $this->gateway->get_title(),
            'description'     => $this->gateway->get_description(),
            'supports'        => array_filter( $this->gateway->supports, array( $this->gateway, 'supports' ) ),
            'publishableKey'  => RPSFW_Stripe_API::get_publishable_key(),
            'accountId'       => $account_id,
            'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
            'createIntentNonce' => wp_create_nonce( 'rpsfw-stripe-create-intent' ),
            // Deferred (order-first) flow: finalize an order after the customer
            // confirms the payment once the block has created the order.
            'finalizeNonce'   => wp_create_nonce( 'rpsfw-stripe-finalize-order' ),
            // Subscription support for block checkout. When the cart contains a
            // subscription, the block JS must call the dedicated
            // rpsfw_stripe_create_subscription endpoint instead of the one-off
            // create-payment-intent endpoint (which rejects subscription carts).
            'isSubscription'  => ( function_exists( 'wcs_is_subscription' )
                                        || ( function_exists( 'rpsfw_native_subscriptions_active' ) && rpsfw_native_subscriptions_active() ) )
                                    && class_exists( 'RPSFW_Stripe_Subscriptions' )
                                    && RPSFW_Stripe_Subscriptions::cart_contains_subscription(),
            'createSubscriptionNonce' => wp_create_nonce( 'rpsfw-stripe-create-subscription' ),
            // Custom Place Order button text for native subscription carts
            // (block checkout reads this from the payment method config).
            'placeOrderButtonLabel' => $this->get_subscription_button_label(),
            'appearance'      => $this->gateway->get_appearance_options(),
            // Express Checkout wallets (Apple Pay / Google Pay) shown inside the
            // Payment Element, and whether Link is enabled (controls passing the
            // customer email for Link authentication).
            'walletsConfig'   => method_exists( $this->gateway, 'get_wallets_config' ) ? $this->gateway->get_wallets_config() : array( 'applePay' => 'never', 'googlePay' => 'never' ),
            'linkEnabled'     => 'yes' === $this->gateway->get_option( 'enable_link', 'yes' ),
            // DISABLED FOR THIS RELEASE — Apple Pay / Google Pay express buttons
            // are not shipping yet. Force off. Restore the wallet-driven value
            // to re-enable:
            // ( 'yes' === $this->gateway->get_option( 'enable_apple_pay', 'no' ) || 'yes' === $this->gateway->get_option( 'enable_google_pay', 'no' ) )
            'expressCheckoutEnabled' => false,
            'showTitle'       => 'yes' === $this->gateway->get_option( 'show_title', 'yes' ),
            'showDescription' => 'yes' === $this->gateway->get_option( 'show_description', 'yes' ),
            'testMode'        => $testmode,
            'testModeMessage' => $testmode ? $this->gateway->test_mode_message : '',
            'locale'          => ( 'site' === $this->gateway->get_option( 'locale', 'auto' ) )
                                    ? str_replace( '_', '-', get_locale() )
                                    : 'auto',
            'loadingText'     => $this->gateway->get_option( 'loading_text', __( 'Loading payment form...', 'restore-paypal-standard-for-woocommerce' ) ),
        );
        
        // Only include icon if setting is enabled
        if ( 'yes' === $this->gateway->get_option( 'show_icon', 'yes' ) ) {
            $data['iconUrl'] = plugins_url( 'assets/images/stripe-logo.png', RPSFW_PLUGIN_FILE );
        }

        // Subscription details panel (test mode only). Mirrors the classic
        // checkout panel so the "Due today / Recurring" summary also appears on
        // block checkout for subscription carts automatically in test mode.
        if ( $testmode
            && class_exists( 'RPSFW_Stripe_Subscriptions' )
            && RPSFW_Stripe_Subscriptions::cart_contains_subscription()
            && method_exists( $this->gateway, 'get_debug_totals_data' )
        ) {
            $totals = $this->gateway->get_debug_totals_data();
            if ( $totals ) {
                // Build a formatted recurring line per schedule (mixed-interval
                // carts have more than one).
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

                $data['debugTotals'] = array(
                    'dueToday'        => wc_price( $totals['today'], array( 'currency' => $totals['currency'] ) ),
                    'recurringLines'  => $recurring_lines,
                );
            }
        }

        return $data;
    }

    /**
     * The Place Order button label for native subscription carts, or ''
     * when the default label should be used (non-subscription carts, WCS
     * mode, or no custom text configured).
     *
     * @return string
     */
    private function get_subscription_button_label() {
        if ( ! function_exists( 'rpsfw_native_subscriptions_active' )
            || ! rpsfw_native_subscriptions_active()
            || ! class_exists( 'RPSFW_Subscriptions_Cart' )
            || ! RPSFW_Subscriptions_Cart::cart_contains_subscription() ) {
            return '';
        }
        $custom = rpsfw_subscriptions_get_setting( 'place_order_button_text' );
        return $custom ? __( $custom, 'restore-paypal-standard-for-woocommerce' ) : ''; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
    }
}
