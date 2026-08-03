<?php
/**
 * Shared refund panel base for gateway order/subscription admin screens.
 *
 * Renders a "Refund line items" style metabox that lists the payment(s)
 * (Stripe charges / PayPal captures) backing an order or subscription and lets
 * the merchant refund a specific payment for a specific amount, with an
 * optional "Cancel subscription" checkbox (unchecked by default) per row.
 *
 * Gateway specifics (how to enumerate payments and how to issue a refund) are
 * provided by concrete subclasses. All the security-sensitive plumbing (nonce,
 * capability, amount validation, WooCommerce refund bookkeeping and the
 * subscription cancellation) lives here so it is defined once.
 *
 * @package RestorePayPalStandard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RPSFW_Refund_Panel
 */
abstract class RPSFW_Refund_Panel {

	/**
	 * WooCommerce payment gateway id this panel applies to.
	 *
	 * @return string
	 */
	abstract protected function provider_id();

	/**
	 * Unique admin-ajax action name used by this panel.
	 *
	 * @return string
	 */
	abstract protected function ajax_action();

	/**
	 * Unique nonce action string used by this panel.
	 *
	 * @return string
	 */
	abstract protected function nonce_action();

	/**
	 * Metabox id (must be unique per subclass).
	 *
	 * @return string
	 */
	abstract protected function metabox_id();

	/**
	 * Metabox title.
	 *
	 * @return string
	 */
	abstract protected function metabox_title();

	/**
	 * Enumerate the refundable payments for a context (order or subscription).
	 *
	 * Each row is an array with:
	 *   - source_id       string  Provider payment id (charge/capture) to refund.
	 *   - label           string  Human label (product names / description).
	 *   - amount          float   Original amount of the payment.
	 *   - refunded        float   Amount already refunded.
	 *   - refundable      float   Remaining refundable amount.
	 *   - subscription_id int     Linked WC subscription id (0 if none).
	 *   - owner_order_id  int     Order id to record the WC refund against.
	 *
	 * @param WC_Order|WC_Subscription $context Context object.
	 * @return array<int,array>
	 */
	abstract protected function enumerate_rows( $context );

	/**
	 * Issue the actual refund with the provider.
	 *
	 * @param string                   $source_id Provider payment id.
	 * @param float                    $amount    Amount to refund.
	 * @param string                   $currency  Currency code.
	 * @param string                   $reason    Reason.
	 * @param WC_Order|WC_Subscription $context   Context object.
	 * @param array                    $row       The matched row.
	 * @return string|WP_Error Provider refund id on success.
	 */
	abstract protected function do_provider_refund( $source_id, $amount, $currency, $reason, $context, $row );

	/**
	 * Log helper (delegates to the gateway logger).
	 *
	 * @param string $message Message.
	 * @param string $level   Level.
	 */
	abstract protected function log( $message, $level = 'info' );

	/**
	 * Optional hook after a successful provider refund (e.g. ledger updates).
	 *
	 * @param WC_Order|WC_Subscription $context      Context object.
	 * @param array                    $row          Matched row.
	 * @param int                      $amount_cents Amount refunded, in minor units.
	 * @param string                   $refund_id    Provider refund id.
	 * @param WC_Order                 $owner_order  Order the refund is recorded on.
	 */
	protected function after_provider_refund( $context, $row, $amount_cents, $refund_id, $owner_order ) {}

	/**
	 * Per-request cache of computed rows keyed by context id.
	 *
	 * @var array<int,array>
	 */
	private $rows_cache = array();

	/**
	 * Human-readable reasons collected while enumerating rows, surfaced in the
	 * box when nothing resolved. This is what makes an empty panel
	 * self-diagnosing WITHOUT the merchant having to enable the gateway's debug
	 * logging (the gateway logger is silent unless debug logging is on).
	 *
	 * @var array<int,string>
	 */
	protected $diagnostics = array();

	/**
	 * Record a diagnostic reason. Also mirrored to the gateway log (only
	 * visible there when debug logging is enabled) so both audiences are
	 * covered.
	 *
	 * @param string $message Reason a payment could not be resolved.
	 */
	protected function add_diagnostic( $message ) {
		$this->diagnostics[] = $message;
		$this->log( 'Refund panel: ' . $message, 'info' );
	}

	/**
	 * Whether this order legitimately took no payment at all.
	 *
	 * A free trial (or a first period discounted to nothing) produces a paid
	 * order with a zero total and no charge behind it. Resolving no refundable
	 * payment is then the correct answer, not a symptom - so the panel must not
	 * blame a missing webhook or print diagnostics for it.
	 *
	 * Deliberately narrow: it requires a zero total AND a linked subscription.
	 * A zero-total order without a subscription, or a subscription order that
	 * really should have a charge, still gets the troubleshooting path.
	 *
	 * @param WC_Order $context Order or subscription being rendered.
	 * @return bool
	 */
	protected function nothing_was_charged( $context ) {
		if ( ! $context instanceof WC_Order ) {
			return false;
		}

		// A WC_Subscription's own total is the RECURRING amount, which says
		// nothing about what was charged today - the order that took (or did
		// not take) the money is its parent.
		$order = ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $context ) )
			? $context->get_parent()
			: $context;

		if ( ! $order instanceof WC_Order || (float) $order->get_total() > 0 ) {
			return false;
		}

		return $this->context_has_subscription( $order );
	}

	/**
	 * Whether an order is linked to a subscription, native or WCS.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	protected function context_has_subscription( $order ) {
		if ( function_exists( 'rpsfw_get_subscriptions_for_order' ) && rpsfw_get_subscriptions_for_order( $order ) ) {
			return true;
		}

		if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order, 'any' ) ) {
			return true;
		}

		return class_exists( 'RPSFW_Subscriptions_Cart' )
			&& RPSFW_Subscriptions_Cart::order_contains_subscription( $order );
	}

	/**
	 * Register hooks. Call once from the subclass bootstrap.
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'maybe_add_meta_box' ), 45, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . $this->ajax_action(), array( $this, 'handle_ajax' ) );
	}

	/**
	 * Resolve a WC_Order or WC_Subscription from the screen's object.
	 *
	 * @param mixed $post_or_order Post or order/subscription object.
	 * @return WC_Order|false
	 */
	protected function resolve_context( $post_or_order ) {
		if ( $post_or_order instanceof WC_Order ) {
			return $post_or_order;
		}
		if ( $post_or_order instanceof WP_Post ) {
			return wc_get_order( $post_or_order->ID );
		}
		return false;
	}

	/**
	 * Whether an order relates to one of this plugin's native subscriptions
	 * (rpsfw_subscription) — either as the parent (initial) order or a renewal
	 * order. Used so the panel appears even when the WooCommerce Subscriptions
	 * plugin is not active.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	protected function order_has_native_subscription( $order ) {
		if ( function_exists( 'rpsfw_get_subscriptions_for_order' ) && ! empty( rpsfw_get_subscriptions_for_order( $order ) ) ) {
			return true;
		}
		if ( function_exists( 'rpsfw_order_is_subscription_renewal' ) && rpsfw_order_is_subscription_renewal( $order ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Register the metabox on qualifying order/subscription screens.
	 *
	 * @param string $post_type     Screen post type / id.
	 * @param mixed  $post_or_order  Post or order/subscription object.
	 */
	public function maybe_add_meta_box( $post_type, $post_or_order = null ) {
		$context = $this->resolve_context( $post_or_order );
		if ( ! $context instanceof WC_Order ) {
			return;
		}
		if ( $context->get_payment_method() !== $this->provider_id() ) {
			return;
		}

		$is_subscription = ( $context instanceof WC_Subscription )
			|| ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $context ) );

		// The box is offered for ANY order paid through this gateway that has a
		// resolvable payment, so merchants find it in the same place on every
		// order instead of only on subscription ones — which reads as the panel
		// being broken on the orders it is missing from. It sits alongside
		// WooCommerce's own refund button; both write their refunds with
		// wc_create_refund(), so each one's "Refundable" figure already accounts
		// for refunds made through the other.
		//
		// Whether the order is subscription-related still matters below: those
		// always get the box (with an explanation when no payment has resolved
		// yet), while a plain order with nothing refundable gets none. Detects
		// subscriptions created by the WooCommerce Subscriptions plugin AND this
		// plugin's own native subscriptions (rpsfw_subscription).
		$subscription_related = $is_subscription;
		if ( ! $subscription_related ) {
			$contains_sub         = function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $context );
			$contains_renewal     = function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $context );
			$native_related       = $this->order_has_native_subscription( $context );
			$subscription_related = $contains_sub || $contains_renewal || $native_related;
		}

		$this->diagnostics = array();
		$rows = $this->enumerate_rows( $context );
		$this->rows_cache[ $context->get_id() ] = $rows;

		// Subscription-related contexts ALWAYS get the box — when no
		// refundable payment resolved yet (first payment still settling, or
		// a lookup failed) the renderer explains instead of the box silently
		// not existing, which reads as a plugin bug and hides the problem.
		if ( empty( $rows ) ) {
			if ( ! $subscription_related ) {
				return;
			}
			$this->log(
				sprintf(
					'Refund panel: no refundable payments resolved for subscription-related order #%d (payment method %s). The box is shown with an explanatory message.',
					$context->get_id(),
					$context->get_payment_method()
				),
				'info'
			);
		}

		$screen = get_current_screen();
		// Use the 'normal' context with 'default' (not 'high') priority so this
		// panel sorts BELOW WooCommerce's own 'normal'/'high' boxes (Order
		// details and Order items) instead of tying with them — which made it
		// sometimes jump to the top of the screen. 'default' renders it directly
		// under the order details.
		add_meta_box(
			$this->metabox_id(),
			$this->metabox_title(),
			array( $this, 'render_meta_box' ),
			$screen ? $screen->id : null,
			'normal',
			'default'
		);
	}

	/**
	 * Render the refund table.
	 *
	 * @param mixed $post_or_order Post or order/subscription object.
	 */
	public function render_meta_box( $post_or_order ) {
		$context = $this->resolve_context( $post_or_order );
		if ( ! $context instanceof WC_Order ) {
			echo '<p>' . esc_html__( 'Could not be loaded.', 'restore-paypal-standard-for-woocommerce' ) . '</p>';
			return;
		}

		$context_id = $context->get_id();
		$rows       = isset( $this->rows_cache[ $context_id ] ) ? $this->rows_cache[ $context_id ] : $this->enumerate_rows( $context );
		if ( empty( $rows ) ) {
			// Nothing was charged and nothing was ever going to be: a free trial
			// or fully discounted first period. This is the normal, correct
			// outcome, so say so plainly and show none of the troubleshooting
			// noise below - there is nothing to troubleshoot.
			if ( $this->nothing_was_charged( $context ) ) {
				echo '<p>' . esc_html__( 'No payment was taken for this order.', 'restore-paypal-standard-for-woocommerce' ) . '</p>';
				echo '<p>' . esc_html__( 'There is nothing to refund here. The first payment will appear on the renewal order once the trial ends, and can be refunded from that order.', 'restore-paypal-standard-for-woocommerce' ) . '</p>';
				return;
			}

			echo '<p>' . esc_html__( 'No refundable payment was found for this order yet.', 'restore-paypal-standard-for-woocommerce' ) . '</p>';
			echo '<p class="description">' . esc_html__( 'For subscription orders this usually means the first payment has not been reported by the payment processor yet (it arrives via webhook shortly after checkout). Reload this page in a minute or two.', 'restore-paypal-standard-for-woocommerce' ) . '</p>';

			// Surface the exact reason(s) inline so the merchant does not need
			// to enable gateway debug logging to see why. Gated to users who
			// can manage the store.
			if ( ! empty( $this->diagnostics ) && current_user_can( 'manage_woocommerce' ) ) {
				echo '<p class="description" style="margin-top:8px;"><strong>' . esc_html__( 'Diagnostics:', 'restore-paypal-standard-for-woocommerce' ) . '</strong></p>';
				echo '<ul class="ul-disc" style="margin-left:18px;">';
				foreach ( array_unique( $this->diagnostics ) as $reason ) {
					echo '<li><code>' . esc_html( $reason ) . '</code></li>';
				}
				echo '</ul>';
			}
			return;
		}

		$currency = $context->get_currency();
		$decimals = wc_get_price_decimals();
		?>
		<div class="rpsfw-refund-panel"
			data-context-id="<?php echo esc_attr( $context_id ); ?>"
			data-action="<?php echo esc_attr( $this->ajax_action() ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( $this->nonce_action() ) ); ?>">
			<p class="rpsfw-refund-intro">
				<?php
				// Only mention the cancel option when a row actually offers one,
				// so a plain order is not told about a control it has no column
				// for.
				$has_linked_subscription = false;
				foreach ( $rows as $intro_row ) {
					if ( ! empty( $intro_row['subscription_id'] ) ) {
						$has_linked_subscription = true;
						break;
					}
				}
				if ( $has_linked_subscription ) {
					esc_html_e( 'Refund line items. Use this box to refund an individual payment. Tick "Cancel subscription" to also cancel the linked subscription.', 'restore-paypal-standard-for-woocommerce' );
				} else {
					esc_html_e( 'Refund line items. Use this box to refund the payment for this order.', 'restore-paypal-standard-for-woocommerce' );
				}
				?>
			</p>
			<table class="widefat rpsfw-refund-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Payment / items', 'restore-paypal-standard-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Refundable', 'restore-paypal-standard-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Amount to refund', 'restore-paypal-standard-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Cancel subscription', 'restore-paypal-standard-for-woocommerce' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$refundable    = (float) $row['refundable'];
						$is_refundable = $refundable > 0;
						$dec_amount    = wc_format_decimal( $refundable, $decimals );

						$fully_refunded     = ( $refundable <= 0 && (float) $row['amount'] > 0 );
						$partially_refunded = ( (float) $row['refunded'] > 0 && $refundable > 0 );
						$sub_source         = isset( $row['subscription_source'] ) ? $row['subscription_source'] : 'wcs';
						$sub_state          = $row['subscription_id']
							? $this->get_subscription_state( (int) $row['subscription_id'], $sub_source )
							: array( 'inactive' => false, 'cancelled_date' => '' );
						$already_cancelled  = $sub_state['inactive'];
						$status_line        = $this->build_row_status( $fully_refunded, $partially_refunded, $already_cancelled, $sub_state['cancelled_date'] );
						?>
						<tr class="rpsfw-refund-row"
							data-source-id="<?php echo esc_attr( $row['source_id'] ); ?>"
							data-subscription-id="<?php echo esc_attr( $row['subscription_id'] ); ?>"
							data-refundable="<?php echo esc_attr( $dec_amount ); ?>">
							<td>
								<strong><?php echo esc_html( $row['label'] ); ?></strong>
								<br /><code><?php echo esc_html( $row['source_id'] ); ?></code>
								<br /><span class="rpsfw-charge-total"><?php
									/* translators: %s: formatted amount. */
									echo wp_kses_post( sprintf( __( 'Charged: %s', 'restore-paypal-standard-for-woocommerce' ), wc_price( (float) $row['amount'], array( 'currency' => $currency ) ) ) );
								?></span>
							</td>
							<td class="rpsfw-refundable-cell"><?php echo wp_kses_post( wc_price( $refundable, array( 'currency' => $currency ) ) ); ?></td>
							<td>
								<input type="number" step="0.01" min="0"
									class="rpsfw-refund-amount"
									value="<?php echo esc_attr( $dec_amount ); ?>"
									max="<?php echo esc_attr( $dec_amount ); ?>"
									<?php disabled( ! $is_refundable ); ?> />
							</td>
							<td class="rpsfw-cancel-cell">
								<?php if ( $row['subscription_id'] ) : ?>
									<label>
										<input type="checkbox" class="rpsfw-cancel-sub" value="1" <?php disabled( $already_cancelled ); ?> />
										<?php esc_html_e( 'Cancel subscription', 'restore-paypal-standard-for-woocommerce' ); ?>
									</label>
								<?php else : ?>
									<span class="description">&mdash;</span>
								<?php endif; ?>
							</td>
							<td>
								<button type="button" class="button rpsfw-refund-charge-btn" <?php disabled( ! $is_refundable ); ?>>
									<?php esc_html_e( 'Refund', 'restore-paypal-standard-for-woocommerce' ); ?>
								</button>
								<?php if ( $status_line ) : ?>
									<div class="rpsfw-row-status"><?php echo esc_html( $status_line ); ?></div>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<div class="rpsfw-refund-feedback" style="display:none;"></div>
		</div>
		<?php
	}

	/**
	 * Whether the current admin screen is an order or subscription edit screen.
	 *
	 * @param string $screen_id Screen id.
	 * @return bool
	 */
	protected function is_target_screen( $screen_id ) {
		$targets = array( 'shop_order', 'shop_subscription', 'woocommerce_page_wc-orders' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$targets[] = wc_get_page_screen_id( 'shop-order' );
			$targets[] = wc_get_page_screen_id( 'shop-subscription' );
		}
		// HPOS subscriptions screen.
		$targets[] = 'woocommerce_page_wc-orders--shop_subscription';
		return in_array( $screen_id, array_filter( $targets ), true );
	}

	/**
	 * Enqueue the shared panel assets on order/subscription screens.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';
		if ( ! $this->is_target_screen( $screen_id ) ) {
			return;
		}

		// Both gateway panels share these assets; the first to run enqueues and
		// localizes them, the second is a no-op (avoids re-localizing the same
		// global).
		if ( wp_script_is( 'rpsfw-refund-panel', 'enqueued' ) ) {
			return;
		}

		// Version assets by file modification time so direct edits to the
		// JS/CSS always bust the browser/WordPress cache (RPSFW_VERSION does not
		// change on asset-only edits, so cached copies would otherwise persist).
		$css_path = RPSFW_PLUGIN_DIR . 'assets/css/refund-panel.css';
		$js_path  = RPSFW_PLUGIN_DIR . 'assets/js/refund-panel.js';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : RPSFW_VERSION;
		$js_ver   = file_exists( $js_path ) ? (string) filemtime( $js_path ) : RPSFW_VERSION;

		wp_enqueue_style(
			'rpsfw-refund-panel',
			RPSFW_PLUGIN_URL . 'assets/css/refund-panel.css',
			array(),
			$css_ver
		);
		wp_enqueue_script(
			'rpsfw-refund-panel',
			RPSFW_PLUGIN_URL . 'assets/js/refund-panel.js',
			array( 'jquery' ),
			$js_ver,
			true
		);
		wp_localize_script(
			'rpsfw-refund-panel',
			'rpsfwRefundPanel',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'strings'  => array(
					/* translators: %s: amount. */
					'confirm'        => __( 'Refund %s? This returns the money to the customer and cannot be undone.', 'restore-paypal-standard-for-woocommerce' ),
					/* translators: %s: amount. */
					'confirm_cancel' => __( 'Refund %s AND cancel the subscription? This returns the money to the customer and cancels future billing. This cannot be undone.', 'restore-paypal-standard-for-woocommerce' ),
					'processing'     => __( 'Processing refund...', 'restore-paypal-standard-for-woocommerce' ),
					'invalid'        => __( 'Enter a valid amount up to the refundable balance.', 'restore-paypal-standard-for-woocommerce' ),
					'success'        => __( 'Refund completed.', 'restore-paypal-standard-for-woocommerce' ),
					'error'          => __( 'Refund failed.', 'restore-paypal-standard-for-woocommerce' ),
					'fully'          => __( 'Fully refunded', 'restore-paypal-standard-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Handle the AJAX refund request.
	 */
	public function handle_ajax() {
		check_ajax_referer( $this->nonce_action(), 'nonce' );

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to refund orders.', 'restore-paypal-standard-for-woocommerce' ) ) );
		}

		$context_id  = isset( $_POST['context_id'] ) ? absint( $_POST['context_id'] ) : 0;
		$source_id   = isset( $_POST['source_id'] ) ? sanitize_text_field( wp_unslash( $_POST['source_id'] ) ) : '';
		$amount      = isset( $_POST['amount'] ) ? (float) wc_format_decimal( wp_unslash( $_POST['amount'] ) ) : 0.0;
		$cancel_sub  = ! empty( $_POST['cancel_subscription'] );
		$reason      = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';

		$context = $context_id ? wc_get_order( $context_id ) : false;
		if ( ! $context instanceof WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'restore-paypal-standard-for-woocommerce' ) ) );
		}
		if ( $context->get_payment_method() !== $this->provider_id() ) {
			wp_send_json_error( array( 'message' => __( 'This order was not paid with this gateway.', 'restore-paypal-standard-for-woocommerce' ) ) );
		}
		if ( $amount <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid refund amount.', 'restore-paypal-standard-for-woocommerce' ) ) );
		}

		// Re-resolve rows authoritatively — never trust the client for the
		// payment identity or the refundable balance.
		$rows  = $this->enumerate_rows( $context );
		$match = null;
		foreach ( $rows as $row ) {
			if ( (string) $row['source_id'] === $source_id ) {
				$match = $row;
				break;
			}
		}
		if ( ! $match ) {
			wp_send_json_error( array( 'message' => __( 'That payment does not belong to this order.', 'restore-paypal-standard-for-woocommerce' ) ) );
		}

		$currency         = $context->get_currency();
		$amount_cents     = $this->to_minor_units( $amount, $currency );
		$refundable_cents = $this->to_minor_units( (float) $match['refundable'], $currency );

		if ( $amount_cents <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid refund amount.', 'restore-paypal-standard-for-woocommerce' ) ) );
		}
		if ( $amount_cents > $refundable_cents ) {
			wp_send_json_error( array( 'message' => __( 'Refund amount exceeds the refundable balance for this payment.', 'restore-paypal-standard-for-woocommerce' ) ) );
		}

		$owner_order = ! empty( $match['owner_order_id'] ) ? wc_get_order( $match['owner_order_id'] ) : $context;
		if ( ! $owner_order instanceof WC_Order ) {
			$owner_order = $context;
		}

		$refund_id = $this->do_provider_refund( $source_id, $amount, $currency, $reason, $context, $match );
		if ( is_wp_error( $refund_id ) ) {
			$this->log( 'Refund failed for ' . $source_id . ': ' . $refund_id->get_error_message(), 'error' );
			wp_send_json_error( array( 'message' => $refund_id->get_error_message() ) );
		}

		$refunded_amount = (float) $this->from_minor_units( $amount_cents, $currency );

		// Mirror into WooCommerce without re-charging the provider.
		$wc_refund = wc_create_refund( array(
			'amount'         => $refunded_amount,
			'reason'         => $reason ? $reason : sprintf(
				/* translators: 1: label, 2: payment id. */
				__( 'Refund for %1$s (Payment: %2$s)', 'restore-paypal-standard-for-woocommerce' ),
				$match['label'],
				$source_id
			),
			'order_id'       => $owner_order->get_id(),
			'refund_payment' => false,
			'restock_items'  => false,
		) );

		if ( is_wp_error( $wc_refund ) ) {
			$this->log( 'Provider refund succeeded but WC refund record failed for order #' . $owner_order->get_id() . ': ' . $wc_refund->get_error_message(), 'error' );
			$owner_order->add_order_note( sprintf(
				/* translators: 1: amount, 2: payment id, 3: refund id. */
				__( 'Refund of %1$s completed (payment: %2$s, Refund ID: %3$s) but the WooCommerce refund line could not be created automatically. Please reconcile.', 'restore-paypal-standard-for-woocommerce' ),
				wc_price( $refunded_amount, array( 'currency' => $currency ) ),
				$source_id,
				$refund_id
			) );
		} else {
			$owner_order->add_order_note( sprintf(
				/* translators: 1: amount, 2: label, 3: payment id, 4: refund id. */
				__( 'Refund of %1$s for %2$s completed (payment: %3$s, Refund ID: %4$s).', 'restore-paypal-standard-for-woocommerce' ),
				wc_price( $refunded_amount, array( 'currency' => $currency ) ),
				$match['label'],
				$source_id,
				$refund_id
			) );
		}

		$this->after_provider_refund( $context, $match, $amount_cents, $refund_id, $owner_order );

		// Optional: cancel the linked subscription when the merchant asked for
		// it. This is the ONLY path that cancels on refund now — there is no
		// automatic setting-driven cancellation.
		$cancelled  = false;
		$sub_source = isset( $match['subscription_source'] ) ? $match['subscription_source'] : 'wcs';
		if ( $cancel_sub && ! empty( $match['subscription_id'] ) ) {
			$can_native = ( 'native' === $sub_source && function_exists( 'rpsfw_get_subscription' ) );
			$can_wcs    = ( 'native' !== $sub_source && function_exists( 'wcs_get_subscription' ) );
			if ( $can_native || $can_wcs ) {
				$cancelled = $this->cancel_subscription( (int) $match['subscription_id'], $sub_source );
			}
		}

		$message = $cancelled
			? __( 'Refund completed and the subscription was cancelled.', 'restore-paypal-standard-for-woocommerce' )
			: __( 'Refund completed.', 'restore-paypal-standard-for-woocommerce' );

		// Refreshed balances for the UI.
		$refreshed = $this->enumerate_rows( $context );
		$out       = array();
		foreach ( $refreshed as $row ) {
			$r_refundable = (float) $row['refundable'];
			$fully        = ( $r_refundable <= 0 && (float) $row['amount'] > 0 );
			$partial      = ( (float) $row['refunded'] > 0 && $r_refundable > 0 );
			$r_source     = isset( $row['subscription_source'] ) ? $row['subscription_source'] : 'wcs';
			$state        = $row['subscription_id'] ? $this->get_subscription_state( (int) $row['subscription_id'], $r_source ) : array( 'inactive' => false, 'cancelled_date' => '' );
			$out[]        = array(
				'source_id'       => $row['source_id'],
				'refundable'      => $r_refundable,
				'refundable_html' => wp_strip_all_tags( wc_price( $r_refundable, array( 'currency' => $currency ) ) ),
				'status'          => $this->build_row_status( $fully, $partial, $state['inactive'], $state['cancelled_date'] ),
			);
		}

		wp_send_json_success( array(
			'message'   => $message,
			'cancelled' => $cancelled,
			'charges'   => $out,
		) );
	}

	/**
	 * Whether a subscription is already cancelled/expired, plus a display date
	 * for when it was cancelled (if resolvable).
	 *
	 * @param int $subscription_id WC subscription id.
	 * @return array{inactive:bool,cancelled_date:string}
	 */
	protected function get_subscription_state( $subscription_id, $source = 'wcs' ) {
		if ( 'native' === $source ) {
			return $this->get_native_subscription_state( $subscription_id );
		}

		$state = array(
			'inactive'       => false,
			'cancelled_date' => '',
		);
		if ( ! $subscription_id || ! function_exists( 'wcs_get_subscription' ) ) {
			return $state;
		}
		$sub = wcs_get_subscription( $subscription_id );
		if ( ! $sub ) {
			return $state;
		}

		$state['inactive'] = $sub->has_status( array( 'cancelled', 'expired' ) );
		if ( $state['inactive'] ) {
			foreach ( array( 'cancelled', 'end' ) as $type ) {
				$ts = 0;
				if ( method_exists( $sub, 'get_time' ) ) {
					$ts = (int) $sub->get_time( $type );
				}
				if ( ! $ts ) {
					$date = $sub->get_date( $type );
					$ts   = $date ? (int) strtotime( $date ) : 0;
				}
				if ( $ts ) {
					$state['cancelled_date'] = date_i18n( wc_date_format(), $ts );
					break;
				}
			}
		}
		return $state;
	}

	/**
	 * Compose the per-row status line shown below the Refund button, e.g.
	 * "Refunded", "Refunded and cancelled on July 1, 2026", "Cancelled".
	 *
	 * @param bool   $fully_refunded     Charge fully refunded.
	 * @param bool   $partially_refunded Charge partially refunded.
	 * @param bool   $cancelled          Subscription cancelled/expired.
	 * @param string $cancelled_date     Display date of cancellation (may be '').
	 * @return string
	 */
	protected function build_row_status( $fully_refunded, $partially_refunded, $cancelled, $cancelled_date ) {
		$refund_txt = '';
		if ( $fully_refunded ) {
			$refund_txt = __( 'Refunded', 'restore-paypal-standard-for-woocommerce' );
		} elseif ( $partially_refunded ) {
			$refund_txt = __( 'Partially refunded', 'restore-paypal-standard-for-woocommerce' );
		}

		if ( $cancelled ) {
			$cancel_txt = $cancelled_date
				/* translators: %s: cancellation date. */
				? sprintf( __( 'cancelled on %s', 'restore-paypal-standard-for-woocommerce' ), $cancelled_date )
				: __( 'cancelled', 'restore-paypal-standard-for-woocommerce' );

			if ( $refund_txt ) {
				/* translators: 1: "Refunded" or "Partially refunded", 2: "cancelled on <date>". */
				return sprintf( __( '%1$s and %2$s', 'restore-paypal-standard-for-woocommerce' ), $refund_txt, $cancel_txt );
			}
			return ucfirst( $cancel_txt );
		}

		return $refund_txt;
	}

	/**
	 * Cancel a WC subscription (which cascades to the provider via the
	 * gateway's woocommerce_subscription_status_cancelled handler).
	 *
	 * @param int $subscription_id WC subscription id.
	 * @return bool True if a cancellation was performed.
	 */
	protected function cancel_subscription( $subscription_id, $source = 'wcs' ) {
		if ( 'native' === $source ) {
			return $this->cancel_native_subscription( $subscription_id );
		}

		$sub = wcs_get_subscription( $subscription_id );
		if ( ! $sub || $sub->has_status( array( 'cancelled', 'expired' ) ) ) {
			return false;
		}
		try {
			$sub->update_status(
				'cancelled',
				__( 'Cancelled by the merchant together with a refund.', 'restore-paypal-standard-for-woocommerce' )
			);
			return true;
		} catch ( Exception $e ) {
			$this->log( 'Failed to cancel subscription #' . $subscription_id . ' on refund: ' . $e->getMessage(), 'error' );
			return false;
		}
	}

	/**
	 * State of one of this plugin's native subscriptions (rpsfw_subscription).
	 *
	 * @param int $subscription_id Native subscription id.
	 * @return array{inactive:bool,cancelled_date:string}
	 */
	protected function get_native_subscription_state( $subscription_id ) {
		$state = array(
			'inactive'       => false,
			'cancelled_date' => '',
		);
		if ( ! $subscription_id || ! function_exists( 'rpsfw_get_subscription' ) ) {
			return $state;
		}
		$sub = rpsfw_get_subscription( $subscription_id );
		if ( ! $sub ) {
			return $state;
		}

		$state['inactive'] = $sub->has_status( array( 'cancelled', 'expired' ) );
		if ( $state['inactive'] && $sub->get_end_date() ) {
			$ts = strtotime( $sub->get_end_date() . ' UTC' );
			if ( $ts ) {
				$state['cancelled_date'] = date_i18n( wc_date_format(), $ts + (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
			}
		}
		return $state;
	}

	/**
	 * Cancel one of this plugin's native subscriptions via the manager (which
	 * propagates the cancellation to the payment processor first).
	 *
	 * @param int $subscription_id Native subscription id.
	 * @return bool True if a cancellation was performed.
	 */
	protected function cancel_native_subscription( $subscription_id ) {
		if ( ! function_exists( 'rpsfw_get_subscription' ) || ! class_exists( 'RPSFW_Subscriptions_Manager' ) ) {
			return false;
		}
		$sub = rpsfw_get_subscription( $subscription_id );
		if ( ! $sub || $sub->has_status( array( 'cancelled', 'expired' ) ) ) {
			return false;
		}
		$result = RPSFW_Subscriptions_Manager::cancel( $sub, 'admin' );
		if ( is_wp_error( $result ) ) {
			$this->log( 'Failed to cancel native subscription #' . $subscription_id . ' on refund: ' . $result->get_error_message(), 'error' );
			return false;
		}
		return true;
	}

	/**
	 * Convert a decimal amount to minor units for exact comparisons.
	 *
	 * Zero-decimal currencies (JPY, etc.) are handled by wc_get_price_decimals
	 * falling back safely; provider subclasses that need Stripe's precise
	 * zero-decimal handling should override to_minor_units/from_minor_units.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency.
	 * @return int
	 */
	protected function to_minor_units( $amount, $currency ) {
		return (int) round( (float) $amount * 100 );
	}

	/**
	 * Convert minor units back to a decimal amount.
	 *
	 * @param int    $cents    Minor units.
	 * @param string $currency Currency.
	 * @return float
	 */
	protected function from_minor_units( $cents, $currency ) {
		return round( (int) $cents / 100, 2 );
	}
}
