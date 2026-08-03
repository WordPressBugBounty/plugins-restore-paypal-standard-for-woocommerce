<?php
/**
 * Admin UI for native subscriptions.
 *
 * - Subscription edit screen (meta boxes on the rpsfw_subscription post
 *   type: details, actions, related orders, activity notes). The list
 *   table itself is the native WordPress posts table, customized in
 *   RPSFW_Subscriptions_Post_Type.
 * - Admin lifecycle actions (cancel / suspend / resume) via admin-post.
 * - The settings page (settings only — records live in the list table).
 * - The Subscriptions card shown on the plugin's settings hub.
 *
 * @package Restore_PayPal_Standard_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RPSFW_Subscriptions_Admin
 */
class RPSFW_Subscriptions_Admin {

	const SETTINGS_PAGE = 'rpsfw-subscriptions-settings';

	/**
	 * Full admin wiring (module enabled or records exist).
	 */
	public static function init() {
		if ( ! is_admin() ) {
			return;
		}

		add_action( 'add_meta_boxes_' . RPSFW_Subscriptions_Post_Type::POST_TYPE, array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'admin_post_rpsfw_sub_admin_action', array( __CLASS__, 'handle_admin_action' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_action_notice' ) );

		// Related-subscriptions meta box on the order edit screen (both the
		// legacy shop_order screen and the HPOS orders screen).
		add_action( 'add_meta_boxes', array( __CLASS__, 'maybe_register_order_meta_box' ), 30, 2 );

		// "Type" (Initial / Renewal) column on the orders list table (HPOS +
		// legacy screens). Only populated for orders paid through one of this
		// plugin's own gateways; blank otherwise. Part of the subscriptions
		// module — it is only registered while this module is active. The
		// always-on "Gateway" column lives separately in
		// includes/admin/order-list-columns.php. Priority 20 (the Gateway
		// column adds at 10) so the final order is Status | Type | Gateway.
		add_filter( 'woocommerce_shop_order_list_table_columns', array( __CLASS__, 'add_order_type_column' ), 20 );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( __CLASS__, 'render_order_type_column' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_order_type_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_order_type_column' ), 10, 2 );

		self::init_settings_only();
	}

	/**
	 * Settings page only (kept reachable while the module is disabled so
	 * it can be re-enabled).
	 */
	public static function init_settings_only() {
		add_action( 'admin_menu', array( __CLASS__, 'register_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_save_settings' ) );
		add_filter( 'admin_title', array( __CLASS__, 'filter_settings_admin_title' ), 10, 2 );
	}

	/**
	 * Set the browser <title> for the settings page.
	 *
	 * The page is registered with an empty parent slug (no menu entry), so
	 * WordPress never populates the $title global and the document title
	 * would otherwise render empty (" — WordPress"). Prepend our page title
	 * while preserving WordPress's site-name/separator formatting.
	 *
	 * @param string $admin_title The full page <title>.
	 * @param string $title       The page title global (empty for this page).
	 * @return string
	 */
	public static function filter_settings_admin_title( $admin_title, $title ) {
		if ( '' !== $title ) {
			return $admin_title;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check.
		if ( isset( $_GET['page'] ) && self::SETTINGS_PAGE === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return __( 'Subscription Settings', 'restore-paypal-standard-for-woocommerce' ) . $admin_title;
		}
		return $admin_title;
	}

	// -----------------------------------------------------------------
	// Edit screen meta boxes.
	// -----------------------------------------------------------------

	/**
	 * Register meta boxes and strip the default post boxes.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function register_meta_boxes( $post ) {
		// Everything is driven by processor state — no publish box.
		remove_meta_box( 'submitdiv', RPSFW_Subscriptions_Post_Type::POST_TYPE, 'side' );
		remove_meta_box( 'slugdiv', RPSFW_Subscriptions_Post_Type::POST_TYPE, 'normal' );
		remove_meta_box( 'commentsdiv', RPSFW_Subscriptions_Post_Type::POST_TYPE, 'normal' );
		remove_meta_box( 'commentstatusdiv', RPSFW_Subscriptions_Post_Type::POST_TYPE, 'normal' );

		add_meta_box(
			'rpsfw-sub-details',
			__( 'Subscription details', 'restore-paypal-standard-for-woocommerce' ),
			array( __CLASS__, 'render_details_meta_box' ),
			RPSFW_Subscriptions_Post_Type::POST_TYPE,
			'normal',
			'high'
		);
		add_meta_box(
			'rpsfw-sub-orders',
			__( 'Related orders', 'restore-paypal-standard-for-woocommerce' ),
			array( __CLASS__, 'render_orders_meta_box' ),
			RPSFW_Subscriptions_Post_Type::POST_TYPE,
			'normal',
			'default'
		);
		add_meta_box(
			'rpsfw-sub-actions',
			__( 'Actions', 'restore-paypal-standard-for-woocommerce' ),
			array( __CLASS__, 'render_actions_meta_box' ),
			RPSFW_Subscriptions_Post_Type::POST_TYPE,
			'side',
			'high'
		);
		add_meta_box(
			'rpsfw-sub-notes',
			__( 'Activity', 'restore-paypal-standard-for-woocommerce' ),
			array( __CLASS__, 'render_notes_meta_box' ),
			RPSFW_Subscriptions_Post_Type::POST_TYPE,
			'side',
			'default'
		);
	}

	/**
	 * Details meta box.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_details_meta_box( $post ) {
		$subscription = rpsfw_get_subscription( $post );
		if ( ! $subscription ) {
			return;
		}

		$customer      = $subscription->get_customer_id() ? get_userdata( $subscription->get_customer_id() ) : false;
		$product_id    = $subscription->get_product_id();
		$processor_url = self::get_processor_url( $subscription );

		$rows = array(
			__( 'Status', 'restore-paypal-standard-for-woocommerce' )          => esc_html( rpsfw_get_subscription_status_label( $subscription->get_status() ) ),
			__( 'Customer', 'restore-paypal-standard-for-woocommerce' )        => $customer
				? '<a href="' . esc_url( get_edit_user_link( $customer->ID ) ) . '">' . esc_html( $subscription->get_customer_name() ? $subscription->get_customer_name() : $customer->display_name ) . '</a> (' . esc_html( $customer->user_email ) . ')'
				: esc_html( $subscription->get_customer_name() ? $subscription->get_customer_name() : __( 'Guest', 'restore-paypal-standard-for-woocommerce' ) ),
			__( 'Product', 'restore-paypal-standard-for-woocommerce' )         => $product_id
				? '<a href="' . esc_url( get_edit_post_link( $product_id ) ) . '">' . esc_html( $subscription->get_item_name() ) . '</a>' . ( $subscription->get_quantity() > 1 ? esc_html( ' × ' . $subscription->get_quantity() ) : '' )
				: esc_html( $subscription->get_item_name() ),
			__( 'Recurring total', 'restore-paypal-standard-for-woocommerce' ) => wp_kses_post( $subscription->get_formatted_recurring_total() ),
			__( 'Sign-up fee', 'restore-paypal-standard-for-woocommerce' )     => $subscription->get_signup_fee() > 0
				? wp_kses_post( wc_price( $subscription->get_signup_fee(), array( 'currency' => $subscription->get_currency() ) ) )
				: esc_html__( 'None', 'restore-paypal-standard-for-woocommerce' ),
			__( 'Free trial', 'restore-paypal-standard-for-woocommerce' )      => $subscription->get_trial_length() > 0
				? esc_html( rpsfw_format_subscription_trial( $subscription->get_trial_length(), $subscription->get_trial_period() ) )
				: esc_html__( 'None', 'restore-paypal-standard-for-woocommerce' ),
			__( 'Payments made', 'restore-paypal-standard-for-woocommerce' )   => $subscription->get_length() > 0
				? esc_html( sprintf( '%1$d / %2$d', $subscription->get_payment_count(), $subscription->get_length() ) )
				: esc_html( (string) $subscription->get_payment_count() ),
			__( 'Start date', 'restore-paypal-standard-for-woocommerce' )      => esc_html( $subscription->get_date_to_display( 'start' ) ),
			__( 'Next payment', 'restore-paypal-standard-for-woocommerce' )    => esc_html( $subscription->get_date_to_display( 'next_payment' ) ),
			__( 'Payment method', 'restore-paypal-standard-for-woocommerce' )  => esc_html( $subscription->get_gateway_title( 'admin' ) ),
			__( 'Mode', 'restore-paypal-standard-for-woocommerce' )            => class_exists( 'RPSFW_Gateway_Deep_Links' )
				? RPSFW_Gateway_Deep_Links::mode_badge( self::get_stamped_subscription_mode( $subscription ) )
				: esc_html( rpsfw_payment_mode_label( self::get_subscription_mode( $subscription ) ) ),
			__( 'Processor ID', 'restore-paypal-standard-for-woocommerce' )    => $processor_url
				? '<a href="' . esc_url( $processor_url ) . '" target="_blank" rel="noopener noreferrer"><code>' . esc_html( $subscription->get_gateway_sub_id() ) . '</code></a>'
				: '<code>' . esc_html( $subscription->get_gateway_sub_id() ) . '</code>',
		);

		if ( $subscription->get_end_date() ) {
			$rows[ __( 'Ended', 'restore-paypal-standard-for-woocommerce' ) ] = esc_html( $subscription->get_date_to_display( 'end' ) );
		}

		echo '<table class="widefat striped" style="border:0;">';
		foreach ( $rows as $label => $value_html ) {
			echo '<tr><th style="width:180px;text-align:left;">' . esc_html( $label ) . '</th><td>' . wp_kses_post( $value_html ) . '</td></tr>';
		}
		echo '</table>';
		echo '<p class="description" style="margin-top:8px;">' . esc_html__( 'Billing for this subscription runs on the payment processor — renewals, retries and dunning happen there and are mirrored here via webhooks.', 'restore-paypal-standard-for-woocommerce' ) . '</p>';
	}

	/**
	 * Dashboard deep link for the processor subscription.
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 * @return string
	 */
	private static function get_processor_url( $subscription ) {
		$id = $subscription->get_gateway_sub_id();
		if ( ! $id ) {
			return '';
		}

		// Link into the account the subscription was actually created in, not
		// whichever mode the store is set to now.
		$is_test = ( 'test' === self::get_subscription_mode( $subscription ) );

		if ( 'rpsfw_stripe' === $subscription->get_gateway_id() ) {
			return 'https://dashboard.stripe.com/' . ( $is_test ? 'test/' : '' ) . 'subscriptions/' . rawurlencode( $id );
		}
		if ( 'rpsfw_paypal_commerce' === $subscription->get_gateway_id() ) {
			$host = $is_test ? 'https://www.sandbox.paypal.com' : 'https://www.paypal.com';
			return $host . '/billing/subscriptions/' . rawurlencode( $id );
		}
		return '';
	}

	/**
	 * The mode a subscription's payments run in.
	 *
	 * Taken from the parent order, which is stamped at checkout; falls back to
	 * the gateway's current setting for records created before stamping.
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 * @return string 'test' or 'live'.
	 */
	private static function get_subscription_mode( $subscription ) {
		$parent = $subscription->get_parent_order();
		if ( $parent ) {
			return rpsfw_get_order_payment_mode( $parent );
		}

		return rpsfw_get_gateway_mode( $subscription->get_gateway_id() );
	}

	/**
	 * The mode recorded for a subscription, or '' when none was recorded.
	 *
	 * Display uses this rather than get_subscription_mode() so a subscription
	 * created before mode stamping is shown as unknown instead of being labelled
	 * with whatever the gateway is set to today.
	 *
	 * @param RPSFW_Subscription $subscription Subscription.
	 * @return string 'test', 'live', or ''.
	 */
	private static function get_stamped_subscription_mode( $subscription ) {
		$parent = $subscription->get_parent_order();

		return $parent ? rpsfw_get_stamped_order_payment_mode( $parent ) : '';
	}

	/**
	 * Related orders meta box.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_orders_meta_box( $post ) {
		$subscription = rpsfw_get_subscription( $post );
		if ( ! $subscription ) {
			return;
		}

		$related_ids = $subscription->get_related_order_ids();
		if ( empty( $related_ids ) ) {
			echo '<p>' . esc_html__( 'No related orders.', 'restore-paypal-standard-for-woocommerce' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Order', 'restore-paypal-standard-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Relationship', 'restore-paypal-standard-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Date', 'restore-paypal-standard-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'restore-paypal-standard-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Total', 'restore-paypal-standard-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( array_reverse( $related_ids ) as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}
			$is_parent = ( $order_id === $subscription->get_parent_order_id() );
			echo '<tr>';
			echo '<td><a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td>';
			echo '<td>' . esc_html( $is_parent ? __( 'Initial order', 'restore-paypal-standard-for-woocommerce' ) : __( 'Renewal', 'restore-paypal-standard-for-woocommerce' ) ) . '</td>';
			echo '<td>' . esc_html( $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '—' ) . '</td>';
			echo '<td>' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</td>';
			echo '<td>' . wp_kses_post( $order->get_formatted_order_total() ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Actions meta box (admin lifecycle buttons).
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_actions_meta_box( $post ) {
		$subscription = rpsfw_get_subscription( $post );
		if ( ! $subscription ) {
			return;
		}

		$buttons = array();
		if ( $subscription->can_be_suspended() ) {
			$buttons[] = array( 'suspend', __( 'Suspend', 'restore-paypal-standard-for-woocommerce' ), '' );
		}
		if ( $subscription->can_be_resumed() ) {
			$buttons[] = array(
				'resume',
				$subscription->has_status( 'pending-cancel' )
					? __( 'Reactivate', 'restore-paypal-standard-for-woocommerce' )
					: __( 'Resume', 'restore-paypal-standard-for-woocommerce' ),
				'button-primary',
			);
		}
		if ( $subscription->can_be_cancelled() ) {
			$buttons[] = array( 'cancel', __( 'Cancel subscription', 'restore-paypal-standard-for-woocommerce' ), '' );
		}

		if ( empty( $buttons ) ) {
			echo '<p>' . esc_html__( 'No actions are available for this subscription.', 'restore-paypal-standard-for-woocommerce' ) . '</p>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'These actions are sent to the payment processor immediately.', 'restore-paypal-standard-for-woocommerce' ) . '</p>';
		foreach ( $buttons as $button ) {
			list( $action, $label, $class ) = $button;
			$url = wp_nonce_url(
				add_query_arg(
					array(
						'action'       => 'rpsfw_sub_admin_action',
						'sub_action'   => $action,
						'subscription' => $subscription->get_id(),
					),
					admin_url( 'admin-post.php' )
				),
				'rpsfw_sub_admin_action_' . $subscription->get_id()
			);
			$confirm = 'cancel' === $action
				? __( 'Cancel this subscription at the payment processor? This cannot be undone.', 'restore-paypal-standard-for-woocommerce' )
				: '';
			printf(
				'<a href="%1$s" class="button %2$s" style="margin:2px 4px 2px 0;"%3$s>%4$s</a>',
				esc_url( $url ),
				esc_attr( $class ),
				$confirm ? ' onclick="return confirm(\'' . esc_js( $confirm ) . '\');"' : '',
				esc_html( $label )
			);
		}
	}

	/**
	 * Notes meta box (read-only activity log).
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_notes_meta_box( $post ) {
		$subscription = rpsfw_get_subscription( $post );
		if ( ! $subscription ) {
			return;
		}

		$notes = $subscription->get_notes();
		if ( empty( $notes ) ) {
			echo '<p>' . esc_html__( 'No activity yet.', 'restore-paypal-standard-for-woocommerce' ) . '</p>';
			return;
		}

		echo '<ul style="max-height:340px;overflow-y:auto;margin:0;">';
		foreach ( $notes as $note ) {
			echo '<li style="border-bottom:1px solid #f0f0f1;padding:6px 0;margin:0;">';
			echo '<div>' . wp_kses_post( wpautop( wptexturize( $note->comment_content ) ) ) . '</div>';
			echo '<small style="color:#787c82;">' . esc_html( date_i18n( wc_date_format() . ' ' . wc_time_format(), strtotime( $note->comment_date ) ) ) . '</small>';
			echo '</li>';
		}
		echo '</ul>';
	}

	// -----------------------------------------------------------------
	// Order edit screen: related subscriptions meta box.
	// -----------------------------------------------------------------

	/**
	 * Resolve the order object handed to add_meta_boxes / the meta box
	 * callback. HPOS passes a WC_Order, the legacy screen passes WP_Post.
	 *
	 * @param mixed $object WC_Order|WP_Post|null.
	 * @return WC_Order|false
	 */
	private static function resolve_order( $object ) {
		if ( $object instanceof WC_Order ) {
			return $object;
		}
		if ( $object instanceof WP_Post && 'shop_order' === $object->post_type ) {
			$order = wc_get_order( $object->ID );
			return $order instanceof WC_Order ? $order : false;
		}
		return false;
	}

	/**
	 * Subscriptions related to an order: the subscription this order is a
	 * renewal of, and/or subscriptions this order is the initial order for.
	 *
	 * @param WC_Order $order Order.
	 * @return array[] Each entry: { subscription: RPSFW_Subscription, relationship: string }.
	 */
	private static function get_order_related_subscriptions( $order ) {
		$related = array();

		$renewal_of = (int) $order->get_meta( '_rpsfw_subscription_renewal' );
		if ( $renewal_of ) {
			$subscription = rpsfw_get_subscription( $renewal_of );
			if ( $subscription ) {
				$related[ $subscription->get_id() ] = array(
					'subscription' => $subscription,
					'relationship' => __( 'Renewal order', 'restore-paypal-standard-for-woocommerce' ),
				);
			}
		}

		foreach ( rpsfw_get_subscriptions_for_order( $order ) as $subscription ) {
			$related[ $subscription->get_id() ] = array(
				'subscription' => $subscription,
				'relationship' => __( 'Initial order', 'restore-paypal-standard-for-woocommerce' ),
			);
		}

		return $related;
	}

	/**
	 * Register the meta box on order edit screens when the order relates to
	 * a native subscription.
	 *
	 * @param string $post_type_or_screen Post type (legacy) or screen id (HPOS).
	 * @param mixed  $object              WP_Post or WC_Order.
	 */
	public static function maybe_register_order_meta_box( $post_type_or_screen, $object = null ) {
		$order = self::resolve_order( $object );
		if ( ! $order || empty( self::get_order_related_subscriptions( $order ) ) ) {
			return;
		}

		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

		add_meta_box(
			'rpsfw-order-subscriptions',
			__( 'Subscriptions', 'restore-paypal-standard-for-woocommerce' ),
			array( __CLASS__, 'render_order_meta_box' ),
			$screen,
			'normal',
			'low'
		);
	}

	/**
	 * Render the related-subscriptions meta box on the order edit screen.
	 *
	 * @param mixed $object WP_Post or WC_Order.
	 */
	public static function render_order_meta_box( $object ) {
		$order = self::resolve_order( $object );
		if ( ! $order ) {
			return;
		}

		$related = self::get_order_related_subscriptions( $order );
		if ( empty( $related ) ) {
			return;
		}

		echo '<table class="widefat striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Subscription', 'restore-paypal-standard-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Relationship', 'restore-paypal-standard-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'restore-paypal-standard-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Recurring total', 'restore-paypal-standard-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Next payment', 'restore-paypal-standard-for-woocommerce' ) . '</th>';
		echo '<th>&nbsp;</th>';
		echo '</tr></thead><tbody>';

		foreach ( $related as $entry ) {
			$subscription = $entry['subscription'];
			$edit_url     = get_edit_post_link( $subscription->get_id() );
			if ( ! $edit_url ) {
				$edit_url = admin_url( 'post.php?post=' . $subscription->get_id() . '&action=edit' );
			}

			echo '<tr>';
			echo '<td><a href="' . esc_url( $edit_url ) . '"><strong>#' . esc_html( $subscription->get_id() ) . '</strong></a>';
			if ( $subscription->get_item_name() ) {
				echo ' — ' . esc_html( $subscription->get_item_name() );
			}
			echo '</td>';
			echo '<td>' . esc_html( $entry['relationship'] ) . '</td>';
			echo '<td>' . esc_html( rpsfw_get_subscription_status_label( $subscription->get_status() ) ) . '</td>';
			echo '<td>' . wp_kses_post( $subscription->get_formatted_recurring_total() ) . '</td>';
			echo '<td>' . esc_html( $subscription->has_status( array( 'active', 'pending-cancel' ) ) ? $subscription->get_date_to_display( 'next_payment' ) : '—' ) . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Manage', 'restore-paypal-standard-for-woocommerce' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description" style="margin-top:8px;">' . esc_html__( 'Billing runs on the payment processor; cancel, pause or resume from the subscription screen.', 'restore-paypal-standard-for-woocommerce' ) . '</p>';
	}

	// -----------------------------------------------------------------
	// Orders list table "Type" (Initial / Renewal) column.
	//
	// The "Gateway" column is separate and always-on (see
	// includes/admin/order-list-columns.php); this Type column is part of the
	// subscriptions module and only registered while the module is active.
	// -----------------------------------------------------------------

	/**
	 * Add the Type column to the orders list table, right after Status.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_order_type_column( $columns ) {
		if ( ! is_array( $columns ) || isset( $columns['rpsfw_order_type'] ) ) {
			return $columns;
		}

		$label   = __( 'Type', 'restore-paypal-standard-for-woocommerce' );
		$updated = array();

		foreach ( $columns as $key => $value ) {
			$updated[ $key ] = $value;
			if ( 'order_status' === $key ) {
				$updated['rpsfw_order_type'] = $label;
			}
		}

		if ( ! isset( $updated['rpsfw_order_type'] ) ) {
			$updated['rpsfw_order_type'] = $label;
		}

		return $updated;
	}

	/**
	 * Render the Type column. HPOS passes a WC_Order, the legacy posts table
	 * passes a post id.
	 *
	 * @param string        $column      Column id.
	 * @param WC_Order|int  $order_or_id Order or order id.
	 */
	public static function render_order_type_column( $column, $order_or_id ) {
		if ( 'rpsfw_order_type' !== $column ) {
			return;
		}

		$order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( $order_or_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		echo esc_html( self::get_order_type_label( $order ) );
	}

	/**
	 * Whether an order created a subscription (initial), came from one
	 * (renewal), or neither. Also recognises WooCommerce Subscriptions
	 * records when that plugin is active. Only evaluated for orders paid
	 * through one of this plugin's own gateways — everything else shows
	 * nothing in the Type column.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function get_order_type_label( $order ) {
		if ( '' === rpsfw_order_gateway_label( $order ) ) {
			return '';
		}

		if ( rpsfw_order_is_subscription_renewal( $order ) ) {
			return __( 'Renewal Order', 'restore-paypal-standard-for-woocommerce' );
		}

		if ( in_array( $order->get_id(), self::get_subscription_parent_order_ids(), true ) ) {
			return __( 'Initial Order', 'restore-paypal-standard-for-woocommerce' );
		}

		if ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) {
			return __( 'Renewal Order', 'restore-paypal-standard-for-woocommerce' );
		}

		if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order, 'parent' ) ) {
			return __( 'Initial Order', 'restore-paypal-standard-for-woocommerce' );
		}

		return '—';
	}

	/**
	 * Parent order ids of every native subscription on the site.
	 *
	 * Resolved once per request (two queries) rather than per row —
	 * rpsfw_get_subscriptions_for_order() would run a query for each of the
	 * orders on the page.
	 *
	 * @return int[]
	 */
	private static function get_subscription_parent_order_ids() {
		static $parent_ids = null;

		if ( null !== $parent_ids ) {
			return $parent_ids;
		}

		$parent_ids = array();

		// Explicit status list rather than 'any' — the subscription statuses
		// are registered with exclude_from_search => true, which 'any' skips.
		$statuses = array_map(
			function ( $status ) {
				return 'rpsfw-' . $status;
			},
			array_keys( rpsfw_get_subscription_statuses() )
		);

		$subscription_ids = get_posts(
			array(
				'post_type'      => RPSFW_Subscriptions_Post_Type::POST_TYPE,
				'post_status'    => $statuses,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_rpsfw_parent_order_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);

		if ( empty( $subscription_ids ) ) {
			return $parent_ids;
		}

		update_meta_cache( 'post', $subscription_ids );

		foreach ( $subscription_ids as $subscription_id ) {
			$parent_id = (int) get_post_meta( $subscription_id, '_rpsfw_parent_order_id', true );
			if ( $parent_id ) {
				$parent_ids[] = $parent_id;
			}
		}

		$parent_ids = array_values( array_unique( $parent_ids ) );

		return $parent_ids;
	}

	// -----------------------------------------------------------------
	// Admin actions.
	// -----------------------------------------------------------------

	/**
	 * Handle an admin lifecycle action.
	 */
	public static function handle_admin_action() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		$subscription_id = isset( $_GET['subscription'] ) ? absint( $_GET['subscription'] ) : 0;
		$sub_action      = isset( $_GET['sub_action'] ) ? sanitize_text_field( wp_unslash( $_GET['sub_action'] ) ) : '';
		$nonce           = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! $subscription_id || ! wp_verify_nonce( $nonce, 'rpsfw_sub_admin_action_' . $subscription_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		$subscription = rpsfw_get_subscription( $subscription_id );
		if ( ! $subscription ) {
			wp_die( esc_html__( 'Subscription not found.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		switch ( $sub_action ) {
			case 'cancel':
				$result = RPSFW_Subscriptions_Manager::cancel( $subscription, 'admin' );
				$notice = __( 'Subscription cancelled.', 'restore-paypal-standard-for-woocommerce' );
				break;
			case 'suspend':
				$result = RPSFW_Subscriptions_Manager::suspend( $subscription, 'admin' );
				$notice = __( 'Subscription suspended.', 'restore-paypal-standard-for-woocommerce' );
				break;
			case 'resume':
				$result = RPSFW_Subscriptions_Manager::resume( $subscription, 'admin' );
				$notice = __( 'Subscription resumed.', 'restore-paypal-standard-for-woocommerce' );
				break;
			default:
				wp_die( esc_html__( 'Unknown action.', 'restore-paypal-standard-for-woocommerce' ) );
		}

		set_transient(
			'rpsfw_sub_admin_notice_' . get_current_user_id(),
			is_wp_error( $result )
				? array( 'type' => 'error', 'message' => $result->get_error_message() )
				: array( 'type' => 'success', 'message' => $notice ),
			60
		);

		$redirect = get_edit_post_link( $subscription_id, 'raw' );
		if ( ! $redirect ) {
			$redirect = admin_url( 'post.php?post=' . $subscription_id . '&action=edit' );
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Show the stored action notice.
	 */
	public static function maybe_show_action_notice() {
		$notice = get_transient( 'rpsfw_sub_admin_notice_' . get_current_user_id() );
		if ( ! $notice || ! is_array( $notice ) ) {
			return;
		}
		delete_transient( 'rpsfw_sub_admin_notice_' . get_current_user_id() );
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ),
			esc_html( $notice['message'] )
		);
	}

	// -----------------------------------------------------------------
	// Settings page.
	// -----------------------------------------------------------------

	/**
	 * Register the (hidden) settings page. Linked from the settings hub —
	 * settings only, records live in the WooCommerce > Subscriptions list.
	 */
	public static function register_settings_page() {
		add_submenu_page(
			'', // No menu entry; reachable via admin.php?page=rpsfw-subscriptions-settings.
			__( 'Subscription Settings', 'restore-paypal-standard-for-woocommerce' ),
			__( 'Subscription Settings', 'restore-paypal-standard-for-woocommerce' ),
			'manage_woocommerce',
			self::SETTINGS_PAGE,
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Save settings when the settings form was posted.
	 */
	public static function maybe_save_settings() {
		if ( ! isset( $_POST['rpsfw_subscriptions_settings_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rpsfw_subscriptions_settings_nonce'] ) ), 'rpsfw_subscriptions_save_settings' ) ) {
			return;
		}

		// While the WooCommerce Subscriptions plugin is active the settings
		// form is locked, so ignore any submission to avoid overwriting the
		// stored configuration.
		if ( rpsfw_wcs_plugin_active() ) {
			return;
		}

		$subscriber_role = isset( $_POST['rpsfw_sub_subscriber_role'] ) ? sanitize_key( wp_unslash( $_POST['rpsfw_sub_subscriber_role'] ) ) : 'subscriber';
		$inactive_role   = isset( $_POST['rpsfw_sub_inactive_role'] ) ? sanitize_key( wp_unslash( $_POST['rpsfw_sub_inactive_role'] ) ) : 'customer';
		$editable_roles  = array_keys( get_editable_roles() );
		if ( ! in_array( $subscriber_role, $editable_roles, true ) ) {
			$subscriber_role = 'subscriber';
		}
		if ( ! in_array( $inactive_role, $editable_roles, true ) ) {
			$inactive_role = 'customer';
		}

		$settings = array(
			'enabled'                  => isset( $_POST['rpsfw_sub_enabled'] ) ? 'yes' : 'no',
			'add_to_cart_button_text'  => isset( $_POST['rpsfw_sub_add_to_cart_text'] ) ? sanitize_text_field( wp_unslash( $_POST['rpsfw_sub_add_to_cart_text'] ) ) : '',
			'place_order_button_text'  => isset( $_POST['rpsfw_sub_place_order_text'] ) ? sanitize_text_field( wp_unslash( $_POST['rpsfw_sub_place_order_text'] ) ) : '',
			'mixed_checkout'           => isset( $_POST['rpsfw_sub_mixed_checkout'] ) ? 'yes' : 'no',
			'customer_can_cancel'      => isset( $_POST['rpsfw_sub_customer_can_cancel'] ) ? 'yes' : 'no',
			'customer_can_suspend'     => isset( $_POST['rpsfw_sub_customer_can_suspend'] ) ? 'yes' : 'no',
			'subscriber_role'          => $subscriber_role,
			'inactive_subscriber_role' => $inactive_role,
		);

		update_option( 'rpsfw_subscriptions_settings', $settings, false );

		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Subscription settings saved.', 'restore-paypal-standard-for-woocommerce' ) . '</p></div>';
			}
		);
	}

	/**
	 * Render the settings page.
	 */
	public static function render_settings_page() {
		$settings   = rpsfw_subscriptions_get_settings();
		$wcs_active = rpsfw_wcs_plugin_active();
		$hub_url    = admin_url( 'admin.php?page=rpsfw-settings-hub' );

		// When the WooCommerce Subscriptions plugin is active it owns new
		// subscription purchases, so the built-in settings below are locked.
		$disabled_attr = $wcs_active ? ' disabled="disabled"' : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Subscription Settings', 'restore-paypal-standard-for-woocommerce' ); ?></h1>
			<p>
				<a href="<?php echo esc_url( $hub_url ); ?>">&larr; <?php esc_html_e( 'Back to Accept PayPal & Stripe Settings', 'restore-paypal-standard-for-woocommerce' ); ?></a>
			</p>

			<?php if ( $wcs_active ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<strong><?php esc_html_e( 'The WooCommerce Subscriptions plugin is active, so these settings are disabled.', 'restore-paypal-standard-for-woocommerce' ); ?></strong>
					</p>
					<p>
						<?php esc_html_e( 'While WooCommerce Subscriptions is active it handles all subscription functionality, and this plugin\'s PayPal Commerce and Stripe gateways integrate with it automatically. The built-in subscriptions engine is turned completely off — no menu, no webhook processing, and no email or role handling — so the two never run at the same time.', 'restore-paypal-standard-for-woocommerce' ); ?>
						</p>
						<p>
							<?php esc_html_e( 'Important: any subscriptions previously created by the built-in engine are NOT serviced while WooCommerce Subscriptions is active — their renewals will not be recorded and their status will not update until you deactivate WooCommerce Subscriptions.', 'restore-paypal-standard-for-woocommerce' ); ?>
					</p>
					<p>
						<?php esc_html_e( 'If you\'d like to use this plugin\'s built-in subscription code instead, deactivate the WooCommerce Subscriptions plugin. These settings will then become editable and take effect.', 'restore-paypal-standard-for-woocommerce' ); ?>
					</p>
				</div>
			<?php else : ?>
				<p class="description" style="max-width:760px;">
					<?php esc_html_e( 'Built-in subscriptions let you sell recurring products through PayPal Commerce and Stripe without any additional plugin. The subscription itself lives at the payment processor - PayPal or Stripe initiates every renewal charge on its own infrastructure, and this store mirrors payments and status changes via webhooks. Mark any simple product or variation as a subscription from its edit screen to get started. PayPal Standard does not support subscriptions.', 'restore-paypal-standard-for-woocommerce' ); ?>
				</p>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'rpsfw_subscriptions_save_settings', 'rpsfw_subscriptions_settings_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable subscriptions', 'restore-paypal-standard-for-woocommerce' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="rpsfw_sub_enabled" value="1" <?php checked( $settings['enabled'], 'yes' ); ?><?php echo $disabled_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
								<?php esc_html_e( 'Enable built-in subscription functionality', 'restore-paypal-standard-for-woocommerce' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Adds subscription options to products and enables recurring checkout with PayPal Commerce and Stripe. Disabling this hides the purchase options but keeps existing subscriptions billing and manageable.', 'restore-paypal-standard-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rpsfw_sub_add_to_cart_text"><?php esc_html_e( 'Add to cart button text', 'restore-paypal-standard-for-woocommerce' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="rpsfw_sub_add_to_cart_text" name="rpsfw_sub_add_to_cart_text" value="<?php echo esc_attr( $settings['add_to_cart_button_text'] ); ?>" placeholder="<?php esc_attr_e( 'Add to cart', 'restore-paypal-standard-for-woocommerce' ); ?>"<?php echo $disabled_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
							<p class="description"><?php esc_html_e( 'Button text shown for subscription products in the shop and on product pages. Leave blank to use the standard "Add to cart" label. Example: "Sign up now".', 'restore-paypal-standard-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rpsfw_sub_place_order_text"><?php esc_html_e( 'Place order button text', 'restore-paypal-standard-for-woocommerce' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="rpsfw_sub_place_order_text" name="rpsfw_sub_place_order_text" value="<?php echo esc_attr( $settings['place_order_button_text'] ); ?>" placeholder="<?php esc_attr_e( 'Place order', 'restore-paypal-standard-for-woocommerce' ); ?>"<?php echo $disabled_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
							<p class="description"><?php esc_html_e( 'Button text on the checkout page when the cart contains a subscription. Leave blank to use the standard "Place order" label. Example: "Sign up now".', 'restore-paypal-standard-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Mixed checkout', 'restore-paypal-standard-for-woocommerce' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="rpsfw_sub_mixed_checkout" value="1" <?php checked( $settings['mixed_checkout'], 'yes' ); ?><?php echo $disabled_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
								<?php esc_html_e( 'Allow a subscription to be purchased together with one-time products', 'restore-paypal-standard-for-woocommerce' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When enabled, one-time products in the same order are charged with the first payment. When disabled, subscriptions must be purchased separately from one-time products. Stripe supports multiple subscriptions per order; PayPal Commerce supports one subscription per order and hides itself at checkout when the cart has more.', 'restore-paypal-standard-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Customer cancellations', 'restore-paypal-standard-for-woocommerce' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="rpsfw_sub_customer_can_cancel" value="1" <?php checked( $settings['customer_can_cancel'], 'yes' ); ?><?php echo $disabled_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
								<?php esc_html_e( 'Allow customers to cancel their subscriptions from My Account', 'restore-paypal-standard-for-woocommerce' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Stripe subscriptions cancelled by the customer stay active until the end of the paid period. PayPal cancellations stop future billing immediately. Customers can always cancel PayPal subscriptions from their own PayPal account regardless of this setting.', 'restore-paypal-standard-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Customer pausing', 'restore-paypal-standard-for-woocommerce' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="rpsfw_sub_customer_can_suspend" value="1" <?php checked( $settings['customer_can_suspend'], 'yes' ); ?><?php echo $disabled_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
								<?php esc_html_e( 'Allow customers to pause and resume their subscriptions from My Account', 'restore-paypal-standard-for-woocommerce' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Billing stops while paused and resumes when the customer (or you) resumes the subscription.', 'restore-paypal-standard-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rpsfw_sub_subscriber_role"><?php esc_html_e( 'Subscriber default role', 'restore-paypal-standard-for-woocommerce' ); ?></label></th>
						<td>
							<select id="rpsfw_sub_subscriber_role" name="rpsfw_sub_subscriber_role"<?php echo $disabled_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
								<?php wp_dropdown_roles( $settings['subscriber_role'] ); ?>
							</select>
							<p class="description"><?php esc_html_e( 'The role given to customers while they have an active subscription. A customer account is always created for subscription purchases (guest checkout is disabled for subscription carts). Administrators and shop managers are never re-assigned.', 'restore-paypal-standard-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rpsfw_sub_inactive_role"><?php esc_html_e( 'Inactive subscriber role', 'restore-paypal-standard-for-woocommerce' ); ?></label></th>
						<td>
							<select id="rpsfw_sub_inactive_role" name="rpsfw_sub_inactive_role"<?php echo $disabled_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
								<?php wp_dropdown_roles( $settings['inactive_subscriber_role'] ); ?>
							</select>
							<p class="description"><?php esc_html_e( 'The role given to customers once their last remaining subscription is cancelled, paused or ends.', 'restore-paypal-standard-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Customer emails', 'restore-paypal-standard-for-woocommerce' ); ?></th>
						<td>
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to the WooCommerce email settings */
									esc_html__( 'Subscription notification emails (new subscription, cancelled, payment failed, ended) can be enabled, disabled and customized under %s.', 'restore-paypal-standard-for-woocommerce' ),
									'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=email' ) ) . '">' . esc_html__( 'WooCommerce → Settings → Emails', 'restore-paypal-standard-for-woocommerce' ) . '</a>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>
				<?php if ( ! $wcs_active ) : ?>
					<?php submit_button( __( 'Save settings', 'restore-paypal-standard-for-woocommerce' ) ); ?>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	// -----------------------------------------------------------------
	// Hub card.
	// -----------------------------------------------------------------

	/**
	 * Render the Subscriptions card on the settings hub. Called from
	 * admin-hub.php on its own row under the gateway cards.
	 */
	public static function render_hub_card() {
		$enabled    = rpsfw_native_subscriptions_enabled();
		$wcs_active = rpsfw_wcs_plugin_active();

		$settings_url = admin_url( 'admin.php?page=' . self::SETTINGS_PAGE );
		?>
		<div class="rpsfw-hub-box rpsfw-hub-box-subscriptions">
			<div class="rpsfw-hub-box-header">
				<div class="rpsfw-hub-box-icon">
					<span class="dashicons dashicons-update" style="font-size:28px;width:28px;height:28px;color:#2271b1;"></span>
				</div>
				<span class="rpsfw-status-badges">
					<?php if ( $wcs_active ) : ?>
						<span class="rpsfw-status-badge rpsfw-status-testmode"><?php esc_html_e( 'Using WooCommerce Subscriptions', 'restore-paypal-standard-for-woocommerce' ); ?></span>
					<?php elseif ( $enabled ) : ?>
						<span class="rpsfw-status-badge rpsfw-status-enabled"><?php esc_html_e( 'Enabled', 'restore-paypal-standard-for-woocommerce' ); ?></span>
					<?php else : ?>
						<span class="rpsfw-status-badge rpsfw-status-disabled"><?php esc_html_e( 'Disabled', 'restore-paypal-standard-for-woocommerce' ); ?></span>
					<?php endif; ?>
				</span>
			</div>
			<h2><?php esc_html_e( 'Subscriptions', 'restore-paypal-standard-for-woocommerce' ); ?></h2>
			<p>
				<?php esc_html_e( 'Accept subscriptions for PayPal Commerce and Stripe.', 'restore-paypal-standard-for-woocommerce' ); ?>
			</p>
			<div>
				<a href="<?php echo esc_url( $settings_url ); ?>" class="button button-primary"><?php esc_html_e( 'Configure', 'restore-paypal-standard-for-woocommerce' ); ?></a>
			</div>
		</div>
		<?php
	}
}
