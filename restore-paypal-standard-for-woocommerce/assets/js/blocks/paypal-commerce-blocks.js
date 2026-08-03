/**
 * PayPal Commerce Blocks Integration
 */
(function() {
    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const { getSetting } = window.wc.wcSettings;
    const { decodeEntities } = window.wp.htmlEntities;
    const { createElement, useState, useEffect, useRef } = window.wp.element;

    const settings = getSetting('rpsfw_paypal_commerce_data', {});
    const title = decodeEntities(settings.title) || 'PayPal';
    const iconUrl = settings.iconUrl || '';
    const ajaxUrl = settings.ajaxUrl || '/wp-admin/admin-ajax.php';
    const createOrderNonce = settings.createOrderNonce || '';
    const createSubscriptionNonce = settings.createSubscriptionNonce || '';
    const isSubscription = settings.isSubscription === true;
    const showTitle = settings.showTitle !== false;
    const showDescription = settings.showDescription !== false;

    // Add CSS to remove iframe borders
    if (!document.getElementById('rpsfw-paypal-commerce-blocks-styles')) {
        const style = document.createElement('style');
        style.id = 'rpsfw-paypal-commerce-blocks-styles';
        style.textContent = `
            #rpsfw-paypal-buttons iframe {
                border: none !important;
                outline: none !important;
            }
            #rpsfw-paypal-buttons {
                min-height: 45px;
            }
        `;
        document.head.appendChild(style);
    }

    // Global state to persist across re-renders
    let globalPaypalOrderId = null;
    let globalPaypalSubscriptionId = null;

    // Build the standard WooCommerce checkout field values (billing_*,
    // shipping_*, etc.) from the block's billing/shipping data so the server
    // can validate them with WooCommerce's own validator before we let PayPal
    // open. Always returns string values so URLSearchParams is clean.
    function buildCheckoutFieldParams(billing, shippingData) {
        const b = (billing && (billing.billingData || billing.billingAddress)) || {};
        const needsShipping = !!(shippingData && shippingData.needsShipping);
        const s = (shippingData && shippingData.shippingAddress) || b;
        const str = (v) => (v === undefined || v === null) ? '' : String(v);

        const params = {
            billing_first_name: str(b.first_name),
            billing_last_name:  str(b.last_name),
            billing_company:    str(b.company),
            billing_email:      str(b.email),
            billing_phone:      str(b.phone),
            billing_country:    str(b.country),
            billing_address_1:  str(b.address_1),
            billing_address_2:  str(b.address_2),
            billing_city:       str(b.city),
            billing_state:      str(b.state),
            billing_postcode:   str(b.postcode),
            payment_method:     'rpsfw_paypal_commerce',
            terms:              '1',
        };

        if (needsShipping) {
            // Force WooCommerce to validate the shipping fields too. When "use
            // same address for billing" is on, the block mirrors shipping into
            // billing, so both sets are populated and both validate.
            params.ship_to_different_address = '1';
            params.shipping_first_name = str(s.first_name);
            params.shipping_last_name  = str(s.last_name);
            params.shipping_company    = str(s.company);
            params.shipping_country    = str(s.country);
            params.shipping_address_1  = str(s.address_1);
            params.shipping_address_2  = str(s.address_2);
            params.shipping_city       = str(s.city);
            params.shipping_state      = str(s.state);
            params.shipping_postcode   = str(s.postcode);
        }

        return params;
    }

    // Collect values for fields registered via the block "Additional checkout
    // fields" API so the server can enforce required ones before PayPal opens.
    // Posted as rpsfw_additional_fields[<field-id>] to match the server reader.
    function getBlockAdditionalFieldParams() {
        var out = {};
        try {
            var sel = window.wp && wp.data && wp.data.select('wc/store/checkout');
            if (sel && typeof sel.getAdditionalFields === 'function') {
                var af = sel.getAdditionalFields() || {};
                Object.keys(af).forEach(function(k) {
                    var v = af[k];
                    if (v === true) { v = '1'; }
                    else if (v === false || v === null || v === undefined) { v = ''; }
                    out['rpsfw_additional_fields[' + k + ']'] = String(v);
                });
            }
        } catch (e) { /* additional-fields store unavailable; ignore */ }
        return out;
    }

    // Surface / clear a checkout error notice in the block's notice area.
    function showCheckoutNotice(message) {
        try {
            if (window.wp && wp.data && wp.data.dispatch('core/notices')) {
                wp.data.dispatch('core/notices').createErrorNotice(message, {
                    context: 'wc/checkout',
                    id: 'rpsfw-ppcp-field-validation',
                });
            }
        } catch (e) { /* notices store unavailable; ignore */ }
    }
    function clearCheckoutNotice() {
        try {
            if (window.wp && wp.data && wp.data.dispatch('core/notices')) {
                wp.data.dispatch('core/notices').removeNotice('rpsfw-ppcp-field-validation', 'wc/checkout');
            }
        } catch (e) { /* ignore */ }
    }

    const Label = (props) => {
        const { PaymentMethodLabel } = props.components;
        
        // If title is hidden, show nothing or just icon
        if (!showTitle) {
            if (iconUrl) {
                return createElement('img', {
                    src: iconUrl,
                    alt: 'PayPal',
                    className: 'wc-block-components-payment-method-icon',
                    style: { maxHeight: '24px' }
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
                    style: { maxHeight: '24px', marginRight: '8px' }
                }),
                createElement('span', null, title)
            );
        }
        return createElement(PaymentMethodLabel, { text: title });
    };

    const Content = (props) => {
        const { eventRegistration, emitResponse, billing, shippingData, validate } = props;
        const { onPaymentSetup } = eventRegistration;
        const [paypalOrderId, setPaypalOrderId] = useState(globalPaypalOrderId);
        const [status, setStatus] = useState(globalPaypalOrderId ? 'approved' : 'idle');
        const [sdkReady, setSdkReady] = useState(false);
        // Bumped when the cart changes so the render effect re-runs and the
        // PayPal buttons are rebuilt for the updated cart.
        const [renderKey, setRenderKey] = useState(0);
        const buttonsRendered = useRef(false);
        const containerRef = useRef(null);
        const didMountRef = useRef(false);
        // Latest billing/shipping data, refreshed on every render, so the
        // PayPal button's onClick validates what the shopper has entered NOW
        // (the buttons are rendered once and would otherwise close over stale
        // field values).
        const fieldsRef = useRef({ billing: billing, shippingData: shippingData });
        useEffect(() => {
            fieldsRef.current = { billing: billing, shippingData: shippingData };
        });

        // Validate the WooCommerce checkout fields server-side (WooCommerce's
        // own validator). Resolves true when valid; on invalid it shows the
        // field errors as a checkout notice and resolves false so the caller
        // can stop PayPal from opening.
        const validateCheckoutFields = () => {
            const current = fieldsRef.current || { billing: billing, shippingData: shippingData };
            const params = Object.assign(
                buildCheckoutFieldParams(current.billing, current.shippingData),
                getBlockAdditionalFieldParams()
            );
            params.action = 'rpsfw_ppcp_validate_checkout';
            params.nonce = isSubscription ? (createSubscriptionNonce || createOrderNonce) : (createOrderNonce || createSubscriptionNonce);
            params.rpsfw_validate_fields = '1';

            return fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(params),
            })
            .then((r) => r.json())
            .then((res) => {
                if (res && res.success) {
                    clearCheckoutNotice();
                    return true;
                }
                const msgs = (res && res.data && res.data.messages && res.data.messages.length)
                    ? res.data.messages
                    : [ (res && res.data && res.data.message) || 'Please complete the required checkout fields before paying.' ];
                showCheckoutNotice(msgs.join(' '));
                try {
                    const el = document.querySelector('.wp-block-woocommerce-checkout, .wc-block-checkout');
                    if (el) { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
                } catch (e) { /* ignore */ }
                return false;
            })
            .catch(() => {
                // If the validation request itself fails, don't hard-block the
                // shopper — the create-order/create-subscription handlers run
                // the same server-side validation and will still reject an
                // invalid form before any PayPal order is created.
                return true;
            });
        };

        // Cart total (minor units). We watch this so cart edits made on the
        // checkout page (e.g. removing an item via the mini/side cart) trigger
        // a re-check and re-render, matching the Stripe block behaviour.
        const cartTotalValue = ( billing && billing.cartTotal ) ? billing.cartTotal.value : null;

        // Payment setup handler
        useEffect(() => {
            const unsubscribe = onPaymentSetup(() => {
                // Subscription cart: the buyer must have approved a PayPal
                // subscription. The subscription id is also stored server-side
                // in the session by the create-subscription AJAX, which the
                // gateway's process_payment override uses to finalize.
                if (isSubscription) {
                    if (!globalPaypalSubscriptionId) {
                        return {
                            type: emitResponse.responseTypes.ERROR,
                            message: settings.paypalButtonErrorText || 'Please click the PayPal button and complete payment first.',
                        };
                    }
                    return {
                        type: emitResponse.responseTypes.SUCCESS,
                        meta: {
                            paymentMethodData: {
                                paypal_subscription_id: globalPaypalSubscriptionId,
                            },
                        },
                    };
                }

                if (!globalPaypalOrderId) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: settings.paypalButtonErrorText || 'Please click the PayPal button and complete payment first.',
                    };
                }

                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            paypal_order_id: globalPaypalOrderId,
                        },
                    },
                };
            });
            return unsubscribe;
        }, [onPaymentSetup, emitResponse.responseTypes]);

        // Check for PayPal SDK
        useEffect(() => {
            if (status === 'approved') return;
            
            const checkSDK = () => {
                if (typeof paypal !== 'undefined' && typeof paypal.Buttons === 'function') {
                    setSdkReady(true);
                    return true;
                }
                return false;
            };

            if (checkSDK()) return;

            // Poll for SDK
            let attempts = 0;
            const maxAttempts = 100; // 10 seconds
            const interval = setInterval(() => {
                attempts++;
                if (checkSDK() || attempts >= maxAttempts) {
                    clearInterval(interval);
                }
            }, 100);

            return () => clearInterval(interval);
        }, [status]);

        // Re-check subscription status and rebuild the buttons when the cart
        // changes on the checkout page (e.g. removing an item via the mini/side
        // cart). The static isSubscription flag and the loaded PayPal SDK mode
        // are fixed at page load; if the cart flips between subscription and
        // one-off we reload so the SDK re-initializes with the correct intent.
        // Skips the initial mount (the render effect below handles first load).
        useEffect(() => {
            if (!didMountRef.current) {
                didMountRef.current = true;
                return;
            }
            if (status === 'approved') {
                return;
            }

            // A cart-total change (e.g. the shopper edited their shipping
            // address, so shipping/tax recalculated) does NOT require rebuilding
            // the PayPal buttons: the order amount is computed server-side at
            // create-order time from the live cart, and the button callbacks
            // read the latest field values on click. Rebuilding here made the
            // buttons visibly flicker (disappear then reappear) on every address
            // edit. The ONLY change that needs handling is a subscription <->
            // one-off flip, which requires a reload so the PayPal SDK
            // re-initializes with the correct intent (subscription vs capture).
            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'rpsfw_ppcp_cart_is_subscription' }),
            })
            .then((r) => r.json())
            .then((data) => {
                if (data && data.success && ( !!data.data.is_subscription !== isSubscription )) {
                    window.location.reload();
                }
            })
            .catch(() => {
                // No-op: leave the existing buttons in place.
            });
        }, [cartTotalValue]);

        // Render PayPal buttons when SDK is ready
        useEffect(() => {
            if (!sdkReady || buttonsRendered.current || status === 'approved') return;
            
            const container = document.getElementById('rpsfw-paypal-buttons');
            if (!container) return;

            buttonsRendered.current = true;

            const buttonsConfig = {
                style: { layout: 'vertical', color: 'gold', shape: 'rect', label: isSubscription ? 'subscribe' : 'paypal' },

                    // Gate the button: validate the WooCommerce checkout fields
                    // BEFORE PayPal opens. If the form is incomplete/invalid we
                    // reject the click so no PayPal order/subscription is created
                    // and no payment is taken. Mirrors the native checkout's
                    // "fill your details first" behaviour.
                    onClick: function(data, actions) {
                        // Prefer WooCommerce Blocks' native form validation. The
                        // validate() prop validates the ENTIRE checkout form —
                        // address, shipping, contact and any custom fields added
                        // via the Additional Checkout Fields API — and displays
                        // the errors itself. Fall back to our server-side field
                        // validator only on older WooCommerce without validate().
                        if (typeof validate === 'function') {
                            return Promise.resolve(validate()).then(function(res) {
                                return (res && res.hasError) ? actions.reject() : actions.resolve();
                            }).catch(function() {
                                // If validate() itself errors, don't hard-block; the
                                // create-order/subscription server call still runs
                                // the same server-side validation as a backstop.
                                return actions.resolve();
                            });
                        }
                        return validateCheckoutFields().then(function(ok) {
                            return ok ? actions.resolve() : actions.reject();
                        });
                    },

                    onCancel: function() {
                        // Payment cancelled
                    },

                    onError: function(err) {
                        // Suppress zoid errors - they're internal PayPal SDK errors
                        if (err && err.message && err.message.indexOf('zoid') !== -1) return;
                    }
            };

            if (isSubscription) {
                // Subscription cart: SDK is loaded with intent=subscription, so
                // we must use createSubscription. The server stores the pending
                // subscription id in the session; the gateway's process_payment
                // override finalizes it when the order is placed.
                buttonsConfig.createSubscription = function() {
                    const current = fieldsRef.current || { billing: billing, shippingData: shippingData };
                    const body = Object.assign(
                        buildCheckoutFieldParams(current.billing, current.shippingData),
                        getBlockAdditionalFieldParams(),
                        {
                            action: 'rpsfw_ppcp_create_subscription',
                            nonce: createSubscriptionNonce,
                            rpsfw_validate_fields: '1'
                        }
                    );
                    return fetch(ajaxUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams(body)
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success && data.data.subscription_id) {
                            return data.data.subscription_id;
                        }
                        throw new Error(data.data?.message || 'Failed to create subscription');
                    });
                };

                buttonsConfig.onApprove = function(data) {
                    globalPaypalSubscriptionId = data.subscriptionID || data.orderID;
                    setStatus('approved');

                    // Auto-submit the order after a brief delay so the block
                    // checkout runs process_payment, which finalizes the
                    // approved PayPal subscription from the session.
                    setTimeout(function() {
                        const placeOrderButton = document.querySelector('.wc-block-components-checkout-place-order-button');
                        if (placeOrderButton) {
                            placeOrderButton.click();
                        }
                    }, 500);

                    return Promise.resolve();
                };
            } else {
                buttonsConfig.createOrder = function() {
                    const current = fieldsRef.current || { billing: billing, shippingData: shippingData };
                    const body = Object.assign(
                        buildCheckoutFieldParams(current.billing, current.shippingData),
                        getBlockAdditionalFieldParams(),
                        {
                            action: 'rpsfw_ppcp_create_order',
                            nonce: createOrderNonce,
                            rpsfw_validate_fields: '1'
                        }
                    );
                    return fetch(ajaxUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams(body)
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success && data.data.order_id) {
                            return data.data.order_id;
                        }
                        throw new Error(data.data?.message || 'Failed to create order');
                    });
                };

                buttonsConfig.onApprove = function(data) {
                    return fetch(ajaxUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'rpsfw_ppcp_approve_order',
                            nonce: createOrderNonce,
                            paypal_order_id: data.orderID
                        })
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(result) {
                        if (result.success) {
                            globalPaypalOrderId = data.orderID;
                            setPaypalOrderId(data.orderID);
                            setStatus('approved');

                            // Auto-submit the order after a brief delay
                            setTimeout(function() {
                                const placeOrderButton = document.querySelector('.wc-block-components-checkout-place-order-button');
                                if (placeOrderButton) {
                                    placeOrderButton.click();
                                }
                            }, 500);
                        }
                    });
                };
            }

            try {
                // Render one button per ENABLED + ELIGIBLE funding source
                // rather than relying on the SDK disable-funding list (which
                // does not reliably hide the card button).
                const funding = settings.funding || {};
                const FUNDING = paypal.FUNDING || {};

                // Render in the merchant's configured order (Appearance tab),
                // filtered by which buttons are enabled.
                const map = {
                    paypal:   FUNDING.PAYPAL,
                    paylater: FUNDING.PAYLATER,
                    card:     FUNDING.CARD,
                    venmo:    FUNDING.VENMO
                };
                const order = (funding.order && funding.order.length) ? funding.order : ['paypal', 'paylater', 'card', 'venmo'];

                const sources = [];
                order.forEach(function(token) {
                    if (token === 'paypal' && funding.paypal === false) return;
                    if (token === 'paylater' && !funding.paylater) return;
                    if (token === 'card' && !funding.card) return;
                    if (token === 'venmo' && !funding.venmo) return;
                    if (map[token]) sources.push(map[token]);
                });

                sources.forEach(function(source) {
                    if (!source) return;

                    const cfg = Object.assign({}, buttonsConfig, { fundingSource: source });
                    cfg.style = Object.assign({}, buttonsConfig.style);
                    if (source === FUNDING.CARD || source === FUNDING.VENMO) {
                        delete cfg.style.label;
                        delete cfg.style.color;
                    } else if (source !== FUNDING.PAYPAL) {
                        delete cfg.style.label;
                    }

                    let button;
                    try {
                        button = paypal.Buttons(cfg);
                    } catch (e) {
                        return;
                    }

                    if (!button.isEligible()) return;

                    button.render('#rpsfw-paypal-buttons').catch(function(err) {
                        // Suppress zoid errors
                        if (err && err.message && err.message.indexOf('zoid') !== -1) return;
                    });
                });
            } catch (e) {
                // Suppress errors
                if (e && e.message && e.message.indexOf('zoid') !== -1) return;
            }
        }, [sdkReady, status, renderKey]);

        if (status === 'approved' && paypalOrderId) {
            return createElement('div', { 
                style: { padding: '15px', backgroundColor: '#d4edda', border: '1px solid #c3e6cb', borderRadius: '4px', color: '#155724' } 
            }, 
                createElement('strong', null, '✓ PayPal payment authorized'),
                createElement('p', { style: { margin: '5px 0 0 0' } }, 'Completing your order...')
            );
        }

        return createElement('div', null,
            showDescription && createElement('p', null, decodeEntities(settings.description || '')),
            createElement('div', { 
                id: 'rpsfw-paypal-buttons', 
                ref: containerRef,
                style: { marginTop: '15px', minHeight: '45px' },
                className: 'rpsfw-paypal-buttons-wrapper'
            },
                !sdkReady ? createElement('div', { 
                    style: { padding: '10px', textAlign: 'center', color: '#666' } 
                }, 'Loading PayPal...') : null
            )
        );
    };

    registerPaymentMethod({
        name: 'rpsfw_paypal_commerce',
        label: createElement(Label, null),
        content: createElement(Content, null),
        edit: createElement('div', null, decodeEntities(settings.description || '')),
        canMakePayment: () => true,
        ariaLabel: title,
        // Custom Place Order button text for subscription carts (empty for
        // regular carts, which keeps the default label).
        placeOrderButtonLabel: settings.placeOrderButtonLabel || undefined,
        supports: { features: settings.supports || ['products'] },
    });
})();
