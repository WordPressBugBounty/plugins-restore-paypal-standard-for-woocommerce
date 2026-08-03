<?php
/**
 * Shared checkout required-field validator.
 *
 * Lets a gateway confirm the shopper has filled the checkout's required fields
 * BEFORE taking payment — e.g. before opening the PayPal window from a payment
 * button — so payment can't complete against an empty/incomplete form.
 *
 * It is CONFIG-AWARE and READ-ONLY:
 *   - Required fields are derived from WooCommerce's own checkout field config
 *     (WC_Checkout::get_checkout_fields()), which reflects the
 *     woocommerce_checkout_fields filter that checkout-editor plugins use. So a
 *     field a store/plugin removed or made optional is NOT enforced, and only
 *     fields that are actually in use are checked — for both the classic and
 *     block checkout.
 *   - It only reads posted values and the field config. It does NOT invoke
 *     WC_Checkout::validate_posted_data() (which defines WOOCOMMERCE_CHECKOUT
 *     and mutates cart/shipping/session), so it has no side effects on the
 *     surrounding payment request.
 *
 * The caller populates the request's $_POST with the standard WooCommerce field
 * keys (billing_*, shipping_*, ship_to_different_address); validate() reads them
 * from $_POST.
 *
 * @package RestorePayPalStandard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class RPSFW_Checkout_Validator
 */
class RPSFW_Checkout_Validator {

	/**
	 * Validate the posted checkout fields against the store's field config.
	 *
	 * @return WP_Error Collected errors. has_errors() is false when valid.
	 */
	public static function validate() {
		$errors = new WP_Error();

		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->checkout() ) {
			return $errors;
		}

		$post     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$checkout = WC()->checkout();

		// Classic checkout fieldsets — respects the woocommerce_checkout_fields
		// filter, so classic checkout-editor plugins' custom/removed/optional
		// fields are honoured. We enforce billing, the "Additional information"
		// (order) and account fieldsets always, and shipping when a separate
		// shipping address is used. Only fields that are BOTH required AND
		// present in the submitted data are enforced (see validate_fieldset),
		// so a surface that doesn't collect a field never trips on it.
		self::validate_fieldset( $checkout->get_checkout_fields( 'billing' ), $post, $errors );

		if ( WC()->cart->needs_shipping()
			&& WC()->cart->needs_shipping_address()
			&& ! empty( $post['ship_to_different_address'] ) ) {
			self::validate_fieldset( $checkout->get_checkout_fields( 'shipping' ), $post, $errors );
		}

		self::validate_fieldset( $checkout->get_checkout_fields( 'order' ), $post, $errors );
		self::validate_fieldset( $checkout->get_checkout_fields( 'account' ), $post, $errors );

		// Block "Additional checkout fields" API
		// (woocommerce_register_additional_checkout_field). Validated via the
		// core CheckoutFields service against the values the block posts.
		self::validate_block_additional_fields( $post, $errors );

		// "An account is already registered with your email address." WooCommerce
		// only raises this in WC_Checkout::process_customer() during order
		// processing — i.e. AFTER payment — so a guest paying for a cart that
		// forces account creation (WooCommerce Subscriptions does) gets charged
		// and only then sees the error. Run WooCommerce's own condition here,
		// pre-payment, so we block before taking money. Uses core
		// is_registration_required()/email_exists(); no custom rules.
		if ( ! is_user_logged_in() ) {
			$email          = isset( $post['billing_email'] ) && is_string( $post['billing_email'] ) ? $post['billing_email'] : '';
			$create_account = ! empty( $post['createaccount'] );
			$must_register  = method_exists( $checkout, 'is_registration_required' ) && $checkout->is_registration_required();

			if ( '' !== $email && is_email( $email ) && ( $must_register || $create_account ) && email_exists( $email ) ) {
				$errors->add(
					'registration-error-email-exists',
					sprintf(
						/* translators: %s: customer email address */
						__( 'An account is already registered with %s. Please log in or use a different email address.', 'restore-paypal-standard-for-woocommerce' ),
						$email
					)
				);
			}
		}

		return $errors;
	}

	/**
	 * Convenience: true when the posted checkout fields are valid.
	 *
	 * @return bool
	 */
	public static function is_valid() {
		return ! self::validate()->has_errors();
	}

	/**
	 * Enforce required fields within a WooCommerce checkout fieldset.
	 *
	 * A field is only checked when it is (a) marked required in the store's
	 * config AND (b) actually present in the posted data — so we never demand a
	 * field a plugin removed, or one a given checkout surface doesn't collect.
	 *
	 * @param array    $fields WC_Checkout fieldset (key => field args).
	 * @param array    $post   Unslashed posted data.
	 * @param WP_Error $errors Error bag.
	 */
	private static function validate_fieldset( $fields, $post, $errors ) {
		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return;
		}

		foreach ( $fields as $key => $field ) {
			if ( empty( $field['required'] ) || ! array_key_exists( $key, $post ) ) {
				continue;
			}

			$value = $post[ $key ];
			$label = ( isset( $field['label'] ) && '' !== $field['label'] ) ? $field['label'] : $key;

			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				/* translators: %s: field label */
				$errors->add( 'required-field', sprintf( __( '%s is a required field.', 'restore-paypal-standard-for-woocommerce' ), $label ) );
				continue;
			}

			// Email-type fields must also be well-formed.
			$is_email_field = ( 'billing_email' === $key )
				|| ( isset( $field['type'] ) && 'email' === $field['type'] );
			if ( $is_email_field && ! is_email( $value ) ) {
				$errors->add( 'required-field', __( 'Please enter a valid email address.', 'restore-paypal-standard-for-woocommerce' ) );
			}
		}
	}

	/**
	 * Enforce required fields registered through the block "Additional checkout
	 * fields" API (woocommerce_register_additional_checkout_field), across the
	 * contact / address / order locations. Uses the core CheckoutFields service
	 * so it reflects exactly what was registered (including required and
	 * conditional rules). Values are posted by the block under
	 * rpsfw_additional_fields[<field-id>]; only required fields whose value was
	 * submitted are enforced, so nothing false-blocks when a field isn't used.
	 *
	 * @param array    $post   Unslashed posted data.
	 * @param WP_Error $errors Error bag.
	 */
	private static function validate_block_additional_fields( $post, $errors ) {
		if ( empty( $post['rpsfw_additional_fields'] ) || ! is_array( $post['rpsfw_additional_fields'] ) ) {
			return;
		}

		$service = self::get_checkout_fields_service();
		if ( ! $service || ! method_exists( $service, 'get_fields_for_location' ) ) {
			return;
		}

		$submitted = $post['rpsfw_additional_fields'];

		foreach ( array( 'contact', 'address', 'order' ) as $location ) {
			$fields = $service->get_fields_for_location( $location );
			if ( empty( $fields ) || ! is_array( $fields ) ) {
				continue;
			}

			foreach ( $fields as $key => $field ) {
				if ( ! array_key_exists( $key, $submitted ) ) {
					continue;
				}

				$required = method_exists( $service, 'is_required_field' )
					? (bool) $service->is_required_field( $field )
					: ! empty( $field['required'] );
				if ( ! $required ) {
					continue;
				}

				$value = $submitted[ $key ];
				$type  = isset( $field['type'] ) ? $field['type'] : 'text';
				$str   = is_bool( $value ) ? ( $value ? '1' : '' ) : ( is_scalar( $value ) ? trim( (string) $value ) : '' );

				// A required checkbox must be affirmatively checked.
				$is_empty = ( 'checkbox' === $type )
					? in_array( strtolower( $str ), array( '', '0', 'false', 'no' ), true )
					: ( '' === $str );

				if ( $is_empty ) {
					$label = ( isset( $field['label'] ) && '' !== $field['label'] ) ? $field['label'] : $key;
					/* translators: %s: field label */
					$errors->add( 'required-field', sprintf( __( '%s is a required field.', 'restore-paypal-standard-for-woocommerce' ), $label ) );
				}
			}
		}
	}

	/**
	 * Resolve the WooCommerce Blocks CheckoutFields service, or null if the
	 * Additional Checkout Fields API isn't available on this install.
	 *
	 * @return \Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields|null
	 */
	private static function get_checkout_fields_service() {
		$package = '\Automattic\WooCommerce\Blocks\Package';
		$service = '\Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields';
		if ( ! class_exists( $package ) || ! class_exists( $service ) ) {
			return null;
		}
		try {
			$container = call_user_func( array( $package, 'container' ) );
			return $container ? $container->get( $service ) : null;
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
