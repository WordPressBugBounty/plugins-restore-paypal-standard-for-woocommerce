<?php
/**
 * WooCommerce Blocks Payment Method Integration
 *
 * @package Restore_PayPal_Standard_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * PayPal Standard payment method integration for WooCommerce Blocks
 */
final class WC_Gateway_PayPal_Standard_Blocks_Support extends AbstractPaymentMethodType {
    /**
     * Payment method name defined by payment methods extending this class.
     *
     * @var string
     */
    protected $name = 'restore_paypal_standard';

    /**
     * Initializes the payment method type.
     */
    public function initialize() {
        $this->settings = get_option( 'woocommerce_restore_paypal_standard_settings', array() );
    }

    /**
     * Returns if this payment method should be active. If false, the scripts will not be enqueued.
     *
     * @return boolean
     */
    public function is_active() {
        // Check if native PayPal is enabled - if so, don't show our gateway
        // Clear cache to get fresh settings
        wp_cache_delete('woocommerce_restore_paypal_standard_settings', 'options');
        $settings = get_option('woocommerce_restore_paypal_standard_settings', array());
        $enable_native_paypal = isset($settings['enable_native_paypal']) && $settings['enable_native_paypal'] === 'yes';
        
        if ($enable_native_paypal) {
            return false;
        }
        
        $payment_gateways_class = WC()->payment_gateways();
        $payment_gateways       = $payment_gateways_class->payment_gateways();

        return isset( $payment_gateways['restore_paypal_standard'] ) && $payment_gateways['restore_paypal_standard']->is_available();
    }

    /**
     * Returns an array of scripts/handles to be registered for this payment method.
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        $script_path       = '/assets/js/blocks/paypal-standard-blocks.js';
        $script_asset_path = RPSFW_PLUGIN_DIR . 'assets/js/blocks/paypal-standard-blocks.asset.php';
        $script_asset      = file_exists( $script_asset_path )
            ? require $script_asset_path
            : array(
                'dependencies' => array(),
                'version'      => RPSFW_VERSION,
            );
        $script_url        = RPSFW_PLUGIN_URL . 'assets/js/blocks/paypal-standard-blocks.js';

        wp_register_script(
            'wc-restore-paypal-standard-blocks',
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        return array( 'wc-restore-paypal-standard-blocks' );
    }

    /**
     * Returns an array of key=>value pairs of data made available to the payment methods script.
     *
     * @return array
     */
    public function get_payment_method_data() {
        $payment_gateways_class = WC()->payment_gateways();
        $payment_gateways       = $payment_gateways_class->payment_gateways();
        $gateway                = isset( $payment_gateways['restore_paypal_standard'] ) ? $payment_gateways['restore_paypal_standard'] : null;

        if ( ! $gateway ) {
            return array();
        }

        // For blocks checkout, use the PayPal logo
        $icon_url = plugins_url( 'assets/images/paypal-logo.png', RPSFW_PLUGIN_FILE );

        return array(
            'title'       => $gateway->get_title(),
            'description' => $gateway->get_description(),
            'supports'    => array_filter( $gateway->supports, array( $gateway, 'supports' ) ),
            'iconUrl'     => $icon_url,
        );
    }
}
