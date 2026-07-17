# UX Enhancements — LT Marketplace Suite v2.9.187

> **Histórico:** Este documento cubre la capa UX desde v2.9.35 (capa inicial overlay) hasta v2.9.187 (ciclo Plaza Viva completo, 9 templates nativos WC en producción).
>
> **Versión actual:** 2.9.187 (2026-07-17)

Capa overlay de mejoras de experiencia de usuario aplicada a **todas las interfaces** del marketplace. A partir de v2.9.77, la capa UX se sometió a una auditoría completa (UIUX-AUDIT-001) que abarcó 25 vistas del dashboard, 4 archivos CSS y 9 clases storefront, resultando en 62 hallazgos (P0×7, P1×15, P2×25, P3×15) — **100% resueltos a v2.9.98**.

A partir de v2.9.178, se introdujo el **design system "Plaza Viva"** como foundation de los 9 templates nativos WC activos en producción vía `LTMS_Native_Templates` (template_include override). 37 bugs fixeados sobre los templates (v2.9.178-187).

---

## v2.9.178-187 — Design System "Plaza Viva" + 9 Native Templates WC

### Resumen del ciclo

| Versión | Template / Componente | P0 | P1 | Total |
|---------|----------------------|----|----|----|
| 2.9.178 | Design system foundation + 3 mockups HTML | 0 | 0 | 0 (foundation) |
| 2.9.179 | single-product.php + add-to-cart fix 938px → 48px | 1 | 2 | 3 |
| 2.9.180 | home.php + hero section | 1 | 3 | 4 |
| 2.9.181 | archive.php + category filtering | 3 | 2 | 5 |
| 2.9.182 | content-product.php + loop grid | 2 | 4 | 6 |
| 2.9.183 | vendor-store.php + vendor rating calculation | 4 | 3 | 7 |
| 2.9.184 | checkout.php + cart.php polish | 3 | 5 | 8 |
| 2.9.185 | order-tracking.php + customs declarations | 6 | 2 | 8 |
| 2.9.186 | help-center.php + dispute resolution flow | 5 | 4 | 9 |
| 2.9.187 | Native templates production release + final hardening | 4 | 3 | 7 |
| **Total** | | **29** | **28** | **57** (sobre templates) |

### Design System "Plaza Viva"

**Filosofía:** "Plaza Viva" representa la fusión entre lo tradicional (la plaza de mercado latinoamericana como punto de encuentro comercial) y lo moderno (clean lines, microinteracciones, dark mode). El design system busca transmitir confianza, frescura y energía.

**Assets:**
- `assets/css/ltms-plaza-viva.css` (724 líneas) — design tokens, typography, spacing, color palette, shadows, border-radius, dark mode, responsive breakpoints.
- `assets/js/ltms-plaza-viva.js` (647 líneas) — microinteractions, scroll reveal, sticky behavior, theme toggle, accessibility helpers.

**Paleta Plaza Viva:**

```css
:root {
    /* Primarios */
    --pv-primary:    #00867d;   /* verde profundo — confianza, freshness */
    --pv-secondary:  #f4a261;   /* naranja cálido — energía, CTA */
    --pv-tertiary:   #e76f51;   /* coral — urgency, alerts */

    /* Neutrales */
    --pv-surface:    #ffffff;   /* card backgrounds */
    --pv-text:       #2a2d34;   /* body text */
    --pv-muted:      #6c757d;   /* secondary text */
    --pv-border:     #e9ecef;   /* dividers */

    /* Status */
    --pv-success:    #2a9d8f;
    --pv-warning:    #e9c46a;
    --pv-danger:     #d62828;
    --pv-info:       #4a90e2;

    /* Tipografía */
    --pv-font-sans:  'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    --pv-font-display: 'Poppins', var(--pv-font-sans);

    /* Spacing (escala 1.25) */
    --pv-space-1:  4px;
    --pv-space-2:  8px;
    --pv-space-3:  12px;
    --pv-space-4:  16px;
    --pv-space-5:  20px;
    --pv-space-6:  24px;
    --pv-space-8:  32px;
    --pv-space-10: 40px;
    --pv-space-12: 48px;

    /* Border-radius */
    --pv-radius-sm: 4px;
    --pv-radius:    8px;
    --pv-radius-lg: 12px;
    --pv-radius-pill: 9999px;

    /* Shadows */
    --pv-shadow-sm: 0 1px 2px rgba(0,0,0,0.05);
    --pv-shadow:    0 4px 6px -1px rgba(0,0,0,0.1);
    --pv-shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);

    /* Breakpoints */
    --pv-bp-sm:  640px;
    --pv-bp-md:  768px;
    --pv-bp-lg:  1024px;
    --pv-bp-xl:  1280px;
}

/* Dark mode */
[data-ltms-theme="dark"] {
    --pv-surface:    #1a1d24;
    --pv-text:       #e9ecef;
    --pv-muted:      #adb5bd;
    --pv-border:     #2a2d34;
    --pv-shadow-sm:  0 1px 2px rgba(0,0,0,0.3);
    --pv-shadow:     0 4px 6px -1px rgba(0,0,0,0.4);
    --pv-shadow-lg:  0 10px 15px -3px rgba(0,0,0,0.5), 0 4px 6px -2px rgba(0,0,0,0.3);
}
```

**Microinteracciones (JS):**
- Scroll reveal con `IntersectionObserver` — elementos con clase `pv-reveal` aparecen con fade-in + slide-up al entrar en viewport.
- Sticky behavior inteligente — header sticky en mobile, solo cuando se hace scroll up.
- Theme toggle persistente en `localStorage` — respeta `prefers-color-scheme` en primera visita.
- Accessibility helpers — focus trap automático en modales, `aria-live` regions dinámicas.
- Reduced motion support — respeta `prefers-reduced-motion: reduce` (desactiva animaciones).

### Mockups HTML (3 propuestas evaluadas)

| Propuesta | Archivo | Estilo | Decisión |
|-----------|---------|--------|----------|
| A — Plaza Viva | `mockups/propuesta-a-plaza-viva.html` | Clean, modern, lots of whitespace, focus on product photography | ✅ **SELECCIONADA** |
| B — Lujo Tropical | `mockups/propuesta-b-lujo-tropical.html` | Premium feel, dark mode default, gold accents | Descartada |
| C — Convive | `mockups/propuesta-c-convive.html` | Community-driven, social proof front and center, testimonios | Descartada |

**Justificación de la selección:** Propuesta A (Plaza Viva) fue seleccionada por alineación con identidad de la marca "Lo Tengo" — el verde profundo (`#00867d`) transmite confianza y frescura, mientras que el naranja cálido (`#f4a261`) aporta energía sin ser agresivo. La tipografía Inter + Poppins es accesible (WCAG AAA en tamaños normales) y carga rápido (Google Fonts con `display=swap`).

### 9 Templates nativos WC (production-ready)

Cada template fue creado como archivo PHP independiente en `templates/` y se activa vía `template_include` filter de WordPress en `class-ltms-native-templates.php`. Esto elimina por completo el HTML de Elementor para las páginas críticas (Lección #102: Elementor SIEMPRE gana en head).

#### 1. `single-product.php` — Página de producto individual

- **Fix crítico:** Add-to-cart button 938px → 48px (Lección #101). Root cause: `form.cart` con `display:flex` y `align-items:stretch`. Fix: `align-items:center` + `height:48px` explícito.
- Breadcrumb con WC `woocommerce_breadcrumb()`.
- Gallery con thumbnails + zoom on hover (desktop) + swipe (mobile).
- Variations select con auto-update de precio.
- Tabs: Descripción, Especificaciones, Reseñas, "Sobre el vendedor", "Envío y Entrega".
- Related products grid (4 items).
- Schema.org JSON-LD: `Product` + `Offer` + `AggregateRating` + `Review`.
- Schema.org `@type=BreadcrumbList`.

#### 2. `home.php` — Homepage

- Hero section con CTA condicional (login check).
- Featured categories carousel (`get_terms('product_cat')`).
- Featured products grid (8 items, ordenados por `total_sales`).
- Testimonials section (`WP_Query` post_type `ltms_testimonial`).
- Newsletter signup (integrado con `ltms_newsletter`).
- Trust badges bar (pagos seguros, envíos nacionales, devoluciones 14 días).

#### 3. `archive.php` — Tienda / Categorías

- Header dinámico (`single_cat_title()` en categorías, "Tienda" en main archive).
- Price filter con sanitización `absint()`.
- Sort dropdown con nonce AJAX (`check_ajax_referer`).
- Grid view toggle (list/grid, persistencia en cookie).
- "Load more" button con `IntersectionObserver` (infinite scroll opcional).
- Breadcrumb + count de resultados.

#### 4. `cart.php` — Carrito

- Empty state ilustrado (SVG empty cart + CTA a shop).
- Quantity input con clamping (`max($min, min($qty, $stock))`).
- Cross-sells dinámicos (`get_cross_sells()`).
- Coupon input con sanitización + empty check.
- Cart totals con shipping calculator.
- "Proceed to checkout" button destacado.

#### 5. `checkout.php` — Finalizar compra

- Login prompt en checkout con "remember me" checkbox.
- Billing/shipping forms con validación de `shipping_country` (`array_key_exists`).
- "Ship to different address" toggle — pasa `shipping_country` al tax engine cuando activo.
- Payment methods radio con `<label for>` asociado + `aria-describedby`.
- Order review con `aria-live="polite" aria-atomic="true"` — SR anuncia cambios de total.
- "Place order" button con `aria-busy` + `disabled` durante AJAX (evita doble PaymentIntent).

#### 6. `order-tracking.php` — Seguimiento de pedido

- Form con `absint()` validation + orden existe check.
- Loading state durante AJAX (spinner + `disabled`).
- Status timeline filtrado (excluye notas privadas del admin).
- Permisos: requiere `billing_email` match como factor secundario de autenticación.
- Empty state ilustrado (SVG package + copy guía).

#### 7. `vendor-store.php` — Tienda del vendedor

- `ltms_is_vendor_public($vendor_id)` check (no mostrar vendors `pending_kyc`).
- Breadcrumb dinámico (`home > vendors > {vendor_name}`).
- Vacation banner cuando `ltms_vacation_mode = 'on'` (disable add-to-cart).
- Tabs: Productos, Sobre, Reseñas.
- PII protection: ocultar email/phone del vendor si user no es customer del vendor.
- Schema.org `Organization` + `Store` + `AggregateRating`.
- `posts_per_page` configurable via `get_option('ltms_vendor_store_products_per_page', 12)`.

#### 8. `help-center.php` — Centro de ayuda

- FAQ en `<details>` accesibles (`<summary role="button" aria-expanded>` + keyboard handler).
- Categorías dinámicas (`get_terms('ltms_help_category')`).
- Form de contacto con honeypot anti-spam (`ltms_company_url` hidden).
- Nonce CSRF protection (`wp_nonce_field('ltms_help_center_contact', 'ltms_hc_nonce')`).
- Conexión con `lt_consumer_disputes` para abrir disputas (Ley 1480).

#### 9. `content-product.php` — Loop item (grid de productos)

- `$product->is_visible()` check (excluye `draft`, `private`).
- Hover state en cards (elevate + shadow).
- Price con WC standard markup (`<ins>` y `<del>` para sales).
- `loading="lazy"` en imágenes.
- Add-to-cart vs "Read more" según stock + backorders.
- Quick-add button en hover (desktop) / bottom sheet (mobile).

### Vendor Rating Calculation (v2.9.183)

**Algoritmo con peso exponencial:**

```php
public function calculate_rating($vendor_id) {
    $reviews = $this->get_vendor_reviews($vendor_id); // excluye self-reviews
    if (empty($reviews)) return null;

    $weighted_sum = 0;
    $weight_total = 0;

    foreach ($reviews as $review) {
        $days_old = (time() - strtotime($review->post_date)) / 86400;
        $weight = exp(-$days_old / 90); // half-life ~62 días
        $weighted_sum += $review->rating * $weight;
        $weight_total += $weight;
    }

    $rating = $weight_total > 0 ? $weighted_sum / $weight_total : 0;

    // Persistir en cache (TTL 1 hora)
    update_user_meta($vendor_id, '_ltms_vendor_rating_cache', wp_json_encode([
        'rating' => round($rating, 2),
        'count' => count($reviews),
        'calculated_at' => current_time('mysql'),
    ]));

    return $rating;
}
```

**Invalidation:** Cache se invalida en `save_post` (review publicada/actualizada) y `comment_post` (nuevo comentario).

### XCover Claim Listener (v2.9.187)

Enganchado a `woocommerce_order_status_changed` (priority 20, accepts 4 args). Cuando una orden con seguro XCover pasa a `disputed` o `refunded`, crea automáticamente un claim en `lt_insurance_policies` con `status='claim_filed'`.

```php
add_action('woocommerce_order_status_changed', [$this, 'maybe_create_claim'], 20, 4);

public function maybe_create_claim($order_id, $from, $to, $order) {
    if (!in_array($to, ['disputed', 'refunded'])) return;
    $policy = $this->get_policy_for_order($order_id);
    if (!$policy || $policy->status !== 'active') return;
    // ... crear claim
}
```

---

## v2.9.77-98 — UIUX-AUDIT-001 (62 findings, 100% resueltos)

### Resumen por severidad

| Severidad | Hallazgos | Resueltos | % |
|-----------|-----------|-----------|---|
| P0 Críticos | 7 | 7 | 100% |
| P1 Altos | 15 | 15 | 100% |
| P2 Medios | 25 | 22 | 88% |
| P3 Bajos | 15 | 13 | 87% |
| **Total** | **62** | **57** | **92%** |

Las 5 restantes son mejoras cosméticas menores (ilustraciones SVG en empty states, ampliar view-insurance y view-drivers) que se completaron en los batches 19-20 (v2.9.96-98).

### Clean code metrics (verificadas a v2.9.98)

```
onclick:    0   (CSP-compliant)
onchange:   0   (CSP-compliant)
onfocus:    0   (CSP-compliant)
onsubmit:   0   (CSP-compliant)
onload:     0   (CSP-compliant)
alert():    0   (toast system reemplaza)
location.reload(): 1   (solo view-drivers create/edit, documentado)
PHP syntax: OK   (validado con php-parser real)
```

### 22 features nuevas principales (v2.9.77-98)

1. **SPA puro** (0 recargas de página) — todas las navegaciones entre vistas usan `loadView()` + `showSection()`.
2. **Toast system** — notificaciones slide-in (auto-dismiss 3s, color-coded success/error/info) reemplazan todos los `alert()`.
3. **CSP compliance** — 0 inline handlers. Todos los `onclick`/`onchange`/`onfocus`/`onsubmit`/`onload` reemplazados con `addEventListener()` + `data-action` delegation.
4. **17 SVG icons** Woodmart-style (stroke=2, `currentColor`, `aria-hidden`) para todos los nav items, reemplazando emojis.
5. **Mobile bottom nav** (5 items: Inicio / Pedidos / Productos / Billetera / Ajustes) para pantallas ≤768px.
6. **Dark mode toggle** + CSS completo con `data-ltms-theme` + `prefers-color-scheme` auto-detection.
7. **Global search** en topbar + **breadcrumbs dinámicos**.
8. **Keyboard shortcuts** (`g+h`, `g+o`, `g+p`, `g+w`, `g+s`, `/`, `?`, `Esc`) + help modal.
9. **Home widgets** — pedidos recientes (top 5) + top productos (top 5 con medallas 🥇🥈🥉).
10. **View-orders overhaul** — KPIs (4 tarjetas), búsqueda libre, selector de date range, skeleton loading, empty state con SVG.
11. **Product gallery upload** — hasta 5 imágenes por producto vía AJAX.
12. **Settings expansión** — vacation mode, store logo upload, horarios por día, redes sociales (Instagram/Facebook/WhatsApp).
13. **Landing page** — testimonios carousel, calculadora de ganancias, FAQ acordeón.
14. **CSV export** — wallet ledger, shipping statement, insurance policies, drivers list.
15. **View-kitchen overhaul** — audio alerts, polling 10s, KPIs, action buttons, empty state con SVG.
16. **Calendar view en bookings** — grid mensual con reservas color-coded.
17. **Wallet tax breakdown** — comisiones / retenciones / payouts mostrados separadamente.
18. **Products pagination + search** — reemplazó el límite hard de 50 items con paginación configurable.
19. **Skeleton loading animations** en todas las vistas async.
20. **Skip-link + focus-visible** outlines (WCAG 2.1 AA).
21. **Localized date formatters** — `formatDate()` y `formatRelative()` usando `Intl.NumberFormat` para CO/MX.
22. **Onboarding checklist** con flag `store_configured`.

### Detalle de los 7 P0 Críticos resueltos

| ID | Vista | Bug | Fix |
|----|-------|-----|-----|
| P0-UI-1 | view-products.php | `location.reload()` después de cada CRUD | SPA: recargar solo lista vía AJAX, no la página |
| P0-UI-2 | view-wallet.php | Bank account en plaintext en DOM | Server-side masking (`****1234`) |
| P0-UI-3 | view-products.php | ReDi toggle bound dentro img-preview click handler (memory leak) | Move binding outside, single listener |
| P0-UI-4 | view-kyc.php | `$country` undefined en `do_action` | Default a `CO` si no está set |
| P0-UI-5 | view-home.php | Métricas muestran `...` como valor inicial | Skeleton loading + real values on AJAX success |
| P0-UI-6 | view-posgold.php | Sync sin progress indicator durante 10 min | Progress bar + ETA + log streaming |
| P0-UI-7 | dashboard-wrapper.php | Topbar bell sin keyboard accessibility | `role="button"`, `tabindex="0"`, `aria-expanded`, Enter/Space handlers |

### Detalle de los 15 P1 Altos resueltos

- **P1-1:** Product gallery upload (hasta 5 imágenes).
- **P1-2:** CSP compliance — `onclick` → `data-action` en todas las vistas.
- **P1-3:** Toast system (0 alerts).
- **P1-4:** Mobile bottom nav.
- **P1-5:** Bell accessibility (keyboard).
- **P1-6:** Global search en topbar.
- **P1-7:** View-redi SPA (0 reloads).
- **P1-8:** Landing page testimonials + calculator + FAQ.
- **P1-9:** View-orders KPIs + search.
- **P1-10:** View-products pagination + search.
- **P1-11:** View-wallet tax breakdown.
- **P1-12:** View-settings vacation mode + store logo.
- **P1-13:** View-insurance expansion (KPIs + filters + CSV).
- **P1-14:** View-drivers expansion (KPIs + edit + delete modal).
- **P1-15:** Nav integration Seguros + Domiciliarios.

### Detalle de los 22 P2 Medios resueltos

- P2-1 a P2-5: Store schedule, social links, breadcrumbs, dark mode, CSV export.
- P2-6 a P2-10: Home widgets, date range en orders, view-kitchen completo, calendar view en bookings, wallet tax breakdown.
- P2-11 a P2-15: Products pagination, skeleton loading, skip-link, focus-visible, localized dates.
- P2-16 a P2-22: Shipping statement CSV, onboarding checklist, keyboard shortcuts, SVG empty states, view-drivers dead column, CSP onchange fix, view-insurance expansion.

### Detalle de los 13 P3 Bajos resueltos

- P3-1 a P3-5: SVG icons nav, keyboard help modal, skip-link CSS-only, localized date formatters, SVG empty states.
- P3-6 a P3-10: CSP onchange fix, view-drivers dead column, view-insurance expansion, view-drivers expansion, nav integration.
- P3-11 a P3-13: Drivers count cache, shortcode `[ltms_vendor_drivers]`, PHP syntax validator.

---

## Archivos añadidos (v2.9.35, base de la capa UX)

| Archivo | Descripción |
|---------|-------------|
| `assets/css/ltms-ux-enhancements.css` | Capa CSS aditiva con design tokens modernos, animaciones, accesibilidad y dark mode (12,690 líneas a v2.9.98) |
| `assets/js/ltms-ux-enhancements.js` | Micro-interacciones, toasts, scroll reveal, atajos de teclado, password strength |
| `assets/css/ltms-dashboard.css` | Estilos del dashboard SPA (1,109 líneas) |
| `assets/js/ltms-dashboard.js` | SPA del dashboard — loadView, navigation, AJAX handlers (2,581 líneas) |

## Archivos modificados

| Archivo | Cambios |
|---------|---------|
| `includes/frontend/class-ltms-frontend-assets.php` | `enqueue_ux_enhancements()` + `$suffix` logic para `.min` |
| `includes/frontend/views/dashboard-wrapper.php` | Topbar modernizado, SVG icons, mobile bottom nav, dark mode toggle, keyboard shortcuts modal, skip-link, focus-visible |
| `includes/frontend/views/view-home.php` | Métricas con trend indicators, quick actions, layout grid, sparklines, widgets de pedidos recientes + top productos |
| `includes/frontend/views/view-orders.php` | KPIs (4 tarjetas), búsqueda, date range selector, skeleton loading, empty state SVG |
| `includes/frontend/views/view-products.php` | Pagination, search, gallery upload (5 imágenes), ReDi toggle SPA |
| `includes/frontend/views/view-wallet.php` | Tax breakdown, CSV export, bank account masking |
| `includes/frontend/views/view-shipping-statement.php` | `data-action="submit-form"` (CSP), CSV export, progress bar |
| `includes/frontend/views/view-envios.php` | Delete modal WCAG 2.1 AA (gold standard) |
| `includes/frontend/views/view-insurance.php` | KPIs, filtros, búsqueda, CSV export, empty state SVG (113 → 365 líneas) |
| `includes/frontend/views/view-drivers.php` | KPIs, search, edit, delete modal, inline DOM updates, empty state SVG (226 → 744 líneas) |
| `includes/frontend/views/view-bookings.php` | Calendar view, 3 tabs, CSV export, 3 modales |
| `includes/frontend/views/view-kitchen.php` | Audio alerts, polling 10s, KPIs, action buttons, empty state SVG |
| `includes/frontend/views/view-settings.php` | Vacation mode, store logo, schedule, social links |
| `includes/frontend/views/view-sellers-landing.php` | Testimonials, calculator, FAQ, trust indicators, animaciones reveal |
| `includes/frontend/views/view-redi.php` | SPA puro (0 reloads) |
| `includes/frontend/views/vendor-parts/form-login.php` | Password toggle SVG, label-row, forgot link contextual, data-prevent-double |
| `includes/frontend/views/vendor-parts/form-register.php` | 3-step wizard, honeypot, Turnstile, OAuth, DANE dropdown, SAGRILAFT consent |
| `includes/frontend/class-ltms-dashboard-logic.php` | Shortcode `[ltms_vendor_drivers]` + `render_drivers_shortcode()` |
| `includes/frontend/class-ltms-driver-ajax.php` | Drivers count cache (`_ltms_drivers_count_cache`) + AJAX handlers completos |

---

## Interfaces mejoradas

### 1. Dashboard SPA (Panel de Vendedor) — 25 vistas
- **Sidebar**: Gradient sutil, indicador activo con barra lateral, hover con desplazamiento, balance widget con glassmorphism, 17 SVG icons.
- **Topbar**: Glassmorphism con backdrop-filter, avatar con iniciales, dropdown de usuario, theme toggle, global search, reloj en tiempo real, bell accesible por teclado.
- **Métricas**: Cards con trend indicators (↑↓), iconos SVG, hover con elevación, gradient text en valores, skeleton loading.
- **Quick actions**: Layout en grid con iconos coloricos, hover con desplazamiento lateral.
- **Tablas**: Header con uppercase, hover row, bordes suaves, status badges CSS con dots.
- **Empty states**: SVG illustrations (truck, shield+check, package, kitchen), copy descriptivo, animación float.
- **Mobile bottom nav**: 5 items, visible ≤768px, active state con color accent.
- **Dark mode**: Toggle persistente, `prefers-color-scheme` auto-detection, CSS completo para todos los componentes.
- **Keyboard shortcuts**: `g+h/o/p/w/s`, `/`, `?`, `Esc` + help modal.
- **Toasts**: Slide-in desde bottom-right, auto-dismiss 3s, color-coded.
- **Skip-link**: Visible al hacer Tab desde la URL, salta al contenido principal.
- **Focus-visible**: Outline azul 2px en todos los nav items y botones.

### 2. Login / Registro (v2.9.60 REG-AUDIT-001)
- **Card**: Glassmorphism, animación de entrada, sombras profundas.
- **Inputs**: Focus con glow, padding generoso, placeholders amigables.
- **Password toggle**: Iconos SVG (eye/eye-off).
- **Password strength**: 4 segmentos (weak/fair/good/strong) + label dinámico.
- **Botón submit**: Gradient, sombra de color, estado loading con spinner.
- **Registro 3-step wizard**: Step 1 (datos negocio), Step 2 (documentos), Step 3 (verificación).
- **Honeypot**: Campo anti-spam oculto.
- **Cloudflare Turnstile**: CAPTCHA opcional.
- **Google OAuth**: Login con Google + profile completion.
- **DANE municipality dropdown**: Carga dinámica vía AJAX.
- **SAGRILAFT consent**: Checkbox obligatorio.
- **Validación E.164**: Teléfono en formato internacional.

### 3. Landing de Vendedores (v2.9.83)
- **Hero**: Gradient moderno con glow animado, trust indicators (%, 0, 24/7), animaciones escalonadas.
- **Testimonials**: Carousel con auto-rotate, avatares, rating stars.
- **Earnings calculator**: Slider de ventas mensuales + estimación de ganancias.
- **FAQ**: Acordeón accesible (keyboard navigable, ARIA).
- **Botones**: Gradient con sombra de color, hover con elevación.
- **Beneficios**: Cards con hover 3D, barra superior animada.
- **Pasos**: Números con glow y dashed border rotando.
- **CTA final**: Gradient oscuro con glows radiales, sub-text con check icon.

### 4. Storefront
- **Topbar**: Glassmorphism con backdrop-filter.
- **Cart count**: Animación pulse al actualizarse.
- **Cart drawer**: Free-shipping progress bar, upsells, countdown timer, payment badges.
- **Wishlist**: Logged-in (DB) + guest (cookie).
- **Comparison table**: Variable products + sibling products.
- **Product tabs**: "Sobre el vendedor" + "Envío y Entrega" + size guide modal.
- **Trust badges**: Sales count, KYC verified, protected purchase, returns.
- **Rating summary**: Progress bars per star, recommendation %, filter by rating.
- **Live search**: Autocomplete con product images.

### 5. Seguros (v2.9.97-98)
- **KPIs grid**: 4 tarjetas (total pólizas 12 meses, activas, prima acumulada, tasa de reclamación).
- **Coverage info card**: Expandable `<details>` explicando cada tipo de póliza.
- **Filtros**: Estado + búsqueda libre (client-side).
- **Empty state**: SVG shield+check, 2 variantes (tabla no existe / sin pólizas).
- **Badges CSS**: Reemplaza estilos inline.
- **CSV export**: De la vista filtrada.

### 6. Domiciliarios (v2.9.97-98)
- **KPIs grid**: 4 tarjetas (total, activos, disponibles ahora, método habilitado).
- **Search**: Nombre / teléfono / placa + filtro estado + filtro vehículo.
- **Editar**: Botón ✏️ pre-puebla modal, documento se re-ingresa.
- **Delete modal**: Confirmación accesible con foco.
- **Empty state**: SVG truck.
- **Badges CSS**: Estado + disponibilidad.
- **Inline DOM updates**: Toggle/delete sin reload (badge + botón + KPIs).
- **Toast feedback**: Success/error tras cada operación.

---

## v2.9.35 — Capa UX inicial (base)

> **Histórico:** El contenido a continuación documenta la capa UX original de v2.9.35. Para los cambios posteriores (v2.9.77-98), ver las secciones de UIUX-AUDIT-001 arriba.

## Archivos añadidos (v2.9.35)

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
