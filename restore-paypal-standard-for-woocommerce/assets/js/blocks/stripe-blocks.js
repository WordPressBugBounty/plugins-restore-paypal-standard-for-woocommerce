/**
 * Stripe Blocks Integration
 */
(function() {
    const { registerPaymentMethod, registerExpressPaymentMethod } = window.wc.wcBlocksRegistry;
    const { getSetting } = window.wc.wcSettings;
    const { decodeEntities } = window.wp.htmlEntities;
    const { createElement, useState, useEffect, useRef } = window.wp.element;

    // Add spinner animation CSS
    if (!document.getElementById('rpsfw-stripe-spinner-css')) {
        const style = document.createElement('style');
        style.id = 'rpsfw-stripe-spinner-css';
        style.textContent = `
            @keyframes rpsfw-spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        `;
        document.head.appendChild(style);
    }

    /**
     * Detect the "already confirmed" race and return the underlying intent if
     * it is in a state we consider paid/authenticated. Stripe surfaces this as
     * error.code === 'payment_intent_unexpected_state' (or the setup_intent
     * equivalent) and includes the intent object on the error. Returns the
     * intent object when it is safe to proceed, otherwise null.
     */
    function recoverIntentFromError(error, mode) {
        if (!error) {
            return null;
        }
        if (mode === 'setup') {
            if (error.code === 'setup_intent_unexpected_state' && error.setup_intent && error.setup_intent.status === 'succeeded') {
                return error.setup_intent;
            }
            return null;
        }
        if (error.code === 'payment_intent_unexpected_state' && error.payment_intent) {
            const status = error.payment_intent.status;
            if (status === 'succeeded' || status === 'requires_capture' || status === 'processing') {
                return error.payment_intent;
            }
        }
        return null;
    }

    const settings = getSetting('rpsfw_stripe_data', {});
    const title = decodeEntities(settings.title) || 'Credit Card (Stripe)';
    const description = decodeEntities(settings.description) || '';
    const publishableKey = settings.publishableKey || '';
    const accountId = settings.accountId || '';
    const ajaxUrl = settings.ajaxUrl || '/wp-admin/admin-ajax.php';
    const createIntentNonce = settings.createIntentNonce || '';
    // Subscription support: when the cart is a subscription, route to the
    // dedicated subscription endpoint instead of the one-off intent endpoint.
    const isSubscription = settings.isSubscription === true;
    const createSubscriptionNonce = settings.createSubscriptionNonce || '';
    const finalizeNonce = settings.finalizeNonce || '';
    const iconUrl = settings.iconUrl || '';
    const showTitle = settings.showTitle !== false;
    const showDescription = settings.showDescription !== false;
    const testMode = settings.testMode === true;
    const testModeMessage = decodeEntities(settings.testModeMessage) || '';
    const locale = settings.locale || 'auto';
    const loadingText = settings.loadingText || 'Loading payment form...';
    // Express Checkout wallets (Apple Pay / Google Pay) shown inside the
    // Payment Element, and whether Link is enabled (controls whether we pass
    // the customer email so Link can authenticate/enrol them).
    const walletsConfig = settings.walletsConfig || { applePay: 'never', googlePay: 'never' };
    const linkEnabled = settings.linkEnabled !== false;
    // Whether the Express Checkout Element (Apple Pay / Google Pay buttons)
    // should be offered. True when the merchant enabled at least one wallet.
    const expressCheckoutEnabled = settings.expressCheckoutEnabled === true;
    // Debug totals panel data (test mode + subscription cart only). Present
    // only when the admin enabled "Checkout Debug Totals". This is the initial
    // snapshot taken at page load; the block re-fetches it on cart change (see
    // the intent-creation effect) so the panel stays in sync with the billing.
    const initialDebugTotals = settings.debugTotals || null;

    // Global Stripe instance
    let stripe = null;
    let elements = null;
    let paymentElement = null;
    let paymentIntentId = null;
    let subscriptionId = null;
    // Stripe customer id from the current draft subscription. Sent back on each
    // re-create so the server reuses the same subscription/customer instead of
    // spawning duplicates when the cart total changes mid-checkout.
    let customerId = null;
    // Deferred subscription mount params { mode, amount, currency }, or null.
    // When set, the Payment Element is mounted from these (no clientSecret) and
    // NOTHING is created on Stripe until the shopper submits — the customer and
    // subscription are created in onPaymentSetup (on click). Scoped to
    // charge-today single-subscription carts (mode 'subscription'); $0 trials,
    // multi-schedule and one-off carts stay on the create-on-load flow.
    let deferredMount = null;

    /**
     * Build a Stripe billing_details object from the block billing data.
     */
    function buildBillingDetails(billing) {
        const bd = (billing && billing.billingData) ? billing.billingData : {};
        return {
            name: ((bd.first_name || '') + ' ' + (bd.last_name || '')).trim(),
            email: bd.email,
            phone: bd.phone,
            address: {
                line1: bd.address_1,
                line2: bd.address_2,
                city: bd.city,
                state: bd.state,
                postal_code: bd.postcode,
                country: bd.country
            }
        };
    }

    /**
     * POST an admin-ajax action (urlencoded) and resolve to the parsed JSON.
     */
    function postJson(params) {
        return fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(params),
        }).then(response => response.json());
    }

    /**
     * Mark an order paid server-side after the customer confirmed the payment
     * (deferred / order-first flow). Resolves to true on success. If it fails
     * after a successful charge, the Stripe webhook reconciles the order, so
     * callers may still let the checkout complete.
     */
    function finalizeOrderBlock(orderId, orderKey, intentId) {
        return fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'rpsfw_stripe_finalize_order',
                nonce: finalizeNonce,
                order_id: orderId || '',
                order_key: orderKey || '',
                payment_intent_id: intentId || '',
                rpsfw_stripe_payment_intent_id: intentId || '',
                rpsfw_stripe_subscription_id: subscriptionId || ''
            })
        })
        .then(function(r) { return r.json(); })
        .then(function(res) { return !!(res && res.success); })
        .catch(function() { return false; });
    }

    const Label = (props) => {
        const { PaymentMethodLabel } = props.components;
        
        // If title is hidden, show nothing or just icon
        if (!showTitle) {
            if (iconUrl) {
                return createElement('img', {
                    src: iconUrl,
                    alt: 'Stripe',
                    className: 'wc-block-components-payment-method-icon',
                    style: { maxHeight: '28px', width: 'auto', verticalAlign: 'middle' }
                });
            }
            return null;
        }
        
        if (iconUrl) {
            return createElement(
                'span',
                { className: 'wc-block-components-payment-method-label wc-block-components-payment-method-label--with-icon' },
                createElement('img', {
                    src: iconUrl,
                    alt: title,
                    className: 'wc-block-components-payment-method-icon',
                    style: { maxHeight: '28px', width: 'auto', verticalAlign: 'middle', marginRight: '8px' }
                }),
                createElement('span', null, title)
            );
        }
        
        return createElement(PaymentMethodLabel, { text: title });
    };

    const Content = (props) => {
        const { eventRegistration, emitResponse, billing } = props;
        const { onPaymentSetup, onCheckoutSuccess, onCheckoutFail } = eventRegistration;
        const [isReady, setIsReady] = useState(false);
        const [error, setError] = useState('');
        // Debug panel totals. Initialized from the page-load snapshot, then
        // refreshed via AJAX whenever the cart changes so the "Due today /
        // Recurring" summary matches what Stripe will actually bill.
        const [debugTotals, setDebugTotals] = useState(initialDebugTotals);
        const containerRef = useRef(null);
        const elementsCreated = useRef(false);
        // The billing email the Payment Element was last built with. Link reads
        // the email from defaultValues at creation time, so when the shopper's
        // email changes we bump remountKey to re-create the Element with it —
        // that's what lets Link recognise a returning customer, with no separate
        // Stripe email field.
        const lastMountedEmailRef = useRef('');
        const [remountKey, setRemountKey] = useState(0);
        const clientSecretRef = useRef(null);
        const isMountedRef = useRef(true);
        // Confirmation mode returned by the server: 'payment' for a normal
        // PaymentIntent (one-off or paid subscription first invoice) or 'setup'
        // for a SetupIntent ($0 free-trial subscription with no amount today).
        const intentModeRef = useRef('payment');

        // Cart total (in minor units). We re-create the intent/subscription
        // whenever this changes (e.g. a coupon added/removed) so the amount
        // Stripe charges — and, for subscriptions, the price it bills — always
        // matches the current cart. The stale draft subscription is cancelled
        // server-side when the new one is created.
        const cartTotalValue = ( billing && billing.cartTotal ) ? billing.cartTotal.value : null;

        // Track mount state (used by async callbacks) independently of the
        // intent-creation effect, which re-runs on cart changes.
        useEffect(() => {
            isMountedRef.current = true;
            return () => { isMountedRef.current = false; };
        }, []);

        // Initialize Stripe and create payment intent
        useEffect(() => {
            let cancelled = false;

            if (!publishableKey) {
                setError('Stripe is not properly configured. Please reconnect your Stripe account.');
                return;
            }

            if (typeof Stripe === 'undefined') {
                setError('Stripe.js failed to load');
                return;
            }

            if (!stripe) {
                try {
                    // Initialize with connected account if using Stripe Connect
                    const stripeOptions = accountId ? { stripeAccount: accountId } : {};
                    stripe = Stripe(publishableKey, stripeOptions);
                } catch (e) {
                    setError('Failed to initialize Stripe: ' + e.message);
                    return;
                }
            }

            // Reset so the payment element re-mounts with the fresh client
            // secret. Setting isReady=false triggers the element effect's
            // cleanup, which unmounts the previous element.
            clientSecretRef.current = null;
            setIsReady(false);
            setError('');

            // Determine the CURRENT subscription status before creating the
            // intent. The `isSubscription` flag is captured at page load and
            // goes stale when the cart changes — e.g. the shopper removes the
            // last recurring item, making the cart one-off. Using the stale
            // flag would call the subscription endpoint for a non-subscription
            // cart and fail with "No subscription in cart" (leaving the form
            // stuck on the loading spinner). So we re-check on every cart change
            // and branch to the matching endpoint.
            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'rpsfw_stripe_cart_is_subscription',
                    nonce: createIntentNonce,
                }),
            })
            .then(response => response.json())
            .then(statusData => {
                if (cancelled || !isMountedRef.current) {
                    return Promise.reject('superseded');
                }
                const isSubNow = !!(
                    statusData && statusData.success && statusData.data && statusData.data.is_subscription
                );

                // One-off cart: existing PaymentIntent path (no customer, no
                // deferral needed).
                if (!isSubNow) {
                    deferredMount = null;
                    return postJson({
                        action: 'rpsfw_stripe_create_payment_intent',
                        nonce: createIntentNonce,
                    });
                }

                // Subscription cart: is it deferred-eligible? get_mount_params
                // returns { mode:'subscription', amount, currency } ONLY for a
                // charge-today SINGLE-subscription cart. It returns null (or a
                // 'setup' mode we reject below) for $0 trials, multi-schedule,
                // and non-subscription carts — those stay on create-on-load.
                return postJson({
                    action: 'rpsfw_stripe_get_mount_params',
                    nonce: createIntentNonce,
                }).then(mp => {
                    const mount = (mp && mp.success && mp.data) ? mp.data.mount : null;
                    if (mount && mount.mode === 'subscription') {
                        // Deferred: create NOTHING now. Signal the final handler
                        // to mount the Element from { mode, amount, currency }.
                        return { __deferred: true, mount: mount };
                    }
                    // Not deferred-eligible: create the subscription now, as before.
                    deferredMount = null;
                    return postJson({
                        action: 'rpsfw_stripe_create_subscription',
                        nonce: createSubscriptionNonce,
                        // Send back the draft we already have so the server
                        // reuses it (same cart) or cancels + replaces it on the
                        // same customer (cart changed) — no orphaned duplicates.
                        existing_subscription_id: subscriptionId || '',
                        existing_customer_id: customerId || '',
                    });
                });
            })
            .then(data => {
                // Ignore if this effect run was superseded (cart changed again)
                // or the component unmounted.
                if (cancelled || !isMountedRef.current) return;

                // Deferred: mount from { mode, amount, currency }; the customer +
                // subscription are created later, in onPaymentSetup (on click).
                if (data && data.__deferred) {
                    deferredMount = data.mount;
                    clientSecretRef.current = null;
                    paymentIntentId = null;
                    subscriptionId = null;
                    intentModeRef.current = 'payment';
                    setIsReady(true);
                    return;
                }

                if (data.success && data.data.client_secret) {
                    deferredMount = null;
                    clientSecretRef.current = data.data.client_secret;
                    paymentIntentId = data.data.payment_intent_id || null;
                    subscriptionId = data.data.subscription_id || null;
                    customerId = data.data.customer_id || customerId || null;
                    intentModeRef.current = data.data.mode || 'payment';
                    setIsReady(true);
                } else {
                    // The existing-account-email check is enforced at Place
                    // Order time by the Store API validation hook
                    // (validate_store_api_account), which surfaces the notice
                    // at the top of the checkout. Mount-time init errors here
                    // stay in the payment area.
                    setError((data.data && data.data.message) || 'Failed to create payment intent');
                }
            })
            .catch(e => {
                if (cancelled || !isMountedRef.current || e === 'superseded') return;
                setError('Failed to initialize payment: ' + (e && e.message ? e.message : e));
            });

            return () => {
                cancelled = true;
            };
        }, [cartTotalValue]);

        // Refresh the debug totals panel on cart change (test mode dev aid).
        // Only runs when the panel is active (initial snapshot present). Kept
        // separate from the intent-creation effect so a debug-fetch failure
        // never blocks payment setup.
        useEffect(() => {
            if (!initialDebugTotals) {
                return;
            }
            let cancelled = false;
            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'rpsfw_stripe_get_debug_totals',
                    nonce: createIntentNonce,
                }),
            })
            .then(response => response.json())
            .then(data => {
                if (cancelled || !isMountedRef.current) return;
                if (data.success && typeof data.data.debugTotals !== 'undefined') {
                    setDebugTotals(data.data.debugTotals);
                }
            })
            .catch(() => { /* debug panel is non-critical; ignore */ });
            return () => { cancelled = true; };
        }, [cartTotalValue]);

        // Create payment element
        useEffect(() => {
            // Mount when ready. The original flow needs a clientSecret; the
            // deferred subscription flow mounts from { mode, amount, currency }
            // with no clientSecret yet.
            if (!isReady || !containerRef.current || elementsCreated.current || (!clientSecretRef.current && !deferredMount)) {
                return;
            }

            const appearance = settings.appearance || { theme: 'stripe' };

            const elementsBase = { appearance: appearance, locale: locale };
            if (deferredMount) {
                // Deferred: mount from { mode, amount, currency }; no intent
                // exists yet. `amount` is omitted in setup mode.
                elementsBase.mode = deferredMount.mode;
                elementsBase.currency = deferredMount.currency;
                if (deferredMount.mode !== 'setup') {
                    elementsBase.amount = deferredMount.amount;
                }
                // Explicit method list (card + Link when enabled) so Link is
                // offered on subscription carts, matching the subscription's
                // payment_settings server-side.
                if (deferredMount.payment_method_types && deferredMount.payment_method_types.length > 0) {
                    elementsBase.paymentMethodTypes = deferredMount.payment_method_types;
                }
            } else {
                elementsBase.clientSecret = clientSecretRef.current;
            }

            elements = stripe.elements(elementsBase);

            // Stripe Link integration: pass the shopper's billing details to the
            // Payment Element so Link can authenticate/enrol them and offer
            // "save your info for faster checkout" (Stripe's recommended Link
            // integration). Only include fields that have a value.
            const bd = ( billing && billing.billingData ) ? billing.billingData : {};
            const linkBillingDetails = {};
            const bdName = ( ( bd.first_name || '' ) + ' ' + ( bd.last_name || '' ) ).trim();
            // Only pass the email (which drives Link enrolment/authentication)
            // when Link is enabled in settings. Name/phone are harmless and help
            // wallet prefill.
            if ( bd.email && linkEnabled ) { linkBillingDetails.email = bd.email; }
            if ( bdName ) { linkBillingDetails.name = bdName; }
            if ( bd.phone ) { linkBillingDetails.phone = bd.phone; }
            const paymentElementOptions = {};
            if ( Object.keys( linkBillingDetails ).length > 0 ) {
                paymentElementOptions.defaultValues = { billingDetails: linkBillingDetails };
            }
            // Apple Pay / Google Pay are rendered by the dedicated Express
            // Checkout Element (registered separately below), which works in all
            // browsers. Disable the Payment Element's inline wallet buttons so
            // the wallets don't appear twice.
            paymentElementOptions.wallets = { applePay: 'never', googlePay: 'never' };

            paymentElement = elements.create('payment', paymentElementOptions);
            paymentElement.mount(containerRef.current);
            elementsCreated.current = true;

            // Remember the email this Element was built with, so an email change
            // triggers exactly one re-create (see the email-watch effect below).
            lastMountedEmailRef.current = ( bd.email || '' );

            paymentElement.on('loaderror', function(event) {
                if (window.console) {
                    console.error('[RPSFW Stripe Block] Payment Element loaderror:', event && event.error ? event.error : event);
                }
            });

            paymentElement.on('change', function(event) {
                if (!isMountedRef.current) return;
                if (event.complete) {
                    setError('');
                }
            });

            return () => {
                if (paymentElement) {
                    try {
                        paymentElement.unmount();
                    } catch (e) {
                        // Ignore unmount errors
                    }
                    paymentElement = null;
                    elementsCreated.current = false;
                }
            };
        }, [isReady, remountKey]);

        // Re-create the Payment Element when the shopper's email changes, so
        // Link can recognise a returning customer from it (Link reads the email
        // from the Element's defaultValues, applied only at creation). Debounced
        // so typing doesn't thrash, and gated to fire once per distinct email.
        useEffect(() => {
            if (linkEnabled === false) {
                return;
            }
            const email = ( billing && billing.billingData && billing.billingData.email ) ? billing.billingData.email : '';
            if (!email || email === lastMountedEmailRef.current) {
                return;
            }
            const t = setTimeout(() => {
                if (!isMountedRef.current) return;
                // Bump remountKey -> the element effect re-runs and re-reads the
                // email into defaultValues.
                setRemountKey(k => k + 1);
            }, 500);
            return () => clearTimeout(t);
        }, [ ( billing && billing.billingData ) ? billing.billingData.email : '' ]);

        // Deferred flow: create the Stripe customer + subscription NOW (on
        // click, after the card is validated) and capture its clientSecret.
        // Resolves { ok: true } or { error }. Called from onPaymentSetup so
        // nothing exists on Stripe until the shopper actually submits.
        const createDeferredSubscriptionBlock = async () => {
            try {
                const data = await postJson({
                    action: 'rpsfw_stripe_create_subscription',
                    nonce: createSubscriptionNonce,
                    // On a retry, reuse the draft already created rather than
                    // minting a duplicate (guest sessions aren't reliable here).
                    existing_subscription_id: subscriptionId || '',
                    existing_customer_id: customerId || '',
                });
                if (data && data.success && data.data && data.data.client_secret) {
                    clientSecretRef.current = data.data.client_secret;
                    subscriptionId = data.data.subscription_id || null;
                    paymentIntentId = data.data.payment_intent_id || null;
                    customerId = data.data.customer_id || customerId || null;
                    intentModeRef.current = data.data.mode || 'payment';
                    return { ok: true };
                }
                return { error: (data && data.data && data.data.message) || 'Could not start the subscription. Please try again.' };
            } catch (e) {
                return { error: (e && e.message) ? e.message : 'Could not start the subscription. Please try again.' };
            }
        };

        // Payment setup handler. Runs BEFORE the block sends the checkout to
        // the server (i.e. before the order is created).
        useEffect(() => {
            const unsubscribe = onPaymentSetup(async () => {
                if (!paymentElement || !elements) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: 'Payment element not initialized',
                    };
                }

                // PAYMENT mode uses the deferred (order-first) flow: do NOT
                // charge here. Hand the intent/subscription references to the
                // server so process_payment can attach them to the order it is
                // about to create, then confirm in onCheckoutSuccess once the
                // order exists and WooCommerce validation has passed. This is
                // what prevents a charge before checkout validation (e.g. an
                // "email already registered" failure).
                //
                // Deferral requires the onCheckoutSuccess event (WC Blocks). On
                // very old versions without it we fall back to confirming here.
                const canDefer = (typeof onCheckoutSuccess === 'function');
                if (intentModeRef.current !== 'setup' && canDefer) {
                    // Validate the Payment Element fields up front — here in the
                    // payment phase, where WooCommerce Blocks reliably shows the
                    // message — so an incomplete card shows Stripe's own
                    // "Your card number is incomplete." rather than a generic
                    // error raised after the order is created. elements.submit()
                    // validates and collects the fields WITHOUT charging; the
                    // actual charge still happens later in onCheckoutSuccess,
                    // after WooCommerce validation (e.g. the email-already-
                    // registered check), so nothing is charged prematurely.
                    if (typeof elements.submit === 'function') {
                        try {
                            const submitResult = await elements.submit();
                            if (submitResult && submitResult.error && submitResult.error.type === 'validation_error') {
                                return {
                                    type: emitResponse.responseTypes.ERROR,
                                    message: submitResult.error.message,
                                    messageContext: emitResponse.noticeContexts.PAYMENTS,
                                };
                            }
                        } catch (e) {
                            // elements.submit() may be unsupported for this
                            // integration mode — fall through and let the later
                            // confirmation surface any card error.
                        }
                    }

                    // Deferred: now that the card passed validation, create the
                    // customer + subscription (on click) so nothing was created
                    // on Stripe until this moment. Sets subscriptionId +
                    // clientSecretRef used by the SUCCESS meta and onCheckoutSuccess.
                    if (deferredMount) {
                        const created = await createDeferredSubscriptionBlock();
                        if (created.error) {
                            return {
                                type: emitResponse.responseTypes.ERROR,
                                message: created.error,
                                messageContext: emitResponse.noticeContexts.PAYMENTS,
                            };
                        }
                    }

                    return {
                        type: emitResponse.responseTypes.SUCCESS,
                        meta: {
                            paymentMethodData: {
                                rpsfw_stripe_payment_intent_id: paymentIntentId || '',
                                rpsfw_stripe_subscription_id: subscriptionId || ''
                            }
                        }
                    };
                }

                // SETUP mode (free trial / card-save) — and the payment-mode
                // fallback on old WC — confirm here. Setup mode charges nothing
                // now, so confirming before order creation is harmless.
                try {
                    const isSetup = (intentModeRef.current === 'setup');
                    const billingDetails = buildBillingDetails(billing);

                    let result = await stripe[ isSetup ? 'confirmSetup' : 'confirmPayment' ]({
                        elements: elements,
                        confirmParams: {
                            payment_method_data: {
                                billing_details: billingDetails
                            }
                        },
                        redirect: 'if_required'
                    });

                    if (result.error) {
                        // Recover from a duplicate confirmation whose intent was
                        // already confirmed (see recoverIntentFromError).
                        const recovered = recoverIntentFromError(result.error, isSetup ? 'setup' : 'payment');
                        if (recovered) {
                            result = isSetup ? { setupIntent: recovered } : { paymentIntent: recovered };
                        } else {
                            return {
                                type: emitResponse.responseTypes.ERROR,
                                message: result.error.message,
                            };
                        }
                    }

                    if (isSetup) {
                        if (result.setupIntent && result.setupIntent.status === 'succeeded') {
                            return {
                                type: emitResponse.responseTypes.SUCCESS,
                                meta: {
                                    paymentMethodData: {
                                        rpsfw_stripe_setup_intent_id: result.setupIntent.id,
                                    },
                                },
                            };
                        }
                        return {
                            type: emitResponse.responseTypes.ERROR,
                            message: 'Payment could not be completed. Please try again.',
                        };
                    }

                    if (result.paymentIntent && (result.paymentIntent.status === 'succeeded' || result.paymentIntent.status === 'requires_capture' || result.paymentIntent.status === 'processing')) {
                        return {
                            type: emitResponse.responseTypes.SUCCESS,
                            meta: {
                                paymentMethodData: {
                                    rpsfw_stripe_payment_intent_id: result.paymentIntent.id,
                                },
                            },
                        };
                    }

                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: 'Payment could not be completed. Please try again.',
                    };

                } catch (e) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: e.message || 'Payment processing failed',
                    };
                }
            });

            return unsubscribe;
        }, [onPaymentSetup, onCheckoutSuccess, emitResponse.responseTypes, billing]);

        // Post-order confirmation handler (deferred / order-first flow). Runs
        // AFTER the server created + validated the order and process_payment
        // returned the rpsfw_stripe_confirm marker. We confirm the PaymentIntent
        // here — so the card is only charged once the order exists — then mark
        // the order paid. Returning an error moves the checkout to the failed
        // state and keeps the customer on the page.
        useEffect(() => {
            if (typeof onCheckoutSuccess !== 'function') {
                return;
            }
            const unsubscribe = onCheckoutSuccess(async (args) => {
                const processingResponse = args && args.processingResponse;
                const details = (processingResponse && processingResponse.paymentDetails) || {};

                // Only the deferred payment flow needs client-side confirmation
                // here. Setup-mode and already-completed flows fall through.
                if (!details.rpsfw_stripe_confirm) {
                    return true;
                }

                if (!stripe || !elements) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: 'Payment could not be completed. Please refresh the page and try again.',
                    };
                }

                const returnUrl = window.location.origin + window.location.pathname + '?rpsfw_stripe_return=1';

                const confirmOptions = {
                    elements: elements,
                    confirmParams: {
                        return_url: returnUrl,
                        payment_method_data: {
                            billing_details: buildBillingDetails(billing)
                        }
                    },
                    redirect: 'if_required'
                };
                // Deferred: the Element was mounted without a clientSecret, so
                // confirmPayment must be given the intent's clientSecret (created
                // in onPaymentSetup) explicitly. The original flow bakes it into
                // the Element, so we must NOT pass it there.
                if (deferredMount && clientSecretRef.current) {
                    confirmOptions.clientSecret = clientSecretRef.current;
                }

                let result = await stripe.confirmPayment(confirmOptions);

                const recovered = result.error ? recoverIntentFromError(result.error, 'payment') : null;
                if (result.error && !recovered) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: result.error.message,
                    };
                }

                const pi = recovered || result.paymentIntent;
                if (!pi || !(pi.status === 'succeeded' || pi.status === 'requires_capture' || pi.status === 'processing')) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: 'Payment could not be completed. Please try again.',
                    };
                }

                // Mark the order paid server-side. If this fails after a
                // successful charge, the Stripe webhook reconciles the order, so
                // we still let the checkout complete and redirect.
                await finalizeOrderBlock(details.rpsfw_stripe_order_id, details.rpsfw_stripe_order_key, pi.id);
                return true;
            });

            return unsubscribe;
        }, [onCheckoutSuccess, emitResponse.responseTypes, billing]);

        return createElement(
            'div',
            { className: 'wc-block-components-stripe-payment' },
            showDescription && description && createElement('p', { 
                dangerouslySetInnerHTML: { __html: description } 
            }),
            // Always show the test mode notice when in test mode, even if the
            // description is hidden via the "Show Description" setting.
            testMode && testModeMessage && createElement('p', {
                className: 'rpsfw-stripe-test-mode-notice',
                dangerouslySetInnerHTML: { __html: testModeMessage }
            }),
            // Subscription details panel (test mode only) — mirrors the classic
            // checkout "Due today / Recurring" summary for subscription carts.
            debugTotals && createElement('div', {
                className: 'rpsfw-stripe-debug-totals',
                style: {
                    background: '#fff3cd',
                    border: '1px solid #ffc107',
                    borderLeft: '4px solid #ffc107',
                    padding: '10px 14px',
                    marginBottom: '12px',
                    fontSize: '13px',
                    borderRadius: '3px'
                }
            },
                createElement('strong', { style: { display: 'block', marginBottom: '4px' } }, 'Subscription details (test mode)'),
                createElement('div', {
                    dangerouslySetInnerHTML: { __html: '<strong>Due today:</strong> ' + debugTotals.dueToday }
                }),
                (debugTotals.recurringLines || []).map(function (line, i) {
                    return createElement('div', {
                        key: 'rpsfw-rec-' + i,
                        dangerouslySetInnerHTML: { __html: '<strong>Renews:</strong> ' + line }
                    });
                })
            ),
            createElement('div', {
                ref: containerRef,
                id: 'stripe-payment-element-blocks',
                className: 'rpsfw-stripe-element-container',
                style: {
                    marginTop: '10px',
                    minHeight: '200px',
                    position: 'relative'
                }
            }, 
                // Show loading spinner until the Element can mount: not ready, or
                // (in the original flow) no client secret yet. In the deferred
                // flow there is intentionally no client secret at mount time, so
                // readiness alone gates the spinner.
                (!isReady || (!clientSecretRef.current && !deferredMount)) && createElement('div', {
                    className: 'rpsfw-stripe-loading',
                    style: {
                        display: 'flex',
                        flexDirection: 'column',
                        alignItems: 'center',
                        justifyContent: 'center',
                        minHeight: '200px',
                        gap: '12px'
                    }
                },
                    createElement('div', {
                        className: 'rpsfw-stripe-spinner',
                        style: {
                            width: '40px',
                            height: '40px',
                            border: '4px solid #f3f3f3',
                            borderTop: '4px solid #635bff',
                            borderRadius: '50%',
                            animation: 'rpsfw-spin 1s linear infinite'
                        }
                    }),
                    createElement('span', {
                        style: {
                            color: '#666',
                            fontSize: '14px'
                        }
                    }, loadingText)
                )
            ),
            error && createElement('div', {
                className: 'wc-block-components-validation-error',
                role: 'alert',
                style: { color: '#fa755a', marginTop: '10px' }
            }, error)
        );
    };

    registerPaymentMethod({
        name: 'rpsfw_stripe',
        label: createElement(Label),
        content: createElement(Content),
        edit: createElement(Content),
        canMakePayment: () => !!publishableKey,
        ariaLabel: title,
        // Custom Place Order button text for subscription carts (empty for
        // regular carts, which keeps the default label).
        placeOrderButtonLabel: settings.placeOrderButtonLabel || undefined,
        supports: {
            features: settings.supports || ['products'],
        },
    });

    /**
     * Express Checkout Element (Apple Pay / Google Pay) for the block checkout.
     *
     * Registered as a dedicated express payment method so it renders in the
     * block's express-payment area (top of checkout) and works across all
     * modern browsers — this is the same element used on classic checkout, so
     * wallet behaviour is consistent everywhere.
     *
     * Flow: mount the element in Stripe "deferred" mode (amount from the cart),
     * and on wallet confirmation create the PaymentIntent for the live cart
     * total, confirm it, then hand the intent id to the server via
     * onPaymentSetup and submit the checkout with props.onSubmit().
     */
    const ExpressCheckoutContent = (props) => {
        const { onClick, onClose, billing, shippingData, setExpressPaymentError, eventRegistration, emitResponse } = props;
        const containerRef = useRef(null);
        const stripeRef = useRef(null);
        const elementsRef = useRef(null);
        const eceRef = useRef(null);
        const confirmedIntentRef = useRef(null);

        // cartTotal.value is in the currency's minor unit (e.g. cents) and may
        // arrive as a string, so coerce to an integer for the Elements amount.
        const amount = ( billing && billing.cartTotal && billing.cartTotal.value )
            ? ( parseInt(billing.cartTotal.value, 10) || 0 )
            : 0;
        const currencyCode = ( billing && billing.currency && billing.currency.code ) ? billing.currency.code : 'USD';

        // Hand the confirmed PaymentIntent id to the server during the block's
        // checkout processing. The gateway's process_payment() reads it from
        // the posted payment method data and verifies the intent server-side.
        useEffect(() => {
            const unsubscribe = eventRegistration.onPaymentSetup(() => {
                if (confirmedIntentRef.current) {
                    return {
                        type: emitResponse.responseTypes.SUCCESS,
                        meta: {
                            paymentMethodData: {
                                rpsfw_stripe_payment_intent_id: confirmedIntentRef.current,
                            },
                        },
                    };
                }
                return {
                    type: emitResponse.responseTypes.ERROR,
                    message: 'The express payment did not complete. Please try again.',
                };
            });
            return unsubscribe;
        }, [eventRegistration, emitResponse]);

        // Initialize Stripe and mount the Express Checkout Element (deferred).
        useEffect(() => {
            if (typeof Stripe === 'undefined' || !publishableKey || amount <= 0) {
                return;
            }
            if (!stripeRef.current) {
                try {
                    stripeRef.current = Stripe(publishableKey, accountId ? { stripeAccount: accountId } : {});
                } catch (e) {
                    return;
                }
            }
            const stripe = stripeRef.current;

            let elements;
            try {
                elements = stripe.elements({
                    mode: 'payment',
                    amount: amount,
                    currency: currencyCode.toLowerCase(),
                    appearance: settings.appearance || { theme: 'stripe' },
                });
            } catch (e) {
                return;
            }
            elementsRef.current = elements;

            const ece = elements.create('expressCheckout', {
                buttonHeight: 44,
                paymentMethods: {
                    applePay: ( walletsConfig.applePay === 'never' ) ? 'never' : 'auto',
                    googlePay: ( walletsConfig.googlePay === 'never' ) ? 'never' : 'auto',
                    link: 'never',
                    amazonPay: 'never',
                    paypal: 'never',
                },
            });

            ece.on('ready', (event) => {
                // Hide the area entirely if no wallet is available on this device.
                if (!event.availablePaymentMethods && containerRef.current) {
                    containerRef.current.style.display = 'none';
                }
            });

            ece.on('click', (event) => {
                onClick();
                const options = { emailRequired: true };
                if (shippingData && shippingData.needsShipping) {
                    options.shippingAddressRequired = true;
                    options.shippingRates = mapShippingRates(shippingData.shippingRates);
                }
                event.resolve(options);
            });

            ece.on('cancel', () => {
                onClose();
            });

            // Accept address/rate changes; the authoritative total is validated
            // server-side when the PaymentIntent is created at confirm time.
            ece.on('shippingaddresschange', (event) => { event.resolve({}); });
            ece.on('shippingratechange', (event) => { event.resolve({}); });

            ece.on('confirm', (event) => { handleConfirm(event); });

            try {
                ece.mount(containerRef.current);
            } catch (e) {
                if (containerRef.current) {
                    containerRef.current.style.display = 'none';
                }
            }
            eceRef.current = ece;

            return () => {
                try { ece.unmount(); } catch (e) {}
                eceRef.current = null;
                elementsRef.current = null;
            };
        }, [amount, currencyCode]);

        // Map WooCommerce block shipping rates to the Express Checkout Element
        // shape. ECE requires at least one rate when a shipping address is
        // required, so fall back to a zero-cost placeholder if none are loaded.
        function mapShippingRates(rates) {
            const out = [];
            try {
                (rates || []).forEach((pkg) => {
                    ((pkg && pkg.shipping_rates) || []).forEach((r) => {
                        out.push({
                            id: r.rate_id,
                            displayName: r.name,
                            amount: parseInt(r.price, 10) || 0,
                        });
                    });
                });
            } catch (e) { /* ignore */ }
            if (out.length === 0) {
                out.push({ id: 'default', displayName: 'Shipping', amount: 0 });
            }
            return out;
        }

        // Push the wallet-supplied addresses into the checkout so the created
        // order and the server cart total match what the shopper approved.
        function applyAddresses(event) {
            try {
                const bd = event.billingDetails || {};
                const bdAddr = bd.address || {};
                const nameParts = (bd.name || '').trim().split(' ');
                const first = nameParts.shift() || '';
                const last = nameParts.join(' ') || first;

                const wpData = window.wp && window.wp.data;
                if (wpData && wpData.dispatch && bd.email) {
                    wpData.dispatch('wc/store/cart').setBillingAddress({
                        first_name: first,
                        last_name: last,
                        email: bd.email,
                        phone: bd.phone || '',
                        address_1: bdAddr.line1 || '',
                        address_2: bdAddr.line2 || '',
                        city: bdAddr.city || '',
                        state: bdAddr.state || '',
                        postcode: bdAddr.postal_code || '',
                        country: bdAddr.country || '',
                    });
                }

                const ship = event.shippingAddress || {};
                const shipAddr = ship.address || {};
                if (shippingData && shippingData.needsShipping && shipAddr.line1 && shippingData.setShippingAddress) {
                    const shipNameParts = (ship.name || bd.name || '').trim().split(' ');
                    shippingData.setShippingAddress({
                        first_name: shipNameParts.shift() || first,
                        last_name: shipNameParts.join(' ') || last,
                        address_1: shipAddr.line1 || '',
                        address_2: shipAddr.line2 || '',
                        city: shipAddr.city || '',
                        state: shipAddr.state || '',
                        postcode: shipAddr.postal_code || '',
                        country: shipAddr.country || '',
                    });
                }
            } catch (e) { /* non-fatal */ }
        }

        function createExpressIntent() {
            return fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'rpsfw_stripe_create_payment_intent',
                    nonce: createIntentNonce,
                }),
            })
            .then((r) => r.json())
            .then((json) => (json && json.success) ? json.data : null)
            .catch(() => null);
        }

        async function handleConfirm(event) {
            const stripe = stripeRef.current;
            const elements = elementsRef.current;
            if (!stripe || !elements) {
                return;
            }
            try {
                applyAddresses(event);

                const data = await createExpressIntent();
                if (!data || !data.client_secret) {
                    setExpressPaymentError('Could not initialize the payment. Please try again.');
                    return;
                }

                let result = await stripe.confirmPayment({
                    elements: elements,
                    clientSecret: data.client_secret,
                    confirmParams: {},
                    redirect: 'if_required',
                });

                if (result.error) {
                    // Recover from a duplicate confirmation whose intent already
                    // succeeded; otherwise surface the error.
                    const recovered = recoverIntentFromError(result.error, 'payment');
                    if (recovered) {
                        result = { paymentIntent: recovered };
                    } else {
                        setExpressPaymentError(result.error.message);
                        return;
                    }
                }

                const pi = result.paymentIntent;
                if (pi && (pi.status === 'succeeded' || pi.status === 'requires_capture' || pi.status === 'processing')) {
                    confirmedIntentRef.current = pi.id;
                    props.onSubmit();
                } else {
                    setExpressPaymentError('Payment could not be completed. Please try again.');
                }
            } catch (e) {
                setExpressPaymentError(e.message || 'Express payment failed.');
            }
        }

        return createElement('div', {
            ref: containerRef,
            className: 'rpsfw-stripe-express-container-blocks',
        });
    };

    // Probe whether a wallet (Apple Pay / Google Pay) is actually available in
    // this browser/device before telling the block to show the express area.
    // Without this, registering the method always renders the block's "Express
    // Checkout" heading even when no wallet is available — leaving an empty
    // section. We create a throwaway Express Checkout Element off-screen and
    // read its `ready` event's availablePaymentMethods. The result is cached so
    // the probe runs once per page. Returns a Promise<boolean>.
    let expressProbePromise = null;
    function isExpressWalletAvailable(args) {
        if (expressProbePromise) {
            return expressProbePromise;
        }
        expressProbePromise = new Promise((resolve) => {
            try {
                if (typeof Stripe === 'undefined' || !publishableKey) {
                    resolve(false);
                    return;
                }
                const probeStripe = Stripe(publishableKey, accountId ? { stripeAccount: accountId } : {});

                let probeAmount = 100;
                let probeCurrency = 'usd';
                try {
                    if (args && args.cartTotals) {
                        probeAmount = parseInt(args.cartTotals.total_price, 10) || 100;
                        probeCurrency = (args.cartTotals.currency_code || 'USD').toLowerCase();
                    }
                } catch (e) { /* use defaults */ }
                if (probeAmount < 1) {
                    probeAmount = 100;
                }

                const probeElements = probeStripe.elements({
                    mode: 'payment',
                    amount: probeAmount,
                    currency: probeCurrency,
                });
                const probe = probeElements.create('expressCheckout', {
                    paymentMethods: {
                        applePay: ( walletsConfig.applePay === 'never' ) ? 'never' : 'auto',
                        googlePay: ( walletsConfig.googlePay === 'never' ) ? 'never' : 'auto',
                        link: 'never',
                        amazonPay: 'never',
                        paypal: 'never',
                    },
                });

                // Mount off-screen (not display:none — some elements skip init
                // when hidden that way) so the `ready` event fires.
                const tmp = document.createElement('div');
                tmp.style.position = 'absolute';
                tmp.style.left = '-9999px';
                tmp.style.top = '0';
                tmp.style.width = '300px';
                document.body.appendChild(tmp);

                let settled = false;
                const cleanup = () => {
                    try { probe.unmount(); } catch (e) {}
                    try { document.body.removeChild(tmp); } catch (e) {}
                };

                probe.on('ready', (event) => {
                    if (settled) { return; }
                    settled = true;
                    const available = !!( event && event.availablePaymentMethods );
                    cleanup();
                    resolve(available);
                });

                probe.mount(tmp);

                // Safety timeout: if `ready` never fires, assume unavailable.
                setTimeout(() => {
                    if (!settled) {
                        settled = true;
                        cleanup();
                        resolve(false);
                    }
                }, 4000);
            } catch (e) {
                resolve(false);
            }
        });
        return expressProbePromise;
    }

    // Register the express buttons only when a wallet is enabled, the Blocks
    // express API is available, and the cart isn't a subscription (wallet
    // express for subscriptions is out of scope here). A separate registration
    // keeps it isolated: if anything here fails, the card payment method above
    // is unaffected.
    if (expressCheckoutEnabled
        && !isSubscription
        && typeof registerExpressPaymentMethod === 'function'
        && ( walletsConfig.applePay !== 'never' || walletsConfig.googlePay !== 'never' )) {
        registerExpressPaymentMethod({
            name: 'rpsfw_stripe_express',
            title: 'Stripe — Apple Pay / Google Pay',
            description: description,
            gatewayId: 'rpsfw_stripe',
            content: createElement(ExpressCheckoutContent),
            edit: createElement(ExpressCheckoutContent),
            // Show the express area only when (a) the cart doesn't need shipping
            // (digital/virtual — avoids the wallet-shipping amount-mismatch
            // edge) and (b) a wallet is actually available on this device. The
            // availability probe prevents an empty "Express Checkout" section.
            canMakePayment: (args) => {
                if (!publishableKey || ( args && args.cartNeedsShipping )) {
                    return false;
                }
                return isExpressWalletAvailable(args);
            },
            supports: {
                features: settings.supports || ['products'],
            },
        });
    }
})();
