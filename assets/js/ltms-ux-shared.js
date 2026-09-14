/**
 * LT Marketplace Suite - UX Enhancements JS
 * ─────────────────────────────────────────────────────────────
 * Micro-interacciones, toasts, scroll animations, keyboard
 * shortcuts y mejoras de accesibilidad para TODAS las
 * interfaces del marketplace.
 *
 * Diseñado como capa aditiva — no rompe la funcionalidad
 * existente de ltms-dashboard.js, ltms-login-register.js, etc.
 *
 * Versión: 2.0.0
 * ─────────────────────────────────────────────────────────────
 */

(function () {
    'use strict';

    // ── Namespace LTMS.UX ──────────────────────────────────────
    window.LTMS = window.LTMS || {};
    LTMS.UX = LTMS.UX || {};

    // ── Configuración ──────────────────────────────────────────
    const CONFIG = {
        toastDuration: 5000,
        toastAnimationDuration: 350,
        scrollOffset: 80,
        debounceMs: 150,
        pollNotifications: true,
    };


    // ═══════════════════════════════════════════════════════════
    // SECCIONES SHARED (toasts, utilidades, animaciones, etc.)
    // Generado por bin/build-ux-bundles.js desde ltms-ux-enhancements.js
    // ═══════════════════════════════════════════════════════════

    // ═══════════════════════════════════════════════════════════
    // 1. TOAST SYSTEM — Notificaciones modernas no bloqueantes
    // ═══════════════════════════════════════════════════════════

    /**
     * Crea el container de toasts si no existe.
     */
    function ensureToastContainer() {
        let container = document.querySelector('.ltms-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'ltms-toast-container';
            container.setAttribute('role', 'region');
            container.setAttribute('aria-live', 'polite');
            container.setAttribute('aria-label', 'Notificaciones');
            document.body.appendChild(container);
        }
        return container;
    }

    /**
     * Iconos SVG inline para cada tipo de toast.
     */
    const TOAST_ICONS = {
        success: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
        error:   '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        warning: '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        info:    '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
    };

    /**
     * Muestra un toast moderno.
     *
     * @param {string} type     success | error | warning | info
     * @param {string} title    Título breve
     * @param {string} message  Mensaje descriptivo (opcional)
     * @param {Object} opts     { duration, action, actionLabel }
     */
    function toast(type, title, message, opts) {
        opts = opts || {};
        const duration = opts.duration || CONFIG.toastDuration;
        const container = ensureToastContainer();

        const el = document.createElement('div');
        el.className = 'ltms-toast ltms-toast-' + type;
        el.setAttribute('role', 'alert');
        el.innerHTML = `
            <div class="ltms-toast-icon">${TOAST_ICONS[type] || TOAST_ICONS.info}</div>
            <div class="ltms-toast-body">
                <div class="ltms-toast-title">${escapeHtml(title)}</div>
                ${message ? `<p class="ltms-toast-message">${escapeHtml(message)}</p>` : ''}
                ${opts.action ? `<button type="button" class="ltms-toast-action ltms-btn ltms-btn-sm ltms-btn-outline" style="margin-top:8px;">${escapeHtml(opts.actionLabel || 'Acción')}</button>` : ''}
            </div>
            <button type="button" class="ltms-toast-close" aria-label="Cerrar">&times;</button>
        `;

        container.appendChild(el);

        // Auto-dismiss
        let timeout = setTimeout(() => dismissToast(el), duration);

        // Cerrar manualmente
        el.querySelector('.ltms-toast-close').addEventListener('click', () => {
            clearTimeout(timeout);
            dismissToast(el);
        });

        // Click en toast → dismiss (a menos que haya acción)
        if (!opts.action) {
            el.addEventListener('click', () => {
                clearTimeout(timeout);
                dismissToast(el);
            });
        }

        // Action callback
        if (opts.action) {
            el.querySelector('.ltms-toast-action').addEventListener('click', (e) => {
                e.stopPropagation();
                try { opts.action(); } catch (err) { console.error('Toast action error:', err); }
                clearTimeout(timeout);
                dismissToast(el);
            });
        }

        // Pausar auto-dismiss en hover
        el.addEventListener('mouseenter', () => clearTimeout(timeout));
        el.addEventListener('mouseleave', () => {
            timeout = setTimeout(() => dismissToast(el), duration);
        });

        return el;
    }

    function dismissToast(el) {
        if (!el || el.classList.contains('ltms-toast-out')) return;
        el.classList.add('ltms-toast-out');
        setTimeout(() => {
            if (el.parentNode) el.parentNode.removeChild(el);
        }, CONFIG.toastAnimationDuration);
    }

    // API pública de toasts
    LTMS.UX.toast = toast;
    LTMS.UX.toastSuccess = (t, m, o) => toast('success', t, m, o);
    LTMS.UX.toastError   = (t, m, o) => toast('error', t, m, o);
    LTMS.UX.toastWarning = (t, m, o) => toast('warning', t, m, o);
    LTMS.UX.toastInfo    = (t, m, o) => toast('info', t, m, o);

    // ═══════════════════════════════════════════════════════════
    // 2. SCROLL ANIMATIONS — Reveal on scroll
    // ═══════════════════════════════════════════════════════════

    /**
     * Inicializa IntersectionObserver para elementos con
     * clase .ltms-reveal. Respeta prefers-reduced-motion.
     */
    function initScrollReveal() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        const revealEls = document.querySelectorAll('.ltms-reveal, .ltms-sl-card, .ltms-sl-step');
        if (!revealEls.length || !('IntersectionObserver' in window)) return;

        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry, i) => {
                if (entry.isIntersecting) {
                    const delay = parseInt(entry.target.dataset.revealDelay || i * 80, 10);
                    setTimeout(() => {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }, delay);
                    observer.unobserve(entry.target);
                }
            });
        }, {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px',
        });

        revealEls.forEach((el) => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(20px)';
            el.style.transition = 'opacity 0.5s cubic-bezier(0.16, 1, 0.3, 1), transform 0.5s cubic-bezier(0.16, 1, 0.3, 1)';
            observer.observe(el);
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 4. PASSWORD STRENGTH METER — Medidor visual mejorado
    // ═══════════════════════════════════════════════════════════

    /**
     * Calcula la fuerza de una contraseña.
     * @returns {Object} { score 0-4, label, percent, class }
     */
    function calcPasswordStrength(pwd) {
        if (!pwd) return { score: 0, label: '', percent: 0, class: '' };

        let score = 0;
        const length = pwd.length;

        // Longitud
        if (length >= 8)  score++;
        if (length >= 12) score++;
        if (length >= 16) score++;

        // Complejidad
        if (/[a-z]/.test(pwd)) score++;
        if (/[A-Z]/.test(pwd)) score++;
        if (/[0-9]/.test(pwd)) score++;
        if (/[^a-zA-Z0-9]/.test(pwd)) score++;

        // Penalizar patrones comunes
        if (/(.)\1{2,}/.test(pwd)) score--;        // repeticiones
        if (/^(123|abc|qwe|password|contraseña)/i.test(pwd)) score -= 2;

        score = Math.max(0, Math.min(4, Math.floor(score / 2)));

        const map = [
            { label: 'Muy débil', percent: 20,  class: 'weak' },
            { label: 'Débil',     percent: 40,  class: 'weak' },
            { label: 'Aceptable', percent: 60,  class: 'fair' },
            { label: 'Buena',     percent: 80,  class: 'good' },
            { label: 'Excelente', percent: 100, class: 'strong' },
        ];

        return { score, ...map[score] };
    }

    /**
     * Inicializa el medidor de fuerza para inputs de contraseña
     * con clase .ltms-password-input o data-strength.
     */
    function initPasswordStrength() {
        const pwdInputs = document.querySelectorAll('input[type="password"][data-strength], .ltms-password-input');

        pwdInputs.forEach((input) => {
            // Crear el medidor si no existe
            let meter = input.parentElement.querySelector('.ltms-strength-segments');
            if (!meter) {
                const wrap = input.closest('.ltms-form-group, .ltms-auth-field') || input.parentElement;
                meter = document.createElement('div');
                meter.className = 'ltms-strength-segments';
                meter.innerHTML = '<div class="ltms-segment"></div><div class="ltms-segment"></div><div class="ltms-segment"></div><div class="ltms-segment"></div>';
                const label = document.createElement('span');
                label.className = 'ltms-strength-label';
                label.style.fontSize = '0.75rem';
                label.style.fontWeight = '600';
                label.style.display = 'block';
                label.style.marginTop = '4px';

                const existing = wrap.querySelector('.ltms-password-strength');
                if (existing) {
                    existing.innerHTML = '';
                    existing.appendChild(meter);
                    existing.appendChild(label);
                } else {
                    wrap.appendChild(meter);
                    wrap.appendChild(label);
                }
                meter = wrap.querySelector('.ltms-strength-segments');
                label = wrap.querySelector('.ltms-strength-label');
            }

            const label = meter.parentElement.querySelector('.ltms-strength-label');
            const segments = meter.querySelectorAll('.ltms-segment');

            input.addEventListener('input', () => {
                const strength = calcPasswordStrength(input.value);
                segments.forEach((seg, i) => {
                    seg.className = 'ltms-segment';
                    if (i < strength.score) {
                        seg.classList.add('active', strength.class);
                    }
                });
                if (label) {
                    label.textContent = input.value ? strength.label : '';
                    label.style.color = {
                        weak: 'var(--ltms-danger, #e74c3c)',
                        fair: 'var(--ltms-warning, #e67e22)',
                        good: '#facc15',
                        strong: 'var(--ltms-success, #27ae60)',
                    }[strength.class] || '#6b7280';
                }
            });
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 5. THEME TOGGLE — Dark / Light mode toggle
    // ═══════════════════════════════════════════════════════════

    function getStoredTheme() {
        try { return localStorage.getItem('ltms-theme') || 'auto'; }
        catch (e) { return 'auto'; }
    }

    function setStoredTheme(theme) {
        try { localStorage.setItem('ltms-theme', theme); }
        catch (e) { /* noop */ }
    }

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-ltms-theme', theme);
    }

    function initThemeToggle() {
        applyTheme(getStoredTheme());

        document.addEventListener('click', (e) => {
            const toggle = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('.ltms-theme-toggle');
            if (!toggle) return;

            const current = getStoredTheme();
            const next = current === 'dark' ? 'light' : 'dark';
            setStoredTheme(next);
            applyTheme(next);
            toast('info', 'Tema cambiado', `Modo ${next === 'dark' ? 'oscuro' : 'claro'} activado`, { duration: 2000 });
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 6. COPY TO CLIPBOARD — Helper para botones de copiar
    // ═══════════════════════════════════════════════════════════

    function initCopyButtons() {
        document.addEventListener('click', async (e) => {
            const btn = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('[data-copy]');
            if (!btn) return;
            e.preventDefault();

            const text = btn.dataset.copy;
            try {
                await navigator.clipboard.writeText(text);
                toast('success', 'Copiado', 'Texto copiado al portapapeles', { duration: 2000 });

                // Feedback visual temporal
                const original = btn.textContent;
                btn.textContent = '✓ Copiado';
                btn.style.pointerEvents = 'none';
                setTimeout(() => {
                    btn.textContent = original;
                    btn.style.pointerEvents = '';
                }, 1500);
            } catch (err) {
                toast('error', 'Error', 'No se pudo copiar al portapapeles');
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 7. CONFIRM DIALOG — Reemplaza confirm() nativo
    // ═══════════════════════════════════════════════════════════

    /**
     * Muestra un modal de confirmación moderno.
     * @returns {Promise<boolean>}
     */
    function confirmDialog(opts) {
        opts = opts || {};
        return new Promise((resolve) => {
            const overlay = document.createElement('div');
            overlay.className = 'ltms-modal-overlay';
            overlay.innerHTML = `
                <div class="ltms-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-confirm-title">
                    <div class="ltms-modal-header">
                        <h3 class="ltms-modal-title" id="ltms-confirm-title">${escapeHtml(opts.title || 'Confirmar')}</h3>
                        <button type="button" class="ltms-modal-close" aria-label="Cerrar">&times;</button>
                    </div>
                    <div class="ltms-modal-body">
                        <p style="margin:0;color:var(--ltms-gray-700);line-height:1.5;">${escapeHtml(opts.message || '¿Estás seguro?')}</p>
                    </div>
                    <div class="ltms-modal-footer">
                        <button type="button" class="ltms-btn ltms-btn-outline ltms-confirm-cancel">${escapeHtml(opts.cancelLabel || 'Cancelar')}</button>
                        <button type="button" class="ltms-btn ${opts.danger ? 'ltms-btn-danger' : 'ltms-btn-primary'} ltms-confirm-ok">${escapeHtml(opts.okLabel || 'Confirmar')}</button>
                    </div>
                </div>
            `;
            document.body.appendChild(overlay);

            // Animación de entrada
            requestAnimationFrame(() => overlay.classList.add('ltms-modal-open'));

            function close(result) {
                overlay.classList.remove('ltms-modal-open');
                setTimeout(() => {
                    if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
                    resolve(result);
                }, 250);
            }

            overlay.querySelector('.ltms-confirm-ok').addEventListener('click', () => close(true));
            overlay.querySelector('.ltms-confirm-cancel').addEventListener('click', () => close(false));
            overlay.querySelector('.ltms-modal-close').addEventListener('click', () => close(false));
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) close(false);
            });

            // Focus inicial
            const okBtn = overlay.querySelector('.ltms-confirm-ok');
            setTimeout(() => okBtn.focus(), 100);
        });
    }

    LTMS.UX.confirm = confirmDialog;

    // Interceptar data-confirm="true" en clicks
    document.addEventListener('click', async (e) => {
        const el = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('[data-confirm-message]');
        if (!el) return;
        e.preventDefault();

        const confirmed = await confirmDialog({
            title: el.dataset.confirmTitle || 'Confirmar acción',
            message: el.dataset.confirmMessage,
            okLabel: el.dataset.confirmOk || 'Confirmar',
            cancelLabel: el.dataset.confirmCancel || 'Cancelar',
            danger: el.dataset.confirmDanger === 'true',
        });

        if (confirmed) {
            // Re-ejecutar la acción original
            el.removeAttribute('data-confirm-message');
            el.click();
            // Restaurar el atributo después
            setTimeout(() => {
                el.setAttribute('data-confirm-message', el.dataset.confirmMessage || '¿Estás seguro?');
            }, 100);
        }
    });

    // ═══════════════════════════════════════════════════════════
    // 8. LAZY LOADING — Imágenes con fade-in
    // ═══════════════════════════════════════════════════════════

    function initLazyImages() {
        if (!('IntersectionObserver' in window)) return;

        const images = document.querySelectorAll('img[data-src]:not([src])');
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    img.src = img.dataset.src;
                    img.removeAttribute('data-src');
                    img.style.opacity = '0';
                    img.style.transition = 'opacity 0.4s ease';
                    img.addEventListener('load', () => {
                        img.style.opacity = '1';
                    });
                    observer.unobserve(img);
                }
            });
        });

        images.forEach((img) => observer.observe(img));
    }

    // ═══════════════════════════════════════════════════════════
    // 9. SKIP LINK — Accesibilidad keyboard
    // ═══════════════════════════════════════════════════════════

    function initSkipLink() {
        if (document.querySelector('.ltms-skip-link')) return;

        const skip = document.createElement('a');
        skip.href = '#ltms-main-content';
        skip.className = 'ltms-skip-link';
        skip.textContent = 'Saltar al contenido principal';
        document.body.insertBefore(skip, document.body.firstChild);

        skip.addEventListener('click', (e) => {
            e.preventDefault();
            const main = document.querySelector('#ltms-main-content, .ltms-main-content, main');
            if (main) {
                main.setAttribute('tabindex', '-1');
                main.focus();
                main.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 10. AJAX ERROR INTERCEPTOR — Captura errores globales
    // ═══════════════════════════════════════════════════════════

    function initAjaxErrorInterceptor() {
        // v2.9.56: Completamente desactivado. El interceptor generaba spam
        // en la consola con errores AJAX de terceros y del JS viejo cacheado.
        // Los errores AJAX ya se manejan en cada función individual.
        return;

        if (typeof jQuery === 'undefined') return;

        // v2.9.31: DISABLED — el interceptor mostraba toast por cada AJAX
        // que fallaba (incluso third-party plugins), saturando al usuario
        // con popups "Error". Solo loguear a consola.
        const SHOW_AJAX_ERROR_TOASTS = false;

        jQuery(document).ajaxError((event, jqXHR, settings, error) => {
            // Siempre loguear a consola para debug
            console.error('[LTMS.UX] AJAX error:', settings.url, jqXHR.status, jqXHR.statusText);

            if (!SHOW_AJAX_ERROR_TOASTS) return;

            // Ignorar peticiones abortadas
            if (jqXHR.statusText === 'abort') return;

            // Intentar parsear respuesta
            let msg = 'Error de conexión. Intenta nuevamente.';
            try {
                const resp = JSON.parse(jqXHR.responseText);
                if (resp.data && typeof resp.data === 'string') msg = resp.data;
            } catch (e) { /* noop */ }

            // Solo mostrar toast si la petición no es silenciosa
            if (settings.data && settings.data.indexOf('silent=true') === -1) {
                toast('error', 'Error', msg);
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 11. NETWORK STATUS — Online/offline indicator
    // ═══════════════════════════════════════════════════════════

    function initNetworkStatus() {
        if (!('onLine' in navigator)) return;

        let offlineToast = null;

        window.addEventListener('offline', () => {
            offlineToast = toast('warning', 'Sin conexión', 'Estás offline. Algunas funciones no estarán disponibles.', {
                duration: 0, // persistente
            });
        });

        window.addEventListener('online', () => {
            if (offlineToast) {
                dismissToast(offlineToast);
                offlineToast = null;
            }
            toast('success', 'Conexión restablecida', 'Ya puedes continuar trabajando.', { duration: 3000 });
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 12. FORM ENHANCEMENTS — Validación, autoguardado
    // ═══════════════════════════════════════════════════════════

    function initFormEnhancements() {
        // Auto-trim inputs de texto/email al blur
        document.addEventListener('blur', (e) => {
            const input = e.target;
            if (input.tagName === 'INPUT' && ['text', 'email', 'tel'].includes(input.type)) {
                input.value = input.value.trim();
            }
        }, true);

        // Validación visual en tiempo real
        document.addEventListener('input', (e) => {
            const input = e.target;
            if (input.tagName !== 'INPUT' && input.tagName !== 'TEXTAREA') return;

            const group = input.closest('.ltms-form-group, .ltms-auth-field');
            if (!group) return;

            const errorEl = group.querySelector('.ltms-field-error');
            if (!errorEl) return;

            // Si el input era inválido y ahora es válido, limpiar error
            if (input.classList.contains('ltms-input-error') && input.checkValidity()) {
                input.classList.remove('ltms-input-error');
                errorEl.style.display = 'none';
            }
        });

        // Prevenir doble submit en forms con data-prevent-double
        document.addEventListener('submit', (e) => {
            const form = e.target;
            if (!form.dataset.preventDouble || form.dataset.submitted === 'true') {
                if (form.dataset.submitted === 'true') {
                    e.preventDefault();
                    return;
                }
                return;
            }
            form.dataset.submitted = 'true';
            const btn = form.querySelector('button[type="submit"], input[type="submit"]');
            if (btn) {
                btn.dataset.originalHtml = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span class="ltms-spinner"></span> Procesando...';
                // Re-habilitar después de 10s como safety net
                setTimeout(() => {
                    form.dataset.submitted = 'false';
                    btn.disabled = false;
                    btn.innerHTML = btn.dataset.originalHtml || btn.innerHTML;
                }, 10000);
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 13. BACK TO TOP — Botón flotante
    // ═══════════════════════════════════════════════════════════

    function initBackToTop() {
        const btn = document.createElement('button');
        btn.className = 'ltms-back-to-top';
        btn.setAttribute('aria-label', 'Volver arriba');
        btn.innerHTML = '↑';
        // BACK-TO-TOP-OVERLAP FIX (2026-09-12): la posición (right/bottom) vive
        // en ltms-ux-enhancements.css para poder apilarlo POR ENCIMA del botón de
        // soporte (.ltms-live-chat) y no sobreponerse, con su variante móvil.
        btn.style.cssText = `
            position: fixed;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: var(--ltms-primary, #1a5276);
            color: #fff;
            border: none;
            cursor: pointer;
            font-size: 1.3rem;
            font-weight: 700;
            box-shadow: 0 4px 14px rgba(15,76,117,0.3);
            opacity: 0;
            visibility: hidden;
            transform: translateY(20px);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1000;
        `;
        document.body.appendChild(btn);

        const scrollContainer = document.querySelector('.ltms-main-content, .ltms-dashboard-container') || window;
        scrollContainer.addEventListener('scroll', () => {
            const scrollTop = scrollContainer === window ? window.scrollY : scrollContainer.scrollTop;
            if (scrollTop > 400) {
                btn.style.opacity = '1';
                btn.style.visibility = 'visible';
                btn.style.transform = 'translateY(0)';
            } else {
                btn.style.opacity = '0';
                btn.style.visibility = 'hidden';
                btn.style.transform = 'translateY(20px)';
            }
        });

        btn.addEventListener('click', () => {
            if (scrollContainer === window) {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } else {
                scrollContainer.scrollTo({ top: 0, behavior: 'smooth' });
            }
        });

        btn.addEventListener('mouseenter', () => {
            btn.style.transform = 'translateY(-3px) scale(1.05)';
            btn.style.boxShadow = '0 6px 20px rgba(15,76,117,0.4)';
        });
        btn.addEventListener('mouseleave', () => {
            btn.style.transform = 'translateY(0) scale(1)';
            btn.style.boxShadow = '0 4px 14px rgba(15,76,117,0.3)';
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 15. PASSWORD TOGGLE — Eye icon swap
    // ═══════════════════════════════════════════════════════════

    function initPasswordToggles() {
        document.addEventListener('click', (e) => {
            const btn = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('.ltms-toggle-password');
            if (!btn) return;

            const targetId = btn.dataset.target;
            if (!targetId) return;

            const input = document.getElementById(targetId);
            if (!input) return;

            const isVisible = input.type === 'text';
            input.type = isVisible ? 'password' : 'text';
            btn.classList.toggle('is-visible', !isVisible);
            btn.setAttribute('aria-label', isVisible ? 'Mostrar contraseña' : 'Ocultar contraseña');

            // Mantener focus en el input
            input.focus();
            // Colocar cursor al final
            const len = input.value.length;
            input.setSelectionRange(len, len);
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 17. REFRESH BUTTON — Spinner animation
    // ═══════════════════════════════════════════════════════════

    function initRefreshButton() {
        document.addEventListener('click', (e) => {
            const btn = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('.ltms-refresh-btn');
            if (!btn) return;

            btn.classList.add('spinning');
            btn.disabled = true;
            setTimeout(() => {
                btn.classList.remove('spinning');
                btn.disabled = false;
            }, 1500);
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 20. FOCUS TRAP — Accesibilidad de modales (WCAG 2.1)
    // ═══════════════════════════════════════════════════════════

    /**
     * Mantiene el focus dentro de un contenedor (modal/dropdown).
     * Esencial para accesibilidad: usuarios de teclado no pueden
     * tabular fuera del modal mientras está abierto.
     *
     * @param {HTMLElement} container Elemento contenedor
     * @param {HTMLElement} initialFocus Elemento a enfocar al abrir (opcional)
     * @returns {Function} Función de cleanup para desactivar el trap
     */
    function trapFocus(container, initialFocus) {
        if (!container) return () => {};

        // Focusables: a, button, input, select, textarea, [tabindex] no negativos
        const focusableSelector = [
            'a[href]',
            'button:not([disabled])',
            'input:not([disabled]):not([type="hidden"])',
            'select:not([disabled])',
            'textarea:not([disabled])',
            '[tabindex]:not([tabindex="-1"])',
        ].join(',');

        let lastFocused = document.activeElement;

        // Enfocar el contenedor o el primer focusable
        setTimeout(() => {
            const target = initialFocus || container.querySelector(focusableSelector);
            if (target) {
                target.focus();
            } else {
                container.setAttribute('tabindex', '-1');
                container.focus();
            }
        }, 50);

        function handleKeydown(e) {
            if (e.key !== 'Tab') return;

            const focusables = container.querySelectorAll(focusableSelector);
            if (!focusables.length) return;

            const first = focusables[0];
            const last = focusables[focusables.length - 1];

            if (e.shiftKey) {
                // Shift+Tab: si está en el primero, ir al último
                if (document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                }
            } else {
                // Tab: si está en el último, ir al primero
                if (document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        }

        container.addEventListener('keydown', handleKeydown);

        // Cleanup function
        return function release() {
            container.removeEventListener('keydown', handleKeydown);
            // Restaurar focus al elemento que tenía antes
            if (lastFocused && typeof lastFocused.focus === 'function') {
                lastFocused.focus();
            }
        };
    }

    LTMS.UX.trapFocus = trapFocus;

    /**
     * Inicializa focus trap automático para modales de LTMS.
     * Detecta cuando un modal se hace visible y aplica el trap.
     */
    function initModalFocusTrap() {
        const traps = new Map(); // modal -> cleanup function

        // Observer para detectar modales que se vuelven visibles
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                if (mutation.type !== 'attributes') return;
                if (mutation.attributeName !== 'style' && mutation.attributeName !== 'class') return;

                const modal = mutation.target;
                if (!modal.classList.contains('ltms-modal') && !modal.classList.contains('ltms-modal-overlay')) return;

                const isVisible = modal.style.display !== 'none' ||
                                  modal.classList.contains('ltms-modal-open') ||
                                  modal.classList.contains('open');

                if (isVisible && !traps.has(modal)) {
                    // Modal abierto: activar trap
                    const inner = modal.querySelector('.ltms-modal-inner, .ltms-modal') || modal;
                    const cleanup = trapFocus(inner);
                    traps.set(modal, cleanup);
                } else if (!isVisible && traps.has(modal)) {
                    // Modal cerrado: desactivar trap
                    const cleanup = traps.get(modal);
                    cleanup();
                    traps.delete(modal);
                }
            });
        });

        // Observar todos los modales existentes
        document.querySelectorAll('.ltms-modal, .ltms-modal-overlay').forEach((modal) => {
            observer.observe(modal, { attributes: true, attributeFilter: ['style', 'class'] });
        });

        // Observar modales que se añadan dinámicamente
        const bodyObserver = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType !== 1) return;
                    if (node.classList && (node.classList.contains('ltms-modal') || node.classList.contains('ltms-modal-overlay'))) {
                        observer.observe(node, { attributes: true, attributeFilter: ['style', 'class'] });
                    }
                    // También observar modales dentro del nodo añadido
                    node.querySelectorAll && node.querySelectorAll('.ltms-modal, .ltms-modal-overlay').forEach((m) => {
                        observer.observe(m, { attributes: true, attributeFilter: ['style', 'class'] });
                    });
                });
            });
        });
        bodyObserver.observe(document.body, { childList: true, subtree: false });
    }

    // ═══════════════════════════════════════════════════════════
    // 23. LIVE REGION — Anuncios para screen readers
    // ═══════════════════════════════════════════════════════════

    function ensureLiveRegion() {
        let region = document.getElementById('ltms-live-region');
        if (!region) {
            region = document.createElement('div');
            region.id = 'ltms-live-region';
            region.setAttribute('aria-live', 'polite');
            region.setAttribute('aria-atomic', 'true');
            region.className = 'ltms-sr-only';
            document.body.appendChild(region);
        }
        return region;
    }

    /**
     * Anuncia un mensaje a screen readers via live region.
     * Útil para: "Cargando pedidos...", "3 resultados encontrados",
     * "Producto agregado al carrito", etc.
     */
    function announce(message) {
        const region = ensureLiveRegion();
        region.textContent = '';
        // Pequeño delay para asegurar que el screen reader lo detecte
        setTimeout(() => { region.textContent = message; }, 50);
    }

    LTMS.UX.announce = announce;

    // ═══════════════════════════════════════════════════════════
    // 25. GLOBAL SEARCH — Overlay de búsqueda con resultados
    // ═══════════════════════════════════════════════════════════

    /**
     * Búsqueda global con overlay tipo spotlight.
     * Diferente del command palette: esta busca contenido real
     * (productos, pedidos) vía AJAX, no solo navegación.
     */

    let searchOverlay = null;
    let searchInput = null;
    let searchResults = null;
    let searchCleanup = null;
    let searchTimer = null;

    function openGlobalSearch() {
        if (searchOverlay) return;

        searchOverlay = document.createElement('div');
        searchOverlay.className = 'ltms-search-overlay';
        searchOverlay.innerHTML = `
            <div class="ltms-search-modal" role="dialog" aria-modal="true" aria-label="Búsqueda global">
                <div class="ltms-search-input-wrap">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color:var(--ltms-gray-400);"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" class="ltms-search-input" placeholder="Buscar productos, pedidos..." autocomplete="off" />
                    <kbd>Esc</kbd>
                </div>
                <div class="ltms-search-results"></div>
                <div class="ltms-search-footer">
                    <span><kbd>↑</kbd><kbd>↓</kbd> navegar · <kbd>↵</kbd> abrir</span>
                    <span>Búsqueda powered by Lo Tengo</span>
                </div>
            </div>
        `;
        document.body.appendChild(searchOverlay);
        document.body.style.overflow = 'hidden';

        searchInput = searchOverlay.querySelector('.ltms-search-input');
        searchResults = searchOverlay.querySelector('.ltms-search-results');

        // Animación de entrada
        requestAnimationFrame(() => searchOverlay.classList.add('ltms-search-open'));

        // Input handler con debounce
        searchInput.addEventListener('input', (e) => {
            clearTimeout(searchTimer);
            const query = e.target.value.trim();
            if (query.length < 2) {
                searchResults.innerHTML = '';
                return;
            }
            searchTimer = setTimeout(() => performSearch(query), 250);
        });

        // Keyboard
        searchInput.addEventListener('keydown', handleSearchKeydown);

        // Click en overlay cierra
        searchOverlay.querySelector('.ltms-search-modal').addEventListener('click', (e) => e.stopPropagation());
        searchOverlay.addEventListener('click', closeGlobalSearch);

        // Focus trap
        const modal = searchOverlay.querySelector('.ltms-search-modal');
        searchCleanup = trapFocus(modal, searchInput);
    }

    function performSearch(query) {
        searchResults.innerHTML = '<div class="ltms-search-empty"><div class="ltms-search-empty-icon">🔍</div>Buscando...</div>';

        // Intentar usar el endpoint de live search si está disponible
        if (typeof ltmsPublic !== 'undefined' && ltmsPublic.searchNonce) {
            const formData = new FormData();
            formData.append('action', 'ltms_live_search');
            formData.append('nonce', ltmsPublic.searchNonce);
            formData.append('q', query);

            fetch(ltmsAjax || (typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php'), {
                method: 'POST',
                body: formData,
            })
                .then((r) => r.json())
                .then((data) => renderSearchResults(data, query))
                .catch(() => renderSearchResults({ success: false }, query));
        } else {
            renderSearchResults({ success: false }, query);
        }
    }

    function renderSearchResults(data, query) {
        if (!data || !data.success || !data.data || !data.data.results || !data.data.results.length) {
            searchResults.innerHTML = `
                <div class="ltms-search-empty">
                    <div class="ltms-search-empty-icon">🔍</div>
                    <p>No se encontraron resultados para "<strong>${escapeHtml(query)}</strong>"</p>
                    <p style="font-size:0.75rem;margin-top:8px;">Intenta con otros términos o revisa la ortografía.</p>
                </div>
            `;
            return;
        }

        const results = data.data.results.slice(0, 8);
        searchResults.innerHTML = results.map((r) => `
            <a href="${escapeHtml(r.url || '#')}" class="ltms-search-result">
                <div class="ltms-search-result-icon">
                    ${r.image ? `<img src="${escapeHtml(r.image)}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;">` : '📦'}
                </div>
                <div class="ltms-search-result-info">
                    <p class="ltms-search-result-title">${escapeHtml(r.title || r.name || '')}</p>
                    <p class="ltms-search-result-meta">${escapeHtml(r.meta || r.price || '')}</p>
                </div>
            </a>
        `).join('');
    }

    function handleSearchKeydown(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            closeGlobalSearch();
        }
    }

    function closeGlobalSearch() {
        if (!searchOverlay) return;
        if (searchCleanup) {
            searchCleanup();
            searchCleanup = null;
        }
        searchOverlay.classList.remove('ltms-search-open');
        document.body.style.overflow = '';
        setTimeout(() => {
            if (searchOverlay && searchOverlay.parentNode) searchOverlay.parentNode.removeChild(searchOverlay);
            searchOverlay = null;
            searchInput = null;
            searchResults = null;
        }, 200);
    }

    LTMS.UX.openGlobalSearch = openGlobalSearch;
    LTMS.UX.closeGlobalSearch = closeGlobalSearch;

    function initGlobalSearch() {
        // Shift+/ (que es "?") abre la búsqueda global
        document.addEventListener('keydown', (e) => {
            if (e.shiftKey && e.key === '/' && !e.target.matches('input, textarea, select')) {
                e.preventDefault();
                openGlobalSearch();
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 28. FILE UPLOAD — Drag & drop con progress
    // ═══════════════════════════════════════════════════════════

    /**
     * Mejora los inputs de archivo con drag & drop, preview,
     * progress bar y validación visual.
     */

    function initFileUploads() {
        document.addEventListener('change', (e) => {
            const input = e.target;
            if (!input.matches('input[type="file"].ltms-file-input, .ltms-dropzone input[type="file"]')) return;

            handleFileSelection(input);
        });

        // Drag & drop zones
        document.addEventListener('dragover', (e) => {
            const zone = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('.ltms-dropzone');
            if (!zone) return;

            e.preventDefault();
            zone.classList.add('ltms-dropzone-active');
        });

        document.addEventListener('dragleave', (e) => {
            const zone = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('.ltms-dropzone');
            if (!zone) return;

            // Solo remover si salimos de la zona completamente
            if (!zone.contains(e.relatedTarget)) {
                zone.classList.remove('ltms-dropzone-active');
            }
        });

        document.addEventListener('drop', (e) => {
            const zone = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('.ltms-dropzone');
            if (!zone) return;

            e.preventDefault();
            zone.classList.remove('ltms-dropzone-active');

            const input = zone.querySelector('input[type="file"]');
            if (input && e.dataTransfer.files.length) {
                input.files = e.dataTransfer.files;
                handleFileSelection(input);
            }
        });
    }

    function handleFileSelection(input) {
        const files = Array.from(input.files);
        if (!files.length) return;

        // Buscar o crear zona de preview
        let previewZone = input.parentElement.querySelector('.ltms-file-preview');
        if (!previewZone) {
            previewZone = document.createElement('div');
            previewZone.className = 'ltms-file-preview';
            input.parentElement.appendChild(previewZone);
        }

        previewZone.innerHTML = '';

        files.forEach((file) => {
            const item = document.createElement('div');
            item.className = 'ltms-file-item';
            item.innerHTML = `
                <div class="ltms-file-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                </div>
                <div class="ltms-file-info">
                    <div class="ltms-file-name">${escapeHtml(file.name)}</div>
                    <div class="ltms-file-meta">${formatFileSize(file.size)}</div>
                    <div class="ltms-file-progress">
                        <div class="ltms-file-progress-bar" style="width:0%;"></div>
                    </div>
                </div>
                <button type="button" class="ltms-file-remove" aria-label="Quitar archivo">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            `;

            // Si es imagen, mostrar preview
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = (e) => {
                    const iconDiv = item.querySelector('.ltms-file-icon');
                    iconDiv.innerHTML = `<img src="${e.target.result}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;">`;
                };
                reader.readAsDataURL(file);
            }

            // Botón quitar
            item.querySelector('.ltms-file-remove').addEventListener('click', () => {
                item.style.opacity = '0';
                item.style.transform = 'translateX(10px)';
                setTimeout(() => item.remove(), 200);
            });

            previewZone.appendChild(item);

            // Simular progreso de subida (visual feedback)
            const progressBar = item.querySelector('.ltms-file-progress-bar');
            let progress = 0;
            const interval = setInterval(() => {
                progress += Math.random() * 20;
                if (progress >= 100) {
                    progress = 100;
                    clearInterval(interval);
                    progressBar.style.background = 'var(--ltms-success)';
                    setTimeout(() => {
                        const pg = item.querySelector('.ltms-file-progress');
                        if (pg) pg.style.opacity = '0';
                    }, 500);
                }
                progressBar.style.width = progress + '%';
            }, 100);
        });
    }

    function formatFileSize(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    LTMS.UX.formatFileSize = formatFileSize;

    // ═══════════════════════════════════════════════════════════
    // 29. PWA INSTALL — Prompt de instalación mejorado
    // ═══════════════════════════════════════════════════════════

    let deferredPrompt = null;

    function initPWAInstall() {
        // Capturar el evento beforeinstallprompt
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            showInstallBanner();
        });

        // Detectar si ya está instalada
        window.addEventListener('appinstalled', () => {
            hideInstallBanner();
            toast('success', '¡App instalada!', 'Accede desde tu pantalla de inicio.');
            try { localStorage.setItem('ltms-pwa-installed', 'true'); } catch (e) {}
        });

        // Si ya está instalada, no mostrar banner
        if (window.matchMedia('(display-mode: standalone)').matches ||
            window.matchMedia('(display-mode: fullscreen)').matches) {
            return;
        }

        // Verificar si el usuario ya rechazó
        try {
            const dismissed = localStorage.getItem('ltms-install-dismissed');
            if (dismissed === 'true') return;
        } catch (e) {}
    }

    function showInstallBanner() {
        if (!deferredPrompt) return;
        if (document.querySelector('.ltms-install-banner')) return;

        const banner = document.createElement('div');
        banner.className = 'ltms-install-banner';
        banner.innerHTML = `
            <div class="ltms-install-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            </div>
            <div class="ltms-install-content">
                <strong>Instala la app</strong>
                <span>Acceso rápido desde tu pantalla de inicio</span>
            </div>
            <div class="ltms-install-actions">
                <button type="button" class="ltms-install-dismiss">Ahora no</button>
                <button type="button" class="ltms-install-accept">Instalar</button>
            </div>
        `;

        document.body.appendChild(banner);

        requestAnimationFrame(() => banner.classList.add('visible'));

        banner.querySelector('.ltms-install-accept').addEventListener('click', async () => {
            banner.classList.remove('visible');
            if (deferredPrompt) {
                deferredPrompt.prompt();
                const choice = await deferredPrompt.userChoice;
                if (choice.outcome === 'dismissed') {
                    try { localStorage.setItem('ltms-install-dismissed', 'true'); } catch (e) {}
                }
                deferredPrompt = null;
            }
            setTimeout(() => banner.remove(), 300);
        });

        banner.querySelector('.ltms-install-dismiss').addEventListener('click', () => {
            banner.classList.remove('visible');
            try { localStorage.setItem('ltms-install-dismissed', 'true'); } catch (e) {}
            setTimeout(() => banner.remove(), 300);
        });
    }

    function hideInstallBanner() {
        const banner = document.querySelector('.ltms-install-banner');
        if (banner) {
            banner.classList.remove('visible');
            setTimeout(() => banner.remove(), 300);
        }
    }

    // ═══════════════════════════════════════════════════════════
    // 30. ERROR BOUNDARIES — Manejo de errores graceful
    // ═══════════════════════════════════════════════════════════

    /**
     * Captura errores JS globales y muestra un mensaje amigable
     * en lugar de romper la experiencia silenciosamente.
     */

    function initErrorBoundaries() {
        // v2.9.31: DISABLED — el error boundary mostraba popups "Algo salió mal"
        // por errores JS menores que no afectan la funcionalidad. Solo loguear
        // a consola sin mostrar toast al usuario.
        // Para reactivar: cambiar el flag abajo a true.

        const SHOW_ERROR_TOASTS = false; // Cambiar a true para debug

        // Errores JS no capturados
        window.addEventListener('error', (e) => {
            console.error('[LTMS.UX] Error capturado:', e.error || e.message);

            if (!SHOW_ERROR_TOASTS) return;

            // No mostrar toast para errores de red de recursos (img, script)
            if (e.target && e.target.tagName) return;

            // En desarrollo, no interferir
            if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') return;

            toast('error', 'Algo salió mal', 'Se produjo un error inesperado. Si persiste, recarga la página.', {
                duration: 4000,
                action: {
                    label: 'Recargar',
                    action: () => window.location.reload(),
                },
            });
        });

        // Promesas rechazadas no capturadas
        window.addEventListener('unhandledrejection', (e) => {
            console.error('[LTMS.UX] Promesa rechazada:', e.reason);

            if (!SHOW_ERROR_TOASTS) return;

            if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') return;

            // Solo mostrar toast si no es un error de red común
            const reason = String(e.reason || '');
            if (reason.includes('NetworkError') || reason.includes('Failed to fetch')) {
                toast('warning', 'Problema de conexión', 'Verifica tu internet e intenta de nuevo.', { duration: 6000 });
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 31. PERFORMANCE MONITOR — Métricas de rendimiento
    // ═══════════════════════════════════════════════════════════

    /**
     * Monitor ligero de rendimiento que mide:
     * - Time to Interactive (TTI)
     * - AJAX request duration
     * - Vista load time
     * Los datos se exponen en LTMS.UX.perf para debugging.
     */

    const perfMetrics = {
        pageLoad: 0,
        ajaxRequests: [],
        viewLoads: [],
    };

    function initPerfMonitor() {
        // Page load time
        window.addEventListener('load', () => {
            setTimeout(() => {
                const timing = performance.timing;
                const loadTime = timing.loadEventEnd - timing.navigationStart;
                perfMetrics.pageLoad = loadTime;

                if (loadTime > 3000) {
                    console.warn('[LTMS.UX] Página lenta:', loadTime + 'ms');
                }
            }, 0);
        });

        // Interceptar AJAX (jQuery)
        if (typeof jQuery !== 'undefined') {
            jQuery(document).ajaxSend((event, jqXHR, settings) => {
                settings._ltmsStartTime = performance.now();
            });

            jQuery(document).ajaxComplete((event, jqXHR, settings) => {
                if (!settings._ltmsStartTime) return;
                const duration = performance.now() - settings._ltmsStartTime;
                perfMetrics.ajaxRequests.push({
                    url: settings.url,
                    duration: Math.round(duration),
                    timestamp: Date.now(),
                });

                // Mantener solo las últimas 50
                if (perfMetrics.ajaxRequests.length > 50) {
                    perfMetrics.ajaxRequests.shift();
                }

                // Alertar si una petición tarda mucho
                if (duration > 5000) {
                    console.warn('[LTMS.UX] AJAX lento:', settings.url, duration + 'ms');
                }
            });
        }

        // Exponer métricas
        LTMS.UX.perf = perfMetrics;
    }

    // ═══════════════════════════════════════════════════════════
    // 32. CONTEXTUAL HELP — Tooltips y coach marks
    // ═══════════════════════════════════════════════════════════

    /**
     * Sistema de ayuda contextual que muestra tooltips informativos
     * al hacer hover o focus en elementos con data-help.
     * También soporta coach marks persistentes que el usuario puede
     * descartar.
     */

    let helpTooltip = null;

    function showHelpTooltip(element, text) {
        hideHelpTooltip();

        helpTooltip = document.createElement('div');
        helpTooltip.className = 'ltms-help-tooltip';
        helpTooltip.setAttribute('role', 'tooltip');
        helpTooltip.innerHTML = `
            <div class="ltms-help-content">${escapeHtml(text)}</div>
            <div class="ltms-help-arrow"></div>
        `;
        document.body.appendChild(helpTooltip);

        const rect = element.getBoundingClientRect();
        const tooltipRect = helpTooltip.getBoundingClientRect();

        let top = rect.bottom + 8;
        let left = rect.left + (rect.width / 2) - (tooltipRect.width / 2);

        // Ajustar si sale de pantalla
        if (left < 8) left = 8;
        if (left + tooltipRect.width > window.innerWidth - 8) {
            left = window.innerWidth - tooltipRect.width - 8;
        }
        if (top + tooltipRect.height > window.innerHeight - 8) {
            top = rect.top - tooltipRect.height - 8;
            helpTooltip.classList.add('ltms-help-tooltip-top');
        }

        helpTooltip.style.top = top + 'px';
        helpTooltip.style.left = left + 'px';

        requestAnimationFrame(() => helpTooltip.classList.add('visible'));
    }

    function hideHelpTooltip() {
        if (helpTooltip) {
            helpTooltip.classList.remove('visible');
            const el = helpTooltip;
            helpTooltip = null;
            setTimeout(() => {
                if (el && el.parentNode) el.parentNode.removeChild(el);
            }, 200);
        }
    }

    function initContextualHelp() {
        // Tooltip en hover/focus para elementos con data-help
        const showHelp = (e) => {
            const target = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('[data-help]');
            if (!target) return;
            const text = target.dataset.help;
            if (!text) return;
            showHelpTooltip(target, text);
        };

        const hideHelp = (e) => {
            if (!e.target.closest('[data-help]')) {
                hideHelpTooltip();
            }
        };

        document.addEventListener('mouseover', showHelp);
        document.addEventListener('mouseout', hideHelp);
        document.addEventListener('focusin', showHelp);
        document.addEventListener('focusout', hideHelp);

        // Scroll cierra tooltip
        window.addEventListener('scroll', hideHelpTooltip, { passive: true });
    }

    // ═══════════════════════════════════════════════════════════
    // 33. FORM VALIDATION — Biblioteca de validación
    // ═══════════════════════════════════════════════════════════

    /**
     * Biblioteca de validación de formularios con reglas
     * predefinidas y mensajes en español.
     */

    const VALIDATORS = {
        required: (val) => {
            if (typeof val === 'string') return val.trim().length > 0;
            if (Array.isArray(val)) return val.length > 0;
            return val !== null && val !== undefined;
        },
        email: (val) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val),
        phone: (val) => /^[\d\s\+\-\(\)]{7,}$/.test(val),
        url: (val) => {
            try { new URL(val); return true; } catch (e) { return false; }
        },
        number: (val) => !isNaN(parseFloat(val)) && isFinite(val),
        min: (val, param) => parseFloat(val) >= parseFloat(param),
        max: (val, param) => parseFloat(val) <= parseFloat(param),
        minlength: (val, param) => String(val).length >= parseInt(param, 10),
        maxlength: (val, param) => String(val).length <= parseInt(param, 10),
        pattern: (val, param) => new RegExp(param).test(val),
        colombia_phone: (val) => /^(\+?57)?[\s\-]?3[\d\s\-]{9}$/.test(val.replace(/\s/g, '')),
        mexico_phone: (val) => /^(\+?52)?[\s\-]?[\d\s\-]{10}$/.test(val.replace(/\s/g, '')),
        cc_colombia: (val) => /^[0-9]{6,10}$/.test(val.replace(/\D/g, '')),
        nit_colombia: (val) => /^[0-9]{8,9}-?[0-9]$/.test(val.replace(/\D/g, '')),
        rfc_mexico: (val) => /^[A-ZÑ&]{3,4}[0-9]{6}[A-Z0-9]{3}$/.test(val.toUpperCase()),
        currency: (val) => /^\d+(\.\d{1,2})?$/.test(val) && parseFloat(val) >= 0,
        password_strong: (val) => {
            // Mínimo 8, mayúscula, minúscula, número
            return val.length >= 8 && /[A-Z]/.test(val) && /[a-z]/.test(val) && /[0-9]/.test(val);
        },
    };

    const VALIDATION_MESSAGES = {
        required: 'Este campo es obligatorio',
        email: 'Ingresa un email válido (ej: tu@email.com)',
        phone: 'Ingresa un teléfono válido',
        url: 'Ingresa una URL válida (ej: https://...)',
        number: 'Ingresa un número válido',
        min: (param) => `El valor mínimo es ${param}`,
        max: (param) => `El valor máximo es ${param}`,
        minlength: (param) => `Mínimo ${param} caracteres`,
        maxlength: (param) => `Máximo ${param} caracteres`,
        pattern: 'Formato no válido',
        colombia_phone: 'Ingresa un teléfono colombiano válido (ej: +57 300 000 0000)',
        mexico_phone: 'Ingresa un teléfono mexicano válido (ej: +52 55 0000 0000)',
        cc_colombia: 'Cédula no válida (6-10 dígitos)',
        nit_colombia: 'NIT no válido (formato: 99999999-9)',
        rfc_mexico: 'RFC no válido',
        currency: 'Ingresa un monto válido (ej: 1000.00)',
        password_strong: 'La contraseña debe tener mínimo 8 caracteres, mayúsculas, minúsculas y números',
    };

    function validateField(input, rules) {
        const value = input.type === 'checkbox' ? input.checked : input.value;
        const errors = [];

        for (const rule of rules) {
            let ruleName, param;
            if (typeof rule === 'string') {
                ruleName = rule;
            } else {
                ruleName = rule.name;
                param = rule.param;
            }

            const validator = VALIDATORS[ruleName];
            if (!validator) continue;

            if (!validator(value, param)) {
                const msg = VALIDATION_MESSAGES[ruleName];
                errors.push(typeof msg === 'function' ? msg(param) : msg);
            }
        }

        return errors;
    }

    function showFieldError(input, message) {
        input.classList.add('ltms-input-error');

        let errorEl = input.parentElement.querySelector('.ltms-field-error');
        if (!errorEl) {
            errorEl = document.createElement('div');
            errorEl.className = 'ltms-field-error';
            input.parentElement.appendChild(errorEl);
        }

        errorEl.innerHTML = `
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            ${escapeHtml(message)}
        `;
        errorEl.style.display = 'block';

        input.setAttribute('aria-invalid', 'true');
        input.setAttribute('aria-describedby', errorEl.id || (errorEl.id = 'ltms-err-' + Math.random().toString(36).substr(2, 9)));
    }

    function clearFieldError(input) {
        input.classList.remove('ltms-input-error');
        const errorEl = input.parentElement.querySelector('.ltms-field-error');
        if (errorEl) errorEl.style.display = 'none';
        input.removeAttribute('aria-invalid');
    }

    function validateForm(form, config) {
        let isValid = true;
        let firstError = null;

        for (const fieldName in config) {
            const input = form.querySelector(`[name="${fieldName}"], #${fieldName}`);
            if (!input) continue;

            const errors = validateField(input, config[fieldName]);

            if (errors.length > 0) {
                showFieldError(input, errors[0]);
                if (!firstError) firstError = input;
                isValid = false;
            } else {
                clearFieldError(input);
            }
        }

        if (firstError) {
            firstError.focus();
            firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        return isValid;
    }

    function initFormValidation() {
        // Validación en tiempo real (al blur)
        document.addEventListener('blur', (e) => {
            const input = e.target;
            if (!input.matches('[data-validate]')) return;

            const rules = input.dataset.validate.split('|').map((r) => {
                const [name, param] = r.split(':');
                return param ? { name, param } : name;
            });

            const errors = validateField(input, rules);
            if (errors.length > 0 && input.value) {
                showFieldError(input, errors[0]);
            } else {
                clearFieldError(input);
            }
        }, true);

        // Limpiar error al escribir
        document.addEventListener('input', (e) => {
            if (e.target.classList.contains('ltms-input-error')) {
                clearFieldError(e.target);
            }
        });
    }

    // API pública de validación
    LTMS.UX.validateForm = validateForm;
    LTMS.UX.validateField = validateField;
    LTMS.UX.showFieldError = showFieldError;
    LTMS.UX.clearFieldError = clearFieldError;
    LTMS.UX.VALIDATORS = VALIDATORS;

    // ═══════════════════════════════════════════════════════════
    // 35. USER PREFERENCES — Panel de preferencias
    // ═══════════════════════════════════════════════════════════

    /**
     * Panel de preferencias de usuario: densidad, animaciones,
     * tamaño de fuente, etc. Persistente en localStorage.
     */

    const DEFAULT_PREFS = {
        density: 'comfortable', // compact | comfortable | spacious
        fontSize: 'medium', // small | medium | large
        animations: 'enabled', // enabled | reduced
        sound: 'disabled', // enabled | disabled
        autoRefresh: 'enabled', // enabled | disabled
    };

    function getUserPrefs() {
        try {
            const saved = localStorage.getItem('ltms-user-prefs');
            return { ...DEFAULT_PREFS, ...(saved ? JSON.parse(saved) : {}) };
        } catch (e) {
            return { ...DEFAULT_PREFS };
        }
    }

    function setUserPrefs(prefs) {
        try {
            const current = getUserPrefs();
            const updated = { ...current, ...prefs };
            localStorage.setItem('ltms-user-prefs', JSON.stringify(updated));
            applyUserPrefs(updated);
            return updated;
        } catch (e) {
            return DEFAULT_PREFS;
        }
    }

    function applyUserPrefs(prefs) {
        const root = document.documentElement;

        // Densidad
        root.setAttribute('data-ltms-density', prefs.density);

        // Tamaño de fuente
        root.setAttribute('data-ltms-font-size', prefs.fontSize);

        // Animaciones
        if (prefs.animations === 'reduced') {
            root.setAttribute('data-ltms-animations', 'reduced');
        } else {
            root.removeAttribute('data-ltms-animations');
        }

        // Sonido
        root.setAttribute('data-ltms-sound', prefs.sound);
    }

    function openPreferencesPanel() {
        const prefs = getUserPrefs();

        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay';
        overlay.innerHTML = `
            <div class="ltms-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-prefs-title">
                <div class="ltms-modal-header">
                    <h3 class="ltms-modal-title" id="ltms-prefs-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        Preferencias
                    </h3>
                    <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ltms-modal-body">
                    <div class="ltms-pref-group">
                        <label class="ltms-pref-label">Densidad de la interfaz</label>
                        <div class="ltms-pref-options">
                            <label class="ltms-pref-option ${prefs.density === 'compact' ? 'active' : ''}">
                                <input type="radio" name="density" value="compact" ${prefs.density === 'compact' ? 'checked' : ''}>
                                <span>Compacta</span>
                            </label>
                            <label class="ltms-pref-option ${prefs.density === 'comfortable' ? 'active' : ''}">
                                <input type="radio" name="density" value="comfortable" ${prefs.density === 'comfortable' ? 'checked' : ''}>
                                <span>Cómoda</span>
                            </label>
                            <label class="ltms-pref-option ${prefs.density === 'spacious' ? 'active' : ''}">
                                <input type="radio" name="density" value="spacious" ${prefs.density === 'spacious' ? 'checked' : ''}>
                                <span>Espaciosa</span>
                            </label>
                        </div>
                    </div>

                    <div class="ltms-pref-group">
                        <label class="ltms-pref-label">Tamaño de fuente</label>
                        <div class="ltms-pref-options">
                            <label class="ltms-pref-option ${prefs.fontSize === 'small' ? 'active' : ''}">
                                <input type="radio" name="fontSize" value="small" ${prefs.fontSize === 'small' ? 'checked' : ''}>
                                <span style="font-size:0.85rem;">Pequeña</span>
                            </label>
                            <label class="ltms-pref-option ${prefs.fontSize === 'medium' ? 'active' : ''}">
                                <input type="radio" name="fontSize" value="medium" ${prefs.fontSize === 'medium' ? 'checked' : ''}>
                                <span>Mediana</span>
                            </label>
                            <label class="ltms-pref-option ${prefs.fontSize === 'large' ? 'active' : ''}">
                                <input type="radio" name="fontSize" value="large" ${prefs.fontSize === 'large' ? 'checked' : ''}>
                                <span style="font-size:1.1rem;">Grande</span>
                            </label>
                        </div>
                    </div>

                    <div class="ltms-pref-group">
                        <label class="ltms-pref-label">Animaciones</label>
                        <div class="ltms-pref-options">
                            <label class="ltms-pref-option ${prefs.animations === 'enabled' ? 'active' : ''}">
                                <input type="radio" name="animations" value="enabled" ${prefs.animations === 'enabled' ? 'checked' : ''}>
                                <span>Activadas</span>
                            </label>
                            <label class="ltms-pref-option ${prefs.animations === 'reduced' ? 'active' : ''}">
                                <input type="radio" name="animations" value="reduced" ${prefs.animations === 'reduced' ? 'checked' : ''}>
                                <span>Reducidas</span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="ltms-modal-footer">
                    <button type="button" class="ltms-btn ltms-btn-outline ltms-modal-close">Cancelar</button>
                    <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-prefs-save">Guardar preferencias</button>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);

        requestAnimationFrame(() => overlay.classList.add('ltms-modal-open'));

        const cleanup = trapFocus(overlay.querySelector('.ltms-modal'));

        // Close handlers
        overlay.querySelectorAll('.ltms-modal-close').forEach((btn) => {
            btn.addEventListener('click', () => {
                cleanup();
                overlay.classList.remove('ltms-modal-open');
                setTimeout(() => overlay.remove(), 250);
            });
        });

        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                cleanup();
                overlay.classList.remove('ltms-modal-open');
                setTimeout(() => overlay.remove(), 250);
            }
        });

        // Option selection visual feedback
        overlay.querySelectorAll('.ltms-pref-option').forEach((opt) => {
            opt.addEventListener('click', () => {
                const name = opt.querySelector('input').name;
                overlay.querySelectorAll(`input[name="${name}"]`).forEach((input) => {
                    input.parentElement.classList.remove('active');
                });
                opt.classList.add('active');
            });
        });

        // Save
        overlay.querySelector('#ltms-prefs-save').addEventListener('click', () => {
            const newPrefs = {};
            overlay.querySelectorAll('input[type="radio"]:checked').forEach((input) => {
                newPrefs[input.name] = input.value;
            });
            setUserPrefs(newPrefs);
            cleanup();
            overlay.classList.remove('ltms-modal-open');
            setTimeout(() => overlay.remove(), 250);
            toast('success', 'Preferencias guardadas', 'Tus cambios se aplicaron correctamente.');
        });
    }

    LTMS.UX.openPreferences = openPreferencesPanel;
    LTMS.UX.getUserPrefs = getUserPrefs;

    function initPreferences() {
        // Aplicar preferencias al cargar
        applyUserPrefs(getUserPrefs());

        // Cmd+, (Mac) / Ctrl+, (Windows) abre preferencias
        document.addEventListener('keydown', (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key === ',') {
                e.preventDefault();
                openPreferencesPanel();
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 40. ACCESSIBILITY ENHANCEMENTS — Mejoras avanzadas
    // ═══════════════════════════════════════════════════════════

    /**
     * Mejoras de accesibilidad adicionales:
     * - Skip links dinámicos
     * - ARIA live regions para cambios dinámicos
     * - Focus management en vistas SPA
     * - Keyboard navigation mejorada
     * - Screen reader announcements
     */

    function initAccessibilityEnhancements() {
        // Añadir role="main" si no existe
        const mainContent = document.querySelector('.ltms-main-content');
        if (mainContent && !mainContent.getAttribute('role')) {
            mainContent.setAttribute('role', 'main');
        }

        // Añadir aria-label a botones sin texto visible
        document.querySelectorAll('button:not([aria-label]):not([aria-labelledby])').forEach((btn) => {
            if (!btn.textContent.trim() && !btn.querySelector('svg[aria-label]')) {
                const svg = btn.querySelector('svg');
                if (svg) {
                    // Intentar inferir el label del SVG
                    const title = svg.querySelector('title');
                    if (title) {
                        btn.setAttribute('aria-label', title.textContent);
                    }
                }
            }
        });

        // Anunciar cambios de vista SPA
        if (typeof jQuery !== 'undefined') {
            jQuery(document).on('ltms:view:loaded', (e, view) => {
                const viewTitles = {
                    home: 'Inicio',
                    orders: 'Pedidos',
                    products: 'Productos',
                    wallet: 'Billetera',
                    settings: 'Configuración',
                    envios: 'Envíos',
                    bookings: 'Reservas',
                };
                const title = viewTitles[view] || view;
                announce(`Vista cambiada a ${title}`);

                // Mover focus al título de la vista
                setTimeout(() => {
                    const viewTitle = document.querySelector('.ltms-topbar-title');
                    if (viewTitle) {
                        viewTitle.setAttribute('tabindex', '-1');
                        viewTitle.focus({ preventScroll: true });
                    }
                }, 100);
            });
        }

        // Mejorar tablas con aria
        document.querySelectorAll('.ltms-dtable').forEach((table) => {
            if (!table.getAttribute('role')) {
                table.setAttribute('role', 'table');
            }
            const thead = table.querySelector('thead');
            const tbody = table.querySelector('tbody');
            if (thead) thead.setAttribute('role', 'rowgroup');
            if (tbody) tbody.setAttribute('role', 'rowgroup');
            table.querySelectorAll('tr').forEach((tr) => tr.setAttribute('role', 'row'));
            table.querySelectorAll('th').forEach((th) => th.setAttribute('role', 'columnheader'));
            table.querySelectorAll('td').forEach((td) => td.setAttribute('role', 'cell'));
        });

        // Detectar high contrast mode
        if (window.matchMedia('(prefers-contrast: more)').matches) {
            document.documentElement.setAttribute('data-ltms-high-contrast', 'true');
        }

        // Detectar prefers-color-scheme si no hay tema guardado
        if (!localStorage.getItem('ltms-theme')) {
            const darkModeMedia = window.matchMedia('(prefers-color-scheme: dark)');
            if (darkModeMedia.matches) {
                document.documentElement.setAttribute('data-ltms-theme', 'dark');
            }
            darkModeMedia.addEventListener('change', (e) => {
                if (!localStorage.getItem('ltms-theme')) {
                    document.documentElement.setAttribute('data-ltms-theme', e.matches ? 'dark' : 'light');
                }
            });
        }
    }

    // ═══════════════════════════════════════════════════════════
    // 41. PERFORMANCE UTILITIES — Debounce, throttle, lazy load
    // ═══════════════════════════════════════════════════════════

    /**
     * Utilidades de rendimiento reutilizables.
     */

    function throttle(fn, wait) {
        let lastTime = 0;
        let timeout = null;
        return function (...args) {
            const now = Date.now();
            const remaining = wait - (now - lastTime);
            if (remaining <= 0) {
                if (timeout) {
                    clearTimeout(timeout);
                    timeout = null;
                }
                lastTime = now;
                fn.apply(this, args);
            } else if (!timeout) {
                timeout = setTimeout(() => {
                    lastTime = Date.now();
                    timeout = null;
                    fn.apply(this, args);
                }, remaining);
            }
        };
    }

    function memoize(fn) {
        const cache = new Map();
        return function (...args) {
            const key = JSON.stringify(args);
            if (cache.has(key)) return cache.get(key);
            const result = fn.apply(this, args);
            cache.set(key, result);
            return result;
        };
    }

    function lazyLoadComponent(factory, target) {
        return new Promise((resolve) => {
            if ('IntersectionObserver' in window) {
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            observer.unobserve(entry.target);
                            const result = factory();
                            resolve(result);
                        }
                    });
                });
                observer.observe(target);
            } else {
                // Fallback: cargar inmediatamente
                const result = factory();
                resolve(result);
            }
        });
    }

    function prefetchData(url) {
        const link = document.createElement('link');
        link.rel = 'prefetch';
        link.href = url;
        document.head.appendChild(link);
    }

    // Exponer utilidades
    LTMS.UX.throttle = throttle;
    LTMS.UX.memoize = memoize;
    LTMS.UX.lazyLoadComponent = lazyLoadComponent;
    LTMS.UX.prefetchData = prefetchData;

    function initPerformanceOptimizations() {
        // Prefetch de páginas comunes al hover
        document.addEventListener('mouseover', throttle((e) => {
            const link = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('a[href]');
            if (!link) return;
            const href = link.getAttribute('href');
            if (href && href.startsWith('/') && !link.dataset.prefetched) {
                link.dataset.prefetched = 'true';
                prefetchData(href);
            }
        }, 500), { passive: true });

        // Lazy load de imágenes fuera de viewport
        if ('IntersectionObserver' in window) {
            const imgObserver = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        if (img.dataset.src) {
                            img.src = img.dataset.src;
                            img.removeAttribute('data-src');
                        }
                        imgObserver.unobserve(img);
                    }
                });
            }, { rootMargin: '50px' });

            // Observar imágenes lazy existentes
            document.querySelectorAll('img[data-src]').forEach((img) => imgObserver.observe(img));
        }

        // Debounce scroll events
        let scrollTimeout;
        const originalScroll = window.onscroll;
        window.addEventListener('scroll', () => {
            if (scrollTimeout) clearTimeout(scrollTimeout);
            scrollTimeout = setTimeout(() => {
                document.dispatchEvent(new CustomEvent('ltms:scroll-debounced'));
            }, 150);
        }, { passive: true });
    }

    // ═══════════════════════════════════════════════════════════
    // 42. EMPTY STATES LIBRARY — Estados vacíos reutilizables
    // ═══════════════════════════════════════════════════════════

    /**
     * Biblioteca de estados vacíos con ilustraciones SVG,
     * mensajes contextuales y CTAs accionables.
     */

    const EMPTY_STATE_TEMPLATES = {
        orders: {
            icon: '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 16v1a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2"/><path d="M22 11l-3-3h-5v8h5l3-3v-2z"/><circle cx="7.5" cy="17.5" r="2.5"/><circle cx="18.5" cy="17.5" r="2.5"/></svg>',
            title: 'No tienes pedidos aún',
            message: 'Cuando los compradores realicen compras, aparecerán aquí. ¡Comparte tus productos para conseguir tu primera venta!',
            cta: { label: 'Ver mis productos', action: () => LTMS.Dashboard && LTMS.Dashboard.loadView('products') },
        },
        products: {
            icon: '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 11V7a4 4 0 0 0-8 0v4"/><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/></svg>',
            title: 'Tu catálogo está vacío',
            message: 'Agrega tu primer producto para comenzar a vender. Es rápido y fácil.',
            cta: { label: 'Agregar producto', action: () => document.querySelector('#ltms-add-product-btn, [data-ltms-modal-open="ltms-modal-new-product"]') && document.querySelector('#ltms-add-product-btn, [data-ltms-modal-open="ltms-modal-new-product"]').click() },
        },
        wallet: {
            icon: '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/></svg>',
            title: 'Sin movimientos todavía',
            message: 'Tus transacciones de ventas, retiros y depósitos aparecerán aquí.',
        },
        notifications: {
            icon: '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
            title: 'Todo al día',
            message: 'No tienes notificaciones nuevas. Te avisaremos cuando haya novedades.',
        },
        search: {
            icon: '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
            title: 'Sin resultados',
            message: 'No encontramos lo que buscas. Intenta con otros términos.',
        },
        error: {
            icon: '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
            title: 'Algo salió mal',
            message: 'Ocurrió un error al cargar los datos. Intenta nuevamente.',
            cta: { label: 'Reintentar', action: () => window.location.reload() },
        },
        bookings: {
            icon: '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
            title: 'Sin reservas todavía',
            message: 'Las reservas de tus experiencias turísticas aparecerán aquí.',
        },
        envios: {
            icon: '<svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
            title: 'Sin relaciones de envío',
            message: 'Crea tu primera relación de envío para agrupar guías y despachar.',
        },
    };

    function renderEmptyState(type, customData = {}) {
        const template = EMPTY_STATE_TEMPLATES[type] || EMPTY_STATE_TEMPLATES.error;
        const data = { ...template, ...customData };

        const ctaHtml = data.cta ? `
            <button type="button" class="ltms-btn ltms-btn-primary ltms-empty-action" data-empty-action="${type}">
                ${escapeHtml(data.cta.label)}
            </button>
        ` : '';

        return `
            <div class="ltms-empty-state ltms-empty-state-${type}" data-empty-type="${type}">
                <div class="ltms-empty-icon">${data.icon}</div>
                <h3>${escapeHtml(data.title)}</h3>
                <p>${escapeHtml(data.message)}</p>
                ${ctaHtml}
            </div>
        `;
    }

    function showEmptyState(container, type, customData) {
        if (!container) return;
        container.innerHTML = renderEmptyState(type, customData);

        const ctaBtn = container.querySelector('.ltms-empty-action');
        if (ctaBtn) {
            ctaBtn.addEventListener('click', () => {
                const template = EMPTY_STATE_TEMPLATES[type];
                if (template && template.cta && typeof template.cta.action === 'function') {
                    template.cta.action();
                }
            });
        }
    }

    LTMS.UX.showEmptyState = showEmptyState;
    LTMS.UX.renderEmptyState = renderEmptyState;
    LTMS.UX.EMPTY_STATES = EMPTY_STATE_TEMPLATES;

    // ═══════════════════════════════════════════════════════════
    // 43. DATA EXPORT — Helpers de exportación CSV/JSON
    // ═══════════════════════════════════════════════════════════

    /**
     * Utilidades para exportar datos a CSV, JSON y Excel-compatible.
     */

    function exportToCSV(data, filename = 'export.csv', options = {}) {
        if (!data || !data.length) {
            toast('warning', 'Sin datos', 'No hay datos para exportar.');
            return;
        }

        const delimiter = options.delimiter || ',';
        const headers = options.headers || Object.keys(data[0]);
        const bom = '\uFEFF'; // BOM para Excel/UTF-8

        // Escape de valores (comillas, saltos de línea)
        const escapeValue = (val) => {
            if (val === null || val === undefined) return '';
            const str = String(val);
            if (str.includes(delimiter) || str.includes('"') || str.includes('\n')) {
                return '"' + str.replace(/"/g, '""') + '"';
            }
            return str;
        };

        const csv = [
            headers.join(delimiter),
            ...data.map((row) => headers.map((h) => escapeValue(row[h])).join(delimiter)),
        ].join('\n');

        downloadFile(bom + csv, filename, 'text/csv;charset=utf-8;');
        toast('success', 'Exportado', `${data.length} registro(s) exportado(s) a ${filename}`);
    }

    function exportToJSON(data, filename = 'export.json', options = {}) {
        if (!data) {
            toast('warning', 'Sin datos', 'No hay datos para exportar.');
            return;
        }

        const json = JSON.stringify(data, options.replacer || null, options.indent || 2);
        downloadFile(json, filename, 'application/json');
        toast('success', 'Exportado', `Datos exportados a ${filename}`);
    }

    function downloadFile(content, filename, mimeType) {
        const blob = new Blob([content], { type: mimeType });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        setTimeout(() => {
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }, 100);
    }

    // Hook para botones con data-export
    function initDataExport() {
        document.addEventListener('click', (e) => {
            const btn = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('[data-export-table]');
            if (!btn) return;
            e.preventDefault();

            const tableSelector = btn.dataset.exportTable;
            const table = document.querySelector(tableSelector);
            if (!table) {
                toast('error', 'Tabla no encontrada', `No se encontró: ${tableSelector}`);
                return;
            }

            const rows = [...table.querySelectorAll('tbody tr')];
            if (!rows.length) {
                toast('warning', 'Sin datos', 'La tabla no tiene filas para exportar.');
                return;
            }

            const headers = [...table.querySelectorAll('thead th')]
                .filter((th) => !th.querySelector('input'))
                .map((th) => th.textContent.trim());

            const data = rows.map((tr) => {
                const cells = [...tr.querySelectorAll('td')];
                const obj = {};
                headers.forEach((h, i) => {
                    const cell = cells[i];
                    obj[h] = cell ? cell.textContent.trim() : '';
                });
                return obj;
            });

            const filename = btn.dataset.exportFilename || `export_${Date.now()}.csv`;
            exportToCSV(data, filename);
        });
    }

    LTMS.UX.exportToCSV = exportToCSV;
    LTMS.UX.exportToJSON = exportToJSON;
    LTMS.UX.downloadFile = downloadFile;

    // ═══════════════════════════════════════════════════════════
    // 44. IMAGE LIGHTBOX — Visor de imágenes fullscreen
    // ═══════════════════════════════════════════════════════════

    /**
     * Lightbox para ver imágenes de productos a pantalla completa.
     * Soporta galería, navegación con teclado y zoom.
     */

    let lightboxState = {
        overlay: null,
        images: [],
        currentIndex: 0,
        cleanup: null,
    };

    function openLightbox(images, startIndex = 0) {
        if (!images || !images.length) return;
        if (lightboxState.overlay) closeLightbox();

        const imageArray = Array.isArray(images) ? images : [images];
        lightboxState.images = imageArray;
        lightboxState.currentIndex = startIndex;

        lightboxState.overlay = document.createElement('div');
        lightboxState.overlay.className = 'ltms-lightbox-overlay';
        lightboxState.overlay.innerHTML = `
            <button type="button" class="ltms-lightbox-close" aria-label="Cerrar">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
            ${imageArray.length > 1 ? `
                <button type="button" class="ltms-lightbox-prev" aria-label="Anterior">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                </button>
                <button type="button" class="ltms-lightbox-next" aria-label="Siguiente">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
                <div class="ltms-lightbox-counter">
                    <span class="ltms-lightbox-current">${startIndex + 1}</span> / ${imageArray.length}
                </div>
            ` : ''}
            <div class="ltms-lightbox-content">
                <img src="${escapeHtml(imageArray[startIndex])}" alt="" class="ltms-lightbox-image">
            </div>
        `;

        document.body.appendChild(lightboxState.overlay);
        document.body.style.overflow = 'hidden';

        requestAnimationFrame(() => lightboxState.overlay.classList.add('ltms-lightbox-open'));

        // Event handlers
        lightboxState.overlay.querySelector('.ltms-lightbox-close').addEventListener('click', closeLightbox);
        lightboxState.overlay.addEventListener('click', (e) => {
            if (e.target === lightboxState.overlay) closeLightbox();
        });

        if (imageArray.length > 1) {
            lightboxState.overlay.querySelector('.ltms-lightbox-prev').addEventListener('click', () => navigateLightbox(-1));
            lightboxState.overlay.querySelector('.ltms-lightbox-next').addEventListener('click', () => navigateLightbox(1));
        }

        // Keyboard
        const keyHandler = (e) => {
            if (e.key === 'Escape') closeLightbox();
            else if (e.key === 'ArrowLeft' && imageArray.length > 1) navigateLightbox(-1);
            else if (e.key === 'ArrowRight' && imageArray.length > 1) navigateLightbox(1);
        };
        document.addEventListener('keydown', keyHandler);
        lightboxState.cleanup = () => document.removeEventListener('keydown', keyHandler);

        lightboxState.cleanup = (function (originalCleanup) {
            return function () {
                originalCleanup();
                document.removeEventListener('keydown', keyHandler);
            };
        })(lightboxState.cleanup);
    }

    function navigateLightbox(direction) {
        const total = lightboxState.images.length;
        lightboxState.currentIndex = (lightboxState.currentIndex + direction + total) % total;

        const img = lightboxState.overlay.querySelector('.ltms-lightbox-image');
        const counter = lightboxState.overlay.querySelector('.ltms-lightbox-current');

        img.style.opacity = '0';
        setTimeout(() => {
            img.src = lightboxState.images[lightboxState.currentIndex];
            img.style.opacity = '1';
        }, 150);

        if (counter) counter.textContent = lightboxState.currentIndex + 1;
    }

    function closeLightbox() {
        if (!lightboxState.overlay) return;
        if (lightboxState.cleanup) lightboxState.cleanup();
        lightboxState.overlay.classList.remove('ltms-lightbox-open');
        document.body.style.overflow = '';
        setTimeout(() => {
            if (lightboxState.overlay && lightboxState.overlay.parentNode) {
                lightboxState.overlay.parentNode.removeChild(lightboxState.overlay);
            }
            lightboxState.overlay = null;
            lightboxState.images = [];
            lightboxState.currentIndex = 0;
            lightboxState.cleanup = null;
        }, 250);
    }

    function initLightbox() {
        // Click en imágenes con data-lightbox
        document.addEventListener('click', (e) => {
            const trigger = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('[data-lightbox]');
            if (!trigger) return;

            e.preventDefault();
            const src = trigger.getAttribute('href') || trigger.dataset.lightboxSrc || trigger.src;
            if (!src) return;

            // Buscar galería: images con mismo data-lightbox-group
            const group = trigger.dataset.lightboxGroup;
            let images = [src];
            if (group) {
                const groupEls = document.querySelectorAll(`[data-lightbox-group="${group}"]`);
                images = [...groupEls].map((el) => el.getAttribute('href') || el.dataset.lightboxSrc || el.src).filter(Boolean);
            }

            const startIndex = images.indexOf(src);
            openLightbox(images, startIndex >= 0 ? startIndex : 0);
        });
    }

    LTMS.UX.openLightbox = openLightbox;
    LTMS.UX.closeLightbox = closeLightbox;

    // ═══════════════════════════════════════════════════════════
    // 45. FORMATTERS — Fecha, hora, moneda, números
    // ═══════════════════════════════════════════════════════════

    /**
     * Formateadores reutilizables para fechas, moneda, números
     * con soporte multi-país (Colombia/México).
     */

    function formatCurrency(amount, currency = 'COP', locale = 'es-CO') {
        if (isNaN(parseFloat(amount))) return '$0';
        const locales = {
            COP: 'es-CO',
            MXN: 'es-MX',
            USD: 'en-US',
        };
        const loc = locales[currency] || locale;
        try {
            return new Intl.NumberFormat(loc, {
                style: 'currency',
                currency: currency,
                minimumFractionDigits: currency === 'COP' ? 0 : 2,
                maximumFractionDigits: currency === 'COP' ? 0 : 2,
            }).format(amount);
        } catch (e) {
            return '$' + Number(amount).toLocaleString(loc);
        }
    }

    function formatNumber(num, locale = 'es-CO', decimals = 0) {
        if (isNaN(parseFloat(num))) return '0';
        return new Intl.NumberFormat(locale, {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        }).format(num);
    }

    function formatPercent(num, locale = 'es-CO', decimals = 1) {
        if (isNaN(parseFloat(num))) return '0%';
        return new Intl.NumberFormat(locale, {
            style: 'percent',
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        }).format(num / 100);
    }

    function formatDate(date, locale = 'es-CO', options = {}) {
        const d = date instanceof Date ? date : new Date(date);
        if (isNaN(d.getTime())) return '';
        return d.toLocaleDateString(locale, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            ...options,
        });
    }

    function formatDateTime(date, locale = 'es-CO') {
        const d = date instanceof Date ? date : new Date(date);
        if (isNaN(d.getTime())) return '';
        return d.toLocaleString(locale, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    function formatRelativeTime(date, locale = 'es-CO') {
        const d = date instanceof Date ? date : new Date(date);
        if (isNaN(d.getTime())) return '';

        const now = new Date();
        const diff = (now - d) / 1000; // segundos
        const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });

        if (diff < 60) return rtf.format(-Math.floor(diff), 'second');
        if (diff < 3600) return rtf.format(-Math.floor(diff / 60), 'minute');
        if (diff < 86400) return rtf.format(-Math.floor(diff / 3600), 'hour');
        if (diff < 604800) return rtf.format(-Math.floor(diff / 86400), 'day');
        if (diff < 2629800) return rtf.format(-Math.floor(diff / 604800), 'week');
        if (diff < 31557600) return rtf.format(-Math.floor(diff / 2629800), 'month');
        return rtf.format(-Math.floor(diff / 31557600), 'year');
    }

    function timeAgo(date) {
        return formatRelativeTime(date);
    }

    // Exponer formatters
    LTMS.UX.formatCurrency = formatCurrency;
    LTMS.UX.formatNumber = formatNumber;
    LTMS.UX.formatPercent = formatPercent;
    LTMS.UX.formatDate = formatDate;
    LTMS.UX.formatDateTime = formatDateTime;
    LTMS.UX.formatRelativeTime = formatRelativeTime;
    LTMS.UX.timeAgo = timeAgo;

    // Auto-formatear elementos con data-format
    function initFormatters() {
        const formatElements = () => {
            document.querySelectorAll('[data-format-currency]').forEach((el) => {
                if (el.dataset.formatted) return;
                const amount = parseFloat(el.dataset.formatCurrency || el.textContent);
                const currency = el.dataset.formatCurrencyCode || 'COP';
                el.textContent = formatCurrency(amount, currency);
                el.dataset.formatted = 'true';
            });

            document.querySelectorAll('[data-format-date]').forEach((el) => {
                if (el.dataset.formatted) return;
                const dateStr = el.dataset.formatDate || el.textContent;
                el.textContent = formatDate(dateStr);
                el.dataset.formatted = 'true';
            });

            document.querySelectorAll('[data-format-relative]').forEach((el) => {
                const dateStr = el.dataset.formatRelative || el.textContent;
                el.textContent = formatRelativeTime(dateStr);
                // No marcar como formatted para que se actualice
            });
        };

        setTimeout(formatElements, 500);

        // Re-formatear relative time cada minuto
        setInterval(() => {
            document.querySelectorAll('[data-format-relative]:not([data-no-refresh])').forEach((el) => {
                const dateStr = el.dataset.formatRelative;
                if (dateStr) el.textContent = formatRelativeTime(dateStr);
            });
        }, 60000);

        // Re-formatear cuando se carga nueva vista
        if (typeof jQuery !== 'undefined') {
            jQuery(document).on('ltms:view:loaded', () => setTimeout(formatElements, 200));
        }
    }

    // ═══════════════════════════════════════════════════════════
    // 46. KEYBOARD SHORTCUTS HELP — Overlay de ayuda
    // ═══════════════════════════════════════════════════════════

    /**
     * Overlay que muestra todos los atajos de teclado disponibles.
     * Activación: ? (Shift+/) cuando NO se está escribiendo.
     */

    const KEYBOARD_SHORTCUTS = [
        { category: 'Navegación', keys: [
            { combo: ['Alt', '1-9'], desc: 'Ir a vista N del menú' },
            { combo: ['Alt', 'H'], desc: 'Ir a Inicio' },
            { combo: ['Alt', 'N'], desc: 'Toggle notificaciones' },
            { combo: ['Alt', '/'], desc: 'Focus en búsqueda' },
        ]},
        { category: 'Búsqueda y acciones', keys: [
            { combo: ['⌘/Ctrl', 'K'], desc: 'Abrir búsqueda rápida (command palette)' },
            { combo: ['?'], desc: 'Mostrar esta ayuda' },
            { combo: ['Esc'], desc: 'Cerrar modal/overlay' },
        ]},
        { category: 'Preferencias', keys: [
            { combo: ['⌘/Ctrl', ','], desc: 'Abrir preferencias' },
            { combo: ['⌘/Ctrl', 'D'], desc: 'Toggle tema claro/oscuro' },
        ]},
        { category: 'Vista', keys: [
            { combo: ['↑', '↓'], desc: 'Navegar en listas/command palette' },
            { combo: ['↵'], desc: 'Seleccionar/ejecutar' },
            { combo: ['→', '←'], desc: 'Navegar pasos del tour' },
        ]},
    ];

    function showKeyboardHelp() {
        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay';
        overlay.innerHTML = `
            <div class="ltms-modal ltms-keyboard-help-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-kb-help-title">
                <div class="ltms-modal-header">
                    <h3 class="ltms-modal-title" id="ltms-kb-help-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2" ry="2"/><path d="M6 8h.01M10 8h.01M14 8h.01M18 8h.01M8 12h.01M12 12h.01M16 12h.01M7 16h10"/></svg>
                        Atajos de teclado
                    </h3>
                    <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ltms-modal-body ltms-keyboard-help-body">
                    ${KEYBOARD_SHORTCUTS.map((section) => `
                        <div class="ltms-kb-section">
                            <h4 class="ltms-kb-section-title">${escapeHtml(section.category)}</h4>
                            <div class="ltms-kb-shortcuts">
                                ${section.keys.map((shortcut) => `
                                    <div class="ltms-kb-shortcut">
                                        <div class="ltms-kb-keys">
                                            ${shortcut.combo.map((key) => `<kbd>${escapeHtml(key)}</kbd>`).join('<span class="ltms-kb-plus">+</span>')}
                                        </div>
                                        <div class="ltms-kb-desc">${escapeHtml(shortcut.desc)}</div>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    `).join('')}
                </div>
                <div class="ltms-modal-footer">
                    <button type="button" class="ltms-btn ltms-btn-primary ltms-modal-close">Entendido</button>
                </div>
            </div>
        `;
        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('ltms-modal-open'));

        const cleanup = trapFocus(overlay.querySelector('.ltms-modal'));

        const close = () => {
            cleanup();
            overlay.classList.remove('ltms-modal-open');
            setTimeout(() => overlay.remove(), 250);
        };

        overlay.querySelectorAll('.ltms-modal-close').forEach((btn) => btn.addEventListener('click', close));
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    }

    function initKeyboardHelp() {
        document.addEventListener('keydown', (e) => {
            // Shift+/ = "?"
            if (e.shiftKey && e.key === '?' && !e.target.matches('input, textarea, select')) {
                e.preventDefault();
                showKeyboardHelp();
            }
        });
    }

    LTMS.UX.showKeyboardHelp = showKeyboardHelp;

    // ═══════════════════════════════════════════════════════════
    // 47. SYSTEM STATUS — Indicador de salud del sistema
    // ═══════════════════════════════════════════════════════════

    /**
     * Indicador visual del estado del sistema: conectividad,
     * últimas actualizaciones, modo offline.
     */

    let statusIndicator = null;

    function createStatusIndicator() {
        if (statusIndicator) return statusIndicator;

        statusIndicator = document.createElement('div');
        statusIndicator.className = 'ltms-status-indicator';
        statusIndicator.setAttribute('role', 'status');
        statusIndicator.setAttribute('aria-live', 'polite');
        statusIndicator.innerHTML = `
            <div class="ltms-status-dot ltms-status-online" aria-hidden="true"></div>
            <span class="ltms-status-text">En línea</span>
        `;
        document.body.appendChild(statusIndicator);
        return statusIndicator;
    }

    function updateSystemStatus(status, message) {
        const indicator = createStatusIndicator();
        const dot = indicator.querySelector('.ltms-status-dot');
        const text = indicator.querySelector('.ltms-status-text');

        dot.className = 'ltms-status-dot ltms-status-' + status;
        text.textContent = message || status;

        // Auto-ocultar después de 3s si es online
        if (status === 'online') {
            clearTimeout(indicator._hideTimer);
            indicator._hideTimer = setTimeout(() => {
                indicator.classList.remove('visible');
            }, 3000);
        } else {
            indicator.classList.add('visible');
        }
    }

    function initSystemStatus() {
        createStatusIndicator();

        // Estado de conexión
        window.addEventListener('online', () => {
            updateSystemStatus('online', 'Conexión restablecida');
        });

        window.addEventListener('offline', () => {
            updateSystemStatus('offline', 'Sin conexión');
        });

        // Estado inicial
        if (!navigator.onLine) {
            updateSystemStatus('offline', 'Sin conexión');
        }

        // Latencia de AJAX
        if (typeof jQuery !== 'undefined') {
            let slowRequestCount = 0;
            jQuery(document).ajaxError(() => {
                slowRequestCount++;
                if (slowRequestCount > 3) {
                    updateSystemStatus('warning', 'Problemas de conexión');
                }
            });

            jQuery(document).ajaxSuccess(() => {
                if (slowRequestCount > 0) slowRequestCount--;
                if (slowRequestCount === 0 && navigator.onLine) {
                    updateSystemStatus('online', 'En línea');
                }
            });
        }
    }

    // ═══════════════════════════════════════════════════════════
    // 48. ADVANCED TABLES — Sorting, filtering, pagination
    // ═══════════════════════════════════════════════════════════

    /**
     * Sistema de tablas avanzadas con:
     * - Sorting por columna (click en header)
     * - Filtering por texto
     * - Pagination inteligente
     * - Virtual scroll para datasets grandes
     * - Export integrado
     */

    function enhanceTable(table, options = {}) {
        if (!table || table.dataset.enhanced) return;
        table.dataset.enhanced = 'true';

        const config = {
            sortable: options.sortable !== false,
            filterable: options.filterable !== false,
            pageSize: options.pageSize || 10,
            pagination: options.pagination !== false,
            ...options,
        };

        const tbody = table.querySelector('tbody');
        const thead = table.querySelector('thead');
        if (!tbody || !thead) return;

        let allRows = [...tbody.querySelectorAll('tr')];
        let filteredRows = [...allRows];
        let currentPage = 1;
        let sortColumn = -1;
        let sortDirection = 'asc';

        // Wrapper para filtros y paginación
        const wrapper = document.createElement('div');
        wrapper.className = 'ltms-table-wrapper-enhanced';
        table.parentNode.insertBefore(wrapper, table);
        wrapper.appendChild(table);

        // Toolbar con búsqueda
        if (config.filterable) {
            const toolbar = document.createElement('div');
            toolbar.className = 'ltms-table-toolbar';
            toolbar.innerHTML = `
                <div class="ltms-table-search">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="search" class="ltms-table-search-input" placeholder="Buscar en tabla..." aria-label="Buscar en tabla">
                </div>
                <div class="ltms-table-info">
                    <span class="ltms-table-count"></span>
                </div>
            `;
            wrapper.insertBefore(toolbar, table);

            const searchInput = toolbar.querySelector('.ltms-table-search-input');
            searchInput.addEventListener('input', debounce((e) => {
                filterTable(e.target.value);
            }, 200));
        }

        // Sorting
        if (config.sortable) {
            const headers = thead.querySelectorAll('th');
            headers.forEach((th, index) => {
                if (th.dataset.noSort) return;

                th.classList.add('ltms-sortable');
                th.setAttribute('role', 'columnheader');
                th.setAttribute('aria-sort', 'none');

                th.addEventListener('click', () => {
                    if (sortColumn === index) {
                        sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
                    } else {
                        sortColumn = index;
                        sortDirection = 'asc';
                    }

                    headers.forEach((h) => {
                        h.classList.remove('ltms-sort-asc', 'ltms-sort-desc');
                        h.setAttribute('aria-sort', 'none');
                    });
                    th.classList.add('ltms-sort-' + sortDirection);
                    th.setAttribute('aria-sort', sortDirection === 'asc' ? 'ascending' : 'descending');

                    sortTable(index, sortDirection);
                });
            });
        }

        function filterTable(query) {
            const q = query.toLowerCase().trim();
            if (!q) {
                filteredRows = [...allRows];
            } else {
                filteredRows = allRows.filter((row) => {
                    return row.textContent.toLowerCase().includes(q);
                });
            }
            currentPage = 1;
            renderTable();
        }

        function sortTable(columnIndex, direction) {
            filteredRows.sort((a, b) => {
                const aCell = a.cells[columnIndex];
                const bCell = b.cells[columnIndex];
                if (!aCell || !bCell) return 0;

                const aVal = aCell.textContent.trim();
                const bVal = bCell.textContent.trim();

                // Intentar ordenar como número
                const aNum = parseFloat(aVal.replace(/[^0-9.-]/g, ''));
                const bNum = parseFloat(bVal.replace(/[^0-9.-]/g, ''));

                if (!isNaN(aNum) && !isNaN(bNum)) {
                    return direction === 'asc' ? aNum - bNum : bNum - aNum;
                }

                // Ordenar como texto
                if (direction === 'asc') return aVal.localeCompare(bVal);
                return bVal.localeCompare(aVal);
            });
            renderTable();
        }

        function renderTable() {
            const totalPages = Math.ceil(filteredRows.length / config.pageSize);
            if (currentPage > totalPages) currentPage = totalPages;
            if (currentPage < 1) currentPage = 1;

            const start = (currentPage - 1) * config.pageSize;
            const end = start + config.pageSize;
            const visibleRows = filteredRows.slice(start, end);

            tbody.innerHTML = '';
            visibleRows.forEach((row) => tbody.appendChild(row));

            // Update info
            const countEl = wrapper.querySelector('.ltms-table-count');
            if (countEl) {
                if (filteredRows.length === 0) {
                    countEl.textContent = 'Sin resultados';
                } else {
                    countEl.textContent = `${start + 1}-${Math.min(end, filteredRows.length)} de ${filteredRows.length}`;
                }
            }

            // Render pagination
            if (config.pagination) {
                renderPagination(totalPages);
            }

            // Empty state
            if (filteredRows.length === 0) {
                const emptyRow = document.createElement('tr');
                emptyRow.innerHTML = `<td colspan="${thead.querySelectorAll('th').length}" class="ltms-empty-cell">${renderEmptyState('search')}</td>`;
                tbody.appendChild(emptyRow);
            }
        }

        function renderPagination(totalPages) {
            let pagination = wrapper.querySelector('.ltms-table-pagination');
            if (!pagination) {
                pagination = document.createElement('div');
                pagination.className = 'ltms-table-pagination';
                wrapper.appendChild(pagination);
            }

            if (totalPages <= 1) {
                pagination.innerHTML = '';
                return;
            }

            const pages = [];
            const maxVisible = 5;
            let startPage = Math.max(1, currentPage - Math.floor(maxVisible / 2));
            let endPage = Math.min(totalPages, startPage + maxVisible - 1);
            if (endPage - startPage < maxVisible - 1) {
                startPage = Math.max(1, endPage - maxVisible + 1);
            }

            pagination.innerHTML = `
                <button type="button" class="ltms-page-btn" data-page="1" ${currentPage === 1 ? 'disabled' : ''} aria-label="Primera página">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg>
                </button>
                <button type="button" class="ltms-page-btn" data-page="${currentPage - 1}" ${currentPage === 1 ? 'disabled' : ''} aria-label="Página anterior">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                </button>
                ${startPage > 1 ? '<span class="ltms-page-ellipsis">...</span>' : ''}
                ${Array.from({ length: endPage - startPage + 1 }, (_, i) => startPage + i).map((p) => `
                    <button type="button" class="ltms-page-btn ${p === currentPage ? 'active' : ''}" data-page="${p}">${p}</button>
                `).join('')}
                ${endPage < totalPages ? '<span class="ltms-page-ellipsis">...</span>' : ''}
                <button type="button" class="ltms-page-btn" data-page="${currentPage + 1}" ${currentPage === totalPages ? 'disabled' : ''} aria-label="Página siguiente">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
                <button type="button" class="ltms-page-btn" data-page="${totalPages}" ${currentPage === totalPages ? 'disabled' : ''} aria-label="Última página">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 17 18 12 13 7"/><polyline points="6 17 11 12 6 7"/></svg>
                </button>
            `;

            pagination.querySelectorAll('.ltms-page-btn:not([disabled])').forEach((btn) => {
                btn.addEventListener('click', () => {
                    currentPage = parseInt(btn.dataset.page, 10);
                    renderTable();
                });
            });
        }

        // Initial render
        renderTable();

        return {
            refresh: () => {
                allRows = [...tbody.querySelectorAll('tr')];
                filteredRows = [...allRows];
                renderTable();
            },
            filter: (query) => filterTable(query),
            sort: (col, dir) => {
                sortColumn = col;
                sortDirection = dir;
                sortTable(col, dir);
            },
        };
    }

    function initAdvancedTables() {
        document.querySelectorAll('.ltms-advanced-table:not([data-enhanced])').forEach((table) => {
            const opts = {};
            if (table.dataset.pageSize) opts.pageSize = parseInt(table.dataset.pageSize, 10);
            if (table.dataset.noSort) opts.sortable = false;
            if (table.dataset.noFilter) opts.filterable = false;
            if (table.dataset.noPagination) opts.pagination = false;
            enhanceTable(table, opts);
        });
    }

    LTMS.UX.enhanceTable = enhanceTable;

    // ═══════════════════════════════════════════════════════════
    // 51. I18N — Sistema multi-idioma
    // ═══════════════════════════════════════════════════════════

    /**
     * Sistema de internacionalización que permite cambiar el idioma
     * de la interfaz dinámicamente via data-i18n attributes.
     */

    const I18N_STRINGS = {
        es: {
            'common.save': 'Guardar',
            'common.cancel': 'Cancelar',
            'common.delete': 'Eliminar',
            'common.edit': 'Editar',
            'common.search': 'Buscar',
            'common.loading': 'Cargando...',
            'common.error': 'Error',
            'common.success': 'Éxito',
            'common.confirm': 'Confirmar',
            'common.close': 'Cerrar',
            'common.refresh': 'Actualizar',
            'common.export': 'Exportar',
            'common.filter': 'Filtrar',
            'common.all': 'Todos',
            'common.none': 'Ninguno',
            'common.yes': 'Sí',
            'common.no': 'No',
            'orders.title': 'Pedidos',
            'orders.empty': 'No tienes pedidos aún',
            'orders.total': 'Total',
            'orders.status': 'Estado',
            'orders.date': 'Fecha',
            'orders.customer': 'Cliente',
            'products.title': 'Productos',
            'products.empty': 'Tu catálogo está vacío',
            'products.add': 'Agregar producto',
            'products.price': 'Precio',
            'products.stock': 'Stock',
            'wallet.title': 'Billetera',
            'wallet.balance': 'Balance disponible',
            'wallet.withdraw': 'Solicitar retiro',
            'wallet.deposit': 'Depositar',
            'wallet.transactions': 'Movimientos',
            'settings.title': 'Configuración',
            'settings.profile': 'Perfil',
            'settings.security': 'Seguridad',
            'settings.notifications': 'Notificaciones',
        },
        en: {
            'common.save': 'Save',
            'common.cancel': 'Cancel',
            'common.delete': 'Delete',
            'common.edit': 'Edit',
            'common.search': 'Search',
            'common.loading': 'Loading...',
            'common.error': 'Error',
            'common.success': 'Success',
            'common.confirm': 'Confirm',
            'common.close': 'Close',
            'common.refresh': 'Refresh',
            'common.export': 'Export',
            'common.filter': 'Filter',
            'common.all': 'All',
            'common.none': 'None',
            'common.yes': 'Yes',
            'common.no': 'No',
            'orders.title': 'Orders',
            'orders.empty': 'No orders yet',
            'orders.total': 'Total',
            'orders.status': 'Status',
            'orders.date': 'Date',
            'orders.customer': 'Customer',
            'products.title': 'Products',
            'products.empty': 'Your catalog is empty',
            'products.add': 'Add product',
            'products.price': 'Price',
            'products.stock': 'Stock',
            'wallet.title': 'Wallet',
            'wallet.balance': 'Available balance',
            'wallet.withdraw': 'Request withdrawal',
            'wallet.deposit': 'Deposit',
            'wallet.transactions': 'Transactions',
            'settings.title': 'Settings',
            'settings.profile': 'Profile',
            'settings.security': 'Security',
            'settings.notifications': 'Notifications',
        },
    };

    let currentLang = 'es';

    function getCurrentLang() {
        try {
            const saved = localStorage.getItem('ltms-lang');
            if (saved && I18N_STRINGS[saved]) return saved;
        } catch (e) {}
        // Detectar del navegador
        const browserLang = (navigator.language || 'es').split('-')[0];
        return I18N_STRINGS[browserLang] ? browserLang : 'es';
    }

    function setLanguage(lang) {
        if (!I18N_STRINGS[lang]) return;
        currentLang = lang;
        try { localStorage.setItem('ltms-lang', lang); } catch (e) {}
        applyTranslations();
    }

    function t(key, fallback) {
        const strings = I18N_STRINGS[currentLang] || I18N_STRINGS.es;
        return strings[key] || fallback || key;
    }

    function applyTranslations() {
        document.querySelectorAll('[data-i18n]').forEach((el) => {
            const key = el.dataset.i18n;
            const translated = t(key);
            if (translated && translated !== key) {
                el.textContent = translated;
            }
        });

        document.querySelectorAll('[data-i18n-placeholder]').forEach((el) => {
            const key = el.dataset.i18nPlaceholder;
            const translated = t(key);
            if (translated && translated !== key) {
                el.setAttribute('placeholder', translated);
            }
        });

        document.querySelectorAll('[data-i18n-title]').forEach((el) => {
            const key = el.dataset.i18nTitle;
            const translated = t(key);
            if (translated && translated !== key) {
                el.setAttribute('title', translated);
            }
        });

        document.documentElement.setAttribute('lang', currentLang);
    }

    function initI18n() {
        currentLang = getCurrentLang();
        applyTranslations();
    }

    LTMS.UX.i18n = {
        t,
        getLang: () => currentLang,
        setLang: setLanguage,
        available: Object.keys(I18N_STRINGS),
    };

    // ═══════════════════════════════════════════════════════════
    // 52. PRINT PREVIEW — Vista previa de impresión
    // ═══════════════════════════════════════════════════════════

    /**
     * Sistema de print preview que permite ver cómo se verá
     * el documento antes de imprimir, con opciones de personalización.
     */

    function openPrintPreview(options = {}) {
        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay ltms-print-preview-overlay';

        const title = options.title || 'Vista previa de impresión';
        const content = options.content || document.querySelector('.ltms-main-content').innerHTML;

        overlay.innerHTML = `
            <div class="ltms-modal ltms-print-preview-modal" role="dialog" aria-modal="true">
                <div class="ltms-modal-header">
                    <h3 class="ltms-modal-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                        ${escapeHtml(title)}
                    </h3>
                    <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ltms-modal-body ltms-print-preview-body">
                    <div class="ltms-print-preview-toolbar">
                        <button type="button" class="ltms-btn ltms-btn-primary ltms-btn-sm" id="ltms-print-now">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                            Imprimir
                        </button>
                        <label class="ltms-print-option">
                            <input type="checkbox" id="ltms-print-color" checked>
                            <span>Color</span>
                        </label>
                        <label class="ltms-print-option">
                            <input type="checkbox" id="ltms-print-header" checked>
                            <span>Encabezado</span>
                        </label>
                    </div>
                    <div class="ltms-print-preview-content" id="ltms-print-content">
                        ${content}
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('ltms-modal-open'));

        const cleanup = trapFocus(overlay.querySelector('.ltms-modal'));

        const close = () => {
            cleanup();
            overlay.classList.remove('ltms-modal-open');
            setTimeout(() => overlay.remove(), 250);
        };

        overlay.querySelectorAll('.ltms-modal-close').forEach((btn) => btn.addEventListener('click', close));
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

        overlay.querySelector('#ltms-print-now').addEventListener('click', () => {
            const printContent = overlay.querySelector('#ltms-print-content');
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>${escapeHtml(title)}</title>
                    <style>
                        body { font-family: -apple-system, sans-serif; padding: 20px; color: #000; }
                        @media print { body { padding: 0; } }
                    </style>
                </head>
                <body>${printContent.innerHTML}</body>
                </html>
            `);
            printWindow.document.close();
            printWindow.focus();
            setTimeout(() => {
                printWindow.print();
                printWindow.close();
            }, 250);
        });
    }

    LTMS.UX.openPrintPreview = openPrintPreview;

    // ═══════════════════════════════════════════════════════════
    // 55. VOICE SEARCH — Búsqueda por voz
    // ═══════════════════════════════════════════════════════════

    /**
     * Búsqueda por voz usando Web Speech API.
     * Añade un micrófono a los campos de búsqueda.
     */

    let voiceRecognition = null;
    let voiceActiveInput = null;

    function initVoiceSearch() {
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRecognition) return;

        // Añadir botón de micrófono a campos de búsqueda
        const addVoiceToSearch = (input) => {
            if (input.dataset.voiceAdded) return;
            input.dataset.voiceAdded = 'true';

            const wrapper = input.parentElement;
            if (!wrapper.classList.contains('ltms-search-box') && !wrapper.classList.contains('ltms-table-search')) return;

            const voiceBtn = document.createElement('button');
            voiceBtn.type = 'button';
            voiceBtn.className = 'ltms-voice-search-btn';
            voiceBtn.setAttribute('aria-label', 'Búsqueda por voz');
            voiceBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>';

            wrapper.appendChild(voiceBtn);

            voiceBtn.addEventListener('click', () => toggleVoiceSearch(input, voiceBtn, SpeechRecognition));
        };

        // Aplicar a inputs de búsqueda existentes
        document.querySelectorAll('input[type="search"], .ltms-search-input, .ltms-table-search-input').forEach(addVoiceToSearch);

        // Observer para nuevos inputs
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node.nodeType !== 1) return;
                    node.querySelectorAll && node.querySelectorAll('input[type="search"], .ltms-search-input').forEach(addVoiceToSearch);
                });
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    function toggleVoiceSearch(input, btn, SpeechRecognition) {
        if (voiceRecognition && voiceActiveInput === input) {
            voiceRecognition.stop();
            return;
        }

        if (voiceRecognition) {
            voiceRecognition.stop();
        }

        voiceRecognition = new SpeechRecognition();
        voiceRecognition.lang = document.documentElement.lang || 'es-ES';
        voiceRecognition.continuous = false;
        voiceRecognition.interimResults = true;

        voiceActiveInput = input;
        btn.classList.add('listening');

        voiceRecognition.onresult = (event) => {
            const transcript = Array.from(event.results)
                .map((r) => r[0].transcript)
                .join('');
            input.value = transcript;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        };

        voiceRecognition.onerror = (event) => {
            if (event.error === 'no-speech') {
                toast('info', 'No se detectó voz', 'Intenta hablar más cerca del micrófono.');
            } else if (event.error === 'not-allowed') {
                toast('warning', 'Permiso denegado', 'Activa el acceso al micrófono en tu navegador.');
            }
        };

        voiceRecognition.onend = () => {
            btn.classList.remove('listening');
            voiceRecognition = null;
            voiceActiveInput = null;
        };

        voiceRecognition.start();
        announce('Escuchando... habla ahora');
    }

    LTMS.UX.toggleVoiceSearch = toggleVoiceSearch;

    // ═══════════════════════════════════════════════════════════
    // 56. INFINITE SCROLL — Carga infinita para grids
    // ═══════════════════════════════════════════════════════════

    /**
     * Carga infinita para grids de productos y listas largas.
     * Usa IntersectionObserver para detectar cuando el usuario
     * llega al final y carga más contenido automáticamente.
     */

    function initInfiniteScroll() {
        const triggers = document.querySelectorAll('[data-infinite-scroll]');
        if (!triggers.length || !('IntersectionObserver' in window)) return;

        triggers.forEach((trigger) => {
            const config = {
                url: trigger.dataset.infiniteScroll,
                page: parseInt(trigger.dataset.page || '2', 10),
                maxPages: parseInt(trigger.dataset.maxPages || '10', 10),
                container: trigger.dataset.infiniteContainer,
                loading: false,
                ended: false,
            };

            if (!config.url || !config.container) return;

            const observer = new IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting && !config.loading && !config.ended) {
                        loadMore(config, trigger, observer);
                    }
                });
            }, { rootMargin: '200px' });

            observer.observe(trigger);
        });
    }

    function loadMore(config, trigger, observer) {
        config.loading = true;
        trigger.classList.add('loading');

        const url = config.url.replace(/\/$/, '') + '/page/' + config.page + '/';

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then((r) => {
                if (!r.ok) throw new Error('No more pages');
                return r.text();
            })
            .then((html) => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                const newItems = doc.querySelectorAll(config.container + ' > *');

                if (!newItems.length) {
                    config.ended = true;
                    trigger.classList.add('ended');
                    observer.unobserve(trigger);
                    return;
                }

                const container = document.querySelector(config.container);
                if (!container) return;

                newItems.forEach((item) => {
                    container.appendChild(item);
                });

                config.page++;
                config.loading = false;
                trigger.classList.remove('loading');

                if (config.page > config.maxPages) {
                    config.ended = true;
                    trigger.classList.add('ended');
                    observer.unobserve(trigger);
                }

                announce(`Cargados ${newItems.length} elementos más`);
            })
            .catch(() => {
                config.ended = true;
                trigger.classList.add('ended');
                observer.unobserve(trigger);
            });
    }

    // ═══════════════════════════════════════════════════════════
    // 61. ORDER TRACKING — Timeline visual de estado de pedido
    // ═══════════════════════════════════════════════════════════

    /**
     * Timeline visual que muestra el progreso de un pedido:
     * Recibido → Procesando → Enviado → Entregado
     */

    const ORDER_STEPS = [
        { key: 'pending', label: 'Pedido recibido', icon: '📝', desc: 'Hemos recibido tu pedido' },
        { key: 'processing', label: 'Procesando', icon: '⚙️', desc: 'Estamos preparando tu pedido' },
        { key: 'shipped', label: 'Enviado', icon: '🚚', desc: 'Tu pedido está en camino' },
        { key: 'delivered', label: 'Entregado', icon: '✅', desc: 'Pedido entregado correctamente' },
    ];

    function renderOrderTimeline(currentStatus, orderData = {}) {
        const statusOrder = ['pending', 'processing', 'shipped', 'delivered'];
        const currentIndex = statusOrder.indexOf(currentStatus);
        const isCancelled = currentStatus === 'cancelled';

        if (isCancelled) {
            return `
                <div class="ltms-order-timeline ltms-order-cancelled">
                    <div class="ltms-order-timeline-item cancelled">
                        <div class="ltms-order-timeline-icon ltms-order-icon-cancelled">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                        </div>
                        <div class="ltms-order-timeline-content">
                            <div class="ltms-order-timeline-label">Pedido cancelado</div>
                            <div class="ltms-order-timeline-desc">${escapeHtml(orderData.cancel_reason || 'El pedido fue cancelado')}</div>
                        </div>
                    </div>
                </div>
            `;
        }

        return `
            <div class="ltms-order-timeline">
                ${ORDER_STEPS.map((step, i) => {
                    const isCompleted = i < currentIndex;
                    const isActive = i === currentIndex;
                    const isPending = i > currentIndex;
                    return `
                        <div class="ltms-order-timeline-item ${isCompleted ? 'completed' : ''} ${isActive ? 'active' : ''} ${isPending ? 'pending' : ''}">
                            <div class="ltms-order-timeline-icon ${isCompleted ? 'completed' : ''} ${isActive ? 'active' : ''}">
                                ${isCompleted
                                    ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>'
                                    : escapeHtml(step.icon)}
                            </div>
                            <div class="ltms-order-timeline-content">
                                <div class="ltms-order-timeline-label">${escapeHtml(step.label)}</div>
                                <div class="ltms-order-timeline-desc">${escapeHtml(step.desc)}</div>
                                ${isActive && orderData.estimated_date ? `<div class="ltms-order-timeline-date">Estimado: ${escapeHtml(orderData.estimated_date)}</div>` : ''}
                                ${isCompleted && orderData[step.key + '_date'] ? `<div class="ltms-order-timeline-date">${escapeHtml(orderData[step.key + '_date'])}</div>` : ''}
                            </div>
                        </div>
                    `;
                }).join('')}
            </div>
        `;
    }

    function initOrderTracking() {
        // Auto-render en elementos con data-order-tracking
        document.querySelectorAll('[data-order-tracking]').forEach((el) => {
            const status = el.dataset.orderTracking;
            const data = {};
            try {
                Object.assign(data, JSON.parse(el.dataset.orderData || '{}'));
            } catch (e) {}
            el.innerHTML = renderOrderTimeline(status, data);
        });
    }

    LTMS.UX.renderOrderTimeline = renderOrderTimeline;

    // ═══════════════════════════════════════════════════════════
    // 63. QR CODE — Generador de códigos QR
    // ═══════════════════════════════════════════════════════════

    /**
     * Genera códigos QR usando la API de Google Charts
     * (sin librerías externas). Útil para compartir productos,
     * links de pago, etc.
     */

    function generateQR(text, options = {}) {
        const size = options.size || 200;
        const color = options.color || '0F4C75';
        const bg = options.bg || 'ffffff';

        const qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=${size}x${size}&data=${encodeURIComponent(text)}&color=${color}&bgcolor=${bg}&margin=10`;

        const container = document.createElement('div');
        container.className = 'ltms-qr-container';
        container.innerHTML = `
            <img src="${qrUrl}" alt="Código QR" class="ltms-qr-image" width="${size}" height="${size}">
            ${options.downloadable !== false ? `
                <a href="${qrUrl}" download="qr-code.png" class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-qr-download">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Descargar QR
                </a>
            ` : ''}
        `;

        return container;
    }

    function openQRModal(text, options = {}) {
        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay';

        const qr = generateQR(text, options);

        overlay.innerHTML = `
            <div class="ltms-modal ltms-qr-modal" role="dialog" aria-modal="true">
                <div class="ltms-modal-header">
                    <h3 class="ltms-modal-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><line x1="14" y1="14" x2="14" y2="21"/><line x1="18" y1="14" x2="21" y2="14"/><line x1="17" y1="17" x2="21" y2="17"/><line x1="14" y1="21" x2="21" y2="21"/></svg>
                        Código QR
                    </h3>
                    <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ltms-modal-body ltms-qr-modal-body"></div>
                <div class="ltms-modal-footer">
                    <button type="button" class="ltms-btn ltms-btn-outline ltms-modal-close">Cerrar</button>
                </div>
            </div>
        `;

        overlay.querySelector('.ltms-qr-modal-body').appendChild(qr);

        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('ltms-modal-open'));

        const cleanup = trapFocus(overlay.querySelector('.ltms-modal'));

        const close = () => {
            cleanup();
            overlay.classList.remove('ltms-modal-open');
            setTimeout(() => overlay.remove(), 250);
        };

        overlay.querySelectorAll('.ltms-modal-close').forEach((btn) => btn.addEventListener('click', close));
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    }

    function initQRCode() {
        document.addEventListener('click', (e) => {
            const trigger = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('[data-qr-generate]');
            if (!trigger) return;
            e.preventDefault();
            const text = trigger.dataset.qrGenerate || window.location.href;
            openQRModal(text);
        });
    }

    LTMS.UX.generateQR = generateQR;
    LTMS.UX.openQRModal = openQRModal;

    // ═══════════════════════════════════════════════════════════
    // 65. INTERACTIVE STAR RATING — Sistema de calificación
    // ═══════════════════════════════════════════════════════════

    /**
     * Sistema de estrellas interactivo para que los usuarios
     * califiquen productos. Soporta hover, click y teclado.
     */

    function createStarRating(options = {}) {
        const max = options.max || 5;
        const initial = options.initial || 0;
        const readonly = options.readonly || false;
        const onChange = options.onChange;

        const container = document.createElement('div');
        container.className = 'ltms-star-rating';
        container.setAttribute('role', 'slider');
        container.setAttribute('aria-label', 'Calificación');
        container.setAttribute('aria-valuemin', '0');
        container.setAttribute('aria-valuemax', String(max));
        container.setAttribute('aria-valuenow', String(initial));

        let currentRating = initial;
        let hoverRating = 0;

        const stars = [];
        for (let i = 1; i <= max; i++) {
            const star = document.createElement('button');
            star.type = 'button';
            star.className = 'ltms-star';
            star.setAttribute('aria-label', `${i} estrella${i > 1 ? 's' : ''}`);
            star.dataset.value = i;
            star.innerHTML = `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>`;
            stars.push(star);
            container.appendChild(star);

            if (!readonly) {
                star.addEventListener('mouseenter', () => {
                    hoverRating = i;
                    updateStars();
                });

                star.addEventListener('click', () => {
                    currentRating = i;
                    hoverRating = 0;
                    container.setAttribute('aria-valuenow', String(i));
                    updateStars();
                    if (onChange) onChange(i);
                    announce(`${i} de ${max} estrellas`);
                });

                star.addEventListener('keydown', (e) => {
                    if (e.key === 'ArrowRight' || e.key === 'ArrowUp') {
                        e.preventDefault();
                        currentRating = Math.min(currentRating + 1, max);
                        updateStars();
                        if (onChange) onChange(currentRating);
                    } else if (e.key === 'ArrowLeft' || e.key === 'ArrowDown') {
                        e.preventDefault();
                        currentRating = Math.max(currentRating - 1, 0);
                        updateStars();
                        if (onChange) onChange(currentRating);
                    }
                });
            }
        }

        if (!readonly) {
            container.addEventListener('mouseleave', () => {
                hoverRating = 0;
                updateStars();
            });
        }

        function updateStars() {
            const display = hoverRating || currentRating;
            stars.forEach((star, i) => {
                const svg = star.querySelector('svg');
                if (i < display) {
                    star.classList.add('filled');
                    svg.setAttribute('fill', 'currentColor');
                } else {
                    star.classList.remove('filled');
                    svg.setAttribute('fill', 'none');
                }
            });
        }

        updateStars();

        container.getRating = () => currentRating;
        container.setRating = (val) => {
            currentRating = Math.max(0, Math.min(val, max));
            updateStars();
        };

        return container;
    }

    function initStarRatings() {
        document.querySelectorAll('[data-star-rating]').forEach((el) => {
            if (el.dataset.ratingInit) return;
            el.dataset.ratingInit = 'true';

            const initial = parseInt(el.dataset.starRating || '0', 10);
            const readonly = el.dataset.readonly === 'true';
            const max = parseInt(el.dataset.maxStars || '5', 10);

            const rating = createStarRating({ initial, readonly, max, onChange: (val) => {
                const input = el.parentElement.querySelector(`input[name="${el.dataset.ratingInput || 'rating'}"]`);
                if (input) input.value = val;
            } });

            el.innerHTML = '';
            el.appendChild(rating);
        });
    }

    LTMS.UX.createStarRating = createStarRating;

    // ═══════════════════════════════════════════════════════════
    // 68. FAQ ACCORDION — Componente acordeón
    // ═══════════════════════════════════════════════════════════

    /**
     * Acordeón para FAQs y secciones colapsables.
     * Accesible (ARIA) con animación smooth.
     */

    function initAccordion() {
        document.addEventListener('click', (e) => {
            const trigger = (!e.target || typeof e.target.closest !== "function") ? null : e.target.closest('[data-accordion-trigger]');
            if (!trigger) return;

            const item = trigger.closest('.ltms-accordion-item');
            if (!item) return;

            const content = item.querySelector('[data-accordion-content]');
            if (!content) return;

            const isOpen = item.classList.contains('open');
            const group = item.closest('[data-accordion-group]');

            // Si es grupo exclusivo (solo uno abierto), cerrar otros
            if (group && group.dataset.accordionExclusive === 'true' && !isOpen) {
                group.querySelectorAll('.ltms-accordion-item.open').forEach((other) => {
                    if (other !== item) {
                        other.classList.remove('open');
                        const otherContent = other.querySelector('[data-accordion-content]');
                        const otherTrigger = other.querySelector('[data-accordion-trigger]');
                        if (otherContent) {
                            otherContent.style.maxHeight = '0';
                        }
                        if (otherTrigger) {
                            otherTrigger.setAttribute('aria-expanded', 'false');
                        }
                    }
                });
            }

            if (isOpen) {
                item.classList.remove('open');
                content.style.maxHeight = '0';
                trigger.setAttribute('aria-expanded', 'false');
            } else {
                item.classList.add('open');
                content.style.maxHeight = content.scrollHeight + 'px';
                trigger.setAttribute('aria-expanded', 'true');

                // Recalcular altura cuando se carga contenido dinámico
                setTimeout(() => {
                    if (item.classList.contains('open')) {
                        content.style.maxHeight = 'none';
                    }
                }, 400);
            }
        });

        // Inicializar items abiertos por defecto
        document.querySelectorAll('.ltms-accordion-item.open [data-accordion-content]').forEach((content) => {
            content.style.maxHeight = 'none';
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 69. STOCK INDICATORS — Indicadores visuales de inventario
    // ═══════════════════════════════════════════════════════════

    /**
     * Muestra el nivel de stock visualmente: barra de progreso,
     * colores semánticos y mensajes contextuales.
     */

    function renderStockIndicator(stock, options = {}) {
        const threshold = options.threshold || 10;
        const maxStock = options.maxStock || 100;

        let level, color, message, percentage;

        if (stock === 0) {
            level = 'out';
            color = '#DC2626';
            message = 'Agotado';
            percentage = 0;
        } else if (stock <= threshold) {
            level = 'low';
            color = '#F59E0B';
            message = `¡Solo ${stock} disponible${stock > 1 ? 's' : ''}!`;
            percentage = Math.max((stock / maxStock) * 100, 15);
        } else if (stock <= threshold * 3) {
            level = 'medium';
            color = '#3282B8';
            message = `${stock} disponibles`;
            percentage = (stock / maxStock) * 100;
        } else {
            level = 'high';
            color = '#16A34A';
            message = 'En stock';
            percentage = 100;
        }

        return `
            <div class="ltms-stock-indicator ltms-stock-${level}">
                <div class="ltms-stock-bar" style="width:${percentage}%;background:${color};"></div>
                <span class="ltms-stock-message" style="color:${color};">
                    ${level !== 'out' ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>' : ''}
                    ${escapeHtml(message)}
                </span>
            </div>
        `;
    }

    function initStockIndicators() {
        document.querySelectorAll('[data-stock-level]').forEach((el) => {
            if (el.dataset.stockInit) return;
            el.dataset.stockInit = 'true';

            const stock = parseInt(el.dataset.stockLevel, 10);
            const threshold = parseInt(el.dataset.stockThreshold || '10', 10);
            const maxStock = parseInt(el.dataset.stockMax || '100', 10);

            el.innerHTML = renderStockIndicator(stock, { threshold, maxStock });
        });
    }

    LTMS.UX.renderStockIndicator = renderStockIndicator;

    // ═══════════════════════════════════════════════════════════
    // 70. COUNTDOWN TIMER — Temporizador de ofertas
    // ═══════════════════════════════════════════════════════════

    /**
     * Cuenta regresiva para ofertas, promociones y eventos.
     * Muestra días, horas, minutos y segundos en tiempo real.
     */

    function createCountdown(container, targetDate, options = {}) {
        const target = typeof targetDate === 'string' ? new Date(targetDate) : targetDate;
        if (isNaN(target.getTime())) return null;

        const showDays = options.showDays !== false;
        const showLabels = options.labels !== false;
        const onExpire = options.onExpire;
        const prefix = options.prefix || '';
        const expiredMessage = options.expiredMessage || '¡Oferta terminada!';

        let interval = null;

        function update() {
            const now = Date.now();
            const diff = target.getTime() - now;

            if (diff <= 0) {
                container.innerHTML = `<div class="ltms-countdown-expired">${escapeHtml(expiredMessage)}</div>`;
                container.classList.add('expired');
                if (interval) clearInterval(interval);
                if (onExpire) onExpire();
                return;
            }

            const days = Math.floor(diff / 86400000);
            const hours = Math.floor((diff % 86400000) / 3600000);
            const minutes = Math.floor((diff % 3600000) / 60000);
            const seconds = Math.floor((diff % 60000) / 1000);

            const pad = (n) => String(n).padStart(2, '0');

            const units = [];
            if (showDays && days > 0) {
                units.push({ value: pad(days), label: days === 1 ? 'día' : 'días' });
            }
            units.push({ value: pad(hours), label: 'hrs' });
            units.push({ value: pad(minutes), label: 'min' });
            units.push({ value: pad(seconds), label: 'seg' });

            const urgencyClass = diff < 3600000 ? 'ltms-countdown-urgent' : diff < 86400000 ? 'ltms-countdown-soon' : '';

            container.className = 'ltms-countdown ' + urgencyClass;
            container.innerHTML = `
                ${prefix ? `<span class="ltms-countdown-prefix">${escapeHtml(prefix)}</span>` : ''}
                <div class="ltms-countdown-units">
                    ${units.map((u) => `
                        <div class="ltms-countdown-unit">
                            <span class="ltms-countdown-value">${u.value}</span>
                            ${showLabels ? `<span class="ltms-countdown-label">${u.label}</span>` : ''}
                        </div>
                    `).join('<span class="ltms-countdown-sep">:</span>')}
                </div>
            `;
        }

        update();
        interval = setInterval(update, 1000);

        return {
            stop: () => { if (interval) clearInterval(interval); },
            getTarget: () => target,
            getRemaining: () => Math.max(0, target.getTime() - Date.now()),
        };
    }

    function initCountdowns() {
        document.querySelectorAll('[data-countdown]').forEach((el) => {
            if (el.dataset.cdInit) return;
            el.dataset.cdInit = 'true';

            const target = el.dataset.countdown;
            const opts = {};
            if (el.dataset.countdownPrefix) opts.prefix = el.dataset.countdownPrefix;
            if (el.dataset.countdownExpired) opts.expiredMessage = el.dataset.countdownExpired;
            if (el.dataset.countdownNoDays) opts.showDays = false;

            createCountdown(el, target, opts);
        });
    }

    LTMS.UX.createCountdown = createCountdown;

    // ═══════════════════════════════════════════════════════════
    // 74. TOGGLE SWITCHES — Interruptores on/off
    // ═══════════════════════════════════════════════════════════

    /**
     * Toggle switches estilizados para reemplazar checkboxes.
     * Accesibles con keyboard y ARIA.
     */

    function initToggleSwitches() {
        document.querySelectorAll('input[type="checkbox"].ltms-toggle-switch').forEach((input) => {
            if (input.dataset.toggleInit) return;
            input.dataset.toggleInit = 'true';

            const wrapper = document.createElement('label');
            wrapper.className = 'ltms-toggle-switch-wrap';

            input.parentNode.insertBefore(wrapper, input);
            wrapper.appendChild(input);

            const slider = document.createElement('span');
            slider.className = 'ltms-toggle-switch-slider';
            wrapper.appendChild(slider);

            // Sincronizar estado
            if (input.checked) wrapper.classList.add('checked');
            input.classList.add('ltms-toggle-switch-hidden');

            input.addEventListener('change', () => {
                wrapper.classList.toggle('checked', input.checked);
                input.dispatchEvent(new CustomEvent('ltms:toggle-change', {
                    detail: { checked: input.checked },
                    bubbles: true,
                }));
            });

            // Labels opcionales
            if (input.dataset.onLabel || input.dataset.offLabel) {
                const labels = document.createElement('span');
                labels.className = 'ltms-toggle-switch-labels';
                labels.innerHTML = `
                    <span class="ltms-toggle-off-label">${escapeHtml(input.dataset.offLabel || 'Off')}</span>
                    <span class="ltms-toggle-on-label">${escapeHtml(input.dataset.onLabel || 'On')}</span>
                `;
                wrapper.appendChild(labels);
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 75. READING PROGRESS — Barra de progreso de lectura
    // ═══════════════════════════════════════════════════════════

    /**
     * Barra de progreso en el top que indica cuánto se ha
     * scrolleado de la página actual.
     */

    function initReadingProgress() {
        const triggers = document.querySelectorAll('[data-reading-progress]');
        if (!triggers.length) return;

        let bar = document.querySelector('.ltms-reading-progress-bar');
        if (!bar) {
            bar = document.createElement('div');
            bar.className = 'ltms-reading-progress-bar';
            bar.innerHTML = '<div class="ltms-reading-progress-fill"></div>';
            document.body.appendChild(bar);
        }

        const fill = bar.querySelector('.ltms-reading-progress-fill');

        const update = throttle(() => {
            const scrollTop = window.scrollY;
            const docHeight = document.documentElement.scrollHeight - window.innerHeight;
            const percentage = docHeight > 0 ? (scrollTop / docHeight) * 100 : 0;
            fill.style.width = Math.min(percentage, 100) + '%';
        }, 10);

        window.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update);
        update();
    }

    // ═══════════════════════════════════════════════════════════
    // 93. LIVE CHAT — Widget de chat flotante
    // ═══════════════════════════════════════════════════════════

    /**
     * Widget de chat de soporte flotante con:
     * - Botón flotante animado
     * - Panel expandible
     * - Mensajes pre-escritos (FAQ)
     * - Integración con WhatsApp o sistema de chat externo
     */

    let chatWidget = null;
    let chatPanel = null;

    function initLiveChat() {
        if (document.querySelector('.ltms-live-chat')) return;
        if (document.querySelector('.ltms-auth-container')) return;

        const config = {
            whatsapp: document.querySelector('[data-live-chat]')?.dataset.liveChat || '',
            title: 'Soporte Lo Tengo',
            subtitle: 'Típicamente responde en 5 min',
            welcome: '¡Hola! 👋 ¿Cómo podemos ayudarte hoy?',
        };

        // Floating button
        chatWidget = document.createElement('button');
        chatWidget.type = 'button';
        chatWidget.className = 'ltms-live-chat';
        chatWidget.setAttribute('aria-label', 'Abrir chat de soporte');
        chatWidget.innerHTML = `
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
            <span class="ltms-live-chat-badge"></span>
        `;
        document.body.appendChild(chatWidget);

        // Chat panel
        chatPanel = document.createElement('div');
        chatPanel.className = 'ltms-live-chat-panel';
        chatPanel.setAttribute('role', 'dialog');
        chatPanel.setAttribute('aria-label', 'Chat de soporte');
        chatPanel.innerHTML = `
            <div class="ltms-live-chat-header">
                <div class="ltms-live-chat-header-info">
                    <div class="ltms-live-chat-avatar">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </div>
                    <div>
                        <div class="ltms-live-chat-title">${escapeHtml(config.title)}</div>
                        <div class="ltms-live-chat-status">
                            <span class="ltms-live-chat-status-dot"></span>
                            ${escapeHtml(config.subtitle)}
                        </div>
                    </div>
                </div>
                <button type="button" class="ltms-live-chat-close" aria-label="Cerrar chat">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="ltms-live-chat-body" id="ltms-chat-body">
                <div class="ltms-live-chat-message ltms-live-chat-message-bot">
                    <div class="ltms-live-chat-message-avatar">🤖</div>
                    <div class="ltms-live-chat-message-bubble">${escapeHtml(config.welcome)}</div>
                </div>
                <div class="ltms-live-chat-quick-replies">
                    <button type="button" class="ltms-live-chat-quick-reply" data-reply="¿Cómo rastreo mi pedido?">📦 ¿Cómo rastreo mi pedido?</button>
                    <button type="button" class="ltms-live-chat-quick-reply" data-reply="¿Cuáles son los métodos de pago?">💳 Métodos de pago</button>
                    <button type="button" class="ltms-live-chat-quick-reply" data-reply="¿Puedo devolver un producto?">↩️ Devoluciones</button>
                    <button type="button" class="ltms-live-chat-quick-reply" data-reply="Hablar con un asesor humano">👤 Hablar con asesor</button>
                </div>
            </div>
            <div class="ltms-live-chat-footer">
                <input type="text" class="ltms-live-chat-input" placeholder="Escribe tu mensaje..." aria-label="Mensaje">
                <button type="button" class="ltms-live-chat-send" aria-label="Enviar">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                </button>
            </div>
        `;
        document.body.appendChild(chatPanel);

        // Toggle
        chatWidget.addEventListener('click', () => {
            const isOpen = chatPanel.classList.contains('open');
            if (isOpen) {
                chatPanel.classList.remove('open');
                chatWidget.classList.remove('active');
            } else {
                chatPanel.classList.add('open');
                chatWidget.classList.add('active');
                chatWidget.querySelector('.ltms-live-chat-badge').style.display = 'none';
                setTimeout(() => chatPanel.querySelector('.ltms-live-chat-input').focus(), 300);
            }
        });

        chatPanel.querySelector('.ltms-live-chat-close').addEventListener('click', () => {
            chatPanel.classList.remove('open');
            chatWidget.classList.remove('active');
        });

        // Quick replies
        const quickReplies = {
            '¿Cómo rastreo mi pedido?': 'Puedes rastrear tu pedido en la sección "Mis Pedidos" del panel, o haciendo clic en el enlace de seguimiento que te enviamos por correo. 📦',
            '¿Cuáles son los métodos de pago?': 'Aceptamos tarjetas de crédito/débito (Visa, Mastercard, Amex), PSE, Nequi, y transferencia bancaria. 💳',
            '¿Puedo devolver un producto?': '¡Sí! Tienes 15 días para devolver productos en su estado original. Ve a "Mis Pedidos" y selecciona "Solicitar devolución". ↩️',
            'Hablar con un asesor humano': 'Te conectaremos con un asesor. Si prefieres, puedes escribirnos por WhatsApp al número que aparece en la página. 👤',
        };

        chatPanel.querySelectorAll('.ltms-live-chat-quick-reply').forEach((btn) => {
            btn.addEventListener('click', () => {
                const reply = btn.dataset.reply;
                addChatMessage('user', reply);
                btn.remove();

                // Bot response
                setTimeout(() => {
                    const response = quickReplies[reply] || 'Gracias por tu mensaje. Un asesor te responderá pronto.';
                    addChatMessage('bot', response);

                    if (reply === 'Hablar con un asesor humano' && config.whatsapp) {
                        const waLink = document.createElement('a');
                        waLink.href = `https://wa.me/${config.whatsapp}`;
                        waLink.target = '_blank';
                        waLink.className = 'ltms-btn ltms-btn-primary ltms-btn-sm ltms-btn-full';
                        waLink.style.marginTop = '8px';
                        waLink.textContent = 'Abrir WhatsApp';
                        chatPanel.querySelector('.ltms-live-chat-body').appendChild(waLink);
                    }
                }, 800);
            });
        });

        // Send message
        const input = chatPanel.querySelector('.ltms-live-chat-input');
        const sendBtn = chatPanel.querySelector('.ltms-live-chat-send');

        function sendMessage() {
            const msg = input.value.trim();
            if (!msg) return;
            addChatMessage('user', msg);
            input.value = '';

            // Bot response
            setTimeout(() => {
                addChatMessage('bot', 'Gracias por tu mensaje. Un asesor te responderá pronto. 🙏');
            }, 1000);
        }

        sendBtn.addEventListener('click', sendMessage);
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); sendMessage(); }
        });
    }

    function addChatMessage(type, message) {
        const body = document.querySelector('#ltms-chat-body');
        if (!body) return;

        const msg = document.createElement('div');
        msg.className = `ltms-live-chat-message ltms-live-chat-message-${type}`;
        msg.innerHTML = `
            ${type === 'bot' ? '<div class="ltms-live-chat-message-avatar">🤖</div>' : ''}
            <div class="ltms-live-chat-message-bubble">${escapeHtml(message)}</div>
            ${type === 'user' ? '<div class="ltms-live-chat-message-avatar ltms-live-chat-message-avatar-user">👤</div>' : ''}
        `;
        body.appendChild(msg);
        body.scrollTop = body.scrollHeight;
    }

    // ═══════════════════════════════════════════════════════════
    // 98. TOAST QUEUE — Gestión de cola de toasts
    // ═══════════════════════════════════════════════════════════

    /**
     * Sistema de cola para toasts que evita saturar la pantalla
     * cuando se disparan múltiples notificaciones simultáneas.
     * Máximo 3 visibles, el resto se encola.
     */

    const toastQueue = [];
    const activeToasts = new Set();
    const MAX_VISIBLE_TOASTS = 3;

    const originalToast = toast;

    function queuedToast(type, title, message, opts) {
        const toastItem = { type, title, message, opts };
        toastQueue.push(toastItem);
        processToastQueue();
    }

    function processToastQueue() {
        while (activeToasts.size < MAX_VISIBLE_TOASTS && toastQueue.length > 0) {
            const item = toastQueue.shift();
            const el = originalToast(item.type, item.title, item.message, item.opts);
            if (el) {
                activeToasts.add(el);
                // Remove from active when dismissed
                const observer = new MutationObserver(() => {
                    if (!el.parentNode) {
                        activeToasts.delete(el);
                        observer.disconnect();
                        processToastQueue();
                    }
                });
                observer.observe(el.parentNode || document.body, { childList: true });
            }
        }
    }

    // Replace the default toast with queued version
    LTMS.UX.toast = queuedToast;
    LTMS.UX.toastSuccess = (t, m, o) => queuedToast('success', t, m, o);
    LTMS.UX.toastError = (t, m, o) => queuedToast('error', t, m, o);
    LTMS.UX.toastWarning = (t, m, o) => queuedToast('warning', t, m, o);
    LTMS.UX.toastInfo = (t, m, o) => queuedToast('info', t, m, o);

    // ═══════════════════════════════════════════════════════════
    // 99. PUSH NOTIFICATIONS — Suscripción a notificaciones push
    // ═══════════════════════════════════════════════════════════

    /**
     * Sistema de suscripción a push notifications usando
     * la Push API del navegador. Requiere service worker.
     */

    async function initPushNotifications() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;

        // No en login/admin
        if (document.querySelector('.ltms-auth-container')) return;
        if (document.body.classList.contains('wp-admin')) return;

        // No preguntar si ya están habilitadas/deshabilitadas
        try {
            const pref = localStorage.getItem('ltms-push-pref');
            if (pref === 'disabled' || pref === 'enabled') return;
        } catch (e) { return; }

        // Esperar 30s antes de preguntar
        setTimeout(async () => {
            // Solo si el usuario está logueado
            if (!document.body.classList.contains('logged-in')) return;

            const registration = await navigator.serviceWorker.getRegistration().catch(() => null);
            if (!registration) return;

            // Mostrar prompt personalizado
            const existingSub = await registration.pushManager.getSubscription();
            if (existingSub) {
                try { localStorage.setItem('ltms-push-pref', 'enabled'); } catch (e) {}
                return;
            }

            showPushPrompt(registration);
        }, 30000);
    }

    function showPushPrompt(swRegistration) {
        const banner = document.createElement('div');
        banner.className = 'ltms-push-prompt';
        banner.innerHTML = `
            <div class="ltms-push-prompt-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
            </div>
            <div class="ltms-push-prompt-content">
                <strong>🔔 Recibe notificaciones</strong>
                <span>Te avisaremos sobre nuevos pedidos, pagos y ofertas.</span>
            </div>
            <div class="ltms-push-prompt-actions">
                <button type="button" class="ltms-push-prompt-deny">No, gracias</button>
                <button type="button" class="ltms-push-prompt-accept">Activar</button>
            </div>
        `;

        document.body.appendChild(banner);
        requestAnimationFrame(() => banner.classList.add('visible'));

        const dismiss = (pref) => {
            try { localStorage.setItem('ltms-push-pref', pref); } catch (e) {}
            banner.classList.remove('visible');
            setTimeout(() => banner.remove(), 400);
        };

        banner.querySelector('.ltms-push-prompt-deny').addEventListener('click', () => dismiss('disabled'));

        banner.querySelector('.ltms-push-prompt-accept').addEventListener('click', async () => {
            dismiss('enabled');

            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                toast('info', 'Notificaciones bloqueadas', 'Puedes activarlas desde la configuración del navegador.');
                return;
            }

            try {
                // Subscribe to push
                const sub = await swRegistration.pushManager.subscribe({
                    userVisibleOnly: true,
                });

                // Send subscription to server
                if (typeof jQuery !== 'undefined' && typeof ltmsDashboard !== 'undefined') {
                    jQuery.post(ltmsDashboard.ajax_url, {
                        action: 'ltms_save_push_subscription',
                        nonce: ltmsDashboard.nonce,
                        subscription: JSON.stringify(sub),
                    });
                }

                toast('success', '✅ Notificaciones activadas', 'Recibirás alertas de pedidos y pagos.');
            } catch (e) {
                toast('error', 'Error', 'No se pudieron activar las notificaciones.');
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // ═══════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function debounce(fn, wait) {
        let t;
        return function (...args) {
            clearTimeout(t);
            t = setTimeout(() => fn.apply(this, args), wait || CONFIG.debounceMs);
        };
    }

    LTMS.UX.debounce = debounce;
    LTMS.UX.escapeHtml = escapeHtml;

    // ═══════════════════════════════════════════════════════════
    // 117. TELEMETRY — Tracking opcional de uso de módulos UX
    // ═══════════════════════════════════════════════════════════

    /**
     * Módulo de telemetría OPT-IN. Solo activo si el usuario lo habilita
     * explícitamente vía `LTMS.UX.telemetry.enable()` o si está activado
     * desde el admin (data attribute en <body>).
     *
     * Respeto a privacidad:
     *   - No envía PII (sin emails, sin IDs, sin contenido)
     *   - Solo registra: nombre del módulo + timestamp + success/error
     *   - Buffer batch cada 30s, máximo 50 eventos
     *   - Endpoint configurable (default: ltms_telemetry AJAX)
     *   - Se desactiva automáticamente si el usuario tiene Do Not Track
     */

    const telemetry = {
        enabled: false,
        buffer: [],
        flushTimer: null,
        endpoint: null,
        maxBufferSize: 50,
        flushIntervalMs: 30000,
        sessionStart: Date.now(),
    };

    function telemetryInit() {
        // Auto-activación si el body tiene data-ltms-telemetry="true"
        const autoEnabled = document.body && document.body.dataset.ltmsTelemetry === 'true';
        if (autoEnabled) {
            telemetryEnable({ silent: true });
        }

        // Respetar Do Not Track
        if (navigator.doNotTrack === '1' || window.doNotTrack === '1') {
            telemetry.enabled = false;
        }

        // Endpoint configurable vía window.ltmsUX
        if (window.ltmsUX && window.ltmsUX.telemetryEndpoint) {
            telemetry.endpoint = window.ltmsUX.telemetryEndpoint;
        } else if (window.ltmsAuth && window.ltmsAuth.ajax_url) {
            telemetry.endpoint = window.ltmsAuth.ajax_url + '?action=ltms_telemetry';
        }
    }

    function telemetryEnable(opts) {
        opts = opts || {};
        if (telemetry.enabled) return;
        telemetry.enabled = true;
        if (!opts.silent) {
            // Toast silencioso en enable explícito
        }
        // Iniciar flush timer
        if (!telemetry.flushTimer) {
            telemetry.flushTimer = setInterval(telemetryFlush, telemetry.flushIntervalMs);
        }
        // Flush al unload
        window.addEventListener('beforeunload', telemetryFlush);
    }

    function telemetryDisable() {
        telemetry.enabled = false;
        if (telemetry.flushTimer) {
            clearInterval(telemetry.flushTimer);
            telemetry.flushTimer = null;
        }
        window.removeEventListener('beforeunload', telemetryFlush);
    }

    /**
     * Registra un evento de uso de módulo. Llamado por otros módulos.
     * @param {string} module - Nombre del módulo (ej: 'cart_drawer')
     * @param {string} action - Acción realizada (ej: 'open', 'close', 'error')
     * @param {object} meta - Metadatos adicionales (sin PII)
     */
    function telemetryTrack(module, action, meta) {
        if (!telemetry.enabled) return;

        const event = {
            module: String(module).slice(0, 64),
            action: String(action).slice(0, 32),
            ts: Date.now(),
            session_age: Date.now() - telemetry.sessionStart,
            meta: meta ? JSON.stringify(meta).slice(0, 512) : null,
        };

        telemetry.buffer.push(event);

        // Flush inmediato si excede el buffer
        if (telemetry.buffer.length >= telemetry.maxBufferSize) {
            telemetryFlush();
        }
    }

    function telemetryFlush() {
        if (!telemetry.enabled || telemetry.buffer.length === 0) return;
        if (!telemetry.endpoint) return;

        const batch = telemetry.buffer.splice(0, telemetry.buffer.length);
        const payload = new URLSearchParams();
        payload.append('events', JSON.stringify(batch));

        // Non-blocking beacon
        if (navigator.sendBeacon) {
            const blob = new Blob([payload.toString()], { type: 'application/x-www-form-urlencoded' });
            navigator.sendBeacon(telemetry.endpoint, blob);
        } else {
            // Fallback fetch con keepalive
            try {
                fetch(telemetry.endpoint, {
                    method: 'POST',
                    body: payload,
                    keepalive: true,
                    credentials: 'same-origin',
                }).catch(() => {});
            } catch (e) { /* silent fail */ }
        }
    }

    LTMS.UX.telemetry = {
        enable: telemetryEnable,
        disable: telemetryDisable,
        track: telemetryTrack,
        flush: telemetryFlush,
        isEnabled: () => telemetry.enabled,
        getBuffer: () => [...telemetry.buffer],
    };

    // ═══════════════════════════════════════════════════════════
    // FUNCIONES CRUZADAS movidas desde dashboard/storefront
    // ═══════════════════════════════════════════════════════════

    function celebrateConfetti() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        const colors = ['#0F4C75', '#3282B8', '#F39C12', '#16A34A', '#DC2626'];
        const confettiContainer = document.createElement('div');
        confettiContainer.className = 'ltms-confetti-container';
        document.body.appendChild(confettiContainer);

        for (let i = 0; i < 50; i++) {
            const piece = document.createElement('div');
            piece.className = 'ltms-confetti-piece';
            piece.style.left = Math.random() * 100 + '%';
            piece.style.background = colors[Math.floor(Math.random() * colors.length)];
            piece.style.animationDelay = Math.random() * 0.5 + 's';
            piece.style.animationDuration = (Math.random() * 1 + 1.5) + 's';
            confettiContainer.appendChild(piece);
        }

        setTimeout(() => {
            if (confettiContainer.parentNode) confettiContainer.parentNode.removeChild(confettiContainer);
        }, 3000);
    }
    LTMS.UX.celebrateConfetti = celebrateConfetti;

    function showOrderSuccess(orderData = {}) {
        const overlay = document.createElement('div');
        overlay.className = 'ltms-order-success-overlay';

        overlay.innerHTML = `
            <div class="ltms-order-success-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-os-title">
                <div class="ltms-order-success-check">
                    <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <path class="ltms-success-circle" d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                        <polyline class="ltms-success-check" points="22 4 12 14.01 9 11.01"/>
                    </svg>
                </div>
                <h2 class="ltms-order-success-title" id="ltms-os-title">¡Pedido confirmado!</h2>
                <p class="ltms-order-success-msg">${escapeHtml(orderData.message || 'Tu pedido se ha procesado correctamente.')}</p>
                ${orderData.order_number ? `<div class="ltms-order-success-number">Pedido #${escapeHtml(orderData.order_number)}</div>` : ''}
                <div class="ltms-order-success-actions">
                    ${orderData.continue_url ? `<a href="${escapeHtml(orderData.continue_url)}" class="ltms-btn ltms-btn-outline">Seguir comprando</a>` : ''}
                    ${orderData.track_url ? `<a href="${escapeHtml(orderData.track_url)}" class="ltms-btn ltms-btn-primary">Rastrear pedido</a>` : ''}
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';

        requestAnimationFrame(() => overlay.classList.add('visible'));

        // Confetti
        setTimeout(() => celebrateConfetti(), 300);

        // Auto-remove after 10s if no action
        setTimeout(() => {
            if (overlay.parentNode) {
                overlay.classList.remove('visible');
                setTimeout(() => {
                    if (overlay.parentNode) overlay.remove();
                    document.body.style.overflow = '';
                }, 400);
            }
        }, 30000);
    }
    LTMS.UX.showOrderSuccess = showOrderSuccess;


    // ── Exports públicos para bundles dependientes ──────────────
    Object.assign(LTMS.UX, {
        toast,
        toastSuccess,
        toastError,
        toastWarning,
        toastInfo,
        trapFocus,
        announce,
        escapeHtml,
        debounce,
        confirmDialog,
        formatCurrency,
        formatDate,
        formatNumber,
        celebrateConfetti,
        showOrderSuccess,
        renderEmptyState,
        createCountdown,
        renderStockIndicator,
        createStarRating,
        openPrintPreview,
        toggle,
        handleKeydown,
        close,
        update,
    });

    // Re-init compartido para el SPA del dashboard (elementos inyectados).
    LTMS.UX.reinit = function () {
        initPasswordStrength();
        initLazyImages();
    };

    LTMS.UX.config = CONFIG;


    // ═══════════════════════════════════════════════════════════
    // INIT — Punto de entrada del bundle
    // ═══════════════════════════════════════════════════════════

    function init() {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initAll);
        } else {
            initAll();
        }
    }

    function initAll() {
        try {
            telemetryInit();
            initSkipLink();
            initScrollReveal();
            initPasswordStrength();
            initThemeToggle();
            initCopyButtons();
            initLazyImages();
            initAjaxErrorInterceptor();
            initNetworkStatus();
            initFormEnhancements();
            initBackToTop();
            initPasswordToggles();
            initRefreshButton();
            initModalFocusTrap();
            initGlobalSearch();
            initFileUploads();
            initPWAInstall();
            initErrorBoundaries();
            initPerfMonitor();
            initContextualHelp();
            initFormValidation();
            initPreferences();
            initAccessibilityEnhancements();
            initPerformanceOptimizations();
            initDataExport();
            initLightbox();
            initFormatters();
            initKeyboardHelp();
            initSystemStatus();
            initAdvancedTables();
            initI18n();
            initVoiceSearch();
            initInfiniteScroll();
            initOrderTracking();
            initQRCode();
            initStarRatings();
            initAccordion();
            initStockIndicators();
            initCountdowns();
            initToggleSwitches();
            initReadingProgress();
            initLiveChat();
            initPushNotifications();


            LTMS.UX.ready = true;
            LTMS.UX.version = '2.0.0';
        } catch (err) {
            console.error('[LTMS.UX] Error inicializando:', err);
        }
    }

    // Auto-init
    init();

})();