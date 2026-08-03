<?php
/**
 * Stripe Payment Gateway Settings.
 *
 * Handles settings for the Stripe Payment Gateway.
 *
 * @class       RPSFW_Gateway_Stripe_Settings
 * @version     1.0.0
 * @package     RestorePayPalStandard
 */

defined( 'ABSPATH' ) || exit;

/**
 * RPSFW_Gateway_Stripe_Settings Class.
 */
class RPSFW_Gateway_Stripe_Settings {

    /**
     * The parent gateway object
     *
     * @var RPSFW_Gateway_Stripe
     */
    private $gateway;

    /**
     * Constructor.
     *
     * @param RPSFW_Gateway_Stripe $gateway The parent gateway.
     */
    public function __construct($gateway) {
        $this->gateway = $gateway;
    }

    /**
     * Whether the Payment Options tab should be exposed in the admin.
     *
     * The initial release ships card-only. Cards are always enabled in the
     * payment logic (see RPSFW_Stripe_API::get_enabled_payment_method_types),
     * so hiding this tab keeps every other method off without touching the
     * payment flow. Flip this to true (or return true via the filter) when
     * additional payment methods have been tested and are ready to expose.
     *
     * @return bool
     */
    public static function payment_options_tab_enabled() {
        /**
         * Filter whether the Stripe Payment Options tab is shown.
         *
         * @param bool $enabled Default false for the card-only launch.
         */
        return (bool) apply_filters( 'rpsfw_stripe_show_payment_options_tab', false );
    }

    /**
     * Get form fields for settings.
     * 
     * @return array
     */
    public function get_form_fields() {
        $current_sub_section = isset($_GET['sub_section']) ? sanitize_title($_GET['sub_section']) : 'general';

        // Payment Options is hidden for the initial card-only launch. If the
        // tab is reached via a stale or hand-typed URL, fall back to General
        // so its fields are never rendered or saved.
        if ( 'payment_options' === $current_sub_section && ! self::payment_options_tab_enabled() ) {
            $current_sub_section = 'general';
        }

        $form_fields = array();

        // General Settings
        if ($current_sub_section === 'general') {
            $form_fields = array(
                'general_settings_title' => array(
                    'title'       => __( 'General Settings', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'title',
                    'description' => '<div style="max-width: 800px;">' . __( 'Configure basic Stripe settings.', 'restore-paypal-standard-for-woocommerce' ) . '</div>',
                ),
                'enabled' => array(
                    'title'   => __( 'Enable/Disable', 'restore-paypal-standard-for-woocommerce' ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Enable Stripe', 'restore-paypal-standard-for-woocommerce' ),
                    'default' => 'no',
                ),
                'testmode' => array(
                    'title'       => __( 'Mode', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'select',
                    'description' => __( 'Use Test mode to test payments. Test mode automatically enables logging.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'yes',
                    'desc_tip'    => true,
                    'options'     => array(
                        'no'  => __( 'Live Mode', 'restore-paypal-standard-for-woocommerce' ),
                        'yes' => __( 'Test Mode', 'restore-paypal-standard-for-woocommerce' ),
                    ),
                    'class'       => 'wc-enhanced-select',
                ),
                'connection_status' => array(
                    'title'       => sprintf(
                        /* translators: %s: mode (Test Mode or Live Mode) */
                        __( 'Stripe Connection (%s)', 'restore-paypal-standard-for-woocommerce' ),
                        $this->gateway->get_option('testmode', 'yes') === 'yes' 
                            ? __( 'Test Mode', 'restore-paypal-standard-for-woocommerce' ) 
                            : __( 'Live Mode', 'restore-paypal-standard-for-woocommerce' )
                    ),
                    'type'        => 'stripe_connection_status',
                    'description' => $this->gateway->get_option('testmode', 'yes') === 'yes' 
                        ? sprintf(
                            /* translators: %s: link to Stripe testing documentation */
                            __( 'In test mode you can use test cards numbers without real charges. <a href="%s" target="_blank">View test card numbers</a> in Stripe Documentation.', 'restore-paypal-standard-for-woocommerce' ),
                            'https://docs.stripe.com/testing#cards'
                        )
                        : '',
                ),
                'webhook_status' => array(
                    'title'       => sprintf(
                        /* translators: %s: mode (Test Mode or Live Mode) */
                        __( 'Webhooks (%s)', 'restore-paypal-standard-for-woocommerce' ),
                        $this->gateway->get_option('testmode', 'yes') === 'yes' 
                            ? __( 'Test Mode', 'restore-paypal-standard-for-woocommerce' ) 
                            : __( 'Live Mode', 'restore-paypal-standard-for-woocommerce' )
                    ),
                    'type'        => 'stripe_webhook_status',
                    'description' => __( 'Webhooks allow Stripe to notify your store about events like refunds processed from the Stripe dashboard, disputes, and chargebacks.', 'restore-paypal-standard-for-woocommerce' ),
                    'desc_tip'    => false,
                ),
                'statement_descriptor' => array(
                    'title'       => __( 'Statement Descriptor', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'text',
                    'desc_tip'    => __( 'Text that appears on your customer\'s credit card statement. Max 22 characters, no >, <, ", \', * and must not consist solely of numbers.', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'This field supports use of <code>{order_id}</code> to insert the WooCommerce order ID.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => '',
                    'placeholder' => '',
                    'custom_attributes' => array(
                        'maxlength' => '22',
                    ),
                ),
                'statement_descriptor_suffix' => array(
                    'title'       => __( 'Statement Descriptor Suffix', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'text',
                    'desc_tip'    => __( 'Appended after the descriptor prefix on card statements. Max 22 characters, no >, <, ", \', *.', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'This field supports use of <code>{order_id}</code> to insert the WooCommerce order ID.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => '',
                    'placeholder' => '',
                    'custom_attributes' => array(
                        'maxlength' => '22',
                    ),
                ),
            );
        }

        // Digital Wallets Settings (Express Checkout: Apple Pay / Google Pay / Link)
        if ($current_sub_section === 'digital_wallets') {
            $form_fields = array(
                'wallets_title' => array(
                    'title'       => __( 'Digital Wallets', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'title',
                    'description' => '<div style="max-width: 800px;">' . __( 'Stripe Link lets shoppers save their card for one-click checkout. (Apple Pay, Google Pay, and ACH bank payments are coming in a future release.)', 'restore-paypal-standard-for-woocommerce' ) . '</div>',
                ),
                'enable_link' => array(
                    'title'       => __( 'Link by Stripe', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable Link ("save my info for faster checkout")', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'Shows Stripe Link in the payment form so shoppers can save their card for one-click checkout and returning customers can autofill it. Turning this off removes Link as a payment option on this store\'s checkout - no changes to your Stripe Dashboard are required.', 'restore-paypal-standard-for-woocommerce' )
                        . '<br><br>'
                        . __( 'Note: Stripe may still display a "Save my information for faster checkout" prompt inside the card field, as that inline Link sign-up is controlled by Stripe and cannot be fully switched off from here.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'yes',
                    'desc_tip'    => false,
                ),
                /*
                 * DISABLED FOR THIS RELEASE — Apple Pay, Google Pay and ACH.
                 * The full implementation remains in the codebase but is gated
                 * off at the logic points so nothing ships enabled. To bring
                 * these back: un-comment these settings fields and remove the
                 * matching "disabled for this release" short-circuits in:
                 *   - RPSFW_Stripe_API::maybe_add_ach_payment_method()
                 *   - RPSFW_Gateway_Stripe::get_wallets_config()
                 *   - RPSFW_Gateway_Stripe::payment_scripts()  (express_checkout_enabled)
                 *   - RPSFW_Gateway_Stripe::payment_fields()   (express container)
                 *   - RPSFW_Gateway_Stripe_Blocks_Support::get_payment_method_data()  (expressCheckoutEnabled)
                 *
                'enable_apple_pay' => array(
                    'title'       => __( 'Apple Pay', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable Apple Pay at checkout', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'Shows an Apple Pay express button. Appears for shoppers who have Apple Pay set up (Apple devices, and Safari on Mac).', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'no',
                    'desc_tip'    => false,
                ),
                'enable_google_pay' => array(
                    'title'       => __( 'Google Pay', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable Google Pay at checkout', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'Shows a Google Pay express button. Works across modern browsers (including Firefox), not only Chrome, thanks to Stripe\'s Express Checkout Element.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'no',
                    'desc_tip'    => false,
                ),
                'ach_title' => array(
                    'title'       => __( 'Bank Payments (ACH)', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'title',
                    'description' => '<div style="max-width: 800px;">' . __( 'Accept ACH direct debit from US bank accounts. Customers pick their bank in the payment form (via Stripe Financial Connections). ACH settles over a few business days, so orders are placed on hold and marked paid automatically once Stripe confirms settlement.', 'restore-paypal-standard-for-woocommerce' ) . '</div>',
                ),
                'enable_ach' => array(
                    'title'       => __( 'ACH Direct Debit', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable ACH bank payments at checkout', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'US bank accounts only, and only when the store currency is USD. Requires ACH to be active on your connected Stripe account. Payments complete asynchronously — the order is marked paid when Stripe confirms settlement (typically a few business days).', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'no',
                    'desc_tip'    => false,
                ),
                */
            );
        }

        // Text Settings
        if ($current_sub_section === 'text') {
            $form_fields = array(
                'text_settings_title' => array(
                    'title'       => __( 'Text Settings', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'title',
                    'description' => '<div style="max-width: 800px;">' . __( 'Customize the text displayed to customers.', 'restore-paypal-standard-for-woocommerce' ) . '</div>',
                ),
                'title' => array(
                    'title'       => __( 'Title', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'text',
                    'description' => __( 'This controls the title which the user sees during checkout.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => __( 'Credit Card (Stripe)', 'restore-paypal-standard-for-woocommerce' ),
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __( 'Description', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'textarea',
                    'description' => __( 'This controls the description which the user sees during checkout.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => __( 'Pay securely with your credit card via Stripe.', 'restore-paypal-standard-for-woocommerce' ),
                    'desc_tip'    => true,
                ),
                'loading_text' => array(
                    'title'       => __( 'Loading Text', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'text',
                    'description' => __( 'Text displayed while the payment form is loading.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => __( 'Loading payment form...', 'restore-paypal-standard-for-woocommerce' ),
                    'desc_tip'    => true,
                ),
            );
        }

        // Appearance Settings
        if ($current_sub_section === 'appearance') {
            $form_fields = array(
                'appearance_settings_title' => array(
                    'title'       => __( 'Appearance Settings', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'title',
                    'description' => '<div style="max-width: 800px;">' . __( 'Customize the appearance of the Stripe payment method on checkout pages.', 'restore-paypal-standard-for-woocommerce' ) . '</div>',
                ),
                'display_options_title' => array(
                    'title'       => __( 'Display Options', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'title',
                    'description' => '<div style="max-width: 800px;">' . __( 'Control what elements are displayed on the checkout page.', 'restore-paypal-standard-for-woocommerce' ) . '</div>',
                ),
                'show_icon' => array(
                    'title'       => __( 'Show Icon', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Display the Stripe logo icon', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'Show the Stripe logo next to the payment method title during checkout.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'yes',
                    'desc_tip'    => true,
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
                'payment_element_title' => array(
                    'title'       => __( 'Payment Element Styling', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'title',
                    'description' => '<div style="max-width: 800px;">' . __( 'Customize the appearance of the Stripe Payment Element to match your site design. Start with a theme, then optionally customize colors, fonts, and spacing.', 'restore-paypal-standard-for-woocommerce' ) . '</div>',
                ),
                'theme' => array(
                    'title'       => __( 'Base Theme', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'select',
                    'description' => __( 'Choose a pre-built theme as the starting point. You can customize any theme with the options below.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'stripe',
                    'desc_tip'    => true,
                    'options'     => array(
                        'stripe' => __( 'Stripe (Light - Recommended)', 'restore-paypal-standard-for-woocommerce' ),
                        'night'  => __( 'Night (Dark Mode)', 'restore-paypal-standard-for-woocommerce' ),
                        'flat'   => __( 'Flat (Minimal)', 'restore-paypal-standard-for-woocommerce' ),
                        'none'   => __( 'None (Use only custom settings below)', 'restore-paypal-standard-for-woocommerce' ),
                    ),
                    'class'       => 'wc-enhanced-select',
                ),
                'customize_appearance' => array(
                    'title'       => __( 'Customize Appearance', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'description' => __( 'Enable to customize colors, fonts, and spacing. Leave unchecked to use the base theme as-is.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'no',
                    'desc_tip'    => false,
                ),
                'label_type' => array(
                    'title'       => __( 'Label Style', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'select',
                    'description' => __( 'Control how field labels are displayed.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'floating',
                    'desc_tip'    => true,
                    'options'     => array(
                        'floating' => __( 'Floating (Inside fields)', 'restore-paypal-standard-for-woocommerce' ),
                        'above'    => __( 'Above (Traditional)', 'restore-paypal-standard-for-woocommerce' ),
                    ),
                    'class'       => 'wc-enhanced-select rpsfw-stripe-custom-field',
                ),
                'color_primary' => array(
                    'title'       => __( 'Primary Color', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'color',
                    'description' => __( 'Main brand color used for buttons, links, and active states.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => '#0570de',
                    'desc_tip'    => true,
                    'css'         => 'width: 6em;',
                    'class'       => 'rpsfw-stripe-custom-field',
                ),
                'color_background' => array(
                    'title'       => __( 'Background Color', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'color',
                    'description' => __( 'Background color of input fields and form elements.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => '#ffffff',
                    'desc_tip'    => true,
                    'css'         => 'width: 6em;',
                    'class'       => 'rpsfw-stripe-custom-field',
                ),
                'color_text' => array(
                    'title'       => __( 'Text Color', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'color',
                    'description' => __( 'Color of text inside input fields and labels.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => '#30313d',
                    'desc_tip'    => true,
                    'css'         => 'width: 6em;',
                    'class'       => 'rpsfw-stripe-custom-field',
                ),
                'color_danger' => array(
                    'title'       => __( 'Error Color', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'color',
                    'description' => __( 'Color used for error messages and validation warnings.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => '#df1b41',
                    'desc_tip'    => true,
                    'css'         => 'width: 6em;',
                    'class'       => 'rpsfw-stripe-custom-field',
                ),
                'font_family' => array(
                    'title'       => __( 'Font Family', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'select',
                    'description' => __( 'Font used throughout the payment form.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'system',
                    'desc_tip'    => true,
                    'options'     => array(
                        'system'     => __( 'System Default', 'restore-paypal-standard-for-woocommerce' ),
                        'arial'      => __( 'Arial', 'restore-paypal-standard-for-woocommerce' ),
                        'helvetica'  => __( 'Helvetica', 'restore-paypal-standard-for-woocommerce' ),
                        'georgia'    => __( 'Georgia', 'restore-paypal-standard-for-woocommerce' ),
                        'times'      => __( 'Times New Roman', 'restore-paypal-standard-for-woocommerce' ),
                        'courier'    => __( 'Courier', 'restore-paypal-standard-for-woocommerce' ),
                        'verdana'    => __( 'Verdana', 'restore-paypal-standard-for-woocommerce' ),
                        'trebuchet'  => __( 'Trebuchet MS', 'restore-paypal-standard-for-woocommerce' ),
                        'custom'     => __( 'Custom (enter below)', 'restore-paypal-standard-for-woocommerce' ),
                    ),
                    'class'       => 'wc-enhanced-select rpsfw-stripe-custom-field',
                ),
                'font_family_custom' => array(
                    'title'       => __( 'Custom Font Family', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'text',
                    'description' => __( 'Enter a custom font family (only used if "Custom" is selected above). Example: "Open Sans", sans-serif', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => '',
                    'desc_tip'    => true,
                    'placeholder' => '"Open Sans", sans-serif',
                    'class'       => 'rpsfw-stripe-custom-field',
                ),
                'font_size' => array(
                    'title'       => __( 'Font Size', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'select',
                    'description' => __( 'Base font size for the payment form. Minimum 16px recommended for mobile.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => '16px',
                    'desc_tip'    => true,
                    'options'     => array(
                        '14px' => '14px (Small)',
                        '16px' => '16px (Default)',
                        '18px' => '18px (Large)',
                        '20px' => '20px (Extra Large)',
                    ),
                    'class'       => 'wc-enhanced-select rpsfw-stripe-custom-field',
                ),
                'border_radius' => array(
                    'title'       => __( 'Border Radius', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'select',
                    'description' => __( 'Roundness of corners for input fields and buttons.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => '4px',
                    'desc_tip'    => true,
                    'options'     => array(
                        '0px'  => __( '0px (Square)', 'restore-paypal-standard-for-woocommerce' ),
                        '2px'  => __( '2px (Slightly Rounded)', 'restore-paypal-standard-for-woocommerce' ),
                        '4px'  => __( '4px (Default)', 'restore-paypal-standard-for-woocommerce' ),
                        '6px'  => __( '6px (Rounded)', 'restore-paypal-standard-for-woocommerce' ),
                        '8px'  => __( '8px (Very Rounded)', 'restore-paypal-standard-for-woocommerce' ),
                        '12px' => __( '12px (Extra Rounded)', 'restore-paypal-standard-for-woocommerce' ),
                    ),
                    'class'       => 'wc-enhanced-select rpsfw-stripe-custom-field',
                ),
                'spacing_unit' => array(
                    'title'       => __( 'Spacing', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'select',
                    'description' => __( 'Base spacing unit for padding and margins. Higher values create more spacious layouts.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => '4px',
                    'desc_tip'    => true,
                    'options'     => array(
                        '2px' => __( '2px (Compact)', 'restore-paypal-standard-for-woocommerce' ),
                        '4px' => __( '4px (Default)', 'restore-paypal-standard-for-woocommerce' ),
                        '6px' => __( '6px (Spacious)', 'restore-paypal-standard-for-woocommerce' ),
                        '8px' => __( '8px (Very Spacious)', 'restore-paypal-standard-for-woocommerce' ),
                    ),
                    'class'       => 'wc-enhanced-select rpsfw-stripe-custom-field',
                ),
            );
        }

        // Payment Options Settings
        if ($current_sub_section === 'payment_options') {
            $form_fields = array(
                'payment_options_intro' => array(
                    'title'       => __( 'Payment Methods', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'title',
                    'description' => '<div style="max-width: 800px;">' . 
                        '<p>' . __( 'Control which payment methods are available at checkout. Payment methods will only appear if they support the transaction currency, amount, and customer location.', 'restore-paypal-standard-for-woocommerce' ) . '</p>' .
                        '<div style="background: #f0f6fc; border-left: 4px solid #0570de; padding: 12px 16px; margin: 16px 0;">' .
                        '<strong style="color: #0570de;">💡 ' . __( 'Important Notes:', 'restore-paypal-standard-for-woocommerce' ) . '</strong>' .
                        '<ul style="margin: 8px 0 0 20px; color: #666;">' .
                        '<li>' . __( '<strong>Apple Pay</strong> only shows on Safari/iOS devices and requires domain verification in your Stripe Dashboard', 'restore-paypal-standard-for-woocommerce' ) . '</li>' .
                        '<li>' . __( '<strong>Google Pay</strong> only shows on Chrome/Android devices with Google Pay configured', 'restore-paypal-standard-for-woocommerce' ) . '</li>' .
                        '<li>' . __( '<strong>BNPL methods</strong> (Klarna, Afterpay, Affirm) have minimum/maximum transaction amounts', 'restore-paypal-standard-for-woocommerce' ) . '</li>' .
                        '<li>' . __( '<strong>Regional methods</strong> (iDEAL, Bancontact, etc.) only work with specific currencies', 'restore-paypal-standard-for-woocommerce' ) . '</li>' .
                        '<li>' . sprintf(
                            /* translators: %s: link reading "Stripe Dashboard" */
                            esc_html__( 'You may also need to enable these methods in your %s', 'restore-paypal-standard-for-woocommerce' ),
                            '<a href="' . esc_url( 'https://dashboard.stripe.com/settings/payment_methods' ) . '" target="_blank" rel="noopener noreferrer">'
                                . esc_html__( 'Stripe Dashboard', 'restore-paypal-standard-for-woocommerce' )
                                . '</a>'
                        ) . '</li>' .
                        '</ul>' .
                        '</div>' .
                        '</div>',
                ),
                'card_section' => array(
                    'title'       => __( 'Credit & Debit Cards', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'title',
                    'description' => __( 'Accept major credit and debit cards from customers worldwide.', 'restore-paypal-standard-for-woocommerce' ),
                ),
                'enable_card' => array(
                    'title'       => __( 'Credit/Debit Cards', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable card payments', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'Accept Visa, Mastercard, American Express, Discover, JCB, Diners Club, and other major cards.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'yes',
                    'desc_tip'    => false,
                ),
                'wallets_section' => array(
                    'title'       => __( 'Digital Wallets', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'title',
                    'description' => __( 'Digital wallets provide fast, secure checkout with saved payment information.', 'restore-paypal-standard-for-woocommerce' ),
                ),
                'enable_apple_pay' => array(
                    'title'       => __( 'Apple Pay', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable Apple Pay', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'Only appears on Safari and iOS devices with Apple Pay configured. Requires domain verification in Stripe Dashboard.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'no',
                    'desc_tip'    => false,
                ),
                'enable_google_pay' => array(
                    'title'       => __( 'Google Pay', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable Google Pay', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'Only appears on Chrome and Android devices with Google Pay configured.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'no',
                    'desc_tip'    => false,
                ),
                // NOTE: Link is intentionally NOT listed here. Its single source
                // of truth is the 'enable_link' toggle in the Digital Wallets
                // section (default 'yes'). A duplicate here — with a conflicting
                // 'no' default writing to the same option key — was removed so
                // that enabling this (currently hidden) Payment Options tab can
                // never accidentally flip Link off. Re-add Link handling only by
                // pointing at that one definition.
                'enable_cashapp' => array(
                    'title'       => __( 'Cash App Pay', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable Cash App Pay', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'Accept payments from Cash App users (US only).', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'no',
                    'desc_tip'    => false,
                ),
                'bnpl_section' => array(
                    'title'       => __( 'Buy Now, Pay Later', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'title',
                    'description' => __( 'Buy Now, Pay Later options let customers split payments over time. These methods have minimum and maximum transaction amounts.', 'restore-paypal-standard-for-woocommerce' ),
                ),
                'enable_klarna' => array(
                    'title'       => __( 'Klarna', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable Klarna', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'Pay in installments with Klarna. Supports USD, EUR, GBP, and more. Typical range: $1-$10,000.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'no',
                    'desc_tip'    => false,
                ),
                'enable_afterpay' => array(
                    'title'       => __( 'Afterpay / Clearpay', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable Afterpay/Clearpay', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'Pay in 4 interest-free installments. Supports USD, CAD, AUD, NZD, GBP. Typical range: $1-$2,000.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'no',
                    'desc_tip'    => false,
                ),
                'enable_affirm' => array(
                    'title'       => __( 'Affirm', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable Affirm', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'Monthly payment plans for larger purchases. US and Canada only. Typical range: $50-$30,000.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'no',
                    'desc_tip'    => false,
                ),
                'bank_section' => array(
                    'title'       => __( 'Bank Payments', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'title',
                    'description' => __( 'Direct bank payment methods. Availability depends on customer location and currency.', 'restore-paypal-standard-for-woocommerce' ),
                ),
                'enable_us_bank_account' => array(
                    'title'       => __( 'ACH Direct Debit', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable ACH Direct Debit', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'Accept bank transfers from US bank accounts (USD only).', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'no',
                    'desc_tip'    => false,
                ),
                'enable_sepa_debit' => array(
                    'title'       => __( 'SEPA Direct Debit', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable SEPA Direct Debit', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'Accept bank transfers from European bank accounts (EUR only).', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'no',
                    'desc_tip'    => false,
                ),
                'regional_section' => array(
                    'title'       => __( 'Regional Payment Methods', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'title',
                    'description' => __( 'Popular payment methods in specific countries and regions.', 'restore-paypal-standard-for-woocommerce' ),
                ),
                'enable_ideal' => array(
                    'title'       => __( 'iDEAL', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable iDEAL', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'Popular in the Netherlands (EUR only).', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'no',
                    'desc_tip'    => false,
                ),
                'enable_bancontact' => array(
                    'title'       => __( 'Bancontact', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable Bancontact', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'Popular in Belgium (EUR only).', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'no',
                    'desc_tip'    => false,
                ),
                'enable_giropay' => array(
                    'title'       => __( 'Giropay', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable Giropay', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'Popular in Germany (EUR only).', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'no',
                    'desc_tip'    => false,
                ),
                'enable_sofort' => array(
                    'title'       => __( 'Sofort', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable Sofort', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'Popular in Europe (EUR only).', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'no',
                    'desc_tip'    => false,
                ),
                'enable_alipay' => array(
                    'title'       => __( 'Alipay', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable Alipay', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'Popular in China. Supports multiple currencies.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'no',
                    'desc_tip'    => false,
                ),
                'enable_wechat_pay' => array(
                    'title'       => __( 'WeChat Pay', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable WeChat Pay', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'Popular in China. Supports multiple currencies.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'no',
                    'desc_tip'    => false,
                ),
            );
        }

        // Advanced Settings
        if ($current_sub_section === 'advanced') {
            // Build the site locale label so the admin can see exactly which
            // language code will be sent to Stripe when "Site Locale" is chosen.
            $wp_locale       = get_locale();                          // e.g. en_US
            $stripe_locale   = str_replace( '_', '-', $wp_locale );  // e.g. en-US
            $site_locale_label = sprintf(
                /* translators: %s: the locale code that will be sent, e.g. en-US */
                __( 'Site Locale (%s)', 'restore-paypal-standard-for-woocommerce' ),
                $stripe_locale
            );

            $form_fields = array(
                'advanced_section' => array(
                    'title'       => __( 'Advanced Options', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'title',
                    'description' => '<div style="max-width: 800px;">' . __( 'Advanced configuration options for Stripe.', 'restore-paypal-standard-for-woocommerce' ) . '</div>',
                ),
                'locale' => array(
                    'title'       => __( 'Locale', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'select',
                    'description' => __( 'Controls the language of the Stripe Payment Element. "Auto" lets Stripe detect the language from the customer\'s browser. "Site Locale" uses the language configured in WordPress Settings → General.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'auto',
                    'desc_tip'    => true,
                    'options'     => array(
                        'auto' => __( 'Auto (browser language)', 'restore-paypal-standard-for-woocommerce' ),
                        'site' => $site_locale_label,
                    ),
                ),
                'payment_action' => array(
                    'title'       => __( 'Payment Action', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'select',
                    'description' => __( '<strong>Important:</strong> Only works with non-recurring payments.', 'restore-paypal-standard-for-woocommerce' )
                        . '<br><br>'
                        . __( 'If the cart contains both a subscription and a one-off (non-recurring) product at the same time, the entire order is charged immediately.', 'restore-paypal-standard-for-woocommerce' )
                        . '<br><br>'
                        . __( 'Authorized payments must be captured manually within 7 days, after which the authorization expires. Enable Extended Authorization below to hold funds longer on supported cards.', 'restore-paypal-standard-for-woocommerce' ),
                    'desc_tip'    => __( 'Choose whether to capture funds immediately or authorize payment only.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'capture',
                    'options'     => array(
                        'capture'   => __( 'Capture', 'restore-paypal-standard-for-woocommerce' ),
                        'authorize' => __( 'Authorize', 'restore-paypal-standard-for-woocommerce' ),
                    ),
                    'class'       => 'wc-enhanced-select',
                ),
                'extended_authorization' => array(
                    'title'       => __( 'Extended Authorization', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable Extended Authorization', 'restore-paypal-standard-for-woocommerce' ),
                    'desc_tip'    => __( 'Requests an extended authorization hold period from Stripe when available. Only applies when Payment Action is set to Authorize. Extends the authorization window beyond the standard 7 days on supported cards.', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'Only takes effect when Payment Action is set to <strong>Authorize</strong>. Note: Your Stripe account may not support this feature or it may be turned off on your account.', 'restore-paypal-standard-for-woocommerce' )
                        . '<br>'
                        . '<a href="https://docs.stripe.com/payments/extended-authorization" target="_blank" rel="noopener noreferrer">' . __( 'Learn more about Stripe extended authorization', 'restore-paypal-standard-for-woocommerce' ) . '</a>',
                    'default'     => 'no',
                ),
                'load_stripe_js_checkout_only' => array(
                    'title'       => __( 'Load Stripe.js on Checkout Only', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Only load Stripe.js on the checkout page', 'restore-paypal-standard-for-woocommerce' ),
                    'desc_tip'    => __( 'By default Stripe.js loads on every page so Stripe\'s fraud detection (Radar) can observe browsing behavior. Enable this to load it only on checkout.', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'Stripe recommends loading Stripe.js on all pages so its advanced fraud detection can analyze customer behavior across your site. Restricting it to checkout reduces requests but <strong>disables that advanced fraud signal</strong>.', 'restore-paypal-standard-for-woocommerce' )
                        . '<br>'
                        . '<a href="https://docs.stripe.com/disputes/prevention/advanced-fraud-detection" target="_blank" rel="noopener noreferrer">' . __( 'Learn more about Stripe advanced fraud detection', 'restore-paypal-standard-for-woocommerce' ) . '</a>',
                    'default'     => 'no',
                ),
            );
        }

        // Disputes Settings
        if ($current_sub_section === 'disputes') {
            $form_fields = array(
                'disputes_section' => array(
                    'title'       => __( 'Dispute Settings', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'title',
                    'description' => '<div style="max-width: 800px;">' . __( 'Configure how your store handles Stripe disputes and chargebacks. These settings control what happens when Stripe notifies your store via webhooks.', 'restore-paypal-standard-for-woocommerce' ) . '</div>',
                ),
                'dispute_created_enabled' => array(
                    'title'       => __( 'Dispute Created', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Handle dispute created events', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'When enabled, the plugin will listen for the <code>charge.dispute.created</code> webhook event and update the order status.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'yes',
                    'desc_tip'    => false,
                ),
                'dispute_created_status' => array(
                    'title'       => __( 'Dispute Created Order Status', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'select',
                    'description' => __( 'The status assigned to an order when a dispute is opened.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'wc-on-hold',
                    'desc_tip'    => true,
                    'options'     => wc_get_order_statuses(),
                    'class'       => 'wc-enhanced-select',
                ),
                'dispute_closed_enabled' => array(
                    'title'       => __( 'Dispute Closed', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Handle dispute closed events', 'restore-paypal-standard-for-woocommerce' ),
                    'description' => __( 'When enabled, the plugin will listen for the <code>charge.dispute.closed</code> webhook event and update the order status based on the outcome.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'yes',
                    'desc_tip'    => false,
                ),
                'dispute_closed_lost_status' => array(
                    'title'       => __( 'Dispute Lost Order Status', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'select',
                    'description' => __( 'The status assigned to an order when a dispute is lost (resolved in the customer\'s favour). When a dispute is won, the order is restored to its pre-dispute status automatically.', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'wc-failed',
                    'desc_tip'    => true,
                    'options'     => wc_get_order_statuses(),
                    'class'       => 'wc-enhanced-select',
                ),
            );
        }

        // Debugging Settings
        if ($current_sub_section === 'debugging') {
            $form_fields = array(
                'debug_section' => array(
                    'title'       => __( 'Debug Options', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'title',
                    'description' => '<div style="max-width: 800px;">' . __( 'Enable logging to troubleshoot Stripe payment issues.', 'restore-paypal-standard-for-woocommerce' ) . '</div>',
                ),
                'debug_enabled' => array(
                    'title'       => __( 'Debug Log', 'restore-paypal-standard-for-woocommerce' ),
                    'type'        => 'checkbox',
                    'label'       => __( 'Enable logging', 'restore-paypal-standard-for-woocommerce' ),
                    'default'     => 'no',
                    /* translators: %s: log file path. */
                    'description' => sprintf( __( 'Log Stripe events inside %s', 'restore-paypal-standard-for-woocommerce' ), '<code>' . WC_Log_Handler_File::get_log_file_path( 'rpsfw-stripe' ) . '</code>' ),
                ),
            );
        }

        return $form_fields;
    }

    /**
     * Admin Panel Options.
     */
    public function admin_options() {
        $current_section = empty($_GET['section']) ? '' : sanitize_title($_GET['section']);
        $current_sub_section = isset($_GET['sub_section']) ? sanitize_title($_GET['sub_section']) : 'general';
        
        // Display title with breadcrumb and unsaved changes notice
        echo '<h2 style="position: relative;">';
        echo esc_html( $this->gateway->get_method_title() );
        echo '<small class="wc-admin-breadcrumb"><a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout')) . '" aria-label="' . esc_attr__('Return to payments', 'restore-paypal-standard-for-woocommerce') . '">⤴</a></small>';
        ?>
        <span id="rpsfw-stripe-save-notice" class="rpsfw-stripe-save-notice-inline" style="display: none;">
            <span class="dashicons dashicons-info"></span>
            <span><?php esc_html_e( 'Unsaved changes - Press "Save changes" at bottom of page.', 'restore-paypal-standard-for-woocommerce' ); ?></span>
        </span>
        <?php
        echo '</h2>';
        echo '<p>' . esc_html( $this->gateway->get_method_description() ) . '</p>';
        
        // Display sub-section tabs
        if ($current_section === 'rpsfw_stripe') {
            echo '<ul class="subsubsub">';
            $sub_sections = array(
                'general' => __('General', 'restore-paypal-standard-for-woocommerce'),
                'digital_wallets' => __('Digital Wallets', 'restore-paypal-standard-for-woocommerce'),
                'payment_options' => __('Payment Options', 'restore-paypal-standard-for-woocommerce'),
                'appearance' => __('Appearance', 'restore-paypal-standard-for-woocommerce'),
                'text' => __('Text', 'restore-paypal-standard-for-woocommerce'),
                'advanced' => __('Advanced', 'restore-paypal-standard-for-woocommerce'),
                'disputes' => __('Disputes', 'restore-paypal-standard-for-woocommerce'),
                'debugging' => __('Debugging', 'restore-paypal-standard-for-woocommerce'),
            );

            // Card-only launch: hide the Payment Options tab until additional
            // methods are ready. See payment_options_tab_enabled().
            if ( ! self::payment_options_tab_enabled() ) {
                unset( $sub_sections['payment_options'] );
            }

            $i = 0;
            $total = count($sub_sections);
            foreach ($sub_sections as $id => $label) {
                $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=rpsfw_stripe&sub_section=' . $id);
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
        <div class="rpsfw-stripe-settings" style="max-width: 800px;">
            <table class="form-table">
                <?php
                // generate_settings_html() returns markup already escaped by WooCommerce core.
                echo $this->gateway->generate_settings_html( $this->gateway->get_form_fields(), false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
     * Process and save admin options.
     */
    public function process_admin_options() {
        $post_data = $this->gateway->get_post_data();
        
        foreach ($this->gateway->form_fields as $key => $field) {
            if (isset($field['type']) && $field['type'] === 'checkbox') {
                $field_key = $this->gateway->get_field_key($key);
                $this->gateway->settings[$key] = isset($post_data[$field_key]) ? 'yes' : 'no';
            } 
            else if (isset($post_data[$this->gateway->get_field_key($key)])) {
                $field_type = isset($field['type']) ? $field['type'] : '';
                $value = $post_data[$this->gateway->get_field_key($key)];
                
                // For select fields, use WooCommerce's validate_select_field
                if ($field_type === 'select' && method_exists($this->gateway, 'validate_select_field')) {
                    $value = $this->gateway->validate_select_field($key, $value);
                } else if (method_exists($this->gateway, 'validate_' . $field_type . '_field')) {
                    $value = $this->gateway->{'validate_' . $field_type . '_field'}($key, $value);
                } else {
                    $value = $this->gateway->validate_text_field($key, $value);
                }
                
                $this->gateway->settings[$key] = $value;
            }
        }
        
        update_option(
            'woocommerce_rpsfw_stripe_settings',
            apply_filters('woocommerce_settings_api_sanitized_fields_' . $this->gateway->id, $this->gateway->settings)
        );
        
        // Clear logs if debugging is disabled
        if ('yes' !== $this->get_option('debug_enabled')) {
            $gateway_class = get_class($this->gateway);
            if (empty($gateway_class::$log)) {
                $gateway_class::$log = wc_get_logger();
            }
            $gateway_class::$log->clear('rpsfw-stripe');
        }
    }

    /**
     * Get option from settings
     *
     * @param string $key Setting key to retrieve.
     * @param mixed $empty_value Value to return if option is empty.
     * @return string The value specified for the option or a default value for the option.
     */
    public function get_option( $key, $empty_value = '' ) {
        if (isset($this->gateway->settings[$key])) {
            return $this->gateway->settings[$key];
        }
        
        return $empty_value;
    }

    /**
     * Display Stripe connection status
     */
    public function display_connection_status() {
        $testmode = $this->get_option('testmode') === 'yes';
        $env = $testmode ? 'sandbox' : 'live';
        $status = rpsfw_stripe_connection_status( true );
        
        if ( $status && ! empty( $status['display_name'] ) ) {
            // Connected successfully
            $reconnect_env = $env === 'live' ? 'sandbox' : 'live';
            ?>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                <span class="dashicons dashicons-yes-alt" style="color: #46b450; font-size: 20px;"></span>
                <div>
                    <strong><?php esc_html_e( 'Connected', 'restore-paypal-standard-for-woocommerce' ); ?></strong>
                    <?php if ( ! empty( $status['email'] ) ) : ?>
                        <span style="color: #666;"> — <?php echo esc_html( $status['email'] ); ?></span>
                    <?php endif; ?>
                    <?php if ( ! empty( $status['display_name'] ) ) : ?>
                        <span style="color: #666;"> (<?php echo esc_html( $status['display_name'] ); ?>)</span>
                    <?php endif; ?>
                </div>
            </div>
            <div style="margin-left: 30px;">
                <a href="<?php echo esc_url( rpsfw_stripe_disconnect_url( $status['account_id'], $status['token'] ) ); ?>" style="color: #b32d2e; text-decoration: none;">
                    <?php esc_html_e( 'Disconnect account', 'restore-paypal-standard-for-woocommerce' ); ?>
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
                        <?php esc_html_e( 'Connect your Stripe account to start accepting payments.', 'restore-paypal-standard-for-woocommerce' ); ?>
                    </p>
                </div>
            </div>
            <div style="margin-left: 30px;">
                <a href="<?php echo esc_url( rpsfw_stripe_connect_url() ); ?>" class="button button-primary">
                    <?php esc_html_e( 'Connect with Stripe', 'restore-paypal-standard-for-woocommerce' ); ?>
                </a>
            </div>
            <?php
        }
    }

    /**
     * Display Stripe webhook status
     */
    public function display_webhook_status() {
        $testmode = $this->get_option('testmode') === 'yes';
        $env = $testmode ? 'sandbox' : 'live';
        $status = rpsfw_stripe_connection_status( true );
        
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
                        <?php esc_html_e( 'Stripe webhooks require a publicly accessible URL. Please deploy your site to a live server to configure webhooks.', 'restore-paypal-standard-for-woocommerce' ); ?>
                    </p>
                </div>
            </div>
            <?php
            return;
        }
        
        if ( empty( $status ) ) {
            ?>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                <span class="dashicons dashicons-warning" style="color: #f0b849; font-size: 20px;"></span>
                <div>
                    <strong><?php esc_html_e( 'Not Available', 'restore-paypal-standard-for-woocommerce' ); ?></strong>
                    <p style="margin: 5px 0 0 0; color: #666;">
                        <?php esc_html_e( 'Connect your Stripe account first to configure webhooks.', 'restore-paypal-standard-for-woocommerce' ); ?>
                    </p>
                </div>
            </div>
            <?php
            return;
        }
        
        // Get webhook ID from settings
        $options = get_option( 'woocommerce_rpsfw_stripe_settings', array() );
        $webhook_id_key = $testmode ? 'webhook_id_test' : 'webhook_id_live';
        $webhook_id = isset( $options[$webhook_id_key] ) ? $options[$webhook_id_key] : '';
        $webhook_secret_key = $testmode ? 'webhook_secret_test' : 'webhook_secret_live';
        $webhook_secret     = isset( $options[ $webhook_secret_key ] ) ? $options[ $webhook_secret_key ] : '';
        $relay_secret_key = $testmode ? 'relay_shared_secret_test' : 'relay_shared_secret_live';
        $relay_secret       = isset( $options[ $relay_secret_key ] ) ? $options[ $relay_secret_key ] : '';

        $webhook_url = home_url( '/wc-api/rpsfw_stripe_webhook/' );

        // Two valid configurations:
        //  1) Relay path (default): a per-merchant shared secret was
        //     captured during the Stripe Connect OAuth completion and
        //     the wpplugin Connect server forwards Stripe events to
        //     this site. Nothing else for the merchant to do.
        //  2) Direct path (fallback): the merchant pasted a Stripe
        //     signing secret directly. We honour that too.
        $is_configured = ! empty( $relay_secret ) || ! empty( $webhook_secret );
        $is_relay      = ! empty( $relay_secret );

        if ( $is_configured ) {
            // Webhook is configured (relay or direct)
            ?>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                <span class="dashicons dashicons-yes-alt" style="color: #46b450; font-size: 20px;"></span>
                <div>
                    <strong>
                        <?php
                        if ( $is_relay ) {
                            esc_html_e( 'Webhooks are active', 'restore-paypal-standard-for-woocommerce' );
                        } else {
                            esc_html_e( 'Webhook signing secret saved', 'restore-paypal-standard-for-woocommerce' );
                        }
                        ?>
                    </strong>
                    <p style="margin: 5px 0 0 0; color: #666;">
                        <?php
                        if ( $is_relay ) {
                            esc_html_e( 'Webhooks have been automatically configured for this website. No setup is required on your part.', 'restore-paypal-standard-for-woocommerce' );
                        } else {
                            esc_html_e( 'Stripe events are being received and verified.', 'restore-paypal-standard-for-woocommerce' );
                        }
                        ?>
                    </p>
                </div>
            </div>
            <?php if ( ! $is_relay ) : ?>
            <div style="margin-left: 30px; margin-bottom: 15px;">
                <p style="margin: 0 0 10px 0; color: #666;">
                    <strong><?php esc_html_e( 'Webhook URL:', 'restore-paypal-standard-for-woocommerce' ); ?></strong><br>
                    <code style="font-size: 12px;"><?php echo esc_html( $webhook_url ); ?></code>
                </p>
                <p style="margin: 0 0 10px 0; color: #666;">
                    <strong><?php esc_html_e( 'Environment:', 'restore-paypal-standard-for-woocommerce' ); ?></strong> <?php echo esc_html( ucfirst( $env ) ); ?>
                </p>
                <p style="margin: 10px 0 5px 0;">
                    <a href="#" class="rpsfw-stripe-edit-webhook"><?php esc_html_e( 'Update signing secret', 'restore-paypal-standard-for-woocommerce' ); ?></a>
                </p>
                <div class="rpsfw-stripe-webhook-secret-form" style="display: none; margin-top: 10px;">
                    <input type="password"
                           class="rpsfw-stripe-webhook-secret-input"
                           placeholder="<?php esc_attr_e( 'whsec_...', 'restore-paypal-standard-for-woocommerce' ); ?>"
                           style="width: 100%; max-width: 400px;" />
                    <p>
                        <button type="button" class="button button-primary rpsfw-stripe-save-webhook-secret" data-mode="<?php echo esc_attr( $testmode ? 'test' : 'live' ); ?>">
                            <?php esc_html_e( 'Save secret', 'restore-paypal-standard-for-woocommerce' ); ?>
                        </button>
                        <button type="button" class="button rpsfw-stripe-clear-webhook-secret" data-mode="<?php echo esc_attr( $testmode ? 'test' : 'live' ); ?>" style="color: #b32d2e;">
                            <?php esc_html_e( 'Clear secret', 'restore-paypal-standard-for-woocommerce' ); ?>
                        </button>
                    </p>
                </div>
            </div>
            <?php endif; ?>
            <?php
        } else {
            // No webhook configured. Encourage reconnect (which will
            // populate the relay shared secret automatically) but also
            // expose the manual paste fallback for edge cases.
            ?>
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                <span class="dashicons dashicons-warning" style="color: #f0b849; font-size: 20px;"></span>
                <div>
                    <strong><?php esc_html_e( 'Webhooks not yet active', 'restore-paypal-standard-for-woocommerce' ); ?></strong>
                    <p style="margin: 5px 0 0 0; color: #666;">
                        <?php esc_html_e( 'Reconnect your Stripe account to automatically configure webhooks for this website.', 'restore-paypal-standard-for-woocommerce' ); ?>
                    </p>
                </div>
            </div>
            <div style="margin-left: 30px; margin-bottom: 15px;">
                <p style="margin: 0 0 12px;">
                    <?php esc_html_e( 'After clicking Connect again above, this section will switch to "Webhooks are active" automatically.', 'restore-paypal-standard-for-woocommerce' ); ?>
                </p>
                <p style="margin: 0 0 8px;"><strong><?php esc_html_e( 'Advanced: paste a Stripe signing secret manually', 'restore-paypal-standard-for-woocommerce' ); ?></strong></p>
                <p style="margin: 0 0 8px; color: #666;">
                    <?php esc_html_e( 'Only use this if you cannot use the connect flow. You will need to create the webhook endpoint yourself in your Stripe dashboard.', 'restore-paypal-standard-for-woocommerce' ); ?>
                </p>
                <input type="password"
                       class="rpsfw-stripe-webhook-secret-input"
                       placeholder="<?php esc_attr_e( 'whsec_...', 'restore-paypal-standard-for-woocommerce' ); ?>"
                       style="width: 100%; max-width: 400px;" />
                <p style="margin: 8px 0 0;">
                    <button type="button" class="button rpsfw-stripe-save-webhook-secret" data-mode="<?php echo esc_attr( $testmode ? 'test' : 'live' ); ?>">
                        <?php esc_html_e( 'Save signing secret', 'restore-paypal-standard-for-woocommerce' ); ?>
                    </button>
                </p>
            </div>
            <?php
        }

        // Show the list of events this integration listens to as an
        // expandable dropdown — mirrors the PayPal Commerce "Subscribed
        // Events" disclosure. Always shown (we are past the not-connected
        // guards here) so merchants can see exactly what is being listened
        // to. On the manual/direct path it also doubles as setup guidance for
        // the events to enable on their own endpoint; on the relay path the
        // platform configures them automatically.
        $subscribed_events = $this->get_subscribed_events_with_labels();
        ?>
        <details class="rpsfw-stripe-events" style="margin-left: 30px; margin-top: 15px; padding: 10px 12px; background: #f9f9f9; border-left: 3px solid #635bff;">
            <summary style="cursor: pointer; font-weight: 600; list-style: none; display: flex; align-items: center; justify-content: space-between;">
                <span><?php esc_html_e( 'Subscribed Events', 'restore-paypal-standard-for-woocommerce' ); ?></span>
                <span class="dashicons dashicons-arrow-down-alt2 rpsfw-stripe-events__arrow" aria-hidden="true"></span>
            </summary>
            <?php if ( ! $is_relay ) : ?>
            <p style="margin: 8px 0 0; color: #666; font-size: 12px;"><?php esc_html_e( 'Enable these events on your webhook endpoint, or simply select "Select all events" in the Stripe dashboard for the easiest setup.', 'restore-paypal-standard-for-woocommerce' ); ?></p>
            <?php endif; ?>
            <ul style="margin: 8px 0 0 15px; color: #666;">
                <?php
                foreach ( $subscribed_events as $event_name => $event_label ) {
                    echo '<li><code>' . esc_html( $event_name ) . '</code> - ' . esc_html( $event_label ) . '</li>';
                }
                ?>
            </ul>
        </details>
        <style>
            .rpsfw-stripe-events > summary::-webkit-details-marker { display: none; }
            .rpsfw-stripe-events__arrow { transition: transform 0.2s ease; }
            .rpsfw-stripe-events[open] .rpsfw-stripe-events__arrow { transform: rotate(180deg); }
        </style>
        <?php
    }

    /**
     * The Stripe webhook events this integration listens to, mapped to
     * human-readable labels. Keep in sync with the enabled_events list in
     * RPSFW_Stripe_API (the rpsfw_stripe_webhook_events filter), which is the
     * authoritative set registered with Stripe.
     *
     * @return array Event name => human-readable label.
     */
    public function get_subscribed_events_with_labels() {
        return array(
            'charge.succeeded'                     => __( 'Charge succeeded', 'restore-paypal-standard-for-woocommerce' ),
            'charge.failed'                        => __( 'Charge failed', 'restore-paypal-standard-for-woocommerce' ),
            'charge.pending'                       => __( 'Charge pending', 'restore-paypal-standard-for-woocommerce' ),
            'charge.refunded'                      => __( 'Charge refunded', 'restore-paypal-standard-for-woocommerce' ),
            'charge.dispute.created'               => __( 'Dispute opened', 'restore-paypal-standard-for-woocommerce' ),
            'charge.dispute.closed'                => __( 'Dispute resolved', 'restore-paypal-standard-for-woocommerce' ),
            'payment_intent.succeeded'             => __( 'Payment captured / succeeded', 'restore-paypal-standard-for-woocommerce' ),
            'payment_intent.payment_failed'        => __( 'Payment failed', 'restore-paypal-standard-for-woocommerce' ),
            'payment_intent.requires_action'       => __( 'Payment requires action (3DS/SCA)', 'restore-paypal-standard-for-woocommerce' ),
            'payment_intent.canceled'              => __( 'Authorization canceled / voided', 'restore-paypal-standard-for-woocommerce' ),
            'review.opened'                        => __( 'Radar review opened', 'restore-paypal-standard-for-woocommerce' ),
            'review.closed'                        => __( 'Radar review closed', 'restore-paypal-standard-for-woocommerce' ),
            'invoice.payment_succeeded'            => __( 'Subscription payment succeeded', 'restore-paypal-standard-for-woocommerce' ),
            'invoice.payment_failed'               => __( 'Subscription payment failed', 'restore-paypal-standard-for-woocommerce' ),
            'invoice.payment_action_required'      => __( 'Subscription payment requires action', 'restore-paypal-standard-for-woocommerce' ),
            'invoice.upcoming'                     => __( 'Upcoming subscription invoice', 'restore-paypal-standard-for-woocommerce' ),
            'invoice.finalized'                    => __( 'Subscription invoice finalized', 'restore-paypal-standard-for-woocommerce' ),
            'customer.subscription.created'        => __( 'Subscription created', 'restore-paypal-standard-for-woocommerce' ),
            'customer.subscription.updated'        => __( 'Subscription updated', 'restore-paypal-standard-for-woocommerce' ),
            'customer.subscription.deleted'        => __( 'Subscription cancelled', 'restore-paypal-standard-for-woocommerce' ),
            'customer.subscription.paused'         => __( 'Subscription paused', 'restore-paypal-standard-for-woocommerce' ),
            'customer.subscription.resumed'        => __( 'Subscription resumed', 'restore-paypal-standard-for-woocommerce' ),
            'customer.subscription.trial_will_end' => __( 'Subscription trial ending', 'restore-paypal-standard-for-woocommerce' ),
        );
    }
}
