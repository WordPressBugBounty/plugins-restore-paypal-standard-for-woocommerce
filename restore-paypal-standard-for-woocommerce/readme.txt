=== Restore PayPal Standard for WooCommerce ===
Contributors: scottpaterson,wp-plugin
Tags: woocommerce, paypal, payment gateway, payment, standard
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 5.6
Stable tag: 3.1.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Re-enables the PayPal Standard payment gateway for WooCommerce.

== Description ==

Restore PayPal Standard For WooCommerce allows you to use the PayPal Standard gateway as a payment method for <a href="https://wordpress.org/plugins/woocommerce/">WooCommerce</a>.

As of version 3.0, this plugin includes PayPal Standard code, ensuring it continues to work even if WooCommerce removes it entirely in future releases.

Previously, this plugin only re-enabled the menu item while PayPal Standard code was still bundled with WooCommerce. Since WooCommerce has been gradually deprecating PayPal Standard, this plugin offers a reliable way to keep using PayPal Standard in WooCommerce it without disruption.

PayPal has confirmed that they have no current plans to discontinue support for Standard PayPal.

This plugin is created by an official PayPal Partner. This plugin is not an official WooCommerce add-on or extension and is not affiliate in any way with WooCommerce, WordPress, or Automattic Inc

== Frequently Asked Questions ==

= Does this work with the latest version of WooCommerce? =

Yes, this plugin is compatible with the latest version of WooCommerce.

= Can I use this alongside other PayPal payment gateways? =

Yes, you can use this gateway alongside other PayPal gateways like PayPal Commerce Platform or PayPal Express Checkout. Standard PayPal will will work alongside these other gateways without issue.

= Where can I get support? =

If you need support, please use the WordPress.org forums for this plugin.


== Screenshots ==
1. This screenshot shows the WooCommerce Enable Gateways settings page with the PayPal Standard Option restored!

== Changelog ==

= 3.1.0 =
* 12/16/25
* New - Added full WooCommerce Blocks support for block-based checkout
* New - Added PayPal Standard Diagnostics to WooCommerce System Status page
* Enhancement - Updated payment method display to show PayPal logo only (removed text label)
* Enhancement - Improved description formatting with better spacing for sandbox mode message
* Enhancement - Optimized icon display for both classic and block-based checkout (24px height)
* Fix - Resolved "no payment methods available" error with WooCommerce 10.4+ block-based checkout
* Fix - Improved gateway availability checks for better reliability
* Fix - Ensured proper HTML rendering in payment method descriptions for blocks checkout

= 3.0.1 =
* 9/22/25
* Fix - Fixed PHP deprecated warning that occurred with PHP 8.2+.

= 3.0 =
* 4/30/25
* New - This plugin now has built in PayPal Standard Code! This is very useful, as it seems likely that WooCommerce may eventually remove PayPal Standard from its core code in a future release.

= 1.0.6 =
* Tested: WooCommerce WordPress 6.7
* Removed: Email to admin

= 1.0.5 =
* Tested: WooCommerce 9.4.0-beta

= 1.0.4 =
* Fix: plugin not working with WooCommece v. 9.1.4

= 1.0.3 =
* Checked compatibility with High-Performance Order Storage (HPOS)

= 1.0.2 =
* Checked compatibility with WordPress 6.1 and WooCommerce 7.2.3

= 1.0.1 =
* Checked compatibility with WordPress 6.0.2 and WooCommerce 6.8.2

= 1.0.0 =
* Initial Release
