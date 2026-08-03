<?php
/**
 * Registers the rpsfw_subscription custom post type, its custom statuses,
 * and the native WordPress admin list table (shown under the WooCommerce
 * menu, like the WooCommerce Subscriptions plugin does).
 *
 * @package Restore_PayPal_Standard_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RPSFW_Subscriptions_Post_Type
 */
class RPSFW_Subscriptions_Post_Type {

	const POST_TYPE = 'rpsfw_subscription';

	/**
	 * Wire everything up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ), 6 );
		add_action( 'init', array( __CLASS__, 'register_post_statuses' ), 7 );

		// Native list table customization.
		add_filter( 'manage_edit-' . self::POST_TYPE . '_columns', array( __CLASS__, 'list_table_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_list_table_column' ), 10, 2 );
		add_filter( 'list_table_primary_column', array( __CLASS__, 'list_table_primary_column' ), 10, 2 );
		// Both run late (999) so they filter out actions added by other plugins
		// regardless of load order — these lists are allow-lists, and a
		// third-party filter registered after ours would otherwise re-add its
		// own entries afterwards.
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 999, 2 );
		add_filter( 'bulk_actions-edit-' . self::POST_TYPE, array( __CLASS__, 'bulk_actions' ), 999 );
		add_filter( 'wp_untrash_post_status', array( __CLASS__, 'untrash_post_status' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'trashed_notice' ) );
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'handle_sorting' ) );
		add_action( 'admin_head', array( __CLASS__, 'list_table_styles' ) );

		// Keep subscription notes (stored as comments) out of front-end
		// comment queries, feeds and counts — same approach WooCommerce
		// uses for order notes.
		add_filter( 'comments_clauses', array( __CLASS__, 'exclude_note_comments' ), 10, 1 );
		add_filter( 'comment_feed_where', array( __CLASS__, 'exclude_note_comments_from_feed' ) );
	}

	/**
	 * Register the post type.
	 */
	public static function register_post_type() {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}

		// This only runs when the module is enabled AND the WooCommerce
		// Subscriptions plugin is NOT active (see the call-site gating in
		// rpsfw_subscriptions_init(): when WCS is active the entire built-in
		// module — including this post type and its menu — is turned off). So
		// there is never a second "Subscriptions" menu competing with WCS, and
		// our menu can use the plain label unconditionally.
		$show_in_menu = 'woocommerce';
		$menu_name    = __( 'Subscriptions', 'restore-paypal-standard-for-woocommerce' );

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Subscriptions', 'restore-paypal-standard-for-woocommerce' ),
					'singular_name'      => __( 'Subscription', 'restore-paypal-standard-for-woocommerce' ),
					'menu_name'          => $menu_name,
					'edit_item'          => __( 'Subscription', 'restore-paypal-standard-for-woocommerce' ),
					'search_items'       => __( 'Search subscriptions', 'restore-paypal-standard-for-woocommerce' ),
					'not_found'          => __( 'No subscriptions found. Subscriptions are created automatically when a customer purchases a subscription product.', 'restore-paypal-standard-for-woocommerce' ),
					'not_found_in_trash' => __( 'No subscriptions found in trash', 'restore-paypal-standard-for-woocommerce' ),
				),
				'description'         => __( 'Customer subscriptions managed by PayPal Commerce or Stripe.', 'restore-paypal-standard-for-woocommerce' ),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => $show_in_menu,
				'show_in_admin_bar'   => false,
				'show_in_nav_menus'   => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'hierarchical'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title' ),
				'capability_type'     => 'shop_order',
				'map_meta_cap'        => true,
				'capabilities'        => array(
					// Subscriptions are only created by checkout — no "Add New".
					'create_posts' => 'do_not_allow',
				),
			)
		);
	}

	/**
	 * Register custom post statuses used for subscription state, so the
	 * native list table shows status views (All | Active | On hold | ...)
	 * for free.
	 */
	public static function register_post_statuses() {
		foreach ( rpsfw_get_subscription_statuses() as $status => $label ) {
			register_post_status(
				'rpsfw-' . $status,
				array(
					'label'                     => $label,
					'public'                    => false,
					'internal'                  => false,
					'exclude_from_search'       => true,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					// Build the nooped-plural array manually because the label is
					// dynamic and _n_noop() requires literal string arguments.
					'label_count'               => array(
						0          => $label . ' <span class="count">(%s)</span>',
						1          => $label . ' <span class="count">(%s)</span>',
						'singular' => $label . ' <span class="count">(%s)</span>',
						'plural'   => $label . ' <span class="count">(%s)</span>',
						'context'  => null,
						'domain'   => 'restore-paypal-standard-for-woocommerce',
					),
				)
			);
		}
	}

	/**
	 * List table columns.
	 *
	 * @param array $columns Default columns.
	 * @return array
	 */
	public static function list_table_columns( $columns ) {
		return array(
			'cb'                   => isset( $columns['cb'] ) ? $columns['cb'] : '<input type="checkbox" />',
			'rpsfw_status'         => __( 'Status', 'restore-paypal-standard-for-woocommerce' ),
			'rpsfw_subscription'   => __( 'Subscription', 'restore-paypal-standard-for-woocommerce' ),
			'rpsfw_items'          => __( 'Items', 'restore-paypal-standard-for-woocommerce' ),
			'rpsfw_total'          => __( 'Recurring total', 'restore-paypal-standard-for-woocommerce' ),
			'rpsfw_gateway'        => __( 'Payment method', 'restore-paypal-standard-for-woocommerce' ),
			'rpsfw_start_date'     => __( 'Start date', 'restore-paypal-standard-for-woocommerce' ),
			'rpsfw_next_payment'   => __( 'Next payment', 'restore-paypal-standard-for-woocommerce' ),
		);
	}

	/**
	 * Render a custom column.
	 *
	 * @param string $column  Column id.
	 * @param int    $post_id Post id.
	 */
	public static function render_list_table_column( $column, $post_id ) {
		$subscription = rpsfw_get_subscription( $post_id );
		if ( ! $subscription ) {
			return;
		}

		switch ( $column ) {
			case 'rpsfw_status':
				$status = $subscription->get_status();
				printf(
					'<mark class="rpsfw-sub-status rpsfw-sub-status-%1$s"><span>%2$s</span></mark>',
					esc_attr( $status ),
					esc_html( rpsfw_get_subscription_status_label( $status ) )
				);
				break;

			case 'rpsfw_subscription':
				$customer  = $subscription->get_customer_name();
				$edit_link = get_edit_post_link( $post_id );
				printf(
					'<a href="%1$s" class="row-title"><strong>#%2$s</strong></a> %3$s',
					esc_url( $edit_link ),
					esc_html( $post_id ),
					$customer ? esc_html( sprintf( '— %s', $customer ) ) : ''
				);
				break;

			case 'rpsfw_items':
				echo esc_html( $subscription->get_item_name() );
				$qty = $subscription->get_quantity();
				if ( $qty > 1 ) {
					echo esc_html( ' × ' . $qty );
				}
				break;

			case 'rpsfw_total':
				echo wp_kses_post( $subscription->get_formatted_recurring_total() );
				break;

			case 'rpsfw_gateway':
				echo esc_html( $subscription->get_gateway_title( 'admin' ) );
				break;

			case 'rpsfw_start_date':
				echo esc_html( $subscription->get_date_to_display( 'start' ) );
				break;

			case 'rpsfw_next_payment':
				echo esc_html( $subscription->get_date_to_display( 'next_payment' ) );
				break;
		}
	}

	/**
	 * Make our subscription column the primary one (row actions attach to it).
	 *
	 * @param string $default_column Default primary column.
	 * @param string $screen_id      Screen id.
	 * @return string
	 */
	public static function list_table_primary_column( $default_column, $screen_id ) {
		if ( 'edit-' . self::POST_TYPE === $screen_id ) {
			return 'rpsfw_subscription';
		}
		return $default_column;
	}

	/**
	 * Row actions: Edit (relabelled "View / Edit") plus core's trash links.
	 * Quick Edit is dropped — status changes must go through the gateway, so
	 * cancelling is done from the record itself, not by editing a field.
	 *
	 * @param array   $actions Actions.
	 * @param WP_Post $post    Post.
	 * @return array
	 */
	public static function row_actions( $actions, $post ) {
		if ( self::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		$new_actions = array();
		if ( isset( $actions['edit'] ) ) {
			$new_actions['edit'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( get_edit_post_link( $post->ID ) ),
				esc_html__( 'View / Edit', 'restore-paypal-standard-for-woocommerce' )
			);
		}

		// Keep core's own nonced links: Trash on the normal views, Restore and
		// Delete permanently on the Trash view.
		foreach ( array( 'trash', 'untrash', 'delete' ) as $key ) {
			if ( isset( $actions[ $key ] ) ) {
				$new_actions[ $key ] = $actions[ $key ];
			}
		}

		return $new_actions;
	}

	/**
	 * Bulk actions.
	 *
	 * An allow-list, not a deny-list. Third-party plugins add bulk actions
	 * across every post type they can see — CSV exporters especially — and a
	 * subscription record is not a document: exporting or mass-editing one is
	 * meaningless at best and misleading at worst, since the authoritative state
	 * lives at the processor. Only the trash actions are kept: "Move to Trash"
	 * on the normal views, "Restore" / "Delete permanently" on the Trash view.
	 * Quick Edit stays out because status changes must go through the gateway.
	 *
	 * Trashing is local only — it does not cancel anything at Stripe or PayPal,
	 * which is what the notice below warns about.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public static function bulk_actions( $actions ) {
		$allowed = array();
		foreach ( array( 'trash', 'untrash', 'delete' ) as $key ) {
			if ( isset( $actions[ $key ] ) ) {
				$allowed[ $key ] = $actions[ $key ];
			}
		}

		/**
		 * Filter the bulk actions offered on the subscriptions list table.
		 *
		 * @since 4.0.0
		 *
		 * @param array $allowed Actions kept by this plugin.
		 * @param array $actions Every action offered before filtering.
		 */
		return apply_filters( 'rpsfw_subscription_bulk_actions', $allowed, $actions );
	}

	/**
	 * Restore a subscription to its own status when it leaves the trash.
	 *
	 * Since WordPress 5.6 wp_untrash_post() restores to 'draft' rather than the
	 * previous status. On this post type the subscription's status IS the post
	 * status, so the default would leave a restored record as a draft — absent
	 * from the list table, from My Account, and from every webhook lookup (they
	 * query the explicit rpsfw-* status list). Put the real status back.
	 *
	 * @param string $new_status      Status core intends to restore to.
	 * @param int    $post_id         Post id.
	 * @param string $previous_status Status the record had when trashed.
	 * @return string
	 */
	public static function untrash_post_status( $new_status, $post_id, $previous_status ) {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return $new_status;
		}

		// Only trust a status that is actually one of ours.
		return ( 0 === strpos( (string) $previous_status, 'rpsfw-' ) ) ? $previous_status : $new_status;
	}

	/**
	 * Warn when a record that was still billing has been trashed.
	 *
	 * Core already reports "N posts moved to the Trash. Undo"; this adds the
	 * part core cannot know — that the money does not stop.
	 */
	public static function trashed_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-' . self::POST_TYPE !== $screen->id ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only inspection of core's own post-trash redirect args.
		if ( empty( $_REQUEST['trashed'] ) || empty( $_REQUEST['ids'] ) ) {
			return;
		}
		$ids = array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_REQUEST['ids'] ) ) ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$billing = 0;
		foreach ( $ids as $id ) {
			if ( self::POST_TYPE !== get_post_type( $id ) ) {
				continue;
			}
			// The pre-trash status is stashed by core; these are the states in
			// which the processor is still charging the customer.
			$was = get_post_meta( $id, '_wp_trash_meta_status', true );
			if ( in_array( $was, array( 'rpsfw-active', 'rpsfw-pending-cancel', 'rpsfw-on-hold' ), true ) ) {
				$billing++;
			}
		}

		if ( ! $billing ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: number of trashed records that were still billing */
					_n(
						'%s of the trashed subscriptions was still billing. Trashing it here does not cancel it at Stripe or PayPal — the customer will keep being charged, and renewals will not be recorded while the record is in the trash. Cancel it in the processor dashboard, or restore the record.',
						'%s of the trashed subscriptions were still billing. Trashing them here does not cancel them at Stripe or PayPal — those customers will keep being charged, and renewals will not be recorded while the records are in the trash. Cancel them in the processor dashboard, or restore the records.',
						$billing,
						'restore-paypal-standard-for-woocommerce'
					),
					number_format_i18n( $billing )
				)
			)
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function sortable_columns( $columns ) {
		$columns['rpsfw_start_date']   = 'rpsfw_start_date';
		$columns['rpsfw_next_payment'] = 'rpsfw_next_payment';
		return $columns;
	}

	/**
	 * Adjust the admin list query: make records visible on the "All" view
	 * and handle sorting for our meta-based columns.
	 *
	 * Visibility: our custom statuses are registered with public=false (they
	 * must never leak to the front end), but WP_Query only includes public/
	 * protected statuses when no post_status is specified — so the "All"
	 * list would render empty even though records exist. Explicitly inject
	 * our statuses when none is requested, the same way WooCommerce does
	 * for its order statuses.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function handle_sorting( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || self::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		$post_status = $query->get( 'post_status' );
		if ( empty( $post_status ) || 'all' === $post_status ) {
			$query->set(
				'post_status',
				array_map(
					function ( $status ) {
						return 'rpsfw-' . $status;
					},
					array_keys( rpsfw_get_subscription_statuses() )
				)
			);
		}

		$orderby = $query->get( 'orderby' );
		if ( 'rpsfw_start_date' === $orderby ) {
			$query->set( 'meta_key', '_rpsfw_start_date' );
			$query->set( 'orderby', 'meta_value' );
		} elseif ( 'rpsfw_next_payment' === $orderby ) {
			$query->set( 'meta_key', '_rpsfw_next_payment' );
			$query->set( 'orderby', 'meta_value' );
		}
	}

	/**
	 * Small status-pill styles for the list table (mirrors WooCommerce's
	 * order status pills).
	 */
	public static function list_table_styles() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-' . self::POST_TYPE !== $screen->id ) {
			return;
		}
		?>
		<style>
			.rpsfw-sub-status { display: inline-flex; padding: 4px 8px; line-height: 1.4; border-radius: 4px; background: #e5e5e5; color: #454545; font-size: 12px; white-space: nowrap; }
			.rpsfw-sub-status-active { background: #c6e1c6; color: #2c4700; }
			.rpsfw-sub-status-pending { background: #f8dda7; color: #573b00; }
			.rpsfw-sub-status-on-hold { background: #f8dda7; color: #573b00; }
			.rpsfw-sub-status-pending-cancel { background: #f8dda7; color: #573b00; }
			.rpsfw-sub-status-cancelled { background: #eba3a3; color: #571a1a; }
			.rpsfw-sub-status-expired { background: #e5e5e5; color: #454545; }
			.wp-list-table .column-rpsfw_status { width: 110px; }
		</style>
		<?php
	}

	/**
	 * Exclude subscription note comments from regular comment queries.
	 *
	 * @param array $clauses Query clauses.
	 * @return array
	 */
	public static function exclude_note_comments( $clauses ) {
		$clauses['where'] .= ( $clauses['where'] ? ' AND ' : '' ) . " comment_type != 'rpsfw_sub_note' ";
		return $clauses;
	}

	/**
	 * Exclude subscription note comments from feeds.
	 *
	 * @param string $where Where clause.
	 * @return string
	 */
	public static function exclude_note_comments_from_feed( $where ) {
		return $where . ( $where ? ' AND ' : '' ) . " comment_type != 'rpsfw_sub_note' ";
	}
}
