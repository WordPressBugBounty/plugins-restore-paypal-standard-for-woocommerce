<?php
/**
 * WooCommerce Blocks Payment Method Integration for PayPal Commerce
 *
 * @package Restore_PayPal_Standard_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * PayPal Commerce payment method integration for WooCommerce Blocks
 */
final class WC_Gateway_PayPal_Commerce_Blocks_Support extends AbstractPaymentMethodType {
    /**
     * Payment method name defined by payment methods extending this class.
     *
     * @var string
     */
    protected $name = 'rpsfw_paypal_commerce';

    /**
     * Gateway instance
     */
    private $gateway;

    /**
     * Initializes the payment method type.
     */
    public function initialize() {
        $this->settings = get_option( 'woocommerce_rpsfw_paypal_commerce_settings', array() );
        
        $payment_gateways = WC()->payment_gateways()->payment_gateways();
        $this->gateway = isset( $payment_gateways['rpsfw_paypal_commerce'] ) ? $payment_gateways['rpsfw_paypal_commerce'] : null;
    }

    /**
     * Returns if this payment method should be active. If false, the scripts will not be enqueued.
     *
     * @return boolean
     */
    public function is_active() {
        if ( ! $this->gateway ) {
            return false;
        }
        
        // Check if the gateway itself is enabled in settings
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
        $script_path       = '/assets/js/blocks/paypal-commerce-blocks.js';
        $script_asset_path = RPSFW_PLUGIN_DIR . 'assets/js/blocks/paypal-commerce-blocks.asset.php';
        $script_asset      = file_exists( $script_asset_path )
            ? require $script_asset_path
            : array(
                'dependencies' => array(),
                'version'      => RPSFW_VERSION,
            );
        $script_url        = RPSFW_PLUGIN_URL . 'assets/js/blocks/paypal-commerce-blocks.js';

        // Bust the browser cache whenever the script file changes.
        $script_file = RPSFW_PLUGIN_DIR . 'assets/js/blocks/paypal-commerce-blocks.js';
        if ( file_exists( $script_file ) ) {
            $script_asset['version'] = filemtime( $script_file );
        }

        // Get PayPal client ID
        $client_id = '';
        if ( $this->gateway ) {
            $env = $this->gateway->testmode ? 'sandbox' : 'live';
            $onboarding = $this->gateway->get_option( 'ppcp_onboarding', array() );
            if ( ! empty( $onboarding[ $env ]['seller_id'] ) ) {
                $client_id = $this->gateway->api->get_client_id( $env, $onboarding[ $env ] );
            }
        }

        // Register PayPal SDK if we have a client ID and it's not already registered
        if ( $client_id && ! wp_script_is( 'rpsfw-paypal-sdk', 'registered' ) ) {
            // Build SDK URL parameters
            $sdk_args = array(
                'client-id'  => $client_id,
                'currency'   => get_woocommerce_currency(),
                'intent'     => 'capture',
            );

            // ALWAYS include both buttons and messages components for checkout
            // This ensures both PayPal buttons and Pay Later messaging work regardless of settings
            $sdk_args['components'] = 'buttons,messages';

            // Sandbox only: eligibility for Venmo / Pay Later is driven by the
            // buyer-country parameter (PayPal ignores the real IP/locale in
            // sandbox). Use the WooCommerce store base country. Never sent live.
            if ( $this->gateway->testmode && function_exists( 'WC' ) && WC()->countries ) {
                $base_country = WC()->countries->get_base_country();
                if ( $base_country ) {
                    $sdk_args['buyer-country'] = $base_country;
                }
            }

            // We render each funding source explicitly (per fundingSource) in
            // the blocks JS and gate it with isEligible(), so we do NOT build a
            // disable-funding list. Disabling 'paypal' here can make the
            // unbranded Card button ineligible. Hiding is handled by which
            // sources the JS chooses to render.

            // enable-funding makes the non-default enabled sources eligible.
            $enable_funding = array();
            if ( 'yes' === $this->gateway->get_option( 'enable_venmo', 'no' ) ) {
                $enable_funding[] = 'venmo';
            }
            if ( 'yes' === $this->gateway->get_option( 'enable_paylater', 'no' ) ) {
                $enable_funding[] = 'paylater';
            }
            
            if ( ! empty( $enable_funding ) ) {
                $sdk_args['enable-funding'] = implode( ',', $enable_funding );
            }

            /**
             * Allow extensions (e.g. WC Subscriptions integration) to flip
             * the SDK into subscription mode for the blocks checkout.
             */
            $sdk_args = apply_filters( 'rpsfw_ppcp_sdk_args', $sdk_args );

            $sdk_url = add_query_arg( $sdk_args, 'https://www.paypal.com/sdk/js' );

            // Use consistent handle across all files: rpsfw-paypal-sdk
            wp_register_script(
                'rpsfw-paypal-sdk',
                $sdk_url,
                array(),
                null,
                true
            );
        }

        wp_register_script(
            'wc-paypal-commerce-blocks',
            $script_url,
            array_merge( $script_asset['dependencies'], $client_id ? array( 'rpsfw-paypal-sdk' ) : array() ),
            $script_asset['version'],
            true
        );

        return array( 'wc-paypal-commerce-blocks' );
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

        $data = array(
            'title'       => $this->gateway->get_title(),
            'description' => $this->gateway->get_description(),
            'supports'    => array_filter( $this->gateway->supports, array( $this->gateway, 'supports' ) ),
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'createOrderNonce' => wp_create_nonce( 'rpsfw-ppcp-create-order' ),
            // Subscription support for the block checkout. When the cart
            // contains a subscription (native product type OR a subscription
            // plan), the shared rpsfw_ppcp_sdk_args filter loads the SDK with
            // intent=subscription, so the block button MUST use
            // createSubscription instead of createOrder.
            'isSubscription' => ( function_exists( 'wcs_is_subscription' )
                                    || ( function_exists( 'rpsfw_native_subscriptions_active' ) && rpsfw_native_subscriptions_active() ) )
                                && WC_PayPal_Commerce_Subscriptions::cart_contains_subscription(),
            'createSubscriptionNonce' => wp_create_nonce( 'rpsfw-ppcp-create-subscription' ),
            // Custom Place Order button text for native subscription carts
            // (block checkout reads this from the payment method config).
            'placeOrderButtonLabel' => $this->get_subscription_button_label(),
            'paypalButtonErrorText' => $this->gateway->get_option( 'paypal_button_error_text', __( 'Please click the PayPal button and complete payment first.', 'restore-paypal-standard-for-woocommerce' ) ),
            'showTitle'   => 'yes' === $this->gateway->get_option( 'show_title', 'yes' ),
            'showDescription' => 'yes' === $this->gateway->get_option( 'show_description', 'yes' ),
            'funding'     => $this->gateway->get_funding_display_settings(),
        );
        
        // Only include icon if setting is enabled
        if ( 'yes' === $this->gateway->get_option( 'show_icon', 'yes' ) ) {
            $custom_icon = trim( (string) $this->gateway->get_option( 'custom_icon_url', '' ) );
            $data['iconUrl'] = ( $custom_icon !== '' && filter_var( $custom_icon, FILTER_VALIDATE_URL ) )
                ? $custom_icon
                : plugins_url( 'assets/images/paypal-logo.svg', RPSFW_PLUGIN_FILE );
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
