# LT Marketplace Suite

> Enterprise multi-vendor marketplace for WooCommerce — Colombia & Mexico

**Version:** 2.9.35 | **PHP:** 8.1+ | **WC:** 8.0+ | **WP:** 6.3+

[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)

[![CI](https://img.shields.io/badge/CI-%231185-green.svg)]() [![Tests](https://img.shields.io/badge/tests-3%2C038%20passing-brightgreen.svg)]() [![Classes](https://img.shields.io/badge/PHP%20classes-309-blue.svg)]() [![JS modules](https://img.shields.io/badge/JS%20modules-113-blue.svg)]()

---

## Overview

LT Marketplace Suite (LTMS) transforms WooCommerce into a full-featured enterprise marketplace with:

- **Multi-vendor management** with vendor-specific dashboards
- **ACID-compliant wallet ledger** with hold/release mechanics
- **Country-specific tax compliance** — Colombia (DIAN/SAGRILAFT) and Mexico (SAT/CFDI 4.0)
- **MLM referral network** with 3-level commission distribution
- **KYC identity verification** workflow
- **PWA vendor dashboard** with push notifications
- **PosGold catalog sync** (v2.9.35) — sync POS inventory to WooCommerce with 8-component price calculator
- **TOTP 2FA** (v2.9.35) — RFC 6238 two-factor auth for vendors
- **SAT México compliance columns** (v2.9.35) — 11 new CFDI columns in `lt_commissions`

---

## v2.9.35 Highlights (2026-07-06)

### New Features

- **PosGold integration**: vendors sync their PosGold catalog to WooCommerce automatically — API client, sync engine, price calculator with 8 components (cost + markup + IVA + IEPS + shipping + platform fee + payment fee + rounding), category dropdown, SEO templates, price rounding, deduplication.
- **Vendor dashboard menu additions** (4 new items):
  - **Marketing** — banner management
  - **Security** — TOTP 2FA enrollment / recovery codes
  - **Donations** — transparency dashboard
  - **PosGold** — catalog sync
- **Activity feed** endpoint for vendor home dashboard
- **6 new AJAX endpoints**: `backorder_notify`, `get_invoices`, `review_helpful`, `save_push_subscription`, `submit_question`, `submit_return`
- **11 SAT México columns** added to `lt_commissions` table (CFDI UUID, RFC, régimen, uso CFDI, etc.)
- **8 frontend classes added to autoloader**: Wishlist, Quick_View, Comparison_Table, Product_Tabs, Product_Video, Rating_Summary, Trust_Badges, SEO_Enhanced

### Bug Fixes

- Composer `dompdf` constraint corrected (`^2.0.9` → `^2.0`)
- `LTMS_Core_Security::derive_key()` declared twice (fatal) — fixed
- `continue 2` in `logistics-compliance.php` illegal — fixed
- `LTMS_Core_Firewall::get_client_ip()` visibility `private` → `public` (was WSOD)
- 35+ classes added to autoloader classmap
- Cross-Border settings section slug normalized (underscore/hyphen)
- `LTMS_PATH` → `LTMS_PLUGIN_DIR` constant migration
- Storefront nonce action `ltms_storefront_nonce` → `ltms_ux_nonce`
- `.min.js` / `.min.css` synchronized with sources, removed from `.gitignore`

### Stats

| Metric | Value |
|--------|-------|
| Version | 2.9.35 |
| Release date | 2026-07-06 |
| Tests passing | 3,038 |
| CI run | #1185 (green) |
| Files tracked | 5,633 |
| PHP classes | 309 |
| JS modules | 113 |

---

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | 8.1 or higher |
| WordPress | 6.3 or higher |
| WooCommerce | 8.0 or higher |
| MySQL | 8.0 or higher |
| PHP Extensions | `openssl`, `bcmath`, `intl`, `mbstring` |

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

1. **Configure payment gateways** — Set Openpay credentials in Settings > Payments
2. **Set commission rates** — Settings > Commissions (default: 10%)
3. **Create login/register pages** — Add shortcodes `[ltms_vendor_login]` and `[ltms_vendor_register]`
4. **Create dashboard page** — Add shortcode `[ltms_vendor_dashboard]`
5. **Configure KYC** — Set required document types in Settings > KYC
6. **Test with sandbox** — All payment gateways default to sandbox mode

---

## Shortcodes

| Shortcode | Description |
|-----------|-------------|
| `[ltms_vendor_dashboard]` | Vendor SPA dashboard |
| `[ltms_vendor_login]` | Vendor login form |
| `[ltms_vendor_register]` | Vendor registration form |

---

## Features

### Wallet System
- ACID transactions with MySQL `SELECT FOR UPDATE`
- Balance, held_balance, available_balance tracking
- Automatic freeze/unfreeze for compliance

### Tax Engine
**Colombia:** ReteFuente, ReteIVA (15%), ReteICA (by CIIU), Impoconsumo
**Mexico:** ISR art. 113-A (2%/4%/6%/10%), IEPS, IVA 16%, CFDI 4.0 + 11 SAT compliance columns on `lt_commissions`

### MLM Network
- 3-level referral commission distribution
- Ancestor path algorithm for efficient tree traversal
- TPTC integration for network synchronization

### Security
- AES-256-CBC encryption for all PII
- Built-in WAF with IP banning
- Immutable forensic logging (MySQL triggers)
- SAGRILAFT compliance logging
- **TOTP 2FA (RFC 6238)** for vendors and compliance officers (v2.9.35)
- Recovery codes (10 single-use, bcrypt-hashed)
- Optional admin-enforced 2FA per role

### Vendor Dashboard (v2.9.35 additions)
- **Marketing view** — manage promotional banners
- **Security view** — enroll/manage TOTP 2FA and recovery codes
- **Donations view** — transparency dashboard for charitable contributions
- **PosGold view** — sync physical-store catalog to WooCommerce, with 8-component price calculator
- Activity feed on home dashboard

### PosGold Integration (v2.9.35)
- Catalog sync from PosGold POS to WooCommerce
- Price calculator with 8 components (cost, markup, IVA, IEPS, shipping, platform fee, payment fee, rounding)
- Category auto-mapping and creation
- Per-category SEO templates
- Deduplication by SKU
- Sync log table (`lt_posgold_sync_log`)
- Manual and scheduled (WP-Cron) sync

---

## Development

```bash
# Install dependencies
make install

# Run tests
make test

# Run linter
make lint

# Build for production
make dist

# Start Docker dev environment
make dev-up
```

---

## License

GNU General Public License v2.0 — See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html)

---

## Support

- **Documentation:** `docs/` directory
- **Security issues:** security@ltmarketplace.co
- **Issues:** Use the GitHub issue tracker
