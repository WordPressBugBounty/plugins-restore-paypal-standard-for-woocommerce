<?php
/**
 * PayPal Commerce Platform Payment Gateway.
 *
 * Provides a PayPal Commerce Platform Payment Gateway for WooCommerce.
 *
 * @class       WC_Gateway_PayPal_Commerce
 * @extends     WC_Payment_Gateway
 * @version     1.0.0
 * @package     RestorePayPalStandard
 */

defined( 'ABSPATH' ) || exit;

// Load API handler
require_once dirname( __FILE__ ) . '/class-paypal-commerce-api.php';

// Load Webhooks handler
require_once dirname( __FILE__ ) . '/class-paypal-commerce-webhooks.php';

// Load WooCommerce Subscriptions integration (no-ops until WC Subs is active)
require_once dirname( __FILE__ ) . '/class-paypal-commerce-subscriptions.php';

// Load the shared refund panel base + PayPal refund panel (admin only).
require_once dirname( __FILE__ ) . '/../class-rpsfw-refund-panel.php';
require_once dirname( __FILE__ ) . '/class-paypal-commerce-refund-panel.php';

/**
 * WC_Gateway_PayPal_Commerce Class.
 */
class WC_Gateway_PayPal_Commerce extends WC_Payment_Gateway {

    /**
     * Whether or not logging is enabled
     *
     * @var bool
     */
    public static $log_enabled = false;

    /**
     * Logger instance
     *
     * @var WC_Logger
     */
    public static $log = false;

    /**
     * Test mode flag
     *
     * @var bool
     */
    public $testmode;

    /**
     * Debug mode flag
     *
     * @var bool
     */
    public $debug;

    /**
     * API handler instance
     *
     * @var WC_PayPal_Commerce_API
     */
    public $api;

    /**
     * Webhooks handler instance
     *
     * @var WC_PayPal_Commerce_Webhooks
     */
    public $webhooks;

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        $this->id                 = 'rpsfw_paypal_commerce';
        $this->has_fields         = true;
        $this->method_title       = __( 'PayPal Commerce Platform', 'restore-paypal-standard-for-woocommerce' );
        $this->method_description = __( 'Accept payments via PayPal Commerce Platform with modern checkout experience.', 'restore-paypal-standard-for-woocommerce' );
        $this->supports           = array(
            'products',
            'refunds',
            'captures',
        );

        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title       = $this->get_option( 'title', __( 'PayPal', 'restore-paypal-standard-for-woocommerce' ) );
        $this->description = $this->get_option( 'description', __( 'Pay securely with PayPal.', 'restore-paypal-standard-for-woocommerce' ) );
        $this->testmode    = 'yes' === $this->get_option( 'testmode', 'yes' );
        $this->debug       = 'yes' === $this->get_option( 'debug', 'no' );

        // Logging follows the "Debug Log" setting on the Debugging tab.
        self::$log_enabled = $this->debug;

        // Set icon
        $this->icon = RPSFW_PLUGIN_URL . 'assets/images/paypal-logo.svg';

        // Initialize API handler
        $this->api = new WC_PayPal_Commerce_API();

        // Initialize Webhooks handler
        $this->webhooks = new WC_PayPal_Commerce_Webhooks( $this );

        // Actions
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
        
        // Enqueue frontend styles and scripts for classic checkout
        add_action( 'wp_enqueue_scripts', array( $this, 'frontend_scripts' ) );
        
        // Handle return from PayPal onboarding
        add_action( 'admin_init', array( $this, 'handle_onboarding_return' ) );

        // Warn (native WP admin notice) when PayPal is connected but no webhook
        // is configured for that environment, so events like refunds, disputes
        // and authorization voids won't be received.
        add_action( 'admin_notices', array( $this, 'maybe_show_missing_webhook_notice' ) );

        // Allow the webhook notice to be persistently dismissed via AJAX.
        add_action( 'wp_ajax_rpsfw_dismiss_webhook_notice', array( $this, 'ajax_dismiss_webhook_notice' ) );
        
        // Add order action for capturing authorized payments
        add_action( 'woocommerce_order_actions', array( $this, 'add_capture_order_action' ) );
        add_action( 'woocommerce_order_action_rpsfw_paypal_commerce_capture_payment', array( $this, 'process_capture_order_action' ) );

        // Conditionally enable WooCommerce Subscriptions integration.
        WC_PayPal_Commerce_Subscriptions::maybe_init( $this );

        // Per-payment refund panel (order + subscription screens) with an
        // optional "Cancel subscription" checkbox. Registered once per request.
        if ( is_admin() && ! did_action( 'rpsfw_ppcp_refund_panel_init' ) ) {
            RPSFW_PayPal_Commerce_Refund_Panel::init( $this );
            do_action( 'rpsfw_ppcp_refund_panel_init' );
        }
    }

    /**
     * Get the gateway icon with proper sizing.
     *
     * @return string
     */
    public function get_icon() {
        // Check if icon should be shown
        if ( 'no' === $this->get_option( 'show_icon', 'yes' ) ) {
            return '';
        }

        // Honour a custom icon URL if the merchant entered one in settings.
        $custom_icon = trim( (string) $this->get_option( 'custom_icon_url', '' ) );
        if ( $custom_icon !== '' && filter_var( $custom_icon, FILTER_VALIDATE_URL ) ) {
            $icon_url = $custom_icon;
        } else {
            $icon_url = RPSFW_PLUGIN_URL . 'assets/images/paypal-logo.svg';
        }

        $icon_html = '<img src="' . esc_url( $icon_url ) . '" alt="' . esc_attr( $this->get_title() ) . '" class="rpsfw-paypal-commerce-icon" style="max-height: 28px; width: auto; vertical-align: middle;" />';

        return apply_filters( 'woocommerce_gateway_icon', $icon_html, $this->id );
    }

    /**
     * Get the gateway title
     *
     * @return string
     */
    public function get_title() {
        // Check if title should be shown
        if ( 'no' === $this->get_option( 'show_title', 'yes' ) ) {
            return '';
        }
        
        return parent::get_title();
    }

    /**
     * Load frontend scripts and styles.
     */
    public function frontend_scripts() {
        // Only load on checkout page
        if ( ! is_checkout() || ! $this->is_available() ) {
            return;
        }

        // Get PayPal client ID from backend
        $env = $this->testmode ? 'sandbox' : 'live';
        $onboarding = $this->get_option( 'ppcp_onboarding', array() );

        if ( empty( $onboarding[ $env ]['seller_id'] ) ) {
            return;
        }

        // Get client ID from backend
        $client_id = $this->api->get_client_id( $env, $onboarding[ $env ] );

        if ( empty( $client_id ) ) {
            self::log( 'Failed to get PayPal client ID', 'error' );
            return;
        }

        // Get payment action setting
        $payment_action = $this->get_option( 'payment_action', 'capture' );
        $intent = ( $payment_action === 'authorize' ) ? 'authorize' : 'capture';

        // Build SDK URL parameters
        $sdk_args = array(
            'client-id' => $client_id,
            'currency'  => get_woocommerce_currency(),
            'intent'    => $intent,
        );

        // ALWAYS include both buttons and messages components for checkout
        // This ensures both PayPal buttons and Pay Later messaging work regardless of settings
        $sdk_args['components'] = 'buttons,messages';

        // Sandbox only: PayPal ignores the buyer's real IP/locale in sandbox,
        // so funding eligibility (Venmo, Pay Later) is driven by the
        // buyer-country parameter. Use the WooCommerce store base country so
        // these buttons can be tested. Live ignores buyer-country and uses the
        // real buyer's country, so it is never sent in live mode.
        if ( $this->testmode && function_exists( 'WC' ) && WC()->countries ) {
            $base_country = WC()->countries->get_base_country();
            if ( $base_country ) {
                $sdk_args['buyer-country'] = $base_country;
            }
        }

        // We render each funding source explicitly (per fundingSource) in JS
        // and gate it with isEligible(), so we deliberately do NOT build a
        // disable-funding list. Disabling a source here — especially
        // 'paypal' — can make other sources ineligible, notably the unbranded
        // "Debit or Credit Card" button. Hiding is handled by which sources
        // the JS chooses to render.

        // enable-funding still makes the non-default sources the merchant has
        // turned on eligible for rendering.
        $enable_funding = array();
        if ( 'yes' === $this->get_option( 'enable_venmo', 'no' ) ) {
            $enable_funding[] = 'venmo';
        }
        if ( 'yes' === $this->get_option( 'enable_paylater', 'no' ) ) {
            $enable_funding[] = 'paylater';
        }
        
        if ( ! empty( $enable_funding ) ) {
            $sdk_args['enable-funding'] = implode( ',', $enable_funding );
        }

        /**
         * Allow extensions (e.g. WC Subscriptions integration) to flip the
         * SDK to subscription mode when needed.
         */
        $sdk_args = apply_filters( 'rpsfw_ppcp_sdk_args', $sdk_args );

        // Enqueue PayPal SDK - use consistent handle across all files
        $sdk_url = add_query_arg( $sdk_args, 'https://www.paypal.com/sdk/js' );

        wp_enqueue_script(
            'rpsfw-paypal-sdk',
            $sdk_url,
            array(),
            null,
            true
        );

        // Enqueue our checkout script
        $checkout_js = RPSFW_PLUGIN_DIR . 'assets/js/paypal-commerce-checkout.js';
        wp_enqueue_script(
            'rpsfw-paypal-commerce-checkout',
            RPSFW_PLUGIN_URL . 'assets/js/paypal-commerce-checkout.js',
            array( 'jquery', 'rpsfw-paypal-sdk' ),
            file_exists( $checkout_js ) ? filemtime( $checkout_js ) : RPSFW_VERSION,
            true
        );

        // Localize script with data
        wp_localize_script(
            'rpsfw-paypal-commerce-checkout',
            'rpsfwPayPalCommerceCheckout',
            array(
                'ajax_url'              => admin_url( 'admin-ajax.php' ),
                'create_nonce'          => wp_create_nonce( 'rpsfw-ppcp-create-order' ),
                'subscription_nonce'    => wp_create_nonce( 'rpsfw-ppcp-create-subscription' ),
                'is_subscription'       => ( function_exists( 'wcs_is_subscription' )
                                                || ( function_exists( 'rpsfw_native_subscriptions_active' ) && rpsfw_native_subscriptions_active() ) )
                                            && WC_PayPal_Commerce_Subscriptions::cart_contains_subscription(),
                'gateway_id'            => $this->id,
                'payment_action'        => $payment_action,
                'funding'               => $this->get_funding_display_settings(),
            )
        );

        // Add inline CSS for the classic checkout
        $css = '
            .payment_method_rpsfw_paypal_commerce img.rpsfw-paypal-commerce-icon {
                max-height: 28px !important;
                width: auto !important;
                vertical-align: middle;
                margin-left: 8px;
            }
            .payment_method_rpsfw_paypal_commerce label {
                display: inline !important;
            }
            .payment_method_rpsfw_paypal_commerce .payment_box {
                padding: 15px;
            }
            #paypal-button-container {
                margin: 15px 0;
                min-height: 50px;
            }
            #paypal-button-container iframe {
                border: none !important;
                outline: none !important;
            }
            .payment_method_rpsfw_paypal_commerce #paypal-order-id {
                display: none;
            }
        ';
        
        wp_add_inline_style( 'woocommerce-general', $css );
    }

    /**
     * Output payment fields for the gateway.
     */
    public function payment_fields() {
        // Display description if enabled
        if ( $this->description && 'yes' === $this->get_option( 'show_description', 'yes' ) ) {
            echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
        }
        
        // Add PayPal button container and hidden field
        ?>
        <div id="paypal-button-container"></div>
        <input type="hidden" id="paypal-order-id" name="paypal_order_id" value="" />
        <?php
    }

    /**
     * Initialize Gateway Settings Form Fields.
     */
    public function init_form_fields() {
        // Get current sub section from URL
        $current_sub_section = isset($_GET['sub_section']) ? sanitize_title($_GET['sub_section']) : 'general';
        
        // Define all sections
        $all_sections = array(
            'general' => $this->get_general_fields(),
            'payment_options' => $this->get_payment_options_fields(),
            'paylater' => $this->get_paylater_fields(),
            'appearance' => $this->get_appearance_fields(),
            'disputes' => $this->get_dispute_fields(),
            'text' => $this->get_text_fields(),
            'advanced' => $this->get_advanced_fields(),
            'debugging' => $this->get_debugging_fields(),
        );
        
        // Set form fields based on current section
        $this->form_fields = isset($all_sections[$current_sub_section]) ? $all_sections[$current_sub_section] : $all_sections['general'];
    }

    /**
     * Get general settings fields.
     *
     * @return array
     */
    private function get_general_fields() {
        $fields = array();
        
        // Check for conflicting PayPal plugins and show warning
        $conflicts = $this->detect_conflicting_plugins();
        if ( ! empty( $conflicts ) ) {
            $fields['plugin_conflict_warning'] = array(
                'title'       => __( 'Plugin Conflict Detected', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'plugin_conflict_warning',
                'description' => '',
                'conflicts'   => $conflicts,
            );
        }
        
        $fields['general_settings_title'] = array(
            'title'       => __( 'General Settings', 'restore-paypal-standard-for-woocommerce' ),
            'type'        => 'title',
            'description' => __( 'Configure basic PayPal Commerce Platform settings.', 'restore-paypal-standard-for-woocommerce' ),
        );
        
        $fields['enabled'] = array(
            'title'   => __( 'Enable/Disable', 'restore-paypal-standard-for-woocommerce' ),
            'type'    => 'checkbox',
            'label'   => __( 'Enable PayPal Commerce Platform', 'restore-paypal-standard-for-woocommerce' ),
            'default' => 'no',
        );
        $fields['testmode'] = array(
            'title'       => __( 'Mode', 'restore-paypal-standard-for-woocommerce' ),
            'type'        => 'select',
            'description' => __( 'Use Test mode to test payments. Test mode automatically enables logging.', 'restore-paypal-standard-for-woocommerce' ),
            'default'     => 'yes',
            'desc_tip'    => true,
            'options'     => array(
                'no'  => __( 'Live Mode', 'restore-paypal-standard-for-woocommerce' ),
                'yes' => __( 'Test Mode (Sandbox)', 'restore-paypal-standard-for-woocommerce' ),
            ),
            'class'       => 'wc-enhanced-select',
        );
        $fields['connection_status'] = array(
            'title'       => sprintf(
                /* translators: %s: mode (Test Mode or Live Mode) */
                __( 'PayPal Connection (%s)', 'restore-paypal-standard-for-woocommerce' ),
                $this->get_option('testmode', 'yes') === 'yes' 
                    ? __( 'Test Mode', 'restore-paypal-standard-for-woocommerce' ) 
                    : __( 'Live Mode', 'restore-paypal-standard-for-woocommerce' )
            ),
            'type'        => 'paypal_connection',
            'description' => $this->get_option('testmode', 'yes') === 'yes' 
                ? sprintf(
                    /* translators: %s: link to PayPal sandbox accounts */
                    __( 'In test mode you can use sandbox accounts to test payments without real charges. <a href="%s" target="_blank">Create sandbox test accounts</a>', 'restore-paypal-standard-for-woocommerce' ),
                    'https://developer.paypal.com/tools/sandbox/accounts/'
                )
                : '',
            'desc_tip'    => false,
        );
        $fields['webhook_status'] = array(
            'title'       => sprintf(
                /* translators: %s: mode (Test Mode or Live Mode) */
                __( 'Webhooks (%s)', 'restore-paypal-standard-for-woocommerce' ),
                $this->get_option('testmode', 'yes') === 'yes' 
                    ? __( 'Test Mode', 'restore-paypal-standard-for-woocommerce' ) 
                    : __( 'Live Mode', 'restore-paypal-standard-for-woocommerce' )
            ),
            'type'        => 'webhook_status',
            'description' => __( 'Webhooks allow PayPal to notify your store about events like refunds processed from the PayPal dashboard, disputes, and chargebacks.', 'restore-paypal-standard-for-woocommerce' ),
            'desc_tip'    => false,
        );
        
        return $fields;
    }

    /**
     * Get payment options fields.
     *
     * @return array
     */
    private function get_payment_options_fields() {
        $fields = array();
        
        $fields['payment_options_title'] = array(
            'title'       => __( 'Payment Options', 'restore-paypal-standard-for-woocommerce' ),
            'type'        => 'title',
            'description' => __( 'Choose which payment buttons to display at checkout.<br><br><strong>Important:</strong> Availability depends on customer location. Pay Later and Venmo buttons will not show for subscriptions.', 'restore-paypal-standard-for-woocommerce' ),
        );
        
        $fields['enable_paypal'] = array(
            'title'   => __( 'PayPal', 'restore-paypal-standard-for-woocommerce' ),
            'type'    => 'checkbox',
            'label'   => __( 'Enable PayPal button', 'restore-paypal-standard-for-woocommerce' ),
            'description' => __( 'Allow customers to pay with their PayPal account.', 'restore-paypal-standard-for-woocommerce' ),
            'default' => 'yes',
            'desc_tip' => true,
        );
        $fields['enable_paylater'] = array(
            'title'   => __( 'Pay Later', 'restore-paypal-standard-for-woocommerce' ),
            'type'    => 'checkbox',
            'label'   => __( 'Enable Pay Later button', 'restore-paypal-standard-for-woocommerce' ),
            'description' => __( 'Allow customers to use PayPal Pay Later options (Pay in 4, PayPal Credit). Availability depends on customer location.', 'restore-paypal-standard-for-woocommerce' ),
            'default' => 'no',
            'desc_tip' => true,
        );
        $fields['enable_card'] = array(
            'title'   => __( 'Debit or Credit Card', 'restore-paypal-standard-for-woocommerce' ),
            'type'    => 'checkbox',
            'label'   => __( 'Enable Debit or Credit Card button', 'restore-paypal-standard-for-woocommerce' ),
            'description' => __( 'Allow customers to pay with a debit or credit card without a PayPal account (guest checkout via PayPal).', 'restore-paypal-standard-for-woocommerce' ),
            'default' => 'no',
            'desc_tip' => true,
        );
        $fields['enable_venmo'] = array(
            'title'   => __( 'Venmo', 'restore-paypal-standard-for-woocommerce' ),
            'type'    => 'checkbox',
            'label'   => __( 'Enable Venmo button', 'restore-paypal-standard-for-woocommerce' ),
            'description' => __( 'Allow customers to pay with Venmo. Only available for US customers.', 'restore-paypal-standard-for-woocommerce' ),
            'default' => 'no',
            'desc_tip' => true,
        );

        $fields['card_form_title'] = array(
            'title'       => __( 'Debit or Credit Card', 'restore-paypal-standard-for-woocommerce' ),
            'type'        => 'title',
            'description' => __( 'On one-time-payment carts the "Debit or Credit Card" button opens its card form inline, alongside PayPal, Venmo and Pay Later.<br><br><strong>Important:</strong> Subscription carts always use a hosted PayPal popup for cards because PayPal requires it, and Venmo / Pay Later are not available for subscriptions.', 'restore-paypal-standard-for-woocommerce' ),
        );

        $fields['cart_buttons_title'] = array(
            'title'       => __( 'Cart Page Buttons', 'restore-paypal-standard-for-woocommerce' ),
            'type'        => 'title',
            'description' => __( 'Display PayPal buttons on the cart page for express checkout.', 'restore-paypal-standard-for-woocommerce' )
                . '<br><br><strong>' . __( 'Important:', 'restore-paypal-standard-for-woocommerce' ) . '</strong> '
                . __( 'Won\'t be displayed if an item in the cart requires shipping (e.g. physical products). Express checkout is only offered for carts that don\'t need a shipping address.', 'restore-paypal-standard-for-woocommerce' ),
        );
        $fields['enable_cart_buttons'] = array(
            'title'   => __( 'Enable Cart Buttons', 'restore-paypal-standard-for-woocommerce' ),
            'type'    => 'checkbox',
            'label'   => __( 'Show PayPal buttons on the cart page', 'restore-paypal-standard-for-woocommerce' ),
            'description' => __( 'Display PayPal express checkout buttons on the cart page, allowing customers to checkout directly with PayPal.', 'restore-paypal-standard-for-woocommerce' ),
            'default' => 'no',
            'desc_tip' => true,
        );
        $fields['cart_button_position'] = array(
            'title'       => __( 'Button Position', 'restore-paypal-standard-for-woocommerce' ),
            'type'        => 'select',
            'description' => __( 'Choose where to display the PayPal buttons relative to the "Proceed to checkout" button.', 'restore-paypal-standard-for-woocommerce' ),
            'default'     => 'below',
            'desc_tip'    => true,
            'options'     => array(
                'above' => __( 'Above "Proceed to checkout"', 'restore-paypal-standard-for-woocommerce' ),
                'below' => __( 'Below "Proceed to checkout"', 'restore-paypal-standard-for-woocommerce' ),
            ),
            'class'       => 'wc-enhanced-select',
        );
        
        return $fields;
    }

    /**
     * Detect conflicting PayPal plugins.
     *
     * @return array Array of detected conflicting plugins with name and status.
     */
    private function detect_conflicting_plugins() {
        // Use static cache to prevent multiple calls
        static $conflicts = null;
        if ( $conflicts !== null ) {
            return $conflicts;
        }
        
        $conflicts = array();
        
        // Only check on admin settings page to avoid initialization issues
        if ( ! is_admin() || ! did_action( 'woocommerce_init' ) ) {
            return $conflicts;
        }

        // Include plugin.php if not already loaded (needed for is_plugin_active)
        if ( ! function_exists( 'is_plugin_active' ) ) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        // Get all registered payment gateways - use get_option directly to avoid recursion
        $ppcp_gateway_settings = get_option( 'woocommerce_ppcp-gateway_settings', array() );
        $ppcp_settings = get_option( 'woocommerce_ppcp_settings', array() );
        $paypal_express_settings = get_option( 'woocommerce_paypal_express_settings', array() );
        $angelleye_ppcp_settings = get_option( 'woocommerce_angelleye_ppcp_settings', array() );
        
        // Official WooCommerce PayPal Payments plugin
        // Gateway ID: ppcp-gateway
        $woo_ppcp_active = is_plugin_active( 'woocommerce-paypal-payments/woocommerce-paypal-payments.php' );
        $woo_ppcp_gateway_enabled = ! empty( $ppcp_gateway_settings['enabled'] ) && 'yes' === $ppcp_gateway_settings['enabled'];
        
        if ( $woo_ppcp_active ) {
            $conflicts[] = array(
                'name'        => __( 'WooCommerce PayPal Payments', 'restore-paypal-standard-for-woocommerce' ),
                'slug'        => 'woocommerce-paypal-payments',
                'gateway_id'  => 'ppcp-gateway',
                'plugin_active' => true,
                'gateway_enabled' => $woo_ppcp_gateway_enabled,
            );
        }
        
        // Payment Plugins for PayPal WooCommerce (pymntpl-paypal-woocommerce)
        // Gateway ID: ppcp
        $pymntpl_active = is_plugin_active( 'pymntpl-paypal-woocommerce/pymntpl-paypal-woocommerce.php' );
        $pymntpl_gateway_enabled = ! empty( $ppcp_settings['enabled'] ) && 'yes' === $ppcp_settings['enabled'];
        
        if ( $pymntpl_active ) {
            $conflicts[] = array(
                'name'        => __( 'Payment Plugins for PayPal WooCommerce', 'restore-paypal-standard-for-woocommerce' ),
                'slug'        => 'pymntpl-paypal-woocommerce',
                'gateway_id'  => 'ppcp',
                'plugin_active' => true,
                'gateway_enabled' => $pymntpl_gateway_enabled,
            );
        }
        
        // PayPal for WooCommerce by Jevin (paypal-for-woocommerce)
        // Gateway IDs: paypal_express, angelleye_ppcp
        $angelleye_active = is_plugin_active( 'paypal-for-woocommerce/paypal-for-woocommerce.php' );
        $angelleye_gateway_enabled = ( ! empty( $paypal_express_settings['enabled'] ) && 'yes' === $paypal_express_settings['enabled'] )
             || ( ! empty( $angelleye_ppcp_settings['enabled'] ) && 'yes' === $angelleye_ppcp_settings['enabled'] );
        
        if ( $angelleye_active ) {
            $conflicts[] = array(
                'name'        => __( 'PayPal for WooCommerce by Jevin', 'restore-paypal-standard-for-woocommerce' ),
                'slug'        => 'paypal-for-woocommerce',
                'gateway_id'  => 'angelleye_ppcp',
                'plugin_active' => true,
                'gateway_enabled' => $angelleye_gateway_enabled,
            );
        }
        
        return $conflicts;
    }

    /**
     * Generate Plugin Conflict Warning field HTML.
     *
     * @param string $key Field key.
     * @param array  $data Field data.
     * @return string
     */
    public function generate_plugin_conflict_warning_html( $key, $data ) {
        $conflicts = isset( $data['conflicts'] ) ? $data['conflicts'] : array();
        
        if ( empty( $conflicts ) ) {
            return '';
        }
        
        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?php echo esc_html( $data['title'] ); ?></label>
            </th>
            <td class="forminp">
                <div class="rpsfw-conflict-warning" style="background: #fef2f2; border: 1px solid #dc2626; border-left: 4px solid #dc2626; padding: 12px 15px; margin-bottom: 15px;">
                    <p style="margin: 0 0 10px 0; font-weight: 600; color: #991b1b;">
                        <span class="dashicons dashicons-warning" style="color: #dc2626; margin-right: 5px;"></span>
                        <?php esc_html_e( 'Conflicting PayPal plugins detected', 'restore-paypal-standard-for-woocommerce' ); ?>
                    </p>
                    <p style="margin: 0 0 10px 0; color: #991b1b;">
                        <?php esc_html_e( 'The following PayPal payment plugins are active and may cause conflicts. The PayPal JavaScript SDK only allows one instance per page, so multiple PayPal checkout plugins cannot coexist. Please deactivate these plugins for this plugin to work correctly.', 'restore-paypal-standard-for-woocommerce' ); ?>
                    </p>
                    <ul style="margin: 10px 0 0 20px; color: #991b1b;">
                        <?php foreach ( $conflicts as $conflict ) : ?>
                            <li>
                                <strong><?php echo esc_html( $conflict['name'] ); ?></strong>
                                <?php if ( ! empty( $conflict['gateway_enabled'] ) ) : ?>
                                    <span style="color: #dc2626;">(<?php esc_html_e( 'Gateway Enabled', 'restore-paypal-standard-for-woocommerce' ); ?>)</span>
                                <?php else : ?>
                                    <span style="color: #b45309;">(<?php esc_html_e( 'Plugin Active - Gateway Disabled', 'restore-paypal-standard-for-woocommerce' ); ?>)</span>
                                <?php endif; ?>
                                <?php if ( current_user_can( 'activate_plugins' ) ) : ?>
                                    <?php $plugins_url = add_query_arg( 's', $conflict['name'], admin_url( 'plugins.php' ) ); ?>
                                    — <a href="<?php echo esc_url( $plugins_url ); ?>" style="color: #991b1b; text-decoration: underline;"><?php esc_html_e( 'Go to Plugins', 'restore-paypal-standard-for-woocommerce' ); ?></a>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p style="margin: 10px 0 0 0; color: #991b1b; font-size: 12px;">
                        <em><?php esc_html_e( 'Note: Even with the gateway disabled, these plugins may still load the PayPal SDK and interfere with PayPal usage in this plugin.', 'restore-paypal-standard-for-woocommerce' ); ?></em>
                    </p>
                </div>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Get text settings fields.
     *
     * @return array
     */
    private function get_text_fields() {
        return array(
            'text_section' => array(
                'title'       => __( 'Text Settings', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'title',
                'description' => __( 'Customize the text shown to customers during checkout.', 'restore-paypal-standard-for-woocommerce' ),
            ),
            'title' => array(
                'title'       => __( 'Title', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'This controls the title which the user sees during checkout.', 'restore-paypal-standard-for-woocommerce' ),
                'default'     => __( 'PayPal', 'restore-paypal-standard-for-woocommerce' ),
                'desc_tip'    => true,
            ),
            'description' => array(
                'title'       => __( 'Description', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'textarea',
                'description' => __( 'This controls the description which the user sees during checkout.', 'restore-paypal-standard-for-woocommerce' ),
                'default'     => __( 'Pay securely with PayPal.', 'restore-paypal-standard-for-woocommerce' ),
                'desc_tip'    => true,
            ),
            'cart_button_separator_text' => array(
                'title'       => __( 'Cart Button Separator Text', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Text displayed between the checkout button and PayPal button on the cart page.', 'restore-paypal-standard-for-woocommerce' ),
                'default'     => __( 'or', 'restore-paypal-standard-for-woocommerce' ),
                'desc_tip'    => true,
            ),
            'cart_processing_text' => array(
                'title'       => __( 'Cart Processing Message', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Message displayed while processing the PayPal payment from the cart page.', 'restore-paypal-standard-for-woocommerce' ),
                'default'     => __( 'PayPal authorized. Processing your order...', 'restore-paypal-standard-for-woocommerce' ),
                'desc_tip'    => true,
            ),
            'paypal_button_error_text' => array(
                'title'       => __( 'PayPal Button Error Message', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'text',
                'description' => __( 'Error message shown when a customer tries to place an order without completing PayPal payment first.', 'restore-paypal-standard-for-woocommerce' ),
                'default'     => __( 'Please click the PayPal button and complete payment first.', 'restore-paypal-standard-for-woocommerce' ),
                'desc_tip'    => true,
            ),
        );
    }

    /**
     * Get appearance settings fields.
     *
     * @return array
     */
    private function get_appearance_fields() {
        return array(
            'appearance_section' => array(
                'title'       => __( 'Appearance Settings', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'title',
                'description' => __( 'Customize the appearance of the PayPal payment method on checkout pages.', 'restore-paypal-standard-for-woocommerce' ),
            ),
            'show_icon' => array(
                'title'       => __( 'Show Icon', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Display the PayPal logo icon', 'restore-paypal-standard-for-woocommerce' ),
                'description' => __( 'Show the PayPal logo next to the payment method title during checkout.', 'restore-paypal-standard-for-woocommerce' ),
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
            'custom_icon_url' => array(
                'title'       => __( 'Custom Icon URL', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'text',
                'placeholder' => __( 'Leave blank to use the default PayPal logo', 'restore-paypal-standard-for-woocommerce' ),
                'description' => __( 'Optional. Paste the full URL of an image to use instead of the default PayPal logo. Recommended height: 28px. Only used when "Show Icon" is enabled.', 'restore-paypal-standard-for-woocommerce' ),
                'default'     => '',
                'desc_tip'    => false,
            ),
            'show_title' => array(
                'title'       => __( 'Show Title', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Display the payment method title', 'restore-paypal-standard-for-woocommerce' ),
                'description' => __( 'Show the payment method title on the checkout page.', 'restore-paypal-standard-for-woocommerce' ),
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
            'show_description' => array(
                'title'       => __( 'Show Description', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Display the payment method description', 'restore-paypal-standard-for-woocommerce' ),
                'description' => __( 'Show the description text below the payment method title.', 'restore-paypal-standard-for-woocommerce' ),
                'default'     => 'yes',
                'desc_tip'    => true,
            ),
            'button_order' => array(
                'title'       => __( 'Button Order', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'button_order',
                'description' => __( 'Drag to reorder the payment buttons. This order is used on the cart and checkout pages. Disabled buttons are shown greyed out and are skipped at checkout.', 'restore-paypal-standard-for-woocommerce' ),
                'default'     => 'paypal,paylater,venmo,card',
            ),
        );
    }

    /**
     * Canonical default funding-button order.
     *
     * @return array
     */
    private function get_default_funding_order() {
        return array( 'paypal', 'paylater', 'venmo', 'card' );
    }

    /**
     * Return the merchant's saved funding-button order as an array of tokens,
     * always containing all four tokens exactly once (missing/invalid tokens
     * are dropped, then any absent canonical tokens appended).
     *
     * @return array
     */
    public function get_funding_order() {
        $default = $this->get_default_funding_order();
        $saved   = (string) $this->get_option( 'button_order', '' );

        if ( '' === $saved ) {
            return $default;
        }

        $tokens = array_filter( array_map( 'trim', explode( ',', $saved ) ) );
        $clean  = array();
        foreach ( $tokens as $token ) {
            if ( in_array( $token, $default, true ) && ! in_array( $token, $clean, true ) ) {
                $clean[] = $token;
            }
        }
        foreach ( $default as $token ) {
            if ( ! in_array( $token, $clean, true ) ) {
                $clean[] = $token;
            }
        }

        return $clean;
    }

    /**
     * Funding settings passed to the frontend scripts: which buttons are
     * enabled plus the order they should render in.
     *
     * @return array
     */
    public function get_funding_display_settings() {
        return array(
            'paypal'   => 'yes' === $this->get_option( 'enable_paypal', 'yes' ),
            'paylater' => 'yes' === $this->get_option( 'enable_paylater', 'no' ),
            'card'     => 'yes' === $this->get_option( 'enable_card', 'no' ),
            'venmo'    => 'yes' === $this->get_option( 'enable_venmo', 'no' ),
            'order'    => $this->get_funding_order(),
        );
    }

    /**
     * Sanitize the drag-and-drop button order on save.
     *
     * @param string $key   Field key.
     * @param string $value Posted value (comma-separated tokens).
     * @return string
     */
    public function validate_button_order_field( $key, $value ) {
        $allowed = $this->get_default_funding_order();
        $tokens  = array_filter( array_map( 'sanitize_text_field', explode( ',', (string) $value ) ) );
        $clean   = array();
        foreach ( $tokens as $token ) {
            if ( in_array( $token, $allowed, true ) && ! in_array( $token, $clean, true ) ) {
                $clean[] = $token;
            }
        }
        foreach ( $allowed as $token ) {
            if ( ! in_array( $token, $clean, true ) ) {
                $clean[] = $token;
            }
        }

        return implode( ',', $clean );
    }

    /**
     * Render the drag-and-drop button-order control.
     *
     * @param string $key  Field key.
     * @param array  $data Field data.
     * @return string
     */
    public function generate_button_order_html( $key, $data ) {
        $field_key = $this->get_field_key( $key );
        $data      = wp_parse_args(
            $data,
            array(
                'title'       => '',
                'description' => '',
                'desc_tip'    => false,
            )
        );

        $labels = array(
            'paypal'   => __( 'PayPal', 'restore-paypal-standard-for-woocommerce' ),
            'paylater' => __( 'Pay Later', 'restore-paypal-standard-for-woocommerce' ),
            'card'     => __( 'Debit or Credit Card', 'restore-paypal-standard-for-woocommerce' ),
            'venmo'    => __( 'Venmo', 'restore-paypal-standard-for-woocommerce' ),
        );
        $enabled = array(
            'paypal'   => 'yes' === $this->get_option( 'enable_paypal', 'yes' ),
            'paylater' => 'yes' === $this->get_option( 'enable_paylater', 'no' ),
            'card'     => 'yes' === $this->get_option( 'enable_card', 'no' ),
            'venmo'    => 'yes' === $this->get_option( 'enable_venmo', 'no' ),
        );
        $order = $this->get_funding_order();

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label><?php echo wp_kses_post( $data['title'] ); ?></label>
            </th>
            <td class="forminp">
                <p class="description" style="margin:0 0 10px;"><?php echo wp_kses_post( $data['description'] ); ?></p>
                <ul id="rpsfw-ppcp-button-order" class="rpsfw-ppcp-button-order">
                    <?php foreach ( $order as $token ) : ?>
                        <?php if ( ! isset( $labels[ $token ] ) ) { continue; } ?>
                        <li class="rpsfw-ppcp-button-order__item<?php echo empty( $enabled[ $token ] ) ? ' is-disabled' : ''; ?>" data-token="<?php echo esc_attr( $token ); ?>">
                            <span class="dashicons dashicons-menu rpsfw-ppcp-button-order__handle"></span>
                            <span class="rpsfw-ppcp-button-order__label"><?php echo esc_html( $labels[ $token ] ); ?></span>
                            <?php if ( empty( $enabled[ $token ] ) ) : ?>
                                <em class="rpsfw-ppcp-button-order__off"><?php esc_html_e( 'disabled', 'restore-paypal-standard-for-woocommerce' ); ?></em>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <input type="hidden" name="<?php echo esc_attr( $field_key ); ?>" id="<?php echo esc_attr( $field_key ); ?>" value="<?php echo esc_attr( implode( ',', $order ) ); ?>" />
            </td>
        </tr>
        <style>
            .rpsfw-ppcp-button-order { margin: 0; padding: 0; max-width: 420px; list-style: none; }
            .rpsfw-ppcp-button-order__item {
                display: flex; align-items: center; gap: 8px;
                padding: 10px 12px; margin: 0 0 6px;
                background: #fff; border: 1px solid #c3c4c7; border-radius: 4px;
                cursor: grab; user-select: none;
            }
            .rpsfw-ppcp-button-order__item.is-disabled { opacity: 0.55; background: #f6f7f7; }
            .rpsfw-ppcp-button-order__handle { color: #787c82; cursor: grab; }
            .rpsfw-ppcp-button-order__label { font-weight: 600; }
            .rpsfw-ppcp-button-order__off { color: #d63638; font-style: normal; font-size: 12px; margin-left: auto; }
            .rpsfw-ppcp-button-order__item.ui-sortable-helper { box-shadow: 0 2px 8px rgba(0,0,0,0.15); }
            .rpsfw-ppcp-button-order__placeholder { border: 1px dashed #2271b1; background: #f0f6fc; border-radius: 4px; margin: 0 0 6px; height: 42px; }
        </style>
        <script>
        jQuery(function($){
            var $list = $('#rpsfw-ppcp-button-order');
            if ( ! $list.length || ! $.fn.sortable ) { return; }
            $list.sortable({
                handle: '.rpsfw-ppcp-button-order__handle',
                placeholder: 'rpsfw-ppcp-button-order__placeholder',
                axis: 'y',
                update: function(){
                    var order = [];
                    $list.find('li').each(function(){ order.push( $(this).data('token') ); });
                    // Trigger 'change' so WooCommerce's "Save changes" button
                    // enables and the plugin's unsaved-changes notice appears.
                    $('#<?php echo esc_js( $field_key ); ?>').val( order.join(',') ).trigger('change');
                }
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Get dispute settings fields.
     *
     * @return array
     */
    private function get_dispute_fields() {
        return array(
            'dispute_section' => array(
                'title'       => __( 'Dispute Settings', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'title',
                'description' => __( 'Configure how your store handles PayPal disputes and chargebacks.', 'restore-paypal-standard-for-woocommerce' ),
            ),
            'dispute_created_enabled' => array(
                'title'       => __( 'Dispute Created', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Dispute Created', 'restore-paypal-standard-for-woocommerce' ),
                'description' => __( 'If enabled, the plugin will listen for the dispute.created webhook event and set the order\'s status to on-hold by default.', 'restore-paypal-standard-for-woocommerce' ),
                'default'     => 'yes',
                'desc_tip'    => false,
            ),
            'dispute_created_status' => array(
                'title'       => __( 'Dispute Created Order Status', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'select',
                'description' => __( 'The status assigned to an order when a dispute is created.', 'restore-paypal-standard-for-woocommerce' ),
                'default'     => 'wc-on-hold',
                'desc_tip'    => true,
                'options'     => wc_get_order_statuses(),
                'class'       => 'wc-enhanced-select',
            ),
            'dispute_resolved_enabled' => array(
                'title'       => __( 'Dispute Resolved', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Dispute Resolved', 'restore-paypal-standard-for-woocommerce' ),
                'description' => __( 'If enabled, the plugin will listen for the dispute.closed webhook event and set the order\'s status back to the status before the dispute was opened.', 'restore-paypal-standard-for-woocommerce' ),
                'default'     => 'yes',
                'desc_tip'    => false,
            ),
        );
    }

    /**
     * Get advanced settings fields.
     *
     * @return array
     */
    private function get_advanced_fields() {
        return array(
            'advanced_section' => array(
                'title'       => __( 'Advanced Settings', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'title',
                'description' => __( 'Advanced configuration options for PayPal Commerce Platform.', 'restore-paypal-standard-for-woocommerce' ),
            ),
            'payment_action' => array(
                'title'       => __( 'Payment Action', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'select',
                'description' => __( '<strong>Important:</strong> Only works with non-recurring payments.', 'restore-paypal-standard-for-woocommerce' )
                    . '<br><br>'
                    . __( 'If the cart contains both a subscription and a one-off (non-recurring) product at the same time, the entire order is charged immediately.', 'restore-paypal-standard-for-woocommerce' )
                    . '<br><br>'
                    . __( 'Authorized payments are valid for 29 days, but PayPal recommends capturing within 3 days (the honor period) for best success rates.', 'restore-paypal-standard-for-woocommerce' )
                    . '<br>'
                    . '<a href="https://developer.paypal.com/docs/checkout/advanced/authorization-honor/" target="_blank" rel="noopener noreferrer">' . __( 'Learn more about PayPal authorization and honor periods', 'restore-paypal-standard-for-woocommerce' ) . '</a>',
                'desc_tip'    => __( 'Choose whether to capture funds immediately or authorize payment only.', 'restore-paypal-standard-for-woocommerce' ),
                'default'     => 'capture',
                'options'     => array(
                    'capture'   => __( 'Capture', 'restore-paypal-standard-for-woocommerce' ),
                    'authorize' => __( 'Authorize', 'restore-paypal-standard-for-woocommerce' ),
                ),
                'class'       => 'wc-enhanced-select',
            ),
            'validate_order_amount' => array(
                'title'       => __( 'Order Amount Validation', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Validate order amount before capture', 'restore-paypal-standard-for-woocommerce' ),
                'description' => __( 'Compares the PayPal order amount with the WooCommerce order total before capturing payment. This helps prevent amount manipulation attacks. If validation fails, the payment will be rejected. If the order details cannot be retrieved from PayPal (for example, a temporary API issue), the payment is allowed to proceed so legitimate orders are not blocked.', 'restore-paypal-standard-for-woocommerce' ),
                'default'     => 'yes',
                'desc_tip'    => false,
            ),

            /*
             * 3D Secure (3DS) settings are intentionally hidden from the UI.
             *
             * With the current integration we use PayPal's standard buttons +
             * the standalone "Debit or Credit Card" button, where PayPal hosts
             * the card form and handles 3DS / SCA (and the liability shift)
             * automatically on its side. We cannot meaningfully force or
             * override 3DS without the Advanced Card Payments (ACDC / hosted
             * card-fields) integration, so exposing these controls would be
             * misleading:
             *   - "Force 3DS" never actually sends the SCA contingency at order
             *     creation (get_verification_method() is not wired into
             *     create_order), so it has no effect.
             *   - The configurable accept/review/reject rules only act on card
             *     payments that return a three_d_secure result, and run AFTER
             *     capture (risking auto-refunds of legitimately captured
             *     orders).
             *
             * The 3DS handler class (class-paypal-commerce-3ds.php) is left in
             * the codebase, and the post-capture validate_order() calls in
             * process_payment() are commented out (parked) rather than removed.
             * If/when we add ACDC support, un-comment the fields below, re-enable
             * those validate_order() calls, and wire get_verification_method()
             * into order creation to expose and use 3DS again.
             *
             * While parked, no 3DS code runs at checkout, so these options are
             * not read anywhere.
             */
            /*
            '3ds_section' => array(
                'title'       => __( '3D Secure (3DS) Settings', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'title',
                'description' => '<div style="background: #f0f6fc; border-left: 4px solid #0073aa; padding: 12px 15px; margin: 10px 0 20px 0; max-width: 800px;">'
                    . '<p style="margin: 0 0 10px 0;">' 
                    . __( '3D Secure (3DS) is an additional security layer for online card payments that helps prevent fraud. When a customer pays, their bank may ask them to verify their identity (via SMS code, app approval, or biometrics) before the payment is approved.', 'restore-paypal-standard-for-woocommerce' )
                    . '</p>'
                    . '<a href="#" class="rpsfw-3ds-info-toggle" style="color: #0073aa; text-decoration: none; font-weight: 500;">'
                    . '<span class="dashicons dashicons-arrow-down-alt2" style="font-size: 16px; vertical-align: middle;"></span> '
                    . __( 'Learn more about 3D Secure', 'restore-paypal-standard-for-woocommerce' )
                    . '</a>'
                    . '<div class="rpsfw-3ds-info-content" style="display: none; margin-top: 15px;">'
                    . '<p style="margin: 0 0 10px 0;"><strong>' . __( 'Key Benefits:', 'restore-paypal-standard-for-woocommerce' ) . '</strong></p>'
                    . '<ul style="margin: 0 0 10px 20px;">'
                    . '<li>' . __( '<strong>Fraud Protection:</strong> Reduces fraudulent transactions by verifying the cardholder\'s identity', 'restore-paypal-standard-for-woocommerce' ) . '</li>'
                    . '<li>' . __( '<strong>Liability Shift:</strong> When 3DS passes, fraud liability shifts from you (the merchant) to the card issuer', 'restore-paypal-standard-for-woocommerce' ) . '</li>'
                    . '<li>' . __( '<strong>Required in EU:</strong> Strong Customer Authentication (SCA) regulations require 3DS for most transactions', 'restore-paypal-standard-for-woocommerce' ) . '</li>'
                    . '<li>' . __( '<strong>Automatic:</strong> PayPal handles the entire 3DS flow - you just validate the result', 'restore-paypal-standard-for-woocommerce' ) . '</li>'
                    . '</ul>'
                    . '<p style="margin: 0 0 10px 0;"><strong>' . __( 'How It Works:', 'restore-paypal-standard-for-woocommerce' ) . '</strong></p>'
                    . '<ol style="margin: 0 0 10px 20px;">'
                    . '<li>' . __( 'Customer enters payment details', 'restore-paypal-standard-for-woocommerce' ) . '</li>'
                    . '<li>' . __( 'PayPal checks if 3DS verification is needed (based on risk, amount, location)', 'restore-paypal-standard-for-woocommerce' ) . '</li>'
                    . '<li>' . __( 'If needed, customer is redirected to their bank to verify (SMS code, fingerprint, etc.)', 'restore-paypal-standard-for-woocommerce' ) . '</li>'
                    . '<li>' . __( 'PayPal returns the verification result to your store', 'restore-paypal-standard-for-woocommerce' ) . '</li>'
                    . '<li>' . __( 'Your store validates the result and decides whether to accept the payment', 'restore-paypal-standard-for-woocommerce' ) . '</li>'
                    . '</ol>'
                    . '<p style="margin: 0;"><strong>' . __( 'Recommendation:', 'restore-paypal-standard-for-woocommerce' ) . '</strong> ' 
                    . __( 'Keep 3DS enabled for fraud protection. PayPal will only trigger it when necessary, so it won\'t affect most transactions.', 'restore-paypal-standard-for-woocommerce' )
                    . '</p>'
                    . '</div>'
                    . '</div>',
            ),
            '3ds_enabled' => array(
                'title'       => __( 'Enable 3D Secure', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable 3D Secure verification', 'restore-paypal-standard-for-woocommerce' ),
                'description' => __( 'When enabled, your store will validate 3D Secure authentication results from PayPal. PayPal automatically triggers 3DS when needed based on risk factors. Disabling this removes fraud protection and liability shift benefits.', 'restore-paypal-standard-for-woocommerce' ),
                'default'     => 'yes',
                'desc_tip'    => false,
            ),
            '3ds_forced' => array(
                'title'       => __( 'Force 3D Secure', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Require 3DS for all transactions', 'restore-paypal-standard-for-woocommerce' ),
                'description' => __( 'When enabled, 3D Secure will be required for ALL card transactions (when supported by the card). This provides maximum security but may reduce conversion rates as some customers may abandon checkout during verification. Only enable if you need the highest level of fraud protection.', 'restore-paypal-standard-for-woocommerce' ),
                'default'     => 'no',
                'desc_tip'    => false,
            ),
            '3ds_action_rules' => array(
                'title'       => __( 'Configure 3DS Rules', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => '3ds_action_rules',
                'desc_tip'    => false,
                'description' => '',
                'default'     => array(),
            ),
            */
        );
    }

    /**
     * Get Pay Later disabled notice if applicable.
     *
     * @return string
     */
    private function get_paylater_disabled_notice() {
        // Check if Pay Later button is disabled on General tab
        if ( 'yes' !== $this->get_option( 'enable_paylater', 'no' ) ) {
            $payment_options_tab_url = add_query_arg(
                array(
                    'page'    => 'wc-settings',
                    'tab'     => 'checkout',
                    'section' => 'rpsfw_paypal_commerce',
                    'sub_section' => 'payment_options',
                ),
                admin_url( 'admin.php' )
            );
            
            return '<div class="notice notice-warning inline" style="margin: 5px 0 15px; padding: 8px 12px;">'
                . '<p style="margin: 0.5em 0;">'
                . '<strong>' . __( 'Notice:', 'restore-paypal-standard-for-woocommerce' ) . '</strong> '
                . sprintf(
                    /* translators: %s: link to the Payment Options tab. */
                    __( 'The Pay Later button is currently disabled on the %s. Enable it to allow customers to use Pay Later payment options at checkout.', 'restore-paypal-standard-for-woocommerce' ),
                    '<a href="' . esc_url( $payment_options_tab_url ) . '">' . __( 'Payment Options tab', 'restore-paypal-standard-for-woocommerce' ) . '</a>'
                )
                . '</p>'
                . '</div>';
        }
        
        return '';
    }

    /**
     * Get Pay Later messaging description.
     *
     * @return string
     */
    private function get_paylater_messaging_description() {
        return __( 'Show Pay Later promotional messages to customers. Availability depends on customer location and eligibility.', 'restore-paypal-standard-for-woocommerce' );
    }

    /**
     * Get Pay Later messaging settings fields.
     *
     * @return array
     */
    private function get_paylater_fields() {
        $fields = array(
            'paylater_section' => array(
                'title'       => __( 'Pay Later Messaging', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'title',
                'description' => $this->get_paylater_disabled_notice()
                    . sprintf(
                        /* translators: %s: link to PayPal Pay Later documentation. */
                        __( 'Display Pay Later messaging to inform customers about flexible payment options like Pay in 4 and PayPal Credit. %s', 'restore-paypal-standard-for-woocommerce' ),
                        '<a href="https://developer.paypal.com/docs/checkout/pay-later/" target="_blank" rel="noopener noreferrer">' . __( 'Learn more about Pay Later', 'restore-paypal-standard-for-woocommerce' ) . '</a>'
                    ) . '<br><br><strong>' . __( 'Important Note:', 'restore-paypal-standard-for-woocommerce' ) . '</strong> '
                      . __( 'Please keep in mind that your PayPal messaging will be automatically generated based upon the order amount. The message content cannot be customized or overridden.', 'restore-paypal-standard-for-woocommerce' )
                      . '<br><br><strong>' . __( 'Example Messages:', 'restore-paypal-standard-for-woocommerce' ) . '</strong><br>'
                      . '<img src="' . esc_url( RPSFW_PLUGIN_URL . 'assets/images/paypal_paylater_example.png' ) . '" alt="' . esc_attr__( 'PayPal Pay Later Example Messages', 'restore-paypal-standard-for-woocommerce' ) . '" style="max-width: 100%; height: auto; margin-top: 10px; border: 1px solid #ddd; border-radius: 4px;">',
            ),
            'paylater_messaging_enabled' => array(
                'title'       => __( 'Enable Pay Later Messaging', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable Pay Later messaging on your store', 'restore-paypal-standard-for-woocommerce' ),
                'description' => $this->get_paylater_messaging_description(),
                'default'     => 'no',
                'desc_tip'    => false,
            ),
            'paylater_locations_intro' => array(
                'title'       => __( 'Messaging Locations', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'title',
                'description' => __( 'Below you can configure your Pay Later messaging options and choose which pages you want to display them on.', 'restore-paypal-standard-for-woocommerce' ),
            ),
        );

        // Product Page Settings
        $fields = array_merge( $fields, $this->get_paylater_location_fields( 'product', __( 'Product Page', 'restore-paypal-standard-for-woocommerce' ) ) );
        
        // Cart Page Settings
        $fields = array_merge( $fields, $this->get_paylater_location_fields( 'cart', __( 'Cart Page', 'restore-paypal-standard-for-woocommerce' ) ) );
        
        // Checkout Page Settings
        $fields = array_merge( $fields, $this->get_paylater_location_fields( 'checkout', __( 'Checkout Page', 'restore-paypal-standard-for-woocommerce' ) ) );
        
        // Shop/Category Page Settings
        $fields = array_merge( $fields, $this->get_paylater_location_fields( 'shop', __( 'Shop/Category Pages', 'restore-paypal-standard-for-woocommerce' ) ) );

        // Mini Cart Settings
        $fields = array_merge( $fields, $this->get_paylater_location_fields( 'minicart', __( 'Mini Cart', 'restore-paypal-standard-for-woocommerce' ) ) );

        // Shortcode info
        $fields['paylater_shortcode_info'] = array(
            'title'       => __( 'Shortcode', 'restore-paypal-standard-for-woocommerce' ),
            'type'        => 'title',
            'description' => sprintf(
                /* translators: %s: shortcode example. */
                __( 'You can also display Pay Later messages anywhere using the shortcode: %s', 'restore-paypal-standard-for-woocommerce' ),
                '<code>[paypal_pay_later_message amount="100" layout="text"]</code>'
            ),
        );

        return $fields;
    }

    /**
     * Get Pay Later settings fields for a specific location.
     *
     * @param string $location Location identifier.
     * @param string $title    Location title.
     * @return array
     */
    private function get_paylater_location_fields( $location, $title ) {
        $prefix = 'paylater_messaging_' . $location;
        
        $fields = array(
            $prefix . '_title' => array(
                'title'       => $title,
                'type'        => 'title',
                'description' => '',
            ),
            $prefix => array(
                'title'       => __( 'Enable', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'checkbox',
                /* translators: %s: location name (e.g. product page). */
                'label'       => sprintf( __( 'Show Pay Later message on %s', 'restore-paypal-standard-for-woocommerce' ), strtolower( $title ) ),
                'default'     => 'no',
            ),
        );

        // Add location-specific position options
        $location_options = $this->get_paylater_location_options( $location );
        if ( ! empty( $location_options ) ) {
            $fields[ $prefix . '_location' ] = array(
                'title'       => __( 'Position', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'select',
                'description' => __( 'Where to display the Pay Later message.', 'restore-paypal-standard-for-woocommerce' ),
                'default'     => key( $location_options ),
                'desc_tip'    => true,
                'options'     => $location_options,
                'class'       => 'wc-enhanced-select',
            );
        }

        $fields[ $prefix . '_layout' ] = array(
            'title'       => __( 'Layout', 'restore-paypal-standard-for-woocommerce' ),
            'type'        => 'select',
            'description' => __( 'Choose between a lightweight text message or a flexible display banner.', 'restore-paypal-standard-for-woocommerce' ),
            'default'     => 'text',
            'desc_tip'    => true,
            'options'     => array(
                'text' => __( 'Text (Lightweight)', 'restore-paypal-standard-for-woocommerce' ),
                'flex' => __( 'Flex (Banner)', 'restore-paypal-standard-for-woocommerce' ),
            ),
            'class'       => 'wc-enhanced-select rpsfw-paylater-layout-select',
        );

        // Text layout options
        $fields[ $prefix . '_logo_type' ] = array(
            'title'       => __( 'Logo Type', 'restore-paypal-standard-for-woocommerce' ),
            'type'        => 'select',
            'description' => __( 'The type of PayPal logo to display.', 'restore-paypal-standard-for-woocommerce' ),
            'default'     => 'primary',
            'desc_tip'    => true,
            'options'     => array(
                'primary'     => __( 'Primary (Full logo)', 'restore-paypal-standard-for-woocommerce' ),
                'alternative' => __( 'Alternative (PP monogram)', 'restore-paypal-standard-for-woocommerce' ),
                'inline'      => __( 'Inline (Logo with text)', 'restore-paypal-standard-for-woocommerce' ),
                'none'        => __( 'None (Text only)', 'restore-paypal-standard-for-woocommerce' ),
            ),
            'class'       => 'wc-enhanced-select rpsfw-paylater-text-option rpsfw-paylater-logo-type',
        );

        $fields[ $prefix . '_logo_position' ] = array(
            'title'       => __( 'Logo Position', 'restore-paypal-standard-for-woocommerce' ),
            'type'        => 'select',
            'description' => __( 'Position of the logo relative to the message.', 'restore-paypal-standard-for-woocommerce' ),
            'default'     => 'left',
            'desc_tip'    => true,
            'options'     => array(
                'left'  => __( 'Left', 'restore-paypal-standard-for-woocommerce' ),
                'right' => __( 'Right', 'restore-paypal-standard-for-woocommerce' ),
                'top'   => __( 'Top', 'restore-paypal-standard-for-woocommerce' ),
            ),
            'class'       => 'wc-enhanced-select rpsfw-paylater-text-option',
        );

        $fields[ $prefix . '_text_color' ] = array(
            'title'       => __( 'Text Color', 'restore-paypal-standard-for-woocommerce' ),
            'type'        => 'select',
            'description' => __( 'Color of the message text and logo.', 'restore-paypal-standard-for-woocommerce' ),
            'default'     => 'black',
            'desc_tip'    => true,
            'options'     => array(
                'black'      => __( 'Black', 'restore-paypal-standard-for-woocommerce' ),
                'white'      => __( 'White', 'restore-paypal-standard-for-woocommerce' ),
                'monochrome' => __( 'Monochrome', 'restore-paypal-standard-for-woocommerce' ),
                'grayscale'  => __( 'Grayscale', 'restore-paypal-standard-for-woocommerce' ),
            ),
            'class'       => 'wc-enhanced-select rpsfw-paylater-text-option',
        );

        $fields[ $prefix . '_text_size' ] = array(
            'title'       => __( 'Text Size', 'restore-paypal-standard-for-woocommerce' ),
            'type'        => 'select',
            'description' => __( 'Font size of the message text.', 'restore-paypal-standard-for-woocommerce' ),
            'default'     => '12',
            'desc_tip'    => true,
            'options'     => array(
                '10' => '10px',
                '11' => '11px',
                '12' => '12px',
                '13' => '13px',
                '14' => '14px',
                '15' => '15px',
                '16' => '16px',
            ),
            'class'       => 'wc-enhanced-select rpsfw-paylater-text-option',
        );

        $fields[ $prefix . '_text_align' ] = array(
            'title'       => __( 'Text Alignment', 'restore-paypal-standard-for-woocommerce' ),
            'type'        => 'select',
            'description' => __( 'Alignment of the message text.', 'restore-paypal-standard-for-woocommerce' ),
            'default'     => 'left',
            'desc_tip'    => true,
            'options'     => array(
                'left'   => __( 'Left', 'restore-paypal-standard-for-woocommerce' ),
                'center' => __( 'Center', 'restore-paypal-standard-for-woocommerce' ),
                'right'  => __( 'Right', 'restore-paypal-standard-for-woocommerce' ),
            ),
            'class'       => 'wc-enhanced-select rpsfw-paylater-text-option',
        );

        // Flex layout options
        $fields[ $prefix . '_flex_color' ] = array(
            'title'       => __( 'Banner Color', 'restore-paypal-standard-for-woocommerce' ),
            'type'        => 'select',
            'description' => __( 'Background color of the flex banner.', 'restore-paypal-standard-for-woocommerce' ),
            'default'     => 'blue',
            'desc_tip'    => true,
            'options'     => array(
                'blue'            => __( 'Blue', 'restore-paypal-standard-for-woocommerce' ),
                'black'           => __( 'Black', 'restore-paypal-standard-for-woocommerce' ),
                'white'           => __( 'White', 'restore-paypal-standard-for-woocommerce' ),
                'white-no-border' => __( 'White (No Border)', 'restore-paypal-standard-for-woocommerce' ),
                'gray'            => __( 'Gray', 'restore-paypal-standard-for-woocommerce' ),
                'monochrome'      => __( 'Monochrome', 'restore-paypal-standard-for-woocommerce' ),
                'grayscale'       => __( 'Grayscale', 'restore-paypal-standard-for-woocommerce' ),
            ),
            'class'       => 'wc-enhanced-select rpsfw-paylater-flex-option',
        );

        $fields[ $prefix . '_flex_ratio' ] = array(
            'title'       => __( 'Banner Ratio', 'restore-paypal-standard-for-woocommerce' ),
            'type'        => 'select',
            'description' => __( 'Aspect ratio of the flex banner.', 'restore-paypal-standard-for-woocommerce' ),
            'default'     => '8x1',
            'desc_tip'    => true,
            'options'     => array(
                '1x1'  => __( '1x1 (Square, 120-300px wide)', 'restore-paypal-standard-for-woocommerce' ),
                '1x4'  => __( '1x4 (Vertical, 160px wide)', 'restore-paypal-standard-for-woocommerce' ),
                '8x1'  => __( '8x1 (Horizontal, 250-768px wide)', 'restore-paypal-standard-for-woocommerce' ),
                '20x1' => __( '20x1 (Wide, 250-1169px wide)', 'restore-paypal-standard-for-woocommerce' ),
            ),
            'class'       => 'wc-enhanced-select rpsfw-paylater-flex-option',
        );

        return $fields;
    }

    /**
     * Get position options for a specific location.
     *
     * @param string $location Location identifier.
     * @return array
     */
    private function get_paylater_location_options( $location ) {
        switch ( $location ) {
            case 'product':
                return array(
                    'above_price'       => __( 'Above Price', 'restore-paypal-standard-for-woocommerce' ),
                    'below_price'       => __( 'Below Price', 'restore-paypal-standard-for-woocommerce' ),
                    'below_add_to_cart' => __( 'Below Add to Cart', 'restore-paypal-standard-for-woocommerce' ),
                );
            case 'cart':
                return array(
                    'below_total'   => __( 'Below Cart Total', 'restore-paypal-standard-for-woocommerce' ),
                    'above_buttons' => __( 'Above Proceed to Checkout Button', 'restore-paypal-standard-for-woocommerce' ),
                );
            case 'checkout':
                return array(
                    'below_total'   => __( 'Below Order Total', 'restore-paypal-standard-for-woocommerce' ),
                    'above_buttons' => __( 'Below Payment Buttons', 'restore-paypal-standard-for-woocommerce' ),
                );
            case 'shop':
                return array(
                    'below_price'       => __( 'Below Product Price', 'restore-paypal-standard-for-woocommerce' ),
                    'below_add_to_cart' => __( 'Below Add to Cart Button', 'restore-paypal-standard-for-woocommerce' ),
                );
            case 'minicart':
                return array(
                    'above_buttons' => __( 'Above Buttons', 'restore-paypal-standard-for-woocommerce' ),
                    'below_buttons' => __( 'Below Buttons', 'restore-paypal-standard-for-woocommerce' ),
                );
            default:
                return array();
        }
    }

    /**
     * Get debugging settings fields.
     *
     * @return array
     */
    private function get_debugging_fields() {
        return array(
            'debug_section' => array(
                'title'       => __( 'Debugging Settings', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'title',
                'description' => __( 'These settings help with troubleshooting PayPal Commerce issues.', 'restore-paypal-standard-for-woocommerce' ),
            ),
            'debug' => array(
                'title'       => __( 'Debug Log', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'checkbox',
                'label'       => __( 'Enable logging', 'restore-paypal-standard-for-woocommerce' ),
                'default'     => 'no',
                /* translators: %s: log file path. */
                'description' => sprintf( __( 'Log PayPal Commerce events inside %s', 'restore-paypal-standard-for-woocommerce' ), '<code>' . WC_Log_Handler_File::get_log_file_path( 'rpsfw-paypal-commerce' ) . '</code>' ),
            ),
            'view_logs' => array(
                'title'       => __( 'View Debug Logs', 'restore-paypal-standard-for-woocommerce' ),
                'type'        => 'title',
                'description' => sprintf(
                    /* translators: %s: URL to the WooCommerce Status logs page. */
                    __( 'You can view PayPal Commerce logs in the <a href="%s">WooCommerce Status > Logs</a> section.', 'restore-paypal-standard-for-woocommerce' ),
                    esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) )
                ),
            ),
        );
    }

    /**
     * Check if this gateway is enabled and available.
     *
     * @return bool
     */
    public function is_available() {
        $is_available = parent::is_available();

        if ( ! $is_available ) {
            return false;
        }

        // Check if connected to PayPal
        if ( ! $this->is_connected() ) {
            return false;
        }

        // PayPal Commerce cannot represent more than one distinct billing
        // schedule in a single subscription (its Subscriptions API is
        // plan-based with a single cadence). Hide it at checkout when the cart
        // has multiple distinct recurring schedules so those carts use a
        // gateway that supports mixed intervals (Stripe). This is an explicit
        // guard because relying solely on the WCS `multiple_subscriptions`
        // support flag to auto-hide the gateway proved unreliable.
        if ( class_exists( 'WC_PayPal_Commerce_Subscriptions' )
            && WC_PayPal_Commerce_Subscriptions::cart_contains_multiple_subscriptions() ) {
            return false;
        }

        return true;
    }

    /**
     * Check if gateway is connected to PayPal.
     *
     * @return bool
     */
    public function is_connected() {
        $env = $this->testmode ? 'sandbox' : 'live';
        $onboarding = $this->get_option( 'ppcp_onboarding', array() );

        if ( empty( $onboarding[ $env ]['seller_id'] ) ) {
            return false;
        }

        return true;
    }

    /**
     * Show a native WordPress admin notice when PayPal is connected in the
     * ACTIVE environment (test or live, per the Mode setting) but no webhook is
     * configured for it.
     *
     * Without a webhook, PayPal cannot notify the store about events such as
     * refunds issued from the PayPal dashboard, disputes/chargebacks, capture
     * completions, and authorization voids — so orders can silently fall out of
     * sync. Only the environment the gateway is actually running in is checked;
     * a missing webhook in the other mode is irrelevant.
     *
     * @return void
     */
    public function maybe_show_missing_webhook_notice() {
        // Only relevant to users who can manage the store/settings.
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        // Nothing to warn about if the gateway is disabled — a missing webhook
        // only matters when PayPal Commerce is actually live and taking orders.
        if ( 'yes' !== $this->get_option( 'enabled', 'no' ) ) {
            return;
        }

        // Render once even if the gateway is instantiated multiple times in a
        // single request.
        static $shown = false;
        if ( $shown ) {
            return;
        }

        $onboarding = $this->get_option( 'ppcp_onboarding', array() );
        if ( ! is_array( $onboarding ) ) {
            return;
        }

        // Only check the environment the gateway is actually operating in. A
        // missing webhook in the OTHER mode (e.g. sandbox while running live)
        // is irrelevant and shouldn't trigger a warning.
        $env   = $this->testmode ? 'sandbox' : 'live';
        $label = $this->testmode
            ? __( 'Test mode (Sandbox)', 'restore-paypal-standard-for-woocommerce' )
            : __( 'Live mode', 'restore-paypal-standard-for-woocommerce' );

        $connected   = ! empty( $onboarding[ $env ]['seller_id'] );
        $has_webhook = ! empty( $this->get_option( 'webhook_id_' . $env ) );

        // Connected and webhooked (or not connected at all): nothing to warn about.
        if ( ! $connected || $has_webhook ) {
            return;
        }

        $shown = true;

        $is_localhost = $this->is_localhost();

        // On a local/dev environment, webhooks simply cannot work (PayPal
        // cannot reach a private IP). Show a softer, dismissible info notice
        // instead of an error, and skip the "Configure webhook" button.
        if ( $is_localhost ) {
            $dismissed_key = 'rpsfw_webhook_notice_dismissed_localhost';
            if ( get_user_meta( get_current_user_id(), $dismissed_key, true ) ) {
                return;
            }

            $nonce = wp_create_nonce( 'rpsfw_dismiss_webhook_notice' );
            echo '<div class="notice notice-warning is-dismissible" id="rpsfw-webhook-notice" data-nonce="' . esc_attr( $nonce ) . '" data-key="' . esc_attr( $dismissed_key ) . '">';
            echo '<p><strong>' . esc_html__( 'PayPal Commerce: webhook not available on localhost', 'restore-paypal-standard-for-woocommerce' ) . '</strong></p>';
            echo '<p>' . sprintf(
                /* translators: %s: the active environment (e.g. "Test mode (Sandbox)"). */
                esc_html__( 'PayPal is connected for %s but webhooks cannot be configured on a local development environment — PayPal requires a publicly reachable URL. You don\'t need to do anything: your webhook will be set up for you automatically once your store is running on a live, publicly accessible site.', 'restore-paypal-standard-for-woocommerce' ),
                '<strong>' . esc_html( $label ) . '</strong>'
            ) . '</p>';
            echo '</div>';
        } else {
            $dismissed_key = 'rpsfw_webhook_notice_dismissed_' . $env;
            if ( get_user_meta( get_current_user_id(), $dismissed_key, true ) ) {
                return;
            }

            $settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=rpsfw_paypal_commerce' );
            $nonce        = wp_create_nonce( 'rpsfw_dismiss_webhook_notice' );

            echo '<div class="notice notice-error is-dismissible" id="rpsfw-webhook-notice" data-nonce="' . esc_attr( $nonce ) . '" data-key="' . esc_attr( $dismissed_key ) . '">';
            echo '<p><strong>' . esc_html__( 'PayPal Commerce: webhook not configured', 'restore-paypal-standard-for-woocommerce' ) . '</strong></p>';
            echo '<p>' . sprintf(
                /* translators: %s: the active environment (e.g. "Live mode"). */
                esc_html__( 'PayPal is connected for %s but no webhook is set up. Without a webhook, your store will not be notified about refunds, disputes, capture completions, or voided authorizations, and orders may fall out of sync.', 'restore-paypal-standard-for-woocommerce' ),
                '<strong>' . esc_html( $label ) . '</strong>'
            ) . '</p>';
            echo '<p><a href="' . esc_url( $settings_url ) . '" class="button button-primary">' . esc_html__( 'Configure webhook', 'restore-paypal-standard-for-woocommerce' ) . '</a></p>';
            echo '</div>';
        }

        // Inline script: persist the dismiss across page loads via AJAX when
        // the user clicks the standard WP notice close button.
        ?>
        <script>
        ( function() {
            var notice = document.getElementById( 'rpsfw-webhook-notice' );
            if ( ! notice ) { return; }
            notice.addEventListener( 'click', function( e ) {
                if ( ! e.target.classList.contains( 'notice-dismiss' ) ) { return; }
                var data = new FormData();
                data.append( 'action', 'rpsfw_dismiss_webhook_notice' );
                data.append( 'nonce',  notice.dataset.nonce );
                data.append( 'key',    notice.dataset.key );
                navigator.sendBeacon( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, data );
            } );
        } )();
        </script>
        <?php
    }

    /**
     * AJAX handler — persistently dismiss the webhook notice for the current user.
     *
     * @return void
     */
    public function ajax_dismiss_webhook_notice() {
        check_ajax_referer( 'rpsfw_dismiss_webhook_notice', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( '', '', array( 'response' => 403 ) );
        }

        $allowed_keys = array(
            'rpsfw_webhook_notice_dismissed_live',
            'rpsfw_webhook_notice_dismissed_sandbox',
            'rpsfw_webhook_notice_dismissed_localhost',
        );

        $key = isset( $_POST['key'] ) ? sanitize_key( $_POST['key'] ) : '';

        if ( in_array( $key, $allowed_keys, true ) ) {
            update_user_meta( get_current_user_id(), $key, '1' );
        }

        wp_die();
    }

    /**
     * Detect whether the current request is coming from a local/dev environment
     * where PayPal webhooks cannot reach the server.
     *
     * @return bool
     */
    private function is_localhost() {
        $host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
        // Strip port number if present.
        $host = preg_replace( '/:\d+$/', '', $host );

        $local_patterns = array( 'localhost', '127.0.0.1', '::1' );
        foreach ( $local_patterns as $pattern ) {
            if ( $host === $pattern ) {
                return true;
            }
        }

        // Common local-dev TLDs: .local, .test, .dev, .example, .invalid, .localhost
        if ( preg_match( '/\.(local|test|dev|localhost|example|invalid)$/', $host ) ) {
            return true;
        }

        return false;
    }

    /**
     * Get connection status from API.
     *
     * @return array|false
     */
    public function get_connection_status() {
        $env = $this->testmode ? 'sandbox' : 'live';
        $onboarding = $this->get_option( 'ppcp_onboarding', array() );

        if ( empty( $onboarding[ $env ] ) ) {
            return false;
        }

        // Check if we need to find seller_id
        if ( empty( $onboarding[ $env ]['seller_id'] ) && ! empty( $onboarding[ $env ]['tracking_id'] ) ) {
            $seller_data = $this->api->find_seller_id( $env, $onboarding[ $env ] );
            if ( $seller_data && ! empty( $seller_data['seller_id'] ) ) {
                // Save seller_id
                $onboarding[ $env ]['seller_id'] = sanitize_text_field( $seller_data['seller_id'] );
                $this->update_option( 'ppcp_onboarding', $onboarding );

                // Auto-create the webhook now that we have the seller id. This
                // mirrors the AJAX status-check path; without it, onboarding
                // that completes via the settings-page redirect would never
                // register a webhook. Idempotent: skips if one already exists.
                if ( isset( $this->webhooks ) && method_exists( $this->webhooks, 'auto_create_for_env' ) ) {
                    $this->webhooks->auto_create_for_env( $env );
                }
            }
        }

        // Get status from API
        if ( ! empty( $onboarding[ $env ]['seller_id'] ) ) {
            return $this->api->get_status( $env, $onboarding[ $env ] );
        }

        return false;
    }

    /**
     * Admin Panel Options.
     */
    public function admin_options() {
        // Get current section
        $current_section = empty($_GET['section']) ? 'rpsfw_paypal_commerce' : sanitize_title($_GET['section']);
        $current_sub_section = isset($_GET['sub_section']) ? sanitize_title($_GET['sub_section']) : 'general';
        
        // Display title with breadcrumb and unsaved changes notice
        echo '<h2 style="position: relative;">';
        echo esc_html( $this->get_method_title() );
        echo '<small class="wc-admin-breadcrumb"><a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout')) . '" aria-label="' . esc_attr__('Return to payments', 'restore-paypal-standard-for-woocommerce') . '">⤴</a></small>';
        ?>
        <span id="rpsfw-ppcp-save-notice" class="rpsfw-ppcp-save-notice-inline" style="display: none;">
            <span class="dashicons dashicons-info"></span>
            <span><?php esc_html_e( 'Unsaved changes - Press "Save changes" at bottom of page.', 'restore-paypal-standard-for-woocommerce' ); ?></span>
        </span>
        <?php
        echo '</h2>';
        echo '<p>' . esc_html( $this->get_method_description() ) . '</p>';
        echo '<p style="margin-top: -6px;">'
            . esc_html__( 'Note: PayPal Commerce does not support selling multiple (more than one) subscription purchases at a time. If this is a feature you need, we recommend also using Stripe.', 'restore-paypal-standard-for-woocommerce' )
            . '</p>';
        
        // Display sub-section tabs
        if ($current_section === 'rpsfw_paypal_commerce') {
            echo '<ul class="subsubsub">';
            $sub_sections = array(
                'general' => __('General', 'restore-paypal-standard-for-woocommerce'),
                'payment_options' => __('Payment Options', 'restore-paypal-standard-for-woocommerce'),
                'paylater' => __('Pay Later', 'restore-paypal-standard-for-woocommerce'),
                'appearance' => __('Appearance', 'restore-paypal-standard-for-woocommerce'),
                'disputes' => __('Disputes', 'restore-paypal-standard-for-woocommerce'),
                'text' => __('Text', 'restore-paypal-standard-for-woocommerce'),
                'advanced' => __('Advanced', 'restore-paypal-standard-for-woocommerce'),
                'debugging' => __('Debugging', 'restore-paypal-standard-for-woocommerce'),
            );
            $i = 0;
            $total = count($sub_sections);
            foreach ($sub_sections as $id => $label) {
                $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=rpsfw_paypal_commerce&sub_section=' . $id);
                $class = ($current_sub_section === $id) ? 'current' : '';
                echo '<li><a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a>';
                if (++$i < $total) {
                    echo ' | ';
                }
                echo '</li>';
            }
            echo '</ul>';
            echo '<br class="clear" />';
        }

        ?>
        <div class="rpsfw-ppcp-settings">
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table>
        </div>
        <script type="text/javascript">
        jQuery( function ( $ ) {
            // Keep WooCommerce's "Save changes" button always enabled on this
            // settings screen. By default WooCommerce renders it disabled and
            // only enables it once a field changes (see core settings.js).
            var rpsfwEnableSave = function () {
                $( '.woocommerce-save-button' ).prop( 'disabled', false ).removeAttr( 'disabled' );
            };
            rpsfwEnableSave();
            setTimeout( rpsfwEnableSave, 500 );
        } );
        </script>
        <?php
    }

    /**
     * Generate PayPal Connection field HTML.
     *
     * @param string $key Field key.
     * @param array  $data Field data.
     * @return string
     */
    public function generate_paypal_connection_html( $key, $data ) {
        $field_key = $this->get_field_key( $key );
        $defaults  = array(
            'title'       => '',
            'class'       => '',
            'description' => '',
        );

        $data = wp_parse_args( $data, $defaults );

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
            </th>
            <td class="forminp">
                <fieldset>
                    <div class="paypal-commerce-connection-status-inline">
                        <?php $this->display_connection_status_inline(); ?>
                    </div>
                    <?php echo wp_kses_post( $this->get_description_html( $data ) ); ?>
                </fieldset>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Generate Webhook Status field HTML.
     *
     * @param string $key Field key.
     * @param array  $data Field data.
     * @return string
     */
    public function generate_webhook_status_html( $key, $data ) {
        $field_key = $this->get_field_key( $key );
        $defaults  = array(
            'title'       => '',
            'class'       => '',
            'description' => '',
        );

        $data = wp_parse_args( $data, $defaults );

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
            </th>
            <td class="forminp">
                <fieldset>
                    <div class="paypal-commerce-webhook-status-inline">
                        <?php $this->display_webhook_status(); ?>
                    </div>
                    <?php echo wp_kses_post( $this->get_description_html( $data ) ); ?>
                </fieldset>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Generate 3DS Action Rules HTML.
     *
     * @param string $key Field key.
     * @param array  $data Field data.
     * @return string
     */
    public function generate_3ds_action_rules_html( $key, $data ) {
        $field_key = $this->get_field_key( $key );
        $defaults  = array(
            'title'       => '',
            'class'       => '',
            'description' => '',
        );

        $data = wp_parse_args( $data, $defaults );

        // Load 3DS handler
        require_once dirname( __FILE__ ) . '/class-paypal-commerce-3ds.php';
        $threeds = new WC_PayPal_Commerce_3DS( $this );
        
        // Get current rules
        $current_rules = $this->get_option( '3ds_action_rules', array() );
        $default_rules = $threeds->get_action_rules();
        $rules = wp_parse_args( $current_rules, $default_rules );

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
            </th>
            <td class="forminp">
                <fieldset>
                    <div style="background: #fff8e5; border-left: 4px solid #ffb900; padding: 12px 15px; margin: 10px 0 15px 0; max-width: 800px;">
                        <p style="margin: 0;"><em><?php 
                            echo esc_html__( 'The default rules are recommended by PayPal and provide a good balance between security and conversion. Only modify if you have specific fraud prevention requirements.', 'restore-paypal-standard-for-woocommerce' );
                            ?> <a href="https://developer.paypal.com/docs/checkout/advanced/customize/3d-secure/response-parameters/" target="_blank" rel="noopener noreferrer"><?php 
                            echo esc_html__( 'Learn more about 3DS response codes', 'restore-paypal-standard-for-woocommerce' );
                            ?></a></em></p>
                    </div>
                    
                    <button type="button" class="button button-secondary rpsfw-ppcp-configure-3ds">
                        <?php esc_html_e( 'Configure 3DS Rules', 'restore-paypal-standard-for-woocommerce' ); ?>
                    </button>
                    <button type="button" class="button rpsfw-ppcp-reset-3ds" style="margin-left: 10px;">
                        <?php esc_html_e( 'Reset to Defaults', 'restore-paypal-standard-for-woocommerce' ); ?>
                    </button>
                    
                    <?php if ( ! empty( $data['description'] ) ) : ?>
                        <p style="margin: 10px 0;"><?php echo wp_kses_post( $data['description'] ); ?></p>
                    <?php endif; ?>
                    
                    <!-- Hidden fields for storing rules -->
                    <div class="rpsfw-3ds-rules-storage" style="display: none;">
                        <?php foreach ( $rules as $status_key => $action ) : ?>
                            <input type="hidden" 
                                   name="<?php echo esc_attr( $field_key ); ?>[<?php echo esc_attr( $status_key ); ?>]" 
                                   value="<?php echo esc_attr( $action ); ?>"
                                   data-status="<?php echo esc_attr( $status_key ); ?>"
                                   data-default="<?php echo esc_attr( isset( $default_rules[ $status_key ] ) ? $default_rules[ $status_key ] : 'reject' ); ?>" />
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Modal container (will be populated by JavaScript) -->
                    <div id="rpsfw-3ds-modal" style="display: none;"></div>
                </fieldset>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Validate 3DS action rules field.
     *
     * @param string $key Field key.
     * @param mixed  $value Posted value.
     * @return array
     */
    public function validate_3ds_action_rules_field( $key, $value ) {
        // Check if reset was requested
        if ( isset( $_POST['woocommerce_rpsfw_paypal_commerce_3ds_action_rules_reset'] ) ) {
            // Return empty array to clear all custom rules and use defaults
            return array();
        }
        
        // If value is not an array, return empty array
        if ( ! is_array( $value ) ) {
            return array();
        }

        // Sanitize each rule
        $sanitized = array();
        $valid_actions = array( 'accept', 'reject', 'review' );

        foreach ( $value as $status_key => $action ) {
            $status_key = sanitize_text_field( $status_key );
            $action = sanitize_text_field( $action );

            // Only keep valid actions
            if ( in_array( $action, $valid_actions, true ) ) {
                $sanitized[ $status_key ] = $action;
            }
        }

        return $sanitized;
    }

    /**
     * Display webhook status and management buttons.
     */
    public function display_webhook_status() {
        $env = $this->testmode ? 'sandbox' : 'live';
        $webhook_status = $this->webhooks->get_webhook_status( $env );
        $is_connected = $this->is_connected();
        
        // Check if running on localhost
        $site_url = get_site_url();
        $is_localhost = ( strpos( $site_url, 'localhost' ) !== false || strpos( $site_url, '127.0.0.1' ) !== false || strpos( $site_url, '.local' ) !== false );
        
        if ( $is_localhost ) {
            ?>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                <span class="dashicons dashicons-warning" style="color: #dc3232; font-size: 20px;"></span>
                <div>
                    <strong style="color: #dc3232;"><?php esc_html_e( 'Webhooks cannot be configured on localhost.', 'restore-paypal-standard-for-woocommerce' ); ?></strong>
                    <p style="margin: 5px 0 0 0; color: #666;">
                        <?php esc_html_e( 'PayPal webhooks require a publicly accessible URL. Please deploy your site to a live server to configure webhooks.', 'restore-paypal-standard-for-woocommerce' ); ?>
                    </p>
                </div>
            </div>
            <?php
            return;
        }
        
        if ( ! $is_connected ) {
            ?>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                <span class="dashicons dashicons-warning" style="color: #f0b849; font-size: 20px;"></span>
                <div>
                    <strong><?php esc_html_e( 'Not Available', 'restore-paypal-standard-for-woocommerce' ); ?></strong>
                    <p style="margin: 5px 0 0 0; color: #666;">
                        <?php esc_html_e( 'Connect your PayPal account first to configure webhooks.', 'restore-paypal-standard-for-woocommerce' ); ?>
                    </p>
                </div>
            </div>
            <?php
            return;
        }
        
        if ( $webhook_status['configured'] ) {
            ?>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                <span class="dashicons dashicons-yes-alt" style="color: #46b450; font-size: 20px;"></span>
                <div>
                    <strong><?php esc_html_e( 'Webhook Configured', 'restore-paypal-standard-for-woocommerce' ); ?></strong>
                    <p style="margin: 5px 0 0 0; color: #666;">
                        <?php
                        /* translators: %s: PayPal webhook ID. */
                        echo wp_kses_post( sprintf( __( 'Webhook ID: %s', 'restore-paypal-standard-for-woocommerce' ), '<code>' . esc_html( $webhook_status['webhook_id'] ) . '</code>' ) ); ?>
                    </p>
                </div>
            </div>
            <div style="margin-left: 30px; margin-bottom: 15px;">
                <p style="margin: 0 0 10px 0; color: #666;">
                    <strong><?php esc_html_e( 'Webhook URL:', 'restore-paypal-standard-for-woocommerce' ); ?></strong><br>
                    <code style="font-size: 12px;"><?php echo esc_html( $webhook_status['url'] ); ?></code>
                </p>
                <p style="margin: 0 0 10px 0; color: #666;">
                    <strong><?php esc_html_e( 'Environment:', 'restore-paypal-standard-for-woocommerce' ); ?></strong> <?php echo esc_html( ucfirst( $env ) ); ?>
                </p>
                <button type="button" class="button rpsfw-ppcp-check-webhook" data-env="<?php echo esc_attr( $env ); ?>">
                    <?php esc_html_e( 'Check Webhook', 'restore-paypal-standard-for-woocommerce' ); ?>
                </button>
                <button type="button" class="button rpsfw-ppcp-delete-webhook" data-env="<?php echo esc_attr( $env ); ?>" style="color: #b32d2e;">
                    <?php esc_html_e( 'Delete Webhook', 'restore-paypal-standard-for-woocommerce' ); ?>
                </button>
            </div>
            <?php
            // Show subscribed events info — only meaningful once a webhook is
            // actually configured. Hidden in the "not configured" state.
            ?>
            <details class="rpsfw-ppcp-events" style="margin-left: 30px; margin-top: 15px; padding: 10px 12px; background: #f9f9f9; border-left: 3px solid #0073aa;">
                <summary style="cursor: pointer; font-weight: 600; list-style: none; display: flex; align-items: center; justify-content: space-between;">
                    <span><?php esc_html_e( 'Subscribed Events', 'restore-paypal-standard-for-woocommerce' ); ?></span>
                    <span class="dashicons dashicons-arrow-down-alt2 rpsfw-ppcp-events__arrow" aria-hidden="true"></span>
                </summary>
                <ul style="margin: 8px 0 0 15px; color: #666;">
                    <?php
                    $subscribed_events = isset( $this->webhooks ) ? $this->webhooks->get_subscribed_events_with_labels() : array();
                    foreach ( $subscribed_events as $event_name => $event_label ) {
                        echo '<li><code>' . esc_html( $event_name ) . '</code> - ' . esc_html( $event_label ) . '</li>';
                    }
                    ?>
                </ul>
            </details>
            <style>
                .rpsfw-ppcp-events > summary::-webkit-details-marker { display: none; }
                .rpsfw-ppcp-events__arrow { transition: transform 0.2s ease; }
                .rpsfw-ppcp-events[open] .rpsfw-ppcp-events__arrow { transform: rotate(180deg); }
            </style>
            <?php
        } else {
            ?>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                <span class="dashicons dashicons-warning" style="color: #f0b849; font-size: 20px;"></span>
                <div>
                    <strong><?php esc_html_e( 'No Webhook Configured', 'restore-paypal-standard-for-woocommerce' ); ?></strong>
                    <p style="margin: 5px 0 0 0; color: #666;">
                        <?php esc_html_e( 'Create a webhook to receive notifications about refunds, disputes, and other events from PayPal.', 'restore-paypal-standard-for-woocommerce' ); ?>
                    </p>
                </div>
            </div>
            <div style="margin-left: 30px; margin-bottom: 15px;">
                <p style="margin: 0 0 10px 0; color: #666;">
                    <strong><?php esc_html_e( 'Webhook URL:', 'restore-paypal-standard-for-woocommerce' ); ?></strong><br>
                    <code style="font-size: 12px;"><?php echo esc_html( $webhook_status['url'] ); ?></code>
                </p>
                <p style="margin: 0 0 10px 0; color: #666;">
                    <strong><?php esc_html_e( 'Environment:', 'restore-paypal-standard-for-woocommerce' ); ?></strong> <?php echo esc_html( ucfirst( $env ) ); ?>
                </p>
                <button type="button" class="button button-primary rpsfw-ppcp-create-webhook" data-env="<?php echo esc_attr( $env ); ?>">
                    <?php esc_html_e( 'Create Webhook', 'restore-paypal-standard-for-woocommerce' ); ?>
                </button>
            </div>
            <?php
        }
    }
    public function display_connection_status_inline() {
        $env = $this->testmode ? 'sandbox' : 'live';
        $status = $this->get_connection_status();
        
        if ( $status && empty( $status['errors'] ) ) {
            // Connected successfully
            $reconnect_env = $env === 'live' ? 'sandbox' : 'live';
            ?>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                <span class="dashicons dashicons-yes-alt" style="color: #46b450; font-size: 20px;"></span>
                <div>
                    <strong><?php esc_html_e( 'Connected', 'restore-paypal-standard-for-woocommerce' ); ?></strong>
                    <?php if ( ! empty( $status['primary_email'] ) ) : ?>
                        <span style="color: #666;"> — <?php echo esc_html( $status['primary_email'] ); ?></span>
                    <?php endif; ?>
                    <?php if ( ! empty( $status['legal_name'] ) ) : ?>
                        <span style="color: #666;"> (<?php echo esc_html( $status['legal_name'] ); ?>)</span>
                    <?php endif; ?>
                </div>
            </div>
            <div style="margin-left: 30px;">
                <a href="#" class="rpsfw-ppcp-disconnect" style="color: #b32d2e; text-decoration: none;">
                    <?php esc_html_e( 'Disconnect account', 'restore-paypal-standard-for-woocommerce' ); ?>
                </a>
            </div>
            <?php
        } elseif ( $status && ! empty( $status['errors'] ) ) {
            // Connected but has errors
            ?>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                <span class="dashicons dashicons-warning" style="color: #dc3232; font-size: 20px;"></span>
                <div>
                    <strong style="color: #dc3232;"><?php esc_html_e( 'Connection Error', 'restore-paypal-standard-for-woocommerce' ); ?></strong>
                    <p style="margin: 5px 0 0 0; color: #666;">
                        <?php esc_html_e( 'There were errors with your PayPal connection.', 'restore-paypal-standard-for-woocommerce' ); ?>
                    </p>
                </div>
            </div>
            <div style="margin-left: 30px;">
                <a href="#" class="button button-primary rpsfw-ppcp-connect" data-env="<?php echo esc_attr( $env ); ?>">
                    <?php esc_html_e( 'Reconnect with PayPal', 'restore-paypal-standard-for-woocommerce' ); ?>
                </a>
            </div>
            <?php
        } else {
            // Not connected
            ?>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                <span class="dashicons dashicons-warning" style="color: #f0b849; font-size: 20px;"></span>
                <div>
                    <strong><?php esc_html_e( 'Not Connected', 'restore-paypal-standard-for-woocommerce' ); ?></strong>
                    <p style="margin: 5px 0 0 0; color: #666;">
                        <?php esc_html_e( 'Connect your PayPal account to start accepting payments.', 'restore-paypal-standard-for-woocommerce' ); ?>
                    </p>
                </div>
            </div>
            <div style="margin-left: 30px;">
                <a href="#" class="button button-primary rpsfw-ppcp-connect" data-env="<?php echo esc_attr( $env ); ?>">
                    <?php esc_html_e( 'Connect with PayPal', 'restore-paypal-standard-for-woocommerce' ); ?>
                </a>
            </div>
            <?php
        }
    }

    /**
     * Display connection status.
     */
    public function display_connection_status() {
        $env = $this->testmode ? 'sandbox' : 'live';
        $status = $this->get_connection_status();
        
        if ( $status && empty( $status['errors'] ) ) {
            // Connected successfully
            $reconnect_env = $env === 'live' ? 'sandbox' : 'live';
            ?>
            <div class="notice inline notice-success" style="margin: 20px 0;">
                <p>
                    <strong><?php esc_html_e( 'Connected to PayPal', 'restore-paypal-standard-for-woocommerce' ); ?></strong><br>
                    <?php if ( ! empty( $status['legal_name'] ) ) : ?>
                        <strong><?php echo esc_html( $status['legal_name'] ); ?></strong><br>
                    <?php endif; ?>
                    <?php if ( ! empty( $status['primary_email'] ) ) : ?>
                        <?php echo esc_html( $status['primary_email'] ); ?> — <?php esc_html_e( 'Administrator (Owner)', 'restore-paypal-standard-for-woocommerce' ); ?>
                    <?php endif; ?>
                </p>
            </div>
            <p>
                <?php
                /* translators: %s: environment name (live or sandbox). */
                echo wp_kses_post( sprintf( __( 'Your PayPal account is connected in %s mode.', 'restore-paypal-standard-for-woocommerce' ), '<strong>' . esc_html( $env ) . '</strong>' ) ); ?>
                <a href="#" class="rpsfw-ppcp-connect" data-env="<?php echo esc_attr( $reconnect_env ); ?>">
                    <?php
                    /* translators: %s: environment name (live or sandbox). */
                    echo wp_kses_post( sprintf( __( 'Connect in %s mode', 'restore-paypal-standard-for-woocommerce' ), '<strong>' . esc_html( $reconnect_env ) . '</strong>' ) ); ?>
                </a>
                <?php esc_html_e( 'or', 'restore-paypal-standard-for-woocommerce' ); ?>
                <a href="#" class="rpsfw-ppcp-disconnect" style="color: #b32d2e;">
                    <?php esc_html_e( 'disconnect this account', 'restore-paypal-standard-for-woocommerce' ); ?>
                </a>
            </p>
            <?php
        } elseif ( $status && ! empty( $status['errors'] ) ) {
            // Connected but has errors
            ?>
            <div class="notice inline notice-error" style="margin: 20px 0;">
                <p>
                    <strong><?php esc_html_e( 'Connection Error', 'restore-paypal-standard-for-woocommerce' ); ?></strong><br>
                    <?php esc_html_e( 'There were errors with your PayPal connection. Please reconnect your account.', 'restore-paypal-standard-for-woocommerce' ); ?>
                </p>
                <ul>
                    <?php foreach ( $status['errors'] as $error ) : ?>
                        <li><?php echo esc_html( $error ); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <p>
                <a href="#" class="button button-primary rpsfw-ppcp-connect" data-env="<?php echo esc_attr( $env ); ?>">
                    <?php esc_html_e( 'Reconnect with PayPal', 'restore-paypal-standard-for-woocommerce' ); ?>
                </a>
            </p>
            <?php
        } else {
            // Not connected
            ?>
            <div class="notice inline notice-warning" style="margin: 20px 0;">
                <p>
                    <strong><?php esc_html_e( 'Not Connected', 'restore-paypal-standard-for-woocommerce' ); ?></strong><br>
                    <?php esc_html_e( 'Connect your PayPal account to start accepting payments.', 'restore-paypal-standard-for-woocommerce' ); ?>
                </p>
            </div>
            <p>
                <a href="#" class="button button-primary rpsfw-ppcp-connect" data-env="<?php echo esc_attr( $env ); ?>">
                    <?php esc_html_e( 'Connect with PayPal', 'restore-paypal-standard-for-woocommerce' ); ?>
                </a>
            </p>
            <?php
        }
    }

    /**
     * Process the payment.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        // Record the mode this payment is being taken in, before anything else
        // can branch. Everything that later interprets this order — refunds,
        // dashboard links, webhook routing — reads this instead of the gateway's
        // current setting, so switching the store test <-> live never
        // reinterprets an existing order. Stamped ahead of the override filter
        // so subscription orders are covered too.
        if ( $order ) {
            rpsfw_set_order_payment_mode( $order, rpsfw_get_gateway_mode( $this->id ) );
        }

        // Allow extensions (WooCommerce Subscriptions integration) to take over
        // the flow when the order requires a different processing path. The
        // subscription integration uses this for subscription carts.
        $override = apply_filters( 'rpsfw_ppcp_process_payment_override', null, $order );
        if ( is_array( $override ) ) {
            return $override;
        }

        // EXTENSIVE DEBUG LOGGING
        self::log( '========== PROCESS PAYMENT START ==========' );
        self::log( 'Order ID: ' . $order_id );
        self::log( 'REQUEST_METHOD: ' . $_SERVER['REQUEST_METHOD'] );
        self::log( 'POST data keys: ' . implode( ', ', array_keys( $_POST ) ) );
        self::log( 'POST paypal_order_id: ' . ( isset( $_POST['paypal_order_id'] ) ? $_POST['paypal_order_id'] : 'NOT SET' ) );
        
        // Check all possible sources
        $paypal_order_id = '';
        $source = 'none';
        
        // 1. POST data (classic checkout or Blocks paymentMethodData)
        if ( ! empty( $_POST['paypal_order_id'] ) ) {
            $paypal_order_id = sanitize_text_field( $_POST['paypal_order_id'] );
            $source = 'POST';
            self::log( 'Found in POST: ' . $paypal_order_id );
        }
        
        // 2. Check order meta (Blocks checkout stores payment data here)
        if ( empty( $paypal_order_id ) ) {
            $meta_order_id = $order->get_meta( '_paypal_order_id' );
            self::log( 'Order meta _paypal_order_id: ' . ( $meta_order_id ? $meta_order_id : 'NOT SET' ) );
            if ( ! empty( $meta_order_id ) ) {
                $paypal_order_id = sanitize_text_field( $meta_order_id );
                $source = 'order_meta';
            }
        }
        
        // 3. Session
        if ( empty( $paypal_order_id ) ) {
            self::log( 'WC()->session exists: ' . ( WC()->session ? 'yes' : 'no' ) );
            if ( WC()->session ) {
                $session_id = WC()->session->get( 'paypal_commerce_order_id' );
                self::log( 'Session paypal_commerce_order_id: ' . ( $session_id ? $session_id : 'NOT SET' ) );
                if ( ! empty( $session_id ) ) {
                    $paypal_order_id = sanitize_text_field( $session_id );
                    $source = 'session';
                }
            }
        }
        
        // 4. Transient (logged in user)
        if ( empty( $paypal_order_id ) ) {
            $customer_id = get_current_user_id();
            self::log( 'Customer ID: ' . $customer_id );
            if ( $customer_id ) {
                $transient_key = 'paypal_commerce_order_' . $customer_id;
                $transient_val = get_transient( $transient_key );
                self::log( 'Transient ' . $transient_key . ': ' . ( $transient_val ? $transient_val : 'NOT SET' ) );
                if ( ! empty( $transient_val ) ) {
                    $paypal_order_id = sanitize_text_field( $transient_val );
                    $source = 'transient_user';
                }
            }
        }
        
        // 5. Transient (guest)
        if ( empty( $paypal_order_id ) ) {
            $guest_id = isset( $_COOKIE['woocommerce_cart_hash'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['woocommerce_cart_hash'] ) ) : '';
            // Validate cart hash format (WooCommerce uses md5 hashes)
            if ( $guest_id && ! preg_match( '/^[a-f0-9]{32}$/', $guest_id ) ) {
                self::log( 'Invalid cart hash format, ignoring: ' . $guest_id, 'warning' );
                $guest_id = '';
            }
            self::log( 'Guest cart hash: ' . ( $guest_id ? $guest_id : 'NOT SET' ) );
            if ( $guest_id ) {
                $transient_key = 'paypal_commerce_order_guest_' . $guest_id;
                $transient_val = get_transient( $transient_key );
                self::log( 'Transient ' . $transient_key . ': ' . ( $transient_val ? $transient_val : 'NOT SET' ) );
                if ( ! empty( $transient_val ) ) {
                    $paypal_order_id = sanitize_text_field( $transient_val );
                    $source = 'transient_guest';
                }
            }
        }

        self::log( 'Final PayPal Order ID: ' . ( $paypal_order_id ? $paypal_order_id : 'EMPTY' ) );
        self::log( 'Source: ' . $source );
        self::log( '===========================================' );

        if ( empty( $paypal_order_id ) ) {
            self::log( 'FAILURE: PayPal order ID is missing from all sources', 'error' );
            wc_add_notice( __( 'Payment error: PayPal order ID is missing. Please try again.', 'restore-paypal-standard-for-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

        // Capture the order
        $env = $this->testmode ? 'sandbox' : 'live';
        $onboarding = $this->get_option( 'ppcp_onboarding', array() );

        if ( empty( $onboarding[ $env ] ) ) {
            self::log( 'FAILURE: No onboarding data for env: ' . $env, 'error' );
            wc_add_notice( __( 'Payment error: PayPal connection not found.', 'restore-paypal-standard-for-woocommerce' ), 'error' );
            return array( 'result' => 'failure' );
        }

        self::log( 'Capturing order with seller_id: ' . $onboarding[ $env ]['seller_id'] );
        
        // Validate PayPal order amount before capture/authorize (if enabled)
        if ( 'yes' === $this->get_option( 'validate_order_amount', 'yes' ) ) {
            $expected_amount = $order->get_total();
            $expected_currency = $order->get_currency();
            
            $validation_result = $this->api->validate_order_amount(
                $env,
                $onboarding[ $env ],
                $paypal_order_id,
                $expected_amount,
                $expected_currency
            );
            
            if ( ! $validation_result['valid'] ) {
                self::log( 'SECURITY: Order amount validation failed - ' . $validation_result['message'], 'error' );
                $order->update_status( 'failed', $validation_result['message'] );
                wc_add_notice( $validation_result['message'], 'error' );
                return array( 'result' => 'failure' );
            }
            
            if ( ! empty( $validation_result['skipped'] ) ) {
                self::log( 'Order amount validation was skipped: ' . $validation_result['message'] );
            }
        }
        
        // Get payment action
        $payment_action = $this->get_option( 'payment_action', 'capture' );
        $intent = ( $payment_action === 'authorize' ) ? 'authorize' : 'capture';
        
        if ( $intent === 'capture' ) {
            $capture_result = $this->api->capture_order( $env, $onboarding[ $env ], $paypal_order_id );
            self::log( 'Capture result: ' . print_r( $capture_result, true ) );

            if ( ! $capture_result || empty( $capture_result['status'] ) ) {
                self::log( 'FAILURE: Capture failed', 'error' );
                wc_add_notice( __( 'Payment error: Failed to capture payment.', 'restore-paypal-standard-for-woocommerce' ), 'error' );
                return array( 'result' => 'failure' );
            }

            if ( $capture_result['status'] === 'COMPLETED' ) {
                /*
                 * 3DS validation is parked while the 3D Secure settings are
                 * hidden (see get_advanced_fields()). Without ACDC we can't
                 * control 3DS anyway, and PayPal handles it on its side. Leave
                 * intact for when ACDC support is added; re-enable alongside
                 * the 3DS settings fields.
                 *
                require_once dirname( __FILE__ ) . '/class-paypal-commerce-3ds.php';
                $threeds = new WC_PayPal_Commerce_3DS( $this );

                try {
                    $threeds_result = $threeds->validate_order( $capture_result, $order );
                    self::log( '3DS validation result: ' . print_r( $threeds_result, true ) );
                } catch ( Exception $e ) {
                    // 3DS validation failed - void the payment
                    self::log( '3DS validation failed: ' . $e->getMessage(), 'error' );
                    $order->update_status( 'failed', sprintf( __( '3D Secure validation failed: %s', 'restore-paypal-standard-for-woocommerce' ), $e->getMessage() ) );
                    wc_add_notice( $e->getMessage(), 'error' );
                    return array( 'result' => 'failure' );
                }
                */

                $transaction_id = ! empty( $capture_result['transaction_id'] ) ? $capture_result['transaction_id'] : $paypal_order_id;
                
                // Store capture_id for refunds (use capture_id if available, otherwise use transaction_id)
                $capture_id = ! empty( $capture_result['capture_id'] ) ? $capture_result['capture_id'] : $transaction_id;
                $order->update_meta_data( '_paypal_capture_id', $capture_id );
                $order->save();
                
                $order->payment_complete( $transaction_id );
                /* translators: %s: PayPal transaction ID. */
                $order->add_order_note( sprintf( __( 'PayPal Commerce payment completed. Transaction ID: %s', 'restore-paypal-standard-for-woocommerce' ), $transaction_id ) );

                self::log( 'SUCCESS: Payment completed. Transaction ID: ' . $transaction_id . ', Capture ID: ' . $capture_id );

                // Clear storage
                if ( WC()->session ) {
                    WC()->session->__unset( 'paypal_commerce_order_id' );
                    WC()->session->__unset( 'paypal_commerce_order_approved' );
                }
                $customer_id = get_current_user_id();
                if ( $customer_id ) {
                    delete_transient( 'paypal_commerce_order_' . $customer_id );
                }
                $guest_id = isset( $_COOKIE['woocommerce_cart_hash'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['woocommerce_cart_hash'] ) ) : '';
                // Validate cart hash format
                if ( $guest_id && ! preg_match( '/^[a-f0-9]{32}$/', $guest_id ) ) {
                    $guest_id = '';
                }
                if ( $guest_id ) {
                    delete_transient( 'paypal_commerce_order_guest_' . $guest_id );
                }

                return array(
                    'result'   => 'success',
                    'redirect' => $this->get_return_url( $order ),
                );
            } else {
                /* translators: %s: PayPal payment status. */
                $order->update_status( 'failed', sprintf( __( 'PayPal payment failed. Status: %s', 'restore-paypal-standard-for-woocommerce' ), $capture_result['status'] ) );
                wc_add_notice( __( 'Payment error: Payment could not be completed.', 'restore-paypal-standard-for-woocommerce' ), 'error' );
                self::log( 'FAILURE: Status was ' . $capture_result['status'], 'error' );
                return array( 'result' => 'failure' );
            }
        } else {
            // Authorize only
            $authorize_result = $this->api->authorize_order( $env, $onboarding[ $env ], $paypal_order_id );
            self::log( 'Authorize result: ' . print_r( $authorize_result, true ) );

            if ( ! $authorize_result || empty( $authorize_result['status'] ) ) {
                self::log( 'FAILURE: Authorization failed', 'error' );
                wc_add_notice( __( 'Payment error: Failed to authorize payment.', 'restore-paypal-standard-for-woocommerce' ), 'error' );
                return array( 'result' => 'failure' );
            }

            if ( $authorize_result['status'] === 'COMPLETED' ) {
                /*
                 * 3DS validation is parked while the 3D Secure settings are
                 * hidden (see get_advanced_fields()). Re-enable alongside the
                 * 3DS settings fields when ACDC support is added.
                 *
                require_once dirname( __FILE__ ) . '/class-paypal-commerce-3ds.php';
                $threeds = new WC_PayPal_Commerce_3DS( $this );

                try {
                    $threeds_result = $threeds->validate_order( $authorize_result, $order );
                    self::log( '3DS validation result: ' . print_r( $threeds_result, true ) );
                } catch ( Exception $e ) {
                    // 3DS validation failed - void the authorization
                    self::log( '3DS validation failed: ' . $e->getMessage(), 'error' );
                    $order->update_status( 'failed', sprintf( __( '3D Secure validation failed: %s', 'restore-paypal-standard-for-woocommerce' ), $e->getMessage() ) );
                    wc_add_notice( $e->getMessage(), 'error' );
                    return array( 'result' => 'failure' );
                }
                */

                $authorization_id = ! empty( $authorize_result['authorization_id'] ) ? $authorize_result['authorization_id'] : $paypal_order_id;
                
                // Store authorization ID
                $order->update_meta_data( '_paypal_authorization_id', $authorization_id );
                $order->update_meta_data( '_paypal_order_id', $paypal_order_id );
                $order->set_transaction_id( $authorization_id );
                
                // Set order to on-hold for manual capture
                /* translators: %s: PayPal authorization ID. */
                $order->update_status( 'on-hold', sprintf( __( 'PayPal payment authorized (ID: %s). Capture the payment to complete the order.', 'restore-paypal-standard-for-woocommerce' ), $authorization_id ) );
                $order->save();

                self::log( 'SUCCESS: Payment authorized. Authorization ID: ' . $authorization_id );

                // Clear storage
                if ( WC()->session ) {
                    WC()->session->__unset( 'paypal_commerce_order_id' );
                    WC()->session->__unset( 'paypal_commerce_order_approved' );
                }
                $customer_id = get_current_user_id();
                if ( $customer_id ) {
                    delete_transient( 'paypal_commerce_order_' . $customer_id );
                }
                $guest_id = isset( $_COOKIE['woocommerce_cart_hash'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['woocommerce_cart_hash'] ) ) : '';
                // Validate cart hash format
                if ( $guest_id && ! preg_match( '/^[a-f0-9]{32}$/', $guest_id ) ) {
                    $guest_id = '';
                }
                if ( $guest_id ) {
                    delete_transient( 'paypal_commerce_order_guest_' . $guest_id );
                }

                return array(
                    'result'   => 'success',
                    'redirect' => $this->get_return_url( $order ),
                );
            } else {
                /* translators: %s: PayPal authorization status. */
                $order->update_status( 'failed', sprintf( __( 'PayPal authorization failed. Status: %s', 'restore-paypal-standard-for-woocommerce' ), $authorize_result['status'] ) );
                wc_add_notice( __( 'Payment error: Payment could not be authorized.', 'restore-paypal-standard-for-woocommerce' ), 'error' );
                self::log( 'FAILURE: Status was ' . $authorize_result['status'], 'error' );
                return array( 'result' => 'failure' );
            }
        }
    }

    /**
     * Logging method.
     *
     * @param string $message Log message.
     * @param string $level   Optional. Default 'info'.
     */
    public static function log( $message, $level = 'info' ) {
        if ( self::$log_enabled ) {
            if ( empty( self::$log ) ) {
                self::$log = wc_get_logger();
            }
            self::$log->log( $level, $message, array( 'source' => 'rpsfw-paypal-commerce' ) );
        }
    }

    /**
     * Capture an authorized payment.
     *
     * @param int    $order_id Order ID.
     * @param float  $amount   Amount to capture.
     * @return bool True on success, false on failure.
     */
    public function capture_payment( $order_id, $amount = null ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return false;
        }

        $authorization_id = $order->get_meta( '_paypal_authorization_id' );

        if ( empty( $authorization_id ) ) {
            $order->add_order_note( __( 'PayPal capture failed: No authorization ID found.', 'restore-paypal-standard-for-woocommerce' ) );
            return false;
        }

        // Use order total if amount not specified
        if ( is_null( $amount ) ) {
            $amount = $order->get_total();
        }

        $env = $this->testmode ? 'sandbox' : 'live';
        $onboarding = $this->get_option( 'ppcp_onboarding', array() );

        if ( empty( $onboarding[ $env ]['seller_id'] ) ) {
            $order->add_order_note( __( 'PayPal capture failed: Not connected to PayPal.', 'restore-paypal-standard-for-woocommerce' ) );
            return false;
        }

        self::log( 'Capturing authorized payment. Authorization ID: ' . $authorization_id . ', Amount: ' . $amount );

        $capture_result = $this->api->capture_authorization( $env, $onboarding[ $env ], $authorization_id, $amount, get_woocommerce_currency() );

        if ( ! $capture_result || empty( $capture_result['status'] ) ) {
            $order->add_order_note( __( 'PayPal capture failed: API error.', 'restore-paypal-standard-for-woocommerce' ) );
            self::log( 'Capture failed for authorization ID: ' . $authorization_id, 'error' );
            return false;
        }

        if ( $capture_result['status'] === 'COMPLETED' ) {
            $capture_id = ! empty( $capture_result['capture_id'] ) ? $capture_result['capture_id'] : $authorization_id;
            
            // Store capture ID in order meta for refunds
            $order->update_meta_data( '_paypal_capture_id', $capture_id );
            $order->save();
            
            $order->payment_complete( $capture_id );
            /* translators: %s: PayPal capture ID. */
            $order->add_order_note( sprintf( __( 'PayPal payment captured. Capture ID: %s', 'restore-paypal-standard-for-woocommerce' ), $capture_id ) );
            
            self::log( 'Payment captured successfully. Capture ID: ' . $capture_id );
            
            return true;
        } else {
            /* translators: %s: PayPal capture status. */
            $order->add_order_note( sprintf( __( 'PayPal capture failed. Status: %s', 'restore-paypal-standard-for-woocommerce' ), $capture_result['status'] ) );
            self::log( 'Capture failed with status: ' . $capture_result['status'], 'error' );
            return false;
        }
    }

    /**
     * Process a refund.
     *
     * @param int    $order_id Order ID.
     * @param float  $amount   Refund amount.
     * @param string $reason   Refund reason.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            self::log( 'Refund failed: Order not found. Order ID: ' . $order_id, 'error' );
            return new WP_Error( 'invalid_order', __( 'Order not found.', 'restore-paypal-standard-for-woocommerce' ) );
        }

        // Resolve the PayPal capture ID to refund.
        //   1. One-off orders store it in _paypal_capture_id.
        //   2. Subscription PARENT orders store the PayPal subscription id
        //      (I-...) as the WC transaction id and the real first-sale
        //      capture id in _rpsfw_ppcp_last_payment_id.
        //   3. One-off (fallback) and subscription RENEWAL orders store the
        //      capture/sale id as the WC transaction id.
        $capture_id = $order->get_meta( '_paypal_capture_id' );

        if ( empty( $capture_id ) ) {
            $capture_id = $order->get_meta( '_rpsfw_ppcp_last_payment_id' );
        }

        if ( empty( $capture_id ) ) {
            $capture_id = $order->get_transaction_id();
        }

        if ( empty( $capture_id ) ) {
            self::log( 'Refund failed: No capture/transaction ID found. Order ID: ' . $order_id, 'error' );
            return new WP_Error( 'no_transaction_id', __( 'No PayPal transaction ID found for this order.', 'restore-paypal-standard-for-woocommerce' ) );
        }

        // A PayPal subscription id (I-...) is NOT a refundable capture. This
        // happens on a subscription parent order before its first
        // PAYMENT.SALE.COMPLETED webhook has recorded the real sale id.
        if ( 0 === strpos( $capture_id, 'I-' ) ) {
            self::log( 'Refund failed: resolved ID is a subscription id, not a capture. Order ID: ' . $order_id . ', ID: ' . $capture_id, 'error' );
            return new WP_Error(
                'no_capture_id',
                __( 'This subscription order has no captured PayPal payment to refund yet. Refund it from your PayPal account, or wait for the payment to be confirmed.', 'restore-paypal-standard-for-woocommerce' )
            );
        }

        // Validate amount
        if ( is_null( $amount ) || $amount <= 0 ) {
            $amount = $order->get_total();
        }

        // Get environment and onboarding data. The capture id above belongs to
        // the account this order was PAID in, so the refund has to go to that
        // same account — not to whichever mode the store happens to be set to
        // now. Sending a live capture id to sandbox (or the reverse) just fails,
        // because the id does not exist in the other account.
        $order_mode = rpsfw_get_order_payment_mode( $order );
        $env        = rpsfw_payment_mode_to_ppcp_env( $order_mode );
        $onboarding = $this->get_option( 'ppcp_onboarding', array() );

        if ( empty( $onboarding[ $env ]['seller_id'] ) ) {
            self::log( 'Refund failed: not connected to PayPal in ' . $env . ' mode, which is where order #' . $order_id . ' was paid.', 'error' );
            return new WP_Error(
                'not_connected',
                sprintf(
                    /* translators: %1$s: mode the order was paid in, e.g. "Test" or "Live". */
                    __( 'This order was paid in %1$s mode, but PayPal Commerce is not connected in %1$s mode. Connect that account to refund this order.', 'restore-paypal-standard-for-woocommerce' ),
                    rpsfw_payment_mode_label( $order_mode )
                )
            );
        }

        self::log( sprintf( 
            'Processing refund for Order #%d. Capture ID: %s, Amount: %s %s, Reason: %s',
            $order_id,
            $capture_id,
            $amount,
            $order->get_currency(),
            $reason
        ) );

        // Call the API to process the refund
        $refund_result = $this->api->refund_capture(
            $env,
            $onboarding[ $env ],
            $capture_id,
            $amount,
            $order->get_currency(),
            $reason
        );

        // Handle API errors
        if ( ! $refund_result ) {
            self::log( 'Refund failed: API returned no response. Order ID: ' . $order_id, 'error' );
            return new WP_Error( 'api_error', __( 'PayPal refund failed. Please try again or refund manually through PayPal.', 'restore-paypal-standard-for-woocommerce' ) );
        }

        // Check for error message in response
        if ( isset( $refund_result['success'] ) && $refund_result['success'] === false ) {
            $error_message = ! empty( $refund_result['message'] ) ? $refund_result['message'] : __( 'Unknown error', 'restore-paypal-standard-for-woocommerce' );
            self::log( 'Refund failed: ' . $error_message . '. Order ID: ' . $order_id, 'error' );
            return new WP_Error( 'refund_failed', $error_message );
        }

        // Check for successful refund
        if ( ! empty( $refund_result['refund_id'] ) ) {
            $refund_id = $refund_result['refund_id'];
            $refund_status = ! empty( $refund_result['status'] ) ? $refund_result['status'] : 'COMPLETED';

            // Add order note
            $note = sprintf(
                /* translators: %1$s: refund ID, %2$s: amount, %3$s: currency, %4$s: refund status. */
                __( 'PayPal refund processed. Refund ID: %1$s, Amount: %2$s %3$s, Status: %4$s', 'restore-paypal-standard-for-woocommerce' ),
                $refund_id,
                $amount,
                $order->get_currency(),
                $refund_status
            );

            if ( ! empty( $reason ) ) {
                /* translators: %s: refund reason. */
                $note .= sprintf( __( ', Reason: %s', 'restore-paypal-standard-for-woocommerce' ), $reason );
            }

            $order->add_order_note( $note );

            // Store refund ID in order meta
            $existing_refunds = $order->get_meta( '_paypal_refund_ids' );
            if ( empty( $existing_refunds ) ) {
                $existing_refunds = array();
            }
            $existing_refunds[] = array(
                'refund_id' => $refund_id,
                'amount'    => $amount,
                'status'    => $refund_status,
                'date'      => current_time( 'mysql' ),
            );
            $order->update_meta_data( '_paypal_refund_ids', $existing_refunds );
            $order->save();

            self::log( sprintf( 
                'Refund successful for Order #%d. Refund ID: %s, Status: %s',
                $order_id,
                $refund_id,
                $refund_status
            ) );

            return true;
        }

        // Fallback error
        self::log( 'Refund failed: Unexpected response. Order ID: ' . $order_id, 'error' );
        return new WP_Error( 'refund_failed', __( 'PayPal refund failed. Please try again or refund manually through PayPal.', 'restore-paypal-standard-for-woocommerce' ) );
    }

    /**
     * Load admin scripts.
     */
    public function admin_scripts() {
        $screen = get_current_screen();
        $screen_id = $screen ? $screen->id : '';

        // Only load on WooCommerce settings pages
        if ( 'woocommerce_page_wc-settings' !== $screen_id ) {
            return;
        }

        // Only load when we're on our settings page
        $section = isset( $_GET['section'] ) ? sanitize_title( $_GET['section'] ) : '';
        if ( $section !== 'rpsfw_paypal_commerce' ) {
            return;
        }

        // Version by file modification time so edits always bust the
        // browser/WordPress cache (RPSFW_VERSION alone doesn't change when we
        // edit the JS/CSS directly, so cached copies would otherwise persist).
        $admin_js_path  = RPSFW_PLUGIN_DIR . 'assets/js/paypal-commerce-admin.js';
        $admin_css_path = RPSFW_PLUGIN_DIR . 'assets/css/paypal-commerce-admin.css';
        $admin_js_ver   = file_exists( $admin_js_path ) ? (string) filemtime( $admin_js_path ) : RPSFW_VERSION;
        $admin_css_ver  = file_exists( $admin_css_path ) ? (string) filemtime( $admin_css_path ) : RPSFW_VERSION;

        // Enqueue admin script
        wp_enqueue_script(
            'rpsfw-paypal-commerce-admin',
            RPSFW_PLUGIN_URL . 'assets/js/paypal-commerce-admin.js',
            array( 'jquery', 'jquery-ui-sortable' ),
            $admin_js_ver,
            true
        );

        // Enqueue admin styles
        wp_enqueue_style(
            'rpsfw-paypal-commerce-admin',
            RPSFW_PLUGIN_URL . 'assets/css/paypal-commerce-admin.css',
            array(),
            $admin_css_ver
        );

        // Localize script
        wp_localize_script(
            'rpsfw-paypal-commerce-admin',
            'rpsfwPayPalCommerce',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'plugin_url' => RPSFW_PLUGIN_URL,
                'onboarding_nonce' => wp_create_nonce( 'rpsfw-ppcp-onboarding' ),
                'disconnect_nonce' => wp_create_nonce( 'rpsfw-ppcp-disconnect' ),
                'webhook_nonce' => wp_create_nonce( 'rpsfw-ppcp-webhook' ),
                'strings' => array(
                    'connecting' => __( 'Connecting...', 'restore-paypal-standard-for-woocommerce' ),
                    'disconnecting' => __( 'Disconnecting...', 'restore-paypal-standard-for-woocommerce' ),
                    /* translators: %s: environment name (live or sandbox). */
                    'confirm_disconnect' => __( 'Are you sure you want to disconnect your PayPal account for %s?', 'restore-paypal-standard-for-woocommerce' ),
                    'creating_webhook' => __( 'Creating webhook...', 'restore-paypal-standard-for-woocommerce' ),
                    'deleting_webhook' => __( 'Deleting webhook...', 'restore-paypal-standard-for-woocommerce' ),
                    'checking_webhook' => __( 'Checking webhook...', 'restore-paypal-standard-for-woocommerce' ),
                    'confirm_delete_webhook' => __( 'Are you sure you want to delete this webhook?', 'restore-paypal-standard-for-woocommerce' ),
                    'learn_more_3ds' => __( 'Learn more about 3D Secure', 'restore-paypal-standard-for-woocommerce' ),
                    'hide_details' => __( 'Hide details', 'restore-paypal-standard-for-woocommerce' ),
                    /* translators: %s: environment name (live or sandbox). */
                    'switching_mode' => __( 'Switching to %s', 'restore-paypal-standard-for-woocommerce' ),
                    'saving_settings' => __( 'Saving settings...', 'restore-paypal-standard-for-woocommerce' ),
                ),
            )
        );
    }

    /**
     * Handle return from PayPal onboarding.
     */
    public function handle_onboarding_return() {
        if ( empty( $_GET['ppcp_connected'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $env = $this->testmode ? 'sandbox' : 'live';
        $onboarding = $this->get_option( 'ppcp_onboarding', array() );

        if ( empty( $onboarding[ $env ]['tracking_id'] ) ) {
            return;
        }

        // Try to get seller_id
        $seller_data = $this->api->find_seller_id( $env, $onboarding[ $env ] );
        
        if ( $seller_data && ! empty( $seller_data['seller_id'] ) ) {
            $onboarding[ $env ]['seller_id'] = sanitize_text_field( $seller_data['seller_id'] );
            $this->update_option( 'ppcp_onboarding', $onboarding );
            
            self::log( 'Onboarding completed. Seller ID: ' . $seller_data['seller_id'] );
            
            // Add success notice
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>';
                echo esc_html__( 'Successfully connected to PayPal!', 'restore-paypal-standard-for-woocommerce' );
                echo '</p></div>';
            } );
        }
    }

    /**
     * Add capture payment action to order actions dropdown.
     *
     * @param array $actions Order actions.
     * @return array
     */
    public function add_capture_order_action( $actions ) {
        global $theorder;

        if ( ! is_object( $theorder ) ) {
            return $actions;
        }

        // Only add action if order was paid with this gateway and has an authorization ID
        if ( $theorder->get_payment_method() === $this->id && $theorder->get_meta( '_paypal_authorization_id' ) ) {
            // Only show if order is on-hold (not yet captured)
            if ( $theorder->has_status( 'on-hold' ) ) {
                $actions['rpsfw_paypal_commerce_capture_payment'] = __( 'Capture PayPal payment', 'restore-paypal-standard-for-woocommerce' );
            }
        }

        return $actions;
    }

    /**
     * Process capture payment order action.
     *
     * @param WC_Order $order Order object.
     */
    public function process_capture_order_action( $order ) {
        $result = $this->capture_payment( $order->get_id() );

        if ( $result ) {
            $order->add_order_note( __( 'Payment captured successfully via order action.', 'restore-paypal-standard-for-woocommerce' ) );
        } else {
            $order->add_order_note( __( 'Failed to capture payment via order action.', 'restore-paypal-standard-for-woocommerce' ) );
        }
    }
}
