<?php
/**
 * Handle PDT Responses from PayPal.
 *
 * @package WooPayPalStandard
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __FILE__ ) . '/paypal-standard-response.php';

/**
 * rpsfw_Gateway_PayPal_Standard_PDT_Handler class.
 */
class rpsfw_Gateway_PayPal_Standard_PDT_Handler extends rpsfw_Gateway_PayPal_Standard_Response {

    /**
     * Identity token for PDT support.
     *
     * @var string
     */
    protected $identity_token;

    /**
     * Receiver email address to validate.
     *
     * @var string
     */
    protected $receiver_email;

    /**
     * Constructor.
     *
     * @param bool   $sandbox        Whether to use the sandbox or not.
     * @param string $identity_token Identity token for PDT support.
     */
    public function __construct( $sandbox = false, $identity_token = '' ) {
        add_action( 'woocommerce_thankyou_restore_paypal_standard', array( $this, 'check_response' ) );

        $this->identity_token = $identity_token;
        $this->sandbox        = $sandbox;
    }

    /**
     * Set receiver email.
     *
     * @param string $email Email to set.
     */
    public function set_receiver_email( $email ) {
        $this->receiver_email = $email;
    }

    /**
     * Check for PayPal PDT Response.
     *
     * @param int $order_id Order ID.
     */
    public function check_response( $order_id ) {
        // bail if no tx token
        if ( empty( $_GET['tx'] ) ) {
            return;
        }

        $order = wc_get_order( $order_id );

        rpsfw_Gateway_PayPal_Standard::log( 'PDT Request: ' . wc_print_r( $_GET, true ) );

        $transaction_details = $this->get_transaction_details( wc_clean( wp_unslash( $_GET['tx'] ) ) );

        if ( ! is_wp_error( $transaction_details ) && ! empty( $transaction_details ) ) {
            $this->validate_transaction_details( $order, $transaction_details );
        } else {
            // Add a notice if something went wrong.
            $order->add_order_note( __( 'PDT transaction details check failed.', 'restore-paypal-standard-for-woocommerce' ) );
            rpsfw_Gateway_PayPal_Standard::log( 'PDT check failed: ' . wc_print_r( $transaction_details, true ) );
        }
    }

    /**
     * Get transaction details by contacting PayPal PDT API.
     *
     * @param string $tx_token Transaction token.
     * @return array|WP_Error Array of parsed transaction details or WP_Error in case of failure.
     */
    protected function get_transaction_details( $tx_token ) {
        $pdt_url = $this->sandbox ? 'https://www.sandbox.paypal.com/cgi-bin/webscr' : 'https://www.paypal.com/cgi-bin/webscr';

        // Log the PDT request for debugging. Only the first few characters of
        // the token are recorded - enough to correlate a log line with a
        // transaction, without writing a live transaction reference into a file
        // any admin can download from WooCommerce > Status > Logs.
        rpsfw_Gateway_PayPal_Standard::log(
            'Contacting PayPal PDT API for transaction details. Token: ' . substr( (string) $tx_token, 0, 6 ) . '...'
        );

        $params = array(
            'body'        => array(
                'cmd' => '_notify-synch',
                'tx'  => $tx_token,
                'at'  => $this->identity_token,
            ),
            'timeout'     => 60,
            'httpversion' => '1.1',
            'user-agent'  => 'WooCommerce/' . WC()->version,
        );

        // Make the remote request
        $response = wp_safe_remote_post( $pdt_url, $params );

        if ( is_wp_error( $response ) ) {
            rpsfw_Gateway_PayPal_Standard::log( 'Error contacting PayPal PDT API: ' . $response->get_error_message() );
            return $response;
        }

        if ( empty( $response['body'] ) ) {
            rpsfw_Gateway_PayPal_Standard::log( 'Empty response body from PayPal PDT API' );
            return new WP_Error( 'empty_response', 'Empty response from PayPal PDT API' );
        }

        // Parse the PDT response
        $lines = explode( "\n", $response['body'] );
        $pdt_response = array();

        // First line is SUCCESS/FAIL
        $status = isset( $lines[0] ) ? trim( $lines[0] ) : '';

        if ( 'SUCCESS' !== $status ) {
            rpsfw_Gateway_PayPal_Standard::log( 'PDT API authentication failed. Response: ' . $response['body'] );
            return new WP_Error( 'pdt_auth_fail', 'PayPal PDT authentication failed. Status: ' . $status );
        }

        // Remove the status line
        unset( $lines[0] );

        // Parse the remaining lines
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) {
                continue;
            }

            // Split by = to get key/value pairs
            $key_val = explode( '=', $line, 2 );
            if ( count( $key_val ) === 2 ) {
                $pdt_response[ urldecode( $key_val[0] ) ] = urldecode( $key_val[1] );
            }
        }

        // Log the parsed response
        rpsfw_Gateway_PayPal_Standard::log( 'Parsed PDT Response: ' . wc_print_r( $pdt_response, true ) );

        return $pdt_response;
    }

    /**
     * Validate transaction response data.
     *
     * @param WC_Order $order Order object.
     * @param array    $details Transaction details.
     */
    protected function validate_transaction_details( $order, $details ) {
        // Every check below is scoped to orders that are NOT already paid.
        //
        // PDT re-runs each time the buyer loads the order-received URL, which
        // includes orders already completed by the IPN handler after it did its
        // own validation. Validating again there could knock a legitimately
        // paid order back to on-hold (for example after an admin edits the
        // total). These checks must only ever prevent a completion, never
        // reverse one.
        $needs_verification = ! $order->has_status( wc_get_is_paid_statuses() );

        // Validate that the money actually went to this store's PayPal account.
        // When a merchant email is configured the response MUST carry a
        // receiver_email to check against - a missing one cannot be treated as
        // a pass, or the check is trivially avoided.
        if ( $needs_verification && ! empty( $this->receiver_email ) ) {
            if ( ! isset( $details['receiver_email'] ) ) {
                rpsfw_Gateway_PayPal_Standard::log( 'PDT Response: no receiver_email returned; cannot confirm the payment reached this store. Leaving order #' . $order->get_id() . ' on-hold.' );

                $order->update_status( 'on-hold', __( 'Validation error: the PayPal PDT response did not identify the receiving account, so the payment could not be verified.', 'restore-paypal-standard-for-woocommerce' ) );

                return false;
            }

            if ( strtolower( $details['receiver_email'] ) !== strtolower( $this->receiver_email ) ) {
                rpsfw_Gateway_PayPal_Standard::log('PDT Response: Receiver email mismatch - ' . $details['receiver_email'] . ' vs ' . $this->receiver_email);
                
                /* translators: %1$s: receiver email, %2$s: order ID */
                $order->update_status('on-hold', sprintf(__('Validation error: PayPal PDT response from a different email address (%1$s). Order #%2$s', 'restore-paypal-standard-for-woocommerce'), 
                    $details['receiver_email'],
                    $order->get_id()
                ));
                
                return false;
            }
        }

        // SECURITY: validate what was actually paid before completing anything.
        //
        // The PayPal payment form is client side, so the buyer controls the
        // amount fields (amount_1, shipping_1, tax_cart, currency_code). Without
        // these checks a buyer can pay 1.00 for a 100.00 order and PDT will mark
        // it fully paid on return. The IPN handler validates the same things,
        // but it aborts early once an order is already paid - so on a
        // synchronous PDT completion this is the only amount check there is.
        //
        // SCOPED TO UNPAID ORDERS ON PURPOSE. PDT re-runs every time the buyer
        // loads the order-received URL, including for an order already paid via
        // IPN. Validating there could knock a legitimately paid order back to
        // on-hold (e.g. after an admin edits the total). These checks must only
        // ever prevent a completion, never reverse one.
        //
        // Fail CLOSED: if the fields needed to verify the payment are missing,
        // the order is not completed. That is safe because the IPN handler
        // validates the same payment independently and will complete it if
        // genuine.
        if ( $needs_verification ) {
            if ( ! isset( $details['mc_gross'], $details['mc_currency'] ) ) {
                rpsfw_Gateway_PayPal_Standard::log( 'PDT Response: missing mc_gross/mc_currency; cannot verify the payment for order #' . $order->get_id() . '.' );

                $order->update_status( 'on-hold', __( 'Validation error: the PayPal PDT response did not include the amount paid, so the payment could not be verified.', 'restore-paypal-standard-for-woocommerce' ) );

                return false;
            }

            if ( $order->get_currency() !== $details['mc_currency'] ) {
                rpsfw_Gateway_PayPal_Standard::log( 'PDT Response: Currencies do not match (sent "' . $order->get_currency() . '" | returned "' . $details['mc_currency'] . '")' );

                /* translators: %s: currency code. */
                $order->update_status( 'on-hold', sprintf( __( 'Validation error: PayPal PDT currencies do not match (code %s).', 'restore-paypal-standard-for-woocommerce' ), $details['mc_currency'] ) );

                return false;
            }

            if ( number_format( $order->get_total(), 2, '.', '' ) !== number_format( (float) $details['mc_gross'], 2, '.', '' ) ) {
                rpsfw_Gateway_PayPal_Standard::log( 'PDT Response: Amounts do not match (gross ' . $details['mc_gross'] . ' vs order total ' . $order->get_total() . ')' );

                /* translators: %s: Amount. */
                $order->update_status( 'on-hold', sprintf( __( 'Validation error: PayPal PDT amounts do not match (gross %s).', 'restore-paypal-standard-for-woocommerce' ), $details['mc_gross'] ) );

                return false;
            }
        }

        // Validate payment status
        if ( isset( $details['payment_status'] ) ) {
            $payment_status = strtolower( $details['payment_status'] );
            $transaction_id = isset( $details['txn_id'] ) ? $details['txn_id'] : '';

            switch ( $payment_status ) {
                case 'completed':
                    // Set transaction ID on the order
                    if ( $transaction_id && !$order->get_transaction_id() ) {
                        $order->set_transaction_id( $transaction_id );
                        $order->save();
                    }

                    // If the order is already completed, just add a note
                    if ( $order->has_status( wc_get_is_paid_statuses() ) ) {
                        $order->add_order_note( 
                            sprintf( 
                                /* translators: %s: PayPal transaction ID. */
                                __( 'PayPal PDT: Payment verified (ID: %s)', 'restore-paypal-standard-for-woocommerce' ),
                                $transaction_id 
                            ) 
                        );
                    } else {
                        // If order is still pending, complete it
                        $order->payment_complete( $transaction_id );
                        $order->add_order_note( 
                            sprintf( 
                                /* translators: %s: PayPal transaction ID. */
                                __( 'PayPal PDT: Payment completed (ID: %s)', 'restore-paypal-standard-for-woocommerce' ),
                                $transaction_id 
                            ) 
                        );
                    }
                    break;

                case 'pending':
                    $pending_reason = isset( $details['pending_reason'] ) ? $details['pending_reason'] : '';
                    
                    $order->add_order_note( 
                        sprintf( 
                            /* translators: %1$s: PayPal transaction ID, %2$s: pending reason. */
                            __( 'PayPal PDT: Payment pending (%1$s). Reason: %2$s', 'restore-paypal-standard-for-woocommerce' ),
                            $transaction_id,
                            $pending_reason
                        ) 
                    );
                    
                    // Save the transaction details to the order
                    $this->save_transaction_details( $order, $details );
                    break;

                default:
                    // For other payment statuses, just add a note
                    $order->add_order_note( 
                        sprintf( 
                            /* translators: %1$s: payment status, %2$s: PayPal transaction ID. */
                            __( 'PayPal PDT: Payment status: %1$s. Transaction ID: %2$s', 'restore-paypal-standard-for-woocommerce' ),
                            $payment_status,
                            $transaction_id
                        ) 
                    );
                    
                    // Save the transaction details to the order
                    $this->save_transaction_details( $order, $details );
                    break;
            }
        } else {
            // No payment_status provided
            $order->add_order_note( __( 'PayPal PDT: No payment status received from PayPal.', 'restore-paypal-standard-for-woocommerce' ) );
            rpsfw_Gateway_PayPal_Standard::log( 'No payment status in PDT response' );
        }
    }

    /**
     * Save important transaction data to order.
     *
     * @param WC_Order $order Order object.
     * @param array    $details Transaction details.
     */
    protected function save_transaction_details( $order, $details ) {
        // Transaction ID
        if ( isset( $details['txn_id'] ) ) {
            $order->update_meta_data( 'PayPal Transaction ID', wc_clean( $details['txn_id'] ) );
        }
        
        // Payment type
        if ( isset( $details['payment_type'] ) ) {
            $order->update_meta_data( 'PayPal Payment Type', wc_clean( $details['payment_type'] ) );
        }
        
        // Payment status
        if ( isset( $details['payment_status'] ) ) {
            $order->update_meta_data( 'PayPal Payment Status', wc_clean( $details['payment_status'] ) );
        }
        
        // Payer email
        if ( isset( $details['payer_email'] ) ) {
            $order->update_meta_data( 'PayPal Payer Email', wc_clean( $details['payer_email'] ) );
        }
        
        // Save payment method title
        $order->set_payment_method_title( 'PayPal Standard' );
        $order->save();
    }
} 