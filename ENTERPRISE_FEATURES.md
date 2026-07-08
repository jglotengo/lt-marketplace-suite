# LT Marketplace Suite — Enterprise Features

**Version:** 2.9.98
**Last Updated:** 2026-07-08
**Audits completed:** REG-AUDIT-001, DEEP-AUDIT-002, UIUX-AUDIT-001 (all 100% resolved)

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
- **Algorithm (v2 preferred):** AES-256-GCM authenticated encryption
- **Algorithm (v1 legacy):** AES-256-CBC with PBKDF2 key derivation (backward-compat)
- **Version auto-detected:** `v1:` / `v2:` prefix in ciphertext
- **Key derivation:** PBKDF2-SHA256, 10,000 iterations
- **Key storage:** `WP_LTMS_MASTER_KEY` constant (never in DB)
- **Encrypted fields:** Bank accounts, document numbers, vehicle plates, API keys, OAuth tokens, TOTP secrets
- **IDOR Protection:** All vendor-data endpoints verify ownership before read/write (v2.9.61 P0-4)
- **Bank Account Masking:** Decrypted server-side, masked (`****1234`) before browser — never plaintext in DOM (v2.9.61 P0-5)
- **SSRF Protection:** PosGold API client validates URLs against allow-list (v2.9.61 P1)

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

---

## 🚚 Own-Delivery Fleet (v2.9.98)

Vendors can manage their own delivery fleet (domiciliarios propios) instead of relying solely on third-party carriers. The "Domiciliario propio" shipping method appears in checkout only when the vendor has ≥1 active driver.

### Architecture
- **AJAX handlers:** `includes/frontend/class-ltms-driver-ajax.php`
- **Shipping method:** `includes/shipping/class-ltms-shipping-method-own-delivery.php`
- **Vendor dashboard view:** `includes/frontend/views/view-drivers.php` (744 lines)
- **Database table:** `lt_vendor_drivers` (id, vendor_id, full_name, document_number, phone, vehicle_type, vehicle_plate, status, created_at, updated_at)

### Driver Management
- **CRUD:** Add, edit, delete drivers (max 50 per vendor)
- **Encrypted fields:** `document_number` and `vehicle_plate` (AES-256-GCM)
- **Vehicle types:** bicycle, moto, car, walking (+ legacy: bici, carro, pie)
- **Status:** active / inactive (persisted in `lt_vendor_drivers.status`)
- **Availability:** available / busy (ephemeral, stored in transient `ltms_driver_available_{id}`, 8h TTL)
- **IDOR Protection:** Every operation verifies `vendor_id` ownership before proceeding

### Delivery Configuration
- **Price:** Per-delivery cost in COP (0 = free)
- **ETA:** Estimated delivery time in minutes (1-480, default 60)
- **Zones:** Free-text coverage zones (e.g., "Chapinero, Usaquén, Suba")
- **Customer message:** Optional note displayed at checkout

### Dashboard UI (v2.9.98)
- **KPIs:** Total drivers, active, available now, method enabled/disabled
- **Search:** By name, phone, or plate
- **Filters:** Status (active/inactive), vehicle type
- **Edit:** Pre-populates modal; document re-entered for security
- **Delete:** Confirmation modal (accessible, focus-managed)
- **Inline DOM updates:** Toggle/delete without page reload (badge + button + KPIs updated instantly)
- **Toast feedback:** Success/error after each operation
- **Empty state:** SVG truck illustration
- **Drivers count cache:** `_ltms_drivers_count_cache` in user_meta avoids DB query per dashboard render

---

## 🛡️ XCover Insurance (v2.9.35, UI expansion v2.9.97)

Vendors can offer XCover insurance on their products (parcel protection + purchase protection). Policies are created automatically when an order is paid, and cancelled when the order is cancelled/refunded.

### Architecture
- **Listener:** `includes/business/listeners/class-ltms-xcover-policy-listener.php`
- **API client:** `includes/api/class-ltms-api-xcover.php`
- **Vendor dashboard view:** `includes/frontend/views/view-insurance.php` (365 lines)
- **Database table:** `lt_insurance_policies`

### Policy Lifecycle
1. **Create:** `woocommerce_payment_complete` → `on_order_paid()` → `LTMS_Api_XCover::create_policy(quote_id, holder_data)` → INSERT into `lt_insurance_policies`
2. **Cancel:** `woocommerce_order_status_cancelled` / `refunded` → `on_order_cancelled()` → `LTMS_Api_XCover::cancel_policy(policy_id, reason)` → UPDATE status
3. **Claim:** Manual via admin panel
4. **Expire:** Cron job checks `created_at` + coverage period

### Policy Types
- `parcel_protection` — Loss, theft, or damage during transit
- `purchase_protection` — Manufacturing defects within 30 days of delivery
- `other` — Custom coverage

### Policy Statuses
- `active` — Policy in effect
- `cancelled` — Cancelled (order cancelled/refunded)
- `claimed` — Claim filed
- `expired` — Coverage period ended

### Dashboard UI (v2.9.97)
- **KPIs:** Total policies (12 months), active, premium sum, claim rate
- **Coverage info card:** Expandable `<details>` explaining each policy type
- **Filters:** Status + free-text search (by order # or policy #)
- **CSV export:** Of filtered view
- **Empty state:** SVG shield+check illustration
- **Status badges:** CSS classes (no inline styles)

---

## 🔄 ReDi Reverse Logistics (v2.9.61)

ReDi integration for reverse logistics (product returns/recalls). Vendors can adopt products from the ReDi catalog, pause/resume them, and track incidents.

### Features
- **Product adoption:** Browse ReDi catalog, adopt products to vendor store
- **Pause/Resume:** Per-product toggle (persists in DB)
- **Incident tracking:** Create incidents with SLA tracking
- **Incident comments:** Threaded discussion per incident
- **CSV export:** Of incidents

### Conditional Visibility
- Tab "ReDi" appears in dashboard nav only when `ltms_redi_enabled = 'yes'` (admin setting)
- Tab "Novedades" (incidents) appears alongside ReDi

---

## 🍳 Kitchen Display System (v2.9.92)

KDS for restaurant vendors. Real-time order ticket display with audio alerts and auto-refresh.

### Features
- **Order tickets:** Status (new / preparing / ready / delivered)
- **Audio alerts:** Plays a sound when a new ticket arrives
- **Auto-refresh:** Polls every 10 seconds for new tickets
- **KPIs:** Pending, preparing, ready, average preparation time
- **Action buttons:** Per-ticket status transitions
- **Empty state:** SVG kitchen illustration

### Conditional Visibility
- Tab "Cocina" appears in dashboard nav only when vendor has `ltms_is_restaurant = 'yes'` user_meta

---

## 📅 Bookings with Calendar (v2.9.91)

Bookings module with 3 tabs (Reservas / Temporadas / Políticas) and a monthly calendar grid view.

### Features
- **Stats:** Total, confirmed, pending, cancelled bookings
- **Filters:** Status, date range
- **Calendar view:** Monthly grid with color-coded reservations
- **Seasons:** Define high/low season date ranges with custom pricing
- **Policies:** Cancellation policies, deposit rules
- **CSV export:** Of bookings
- **3 modals:** New booking, new season, new policy

---

## 🎨 UI/UX Layer (v2.9.77-98, UIUX-AUDIT-001)

62 findings audited and 100% resolved across 25 dashboard views. See `UX_ENHANCEMENTS.md` for full details.

### Key UI Features
- **Pure SPA:** 0 page reloads (except create/edit flows)
- **Toast system:** 0 alerts
- **CSP compliance:** 0 inline handlers
- **17 SVG icons:** Woodmart-style
- **Mobile bottom nav:** 5 items, ≤768px
- **Dark mode:** Toggle + `prefers-color-scheme`
- **Global search:** Topbar + breadcrumbs
- **Keyboard shortcuts:** g+key, /, ?, Esc + help modal
- **Skeleton loading:** All async views
- **Skip-link + focus-visible:** WCAG 2.1 AA
- **Localized dates:** `formatDate()` + `formatRelative()` for CO/MX

### Registration Wizard (v2.9.60 REG-AUDIT-001)
- 3-step wizard (business data → documents → verification)
- Honeypot anti-spam
- Cloudflare Turnstile CAPTCHA (optional)
- Google OAuth login + profile completion
- DANE municipality dropdown (AJAX-loaded)
- SAGRILAFT consent checkbox
- E.164 phone validation
- Whitelists: business_type, document_type, vendor_country
- Atomic rate limiting (INSERT ON DUPLICATE KEY UPDATE)
- Admin notification on new registration
- Resend verification email endpoint

### Security Hardening (v2.9.61 DEEP-AUDIT-002)
- **IDOR Protection:** All vendor-data endpoints verify ownership
- **Bank Account Masking:** Server-side decrypt + mask, never plaintext in DOM
- **2FA Rate Limiting:** 5 failed attempts per IP per 15 min → lockout
- **Nopriv Abuse Vectors:** Closed (guests can't access vendor endpoints)
- **Payout Bank Validation:** Bank account format validated before submission
- **PosGold SSRF Protection:** URL allow-list before outbound HTTP
- **KYC Document Validation:** File type, size, and content validation
- **Nonce Standardization:** `ltms_dashboard_nonce` (dashboard), `ltms_ux_nonce` (storefront), `ltms_admin_nonce` (admin), `ltms_auth_nonce` (auth)
- **Custom Tables:** `lt_backorder_subscriptions`, `lt_review_votes`
- **KYC Expiry Cron:** Daily reminder for expiring KYC documents

---

## 📊 Vendor Dashboard Views (25 total, v2.9.98)

| View | Conditional | Description |
|------|-------------|-------------|
| home | Always | KPIs, sales chart, recent orders, top products, onboarding checklist |
| orders | Always | KPIs, search, date range, skeleton loading, CSV export |
| products | Always | Pagination, search, gallery upload (5 imgs), ReDi toggle, quick edit |
| envios | Always | Shipping labels, carrier selection, WCAG 2.1 AA delete modal |
| shipping-statement | Always | Absorbed shipping ledger, budget progress, CSV export |
| wallet | Always | Balance, transactions, tax breakdown, CSV export, payout request |
| **insurance** | Always | XCover policies, KPIs, filters, CSV export (v2.9.97) |
| **drivers** | Own-delivery or drivers ≥1 | Driver fleet CRUD, KPIs, inline DOM updates (v2.9.98) |
| bookings | Always | Calendar view, seasons, policies, CSV export |
| marketing | Always | Banner management with download tracking |
| security | Always | TOTP 2FA enrollment, backup codes |
| donations | Always | Transparency dashboard |
| posgold | Always | Catalog sync, credentials test, scheduled sync |
| settings | Always | Vacation mode, store logo, schedule, social links |
| redi | `ltms_redi_enabled = 'yes'` | Reverse logistics, pause/resume |
| incidents | `ltms_redi_enabled = 'yes'` | Incident management with SLA |
| kitchen | `ltms_is_restaurant = 'yes'` | KDS with audio alerts, polling |
| ordenes-compra | `ltms_ordenes_compra_enabled = 'yes'` | Aveonline OC management |
| analytics | `ltms_vendor_premium` role only | Advanced analytics with charts |
| kyc | Always (shortcode) | KYC document submission |
| sellers-landing | Always (shortcode) | Public seller landing page |
| aveonline-onboarding | Always (shortcode) | Aveonline onboarding wizard |
| form-register | Always (shortcode) | Vendor registration wizard |
| form-login | Always (shortcode) | Vendor login form |
| store | Always (shortcode) | Public vendor storefront |
