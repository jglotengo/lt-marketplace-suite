# LT Marketplace Suite — Architecture Documentation

**Version:** 1.5.0
**Last Updated:** 2025-01-01
**Pattern:** Hexagonal Architecture (Ports & Adapters)

---

## 1. Overview

LT Marketplace Suite (LTMS) is an enterprise multi-vendor WooCommerce plugin built with strict Hexagonal Architecture principles. The system separates business logic from infrastructure concerns, enabling independent testability and replaceable adapters.

```
┌─────────────────────────────────────────────────────────────────┐
│                        DRIVING SIDE                             │
│    WordPress Hooks │ REST API │ WP-Admin │ Vendor SPA           │
└─────────────────────────────┬───────────────────────────────────┘
                               │
                    ┌──────────▼──────────┐
                    │   Application Core  │
                    │  (Business Logic)   │
                    │                     │
                    │  LTMS_Wallet        │
                    │  LTMS_Tax_Engine    │
                    │  LTMS_Commission    │
                    │  LTMS_Referral_Tree │
                    │  LTMS_Payout_Sched. │
                    └──────────┬──────────┘
                               │
┌─────────────────────────────▼───────────────────────────────────┐
│                        DRIVEN SIDE                              │
│  MySQL/wpdb │ Openpay │ Siigo │ Addi │ ZapSign │ XCover │ TPTC  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 2. Directory Structure

```
lt-marketplace-suite/
├── includes/
│   ├── core/                    # Infrastructure layer
│   │   ├── class-ltms-kernel.php        # Plugin bootstrapper
│   │   ├── class-ltms-config.php        # Configuration singleton
│   │   ├── class-ltms-security.php      # AES-256 encryption + WAF
│   │   ├── class-ltms-logger.php        # Immutable forensic logger
│   │   ├── class-ltms-firewall.php      # WAF (SQL/XSS/LFI detection)
│   │   ├── class-ltms-data-masking.php  # PII masking/auditor access
│   │   ├── interfaces/                  # Ports (contracts)
│   │   ├── traits/                      # Reusable concerns
│   │   ├── migrations/                  # DB schema management
│   │   ├── services/                    # Activator / Deactivator
│   │   └── utils/                       # Helpers
│   │
│   ├── business/                # Domain layer
│   │   ├── class-ltms-wallet.php              # ACID ledger
│   │   ├── class-ltms-order-split.php         # Commission splitting
│   │   ├── class-ltms-tax-engine.php          # Tax strategy facade
│   │   ├── class-ltms-commission-strategy.php # Volume-based rates
│   │   ├── class-ltms-referral-tree.php       # MLM network
│   │   ├── class-ltms-payout-scheduler.php    # Payout management
│   │   ├── class-ltms-affiliates.php          # Referral code management
│   │   ├── strategies/                        # Tax strategies (CO, MX)
│   │   └── listeners/                         # Domain event handlers
│   │
│   ├── api/                     # Infrastructure layer (adapters)
│   │   ├── class-ltms-abstract-api-client.php # Base HTTP client
│   │   ├── class-ltms-api-openpay.php         # Openpay CO/MX
│   │   ├── class-ltms-api-siigo.php           # Siigo accounting
│   │   ├── class-ltms-api-addi.php            # Addi BNPL
│   │   ├── class-ltms-api-aveonline.php       # Aveonline logistics
│   │   ├── class-ltms-api-zapsign.php         # ZapSign e-signature
│   │   ├── class-ltms-api-tptc.php            # TPTC MLM network
│   │   ├── class-ltms-api-xcover.php          # XCover insurance
│   │   └── factories/                         # API factory
│   │
│   ├── admin/                   # WP Admin UI layer
│   │   ├── class-ltms-admin.php               # Menu + page registration
│   │   ├── class-ltms-admin-settings.php      # Settings AJAX controller
│   │   ├── class-ltms-admin-payouts.php       # Payout/KYC AJAX controller
│   │   └── views/                             # PHP view templates
│   │
│   ├── frontend/                # Public-facing layer
│   │   ├── class-ltms-frontend-assets.php     # CSS/JS enqueueing
│   │   ├── class-ltms-dashboard-logic.php     # Vendor SPA logic
│   │   ├── class-ltms-public-auth-handler.php # Login/register
│   │   └── views/                             # PHP view templates
│   │
│   └── roles/                   # RBAC
│       ├── class-ltms-roles.php               # Role registration
│       └── class-ltms-external-auditor-role.php
│
├── assets/
│   ├── css/                     # Stylesheets
│   └── js/                      # JavaScript modules
│
├── templates/
│   ├── emails/                  # HTML email templates
│   └── pdf/                     # PDF document templates
│
├── languages/                   # i18n (.pot, .po files)
├── tests/                       # PHPUnit + Cypress
├── docs/                        # This documentation
└── bin/                         # DevOps scripts
```

---

## 3. Core Design Patterns

### 3.1 Hexagonal Architecture (Ports & Adapters)

**Ports (Interfaces):**
- `LTMS_Api_Client_Interface` — contract for all external API clients
- `LTMS_Tax_Strategy_Interface` — contract for tax calculation strategies

**Adapters (Implementations):**
- `LTMS_Api_Openpay`, `LTMS_Api_Siigo`, etc. — HTTP adapters for external services
- `LTMS_Tax_Strategy_Colombia`, `LTMS_Tax_Strategy_Mexico` — country-specific strategies

### 3.2 Strategy Pattern (Tax Engine)

```
LTMS_Tax_Engine::calculate($gross, $order_data, $vendor_data, 'CO')
    → get_strategy('CO')
    → LTMS_Tax_Strategy_Colombia::calculate(...)
    → returns [rete_fuente, rete_iva, rete_ica, impoconsumo, vendor_net, ...]
```

Tax rates (Colombia 2025):
- UVT = $49,799 (Decreto 2229/2024)
- ReteFuente servicios: 4% (base > 4 UVT)
- ReteIVA: 15% del IVA
- ReteICA: 11.04‰ (CIIU 4711), variable by CIIU
- Impoconsumo: 8% (restaurantes CIIU 5611)

Tax rates (Mexico 2025):
- ISR art. 113-A: 2% (<25K) / 4% (25K-100K) / 6% (100K-300K) / 10% (>300K MXN/mes)
- IVA: 16%
- IEPS: variable by product category

### 3.3 Singleton Pattern

Used for `LTMS_Core_Config` and `LTMS_Affiliates`. Prevents multiple instantiations of configuration and logger objects.

### 3.4 Factory Pattern (API Clients)

```php
$client = LTMS_Api_Factory::get('openpay');  // Returns LTMS_Api_Openpay
$client = LTMS_Api_Factory::get('siigo');    // Returns LTMS_Api_Siigo
```

---

## 4. ACID Wallet Ledger

The wallet system implements full ACID compliance using MySQL transactions and `SELECT FOR UPDATE`:

```sql
BEGIN;
SELECT balance, held_balance, is_frozen
FROM lt_wallets
WHERE user_id = ? FOR UPDATE;  -- Pessimistic lock

-- Validate: is_frozen, sufficient balance
UPDATE lt_wallets SET balance = balance - ? WHERE user_id = ?;
INSERT INTO lt_wallet_ledger (...);
COMMIT;
```

**Ledger types:** `commission`, `payout`, `referral`, `adjustment`, `hold`, `release`

---

## 5. Security Architecture

### 5.1 AES-256-CBC Encryption
- Key derivation: PBKDF2 with 10,000 iterations
- Used for: bank accounts, document numbers, API keys
- Key storage: `WP_LTMS_MASTER_KEY` constant in `wp-config.php`

### 5.2 WAF (Web Application Firewall)
Detects and blocks at the WordPress request level:
- SQL injection patterns
- XSS vectors
- LFI/RFI patterns
- Bad bots (User-Agent matching)
- Rate limiting by IP

### 5.3 Forensic Logging
The `lt_security_events` table is protected by MySQL triggers:
```sql
CREATE TRIGGER prevent_log_update BEFORE UPDATE ON lt_security_events
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Log records are immutable';
```

### 5.4 RBAC — Custom Roles
| Role | Key Capability |
|------|---------------|
| `ltms_vendor` | `ltms_view_dashboard` |
| `ltms_vendor_premium` | `ltms_analytics`, `ltms_bulk_products` |
| `ltms_external_auditor` | `ltms_view_audit_logs` (read-only) |
| `ltms_compliance_officer` | `ltms_manage_kyc`, `ltms_view_sagrilaft` |
| `ltms_support_agent` | `ltms_manage_orders`, `ltms_view_vendors` |

---

## 6. MLM / Referral Network

The referral tree stores ancestor paths for O(1) commission distribution:

```
User 1 (root)
  └── User 5 (referred by 1)  → ancestor_path = "1"
        └── User 12 (referred by 5) → ancestor_path = "1/5"
              └── User 23 (referred by 12) → ancestor_path = "1/5/12"
```

Commission distribution (% of platform_fee):
- Level 1 (direct sponsor): 40%
- Level 2: 20%
- Level 3: 10%

---

## 7. Vendor SPA (Single Page Application)

The vendor dashboard is a jQuery-based SPA loaded by the `[ltms_vendor_dashboard]` shortcode.

**View loading pattern:**
```javascript
LTMS.Dashboard.loadView('wallet')
→ loadWalletView()
→ AJAX: ltms_get_wallet_data
→ renderWalletView(data)
```

**PWA:** Service Worker (Network-First) + Web App Manifest for installable mobile experience.

---

## 8. API Integrations

| Service | Purpose | Auth |
|---------|---------|------|
| Openpay CO/MX | Card payments, PSE, OXXO | API Key |
| Siigo | Electronic invoicing (DIAN) | OAuth2 |
| Addi | BNPL installments | OAuth2 |
| Aveonline | Logistics / shipping | API Key |
| ZapSign | E-signatures for vendor contracts | Bearer |
| TPTC | MLM network sync | API Key |
| XCover | Product insurance | Partner Code |
| Backblaze B2 | File storage (KYC docs) | App Key |

---

## 9. Database Schema (Key Tables)

| Table | Purpose |
|-------|---------|
| `lt_wallets` | Vendor wallet balances |
| `lt_wallet_ledger` | Immutable transaction log |
| `lt_commissions` | Commission records per order |
| `lt_payout_requests` | Payout requests + status |
| `lt_vendor_kyc` | KYC document submissions |
| `lt_referral_network` | MLM tree with ancestor paths |
| `lt_security_events` | Immutable WAF/auth event log |
| `lt_waf_blocked_ips` | WAF IP blocklist |
| `lt_notifications` | In-app vendor notifications |
| `lt_marketing_banners` | Marketing banner registry |

---

## 10. Cron Jobs

| Hook | Schedule | Action |
|------|----------|--------|
| `ltms_daily_payout_processor` | Daily 02:00 | Process pending payouts |
| `ltms_weekly_tax_report` | Weekly Monday | Generate fiscal reports |
| `ltms_hourly_waf_cleanup` | Hourly | Clean expired WAF blocks |
| `ltms_sync_commission_rates` | Daily 06:00 | Sync rates from config |

---

*This document was generated as part of the LTMS 1.5.0 release.*
