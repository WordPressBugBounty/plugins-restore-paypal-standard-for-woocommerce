/**
 * PayPal Standard Admin JavaScript
 */
(function($) {
    'use strict';
    
    // This file serves as a base for any additional admin functionality
    // The main toggle functionality is injected inline in the admin_scripts method
    
    $(document).ready(function() {
        var gatewayId = 'restore-paypal-standard';
        
        // Get translated strings from global object
        var translatedStrings = window.rpsfwPayPalParams || {
            sandboxNotice: 'SANDBOX MODE ENABLED',
            sandboxHelpLinkText: 'Learn how to set up a PayPal Sandbox account',
            descriptionText: ''
        };
        
        // Function to get field ID with proper WooCommerce prefix
        function getFieldId(field) {
            return '#woocommerce_' + gatewayId + '_' + field;
        }
        
        // Function to toggle PayPal fields based on selected mode
        function toggleFields() {
            var modeSelector = $(getFieldId('testmode'));
            if (!modeSelector.length) return; // Exit if mode selector isn't on this page
            
            var currentMode = modeSelector.val();
            console.log('PayPal Standard: Current mode:', currentMode);
            
            // Get all form rows and settings wrapper
            var allRows = modeSelector.closest('form').find('tr');
            var settingsWrap = modeSelector.closest('form');
            
            // Update styling based on mode
            settingsWrap.removeClass('rpsfw-sandbox-mode rpsfw-live-mode');
            settingsWrap.addClass(currentMode === 'yes' ? 'rpsfw-sandbox-mode' : 'rpsfw-live-mode');
            
            // Define field groups based on mode
            var liveFields = [
                'email',
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
        
        // Initialize when page loads - if we're on a PayPal settings page
        if ($('.rpsfw-settings-tabs').length) {
            // If the mode field exists on this section
            if ($(getFieldId('testmode')).length) {
                storeOriginalDescription();
                toggleFields();
                
                // Run when mode is changed
                $(document).on('change', getFieldId('testmode'), function() {
                    console.log('PayPal Standard: Mode changed to', $(this).val());
                    toggleFields();
                });
            }
            
            // Mark active section tab
            var urlParams = new URLSearchParams(window.location.search);
            var currentSubSection = urlParams.get('sub_section') || 'general';
            $('.rpsfw-settings-tabs .nav-tab').removeClass('nav-tab-active');
            $('.rpsfw-settings-tabs .nav-tab[href*="sub_section=' + currentSubSection + '"]').addClass('nav-tab-active');
        }
    });
    
})(jQuery); 