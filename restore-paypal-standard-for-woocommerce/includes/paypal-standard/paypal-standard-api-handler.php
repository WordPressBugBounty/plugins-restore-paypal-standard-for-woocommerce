<?php
/**
 * Handles API requests to the PayPal API for payment processing.
 *
 * @package WooPayPalStandard
 */

defined( 'ABSPATH' ) || exit;

/**
 * rpsfw_Gateway_PayPal_Standard_API_Handler class.
 */
class rpsfw_Gateway_PayPal_Standard_API_Handler {

    /**
     * API Username.
     *
     * @var string
     */
    public $api_username;

    /**
     * API Password
     *
     * @var string
     */
    public $api_password;

    /**
     * API Signature.
     *
     * @var string
     */
    public $api_signature;

    /**
     * Sandbox mode.
     *
     * @var bool
     */
    public $sandbox = false;

    /**
     * Get things going with the correct API credentials.
     *
     * @param bool   $sandbox      Use sandbox or not.
     * @param string $api_username API username.
     * @param string $api_password API password.
     * @param string $api_signature API signature.
     */
    public function __construct( $sandbox = false, $api_username = '', $api_password = '', $api_signature = '' ) {
        $this->sandbox        = $sandbox;
        $this->api_username   = $api_username;
        $this->api_password   = $api_password;
        $this->api_signature  = $api_signature;
    }

    /**
     * Get the API URL for the specified action.
     *
     * @return string
     */
    public function get_api_url() {
        return $this->sandbox ? 'https://api-3t.sandbox.paypal.com/nvp' : 'https://api-3t.paypal.com/nvp';
    }

    /**
     * Perform a refund.
     *
     * @param  WC_Order $order  Order object.
     * @param  float    $amount Refund amount.
     * @param  string   $reason Refund reason.
     * @return array|WP_Error
     */
    public function refund_transaction( $order, $amount = null, $reason = '' ) {
        rpsfw_Gateway_PayPal_Standard::log( 'Refunding order #' . $order->get_id() );

        $transaction_id = $order->get_transaction_id();

        if ( ! $transaction_id ) {
            return new WP_Error( 'error', __( 'No transaction ID found for this order', 'restore-paypal-standard-for-woocommerce' ) );
        }

        $params = array(
            'METHOD'        => 'RefundTransaction',
            'TRANSACTIONID' => $transaction_id,
            'NOTE'          => html_entity_decode( wc_trim_string( $reason, 255 ), ENT_NOQUOTES, 'UTF-8' ),
            'REFUNDTYPE'    => 'Full',
        );

        if ( ! is_null( $amount ) ) {
            $params['REFUNDTYPE'] = 'Partial';
            $params['AMT']        = number_format( $amount, 2, '.', '' );
            $params['CURRENCYCODE'] = $order->get_currency();
        }

        $response = $this->make_api_request( $params );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response;
    }

    /**
     * Capture an authorization.
     *
     * @param  WC_Order $order         Order object.
     * @param  string   $transaction_id Transaction ID.
     * @return array|WP_Error
     */
    public function capture_payment( $order, $transaction_id ) {
        rpsfw_Gateway_PayPal_Standard::log( 'Capturing payment for order #' . $order->get_id() );

        $params = array(
            'METHOD'          => 'DoCapture',
            'AUTHORIZATIONID' => $transaction_id,
            'AMT'             => number_format( $order->get_total(), 2, '.', '' ),
            'CURRENCYCODE'    => $order->get_currency(),
            'COMPLETETYPE'    => 'Complete',
        );

        $response = $this->make_api_request( $params );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return $response;
    }

    /**
     * Make an API request.
     *
     * @param  array $params Request parameters.
     * @return array|WP_Error
     */
    private function make_api_request( $params ) {
        $args = array(
            'method'      => 'POST',
            'timeout'     => 60,
            'httpversion' => '1.1',
            'body'        => wp_parse_args(
                $params,
                array(
                    'VERSION'   => '94.0',
                    'USER'      => $this->api_username,
                    'PWD'       => $this->api_password,
                    'SIGNATURE' => $this->api_signature,
                )
            ),
        );

        $url = $this->get_api_url();

        rpsfw_Gateway_PayPal_Standard::log( 'Making API request to ' . $url );
        rpsfw_Gateway_PayPal_Standard::log( 'Request: ' . wc_print_r( $args['body'], true ) );

        $response = wp_remote_post( $url, $args );

        if ( is_wp_error( $response ) ) {
            rpsfw_Gateway_PayPal_Standard::log( 'API Error: ' . $response->get_error_message() );
            return $response;
        }

        $body = wp_remote_retrieve_body( $response );
        $code = wp_remote_retrieve_response_code( $response );

        if ( 200 !== $code ) {
            rpsfw_Gateway_PayPal_Standard::log( 'API response code: ' . $code );
            return new WP_Error( 'api-error', sprintf( __( 'Invalid API response (Code: %d)', 'restore-paypal-standard-for-woocommerce' ), $code ) );
        }

        parse_str( $body, $parsed_response );

        rpsfw_Gateway_PayPal_Standard::log( 'Response: ' . wc_print_r( $parsed_response, true ) );

        return $parsed_response;
    }
} 