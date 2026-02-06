<?php
/**
 * PayPal Standard Payment Gateway Settings.
 *
 * Handles settings for the PayPal Standard Payment Gateway.
 *
 * @class       rpsfw_Gateway_PayPal_Standard_Settings
 * @version     1.0.0
 * @package     WooPayPalStandard
 */

defined( 'ABSPATH' ) || exit;

/**
 * rpsfw_Gateway_PayPal_Standard_Settings Class.
 */
class rpsfw_Gateway_PayPal_Standard_Settings {

    /**
     * The parent gateway object
     *
     * @var rpsfw_Gateway_PayPal_Standard
     */
    private $gateway;

    /**
     * Constructor.
     *
     * @param rpsfw_Gateway_PayPal_Standard $gateway The parent gateway.
     */
    public function __construct($gateway) {
        $this->gateway = $gateway;
    }

    /**
     * Get the PayPal title.
     *
     * @return string
     */
    public function get_title() {
        $title = $this->get_option('title');
        return empty($title) ? __('PayPal', 'restore-paypal-standard-for-woocommerce') : $title;
    }

    /**
     * Get the PayPal description.
     *
     * @return string
     */
    public function get_description() {
        $description = $this->get_option('description');
        return empty($description) ? __("Pay with PayPal - You can pay with your credit card if you don't have a PayPal account.", 'restore-paypal-standard-for-woocommerce') : $description;
    }

    /**
     * Get testmode setting.
     *
     * @return string
     */
    public function get_testmode() {
        return 'yes' === $this->get_option('testmode', 'no');
    }

    /**
     * Get email setting based on current mode.
     *
     * @return string
     */
    public function get_email() {
        $testmode = $this->get_testmode();
        
        // Get the appropriate email based on mode
        $email = $testmode ? 
            $this->get_option('sandbox_email') : 
            $this->get_option('email');
        
        return $email;
    }

    /**
     * Get identity token.
     *
     * @return string
     */
    public function get_identity_token() {
        return $this->get_option('identity_token');
    }

    /**
     * Get invoice prefix.
     *
     * @return string
     */
    public function get_invoice_prefix() {
        return $this->get_option('invoice_prefix', 'WC-');
    }

    /**
     * Get checkout button text.
     *
     * @return string
     */
    public function get_checkout_button_text() {
        $button_text = $this->get_option('checkout_button_text');
        return empty($button_text) ? __('Proceed to PayPal', 'restore-paypal-standard-for-woocommerce') : $button_text;
    }

    /**
     * Get debug mode.
     *
     * @return bool
     */
    public function get_debug() {
        return ($this->get_option('debug_enabled') === 'yes') || $this->get_testmode();
    }

    /**
     * Return whether or not this gateway still requires setup to function.
     *
     * When this gateway is toggled on via AJAX, if this returns true a
     * redirect will occur to the settings page instead.
     *
     * @return bool
     */
    public function needs_setup() {
        return ! is_email( $this->get_email() );
    }

    /**
     * Process and save admin options.
     */
    public function process_admin_options() {
        // Get all the form fields that were submitted
        $post_data = $this->gateway->get_post_data();
        
        // Process each form field
        foreach ($this->gateway->form_fields as $key => $field) {
            // Special handling for checkboxes - they don't appear in the post data when unchecked
            if (isset($field['type']) && $field['type'] === 'checkbox') {
                $field_key = $this->gateway->get_field_key($key);
                $this->gateway->settings[$key] = isset($post_data[$field_key]) ? 'yes' : 'no';
            } 
            // Process fields that are present in post data
            else if (isset($post_data[$this->gateway->get_field_key($key)])) {
                // Use WooCommerce's field validation methods
                $field_type = isset($field['type']) ? $field['type'] : '';
                $value = $post_data[$this->gateway->get_field_key($key)];
                
                // Use the appropriate validation method based on field type
                if (method_exists($this->gateway, 'validate_' . $field_type . '_field')) {
                    $value = $this->gateway->{'validate_' . $field_type . '_field'}($key, $value);
                } else {
                    // Default to text field validation if no specific method exists
                    $value = $this->gateway->validate_text_field($key, $value);
                }
                
                // Save to gateway settings
                $this->gateway->settings[$key] = $value;
            }
        }
        
        // Save the settings to our plugin's option
        update_option(
            'woocommerce_restore_paypal_standard_settings',
            apply_filters('woocommerce_settings_api_sanitized_fields_' . $this->gateway->id, $this->gateway->settings)
        );
        
        // Clear logs if debugging is disabled and we're not in sandbox mode
        if ('yes' !== $this->get_option('debug_enabled') && !$this->get_testmode()) {
            if (empty($this->gateway::$log)) {
                $this->gateway::$log = wc_get_logger();
            }
            $this->gateway::$log->clear('restore_paypal_standard');
        }
    }

    /**
     * Get form fields for settings.
     * 
     * @return array
     */
    public function get_form_fields() {
        // Get current sub section from URL
        $current_sub_section = isset($_GET['sub_section']) ? sanitize_title($_GET['sub_section']) : 'general';
        
        // Load appropriate settings for this section
        $form_fields = include RPSFW_PLUGIN_DIR . 'includes/admin/settings.php';
        
        return $form_fields;
    }

    /**
     * Get gateway icon.
     *
     * @return string
     */
    public function get_icon() {
        // We need a base country for the link to work, bail if in the unlikely event no country is set.
        $base_country = WC()->countries->get_base_country();
        if ( empty( $base_country ) ) {
            return '';
        }

        // Check if a custom image URL is set
        $custom_image_url = $this->get_option('image_url');
        
        // Use custom image URL if provided, otherwise use the PayPal logo
        $icon_url = !empty($custom_image_url) ? 
            $custom_image_url : 
            plugins_url( 'assets/images/paypal-logo.png', RPSFW_PLUGIN_FILE );
            
        $icon = '<img src="' . esc_attr( $icon_url ) . '" alt="' . esc_attr__( 'PayPal', 'restore-paypal-standard-for-woocommerce' ) . '" style="height:24px;width:auto;" />';
        
        return apply_filters( 'woocommerce_gateway_icon', $icon, $this->gateway->id );
    }

    /**
     * Check if this gateway is enabled and available in the user's country.
     *
     * @return bool
     */
    public function is_valid_for_use() {
        return in_array(
            get_woocommerce_currency(),
            apply_filters(
                'woocommerce_paypal_standard_supported_currencies',
                array( 'AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 'TRY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 'CHF', 'TWD', 'THB', 'GBP', 'RMB', 'RUB', 'INR' )
            ),
            true
        );
    }

    /**
     * Admin Panel Options.
     */
    public function admin_options() {
        if ( $this->is_valid_for_use() ) {
            // Get current tab/section
            $current_section = empty($_GET['section']) ? 'restore-paypal-standard' : sanitize_title($_GET['section']);
            $current_sub_section = isset($_GET['sub_section']) ? sanitize_title($_GET['sub_section']) : 'general';
            
            // Display title with breadcrumb and description before the tabs
            echo '<h2>';
            echo esc_html( $this->gateway->get_method_title() );
            echo '<small class="wc-admin-breadcrumb"><a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout')) . '" aria-label="' . esc_attr__('Return to payments', 'restore-paypal-standard-for-woocommerce') . '">⤴</a></small>';
            echo '</h2>';
            
            // Only show the sub-section tabs if we're on our tab
            if ($current_section === 'restore_paypal_standard') {
                echo '<ul class="subsubsub">';
                $sub_sections = array(
                    'general' => __('General', 'restore-paypal-standard-for-woocommerce'),
                    'text' => __('Text', 'restore-paypal-standard-for-woocommerce'),
                    'advanced' => __('Advanced', 'restore-paypal-standard-for-woocommerce'),
                    'debugging' => __('Debugging', 'restore-paypal-standard-for-woocommerce'),
                );
                $i = 0;
                $total = count($sub_sections);
                foreach ($sub_sections as $id => $label) {
                    $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=restore_paypal_standard&sub_section=' . $id);
                    $class = ($current_sub_section === $id) ? 'current' : '';
                    echo '<li><a href="' . esc_url($url) . '" class="' . $class . '">' . esc_html($label) . '</a>';
                    if (++$i < $total) {
                        echo ' | ';
                    }
                    echo '</li>';
                }
                echo '</ul>';
            }
            
            // Output the settings, but skip the title as we've already displayed it
            echo '<table class="form-table">' . $this->gateway->generate_settings_html( $this->gateway->get_form_fields(), false ) . '</table>';
        } else {
            ?>
            <div class="inline error">
                <p>
                    <strong><?php esc_html_e( 'Gateway disabled', 'restore-paypal-standard-for-woocommerce' ); ?></strong>: <?php esc_html_e( 'PayPal Standard does not support your store currency.', 'restore-paypal-standard-for-woocommerce' ); ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Set custom text on 'Thank you page' based on the order received.
     *
     * @param string   $text Text.
     * @param WC_Order $order Order.
     * @return string
     */
    public function get_order_received_text( $text, $order ) {
        if ( $order && $this->gateway->id === $order->get_payment_method() ) {
            if ( $order->has_status( array('on-hold', 'pending') ) ) {
                $pending_text = $this->get_option( 'pending_order_received_text' );
                if ( ! empty( $pending_text ) ) {
                    return esc_html( $pending_text );
                }
                // Fallback to default pending text
                return esc_html__( 'Thank you for your order. It is currently being processed. We are waiting for PayPal to authenticate the payment.', 'restore-paypal-standard-for-woocommerce' );
            } else {
                $custom_text = $this->get_option( 'order_received_text' );
                if ( ! empty( $custom_text ) ) {
                    return esc_html( $custom_text );
                }
            }
        }
        return $text;
    }

    /**
     * Get option from settings
     *
     * @param string $key Setting key to retrieve.
     * @param mixed $empty_value Value to return if option is empty.
     * @return string The value specified for the option or a default value for the option.
     */
    public function get_option( $key, $empty_value = '' ) {
        // Get from gateway settings
        if (isset($this->gateway->settings[$key])) {
            return $this->gateway->settings[$key];
        }
        
        return $empty_value;
    }

    /**
     * Output custom thank you page content.
     *
     * @param WC_Order $order Order object.
     */
    public function output_thankyou_page($order) {
        // If identity token is set, use the thankyou hook to process PDT
        if (!empty($this->get_identity_token()) && isset($_GET['tx'])) {
            // The PDT handler will automatically validate the transaction
            // as it's hooked into woocommerce_thankyou_paypal_standard
            do_action('woocommerce_thankyou_paypal_standard', $order->get_id());
        }
    }
} 