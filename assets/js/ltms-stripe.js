/* global Stripe, ltmsStripe, jQuery */
/**
 * LTMS Stripe.js — Tokenización de tarjetas con Stripe Elements
 * Carga en checkout únicamente. Crea payment_method y lo envía al servidor.
 *
 * @package LTMS
 * @version 1.7.0
 */
( function ( $ ) {
	'use strict';

	if ( typeof ltmsStripe === 'undefined' || ! ltmsStripe.publishable_key ) {
		return;
	}

	var stripe      = Stripe( ltmsStripe.publishable_key );
	var elements    = stripe.elements();
	var cardElement = null;

	function mountCard() {
		var $container = $( '#ltms-stripe-card-element' );
		if ( ! $container.length ) {
			return;
		}
		cardElement = elements.create( 'card', {
			style: {
				base: {
					fontSize     : '16px',
					color        : '#32325d',
					fontFamily   : '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
					'::placeholder': { color: '#aab7c4' }
				},
				invalid: { color: '#fa755a' }
			},
			hidePostalCode: true
		} );
		cardElement.mount( '#ltms-stripe-card-element' );

		cardElement.on( 'change', function ( event ) {
			var $error = $( '#ltms-stripe-card-errors' );
			if ( event.error ) {
				$error.text( event.error.message ).show();
			} else {
				$error.text( '' ).hide();
			}
		} );
	}

	function handleCheckoutSubmit( e ) {
		// Only intercept if Stripe is the selected payment method
		if ( $( 'input[name="payment_method"]:checked' ).val() !== 'ltms_stripe' ) {
			return;
		}

		e.preventDefault();
		e.stopImmediatePropagation();

		var $form = $( this );
		$form.addClass( 'processing' ).block( {
			message   : null,
			overlayCSS: { background: '#fff', opacity: 0.6 }
		} );

		stripe.createPaymentMethod( {
			type: 'card',
			card: cardElement,
			billing_details: {
				name  : ( $( '#billing_first_name' ).val() || '' ) + ' ' + ( $( '#billing_last_name' ).val() || '' ),
				email : $( '#billing_email' ).val() || '',
				phone : $( '#billing_phone' ).val() || '',
				address: {
					line1      : $( '#billing_address_1' ).val() || '',
					city       : $( '#billing_city' ).val() || '',
					postal_code: $( '#billing_postcode' ).val() || '',
					country    : $( '#billing_country' ).val() || ''
				}
			}
		} ).then( function ( result ) {
			if ( result.error ) {
				$( '#ltms-stripe-card-errors' ).text( result.error.message ).show();
				$form.removeClass( 'processing' ).unblock();
			} else {
				$( 'input[name="_ltms_stripe_payment_method"]' ).val( result.paymentMethod.id );
				$form.removeClass( 'processing' ).unblock();
				// Re-submit without triggering our handler again
				$form.off( 'submit', handleCheckoutSubmit ).submit();
			}
		} );
	}

	$( function () {
		mountCard();

		// Re-mount after checkout update (WC AJAX refresh)
		$( document.body ).on( 'updated_checkout', mountCard );

		// Intercept form submit
		$( document.body ).on( 'submit', 'form.checkout, form#order_review', handleCheckoutSubmit );
	} );
} )( jQuery );
