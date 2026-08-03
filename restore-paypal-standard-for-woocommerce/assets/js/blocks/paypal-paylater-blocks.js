/**
 * PayPal Pay Later Messaging - WooCommerce Blocks Integration
 * 
 * Renders Pay Later messages in block-based cart and checkout.
 */
(function() {
    const { registerPlugin } = window.wp.plugins;
    const { createElement, useState, useEffect, useRef } = window.wp.element;
    const { getSetting } = window.wc.wcSettings;
    const { ExperimentalOrderMeta } = window.wc.blocksCheckout || {};

    // Get Pay Later settings
    const payLaterSettings = getSetting('rpsfw_paylater_data', {});
    
    if (!payLaterSettings.enabled) {
        return;
    }

    /**
     * Pay Later Message Component
     */
    const PayLaterMessage = ({ context }) => {
        const containerRef = useRef(null);
        const [rendered, setRendered] = useState(false);
        const cartTotals = window.wc?.wcBlocksData?.CART_STORE_KEY 
            ? window.wp.data.select(window.wc.wcBlocksData.CART_STORE_KEY)?.getCartTotals() 
            : null;

        useEffect(() => {
            if (rendered || !containerRef.current) return;

            // Wait for PayPal SDK
            const checkAndRender = () => {
                if (typeof paypal === 'undefined' || typeof paypal.Messages === 'undefined') {
                    return false;
                }

                const config = payLaterSettings[context];
                if (!config || !config.enabled) return true;

                // Get amount from cart totals
                let amount = 0;
                if (cartTotals && cartTotals.total_price) {
                    // WooCommerce stores prices in minor units (cents)
                    amount = parseInt(cartTotals.total_price, 10) / 100;
                }

                if (amount <= 0) return true;

                try {
                    paypal.Messages({
                        amount: amount,
                        currency: payLaterSettings.currency || 'USD',
                        placement: context === 'checkout' ? 'payment' : 'cart',
                        style: config.style || { layout: 'text' }
                    }).render(containerRef.current).then(() => {
                        setRendered(true);
                    }).catch(() => {
                        // Silent fail
                    });
                } catch (e) {
                    // Silent fail
                }

                return true;
            };

            if (!checkAndRender()) {
                // Poll for SDK
                let attempts = 0;
                const interval = setInterval(() => {
                    attempts++;
                    if (checkAndRender() || attempts >= 50) {
                        clearInterval(interval);
                    }
                }, 200);
            }
        }, [context, cartTotals, rendered]);

        // Re-render when cart totals change
        useEffect(() => {
            if (rendered && containerRef.current) {
                setRendered(false);
                containerRef.current.innerHTML = '';
            }
        }, [cartTotals?.total_price]);

        const config = payLaterSettings[context];
        if (!config || !config.enabled) {
            return null;
        }

        return createElement('div', {
            ref: containerRef,
            className: 'rpsfw-paylater-message rpsfw-paylater-blocks',
            style: { margin: '15px 0', padding: '0 15px', minHeight: '20px' }
        });
    };

    /**
     * Cart Block Pay Later Message
     */
    const CartPayLaterMessage = () => {
        if (!payLaterSettings.cart || !payLaterSettings.cart.enabled) {
            return null;
        }
        return createElement(PayLaterMessage, { context: 'cart' });
    };

    /**
     * Checkout Block Pay Later Message
     */
    const CheckoutPayLaterMessage = () => {
        if (!payLaterSettings.checkout || !payLaterSettings.checkout.enabled) {
            return null;
        }
        return createElement(PayLaterMessage, { context: 'checkout' });
    };

    // ExperimentalOrderMeta renders inside BOTH the Cart and Checkout blocks in
    // current WooCommerce, so this single registration shows the Pay Later
    // message on both. The cart block previously ALSO injected a second message
    // manually (#rpsfw-paylater-blocks-cart), which produced a duplicate on the
    // cart block — that manual injection has been removed.
    if (ExperimentalOrderMeta) {
        registerPlugin('rpsfw-paylater-checkout', {
            render: () => {
                return createElement(ExperimentalOrderMeta, null,
                    createElement(CheckoutPayLaterMessage, null)
                );
            },
            scope: 'woocommerce-checkout'
        });
    }

    // Mini cart support (blocks-based mini cart drawer)
    const injectMiniCartMessage = () => {
        // Don't inject if already exists
        if (document.getElementById('rpsfw-paylater-blocks-minicart')) {
            return;
        }

        // Get location setting (above_buttons or below_buttons)
        const location = payLaterSettings.minicart?.location || 'above_buttons';
        
        // Look for the mini cart drawer content
        const miniCartFooter = document.querySelector('.wc-block-mini-cart__footer-actions');
        const miniCartTotal = document.querySelector('.wc-block-mini-cart__footer .wc-block-components-totals-item');
        
        if (!miniCartFooter && !miniCartTotal) {
            return;
        }

        const container = document.createElement('div');
        container.id = 'rpsfw-paylater-blocks-minicart';
        container.className = 'rpsfw-paylater-message rpsfw-paylater-blocks rpsfw-paylater-minicart';
        container.style.cssText = 'margin: 10px 16px; padding: 0; text-align: center;';
        
        // Insert based on location setting
        if (location === 'below_buttons' && miniCartFooter) {
            // Insert after the footer buttons
            miniCartFooter.parentNode.insertBefore(container, miniCartFooter.nextSibling);
        } else if (miniCartTotal) {
            // Insert after the total (above buttons)
            miniCartTotal.parentNode.insertBefore(container, miniCartTotal.nextSibling);
        } else if (miniCartFooter) {
            // Fallback: insert before footer buttons
            miniCartFooter.parentNode.insertBefore(container, miniCartFooter);
        }
        
        // Use minicart settings
        const style = payLaterSettings.minicart?.style || { layout: 'text' };
        renderPayLaterMessage(container, 'cart', style);
    };

    // Helper function to render PayPal message
    const renderPayLaterMessage = (container, placement, style) => {
        const tryRender = () => {
            if (typeof paypal !== 'undefined' && typeof paypal.Messages !== 'undefined') {
                const cartData = window.wc?.wcBlocksData?.CART_STORE_KEY 
                    ? window.wp.data.select(window.wc.wcBlocksData.CART_STORE_KEY)?.getCartTotals() 
                    : null;
                
                let amount = 0;
                if (cartData && cartData.total_price) {
                    amount = parseInt(cartData.total_price, 10) / 100;
                }

                if (amount > 0) {
                    paypal.Messages({
                        amount: amount,
                        currency: payLaterSettings.currency || 'USD',
                        placement: placement,
                        style: style || { layout: 'text' }
                    }).render(container).catch(() => {});
                }
                return true;
            }
            return false;
        };

        if (!tryRender()) {
            let attempts = 0;
            const interval = setInterval(() => {
                attempts++;
                if (tryRender() || attempts >= 50) {
                    clearInterval(interval);
                }
            }, 200);
        }
    };

    // Observe for mini cart opening
    const miniCartObserver = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node.nodeType === 1) {
                    // Check if mini cart drawer was added
                    if (node.classList && (node.classList.contains('wc-block-mini-cart__drawer') || 
                        node.querySelector('.wc-block-mini-cart__drawer'))) {
                        setTimeout(injectMiniCartMessage, 100);
                    }
                }
            });
        });
    });
    miniCartObserver.observe(document.body, { childList: true, subtree: true });

    // Also try on page load in case mini cart is already open
    if (document.querySelector('.wc-block-mini-cart__drawer')) {
        injectMiniCartMessage();
    }
})();
