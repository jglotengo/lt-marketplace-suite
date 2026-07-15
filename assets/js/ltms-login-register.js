/**
 * LTMS Vendor Registration Form — Cloudflare Turnstile + country/document dynamic fields.
 * FASE2B P0 FIX (CSP): extracted from inline <script> in vendor-parts/form-register.php.
 */
(function () {
    'use strict';

    // Cloudflare Turnstile callback (global, required by Turnstile API).
    window.onloadTurnstileCallback = function () {
        if (typeof turnstile !== 'undefined') {
            turnstile.render('.cf-turnstile', {
                callback: function (token) {
                    var el = document.getElementById('ltms-turnstile-token');
                    if (el) el.value = token;
                }
            });
        }
    };

    // Country → document type + phone placeholder + municipality toggle.
    var sel = document.getElementById('ltms-reg-vendor-country');
    var phone = document.getElementById('ltms-reg-phone');
    var docSel = document.getElementById('ltms-reg-document-type');

    var docOpts = {
        CO: [
            { v: '', l: 'Seleccionar...' },
            { v: 'CC', l: 'Cédula de Ciudadanía' },
            { v: 'CE', l: 'Cédula de Extranjería' },
            { v: 'NIT', l: 'NIT' },
            { v: 'PAS', l: 'Pasaporte' }
        ],
        MX: [
            { v: '', l: 'Seleccionar...' },
            { v: 'RFC', l: 'RFC' },
            { v: 'CURP', l: 'CURP' },
            { v: 'PAS', l: 'Pasaporte' }
        ]
    };

    var municipioWrap = document.getElementById('ltms-municipality-wrap');
    var municipioSel = document.getElementById('ltms-reg-municipality');

    function updateCountry(country) {
        if (phone) phone.placeholder = country === 'MX' ? '+52 55 0000 0000' : '+57 300 000 0000';
        if (docSel) {
            var opts = docOpts[country] || docOpts.CO;
            docSel.innerHTML = opts.map(function (o) {
                return '<option value="' + o.v + '">' + o.l + '</option>';
            }).join('');
        }
        if (municipioWrap) {
            var isCO = country === 'CO';
            municipioWrap.style.display = isCO ? '' : 'none';
            if (municipioSel) municipioSel.required = isCO;
        }
    }

    if (sel) {
        sel.addEventListener('change', function () { updateCountry(this.value); });
        updateCountry(sel.value);
    }
})();
