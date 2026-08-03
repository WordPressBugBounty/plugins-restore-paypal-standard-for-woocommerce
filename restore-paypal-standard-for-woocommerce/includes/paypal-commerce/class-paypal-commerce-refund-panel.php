<?php
/**
 * PayPal Commerce refund panel for orders and subscriptions.
 *
 * PayPal captures a single payment per order, so this panel shows one row (the
 * capture backing the order/subscription) and lets the merchant refund it with
 * an optional "Cancel subscription" checkbox.
 *
 * Extends the shared RPSFW_Refund_Panel base, which owns the metabox, assets
 * and AJAX plumbing. This subclass provides the PayPal-specific bits: how to
 * resolve the capture and how to issue a PayPal refund.
 *
 * @package RestorePayPalStandard
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __FILE__ ) . '/../class-rpsfw-refund-panel.php';

/**
 * Class RPSFW_PayPal_Commerce_Refund_Panel
 */
class RPSFW_PayPal_Commerce_Refund_Panel extends RPSFW_Refund_Panel {

	/**
	 * Gateway instance (for API access).
	 *
	 * @var WC_Gateway_PayPal_Commerce
	 */
	private $gateway;

	/**
	 * Status of the last provider refund, captured for the ledger note.
	 *
	 * @var string
	 */
	private $last_refund_status = 'COMPLETED';

	/**
	 * Bootstrap.
	 *
	 * @param WC_Gateway_PayPal_Commerce $gateway Gateway instance.
	 */
	public static function init( $gateway ) {
		$self = new self();
		$self->gateway = $gateway;
		$self->register();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function provider_id() {
		return 'rpsfw_paypal_commerce';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function ajax_action() {
		return 'rpsfw_ppcp_refund_charge';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function nonce_action() {
		return 'rpsfw-ppcp-refund-charge';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function metabox_id() {
		return 'rpsfw-ppcp-refund-panel';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function metabox_title() {
		return __( 'PayPal Commerce Payments & Subscriptions', 'restore-paypal-standard-for-woocommerce' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function log( $message, $level = 'info' ) {
		if ( class_exists( 'WC_Gateway_PayPal_Commerce' ) ) {
			WC_Gateway_PayPal_Commerce::log( $message, $level );
		}
	}

	/**
	 * Resolve the gateway instance (stored at init, or looked up as a fallback).
	 *
	 * @return WC_Gateway_PayPal_Commerce|null
	 */
	private function get_gateway() {
		if ( $this->gateway instanceof WC_Gateway_PayPal_Commerce ) {
			return $this->gateway;
		}
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			if ( isset( $gateways['rpsfw_paypal_commerce'] ) ) {
				$this->gateway = $gateways['rpsfw_paypal_commerce'];
				return $this->gateway;
			}
		}
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function enumerate_rows( $context ) {
		// The order that actually holds the captured payment.
		$subscription_source = 'wcs';
		if ( $context instanceof WC_Subscription ) {
			$owner_order_id = $context->get_parent_id();
			if ( ! $owner_order_id ) {
				$last           = $context->get_last_order( 'id' );
				$owner_order_id = $last ? $last : 0;
			}
			$subscription_id = $context->get_id();
		} else {
			$owner_order_id  = $context->get_id();
			$subscription_id = 0;
			if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
				// Parent (initial) OR renewal order both link to a subscription.
				$subs = wcs_get_subscriptions_for_order( $context, array( 'order_type' => array( 'parent', 'renewal' ) ) );
				if ( ! empty( $subs ) ) {
					$first           = reset( $subs );
					$subscription_id = $first->get_id();
				}
			}

			// Native subscriptions (rpsfw_subscription) — when the WooCommerce
			// Subscriptions plugin is not active.
			if ( ! $subscription_id ) {
				if ( function_exists( 'rpsfw_get_subscriptions_for_order' ) ) {
					$native = rpsfw_get_subscriptions_for_order( $context );
					if ( ! empty( $native ) ) {
						$subscription_id     = $native[0]->get_id();
						$subscription_source = 'native';
					}
				}
				if ( ! $subscription_id ) {
					$renewal_of = (int) $context->get_meta( '_rpsfw_subscription_renewal' );
					if ( $renewal_of ) {
						$subscription_id     = $renewal_of;
						$subscription_source = 'native';
					}
				}
			}
		}

		if ( ! $owner_order_id ) {
			$this->add_diagnostic( 'No order holding the PayPal payment could be determined for this subscription.' );
			return array();
		}
		$owner_order = wc_get_order( $owner_order_id );
		if ( ! $owner_order instanceof WC_Order ) {
			$this->add_diagnostic( sprintf( 'The order holding the PayPal payment (#%d) could not be loaded.', $owner_order_id ) );
			return array();
		}

		$capture_id = $this->resolve_capture_id( $owner_order );
		if ( ! $capture_id ) {
			$this->add_diagnostic( sprintf( 'Order #%d has no PayPal capture id yet — only a subscription id (I-...) is stored. PayPal reports the capture via the PAYMENT.SALE.COMPLETED webhook shortly after checkout; a subscription id on its own is not refundable. If this test site cannot receive PayPal webhooks, the capture id will never arrive.', $owner_order_id ) );
			return array();
		}

		$amount     = (float) $owner_order->get_total();
		$refunded   = (float) $owner_order->get_total_refunded();
		$refundable = max( 0, round( $amount - $refunded, 2 ) );

		$names = array();
		foreach ( $context->get_items() as $item ) {
			$names[] = $item->get_name();
		}
		$label = ! empty( $names ) ? implode( ', ', $names ) : sprintf(
			/* translators: %d: order id. */
			__( 'Order #%d', 'restore-paypal-standard-for-woocommerce' ),
			$owner_order_id
		);

		return array(
			array(
				'source_id'           => $capture_id,
				'label'               => $label,
				'amount'              => $amount,
				'refunded'            => $refunded,
				'refundable'          => $refundable,
				'subscription_id'     => $subscription_id,
				'subscription_source' => $subscription_source,
				'owner_order_id'      => $owner_order_id,
			),
		);
	}

	/**
	 * Resolve the refundable PayPal capture id for an order (mirrors the
	 * gateway's process_refund resolution). Returns '' when only a
	 * subscription id (I-...) is available (no capture to refund yet).
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function resolve_capture_id( $order ) {
		$capture_id = $order->get_meta( '_paypal_capture_id' );
		if ( empty( $capture_id ) ) {
			$capture_id = $order->get_meta( '_rpsfw_ppcp_last_payment_id' );
		}
		if ( empty( $capture_id ) ) {
			$capture_id = $order->get_transaction_id();
		}
		if ( empty( $capture_id ) || 0 === strpos( $capture_id, 'I-' ) ) {
			return '';
		}
		return $capture_id;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function do_provider_refund( $source_id, $amount, $currency, $reason, $context, $row ) {
		$gateway = $this->get_gateway();
		if ( ! $gateway ) {
			return new WP_Error( 'no_gateway', __( 'PayPal gateway is unavailable.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		// Refund against the account the payment was actually taken in, not
		// whichever mode the store is set to now — the capture id only exists in
		// the one it was created in. The owner order is the record that holds
		// the payment (for a subscription context that is its parent or last
		// order, not the subscription itself), mirroring handle_ajax().
		$owner_order = ! empty( $row['owner_order_id'] ) ? wc_get_order( $row['owner_order_id'] ) : $context;
		if ( ! $owner_order instanceof WC_Order ) {
			$owner_order = $context;
		}

		$order_mode = rpsfw_get_order_payment_mode( $owner_order );
		$env        = rpsfw_payment_mode_to_ppcp_env( $order_mode );
		$onboarding = $gateway->get_option( 'ppcp_onboarding', array() );
		if ( empty( $onboarding[ $env ]['seller_id'] ) ) {
			return new WP_Error(
				'not_connected',
				sprintf(
					/* translators: %1$s: mode the order was paid in, e.g. "Test" or "Live". */
					__( 'This order was paid in %1$s mode, but PayPal Commerce is not connected in %1$s mode. Connect that account to refund this order.', 'restore-paypal-standard-for-woocommerce' ),
					rpsfw_payment_mode_label( $order_mode )
				)
			);
		}

		$result = $gateway->api->refund_capture( $env, $onboarding[ $env ], $source_id, $amount, $currency, $reason );

		if ( ! $result ) {
			return new WP_Error( 'api_error', __( 'PayPal refund failed. Please try again or refund manually through PayPal.', 'restore-paypal-standard-for-woocommerce' ) );
		}
		if ( isset( $result['success'] ) && false === $result['success'] ) {
			$msg = ! empty( $result['message'] ) ? $result['message'] : __( 'Unknown error', 'restore-paypal-standard-for-woocommerce' );
			return new WP_Error( 'refund_failed', $msg );
		}
		if ( empty( $result['refund_id'] ) ) {
			return new WP_Error( 'refund_failed', __( 'PayPal refund failed. Please try again or refund manually through PayPal.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		$this->last_refund_status = ! empty( $result['status'] ) ? $result['status'] : 'COMPLETED';
		return $result['refund_id'];
	}

	/**
	 * Record the PayPal refund id on the order so the PAYMENT.CAPTURE.REFUNDED
	 * webhook dedupes it (its idempotency check reads _paypal_refund_ids) and
	 * does not add a duplicate note or over-count the refund.
	 *
	 * {@inheritDoc}
	 */
	protected function after_provider_refund( $context, $row, $amount_cents, $refund_id, $owner_order ) {
		$existing = $owner_order->get_meta( '_paypal_refund_ids' );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}
		$existing[] = array(
			'refund_id' => $refund_id,
			'amount'    => $this->from_minor_units( $amount_cents, $owner_order->get_currency() ),
			'status'    => $this->last_refund_status,
			'date'      => current_time( 'mysql' ),
			'source'    => 'panel',
		);
		$owner_order->update_meta_data( '_paypal_refund_ids', $existing );
		$owner_order->save();
	}
}
