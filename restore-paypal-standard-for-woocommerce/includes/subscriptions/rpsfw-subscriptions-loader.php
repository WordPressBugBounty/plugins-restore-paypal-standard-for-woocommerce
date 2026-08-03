<?php
/**
 * Native (built-in) subscriptions module loader.
 *
 * This module provides subscription functionality WITHOUT requiring the
 * WooCommerce Subscriptions plugin. The billing schedule is owned entirely
 * by the payment processor (PayPal Commerce or Stripe) — this plugin never
 * initiates recurring charges itself. It creates the subscription on the
 * processor at checkout, then mirrors renewals and status changes back into
 * WooCommerce via webhooks.
 *
 * Coexistence with the WooCommerce Subscriptions plugin:
 *  - When WooCommerce Subscriptions IS active, all purchase surfaces of this
 *    module (product fields, cart handling, checkout glue) step aside so the
 *    existing WCS integrations in this plugin handle everything, exactly as
 *    before. Record servicing (webhooks, admin list, My Account, emails)
 *    stays active so any subscriptions previously created by this module
 *    keep renewing and stay manageable.
 *  - When WooCommerce Subscriptions is NOT active, this module provides the
 *    full experience.
 *
 * PayPal Standard is intentionally excluded — native subscriptions are only
 * supported on PayPal Commerce and Stripe.
 *
 * @package Restore_PayPal_Standard_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

// Data model + helpers.
require_once dirname( __FILE__ ) . '/class-rpsfw-subscriptions-post-type.php';
require_once dirname( __FILE__ ) . '/class-rpsfw-subscription.php';
require_once dirname( __FILE__ ) . '/rpsfw-subscriptions-functions.php';

// Product meta + front-end display.
require_once dirname( __FILE__ ) . '/class-rpsfw-subscription-product.php';

// Cart / checkout handling.
require_once dirname( __FILE__ ) . '/class-rpsfw-subscriptions-cart.php';

// Orchestration (record creation, renewals, lifecycle actions).
require_once dirname( __FILE__ ) . '/class-rpsfw-subscriptions-manager.php';

// Gateway glue.
require_once dirname( __FILE__ ) . '/gateways/class-rpsfw-subscriptions-paypal-commerce.php';
require_once dirname( __FILE__ ) . '/gateways/class-rpsfw-subscriptions-stripe.php';

// Subscriber role management.
require_once dirname( __FILE__ ) . '/class-rpsfw-subscriptions-roles.php';

// Customer-facing My Account area.
require_once dirname( __FILE__ ) . '/class-rpsfw-subscriptions-my-account.php';

// Admin (edit screen meta boxes, settings page, hub card support).
require_once dirname( __FILE__ ) . '/class-rpsfw-subscriptions-admin.php';

// Emails.
require_once dirname( __FILE__ ) . '/emails/class-rpsfw-subscriptions-emails.php';

/**
 * Get the subscriptions module settings with defaults applied.
 *
 * @return array
 */
function rpsfw_subscriptions_get_settings() {
	// NOTE: Defaults must not call translation functions such as __(). This
	// getter runs on plugins_loaded (via the module bootstrap), which is
	// before the 'init' action, and translating here triggers WordPress's
	// "textdomain loaded too early" notice (WP 6.7+). Custom button texts
	// are translated at their point of display instead.
	//
	// The button texts default to EMPTY, which keeps WooCommerce's native
	// labels ("Add to cart" / "Place order"); merchants can override them.
	$defaults = array(
		'enabled'                  => 'no',
		'add_to_cart_button_text'  => '',
		'place_order_button_text'  => '',
		'mixed_checkout'           => 'yes',
		'customer_can_cancel'      => 'yes',
		'customer_can_suspend'     => 'yes',
		'subscriber_role'          => 'subscriber',
		'inactive_subscriber_role' => 'customer',
	);

	$settings = get_option( 'rpsfw_subscriptions_settings', array() );
	if ( ! is_array( $settings ) ) {
		$settings = array();
	}

	return wp_parse_args( $settings, $defaults );
}

/**
 * Get a single subscriptions module setting.
 *
 * @param string $key Setting key.
 * @return mixed
 */
function rpsfw_subscriptions_get_setting( $key ) {
	$settings = rpsfw_subscriptions_get_settings();
	return isset( $settings[ $key ] ) ? $settings[ $key ] : '';
}

/**
 * Whether the native subscriptions module is enabled in settings.
 *
 * @return bool
 */
function rpsfw_native_subscriptions_enabled() {
	return 'yes' === rpsfw_subscriptions_get_setting( 'enabled' );
}

/**
 * Whether the WooCommerce Subscriptions plugin is active.
 *
 * Evaluated lazily (at call time) because plugin load order is not
 * guaranteed at file-include time.
 *
 * @return bool
 */
function rpsfw_wcs_plugin_active() {
	return class_exists( 'WC_Subscriptions' ) || function_exists( 'wcs_is_subscription' );
}

/**
 * Whether the native subscriptions PURCHASE surfaces are active.
 *
 * True only when the module is enabled AND the WooCommerce Subscriptions
 * plugin is not active. When WCS is active it owns new subscription
 * purchases and this module only services its own existing records.
 *
 * @return bool
 */
function rpsfw_native_subscriptions_active() {
	return rpsfw_native_subscriptions_enabled() && ! rpsfw_wcs_plugin_active();
}

/**
 * Whether any native subscription records exist on this site.
 *
 * Cheap flag (option) set when the first record is created, used to keep
 * record-servicing surfaces (admin list, My Account) available even after
 * the module is disabled or WCS is installed.
 *
 * @return bool
 */
function rpsfw_native_subscriptions_exist() {
	return 'yes' === get_option( 'rpsfw_subscriptions_exist', 'no' );
}

/**
 * Bootstrap the module. Runs on plugins_loaded (after WooCommerce) so the
 * WCS-presence check is reliable.
 */
function rpsfw_subscriptions_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	// When the WooCommerce Subscriptions plugin is active it owns ALL
	// subscription functionality — this plugin's PayPal Commerce and Stripe
	// gateways integrate with WCS directly. The built-in module is then turned
	// 100% OFF: no custom post type or admin menu, no webhook listening, no
	// admin screens, no My Account surfaces, no emails, no role management, no
	// purchase surfaces. The only thing that stays reachable is the read-only
	// settings page (admin only) — which is NOT a menu item, just a page the
	// settings hub links to that explains why it's disabled. Nothing runs.
	if ( rpsfw_wcs_plugin_active() ) {
		if ( is_admin() ) {
			RPSFW_Subscriptions_Admin::init_settings_only();
		}
		return;
	}

	// WooCommerce Subscriptions is NOT active below.
	//
	// Everything is gated on the "Enable built-in subscription functionality"
	// setting, same as how the WooCommerce Subscriptions plugin itself works:
	// it's either active (post type + admin UI + engine) or off (nothing loads,
	// full stop, even though its DB rows remain). Existing records are NOT a
	// reason to keep it on — toggling the setting off fully turns it off.
	// (With WCS inactive, "enabled" and "active" are equivalent here, so the
	// purchase surfaces load in the same block.)
	if ( rpsfw_native_subscriptions_enabled() ) {
		// Data model + native list table.
		RPSFW_Subscriptions_Post_Type::init();

		// Record servicing: webhooks, lifecycle propagation, admin screens, emails.
		RPSFW_Subscriptions_PayPal_Commerce::init_webhooks();
		RPSFW_Subscriptions_Stripe::init_webhooks();
		RPSFW_Subscriptions_My_Account::init();
		RPSFW_Subscriptions_Admin::init();
		RPSFW_Subscriptions_Emails::init();
		RPSFW_Subscriptions_Roles::init();

		// Purchase surfaces.
		RPSFW_Subscription_Product::init();
		RPSFW_Subscriptions_Cart::init();
		RPSFW_Subscriptions_PayPal_Commerce::init_checkout();
		RPSFW_Subscriptions_Stripe::init_checkout();
	} elseif ( is_admin() ) {
		// Settings page stays reachable so the module can be re-enabled.
		RPSFW_Subscriptions_Admin::init_settings_only();
	}
}
add_action( 'plugins_loaded', 'rpsfw_subscriptions_init', 25 );
