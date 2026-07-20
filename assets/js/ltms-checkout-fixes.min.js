// LTMS Checkout Fixes — v2.9.230
// This file is loaded via wp_enqueue_script on checkout pages.
// Country-specific labels are passed via wp_localize_script (window.ltmsCheckoutFixes).
(function(){
    'use strict';
    var scope = document.querySelector('.pv-scope.pv-checkout');
    if (!scope) return;

    var config = window.ltmsCheckoutFixes || { country: 'CO' };
    var ltmsCountry = config.country;

    // CSS injection (survives SG Optimizer because it's done via JS DOM)
    if (!document.getElementById('ltms-checkout-fixes-css')) {
        var style = document.createElement('style');
        style.id = 'ltms-checkout-fixes-css';
        style.textContent = [
            '.pv-scope.pv-checkout .woocommerce-shipping-fields{display:none !important;}',
            '.pv-scope.pv-checkout .woocommerce-shipping-fields.shipping-fields--visible{display:block !important;}',
            '.pv-scope.pv-checkout #billing_address_1_field label .optional,' +
            '.pv-scope.pv-checkout #billing_state_field label .optional,' +
            '.pv-scope.pv-checkout #shipping_address_1_field label .optional,' +
            '.pv-scope.pv-checkout #shipping_state_field label .optional{display:none !important;}'
        ].join('\n');
        document.head.appendChild(style);
    }

    var labelMap = {};
    if (ltmsCountry === 'CO') {
        labelMap = {
            'billing_state': 'Departamento', 'shipping_state': 'Departamento',
            'billing_city': 'Municipio', 'shipping_city': 'Municipio',
            'billing_country': 'País', 'shipping_country': 'País',
            'billing_postcode': 'Código postal', 'shipping_postcode': 'Código postal',
            'billing_address_1': 'Dirección', 'shipping_address_1': 'Dirección',
            'billing_address_2': 'Apartamento, suite, etc. (opcional)', 'shipping_address_2': 'Apartamento, suite, etc. (opcional)'
        };
    } else if (ltmsCountry === 'MX') {
        labelMap = {
            'billing_state': 'Estado', 'shipping_state': 'Estado',
            'billing_city': 'Municipio / Alcaldía', 'shipping_city': 'Municipio / Alcaldía',
            'billing_country': 'País', 'shipping_country': 'País',
            'billing_postcode': 'Código postal', 'shipping_postcode': 'Código postal',
            'billing_address_1': 'Dirección', 'shipping_address_1': 'Dirección',
            'billing_address_2': 'Apartamento, suite, etc. (opcional)', 'shipping_address_2': 'Apartamento, suite, etc. (opcional)'
        };
    }

    function fixFieldLabels() {
        Object.keys(labelMap).forEach(function(fieldKey) {
            var newLabel = labelMap[fieldKey];
            var labelEl = scope.querySelector('label[for="' + fieldKey + '"]');
            if (!labelEl) return;
            if (labelEl.getAttribute('data-ltms-label-fixed') === '1') return;
            var abbr = labelEl.querySelector('abbr.required, abbr');
            var optionalSpan = labelEl.querySelector('span.optional, .optional');
            labelEl.innerHTML = '';
            labelEl.appendChild(document.createTextNode(newLabel));
            if (abbr) { labelEl.appendChild(document.createTextNode(' ')); labelEl.appendChild(abbr); }
            if (optionalSpan && newLabel.toLowerCase().indexOf('(opcional)') === -1 && newLabel.toLowerCase().indexOf('opcional') === -1) {
                labelEl.appendChild(document.createTextNode(' ')); labelEl.appendChild(optionalSpan);
            }
            labelEl.setAttribute('data-ltms-label-fixed', '1');
        });

        var requiredFields = ['billing_first_name','billing_last_name','billing_country','billing_address_1','billing_state','shipping_first_name','shipping_last_name','shipping_country','shipping_address_1','shipping_state'];
        requiredFields.forEach(function(fid) {
            var el = scope.querySelector('#' + fid);
            if (el && !el.required) el.setAttribute('required', 'required');
        });

        var phoneFields = scope.querySelectorAll('#billing_phone_field, #shipping_phone_field');
        if (phoneFields.length > 1) { for (var p = 1; p < phoneFields.length; p++) { phoneFields[p].style.display = 'none'; } }
        var emailFields = scope.querySelectorAll('#billing_email_field, #shipping_email_field');
        if (emailFields.length > 1) { for (var e = 1; e < emailFields.length; e++) { emailFields[e].style.display = 'none'; } }

        var countrySelect = scope.querySelector('#billing_country, #shipping_country');
        if (countrySelect && countrySelect.value !== ltmsCountry) {
            countrySelect.value = ltmsCountry;
            countrySelect.dispatchEvent(new Event('change', { bubbles: true }));
        }

        var shipDiffWrap = scope.querySelector('#ship-to-different-address');
        if (shipDiffWrap && !shipDiffWrap.getAttribute('data-ltms-styled')) {
            shipDiffWrap.setAttribute('data-ltms-styled', '1');
            shipDiffWrap.style.cssText = 'display:flex;align-items:flex-start;gap:10px;padding:14px 16px;margin:16px 0 12px;background:#FFF9F9;border:1px solid #FFD6D6;border-left:4px solid #E80001;border-radius:10px;cursor:pointer;transition:all .15s';
            var shipDiffLabel = shipDiffWrap.querySelector('label');
            if (shipDiffLabel) {
                shipDiffLabel.style.cssText = 'font-size:14px;font-weight:600;color:#1A1F2E;line-height:1.5;cursor:pointer;display:flex;align-items:flex-start;gap:8px;width:100%';
                var desc = document.createElement('span');
                desc.style.cssText = 'display:block;font-size:12px;font-weight:400;color:#565C66;margin-top:4px;line-height:1.45';
                desc.textContent = 'Marca si quieres que el pedido se entregue en una dirección diferente a la de facturación.';
                shipDiffLabel.appendChild(desc);
            }
            var shipDiffCheckbox = shipDiffWrap.querySelector('#ship-to-different-address-checkbox');
            if (shipDiffCheckbox) { shipDiffCheckbox.style.cssText = 'width:20px;height:20px;margin-top:2px;accent-color:#E80001;cursor:pointer;flex-shrink:0'; }
        }

        var billingHeading = scope.querySelector('.woocommerce-billing-fields h3, .woocommerce-billing-fields legend');
        if (billingHeading && !billingHeading.getAttribute('data-ltms-renamed')) {
            billingHeading.setAttribute('data-ltms-renamed', '1');
            billingHeading.textContent = 'Datos de facturación';
        }
        var shippingHeading = scope.querySelector('.woocommerce-shipping-fields h3, .woocommerce-shipping-fields legend');
        if (shippingHeading && !shippingHeading.getAttribute('data-ltms-renamed')) {
            shippingHeading.setAttribute('data-ltms-renamed', '1');
            shippingHeading.textContent = 'Dirección de entrega (envío)';
        }
    }

    function syncShipFieldsVisibility() {
        var shipCheckbox = scope.querySelector('#ship-to-different-address-checkbox');
        var shipFieldsWrap = scope.querySelector('.woocommerce-shipping-fields');
        if (!shipCheckbox || !shipFieldsWrap) return;
        if (shipCheckbox.checked) {
            shipFieldsWrap.style.display = 'block';
            shipFieldsWrap.classList.add('shipping-fields--visible');
        } else {
            shipFieldsWrap.style.display = 'none';
            shipFieldsWrap.classList.remove('shipping-fields--visible');
        }
    }

    function syncStateFromCity() {
        var citySelect = scope.querySelector('#billing_city, #ltms-municipality-select');
        var stateSelect = scope.querySelector('#billing_state');
        if (!citySelect || !stateSelect) return;
        if (!citySelect.value || citySelect.value.length < 2) return;
        var selectedOption = citySelect.options[citySelect.selectedIndex];
        if (!selectedOption) return;
        var optionText = selectedOption.textContent || '';
        var deptName = '';
        var dashIdx = optionText.indexOf('—');
        if (dashIdx !== -1) { deptName = optionText.substring(dashIdx + 1).trim(); }
        else if (optionText.indexOf('-') !== -1) { deptName = optionText.substring(optionText.indexOf('-') + 1).trim(); }
        if (!deptName) return;
        var bestMatch = null, bestScore = 0;
        for (var i = 0; i < stateSelect.options.length; i++) {
            var opt = stateSelect.options[i];
            var optText = (opt.textContent || '').trim();
            var normOpt = optText.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            var normDept = deptName.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            if (normOpt === normDept) { bestMatch = opt; bestScore = 100; break; }
            if (normOpt.indexOf(normDept) !== -1 || normDept.indexOf(normOpt) !== -1) {
                var score = Math.min(normOpt.length, normDept.length);
                if (score > bestScore) { bestScore = score; bestMatch = opt; }
            }
        }
        if (bestMatch && bestScore > 0) {
            stateSelect.value = bestMatch.value;
            stateSelect.dispatchEvent(new Event('change', { bubbles: true }));
            if (typeof jQuery !== 'undefined') { jQuery(document.body).trigger('update_checkout'); }
        }
    }

    fixFieldLabels();
    syncShipFieldsVisibility();
    setTimeout(function() { fixFieldLabels(); syncShipFieldsVisibility(); }, 500);
    setTimeout(function() { fixFieldLabels(); syncShipFieldsVisibility(); }, 1500);
    setTimeout(function() { fixFieldLabels(); syncShipFieldsVisibility(); }, 3000);

    if ('MutationObserver' in window) {
        var observer = new MutationObserver(function() { fixFieldLabels(); syncShipFieldsVisibility(); });
        observer.observe(scope, { childList: true, subtree: true, characterData: true });
        setTimeout(function() { observer.disconnect(); }, 8000);
    }

    var shipCheckboxForToggle = scope.querySelector('#ship-to-different-address-checkbox');
    if (shipCheckboxForToggle) { shipCheckboxForToggle.addEventListener('change', syncShipFieldsVisibility); }

    var citySelectForSync = scope.querySelector('#billing_city, #ltms-municipality-select');
    if (citySelectForSync) {
        citySelectForSync.addEventListener('change', function() { setTimeout(syncStateFromCity, 100); });
        setTimeout(syncStateFromCity, 800);
    }

    // Invoice toggle
    function initInvoiceToggle() {
        var toggle = document.querySelector('#ltms_needs_invoice');
        if (!toggle) return;
        if (toggle.getAttribute('data-ltms-invoice-init') === '1') return;
        toggle.setAttribute('data-ltms-invoice-init', '1');
        var invoiceFields = document.querySelectorAll('.ltms-invoice-field');
        var toggleLabel = document.querySelector('label[for="ltms_needs_invoice"]');
        if (toggleLabel && !toggleLabel.getAttribute('data-ltms-desc-added')) {
            toggleLabel.setAttribute('data-ltms-desc-added', '1');
            var desc = document.createElement('span');
            desc.style.cssText = 'display:block;font-size:12px;font-weight:400;color:#565C66;margin-top:4px;line-height:1.45';
            desc.textContent = 'La factura electrónica la emite cada vendedor. Marca si compras para empresa o necesitas soporte contable.';
            toggleLabel.appendChild(desc);
        }
        function updateVisibility() {
            var show = toggle.checked;
            invoiceFields.forEach(function(field) { field.style.display = show ? '' : 'none'; });
        }
        toggle.addEventListener('change', updateVisibility);
        updateVisibility();
    }
    initInvoiceToggle();
    setTimeout(initInvoiceToggle, 500);
    setTimeout(initInvoiceToggle, 1500);
})();
