<?php
/**
 * Subscription product meta.
 *
 * Adds subscription settings to simple products and variations (billing
 * interval/period, number of payments, free trial, sign-up fee), and
 * decorates the front-end price display. The product's regular/sale price
 * is the recurring price.
 *
 * The static helpers are always available (used by record servicing), but
 * the admin fields and front-end display are only hooked when native mode
 * is active (module enabled + WooCommerce Subscriptions plugin absent).
 *
 * @package Restore_PayPal_Standard_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RPSFW_Subscription_Product
 */
class RPSFW_Subscription_Product {

	/**
	 * Hook admin fields and front-end display. Only called in native mode.
	 */
	public static function init() {
		// Simple product fields (General tab, under pricing).
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_simple_product_fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_simple_product_fields' ) );

		// Variation fields.
		add_action( 'woocommerce_variation_options', array( __CLASS__, 'render_variation_checkbox' ), 10, 3 );
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation_fields' ), 10, 2 );

		// Toggle field visibility in the product editor.
		add_action( 'admin_footer', array( __CLASS__, 'admin_toggle_script' ) );

		// Front-end display.
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'filter_price_html' ), 10, 2 );
		add_filter( 'woocommerce_product_single_add_to_cart_text', array( __CLASS__, 'filter_add_to_cart_text' ), 10, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( __CLASS__, 'filter_add_to_cart_text' ), 10, 2 );

		// Subscriptions are sold-individually per checkout constraints when
		// mixed checkout is off; quantity itself stays configurable.
	}

	// -----------------------------------------------------------------
	// Meta helpers (always available).
	// -----------------------------------------------------------------

	/**
	 * Resolve a product object.
	 *
	 * @param WC_Product|int $product Product or id.
	 * @return WC_Product|false
	 */
	private static function get_product( $product ) {
		if ( $product instanceof WC_Product ) {
			return $product;
		}
		return $product ? wc_get_product( $product ) : false;
	}

	/**
	 * Whether a product (simple or variation) is a native subscription.
	 *
	 * @param WC_Product|int $product Product or id.
	 * @return bool
	 */
	public static function is_subscription( $product ) {
		$product = self::get_product( $product );
		if ( ! $product ) {
			return false;
		}
		return 'yes' === $product->get_meta( '_rpsfw_is_subscription' );
	}

	/**
	 * Whether a variable product has at least one subscription variation.
	 *
	 * @param WC_Product $product Variable product.
	 * @return bool
	 */
	public static function has_subscription_variation( $product ) {
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return false;
		}
		foreach ( $product->get_children() as $child_id ) {
			if ( self::is_subscription( $child_id ) ) {
				return true;
			}
		}
		return false;
	}

	/** @return string day|week|month|year */
	public static function get_period( $product ) {
		$product = self::get_product( $product );
		$period  = $product ? $product->get_meta( '_rpsfw_sub_period' ) : '';
		return in_array( $period, array( 'day', 'week', 'month', 'year' ), true ) ? $period : 'month';
	}

	/** @return int Billing interval (1-6). */
	public static function get_interval( $product ) {
		$product = self::get_product( $product );
		return $product ? max( 1, min( 6, (int) $product->get_meta( '_rpsfw_sub_interval' ) ) ) : 1;
	}

	/** @return int Total number of payments (0 = until cancelled). */
	public static function get_length( $product ) {
		$product = self::get_product( $product );
		return $product ? max( 0, (int) $product->get_meta( '_rpsfw_sub_length' ) ) : 0;
	}

	/** @return int Free trial length (0 = none). */
	public static function get_trial_length( $product ) {
		$product = self::get_product( $product );
		return $product ? max( 0, (int) $product->get_meta( '_rpsfw_sub_trial_length' ) ) : 0;
	}

	/** @return string day|week|month|year */
	public static function get_trial_period( $product ) {
		$product = self::get_product( $product );
		$period  = $product ? $product->get_meta( '_rpsfw_sub_trial_period' ) : '';
		return in_array( $period, array( 'day', 'week', 'month', 'year' ), true ) ? $period : 'day';
	}

	/** @return float Sign-up fee. */
	public static function get_signup_fee( $product ) {
		$product = self::get_product( $product );
		return $product ? (float) $product->get_meta( '_rpsfw_sub_signup_fee' ) : 0.0;
	}

	/** @return float Recurring price (the product price). */
	public static function get_recurring_price( $product ) {
		$product = self::get_product( $product );
		return $product ? (float) $product->get_price() : 0.0;
	}

	/**
	 * Build the descriptive suffix appended to prices, e.g.
	 * " / month with a 14-day free trial and a $5.00 sign-up fee".
	 *
	 * @param WC_Product|int $product Product.
	 * @return string HTML.
	 */
	public static function get_price_suffix( $product ) {
		$product = self::get_product( $product );
		if ( ! $product || ! self::is_subscription( $product ) ) {
			return '';
		}

		$schedule = rpsfw_format_subscription_period( self::get_interval( $product ), self::get_period( $product ) );
		$suffix   = ' <span class="rpsfw-sub-details">' . esc_html( $schedule );

		$trial = rpsfw_format_subscription_trial( self::get_trial_length( $product ), self::get_trial_period( $product ) );
		if ( $trial ) {
			/* translators: %s: trial description, e.g. "14-day free trial" */
			$suffix .= esc_html( sprintf( __( ' with a %s', 'restore-paypal-standard-for-woocommerce' ), $trial ) );
		}

		$signup_fee = self::get_signup_fee( $product );
		if ( $signup_fee > 0 ) {
			$suffix .= ' ' . wp_kses_post(
				sprintf(
					/* translators: %s: sign-up fee amount */
					__( 'and a %s sign-up fee', 'restore-paypal-standard-for-woocommerce' ),
					wc_price( $signup_fee )
				)
			);
		}

		$length = self::get_length( $product );
		if ( $length > 0 ) {
			$suffix .= ' ' . esc_html(
				sprintf(
					/* translators: %d: number of payments */
					_n( 'for %d payment', 'for %d payments', $length, 'restore-paypal-standard-for-woocommerce' ),
					$length
				)
			);
		}

		$suffix .= '</span>';

		return apply_filters( 'rpsfw_subscription_price_suffix', $suffix, $product );
	}

	// -----------------------------------------------------------------
	// Front-end display.
	// -----------------------------------------------------------------

	/**
	 * Append the subscription details to price HTML.
	 *
	 * @param string     $price_html Price HTML.
	 * @param WC_Product $product    Product.
	 * @return string
	 */
	public static function filter_price_html( $price_html, $product ) {
		if ( ! $price_html || ! self::is_subscription( $product ) ) {
			return $price_html;
		}
		return $price_html . self::get_price_suffix( $product );
	}

	/**
	 * Use the configured add-to-cart button text for subscription products.
	 *
	 * @param string     $text    Button text.
	 * @param WC_Product $product Product.
	 * @return string
	 */
	public static function filter_add_to_cart_text( $text, $product ) {
		$is_subscription = self::is_subscription( $product )
			|| ( $product && $product->is_type( 'variable' ) && self::has_subscription_variation( $product ) );

		if ( ! $is_subscription ) {
			return $text;
		}

		$custom = rpsfw_subscriptions_get_setting( 'add_to_cart_button_text' );
		return $custom ? __( $custom, 'restore-paypal-standard-for-woocommerce' ) : $text; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
	}

	// -----------------------------------------------------------------
	// Admin: simple product fields.
	// -----------------------------------------------------------------

	/**
	 * Render fields on the General product data tab.
	 */
	public static function render_simple_product_fields() {
		global $post;

		echo '<div class="options_group rpsfw-subscription-options show_if_simple">';

		woocommerce_wp_checkbox(
			array(
				'id'          => '_rpsfw_is_subscription',
				'label'       => __( 'Subscription', 'restore-paypal-standard-for-woocommerce' ),
				'description' => __( 'Sell this product as a subscription with recurring payments via PayPal or Stripe. The product price above is the recurring price.', 'restore-paypal-standard-for-woocommerce' ),
			)
		);

		echo '<div class="rpsfw-subscription-fields">';
		self::render_schedule_fields( $post->ID );
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Render the shared schedule fields for a product/variation.
	 *
	 * @param int    $post_id     Product or variation id.
	 * @param string $loop_prefix Field name prefix for variation loops ('' for simple).
	 */
	private static function render_schedule_fields( $post_id, $loop_prefix = '' ) {
		$interval_options = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$interval_options[ $i ] = 1 === $i
				? __( 'every', 'restore-paypal-standard-for-woocommerce' )
				/* translators: %d: billing interval */
				: sprintf( __( 'every %d', 'restore-paypal-standard-for-woocommerce' ), $i );
		}

		$period_options = array(
			'day'   => __( 'day(s)', 'restore-paypal-standard-for-woocommerce' ),
			'week'  => __( 'week(s)', 'restore-paypal-standard-for-woocommerce' ),
			'month' => __( 'month(s)', 'restore-paypal-standard-for-woocommerce' ),
			'year'  => __( 'year(s)', 'restore-paypal-standard-for-woocommerce' ),
		);

		woocommerce_wp_select(
			array(
				'id'            => $loop_prefix ? "_rpsfw_sub_interval{$loop_prefix}" : '_rpsfw_sub_interval',
				'name'          => $loop_prefix ? "_rpsfw_sub_interval{$loop_prefix}" : '_rpsfw_sub_interval',
				'label'         => __( 'Renews', 'restore-paypal-standard-for-woocommerce' ),
				'options'       => $interval_options,
				'value'         => (string) max( 1, (int) get_post_meta( $post_id, '_rpsfw_sub_interval', true ) ),
				'wrapper_class' => 'rpsfw-sub-field',
			)
		);

		woocommerce_wp_select(
			array(
				'id'            => $loop_prefix ? "_rpsfw_sub_period{$loop_prefix}" : '_rpsfw_sub_period',
				'name'          => $loop_prefix ? "_rpsfw_sub_period{$loop_prefix}" : '_rpsfw_sub_period',
				'label'         => __( 'Billing period', 'restore-paypal-standard-for-woocommerce' ),
				'options'       => $period_options,
				'value'         => self::get_period( $post_id ),
				'wrapper_class' => 'rpsfw-sub-field',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => $loop_prefix ? "_rpsfw_sub_length{$loop_prefix}" : '_rpsfw_sub_length',
				'name'              => $loop_prefix ? "_rpsfw_sub_length{$loop_prefix}" : '_rpsfw_sub_length',
				'label'             => __( 'Number of payments', 'restore-paypal-standard-for-woocommerce' ),
				'description'       => __( 'Total number of payments before the subscription ends automatically, including the first payment taken at checkout. Enter 3 and the customer is charged 3 times in total. A free trial does not count as a payment. Leave 0 to renew until cancelled.', 'restore-paypal-standard-for-woocommerce' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'value'             => (string) max( 0, (int) get_post_meta( $post_id, '_rpsfw_sub_length', true ) ),
				'wrapper_class'     => 'rpsfw-sub-field',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'            => $loop_prefix ? "_rpsfw_sub_signup_fee{$loop_prefix}" : '_rpsfw_sub_signup_fee',
				'name'          => $loop_prefix ? "_rpsfw_sub_signup_fee{$loop_prefix}" : '_rpsfw_sub_signup_fee',
				/* translators: %s: currency symbol */
				'label'         => sprintf( __( 'Sign-up fee (%s)', 'restore-paypal-standard-for-woocommerce' ), get_woocommerce_currency_symbol() ),
				'description'   => __( 'A one-time fee charged with the first payment. Leave blank for none.', 'restore-paypal-standard-for-woocommerce' ),
				'desc_tip'      => true,
				'data_type'     => 'price',
				'value'         => ( '' !== get_post_meta( $post_id, '_rpsfw_sub_signup_fee', true ) && (float) get_post_meta( $post_id, '_rpsfw_sub_signup_fee', true ) > 0 )
					? wc_format_localized_price( get_post_meta( $post_id, '_rpsfw_sub_signup_fee', true ) )
					: '',
				'wrapper_class' => 'rpsfw-sub-field',
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => $loop_prefix ? "_rpsfw_sub_trial_length{$loop_prefix}" : '_rpsfw_sub_trial_length',
				'name'              => $loop_prefix ? "_rpsfw_sub_trial_length{$loop_prefix}" : '_rpsfw_sub_trial_length',
				'label'             => __( 'Free trial', 'restore-paypal-standard-for-woocommerce' ),
				'description'       => __( 'Length of the free trial before the first payment. Leave 0 for no trial.', 'restore-paypal-standard-for-woocommerce' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '1',
				),
				'value'             => (string) max( 0, (int) get_post_meta( $post_id, '_rpsfw_sub_trial_length', true ) ),
				'wrapper_class'     => 'rpsfw-sub-field',
			)
		);

		woocommerce_wp_select(
			array(
				'id'            => $loop_prefix ? "_rpsfw_sub_trial_period{$loop_prefix}" : '_rpsfw_sub_trial_period',
				'name'          => $loop_prefix ? "_rpsfw_sub_trial_period{$loop_prefix}" : '_rpsfw_sub_trial_period',
				'label'         => __( 'Trial period', 'restore-paypal-standard-for-woocommerce' ),
				'options'       => $period_options,
				'value'         => self::get_trial_period( $post_id ),
				'wrapper_class' => 'rpsfw-sub-field',
			)
		);
	}

	/**
	 * Save simple product fields.
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public static function save_simple_product_fields( $product ) {
		// Variations are saved separately; only handle non-variable types here.
		if ( $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) || $product->is_type( 'external' ) ) {
			$product->update_meta_data( '_rpsfw_is_subscription', 'no' );
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the product-save nonce.
		$is_subscription = isset( $_POST['_rpsfw_is_subscription'] ) ? 'yes' : 'no';
		$product->update_meta_data( '_rpsfw_is_subscription', $is_subscription );

		if ( 'yes' === $is_subscription ) {
			self::save_schedule_fields_to_object( $product, $_POST, '' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Copy posted schedule fields onto a product object.
	 *
	 * @param WC_Product $product Product (or variation) object.
	 * @param array      $data    Posted data.
	 * @param string     $suffix  Field name suffix for variation loops.
	 */
	private static function save_schedule_fields_to_object( $product, $data, $suffix = '' ) {
		$interval = isset( $data[ "_rpsfw_sub_interval{$suffix}" ] ) ? max( 1, min( 6, (int) $data[ "_rpsfw_sub_interval{$suffix}" ] ) ) : 1;
		$period   = isset( $data[ "_rpsfw_sub_period{$suffix}" ] ) ? sanitize_text_field( wp_unslash( $data[ "_rpsfw_sub_period{$suffix}" ] ) ) : 'month';
		if ( ! in_array( $period, array( 'day', 'week', 'month', 'year' ), true ) ) {
			$period = 'month';
		}
		$length       = isset( $data[ "_rpsfw_sub_length{$suffix}" ] ) ? max( 0, (int) $data[ "_rpsfw_sub_length{$suffix}" ] ) : 0;
		$signup_fee   = isset( $data[ "_rpsfw_sub_signup_fee{$suffix}" ] ) ? wc_format_decimal( wp_unslash( $data[ "_rpsfw_sub_signup_fee{$suffix}" ] ) ) : '';
		$trial_length = isset( $data[ "_rpsfw_sub_trial_length{$suffix}" ] ) ? max( 0, (int) $data[ "_rpsfw_sub_trial_length{$suffix}" ] ) : 0;
		$trial_period = isset( $data[ "_rpsfw_sub_trial_period{$suffix}" ] ) ? sanitize_text_field( wp_unslash( $data[ "_rpsfw_sub_trial_period{$suffix}" ] ) ) : 'day';
		if ( ! in_array( $trial_period, array( 'day', 'week', 'month', 'year' ), true ) ) {
			$trial_period = 'day';
		}

		$product->update_meta_data( '_rpsfw_sub_interval', $interval );
		$product->update_meta_data( '_rpsfw_sub_period', $period );
		$product->update_meta_data( '_rpsfw_sub_length', $length );
		$product->update_meta_data( '_rpsfw_sub_signup_fee', $signup_fee );
		$product->update_meta_data( '_rpsfw_sub_trial_length', $trial_length );
		$product->update_meta_data( '_rpsfw_sub_trial_period', $trial_period );
	}

	// -----------------------------------------------------------------
	// Admin: variation fields.
	// -----------------------------------------------------------------

	/**
	 * Add a "Subscription" checkbox to the variation options row.
	 *
	 * @param int     $loop           Variation loop index.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Variation post.
	 */
	public static function render_variation_checkbox( $loop, $variation_data, $variation ) {
		$is_subscription = get_post_meta( $variation->ID, '_rpsfw_is_subscription', true );
		?>
		<label class="tips" data-tip="<?php esc_attr_e( 'Sell this variation as a subscription with recurring payments via PayPal or Stripe.', 'restore-paypal-standard-for-woocommerce' ); ?>">
			<?php esc_html_e( 'Subscription', 'restore-paypal-standard-for-woocommerce' ); ?>
			<input type="checkbox" class="checkbox rpsfw-variation-is-subscription" name="_rpsfw_is_subscription[<?php echo esc_attr( $loop ); ?>]" <?php checked( $is_subscription, 'yes' ); ?> />
		</label>
		<?php
	}

	/**
	 * Render schedule fields inside each variation panel.
	 *
	 * @param int     $loop           Variation loop index.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Variation post.
	 */
	public static function render_variation_fields( $loop, $variation_data, $variation ) {
		echo '<div class="rpsfw-subscription-fields rpsfw-subscription-variation-fields" style="' . ( 'yes' === get_post_meta( $variation->ID, '_rpsfw_is_subscription', true ) ? '' : 'display:none;' ) . '">';
		self::render_schedule_fields( $variation->ID, '[' . $loop . ']' );
		echo '</div>';
	}

	/**
	 * Save variation fields.
	 *
	 * @param int $variation_id Variation id.
	 * @param int $loop         Loop index.
	 */
	public static function save_variation_fields( $variation_id, $loop ) {
		$variation = wc_get_product( $variation_id );
		if ( ! $variation ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verified the variation-save nonce.
		$is_subscription = isset( $_POST['_rpsfw_is_subscription'][ $loop ] ) ? 'yes' : 'no';
		$variation->update_meta_data( '_rpsfw_is_subscription', $is_subscription );

		if ( 'yes' === $is_subscription ) {
			// Flatten the loop-indexed fields for the shared saver.
			$data = array();
			foreach ( array( '_rpsfw_sub_interval', '_rpsfw_sub_period', '_rpsfw_sub_length', '_rpsfw_sub_signup_fee', '_rpsfw_sub_trial_length', '_rpsfw_sub_trial_period' ) as $key ) {
				if ( isset( $_POST[ $key ][ $loop ] ) ) {
					$data[ $key ] = wc_clean( wp_unslash( $_POST[ $key ][ $loop ] ) );
				}
			}
			self::save_schedule_fields_to_object( $variation, $data, '' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$variation->save();
	}

	// -----------------------------------------------------------------
	// Admin: show/hide toggle.
	// -----------------------------------------------------------------

	/**
	 * Inline script to toggle the schedule fields with the checkbox.
	 */
	public static function admin_toggle_script() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}
		?>
		<script>
		jQuery( function( $ ) {
			function rpsfwToggleSimple() {
				var checked = $( '#_rpsfw_is_subscription' ).is( ':checked' );
				$( '.rpsfw-subscription-options .rpsfw-subscription-fields' ).toggle( checked );
			}
			$( document ).on( 'change', '#_rpsfw_is_subscription', rpsfwToggleSimple );
			rpsfwToggleSimple();

			// Variations: each checkbox toggles its own panel's fields.
			$( document ).on( 'change', '.rpsfw-variation-is-subscription', function() {
				$( this ).closest( '.woocommerce_variation' ).find( '.rpsfw-subscription-variation-fields' ).toggle( $( this ).is( ':checked' ) );
			} );
			$( document.body ).on( 'woocommerce_variations_loaded', function() {
				$( '.rpsfw-variation-is-subscription' ).each( function() {
					$( this ).closest( '.woocommerce_variation' ).find( '.rpsfw-subscription-variation-fields' ).toggle( $( this ).is( ':checked' ) );
				} );
			} );
		} );
		</script>
		<?php
	}
}
