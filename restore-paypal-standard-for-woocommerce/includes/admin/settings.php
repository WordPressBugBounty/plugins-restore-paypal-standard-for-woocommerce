<?php
/**
 * Settings for PayPal Standard Gateway.
 *
 * @package WooPayPalStandard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get settings for the specified section.
 *
 * @param string $section Section ID (general, advanced, debug)
 * @return array Settings fields
 */
if ( ! function_exists( 'rpsfw_get_settings_for_section' ) ) {
    function rpsfw_get_settings_for_section($section = 'general') {
        $all_settings = array(
            'general' => array(
                'general_section' => array(
                    'title'       => __( 'General Settings', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'title',
                    'description' => __( 'Configure your PayPal Standard payment gateway settings.', 'restore-paypal-standard-for-woocommerce' ),
                    'class'       => 'rpsfw-section-title',
                ),
                'enabled' => array(
                    'title'   => __( 'Enable/Disable', 'restore-paypal-standard-for-woocommerce' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Enable PayPal Standard', 'restore-paypal-standard-for-woocommerce' ),
                    'default' => 'no',
                ),
                'testmode' => array(
                    'title'       => __( 'Mode', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'select',
                    'description' => __( 'Use Sandbox mode to test payments. Sandbox mode automatically enables logging.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'no',
                    'desc_tip'    => true,
                    'options'     => array(
                        'no'    => __( 'Live Mode', 'restore-paypal-standard-for-woocommerce' ),
                        'yes' => __( 'Sandbox Mode (Testing)', 'restore-paypal-standard-for-woocommerce' ),
                    ),
                    'class'       => 'wc-enhanced-select rpsfw-testmode-selector',
                ),

                'email' => array(
                    'title'       => __( 'PayPal Email', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'email',
                    'description' => __( 'Please enter your PayPal email address; this is needed in order to accept payments.', 'restore-paypal-standard-for-woocommerce' ),
                    //'default'     => get_option( 'admin_email' ),
                    'desc_tip'    => true,
                    //'placeholder' => 'you@youremail.com',
                    'class'       => 'rpsfw-live-field',
                ),
                'receiver_email' => array(
                    'title'       => __( 'Receiver Email', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'email',
                    'description' => __( 'If your primary PayPal email is different from the one entered above, enter it here. This is used solely to validate IPN requests as a security measure. It is not sent to PayPal during checkout, so you can safely leave it blank if both emails are the same.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => '',
                    'desc_tip'    => true,
                    //'placeholder' => __( 'PayPal primary email', 'restore-paypal-standard-for-woocommerce' ),
                ),
                'sandbox_email' => array(
                    'title'       => __( 'Sandbox PayPal Email', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'email',
                    'description' => sprintf( __( 'Enter the email of your PayPal sandbox account for testing. %s', 'restore-paypal-standard-for-woocommerce' ), '<a href="https://wpplugin.org/documentation/sandbox-mode/" target="_blank">' . __( 'Learn more about sandbox mode', 'restore-paypal-standard-for-woocommerce' ) . '</a>' ),
                    'default'     => '',
                    'desc_tip'    => __( 'A Sandbox account is used for testing with fake money to make sure things are working correctly. Enter the email of your PayPal sandbox account for testing.', 'restore-paypal-standard-for-woocommerce' ),
                    //'placeholder' => 'sandbox@example.com',
                    'class'       => 'rpsfw-sandbox-field',
                ),
                'paymentaction' => array(
                    'title'       => __( 'Payment Action', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'select',
                    'class'       => 'wc-enhanced-select',
                    'description' => __( 'Choose whether you wish to capture funds immediately or authorize payment only which you can capture at a later date.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'sale',
                    'desc_tip'    => true,
                    'options'     => array(
                        'sale'          => __( 'Capture', 'restore-paypal-standard-for-woocommerce' ),
                        'authorization' => __( 'Authorize', 'restore-paypal-standard-for-woocommerce' ),
                    ),
                ),
            ),
            'text' => array(
                'text_section' => array(
                    'title'       => __( 'Text Settings', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'title',
                    'description' => __( 'Customize the text shown to customers during the checkout and payment process.', 'restore-paypal-standard-for-woocommerce' ),
                    'class'       => 'rpsfw-section-title',
                ),
                'title' => array(
                    'title'       => __( 'Title', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'safe_text',
                    'description' => __( 'This controls the title which the user sees during checkout.', 'restore-paypal-standard-for-woocommerce' ),
                    'placeholder' => __( 'PayPal', 'restore-paypal-standard-for-woocommerce' ),
                    'default' 	  => __( 'PayPal', 'restore-paypal-standard-for-woocommerce' ),
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __( 'Description', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'textarea',
                    'desc_tip'    => true,
                    'description' => __( 'This controls the description which the user sees during checkout.', 'restore-paypal-standard-for-woocommerce' ),
                    'placeholder' => __( "Pay with PayPal - You can pay with your credit card if you don't have a PayPal account.", 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => __( "Pay with PayPal - You can pay with your credit card if you don't have a PayPal account.", 'restore-paypal-standard-for-woocommerce' ),
                ),
                'checkout_button_text' => array(
                    'title'       => __( 'Checkout Button Text', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'safe_text',
                    'description' => __( 'This controls the text on the PayPal checkout button.', 'restore-paypal-standard-for-woocommerce' ),
                    'placeholder' => __( 'Proceed to PayPal', 'restore-paypal-standard-for-woocommerce' ),
                    'default' => __( 'Proceed to PayPal', 'restore-paypal-standard-for-woocommerce' ),
                    'desc_tip'    => true,
                ),
                'order_received_text' => array(
                    'title'       => __( 'Order Received Text', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'textarea',
                    'description' => __( 'This text is displayed on the order received page for successful orders.', 'restore-paypal-standard-for-woocommerce' ),
                    'placeholder' => __( 'Thank you. Your order has been received', 'restore-paypal-standard-for-woocommerce' ),
                    'default' => __( 'Thank you. Your order has been received', 'restore-paypal-standard-for-woocommerce' ),
                    'desc_tip'    => true,
                ),
                'pending_order_received_text' => array(
                    'title'       => __( 'Pending Order Received Text', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'textarea',
                    'description' => __( 'This text is displayed on the order received page when payment is pending.', 'restore-paypal-standard-for-woocommerce' ),
                    'placeholder' => __( 'Thank you for your order. It is currently being processed. We are waiting for PayPal to authenticate the payment.', 'restore-paypal-standard-for-woocommerce' ),
                    'default' => __( 'Thank you for your order. It is currently being processed. We are waiting for PayPal to authenticate the payment.', 'restore-paypal-standard-for-woocommerce' ),
                    'desc_tip'    => true,
                ),
            ),
            'advanced' => array(
                'advanced_section' => array(
                    'title'       => __( 'Advanced Settings', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'title',
                    'description' => __( 'These are extra settings that are not required for the plugin to work. You can leave them blank if you are not sure what they are.', 'restore-paypal-standard-for-woocommerce' ),
                    'class'       => 'rpsfw-section-title',
                ),
                'invoice_prefix' => array(
                    'title'       => __( 'Invoice Prefix', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'safe_text',
                    'description' => __( 'Please enter a prefix for your invoice numbers. If you use your PayPal account for multiple stores ensure this prefix is unique as PayPal will not allow orders with the same invoice number.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'WC-',
                    'desc_tip'    => true,
                ),
                'identity_token' => array(
                    'title'       => __( 'PayPal Identity Token', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'safe_text',
                    'description' => __( 'Enter your PayPal identity token to enable Payment Data Transfer (PDT). PDT allows PayPal to send order details directly to your website after payment, which can help reduce fraudulent orders.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => '',
                    'desc_tip'    => true,
                    'placeholder' => __( 'Optional', 'restore-paypal-standard-for-woocommerce' ),
                ),
                'address_override' => array(
                    'title'       => __( 'Address Override', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable address override', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'no',
                    'description' => __( 'Should the shipping address entered by the customer durning checkout be used instead of the PayPal address? (We recommend keeping it disabled to avoid errors).', 'restore-paypal-standard-for-woocommerce' ),
                    'desc_tip'    => __( 'address_override is a parameter sent to PayPal during checkout that controls whether the shipping address from your WooCommerce checkout overrides the customer\'s PayPal-stored address. This is useful for strict shipping control and fraud prevention but it comes at the cost of more errors since the customer cannot change their address at PayPal checkout. We recommend you keep this turned off.', 'restore-paypal-standard-for-woocommerce' ),
                ),
                'image_url' => array(
                    'title'       => __( 'Checkout Image URL', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'safe_text',
                    'description' => __( 'Enter a URL to an image you want to display on checkout. Leave blank to use the default image.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => '',
                    'desc_tip'    => true,
                    'placeholder' => __( 'Optional', 'restore-paypal-standard-for-woocommerce' ),
                ),
                'api_credentials_title' => array(
                    'title'       => __( 'API Credentials', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'title',
                    'description' => sprintf( __( 'Enter your PayPal API credentials to process refunds via PayPal. Learn how to access your %s.', 'restore-paypal-standard-for-woocommerce' ), '<a href="https://wpplugin.org/documentation/how-to-request-paypal-api-signature-credentials/" target="_blank" rel="noopener noreferrer">' . __( 'PayPal API Credentials', 'restore-paypal-standard-for-woocommerce' ) . '</a>' ),
                    'class'       => 'rpsfw-section-title',
                ),
                'api_username' => array(
                    'title'       => __( 'Live API Username', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'safe_text',
                    'description' => __( 'Get your API credentials from PayPal.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => '',
                    'desc_tip'    => true,
                    'placeholder' => __( 'Optional', 'restore-paypal-standard-for-woocommerce' ),
                    'class'       => 'rpsfw-live-field',
                ),
                'api_password' => array(
                    'title'       => __( 'Live API Password', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'password',
                    'description' => __( 'Get your API credentials from PayPal.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => '',
                    'desc_tip'    => true,
                    'placeholder' => __( 'Optional', 'restore-paypal-standard-for-woocommerce' ),
                    'class'       => 'rpsfw-live-field',
                ),
                'api_signature' => array(
                    'title'       => __( 'Live API Signature', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'password',
                    'description' => __( 'Get your API credentials from PayPal.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => '',
                    'desc_tip'    => true,
                    'placeholder' => __( 'Optional', 'restore-paypal-standard-for-woocommerce' ),
                    'class'       => 'rpsfw-live-field',
                ),
                'sandbox_api_username' => array(
                    'title'       => __( 'Sandbox API Username', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'safe_text',
                    'description' => __( 'Get your API credentials from PayPal.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => '',
                    'desc_tip'    => true,
                    'placeholder' => __( 'Optional', 'restore-paypal-standard-for-woocommerce' ),
                    'class'       => 'rpsfw-sandbox-field',
                ),
                'sandbox_api_password' => array(
                    'title'       => __( 'Sandbox API Password', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'password',
                    'description' => __( 'Get your API credentials from PayPal.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => '',
                    'desc_tip'    => true,
                    'placeholder' => __( 'Optional', 'restore-paypal-standard-for-woocommerce' ),
                    'class'       => 'rpsfw-sandbox-field',
                ),
                'sandbox_api_signature' => array(
                    'title'       => __( 'Sandbox API Signature', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'password',
                    'description' => __( 'Get your API credentials from PayPal.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => '',
                    'desc_tip'    => true,
                    'placeholder' => __( 'Optional', 'restore-paypal-standard-for-woocommerce' ),
                    'class'       => 'rpsfw-sandbox-field',
                ),
            ),
            'debugging' => array(
                'debug_section' => array(
                    'title'       => __( 'Debugging Settings', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'title',
                    'description' => __( 'These settings help with troubleshooting PayPal Standard issues.', 'restore-paypal-standard-for-woocommerce' ),
                    'class'       => 'rpsfw-section-title',
                ),
                'debug_enabled' => array(
                    'title'       => __( 'Enable Debugging', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable debug logging', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'no',
                    'description' => __( 'Log PayPal events such as IPN requests. This may help to diagnose connection issues with PayPal. The logs will be saved in WooCommerce > Status > Logs.', 'restore-paypal-standard-for-woocommerce' ),
                ),
                'enable_native_paypal' => array(
                    'title'       => __( 'Enable Native PayPal Standard', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable native PayPal Standard', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'no',
                    'description' => __( 'DISCLAIMER: WooCommerce may remove this feature at any time. This option should not be used long-term as it relies on native code that might be removed in future WooCommerce updates. This option will display the native PayPal Standard gateway in the WooCommerce payment methods list, so you can configure it as you normally would. You may wish to also disable this gateway if you are having issues. <a href="https://wordpress.org/support/plugin/restore-paypal-standard-for-woocommerce/" target="_blank">Please let us know</a> if you experience any issues so we can fix it for you.</a>', 'restore-paypal-standard-for-woocommerce' ),
                ),
                'view_logs' => array(
                    'title'       => __( 'View Debug Logs', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'title',
                    'description' => sprintf(
                        __( 'You can view PayPal Standard logs in the <a href="%s">WooCommerce Status > Logs</a> section.', 'restore-paypal-standard-for-woocommerce' ),
                        esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) )
                    ),
                ),
            ),
        );

        // Return the settings for the specified section
        return isset($all_settings[$section]) ? $all_settings[$section] : $all_settings['general'];
    }
}

// If section parameter is provided, return that section's settings
$section = isset($_GET['section']) ? sanitize_text_field($_GET['section']) : 'general';
$section = isset($_GET['sub_section']) ? sanitize_text_field($_GET['sub_section']) : $section; // For backward compatibility

return rpsfw_get_settings_for_section($section); 