<?php
/**
 * PayPal Commerce Platform API Handler.
 *
 * Handles communication with the backend server for PayPal Commerce Platform.
 *
 * @class       WC_PayPal_Commerce_API
 * @version     1.0.0
 * @package     RestorePayPalStandard
 */

defined( 'ABSPATH' ) || exit;

/**
 * WC_PayPal_Commerce_API Class.
 */
class WC_PayPal_Commerce_API {

    /**
     * Backend API endpoint URL
     *
     * @var string
     */
    private $api_endpoint;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->api_endpoint = defined( 'RPSFW_PPCP_API_ENDPOINT' ) ? RPSFW_PPCP_API_ENDPOINT : 'https://wpplugin.org/ppcp-rpsfw/';
    }

    /**
     * Get connection status from backend.
     *
     * @param string $env Environment (sandbox or live).
     * @param array  $onboarding Onboarding data.
     * @return array|false Status data or false on failure.
     */
    public function get_status( $env, $onboarding ) {
        if ( empty( $onboarding['seller_id'] ) ) {
            return false;
        }

        $args = array(
            'env' => $env,
            'onboarding' => $onboarding,
        );

        $transient_key = 'rpsfw_ppcp_status_' . md5( json_encode( $args ) );
        $cached_status = get_transient( $transient_key );

        if ( false !== $cached_status ) {
            return $cached_status;
        }

        $response = wp_remote_get(
            $this->api_endpoint . 'get-status?' . http_build_query( $args ),
            array( 'timeout' => 30 )
        );

        if ( is_wp_error( $response ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Error getting status: ' . $response->get_error_message(), 'error' );
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( is_array( $data ) && ! empty( $data['env'] ) ) {
            set_transient( $transient_key, $data, HOUR_IN_SECONDS );
            return $data;
        }

        return false;
    }

    /**
     * Find seller ID after onboarding.
     *
     * @param string $env Environment (sandbox or live).
     * @param array  $onboarding Onboarding data.
     * @return array|false Seller data or false on failure.
     */
    public function find_seller_id( $env, $onboarding ) {
        $response = wp_remote_get(
            $this->api_endpoint . 'find-seller-id?' . http_build_query( array(
                'env' => $env,
                'onboarding' => $onboarding,
            ) ),
            array( 'timeout' => 30 )
        );

        if ( is_wp_error( $response ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Error finding seller ID: ' . $response->get_error_message(), 'error' );
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( is_array( $data ) && ! empty( $data['env'] ) && ! empty( $data['seller_id'] ) ) {
            return $data;
        }

        return false;
    }

    /**
     * Start onboarding process.
     *
     * @param string $env Environment (sandbox or live).
     * @param string $return_url Return URL after onboarding.
     * @return array|false Onboarding data or false on failure.
     */
    public function start_onboarding( $env, $return_url ) {
        // Get store country from WooCommerce settings
        $store_country = WC()->countries->get_base_country();
        if ( empty( $store_country ) ) {
            $store_country = 'US'; // Default to US if not set
        }
        
        $response = wp_remote_post(
            $this->api_endpoint . 'signup',
            array(
                'timeout' => 60,
                'body' => array(
                    'env' => $env,
                    'return_url' => $return_url,
                    'email' => get_bloginfo( 'admin_email' ),
                    'country' => $store_country,
                    'accept_cards' => true,
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Error starting onboarding: ' . $response->get_error_message(), 'error' );
            return false;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        
        if ( $status_code !== 200 ) {
            WC_Gateway_PayPal_Commerce::log( 'Onboarding API returned status ' . $status_code . ': ' . $body, 'error' );
            return false;
        }
        
        $data = json_decode( $body, true );

        if ( empty( $data['action_url'] ) || empty( $data['tracking_id'] ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Invalid onboarding response - missing action_url or tracking_id', 'error' );
            return false;
        }
        
        return $data;
    }

    /**
     * Disconnect account.
     *
     * @param string $env Environment (sandbox or live).
     * @param array  $onboarding Onboarding data.
     * @return bool Success or failure.
     */
    public function disconnect( $env, $onboarding ) {
        $args = array(
            'env' => $env,
            'onboarding' => $onboarding,
        );

        $response = wp_remote_post(
            $this->api_endpoint . 'disconnect',
            array(
                'timeout' => 60,
                'body' => $args,
            )
        );

        if ( is_wp_error( $response ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Error disconnecting: ' . $response->get_error_message(), 'error' );
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! empty( $data['success'] ) ) {
            // Clear cached status
            $transient_key = 'rpsfw_ppcp_status_' . md5( json_encode( $args ) );
            delete_transient( $transient_key );
            return true;
        }

        return false;
    }

    /**
     * Get PayPal client ID.
     *
     * @param string $env Environment (sandbox or live).
     * @param array  $onboarding Onboarding data.
     * @return string|false Client ID or false on failure.
     */
    public function get_client_id( $env, $onboarding ) {
        if ( empty( $onboarding['seller_id'] ) ) {
            return false;
        }

        $response = wp_remote_get(
            $this->api_endpoint . 'get-status?' . http_build_query( array(
                'env' => $env,
                'onboarding' => $onboarding,
            ) ),
            array( 'timeout' => 30 )
        );

        if ( is_wp_error( $response ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Error getting client ID: ' . $response->get_error_message(), 'error' );
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! empty( $data['client_id'] ) ) {
            return $data['client_id'];
        }

        return false;
    }

    /**
     * Create PayPal order.
     *
     * @param string $env Environment (sandbox or live).
     * @param array  $order_data Order data including seller_id, items, currency, etc.
     * @return array|false Order data or false on failure.
     */
    public function create_order( $env, $order_data ) {
        $body_data = array_merge(
            array( 'env' => $env ),
            $order_data
        );

        $response = wp_remote_post(
            $this->api_endpoint . 'create-order',
            array(
                'timeout' => 60,
                'body' => $body_data, // Send as form data, not JSON
            )
        );

        if ( is_wp_error( $response ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Error creating order: ' . $response->get_error_message(), 'error' );
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! empty( $data['success'] ) && ! empty( $data['order_id'] ) ) {
            return $data;
        }

        // Return error message if available
        if ( ! empty( $data['message'] ) ) {
            return array(
                'success' => false,
                'message' => $data['message'],
            );
        }

        WC_Gateway_PayPal_Commerce::log( 'Invalid create order response: ' . $body, 'error' );
        return false;
    }

    /**
     * Capture PayPal order.
     *
     * @param string $env Environment (sandbox or live).
     * @param array  $onboarding Onboarding data.
     * @param string $order_id PayPal order ID.
     * @return array|false Capture data or false on failure.
     */
    public function capture_order( $env, $onboarding, $order_id ) {
        if ( empty( $onboarding['seller_id'] ) ) {
            WC_Gateway_PayPal_Commerce::log( 'seller_id is missing for capture', 'error' );
            return false;
        }

        $response = wp_remote_post(
            $this->api_endpoint . 'finalize-order',
            array(
                'timeout' => 60,
                'body' => array(
                    'env'       => $env,
                    'seller_id' => $onboarding['seller_id'],
                    'order_id'  => $order_id,
                    'intent'    => 'capture',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Error capturing order: ' . $response->get_error_message(), 'error' );
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! empty( $data['success'] ) ) {
            $result = array(
                'status'         => 'COMPLETED',
                'transaction_id' => $order_id,
                'payer_email'    => ! empty( $data['payer_email'] ) ? $data['payer_email'] : '',
            );
            
            // Include capture_id if available (needed for refunds)
            if ( ! empty( $data['capture_id'] ) ) {
                $result['capture_id'] = $data['capture_id'];
            }
            
            return $result;
        }

        WC_Gateway_PayPal_Commerce::log( 'Invalid capture order response: ' . $body, 'error' );
        return false;
    }

    /**
     * Authorize PayPal order.
     *
     * @param string $env Environment (sandbox or live).
     * @param array  $onboarding Onboarding data.
     * @param string $order_id PayPal order ID.
     * @return array|false Authorization data or false on failure.
     */
    public function authorize_order( $env, $onboarding, $order_id ) {
        if ( empty( $onboarding['seller_id'] ) ) {
            WC_Gateway_PayPal_Commerce::log( 'seller_id is missing for authorization', 'error' );
            return false;
        }

        $response = wp_remote_post(
            $this->api_endpoint . 'finalize-order',
            array(
                'timeout' => 60,
                'body' => array(
                    'env'       => $env,
                    'seller_id' => $onboarding['seller_id'],
                    'order_id'  => $order_id,
                    'intent'    => 'authorize',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Error authorizing order: ' . $response->get_error_message(), 'error' );
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! empty( $data['success'] ) && ! empty( $data['authorization_id'] ) ) {
            return array(
                'status'           => 'COMPLETED',
                'authorization_id' => $data['authorization_id'],
                'payer_email'      => ! empty( $data['payer_email'] ) ? $data['payer_email'] : '',
            );
        }

        WC_Gateway_PayPal_Commerce::log( 'Invalid authorize order response: ' . $body, 'error' );
        return false;
    }

    /**
     * Capture an authorized payment.
     *
     * @param string $env Environment (sandbox or live).
     * @param array  $onboarding Onboarding data.
     * @param string $authorization_id PayPal authorization ID.
     * @param float  $amount Amount to capture.
     * @param string $currency Currency code.
     * @return array|false Capture data or false on failure.
     */
    public function capture_authorization( $env, $onboarding, $authorization_id, $amount, $currency ) {
        if ( empty( $onboarding['seller_id'] ) ) {
            WC_Gateway_PayPal_Commerce::log( 'seller_id is missing for capture authorization', 'error' );
            return false;
        }

        $response = wp_remote_post(
            $this->api_endpoint . 'payment-capture',
            array(
                'timeout' => 60,
                'body' => array(
                    'env'           => $env,
                    'seller_id'     => $onboarding['seller_id'],
                    'authorization' => $authorization_id,
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Error capturing authorization: ' . $response->get_error_message(), 'error' );
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        // The relay's payment-capture endpoint captures the full authorization
        // and returns { success: bool }. It does not echo PayPal's capture id,
        // so treat success alone as completion. The real capture id is
        // backfilled by the PAYMENT.CAPTURE.COMPLETED webhook, which resolves
        // the order via the authorization id and stores _paypal_capture_id.
        if ( ! empty( $data['success'] ) ) {
            return array(
                'status'     => 'COMPLETED',
                'capture_id' => ! empty( $data['capture_id'] ) ? $data['capture_id'] : '',
            );
        }

        // Surface the HTTP status and (possibly empty) raw body so capture
        // failures are diagnosable. An empty body usually means the relay
        // endpoint errored or the authorization is no longer capturable (e.g.
        // already captured, voided, or expired past the honor period).
        $status_code = wp_remote_retrieve_response_code( $response );
        $error_detail = '';
        if ( isset( $data['message'] ) ) {
            $error_detail = $data['message'];
        } elseif ( isset( $data['error'] ) ) {
            $error_detail = is_string( $data['error'] ) ? $data['error'] : wp_json_encode( $data['error'] );
        }
        WC_Gateway_PayPal_Commerce::log(
            sprintf(
                'Invalid capture authorization response (HTTP %s) for authorization %s. Detail: %s. Raw body: %s',
                $status_code,
                $authorization_id,
                $error_detail !== '' ? $error_detail : 'none',
                '' === $body ? '(empty)' : $body
            ),
            'error'
        );
        return false;
    }

    /**
     * Get PayPal order details.
     *
     * @param string $env Environment (sandbox or live).
     * @param array  $onboarding Onboarding data.
     * @param string $order_id PayPal order ID.
     * @return array|false Order details or false on failure.
     */
    public function get_order_details( $env, $onboarding, $order_id ) {
        if ( empty( $onboarding['seller_id'] ) ) {
            WC_Gateway_PayPal_Commerce::log( 'seller_id is missing for get order details', 'error' );
            return false;
        }

        $response = wp_remote_get(
            $this->api_endpoint . 'get-order?' . http_build_query( array(
                'env'       => $env,
                'seller_id' => $onboarding['seller_id'],
                'order_id'  => $order_id,
            ) ),
            array( 'timeout' => 30 )
        );

        if ( is_wp_error( $response ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Error getting order details: ' . $response->get_error_message(), 'error' );
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! empty( $data['success'] ) && ! empty( $data['order'] ) ) {
            return $data['order'];
        }

        // Return empty array if no details available - payment can still proceed
        WC_Gateway_PayPal_Commerce::log( 'Could not get order details, continuing without: ' . $body );
        return array();
    }

    /**
     * Validate PayPal order amount against expected amount.
     *
     * Compares the PayPal order amount with the expected WooCommerce order/cart total
     * to prevent amount manipulation attacks. Uses a small tolerance for rounding differences.
     *
     * @param string $env Environment (sandbox or live).
     * @param array  $onboarding Onboarding data.
     * @param string $paypal_order_id PayPal order ID.
     * @param float  $expected_amount Expected amount from WooCommerce.
     * @param string $expected_currency Expected currency code.
     * @param float  $tolerance Optional. Tolerance for rounding differences. Default 0.01.
     * @return array Validation result with 'valid' boolean and 'message' string.
     */
    public function validate_order_amount( $env, $onboarding, $paypal_order_id, $expected_amount, $expected_currency, $tolerance = 0.01 ) {
        WC_Gateway_PayPal_Commerce::log( sprintf(
            'Validating order amount. PayPal Order ID: %s, Expected: %s %s',
            $paypal_order_id,
            $expected_amount,
            $expected_currency
        ) );

        // Get PayPal order details
        $order_details = $this->get_order_details( $env, $onboarding, $paypal_order_id );

        // If we can't get order details, log warning but allow payment to proceed
        // This prevents blocking legitimate payments due to API issues
        if ( empty( $order_details ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Could not retrieve PayPal order details for validation - proceeding with payment', 'warning' );
            return array(
                'valid'   => true,
                'message' => 'Validation skipped - could not retrieve order details',
                'skipped' => true,
            );
        }

        // Extract amount from PayPal order
        // PayPal Orders API v2 structure: purchase_units[0].amount.value and purchase_units[0].amount.currency_code
        $paypal_amount = null;
        $paypal_currency = null;

        if ( ! empty( $order_details['purchase_units'][0]['amount'] ) ) {
            $amount_data = $order_details['purchase_units'][0]['amount'];
            $paypal_amount = isset( $amount_data['value'] ) ? floatval( $amount_data['value'] ) : null;
            $paypal_currency = isset( $amount_data['currency_code'] ) ? strtoupper( $amount_data['currency_code'] ) : null;
        }

        // If amount data is missing, log and allow payment
        if ( is_null( $paypal_amount ) || is_null( $paypal_currency ) ) {
            WC_Gateway_PayPal_Commerce::log( 'PayPal order missing amount data - proceeding with payment', 'warning' );
            return array(
                'valid'   => true,
                'message' => 'Validation skipped - PayPal order missing amount data',
                'skipped' => true,
            );
        }

        WC_Gateway_PayPal_Commerce::log( sprintf(
            'PayPal order amount: %s %s',
            $paypal_amount,
            $paypal_currency
        ) );

        // Normalize expected values
        $expected_amount = floatval( $expected_amount );
        $expected_currency = strtoupper( $expected_currency );

        // Check currency match
        if ( $paypal_currency !== $expected_currency ) {
            WC_Gateway_PayPal_Commerce::log( sprintf(
                'SECURITY: Currency mismatch! PayPal: %s, Expected: %s. PayPal Order ID: %s',
                $paypal_currency,
                $expected_currency,
                $paypal_order_id
            ), 'error' );

            return array(
                'valid'           => false,
                'message'         => sprintf(
                    /* translators: %1$s: expected currency, %2$s: currency returned by PayPal. */
                    __( 'Payment validation failed: Currency mismatch. Expected %1$s but PayPal order has %2$s.', 'restore-paypal-standard-for-woocommerce' ),
                    $expected_currency,
                    $paypal_currency
                ),
                'paypal_amount'   => $paypal_amount,
                'paypal_currency' => $paypal_currency,
                'expected_amount' => $expected_amount,
                'expected_currency' => $expected_currency,
            );
        }

        // Check amount match with tolerance
        $amount_difference = abs( $paypal_amount - $expected_amount );

        if ( $amount_difference > $tolerance ) {
            WC_Gateway_PayPal_Commerce::log( sprintf(
                'SECURITY: Amount mismatch! PayPal: %s, Expected: %s, Difference: %s. PayPal Order ID: %s',
                $paypal_amount,
                $expected_amount,
                $amount_difference,
                $paypal_order_id
            ), 'error' );

            return array(
                'valid'           => false,
                'message'         => sprintf(
                    /* translators: %1$s: expected amount, %2$s: expected currency, %3$s: amount returned by PayPal, %4$s: currency returned by PayPal. */
                    __( 'Payment validation failed: Amount mismatch. Expected %1$s %2$s but PayPal order has %3$s %4$s.', 'restore-paypal-standard-for-woocommerce' ),
                    number_format( $expected_amount, 2 ),
                    $expected_currency,
                    number_format( $paypal_amount, 2 ),
                    $paypal_currency
                ),
                'paypal_amount'   => $paypal_amount,
                'paypal_currency' => $paypal_currency,
                'expected_amount' => $expected_amount,
                'expected_currency' => $expected_currency,
                'difference'      => $amount_difference,
            );
        }

        WC_Gateway_PayPal_Commerce::log( 'Order amount validation passed' );

        return array(
            'valid'           => true,
            'message'         => 'Amount validation passed',
            'paypal_amount'   => $paypal_amount,
            'paypal_currency' => $paypal_currency,
            'expected_amount' => $expected_amount,
            'expected_currency' => $expected_currency,
            'difference'      => $amount_difference,
        );
    }

    /**
     * Refund a captured payment.
     *
     * Uses PayPal Payments API v2: POST /v2/payments/captures/{capture_id}/refund
     *
     * @param string $env Environment (sandbox or live).
     * @param array  $onboarding Onboarding data.
     * @param string $capture_id PayPal capture ID (transaction ID).
     * @param float  $amount Amount to refund (null for full refund).
     * @param string $currency Currency code.
     * @param string $note_to_payer Optional note to payer.
     * @return array|false Refund data or false on failure.
     */
    public function refund_capture( $env, $onboarding, $capture_id, $amount = null, $currency = 'USD', $note_to_payer = '' ) {
        if ( empty( $onboarding['seller_id'] ) ) {
            WC_Gateway_PayPal_Commerce::log( 'seller_id is missing for refund', 'error' );
            return false;
        }

        $body_data = array(
            'env'        => $env,
            'seller_id'  => $onboarding['seller_id'],
            'capture_id' => $capture_id,
        );

        // Add amount for partial refunds
        if ( ! is_null( $amount ) && $amount > 0 ) {
            $body_data['amount'] = $amount;
            $body_data['currency'] = $currency;
        }

        // Add note to payer if provided
        if ( ! empty( $note_to_payer ) ) {
            $body_data['note_to_payer'] = substr( $note_to_payer, 0, 255 ); // Max 255 chars per PayPal API
        }

        WC_Gateway_PayPal_Commerce::log( 'Refund request: ' . print_r( $body_data, true ) );

        $response = wp_remote_post(
            $this->api_endpoint . 'refund-capture',
            array(
                'timeout' => 60,
                'body'    => $body_data,
            )
        );

        if ( is_wp_error( $response ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Error refunding capture: ' . $response->get_error_message(), 'error' );
            return false;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        WC_Gateway_PayPal_Commerce::log( 'Refund response (HTTP ' . $status_code . '): ' . $body );

        if ( ! empty( $data['success'] ) && ! empty( $data['refund_id'] ) ) {
            return array(
                'refund_id' => $data['refund_id'],
                'status'    => ! empty( $data['status'] ) ? $data['status'] : 'COMPLETED',
                'amount'    => ! empty( $data['amount'] ) ? $data['amount'] : $amount,
            );
        }

        // Return error message if available
        if ( ! empty( $data['message'] ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Refund failed: ' . $data['message'], 'error' );
            return array(
                'success' => false,
                'message' => $data['message'],
            );
        }

        WC_Gateway_PayPal_Commerce::log( 'Invalid refund response: ' . $body, 'error' );
        return false;
    }

    /**
     * Create a webhook.
     *
     * Uses PayPal Webhooks API v1: POST /v1/notifications/webhooks
     *
     * @param string $env Environment (sandbox or live).
     * @param array  $onboarding Onboarding data.
     * @param string $webhook_url URL to receive webhook notifications.
     * @param array  $event_types Array of event type names to subscribe to.
     * @return array|false Webhook data or false on failure.
     */
    public function create_webhook( $env, $onboarding, $webhook_url, $event_types ) {
        if ( empty( $onboarding['seller_id'] ) ) {
            WC_Gateway_PayPal_Commerce::log( 'seller_id is missing for create webhook', 'error' );
            return false;
        }

        // Format event types for PayPal API
        $formatted_events = array();
        foreach ( $event_types as $event_type ) {
            $formatted_events[] = array( 'name' => $event_type );
        }

        $body_data = array(
            'env'         => $env,
            'seller_id'   => $onboarding['seller_id'],
            'webhook_url' => $webhook_url,
            'event_types' => wp_json_encode( $formatted_events ),
        );

        WC_Gateway_PayPal_Commerce::log( 'Create webhook request: ' . print_r( $body_data, true ) );

        $response = wp_remote_post(
            $this->api_endpoint . 'create-webhook',
            array(
                'timeout' => 60,
                'body'    => $body_data,
            )
        );

        if ( is_wp_error( $response ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Error creating webhook: ' . $response->get_error_message(), 'error' );
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        WC_Gateway_PayPal_Commerce::log( 'Create webhook response: ' . $body );

        if ( ! empty( $data['success'] ) && ! empty( $data['webhook_id'] ) ) {
            return array(
                'webhook_id' => $data['webhook_id'],
                'url'        => ! empty( $data['url'] ) ? $data['url'] : $webhook_url,
            );
        }

        // Return error message if available
        if ( ! empty( $data['message'] ) ) {
            return array(
                'success' => false,
                'message' => $data['message'],
            );
        }

        return false;
    }

    /**
     * Delete a webhook.
     *
     * Uses PayPal Webhooks API v1: DELETE /v1/notifications/webhooks/{webhook_id}
     *
     * @param string $env Environment (sandbox or live).
     * @param array  $onboarding Onboarding data.
     * @param string $webhook_id Webhook ID to delete.
     * @return array {
     *     @type bool   $success   True if PayPal confirmed deletion.
     *     @type string $message   Human-readable reason when not successful.
     *     @type int    $http_code HTTP status returned by the backend (0 if no response).
     *     @type string $reason    Short machine reason code: ok|missing_seller_id|request_error|http_error|invalid_json|api_error.
     * }
     */
    public function delete_webhook( $env, $onboarding, $webhook_id ) {
        if ( empty( $onboarding['seller_id'] ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Delete webhook ABORTED: seller_id is missing (webhook_id: ' . $webhook_id . ')', 'error' );
            return array(
                'success'   => false,
                'message'   => 'seller_id is missing; cannot authenticate the delete request.',
                'http_code' => 0,
                'reason'    => 'missing_seller_id',
            );
        }

        $body_data = array(
            'env'        => $env,
            'seller_id'  => $onboarding['seller_id'],
            'webhook_id' => $webhook_id,
        );

        WC_Gateway_PayPal_Commerce::log( 'Delete webhook request: ' . print_r( $body_data, true ) );

        $response = wp_remote_post(
            $this->api_endpoint . 'delete-webhook',
            array(
                'timeout' => 60,
                'body'    => $body_data,
            )
        );

        if ( is_wp_error( $response ) ) {
            $err = $response->get_error_message();
            WC_Gateway_PayPal_Commerce::log( 'Delete webhook FAILED (request error): ' . $err, 'error' );
            return array(
                'success'   => false,
                'message'   => 'Request to backend failed: ' . $err,
                'http_code' => 0,
                'reason'    => 'request_error',
            );
        }

        $http_code = (int) wp_remote_retrieve_response_code( $response );
        $body      = wp_remote_retrieve_body( $response );
        $data      = json_decode( $body, true );

        WC_Gateway_PayPal_Commerce::log( 'Delete webhook response (HTTP ' . $http_code . '): ' . $body );

        if ( $http_code < 200 || $http_code >= 300 ) {
            $msg = ! empty( $data['message'] ) ? $data['message'] : 'Backend returned HTTP ' . $http_code;
            WC_Gateway_PayPal_Commerce::log( 'Delete webhook FAILED (HTTP error): ' . $msg, 'error' );
            return array(
                'success'   => false,
                'message'   => $msg,
                'http_code' => $http_code,
                'reason'    => 'http_error',
            );
        }

        if ( null === $data ) {
            WC_Gateway_PayPal_Commerce::log( 'Delete webhook FAILED (invalid JSON response)', 'error' );
            return array(
                'success'   => false,
                'message'   => 'Backend returned a non-JSON response.',
                'http_code' => $http_code,
                'reason'    => 'invalid_json',
            );
        }

        if ( ! empty( $data['success'] ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Delete webhook SUCCESS for webhook_id: ' . $webhook_id );
            return array(
                'success'   => true,
                'message'   => 'Webhook deleted on PayPal.',
                'http_code' => $http_code,
                'reason'    => 'ok',
            );
        }

        $msg = ! empty( $data['message'] ) ? $data['message'] : 'Backend reported failure without a message.';
        WC_Gateway_PayPal_Commerce::log( 'Delete webhook FAILED (API error): ' . $msg, 'error' );
        return array(
            'success'   => false,
            'message'   => $msg,
            'http_code' => $http_code,
            'reason'    => 'api_error',
        );
    }

    /**
     * Get webhook details.
     *
     * Uses PayPal Webhooks API v1: GET /v1/notifications/webhooks/{webhook_id}
     *
     * @param string $env Environment (sandbox or live).
     * @param array  $onboarding Onboarding data.
     * @param string $webhook_id Webhook ID to retrieve.
     * @return array|false Webhook data or false on failure.
     */
    public function get_webhook( $env, $onboarding, $webhook_id ) {
        if ( empty( $onboarding['seller_id'] ) ) {
            WC_Gateway_PayPal_Commerce::log( 'seller_id is missing for get webhook', 'error' );
            return false;
        }

        $response = wp_remote_get(
            $this->api_endpoint . 'get-webhook?' . http_build_query( array(
                'env'        => $env,
                'seller_id'  => $onboarding['seller_id'],
                'webhook_id' => $webhook_id,
            ) ),
            array( 'timeout' => 30 )
        );

        if ( is_wp_error( $response ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Error getting webhook: ' . $response->get_error_message(), 'error' );
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        WC_Gateway_PayPal_Commerce::log( 'Get webhook response: ' . $body );

        if ( ! empty( $data['success'] ) && ! empty( $data['id'] ) ) {
            return $data;
        }

        return false;
    }

    /**
     * Verify webhook signature.
     *
     * Uses PayPal Webhooks API v1: POST /v1/notifications/verify-webhook-signature
     *
     * @param string $env Environment (sandbox or live).
     * @param array  $onboarding Onboarding data.
     * @param string $webhook_id Webhook ID.
     * @param array  $headers Webhook headers.
     * @param string $raw_body Raw webhook body.
     * @return array|false Verification result or false on failure.
     */
    public function verify_webhook_signature( $env, $onboarding, $webhook_id, $headers, $raw_body ) {
        if ( empty( $onboarding['seller_id'] ) ) {
            WC_Gateway_PayPal_Commerce::log( 'seller_id is missing for verify webhook signature', 'error' );
            return false;
        }

        $body_data = array(
            'env'               => $env,
            'seller_id'         => $onboarding['seller_id'],
            'webhook_id'        => $webhook_id,
            'transmission_id'   => ! empty( $headers['transmission_id'] ) ? $headers['transmission_id'] : '',
            'transmission_time' => ! empty( $headers['transmission_time'] ) ? $headers['transmission_time'] : '',
            'transmission_sig'  => ! empty( $headers['transmission_sig'] ) ? $headers['transmission_sig'] : '',
            'cert_url'          => ! empty( $headers['cert_url'] ) ? $headers['cert_url'] : '',
            'auth_algo'         => ! empty( $headers['auth_algo'] ) ? $headers['auth_algo'] : '',
            'webhook_event'     => $raw_body,
        );

        $response = wp_remote_post(
            $this->api_endpoint . 'verify-webhook-signature',
            array(
                'timeout' => 60,
                'body'    => $body_data,
            )
        );

        if ( is_wp_error( $response ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Error verifying webhook signature: ' . $response->get_error_message(), 'error' );
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        WC_Gateway_PayPal_Commerce::log( 'Verify webhook signature response: ' . $body );

        return $data;
    }

    // -----------------------------------------------------------------------
    //  Subscription endpoints (used by the WC Subscriptions integration).
    //  These hit relay routes that the wpplugin.org server forwards to
    //  PayPal's /v1/catalogs/products, /v1/billing/plans, and
    //  /v1/billing/subscriptions APIs. The relay attaches the partner BN code
    //  on every call so partner attribution is preserved.
    // -----------------------------------------------------------------------

    /**
     * Create a Catalog Product on the seller's PayPal account.
     *
     * @param string $env        Environment (sandbox or live).
     * @param array  $onboarding Onboarding data with seller_id.
     * @param array  $args       Product fields: name, description, type.
     * @return array|false       array{ product_id: string } on success.
     */
    public function create_subscription_product( $env, $onboarding, $args ) {
        if ( empty( $onboarding['seller_id'] ) ) {
            return false;
        }

        $body = array(
            'env'         => $env,
            'seller_id'   => $onboarding['seller_id'],
            'name'        => isset( $args['name'] ) ? $args['name'] : 'Subscription',
            'description' => isset( $args['description'] ) ? $args['description'] : '',
            'type'        => isset( $args['type'] ) ? $args['type'] : 'SERVICE',
        );

        $response = wp_remote_post(
            $this->api_endpoint . 'create-subscription-product',
            array(
                'timeout' => 60,
                'body'    => $body,
            )
        );

        if ( is_wp_error( $response ) ) {
            WC_Gateway_PayPal_Commerce::log( 'create_subscription_product error: ' . $response->get_error_message(), 'error' );
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! empty( $data['product_id'] ) ) {
            return array( 'product_id' => $data['product_id'] );
        }
        WC_Gateway_PayPal_Commerce::log( 'create_subscription_product unexpected response: ' . wp_remote_retrieve_body( $response ), 'error' );
        return false;
    }

    /**
     * Create a Billing Plan tied to a Catalog Product.
     *
     * @param string $env        Environment.
     * @param array  $onboarding Onboarding data.
     * @param array  $args       product_id, name, description, currency_code,
     *                           amount, interval_unit, interval_count,
     *                           total_cycles, trial_amount, trial_unit,
     *                           trial_count, trial_cycles.
     * @return array|false       array{ plan_id: string } on success.
     */
    public function create_subscription_plan( $env, $onboarding, $args ) {
        if ( empty( $onboarding['seller_id'] ) || empty( $args['product_id'] ) ) {
            return false;
        }

        $body = array_merge(
            array(
                'env'       => $env,
                'seller_id' => $onboarding['seller_id'],
            ),
            $args
        );

        $response = wp_remote_post(
            $this->api_endpoint . 'create-subscription-plan',
            array(
                'timeout' => 60,
                'body'    => $body,
            )
        );

        if ( is_wp_error( $response ) ) {
            WC_Gateway_PayPal_Commerce::log( 'create_subscription_plan error: ' . $response->get_error_message(), 'error' );
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! empty( $data['plan_id'] ) ) {
            return array( 'plan_id' => $data['plan_id'] );
        }
        WC_Gateway_PayPal_Commerce::log( 'create_subscription_plan unexpected response: ' . wp_remote_retrieve_body( $response ), 'error' );
        return false;
    }

    /**
     * Create a PayPal Subscription against a Plan.
     *
     * @param string $env        Environment.
     * @param array  $onboarding Onboarding data.
     * @param array  $args       plan_id, return_url, cancel_url, subscriber.
     * @return array|false       { subscription_id, approve_url, status }
     */
    public function create_subscription( $env, $onboarding, $args ) {
        if ( empty( $onboarding['seller_id'] ) || empty( $args['plan_id'] ) ) {
            return false;
        }

        $body = array(
            'env'        => $env,
            'seller_id'  => $onboarding['seller_id'],
            'plan_id'    => $args['plan_id'],
            'return_url' => isset( $args['return_url'] ) ? $args['return_url'] : '',
            'cancel_url' => isset( $args['cancel_url'] ) ? $args['cancel_url'] : '',
        );

        if ( ! empty( $args['subscriber'] ) && is_array( $args['subscriber'] ) ) {
            $body['subscriber'] = $args['subscriber'];
        }

        // Diagnostic: log the EXACT payload we send to the relay/PayPal so a
        // "violates schema" / "did not return an approval URL" rejection can be
        // traced to the offending field (plan_id, subscriber name/email/
        // shipping, return/cancel URLs).
        WC_Gateway_PayPal_Commerce::log( 'create_subscription request body: ' . wp_json_encode( $body ), 'info' );

        $response = wp_remote_post(
            $this->api_endpoint . 'create-subscription',
            array(
                'timeout' => 60,
                'body'    => $body,
            )
        );

        if ( is_wp_error( $response ) ) {
            WC_Gateway_PayPal_Commerce::log( 'create_subscription error: ' . $response->get_error_message(), 'error' );
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! empty( $data['subscription_id'] ) && ! empty( $data['approve_url'] ) ) {
            return array(
                'subscription_id' => $data['subscription_id'],
                'approve_url'     => $data['approve_url'],
                'status'          => isset( $data['status'] ) ? $data['status'] : '',
            );
        }
        WC_Gateway_PayPal_Commerce::log( 'create_subscription unexpected response: ' . wp_remote_retrieve_body( $response ), 'error' );

        // Flag the "resource does not exist" case so callers can invalidate a
        // stale cached plan (e.g. a plan created on a previously connected
        // PayPal account) and recreate it.
        $message = isset( $data['message'] ) ? (string) $data['message'] : '';
        if ( false !== stripos( $message, 'does not exist' )
            || ( isset( $data['name'] ) && 'RESOURCE_NOT_FOUND' === $data['name'] ) ) {
            return array( 'not_found' => true );
        }

        return false;
    }

    /**
     * Get details for a PayPal Subscription.
     *
     * @param string $env             Environment.
     * @param array  $onboarding      Onboarding data.
     * @param string $subscription_id PayPal subscription id.
     * @return array|false            Subscription details.
     */
    public function get_subscription( $env, $onboarding, $subscription_id ) {
        if ( empty( $onboarding['seller_id'] ) || empty( $subscription_id ) ) {
            return false;
        }
        $url = $this->api_endpoint . 'get-subscription?' . http_build_query( array(
            'env'             => $env,
            'seller_id'       => $onboarding['seller_id'],
            'subscription_id' => $subscription_id,
        ) );
        $response = wp_remote_get( $url, array( 'timeout' => 30 ) );
        if ( is_wp_error( $response ) ) {
            WC_Gateway_PayPal_Commerce::log( 'get_subscription error: ' . $response->get_error_message(), 'error' );
            return false;
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $data ) ? $data : false;
    }

    /**
     * Cancel a PayPal Subscription.
     *
     * @param string $env             Environment.
     * @param array  $onboarding      Onboarding data.
     * @param string $subscription_id PayPal subscription id.
     * @param string $reason          Reason string sent to PayPal.
     * @return bool
     */
    public function cancel_subscription( $env, $onboarding, $subscription_id, $reason = '' ) {
        return $this->subscription_action( $env, $onboarding, $subscription_id, 'cancel-subscription', $reason );
    }

    /**
     * Suspend a PayPal Subscription.
     *
     * @param string $env             Environment.
     * @param array  $onboarding      Onboarding data.
     * @param string $subscription_id PayPal subscription id.
     * @param string $reason          Reason string sent to PayPal.
     * @return bool
     */
    public function suspend_subscription( $env, $onboarding, $subscription_id, $reason = '' ) {
        return $this->subscription_action( $env, $onboarding, $subscription_id, 'suspend-subscription', $reason );
    }

    /**
     * Activate a PayPal Subscription that is currently SUSPENDED.
     *
     * @param string $env             Environment.
     * @param array  $onboarding      Onboarding data.
     * @param string $subscription_id PayPal subscription id.
     * @param string $reason          Reason string sent to PayPal.
     * @return bool
     */
    public function activate_subscription( $env, $onboarding, $subscription_id, $reason = '' ) {
        return $this->subscription_action( $env, $onboarding, $subscription_id, 'activate-subscription', $reason );
    }

    /**
     * Internal helper for cancel/suspend/activate.
     *
     * @param string $env             Environment.
     * @param array  $onboarding      Onboarding data.
     * @param string $subscription_id PayPal subscription id.
     * @param string $action          Relay path.
     * @param string $reason          Reason string.
     * @return bool
     */
    private function subscription_action( $env, $onboarding, $subscription_id, $action, $reason = '' ) {
        if ( empty( $onboarding['seller_id'] ) || empty( $subscription_id ) ) {
            return false;
        }
        $response = wp_remote_post(
            $this->api_endpoint . $action,
            array(
                'timeout' => 30,
                'body'    => array(
                    'env'             => $env,
                    'seller_id'       => $onboarding['seller_id'],
                    'subscription_id' => $subscription_id,
                    'reason'          => $reason,
                ),
            )
        );
        if ( is_wp_error( $response ) ) {
            WC_Gateway_PayPal_Commerce::log( $action . ' error: ' . $response->get_error_message(), 'error' );
            return false;
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return ! empty( $data['success'] );
    }
}

