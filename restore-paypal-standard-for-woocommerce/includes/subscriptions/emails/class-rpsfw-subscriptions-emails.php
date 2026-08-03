<?php
/**
 * Email registrar for native subscriptions.
 *
 * Registers the subscription notification emails with WooCommerce's
 * mailer, and tells WooCommerce which of this module's actions should
 * initialize the mailer (transactional email bridging).
 *
 * Renewal receipts intentionally ride on WooCommerce's standard order
 * emails — every renewal creates a real order and marks it paid, which
 * triggers the normal "order processing/completed" emails.
 *
 * @package Restore_PayPal_Standard_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RPSFW_Subscriptions_Emails
 */
class RPSFW_Subscriptions_Emails {

	/**
	 * Wire up.
	 */
	public static function init() {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register_email_classes' ) );
		add_filter( 'woocommerce_email_actions', array( __CLASS__, 'register_email_actions' ) );
	}

	/**
	 * Register our WC_Email subclasses. Runs when WooCommerce builds its
	 * mailer, so WC_Email is guaranteed to exist here.
	 *
	 * @param array $emails Email classes keyed by id.
	 * @return array
	 */
	public static function register_email_classes( $emails ) {
		require_once dirname( __FILE__ ) . '/class-rpsfw-subscription-email-classes.php';

		$emails['RPSFW_Email_New_Subscription']                = new RPSFW_Email_New_Subscription();
		$emails['RPSFW_Email_Cancelled_Subscription']          = new RPSFW_Email_Cancelled_Subscription();
		$emails['RPSFW_Email_Customer_Subscription_Cancelled'] = new RPSFW_Email_Customer_Subscription_Cancelled();
		$emails['RPSFW_Email_Customer_Payment_Failed']         = new RPSFW_Email_Customer_Payment_Failed();
		$emails['RPSFW_Email_Customer_Subscription_Expired']   = new RPSFW_Email_Customer_Subscription_Expired();

		return $emails;
	}

	/**
	 * Actions that should initialize the mailer so the email classes can
	 * hook their {action}_notification triggers.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public static function register_email_actions( $actions ) {
		$actions[] = 'rpsfw_subscription_activated_for_order';
		$actions[] = 'rpsfw_subscription_status_cancelled';
		$actions[] = 'rpsfw_subscription_status_expired';
		$actions[] = 'rpsfw_subscription_payment_failed';
		return $actions;
	}
}
