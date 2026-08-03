<?php
/**
 * Admin meta box: deep links to PayPal / Stripe dashboards from a WC
 * order or subscription edit page.
 *
 * Renders a small panel as its own meta box positioned immediately
 * after the "Order actions" / "Subscription actions" box in the side
 * column.
 *
 * @package RestorePayPalStandard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RPSFW_Gateway_Deep_Links
 */
class RPSFW_Gateway_Deep_Links {

	const PAYPAL_GATEWAY_ID = 'rpsfw_paypal_commerce';
	const STRIPE_GATEWAY_ID = 'rpsfw_stripe';

	/**
	 * Bootstrap.
	 */
	public static function init() {
		// Register at priority 99 so we run after WC has registered its
		// Order Actions, Order Attribution, and Customer History meta
		// boxes (all default priority 10).
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_box' ), 99, 2 );

		// Reorder the side column so our meta box sits immediately after
		// Order Actions. Runs at priority 999 to ensure all registrations
		// have completed.
		add_action( 'add_meta_boxes', array( __CLASS__, 'reorder_side_meta_boxes' ), 999, 2 );
	}

	/**
	 * Register on order, subscription, and HPOS screens.
	 *
	 * @param string                                                  $screen_id     Current screen id.
	 * @param WP_Post|WC_Order|WC_Order_Refund|WC_Subscription|null   $post_or_order Object on screen.
	 */
	public static function register_meta_box( $screen_id, $post_or_order = null ) {
		if ( ! self::is_supported_screen( $screen_id ) ) {
			return;
		}

		add_meta_box(
			'rpsfw_gateway_deep_links',
			__( 'Gateway dashboard', 'restore-paypal-standard-for-woocommerce' ),
			array( __CLASS__, 'render_meta_box' ),
			$screen_id,
			'side',
			'high'
		);
	}

	/**
	 * Reorder the global $wp_meta_boxes array so our meta box lives
	 * directly after woocommerce-order-actions in the side column.
	 *
	 * WordPress renders side-column meta boxes in array order within a
	 * given priority bucket. Sorting the high-priority bucket so our
	 * key follows woocommerce-order-actions guarantees the layout we
	 * want, regardless of registration order.
	 *
	 * @param string $screen_id Current screen id.
	 */
	public static function reorder_side_meta_boxes( $screen_id, $post_or_order = null ) {
		if ( ! self::is_supported_screen( $screen_id ) ) {
			return;
		}

		global $wp_meta_boxes;

		if ( empty( $wp_meta_boxes[ $screen_id ]['side']['high'] ) ) {
			return;
		}

		$boxes = &$wp_meta_boxes[ $screen_id ]['side']['high'];

		if ( ! isset( $boxes['rpsfw_gateway_deep_links'] ) || ! isset( $boxes['woocommerce-order-actions'] ) ) {
			return;
		}

		$keys = array_keys( $boxes );

		// Remove our key wherever it currently sits.
		$keys = array_values( array_diff( $keys, array( 'rpsfw_gateway_deep_links' ) ) );

		// Insert it directly after woocommerce-order-actions.
		$actions_pos = array_search( 'woocommerce-order-actions', $keys, true );
		if ( false === $actions_pos ) {
			return;
		}

		array_splice( $keys, $actions_pos + 1, 0, array( 'rpsfw_gateway_deep_links' ) );

		// Rebuild the bucket in the new order.
		$reordered = array();
		foreach ( $keys as $key ) {
			$reordered[ $key ] = $boxes[ $key ];
		}
		$boxes = $reordered;
	}

	/**
	 * True if the current screen is one we support (order or
	 * subscription, legacy or HPOS).
	 *
	 * @param string $screen_id Screen id.
	 * @return bool
	 */
	private static function is_supported_screen( $screen_id ) {
		return in_array(
			$screen_id,
			array(
				'shop_order',
				'shop_subscription',
				'woocommerce_page_wc-orders',
				'woocommerce_page_wc-orders--shop_subscription',
			),
			true
		);
	}

	/**
	 * Render the meta box for the current order/subscription.
	 *
	 * @param WP_Post|WC_Order|WC_Subscription $post_or_order Either a WP_Post
	 *        (legacy screen) or a WC_Order/WC_Subscription (HPOS screen).
	 */
	public static function render_meta_box( $post_or_order ) {
		$order = self::resolve_order( $post_or_order );
		if ( ! $order ) {
			echo '<p>' . esc_html__( 'No order context available.', 'restore-paypal-standard-for-woocommerce' ) . '</p>';
			return;
		}

		$payment_method = $order->get_payment_method();

		if ( $payment_method === self::PAYPAL_GATEWAY_ID ) {
			self::render_paypal_links( $order );
		} elseif ( $payment_method === self::STRIPE_GATEWAY_ID ) {
			self::render_stripe_links( $order );
		} else {
			printf(
				'<p>%s</p>',
				esc_html__( 'No gateway dashboard links for this payment method.', 'restore-paypal-standard-for-woocommerce' )
			);
		}
	}

	/**
	 * Escaped "Test"/"Live" badge for the mode a payment was taken in.
	 *
	 * Test mode is called out in colour because it is the surprising case: it
	 * tells the admin at a glance that this order's money, ids and dashboard
	 * links live in the sandbox account, whatever the store is set to now.
	 *
	 * Pass '' for an order with no recorded mode. That renders "Unknown" rather
	 * than the gateway's current setting: the mode was never recorded for that
	 * payment, and printing today's setting would state a guess as fact.
	 *
	 * @param string $mode 'test', 'live', or '' when unrecorded.
	 * @return string Escaped HTML.
	 */
	public static function mode_badge( $mode ) {
		if ( '' === $mode || null === $mode ) {
			return '<span title="' . esc_attr__( 'This payment was taken before the plugin recorded which mode was used. Links below follow the gateway\'s current mode, which may not be where this payment lives.', 'restore-paypal-standard-for-woocommerce' ) . '" style="background:#f6f7f7;color:#787c82;border:1px dashed #c3c4c7;font-size:11px;font-weight:600;padding:0 6px;border-radius:9px;vertical-align:middle;">'
				. esc_html__( 'Mode unknown', 'restore-paypal-standard-for-woocommerce' )
				. '</span>';
		}

		$mode  = rpsfw_normalize_payment_mode( $mode );
		$style = ( 'test' === $mode )
			? 'background:#f0b849;color:#1d2327;'
			: 'background:#edeff0;color:#50575e;';

		return '<span style="' . esc_attr( $style ) . 'font-size:11px;font-weight:600;padding:1px 6px;border-radius:9px;vertical-align:middle;">'
			. esc_html( rpsfw_payment_mode_label( $mode ) )
			. '</span>';
	}

	/**
	 * Render the "Gateway: … / Mode: …" header shared by both sections.
	 *
	 * The label comes from rpsfw_gateway_label(), the single source of truth for
	 * admin gateway names, so this box always agrees with the orders and
	 * subscriptions list tables.
	 *
	 * @param WC_Order|WC_Subscription $order        Order or subscription.
	 * @param string                   $stamped_mode Recorded mode, or '' when unrecorded.
	 */
	private static function render_header( $order, $stamped_mode ) {
		$label = function_exists( 'rpsfw_gateway_label' ) ? rpsfw_gateway_label( $order->get_payment_method() ) : '';
		if ( '' === $label ) {
			$label = $order->get_payment_method_title();
		}

		echo '<p style="margin:0 0 4px;">';
		echo '<strong>' . esc_html__( 'Gateway:', 'restore-paypal-standard-for-woocommerce' ) . '</strong> ';
		echo esc_html( $label );
		echo '</p>';

		echo '<p style="margin:0 0 10px;">';
		echo '<strong>' . esc_html__( 'Mode:', 'restore-paypal-standard-for-woocommerce' ) . '</strong> ';
		echo self::mode_badge( $stamped_mode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in mode_badge().
		echo '</p>';
	}

	/**
	 * Render the PayPal section.
	 *
	 * @param WC_Order|WC_Subscription $order Order or subscription.
	 */
	private static function render_paypal_links( $order ) {
		// The mode this order was PAID in — see render_stripe_links().
		$stamped_mode = rpsfw_get_stamped_order_payment_mode( $order );
		$is_test      = ( 'test' === rpsfw_get_order_payment_mode( $order ) );
		$base         = $is_test ? 'https://www.sandbox.paypal.com' : 'https://www.paypal.com';

		$subscription_id = $order->get_meta( '_rpsfw_ppcp_subscription_id' );
		$plan_id         = $order->get_meta( '_rpsfw_ppcp_plan_id' );
		$last_payment_id = $order->get_meta( '_rpsfw_ppcp_last_payment_id' );
		$txn_id          = $order->get_transaction_id();
		$paypal_order_id = $order->get_meta( '_paypal_order_id' );
		$capture_id      = $order->get_meta( '_paypal_capture_id' );
		$auth_id         = $order->get_meta( '_paypal_authorization_id' );

		self::render_header( $order, $stamped_mode );

		$links = array();

		if ( $subscription_id ) {
			$links[] = array(
				'label' => __( 'View subscription', 'restore-paypal-standard-for-woocommerce' ),
				'url'   => $base . '/billing/subscriptions/' . rawurlencode( $subscription_id ),
				'id'    => $subscription_id,
			);
		}

		if ( $plan_id ) {
			$links[] = array(
				'label' => __( 'View plan', 'restore-paypal-standard-for-woocommerce' ),
				'url'   => $base . '/billing/plans/' . rawurlencode( $plan_id ),
				'id'    => $plan_id,
			);
		}

		// For one-off orders, prefer capture id, then authorization, then
		// PayPal order id, then a non-subscription transaction id. For
		// subscription orders, prefer the captured sale id from the
		// PAYMENT.SALE.COMPLETED webhook.
		$activity_id = $last_payment_id;
		if ( ! $activity_id ) {
			$activity_id = $capture_id ? $capture_id : ( $auth_id ? $auth_id : ( $paypal_order_id ? $paypal_order_id : '' ) );
		}
		if ( ! $activity_id && $txn_id && strpos( $txn_id, 'I-' ) !== 0 ) {
			$activity_id = $txn_id;
		}

		if ( $activity_id ) {
			$links[] = array(
				'label' => __( 'View payment', 'restore-paypal-standard-for-woocommerce' ),
				'url'   => $base . '/activity/payment/' . rawurlencode( $activity_id ),
				'id'    => $activity_id,
			);
		}

		if ( empty( $links ) ) {
			echo '<p>' . esc_html__( 'No PayPal references stored on this record yet.', 'restore-paypal-standard-for-woocommerce' ) . '</p>';
			return;
		}

		self::render_link_list( $links );
	}

	/**
	 * Render the Stripe section.
	 *
	 * @param WC_Order|WC_Subscription $order Order or subscription.
	 */
	private static function render_stripe_links( $order ) {
		// The mode this order was PAID in, not the gateway's current setting —
		// otherwise flipping the store to live sends every historic test order's
		// links to the live dashboard, where those ids do not exist. The badge
		// shows only what was actually recorded; the links have to point
		// somewhere, so for unrecorded orders they follow the current mode.
		$stamped_mode = rpsfw_get_stamped_order_payment_mode( $order );
		$is_test      = ( 'test' === rpsfw_get_order_payment_mode( $order ) );
		// Stripe dashboard URLs use a /test/ prefix in test mode.
		$base = $is_test ? 'https://dashboard.stripe.com/test' : 'https://dashboard.stripe.com';

		$customer_id     = $order->get_meta( '_rpsfw_stripe_customer_id' );
		$subscription_id = $order->get_meta( '_rpsfw_stripe_subscription_id' );
		$invoice_id      = $order->get_meta( '_rpsfw_stripe_invoice_id' );
		$payment_intent  = $order->get_meta( '_rpsfw_stripe_payment_intent_id' );
		$payment_method  = $order->get_meta( '_rpsfw_stripe_payment_method_id' );
		$charge_id       = $order->get_meta( '_rpsfw_stripe_charge_id' );
		$txn_id          = $order->get_transaction_id();

		self::render_header( $order, $stamped_mode );

		$links = array();

		if ( $subscription_id ) {
			$links[] = array(
				'label' => __( 'View subscription', 'restore-paypal-standard-for-woocommerce' ),
				'url'   => $base . '/subscriptions/' . rawurlencode( $subscription_id ),
				'id'    => $subscription_id,
			);
		}

		// Direct link to the actual payment / charge for this order.
		// On subscription orders the transaction_id is the sub_..., so
		// META_PAYMENT_INTENT is what we want here. On renewal orders,
		// transaction_id is the invoice id (in_...).
		if ( $payment_intent ) {
			$links[] = array(
				'label' => __( 'View payment', 'restore-paypal-standard-for-woocommerce' ),
				'url'   => $base . '/payments/' . rawurlencode( $payment_intent ),
				'id'    => $payment_intent,
			);
		}

		if ( $customer_id ) {
			$links[] = array(
				'label' => __( 'View customer', 'restore-paypal-standard-for-woocommerce' ),
				'url'   => $base . '/customers/' . rawurlencode( $customer_id ),
				'id'    => $customer_id,
			);
		}

		if ( $invoice_id ) {
			$links[] = array(
				'label' => __( 'View first invoice', 'restore-paypal-standard-for-woocommerce' ),
				'url'   => $base . '/invoices/' . rawurlencode( $invoice_id ),
				'id'    => $invoice_id,
			);
		}

		if ( $payment_method ) {
			$links[] = array(
				'label' => __( 'View payment method', 'restore-paypal-standard-for-woocommerce' ),
				'url'   => $base . '/payment_methods/' . rawurlencode( $payment_method ),
				'id'    => $payment_method,
			);
		}

		// Resolve a payment / charge / invoice link from the WC
		// transaction id depending on its prefix. Skip if we already
		// added the same id above as META_PAYMENT_INTENT.
		if ( $txn_id && $txn_id !== $payment_intent ) {
			if ( strpos( $txn_id, 'pi_' ) === 0 ) {
				$links[] = array(
					'label' => __( 'View payment', 'restore-paypal-standard-for-woocommerce' ),
					'url'   => $base . '/payments/' . rawurlencode( $txn_id ),
					'id'    => $txn_id,
				);
			} elseif ( strpos( $txn_id, 'ch_' ) === 0 ) {
				$links[] = array(
					'label' => __( 'View charge', 'restore-paypal-standard-for-woocommerce' ),
					'url'   => $base . '/payments/' . rawurlencode( $txn_id ),
					'id'    => $txn_id,
				);
			} elseif ( strpos( $txn_id, 'in_' ) === 0 && empty( $invoice_id ) ) {
				$links[] = array(
					'label' => __( 'View invoice', 'restore-paypal-standard-for-woocommerce' ),
					'url'   => $base . '/invoices/' . rawurlencode( $txn_id ),
					'id'    => $txn_id,
				);
			}
		}

		// Final fallback for older one-off orders that stored only a
		// charge id.
		if ( $charge_id && empty( $txn_id ) ) {
			$links[] = array(
				'label' => __( 'View charge', 'restore-paypal-standard-for-woocommerce' ),
				'url'   => $base . '/payments/' . rawurlencode( $charge_id ),
				'id'    => $charge_id,
			);
		}

		if ( empty( $links ) ) {
			echo '<p>' . esc_html__( 'No Stripe references stored on this record yet.', 'restore-paypal-standard-for-woocommerce' ) . '</p>';
			return;
		}

		self::render_link_list( $links );
	}

	/**
	 * Render a simple list of labelled links with their associated id.
	 *
	 * @param array $links Each item: label, url, id.
	 */
	private static function render_link_list( $links ) {
		echo '<ul style="margin: 0; padding: 0; list-style: none;">';
		foreach ( $links as $link ) {
			echo '<li style="margin: 0 0 8px;">';
			printf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s ↗</a>',
				esc_url( $link['url'] ),
				esc_html( $link['label'] )
			);
			if ( ! empty( $link['id'] ) ) {
				echo '<br /><code style="font-size:11px;color:#666;word-break:break-all;">' . esc_html( $link['id'] ) . '</code>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Resolve a WC_Order/WC_Subscription from the meta box callback arg.
	 * On legacy screens it's a WP_Post; on HPOS it's already the order.
	 *
	 * @param WP_Post|WC_Order|WC_Subscription $post_or_order Object.
	 * @return WC_Order|WC_Subscription|false
	 */
	private static function resolve_order( $post_or_order ) {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}
		if ( $post_or_order instanceof WP_Post ) {
			return wc_get_order( $post_or_order->ID );
		}
		if ( is_object( $post_or_order ) && method_exists( $post_or_order, 'get_id' ) ) {
			return wc_get_order( $post_or_order->get_id() );
		}
		return false;
	}

}

RPSFW_Gateway_Deep_Links::init();
