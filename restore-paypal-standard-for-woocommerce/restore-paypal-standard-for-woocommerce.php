<?php
/**
 * Plugin Name: Restore Paypal Standard For WooCommerce
 * Description: Restore PayPal Standard payment gateway for WooCommerce
 * Version: 3.0.1
 * Author: Scott Paterson
 * Author URI: https://wpplugin.org
 * Text Domain: restore-paypal-standard-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 5.6
 * WC requires at least: 6.0
 * WC tested up to: 10
 * 
 * Requires Plugins: woocommerce
 * 
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

defined( 'ABSPATH' ) || exit;

// Define plugin constants
define( 'RPSFW_VERSION', '3.0.1' );
define( 'RPSFW_PLUGIN_FILE', __FILE__ );
define( 'RPSFW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RPSFW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// PHP version check
if ( version_compare( PHP_VERSION, '5.6.0', '<' ) ) {
    add_action( 'admin_notices', 'rpsfw_php_version_notice' );
    return;
}

// Include helper functions
require_once RPSFW_PLUGIN_DIR . 'includes/functions.php';

// Include migration functionality
require_once RPSFW_PLUGIN_DIR . 'includes/admin/migration.php';

// Register all hooks
rpsfw_register_hooks();

// Register activation hook - this cannot be moved to a function
register_activation_hook( __FILE__, 'rpsfw_activation_hook' );

// Add admin notice
add_action( 'admin_notices', 'rpsfw_admin_notice', 20 );

// Check if the enable_native_paypal option is set to 'yes'
$plugin = plugin_basename( __FILE__ );
$settings = get_option('woocommerce_restore_paypal_standard_settings', array());
$enable_native_paypal = isset($settings['enable_native_paypal']) && $settings['enable_native_paypal'] === 'yes';
$migration_complete = 'yes' === get_option( 'rpsfw_migration_completed', 'no' );

// Run if native PayPal is enabled OR migration is not complete
if ($enable_native_paypal || !$migration_complete) {
  add_action( 'plugins_loaded',function(){
    // It enable PayPal Standard for WooCommerce.
    $paypal = class_exists( 'WC_Gateway_Paypal' ) ? new WC_Gateway_Paypal() : null;
    if( $paypal ) {
      $paypal->update_option( '_should_load', 'yes' );
    }
    add_filter( 'woocommerce_should_load_paypal_standard','__return_true',9999999999999 );
  } );
}