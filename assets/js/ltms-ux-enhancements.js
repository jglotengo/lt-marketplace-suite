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
    // 3. KEYBOARD SHORTCUTS — Productividad en el dashboard
    // ═══════════════════════════════════════════════════════════

    /**
     * Atajos de teclado:
     *  - Alt+1..9 → navegar vistas del dashboard
     *  - Alt+H    → ir a inicio
     *  - Alt+N    → toggle notificaciones
     *  - Alt+/    → focus en búsqueda (si existe)
     *  - Esc      → cerrar modales/overlays
     */
    function initKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Solo si no estamos escribiendo en un input
            const tag = (e.target.tagName || '').toLowerCase();
            const isTyping = tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable;

            // Esc siempre funciona
            if (e.key === 'Escape') {
                // Cerrar modales
                document.querySelectorAll('.ltms-modal-overlay.ltms-modal-open').forEach(m => {
                    m.classList.remove('ltms-modal-open');
                });
                // Cerrar notifications panel
                const notifPanel = document.querySelector('.ltms-notifications-panel.open');
                if (notifPanel) notifPanel.classList.remove('open');
                // Cerrar sidebar móvil
                const sidebar = document.querySelector('.ltms-sidebar.ltms-sidebar-open');
                if (sidebar) sidebar.classList.remove('ltms-sidebar-open');
                return;
            }

            if (isTyping || !e.altKey) return;

            // Alt + número → navegar a vista N
            if (/^[1-9]$/.test(e.key)) {
                e.preventDefault();
                const idx = parseInt(e.key, 10) - 1;
                const navItems = document.querySelectorAll('.ltms-nav-item[data-view]');
                if (navItems[idx]) navItems[idx].click();
                return;
            }

            // Alt + H → Home
            if (e.key.toLowerCase() === 'h') {
                e.preventDefault();
                const home = document.querySelector('.ltms-nav-item[data-view="home"]');
                if (home) home.click();
                return;
            }

            // Alt + N → toggle notificaciones
            if (e.key.toLowerCase() === 'n') {
                e.preventDefault();
                const notifBtn = document.querySelector('.ltms-topbar-notif');
                if (notifBtn) notifBtn.click();
                return;
            }

            // Alt + / → focus búsqueda
            if (e.key === '/') {
                e.preventDefault();
                const search = document.querySelector('.ltms-live-search-input, input[type="search"], .ltms-search-input');
                if (search) search.focus();
                return;
            }
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
            const toggle = e.target.closest('.ltms-theme-toggle');
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
            const btn = e.target.closest('[data-copy]');
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
        const el = e.target.closest('[data-confirm-message]');
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
        btn.style.cssText = `
            position: fixed;
            bottom: 24px;
            right: 24px;
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
    // 14. SIDEBAR MOBILE — Cerrar al click fuera
    // ═══════════════════════════════════════════════════════════

    function initSidebarOverlay() {
        const overlay = document.querySelector('.ltms-sidebar-overlay');
        if (!overlay) return;

        overlay.addEventListener('click', () => {
            const sidebar = document.querySelector('.ltms-sidebar');
            if (sidebar) {
                sidebar.classList.remove('ltms-sidebar-open');
                overlay.classList.remove('active');
                overlay.style.display = 'none';
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 15. PASSWORD TOGGLE — Eye icon swap
    // ═══════════════════════════════════════════════════════════

    function initPasswordToggles() {
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.ltms-toggle-password');
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
    // 16. USER DROPDOWN — Toggle en topbar
    // ═══════════════════════════════════════════════════════════

    function initUserDropdown() {
        const wrap = document.querySelector('.ltms-user-dropdown-wrap');
        if (!wrap) return;

        const trigger = wrap.querySelector('.ltms-topbar-user');
        const overlay = wrap.querySelector('.ltms-dropdown-overlay');

        function toggle(open) {
            const isOpen = typeof open === 'boolean' ? open : !wrap.classList.contains('is-open');
            wrap.classList.toggle('is-open', isOpen);
            if (trigger) trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            if (overlay) overlay.classList.toggle('is-visible', isOpen);
        }

        if (trigger) {
            trigger.addEventListener('click', (e) => {
                e.stopPropagation();
                toggle();
            });
        }

        if (overlay) {
            overlay.addEventListener('click', () => toggle(false));
        }

        // Cerrar al click fuera
        document.addEventListener('click', (e) => {
            if (!wrap.contains(e.target)) toggle(false);
        });

        // Cerrar con Escape
        wrap.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                toggle(false);
                if (trigger) trigger.focus();
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 17. REFRESH BUTTON — Spinner animation
    // ═══════════════════════════════════════════════════════════

    function initRefreshButton() {
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.ltms-refresh-btn');
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
    // 18. NOTIFICATIONS PANEL — Close button + mark all
    // ═══════════════════════════════════════════════════════════

    function initNotificationsPanel() {
        // Close button del panel de notificaciones
        document.addEventListener('click', (e) => {
            const closeBtn = e.target.closest('.ltms-notif-close');
            if (closeBtn) {
                const panel = document.querySelector('.ltms-notifications-panel');
                if (panel) {
                    panel.classList.remove('open');
                    panel.setAttribute('aria-hidden', 'true');
                }
                const notifBtn = document.querySelector('.ltms-topbar-notif');
                if (notifBtn) notifBtn.setAttribute('aria-expanded', 'false');
                return;
            }

            // Mark all as read
            const markAllBtn = e.target.closest('.ltms-notif-mark-all');
            if (markAllBtn) {
                e.preventDefault();
                const unreadItems = document.querySelectorAll('.ltms-notif-item.unread');
                unreadItems.forEach((item) => {
                    const id = item.dataset.id;
                    if (id && window.LTMS && LTMS.Dashboard && typeof LTMS.Dashboard.markNotificationRead === 'function') {
                        LTMS.Dashboard.markNotificationRead(id, jQuery(item));
                    }
                });
                if (window.LTMS && LTMS.UX) {
                    LTMS.UX.toastSuccess('Listo', unreadItems.length > 0 ? `${unreadItems.length} notificación(es) marcada(s) como leída(s)` : 'No había notificaciones sin leer');
                }
                return;
            }
        });

        // Actualizar aria-expanded del botón de notificaciones al togglear
        if (typeof jQuery !== 'undefined') {
            jQuery(document).on('click', '.ltms-topbar-notif', function () {
                const panel = document.querySelector('.ltms-notifications-panel');
                const isOpen = panel && panel.classList.contains('open');
                this.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                if (panel) panel.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
            });
        }
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
    // 21. COMMAND PALETTE — Búsqueda rápida con Cmd+K / Ctrl+K
    // ═══════════════════════════════════════════════════════════

    /**
     * Command palette: overlay de búsqueda rápida que permite
     * navegar a cualquier vista del dashboard, ejecutar acciones
     * comunes y buscar productos/pedidos con teclado.
     *
     * Activación: Cmd+K (Mac) / Ctrl+K (Windows/Linux)
     */

    const COMMANDS = [
        { id: 'goto-home',     label: 'Ir a Inicio',        icon: 'home',     view: 'home',     keywords: 'inicio dashboard' },
        { id: 'goto-orders',   label: 'Ir a Pedidos',       icon: 'orders',   view: 'orders',   keywords: 'pedidos ventas' },
        { id: 'goto-products', label: 'Ir a Productos',     icon: 'products', view: 'products', keywords: 'productos catalogo' },
        { id: 'goto-wallet',   label: 'Ir a Billetera',     icon: 'wallet',   view: 'wallet',   keywords: 'billetera dinero retiro' },
        { id: 'goto-envios',   label: 'Ir a Envíos',        icon: 'shipping', view: 'envios',   keywords: 'envios guias despacho' },
        { id: 'goto-bookings', label: 'Ir a Reservas',      icon: 'booking',  view: 'bookings', keywords: 'reservas turismo alojamiento' },
        { id: 'goto-settings', label: 'Ir a Configuración', icon: 'settings', view: 'settings', keywords: 'configuracion ajustes cuenta' },
        { id: 'action-payout', label: 'Solicitar Retiro',   icon: 'payout',   action: () => LTMS.Dashboard && LTMS.Dashboard.openPayoutModal && LTMS.Dashboard.openPayoutModal(), keywords: 'retiro dinero transferir' },
        { id: 'action-refresh', label: 'Actualizar datos',  icon: 'refresh',  action: () => LTMS.Dashboard && LTMS.Dashboard.loadView && LTMS.Dashboard.loadView(LTMS.Dashboard.currentView || 'home', true), keywords: 'actualizar refrescar recargar' },
        { id: 'action-theme',  label: 'Cambiar tema (claro/oscuro)', icon: 'theme', action: () => document.querySelector('.ltms-theme-toggle') && document.querySelector('.ltms-theme-toggle').click(), keywords: 'tema dark light oscuro claro' },
        { id: 'action-logout', label: 'Cerrar sesión',      icon: 'logout',   action: () => { const link = document.querySelector('.ltms-user-dropdown a[href*="logout"]'); if (link) window.location.href = link.href; }, keywords: 'salir logout session' },
    ];

    const COMMAND_ICONS = {
        home:     '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
        orders:   '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 16v1a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2"/><path d="M22 11l-3-3h-5v8h5l3-3v-2z"/></svg>',
        products: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 11V7a4 4 0 0 0-8 0v4"/><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/></svg>',
        wallet:   '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/></svg>',
        shipping: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
        booking:  '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
        settings: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
        payout:   '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
        refresh:  '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>',
        theme:    '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/></svg>',
        logout:   '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
    };

    let paletteEl = null;
    let paletteCleanup = null;
    let selectedIndex = 0;

    function getCommands() {
        return COMMANDS;
    }

    function filterCommands(query) {
        if (!query) return COMMANDS;
        const q = query.toLowerCase().trim();
        return COMMANDS.filter((cmd) => {
            return cmd.label.toLowerCase().includes(q) || cmd.keywords.toLowerCase().includes(q);
        });
    }

    function openCommandPalette() {
        if (paletteEl) return; // ya abierto

        // Solo funcionar en contexto de dashboard
        if (!document.querySelector('.ltms-dashboard-container')) return;

        paletteEl = document.createElement('div');
        paletteEl.className = 'ltms-command-palette';
        paletteEl.innerHTML = `
            <div class="ltms-cp-overlay"></div>
            <div class="ltms-cp-modal" role="dialog" aria-modal="true" aria-label="Búsqueda rápida">
                <div class="ltms-cp-input-wrap">
                    <svg class="ltms-cp-search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" class="ltms-cp-input" placeholder="Buscar acciones o ir a..." autocomplete="off" />
                    <kbd class="ltms-cp-kbd">Esc</kbd>
                </div>
                <div class="ltms-cp-results" role="listbox" aria-label="Resultados"></div>
                <div class="ltms-cp-footer">
                    <span><kbd>↑</kbd><kbd>↓</kbd> navegar</span>
                    <span><kbd>↵</kbd> seleccionar</span>
                    <span><kbd>Esc</kbd> cerrar</span>
                </div>
            </div>
        `;
        document.body.appendChild(paletteEl);
        document.body.style.overflow = 'hidden';

        const input = paletteEl.querySelector('.ltms-cp-input');
        const results = paletteEl.querySelector('.ltms-cp-results');
        selectedIndex = 0;

        function render(filtered) {
            if (!filtered.length) {
                results.innerHTML = '<div class="ltms-cp-empty">No se encontraron resultados</div>';
                return;
            }
            results.innerHTML = filtered.map((cmd, i) => `
                <button type="button" class="ltms-cp-item ${i === selectedIndex ? 'active' : ''}" data-id="${cmd.id}" role="option" aria-selected="${i === selectedIndex}">
                    <span class="ltms-cp-item-icon">${COMMAND_ICONS[cmd.icon] || COMMAND_ICONS.settings}</span>
                    <span class="ltms-cp-item-label">${escapeHtml(cmd.label)}</span>
                    ${cmd.view ? `<kbd class="ltms-cp-item-hint">Alt+${COMMANDS.findIndex(c => c.view === cmd.view) + 1}</kbd>` : ''}
                </button>
            `).join('');

            // Click handler
            results.querySelectorAll('.ltms-cp-item').forEach((el) => {
                el.addEventListener('click', () => {
                    const cmd = filtered[parseInt(el.dataset.idx ?? Array.from(results.children).indexOf(el), 10)];
                    executeCommand(cmd);
                });
                el.dataset.idx = Array.from(results.children).indexOf(el);
            });
        }

        function executeCommand(cmd) {
            if (!cmd) return;
            if (cmd.view && window.LTMS && LTMS.Dashboard && LTMS.Dashboard.loadView) {
                LTMS.Dashboard.loadView(cmd.view);
            } else if (cmd.action) {
                cmd.action();
            }
            closeCommandPalette();
        }

        function updateActive() {
            const items = results.querySelectorAll('.ltms-cp-item');
            items.forEach((el, i) => {
                el.classList.toggle('active', i === selectedIndex);
                el.setAttribute('aria-selected', i === selectedIndex ? 'true' : 'false');
            });
            // Scroll into view
            const active = items[selectedIndex];
            if (active) active.scrollIntoView({ block: 'nearest' });
        }

        // Input handler
        input.addEventListener('input', (e) => {
            const filtered = filterCommands(e.target.value);
            selectedIndex = 0;
            render(filtered);
        });

        // Keyboard navigation
        function handleKeydown(e) {
            const filtered = filterCommands(input.value);
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedIndex = Math.min(selectedIndex + 1, filtered.length - 1);
                updateActive();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedIndex = Math.max(selectedIndex - 1, 0);
                updateActive();
            } else if (e.key === 'Enter') {
                e.preventDefault();
                executeCommand(filtered[selectedIndex]);
            } else if (e.key === 'Escape') {
                e.preventDefault();
                closeCommandPalette();
            }
        }
        input.addEventListener('keydown', handleKeydown);

        // Click en overlay cierra
        paletteEl.querySelector('.ltms-cp-overlay').addEventListener('click', closeCommandPalette);

        // Render inicial
        render(COMMANDS);

        // Focus trap
        const modal = paletteEl.querySelector('.ltms-cp-modal');
        paletteCleanup = trapFocus(modal, input);

        // Animación de entrada
        requestAnimationFrame(() => paletteEl.classList.add('ltms-cp-open'));
    }

    function closeCommandPalette() {
        if (!paletteEl) return;
        if (paletteCleanup) {
            paletteCleanup();
            paletteCleanup = null;
        }
        paletteEl.classList.remove('ltms-cp-open');
        document.body.style.overflow = '';
        setTimeout(() => {
            if (paletteEl && paletteEl.parentNode) paletteEl.parentNode.removeChild(paletteEl);
            paletteEl = null;
        }, 200);
    }

    LTMS.UX.openCommandPalette = openCommandPalette;
    LTMS.UX.closeCommandPalette = closeCommandPalette;

    function initCommandPalette() {
        // Cmd+K / Ctrl+K
        document.addEventListener('keydown', (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                if (paletteEl) {
                    closeCommandPalette();
                } else {
                    openCommandPalette();
                }
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 22. SKELETON LOADERS — Placeholders durante carga SPA
    // ═══════════════════════════════════════════════════════════

    /**
     * Renderiza un skeleton loader para una vista del dashboard.
     * Mejora la percepción de velocidad al mostrar placeholders
     * estructurados en lugar de "..." o spinners genéricos.
     */
    const SKELETON_TEMPLATES = {
        home: `
            <div class="ltms-skeleton-view ltms-skeleton-home">
                <div class="ltms-skeleton-row" style="margin-bottom:24px;">
                    <div class="ltms-skeleton" style="width:200px;height:32px;"></div>
                </div>
                <div class="ltms-skeleton-grid">
                    <div class="ltms-skeleton-card" repeat="4"></div>
                </div>
                <div class="ltms-skeleton-chart"></div>
            </div>
        `,
        orders: `
            <div class="ltms-skeleton-view">
                <div class="ltms-skeleton-row">
                    <div class="ltms-skeleton" style="width:150px;height:32px;"></div>
                </div>
                <div class="ltms-skeleton-table">
                    <div class="ltms-skeleton-row" repeat="6"></div>
                </div>
            </div>
        `,
        products: `
            <div class="ltms-skeleton-view">
                <div class="ltms-skeleton-row">
                    <div class="ltms-skeleton" style="width:150px;height:32px;"></div>
                </div>
                <div class="ltms-skeleton-grid">
                    <div class="ltms-skeleton-product" repeat="8"></div>
                </div>
            </div>
        `,
        wallet: `
            <div class="ltms-skeleton-view">
                <div class="ltms-skeleton-wallet"></div>
                <div class="ltms-skeleton-table">
                    <div class="ltms-skeleton-row" repeat="5"></div>
                </div>
            </div>
        `,
        default: `
            <div class="ltms-skeleton-view">
                <div class="ltms-skeleton" style="width:200px;height:32px;margin-bottom:24px;"></div>
                <div class="ltms-skeleton" style="width:100%;height:120px;margin-bottom:16px;"></div>
                <div class="ltms-skeleton" style="width:100%;height:120px;"></div>
            </div>
        `,
    };

    function showSkeleton(view, container) {
        const target = container || document.querySelector('.ltms-view-section[style*="block"], .ltms-view-section:not([style*="none"])');
        if (!target) return;

        const template = (SKELETON_TEMPLATES[view] || SKELETON_TEMPLATES.default).replace(/repeat="(\d+)"/g, (m, n) => {
            const count = parseInt(n, 10);
            const el = m.replace(/repeat="\d+"/, '').replace(/\s+/g, ' ').trim();
            return Array(count).fill(el).join('');
        });

        target.innerHTML = template;
    }

    LTMS.UX.showSkeleton = showSkeleton;

    function initSkeletonLoaders() {
        // Hook en navegación del dashboard para mostrar skeleton
        if (typeof jQuery !== 'undefined' && window.LTMS && LTMS.Dashboard) {
            const origLoadView = LTMS.Dashboard.loadView;
            if (typeof origLoadView === 'function') {
                LTMS.Dashboard.loadView = function (view, forceRefresh) {
                    // Mostrar skeleton inmediatamente
                    setTimeout(() => showSkeleton(view), 0);
                    // Llamar al método original
                    return origLoadView.call(this, view, forceRefresh);
                };
            }
        }
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
    // 24. TOUR SYSTEM — Tour guiado/onboarding interactivo
    // ═══════════════════════════════════════════════════════════

    /**
     * Sistema de tour guiado que destaca elementos del dashboard
     * con un spotlight y tooltip explicativo. Ideal para onboarding
     * de nuevos vendedores.
     */

    const TOUR_STEPS = [
        {
            target: '.ltms-sidebar',
            title: 'Navegación principal',
            body: 'Usa el menú lateral para acceder a todas las secciones de tu panel: pedidos, productos, billetera y más.',
            placement: 'right',
        },
        {
            target: '.ltms-cp-trigger',
            title: 'Búsqueda rápida (Cmd+K)',
            body: 'Presiona Cmd+K (Mac) o Ctrl+K (Windows) en cualquier momento para abrir la búsqueda rápida y navegar a cualquier sección al instante.',
            placement: 'bottom',
        },
        {
            target: '.ltms-metrics-grid',
            title: 'Tus métricas en tiempo real',
            body: 'Aquí verás un resumen de tus ventas, pedidos, comisiones y balance. Los números se actualizan automáticamente.',
            placement: 'top',
        },
        {
            target: '.ltms-topbar-notif',
            title: 'Notificaciones',
            body: 'Recibirás alertas sobre nuevos pedidos, pagos, retiros y verificación KYC. Mantente al día revisando este icono.',
            placement: 'bottom',
        },
        {
            target: '.ltms-balance-widget',
            title: 'Tu billetera',
            body: 'Desde aquí puedes solicitar retiros a tu cuenta bancaria. Los fondos se liberan automáticamente cuando se completa un pedido.',
            placement: 'right',
        },
    ];

    let tourState = {
        active: false,
        currentStep: 0,
        overlay: null,
        spotlight: null,
        tooltip: null,
    };

    function startTour() {
        if (tourState.active) return;
        if (!document.querySelector('.ltms-dashboard-container')) {
            toast('info', 'Tour no disponible', 'El tour solo está disponible en el panel de vendedor.');
            return;
        }

        tourState.active = true;
        tourState.currentStep = 0;

        // Crear overlay
        tourState.overlay = document.createElement('div');
        tourState.overlay.className = 'ltms-tour-overlay';
        tourState.spotlight = document.createElement('div');
        tourState.spotlight.className = 'ltms-tour-spotlight';
        tourState.tooltip = document.createElement('div');
        tourState.tooltip.className = 'ltms-tour-tooltip';

        tourState.overlay.appendChild(tourState.spotlight);
        tourState.overlay.appendChild(tourState.tooltip);
        document.body.appendChild(tourState.overlay);
        document.body.style.overflow = 'hidden';

        renderTourStep();

        // Cerrar con Escape
        document.addEventListener('keydown', tourKeydownHandler);

        announce('Tour iniciado. Presiona Escape para salir.');
    }

    function tourKeydownHandler(e) {
        if (e.key === 'Escape') {
            e.preventDefault();
            endTour();
        } else if (e.key === 'ArrowRight') {
            e.preventDefault();
            nextTourStep();
        } else if (e.key === 'ArrowLeft') {
            e.preventDefault();
            prevTourStep();
        }
    }

    function renderTourStep() {
        const step = TOUR_STEPS[tourState.currentStep];
        if (!step) {
            endTour();
            return;
        }

        const target = document.querySelector(step.target);
        if (!target) {
            // Si el target no existe, saltar al siguiente paso
            tourState.currentStep++;
            renderTourStep();
            return;
        }

        // Scroll al elemento
        target.scrollIntoView({ behavior: 'smooth', block: 'center' });

        setTimeout(() => {
            const rect = target.getBoundingClientRect();
            const padding = 8;

            // Posicionar spotlight
            tourState.spotlight.style.top = (rect.top - padding) + 'px';
            tourState.spotlight.style.left = (rect.left - padding) + 'px';
            tourState.spotlight.style.width = (rect.width + padding * 2) + 'px';
            tourState.spotlight.style.height = (rect.height + padding * 2) + 'px';

            // Posicionar tooltip según placement
            const tooltip = tourState.tooltip;
            tooltip.innerHTML = `
                <div class="ltms-tour-tooltip-header">
                    <span class="ltms-tour-step-num">${tourState.currentStep + 1}</span>
                    <h3 class="ltms-tour-title">${escapeHtml(step.title)}</h3>
                </div>
                <p class="ltms-tour-body">${escapeHtml(step.body)}</p>
                <div class="ltms-tour-footer">
                    <span class="ltms-tour-progress">${tourState.currentStep + 1} de ${TOUR_STEPS.length}</span>
                    <div class="ltms-tour-actions">
                        ${tourState.currentStep > 0 ? '<button type="button" class="ltms-tour-btn ltms-tour-btn-back" data-tour-action="prev">Atrás</button>' : ''}
                        ${tourState.currentStep < TOUR_STEPS.length - 1
                            ? '<button type="button" class="ltms-tour-btn ltms-tour-btn-next" data-tour-action="next">Siguiente</button>'
                            : '<button type="button" class="ltms-tour-btn ltms-tour-btn-next" data-tour-action="finish">Finalizar</button>'}
                        <button type="button" class="ltms-tour-btn ltms-tour-btn-skip" data-tour-action="skip">Saltar</button>
                    </div>
                </div>
            `;

            // Calcular posición del tooltip
            const tooltipRect = tooltip.getBoundingClientRect();
            let top, left;

            switch (step.placement) {
                case 'right':
                    top = rect.top + (rect.height / 2) - (tooltipRect.height / 2);
                    left = rect.right + 16;
                    break;
                case 'left':
                    top = rect.top + (rect.height / 2) - (tooltipRect.height / 2);
                    left = rect.left - tooltipRect.width - 16;
                    break;
                case 'top':
                    top = rect.top - tooltipRect.height - 16;
                    left = rect.left + (rect.width / 2) - (tooltipRect.width / 2);
                    break;
                case 'bottom':
                default:
                    top = rect.bottom + 16;
                    left = rect.left + (rect.width / 2) - (tooltipRect.width / 2);
                    break;
            }

            // Ajustar si sale de la pantalla
            if (left < 16) left = 16;
            if (left + tooltipRect.width > window.innerWidth - 16) {
                left = window.innerWidth - tooltipRect.width - 16;
            }
            if (top < 16) top = rect.bottom + 16;
            if (top + tooltipRect.height > window.innerHeight - 16) {
                top = rect.top - tooltipRect.height - 16;
            }

            tooltip.style.top = top + 'px';
            tooltip.style.left = left + 'px';

            requestAnimationFrame(() => tooltip.classList.add('ltms-tour-visible'));
        }, 300);
    }

    function nextTourStep() {
        tourState.currentStep++;
        if (tourState.currentStep >= TOUR_STEPS.length) {
            endTour();
        } else {
            tourState.tooltip.classList.remove('ltms-tour-visible');
            setTimeout(renderTourStep, 200);
        }
    }

    function prevTourStep() {
        if (tourState.currentStep > 0) {
            tourState.currentStep--;
            tourState.tooltip.classList.remove('ltms-tour-visible');
            setTimeout(renderTourStep, 200);
        }
    }

    function endTour() {
        if (!tourState.active) return;
        tourState.active = false;
        document.removeEventListener('keydown', tourKeydownHandler);

        if (tourState.tooltip) tourState.tooltip.classList.remove('ltms-tour-visible');

        setTimeout(() => {
            if (tourState.overlay && tourState.overlay.parentNode) {
                tourState.overlay.parentNode.removeChild(tourState.overlay);
            }
            tourState.overlay = null;
            tourState.spotlight = null;
            tourState.tooltip = null;
            document.body.style.overflow = '';
        }, 200);

        // Marcar tour como completado en localStorage
        try { localStorage.setItem('ltms-tour-completed', 'true'); } catch (e) { /* noop */ }

        if (window.LTMS && LTMS.UX) {
            LTMS.UX.toastSuccess('¡Tour completado!', 'Ya estás listo para vender. ¡Mucho éxito!');
        }
        announce('Tour finalizado.');
    }

    LTMS.UX.startTour = startTour;
    LTMS.UX.endTour = endTour;

    // ═══════════════════════════════════════════════════════════
    // 25. ONBOARDING CHECKLIST — Gamificación de onboarding
    // ═══════════════════════════════════════════════════════════

    /**
     * Sistema de checklist de onboarding que recompensa al vendedor
     * por completar tareas clave. Muestra progreso, desbloquea
     * badges y celebra hitos con confetti.
     */

    const ONBOARDING_TASKS = [
        { id: 'profile',     label: 'Completa tu perfil de tienda',     icon: '👤', points: 10, check: () => document.querySelector('.ltms-settings-view') || getMeta('ltms_store_name') },
        { id: 'kyc',         label: 'Verifica tu identidad (KYC)',      icon: '🪪', points: 20, check: () => getMeta('ltms_kyc_status') === 'approved' },
        { id: 'first_product', label: 'Publica tu primer producto',     icon: '🛍️', points: 15, check: () => hasProducts() },
        { id: 'bank_account', label: 'Configura tu cuenta bancaria',   icon: '🏦', points: 10, check: () => getMeta('ltms_bank_account_number') },
        { id: 'first_sale',  label: 'Consigue tu primera venta',       icon: '💰', points: 25, check: () => hasOrders() },
        { id: 'tour',        label: 'Completa el tour del panel',      icon: '🎯', points: 5,  check: () => localStorage.getItem('ltms-tour-completed') === 'true' },
        { id: 'storefront',  label: 'Personaliza tu tienda pública',   icon: '🎨', points: 15, check: () => getMeta('ltms_store_description') },
    ];

    function getMeta(key) {
        // Helper simplificado — en producción esto vendría del servidor
        try { return localStorage.getItem('ltms-meta-' + key) || ''; } catch (e) { return ''; }
    }

    function hasProducts() {
        return document.querySelector('.ltms-products-grid .ltms-product-card') !== null;
    }

    function hasOrders() {
        const ordersTable = document.querySelector('#ltms-orders-tbody');
        return ordersTable && ordersTable.querySelectorAll('tr:not(:first-child)').length > 0;
    }

    function getOnboardingProgress() {
        const completed = ONBOARDING_TASKS.filter((t) => {
            try { return t.check(); } catch (e) { return false; }
        });
        const totalPoints = ONBOARDING_TASKS.reduce((sum, t) => sum + t.points, 0);
        const earnedPoints = completed.reduce((sum, t) => sum + t.points, 0);
        return {
            completed: completed.length,
            total: ONBOARDING_TASKS.length,
            percentage: Math.round((completed.length / ONBOARDING_TASKS.length) * 100),
            points: earnedPoints,
            totalPoints,
            tasks: ONBOARDING_TASKS.map((t) => ({
                ...t,
                done: completed.includes(t),
            })),
        };
    }

    function renderOnboardingWidget() {
        const progress = getOnboardingProgress();
        if (progress.percentage === 100) return null; // No mostrar si todo completado

        const widget = document.createElement('div');
        widget.className = 'ltms-onboarding-widget';
        widget.innerHTML = `
            <div class="ltms-onboarding-header">
                <div class="ltms-onboarding-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    Configura tu tienda
                </div>
                <span class="ltms-onboarding-points">${progress.points}/${progress.totalPoints} pts</span>
            </div>
            <div class="ltms-onboarding-progress">
                <div class="ltms-onboarding-progress-bar" style="width:${progress.percentage}%;"></div>
            </div>
            <div class="ltms-onboarding-tasks">
                ${progress.tasks.map((t) => `
                    <div class="ltms-onboarding-task ${t.done ? 'done' : ''}" data-task="${t.id}">
                        <div class="ltms-onboarding-task-icon">${t.done ? '✓' : t.icon}</div>
                        <div class="ltms-onboarding-task-info">
                            <div class="ltms-onboarding-task-label">${escapeHtml(t.label)}</div>
                            <div class="ltms-onboarding-task-points">+${t.points} pts</div>
                        </div>
                        ${!t.done && t.id === 'tour' ? '<button type="button" class="ltms-onboarding-task-action" data-tour-start>Iniciar</button>' : ''}
                    </div>
                `).join('')}
            </div>
        `;

        // Click en tarea para navegar
        widget.querySelectorAll('.ltms-onboarding-task').forEach((el) => {
            el.addEventListener('click', () => {
                const taskId = el.dataset.task;
                const task = ONBOARDING_TASKS.find((t) => t.id === taskId);
                if (task && !task.done) {
                    navigateToTask(taskId);
                }
            });
        });

        return widget;
    }

    function navigateToTask(taskId) {
        const routes = {
            profile: 'settings',
            kyc: 'settings',
            first_product: 'products',
            bank_account: 'settings',
            first_sale: 'orders',
            storefront: 'settings',
        };
        const view = routes[taskId];
        if (view && window.LTMS && LTMS.Dashboard && LTMS.Dashboard.loadView) {
            LTMS.Dashboard.loadView(view);
        }
    }

    function initOnboardingWidget() {
        // Solo mostrar en dashboard
        if (!document.querySelector('.ltms-dashboard-container')) return;

        // No mostrar si ya está completo
        const progress = getOnboardingProgress();
        if (progress.percentage === 100) return;

        // Insertar al inicio de la vista home
        const insertWidget = () => {
            const homeView = document.querySelector('#ltms-view-home .ltms-view-pad, #ltms-view-home');
            if (!homeView) return;
            if (homeView.querySelector('.ltms-onboarding-widget')) return;

            const widget = renderOnboardingWidget();
            if (widget) {
                homeView.insertBefore(widget, homeView.firstChild.nextSibling);
            }
        };

        // Intentar insertar periódicamente hasta que la vista home esté visible
        setTimeout(insertWidget, 1000);
        setTimeout(insertWidget, 3000);

        // Re-insertar cuando se carga la vista home
        if (typeof jQuery !== 'undefined') {
            jQuery(document).on('ltms:view:loaded', (e, view) => {
                if (view === 'home') setTimeout(insertWidget, 200);
            });
        }
    }

    LTMS.UX.getOnboardingProgress = getOnboardingProgress;

    // Confetti simple para celebrar hitos
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

    LTMS.UX.celebrate = celebrateConfetti;

    function initTour() {
        // Click en botones de tour
        document.addEventListener('click', (e) => {
            const action = e.target.closest('[data-tour-action]');
            if (action) {
                e.preventDefault();
                const act = action.dataset.tourAction;
                if (act === 'next') nextTourStep();
                else if (act === 'prev') prevTourStep();
                else if (act === 'skip' || act === 'finish') endTour();
                return;
            }

            // Launcher button
            const launcher = e.target.closest('.ltms-tour-launcher');
            if (launcher) {
                e.preventDefault();
                startTour();
            }
        });

        // Mostrar tour automáticamente para nuevos usuarios (una sola vez)
        try {
            const completed = localStorage.getItem('ltms-tour-completed');
            const skipped = sessionStorage.getItem('ltms-tour-skipped');
            if (!completed && !skipped && document.querySelector('.ltms-dashboard-container')) {
                // Añadir launcher después de 3s
                setTimeout(() => {
                    if (!document.querySelector('.ltms-tour-launcher')) {
                        const launcher = document.createElement('button');
                        launcher.className = 'ltms-tour-launcher';
                        launcher.setAttribute('aria-label', 'Iniciar tour guiado');
                        launcher.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
                        document.body.appendChild(launcher);
                    }
                }, 3000);
            }
        } catch (e) { /* noop */ }
    }

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
    // 26. MOBILE GESTURES — Swipe y pull-to-refresh
    // ═══════════════════════════════════════════════════════════

    /**
     * Gestures para móvil:
     * - Swipe right desde el borde izquierdo → abre sidebar
     * - Swipe left en sidebar abierto → cierra sidebar
     * - Pull-to-refresh en el contenido principal (scroll top)
     * - Swipe horizontal entre vistas del dashboard
     */

    function initMobileGestures() {
        // Solo activar en touch devices
        if (!('ontouchstart' in window)) return;

        const sidebar = document.querySelector('.ltms-sidebar');
        const overlay = document.querySelector('.ltms-sidebar-overlay');
        const mainContent = document.querySelector('.ltms-main-content');

        if (!sidebar || !mainContent) return;

        let touchStartX = 0;
        let touchStartY = 0;
        let touchStartTime = 0;
        let isTracking = false;
        let isPulling = false;
        let pullDistance = 0;
        const EDGE_THRESHOLD = 30; // px desde el borde
        const SWIPE_THRESHOLD = 80; // distancia mínima para swipe
        const PULL_THRESHOLD = 70; // distancia para pull-to-refresh

        // ── Swipe para abrir/cerrar sidebar ──
        document.addEventListener('touchstart', (e) => {
            if (e.target.matches('input, textarea, select, button, a')) return;

            const touch = e.touches[0];
            touchStartX = touch.clientX;
            touchStartY = touch.clientY;
            touchStartTime = Date.now();
            isTracking = true;

            // Detectar si empieza desde el borde izquierdo (para abrir sidebar)
            const isEdge = touch.clientX < EDGE_THRESHOLD;
            const sidebarOpen = sidebar.classList.contains('ltms-sidebar-open');

            if (isEdge && !sidebarOpen) {
                isTracking = 'open';
            } else if (sidebarOpen) {
                isTracking = 'close';
            }
        }, { passive: true });

        document.addEventListener('touchmove', (e) => {
            if (!isTracking) return;

            const touch = e.touches[0];
            const deltaX = touch.clientX - touchStartX;
            const deltaY = touch.clientY - touchStartY;

            // Si el movimiento es más vertical que horizontal, no es swipe
            if (Math.abs(deltaY) > Math.abs(deltaX)) {
                isTracking = isTracking === 'open' || isTracking === 'close' ? false : isTracking;
                return;
            }

            // Abrir sidebar con swipe right desde el borde
            if (isTracking === 'open' && deltaX > 0) {
                sidebar.style.transform = `translateX(${deltaX - 260}px)`;
                sidebar.style.transition = 'none';
                if (overlay) {
                    overlay.style.display = 'block';
                    overlay.style.opacity = Math.min(deltaX / 260, 0.5);
                }
            }
            // Cerrar sidebar con swipe left
            else if (isTracking === 'close' && deltaX < 0) {
                sidebar.style.transform = `translateX(${deltaX}px)`;
                sidebar.style.transition = 'none';
                if (overlay) {
                    overlay.style.opacity = Math.max(1 + deltaX / 260, 0);
                }
            }
        }, { passive: true });

        document.addEventListener('touchend', (e) => {
            if (!isTracking) return;

            const touch = e.changedTouches[0];
            const deltaX = touch.clientX - touchStartX;
            const deltaTime = Date.now() - touchStartTime;

            sidebar.style.transition = '';
            sidebar.style.transform = '';

            if (overlay) {
                overlay.style.opacity = '';
            }

            // Swipe rápido o distancia suficiente
            if (isTracking === 'open' && (deltaX > SWIPE_THRESHOLD || (deltaTime < 300 && deltaX > 50))) {
                sidebar.classList.add('ltms-sidebar-open');
                if (overlay) {
                    overlay.classList.add('active');
                    overlay.style.display = 'block';
                }
                document.body.style.overflow = 'hidden';
            } else if (isTracking === 'close' && (deltaX < -SWIPE_THRESHOLD || (deltaTime < 300 && deltaX < -50))) {
                sidebar.classList.remove('ltms-sidebar-open');
                if (overlay) {
                    overlay.classList.remove('active');
                    overlay.style.display = 'none';
                }
                document.body.style.overflow = '';
            } else if (overlay) {
                overlay.style.display = '';
            }

            isTracking = false;
        }, { passive: true });

        // ── Pull-to-refresh ──
        let refreshIndicator = null;

        mainContent.addEventListener('touchstart', (e) => {
            const scrollTop = mainContent.scrollTop || window.scrollY;
            if (scrollTop > 0) return;

            const touch = e.touches[0];
            touchStartY = touch.clientY;
            isPulling = true;
            pullDistance = 0;
        }, { passive: true });

        mainContent.addEventListener('touchmove', (e) => {
            if (!isPulling) return;

            const touch = e.touches[0];
            const deltaY = touch.clientY - touchStartY;

            if (deltaY > 0 && deltaY < 120) {
                pullDistance = deltaY;

                if (!refreshIndicator) {
                    refreshIndicator = document.createElement('div');
                    refreshIndicator.className = 'ltms-pull-refresh';
                    refreshIndicator.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>';
                    mainContent.insertBefore(refreshIndicator, mainContent.firstChild);
                }

                const progress = Math.min(pullDistance / PULL_THRESHOLD, 1);
                const rotation = progress * 180;
                refreshIndicator.style.transform = `translateY(${pullDistance}px) rotate(${rotation}deg)`;
                refreshIndicator.style.opacity = progress;

                if (pullDistance >= PULL_THRESHOLD) {
                    refreshIndicator.classList.add('ready');
                } else {
                    refreshIndicator.classList.remove('ready');
                }
            }
        }, { passive: true });

        mainContent.addEventListener('touchend', () => {
            if (!isPulling) return;
            isPulling = false;

            if (refreshIndicator && pullDistance >= PULL_THRESHOLD) {
                // Trigger refresh
                refreshIndicator.classList.add('spinning');
                if (window.LTMS && LTMS.Dashboard && LTMS.Dashboard.loadView) {
                    LTMS.Dashboard.loadView(LTMS.Dashboard.currentView || 'home', true);
                }
                announce('Actualizando datos...');

                setTimeout(() => {
                    if (refreshIndicator && refreshIndicator.parentNode) {
                        refreshIndicator.parentNode.removeChild(refreshIndicator);
                    }
                    refreshIndicator = null;
                }, 1000);
            } else if (refreshIndicator) {
                refreshIndicator.style.transform = '';
                refreshIndicator.style.opacity = '0';
                setTimeout(() => {
                    if (refreshIndicator && refreshIndicator.parentNode) {
                        refreshIndicator.parentNode.removeChild(refreshIndicator);
                    }
                    refreshIndicator = null;
                }, 300);
            }

            pullDistance = 0;
        }, { passive: true });

        // ── Swipe horizontal entre vistas ──
        let viewSwipeStart = 0;
        let viewSwipeActive = false;

        mainContent.addEventListener('touchstart', (e) => {
            const touch = e.touches[0];
            viewSwipeStart = touch.clientX;
            viewSwipeActive = true;
        }, { passive: true });

        mainContent.addEventListener('touchend', (e) => {
            if (!viewSwipeActive) return;
            viewSwipeActive = false;

            const touch = e.changedTouches[0];
            const deltaX = touch.clientX - viewSwipeStart;

            // Solo si el swipe es horizontal y significativo
            if (Math.abs(deltaX) > 100) {
                const navItems = Array.from(document.querySelectorAll('.ltms-nav-item[data-view]'));
                const activeItem = document.querySelector('.ltms-nav-item.active');
                const activeIndex = navItems.indexOf(activeItem);

                if (deltaX > 0 && activeIndex > 0) {
                    // Swipe right → vista anterior
                    navItems[activeIndex - 1].click();
                } else if (deltaX < 0 && activeIndex < navItems.length - 1) {
                    // Swipe left → vista siguiente
                    navItems[activeIndex + 1].click();
                }
            }
        }, { passive: true });
    }

    // ═══════════════════════════════════════════════════════════
    // 27. BULK ACTIONS — Acciones masivas en tablas
    // ═══════════════════════════════════════════════════════════

    /**
     * Sistema de acciones masivas para tablas del dashboard.
     * Añade checkboxes, contador de selección, barra de acciones
     * flotante y operaciones batch.
     */

    function initBulkActions() {
        // Detectar tablas con clase ltms-bulk-table
        document.addEventListener('change', (e) => {
            if (!e.target.matches('.ltms-bulk-checkbox')) return;

            const table = e.target.closest('.ltms-bulk-table');
            if (!table) return;

            updateBulkSelection(table);
        });

        // Select all
        document.addEventListener('change', (e) => {
            if (!e.target.matches('.ltms-bulk-select-all')) return;

            const table = e.target.closest('.ltms-bulk-table');
            if (!table) return;

            const checkboxes = table.querySelectorAll('.ltms-bulk-checkbox');
            checkboxes.forEach((cb) => {
                cb.checked = e.target.checked;
            });

            updateBulkSelection(table);
        });

        // Bulk action button
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-bulk-action]');
            if (!btn) return;

            const table = btn.closest('.ltms-bulk-table') || document.querySelector('.ltms-bulk-table');
            if (!table) return;

            const action = btn.dataset.bulkAction;
            const selected = Array.from(table.querySelectorAll('.ltms-bulk-checkbox:checked')).map((cb) => cb.value);

            if (!selected.length) {
                toast('warning', 'Sin selección', 'Selecciona al menos un elemento.');
                return;
            }

            // Disparar evento personalizado para que el código de la vista lo maneje
            const event = new CustomEvent('ltms:bulk-action', {
                detail: { action, ids: selected, table },
                bubbles: true,
            });
            table.dispatchEvent(event);

            announce(`Acción "${action}" ejecutada sobre ${selected.length} elemento(s).`);
        });
    }

    function updateBulkSelection(table) {
        const checkboxes = table.querySelectorAll('.ltms-bulk-checkbox');
        const checked = table.querySelectorAll('.ltms-bulk-checkbox:checked');
        const selectAll = table.querySelector('.ltms-bulk-select-all');

        if (selectAll) {
            selectAll.checked = checkboxes.length > 0 && checked.length === checkboxes.length;
            selectAll.indeterminate = checked.length > 0 && checked.length < checkboxes.length;
        }

        // Mostrar/ocultar barra de acciones
        let actionBar = table.parentElement.querySelector('.ltms-bulk-action-bar');
        if (checked.length > 0) {
            if (!actionBar) {
                actionBar = document.createElement('div');
                actionBar.className = 'ltms-bulk-action-bar';
                actionBar.innerHTML = `
                    <div class="ltms-bulk-info">
                        <span class="ltms-bulk-count">0</span> seleccionado(s)
                    </div>
                    <div class="ltms-bulk-actions"></div>
                    <button type="button" class="ltms-bulk-clear" aria-label="Limpiar selección">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                `;
                table.parentElement.insertBefore(actionBar, table);

                actionBar.querySelector('.ltms-bulk-clear').addEventListener('click', () => {
                    table.querySelectorAll('.ltms-bulk-checkbox:checked').forEach((cb) => {
                        cb.checked = false;
                    });
                    updateBulkSelection(table);
                });
            }

            actionBar.querySelector('.ltms-bulk-count').textContent = checked.length;
            actionBar.classList.add('visible');

            // Copiar acciones del template
            const actionsTemplate = table.querySelector('.ltms-bulk-actions-template');
            if (actionsTemplate && !actionBar.querySelector('.ltms-bulk-actions').children.length) {
                actionBar.querySelector('.ltms-bulk-actions').innerHTML = actionsTemplate.innerHTML;
            }
        } else if (actionBar) {
            actionBar.classList.remove('visible');
        }
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
            const zone = e.target.closest('.ltms-dropzone');
            if (!zone) return;

            e.preventDefault();
            zone.classList.add('ltms-dropzone-active');
        });

        document.addEventListener('dragleave', (e) => {
            const zone = e.target.closest('.ltms-dropzone');
            if (!zone) return;

            // Solo remover si salimos de la zona completamente
            if (!zone.contains(e.relatedTarget)) {
                zone.classList.remove('ltms-dropzone-active');
            }
        });

        document.addEventListener('drop', (e) => {
            const zone = e.target.closest('.ltms-dropzone');
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
            const target = e.target.closest('[data-help]');
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
    // 34. SMART NOTIFICATIONS — Filtros y prioridad
    // ═══════════════════════════════════════════════════════════

    /**
     * Mejora el panel de notificaciones con filtros por categoría,
     * prioridad visual y soporte para marcar como leída al hacer click.
     */

    const NOTIF_CATEGORIES = {
        order: { icon: '📦', label: 'Pedidos', color: '#3282B8' },
        payment: { icon: '💰', label: 'Pagos', color: '#16A34A' },
        kyc: { icon: '🪪', label: 'Verificación', color: '#F39C12' },
        shipping: { icon: '🚚', label: 'Envíos', color: '#8B5CF6' },
        system: { icon: '⚙️', label: 'Sistema', color: '#6B7280' },
    };

    const NOTIF_PRIORITY = {
        high: { label: 'Urgente', color: '#DC2626' },
        medium: { label: 'Importante', color: '#F59E0B' },
        low: { label: 'Info', color: '#6B7280' },
    };

    function enhanceNotificationItem(item) {
        if (!item || item.dataset.enhanced) return;
        item.dataset.enhanced = 'true';

        // Detectar categoría por contenido
        const text = (item.textContent || '').toLowerCase();
        let category = 'system';
        if (text.includes('pedido') || text.includes('orden')) category = 'order';
        else if (text.includes('pago') || text.includes('retiro') || text.includes('payout')) category = 'payment';
        else if (text.includes('kyc') || text.includes('verificación')) category = 'kyc';
        else if (text.includes('envío') || text.includes('guía')) category = 'shipping';

        const cat = NOTIF_CATEGORIES[category];
        if (cat) {
            item.dataset.category = category;
            item.style.borderLeftColor = cat.color;

            // Añadir icono de categoría si no tiene
            if (!item.querySelector('.ltms-notif-cat-icon')) {
                const icon = document.createElement('span');
                icon.className = 'ltms-notif-cat-icon';
                icon.textContent = cat.icon;
                icon.style.background = cat.color + '22';
                item.insertBefore(icon, item.firstChild);
            }
        }
    }

    function initSmartNotifications() {
        // Mejorar items existentes
        const enhance = () => {
            document.querySelectorAll('.ltms-notif-item:not([data-enhanced])').forEach(enhanceNotificationItem);
        };

        setTimeout(enhance, 2000);

        // Observer para nuevos items
        const notifList = document.getElementById('ltms-notif-list');
        if (notifList) {
            const observer = new MutationObserver(enhance);
            observer.observe(notifList, { childList: true, subtree: true });
        }

        // Filtros en el header del panel
        const panel = document.querySelector('.ltms-notifications-panel');
        if (panel && !panel.querySelector('.ltms-notif-filters')) {
            const header = panel.querySelector('.ltms-notif-header');
            if (header) {
                const filters = document.createElement('div');
                filters.className = 'ltms-notif-filters';
                filters.innerHTML = `
                    <button type="button" class="ltms-notif-filter active" data-filter="all">Todas</button>
                    <button type="button" class="ltms-notif-filter" data-filter="order">📦 Pedidos</button>
                    <button type="button" class="ltms-notif-filter" data-filter="payment">💰 Pagos</button>
                    <button type="button" class="ltms-notif-filter" data-filter="kyc">🪪 KYC</button>
                `;
                header.appendChild(filters);

                filters.addEventListener('click', (e) => {
                    const btn = e.target.closest('.ltms-notif-filter');
                    if (!btn) return;

                    filters.querySelectorAll('.ltms-notif-filter').forEach((b) => b.classList.remove('active'));
                    btn.classList.add('active');

                    const filter = btn.dataset.filter;
                    document.querySelectorAll('.ltms-notif-item').forEach((item) => {
                        if (filter === 'all' || item.dataset.category === filter) {
                            item.style.display = '';
                        } else {
                            item.style.display = 'none';
                        }
                    });
                });
            }
        }
    }

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
    // 36. ACTIVITY FEED — Timeline de actividad reciente
    // ═══════════════════════════════════════════════════════════

    /**
     * Timeline visual de actividad reciente del vendedor:
     * pedidos, pagos, retiros, KYC, productos, etc.
     * Se muestra como widget en el dashboard home.
     */

    function renderActivityFeed(activities) {
        if (!activities || !activities.length) {
            return `
                <div class="ltms-activity-empty">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.4;margin-bottom:8px;"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <p>Sin actividad reciente</p>
                </div>
            `;
        }

        const typeConfig = {
            order: { icon: '📦', color: '#3282B8', bg: '#DBEAFE' },
            payment: { icon: '💰', color: '#16A34A', bg: '#DCFCE7' },
            payout: { icon: '💸', color: '#F39C12', bg: '#FEF3C7' },
            kyc: { icon: '🪪', color: '#8B5CF6', bg: '#EDE9FE' },
            product: { icon: '🛍️', color: '#EC4899', bg: '#FCE7F3' },
            shipping: { icon: '🚚', color: '#06B6D4', bg: '#CFFAFE' },
            system: { icon: '⚙️', color: '#6B7280', bg: '#F3F4F6' },
        };

        return activities.map((act) => {
            const config = typeConfig[act.type] || typeConfig.system;
            return `
                <div class="ltms-activity-item" data-type="${act.type}">
                    <div class="ltms-activity-icon" style="background:${config.bg};color:${config.color};">
                        ${config.icon}
                    </div>
                    <div class="ltms-activity-content">
                        <div class="ltms-activity-title">${escapeHtml(act.title)}</div>
                        <div class="ltms-activity-desc">${escapeHtml(act.description || '')}</div>
                        <div class="ltms-activity-time">${escapeHtml(act.time || '')}</div>
                    </div>
                    ${act.amount ? `<div class="ltms-activity-amount ${act.amountType || ''}">${escapeHtml(act.amount)}</div>` : ''}
                </div>
            `;
        }).join('');
    }

    function loadActivityFeed() {
        const container = document.querySelector('#ltms-activity-feed');
        if (!container) return;

        container.innerHTML = '<div class="ltms-activity-loading"><div class="ltms-spinner-lg"></div></div>';

        if (typeof jQuery === 'undefined' || typeof ltmsDashboard === 'undefined') {
            // Fallback: datos de ejemplo
            container.innerHTML = renderActivityFeed([
                { type: 'order', title: 'Nuevo pedido #1024', description: 'Camiseta azul talla M', time: 'Hace 5 min', amount: '+$45.000', amountType: 'positive' },
                { type: 'payment', title: 'Pago recibido', description: 'Pedido #1023 completado', time: 'Hace 1 hora', amount: '+$89.000', amountType: 'positive' },
                { type: 'payout', title: 'Retiro solicitado', description: 'Transferencia bancaria', time: 'Hace 2 horas', amount: '-$200.000', amountType: 'negative' },
                { type: 'kyc', title: 'KYC aprobado', description: 'Tu identidad fue verificada', time: 'Ayer', amount: '' },
            ]);
            return;
        }

        jQuery.post(ltmsDashboard.ajax_url, {
            action: 'ltms_get_activity_feed',
            nonce: ltmsDashboard.nonce,
            limit: 10,
        }, (response) => {
            if (response.success && response.data && response.data.activities) {
                container.innerHTML = renderActivityFeed(response.data.activities);
            } else {
                container.innerHTML = renderActivityFeed([]);
            }
        }).fail(() => {
            container.innerHTML = renderActivityFeed([]);
        });
    }

    function initActivityFeed() {
        if (!document.querySelector('.ltms-dashboard-container')) return;

        // Crear widget de activity feed en home si no existe
        const insertActivityFeed = () => {
            const homeView = document.querySelector('#ltms-view-home .ltms-home-grid, #ltms-view-home');
            if (!homeView || homeView.querySelector('#ltms-activity-feed')) return;

            const widget = document.createElement('div');
            widget.className = 'ltms-card ltms-activity-card';
            widget.innerHTML = `
                <div class="ltms-card-header">
                    <div class="ltms-card-header-title">
                        <h3>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            Actividad reciente
                        </h3>
                    </div>
                    <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-activity-refresh" aria-label="Actualizar actividad">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    </button>
                </div>
                <div class="ltms-card-body ltms-activity-body" id="ltms-activity-feed">
                    <div class="ltms-activity-loading"><div class="ltms-spinner-lg"></div></div>
                </div>
            `;
            homeView.appendChild(widget);

            widget.querySelector('.ltms-activity-refresh').addEventListener('click', loadActivityFeed);

            setTimeout(loadActivityFeed, 800);
        };

        setTimeout(insertActivityFeed, 1500);
        if (typeof jQuery !== 'undefined') {
            jQuery(document).on('ltms:view:loaded', (e, view) => {
                if (view === 'home') setTimeout(insertActivityFeed, 300);
            });
        }
    }

    // ═══════════════════════════════════════════════════════════
    // 37. DASHBOARD CUSTOMIZATION — Drag & drop de widgets
    // ═══════════════════════════════════════════════════════════

    /**
     * Permite al vendedor reordenar widgets del dashboard via drag & drop.
     * El orden se persiste en localStorage.
     */

    function initDashboardCustomization() {
        if (!document.querySelector('.ltms-dashboard-container')) return;
        if (!('ondragstart' in window)) return;

        let draggedEl = null;
        let placeholder = null;

        const createPlaceholder = (height) => {
            placeholder = document.createElement('div');
            placeholder.className = 'ltms-widget-placeholder';
            placeholder.style.height = height + 'px';
        };

        document.addEventListener('dragstart', (e) => {
            const widget = e.target.closest('.ltms-draggable-widget');
            if (!widget) return;

            draggedEl = widget;
            widget.classList.add('ltms-widget-dragging');

            createPlaceholder(widget.offsetHeight);
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', '');
        });

        document.addEventListener('dragend', (e) => {
            const widget = e.target.closest('.ltms-draggable-widget');
            if (widget) widget.classList.remove('ltms-widget-dragging');

            if (placeholder && placeholder.parentNode) {
                placeholder.parentNode.removeChild(placeholder);
            }
            placeholder = null;
            draggedEl = null;

            saveWidgetOrder();
        });

        document.addEventListener('dragover', (e) => {
            if (!draggedEl) return;

            e.preventDefault();
            const container = e.target.closest('.ltms-home-grid, .ltms-view-section');
            if (!container) return;

            const afterElement = getDragAfterElement(container, e.clientY);
            if (afterElement == null) {
                container.appendChild(placeholder);
            } else {
                container.insertBefore(placeholder, afterElement);
            }
        });

        document.addEventListener('drop', (e) => {
            if (!draggedEl || !placeholder) return;
            e.preventDefault();

            placeholder.parentNode.insertBefore(draggedEl, placeholder);
        });

        function getDragAfterElement(container, y) {
            const els = [...container.querySelectorAll('.ltms-draggable-widget:not(.ltms-widget-dragging)')];

            return els.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) {
                    return { offset, element: child };
                }
                return closest;
            }, { offset: -Infinity }).element;
        }

        function saveWidgetOrder() {
            const container = document.querySelector('.ltms-home-grid, .ltms-view-section');
            if (!container) return;

            const order = [...container.querySelectorAll('.ltms-draggable-widget')].map((el) => el.dataset.widgetId || el.id || el.className);
            try {
                localStorage.setItem('ltms-widget-order', JSON.stringify(order));
            } catch (e) { /* noop */ }
        }

        function restoreWidgetOrder() {
            try {
                const saved = localStorage.getItem('ltms-widget-order');
                if (!saved) return;

                const order = JSON.parse(saved);
                const container = document.querySelector('.ltms-home-grid, .ltms-view-section');
                if (!container) return;

                order.forEach((id) => {
                    const el = container.querySelector(`[data-widget-id="${id}"], #${id}`);
                    if (el) container.appendChild(el);
                });
            } catch (e) { /* noop */ }
        }

        // Restaurar orden al cargar
        setTimeout(restoreWidgetOrder, 2000);
    }

    // ═══════════════════════════════════════════════════════════
    // 38. CHART HELPERS — Wrappers para visualización de datos
    // ═══════════════════════════════════════════════════════════

    /**
     * Helpers para crear gráficos consistentes con el design system.
     * Envuelve Chart.js con colores y opciones predefinidas.
     */

    const CHART_COLORS = {
        primary: '#0F4C75',
        secondary: '#3282B8',
        accent: '#F39C12',
        success: '#16A34A',
        danger: '#DC2626',
        info: '#2563EB',
        purple: '#8B5CF6',
        pink: '#EC4899',
    };

    const CHART_GRADIENTS = {};

    function createGradient(ctx, color1, color2) {
        const gradient = ctx.createLinearGradient(0, 0, 0, 300);
        gradient.addColorStop(0, color1 + '40');
        gradient.addColorStop(1, color2 + '00');
        return gradient;
    }

    function createLineChart(canvas, data, options = {}) {
        if (typeof Chart === 'undefined' || !canvas) return null;
        const ctx = canvas.getContext('2d');

        const datasets = data.datasets.map((ds, i) => {
            const color = ds.color || Object.values(CHART_COLORS)[i];
            return {
                ...ds,
                borderColor: color,
                backgroundColor: ds.fill ? createGradient(ctx, color, color) : 'transparent',
                tension: 0.4,
                borderWidth: 2.5,
                pointRadius: 0,
                pointHoverRadius: 6,
                pointHoverBackgroundColor: color,
                pointHoverBorderColor: '#fff',
                pointHoverBorderWidth: 2,
            };
        });

        return new Chart(canvas, {
            type: 'line',
            data: { labels: data.labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display: options.legend !== false,
                        position: 'top',
                        align: 'end',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 16,
                            font: { size: 12, weight: '500' },
                            color: '#374151',
                        },
                    },
                    tooltip: {
                        backgroundColor: '#1F2937',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        borderColor: '#374151',
                        borderWidth: 1,
                        padding: 12,
                        cornerRadius: 8,
                        displayColors: true,
                        usePointStyle: true,
                        titleFont: { size: 13, weight: '700' },
                        bodyFont: { size: 12 },
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: '#6B7280', font: { size: 11 } },
                        border: { color: '#E5E7EB' },
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#F3F4F6', drawBorder: false },
                        ticks: { color: '#6B7280', font: { size: 11 } },
                        border: { display: false },
                    },
                },
                ...options,
            },
        });
    }

    function createBarChart(canvas, data, options = {}) {
        if (typeof Chart === 'undefined' || !canvas) return null;
        const ctx = canvas.getContext('2d');

        const datasets = data.datasets.map((ds, i) => {
            const color = ds.color || Object.values(CHART_COLORS)[i];
            return {
                ...ds,
                backgroundColor: color,
                borderColor: color,
                borderWidth: 0,
                borderRadius: 6,
                borderSkipped: false,
            };
        });

        return new Chart(canvas, {
            type: 'bar',
            data: { labels: data.labels, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false, ...options.legend },
                    tooltip: {
                        backgroundColor: '#1F2937',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        padding: 12,
                        cornerRadius: 8,
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: '#6B7280', font: { size: 11 } },
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#F3F4F6', drawBorder: false },
                        ticks: { color: '#6B7280', font: { size: 11 } },
                        border: { display: false },
                    },
                },
                ...options,
            },
        });
    }

    function createDoughnutChart(canvas, data, options = {}) {
        if (typeof Chart === 'undefined' || !canvas) return null;

        const colors = data.datasets[0].colors || Object.values(CHART_COLORS);

        return new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: data.labels,
                datasets: data.datasets.map((ds, i) => ({
                    ...ds,
                    backgroundColor: colors,
                    borderColor: '#fff',
                    borderWidth: 3,
                    hoverOffset: 8,
                })),
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 14,
                            font: { size: 12 },
                            color: '#374151',
                        },
                    },
                    tooltip: {
                        backgroundColor: '#1F2937',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        padding: 12,
                        cornerRadius: 8,
                        callbacks: {
                            label: (ctx) => {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = ((ctx.parsed / total) * 100).toFixed(1);
                                return ctx.label + ': ' + ctx.parsed + ' (' + pct + '%)';
                            },
                        },
                    },
                },
                ...options,
            },
        });
    }

    LTMS.UX.charts = {
        colors: CHART_COLORS,
        line: createLineChart,
        bar: createBarChart,
        doughnut: createDoughnutChart,
    };

    // ═══════════════════════════════════════════════════════════
    // 39. REAL-TIME UPDATES — Polling inteligente
    // ═══════════════════════════════════════════════════════════

    /**
     * Sistema de actualizaciones en tiempo real via polling adaptativo.
     * Aumenta la frecuencia cuando hay actividad y la reduce cuando
     * el usuario está inactivo.
     */

    const realtimeState = {
        active: false,
        interval: 30000, // 30s por defecto
        minInterval: 10000, // 10s cuando hay actividad
        maxInterval: 120000, // 2min cuando inactivo
        lastActivity: Date.now(),
        timer: null,
        handlers: {},
    };

    function registerRealtimeHandler(name, handler) {
        realtimeState.handlers[name] = handler;
    }

    function unregisterRealtimeHandler(name) {
        delete realtimeState.handlers[name];
    }

    function startRealtimeUpdates() {
        if (realtimeState.active) return;
        realtimeState.active = true;

        // Detectar actividad del usuario
        ['click', 'keydown', 'scroll', 'touchstart'].forEach((evt) => {
            document.addEventListener(evt, () => {
                realtimeState.lastActivity = Date.now();
            }, { passive: true });
        });

        function poll() {
            if (!realtimeState.active) return;

            const idleTime = Date.now() - realtimeState.lastActivity;
            if (idleTime > 60000) {
                // Usuario inactivo >1min: reducir frecuencia
                realtimeState.interval = Math.min(realtimeState.interval * 1.5, realtimeState.maxInterval);
            } else {
                // Usuario activo: frecuencia normal
                realtimeState.interval = realtimeState.minInterval;
            }

            // Ejecutar handlers
            Object.values(realtimeState.handlers).forEach((handler) => {
                try { handler(); } catch (e) { console.error('[LTMS.UX] Realtime handler error:', e); }
            });

            realtimeState.timer = setTimeout(poll, realtimeState.interval);
        }

        poll();
    }

    function stopRealtimeUpdates() {
        realtimeState.active = false;
        if (realtimeState.timer) {
            clearTimeout(realtimeState.timer);
            realtimeState.timer = null;
        }
    }

    LTMS.UX.realtime = {
        start: startRealtimeUpdates,
        stop: stopRealtimeUpdates,
        register: registerRealtimeHandler,
        unregister: unregisterRealtimeHandler,
    };

    function initRealtimeUpdates() {
        if (!document.querySelector('.ltms-dashboard-container')) return;

        // Solo activar si la página está visible
        if (document.hidden) {
            document.addEventListener('visibilitychange', () => {
                if (!document.hidden) startRealtimeUpdates();
                else stopRealtimeUpdates();
            });
        } else {
            startRealtimeUpdates();
        }

        // Pausar cuando la pestaña no está visible
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                stopRealtimeUpdates();
            } else {
                startRealtimeUpdates();
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
            const link = e.target.closest('a[href]');
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
            const btn = e.target.closest('[data-export-table]');
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
            const trigger = e.target.closest('[data-lightbox]');
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
    // 49. NOTIFICATION CENTER — Centro con historial y settings
    // ═══════════════════════════════════════════════════════════

    /**
     * Centro de notificaciones avanzado:
     * - Historial persistente
     * - Filtros por categoría
     * - Marcar como leída individual
     * - Acciones rápidas (archivar, eliminar)
     * - Settings de preferencias
     */

    const notifCenter = {
        notifications: [],
        settings: {
            sound: true,
            desktop: false,
            categories: {
                order: true,
                payment: true,
                kyc: true,
                shipping: true,
                system: true,
            },
        },
    };

    function loadNotifSettings() {
        try {
            const saved = localStorage.getItem('ltms-notif-settings');
            if (saved) notifCenter.settings = { ...notifCenter.settings, ...JSON.parse(saved) };
        } catch (e) {}
    }

    function saveNotifSettings() {
        try {
            localStorage.setItem('ltms-notif-settings', JSON.stringify(notifCenter.settings));
        } catch (e) {}
    }

    function sendDesktopNotification(title, options = {}) {
        if (!notifCenter.settings.desktop) return;
        if (!('Notification' in window)) return;
        if (Notification.permission !== 'granted') return;

        try {
            new Notification(title, {
                body: options.body || '',
                icon: options.icon || '',
                tag: options.tag || 'ltms',
                ...options,
            });
        } catch (e) {}
    }

    function requestNotificationPermission() {
        if (!('Notification' in window)) {
            toast('warning', 'No soportado', 'Tu navegador no soporta notificaciones de escritorio.');
            return;
        }
        Notification.requestPermission().then((permission) => {
            if (permission === 'granted') {
                notifCenter.settings.desktop = true;
                saveNotifSettings();
                toast('success', 'Permitido', 'Recibirás notificaciones de escritorio.');
            } else {
                notifCenter.settings.desktop = false;
                saveNotifSettings();
                toast('info', 'Denegado', 'No recibirás notificaciones de escritorio.');
            }
        });
    }

    LTMS.UX.sendDesktopNotification = sendDesktopNotification;
    LTMS.UX.requestNotificationPermission = requestNotificationPermission;

    // ═══════════════════════════════════════════════════════════
    // 50. ANALYTICS DASHBOARD — Visualizaciones avanzadas
    // ═══════════════════════════════════════════════════════════

    /**
     * Dashboard de analytics con:
     * - Métricas con sparklines
     * - Gráficos de tendencia
     * - Top productos
     * - Distribución geográfica
     * - Comparación de periodos
     */

    function createSparkline(canvas, data, color = '#0F4C75') {
        if (typeof Chart === 'undefined' || !canvas || !data || !data.length) return null;

        return new Chart(canvas, {
            type: 'line',
            data: {
                labels: data.map((_, i) => i),
                datasets: [{
                    data: data,
                    borderColor: color,
                    backgroundColor: color + '20',
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 4,
                    tension: 0.4,
                    fill: true,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: '#1F2937',
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        padding: 8,
                        cornerRadius: 6,
                        displayColors: false,
                    },
                },
                scales: {
                    x: { display: false },
                    y: { display: false },
                },
                elements: { point: { radius: 0 } },
            },
        });
    }

    function createMetricCard(config) {
        const card = document.createElement('div');
        card.className = 'ltms-card ltms-analytics-metric-card';
        card.innerHTML = `
            <div class="ltms-card-body" style="padding:16px;">
                <div class="ltms-analytics-metric-header">
                    <div class="ltms-analytics-metric-icon" style="background:${config.iconBg || '#DBEAFE'};color:${config.iconColor || '#2563EB'};">
                        ${config.icon || ''}
                    </div>
                    ${config.trend !== undefined ? `
                        <span class="ltms-metric-trend ${config.trend >= 0 ? 'up' : 'down'}">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                ${config.trend >= 0
                                    ? '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>'
                                    : '<polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/>'}
                            </svg>
                            ${Math.abs(config.trend).toFixed(1)}%
                        </span>
                    ` : ''}
                </div>
                <div class="ltms-analytics-metric-value">${escapeHtml(config.value || '—')}</div>
                <div class="ltms-analytics-metric-label">${escapeHtml(config.label || '')}</div>
                ${config.sparkline ? `<div class="ltms-analytics-sparkline"><canvas width="100" height="30"></canvas></div>` : ''}
            </div>
        `;

        if (config.sparkline) {
            const canvas = card.querySelector('canvas');
            setTimeout(() => createSparkline(canvas, config.sparkline, config.sparklineColor || config.iconColor), 100);
        }

        return card;
    }

    function createTopProductsList(products) {
        const container = document.createElement('div');
        container.className = 'ltms-top-products';
        container.innerHTML = products.map((p, i) => `
            <div class="ltms-top-product-item">
                <div class="ltms-top-product-rank">${i + 1}</div>
                <div class="ltms-top-product-info">
                    <div class="ltms-top-product-name">${escapeHtml(p.name)}</div>
                    <div class="ltms-top-product-sales">${p.sales} ventas</div>
                </div>
                <div class="ltms-top-product-revenue">${escapeHtml(p.revenue)}</div>
                <div class="ltms-top-product-bar" style="width:${(p.sales / products[0].sales * 100)}%;background:${p.color || '#3282B8'};"></div>
            </div>
        `).join('');
        return container;
    }

    LTMS.UX.analytics = {
        createSparkline,
        createMetricCard,
        createTopProductsList,
    };

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
    // 53. CART DRAWER — Carrito deslizable lateral
    // ═══════════════════════════════════════════════════════════

    /**
     * Carrito de compras tipo drawer que se desliza desde la derecha.
     * Permite ver y modificar el carrito sin abandonar la página.
     */

    let cartDrawerState = {
        drawer: null,
        overlay: null,
        cleanup: null,
    };

    function openCartDrawer() {
        if (cartDrawerState.drawer) return;

        cartDrawerState.overlay = document.createElement('div');
        cartDrawerState.overlay.className = 'ltms-cart-drawer-overlay';

        cartDrawerState.drawer = document.createElement('aside');
        cartDrawerState.drawer.className = 'ltms-cart-drawer';
        cartDrawerState.drawer.setAttribute('role', 'dialog');
        cartDrawerState.drawer.setAttribute('aria-modal', 'true');
        cartDrawerState.drawer.setAttribute('aria-label', 'Carrito de compras');

        cartDrawerState.drawer.innerHTML = `
            <div class="ltms-cart-drawer-header">
                <h3 class="ltms-cart-drawer-title">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                    Tu carrito
                </h3>
                <button type="button" class="ltms-cart-drawer-close" aria-label="Cerrar carrito">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="ltms-cart-drawer-body" id="ltms-cart-drawer-items">
                <div class="ltms-cart-drawer-loading">
                    <div class="ltms-spinner-lg"></div>
                </div>
            </div>
            <div class="ltms-cart-drawer-footer">
                <div class="ltms-cart-drawer-subtotal">
                    <span>Subtotal</span>
                    <span class="ltms-cart-drawer-subtotal-value" id="ltms-cart-subtotal">$0</span>
                </div>
                <div class="ltms-cart-drawer-shipping">
                    <span>Envío</span>
                    <span id="ltms-cart-shipping">A calcular</span>
                </div>
                <button type="button" class="ltms-btn ltms-btn-primary ltms-btn-full ltms-cart-drawer-checkout" id="ltms-cart-checkout-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    Finalizar compra
                </button>
                <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-full ltms-cart-drawer-continue">
                    Seguir comprando
                </button>
            </div>
        `;

        document.body.appendChild(cartDrawerState.overlay);
        document.body.appendChild(cartDrawerState.drawer);
        document.body.style.overflow = 'hidden';

        requestAnimationFrame(() => {
            cartDrawerState.overlay.classList.add('visible');
            cartDrawerState.drawer.classList.add('open');
        });

        // Event handlers
        cartDrawerState.drawer.querySelector('.ltms-cart-drawer-close').addEventListener('click', closeCartDrawer);
        cartDrawerState.drawer.querySelector('.ltms-cart-drawer-continue').addEventListener('click', closeCartDrawer);
        cartDrawerState.overlay.addEventListener('click', closeCartDrawer);

        cartDrawerState.drawer.querySelector('#ltms-cart-checkout-btn').addEventListener('click', () => {
            window.location.href = '/checkout/';
        });

        // Keyboard
        const keyHandler = (e) => { if (e.key === 'Escape') closeCartDrawer(); };
        document.addEventListener('keydown', keyHandler);

        cartDrawerState.cleanup = () => {
            document.removeEventListener('keydown', keyHandler);
        };

        // Load cart contents
        loadCartContents();
        announce('Carrito abierto');
    }

    function closeCartDrawer() {
        if (!cartDrawerState.drawer) return;
        if (cartDrawerState.cleanup) cartDrawerState.cleanup();

        cartDrawerState.overlay.classList.remove('visible');
        cartDrawerState.drawer.classList.remove('open');
        document.body.style.overflow = '';

        setTimeout(() => {
            if (cartDrawerState.overlay && cartDrawerState.overlay.parentNode) cartDrawerState.overlay.parentNode.removeChild(cartDrawerState.overlay);
            if (cartDrawerState.drawer && cartDrawerState.drawer.parentNode) cartDrawerState.drawer.parentNode.removeChild(cartDrawerState.drawer);
            cartDrawerState.drawer = null;
            cartDrawerState.overlay = null;
            cartDrawerState.cleanup = null;
        }, 300);
    }

    function loadCartContents() {
        const itemsContainer = document.querySelector('#ltms-cart-drawer-items');
        if (!itemsContainer) return;

        // Task 67-A / UX-CART-1 FIX: previously POSTed to the
        // `woocommerce_get_cart_contents` AJAX action — which does NOT exist
        // in WooCommerce core (WC only exposes `get_refreshed_fragments`,
        // `add_to_cart`, `remove_from_cart`, `apply_coupon`…). Every request
        // 404'd / returned `-1`, the success branch never ran, and the drawer
        // always showed "Tu carrito está vacío" even when the cart had items.
        //
        // We now call the LTMS custom endpoint `ltms_get_cart` (registered in
        // class-ltms-frontend-checkout-handler.php) which returns structured
        // cart contents in the exact shape renderCartItems() expects. Falls
        // back to WC's `get_refreshed_fragments` (HTML fragments) only as a
        // last resort when `ltmsUX` is unavailable (older pages cached).

        const sendGetCart = () => {
            const ajaxUrl = (typeof ltmsUX !== 'undefined' && ltmsUX.ajax_url)
                || (typeof wc_cart_fragments_params !== 'undefined' && wc_cart_fragments_params.ajax_url)
                || (typeof ltmsCheckout !== 'undefined' && ltmsCheckout.ajax_url)
                || '';
            if (!ajaxUrl) {
                renderCartEmpty(itemsContainer);
                return;
            }
            const body = new URLSearchParams();
            body.append('action', 'ltms_get_cart');
            if (typeof ltmsUX !== 'undefined' && ltmsUX.nonce) {
                body.append('nonce', ltmsUX.nonce);
            }
            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString(),
            })
                .then((r) => r.json())
                .then((response) => {
                    if (response && response.success && response.data) {
                        renderCartItems(itemsContainer, response.data);
                    } else {
                        renderCartEmpty(itemsContainer);
                    }
                })
                .catch(() => {
                    // Final fallback: WC's get_refreshed_fragments returns HTML
                    // fragments, not structured data. We can't reliably parse
                    // items from it, so show the empty state with a link to the
                    // full cart page (renderCartEmpty already provides this).
                    renderCartEmpty(itemsContainer);
                });
        };

        if (typeof jQuery !== 'undefined') {
            // jQuery wrapper so we keep the same fail-hard semantics as the
            // original code (renderCartEmpty on any error).
            try {
                sendGetCart();
            } catch (e) {
                renderCartEmpty(itemsContainer);
            }
        } else if (typeof fetch !== 'undefined') {
            sendGetCart();
        } else {
            // No AJAX available — show empty state.
            setTimeout(() => renderCartEmpty(itemsContainer), 500);
        }
    }

    function renderCartItems(container, data) {
        if (!data.items || !data.items.length) {
            renderCartEmpty(container);
            return;
        }

        // v2.9.36: NO usar escapeHtml en price_formatted ni total_formatted
        // porque ya vienen sanitizados con wp_strip_all_tags() desde PHP.
        // escapeHtml convierte &#36; → &amp;#36; rompiendo los precios.
        container.innerHTML = data.items.map((item) => `
            <div class="ltms-cart-item" data-cart-item-key="${escapeHtml(item.key)}">
                <div class="ltms-cart-item-img">
                    ${item.image ? `<img src="${escapeHtml(item.image)}" alt="${escapeHtml(item.name)}" loading="lazy">` : '<div class="ltms-cart-item-no-img">📦</div>'}
                </div>
                <div class="ltms-cart-item-info">
                    <a href="${escapeHtml(item.product_url || '#')}" class="ltms-cart-item-name">${escapeHtml(item.name)}</a>
                    ${item.variation ? `<div class="ltms-cart-item-variation">${escapeHtml(item.variation)}</div>` : ''}
                    <div class="ltms-cart-item-price">${item.price_formatted || ''}</div>
                    <div class="ltms-cart-item-qty">
                        <button type="button" class="ltms-cart-qty-btn ltms-cart-qty-dec" data-key="${escapeHtml(item.key)}" aria-label="Disminuir">−</button>
                        <span class="ltms-cart-qty-value">${item.quantity}</span>
                        <button type="button" class="ltms-cart-qty-btn ltms-cart-qty-inc" data-key="${escapeHtml(item.key)}" aria-label="Aumentar">+</button>
                    </div>
                </div>
                <button type="button" class="ltms-cart-item-remove" data-key="${escapeHtml(item.key)}" aria-label="Eliminar">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                </button>
            </div>
        `).join('');

        const subtotalEl = document.querySelector('#ltms-cart-subtotal');
        if (subtotalEl && data.total_formatted) {
            subtotalEl.textContent = data.total_formatted;
        }

        // Bind qty buttons
        container.querySelectorAll('.ltms-cart-qty-inc').forEach((btn) => {
            btn.addEventListener('click', () => updateCartQty(btn.dataset.key, 1));
        });
        container.querySelectorAll('.ltms-cart-qty-dec').forEach((btn) => {
            btn.addEventListener('click', () => updateCartQty(btn.dataset.key, -1));
        });
        container.querySelectorAll('.ltms-cart-item-remove').forEach((btn) => {
            btn.addEventListener('click', () => removeCartItem(btn.dataset.key));
        });
    }

    function renderCartEmpty(container) {
        container.innerHTML = `
            <div class="ltms-cart-empty">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.3;margin-bottom:12px;"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                <p>Tu carrito está vacío</p>
                <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" onclick="LTMS.UX.closeCartDrawer()">Explorar productos</button>
            </div>
        `;
        const subtotalEl = document.querySelector('#ltms-cart-subtotal');
        if (subtotalEl) subtotalEl.textContent = '$0';
    }

    function updateCartQty(key, change) {
        // v2.9.36: usar ltms_get_cart para refrescar después de actualizar.
        // Primero obtener la cantidad actual, luego actualizar via WC.
        const ajaxUrl = (typeof ltmsUX !== 'undefined' && ltmsUX.ajax_url)
            || (typeof wc_cart_fragments_params !== 'undefined' && wc_cart_fragments_params.ajax_url)
            || '/wp-admin/admin-ajax.php';

        // Buscar la cantidad actual en el DOM
        const qtyEl = document.querySelector(`[data-cart-item-key="${key}"] .ltms-cart-qty-value`);
        const currentQty = qtyEl ? parseInt(qtyEl.textContent) : 1;
        const newQty = Math.max(1, currentQty + change);

        // Usar WooCommerce's built-in cart update via fragments
        if (typeof jQuery !== 'undefined' && typeof wc_add_to_cart_params !== 'undefined') {
            jQuery.post(ajaxUrl, {
                action: 'woocommerce_set_cart_quantity',
                cart_key: key,
                cart_quantity: newQty,
            }, () => {
                loadCartContents();
                announce('Carrito actualizado');
            }).fail(() => {
                // Fallback: recargar datos del carrito
                loadCartContents();
            });
        } else {
            // Sin jQuery, usar fetch
            const body = new URLSearchParams();
            body.append('action', 'ltms_drawer_update_qty');
            body.append('nonce', (typeof ltmsUX !== 'undefined' && ltmsUX.nonce) || '');
            body.append('cart_item_key', key);
            body.append('qty', newQty);
            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString(),
            }).then(() => {
                loadCartContents();
                announce('Carrito actualizado');
            }).catch(() => {
                loadCartContents();
            });
        }
    }

    function removeCartItem(key) {
        const ajaxUrl = (typeof ltmsUX !== 'undefined' && ltmsUX.ajax_url)
            || (typeof wc_cart_fragments_params !== 'undefined' && wc_cart_fragments_params.ajax_url)
            || '/wp-admin/admin-ajax.php';

        // v2.9.36: usar ltms_drawer_remove_item (soporta guests) en vez de
        // woocommerce_remove_cart_item (requiere nonce de WC que puede no estar).
        const body = new URLSearchParams();
        body.append('action', 'ltms_drawer_remove_item');
        body.append('nonce', (typeof ltmsUX !== 'undefined' && ltmsUX.nonce) || '');
        body.append('cart_item_key', key);
        fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
        }).then(() => {
            loadCartContents();
            announce('Producto eliminado del carrito');
        }).catch(() => {
            loadCartContents();
        });
    }

    function initCartDrawer() {
        // v2.9.39: Hook en botones de carrito del marketplace.
        // EXCLUIR Elementor cart (elementor-menu-cart) para no romper su funcionamiento.
        document.addEventListener('click', (e) => {
            // NO interceptar clicks en el carrito de Elementor
            if (e.target.closest('.elementor-menu-cart__toggle_button, .elementor-menu-cart__wrapper, #elementor-menu-cart__toggle_button')) {
                return; // Dejar que Elementor maneje su propio carrito
            }

            const cartTrigger = e.target.closest(
                '.ltms-sf-topbar-cart, .ltms-cart-trigger, [data-cart-drawer], ' +
                '.ltms-header-cart, .ltms-cart-icon, .ltms-cart-link'
            );
            if (!cartTrigger) return;

            e.preventDefault();
            e.stopPropagation();
            openCartDrawer();
        });

        // Actualizar contador del carrito cuando cambia
        if (typeof jQuery !== 'undefined') {
            jQuery(document.body).on('updated_cart_totals', () => {
                if (cartDrawerState.drawer) loadCartContents();
            });
            jQuery(document.body).on('added_to_cart', () => {
                if (cartDrawerState.drawer) loadCartContents();
            });
        }
    }

    LTMS.UX.openCartDrawer = openCartDrawer;
    LTMS.UX.closeCartDrawer = closeCartDrawer;

    // ═══════════════════════════════════════════════════════════
    // 54. IMAGE CROPPER — Recorte de imágenes antes de subir
    // ═══════════════════════════════════════════════════════════

    /**
     * Permite recortar imágenes antes de subirlas (productos, KYC, etc.)
     * Usa canvas para el recorte, sin librerías externas.
     */

    function openImageCropper(imageSrc, options = {}) {
        const aspectRatio = options.aspectRatio || null; // ej: 1 (cuadrado), 4/3, 16/9
        const minWidth = options.minWidth || 100;
        const minHeight = options.minHeight || 100;
        const outputWidth = options.outputWidth || 600;
        const outputHeight = options.outputHeight || (aspectRatio ? outputWidth / aspectRatio : 600);

        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay ltms-cropper-overlay';
        overlay.innerHTML = `
            <div class="ltms-modal ltms-cropper-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-cropper-title">
                <div class="ltms-modal-header">
                    <h3 class="ltms-modal-title" id="ltms-cropper-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2v14a2 2 0 0 0 2 2h14"/><path d="M18 22V8a2 2 0 0 0-2-2H2"/></svg>
                        Recortar imagen
                    </h3>
                    <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ltms-modal-body ltms-cropper-body">
                    <div class="ltms-cropper-container" id="ltms-cropper-container">
                        <img src="${escapeHtml(imageSrc)}" id="ltms-cropper-img" alt="Imagen a recortar" style="max-width:100%;display:block;">
                        <div class="ltms-cropper-overlay-box" id="ltms-cropper-box"></div>
                    </div>
                    <div class="ltms-cropper-controls">
                        <label class="ltms-cropper-zoom-label">
                            <span>Zoom</span>
                            <input type="range" id="ltms-cropper-zoom" min="50" max="200" value="100">
                        </label>
                        ${aspectRatio ? `<span class="ltms-cropper-aspect">Proporción ${aspectRatio.toFixed(2)}:1</span>` : ''}
                    </div>
                </div>
                <div class="ltms-modal-footer">
                    <button type="button" class="ltms-btn ltms-btn-outline ltms-modal-close">Cancelar</button>
                    <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-cropper-apply">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        Aplicar recorte
                    </button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('ltms-modal-open'));

        const img = overlay.querySelector('#ltms-cropper-img');
        const box = overlay.querySelector('#ltms-cropper-box');
        const container = overlay.querySelector('#ltms-cropper-container');
        const zoomInput = overlay.querySelector('#ltms-cropper-zoom');

        let scale = 1;
        let boxX = 0, boxY = 0, boxW = 0, boxH = 0;
        let isDragging = false;
        let dragStartX = 0, dragStartY = 0;

        function initCropBox() {
            const imgRect = img.getBoundingClientRect();
            const containerRect = container.getBoundingClientRect();

            if (aspectRatio) {
                boxW = Math.min(imgRect.width * 0.8, imgRect.height * 0.8 * aspectRatio);
                boxH = boxW / aspectRatio;
            } else {
                boxW = imgRect.width * 0.7;
                boxH = imgRect.height * 0.7;
            }

            boxX = (imgRect.width - boxW) / 2;
            boxY = (imgRect.height - boxH) / 2;

            updateBox();
        }

        function updateBox() {
            box.style.left = boxX + 'px';
            box.style.top = boxY + 'px';
            box.style.width = boxW + 'px';
            box.style.height = boxH + 'px';
        }

        img.onload = initCropBox;

        // Drag del crop box
        box.addEventListener('mousedown', (e) => {
            isDragging = true;
            dragStartX = e.clientX - boxX;
            dragStartY = e.clientY - boxY;
            e.preventDefault();
        });

        document.addEventListener('mousemove', (e) => {
            if (!isDragging) return;
            const imgRect = img.getBoundingClientRect();
            boxX = Math.max(0, Math.min(e.clientX - dragStartX, imgRect.width - boxW));
            boxY = Math.max(0, Math.min(e.clientY - dragStartY, imgRect.height - boxH));
            updateBox();
        });

        document.addEventListener('mouseup', () => { isDragging = false; });

        // Zoom
        zoomInput.addEventListener('input', (e) => {
            scale = e.target.value / 100;
            img.style.transform = `scale(${scale})`;
        });

        const cleanup = trapFocus(overlay.querySelector('.ltms-modal'));

        const close = () => {
            cleanup();
            overlay.classList.remove('ltms-modal-open');
            setTimeout(() => overlay.remove(), 250);
        };

        overlay.querySelectorAll('.ltms-modal-close').forEach((btn) => btn.addEventListener('click', close));
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

        // Apply crop
        overlay.querySelector('#ltms-cropper-apply').addEventListener('click', () => {
            const canvas = document.createElement('canvas');
            canvas.width = outputWidth;
            canvas.height = outputHeight;
            const ctx = canvas.getContext('2d');

            const imgRect = img.getBoundingClientRect();
            const scaleX = img.naturalWidth / (imgRect.width / scale);
            const scaleY = img.naturalHeight / (imgRect.height / scale);

            const srcX = (boxX / scale) * scaleX;
            const srcY = (boxY / scale) * scaleY;
            const srcW = (boxW / scale) * scaleX;
            const srcH = (boxH / scale) * scaleY;

            ctx.drawImage(img, srcX, srcY, srcW, srcH, 0, 0, outputWidth, outputHeight);

            const croppedDataUrl = canvas.toDataURL('image/jpeg', 0.9);

            if (options.onCrop && typeof options.onCrop === 'function') {
                options.onCrop(croppedDataUrl, canvas);
            }

            close();
            toast('success', 'Imagen recortada', 'El recorte se aplicó correctamente.');
        });
    }

    LTMS.UX.openImageCropper = openImageCropper;

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
    // 57. COOKIE CONSENT — Banner GDPR
    // ═══════════════════════════════════════════════════════════

    /**
     * Banner de consentimiento de cookies que cumple GDPR.
     * Permite aceptar todo, rechazar todo o personalizar categorías.
     */

    function initCookieConsent() {
        try {
            const consent = localStorage.getItem('ltms-cookie-consent');
            if (consent) return;
        } catch (e) { return; }

        // No mostrar en admin o login
        if (document.querySelector('.ltms-auth-container')) return;

        setTimeout(() => {
            const banner = document.createElement('div');
            banner.className = 'ltms-cookie-consent';
            banner.innerHTML = `
                <div class="ltms-cookie-content">
                    <div class="ltms-cookie-icon">
                        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="8" cy="9" r="1"/><circle cx="16" cy="10" r="1"/><circle cx="9" cy="15" r="1"/><circle cx="15" cy="16" r="1"/></svg>
                    </div>
                    <div class="ltms-cookie-text">
                        <strong>Usamos cookies</strong>
                        <p>Utilizamos cookies propias y de terceros para mejorar tu experiencia, analizar el tráfico y personalizar contenido. Puedes aceptar todas, rechazar no esenciales o personalizar.</p>
                    </div>
                </div>
                <div class="ltms-cookie-actions">
                    <button type="button" class="ltms-cookie-btn ltms-cookie-reject">Solo esenciales</button>
                    <button type="button" class="ltms-cookie-btn ltms-cookie-custom">Personalizar</button>
                    <button type="button" class="ltms-cookie-btn ltms-cookie-accept ltms-btn-primary">Aceptar todas</button>
                </div>
            `;

            document.body.appendChild(banner);
            requestAnimationFrame(() => banner.classList.add('visible'));

            const saveConsent = (prefs) => {
                try {
                    localStorage.setItem('ltms-cookie-consent', JSON.stringify({ ...prefs, date: Date.now() }));
                } catch (e) {}
                banner.classList.remove('visible');
                setTimeout(() => banner.remove(), 400);

                // Disparar evento para que otros scripts reaccionen
                document.dispatchEvent(new CustomEvent('ltms:cookie-consent', { detail: prefs }));
            };

            banner.querySelector('.ltms-cookie-accept').addEventListener('click', () => {
                saveConsent({ essential: true, analytics: true, marketing: true });
            });

            banner.querySelector('.ltms-cookie-reject').addEventListener('click', () => {
                saveConsent({ essential: true, analytics: false, marketing: false });
            });

            banner.querySelector('.ltms-cookie-custom').addEventListener('click', () => {
                openCookiePreferences(saveConsent, banner);
            });
        }, 1500);
    }

    function openCookiePreferences(saveCallback, bannerEl) {
        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay';
        overlay.innerHTML = `
            <div class="ltms-modal" role="dialog" aria-modal="true">
                <div class="ltms-modal-header">
                    <h3 class="ltms-modal-title">Preferencias de cookies</h3>
                    <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ltms-modal-body">
                    <div class="ltms-cookie-pref-group">
                        <div class="ltms-cookie-pref-header">
                            <div>
                                <strong>Cookies esenciales</strong>
                                <p>Necesarias para el funcionamiento básico. No se pueden desactivar.</p>
                            </div>
                            <label class="ltms-cookie-toggle">
                                <input type="checkbox" checked disabled>
                                <span class="ltms-cookie-toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                    <div class="ltms-cookie-pref-group">
                        <div class="ltms-cookie-pref-header">
                            <div>
                                <strong>Cookies analíticas</strong>
                                <p>Nos ayudan a entender cómo usas el sitio para mejorarlo.</p>
                            </div>
                            <label class="ltms-cookie-toggle">
                                <input type="checkbox" id="ltms-cookie-analytics">
                                <span class="ltms-cookie-toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                    <div class="ltms-cookie-pref-group">
                        <div class="ltms-cookie-pref-header">
                            <div>
                                <strong>Cookies de marketing</strong>
                                <p>Usadas para mostrar anuncios personalizados.</p>
                            </div>
                            <label class="ltms-cookie-toggle">
                                <input type="checkbox" id="ltms-cookie-marketing">
                                <span class="ltms-cookie-toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="ltms-modal-footer">
                    <button type="button" class="ltms-btn ltms-btn-outline ltms-modal-close">Cancelar</button>
                    <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-cookie-save">Guardar preferencias</button>
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

        overlay.querySelector('#ltms-cookie-save').addEventListener('click', () => {
            saveCallback({
                essential: true,
                analytics: overlay.querySelector('#ltms-cookie-analytics').checked,
                marketing: overlay.querySelector('#ltms-cookie-marketing').checked,
            });
            close();
        });
    }

    LTMS.UX.initCookieConsent = initCookieConsent;

    // ═══════════════════════════════════════════════════════════
    // 58. QUICK VIEW — Vista rápida de producto en modal
    // ═══════════════════════════════════════════════════════════

    /**
     * Modal de vista rápida que permite ver un producto
     * sin abandonar la página actual. Incluye imagen,
     * precio, descripción breve y botón de añadir al carrito.
     */

    function openQuickView(productId, options = {}) {
        if (!productId) return;

        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay ltms-quick-view-overlay';

        overlay.innerHTML = `
            <div class="ltms-modal ltms-quick-view-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-qv-title">
                <div class="ltms-modal-header">
                    <h3 class="ltms-modal-title" id="ltms-qv-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        Vista rápida
                    </h3>
                    <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ltms-modal-body ltms-quick-view-body">
                    <div class="ltms-quick-view-loading">
                        <div class="ltms-spinner-lg"></div>
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

        // Fetch product data
        fetchQuickViewData(productId, overlay, close, options);
    }

    function fetchQuickViewData(productId, overlay, close, options) {
        // Try WooCommerce REST API or AJAX.
        // Task 67-B — Prefer the global ltmsUX bootstrap (available on every
        // page), fall back to ltmsDashboard (vendor dashboard context). Only
        // when neither is available do we fall back to fetching the product
        // page HTML, which is wasteful (≈ 300 KB per modal open).
        const ajaxUrl   = (typeof ltmsUX !== 'undefined' && ltmsUX.ajax_url)
            || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url);
        const ajaxNonce = (typeof ltmsUX !== 'undefined' && ltmsUX.nonce)
            || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce);

        if (typeof jQuery !== 'undefined' && ajaxUrl && ajaxNonce) {
            jQuery.post(ajaxUrl, {
                action: 'ltms_quick_view',
                nonce: ajaxNonce,
                product_id: productId,
            }, (response) => {
                if (response.success && response.data) {
                    renderQuickView(overlay, response.data, close, options);
                } else {
                    renderQuickViewError(overlay);
                }
            }).fail(() => renderQuickViewError(overlay));
        } else {
            // Fallback: fetch product page and extract data
            fetch('/?p=' + productId)
                .then((r) => r.text())
                .then((html) => {
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const name = doc.querySelector('.product_title')?.textContent || 'Producto';
                    const price = doc.querySelector('.price')?.textContent || '';
                    const img = doc.querySelector('.woocommerce-product-gallery__image img')?.src || '';
                    const desc = doc.querySelector('.woocommerce-product-details__short-description')?.textContent || '';
                    renderQuickView(overlay, { name, price, image: img, description: desc, url: '/?p=' + productId }, close, options);
                })
                .catch(() => renderQuickViewError(overlay));
        }
    }

    function renderQuickView(overlay, data, close, options) {
        const body = overlay.querySelector('.ltms-quick-view-body');
        body.innerHTML = `
            <div class="ltms-quick-view-grid">
                <div class="ltms-quick-view-image">
                    ${data.image ? `<img src="${escapeHtml(data.image)}" alt="${escapeHtml(data.name)}" data-lightbox>` : '<div class="ltms-quick-view-no-img">📦</div>'}
                </div>
                <div class="ltms-quick-view-info">
                    <h2 class="ltms-quick-view-name">${escapeHtml(data.name)}</h2>
                    <div class="ltms-quick-view-price">${escapeHtml(data.price)}</div>
                    ${data.description ? `<p class="ltms-quick-view-desc">${escapeHtml(data.description.substring(0, 200))}${data.description.length > 200 ? '...' : ''}</p>` : ''}
                    ${data.rating !== undefined ? `
                        <div class="ltms-quick-view-rating">
                            ${renderStars(data.rating)}
                            <span>${data.rating.toFixed(1)} (${data.review_count || 0})</span>
                        </div>
                    ` : ''}
                    <div class="ltms-quick-view-actions">
                        <button type="button" class="ltms-btn ltms-btn-primary ltms-quick-view-add-cart" data-product-id="${data.id || ''}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                            Añadir al carrito
                        </button>
                        <button type="button" class="ltms-btn ltms-btn-outline ltms-quick-view-wishlist" data-product-id="${data.id || ''}">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                            Favorito
                        </button>
                        <a href="${escapeHtml(data.url || '#')}" class="ltms-btn ltms-btn-outline">
                            Ver detalles
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                        </a>
                    </div>
                </div>
            </div>
        `;

        // Add to cart
        body.querySelector('.ltms-quick-view-add-cart').addEventListener('click', () => {
            if (typeof jQuery !== 'undefined') {
                jQuery.post(wc_add_to_cart_params ? wc_add_to_cart_params.ajax_url : '/wp-admin/admin-ajax.php', {
                    action: 'woocommerce_add_to_cart',
                    product_id: data.id || productId,
                    quantity: 1,
                }, (response) => {
                    if (response.fragments) {
                        jQuery.each(response.fragments, function(key, value) {
                            jQuery(key).replaceWith(value);
                        });
                    }
                    toast('success', 'Añadido al carrito', data.name);
                    openCartDrawer();
                    close();
                });
            } else {
                toast('success', 'Añadido', data.name);
                close();
            }
        });

        // Wishlist
        body.querySelector('.ltms-quick-view-wishlist').addEventListener('click', (e) => {
            toggleWishlist(data.id || productId, e.currentTarget);
        });

        // Track recently viewed
        trackRecentlyViewed(data.id || productId, data);
    }

    function renderQuickViewError(overlay) {
        const body = overlay.querySelector('.ltms-quick-view-body');
        body.innerHTML = '<div class="ltms-quick-view-error">No se pudo cargar el producto. Intenta de nuevo.</div>';
    }

    function renderStars(rating) {
        const full = Math.floor(rating);
        const half = rating % 1 >= 0.5;
        let html = '';
        for (let i = 0; i < 5; i++) {
            if (i < full) {
                html += '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="1"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
            } else if (i === full && half) {
                html += '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><defs><linearGradient id="half"><stop offset="50%" stop-color="currentColor"/><stop offset="50%" stop-color="transparent"/></linearGradient></defs><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2" fill="url(#half)"/></svg>';
            } else {
                html += '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
            }
        }
        return `<div class="ltms-stars">${html}</div>`;
    }

    function initQuickView() {
        document.addEventListener('click', (e) => {
            const trigger = e.target.closest('[data-quick-view]');
            if (!trigger) return;
            e.preventDefault();
            const productId = trigger.dataset.quickView || trigger.dataset.productId;
            if (productId) openQuickView(productId);
        });
    }

    LTMS.UX.openQuickView = openQuickView;

    // ═══════════════════════════════════════════════════════════
    // 59. WISHLIST — Favoritos persistente
    // ═══════════════════════════════════════════════════════════

    /**
     * Sistema de favoritos/wishlist que persiste en localStorage.
     * Permite marcar productos como favoritos, ver la lista completa
     * y sincronizar entre dispositivos si hay backend.
     */

    function getWishlist() {
        try {
            return JSON.parse(localStorage.getItem('ltms-wishlist') || '[]');
        } catch (e) {
            return [];
        }
    }

    function saveWishlist(items) {
        try {
            localStorage.setItem('ltms-wishlist', JSON.stringify(items));
        } catch (e) {}
        updateWishlistBadges(items.length);
    }

    function toggleWishlist(productId, btnEl) {
        if (!productId) return;
        const list = getWishlist();
        const index = list.indexOf(productId);

        if (index >= 0) {
            list.splice(index, 1);
            if (btnEl) {
                btnEl.classList.remove('active');
                btnEl.querySelector('svg')?.setAttribute('fill', 'none');
            }
            toast('info', 'Eliminado de favoritos', '');
        } else {
            list.push(productId);
            if (btnEl) {
                btnEl.classList.add('active');
                btnEl.querySelector('svg')?.setAttribute('fill', 'currentColor');
            }
            toast('success', 'Añadido a favoritos', '');
        }

        saveWishlist(list);
        announce(list.length + ' producto(s) en favoritos');
    }

    function isInWishlist(productId) {
        return getWishlist().includes(productId);
    }

    function updateWishlistBadges(count) {
        document.querySelectorAll('[data-wishlist-count]').forEach((el) => {
            el.textContent = count;
            el.style.display = count > 0 ? 'flex' : 'none';
        });
    }

    function initWishlist() {
        // Marcar botones de wishlist existentes como activos
        const wishlist = getWishlist();
        updateWishlistBadges(wishlist.length);

        // Hook en botones con data-wishlist
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-wishlist-toggle]');
            if (!btn) return;
            e.preventDefault();
            const productId = btn.dataset.wishlistToggle || btn.dataset.productId;
            toggleWishlist(productId, btn);
        });

        // Marcar botones activos
        document.querySelectorAll('[data-wishlist-toggle]').forEach((btn) => {
            const productId = btn.dataset.wishlistToggle || btn.dataset.productId;
            if (productId && isInWishlist(productId)) {
                btn.classList.add('active');
                btn.querySelector('svg')?.setAttribute('fill', 'currentColor');
            }
        });
    }

    LTMS.UX.toggleWishlist = toggleWishlist;
    LTMS.UX.getWishlist = getWishlist;
    LTMS.UX.isInWishlist = isInWishlist;

    // ═══════════════════════════════════════════════════════════
    // 60. RECENTLY VIEWED — Productos vistos recientemente
    // ═══════════════════════════════════════════════════════════

    /**
     * Rastrea productos vistos recientemente y muestra un widget
     * con los últimos 10 productos visitados.
     */

    function trackRecentlyViewed(productId, data) {
        if (!productId) return;

        let recent = [];
        try {
            recent = JSON.parse(localStorage.getItem('ltms-recently-viewed') || '[]');
        } catch (e) {}

        // Remover si ya existe (mover al inicio)
        recent = recent.filter((item) => item.id !== productId);

        // Añadir al inicio
        recent.unshift({
            id: productId,
            name: data.name || '',
            price: data.price || '',
            image: data.image || '',
            url: data.url || '',
            timestamp: Date.now(),
        });

        // Mantener solo los últimos 10
        recent = recent.slice(0, 10);

        try {
            localStorage.setItem('ltms-recently-viewed', JSON.stringify(recent));
        } catch (e) {}

        renderRecentlyViewedWidget();
    }

    function renderRecentlyViewedWidget() {
        let recent = [];
        try {
            recent = JSON.parse(localStorage.getItem('ltms-recently-viewed') || '[]');
        } catch (e) {}

        if (recent.length === 0) return;

        // Buscar o crear widget
        let widget = document.querySelector('.ltms-recently-viewed-widget');
        if (!widget) {
            // Solo mostrar en storefront, no en dashboard
            if (!document.querySelector('.ltms-storefront-page, .ltms-sellers-landing')) return;

            widget = document.createElement('div');
            widget.className = 'ltms-recently-viewed-widget ltms-card';
            widget.innerHTML = `
                <div class="ltms-card-header">
                    <div class="ltms-card-header-title">
                        <h3>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            Vistos recientemente
                        </h3>
                    </div>
                </div>
                <div class="ltms-card-body ltms-recently-viewed-body"></div>
            `;

            // Insertar después del contenido principal
            const main = document.querySelector('main, .ltms-storefront, #content');
            if (main) main.appendChild(widget);
        }

        const body = widget.querySelector('.ltms-recently-viewed-body');
        body.innerHTML = `
            <div class="ltms-recently-viewed-scroll">
                ${recent.map((item) => `
                    <a href="${escapeHtml(item.url || '#')}" class="ltms-recently-viewed-item" data-product-id="${item.id}">
                        <div class="ltms-recently-viewed-img">
                            ${item.image ? `<img src="${escapeHtml(item.image)}" alt="${escapeHtml(item.name)}" loading="lazy">` : '📦'}
                        </div>
                        <div class="ltms-recently-viewed-info">
                            <div class="ltms-recently-viewed-name">${escapeHtml(item.name)}</div>
                            <div class="ltms-recently-viewed-price">${escapeHtml(item.price)}</div>
                        </div>
                    </a>
                `).join('')}
            </div>
        `;

        // Click para quick view
        body.querySelectorAll('.ltms-recently-viewed-item').forEach((el) => {
            el.addEventListener('click', (e) => {
                // Si es click normal, dejar navegar; si tiene data-quick-view, abrir modal
            });
        });
    }

    function initRecentlyViewed() {
        // Renderizar widget si ya hay productos vistos
        setTimeout(renderRecentlyViewedWidget, 1000);

        // Tracking automático en páginas de producto
        if (typeof jQuery !== 'undefined') {
            jQuery(document).on('ltms:product: viewed', (e, data) => {
                if (data && data.id) trackRecentlyViewed(data.id, data);
            });
        }

        // Auto-detectar página de producto WooCommerce
        const productGallery = document.querySelector('.woocommerce-product-gallery');
        if (productGallery) {
            const productId = document.body.className.match(/postid-(\d+)/);
            if (productId) {
                const name = document.querySelector('.product_title')?.textContent || '';
                const price = document.querySelector('.price')?.textContent || '';
                const img = document.querySelector('.woocommerce-product-gallery__image img')?.src || '';
                trackRecentlyViewed(productId[1], { name, price, image: img, url: window.location.href });
            }
        }
    }

    LTMS.UX.trackRecentlyViewed = trackRecentlyViewed;

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
    // 62. SOCIAL SHARE — Botones de compartir
    // ═══════════════════════════════════════════════════════════

    /**
     * Botones de compartir en redes sociales con URL, título
     * e imagen del producto/página actual.
     */

    const SHARE_PLATFORMS = {
        whatsapp: {
            label: 'WhatsApp',
            icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/></svg>',
            color: '#25D366',
            url: (data) => `https://wa.me/?text=${encodeURIComponent(data.title + ' ' + data.url)}`,
        },
        facebook: {
            label: 'Facebook',
            icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
            color: '#1877F2',
            url: (data) => `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(data.url)}`,
        },
        twitter: {
            label: 'X',
            icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
            color: '#000000',
            url: (data) => `https://twitter.com/intent/tweet?text=${encodeURIComponent(data.title)}&url=${encodeURIComponent(data.url)}`,
        },
        telegram: {
            label: 'Telegram',
            icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.139-5.061 3.345-.48.329-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"/></svg>',
            color: '#0088CC',
            url: (data) => `https://t.me/share/url?url=${encodeURIComponent(data.url)}&text=${encodeURIComponent(data.title)}`,
        },
        email: {
            label: 'Email',
            icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
            color: '#6B7280',
            url: (data) => `mailto:?subject=${encodeURIComponent(data.title)}&body=${encodeURIComponent(data.url)}`,
        },
        copy: {
            label: 'Copiar',
            icon: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>',
            color: '#6B7280',
            url: null,
        },
    };

    function renderShareButtons(container, data = {}) {
        const shareData = {
            title: data.title || document.title,
            url: data.url || window.location.href,
            ...data,
        };

        const html = `
            <div class="ltms-social-share">
                <span class="ltms-share-label">Compartir:</span>
                <div class="ltms-share-buttons">
                    ${Object.entries(SHARE_PLATFORMS).map(([key, platform]) => `
                        <button type="button" class="ltms-share-btn ltms-share-${key}" data-share="${key}" aria-label="Compartir en ${escapeHtml(platform.label)}" title="${escapeHtml(platform.label)}" style="--share-color:${platform.color};">
                            ${platform.icon}
                        </button>
                    `).join('')}
                </div>
            </div>
        `;

        if (typeof container === 'string') {
            container = document.querySelector(container);
        }

        if (container) {
            container.innerHTML = html;

            container.querySelectorAll('[data-share]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const platform = btn.dataset.share;
                    const config = SHARE_PLATFORMS[platform];

                    if (platform === 'copy') {
                        navigator.clipboard.writeText(shareData.url).then(() => {
                            toast('success', 'Enlace copiado', 'Pégalo donde quieras compartirlo');
                        });
                        return;
                    }

                    if (config && config.url) {
                        const url = config.url(shareData);
                        window.open(url, '_blank', 'width=600,height=400,scrollbars=yes');
                    }
                });
            });
        }

        return html;
    }

    function initSocialShare() {
        // Auto-render en contenedores con data-share-buttons
        document.querySelectorAll('[data-share-buttons]').forEach((el) => {
            const data = {};
            if (el.dataset.shareTitle) data.title = el.dataset.shareTitle;
            if (el.dataset.shareUrl) data.url = el.dataset.shareUrl;
            renderShareButtons(el, data);
        });
    }

    LTMS.UX.renderShareButtons = renderShareButtons;

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
            const trigger = e.target.closest('[data-qr-generate]');
            if (!trigger) return;
            e.preventDefault();
            const text = trigger.dataset.qrGenerate || window.location.href;
            openQRModal(text);
        });
    }

    LTMS.UX.generateQR = generateQR;
    LTMS.UX.openQRModal = openQRModal;

    // ═══════════════════════════════════════════════════════════
    // 64. PRODUCT COMPARISON — Comparación side-by-side
    // ═══════════════════════════════════════════════════════════

    /**
     * Permite comparar hasta 4 productos lado a lado en una tabla
     * con sus características, precios y especificaciones.
     */

    function getCompareList() {
        try {
            return JSON.parse(localStorage.getItem('ltms-compare') || '[]');
        } catch (e) { return []; }
    }

    function toggleCompare(productId, data, btnEl) {
        if (!productId) return;
        const list = getCompareList();
        const index = list.findIndex((p) => p.id === productId);

        if (index >= 0) {
            list.splice(index, 1);
            if (btnEl) btnEl.classList.remove('active');
            toast('info', 'Eliminado de comparación', '');
        } else {
            if (list.length >= 4) {
                toast('warning', 'Máximo 4 productos', 'Elimina uno para comparar otro.');
                return;
            }
            list.push({ id: productId, ...data });
            if (btnEl) btnEl.classList.add('active');
            toast('success', 'Añadido a comparación', `${list.length}/4 productos seleccionados`);
        }

        try { localStorage.setItem('ltms-compare', JSON.stringify(list)); } catch (e) {}
        updateCompareBadge(list.length);
    }

    function updateCompareBadge(count) {
        document.querySelectorAll('[data-compare-count]').forEach((el) => {
            el.textContent = count;
            el.style.display = count > 0 ? 'flex' : 'none';
        });

        // Mostrar/ocultar barra flotante de comparación
        let bar = document.querySelector('.ltms-compare-bar');
        if (count > 0) {
            if (!bar) {
                bar = document.createElement('div');
                bar.className = 'ltms-compare-bar';
                document.body.appendChild(bar);
            }
            const list = getCompareList();
            bar.innerHTML = `
                <div class="ltms-compare-bar-items">
                    ${list.map((p) => `
                        <div class="ltms-compare-bar-item">
                            ${p.image ? `<img src="${escapeHtml(p.image)}" alt="">` : '<span>📦</span>'}
                            <button type="button" class="ltms-compare-bar-remove" data-compare-remove="${p.id}" aria-label="Quitar">×</button>
                        </div>
                    `).join('')}
                </div>
                <button type="button" class="ltms-btn ltms-btn-primary ltms-btn-sm" id="ltms-compare-now">
                    Comparar (${count})
                </button>
                <button type="button" class="ltms-compare-bar-clear" id="ltms-compare-clear">Limpiar</button>
            `;
            bar.classList.add('visible');

            bar.querySelectorAll('[data-compare-remove]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    toggleCompare(btn.dataset.compareRemove, {}, null);
                });
            });

            bar.querySelector('#ltms-compare-now').addEventListener('click', openCompareModal);
            bar.querySelector('#ltms-compare-clear').addEventListener('click', () => {
                try { localStorage.removeItem('ltms-compare'); } catch (e) {}
                updateCompareBadge(0);
                document.querySelectorAll('[data-compare-toggle].active').forEach((b) => b.classList.remove('active'));
                bar.classList.remove('visible');
            });
        } else if (bar) {
            bar.classList.remove('visible');
        }
    }

    function openCompareModal() {
        const list = getCompareList();
        if (list.length < 2) {
            toast('warning', 'Selecciona al menos 2 productos', 'Necesitas 2 o más para comparar.');
            return;
        }

        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay ltms-compare-overlay';

        // Collect all unique attribute keys
        const allKeys = new Set(['price', 'stock', 'category', 'brand', 'sku', 'weight', 'dimensions', 'rating']);
        list.forEach((p) => { if (p.attributes) Object.keys(p.attributes).forEach((k) => allKeys.add(k)); });

        const attrLabels = {
            price: 'Precio', stock: 'Stock', category: 'Categoría', brand: 'Marca',
            sku: 'SKU', weight: 'Peso', dimensions: 'Dimensiones', rating: 'Calificación',
        };

        overlay.innerHTML = `
            <div class="ltms-modal ltms-compare-modal" role="dialog" aria-modal="true">
                <div class="ltms-modal-header">
                    <h3 class="ltms-modal-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="18"/><rect x="14" y="3" width="7" height="18"/></svg>
                        Comparar productos (${list.length})
                    </h3>
                    <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ltms-modal-body ltms-compare-body">
                    <div class="ltms-compare-table-wrap">
                        <table class="ltms-compare-table">
                            <thead>
                                <tr>
                                    <th class="ltms-compare-spacer"></th>
                                    ${list.map((p) => `
                                        <th class="ltms-compare-product">
                                            <div class="ltms-compare-product-img">
                                                ${p.image ? `<img src="${escapeHtml(p.image)}" alt="${escapeHtml(p.name)}">` : '📦'}
                                            </div>
                                            <div class="ltms-compare-product-name">${escapeHtml(p.name || '')}</div>
                                            <a href="${escapeHtml(p.url || '#')}" class="ltms-btn ltms-btn-outline ltms-btn-sm">Ver</a>
                                        </th>
                                    `).join('')}
                                </tr>
                            </thead>
                            <tbody>
                                ${[...allKeys].map((key) => `
                                    <tr>
                                        <td class="ltms-compare-attr-label">${escapeHtml(attrLabels[key] || key)}</td>
                                        ${list.map((p) => {
                                            const val = p.attributes?.[key] ?? p[key] ?? '—';
                                            const isPrice = key === 'price';
                                            return `<td class="${isPrice ? 'ltms-compare-price' : ''}">${escapeHtml(String(val))}</td>`;
                                        }).join('')}
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
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
    }

    function initCompare() {
        updateCompareBadge(getCompareList().length);

        document.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-compare-toggle]');
            if (!btn) return;
            e.preventDefault();
            const productId = btn.dataset.compareToggle || btn.dataset.productId;
            const data = {
                name: btn.dataset.compareName || '',
                price: btn.dataset.comparePrice || '',
                image: btn.dataset.compareImage || '',
                url: btn.dataset.compareUrl || '',
            };
            toggleCompare(productId, data, btn);
        });

        // Marcar botones activos
        const list = getCompareList();
        document.querySelectorAll('[data-compare-toggle]').forEach((btn) => {
            const pid = btn.dataset.compareToggle || btn.dataset.productId;
            if (pid && list.find((p) => p.id === pid)) btn.classList.add('active');
        });
    }

    LTMS.UX.toggleCompare = toggleCompare;
    LTMS.UX.getCompareList = getCompareList;

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
    // 66. PRICE RANGE SLIDER — Filtro de rango de precio
    // ═══════════════════════════════════════════════════════════

    /**
     * Slider dual para filtrar productos por rango de precio.
     * Sin librerías externas, usa dos input[type=range] superpuestos.
     */

    function createPriceRange(container, options = {}) {
        const min = options.min || 0;
        const max = options.max || 1000000;
        const step = options.step || 1000;
        const initialMin = options.initialMin ?? min;
        const initialMax = options.initialMax ?? max;
        const currency = options.currency || 'COP';
        const onChange = options.onChange;

        container.className = 'ltms-price-range';
        container.innerHTML = `
            <div class="ltms-price-range-track">
                <div class="ltms-price-range-fill" id="ltms-pr-fill"></div>
            </div>
            <input type="range" class="ltms-price-range-input ltms-price-range-min" min="${min}" max="${max}" step="${step}" value="${initialMin}" aria-label="Precio mínimo">
            <input type="range" class="ltms-price-range-input ltms-price-range-max" min="${min}" max="${max}" step="${step}" value="${initialMax}" aria-label="Precio máximo">
            <div class="ltms-price-range-values">
                <span class="ltms-price-range-min-val">${formatCurrency(initialMin, currency)}</span>
                <span class="ltms-price-range-sep">—</span>
                <span class="ltms-price-range-max-val">${formatCurrency(initialMax, currency)}</span>
            </div>
        `;

        const minInput = container.querySelector('.ltms-price-range-min');
        const maxInput = container.querySelector('.ltms-price-range-max');
        const fill = container.querySelector('#ltms-pr-fill');
        const minVal = container.querySelector('.ltms-price-range-min-val');
        const maxVal = container.querySelector('.ltms-price-range-max-val');

        function update() {
            let minV = parseInt(minInput.value, 10);
            let maxV = parseInt(maxInput.value, 10);

            // Prevenir cruce
            if (minV > maxV - step) {
                if (document.activeElement === minInput) {
                    minV = maxV - step;
                    minInput.value = minV;
                } else {
                    maxV = minV + step;
                    maxInput.value = maxV;
                }
            }

            const percentMin = ((minV - min) / (max - min)) * 100;
            const percentMax = ((maxV - min) / (max - min)) * 100;

            fill.style.left = percentMin + '%';
            fill.style.width = (percentMax - percentMin) + '%';

            minVal.textContent = formatCurrency(minV, currency);
            maxVal.textContent = formatCurrency(maxV, currency);

            if (onChange) onChange(minV, maxV);
        }

        minInput.addEventListener('input', update);
        maxInput.addEventListener('input', update);

        update();

        return {
            getValues: () => [parseInt(minInput.value, 10), parseInt(maxInput.value, 10)],
            setValues: (minV, maxV) => {
                minInput.value = minV;
                maxInput.value = maxV;
                update();
            },
        };
    }

    function initPriceRanges() {
        document.querySelectorAll('[data-price-range]').forEach((el) => {
            if (el.dataset.prInit) return;
            el.dataset.prInit = 'true';

            createPriceRange(el, {
                min: parseInt(el.dataset.min || '0', 10),
                max: parseInt(el.dataset.max || '1000000', 10),
                step: parseInt(el.dataset.step || '1000', 10),
                initialMin: parseInt(el.dataset.initialMin || el.dataset.min || '0', 10),
                initialMax: parseInt(el.dataset.initialMax || el.dataset.max || '1000000', 10),
                currency: el.dataset.currency || 'COP',
            });
        });
    }

    LTMS.UX.createPriceRange = createPriceRange;

    // ═══════════════════════════════════════════════════════════
    // 67. IMAGE ZOOM — Zoom de imagen al hover
    // ═══════════════════════════════════════════════════════════

    /**
     * Efecto de zoom en imágenes de producto al pasar el mouse.
     * Muestra una versión ampliada siguiendo el cursor.
     */

    function initImageZoom() {
        document.addEventListener('mousemove', (e) => {
            // v2.9.32: e.target puede ser un Document o text node que no tiene .closest()
            if (!e.target || typeof e.target.closest !== 'function') return;
            const container = e.target.closest('.ltms-zoom-container');
            if (!container) return;

            const img = container.querySelector('img');
            if (!img) return;

            const rect = container.getBoundingClientRect();
            const x = ((e.clientX - rect.left) / rect.width) * 100;
            const y = ((e.clientY - rect.top) / rect.height) * 100;

            img.style.transformOrigin = `${x}% ${y}%`;
            img.style.transform = 'scale(2)';
        });

        document.addEventListener('mouseleave', (e) => {
            const container = e.target.closest('.ltms-zoom-container');
            if (!container) return;

            const img = container.querySelector('img');
            if (img) {
                img.style.transform = 'scale(1)';
                img.style.transformOrigin = 'center';
            }
        }, true);

        // Auto-inicializar contenedores
        document.querySelectorAll('.ltms-zoom-container').forEach((container) => {
            if (container.dataset.zoomInit) return;
            container.dataset.zoomInit = 'true';
            container.style.cursor = 'zoom-in';

            const img = container.querySelector('img');
            if (img) {
                img.style.transition = 'transform 0.2s ease-out';
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 68. FAQ ACCORDION — Componente acordeón
    // ═══════════════════════════════════════════════════════════

    /**
     * Acordeón para FAQs y secciones colapsables.
     * Accesible (ARIA) con animación smooth.
     */

    function initAccordion() {
        document.addEventListener('click', (e) => {
            const trigger = e.target.closest('[data-accordion-trigger]');
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
    // 71. PRODUCT CAROUSEL — Slider de imágenes de producto
    // ═══════════════════════════════════════════════════════════

    /**
     * Carrusel de imágenes para productos con:
     * - Navegación por flechas y dots
     * - Swipe en móvil
     * - Zoom al click
     * - Thumbnail navigation
     */

    function initProductCarousels() {
        document.querySelectorAll('.ltms-carousel').forEach((carousel) => {
            if (carousel.dataset.carouselInit) return;
            carousel.dataset.carouselInit = 'true';

            const slides = carousel.querySelector('.ltms-carousel-slides');
            const dotsContainer = carousel.querySelector('.ltms-carousel-dots');
            const prevBtn = carousel.querySelector('.ltms-carousel-prev');
            const nextBtn = carousel.querySelector('.ltms-carousel-next');
            const thumbs = carousel.querySelectorAll('.ltms-carousel-thumb');

            if (!slides) return;

            const total = slides.children.length;
            let current = 0;

            // Crear dots si no existen
            if (dotsContainer && !dotsContainer.children.length) {
                for (let i = 0; i < total; i++) {
                    const dot = document.createElement('button');
                    dot.type = 'button';
                    dot.className = 'ltms-carousel-dot' + (i === 0 ? ' active' : '');
                    dot.setAttribute('aria-label', `Ir a imagen ${i + 1}`);
                    dot.addEventListener('click', () => goTo(i));
                    dotsContainer.appendChild(dot);
                }
            }

            function goTo(index) {
                current = Math.max(0, Math.min(index, total - 1));
                slides.style.transform = `translateX(-${current * 100}%)`;

                carousel.querySelectorAll('.ltms-carousel-dot').forEach((d, i) => {
                    d.classList.toggle('active', i === current);
                });

                thumbs.forEach((t, i) => {
                    t.classList.toggle('active', i === current);
                });
            }

            function next() { goTo(current + 1 >= total ? 0 : current + 1); }
            function prev() { goTo(current - 1 < 0 ? total - 1 : current - 1); }

            if (prevBtn) prevBtn.addEventListener('click', prev);
            if (nextBtn) nextBtn.addEventListener('click', next);

            // Keyboard
            carousel.setAttribute('tabindex', '0');
            carousel.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowLeft') { e.preventDefault(); prev(); }
                else if (e.key === 'ArrowRight') { e.preventDefault(); next(); }
            });

            // Thumbnails
            thumbs.forEach((thumb, i) => {
                thumb.addEventListener('click', () => goTo(i));
            });

            // Touch / Swipe
            let touchStartX = 0;
            let touchEndX = 0;

            carousel.addEventListener('touchstart', (e) => {
                touchStartX = e.touches[0].clientX;
            }, { passive: true });

            carousel.addEventListener('touchend', (e) => {
                touchEndX = e.changedTouches[0].clientX;
                const diff = touchStartX - touchEndX;

                if (Math.abs(diff) > 50) {
                    if (diff > 0) next();
                    else prev();
                }
            }, { passive: true });

            // Auto-play opcional
            if (carousel.dataset.autoplay === 'true') {
                const interval = parseInt(carousel.dataset.autoplayInterval || '5000', 10);
                let autoTimer = setInterval(next, interval);

                carousel.addEventListener('mouseenter', () => clearInterval(autoTimer));
                carousel.addEventListener('mouseleave', () => {
                    autoTimer = setInterval(next, interval);
                });
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 72. QUANTITY STEPPER — Selector de cantidad
    // ═══════════════════════════════════════════════════════════

    /**
     * Componente reutilizable para seleccionar cantidad
     * con botones +/-, validación de min/max y soporte keyboard.
     */

    function createQuantityStepper(options = {}) {
        const min = options.min ?? 1;
        const max = options.max ?? 999;
        const step = options.step || 1;
        const initial = options.initial ?? min;
        const onChange = options.onChange;

        const container = document.createElement('div');
        container.className = 'ltms-qty-stepper';
        container.setAttribute('role', 'group');
        container.setAttribute('aria-label', 'Selector de cantidad');

        const decBtn = document.createElement('button');
        decBtn.type = 'button';
        decBtn.className = 'ltms-qty-btn ltms-qty-dec';
        decBtn.setAttribute('aria-label', 'Disminuir cantidad');
        decBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>';

        const input = document.createElement('input');
        input.type = 'number';
        input.className = 'ltms-qty-input';
        input.value = initial;
        input.min = min;
        input.max = max;
        input.step = step;
        input.setAttribute('aria-label', 'Cantidad');
        input.setAttribute('inputmode', 'numeric');

        const incBtn = document.createElement('button');
        incBtn.type = 'button';
        incBtn.className = 'ltms-qty-btn ltms-qty-inc';
        incBtn.setAttribute('aria-label', 'Aumentar cantidad');
        incBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>';

        container.appendChild(decBtn);
        container.appendChild(input);
        container.appendChild(incBtn);

        let current = initial;

        function setValue(val) {
            val = Math.max(min, Math.min(val, max));
            val = Math.round(val / step) * step;
            if (val === current) return;
            current = val;
            input.value = val;
            updateButtons();
            if (onChange) onChange(val);
        }

        function updateButtons() {
            decBtn.disabled = current <= min;
            incBtn.disabled = current >= max;
        }

        decBtn.addEventListener('click', () => setValue(current - step));
        incBtn.addEventListener('click', () => setValue(current + step));

        input.addEventListener('change', () => {
            const val = parseInt(input.value, 10);
            if (!isNaN(val)) setValue(val);
            else input.value = current;
        });

        input.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowUp') { e.preventDefault(); setValue(current + step); }
            else if (e.key === 'ArrowDown') { e.preventDefault(); setValue(current - step); }
        });

        updateButtons();

        container.getValue = () => current;
        container.setValue = setValue;

        return container;
    }

    function initQuantitySteppers() {
        document.querySelectorAll('[data-qty-stepper]').forEach((el) => {
            if (el.dataset.qtyInit) return;
            el.dataset.qtyInit = 'true';

            const stepper = createQuantityStepper({
                min: parseInt(el.dataset.min || '1', 10),
                max: parseInt(el.dataset.max || '999', 10),
                step: parseInt(el.dataset.step || '1', 10),
                initial: parseInt(el.dataset.initial || el.dataset.min || '1', 10),
                onChange: (val) => {
                    const hidden = el.parentElement.querySelector(`input[name="${el.dataset.qtyName || 'quantity'}"]`);
                    if (hidden) hidden.value = val;
                    el.dispatchEvent(new CustomEvent('ltms:qty-change', { detail: { value: val }, bubbles: true }));
                },
            });

            el.innerHTML = '';
            el.appendChild(stepper);
        });
    }

    LTMS.UX.createQuantityStepper = createQuantityStepper;

    // ═══════════════════════════════════════════════════════════
    // 73. COUPON CODE — Input con validación visual
    // ═══════════════════════════════════════════════════════════

    /**
     * Campo de código de cupón con validación visual,
     * feedback inmediato y estado de carga.
     */

    function initCouponInputs() {
        document.querySelectorAll('[data-coupon-input]').forEach((wrapper) => {
            if (wrapper.dataset.couponInit) return;
            wrapper.dataset.couponInit = 'true';

            const input = wrapper.querySelector('input');
            const btn = wrapper.querySelector('button');
            if (!input || !btn) return;

            const validateUrl = wrapper.dataset.couponValidate;
            const nonceName = wrapper.dataset.couponNonce;

            btn.addEventListener('click', async () => {
                const code = input.value.trim().toUpperCase();
                if (!code) {
                    showCouponResult(wrapper, 'error', 'Ingresa un código');
                    return;
                }

                // Estado de carga
                btn.disabled = true;
                btn.innerHTML = '<span class="ltms-spinner"></span> Verificando...';
                wrapper.classList.remove('ltms-coupon-success', 'ltms-coupon-error');
                wrapper.classList.add('ltms-coupon-loading');

                try {
                    // UX-FAKE-6 FIX — Previously when no `data-coupon-validate`
                    // URL was provided, the code simulated a success toast
                    // claiming "¡Cupón aplicado! -10% de descuento" without
                    // ever sending the code to the server. The user was misled
                    // into believing a discount was applied. Now we always
                    // POST to the ltms_validate_coupon endpoint (registered in
                    // class-ltms-frontend-checkout-handler.php) via the global
                    // ltmsUX bootstrap and only show success when the server
                    // actually validates the coupon.
                    const ajaxUrl   = validateUrl
                        || ((typeof ltmsUX !== 'undefined' && ltmsUX.ajax_url) && ltmsUX.ajax_url)
                        || ((typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url) && ltmsDashboard.ajax_url);
                    const ajaxNonce = (nonceName && window[nonceName])
                        || (typeof ltmsUX !== 'undefined' && ltmsUX.nonce)
                        || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce);

                    const restoreBtn = () => {
                        btn.disabled = false;
                        btn.textContent = 'Aplicar';
                        wrapper.classList.remove('ltms-coupon-loading');
                    };

                    if (ajaxUrl && ajaxNonce && typeof jQuery !== 'undefined') {
                        const data = {
                            action: 'ltms_validate_coupon',
                            nonce: ajaxNonce,
                            coupon_code: code,
                        };

                        jQuery.post(ajaxUrl, data, (response) => {
                            if (response.success) {
                                showCouponResult(wrapper, 'success', response.data?.message || '¡Cupón aplicado!', response.data);
                            } else {
                                showCouponResult(wrapper, 'error', response.data?.message || response.data || 'Cupón no válido');
                            }
                            restoreBtn();
                        }).fail(() => {
                            showCouponResult(wrapper, 'error', 'Error de conexión. Intenta de nuevo.');
                            restoreBtn();
                        });
                    } else {
                        // UX-FAKE-6 FIX — do NOT fake success. Surface a real
                        // error so the user knows the coupon was not validated.
                        showCouponResult(wrapper, 'error', 'No se pudo validar el cupón en este momento. Recarga la página e inténtalo de nuevo.');
                        restoreBtn();
                    }
                } catch (e) {
                    showCouponResult(wrapper, 'error', 'Error inesperado');
                    btn.disabled = false;
                    btn.textContent = 'Aplicar';
                    wrapper.classList.remove('ltms-coupon-loading');
                }
            });

            // Enter key
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    btn.click();
                }
            });

            // Limpiar al escribir
            input.addEventListener('input', () => {
                wrapper.classList.remove('ltms-coupon-success', 'ltms-coupon-error');
                const msg = wrapper.querySelector('.ltms-coupon-message');
                if (msg) msg.remove();
            });
        });
    }

    function showCouponResult(wrapper, type, message, data) {
        wrapper.classList.remove('ltms-coupon-loading');
        wrapper.classList.add('ltms-coupon-' + type);

        let msg = wrapper.querySelector('.ltms-coupon-message');
        if (!msg) {
            msg = document.createElement('div');
            msg.className = 'ltms-coupon-message';
            wrapper.appendChild(msg);
        }

        const icon = type === 'success'
            ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>'
            : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';

        msg.innerHTML = `${icon} ${escapeHtml(message)}`;
        msg.className = 'ltms-coupon-message ltms-coupon-' + type;

        if (type === 'success') {
            toast('success', 'Cupón aplicado', message);
            if (data && data.discount) {
                announce(`Cupón aplicado. Descuento: ${data.discount}`);
            }
        }
    }

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
    // 76. PRODUCT FILTER SIDEBAR — Filtros facetados
    // ═══════════════════════════════════════════════════════════

    /**
     * Sidebar de filtros con facets: categorías, atributos,
     * precio, rating. Aplica filtros en tiempo real con AJAX.
     */

    function initFilterSidebar() {
        const sidebar = document.querySelector('[data-filter-sidebar]');
        if (!sidebar) return;

        const form = sidebar.querySelector('form') || sidebar;
        const resultsContainer = document.querySelector(sidebar.dataset.filterTarget || '.ltms-products-grid, .ltms-filter-results');
        const ajaxUrl = sidebar.dataset.filterAjax;
        const state = { page: 1, filters: {} };

        // Recopilar filtros iniciales
        function collectFilters() {
            const data = {};
            // Checkboxes
            sidebar.querySelectorAll('input[type="checkbox"][data-filter]:checked').forEach((cb) => {
                const key = cb.dataset.filter;
                if (!data[key]) data[key] = [];
                data[key].push(cb.value);
            });
            // Radios
            sidebar.querySelectorAll('input[type="radio"][data-filter]:checked').forEach((r) => {
                data[r.dataset.filter] = r.value;
            });
            // Range inputs
            sidebar.querySelectorAll('[data-price-range]').forEach((el) => {
                const min = el.querySelector('.ltms-price-range-min');
                const max = el.querySelector('.ltms-price-range-max');
                if (min && max) {
                    data.price_min = min.value;
                    data.price_max = max.value;
                }
            });
            // Text search
            const search = sidebar.querySelector('[data-filter-search]');
            if (search) data.search = search.value;
            // Sort
            const sort = sidebar.querySelector('[data-filter-sort]');
            if (sort) data.sort = sort.value;

            return data;
        }

        function applyFilters(resetPage) {
            if (resetPage) state.page = 1;
            state.filters = collectFilters();

            // Update URL
            const params = new URLSearchParams();
            Object.entries(state.filters).forEach(([key, val]) => {
                if (Array.isArray(val)) val.forEach((v) => params.append(key + '[]', v));
                else params.set(key, val);
            });
            const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
            window.history.replaceState(null, '', newUrl);

            if (!ajaxUrl || !resultsContainer) {
                // Sin AJAX: aplicar filtros client-side
                applyClientSideFilters();
                return;
            }

            // AJAX filter
            resultsContainer.style.opacity = '0.5';
            resultsContainer.style.pointerEvents = 'none';

            const data = new FormData();
            data.append('action', 'ltms_filter_products');
            Object.entries(state.filters).forEach(([key, val]) => {
                if (Array.isArray(val)) val.forEach((v) => data.append(key + '[]', v));
                else data.append(key, val);
            });
            data.append('page', state.page);

            fetch(ajaxUrl, { method: 'POST', body: data })
                .then((r) => r.json())
                .then((response) => {
                    if (response.success && response.data) {
                        resultsContainer.innerHTML = response.data.html || '';
                        updateFilterCounts(response.data.counts || {});
                        updateActiveFiltersChips(state.filters);
                        announce(`${response.data.total || 0} productos encontrados`);
                    }
                })
                .catch(() => {
                    toast('error', 'Error', 'No se pudieron aplicar los filtros.');
                })
                .finally(() => {
                    resultsContainer.style.opacity = '';
                    resultsContainer.style.pointerEvents = '';
                });
        }

        function applyClientSideFilters() {
            const items = document.querySelectorAll('[data-product-item]');
            let visible = 0;

            items.forEach((item) => {
                let show = true;
                const categories = (item.dataset.categories || '').split(',');
                const price = parseFloat(item.dataset.price || '0');
                const rating = parseFloat(item.dataset.rating || '0');

                // Category filter
                if (state.filters.category && state.filters.category.length) {
                    show = show && state.filters.category.some((c) => categories.includes(c));
                }
                // Price filter
                if (state.filters.price_min && price < parseFloat(state.filters.price_min)) show = false;
                if (state.filters.price_max && price > parseFloat(state.filters.price_max)) show = false;
                // Rating filter
                if (state.filters.rating && rating < parseFloat(state.filters.rating)) show = false;
                // Search
                if (state.filters.search) {
                    const text = (item.textContent || '').toLowerCase();
                    show = show && text.includes(state.filters.search.toLowerCase());
                }

                item.style.display = show ? '' : 'none';
                if (show) visible++;
            });

            updateActiveFiltersChips(state.filters);
            announce(`${visible} productos encontrados`);

            // Show empty state
            const emptyState = document.querySelector('[data-filter-empty]');
            if (emptyState) {
                emptyState.style.display = visible === 0 ? 'block' : 'none';
            }
        }

        function updateFilterCounts(counts) {
            Object.entries(counts).forEach(([key, val]) => {
                const el = sidebar.querySelector(`[data-filter-count="${key}"]`);
                if (el) el.textContent = `(${val})`;
            });
        }

        function updateActiveFiltersChips(filters) {
            let chipsContainer = document.querySelector('[data-filter-chips]');
            if (!chipsContainer) {
                chipsContainer = document.createElement('div');
                chipsContainer.className = 'ltms-filter-chips';
                chipsContainer.setAttribute('data-filter-chips', '');
                sidebar.parentNode.insertBefore(chipsContainer, sidebar.nextSibling);
            }

            const chips = [];
            Object.entries(filters).forEach(([key, val]) => {
                if (Array.isArray(val)) {
                    val.forEach((v) => {
                        chips.push({ key, value: v, label: v });
                    });
                } else if (val && key !== 'search' && key !== 'sort') {
                    chips.push({ key, value: val, label: val });
                }
            });

            if (!chips.length) {
                chipsContainer.innerHTML = '';
                chipsContainer.style.display = 'none';
                return;
            }

            chipsContainer.style.display = 'flex';
            chipsContainer.innerHTML = `
                <span class="ltms-filter-chips-label">Filtros activos:</span>
                ${chips.map((c) => `
                    <button type="button" class="ltms-filter-chip" data-filter-remove="${c.key}" data-filter-value="${escapeHtml(c.value)}">
                        ${escapeHtml(c.label)}
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                `).join('')}
                <button type="button" class="ltms-filter-clear-all">Limpiar todo</button>
            `;

            chipsContainer.querySelectorAll('[data-filter-remove]').forEach((chip) => {
                chip.addEventListener('click', () => {
                    const key = chip.dataset.filterRemove;
                    const value = chip.dataset.filterValue;
                    if (key === 'price_min' || key === 'price_max') {
                        const input = sidebar.querySelector(`[data-price-range]`);
                        if (input) {
                            // Reset price range
                            applyFilters(true);
                        }
                    } else {
                        const cb = sidebar.querySelector(`input[data-filter="${key}"][value="${value}"]`);
                        if (cb) {
                            cb.checked = false;
                            cb.dispatchEvent(new Event('change'));
                        }
                    }
                });
            });

            chipsContainer.querySelector('.ltms-filter-clear-all')?.addEventListener('click', () => {
                sidebar.querySelectorAll('input[type="checkbox"][data-filter]:checked').forEach((cb) => cb.checked = false);
                sidebar.querySelectorAll('input[type="radio"][data-filter]:checked').forEach((r) => r.checked = false);
                applyFilters(true);
            });
        }

        // Bind events
        const debouncedApply = debounce(() => applyFilters(true), 300);

        sidebar.addEventListener('change', (e) => {
            if (e.target.matches('[data-filter]')) {
                applyFilters(true);
            }
        });

        sidebar.addEventListener('input', (e) => {
            if (e.target.matches('[data-filter-search]')) {
                debouncedApply();
            }
        });

        sidebar.addEventListener('change', (e) => {
            if (e.target.matches('[data-filter-sort]')) {
                applyFilters(false);
            }
        });

        // Mobile toggle
        const toggleBtn = document.querySelector('[data-filter-toggle]');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => {
                sidebar.classList.toggle('ltms-filter-sidebar-open');
                document.body.style.overflow = sidebar.classList.contains('ltms-filter-sidebar-open') ? 'hidden' : '';
            });
        }

        // Close on overlay click
        const overlay = document.querySelector('[data-filter-overlay]');
        if (overlay) {
            overlay.addEventListener('click', () => {
                sidebar.classList.remove('ltms-filter-sidebar-open');
                document.body.style.overflow = '';
            });
        }

        // Initial filter collection
        state.filters = collectFilters();
        updateActiveFiltersChips(state.filters);
    }

    // ═══════════════════════════════════════════════════════════
    // 77. MULTI-STEP CHECKOUT — Wizard de checkout
    // ═══════════════════════════════════════════════════════════

    /**
     * Wizard de checkout multi-paso con validación por paso,
     * indicador de progreso y persistencia de datos.
     */

    function initMultiStepCheckout() {
        const wizard = document.querySelector('[data-checkout-wizard]');
        if (!wizard) return;

        const steps = [...wizard.querySelectorAll('[data-checkout-step]')];
        const indicators = [...wizard.querySelectorAll('[data-step-indicator]')];
        let current = 0;

        function showStep(index) {
            steps.forEach((step, i) => {
                step.style.display = i === index ? 'block' : 'none';
            });

            indicators.forEach((ind, i) => {
                ind.classList.toggle('active', i === index);
                ind.classList.toggle('completed', i < index);
            });

            // Update buttons
            const prevBtn = wizard.querySelector('[data-checkout-prev]');
            const nextBtn = wizard.querySelector('[data-checkout-next]');
            const submitBtn = wizard.querySelector('[data-checkout-submit]');

            if (prevBtn) prevBtn.style.display = index > 0 ? '' : 'none';
            if (nextBtn) nextBtn.style.display = index < steps.length - 1 ? '' : 'none';
            if (submitBtn) submitBtn.style.display = index === steps.length - 1 ? '' : 'none';

            // Scroll to top
            wizard.scrollIntoView({ behavior: 'smooth', block: 'start' });

            // Announce
            const stepTitle = steps[index]?.dataset.checkoutStepTitle || `Paso ${index + 1}`;
            announce(`Paso ${index + 1} de ${steps.length}: ${stepTitle}`);

            current = index;
        }

        function validateStep(index) {
            const step = steps[index];
            if (!step) return true;

            const required = step.querySelectorAll('[required]');
            let valid = true;
            let firstError = null;

            required.forEach((field) => {
                if (!field.value.trim()) {
                    valid = false;
                    field.classList.add('ltms-input-error');
                    if (!firstError) firstError = field;
                } else {
                    field.classList.remove('ltms-input-error');
                }
            });

            if (!valid && firstError) {
                firstError.focus();
                toast('error', 'Campo requerido', 'Completa todos los campos obligatorios.');
            }

            return valid;
        }

        function nextStep() {
            if (!validateStep(current)) return;
            if (current < steps.length - 1) showStep(current + 1);
        }

        function prevStep() {
            if (current > 0) showStep(current - 1);
        }

        const nextBtn = wizard.querySelector('[data-checkout-next]');
        const prevBtn = wizard.querySelector('[data-checkout-prev]');
        const submitBtn = wizard.querySelector('[data-checkout-submit]');

        if (nextBtn) nextBtn.addEventListener('click', nextStep);
        if (prevBtn) prevBtn.addEventListener('click', prevStep);

        // Allow clicking on indicators to navigate (only to completed steps)
        indicators.forEach((ind, i) => {
            ind.addEventListener('click', () => {
                if (i < current || validateStep(current)) {
                    showStep(i);
                }
            });
        });

        // Persistence
        wizard.querySelectorAll('input, select, textarea').forEach((field) => {
            const key = 'ltms-checkout-' + (field.name || field.id);
            const saved = sessionStorage.getItem(key);
            if (saved && field.type !== 'password') {
                if (field.type === 'checkbox') field.checked = saved === 'true';
                else field.value = saved;
            }
            field.addEventListener('change', () => {
                sessionStorage.setItem(key, field.type === 'checkbox' ? field.checked : field.value);
            });
        });

        showStep(0);
    }

    // ═══════════════════════════════════════════════════════════
    // 78. ADDRESS AUTOCOMPLETE — Autocompletado de dirección
    // ═══════════════════════════════════════════════════════════

    /**
     * Autocompletado de direcciones usando la API de Google Places
     * (si está disponible) o fallback con sugerencias locales.
     */

    function initAddressAutocomplete() {
        document.querySelectorAll('[data-address-autocomplete]').forEach((input) => {
            if (input.dataset.acInit) return;
            input.dataset.acInit = 'true';

            const targetFields = {
                street: input.dataset.addressStreet,
                city: input.dataset.addressCity,
                state: input.dataset.addressState,
                zip: input.dataset.addressZip,
                country: input.dataset.addressCountry,
            };

            // Try Google Places
            if (typeof google !== 'undefined' && google.maps && google.maps.places) {
                const autocomplete = new google.maps.places.Autocomplete(input, {
                    types: ['address'],
                    fields: ['address_components', 'formatted_address'],
                });

                autocomplete.addListener('place_changed', () => {
                    const place = autocomplete.getPlace();
                    if (!place.address_components) return;

                    const components = {};
                    place.address_components.forEach((c) => {
                        c.types.forEach((t) => { components[t] = c.long_name; });
                    });

                    if (targetFields.street) {
                        const el = document.querySelector(targetFields.street);
                        if (el) el.value = `${components.street_number || ''} ${components.route || ''}`.trim();
                    }
                    if (targetFields.city) {
                        const el = document.querySelector(targetFields.city);
                        if (el) el.value = components.locality || components.administrative_area_level_2 || '';
                    }
                    if (targetFields.state) {
                        const el = document.querySelector(targetFields.state);
                        if (el) el.value = components.administrative_area_level_1 || '';
                    }
                    if (targetFields.zip) {
                        const el = document.querySelector(targetFields.zip);
                        if (el) el.value = components.postal_code || '';
                    }
                    if (targetFields.country) {
                        const el = document.querySelector(targetFields.country);
                        if (el) el.value = components.country || '';
                    }

                    input.value = place.formatted_address || '';
                    input.dispatchEvent(new Event('ltms:address-selected', { bubbles: true, detail: place }));
                    toast('success', 'Dirección completada', 'Revisa los campos autocompletados.');
                });
                return;
            }

            // Fallback: simple suggestions dropdown
            let dropdown = null;
            let debounceTimer = null;

            input.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                const query = input.value.trim();

                if (query.length < 3) {
                    if (dropdown) dropdown.remove();
                    return;
                }

                debounceTimer = setTimeout(() => {
                    // Simple local suggestions (can be replaced with API)
                    const suggestions = [
                        `Calle ${query}, Bogotá, Colombia`,
                        `Carrera ${query}, Medellín, Colombia`,
                        `Avenida ${query}, Cali, Colombia`,
                        `${query}, Ciudad de México, México`,
                    ];

                    if (dropdown) dropdown.remove();
                    dropdown = document.createElement('div');
                    dropdown.className = 'ltms-address-suggestions';
                    dropdown.innerHTML = suggestions.map((s) => `<div class="ltms-address-suggestion">${escapeHtml(s)}</div>`).join('');

                    input.parentNode.appendChild(dropdown);

                    dropdown.querySelectorAll('.ltms-address-suggestion').forEach((s) => {
                        s.addEventListener('click', () => {
                            input.value = s.textContent;
                            dropdown.remove();
                            dropdown = null;
                            input.dispatchEvent(new Event('ltms:address-selected', { bubbles: true }));
                        });
                    });
                }, 300);
            });

            input.addEventListener('blur', () => {
                setTimeout(() => { if (dropdown) dropdown.remove(); }, 200);
            });
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 79. REVIEW SYSTEM — Sistema de reseñas
    // ═══════════════════════════════════════════════════════════

    /**
     * Sistema de reseñas con rating, comentarios, fotos
     * y respuestas del vendedor.
     */

    function initReviewSystem() {
        // Review form con star rating integrado
        document.querySelectorAll('[data-review-form]').forEach((form) => {
            if (form.dataset.reviewInit) return;
            form.dataset.reviewInit = 'true';

            const ratingContainer = form.querySelector('[data-review-rating]');
            if (ratingContainer) {
                const rating = createStarRating({
                    max: 5,
                    initial: 0,
                    onChange: (val) => {
                        const hidden = form.querySelector('input[name="rating"]');
                        if (hidden) hidden.value = val;

                        // Update label
                        const labels = ['','Pésimo','Malo','Regular','Bueno','Excelente'];
                        const labelEl = form.querySelector('[data-review-rating-label]');
                        if (labelEl) labelEl.textContent = labels[val] || '';
                    },
                });
                ratingContainer.innerHTML = '';
                ratingContainer.appendChild(rating);
            }

            // Photo upload
            const photoInput = form.querySelector('[data-review-photos]');
            if (photoInput) {
                photoInput.addEventListener('change', () => {
                    const preview = form.querySelector('[data-review-photo-preview]');
                    if (!preview) return;

                    preview.innerHTML = '';
                    [...photoInput.files].slice(0, 4).forEach((file) => {
                        const reader = new FileReader();
                        reader.onload = (e) => {
                            const div = document.createElement('div');
                            div.className = 'ltms-review-photo-thumb';
                            div.innerHTML = `<img src="${e.target.result}" alt="">`;
                            preview.appendChild(div);
                        };
                        reader.readAsDataURL(file);
                    });
                });
            }

            // Submit
            form.addEventListener('submit', (e) => {
                e.preventDefault();
                const rating = form.querySelector('input[name="rating"]')?.value;
                if (!rating || rating === '0') {
                    toast('warning', 'Calificación requerida', 'Selecciona al menos 1 estrella.');
                    return;
                }

                const btn = form.querySelector('[type="submit"]');
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<span class="ltms-spinner"></span> Enviando...';
                }

                // AJAX submit.
                // Task 67-B — Prefer the global ltmsUX bootstrap (available
                // everywhere), fall back to ltmsDashboard (vendor dashboard
                // context). Previously this gated on ltmsDashboard only and
                // silently failed on the customer-facing storefront.
                const ajaxUrl   = (typeof ltmsUX !== 'undefined' && ltmsUX.ajax_url) || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url);
                const ajaxNonce = (typeof ltmsUX !== 'undefined' && ltmsUX.nonce)     || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce);

                if (typeof jQuery !== 'undefined' && ajaxUrl && ajaxNonce) {
                    jQuery.post(ajaxUrl, {
                        action: 'ltms_submit_review',
                        nonce: ajaxNonce,
                        product_id: form.dataset.reviewForm,
                        rating: rating,
                        title: form.querySelector('[name="title"]')?.value || '',
                        comment: form.querySelector('[name="content"]')?.value || '',
                    }, (response) => {
                        if (response.success) {
                            toast('success', 'Reseña enviada', response.data?.message || '¡Gracias por tu calificación!');
                            form.reset();
                            form.querySelector('[data-review-photo-preview]')?.replaceChildren();
                            if (ratingContainer) {
                                ratingContainer.querySelector('.ltms-star-rating')?.dispatchEvent(new Event('reset'));
                            }
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            toast('error', 'Error', response.data?.message || response.data || 'No se pudo enviar la reseña.');
                        }
                    }).fail(() => {
                        toast('error', 'Error', 'No se pudo enviar. Intenta de nuevo.');
                    }).always(() => {
                        if (btn) {
                            btn.disabled = false;
                            btn.innerHTML = 'Publicar reseña';
                        }
                    });
                } else {
                    // No AJAX bootstrap — surface a clear error instead of
                    // silently dropping the submission.
                    toast('error', 'No disponible', 'No se pudo enviar la reseña en este momento. Recarga la página e inténtalo de nuevo.');
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = 'Publicar reseña';
                    }
                }
            });
        });

        // Helpful voting
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-review-helpful]');
            if (!btn) return;
            e.preventDefault();

            const reviewId = btn.dataset.reviewHelpful;
            const countEl = btn.querySelector('[data-helpful-count]');
            if (!countEl) return;

            const current = parseInt(countEl.textContent || '0', 10);
            countEl.textContent = current + 1;
            btn.classList.add('voted');
            btn.disabled = true;

            if (typeof jQuery !== 'undefined' && typeof ltmsDashboard !== 'undefined') {
                jQuery.post(ltmsDashboard.ajax_url, {
                    action: 'ltms_review_helpful',
                    nonce: ltmsDashboard.nonce,
                    review_id: reviewId,
                });
            }

            toast('success', '¡Gracias!', 'Tu voto ayuda a otros compradores.');
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 80. ORDER SUCCESS — Animación de pedido exitoso
    // ═══════════════════════════════════════════════════════════

    /**
     * Animación de celebración cuando se completa un pedido:
     * check animado, confetti y resumen del pedido.
     */

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

    function initOrderSuccess() {
        // Auto-detect WooCommerce order received page
        if (document.body.classList.contains('woocommerce-order-received')) {
            const orderNumber = document.querySelector('.woocommerce-order-overview__order.order > strong')?.textContent;
            showOrderSuccess({
                order_number: orderNumber,
                message: '¡Gracias por tu compra!',
                continue_url: home_url || '/',
                track_url: window.location.href,
            });
        }

        // Manual trigger
        document.addEventListener('click', (e) => {
            const trigger = e.target.closest('[data-order-success]');
            if (!trigger) return;
            e.preventDefault();
            showOrderSuccess({
                order_number: trigger.dataset.orderSuccess,
                continue_url: trigger.dataset.continueUrl,
                track_url: trigger.dataset.trackUrl,
            });
        });
    }

    LTMS.UX.showOrderSuccess = showOrderSuccess;

    // ═══════════════════════════════════════════════════════════
    // 81. BACKORDER — Notificación de pre-order/backorder
    // ═══════════════════════════════════════════════════════════

    /**
     * Muestra notificaciones cuando un producto está agotado
     * pero disponible para pre-order o backorder.
     */

    function initBackorderNotice() {
        document.querySelectorAll('[data-backorder]').forEach((el) => {
            if (el.dataset.boInit) return;
            el.dataset.boInit = 'true';

            const type = el.dataset.backorder; // 'pre-order' or 'backorder'
            const eta = el.dataset.backorderEta;
            const allowNotify = el.dataset.backorderNotify === 'true';

            const icon = type === 'pre-order'
                ? '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>'
                : '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>';

            el.className = 'ltms-backorder-notice ltms-backorder-' + type;
            el.innerHTML = `
                <div class="ltms-backorder-icon">${icon}</div>
                <div class="ltms-backorder-content">
                    <strong>${type === 'pre-order' ? 'Disponible en pre-order' : 'Producto en backorder'}</strong>
                    ${eta ? `<span>Fecha estimada de disponibilidad: <strong>${escapeHtml(eta)}</strong></span>` : ''}
                    ${type === 'pre-order' ? '<span class="ltms-backorder-hint">Reserva ahora y recíbelo cuando esté disponible.</span>' : '<span class="ltms-backorder-hint">Puedes comprarlo ahora, se enviará cuando tengamos stock.</span>'}
                </div>
                ${allowNotify ? `<button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-backorder-notify" data-backorder-notify="${el.dataset.productId || ''}">Avísame</button>` : ''}
            `;

            // Notify button
            const notifyBtn = el.querySelector('[data-backorder-notify]');
            if (notifyBtn) {
                notifyBtn.addEventListener('click', () => {
                    if (typeof jQuery !== 'undefined' && typeof ltmsDashboard !== 'undefined') {
                        jQuery.post(ltmsDashboard.ajax_url, {
                            action: 'ltms_backorder_notify',
                            nonce: ltmsDashboard.nonce,
                            product_id: notifyBtn.dataset.backorderNotify,
                        }, (response) => {
                            if (response.success) {
                                toast('success', 'Notificación activada', 'Te avisaremos cuando el producto esté disponible.');
                                notifyBtn.disabled = true;
                                notifyBtn.textContent = '✓ Notificación activa';
                            }
                        });
                    } else {
                        toast('success', 'Notificación activada', 'Te avisaremos cuando esté disponible.');
                        notifyBtn.disabled = true;
                        notifyBtn.textContent = '✓ Activado';
                    }
                });
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 82. STICKY ADD-TO-CART — Barra fija para móvil
    // ═══════════════════════════════════════════════════════════

    /**
     * Barra fija en el bottom de la pantalla que aparece cuando
     * el botón de añadir al carrito sale del viewport.
     * Muestra precio, nombre y botón de compra rápida.
     */

    let stickyBar = null;
    let stickyBarVisible = false;

    function initStickyAddToCart() {
        // Solo en páginas de producto
        const addToCartBtn = document.querySelector('.single_add_to_cart_button, [data-add-to-cart-btn]');
        if (!addToCartBtn) return;

        // Crear barra
        stickyBar = document.createElement('div');
        stickyBar.className = 'ltms-sticky-addcart';
        stickyBar.innerHTML = `
            <div class="ltms-sticky-addcart-info">
                <div class="ltms-sticky-addcart-img" id="ltms-sticky-img"></div>
                <div class="ltms-sticky-addcart-text">
                    <div class="ltms-sticky-addcart-name" id="ltms-sticky-name"></div>
                    <div class="ltms-sticky-addcart-price" id="ltms-sticky-price"></div>
                </div>
            </div>
            <button type="button" class="ltms-btn ltms-btn-primary ltms-sticky-addcart-btn" id="ltms-sticky-btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                Añadir al carrito
            </button>
        `;
        document.body.appendChild(stickyBar);

        // Llenar datos del producto
        const productImg = document.querySelector('.woocommerce-product-gallery__image img, .product-image img');
        const productName = document.querySelector('.product_title, .product-name, h1');
        const productPrice = document.querySelector('.price, .product-price, .woocommerce-Price-amount');

        if (productImg) stickyBar.querySelector('#ltms-sticky-img').style.backgroundImage = `url(${productImg.src})`;
        if (productName) stickyBar.querySelector('#ltms-sticky-name').textContent = productName.textContent.trim();
        if (productPrice) stickyBar.querySelector('#ltms-sticky-price').textContent = productPrice.textContent.trim();

        // Botón: disparar el botón real de añadir al carrito
        stickyBar.querySelector('#ltms-sticky-btn').addEventListener('click', () => {
            addToCartBtn.click();
            toast('success', 'Añadido al carrito', '');
        });

        // Observer para detectar cuando el botón sale del viewport
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                const shouldShow = !entry.isIntersecting && entry.boundingClientRect.top < 0;

                if (shouldShow && !stickyBarVisible) {
                    stickyBar.classList.add('visible');
                    stickyBarVisible = true;
                    // Ajustar padding del body para que el bottom nav no solape
                    if (window.innerWidth <= 768) {
                        document.body.style.paddingBottom = '140px';
                    }
                } else if (!shouldShow && stickyBarVisible) {
                    stickyBar.classList.remove('visible');
                    stickyBarVisible = false;
                    document.body.style.paddingBottom = '';
                }
            });
        }, { threshold: 0 });

        observer.observe(addToCartBtn);
    }

    // ═══════════════════════════════════════════════════════════
    // 83. PRODUCT TABS — Pestañas de producto
    // ═══════════════════════════════════════════════════════════

    /**
     * Sistema de pestañas para páginas de producto:
     * Descripción, Especificaciones, Reseñas, Envío, etc.
     */

    function initProductTabs() {
        document.querySelectorAll('[data-product-tabs]').forEach((container) => {
            if (container.dataset.tabsInit) return;
            container.dataset.tabsInit = 'true';

            const triggers = container.querySelectorAll('[data-tab-trigger]');
            const panels = container.querySelectorAll('[data-tab-panel]');

            triggers.forEach((trigger) => {
                trigger.addEventListener('click', () => {
                    const target = trigger.dataset.tabTrigger;

                    // Update triggers
                    triggers.forEach((t) => {
                        t.classList.remove('active');
                        t.setAttribute('aria-selected', 'false');
                    });
                    trigger.classList.add('active');
                    trigger.setAttribute('aria-selected', 'true');

                    // Update panels
                    panels.forEach((panel) => {
                        const isActive = panel.dataset.tabPanel === target;
                        panel.classList.toggle('active', isActive);
                        panel.style.display = isActive ? 'block' : 'none';
                    });

                    announce(`Pestaña: ${trigger.textContent.trim()}`);
                });
            });

            // Keyboard navigation
            triggers.forEach((trigger, i) => {
                trigger.addEventListener('keydown', (e) => {
                    let newIndex = i;
                    if (e.key === 'ArrowRight') { e.preventDefault(); newIndex = (i + 1) % triggers.length; }
                    else if (e.key === 'ArrowLeft') { e.preventDefault(); newIndex = (i - 1 + triggers.length) % triggers.length; }
                    else if (e.key === 'Home') { e.preventDefault(); newIndex = 0; }
                    else if (e.key === 'End') { e.preventDefault(); newIndex = triggers.length - 1; }

                    if (newIndex !== i) {
                        triggers[newIndex].focus();
                        triggers[newIndex].click();
                    }
                });
            });

            // Activar primera pestaña por defecto si no hay ninguna activa
            if (!container.querySelector('[data-tab-trigger].active') && triggers.length) {
                triggers[0].click();
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 84. VARIANT SELECTOR — Selector de variantes
    // ═══════════════════════════════════════════════════════════

    /**
     * Selector de variantes de producto (color, talla, material)
     * con feedback visual, disponibilidad y sincronización de precio.
     */

    function initVariantSelector() {
        document.querySelectorAll('[data-variant-group]').forEach((group) => {
            if (group.dataset.variantInit) return;
            group.dataset.variantInit = 'true';

            const variantName = group.dataset.variantGroup; // ej: "color", "size"
            const options = group.querySelectorAll('[data-variant-option]');
            const labelEl = group.querySelector('[data-variant-label]');
            const priceEl = document.querySelector(group.dataset.variantPriceTarget || '[data-variant-price]');
            const stockEl = document.querySelector(group.dataset.variantStockTarget || '[data-variant-stock]');

            options.forEach((option) => {
                option.addEventListener('click', () => {
                    if (option.classList.contains('disabled')) return;

                    // Deselect all
                    options.forEach((o) => {
                        o.classList.remove('selected');
                        o.setAttribute('aria-pressed', 'false');
                    });

                    // Select this
                    option.classList.add('selected');
                    option.setAttribute('aria-pressed', 'true');

                    // Update label
                    if (labelEl) {
                        labelEl.textContent = option.dataset.variantLabel || option.textContent.trim();
                    }

                    // Update price
                    if (priceEl && option.dataset.variantPrice) {
                        priceEl.textContent = option.dataset.variantPrice;
                    }

                    // Update stock
                    if (stockEl && option.dataset.variantStock !== undefined) {
                        const stock = parseInt(option.dataset.variantStock, 10);
                        stockEl.innerHTML = renderStockIndicator(stock, {
                            threshold: 5,
                            maxStock: 50,
                        });
                    }

                    // Update hidden input
                    const hidden = document.querySelector(`input[name="${variantName}"]`);
                    if (hidden) hidden.value = option.dataset.variantValue || option.textContent.trim();

                    // Update add-to-cart button state
                    const addToCartBtn = document.querySelector('.single_add_to_cart_button, [data-add-to-cart-btn]');
                    if (addToCartBtn && option.dataset.variantDisabled === 'true') {
                        addToCartBtn.disabled = true;
                        addToCartBtn.textContent = 'No disponible';
                    } else if (addToCartBtn) {
                        addToCartBtn.disabled = false;
                        addToCartBtn.innerHTML = 'Añadir al carrito';
                    }

                    // Dispatch event
                    group.dispatchEvent(new CustomEvent('ltms:variant-selected', {
                        detail: {
                            name: variantName,
                            value: option.dataset.variantValue || option.textContent.trim(),
                            label: option.dataset.variantLabel || option.textContent.trim(),
                            price: option.dataset.variantPrice,
                            stock: option.dataset.variantStock,
                        },
                        bubbles: true,
                    }));
                });
            });
        });

        // Auto-check all variant groups are selected before add to cart
        const addToCartBtn = document.querySelector('.single_add_to_cart_button, [data-add-to-cart-btn]');
        if (addToCartBtn) {
            addToCartBtn.addEventListener('click', (e) => {
                const groups = document.querySelectorAll('[data-variant-group][data-variant-required="true"]');
                let allSelected = true;

                groups.forEach((group) => {
                    if (!group.querySelector('[data-variant-option].selected')) {
                        allSelected = false;
                        group.classList.add('ltms-variant-error');
                        const label = group.dataset.variantGroup;
                        toast('warning', 'Selecciona una opción', `Por favor selecciona ${label}.`);
                    } else {
                        group.classList.remove('ltms-variant-error');
                    }
                });

                if (!allSelected) {
                    e.preventDefault();
                    e.stopPropagation();
                    return false;
                }
            }, true);
        }
    }

    // ═══════════════════════════════════════════════════════════
    // 85. TRUST BADGES — Insignian de confianza
    // ═══════════════════════════════════════════════════════════

    /**
     * Componente de badges de confianza: pago seguro, envío gratis,
     * garantía, devoluciones, etc.
     */

    const TRUST_BADGES = {
        secure_payment: {
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
            label: 'Pago seguro',
            desc: 'Tus datos están protegidos',
            color: '#16A34A',
        },
        free_shipping: {
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
            label: 'Envío gratis',
            desc: 'En compras superiores a $100.000',
            color: '#3282B8',
        },
        warranty: {
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4"/><path d="M21 12c0 5-3.5 7.5-8.5 9.5C7.5 19.5 4 17 4 12V6l8-3 8 3v6z"/></svg>',
            label: 'Garantía',
            desc: '30 días de garantía',
            color: '#F39C12',
        },
        returns: {
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>',
            label: 'Devoluciones',
            desc: '15 días para devoluciones',
            color: '#8B5CF6',
        },
        support: {
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>',
            label: 'Soporte 24/7',
            desc: 'Atención al cliente siempre',
            color: '#EC4899',
        },
        authentic: {
            icon: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
            label: '100% Auténtico',
            desc: 'Productos originales garantizados',
            color: '#16A34A',
        },
    };

    function renderTrustBadges(container, badges = []) {
        if (typeof container === 'string') container = document.querySelector(container);
        if (!container) return;

        const badgeKeys = badges.length ? badges : Object.keys(TRUST_BADGES);

        container.className = 'ltms-trust-badges';
        container.innerHTML = badgeKeys.map((key) => {
            const badge = TRUST_BADGES[key];
            if (!badge) return '';
            return `
                <div class="ltms-trust-badge" style="--badge-color:${badge.color};">
                    <div class="ltms-trust-badge-icon">${badge.icon}</div>
                    <div class="ltms-trust-badge-content">
                        <strong>${escapeHtml(badge.label)}</strong>
                        <span>${escapeHtml(badge.desc)}</span>
                    </div>
                </div>
            `;
        }).join('');
    }

    function initTrustBadges() {
        document.querySelectorAll('[data-trust-badges]').forEach((el) => {
            if (el.dataset.tbInit) return;
            el.dataset.tbInit = 'true';

            const badges = el.dataset.trustBadges ? el.dataset.trustBadges.split(',') : [];
            renderTrustBadges(el, badges);
        });
    }

    LTMS.UX.renderTrustBadges = renderTrustBadges;

    // ═══════════════════════════════════════════════════════════
    // 86. DELIVERY DATE PICKER — Selector de fecha de entrega
    // ═══════════════════════════════════════════════════════════

    /**
     * Selector de fecha de entrega con:
     * - Días no laborables excluidos
     * - Fecha mínima (envío en X días)
     * - Slots horarios opcionales
     */

    function initDeliveryDatePickers() {
        document.querySelectorAll('[data-delivery-picker]').forEach((input) => {
            if (input.dataset.ddpInit) return;
            input.dataset.ddpInit = 'true';

            const minDays = parseInt(input.dataset.minDays || '2', 10);
            const excludeWeekends = input.dataset.excludeWeekends === 'true';
            const excludedDates = (input.dataset.excludedDates || '').split(',').filter(Boolean);
            const slotContainer = document.querySelector(input.dataset.slotTarget);

            // Set min date
            const minDate = new Date();
            minDate.setDate(minDate.getDate() + minDays);
            input.min = minDate.toISOString().split('T')[0];

            // Validate date on change
            input.addEventListener('change', () => {
                const selected = new Date(input.value);
                const day = selected.getDay();

                let error = null;

                // Check weekend
                if (excludeWeekends && (day === 0 || day === 6)) {
                    error = 'No hacemos entregas en fin de semana.';
                }

                // Check excluded dates
                const dateStr = selected.toISOString().split('T')[0];
                if (excludedDates.includes(dateStr)) {
                    error = 'No hay entregas disponibles en esta fecha.';
                }

                // Check past dates
                if (selected < minDate) {
                    error = `La fecha mínima de entrega es ${minDate.toLocaleDateString('es-CO')}.`;
                }

                if (error) {
                    toast('warning', 'Fecha no válida', error);
                    input.value = '';
                    if (slotContainer) slotContainer.innerHTML = '';
                    return;
                }

                // Load time slots
                if (slotContainer) {
                    loadTimeSlots(slotContainer, input.value);
                }

                // Dispatch event
                input.dispatchEvent(new CustomEvent('ltms:delivery-date-selected', {
                    detail: { date: input.value },
                    bubbles: true,
                }));
            });
        });
    }

    function loadTimeSlots(container, date) {
        const slots = [
            { value: '09:00-12:00', label: 'Mañana (9:00 - 12:00)' },
            { value: '12:00-15:00', label: 'Mediodía (12:00 - 15:00)' },
            { value: '15:00-18:00', label: 'Tarde (15:00 - 18:00)' },
            { value: '18:00-20:00', label: 'Noche (18:00 - 20:00)' },
        ];

        container.innerHTML = `
            <div class="ltms-delivery-slots">
                <label class="ltms-delivery-slots-title">Horario de entrega:</label>
                <div class="ltms-delivery-slots-grid">
                    ${slots.map((slot, i) => `
                        <label class="ltms-delivery-slot">
                            <input type="radio" name="delivery_slot" value="${escapeHtml(slot.value)}" ${i === 0 ? 'checked' : ''}>
                            <span>${escapeHtml(slot.label)}</span>
                        </label>
                    `).join('')}
                </div>
            </div>
        `;
    }

    // ═══════════════════════════════════════════════════════════
    // 87. GIFT WRAPPING — Opción de envoltura de regalo
    // ═══════════════════════════════════════════════════════════

    /**
     * Permite añadir envoltura de regalo al pedido con mensaje
     * personalizado y vista previa.
     */

    function initGiftWrapping() {
        document.querySelectorAll('[data-gift-wrapping]').forEach((container) => {
            if (container.dataset.gwInit) return;
            container.dataset.gwInit = 'true';

            const price = container.dataset.giftWrappingPrice || '$5.000';
            const wrapper = document.createElement('div');
            wrapper.className = 'ltms-gift-wrapping';
            wrapper.innerHTML = `
                <label class="ltms-gift-wrapping-toggle">
                    <input type="checkbox" id="ltms-gift-wrapping-check">
                    <span class="ltms-gift-wrapping-icon">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>
                    </span>
                    <div class="ltms-gift-wrapping-info">
                        <strong>Envoltura de regalo</strong>
                        <span>+${escapeHtml(price)} · Mensaje personalizado incluido</span>
                    </div>
                </label>
                <div class="ltms-gift-wrapping-options" style="display:none;">
                    <div class="ltms-gift-wrapping-preview">
                        <div class="ltms-gift-preview-box">
                            <div class="ltms-gift-preview-ribbon"></div>
                        </div>
                    </div>
                    <textarea class="ltms-gift-wrapping-message" placeholder="Mensaje para la tarjeta de regalo (máx. 200 caracteres)" maxlength="200" rows="3"></textarea>
                    <div class="ltms-gift-wrapping-counter"><span>0</span>/200</div>
                </div>
            `;
            container.appendChild(wrapper);

            const check = wrapper.querySelector('#ltms-gift-wrapping-check');
            const options = wrapper.querySelector('.ltms-gift-wrapping-options');
            const message = wrapper.querySelector('.ltms-gift-wrapping-message');
            const counter = wrapper.querySelector('.ltms-gift-wrapping-counter span');

            check.addEventListener('change', () => {
                options.style.display = check.checked ? 'block' : 'none';

                // UX-FAKE-5 FIX — Persist the gift-wrapping choice in a hidden
                // input named `ltms_gift_wrapping` so WooCommerce's checkout
                // POST includes it and the `woocommerce_cart_calculate_fees`
                // handler (registered in class-ltms-frontend-checkout-handler.php)
                // can read it via `$_POST['ltms_gift_wrapping']`. Without this,
                // the checkbox visually toggled but no fee was ever applied.
                // The hidden input is created on the fly if the page does not
                // already provide one so the value survives navigation.
                let hidden = document.querySelector('input[name="ltms_gift_wrapping"]');
                if (!hidden) {
                    hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'ltms_gift_wrapping';
                    // Append to the WC checkout form when present, otherwise to body.
                    const checkoutForm = document.querySelector('form.checkout, form.woocommerce-checkout, #ltms-checkout-form');
                    (checkoutForm || document.body).appendChild(hidden);
                }
                hidden.value = check.checked ? 'yes' : 'no';

                // Dispatch event
                container.dispatchEvent(new CustomEvent('ltms:gift-wrapping-change', {
                    detail: { enabled: check.checked },
                    bubbles: true,
                }));

                if (check.checked) {
                    toast('success', 'Envoltura de regalo añadida', `+${price}`);
                    // Trigger WC checkout review refresh so the new fee
                    // appears in the order totals immediately. On non-checkout
                    // pages this is a no-op (no listener bound).
                    if (typeof jQuery !== 'undefined') {
                        try { jQuery(document.body).trigger('update_checkout'); } catch (e) {}
                    }
                } else {
                    if (typeof jQuery !== 'undefined') {
                        try { jQuery(document.body).trigger('update_checkout'); } catch (e) {}
                    }
                }
            });

            message.addEventListener('input', () => {
                counter.textContent = message.value.length;
            });
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 88. ABANDONED CART — Recuperación de carrito abandonado
    // ═══════════════════════════════════════════════════════════

    /**
     * Detecta cuando el usuario va a abandonar la página con items
     * en el carrito y muestra un modal de recuperación con incentivo.
     */

    let cartRecoveryShown = false;

    function initAbandonedCartRecovery() {
        // Solo en páginas con carrito (no checkout, no order-received)
        if (document.body.classList.contains('woocommerce-checkout')) return;
        if (document.body.classList.contains('woocommerce-order-received')) return;
        if (document.querySelector('.ltms-auth-container')) return;

        // Detectar mouseleave hacia la parte superior (intención de salir)
        document.addEventListener('mouseleave', (e) => {
            if (e.clientY <= 0 && !cartRecoveryShown) {
                checkCartAndShowRecovery();
            }
        });

        // También detectar en mobile: visibilitychange
        document.addEventListener('visibilitychange', () => {
            if (document.hidden && !cartRecoveryShown) {
                // Guardar timestamp para mostrar al volver
                try { sessionStorage.setItem('ltms-cart-abandon-time', Date.now().toString()); } catch (e) {}
            } else if (!document.hidden && !cartRecoveryShown) {
                const abandonTime = sessionStorage.getItem('ltms-cart-abandon-time');
                if (abandonTime) {
                    const elapsed = Date.now() - parseInt(abandonTime, 10);
                    if (elapsed > 30000) { // >30s away
                        sessionStorage.removeItem('ltms-cart-abandon-time');
                        checkCartAndShowRecovery();
                    }
                }
            }
        });
    }

    function checkCartAndShowRecovery() {
        // Check if cart has items via WooCommerce
        if (typeof jQuery === 'undefined') return;

        const cartCountEl = document.querySelector('.ltms-sf-cart-count, .cart-count');
        const cartCount = cartCountEl ? parseInt(cartCountEl.textContent || '0', 10) : 0;

        if (cartCount === 0) {
            // Try WC fragments
            if (typeof wc_cart_fragments_params !== 'undefined') {
                jQuery.get(wc_cart_fragments_params.wc_ajax_url.replace('%%endpoint%%', 'get_refreshed_fragments'), (response) => {
                    if (response.fragments && response.cart_hash && response.cart_hash !== '') {
                        showCartRecoveryModal();
                    }
                });
            }
            return;
        }

        showCartRecoveryModal();
    }

    function showCartRecoveryModal() {
        cartRecoveryShown = true;

        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay ltms-cart-recovery-overlay';
        overlay.innerHTML = `
            <div class="ltms-modal ltms-cart-recovery-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-cr-title">
                <div class="ltms-cart-recovery-header">
                    <div class="ltms-cart-recovery-icon">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                    </div>
                    <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ltms-modal-body ltms-cart-recovery-body">
                    <h3 class="ltms-cart-recovery-title" id="ltms-cr-title">¡Espera! Tienes items en tu carrito</h3>
                    <p class="ltms-cart-recovery-msg">No te vayas sin completar tu compra. Tienes productos esperándote.</p>
                    <div class="ltms-cart-recovery-incentive">
                        <div class="ltms-cart-recovery-incentive-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12V8H6a2 2 0 0 1-2-2c0-1.1.9-2 2-2h12v4"/><path d="M4 6v12c0 1.1.9 2 2 2h14v-4"/><path d="M18 12a2 2 0 0 0-2 2c0 1.1.9 2 2 2h4v-4h-4z"/></svg>
                        </div>
                        <div>
                            <strong>¡Oferta especial para ti!</strong>
                            <span>Usa el código <code>QUEDATE10</code> para 10% de descuento</span>
                        </div>
                    </div>
                </div>
                <div class="ltms-modal-footer ltms-cart-recovery-footer">
                    <button type="button" class="ltms-btn ltms-btn-outline ltms-modal-close">Seguir navegando</button>
                    <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-cr-continue">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                        Ir al carrito
                    </button>
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

        overlay.querySelector('#ltms-cr-continue').addEventListener('click', () => {
            close();
            openCartDrawer();
        });

        announce('Tienes productos en tu carrito. ¿Deseas completar tu compra?');
    }

    // ═══════════════════════════════════════════════════════════
    // 89. PRODUCT RECOMMENDATIONS — "También te puede gustar"
    // ═══════════════════════════════════════════════════════════

    /**
     * Widget de productos recomendados basado en:
     * - Productos vistos recientemente
     * - Categoría del producto actual
     * - Comprados juntos
     */

    function initProductRecommendations() {
        document.querySelectorAll('[data-recommendations]').forEach((container) => {
            if (container.dataset.recInit) return;
            container.dataset.recInit = 'true';

            const type = container.dataset.recommendations; // 'related', 'viewed', 'cross-sell'
            const limit = parseInt(container.dataset.recLimit || '6', 10);
            // Task 67-B — Prefer the server-provided URL, fall back to the
            // global ltmsUX bootstrap, then to ltmsDashboard for back-compat.
            const ajaxUrl   = container.dataset.recAjax
                || (typeof ltmsUX !== 'undefined' && ltmsUX.ajax_url)
                || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url);
            const ajaxNonce = (typeof ltmsUX !== 'undefined' && ltmsUX.nonce)
                || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce);

            if (ajaxUrl && ajaxNonce && typeof jQuery !== 'undefined') {
                jQuery.post(ajaxUrl, {
                    action: 'ltms_get_recommendations',
                    nonce: ajaxNonce,
                    type: type,
                    limit: limit,
                    product_id: container.dataset.productId || '',
                }, (response) => {
                    if (response.success && response.data && response.data.products) {
                        renderRecommendations(container, response.data.products, type);
                    }
                });
            } else {
                // Fallback: usar recently viewed
                const recent = JSON.parse(localStorage.getItem('ltms-recently-viewed') || '[]');
                if (recent.length) {
                    renderRecommendations(container, recent.slice(0, limit).map((r) => ({
                        id: r.id,
                        name: r.name,
                        price: r.price,
                        image: r.image,
                        url: r.url,
                    })), 'viewed');
                }
            }
        });
    }

    function renderRecommendations(container, products, type) {
        if (!products || !products.length) return;

        const titles = {
            'related': 'Productos relacionados',
            'viewed': 'Vistos recientemente',
            'cross-sell': 'También te puede gustar',
            'up-sell': 'Mejora tu experiencia',
        };

        const title = titles[type] || 'Recomendados para ti';

        container.className = 'ltms-recommendations';
        container.innerHTML = `
            <div class="ltms-recommendations-header">
                <h3>${escapeHtml(title)}</h3>
            </div>
            <div class="ltms-recommendations-scroll">
                ${products.map((p) => `
                    <a href="${escapeHtml(p.url || '#')}" class="ltms-recommendation-card" data-product-id="${p.id || ''}" data-quick-view="${p.id || ''}">
                        <div class="ltms-recommendation-img">
                            ${p.image ? `<img src="${escapeHtml(p.image)}" alt="${escapeHtml(p.name)}" loading="lazy">` : '📦'}
                        </div>
                        <div class="ltms-recommendation-info">
                            <div class="ltms-recommendation-name">${escapeHtml(p.name || '')}</div>
                            <div class="ltms-recommendation-price">${escapeHtml(p.price || '')}</div>
                        </div>
                    </a>
                `).join('')}
            </div>
        `;
    }

    // ═══════════════════════════════════════════════════════════
    // 90. SEARCH AUTOCOMPLETE — Búsqueda con sugerencias
    // ═══════════════════════════════════════════════════════════

    /**
     * Autocompletado de búsqueda con sugerencias en tiempo real:
     * productos, categorías, búsquedas populares.
     */

    function initSearchAutocomplete() {
        document.querySelectorAll('[data-search-autocomplete]').forEach((input) => {
            if (input.dataset.sacInit) return;
            input.dataset.sacInit = 'true';

            const ajaxUrl = input.dataset.searchAutocomplete
                || (typeof ltmsUX !== 'undefined' && ltmsUX.ajax_url)
                || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url);
            const ajaxNonce = (typeof ltmsUX !== 'undefined' && ltmsUX.nonce)
                || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce);
            const minChars = parseInt(input.dataset.minChars || '2', 10);
            let dropdown = null;
            let timer = null;
            let currentRequest = null;

            input.setAttribute('autocomplete', 'off');

            function createDropdown() {
                if (dropdown) dropdown.remove();
                dropdown = document.createElement('div');
                dropdown.className = 'ltms-search-autocomplete';
                input.parentNode.style.position = 'relative';
                input.parentNode.appendChild(dropdown);
            }

            function showLoading() {
                createDropdown();
                dropdown.innerHTML = '<div class="ltms-sac-loading"><div class="ltms-spinner-lg"></div></div>';
            }

            function showResults(data) {
                createDropdown();

                if (!data || (!data.products?.length && !data.categories?.length && !data.popular?.length)) {
                    dropdown.innerHTML = '<div class="ltms-sac-empty">Sin resultados. Intenta con otros términos.</div>';
                    return;
                }

                let html = '';

                if (data.products?.length) {
                    html += `
                        <div class="ltms-sac-section">
                            <div class="ltms-sac-section-title">Productos</div>
                            ${data.products.slice(0, 5).map((p) => `
                                <a href="${escapeHtml(p.url || '#')}" class="ltms-sac-item ltms-sac-product">
                                    <div class="ltms-sac-item-img">${p.image ? `<img src="${escapeHtml(p.image)}" alt="" loading="lazy">` : '📦'}</div>
                                    <div class="ltms-sac-item-info">
                                        <div class="ltms-sac-item-name">${escapeHtml(p.name)}</div>
                                        <div class="ltms-sac-item-price">${escapeHtml(p.price || '')}</div>
                                    </div>
                                </a>
                            `).join('')}
                        </div>
                    `;
                }

                if (data.categories?.length) {
                    html += `
                        <div class="ltms-sac-section">
                            <div class="ltms-sac-section-title">Categorías</div>
                            ${data.categories.slice(0, 3).map((c) => `
                                <a href="${escapeHtml(c.url || '#')}" class="ltms-sac-item ltms-sac-category">
                                    <div class="ltms-sac-item-icon">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
                                    </div>
                                    <div class="ltms-sac-item-name">${escapeHtml(c.name)}</div>
                                </a>
                            `).join('')}
                        </div>
                    `;
                }

                if (data.popular?.length && !data.products?.length) {
                    html += `
                        <div class="ltms-sac-section">
                            <div class="ltms-sac-section-title">Búsquedas populares</div>
                            ${data.popular.slice(0, 5).map((term) => `
                                <a href="#" class="ltms-sac-item ltms-sac-popular" data-sac-term="${escapeHtml(term)}">
                                    <div class="ltms-sac-item-icon">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                    </div>
                                    <div class="ltms-sac-item-name">${escapeHtml(term)}</div>
                                </a>
                            `).join('')}
                        </div>
                    `;
                }

                html += `
                    <div class="ltms-sac-footer">
                        <kbd>Enter</kbd> para ver todos los resultados
                    </div>
                `;

                dropdown.innerHTML = html;

                // Click en búsqueda popular
                dropdown.querySelectorAll('[data-sac-term]').forEach((el) => {
                    el.addEventListener('click', (e) => {
                        e.preventDefault();
                        input.value = el.dataset.sacTerm;
                        input.form?.submit();
                    });
                });
            }

            input.addEventListener('input', () => {
                clearTimeout(timer);
                const query = input.value.trim();

                if (query.length < minChars) {
                    if (dropdown) dropdown.remove();
                    return;
                }

                timer = setTimeout(() => {
                    showLoading();

                    if (currentRequest) currentRequest.abort();

                    if (ajaxUrl && ajaxNonce && typeof jQuery !== 'undefined') {
                        currentRequest = jQuery.post(ajaxUrl, {
                            action: 'ltms_search_autocomplete',
                            nonce: ajaxNonce,
                            query: query,
                        }, (response) => {
                            if (response.success) {
                                showResults(response.data);
                            } else {
                                showResults({});
                            }
                        }).fail(() => showResults({}));
                    } else {
                        // No AJAX bootstrap available — surface a clear
                        // empty state instead of pretending to search.
                        setTimeout(() => showResults({}), 300);
                    }
                }, 250);
            });

            input.addEventListener('focus', () => {
                if (input.value.trim().length >= minChars && !dropdown) {
                    input.dispatchEvent(new Event('input'));
                }
            });

            // Close on outside click
            document.addEventListener('click', (e) => {
                if (dropdown && !input.parentNode.contains(e.target)) {
                    dropdown.remove();
                    dropdown = null;
                }
            });

            // Keyboard navigation
            let selectedIndex = -1;
            input.addEventListener('keydown', (e) => {
                if (!dropdown) return;

                const items = dropdown.querySelectorAll('.ltms-sac-item');

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                    updateSelection(items);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    selectedIndex = Math.max(selectedIndex - 1, -1);
                    updateSelection(items);
                } else if (e.key === 'Enter' && selectedIndex >= 0) {
                    e.preventDefault();
                    items[selectedIndex]?.click();
                } else if (e.key === 'Escape') {
                    dropdown.remove();
                    dropdown = null;
                }
            });

            function updateSelection(items) {
                items.forEach((item, i) => {
                    item.classList.toggle('active', i === selectedIndex);
                });
                items[selectedIndex]?.scrollIntoView({ block: 'nearest' });
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 91. MULTI-CURRENCY — Switcher de moneda
    // ═══════════════════════════════════════════════════════════

    /**
     * Permite cambiar entre COP, MXN y USD con conversión
     * automática de precios en la página.
     */

    const CURRENCY_RATES = {
        COP: { symbol: '$', decimals: 0, locale: 'es-CO' },
        MXN: { symbol: '$', decimals: 2, locale: 'es-MX' },
        USD: { symbol: 'US$', decimals: 2, locale: 'en-US' },
    };

    // Tasa base: COP
    const EXCHANGE_RATES = {
        COP: 1,
        MXN: 0.0045,
        USD: 0.00025,
    };

    function getCurrentCurrency() {
        try { return localStorage.getItem('ltms-currency') || 'COP'; }
        catch (e) { return 'COP'; }
    }

    function setCurrency(currency) {
        if (!CURRENCY_RATES[currency]) return;
        try { localStorage.setItem('ltms-currency', currency); } catch (e) {}
        convertAllPrices(currency);
        updateCurrencySwitchers(currency);
        announce(`Moneda cambiada a ${currency}`);
        toast('success', 'Moneda actualizada', `Precios en ${currency}`);
    }

    function convertPrice(priceCop, targetCurrency) {
        const rate = EXCHANGE_RATES[targetCurrency] || 1;
        const converted = priceCop * rate;
        const config = CURRENCY_RATES[targetCurrency] || CURRENCY_RATES.COP;

        return config.symbol + new Intl.NumberFormat(config.locale, {
            minimumFractionDigits: config.decimals,
            maximumFractionDigits: config.decimals,
        }).format(converted);
    }

    function convertAllPrices(currency) {
        document.querySelectorAll('[data-price-cop]').forEach((el) => {
            const cop = parseFloat(el.dataset.priceCop);
            if (!isNaN(cop)) {
                el.textContent = convertPrice(cop, currency);
            }
        });
    }

    function updateCurrencySwitchers(currency) {
        document.querySelectorAll('[data-currency-switcher]').forEach((switcher) => {
            switcher.querySelectorAll('[data-currency-option]').forEach((opt) => {
                opt.classList.toggle('active', opt.dataset.currencyOption === currency);
            });
        });
    }

    function initMultiCurrency() {
        // Render switchers
        document.querySelectorAll('[data-currency-switcher]').forEach((switcher) => {
            if (switcher.dataset.csInit) return;
            switcher.dataset.csInit = 'true';

            const currencies = (switcher.dataset.currencyOptions || 'COP,MXN,USD').split(',');
            switcher.innerHTML = currencies.map((cur) => {
                const config = CURRENCY_RATES[cur];
                if (!config) return '';
                return `<button type="button" class="ltms-currency-option" data-currency-option="${cur}">${cur}</button>`;
            }).join('');

            switcher.querySelectorAll('[data-currency-option]').forEach((opt) => {
                opt.addEventListener('click', () => setCurrency(opt.dataset.currencyOption));
            });
        });

        // Initial conversion
        const current = getCurrentCurrency();
        if (current !== 'COP') {
            convertAllPrices(current);
        }
        updateCurrencySwitchers(current);
    }

    LTMS.UX.setCurrency = setCurrency;
    LTMS.UX.getCurrentCurrency = getCurrentCurrency;
    LTMS.UX.convertPrice = convertPrice;

    // ═══════════════════════════════════════════════════════════
    // 92. LOYALTY POINTS — Display de puntos de fidelidad
    // ═══════════════════════════════════════════════════════════

    /**
     * Widget que muestra puntos de fidelidad acumulados,
     * progreso hacia el siguiente nivel y beneficios.
     */

    function initLoyaltyPoints() {
        document.querySelectorAll('[data-loyalty-widget]').forEach((container) => {
            if (container.dataset.lpInit) return;
            container.dataset.lpInit = 'true';

            const points = parseInt(container.dataset.loyaltyWidget || '0', 10);
            const level = container.dataset.loyaltyLevel || 'Bronce';
            const nextLevel = container.dataset.loyaltyNextLevel || 'Plata';
            const nextThreshold = parseInt(container.dataset.loyaltyNextThreshold || '1000', 10);
            const currentThreshold = parseInt(container.dataset.loyaltyCurrentThreshold || '0', 10);

            const range = nextThreshold - currentThreshold;
            const progress = Math.min(((points - currentThreshold) / range) * 100, 100);
            const remaining = Math.max(nextThreshold - points, 0);

            const levelColors = {
                'Bronce': '#CD7F32',
                'Plata': '#C0C0C0',
                'Oro': '#FFD700',
                'Platino': '#E5E4E2',
                'Diamante': '#B9F2FF',
            };

            const color = levelColors[level] || '#CD7F32';

            container.className = 'ltms-loyalty-widget';
            container.innerHTML = `
                <div class="ltms-loyalty-header">
                    <div class="ltms-loyalty-level-badge" style="background:${color};">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    </div>
                    <div class="ltms-loyalty-info">
                        <div class="ltms-loyalty-level" style="color:${color};">${escapeHtml(level)}</div>
                        <div class="ltms-loyalty-points">${points.toLocaleString('es-CO')} puntos</div>
                    </div>
                </div>
                <div class="ltms-loyalty-progress">
                    <div class="ltms-loyalty-progress-bar" style="width:${progress}%;background:${color};"></div>
                </div>
                <div class="ltms-loyalty-next">
                    ${remaining > 0
                        ? `Te faltan <strong>${remaining.toLocaleString('es-CO')}</strong> puntos para <strong style="color:${levelColors[nextLevel] || '#C0C0C0'};">${escapeHtml(nextLevel)}</strong>`
                        : '¡Has alcanzado el nivel máximo!'}
                </div>
            `;
        });
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
    // 94. SOCIAL PROOF — Notificaciones de compras recientes
    // ═══════════════════════════════════════════════════════════

    /**
     * Muestra notificaciones flotantes de compras recientes:
     * "Juan desde Bogotá compró Camiseta Azul hace 5 min"
     * Genera urgencia y confianza social.
     *
     * Task 67-A / UX-SOCIAL-1 FIX: This module previously fabricated
     * notifications with `Math.random()` picking from hardcoded arrays of
     * names, cities and products. That violates Colombia's Estatuto del
     * Consumidor (Ley 1480/2011 art. 31 — información engañosa) and Mexico's
     * LFPU. The module is now DISABLED until a real `ltms_get_recent_purchases`
     * AJAX endpoint is implemented server-side that returns anonymized recent
     * completed orders (first-name only + city + real product image).
     *
     * TODO: implement ltms_get_recent_purchases endpoint in
     * includes/frontend/class-ltms-frontend-checkout-handler.php (or a new
     * class-ltms-frontend-social-proof.php) querying completed WC orders in
     * the last hour with first-name + city anonymization. Gate behind explicit
     * admin opt-in (ltms_social_proof_enabled option). When ready, uncomment
     * the fetch() block below and remove the early return.
     */

    const SOCIAL_PROOF_NAMES = ['Juan', 'María', 'Carlos', 'Ana', 'Pedro', 'Laura', 'Diego', 'Sofía', 'Andrés', 'Valeria', 'Camilo', 'Daniela', 'Felipe', 'Isabella', 'Sebastián', 'Camila'];
    const SOCIAL_PROOF_CITIES = ['Bogotá', 'Medellín', 'Cali', 'Barranquilla', 'Cartagena', 'Cúcuta', 'Bucaramanga', 'Pereira', 'Santa Marta', 'Ibagué', 'Manizales', 'Villavicencio'];
    const SOCIAL_PROOF_PRODUCTS = [
        { name: 'Camiseta Premium', img: '👕' },
        { name: 'Zapatillas Deportivas', img: '👟' },
        { name: 'Reloj Inteligente', img: '⌚' },
        { name: 'Auriculares Bluetooth', img: '🎧' },
        { name: 'Mochila Urbana', img: '🎒' },
        { name: 'Gafas de Sol', img: '🕶️' },
        { name: 'Perfume Importado', img: '🧴' },
        { name: 'Set de Cocina', img: '🍳' },
    ];

    let socialProofActive = false;
    let socialProofTimer = null;

    function initSocialProof() {
        // UX-SOCIAL-1: Module disabled — fabricated data violates consumer
        // protection law. Re-enable only when ltms_get_recent_purchases
        // endpoint exists and returns real anonymized purchases.
        //
        // To re-enable once the endpoint is implemented:
        //   1. Replace the early-return below with a fetch() call to
        //     ltmsUX.ajax_url + '?action=ltms_get_recent_purchases&nonce=' + ltmsUX.nonce
        //   2. Cache the response and call showSocialProofNotification(purchase)
        //     with a real purchase object on each cycle.
        //   3. Hide the module silently if the fetch fails or returns no data.
        return;

        /* eslint-disable no-unreachable */
        // The code below is preserved for reference once the endpoint exists.
        // It will not run while the early return above is in place.

        // No mostrar en login, registro o dashboard admin
        if (document.querySelector('.ltms-auth-container, .ltms-dashboard-container')) return;
        if (document.body.classList.contains('wp-admin')) return;

        // No mostrar si el usuario ya las cerró
        try {
            if (sessionStorage.getItem('ltms-social-proof-dismissed') === 'true') return;
        } catch (e) {}

        // Esperar 15s antes de la primera notificación
        setTimeout(() => {
            socialProofActive = true;
            showSocialProofNotification();
        }, 15000);
        /* eslint-enable no-unreachable */
    }

    function showSocialProofNotification() {
        if (!socialProofActive) return;

        // UX-SOCIAL-1: Do NOT fabricate names/cities/products with Math.random().
        // The arrays above are kept only for the future implementation that will
        // fetch real purchases from the server. Returning early ensures no
        // fabricated notification is shown to the user.
        return;

        /* eslint-disable no-unreachable */
        const name = SOCIAL_PROOF_NAMES[Math.floor(Math.random() * SOCIAL_PROOF_NAMES.length)];
        const city = SOCIAL_PROOF_CITIES[Math.floor(Math.random() * SOCIAL_PROOF_CITIES.length)];
        const product = SOCIAL_PROOF_PRODUCTS[Math.floor(Math.random() * SOCIAL_PROOF_PRODUCTS.length)];
        const minutesAgo = Math.floor(Math.random() * 30) + 1;

        const notif = document.createElement('div');
        notif.className = 'ltms-social-proof';
        notif.innerHTML = `
            <div class="ltms-social-proof-img">${product.img}</div>
            <div class="ltms-social-proof-content">
                <div class="ltms-social-proof-text">
                    <strong>${escapeHtml(name)}</strong> desde ${escapeHtml(city)} compró
                    <strong>${escapeHtml(product.name)}</strong>
                </div>
                <div class="ltms-social-proof-time">Hace ${minutesAgo} min · ✓ Verificado</div>
            </div>
            <button type="button" class="ltms-social-proof-close" aria-label="Cerrar">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        `;

        document.body.appendChild(notif);
        requestAnimationFrame(() => notif.classList.add('visible'));

        // Auto-hide after 5s
        const hideTimer = setTimeout(() => hideSocialProof(notif), 5000);

        // Close button
        notif.querySelector('.ltms-social-proof-close').addEventListener('click', () => {
            clearTimeout(hideTimer);
            hideSocialProof(notif);
            try { sessionStorage.setItem('ltms-social-proof-dismissed', 'true'); } catch (e) {}
            socialProofActive = false;
        });

        // Hover pauses auto-hide
        notif.addEventListener('mouseenter', () => clearTimeout(hideTimer));
        notif.addEventListener('mouseleave', () => {
            setTimeout(() => hideSocialProof(notif), 3000);
        });

        // Schedule next notification (30-90s random)
        socialProofTimer = setTimeout(() => {
            if (socialProofActive) showSocialProofNotification();
        }, 30000 + Math.random() * 60000);
        /* eslint-enable no-unreachable */
    }

    function hideSocialProof(notif) {
        if (!notif || !notif.parentNode) return;
        notif.classList.remove('visible');
        setTimeout(() => {
            if (notif.parentNode) notif.parentNode.removeChild(notif);
        }, 400);
    }

    // ═══════════════════════════════════════════════════════════
    // 95. SIZE GUIDE — Modal de guía de tallas
    // ═══════════════════════════════════════════════════════════

    /**
     * Modal con tabla de guía de tallas y conversión
     * internacional (Colombia, México, USA, EU).
     */

    function initSizeGuide() {
        document.addEventListener('click', (e) => {
            const trigger = e.target.closest('[data-size-guide]');
            if (!trigger) return;
            e.preventDefault();

            const guideData = trigger.dataset.sizeGuide;
            const guideType = trigger.dataset.sizeGuideType || 'clothing';

            openSizeGuideModal(guideType, guideData);
        });
    }

    function openSizeGuideModal(type, customData) {
        const tables = {
            clothing: {
                title: 'Guía de tallas — Ropa',
                headers: ['Talla', 'Busto (cm)', 'Cintura (cm)', 'Cadera (cm)'],
                rows: [
                    ['XS', '78-82', '60-64', '86-90'],
                    ['S', '82-86', '64-68', '90-94'],
                    ['M', '86-90', '68-72', '94-98'],
                    ['L', '90-94', '72-76', '98-102'],
                    ['XL', '94-98', '76-80', '102-106'],
                    ['XXL', '98-102', '80-84', '106-110'],
                ],
            },
            shoes: {
                title: 'Guía de tallas — Calzado',
                headers: ['CO', 'MX', 'USA', 'EU', 'Largo pie (cm)'],
                rows: [
                    ['34', '3.5', '4', '35', '22.5'],
                    ['35', '4', '5', '36', '23'],
                    ['36', '5', '6', '37', '23.5'],
                    ['37', '6', '7', '38', '24'],
                    ['38', '7', '8', '39', '25'],
                    ['39', '8', '9', '40', '25.5'],
                    ['40', '9', '10', '41', '26'],
                    ['41', '10', '11', '42', '27'],
                ],
            },
            rings: {
                title: 'Guía de tallas — Anillos',
                headers: ['Talla', 'Diámetro (mm)', 'Perímetro (mm)'],
                rows: [
                    ['6', '16.5', '52'],
                    ['7', '17.3', '54.4'],
                    ['8', '18.2', '57'],
                    ['9', '19.0', '59.5'],
                    ['10', '19.8', '62.1'],
                    ['11', '20.6', '64.6'],
                    ['12', '21.4', '67.2'],
                ],
            },
        };

        const guide = tables[type] || tables.clothing;

        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay';
        overlay.innerHTML = `
            <div class="ltms-modal ltms-size-guide-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-sg-title">
                <div class="ltms-modal-header">
                    <h3 class="ltms-modal-title" id="ltms-sg-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M7 16l4-4 4 4 5-5"/></svg>
                        ${escapeHtml(guide.title)}
                    </h3>
                    <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ltms-modal-body ltms-size-guide-body">
                    <div class="ltms-size-guide-tip">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                        <span>Mide tu cuerpo con una cinta métrica y compara con la tabla. Si estás entre dos tallas, elige la más grande.</span>
                    </div>
                    <table class="ltms-size-guide-table">
                        <thead>
                            <tr>${guide.headers.map((h) => `<th>${escapeHtml(h)}</th>`).join('')}</tr>
                        </thead>
                        <tbody>
                            ${guide.rows.map((row) => `<tr>${row.map((cell) => `<td>${escapeHtml(cell)}</td>`).join('')}</tr>`).join('')}
                        </tbody>
                    </table>
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

    LTMS.UX.openSizeGuide = openSizeGuideModal;

    // ═══════════════════════════════════════════════════════════
    // 96. PRODUCT VIDEO — Soporte de video en galería
    // ═══════════════════════════════════════════════════════════

    /**
     * Añade soporte para videos de producto (YouTube/Vimeo/MP4)
     * dentro del carrusel de imágenes existente.
     */

    function initProductVideo() {
        document.querySelectorAll('[data-product-video]').forEach((videoTrigger) => {
            if (videoTrigger.dataset.pvInit) return;
            videoTrigger.dataset.pvInit = 'true';

            videoTrigger.addEventListener('click', (e) => {
                e.preventDefault();
                const videoUrl = videoTrigger.dataset.productVideo;
                const videoType = videoTrigger.dataset.videoType || 'youtube';

                openVideoModal(videoUrl, videoType);
            });
        });
    }

    function openVideoModal(url, type) {
        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay ltms-video-modal-overlay';

        let embedHtml = '';
        if (type === 'youtube') {
            const videoId = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/)([^&\n?#]+)/)?.[1] || url;
            embedHtml = `<iframe src="https://www.youtube.com/embed/${escapeHtml(videoId)}?autoplay=1&rel=0" frameborder="0" allow="autoplay; encrypted-media" allowfullscreen></iframe>`;
        } else if (type === 'vimeo') {
            const videoId = url.match(/vimeo\.com\/(\d+)/)?.[1] || url;
            embedHtml = `<iframe src="https://player.vimeo.com/video/${escapeHtml(videoId)}?autoplay=1" frameborder="0" allow="autoplay; fullscreen" allowfullscreen></iframe>`;
        } else {
            embedHtml = `<video src="${escapeHtml(url)}" controls autoplay playsinline></video>`;
        }

        overlay.innerHTML = `
            <div class="ltms-video-modal" role="dialog" aria-modal="true" aria-label="Video del producto">
                <button type="button" class="ltms-video-modal-close" aria-label="Cerrar video">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
                <div class="ltms-video-modal-content">
                    ${embedHtml}
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';
        requestAnimationFrame(() => overlay.classList.add('visible'));

        const close = () => {
            overlay.classList.remove('visible');
            document.body.style.overflow = '';
            setTimeout(() => {
                if (overlay.parentNode) overlay.remove();
            }, 300);
        };

        overlay.querySelector('.ltms-video-modal-close').addEventListener('click', close);
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

        document.addEventListener('keydown', function escHandler(e) {
            if (e.key === 'Escape') {
                close();
                document.removeEventListener('keydown', escHandler);
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 97. RECENT SEARCHES — Términos de búsqueda recientes
    // ═══════════════════════════════════════════════════════════

    /**
     * Guarda y muestra búsquedas recientes del usuario
     * en los campos de búsqueda.
     */

    function getRecentSearches() {
        try {
            return JSON.parse(localStorage.getItem('ltms-recent-searches') || '[]');
        } catch (e) { return []; }
    }

    function addRecentSearch(term) {
        if (!term || term.trim().length < 2) return;
        let searches = getRecentSearches();

        // Remover duplicados
        searches = searches.filter((s) => s.toLowerCase() !== term.toLowerCase());

        // Añadir al inicio
        searches.unshift(term.trim());

        // Mantener solo 8
        searches = searches.slice(0, 8);

        try { localStorage.setItem('ltms-recent-searches', JSON.stringify(searches)); } catch (e) {}
    }

    function clearRecentSearches() {
        try { localStorage.removeItem('ltms-recent-searches'); } catch (e) {}
    }

    function initRecentSearches() {
        const searchInputs = document.querySelectorAll('[data-search-autocomplete], [data-recent-searches], input[type="search"]');

        searchInputs.forEach((input) => {
            if (input.dataset.rsInit) return;
            input.dataset.rsInit = 'true';

            let dropdown = null;

            input.addEventListener('focus', () => {
                const recent = getRecentSearches();
                if (!recent.length) return;
                if (input.value.trim().length > 0) return; // No mostrar si ya está escribiendo

                if (dropdown) dropdown.remove();
                dropdown = document.createElement('div');
                dropdown.className = 'ltms-recent-searches-dropdown';
                dropdown.innerHTML = `
                    <div class="ltms-recent-searches-header">
                        <span>Búsquedas recientes</span>
                        <button type="button" class="ltms-recent-searches-clear">Limpiar</button>
                    </div>
                    ${recent.map((term) => `
                        <div class="ltms-recent-search-item" data-term="${escapeHtml(term)}">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            <span>${escapeHtml(term)}</span>
                        </div>
                    `).join('')}
                `;

                input.parentNode.style.position = 'relative';
                input.parentNode.appendChild(dropdown);

                dropdown.querySelectorAll('.ltms-recent-search-item').forEach((item) => {
                    item.addEventListener('click', () => {
                        input.value = item.dataset.term;
                        dropdown.remove();
                        dropdown = null;
                        input.form?.submit();
                    });
                });

                dropdown.querySelector('.ltms-recent-searches-clear')?.addEventListener('click', () => {
                    clearRecentSearches();
                    dropdown.remove();
                    dropdown = null;
                });
            });

            // Save search on submit
            input.form?.addEventListener('submit', () => {
                addRecentSearch(input.value);
            });

            // Save on Enter
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    addRecentSearch(input.value);
                }
            });

            // Close on blur
            input.addEventListener('blur', () => {
                setTimeout(() => {
                    if (dropdown) {
                        dropdown.remove();
                        dropdown = null;
                    }
                }, 200);
            });
        });
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
    // 100. RETURN/REFUND WIZARD — Asistente de devoluciones
    // ═══════════════════════════════════════════════════════════

    /**
     * Wizard multi-paso para gestionar devoluciones:
     * 1. Seleccionar items a devolver
     * 2. Motivo de devolución
     * 3. Método de reembolso
     * 4. Confirmación
     */

    function openReturnWizard(orderId, items = []) {
        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay ltms-return-wizard-overlay';
        overlay.innerHTML = `
            <div class="ltms-modal ltms-return-wizard-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-rw-title">
                <div class="ltms-modal-header">
                    <h3 class="ltms-modal-title" id="ltms-rw-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                        Solicitar devolución
                    </h3>
                    <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ltms-modal-body ltms-return-wizard-body">
                    <div class="ltms-return-steps">
                        <div class="ltms-return-step active" data-rstep="1"><span>1</span> Items</div>
                        <div class="ltms-return-step" data-rstep="2"><span>2</span> Motivo</div>
                        <div class="ltms-return-step" data-rstep="3"><span>3</span> Reembolso</div>
                        <div class="ltms-return-step" data-rstep="4"><span>4</span> Confirmar</div>
                    </div>

                    <div class="ltms-return-page" data-rpage="1">
                        <h4>Selecciona los items a devolver</h4>
                        <div class="ltms-return-items">
                            ${items.map((item) => `
                                <label class="ltms-return-item">
                                    <input type="checkbox" class="ltms-return-item-check" data-item-id="${item.id}" data-item-name="${escapeHtml(item.name)}" data-item-price="${item.price || ''}">
                                    <div class="ltms-return-item-info">
                                        <div class="ltms-return-item-name">${escapeHtml(item.name)}</div>
                                        <div class="ltms-return-item-price">${escapeHtml(item.price || '')}</div>
                                    </div>
                                </label>
                            `).join('')}
                        </div>
                    </div>

                    <div class="ltms-return-page" data-rpage="2" style="display:none;">
                        <h4>¿Cuál es el motivo de la devolución?</h4>
                        <div class="ltms-return-reasons">
                            <label class="ltms-return-reason"><input type="radio" name="return_reason" value="defective"> <span>🔧 Producto defectuoso</span></label>
                            <label class="ltms-return-reason"><input type="radio" name="return_reason" value="wrong_item"> <span>📦 Producto incorrecto</span></label>
                            <label class="ltms-return-reason"><input type="radio" name="return_reason" value="not_as_described"> <span>📝 No coincide con la descripción</span></label>
                            <label class="ltms-return-reason"><input type="radio" name="return_reason" value="changed_mind"> <span>🔄 Cambié de opinión</span></label>
                            <label class="ltms-return-reason"><input type="radio" name="return_reason" value="arrived_late"> <span>⏰ Llegó tarde</span></label>
                            <label class="ltms-return-reason"><input type="radio" name="return_reason" value="other"> <span>❓ Otro motivo</span></label>
                        </div>
                        <textarea class="ltms-return-details" placeholder="Cuéntanos más sobre el problema (opcional)..." rows="3"></textarea>
                    </div>

                    <div class="ltms-return-page" data-rpage="3" style="display:none;">
                        <h4>¿Cómo quieres el reembolso?</h4>
                        <div class="ltms-return-methods">
                            <label class="ltms-return-method">
                                <input type="radio" name="refund_method" value="original" checked>
                                <div class="ltms-return-method-info">
                                    <strong>💳 Reembolso al método original</strong>
                                    <span>Se devolverá a la tarjeta/cuenta usada en la compra (3-5 días hábiles)</span>
                                </div>
                            </label>
                            <label class="ltms-return-method">
                                <input type="radio" name="refund_method" value="wallet">
                                <div class="ltms-return-method-info">
                                    <strong>💰 Reembolso a billetera</strong>
                                    <span>Crédito instantáneo en tu billetera de la plataforma</span>
                                </div>
                            </label>
                            <label class="ltms-return-method">
                                <input type="radio" name="refund_method" value="exchange">
                                <div class="ltms-return-method-info">
                                    <strong>🔄 Cambio por otro producto</strong>
                                    <span>Recibe un producto de igual o menor valor</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="ltms-return-page" data-rpage="4" style="display:none;">
                        <h4>Confirma tu solicitud</h4>
                        <div class="ltms-return-summary">
                            <div class="ltms-return-summary-row"><span>Items:</span> <strong id="ltms-rw-items">—</strong></div>
                            <div class="ltms-return-summary-row"><span>Motivo:</span> <strong id="ltms-rw-reason">—</strong></div>
                            <div class="ltms-return-summary-row"><span>Reembolso:</span> <strong id="ltms-rw-method">—</strong></div>
                        </div>
                        <div class="ltms-return-warning">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            <span>Los items deben estar en su estado original con etiquetas. Tienes 15 días desde la recepción.</span>
                        </div>
                    </div>
                </div>
                <div class="ltms-modal-footer">
                    <button type="button" class="ltms-btn ltms-btn-outline" id="ltms-rw-back" style="display:none;">Atrás</button>
                    <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-rw-next">Siguiente</button>
                    <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-rw-submit" style="display:none;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        Enviar solicitud
                    </button>
                </div>
            </div>
        `;

        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('ltms-modal-open'));

        const cleanup = trapFocus(overlay.querySelector('.ltms-modal'));
        let currentPage = 1;

        const close = () => {
            cleanup();
            overlay.classList.remove('ltms-modal-open');
            setTimeout(() => overlay.remove(), 250);
        };

        overlay.querySelectorAll('.ltms-modal-close').forEach((btn) => btn.addEventListener('click', close));
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

        const nextBtn = overlay.querySelector('#ltms-rw-next');
        const backBtn = overlay.querySelector('#ltms-rw-back');
        const submitBtn = overlay.querySelector('#ltms-rw-submit');
        const steps = overlay.querySelectorAll('.ltms-return-step');
        const pages = overlay.querySelectorAll('.ltms-return-page');

        function showPage(page) {
            pages.forEach((p) => p.style.display = p.dataset.rpage == page ? 'block' : 'none');
            steps.forEach((s, i) => {
                s.classList.toggle('active', i + 1 === page);
                s.classList.toggle('completed', i + 1 < page);
            });
            backBtn.style.display = page > 1 ? '' : 'none';
            nextBtn.style.display = page < 4 ? '' : 'none';
            submitBtn.style.display = page === 4 ? '' : 'none';

            if (page === 4) updateSummary();
            currentPage = page;
        }

        function updateSummary() {
            const selected = [...overlay.querySelectorAll('.ltms-return-item-check:checked')];
            const itemsText = selected.map((c) => c.dataset.itemName).join(', ');
            const reason = overlay.querySelector('input[name="return_reason"]:checked');
            const method = overlay.querySelector('input[name="refund_method"]:checked');

            overlay.querySelector('#ltms-rw-items').textContent = itemsText || '—';
            overlay.querySelector('#ltms-rw-reason').textContent = reason?.parentElement.textContent.trim() || '—';
            overlay.querySelector('#ltms-rw-method').textContent = method?.parentElement.querySelector('strong')?.textContent || '—';
        }

        nextBtn.addEventListener('click', () => {
            if (currentPage === 1) {
                const selected = overlay.querySelectorAll('.ltms-return-item-check:checked');
                if (!selected.length) {
                    toast('warning', 'Selecciona al menos un item', 'Debes elegir qué productos devolver.');
                    return;
                }
            }
            if (currentPage === 2) {
                if (!overlay.querySelector('input[name="return_reason"]:checked')) {
                    toast('warning', 'Selecciona un motivo', 'Debes indicar por qué devuelves el producto.');
                    return;
                }
            }
            showPage(currentPage + 1);
        });

        backBtn.addEventListener('click', () => showPage(currentPage - 1));

        submitBtn.addEventListener('click', () => {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="ltms-spinner"></span> Enviando...';

            const selected = [...overlay.querySelectorAll('.ltms-return-item-check:checked')].map((c) => c.dataset.itemId);
            const reason = overlay.querySelector('input[name="return_reason"]:checked')?.value;
            const method = overlay.querySelector('input[name="refund_method"]:checked')?.value;
            const details = overlay.querySelector('.ltms-return-details').value;

            if (typeof jQuery !== 'undefined' && typeof ltmsDashboard !== 'undefined') {
                jQuery.post(ltmsDashboard.ajax_url, {
                    action: 'ltms_submit_return',
                    nonce: ltmsDashboard.nonce,
                    order_id: orderId,
                    items: selected,
                    reason: reason,
                    refund_method: method,
                    details: details,
                }, (response) => {
                    if (response.success) {
                        close();
                        showOrderSuccess({
                            order_number: response.data.return_id || 'DEV-' + Date.now(),
                            message: 'Tu solicitud de devolución ha sido enviada. Te contactaremos en 24h.',
                        });
                    } else {
                        toast('error', 'Error', response.data || 'No se pudo enviar la solicitud.');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Enviar solicitud';
                    }
                });
            } else {
                setTimeout(() => {
                    close();
                    toast('success', 'Solicitud enviada', 'Te contactaremos en 24 horas.');
                }, 1000);
            }
        });

        showPage(1);
    }

    function initReturnWizard() {
        document.addEventListener('click', (e) => {
            const trigger = e.target.closest('[data-return-wizard]');
            if (!trigger) return;
            e.preventDefault();

            const orderId = trigger.dataset.returnWizard;
            const items = JSON.parse(trigger.dataset.returnItems || '[]');
            openReturnWizard(orderId, items);
        });
    }

    LTMS.UX.openReturnWizard = openReturnWizard;

    // ═══════════════════════════════════════════════════════════
    // 101. INVOICE CENTER — Centro de descarga de facturas
    // ═══════════════════════════════════════════════════════════

    /**
     * Widget que lista todas las facturas/recibos del usuario
     * con opción de descarga PDF y vista previa.
     */

    function initInvoiceCenter() {
        document.querySelectorAll('[data-invoice-center]').forEach((container) => {
            if (container.dataset.icInit) return;
            container.dataset.icInit = 'true';

            const ajaxUrl = container.dataset.invoiceCenter;

            if (ajaxUrl && typeof jQuery !== 'undefined' && typeof ltmsDashboard !== 'undefined') {
                container.innerHTML = '<div class="ltms-invoice-loading"><div class="ltms-spinner-lg"></div></div>';

                jQuery.post(ltmsDashboard.ajax_url, {
                    action: 'ltms_get_invoices',
                    nonce: ltmsDashboard.nonce,
                }, (response) => {
                    if (response.success && response.data && response.data.invoices) {
                        renderInvoices(container, response.data.invoices);
                    } else {
                        container.innerHTML = '<div class="ltms-invoice-empty">' + renderEmptyState('orders') + '</div>';
                    }
                }).fail(() => {
                    container.innerHTML = '<div class="ltms-invoice-empty">' + renderEmptyState('error') + '</div>';
                });
            }
        });
    }

    function renderInvoices(container, invoices) {
        if (!invoices.length) {
            container.innerHTML = '<div class="ltms-invoice-empty">' + renderEmptyState('orders') + '</div>';
            return;
        }

        container.className = 'ltms-invoice-center';
        container.innerHTML = `
            <div class="ltms-invoice-list">
                ${invoices.map((inv) => `
                    <div class="ltms-invoice-item">
                        <div class="ltms-invoice-icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        </div>
                        <div class="ltms-invoice-info">
                            <div class="ltms-invoice-number">Factura #${escapeHtml(inv.number)}</div>
                            <div class="ltms-invoice-date">${escapeHtml(inv.date)}</div>
                        </div>
                        <div class="ltms-invoice-amount">${escapeHtml(inv.amount)}</div>
                        <div class="ltms-invoice-actions">
                            <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-invoice-preview" data-invoice-url="${escapeHtml(inv.url)}">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                Ver
                            </button>
                            <a href="${escapeHtml(inv.url)}" download class="ltms-btn ltms-btn-primary ltms-btn-sm">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                PDF
                            </a>
                        </div>
                    </div>
                `).join('')}
            </div>
        `;

        // Preview buttons
        container.querySelectorAll('.ltms-invoice-preview').forEach((btn) => {
            btn.addEventListener('click', () => {
                openPrintPreview({
                    title: 'Factura',
                    content: `<iframe src="${btn.dataset.invoiceUrl}" style="width:100%;height:500px;border:none;"></iframe>`,
                });
            });
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 102. MULTI-VENDOR CART SPLIT — Carrito agrupado por vendedor
    // ═══════════════════════════════════════════════════════════

    /**
     * En un marketplace multi-vendor, muestra los items del carrito
     * agrupados por vendedor con subtotales independientes.
     */

    function initMultiVendorCart() {
        const cartContainer = document.querySelector('[data-multivendor-cart]');
        if (!cartContainer) return;
        if (cartContainer.dataset.mvcInit) return;
        cartContainer.dataset.mvcInit = 'true';

        // Group items by vendor
        const items = [...cartContainer.querySelectorAll('[data-cart-item-vendor]')];
        const vendorGroups = {};

        items.forEach((item) => {
            const vendor = item.dataset.cartItemVendor;
            const vendorName = item.dataset.cartVendorName || vendor;
            if (!vendorGroups[vendor]) {
                vendorGroups[vendor] = { name: vendorName, items: [], subtotal: 0 };
            }
            vendorGroups[vendor].items.push(item);
            const price = parseFloat(item.dataset.cartItemPrice || '0');
            const qty = parseInt(item.dataset.cartItemQty || '1', 10);
            vendorGroups[vendor].subtotal += price * qty;
        });

        // Restructure cart display
        Object.entries(vendorGroups).forEach(([vendorId, group]) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'ltms-vendor-cart-group';
            wrapper.innerHTML = `
                <div class="ltms-vendor-cart-header">
                    <div class="ltms-vendor-cart-info">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                        <span>Vendido por <strong>${escapeHtml(group.name)}</strong></span>
                    </div>
                    <span class="ltms-vendor-cart-subtotal">${formatCurrency(group.subtotal)}</span>
                </div>
                <div class="ltms-vendor-cart-items"></div>
            `;

            // Move items into group
            const itemsContainer = wrapper.querySelector('.ltms-vendor-cart-items');
            group.items.forEach((item) => {
                itemsContainer.appendChild(item.cloneNode(true));
                item.style.display = 'none';
            });

            cartContainer.appendChild(wrapper);
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 103. ESTIMATED DELIVERY — Calculadora de fecha de entrega
    // ═══════════════════════════════════════════════════════════

    /**
     * Calcula y muestra la fecha estimada de entrega basada en
     * la ubicación del usuario y el método de envío.
     */

    function initEstimatedDelivery() {
        document.querySelectorAll('[data-estimated-delivery]').forEach((container) => {
            if (container.dataset.edInit) return;
            container.dataset.edInit = 'true';

            const baseDays = parseInt(container.dataset.baseDays || '3', 10);
            const city = container.dataset.city || '';
            const shippingMethod = container.dataset.shippingMethod || 'standard';

            // City-based additional days
            const cityDelays = { 'Bogotá': 0, 'Medellín': 1, 'Cali': 2, 'Barranquilla': 3, 'Cartagena': 3 };
            const extraDays = cityDelays[city] || 4;

            // Method adjustments
            const methodMultipliers = { standard: 1, express: 0.4, same_day: 0.1, pickup: 0 };
            const multiplier = methodMultipliers[shippingMethod] || 1;

            const totalDays = Math.ceil(baseDays * multiplier) + extraDays;

            const deliveryDate = new Date();
            deliveryDate.setDate(deliveryDate.getDate() + totalDays);

            // Skip weekends
            while (deliveryDate.getDay() === 0 || deliveryDate.getDay() === 6) {
                deliveryDate.setDate(deliveryDate.getDate() + 1);
            }

            const dateStr = deliveryDate.toLocaleDateString('es-CO', {
                weekday: 'long',
                day: 'numeric',
                month: 'long',
            });

            const isExpress = shippingMethod === 'express' || shippingMethod === 'same_day';

            container.className = 'ltms-estimated-delivery' + (isExpress ? ' ltms-delivery-express' : '');
            container.innerHTML = `
                <div class="ltms-delivery-icon">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                </div>
                <div class="ltms-delivery-content">
                    <strong>Entrega estimada:</strong>
                    <span>${escapeHtml(dateStr.charAt(0).toUpperCase() + dateStr.slice(1))}</span>
                </div>
            `;
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 104. SUBSCRIPTION / AUTO-REORDER — Suscripción a productos
    // ═══════════════════════════════════════════════════════════

    /**
     * Permite suscribirse a entregas recurrentes de productos
     * con descuento por suscripción.
     */

    function initProductSubscription() {
        document.querySelectorAll('[data-subscription-option]').forEach((container) => {
            if (container.dataset.subInit) return;
            container.dataset.subInit = 'true';

            const discount = container.dataset.subscriptionDiscount || '10';
            const frequencies = (container.dataset.subscriptionFrequencies || '7,14,30,60').split(',');
            const originalPrice = container.dataset.subscriptionPrice || '';

            container.className = 'ltms-subscription-option';
            container.innerHTML = `
                <div class="ltms-subscription-choices">
                    <label class="ltms-subscription-choice">
                        <input type="radio" name="purchase_type" value="one_time" checked>
                        <div class="ltms-subscription-choice-info">
                            <strong>Compra única</strong>
                            <span>${escapeHtml(originalPrice)}</span>
                        </div>
                    </label>
                    <label class="ltms-subscription-choice ltms-subscription-choice-recurring">
                        <input type="radio" name="purchase_type" value="subscription">
                        <div class="ltms-subscription-choice-info">
                            <strong>🔄 Suscripción recurrente</strong>
                            <span class="ltms-subscription-discount">Ahorra ${escapeHtml(discount)}% en cada pedido</span>
                        </div>
                    </label>
                </div>
                <div class="ltms-subscription-frequency" style="display:none;">
                    <label>Elige la frecuencia:</label>
                    <div class="ltms-subscription-frequencies">
                        ${frequencies.map((f, i) => {
                            const days = parseInt(f, 10);
                            const label = days === 7 ? 'Semanal' : days === 14 ? 'Cada 2 semanas' : days === 30 ? 'Mensual' : days === 60 ? 'Bimensual' : `Cada ${days} días`;
                            return `<label class="ltms-subscription-freq"><input type="radio" name="subscription_freq" value="${f}" ${i === 0 ? 'checked' : ''}> <span>${label}</span></label>`;
                        }).join('')}
                    </div>
                </div>
            `;

            const subCheck = container.querySelector('input[value="subscription"]');
            const freqSection = container.querySelector('.ltms-subscription-frequency');

            subCheck.addEventListener('change', () => {
                freqSection.style.display = subCheck.checked ? 'block' : 'none';

                // Update price display
                const priceEl = document.querySelector(container.dataset.priceTarget || '[data-variant-price]');
                if (priceEl && subCheck.checked && originalPrice) {
                    const numericPrice = parseFloat(originalPrice.replace(/[^0-9.]/g, ''));
                    const discounted = numericPrice * (1 - parseFloat(discount) / 100);
                    priceEl.textContent = formatCurrency(discounted) + ' (-' + discount + '%)';
                }

                // UX-FAKE-4 FIX — Persist the subscription choice server-side.
                // Previously the toggle only fired a success toast and never
                // notified the backend, so when the user proceeded to checkout
                // the subscription option was silently dropped. We now POST to
                // ltms_toggle_subscription (registered in
                // class-ltms-frontend-checkout-handler.php) and only toast
                // success on server confirmation.
                const productId = container.dataset.productId || container.dataset.subscriptionOption || '';
                const isSubscribed = !!subCheck.checked;

                // Optimistically update the hidden input if present (so the
                // checkout form submission also carries the choice).
                const subHidden = container.querySelector('input[name="ltms_subscription"]');
                if (subHidden) subHidden.value = isSubscribed ? 'yes' : 'no';

                const ajaxUrl   = (typeof ltmsUX !== 'undefined' && ltmsUX.ajax_url) || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url);
                const ajaxNonce = (typeof ltmsUX !== 'undefined' && ltmsUX.nonce)     || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce);

                if (typeof jQuery !== 'undefined' && ajaxUrl && ajaxNonce && productId) {
                    jQuery.post(ajaxUrl, {
                        action: 'ltms_toggle_subscription',
                        nonce: ajaxNonce,
                        product_id: productId,
                        subscribe: isSubscribed,
                    }, (response) => {
                        if (response.success) {
                            if (isSubscribed) {
                                toast('success', '¡Suscripción activada!', `Ahorras ${discount}% en cada pedido recurrente.`);
                            } else {
                                toast('info', 'Compra única', 'Suscripción desactivada.');
                            }
                        } else {
                            toast('error', 'Error', response.data?.message || 'No se pudo guardar la preferencia.');
                            // Revert the toggle so the UI reflects the actual state.
                            subCheck.checked = !isSubscribed;
                            freqSection.style.display = subCheck.checked ? 'block' : 'none';
                        }
                    }).fail(() => {
                        toast('error', 'Error de conexión', 'No se pudo guardar la preferencia. Intenta de nuevo.');
                        subCheck.checked = !isSubscribed;
                        freqSection.style.display = subCheck.checked ? 'block' : 'none';
                    });
                } else if (isSubscribed) {
                    // No AJAX available — surface a warning instead of faking success.
                    toast('warning', 'Suscripción no disponible', 'No se pudo activar la suscripción en este momento. Intenta de nuevo más tarde.');
                    subCheck.checked = false;
                    freqSection.style.display = 'none';
                }
            });

            container.querySelector('input[value="one_time"]').addEventListener('change', () => {
                const priceEl = document.querySelector(container.dataset.priceTarget || '[data-variant-price]');
                if (priceEl) priceEl.textContent = originalPrice;
            });
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 105. PICKUP POINT SELECTOR — Selector de puntos de recogida
    // ═══════════════════════════════════════════════════════════

    /**
     * Selector de puntos de recogida con lista de tiendas
     * físicas, horarios y disponibilidad.
     */

    function initPickupSelector() {
        document.querySelectorAll('[data-pickup-selector]').forEach((container) => {
            if (container.dataset.psInit) return;
            container.dataset.psInit = 'true';

            const points = JSON.parse(container.dataset.pickupPoints || '[]');
            if (!points.length) return;

            container.className = 'ltms-pickup-selector';
            container.innerHTML = `
                <div class="ltms-pickup-header">
                    <h4>
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Selecciona punto de recogida
                    </h4>
                    <input type="text" class="ltms-pickup-search" placeholder="Buscar por ciudad o dirección..." aria-label="Buscar punto de recogida">
                </div>
                <div class="ltms-pickup-list">
                    ${points.map((p, i) => `
                        <label class="ltms-pickup-point ${!p.available ? 'unavailable' : ''}" data-pickup-search-text="${escapeHtml((p.name + ' ' + p.address + ' ' + p.city).toLowerCase())}">
                            <input type="radio" name="pickup_point" value="${p.id}" ${i === 0 ? 'checked' : ''} ${!p.available ? 'disabled' : ''}>
                            <div class="ltms-pickup-point-info">
                                <div class="ltms-pickup-point-name">${escapeHtml(p.name)}</div>
                                <div class="ltms-pickup-point-address">${escapeHtml(p.address)}, ${escapeHtml(p.city)}</div>
                                <div class="ltms-pickup-point-hours">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    ${escapeHtml(p.hours || 'Lun-Sab 9:00-18:00')}
                                </div>
                                ${p.available ? '<span class="ltms-pickup-point-badge">Disponible</span>' : '<span class="ltms-pickup-point-badge unavailable">No disponible</span>'}
                            </div>
                            <div class="ltms-pickup-point-distance">${escapeHtml(p.distance || '')}</div>
                        </label>
                    `).join('')}
                </div>
            `;

            // Search filter
            const searchInput = container.querySelector('.ltms-pickup-search');
            searchInput.addEventListener('input', () => {
                const query = searchInput.value.toLowerCase().trim();
                container.querySelectorAll('.ltms-pickup-point').forEach((el) => {
                    const text = el.dataset.pickupSearchText;
                    el.style.display = text.includes(query) ? '' : 'none';
                });
            });

            // Selection
            container.querySelectorAll('input[name="pickup_point"]').forEach((radio) => {
                radio.addEventListener('change', () => {
                    if (radio.checked) {
                        const point = points.find((p) => p.id == radio.value);
                        container.dispatchEvent(new CustomEvent('ltms:pickup-selected', {
                            detail: point,
                            bubbles: true,
                        }));
                    }
                });
            });
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 106. FLASH SALE — Oferta flash / Deal of the day
    // ═══════════════════════════════════════════════════════════

    /**
     * Banner de oferta flash con countdown integrado, descuento
     * destacado y CTA urgente. Ideal para páginas de inicio.
     */

    function initFlashSale() {
        document.querySelectorAll('[data-flash-sale]').forEach((container) => {
            if (container.dataset.fsInit) return;
            container.dataset.fsInit = 'true';

            const endTime = container.dataset.flashSale;
            const discount = container.dataset.flashDiscount || '50%';
            const productName = container.dataset.flashProduct || 'Producto destacado';
            const productImg = container.dataset.flashImage || '';
            const originalPrice = container.dataset.flashOriginalPrice || '';
            const salePrice = container.dataset.flashSalePrice || '';
            const productUrl = container.dataset.flashUrl || '#';

            // Task 67-A / UX-SOCIAL-2 FIX: the progress bar previously used
            // `Math.random()` to fabricate "vendidos · ¡Quedan pocas unidades!"
            // counts and a percentage width. That is fabricated scarcity and
            // violates Colombia's Estatuto del Consumidor (Ley 1480/2011 art.
            // 31 — información engañosa) and Mexico's LFPU. We now render the
            // progress block ONLY when the server provides real values via the
            // `data-flash-sold` (units sold) and `data-flash-stock` (units
            // remaining) attributes — typically populated from the WC product's
            // `total_sales` and `_stock` meta. If either attribute is missing,
            // the entire progress block is omitted (no fabricated numbers).
            const soldRaw = container.dataset.flashSold;
            const stockRaw = container.dataset.flashStock;
            const sold = soldRaw !== undefined ? parseInt(soldRaw, 10) : NaN;
            const stock = stockRaw !== undefined ? parseInt(stockRaw, 10) : NaN;
            const hasRealData = !Number.isNaN(sold) && sold >= 0
                && !Number.isNaN(stock) && stock >= 0
                && (sold + stock) > 0;
            const soldPct = hasRealData
                ? Math.min(100, Math.max(0, Math.round((sold / (sold + stock)) * 100)))
                : 0;
            const progressBlock = hasRealData
                ? `
                <div class="ltms-flash-sale-progress" role="progressbar" aria-valuenow="${soldPct}" aria-valuemin="0" aria-valuemax="100" aria-label="${sold} vendidos, ${stock} disponibles">
                    <div class="ltms-flash-sale-progress-bar" style="width:${soldPct}%;"></div>
                    <span class="ltms-flash-sale-progress-text">${sold} vendidos${stock > 0 ? ` · ¡Quedan ${stock} unidades!` : ' · ¡Agotado!'}</span>
                </div>`
                : '';

            container.className = 'ltms-flash-sale';
            container.innerHTML = `
                <div class="ltms-flash-sale-badge">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                    FLASH SALE · -${escapeHtml(discount)}
                </div>
                <div class="ltms-flash-sale-content">
                    <div class="ltms-flash-sale-image">
                        ${productImg ? `<img src="${escapeHtml(productImg)}" alt="${escapeHtml(productName)}">` : '<div class="ltms-flash-sale-no-img">⚡</div>'}
                    </div>
                    <div class="ltms-flash-sale-info">
                        <h3 class="ltms-flash-sale-name">${escapeHtml(productName)}</h3>
                        <div class="ltms-flash-sale-prices">
                            ${originalPrice ? `<span class="ltms-flash-sale-original">${escapeHtml(originalPrice)}</span>` : ''}
                            ${salePrice ? `<span class="ltms-flash-sale-price">${escapeHtml(salePrice)}</span>` : ''}
                        </div>
                        <div class="ltms-flash-sale-countdown" data-countdown="${escapeHtml(endTime)}" data-countdown-prefix="⏰ Termina en"></div>
                        <a href="${escapeHtml(productUrl)}" class="ltms-btn ltms-btn-primary ltms-flash-sale-btn">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                            ¡Comprar ahora!
                        </a>
                    </div>
                </div>
                ${progressBlock}
            `;

            // Init countdown inside
            const cdEl = container.querySelector('[data-countdown]');
            if (cdEl) createCountdown(cdEl, endTime);

            // Animate progress bar from 0 → soldPct (no fabricated randomness).
            // Only animate when real data was provided.
            if (hasRealData) {
                const bar = container.querySelector('.ltms-flash-sale-progress-bar');
                if (bar) {
                    bar.style.width = '0%';
                    bar.style.transition = 'width 2s ease-out';
                    setTimeout(() => {
                        bar.style.width = soldPct + '%';
                    }, 500);
                }
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 107. WAITLIST — Lista de espera para productos agotados
    // ═══════════════════════════════════════════════════════════

    /**
     * Permite a los usuarios unirse a una lista de espera cuando
     * un producto está agotado. Notifica automáticamente cuando
     * vuelve a estar disponible.
     */

    function initWaitlist() {
        document.querySelectorAll('[data-waitlist]').forEach((container) => {
            if (container.dataset.wlInit) return;
            container.dataset.wlInit = 'true';

            const productId = container.dataset.waitlist;
            const productName = container.dataset.waitlistName || '';

            container.className = 'ltms-waitlist';
            container.innerHTML = `
                <div class="ltms-waitlist-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                </div>
                <div class="ltms-waitlist-content">
                    <strong>Producto agotado</strong>
                    <p>Te avisaremos por email cuando vuelva a estar disponible.</p>
                </div>
                <button type="button" class="ltms-btn ltms-btn-primary ltms-waitlist-btn" data-waitlist-trigger="${escapeHtml(productId)}" data-waitlist-name="${escapeHtml(productName)}">
                    Unirme a la lista de espera
                </button>
            `;
        });

        // Waitlist trigger
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-waitlist-trigger]');
            if (!btn) return;
            e.preventDefault();

            const productId = btn.dataset.waitlistTrigger;
            openWaitlistModal(productId, btn.dataset.waitlistName || '');
        });
    }

    function openWaitlistModal(productId, productName) {
        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay';
        overlay.innerHTML = `
            <div class="ltms-modal ltms-waitlist-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-wl-title">
                <div class="ltms-modal-header">
                    <h3 class="ltms-modal-title" id="ltms-wl-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                        Lista de espera
                    </h3>
                    <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ltms-modal-body">
                    <p style="margin:0 0 16px;color:var(--ltms-gray-600);font-size:0.9rem;line-height:1.5;">
                        Te avisaremos por email en cuanto <strong>${escapeHtml(productName)}</strong> vuelva a estar disponible. ¡No te lo pierdas!
                    </p>
                    <div class="ltms-form-group">
                        <label for="ltms-wl-email">Email *</label>
                        <input type="email" id="ltms-wl-email" class="ltms-form-control" required placeholder="tu@email.com" data-validate="required|email">
                    </div>
                    <div class="ltms-form-group">
                        <label for="ltms-wl-phone">Teléfono (opcional)</label>
                        <input type="tel" id="ltms-wl-phone" class="ltms-form-control" placeholder="+57 300 000 0000">
                    </div>
                    <label class="ltms-checkbox-label" style="margin-bottom:16px;">
                        <input type="checkbox" id="ltms-wl-notify" checked>
                        Notificarme también por SMS/WhatsApp
                    </label>
                </div>
                <div class="ltms-modal-footer">
                    <button type="button" class="ltms-btn ltms-btn-outline ltms-modal-close">Cancelar</button>
                    <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-wl-submit">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        Unirme
                    </button>
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

        overlay.querySelector('#ltms-wl-submit').addEventListener('click', () => {
            const email = overlay.querySelector('#ltms-wl-email').value.trim();
            const phone = overlay.querySelector('#ltms-wl-phone').value.trim();
            const notify = overlay.querySelector('#ltms-wl-notify').checked;

            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                toast('warning', 'Email requerido', 'Ingresa un email válido.');
                return;
            }

            const submitBtn = overlay.querySelector('#ltms-wl-submit');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="ltms-spinner"></span> Guardando...';

            // Task 67-B — Use the global ltmsUX bootstrap (available on every
            // page) and POST to `ltms_waitlist_subscribe` (registered in
            // class-ltms-frontend-checkout-handler.php). The previous code
            // gated on `ltmsDashboard` only (vendor dashboard) and faked a
            // success toast on the storefront, telling the user they were on
            // the list when nothing was sent.
            const ajaxUrl   = (typeof ltmsUX !== 'undefined' && ltmsUX.ajax_url) || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url);
            const ajaxNonce = (typeof ltmsUX !== 'undefined' && ltmsUX.nonce)     || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce);

            if (typeof jQuery !== 'undefined' && ajaxUrl && ajaxNonce) {
                jQuery.post(ajaxUrl, {
                    action: 'ltms_waitlist_subscribe',
                    nonce: ajaxNonce,
                    product_id: productId,
                    email: email,
                    phone: phone,
                    notify_sms: notify,
                }, (response) => {
                    if (response.success) {
                        close();
                        toast('success', '✅ ¡Estás en la lista!', response.data?.message || 'Te avisaremos cuando el producto esté disponible.');
                        // Replace button text
                        const trigger = document.querySelector(`[data-waitlist-trigger="${CSS.escape(productId)}"]`);
                        if (trigger) {
                            trigger.replaceWith(
                                Object.assign(document.createElement('div'), {
                                    className: 'ltms-waitlist-joined',
                                    innerHTML: '✓ Estás en la lista de espera',
                                })
                            );
                        }
                    } else {
                        toast('error', 'Error', response.data?.message || response.data || 'No se pudo completar.');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Unirme';
                    }
                }).fail(() => {
                    toast('error', 'Error de conexión', 'No se pudo completar. Intenta de nuevo.');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Unirme';
                });
            } else {
                // UX-FAKE FIX — do NOT fake success. Surface a real error so
                // the user knows the subscription was not saved.
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Unirme';
                toast('error', 'No disponible', 'No se pudo registrar tu email en este momento. Recarga la página e inténtalo de nuevo.');
            }
        });

        setTimeout(() => overlay.querySelector('#ltms-wl-email').focus(), 300);
    }

    LTMS.UX.openWaitlistModal = openWaitlistModal;

    // ═══════════════════════════════════════════════════════════
    // 108. PRODUCT BUNDLE — Constructor de paquetes
    // ═══════════════════════════════════════════════════════════

    /**
     * Permite crear bundles personalizados: el usuario selecciona
     * productos y ve el precio total con descuento por bundle.
     */

    function initProductBundle() {
        document.querySelectorAll('[data-product-bundle]').forEach((container) => {
            if (container.dataset.pbInit) return;
            container.dataset.pbInit = 'true';

            const discount = parseFloat(container.dataset.bundleDiscount || '10');
            const minItems = parseInt(container.dataset.bundleMin || '2', 10);
            const items = container.querySelectorAll('[data-bundle-item]');
            const summaryEl = container.querySelector('[data-bundle-summary]') || createBundleSummary(container);

            function updateBundle() {
                const selected = [...items].filter((item) => item.querySelector('input[type="checkbox"]')?.checked);
                const count = selected.length;
                const subtotal = selected.reduce((sum, item) => {
                    return sum + parseFloat(item.dataset.bundlePrice || '0');
                }, 0);

                const hasDiscount = count >= minItems;
                const discountAmount = hasDiscount ? subtotal * (discount / 100) : 0;
                const total = subtotal - discountAmount;

                // Update items visual
                items.forEach((item) => {
                    item.classList.toggle('selected', item.querySelector('input')?.checked);
                });

                // Update summary
                summaryEl.innerHTML = `
                    <div class="ltms-bundle-summary-count">
                        <strong>${count}</strong> producto${count !== 1 ? 's' : ''} seleccionado${count !== 1 ? 's' : ''}
                        ${count < minItems ? `<span class="ltms-bundle-min-hint">· Selecciona ${minItems - count} más para descuento</span>` : ''}
                    </div>
                    ${count > 0 ? `
                        <div class="ltms-bundle-summary-prices">
                            <div class="ltms-bundle-summary-row">
                                <span>Subtotal:</span>
                                <span>${formatCurrency(subtotal)}</span>
                            </div>
                            ${hasDiscount ? `
                                <div class="ltms-bundle-summary-row ltms-bundle-discount">
                                    <span>Descuento (${discount}%):</span>
                                    <span>-${formatCurrency(discountAmount)}</span>
                                </div>
                                <div class="ltms-bundle-summary-row ltms-bundle-total">
                                    <span>Total:</span>
                                    <strong>${formatCurrency(total)}</strong>
                                </div>
                            ` : `
                                <div class="ltms-bundle-summary-row ltms-bundle-total">
                                    <span>Total:</span>
                                    <strong>${formatCurrency(subtotal)}</strong>
                                </div>
                            `}
                        </div>
                        <button type="button" class="ltms-btn ltms-btn-primary ltms-btn-full ltms-bundle-add-cart" ${count < 1 ? 'disabled' : ''}>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                            Añadir ${count} al carrito ${hasDiscount ? `(${formatCurrency(total)})` : ''}
                        </button>
                    ` : ''}
                `;

                // Add to cart
                const addBtn = summaryEl.querySelector('.ltms-bundle-add-cart');
                if (addBtn) {
                    addBtn.addEventListener('click', () => {
                        const productIds = selected.map((item) => item.dataset.bundleItem);

                        // UX-FAKE-3 FIX — Previously this handler only fired a
                        // success toast and `announce()` without ever POSTing
                        // the bundle to the server. The user was told the
                        // bundle was added to the cart while nothing happened.
                        // Now we POST to ltms_add_bundle_to_cart (registered
                        // in class-ltms-frontend-checkout-handler.php) and
                        // only toast success when the server confirms.
                        if (!productIds.length) {
                            toast('warning', 'Selecciona productos', 'Elige al menos un producto del bundle.');
                            return;
                        }

                        // Prefer the global ltmsUX bootstrap (available on
                        // every page) and fall back to ltmsDashboard for the
                        // vendor dashboard context.
                        const ajaxUrl   = (typeof ltmsUX !== 'undefined' && ltmsUX.ajax_url) || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url);
                        const ajaxNonce = (typeof ltmsUX !== 'undefined' && ltmsUX.nonce)     || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce);

                        if (typeof jQuery === 'undefined' || !ajaxUrl || !ajaxNonce) {
                            toast('error', 'No disponible', 'No se pudo agregar el bundle. Recarga la página e inténtalo de nuevo.');
                            return;
                        }

                        const originalHtml = addBtn.innerHTML;
                        addBtn.disabled = true;
                        addBtn.innerHTML = '<span class="ltms-spinner"></span> Añadiendo...';

                        jQuery.post(ajaxUrl, {
                            action: 'ltms_add_bundle_to_cart',
                            nonce: ajaxNonce,
                            product_ids: productIds,
                        }, (response) => {
                            if (response.success) {
                                toast('success', '¡Éxito!', response.data?.message || 'Bundle agregado al carrito');
                                announce(`Bundle de ${productIds.length} productos añadido al carrito`);
                                // Update cart count badge if the server returned one.
                                if (response.data?.cart_count !== undefined) {
                                    document.querySelectorAll('.ltms-sf-cart-count, .cart-count').forEach((el) => {
                                        el.textContent = response.data.cart_count;
                                        el.style.display = response.data.cart_count > 0 ? '' : 'none';
                                    });
                                }
                                // Trigger WC fragments refresh so mini-cart widgets update.
                                if (typeof jQuery !== 'undefined' && jQuery(document.body).triggerHandler) {
                                    jQuery(document.body).trigger('wc_fragment_refresh');
                                }
                            } else {
                                toast('error', 'Error', response.data?.message || 'No se pudo agregar el bundle');
                            }
                        }).fail(() => {
                            toast('error', 'Error de conexión', 'No se pudo agregar el bundle. Intenta de nuevo.');
                        }).always(() => {
                            addBtn.disabled = false;
                            addBtn.innerHTML = originalHtml;
                        });
                    });
                }
            }

            items.forEach((item) => {
                const checkbox = item.querySelector('input[type="checkbox"]');
                if (checkbox) {
                    checkbox.addEventListener('change', updateBundle);
                }
                item.addEventListener('click', (e) => {
                    if (e.target !== checkbox) {
                        checkbox.checked = !checkbox.checked;
                        updateBundle();
                    }
                });
            });

            updateBundle();
        });
    }

    function createBundleSummary(container) {
        const summary = document.createElement('div');
        summary.className = 'ltms-bundle-summary';
        summary.setAttribute('data-bundle-summary', '');
        container.appendChild(summary);
        return summary;
    }

    // ═══════════════════════════════════════════════════════════
    // 109. DIGITAL DOWNLOADS — Gestor de descargas digitales
    // ═══════════════════════════════════════════════════════════

    /**
     * Gestiona descargas de productos digitales: muestra archivos
     * disponibles, límite de descargas, expiración y historial.
     */

    function initDigitalDownloads() {
        document.querySelectorAll('[data-digital-downloads]').forEach((container) => {
            if (container.dataset.ddInit) return;
            container.dataset.ddInit = 'true';

            const orderId = container.dataset.digitalDownloads;
            const downloads = JSON.parse(container.dataset.downloadFiles || '[]');

            if (!downloads.length) return;

            container.className = 'ltms-digital-downloads';
            container.innerHTML = `
                <div class="ltms-digital-downloads-header">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    <h3>Tus descargas digitales</h3>
                </div>
                <div class="ltms-digital-downloads-list">
                    ${downloads.map((file) => {
                        const expired = file.expires && new Date(file.expires) < new Date();
                        const exhausted = file.max_downloads && file.downloads >= file.max_downloads;
                        const disabled = expired || exhausted;

                        return `
                            <div class="ltms-download-item ${disabled ? 'disabled' : ''}">
                                <div class="ltms-download-icon">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                </div>
                                <div class="ltms-download-info">
                                    <div class="ltms-download-name">${escapeHtml(file.name)}</div>
                                    <div class="ltms-download-meta">
                                        ${file.size ? `<span>📄 ${escapeHtml(file.size)}</span>` : ''}
                                        ${file.max_downloads ? `<span>⬇️ ${file.downloads || 0}/${file.max_downloads} descargas</span>` : ''}
                                        ${file.expires ? `<span>⏰ ${expired ? 'Expirado' : 'Expira: ' + escapeHtml(file.expires)}</span>` : ''}
                                    </div>
                                </div>
                                ${disabled
                                    ? '<span class="ltms-download-disabled-badge">No disponible</span>'
                                    : `<a href="${escapeHtml(file.url)}" download class="ltms-btn ltms-btn-primary ltms-btn-sm ltms-download-btn" data-download-url="${escapeHtml(file.url)}">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                        Descargar
                                    </a>`
                                }
                            </div>
                        `;
                    }).join('')}
                </div>
            `;

            // Track downloads
            container.querySelectorAll('.ltms-download-btn').forEach((btn) => {
                btn.addEventListener('click', () => {
                    toast('info', 'Descargando...', 'Tu archivo se está descargando.');
                    announce('Descarga iniciada');
                });
            });
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 110. VENDOR RATING — Calificación de vendedores
    // ═══════════════════════════════════════════════════════════

    /**
     * Sistema de calificación de vendedores con métricas:
     * rating general, tiempo de respuesta, calidad de envío,
     * comunicación y precisión de descripción.
     */

    function initVendorRating() {
        document.querySelectorAll('[data-vendor-rating]').forEach((container) => {
            if (container.dataset.vrInit) return;
            container.dataset.vrInit = 'true';

            const rating = parseFloat(container.dataset.vendorRating || '0');
            const reviewCount = parseInt(container.dataset.vendorReviews || '0', 10);
            const metrics = JSON.parse(container.dataset.vendorMetrics || '{}');

            const defaultMetrics = {
                quality: rating,
                shipping: rating,
                communication: rating,
                accuracy: rating,
            };
            const m = { ...defaultMetrics, ...metrics };

            const metricLabels = {
                quality: 'Calidad del producto',
                shipping: 'Velocidad de envío',
                communication: 'Comunicación',
                accuracy: 'Precisión de descripción',
            };

            container.className = 'ltms-vendor-rating';
            container.innerHTML = `
                <div class="ltms-vendor-rating-summary">
                    <div class="ltms-vendor-rating-score">
                        <span class="ltms-vendor-rating-num">${rating.toFixed(1)}</span>
                        <div class="ltms-vendor-rating-stars">${renderStars(rating)}</div>
                        <span class="ltms-vendor-rating-count">${reviewCount} reseñas</span>
                    </div>
                    <div class="ltms-vendor-rating-metrics">
                        ${Object.entries(m).map(([key, val]) => `
                            <div class="ltms-vendor-rating-metric">
                                <span class="ltms-vendor-rating-metric-label">${escapeHtml(metricLabels[key] || key)}</span>
                                <div class="ltms-vendor-rating-metric-bar">
                                    <div class="ltms-vendor-rating-metric-fill" style="width:${(val / 5) * 100}%;"></div>
                                </div>
                                <span class="ltms-vendor-rating-metric-value">${val.toFixed(1)}</span>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 111. PRICE MATCH — Garantía de mejor precio
    // ═══════════════════════════════════════════════════════════

    /**
     * Formulario de garantía de mejor precio: el usuario reporta
     * un precio menor en otro sitio y recibe un reembolso o
     * ajuste de precio.
     */

    function initPriceMatch() {
        document.addEventListener('click', (e) => {
            const trigger = e.target.closest('[data-price-match]');
            if (!trigger) return;
            e.preventDefault();

            const productId = trigger.dataset.priceMatch;
            const currentPrice = trigger.dataset.currentPrice || '';
            const productName = trigger.dataset.productName || '';

            openPriceMatchModal(productId, productName, currentPrice);
        });
    }

    function openPriceMatchModal(productId, productName, currentPrice) {
        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay';
        overlay.innerHTML = `
            <div class="ltms-modal ltms-price-match-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-pm-title">
                <div class="ltms-modal-header">
                    <h3 class="ltms-modal-title" id="ltms-pm-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                        Garantía de mejor precio
                    </h3>
                    <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ltms-modal-body">
                    <div class="ltms-price-match-info">
                        <p>¿Encontraste <strong>${escapeHtml(productName)}</strong> más barato en otro sitio? ¡Igualamos el precio!</p>
                        <p class="ltms-price-match-current">Precio actual: <strong>${escapeHtml(currentPrice)}</strong></p>
                    </div>
                    <div class="ltms-form-group">
                        <label for="ltms-pm-competitor">URL del producto en la tienda competidora *</label>
                        <input type="url" id="ltms-pm-competitor" class="ltms-form-control" required placeholder="https://otra-tienda.com/producto" data-validate="required|url">
                    </div>
                    <div class="ltms-form-group">
                        <label for="ltms-pm-price">Precio encontrado *</label>
                        <input type="number" id="ltms-pm-price" class="ltms-form-control" required placeholder="0" min="0" step="0.01" data-validate="required|number|min:0">
                    </div>
                    <div class="ltms-form-group">
                        <label for="ltms-pm-email">Tu email *</label>
                        <input type="email" id="ltms-pm-email" class="ltms-form-control" required placeholder="tu@email.com" data-validate="required|email">
                    </div>
                    <div class="ltms-form-group">
                        <label for="ltms-pm-screenshot">Captura de pantalla (opcional)</label>
                        <input type="file" id="ltms-pm-screenshot" class="ltms-file-input" accept="image/*">
                    </div>
                    <div class="ltms-price-match-terms">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                        <span>El producto debe ser idéntico (misma marca, modelo, condición). Válido dentro de los 7 días posteriores a la compra.</span>
                    </div>
                </div>
                <div class="ltms-modal-footer">
                    <button type="button" class="ltms-btn ltms-btn-outline ltms-modal-close">Cancelar</button>
                    <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-pm-submit">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        Enviar solicitud
                    </button>
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

        overlay.querySelector('#ltms-pm-submit').addEventListener('click', () => {
            const url = overlay.querySelector('#ltms-pm-competitor').value.trim();
            const price = overlay.querySelector('#ltms-pm-price').value;
            const email = overlay.querySelector('#ltms-pm-email').value.trim();

            if (!url || !price || !email) {
                toast('warning', 'Campos requeridos', 'Completa todos los campos obligatorios.');
                return;
            }

            const submitBtn = overlay.querySelector('#ltms-pm-submit');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="ltms-spinner"></span> Enviando...';

            setTimeout(() => {
                close();
                toast('success', '✅ Solicitud enviada', 'Revisaremos tu solicitud en 24-48 horas.');
            }, 1000);
        });
    }

    LTMS.UX.openPriceMatchModal = openPriceMatchModal;

    // ═══════════════════════════════════════════════════════════
    // 112. WHATSAPP ORDER — Botón de pedido por WhatsApp
    // ═══════════════════════════════════════════════════════════

    /**
     * Botón flotante o inline que abre WhatsApp con un mensaje
     * pre-rellenado con los datos del producto/carrito.
     */

    function initWhatsAppOrder() {
        document.querySelectorAll('[data-whatsapp-order]').forEach((btn) => {
            if (btn.dataset.waInit) return;
            btn.dataset.waInit = 'true';

            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const phone = btn.dataset.whatsappOrder || '';
                const productName = btn.dataset.productName || '';
                const productUrl = btn.dataset.productUrl || window.location.href;
                const productPrice = btn.dataset.productPrice || '';
                const vendorName = btn.dataset.vendorName || '';

                let message = '¡Hola! 👋\n\n';
                message += `Quiero hacer un pedido:\n\n`;
                message += `📦 *Producto:* ${productName}\n`;
                if (productPrice) message += `💰 *Precio:* ${productPrice}\n`;
                if (vendorName) message += `🏪 *Tienda:* ${vendorName}\n`;
                message += `🔗 *Link:* ${productUrl}\n\n`;
                message += `¿Está disponible? ¿Cómo procedo con el pago?`;

                const url = `https://wa.me/${phone}?text=${encodeURIComponent(message)}`;
                window.open(url, '_blank', 'width=600,height=600');

                toast('success', 'Abriendo WhatsApp', 'Te redirigimos al chat para completar tu pedido.');
                announce('Abriendo WhatsApp para realizar pedido');
            });
        });

        // Floating WhatsApp button
        const floatingBtn = document.querySelector('[data-whatsapp-float]');
        if (floatingBtn && !floatingBtn.dataset.waFloatInit) {
            floatingBtn.dataset.waFloatInit = 'true';
            floatingBtn.className = 'ltms-whatsapp-float';
            floatingBtn.setAttribute('aria-label', 'Pedir por WhatsApp');
            floatingBtn.innerHTML = `
                <svg width="28" height="28" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.693.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
            `;
        }
    }

    // ═══════════════════════════════════════════════════════════
    // 113. ONE-CLICK REORDER — Reordenar pedido anterior
    // ═══════════════════════════════════════════════════════════

    /**
     * Permite reordenar un pedido anterior con un solo clic,
     * añadiendo todos los items al carrito automáticamente.
     */

    function initOneClickReorder() {
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-reorder]');
            if (!btn) return;
            e.preventDefault();

            const orderId = btn.dataset.reorder;
            const itemCount = btn.dataset.reorderCount || '';

            const confirmMsg = itemCount
                ? `¿Reordenar ${itemCount} producto(s) de tu pedido anterior?`
                : '¿Reordenar este pedido?';

            if (!confirm(confirmMsg)) return;

            btn.disabled = true;
            btn.innerHTML = '<span class="ltms-spinner"></span> Añadiendo...';

            // Task 67-B / UX-FAKE-1 — Resolve the AJAX bootstrap. Historically
            // every UX module gated the AJAX call on
            // `typeof ltmsDashboard !== 'undefined'`, but ltmsDashboard is only
            // localized on the vendor dashboard, so on the customer-facing
            // storefront the call was never made and the else-branch faked a
            // success toast. The new `ltmsUX` global (added in
            // class-ltms-frontend-assets.php) is available everywhere — prefer
            // it and fall back to ltmsDashboard for back-compat with older
            // dashboard pages.
            const ajaxUrl   = (typeof ltmsUX !== 'undefined' && ltmsUX.ajax_url) || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.ajax_url);
            const ajaxNonce = (typeof ltmsUX !== 'undefined' && ltmsUX.nonce)     || (typeof ltmsDashboard !== 'undefined' && ltmsDashboard.nonce);

            if (typeof jQuery !== 'undefined' && ajaxUrl && ajaxNonce) {
                jQuery.post(ajaxUrl, {
                    action: 'ltms_reorder',
                    nonce: ajaxNonce,
                    order_id: orderId,
                }, (response) => {
                    if (response.success) {
                        toast('success', '¡Productos añadidos!', response.data?.message || 'Tu carrito ha sido actualizado.');
                        // Update cart count
                        if (response.data?.cart_count !== undefined) {
                            document.querySelectorAll('.ltms-sf-cart-count, .cart-count').forEach((el) => {
                                el.textContent = response.data.cart_count;
                                el.style.display = response.data.cart_count > 0 ? '' : 'none';
                            });
                        }
                        // Open cart drawer
                        setTimeout(() => openCartDrawer(), 500);
                    } else {
                        toast('error', 'Error', response.data?.message || response.data || 'No se pudo reordenar.');
                    }
                }).fail(() => {
                    toast('error', 'Error de conexión', 'No se pudo completar la reorden. Intenta de nuevo.');
                }).always(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg> Reordenar';
                });
            } else {
                // UX-FAKE-1 FIX — do NOT fake a success toast. The endpoint
                // cannot be reached (no jQuery or no ajax bootstrap). Surface
                // a clear error so the user knows the action failed and is
                // not misled into believing the items were added.
                btn.disabled = false;
                btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg> Reordenar';
                toast('error', 'No disponible', 'La reorden no está disponible en este momento. Recarga la página e inténtalo de nuevo.');
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 114. SAVE CART — Guardar carrito para después
    // ═══════════════════════════════════════════════════════════

    /**
     * Permite guardar el carrito actual para recuperarlo después,
     * útil cuando el usuario quiere seguir navegando.
     */

    function initSaveCart() {
        // Save cart button
        document.addEventListener('click', (e) => {
            const saveBtn = e.target.closest('[data-save-cart]');
            if (!saveBtn) return;
            e.preventDefault();

            const cartItems = [];
            document.querySelectorAll('[data-cart-item-key]').forEach((item) => {
                cartItems.push({
                    key: item.dataset.cartItemKey,
                    name: item.querySelector('.ltms-cart-item-name, .product-name')?.textContent?.trim() || '',
                    price: item.querySelector('.ltms-cart-item-price, .product-price')?.textContent?.trim() || '',
                    qty: item.querySelector('.ltms-cart-qty-value, .qty')?.textContent?.trim() || '1',
                });
            });

            if (!cartItems.length) {
                toast('warning', 'Carrito vacío', 'No hay items para guardar.');
                return;
            }

            const savedCarts = JSON.parse(localStorage.getItem('ltms-saved-carts') || '[]');
            savedCarts.unshift({
                id: Date.now(),
                date: new Date().toISOString(),
                items: cartItems,
                count: cartItems.length,
            });

            // Keep max 5 saved carts
            const trimmed = savedCarts.slice(0, 5);
            localStorage.setItem('ltms-saved-carts', JSON.stringify(trimmed));

            toast('success', '✅ Carrito guardado', `${cartItems.length} producto(s) guardados para después.`);
            announce(`Carrito guardado con ${cartItems.length} productos`);
        });

        // Restore cart button
        document.addEventListener('click', (e) => {
            const restoreBtn = e.target.closest('[data-restore-cart]');
            if (!restoreBtn) return;
            e.preventDefault();

            const cartId = restoreBtn.dataset.restoreCart;
            const savedCarts = JSON.parse(localStorage.getItem('ltms-saved-carts') || '[]');
            const cart = savedCarts.find((c) => c.id == cartId);

            if (!cart) {
                toast('error', 'No encontrado', 'El carrito guardado ya no existe.');
                return;
            }

            // Show confirmation
            const overlay = document.createElement('div');
            overlay.className = 'ltms-modal-overlay';
            overlay.innerHTML = `
                <div class="ltms-modal" role="dialog" aria-modal="true">
                    <div class="ltms-modal-header">
                        <h3 class="ltms-modal-title">Restaurar carrito</h3>
                        <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                    <div class="ltms-modal-body">
                        <p style="margin:0 0 14px;color:var(--ltms-gray-600);">¿Restaurar ${cart.count} producto(s) guardados el ${new Date(cart.date).toLocaleDateString('es-CO')}?</p>
                        <div class="ltms-saved-cart-items">
                            ${cart.items.map((item) => `
                                <div class="ltms-saved-cart-item">
                                    <span>${escapeHtml(item.name)}</span>
                                    <span style="color:var(--ltms-gray-500);font-size:0.8rem;">${escapeHtml(item.price)} × ${escapeHtml(item.qty)}</span>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                    <div class="ltms-modal-footer">
                        <button type="button" class="ltms-btn ltms-btn-outline ltms-modal-close">Cancelar</button>
                        <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-restore-confirm">Restaurar</button>
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

            overlay.querySelectorAll('.ltms-modal-close').forEach((b) => b.addEventListener('click', close));
            overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

            overlay.querySelector('#ltms-restore-confirm').addEventListener('click', () => {
                close();
                toast('success', 'Carrito restaurado', `${cart.count} producto(s) añadidos. Recarga la página para verlos.`);
                setTimeout(() => location.reload(), 1500);
            });
        });

        // Delete saved cart
        document.addEventListener('click', (e) => {
            const deleteBtn = e.target.closest('[data-delete-saved-cart]');
            if (!deleteBtn) return;
            e.preventDefault();

            const cartId = deleteBtn.dataset.deleteSavedCart;
            const savedCarts = JSON.parse(localStorage.getItem('ltms-saved-carts') || '[]');
            const filtered = savedCarts.filter((c) => c.id != cartId);
            localStorage.setItem('ltms-saved-carts', JSON.stringify(filtered));

            deleteBtn.closest('.ltms-saved-cart-entry')?.remove();
            toast('info', 'Carrito eliminado', '');
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 115. SHIPPING CALCULATOR — Calculadora de envío
    // ═══════════════════════════════════════════════════════════

    /**
     * Widget que calcula el costo de envío estimado
     * basado en código postal/ciudad antes del checkout.
     */

    function initShippingCalculator() {
        document.querySelectorAll('[data-shipping-calculator]').forEach((container) => {
            if (container.dataset.scInit) return;
            container.dataset.scInit = 'true';

            container.className = 'ltms-shipping-calculator';
            container.innerHTML = `
                <div class="ltms-shipping-calc-header">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
                    <h4>Calcular envío</h4>
                </div>
                <div class="ltms-shipping-calc-form">
                    <select class="ltms-shipping-calc-country" aria-label="País">
                        <option value="CO">🇨🇴 Colombia</option>
                        <option value="MX">🇲🇽 México</option>
                    </select>
                    <input type="text" class="ltms-shipping-calc-city" placeholder="Ciudad o código postal" aria-label="Ciudad">
                    <button type="button" class="ltms-btn ltms-btn-primary ltms-btn-sm ltms-shipping-calc-btn">
                        Calcular
                    </button>
                </div>
                <div class="ltms-shipping-calc-results" style="display:none;"></div>
            `;

            const resultsEl = container.querySelector('.ltms-shipping-calc-results');
            const calcBtn = container.querySelector('.ltms-shipping-calc-btn');
            const cityInput = container.querySelector('.ltms-shipping-calc-city');
            const countrySelect = container.querySelector('.ltms-shipping-calc-country');

            calcBtn.addEventListener('click', () => {
                const city = cityInput.value.trim();
                if (!city) {
                    toast('warning', 'Campo requerido', 'Ingresa tu ciudad o código postal.');
                    return;
                }

                calcBtn.disabled = true;
                calcBtn.innerHTML = '<span class="ltms-spinner"></span>';

                // Simulated rates (in production, use AJAX to Aveonline/Heka/Deprisa)
                const rates = [
                    { name: 'Envío Estándar', price: '$8.500', days: '3-5 días', icon: '📦' },
                    { name: 'Envío Express', price: '$15.000', days: '1-2 días', icon: '⚡' },
                    { name: 'Recogida en tienda', price: 'Gratis', days: 'Disponible hoy', icon: '🏪' },
                ];

                setTimeout(() => {
                    resultsEl.style.display = 'block';
                    resultsEl.innerHTML = `
                        <div class="ltms-shipping-calc-rates">
                            ${rates.map((rate, i) => `
                                <label class="ltms-shipping-rate ${i === 0 ? 'selected' : ''}">
                                    <input type="radio" name="shipping_rate" value="${escapeHtml(rate.name)}" ${i === 0 ? 'checked' : ''}>
                                    <div class="ltms-shipping-rate-icon">${rate.icon}</div>
                                    <div class="ltms-shipping-rate-info">
                                        <strong>${escapeHtml(rate.name)}</strong>
                                        <span>${escapeHtml(rate.days)}</span>
                                    </div>
                                    <div class="ltms-shipping-rate-price ${rate.price === 'Gratis' ? 'free' : ''}">${escapeHtml(rate.price)}</div>
                                </label>
                            `).join('')}
                        </div>
                    `;

                    calcBtn.disabled = false;
                    calcBtn.textContent = 'Recalcular';

                    // Selection
                    resultsEl.querySelectorAll('.ltms-shipping-rate').forEach((el) => {
                        el.addEventListener('click', () => {
                            resultsEl.querySelectorAll('.ltms-shipping-rate').forEach((r) => r.classList.remove('selected'));
                            el.classList.add('selected');
                            el.querySelector('input').checked = true;
                        });
                    });

                    announce(`${rates.length} opciones de envío disponibles`);
                }, 800);
            });

            // Enter key
            cityInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); calcBtn.click(); }
            });
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 116. PRODUCT Q&A — Preguntas y respuestas
    // ═══════════════════════════════════════════════════════════

    /**
     * Sección de preguntas y respuestas de productos:
     * los usuarios pueden hacer preguntas y ver respuestas
     * del vendedor y de otros compradores.
     */

    function initProductQA() {
        document.querySelectorAll('[data-product-qa]').forEach((container) => {
            if (container.dataset.qaInit) return;
            container.dataset.qaInit = 'true';

            const productId = container.dataset.productQa;
            const questions = JSON.parse(container.dataset.qaQuestions || '[]');

            container.className = 'ltms-product-qa';
            container.innerHTML = `
                <div class="ltms-qa-header">
                    <h3>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        Preguntas y respuestas
                    </h3>
                    <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" id="ltms-qa-ask">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Hacer pregunta
                    </button>
                </div>
                <div class="ltms-qa-list">
                    ${questions.length ? questions.map((q) => `
                        <div class="ltms-qa-item">
                            <div class="ltms-qa-question">
                                <div class="ltms-qa-question-avatar">👤</div>
                                <div class="ltms-qa-question-content">
                                    <div class="ltms-qa-question-text">${escapeHtml(q.question)}</div>
                                    <div class="ltms-qa-meta">${escapeHtml(q.author || 'Anónimo')} · ${escapeHtml(q.date || '')}</div>
                                </div>
                            </div>
                            ${q.answer ? `
                                <div class="ltms-qa-answer">
                                    <div class="ltms-qa-answer-avatar">🏪</div>
                                    <div class="ltms-qa-answer-content">
                                        <div class="ltms-qa-answer-text">${escapeHtml(q.answer)}</div>
                                        <div class="ltms-qa-meta">Vendedor · ${escapeHtml(q.answer_date || '')}</div>
                                    </div>
                                </div>
                            ` : '<div class="ltms-qa-pending">⏳ Pendiente de respuesta</div>'}
                        </div>
                    `).join('') : '<div class="ltms-qa-empty">Aún no hay preguntas. ¡Sé el primero en preguntar!</div>'}
                </div>
            `;

            // Ask question
            container.querySelector('#ltms-qa-ask').addEventListener('click', () => {
                openQAForm(productId, container);
            });
        });
    }

    function openQAForm(productId, container) {
        const overlay = document.createElement('div');
        overlay.className = 'ltms-modal-overlay';
        overlay.innerHTML = `
            <div class="ltms-modal ltms-qa-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-qa-title">
                <div class="ltms-modal-header">
                    <h3 class="ltms-modal-title" id="ltms-qa-title">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        Hacer una pregunta
                    </h3>
                    <button type="button" class="ltms-modal-close" aria-label="Cerrar">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="ltms-modal-body">
                    <div class="ltms-form-group">
                        <label for="ltms-qa-input">Tu pregunta *</label>
                        <textarea id="ltms-qa-input" class="ltms-form-control" rows="3" placeholder="Ej: ¿Este producto viene en otros colores?" maxlength="500" data-validate="required|minlength:10"></textarea>
                        <small class="ltms-field-hint"><span id="ltms-qa-counter">0</span>/500 caracteres</small>
                    </div>
                    <div class="ltms-form-group">
                        <label for="ltms-qa-name">Tu nombre (opcional)</label>
                        <input type="text" id="ltms-qa-name" class="ltms-form-control" placeholder="Anónimo">
                    </div>
                </div>
                <div class="ltms-modal-footer">
                    <button type="button" class="ltms-btn ltms-btn-outline ltms-modal-close">Cancelar</button>
                    <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-qa-submit">Enviar pregunta</button>
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

        overlay.querySelectorAll('.ltms-modal-close').forEach((b) => b.addEventListener('click', close));
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

        const textarea = overlay.querySelector('#ltms-qa-input');
        const counter = overlay.querySelector('#ltms-qa-counter');
        textarea.addEventListener('input', () => { counter.textContent = textarea.value.length; });

        overlay.querySelector('#ltms-qa-submit').addEventListener('click', () => {
            const question = textarea.value.trim();
            if (question.length < 10) {
                toast('warning', 'Muy corta', 'Tu pregunta debe tener al menos 10 caracteres.');
                return;
            }

            const submitBtn = overlay.querySelector('#ltms-qa-submit');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="ltms-spinner"></span> Enviando...';

            if (typeof jQuery !== 'undefined' && typeof ltmsDashboard !== 'undefined') {
                jQuery.post(ltmsDashboard.ajax_url, {
                    action: 'ltms_submit_question',
                    nonce: ltmsDashboard.nonce,
                    product_id: productId,
                    question: question,
                    author: overlay.querySelector('#ltms-qa-name').value.trim(),
                }, (response) => {
                    if (response.success) {
                        close();
                        toast('success', '✅ Pregunta enviada', 'El vendedor responderá pronto.');
                        // Add to list
                        const list = container.querySelector('.ltms-qa-list');
                        const empty = list.querySelector('.ltms-qa-empty');
                        if (empty) empty.remove();

                        const item = document.createElement('div');
                        item.className = 'ltms-qa-item';
                        item.innerHTML = `
                            <div class="ltms-qa-question">
                                <div class="ltms-qa-question-avatar">👤</div>
                                <div class="ltms-qa-question-content">
                                    <div class="ltms-qa-question-text">${escapeHtml(question)}</div>
                                    <div class="ltms-qa-meta">Tú · hace un momento</div>
                                </div>
                            </div>
                            <div class="ltms-qa-pending">⏳ Pendiente de respuesta</div>
                        `;
                        list.insertBefore(item, list.firstChild);
                    } else {
                        toast('error', 'Error', response.data || 'No se pudo enviar.');
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Enviar pregunta';
                    }
                });
            } else {
                setTimeout(() => {
                    close();
                    toast('success', '✅ Pregunta enviada', 'El vendedor responderá pronto.');
                }, 800);
            }
        });

        setTimeout(() => textarea.focus(), 300);
    }

    // ═══════════════════════════════════════════════════════════
    // 117. NEWSLETTER SIGNUP — Captura de email con incentivo
    // ═══════════════════════════════════════════════════════════

    /**
     * Modal/banner de newsletter con incentivo de descuento
     * para capturar emails de nuevos visitantes.
     */

    function initNewsletterSignup() {
        // No mostrar si ya se suscribió o cerró
        try {
            if (localStorage.getItem('ltms-newsletter-closed') === 'true') return;
            if (localStorage.getItem('ltms-newsletter-subscribed') === 'true') return;
        } catch (e) { return; }

        // No mostrar en login/admin/checkout
        if (document.querySelector('.ltms-auth-container')) return;
        if (document.body.classList.contains('wp-admin')) return;
        if (document.body.classList.contains('woocommerce-checkout')) return;

        // Solo a usuarios no logueados o después de 60s
        const delay = document.body.classList.contains('logged-in') ? 120000 : 45000;

        setTimeout(() => {
            const overlay = document.createElement('div');
            overlay.className = 'ltms-modal-overlay ltms-newsletter-overlay';
            overlay.innerHTML = `
                <div class="ltms-newsletter-modal" role="dialog" aria-modal="true" aria-labelledby="ltms-nl-title">
                    <button type="button" class="ltms-newsletter-close" aria-label="Cerrar">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                    <div class="ltms-newsletter-content">
                        <div class="ltms-newsletter-icon">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        </div>
                        <h2 id="ltms-nl-title">¡Bienvenido! 🎁</h2>
                        <p>Suscríbete y recibe <strong>10% de descuento</strong> en tu primera compra + ofertas exclusivas.</p>
                        <form id="ltms-newsletter-form" class="ltms-newsletter-form">
                            <input type="email" id="ltms-nl-email" placeholder="tu@email.com" required aria-label="Email" data-validate="required|email">
                            <button type="submit" class="ltms-btn ltms-btn-primary">
                                Quiero mi descuento
                            </button>
                        </form>
                        <div class="ltms-newsletter-terms">
                            Al suscribirte aceptas recibir emails de marketing. Puedes darte de baja en cualquier momento.
                        </div>
                        <div class="ltms-newsletter-trust">
                            <span>🔒 Spam-free</span>
                            <span>✓ Sin compromiso</span>
                            <span>🎁 Descuento inmediato</span>
                        </div>
                    </div>
                </div>
            `;

            document.body.appendChild(overlay);
            document.body.style.overflow = 'hidden';
            requestAnimationFrame(() => overlay.classList.add('visible'));

            const close = () => {
                overlay.classList.remove('visible');
                document.body.style.overflow = '';
                try { localStorage.setItem('ltms-newsletter-closed', 'true'); } catch (e) {}
                setTimeout(() => overlay.remove(), 400);
            };

            overlay.querySelector('.ltms-newsletter-close').addEventListener('click', close);
            overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

            // Form submit
            overlay.querySelector('#ltms-newsletter-form').addEventListener('submit', (e) => {
                e.preventDefault();
                const email = overlay.querySelector('#ltms-nl-email').value.trim();
                if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    toast('warning', 'Email inválido', 'Ingresa un email correcto.');
                    return;
                }

                const btn = overlay.querySelector('button[type="submit"]');
                btn.disabled = true;
                btn.innerHTML = '<span class="ltms-spinner"></span>';

                // Simulate AJAX
                setTimeout(() => {
                    try { localStorage.setItem('ltms-newsletter-subscribed', 'true'); } catch (e) {}

                    overlay.querySelector('.ltms-newsletter-content').innerHTML = `
                        <div class="ltms-newsletter-success">
                            <div class="ltms-newsletter-success-icon">
                                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            </div>
                            <h2>¡Listo! 🎉</h2>
                            <p>Tu código de descuento es <strong class="ltms-newsletter-code">BIENVENIDO10</strong></p>
                            <p class="ltms-newsletter-success-hint">Úsalo al finalizar tu compra para obtener 10% off.</p>
                            <button type="button" class="ltms-btn ltms-btn-primary" onclick="navigator.clipboard.writeText('BIENVENIDO10'); LTMS.UX.toastSuccess('Copiado', 'Código copiado al portapapeles')">
                                Copiar código
                            </button>
                        </div>
                    `;

                    setTimeout(() => {
                        close();
                        toast('success', '🎉 ¡Bienvenido!', 'Revisa tu email para más detalles.');
                    }, 5000);
                }, 1000);
            });

            setTimeout(() => overlay.querySelector('#ltms-nl-email').focus(), 500);
        }, delay);
    }

    // ═══════════════════════════════════════════════════════════
    // 19. BOTTOM NAV — Navegación inferior móvil
    // ═══════════════════════════════════════════════════════════

    function initBottomNav() {
        // Click en items de bottom nav
        document.addEventListener('click', (e) => {
            const item = e.target.closest('.ltms-bottom-nav-item');
            if (!item) return;

            // Botón "Más" abre el sidebar
            if (item.classList.contains('ltms-bottom-nav-more')) {
                const sidebar = document.querySelector('.ltms-sidebar');
                const overlay = document.querySelector('.ltms-sidebar-overlay');
                if (sidebar) {
                    sidebar.classList.add('ltms-sidebar-open');
                    if (overlay) {
                        overlay.classList.add('active');
                        overlay.style.display = 'block';
                    }
                    document.body.style.overflow = 'hidden';
                }
                return;
            }

            // Navegación a vista
            const view = item.dataset.view;
            if (!view) return;

            // Actualizar estado activo en bottom nav
            document.querySelectorAll('.ltms-bottom-nav-item').forEach((el) => {
                if (!el.classList.contains('ltms-bottom-nav-fab')) {
                    el.classList.remove('active');
                }
            });
            item.classList.add('active');

            // Disparar navegación del dashboard
            if (window.LTMS && LTMS.Dashboard && typeof LTMS.Dashboard.loadView === 'function') {
                LTMS.Dashboard.loadView(view);
            }
        });

        // Sincronizar estado activo del bottom nav cuando se navega desde el sidebar
        if (typeof jQuery !== 'undefined') {
            jQuery(document).on('click', '.ltms-nav-item[data-view]', function () {
                const view = jQuery(this).data('view');
                if (!view) return;
                // Actualizar bottom nav
                document.querySelectorAll('.ltms-bottom-nav-item').forEach((el) => {
                    if (!el.classList.contains('ltms-bottom-nav-fab')) {
                        el.classList.remove('active');
                        if (el.dataset.view === view) {
                            el.classList.add('active');
                        }
                    }
                });
            });
        }
    }

    // ═══════════════════════════════════════════════════════════
    // 19. ORDERS FILTER CHIPS — Sincronizar con select nativo
    // ═══════════════════════════════════════════════════════════

    function initOrdersFilterChips() {
        document.addEventListener('click', (e) => {
            const chip = e.target.closest('.ltms-filter-chip');
            if (!chip) return;

            const status = chip.dataset.status || '';

            // Actualizar UI de chips
            const chipsContainer = chip.closest('.ltms-filter-chips');
            if (chipsContainer) {
                chipsContainer.querySelectorAll('.ltms-filter-chip').forEach((c) => {
                    c.classList.remove('active');
                    c.setAttribute('aria-selected', 'false');
                });
                chip.classList.add('active');
                chip.setAttribute('aria-selected', 'true');
            }

            // Sincronizar con el select nativo (que usa el JS original del dashboard)
            const select = document.getElementById('ltms-order-status-filter');
            if (select) {
                select.value = status;
                // Disparar evento change para que el handler original reaccione
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 20. ORDERS SEARCH — Búsqueda con debounce
    // ═══════════════════════════════════════════════════════════

    function initOrdersSearch() {
        const searchInput = document.getElementById('ltms-order-search');
        if (!searchInput) return;

        const debouncedSearch = debounce((query) => {
            // Filtrar las filas visibles de la tabla
            const rows = document.querySelectorAll('#ltms-orders-tbody tr');
            const q = query.toLowerCase().trim();

            rows.forEach((row) => {
                if (!q) {
                    row.style.display = '';
                    return;
                }
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(q) ? '' : 'none';
            });

            // Mostrar mensaje si no hay resultados
            const visibleRows = document.querySelectorAll('#ltms-orders-tbody tr:not([style*="display: none"])');
            const tbody = document.getElementById('ltms-orders-tbody');
            const noResults = document.getElementById('ltms-orders-no-results');

            if (visibleRows.length === 0 && !noResults) {
                const tr = document.createElement('tr');
                tr.id = 'ltms-orders-no-results';
                tr.innerHTML = `<td colspan="8" class="ltms-loading-cell">
                    <div style="padding:30px;color:#9ca3af;">
                        <div style="font-size:2rem;margin-bottom:8px;opacity:0.5;">🔍</div>
                        <div style="font-weight:600;color:#6b7280;margin-bottom:4px;">Sin resultados</div>
                        <div style="font-size:0.825rem;">No se encontraron pedidos para "${escapeHtml(query)}"</div>
                    </div>
                </td>`;
                tbody.appendChild(tr);
            } else if (visibleRows.length > 0 && noResults) {
                noResults.remove();
            }
        }, CONFIG.debounceMs);

        searchInput.addEventListener('input', (e) => {
            debouncedSearch(e.target.value);
        });

        // Limpiar búsqueda con Escape
        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && searchInput.value) {
                searchInput.value = '';
                debouncedSearch('');
                searchInput.blur();
            }
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 21. EXPORT BUTTON — Toast para funcionalidad pendiente
    // ═══════════════════════════════════════════════════════════

    function initExportButtons() {
        document.addEventListener('click', (e) => {
            const btn = e.target.closest('#ltms-export-movements, [data-export]');
            if (!btn) return;
            e.preventDefault();

            const type = btn.dataset.export || 'movimientos';
            toast('info', 'Exportando', `Preparando ${type} para descarga...`, { duration: 2500 });

            // Feedback visual
            const original = btn.innerHTML;
            btn.innerHTML = '<span class="ltms-spinner" style="width:14px;height:14px;border-width:2px;margin-right:4px;"></span> Exportando...';
            btn.disabled = true;
            setTimeout(() => {
                btn.innerHTML = original;
                btn.disabled = false;
            }, 2000);
        });
    }

    // ═══════════════════════════════════════════════════════════
    // 15. REAL-TIME CLOCK en topbar (opcional)
    // ═══════════════════════════════════════════════════════════

    function initTopbarClock() {
        const clockEl = document.querySelector('.ltms-topbar-clock');
        if (!clockEl) return;

        function update() {
            const now = new Date();
            const time = now.toLocaleTimeString('es-CO', {
                hour: '2-digit',
                minute: '2-digit',
                hour12: true,
            });
            clockEl.textContent = time;
        }
        update();
        setInterval(update, 30000);
    }

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
    // INIT — Punto de entrada
    // ═══════════════════════════════════════════════════════════

    function init() {
        // Esperar DOM ready
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
            initKeyboardShortcuts();
            initPasswordStrength();
            initThemeToggle();
            initCopyButtons();
            initLazyImages();
            initAjaxErrorInterceptor();
            initNetworkStatus();
            initFormEnhancements();
            initSidebarOverlay();
            initTopbarClock();
            initBackToTop();
            initPasswordToggles();
            initUserDropdown();
            initRefreshButton();
            initNotificationsPanel();
            initBottomNav();
            initModalFocusTrap();
            initCommandPalette();
            initSkeletonLoaders();
            initOrdersFilterChips();
            initOrdersSearch();
            initExportButtons();
            initTour();
            initGlobalSearch();
            initMobileGestures();
            initBulkActions();
            initFileUploads();
            initPWAInstall();
            initErrorBoundaries();
            initPerfMonitor();
            initOnboardingWidget();
            initContextualHelp();
            initFormValidation();
            initSmartNotifications();
            initPreferences();
            initActivityFeed();
            initDashboardCustomization();
            initRealtimeUpdates();
            initAccessibilityEnhancements();
            initPerformanceOptimizations();
            initDataExport();
            initLightbox();
            initFormatters();
            initKeyboardHelp();
            initSystemStatus();
            initAdvancedTables();
            loadNotifSettings();
            initI18n();
            initCartDrawer();
            initVoiceSearch();
            initInfiniteScroll();
            initCookieConsent();
            initQuickView();
            initWishlist();
            initRecentlyViewed();
            initOrderTracking();
            initSocialShare();
            initQRCode();
            initCompare();
            initStarRatings();
            initPriceRanges();
            initImageZoom();
            initAccordion();
            initStockIndicators();
            initCountdowns();
            initProductCarousels();
            initQuantitySteppers();
            initCouponInputs();
            initToggleSwitches();
            initReadingProgress();
            initFilterSidebar();
            initMultiStepCheckout();
            initAddressAutocomplete();
            initReviewSystem();
            initOrderSuccess();
            initBackorderNotice();
            initStickyAddToCart();
            initProductTabs();
            initVariantSelector();
            initTrustBadges();
            initDeliveryDatePickers();
            initGiftWrapping();
            initAbandonedCartRecovery();
            initProductRecommendations();
            initSearchAutocomplete();
            initMultiCurrency();
            initLoyaltyPoints();
            initLiveChat();
            initSocialProof();
            initSizeGuide();
            initProductVideo();
            initRecentSearches();
            initPushNotifications();
            initReturnWizard();
            initInvoiceCenter();
            initMultiVendorCart();
            initEstimatedDelivery();
            initProductSubscription();
            initPickupSelector();
            initFlashSale();
            initWaitlist();
            initProductBundle();
            initDigitalDownloads();
            initVendorRating();
            initPriceMatch();
            initWhatsAppOrder();
            initOneClickReorder();
            initSaveCart();
            initShippingCalculator();
            initProductQA();
            initNewsletterSignup();

            // Re-inicializar password strength cuando se inyecte nuevo HTML (SPA)
            if (typeof jQuery !== 'undefined') {
                jQuery(document).on('ltms:view:loaded ltms:modal:open', () => {
                    initPasswordStrength();
                    initLazyImages();
                    initOrdersSearch();
                });
            }

            // Exponer ready flag
            LTMS.UX.ready = true;
            LTMS.UX.version = '2.0.0';

            // Notificar inicialización (silenciosamente)
            if (window.console && console.debug) {
                console.debug('[LTMS.UX] Inicializado v2.0.0');
            }
        } catch (err) {
            console.error('[LTMS.UX] Error inicializando:', err);
        }
    }

    // Auto-init
    init();

})();
