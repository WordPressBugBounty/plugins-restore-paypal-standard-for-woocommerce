<?php
/**
 * My Account area for native subscriptions.
 *
 * Adds a "Subscriptions" tab (list) and a view-subscription endpoint with
 * details, related orders and customer actions (cancel / pause / resume /
 * reactivate, according to settings and subscription state). Actions are
 * propagated to the payment processor through the manager.
 *
 * Endpoint slugs are prefixed (rpsfw-subscriptions) so they never collide
 * with the WooCommerce Subscriptions plugin's "subscriptions" endpoint
 * when both are present.
 *
 * @package Restore_PayPal_Standard_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RPSFW_Subscriptions_My_Account
 */
class RPSFW_Subscriptions_My_Account {

	const ENDPOINT_LIST = 'rpsfw-subscriptions';
	const ENDPOINT_VIEW = 'rpsfw-view-subscription';

	/**
	 * Wire up.
	 */
	public static function init() {
		// Let WooCommerce register + resolve the endpoints.
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'add_query_vars' ) );

		// Menu item (after Orders).
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_menu_item' ) );

		// Endpoint content + titles.
		add_action( 'woocommerce_account_' . self::ENDPOINT_LIST . '_endpoint', array( __CLASS__, 'render_list' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT_VIEW . '_endpoint', array( __CLASS__, 'render_view' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT_LIST . '_title', array( __CLASS__, 'list_title' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT_VIEW . '_title', array( __CLASS__, 'view_title' ) );

		// Customer actions (nonce'd links).
		add_action( 'template_redirect', array( __CLASS__, 'handle_customer_action' ) );

		// One-time rewrite flush after the endpoints were introduced.
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_flush_rewrite_rules' ) );
	}

	/**
	 * Register the endpoints with WooCommerce's query handling.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function add_query_vars( $vars ) {
		$vars[ self::ENDPOINT_LIST ] = self::ENDPOINT_LIST;
		$vars[ self::ENDPOINT_VIEW ] = self::ENDPOINT_VIEW;
		return $vars;
	}

	/**
	 * Flush rewrite rules once so the new endpoints resolve.
	 */
	public static function maybe_flush_rewrite_rules() {
		if ( 'yes' !== get_option( 'rpsfw_subscriptions_endpoints_flushed', 'no' ) ) {
			flush_rewrite_rules();
			update_option( 'rpsfw_subscriptions_endpoints_flushed', 'yes', false );
		}
	}

	/**
	 * Add the Subscriptions menu item after Orders.
	 *
	 * Hidden when the current user has no subscriptions AND purchasing is
	 * not possible (module servicing legacy records only).
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public static function add_menu_item( $items ) {
		if ( ! rpsfw_native_subscriptions_active() && empty( rpsfw_get_subscriptions_for_user( get_current_user_id() ) ) ) {
			return $items;
		}

		$new_items = array();
		foreach ( $items as $key => $label ) {
			$new_items[ $key ] = $label;
			if ( 'orders' === $key ) {
				$new_items[ self::ENDPOINT_LIST ] = __( 'Subscriptions', 'restore-paypal-standard-for-woocommerce' );
			}
		}
		// Fallback when there is no Orders item.
		if ( ! isset( $new_items[ self::ENDPOINT_LIST ] ) ) {
			$new_items[ self::ENDPOINT_LIST ] = __( 'Subscriptions', 'restore-paypal-standard-for-woocommerce' );
		}
		return $new_items;
	}

	/** @return string */
	public static function list_title() {
		return __( 'Subscriptions', 'restore-paypal-standard-for-woocommerce' );
	}

	/** @return string */
	public static function view_title() {
		global $wp;
		$subscription_id = isset( $wp->query_vars[ self::ENDPOINT_VIEW ] ) ? absint( $wp->query_vars[ self::ENDPOINT_VIEW ] ) : 0;
		/* translators: %s: subscription id */
		return sprintf( __( 'Subscription #%s', 'restore-paypal-standard-for-woocommerce' ), $subscription_id );
	}

	// -----------------------------------------------------------------
	// List endpoint.
	// -----------------------------------------------------------------

	/**
	 * Render the subscriptions list.
	 */
	public static function render_list() {
		$subscriptions = rpsfw_get_subscriptions_for_user( get_current_user_id() );

		if ( empty( $subscriptions ) ) {
			echo '<div class="woocommerce-message woocommerce-message--info woocommerce-Message woocommerce-Message--info woocommerce-info">';
			echo esc_html__( 'You have no active subscriptions.', 'restore-paypal-standard-for-woocommerce' );
			echo ' <a class="woocommerce-Button button" href="' . esc_url( wc_get_page_permalink( 'shop' ) ) . '">' . esc_html__( 'Browse products', 'restore-paypal-standard-for-woocommerce' ) . '</a>';
			echo '</div>';
			return;
		}
		?>
		<table class="woocommerce-orders-table woocommerce-MyAccount-subscriptions shop_table shop_table_responsive my_account_subscriptions account-orders-table">
			<thead>
				<tr>
					<th class="woocommerce-orders-table__header"><span class="nobr"><?php esc_html_e( 'Subscription', 'restore-paypal-standard-for-woocommerce' ); ?></span></th>
					<th class="woocommerce-orders-table__header"><span class="nobr"><?php esc_html_e( 'Status', 'restore-paypal-standard-for-woocommerce' ); ?></span></th>
					<th class="woocommerce-orders-table__header"><span class="nobr"><?php esc_html_e( 'Next payment', 'restore-paypal-standard-for-woocommerce' ); ?></span></th>
					<th class="woocommerce-orders-table__header"><span class="nobr"><?php esc_html_e( 'Total', 'restore-paypal-standard-for-woocommerce' ); ?></span></th>
					<th class="woocommerce-orders-table__header">&nbsp;</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $subscriptions as $subscription ) : ?>
					<tr class="woocommerce-orders-table__row">
						<td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e( 'Subscription', 'restore-paypal-standard-for-woocommerce' ); ?>">
							<a href="<?php echo esc_url( $subscription->get_view_url() ); ?>">#<?php echo esc_html( $subscription->get_id() ); ?></a>
							<br><small><?php echo esc_html( $subscription->get_item_name() ); ?></small>
						</td>
						<td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e( 'Status', 'restore-paypal-standard-for-woocommerce' ); ?>">
							<?php echo esc_html( rpsfw_get_subscription_status_label( $subscription->get_status() ) ); ?>
						</td>
						<td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e( 'Next payment', 'restore-paypal-standard-for-woocommerce' ); ?>">
							<?php echo esc_html( $subscription->has_status( array( 'active', 'pending-cancel' ) ) ? $subscription->get_date_to_display( 'next_payment' ) : '—' ); ?>
						</td>
						<td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e( 'Total', 'restore-paypal-standard-for-woocommerce' ); ?>">
							<?php echo wp_kses_post( $subscription->get_formatted_recurring_total() ); ?>
						</td>
						<td class="woocommerce-orders-table__cell">
							<a class="woocommerce-button button view" href="<?php echo esc_url( $subscription->get_view_url() ); ?>"><?php esc_html_e( 'View', 'restore-paypal-standard-for-woocommerce' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// -----------------------------------------------------------------
	// View endpoint.
	// -----------------------------------------------------------------

	/**
	 * Render one subscription with details, actions and related orders.
	 *
	 * @param int $subscription_id Subscription id from the URL.
	 */
	public static function render_view( $subscription_id ) {
		$subscription = rpsfw_get_subscription( absint( $subscription_id ) );

		if ( ! $subscription || ! $subscription->belongs_to_user( get_current_user_id() ) ) {
			echo '<div class="woocommerce-error">' . esc_html__( 'Invalid subscription.', 'restore-paypal-standard-for-woocommerce' ) . '</div>';
			return;
		}

		$actions = self::get_customer_actions( $subscription );
		?>
		<table class="woocommerce-table shop_table subscription_details">
			<tbody>
				<tr>
					<th><?php esc_html_e( 'Status', 'restore-paypal-standard-for-woocommerce' ); ?></th>
					<td><?php echo esc_html( rpsfw_get_subscription_status_label( $subscription->get_status() ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Product', 'restore-paypal-standard-for-woocommerce' ); ?></th>
					<td>
						<?php
						echo esc_html( $subscription->get_item_name() );
						if ( $subscription->get_quantity() > 1 ) {
							echo esc_html( ' × ' . $subscription->get_quantity() );
						}
						?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Recurring total', 'restore-paypal-standard-for-woocommerce' ); ?></th>
					<td><?php echo wp_kses_post( $subscription->get_formatted_recurring_total() ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Start date', 'restore-paypal-standard-for-woocommerce' ); ?></th>
					<td><?php echo esc_html( $subscription->get_date_to_display( 'start' ) ); ?></td>
				</tr>
				<?php if ( $subscription->has_status( array( 'active', 'pending-cancel', 'on-hold' ) ) ) : ?>
					<tr>
						<th>
							<?php
							echo esc_html(
								$subscription->has_status( 'pending-cancel' )
									? __( 'End date', 'restore-paypal-standard-for-woocommerce' )
									: __( 'Next payment', 'restore-paypal-standard-for-woocommerce' )
							);
							?>
						</th>
						<td><?php echo esc_html( $subscription->get_date_to_display( 'next_payment' ) ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( $subscription->get_end_date() ) : ?>
					<tr>
						<th><?php esc_html_e( 'Ended', 'restore-paypal-standard-for-woocommerce' ); ?></th>
						<td><?php echo esc_html( $subscription->get_date_to_display( 'end' ) ); ?></td>
					</tr>
				<?php endif; ?>
				<tr>
					<th><?php esc_html_e( 'Payment method', 'restore-paypal-standard-for-woocommerce' ); ?></th>
					<td><?php echo esc_html( $subscription->get_gateway_title() ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Payments made', 'restore-paypal-standard-for-woocommerce' ); ?></th>
					<td>
						<?php
						if ( $subscription->get_length() > 0 ) {
							printf(
								/* translators: 1: payments made, 2: total payments */
								esc_html__( '%1$d of %2$d', 'restore-paypal-standard-for-woocommerce' ),
								(int) $subscription->get_payment_count(),
								(int) $subscription->get_length()
							);
						} else {
							echo esc_html( $subscription->get_payment_count() );
						}
						?>
					</td>
				</tr>
			</tbody>
		</table>

		<?php if ( ! empty( $actions ) ) : ?>
			<p class="rpsfw-subscription-actions">
				<?php foreach ( $actions as $action ) : ?>
					<a class="woocommerce-button button<?php echo esc_attr( $action['class'] ); ?>"
						href="<?php echo esc_url( $action['url'] ); ?>"
						<?php if ( ! empty( $action['confirm'] ) ) : ?>
							onclick="return confirm('<?php echo esc_js( $action['confirm'] ); ?>');"
						<?php endif; ?>
					><?php echo esc_html( $action['label'] ); ?></a>
				<?php endforeach; ?>
			</p>
		<?php endif; ?>

		<?php
		$related_ids = $subscription->get_related_order_ids();
		if ( ! empty( $related_ids ) ) :
			?>
			<h2><?php esc_html_e( 'Related orders', 'restore-paypal-standard-for-woocommerce' ); ?></h2>
			<table class="woocommerce-orders-table shop_table shop_table_responsive">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Order', 'restore-paypal-standard-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Date', 'restore-paypal-standard-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Status', 'restore-paypal-standard-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Total', 'restore-paypal-standard-for-woocommerce' ); ?></th>
						<th>&nbsp;</th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( array_reverse( $related_ids ) as $order_id ) :
						$order = wc_get_order( $order_id );
						if ( ! $order ) {
							continue;
						}
						$is_parent = ( $order_id === $subscription->get_parent_order_id() );
						?>
						<tr>
							<td data-title="<?php esc_attr_e( 'Order', 'restore-paypal-standard-for-woocommerce' ); ?>">
								<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
								<?php if ( $is_parent ) : ?>
									<small>(<?php esc_html_e( 'initial order', 'restore-paypal-standard-for-woocommerce' ); ?>)</small>
								<?php endif; ?>
							</td>
							<td data-title="<?php esc_attr_e( 'Date', 'restore-paypal-standard-for-woocommerce' ); ?>">
								<?php echo esc_html( $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '—' ); ?>
							</td>
							<td data-title="<?php esc_attr_e( 'Status', 'restore-paypal-standard-for-woocommerce' ); ?>">
								<?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?>
							</td>
							<td data-title="<?php esc_attr_e( 'Total', 'restore-paypal-standard-for-woocommerce' ); ?>">
								<?php echo wp_kses_post( $order->get_formatted_order_total() ); ?>
							</td>
							<td>
								<a class="woocommerce-button button view" href="<?php echo esc_url( $order->get_view_order_url() ); ?>"><?php esc_html_e( 'View', 'restore-paypal-standard-for-woocommerce' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		endif;
	}

	/**
	 * Build the action buttons available to the customer for a
	 * subscription, based on settings and state.
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 * @return array[] { url, label, class, confirm }
	 */
	private static function get_customer_actions( $subscription ) {
		$actions = array();

		$can_cancel  = 'yes' === rpsfw_subscriptions_get_setting( 'customer_can_cancel' );
		$can_suspend = 'yes' === rpsfw_subscriptions_get_setting( 'customer_can_suspend' );

		$base_url = $subscription->get_view_url();

		if ( $can_suspend && $subscription->can_be_suspended() ) {
			$actions[] = array(
				'url'     => wp_nonce_url( add_query_arg( 'rpsfw_sub_action', 'suspend', $base_url ), 'rpsfw_sub_action_' . $subscription->get_id() ),
				'label'   => __( 'Pause', 'restore-paypal-standard-for-woocommerce' ),
				'class'   => '',
				'confirm' => __( 'Pause this subscription? You will not be billed while it is paused.', 'restore-paypal-standard-for-woocommerce' ),
			);
		}

		if ( $subscription->can_be_resumed() ) {
			$is_reactivate = $subscription->has_status( 'pending-cancel' );
			// Resuming a customer-paused subscription is always allowed;
			// reactivating a pending cancellation likewise.
			if ( $can_suspend || $is_reactivate ) {
				$actions[] = array(
					'url'     => wp_nonce_url( add_query_arg( 'rpsfw_sub_action', 'resume', $base_url ), 'rpsfw_sub_action_' . $subscription->get_id() ),
					'label'   => $is_reactivate
						? __( 'Reactivate', 'restore-paypal-standard-for-woocommerce' )
						: __( 'Resume', 'restore-paypal-standard-for-woocommerce' ),
					'class'   => '',
					'confirm' => '',
				);
			}
		}

		if ( $can_cancel && $subscription->can_be_cancelled() && ! $subscription->has_status( 'pending-cancel' ) ) {
			$actions[] = array(
				'url'     => wp_nonce_url( add_query_arg( 'rpsfw_sub_action', 'cancel', $base_url ), 'rpsfw_sub_action_' . $subscription->get_id() ),
				'label'   => __( 'Cancel', 'restore-paypal-standard-for-woocommerce' ),
				'class'   => ' cancel',
				'confirm' => __( 'Cancel this subscription? This cannot be undone.', 'restore-paypal-standard-for-woocommerce' ),
			);
		}

		return apply_filters( 'rpsfw_subscription_customer_actions', $actions, $subscription );
	}

	// -----------------------------------------------------------------
	// Action handling.
	// -----------------------------------------------------------------

	/**
	 * Handle a customer subscription action (nonce'd link).
	 */
	public static function handle_customer_action() {
		if ( empty( $_GET['rpsfw_sub_action'] ) || ! is_user_logged_in() ) {
			return;
		}

		global $wp;
		$subscription_id = isset( $wp->query_vars[ self::ENDPOINT_VIEW ] ) ? absint( $wp->query_vars[ self::ENDPOINT_VIEW ] ) : 0;
		if ( ! $subscription_id ) {
			return;
		}

		$subscription = rpsfw_get_subscription( $subscription_id );
		if ( ! $subscription || ! $subscription->belongs_to_user( get_current_user_id() ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_GET['rpsfw_sub_action'] ) );
		$nonce  = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'rpsfw_sub_action_' . $subscription->get_id() ) ) {
			wc_add_notice( __( 'Security check failed. Please try again.', 'restore-paypal-standard-for-woocommerce' ), 'error' );
			wp_safe_redirect( $subscription->get_view_url() );
			exit;
		}

		switch ( $action ) {
			case 'cancel':
				if ( 'yes' !== rpsfw_subscriptions_get_setting( 'customer_can_cancel' ) ) {
					$result = new WP_Error( 'rpsfw_sub_not_allowed', __( 'Cancelling subscriptions is not enabled. Please contact us for help.', 'restore-paypal-standard-for-woocommerce' ) );
				} else {
					$result = RPSFW_Subscriptions_Manager::cancel( $subscription, 'customer' );
				}
				$success_notice = $subscription->has_status( 'pending-cancel' )
					? __( 'Your subscription has been cancelled. It remains active until the end of the paid period.', 'restore-paypal-standard-for-woocommerce' )
					: __( 'Your subscription has been cancelled.', 'restore-paypal-standard-for-woocommerce' );
				break;

			case 'suspend':
				if ( 'yes' !== rpsfw_subscriptions_get_setting( 'customer_can_suspend' ) ) {
					$result = new WP_Error( 'rpsfw_sub_not_allowed', __( 'Pausing subscriptions is not enabled. Please contact us for help.', 'restore-paypal-standard-for-woocommerce' ) );
				} else {
					$result = RPSFW_Subscriptions_Manager::suspend( $subscription, 'customer' );
				}
				$success_notice = __( 'Your subscription has been paused.', 'restore-paypal-standard-for-woocommerce' );
				break;

			case 'resume':
				$result         = RPSFW_Subscriptions_Manager::resume( $subscription, 'customer' );
				$success_notice = __( 'Your subscription is active again.', 'restore-paypal-standard-for-woocommerce' );
				break;

			default:
				return;
		}

		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
		} else {
			wc_add_notice( $success_notice );
		}

		wp_safe_redirect( $subscription->get_view_url() );
		exit;
	}
}
