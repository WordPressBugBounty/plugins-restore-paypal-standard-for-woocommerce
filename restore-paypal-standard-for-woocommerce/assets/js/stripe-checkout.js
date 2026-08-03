/**
 * Stripe Checkout JavaScript
 *
 * Handles two flows:
 *  - One-off carts: create a PaymentIntent for cart->total and confirm.
 *  - Subscription carts: create a Stripe Subscription server-side, then
 *    confirm its first invoice's PaymentIntent (or its
 *    pending_setup_intent for free trial without sign-up fee). Stripe
 *    Billing then drives renewals, dunning, and SCA recovery from there.
 */
(function($) {
    'use strict';

    var stripe = null;
    var elements = null;
    var paymentElement = null;
    var expressCheckoutElement = null;
    // The billing email the Payment Element was last created with. Link reads
    // the email from defaultValues at creation time, so when the shopper fills
    // in the WooCommerce email field we re-create the Element with the new
    // email (see remountForEmail) — that's what makes Link recognise a
    // returning customer without any separate Stripe email field.
    var lastMountedEmail = '';
    var clientSecret = null;
    var paymentIntentId = null;
    var setupIntentId = null;
    var subscriptionId = null;
    var intentMode = 'payment'; // 'payment' or 'setup'
    var isProcessing = false;
    var isCreatingIntent = false;
    // True once the payment/setup has been confirmed in the browser. In the
    // deferred (order-first) flow this stays false until AFTER the order is
    // created; the confirm-first paths (order-pay, express wallets, setup-mode
    // trials) set it before re-submitting so the checkout handler lets WC
    // through.
    var paymentConfirmed = false;

    function isSubscriptionCart() {
        return !!(rpsfwStripeParams && rpsfwStripeParams.is_subscription);
    }

    function isChangePaymentMethod() {
        return !!(rpsfwStripeParams && rpsfwStripeParams.is_change_payment_method);
    }

    /**
     * Whether this checkout uses the DEFERRED subscription flow: the Payment
     * Element is mounted with { mode, amount, currency } and NOTHING is created
     * on Stripe (customer/subscription) until the pay button is clicked. This
     * is what keeps abandoned checkouts from minting blank Stripe customers and
     * lets the customer be created named, with final billing details.
     *
     * Scoped deliberately to the main checkout, a real charge-today
     * subscription cart (deferred_mount.mode === 'subscription'). $0 free
     * trials (mode 'setup'), one-off carts, change-payment-method and the
     * order-pay page all keep the original clientSecret-first flow untouched.
     */
    function usesDeferredSub() {
        var dm = rpsfwStripeParams && rpsfwStripeParams.deferred_mount;
        return !!dm
            && dm.mode === 'subscription'
            && isSubscriptionCart()
            && !isChangePaymentMethod()
            && getOrderId() === 0;
    }

    /**
     * Initialize Stripe
     */
    function initStripe() {
        if (!rpsfwStripeParams.publishable_key) {
            return false;
        }

        try {
            // Initialize Stripe with platform key and connected account
            var stripeOptions = {};
            if (rpsfwStripeParams.account_id) {
                stripeOptions.stripeAccount = rpsfwStripeParams.account_id;
            }
            stripe = Stripe(rpsfwStripeParams.publishable_key, stripeOptions);
            
            return true;
        } catch (error) {
            return false;
        }
    }

    /**
     * Create payment element
     */
    function createPaymentElement() {
        // Check if container exists and is visible
        var container = $('#stripe-payment-element');
        if (container.length === 0 || !container.is(':visible')) {
            return;
        }

        // Check if iframe already exists - if so, element is already mounted
        if (container.find('iframe').length > 0) {
            return;
        }

        // Need a client secret to mount — EXCEPT in the deferred subscription
        // flow, where the Element is mounted from { mode, amount, currency }
        // and nothing is created on Stripe until the pay button is clicked.
        if (!usesDeferredSub() && !clientSecret) {
            // Show loading spinner if not already shown
            if (container.find('.rpsfw-stripe-loading').length === 0) {
                var loadingText = rpsfwStripeParams.loading_text || 'Loading payment form...';
                container.html(
                    '<div class="rpsfw-stripe-loading">' +
                    '<div class="rpsfw-stripe-spinner"></div>' +
                    '<span>' + loadingText + '</span>' +
                    '</div>'
                );
            }
            
            // Create the right kind of intent if not already creating
            if (!isCreatingIntent) {
                if (isChangePaymentMethod()) {
                    createSetupIntentForChangePM();
                } else if (isSubscriptionCart()) {
                    createSubscription();
                } else {
                    createPaymentIntent();
                }
            }
            return;
        }

        // Destroy existing element if it exists
        if (paymentElement) {
            try {
                paymentElement.unmount();
                paymentElement.destroy();
            } catch(e) {
                // Ignore errors
            }
            paymentElement = null;
        }

        try {
            var appearance = rpsfwStripeParams.appearance || { theme: 'stripe' };
            var elementsOptions = {
                appearance: appearance,
                locale: rpsfwStripeParams.locale || 'auto'
            };

            if (usesDeferredSub()) {
                // Deferred: mount from { mode, amount, currency }; no intent
                // exists yet. `amount` is only meaningful for a charge
                // (mode 'subscription') and is omitted in setup mode.
                var dm = rpsfwStripeParams.deferred_mount;
                elementsOptions.mode = dm.mode;
                elementsOptions.currency = dm.currency;
                if (dm.mode !== 'setup') {
                    elementsOptions.amount = dm.amount;
                }
                // Explicit method list (card + Link when enabled) so Link is
                // offered on subscription carts, matching the subscription's
                // payment_settings server-side.
                if (dm.payment_method_types && dm.payment_method_types.length > 0) {
                    elementsOptions.paymentMethodTypes = dm.payment_method_types;
                }
            } else {
                // Original flow: the intent's clientSecret drives the Element.
                elementsOptions.clientSecret = clientSecret;
            }

            // Add payment method order if specified
            if (rpsfwStripeParams.payment_method_order && rpsfwStripeParams.payment_method_order.length > 0) {
                elementsOptions.paymentMethodOrder = rpsfwStripeParams.payment_method_order;
            }

            elements = stripe.elements(elementsOptions);
            
            var paymentElementOptions = {};

            // Apple Pay / Google Pay are rendered by the dedicated Express
            // Checkout Element (works in all browsers), so switch OFF the
            // Payment Element's inline wallet buttons to avoid showing the
            // wallets twice.
            paymentElementOptions.wallets = { applePay: 'never', googlePay: 'never' };

            // Stripe Link integration: pass the customer's billing details to
            // the Payment Element so Link can authenticate/enrol the shopper and
            // offer "save your info for faster checkout" consistently (this is
            // Stripe's recommended Link integration). defaultValues only applies
            // at element creation; the element is re-created on updated_checkout,
            // and Link's prefill tool watches the email field for changes typed
            // afterward. Only include fields that have a value so we never send
            // empty defaults.
            var rpsfwBillingDetails = {};
            var rpsfwEmail = ( $('#billing_email').val() || '' ).trim();
            var rpsfwName  = ( ( $('#billing_first_name').val() || '' ) + ' ' + ( $('#billing_last_name').val() || '' ) ).trim();
            var rpsfwPhone = ( $('#billing_phone').val() || '' ).trim();
            // Only pass the email (which drives Link) when Link is enabled.
            if ( rpsfwEmail && rpsfwStripeParams.link_enabled !== false ) { rpsfwBillingDetails.email = rpsfwEmail; }
            if ( rpsfwName ) { rpsfwBillingDetails.name = rpsfwName; }
            if ( rpsfwPhone ) { rpsfwBillingDetails.phone = rpsfwPhone; }
            if ( Object.keys( rpsfwBillingDetails ).length > 0 ) {
                paymentElementOptions.defaultValues = { billingDetails: rpsfwBillingDetails };
            }

            paymentElement = elements.create('payment', paymentElementOptions);
            paymentElement.mount('#stripe-payment-element');

            // Remember the email this Element was built with, so a later change
            // to the WooCommerce email field triggers exactly one re-create.
            lastMountedEmail = rpsfwEmail;

            paymentElement.on('loaderror', function(event) {
                if (window.console) {
                    console.error('[RPSFW Stripe Classic] Payment Element loaderror:', event && event.error ? event.error : event);
                }
            });

            // Hide loading spinner when element is ready
            paymentElement.on('ready', function() {
                container.find('.rpsfw-stripe-loading').remove();
            });

            // Handle real-time validation errors
            paymentElement.on('change', function(event) {
                if (event.complete) {
                    displayError('');
                }
            });

            // Mount the Express Checkout Element (Apple Pay / Google Pay one-tap
            // buttons) on the same Elements instance, above the card form.
            maybeMountExpressCheckout();
        } catch(error) {
            console.error('Error creating payment element:', error);
            container.html('<span style="color: #df1b41;">Failed to load payment form. Please refresh the page.</span>');
        }
    }

    /**
     * Re-create the Payment Element when the WooCommerce email field changes, so
     * Link picks up the newly-entered email. Link reads the email from the
     * Payment Element's defaultValues, which is only applied at creation time —
     * and the Element usually mounts before the shopper has typed their email —
     * so we re-create it once the email is known. Guarded to fire at most once
     * per distinct email, only when Link is enabled and we're not mid-submit.
     */
    function remountForEmail() {
        if (rpsfwStripeParams.link_enabled === false || isProcessing) {
            return;
        }
        var email = ($('#billing_email').val() || '').trim();
        if (!email || email === lastMountedEmail) {
            return;
        }
        var container = $('#stripe-payment-element');
        if (container.length === 0 || !container.is(':visible')) {
            return;
        }
        // Tear the current Element down so createPaymentElement() re-mounts it
        // (it early-returns while an iframe is still present). The new Element
        // re-reads #billing_email into defaultValues, which is what Link uses.
        if (paymentElement) {
            try {
                paymentElement.unmount();
                paymentElement.destroy();
            } catch (e) {
                // Ignore
            }
            paymentElement = null;
        }
        createPaymentElement();
    }

    /**
     * Mount Stripe's Express Checkout Element (Apple Pay / Google Pay). This is
     * a dedicated element with broader browser reach than the Payment Element's
     * inline wallet buttons (e.g. Google Pay can appear in Firefox). It shares
     * the same Elements instance / PaymentIntent as the card form, so a wallet
     * confirmation pays the same intent. On success we populate the checkout
     * fields from the wallet's contact details and submit the order.
     */
    function maybeMountExpressCheckout() {
        if (!rpsfwStripeParams.express_checkout_enabled || !elements) {
            return;
        }
        // Wallet express checkout is one-off only for now. Subscription and
        // change-payment-method flows use a different confirmation path
        // (SetupIntent / subscription first invoice), so skip express there —
        // this keeps parity with the block checkout, which also excludes it.
        if (isSubscriptionCart() || isChangePaymentMethod()) {
            return;
        }
        var mount = document.getElementById('stripe-express-checkout-element');
        if (!mount || expressCheckoutElement) {
            return;
        }

        try {
            var wc = rpsfwStripeParams.wallets_config || {};
            expressCheckoutElement = elements.create('expressCheckout', {
                buttonHeight: 44,
                // Only offer the wallets the merchant enabled. Link stays in the
                // card form (Payment Element), not the express buttons.
                paymentMethods: {
                    applePay: ( wc.applePay === 'never' ) ? 'never' : 'auto',
                    googlePay: ( wc.googlePay === 'never' ) ? 'never' : 'auto',
                    link: 'never',
                    amazonPay: 'never',
                    paypal: 'never'
                }
            });

            // Hide the container entirely if no wallet is available so we don't
            // render an empty gap above the card form.
            expressCheckoutElement.on('ready', function(event) {
                var available = event && event.availablePaymentMethods;
                if (!available) {
                    mount.style.display = 'none';
                } else {
                    mount.style.display = '';
                }
            });

            expressCheckoutElement.on('confirm', function(event) {
                handleExpressConfirm(event);
            });

            expressCheckoutElement.mount('#stripe-express-checkout-element');
        } catch (e) {
            if (window.console) {
                console.error('[RPSFW Stripe Classic] Express Checkout Element error:', e);
            }
            mount.style.display = 'none';
        }
    }

    /**
     * Handle an Express Checkout (wallet) confirmation. The wallet supplies the
     * shopper's contact/billing (and shipping) details; we copy them into the
     * WooCommerce checkout fields so order creation validates, confirm the
     * PaymentIntent, then submit the checkout form to place the order.
     */
    function handleExpressConfirm(event) {
        populateCheckoutFieldsFromWallet(event);

        var returnUrl = window.location.origin + window.location.pathname + '?rpsfw_stripe_return=1';

        stripe.confirmPayment({
            elements: elements,
            confirmParams: { return_url: returnUrl },
            redirect: 'if_required'
        }).then(function(result) {
            if (result.error) {
                displayError(result.error.message);
                return;
            }
            isProcessing = true;
            var form = $('form.checkout, form#order_review');
            handleConfirmResult(result, form, intentMode === 'setup' ? 'setup' : 'payment');
        });
    }

    /**
     * Copy wallet-provided contact details into the classic checkout fields so
     * WooCommerce can create the order. Only fills a field when the wallet
     * provided a value and the field exists. Country/state selects are updated
     * with a change trigger so WooCommerce's dependent fields refresh.
     */
    function populateCheckoutFieldsFromWallet(event) {
        function setVal(selector, value) {
            var el = $(selector);
            if (el.length && value) {
                el.val(value).trigger('change');
            }
        }

        try {
            var bd = event.billingDetails || {};
            var name = (bd.name || '').trim();
            var nameParts = name.split(' ');
            var first = nameParts.shift() || '';
            var last = nameParts.join(' ') || first;

            setVal('#billing_first_name', first);
            setVal('#billing_last_name', last);
            setVal('#billing_email', bd.email);
            setVal('#billing_phone', bd.phone);

            var addr = bd.address || {};
            setVal('#billing_country', addr.country);
            setVal('#billing_address_1', addr.line1);
            setVal('#billing_address_2', addr.line2);
            setVal('#billing_city', addr.city);
            setVal('#billing_state', addr.state);
            setVal('#billing_postcode', addr.postal_code);

            // Shipping (only when the wallet collected an address).
            var ship = event.shippingAddress || {};
            var shipAddr = ship.address || {};
            if (shipAddr.line1) {
                var shipName = (ship.name || name || '').trim();
                var shipParts = shipName.split(' ');
                setVal('#shipping_first_name', shipParts.shift() || first);
                setVal('#shipping_last_name', shipParts.join(' ') || last);
                setVal('#shipping_country', shipAddr.country);
                setVal('#shipping_address_1', shipAddr.line1);
                setVal('#shipping_address_2', shipAddr.line2);
                setVal('#shipping_city', shipAddr.city);
                setVal('#shipping_state', shipAddr.state);
                setVal('#shipping_postcode', shipAddr.postal_code);
            }
        } catch (e) {
            if (window.console) {
                console.warn('[RPSFW Stripe Classic] Could not populate checkout fields from wallet:', e);
            }
        }
    }

    /**
     * Display error message
     */
    function displayError(message) {
        var errorDiv = $('#stripe-payment-errors');
        if (message) {
            errorDiv.text(message).show();
        } else {
            errorDiv.text('').hide();
        }
    }

    /**
     * Create payment intent (one-off carts)
     */
    function createPaymentIntent() {
        if (isCreatingIntent) {
            return;
        }
        
        isCreatingIntent = true;
        
        var data = {
            action: 'rpsfw_stripe_create_payment_intent',
            nonce: rpsfwStripeParams.create_intent_nonce
        };

        // Add order ID if on pay for order page
        var orderId = getOrderId();
        if (orderId) {
            data.order_id = orderId;
        }

        $.ajax({
            url: rpsfwStripeParams.ajax_url,
            type: 'POST',
            data: data
        }).done(function(response) {
            if (response.success && response.data.client_secret) {
                clientSecret = response.data.client_secret;
                paymentIntentId = response.data.payment_intent_id;
                intentMode = 'payment';

                // Now create the payment element
                createPaymentElement();
            } else {
                displayError(response.data && response.data.message ? response.data.message : 'Failed to initialize payment');
            }
        }).fail(function() {
            displayError('Failed to initialize payment. Please try again.');
        }).always(function() {
            isCreatingIntent = false;
        });
    }

    /**
     * Create a Stripe Subscription server-side and use its first
     * invoice's PaymentIntent (or pending_setup_intent for $0 trials)
     * for the Payment Element.
     */
    function createSubscription() {
        if (isCreatingIntent) {
            return;
        }
        isCreatingIntent = true;

        $.ajax({
            url: rpsfwStripeParams.ajax_url,
            type: 'POST',
            data: {
                action: 'rpsfw_stripe_create_subscription',
                nonce: rpsfwStripeParams.create_subscription_nonce
            }
        }).done(function(response) {
            if (response.success && response.data.client_secret) {
                clientSecret = response.data.client_secret;
                subscriptionId = response.data.subscription_id;
                paymentIntentId = response.data.payment_intent_id || null;
                setupIntentId = response.data.setup_intent_id || null;
                intentMode = response.data.mode || 'payment';

                createPaymentElement();
            } else {
                displayError(response.data && response.data.message ? response.data.message : 'Failed to initialize subscription');
            }
        }).fail(function() {
            displayError('Failed to initialize subscription. Please try again.');
        }).always(function() {
            isCreatingIntent = false;
        });
    }

    /**
     * Deferred subscription pay-now, step 1: validate + collect the card with
     * elements.submit() (required first in deferred mode), then create the
     * subscription. Called from handleCheckoutPlaceOrder when usesDeferredSub().
     */
    function submitDeferredSubscription() {
        elements.submit().then(function(res) {
            if (res && res.error) {
                showCheckoutError('<div class="woocommerce-error">' + escapeHtml(res.error.message) + '</div>');
                resetProcessing();
                return;
            }
            createDeferredSubscription();
        }).catch(function() {
            showCheckoutError('<div class="woocommerce-error">' + genericPaymentErrorText() + '</div>');
            resetProcessing();
        });
    }

    /**
     * Deferred subscription pay-now, step 2: create the Stripe customer +
     * subscription NOW (on click, so the customer is born with final billing
     * details), then run the SAME order-first submit -> confirm -> finalize
     * chain the original flow uses. The only difference from createSubscription()
     * is that this does NOT re-mount the Element (it is already mounted in
     * deferred mode); it just captures the clientSecret and proceeds.
     */
    function createDeferredSubscription() {
        $.ajax({
            url: rpsfwStripeParams.ajax_url,
            type: 'POST',
            data: {
                action: 'rpsfw_stripe_create_subscription',
                nonce: rpsfwStripeParams.create_subscription_nonce,
                // On a retry (previous attempt failed), send back the draft we
                // already created so the server reuses/replaces it on the same
                // customer instead of minting a duplicate — matters for guests,
                // whose session pointer isn't reliable across admin-ajax calls.
                existing_subscription_id: subscriptionId || ''
            }
        }).done(function(response) {
            if (response.success && response.data.client_secret) {
                clientSecret = response.data.client_secret;
                subscriptionId = response.data.subscription_id;
                paymentIntentId = response.data.payment_intent_id || null;
                setupIntentId = response.data.setup_intent_id || null;
                intentMode = response.data.mode || 'payment';
                submitCheckoutDeferred();
            } else {
                var msg = (response.data && response.data.message) ? response.data.message : genericPaymentErrorText();
                showCheckoutError('<div class="woocommerce-error">' + escapeHtml(msg) + '</div>');
                resetProcessing();
            }
        }).fail(function() {
            showCheckoutError('<div class="woocommerce-error">' + genericPaymentErrorText() + '</div>');
            resetProcessing();
        });
    }

    /**
     * Create a SetupIntent for the change-payment-method flow on My
     * Account → Subscriptions → Change Payment.
     */
    function createSetupIntentForChangePM() {
        if (isCreatingIntent) {
            return;
        }
        isCreatingIntent = true;

        $.ajax({
            url: rpsfwStripeParams.ajax_url,
            type: 'POST',
            data: {
                action: 'rpsfw_stripe_create_setup_intent',
                nonce: rpsfwStripeParams.create_setup_intent_nonce,
                subscription_id: rpsfwStripeParams.change_payment_subscription_id || 0
            }
        }).done(function(response) {
            if (response.success && response.data.client_secret) {
                clientSecret = response.data.client_secret;
                setupIntentId = response.data.setup_intent_id || null;
                paymentIntentId = null;
                subscriptionId = null;
                intentMode = 'setup';

                createPaymentElement();
            } else {
                displayError(response.data && response.data.message ? response.data.message : 'Failed to initialize setup');
            }
        }).fail(function() {
            displayError('Failed to initialize setup. Please try again.');
        }).always(function() {
            isCreatingIntent = false;
        });
    }

    /**
     * Get order ID from checkout form or pay for order page
     */
    function getOrderId() {
        // Check if we're on pay for order page
        var urlParams = new URLSearchParams(window.location.search);
        var orderId = urlParams.get('order-pay');
        
        if (orderId) {
            return orderId;
        }

        // Check if order ID is in form (after order creation)
        var orderIdInput = $('input[name="order_id"]');
        if (orderIdInput.length) {
            return orderIdInput.val();
        }

        return 0;
    }

    /**
     * Handle form submission
     */
    function handleFormSubmit(e) {
        // Only handle if Stripe is selected
        if ($('input[name="payment_method"]:checked').val() !== 'rpsfw_stripe') {
            return true;
        }

        // If a prior confirm already populated the form with subscription_id,
        // setup_intent_id (change-pm) or payment_intent_id, allow the submit to
        // proceed so WooCommerce runs its normal checkout. This MUST be checked
        // before the isProcessing guard below: after confirmIntent succeeds we
        // re-submit the form, and isProcessing is still true at that point — if
        // the guard ran first it would abort the re-submit and the order would
        // never be created (form left blocked with no order).
        if ($('input[name="rpsfw_stripe_subscription_id"]').length || $('input[name="rpsfw_stripe_payment_intent_id"]').length || $('input[name="rpsfw_stripe_setup_intent_id"]').length) {
            return true;
        }

        // Prevent duplicate confirmations while one is already in flight.
        if (isProcessing) {
            e.preventDefault();
            return false;
        }

        e.preventDefault();
        isProcessing = true;

        // Show a spinner on the Place Order button instead of blocking the
        // whole form with WooCommerce's centered overlay (which appears
        // mid-page and is easy to miss on a long checkout). This matches the
        // block checkout's button-side spinner. WooCommerce still shows its
        // native overlay during the final server submission after we re-submit.
        var form = $('form.checkout, form#order_review');
        setPlaceOrderLoading(true);

        // Confirm the payment / setup with Stripe
        confirmIntent(form);

        return false;
    }

    /**
     * Handler for the main checkout form's `checkout_place_order_rpsfw_stripe`
     * event. Returning true lets WooCommerce run its normal submit; returning
     * false aborts it so we can drive the flow ourselves.
     *
     * PAYMENT mode uses the deferred (order-first) flow: we submit the checkout
     * ourselves so WooCommerce validates and CREATES the order first, and only
     * then confirm the PaymentIntent in the browser. This guarantees the card
     * is never charged before checkout validation (e.g. "email already
     * registered") passes.
     *
     * SETUP mode (free trials / card-save) charges nothing up front, so it
     * keeps the simpler confirm-then-submit behavior. Express wallets and the
     * order-pay page also confirm first and set paymentConfirmed.
     */
    function handleCheckoutPlaceOrder(e) {
        if ($('input[name="payment_method"]:checked').val() !== 'rpsfw_stripe') {
            return true;
        }

        // Already confirmed in the browser (express wallet / setup-mode
        // re-submit): let WooCommerce submit normally.
        if (paymentConfirmed || $('input[name="rpsfw_stripe_setup_intent_id"]').length) {
            return true;
        }

        // Deferred subscription flow: nothing is created on Stripe yet.
        // Validate the card, THEN create the customer + subscription, THEN run
        // the same order-first confirm/finalize chain. Checked before the
        // clientSecret guard below because clientSecret is intentionally null
        // here until the subscription is created on click.
        if (usesDeferredSub()) {
            if (isProcessing) {
                return false;
            }
            if (!elements) {
                displayError('Payment form is still loading. Please wait a moment and try again.');
                return false;
            }
            isProcessing = true;
            setPlaceOrderLoading(true);
            submitDeferredSubscription();
            return false;
        }

        // Setup mode: keep confirm-first (no charge happens now).
        if (intentMode === 'setup') {
            return handleFormSubmit(e);
        }

        // Payment mode: deferred order-first flow.
        if (isProcessing) {
            return false;
        }
        if (!clientSecret || !elements) {
            displayError('Payment form is still loading. Please wait a moment and try again.');
            return false;
        }

        isProcessing = true;
        setPlaceOrderLoading(true);
        submitCheckoutDeferred();
        return false;
    }

    /**
     * Submit the checkout to WooCommerce's own AJAX endpoint so the order is
     * validated and created BEFORE any charge. On success the server hands back
     * a confirmation marker (rpsfw_stripe_confirm) which we complete in the
     * browser; on validation failure it returns the notices with no charge.
     */
    function submitCheckoutDeferred() {
        var $form = $('form.checkout');

        // Make sure the server can resolve the intent/subscription created when
        // the payment form loaded.
        ensureHiddenField($form, 'rpsfw_stripe_payment_intent_id', paymentIntentId);
        ensureHiddenField($form, 'rpsfw_stripe_subscription_id', subscriptionId);

        var url = rpsfwStripeParams.checkout_url ||
            (window.wc_checkout_params && window.wc_checkout_params.checkout_url) ||
            '/?wc-ajax=checkout';

        $.ajax({
            type: 'POST',
            url: url,
            data: $form.serialize(),
            dataType: 'json'
        }).done(function(result) {
            if (!result || typeof result !== 'object') {
                showCheckoutError(genericErrorHtml());
                resetProcessing();
                return;
            }
            if (result.result === 'success') {
                if (result.rpsfw_stripe_confirm) {
                    confirmDeferred(result);
                } else {
                    // Completed server-side (e.g. an already-succeeded intent).
                    navigateTo(result.redirect);
                }
            } else if (result.result === 'failure') {
                if (result.reload === true || result.reload === 'true') {
                    window.location.reload();
                    return;
                }
                showCheckoutError(result.messages || genericErrorHtml());
                resetProcessing();
            } else {
                showCheckoutError(genericErrorHtml());
                resetProcessing();
            }
        }).fail(function() {
            showCheckoutError(genericErrorHtml());
            resetProcessing();
        });
    }

    /**
     * Confirm the PaymentIntent in the browser after the order was created,
     * then finalize the order server-side.
     */
    function confirmDeferred(result) {
        var returnUrl = window.location.origin + window.location.pathname + '?rpsfw_stripe_return=1';

        var confirmOptions = {
            elements: elements,
            confirmParams: {
                return_url: returnUrl,
                payment_method_data: {
                    billing_details: getBillingDetails()
                }
            },
            redirect: 'if_required'
        };

        // In the deferred flow the Element was created WITHOUT a clientSecret,
        // so confirmPayment must be given the intent's clientSecret explicitly.
        // In the original flow it is baked into the Element, so we must NOT
        // pass it here.
        if (usesDeferredSub() && clientSecret) {
            confirmOptions.clientSecret = clientSecret;
        }

        stripe.confirmPayment(confirmOptions).then(function(res) {
            var recovered = res.error ? recoverIntentFromError(res.error, 'payment') : null;
            if (res.error && !recovered) {
                showCheckoutError('<div class="woocommerce-error">' + escapeHtml(res.error.message) + '</div>');
                resetProcessing();
                return;
            }

            var pi = recovered || res.paymentIntent;
            if (!pi || !(pi.status === 'succeeded' || pi.status === 'requires_capture' || pi.status === 'processing')) {
                showCheckoutError('<div class="woocommerce-error">' + genericPaymentErrorText() + '</div>');
                resetProcessing();
                return;
            }

            paymentConfirmed = true;
            finalizeOrder(result.rpsfw_stripe_order_id, result.rpsfw_stripe_order_key, pi.id, result.redirect);
        });
    }

    /**
     * Ask the server to verify the confirmed payment and mark the order paid,
     * then send the customer to the order received page.
     *
     * IMPORTANT: only forward to the order-received page when the server
     * confirms the order was finalized (res.success + redirect). A failed or
     * unrecognized finalize response means the order is still pending and the
     * Stripe subscription's first invoice is still unpaid/incomplete — in that
     * case we must NOT send the customer to "order received", or they see a
     * confirmation for a payment that never completed. Surface an error and let
     * them retry instead. (The unused fallbackRedirect argument is kept for
     * call-site compatibility.)
     */
    function finalizeOrder(orderId, orderKey, intentId, fallbackRedirect) { // eslint-disable-line no-unused-vars
        $.ajax({
            type: 'POST',
            url: rpsfwStripeParams.ajax_url,
            data: {
                action: 'rpsfw_stripe_finalize_order',
                nonce: rpsfwStripeParams.finalize_nonce,
                order_id: orderId,
                order_key: orderKey,
                payment_intent_id: intentId || '',
                rpsfw_stripe_payment_intent_id: intentId || '',
                rpsfw_stripe_subscription_id: subscriptionId || ''
            },
            dataType: 'json'
        }).done(function(res) {
            if (res && res.success && res.data && res.data.redirect) {
                navigateTo(res.data.redirect);
            } else if (res && !res.success && res.data && res.data.message) {
                showCheckoutError('<div class="woocommerce-error">' + escapeHtml(res.data.message) + '</div>');
                resetProcessing();
            } else {
                showCheckoutError('<div class="woocommerce-error">' + genericPaymentErrorText() + '</div>');
                resetProcessing();
            }
        }).fail(function() {
            showCheckoutError('<div class="woocommerce-error">' + genericPaymentErrorText() + '</div>');
            resetProcessing();
        });
    }

    /**
     * Append a hidden field to the form if it is not already present.
     */
    function ensureHiddenField($form, name, value) {
        if (!value) {
            return;
        }
        if ($form.find('input[name="' + name + '"]').length === 0) {
            $form.append('<input type="hidden" name="' + name + '" value="' + escapeHtml(String(value)) + '" />');
        }
    }

    /**
     * Render a checkout error the same way WooCommerce does: clear old notices,
     * prepend the message to the checkout form, unblock and scroll to it.
     */
    function showCheckoutError(html) {
        var $form = $('form.checkout');
        // Clear any prior notice, including one left by the PayPal button flow
        // (.rpsfw-ppcp-error), so only a single notice is ever shown.
        $('.rpsfw-ppcp-error, .woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
        $form.prepend('<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">' + html + '</div>');
        $form.removeClass('processing').unblock();
        if ($form.offset()) {
            $('html, body').animate({ scrollTop: ($form.offset().top - 100) }, 500);
        }
        $(document.body).trigger('checkout_error', [ html ]);
    }

    /**
     * Clear the processing state and button spinner.
     */
    function resetProcessing() {
        isProcessing = false;
        setPlaceOrderLoading(false);
    }

    /**
     * Navigate the browser, mirroring WooCommerce's redirect handling.
     */
    function navigateTo(url) {
        if (!url) {
            window.location.reload();
            return;
        }
        if (url.indexOf('http') === 0) {
            window.location = decodeURI(url);
        } else {
            window.location = url;
        }
    }

    function genericErrorHtml() {
        return '<div class="woocommerce-error">' + genericPaymentErrorText() + '</div>';
    }

    function genericPaymentErrorText() {
        return 'We were unable to process your payment. Please try again.';
    }

    /**
     * Minimal HTML escaping for values injected into markup/attributes.
     */
    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /**
     * Toggle a spinner on the Place Order button (classic checkout) or the
     * order-pay submit button. Used instead of blocking the whole form so the
     * loading indicator stays next to the button the customer just clicked.
     *
     * @param {boolean} loading
     */
    function setPlaceOrderLoading(loading) {
        var $btn = $('#place_order');
        if (!$btn.length) {
            $btn = $('#order_review button[type="submit"], form#order_review #place_order');
        }
        if (!$btn.length) {
            return;
        }
        if (loading) {
            $btn.prop('disabled', true).addClass('rpsfw-stripe-btn-loading');
            if ($btn.find('.rpsfw-stripe-btn-spinner').length === 0) {
                $btn.append('<span class="rpsfw-stripe-btn-spinner" aria-hidden="true"></span>');
            }
        } else {
            $btn.prop('disabled', false).removeClass('rpsfw-stripe-btn-loading');
            $btn.find('.rpsfw-stripe-btn-spinner').remove();
        }
    }

    /**
     * Confirm PaymentIntent or SetupIntent depending on flow.
     */
    function confirmIntent(form) {
        if (!paymentElement || !elements) {
            displayError('Payment element not initialized. Please refresh the page.');
            setPlaceOrderLoading(false);
            isProcessing = false;
            return;
        }

        var returnUrl = window.location.origin + window.location.pathname + '?rpsfw_stripe_return=1';

        var confirmParams = {
            return_url: returnUrl,
            payment_method_data: {
                billing_details: getBillingDetails()
            }
        };

        if (intentMode === 'setup') {
            stripe.confirmSetup({
                elements: elements,
                confirmParams: confirmParams,
                redirect: 'if_required'
            }).then(function(result) {
                handleConfirmResult(result, form, 'setup');
            });
        } else {
            stripe.confirmPayment({
                elements: elements,
                confirmParams: confirmParams,
                redirect: 'if_required'
            }).then(function(result) {
                handleConfirmResult(result, form, 'payment');
            });
        }
    }

    /**
     * Detect the "already confirmed" race and return the underlying intent if
     * it is in a state we consider paid/authenticated. Stripe surfaces this as
     * error.code === 'payment_intent_unexpected_state' (or the setup_intent
     * equivalent) and includes the intent object on the error. Returns the
     * intent object when it is safe to proceed, otherwise null.
     *
     * @param {object} error Stripe error object from confirmPayment/confirmSetup.
     * @param {string} mode  'payment' or 'setup'.
     * @returns {object|null}
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
            var status = error.payment_intent.status;
            if (status === 'succeeded' || status === 'requires_capture' || status === 'processing') {
                return error.payment_intent;
            }
        }
        return null;
    }

    /**
     * Common handler for confirmPayment / confirmSetup results.
     */
    function handleConfirmResult(result, form, mode) {
        // A duplicate confirmation (network retry, back-navigation, or a
        // second submit) can hit an intent that was ALREADY confirmed on the
        // first attempt. Stripe then returns payment_intent_unexpected_state /
        // setup_intent_unexpected_state instead of a success result. When the
        // embedded intent is already in a good state the payment really did go
        // through, so treat it as success and continue to place the order
        // rather than showing the customer a confusing error.
        if (result.error) {
            var recovered = recoverIntentFromError(result.error, mode);
            if (recovered) {
                if (mode === 'setup') {
                    result = { setupIntent: recovered };
                } else {
                    result = { paymentIntent: recovered };
                }
            } else {
                displayError(result.error.message);
                setPlaceOrderLoading(false);
                isProcessing = false;
                return;
            }
        }

        var ok = false;
        if (mode === 'setup') {
            ok = result.setupIntent && result.setupIntent.status === 'succeeded';
        } else {
            ok = result.paymentIntent && (result.paymentIntent.status === 'succeeded' || result.paymentIntent.status === 'requires_capture' || result.paymentIntent.status === 'processing');
        }

        if (!ok) {
            displayError('Payment could not be completed. Please try again.');
            setPlaceOrderLoading(false);
            isProcessing = false;
            return;
        }

        // For subscription carts, the server already has the subscription
        // id stashed in session and tied to this cart. Pass it back so
        // process_payment can finalize without a second look-up.
        if (subscriptionId) {
            if ($('input[name="rpsfw_stripe_subscription_id"]').length === 0) {
                form.append('<input type="hidden" name="rpsfw_stripe_subscription_id" value="' + subscriptionId + '" />');
            }
        }

        // For change-payment-method, pass the SetupIntent id back so
        // process_payment can attach + set as default on the Stripe
        // subscription.
        if (mode === 'setup' && result.setupIntent && result.setupIntent.id) {
            if ($('input[name="rpsfw_stripe_setup_intent_id"]').length === 0) {
                form.append('<input type="hidden" name="rpsfw_stripe_setup_intent_id" value="' + result.setupIntent.id + '" />');
            }
        }

        // For one-off PaymentIntents, pass the intent id back so
        // process_payment can verify the status.
        if (mode === 'payment' && result.paymentIntent && result.paymentIntent.id) {
            if ($('input[name="rpsfw_stripe_payment_intent_id"]').length === 0) {
                form.append('<input type="hidden" name="rpsfw_stripe_payment_intent_id" value="' + result.paymentIntent.id + '" />');
            }
        }

        // Mark confirmed so the checkout place-order handler lets WooCommerce
        // submit normally (process_payment will see the confirmed intent).
        paymentConfirmed = true;

        // Submit the form.
        form.off('submit', handleFormSubmit);
        form.submit();
    }

    /**
     * Get billing details from form
     */
    function getBillingDetails() {
        var billingDetails = {
            name: $('#billing_first_name').val() + ' ' + $('#billing_last_name').val(),
            email: $('#billing_email').val(),
            phone: $('#billing_phone').val(),
            address: {
                line1: $('#billing_address_1').val(),
                line2: $('#billing_address_2').val(),
                city: $('#billing_city').val(),
                state: $('#billing_state').val(),
                postal_code: $('#billing_postcode').val(),
                country: $('#billing_country').val()
            }
        };

        return billingDetails;
    }

    /**
     * Tear down the mounted Payment Element and clear the cached intent
     * so the next createPaymentElement() pass fetches a fresh one. Used
     * when the cart total changes (coupon applied/removed, shipping
     * change) so Stripe charges the up-to-date amount instead of the
     * amount captured when the page first loaded.
     */
    function resetIntent() {
        clientSecret = null;
        paymentIntentId = null;
        setupIntentId = null;
        subscriptionId = null;

        if (paymentElement) {
            try {
                paymentElement.unmount();
                paymentElement.destroy();
            } catch (e) {
                // Ignore
            }
            paymentElement = null;
        }
        if (expressCheckoutElement) {
            try {
                expressCheckoutElement.unmount();
                expressCheckoutElement.destroy();
            } catch (e) {
                // Ignore
            }
            expressCheckoutElement = null;
        }
        elements = null;

        // Clear the in-flight guard. If an intent request was still pending when
        // the cart changed, leaving this true would block the next
        // createPaymentElement() from creating a fresh intent — the form would
        // stay stuck on the "Loading payment form..." spinner until a full page
        // refresh.
        isCreatingIntent = false;

        // A fresh intent means nothing is confirmed yet.
        paymentConfirmed = false;

        // Drop any hidden fields a prior confirm appended so submit does
        // not short-circuit with a stale intent id.
        $('input[name="rpsfw_stripe_payment_intent_id"], input[name="rpsfw_stripe_subscription_id"]').remove();
    }

    /**
     * Deferred flow: refresh the mounted Element's { mode, amount, currency }
     * to match the new cart total (coupon / shipping / address), creating
     * nothing on Stripe. Reloads only if the cart is no longer a charge-today
     * subscription (mode flipped to setup, or no longer a subscription), so the
     * correct flow initializes cleanly.
     */
    function updateDeferredMount() {
        if (isProcessing || !elements) {
            return;
        }
        $.ajax({
            url: rpsfwStripeParams.ajax_url,
            type: 'POST',
            data: {
                action: 'rpsfw_stripe_get_mount_params',
                nonce: rpsfwStripeParams.create_intent_nonce
            }
        }).done(function(response) {
            var mount = (response && response.success && response.data) ? response.data.mount : null;
            if (!mount || mount.mode !== 'subscription') {
                // Flow changed (now a $0 trial or one-off): reload to switch paths.
                window.location.reload();
                return;
            }
            rpsfwStripeParams.deferred_mount = mount;
            try {
                elements.update({ mode: mount.mode, amount: mount.amount, currency: mount.currency });
            } catch (e) {
                // Rather than leave a stale amount (which would fail confirm),
                // reload so the Element re-mounts with the correct total.
                window.location.reload();
            }
        });
        // On AJAX failure we intentionally leave the current Element as-is; the
        // server re-derives and charges the correct amount at subscription
        // creation regardless of the Element's displayed figure.
    }

    /**
     * Re-evaluate the cart after it changes (coupon, quantity, item add/remove,
     * mini-cart edit) and rebuild the payment element for the new cart.
     *
     * Crucially this re-checks whether the cart is still a subscription cart:
     * the localized `is_subscription` flag is captured at page load and goes
     * stale when items change. If the flow flipped (subscription <-> one-off)
     * we reload so the correct intent type is initialized from scratch;
     * otherwise we just re-mount with a fresh intent. Without this, re-mounting
     * with a stale flag calls the wrong endpoint (e.g. the subscription
     * endpoint after the last recurring item was removed -> "No subscription in
     * cart").
     */
    function reevaluateCartAndRemount() {
        if (isChangePaymentMethod()) {
            createPaymentElement();
            return;
        }
        if (isProcessing) {
            return;
        }

        // Deferred subscription flow: nothing is created on Stripe on a cart
        // change. If the Element is still mounted, just refresh its
        // { mode, amount, currency } to match the new total. If a fragment
        // refresh wiped the iframe, remount deferred. If the cart is no longer
        // a charge-today subscription, updateDeferredMount() reloads.
        if (usesDeferredSub()) {
            var dc = $('#stripe-payment-element');
            if (dc.length && dc.find('iframe').length === 0) {
                resetIntent();
                createPaymentElement();
            } else {
                updateDeferredMount();
            }
            return;
        }
        // A creation is already in flight (e.g. the initial mount fired
        // createSubscription and an updated_checkout arrived moments later).
        // Don't reset and recreate — that produced a second, orphaned Stripe
        // subscription. Let the in-flight request finish and mount the element.
        if (isCreatingIntent) {
            return;
        }
        resetIntent();

        $.ajax({
            url: rpsfwStripeParams.ajax_url,
            type: 'POST',
            data: {
                action: 'rpsfw_stripe_cart_is_subscription',
                nonce: rpsfwStripeParams.create_intent_nonce
            },
            success: function(response) {
                if (response.success && typeof response.data.is_subscription !== 'undefined') {
                    var wasSubscription = !!rpsfwStripeParams.is_subscription;
                    var isNow = !!response.data.is_subscription;
                    if (wasSubscription !== isNow) {
                        // Flow changed (subscription <-> one-off): reload so the
                        // correct intent type initializes cleanly.
                        window.location.reload();
                        return;
                    }
                    rpsfwStripeParams.is_subscription = isNow;
                }
                createPaymentElement();
            },
            error: function() {
                // Even if the check fails, re-mount so the customer still gets a
                // payment form.
                createPaymentElement();
            }
        });
    }

    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        // Check if we're on checkout page
        if ($('#stripe-payment-element').length === 0) {
            return;
        }

        // Inject the button-spinner styles once (used by setPlaceOrderLoading).
        if (!document.getElementById('rpsfw-stripe-btn-spinner-css')) {
            var spinnerStyle = document.createElement('style');
            spinnerStyle.id = 'rpsfw-stripe-btn-spinner-css';
            spinnerStyle.textContent =
                '@keyframes rpsfw-stripe-btn-spin{0%{transform:rotate(0)}100%{transform:rotate(360deg)}}' +
                '.rpsfw-stripe-btn-spinner{display:inline-block;width:1em;height:1em;margin-left:.6em;' +
                'vertical-align:-.15em;border:2px solid currentColor;border-right-color:transparent;' +
                'border-radius:50%;animation:rpsfw-stripe-btn-spin .7s linear infinite;opacity:.9}' +
                '.rpsfw-stripe-btn-loading{opacity:.85;cursor:default}';
            document.head.appendChild(spinnerStyle);
        }

        // Initialize Stripe
        if (!initStripe()) {
            return;
        }

        // Mount the payment element when it becomes available. Rather than
        // polling every second (which added overhead and could churn the
        // mounted Stripe element), react to the specific events that render or
        // re-render the checkout payment box:
        //  - initial page load
        //  - the customer selecting the Stripe payment method radio
        //  - WooCommerce refreshing the checkout fragment (updated_checkout)
        createPaymentElement();
        $(document.body).on('payment_method_selected', createPaymentElement);
        $(document.body).on('change', 'input[name="payment_method"]', createPaymentElement);

        // When WooCommerce refreshes the checkout (coupon applied/removed,
        // shipping method change, address change), the cart total may have
        // changed. The change-payment-method flow has a fixed amount and
        // must not be reset. Otherwise discard the stale intent so a fresh
        // one is created for the new total, then re-mount once we know the
        // correct flow (subscription vs one-off) for the new cart.
        $(document.body).on('updated_checkout', function() {
            reevaluateCartAndRemount();
        });

        // When the shopper fills in / changes their email, re-create the Payment
        // Element so Link can recognise a returning customer from it. Delegated
        // so it survives WooCommerce re-rendering the checkout fragment.
        $(document.body).on('change', '#billing_email', remountForEmail);

        // Mini-cart / side-cart changes (e.g. removing an item) refresh
        // WooCommerce fragments, which re-render the checkout HTML and wipe the
        // mounted Stripe iframe — but they don't always fire `updated_checkout`.
        // When that happens the payment box is left showing the loading spinner
        // until a manual page refresh. Detect the wiped iframe here and rebuild
        // the element, re-checking subscription status for the changed cart (so
        // we don't call the subscription endpoint after the last recurring item
        // was removed -> "No subscription in cart").
        $(document.body).on('wc_fragments_refreshed wc_fragments_loaded', function() {
            var container = $('#stripe-payment-element');
            if (container.length === 0 || isProcessing) {
                return;
            }
            // Only act if the mounted element was wiped by the fragment refresh.
            if (container.find('iframe').length === 0) {
                reevaluateCartAndRemount();
            }
        });

        // Handle form submission.
        //  - Main checkout: deferred (order-first) flow for payment mode.
        //  - Order-pay page: confirm-first (the order already exists and is
        //    validated), so the original handler is fine there.
        $('form.checkout').on('checkout_place_order_rpsfw_stripe', handleCheckoutPlaceOrder);
        $('form#order_review').on('submit', handleFormSubmit);
    });

})(jQuery);
