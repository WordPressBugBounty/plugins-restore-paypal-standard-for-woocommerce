<?php
/**
 * Diagnostic functions for troubleshooting PayPal Standard gateway issues
 *
 * @package Restore_PayPal_Standard_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add diagnostic information to WooCommerce System Status
 */
function rpsfw_add_system_status_info() {
    ?>
    <table class="wc_status_table widefat" cellspacing="0">
        <thead>
            <tr>
                <th colspan="3" data-export-label="PayPal Standard Diagnostics">
                    <h2><?php esc_html_e( 'PayPal Standard Diagnostics', 'restore-paypal-standard-for-woocommerce' ); ?></h2>
                </th>
            </tr>
        </thead>
        <tbody>
            <?php
            // Get settings
            $settings = get_option('woocommerce_restore_paypal_standard_settings', array());
            $migration_complete = get_option( 'rpsfw_migration_completed', 'no' );
            
            // Check if gateway is enabled
            $enabled = isset($settings['enabled']) ? $settings['enabled'] : 'no';
            ?>
            <tr>
                <td data-export-label="Gateway Enabled"><?php esc_html_e( 'Gateway Enabled', 'restore-paypal-standard-for-woocommerce' ); ?>:</td>
                <td class="help">&nbsp;</td>
                <td>
                    <?php echo $enabled === 'yes' ? '<mark class="yes"><span class="dashicons dashicons-yes"></span></mark>' : '<mark class="error"><span class="dashicons dashicons-warning"></span> ' . esc_html__( 'Disabled', 'restore-paypal-standard-for-woocommerce' ) . '</mark>'; ?>
                </td>
            </tr>
            <?php
            // Check currency
            $currency = get_woocommerce_currency();
            $supported_currencies = array( 'AUD', 'BRL', 'CAD', 'MXN', 'NZD', 'HKD', 'SGD', 'USD', 'EUR', 'JPY', 'TRY', 'NOK', 'CZK', 'DKK', 'HUF', 'ILS', 'MYR', 'PHP', 'PLN', 'SEK', 'CHF', 'TWD', 'THB', 'GBP', 'RMB', 'RUB', 'INR' );
            $currency_supported = in_array($currency, $supported_currencies);
            ?>
            <tr>
                <td data-export-label="Store Currency"><?php esc_html_e( 'Store Currency', 'restore-paypal-standard-for-woocommerce' ); ?>:</td>
                <td class="help">&nbsp;</td>
                <td>
                    <?php 
                    echo esc_html($currency);
                    if ($currency_supported) {
                        echo ' <mark class="yes"><span class="dashicons dashicons-yes"></span></mark>';
                    } else {
                        echo ' <mark class="error"><span class="dashicons dashicons-warning"></span> ' . esc_html__( 'Not supported by PayPal', 'restore-paypal-standard-for-woocommerce' ) . '</mark>';
                    }
                    ?>
                </td>
            </tr>
            <?php
            // Check email configuration
            $testmode = isset($settings['testmode']) && $settings['testmode'] === 'yes';
            $email = $testmode ? 
                (isset($settings['sandbox_email']) ? $settings['sandbox_email'] : '') : 
                (isset($settings['email']) ? $settings['email'] : '');
            $email_valid = is_email($email);
            ?>
            <tr>
                <td data-export-label="PayPal Email Configured"><?php esc_html_e( 'PayPal Email Configured', 'restore-paypal-standard-for-woocommerce' ); ?>:</td>
                <td class="help">&nbsp;</td>
                <td>
                    <?php 
                    if ($email_valid) {
                        echo '<mark class="yes"><span class="dashicons dashicons-yes"></span> ' . esc_html__( 'Yes', 'restore-paypal-standard-for-woocommerce' ) . '</mark>';
                    } else {
                        echo '<mark class="error"><span class="dashicons dashicons-warning"></span> ' . esc_html__( 'No', 'restore-paypal-standard-for-woocommerce' ) . '</mark>';
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <td data-export-label="Test Mode"><?php esc_html_e( 'Test Mode', 'restore-paypal-standard-for-woocommerce' ); ?>:</td>
                <td class="help">&nbsp;</td>
                <td><?php echo $testmode ? esc_html__( 'Yes (Sandbox)', 'restore-paypal-standard-for-woocommerce' ) : esc_html__( 'No (Live)', 'restore-paypal-standard-for-woocommerce' ); ?></td>
            </tr>
            <tr>
                <td data-export-label="Migration Status"><?php esc_html_e( 'Migration Status', 'restore-paypal-standard-for-woocommerce' ); ?>:</td>
                <td class="help">&nbsp;</td>
                <td><?php echo $migration_complete === 'yes' ? esc_html__( 'Complete', 'restore-paypal-standard-for-woocommerce' ) : esc_html__( 'Not Complete', 'restore-paypal-standard-for-woocommerce' ); ?></td>
            </tr>
            <?php
            // Check if gateway class exists - need to check registered gateways instead
            $all_gateways = WC()->payment_gateways->payment_gateways();
            $gateway_instance = null;
            foreach ($all_gateways as $gateway) {
                if ($gateway->id === 'restore_paypal_standard') {
                    $gateway_instance = $gateway;
                    break;
                }
            }
            $gateway_class_exists = $gateway_instance !== null;
            ?>
            <tr>
                <td data-export-label="Gateway Class"><?php esc_html_e( 'Gateway Class', 'restore-paypal-standard-for-woocommerce' ); ?>:</td>
                <td class="help">&nbsp;</td>
                <td>
                    <?php 
                    if ($gateway_class_exists) {
                        echo '<mark class="yes"><span class="dashicons dashicons-yes"></span> ' . esc_html__( 'Loaded', 'restore-paypal-standard-for-woocommerce' ) . '</mark>';
                    } else {
                        echo '<mark class="error"><span class="dashicons dashicons-warning"></span> ' . esc_html__( 'Not loaded', 'restore-paypal-standard-for-woocommerce' ) . '</mark>';
                    }
                    ?>
                </td>
            </tr>
            <?php
            // Check available payment gateways
            $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
            $gateway_available = isset($available_gateways['restore_paypal_standard']);
            ?>
            <tr>
                <td data-export-label="Gateway Available"><?php esc_html_e( 'Gateway Available at Checkout', 'restore-paypal-standard-for-woocommerce' ); ?>:</td>
                <td class="help">&nbsp;</td>
                <td>
                    <?php 
                    if ($gateway_available) {
                        echo '<mark class="yes"><span class="dashicons dashicons-yes"></span> ' . esc_html__( 'Yes', 'restore-paypal-standard-for-woocommerce' ) . '</mark>';
                    } else {
                        echo '<mark class="error"><span class="dashicons dashicons-warning"></span> ' . esc_html__( 'No - Not showing at checkout', 'restore-paypal-standard-for-woocommerce' ) . '</mark>';
                    }
                    ?>
                </td>
            </tr>
            <?php
            // List all registered payment gateways
            $all_gateways = WC()->payment_gateways->payment_gateways();
            $our_gateway_registered = false;
            foreach ($all_gateways as $gateway) {
                if ($gateway->id === 'restore_paypal_standard') {
                    $our_gateway_registered = true;
                    break;
                }
            }
            ?>
            <tr>
                <td data-export-label="Gateway Registered"><?php esc_html_e( 'Gateway Registered with WooCommerce', 'restore-paypal-standard-for-woocommerce' ); ?>:</td>
                <td class="help">&nbsp;</td>
                <td>
                    <?php 
                    if ($our_gateway_registered) {
                        echo '<mark class="yes"><span class="dashicons dashicons-yes"></span> ' . esc_html__( 'Yes', 'restore-paypal-standard-for-woocommerce' ) . '</mark>';
                    } else {
                        echo '<mark class="error"><span class="dashicons dashicons-warning"></span> ' . esc_html__( 'No - Gateway not registered', 'restore-paypal-standard-for-woocommerce' ) . '</mark>';
                    }
                    ?>
                </td>
            </tr>
            <?php
            // Additional diagnostic info if gateway is registered but not available
            if ($our_gateway_registered && !$gateway_available && $gateway_instance) {
                ?>
                <tr>
                    <td data-export-label="Gateway Enabled Setting"><?php esc_html_e( 'Gateway Enabled Setting', 'restore-paypal-standard-for-woocommerce' ); ?>:</td>
                    <td class="help">&nbsp;</td>
                    <td><?php echo esc_html($gateway_instance->enabled); ?></td>
                </tr>
                <tr>
                    <td data-export-label="Gateway Email"><?php esc_html_e( 'Gateway Email Property', 'restore-paypal-standard-for-woocommerce' ); ?>:</td>
                    <td class="help">&nbsp;</td>
                    <td><?php echo esc_html($gateway_instance->email ? $gateway_instance->email : 'Empty'); ?></td>
                </tr>
                <tr>
                    <td data-export-label="Gateway is_available() Result"><?php esc_html_e( 'Gateway is_available() Check', 'restore-paypal-standard-for-woocommerce' ); ?>:</td>
                    <td class="help">&nbsp;</td>
                    <td><?php echo $gateway_instance->is_available() ? '<mark class="yes">True</mark>' : '<mark class="error">False</mark>'; ?></td>
                </tr>
                <?php
            }
            ?>
        </tbody>
    </table>
    <?php
}
add_action( 'woocommerce_system_status_report', 'rpsfw_add_system_status_info' );
