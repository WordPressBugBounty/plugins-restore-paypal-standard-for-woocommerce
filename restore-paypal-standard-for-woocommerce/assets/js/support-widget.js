/**
 * Support contact widget.
 *
 * Opens/minimizes the panel and posts the form to admin-ajax. Kept dependency
 * -light on purpose: jQuery is already present in wp-admin and nothing here
 * needs more than it.
 */
(function ($) {
	'use strict';

	$(function () {
		var $widget = $('#rpsfw-support');
		if (!$widget.length || typeof rpsfwSupport === 'undefined') {
			return;
		}

		var $launcher = $widget.find('.rpsfw-support-launcher');
		var $panel    = $widget.find('.rpsfw-support-panel');
		var $form     = $widget.find('.rpsfw-support-form');
		var $notice   = $widget.find('.rpsfw-support-notice');
		var $send     = $widget.find('.rpsfw-support-send');
		var sending   = false;

		function open() {
			$widget.removeClass('is-closed').addClass('is-open');
			$launcher.attr('aria-expanded', 'true');
			// Focus the first empty field so a returning user can just type.
			var $first = $form.find('input, textarea').filter(function () {
				return !$(this).val();
			}).first();
			($first.length ? $first : $form.find('#rpsfw-support-message')).trigger('focus');
		}

		function minimize() {
			$widget.removeClass('is-open').addClass('is-closed');
			$launcher.attr('aria-expanded', 'false').trigger('focus');
		}

		function showNotice(message, type) {
			$notice
				.removeClass('is-error is-success')
				.addClass(type ? 'is-' + type : '')
				.text(message)
				.prop('hidden', false);

			// The panel body scrolls and the notice sits above the form, so on
			// a long message (or a short window) the confirmation would land
			// off-screen with the Send button still in view - looking like
			// nothing happened. Jump back to the top so it is always read.
			var $body = $widget.find('.rpsfw-support-body');
			if ($body.length) {
				$body.scrollTop(0);
			}
		}

		function clearNotice() {
			$notice.prop('hidden', true).removeClass('is-error is-success').text('');
		}

		function isEmail(value) {
			return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
		}

		$launcher.on('click', open);
		$widget.find('.rpsfw-support-minimize').on('click', minimize);

		// Escape minimizes, matching how every other panel in wp-admin behaves.
		$widget.on('keydown', function (event) {
			if (27 === event.keyCode && $widget.hasClass('is-open')) {
				minimize();
			}
		});

		$form.on('submit', function (event) {
			event.preventDefault();

			if (sending) {
				return;
			}

			var name    = $.trim($form.find('#rpsfw-support-name').val());
			var email   = $.trim($form.find('#rpsfw-support-email').val());
			var siteUrl = $.trim($form.find('#rpsfw-support-site').val());
			var gateway = $form.find('#rpsfw-support-gateway').val();
			var message = $.trim($form.find('#rpsfw-support-message').val());

			if (!name || !email || !message || !gateway) {
				showNotice(rpsfwSupport.i18n.incomplete, 'error');
				return;
			}

			if (!isEmail(email)) {
				showNotice(rpsfwSupport.i18n.badEmail, 'error');
				return;
			}

			sending = true;
			clearNotice();
			$send.prop('disabled', true).text(rpsfwSupport.i18n.sending);

			$.post(rpsfwSupport.ajaxUrl, {
				action: rpsfwSupport.action,
				nonce: rpsfwSupport.nonce,
				name: name,
				email: email,
				site_url: siteUrl,
				gateway: gateway,
				message: message
			}).done(function (response) {
				if (response && response.success) {
					showNotice(response.data.message, 'success');
					// Keep name/email (likely reused), clear the message only.
					$form.find('#rpsfw-support-message').val('');
				} else {
					showNotice(
						(response && response.data && response.data.message) || rpsfwSupport.i18n.failed,
						'error'
					);
				}
			}).fail(function () {
				showNotice(rpsfwSupport.i18n.failed, 'error');
			}).always(function () {
				sending = false;
				$send.prop('disabled', false).text(rpsfwSupport.i18n.send);
			});
		});
	});
})(jQuery);
