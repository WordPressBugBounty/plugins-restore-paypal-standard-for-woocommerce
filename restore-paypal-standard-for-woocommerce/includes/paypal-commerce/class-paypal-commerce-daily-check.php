<?php
/**
 * PayPal Commerce daily check.
 *
 * @package RestorePayPalStandard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_PayPal_Commerce_Daily_Check
 */
class WC_PayPal_Commerce_Daily_Check {

	const HOOK = 'rpsfw_ppcp_daily_check';

	const HOOK_CONTINUE = 'rpsfw_ppcp_daily_check_continue';

	const GROUP = 'rpsfw';

	const META_CHECKED = '_rpsfw_ppcp_checked_at';

	const META_READ_TO = '_rpsfw_ppcp_read_to';

	const META_FIRST_PAYMENT = '_rpsfw_ppcp_last_payment_id';

	const BATCH = 100;

	const TIME_BUDGET = 60;

	const RECHECK_AFTER = 72000;

	const LOOK_BACK = 864000;

	const WINDOW = 15552000;

	const MIN_AGE = 3600;

	const QUIET_AFTER = 172800;

	/**
	 * @var string[]
	 */
	private static $taken = array( 'COMPLETED', 'PARTIALLY_REFUNDED', 'REFUNDED' );

	/**
	 * @var array
	 */
	private static $statuses = array(
		'CANCELLED' => array( 'BILLING.SUBSCRIPTION.CANCELLED', 'cancelled', array( 'active', 'on-hold', 'pending' ) ),
		'SUSPENDED' => array( 'BILLING.SUBSCRIPTION.SUSPENDED', 'on-hold', array( 'active', 'pending' ) ),
		'EXPIRED'   => array( 'BILLING.SUBSCRIPTION.EXPIRED', 'expired', array( 'active', 'on-hold', 'pending' ) ),
	);

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::HOOK_CONTINUE, array( __CLASS__, 'run' ) );
		add_action( 'action_scheduler_init', array( __CLASS__, 'maybe_schedule' ) );
	}

	/**
	 * Schedule the daily check.
	 */
	public static function maybe_schedule() {
		if ( get_transient( 'rpsfw_ppcp_daily_check_scheduled' ) ) {
			return;
		}
		if ( ! self::is_connected() ) {
			set_transient( 'rpsfw_ppcp_daily_check_scheduled', 1, HOUR_IN_SECONDS );
			return;
		}
		set_transient( 'rpsfw_ppcp_daily_check_scheduled', 1, DAY_IN_SECONDS );

		if ( function_exists( 'as_next_scheduled_action' ) && false === as_next_scheduled_action( self::HOOK ) ) {
			as_schedule_recurring_action( time() + wp_rand( 5 * MINUTE_IN_SECONDS, 6 * HOUR_IN_SECONDS ), DAY_IN_SECONDS, self::HOOK, array(), self::GROUP );
		}
	}

	/**
	 * Unschedule the daily check.
	 */
	public static function unschedule() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK );
			as_unschedule_all_actions( self::HOOK_CONTINUE );
		}
		delete_transient( 'rpsfw_ppcp_daily_check_scheduled' );
	}

	/**
	 * Run the daily check.
	 */
	public static function run() {
		if ( get_transient( 'rpsfw_ppcp_daily_check_running' ) ) {
			return;
		}
		set_transient( 'rpsfw_ppcp_daily_check_running', 1, 10 * MINUTE_IN_SECONDS );

		try {
			$more = self::check_due_subscriptions();
		} finally {
			delete_transient( 'rpsfw_ppcp_daily_check_running' );
		}

		if ( $more && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::HOOK_CONTINUE, array(), self::GROUP );
		}
	}

	/**
	 * Check due subscriptions.
	 *
	 * @return bool
	 */
	private static function check_due_subscriptions() {
		$gateway = self::get_gateway();
		if ( ! $gateway ) {
			return false;
		}
		$onboarding = $gateway->get_option( 'ppcp_onboarding', array() );
		if ( ! is_array( $onboarding ) ) {
			return false;
		}

		self::protect_store( $gateway, $onboarding );

		list( $due, $more ) = self::get_due_subscriptions();

		$started = time();
		$counts  = array(
			'checked'  => 0,
			'recorded' => 0,
			'changed'  => 0,
			'failed'   => 0,
		);
		foreach ( $due as $subscription ) {
			if ( time() - $started > self::TIME_BUDGET ) {
				$more = true;
				break;
			}
			try {
				self::check_subscription( $gateway, $onboarding, $subscription, $counts );
			} catch ( Exception $e ) {
				$counts['failed']++;
				WC_Gateway_PayPal_Commerce::log( 'Daily check: subscription #' . $subscription->get_id() . ' failed: ' . $e->getMessage(), 'error' );
			} catch ( Throwable $e ) { // phpcs:ignore PHPCompatibility.Classes.NewClasses.throwableFound
				$counts['failed']++;
				WC_Gateway_PayPal_Commerce::log( 'Daily check: subscription #' . $subscription->get_id() . ' failed: ' . $e->getMessage(), 'error' );
			}
		}

		if ( $counts['checked'] || $counts['failed'] ) {
			WC_Gateway_PayPal_Commerce::log( sprintf(
				'Daily check: %d PayPal subscriptions checked, %d missed payments recorded, %d statuses updated from PayPal, %d could not be checked.',
				$counts['checked'],
				$counts['recorded'],
				$counts['changed'],
				$counts['failed']
			) );
		}

		return $more;
	}

	/**
	 * Register the store.
	 *
	 * @param WC_Gateway_PayPal_Commerce $gateway    Gateway.
	 * @param array                      $onboarding Onboarding data.
	 */
	private static function protect_store( $gateway, $onboarding ) {
		$done = get_option( 'rpsfw_ppcp_store_protected', array() );
		if ( ! is_array( $done ) ) {
			$done = array();
		}
		$changed = false;
		foreach ( array( 'live', 'sandbox' ) as $env ) {
			if ( empty( $onboarding[ $env ]['seller_id'] ) || empty( $onboarding[ $env ]['tracking_id'] ) ) {
				continue;
			}
			$seller_id = (string) $onboarding[ $env ]['seller_id'];
			if ( isset( $done[ $env ]['seller_id'], $done[ $env ]['at'] ) && $done[ $env ]['seller_id'] === $seller_id
				&& $done[ $env ]['at'] > time() - 30 * DAY_IN_SECONDS ) {
				continue;
			}
			if ( $gateway->api->protect_store( $env, $onboarding[ $env ] ) ) {
				$done[ $env ] = array(
					'seller_id' => $seller_id,
					'at'        => time(),
				);
				$changed      = true;
			}
		}
		if ( $changed ) {
			update_option( 'rpsfw_ppcp_store_protected', $done, false );
		}
	}

	/**
	 * Due subscriptions.
	 *
	 * @return array
	 */
	private static function get_due_subscriptions() {
		$not_checked = array(
			'relation' => 'OR',
			array(
				'key'     => self::META_CHECKED,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => self::META_CHECKED,
				'value'   => time() - self::RECHECK_AFTER,
				'compare' => '<',
				'type'    => 'NUMERIC',
			),
		);
		$due  = array();
		$more = false;

		if ( function_exists( 'wcs_get_subscription' ) && class_exists( 'WC_PayPal_Commerce_Subscriptions' ) ) {
			$ids = wc_get_orders(
				array(
					'type'           => 'shop_subscription',
					'status'         => array( 'wc-active', 'wc-on-hold', 'wc-pending-cancel', 'wc-pending' ),
					'payment_method' => 'rpsfw_paypal_commerce',
					'meta_query'     => $not_checked, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'limit'          => self::BATCH,
					'return'         => 'ids',
				)
			);
			$more = count( $ids ) >= self::BATCH;
			foreach ( $ids as $id ) {
				$subscription = wcs_get_subscription( $id );
				if ( $subscription ) {
					$due[] = $subscription;
				}
			}
		}

		if ( class_exists( 'RPSFW_Subscriptions_Post_Type' ) && function_exists( 'rpsfw_get_subscription' )
			&& false !== has_action( 'rpsfw_ppcp_webhook_event', array( 'RPSFW_Subscriptions_PayPal_Commerce', 'handle_webhook_event' ) ) ) {
			$ids = get_posts(
				array(
					'post_type'      => RPSFW_Subscriptions_Post_Type::POST_TYPE,
					'post_status'    => array( 'rpsfw-active', 'rpsfw-on-hold', 'rpsfw-pending-cancel', 'rpsfw-pending' ),
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'AND',
						array(
							'key'   => '_rpsfw_gateway',
							'value' => 'rpsfw_paypal_commerce',
						),
						$not_checked,
					),
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'posts_per_page' => self::BATCH,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			$more = $more || count( $ids ) >= self::BATCH;
			foreach ( $ids as $id ) {
				$subscription = rpsfw_get_subscription( $id );
				if ( $subscription ) {
					$due[] = $subscription;
				}
			}
		}

		return array( $due, $more );
	}

	/**
	 * Check one subscription.
	 *
	 * @param WC_Gateway_PayPal_Commerce          $gateway      Gateway.
	 * @param array                               $onboarding   Onboarding data.
	 * @param WC_Subscription|RPSFW_Subscription $subscription Subscription.
	 * @param array                               $counts       Run totals.
	 */
	private static function check_subscription( $gateway, $onboarding, $subscription, &$counts ) {
		$is_wcs = ! ( $subscription instanceof RPSFW_Subscription );
		$now    = time();

		self::set_meta( $subscription, self::META_CHECKED, $now );

		$paypal_id = $is_wcs
			? (string) $subscription->get_meta( WC_PayPal_Commerce_Subscriptions::META_SUBSCRIPTION_ID )
			: (string) $subscription->get_gateway_sub_id();
		if ( '' === $paypal_id ) {
			return;
		}

		$parent = $is_wcs ? $subscription->get_parent() : $subscription->get_parent_order();
		$mode   = $parent ? rpsfw_get_order_payment_mode( $parent ) : rpsfw_get_gateway_mode( 'rpsfw_paypal_commerce' );
		$env    = rpsfw_payment_mode_to_ppcp_env( $mode );
		if ( empty( $onboarding[ $env ]['seller_id'] ) ) {
			return;
		}
		$onboard = $onboarding[ $env ];
		$counts['checked']++;

		$details = $gateway->api->get_subscription( $env, $onboard, $paypal_id );
		if ( ! is_array( $details ) || ! isset( $details['id'] ) || (string) $details['id'] !== $paypal_id || empty( $details['status'] ) ) {
			$counts['failed']++;
			WC_Gateway_PayPal_Commerce::log( 'Daily check: PayPal subscription ' . $paypal_id . ' could not be read: ' . ( is_array( $details ) && ! empty( $details['message'] ) ? $details['message'] : 'no answer' ), 'warning' );
			return;
		}

		$read_to = (int) self::get_meta( $subscription, self::META_READ_TO );
		if ( $read_to > 0 ) {
			$from = $read_to - self::LOOK_BACK;
		} else {
			$start = self::get_start_time( $subscription, $parent, $is_wcs );
			$from  = ( $start ? $start : $now - YEAR_IN_SECONDS ) - 2 * DAY_IN_SECONDS;
		}

		$payments = array();
		for ( $window_start = $from; $window_start < $now; $window_start += self::WINDOW ) {
			$list = $gateway->api->list_subscription_transactions( $env, $onboard, $paypal_id, self::iso( $window_start ), self::iso( min( $now, $window_start + self::WINDOW ) ) );
			if ( false === $list ) {
				$counts['failed']++;
				return;
			}
			foreach ( $list as $txn ) {
				if ( is_array( $txn ) && ! empty( $txn['id'] ) ) {
					$payments[ (string) $txn['id'] ] = $txn;
				}
			}
		}

		$missed = array();
		foreach ( $payments as $txn ) {
			$time = ! empty( $txn['time'] ) ? strtotime( $txn['time'] ) : false;
			if ( ! $time || $time > $now - self::MIN_AGE ) {
				continue;
			}
			$status = isset( $txn['status'] ) ? strtoupper( (string) $txn['status'] ) : '';
			if ( ! in_array( $status, self::$taken, true ) || self::is_recorded( (string) $txn['id'] ) ) {
				continue;
			}
			$txn['status']    = $status;
			$txn['timestamp'] = $time;
			$missed[]         = $txn;
		}
		usort( $missed, array( __CLASS__, 'by_time' ) );

		if ( $missed ) {
			rpsfw_current_webhook_mode( $mode );
			try {
				$newest = count( $missed ) - 1;
				foreach ( $missed as $i => $txn ) {
					$resource = array(
						'id'                   => (string) $txn['id'],
						'state'                => 'completed',
						'billing_agreement_id' => $paypal_id,
						'create_time'          => $txn['time'],
					);
					if ( isset( $txn['amount'] ) && '' !== (string) $txn['amount'] ) {
						$resource['amount'] = array(
							'total'    => (string) $txn['amount'],
							'currency' => isset( $txn['currency'] ) ? (string) $txn['currency'] : '',
						);
					}
					if ( $i === $newest && 'ACTIVE' === $details['status'] && ! empty( $details['billing_info']['next_billing_time'] ) ) {
						$resource['next_billing_time'] = $details['billing_info']['next_billing_time'];
					}
					if ( self::record_payment( $resource, $txn, $now ) ) {
						$counts['recorded']++;
					}
				}
			} finally {
				rpsfw_current_webhook_mode( '' );
			}
		}

		if ( self::follow_status( $subscription, $is_wcs, $details, $mode, $now ) ) {
			$counts['changed']++;
		}

		self::set_meta( $subscription, self::META_READ_TO, $now );
	}

	/**
	 * Record one payment.
	 *
	 * @param array $resource Sale resource.
	 * @param array $txn      Transaction.
	 * @param int   $now      Time of this check.
	 * @return bool
	 */
	private static function record_payment( $resource, $txn, $now ) {
		$quiet = $txn['timestamp'] < $now - self::QUIET_AFTER;
		$order = false;

		if ( $quiet ) {
			self::mute_emails();
		}
		try {
			self::fire( 'PAYMENT.SALE.COMPLETED', $resource );

			$orders = wc_get_orders(
				array(
					'transaction_id' => $resource['id'],
					'limit'          => 1,
				)
			);
			$order  = $orders ? $orders[0] : false;
			if ( $order ) {
				self::finish_order( $order, $txn, $quiet );
			}
		} finally {
			if ( $quiet ) {
				self::unmute_emails();
			}
		}

		if ( $order ) {
			WC_Gateway_PayPal_Commerce::log( 'Daily check: PayPal payment ' . $resource['id'] . ' on ' . $resource['billing_agreement_id'] . ' had no order; recorded as order #' . $order->get_id() . '.' );
		}
		return (bool) $order;
	}

	/**
	 * Update the order made for a payment.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $txn   Transaction.
	 * @param bool     $quiet Whether the payment is old.
	 */
	private static function finish_order( $order, $txn, $quiet ) {
		if ( $quiet ) {
			$order->set_date_created( $txn['timestamp'] );
			$order->set_date_paid( $txn['timestamp'] );
			$order->save();
			$order->add_order_note(
				sprintf(
					/* translators: %s: date PayPal took the payment */
					__( 'Recorded by the daily PayPal check. PayPal took this payment on %s, but its notice never reached this store, so the order is dated then and no emails were sent.', 'restore-paypal-standard-for-woocommerce' ),
					wc_format_datetime( $order->get_date_paid() )
				)
			);
		} else {
			$order->add_order_note( __( 'Recorded by the daily PayPal check: PayPal took this payment, but its notice never reached this store.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		if ( 'REFUNDED' === $txn['status'] ) {
			$amount = round( (float) $order->get_total() - (float) $order->get_total_refunded(), wc_get_price_decimals() );
			if ( $amount > 0 ) {
				$refund = wc_create_refund(
					array(
						'amount'         => $amount,
						'reason'         => __( 'Refunded in PayPal (found by the daily PayPal check).', 'restore-paypal-standard-for-woocommerce' ),
						'order_id'       => $order->get_id(),
						'refund_payment' => false,
						'restock_items'  => false,
					)
				);
				if ( is_wp_error( $refund ) ) {
					$order->add_order_note( __( 'PayPal shows this payment as refunded, but the refund could not be recorded here. Please check it in PayPal.', 'restore-paypal-standard-for-woocommerce' ) );
				}
			}
		} elseif ( 'PARTIALLY_REFUNDED' === $txn['status'] ) {
			$order->add_order_note( __( 'PayPal shows part of this payment as refunded. Please check it in PayPal and record the refund here.', 'restore-paypal-standard-for-woocommerce' ) );
		}
	}

	/**
	 * Apply PayPal's status.
	 *
	 * @param WC_Subscription|RPSFW_Subscription $subscription Subscription.
	 * @param bool                                $is_wcs       Whether it is a WooCommerce Subscriptions one.
	 * @param array                               $details      PayPal subscription.
	 * @param string                              $mode         Payment mode.
	 * @param int                                 $now          Time of this check.
	 * @return bool
	 */
	private static function follow_status( $subscription, $is_wcs, $details, $mode, $now ) {
		$paypal_status = strtoupper( (string) $details['status'] );
		if ( ! isset( self::$statuses[ $paypal_status ] ) ) {
			return false;
		}
		list( $event_type, $target, $from ) = self::$statuses[ $paypal_status ];

		$subscription = self::reload( $subscription, $is_wcs );
		if ( ! $subscription || ! $subscription->has_status( $from ) ) {
			return false;
		}
		if ( $is_wcs && ! $subscription->can_be_updated_to( $target ) ) {
			return false;
		}

		$changed_at = ! empty( $details['status_update_time'] ) ? strtotime( $details['status_update_time'] ) : false;
		$quiet      = $changed_at && $changed_at < $now - self::QUIET_AFTER;

		rpsfw_current_webhook_mode( $mode );
		if ( $quiet ) {
			self::mute_emails();
		}
		try {
			self::fire( $event_type, $details );
		} finally {
			if ( $quiet ) {
				self::unmute_emails();
			}
			rpsfw_current_webhook_mode( '' );
		}

		$subscription = self::reload( $subscription, $is_wcs );
		if ( ! $subscription || ! $subscription->has_status( $target ) ) {
			return false;
		}

		$notes = array(
			'CANCELLED' => __( 'Found by the daily PayPal check: this subscription was cancelled in PayPal, but the notice never reached this store.', 'restore-paypal-standard-for-woocommerce' ),
			'SUSPENDED' => __( 'Found by the daily PayPal check: this subscription was suspended in PayPal, but the notice never reached this store.', 'restore-paypal-standard-for-woocommerce' ),
			'EXPIRED'   => __( 'Found by the daily PayPal check: this subscription ended in PayPal (all payments collected), but the notice never reached this store.', 'restore-paypal-standard-for-woocommerce' ),
		);
		if ( $is_wcs ) {
			$subscription->add_order_note( $notes[ $paypal_status ] );
		} else {
			$subscription->add_note( $notes[ $paypal_status ] );
		}
		WC_Gateway_PayPal_Commerce::log( 'Daily check: subscription #' . $subscription->get_id() . ' is ' . $paypal_status . ' in PayPal; now ' . $target . ' here.' );
		return true;
	}

	/**
	 * Run the webhook event handlers.
	 *
	 * @param string $event_type Event type.
	 * @param array  $resource   Resource.
	 */
	private static function fire( $event_type, $resource ) {
		try {
			do_action(
				'rpsfw_ppcp_webhook_event',
				$event_type,
				$resource,
				array(
					'event_type' => $event_type,
					'resource'   => $resource,
				)
			);
		} catch ( Exception $e ) {
			WC_Gateway_PayPal_Commerce::log( 'Daily check: ' . $event_type . ' handling failed: ' . $e->getMessage(), 'error' );
		}
	}

	/**
	 * Whether a payment is already recorded.
	 *
	 * @param string $txn_id Transaction id.
	 * @return bool
	 */
	private static function is_recorded( $txn_id ) {
		$statuses = array_merge( array_keys( wc_get_order_statuses() ), array( 'trash' ) );
		$found    = wc_get_orders(
			array(
				'transaction_id' => $txn_id,
				'status'         => $statuses,
				'limit'          => 1,
				'return'         => 'ids',
			)
		);
		if ( empty( $found ) ) {
			$found = wc_get_orders(
				array(
					'meta_key'   => self::META_FIRST_PAYMENT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $txn_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'status'     => $statuses,
					'limit'      => 1,
					'return'     => 'ids',
				)
			);
		}
		return ! empty( $found );
	}

	/**
	 * Subscription start time.
	 *
	 * @param WC_Subscription|RPSFW_Subscription $subscription Subscription.
	 * @param WC_Order|false                      $parent       Parent order.
	 * @param bool                                $is_wcs       Whether it is a WooCommerce Subscriptions one.
	 * @return int
	 */
	private static function get_start_time( $subscription, $parent, $is_wcs ) {
		if ( $parent && $parent->get_date_created() ) {
			return $parent->get_date_created()->getTimestamp();
		}
		if ( $is_wcs ) {
			return (int) $subscription->get_time( 'start' );
		}
		$start = $subscription->get_start_date();
		return $start ? (int) wc_string_to_timestamp( $start ) : 0;
	}

	/**
	 * Disable emails.
	 */
	private static function mute_emails() {
		foreach ( self::email_ids() as $id ) {
			add_filter( 'woocommerce_email_enabled_' . $id, array( __CLASS__, 'email_off' ), 999 );
		}
	}

	/**
	 * Re-enable emails.
	 */
	private static function unmute_emails() {
		foreach ( self::email_ids() as $id ) {
			remove_filter( 'woocommerce_email_enabled_' . $id, array( __CLASS__, 'email_off' ), 999 );
		}
	}

	/**
	 * Email ids.
	 *
	 * @return string[]
	 */
	private static function email_ids() {
		$ids = array();
		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			foreach ( WC()->mailer()->get_emails() as $email ) {
				if ( ! empty( $email->id ) ) {
					$ids[] = $email->id;
				}
			}
		}
		return $ids;
	}

	/**
	 * Filter callback.
	 *
	 * @return bool
	 */
	public static function email_off() {
		return false;
	}

	/**
	 * Whether PayPal is connected.
	 *
	 * @return bool
	 */
	private static function is_connected() {
		$settings   = get_option( 'woocommerce_rpsfw_paypal_commerce_settings', array() );
		$onboarding = is_array( $settings ) && isset( $settings['ppcp_onboarding'] ) ? $settings['ppcp_onboarding'] : array();
		return is_array( $onboarding ) && ( ! empty( $onboarding['live']['seller_id'] ) || ! empty( $onboarding['sandbox']['seller_id'] ) );
	}

	/**
	 * The PayPal Commerce gateway.
	 *
	 * @return WC_Gateway_PayPal_Commerce|false
	 */
	private static function get_gateway() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return false;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways['rpsfw_paypal_commerce'] ) && is_object( $gateways['rpsfw_paypal_commerce']->api ) ? $gateways['rpsfw_paypal_commerce'] : false;
	}

	/**
	 * Reload a subscription.
	 *
	 * @param WC_Subscription|RPSFW_Subscription $subscription Subscription.
	 * @param bool                                $is_wcs       Whether it is a WooCommerce Subscriptions one.
	 * @return WC_Subscription|RPSFW_Subscription|false
	 */
	private static function reload( $subscription, $is_wcs ) {
		return $is_wcs ? wcs_get_subscription( $subscription->get_id() ) : rpsfw_get_subscription( $subscription->get_id() );
	}

	/**
	 * Get meta.
	 *
	 * @param WC_Subscription|RPSFW_Subscription $subscription Subscription.
	 * @param string                              $key          Meta key.
	 * @return mixed
	 */
	private static function get_meta( $subscription, $key ) {
		return $subscription instanceof RPSFW_Subscription
			? get_post_meta( $subscription->get_id(), $key, true )
			: $subscription->get_meta( $key );
	}

	/**
	 * Set meta.
	 *
	 * @param WC_Subscription|RPSFW_Subscription $subscription Subscription.
	 * @param string                              $key          Meta key.
	 * @param mixed                               $value        Value.
	 */
	private static function set_meta( $subscription, $key, $value ) {
		if ( $subscription instanceof RPSFW_Subscription ) {
			update_post_meta( $subscription->get_id(), $key, $value );
			return;
		}
		$subscription->update_meta_data( $key, $value );
		$subscription->save_meta_data();
	}

	/**
	 * ISO 8601 UTC time.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	private static function iso( $timestamp ) {
		return gmdate( 'Y-m-d\TH:i:s\Z', (int) $timestamp );
	}

	/**
	 * Sort callback.
	 *
	 * @param array $a Transaction.
	 * @param array $b Transaction.
	 * @return int
	 */
	public static function by_time( $a, $b ) {
		if ( $a['timestamp'] === $b['timestamp'] ) {
			return strcmp( (string) $a['id'], (string) $b['id'] );
		}
		return $a['timestamp'] < $b['timestamp'] ? -1 : 1;
	}
}

WC_PayPal_Commerce_Daily_Check::init();
