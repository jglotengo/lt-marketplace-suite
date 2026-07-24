/**
 * LTMS Vendor Registration Form — Wizard navigation + validation + submit + Turnstile + country/document.
 *
 * UX-REG-01 FIX: The wizard navigation handler (data-next, data-back buttons) was
 * missing — it was in an inline <script> removed during FASE2B P0 FIX (CSP) but
 * never migrated to this external file. The 3-step wizard was completely broken:
 * clicking "Siguiente" did nothing. This file now recreates the full handler:
 *   - Step navigation (next/back) with progress indicator update
 *   - Per-step validation before advancing
 *   - AJAX submit via ltms_register_vendor action
 *   - Error display with aria-live announcements
 *   - Turnstile + country/document dynamic fields (preserved from original)
 */
(function () {
    'use strict';

    // ════════════════════════════════════════════════════════════════
    // 1. Cloudflare Turnstile callback (global, required by Turnstile API).
    // ════════════════════════════════════════════════════════════════
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

    // ════════════════════════════════════════════════════════════════
    // 2. Country → document type + phone placeholder + municipality toggle.
    // ════════════════════════════════════════════════════════════════
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

    // ════════════════════════════════════════════════════════════════
    // 3. UX-REG-01 FIX: Wizard navigation handler (was missing entirely).
    // ════════════════════════════════════════════════════════════════
    var form = document.getElementById('ltms-register-form');
    if (!form) return;

    var pages = form.querySelectorAll('.ltms-wizard-page');
    var steps = document.querySelectorAll('.ltms-wizard-steps .ltms-step');
    var notice = document.getElementById('ltms-register-notice');
    var currentPage = 1;
    var totalPages = pages.length;

    // Show a notice message (error or info) with aria-live.
    function showNotice(message, type) {
        if (!notice) return;
        notice.className = 'ltms-notice ltms-notice-' + (type || 'info');
        notice.innerHTML = '<p>' + message + '</p>';
        notice.style.display = 'block';
        // A11Y-REG-03: aria-live announces to screen readers.
        notice.setAttribute('aria-live', 'polite');
    }

    function clearNotice() {
        if (!notice) return;
        notice.style.display = 'none';
        notice.innerHTML = '';
    }

    // Validate all required fields in a given page. Returns array of error messages.
    function validatePage(pageNum) {
        var page = form.querySelector('.ltms-wizard-page[data-page="' + pageNum + '"]');
        if (!page) return [];
        var errors = [];
        var fields = page.querySelectorAll('input[required], select[required], textarea[required]');

        fields.forEach(function (field) {
            // Skip radio groups (validate by name)
            if (field.type === 'radio') return;
            // Skip hidden municipality when country is MX
            if (field.id === 'ltms-reg-municipality' && field.closest('#ltms-municipality-wrap') &&
                field.closest('#ltms-municipality-wrap').style.display === 'none') return;

            var label = document.querySelector('label[for="' + field.id + '"]');
            var labelText = label ? label.textContent.replace(/\s*\*?\s*$/, '').trim() : field.name;

            // A11Y-REG-02: set aria-invalid on the field
            field.setAttribute('aria-invalid', 'false');

            if (!field.value || !field.value.trim()) {
                errors.push(labelText + ' es obligatorio');
                field.setAttribute('aria-invalid', 'true');
                field.setAttribute('aria-describedby', 'ltms-field-error-' + field.id);
            } else if (field.type === 'email') {
                var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRe.test(field.value)) {
                    errors.push('Email inválido');
                    field.setAttribute('aria-invalid', 'true');
                }
            } else if (field.type === 'tel') {
                var cleanPhone = field.value.replace(/[\s\-\(\)]/g, '');
                var phoneRe = /^\+[1-9][0-9]{6,19}$/;
                if (!phoneRe.test(cleanPhone)) {
                    errors.push('Teléfono inválido. Usa formato E.164 (ej: +573001112233)');
                    field.setAttribute('aria-invalid', 'true');
                }
            } else if (field.type === 'password' && field.name === 'password') {
                if (field.value.length < 8) {
                    errors.push('La contraseña debe tener al menos 8 caracteres');
                    field.setAttribute('aria-invalid', 'true');
                }
            } else if (field.type === 'password' && field.name === 'password_confirm') {
                var pwd = document.getElementById('ltms-reg-password');
                if (pwd && field.value !== pwd.value) {
                    errors.push('Las contraseñas no coinciden');
                    field.setAttribute('aria-invalid', 'true');
                }
            }
        });

        // Validate radio group (business_type)
        var radioGroup = page.querySelector('input[name="business_type"]');
        if (radioGroup && radioGroup.hasAttribute('required')) {
            var checked = page.querySelector('input[name="business_type"]:checked');
            if (!checked) {
                errors.push('Selecciona un tipo de negocio');
            }
        }

        // Validate checkboxes (accept_terms, accept_sagrilaft)
        var checkboxes = page.querySelectorAll('input[type="checkbox"][required]');
        checkboxes.forEach(function (cb) {
            if (!cb.checked) {
                var cbLabel = document.querySelector('label[for="' + cb.id + '"]');
                var cbText = cbLabel ? cbLabel.textContent.replace(/\s*\*?\s*$/, '').trim().substring(0, 40) : cb.name;
                errors.push('Debes aceptar: ' + cbText + '…');
            }
        });

        return errors;
    }

    // Navigate to a specific page.
    function goToPage(pageNum) {
        if (pageNum < 1 || pageNum > totalPages) return;
        currentPage = pageNum;
        pages.forEach(function (p) {
            p.style.display = (parseInt(p.dataset.page, 10) === pageNum) ? '' : 'none';
        });
        // Update step indicators
        steps.forEach(function (s, i) {
            s.classList.toggle('active', (i + 1) === pageNum);
        });
        clearNotice();
        // Focus first field of the new page for accessibility
        var newPage = form.querySelector('.ltms-wizard-page[data-page="' + pageNum + '"]');
        if (newPage) {
            var firstInput = newPage.querySelector('input, select, textarea');
            if (firstInput) firstInput.focus();
        }
        // Scroll to top of form
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    // Next button handler
    form.addEventListener('click', function (e) {
        var nextBtn = e.target.closest('.ltms-wizard-next');
        if (nextBtn) {
            e.preventDefault();
            var nextNum = parseInt(nextBtn.dataset.next, 10);
            var errors = validatePage(currentPage);
            if (errors.length > 0) {
                showNotice('<strong>Por favor corrige:</strong><br>• ' + errors.join('<br>• '), 'error');
                return;
            }
            goToPage(nextNum);
            return;
        }

        var backBtn = e.target.closest('.ltms-wizard-back');
        if (backBtn) {
            e.preventDefault();
            var backNum = parseInt(backBtn.dataset.back, 10);
            goToPage(backNum);
            return;
        }
    });

    // ════════════════════════════════════════════════════════════════
    // 4. Form submit via AJAX (ltms_register_vendor action).
    // ════════════════════════════════════════════════════════════════
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        clearNotice();

        // Validate all pages before submit
        var allErrors = [];
        for (var i = 1; i <= totalPages; i++) {
            allErrors = allErrors.concat(validatePage(i));
        }
        if (allErrors.length > 0) {
            showNotice('<strong>Por favor corrige:</strong><br>• ' + allErrors.join('<br>• '), 'error');
            goToPage(1);
            return;
        }

        // Honeypot check
        var hp = document.getElementById('ltms-hp-website');
        if (hp && hp.value) {
            // Bot filled the hidden field — silently fail
            return;
        }

        var submitBtn = document.getElementById('ltms-register-btn');
        var btnText = submitBtn ? submitBtn.querySelector('.ltms-btn-text') : null;
        var btnSpinner = submitBtn ? submitBtn.querySelector('.ltms-btn-spinner') : null;

        if (submitBtn) submitBtn.disabled = true;
        if (btnText) btnText.style.display = 'none';
        if (btnSpinner) btnSpinner.style.display = 'inline-block';

        // Collect form data
        var formData = new FormData(form);
        formData.append('action', 'ltms_register_vendor');
        formData.append('nonce', (typeof ltmsAuth !== 'undefined' && ltmsAuth.nonce) ? ltmsAuth.nonce : '');

        fetch((typeof ltmsAuth !== 'undefined' && ltmsAuth.ajax_url) ? ltmsAuth.ajax_url : '/wp-admin/admin-ajax.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (submitBtn) submitBtn.disabled = false;
            if (btnText) btnText.style.display = '';
            if (btnSpinner) btnSpinner.style.display = 'none';

            if (data.success) {
                // Registration successful — redirect or show success
                if (data.data && data.data.redirect) {
                    showNotice('<strong>¡Cuenta creada!</strong> Redirigiendo…', 'success');
                    setTimeout(function () { window.location.href = data.data.redirect; }, 1500);
                } else {
                    showNotice('<strong>¡Cuenta creada!</strong> Revisa tu email para verificar tu cuenta.', 'success');
                    form.reset();
                    goToPage(1);
                }
            } else {
                // Server returned errors
                var errorMsg = data.data && data.data.message ? data.data.message : 'Error al registrar. Intenta de nuevo.';
                if (data.data && data.data.errors && data.data.errors.length) {
                    errorMsg += '<br>• ' + data.data.errors.map(function (e) { return e.message; }).join('<br>• ');
                }
                showNotice(errorMsg, 'error');
            }
        })
        .catch(function (err) {
            if (submitBtn) submitBtn.disabled = false;
            if (btnText) btnText.style.display = '';
            if (btnSpinner) btnSpinner.style.display = 'none';
            showNotice('Error de conexión. Verifica tu internet e intenta de nuevo.', 'error');
        });
    });

    // ════════════════════════════════════════════════════════════════
    // 5. A11Y-REG-01: Add aria-required to all required fields dynamically.
    // ════════════════════════════════════════════════════════════════
    form.querySelectorAll('input[required], select[required], textarea[required]').forEach(function (field) {
        field.setAttribute('aria-required', 'true');
    });

    // ════════════════════════════════════════════════════════════════
    // 6. REG-03: Show/hide compliance notices based on business_type selection.
    // ════════════════════════════════════════════════════════════════
    var btypeRadios = form.querySelectorAll('input[name="business_type"]');
    var noticeRestaurant = document.getElementById('ltms-btype-notice-restaurant');
    var noticeTourism = document.getElementById('ltms-btype-notice-tourism');

    function updateBtypeNotices() {
        var checked = form.querySelector('input[name="business_type"]:checked');
        var val = checked ? checked.value : '';
        if (noticeRestaurant) noticeRestaurant.style.display = (val === 'restaurant') ? 'block' : 'none';
        if (noticeTourism) noticeTourism.style.display = (val === 'tourism') ? 'block' : 'none';
    }

    btypeRadios.forEach(function (radio) {
        radio.addEventListener('change', updateBtypeNotices);
    });
    updateBtypeNotices();

})();
