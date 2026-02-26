# LT Marketplace Suite

> Enterprise multi-vendor marketplace for WooCommerce — Colombia & Mexico

**Version:** 1.5.0 | **PHP:** 8.1+ | **WC:** 8.0+ | **WP:** 6.3+

[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)

---

## Overview

LT Marketplace Suite (LTMS) transforms WooCommerce into a full-featured enterprise marketplace with:

- **Multi-vendor management** with vendor-specific dashboards
- **ACID-compliant wallet ledger** with hold/release mechanics
- **Country-specific tax compliance** — Colombia (DIAN/SAGRILAFT) and Mexico (SAT/CFDI 4.0)
- **MLM referral network** with 3-level commission distribution
- **KYC identity verification** workflow
- **PWA vendor dashboard** with push notifications

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
**Mexico:** ISR art. 113-A (2%/4%/6%/10%), IEPS, IVA 16%, CFDI 4.0

### MLM Network
- 3-level referral commission distribution
- Ancestor path algorithm for efficient tree traversal
- TPTC integration for network synchronization

### Security
- AES-256-CBC encryption for all PII
- Built-in WAF with IP banning
- Immutable forensic logging (MySQL triggers)
- SAGRILAFT compliance logging

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
