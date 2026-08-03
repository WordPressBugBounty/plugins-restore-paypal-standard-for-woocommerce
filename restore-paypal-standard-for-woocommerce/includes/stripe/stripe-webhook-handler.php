<?php
/**
 * Stripe Webhook Handler
 *
 * Handles incoming webhook events from Stripe
 *
 * @package RestorePayPalStandard
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register webhook endpoint
 */
add_action( 'woocommerce_api_rpsfw_stripe_webhook', 'rpsfw_stripe_webhook_handler' );

/**
 * Handle Stripe webhook
 */
function rpsfw_stripe_webhook_handler() {
    $payload = @file_get_contents( 'php://input' );

    RPSFW_Gateway_Stripe::log( 'Webhook received' );

    if ( empty( $payload ) ) {
        RPSFW_Gateway_Stripe::log( 'Webhook error: Empty payload', 'error' );
        status_header( 400 );
        exit;
    }

    $options = get_option( 'woocommerce_rpsfw_stripe_settings', array() );

    // Events are accepted from BOTH modes, whichever the site is set to right
    // now: a store that has switched to live still needs the sandbox events for
    // the test orders it took earlier (and vice versa). The signature is still
    // required — only the secret it is checked against varies — and the mode the
    // event came from is carried forward so every lookup during this request
    // uses the matching account. Handlers then refuse to act on an order paid in
    // the other mode. Current mode first, so the common case verifies on the
    // first attempt.
    $current_mode    = rpsfw_get_gateway_mode( 'rpsfw_stripe' );
    $candidate_modes = ( 'test' === $current_mode ) ? array( 'test', 'live' ) : array( 'live', 'test' );
    $verified_mode   = '';

    // Two delivery paths:
    //  1) Forwarded via the wpplugin Connect server. Carries
    //     X-RPSFW-Signature (HMAC-SHA256 of "<timestamp>.<payload>"
    //     using the per-merchant shared secret captured at OAuth
    //     completion). This is the default path now.
    //  2) Direct from Stripe to this site (legacy / manual setup).
    //     Carries Stripe-Signature and verifies against
    //     webhook_secret_<mode> stored on the gateway.
    $relay_signature = isset( $_SERVER['HTTP_X_RPSFW_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_RPSFW_SIGNATURE'] ) ) : '';
    $relay_timestamp = isset( $_SERVER['HTTP_X_RPSFW_TIMESTAMP'] ) ? (int) $_SERVER['HTTP_X_RPSFW_TIMESTAMP'] : 0;

    if ( $relay_signature ) {
        // Relay path.
        // Reject events older than 10 minutes to defeat replays. Mode-independent,
        // so check once before trying either secret.
        if ( $relay_timestamp <= 0 || abs( time() - $relay_timestamp ) > 600 ) {
            RPSFW_Gateway_Stripe::log( 'Webhook error: relay timestamp out of tolerance.', 'error' );
            status_header( 401 );
            exit;
        }

        $any_secret_configured = false;
        foreach ( $candidate_modes as $mode ) {
            $relay_secret_key = ( 'test' === $mode ) ? 'relay_shared_secret_test' : 'relay_shared_secret_live';
            $shared_secret    = isset( $options[ $relay_secret_key ] ) ? $options[ $relay_secret_key ] : '';
            if ( empty( $shared_secret ) ) {
                continue;
            }
            $any_secret_configured = true;

            $expected = hash_hmac( 'sha256', $relay_timestamp . '.' . $payload, $shared_secret );
            if ( hash_equals( $expected, $relay_signature ) ) {
                $verified_mode = $mode;
                break;
            }
        }

        if ( ! $any_secret_configured ) {
            RPSFW_Gateway_Stripe::log( 'Webhook error: relay signature present but no shared secret configured for either mode.', 'error' );
            status_header( 401 );
            exit;
        }

        if ( '' === $verified_mode ) {
            // RPSFW_Gateway_Stripe::log( 'Webhook error: relay signature mismatch.', 'error' );
            status_header( 401 );
            exit;
        }

        RPSFW_Gateway_Stripe::log( 'Webhook signature verified (relay path, ' . $verified_mode . ' mode secret).' );
        $event = json_decode( $payload, true );
        if ( ! is_array( $event ) ) {
            RPSFW_Gateway_Stripe::log( 'Webhook error: invalid JSON in relay payload.', 'error' );
            status_header( 400 );
            exit;
        }
    } else {
        // Direct-from-Stripe path.
        $sig_header = isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ) : '';

        if ( empty( $sig_header ) ) {
            RPSFW_Gateway_Stripe::log( 'Webhook error: Missing Stripe-Signature header.', 'error' );
            status_header( 401 );
            exit;
        }

        $event                 = null;
        $any_secret_configured = false;
        foreach ( $candidate_modes as $mode ) {
            $webhook_secret_key = ( 'test' === $mode ) ? 'webhook_secret_test' : 'webhook_secret_live';
            $webhook_secret     = isset( $options[ $webhook_secret_key ] ) ? $options[ $webhook_secret_key ] : '';
            if ( empty( $webhook_secret ) ) {
                continue;
            }
            $any_secret_configured = true;

            try {
                $event         = \Stripe\Webhook::constructEvent( $payload, $sig_header, $webhook_secret );
                $verified_mode = $mode;
                break;
            } catch ( \UnexpectedValueException $e ) {
                // Malformed payload — no secret will help.
                RPSFW_Gateway_Stripe::log( 'Webhook error: Invalid payload - ' . $e->getMessage(), 'error' );
                status_header( 400 );
                exit;
            } catch ( \Stripe\Exception\SignatureVerificationException $e ) {
                // Wrong secret for this delivery; try the other mode's.
                continue;
            }
        }

        if ( ! $any_secret_configured ) {
            RPSFW_Gateway_Stripe::log( 'Webhook error: No webhook secret configured for either mode. Refusing event.', 'error' );
            status_header( 401 );
            exit;
        }

        if ( '' === $verified_mode || null === $event ) {
            RPSFW_Gateway_Stripe::log( 'Webhook error: Invalid signature (checked both mode secrets).', 'error' );
            status_header( 401 );
            exit;
        }

        RPSFW_Gateway_Stripe::log( 'Webhook signature verified (direct path, ' . $verified_mode . ' mode secret).' );

        // Convert Stripe object to array for downstream handlers.
        $event = $event->toArray();
    }

    if ( ! isset( $event['type'] ) ) {
        RPSFW_Gateway_Stripe::log( 'Webhook error: Missing event type', 'error' );
        status_header( 400 );
        exit;
    }

    // The event itself is authoritative about its mode; fall back to whichever
    // secret verified it. Everything downstream — API lookups, refunds, the
    // order-mode guard — keys off this rather than the gateway setting.
    $event_mode = isset( $event['livemode'] )
        ? ( $event['livemode'] ? 'live' : 'test' )
        : $verified_mode;

    rpsfw_current_webhook_mode( $event_mode );
    RPSFW_Stripe_API::set_request_mode( $event_mode );

    if ( $event_mode !== $current_mode ) {
        RPSFW_Gateway_Stripe::log( sprintf(
            'Webhook arrived in %s mode while the gateway is set to %s. Processing it against the %s account.',
            $event_mode,
            $current_mode,
            $event_mode
        ) );
        if ( ! RPSFW_Stripe_API::has_credentials_for_mode( $event_mode ) ) {
            RPSFW_Gateway_Stripe::log( sprintf(
                'No %s mode secret key stored — this event will be applied where it needs no API lookup, and enrichment that requires the API will be skipped.',
                $event_mode
            ), 'warning' );
        }
    }

    RPSFW_Gateway_Stripe::log( 'Processing webhook event: ' . $event['type'] . ' (' . $event_mode . ' mode)' );

    // Process the event
    $result = rpsfw_stripe_process_webhook_event( $event );

    if ( $result ) {
        status_header( 200 );
        echo wp_json_encode( array( 'received' => true ) );
    } else {
        status_header( 500 );
        echo wp_json_encode( array( 'error' => 'Processing failed' ) );
    }

    exit;
}

/**
 * Process webhook event
 *
 * @param array $event Stripe event data
 * @return bool
 */
function rpsfw_stripe_process_webhook_event( $event ) {
    $event_type = $event['type'];
    $event_data = isset( $event['data']['object'] ) ? $event['data']['object'] : array();

    // Ensure payment gateways are instantiated for this request. The Stripe
    // gateway's constructor calls RPSFW_Stripe_Subscriptions::maybe_init(),
    // which registers the customer.subscription.* and invoice.* webhook
    // handlers (via add_action on the rpsfw_stripe_webhook_* hooks below).
    // During a raw wc-api webhook request WooCommerce does not always load
    // gateways, so without this those handlers would never bind and events
    // like customer.subscription.deleted / .updated (cancel, pause) would be
    // silently ignored — even though standalone handlers such as
    // charge.refunded still run. Loading here is idempotent.
    if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
        WC()->payment_gateways()->payment_gateways();
    }

    // Debug: for subscription/invoice events, log whether a handler is actually
    // bound after loading gateways. This pinpoints where an event is lost:
    //   - this line missing entirely  -> event never reached the site
    //   - "handler bound: no"         -> the subscriptions integration didn't load
    //   - "handler bound: yes" + no handler log -> dispatch problem
    //   - handler log "no matching WC subscription" -> meta/lookup problem
    $dynamic_hook = 'rpsfw_stripe_webhook_' . str_replace( '.', '_', $event_type );
    if ( 0 === strpos( $event_type, 'customer.subscription' ) || 0 === strpos( $event_type, 'invoice.' ) ) {
        RPSFW_Gateway_Stripe::log( 'Dispatching ' . $event_type . ' — handler bound: ' . ( has_action( $dynamic_hook ) ? 'yes' : 'no' ) );
    }

    // Fire dynamic action hook for extensibility (allows future modules to hook in)
    do_action( $dynamic_hook, $event_data, $event );

    switch ( $event_type ) {
        case 'charge.succeeded':
            return rpsfw_stripe_handle_charge_succeeded( $event_data );

        case 'charge.failed':
            return rpsfw_stripe_handle_charge_failed( $event_data );

        case 'charge.pending':
            return rpsfw_stripe_handle_charge_pending( $event_data );

        case 'charge.refunded':
            return rpsfw_stripe_handle_charge_refunded( $event_data );

        case 'payment_intent.succeeded':
            return rpsfw_stripe_handle_payment_intent_succeeded( $event_data );

        case 'payment_intent.payment_failed':
            return rpsfw_stripe_handle_payment_intent_failed( $event_data );

        case 'payment_intent.requires_action':
            return rpsfw_stripe_handle_payment_intent_requires_action( $event_data );

        case 'payment_intent.canceled':
            return rpsfw_stripe_handle_payment_intent_canceled( $event_data );

        case 'charge.dispute.created':
            return rpsfw_stripe_handle_dispute_created( $event_data );

        case 'charge.dispute.closed':
            return rpsfw_stripe_handle_dispute_closed( $event_data );

        case 'review.opened':
            return rpsfw_stripe_handle_review_opened( $event_data );

        case 'review.closed':
            return rpsfw_stripe_handle_review_closed( $event_data );

        // invoice.* and customer.subscription.* events are handled by the
        // RPSFW_Stripe_Subscriptions class via the dynamic do_action() above.
        // Falling through here just acknowledges receipt.
        case 'invoice.payment_succeeded':
        case 'invoice.payment_failed':
        case 'invoice.payment_action_required':
        case 'invoice.upcoming':
        case 'invoice.finalized':
        case 'customer.subscription.created':
        case 'customer.subscription.updated':
        case 'customer.subscription.deleted':
        case 'customer.subscription.paused':
        case 'customer.subscription.resumed':
        case 'customer.subscription.trial_will_end':
            return true;

        default:
            RPSFW_Gateway_Stripe::log( 'Unhandled webhook event type: ' . $event_type );
            return true; // Return true to acknowledge receipt
    }
}

/**
 * Get order from payment intent or charge
 *
 * @param array $data Event data
 * @return WC_Order|false
 */
function rpsfw_stripe_get_order_from_event( $data ) {
    $order = rpsfw_stripe_resolve_order_from_event( $data );

    // Cross-mode protection: an order paid in one mode must never be touched by
    // an event from the other. Ids from the two accounts are unrelated, so a
    // match across modes means we resolved the wrong record — or that someone
    // is replaying test events at live orders.
    if ( $order && ! rpsfw_webhook_may_touch_order( $order ) ) {
        RPSFW_Gateway_Stripe::log( sprintf(
            'Refusing %s mode event for order #%d, which was paid in %s mode.',
            rpsfw_current_webhook_mode(),
            $order->get_id(),
            rpsfw_get_order_payment_mode( $order )
        ), 'warning' );
        return false;
    }

    return $order;
}

/**
 * Resolve the WooCommerce order an event refers to, by every id we may have
 * recorded. Callers should use rpsfw_stripe_get_order_from_event(), which adds
 * the cross-mode guard.
 *
 * @param array $data Stripe event object data.
 * @return WC_Order|false
 */
function rpsfw_stripe_resolve_order_from_event( $data ) {
    // 1. Order id stamped in the object's own metadata (order-pay intents, and
    // any object we stamped directly).
    foreach ( array( 'rpsfw_order_id', 'order_id' ) as $meta_order_key ) {
        if ( ! empty( $data['metadata'][ $meta_order_key ] ) ) {
            $order = wc_get_order( absint( $data['metadata'][ $meta_order_key ] ) );
            if ( $order ) {
                return $order;
            }
        }
    }

    // Resolve the PaymentIntent id. On payment_intent.* events the event
    // object IS the PaymentIntent (id = pi_..., with no `payment_intent`
    // field). On charge.* events the charge carries a `payment_intent` field.
    $payment_intent_id = '';
    if ( ! empty( $data['payment_intent'] ) ) {
        $payment_intent_id = is_array( $data['payment_intent'] )
            ? ( isset( $data['payment_intent']['id'] ) ? $data['payment_intent']['id'] : '' )
            : (string) $data['payment_intent'];
    } elseif ( ! empty( $data['id'] ) && 0 === strpos( (string) $data['id'], 'pi_' ) ) {
        $payment_intent_id = (string) $data['id'];
    }

    // 2. By PaymentIntent id.
    if ( $payment_intent_id ) {
        $order = rpsfw_stripe_find_order_by_meta( '_rpsfw_stripe_payment_intent_id', $payment_intent_id );
        if ( $order ) {
            return $order;
        }
    }

    // 2b. By the PaymentIntent's metadata. The charge does not inherit the
    // PaymentIntent's metadata, so on a charge.* event we retrieve the PI and
    // read the order id we stamped there at checkout/finalize. This is the
    // primary, API-version-proof path for subscription charges.
    if ( $payment_intent_id ) {
        $order = rpsfw_stripe_find_order_via_payment_intent_metadata( $payment_intent_id );
        if ( $order ) {
            return $order;
        }
    }

    // Resolve the charge id. On charge.* events the event object IS the charge
    // (id = ch_...). On dispute events the charge is in the `charge` field.
    $charge_id = '';
    if ( ! empty( $data['id'] ) && 0 === strpos( (string) $data['id'], 'ch_' ) ) {
        $charge_id = (string) $data['id'];
    } elseif ( ! empty( $data['charge'] ) ) {
        $charge_id = is_array( $data['charge'] )
            ? ( isset( $data['charge']['id'] ) ? $data['charge']['id'] : '' )
            : (string) $data['charge'];
    }

    // 3. By charge id (regular charge-based payments, recorded on the order).
    if ( $charge_id ) {
        $order = rpsfw_stripe_find_order_by_meta( '_rpsfw_stripe_charge_id', $charge_id );
        if ( $order ) {
            return $order;
        }
    }

    // 4. By WooCommerce transaction id (may hold either a charge or intent id).
    if ( ! empty( $data['id'] ) ) {
        $order = rpsfw_stripe_find_order_by_meta( '_transaction_id', $data['id'] );
        if ( $order ) {
            return $order;
        }
    }
    if ( $payment_intent_id ) {
        $order = rpsfw_stripe_find_order_by_meta( '_transaction_id', $payment_intent_id );
        if ( $order ) {
            return $order;
        }
    }

    // 5. Subscription charges. Subscription orders record the Stripe
    // subscription id (not the per-charge/PI id), so the lookups above miss.
    // Resolve via the charge's invoice instead.
    $invoice_id = '';
    if ( ! empty( $data['invoice'] ) ) {
        $invoice_id = is_array( $data['invoice'] )
            ? ( isset( $data['invoice']['id'] ) ? $data['invoice']['id'] : '' )
            : (string) $data['invoice'];
    }
    if ( $invoice_id ) {
        // 5a. Renewal orders record the paid invoice id as their transaction id.
        $order = rpsfw_stripe_find_order_by_meta( '_transaction_id', $invoice_id );
        if ( $order ) {
            return $order;
        }
        // 5b. Initial invoice: map the invoice -> its subscription -> the WC
        // subscription -> its parent (checkout) order.
        $order = rpsfw_stripe_find_order_via_invoice_subscription( $invoice_id );
        if ( $order ) {
            return $order;
        }
    }

    // 6. Subscription-scoped fallback. The independent multi-schedule flow
    // records each subscription's initial PaymentIntent + charge on the WC
    // subscription (not the order). Match the charge's ids there and return the
    // subscription's parent order.
    if ( $payment_intent_id || $charge_id ) {
        $order = rpsfw_stripe_find_order_via_subscription_meta( $payment_intent_id, $charge_id );
        if ( $order ) {
            return $order;
        }
    }

    return false;
}

/**
 * Resolve a WooCommerce order by reading the order id we stamped into a
 * PaymentIntent's metadata. Used on charge.* events, where the charge does not
 * carry the PaymentIntent's metadata, so the PI must be retrieved.
 *
 * @param string $payment_intent_id PaymentIntent id (pi_...).
 * @return WC_Order|false
 */
function rpsfw_stripe_find_order_via_payment_intent_metadata( $payment_intent_id ) {
    $client = RPSFW_Stripe_API::get_client();
    if ( ! $client || empty( $payment_intent_id ) ) {
        return false;
    }
    try {
        $pi = $client->paymentIntents->retrieve( $payment_intent_id, array() );
    } catch ( \Exception $e ) {
        RPSFW_Gateway_Stripe::log( 'Refund resolve: could not retrieve PaymentIntent ' . $payment_intent_id . ': ' . $e->getMessage(), 'warning' );
        return false;
    }

    $order_id = 0;
    if ( ! empty( $pi->metadata['rpsfw_order_id'] ) ) {
        $order_id = absint( $pi->metadata['rpsfw_order_id'] );
    } elseif ( ! empty( $pi->metadata['order_id'] ) ) {
        $order_id = absint( $pi->metadata['order_id'] );
    }
    if ( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order ) {
            return $order;
        }
    }
    return false;
}

/**
 * Resolve a WooCommerce order from a Stripe PaymentIntent id or Charge id that
 * is recorded on a WC subscription (rather than on an order). Returns the
 * subscription's parent (checkout) order.
 *
 * @param string $payment_intent_id PaymentIntent id (pi_...), may be empty.
 * @param string $charge_id         Charge id (ch_...), may be empty.
 * @return WC_Order|false
 */
function rpsfw_stripe_find_order_via_subscription_meta( $payment_intent_id, $charge_id ) {
    $lookups = array();
    if ( $payment_intent_id ) {
        $lookups[] = array( '_rpsfw_stripe_payment_intent_id', $payment_intent_id );
    }
    if ( $charge_id ) {
        $lookups[] = array( '_rpsfw_stripe_charge_id', $charge_id );
    }

    foreach ( $lookups as $lookup ) {
        $subs = wc_get_orders( array(
            'limit'      => 1,
            'type'       => 'shop_subscription',
            'status'     => 'any',
            'meta_query' => array(
                array(
                    'key'   => $lookup[0],
                    'value' => $lookup[1],
                ),
            ),
        ) );
        if ( ! empty( $subs ) ) {
            $parent = $subs[0]->get_parent();
            if ( $parent ) {
                return $parent;
            }
        }
    }

    return false;
}

/**
 * Resolve a WooCommerce order from a Stripe invoice id by following the
 * invoice to its subscription, then to the WC subscription that stores that
 * subscription id, and finally to that subscription's parent (checkout) order.
 *
 * Used for refund/dispute events on a subscription's initial invoice, whose
 * charge is not recorded directly on any WooCommerce order.
 *
 * @param string $invoice_id Stripe invoice id (in_...).
 * @return WC_Order|false
 */
function rpsfw_stripe_find_order_via_invoice_subscription( $invoice_id ) {
    $client = RPSFW_Stripe_API::get_client();
    if ( ! $client || empty( $invoice_id ) ) {
        return false;
    }

    try {
        $invoice = $client->invoices->retrieve( $invoice_id, array() );
    } catch ( \Exception $e ) {
        RPSFW_Gateway_Stripe::log( 'Refund resolve: could not retrieve invoice ' . $invoice_id . ': ' . $e->getMessage(), 'warning' );
        return false;
    }

    // Subscription id lives at the top level (legacy) or under
    // parent.subscription_details.subscription (basil 2025-03-31+).
    $subscription_id = '';
    if ( ! empty( $invoice->subscription ) ) {
        $subscription_id = is_object( $invoice->subscription ) ? $invoice->subscription->id : (string) $invoice->subscription;
    } elseif ( ! empty( $invoice->parent->subscription_details->subscription ) ) {
        $sub = $invoice->parent->subscription_details->subscription;
        $subscription_id = is_object( $sub ) ? $sub->id : (string) $sub;
    }
    if ( ! $subscription_id ) {
        return false;
    }

    // The WC subscription carries the Stripe subscription id; its parent is the
    // checkout order that captured the initial payment.
    $subs = wc_get_orders( array(
        'limit'      => 1,
        'type'       => 'shop_subscription',
        'status'     => 'any',
        'meta_query' => array(
            array(
                'key'   => '_rpsfw_stripe_subscription_id',
                'value' => $subscription_id,
            ),
        ),
    ) );
    if ( ! empty( $subs ) ) {
        $parent = $subs[0]->get_parent();
        if ( $parent ) {
            return $parent;
        }
    }

    // Fallback: an order that directly carries the subscription id (the parent
    // order records it in both the single-schedule and independent flows).
    $orders = wc_get_orders( array(
        'limit'      => 1,
        'type'       => 'shop_order',
        'status'     => 'any',
        'meta_query' => array(
            array(
                'key'   => '_rpsfw_stripe_subscription_id',
                'value' => $subscription_id,
            ),
        ),
    ) );
    if ( ! empty( $orders ) ) {
        return $orders[0];
    }

    return false;
}

/**
 * Find a single WooCommerce order by a meta key/value.
 *
 * Uses a proper meta_query (not the meta_key/meta_value shorthand, which is
 * not reliably honored by wc_get_orders across storage backends) and status
 * 'any' so authorized/on-hold orders are matched too.
 *
 * @param string $key   Meta key.
 * @param string $value Meta value.
 * @return WC_Order|false
 */
function rpsfw_stripe_find_order_by_meta( $key, $value ) {
    if ( '' === $value ) {
        return false;
    }
    $orders = wc_get_orders( array(
        'limit'      => 1,
        'status'     => 'any',
        'meta_query' => array(
            array(
                'key'   => $key,
                'value' => $value,
            ),
        ),
    ) );
    return ! empty( $orders ) ? $orders[0] : false;
}

/**
 * Handle charge.succeeded event (legacy - for older Charge API)
 *
 * @param array $charge Charge data
 * @return bool
 */
function rpsfw_stripe_handle_charge_succeeded( $charge ) {
    // Skip if this charge belongs to a payment intent
    // The payment_intent.succeeded event will handle it
    if ( ! empty( $charge['payment_intent'] ) ) {
        RPSFW_Gateway_Stripe::log( 'Charge succeeded but belongs to payment intent - skipping (will be handled by payment_intent.succeeded)' );
        return true;
    }

    $order = rpsfw_stripe_get_order_from_event( $charge );

    if ( ! $order ) {
        RPSFW_Gateway_Stripe::log( 'Charge succeeded but order not found: ' . $charge['id'] );
        return true;
    }

    // Verify this is our gateway
    if ( $order->get_payment_method() !== 'rpsfw_stripe' ) {
        RPSFW_Gateway_Stripe::log( 'Charge succeeded for different payment gateway: ' . $order->get_payment_method() );
        return true;
    }

    if ( $order->is_paid() ) {
        RPSFW_Gateway_Stripe::log( 'Order already paid: ' . $order->get_id() );
        return true;
    }

    RPSFW_Gateway_Stripe::log( 'Charge succeeded for order #' . $order->get_id() );

    $order->add_order_note(
        sprintf(
            /* translators: %s: Stripe charge ID. */
            __( 'Stripe charge succeeded via webhook (Charge ID: %s)', 'restore-paypal-standard-for-woocommerce' ),
            $charge['id']
        )
    );

    return true;
}

/**
 * Handle charge.failed event
 *
 * @param array $charge Charge data
 * @return bool
 */
function rpsfw_stripe_handle_charge_failed( $charge ) {
    $order = rpsfw_stripe_get_order_from_event( $charge );

    if ( ! $order ) {
        RPSFW_Gateway_Stripe::log( 'Charge failed but order not found: ' . $charge['id'] );
        return true;
    }

    RPSFW_Gateway_Stripe::log( 'Charge failed for order #' . $order->get_id(), 'error' );

    $failure_message = isset( $charge['failure_message'] ) ? $charge['failure_message'] : __( 'Unknown error', 'restore-paypal-standard-for-woocommerce' );

    $order->update_status(
        'failed',
        sprintf(
            /* translators: %1$s: failure message, %2$s: Stripe charge ID. */
            __( 'Stripe charge failed via webhook: %1$s (Charge ID: %2$s)', 'restore-paypal-standard-for-woocommerce' ),
            $failure_message,
            $charge['id']
        )
    );

    return true;
}

/**
 * Handle charge.refunded event
 *
 * @param array $charge Charge data
 * @return bool
 */
function rpsfw_stripe_handle_charge_refunded( $charge ) {
    $order = rpsfw_stripe_get_order_from_event( $charge );

    if ( ! $order ) {
        RPSFW_Gateway_Stripe::log( sprintf(
            'Charge refunded but order not found: charge=%s, payment_intent=%s, invoice=%s, customer=%s',
            isset( $charge['id'] ) ? $charge['id'] : '(none)',
            isset( $charge['payment_intent'] ) ? ( is_array( $charge['payment_intent'] ) ? wp_json_encode( $charge['payment_intent'] ) : $charge['payment_intent'] ) : '(none)',
            isset( $charge['invoice'] ) ? ( is_array( $charge['invoice'] ) ? wp_json_encode( $charge['invoice'] ) : $charge['invoice'] ) : '(none)',
            isset( $charge['customer'] ) ? ( is_array( $charge['customer'] ) ? wp_json_encode( $charge['customer'] ) : $charge['customer'] ) : '(none)'
        ), 'warning' );
        return true;
    }

    RPSFW_Gateway_Stripe::log( 'Charge refunded for order #' . $order->get_id() );

    $amount_refunded = isset( $charge['amount_refunded'] ) ? $charge['amount_refunded'] : 0;
    $currency        = isset( $charge['currency'] ) ? strtoupper( $charge['currency'] ) : $order->get_currency();
    $charge_id       = isset( $charge['id'] ) ? $charge['id'] : '';

    // Stripe reports the CUMULATIVE amount refunded for THIS charge. A single
    // WooCommerce order can be backed by more than one Stripe charge (the
    // independent multi-schedule flow charges each subscription's first invoice
    // separately under one parent order). Comparing one charge's cumulative to
    // the whole order under-counts once a second charge is refunded, so we keep
    // a per-charge ledger and sum it for the order's true refunded total.
    $this_charge_refunded = (float) RPSFW_Stripe_API::format_stripe_amount( $amount_refunded, $currency );

    $ledger = (array) $order->get_meta( '_rpsfw_stripe_charge_refunds' );
    if ( $charge_id ) {
        $ledger[ $charge_id ] = $this_charge_refunded;
    }
    $stripe_total_refunded = 0.0;
    foreach ( $ledger as $charge_amount ) {
        $stripe_total_refunded += (float) $charge_amount;
    }
    $stripe_total_refunded = round( $stripe_total_refunded, 2 );

    // How much WooCommerce has already recorded as refunded for this order.
    // A refund initiated from WooCommerce already created a WC refund AND hit
    // Stripe, so comparing against the order total also dedupes those. Only
    // record the delta that Stripe knows about but WooCommerce does not yet.
    $wc_already_refunded = (float) $order->get_total_refunded();
    $delta               = round( $stripe_total_refunded - $wc_already_refunded, 2 );

    if ( $delta <= 0 ) {
        // Nothing new to record (e.g. the refund originated in WooCommerce, or
        // another charge's webhook already covered it). Persist the ledger so a
        // later refund on a different charge computes the correct increment.
        if ( $charge_id ) {
            $order->update_meta_data( '_rpsfw_stripe_charge_refunds', $ledger );
            $order->save();
        }
        RPSFW_Gateway_Stripe::log( 'Refund already recorded in WooCommerce for order #' . $order->get_id() . '; skipping.' );
        return true;
    }

    // Create a real WooCommerce refund so the order total, status and refund
    // line all update. refund_payment => false because Stripe has already
    // moved the money — we are only mirroring it into WooCommerce.
    $refund = wc_create_refund( array(
        'amount'         => $delta,
        'reason'         => sprintf(
            /* translators: %s: Stripe charge id */
            __( 'Refunded via Stripe (Charge ID: %s)', 'restore-paypal-standard-for-woocommerce' ),
            $charge['id']
        ),
        'order_id'       => $order->get_id(),
        'refund_payment' => false,
        'restock_items'  => false,
    ) );

    if ( is_wp_error( $refund ) ) {
        RPSFW_Gateway_Stripe::log( 'Failed to create WooCommerce refund from Stripe webhook: ' . $refund->get_error_message(), 'error' );
        // Fall back to at least leaving a note so the merchant is aware.
        $order->add_order_note(
            sprintf(
                /* translators: %s: refunded amount */
                __( 'Stripe reported a refund of %s but the WooCommerce refund could not be created automatically. Please reconcile manually.', 'restore-paypal-standard-for-woocommerce' ),
                wc_price( $delta, array( 'currency' => $currency ) )
            )
        );
        return true;
    }

    // Persist the per-charge ledger so a later refund on another charge of the
    // same order records only its own incremental amount.
    if ( $charge_id ) {
        $order->update_meta_data( '_rpsfw_stripe_charge_refunds', $ledger );
        $order->save();
    }

    $order->add_order_note(
        sprintf(
            /* translators: 1: refunded amount, 2: Stripe charge id */
            __( 'Refund of %1$s synced from Stripe (Charge ID: %2$s).', 'restore-paypal-standard-for-woocommerce' ),
            wc_price( $delta, array( 'currency' => $currency ) ),
            $charge['id']
        )
    );

    return true;
}

/**
 * Handle payment_intent.succeeded event
 *
 * @param array $intent Payment intent data
 * @return bool
 */
function rpsfw_stripe_handle_payment_intent_succeeded( $intent ) {
    $order = rpsfw_stripe_get_order_from_event( $intent );

    if ( ! $order ) {
        RPSFW_Gateway_Stripe::log( 'Payment intent succeeded but order not found: ' . $intent['id'] );
        return true;
    }

    // Re-read the stored status rather than trusting the loaded object.
    // Resolving the order above can involve Stripe round trips (invoice /
    // PaymentIntent lookups), during which the checkout's own finalize call may
    // have completed it — most often on subscription orders, where several
    // events arrive at once. Completing it again would transition the order a
    // second time and re-send the customer "order processing" email.
    if ( ! rpsfw_order_still_needs_payment( $order ) ) {
        RPSFW_Gateway_Stripe::log( 'Order already paid (or completed by another request): ' . $order->get_id() );
        return true;
    }

    RPSFW_Gateway_Stripe::log( 'Payment intent succeeded for order #' . $order->get_id() );

    // Detect a partial capture: the merchant captured less than the authorized
    // amount (the remainder is released back to the customer). Stripe reports
    // the authorized amount in `amount` and the captured amount in
    // `amount_received`. Without handling this, payment_complete() would mark
    // the order fully paid at the order total even though only part was taken.
    $currency        = isset( $intent['currency'] ) ? strtoupper( $intent['currency'] ) : $order->get_currency();
    $amount_auth     = isset( $intent['amount'] ) ? (int) $intent['amount'] : 0;
    $amount_captured = isset( $intent['amount_received'] ) ? (int) $intent['amount_received'] : 0;
    $is_partial      = ( $amount_captured > 0 && $amount_auth > 0 && $amount_captured < $amount_auth );

    if ( $is_partial ) {
        $captured   = (float) RPSFW_Stripe_API::format_stripe_amount( $amount_captured, $currency );
        $authorized = (float) RPSFW_Stripe_API::format_stripe_amount( $amount_auth, $currency );
        $released   = round( $authorized - $captured, 2 );

        // Reflect the amount actually captured as the order total so Woo shows
        // what was really charged (the line items are left intact for record).
        $order->set_total( $captured );
        $order->add_order_note(
            sprintf(
                /* translators: 1: captured amount, 2: authorized amount, 3: released amount, 4: Payment Intent id */
                __( 'Stripe partial capture: %1$s captured of %2$s authorized; %3$s released back to the customer. Order total adjusted to the captured amount. (Payment Intent ID: %4$s)', 'restore-paypal-standard-for-woocommerce' ),
                wc_price( $captured, array( 'currency' => $currency ) ),
                wc_price( $authorized, array( 'currency' => $currency ) ),
                wc_price( $released, array( 'currency' => $currency ) ),
                $intent['id']
            )
        );
    }

    // Mark order as paid (uses the — possibly adjusted — order total).
    $order->payment_complete( $intent['id'] );

    if ( ! $is_partial ) {
        $order->add_order_note(
            sprintf(
                /* translators: %s: Stripe payment intent ID. */
                __( 'Stripe payment completed via webhook (Payment Intent ID: %s)', 'restore-paypal-standard-for-woocommerce' ),
                $intent['id']
            )
        );
    }

    return true;
}

/**
 * Handle payment_intent.payment_failed event
 *
 * @param array $intent Payment intent data
 * @return bool
 */
function rpsfw_stripe_handle_payment_intent_failed( $intent ) {
    $order = rpsfw_stripe_get_order_from_event( $intent );

    if ( ! $order ) {
        RPSFW_Gateway_Stripe::log( 'Payment intent failed but order not found: ' . $intent['id'] );
        return true;
    }

    RPSFW_Gateway_Stripe::log( 'Payment intent failed for order #' . $order->get_id(), 'error' );

    $failure_message = isset( $intent['last_payment_error']['message'] ) 
        ? $intent['last_payment_error']['message'] 
        : __( 'Unknown error', 'restore-paypal-standard-for-woocommerce' );

    $order->update_status(
        'failed',
        sprintf(
            /* translators: %1$s: failure message, %2$s: Stripe payment intent ID. */
            __( 'Stripe payment failed via webhook: %1$s (Payment Intent ID: %2$s)', 'restore-paypal-standard-for-woocommerce' ),
            $failure_message,
            $intent['id']
        )
    );

    return true;
}

/**
 * Handle payment_intent.canceled event
 *
 * Fires when an authorized (uncaptured) PaymentIntent is canceled, either by
 * the merchant from the Stripe dashboard or automatically by Stripe once the
 * authorization expires (~7 days for cards, or longer with extended
 * authorization). A canceled authorization can no longer be captured, so the
 * matching on-hold order is cancelled instead of being left awaiting a capture
 * that can never succeed. Mirrors PayPal's PAYMENT.AUTHORIZATION.VOIDED.
 *
 * @param array $intent Payment intent data
 * @return bool
 */
function rpsfw_stripe_handle_payment_intent_canceled( $intent ) {
    $order = rpsfw_stripe_get_order_from_event( $intent );

    if ( ! $order ) {
        RPSFW_Gateway_Stripe::log( 'Payment intent canceled but order not found: ' . $intent['id'] );
        return true;
    }

    // If the order was already captured/paid, or already cancelled/refunded,
    // there is nothing to do. Canceling an authorization does not reverse a
    // capture.
    if ( $order->is_paid() || $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
        RPSFW_Gateway_Stripe::log( 'Canceled intent for order not awaiting capture; no status change: ' . $order->get_id() );
        return true;
    }

    // Surface Stripe's cancellation reason when present (e.g. "automatic" for
    // an expired authorization vs "requested_by_customer" for a manual void).
    $reason = isset( $intent['cancellation_reason'] ) && $intent['cancellation_reason'] !== ''
        ? $intent['cancellation_reason']
        : __( 'canceled', 'restore-paypal-standard-for-woocommerce' );

    RPSFW_Gateway_Stripe::log( 'Payment intent canceled for order #' . $order->get_id() . ' (reason: ' . $reason . ')' );

    $order->update_status(
        'cancelled',
        sprintf(
            /* translators: %1$s: cancellation reason, %2$s: Stripe payment intent ID. */
            __( 'Stripe authorization was canceled (%1$s) and can no longer be captured. Order cancelled. (Payment Intent ID: %2$s)', 'restore-paypal-standard-for-woocommerce' ),
            $reason,
            $intent['id']
        )
    );

    return true;
}

/**
 * Handle charge.dispute.created event
 *
 * Stores the order's pre-dispute status in order meta so it can be
 * restored if the dispute is later resolved in the merchant's favour.
 * Also writes the order ID and pre-dispute status to the Stripe dispute
 * object's metadata so the closed handler can look them up without an
 * extra database query (mirrors the woo-stripe-payment approach).
 *
 * @param array $dispute Dispute data
 * @return bool
 */
function rpsfw_stripe_handle_dispute_created( $dispute ) {
    $options  = get_option( 'woocommerce_rpsfw_stripe_settings', array() );

    // Check if dispute-created handling is enabled (default yes).
    if ( isset( $options['dispute_created_enabled'] ) && 'yes' !== $options['dispute_created_enabled'] ) {
        RPSFW_Gateway_Stripe::log( 'Dispute created handling is disabled, skipping.' );
        return true;
    }

    $charge_id = isset( $dispute['charge'] ) ? $dispute['charge'] : '';

    if ( empty( $charge_id ) ) {
        RPSFW_Gateway_Stripe::log( 'Dispute created but no charge ID found' );
        return true;
    }

    // Resolve via the comprehensive resolver first (handles subscription
    // charges via PaymentIntent metadata / subscription meta), then fall back
    // to the charge/transaction lookup.
    $order = rpsfw_stripe_get_order_from_event( $dispute );
    if ( ! $order ) {
        $order = rpsfw_stripe_get_order_from_charge_or_transaction( $charge_id );
    }

    if ( ! $order ) {
        RPSFW_Gateway_Stripe::log( 'Dispute created but order not found for charge: ' . $charge_id );
        return true;
    }

    // Verify this is our gateway
    if ( $order->get_payment_method() !== 'rpsfw_stripe' ) {
        RPSFW_Gateway_Stripe::log( 'Dispute created for different payment gateway: ' . $order->get_payment_method() );
        return true;
    }

    $amount   = isset( $dispute['amount'] ) ? $dispute['amount'] : 0;
    $currency = isset( $dispute['currency'] ) ? strtoupper( $dispute['currency'] ) : $order->get_currency();
    $reason   = isset( $dispute['reason'] ) ? $dispute['reason'] : __( 'Unknown', 'restore-paypal-standard-for-woocommerce' );
    $status   = isset( $dispute['status'] ) ? strtoupper( $dispute['status'] ) : '';

    $dispute_amount = RPSFW_Stripe_API::format_stripe_amount( $amount, $currency );

    // Save the pre-dispute status in WP order meta so we can restore it later.
    $pre_dispute_status = $order->get_status();
    $order->update_meta_data( '_rpsfw_stripe_pre_dispute_status', $pre_dispute_status );
    $order->save();

    // Get the configured dispute-created order status (default on-hold).
    $new_status = isset( $options['dispute_created_status'] ) ? $options['dispute_created_status'] : 'wc-on-hold';
    $new_status = str_replace( 'wc-', '', $new_status ); // update_status() takes status without prefix

    $message = sprintf(
        /* translators: 1: charge id 2: dispute status 3: reason 4: amount */
        __( 'A dispute has been created for charge %1$s. Dispute status: %2$s. Reason: %3$s. Amount: %4$s', 'restore-paypal-standard-for-woocommerce' ),
        $charge_id,
        $status,
        $reason,
        wc_price( $dispute_amount, array( 'currency' => $currency ) )
    );

    RPSFW_Gateway_Stripe::log( 'Dispute created for order #' . $order->get_id() . ' (Dispute ID: ' . $dispute['id'] . ')', 'warning' );

    $order->update_status( $new_status, $message );

    // Write the order ID + pre-dispute status to the Stripe dispute object
    // metadata so the closed handler can read them back without a DB lookup,
    // matching the woo-stripe-payment implementation.
    $client = RPSFW_Stripe_API::get_client();
    if ( $client && isset( $dispute['id'] ) ) {
        try {
            $client->disputes->update( $dispute['id'], array(
                'metadata' => array(
                    'order_id'          => (string) $order->get_id(),
                    'prev_order_status' => $pre_dispute_status,
                ),
            ) );
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            // Non-fatal: log and continue. The WP meta fallback is still set.
            RPSFW_Gateway_Stripe::log( 'Could not update dispute metadata: ' . $e->getMessage(), 'warning' );
        }
    }

    return true;
}

/**
 * Handle charge.dispute.closed event
 *
 * Looks up the previous order status from Stripe dispute metadata (set
 * by the created handler) so it can be restored when the merchant wins.
 * Falls back to WP order meta if the Stripe metadata is absent.
 *
 * @param array $dispute Dispute data
 * @return bool
 */
function rpsfw_stripe_handle_dispute_closed( $dispute ) {
    $options = get_option( 'woocommerce_rpsfw_stripe_settings', array() );

    // Check if dispute-closed handling is enabled (default yes).
    if ( isset( $options['dispute_closed_enabled'] ) && 'yes' !== $options['dispute_closed_enabled'] ) {
        RPSFW_Gateway_Stripe::log( 'Dispute closed handling is disabled, skipping.' );
        return true;
    }

    $charge_id = isset( $dispute['charge'] ) ? $dispute['charge'] : '';

    if ( empty( $charge_id ) ) {
        RPSFW_Gateway_Stripe::log( 'Dispute closed but no charge ID found' );
        return true;
    }

    // Primary: try to get the order ID from the Stripe dispute metadata
    // (written there by rpsfw_stripe_handle_dispute_created).
    $order = null;
    $prev_status_from_stripe = '';
    if ( ! empty( $dispute['metadata']['order_id'] ) ) {
        $order = wc_get_order( absint( $dispute['metadata']['order_id'] ) );
        $prev_status_from_stripe = isset( $dispute['metadata']['prev_order_status'] )
            ? $dispute['metadata']['prev_order_status']
            : '';
    }

    // Fallback: comprehensive resolver (handles subscription charges), then
    // the charge/transaction lookup.
    if ( ! $order ) {
        $order = rpsfw_stripe_get_order_from_event( $dispute );
    }
    if ( ! $order ) {
        $order = rpsfw_stripe_get_order_from_charge_or_transaction( $charge_id );
    }

    if ( ! $order ) {
        RPSFW_Gateway_Stripe::log( 'Dispute closed but order not found for charge: ' . $charge_id );
        return true;
    }

    // Verify this is our gateway
    if ( $order->get_payment_method() !== 'rpsfw_stripe' ) {
        RPSFW_Gateway_Stripe::log( 'Dispute closed for different payment gateway: ' . $order->get_payment_method() );
        return true;
    }

    $dispute_status = isset( $dispute['status'] ) ? $dispute['status'] : '';

    // Retrieve previous order status: prefer Stripe metadata, fall back to WP meta.
    $prev_status = $prev_status_from_stripe
        ? $prev_status_from_stripe
        : $order->get_meta( '_rpsfw_stripe_pre_dispute_status' );

    RPSFW_Gateway_Stripe::log( 'Dispute closed for order #' . $order->get_id() . ' - Stripe status: ' . $dispute_status );

    $message = sprintf(
        /* translators: 1: dispute id 2: status */
        __( 'Dispute %1$s has been closed. Result: %2$s.', 'restore-paypal-standard-for-woocommerce' ),
        $dispute['id'],
        $dispute_status
    );

    switch ( $dispute_status ) {
        case 'won':
            // Restore the pre-dispute status; fall back to processing/completed.
            if ( ! empty( $prev_status ) ) {
                $restore_status = $prev_status;
            } else {
                $restore_status = $order->needs_processing() ? 'processing' : 'completed';
            }
            $order->update_status( $restore_status, $message );
            break;

        case 'lost':
            // Get the configured lost status (default failed).
            $lost_status = isset( $options['dispute_closed_lost_status'] ) ? $options['dispute_closed_lost_status'] : 'wc-failed';
            $lost_status = str_replace( 'wc-', '', $lost_status );
            $order->update_status( $lost_status, $message );
            break;

        default:
            // Any other terminal state (needs_response, under_review, etc.) —
            // just add a note without changing the status.
            $order->add_order_note( $message );
            break;
    }

    // Clean up WP order meta.
    $order->delete_meta_data( '_rpsfw_stripe_pre_dispute_status' );
    $order->save();

    return true;
}

/**
 * Find a WooCommerce order by Stripe charge ID.
 *
 * Checks the _rpsfw_stripe_charge_id meta first, then falls back to the
 * order transaction ID (which holds the charge or payment_intent ID on
 * older orders), mirroring woo-stripe-payment's wc_stripe_get_order_from_transaction.
 *
 * @param string $charge_id Stripe charge ID (ch_...).
 * @return WC_Order|false
 */
function rpsfw_stripe_get_order_from_charge_or_transaction( $charge_id ) {
    if ( empty( $charge_id ) ) {
        return false;
    }

    // 1. Check dedicated charge ID meta.
    $orders = wc_get_orders( array(
        'limit'      => 1,
        'meta_key'   => '_rpsfw_stripe_charge_id',
        'meta_value' => $charge_id,
    ) );
    if ( ! empty( $orders ) ) {
        return $orders[0];
    }

    // 2. Check transaction ID (set on the WC order by payment_complete()).
    $orders = wc_get_orders( array(
        'limit'          => 1,
        'transaction_id' => $charge_id,
    ) );
    if ( ! empty( $orders ) ) {
        return $orders[0];
    }

    return false;
}

/**
 * Handle charge.pending event
 *
 * @param array $charge Charge data
 * @return bool
 */
function rpsfw_stripe_handle_charge_pending( $charge ) {
    $order = rpsfw_stripe_get_order_from_event( $charge );

    if ( ! $order ) {
        RPSFW_Gateway_Stripe::log( 'Charge pending but order not found: ' . $charge['id'] );
        return true;
    }

    // Verify this is our gateway
    if ( $order->get_payment_method() !== 'rpsfw_stripe' ) {
        RPSFW_Gateway_Stripe::log( 'Charge pending for different payment gateway: ' . $order->get_payment_method() );
        return true;
    }

    RPSFW_Gateway_Stripe::log( 'Charge pending for order #' . $order->get_id() );

    $order->add_order_note(
        sprintf(
            /* translators: %s: Stripe charge ID. */
            __( 'Stripe charge is pending (Charge ID: %s)', 'restore-paypal-standard-for-woocommerce' ),
            $charge['id']
        )
    );

    return true;
}

/**
 * Handle payment_intent.requires_action event
 *
 * @param array $intent Payment intent data
 * @return bool
 */
function rpsfw_stripe_handle_payment_intent_requires_action( $intent ) {
    $order = rpsfw_stripe_get_order_from_event( $intent );

    if ( ! $order ) {
        RPSFW_Gateway_Stripe::log( 'Payment intent requires action but order not found: ' . $intent['id'] );
        return true;
    }

    // Verify this is our gateway
    if ( $order->get_payment_method() !== 'rpsfw_stripe' ) {
        RPSFW_Gateway_Stripe::log( 'Payment intent requires action for different payment gateway: ' . $order->get_payment_method() );
        return true;
    }

    RPSFW_Gateway_Stripe::log( 'Payment intent requires action for order #' . $order->get_id() );

    $order->add_order_note(
        sprintf(
            /* translators: %s: Stripe payment intent ID. */
            __( 'Payment requires additional action (3D Secure or verification). Payment Intent ID: %s', 'restore-paypal-standard-for-woocommerce' ),
            $intent['id']
        )
    );

    return true;
}

/**
 * Handle review.opened event
 *
 * @param array $review Review data
 * @return bool
 */
function rpsfw_stripe_handle_review_opened( $review ) {
    // Comprehensive resolver handles the review's charge + payment_intent,
    // including subscription charges (via PaymentIntent metadata / sub meta).
    $order = rpsfw_stripe_get_order_from_event( $review );

    if ( ! $order ) {
        RPSFW_Gateway_Stripe::log( 'Review opened but order not found (charge: ' . ( isset( $review['charge'] ) ? $review['charge'] : '(none)' ) . ', payment_intent: ' . ( isset( $review['payment_intent'] ) ? $review['payment_intent'] : '(none)' ) . ')' );
        return true;
    }

    // Verify this is our gateway
    if ( $order->get_payment_method() !== 'rpsfw_stripe' ) {
        RPSFW_Gateway_Stripe::log( 'Review opened for different payment gateway: ' . $order->get_payment_method() );
        return true;
    }

    $reason = isset( $review['reason'] ) ? $review['reason'] : __( 'Unknown', 'restore-paypal-standard-for-woocommerce' );
    
    // Store current status to restore later if review is closed favorably
    $current_status = $order->get_status();
    $order->update_meta_data( '_rpsfw_stripe_pre_review_status', $current_status );
    $order->save();

    RPSFW_Gateway_Stripe::log( 'Review opened for order #' . $order->get_id() . ' - Reason: ' . $reason, 'warning' );

    $order->update_status(
        'on-hold',
        sprintf(
            /* translators: %1$s: review reason, %2$s: Stripe review ID. */
            __( 'Payment is under review by Stripe. Reason: %1$s (Review ID: %2$s)', 'restore-paypal-standard-for-woocommerce' ),
            $reason,
            $review['id']
        )
    );

    return true;
}

/**
 * Handle review.closed event
 *
 * @param array $review Review data
 * @return bool
 */
function rpsfw_stripe_handle_review_closed( $review ) {
    // Comprehensive resolver handles the review's charge + payment_intent,
    // including subscription charges (via PaymentIntent metadata / sub meta).
    $order = rpsfw_stripe_get_order_from_event( $review );

    if ( ! $order ) {
        RPSFW_Gateway_Stripe::log( 'Review closed but order not found (charge: ' . ( isset( $review['charge'] ) ? $review['charge'] : '(none)' ) . ', payment_intent: ' . ( isset( $review['payment_intent'] ) ? $review['payment_intent'] : '(none)' ) . ')' );
        return true;
    }

    // Verify this is our gateway
    if ( $order->get_payment_method() !== 'rpsfw_stripe' ) {
        RPSFW_Gateway_Stripe::log( 'Review closed for different payment gateway: ' . $order->get_payment_method() );
        return true;
    }

    $reason = isset( $review['reason'] ) ? $review['reason'] : __( 'Unknown', 'restore-paypal-standard-for-woocommerce' );
    
    // Get the pre-review status
    $pre_review_status = $order->get_meta( '_rpsfw_stripe_pre_review_status' );
    
    if ( ! empty( $pre_review_status ) ) {
        $order->update_status(
            $pre_review_status,
            sprintf(
                /* translators: %1$s: review reason, %2$s: Stripe review ID. */
                __( 'Review closed. Reason: %1$s (Review ID: %2$s)', 'restore-paypal-standard-for-woocommerce' ),
                $reason,
                $review['id']
            )
        );
    } else {
        // Default to processing/completed
        $status = $order->needs_processing() ? 'processing' : 'completed';
        $order->update_status(
            $status,
            sprintf(
                /* translators: %1$s: review reason, %2$s: Stripe review ID. */
                __( 'Review closed. Reason: %1$s (Review ID: %2$s)', 'restore-paypal-standard-for-woocommerce' ),
                $reason,
                $review['id']
            )
        );
    }

    // Clean up meta
    $order->delete_meta_data( '_rpsfw_stripe_pre_review_status' );
    $order->save();

    RPSFW_Gateway_Stripe::log( 'Review closed for order #' . $order->get_id() . ' - Reason: ' . $reason );

    return true;
}

/**
 * Invoice and customer.subscription.* events are handled by the
 * RPSFW_Stripe_Subscriptions class via the do_action(
 * 'rpsfw_stripe_webhook_<event>', ... ) hook fired in
 * rpsfw_stripe_process_webhook_event(). The legacy stub previously here
 * has been removed.
 */
