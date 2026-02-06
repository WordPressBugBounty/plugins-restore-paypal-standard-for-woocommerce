<?php
/**
 * PayPal Standard Payment Gateway.
 *
 * Provides a PayPal Standard Payment Gateway for WooCommerce.
 *
 * @class       rpsfw_Gateway_PayPal_Standard
 * @extends     WC_Payment_Gateway
 * @version     1.0.0
 * @package     WooPayPalStandard
 */

defined( 'ABSPATH' ) || exit;

// Ensure required files are loaded
$required_files = array(
    'paypal-standard-response.php',
    'paypal-standard-request.php',
    'paypal-standard-ipn-handler.php',
    'paypal-standard-pdt-handler.php',
    'paypal-standard-api-handler.php'
);

foreach ($required_files as $file) {
    $file_path = RPSFW_PLUGIN_DIR . 'includes/paypal-standard/' . $file;
    if (file_exists($file_path)) {
        require_once $file_path;
    } else {
        if (function_exists('rpsfw_debug_log')) {
            rpsfw_debug_log('ERROR: Required PayPal file not found: ' . $file_path);
        }
    }
}

require_once RPSFW_PLUGIN_DIR . 'includes/paypal-standard-settings.php';

/**
 * rpsfw_Gateway_PayPal_Standard Class.
 */
class rpsfw_Gateway_PayPal_Standard extends WC_Payment_Gateway {

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
     * @var rpsfw_Gateway_PayPal_Standard_Settings
     */
    public $settings_handler;

    /**
     * Test mode flag
     *
     * @var bool
     */
    public $testmode;

    /**
     * PayPal email address
     *
     * @var string
     */
    public $email;

    /**
     * PayPal identity token
     *
     * @var string
     */
    public $identity_token;

    /**
     * Invoice prefix
     *
     * @var string
     */
    public $invoice_prefix;

    /**
     * Debug mode flag
     *
     * @var bool
     */
    public $debug;

    /**
     * Receiver email address
     *
     * @var string
     */
    public $receiver_email;

    /**
     * Constructor for the gateway.
     */
    public function __construct() {
        // Load the Response class, which is the parent for IPN and PDT handlers
        if (!class_exists('rpsfw_Gateway_PayPal_Standard_Response') && 
            file_exists(RPSFW_PLUGIN_DIR . 'includes/paypal-standard/paypal-standard-response.php')) {
            require_once RPSFW_PLUGIN_DIR . 'includes/paypal-standard/paypal-standard-response.php';
        }

        $this->id                = 'restore_paypal_standard';
        $this->has_fields        = false;
        $this->method_title      = __( 'Restore PayPal Standard', 'restore-paypal-standard-for-woocommerce' );
        $this->method_description = __( 'Restore PayPal Standard brings back PayPal Standard as a payment gateway for WooCommerce.', 'restore-paypal-standard-for-woocommerce' );
        $this->supports           = array(
            'products',
            'refunds',
        );

        // Initialize the settings handler
        $this->settings_handler = new rpsfw_Gateway_PayPal_Standard_Settings($this);
        
        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();
        
        // Define properties using direct settings access
        $this->title = $this->get_option('title', __('PayPal', 'restore-paypal-standard-for-woocommerce'));
        $this->description = $this->get_option('description', __("Pay with PayPal - You can pay with your credit card if you don't have a PayPal account.", 'restore-paypal-standard-for-woocommerce'));
        $this->testmode = 'yes' === $this->get_option('testmode', 'no');
        $this->email = $this->testmode ? $this->get_option('sandbox_email', '') : $this->get_option('email', '');
        $this->identity_token = $this->get_option('identity_token', '');
        $this->invoice_prefix = $this->get_option('invoice_prefix', 'WC-');
        $this->order_button_text = $this->get_option('checkout_button_text', __('Proceed to PayPal', 'restore-paypal-standard-for-woocommerce'));
        $this->debug = $this->get_option('debug_enabled') === 'yes';
        $this->icon = plugins_url( 'assets/images/paypal-logo.svg', RPSFW_PLUGIN_FILE );
        self::$log_enabled = $this->debug;
        $this->receiver_email = $this->get_option('receiver_email', '');
        
        // Add sandbox messaging if in test mode
        if ( $this->testmode ) {
            $this->description .= '<br><br>' . sprintf( __( 'SANDBOX ENABLED. You can use sandbox testing accounts only. See the %s for more details.', 'restore-paypal-standard-for-woocommerce' ), '<a target="_blank" href="https://wpplugin.org/documentation/sandbox-mode/">' . __( 'PayPal Sandbox Testing Guide', 'restore-paypal-standard-for-woocommerce' ) . '</a>' );
            $this->description  = trim( $this->description );
        }

        // Actions
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_order_status_processing', array( $this, 'capture_payment' ) );
        add_action( 'woocommerce_order_status_completed', array( $this, 'capture_payment' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );

        if ( ! $this->is_valid_for_use() ) {
            $this->enabled = 'no';
        } else {
            $this->init_handlers();
        }

        if ( 'yes' === $this->enabled ) {
            add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'order_received_text' ), 10, 2 );
            // Add the payment mode to order meta data display in admin
            add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'display_paypal_mode_in_order_meta' ) );
            // Add our hook to the thankyou page
            add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );
        }
    }

    /**
     * Initialize IPN and PDT handlers
     */
    private function init_handlers() {
        // Determine which email to use for validation
        $validation_email = !empty($this->receiver_email) ? $this->receiver_email : $this->email;
        
        // Log which email is being used for validation
        self::log('Using email for IPN/PDT validation: ' . $validation_email . 
                 (!empty($this->receiver_email) ? ' (from receiver_email)' : ' (from payment email)'));
        
        // Load IPN handler
        if (file_exists(RPSFW_PLUGIN_DIR . 'includes/paypal-standard/paypal-standard-ipn-handler.php')) {
            new rpsfw_Gateway_PayPal_Standard_IPN_Handler($this->testmode, $validation_email);
            self::log('IPN handler loaded and initialized');
        }
        
        // Load PDT handler if identity token is set
        if (!empty($this->identity_token)) {
            $pdt_handler_file = RPSFW_PLUGIN_DIR . 'includes/paypal-standard/paypal-standard-pdt-handler.php';
            if (file_exists($pdt_handler_file)) {
                include_once $pdt_handler_file;
                if (class_exists('rpsfw_Gateway_PayPal_Standard_PDT_Handler')) {
                    $pdt_handler = new rpsfw_Gateway_PayPal_Standard_PDT_Handler( $this->testmode, $this->identity_token );
                    $pdt_handler->set_receiver_email($validation_email);
                    if (function_exists('rpsfw_debug_log')) {
                        rpsfw_debug_log('PDT handler loaded and initialized with identity token');
                    }
                } else {
                    if (function_exists('rpsfw_debug_log')) {
                        rpsfw_debug_log('ERROR: PDT handler class does not exist after loading file');
                    }
                }
            } else {
                if (function_exists('rpsfw_debug_log')) {
                    rpsfw_debug_log('ERROR: PDT handler file not found: ' . $pdt_handler_file);
                }
            }
        }
    }

    /**
     * Setup form fields.
     */
    public function init_form_fields() {
        $this->form_fields = $this->settings_handler->get_form_fields();
    }

    /**
     * Check if this gateway is enabled and available for the current cart.
     *
     * @return bool
     */
    public function is_valid_for_use() {
        return $this->settings_handler->is_valid_for_use();
    }
    
    /**
     * Check if gateway is available for use.
     *
     * @return bool
     */
    public function is_available() {
        // Check if native PayPal is enabled - if so, hide this gateway at checkout
        // Use wp_cache_get to bypass object caching
        wp_cache_delete('woocommerce_restore_paypal_standard_settings', 'options');
        $settings = get_option('woocommerce_restore_paypal_standard_settings', array());
        $enable_native_paypal = isset($settings['enable_native_paypal']) && $settings['enable_native_paypal'] === 'yes';
        
        if ($enable_native_paypal && !is_admin()) {
            return false;
        }
        
        $is_available = parent::is_available();
        
        if (!$is_available) {
            return false;
        }
        
        if (!$this->is_valid_for_use()) {
            return false;
        }
        
        // Check if email is configured
        if (empty($this->email)) {
            return false;
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
        return $this->settings_handler->needs_setup();
    }

    /**
     * Output for the order received page.
     *
     * @param string   $text Order received text.
     * @param WC_Order $order Order object.
     * @return string
     */
    public function order_received_text( $text, $order ) {
        if ( $order && $order->get_payment_method() === $this->id ) {
            return $this->settings_handler->get_order_received_text($text, $order);
        }
        return $text;
    }

    /**
     * Get transaction URL.
     *
     * @param WC_Order $order Order object.
     * @return string
     */
    public function get_transaction_url( $order ) {
        if ( $this->testmode ) {
            $this->view_transaction_url = 'https://www.sandbox.paypal.com/cgi-bin/webscr?cmd=_view-a-trans&id=%s';
        } else {
            $this->view_transaction_url = 'https://www.paypal.com/cgi-bin/webscr?cmd=_view-a-trans&id=%s';
        }
        return parent::get_transaction_url( $order );
    }

    /**
     * Process the payment and return the result.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment( $order_id ) {
        include_once RPSFW_PLUGIN_DIR . 'includes/paypal-standard/paypal-standard-request.php';

        $order = wc_get_order( $order_id );


        // Add order note if it's a sandbox payment
        if ($this->testmode) {
            $order->add_order_note(__('This is a PayPal Sandbox payment.', 'restore-paypal-standard-for-woocommerce'));
        }
     
        // Add order note if payment action is set to authorize only
        $payment_action = $this->get_option('paymentaction');
        if ($payment_action === 'authorization') {
            $order->add_order_note(__('This PayPal payment is authorized only (not captured).', 'restore-paypal-standard-for-woocommerce'));
        }

        // Save payment mode to order meta
        $order->update_meta_data('_paypal_payment_mode', $this->testmode ? 'sandbox' : 'live');
        $order->save();
        
        $paypal_request = new rpsfw_Gateway_PayPal_Standard_Request( $this );

        return array(
            'result'   => 'success',
            'redirect' => $paypal_request->get_request_url( $order, $this->testmode ),
        );
    }

    /**
     * Can the order be refunded via PayPal?
     *
     * @param  WC_Order $order Order object.
     * @return bool
     */
    public function can_refund_order( $order ) {
        $has_api_creds = false;

        if ( $this->testmode ) {
            $has_api_creds = $this->get_option( 'sandbox_api_username' ) && $this->get_option( 'sandbox_api_password' ) && $this->get_option( 'sandbox_api_signature' );
        } else {
            $has_api_creds = $this->get_option( 'api_username' ) && $this->get_option( 'api_password' ) && $this->get_option( 'api_signature' );
        }

        return $order && $order->get_transaction_id() && $has_api_creds;
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

        if ( ! $this->can_refund_order( $order ) ) {
            return new WP_Error( 'error', __( 'Refund failed.', 'restore-paypal-standard-for-woocommerce' ) );
        }

        include_once RPSFW_PLUGIN_DIR . 'includes/paypal-standard/paypal-standard-api-handler.php';

        $api_handler = new rpsfw_Gateway_PayPal_Standard_API_Handler( 
            $this->testmode,
            $this->testmode ? $this->get_option( 'sandbox_api_username' ) : $this->get_option( 'api_username' ),
            $this->testmode ? $this->get_option( 'sandbox_api_password' ) : $this->get_option( 'api_password' ),
            $this->testmode ? $this->get_option( 'sandbox_api_signature' ) : $this->get_option( 'api_signature' )
        );

        self::log( 'Refund request for order #' . $order_id );

        $result = $api_handler->refund_transaction( $order, $amount, $reason );

        if ( is_wp_error( $result ) ) {
            self::log( 'Refund failed: ' . $result->get_error_message() );
            return $result;
        }

        self::log( 'Refund result: ' . wc_print_r( $result, true ) );

        switch ( strtolower( $result['ACK'] ) ) {
            case 'success':
            case 'successwithwarning':
                $order->add_order_note(
                    sprintf( __( 'Refunded %1$s - Refund ID: %2$s', 'restore-paypal-standard-for-woocommerce' ), $result['GROSSREFUNDAMT'], $result['REFUNDTRANSACTIONID'] )
                );
                return true;
        }

        return isset( $result['L_LONGMESSAGE0'] ) ? new WP_Error( 'error', $result['L_LONGMESSAGE0'] ) : false;
    }

    /**
     * Capture payment when the order is changed from on-hold to complete or processing.
     *
     * @param int $order_id Order ID.
     */
    public function capture_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( $order->get_payment_method() === $this->id ) {
            $transaction_id = $order->get_transaction_id();
            $captured = $order->get_meta( '_paypal_payment_captured' );

            if ( $transaction_id && 'yes' !== $captured ) {
                include_once RPSFW_PLUGIN_DIR . 'includes/paypal-standard/paypal-standard-api-handler.php';

                $api_handler = new rpsfw_Gateway_PayPal_Standard_API_Handler( 
                    $this->testmode,
                    $this->testmode ? $this->get_option( 'sandbox_api_username' ) : $this->get_option( 'api_username' ),
                    $this->testmode ? $this->get_option( 'sandbox_api_password' ) : $this->get_option( 'api_password' ),
                    $this->testmode ? $this->get_option( 'sandbox_api_signature' ) : $this->get_option( 'api_signature' )
                );

                self::log( 'Capture payment for order #' . $order_id );

                $result = $api_handler->capture_payment( $order, $transaction_id );

                if ( is_wp_error( $result ) ) {
                    self::log( 'Capture failed: ' . $result->get_error_message() );
                } else {
                    self::log( 'Capture result: ' . wc_print_r( $result, true ) );

                    if ( 'success' === strtolower( $result['ACK'] ) ) {
                        $order->add_order_note( __( 'PayPal payment captured', 'restore-paypal-standard-for-woocommerce' ) );
                        $order->update_meta_data( '_paypal_payment_captured', 'yes' );
                        $order->save();
                    }
                }
            }
        }
    }

    /**
     * Load admin scripts.
     */
    public function admin_scripts() {
        $screen    = get_current_screen();
        $screen_id = $screen ? $screen->id : '';

        // Only load on WooCommerce settings pages
        if ( 'woocommerce_page_wc-settings' !== $screen_id ) {
            return;
        }

        // Only load when we're on our settings pages
        $section = isset($_GET['section']) ? sanitize_title($_GET['section']) : '';
        if ( $section !== 'restore_paypal_standard' ) {
            return;
        }

        $suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

        // Enqueue admin assets
        wp_enqueue_script( 'rpsfw-paypal-admin', RPSFW_PLUGIN_URL . 'assets/js/paypal-standard-admin' . $suffix . '.js', array( 'jquery' ), RPSFW_VERSION, true );
        wp_enqueue_style( 'rpsfw-paypal-admin-styles', RPSFW_PLUGIN_URL . 'assets/css/paypal-standard-admin.css', array(), RPSFW_VERSION );
    }

    /**
     * Custom PayPal order received text.
     *
     * @param string $text Default text.
     * @param WC_Order $order Order data.
     * @return string
     */
    public function thankyou_page( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( $order && $order->get_payment_method() === $this->id ) {
            $this->settings_handler->output_thankyou_page( $order );
        }
    }

    /**
     * Display the PayPal mode in order meta data
     *
     * @param WC_Order $order Order object.
     */
    public function display_paypal_mode_in_order_meta( $order ) {
        if ( $order->get_payment_method() !== $this->id ) {
            return;
        }
        
        $mode = $order->get_meta('_paypal_payment_mode');
        if ($mode) {
            ?>
            <p class="form-field form-field-wide">
                <span><?php esc_html_e('PayPal Mode:', 'restore-paypal-standard-for-woocommerce'); ?></span>
                <strong><?php echo esc_html(ucfirst($mode)); ?></strong>
            </p>
            <?php
        }
    }

    /**
     * Get gateway icon.
     *
     * @return string
     */
    public function get_icon() {
        return $this->settings_handler->get_icon();
    }
    
    /**
     * Get the title for the payment method.
     * Returns empty string on frontend so only icon shows.
     *
     * @return string
     */
    public function get_title() {
        // Return empty string on frontend (checkout) so only icon displays
        if ( ! is_admin() ) {
            return '';
        }
        
        // Return title in admin
        return parent::get_title();
    }

    /**
     * Logging method.
     *
     * @param string $message Log message.
     * @param string $level   Optional. Default 'info'. Possible values:
     *                        emergency|alert|critical|error|warning|notice|info|debug.
     */
    public static function log( $message, $level = 'info' ) {
        if ( self::$log_enabled ) {
            if ( empty( self::$log ) ) {
                self::$log = wc_get_logger();
            }
            self::$log->log( $level, $message, array( 'source' => 'restore-paypal-standard' ) );
        }
    }
} 