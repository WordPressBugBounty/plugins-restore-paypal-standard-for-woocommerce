<?php
/**
 * Plugin Name: Accept PayPal & Stripe with Subscriptions for WooCommerce
 * Description: Accept PayPal and Stripe payments in WooCommerce. Includes built-in subscriptions and is also compatible with the WooCommerce Subscriptions plugin.
 * Version: 4.0.0
 * Author: Scott Paterson
 * Author URI: https://wpplugin.org
 * Plugin URI: https://wpplugin.org
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

/**
 * Feature / gateway support matrix support for latest version
 * ================================
 *
 * This plugin bundles three payment gateways. The table below summarizes which
 * features each one supports. ("Yes" = supported, "No" = not supported.)
 *
 * +-------------------------------------------+-----------------+-----------------+--------------------------+
 * | Feature                                   | PayPal Standard | PayPal Commerce | Stripe                   |
 * +-------------------------------------------+-----------------+-----------------+--------------------------+
 * | Gateway ID                                | restore_paypal_ | rpsfw_paypal_   | rpsfw_stripe             |
 * |                                           | standard        | commerce        |                          |
 * | Classic (shortcode) checkout              | Yes             | Yes             | Yes                      |
 * | Block checkout                            | Yes             | Yes             | Yes                      |
 * | One-time payments                         | Yes             | Yes             | Yes                      |
 * | Refunds (from WooCommerce)                | Yes             | Yes             | Yes                      |
 * | Built-in subscriptions (no WCS plugin)    | No              | Yes             | Yes (card + Link)        |
 * | WooCommerce Subscriptions plugin          | No              | Yes             | Yes (card + Link)        |
 * | Multiple subscriptions in one cart        | No              | No              | Yes                      |
 * | Authorize now, capture later              | No              | Yes             | Yes                      |
 * | PayPal / Venmo buttons                    | No              | Yes             | No                       |
 * | PayPal Pay Later + on-site messaging      | No              | Yes             | No                       |
 * | Apple Pay / Google Pay (Express Checkout) | No              | No              | No (planned; code gated) |
 * | Stripe Link ("save for faster checkout")  | No              | No              | Yes                      |
 * | ACH direct debit (US bank, USD)           | No              | No              | No (planned; code gated) |
 * +-------------------------------------------+-----------------+-----------------+--------------------------+
 *
 * Notes:
 *  - Built-in subscriptions (includes/subscriptions/) provide recurring
 *    billing WITHOUT the WooCommerce Subscriptions plugin. The subscription
 *    lives at the processor (PayPal Billing Plans / Stripe Subscriptions),
 *    which initiates every renewal charge; webhooks mirror payments and
 *    status changes back into WooCommerce. When the WooCommerce
 *    Subscriptions plugin is active, the built-in engine's purchase
 *    surfaces step aside automatically and the WCS integrations take over.
 *  - Apple Pay / Google Pay use Stripe's Express Checkout Element (all modern
 *    browsers) and are shown only on carts that do not require shipping, and
 *    never on subscription carts.
 *  - ACH and the wallet express buttons are one-time only; subscriptions are
 *    charged with card or Link.
 *  - "Authorize now, capture later" is driven by each gateway's payment-action
 *    setting and a manual "capture" order action.
 */

defined( 'ABSPATH' ) || exit;

// Define plugin constants
define( 'RPSFW_PLUGIN_NAME', 'Accept PayPal & Stripe with Subscriptions for WooCommerce' );
define( 'RPSFW_VERSION', '4.0.0' );
define( 'RPSFW_PLUGIN_FILE', __FILE__ );
define( 'RPSFW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RPSFW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'RPSFW_PPCP_API_ENDPOINT', 'https://wpplugin.org/ppcp-rpsfw/' );
define( 'RPSFW_STRIPE_CONNECT_ENDPOINT', 'https://wpplugin.org/stripe-rpsfw/connect.php' );

// PHP version check
if ( version_compare( PHP_VERSION, '5.6.0', '<' ) ) {
    add_action( 'admin_notices', 'rpsfw_php_version_notice' );
    return;
}

// Include helper functions
require_once RPSFW_PLUGIN_DIR . 'includes/functions.php';

// Include migration functionality
require_once RPSFW_PLUGIN_DIR . 'includes/admin/migration.php';

// Include admin hub page
require_once RPSFW_PLUGIN_DIR . 'includes/admin/admin-hub.php';

// Include the always-on "Gateway" column on the orders list table (independent
// of the built-in subscriptions module).
require_once RPSFW_PLUGIN_DIR . 'includes/admin/order-list-columns.php';

// Include admin gateway deep-links meta box (PayPal/Stripe dashboard
// links on order + subscription edit pages).
require_once RPSFW_PLUGIN_DIR . 'includes/admin/class-gateway-deep-links.php';

// Include the support contact widget shown on this plugin's admin screens.
require_once RPSFW_PLUGIN_DIR . 'includes/admin/class-rpsfw-support-widget.php';

// Include diagnostics
require_once RPSFW_PLUGIN_DIR . 'includes/paypal-standard/diagnostics.php';

// Include blocks support
require_once RPSFW_PLUGIN_DIR . 'includes/blocks-support.php';

// Include the native (built-in) subscriptions module. Provides
// subscription functionality via PayPal Commerce and Stripe without the
// WooCommerce Subscriptions plugin; steps aside automatically when that
// plugin is active.
require_once RPSFW_PLUGIN_DIR . 'includes/subscriptions/rpsfw-subscriptions-loader.php';

// Include PayPal Commerce AJAX handlers
require_once RPSFW_PLUGIN_DIR . 'includes/paypal-commerce/ajax-handlers.php';

// Include PayPal Commerce Cart Buttons
require_once RPSFW_PLUGIN_DIR . 'includes/paypal-commerce/class-paypal-commerce-cart-buttons.php';

// Include PayPal Commerce Pay Later Messaging
require_once RPSFW_PLUGIN_DIR . 'includes/paypal-commerce/class-paypal-commerce-pay-later-messaging.php';

// Include PayPal Commerce subscription diagnostic admin tool.
// Disabled by default for the 4.0.0 release. Re-enable by uncommenting
// the line below to expose the test under WooCommerce -> Status -> Tools.
// require_once RPSFW_PLUGIN_DIR . 'includes/paypal-commerce/class-paypal-commerce-diagnostic.php';

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
  add_action( 'init',function(){
    // It enable PayPal Standard for WooCommerce.
    $paypal = class_exists( 'WC_Gateway_Paypal' ) ? new WC_Gateway_Paypal() : null;
    if( $paypal ) {
      $paypal->update_option( '_should_load', 'yes' );
    }
    add_filter( 'woocommerce_should_load_paypal_standard','__return_true',9999999999999 );
  } );
} else {
  // Ensure native PayPal is disabled after migration
  add_action( 'init', function() {
    if (class_exists( 'WC_Gateway_Paypal' )) {
      $paypal = new WC_Gateway_Paypal();
      $paypal->update_option( '_should_load', 'no' );
    }
  }, 5 );
}

