<?php
/**
 * PayPal Commerce Platform Webhook Handler.
 *
 * Handles webhook registration, verification, and event processing.
 *
 * @class       WC_PayPal_Commerce_Webhooks
 * @version     1.0.0
 * @package     RestorePayPalStandard
 */

defined( 'ABSPATH' ) || exit;

/**
 * WC_PayPal_Commerce_Webhooks Class.
 */
class WC_PayPal_Commerce_Webhooks {

    /**
     * Gateway instance
     *
     * @var WC_Gateway_PayPal_Commerce
     */
    private $gateway;

    /**
     * Webhook events to subscribe to
     *
     * @var array
     */
    private $webhook_events = array(
        'PAYMENT.CAPTURE.COMPLETED',
        'PAYMENT.CAPTURE.REFUNDED',
        'PAYMENT.CAPTURE.REVERSED',
        'PAYMENT.CAPTURE.DENIED',
        'PAYMENT.AUTHORIZATION.VOIDED',
        'CHECKOUT.ORDER.APPROVED',
        'CHECKOUT.ORDER.COMPLETED',
        'CUSTOMER.DISPUTE.CREATED',
        'CUSTOMER.DISPUTE.RESOLVED',
        // Subscription events. Harmless if WC Subscriptions is not installed:
        // the integration class is the only listener and it self-disables
        // when wcs_is_subscription is unavailable.
        'PAYMENT.SALE.COMPLETED',
        'PAYMENT.SALE.REFUNDED',
        'BILLING.SUBSCRIPTION.CREATED',
        'BILLING.SUBSCRIPTION.ACTIVATED',
        'BILLING.SUBSCRIPTION.CANCELLED',
        'BILLING.SUBSCRIPTION.SUSPENDED',
        'BILLING.SUBSCRIPTION.EXPIRED',
        'BILLING.SUBSCRIPTION.PAYMENT.FAILED',
        'BILLING.SUBSCRIPTION.UPDATED',
    );

    /**
     * Constructor.
     *
     * @param WC_Gateway_PayPal_Commerce $gateway Gateway instance.
     */
    public function __construct( $gateway ) {
        $this->gateway = $gateway;
        
        // Register webhook listener endpoint
        add_action( 'woocommerce_api_rpsfw_paypal_commerce_webhook', array( $this, 'handle_webhook' ) );
        
        // Add AJAX handlers for webhook management
        add_action( 'wp_ajax_rpsfw_ppcp_create_webhook', array( $this, 'ajax_create_webhook' ) );
        add_action( 'wp_ajax_rpsfw_ppcp_delete_webhook', array( $this, 'ajax_delete_webhook' ) );
        add_action( 'wp_ajax_rpsfw_ppcp_check_webhook', array( $this, 'ajax_check_webhook' ) );
    }

    /**
     * Get the webhook listener URL.
     *
     * @return string
     */
    public function get_webhook_url() {
        return WC()->api_request_url( 'rpsfw_paypal_commerce_webhook' );
    }

    /**
     * The events this plugin subscribes to, mapped to human-readable labels.
     * Single source of truth derived from $webhook_events, so the settings
     * display can never drift from what is actually registered.
     *
     * @return array event_name => label
     */
    public function get_subscribed_events_with_labels() {
        $labels = array(
            'PAYMENT.CAPTURE.COMPLETED'           => __( 'Payment captured', 'restore-paypal-standard-for-woocommerce' ),
            'PAYMENT.CAPTURE.REFUNDED'            => __( 'Payment refunded', 'restore-paypal-standard-for-woocommerce' ),
            'PAYMENT.CAPTURE.REVERSED'            => __( 'Payment reversed (chargeback)', 'restore-paypal-standard-for-woocommerce' ),
            'PAYMENT.CAPTURE.DENIED'              => __( 'Payment denied', 'restore-paypal-standard-for-woocommerce' ),
            'PAYMENT.AUTHORIZATION.VOIDED'        => __( 'Authorization voided', 'restore-paypal-standard-for-woocommerce' ),
            'CHECKOUT.ORDER.APPROVED'             => __( 'Order approved', 'restore-paypal-standard-for-woocommerce' ),
            'CHECKOUT.ORDER.COMPLETED'            => __( 'Order completed', 'restore-paypal-standard-for-woocommerce' ),
            'CUSTOMER.DISPUTE.CREATED'            => __( 'Dispute opened', 'restore-paypal-standard-for-woocommerce' ),
            'CUSTOMER.DISPUTE.RESOLVED'           => __( 'Dispute resolved', 'restore-paypal-standard-for-woocommerce' ),
            'PAYMENT.SALE.COMPLETED'              => __( 'Subscription payment completed', 'restore-paypal-standard-for-woocommerce' ),
            'PAYMENT.SALE.REFUNDED'               => __( 'Subscription payment refunded', 'restore-paypal-standard-for-woocommerce' ),
            'BILLING.SUBSCRIPTION.CREATED'        => __( 'Subscription created', 'restore-paypal-standard-for-woocommerce' ),
            'BILLING.SUBSCRIPTION.ACTIVATED'      => __( 'Subscription activated', 'restore-paypal-standard-for-woocommerce' ),
            'BILLING.SUBSCRIPTION.CANCELLED'      => __( 'Subscription cancelled', 'restore-paypal-standard-for-woocommerce' ),
            'BILLING.SUBSCRIPTION.SUSPENDED'      => __( 'Subscription suspended', 'restore-paypal-standard-for-woocommerce' ),
            'BILLING.SUBSCRIPTION.EXPIRED'        => __( 'Subscription expired', 'restore-paypal-standard-for-woocommerce' ),
            'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => __( 'Subscription payment failed', 'restore-paypal-standard-for-woocommerce' ),
            'BILLING.SUBSCRIPTION.UPDATED'        => __( 'Subscription updated', 'restore-paypal-standard-for-woocommerce' ),
        );

        $events = array();
        foreach ( $this->webhook_events as $event ) {
            $events[ $event ] = isset( $labels[ $event ] ) ? $labels[ $event ] : $event;
        }

        return $events;
    }

    /**
     * Handle incoming webhook.
     */
    public function handle_webhook() {
        // Get raw POST data
        $raw_body = file_get_contents( 'php://input' );
        
        if ( empty( $raw_body ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Webhook received with empty body', 'error' );
            status_header( 400 );
            exit;
        }

        $event = json_decode( $raw_body, true );
        
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            WC_Gateway_PayPal_Commerce::log( 'Webhook JSON decode error: ' . json_last_error_msg(), 'error' );
            status_header( 400 );
            exit;
        }

        WC_Gateway_PayPal_Commerce::log( 'Webhook received: ' . $event['event_type'] . ' - ID: ' . $event['id'] );

        // Verify webhook signature.
        //   'valid'   => signature matches our account's webhook, process it.
        //   'invalid' => PayPal definitively rejected the signature. This is
        //                almost always a webhook from a different/old PayPal
        //                account hitting this (public) endpoint URL. Such an
        //                event will NEVER verify, so we acknowledge it with a
        //                200 to stop PayPal retrying, but we do NOT process it.
        //   'error'   => we could not complete verification (relay/API issue,
        //                missing config). Return a retryable status so PayPal
        //                re-delivers a legitimate event later.
        $verification = $this->verify_webhook_signature( $raw_body );

        if ( 'valid' !== $verification ) {
            if ( 'invalid' === $verification ) {
                WC_Gateway_PayPal_Commerce::log( sprintf(
                    'Webhook ignored: signature did not match this account\'s webhook (likely a stale or cross-account webhook). Acknowledging without processing. Event: %s, ID: %s',
                    isset( $event['event_type'] ) ? $event['event_type'] : 'unknown',
                    isset( $event['id'] ) ? $event['id'] : 'unknown'
                ), 'warning' );
                // Acknowledge so PayPal stops retrying a webhook that can never
                // verify against this account.
                status_header( 200 );
                exit;
            }

            // Transient / unknown verification problem — ask PayPal to retry.
            WC_Gateway_PayPal_Commerce::log( 'Webhook signature could not be verified (transient); requesting retry.', 'error' );
            status_header( 503 );
            exit;
        }

        // Process the event
        $this->process_event( $event );

        // Return 200 OK
        status_header( 200 );
        exit;
    }


    /**
     * Verify webhook signature using PayPal's API.
     *
     * @param string $raw_body Raw POST body.
     * @return string 'valid', 'invalid' (definitive signature mismatch), or
     *                'error' (could not complete verification).
     */
    private function verify_webhook_signature( $raw_body ) {
        // Validate all required headers are present. Missing headers means the
        // request is malformed / not a genuine PayPal delivery — definitively
        // not processable, so deflect it (acknowledge) rather than retry.
        try {
            $this->validate_webhook_headers();
        } catch ( Exception $e ) {
            WC_Gateway_PayPal_Commerce::log( 'Webhook verification failed: ' . $e->getMessage(), 'warning' );
            return 'invalid';
        }

        // Get headers
        $headers = $this->get_webhook_headers();

        if ( empty( $headers['transmission_id'] ) || empty( $headers['transmission_sig'] ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Missing required webhook headers', 'warning' );
            return 'invalid';
        }

        // Deliveries are accepted from BOTH modes, whichever the site is set to
        // right now: a store that has switched to live still needs sandbox
        // events for the test orders it took earlier (and vice versa). Each mode
        // has its own webhook id and credentials, so try the current mode first
        // and fall back to the other. Handlers then refuse to act on an order
        // paid in the other mode.
        $current_mode = rpsfw_get_gateway_mode( $this->gateway->id );
        $modes        = ( 'test' === $current_mode ) ? array( 'test', 'live' ) : array( 'live', 'test' );
        $onboarding   = $this->gateway->get_option( 'ppcp_onboarding', array() );

        $any_configured = false;
        $saw_transient  = false;

        foreach ( $modes as $mode ) {
            $env        = rpsfw_payment_mode_to_ppcp_env( $mode );
            $webhook_id = $this->gateway->get_option( 'webhook_id_' . $env );
            if ( empty( $webhook_id ) || empty( $onboarding[ $env ] ) ) {
                continue;
            }
            $any_configured = true;

            $result = $this->gateway->api->verify_webhook_signature(
                $env,
                $onboarding[ $env ],
                $webhook_id,
                $headers,
                $raw_body
            );

            // Relay reached PayPal and got a definitive verification status.
            if ( $result && isset( $result['verification_status'] ) ) {
                if ( 'SUCCESS' === $result['verification_status'] ) {
                    // The verified account IS the event's mode; everything
                    // downstream (credential selection, the order guard) reads
                    // it from here.
                    rpsfw_current_webhook_mode( $mode );
                    if ( $mode !== $current_mode ) {
                        WC_Gateway_PayPal_Commerce::log( sprintf(
                            'Webhook verified against the %s account while the gateway is set to %s. Processing it as a %s event.',
                            $mode,
                            $current_mode,
                            $mode
                        ) );
                    }
                    return 'valid';
                }

                // PayPal explicitly returned FAILURE for this account: the
                // signature does not match its webhook id. Try the other mode
                // before giving up.
                continue;
            }

            // No definitive status (relay error, WP_Error, etc.) — transient.
            WC_Gateway_PayPal_Commerce::log( 'Webhook verification result for ' . $env . ' (no status): ' . print_r( $result, true ), 'error' );
            $saw_transient = true;
        }

        // No webhook configured for either mode: we cannot verify. Treat as a
        // (transient) error so it is not silently processed and can be retried
        // once the merchant configures a webhook.
        if ( ! $any_configured ) {
            WC_Gateway_PayPal_Commerce::log( 'Webhook verification failed: No webhook ID configured for either mode', 'error' );
            return 'error';
        }

        // A transient failure on any account means we cannot say the delivery is
        // definitively bad — ask PayPal to retry.
        return $saw_transient ? 'error' : 'invalid';
    }

    /**
     * Validate that all required webhook headers are present.
     * Matches pymntpl-paypal-woocommerce implementation.
     *
     * @throws Exception If any required header is missing.
     */
    private function validate_webhook_headers() {
        $required_headers = array(
            'HTTP_PAYPAL_TRANSMISSION_SIG',
            'HTTP_PAYPAL_AUTH_ALGO',
            'HTTP_PAYPAL_CERT_URL',
            'HTTP_PAYPAL_TRANSMISSION_ID',
            'HTTP_PAYPAL_TRANSMISSION_TIME'
        );
        
        foreach ( $required_headers as $header ) {
            if ( empty( $_SERVER[ $header ] ) ) {
                throw new Exception( esc_html( sprintf( 'The %s header cannot be empty.', $header ) ) );
            }
        }
    }

    /**
     * Get webhook headers from request.
     *
     * @return array
     */
    private function get_webhook_headers() {
        $headers = array();
        
        // PayPal sends these headers with webhook notifications
        $header_map = array(
            'HTTP_PAYPAL_TRANSMISSION_ID'   => 'transmission_id',
            'HTTP_PAYPAL_TRANSMISSION_TIME' => 'transmission_time',
            'HTTP_PAYPAL_TRANSMISSION_SIG'  => 'transmission_sig',
            'HTTP_PAYPAL_CERT_URL'          => 'cert_url',
            'HTTP_PAYPAL_AUTH_ALGO'         => 'auth_algo',
        );

        foreach ( $header_map as $server_key => $header_key ) {
            if ( isset( $_SERVER[ $server_key ] ) ) {
                $headers[ $header_key ] = sanitize_text_field( $_SERVER[ $server_key ] );
            }
        }

        return $headers;
    }

    /**
     * Process webhook event.
     *
     * @param array $event Event data.
     */
    private function process_event( $event ) {
        $event_type = isset( $event['event_type'] ) ? $event['event_type'] : '';
        $resource = isset( $event['resource'] ) ? $event['resource'] : array();

        WC_Gateway_PayPal_Commerce::log( 'Processing webhook event: ' . $event_type );

        // Fire a generic action so the WC Subscriptions integration (and any
        // future module) can listen for any event type without us having to
        // touch the switch below.
        do_action( 'rpsfw_ppcp_webhook_event', $event_type, $resource, $event );

        switch ( $event_type ) {
            case 'PAYMENT.CAPTURE.COMPLETED':
                $this->handle_capture_completed( $resource );
                break;

            case 'PAYMENT.CAPTURE.REFUNDED':
                $this->handle_capture_refunded( $resource, $event );
                break;

            case 'PAYMENT.CAPTURE.REVERSED':
                $this->handle_capture_reversed( $resource );
                break;

            case 'PAYMENT.CAPTURE.DENIED':
                $this->handle_capture_denied( $resource );
                break;

            case 'PAYMENT.AUTHORIZATION.VOIDED':
                $this->handle_authorization_voided( $resource );
                break;

            case 'CHECKOUT.ORDER.APPROVED':
                $this->handle_order_approved( $resource );
                break;

            case 'CHECKOUT.ORDER.COMPLETED':
                $this->handle_order_completed( $resource );
                break;

            case 'CUSTOMER.DISPUTE.CREATED':
                $this->handle_dispute_created( $resource );
                break;

            case 'CUSTOMER.DISPUTE.RESOLVED':
                $this->handle_dispute_resolved( $resource );
                break;

            // Subscription payments use PayPal's "sale" model, so a refund of a
            // subscription transaction arrives as PAYMENT.SALE.REFUNDED.
            case 'PAYMENT.SALE.REFUNDED':
                $this->handle_sale_refunded( $resource );
                break;

            // Subscription-related events are handled by the WC Subscriptions
            // integration via the `rpsfw_ppcp_webhook_event` action above.
            // We acknowledge them here so the switch does not log them as
            // unhandled.
            case 'PAYMENT.SALE.COMPLETED':
            case 'BILLING.SUBSCRIPTION.CREATED':
            case 'BILLING.SUBSCRIPTION.ACTIVATED':
            case 'BILLING.SUBSCRIPTION.CANCELLED':
            case 'BILLING.SUBSCRIPTION.SUSPENDED':
            case 'BILLING.SUBSCRIPTION.EXPIRED':
            case 'BILLING.SUBSCRIPTION.PAYMENT.FAILED':
            case 'BILLING.SUBSCRIPTION.UPDATED':
                break;

            default:
                WC_Gateway_PayPal_Commerce::log( 'Unhandled webhook event type: ' . $event_type );
                break;
        }
    }

    /**
     * Handle PAYMENT.CAPTURE.COMPLETED event.
     *
     * @param array $resource Resource data.
     */
    private function handle_capture_completed( $resource ) {
        $capture_id = isset( $resource['id'] ) ? $resource['id'] : '';
        
        if ( empty( $capture_id ) ) {
            return;
        }

        // Find order by capture ID
        $order = $this->find_order_by_capture_id( $capture_id );

        // Fallback: a capture created outside this site (e.g. the merchant
        // captured the authorization from the PayPal dashboard) carries a new
        // capture id we have never stored. Resolve the parent authorization id
        // from the capture's "up" HATEOAS link and match the order by the
        // authorization id we stored at checkout.
        if ( ! $order ) {
            $authorization_id = $this->get_id_from_links( $resource, 'up', '/authorizations/' );
            if ( $authorization_id ) {
                $order = $this->find_order_by_authorization_id( $authorization_id );
            }
        }

        if ( ! $order ) {
            WC_Gateway_PayPal_Commerce::log( 'Order not found for capture ID: ' . $capture_id );
            return;
        }

        // Persist/refresh the real PayPal capture id so refund, dispute and
        // reversal webhooks can match this order later. This runs even when the
        // order is already paid, because the on-site orders-page capture flow
        // completes the order without a capture id (the relay only returns
        // success), leaving the authorization id as a placeholder until now.
        if ( $order->get_meta( '_paypal_capture_id' ) !== $capture_id ) {
            $order->update_meta_data( '_paypal_capture_id', $capture_id );
            $order->save();
        }

        // If order is already completed, skip the rest (status + payment_complete).
        if ( $order->is_paid() ) {
            WC_Gateway_PayPal_Commerce::log( 'Order already paid; capture id refreshed for: ' . $order->get_id() );
            return;
        }

        // Complete the payment
        $order->payment_complete( $capture_id );
        $order->add_order_note( __( 'Payment confirmed via PayPal webhook.', 'restore-paypal-standard-for-woocommerce' ) );
        
        WC_Gateway_PayPal_Commerce::log( 'Order completed via webhook: ' . $order->get_id() );
    }


    /**
     * Handle PAYMENT.CAPTURE.REFUNDED event.
     *
     * @param array $resource Resource data.
     * @param array $event    Full event data.
     */
    private function handle_capture_refunded( $resource, $event ) {
        // For refund events, resource.id is the REFUND id, not the capture id.
        // The original capture id is in the "up" HATEOAS link. Resolve it so we
        // can find the order.
        $refund_id  = isset( $resource['id'] ) ? $resource['id'] : '';
        $capture_id = $this->get_capture_id_from_refund_resource( $resource );

        if ( empty( $capture_id ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Refund webhook: could not determine capture id from resource. Refund id: ' . $refund_id, 'warning' );
            return;
        }

        // Find order by capture ID
        $order = $this->find_order_by_capture_id( $capture_id );
        
        if ( ! $order ) {
            WC_Gateway_PayPal_Commerce::log( 'Order not found for refund. Capture ID: ' . $capture_id . ', Refund ID: ' . $refund_id );
            return;
        }

        // v2 capture refund amount is in amount.value.
        $refund_amount = isset( $resource['amount']['value'] ) ? floatval( $resource['amount']['value'] ) : 0;

        $this->apply_refund_to_order( $order, $refund_id, $refund_amount, isset( $resource['status'] ) ? $resource['status'] : 'COMPLETED' );
    }

    /**
     * Handle PAYMENT.SALE.REFUNDED event.
     *
     * Subscription payments use PayPal's older "sale" model, so refunds of a
     * subscription transaction arrive as PAYMENT.SALE.REFUNDED rather than
     * PAYMENT.CAPTURE.REFUNDED. Here resource.id is the refund id and
     * resource.sale_id is the original sale id (which we store on the order as
     * the transaction id for renewals, or _rpsfw_ppcp_last_payment_id for the
     * subscription parent's first sale).
     *
     * @param array $resource Resource data.
     */
    private function handle_sale_refunded( $resource ) {
        $refund_id = isset( $resource['id'] ) ? $resource['id'] : '';

        $sale_id = isset( $resource['sale_id'] ) ? $resource['sale_id'] : '';
        if ( empty( $sale_id ) ) {
            // Fallback: the original sale is referenced in the "sale" link.
            $sale_id = $this->get_id_from_links( $resource, 'sale', '/sale/' );
        }

        if ( empty( $sale_id ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Sale refund webhook: could not determine sale id. Refund id: ' . $refund_id, 'warning' );
            return;
        }

        $order = $this->find_order_by_capture_id( $sale_id );

        if ( ! $order ) {
            WC_Gateway_PayPal_Commerce::log( 'Order not found for sale refund. Sale ID: ' . $sale_id . ', Refund ID: ' . $refund_id );
            return;
        }

        // v1 sale refund amount is in amount.total (not amount.value).
        $refund_amount = 0;
        if ( isset( $resource['amount']['total'] ) ) {
            $refund_amount = floatval( $resource['amount']['total'] );
        } elseif ( isset( $resource['amount']['value'] ) ) {
            $refund_amount = floatval( $resource['amount']['value'] );
        }

        $this->apply_refund_to_order( $order, $refund_id, $refund_amount, isset( $resource['state'] ) ? $resource['state'] : 'COMPLETED' );
    }

    /**
     * Record a PayPal-initiated refund on a WooCommerce order: dedupe by refund
     * id, add a note, and mark the order refunded once fully refunded. Shared
     * by the capture-refund and sale-refund webhook handlers.
     *
     * @param WC_Order $order         Order to update.
     * @param string   $refund_id     PayPal refund id (for dedupe).
     * @param float    $refund_amount Amount refunded.
     * @param string   $status        Refund status for the meta record.
     */
    private function apply_refund_to_order( $order, $refund_id, $refund_amount, $status = 'COMPLETED' ) {
        // Idempotency: skip if we've already recorded this PayPal refund id
        // (from an on-site refund via process_refund, or a previous delivery
        // of this same webhook).
        $existing_refunds = $order->get_meta( '_paypal_refund_ids' );
        if ( ! is_array( $existing_refunds ) ) {
            $existing_refunds = array();
        }
        foreach ( $existing_refunds as $refund ) {
            if ( isset( $refund['refund_id'] ) && $refund_id && $refund['refund_id'] === $refund_id ) {
                WC_Gateway_PayPal_Commerce::log( 'Refund already processed, skipping: ' . $refund_id );
                return;
            }
        }

        // Record this refund id so repeat webhook deliveries are deduped.
        if ( $refund_id ) {
            $existing_refunds[] = array(
                'refund_id' => $refund_id,
                'amount'    => $refund_amount,
                'status'    => $status,
                'date'      => current_time( 'mysql' ),
                'source'    => 'webhook',
            );
            $order->update_meta_data( '_paypal_refund_ids', $existing_refunds );
            $order->save();
        }

        // Mirror the PayPal-initiated refund into WooCommerce as a real refund
        // line item so the order total, status and the "Refunded" line in the
        // order screen all update — not just the order note. This only runs for
        // refunds that originated on PayPal's side (e.g. the PayPal dashboard);
        // refunds started from WooCommerce already created their own refund
        // line item before process_refund() ran, and the idempotency check
        // above skips their webhook by refund id.
        //
        // Clamp to the amount WooCommerce does not yet know about so we never
        // ask wc_create_refund() for more than the remaining refundable total
        // (which would return a WP_Error).
        $order_total     = floatval( $order->get_total() );
        $already_refunded = floatval( $order->get_total_refunded() );
        $refundable      = round( $order_total - $already_refunded, 2 );
        $amount_to_record = min( floatval( $refund_amount ), $refundable );

        if ( $amount_to_record > 0 ) {
            // refund_payment => false: PayPal has already moved the money, we
            // are only reflecting it in WooCommerce.
            $wc_refund = wc_create_refund( array(
                'amount'         => $amount_to_record,
                'reason'         => sprintf(
                    /* translators: %s: PayPal refund id. */
                    __( 'Refunded via PayPal (Refund ID: %s)', 'restore-paypal-standard-for-woocommerce' ),
                    $refund_id
                ),
                'order_id'       => $order->get_id(),
                'refund_payment' => false,
                'restock_items'  => false,
            ) );

            if ( is_wp_error( $wc_refund ) ) {
                WC_Gateway_PayPal_Commerce::log( 'Failed to create WooCommerce refund from PayPal webhook: ' . $wc_refund->get_error_message(), 'error' );
                // Fall back to a note so the merchant is at least aware.
                $order->add_order_note( sprintf(
                    /* translators: %1$s: refund amount, %2$s: currency code. */
                    __( 'PayPal refund notification received via webhook. Amount: %1$s %2$s', 'restore-paypal-standard-for-woocommerce' ),
                    $refund_amount,
                    $order->get_currency()
                ) );
            } else {
                $order->add_order_note( sprintf(
                    /* translators: %1$s: refund amount, %2$s: currency code, %3$s: PayPal refund id. */
                    __( 'PayPal refund synced via webhook. Amount: %1$s %2$s (Refund ID: %3$s)', 'restore-paypal-standard-for-woocommerce' ),
                    $amount_to_record,
                    $order->get_currency(),
                    $refund_id
                ) );
            }
        } else {
            // Nothing new to record in WooCommerce (already fully refunded, or
            // the amount was already accounted for). Leave a note for the trail.
            $order->add_order_note( sprintf(
                /* translators: %1$s: refund amount, %2$s: currency code. */
                __( 'PayPal refund notification received via webhook. Amount: %1$s %2$s', 'restore-paypal-standard-for-woocommerce' ),
                $refund_amount,
                $order->get_currency()
            ) );
        }

        // wc_create_refund() moves the order to "refunded" automatically once
        // it is fully refunded. Guard the case where the WC refund could not be
        // created but PayPal still reports the order fully refunded.
        $total_refunded = floatval( $order->get_total_refunded() );
        if ( $order_total > 0 && $total_refunded >= $order_total && ! $order->has_status( 'refunded' ) ) {
            $order->update_status( 'refunded', __( 'Order fully refunded via PayPal.', 'restore-paypal-standard-for-woocommerce' ) );
        }

        WC_Gateway_PayPal_Commerce::log( 'Refund webhook processed for order: ' . $order->get_id() );
    }

    /**
     * Handle PAYMENT.CAPTURE.REVERSED event.
     *
     * @param array $resource Resource data.
     */
    private function handle_capture_reversed( $resource ) {
        // Reversal events also carry the refund/reversal id in resource.id; the
        // original capture id is in the "up" link.
        $capture_id = $this->get_capture_id_from_refund_resource( $resource );
        if ( empty( $capture_id ) ) {
            $capture_id = isset( $resource['id'] ) ? $resource['id'] : '';
        }

        if ( empty( $capture_id ) ) {
            return;
        }

        $order = $this->find_order_by_capture_id( $capture_id );
        
        if ( ! $order ) {
            WC_Gateway_PayPal_Commerce::log( 'Order not found for reversed capture ID: ' . $capture_id );
            return;
        }

        $order->update_status( 'on-hold', __( 'Payment reversed by PayPal. Please review this order.', 'restore-paypal-standard-for-woocommerce' ) );
        $order->add_order_note( __( 'PayPal payment was reversed (chargeback or dispute). Please check your PayPal account.', 'restore-paypal-standard-for-woocommerce' ) );
        
        WC_Gateway_PayPal_Commerce::log( 'Payment reversed for order: ' . $order->get_id() );
    }

    /**
     * Handle PAYMENT.CAPTURE.DENIED event.
     *
     * @param array $resource Resource data.
     */
    private function handle_capture_denied( $resource ) {
        $capture_id = isset( $resource['id'] ) ? $resource['id'] : '';
        
        if ( empty( $capture_id ) ) {
            return;
        }

        $order = $this->find_order_by_capture_id( $capture_id );
        
        if ( ! $order ) {
            WC_Gateway_PayPal_Commerce::log( 'Order not found for denied capture ID: ' . $capture_id );
            return;
        }

        $order->update_status( 'failed', __( 'Payment capture denied by PayPal.', 'restore-paypal-standard-for-woocommerce' ) );
        
        WC_Gateway_PayPal_Commerce::log( 'Payment denied for order: ' . $order->get_id() );
    }

    /**
     * Handle PAYMENT.AUTHORIZATION.VOIDED event.
     *
     * Fires when an authorization is voided (by the merchant from the PayPal
     * dashboard, or automatically once it expires past the authorization
     * period). A voided authorization can no longer be captured, so the
     * matching on-hold order is cancelled instead of being left waiting for a
     * capture that can never succeed.
     *
     * @param array $resource Resource data.
     */
    private function handle_authorization_voided( $resource ) {
        // For an authorization resource the id IS the authorization id.
        $authorization_id = isset( $resource['id'] ) ? $resource['id'] : '';

        if ( empty( $authorization_id ) ) {
            return;
        }

        $order = $this->find_order_by_authorization_id( $authorization_id );

        if ( ! $order ) {
            WC_Gateway_PayPal_Commerce::log( 'Order not found for voided authorization ID: ' . $authorization_id );
            return;
        }

        // Already paid (captured before the void) or already cancelled: nothing
        // to do. Voiding a captured authorization does not reverse the capture.
        if ( $order->is_paid() || $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Voided authorization for order not awaiting capture; no status change: ' . $order->get_id() );
            return;
        }

        $order->update_status(
            'cancelled',
            __( 'PayPal authorization was voided and can no longer be captured. Order cancelled.', 'restore-paypal-standard-for-woocommerce' )
        );

        WC_Gateway_PayPal_Commerce::log( 'Authorization voided; order cancelled: ' . $order->get_id() );
    }

    /**
     * Handle CHECKOUT.ORDER.APPROVED event.
     *
     * @param array $resource Resource data.
     */
    private function handle_order_approved( $resource ) {
        // This is informational - the actual capture happens via onApprove callback
        $order_id = isset( $resource['id'] ) ? $resource['id'] : '';
        WC_Gateway_PayPal_Commerce::log( 'Order approved webhook received for PayPal order: ' . $order_id );
    }

    /**
     * Handle CHECKOUT.ORDER.COMPLETED event.
     *
     * @param array $resource Resource data.
     */
    private function handle_order_completed( $resource ) {
        $paypal_order_id = isset( $resource['id'] ) ? $resource['id'] : '';
        
        if ( empty( $paypal_order_id ) ) {
            return;
        }

        // Find order by PayPal order ID
        $order = $this->find_order_by_paypal_order_id( $paypal_order_id );
        
        if ( ! $order ) {
            WC_Gateway_PayPal_Commerce::log( 'Order not found for PayPal order ID: ' . $paypal_order_id );
            return;
        }

        // If order is already completed, skip
        if ( $order->is_paid() ) {
            return;
        }

        // Authorize-only orders: PayPal sends CHECKOUT.ORDER.COMPLETED as soon
        // as the authorization is created. "Completed" here means the checkout
        // finished and an authorization exists — it does NOT mean funds were
        // captured. These orders must stay on-hold until the merchant captures
        // them (via the order action or the PayPal dashboard, which then fires
        // PAYMENT.CAPTURE.COMPLETED). Detect this by the absence of a capture
        // in the resource on an order that carries an authorization id, and
        // leave it on-hold.
        $has_capture = ! empty( $resource['purchase_units'][0]['payments']['captures'][0]['id'] );
        if ( ! $has_capture && $order->get_meta( '_paypal_authorization_id' ) ) {
            WC_Gateway_PayPal_Commerce::log( 'ORDER.COMPLETED for an authorized (not captured) order; leaving on-hold for manual capture: ' . $order->get_id() );
            return;
        }

        // Get capture ID from the resource
        $capture_id = '';
        if ( isset( $resource['purchase_units'][0]['payments']['captures'][0]['id'] ) ) {
            $capture_id = $resource['purchase_units'][0]['payments']['captures'][0]['id'];
        }

        $order->payment_complete( $capture_id ?: $paypal_order_id );
        $order->add_order_note( __( 'Payment confirmed via PayPal webhook (ORDER.COMPLETED).', 'restore-paypal-standard-for-woocommerce' ) );
        
        WC_Gateway_PayPal_Commerce::log( 'Order completed via webhook: ' . $order->get_id() );
    }

    /**
     * Handle CUSTOMER.DISPUTE.CREATED event.
     *
     * @param array $resource Resource data.
     */
    private function handle_dispute_created( $resource ) {
        // Check if dispute handling is enabled
        if ( 'yes' !== $this->gateway->get_option( 'dispute_created_enabled', 'yes' ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Dispute created handling is disabled, skipping.' );
            return;
        }

        $dispute_id = isset( $resource['dispute_id'] ) ? $resource['dispute_id'] : '';
        
        if ( empty( $dispute_id ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Dispute created webhook missing dispute_id' );
            return;
        }

        // Get the transaction ID from the disputed transactions
        $capture_id = '';
        if ( isset( $resource['disputed_transactions'][0]['seller_transaction_id'] ) ) {
            $capture_id = $resource['disputed_transactions'][0]['seller_transaction_id'];
        }

        if ( empty( $capture_id ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Dispute created webhook missing transaction ID. Dispute ID: ' . $dispute_id );
            return;
        }

        // Find order by capture ID
        $order = $this->find_order_by_capture_id( $capture_id );
        
        if ( ! $order ) {
            WC_Gateway_PayPal_Commerce::log( 'Order not found for dispute. Capture ID: ' . $capture_id . ', Dispute ID: ' . $dispute_id );
            return;
        }

        // Store the current status before changing it (for dispute resolved)
        $current_status = $order->get_status();
        $order->update_meta_data( '_paypal_pre_dispute_status', $current_status );
        $order->update_meta_data( '_paypal_dispute_id', $dispute_id );
        $order->save();

        // Get the configured status for dispute created
        $dispute_status = $this->gateway->get_option( 'dispute_created_status', 'wc-on-hold' );
        // Remove 'wc-' prefix if present (WooCommerce stores with prefix but update_status needs without)
        $dispute_status = str_replace( 'wc-', '', $dispute_status );

        // Get dispute details for the order note
        $dispute_reason = isset( $resource['reason'] ) ? $resource['reason'] : __( 'Unknown', 'restore-paypal-standard-for-woocommerce' );
        $dispute_amount = '';
        if ( isset( $resource['dispute_amount']['value'] ) && isset( $resource['dispute_amount']['currency_code'] ) ) {
            $dispute_amount = $resource['dispute_amount']['value'] . ' ' . $resource['dispute_amount']['currency_code'];
        }

        // Update order status
        $note = sprintf(
            /* translators: %1$s: dispute ID, %2$s: dispute reason. */
            __( 'PayPal dispute opened. Dispute ID: %1$s, Reason: %2$s', 'restore-paypal-standard-for-woocommerce' ),
            $dispute_id,
            $dispute_reason
        );
        if ( ! empty( $dispute_amount ) ) {
            /* translators: %s: dispute amount. */
            $note .= sprintf( __( ', Amount: %s', 'restore-paypal-standard-for-woocommerce' ), $dispute_amount );
        }
        $note .= __( '. Please check your PayPal account for details.', 'restore-paypal-standard-for-woocommerce' );

        $order->update_status( $dispute_status, $note );
        
        WC_Gateway_PayPal_Commerce::log( sprintf( 
            'Dispute created for order #%d. Dispute ID: %s, New status: %s',
            $order->get_id(),
            $dispute_id,
            $dispute_status
        ) );
    }

    /**
     * Handle CUSTOMER.DISPUTE.RESOLVED event.
     *
     * @param array $resource Resource data.
     */
    private function handle_dispute_resolved( $resource ) {
        // Check if dispute resolved handling is enabled
        if ( 'yes' !== $this->gateway->get_option( 'dispute_resolved_enabled', 'yes' ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Dispute resolved handling is disabled, skipping.' );
            return;
        }

        $dispute_id = isset( $resource['dispute_id'] ) ? $resource['dispute_id'] : '';
        
        if ( empty( $dispute_id ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Dispute resolved webhook missing dispute_id' );
            return;
        }

        // Get the transaction ID from the disputed transactions
        $capture_id = '';
        if ( isset( $resource['disputed_transactions'][0]['seller_transaction_id'] ) ) {
            $capture_id = $resource['disputed_transactions'][0]['seller_transaction_id'];
        }

        if ( empty( $capture_id ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Dispute resolved webhook missing transaction ID. Dispute ID: ' . $dispute_id );
            return;
        }

        // Find order by capture ID
        $order = $this->find_order_by_capture_id( $capture_id );
        
        if ( ! $order ) {
            WC_Gateway_PayPal_Commerce::log( 'Order not found for resolved dispute. Capture ID: ' . $capture_id . ', Dispute ID: ' . $dispute_id );
            return;
        }

        // Get dispute outcome
        $dispute_outcome = isset( $resource['dispute_outcome']['outcome_code'] ) ? $resource['dispute_outcome']['outcome_code'] : '';
        $outcome_text = $this->get_dispute_outcome_text( $dispute_outcome );

        // Get the pre-dispute status
        $pre_dispute_status = $order->get_meta( '_paypal_pre_dispute_status' );

        // Build order note
        $note = sprintf(
            /* translators: %1$s: dispute ID, %2$s: dispute outcome. */
            __( 'PayPal dispute resolved. Dispute ID: %1$s, Outcome: %2$s', 'restore-paypal-standard-for-woocommerce' ),
            $dispute_id,
            $outcome_text
        );

        // Restore to pre-dispute status if available and outcome is favorable
        if ( ! empty( $pre_dispute_status ) && in_array( $dispute_outcome, array( 'RESOLVED_BUYER_FAVOUR', 'RESOLVED_SELLER_FAVOUR', 'RESOLVED_WITH_PAYOUT', 'CANCELED_BY_BUYER' ), true ) ) {
            // Only restore status if outcome is not against seller
            if ( $dispute_outcome !== 'RESOLVED_BUYER_FAVOUR' ) {
                $order->update_status( $pre_dispute_status, $note );
                WC_Gateway_PayPal_Commerce::log( sprintf( 
                    'Dispute resolved for order #%d. Restored to status: %s',
                    $order->get_id(),
                    $pre_dispute_status
                ) );
            } else {
                // Buyer won - keep on hold or set to refunded
                $order->add_order_note( $note );
                WC_Gateway_PayPal_Commerce::log( sprintf( 
                    'Dispute resolved in buyer favor for order #%d. Status not changed.',
                    $order->get_id()
                ) );
            }
        } else {
            // Just add note without changing status
            $order->add_order_note( $note );
            WC_Gateway_PayPal_Commerce::log( sprintf( 
                'Dispute resolved for order #%d. Outcome: %s',
                $order->get_id(),
                $dispute_outcome
            ) );
        }

        // Clean up dispute meta
        $order->delete_meta_data( '_paypal_pre_dispute_status' );
        $order->save();
    }

    /**
     * Get human-readable dispute outcome text.
     *
     * @param string $outcome_code PayPal outcome code.
     * @return string
     */
    private function get_dispute_outcome_text( $outcome_code ) {
        $outcomes = array(
            'RESOLVED_BUYER_FAVOUR'  => __( 'Resolved in buyer\'s favor', 'restore-paypal-standard-for-woocommerce' ),
            'RESOLVED_SELLER_FAVOUR' => __( 'Resolved in seller\'s favor', 'restore-paypal-standard-for-woocommerce' ),
            'RESOLVED_WITH_PAYOUT'   => __( 'Resolved with payout', 'restore-paypal-standard-for-woocommerce' ),
            'CANCELED_BY_BUYER'      => __( 'Canceled by buyer', 'restore-paypal-standard-for-woocommerce' ),
            'ACCEPTED'               => __( 'Accepted', 'restore-paypal-standard-for-woocommerce' ),
            'DENIED'                 => __( 'Denied', 'restore-paypal-standard-for-woocommerce' ),
            'NONE'                   => __( 'None', 'restore-paypal-standard-for-woocommerce' ),
        );

        return isset( $outcomes[ $outcome_code ] ) ? $outcomes[ $outcome_code ] : $outcome_code;
    }


    /**
     * Extract the original capture id from a refund/reversal webhook resource.
     *
     * For PAYMENT.CAPTURE.REFUNDED / PAYMENT.CAPTURE.REVERSED, resource.id is
     * the refund/reversal id. The original capture id is carried in the "up"
     * HATEOAS link, e.g. https://api.paypal.com/v2/payments/captures/{id}.
     *
     * @param array $resource Webhook resource.
     * @return string Capture id, or '' if it cannot be determined.
     */
    private function get_capture_id_from_refund_resource( $resource ) {
        return $this->get_id_from_links( $resource, 'up', '/captures/' );
    }

    /**
     * Pull an id out of a resource's HATEOAS links by matching the link rel and
     * a path fragment, e.g. rel "up" + "/captures/" or rel "sale" + "/sale/".
     *
     * @param array  $resource Webhook resource.
     * @param string $rel      Link relation to match (case-insensitive).
     * @param string $needle   Path fragment that precedes the id in the href.
     * @return string The id, or '' if not found.
     */
    private function get_id_from_links( $resource, $rel, $needle ) {
        if ( empty( $resource['links'] ) || ! is_array( $resource['links'] ) ) {
            return '';
        }

        $rel = strtolower( $rel );
        foreach ( $resource['links'] as $link ) {
            if ( empty( $link['href'] ) ) {
                continue;
            }
            $link_rel = isset( $link['rel'] ) ? strtolower( $link['rel'] ) : '';
            if ( $rel === $link_rel && false !== strpos( $link['href'], $needle ) ) {
                $parts = explode( $needle, $link['href'] );
                $tail  = end( $parts );
                $id    = trim( preg_replace( '/[\/?].*$/', '', (string) $tail ) );
                if ( '' !== $id ) {
                    return $id;
                }
            }
        }

        return '';
    }

    /**
     * Cross-mode protection for a resolved order.
     *
     * An order paid in one mode must never be touched by an event from the
     * other: the two accounts' ids are unrelated, so a cross-mode match means we
     * resolved the wrong record — or someone is replaying sandbox events at live
     * orders. The site's current mode is irrelevant; only the mode the order was
     * paid in counts.
     *
     * @param WC_Order|false $order Resolved order.
     * @return WC_Order|false The order, or false when the modes disagree.
     */
    private function guard_order_mode( $order ) {
        if ( ! $order || rpsfw_webhook_may_touch_order( $order ) ) {
            return $order;
        }

        WC_Gateway_PayPal_Commerce::log( sprintf(
            'Refusing %s mode event for order #%d, which was paid in %s mode.',
            rpsfw_current_webhook_mode(),
            $order->get_id(),
            rpsfw_get_order_payment_mode( $order )
        ), 'warning' );

        return false;
    }

    /**
     * Find WooCommerce order by PayPal capture ID.
     *
     * @param string $capture_id PayPal capture ID.
     * @return WC_Order|false
     */
    private function find_order_by_capture_id( $capture_id ) {
        // First try by transaction ID
        $orders = wc_get_orders( array(
            'transaction_id' => $capture_id,
            'limit'          => 1,
            'payment_method' => 'rpsfw_paypal_commerce',
        ) );

        if ( ! empty( $orders ) ) {
            return $this->guard_order_mode( $orders[0] );
        }

        // Try by capture ID meta
        $orders = wc_get_orders( array(
            'meta_key'       => '_paypal_capture_id',
            'meta_value'     => $capture_id,
            'limit'          => 1,
            'payment_method' => 'rpsfw_paypal_commerce',
        ) );

        if ( ! empty( $orders ) ) {
            return $this->guard_order_mode( $orders[0] );
        }

        // Subscription parent orders store the PayPal subscription id as the
        // WooCommerce transaction id, and the actual first-sale capture id in
        // the _rpsfw_ppcp_last_payment_id meta. A dispute/refund that
        // references that first-sale capture id is found here. (Renewal orders
        // store their sale id as the transaction id, so they match above.)
        $orders = wc_get_orders( array(
            'meta_key'       => '_rpsfw_ppcp_last_payment_id',
            'meta_value'     => $capture_id,
            'limit'          => 1,
            'payment_method' => 'rpsfw_paypal_commerce',
        ) );

        if ( ! empty( $orders ) ) {
            return $this->guard_order_mode( $orders[0] );
        }

        return false;
    }

    /**
     * Find WooCommerce order by PayPal authorization ID.
     *
     * Authorize-only orders store the authorization id both as the WC
     * transaction id and in the _paypal_authorization_id meta. Used to match
     * captures performed outside this site (e.g. from the PayPal dashboard),
     * whose new capture id we have never seen.
     *
     * @param string $authorization_id PayPal authorization ID.
     * @return WC_Order|false
     */
    private function find_order_by_authorization_id( $authorization_id ) {
        if ( empty( $authorization_id ) ) {
            return false;
        }

        $orders = wc_get_orders( array(
            'transaction_id' => $authorization_id,
            'limit'          => 1,
            'payment_method' => 'rpsfw_paypal_commerce',
        ) );

        if ( ! empty( $orders ) ) {
            return $this->guard_order_mode( $orders[0] );
        }

        $orders = wc_get_orders( array(
            'meta_key'       => '_paypal_authorization_id',
            'meta_value'     => $authorization_id,
            'limit'          => 1,
            'payment_method' => 'rpsfw_paypal_commerce',
        ) );

        if ( ! empty( $orders ) ) {
            return $this->guard_order_mode( $orders[0] );
        }

        return false;
    }

    /**
     * Find WooCommerce order by PayPal order ID.
     *
     * @param string $paypal_order_id PayPal order ID.
     * @return WC_Order|false
     */
    private function find_order_by_paypal_order_id( $paypal_order_id ) {
        $orders = wc_get_orders( array(
            'meta_key'       => '_paypal_order_id',
            'meta_value'     => $paypal_order_id,
            'limit'          => 1,
            'payment_method' => 'rpsfw_paypal_commerce',
        ) );

        if ( ! empty( $orders ) ) {
            return $this->guard_order_mode( $orders[0] );
        }

        return false;
    }

    /**
     * AJAX handler to create webhook.
     */
    public function ajax_create_webhook() {
        check_ajax_referer( 'rpsfw-ppcp-webhook', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'restore-paypal-standard-for-woocommerce' ) ) );
        }

        $env = isset( $_POST['env'] ) ? sanitize_text_field( $_POST['env'] ) : '';
        
        if ( ! in_array( $env, array( 'sandbox', 'live' ), true ) ) {
            $env = $this->gateway->testmode ? 'sandbox' : 'live';
        }

        $onboarding = $this->gateway->get_option( 'ppcp_onboarding', array() );
        
        if ( empty( $onboarding[ $env ]['seller_id'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Please connect to PayPal first.', 'restore-paypal-standard-for-woocommerce' ) ) );
        }

        // Check if webhook already exists
        $existing_webhook_id = $this->gateway->get_option( 'webhook_id_' . $env );
        if ( ! empty( $existing_webhook_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Webhook already exists. Delete it first to create a new one.', 'restore-paypal-standard-for-woocommerce' ) ) );
        }

        // Create webhook via API
        $result = $this->gateway->api->create_webhook(
            $env,
            $onboarding[ $env ],
            $this->get_webhook_url(),
            $this->webhook_events
        );

        if ( $result && ! empty( $result['webhook_id'] ) ) {
            // Save webhook ID
            $this->gateway->update_option( 'webhook_id_' . $env, $result['webhook_id'] );
            
            WC_Gateway_PayPal_Commerce::log( 'Webhook created successfully. ID: ' . $result['webhook_id'] );
            
            wp_send_json_success( array(
                'message'    => __( 'Webhook created successfully!', 'restore-paypal-standard-for-woocommerce' ),
                'webhook_id' => $result['webhook_id'],
            ) );
        } else {
            $error_message = isset( $result['message'] ) ? $result['message'] : __( 'Failed to create webhook.', 'restore-paypal-standard-for-woocommerce' );
            wp_send_json_error( array( 'message' => $error_message ) );
        }
    }

    /**
     * AJAX handler to delete webhook.
     */
    public function ajax_delete_webhook() {
        check_ajax_referer( 'rpsfw-ppcp-webhook', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'restore-paypal-standard-for-woocommerce' ) ) );
        }

        $env = isset( $_POST['env'] ) ? sanitize_text_field( $_POST['env'] ) : '';
        
        if ( ! in_array( $env, array( 'sandbox', 'live' ), true ) ) {
            $env = $this->gateway->testmode ? 'sandbox' : 'live';
        }

        $webhook_id = $this->gateway->get_option( 'webhook_id_' . $env );
        
        if ( empty( $webhook_id ) ) {
            wp_send_json_error( array( 'message' => __( 'No webhook configured.', 'restore-paypal-standard-for-woocommerce' ) ) );
        }

        $onboarding = $this->gateway->get_option( 'ppcp_onboarding', array() );

        // Delete webhook via API
        $result = $this->gateway->api->delete_webhook( $env, isset( $onboarding[ $env ] ) ? $onboarding[ $env ] : array(), $webhook_id );

        // Remove webhook ID from settings regardless of API result
        $this->gateway->update_option( 'webhook_id_' . $env, '' );

        WC_Gateway_PayPal_Commerce::log( 'Manual delete webhook result for ' . $env . ' (ID: ' . $webhook_id . '): ' . wp_json_encode( $result ) );

        if ( ! empty( $result['success'] ) ) {
            wp_send_json_success( array(
                'message'          => __( 'Webhook deleted successfully!', 'restore-paypal-standard-for-woocommerce' ),
                'webhook_deletion' => $result,
            ) );
        }

        // Local ID is cleared, but PayPal still has the webhook. Warn the merchant
        // so they know it is orphaned and will collide on the next create.
        wp_send_json_success( array(
            'message'          => sprintf(
                /* translators: %s: failure reason from PayPal */
                __( 'Local webhook ID cleared, but PayPal did NOT confirm deletion: %s The webhook may still be active on PayPal.', 'restore-paypal-standard-for-woocommerce' ),
                isset( $result['message'] ) ? $result['message'] : __( 'unknown reason.', 'restore-paypal-standard-for-woocommerce' )
            ),
            'webhook_deletion' => $result,
        ) );
    }

    /**
     * AJAX handler to check webhook status.
     */
    public function ajax_check_webhook() {
        check_ajax_referer( 'rpsfw-ppcp-webhook', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'restore-paypal-standard-for-woocommerce' ) ) );
        }

        $env = isset( $_POST['env'] ) ? sanitize_text_field( $_POST['env'] ) : '';
        
        if ( ! in_array( $env, array( 'sandbox', 'live' ), true ) ) {
            $env = $this->gateway->testmode ? 'sandbox' : 'live';
        }

        $webhook_id = $this->gateway->get_option( 'webhook_id_' . $env );
        
        if ( empty( $webhook_id ) ) {
            wp_send_json_success( array(
                'status'  => 'not_configured',
                'message' => __( 'No webhook configured.', 'restore-paypal-standard-for-woocommerce' ),
            ) );
        }

        $onboarding = $this->gateway->get_option( 'ppcp_onboarding', array() );

        // Get webhook details via API
        $result = $this->gateway->api->get_webhook( $env, isset( $onboarding[ $env ] ) ? $onboarding[ $env ] : array(), $webhook_id );

        if ( $result && ! empty( $result['id'] ) ) {
            wp_send_json_success( array(
                'status'     => 'active',
                'webhook_id' => $result['id'],
                'url'        => isset( $result['url'] ) ? $result['url'] : '',
                'events'     => isset( $result['event_types'] ) ? $result['event_types'] : array(),
                'message'    => __( 'Webhook is active.', 'restore-paypal-standard-for-woocommerce' ),
            ) );
        } else {
            // Webhook ID exists but webhook not found - clear it
            $this->gateway->update_option( 'webhook_id_' . $env, '' );
            
            wp_send_json_success( array(
                'status'  => 'not_found',
                'message' => __( 'Webhook not found. It may have been deleted from PayPal.', 'restore-paypal-standard-for-woocommerce' ),
            ) );
        }
    }

    /**
     * Get webhook status for display.
     *
     * @param string $env Environment (sandbox or live).
     * @return array
     */
    public function get_webhook_status( $env ) {
        $webhook_id = $this->gateway->get_option( 'webhook_id_' . $env );
        
        return array(
            'configured' => ! empty( $webhook_id ),
            'webhook_id' => $webhook_id,
            'url'        => $this->get_webhook_url(),
        );
    }

    /**
     * Auto-create a webhook for the given env. Called after onboarding
     * completes. Idempotent: skips if a webhook id is already stored for
     * this env. Failures are logged but do not stop the connect flow.
     *
     * @param string $env 'sandbox' or 'live'.
     */
    public function auto_create_for_env( $env ) {
        if ( ! in_array( $env, array( 'sandbox', 'live' ), true ) ) {
            return;
        }

        $existing = $this->gateway->get_option( 'webhook_id_' . $env );
        if ( ! empty( $existing ) ) {
            return;
        }

        $onboarding = $this->gateway->get_option( 'ppcp_onboarding', array() );
        if ( empty( $onboarding[ $env ]['seller_id'] ) ) {
            return;
        }

        $result = $this->gateway->api->create_webhook(
            $env,
            $onboarding[ $env ],
            $this->get_webhook_url(),
            $this->webhook_events
        );

        if ( $result && ! empty( $result['webhook_id'] ) ) {
            $this->gateway->update_option( 'webhook_id_' . $env, $result['webhook_id'] );
            WC_Gateway_PayPal_Commerce::log( 'Auto-created webhook for ' . $env . '. ID: ' . $result['webhook_id'] );
        } else {
            $msg = is_array( $result ) && ! empty( $result['message'] ) ? $result['message'] : 'unknown error';
            WC_Gateway_PayPal_Commerce::log( 'Auto-create webhook failed for ' . $env . ': ' . $msg, 'warning' );
        }
    }

    /**
     * Auto-delete the stored webhook for the given env, called from the
     * disconnect flow before credentials are wiped. Failures are logged
     * but do not stop the disconnect.
     *
     * @param string $env 'sandbox' or 'live'.
     * @return array Result describing whether the PayPal-side delete succeeded.
     */
    public function auto_delete_for_env( $env ) {
        if ( ! in_array( $env, array( 'sandbox', 'live' ), true ) ) {
            return array(
                'attempted' => false,
                'success'   => false,
                'message'   => 'Invalid environment: ' . $env,
                'reason'    => 'invalid_env',
            );
        }

        $webhook_id = $this->gateway->get_option( 'webhook_id_' . $env );
        if ( empty( $webhook_id ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Auto-delete webhook skipped for ' . $env . ': no webhook ID stored locally.' );
            return array(
                'attempted' => false,
                'success'   => false,
                'message'   => 'No webhook ID was stored locally, so there was nothing to delete from this side.',
                'reason'    => 'no_local_id',
            );
        }

        $onboarding = $this->gateway->get_option( 'ppcp_onboarding', array() );
        $onboard    = isset( $onboarding[ $env ] ) ? $onboarding[ $env ] : array();

        $result = $this->gateway->api->delete_webhook( $env, $onboard, $webhook_id );

        // Clear the stored id whether or not the API call succeeded; we are
        // about to wipe the credentials so the id is no longer usable
        // either way.
        $this->gateway->update_option( 'webhook_id_' . $env, '' );

        if ( ! empty( $result['success'] ) ) {
            WC_Gateway_PayPal_Commerce::log( 'Auto-delete webhook for ' . $env . ' SUCCEEDED. ID: ' . $webhook_id . ' was removed from PayPal.' );
        } else {
            $reason = isset( $result['message'] ) ? $result['message'] : 'unknown reason';
            WC_Gateway_PayPal_Commerce::log(
                'Auto-delete webhook for ' . $env . ' FAILED. ID: ' . $webhook_id
                . ' was NOT removed from PayPal and the local ID was cleared anyway. '
                . 'This webhook is now orphaned on PayPal and will cause WEBHOOK_URL_ALREADY_EXISTS on the next create. Reason: ' . $reason,
                'warning'
            );
        }

        return array(
            'attempted'  => true,
            'success'    => ! empty( $result['success'] ),
            'message'    => isset( $result['message'] ) ? $result['message'] : '',
            'http_code'  => isset( $result['http_code'] ) ? $result['http_code'] : 0,
            'reason'     => isset( $result['reason'] ) ? $result['reason'] : 'unknown',
            'webhook_id' => $webhook_id,
        );
    }
}
