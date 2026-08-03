/**
 * PayPal Commerce Cart Buttons
 *
 * Two flows:
 *  - One-off carts: createOrder → onApprove → process_cart_payment
 *    (creates a WC order, captures the PayPal order, redirects to
 *    order-received).
 *  - Subscription carts: createSubscription → onApprove →
 *    process_cart_subscription (creates the PayPal Subscription via the
 *    subscriptions integration's existing AJAX endpoint, then on
 *    approval creates a WC order, finalizes the subscription, and
 *    redirects to order-received).
 *
 * Subscription support requires WooCommerce Subscriptions to be active
 * and the SDK to be loaded with intent=subscription (which the
 * integration toggles via the rpsfw_ppcp_sdk_args filter).
 *
 * When the last subscription product is removed from the cart the SDK
 * is dynamically reloaded with intent=capture (no page refresh needed).
 * This mirrors the approach used by pymntpl-paypal-woocommerce: remove
 * the existing SDK <script> tag, inject a new one with the updated URL,
 * and re-render the buttons once the new SDK has loaded.
 */
(function($) {
    'use strict';

    var PayPalCommerceCart = {
        /**
         * Initialize the cart buttons.
         */
        init: function() {
            if (typeof paypal === 'undefined') {
                console.error('PayPal SDK not loaded');
                return;
            }

            // Only render at page load when the server left the button area
            // visible. The server's render-time shipping check is
            // authoritative; the localized is_eligible flag is computed earlier
            // (wp_enqueue_scripts), before shipping is fully settled, so we
            // trust the DOM here rather than that flag. Show/hide on later cart
            // changes is handled by the updated_cart_totals refresh, which does
            // a fresh server-side eligibility check.
            var $wrap = $('.rpsfw-ppcp-cart-buttons');
            if ($wrap.length && $wrap.css('display') !== 'none') {
                this.renderButtons();
            }

            this.bindEvents();
        },

        /**
         * True when the cart contains a subscription.
         */
        isSubscription: function() {
            return !!(rpsfwPayPalCommerceCart && rpsfwPayPalCommerceCart.is_subscription);
        },

        /**
         * True when the cart is currently eligible for the express button
         * (i.e. it does not require shipping). Re-checked on every cart update.
         */
        isEligible: function() {
            return !(rpsfwPayPalCommerceCart && rpsfwPayPalCommerceCart.is_eligible === false);
        },

        /**
         * Render PayPal buttons. Renders into the container only; visibility of
         * the surrounding .rpsfw-ppcp-cart-buttons wrapper is controlled by the
         * server at render time and by the updated_cart_totals refresh, not
         * here, so we never override a correct server-rendered hidden state.
         */
        renderButtons: function() {
            var self = this;

            // Never render (and hide the whole wrapper, including the "or"
            // separator) when the cart isn't eligible — e.g. it needs a
            // shipping address, which express checkout can't collect before
            // approval. Guarding here means every caller (init, cart-update
            // refresh, and the error/fallback branches) respects eligibility,
            // so the "or" separator never appears without buttons beneath it.
            if (!self.isEligible()) {
                self.clearButtons();
                $('.rpsfw-ppcp-cart-buttons').hide();
                return;
            }

            var container = document.getElementById('rpsfw-paypal-cart-button-container');

            if (!container) {
                return;
            }

            // Clear existing content
            container.innerHTML = '';

            var isSub = this.isSubscription();

            var config = {
                style: {
                    layout: 'vertical',
                    color: 'gold',
                    shape: 'rect',
                    label: isSub ? 'subscribe' : 'paypal'
                },

                /**
                 * Handle cancellation.
                 */
                onCancel: function(data) {
                    // Payment cancelled - do nothing
                },

                /**
                 * Handle errors.
                 */
                onError: function(err) {
                    console.error('PayPal error:', err);
                    self.showError(err && err.message ? err.message : 'An error occurred with PayPal. Please try again.');
                }
            };

            if (isSub) {
                config.createSubscription = function(data, actions) {
                    return self.createSubscription();
                };
                config.onApprove = function(data, actions) {
                    return self.onApproveSubscription(data);
                };
            } else {
                config.createOrder = function(data, actions) {
                    return self.createOrder();
                };
                config.onApprove = function(data, actions) {
                    return self.onApproveOrder(data);
                };
            }

            self.renderFundingSources(config, isSub, '#rpsfw-paypal-cart-button-container');
        },

        /**
         * Render one PayPal Buttons instance per ENABLED + ELIGIBLE funding
         * source instead of relying on the SDK `disable-funding` parameter.
         *
         * The SDK's disable-funding list is unreliable for hiding individual
         * buttons (the standalone "Debit or Credit Card" button in particular
         * often still renders). Explicitly creating a button for each desired
         * funding source — and skipping the rest entirely — guarantees that a
         * funding source disabled in the settings never appears. Each button
         * is gated with isEligible() so ineligible sources (e.g. Venmo outside
         * the US) are silently skipped.
         *
         * @param {object} baseConfig Shared Buttons config (callbacks, style).
         * @param {boolean} isSub      Whether the cart is a subscription cart.
         * @param {string} selector    Container selector to render into.
         */
        renderFundingSources: function(baseConfig, isSub, selector) {
            var funding = (typeof rpsfwPayPalCommerceCart !== 'undefined' && rpsfwPayPalCommerceCart.funding) || {};
            var FUNDING = paypal.FUNDING || {};

            // Build the list of funding sources to render, in the merchant's
            // configured order (Appearance tab), filtered by which are enabled.
            // PayPal's isEligible() then decides which actually render: the
            // Card button can drive createSubscription when the account
            // supports it, while Venmo/Pay Later are filtered out in
            // subscription mode.
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

                var cfg = $.extend(true, {}, baseConfig, { fundingSource: source });

                // The PayPal/Pay Later buttons accept color 'gold' and a
                // 'label'. The standalone Card and Venmo buttons do NOT:
                // Card only allows black/white, Venmo blue/silver/black/white,
                // and neither accepts 'label'. Passing 'gold'/label to them
                // throws "Unexpected style.color". Drop those keys so the SDK
                // uses each source's valid defaults.
                if ((source === FUNDING.CARD || source === FUNDING.VENMO) && cfg.style) {
                    delete cfg.style.label;
                    delete cfg.style.color;
                } else if (source !== FUNDING.PAYPAL && cfg.style) {
                    delete cfg.style.label;
                }

                var button;
                try {
                    button = paypal.Buttons(cfg);
                } catch (e) {
                    return;
                }

                if (button.isEligible()) {
                    button.render(selector).catch(function() {});
                }
            });
        },

        /**
         * Bind events for cart updates.
         */
        bindEvents: function() {
            var self = this;

            // WooCommerce blocks the cart-totals area while it recalculates,
            // then fires `updated_cart_totals` AFTER it has rebuilt and
            // unblocked that DOM. By that point our button container has been
            // re-created with the OLD (possibly stale) markup. To avoid the
            // flash of a stale "Subscribe" button we immediately clear the
            // container and re-block it with WooCommerce's native loader, then
            // keep it blocked until the correct buttons are ready.
            $(document.body).on('updated_cart_totals', function() {
                self.refreshSubscriptionStatus();
            });
        },

        /**
         * Ask the server whether the current cart contains a subscription.
         *
         * If the SDK was in subscription mode but the last subscription
         * product has been removed, we need to reload the PayPal SDK with
         * intent=capture instead of intent=subscription. We do this by:
         *
         *  1. Finding and removing the existing PayPal SDK <script> tag.
         *  2. Injecting a new <script> tag pointing to the one-off SDK URL
         *     (returned in the SAME AJAX response, so no second round-trip).
         *  3. Waiting for the new SDK to load, then re-rendering the buttons.
         *
         * This is the same technique used by pymntpl-paypal-woocommerce:
         * their `loadPayPalSdk` util removes the old script and injects a
         * new one whenever `queryParams` change, avoiding a full page reload.
         *
         * If the state has NOT changed (still a subscription, or still
         * one-off) we simply re-render the buttons with the existing SDK.
         *
         * The container is blocked with WooCommerce's native loader for the
         * whole duration so the customer never sees an intermediate or stale
         * state.
         */
        refreshSubscriptionStatus: function() {
            var self = this;

            // Clear any stale buttons immediately and block the area with
            // WooCommerce's native loader before doing anything async, so
            // there is no visible gap after WC unblocks the cart totals.
            self.clearButtons();
            self.showLoading();

            $.ajax({
                url: rpsfwPayPalCommerceCart.ajax_url,
                type: 'POST',
                data: {
                    action: 'rpsfw_ppcp_cart_is_subscription'
                },
                success: function(response) {
                    if (!response.success) {
                        self.hideLoading();
                        self.renderButtons();
                        return;
                    }

                    // Update shipping eligibility first. If the cart now
                    // requires shipping, hide the express area entirely and
                    // stop — express is only offered for no-shipping carts.
                    // If it has just become eligible (shippable item removed),
                    // fall through and render the buttons.
                    rpsfwPayPalCommerceCart.is_eligible = !!response.data.is_eligible;
                    if (!rpsfwPayPalCommerceCart.is_eligible) {
                        self.clearButtons();
                        self.hideLoading();
                        $('.rpsfw-ppcp-cart-buttons').hide();
                        return;
                    }
                    $('.rpsfw-ppcp-cart-buttons').show();

                    var wasSubscription = !!rpsfwPayPalCommerceCart.is_subscription;
                    var isNow = !!response.data.is_subscription;

                    // State unchanged, OR a transition that does not require
                    // an SDK reload (non-sub → sub, which WC handles via a
                    // full fragment refresh) — just re-render with the
                    // existing SDK.
                    if (wasSubscription === isNow || !wasSubscription || isNow) {
                        rpsfwPayPalCommerceCart.is_subscription = isNow;
                        self.hideLoading();
                        self.renderButtons();
                        return;
                    }

                    // wasSubscription && !isNow: reload the SDK in one-off
                    // mode. Keep the loader up until the SDK has reloaded and
                    // the buttons are re-rendered. The SDK URL came back in
                    // this same response, so there is no second AJAX call.
                    rpsfwPayPalCommerceCart.is_subscription = false;

                    if (!response.data.sdk_url) {
                        // No URL available — fall back to a full reload.
                        window.location.reload();
                        return;
                    }

                    self.reloadSdk(response.data.sdk_url);
                },
                error: function() {
                    self.hideLoading();
                    self.renderButtons();
                }
            });
        },

        /**
         * Clear the button container's PayPal buttons without removing the
         * loader overlay (when blockUI is used the overlay is a sibling, so
         * emptying innerHTML is safe; with blockUI the .blockUI nodes live
         * outside the cleared markup).
         */
        clearButtons: function() {
            var container = document.getElementById('rpsfw-paypal-cart-button-container');
            if (container) {
                // Remove only the PayPal button iframes/markup, leaving any
                // blockUI overlay nodes in place.
                $(container).children().not('.blockUI').remove();
            }
        },

        /**
         * Block the button container with WooCommerce's native blockUI
         * loader (the same spinner WooCommerce uses elsewhere) so the
         * loading state is visually consistent with the rest of the cart.
         */
        showLoading: function() {
            var $container = $('#rpsfw-paypal-cart-button-container');
            if (!$container.length) {
                return;
            }

            // Reserve height so the loader has something to cover even when
            // the buttons have been cleared.
            $container.css('min-height', '45px');

            if ($.fn.block) {
                // Avoid stacking multiple blocks.
                $container.unblock();
                $container.block({
                    message: null,
                    overlayCSS: {
                        background: '#fff',
                        opacity: 0.6
                    }
                });
            } else {
                // Fallback CSS spinner if blockUI isn't available.
                if ($container.css('position') === 'static') {
                    $container.css('position', 'relative');
                }
                if (!$container.find('.rpsfw-ppcp-cart-loading').length) {
                    $container.append(
                        '<div class="rpsfw-ppcp-cart-loading" style="' +
                        'position:absolute;top:0;left:0;right:0;bottom:0;' +
                        'display:flex;align-items:center;justify-content:center;' +
                        'background:rgba(255,255,255,0.6);z-index:10;">' +
                        '<span style="display:inline-block;width:28px;height:28px;' +
                        'border:3px solid #ccc;border-top-color:#0070ba;border-radius:50%;' +
                        'animation:rpsfw-ppcp-spin 0.8s linear infinite;"></span></div>'
                    );
                    if (!document.getElementById('rpsfw-ppcp-cart-spinner-style')) {
                        var style = document.createElement('style');
                        style.id = 'rpsfw-ppcp-cart-spinner-style';
                        style.textContent = '@keyframes rpsfw-ppcp-spin{to{transform:rotate(360deg);}}';
                        document.head.appendChild(style);
                    }
                }
            }
        },

        /**
         * Remove the loading state.
         */
        hideLoading: function() {
            var $container = $('#rpsfw-paypal-cart-button-container');
            if (!$container.length) {
                return;
            }
            if ($.fn.unblock) {
                $container.unblock();
            }
            $container.find('.rpsfw-ppcp-cart-loading').remove();
        },

        /**
         * Replace the PayPal SDK script tag with a new one pointing to
         * `newSdkUrl`, then re-render the buttons once the SDK has loaded.
         *
         * @param {string} newSdkUrl Full https://www.paypal.com/sdk/js?... URL
         */
        reloadSdk: function(newSdkUrl) {
            var self = this;

            // Remove every existing PayPal SDK script tag (there should only
            // be one, but guard against duplicates from earlier hot-reloads).
            var existing = document.querySelectorAll('script[src*="paypal.com/sdk/js"]');
            existing.forEach(function(el) {
                el.parentNode.removeChild(el);
            });

            // Clear the window.paypal namespace so stale button instances
            // from the previous SDK session can't interfere.
            try {
                delete window.paypal;
            } catch (e) {
                window.paypal = undefined;
            }

            // Inject the new SDK script. PayPal's SDK attaches the global
            // asynchronously after the script has loaded, so we need to
            // poll for it rather than checking immediately in onload.
            var script = document.createElement('script');
            script.src = newSdkUrl;
            script.async = true;

            script.onload = function() {
                // Poll for window.paypal to become available. The SDK file
                // has loaded but its internal bootstrap happens async, so
                // the global may not be ready yet. Poll every 50ms for up
                // to 5 seconds before falling back to a full page reload.
                self.pollForPayPalGlobal(5000);
            };

            script.onerror = function() {
                // If the SDK script fails to load (network error, 404, etc.)
                // a full page reload is the safest recovery.
                window.location.reload();
            };

            document.head.appendChild(script);
        },

        /**
         * Poll for window.paypal to become available after the SDK script
         * has loaded. Fires renderButtons() as soon as it's ready, or
         * falls back to a full page reload if it doesn't appear within the
         * timeout.
         *
         * @param {number} timeout_ms Milliseconds to wait before giving up.
         */
        pollForPayPalGlobal: function(timeout_ms) {
            var self = this;
            var elapsed = 0;
            var interval = 250; // Check every 250ms

            var poll = setInterval(function() {
                elapsed += interval;

                if (typeof window.paypal !== 'undefined') {
                    clearInterval(poll);
                    self.hideLoading();
                    self.renderButtons();
                    return;
                }

                if (elapsed >= timeout_ms) {
                    // Timeout: the PayPal global never appeared. Fall back
                    // to a full page reload so the customer sees correct buttons.
                    clearInterval(poll);
                    window.location.reload();
                }
            }, interval);
        },

        /**
         * Create PayPal order via AJAX (one-off carts).
         */
        createOrder: function() {
            return new Promise(function(resolve, reject) {
                $.ajax({
                    url: rpsfwPayPalCommerceCart.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'rpsfw_ppcp_create_order',
                        nonce: rpsfwPayPalCommerceCart.create_nonce,
                        context: 'cart'
                    },
                    success: function(response) {
                        if (response.success && response.data.order_id) {
                            resolve(response.data.order_id);
                        } else {
                            var message = response.data && response.data.message
                                ? response.data.message
                                : 'Failed to create PayPal order';
                            reject(new Error(message));
                        }
                    },
                    error: function(xhr, status, error) {
                        reject(new Error('Failed to create PayPal order: ' + error));
                    }
                });
            });
        },

        /**
         * Create PayPal Subscription via AJAX (subscription carts).
         * Reuses the subscriptions integration's existing endpoint that
         * the checkout page also uses.
         */
        createSubscription: function() {
            return new Promise(function(resolve, reject) {
                $.ajax({
                    url: rpsfwPayPalCommerceCart.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'rpsfw_ppcp_create_subscription',
                        nonce: rpsfwPayPalCommerceCart.subscription_nonce
                    },
                    success: function(response) {
                        if (response.success && response.data.subscription_id) {
                            resolve(response.data.subscription_id);
                        } else {
                            var message = response.data && response.data.message
                                ? response.data.message
                                : 'Failed to create PayPal subscription';
                            reject(new Error(message));
                        }
                    },
                    error: function(xhr, status, error) {
                        reject(new Error('Failed to create PayPal subscription: ' + error));
                    }
                });
            });
        },

        /**
         * Handle PayPal approval for a one-off order: create WC order
         * and capture payment.
         */
        onApproveOrder: function(data) {
            var self = this;

            self.showProcessing();

            return new Promise(function(resolve, reject) {
                $.ajax({
                    url: rpsfwPayPalCommerceCart.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'rpsfw_ppcp_process_cart_payment',
                        nonce: rpsfwPayPalCommerceCart.create_nonce,
                        paypal_order_id: data.orderID
                    },
                    success: function(response) {
                        if (response.success && response.data.redirect) {
                            window.location.href = response.data.redirect;
                        } else {
                            var message = response.data && response.data.message
                                ? response.data.message
                                : 'Failed to process payment';
                            self.hideProcessing();
                            self.showError(message);
                            reject(new Error(message));
                        }
                    },
                    error: function(xhr, status, error) {
                        self.hideProcessing();
                        self.showError('Failed to process payment: ' + error);
                        reject(new Error(error));
                    }
                });
            });
        },

        /**
         * Handle PayPal approval for a subscription cart: create WC
         * order, finalize the PayPal subscription, redirect.
         */
        onApproveSubscription: function(data) {
            var self = this;

            self.showProcessing();

            // PayPal returns the subscription id as data.subscriptionID
            // when intent=subscription. Pass it explicitly so the server
            // does not have to rely on session alone.
            var subscriptionId = data.subscriptionID || data.orderID || '';

            return new Promise(function(resolve, reject) {
                $.ajax({
                    url: rpsfwPayPalCommerceCart.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'rpsfw_ppcp_process_cart_subscription',
                        nonce: rpsfwPayPalCommerceCart.subscription_nonce,
                        paypal_subscription_id: subscriptionId
                    },
                    success: function(response) {
                        if (response.success && response.data.redirect) {
                            window.location.href = response.data.redirect;
                        } else {
                            var message = response.data && response.data.message
                                ? response.data.message
                                : 'Failed to finalize subscription';
                            self.hideProcessing();
                            self.showError(message);
                            reject(new Error(message));
                        }
                    },
                    error: function(xhr, status, error) {
                        self.hideProcessing();
                        self.showError('Failed to finalize subscription: ' + error);
                        reject(new Error(error));
                    }
                });
            });
        },

        /**
         * Show processing state.
         */
        showProcessing: function() {
            var $container = $('#rpsfw-paypal-cart-button-container');
            var processingText = rpsfwPayPalCommerceCart.processing_text || 'PayPal authorized. Processing your order...';
            $container.html('<div style="padding:15px;background:#d4edda;border:1px solid #c3e6cb;border-radius:4px;color:#155724;text-align:center;"><strong>✓ ' + processingText + '</strong></div>');
        },

        /**
         * Hide processing state.
         */
        hideProcessing: function() {
            this.renderButtons();
        },

        /**
         * Show error message.
         */
        showError: function(message) {
            var $container = $('.rpsfw-ppcp-cart-buttons');

            // Remove existing errors
            $container.find('.woocommerce-error').remove();

            // Add error message
            $container.prepend(
                '<div class="woocommerce-error" role="alert" style="margin-bottom:15px;">' + message + '</div>'
            );

            // Scroll to error
            $('html, body').animate({
                scrollTop: $container.offset().top - 100
            }, 500);
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        if (typeof rpsfwPayPalCommerceCart !== 'undefined') {
            PayPalCommerceCart.init();
        }
    });

})(jQuery);
