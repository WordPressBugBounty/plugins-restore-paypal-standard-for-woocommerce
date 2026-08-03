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
    rpsfw_debug_log('rpsfw: Current gateways count: ' . count($gateways));
    
    // Include the PayPal Standard gateway class if it's not already loaded
    if ( ! class_exists( 'rpsfw_Gateway_PayPal_Standard' ) ) {
        // Load the settings class first
        if ( ! class_exists( 'rpsfw_Gateway_PayPal_Standard_Settings' ) ) {
            require_once RPSFW_PLUGIN_DIR . 'includes/paypal-standard/paypal-standard-settings.php';
        }
        
        // Then load the main gateway class
        require_once RPSFW_PLUGIN_DIR . 'includes/paypal-standard/class-gateway-paypal-standard.php';
        rpsfw_debug_log('rpsfw: PayPal Standard gateway class loaded');
    } else {
        rpsfw_debug_log('rpsfw: PayPal Standard gateway class already exists');
    }
    
    // Add PayPal Standard gateway to the list
    $gateways[] = 'rpsfw_Gateway_PayPal_Standard';
    
    // Include the PayPal Commerce gateway class if it's not already loaded
    if ( ! class_exists( 'WC_Gateway_PayPal_Commerce' ) ) {
        require_once RPSFW_PLUGIN_DIR . 'includes/paypal-commerce/class-gateway-paypal-commerce.php';
        rpsfw_debug_log('rpsfw: PayPal Commerce gateway class loaded');
    } else {
        rpsfw_debug_log('rpsfw: PayPal Commerce gateway class already exists');
    }
    
    // Add PayPal Commerce gateway to the list
    $gateways[] = 'WC_Gateway_PayPal_Commerce';
    
    // Include the Stripe gateway class if it's not already loaded
    if ( ! class_exists( 'RPSFW_Gateway_Stripe' ) ) {
        try {
            require_once RPSFW_PLUGIN_DIR . 'includes/stripe/class-gateway-stripe.php';
            rpsfw_debug_log('rpsfw: Stripe gateway class loaded');
        } catch ( Exception $e ) {
            rpsfw_debug_log('rpsfw: FATAL ERROR loading Stripe gateway: ' . $e->getMessage());
        }
    } else {
        rpsfw_debug_log('rpsfw: Stripe gateway class already exists');
    }
    
    // Verify class exists before adding
    if ( class_exists( 'RPSFW_Gateway_Stripe' ) ) {
        $gateways[] = 'RPSFW_Gateway_Stripe';
    }
    
    rpsfw_debug_log('rpsfw: Added gateways to array. New count: ' . count($gateways));
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
        // If migration is complete or not needed, link to our hub page
        $settings_url = admin_url( 'admin.php?page=rpsfw-settings-hub' );
        $settings_link = '<a href="' . $settings_url . '">' . $settings_label . '</a>';
        
        if (function_exists('rpsfw_debug_log')) {
            rpsfw_debug_log('rpsfw: Adding settings link to hub page');
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

    // Ensure our gateways are instantiated in the admin. WooCommerce only adds
    // gateway class NAMES to the woocommerce_payment_gateways filter and
    // instantiates them lazily (e.g. at checkout / on the settings screen). On
    // the order edit screen it generally does NOT instantiate them, so the
    // gateway constructors — which register the per-order refund + cancel
    // subscription meta boxes on 'add_meta_boxes' — never run. Forcing
    // instantiation early (admin_init fires before add_meta_boxes) guarantees
    // those panels are registered on order/subscription screens.
    if ( is_admin() ) {
        add_action( 'admin_init', 'rpsfw_admin_instantiate_gateways' );
    }
}

/**
 * Instantiate the plugin's payment gateways in the admin so their order-screen
 * meta boxes register. Safe to call repeatedly — WooCommerce caches the
 * instantiated gateway objects, and each panel guards against double
 * registration via did_action().
 */
function rpsfw_admin_instantiate_gateways() {
    if ( ! function_exists( 'WC' ) ) {
        return;
    }
    $gateways = WC()->payment_gateways();
    if ( $gateways ) {
        // Triggers WC_Payment_Gateways::init(), which does `new $gateway_class()`
        // for each registered gateway — running our constructors.
        $gateways->payment_gateways();
    }
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
        // Get the settings URL - point to the new hub page
        $settings_url = admin_url( 'admin.php?page=rpsfw-settings-hub' );
        
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p>' . wp_kses_post( sprintf( 
            /* translators: %1$s: plugin name, %2$s: settings URL */
            __( 'Thank you for installing %1$s. Please %2$s to start accepting payments.', 'restore-paypal-standard-for-woocommerce' ),
            esc_html( RPSFW_PLUGIN_NAME ),
            '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'configure your settings', 'restore-paypal-standard-for-woocommerce' ) . '</a>'
        ) ) . '</p>';
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

    // Set a short-lived transient that triggers a one-time redirect to the
    // settings hub on the next admin page load (handled in
    // rpsfw_maybe_activation_redirect()). register_activation_hook cannot
    // safely redirect on its own.
    set_transient( 'rpsfw_activation_redirect', true, 30 );
}

/**
 * Redirect to the settings hub once, right after activation.
 *
 * Skips the redirect during bulk plugin activation so we don't hijack the
 * "activate multiple plugins" flow.
 */
function rpsfw_maybe_activation_redirect() {
    if ( ! get_transient( 'rpsfw_activation_redirect' ) ) {
        return;
    }

    // Only redirect on a single-plugin activation, not bulk activation.
    if ( isset( $_GET['activate-multi'] ) ) {
        delete_transient( 'rpsfw_activation_redirect' );
        return;
    }

    delete_transient( 'rpsfw_activation_redirect' );

    wp_safe_redirect( admin_url( 'admin.php?page=rpsfw-settings-hub' ) );
    exit;
}
add_action( 'admin_init', 'rpsfw_maybe_activation_redirect' );

/**
 * Add debug helper function
 *
 * @param string $message Message to log
 */
function rpsfw_debug_log($message) {
    global $rpsfw_debug_enabled;
    
    // Don't use WC logger before 'init' action to avoid triggering early translation loading
    // This prevents the "_load_textdomain_just_in_time was called incorrectly" warning
    if ( ! did_action( 'init' ) ) {
        // Before init, only log to error_log if WP_DEBUG is enabled
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'rpsfw PayPal: ' . $message );
        }
        return;
    }
    
    // Initialize debug status if not set
    if ($rpsfw_debug_enabled === null) {
        // Check settings
        $settings = get_option('woocommerce_restore_paypal_standard_settings', array());
        
        // Enable debugging if debug_enabled is set to 'yes' - keep sandbox mode removed
        $rpsfw_debug_enabled = isset($settings['debug_enabled']) ? ($settings['debug_enabled'] === 'yes') : false;
    }
    
    // Only write to the WooCommerce log file when logging is explicitly
    // enabled in the plugin settings. WP_DEBUG alone must not create the
    // gateway log file; it only mirrors messages to PHP's error_log for
    // local development.
    if ($rpsfw_debug_enabled) {
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->debug($message, array('source' => 'rpsfw-paypal-standard'));
        } else {
            // Fallback to error_log only when WC logger isn't available
            error_log('rpsfw PayPal: ' . $message);
        }
    } elseif (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('rpsfw PayPal: ' . $message);
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
 * Rename our gateway class names in the WooCommerce Orders "Origin" column.
 *
 * WooCommerce's Order Attribution feature stores the payment gateway class name
 * as the UTM source when no marketing attribution data is present. This filter
 * replaces those raw class names with human-readable labels — but only for
 * gateways that belong to this plugin.
 *
 * @param string $formatted_source The formatted source string shown in the column.
 * @param string $source           The raw source value stored in order meta.
 * @return string
 */
function rpsfw_filter_order_origin_source( $formatted_source, $source ) {
    $map = array(
        'Rpsfw_paypal_commerce'      => 'PayPal Commerce',
        'rpsfw_paypal_commerce'      => 'PayPal Commerce',
        'WC_Gateway_PayPal_Commerce' => 'PayPal Commerce',
        'Rpsfw_gateway_stripe'       => 'Stripe',
        'rpsfw_stripe'               => 'Stripe',
        'RPSFW_Gateway_Stripe'       => 'Stripe',
        'Restore_paypal_standard'    => 'PayPal Standard',
        'restore_paypal_standard'    => 'PayPal Standard',
        'Rpsfw_gateway_paypal_standard' => 'PayPal Standard',
    );

    // Match against both the raw source and the already-formatted (ucfirst) version.
    if ( isset( $map[ $source ] ) ) {
        return $map[ $source ];
    }
    if ( isset( $map[ $formatted_source ] ) ) {
        return $map[ $formatted_source ];
    }

    return $formatted_source;
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

    // Replace raw gateway class names with friendly labels in the Orders "Origin" column.
    add_filter( 'wc_order_attribution_origin_formatted_source', 'rpsfw_filter_order_origin_source', 10, 2 );
}

/**
 * Filter WooCommerce payment gateways during migration
 *
 * @param array $gateways Payment gateways
 * @return array Filtered payment gateways
 */
function rpsfw_filter_payment_gateways( $gateways ) {
    rpsfw_debug_log('rpsfw: Filter payment gateways called - Total gateways: ' . count($gateways));
    
    // Check if migration is complete - we only hide our PayPal gateway if migration is needed
    $migration_complete = 'yes' === get_option( 'rpsfw_migration_completed', 'no' );
    $has_native_settings = function_exists( 'rpsfw_has_native_paypal_settings' ) && rpsfw_has_native_paypal_settings();
    
    // Get the plugin settings
    $settings = get_option('woocommerce_restore_paypal_standard_settings', array());
    
    // Check if native PayPal is enabled via our debugging option
    $enable_native_paypal = isset($settings['enable_native_paypal']) && $settings['enable_native_paypal'] === 'yes';

    // Whether the merchant has explicitly enabled OUR gateway. Once enabled,
    // the gateway must appear at checkout regardless of migration state. The
    // migration prompt is only a suggestion (and now only shows AFTER the
    // gateway is enabled), so it must never suppress a gateway the merchant
    // has deliberately turned on.
    $our_gateway_enabled = is_array($settings) && isset($settings['enabled']) && 'yes' === $settings['enabled'];

    rpsfw_debug_log('rpsfw: Filter payment gateways - migration_complete: ' . ($migration_complete ? 'yes' : 'no'));
    rpsfw_debug_log('rpsfw: Filter payment gateways - has_native_settings: ' . ($has_native_settings ? 'yes' : 'no'));
    rpsfw_debug_log('rpsfw: Filter payment gateways - enable_native_paypal: ' . ($enable_native_paypal ? 'yes' : 'no'));
    rpsfw_debug_log('rpsfw: Filter payment gateways - our_gateway_enabled: ' . ($our_gateway_enabled ? 'yes' : 'no'));
    
    // Hide our gateway only while migration is needed AND the merchant has not
    // enabled it yet. This preserves the pre-migration nudge without hiding an
    // explicitly enabled gateway from checkout.
    //
    // IMPORTANT: never hide our gateway in the admin. This filter runs on the
    // global woocommerce_payment_gateways registration, so removing the class
    // here also removes it from the Payments settings screen — which makes the
    // gateway's settings page render blank (no tabs/fields, just Save). The
    // hiding is purely a checkout/front-end concern, so restrict it to
    // non-admin requests.
    if ( ! is_admin() && $has_native_settings && !$migration_complete && !$our_gateway_enabled) {
        // Array of our PayPal gateway class names to hide
        $our_paypal_gateways = array(
            'rpsfw_Gateway_PayPal_Standard'  // Our restored PayPal gateway
        );
        
        // Filter out our PayPal gateway
        $gateways = array_filter($gateways, function($gateway) use ($our_paypal_gateways) {
            // Return false to filter out our PayPal gateway, true to keep other gateways
            return !in_array($gateway, $our_paypal_gateways);
        });
        
        rpsfw_debug_log('rpsfw: Hiding our gateway until migration is completed');
        rpsfw_debug_log('rpsfw: Remaining gateways after filter: ' . count($gateways));
    } 
    // If migration is completed and native PayPal is not enabled, hide the native WooCommerce PayPal gateway
    else if ($migration_complete && !$enable_native_paypal) {
        // Native WooCommerce PayPal gateway class name
        $native_paypal_gateway = 'WC_Gateway_Paypal';
        
        // Filter out the native PayPal gateway
        $gateways = array_filter($gateways, function($gateway) use ($native_paypal_gateway) {
            // Return false to filter out native PayPal gateway, true to keep other gateways
            return $gateway !== $native_paypal_gateway;
        });
        
        rpsfw_debug_log('rpsfw: Hiding native WooCommerce PayPal gateway after migration');
        rpsfw_debug_log('rpsfw: Remaining gateways after filter: ' . count($gateways));
    } else if ($enable_native_paypal) {
        rpsfw_debug_log('rpsfw: Native PayPal gateway enabled via debug option');
    } else {
        rpsfw_debug_log('rpsfw: No gateway filtering applied');
    }
    
    return $gateways;
}

/**
 * Add deactivation survey
 */
function rpsfw_enqueue_deactivation_survey() {
    if (get_current_screen() && get_current_screen()->id === 'plugins') {
        // Version assets by file modification time so edits always bust the
        // browser/WordPress cache (RPSFW_VERSION alone doesn't change when we
        // edit the JS/CSS directly, so cached copies would otherwise persist).
        $js_path  = RPSFW_PLUGIN_DIR . 'assets/js/deactivation-survey.js';
        $css_path = RPSFW_PLUGIN_DIR . 'assets/css/paypal-standard-admin.css';
        $js_ver   = file_exists( $js_path ) ? (string) filemtime( $js_path ) : RPSFW_VERSION;
        $css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : RPSFW_VERSION;

        // Enqueue JavaScript
        wp_enqueue_script('rpsfw-deactivation-survey', plugins_url('assets/js/deactivation-survey.js', RPSFW_PLUGIN_FILE), array('jquery'), $js_ver, true);

        // Enqueue admin CSS (this contains the survey styles)
        wp_enqueue_style('rpsfw-admin-css', plugins_url('assets/css/paypal-standard-admin.css', RPSFW_PLUGIN_FILE), array(), $css_ver);
        
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
                /* translators: %s: plugin name */
                'title' => sprintf( __('%s Deactivation', 'restore-paypal-standard-for-woocommerce'), RPSFW_PLUGIN_NAME ),
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



/**
 * Cap the WooCommerce "Order notes" list height (with a scrollbar) on the order
 * edit screen, matching the Activity box on the subscription screen.
 *
 * Scoped to orders paid through THIS plugin's gateways only — orders paid with
 * any other gateway keep WooCommerce's default (unbounded) notes list.
 *
 * @param string $hook Current admin page hook (unused; screen is resolved below).
 */
function rpsfw_order_notes_scrollbar_style( $hook ) {
	if ( ! function_exists( 'get_current_screen' ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( ! $screen ) {
		return;
	}

	// Order edit screen only (legacy shop_order + HPOS wc-orders).
	$order_edit_screens = array( 'shop_order', 'woocommerce_page_wc-orders' );
	if ( ! in_array( $screen->id, $order_edit_screens, true ) ) {
		return;
	}

	// Resolve the order currently being edited (legacy 'post' or HPOS 'id').
	$order_id = 0;
	if ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	} elseif ( isset( $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_id = absint( $_GET['id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
	if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) {
		return;
	}
	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return;
	}

	// Only for orders paid through one of this plugin's gateways.
	$our_gateways = array( 'rpsfw_stripe', 'rpsfw_paypal_commerce', 'restore_paypal_standard' );
	if ( ! in_array( $order->get_payment_method(), $our_gateways, true ) ) {
		return;
	}

	wp_register_style( 'rpsfw-order-notes-scroll', false, array(), RPSFW_VERSION );
	wp_enqueue_style( 'rpsfw-order-notes-scroll' );
	wp_add_inline_style(
		'rpsfw-order-notes-scroll',
		'#woocommerce-order-notes ul.order_notes{max-height:340px;overflow-y:auto;}'
	);
}
add_action( 'admin_enqueue_scripts', 'rpsfw_order_notes_scrollbar_style' );

/**
 * Whether an order still needs to be marked paid, re-read from storage.
 *
 * WC_Order::payment_complete() tests the order object's IN-MEMORY status. A
 * request that loaded the order and then spent time talking to the processor
 * (the checkout "finalize" call makes several Stripe round trips before
 * completing the order) still believes the order is pending, so it transitions
 * pending -> processing a second time when a webhook already completed it in a
 * parallel request. WooCommerce fires the customer "order processing" email on
 * every such transition, so the buyer gets two "Thank you for your order"
 * emails. This is most visible on subscription purchases, where a single
 * checkout produces several concurrent Stripe events that can each complete the
 * parent order.
 *
 * Call this immediately before payment_complete() on any path that may run
 * concurrently with a webhook, and skip the completion when it returns false.
 *
 * The status is read with a direct query on purpose. wc_get_order() serves from
 * WooCommerce's order cache, which is per-request unless the site runs a
 * persistent object cache — so it would happily hand back the very copy this
 * request loaded before the webhook fired, making the check a no-op exactly when
 * it matters. One scalar query is also cheaper than hydrating a whole order.
 *
 * @since 4.0.0
 *
 * @param WC_Order|int $order Order object or order id.
 * @return bool True when the stored order is still awaiting payment.
 */
function rpsfw_order_still_needs_payment( $order ) {
	$order_id = ( $order instanceof WC_Order ) ? $order->get_id() : absint( $order );
	if ( ! $order_id ) {
		return false;
	}

	global $wpdb;

	// HPOS keeps the authoritative status in its own table; classic storage
	// keeps it in the posts table.
	$hpos = class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

	if ( $hpos ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}wc_orders WHERE id = %d", $order_id ) );
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$status = $wpdb->get_var( $wpdb->prepare( "SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $order_id ) );
	}

	if ( empty( $status ) ) {
		return false;
	}

	// Both backends store the prefixed form ('wc-processing'); WooCommerce's
	// status lists use the bare form.
	if ( 0 === strpos( $status, 'wc-' ) ) {
		$status = substr( $status, 3 );
	}

	// Mirror the status set payment_complete() acts on, so this answers exactly
	// "would payment_complete() transition this order?". The filter is only
	// applied when the caller gave us an order object, since callbacks expect
	// one as the second argument.
	$statuses = array( 'on-hold', 'pending', 'failed', 'cancelled' );
	if ( $order instanceof WC_Order ) {
		$statuses = apply_filters( 'woocommerce_valid_order_statuses_for_payment_complete', $statuses, $order );
	}

	return in_array( $status, $statuses, true );
}

/**
 * Meta key holding the mode a payment was taken in ('test' or 'live').
 *
 * Stamped on orders, and on native subscription records, at payment time. The
 * gateway's CURRENT mode setting must never be used to interpret an existing
 * payment: a store that switches test -> live would otherwise retroactively
 * reinterpret every past order, sending API lookups to the wrong account and
 * pointing dashboard links at the wrong place.
 */
define( 'RPSFW_PAYMENT_MODE_META', '_rpsfw_payment_mode' );

/**
 * Normalize any mode expression to the canonical 'test' or 'live'.
 *
 * Accepts the various shapes the codebase and the processors use: booleans
 * (testmode flags), 'yes'/'no', PayPal's 'sandbox', and Stripe's livemode.
 *
 * @since 4.0.0
 *
 * @param mixed $mode Mode expression.
 * @return string 'test' or 'live'.
 */
function rpsfw_normalize_payment_mode( $mode ) {
	if ( is_bool( $mode ) ) {
		return $mode ? 'test' : 'live';
	}

	$mode = strtolower( trim( (string) $mode ) );

	return in_array( $mode, array( 'test', 'sandbox', 'testmode', 'yes', '1' ), true ) ? 'test' : 'live';
}

/**
 * PayPal's own environment name for a canonical mode. PayPal's API endpoints,
 * onboarding credentials and webhook option keys are all keyed 'sandbox' /
 * 'live', so translate at that boundary only — everywhere else in this plugin
 * the mode is 'test' / 'live'.
 *
 * @since 4.0.0
 *
 * @param string $mode 'test' or 'live'.
 * @return string 'sandbox' or 'live'.
 */
function rpsfw_payment_mode_to_ppcp_env( $mode ) {
	return ( 'test' === rpsfw_normalize_payment_mode( $mode ) ) ? 'sandbox' : 'live';
}

/**
 * The mode a gateway is configured in right now.
 *
 * Only correct for deciding how to take a NEW payment. To interpret an existing
 * payment use rpsfw_get_order_payment_mode().
 *
 * @since 4.0.0
 *
 * @param string $gateway_id Gateway id, e.g. 'rpsfw_stripe'.
 * @return string 'test' or 'live'.
 */
function rpsfw_get_gateway_mode( $gateway_id ) {
	$options = get_option( 'woocommerce_' . $gateway_id . '_settings', array() );

	return ( isset( $options['testmode'] ) && 'yes' === $options['testmode'] ) ? 'test' : 'live';
}

/**
 * The mode an order's payment was actually taken in.
 *
 * Falls back to the gateway's current setting for orders placed before mode
 * stamping existed — those cannot be resolved any better, and the fallback
 * reproduces the old behaviour rather than guessing.
 *
 * @since 4.0.0
 *
 * @param WC_Order|int $order Order or order id.
 * @return string 'test' or 'live'.
 */
function rpsfw_get_order_payment_mode( $order ) {
	if ( ! $order instanceof WC_Order ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order ) ) : false;
	}
	if ( ! $order instanceof WC_Order ) {
		return 'live';
	}

	$mode = $order->get_meta( RPSFW_PAYMENT_MODE_META );
	if ( ! empty( $mode ) ) {
		return rpsfw_normalize_payment_mode( $mode );
	}

	return rpsfw_get_gateway_mode( $order->get_payment_method() );
}

/**
 * The mode recorded on an order, or '' when none was recorded.
 *
 * Unlike rpsfw_get_order_payment_mode(), this never falls back to the gateway's
 * current setting — so callers can tell "this order was taken in test mode"
 * apart from "we do not know". Display code must use this: showing the current
 * setting as though it were the order's mode states a guess as fact, and for
 * orders placed before stamping existed that guess is wrong exactly when it
 * matters (an old test order on a store that has since gone live).
 *
 * @since 4.0.0
 *
 * @param WC_Order|int $order Order or order id.
 * @return string 'test', 'live', or '' when unrecorded.
 */
function rpsfw_get_stamped_order_payment_mode( $order ) {
	if ( ! $order instanceof WC_Order ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $order ) ) : false;
	}
	if ( ! $order instanceof WC_Order ) {
		return '';
	}

	$mode = $order->get_meta( RPSFW_PAYMENT_MODE_META );

	return empty( $mode ) ? '' : rpsfw_normalize_payment_mode( $mode );
}

/**
 * Stamp the mode a payment was taken in onto an order.
 *
 * @since 4.0.0
 *
 * @param WC_Order    $order Order.
 * @param string|null $mode  Mode to record. Null or '' falls back to the
 *                           gateway's current mode, so callers can pass a
 *                           possibly-empty mode without special-casing it.
 * @param bool        $save  Whether to persist immediately. Pass false when the
 *                           caller saves the order itself.
 * @return string The recorded mode.
 */
function rpsfw_set_order_payment_mode( $order, $mode = null, $save = true ) {
	if ( ! $order instanceof WC_Order ) {
		return 'live';
	}

	$mode = ( null === $mode || '' === $mode )
		? rpsfw_get_gateway_mode( $order->get_payment_method() )
		: rpsfw_normalize_payment_mode( $mode );

	$order->update_meta_data( RPSFW_PAYMENT_MODE_META, $mode );
	if ( $save ) {
		$order->save();
	}

	return $mode;
}

/**
 * Whether an incoming processor event may act on an order.
 *
 * A live event must never touch an order paid in test mode, and vice versa —
 * the ids collide conceptually and acting across modes would complete or refund
 * the wrong money. The site's current mode is irrelevant here: what matters is
 * the mode the order was paid in.
 *
 * @since 4.0.0
 *
 * @param WC_Order $order      Order the event resolved to.
 * @param string   $event_mode Mode the event arrived from ('test' or 'live').
 * @return bool True when the event may be applied to this order.
 */
function rpsfw_event_mode_matches_order( $order, $event_mode ) {
	if ( ! $order instanceof WC_Order || '' === (string) $event_mode ) {
		return false;
	}

	return rpsfw_get_order_payment_mode( $order ) === rpsfw_normalize_payment_mode( $event_mode );
}

/**
 * Get (or set) the mode of the processor event being handled in this request.
 *
 * Empty outside a webhook request, which is how the order guard below knows to
 * stand aside for normal admin/checkout code.
 *
 * @since 4.0.0
 *
 * @param string|null $mode Pass 'test'/'live' to set, '' to clear, null to read.
 * @return string 'test', 'live', or '' when not handling an event.
 */
function rpsfw_current_webhook_mode( $mode = null ) {
	static $current = '';

	if ( null !== $mode ) {
		$current = ( '' === $mode ) ? '' : rpsfw_normalize_payment_mode( $mode );
	}

	return $current;
}

/**
 * Whether the event currently being handled may act on this order.
 *
 * Returns true outside a webhook request. Inside one, the event's mode must
 * match the mode the order was paid in — a live event must not complete or
 * refund a test order, and vice versa.
 *
 * @since 4.0.0
 *
 * @param WC_Order $order Order the event resolved to.
 * @return bool
 */
function rpsfw_webhook_may_touch_order( $order ) {
	$mode = rpsfw_current_webhook_mode();
	if ( '' === $mode ) {
		return true;
	}

	return rpsfw_event_mode_matches_order( $order, $mode );
}

/**
 * Display label for a mode. Call at render time only — never at option-default
 * or hook-registration time, since it translates.
 *
 * @since 4.0.0
 *
 * @param string $mode 'test' or 'live'.
 * @return string Translated label.
 */
function rpsfw_payment_mode_label( $mode ) {
	return ( 'test' === rpsfw_normalize_payment_mode( $mode ) )
		? __( 'Test', 'restore-paypal-standard-for-woocommerce' )
		: __( 'Live', 'restore-paypal-standard-for-woocommerce' );
}
