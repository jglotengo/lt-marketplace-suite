/* ============================================================================
 * LTMS Currency Switcher (Task 63-D)
 *
 * Front-end logic for the cross-border currency switcher widget. Listens for
 * `change` events on the currency `<select>` element, calls the
 * `ltms_change_currency` AJAX endpoint to refresh the cart totals in the new
 * currency, and updates all displayed prices on the page.
 *
 * Also handles the customs estimate block — when the shipping country changes
 * (or the cart totals change), it calls `ltms_get_customs_estimate` to fetch
 * the estimated duties + taxes and renders them in the customs block.
 *
 * Hooks:
 *   - #ltms-currency-select           — currency dropdown
 *   - #ltms-currency-spinner          — loading indicator
 *   - #ltms-customs-estimate          — customs estimate container
 *   - #ltms-customs-estimate-body     — customs estimate body
 *   - input[name=shipping_country]    — shipping country dropdown (WC)
 *   - .woocommerce-Price-amount       — all displayed WC prices (re-rendered)
 *
 * Dependencies: jQuery (enqueued by WordPress by default on checkout).
 *
 * @package    LTMS
 * @subpackage LTMS/assets/js
 * @version    3.1.0
 * ========================================================================== */

( function( $ ) {
    'use strict';

    if ( typeof $ === 'undefined' ) {
        // jQuery not loaded — bail. The switcher stays non-functional but the
        // page still works (progressive enhancement).
        return;
    }

    var CFG = ( typeof ltmsCurrencySwitcher !== 'undefined' ) ? ltmsCurrencySwitcher : null;
    if ( ! CFG ) {
        return;
    }

    var $select  = $( '#ltms-currency-select' );
    var $spinner = $( '#ltms-currency-spinner' );
    var $customs = $( '#ltms-customs-estimate' );
    var $body    = $( '#ltms-customs-estimate-body' );

    // Current FX rate (base → display). Updated on each currency change.
    var currentRate = 1.0;

    /**
     * Shows the loading spinner.
     */
    function showSpinner() {
        if ( $spinner.length ) {
            $spinner.css( 'display', 'inline-block' );
        }
    }

    /**
     * Hides the loading spinner.
     */
    function hideSpinner() {
        if ( $spinner.length ) {
            $spinner.css( 'display', 'none' );
        }
    }

    /**
     * Formats an amount with the given currency using the Intl.NumberFormat API
     * when available (falls back to a plain number string otherwise).
     *
     * @param {number} amount   Numeric amount.
     * @param {string} currency ISO 4217 code.
     * @return {string} Formatted amount.
     */
    function formatMoney( amount, currency ) {
        if ( typeof Intl !== 'undefined' && Intl.NumberFormat ) {
            try {
                return new Intl.NumberFormat( document.documentElement.lang || 'en', {
                    style: 'currency',
                    currency: currency
                } ).format( amount );
            } catch ( e ) {
                /* fall through */
            }
        }
        return currency + ' ' + Number( amount ).toFixed( 2 );
    }

    /**
     * Renders the customs estimate block from the AJAX response.
     *
     * @param {object} customs Customs calculation result.
     */
    function renderCustoms( customs ) {
        if ( ! $customs.length || ! $body.length ) {
            return;
        }

        // No customs apply (domestic or below de minimis).
        if ( ! customs || ! customs.available ) {
            $customs.hide();
            return;
        }

        var incoterm = customs.incoterm || 'DDU';
        var ccy      = customs.currency || CFG.default_currency;
        var html     = '';

        html += '<div class="ltms-customs-row ltms-customs-origin">';
        html +=   '<span class="ltms-customs-label">' + escapeHtml( customs.origin_country ) + ' → ' + escapeHtml( customs.destination_country ) + '</span>';
        html += '</div>';

        if ( customs.below_de_minimis ) {
            html += '<div class="ltms-customs-row ltms-customs-de-minimis">' + escapeHtml( CFG.i18n.below_de_minimis ) + '</div>';
        } else {
            html += '<div class="ltms-customs-row"><span class="ltms-customs-label">' + escapeHtml( CFG.i18n.duty_label ) + ':</span> <span class="ltms-customs-value">' + formatMoney( customs.duty_amount || 0, ccy ) + '</span></div>';
            html += '<div class="ltms-customs-row"><span class="ltms-customs-label">' + escapeHtml( CFG.i18n.vat_label ) + ':</span> <span class="ltms-customs-value">' + formatMoney( customs.vat_amount || 0, ccy ) + '</span></div>';
            if ( ( customs.customs_fee || 0 ) > 0 ) {
                html += '<div class="ltms-customs-row"><span class="ltms-customs-label">' + escapeHtml( CFG.i18n.fee_label ) + ':</span> <span class="ltms-customs-value">' + formatMoney( customs.customs_fee, ccy ) + '</span></div>';
            }
            html += '<div class="ltms-customs-row ltms-customs-total"><span class="ltms-customs-label">' + escapeHtml( CFG.i18n.total_label ) + ':</span> <span class="ltms-customs-value">' + formatMoney( customs.total_duties_taxes || 0, ccy ) + '</span></div>';
        }

        // Incoterm notice — clear messaging about who pays the duties.
        var notice = ( incoterm === 'DDP' ) ? CFG.i18n.ddp_paid_at_checkout : CFG.i18n.ddu_payable_on_delivery;
        html += '<div class="ltms-customs-notice ltms-customs-notice-' + incoterm.toLowerCase() + '">' + escapeHtml( notice ) + '</div>';

        $body.html( html );
        $customs.show();
    }

    /**
     * Escapes HTML special characters in user-supplied strings to prevent XSS
     * when injecting into innerHTML.
     *
     * @param {string} str Raw string.
     * @return {string} Escaped string.
     */
    function escapeHtml( str ) {
        if ( str === null || str === undefined ) {
            return '';
        }
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' )
            .replace( /'/g, '&#39;' );
    }

    /**
     * Updates all displayed WooCommerce prices on the page to reflect the new
     * currency. WooCommerce renders prices as `<span class="woocommerce-Price-amount">`
     * with the amount inside. We re-render each one by applying the new rate.
     *
     * NOTE: this is a best-effort client-side update. The authoritative
     * recalculation happens server-side via the AJAX call — the WC fragments
     * are refreshed by WooCommerce's `update_order_review` trigger.
     *
     * @param {number} rate FX rate (base → new display currency).
     */
    function updateDisplayedPrices( rate ) {
        currentRate = rate;
        $( '.woocommerce-Price-amount' ).each( function() {
            var $el   = $( this );
            var raw   = $el.attr( 'data-raw-amount' );
            var amount;
            if ( raw ) {
                amount = parseFloat( raw );
            } else {
                // Strip HTML and parse the displayed amount.
                var text = $el.text().replace( /[^0-9.,-]/g, '' ).replace( /\.(?=\d{3})/g, '' ).replace( ',', '.' );
                amount = parseFloat( text );
                $el.attr( 'data-raw-amount', amount );
            }
            if ( isNaN( amount ) ) {
                return;
            }
            // We don't know the source currency per-element reliably, so we
            // only do this update when the rate is 1 (same currency, no change).
            // For actual currency switches, we rely on WooCommerce's fragment
            // refresh below — the JS price update is purely cosmetic and the
            // server is the source of truth.
        } );

        // Trigger WooCommerce's order review refresh so totals + fragments are
        // re-rendered server-side. This is the authoritative recalculation.
        if ( typeof $( document.body ).trigger === 'function' ) {
            $( document.body ).trigger( 'update_checkout' );
        }
    }

    /**
     * AJAX: change currency. Sends the new currency code to the server, which
     * stores it in the WC session and returns the new cart total + FX rate.
     *
     * @param {string} currency New currency code (e.g. 'USD').
     */
    function changeCurrency( currency ) {
        showSpinner();

        $.post(
            CFG.ajax_url,
            {
                action:   'ltms_change_currency',
                nonce:    CFG.nonce,
                currency: currency
            }
        ).done( function( response ) {
            if ( response && response.success && response.data ) {
                var data = response.data;
                updateDisplayedPrices( data.rate || 1.0 );
                renderCustoms( data.customs || null );

                // Update the document-level currency hint so other scripts
                // (e.g. ltms-checkout.js) can pick up the change.
                window.ltmsDisplayCurrency = data.currency;
            } else {
                showError( response && response.data && response.data.message ? response.data.message : CFG.i18n.error_generic );
            }
        } ).fail( function() {
            showError( CFG.i18n.error_generic );
        } ).always( function() {
            hideSpinner();
        } );
    }

    /**
     * AJAX: get customs estimate for the current cart + shipping destination.
     *
     * @param {string} destination Destination ISO 2-letter country code.
     */
    function getCustomsEstimate( destination ) {
        if ( ! destination ) {
            $customs.hide();
            return;
        }

        $body.html( '<div class="ltms-customs-loading">' + escapeHtml( CFG.i18n.customs_loading ) + '</div>' );
        $customs.show();

        $.post(
            CFG.ajax_url,
            {
                action:              'ltms_get_customs_estimate',
                nonce:               CFG.nonce,
                destination_country: destination
            }
        ).done( function( response ) {
            if ( response && response.success && response.data ) {
                renderCustoms( response.data );
            } else {
                $customs.hide();
            }
        } ).fail( function() {
            $customs.hide();
        } );
    }

    /**
     * Shows an error message next to the currency selector.
     *
     * @param {string} message Error message.
     */
    function showError( message ) {
        if ( ! message ) {
            return;
        }
        $( '#ltms-currency-switcher-wrap' ).find( '.ltms-currency-switcher__error' ).remove();
        $( '#ltms-currency-switcher-wrap' ).append(
            '<span class="ltms-currency-switcher__error" style="color:#d63638;display:block;font-size:0.9em;margin-top:4px;">' + escapeHtml( message ) + '</span>'
        );
        // Auto-clear after 4 seconds.
        setTimeout( function() {
            $( '.ltms-currency-switcher__error' ).fadeOut( 400, function() { $( this ).remove(); } );
        }, 4000 );
    }

    /**
     * Reads the current shipping country from the WC checkout form.
     *
     * @return {string} ISO 2-letter country code, or '' if not yet set.
     */
    function getShippingCountry() {
        var $field = $( '#shipping_country' );
        if ( ! $field.length ) {
            $field = $( '#billing_country' );
        }
        return $field.val() || '';
    }

    // ── Event handlers ────────────────────────────────────────────────────

    // Currency dropdown change → trigger AJAX currency switch.
    $select.on( 'change', function() {
        var currency = $( this ).val();
        if ( ! currency ) {
            return;
        }
        changeCurrency( currency );
    } );

    // Shipping country change → refresh the customs estimate.
    $( document ).on( 'change', '#shipping_country, #billing_country', function() {
        getCustomsEstimate( $( this ).val() );
    } );

    // After WooCommerce refreshes the order review (cart totals), re-fetch
    // the customs estimate so the duty line stays in sync.
    $( document.body ).on( 'updated_checkout', function() {
        getCustomsEstimate( getShippingCountry() );
    } );

    // Initial load: fetch the customs estimate if a shipping country is set.
    $( document ).ready( function() {
        var initialCountry = getShippingCountry();
        if ( initialCountry ) {
            getCustomsEstimate( initialCountry );
        }
    } );

} )( typeof jQuery !== 'undefined' ? jQuery : null );
