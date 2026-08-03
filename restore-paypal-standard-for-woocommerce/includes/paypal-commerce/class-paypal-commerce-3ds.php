<?php
/**
 * PayPal Commerce Platform 3D Secure Handler.
 *
 * Handles 3D Secure (3DS) validation for PayPal Commerce Platform payments.
 * 3DS adds an extra layer of authentication to reduce fraud and shift liability.
 *
 * @class       WC_PayPal_Commerce_3DS
 * @version     1.0.0
 * @package     RestorePayPalStandard
 */

defined( 'ABSPATH' ) || exit;

/**
 * WC_PayPal_Commerce_3DS Class.
 */
class WC_PayPal_Commerce_3DS {

	/**
	 * Gateway instance
	 *
	 * @var WC_Gateway_PayPal_Commerce
	 */
	private $gateway;

	/**
	 * Constructor.
	 *
	 * @param WC_Gateway_PayPal_Commerce $gateway Gateway instance.
	 */
	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	/**
	 * Check if 3DS is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return 'yes' === $this->gateway->get_option( '3ds_enabled', 'yes' );
	}

	/**
	 * Check if 3DS should be forced for all transactions.
	 *
	 * @return bool
	 */
	public function is_forced() {
		return 'yes' === $this->gateway->get_option( '3ds_forced', 'no' );
	}

	/**
	 * Get 3DS action rules.
	 *
	 * Returns an array of status codes and their corresponding actions.
	 * Status codes are in format: {enrollment}_{authentication}_{liability_shift}
	 *
	 * @return array
	 */
	public function get_action_rules() {
		$default_rules = array(
			// Card enrolled, authentication successful, liability shifted
			'Y_Y_YES'      => 'accept',
			'Y_Y_POSSIBLE' => 'accept',
			
			// Card enrolled, authentication failed, no liability shift
			'Y_N_NO'       => 'reject',
			'Y_R_NO'       => 'reject',
			'Y_U_NO'       => 'reject',
			
			// Card enrolled, authentication attempted, possible liability shift
			'Y_A_POSSIBLE' => 'accept',
			'Y_A_NO'       => 'review',
			
			// Card enrolled, challenge required but not completed
			'Y_C_UNKNOWN'  => 'reject',
			'Y_C_NO'       => 'reject',
			
			// Card enrolled, unknown result
			'Y_U_UNKNOWN'  => 'reject',
			'Y__NO'        => 'reject',
			
			// Card not enrolled in 3DS
			'N_N_NO'       => 'accept',
			'N__NO'        => 'accept',
			
			// Unable to verify enrollment
			'U__NO'        => 'accept',
			'U__UNKNOWN'   => 'review',
			
			// Bypass (card issuer doesn't support 3DS)
			'B__NO'        => 'accept',
			
			// Unknown status
			'__UNKNOWN'    => 'reject',
		);

		$custom_rules = $this->gateway->get_option( '3ds_action_rules', array() );
		
		return wp_parse_args( $custom_rules, $default_rules );
	}

	/**
	 * Validate 3DS for a PayPal order.
	 *
	 * @param array     $paypal_order PayPal order data from API.
	 * @param WC_Order  $wc_order WooCommerce order object.
	 * @return array Validation result with 'valid' boolean and 'message' string.
	 * @throws Exception If validation fails and payment should be rejected.
	 */
	public function validate_order( $paypal_order, $wc_order ) {
		// If 3DS is disabled, skip validation
		if ( ! $this->is_enabled() ) {
			WC_Gateway_PayPal_Commerce::log( '3DS validation skipped - disabled in settings' );
			return array(
				'valid'   => true,
				'message' => '3DS validation skipped - disabled',
				'skipped' => true,
			);
		}

		// Extract payment source from PayPal order
		$payment_source = $this->extract_payment_source( $paypal_order );
		
		if ( ! $payment_source ) {
			WC_Gateway_PayPal_Commerce::log( '3DS validation skipped - no payment source found' );
			return array(
				'valid'   => true,
				'message' => '3DS validation skipped - no payment source',
				'skipped' => true,
			);
		}

		// Extract card data (handles different payment methods)
		$card = $this->extract_card_data( $payment_source );
		
		if ( ! $card ) {
			WC_Gateway_PayPal_Commerce::log( '3DS validation skipped - no card data found' );
			
			// If 3DS is forced and no card data, reject
			if ( $this->is_forced() ) {
				throw new Exception( esc_html__( 'Payment requires 3D Secure verification but card data is not available.', 'restore-paypal-standard-for-woocommerce' ) );
			}
			
			return array(
				'valid'   => true,
				'message' => '3DS validation skipped - no card data',
				'skipped' => true,
			);
		}

		// Extract authentication result
		$auth_result = isset( $card['authentication_result'] ) ? $card['authentication_result'] : null;
		
		if ( ! $auth_result ) {
			WC_Gateway_PayPal_Commerce::log( '3DS validation: No authentication result found' );
			
			// If 3DS is forced and no authentication result, reject
			if ( $this->is_forced() ) {
				throw new Exception( esc_html__( 'Payment requires 3D Secure verification.', 'restore-paypal-standard-for-woocommerce' ) );
			}
			
			// Add note to order
			$wc_order->add_order_note( __( '3DS: No authentication performed', 'restore-paypal-standard-for-woocommerce' ) );
			
			return array(
				'valid'   => true,
				'message' => '3DS not performed',
			);
		}

		// Parse 3DS result
		$liability_shift = isset( $auth_result['liability_shift'] ) ? strtoupper( $auth_result['liability_shift'] ) : 'NO';
		$threeds = isset( $auth_result['three_d_secure'] ) ? $auth_result['three_d_secure'] : array();
		
		$enrollment = isset( $threeds['enrollment_status'] ) ? strtoupper( $threeds['enrollment_status'] ) : '';
		$authentication = isset( $threeds['authentication_status'] ) ? strtoupper( $threeds['authentication_status'] ) : '';
		
		// Build status key
		$status_key = $enrollment . '_' . $authentication . '_' . $liability_shift;
		
		WC_Gateway_PayPal_Commerce::log( sprintf(
			'3DS Status: %s (Enrollment: %s, Authentication: %s, Liability Shift: %s)',
			$status_key,
			$enrollment ? $enrollment : 'N/A',
			$authentication ? $authentication : 'N/A',
			$liability_shift
		) );

		// Get action for this status
		$rules = $this->get_action_rules();
		$action = isset( $rules[ $status_key ] ) ? $rules[ $status_key ] : 'reject';
		
		// Add detailed note to order
		$note = $this->build_order_note( $status_key, $enrollment, $authentication, $liability_shift, $action );
		$wc_order->add_order_note( $note );
		
		// Take action based on rule
		switch ( $action ) {
			case 'accept':
				WC_Gateway_PayPal_Commerce::log( '3DS validation: ACCEPTED' );
				return array(
					'valid'           => true,
					'message'         => '3DS validation passed',
					'status_key'      => $status_key,
					'liability_shift' => $liability_shift,
				);
				
			case 'review':
				WC_Gateway_PayPal_Commerce::log( '3DS validation: REVIEW REQUIRED' );
				$wc_order->update_status( 'on-hold', __( '3DS authentication requires manual review.', 'restore-paypal-standard-for-woocommerce' ) );
				return array(
					'valid'           => true,
					'message'         => '3DS validation requires review',
					'status_key'      => $status_key,
					'liability_shift' => $liability_shift,
					'review'          => true,
				);
				
			case 'reject':
			default:
				WC_Gateway_PayPal_Commerce::log( '3DS validation: REJECTED - ' . $status_key, 'error' );
				throw new Exception( esc_html( $this->get_rejection_message( $status_key, $enrollment, $authentication ) ) );
		}
	}

	/**
	 * Extract payment source from PayPal order.
	 *
	 * @param array $paypal_order PayPal order data.
	 * @return array|null Payment source data or null.
	 */
	private function extract_payment_source( $paypal_order ) {
		// Handle different response structures
		if ( isset( $paypal_order['payment_source'] ) ) {
			return $paypal_order['payment_source'];
		}
		
		if ( isset( $paypal_order['purchase_units'][0]['payments']['captures'][0]['payment_source'] ) ) {
			return $paypal_order['purchase_units'][0]['payments']['captures'][0]['payment_source'];
		}
		
		return null;
	}

	/**
	 * Extract card data from payment source.
	 *
	 * Handles different payment methods (card, google_pay, apple_pay).
	 *
	 * @param array $payment_source Payment source data.
	 * @return array|null Card data or null.
	 */
	private function extract_card_data( $payment_source ) {
		// Direct card payment
		if ( isset( $payment_source['card'] ) ) {
			return $payment_source['card'];
		}
		
		// Google Pay
		if ( isset( $payment_source['google_pay']['card'] ) ) {
			return $payment_source['google_pay']['card'];
		}
		
		// Apple Pay
		if ( isset( $payment_source['apple_pay']['card'] ) ) {
			return $payment_source['apple_pay']['card'];
		}
		
		return null;
	}

	/**
	 * Build order note for 3DS result.
	 *
	 * @param string $status_key Status key.
	 * @param string $enrollment Enrollment status.
	 * @param string $authentication Authentication status.
	 * @param string $liability_shift Liability shift status.
	 * @param string $action Action taken.
	 * @return string Order note.
	 */
	private function build_order_note( $status_key, $enrollment, $authentication, $liability_shift, $action ) {
		$note = sprintf(
			/* translators: %s: 3D Secure status code. */
			__( '3D Secure Result: %s', 'restore-paypal-standard-for-woocommerce' ),
			'<code>' . $status_key . '</code>'
		) . "\n";
		
		$note .= sprintf(
			/* translators: %s: enrollment status label. */
			__( 'Enrollment: %s', 'restore-paypal-standard-for-woocommerce' ),
			$this->get_enrollment_label( $enrollment )
		) . "\n";
		
		$note .= sprintf(
			/* translators: %s: authentication status label. */
			__( 'Authentication: %s', 'restore-paypal-standard-for-woocommerce' ),
			$this->get_authentication_label( $authentication )
		) . "\n";
		
		$note .= sprintf(
			/* translators: %s: liability shift status. */
			__( 'Liability Shift: %s', 'restore-paypal-standard-for-woocommerce' ),
			$liability_shift === 'YES' ? __( 'Yes (Protected)', 'restore-paypal-standard-for-woocommerce' ) : __( 'No (Not Protected)', 'restore-paypal-standard-for-woocommerce' )
		) . "\n";
		
		$note .= sprintf(
			/* translators: %s: action taken (accept, reject, review). */
			__( 'Action: %s', 'restore-paypal-standard-for-woocommerce' ),
			ucfirst( $action )
		);
		
		return $note;
	}

	/**
	 * Get human-readable enrollment status label.
	 *
	 * @param string $status Enrollment status code.
	 * @return string Label.
	 */
	private function get_enrollment_label( $status ) {
		$labels = array(
			'Y' => __( 'Enrolled', 'restore-paypal-standard-for-woocommerce' ),
			'N' => __( 'Not Enrolled', 'restore-paypal-standard-for-woocommerce' ),
			'U' => __( 'Unable to Verify', 'restore-paypal-standard-for-woocommerce' ),
			'B' => __( 'Bypass (Not Supported)', 'restore-paypal-standard-for-woocommerce' ),
		);
		
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Get human-readable authentication status label.
	 *
	 * @param string $status Authentication status code.
	 * @return string Label.
	 */
	private function get_authentication_label( $status ) {
		$labels = array(
			'Y' => __( 'Successful', 'restore-paypal-standard-for-woocommerce' ),
			'N' => __( 'Failed', 'restore-paypal-standard-for-woocommerce' ),
			'R' => __( 'Rejected', 'restore-paypal-standard-for-woocommerce' ),
			'A' => __( 'Attempted', 'restore-paypal-standard-for-woocommerce' ),
			'U' => __( 'Unable to Authenticate', 'restore-paypal-standard-for-woocommerce' ),
			'C' => __( 'Challenge Required', 'restore-paypal-standard-for-woocommerce' ),
		);
		
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Get rejection message for customer.
	 *
	 * @param string $status_key Status key.
	 * @param string $enrollment Enrollment status.
	 * @param string $authentication Authentication status.
	 * @return string Error message.
	 */
	private function get_rejection_message( $status_key, $enrollment, $authentication ) {
		// Specific messages for common scenarios
		if ( $authentication === 'N' || $authentication === 'R' ) {
			return __( 'Payment verification failed. Please try again or use a different payment method.', 'restore-paypal-standard-for-woocommerce' );
		}
		
		if ( $authentication === 'C' ) {
			return __( 'Payment verification was not completed. Please try again.', 'restore-paypal-standard-for-woocommerce' );
		}
		
		// Generic message
		return __( 'Payment could not be verified for security reasons. Please try a different payment method.', 'restore-paypal-standard-for-woocommerce' );
	}

	/**
	 * Get 3DS verification method for order creation.
	 *
	 * Returns the verification method to request from PayPal when creating an order.
	 *
	 * @return string Verification method (SCA_ALWAYS or SCA_WHEN_REQUIRED).
	 */
	public function get_verification_method() {
		if ( $this->is_forced() ) {
			return 'SCA_ALWAYS';
		}
		
		return 'SCA_WHEN_REQUIRED';
	}
}
