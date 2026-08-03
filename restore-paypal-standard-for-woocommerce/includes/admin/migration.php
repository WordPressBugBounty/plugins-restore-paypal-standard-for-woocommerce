<?php
/**
 * PayPal Standard Migration functionality
 *
 * @package Restore_PayPal_Standard_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;










/**
 * Check if WooCommerce's native PayPal settings exist and the migration has not been completed
 * 
 * @return boolean True if native PayPal settings exist and migration is needed
 */
function rpsfw_has_native_paypal_settings() {
    // Check if migration has already been completed
    if ( 'yes' === get_option( 'rpsfw_migration_completed', 'no' ) ) {
        return false;
    }
    
    // Check if user has permanently dismissed the notice
    if ( 'yes' === get_option( 'rpsfw_migration_notice_dismissed_permanently', 'no' ) ) {
        return false;
    }
    
    // Check if notice was temporarily dismissed and if the dismissal period has expired
    $dismissal_timestamp = get_option( 'rpsfw_migration_notice_dismissed_until', 0 );
    if ( $dismissal_timestamp && time() < $dismissal_timestamp ) {
        return false;
    }
    
    // Get the native PayPal settings
    $native_settings = get_option( 'woocommerce_paypal_settings', array() );
    
    // Default WooCommerce PayPal settings - exact match from the user's provided serialized array
    $default_settings = array(
        'enabled' => 'no',
        'title' => 'PayPal',
        'description' => 'Pay via PayPal; you can pay with your credit card if you don\'t have a PayPal account.',
        'email' => 'test@test.com',
        'advanced' => '',
        'testmode' => 'no',
        'debug' => 'no',
        'ipn_notification' => 'yes',
        'receiver_email' => 'test@test.com',
        'identity_token' => '',
        'invoice_prefix' => 'WC-',
        'send_shipping' => 'yes',
        'address_override' => 'no',
        'paymentaction' => 'sale',
        'image_url' => '',
        'api_details' => '',
        'api_username' => '',
        'api_password' => '',
        'api_signature' => '',
        'sandbox_api_username' => '',
        'sandbox_api_password' => '',
        'sandbox_api_signature' => ''
    );
    
    // If settings match default settings exactly, don't show the migration notice
    if (!empty($native_settings)) {
		
		// Check if all keys in the default settings are present and equal in the native settings
		$settings_match = true;
		
		
		foreach ($default_settings as $key => $value) {
			if (!isset($native_settings[$key]) || $native_settings[$key] !== $value) {
				$settings_match = false;
				break;
			}
		}
		
		// Also check if any extra keys exist in native settings that aren't in the defaults
		if ($settings_match) {
			foreach ($native_settings as $key => $value) {
				if (!isset($default_settings[$key])) {
					$settings_match = false;
					break;
				}
			}
		}
		
		// If settings match default settings and _should_load is 'no', don't show migration notice
		if ($settings_match) {
			return false;
		}
		
    }
    
    // Check if native PayPal settings exist with at least email or sandbox_email
    if ( ! empty( $native_settings ) && 
        ( ! empty( $native_settings['email'] ) || ! empty( $native_settings['sandbox_email'] ) ) ) {
        
        return true;
    }
    
    return false;
}















/**
 * Migrate settings from WooCommerce's native PayPal to our restored version
 */
function rpsfw_migrate_native_paypal_settings() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }
    
    // Get the native PayPal settings
    $native_settings = get_option( 'woocommerce_paypal_settings', array() );
    
    // Get our plugin's settings
    $restored_settings = get_option( 'woocommerce_restore_paypal_standard_settings', array() );
    
    // List of settings to migrate (mapping between native and restored keys)
    $settings_to_migrate = array(
        // Common fields (same keys)
        'enabled'          => 'enabled',
        'title'            => 'title',
        'description'      => 'description',
        'identity_token'   => 'identity_token',
        'invoice_prefix'   => 'invoice_prefix',
        'image_url'        => 'image_url',
        'debug'            => 'debug_enabled',
        'testmode'         => 'testmode',
        
        // Special mappings
        'address_override' => 'address_override',
        'send_shipping'    => 'send_shipping',
        'ipn_notification' => 'ipn_notification',

        // API Credentials
        'api_username'     => 'api_username',
        'api_password'     => 'api_password',
        'api_signature'    => 'api_signature',
        'sandbox_api_username' => 'sandbox_api_username',
        'sandbox_api_password' => 'sandbox_api_password',
        'sandbox_api_signature' => 'sandbox_api_signature',
        'paymentaction'    => 'paymentaction',
        'advanced'         => 'advanced',
        '_should_load'     => '_should_load',
    );
    
    // Migrate the settings
    foreach ( $settings_to_migrate as $native_key => $restored_key ) {
        if ( isset( $native_settings[ $native_key ] ) ) {
            $restored_settings[ $restored_key ] = $native_settings[ $native_key ];
        }
    }
    
    // Handle email settings based on sandbox mode
    $is_sandbox = isset($native_settings['testmode']) && 'yes' === $native_settings['testmode'];
    
    if ($is_sandbox) {
        // Carry the LIVE payee address across as well. The gateway sends to
        // 'email' in live mode, so dropping it just because the store happened
        // to be in test mode at migration time would leave live checkout with
        // no PayPal address the moment the merchant goes live.
        if (!empty($native_settings['email'])) {
            $restored_settings['email'] = $native_settings['email'];
        }

        // In sandbox mode, use sandbox_email as primary email
        if (!empty($native_settings['sandbox_email'])) {
            $restored_settings['sandbox_email'] = $native_settings['sandbox_email'];
            // Also use sandbox_email for receiver_email in sandbox mode if it exists
            if (empty($native_settings['receiver_email'])) {
                $restored_settings['receiver_email'] = $native_settings['sandbox_email'];
            } else {
                $restored_settings['receiver_email'] = $native_settings['receiver_email'];
            }
        } elseif (!empty($native_settings['email'])) {
            // Fallback to regular email if sandbox_email is not set
            $restored_settings['sandbox_email'] = $native_settings['email'];
            // Also use email for receiver_email if it exists and receiver_email doesn't
            if (empty($native_settings['receiver_email'])) {
                $restored_settings['receiver_email'] = $native_settings['email'];
            } else {
                $restored_settings['receiver_email'] = $native_settings['receiver_email'];
            }
        }
    } else {
        // In live mode, use regular email
        if (!empty($native_settings['email'])) {
            $restored_settings['email'] = $native_settings['email'];
            // Also use email for receiver_email if it exists and receiver_email doesn't
            if (empty($native_settings['receiver_email'])) {
                $restored_settings['receiver_email'] = $native_settings['email'];
            } else {
                $restored_settings['receiver_email'] = $native_settings['receiver_email'];
            }
        }
        // Still copy sandbox_email if it exists
        if (!empty($native_settings['sandbox_email'])) {
            $restored_settings['sandbox_email'] = $native_settings['sandbox_email'];
        }
    }
    
    // Set the plugin as enabled if native gateway was enabled
    if (isset($native_settings['enabled']) && 'yes' === $native_settings['enabled']) {
        $restored_settings['enabled'] = 'yes';
        
        // Disable the native PayPal gateway
        $native_settings['enabled'] = 'no';
        update_option( 'woocommerce_paypal_settings', $native_settings );
    }
    
    // Save the restored settings
    update_option( 'woocommerce_restore_paypal_standard_settings', $restored_settings );
    
    // Set default values for text fields if they are blank - these are not set by default in the native paypal standard settings.
    if (empty($restored_settings['checkout_button_text'])) {
        $restored_settings['checkout_button_text'] = __('Proceed to PayPal', 'restore-paypal-standard-for-woocommerce');
    }
    if (empty($restored_settings['order_received_text'])) {
        $restored_settings['order_received_text'] = __('Thank you. Your order has been received', 'restore-paypal-standard-for-woocommerce');
    }
    if (empty($restored_settings['pending_order_received_text'])) {
        $restored_settings['pending_order_received_text'] = __('Thank you for your order. It is currently being processed. We are waiting for PayPal to authenticate the payment.', 'restore-paypal-standard-for-woocommerce');
    }
    
    // Update settings again with default values if needed
    update_option( 'woocommerce_restore_paypal_standard_settings', $restored_settings );
    
    // Mark migration as completed
    update_option( 'rpsfw_migration_completed', 'yes' );
    
    // Set a transient to show a success message
    set_transient( 'rpsfw_migration_success', true, 60 );
    
    rpsfw_debug_log('Migration from native PayPal Standard to Restored PayPal Standard completed successfully');
}

/**
 * Display the migration notice
 */
function rpsfw_display_migration_notice() {
    // Only show to admin users
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        return;
    }
    
    // Check if native PayPal settings exist
    if ( ! rpsfw_has_native_paypal_settings() ) {
        return;
    }
    
    // Don't show this notice if we're already showing the forced notice
    if ( get_transient( 'rpsfw_show_migration_notice' ) ) {
        return;
    }

    // Show the migration prompt whenever native PayPal settings exist and the
    // migration hasn't been completed/dismissed (handled by
    // rpsfw_has_native_paypal_settings() above). This is intentionally shown
    // regardless of whether the Restore PayPal Standard gateway is enabled or
    // disabled — the prompt appears as soon as the plugin is active.
    rpsfw_render_migration_notice( 0 );
}

/**
 * Render the migration notice with the given notice count
 *
 * @param int $notice_count The number of times the notice has been shown
 */
function rpsfw_render_migration_notice( $notice_count ) {
    $notice_class = 'notice-warning';
    ?>
    <div class="notice <?php echo esc_attr( $notice_class ); ?> is-dismissible rpsfw-migration-notice" style="padding:15px; border-left-color:#FFB900;" data-notice-id="rpsfw-migration-notice">
        <h2 style="margin-top:0;"><?php esc_html_e( 'Would you like to migrate any PayPal Standard settings?', 'restore-paypal-standard-for-woocommerce' ); ?></h2>
        <p><?php esc_html_e( 'If you are planning on using PayPal Standard, we recommend you do this. If not, you can ignore it.', 'restore-paypal-standard-for-woocommerce' ); ?></p>
        <p><?php esc_html_e( 'WooCommerce is phasing out its built-in PayPal Standard payment gateway and may remove it entirely in a future update.', 'restore-paypal-standard-for-woocommerce' ); ?></p>
        <p>
            <?php
            printf(
                /* translators: %s: plugin name */
                esc_html__( 'To ensure your site continues running without interruption, %s now includes its own standalone PayPal Standard integration - no longer relying on WooCommerce\'s native code.', 'restore-paypal-standard-for-woocommerce' ),
                esc_html( RPSFW_PLUGIN_NAME )
            );
            ?>
        </p>
        <p><?php esc_html_e( 'This means your PayPal Standard setup will continue to work reliably, regardless of future changes in WooCommerce.', 'restore-paypal-standard-for-woocommerce' ); ?></p>
        <p><strong><?php esc_html_e( 'If you plan on using PayPal Standard, we highly recommend switching now to avoid any disruption. With your consent, your existing PayPal Standard settings will be copied over automatically to this plugin and the native PayPal Standard Code will be disabled.', 'restore-paypal-standard-for-woocommerce' ); ?></strong></p>
        <p><?php esc_html_e( 'After clicking the button below everything should continue to work as normal with no other changes needed.', 'restore-paypal-standard-for-woocommerce' ); ?></p>
        <p>
            <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'action', 'rpsfw_migrate_paypal' ), 'rpsfw_migrate_paypal', 'rpsfw_nonce' ) ); ?>" class="button button-primary">
                <?php esc_html_e( 'Switch to Restore PayPal Standard', 'restore-paypal-standard-for-woocommerce' ); ?>
            </a>
        </p>
    </div>
    <?php
    
    // Add JavaScript to handle notice dismissal
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        $(document).on('click', '.rpsfw-migration-notice .notice-dismiss', function() {
            $.ajax({
                url: ajaxurl,
                data: {
                    action: 'rpsfw_dismiss_migration_notice',
                    nonce: '<?php echo esc_js( wp_create_nonce( 'rpsfw_dismiss_migration_notice' ) ); ?>',
                    notice_count: <?php echo esc_js( $notice_count ); ?>
                }
            });
        });
    });
    </script>
    <?php
}

/**
 * Handle AJAX request to dismiss migration notice temporarily
 */
function rpsfw_handle_dismiss_migration_notice() {
    // Verify nonce
    if ( ! isset( $_REQUEST['nonce'] ) || ! wp_verify_nonce( $_REQUEST['nonce'], 'rpsfw_dismiss_migration_notice' ) ) {
        wp_die( '', '', array( 'response' => 403 ) );
    }
    
    // Dismissing the notice once permanently hides it
    update_option( 'rpsfw_migration_notice_dismissed_permanently', 'yes' );
    
    wp_die();
}

/**
 * Handle permanent dismissal of migration notice
 */
function rpsfw_handle_permanent_dismiss_migration_notice() {
    // Check if this is our dismiss action
    if ( isset( $_GET['action'] ) && 'rpsfw_dismiss_migration_notice_permanently' === $_GET['action'] ) {
        // Verify nonce
        if ( ! isset( $_GET['rpsfw_nonce'] ) || ! wp_verify_nonce( $_GET['rpsfw_nonce'], 'rpsfw_dismiss_migration_notice_permanently' ) ) {
            wp_die( esc_html__( 'Security check failed', 'restore-paypal-standard-for-woocommerce' ) );
        }
        
        // Mark notice as permanently dismissed
        update_option( 'rpsfw_migration_notice_dismissed_permanently', 'yes' );
        
        // Redirect back to the current page without action parameters
        wp_safe_redirect( remove_query_arg( array( 'action', 'rpsfw_nonce' ) ) );
        exit;
    }
}

/**
 * Display migration success notice
 */
function rpsfw_display_migration_success_notice() {
    // Check if the success transient is set
    if ( ! get_transient( 'rpsfw_migration_success' ) ) {
        return;
    }
    
    // Display the success notice
    ?>
    <div class="notice notice-success is-dismissible">
        <p><strong><?php esc_html_e( 'PayPal Standard settings successfully migrated!', 'restore-paypal-standard-for-woocommerce' ); ?></strong></p>
        <p><?php esc_html_e( 'Your PayPal Standard settings have been migrated from WooCommerce\'s native integration to Restore PayPal Standard for WooCommerce.', 'restore-paypal-standard-for-woocommerce' ); ?></p>
        <p><?php esc_html_e( 'WooCommerce\'s native PayPal Standard gateway has been disabled, and this plugin has been enabled with your existing settings.', 'restore-paypal-standard-for-woocommerce' ); ?></p>
        <p><b><?php esc_html_e( 'If you experience any issues, please enabled the "Enabled Native PayPal Standard" option in the settings debugging tab and let us know.', 'restore-paypal-standard-for-woocommerce' ); ?></b></p>
    </div>
    <?php
    
    // Don't delete the transient here, let rpsfw_admin_notice() function use it too
}

/**
 * Handle the migration action
 */
function rpsfw_handle_migration_action() {
    // Check if this is our migration action
    if ( isset( $_GET['action'] ) && 'rpsfw_migrate_paypal' === $_GET['action'] ) {
        // Verify nonce
        if ( ! isset( $_GET['rpsfw_nonce'] ) || ! wp_verify_nonce( $_GET['rpsfw_nonce'], 'rpsfw_migrate_paypal' ) ) {
            wp_die( esc_html__( 'Security check failed', 'restore-paypal-standard-for-woocommerce' ) );
        }
        
        // Run the migration
        rpsfw_migrate_native_paypal_settings();
        
        // Redirect back to the current page without action parameters
        wp_safe_redirect( remove_query_arg( array( 'action', 'rpsfw_nonce' ) ) );
        exit;
    }
}

/**
 * Reset this plugin's PayPal Standard settings back to their default values.
 *
 * Exposed from the PayPal Standard "Debugging" settings tab. This does NOT copy
 * anything from WooCommerce's native PayPal gateway — it simply clears the
 * plugin's own settings and rewrites them with the defaults defined by the
 * settings form fields (general, text, advanced and debugging sections).
 */
function rpsfw_handle_reset_settings_action() {
    if ( ! isset( $_GET['action'] ) || 'rpsfw_reset_paypal_settings' !== $_GET['action'] ) {
        return;
    }

    // Verify nonce.
    if ( ! isset( $_GET['rpsfw_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_GET['rpsfw_nonce'] ), 'rpsfw_reset_paypal_settings' ) ) {
        wp_die( esc_html__( 'Security check failed', 'restore-paypal-standard-for-woocommerce' ) );
    }

    // Only allow store managers to trigger this.
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( esc_html__( 'You do not have permission to do this.', 'restore-paypal-standard-for-woocommerce' ) );
    }

    // Build the default settings from every settings section's form fields.
    $defaults = array();
    if ( function_exists( 'rpsfw_get_settings_for_section' ) ) {
        foreach ( array( 'general', 'text', 'advanced', 'debugging' ) as $section ) {
            $fields = rpsfw_get_settings_for_section( $section );
            if ( ! is_array( $fields ) ) {
                continue;
            }
            foreach ( $fields as $key => $field ) {
                // Skip informational fields (section headings, buttons, log
                // links) — they are not real settings.
                if ( isset( $field['type'] ) && 'title' === $field['type'] ) {
                    continue;
                }
                $defaults[ $key ] = isset( $field['default'] ) ? $field['default'] : '';
            }
        }
    }

    // Overwrite the plugin settings with the defaults (full reset).
    update_option( 'woocommerce_restore_paypal_standard_settings', $defaults );

    // Also clear the migration tracking state so the migration prompt behaves
    // as if it had never run. Combined with the settings reset (which disables
    // the gateway), the migration box will appear again the next time the
    // merchant re-enables the gateway — matching the "show the migrate box only
    // after the gateway is enabled" flow.
    delete_option( 'rpsfw_migration_completed' );
    delete_option( 'rpsfw_migration_notice_dismissed_permanently' );
    delete_option( 'rpsfw_migration_notice_dismissed_until' );
    delete_option( 'rpsfw_migration_notice_first_shown' );
    delete_option( 'rpsfw_migration_notice_count' );
    delete_transient( 'rpsfw_show_migration_notice' );

    // Flag a success message for the redirect.
    set_transient( 'rpsfw_settings_reset_success', true, 60 );

    rpsfw_debug_log( 'Restore PayPal Standard settings reset to default values.' );

    // Redirect back to the current page without action parameters.
    wp_safe_redirect( remove_query_arg( array( 'action', 'rpsfw_nonce' ) ) );
    exit;
}

/**
 * Display a notice after the settings have been reset to defaults.
 */
function rpsfw_display_settings_reset_notice() {
    if ( ! get_transient( 'rpsfw_settings_reset_success' ) ) {
        return;
    }
    delete_transient( 'rpsfw_settings_reset_success' );
    ?>
    <div class="notice notice-success is-dismissible">
        <p><strong><?php esc_html_e( 'PayPal Standard settings have been reset to their default values.', 'restore-paypal-standard-for-woocommerce' ); ?></strong></p>
    </div>
    <?php
}

/**
 * Handle showing the migration notice from the plugin action link
 */
function rpsfw_handle_show_migration_action() {
    // Check if this is our show migration action
    if ( isset( $_GET['action'] ) && 'rpsfw_show_migration' === $_GET['action'] ) {
        // Verify nonce
        if ( ! isset( $_GET['rpsfw_nonce'] ) || ! wp_verify_nonce( $_GET['rpsfw_nonce'], 'rpsfw_show_migration' ) ) {
            wp_die( esc_html__( 'Security check failed', 'restore-paypal-standard-for-woocommerce' ) );
        }
        
        // Reset dismissal options to force showing the notice
        delete_option( 'rpsfw_migration_notice_dismissed_until' );
        delete_option( 'rpsfw_migration_notice_dismissed_permanently' );
        
        // Set a transient to show the migration notice immediately
        set_transient( 'rpsfw_show_migration_notice', true, 30 );
        
        // Redirect to the settings page without the action parameter
        wp_safe_redirect( remove_query_arg( array( 'action', 'rpsfw_nonce' ) ) );
        exit;
    }
}

/**
 * Force display of migration notice from the plugin action link
 */
function rpsfw_force_display_migration_notice() {
    // Check if the show migration transient is set
    if ( get_transient( 'rpsfw_show_migration_notice' ) ) {
        // Only show to admin users
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        
        // Check if this is the first or second notice
        $notice_count = intval( get_option( 'rpsfw_migration_notice_count', 0 ) );
        
        // Display the migration notice
        rpsfw_render_migration_notice( $notice_count );
        
        // Increment the notice count for next time
        update_option( 'rpsfw_migration_notice_count', $notice_count + 1 );
        
        // Delete the transient
        delete_transient( 'rpsfw_show_migration_notice' );
    }
}

/**
 * Add plugin action link for migration
 *
 * @param array $links Array of plugin action links
 * @return array Modified array of plugin action links
 */
function rpsfw_add_migration_action_link( $links ) {
    // Hide the link if the notice has been dismissed
    if ( 'yes' === get_option( 'rpsfw_migration_notice_dismissed_permanently', 'no' ) ) {
        return $links;
    }

    // Only add the link if migration is needed
    if ( rpsfw_has_native_paypal_settings() || 'yes' !== get_option( 'rpsfw_migration_completed', 'no' ) ) {
        $migration_link = '<a href="' . esc_url( wp_nonce_url( add_query_arg( array(
            'action' => 'rpsfw_show_migration',
        ), admin_url( 'index.php' ) ), 'rpsfw_show_migration', 'rpsfw_nonce' ) ) . '" style="color: #d63638; font-weight: bold;">' . esc_html__( 'Start Migration', 'restore-paypal-standard-for-woocommerce' ) . '</a>';
        
        // Add to the beginning of the links array
        array_unshift( $links, $migration_link );
    }
    
    return $links;
}

// Register hooks
add_action( 'admin_notices', 'rpsfw_display_migration_notice' );
add_action( 'admin_notices', 'rpsfw_display_migration_success_notice' );
add_action( 'admin_init', 'rpsfw_handle_migration_action' );
add_action( 'admin_init', 'rpsfw_handle_permanent_dismiss_migration_notice' );
add_action( 'admin_init', 'rpsfw_handle_show_migration_action' );
add_action( 'admin_init', 'rpsfw_handle_reset_settings_action' );
add_action( 'admin_notices', 'rpsfw_display_settings_reset_notice' );
add_action( 'wp_ajax_rpsfw_dismiss_migration_notice', 'rpsfw_handle_dismiss_migration_notice' );
add_action( 'admin_notices', 'rpsfw_force_display_migration_notice' );
add_filter( 'plugin_action_links_restore-paypal-standard-for-woocommerce/restore-paypal-standard-for-woocommerce.php', 'rpsfw_add_migration_action_link' );