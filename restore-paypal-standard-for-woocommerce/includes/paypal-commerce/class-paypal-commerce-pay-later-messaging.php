<?php
/**
 * PayPal Commerce Pay Later Messaging.
 *
 * Handles displaying Pay Later messaging on various pages.
 *
 * @class       WC_PayPal_Commerce_Pay_Later_Messaging
 * @version     1.0.0
 * @package     RestorePayPalStandard
 */

defined( 'ABSPATH' ) || exit;

/**
 * WC_PayPal_Commerce_Pay_Later_Messaging Class.
 */
class WC_PayPal_Commerce_Pay_Later_Messaging {

    /**
     * Gateway instance.
     *
     * @var WC_Gateway_PayPal_Commerce
     */
    private $gateway;

    /**
     * Message configurations to pass to JavaScript.
     *
     * @var array
     */
    private $message_configs = array();

    /**
     * Constructor.
     */
    public function __construct() {
        // Product page hooks - multiple positions
        add_action( 'woocommerce_single_product_summary', array( $this, 'render_product_above_price' ), 8 );
        add_action( 'woocommerce_single_product_summary', array( $this, 'render_product_below_price' ), 15 );
        add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'render_product_below_add_to_cart' ), 5 );
        
        // Cart page hooks - multiple positions
        add_action( 'woocommerce_cart_totals_after_order_total', array( $this, 'render_cart_below_total' ) );
        add_action( 'woocommerce_proceed_to_checkout', array( $this, 'render_cart_above_buttons' ), 5 );
        
        // Checkout page hooks - multiple positions
        add_action( 'woocommerce_review_order_after_order_total', array( $this, 'render_checkout_below_total' ) );
        add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_checkout_above_buttons' ), 5 );
        
        // Shop/Category page hooks - multiple positions
        add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'render_shop_below_price' ), 20 );
        add_action( 'woocommerce_after_shop_loop_item', array( $this, 'render_shop_below_add_to_cart' ), 20 );
        
        // Mini cart hooks
        add_action( 'woocommerce_widget_shopping_cart_buttons', array( $this, 'render_minicart_above_buttons' ), 5 );
        add_action( 'woocommerce_widget_shopping_cart_buttons', array( $this, 'render_minicart_below_buttons' ), 30 );
        
        // Enqueue scripts
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ), 20 );
        
        // Shortcode support
        add_shortcode( 'paypal_pay_later_message', array( $this, 'shortcode_handler' ) );
    }

    /**
     * Get gateway instance.
     *
     * @return WC_Gateway_PayPal_Commerce|null
     */
    private function get_gateway() {
        if ( $this->gateway ) {
            return $this->gateway;
        }

        if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
            return null;
        }

        $gateways = WC()->payment_gateways()->payment_gateways();
        if ( isset( $gateways['rpsfw_paypal_commerce'] ) ) {
            $this->gateway = $gateways['rpsfw_paypal_commerce'];
        }

        return $this->gateway;
    }

    /**
     * Check if Pay Later messaging is enabled globally.
     *
     * @return bool
     */
    private function is_messaging_enabled() {
        $gateway = $this->get_gateway();
        
        if ( ! $gateway ) {
            return false;
        }

        if ( 'yes' !== $gateway->get_option( 'enabled', 'no' ) ) {
            return false;
        }

        if ( ! $gateway->is_connected() ) {
            return false;
        }

        if ( 'yes' !== $gateway->get_option( 'paylater_messaging_enabled', 'no' ) ) {
            return false;
        }

        return true;
    }

    /**
     * Check if messaging is enabled for a specific location.
     *
     * @param string $location Location identifier.
     * @return bool
     */
    private function is_location_enabled( $location ) {
        if ( ! $this->is_messaging_enabled() ) {
            return false;
        }

        $gateway = $this->get_gateway();
        return 'yes' === $gateway->get_option( 'paylater_messaging_' . $location, 'no' );
    }

    /**
     * Get the configured position for a location.
     *
     * @param string $location Location identifier.
     * @return string
     */
    private function get_location_position( $location ) {
        $gateway = $this->get_gateway();
        if ( ! $gateway ) {
            return '';
        }

        $defaults = array(
            'product'  => 'below_price',
            'cart'     => 'below_total',
            'checkout' => 'below_total',
            'shop'     => 'below_price',
            'minicart' => 'above_buttons',
        );

        $default = isset( $defaults[ $location ] ) ? $defaults[ $location ] : '';
        return $gateway->get_option( 'paylater_messaging_' . $location . '_location', $default );
    }

    /**
     * Get messaging style configuration for a location.
     *
     * @param string $location Location identifier.
     * @return array
     */
    private function get_style_config( $location ) {
        $gateway = $this->get_gateway();
        
        if ( ! $gateway ) {
            return array();
        }

        $prefix = 'paylater_messaging_' . $location . '_';
        $layout = $gateway->get_option( $prefix . 'layout', 'text' );
        
        $style = array(
            'layout' => $layout,
            'logo'   => array(
                'type'     => $gateway->get_option( $prefix . 'logo_type', 'primary' ),
                'position' => $gateway->get_option( $prefix . 'logo_position', 'left' ),
            ),
            'text'   => array(
                'color' => $gateway->get_option( $prefix . 'text_color', 'black' ),
                'size'  => $gateway->get_option( $prefix . 'text_size', '12' ),
            ),
            'color'  => $gateway->get_option( $prefix . 'flex_color', 'blue' ),
            'ratio'  => $gateway->get_option( $prefix . 'flex_ratio', '8x1' ),
        );

        if ( $layout === 'text' ) {
            unset( $style['color'] );
            unset( $style['ratio'] );
        } else {
            unset( $style['text'] );
            unset( $style['logo'] );
        }

        return $style;
    }

    // =========================================================================
    // PRODUCT PAGE RENDERING
    // =========================================================================

    /**
     * Render Pay Later message above product price.
     */
    public function render_product_above_price() {
        if ( ! $this->is_location_enabled( 'product' ) ) {
            return;
        }
        if ( $this->get_location_position( 'product' ) !== 'above_price' ) {
            return;
        }
        $this->render_product_message();
    }

    /**
     * Render Pay Later message below product price.
     */
    public function render_product_below_price() {
        if ( ! $this->is_location_enabled( 'product' ) ) {
            return;
        }
        if ( $this->get_location_position( 'product' ) !== 'below_price' ) {
            return;
        }
        $this->render_product_message();
    }

    /**
     * Render Pay Later message below add to cart button.
     */
    public function render_product_below_add_to_cart() {
        if ( ! $this->is_location_enabled( 'product' ) ) {
            return;
        }
        if ( $this->get_location_position( 'product' ) !== 'below_add_to_cart' ) {
            return;
        }
        $this->render_product_message();
    }

    /**
     * Render Pay Later message on product page.
     */
    private function render_product_message() {
        global $product;
        if ( ! $product ) {
            return;
        }

        $amount = $product->get_price();
        if ( empty( $amount ) || $amount <= 0 ) {
            return;
        }

        $this->output_message_container( 'product', $amount );
    }

    // =========================================================================
    // CART PAGE RENDERING
    // =========================================================================

    /**
     * Render Pay Later message below cart total.
     */
    public function render_cart_below_total() {
        if ( ! $this->is_location_enabled( 'cart' ) ) {
            return;
        }
        if ( $this->get_location_position( 'cart' ) !== 'below_total' ) {
            return;
        }

        if ( ! WC()->cart ) {
            return;
        }

        $amount = WC()->cart->get_total( 'edit' );
        if ( empty( $amount ) || $amount <= 0 ) {
            return;
        }

        echo '<tr class="rpsfw-paylater-message-row"><td colspan="2">';
        $this->output_message_container( 'cart', $amount );
        echo '</td></tr>';
    }

    /**
     * Render Pay Later message above proceed to checkout button.
     */
    public function render_cart_above_buttons() {
        if ( ! $this->is_location_enabled( 'cart' ) ) {
            return;
        }
        if ( $this->get_location_position( 'cart' ) !== 'above_buttons' ) {
            return;
        }

        if ( ! WC()->cart ) {
            return;
        }

        $amount = WC()->cart->get_total( 'edit' );
        if ( empty( $amount ) || $amount <= 0 ) {
            return;
        }

        $this->output_message_container( 'cart', $amount, 'buttons' );
    }

    // =========================================================================
    // CHECKOUT PAGE RENDERING
    // =========================================================================

    /**
     * Render Pay Later message below order total.
     */
    public function render_checkout_below_total() {
        if ( ! $this->is_location_enabled( 'checkout' ) ) {
            return;
        }
        if ( $this->get_location_position( 'checkout' ) !== 'below_total' ) {
            return;
        }

        if ( ! WC()->cart ) {
            return;
        }

        $amount = WC()->cart->get_total( 'edit' );
        if ( empty( $amount ) || $amount <= 0 ) {
            return;
        }

        echo '<tr class="rpsfw-paylater-message-row"><td colspan="2">';
        $this->output_message_container( 'checkout', $amount );
        echo '</td></tr>';
    }

    /**
     * Render Pay Later message above payment buttons.
     */
    public function render_checkout_above_buttons() {
        if ( ! $this->is_location_enabled( 'checkout' ) ) {
            return;
        }
        if ( $this->get_location_position( 'checkout' ) !== 'above_buttons' ) {
            return;
        }

        if ( ! WC()->cart ) {
            return;
        }

        $amount = WC()->cart->get_total( 'edit' );
        if ( empty( $amount ) || $amount <= 0 ) {
            return;
        }

        $this->output_message_container( 'checkout', $amount, 'buttons' );
    }

    // =========================================================================
    // SHOP/CATEGORY PAGE RENDERING
    // =========================================================================

    /**
     * Render Pay Later message below product price on shop pages.
     */
    public function render_shop_below_price() {
        if ( ! $this->is_location_enabled( 'shop' ) ) {
            return;
        }
        if ( $this->get_location_position( 'shop' ) !== 'below_price' ) {
            return;
        }
        $this->render_shop_message();
    }

    /**
     * Render Pay Later message below add to cart on shop pages.
     */
    public function render_shop_below_add_to_cart() {
        if ( ! $this->is_location_enabled( 'shop' ) ) {
            return;
        }
        if ( $this->get_location_position( 'shop' ) !== 'below_add_to_cart' ) {
            return;
        }
        $this->render_shop_message();
    }

    /**
     * Render Pay Later message on shop/category pages.
     */
    private function render_shop_message() {
        global $product;
        if ( ! $product ) {
            return;
        }

        $amount = $product->get_price();
        if ( empty( $amount ) || $amount <= 0 ) {
            return;
        }

        $this->output_message_container( 'shop', $amount, 'shop-' . $product->get_id() );
    }

    // =========================================================================
    // MINI CART RENDERING
    // =========================================================================

    /**
     * Render Pay Later message above mini cart buttons.
     */
    public function render_minicart_above_buttons() {
        if ( ! $this->is_location_enabled( 'minicart' ) ) {
            return;
        }
        if ( $this->get_location_position( 'minicart' ) !== 'above_buttons' ) {
            return;
        }
        $this->render_minicart_message();
    }

    /**
     * Render Pay Later message below mini cart buttons.
     */
    public function render_minicart_below_buttons() {
        if ( ! $this->is_location_enabled( 'minicart' ) ) {
            return;
        }
        if ( $this->get_location_position( 'minicart' ) !== 'below_buttons' ) {
            return;
        }
        $this->render_minicart_message();
    }

    /**
     * Render Pay Later message in mini cart.
     * 
     * Mini cart is loaded via AJAX, so we output the config inline with the container.
     */
    private function render_minicart_message() {
        if ( ! WC()->cart ) {
            return;
        }

        $amount = WC()->cart->get_total( 'edit' );
        if ( empty( $amount ) || $amount <= 0 ) {
            return;
        }

        $container_id = 'rpsfw-paylater-message-minicart';
        $config = array(
            'amount'    => (float) $amount,
            'currency'  => get_woocommerce_currency(),
            'style'     => $this->get_style_config( 'minicart' ),
            'placement' => 'cart',
            'location'  => 'minicart',
        );

        // Output container with inline config for AJAX-loaded mini cart
        echo '<div id="' . esc_attr( $container_id ) . '" class="rpsfw-paylater-message rpsfw-paylater-minicart" data-paylater-config="' . esc_attr( wp_json_encode( $config ) ) . '"></div>';
        
        // Also store in our configs array for non-AJAX scenarios
        $this->message_configs[ $container_id ] = $config;
    }

    // =========================================================================
    // CONTAINER OUTPUT
    // =========================================================================

    /**
     * Output message container HTML.
     *
     * IMPORTANT: PayPal SDK Auto-Rendering Behavior
     * ---------------------------------------------
     * The PayPal SDK automatically detects and renders Pay Later messages to any element
     * that has a `data-pp-amount` attribute. This auto-rendering happens immediately when
     * the SDK loads, BEFORE any custom JavaScript can run, and uses default styles
     * (layout: text) regardless of what other attributes are present.
     *
     * Once PayPal auto-renders a message, it caches the instance per container and ignores
     * subsequent render() calls with different style configurations.
     *
     * To prevent this, we output EMPTY containers with NO data-pp-* attributes.
     * All configuration (amount, style, placement) is passed via wp_localize_script()
     * to a JavaScript object. This approach:
     * - Prevents PayPal SDK auto-detection and auto-rendering
     * - Avoids potential issues with HTML minifiers mangling data attributes
     * - Matches the pattern used by other major PayPal plugins
     * - Gives our JavaScript full control over when and how messages render
     *
     * The JavaScript reads config from the rpsfwPayLaterMessages global object
     * and renders messages with the correct styles after the SDK loads.
     *
     * @param string $location Location identifier.
     * @param float  $amount   Amount to display.
     * @param string $suffix   Optional suffix for container ID.
     */
    private function output_message_container( $location, $amount, $suffix = '' ) {
        $container_id = 'rpsfw-paylater-message-' . $location;
        if ( $suffix ) {
            $container_id .= '-' . $suffix;
        }

        $this->add_message_config( $container_id, $location, $amount );

        // Emit the per-container config inline as a data attribute. The JS
        // also reads from the localized rpsfwPayLaterMessages global, but
        // wp_localize_script captures the configs by value at the time it
        // is called (which happens during enqueue, before WC's render
        // hooks fire), so the global ends up with an empty messages map.
        // Inlining the config alongside the container guarantees the data
        // is available to the JS regardless of order.
        $config_json = wp_json_encode( $this->message_configs[ $container_id ] );

        echo '<div id="' . esc_attr( $container_id ) . '" class="rpsfw-paylater-message" data-paylater-config="' . esc_attr( $config_json ) . '"></div>';
    }

    /**
     * Add message configuration for a container.
     *
     * @param string $container_id Container element ID.
     * @param string $location     Location identifier.
     * @param float  $amount       Amount to display.
     */
    private function add_message_config( $container_id, $location, $amount ) {
        $this->message_configs[ $container_id ] = array(
            'amount'    => (float) $amount,
            'currency'  => get_woocommerce_currency(),
            'style'     => $this->get_style_config( $location ),
            'placement' => $this->get_placement( $location ),
            'location'  => $location,
        );
    }

    /**
     * Get placement value for PayPal Messages API.
     *
     * @param string $location Location identifier.
     * @return string
     */
    private function get_placement( $location ) {
        $placements = array(
            'product'  => 'product',
            'cart'     => 'cart',
            'checkout' => 'payment',
            'shop'     => 'category',
            'minicart' => 'cart',
        );

        return isset( $placements[ $location ] ) ? $placements[ $location ] : 'product';
    }

    /**
     * Get all message configurations.
     *
     * @return array
     */
    public function get_message_configs() {
        return $this->message_configs;
    }


    // =========================================================================
    // SHORTCODE
    // =========================================================================

    /**
     * Shortcode handler for Pay Later messaging.
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public function shortcode_handler( $atts ) {
        if ( ! $this->is_messaging_enabled() ) {
            return '';
        }

        $atts = shortcode_atts( array(
            'amount'        => '',
            'layout'        => 'text',
            'logo_type'     => 'primary',
            'logo_position' => 'left',
            'text_color'    => 'black',
            'text_size'     => '12',
            'text_align'    => 'left',
            'flex_color'    => 'blue',
            'flex_ratio'    => '8x1',
        ), $atts, 'paypal_pay_later_message' );

        if ( empty( $atts['amount'] ) ) {
            global $product;
            if ( $product ) {
                $atts['amount'] = $product->get_price();
            } elseif ( WC()->cart ) {
                $atts['amount'] = WC()->cart->get_total( 'edit' );
            }
        }

        if ( empty( $atts['amount'] ) || $atts['amount'] <= 0 ) {
            return '';
        }

        $style = array( 'layout' => $atts['layout'] );
        
        if ( $atts['layout'] === 'text' ) {
            $style['logo'] = array(
                'type'     => $atts['logo_type'],
                'position' => $atts['logo_position'],
            );
            $style['text'] = array(
                'color' => $atts['text_color'],
                'size'  => $atts['text_size'],
                'align' => $atts['text_align'],
            );
        } else {
            $style['color'] = $atts['flex_color'];
            $style['ratio'] = $atts['flex_ratio'];
        }

        $container_id = 'rpsfw-paylater-shortcode-' . wp_rand( 1000, 9999 );

        $this->message_configs[ $container_id ] = array(
            'amount'    => (float) $atts['amount'],
            'currency'  => get_woocommerce_currency(),
            'style'     => $style,
            'placement' => 'product',
            'location'  => 'shortcode',
        );

        return '<div id="' . esc_attr( $container_id ) . '" class="rpsfw-paylater-message rpsfw-paylater-shortcode"></div>';
    }

    // =========================================================================
    // SCRIPTS & STYLES
    // =========================================================================

    /**
     * Enqueue Pay Later messaging scripts.
     */
    public function enqueue_scripts() {
        if ( ! $this->is_messaging_enabled() ) {
            return;
        }

        $should_load = false;
        $needs_sdk = false;
        
        // Product page
        if ( is_product() && $this->is_location_enabled( 'product' ) ) {
            $should_load = true;
            $needs_sdk = true;
        }
        
        // Shop/category pages
        if ( ( is_shop() || is_product_category() || is_product_tag() ) && $this->is_location_enabled( 'shop' ) ) {
            $should_load = true;
            $needs_sdk = true;
        }
        
        // Cart page - SDK loaded by cart buttons
        if ( is_cart() && $this->is_location_enabled( 'cart' ) ) {
            $should_load = true;
            $needs_sdk = false;
        }
        
        // Checkout page - SDK loaded by checkout scripts
        if ( is_checkout() && $this->is_location_enabled( 'checkout' ) ) {
            $should_load = true;
            $needs_sdk = false;
        }

        // Mini cart - always load if enabled (appears on any page)
        if ( $this->is_location_enabled( 'minicart' ) ) {
            $should_load = true;
            // Only need SDK if not on cart/checkout
            if ( ! is_cart() && ! is_checkout() ) {
                $needs_sdk = true;
            }
        }
        
        // Shortcode support
        global $post;
        if ( $post && has_shortcode( $post->post_content, 'paypal_pay_later_message' ) ) {
            $should_load = true;
            if ( ! is_cart() && ! is_checkout() ) {
                $needs_sdk = true;
            }
        }

        if ( ! $should_load ) {
            return;
        }

        if ( $needs_sdk ) {
            $this->enqueue_paypal_sdk();
        }
        
        $dependencies = array( 'jquery' );
        if ( $needs_sdk && wp_script_is( 'rpsfw-paypal-sdk', 'registered' ) ) {
            $dependencies[] = 'rpsfw-paypal-sdk';
        }
        
        // Version by file modification time so edits always bust the
        // browser/WordPress cache (RPSFW_VERSION alone doesn't change when we
        // edit the JS directly, so cached copies would otherwise persist).
        $messaging_js_path = RPSFW_PLUGIN_DIR . 'assets/js/paypal-paylater-messaging.js';
        $messaging_js_ver  = file_exists( $messaging_js_path ) ? (string) filemtime( $messaging_js_path ) : RPSFW_VERSION;

        wp_enqueue_script(
            'rpsfw-paypal-paylater-messaging',
            RPSFW_PLUGIN_URL . 'assets/js/paypal-paylater-messaging.js',
            $dependencies,
            $messaging_js_ver,
            true
        );

        wp_localize_script(
            'rpsfw-paypal-paylater-messaging',
            'rpsfwPayLaterMessages',
            array(
                'currency' => get_woocommerce_currency(),
                'messages' => $this->get_message_configs(),
            )
        );

        $this->add_inline_styles();
    }

    /**
     * Enqueue PayPal SDK for messaging only.
     */
    private function enqueue_paypal_sdk() {
        $gateway = $this->get_gateway();
        if ( ! $gateway ) {
            return;
        }

        if ( wp_script_is( 'rpsfw-paypal-sdk', 'registered' ) ) {
            return;
        }

        $env = $gateway->testmode ? 'sandbox' : 'live';
        $onboarding = $gateway->get_option( 'ppcp_onboarding', array() );

        if ( empty( $onboarding[ $env ]['seller_id'] ) ) {
            return;
        }

        $client_id = $gateway->api->get_client_id( $env, $onboarding[ $env ] );

        if ( empty( $client_id ) ) {
            return;
        }

        $sdk_args = array(
            'client-id'      => $client_id,
            'components'     => 'messages',
            'currency'       => get_woocommerce_currency(),
            'enable-funding' => 'paylater',
        );

        $sdk_url = add_query_arg( $sdk_args, 'https://www.paypal.com/sdk/js' );

        wp_register_script( 'rpsfw-paypal-sdk', $sdk_url, array(), null, true );
        wp_enqueue_script( 'rpsfw-paypal-sdk' );
    }

    /**
     * Add inline styles for Pay Later messaging.
     */
    private function add_inline_styles() {
        $css = '
            .rpsfw-paylater-message {
                margin: 10px 0;
                min-height: 20px;
            }
            .rpsfw-paylater-message-row .rpsfw-paylater-message {
                margin: 5px 0;
            }
            .rpsfw-paylater-message.rpsfw-paylater-shortcode {
                display: inline-block;
            }
            .single-product .rpsfw-paylater-message {
                margin: 15px 0;
            }
            .woocommerce-loop-product__title + .rpsfw-paylater-message {
                margin: 5px 0 10px;
                font-size: 12px;
            }
            .woocommerce-cart .rpsfw-paylater-message {
                margin: 15px 0;
            }
            .woocommerce-checkout .rpsfw-paylater-message {
                margin: 10px 0;
            }
            .widget_shopping_cart .rpsfw-paylater-message {
                margin: 10px 0;
                text-align: center;
            }
        ';
        
        wp_add_inline_style( 'woocommerce-general', $css );
    }
}

// Initialize Pay Later messaging
global $rpsfw_paylater_messaging;
$rpsfw_paylater_messaging = new WC_PayPal_Commerce_Pay_Later_Messaging();
