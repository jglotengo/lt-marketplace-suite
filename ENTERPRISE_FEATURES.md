# LT Marketplace Suite — Enterprise Features

**Version:** 1.5.0

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

All clients extend `LTMS_Abstract_Api_Client` implementing `LTMS_Api_Client_Interface`.
Created via `LTMS_Api_Factory::get('provider')`.

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
