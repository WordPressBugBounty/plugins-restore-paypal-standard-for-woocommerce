/**
 * Stripe Admin JavaScript
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        
        /**
         * Initialize Payment Options accordions
         */
        function initPaymentOptionsAccordions() {
            // Find all payment method section titles (h3 elements)
            var sectionTitles = [
                'woocommerce_rpsfw_stripe_card_section',
                'woocommerce_rpsfw_stripe_wallets_section',
                'woocommerce_rpsfw_stripe_bnpl_section',
                'woocommerce_rpsfw_stripe_bank_section',
                'woocommerce_rpsfw_stripe_regional_section'
            ];
            
            sectionTitles.forEach(function(titleId) {
                var $title = $('#' + titleId);
                
                if ($title.length) {
                    // Make the h3 clickable and add icon
                    $title.addClass('rpsfw-payment-accordion-title collapsed');
                    $title.css('cursor', 'pointer');
                    
                    if (!$title.find('.rpsfw-accordion-icon').length) {
                        $title.append('<span class="rpsfw-accordion-icon dashicons dashicons-arrow-down-alt2"></span>');
                    }
                    
                    // Find the next table.form-table (contains the settings for this section)
                    var $nextTable = $title.nextAll('table.form-table').first();
                    
                    if ($nextTable.length) {
                        // Wrap the table in a container for smooth animation
                        if (!$nextTable.parent().hasClass('rpsfw-accordion-wrapper')) {
                            $nextTable.wrap('<div class="rpsfw-accordion-wrapper"></div>');
                        }
                        var $wrapper = $nextTable.parent('.rpsfw-accordion-wrapper');
                        
                        // Hide the wrapper by default
                        $wrapper.hide();
                        
                        // Add click handler to the h3
                        $title.off('click').on('click', function(e) {
                            var $this = $(this);
                            var $wrapper = $this.nextAll('.rpsfw-accordion-wrapper').first();
                            var $icon = $this.find('.rpsfw-accordion-icon');
                            
                            // Toggle the wrapper with smooth animation
                            $wrapper.slideToggle(300);
                            
                            // Toggle icon and class
                            $this.toggleClass('collapsed');
                            if ($this.hasClass('collapsed')) {
                                $icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
                            } else {
                                $icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
                            }
                        });
                    }
                }
            });
        }
        
        // Initialize accordions on Payment Options tab
        if ($('input[name="woocommerce_rpsfw_stripe_enable_card"]').length) {
            initPaymentOptionsAccordions();
        }
        
        /**
         * Toggle custom appearance fields
         */
        function toggleCustomFields() {
            var isChecked = $('#woocommerce_rpsfw_stripe_customize_appearance').is(':checked');
            
            if (isChecked) {
                $('.rpsfw-stripe-custom-field').closest('tr').show();
            } else {
                $('.rpsfw-stripe-custom-field').closest('tr').hide();
            }
        }
        
        // Initialize on page load
        toggleCustomFields();
        
        // Toggle when checkbox changes
        $(document).on('change', '#woocommerce_rpsfw_stripe_customize_appearance', function() {
            toggleCustomFields();
        });
        
        /**
         * Validate statement descriptor
         */
        function validateStatementDescriptor(value) {
            var errors = [];
            
            // Check length (max 22 characters)
            if (value.length > 22) {
                errors.push('Statement Descriptor must be 22 characters or less.');
            }
            
            // Check for invalid characters: >, <, ", ', *
            var invalidChars = ['>', '<', '"', "'", '*'];
            var foundInvalidChars = [];
            for (var i = 0; i < invalidChars.length; i++) {
                if (value.indexOf(invalidChars[i]) !== -1) {
                    foundInvalidChars.push(invalidChars[i]);
                }
            }
            if (foundInvalidChars.length > 0) {
                errors.push('Statement Descriptor cannot contain these characters: ' + foundInvalidChars.join(' '));
            }
            
            // Check if it consists solely of numbers
            if (value.length > 0 && /^\d+$/.test(value)) {
                errors.push('Statement Descriptor cannot consist solely of numbers.');
            }
            
            return errors;
        }
        
        /**
         * Show validation error for statement descriptor
         */
        function showStatementDescriptorError(errors) {
            var $field = $('#woocommerce_rpsfw_stripe_statement_descriptor');
            var $row = $field.closest('tr');
            
            // Remove existing error
            $row.find('.rpsfw-statement-descriptor-error').remove();
            
            if (errors.length > 0) {
                // Add error styling
                $field.css('border-color', '#dc3232');
                
                // Create error message
                var $error = $('<div class="rpsfw-statement-descriptor-error" style="color: #dc3232; margin-top: 5px; font-weight: 600;"></div>');
                $error.html(errors.join('<br>'));
                
                // Insert error after field
                $field.after($error);
                
                return false;
            } else {
                // Remove error styling
                $field.css('border-color', '');
                return true;
            }
        }
        
        /**
         * Real-time validation for statement descriptor
         */
        $(document).on('input', '#woocommerce_rpsfw_stripe_statement_descriptor', function() {
            var value = $(this).val();
            
            if (value.length === 0) {
                // Empty is valid (optional field)
                showStatementDescriptorError([]);
                return;
            }
            
            var errors = validateStatementDescriptor(value);
            showStatementDescriptorError(errors);
        });
        
        /**
         * Prevent form submission if statement descriptor is invalid
         */
        $('form').on('submit', function(e) {
            var $field = $('#woocommerce_rpsfw_stripe_statement_descriptor');
            
            // Only validate if field exists and has a value
            if ($field.length && $field.val().length > 0) {
                var errors = validateStatementDescriptor($field.val());
                
                if (errors.length > 0) {
                    e.preventDefault();
                    showStatementDescriptorError(errors);
                    
                    // Scroll to the error
                    $('html, body').animate({
                        scrollTop: $field.offset().top - 100
                    }, 500);
                    
                    // Show alert
                    alert('Please fix the Statement Descriptor errors before saving.');
                    
                    return false;
                }
            }
        });
        var hasUnsavedChanges = false;
        var $saveNotice = $('#rpsfw-stripe-save-notice');
        
        /**
         * Auto-save when Mode dropdown changes
         * This ensures connection status and webhooks refresh for the selected mode
         */
        $(document).on('change', '#woocommerce_rpsfw_stripe_testmode', function() {
            // Reset unsaved changes flag to prevent browser warning
            hasUnsavedChanges = false;
            window.onbeforeunload = null;
            
            // Hide the unsaved changes notice
            $saveNotice.hide();
            
            // Show switching mode overlay
            var newMode = $(this).val() === 'yes' ? 'Test Mode' : 'Live Mode';
            var overlay = $('<div class="rpsfw-stripe-overlay active"></div>');
            var content = $('<div class="rpsfw-stripe-overlay-content"></div>');
            var spinner = $('<div class="rpsfw-stripe-spinner"></div>');
            var title = $('<h2>' + rpsfwStripe.strings.switching_mode.replace('%s', newMode) + '</h2>');
            var message = $('<p>' + rpsfwStripe.strings.saving_settings + '</p>');
            
            content.append(spinner).append(title).append(message);
            overlay.append(content);
            $('body').append(overlay);
            
            // Find and click the save button - this ensures WooCommerce's normal save flow
            var $saveButton = $('button.woocommerce-save-button, input[name="save"], .button-primary[type="submit"]').first();
            if ($saveButton.length) {
                $saveButton.click();
            } else {
                // Fallback to form submit if no save button found
                var $form = $(this).closest('form');
                if ($form.length) {
                    $form.submit();
                }
            }
        });
        
        // Track changes on all form inputs within the Stripe settings
        $('.rpsfw-stripe-settings').on('change', 'input, select, textarea', function() {
            if (!hasUnsavedChanges) {
                hasUnsavedChanges = true;
                $saveNotice.fadeIn(200);
            }
        });
        
        // Also track 'input' event for immediate feedback on text fields and textareas
        $('.rpsfw-stripe-settings').on('input', 'input[type="text"], input[type="email"], input[type="password"], input[type="number"], input[type="color"], textarea', function() {
            if (!hasUnsavedChanges) {
                hasUnsavedChanges = true;
                $saveNotice.fadeIn(200);
            }
        });
        
        // Hide notice when form is submitted
        $('form').on('submit', function() {
            hasUnsavedChanges = false;
            $saveNotice.fadeOut(200);
        });
        
        /**
         * Show success overlay
         */
        function showSuccessOverlay() {
            var overlay = $('<div class="rpsfw-stripe-overlay active"></div>');
            var content = $('<div class="rpsfw-stripe-overlay-content"></div>');
            var spinner = $('<div class="rpsfw-stripe-spinner"></div>');
            var title = $('<h2>' + rpsfwStripe.strings.connected + '</h2>');
            var message = $('<p>' + rpsfwStripe.strings.refreshing + '</p>');
            
            content.append(spinner).append(title).append(message);
            overlay.append(content);
            $('body').append(overlay);
        }

        /**
         * Show a generic overlay matching the connect-success style.
         *
         * options.title    - heading text
         * options.message  - paragraph HTML
         * options.spinner  - true to show spinner (default), false for static
         * options.variant  - 'success' (default), 'error', 'info'
         * options.autoClose - milliseconds before dismissing (0 = never)
         * options.onClose  - callback fired when overlay closes
         */
        function showStripeOverlay(options) {
            options = options || {};
            var spinner = options.spinner !== false ? '<div class="rpsfw-stripe-spinner"></div>' : '';
            var variantClass = options.variant === 'error' ? ' is-error' : (options.variant === 'info' ? ' is-info' : '');

            $('.rpsfw-stripe-overlay').remove();

            var $overlay = $(
                '<div class="rpsfw-stripe-overlay active' + variantClass + '">' +
                    '<div class="rpsfw-stripe-overlay-content">' +
                        spinner +
                        '<h2></h2>' +
                        '<p></p>' +
                    '</div>' +
                '</div>'
            );
            $overlay.find('h2').text(options.title || '');
            $overlay.find('p').html(options.message || '');

            if ( options.variant === 'error' || options.variant === 'info' ) {
                var $btn = $('<button type="button" class="button button-secondary" style="margin-top:15px;">Close</button>');
                $btn.on('click', function() {
                    $overlay.remove();
                    if ( typeof options.onClose === 'function' ) {
                        options.onClose();
                    }
                });
                $overlay.find('.rpsfw-stripe-overlay-content').append($btn);
            }

            $('body').append($overlay);

            if ( options.autoClose && options.autoClose > 0 ) {
                setTimeout(function() {
                    $overlay.remove();
                    if ( typeof options.onClose === 'function' ) {
                        options.onClose();
                    }
                }, options.autoClose);
            }

            return $overlay;
        }
        
        /**
         * Handle Connect with Stripe button click
         */
        $(document).on('click', 'a[href*="stripe-rpsfw/connect.php"]', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var connectUrl = $button.attr('href');
            var originalText = $button.text();
            
            // Don't open popup if it's a disconnect link
            if (connectUrl.indexOf('action=disconnect') !== -1) {
                window.location.href = connectUrl;
                return;
            }
            
            // Disable button
            $button.css('pointer-events', 'none').css('opacity', '0.6');
            
            // Open Stripe Connect in popup
            var width = 600;
            var height = 700;
            var left = (screen.width / 2) - (width / 2);
            var top = (screen.height / 2) - (height / 2);
            
            var popup = window.open(
                connectUrl,
                'StripeConnect',
                'width=' + width + ',height=' + height + ',left=' + left + ',top=' + top + ',scrollbars=yes,resizable=yes'
            );
            
            // Check if popup was blocked
            if (!popup || popup.closed || typeof popup.closed === 'undefined') {
                alert('Please allow popups for this site to connect with Stripe.');
                $button.css('pointer-events', 'auto').css('opacity', '1');
                return;
            }
            
            // Focus the popup
            if (popup.focus) {
                popup.focus();
            }
            
            // Update button text
            if ($button.hasClass('button')) {
                $button.text(rpsfwStripe.strings.waiting);
            }
            
            // Poll for connection completion
            var pollTimer = setInterval(function() {
                try {
                    // Check if popup is still open
                    if (popup.closed) {
                        clearInterval(pollTimer);
                        // Show loading message
                        if ($button.hasClass('button')) {
                            $button.text(rpsfwStripe.strings.refreshing);
                        }
                        // Clear WC's unsaved-changes warning before reload.
                        window.onbeforeunload = null;
                        // Reload page to show updated connection status
                        window.location.reload();
                        return;
                    }
                } catch(e) {
                    // Popup might be on different domain, ignore errors
                }
                
                // Check if connection completed successfully
                $.ajax({
                    url: rpsfwStripe.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'rpsfw_stripe_check_connection_status',
                        nonce: rpsfwStripe.connection_nonce
                    },
                    success: function(response) {
                        if (response.success && response.data.completed) {
                            // Connection completed successfully!
                            clearInterval(pollTimer);
                            
                            // Close the popup
                            try {
                                popup.close();
                            } catch(e) {
                                // Ignore errors
                            }
                            
                            // Show success overlay
                            showSuccessOverlay();
                            
                            // Clear WC's unsaved-changes warning before reloading,
                            // otherwise the browser shows a "leave page" prompt
                            // on top of the success popup.
                            window.onbeforeunload = null;

                            // Reload page after a short delay
                            setTimeout(function() {
                                window.location.reload();
                            }, 1500);
                        }
                    }
                });
            }, 2000); // Check every 2 seconds
        });
        
        /**
         * Handle Disconnect link click
         */
        $(document).on('click', 'a[href*="action=disconnect"]', function(e) {
            // Determine which mode we're disconnecting based on URL
            var href = $(this).attr('href');
            var mode = href.indexOf('mode=sandbox') !== -1 ? 'Test Mode' : 'Live Mode';
            var confirmMessage = rpsfwStripe.strings.confirm_disconnect.replace('%s', mode);
            
            if (!confirm(confirmMessage)) {
                e.preventDefault();
                return false;
            }
        });
        
        /**
         * Try to auto-create the webhook on the connected account.
         * Stripe rejects this on some Connect platforms; if so we surface
         * the error and the merchant can fall back to manual paste.
         */
        $(document).on('click', '.rpsfw-stripe-create-webhook', function(e) {
            e.preventDefault();

            var $button = $(this);
            var originalText = $button.text();

            $button.prop('disabled', true).text('Creating...');

            $.ajax({
                url: rpsfwStripe.ajax_url,
                type: 'POST',
                data: {
                    action: 'rpsfw_stripe_create_webhook',
                    nonce: rpsfwStripe.webhook_nonce
                },
                success: function(response) {
                    if (response.success) {
                        showStripeOverlay({
                            title: 'Webhook created',
                            message: 'Refreshing settings...',
                            spinner: true
                        });
                        window.onbeforeunload = null;
                        setTimeout(function() {
                            window.location.reload();
                        }, 900);
                    } else {
                        showStripeOverlay({
                            title: 'Auto-create failed',
                            message: ((response.data && response.data.message) || 'Stripe rejected the request.') + ' Use Option 2 below to set up the webhook manually.',
                            spinner: false,
                            variant: 'error'
                        });
                        $button.prop('disabled', false).text(originalText);
                    }
                },
                error: function() {
                    showStripeOverlay({
                        title: 'Auto-create failed',
                        message: 'Network error. Try again or use Option 2 below.',
                        spinner: false,
                        variant: 'error'
                    });
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });

        /**
         * Toggle the "update signing secret" form on the configured-state UI.
         */
        $(document).on('click', '.rpsfw-stripe-edit-webhook', function(e) {
            e.preventDefault();
            $('.rpsfw-stripe-webhook-secret-form').slideToggle(150);
        });

        /**
         * Save the manually-entered webhook signing secret.
         */
        $(document).on('click', '.rpsfw-stripe-save-webhook-secret', function(e) {
            e.preventDefault();

            var $button = $(this);
            var $input = $button.closest('div').find('.rpsfw-stripe-webhook-secret-input').first();
            // Fall back to the page-level input if the button is not
            // wrapped with the input (initial-setup variant).
            if ($input.length === 0) {
                $input = $('.rpsfw-stripe-webhook-secret-input').first();
            }
            var secret = ($input.val() || '').trim();
            var mode = $button.data('mode');
            var originalText = $button.text();

            if (!secret) {
                showStripeOverlay({
                    title: 'Missing signing secret',
                    message: 'Paste the signing secret from your Stripe dashboard before saving.',
                    spinner: false,
                    variant: 'error'
                });
                return;
            }

            $button.prop('disabled', true).text('Saving...');

            $.ajax({
                url: rpsfwStripe.ajax_url,
                type: 'POST',
                data: {
                    action: 'rpsfw_stripe_save_webhook_secret',
                    nonce: rpsfwStripe.webhook_nonce,
                    mode: mode,
                    secret: secret
                },
                success: function(response) {
                    if (response.success) {
                        showStripeOverlay({
                            title: 'Signing secret saved',
                            message: 'Refreshing settings...',
                            spinner: true
                        });
                        window.onbeforeunload = null;
                        setTimeout(function() {
                            window.location.reload();
                        }, 900);
                    } else {
                        showStripeOverlay({
                            title: 'Could not save signing secret',
                            message: (response.data && response.data.message) || 'Try again.',
                            spinner: false,
                            variant: 'error'
                        });
                        $button.prop('disabled', false).text(originalText);
                    }
                },
                error: function() {
                    showStripeOverlay({
                        title: 'Could not save signing secret',
                        message: 'Network error, please try again.',
                        spinner: false,
                        variant: 'error'
                    });
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });

        /**
         * Clear the stored webhook signing secret.
         */
        $(document).on('click', '.rpsfw-stripe-clear-webhook-secret', function(e) {
            e.preventDefault();

            if (!confirm('Clear the stored signing secret? Webhook events will no longer be verified until a new secret is saved.')) {
                return;
            }

            var $button = $(this);
            var mode = $button.data('mode');
            var originalText = $button.text();

            $button.prop('disabled', true).text('Clearing...');

            $.ajax({
                url: rpsfwStripe.ajax_url,
                type: 'POST',
                data: {
                    action: 'rpsfw_stripe_clear_webhook_secret',
                    nonce: rpsfwStripe.webhook_nonce,
                    mode: mode
                },
                success: function(response) {
                    if (response.success) {
                        window.onbeforeunload = null;
                        window.location.reload();
                    } else {
                        showStripeOverlay({
                            title: 'Could not clear signing secret',
                            message: (response.data && response.data.message) || 'Try again.',
                            spinner: false,
                            variant: 'error'
                        });
                        $button.prop('disabled', false).text(originalText);
                    }
                },
                error: function() {
                    showStripeOverlay({
                        title: 'Could not clear signing secret',
                        message: 'Network error, please try again.',
                        spinner: false,
                        variant: 'error'
                    });
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });

    });

})(jQuery);
