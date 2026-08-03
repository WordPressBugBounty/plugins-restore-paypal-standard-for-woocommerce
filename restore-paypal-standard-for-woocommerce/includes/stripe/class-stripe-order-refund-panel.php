<?php
/**
 * Stripe per-charge refund panel for orders and subscriptions.
 *
 * The independent multi-schedule subscription flow charges each subscription's
 * first invoice as a SEPARATE Stripe charge under a single parent WooCommerce
 * order. WooCommerce's built-in refund box only takes one blanket amount for
 * the whole order and cannot target an individual charge. This panel lists each
 * backing Stripe charge and lets the merchant refund a specific one, with an
 * optional "Cancel subscription" checkbox per row.
 *
 * Extends the shared RPSFW_Refund_Panel base, which owns the metabox, assets
 * and AJAX plumbing. This subclass provides the Stripe-specific bits: how to
 * enumerate charges and how to issue a Stripe refund.
 *
 * @package RestorePayPalStandard
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __FILE__ ) . '/../class-rpsfw-refund-panel.php';

/**
 * Class RPSFW_Stripe_Order_Refund_Panel
 */
class RPSFW_Stripe_Order_Refund_Panel extends RPSFW_Refund_Panel {

	const LEDGER_META = '_rpsfw_stripe_charge_refunds';

	/**
	 * Bootstrap.
	 */
	public static function init() {
		$self = new self();
		$self->register();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function provider_id() {
		return 'rpsfw_stripe';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function ajax_action() {
		return 'rpsfw_stripe_refund_charge';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function nonce_action() {
		return 'rpsfw-stripe-refund-charge';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function metabox_id() {
		return 'rpsfw-stripe-refund-panel';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function metabox_title() {
		return __( 'Stripe Payments & Subscriptions', 'restore-paypal-standard-for-woocommerce' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function log( $message, $level = 'info' ) {
		if ( class_exists( 'RPSFW_Gateway_Stripe' ) ) {
			RPSFW_Gateway_Stripe::log( $message, $level );
		}
	}

	/**
	 * Use Stripe's zero-decimal-aware conversion.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency.
	 * @return int
	 */
	protected function to_minor_units( $amount, $currency ) {
		return (int) RPSFW_Stripe_API::get_stripe_amount( $amount, $currency );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $cents    Minor units.
	 * @param string $currency Currency.
	 * @return float
	 */
	protected function from_minor_units( $cents, $currency ) {
		return (float) RPSFW_Stripe_API::format_stripe_amount( (int) $cents, $currency );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function enumerate_rows( $context ) {
		// Look this order up against the account it was actually paid in. A
		// store that has since switched test <-> live would otherwise query the
		// wrong Stripe account, where none of these ids exist, and report that
		// no charge could be resolved.
		$order = ( $context instanceof WC_Subscription ) ? $context->get_parent() : $context;
		if ( $order instanceof WC_Order ) {
			RPSFW_Stripe_API::set_request_mode( rpsfw_get_order_payment_mode( $order ) );
		}

		if ( $context instanceof WC_Subscription ) {
			return $this->rows_for_subscription( $context );
		}
		return $this->rows_for_order( $context );
	}

	/**
	 * Rows for an order. Regular, single-schedule initial, and renewal orders
	 * resolve to exactly one charge on the order itself. Only the independent
	 * multi-schedule parent order (which has no order-level charge) falls back
	 * to one charge per subscription.
	 *
	 * Mirrors the gateway's collect_refund_charges precedence so behaviour is
	 * identical to the standard refund box.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private function rows_for_order( $order ) {
		// 1. Order-level charge (regular / single-schedule initial / renewal).
		$source = $this->resolve_order_charge_source( $order );
		if ( $source ) {
			$charge = $this->resolve_charge_object( $source );
			if ( $charge && ! empty( $charge->id ) ) {
				$link = $this->linked_subscription( $order );
				$row  = $this->build_row_from_charge( $charge, $order, $link['id'], $order->get_id(), $link['source'] );
				if ( $row ) {
					return array( $row );
				}
			}
		}

		// 2. Parent order without an order-level charge: one charge per
		// subscription. Covers WCS independent multi-schedule orders AND
		// this plugin's native subscriptions (single or multiple per order).
		$rows = array();
		$seen = array();

		if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			$subs = wcs_get_subscriptions_for_order( $order, array( 'order_type' => 'parent' ) );
			foreach ( (array) $subs as $sub ) {
				$row = $this->build_charge_row( $sub, $order->get_id() );
				if ( ! $row || isset( $seen[ $row['source_id'] ] ) ) {
					continue;
				}
				$seen[ $row['source_id'] ] = true;
				$rows[] = $row;
			}
		}

		if ( empty( $rows ) && function_exists( 'rpsfw_get_subscriptions_for_order' ) ) {
			foreach ( rpsfw_get_subscriptions_for_order( $order ) as $record ) {
				$row = $this->build_native_charge_row( $record, $order );
				if ( ! $row || isset( $seen[ $row['source_id'] ] ) ) {
					continue;
				}
				$seen[ $row['source_id'] ] = true;
				$rows[] = $row;
			}
		}

		// 3. Last-resort fallback: resolve charges straight from the invoice
		// ids the multi-schedule finalize stamped on the ORDER
		// (_rpsfw_stripe_processed_invoices). This does not depend on the
		// subscription records carrying charge meta, nor on
		// retrieve_subscription/get_gateway_sub_id succeeding — so it recovers
		// rows for older orders created before per-record charge stamping, and
		// whenever the per-record path fails. Rows here carry no subscription
		// link (no cancel checkbox) but are fully refundable.
		if ( empty( $rows ) ) {
			$rows = array_merge( $rows, $this->rows_from_order_invoices( $order, $seen ) );
		}

		if ( empty( $rows ) ) {
			$mode = rpsfw_get_order_payment_mode( $order );
			$this->add_diagnostic( sprintf(
				'No Stripe charge could be resolved for order #%d through any path (order meta, subscription records, or stored invoices), searching the %s account.%s',
				$order->get_id(),
				$mode,
				RPSFW_Stripe_API::has_credentials_for_mode( $mode )
					? ''
					: sprintf( ' No %s mode secret key is stored, so no lookup could be made.', $mode )
			) );
		}

		return $rows;
	}

	/**
	 * Build rows from the invoice ids stored on the order by the multi-schedule
	 * finalize (_rpsfw_stripe_processed_invoices). Independent of the
	 * subscription records — used as a recovery path when the per-record
	 * resolution yields nothing.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $seen  Source ids already added (by reference).
	 * @return array
	 */
	private function rows_from_order_invoices( $order, &$seen ) {
		$invoices = (array) $order->get_meta( '_rpsfw_stripe_processed_invoices' );
		if ( empty( $invoices ) ) {
			return array();
		}

		$rows = array();
		foreach ( $invoices as $invoice_id ) {
			if ( ! is_string( $invoice_id ) || '' === $invoice_id ) {
				continue;
			}
			$refs   = RPSFW_Stripe_API::get_invoice_payment_refs( $invoice_id );
			$source = ! empty( $refs['charge'] ) ? $refs['charge'] : ( ! empty( $refs['payment_intent'] ) ? $refs['payment_intent'] : '' );
			if ( ! $source ) {
				// An empty result here is ambiguous: it can mean a genuine $0
				// invoice OR that the Stripe lookup failed (e.g. an expired /
				// revoked API key — see any API errors listed above). Do not
				// assert "$0" as if it were confirmed.
				$this->add_diagnostic( sprintf( 'Invoice %s (order #%d) returned no charge or PaymentIntent. This is either a $0 invoice (nothing to refund) or the Stripe lookup failed — check for API errors above.', $invoice_id, $order->get_id() ) );
				continue;
			}
			$charge = $this->resolve_charge_object( $source );
			if ( ! $charge || empty( $charge->id ) || isset( $seen[ $charge->id ] ) ) {
				continue;
			}
			$seen[ $charge->id ] = true;
			$row = $this->build_row_from_charge( $charge, $order, 0, $order->get_id(), 'native' );
			if ( $row ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * Build a charge row for a native subscription record
	 * (rpsfw_subscription). The charge source is read from the record's
	 * meta when the checkout stored it, otherwise resolved live from the
	 * Stripe subscription's latest invoice and cached back on the record.
	 *
	 * @param RPSFW_Subscription $record Native subscription record.
	 * @param WC_Order           $order  Parent order (owns the WC refund).
	 * @return array|null
	 */
	private function build_native_charge_row( $record, $order ) {
		$record_id = $record->get_id();

		// Fully local fast path: the charge id AND the captured amount were
		// stored on the record at checkout. The already-refunded total lives on
		// the order's per-charge ledger (kept current by the charge.refunded
		// webhook, including dashboard refunds). So a normal render needs ZERO
		// Stripe API calls — it is a plain post-meta lookup.
		$stored_charge = get_post_meta( $record_id, '_rpsfw_stripe_charge_id', true );
		$stored_amount = (float) get_post_meta( $record_id, '_rpsfw_stripe_charge_amount', true );
		if ( $stored_charge && 0 === strpos( $stored_charge, 'ch_' ) && $stored_amount > 0 ) {
			$currency = (string) get_post_meta( $record_id, '_rpsfw_stripe_charge_currency', true );
			if ( ! $currency ) {
				$currency = $record->get_currency();
			}
			$refunded = $this->refunded_from_ledger( $order, $stored_charge );
			return $this->build_native_row_array( $record, $order, $stored_charge, $stored_amount, $refunded );
		}

		// --- Backfill path (legacy orders created before amount caching, or a
		// first invoice that had not settled at checkout). Resolve once via the
		// API, then persist charge id + amount so every later load is local. ---
		$source = $stored_charge;
		if ( ! $source ) {
			$source = get_post_meta( $record_id, '_rpsfw_stripe_payment_intent_id', true );
		}

		if ( ! $source ) {
			$stripe_sub_id = $record->get_gateway_sub_id();
			if ( ! $stripe_sub_id ) {
				$this->add_diagnostic( sprintf( 'Subscription record #%d has no Stripe subscription id stored.', $record_id ) );
				return null;
			}
			// Expand latest_invoice so we can read the charge link (and the
			// invoice amount) directly without a second round-trip.
			$stripe_sub = RPSFW_Stripe_API::retrieve_subscription( $stripe_sub_id, array( 'expand' => array( 'latest_invoice' ) ) );
			if ( is_wp_error( $stripe_sub ) ) {
				$this->add_diagnostic( sprintf( 'Could not retrieve Stripe subscription %s (record #%d): %s', $stripe_sub_id, $record_id, $stripe_sub->get_error_message() ) );
				return null;
			}
			if ( empty( $stripe_sub->latest_invoice ) ) {
				$this->add_diagnostic( sprintf( 'Stripe subscription %s has no invoice yet (record #%d) — the first payment has not been billed.', $stripe_sub_id, $record_id ) );
				return null;
			}
			$inv_id = is_object( $stripe_sub->latest_invoice ) ? $stripe_sub->latest_invoice->id : (string) $stripe_sub->latest_invoice;
			$refs   = RPSFW_Stripe_API::get_invoice_payment_refs( $inv_id );
			if ( ! empty( $refs['charge'] ) ) {
				$source = $refs['charge'];
			} elseif ( ! empty( $refs['payment_intent'] ) ) {
				$source = $refs['payment_intent'];
			}
			if ( ! $source ) {
				$this->add_diagnostic( sprintf( 'Invoice %s (subscription %s, record #%d) returned no charge or PaymentIntent. This is either a $0 first invoice (trial/free sign-up, nothing to refund) or the Stripe lookup failed — check for API errors above.', $inv_id, $stripe_sub_id, $record_id ) );
				return null;
			}
			// Cache so later admin loads skip the API round-trips.
			update_post_meta( $record_id, 0 === strpos( $source, 'ch_' ) ? '_rpsfw_stripe_charge_id' : '_rpsfw_stripe_payment_intent_id', $source );
		}

		$charge = $this->resolve_charge_object( $source );
		if ( ! $charge || empty( $charge->id ) ) {
			$this->add_diagnostic( sprintf( 'Stored payment reference %s (record #%d) did not resolve to a Stripe charge.', $source, $record_id ) );
			return null;
		}

		$currency = isset( $charge->currency ) ? strtoupper( $charge->currency ) : $record->get_currency();
		$amount   = isset( $charge->amount ) ? (float) RPSFW_Stripe_API::format_stripe_amount( (int) $charge->amount, $currency ) : 0.0;
		$refunded = isset( $charge->amount_refunded ) ? (float) RPSFW_Stripe_API::format_stripe_amount( (int) $charge->amount_refunded, $currency ) : 0.0;

		// Persist what we just resolved so subsequent admin loads are fully
		// local (no more API calls for this record).
		update_post_meta( $record_id, '_rpsfw_stripe_charge_id', $charge->id );
		if ( $amount > 0 ) {
			update_post_meta( $record_id, '_rpsfw_stripe_charge_amount', $amount );
			update_post_meta( $record_id, '_rpsfw_stripe_charge_currency', $currency );
		}

		return $this->build_native_row_array( $record, $order, $charge->id, $amount, $refunded );
	}

	/**
	 * Amount already refunded for a charge, read from the order's local
	 * per-charge ledger (_rpsfw_stripe_charge_refunds). The ledger is updated
	 * both by this panel and by the charge.refunded webhook, so it reflects
	 * refunds made anywhere — including directly in the Stripe dashboard —
	 * without polling the API.
	 *
	 * @param WC_Order $order     Order carrying the ledger.
	 * @param string   $charge_id Stripe charge id.
	 * @return float
	 */
	private function refunded_from_ledger( $order, $charge_id ) {
		$ledger = (array) $order->get_meta( self::LEDGER_META );
		return isset( $ledger[ $charge_id ] ) ? (float) $ledger[ $charge_id ] : 0.0;
	}

	/**
	 * Assemble a native subscription panel row from resolved values.
	 *
	 * @param RPSFW_Subscription $record    Native subscription record.
	 * @param WC_Order           $order     Parent order (owns the WC refund).
	 * @param string             $source_id Stripe charge id to refund.
	 * @param float              $amount    Amount originally captured.
	 * @param float              $refunded  Amount already refunded.
	 * @return array
	 */
	private function build_native_row_array( $record, $order, $source_id, $amount, $refunded ) {
		$refundable = max( 0, round( $amount - $refunded, 2 ) );

		$label = $record->get_item_name();
		if ( ! $label ) {
			$label = sprintf(
				/* translators: %s: Stripe charge id. */
				__( 'Payment %s', 'restore-paypal-standard-for-woocommerce' ),
				$source_id
			);
		}
		if ( $record->get_quantity() > 1 ) {
			$label .= ' × ' . $record->get_quantity();
		}

		return array(
			'source_id'           => $source_id,
			'label'               => $label,
			'amount'              => $amount,
			'refunded'            => $refunded,
			'refundable'          => $refundable,
			'subscription_id'     => $record->get_id(),
			'subscription_source' => 'native',
			'owner_order_id'      => $order->get_id(),
		);
	}

	/**
	 * Resolve the order-level Stripe charge/PaymentIntent source (mirrors the
	 * gateway's resolve_refund_source). Returns '' for the independent
	 * multi-schedule parent order, which has no single order-level charge.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private function resolve_order_charge_source( $order ) {
		$charge_id = $order->get_meta( '_rpsfw_stripe_charge_id' );
		if ( $charge_id ) {
			return $charge_id;
		}
		$pi = $order->get_meta( '_rpsfw_stripe_payment_intent_id' );
		if ( $pi && 0 === strpos( $pi, 'pi_' ) ) {
			return $pi;
		}
		$txn = $order->get_transaction_id();
		if ( $txn && ( 0 === strpos( $txn, 'pi_' ) || 0 === strpos( $txn, 'ch_' ) ) ) {
			return $txn;
		}
		$invoice_id = $order->get_meta( '_rpsfw_stripe_invoice_id' );
		if ( ! $invoice_id && $txn && 0 === strpos( $txn, 'in_' ) ) {
			$invoice_id = $txn;
		}
		if ( $invoice_id ) {
			$refs = RPSFW_Stripe_API::get_invoice_payment_refs( $invoice_id );
			if ( ! empty( $refs['charge'] ) ) {
				return $refs['charge'];
			}
			if ( ! empty( $refs['payment_intent'] ) ) {
				return $refs['payment_intent'];
			}
		}
		return '';
	}

	/**
	 * The subscription an order belongs to (parent or renewal), for the
	 * "Cancel subscription" checkbox. Resolves both WooCommerce Subscriptions
	 * and this plugin's native subscriptions.
	 *
	 * @param WC_Order $order Order.
	 * @return array{id:int,source:string} Subscription id (0 if none) and source ('wcs'|'native').
	 */
	private function linked_subscription( $order ) {
		// WooCommerce Subscriptions first (parent or renewal order).
		if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			$subs = wcs_get_subscriptions_for_order( $order, array( 'order_type' => array( 'parent', 'renewal' ) ) );
			if ( ! empty( $subs ) ) {
				$first = reset( $subs );
				return array( 'id' => $first->get_id(), 'source' => 'wcs' );
			}
		}

		// Native: parent (initial) order → subscription(s) created from it.
		if ( function_exists( 'rpsfw_get_subscriptions_for_order' ) ) {
			$native = rpsfw_get_subscriptions_for_order( $order );
			if ( ! empty( $native ) ) {
				return array( 'id' => $native[0]->get_id(), 'source' => 'native' );
			}
		}

		// Native: renewal order → parent subscription id stored on the order.
		$renewal_of = (int) $order->get_meta( '_rpsfw_subscription_renewal' );
		if ( $renewal_of ) {
			return array( 'id' => $renewal_of, 'source' => 'native' );
		}

		return array( 'id' => 0, 'source' => 'wcs' );
	}

	/**
	 * Single row for a subscription's own Stripe charge (recorded against the
	 * order that actually holds the payment — the parent order).
	 *
	 * @param WC_Subscription $sub Subscription.
	 * @return array
	 */
	private function rows_for_subscription( $sub ) {
		$owner_order_id = $sub->get_parent_id();
		if ( ! $owner_order_id ) {
			$last = $sub->get_last_order( 'id' );
			$owner_order_id = $last ? $last : $sub->get_id();
		}
		$row = $this->build_charge_row( $sub, $owner_order_id );
		return $row ? array( $row ) : array();
	}

	/**
	 * Build a charge row from a subscription's recorded Stripe charge.
	 *
	 * @param WC_Subscription $sub            Subscription.
	 * @param int             $owner_order_id Order id to record the refund on.
	 * @return array|null
	 */
	private function build_charge_row( $sub, $owner_order_id ) {
		$source = $this->resolve_subscription_charge_source( $sub );
		if ( ! $source ) {
			return null;
		}
		$charge = $this->resolve_charge_object( $source );
		if ( ! $charge || empty( $charge->id ) ) {
			return null;
		}
		return $this->build_row_from_charge( $charge, $sub, $sub->get_id(), $owner_order_id );
	}

	/**
	 * Build a panel row from a resolved Stripe charge object.
	 *
	 * @param object                   $charge              Stripe charge.
	 * @param WC_Order|WC_Subscription $label_source        Object to derive the item label from.
	 * @param int                      $subscription_id     Linked subscription id (0 if none).
	 * @param int                      $owner_order_id      Order to record the WC refund on.
	 * @param string                   $subscription_source 'wcs' (WooCommerce Subscriptions) or 'native'.
	 * @return array|null
	 */
	private function build_row_from_charge( $charge, $label_source, $subscription_id, $owner_order_id, $subscription_source = 'wcs' ) {
		$currency   = isset( $charge->currency ) ? strtoupper( $charge->currency ) : $label_source->get_currency();
		$amount     = isset( $charge->amount ) ? (float) RPSFW_Stripe_API::format_stripe_amount( (int) $charge->amount, $currency ) : 0.0;
		$refunded   = isset( $charge->amount_refunded ) ? (float) RPSFW_Stripe_API::format_stripe_amount( (int) $charge->amount_refunded, $currency ) : 0.0;
		$refundable = max( 0, round( $amount - $refunded, 2 ) );

		$names = array();
		foreach ( $label_source->get_items() as $item ) {
			$names[] = $item->get_name();
		}
		$label = ! empty( $names ) ? implode( ', ', $names ) : sprintf(
			/* translators: %s: Stripe charge id. */
			__( 'Payment %s', 'restore-paypal-standard-for-woocommerce' ),
			$charge->id
		);

		return array(
			'source_id'           => $charge->id,
			'label'               => $label,
			'amount'              => $amount,
			'refunded'            => $refunded,
			'refundable'          => $refundable,
			'subscription_id'     => $subscription_id,
			'subscription_source' => $subscription_source,
			'owner_order_id'      => $owner_order_id,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function do_provider_refund( $source_id, $amount, $currency, $reason, $context, $row ) {
		$amount_cents = $this->to_minor_units( $amount, $currency );

		// enumerate_rows() has already pointed the API at the account this order
		// was paid in. Fail with a message that names the mode when the store is
		// not connected to that account, instead of surfacing a bare API error
		// that looks like the charge itself is at fault.
		$owner_order = ! empty( $row['owner_order_id'] ) ? wc_get_order( $row['owner_order_id'] ) : $context;
		if ( ! $owner_order instanceof WC_Order ) {
			$owner_order = $context;
		}
		$order_mode = rpsfw_get_order_payment_mode( $owner_order );
		if ( ! RPSFW_Stripe_API::get_secret_key( $order_mode ) ) {
			return new WP_Error(
				'not_connected',
				sprintf(
					/* translators: %1$s: mode the order was paid in, e.g. "Test" or "Live". */
					__( 'This order was paid in %1$s mode, but Stripe is not connected in %1$s mode. Connect that account to refund this order.', 'restore-paypal-standard-for-woocommerce' ),
					rpsfw_payment_mode_label( $order_mode )
				)
			);
		}

		// Capture the charge's prior refunded total for the ledger update.
		$before = $this->resolve_charge_object( $source_id );
		$this->prior_refunded_cents = ( $before && isset( $before->amount_refunded ) ) ? (int) $before->amount_refunded : 0;

		$refund = RPSFW_Stripe_API::create_refund( $source_id, $amount_cents, $reason );
		if ( is_wp_error( $refund ) ) {
			return $refund;
		}
		return isset( $refund->id ) ? $refund->id : 'refund';
	}

	/**
	 * Prior refunded amount (minor units) captured during do_provider_refund.
	 *
	 * @var int
	 */
	private $prior_refunded_cents = 0;

	/**
	 * Update the per-charge ledger so the charge.refunded webhook treats this
	 * refund as already recorded (delta <= 0) and does not double-count it.
	 *
	 * {@inheritDoc}
	 */
	protected function after_provider_refund( $context, $row, $amount_cents, $refund_id, $owner_order ) {
		$currency             = $owner_order->get_currency();
		$new_cumulative_cents = $this->prior_refunded_cents + (int) $amount_cents;
		$ledger               = (array) $owner_order->get_meta( self::LEDGER_META );
		$ledger[ $row['source_id'] ] = (float) RPSFW_Stripe_API::format_stripe_amount( $new_cumulative_cents, $currency );
		$owner_order->update_meta_data( self::LEDGER_META, $ledger );
		$owner_order->save();
	}

	/**
	 * Resolve the charge/PaymentIntent source id recorded on a subscription.
	 *
	 * @param WC_Subscription $sub Subscription.
	 * @return string
	 */
	private function resolve_subscription_charge_source( $sub ) {
		$charge_id = $sub->get_meta( '_rpsfw_stripe_charge_id' );
		if ( $charge_id ) {
			return $charge_id;
		}
		$pi = $sub->get_meta( '_rpsfw_stripe_payment_intent_id' );
		if ( $pi ) {
			return $pi;
		}

		$stripe_sub_id = $sub->get_meta( '_rpsfw_stripe_subscription_id' );
		if ( ! $stripe_sub_id ) {
			return '';
		}
		$stripe_sub = RPSFW_Stripe_API::retrieve_subscription( $stripe_sub_id, array() );
		if ( is_wp_error( $stripe_sub ) || empty( $stripe_sub->latest_invoice ) ) {
			return '';
		}
		$inv_id = is_object( $stripe_sub->latest_invoice ) ? $stripe_sub->latest_invoice->id : (string) $stripe_sub->latest_invoice;
		$refs   = RPSFW_Stripe_API::get_invoice_payment_refs( $inv_id );
		if ( ! empty( $refs['charge'] ) ) {
			return $refs['charge'];
		}
		if ( ! empty( $refs['payment_intent'] ) ) {
			return $refs['payment_intent'];
		}
		return '';
	}

	/**
	 * Resolve a source id (ch_ or pi_) to its Stripe Charge object.
	 *
	 * @param string $source Charge or PaymentIntent id.
	 * @return object|null
	 */
	private function resolve_charge_object( $source ) {
		if ( 0 === strpos( $source, 'ch_' ) ) {
			$charge = RPSFW_Stripe_API::retrieve_charge( $source );
			return is_wp_error( $charge ) ? null : $charge;
		}
		if ( 0 === strpos( $source, 'pi_' ) ) {
			$intent = RPSFW_Stripe_API::retrieve_payment_intent( $source );
			if ( is_wp_error( $intent ) ) {
				return null;
			}
			return RPSFW_Stripe_API::get_charge_from_intent( $intent );
		}
		return null;
	}
}
