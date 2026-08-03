<?php
/**
 * PayPal Commerce subscriptions diagnostic.
 *
 * Registers an entry under WooCommerce -> Status -> Tools that exercises
 * the three calls required for subscription support: create product,
 * create plan, create subscription. The test subscription is cancelled
 * immediately so it does not linger on the seller's account.
 *
 * Useful when a merchant reports that subscriptions are not working: the
 * tool isolates whether the issue is the relay, the merchant's PayPal
 * account, or something downstream of the API. Commented out by default
 * for the 4.0.0 release; re-enable by uncommenting the require_once line
 * in restore-paypal-standard-for-woocommerce.php.
 *
 * @package RestorePayPalStandard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_PayPal_Commerce_Diagnostic
 */
class WC_PayPal_Commerce_Diagnostic {

	/**
	 * Tool id used by WooCommerce Status -> Tools.
	 */
	const TOOL_ID = 'rpsfw_ppcp_subscription_diagnostic';

	/**
	 * Hook the WC Tools filter.
	 */
	public static function init() {
		add_filter( 'woocommerce_debug_tools', array( __CLASS__, 'register_tool' ) );
	}

	/**
	 * Register the tool entry.
	 *
	 * @param array $tools Existing tools.
	 * @return array
	 */
	public static function register_tool( $tools ) {
		$tools[ self::TOOL_ID ] = array(
			'name'     => __( 'Test PayPal subscriptions', 'restore-paypal-standard-for-woocommerce' ),
			'button'   => __( 'Run test', 'restore-paypal-standard-for-woocommerce' ),
			'desc'     => __( 'Runs three test API calls (create product, create plan, create subscription) on your connected PayPal account to confirm subscription support is configured. The test subscription is cancelled automatically.', 'restore-paypal-standard-for-woocommerce' ),
			'callback' => array( __CLASS__, 'run' ),
		);
		return $tools;
	}

	/**
	 * Tool callback. Runs the three calls and returns a formatted string
	 * for display in the WC Tools result panel.
	 *
	 * @return string
	 */
	public static function run() {
		$gateway = self::get_gateway();
		if ( ! $gateway ) {
			return __( 'PayPal Commerce gateway is not loaded.', 'restore-paypal-standard-for-woocommerce' );
		}
		if ( ! $gateway->is_connected() ) {
			return __( 'PayPal Commerce is not connected. Connect your PayPal account on the gateway settings page first.', 'restore-paypal-standard-for-woocommerce' );
		}

		$env        = $gateway->testmode ? 'sandbox' : 'live';
		$onboarding = $gateway->get_option( 'ppcp_onboarding', array() );
		$onboard    = isset( $onboarding[ $env ] ) ? $onboarding[ $env ] : array();

		$lines   = array();
		/* translators: %s: environment name (live or sandbox). */
		$lines[] = sprintf( __( 'Environment: %s', 'restore-paypal-standard-for-woocommerce' ), $env );

		// Step 1: create product.
		$product = $gateway->api->create_subscription_product( $env, $onboard, array(
			'name'        => 'RPSFW Diagnostic Product (' . wp_date( 'Y-m-d H:i:s' ) . ')',
			'description' => 'Created by the diagnostic tool. Safe to delete.',
			'type'        => 'SERVICE',
		) );
		$product_id = is_array( $product ) && ! empty( $product['product_id'] ) ? $product['product_id'] : '';
		if ( ! $product_id ) {
			$lines[] = sprintf(
				/* translators: %s: response detail */
				__( 'FAILED at step 1 (create product): %s', 'restore-paypal-standard-for-woocommerce' ),
				is_array( $product ) && ! empty( $product['message'] ) ? $product['message'] : 'no response'
			);
			$lines[] = __( 'If this fails repeatedly, contact support.', 'restore-paypal-standard-for-woocommerce' );
			return implode( "\n", $lines );
		}
		$lines[] = sprintf(
			/* translators: %s: product id */
			__( 'OK step 1: created product %s', 'restore-paypal-standard-for-woocommerce' ),
			$product_id
		);

		// Step 2: create plan.
		$plan = $gateway->api->create_subscription_plan( $env, $onboard, array(
			'product_id'     => $product_id,
			'name'           => 'RPSFW Diagnostic Plan',
			'description'    => 'Created by the diagnostic tool. Safe to delete.',
			'currency_code'  => get_woocommerce_currency() ?: 'USD',
			'amount'         => '1.00',
			'interval_unit'  => 'MONTH',
			'interval_count' => 1,
			'total_cycles'   => 0,
			'trial_amount'   => '0',
			'trial_unit'     => 'DAY',
			'trial_count'    => 0,
			'trial_cycles'   => 0,
		) );
		$plan_id = is_array( $plan ) && ! empty( $plan['plan_id'] ) ? $plan['plan_id'] : '';
		if ( ! $plan_id ) {
			$lines[] = sprintf(
				/* translators: %s: response detail */
				__( 'FAILED at step 2 (create plan): %s', 'restore-paypal-standard-for-woocommerce' ),
				is_array( $plan ) && ! empty( $plan['message'] ) ? $plan['message'] : 'no response'
			);
			return implode( "\n", $lines );
		}
		$lines[] = sprintf(
			/* translators: %s: plan id */
			__( 'OK step 2: created plan %s', 'restore-paypal-standard-for-woocommerce' ),
			$plan_id
		);

		// Step 3: create subscription.
		$sub = $gateway->api->create_subscription( $env, $onboard, array(
			'plan_id'    => $plan_id,
			'return_url' => admin_url( 'admin.php?page=wc-status&tab=tools' ),
			'cancel_url' => admin_url( 'admin.php?page=wc-status&tab=tools' ),
		) );
		$sub_id = is_array( $sub ) && ! empty( $sub['subscription_id'] ) ? $sub['subscription_id'] : '';
		if ( ! $sub_id ) {
			$lines[] = sprintf(
				/* translators: %s: response detail */
				__( 'FAILED at step 3 (create subscription): %s', 'restore-paypal-standard-for-woocommerce' ),
				is_array( $sub ) && ! empty( $sub['message'] ) ? $sub['message'] : 'no response'
			);
			return implode( "\n", $lines );
		}
		$lines[] = sprintf(
			/* translators: %s: subscription id */
			__( 'OK step 3: created subscription %s', 'restore-paypal-standard-for-woocommerce' ),
			$sub_id
		);

		// Cancel the test subscription so it does not linger on the seller account.
		$gateway->api->cancel_subscription( $env, $onboard, $sub_id, 'RPSFW diagnostic cleanup.' );
		$lines[] = __( 'Test subscription cancelled.', 'restore-paypal-standard-for-woocommerce' );
		$lines[] = '';
		$lines[] = __( 'All three calls succeeded. Subscription support is correctly configured for this connected PayPal account.', 'restore-paypal-standard-for-woocommerce' );

		return implode( "\n", $lines );
	}

	/**
	 * Get the PayPal Commerce gateway instance, or null.
	 *
	 * @return WC_Gateway_PayPal_Commerce|null
	 */
	private static function get_gateway() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways['rpsfw_paypal_commerce'] ) ? $gateways['rpsfw_paypal_commerce'] : null;
	}
}

WC_PayPal_Commerce_Diagnostic::init();
