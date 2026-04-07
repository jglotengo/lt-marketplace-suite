# Changelog — LT Marketplace Suite

All notable changes to this project are documented in this file.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
This project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] — 2026-04-07

### Added
- **Módulo de Reservas ACID**: `LTMS_Booking_Manager` con `START TRANSACTION` + `SELECT…FOR UPDATE` para eliminar doble-booking
- **Producto Bookable**: Tipo WooCommerce personalizado `ltms_bookable` (alojamiento, experiencia, renta, restaurante…)
- **Calendario Frontend**: Flatpickr range picker con precios dinámicos por temporada vía REST API
- **Temporadas de precio**: `LTMS_Booking_Season_Manager` — reglas globales y por producto; semillas CO/MX
- **Políticas de cancelación**: `LTMS_Booking_Policy_Handler` — flexible, moderate, strict, non_refundable
- **Compliance Turístico**: RNT (FONTUR Colombia, Ley 2068/2020) + SECTUR México con panel admin y formulario My Account
- **Panel admin Reservas**: Tabla filtrable, calendario FullCalendar 6.x, export CSV, cancelación con reembolso automático
- **6 Cron Jobs**: cleanup pending, check-in reminders, balance reminders, auto-checkout, RNT expiry, deposit release
- **Módulo Envíos v2**: Modo `absorbed` con `LTMS_Shipping_Method_Free_Absorbed` + `get_cheapest_quote()`; debit de billetera en orden pagada
- **SEO Técnico**: Schema.org Product/Organization, Open Graph, Twitter Card, Google Search Console verification
- **Sitemap XML**: `/ltms-sitemap.xml` con productos, tiendas y páginas del plugin
- **Analytics Unificado**: GTM o GA4+Meta Pixel (plataforma + nivel vendedor); GA4 ecommerce events
- **Geolocalización**: ip-api.com sin API key, caché 24h, URLs SEO `/productos/{ciudad}/`
- **CI/CD GitHub Actions**: lint + PHPStan + PHPUnit + release ZIP automático en tag
- **10 plantillas de email**: booking confirmed/cancelled/pending/checkin-reminder/balance-reminder, vendor-new, rnt-approved/rejected/expiry, deposit-released
- **9 tests unitarios** con Brain\Monkey
- **5 tablas de BD**: `lt_bookings`, `lt_booking_slots`, `lt_booking_policies`, `lt_tourism_compliance`, `lt_booking_season_rules`
- `bin/version-bump.php`, `bin/install-wp-tests.sh`, `phpunit.xml`, `phpstan.neon`

### Changed
- `LTMS_VERSION` y `LTMS_DB_VERSION` de 1.7.3 → **2.0.0**
- Kernel carga condicional de todos los módulos nuevos
- `LTMS_Core_Activator` incluye todos los defaults de configuración v2.0.0

### Fixed
- `LTMS_Shipping_Parallel_Quoter::get_cheapest_quote()` ahora es público
- `LTMS_Order_Paid_Listener` debita el costo de envío absorbido de la billetera del vendedor tras el pago

---

## [1.7.0] — 2026-03-24

### Added
- **Stripe Payment Gateway** (`LTMS_Gateway_Stripe`) — full WooCommerce gateway with Stripe Elements
  client-side tokenization, 3DS redirect support, test/live key toggle, and webhook handler
  (`POST /wp-json/ltms/v1/webhooks/stripe`).
- **Stripe API client** (`LTMS_Api_Stripe`) — wraps Stripe PHP SDK; supports PaymentIntent,
  Refund, Customer, Connect account, and Transfer operations.
- **Payment Orchestrator** (`LTMS_Payment_Orchestrator`) — intelligent routing between Stripe
  and Openpay based on payment type (PSE/Nequi/OXXO/SPEI → Openpay exclusive); circuit breaker
  pattern auto-trips after 3 consecutive errors within 1 hour, routes to fallback gateway.
- **Provider Health Dashboard** (`html-admin-provider-health.php`) — real-time uptime cards for
  all 6 providers (stripe, openpay, addi, aveonline, heka, uber); circuit breaker reset button;
  last-50-events table from `lt_provider_health`.
- **Parallel Shipping Quoter** (`LTMS_Shipping_Parallel_Quoter`) — fetches Aveonline, Heka and
  Uber Direct rates simultaneously via `curl_multi_exec` with configurable 3 s timeout; applies
  "Mejor precio" and "Más rápido" badges; caches results in `lt_shipping_quotes_cache`.
- **Own Delivery Shipping Method** (`LTMS_Shipping_Method_Own_Delivery`) — vendor-operated
  couriers; only visible in checkout when vendor has ≥ 1 active + available driver in
  `lt_vendor_drivers`; price, ETA, zones and message fully configurable per-vendor.
- **Driver Management Panel** (`view-drivers.php`) — vendor-side SPA view for CRUD of
  delivery drivers; toggle active/available; document number and vehicle plate stored AES-256.
- **Commission Tiers Admin** (`html-admin-commission-tiers.php`) — full CRUD for
  `lt_commission_tiers` table; rates now driven by DB instead of hardcoded constants.
- **Fiscal Colombia Panel** (`html-admin-fiscal-colombia.php`) — configurable UVT, IVA,
  ReteFuente (honorarios / servicios / compras / tech), ReteIVA, Impoconsumo, SAGRILAFT
  threshold (UVT × n); all changes recorded in `lt_tax_rates_history`.
- **Fiscal México Panel** (`html-admin-fiscal-mexico.php`) — configurable IVA general / frontera,
  ISR Art. 113-A tramos (CRUD), IEPS by product category (CRUD), Retención IVA PM.
- **Tax Rate History View** (`html-admin-tax-history.php`) — immutable audit log of all tax
  rate changes with country, key, old/new value, decree reference, and author.
- **Auto-pages management** (`html-admin-pages.php`) — shows status of 8 required plugin pages;
  "Recreate" action via `admin-post`.
- **Uninstall script** (`uninstall.php`) — 3-level uninstall:
  - Level 1 (default): deactivate only, no data removed.
  - Level 2: removes options, transients, installed pages, and custom roles.
  - Level 3 (opt-in via `LTMS_UNINSTALL_DELETE_ALL_DATA=true`): creates SQL backup in
    `wp-content/ltms-backup-{timestamp}.sql`, then drops all `lt_*` tables and log files.
- **7 new database tables** (v1.7.0 migration):
  `lt_provider_health`, `lt_vendor_drivers`, `lt_commission_tiers`,
  `lt_tax_rates_history`, `lt_mx_ieps_rates`, `lt_mx_isr_tramos`, `lt_co_reteica_rates`.
- **Stripe Elements JS** (`ltms-stripe.js`) — mounts card element on checkout, re-mounts after
  WC AJAX refresh, intercepts form submit to call `createPaymentMethod` before server POST.
- GOB-002: admin notice prompts to configure real server cron if `DISABLE_WP_CRON` is not set.

### Changed
- **Commission rates** are now read from `lt_commission_tiers` via DB query instead of
  hardcoded `if/else` tiers in `LTMS_Commission_Strategy`.
- **Colombian tax rates** (UVT, IVA, ReteFuente thresholds, etc.) read from
  `LTMS_Core_Config::get()` → WordPress options instead of PHP `private const`.
- **Mexican tax rates** (IVA, ISR Art. 113-A, IEPS, Retención IVA PM) read from options and
  `lt_mx_ieps_rates` / `lt_mx_isr_tramos` tables instead of hardcoded arrays.
- **SAGRILAFT alert threshold** in the auditor dashboard now computed as
  `UVT × ltms_sagrilaft_uvt_threshold` (default 10 000 UVT ≈ $497 990 000 COP, 2025)
  instead of the previous hardcoded `100000000`.
- **WAF block duration** and **IP cache TTL** configurable via
  `ltms_waf_block_duration_seconds` and `ltms_waf_ip_cache_ttl_seconds` options.
- **KYC file size limit** configurable via `ltms_kyc_max_file_size_mb` (default 10 MB);
  allowed MIME types configurable via `ltms_kyc_allowed_mime_types`.
- **Vault signed-URL TTL** configurable via `ltms_vault_signed_url_ttl_seconds` (default 300 s).
- **Abstract API Client** timeout / max-retries / retry-delay now configurable via
  `ltms_api_timeout_seconds`, `ltms_api_max_retries`, `ltms_api_retry_delay_seconds`.
- `LTMS_VERSION` and `LTMS_DB_VERSION` bumped to `1.7.0`.
- `lt-marketplace-suite.php` visibility fix: main plugin file uses `LTMS_VERSION` constant.

### Fixed
- VUL-003: replaced raw `LIKE` query strings with `$wpdb->prepare()` + `$wpdb->esc_like()`.
- `LTMS_Deactivator::deactivate()` now uses `$wpdb->prepare()` for all direct DB queries.

### Security
- Driver PII (document number, vehicle plate) encrypted with `LTMS_Encryption::encrypt()` before
  DB insert; decrypted on read.
- Stripe webhook endpoint validates `Stripe-Signature` via HMAC-SHA256 before processing events.
- Payment Orchestrator records every gateway attempt in `lt_provider_health` for forensic audit.
- **C-01** — IP spoofing via `X-Forwarded-For` fixed: WAF now only trusts proxy headers when
  `REMOTE_ADDR` is in `LTMS_TRUSTED_PROXY_IPS`; CIDR range support added.
- **C-02** — Uber Direct webhook accepted unsigned requests when secret was unconfigured; now
  returns 401 immediately if secret is empty.
- **C-03** — WAF blind spot: `php://input` JSON body now scanned for attack patterns.
- **H-01** — `document`, `document_number`, `nit`, `rfc`, `curp`, `cedula` added to API log
  redaction list in `LTMS_Abstract_API_Client`.
- **H-03** — Frozen wallet now blocks `hold` and `adjustment` operations (previously only
  blocked `debit` and `payout`).
- **H-05** — SSL verification now always enabled; disable only via explicit
  `LTMS_DISABLE_SSL_VERIFY` constant (never auto-disabled in non-production).
- **H-06/H-07** — Double-prepare SQLi pattern fixed in notifications handler and payout export:
  both now use a single fully-parameterized `$wpdb->prepare()` call.
- **L-01** — PBKDF2 key derivation iterations increased from 10,000 to 600,000 (NIST SP 800-132).
- **L-02** — HMAC salt now cascades `SECURE_AUTH_SALT` → `AUTH_SALT` → `AUTH_KEY` → derived;
  hardcoded fallback string removed.
- **L-06** — Auditor access IP now resolved via `LTMS_Firewall::get_client_ip()` instead of raw
  `REMOTE_ADDR`, ensuring accurate forensic logs behind proxies.
- **L-07** — Stripe webhook now returns 401 immediately when `webhook_secret` is unconfigured.
- **M-07** — CSV export guards formula-injection characters (`=`, `+`, `-`, `@`) in all fields.
- **M-08** — All static admin-security-log queries now use `$wpdb->prepare()`.
- `composer.json`: `firebase/php-jwt` pin widened from `"7.0"` (exact) to `"^7.0"` to receive
  patch-level security fixes; `ext-intl` added to required extensions.
- `wp-config-sample-snippet.php`: corrected constant name from `WP_LTMS_MASTER_KEY` to
  `LTMS_ENCRYPTION_KEY` (matching what `class-ltms-config.php` actually checks); added
  documentation for `LTMS_TRUSTED_PROXY_IPS`, `LTMS_DISABLE_SSL_VERIFY`, `LTMS_CHARTJS_SRI`.

---

## [1.6.0] — 2026-01-15

### Added
- ReDi reseller distribution system (Module 1): `lt_redi_agreements`, `lt_redi_commissions`,
  reseller adoption flow, multi-credit wallet split, origin stock deduction.
- Uber Direct logistics (Module 2): `LTMS_Api_Uber`, OAuth2 token cache, delivery CRUD,
  HMAC-SHA256 webhook handler.
- Heka logistics provider (Module 3): `LTMS_Api_Heka`, rate query, shipment creation, tracking.
- Physical Pickup shipping method (Module 3): `wc-ready-for-pickup` custom order status, vendor
  store info email, ICA municipality adjustment.
- Backblaze B2 storage (Module 4): `LTMS_Api_Backblaze` with AWS Sig V4, `LTMS_Media_Guard`
  vault rewrite rules, KYC upload pipeline, `lt_media_files` table.
- XCover insurance lifecycle (Module 5): checkout UI, `LTMS_XCover_Policy_Listener` on payment,
  cancellation on order cancel, `lt_insurance_policies` table.
- 5 new DB tables: `lt_media_files`, `lt_shipping_quotes_cache`, `lt_insurance_policies`,
  `lt_redi_agreements`, `lt_redi_commissions`.
- Shipping comparison UI (`ltms-shipping-selector.js`) — side-by-side quote cards in WC checkout.
- Admin views: XCover policies, ReDi agreements, Pickup orders.
- Vendor dashboard tabs: Insurance, ReDi.

---

## [1.5.0] — 2025-11-01

### Added
- Initial public release of LT Marketplace Suite.
- Multi-vendor WooCommerce marketplace with ACID wallet ledger.
- Colombian and Mexican tax engines (ReteFuente, ReteIVA, ReteICA, Impoconsumo, ISR, IVA, IEPS).
- SAGRILAFT / FATF compliance pipeline with KYC document management.
- CFDI 4.0 XML generation for Mexico.
- Openpay payment gateway (CO + MX).
- Addi BNPL gateway.
- MLM commission system (3 levels, configurable rates).
- WAF (SQL Injection, XSS, LFI, CSRF, Brute Force protection).
- AES-256-CBC encryption for PII fields.
- Role-based access control: `ltms_vendor`, `ltms_vendor_premium`,
  `ltms_external_auditor`, `ltms_compliance_officer`, `ltms_support_agent`.
- Hexagonal architecture: Core / Business / API / Admin / Frontend / Roles.
- Composer PSR-4 autoloader.
- Docker Compose dev environment.
- Audit log, security events, API log tables.
- Progressive Web App support (manifest + service worker).

---

*Generated by LT Marketplace Suite · https://github.com/jglotengo/lt-marketplace-suite*
