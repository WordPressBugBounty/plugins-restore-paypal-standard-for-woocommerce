<?php
/**
 * Stripe Payment Gateway.
 *
 * Provides a Stripe Payment Gateway for WooCommerce.
 *
 * @class       RPSFW_Gateway_Stripe
 * @extends     WC_Payment_Gateway
 * @version     1.0.0
 * @package     RestorePayPalStandard
 */

defined( 'ABSPATH' ) || exit;

require_once RPSFW_PLUGIN_DIR . 'includes/stripe/stripe-settings.php';
require_once RPSFW_PLUGIN_DIR . 'includes/stripe/stripe-connect.php';
require_once RPSFW_PLUGIN_DIR . 'includes/stripe/stripe-ajax-handlers.php';
require_once RPSFW_PLUGIN_DIR . 'includes/stripe/class-stripe-api.php';
require_once RPSFW_PLUGIN_DIR . 'includes/stripe/stripe-webhook-handler.php';
require_once RPSFW_PLUGIN_DIR . 'includes/stripe/class-stripe-subscriptions.php';
require_once RPSFW_PLUGIN_DIR . 'includes/class-rpsfw-refund-panel.php';
require_once RPSFW_PLUGIN_DIR . 'includes/stripe/class-stripe-order-refund-panel.php';

/**
 * RPSFW_Gateway_Stripe Class.
 */
class RPSFW_Gateway_Stripe extends WC_Payment_Gateway {

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
     * Settings handler instance
     *
     * @var RPSFW_Gateway_Stripe_Settings
     */
    public $settings_handler;

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
     * Test mode notice shown on checkout.
     *
     * @var string
     */
    public $test_mode_message = '';

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        $this->id                 = 'rpsfw_stripe';
        $this->has_fields         = true;
        $this->method_title       = __( 'Stripe', 'restore-paypal-standard-for-woocommerce' );
        $this->method_description = __( 'Accept credit card payments via Stripe with modern checkout experience.', 'restore-paypal-standard-for-woocommerce' );
        $this->supports           = array(
            'products',
            'refunds',
        );

        // Initialize the settings handler
        $this->settings_handler = new RPSFW_Gateway_Stripe_Settings($this);
        
        // Load the settings
        $this->init_form_fields();
        $this->init_settings();
        
        // Define properties
        $this->title = $this->get_option('title', __('Credit Card (Stripe)', 'restore-paypal-standard-for-woocommerce'));
        $this->description = $this->get_option('description', __('Pay securely with your credit card via Stripe.', 'restore-paypal-standard-for-woocommerce'));
        $this->testmode = 'yes' === $this->get_option('testmode', 'no');
        $this->debug = $this->get_option('debug_enabled') === 'yes';
        self::$log_enabled = $this->debug;

        // Set the gateway icon
        $this->icon = plugins_url( 'assets/images/stripe-logo.png', RPSFW_PLUGIN_FILE );

        // Add test mode notice
        if ( $this->testmode ) {
            $this->test_mode_message = __( 'TEST MODE ENABLED. Use test card numbers only.', 'restore-paypal-standard-for-woocommerce' );
        }

        // Actions
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'payment_scripts' ) );
        
        // Add custom field type handlers
        add_action( 'woocommerce_admin_field_stripe_connection_status', array( $this, 'generate_stripe_connection_status_html' ) );
        add_action( 'woocommerce_admin_field_stripe_webhook_status', array( $this, 'generate_stripe_webhook_status_html' ) );
        
        // Add order action for capturing authorized payments
        add_action( 'woocommerce_order_actions', array( $this, 'add_capture_order_action' ) );
        add_action( 'woocommerce_order_action_rpsfw_stripe_capture_payment', array( $this, 'process_capture_order_action' ) );

        // AJAX: finalize an order after the customer confirms the payment in
        // the browser. Used by the deferred (order-first) checkout flow, where
        // WooCommerce creates + validates the order BEFORE the card is charged.
        add_action( 'wp_ajax_rpsfw_stripe_finalize_order', array( $this, 'ajax_finalize_order' ) );
        add_action( 'wp_ajax_nopriv_rpsfw_stripe_finalize_order', array( $this, 'ajax_finalize_order' ) );

        // Conditionally enable WooCommerce Subscriptions integration.
        RPSFW_Stripe_Subscriptions::maybe_init( $this );

        // Per-charge refund panel for multi-charge (independent-subscription)
        // parent orders. Registered once; renders only on qualifying orders.
        if ( is_admin() && ! did_action( 'rpsfw_stripe_refund_panel_init' ) ) {
            RPSFW_Stripe_Order_Refund_Panel::init();
            do_action( 'rpsfw_stripe_refund_panel_init' );
        }
    }
    
    /**
     * Get the gateway icon
     */
    public function get_icon() {
        // Check if icon should be shown
        if ( 'no' === $this->get_option( 'show_icon', 'yes' ) ) {
            return '';
        }
        
        $icon_url = RPSFW_PLUGIN_URL . 'assets/images/stripe-logo.png';
        $icon_html = '<img src="' . esc_url( $icon_url ) . '" alt="' . esc_attr( $this->get_title() ) . '" class="rpsfw-stripe-icon" style="max-height: 28px; width: auto; vertical-align: middle;" />';
        
        return apply_filters( 'woocommerce_gateway_icon', $icon_html, $this->id );
    }
    
    /**
     * Get the gateway title
     */
    public function get_title() {
        // Check if title should be shown
        if ( 'no' === $this->get_option( 'show_title', 'yes' ) ) {
            return '';
        }
        
        return parent::get_title();
    }

    /**
     * Setup form fields.
     */
    public function init_form_fields() {
        $this->form_fields = $this->settings_handler->get_form_fields();
    }

    /**
     * Check if gateway is available for use.
     *
     * Requires a configured Stripe Connect account in the active mode.
     *
     * @return bool
     */
    public function is_available() {
        $is_available = parent::is_available();

        if ( ! $is_available ) {
            return false;
        }

        // Require a connected Stripe account before showing this gateway at
        // checkout. Without it, we have no secret/publishable key and the
        // payment will fail mid-flow.
        if ( function_exists( 'rpsfw_stripe_connection_status' ) ) {
            $status = rpsfw_stripe_connection_status();
            if ( empty( $status ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Admin options rendering
     */
    public function admin_options() {
        $this->settings_handler->admin_options();
    }

    /**
     * Process admin options.
     */
    public function process_admin_options() {
        $this->settings_handler->process_admin_options();
        return parent::process_admin_options();
    }

    /**
     * Check if gateway needs setup.
     *
     * @return bool
     */
    public function needs_setup() {
        // Check if Stripe Connect is configured
        $status = rpsfw_stripe_connection_status();
        return empty( $status );
    }

    /**
     * Generate connection status HTML
     *
     * @param string $key Field key
     * @param array $data Field data
     * @return string
     */
    public function generate_stripe_connection_status_html( $key, $data ) {
        $field_key = $this->get_field_key( $key );
        $defaults  = array(
            'title'             => '',
            'disabled'          => false,
            'class'             => '',
            'css'               => '',
            'placeholder'       => '',
            'type'              => 'text',
            'desc_tip'          => false,
            'description'       => '',
            'custom_attributes' => array(),
        );

        $data = wp_parse_args( $data, $defaults );

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?> <?php echo wp_kses_post( $this->get_tooltip_html( $data ) ); ?></label>
            </th>
            <td class="forminp">
                <fieldset>
                    <div class="stripe-connection-status-inline">
                        <?php $this->settings_handler->display_connection_status(); ?>
                    </div>
                    <?php echo wp_kses_post( $this->get_description_html( $data ) ); ?>
                </fieldset>
            </td>
        </tr>
        <?php

        return ob_get_clean();
    }

    /**
     * Generate webhook status HTML
     *
     * @param string $key Field key
     * @param array $data Field data
     * @return string
     */
    public function generate_stripe_webhook_status_html( $key, $data ) {
        $field_key = $this->get_field_key( $key );
        $defaults  = array(
            'title'             => '',
            'disabled'          => false,
            'class'             => '',
            'css'               => '',
            'placeholder'       => '',
            'type'              => 'text',
            'desc_tip'          => false,
            'description'       => '',
            'custom_attributes' => array(),
        );

        $data = wp_parse_args( $data, $defaults );

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?> <?php echo wp_kses_post( $this->get_tooltip_html( $data ) ); ?></label>
            </th>
            <td class="forminp">
                <fieldset>
                    <div class="stripe-webhook-status-inline">
                        <?php $this->settings_handler->display_webhook_status(); ?>
                    </div>
                    <?php echo wp_kses_post( $this->get_description_html( $data ) ); ?>
                </fieldset>
            </td>
        </tr>
        <?php

        return ob_get_clean();
    }

    /**
     * Payment form on checkout page
     */
    public function payment_fields() {
        // Check if description should be shown
        if ( $this->description && 'yes' === $this->get_option( 'show_description', 'yes' ) ) {
            echo wp_kses_post( wpautop( $this->description ) );
        }

        // Always show the test mode notice when in test mode, even if the
        // description is hidden via the "Show Description" setting.
        if ( $this->testmode && $this->test_mode_message ) {
            echo wp_kses_post( wpautop( $this->test_mode_message ) );
        }

        // Show the subscription details (today's charge + recurring amount)
        // above the payment form for subscription carts. Shown automatically
        // while in Test Mode so merchants can verify the amounts Stripe will
        // bill before going live. Mirrors the WooCommerce Subscriptions
        // recurring-totals summary at checkout.
        if ( $this->testmode
            && class_exists( 'RPSFW_Stripe_Subscriptions' )
            && RPSFW_Stripe_Subscriptions::cart_contains_subscription()
        ) {
            $this->output_stripe_debug_totals();
        }

        // Express Checkout Element mount point (Apple Pay / Google Pay one-tap
        // buttons).
        //
        // DISABLED FOR THIS RELEASE — Apple Pay / Google Pay are not shipping
        // yet, so the express container is never rendered. To re-enable, remove
        // the `false &&` guard below (the original conditions are preserved):
        //  - skip for subscription carts (wallet express is one-off only), and
        //  - skip when the cart needs shipping (wallet-selected shipping can
        //    change the total after the PaymentIntent amount is set).
        $cart_has_subscription = class_exists( 'RPSFW_Stripe_Subscriptions' )
            && RPSFW_Stripe_Subscriptions::cart_contains_subscription();
        $cart_needs_shipping = WC()->cart && WC()->cart->needs_shipping();
        if ( false
            && ! $cart_has_subscription
            && ! $cart_needs_shipping
            && ( 'yes' === $this->get_option( 'enable_apple_pay', 'no' )
                || 'yes' === $this->get_option( 'enable_google_pay', 'no' ) ) ) {
            echo '<div id="stripe-express-checkout-element" class="rpsfw-stripe-express-container"></div>';
        }

        echo '<div id="stripe-payment-element" class="rpsfw-stripe-element-container"></div>';
        echo '<div id="stripe-payment-errors" role="alert"></div>';
    }

    /**
     * Output the subscription details (today's charge and recurring amount) for
     * subscription carts. Shown above the card fields while in test mode so the
     * shopper/merchant can see what will be billed now and on renewal, mirroring
     * the WooCommerce Subscriptions recurring-totals summary at checkout.
     */
    private function output_stripe_debug_totals() {
        $totals = $this->get_debug_totals_data();
        if ( ! $totals ) {
            return;
        }
        ?>
        <div class="rpsfw-stripe-debug-totals" style="background:#fff3cd;border:1px solid #ffc107;border-left:4px solid #ffc107;padding:10px 14px;margin-bottom:12px;font-size:13px;border-radius:3px;">
            <strong style="display:block;margin-bottom:4px;"><?php esc_html_e( 'Subscription details (test mode)', 'restore-paypal-standard-for-woocommerce' ); ?></strong>
            <div><strong><?php esc_html_e( 'Due today:', 'restore-paypal-standard-for-woocommerce' ); ?></strong> <?php echo wp_kses_post( wc_price( $totals['today'], array( 'currency' => $totals['currency'] ) ) ); ?></div>
            <?php if ( ! empty( $totals['recurring_lines'] ) ) : ?>
                <?php foreach ( $totals['recurring_lines'] as $line ) : ?>
                    <div><strong><?php esc_html_e( 'Renews:', 'restore-paypal-standard-for-woocommerce' ); ?></strong>
                        <?php
                        if ( $line['amount'] > 0 ) {
                            echo wp_kses_post( wc_price( $line['amount'], array( 'currency' => $totals['currency'] ) ) );
                            echo $line['label'] ? ' ' . esc_html( $line['label'] ) : '';
                        } else {
                            esc_html_e( '$0.00 (free trial or fully discounted)', 'restore-paypal-standard-for-woocommerce' );
                            echo $line['label'] ? ' ' . esc_html( $line['label'] ) : '';
                        }
                        ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Compute the debug totals (today's charge and each recurring schedule with
     * a human-readable label) for the current subscription cart. Supports
     * multiple recurring schedules (mixed-interval carts).
     *
     * Shared by the classic checkout (output_stripe_debug_totals) and the
     * block checkout (passed to the block via the blocks-support data), so the
     * debug panel appears on both surfaces.
     *
     * @return array|false Totals array, or false if the cart is unavailable.
     */
    public function get_debug_totals_data() {
        if ( ! WC()->cart ) {
            return false;
        }

        WC()->cart->calculate_totals();

        $currency        = get_woocommerce_currency();
        $today_total     = WC()->cart->get_total( 'edit' );
        $recurring_lines = array();

        if ( ! empty( WC()->cart->recurring_carts ) ) {
            foreach ( WC()->cart->recurring_carts as $recurring_cart ) {
                $amount = (float) $recurring_cart->get_total( 'edit' );
                $label  = '';
                // Label from the first subscription product in this schedule.
                foreach ( $recurring_cart->get_cart() as $item ) {
                    $product = $item['data'];
                    if ( class_exists( 'WC_Subscriptions_Product' ) && WC_Subscriptions_Product::is_subscription( $product ) ) {
                        $period   = WC_Subscriptions_Product::get_period( $product );
                        $interval = (int) WC_Subscriptions_Product::get_interval( $product );
                        $label    = $interval > 1
                            ? sprintf( 'every %d %ss', $interval, $period )
                            : sprintf( 'every %s', $period );
                        break;
                    }
                }
                $recurring_lines[] = array(
                    'amount' => round( $amount, 2 ),
                    'label'  => $label,
                );
            }
        }

        // Built-in (native) subscription carts have no WCS recurring_carts;
        // derive the recurring line from the native cart signature so the
        // debug panel shows the same "Due today / Recurring" breakdown.
        if ( empty( $recurring_lines )
            && class_exists( 'RPSFW_Subscriptions_Cart' )
            && RPSFW_Subscriptions_Cart::cart_contains_subscription() ) {
            $signature = RPSFW_Subscriptions_Cart::build_cart_signature();
            if ( $signature && ! empty( $signature['items'] ) ) {
                $multiple = count( $signature['items'] ) > 1;
                foreach ( $signature['items'] as $sig_item ) {
                    // Build a WooCommerce-Subscriptions-style detail string:
                    // "every day, 1-day free trial, 3 payments".
                    $label_parts = array();
                    if ( function_exists( 'rpsfw_format_subscription_period' ) ) {
                        $label_parts[] = rpsfw_format_subscription_period( $sig_item['interval'], $sig_item['period'] );
                    }
                    if ( ! empty( $sig_item['trial_length'] ) && function_exists( 'rpsfw_format_subscription_trial' ) ) {
                        $trial = rpsfw_format_subscription_trial( $sig_item['trial_length'], $sig_item['trial_period'] );
                        if ( $trial ) {
                            $label_parts[] = $trial;
                        }
                    }
                    if ( ! empty( $sig_item['length'] ) ) {
                        $label_parts[] = sprintf(
                            /* translators: %d: total number of payments over the life of the subscription */
                            _n( '%d payment', '%d payments', (int) $sig_item['length'], 'restore-paypal-standard-for-woocommerce' ),
                            (int) $sig_item['length']
                        );
                    }
                    // With several subscriptions in the cart, prefix each
                    // line with the product so the rows are tellable apart.
                    $label = implode( ', ', $label_parts );
                    if ( $multiple && ! empty( $sig_item['plan_name'] ) ) {
                        $label = $sig_item['plan_name'] . ' — ' . $label;
                    }

                    $recurring_lines[] = array(
                        'amount' => round( (float) $sig_item['recurring_amount'], 2 ),
                        'label'  => $label,
                    );
                }
            }
        }

        return array(
            'today'              => $today_total,
            'currency'           => $currency,
            'recurring_lines'    => $recurring_lines,
            'has_recurring_cart' => ! empty( WC()->cart->recurring_carts ) || ! empty( $recurring_lines ),
        );
    }

    /**
     * Deferred Payment Element mount params for the current (subscription)
     * cart: { mode, amount, currency }.
     *
     * `amount` is the "due today" total in the smallest currency unit (e.g.
     * cents), matching what the server will charge on the subscription's first
     * invoice — Stripe rejects the confirm if the Element's amount and the
     * confirmed intent's amount disagree. `mode` is 'setup' when nothing is
     * charged today (e.g. a free trial with no sign-up fee) and 'subscription'
     * otherwise. Amount is meaningless in setup mode, so the JS omits it there.
     *
     * @return array{mode:string,amount:int,currency:string}|null Null if the cart is unavailable.
     */
    public function get_deferred_mount_params() {
        $totals = $this->get_debug_totals_data();
        if ( ! is_array( $totals ) ) {
            return null;
        }

        // First deferred slice: SINGLE-subscription carts only. Multi-schedule
        // carts (more than one billing schedule) authenticate once with a
        // SetupIntent and create the separate subscriptions off-session — a
        // different confirm path — so leave them on the original create-on-load
        // flow by returning null here (which makes usesDeferredSub() false in JS).
        if ( ! empty( WC()->cart->recurring_carts ) && count( WC()->cart->recurring_carts ) > 1 ) {
            return null;
        }
        if ( class_exists( 'RPSFW_Subscriptions_Cart' ) ) {
            $sig = RPSFW_Subscriptions_Cart::build_cart_signature();
            if ( ! empty( $sig['sub_count'] ) && (int) $sig['sub_count'] > 1 ) {
                return null;
            }
        }

        $today    = isset( $totals['today'] ) ? (float) $totals['today'] : 0.0;
        $currency = isset( $totals['currency'] ) ? $totals['currency'] : get_woocommerce_currency();

        return array(
            'mode'                 => $today > 0 ? 'subscription' : 'setup',
            'amount'               => RPSFW_Stripe_API::get_stripe_amount( $today, $currency ),
            'currency'             => strtolower( $currency ),
            // Methods the deferred Element should offer (card + Link when
            // enabled). Must match the Stripe Subscription's payment_settings so
            // Link both appears AND is accepted on the first invoice.
            'payment_method_types' => RPSFW_Stripe_API::subscription_payment_method_types(),
        );
    }

    /**
     * Load payment scripts
     */
    public function payment_scripts() {
        // Never load anything unless the Stripe gateway is enabled and usable
        // (is_available() enforces enabled + a connected Stripe account).
        if ( ! $this->is_available() ) {
            return;
        }

        // Are we on a page where the Stripe payment form actually renders?
        $is_checkout_context = is_checkout() || isset( $_GET['pay_for_order'] );

        // Stripe recommends loading Stripe.js on EVERY page so its advanced
        // fraud detection (Radar) can observe browsing behavior and better
        // distinguish legitimate shoppers from fraudsters. Merchants who want
        // to minimise requests can restrict it to checkout via the advanced
        // setting below - at the cost of that advanced fraud signal.
        $checkout_only = 'yes' === $this->get_option( 'load_stripe_js_checkout_only', 'no' );

        // Off checkout: load only the lightweight Stripe.js library (for fraud
        // detection), unless the merchant restricted it to checkout. The
        // checkout form assets/params are not needed here.
        if ( ! $is_checkout_context ) {
            if ( ! $checkout_only ) {
                wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), '3.0', true );
            }
            return;
        }

        // Enqueue Stripe.js
        wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', array(), '3.0', true );
        
        // Version assets by file modification time so edits always bust the
        // browser/WordPress cache (RPSFW_VERSION alone doesn't change when we
        // edit the JS/CSS directly, so cached copies would otherwise persist).
        $rpsfw_css_path = RPSFW_PLUGIN_DIR . 'assets/css/stripe-checkout.css';
        $rpsfw_js_path  = RPSFW_PLUGIN_DIR . 'assets/js/stripe-checkout.js';
        $rpsfw_css_ver  = file_exists( $rpsfw_css_path ) ? (string) filemtime( $rpsfw_css_path ) : RPSFW_VERSION;
        $rpsfw_js_ver   = file_exists( $rpsfw_js_path ) ? (string) filemtime( $rpsfw_js_path ) : RPSFW_VERSION;

        // Enqueue custom Stripe checkout CSS
        wp_enqueue_style( 'rpsfw-stripe-checkout', RPSFW_PLUGIN_URL . 'assets/css/stripe-checkout.css', array(), $rpsfw_css_ver );
        
        // Enqueue custom Stripe checkout script
        wp_enqueue_script( 'rpsfw-stripe-checkout', RPSFW_PLUGIN_URL . 'assets/js/stripe-checkout.js', array( 'jquery', 'stripe-js' ), $rpsfw_js_ver, true );
        
        // Get account ID for connected accounts
        $options = get_option( 'woocommerce_rpsfw_stripe_settings', array() );
        $testmode = isset( $options['testmode'] ) && $options['testmode'] === 'yes';
        $account_id_key = $testmode ? 'acct_id_test' : 'acct_id_live';
        $account_id = isset( $options[$account_id_key] ) ? $options[$account_id_key] : '';
        
        // Get payment method order
        $payment_method_order = $this->get_payment_method_order();

        // Detect whether the cart contains a subscription so the JS can
        // call the right AJAX endpoint (Subscription create vs one-off
        // PaymentIntent). True for WooCommerce Subscriptions carts AND for
        // this plugin's built-in (native) subscription carts — the static
        // detector below covers both.
        $is_subscription = ( function_exists( 'wcs_is_subscription' )
                || ( function_exists( 'rpsfw_native_subscriptions_active' ) && rpsfw_native_subscriptions_active() ) )
            && class_exists( 'RPSFW_Stripe_Subscriptions' )
            && RPSFW_Stripe_Subscriptions::cart_contains_subscription();

        // Detect whether this is a "change payment method" page so the
        // JS can mount a SetupIntent flow against the existing
        // subscription instead of a checkout.
        $is_change_pm    = class_exists( 'RPSFW_Stripe_Subscriptions' )
            && RPSFW_Stripe_Subscriptions::is_change_payment_method_request();
        $change_pm_sub_id = 0;
        if ( $is_change_pm ) {
            global $wp;
            $change_pm_sub_id = isset( $wp->query_vars['order-pay'] ) ? absint( $wp->query_vars['order-pay'] ) : 0;
        }

        // Deferred Payment Element mount data (subscription carts only).
        //
        // In the deferred flow the Element is mounted with { mode, amount,
        // currency } BEFORE anything is created on Stripe — the customer and
        // subscription are only created when the pay button is clicked. These
        // values seed the Element's initial amount/currency and tell it
        // whether today's charge is $0 (setup mode) vs a real charge
        // (subscription/payment mode). They are refreshed on cart change via
        // the get_mount_params AJAX endpoint. Reuses the same due-today
        // computation the totals panel uses, so the Element's amount always
        // matches what the server will actually charge.
        $deferred_mount = null;
        if ( $is_subscription ) {
            $deferred_mount = $this->get_deferred_mount_params();
        }

        // Resolve the locale value to pass to the JS.
        // 'auto' is passed as-is; 'site' is converted to the WordPress locale
        // in Stripe's IETF format (underscore → hyphen, e.g. en_US → en-US).
        $locale_setting = $this->get_option( 'locale', 'auto' );
        if ( 'site' === $locale_setting ) {
            $stripe_locale = str_replace( '_', '-', get_locale() );
        } else {
            $stripe_locale = 'auto';
        }

        // Localize script with Stripe data
        wp_localize_script(
            'rpsfw-stripe-checkout',
            'rpsfwStripeParams',
            array(
                'publishable_key' => RPSFW_Stripe_API::get_publishable_key(),
                'account_id' => $account_id,
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'create_intent_nonce' => wp_create_nonce( 'rpsfw-stripe-create-intent' ),
                'create_subscription_nonce' => wp_create_nonce( 'rpsfw-stripe-create-subscription' ),
                'create_setup_intent_nonce' => wp_create_nonce( 'rpsfw-stripe-create-setup-intent' ),
                // Deferred (order-first) checkout flow: finalize endpoint nonce
                // and WooCommerce's own AJAX checkout URL so the front end can
                // create + validate the order BEFORE confirming payment.
                'finalize_nonce' => wp_create_nonce( 'rpsfw-stripe-finalize-order' ),
                'checkout_url' => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'checkout' ) : add_query_arg( 'wc-ajax', 'checkout', home_url( '/' ) ),
                'is_subscription' => $is_subscription,
                // Deferred mount params { mode, amount, currency } for
                // subscription carts (null otherwise). See get_deferred_mount_params().
                'deferred_mount' => $deferred_mount,
                'is_change_payment_method' => $is_change_pm,
                'change_payment_subscription_id' => $change_pm_sub_id,
                'appearance' => $this->get_appearance_options(),
                'payment_method_order' => $payment_method_order,
                'wallets_config' => $this->get_wallets_config(),
                'link_enabled' => ( 'yes' === $this->get_option( 'enable_link', 'yes' ) ),
                // DISABLED FOR THIS RELEASE — Apple Pay / Google Pay express
                // buttons are not shipping yet. Force off. Restore the
                // wallet-driven value to re-enable:
                // ( 'yes' === $this->get_option( 'enable_apple_pay', 'no' ) || 'yes' === $this->get_option( 'enable_google_pay', 'no' ) )
                'express_checkout_enabled' => false,
                'locale' => $stripe_locale,
                'loading_text' => $this->get_option( 'loading_text', __( 'Loading payment form...', 'restore-paypal-standard-for-woocommerce' ) ),
                'strings' => array(
                    'invalid_number' => __( 'The card number is not a valid credit card number.', 'restore-paypal-standard-for-woocommerce' ),
                    'invalid_expiry' => __( 'The card\'s expiration date is invalid.', 'restore-paypal-standard-for-woocommerce' ),
                    'invalid_cvc' => __( 'The card\'s security code is invalid.', 'restore-paypal-standard-for-woocommerce' ),
                    'incorrect_number' => __( 'The card number is incorrect.', 'restore-paypal-standard-for-woocommerce' ),
                    'incomplete_number' => __( 'The card number is incomplete.', 'restore-paypal-standard-for-woocommerce' ),
                    'incomplete_expiry' => __( 'The card\'s expiration date is incomplete.', 'restore-paypal-standard-for-woocommerce' ),
                    'incomplete_cvc' => __( 'The card\'s security code is incomplete.', 'restore-paypal-standard-for-woocommerce' ),
                    'expired_card' => __( 'The card has expired.', 'restore-paypal-standard-for-woocommerce' ),
                    'incorrect_cvc' => __( 'The card\'s security code is incorrect.', 'restore-paypal-standard-for-woocommerce' ),
                    'incorrect_zip' => __( 'The card\'s zip code failed validation.', 'restore-paypal-standard-for-woocommerce' ),
                    'card_declined' => __( 'The card was declined.', 'restore-paypal-standard-for-woocommerce' ),
                    'missing' => __( 'There is no card on a customer that is being charged.', 'restore-paypal-standard-for-woocommerce' ),
                    'processing_error' => __( 'An error occurred while processing the card.', 'restore-paypal-standard-for-woocommerce' ),
                ),
            )
        );
    }

    /**
     * Process the payment and return the result.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        self::log( 'Processing payment for order #' . $order_id );

        // Record the mode this payment is being taken in, before anything else
        // can branch. Everything that later interprets this order — refunds,
        // dashboard links, webhook routing — reads this instead of the gateway's
        // current setting, so switching the store test <-> live never
        // reinterprets an existing order. Stamped ahead of the override filter
        // so subscription orders are covered too.
        if ( $order ) {
            rpsfw_set_order_payment_mode( $order, rpsfw_get_gateway_mode( $this->id ) );
        }

        // Allow extensions (WooCommerce Subscriptions integration) to take
        // over the flow when the order requires a different processing path,
        // e.g. the change-payment-method page that has no PaymentIntent.
        $override = apply_filters( 'rpsfw_stripe_process_payment_override', null, $order_id, $order );
        if ( is_array( $override ) ) {
            return $override;
        }

        // Get payment intent ID from POST data
        $payment_intent_id = isset( $_POST['rpsfw_stripe_payment_intent_id'] ) ? wc_clean( wp_unslash( $_POST['rpsfw_stripe_payment_intent_id'] ) ) : '';

        if ( empty( $payment_intent_id ) ) {
            wc_add_notice( __( 'Payment error: Missing payment intent.', 'restore-paypal-standard-for-woocommerce' ), 'error' );
            return array( 'result' => 'fail' );
        }
        
        // Validate payment intent ID format (should start with 'pi_')
        if ( strpos( $payment_intent_id, 'pi_' ) !== 0 ) {
            self::log( 'Invalid payment intent ID format: ' . $payment_intent_id, 'error' );
            wc_add_notice( __( 'Payment error: Invalid payment intent.', 'restore-paypal-standard-for-woocommerce' ), 'error' );
            return array( 'result' => 'fail' );
        }

        // Retrieve the payment intent
        $intent = RPSFW_Stripe_API::retrieve_payment_intent( $payment_intent_id );

        if ( is_wp_error( $intent ) ) {
            self::log( 'Failed to retrieve payment intent: ' . $intent->get_error_message(), 'error' );
            wc_add_notice( __( 'Payment error: ', 'restore-paypal-standard-for-woocommerce' ) . $intent->get_error_message(), 'error' );
            return array( 'result' => 'fail' );
        }

        // SECURITY: the PaymentIntent amount is locked in when the intent is
        // created on the checkout page. If the cart changed afterwards (e.g.
        // a coupon applied or removed, or a stale intent from a prior page
        // load) the intent amount can diverge from the order total. Reject a
        // mismatch so the customer is never under- or over-charged relative
        // to the WooCommerce order. Allow a 1-cent tolerance for rounding.
        $expected_cents = RPSFW_Stripe_API::get_stripe_amount( $order->get_total(), $order->get_currency() );
        $intent_cents   = isset( $intent->amount ) ? (int) $intent->amount : 0;
        if ( abs( $intent_cents - $expected_cents ) > 1 ) {
            self::log( sprintf(
                'SECURITY: Stripe amount mismatch for order #%d. Intent: %d, expected: %d (%s). Likely a coupon/cart change after the payment form loaded.',
                $order_id,
                $intent_cents,
                $expected_cents,
                $order->get_currency()
            ), 'error' );
            wc_add_notice(
                __( 'Your cart total changed. Please review the payment form and try again.', 'restore-paypal-standard-for-woocommerce' ),
                'error'
            );
            return array( 'result' => 'fail' );
        }

        // Deferred confirmation (order-first flow). The order has now been
        // created and validated by WooCommerce, but the customer has NOT yet
        // confirmed the PaymentIntent in the browser (so no money has moved).
        // Persist the intent on the order, keep it pending, and hand back a
        // marker so the checkout JS confirms the PaymentIntent with the mounted
        // Payment Element and then completes the order via ajax_finalize_order().
        // This is what prevents a charge before checkout validation (e.g. an
        // "email already registered" failure) — the charge only happens after
        // the order exists.
        if ( in_array( $intent->status, array( 'requires_payment_method', 'requires_confirmation', 'requires_action' ), true ) ) {
            $order->set_transaction_id( $intent->id );
            $order->update_meta_data( '_rpsfw_stripe_payment_intent_id', $intent->id );
            $order->update_status( 'pending', __( 'Awaiting Stripe payment confirmation.', 'restore-paypal-standard-for-woocommerce' ) );
            $order->save();

            return array(
                'result'                 => 'success',
                'rpsfw_stripe_confirm'   => 'payment',
                'rpsfw_stripe_order_id'  => $order->get_id(),
                'rpsfw_stripe_order_key' => $order->get_order_key(),
                'redirect'               => $this->get_return_url( $order ),
            );
        }

        // Check payment intent status
        if ( $intent->status === 'succeeded' ) {
            // Payment successful
            $this->process_payment_success( $order, $intent );
            
            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url( $order ),
            );
        } elseif ( $intent->status === 'requires_capture' ) {
            // Payment authorized but not captured
            $this->process_payment_authorized( $order, $intent );
            
            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url( $order ),
            );
        } elseif ( $intent->status === 'processing' ) {
            // Asynchronous payment method (e.g. ACH direct debit). The debit has
            // been initiated but settles over a few business days. Put the order
            // on hold; the payment_intent.succeeded webhook marks it paid once
            // Stripe confirms settlement, or payment_intent.payment_failed marks
            // it failed.
            $this->process_payment_processing( $order, $intent );

            return array(
                'result'   => 'success',
                'redirect' => $this->get_return_url( $order ),
            );
        } elseif ( $intent->status === 'requires_action' || $intent->status === 'requires_confirmation' ) {
            // 3D Secure or additional authentication required
            wc_add_notice( __( 'Payment requires additional authentication. Please try again.', 'restore-paypal-standard-for-woocommerce' ), 'error' );
            return array( 'result' => 'fail' );
        } else {
            // Payment failed or in unexpected state
            self::log( 'Payment intent in unexpected state: ' . $intent->status, 'error' );
            wc_add_notice( __( 'Payment failed. Please try again.', 'restore-paypal-standard-for-woocommerce' ), 'error' );
            return array( 'result' => 'fail' );
        }
    }

    /**
     * AJAX: finalize an order after the customer confirmed the payment in the
     * browser (deferred / order-first flow).
     *
     * The order was already created + validated by WooCommerce during the
     * checkout submission; here we verify the PaymentIntent (or, via the
     * subscriptions filter, the Stripe subscription) reached a paid state and
     * mark the WooCommerce order complete. Runs for both logged-in and guest
     * customers.
     *
     * @return void Sends a JSON response.
     */
    public function ajax_finalize_order() {
        // CSRF nonce is BEST-EFFORT here. The block's finalize nonce is minted
        // when the checkout page renders and can be stale by submit time (the
        // guest session is reassigned during Store API checkout, or the page
        // was cached), which makes check_ajax_referer() reject with a 403 and
        // strand an order that Stripe has already charged. Authorization for
        // this endpoint is instead carried by the order_key verified below — an
        // unguessable per-order secret sent explicitly (not a cookie, so not a
        // CSRF vector) — combined with independent verification that the
        // PaymentIntent/subscription actually reached a paid state on Stripe.
        // So a missing/stale nonce is logged but must not block completion.
        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'rpsfw-stripe-finalize-order' ) ) {
            self::log( 'Stripe finalize: nonce stale/missing; authorizing via order key + Stripe payment state.', 'warning' );
        }

        $order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
        $order     = $order_id ? wc_get_order( $order_id ) : false;

        if ( ! $order || ! hash_equals( (string) $order->get_order_key(), (string) $order_key ) ) {
            wp_send_json_error( array( 'message' => __( 'We could not verify your order. Please contact us before trying again.', 'restore-paypal-standard-for-woocommerce' ) ) );
        }

        // Already completed (e.g. a duplicate finalize call, or the webhook
        // beat us to it). Just send the customer to the order received page.
        if ( $order->has_status( array( 'on-hold', 'processing', 'completed' ) ) ) {
            wp_send_json_success( array( 'redirect' => $this->get_return_url( $order ) ) );
        }

        // Let the WooCommerce Subscriptions integration finalize subscription
        // orders (marks the WC order paid once the Stripe subscription is
        // active). Returns an array on success or a WP_Error on failure.
        $override = apply_filters( 'rpsfw_stripe_finalize_order_override', null, $order );
        if ( is_wp_error( $override ) ) {
            wp_send_json_error( array( 'message' => $override->get_error_message() ) );
        }
        if ( is_array( $override ) ) {
            wp_send_json_success( $override );
        }

        // One-off order: verify the PaymentIntent completed, then mark paid.
        $intent_id = $order->get_meta( '_rpsfw_stripe_payment_intent_id' );
        if ( empty( $intent_id ) && ! empty( $_POST['payment_intent_id'] ) ) {
            $intent_id = sanitize_text_field( wp_unslash( $_POST['payment_intent_id'] ) );
        }
        if ( empty( $intent_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Missing payment reference. Please try again.', 'restore-paypal-standard-for-woocommerce' ) ) );
        }

        $intent = RPSFW_Stripe_API::retrieve_payment_intent( $intent_id );
        if ( is_wp_error( $intent ) ) {
            wp_send_json_error( array( 'message' => $intent->get_error_message() ) );
        }

        // Defense in depth: re-check the charged amount against the order total.
        $expected_cents = RPSFW_Stripe_API::get_stripe_amount( $order->get_total(), $order->get_currency() );
        $intent_cents   = isset( $intent->amount ) ? (int) $intent->amount : 0;
        if ( abs( $intent_cents - $expected_cents ) > 1 ) {
            self::log( sprintf( 'SECURITY: finalize amount mismatch for order #%d. Intent: %d, expected: %d.', $order->get_id(), $intent_cents, $expected_cents ), 'error' );
            wp_send_json_error( array( 'message' => __( 'The payment amount did not match your order. Please contact us.', 'restore-paypal-standard-for-woocommerce' ) ) );
        }

        if ( 'succeeded' === $intent->status ) {
            $this->process_payment_success( $order, $intent );
        } elseif ( 'requires_capture' === $intent->status ) {
            $this->process_payment_authorized( $order, $intent );
        } elseif ( 'processing' === $intent->status ) {
            $this->process_payment_processing( $order, $intent );
        } else {
            wp_send_json_error( array( 'message' => __( 'Your payment was not completed. Please try again.', 'restore-paypal-standard-for-woocommerce' ) ) );
        }

        if ( WC()->cart ) {
            WC()->cart->empty_cart();
        }

        wp_send_json_success( array( 'redirect' => $this->get_return_url( $order ) ) );
    }

    /**
     * Process an asynchronous, still-settling payment (e.g. ACH direct debit).
     *
     * The PaymentIntent is in 'processing': the customer has authorized the
     * debit but the funds take a few business days to clear. We record the
     * intent, place the order on hold, and reduce stock so inventory is
     * reserved during settlement. The payment_intent.succeeded webhook later
     * marks the order paid (or payment_intent.payment_failed marks it failed).
     *
     * @param WC_Order              $order  Order object
     * @param \Stripe\PaymentIntent $intent Payment intent object
     */
    private function process_payment_processing( $order, $intent ) {
        // Record identifiers so the settlement webhook can resolve this order.
        $order->set_transaction_id( $intent->id );
        $order->update_meta_data( '_rpsfw_stripe_payment_intent_id', $intent->id );

        $charge = RPSFW_Stripe_API::get_charge_from_intent( $intent );
        if ( $charge && ! empty( $charge->id ) ) {
            $order->update_meta_data( '_rpsfw_stripe_charge_id', $charge->id );
        }

        // On hold until Stripe confirms settlement. update_status() saves meta.
        $order->update_status(
            'on-hold',
            sprintf(
                /* translators: %s: Stripe payment intent ID. */
                __( 'Stripe payment is processing (e.g. ACH bank debit) and awaiting settlement. The order will be marked paid automatically once Stripe confirms. (Payment Intent ID: %s)', 'restore-paypal-standard-for-woocommerce' ),
                $intent->id
            )
        );

        // Reserve inventory during the multi-day settlement window. (A fully
        // paid order reduces stock via payment_complete; on-hold does not, so
        // do it explicitly.)
        wc_maybe_reduce_stock_levels( $order->get_id() );

        self::log( 'Payment processing (async) for order #' . $order->get_id() . ' (Intent: ' . $intent->id . ')' );
    }

    /**
     * Process successful payment
     *
     * @param WC_Order $order Order object
     * @param \Stripe\PaymentIntent $intent Payment intent object
     */
    private function process_payment_success( $order, $intent ) {
        // Store transaction ID
        $order->set_transaction_id( $intent->id );

        // Store the PaymentIntent id so webhook handlers (reviews, disputes)
        // can resolve this order by payment_intent.
        $order->update_meta_data( '_rpsfw_stripe_payment_intent_id', $intent->id );

        // Store charge ID + card details (via latest_charge; the legacy
        // charges list was removed in modern API versions).
        $charge = RPSFW_Stripe_API::get_charge_from_intent( $intent );
        if ( $charge ) {
            if ( ! empty( $charge->id ) ) {
                $order->update_meta_data( '_rpsfw_stripe_charge_id', $charge->id );
            }
            if ( ! empty( $charge->payment_method_details ) && isset( $charge->payment_method_details->card ) ) {
                $order->update_meta_data( '_rpsfw_stripe_card_brand', $charge->payment_method_details->card->brand );
                $order->update_meta_data( '_rpsfw_stripe_card_last4', $charge->payment_method_details->card->last4 );
            }
        }

        // Diagnostic: log which payment method actually completed the order so
        // we can tell a raw card from Link / Apple Pay / Google Pay. Link
        // enrolment happens in the browser; the server-visible signal that Link
        // (or a wallet) was used is the payment method type / card wallet here.
        if ( $charge && ! empty( $charge->payment_method_details ) ) {
            $pmd    = $charge->payment_method_details;
            $type   = isset( $pmd->type ) ? $pmd->type : 'unknown';
            $wallet = ( isset( $pmd->card ) && isset( $pmd->card->wallet ) && isset( $pmd->card->wallet->type ) )
                ? $pmd->card->wallet->type
                : 'none';
            self::log( 'Payment method used for order #' . $order->get_id() . ': type=' . $type . ', card wallet=' . $wallet . ' (type=link or wallet=link => Link used; wallet=apple_pay/google_pay => that wallet used).' );
        }

        // Mark order as paid
        $order->payment_complete( $intent->id );
        
        // Add order note
        $order->add_order_note(
            sprintf(
                /* translators: %s: Stripe payment intent ID. */
                __( 'Stripe payment completed (Payment Intent ID: %s)', 'restore-paypal-standard-for-woocommerce' ),
                $intent->id
            )
        );

        self::log( 'Payment completed for order #' . $order->get_id() . ' (Intent: ' . $intent->id . ')' );

        /**
         * Fires when a Stripe payment completes successfully. Used by the
         * WooCommerce Subscriptions integration to copy meta onto subscriptions
         * and persist saved cards.
         */
        do_action( 'rpsfw_stripe_payment_complete', $order, $intent );
    }

    /**
     * Process authorized payment
     *
     * @param WC_Order $order Order object
     * @param \Stripe\PaymentIntent $intent Payment intent object
     */
    private function process_payment_authorized( $order, $intent ) {
        // Store transaction ID
        $order->set_transaction_id( $intent->id );

        // Store the PaymentIntent id so webhook handlers (reviews, disputes,
        // payment_intent.canceled) can resolve this order by payment_intent.
        $order->update_meta_data( '_rpsfw_stripe_payment_intent_id', $intent->id );

        // Store charge ID + card details (via latest_charge; the legacy
        // charges list was removed in modern API versions).
        $charge = RPSFW_Stripe_API::get_charge_from_intent( $intent );
        if ( $charge ) {
            if ( ! empty( $charge->id ) ) {
                $order->update_meta_data( '_rpsfw_stripe_charge_id', $charge->id );
            }
            if ( ! empty( $charge->payment_method_details ) && isset( $charge->payment_method_details->card ) ) {
                $order->update_meta_data( '_rpsfw_stripe_card_brand', $charge->payment_method_details->card->brand );
                $order->update_meta_data( '_rpsfw_stripe_card_last4', $charge->payment_method_details->card->last4 );
            }
        }

        // Set order to on-hold for manual capture
        $order->update_status( 
            'on-hold', 
            sprintf( 
                /* translators: %s: Stripe payment intent ID. */
                __( 'Stripe payment authorized (Payment Intent ID: %s). Capture the payment to complete the order.', 'restore-paypal-standard-for-woocommerce' ), 
                $intent->id 
            ) 
        );
        
        $order->save();

        self::log( 'Payment authorized for order #' . $order->get_id() . ' (Intent: ' . $intent->id . ')' );

        /**
         * Fires when a Stripe payment is authorized (but not captured). Used
         * by the WooCommerce Subscriptions integration to copy meta onto
         * subscriptions.
         */
        do_action( 'rpsfw_stripe_payment_complete', $order, $intent );
    }

    /**
     * Process a refund if supported.
     *
     * @param  int    $order_id Order ID.
     * @param  float  $amount Refund amount.
     * @param  string $reason Refund reason.
     * @return bool|WP_Error
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return new WP_Error( 'error', __( 'Refund failed: order not found.', 'restore-paypal-standard-for-woocommerce' ) );
        }

        // Refund against the account this order was PAID in, not whichever mode
        // the store is set to now. The charge/intent ids below only exist in
        // that account, so a store that has since switched test <-> live would
        // otherwise query the wrong Stripe account and report that no charge
        // could be resolved. Mirrors RPSFW_Stripe_Order_Refund_Panel.
        $order_mode = rpsfw_get_order_payment_mode( $order );
        RPSFW_Stripe_API::set_request_mode( $order_mode );

        // Say plainly when the store is not connected to the account this order
        // belongs to. Without this the charge lookup below simply finds nothing
        // and reports "could not resolve a Stripe charge", which reads as a
        // broken order rather than a missing connection.
        if ( ! RPSFW_Stripe_API::get_secret_key( $order_mode ) ) {
            self::log( 'Refund failed: not connected to Stripe in ' . $order_mode . ' mode, which is where order #' . $order_id . ' was paid.', 'error' );
            return new WP_Error(
                'error',
                sprintf(
                    /* translators: %1$s: mode the order was paid in, e.g. "Test" or "Live". */
                    __( 'This order was paid in %1$s mode, but Stripe is not connected in %1$s mode. Connect that account to refund this order.', 'restore-paypal-standard-for-woocommerce' ),
                    rpsfw_payment_mode_label( $order_mode )
                )
            );
        }

        $currency  = $order->get_currency();
        $requested = is_null( $amount ) ? (float) $order->get_total() : (float) $amount;
        if ( $requested <= 0 ) {
            return new WP_Error( 'error', __( 'Refund failed: invalid amount.', 'restore-paypal-standard-for-woocommerce' ) );
        }
        $remaining_cents = RPSFW_Stripe_API::get_stripe_amount( $requested, $currency );

        self::log( 'Processing refund for order #' . $order_id . ' - Amount: ' . $requested );

        // An order can be backed by MORE THAN ONE Stripe charge: the
        // independent multi-schedule subscription flow charges each
        // subscription's first invoice separately under a single parent order.
        // Collect every UNIQUE charge tied to this order (sources are
        // normalised to charge ids and deduped, so a charge is never refunded
        // twice) and refund across them until the requested amount is
        // satisfied. Regular and single-schedule orders resolve to exactly one
        // charge, so behaviour is unchanged for them.
        $charges = $this->collect_refund_charges( $order );

        if ( empty( $charges ) ) {
            self::log( 'Refund failed: could not resolve any Stripe charge for order #' . $order_id, 'error' );
            return new WP_Error( 'error', __( 'Refund failed: could not resolve a Stripe charge or payment intent for this order.', 'restore-paypal-standard-for-woocommerce' ) );
        }

        $refunded_any = false;

        foreach ( $charges as $charge ) {
            if ( $remaining_cents <= 0 ) {
                break;
            }
            if ( $charge['refundable'] <= 0 ) {
                continue;
            }

            $this_cents = min( $remaining_cents, $charge['refundable'] );
            $refund     = RPSFW_Stripe_API::create_refund( $charge['id'], $this_cents, $reason );

            if ( is_wp_error( $refund ) ) {
                self::log( 'Refund failed for charge ' . $charge['id'] . ': ' . $refund->get_error_message(), 'error' );
                continue;
            }

            $remaining_cents -= $this_cents;
            $refunded_any     = true;

            $order->add_order_note(
                sprintf(
                    /* translators: 1: refund amount, 2: Stripe charge id, 3: Stripe refund id. */
                    __( 'Stripe refund of %1$s completed (charge: %2$s, Refund ID: %3$s).', 'restore-paypal-standard-for-woocommerce' ),
                    wc_price( RPSFW_Stripe_API::format_stripe_amount( $this_cents, $currency ), array( 'currency' => $currency ) ),
                    $charge['id'],
                    $refund->id
                )
            );

            self::log( 'Refund of ' . $this_cents . ' cents completed for order #' . $order_id . ' via ' . $charge['id'] . ' (Refund ID: ' . $refund->id . ')' );
        }

        if ( ! $refunded_any ) {
            return new WP_Error( 'error', __( 'Refund failed: Stripe did not accept the refund. Check the order\'s Stripe charges.', 'restore-paypal-standard-for-woocommerce' ) );
        }

        // Couldn't cover the full requested amount across the available
        // charges. Report it so WooCommerce doesn't record a larger refund than
        // Stripe actually processed. Any amount already refunded above is
        // reconciled by the charge.refunded webhook.
        if ( $remaining_cents > 1 ) {
            $short = RPSFW_Stripe_API::format_stripe_amount( $remaining_cents, $currency );
            self::log( 'Refund shortfall for order #' . $order_id . ': ' . $short . ' could not be refunded.', 'error' );
            return new WP_Error(
                'error',
                sprintf(
                    /* translators: %s: unrefunded amount. */
                    __( 'Only part of the refund could be processed on Stripe; %s could not be refunded. Please reconcile manually.', 'restore-paypal-standard-for-woocommerce' ),
                    wc_price( $short, array( 'currency' => $currency ) )
                )
            );
        }

        self::log( 'Refund completed for order #' . $order_id );

        return true;
    }

    /**
     * Collect every UNIQUE Stripe charge that backs an order, with how much can
     * still be refunded on each. Most orders resolve to one charge, but the
     * independent multi-schedule subscription flow charges each subscription's
     * first invoice separately under one parent order.
     *
     * Sources gathered from the order and its subscriptions may reference the
     * same charge in different id forms (a pi_ and its ch_). Everything is
     * normalised to the underlying charge object and deduped by charge id, so a
     * charge is never refunded twice.
     *
     * @param WC_Order $order Order.
     * @return array<int,array{id:string,refundable:int}> Unique charges.
     */
    private function collect_refund_charges( $order ) {
        $raw = array();

        // Order-level source (regular, single-schedule, renewal orders resolve
        // to exactly one charge/PI). If found, that's the only charge for this
        // order — use it alone.
        $single = $this->resolve_refund_source( $order );
        if ( ! is_wp_error( $single ) && $single ) {
            $raw[] = $single;
        } elseif ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
            // No single order-level source: this is the independent
            // multi-schedule parent order, which is backed by one charge per
            // subscription. Gather each parent subscription's charge.
            $subs = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) );
            foreach ( $subs as $sub ) {
                $charge_id = $sub->get_meta( '_rpsfw_stripe_charge_id' );
                $pi        = $sub->get_meta( '_rpsfw_stripe_payment_intent_id' );

                // Fallback: if neither was recorded (e.g. the initial-invoice
                // backfill hasn't run yet), resolve them live from the Stripe
                // subscription's latest invoice. For a not-yet-renewed
                // subscription the latest invoice IS the initial one.
                if ( ! $charge_id && ! $pi ) {
                    $stripe_sub_id = $sub->get_meta( '_rpsfw_stripe_subscription_id' );
                    if ( $stripe_sub_id ) {
                        $stripe_sub = RPSFW_Stripe_API::retrieve_subscription( $stripe_sub_id, array() );
                        if ( ! is_wp_error( $stripe_sub ) && ! empty( $stripe_sub->latest_invoice ) ) {
                            $inv_id = is_object( $stripe_sub->latest_invoice ) ? $stripe_sub->latest_invoice->id : (string) $stripe_sub->latest_invoice;
                            $refs   = RPSFW_Stripe_API::get_invoice_payment_refs( $inv_id );
                            if ( ! empty( $refs['charge'] ) ) {
                                $charge_id = $refs['charge'];
                            } elseif ( ! empty( $refs['payment_intent'] ) ) {
                                $pi = $refs['payment_intent'];
                            }
                        }
                    }
                }

                if ( $charge_id ) {
                    $raw[] = $charge_id;
                } elseif ( $pi ) {
                    $raw[] = $pi;
                }
            }
        }

        // Normalise each source to its charge object and dedupe by charge id.
        $charges = array();
        foreach ( array_unique( array_filter( $raw ) ) as $source ) {
            $charge = $this->resolve_charge_object( $source );
            if ( ! $charge || empty( $charge->id ) ) {
                continue;
            }
            if ( isset( $charges[ $charge->id ] ) ) {
                continue;
            }
            $amount   = isset( $charge->amount ) ? (int) $charge->amount : 0;
            $refunded = isset( $charge->amount_refunded ) ? (int) $charge->amount_refunded : 0;
            $charges[ $charge->id ] = array(
                'id'         => $charge->id,
                'refundable' => max( 0, $amount - $refunded ),
            );
        }

        return array_values( $charges );
    }

    /**
     * Resolve a source id (charge ch_ or PaymentIntent pi_) to its Stripe
     * Charge object.
     *
     * @param string $source Charge or PaymentIntent id.
     * @return \Stripe\Charge|object|null
     */
    private function resolve_charge_object( $source ) {
        if ( 0 === strpos( $source, 'ch_' ) ) {
            $charge = RPSFW_Stripe_API::retrieve_charge( $source );
            return is_wp_error( $charge ) ? null : $charge;
        }
        if ( 0 === strpos( $source, 'pi_' ) ) {
            $intent = RPSFW_Stripe_API::retrieve_payment_intent( $source );
            if ( is_wp_error( $intent ) ) {
                return null;
            }
            return RPSFW_Stripe_API::get_charge_from_intent( $intent );
        }
        return null;
    }

    /**
     * Determine the Stripe id that can actually be refunded for an order.
     *
     * Regular payments store a charge / payment intent, but subscription
     * orders record the subscription id (sub_...) as the transaction id and
     * renewal orders record the invoice id (in_...). Neither is refundable
     * directly, so we resolve the underlying charge or payment intent from
     * order meta (preferred) or by looking up the Stripe invoice.
     *
     * @param WC_Order $order Order being refunded.
     * @return string|WP_Error Charge id (ch_...) or payment intent id (pi_...), or error.
     */
    private function resolve_refund_source( $order ) {
        // 1. Explicit charge id recorded at payment time (regular payments).
        $charge_id = $order->get_meta( '_rpsfw_stripe_charge_id' );
        if ( $charge_id ) {
            return $charge_id;
        }

        // 2. Payment intent recorded at payment time (regular + first
        //    subscription invoice). create_refund() handles the pi_ prefix.
        $payment_intent_id = $order->get_meta( '_rpsfw_stripe_payment_intent_id' );
        if ( $payment_intent_id && strpos( $payment_intent_id, 'pi_' ) === 0 ) {
            return $payment_intent_id;
        }

        // 3. Transaction id, but only if it is itself a charge / payment intent.
        $transaction_id = $order->get_transaction_id();
        if ( $transaction_id && ( strpos( $transaction_id, 'pi_' ) === 0 || strpos( $transaction_id, 'ch_' ) === 0 ) ) {
            return $transaction_id;
        }

        // 4. Resolve via the Stripe invoice (subscription initial + renewal
        //    orders). Prefer an explicit invoice id, otherwise use a
        //    transaction id that is an invoice id.
        $invoice_id = $order->get_meta( '_rpsfw_stripe_invoice_id' );
        if ( ! $invoice_id && $transaction_id && strpos( $transaction_id, 'in_' ) === 0 ) {
            $invoice_id = $transaction_id;
        }

        if ( $invoice_id ) {
            $invoice = RPSFW_Stripe_API::retrieve_invoice( $invoice_id );
            if ( is_wp_error( $invoice ) ) {
                return $invoice;
            }
            $source = $this->extract_refund_source_from_invoice( $invoice );
            if ( $source ) {
                return $source;
            }
        }

        return new WP_Error(
            'error',
            __( 'Refund failed: could not resolve a Stripe charge or payment intent for this order.', 'restore-paypal-standard-for-woocommerce' )
        );
    }

    /**
     * Pull a refundable charge / payment intent id out of a Stripe Invoice,
     * tolerating the several shapes across Stripe API versions.
     *
     * @param \Stripe\Invoice $invoice Invoice object.
     * @return string Charge id, payment intent id, or '' if none found.
     */
    private function extract_refund_source_from_invoice( $invoice ) {
        // Legacy fields (pre-basil): invoice->charge / invoice->payment_intent.
        if ( ! empty( $invoice->charge ) ) {
            return is_object( $invoice->charge ) ? $invoice->charge->id : (string) $invoice->charge;
        }
        if ( ! empty( $invoice->payment_intent ) ) {
            return is_object( $invoice->payment_intent ) ? $invoice->payment_intent->id : (string) $invoice->payment_intent;
        }

        // Modern (basil+): invoice->payments->data[*]->payment->payment_intent.
        if ( ! empty( $invoice->payments ) && ! empty( $invoice->payments->data ) ) {
            foreach ( $invoice->payments->data as $payment ) {
                if ( empty( $payment->payment ) ) {
                    continue;
                }
                $pay = $payment->payment;
                if ( ! empty( $pay->payment_intent ) ) {
                    return is_object( $pay->payment_intent ) ? $pay->payment_intent->id : (string) $pay->payment_intent;
                }
                if ( ! empty( $pay->charge ) ) {
                    return is_object( $pay->charge ) ? $pay->charge->id : (string) $pay->charge;
                }
            }
        }

        return '';
    }

    /**
     * Load admin scripts.
     */
    public function admin_scripts() {
        $screen    = get_current_screen();
        $screen_id = $screen ? $screen->id : '';

        if ( 'woocommerce_page_wc-settings' !== $screen_id ) {
            return;
        }

        $section = isset($_GET['section']) ? sanitize_title($_GET['section']) : '';
        if ( $section !== 'rpsfw_stripe' ) {
            return;
        }

        // Version by file modification time so edits always bust the
        // browser/WordPress cache (RPSFW_VERSION alone doesn't change when we
        // edit the JS/CSS directly, so cached copies would otherwise persist).
        $admin_css_path = RPSFW_PLUGIN_DIR . 'assets/css/stripe-admin.css';
        $admin_js_path  = RPSFW_PLUGIN_DIR . 'assets/js/stripe-admin.js';
        $admin_css_ver  = file_exists( $admin_css_path ) ? (string) filemtime( $admin_css_path ) : RPSFW_VERSION;
        $admin_js_ver   = file_exists( $admin_js_path ) ? (string) filemtime( $admin_js_path ) : RPSFW_VERSION;

        // Enqueue admin CSS
        wp_enqueue_style( 'rpsfw-stripe-admin', RPSFW_PLUGIN_URL . 'assets/css/stripe-admin.css', array(), $admin_css_ver );

        // Enqueue admin JavaScript
        wp_enqueue_script( 'rpsfw-stripe-admin', RPSFW_PLUGIN_URL . 'assets/js/stripe-admin.js', array( 'jquery' ), $admin_js_ver, true );
        
        // Localize script with data
        wp_localize_script(
            'rpsfw-stripe-admin',
            'rpsfwStripe',
            array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'connection_nonce' => wp_create_nonce( 'rpsfw-stripe-connection' ),
                'webhook_nonce' => wp_create_nonce( 'rpsfw-stripe-webhook' ),
                'strings' => array(
                    'waiting' => __( 'Waiting for Stripe connection...', 'restore-paypal-standard-for-woocommerce' ),
                    'refreshing' => __( 'Refreshing...', 'restore-paypal-standard-for-woocommerce' ),
                    'connected' => __( 'Connected!', 'restore-paypal-standard-for-woocommerce' ),
                    /* translators: %s: environment name (live or test). */
                    'confirm_disconnect' => __( 'Are you sure you want to disconnect your Stripe account for %s?', 'restore-paypal-standard-for-woocommerce' ),
                    'confirm_delete_webhook' => __( 'Are you sure you want to delete this webhook?', 'restore-paypal-standard-for-woocommerce' ),
                    'creating_webhook' => __( 'Creating webhook...', 'restore-paypal-standard-for-woocommerce' ),
                    'deleting_webhook' => __( 'Deleting webhook...', 'restore-paypal-standard-for-woocommerce' ),
                    'checking_webhook' => __( 'Checking webhook...', 'restore-paypal-standard-for-woocommerce' ),
                    /* translators: %s: environment name (live or test). */
                    'switching_mode' => __( 'Switching to %s', 'restore-paypal-standard-for-woocommerce' ),
                    'saving_settings' => __( 'Saving settings...', 'restore-paypal-standard-for-woocommerce' ),
                ),
            )
        );
    }

    /**
     * Get payment method order
     *
     * @return array
     */
    public function get_payment_method_order() {
        $order = array();
        
        // Get cart amount and currency for filtering
        $amount = null;
        $currency = get_woocommerce_currency();
        
        if ( WC()->cart && ! WC()->cart->is_empty() ) {
            $amount = WC()->cart->total;
        }
        
        // Get filtered payment methods based on amount/currency requirements
        $available_methods = RPSFW_Stripe_API::get_enabled_payment_method_types( $amount, $currency );
        
        // Prioritize wallets first if enabled and available
        if ( 'yes' === $this->get_option( 'enable_apple_pay', 'no' ) && in_array( 'apple_pay', $available_methods, true ) ) {
            $order[] = 'apple_pay';
        }
        if ( 'yes' === $this->get_option( 'enable_google_pay', 'no' ) && in_array( 'google_pay', $available_methods, true ) ) {
            $order[] = 'google_pay';
        }
        if ( 'yes' === $this->get_option( 'enable_link', 'yes' ) && in_array( 'link', $available_methods, true ) ) {
            $order[] = 'link';
        }
        if ( 'yes' === $this->get_option( 'enable_cashapp', 'no' ) && in_array( 'cashapp', $available_methods, true ) ) {
            $order[] = 'cashapp';
        }
        
        // Then BNPL methods (only if they meet amount requirements)
        if ( 'yes' === $this->get_option( 'enable_klarna', 'no' ) && in_array( 'klarna', $available_methods, true ) ) {
            $order[] = 'klarna';
        }
        if ( 'yes' === $this->get_option( 'enable_afterpay', 'no' ) && in_array( 'afterpay_clearpay', $available_methods, true ) ) {
            $order[] = 'afterpay_clearpay';
        }
        if ( 'yes' === $this->get_option( 'enable_affirm', 'no' ) && in_array( 'affirm', $available_methods, true ) ) {
            $order[] = 'affirm';
        }
        
        // Card always last
        if ( 'yes' === $this->get_option( 'enable_card', 'yes' ) && in_array( 'card', $available_methods, true ) ) {
            $order[] = 'card';
        }
        
        return apply_filters( 'rpsfw_stripe_payment_method_order', $order, $this );
    }

    /**
     * Get wallets configuration
     *
     * @return array
     */
    public function get_wallets_config() {
        // Configure wallet display behavior
        // 'auto' = show if available on device/browser
        // 'never' = don't show even if available
        // Driven directly by the Express Checkout (Digital Wallets) toggles in
        // the gateway settings. 'auto' = show when the device/browser supports
        // the wallet and the domain is registered (we auto-register on connect);
        // 'never' = suppress. Apple Pay and Google Pay ride on the card rails
        // inside the Payment Element, so no separate payment_method_types entry
        // is required.
        //
        // NOTE: Link is NOT part of the wallets configuration. Link is
        // automatically enabled by Stripe when the customer's email is passed to
        // the Payment Element via defaultValues.billingDetails.email. The
        // enable_link setting controls whether we pass that email (see the
        // link_enabled flag passed to JS, which the frontend uses to decide
        // whether to include the email in the payment element options).
        // DISABLED FOR THIS RELEASE — Apple Pay / Google Pay are not shipping
        // yet, so force both to 'never'. Restore the settings-driven values
        // below (and un-comment the wallet settings fields) to re-enable.
        $config = array(
            'applePay'  => 'never',
            'googlePay' => 'never',
        );
        /*
        $config = array(
            'applePay'  => ( 'yes' === $this->get_option( 'enable_apple_pay', 'no' ) ) ? 'auto' : 'never',
            'googlePay' => ( 'yes' === $this->get_option( 'enable_google_pay', 'no' ) ) ? 'auto' : 'never',
        );
        */

        return apply_filters( 'rpsfw_stripe_wallets_config', $config, $this );
    }

    /**
     * Get appearance options for Payment Element
     *
     * @return array
     */
    public function get_appearance_options() {
        $theme = $this->get_option( 'theme', 'stripe' );
        $customize = $this->get_option( 'customize_appearance', 'no' );
        
        $appearance = array();
        
        // Only add theme if not 'none'
        if ( $theme !== 'none' ) {
            $appearance['theme'] = $theme;
        }
        
        // Only add custom variables if customization is enabled
        if ( $customize === 'yes' ) {
            $label_type = $this->get_option( 'label_type', 'floating' );
            
            // Build font family
            $font_family_option = $this->get_option( 'font_family', 'system' );
            $font_families = array(
                'system'     => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif',
                'arial'      => 'Arial, sans-serif',
                'helvetica'  => '"Helvetica Neue", Helvetica, Arial, sans-serif',
                'georgia'    => 'Georgia, serif',
                'times'      => '"Times New Roman", Times, serif',
                'courier'    => '"Courier New", Courier, monospace',
                'verdana'    => 'Verdana, Geneva, sans-serif',
                'trebuchet'  => '"Trebuchet MS", Helvetica, sans-serif',
            );
            
            if ( $font_family_option === 'custom' ) {
                $font_family = $this->get_option( 'font_family_custom', $font_families['system'] );
            } else {
                $font_family = isset( $font_families[$font_family_option] ) ? $font_families[$font_family_option] : $font_families['system'];
            }
            
            $appearance['variables'] = array(
                'colorPrimary' => $this->get_option( 'color_primary', '#0570de' ),
                'colorBackground' => $this->get_option( 'color_background', '#ffffff' ),
                'colorText' => $this->get_option( 'color_text', '#30313d' ),
                'colorDanger' => $this->get_option( 'color_danger', '#df1b41' ),
                'fontFamily' => $font_family,
                'fontSizeBase' => $this->get_option( 'font_size', '16px' ),
                'spacingUnit' => $this->get_option( 'spacing_unit', '4px' ),
                'borderRadius' => $this->get_option( 'border_radius', '4px' ),
            );
            
            // Add labels configuration
            $appearance['labels'] = $label_type;
        }
        
        return apply_filters( 'rpsfw_stripe_appearance_options', $appearance, $this );
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
            self::$log->log( $level, $message, array( 'source' => 'rpsfw-stripe' ) );
        }
    }

    /**
     * Add capture payment order action
     *
     * @param array $actions Order actions
     * @return array
     */
    public function add_capture_order_action( $actions ) {
        global $theorder;

        if ( ! is_object( $theorder ) ) {
            return $actions;
        }

        // Only add action if order was paid with this gateway and is on-hold
        if ( $theorder->get_payment_method() === $this->id && $theorder->has_status( 'on-hold' ) ) {
            $transaction_id = $theorder->get_transaction_id();
            
            // Check if this is an authorized payment
            if ( ! empty( $transaction_id ) && strpos( $transaction_id, 'pi_' ) === 0 ) {
                $actions['rpsfw_stripe_capture_payment'] = __( 'Capture Stripe payment', 'restore-paypal-standard-for-woocommerce' );
            }
        }

        return $actions;
    }

    /**
     * Process capture payment order action
     *
     * @param WC_Order $order Order object
     */
    public function process_capture_order_action( $order ) {
        $transaction_id = $order->get_transaction_id();

        if ( empty( $transaction_id ) ) {
            $order->add_order_note( __( 'Unable to capture payment: No transaction ID found.', 'restore-paypal-standard-for-woocommerce' ) );
            return;
        }

        self::log( 'Capturing authorized payment for order #' . $order->get_id() );

        // Capture the payment intent
        $intent = RPSFW_Stripe_API::capture_payment_intent( $transaction_id );

        if ( is_wp_error( $intent ) ) {
            self::log( 'Failed to capture payment: ' . $intent->get_error_message(), 'error' );
            $order->add_order_note(
                sprintf(
                    /* translators: %s: error message. */
                    __( 'Failed to capture Stripe payment: %s', 'restore-paypal-standard-for-woocommerce' ),
                    $intent->get_error_message()
                )
            );
            return;
        }

        // Update order status
        $order->payment_complete( $transaction_id );
        
        $order->add_order_note(
            sprintf(
                /* translators: %s: Stripe payment intent ID. */
                __( 'Stripe payment captured successfully (Payment Intent ID: %s)', 'restore-paypal-standard-for-woocommerce' ),
                $transaction_id
            )
        );

        self::log( 'Payment captured successfully for order #' . $order->get_id() );
    }
}
