/**
 * PayPal Pay Later Messaging
 *
 * Handles rendering Pay Later messages on various pages.
 * 
 * IMPORTANT: Configuration is passed entirely via JavaScript (rpsfwPayLaterMessages global)
 * rather than HTML data attributes. This prevents PayPal SDK from auto-detecting and
 * auto-rendering messages with default styles before our code runs.
 * See PHP class WC_PayPal_Commerce_Pay_Later_Messaging for detailed explanation.
 *
 * @package RestorePayPalStandard
 */

(function($) {
    'use strict';

    var PayLaterMessaging = {
        maxRetries: 50,
        retryCount: 0,
        config: {},

        /**
         * Initialize Pay Later messaging.
         */
        init: function() {
            var self = this;

            // Get config from localized script data
            if (typeof rpsfwPayLaterMessages !== 'undefined') {
                self.config = rpsfwPayLaterMessages;
            }

            // Wait for PayPal SDK with Messages component
            if (typeof paypal === 'undefined' || typeof paypal.Messages === 'undefined') {
                self.retryCount++;
                if (self.retryCount < self.maxRetries) {
                    setTimeout(function() {
                        self.init();
                    }, 200);
                }
                return;
            }

            // Render all message containers
            self.renderAllMessages();

            // Set up observers for dynamic content
            self.setupObservers();
        },

        /**
         * Render all Pay Later message containers.
         */
        renderAllMessages: function() {
            var self = this;

            // Render messages from config (passed via wp_localize_script)
            if (self.config.messages) {
                Object.keys(self.config.messages).forEach(function(containerId) {
                    var container = document.getElementById(containerId);
                    if (container && !container.classList.contains('rendered')) {
                        self.renderMessage(container, self.config.messages[containerId]);
                    }
                });
            }

            // Also check for containers with inline config (e.g., mini cart loaded via AJAX)
            var inlineContainers = document.querySelectorAll('.rpsfw-paylater-message[data-paylater-config]:not(.rendered)');
            inlineContainers.forEach(function(container) {
                var configData = container.getAttribute('data-paylater-config');
                if (configData) {
                    try {
                        var msgConfig = JSON.parse(configData);
                        self.renderMessage(container, msgConfig);
                    } catch (e) {
                        // Invalid JSON, skip
                    }
                }
            });
        },

        /**
         * Render a single Pay Later message.
         *
         * Config is passed via JavaScript object (not HTML attributes) to prevent
         * PayPal SDK auto-rendering. See PHP class docblock for details.
         *
         * @param {HTMLElement} container The container element.
         * @param {Object} messageConfig Configuration for this message.
         */
        renderMessage: function(container, messageConfig) {
            var self = this;
            
            if (container.classList.contains('rendered')) {
                return;
            }

            if (!messageConfig || !messageConfig.amount || messageConfig.amount <= 0) {
                return;
            }

            var config = {
                amount: messageConfig.amount,
                currency: messageConfig.currency || self.config.currency,
                placement: messageConfig.placement || 'product',
                style: messageConfig.style || {}
            };

            try {
                paypal.Messages(config).render(container).then(function() {
                    container.classList.add('rendered');
                }).catch(function(err) {
                    // Silent fail - common reasons include the customer's
                    // region/currency not being eligible, or the cart amount
                    // being outside PayPal's supported range. PayPal's SDK
                    // already logs its own diagnostic warnings to the console
                    // for these cases, so we don't add another.
                });
            } catch (e) {
                // Silent fail
            }
        },

        /**
         * Update message amount for a location.
         *
         * @param {string} location The location identifier.
         * @param {number} amount   The new amount.
         */
        updateAmount: function(location, amount) {
            var self = this;

            // Find containers for this location and update them
            if (self.config.messages) {
                Object.keys(self.config.messages).forEach(function(containerId) {
                    var msgConfig = self.config.messages[containerId];
                    if (msgConfig.location === location) {
                        var container = document.getElementById(containerId);
                        if (container) {
                            // Update config and re-render
                            msgConfig.amount = amount;
                            container.classList.remove('rendered');
                            container.innerHTML = '';
                            self.renderMessage(container, msgConfig);
                        }
                    }
                });
            }
        },

        /**
         * Set up mutation observers for dynamic content.
         */
        setupObservers: function() {
            var self = this;

            // Watch for cart updates
            $(document.body).on('updated_cart_totals', function() {
                self.handleCartUpdate();
            });

            // Watch for checkout updates
            $(document.body).on('updated_checkout', function() {
                self.handleCheckoutUpdate();
            });

            // Watch for variation changes on product pages
            $('form.variations_form').on('found_variation', function(event, variation) {
                if (variation.display_price) {
                    self.updateAmount('product', variation.display_price);
                }
            });

            // Watch for quantity changes
            $(document).on('change', 'input.qty', function() {
                self.handleQuantityChange($(this));
            });

            // MutationObserver for dynamically added containers
            if (typeof MutationObserver !== 'undefined') {
                var observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        mutation.addedNodes.forEach(function(node) {
                            if (node.nodeType === 1) {
                                var newContainers = node.querySelectorAll ? 
                                    node.querySelectorAll('.rpsfw-paylater-message:not(.rendered)') : [];
                                newContainers.forEach(function(container) {
                                    // Check for inline config first (mini cart AJAX)
                                    var inlineConfig = container.getAttribute('data-paylater-config');
                                    if (inlineConfig) {
                                        try {
                                            var msgConfig = JSON.parse(inlineConfig);
                                            self.renderMessage(container, msgConfig);
                                        } catch (e) {
                                            // Invalid JSON
                                        }
                                    } else {
                                        // Fall back to global config
                                        var msgConfig = self.config.messages ? self.config.messages[container.id] : null;
                                        if (msgConfig) {
                                            self.renderMessage(container, msgConfig);
                                        }
                                    }
                                });
                            }
                        });
                    });
                });

                observer.observe(document.body, {
                    childList: true,
                    subtree: true
                });
            }
        },

        /**
         * Handle cart total updates.
         */
        handleCartUpdate: function() {
            var self = this;
            var cartTotal = self.getCartTotal();
            if (cartTotal > 0) {
                self.updateAmount('cart', cartTotal);
            }
        },

        /**
         * Handle checkout updates.
         */
        handleCheckoutUpdate: function() {
            var self = this;
            var checkoutTotal = self.getCheckoutTotal();
            if (checkoutTotal > 0) {
                self.updateAmount('checkout', checkoutTotal);
            }
        },

        /**
         * Handle quantity changes on product page.
         */
        handleQuantityChange: function($input) {
            var self = this;
            if (!$('body').hasClass('single-product')) {
                return;
            }

            var qty = parseInt($input.val()) || 1;
            var priceElement = $('.summary .price .amount').first();
            var basePrice = self.parsePrice(priceElement.text());
            
            if (basePrice > 0) {
                self.updateAmount('product', basePrice * qty);
            }
        },

        /**
         * Get cart total from page.
         */
        getCartTotal: function() {
            var self = this;
            var totalElement = $('.cart_totals .order-total .amount').first();
            if (!totalElement.length) {
                totalElement = $('.wc-block-components-totals-footer-item .wc-block-components-totals-item__value').first();
            }
            return self.parsePrice(totalElement.text());
        },

        /**
         * Get checkout total from page.
         */
        getCheckoutTotal: function() {
            var self = this;
            var totalElement = $('.order-total .amount').first();
            if (!totalElement.length) {
                totalElement = $('.wc-block-components-totals-footer-item .wc-block-components-totals-item__value').first();
            }
            return self.parsePrice(totalElement.text());
        },

        /**
         * Parse price from formatted string.
         */
        parsePrice: function(priceString) {
            if (!priceString) {
                return 0;
            }
            var cleanPrice = priceString.replace(/[^0-9.,]/g, '');
            
            if (cleanPrice.indexOf(',') !== -1 && cleanPrice.indexOf('.') !== -1) {
                if (cleanPrice.lastIndexOf(',') > cleanPrice.lastIndexOf('.')) {
                    cleanPrice = cleanPrice.replace(/\./g, '').replace(',', '.');
                } else {
                    cleanPrice = cleanPrice.replace(/,/g, '');
                }
            } else if (cleanPrice.indexOf(',') !== -1) {
                var parts = cleanPrice.split(',');
                if (parts.length === 2 && parts[1].length <= 2) {
                    cleanPrice = cleanPrice.replace(',', '.');
                } else {
                    cleanPrice = cleanPrice.replace(/,/g, '');
                }
            }

            return parseFloat(cleanPrice) || 0;
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        PayLaterMessaging.init();
    });

})(jQuery);
