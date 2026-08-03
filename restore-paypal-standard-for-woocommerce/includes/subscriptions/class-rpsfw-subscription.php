<?php
/**
 * Subscription model.
 *
 * A thin object wrapper around the rpsfw_subscription post type. All state
 * is stored as post meta; the subscription status is the post status
 * (prefixed "rpsfw-"). The billing schedule itself is owned by the payment
 * processor — the dates stored here mirror what PayPal/Stripe report via
 * webhooks and are informational.
 *
 * @package Restore_PayPal_Standard_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RPSFW_Subscription
 */
class RPSFW_Subscription {

	/**
	 * Post id.
	 *
	 * @var int
	 */
	private $id = 0;

	/**
	 * Backing post.
	 *
	 * @var WP_Post|null
	 */
	private $post = null;

	/**
	 * Constructor.
	 *
	 * @param int|WP_Post|RPSFW_Subscription $subscription Subscription id or post.
	 */
	public function __construct( $subscription = 0 ) {
		if ( $subscription instanceof RPSFW_Subscription ) {
			$subscription = $subscription->get_id();
		}
		if ( $subscription instanceof WP_Post ) {
			$this->post = $subscription;
			$this->id   = $subscription->ID;
		} elseif ( is_numeric( $subscription ) && $subscription > 0 ) {
			$post = get_post( (int) $subscription );
			if ( $post && RPSFW_Subscriptions_Post_Type::POST_TYPE === $post->post_type ) {
				$this->post = $post;
				$this->id   = $post->ID;
			}
		}
	}

	/**
	 * Create a new subscription record.
	 *
	 * @param array $args {
	 *     Subscription data.
	 *
	 *     @type string $status              Initial status (default 'pending').
	 *     @type int    $customer_id         WP user id (0 for guests).
	 *     @type int    $parent_order_id     Parent (initial) order id.
	 *     @type string $gateway_id          WC gateway id (rpsfw_paypal_commerce | rpsfw_stripe).
	 *     @type string $gateway_sub_id      Processor subscription id (I-... / sub_...).
	 *     @type string $gateway_customer_id Processor customer id (Stripe cus_...).
	 *     @type int    $product_id          Product id.
	 *     @type int    $variation_id        Variation id (0 if none).
	 *     @type int    $quantity            Quantity.
	 *     @type string $item_name           Product name snapshot.
	 *     @type float  $recurring_amount    Full recurring total per cycle.
	 *     @type string $currency            Currency code.
	 *     @type string $period              day|week|month|year.
	 *     @type int    $interval            Billing interval.
	 *     @type int    $length              Total number of payments (0 = until cancelled).
	 *     @type int    $trial_length        Free trial length (0 = none).
	 *     @type string $trial_period        day|week|month|year.
	 *     @type float  $signup_fee          Sign-up fee charged on the first payment.
	 * }
	 * @return RPSFW_Subscription|WP_Error
	 */
	public static function create( $args ) {
		$defaults = array(
			'status'              => 'pending',
			'customer_id'         => 0,
			'parent_order_id'     => 0,
			'gateway_id'          => '',
			'gateway_sub_id'      => '',
			'gateway_customer_id' => '',
			'product_id'          => 0,
			'variation_id'        => 0,
			'quantity'            => 1,
			'item_name'           => '',
			'recurring_amount'    => 0,
			'currency'            => get_woocommerce_currency(),
			'period'              => 'month',
			'interval'            => 1,
			'length'              => 0,
			'trial_length'        => 0,
			'trial_period'        => 'day',
			'signup_fee'          => 0,
		);
		$args = wp_parse_args( $args, $defaults );

		$post_id = wp_insert_post(
			array(
				'post_type'     => RPSFW_Subscriptions_Post_Type::POST_TYPE,
				'post_status'   => 'rpsfw-' . $args['status'],
				'post_author'   => (int) $args['customer_id'],
				'post_title'    => __( 'Subscription', 'restore-paypal-standard-for-woocommerce' ),
				'post_password' => wp_generate_password( 20, false ), // Belt-and-braces: never publicly viewable.
				'ping_status'   => 'closed',
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Retitle with the id for admin list/search.
		wp_update_post(
			array(
				'ID'         => $post_id,
				/* translators: %s: subscription id */
				'post_title' => sprintf( __( 'Subscription #%s', 'restore-paypal-standard-for-woocommerce' ), $post_id ),
			)
		);

		$meta = array(
			'_rpsfw_customer_id'         => (int) $args['customer_id'],
			'_rpsfw_parent_order_id'     => (int) $args['parent_order_id'],
			'_rpsfw_gateway'             => sanitize_text_field( $args['gateway_id'] ),
			'_rpsfw_gateway_sub_id'      => sanitize_text_field( $args['gateway_sub_id'] ),
			'_rpsfw_gateway_customer_id' => sanitize_text_field( $args['gateway_customer_id'] ),
			'_rpsfw_product_id'          => (int) $args['product_id'],
			'_rpsfw_variation_id'        => (int) $args['variation_id'],
			'_rpsfw_quantity'            => max( 1, (int) $args['quantity'] ),
			'_rpsfw_item_name'           => sanitize_text_field( $args['item_name'] ),
			'_rpsfw_recurring_amount'    => wc_format_decimal( $args['recurring_amount'] ),
			'_rpsfw_currency'            => sanitize_text_field( $args['currency'] ),
			'_rpsfw_period'              => sanitize_text_field( $args['period'] ),
			'_rpsfw_interval'            => max( 1, (int) $args['interval'] ),
			'_rpsfw_length'              => max( 0, (int) $args['length'] ),
			'_rpsfw_trial_length'        => max( 0, (int) $args['trial_length'] ),
			'_rpsfw_trial_period'        => sanitize_text_field( $args['trial_period'] ),
			'_rpsfw_signup_fee'          => wc_format_decimal( $args['signup_fee'] ),
			'_rpsfw_start_date'          => current_time( 'mysql', true ),
			'_rpsfw_next_payment'        => '',
			'_rpsfw_end_date'            => '',
			'_rpsfw_payment_count'       => 0,
			'_rpsfw_renewal_order_ids'   => array(),
			'_rpsfw_last_transaction_id' => '',
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}

		// Flag used to keep record-servicing surfaces available even after
		// the module is disabled or WCS is installed later.
		update_option( 'rpsfw_subscriptions_exist', 'yes', false );

		$subscription = new self( $post_id );
		do_action( 'rpsfw_subscription_created', $subscription, $args );

		return $subscription;
	}

	/**
	 * Whether this object is backed by a real record.
	 *
	 * @return bool
	 */
	public function exists() {
		return $this->id > 0;
	}

	/**
	 * Get id.
	 *
	 * @return int
	 */
	public function get_id() {
		return $this->id;
	}

	// -----------------------------------------------------------------
	// Meta accessors.
	// -----------------------------------------------------------------

	/**
	 * Read a meta value.
	 *
	 * @param string $key     Meta key (without leading underscore prefix pattern).
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	private function get_meta( $key, $default = '' ) {
		$value = get_post_meta( $this->id, $key, true );
		return ( '' === $value || null === $value ) ? $default : $value;
	}

	/**
	 * Write a meta value.
	 *
	 * @param string $key   Meta key.
	 * @param mixed  $value Value.
	 */
	private function set_meta( $key, $value ) {
		update_post_meta( $this->id, $key, $value );
	}

	/** @return string Status without the rpsfw- prefix. */
	public function get_status() {
		$status = $this->post ? $this->post->post_status : '';
		// Refresh in case it was updated during this request.
		$fresh = get_post_status( $this->id );
		if ( $fresh ) {
			$status = $fresh;
		}
		return 0 === strpos( $status, 'rpsfw-' ) ? substr( $status, 6 ) : $status;
	}

	/** @return bool */
	public function has_status( $status ) {
		return in_array( $this->get_status(), (array) $status, true );
	}

	/**
	 * Whether this subscription has reached an end state it can never leave.
	 *
	 * Cancelling is final at both processors - nothing can un-cancel a PayPal
	 * subscription or a deleted Stripe one, and a re-subscribe creates a new
	 * record with a new processor id. Expiry is final for the same reason.
	 *
	 * Processor events do not arrive in a guaranteed order: pausing and then
	 * cancelling in quick succession fires SUSPENDED and CANCELLED at almost the
	 * same moment, and if SUSPENDED is processed last it would otherwise drag a
	 * cancelled subscription back to on-hold. Webhook handlers test this before
	 * applying any status.
	 *
	 * @since 4.0.0
	 *
	 * @return bool
	 */
	public function has_terminal_status() {
		return $this->has_status( array( 'cancelled', 'expired' ) );
	}

	/** @return int */
	public function get_customer_id() {
		return (int) $this->get_meta( '_rpsfw_customer_id', 0 );
	}

	/** @return int */
	public function get_parent_order_id() {
		return (int) $this->get_meta( '_rpsfw_parent_order_id', 0 );
	}

	/** @return WC_Order|false */
	public function get_parent_order() {
		$order_id = $this->get_parent_order_id();
		return $order_id ? wc_get_order( $order_id ) : false;
	}

	/** @return string WC gateway id. */
	public function get_gateway_id() {
		return (string) $this->get_meta( '_rpsfw_gateway' );
	}

	/** @return string Processor subscription id (I-... / sub_...). */
	public function get_gateway_sub_id() {
		return (string) $this->get_meta( '_rpsfw_gateway_sub_id' );
	}

	/** @param string $id Processor subscription id. */
	public function set_gateway_sub_id( $id ) {
		$this->set_meta( '_rpsfw_gateway_sub_id', sanitize_text_field( $id ) );
	}

	/** @return string Processor customer id. */
	public function get_gateway_customer_id() {
		return (string) $this->get_meta( '_rpsfw_gateway_customer_id' );
	}

	/** @return int */
	public function get_product_id() {
		return (int) $this->get_meta( '_rpsfw_product_id', 0 );
	}

	/** @return int */
	public function get_variation_id() {
		return (int) $this->get_meta( '_rpsfw_variation_id', 0 );
	}

	/** @return WC_Product|false The purchasable product (variation when set). */
	public function get_product() {
		$product_id = $this->get_variation_id() ? $this->get_variation_id() : $this->get_product_id();
		return $product_id ? wc_get_product( $product_id ) : false;
	}

	/** @return int */
	public function get_quantity() {
		return max( 1, (int) $this->get_meta( '_rpsfw_quantity', 1 ) );
	}

	/** @return string */
	public function get_item_name() {
		return (string) $this->get_meta( '_rpsfw_item_name' );
	}

	/** @return float Full recurring total per billing cycle. */
	public function get_recurring_amount() {
		return (float) $this->get_meta( '_rpsfw_recurring_amount', 0 );
	}

	/** @param float $amount New recurring amount. */
	public function set_recurring_amount( $amount ) {
		$this->set_meta( '_rpsfw_recurring_amount', wc_format_decimal( $amount ) );
	}

	/** @return string */
	public function get_currency() {
		return (string) $this->get_meta( '_rpsfw_currency', get_woocommerce_currency() );
	}

	/** @return string day|week|month|year */
	public function get_period() {
		return (string) $this->get_meta( '_rpsfw_period', 'month' );
	}

	/** @return int */
	public function get_interval() {
		return max( 1, (int) $this->get_meta( '_rpsfw_interval', 1 ) );
	}

	/** @return int Total payments (0 = until cancelled). */
	public function get_length() {
		return max( 0, (int) $this->get_meta( '_rpsfw_length', 0 ) );
	}

	/** @return int */
	public function get_trial_length() {
		return max( 0, (int) $this->get_meta( '_rpsfw_trial_length', 0 ) );
	}

	/** @return string */
	public function get_trial_period() {
		return (string) $this->get_meta( '_rpsfw_trial_period', 'day' );
	}

	/** @return float */
	public function get_signup_fee() {
		return (float) $this->get_meta( '_rpsfw_signup_fee', 0 );
	}

	/** @return string GMT MySQL datetime. */
	public function get_start_date() {
		return (string) $this->get_meta( '_rpsfw_start_date' );
	}

	/** @return string GMT MySQL datetime ('' if unknown). */
	public function get_next_payment_date() {
		return (string) $this->get_meta( '_rpsfw_next_payment' );
	}

	/** @param string $date GMT MySQL datetime or '' to clear. */
	public function set_next_payment_date( $date ) {
		$this->set_meta( '_rpsfw_next_payment', $date );
	}

	/** @return string GMT MySQL datetime ('' if none). */
	public function get_end_date() {
		return (string) $this->get_meta( '_rpsfw_end_date' );
	}

	/** @param string $date GMT MySQL datetime. */
	public function set_end_date( $date ) {
		$this->set_meta( '_rpsfw_end_date', $date );
	}

	/** @return int Number of successful payments recorded (incl. initial). */
	public function get_payment_count() {
		return (int) $this->get_meta( '_rpsfw_payment_count', 0 );
	}

	/** Increment the successful payment counter. */
	public function increment_payment_count() {
		$this->set_meta( '_rpsfw_payment_count', $this->get_payment_count() + 1 );
	}

	/** @return array Renewal order ids (most recent last). */
	public function get_renewal_order_ids() {
		$ids = $this->get_meta( '_rpsfw_renewal_order_ids', array() );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/** @param int $order_id Renewal order id to append. */
	public function add_renewal_order_id( $order_id ) {
		$ids   = $this->get_renewal_order_ids();
		$ids[] = (int) $order_id;
		$this->set_meta( '_rpsfw_renewal_order_ids', $ids );
	}

	/** @return array All related order ids: parent first, then renewals. */
	public function get_related_order_ids() {
		$ids = $this->get_renewal_order_ids();
		if ( $this->get_parent_order_id() ) {
			array_unshift( $ids, $this->get_parent_order_id() );
		}
		return $ids;
	}

	/** @return string */
	public function get_last_transaction_id() {
		return (string) $this->get_meta( '_rpsfw_last_transaction_id' );
	}

	/** @param string $txn_id Transaction id. */
	public function set_last_transaction_id( $txn_id ) {
		$this->set_meta( '_rpsfw_last_transaction_id', sanitize_text_field( $txn_id ) );
	}

	// -----------------------------------------------------------------
	// Status handling.
	// -----------------------------------------------------------------

	/**
	 * Update the subscription status.
	 *
	 * Fires rpsfw_subscription_status_changed and
	 * rpsfw_subscription_status_{new} actions. This method only records the
	 * status locally — propagating the change to the payment processor is
	 * the manager's job (RPSFW_Subscriptions_Manager).
	 *
	 * @param string $new_status New status (no prefix).
	 * @param string $note       Optional note describing why.
	 * @return bool
	 */
	public function update_status( $new_status, $note = '' ) {
		$new_status = str_replace( 'rpsfw-', '', $new_status );
		$old_status = $this->get_status();

		if ( ! array_key_exists( $new_status, rpsfw_get_subscription_statuses() ) || $new_status === $old_status ) {
			return false;
		}

		wp_update_post(
			array(
				'ID'          => $this->id,
				'post_status' => 'rpsfw-' . $new_status,
			)
		);
		if ( $this->post ) {
			$this->post->post_status = 'rpsfw-' . $new_status;
		}

		if ( 'cancelled' === $new_status || 'expired' === $new_status ) {
			$this->set_end_date( current_time( 'mysql', true ) );
			$this->set_next_payment_date( '' );
		}

		$this->add_note(
			$note
				? sprintf(
					/* translators: 1: old status, 2: new status, 3: reason */
					__( 'Status changed from %1$s to %2$s. %3$s', 'restore-paypal-standard-for-woocommerce' ),
					rpsfw_get_subscription_status_label( $old_status ),
					rpsfw_get_subscription_status_label( $new_status ),
					$note
				)
				: sprintf(
					/* translators: 1: old status, 2: new status */
					__( 'Status changed from %1$s to %2$s.', 'restore-paypal-standard-for-woocommerce' ),
					rpsfw_get_subscription_status_label( $old_status ),
					rpsfw_get_subscription_status_label( $new_status )
				)
		);

		do_action( 'rpsfw_subscription_status_changed', $this, $old_status, $new_status );
		do_action( 'rpsfw_subscription_status_' . $new_status, $this, $old_status );

		return true;
	}

	// -----------------------------------------------------------------
	// Notes.
	// -----------------------------------------------------------------

	/**
	 * Add a note (stored as a comment, like order notes).
	 *
	 * @param string $note Note text.
	 * @return int Comment id.
	 */
	public function add_note( $note ) {
		return wp_insert_comment(
			array(
				'comment_post_ID'      => $this->id,
				'comment_author'       => RPSFW_PLUGIN_NAME,
				'comment_author_email' => '',
				'comment_author_url'   => '',
				'comment_content'      => $note,
				'comment_type'         => 'rpsfw_sub_note',
				'comment_approved'     => 1,
				'comment_agent'        => 'rpsfw',
			)
		);
	}

	/**
	 * Get notes, newest first.
	 *
	 * @return WP_Comment[]
	 */
	public function get_notes() {
		remove_filter( 'comments_clauses', array( 'RPSFW_Subscriptions_Post_Type', 'exclude_note_comments' ), 10 );
		$notes = get_comments(
			array(
				'post_id' => $this->id,
				'type'    => 'rpsfw_sub_note',
				'status'  => 'approve',
				'orderby' => 'comment_date_gmt',
				'order'   => 'DESC',
			)
		);
		add_filter( 'comments_clauses', array( 'RPSFW_Subscriptions_Post_Type', 'exclude_note_comments' ), 10, 1 );
		return $notes;
	}

	// -----------------------------------------------------------------
	// Display helpers.
	// -----------------------------------------------------------------

	/** @return string Customer display name (billing name or user display name). */
	public function get_customer_name() {
		$order = $this->get_parent_order();
		if ( $order ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			if ( $name ) {
				return $name;
			}
		}
		$user = $this->get_customer_id() ? get_userdata( $this->get_customer_id() ) : false;
		return $user ? $user->display_name : '';
	}

	/**
	 * Human gateway title.
	 *
	 * @param string $context 'view' for customer-facing output (My Account,
	 *                        emails, renewal order titles) or 'admin' for the
	 *                        list table and record screens.
	 * @return string
	 */
	public function get_gateway_title( $context = 'view' ) {
		$gateway_id = $this->get_gateway_id();

		// Admin labels live in one place — rpsfw_gateway_label() — so the names
		// cannot drift between the orders list, the subscriptions list and the
		// deep-links box.
		if ( 'admin' === $context ) {
			$label = function_exists( 'rpsfw_gateway_label' ) ? rpsfw_gateway_label( $gateway_id ) : '';
			if ( $label ) {
				return $label;
			}
		}

		// Customer-facing: shoppers know it as "PayPal", not "PayPal Commerce".
		switch ( $gateway_id ) {
			case 'rpsfw_paypal_commerce':
				return __( 'PayPal', 'restore-paypal-standard-for-woocommerce' );

			case 'rpsfw_stripe':
				return __( 'Stripe', 'restore-paypal-standard-for-woocommerce' );
		}

		return $gateway_id ? $gateway_id : __( 'Unknown', 'restore-paypal-standard-for-woocommerce' );
	}

	/**
	 * Human-readable billing schedule, e.g. "every month", "every 2 weeks".
	 *
	 * @return string
	 */
	public function get_formatted_schedule() {
		return rpsfw_format_subscription_period( $this->get_interval(), $this->get_period() );
	}

	/**
	 * Formatted recurring total, e.g. "$29.99 / month".
	 *
	 * @return string HTML.
	 */
	public function get_formatted_recurring_total() {
		return sprintf(
			/* translators: 1: recurring amount, 2: billing schedule (e.g. "every month") */
			_x( '%1$s %2$s', 'formatted recurring total: price then schedule', 'restore-paypal-standard-for-woocommerce' ),
			wc_price( $this->get_recurring_amount(), array( 'currency' => $this->get_currency() ) ),
			$this->get_formatted_schedule()
		);
	}

	/**
	 * Formatted date for display (site timezone), or a dash when not set.
	 *
	 * @param string $type start|next_payment|end.
	 * @return string
	 */
	public function get_date_to_display( $type ) {
		switch ( $type ) {
			case 'next_payment':
				$date = $this->get_next_payment_date();
				break;
			case 'end':
				$date = $this->get_end_date();
				break;
			case 'start':
			default:
				$date = $this->get_start_date();
				break;
		}

		if ( ! $date ) {
			return '—';
		}

		$timestamp = strtotime( $date . ' UTC' );
		if ( ! $timestamp ) {
			return '—';
		}

		return date_i18n( wc_date_format(), $timestamp + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
	}

	/** @return string My Account view URL. */
	public function get_view_url() {
		return wc_get_endpoint_url( 'rpsfw-view-subscription', $this->id, wc_get_page_permalink( 'myaccount' ) );
	}

	// -----------------------------------------------------------------
	// Permissions.
	// -----------------------------------------------------------------

	/** @return bool Whether the record can be cancelled (state-wise). */
	public function can_be_cancelled() {
		return $this->has_status( array( 'active', 'on-hold', 'pending-cancel', 'pending' ) );
	}

	/** @return bool Whether the record can be suspended (state-wise). */
	public function can_be_suspended() {
		return $this->has_status( array( 'active' ) );
	}

	/** @return bool Whether the record can be resumed (state-wise). */
	public function can_be_resumed() {
		if ( $this->has_status( 'on-hold' ) ) {
			return true;
		}
		// Stripe pending-cancel can be reactivated by clearing the
		// scheduled cancellation; PayPal has no equivalent.
		return $this->has_status( 'pending-cancel' ) && 'rpsfw_stripe' === $this->get_gateway_id();
	}

	/**
	 * Whether the given user owns this subscription.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public function belongs_to_user( $user_id ) {
		return $this->get_customer_id() && (int) $user_id === $this->get_customer_id();
	}
}
