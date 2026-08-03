/**
 * Shared gateway refund panel (admin order / subscription screens).
 *
 * Refunds a specific payment (Stripe charge / PayPal capture) that backs an
 * order or subscription. Clicking "Refund" on a row shows a confirmation
 * popup (which mentions cancellation when the "Cancel subscription" box is
 * ticked) and posts to the panel's AJAX action.
 *
 * @package RestorePayPalStandard
 */
( function ( $ ) {
	'use strict';

	if ( typeof rpsfwRefundPanel === 'undefined' ) {
		return;
	}

	var strings = rpsfwRefundPanel.strings || {};

	function showFeedback( $panel, message, isError ) {
		$panel.find( '.rpsfw-refund-feedback' )
			.removeClass( 'notice-success notice-error' )
			.addClass( 'notice' )
			.addClass( isError ? 'notice-error' : 'notice-success' )
			.html( '<p>' + $( '<div/>' ).text( message ).html() + '</p>' )
			.show();
	}

	$( document ).on( 'click', '.rpsfw-refund-charge-btn', function ( e ) {
		e.preventDefault();

		var $btn   = $( this );
		var $row   = $btn.closest( '.rpsfw-refund-row' );
		var $panel = $btn.closest( '.rpsfw-refund-panel' );

		if ( $btn.prop( 'disabled' ) ) {
			return;
		}

		var sourceId   = String( $row.data( 'source-id' ) );
		var refundable = parseFloat( $row.data( 'refundable' ) ) || 0;
		var amount     = parseFloat( $row.find( '.rpsfw-refund-amount' ).val() );
		var cancelSub  = $row.find( '.rpsfw-cancel-sub' ).is( ':checked' );

		if ( isNaN( amount ) || amount <= 0 || amount > refundable + 0.0001 ) {
			window.alert( strings.invalid || 'Enter a valid amount.' );
			return;
		}

		var amountLabel = amount.toFixed( 2 );
		var template    = cancelSub ? ( strings.confirm_cancel || strings.confirm ) : strings.confirm;
		if ( ! window.confirm( ( template || 'Refund %s?' ).replace( '%s', amountLabel ) ) ) {
			return;
		}

		var data = {
			action:              $panel.data( 'action' ),
			nonce:               $panel.data( 'nonce' ),
			context_id:          $panel.data( 'context-id' ),
			source_id:           sourceId,
			amount:              amount,
			cancel_subscription: cancelSub ? 1 : 0
		};

		$btn.prop( 'disabled', true ).data( 'label', $btn.text() ).text( strings.processing || 'Processing...' );

		$.post( rpsfwRefundPanel.ajax_url, data ).done( function ( response ) {
			if ( response && response.success ) {
				var res = response.data || {};

				if ( res.charges && res.charges.length ) {
					$.each( res.charges, function ( i, c ) {
						var $r = $panel.find( '.rpsfw-refund-row' ).filter( function () {
							return String( $( this ).data( 'source-id' ) ) === String( c.source_id );
						} );
						if ( ! $r.length ) {
							return;
						}
						$r.data( 'refundable', c.refundable );
						$r.find( '.rpsfw-refundable-cell' ).html( c.refundable_html );
						var $amt = $r.find( '.rpsfw-refund-amount' );
						$amt.attr( 'max', c.refundable.toFixed( 2 ) ).val( c.refundable.toFixed( 2 ) );
						if ( c.refundable <= 0 ) {
							$amt.prop( 'disabled', true ).val( '' );
							$r.find( '.rpsfw-refund-charge-btn' )
								.prop( 'disabled', true )
								.text( strings.fully || 'Fully refunded' );
						}

						// Update (or add) the status line below the button.
						if ( typeof c.status !== 'undefined' ) {
							var $btnCell = $r.find( '.rpsfw-refund-charge-btn' ).closest( 'td' );
							var $status  = $btnCell.find( '.rpsfw-row-status' );
							if ( c.status ) {
								if ( ! $status.length ) {
									$status = $( '<div class="rpsfw-row-status"></div>' ).appendTo( $btnCell );
								}
								$status.text( c.status );
							} else if ( $status.length ) {
								$status.remove();
							}
						}
					} );
				}

				showFeedback( $panel, res.message || strings.success || 'Refund completed.', false );

				if ( res.cancelled ) {
					$panel.find( '.rpsfw-cancel-sub' ).prop( 'checked', false ).prop( 'disabled', true );
				}
				if ( ! $btn.prop( 'disabled' ) ) {
					$btn.text( $btn.data( 'label' ) );
				}
			} else {
				var msg = ( response && response.data && response.data.message ) ? response.data.message : ( strings.error || 'Refund failed.' );
				showFeedback( $panel, msg, true );
				$btn.prop( 'disabled', false ).text( $btn.data( 'label' ) );
			}
		} ).fail( function () {
			showFeedback( $panel, strings.error || 'Refund failed.', true );
			$btn.prop( 'disabled', false ).text( $btn.data( 'label' ) );
		} );
	} );

	/**
	 * Scroll to our refund panel and flash it, expanding the metabox first if
	 * it happens to be collapsed.
	 */
	function jumpToPanel() {
		var panel = document.querySelector( '.rpsfw-refund-panel' );
		if ( ! panel ) {
			return;
		}
		var postbox = panel.closest ? panel.closest( '.postbox' ) : null;
		if ( postbox && postbox.classList.contains( 'closed' ) ) {
			postbox.classList.remove( 'closed' );
		}
		panel.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		panel.classList.remove( 'rpsfw-flash' );
		// Force reflow so the animation restarts on repeated clicks.
		void panel.offsetWidth;
		panel.classList.add( 'rpsfw-flash' );
	}

	// The two refund entry points (WooCommerce's native "Refund" button and our
	// panel) are confusing together. On orders where our panel is shown, take
	// over the native button: block its refund form and jump to our panel
	// instead. A capture-phase listener runs before WooCommerce's own bubbling
	// handler, so stopping propagation here prevents the native UI from opening.
	document.addEventListener( 'click', function ( e ) {
		var target = e.target;
		if ( ! target || ! target.closest ) {
			return;
		}
		if ( ! target.closest( '.refund-items' ) ) {
			return;
		}
		// Only intercept when our panel exists on this order screen.
		if ( ! document.querySelector( '.rpsfw-refund-panel' ) ) {
			return;
		}
		e.preventDefault();
		e.stopPropagation();
		if ( e.stopImmediatePropagation ) {
			e.stopImmediatePropagation();
		}
		jumpToPanel();
	}, true );
} )( jQuery );
