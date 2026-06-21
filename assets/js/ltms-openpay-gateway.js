/* global OpenPay, ltmsOpenpay, jQuery */
/**
 * LTMS Openpay Gateway — WooCommerce checkout tokenization
 *
 * Hooks directly on the Place Order button click to ensure tokenization
 * runs before any checkout manager plugins can intercept the form submit.
 *
 * @package LTMS
 * @version 1.2.0
 */
( function ( $ ) {
	'use strict';

	if ( typeof ltmsOpenpay === 'undefined' ) {
		return;
	}

	var openpayReady = false;

	function initOpenpay() {
		if ( typeof OpenPay === 'undefined' ) {
			return false;
		}
		if ( openpayReady ) {
			return true;
		}
		OpenPay.setId( ltmsOpenpay.merchant_id );
		OpenPay.setApiKey( ltmsOpenpay.public_key );
		OpenPay.setSandboxMode( ltmsOpenpay.is_sandbox );
		openpayReady = true;
		return true;
	}

	function showError( msg ) {
		var $err = $( '#ltms-openpay-card-errors' );
		$err.text( msg ).show();
		if ( $err.length ) {
			$( 'html, body' ).animate( { scrollTop: $err.offset().top - 80 }, 200 );
		}
	}

	function hideError() {
		$( '#ltms-openpay-card-errors' ).hide();
	}

	function isOpenpaySelected() {
		return $( 'input[name="payment_method"]:checked' ).val() === 'ltms_openpay';
	}

	function doTokenize() {
		// Already tokenized (re-submit after tokenization) → proceed
		if ( $( '#ltms_openpay_token' ).val() ) {
			return true;
		}

		if ( ! initOpenpay() ) {
			showError( ltmsOpenpay.i18n.sdk_unavailable );
			return false;
		}

		hideError();

		var expiry = $( '#ltms-card-expiry' ).val().split( '/' );
		var year   = ( expiry[1] || '' ).trim();

		var cardData = {
			card_number:      $( '#ltms-card-number' ).val().replace( /\s/g, '' ),
			holder_name:      $( '#ltms-card-name' ).val().trim(),
			expiration_month: ( expiry[0] || '' ).trim(),
			expiration_year:  year.length === 2 ? '20' + year : year,
			cvv2:             $( '#ltms-card-cvv' ).val(),
		};

		if ( ! cardData.card_number || ! cardData.holder_name ||
			 ! cardData.expiration_month || ! cardData.expiration_year || ! cardData.cvv2 ) {
			showError( ltmsOpenpay.i18n.fill_all_fields );
			return false;
		}

		var $form = $( 'form.woocommerce-checkout, form.checkout' ).first();
		$form.block( { message: null, overlayCSS: { background: '#fff', opacity: 0.6 } } );

		OpenPay.token.create(
			cardData,
			function ( res ) {
				$( '#ltms_openpay_token' ).val( res.data.id );
				try {
					var deviceId = OpenPay.deviceData.setup( 'ltms-openpay-fields', 'ltms_openpay_device_hidden' );
					$( '#ltms_openpay_device' ).val( deviceId || '' );
				} catch ( e ) {
					$( '#ltms_openpay_device' ).val( '' );
				}
				$form.unblock().submit();
			},
			function ( err ) {
				var code     = err.data && err.data.error_code ? err.data.error_code : 0;
				var messages = {
					1001: ltmsOpenpay.i18n.invalid_card,
					3001: ltmsOpenpay.i18n.card_declined,
					3002: ltmsOpenpay.i18n.card_expired,
					3003: ltmsOpenpay.i18n.insufficient_funds,
					3005: ltmsOpenpay.i18n.card_blocked,
				};
				showError( messages[ code ] || ltmsOpenpay.i18n.card_error );
				$form.unblock();
			}
		);

		return false;
	}

	// ── Primary: WooCommerce native event ────────────────────────────────────
	$( document ).on( 'checkout_place_order_ltms_openpay', function () {
		return doTokenize();
	} );

	// ── Fallback A: direct button click (highest priority, runs before WOOCCM) ──
	$( document ).on( 'click', '#place_order', function ( e ) {
		if ( ! isOpenpaySelected() ) {
			return true;
		}
		var proceed = doTokenize();
		if ( ! proceed ) {
			e.preventDefault();
			e.stopImmediatePropagation();
			return false;
		}
	} );

	// ── Fallback B: form submit (catches submit() calls from other JS) ────────
	$( document ).on( 'submit', 'form.woocommerce-checkout, form.checkout', function ( e ) {
		if ( ! isOpenpaySelected() ) {
			return true;
		}
		// If token is set we're in the re-submit after tokenization — let through
		if ( $( '#ltms_openpay_token' ).val() ) {
			return true;
		}
		e.preventDefault();
		e.stopImmediatePropagation();
		doTokenize();
		return false;
	} );

	// Reset token on checkout refresh
	$( document.body ).on( 'updated_checkout', function () {
		$( '#ltms_openpay_token' ).val( '' );
		$( '#ltms_openpay_device' ).val( '' );
	} );

	$( function () {
		initOpenpay();

		// Format card number as groups of 4
		$( document ).on( 'input', '#ltms-card-number', function () {
			var val = $( this ).val().replace( /\D/g, '' ).substring( 0, 16 );
			$( this ).val( val.match( /.{1,4}/g ) ? val.match( /.{1,4}/g ).join( ' ' ) : val );
		} );

		// Format expiry MM/AA
		$( document ).on( 'input', '#ltms-card-expiry', function () {
			var val = $( this ).val().replace( /\D/g, '' ).substring( 0, 4 );
			if ( val.length > 2 ) {
				val = val.substring( 0, 2 ) + '/' + val.substring( 2 );
			}
			$( this ).val( val );
		} );

		// CVV digits only
		$( document ).on( 'input', '#ltms-card-cvv', function () {
			$( this ).val( $( this ).val().replace( /\D/g, '' ).substring( 0, 4 ) );
		} );
	} );

} )( jQuery );
