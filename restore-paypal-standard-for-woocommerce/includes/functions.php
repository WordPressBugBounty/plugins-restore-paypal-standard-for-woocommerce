<?php
/**
 * Helper functions for Restore PayPal Standard For WooCommerce
 *
 * @package Restore_PayPal_Standard_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Global debugging status
 */
$rpsfw_debug_enabled = null;

/**
 * Display a notice if PHP version is too low.
 */
function rpsfw_php_version_notice() {
    echo '<div class="error"><p>' . esc_html__( 'Restore PayPal Standard for WooCommerce requires PHP 5.6 or higher. Please update your PHP version to use this plugin.', 'restore-paypal-standard-for-woocommerce' ) . '</p></div>';
}

/**
 * WooCommerce plugin dependency check.
 * 
 * @return bool
 */
function rpsfw_woocommerce_dependency_check() {
    // Check if WooCommerce class exists
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'rpsfw_woocommerce_dependency_notice' );
        return false;
    }
    
    // Check if payment gateways class exists
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        return false;
    }
    
    return true;
}

/**
 * Display a notice if WooCommerce is not active.
 */
function rpsfw_woocommerce_dependency_notice() {
    $install_url = wp_nonce_url(
        add_query_arg(
            array(
                'action' => 'install-plugin',
                'plugin' => 'woocommerce',
            ),
            admin_url( 'update.php' )
        ),
        'install-plugin_woocommerce'
    );

    $activate_url = wp_nonce_url(
        add_query_arg(
            array(
                'action' => 'activate',
                'plugin' => 'woocommerce/woocommerce.php',
            ),
            admin_url( 'plugins.php' )
        ),
        'activate-plugin_woocommerce/woocommerce.php'
    );

    echo '<div class="error">';
    echo '<p><strong>' . esc_html__( 'WooCommerce PayPal Standard requires WooCommerce to be installed and active.', 'restore-paypal-standard-for-woocommerce' ) . '</strong></p>';
    
    if ( ! file_exists( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' ) ) {
        echo '<p><a href="' . esc_url( $install_url ) . '" class="button-primary">' . esc_html__( 'Install WooCommerce', 'restore-paypal-standard-for-woocommerce' ) . '</a></p>';
    } elseif ( is_plugin_inactive( 'woocommerce/woocommerce.php' ) ) {
        echo '<p><a href="' . esc_url( $activate_url ) . '" class="button-primary">' . esc_html__( 'Activate WooCommerce', 'restore-paypal-standard-for-woocommerce' ) . '</a></p>';
    }
    
    echo '</div>';
}

/**
 * Declare compatibility with WooCommerce HPOS (High-Performance Order Storage)
 */
function rpsfw_declare_hpos_compatibility() {
    // Check if the class exists without using ::class syntax
    if ( class_exists( 'Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        // Declare compatibility with custom order tables
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', RPSFW_PLUGIN_FILE, true );
    }
}

/**
 * Add the gateway to WooCommerce
 * 
 * @param array $gateways Payment gateways.
 * @return array
 */
function rpsfw_add_paypal_gateway( $gateways ) {
    rpsfw_debug_log('rpsfw: Adding gateway to WooCommerce payment gateways filter');
    
    // Include the gateway class if it's not already loaded
    if ( ! class_exists( 'rpsfw_Gateway_PayPal_Standard' ) ) {
        // Load the settings class first
        if ( ! class_exists( 'rpsfw_Gateway_PayPal_Standard_Settings' ) ) {
            require_once RPSFW_PLUGIN_DIR . 'includes/paypal-standard-settings.php';
        }
        
        // Then load the main gateway class
        require_once RPSFW_PLUGIN_DIR . 'includes/paypal-standard.php';
    }
    
    // Add our gateway to the list
    $gateways[] = 'rpsfw_Gateway_PayPal_Standard';
    
    rpsfw_debug_log('rpsfw: Added gateway to gateways array');
    return $gateways;
}

/**
 * Add settings link to plugin page
 */
function rpsfw_add_settings_link( $links ) {
    // Check if migration is needed but not completed
    $migration_complete = 'yes' === get_option( 'rpsfw_migration_completed', 'no' );
    $has_native_settings = function_exists( 'rpsfw_has_native_paypal_settings' ) && rpsfw_has_native_paypal_settings();
    
    // Settings link label
    $settings_label = __( 'Settings', 'restore-paypal-standard-for-woocommerce' );
    
    if (!$has_native_settings || $migration_complete) {
        // If migration is complete or not needed, link to our settings
        $settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=restore_paypal_standard' );
        $settings_link = '<a href="' . $settings_url . '">' . $settings_label . '</a>';
        
        if (function_exists('rpsfw_debug_log')) {
            rpsfw_debug_log('rpsfw: Adding settings link to our PayPal settings page');
        }
    } else {
        // If migration is needed but not completed, link to native PayPal settings
        $settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=paypal' );
        $settings_link = '<a href="' . $settings_url . '">' . $settings_label . '</a>';
        
        if (function_exists('rpsfw_debug_log')) {
            rpsfw_debug_log('rpsfw: Adding settings link to native PayPal settings page');
        }
    }
    
    array_unshift( $links, $settings_link );
    
    return $links;
}

/**
 * Initialize the plugin
 */
function woo_paypal_standard_init() {
    // Load text domain
    load_plugin_textdomain( 'restore-paypal-standard-for-woocommerce', false, dirname( plugin_basename( RPSFW_PLUGIN_FILE ) ) . '/languages' );
    
    // Check if WooCommerce is active
    if ( ! rpsfw_woocommerce_dependency_check() ) {
        rpsfw_debug_log('rpsfw: WooCommerce dependency check failed');
        return;
    }
    
    rpsfw_debug_log('rpsfw: Plugin initialized, adding payment_gateways filter');
    
    // Add our gateway to WooCommerce
    add_filter( 'woocommerce_payment_gateways', 'rpsfw_add_paypal_gateway' );
}

/**
 * Add an admin notice to configure the plugin after activation
 */
function rpsfw_admin_notice() {
    // If migration notice is being shown, don't show the activation notice
    if ( function_exists( 'rpsfw_has_native_paypal_settings' ) && rpsfw_has_native_paypal_settings() && 'yes' !== get_option( 'rpsfw_migration_completed', 'no' ) ) {
        return;
    }
    
    // Show notice either after activation or after successful migration
    if ( get_transient( 'rpsfw_activation_notice' ) || get_transient( 'rpsfw_migration_success' ) ) {
        // Get the settings URL
        $settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=restore_paypal_standard' );
        
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p>' . sprintf( 
            /* translators: %s: settings URL */
            __( 'Thank you for installing Restore PayPal Standard for WooCommerce. Please %s to start accepting payments.', 'restore-paypal-standard-for-woocommerce' ),
            '<a href="' . esc_url( $settings_url ) . '">' . __( 'configure your settings', 'restore-paypal-standard-for-woocommerce' ) . '</a>'
        ) . '</p>';
        echo '</div>';
        
        // Delete the transients so the notice only shows once
        delete_transient( 'rpsfw_activation_notice' );
        delete_transient( 'rpsfw_migration_success' );
    }
}

/**
 * Set a transient on plugin activation
 */
function rpsfw_activation_hook() {
    // Set a transient to show the activation notice
    set_transient( 'rpsfw_activation_notice', true, 5 * DAY_IN_SECONDS );
}

/**
 * Add debug helper function
 *
 * @param string $message Message to log
 */
function rpsfw_debug_log($message) {
    global $rpsfw_debug_enabled;
    
    // Initialize debug status if not set
    if ($rpsfw_debug_enabled === null) {
        // Check settings
        $settings = get_option('woocommerce_restore_paypal_standard_settings', array());
        
        // Enable debugging if debug_enabled is set to 'yes' - keep sandbox mode removed
        $rpsfw_debug_enabled = isset($settings['debug_enabled']) ? ($settings['debug_enabled'] === 'yes') : false;
    }
    
    // Only log if debugging is explicitly enabled in settings OR WP_DEBUG is enabled
    if ($rpsfw_debug_enabled || (defined('WP_DEBUG') && WP_DEBUG)) {
        // Use WC logger if available
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->debug($message, array('source' => 'restore_paypal_standard'));
        } else {
            // Fallback to error_log only when WC logger isn't available
            error_log('rpsfw PayPal: ' . $message);
        }
    }
}

/**
 * Hide our settings tab when migration is needed but hasn't been completed
 *
 * @param array $sections WooCommerce checkout sections
 * @return array Filtered sections
 */
function rpsfw_filter_checkout_sections( $sections ) {
    // Check if migration is needed but not completed
    $migration_complete = 'yes' === get_option( 'rpsfw_migration_completed', 'no' );
    $has_native_settings = function_exists( 'rpsfw_has_native_paypal_settings' ) && rpsfw_has_native_paypal_settings();
    
    // Remove our section if migration is needed but not completed
    if ($has_native_settings && !$migration_complete) {
        if (isset($sections['restore_paypal_standard'])) {
            unset($sections['restore_paypal_standard']);
        }
    }
    
    return $sections;
}

/**
 * Register all hooks and actions for the plugin
 */
function rpsfw_register_hooks() {
    // Declare HPOS compatibility
    add_action( 'before_woocommerce_init', 'rpsfw_declare_hpos_compatibility' );
    
    // Add settings link to plugin page
    add_filter( 'plugin_action_links_' . plugin_basename( RPSFW_PLUGIN_FILE ), 'rpsfw_add_settings_link' );
    
    // Initialize the plugin - use a higher priority to ensure WooCommerce is loaded first
    add_action( 'plugins_loaded', 'woo_paypal_standard_init', 20 );
    
    // Add admin notice
    add_action( 'admin_notices', 'rpsfw_admin_notice' );
    
    // Filter payment gateways during migration
    add_filter( 'woocommerce_payment_gateways', 'rpsfw_filter_payment_gateways', 30 );
    
    // Filter checkout sections to hide our settings tab if needed
    add_filter( 'woocommerce_get_sections_checkout', 'rpsfw_filter_checkout_sections', 20 );
}

/**
 * Filter WooCommerce payment gateways during migration
 *
 * @param array $gateways Payment gateways
 * @return array Filtered payment gateways
 */
function rpsfw_filter_payment_gateways( $gateways ) {
    // Check if migration is complete - we only hide our PayPal gateway if migration is needed
    $migration_complete = 'yes' === get_option( 'rpsfw_migration_completed', 'no' );
    $has_native_settings = function_exists( 'rpsfw_has_native_paypal_settings' ) && rpsfw_has_native_paypal_settings();
    
    // Get the plugin settings
    $settings = get_option('woocommerce_restore_paypal_standard_settings', array());
    
    // Check if native PayPal is enabled via our debugging option
    $enable_native_paypal = isset($settings['enable_native_paypal']) && $settings['enable_native_paypal'] === 'yes';
    
    if (function_exists('rpsfw_debug_log')) {
        rpsfw_debug_log('rpsfw: Filter payment gateways - migration_complete: ' . ($migration_complete ? 'yes' : 'no'));
        rpsfw_debug_log('rpsfw: Filter payment gateways - has_native_settings: ' . ($has_native_settings ? 'yes' : 'no'));
        rpsfw_debug_log('rpsfw: Filter payment gateways - enable_native_paypal: ' . ($enable_native_paypal ? 'yes' : 'no'));
    }
    
    // Hide our gateway if migration is needed but not completed
    if ($has_native_settings && !$migration_complete) {
        // Array of our PayPal gateway class names to hide
        $our_paypal_gateways = array(
            'rpsfw_Gateway_PayPal_Standard'  // Our restored PayPal gateway
        );
        
        // Filter out our PayPal gateway
        $gateways = array_filter($gateways, function($gateway) use ($our_paypal_gateways) {
            // Return false to filter out our PayPal gateway, true to keep other gateways
            return !in_array($gateway, $our_paypal_gateways);
        });
        
        if (function_exists('rpsfw_debug_log')) {
            rpsfw_debug_log('rpsfw: Hiding our gateway until migration is completed');
        }
    } else if ($migration_complete && !$enable_native_paypal) {
        // If migration is completed and native PayPal is not enabled, hide the native WooCommerce PayPal gateway
        // Native WooCommerce PayPal gateway class name
        $native_paypal_gateway = 'WC_Gateway_Paypal';
        
        // Filter out the native PayPal gateway
        $gateways = array_filter($gateways, function($gateway) use ($native_paypal_gateway) {
            // Return false to filter out native PayPal gateway, true to keep other gateways
            return $gateway !== $native_paypal_gateway;
        });
        
        if (function_exists('rpsfw_debug_log')) {
            rpsfw_debug_log('rpsfw: Hiding native WooCommerce PayPal gateway after migration');
        }
    } else if ($enable_native_paypal) {
        if (function_exists('rpsfw_debug_log')) {
            rpsfw_debug_log('rpsfw: Native PayPal gateway enabled via debug option');
        }
    }
    
    return $gateways;
}

/**
 * Add deactivation survey
 */
function rpsfw_enqueue_deactivation_survey() {
    if (get_current_screen() && get_current_screen()->id === 'plugins') {
        // Enqueue JavaScript
        wp_enqueue_script('rpsfw-deactivation-survey', plugins_url('assets/js/deactivation-survey.js', RPSFW_PLUGIN_FILE), array('jquery'), RPSFW_VERSION, true);
        
        // Enqueue admin CSS (this contains the survey styles)
        wp_enqueue_style('rpsfw-admin-css', plugins_url('assets/css/paypal-standard-admin.css', RPSFW_PLUGIN_FILE), array(), RPSFW_VERSION);
        
        wp_localize_script('rpsfw-deactivation-survey', 'rpsfwDeactivationSurvey', array(
            'pluginVersion' => RPSFW_VERSION,
            'deactivationOptions' => array(
                'no_longer_needed' => __('I no longer need the plugin', 'restore-paypal-standard-for-woocommerce'),
                'found_better' => __('I found a better plugin', 'restore-paypal-standard-for-woocommerce'),
                'not_working' => __('The plugin is not working', 'restore-paypal-standard-for-woocommerce'),
                'temporary' => __('It\'s a temporary deactivation', 'restore-paypal-standard-for-woocommerce'),
                'other' => __('Other', 'restore-paypal-standard-for-woocommerce')
            ),
            'strings' => array(
                'title' => __('Restore PayPal Standard For WooCommerce Deactivation', 'restore-paypal-standard-for-woocommerce'),
                'description' => __('If you have a moment, please let us know why you are deactivating. All submissions are anonymous and we only use this feedback to improve this plugin.', 'restore-paypal-standard-for-woocommerce'),
                'otherPlaceholder' => __('Please tell us more...', 'restore-paypal-standard-for-woocommerce'),
                'skipButton' => __('Skip & Deactivate', 'restore-paypal-standard-for-woocommerce'),
                'submitButton' => __('Submit & Deactivate', 'restore-paypal-standard-for-woocommerce'),
                'cancelButton' => __('Cancel', 'restore-paypal-standard-for-woocommerce'),
                'betterPluginQuestion' => __('What is the name of the plugin?', 'restore-paypal-standard-for-woocommerce'),
                'notWorkingQuestion' => __('We\'re sorry to hear that. Can you describe the issue?', 'restore-paypal-standard-for-woocommerce'),
                'errorRequired' => __('Error: Please complete the required field.', 'restore-paypal-standard-for-woocommerce')
            )
        ));
    }
}
add_action('admin_enqueue_scripts', 'rpsfw_enqueue_deactivation_survey');

