/**
 * PayPal Commerce Classic Checkout
 */
(function($) {
    'use strict';

    var paypalOrderId = null;
    var buttonsRendered = false;
    var buttonsInstances = [];
    // Set when we've already shown a specific checkout error (e.g. "please log
    // in") at the top of the page, so PayPal's generic onError alert is
    // suppressed for that rejection.
    var handledCheckoutError = false;

    // Build a request body from the current checkout form (which already uses
    // WooCommerce's field names) plus any extra params. Used so the server can
    // validate the checkout fields with WooCommerce's own validator before we
    // let PayPal open.
    function checkoutFormBody(extra) {
        var $form = $('form.checkout');
        var params = new URLSearchParams($form.length ? $form.serialize() : '');
        Object.keys(extra || {}).forEach(function(k) { params.set(k, extra[k]); });
        return params;
    }

    // Fetch fresh checkout nonces for the current session and update the
    // localized globals. Needed because a subscription cart forces account
    // registration, so an order-first payment attempt (e.g. Place Order on
    // Stripe) can create the account and log the shopper in mid-checkout,
    // invalidating the nonces minted on page load.
    function refreshCheckoutNonces() {
        return fetch(rpsfwPayPalCommerceCheckout.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ action: 'rpsfw_ppcp_refresh_nonce' })
        })
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res && res.success && res.data) {
                if (res.data.subscription_nonce) {
                    rpsfwPayPalCommerceCheckout.subscription_nonce = res.data.subscription_nonce;
                }
                if (res.data.create_nonce) {
                    rpsfwPayPalCommerceCheckout.create_nonce = res.data.create_nonce;
                }
            }
        })
        .catch(function() { /* best effort */ });
    }

    // POST a checkout action, transparently refreshing the nonce and retrying
    // ONCE if WordPress rejects a stale nonce (403 / body "-1"). Resolves to
    // the parsed JSON response.
    //
    // @param {object} extra    Body params (must include `action`).
    // @param {string} nonceKey Key on rpsfwPayPalCommerceCheckout holding the nonce.
    function postCheckoutAction(extra, nonceKey) {
        function doPost() {
            var body = checkoutFormBody(Object.assign({}, extra, { nonce: rpsfwPayPalCommerceCheckout[nonceKey] }));
            return fetch(rpsfwPayPalCommerceCheckout.ajax_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            }).then(function(r) {
                return r.text().then(function(text) {
                    var data;
                    try { data = JSON.parse(text); } catch (e) { data = text; }
                    return { ok: r.ok, status: r.status, data: data };
                });
            });
        }

        return doPost().then(function(res) {
            var nonceFailed = (res.status === 403) || res.data === -1 || res.data === '-1';
            if (nonceFailed) {
                return refreshCheckoutNonces().then(doPost).then(function(retry) { return retry.data; });
            }
            return res.data;
        });
    }

    function removeCheckoutError() {
        // Clear our own notice AND any notice left by the Stripe flow or
        // WooCommerce, so switching gateways never stacks two identical notices.
        $('.rpsfw-ppcp-error, .woocommerce-NoticeGroup-checkout, .woocommerce-error, .woocommerce-message').remove();
    }

    function showCheckoutError(messages) {
        removeCheckoutError();
        var html = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout rpsfw-ppcp-error">' +
            '<ul class="woocommerce-error" role="alert">';
        (messages || []).forEach(function(m) { html += '<li>' + m + '</li>'; });
        html += '</ul></div>';
        var $form = $('form.checkout');
        if ($form.length) {
            $form.prepend(html);
            $('html, body').animate({ scrollTop: Math.max(0, $form.offset().top - 100) }, 400);
        }
    }

    // Validate the checkout fields using WooCommerce's OWN front-end validation
    // — the same validation the native Place Order button uses. We trigger it
    // on the visible fields and then read WooCommerce's own invalid marker, so
    // WooCommerce (not this plugin) owns the field rules, highlights the
    // offending fields, and covers every field it renders (core + custom).
    // Returns true when the form is valid. Synchronous: WooCommerce's field
    // validation runs inline on the triggered events.
    function validateCheckoutFields() {
        var $form = $('form.checkout');
        if (!$form.length) {
            return true;
        }

        removeCheckoutError();

        // Terms & conditions checkbox (WooCommerce requires it to submit).
        var $terms = $('[name="terms"]').filter(':visible');
        if ($terms.length && !$terms.is(':checked')) {
            showCheckoutError([ 'Please read and accept the terms and conditions to proceed with your order.' ]);
            return false;
        }

        // Ask WooCommerce to validate its visible fields.
        $form.find('.input-text, select, input:checkbox').filter(':visible')
            .trigger('validate').trigger('blur');

        // WooCommerce marks invalid fields with .woocommerce-invalid on the row.
        var $invalid = $form.find('.form-row.woocommerce-invalid:visible');
        if ($invalid.length) {
            // Fields are already highlighted by WooCommerce; bring the first
            // one into view.
            try {
                $('html, body').animate({ scrollTop: Math.max(0, $invalid.first().offset().top - 120) }, 400);
            } catch (e) { /* ignore */ }
            return false;
        }

        return true;
    }

    function renderPayPalButtons() {
        if (buttonsRendered) return;
        if (typeof paypal === 'undefined' || typeof paypal.Buttons === 'undefined') {
            // SDK not loaded yet, wait
            setTimeout(renderPayPalButtons, 100);
            return;
        }

        var container = document.getElementById('paypal-button-container');
        if (!container) {
            return;
        }

        // Clear any existing content
        container.innerHTML = '';

        buttonsRendered = true;

        try {
            var isSubscription = !!rpsfwPayPalCommerceCheckout.is_subscription;
            var buttonsConfig = {
                style: { layout: 'vertical', color: 'gold', shape: 'rect', label: isSubscription ? 'subscribe' : 'paypal' },

                // Gate every funding button (PayPal, Pay Later, Card, Venmo):
                // validate the checkout fields BEFORE PayPal opens, and reject
                // the click if the form is incomplete so no payment is taken.
                onClick: function(data, actions) {
                    // Gate every funding button using WooCommerce's own field
                    // validation; reject the click if the form isn't valid so
                    // PayPal never opens.
                    return validateCheckoutFields() ? actions.resolve() : actions.reject();
                },

                onCancel: function() {
                    // Payment cancelled
                },

                onError: function(err) {
                    // Ignore zoid errors - they happen during page transitions
                    if (err && err.message && err.message.indexOf('zoid') !== -1) {
                        return;
                    }
                    // We already surfaced a specific message at the top of the
                    // checkout (e.g. "please log in") — don't also show the
                    // generic popup.
                    if (handledCheckoutError) {
                        handledCheckoutError = false;
                        return;
                    }
                    alert('PayPal error. Please try again.');
                }
            };

            if (isSubscription) {
                buttonsConfig.createSubscription = function() {
                    return postCheckoutAction({
                        action: 'rpsfw_ppcp_create_subscription',
                        rpsfw_validate_fields: '1'
                    }, 'subscription_nonce')
                    .then(function(data) {
                        if (data && data.success && data.data && data.data.subscription_id) {
                            return data.data.subscription_id;
                        }
                        // Show the server's message (e.g. "please log in") as a
                        // notice at the top of the checkout, and suppress the
                        // generic PayPal error popup for this rejection.
                        var msg = data.data && data.data.message ? data.data.message : 'Failed to start the subscription. Please try again.';
                        showCheckoutError([ msg ]);
                        handledCheckoutError = true;
                        throw new Error(msg);
                    });
                };

                buttonsConfig.onApprove = function(data) {
                    paypalOrderId = data.subscriptionID || data.orderID;

                    var $form = $('form.checkout');
                    if ($form.find('input[name="rpsfw_ppcp_subscription_id"]').length === 0) {
                        $form.append('<input type="hidden" name="rpsfw_ppcp_subscription_id" value="' + paypalOrderId + '">');
                    } else {
                        $form.find('input[name="rpsfw_ppcp_subscription_id"]').val(paypalOrderId);
                    }
                    // Also set legacy hidden field so the place-order block does not refuse.
                    $('#paypal-order-id').val(paypalOrderId);
                    if ($form.find('input[name="paypal_order_id"]').length === 0) {
                        $form.append('<input type="hidden" name="paypal_order_id" value="' + paypalOrderId + '">');
                    } else {
                        $form.find('input[name="paypal_order_id"]').val(paypalOrderId);
                    }

                    $('#paypal-button-container').html('<div style="padding:15px;background:#d4edda;border:1px solid #c3e6cb;border-radius:4px;color:#155724;"><strong>✓ PayPal subscription approved</strong><br>Submitting order...</div>');
                    $form.submit();
                };
            } else {
                buttonsConfig.createOrder = function() {
                    return postCheckoutAction({
                        action: 'rpsfw_ppcp_create_order',
                        rpsfw_validate_fields: '1'
                    }, 'create_nonce')
                    .then(function(data) {
                        if (data && data.success && data.data && data.data.order_id) {
                            return data.data.order_id;
                        }
                        // Show the server's message as a notice at the top of
                        // the checkout, and suppress the generic PayPal error
                        // popup for this rejection.
                        var msg = data.data && data.data.message ? data.data.message : 'Failed to start the order. Please try again.';
                        showCheckoutError([ msg ]);
                        handledCheckoutError = true;
                        throw new Error(msg);
                    });
                };

                buttonsConfig.onApprove = function(data) {
                    paypalOrderId = data.orderID;

                    return fetch(rpsfwPayPalCommerceCheckout.ajax_url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            action: 'rpsfw_ppcp_approve_order',
                            nonce: rpsfwPayPalCommerceCheckout.create_nonce,
                            paypal_order_id: data.orderID
                        })
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(result) {
                        $('#paypal-order-id').val(data.orderID);

                        var $form = $('form.checkout');
                        if ($form.find('input[name="paypal_order_id"]').length === 0) {
                            $form.append('<input type="hidden" name="paypal_order_id" value="' + data.orderID + '">');
                        } else {
                            $form.find('input[name="paypal_order_id"]').val(data.orderID);
                        }

                        $('#paypal-button-container').html('<div style="padding:15px;background:#d4edda;border:1px solid #c3e6cb;border-radius:4px;color:#155724;"><strong>✓ PayPal authorized</strong><br>Submitting order...</div>');
                        $form.submit();
                    });
                };
            }

            buttonsInstances = [];

            var funding = (rpsfwPayPalCommerceCheckout.funding) || {};
            var FUNDING = paypal.FUNDING || {};

            // Render one button per ENABLED + ELIGIBLE funding source, in the
            // merchant's configured order (Appearance tab). isEligible() then
            // decides what actually renders per mode.
            var map = {
                paypal:   FUNDING.PAYPAL,
                paylater: FUNDING.PAYLATER,
                card:     FUNDING.CARD,
                venmo:    FUNDING.VENMO
            };
            var order = (funding.order && funding.order.length) ? funding.order : ['paypal', 'paylater', 'card', 'venmo'];

            var sources = [];
            order.forEach(function(token) {
                if (token === 'paypal' && funding.paypal === false) { return; }
                if (token === 'paylater' && !funding.paylater) { return; }
                if (token === 'card' && !funding.card) { return; }
                if (token === 'venmo' && !funding.venmo) { return; }
                if (map[token]) { sources.push(map[token]); }
            });

            sources.forEach(function(source) {
                if (!source) { return; }

                var cfg = $.extend(true, {}, buttonsConfig, { fundingSource: source });
                if ((source === FUNDING.CARD || source === FUNDING.VENMO) && cfg.style) {
                    delete cfg.style.label;
                    delete cfg.style.color;
                } else if (source !== FUNDING.PAYPAL && cfg.style) {
                    delete cfg.style.label;
                }

                var instance;
                try {
                    instance = paypal.Buttons(cfg);
                } catch (e) {
                    return;
                }

                if (!instance.isEligible()) { return; }

                buttonsInstances.push(instance);
                instance.render('#paypal-button-container').catch(function(err) {
                    // Ignore zoid errors during render
                    if (err && err.message && err.message.indexOf('zoid') === -1) {
                        console.error('PayPal render error:', err);
                    }
                });
            });
        } catch (err) {
            // Ignore zoid errors
            if (err && err.message && err.message.indexOf('zoid') === -1) {
                console.error('PayPal setup error:', err);
            }
        }
    }

    function closeButtons() {
        if (buttonsInstances && buttonsInstances.length) {
            buttonsInstances.forEach(function(instance) {
                if (instance && typeof instance.close === 'function') {
                    try {
                        instance.close();
                    } catch (e) {
                        // Ignore close errors
                    }
                }
            });
        }
        buttonsInstances = [];
        buttonsRendered = false;
    }

    function toggleButtons() {
        var selected = $('input[name="payment_method"]:checked').val();
        var $placeOrder = $('#place_order');
        
        if (selected === rpsfwPayPalCommerceCheckout.gateway_id) {
            $placeOrder.hide();
            if (!buttonsRendered) {
                setTimeout(renderPayPalButtons, 100);
            }
        } else {
            $placeOrder.show();
        }
    }

    $(document).ready(function() {
        if (typeof rpsfwPayPalCommerceCheckout === 'undefined') {
            return;
        }

        toggleButtons();

        $(document.body).on('change', 'input[name="payment_method"]', function() {
            // Close existing buttons before switching
            closeButtons();
            toggleButtons();
        });

        // Re-evaluate the cart after it changes and re-render the PayPal
        // buttons. Shared by `updated_checkout` (checkout-native updates) and
        // `wc_fragments_refreshed` (mini/side-cart edits, which re-render the
        // checkout HTML and wipe the button container but don't always fire
        // `updated_checkout`). Re-checks subscription status because the
        // server-side flag and the loaded PayPal SDK mode are fixed at page
        // load and go stale when items change; if the flow flipped
        // (subscription <-> one-off) we reload so the SDK re-initializes.
        function handlePayPalCartChange() {
            var container = document.getElementById('paypal-button-container');

            // WooCommerce re-renders the checkout review/payment area on updates
            // (e.g. after an address edit that recalculates shipping), which can
            // wipe our button container. Only rebuild when the buttons were
            // ACTUALLY removed (no rendered iframe). A simple address edit that
            // leaves the buttons in place needs no rebuild — the order amount is
            // computed server-side at create-order time and the button callbacks
            // read the latest field values on click — so re-rendering there just
            // made the buttons visibly flicker (disappear then reappear).
            var buttonsWiped = !container || container.innerHTML === '' || container.querySelector('iframe') === null;

            if (buttonsWiped) {
                buttonsRendered = false;
                closeButtons();
                toggleButtons();
            }

            // Separately, detect a subscription <-> one-off flip (cart items
            // changed) and reload so the PayPal SDK re-initializes with the
            // correct intent. This does not touch the buttons otherwise.
            $.ajax({
                url: rpsfwPayPalCommerceCheckout.ajax_url,
                type: 'POST',
                data: { action: 'rpsfw_ppcp_cart_is_subscription' },
                success: function(response) {
                    if (response.success) {
                        var wasSubscription = !!rpsfwPayPalCommerceCheckout.is_subscription;
                        var isNow = !!response.data.is_subscription;
                        if (wasSubscription !== isNow) {
                            // SDK was loaded in the wrong mode — reload the page
                            // so the SDK is re-enqueued with correct parameters.
                            window.location.reload();
                        } else {
                            rpsfwPayPalCommerceCheckout.is_subscription = isNow;
                        }
                    }
                }
            });
        }

        $(document.body).on('updated_checkout', handlePayPalCartChange);

        // Mini-cart / side-cart edits refresh WooCommerce fragments, which
        // re-render the checkout and wipe the PayPal button container without
        // firing `updated_checkout`. Re-render when that happens so the buttons
        // don't disappear until a manual refresh.
        $(document.body).on('wc_fragments_refreshed wc_fragments_loaded', function() {
            var container = document.getElementById('paypal-button-container');
            if (!container) {
                return;
            }
            // Re-render only if the button area was wiped (no rendered iframe).
            if (container.innerHTML === '' || container.querySelector('iframe') === null) {
                handlePayPalCartChange();
            }
        });

        // Block form submission if no PayPal order/subscription was approved.
        $(document.body).on('checkout_place_order_rpsfw_paypal_commerce', function() {
            if (!paypalOrderId) {
                return false;
            }
            return true;
        });
    });

})(jQuery);
