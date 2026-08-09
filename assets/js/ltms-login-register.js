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
    // 3. UX-REG-08 FIX: Login form handler — must be BEFORE the registration
    //    form check (if (!form) return) because on the login page there is
    //    no registration form, and the early return would skip this handler.
    // ════════════════════════════════════════════════════════════════
    var loginForm = document.getElementById('ltms-login-form');
    if (loginForm) {
        loginForm.addEventListener('submit', function (e) {
            e.preventDefault();

            var loginNotice = document.getElementById('ltms-login-notice');
            function showLoginNotice(message, type) {
                if (!loginNotice) return;
                loginNotice.className = 'ltms-notice ltms-notice-' + (type || 'info');
                loginNotice.innerHTML = '<p>' + message + '</p>';
                loginNotice.style.display = 'block';
            }
            function clearLoginNotice() {
                if (!loginNotice) return;
                loginNotice.style.display = 'none';
                loginNotice.innerHTML = '';
            }

            clearLoginNotice();

            var username = loginForm.querySelector('#ltms-login-username');
            var password = loginForm.querySelector('#ltms-login-password');
            var remember = loginForm.querySelector('input[name="rememberme"]');
            var submitBtn = document.getElementById('ltms-login-btn');
            var btnText = submitBtn ? submitBtn.querySelector('.ltms-btn-text') : null;
            var btnSpinner = submitBtn ? submitBtn.querySelector('.ltms-btn-spinner') : null;

            if (!username.value || !password.value) {
                showLoginNotice('Usuario y contraseña son requeridos.', 'error');
                return;
            }

            if (submitBtn) submitBtn.disabled = true;
            if (btnText) btnText.style.display = 'none';
            if (btnSpinner) btnSpinner.style.display = 'inline-block';

            var loginData = new FormData();
            loginData.append('action', 'ltms_vendor_login');
            loginData.append('username', username.value);
            loginData.append('password', password.value);
            loginData.append('remember', remember && remember.checked ? '1' : '0');
            loginData.append('nonce', (typeof ltmsAuth !== 'undefined' && ltmsAuth.nonce) ? ltmsAuth.nonce : '');

            fetch((typeof ltmsAuth !== 'undefined' && ltmsAuth.ajax_url) ? ltmsAuth.ajax_url : '/wp-admin/admin-ajax.php', {
                method: 'POST',
                body: loginData,
                credentials: 'same-origin'
            })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (submitBtn) submitBtn.disabled = false;
                if (btnText) btnText.style.display = '';
                if (btnSpinner) btnSpinner.style.display = 'none';

                if (data.success) {
                    showLoginNotice('<strong>¡Bienvenido!</strong> Redirigiendo…', 'success');
                    if (data.data && data.data.redirect) {
                        setTimeout(function () { window.location.href = data.data.redirect; }, 1000);
                    }
                } else {
                    var msg = data.data && data.data.message ? data.data.message : 'Usuario o contraseña incorrectos.';
                    showLoginNotice(msg, 'error');
                    // AUTH-RA4 (P1) RE-AUDIT-AUTH FIX: seguir data.data.redirect en
                    // branch error. El backend (ajax_vendor_login, AUTH-01) retorna
                    // HTTP 403 con message + redirect cuando el vendor tiene email
                    // no verificado — el redirect apunta a la página de login con
                    // ?resend_verification=1 que muestra el mini-form de reenvío.
                    // Antes el JS solo mostraba el message e ignoraba el redirect,
                    // rompiendo la UX del fix AUTH-01: el vendor veia el mensaje
                    // "verifica tu email" pero NO era llevado al form de reenvío.
                    // Pequeño delay (1.2s) para que el usuario lea el message antes
                    // del redirect automatico (mismo patron que el branch success).
                    if (data.data && data.data.redirect) {
                        var redirectUrl = data.data.redirect;
                        if (submitBtn) submitBtn.disabled = true;
                        setTimeout(function () { window.location.href = redirectUrl; }, 1200);
                    }
                }
            })
            .catch(function (err) {
                if (submitBtn) submitBtn.disabled = false;
                if (btnText) btnText.style.display = '';
                if (btnSpinner) btnSpinner.style.display = 'none';
                showLoginNotice('Error de conexión. Intenta de nuevo.', 'error');
            });
        });
    }

    // ════════════════════════════════════════════════════════════════
    // 4. UX-REG-01 FIX: Wizard navigation handler (registration page only).
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
                } else if (!/[A-Z]/.test(field.value) || !/[0-9]/.test(field.value)) {
                    errors.push('La contraseña debe incluir al menos una mayúscula y un número');
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
        // UX-REG-02 FIX: antes usabamos '' (string vacio) para mostrar la pagina
        // activa, lo que removia el inline style y dejaba que el CSS externo
        // (.ltms-wizard-page { display: none; }) la ocultara de nuevo. Ahora
        // usamos 'block' explicito para garantizar visibilidad.
        pages.forEach(function (p) {
            p.style.display = (parseInt(p.dataset.page, 10) === pageNum) ? 'block' : 'none';
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
        var isCompleteProfile = window.location.search.indexOf('complete_profile=1') > -1;
        formData.append('action', isCompleteProfile ? 'ltms_complete_profile' : 'ltms_vendor_register');
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
                if (isCompleteProfile) {
                    showNotice('<strong>¡Perfil completado!</strong> Redirigiendo a tu panel…', 'success');
                    setTimeout(function () {
                        if (data.data && data.data.redirect) {
                            window.location.href = data.data.redirect;
                        }
                    }, 1500);
                } else if (data.data && data.data.email_verification_required) {
                    // Email verification required — NO auto-login. Show clear message
                    // and redirect to login page so user knows to check inbox.
                    // REG-AUDIT-002 F6: enumerar los 3 pasos siguientes para que el
                    // vendedor sepa EXACTAMENTE qué hacer. Antes el mensaje era solo
                    // "Revisa tu email" sin pasos, lo que generaba el reporte "me
                    // registré pero no sé qué hacer".
                    showNotice(
                        '<strong>¡Cuenta creada!</strong> Para acceder a tu panel de vendedor, sigue estos 3 pasos:' +
                        '<div style="text-align:left;margin-top:10px;padding:10px 14px;background:#f9fafb;border-radius:8px;font-size:0.88rem;line-height:1.7;">' +
                        '<div><strong>1.</strong> Revisa tu email <strong>(y carpeta de spam)</strong> — te enviamos un correo de verificación.</div>' +
                        '<div><strong>2.</strong> Haz clic en el enlace del email para verificar tu cuenta.</div>' +
                        '<div><strong>3.</strong> Serás llevado automáticamente a tu panel de vendedor, donde verás los pasos siguientes (KYC, configurar tienda, subir producto).</div>' +
                        '</div>' +
                        '<div style="margin-top:10px;font-size:0.82rem;color:#6b7280;">¿No recibiste el correo en 5 minutos? Revisa spam. Si no llega, en el formulario de login abajo verás la opción <em>Reenviar email</em>.</div>',
                        'success'
                    );
                    // Redirect to login after 6s (give user time to read the 3 steps).
                    setTimeout(function () {
                        if (data.data.redirect) {
                            window.location.href = data.data.redirect;
                        }
                    }, 6000);
                } else if (data.data && data.data.redirect) {
                    // Auto-login — redirect to dashboard
                    showNotice('<strong>¡Cuenta creada!</strong> Redirigiendo a tu panel…', 'success');
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

    // UX-REG-04 FIX: Feedback visual al seleccionar business_type.
    btypeRadios.forEach(function (radio) {
        radio.addEventListener('change', function () {
            form.querySelectorAll('.ltms-btype-lbl').forEach(function (lbl) {
                lbl.classList.remove('ltms-btype-selected');
            });
            var checked = form.querySelector('input[name="business_type"]:checked');
            if (checked) {
                var lbl = checked.closest('.ltms-btype-lbl');
                if (lbl) lbl.classList.add('ltms-btype-selected');
            }
        });
    });
    updateBtypeNotices();

})();
