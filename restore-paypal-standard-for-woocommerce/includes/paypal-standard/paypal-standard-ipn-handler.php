<?php
/**
 * Handles responses from PayPal IPN.
 *
 * @package WooPayPalStandard
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __FILE__ ) . '/paypal-standard-response.php';

/**
 * rpsfw_Gateway_PayPal_Standard_IPN_Handler class.
 */
class rpsfw_Gateway_PayPal_Standard_IPN_Handler extends rpsfw_Gateway_PayPal_Standard_Response {

    /**
     * Receiver email address to validate.
     *
     * @var string Receiver email address.
     */
    protected $receiver_email;

    /**
     * Constructor.
     *
     * @param bool   $sandbox Use sandbox or not.
     * @param string $receiver_email Email to receive IPN from.
     */
    public function __construct( $sandbox = false, $receiver_email = '' ) {
        add_action( 'woocommerce_api_rpsfw_gateway_paypal_standard', array( $this, 'check_response' ) );
        add_action( 'valid-restore_paypal_standard-ipn-request', array( $this, 'valid_response' ) );

        $this->receiver_email = $receiver_email;
        $this->sandbox        = $sandbox;
    }

    /**
     * Check for PayPal IPN Response.
     */
    public function check_response() {
        if ( ! empty( $_POST ) && $this->validate_ipn() ) {
            $posted = wp_unslash( $_POST );

            do_action( 'valid-restore_paypal_standard-ipn-request', $posted );
            exit;
        }

        wp_die( 'PayPal IPN Request Failure', 'PayPal IPN', array( 'response' => 500 ) );
    }

    /**
     * Validate IPN request with PayPal.
     */
    protected function validate_ipn() {
        if (function_exists('rpsfw_debug_log')) {
            rpsfw_debug_log('Checking IPN response is valid');
        }

        // Get received values from post data.
        $validate_ipn = wp_unslash( $_POST ); // WPCS: CSRF ok, input var ok.
        $validate_ipn['cmd'] = '_notify-validate';

        // Send back post vars to paypal.
        $params = array(
            'body'        => $validate_ipn,
            'timeout'     => 60,
            'httpversion' => '1.1',
            'compress'    => false,
            'decompress'  => false,
            'user-agent'  => 'WooCommerce/' . WC()->version,
        );

        if (function_exists('rpsfw_debug_log')) {
            rpsfw_debug_log('IPN Request: ' . wc_print_r( $params, true ));
        }

        // Post back to get a response.
        $response = wp_safe_remote_post( $this->sandbox ? 'https://www.sandbox.paypal.com/cgi-bin/webscr' : 'https://www.paypal.com/cgi-bin/webscr', $params );

        if (function_exists('rpsfw_debug_log')) {
            rpsfw_debug_log('IPN Response: ' . wc_print_r( $response, true ));
        }

        // Check to see if the request was valid.
        if ( ! is_wp_error( $response ) && $response['response']['code'] >= 200 && $response['response']['code'] < 300 && strstr( $response['body'], 'VERIFIED' ) ) {
            if (function_exists('rpsfw_debug_log')) {
                rpsfw_debug_log('Received valid IPN response from PayPal');
            }
            return true;
        }

        if (function_exists('rpsfw_debug_log')) {
            rpsfw_debug_log('Received invalid IPN response from PayPal');
            if ( is_wp_error( $response ) ) {
                rpsfw_debug_log('Error response: ' . $response->get_error_message() );
            }
        }

        return false;
    }

    /**
     * Check PayPal IPN validity.
     *
     * @param array $posted Posted data.
     */
    public function valid_response( $posted ) {
        if (function_exists('rpsfw_debug_log')) {
            rpsfw_debug_log('Valid IPN response - processing');
        }

        // IPN validation result is already verified at this point.
        if ( ! empty( $posted['custom'] ) && ( $order = $this->get_paypal_order( $posted['custom'] ) ) ) {

            // Lowercase posted variables.
            $posted['payment_status'] = strtolower( $posted['payment_status'] );

            rpsfw_Gateway_PayPal_Standard::log( 'Found order #' . $order->get_id() );
            rpsfw_Gateway_PayPal_Standard::log( 'Payment status: ' . $posted['payment_status'] );

            if ( method_exists( $this, 'payment_status_' . $posted['payment_status'] ) ) {
                call_user_func( array( $this, 'payment_status_' . $posted['payment_status'] ), $order, $posted );
            }

        } else {
            rpsfw_Gateway_PayPal_Standard::log('Order not found or invalid custom field');
        }
    }

    /**
     * Check for a valid transaction type.
     *
     * @param string $txn_type Transaction type.
     */
    protected function validate_transaction_type( $txn_type ) {
        $accepted_types = array( 'cart', 'instant', 'express_checkout', 'web_accept', 'masspay', 'send_money', 'paypal_here' );

        if ( ! in_array( strtolower( $txn_type ), $accepted_types, true ) ) {
            rpsfw_Gateway_PayPal_Standard::log( 'Aborting, Invalid type:' . $txn_type );
            exit;
        }
    }

    /**
     * Check currency from IPN matches the order.
     *
     * @param WC_Order $order    Order object.
     * @param string   $currency Currency code.
     */
    protected function validate_currency( $order, $currency ) {
        if ( $order->get_currency() !== $currency ) {
            rpsfw_Gateway_PayPal_Standard::log( 'Payment error: Currencies do not match (sent "' . $order->get_currency() . '" | returned "' . $currency . '")' );

            /* translators: %s: currency code. */
            $order->update_status( 'on-hold', sprintf( __( 'Validation error: PayPal currencies do not match (code %s).', 'restore-paypal-standard-for-woocommerce' ), $currency ) );
            exit;
        }
    }

    /**
     * Check payment amount from IPN matches the order.
     *
     * @param WC_Order $order  Order object.
     * @param int      $amount Amount to validate.
     */
    protected function validate_amount( $order, $amount ) {
        if ( number_format( $order->get_total(), 2, '.', '' ) !== number_format( $amount, 2, '.', '' ) ) {
            rpsfw_Gateway_PayPal_Standard::log( 'Payment error: Amounts do not match (gross ' . $amount . ')' );

            /* translators: %s: Amount. */
            $order->update_status( 'on-hold', sprintf( __( 'Validation error: PayPal amounts do not match (gross %s).', 'restore-paypal-standard-for-woocommerce' ), $amount ) );
            exit;
        }
    }

    /**
     * Check receiver email from PayPal. If the receiver email in the IPN is different than what is stored in our settings, process order but add a note.
     *
     * @param WC_Order $order          Order object.
     * @param string   $receiver_email Email to validate.
     */
    protected function validate_receiver_email( $order, $receiver_email ) {
        if ( strcasecmp( trim( $receiver_email ), trim( $this->receiver_email ) ) !== 0 ) {
            rpsfw_Gateway_PayPal_Standard::log( 'IPN Response: Receiver email mismatch - ' . $receiver_email . ' vs ' . $this->receiver_email );
            
            /* translators: %s: receiver email */
            $order->update_status('on-hold', sprintf(__('Validation error: PayPal IPN response from a different email address (%s).', 'restore-paypal-standard-for-woocommerce'), $receiver_email));
            
            return false;
        }
        return true;
    }

    /**
     * Handle payment complete action. Stores transaction fee and completes the order.
     *
     * @param WC_Order $order  Order object.
     * @param array    $posted Posted data.
     */
    protected function payment_status_completed( $order, $posted ) {
        if ( $order->has_status( wc_get_is_paid_statuses() ) ) {
            rpsfw_Gateway_PayPal_Standard::log( 'Aborting, Order #' . $order->get_id() . ' is already complete.' );
            exit;
        }

        $this->validate_transaction_type( $posted['txn_type'] );
        $this->validate_currency( $order, $posted['mc_currency'] );
        $this->validate_amount( $order, $posted['mc_gross'] );
        $this->validate_receiver_email( $order, $posted['receiver_email'] );
        $this->save_paypal_meta_data( $order, $posted );

        if ( 'completed' === $posted['payment_status'] ) {
            if ( $order->has_status( 'cancelled' ) ) {
                $this->payment_status_paid_cancelled_order( $order, $posted );
            }

            $this->payment_complete( $order, ( ! empty( $posted['txn_id'] ) ? wc_clean( $posted['txn_id'] ) : '' ), __( 'IPN payment completed', 'restore-paypal-standard-for-woocommerce' ) );

            // Store transaction fee
            if ( ! empty( $posted['mc_fee'] ) ) {
                $order->update_meta_data( 'PayPal Transaction Fee', wc_clean( $posted['mc_fee'] ) );
                $order->save();
            }
        } else {
            $this->payment_on_hold( $order, sprintf( __( 'Payment pending: %s', 'restore-paypal-standard-for-woocommerce' ), $posted['pending_reason'] ) );
        }
    }

    /**
     * Handle payment pending status. This will typically happen for eChecks.
     *
     * @param WC_Order $order  Order object.
     * @param array    $posted Posted data.
     */
    protected function payment_status_pending( $order, $posted ) {
        $this->payment_status_completed( $order, $posted );
    }

    /**
     * Handle payment failed status.
     *
     * @param WC_Order $order  Order object.
     * @param array    $posted Posted data.
     */
    protected function payment_status_failed( $order, $posted ) {
        $order->update_status( 'failed', sprintf( __( 'Payment failed via IPN. Status: %s.', 'restore-paypal-standard-for-woocommerce' ), $posted['payment_status'] ) );
    }

    /**
     * Handle payment denied status.
     *
     * @param WC_Order $order  Order object.
     * @param array    $posted Posted data.
     */
    protected function payment_status_denied( $order, $posted ) {
        $this->payment_status_failed( $order, $posted );
    }

    /**
     * Handle payment expired status.
     *
     * @param WC_Order $order  Order object.
     * @param array    $posted Posted data.
     */
    protected function payment_status_expired( $order, $posted ) {
        $this->payment_status_failed( $order, $posted );
    }

    /**
     * Handle payment voided status.
     *
     * @param WC_Order $order  Order object.
     * @param array    $posted Posted data.
     */
    protected function payment_status_voided( $order, $posted ) {
        $this->payment_status_failed( $order, $posted );
    }

    /**
     * When a user cancelled order is marked as paid via IPN.
     *
     * @param WC_Order $order  Order object.
     * @param array    $posted Posted data.
     */
    protected function payment_status_paid_cancelled_order( $order, $posted ) {
        rpsfw_Gateway_PayPal_Standard::log( 'Order #' . $order->get_id() . ' was cancelled but has been paid via IPN. Reprocessing as completed.' );
        $order->update_status( 'processing', __( 'Order cancelled by customer but payment received via IPN. Order status reset to Processing.', 'restore-paypal-standard-for-woocommerce' ) );
    }

    /**
     * Complete order, add transaction ID and note.
     *
     * @param WC_Order $order        Order object.
     * @param string   $txn_id       Transaction ID.
     * @param string   $note         Order note.
     */
    protected function payment_complete( $order, $txn_id = '', $note = '' ) {
        $order->add_order_note( $note );
        $order->payment_complete( $txn_id );
    }

    /**
     * Put the order on-hold (we don't want to cancel it though).
     *
     * @param WC_Order $order Order object.
     * @param string   $reason Reason why the payment is on-hold.
     */
    protected function payment_on_hold( $order, $reason ) {
        $order->update_status( 'on-hold', $reason );
    }

    /**
     * Save important data from the IPN to the order.
     *
     * @param WC_Order $order  Order object.
     * @param array    $posted Posted data.
     */
    protected function save_paypal_meta_data( $order, $posted ) {
        if ( ! empty( $posted['payer_email'] ) ) {
            $order->update_meta_data( 'PayPal Payer Email', wc_clean( $posted['payer_email'] ) );
        }
        if ( ! empty( $posted['first_name'] ) ) {
            $order->update_meta_data( 'PayPal Payer First Name', wc_clean( $posted['first_name'] ) );
        }
        if ( ! empty( $posted['last_name'] ) ) {
            $order->update_meta_data( 'PayPal Payer Last Name', wc_clean( $posted['last_name'] ) );
        }
        if ( ! empty( $posted['payment_type'] ) ) {
            $order->update_meta_data( 'PayPal Payment Type', wc_clean( $posted['payment_type'] ) );
        }
        if ( ! empty( $posted['pending_reason'] ) ) {
            $order->update_meta_data( 'PayPal Pending Reason', wc_clean( $posted['pending_reason'] ) );
        }
        if ( ! empty( $posted['txn_id'] ) ) {
            $order->update_meta_data( 'PayPal Transaction ID', wc_clean( $posted['txn_id'] ) );
        }
        if ( ! empty( $posted['payment_status'] ) ) {
            $order->update_meta_data( 'PayPal Payment Status', wc_clean( $posted['payment_status'] ) );
        }

        // Saving time for future reference.
        $order->update_meta_data( 'PayPal IPN Processing Time', current_time( 'mysql' ) );
        
        // Save PayPal environment
        $order->update_meta_data( '_paypal_environment', $this->sandbox ? 'sandbox' : 'live' );
        
        $order->save();
    }
} 