/* global OpenPay, ltmsOpenpayMX, jQuery */
/**
 * LTMS Openpay MX — tokenización de tarjeta en checkout WooCommerce.
 *
 * E2E-BUYER-MX-CARD FIX (2026-11-20, P2): el gateway MX encolaba este archivo
 * pero nunca existió — el comprador de MX no tenía campos de tarjeta ni
 * tokenización: `openpay_mx_token` llegaba vacío y validate_fields() bloqueaba
 * SIEMPRE el método card. Espeja el patrón del gateway CO
 * (ltms-openpay-gateway.js): render de campos + tokenización por Openpay.js
 * antes del submit, con device session id para antifraude.
 *
 * Hooks directamente en el click de "Place Order" para asegurar que la
 * tokenización corre antes de que plugins de checkout Manager intercepten el
 * submit.
 *
 * @package LTMS
 * @version 1.0.0
 */
( function ( $ ) {
	'use strict';

	if ( typeof ltmsOpenpayMX === 'undefined' ) {
		return;
	}

	var i18n = $.extend(
		{
			fill_all_fields: 'Completa todos los datos de tu tarjeta.',
			invalid_card: 'Número de tarjeta inválido.',
			card_declined: 'La tarjeta fue rechazada.',
			card_expired: 'La tarjeta está expirada.',
			insufficient_funds: 'Fondos insuficientes.',
			card_blocked: 'La tarjeta está bloqueada.',
			card_error: 'No pudimos procesar tu tarjeta. Intenta de nuevo.',
			sdk_unavailable: 'El módulo de pago no está disponible. Recarga la página.',
			card_number: 'Número de tarjeta',
			card_name: 'Nombre del titular',
			card_expiry: 'MM/AA',
			card_cvv: 'CVV'
		},
		ltmsOpenpayMX.i18n || {}
	);

	var openpayReady = false;

	function initOpenpay() {
		if ( typeof OpenPay === 'undefined' ) {
			return false;
		}
		if ( openpayReady ) {
			return true;
		}
		OpenPay.setId( ltmsOpenpayMX.merchant_id );
		OpenPay.setApiKey( ltmsOpenpayMX.public_key );
		OpenPay.setSandboxMode( !! ltmsOpenpayMX.sandbox );
		openpayReady = true;
		return true;
	}

	function isOpenpayMXSelected() {
		return $( 'input[name="payment_method"]:checked' ).val() === 'ltms_openpay_mx';
	}

	function isCardMethod() {
		return ltmsOpenpayMX.method === 'card';
	}

	/**
	 * El payment_fields del gateway solo renderiza el contenedor vacío
	 * (#ltms-openpay-mx-card) — los campos de tarjeta los inyecta este script.
	 * Los inputs van SIN atributo name (revisión 2026-11-20): el PAN y el CVV
	 * NUNCA deben postearse al servidor — solo el token llega por POST.
	 */
	function renderCardFields() {
		if ( ! isCardMethod() || $( '#ltms-mx-card-number' ).length ) {
			return;
		}
		var $container = $( '#ltms-openpay-mx-card' );
		if ( ! $container.length ) {
			return;
		}
		$container.append(
			'<div class="ltms-mx-field"><input type="text" id="ltms-mx-card-number" autocomplete="cc-number" inputmode="numeric" placeholder="' + i18n.card_number + '" /></div>' +
			'<div class="ltms-mx-field"><input type="text" id="ltms-mx-card-name" autocomplete="cc-name" placeholder="' + i18n.card_name + '" /></div>' +
			'<div class="ltms-mx-field-row">' +
				'<input type="text" id="ltms-mx-card-expiry" autocomplete="cc-exp" inputmode="numeric" placeholder="' + i18n.card_expiry + '" maxlength="5" />' +
				'<input type="password" id="ltms-mx-card-cvv" autocomplete="cc-csc" inputmode="numeric" placeholder="' + i18n.card_cvv + '" maxlength="4" />' +
			'</div>' +
			'<div id="ltms-openpay-mx-card-errors" class="ltms-mx-error" style="display:none;color:#b32d2e;padding:6px 0;"></div>'
		);
	}

	function showError( msg ) {
		var $err = $( '#ltms-openpay-mx-card-errors' );
		$err.text( msg ).show();
		if ( $err.length ) {
			$( 'html, body' ).animate( { scrollTop: $err.offset().top - 80 }, 200 );
		}
	}

	function hideError() {
		$( '#ltms-openpay-mx-card-errors' ).hide();
	}

	function doTokenize() {
		// Ya tokenizado (re-submit tras tokenización) — dejar pasar.
		if ( $( '#ltms_openpay_mx_token' ).val() ) {
			return true;
		}

		if ( ! initOpenpay() ) {
			showError( i18n.sdk_unavailable );
			return false;
		}

		hideError();

		var expiry = $( '#ltms-mx-card-expiry' ).val().split( '/' );
		var year   = ( expiry[1] || '' ).trim();

		// Openpay espera expiration_year en 2 dígitos (ej. "28"), no 4 ("2028").
		var year2 = year.length === 4 ? year.substring( 2 ) : year;

		var cardData = {
			card_number:      $( '#ltms-mx-card-number' ).val().replace( /\s/g, '' ),
			holder_name:      $( '#ltms-mx-card-name' ).val().trim(),
			expiration_month: ( expiry[0] || '' ).trim(),
			expiration_year:  year2,
			cvv2:             $( '#ltms-mx-card-cvv' ).val()
		};

		if ( ! cardData.card_number || ! cardData.holder_name ||
			 ! cardData.expiration_month || ! cardData.expiration_year || ! cardData.cvv2 ) {
			showError( i18n.fill_all_fields );
			return false;
		}

		var $form = $( 'form.woocommerce-checkout, form.checkout' ).first();
		$form.block( { message: null, overlayCSS: { background: '#fff', opacity: 0.6 } } );

		OpenPay.token.create(
			cardData,
			function ( res ) {
				$( '#ltms_openpay_mx_token' ).val( res.data.id );
				try {
					var deviceId = OpenPay.deviceData.setup( 'ltms-openpay-mx-fields', 'ltms_openpay_mx_device' );
					$( '#ltms_openpay_mx_device' ).val( deviceId || '' );
				} catch ( e ) {
					$( '#ltms_openpay_mx_device' ).val( '' );
				}
				$form.unblock().submit();
			},
			function ( err ) {
				var code     = err.data && err.data.error_code ? err.data.error_code : 0;
				var messages = {
					1001: i18n.invalid_card,
					3001: i18n.card_declined,
					3002: i18n.card_expired,
					3003: i18n.insufficient_funds,
					3005: i18n.card_blocked
				};
				if ( window.console && console.error ) {
					console.error( 'Openpay MX token error:', err );
				}
				showError( messages[ code ] || i18n.card_error );
				$form.unblock();
			}
		);

		return false;
	}

	// ── Primario: evento nativo de WooCommerce ─────────────────────────────
	$( document ).on( 'checkout_place_order_ltms_openpay_mx', function () {
		if ( ! isCardMethod() ) {
			return true;
		}
		return doTokenize();
	} );

	// ── Fallback A: click directo en el botón (antes de WOOCCM) ────────────
	$( document ).on( 'click', '#place_order', function ( e ) {
		if ( ! isOpenpayMXSelected() || ! isCardMethod() ) {
			return true;
		}
		var proceed = doTokenize();
		if ( ! proceed ) {
			e.preventDefault();
			e.stopImmediatePropagation();
			return false;
		}
	} );

	// ── Fallback B: submit del form (atrapa submit() de otros JS) ──────────
	$( document ).on( 'submit', 'form.woocommerce-checkout, form.checkout', function ( e ) {
		if ( ! isOpenpayMXSelected() || ! isCardMethod() ) {
			return true;
		}
		// Con token seteado estamos en el re-submit tras tokenizar — dejar pasar.
		if ( $( '#ltms_openpay_mx_token' ).val() ) {
			return true;
		}
		e.preventDefault();
		e.stopImmediatePropagation();
		doTokenize();
		return false;
	} );

	// Reset del token en cada refresh de checkout (WC ajax).
	$( document.body ).on( 'updated_checkout', function () {
		$( '#ltms_openpay_mx_token' ).val( '' );
		$( '#ltms_openpay_mx_device' ).val( '' );
	} );

	$( function () {
		renderCardFields();
		initOpenpay();

		// Formatear número de tarjeta en grupos de 4.
		$( document ).on( 'input', '#ltms-mx-card-number', function () {
			var val = $( this ).val().replace( /\D/g, '' ).substring( 0, 16 );
			$( this ).val( val.match( /.{1,4}/g ) ? val.match( /.{1,4}/g ).join( ' ' ) : val );
		} );

		// Formatear vencimiento MM/AA.
		$( document ).on( 'input', '#ltms-mx-card-expiry', function () {
			var val = $( this ).val().replace( /\D/g, '' ).substring( 0, 4 );
			if ( val.length > 2 ) {
				val = val.substring( 0, 2 ) + '/' + val.substring( 2 );
			}
			$( this ).val( val );
		} );

		// CVV: solo dígitos.
		$( document ).on( 'input', '#ltms-mx-card-cvv', function () {
			$( this ).val( $( this ).val().replace( /\D/g, '' ) );
		} );

		// Re-render tras refresh AJAX (el contenedor se re-imprime).
		$( document.body ).on( 'updated_checkout', function () {
			renderCardFields();
		} );
	} );
}( jQuery ) );
