/**
 * PayPal Standard Admin JavaScript
 */
(function($) {
    'use strict';
    
    // Scopes the gateway settings screen to the selected Mode: Live Mode shows
    // the live PayPal email / API credentials, Sandbox Mode shows the sandbox
    // ones, and the inactive set is hidden so it cannot be filled in by mistake.

    $(document).ready(function() {
        // Must match the gateway id exactly (underscores) — WooCommerce builds
        // field ids as woocommerce_{gateway_id}_{field}.
        var gatewayId = 'restore_paypal_standard';
        
        // Translated strings provided from PHP via wp_localize_script.
        var translatedStrings = window.rpsfwPayPalParams || {};
        
        // Function to get field ID with proper WooCommerce prefix
        function getFieldId(field) {
            return '#woocommerce_' + gatewayId + '_' + field;
        }
        
        // Function to toggle PayPal fields based on selected mode
        function toggleFields() {
            // The Mode selector only lives on the General tab. On the other
            // tabs (Advanced carries the live/sandbox API credentials) fall
            // back to the saved mode passed in from PHP, so those fields are
            // scoped to the active mode too.
            var modeSelector = $(getFieldId('testmode'));
            var currentMode = modeSelector.length ? modeSelector.val() : translatedStrings.testmode;

            if (typeof currentMode === 'undefined') return;

            var settingsWrap = $('#mainform');
            if (!settingsWrap.length) return;

            // Update styling based on mode
            settingsWrap.removeClass('rpsfw-sandbox-mode rpsfw-live-mode');
            settingsWrap.addClass(currentMode === 'yes' ? 'rpsfw-sandbox-mode' : 'rpsfw-live-mode');
            
            // Define field groups based on mode
            var liveFields = [
                'email',
                'receiver_email',
                'api_username',
                'api_password',
                'api_signature'
            ];
            
            var sandboxFields = [
                'sandbox_email',
                'sandbox_api_username',
                'sandbox_api_password',
                'sandbox_api_signature'
            ];
            
            // Add classes to rows for CSS targeting
            $.each(liveFields, function(i, field) {
                var fieldRow = $(getFieldId(field)).closest('tr');
                if (fieldRow.length) {
                    fieldRow.addClass('rpsfw-live-field-row');
                }
            });
            
            $.each(sandboxFields, function(i, field) {
                var fieldRow = $(getFieldId(field)).closest('tr');
                if (fieldRow.length) {
                    fieldRow.addClass('rpsfw-sandbox-field-row');
                }
            });
            
            // Get the current section from URL params
            var urlParams = new URLSearchParams(window.location.search);
            var currentSubSection = urlParams.get('sub_section') || 'general';

            // Only toggle visibility based on mode if we're on the appropriate sections
            if (currentSubSection === 'general' || currentSubSection === 'advanced') {
                // Hide/show fields based on current mode
                if (currentMode === 'yes') {
                    // Hide live fields
                    $.each(liveFields, function(i, field) {
                        var fieldRow = $(getFieldId(field)).closest('tr');
                        if (fieldRow.length) {
                            fieldRow.hide();
                        }
                    });
                    
                    // Show sandbox fields
                    $.each(sandboxFields, function(i, field) {
                        var fieldRow = $(getFieldId(field)).closest('tr');
                        if (fieldRow.length) {
                            fieldRow.show();
                        }
                    });
                    
                    // Add sandbox notice if we're on the general section
                    if (currentSubSection === 'general') {
                        // Add sandbox notice - use translated text from PHP
                        var descElement = $(getFieldId('description')).closest('tr').find('.description');
                        if (descElement.length) {
                            descElement.html(translatedStrings.sandboxNotice);
                        }
                        
                        // Add sandbox help link if it doesn't exist - use translated text from PHP
                        var sandboxHelpLink = '<div class="rpsfw-sandbox-help"><a href="https://wpplugin.org/documentation/sandbox-mode/" target="_blank" rel="noopener noreferrer">' + translatedStrings.sandboxHelpLinkText + '</a></div>';
                        var modeRow = modeSelector.closest('tr');
                        
                        if (modeRow.length && modeRow.find('.rpsfw-sandbox-help').length === 0) {
                            modeRow.find('td.forminp').append(sandboxHelpLink);
                        }
                    }
                } else {
                    // Hide sandbox fields
                    $.each(sandboxFields, function(i, field) {
                        var fieldRow = $(getFieldId(field)).closest('tr');
                        if (fieldRow.length) {
                            fieldRow.hide();
                        }
                    });
                    
                    // Show live fields
                    $.each(liveFields, function(i, field) {
                        var fieldRow = $(getFieldId(field)).closest('tr');
                        if (fieldRow.length) {
                            fieldRow.show();
                        }
                    });
                    
                    // Only update description if we're on the general section
                    if (currentSubSection === 'general') {
                        // Restore original description
                        // First try getting from data attribute, then from translated strings, or leave unchanged
                        var descElement = $(getFieldId('description')).closest('tr').find('.description');
                        if (descElement.length) {
                            var originalDesc = descElement.data('original-text');
                            if (!originalDesc && translatedStrings.descriptionText) {
                                originalDesc = translatedStrings.descriptionText;
                            }
                            
                            if (originalDesc) {
                                descElement.html(originalDesc);
                            }
                        }
                        
                        // Remove sandbox help link if it exists
                        modeSelector.closest('tr').find('.rpsfw-sandbox-help').remove();
                    }
                }
            }
        }
        
        // Store original description text in a data attribute when page loads
        function storeOriginalDescription() {
            var descElement = $(getFieldId('description')).closest('tr').find('.description');
            if (descElement.length && !descElement.data('original-text')) {
                // If we have the text from PHP, use that (it will be properly translated)
                if (translatedStrings.descriptionText) {
                    descElement.data('original-text', translatedStrings.descriptionText);
                } else {
                    // Otherwise, store what's there now
                    descElement.data('original-text', descElement.html());
                }
            }
        }
        
        // Initialize. This script is only enqueued on our own gateway section
        // (see rpsfw_Gateway_PayPal_Standard::admin_scripts), so there is no
        // further screen check to make here — an earlier gate on a
        // '.rpsfw-settings-tabs' element that the settings screen never
        // renders is what kept the mode toggle from ever running.
        storeOriginalDescription();
        toggleFields();

        // Run when mode is changed. Delegated, so it also covers the
        // select2/enhanced-select replacement of the Mode dropdown.
        $(document).on('change', getFieldId('testmode'), function() {
            toggleFields();
        });

        // Belt-and-suspenders: ensure WooCommerce's save button gets
        // enabled when any input on this form changes. WC core binds its
        // own 'change input' handler in settings.js, but in some cases
        // (notably unchecking a checkbox right after page load) the
        // event was getting dropped, leaving the button disabled.
        $('#mainform').on('change input', 'input, select, textarea', function() {
            $('.woocommerce-save-button').removeAttr('disabled');
        });
    });
    
})(jQuery); 