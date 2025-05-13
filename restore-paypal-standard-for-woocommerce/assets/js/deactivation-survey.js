jQuery(document).ready(function($) {
    // Create and append the survey modal
    var surveyHTML = `
        <div id="rpsfw-deactivation-survey" style="display: none;">
            <div class="rpsfw-survey-content">
                <h2>${rpsfwDeactivationSurvey.strings.title}</h2>
                <p>${rpsfwDeactivationSurvey.strings.description}</p>
                <form id="rpsfw-deactivation-form">
                    <div class="rpsfw-survey-options">
                        ${Object.entries(rpsfwDeactivationSurvey.deactivationOptions).map(([key, value]) => `
                            <label>
                                <input type="radio" name="deactivation_reason" value="${key}">
                                ${value}
                            </label>
                            ${key === 'found_better' ? `<div class="rpsfw-additional-field" data-for="found_better" style="display: none;">
                                <textarea name="user-reason" class="" rows="6" style="border-spacing: 0; width: 100%; clear: both; margin: 0;" placeholder="${rpsfwDeactivationSurvey.strings.betterPluginQuestion}"></textarea>
                            </div>` : ''}
                            ${key === 'not_working' ? `<div class="rpsfw-additional-field" data-for="not_working" style="display: none;">
                                <textarea name="user-reason" class="" rows="6" style="border-spacing: 0; width: 100%; clear: both; margin: 0;" placeholder="${rpsfwDeactivationSurvey.strings.notWorkingQuestion}"></textarea>
                            </div>` : ''}
                        `).join('')}
                    </div>
                    <div id="rpsfw-other-reason" style="display: none;">
                        <textarea name="user-reason" class="" rows="6" style="border-spacing: 0; width: 100%; clear: both; margin: 0;" placeholder="${rpsfwDeactivationSurvey.strings.otherPlaceholder}"></textarea>
                    </div>
                    <div id="rpsfw-error-notice" class="notice notice-error" style="display: none; margin: 10px 0;">
                        <p>${rpsfwDeactivationSurvey.strings.errorRequired}</p>
                    </div>
                    <div class="rpsfw-survey-buttons" style="display: flex; justify-content: space-between; margin-top: 20px;">
                        <div>
                            <button type="button" class="button button-secondary" id="rpsfw-skip-survey">${rpsfwDeactivationSurvey.strings.skipButton}</button>
                        </div>
                        <div>
                            <button type="button" class="button button-secondary" id="rpsfw-cancel-survey">${rpsfwDeactivationSurvey.strings.cancelButton}</button>
                            <button type="submit" class="button button-primary">${rpsfwDeactivationSurvey.strings.submitButton}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    `;

    $('body').append(surveyHTML);

    // Show survey when deactivation link is clicked
    $(document).on('click', 'a[href*="action=deactivate&plugin=restore-paypal-standard-for-woocommerce"]', function(e) {
        e.preventDefault();
        $('#rpsfw-deactivation-survey').show();
    });

    // Handle escape key
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $('#rpsfw-deactivation-survey').is(':visible')) {
            $('#rpsfw-deactivation-survey').hide();
        }
    });

    // Handle cancel button
    $('#rpsfw-cancel-survey').on('click', function() {
        $('#rpsfw-deactivation-survey').hide();
    });

    // Handle radio button changes
    $('input[name="deactivation_reason"]').on('change', function() {
        var selectedValue = $(this).val();
        
        // Hide all additional fields first
        $('.rpsfw-additional-field').hide();
        $('#rpsfw-other-reason').hide();
        $('#rpsfw-error-notice').hide();
        
        // Remove error styling from all textareas
        $('textarea[name="user-reason"]').css('border-color', '');
        
        // Show relevant field based on selection
        if (selectedValue === 'other') {
            $('#rpsfw-other-reason').show();
        } else if (selectedValue === 'found_better' || selectedValue === 'not_working') {
            $(`.rpsfw-additional-field[data-for="${selectedValue}"]`).show();
        }
    });

    // Handle textarea input to remove error styling
    $('textarea[name="user-reason"]').on('input', function() {
        $(this).css('border-color', '');
        $('#rpsfw-error-notice').hide();
    });

    // Handle skip button
    $('#rpsfw-skip-survey').on('click', function() {
        window.location.href = $('a[href*="action=deactivate&plugin=restore-paypal-standard-for-woocommerce"]').attr('href');
    });

    // Handle form submission
    $('#rpsfw-deactivation-form').on('submit', function(e) {
        e.preventDefault();
        
        var reason = $('input[name="deactivation_reason"]:checked').val();
        var additionalReason = '';
        var $textarea = null;
        
        // Get the appropriate additional reason based on the selected option
        if (reason === 'other') {
            $textarea = $('#rpsfw-other-reason textarea');
            additionalReason = $textarea.val();
        } else if (reason === 'found_better') {
            $textarea = $('.rpsfw-additional-field[data-for="found_better"] textarea');
            additionalReason = $textarea.val();
        } else if (reason === 'not_working') {
            $textarea = $('.rpsfw-additional-field[data-for="not_working"] textarea');
            additionalReason = $textarea.val();
        }
        
        // Hide any existing error notice
        $('#rpsfw-error-notice').hide();
        
        // Remove error styling from all textareas
        $('textarea[name="user-reason"]').css('border-color', '');
        
        // Validate required fields
        if ((reason === 'other' || reason === 'found_better' || reason === 'not_working') && !additionalReason) {
            $('#rpsfw-error-notice').show();
            if ($textarea) {
                $textarea.css('border-color', '#dc3232');
            }
            return;
        }
        
        $.ajax({
            url: 'https://wpplugin.org/wp-json/wpplugin/v1/deactivation-survey',
            method: 'POST',
            data: {
                plugin_slug: 'restore-paypal-standard-for-woocommerce',
                plugin_version: rpsfwDeactivationSurvey.pluginVersion,
                reason: reason,
                additional_reason: additionalReason
            },
            success: function() {
                window.location.href = $('a[href*="action=deactivate&plugin=restore-paypal-standard-for-woocommerce"]').attr('href');
            },
            error: function() {
                window.location.href = $('a[href*="action=deactivate&plugin=restore-paypal-standard-for-woocommerce"]').attr('href');
            }
        });
    });
}); 