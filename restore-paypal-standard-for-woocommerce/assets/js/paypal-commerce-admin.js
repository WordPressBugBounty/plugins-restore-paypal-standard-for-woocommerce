/**
 * PayPal Commerce Platform Admin Scripts
 */
(function($) {
    'use strict';

    /**
     * Show success overlay with spinner
     */
    function showSuccessOverlay() {
        // Create overlay HTML
        var overlayHtml = '<div class="rpsfw-ppcp-overlay active">' +
            '<div class="rpsfw-ppcp-overlay-content">' +
                '<div class="rpsfw-ppcp-spinner"></div>' +
                '<h2>Connection Successful!</h2>' +
                '<p>Loading your PayPal settings...</p>' +
            '</div>' +
        '</div>';
        
        // Append to body
        $('body').append(overlayHtml);
    }

    /**
     * Show a generic overlay matching the connect-success style.
     *
     * options.title    - heading text
     * options.message  - paragraph text
     * options.spinner  - true to show spinner (default), false for static
     * options.variant  - 'success' (default), 'error', 'info'
     * options.autoClose - milliseconds before dismissing (0 = never)
     * options.onClose  - callback fired when overlay closes
     */
    function showPpcpOverlay(options) {
        options = options || {};
        var spinner = options.spinner !== false ? '<div class="rpsfw-ppcp-spinner"></div>' : '';
        var variantClass = options.variant === 'error' ? ' is-error' : (options.variant === 'info' ? ' is-info' : '');

        // Remove any existing overlay first.
        $('.rpsfw-ppcp-overlay').remove();

        var $overlay = $(
            '<div class="rpsfw-ppcp-overlay active' + variantClass + '">' +
                '<div class="rpsfw-ppcp-overlay-content">' +
                    spinner +
                    '<h2></h2>' +
                    '<p></p>' +
                '</div>' +
            '</div>'
        );
        $overlay.find('h2').text(options.title || '');
        $overlay.find('p').html(options.message || '');

        // For error/info variants, add a Close button so the user can dismiss.
        if ( options.variant === 'error' || options.variant === 'info' ) {
            var $btn = $('<button type="button" class="button button-secondary" style="margin-top:15px;">Close</button>');
            $btn.on('click', function() {
                $overlay.remove();
                if ( typeof options.onClose === 'function' ) {
                    options.onClose();
                }
            });
            $overlay.find('.rpsfw-ppcp-overlay-content').append($btn);
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

    $(document).ready(function() {
        
        /**
         * Initialize Pay Later location accordions
         */
        function initPayLaterAccordions() {
            // Find all Pay Later location section titles (h3 elements)
            var locationTitles = [
                'woocommerce_rpsfw_paypal_commerce_paylater_messaging_product_title',
                'woocommerce_rpsfw_paypal_commerce_paylater_messaging_cart_title',
                'woocommerce_rpsfw_paypal_commerce_paylater_messaging_checkout_title',
                'woocommerce_rpsfw_paypal_commerce_paylater_messaging_shop_title',
                'woocommerce_rpsfw_paypal_commerce_paylater_messaging_minicart_title'
            ];
            
            locationTitles.forEach(function(titleId) {
                var $title = $('#' + titleId);
                
                if ($title.length) {
                    // Make the h3 clickable and add icon
                    $title.addClass('rpsfw-paylater-accordion-title collapsed');
                    $title.css('cursor', 'pointer');
                    
                    if (!$title.find('.rpsfw-accordion-icon').length) {
                        $title.append('<span class="rpsfw-accordion-icon dashicons dashicons-arrow-down-alt2"></span>');
                    }
                    
                    // Find the next table.form-table (contains the settings for this location)
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
        
        /**
         * Initialize Logo Type and other Pay Later preview images
         */
        function initLogoTypePreview() {
            // Map for all preview images
            var previewMaps = {
                // Logo Type
                'logo_type': {
                    'primary': 'message-text-primary-left-black-GPLQ.png',
                    'alternative': 'message-text-alternative-left-black-left-GPLQ.png',
                    'inline': 'message-text-inline-left-black-left-GPLQ.png',
                    'none': 'message-text-none-left-black-left-GPLQ.png'
                },
                // Logo Position
                'logo_position': {
                    'left': 'message-text-primary-left-black-GPLQ.png',
                    'right': 'message-text-primary-right-black-left-GPLQ.png',
                    'top': 'message-text-primary-top-black-left-GPLQ.png'
                },
                // Text Color
                'text_color': {
                    'black': 'message-text-primary-left-black-GPLQ.png',
                    'white': 'message-text-primary-left-white-left-GPLQ.png',
                    'monochrome': 'message-text-primary-left-monochrome-left-GPLQ.png',
                    'grayscale': 'message-text-primary-left-grayscale-left-GPLQ.png'
                },
                // Text Size
                'text_size': {
                    '10': 'message-text-alternative-left-black-GPLQ-multi-small.png',
                    '11': 'message-text-alternative-left-black-GPLQ-multi-small.png',
                    '12': 'message-text-alternative-left-black-GPLQ-multi-medium.png',
                    '13': 'message-text-alternative-left-black-GPLQ-multi-large.png',
                    '14': 'message-text-alternative-left-black-GPLQ-multi-large.png',
                    '15': 'message-text-alternative-left-black-GPLQ-multi-large.png',
                    '16': 'message-text-alternative-left-black-GPLQ-multi-large.png'
                },
                // Text Align
                'text_align': {
                    'left': 'message-text-primary-left-black-GPLQ.png',
                    'center': 'message-text-primary-black-center-GPLQ.png',
                    'right': 'message-text-primary-left-black-right-GPLQ.png'
                },
                // Flex Color
                'flex_color': {
                    'blue': 'message-flex-1x1-blue-GPLQ.png',
                    'black': 'message-flex-1x1-black-GPLQ.png',
                    'white': 'message-flex-1x1-white-GPLQ.png',
                    'white-no-border': 'message-flex-1x1-white-no-border-GPLQ.png',
                    'gray': 'message-flex-1x1-gray-GPLQ.png',
                    'monochrome': 'message-flex-1x1-monochrome-GPLQ.png',
                    'grayscale': 'message-flex-1x1-grayscale-GPLQ.png'
                },
                // Flex Ratio
                'flex_ratio': {
                    '1x1': 'message-flex-1x1-blue-GPLQ.png',
                    '1x4': 'message-flex-1x4-blue-GPLQ.png',
                    '8x1': 'message-flex-8x1-blue-GPLQ.png',
                    '20x1': 'message-flex-20x1-blue-GPLQ.png'
                }
            };
            
            // Process each type of setting
            $.each(previewMaps, function(settingType, imageMap) {
                // Find all selects that match this setting type
                $('select[id*="_' + settingType + '"]').each(function() {
                    var $select = $(this);
                    var $row = $select.closest('tr');
                    
                    // Create preview container if it doesn't exist
                    if (!$row.find('.rpsfw-logo-preview').length) {
                        var previewHtml = '<div class="rpsfw-logo-preview" style="margin-top: 10px;">' +
                            '<img src="" alt="Preview" style="max-width: 100%; height: auto; border: 1px solid #ddd; border-radius: 4px; display: none;">' +
                            '</div>';
                        $row.find('td').append(previewHtml);
                    }
                    
                    var $preview = $row.find('.rpsfw-logo-preview img');
                    
                    // Function to update preview
                    function updatePreview() {
                        var selectedValue = $select.val();
                        var imageName = imageMap[selectedValue];
                        
                        if (imageName) {
                            var imageUrl = rpsfwPayPalCommerce.plugin_url + 'assets/images/paylater/' + imageName;
                            $preview.attr('src', imageUrl).fadeIn(200);
                        } else {
                            $preview.fadeOut(200);
                        }
                    }
                    
                    // Initial preview
                    updatePreview();
                    
                    // Update on change
                    $select.on('change', function() {
                        updatePreview();
                    });
                });
            });
        }
        
        // Initialize accordions on Pay Later tab
        if ($('input[name="woocommerce_rpsfw_paypal_commerce_paylater_messaging_enabled"]').length) {
            initPayLaterAccordions();
            initLogoTypePreview();
        }
        
        /**
         * Initialize 3DS info accordion
         */
        $(document).on('click', '.rpsfw-3ds-info-toggle', function(e) {
            e.preventDefault();
            var $toggle = $(this);
            var $content = $toggle.siblings('.rpsfw-3ds-info-content');
            var $icon = $toggle.find('.dashicons');
            
            // Toggle the content
            $content.slideToggle(200, function() {
                $content.toggleClass('open');
            });
            
            // Update the toggle text and icon
            if ($icon.hasClass('dashicons-arrow-down-alt2')) {
                $icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
                $toggle.html('<span class="dashicons dashicons-arrow-up-alt2" style="font-size: 16px; vertical-align: middle;"></span> Hide details');
            } else {
                $icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
                $toggle.html('<span class="dashicons dashicons-arrow-down-alt2" style="font-size: 16px; vertical-align: middle;"></span> Learn more about 3D Secure');
            }
        });
        
        /**
         * Handle Configure 3DS Rules button click
         */
        $('.rpsfw-ppcp-configure-3ds').on('click', function(e) {
            e.preventDefault();
            show3DSModal();
        });
        
        /**
         * Handle Reset 3DS Rules button click
         */
        $('.rpsfw-ppcp-reset-3ds').on('click', function(e) {
            e.preventDefault();
            
            if (!confirm('Are you sure you want to reset all 3DS rules to their default values?')) {
                return;
            }
            
            // Remove all existing hidden fields
            $('.rpsfw-3ds-rules-storage').empty();
            
            // Add a marker to indicate we want to clear/reset
            var $resetField = $('<input type="hidden" name="woocommerce_rpsfw_paypal_commerce_3ds_action_rules_reset" value="1" />');
            $('.rpsfw-3ds-rules-storage').append($resetField);
            
            // Mark the form as changed by triggering change on a visible WooCommerce field
            // This enables the Save Changes button
            var $form = $('.rpsfw-3ds-rules-storage').closest('form');
            $form.find('input[type="text"], input[type="checkbox"], select').first().trigger('change');
            
            // Show notice
            var $notice = $('<div class="notice notice-success inline rpsfw-3ds-save-notice" style="margin: 15px 0; padding: 10px 15px;">' +
                '<p style="margin: 0;"><strong>3DS rules reset to defaults.</strong> Click the "Save changes" button below to apply.</p>' +
            '</div>');
            
            // Remove any existing notice
            $('.rpsfw-3ds-save-notice').remove();
            
            // Insert notice before the buttons
            $('.rpsfw-ppcp-configure-3ds').before($notice);
        });
        
        /**
         * Show 3DS configuration modal
         */
        function show3DSModal() {
            // Get current rules from hidden fields
            var rules = {};
            $('.rpsfw-3ds-rules-storage input[type="hidden"]').each(function() {
                var $input = $(this);
                var status = $input.data('status');
                var action = $input.val();
                rules[status] = action;
            });
            
            // Build modal HTML
            var modalHtml = '<div class="rpsfw-3ds-modal-overlay">' +
                '<div class="rpsfw-3ds-modal">' +
                    '<div class="rpsfw-3ds-modal-header">' +
                        '<h2>Configure 3D Secure Rules</h2>' +
                        '<button class="rpsfw-3ds-modal-close">&times;</button>' +
                    '</div>' +
                    '<div class="rpsfw-3ds-modal-body">' +
                        '<div style="background: #fff8e5; border-left: 4px solid #ffb900; padding: 12px 15px; margin: 0 0 15px 0;">' +
                            '<p style="margin: 0;"><em>The default rules are recommended by PayPal and provide a good balance between security and conversion. Only modify if you have specific fraud prevention requirements. ' +
                            '<a href="https://developer.paypal.com/docs/checkout/advanced/customize/3d-secure/response-parameters/" target="_blank" rel="noopener noreferrer">Learn more about 3DS response codes</a></em></p>' +
                        '</div>' +
                        '<p style="margin-bottom: 15px;">Configure how your store handles different 3D Secure authentication results. Each status code represents a combination of enrollment, authentication, and liability shift.</p>' +
                        '<p style="margin: 10px 0;">Configure how your store handles different 3D Secure authentication results. Each result has three components:</p>' +
                        '<ul style="margin: 0 0 10px 20px;">' +
                            '<li><strong>Enrollment Status:</strong> Is the card enrolled in 3DS?</li>' +
                            '<li><strong>Authentication Status:</strong> Did the customer pass verification?</li>' +
                            '<li><strong>Liability Shift:</strong> Who is responsible for fraud?</li>' +
                        '</ul>' +
                        '<p style="margin: 10px 0;"><strong>Actions:</strong></p>' +
                        '<ul style="margin: 0 0 15px 20px;">' +
                            '<li><strong>Accept:</strong> Process the payment normally</li>' +
                            '<li><strong>Reject:</strong> Decline the payment and show an error to the customer</li>' +
                            '<li><strong>Review:</strong> Accept the payment but mark the order as "On Hold" for manual review</li>' +
                        '</ul>' +
                        '<table class="rpsfw-3ds-rules-table">' +
                            '<thead>' +
                                '<tr>' +
                                    '<th>Status Code</th>' +
                                    '<th>Enrollment</th>' +
                                    '<th>Authentication</th>' +
                                    '<th>Liability Shift</th>' +
                                    '<th>Action</th>' +
                                '</tr>' +
                            '</thead>' +
                            '<tbody>';
            
            // Status code descriptions
            var statusDescriptions = {
                'Y_Y_YES': { enroll: 'Enrolled', auth: 'Successful', liability: 'Yes', recommended: 'accept' },
                'Y_Y_POSSIBLE': { enroll: 'Enrolled', auth: 'Successful', liability: 'Possible', recommended: 'accept' },
                'Y_N_NO': { enroll: 'Enrolled', auth: 'Failed', liability: 'No', recommended: 'reject' },
                'Y_R_NO': { enroll: 'Enrolled', auth: 'Rejected', liability: 'No', recommended: 'reject' },
                'Y_U_NO': { enroll: 'Enrolled', auth: 'Unable', liability: 'No', recommended: 'reject' },
                'Y_A_POSSIBLE': { enroll: 'Enrolled', auth: 'Attempted', liability: 'Possible', recommended: 'accept' },
                'Y_A_NO': { enroll: 'Enrolled', auth: 'Attempted', liability: 'No', recommended: 'review' },
                'Y_C_UNKNOWN': { enroll: 'Enrolled', auth: 'Challenge Required', liability: 'Unknown', recommended: 'reject' },
                'Y_C_NO': { enroll: 'Enrolled', auth: 'Challenge Required', liability: 'No', recommended: 'reject' },
                'Y_U_UNKNOWN': { enroll: 'Enrolled', auth: 'Unable', liability: 'Unknown', recommended: 'reject' },
                'Y__NO': { enroll: 'Enrolled', auth: 'N/A', liability: 'No', recommended: 'reject' },
                'N_N_NO': { enroll: 'Not Enrolled', auth: 'N/A', liability: 'No', recommended: 'accept' },
                'N__NO': { enroll: 'Not Enrolled', auth: 'N/A', liability: 'No', recommended: 'accept' },
                'U__NO': { enroll: 'Unable to Verify', auth: 'N/A', liability: 'No', recommended: 'accept' },
                'U__UNKNOWN': { enroll: 'Unable to Verify', auth: 'N/A', liability: 'Unknown', recommended: 'review' },
                'B__NO': { enroll: 'Bypass', auth: 'N/A', liability: 'No', recommended: 'accept' },
                '__UNKNOWN': { enroll: 'Unknown', auth: 'Unknown', liability: 'Unknown', recommended: 'reject' }
            };
            
            // Add rows for each status code
            $.each(statusDescriptions, function(statusCode, desc) {
                var currentAction = rules[statusCode] || desc.recommended;
                var isDefault = currentAction === desc.recommended;
                
                modalHtml += '<tr data-status="' + statusCode + '">' +
                    '<td><code>' + statusCode + '</code></td>' +
                    '<td>' + desc.enroll + '</td>' +
                    '<td>' + desc.auth + '</td>' +
                    '<td>' + desc.liability + '</td>' +
                    '<td>' +
                        '<select class="rpsfw-3ds-action-select" data-status="' + statusCode + '">' +
                            '<option value="accept"' + (currentAction === 'accept' ? ' selected' : '') + '>Accept</option>' +
                            '<option value="reject"' + (currentAction === 'reject' ? ' selected' : '') + '>Reject</option>' +
                            '<option value="review"' + (currentAction === 'review' ? ' selected' : '') + '>Review</option>' +
                        '</select>' +
                        (!isDefault ? ' <span class="rpsfw-3ds-modified" title="Modified from default">*</span>' : '') +
                    '</td>' +
                '</tr>';
            });
            
            modalHtml += '</tbody>' +
                        '</table>' +
                        '<p style="margin-top: 15px; font-size: 12px; color: #666;"><em>* Modified from recommended default</em></p>' +
                    '</div>' +
                    '<div class="rpsfw-3ds-modal-footer">' +
                        '<button class="button button-primary rpsfw-3ds-modal-save">Save Rules</button>' +
                        '<button class="button rpsfw-3ds-modal-cancel">Cancel</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
            
            // Add modal to page
            $('body').append(modalHtml);
            
            // Prevent body scroll
            $('body').css('overflow', 'hidden');
        }
        
        /**
         * Close 3DS modal
         */
        $(document).on('click', '.rpsfw-3ds-modal-close, .rpsfw-3ds-modal-cancel, .rpsfw-3ds-modal-overlay', function(e) {
            if (e.target === this) {
                $('.rpsfw-3ds-modal-overlay').remove();
                $('body').css('overflow', '');
            }
        });
        
        /**
         * Save 3DS rules
         */
        $(document).on('click', '.rpsfw-3ds-modal-save', function(e) {
            e.preventDefault();
            
            var hasChanges = false;
            
            // Update hidden fields with new values
            $('.rpsfw-3ds-action-select').each(function() {
                var $select = $(this);
                var status = $select.data('status');
                var action = $select.val();
                
                // Find corresponding hidden field
                var $hiddenField = $('.rpsfw-3ds-rules-storage input[data-status="' + status + '"]');
                
                // Check if value changed
                if ($hiddenField.val() !== action) {
                    hasChanges = true;
                    $hiddenField.val(action);
                }
            });
            
            if (hasChanges) {
                // Trigger change event to enable Save Changes button
                // WooCommerce listens for changes on form inputs
                $('.rpsfw-3ds-rules-storage input').first().trigger('change');
                
                // Show notice
                var $notice = $('<div class="notice notice-success inline rpsfw-3ds-save-notice" style="margin: 15px 0; padding: 10px 15px;">' +
                    '<p style="margin: 0;"><strong>3DS rules updated.</strong> Click the "Save changes" button below to apply your changes.</p>' +
                '</div>');
                
                // Remove any existing notice
                $('.rpsfw-3ds-save-notice').remove();
                
                // Insert notice before the buttons
                $('.rpsfw-ppcp-configure-3ds').before($notice);
            }
            
            // Close modal
            $('.rpsfw-3ds-modal-overlay').remove();
            $('body').css('overflow', '');
        });
        
        /**
         * Toggle Pay Later layout options visibility
         */
        function togglePayLaterOptions() {
            $('.rpsfw-paylater-layout-select').each(function() {
                var $select = $(this);
                var layout = $select.val();
                var $row = $select.closest('tr');
                // Get the location prefix (e.g., 'product', 'cart', 'checkout', 'shop', 'minicart')
                var selectId = $select.attr('id');
                var locationPrefix = selectId.replace('woocommerce_rpsfw_paypal_commerce_paylater_messaging_', '').replace('_layout', '');
                
                // Find all related option rows within the same table
                var $table = $row.closest('table');
                
                // Match text options: logo_type, logo_position, text_color, text_size, text_align
                var $textOptions = $table.find('tr').filter(function() {
                    var $el = $(this).find('.rpsfw-paylater-text-option');
                    if (!$el.length) return false;
                    var elId = $el.attr('id') || '';
                    // Check if this element belongs to the same location section
                    return elId.indexOf('_' + locationPrefix + '_') > -1;
                });
                
                // Match flex options: flex_color, flex_ratio
                var $flexOptions = $table.find('tr').filter(function() {
                    var $el = $(this).find('.rpsfw-paylater-flex-option');
                    if (!$el.length) return false;
                    var elId = $el.attr('id') || '';
                    // Check if this element belongs to the same location section
                    return elId.indexOf('_' + locationPrefix + '_') > -1;
                });
                
                if (layout === 'text') {
                    $textOptions.show();
                    $flexOptions.hide();
                } else {
                    $textOptions.hide();
                    $flexOptions.show();
                }
            });
        }
        
        // Initial toggle
        togglePayLaterOptions();
        
        // Toggle on change
        $(document).on('change', '.rpsfw-paylater-layout-select', function() {
            togglePayLaterOptions();
        });

        /**
         * Handle Connect with PayPal button click
         */
        $(document).on('click', '.rpsfw-ppcp-connect', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var env = $button.data('env');
            var originalText = $button.text();
            
            // Disable button and show loading state
            $button.prop('disabled', true).text(rpsfwPayPalCommerce.strings.connecting);
            
            // Make AJAX request to start onboarding
            $.ajax({
                url: rpsfwPayPalCommerce.ajax_url,
                type: 'POST',
                data: {
                    action: 'rpsfw_ppcp_start_onboarding',
                    nonce: rpsfwPayPalCommerce.onboarding_nonce,
                    sandbox: env === 'sandbox' ? '1' : ''
                },
                success: function(response) {
                    if (response.success && response.data.action_url) {
                        // Add displayMode=minibrowser to auto-close popup
                        var actionUrl = response.data.action_url;
                        var separator = actionUrl.indexOf('?') > -1 ? '&' : '?';
                        actionUrl = actionUrl + separator + 'displayMode=minibrowser';
                        
                        // Open PayPal onboarding in popup
                        var width = 500;
                        var height = 600;
                        var left = (screen.width / 2) - (width / 2);
                        var top = (screen.height / 2) - (height / 2);
                        
                        var popup = window.open(
                            actionUrl,
                            'PayPalOnboarding',
                            'width=' + width + ',height=' + height + ',left=' + left + ',top=' + top + ',toolbar=no,location=no,status=no,menubar=no,scrollbars=yes,resizable=yes'
                        );
                        
                        // Check if popup was blocked
                        if (!popup || popup.closed || typeof popup.closed === 'undefined') {
                            alert('Please allow popups for this site to connect with PayPal.');
                            $button.prop('disabled', false).text(originalText);
                            return;
                        }
                        
                        // Update button text to show we're waiting
                        $button.text('Waiting for PayPal connection...');
                        
                        // Poll for onboarding completion
                        var pollTimer = setInterval(function() {
                            try {
                                // Check if popup is still open
                                if (popup.closed) {
                                    clearInterval(pollTimer);
                                    // Show loading message
                                    $button.text('Refreshing...');
                                    // Clear WC's unsaved-changes warning before reload.
                                    window.onbeforeunload = null;
                                    // Reload page to show updated connection status
                                    window.location.reload();
                                    return;
                                }
                            } catch(e) {
                                // Popup might be on different domain, ignore errors
                            }
                            
                            // Check if onboarding completed successfully
                            $.ajax({
                                url: rpsfwPayPalCommerce.ajax_url,
                                type: 'POST',
                                data: {
                                    action: 'rpsfw_ppcp_check_onboarding_status',
                                    nonce: rpsfwPayPalCommerce.onboarding_nonce,
                                    env: env
                                },
                                success: function(statusResponse) {
                                    if (statusResponse.success && statusResponse.data.completed) {
                                        // Onboarding completed successfully!
                                        clearInterval(pollTimer);
                                        
                                        // Close the popup
                                        try {
                                            popup.close();
                                        } catch(e) {
                                            // Ignore errors
                                        }
                                        
                                        // Show success overlay
                                        showSuccessOverlay();
                                        
                                        // Clear WC's unsaved-changes warning before reload.
                                        window.onbeforeunload = null;

                                        // Reload page after a short delay
                                        setTimeout(function() {
                                            window.location.reload();
                                        }, 1500);
                                    }
                                }
                            });
                        }, 2000); // Check every 2 seconds
                        
                    } else {
                        var errorMsg = 'Failed to start onboarding. Please try again.';
                        if (response.data && response.data.message) {
                            errorMsg = response.data.message;
                        }
                        alert(errorMsg);
                        $button.prop('disabled', false).text(originalText);
                    }
                },
                error: function(xhr, textStatus, error) {
                    alert('An error occurred: ' + (xhr.responseText || error || 'Unknown error'));
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });
        
        /**
         * Handle Disconnect button click
         */
        $(document).on('click', '.rpsfw-ppcp-disconnect', function(e) {
            e.preventDefault();
            
            // Determine which mode we're disconnecting based on current testmode setting
            var $testmodeSelect = $('#woocommerce_rpsfw_paypal_commerce_testmode');
            var mode = $testmodeSelect.val() === 'yes' ? 'Test Mode' : 'Live Mode';
            var confirmMessage = rpsfwPayPalCommerce.strings.confirm_disconnect.replace('%s', mode);
            
            if (!confirm(confirmMessage)) {
                return;
            }
            
            var $button = $(this);
            var originalText = $button.text();
            
            // Disable button and show loading state
            $button.prop('disabled', true).text(rpsfwPayPalCommerce.strings.disconnecting);
            
            // Make AJAX request to disconnect
            $.ajax({
                url: rpsfwPayPalCommerce.ajax_url,
                type: 'POST',
                data: {
                    action: 'rpsfw_ppcp_disconnect',
                    nonce: rpsfwPayPalCommerce.disconnect_nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Reload page to show updated connection status
                        window.location.reload();
                    } else {
                        alert(response.data.message || 'Failed to disconnect. Please try again.');
                        $button.prop('disabled', false).text(originalText);
                    }
                },
                error: function() {
                    alert('An error occurred. Please try again.');
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });

        /**
         * Handle Create Webhook button click
         */
        $(document).on('click', '.rpsfw-ppcp-create-webhook', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var env = $button.data('env');
            var originalText = $button.text();
            
            // Disable button and show loading state
            $button.prop('disabled', true).text(rpsfwPayPalCommerce.strings.creating_webhook);
            
            // Make AJAX request to create webhook
            $.ajax({
                url: rpsfwPayPalCommerce.ajax_url,
                type: 'POST',
                data: {
                    action: 'rpsfw_ppcp_create_webhook',
                    nonce: rpsfwPayPalCommerce.webhook_nonce,
                    env: env
                },
                success: function(response) {
                    if (response.success) {
                        showPpcpOverlay({
                            title: 'Webhook Created!',
                            message: response.data.message || 'Loading webhook details...',
                            spinner: true
                        });
                        // Clear WC's unsaved-changes warning before reload.
                        window.onbeforeunload = null;
                        // Reload page to show updated webhook status
                        setTimeout(function() {
                            window.location.reload();
                        }, 1200);
                    } else {
                        showPpcpOverlay({
                            title: 'Webhook Creation Failed',
                            message: response.data.message || 'Failed to create webhook. Please try again.',
                            spinner: false,
                            variant: 'error'
                        });
                        $button.prop('disabled', false).text(originalText);
                    }
                },
                error: function(xhr) {
                    showPpcpOverlay({
                        title: 'Webhook Creation Failed',
                        message: 'An error occurred: ' + (xhr.responseText || 'Unknown error'),
                        spinner: false,
                        variant: 'error'
                    });
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });

        /**
         * Handle Delete Webhook button click
         */
        $(document).on('click', '.rpsfw-ppcp-delete-webhook', function(e) {
            e.preventDefault();
            
            if (!confirm(rpsfwPayPalCommerce.strings.confirm_delete_webhook)) {
                return;
            }
            
            var $button = $(this);
            var env = $button.data('env');
            var originalText = $button.text();
            
            // Disable button and show loading state
            $button.prop('disabled', true).text(rpsfwPayPalCommerce.strings.deleting_webhook);
            
            // Make AJAX request to delete webhook
            $.ajax({
                url: rpsfwPayPalCommerce.ajax_url,
                type: 'POST',
                data: {
                    action: 'rpsfw_ppcp_delete_webhook',
                    nonce: rpsfwPayPalCommerce.webhook_nonce,
                    env: env
                },
                success: function(response) {
                    if (response.success) {
                        showPpcpOverlay({
                            title: 'Webhook Deleted',
                            message: response.data.message || 'Refreshing settings...',
                            spinner: true
                        });
                        window.onbeforeunload = null;
                        setTimeout(function() {
                            window.location.reload();
                        }, 1200);
                    } else {
                        showPpcpOverlay({
                            title: 'Delete Failed',
                            message: response.data.message || 'Failed to delete webhook. Please try again.',
                            spinner: false,
                            variant: 'error'
                        });
                        $button.prop('disabled', false).text(originalText);
                    }
                },
                error: function(xhr) {
                    showPpcpOverlay({
                        title: 'Delete Failed',
                        message: 'An error occurred: ' + (xhr.responseText || 'Unknown error'),
                        spinner: false,
                        variant: 'error'
                    });
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });

        /**
         * Handle Check Webhook button click
         */
        $(document).on('click', '.rpsfw-ppcp-check-webhook', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var env = $button.data('env');
            var originalText = $button.text();
            
            // Disable button and show loading state
            $button.prop('disabled', true).text(rpsfwPayPalCommerce.strings.checking_webhook);
            
            // Make AJAX request to check webhook
            $.ajax({
                url: rpsfwPayPalCommerce.ajax_url,
                type: 'POST',
                data: {
                    action: 'rpsfw_ppcp_check_webhook',
                    nonce: rpsfwPayPalCommerce.webhook_nonce,
                    env: env
                },
                success: function(response) {
                    if (response.success) {
                        var status = response.data.status;
                        var message = response.data.message;

                        if (status === 'active') {
                            var events = response.data.events || [];
                            var eventNames = events.map(function(e){ return (e && e.name) ? e.name : e; });
                            var hasRefundEvents = eventNames.indexOf('PAYMENT.CAPTURE.REFUNDED') !== -1 ||
                                eventNames.indexOf('PAYMENT.SALE.REFUNDED') !== -1;
                            var eventsHtml = eventNames.length
                                ? '<br><br><strong>Subscribed events (' + eventNames.length + '):</strong><br><code style="font-size:11px;line-height:1.6;">' + eventNames.join('<br>') + '</code>' +
                                  '<br><br><strong>Refund events registered:</strong> ' + (hasRefundEvents ? '✓ Yes' : '✗ No — delete and recreate the webhook')
                                : '<br><br><em>PayPal returned no event types for this webhook.</em>';
                            var detail = (message || 'Webhook is active.') +
                                '<br><br><strong>Webhook ID:</strong> <code>' + response.data.webhook_id + '</code>' +
                                '<br><strong>URL:</strong> <code style="font-size:11px;word-break:break-all;">' + response.data.url + '</code>' +
                                eventsHtml;
                            showPpcpOverlay({
                                title: 'Webhook Active',
                                message: detail,
                                spinner: false,
                                variant: 'info'
                            });
                            $button.prop('disabled', false).text(originalText);
                        } else if (status === 'not_found') {
                            showPpcpOverlay({
                                title: 'Webhook Not Found',
                                message: (message || 'Webhook is no longer registered with PayPal.') + ' Refreshing settings...',
                                spinner: true
                            });
                            window.onbeforeunload = null;
                            setTimeout(function() {
                                window.location.reload();
                            }, 1500);
                        } else {
                            showPpcpOverlay({
                                title: 'Webhook Status',
                                message: message,
                                spinner: false,
                                variant: 'info'
                            });
                            $button.prop('disabled', false).text(originalText);
                        }
                    } else {
                        showPpcpOverlay({
                            title: 'Check Failed',
                            message: response.data.message || 'Failed to check webhook status.',
                            spinner: false,
                            variant: 'error'
                        });
                        $button.prop('disabled', false).text(originalText);
                    }
                },
                error: function(xhr) {
                    showPpcpOverlay({
                        title: 'Check Failed',
                        message: 'An error occurred: ' + (xhr.responseText || 'Unknown error'),
                        spinner: false,
                        variant: 'error'
                    });
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });

        /**
         * Show save notice when settings are changed
         */
        var hasUnsavedChanges = false;
        var $saveNotice = $('#rpsfw-ppcp-save-notice');
        
        /**
         * Auto-save when Mode dropdown changes
         * This ensures connection status and webhooks refresh for the selected mode
         */
        $(document).on('change', '#woocommerce_rpsfw_paypal_commerce_testmode', function() {
            // Reset unsaved changes flag to prevent browser warning
            hasUnsavedChanges = false;
            window.onbeforeunload = null;
            
            // Hide the unsaved changes notice
            $saveNotice.hide();
            
            // Show switching mode overlay
            var newMode = $(this).val() === 'yes' ? 'Test Mode' : 'Live Mode';
            var overlay = $('<div class="rpsfw-ppcp-overlay active"></div>');
            var content = $('<div class="rpsfw-ppcp-overlay-content"></div>');
            var spinner = $('<div class="rpsfw-ppcp-spinner"></div>');
            var title = $('<h2>' + rpsfwPayPalCommerce.strings.switching_mode.replace('%s', newMode) + '</h2>');
            var message = $('<p>' + rpsfwPayPalCommerce.strings.saving_settings + '</p>');
            
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
        
        // Track changes on all form inputs within the PayPal Commerce settings
        // Use 'change' event for checkboxes, selects, and when inputs lose focus
        $('.rpsfw-ppcp-settings').on('change', 'input, select, textarea', function() {
            if (!hasUnsavedChanges) {
                hasUnsavedChanges = true;
                $saveNotice.fadeIn(200);
            }
        });
        
        // Also track 'input' event for immediate feedback on text fields and textareas
        $('.rpsfw-ppcp-settings').on('input', 'input[type="text"], input[type="email"], input[type="password"], input[type="number"], textarea', function() {
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
        
        // Also track changes on 3DS rules storage (hidden fields)
        $('.rpsfw-3ds-rules-storage').on('change', 'input', function() {
            if (!hasUnsavedChanges) {
                hasUnsavedChanges = true;
                $saveNotice.fadeIn(200);
            }
        });
        
    });
    
})(jQuery);
