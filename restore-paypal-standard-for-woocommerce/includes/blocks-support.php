<?php
/**
 * WooCommerce Blocks Support
 *
 * @package Restore_PayPal_Standard_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the payment methods with WooCommerce Blocks
 */
function rpsfw_register_payment_method_blocks_support() {
    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        return;
    }

    // Load PayPal Standard blocks support
    require_once __DIR__ . '/paypal-standard/class-wc-gateway-paypal-standard-blocks-support.php';
    
    // Load PayPal Commerce blocks support
    require_once __DIR__ . '/paypal-commerce/class-gateway-paypal-commerce-blocks-support.php';
    
    // Load Stripe blocks support
    require_once __DIR__ . '/stripe/class-stripe-blocks-support.php';

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function( $payment_method_registry ) {
            // Register PayPal Standard
            $payment_method_registry->register( new WC_Gateway_PayPal_Standard_Blocks_Support() );
            
            // Register PayPal Commerce
            $payment_method_registry->register( new WC_Gateway_PayPal_Commerce_Blocks_Support() );
            
            // Register Stripe
            $payment_method_registry->register( new RPSFW_Gateway_Stripe_Blocks_Support() );
        }
    );
}
add_action( 'woocommerce_blocks_loaded', 'rpsfw_register_payment_method_blocks_support' );

/**
 * Register Pay Later messaging for WooCommerce Blocks.
 */
function rpsfw_register_paylater_blocks_support() {
    // Only load if blocks are available
    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Package' ) ) {
        return;
    }

    add_action( 'woocommerce_blocks_enqueue_cart_block_scripts_after', 'rpsfw_enqueue_paylater_blocks_scripts' );
    add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_after', 'rpsfw_enqueue_paylater_blocks_scripts' );
    
    // For mini cart, we need to load on frontend globally
    add_action( 'wp_enqueue_scripts', 'rpsfw_maybe_enqueue_paylater_minicart_blocks', 20 );
}
add_action( 'woocommerce_blocks_loaded', 'rpsfw_register_paylater_blocks_support' );

/**
 * Maybe enqueue Pay Later blocks for mini cart on all frontend pages.
 */
function rpsfw_maybe_enqueue_paylater_minicart_blocks() {
    // Don't load in admin
    if ( is_admin() ) {
        return;
    }

    // Get gateway settings
    if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
        return;
    }

    $gateways = WC()->payment_gateways()->payment_gateways();
    if ( ! isset( $gateways['rpsfw_paypal_commerce'] ) ) {
        return;
    }

    $gateway = $gateways['rpsfw_paypal_commerce'];

    // Check if Pay Later messaging is enabled for mini cart
    if ( 'yes' !== $gateway->get_option( 'paylater_messaging_enabled', 'no' ) ) {
        return;
    }

    if ( 'yes' !== $gateway->get_option( 'paylater_messaging_minicart', 'no' ) ) {
        return;
    }

    if ( ! $gateway->is_connected() ) {
        return;
    }

    // Enqueue the blocks script for mini cart
    rpsfw_enqueue_paylater_blocks_scripts();
}

/**
 * Enqueue Pay Later blocks scripts and pass settings.
 */
function rpsfw_enqueue_paylater_blocks_scripts() {
    static $enqueued = false;
    if ( $enqueued ) {
        return;
    }

    // Get gateway settings
    $gateways = WC()->payment_gateways()->payment_gateways();
    if ( ! isset( $gateways['rpsfw_paypal_commerce'] ) ) {
        return;
    }

    $gateway = $gateways['rpsfw_paypal_commerce'];

    // Check if Pay Later messaging is enabled
    if ( 'yes' !== $gateway->get_option( 'paylater_messaging_enabled', 'no' ) ) {
        return;
    }

    if ( ! $gateway->is_connected() ) {
        return;
    }

    // Build settings for blocks
    $settings = array(
        'enabled'  => true,
        'currency' => get_woocommerce_currency(),
        'cart'     => array(
            'enabled' => 'yes' === $gateway->get_option( 'paylater_messaging_cart', 'no' ),
            'style'   => rpsfw_get_paylater_style_config( $gateway, 'cart' ),
        ),
        'checkout' => array(
            'enabled' => 'yes' === $gateway->get_option( 'paylater_messaging_checkout', 'no' ),
            'style'   => rpsfw_get_paylater_style_config( $gateway, 'checkout' ),
        ),
        'minicart' => array(
            'enabled'  => 'yes' === $gateway->get_option( 'paylater_messaging_minicart', 'no' ),
            'style'    => rpsfw_get_paylater_style_config( $gateway, 'minicart' ),
            'location' => $gateway->get_option( 'paylater_messaging_minicart_location', 'above_buttons' ),
        ),
    );

    // Only enqueue if at least one location is enabled
    if ( ! $settings['cart']['enabled'] && ! $settings['checkout']['enabled'] && ! $settings['minicart']['enabled'] ) {
        return;
    }

    // Register settings with WooCommerce Blocks
    if ( function_exists( 'wc_get_container' ) ) {
        try {
            $data_registry = wc_get_container()->get( \Automattic\WooCommerce\Blocks\Assets\AssetDataRegistry::class );
            if ( $data_registry && ! $data_registry->exists( 'rpsfw_paylater_data' ) ) {
                $data_registry->add( 'rpsfw_paylater_data', $settings );
            }
        } catch ( Exception $e ) {
            // Container not available, use fallback
        }
    }

    // Enqueue the blocks script
    $asset_file = RPSFW_PLUGIN_DIR . 'assets/js/blocks/paypal-paylater-blocks.asset.php';
    $asset = file_exists( $asset_file ) ? require $asset_file : array( 'dependencies' => array(), 'version' => RPSFW_VERSION );

    // Version by file modification time so edits always bust the
    // browser/WordPress cache (the asset.php version is a static string that
    // doesn't change when the JS is edited directly).
    $script_path    = RPSFW_PLUGIN_DIR . 'assets/js/blocks/paypal-paylater-blocks.js';
    $script_version = file_exists( $script_path ) ? (string) filemtime( $script_path ) : $asset['version'];

    wp_enqueue_script(
        'rpsfw-paylater-blocks',
        RPSFW_PLUGIN_URL . 'assets/js/blocks/paypal-paylater-blocks.js',
        $asset['dependencies'],
        $script_version,
        true
    );

    $enqueued = true;
}

/**
 * Get Pay Later style configuration for a location.
 *
 * @param WC_Gateway_PayPal_Commerce $gateway  Gateway instance.
 * @param string                     $location Location identifier.
 * @return array
 */
function rpsfw_get_paylater_style_config( $gateway, $location ) {
    $prefix = 'paylater_messaging_' . $location . '_';
    $layout = $gateway->get_option( $prefix . 'layout', 'text' );
    
    $style = array(
        'layout' => $layout,
    );

    if ( $layout === 'text' ) {
        $style['logo'] = array(
            'type'     => $gateway->get_option( $prefix . 'logo_type', 'primary' ),
            'position' => $gateway->get_option( $prefix . 'logo_position', 'left' ),
        );
        $style['text'] = array(
            'color' => $gateway->get_option( $prefix . 'text_color', 'black' ),
            'size'  => $gateway->get_option( $prefix . 'text_size', '12' ),
        );
    } else {
        $style['color'] = $gateway->get_option( $prefix . 'flex_color', 'blue' );
        $style['ratio'] = $gateway->get_option( $prefix . 'flex_ratio', '8x1' );
    }

    return $style;
}

