# UX Enhancements — LT Marketplace Suite v2.9.35

Capa overlay de mejoras de experiencia de usuario aplicada a **todas las interfaces** del marketplace.

## Archivos añadidos

| Archivo | Descripción |
|---------|-------------|
| `assets/css/ltms-ux-enhancements.css` | Capa CSS aditiva con design tokens modernos, animaciones, accesibilidad y dark mode |
| `assets/js/ltms-ux-enhancements.js` | Micro-interacciones, toasts, scroll reveal, atajos de teclado, password strength |

## Archivos modificados

| Archivo | Cambios |
|---------|---------|
| `includes/frontend/class-ltms-frontend-assets.php` | Añadido método `enqueue_ux_enhancements()` para cargar los assets globalmente |
| `includes/frontend/views/dashboard-wrapper.php` | Topbar modernizado con avatar, dropdown de usuario, theme toggle, ARIA, SVG icons |
| `includes/frontend/views/view-home.php` | Métricas con trend indicators, quick actions, layout en grid, sparklines |
| `includes/frontend/views/view-sellers-landing.php` | Trust indicators en hero, animaciones reveal, CTA sub-text, SVG icons |
| `includes/frontend/views/vendor-parts/form-login.php` | Password toggle con SVG, label-row, forgot link contextual, data-prevent-double |

## Interfaces mejoradas

### 1. Dashboard SPA (Panel de Vendedor)
- **Sidebar**: Gradient sutil, indicador activo con barra lateral, hover con desplazamiento, balance widget con glassmorphism
- **Topbar**: Glassmorphism con backdrop-filter, avatar con iniciales, dropdown de usuario, theme toggle, reloj en tiempo real
- **Métricas**: Cards con trend indicators (↑↓), iconos SVG, hover con elevación, gradient text en valores
- **Quick actions**: Layout en grid con iconos coloridos, hover con desplazamiento lateral
- **Tablas**: Header con uppercase, hover row, bordes suaves, status badges con dots
- **Empty states**: Iconos flotantes animados, copy más descriptivo

### 2. Login / Registro
- **Card**: Glassmorphism, animación de entrada, sombras profundas
- **Inputs**: Focus con glow, padding generoso, placeholders más amigables
- **Password toggle**: Iconos SVG (eye/eye-off) en lugar de emoji, swap visual al toggle
- **Password strength**: Medidor visual con 4 segmentos (weak/fair/good/strong) + label dinámico
- **Botón submit**: Gradient, sombra de color, estado loading con spinner
- **Forgot link**: Movido al lado del label de password (mejor contexto)
- **Footer**: Separador visual, link con transición de color

### 3. Landing de Vendedores
- **Hero**: Gradient moderno con glow animado, trust indicators (%, 0, 24/7), animaciones escalonadas
- **Botones**: Gradient con sombra de color, hover con elevación
- **Beneficios**: Cards con hover 3D, barra superior animada, iconos circulares con scale
- **Pasos**: Números con glow y dashed border rotando, sombras profundas
- **CTA final**: Gradient oscuro con glows radiales, sub-text con check icon

### 4. Storefront
- **Topbar**: Glassmorphism con backdrop-filter
- **Cart count**: Animación pulse al actualizarse
- **Product cards**: Hover con elevación, imagen con zoom suave, border radius grande
- **Quick add**: Botón flotante que aparece en hover

### 5. Checkout
- **Payment panel**: Animación fade-in al cambiar método
- **Place order btn**: Gradient púrpura, hover con elevación

### 6. Header Nav (Seller/Cliente)
- **Dropdown**: Animación de entrada con scale, sombras profundas
- **Tooltips**: Solo en desktop, hover suave
- **Mobile**: Labels siempre visibles, touch targets generosos

## Funcionalidades JS nuevas

### Toast System (`LTMS.UX.toast`)
Notificaciones modernas no bloqueantes con 4 tipos (success/error/warning/info), auto-dismiss, pausa en hover, acción opcional.

```js
LTMS.UX.toastSuccess('¡Éxito!', 'Producto agregado al carrito');
LTMS.UX.toastError('Error', 'No se pudo procesar el pago');
LTMS.UX.toastWarning('Atención', 'Tu cuenta vence pronto');
LTMS.UX.toastInfo('Info', 'Nueva función disponible');
```

### Keyboard Shortcuts
- `Alt + 1-9`: Navegar entre vistas del dashboard
- `Alt + H`: Ir a inicio
- `Alt + N`: Toggle panel de notificaciones
- `Alt + /`: Focus en búsqueda
- `Esc`: Cerrar modales/overlays

### Theme Toggle
Botón en topbar para alternar entre tema claro/oscuro. Persiste en `localStorage`.

### Password Strength Meter
Medidor visual de 4 segmentos con cálculo mejorado (longitud + complejidad - patrones comunes).

### Confirm Dialog
Reemplaza `confirm()` nativo con un modal moderno accesible:
```js
const ok = await LTMS.UX.confirm({
    title: '¿Eliminar producto?',
    message: 'Esta acción no se puede deshacer.',
    okLabel: 'Sí, eliminar',
    danger: true
});
```

### Scroll Reveal
Animaciones de entrada para elementos al hacer scroll (respeta `prefers-reduced-motion`).

### Copy to Clipboard
Botones con `data-copy="texto"` copian al portapapeles y muestran toast de confirmación.

### Network Status
Indicador automático de online/offline con toast persistente.

### Form Enhancements
- Auto-trim de inputs al blur
- Validación visual en tiempo real
- Prevención de doble submit (`data-prevent-double="true"`)

### Skip Link
Accesibilidad: link "Saltar al contenido" aparece al navegar con teclado.

### Back to Top
Botón flotante que aparece al hacer scroll > 400px.

## Accesibilidad

- **Focus visible** consistente en todos los interactivos
- **ARIA** labels, roles, states en topbar, sidebar, notificaciones, modales
- **prefers-reduced-motion**: todas las animaciones se respetan
- **prefers-color-scheme**: dark mode automático (también manual via toggle)
- **Skip link** para usuarios de teclado
- **Screen reader only** utility class
- **Keyboard navigation** completa en dropdowns y modales

## Design Tokens

Sistema unificado de variables CSS:
- **Colores**: primary/secondary/accent + estados semánticos + 10 neutros
- **Espaciado**: escala 4px (1-12)
- **Radios**: sm/default/lg/xl/full
- **Sombras**: xs/sm/default/md/lg/xl/glow
- **Tipografía**: font-sans + font-mono
- **Transiciones**: ease/ease-out/ease-spring + durations
- **Z-index**: escala 1000-1100

## Responsive

Breakpoints refinados:
- `1024px`: métricas en 2 columnas, home grid en 1 columna
- `768px`: sidebar colapsable, tablas con scroll horizontal, topbar compacto
- `480px`: auth card padding reducido, botones full width, trust indicators wrap

## Compatibilidad

- **Aditivo**: no rompe estilos existentes, solo sobreescribe lo necesario
- **Dependencias**: carga después de ltms-dashboard, ltms-frontend, ltms-login-register, ltms-header-nav
- **jQuery**: opcional, funciona con o sin jQuery
- **WordPress**: respeta `is_admin()`, no carga en admin
- **i18n**: strings localizables via `wp_localize_script`

---

## Fase 19 — Activación de módulos mediante `data-*`

Los 105+ módulos JS ahora se activan automáticamente mediante atributos `data-*` en las plantillas PHP. No requiere configuración adicional: al cargar la plantilla, el JS detecta los atributos y monta la funcionalidad.

### Resumen de activaciones por plantilla

| Plantilla | data-* añadidos | Módulos activados |
|-----------|----------------|-------------------|
| `dashboard-wrapper.php` | `data-tour-start` (topbar) | Tour guiado del panel |
| `view-home.php` | `data-tour-step` (×2), sección "Atajos y tips" | Tour contextual paso a paso |
| `view-products.php` | `data-lightbox`, `data-lightbox-group`, `data-quick-view`, `data-stock-level`, `data-stock-threshold`, `data-export-table` | Lightbox de imágenes, vista rápida, indicador de inventario, exportar CSV |
| `view-orders.php` | `data-search-autocomplete`, `data-export-table` | Autocompletar en búsqueda, exportar pedidos |
| `view-envios.php` | `data-export-table`, `id="ltms-envios-table"` | Exportar relaciones de envíos |
| `view-wallet.php` | `data-export-table`, `data-export-name`, `id="ltms-ledger-table"` | Exportar movimientos de billetera |
| `form-login.php` | `data-validate="required|email"`, `data-validate="required|minlength:6"`, `data-strength` | Validación en vivo + medidor de fuerza |
| `form-register.php` | `data-validate` en 7 campos (email, phone, doc#, store_name, address, password, confirm), `data-strength` | Validación local (email, phone CO/MX, password_strong) + medidor |
| `view-sellers-landing.php` | `data-share-buttons`, `data-accordion-group`, `data-accordion`, `data-accordion-trigger`, `data-accordion-content` | Botones de compartir (5 plataformas), FAQ acordeón |

### Cobertura de data-*

- **245 referencias** a `data-*` en el JS (selectores `querySelector`)
- **124 atributos** `data-*` distribuidos en las plantillas PHP (vs 0 antes de esta fase)
- **0 cambios destructivos** — todos los `onclick` y handlers existentes siguen funcionando

---

## Fase 19-bis — Migración de plantillas de email a `email-styles.php`

8 plantillas de email migradas para usar el sistema centralizado de estilos:

| Plantilla | Cambios |
|-----------|---------|
| `email-rnt-approved.php` | `alert-success` + `include email-styles.php` |
| `email-deposit-released.php` | `alert-success` con importe destacado |
| `email-booking-pending.php` | `alert-info` + `.ltms-email-table` (reemplaza tabla inline) |
| `email-booking-cancelled.php` | `alert-danger` + `alert-info`/`alert-warning` según reembolso |
| `email-booking-balance-reminder.php` | `alert-warning` + `.ltms-email-btn` (reemplaza botón inline) |
| `email-rnt-rejected.php` | `alert-warning` + `alert-info` con motivo + `.ltms-email-btn` |
| `email-rnt-expiry-warning.php` | `alert-warning` con días para vencer + `.ltms-email-btn` |
| `email-booking-checkin-reminder.php` | `alert-info` + `.ltms-email-table` |
| `email-booking-confirmed.php` | `alert-success` + `.ltms-email-table` con `.ltms-email-total` |

**Beneficios:**
- Paleta de colores consistente con el frontend (usando `$ltms_email_colors`)
- Clases reutilizables: `.ltms-email-alert-{success,warning,danger,info}`, `.ltms-email-table`, `.ltms-email-btn`, `.ltms-email-status-{success,warning,danger,info}`
- Responsive `@media (max-width:480px)` centralizado
- Inline styles disponibles vía `ltms_email_inline_style()` para clientes de email que no soportan `<style>`

---

## Próximos pasos recomendados

1. **Testing en staging**: probar cada módulo activado en un entorno de pre-producción
2. **Minificación**: ejecutar webpack/rollup para producir versiones `.min.js` y `.min.css`
3. **Lighthouse audit**: medir impacto en performance y accessibility scores
4. **A/B testing**: comparar conversión de registro antes/después de validación en vivo
5. **Migración de emails restantes**: las plantillas grandes (`email-welcome-vendor.php`, `email-kyc-approved.php`, `email-payout-approved.php`, `email-commission-credited.php`) mantienen su diseño bespoke pero podrían alinear colores con la paleta centralizada

---

## v2.9.35 — CSS Fixes & Toast Behavior

### Correcciones de CSS en la página de producto

| Issue | Causa | Fix |
|-------|-------|-----|
| Botón "Añadir al carrito" deformado | Falta `min-width` + `padding` heredado de WC | `.single_add_to_cart_button { min-width: 180px; padding: 12px 24px; }` |
| Campo de cantidad demasiado pequeño | `input.qty` sin `width` definido | `input.qty { width: 60px; min-height: 40px; text-align: center; }` |
| Items de upsell renderizados como botones gigantes | `.upsells .button` con `width: 100%` | `.upsells .button { width: auto; display: inline-block; }` |
| Padding desigual entre productos relacionados y upsells | Margin collapse entre secciones | Regla normalizadora `.related.products, .upsells.products { padding-top: 2rem; }` |

### Error toasts deshabilitados

Los toasts genéricos de error "Algo salió mal" que aparecían en cada AJAX fallido han sido **deshabilitados globalmente** para reducir ruido visual en producción:

```js
// assets/js/ltms-ux-enhancements.js
window.LTMS_CONFIG = window.LTMS_CONFIG || {};
window.LTMS_CONFIG.SHOW_ERROR_TOASTS = false;       // toasts de error genéricos
window.LTMS_CONFIG.SHOW_AJAX_ERROR_TOASTS = false;  // toasts de error AJAX
```

**Rationale:**
- Cada error AJAX ya queda registrado en `lt_security_events` y en el log PHP.
- El toast genérico era confuso para el usuario final (no le daba información actionable).
- Los errores de validación (formularios) siguen mostrando feedback visual inline.
- Los errores críticos (pago fallido, KYC rechazado) muestran mensajes contextizados vía el handler específico, no vía el toast genérico.

**Para re-habilitar en debugging:**
```js
// En la consola del navegador o vía wp_localize_script
LTMS_CONFIG.SHOW_ERROR_TOASTS = true;
LTMS_CONFIG.SHOW_AJAX_ERROR_TOASTS = true;
```

### Otros fixes JS de v2.9.35

- **`e.target.closest is not a function`**: cuando el usuario hace click sobre un text node (no un Element), `e.target` no tiene método `closest`. Se añadió guard:
  ```js
  function handleClick(e) {
      const target = e.target.nodeType === 1 ? e.target : e.target.parentElement;
      if (!target) return;
      const btn = target.closest('[data-action]');
      // ...
  }
  ```
- **Nonce action corregido**: los handlers AJAX del storefront usaban `check_ajax_referer('ltms_storefront_nonce', 'nonce')` (nombre inexistente). Corregido a `ltms_ux_nonce` en todos los handlers. Esto resolvía la falsa impresión de "AJAX no funciona" en el storefront.
- **`.min.js` / `.min.css` sincronizados**: las versiones minificadas ahora se regeneran en cada commit que toca las fuentes. Fueron removidas de `.gitignore` para que siempre lleguen a producción.

### Nuevas vistas del dashboard (v2.9.35)

4 nuevas plantillas PHP añadidas al directorio `includes/frontend/views/`:

| Archivo | Propósito | data-* principales |
|---------|-----------|--------------------|
| `view-marketing.php` | Gestión de banners promocionales | `data-banner-list`, `data-banner-upload`, `data-banner-toggle` |
| `view-security.php` | TOTP 2FA + códigos de recuperación | `data-totp-enroll`, `data-totp-verify`, `data-totp-disable`, `data-recovery-codes` |
| `view-donations.php` | Transparencia de donaciones | `data-donation-chart`, `data-donation-list` |
| `view-posgold.php` | Sincronización de catálogo PosGold | `data-posgold-sync`, `data-posgold-status`, `data-posgold-mapping` |

Cada vista se carga vía el SPA del dashboard (jQuery `LTMS.Dashboard.loadView('marketing')` etc.) con su endpoint AJAX correspondiente.
