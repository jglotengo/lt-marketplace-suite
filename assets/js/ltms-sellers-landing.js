/**
 * LTMS Sellers Landing — interactive earnings calculator.
 * FASE2B P0 FIX (CSP): extracted from inline <script> in view-sellers-landing.php.
 * The commission rate is passed via data attribute on the input element.
 */
(function () {
    'use strict';
    var input = document.getElementById('ltms-calc-price');
    if (!input) return;
    var commission = parseFloat(input.dataset.commission || '0.15');
    var fmt = function (v) { return '$' + new Intl.NumberFormat('es-CO').format(Math.round(v)); };
    input.addEventListener('input', function () {
        var price = parseFloat(input.value) || 0;
        var fee = price * commission;
        var earn = price - fee;
        var totalEl = document.getElementById('ltms-calc-total');
        var feeEl = document.getElementById('ltms-calc-fee');
        var earnEl = document.getElementById('ltms-calc-earn');
        if (totalEl) totalEl.textContent = fmt(price);
        if (feeEl) feeEl.textContent = '-' + fmt(fee);
        if (earnEl) earnEl.textContent = fmt(earn);
    });
})();
