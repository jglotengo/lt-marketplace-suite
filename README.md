# LT Marketplace Suite

> Enterprise multi-vendor marketplace for WooCommerce — Colombia & Mexico

**Version:** 2.9.102 | **PHP:** 8.1+ | **WC:** 8.0+ | **WP:** 6.3+

[![License: Proprietary](https://img.shields.io/badge/License-Proprietary-red.svg)](https://ltmarketplace.co/eula)
[![Version](https://img.shields.io/badge/version-2.9.102-blue.svg)]()
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)]()
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0%2B-purple.svg)]()
[![CI](https://img.shields.io/badge/CI-GitHub%20Actions-green.svg)]()
[![Countries](https://img.shields.io/badge/Coverage-CO%20%7C%20MX-success.svg)]()

---

## Overview

LT Marketplace Suite (LTMS) transforms WooCommerce into a full-featured enterprise marketplace for Colombia and Mexico. The plugin covers marketplace operations, MLM referral networks, fintech (wallets/payouts), insurtech (XCover policies), logistics (ReDi, Aveonline, own-delivery), and full fiscal compliance (DIAN/SAGRILAFT + SAT/CFDI 4.0).

- **Multi-vendor management** with a SPA dashboard (no page reloads)
- **ACID-compliant wallet ledger** with hold/release mechanics, tax breakdowns, and CSV export
- **Country-specific tax compliance** — Colombia (DIAN/SAGRILAFT) and Mexico (SAT/CFDI 4.0)
- **MLM referral network** with 3-level commission distribution
- **KYC identity verification** with country-aware document uploads and IDOR-protected endpoints
- **PWA vendor dashboard** with push notifications, dark mode, mobile bottom-nav, keyboard shortcuts
- **PosGold catalog sync** with 8-component price calculator
- **TOTP 2FA** (RFC 6238) with backup codes and admin-enforced policy
- **XCover insurance** policy lifecycle (create / cancel / claim / certificate download)
- **Own-delivery fleet** management (CRUD drivers, availability toggle, ETA + zones)
- **ReDi reverse-logistics** integration with pause/resume and incident tracking
- **Kitchen Display System** (KDS) for restaurants with audio alerts and polling
- **Bookings** module with calendar grid view, seasons, and policies
- **Cloudflare Turnstile** CAPTCHA on registration and 2FA flows
- **CSP-compliant frontend** — 0 inline handlers (onclick/onchange/onfocus/onsubmit), 0 alerts, 0 unnecessary reloads

---

## v2.9.98 Highlights (2026-07-08)

### New UI/UX — UIUX-AUDIT-001 (62 findings, 100% resolved)

A complete front-end audit was performed across 25 dashboard views and 4 CSS files. All 7 P0 critical bugs, 15 P1 high bugs, 22/25 P2 medium issues, and 13/15 P3 low issues were resolved:

- **Pure SPA navigation** — all view switches happen without page reloads (eliminated `location.reload()` everywhere except create/edit flows that need fresh server-rendered HTML).
- **Toast notification system** — replaced every `alert()` with a slide-in toast (auto-dismiss after 3s, color-coded: success/error/info).
- **CSP compliance** — every inline `onclick`/`onchange`/`onfocus`/`onsubmit` replaced with `addEventListener` + `data-action` delegation. The plugin is ready for strict Content-Security-Policy headers.
- **17 SVG icons** (Woodmart-style, stroke=2, `currentColor`) for all nav items, replacing emoji.
- **Mobile bottom navigation** (5 items: Inicio / Pedidos / Productos / Billetera / Ajustes) for screens ≤768px.
- **Dark mode** toggle with `data-ltms-theme` + `prefers-color-scheme` auto-detection.
- **Global search** in the topbar + dynamic breadcrumbs.
- **Keyboard shortcuts** (`g+h`, `g+o`, `g+p`, `g+w`, `g+s`, `/`, `?`, `Esc`) with a help modal.
- **Home widgets** — recent orders (top 5) + top products (top 5 with medal icons).
- **Orders view overhaul** — KPIs, free-text search, date-range selector, skeleton loading, empty state with SVG.
- **Products pagination + search** — replaced the 50-item hard limit with configurable pagination.
- **Product gallery upload** — up to 5 images per product via AJAX.
- **Settings expansion** — vacation mode, store logo upload, store schedule (per-day open/close), social links (Instagram/Facebook/WhatsApp).
- **Landing page** — testimonials carousel, earnings calculator, FAQ accordion.
- **CSV export** — wallet ledger, shipping statement, insurance policies, drivers list.
- **Kitchen Display** — audio alerts, 10s polling, KPIs, action buttons, empty state.
- **Bookings calendar** — monthly grid with color-coded reservations.
- **Wallet tax breakdown** — commissions / withholdings / payouts displayed separately.
- **Skeleton loading animations** across all async views.
- **Skip-link + focus-visible** outlines (WCAG 2.1 AA).
- **Localized date formatters** — `formatDate()` and `formatRelative()` using `Intl.NumberFormat` for CO/MX locales.
- **Onboarding checklist** with `store_configured` flag.
- **SVG illustrations** in empty states (truck for drivers, shield+check for insurance, package for orders, kitchen for KDS).
- **Insurance view expansion** (113 → 365 lines) — KPIs (total/active/premium/claim-rate), coverage info card, status filter + free-text search, CSV export, no-results message.
- **Drivers view expansion** (226 → 744 lines) — KPIs (total/active/available-now/method-enabled), search + status filter + vehicle filter, edit capability, delete confirmation modal, inline DOM updates for toggles (no reload), AJAX handler for delivery settings form (was missing — bug fix).
- **Nav integration** — Seguros and Domiciliarios tabs added to the dashboard sidebar (Domiciliarios conditional on vendor having own-delivery configured or drivers registered).

### Security & Onboarding Audits — DEEP-AUDIT-002 (56 findings, 100% P0+P1+P2 resolved)

A deep audit of the onboarding flow and vendor panel surfaced 56 issues across P0 (critical), P1 (high), P2 (medium), and P3 (low) categories. All P0-P2 issues were resolved:

- **P0:** ReDi pause/resume, PosGold token masking, Aveonline OC access control, KYC IDOR fix, bank account decryption for masking.
- **P1:** 2FA rate limiting, nopriv abuse vectors closed, payout bank validation, PosGold SSRF protection, KYC document validation.
- **P2:** Custom tables for backorder notifications + review votes, bank account sync, PosGold cron, onboarding store-configured check.
- **P3:** Dead code cleanup, nonce standardization (`ltms_dashboard_nonce` everywhere), PosGold JSON categories, KYC expiry reminder cron.

### Registration Audit — REG-AUDIT-001 (11 fixes + 3 missing features)

- **REG-10:** Google OAuth nonce fix (`ltms_admin_nonce` → `ltms_auth_nonce`).
- **REG-01/02:** Atomic rate limiting via `$wpdb` `INSERT ON DUPLICATE KEY UPDATE`.
- **REG-04:** E.164 phone number validation.
- **REG-05/06/07:** Whitelists for `business_type`, `document_type`, `vendor_country`.
- **REG-08:** HTML email templates for KYC submission.
- **REG-09:** Verification URL now uses `wp_login_url()`.
- **REG-11:** `set_role()` → `add_role()` (don't strip existing roles).
- **MISSING-03:** Cloudflare Turnstile CAPTCHA (optional).
- **MISSING-04:** Admin notification on new vendor registration.
- **MISSING-08:** Endpoint to resend verification email.
- **UX-06:** Google OAuth profile completion flow.

### Cart & Checkout Fixes

- Cart drawer subtotal now displays correctly (HTML entity decode in PHP, `innerHTML` instead of `textContent` in JS).
- +/- buttons and remove button now work (nonce fix `ltms_drawer_nonce` → `ltms_ux_nonce`, correct parameters).
- Guests are allowed in cart drawer AJAX.
- Inline script with output buffering (SiteGround cannot remove it).
- NO-OP in old external JS `updateCartQty` / `removeCartItem` to avoid double-binding.
- Abandoned-cart modal disabled on cart page.

### Performance

- CAPI (Conversions API) now async.
- Cart drawer skips upsells on first load.
- Bundle discount calculation optimized from O(N) to O(1).
- Add-to-cart latency reduced via 5 optimizations (lazy-load, debounce, cache-bust).

### Stats

| Metric | Value |
|--------|-------|
| Version | 2.9.98 |
| Latest release date | 2026-07-08 |
| PHP classes | 516+ |
| JS modules | 20 |
| CSS files | 22 |
| Dashboard views | 25 |
| Business logic classes | 66 |
| Shipping methods | 9 |
| Audits completed | 3 (REG, DEEP, UIUX) |
| Total commits | 1,300+ |

---

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | 8.1 or higher |
| WordPress | 6.3 or higher |
| WooCommerce | 8.0 or higher |
| MySQL | 8.0 or higher |
| PHP Extensions | `openssl`, `bcmath`, `intl`, `mbstring`, `gd` |

---

## Installation

### 1. Upload Plugin

Upload `lt-marketplace-suite.zip` to `wp-admin > Plugins > Add New > Upload Plugin`.

### 2. Configure wp-config.php

Add the required constants from `wp-config-sample-snippet.php` to your `wp-config.php`.

At minimum:
```php
define( 'WP_LTMS_MASTER_KEY', 'your-64-char-random-key' );
define( 'WP_LTMS_OPENPAY_MERCHANT_ID_CO', 'your-merchant-id' );
define( 'WP_LTMS_OPENPAY_PRIVATE_KEY_CO', 'your-private-key' );
```

### 3. Activate

Activate the plugin from `wp-admin > Plugins`.

### 4. Configure

Navigate to `LT Marketplace > Configuración` in the WordPress admin menu.

---

## Quick Setup

1. **Configure payment gateways** — Set Openpay / Stripe credentials in Settings > Payments
2. **Set commission rates** — Settings > Commissions (default: 10%)
3. **Create login/register pages** — Add shortcodes `[ltms_vendor_login]` and `[ltms_vendor_register]`
4. **Create dashboard page** — Add shortcode `[ltms_vendor_dashboard]`
5. **Configure KYC** — Set required document types in Settings > KYC
6. **Enable optional modules** — ReDi, Kitchen Display, Ordenes de Compra Aveonline (toggle in Settings)
7. **Test with sandbox** — All payment gateways default to sandbox mode

---

## Shortcodes

| Shortcode | Description |
|-----------|-------------|
| `[ltms_vendor_dashboard]` | Vendor SPA dashboard (all views integrated) |
| `[ltms_vendor_login]` | Vendor login form |
| `[ltms_vendor_register]` | Vendor registration wizard (3-step with OAuth) |
| `[ltms_vendor_store]` | Public vendor storefront |
| `[ltms_vendor_orders]` | Standalone orders view |
| `[ltms_vendor_wallet]` | Standalone wallet view |
| `[ltms_vendor_kyc]` | Standalone KYC submission view |
| `[ltms_vendor_insurance]` | Standalone insurance policies view |
| `[ltms_vendor_bookings]` | Standalone bookings view |
| `[ltms_vendor_drivers]` | Standalone own-delivery drivers view (v2.9.98) |
| `[ltms_vendor_rnt]` | RNT/SECTUR tourism compliance form |

---

## Features

### Wallet System
- ACID transactions with MySQL `SELECT FOR UPDATE`
- Balance, held_balance, available_balance tracking
- Automatic freeze/unfreeze for compliance
- Tax breakdown display (commissions / withholdings / payouts)
- CSV export of ledger entries

### Tax Engine
**Colombia:** ReteFuente, ReteIVA (15%), ReteICA (by CIIU), Impoconsumo
**Mexico:** ISR art. 113-A (2%/4%/6%/10%), IEPS, IVA 16%, CFDI 4.0 + 11 SAT compliance columns on `lt_commissions`

### MLM Network
- 3-level referral commission distribution
- Ancestor path algorithm for efficient tree traversal
- TPTC integration for network synchronization

### Security
- AES-256-GCM encryption (v2) with backward-compatible CBC (v1) for all PII
- Built-in WAF with IP banning
- Immutable forensic logging (MySQL triggers)
- SAGRILAFT compliance logging
- **TOTP 2FA (RFC 6238)** for vendors and compliance officers
- Recovery codes (10 single-use, bcrypt-hashed)
- Optional admin-enforced 2FA per role
- **Cloudflare Turnstile** CAPTCHA on registration and 2FA
- **IDOR protection** on all KYC endpoints (ownership verification before read/write)
- **Bank account decryption** for masking in UI (no plaintext in DOM)
- **SSRF protection** on PosGold API client (URL allow-list)
- **Rate limiting** atomic via `INSERT ON DUPLICATE KEY UPDATE`
- **CSP-compliant frontend** — 0 inline handlers

### Vendor Dashboard (SPA, no page reloads)
- **Home** — KPIs, sales chart, recent orders widget, top products widget, onboarding checklist
- **Orders** — KPIs, search, date-range selector, skeleton loading, CSV export
- **Products** — pagination, search, gallery upload (5 images), ReDi toggle, quick edit
- **Envíos** — shipping labels, carrier selection, delete modal (WCAG 2.1 AA)
- **Fletes** — absorbed-shipping ledger, budget progress bar, monthly summary, CSV export
- **Wallet** — balance, transactions, tax breakdown, CSV export, payout request
- **Seguros** — XCover policies with KPIs, filters, CSV export (v2.9.98)
- **Domiciliarios** — own-delivery fleet CRUD with KPIs and inline DOM updates (v2.9.98)
- **Reservas** — bookings with calendar grid, seasons, policies, CSV export
- **Marketing** — banner management with download tracking
- **Seguridad** — TOTP 2FA enrollment, backup codes, recovery
- **Donaciones** — transparency dashboard
- **PosGold** — catalog sync with credentials test, scheduled sync
- **Configuración** — vacation mode, store logo, schedule, social links, store zones
- **ReDi** — reverse logistics with pause/resume, incident tracking (conditional)
- **Novedades** — incident management with SLA and comment threads (conditional)
- **Cocina** — Kitchen Display System with audio alerts and polling (restaurant vendors only)
- **Órdenes de Compra** — Aveonline OC management (conditional)

### Vendor Storefront
- Banner, logo, search, filters (category, stock, age range)
- Sort (recent, price asc/desc), pagination
- Grid/list view toggle
- Product cards with hover image swap, discount %, "NUEVO" / "AGOTADO" badges
- Rating stars with schema.org Product markup
- Cart drawer with free-shipping progress bar, upsells, countdown timer
- Wishlist (logged-in DB + guest cookie)
- Comparison table (variable products + sibling products)
- Product tabs: "Sobre el vendedor" + "Envío y Entrega" + size guide modal
- Trust badges: sales count, KYC verified, protected purchase, returns
- Rating summary: progress bars per star, recommendation %, filter by rating
- Live search with autocomplete

### Own-Delivery Fleet (v2.9.98)
- CRUD drivers (name, phone, document, vehicle type, plate)
- AES-256 encryption for document number and vehicle plate
- Active/inactive toggle (persists in `lt_vendor_drivers.status`)
- Available/busy toggle (ephemeral, stored in transient)
- Delivery configuration: price, ETA, zones, customer message
- "Domiciliario propio" shipping method appears in checkout only when vendor has ≥1 active driver
- KPIs: total drivers, active, available now, method enabled/disabled
- Search by name/phone/plate + status filter + vehicle filter
- Edit capability (document re-entered on edit for security)
- Delete confirmation modal (accessible, focus-managed)
- Inline DOM updates for toggles and deletes (no reload)
- Drivers count cache (`_ltms_drivers_count_cache` user_meta) to avoid DB query per dashboard render

### XCover Insurance
- Policy lifecycle: create on order paid, cancel on order cancelled/refunded
- Policy types: `parcel_protection`, `purchase_protection`, `other`
- Statuses: active, cancelled, claimed, expired
- Certificate download from vendor dashboard
- KPIs: total policies (12 months), active, premium sum, claim rate
- Coverage info card (expandable) explaining each policy type
- Filter by status + free-text search
- CSV export of filtered view

### ReDi Reverse Logistics (conditional)
- Product adoption from ReDi catalog
- Pause/resume per product
- Incident tracking with SLA
- CSV export

### Kitchen Display System (restaurant vendors only)
- Order tickets with status (new / preparing / ready / delivered)
- Audio alert on new ticket
- 10s polling for auto-refresh
- KPIs: pending, preparing, ready, avg time
- Action buttons per ticket
- Empty state with SVG

### Bookings
- 3 tabs: Reservas / Temporadas / Políticas
- Stats: total, confirmed, pending, cancelled
- Filters: status, date range
- Calendar grid view (monthly, color-coded reservations)
- CSV export
- 3 modals: new booking, new season, new policy

---

## Development

### Build Pipeline (v2.9.100+)

```bash
# Install dependencies
npm install

# Generate all .min.js and .min.css files
npm run build

# Validate PHP syntax (real AST parser)
npm run lint:php

# Validate JS syntax
npm run lint:js

# Run all linters
npm run lint

# Deploy to production (automated)
npm run deploy

# Rollback to previous commit
npm run rollback
```

### CI (GitHub Actions)

The CI pipeline (`.github/workflows/ci-lint.yml`) runs on every push/PR to `main`:
- ✅ PHP syntax check (`php -l` on all `.php` files)
- ✅ JS syntax check (`vm.Script` on all `.js` files)
- ✅ CSP compliance check (0 inline handlers in views)
- ✅ alert()/confirm() check (0 native calls in views)
- ✅ .min files sync check (all .min.js must exist)

### PHP Syntax Validation

```bash
node scripts/php_check.js <file.php> [...]
```

Uses `php-parser` npm package (real AST, not naive balance counting).

### Deploy

```bash
# Automated (recommended)
bash scripts/deploy.sh

# Manual
cd /home/customer/www/lo-tengo.com.co/public_html/wp-content/plugins/lt-marketplace-suite
git fetch origin && git reset --hard origin/main
cd /home/customer/www/lo-tengo.com.co/public_html
wp cache flush --allow-root
rm -rf wp-content/cache/supercache/* wp-content/uploads/siteground-optimizer-assets/*
wp eval 'opcache_reset();' --allow-root
```

### Rollback

```bash
bash scripts/rollback.sh [commit-hash]
```

---

## License

Proprietary — See [EULA](https://ltmarketplace.co/eula)

---

## Support

- **Documentation:** `docs/` directory
- **Security issues:** pqrscolombia@lo-tengo.com.co
- **Issues:** Use the GitHub issue tracker
- **Production:** lo-tengo.com.co (SiteGround hosting)
