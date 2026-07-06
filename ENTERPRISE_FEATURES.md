# LT Marketplace Suite — Enterprise Features

**Version:** 2.9.35

---

## 🏦 Financial Infrastructure

### ACID Wallet Ledger
- MySQL transactions with `SELECT FOR UPDATE` pessimistic locking
- `bcmath` precision for all monetary calculations (no floating point)
- Hold/Release mechanics for payout reservations
- Complete ledger history with running balances
- Automatic frozen wallet detection

### Multi-Currency Support
- **Colombia:** COP (Colombian Peso) with DIAN-compliant formatting
- **Mexico:** MXN (Mexican Peso) with SAT-compliant CFDI 4.0 support
- `Intl.NumberFormat` for locale-aware currency display

### Payout Engine
- Minimum payout thresholds per country
- Maximum concurrent pending requests (3 per vendor)
- Auto-approval for amounts below configured threshold
- Manual approval with admin notes
- Hold funds → approve/execute → debit ledger flow
- Automatic hold release on rejection

---

## 🇨🇴 Colombia Compliance

### DIAN Tax Engine
| Tax | Rate | Trigger |
|-----|------|---------|
| ReteFuente | 3.5–11% | Purchase > 4 UVT ($199,196 COP 2025) |
| ReteIVA | 15% of VAT | VAT-responsible vendors |
| ReteICA | 0.4–11.04‰ | By CIIU industry code |
| Impoconsumo | 8% | Restaurants (CIIU 5611) |

- UVT 2025 = $49,799 (Decreto 2229/2024)
- Electronic invoicing via Siigo (DIAN e-facturación)

### SAGRILAFT Compliance
- KYC mandatory before wallet withdrawals
- Automatic flagging of transactions above 10,000 UVT
- Immutable audit log protected by MySQL triggers
- External auditor read-only access role
- All auditor sessions logged with timestamp and summary

### Payment Methods (CO)
- Openpay credit/debit cards
- PSE (Pagos Seguros en Línea) bank transfer
- Nequi mobile wallet
- Daviplata
- Addi BNPL installments

---

## 🇲🇽 Mexico Compliance

### SAT Tax Engine — Art. 113-A LISR (Plataformas Tecnológicas)
| Monthly Income | ISR Rate |
|----------------|----------|
| ≤ $25,000 MXN | 2% |
| $25,001–$100,000 | 4% |
| $100,001–$300,000 | 6% |
| > $300,000 | 10% (provisional, annual reconciliation) |

- IVA: 16% standard rate
- IEPS: Variable by product category (beverages, tobacco, fuels)
- CFDI 4.0 support via SAT XML schema
- RESICO regime flag for simplified tax calculation

### Payment Methods (MX)
- Openpay credit/debit cards
- SPEI bank transfer (CLABE interbancaria)
- OXXO Pay voucher (24-hour expiry)
- Meses Sin Intereses (MSI) installments
- Addi BNPL

---

## 🔒 Security Architecture

### Encryption
- **Algorithm:** AES-256-CBC with PBKDF2 key derivation
- **Key derivation:** PBKDF2-SHA256, 10,000 iterations
- **Key storage:** `WP_LTMS_MASTER_KEY` constant (never in DB)
- **Encrypted fields:** Bank accounts, document numbers, API keys

### Web Application Firewall
- SQL injection detection (25+ patterns)
- XSS pattern matching
- LFI/RFI path traversal detection
- Bad bot user-agent filtering
- Rate limiting: 10 triggers → 24h IP ban
- Admin panel for IP whitelist/blacklist management

### Forensic Logging
```sql
-- Immutable log enforced at DB level
BEFORE UPDATE ON lt_security_events → SIGNAL SQLSTATE '45000'
BEFORE DELETE ON lt_security_events → SIGNAL SQLSTATE '45000'
```

### RBAC (Role-Based Access Control)
| Role | Dashboard | Withdrawals | KYC | Analytics | Audit |
|------|-----------|-------------|-----|-----------|-------|
| `ltms_vendor` | ✅ | ✅ | ✅ | ❌ | ❌ |
| `ltms_vendor_premium` | ✅ | ✅ | ✅ | ✅ | ❌ |
| `ltms_external_auditor` | ❌ | ❌ | Read | ✅ | ✅ |
| `ltms_compliance_officer` | Admin | Admin | ✅ | ✅ | ✅ |
| `ltms_support_agent` | Admin | Approve | ✅ | View | ❌ |

---

## 🌳 MLM Network

### Referral Tree Algorithm
- Ancestor path storage: `"1/5/12/23"` for O(depth) traversal
- Maximum 3 commission levels
- Real-time commission calculation and distribution
- TPTC network synchronization

### Commission Distribution
| Level | Description | Rate (% of platform fee) |
|-------|-------------|--------------------------|
| 1 | Direct sponsor | 40% |
| 2 | Sponsor's sponsor | 20% |
| 3 | 3rd level | 10% |

### Volume-Based Platform Rates (CO)
| Monthly Volume | Platform Fee |
|----------------|-------------|
| < $5M COP | 12% |
| $5M–$20M | 10% |
| $20M–$50M | 8% |
| > $50M | 6% |

---

## 🔌 API Integrations

| Service | Type | Countries | Auth |
|---------|------|-----------|------|
| Openpay | Payment Gateway | CO, MX | API Key |
| Siigo | E-Invoicing | CO | OAuth2 |
| Addi | BNPL | CO, MX | OAuth2 |
| Aveonline | Logistics | CO | API Key |
| ZapSign | E-Signatures | CO, MX | Bearer |
| TPTC | MLM Network | CO, MX | API Key |
| XCover | Insurance | CO, MX | Partner Code |
| Backblaze B2 | File Storage | All | App Key |
| **PosGold** | **Catalog Sync (POS → WooCommerce)** | **MX, CO** | **API Key + Token** |

All clients extend `LTMS_Abstract_Api_Client` implementing `LTMS_Api_Client_Interface`.
Created via `LTMS_Api_Factory::get('provider')`.

---

## 🏪 PosGold Integration (v2.9.35)

PosGold is a Point-of-Sale / ERP system widely used by Mexican and Colombian vendors. The v2.9.35 integration allows vendors to sync their physical-store catalog into WooCommerce automatically.

### Architecture

- **API client:** `includes/api/class-ltms-api-posgold.php` (extends `LTMS_Abstract_Api_Client`)
- **Sync engine:** `includes/business/class-ltms-posgold-sync.php`
- **Price calculator:** `includes/business/class-ltms-posgold-price-calculator.php`
- **Vendor dashboard view:** `includes/frontend/views/view-posgold.php`

### Price Calculator — 8 Components

The price calculator decomposes the final WooCommerce price into 8 configurable components so vendors have full transparency over margins and tax burden:

| # | Component | Description |
|---|-----------|-------------|
| 1 | `cost` | Base acquisition cost from PosGold |
| 2 | `markup` | Vendor profit margin (percentage) |
| 3 | `iva` | VAT (16% MX / 19% CO) |
| 4 | `ieps` | IEPS excise tax (MX only, variable by category) |
| 5 | `shipping_factor` | Average shipping cost amortized per unit |
| 6 | `platform_fee` | LTMS marketplace commission (volume-tiered) |
| 7 | `payment_fee` | Payment gateway fee (Openpay/SPEI/OXXO) |
| 8 | `rounding` | Round-up to next psychological price (.99 / .00) |

Final price = `cost + markup + iva + ieps + shipping_factor + platform_fee + payment_fee + rounding`

### Catalog Sync Features

- **Category dropdown:** maps PosGold categories → WooCommerce categories (auto-create if missing)
- **SEO templates:** per-category SEO title/description templates (filled with product data)
- **Price rounding:** configurable rounding rule (none / .99 / .00 / next-10 / next-50)
- **Deduplication:** matches PosGold SKU ↔ `_sku` to avoid duplicate products on re-sync
- **Activity log:** every sync batch writes to `lt_posgold_sync_log` (vendor_id, products_synced, errors, duration)
- **Manual trigger:** vendor clicks "Sincronizar ahora" in `view-posgold.php` (AJAX `ltms_posgold_sync`)
- **Scheduled sync:** optional WP-Cron event `ltms_posgold_scheduled_sync` (daily by default)

---

## 🔐 TOTP 2FA (v2.9.35)

Vendors can now enable Time-based One-Time Password (TOTP) two-factor authentication from their dashboard.

- **Class:** `includes/core/class-ltms-totp-2fa.php`
- **Algorithm:** RFC 6238 TOTP (SHA-1, 30-second window, 6 digits)
- **QR code:** generated via `endroid/qr-code` (Composer dependency)
- **Secret storage:** encrypted with `LTMS_Core_Security::encrypt()` (AES-256-CBC) before saving to `user_meta` (`ltms_totp_secret`)
- **Recovery codes:** 10 single-use codes stored hashed (bcrypt), regenerated on demand
- **Enforcement:** admin can force 2FA for `ltms_vendor_premium` and `ltms_compliance_officer` roles via `ltms_force_2fa_roles` setting
- **Grace period:** 7 days from first login after enrollment before 2FA is mandatory (configurable)
- **Vendor dashboard view:** `view-security.php` (enroll, verify, regenerate recovery codes, disable)
- **Login flow:** after password verification, if 2FA enabled → redirect to `ltms_2fa_challenge` page → verify TOTP code → complete login

### SAT México Compliance Logging

The TOTP module also writes to `lt_security_events` for SAT compliance auditing:
- 2FA enrollment events
- 2FA challenge failures (with IP + user-agent)
- 2FA disabled events (with reason)
- Recovery code usage

---

## 🇲🇽 SAT México Compliance Columns (v2.9.35)

The `lt_commissions` table was extended with **11 new columns** to support Mexican fiscal reporting (CFDI 4.0):

| # | Column | Type | Purpose |
|---|--------|------|---------|
| 1 | `cfdi_uuid` | VARCHAR(64) | UUID del CFDI emitido (SAT) |
| 2 | `cfdi_serie` | VARCHAR(20) | Serie del comprobante |
| 3 | `cfdi_folio` | VARCHAR(40) | Folio del comprobante |
| 4 | `rfc_emisor` | VARCHAR(13) | RFC del emisor (vendedor) |
| 5 | `rfc_receptor` | VARCHAR(13) | RFC del receptor (comprador/marketplace) |
| 6 | `regimen_fiscal` | VARCHAR(10) | Clave de régimen fiscal (ej. 616 — Sin obligaciones fiscales) |
| 7 | `uso_cfdi` | VARCHAR(10) | Clave de uso de CFDI (ej. G03 — Gastos en general) |
| 8 | `forma_pago` | VARCHAR(10) | Clave de forma de pago (ej. 03 — Transferencia) |
| 9 | `metodo_pago` | VARCHAR(10) | Clave de método de pago (PUE / PPD) |
| 10 | `fecha_certificacion` | DATETIME | Fecha de certificación SAT |
| 11 | `estado_cfdi` | VARCHAR(20) | Vigente / Cancelado |

- **Migration:** `includes/core/migrations/class-ltms-db-migrations.php` (idempotent `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`)
- **Fiscal reports:** admin fiscal-mexico panel queries these columns for monthly SAT reports
- **Export:** CSV export of `lt_commissions` includes these columns when `LTMS_Core_Config::get_country() === 'MX'`

---

## 📱 Progressive Web App (PWA)

- Web App Manifest (6 icon sizes: 72px–512px)
- Service Worker with Network-First caching strategy
- Push Notifications (VAPID, Web Push API)
- In-app notification polling (30-second interval)
- Offline fallback to cache
- Installable on Android and iOS

---

## 🏪 KYC Workflow

```
Vendor submits docs → Admin queue → Review → Decision
                                          ↙        ↘
                                    Approved     Rejected
                                    (wallet ←)  (email with reason)
                                    unlocked    (vendor can resubmit)
```

- Secure document storage (Backblaze B2)
- Document types per country (CC/NIT/RFC/CURP)
- 1-2 business day SLA
- Email notifications for each status change
- SAGRILAFT-compliant retention

---

## 📊 Analytics & Reporting

### Vendor Analytics
- 12-month sales trend (Chart.js)
- Commission breakdown by category
- Referral network performance
- Volume tier indicator

### Admin Reports
- Platform-wide fiscal summary
- Per-country tax breakdown
- Pending/processed payout metrics
- Vendor activity heatmap
- Security event log

---

## ⚡ Performance

- Singleton config loading (O(1) after first call)
- AJAX-driven SPA (no full-page reloads)
- Debounced live search (300ms)
- PHP OpCache optimized autoloader
- Asset minification (CSS + JS)
- Service Worker pre-caching for static assets
