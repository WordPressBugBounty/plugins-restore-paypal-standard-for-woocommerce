<?php
/**
*Plugin Name: Restore Paypal Standard For WooCommerce
*Description: It enables PayPal Standard for WooCommerce
*Author: Jose Mortellaro
*Author URI: https://josemortellaro.com/
*Domain Path: /languages/
*Text Domain: restore-paypal-standard-for-woocommerce
*Version: 1.0.6
*WC requires at least: 6.0
*WC tested up to: 9.1.4
*Requires Plugins: woocommerce
*/

/*  This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.
This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
*/
defined( 'ABSPATH' ) || exit; // Exit if accessed directly

$plugin = untrailingslashit( plugin_basename( __FILE__ ) );

add_action( 'plugins_loaded',function(){
  // It enable PayPal Standard for WooCommerce.
  $paypal = class_exists( 'WC_Gateway_Paypal' ) ? new WC_Gateway_Paypal() : null;
  if( $paypal ) {
    $paypal->update_option( '_should_load', 'yes' );
  }
  add_filter( 'woocommerce_should_load_paypal_standard','__return_true',9999999999999 );
} );

add_filter( "plugin_action_links_$plugin",'eos_psfw_plugin_links' );
// It adds a settings link to the action links in the plugins page.
function eos_psfw_plugin_links( $links ){
  if( class_exists( 'WooCommerce' ) ){
    $settings_link = ' <a href="'.admin_url( 'admin.php?page=wc-settings&tab=checkout&section=paypal' ).'">' . esc_html__( 'Settings','restore-paypal-standard-for-woocommerce' ). '</a>';
  }
  else{
    $settings_link = ' <a style="color:red" href="#">' . esc_html__( 'WooCommerce not active!','restore-paypal-standard-for-woocommerce' ). '</a>';
  }
  array_push( $links, $settings_link );
	return $links;
}

if( is_admin() ){
  if( isset( $_GET['page'] ) && 'wc-settings' === $_GET['page'] ) { // @codingStandardsIgnoreLine. Here we just include the file for the admin only where it's needed. We don't need any nonce verification here.
    require_once untrailingslashit( dirname( __FILE__ ) ).'/admin/rpsw-admin.php';
  }
  if( wp_doing_ajax() ) {
    require_once untrailingslashit( dirname( __FILE__ ) ).'/admin/rpsw-ajax.php';
  }
}

add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );
