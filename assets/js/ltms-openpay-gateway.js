/* global OpenPay, ltmsOpenpay, jQuery */
/**
 * LTMS Openpay Gateway — WooCommerce checkout tokenization
 *
 * Intercepts WooCommerce's checkout_place_order_ltms_openpay event,
 * tokenizes the card with Openpay.js, stores the token in hidden fields,
 * then re-submits the form so process_payment() can charge it server-side.
 *
 * @package LTMS
 * @version 1.1.0
 */
( function ( $ ) {
	'use strict';

	if ( typeof ltmsOpenpay === 'undefined' ) {
		return;
	}

	var openpayReady = false;

	/**
	 * Initialize the Openpay SDK with the gateway credentials.
	 *
	 * @return {boolean} true if SDK loaded and initialized.
	 */
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
		var val = $( 'input[name="payment_method"]:checked' ).val();
		return val === 'ltms_openpay';
	}

	/**
	 * Core tokenization logic — shared by both the WC event and the submit fallback.
	 * Returns false to stop submission; re-submits after async tokenization.
	 */
	function handleCheckout() {
		// Token already set (re-submit after tokenization) → let WC proceed.
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

				// Device data (fraud detection)
				try {
					var deviceId = OpenPay.deviceData.setup( 'ltms-openpay-fields', 'ltms_openpay_device_hidden' );
					$( '#ltms_openpay_device' ).val( deviceId || '' );
				} catch ( e ) {
					$( '#ltms_openpay_device' ).val( '' );
				}

				// Unblock and re-submit — token is set, handler returns true next time
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

		return false; // Stop WC; we\'ll re-submit after tokenization
	}

	/**
	 * Primary listener: WooCommerce fires checkout_place_order_ltms_openpay
	 * when the user clicks "Place Order" with this gateway selected.
	 */
	$( document ).on( 'checkout_place_order_ltms_openpay', function () {
		return handleCheckout();
	} );

	/**
	 * Fallback listener: some page-builders / checkout plugins (e.g. WOOCCM)
	 * may intercept the checkout form submit before WooCommerce\'s checkout.js
	 * fires checkout_place_order_* events. This catches that case.
	 */
	$( document ).on( 'submit', 'form.woocommerce-checkout, form.checkout', function ( e ) {
		if ( ! isOpenpaySelected() ) {
			return true; // other gateway — don\'t interfere
		}
		var proceed = handleCheckout();
		if ( ! proceed ) {
			e.preventDefault();
			e.stopPropagation();
			return false;
		}
	} );

	// Reset token on WC AJAX checkout refresh (e.g. shipping update)
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

		// Format expiry as MM/AA
		$( document ).on( 'input', '#ltms-card-expiry', function () {
			var val = $( this ).val().replace( /\D/g, '' ).substring( 0, 4 );
			if ( val.length > 2 ) {
				val = val.substring( 0, 2 ) + '/' + val.substring( 2 );
			}
			$( this ).val( val );
		} );

		// CVV: digits only
		$( document ).on( 'input', '#ltms-card-cvv', function () {
			$( this ).val( $( this ).val().replace( /\D/g, '' ).substring( 0, 4 ) );
		} );
	} );

} )( jQuery );
