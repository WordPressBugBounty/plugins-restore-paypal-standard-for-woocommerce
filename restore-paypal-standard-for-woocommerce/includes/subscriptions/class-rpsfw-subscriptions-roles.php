<?php
/**
 * Subscriber role management.
 *
 * Mirrors the WooCommerce Subscriptions plugin's Roles feature: customers
 * with an active subscription get the "Subscriber Default Role"; when
 * their last active subscription ends they get the "Inactive Subscriber
 * Role". WooCommerce itself creates the customer account at checkout
 * (account creation is enforced for subscription carts by
 * RPSFW_Subscriptions_Cart); this class only adjusts the role afterwards.
 *
 * Users who can manage the store (administrators, shop managers) are
 * never re-assigned, so admins cannot lock themselves out by test-buying
 * a subscription.
 *
 * @package Restore_PayPal_Standard_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RPSFW_Subscriptions_Roles
 */
class RPSFW_Subscriptions_Roles {

	/**
	 * Statuses that make a customer an "active" subscriber. pending-cancel
	 * keeps the role: the customer has paid through the end of the period.
	 *
	 * @var array
	 */
	private static $active_statuses = array( 'active', 'pending-cancel' );

	/**
	 * Wire up. Runs whenever record servicing is active (role changes are
	 * also driven by webhook status updates).
	 */
	public static function init() {
		add_action( 'rpsfw_subscription_activated_for_order', array( __CLASS__, 'on_subscription_activated' ), 10, 2 );
		add_action( 'rpsfw_subscription_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 3 );
	}

	/**
	 * New subscription purchased.
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 * @param WC_Order           $order        Parent order.
	 */
	public static function on_subscription_activated( $subscription, $order = null ) {
		self::update_role_for_customer( $subscription->get_customer_id() );
	}

	/**
	 * Any status transition (checkout, admin action, customer action or
	 * webhook).
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 * @param string             $old_status   Old status.
	 * @param string             $new_status   New status.
	 */
	public static function on_status_changed( $subscription, $old_status, $new_status ) {
		self::update_role_for_customer( $subscription->get_customer_id() );
	}

	/**
	 * Re-evaluate and apply the correct role for a customer based on
	 * whether they have any active subscription.
	 *
	 * @param int $user_id WP user id.
	 */
	public static function update_role_for_customer( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		// Never re-assign users who can manage the store.
		if ( user_can( $user, 'manage_woocommerce' ) || user_can( $user, 'manage_options' ) ) {
			return;
		}

		$subscriber_role = rpsfw_subscriptions_get_setting( 'subscriber_role' );
		$inactive_role   = rpsfw_subscriptions_get_setting( 'inactive_subscriber_role' );

		$has_active = false;
		foreach ( rpsfw_get_subscriptions_for_user( $user_id ) as $subscription ) {
			if ( $subscription->has_status( self::$active_statuses ) ) {
				$has_active = true;
				break;
			}
		}

		$target_role = $has_active ? $subscriber_role : $inactive_role;

		// Only apply real, existing roles; a removed/invalid role is a no-op.
		if ( ! $target_role || ! get_role( $target_role ) ) {
			return;
		}

		if ( ! in_array( $target_role, (array) $user->roles, true ) ) {
			$user->set_role( $target_role );
		}
	}
}
