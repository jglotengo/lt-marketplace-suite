/* global jQuery, flatpickr, ltmsBookingData */
/**
 * LTMS Booking Calendar — v2.0.0
 * Flatpickr range picker con precios dinámicos y validación de disponibilidad.
 */
(function ($) {
    'use strict';

    if (typeof ltmsBookingData === 'undefined') return;

    var data        = ltmsBookingData;
    var $widget     = $('#ltms-booking-widget');
    var $checkin    = $('#ltms-checkin');
    var $checkout   = $('#ltms-checkout');
    var $summary    = $('#ltms-booking-summary');
    var $error      = $('#ltms-calendar-error');
    var $nightsLbl  = $('#ltms-nights-label');
    var $totalPx    = $('#ltms-total-price');
    var $dispCi     = $('#ltms-display-checkin');
    var $dispCo     = $('#ltms-display-checkout');
    var priceCache  = {};
    var fp;

    // Build disable array for Flatpickr from blocked dates.
    function buildDisabled() {
        var disabled = data.blockedDates.slice();
        // Also disable past dates.
        var today = new Date();
        today.setHours(0, 0, 0, 0);
        disabled.push(function (date) {
            return date < today;
        });
        if (data.minNights > 0) {
            // advance booking: minimum days ahead
        }
        return disabled;
    }

    function formatDate(d) {
        if (!d) return '';
        var y = d.getFullYear();
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var dd = String(d.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + dd;
    }

    function showError(msg) {
        $error.text(msg).show();
        $summary.hide();
    }

    function clearError() {
        $error.hide().text('');
    }

    function fetchPrice(checkin, checkout) {
        var cacheKey = checkin + '_' + checkout;
        if (priceCache[cacheKey]) {
            renderSummary(checkin, checkout, priceCache[cacheKey]);
            return;
        }
        $.ajax({
            url: data.restUrl + data.productId + '/price',
            method: 'GET',
            data: { checkin_date: checkin, checkout_date: checkout },
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', data.nonce);
            },
            success: function (resp) {
                priceCache[cacheKey] = resp;
                renderSummary(checkin, checkout, resp);
            },
            error: function () {
                // Fallback: client-side calc.
                var nights = Math.round((new Date(checkout) - new Date(checkin)) / 86400000);
                renderSummary(checkin, checkout, {
                    nights: nights,
                    total: data.pricePerNight * nights,
                    total_formatted: data.currency + (data.pricePerNight * nights).toLocaleString()
                });
            }
        });
    }

    function renderSummary(checkin, checkout, resp) {
        var nights = resp.nights || 0;
        var nightLabel = nights === 1 ? data.i18n.night : data.i18n.nights;
        $nightsLbl.text(nights + ' ' + nightLabel);
        $totalPx.text(resp.total_formatted);
        $dispCi.text(checkin + ' ' + data.checkinTime);
        $dispCo.text(checkout + ' ' + data.checkoutTime);
        $summary.show();
    }

    // Init Flatpickr.
    fp = flatpickr('#ltms-date-range', {
        mode:            'range',
        dateFormat:      'Y-m-d',
        minDate:         'today',
        locale:          'es',
        showMonths:      window.innerWidth >= 768 ? 2 : 1,
        disable:         buildDisabled(),
        onReady: function () {
            $widget.removeClass('is-loading');
        },
        onChange: function (selectedDates) {
            clearError();
            if (selectedDates.length < 2) {
                $checkin.val('');
                $checkout.val('');
                $summary.hide();
                return;
            }
            var ci = formatDate(selectedDates[0]);
            var co = formatDate(selectedDates[1]);

            // Validate min nights.
            if (data.minNights > 1) {
                var nights = Math.round((selectedDates[1] - selectedDates[0]) / 86400000);
                if (nights < data.minNights) {
                    showError(data.i18n.minNightsError);
                    fp.clear();
                    return;
                }
            }

            // Validate max nights.
            if (data.maxNights > 0) {
                var nMax = Math.round((selectedDates[1] - selectedDates[0]) / 86400000);
                if (nMax > data.maxNights) {
                    showError(data.i18n.maxNightsError || ('Máximo ' + data.maxNights + ' noches.'));
                    fp.clear();
                    return;
                }
            }

            $checkin.val(ci);
            $checkout.val(co);
            fetchPrice(ci, co);
        }
    });

    // Validate before add-to-cart.
    $('form.cart').on('submit', function (e) {
        if ($widget.length && (!$checkin.val() || !$checkout.val())) {
            e.preventDefault();
            showError(data.i18n.selectDates);
            $('html, body').animate({ scrollTop: $widget.offset().top - 80 }, 400);
        }
    });

    // Reload blocked dates periodically (every 5 minutes) to catch other bookings.
    setInterval(function () {
        $.ajax({
            url: data.restUrl + data.productId + '/blocked-dates',
            method: 'GET',
            beforeSend: function (xhr) {
                xhr.setRequestHeader('X-WP-Nonce', data.nonce);
            },
            success: function (resp) {
                if (resp.blocked_dates) {
                    data.blockedDates = resp.blocked_dates;
                    fp.set('disable', buildDisabled());
                }
            }
        });
    }, 300000);

}(jQuery));
