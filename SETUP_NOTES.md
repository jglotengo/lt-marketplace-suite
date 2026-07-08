# LTMS Setup Notes — 2026-07-08

> Notas operativas de la instancia de producción de LT Marketplace Suite v2.9.98.
>
> **Histórico:** Este documento se actualizó desde v2.9.35 (2026-07-06) hasta v2.9.98 (2026-07-08), reflejando 3 auditorías completas (REG-AUDIT-001, DEEP-AUDIT-002, UIUX-AUDIT-001) y 60+ commits de mejoras.

---

## Estado BD (v2.9.98)

### Tablas existentes (verificadas en producción)

- `lt_commissions`: 9 filas (6 CO + 3 MX prueba) — v2.9.35 añade 11 columnas SAT México (cfdi_uuid, cfdi_serie, cfdi_folio, rfc_emisor, rfc_receptor, regimen_fiscal, uso_cfdi, forma_pago, metodo_pago, fecha_certificacion, estado_cfdi)
- `lt_mx_isr_tramos`: 4 tramos vigentes Art. 113-A
- `lt_mx_ieps_rates`: 7 categorías LIEPS
- `lt_co_reteica_rates`: 10 ciudades CO
- `lt_vendor_kyc`: vendor 30 (friDker) — pending
- `lt_vendor_wallets`: 46 wallets
- `lt_payout_requests`: 13 solicitudes
- `lt_posgold_sync_log` (v2.9.35): vacío hasta que un vendor sincronice por primera vez
- `lt_security_events` (v2.9.35): incluye eventos TOTP 2FA (enrollment, challenge failure, recovery code usage)
- `lt_vendor_drivers` (v2.9.98): own-delivery fleet — vacío hasta que un vendor agregue repartidores
- `lt_insurance_policies` (v2.9.35): XCover policies — vacío hasta que un cliente contrate seguro
- `lt_shipping_cost_ledger`: absorbed shipping costs ledger
- `lt_backorder_subscriptions` (v2.9.61 P2-24): back-in-stock notification subscriptions
- `lt_review_votes` (v2.9.61 P2-25): review helpfulness votes
- `lt_consent_log`: Habeas Data consent audit (Ley 1581/2012)
- `lt_rate_limits`: atomic rate limiting records (v2.9.60 REG-01/02)
- `lt_api_logs`: external API call audit log
- `lt_webhook_logs`: webhook delivery audit log
- `lt_job_queue`: background job queue
- `lt_tax_reports`: generated fiscal reports
- `lt_deposits`: deposit records

### User Meta Keys Nuevas (v2.9.60-98)

- `ltms_totp_secret` — TOTP 2FA secret (AES-256-GCM encrypted)
- `ltms_totp_recovery_hashes` — 10 backup codes (bcrypt cost 12)
- `ltms_own_delivery_price` — own-delivery price (COP)
- `ltms_own_delivery_eta_minutes` — estimated delivery time
- `ltms_own_delivery_zones` — coverage zones (free text)
- `ltms_own_delivery_message` — customer message
- `ltms_is_restaurant` — `'yes'` enables Kitchen Display tab
- `_ltms_drivers_count_cache` — cached driver count for nav visibility (v2.9.98)
- `ltms_store_name` — vendor store name
- `ltms_store_logo` — store logo URL
- `ltms_vacation_mode` — `'yes'` hides products from storefront
- `ltms_store_schedule` — JSON array of per-day open/close times
- `ltms_social_instagram`, `ltms_social_facebook`, `ltms_social_whatsapp` — social links

---

## Migraciones DB

### v2.9.35
- `ALTER TABLE lt_commissions ADD COLUMN IF NOT EXISTS cfdi_uuid VARCHAR(64) ...` (11 columnas, idempotente)
- Verificar: `wp db query 'DESCRIBE lt_commissions;' --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html | grep -E 'cfdi|rfc|regimen|uso_cfdi|forma_pago|metodo_pago|fecha_certificacion|estado_cfdi'`

### v2.9.60-98 (idempotentes, auto-ejecutadas en activación)
- `CREATE TABLE IF NOT EXISTS lt_vendor_drivers` (v2.9.98)
- `CREATE TABLE IF NOT EXISTS lt_backorder_subscriptions` (v2.9.61 P2-24)
- `CREATE TABLE IF NOT EXISTS lt_review_votes` (v2.9.61 P2-25)
- `CREATE TABLE IF NOT EXISTS lt_consent_log` (Habeas Data)
- Verificar tablas nuevas:
  ```bash
  wp db query 'SHOW TABLES LIKE "bkr_lt_vendor_drivers";' --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html
  wp db query 'SHOW TABLES LIKE "bkr_lt_insurance_policies";' --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html
  wp db query 'SHOW TABLES LIKE "bkr_lt_shipping_cost_ledger";' --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html
  wp db query 'SHOW TABLES LIKE "bkr_lt_backorder_subscriptions";' --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html
  wp db query 'SHOW TABLES LIKE "bkr_lt_review_votes";' --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html
  ```

---

## Crons (v2.9.98)

| Hook | Schedule | Descripción | Versión |
|------|----------|-------------|---------|
| `ltms_retention_sweep` | daily | Clean expired transients/rate limits | original |
| `ltms_daily_maintenance` | daily | General maintenance tasks | original |
| `ltms_daily_payout_processor` | daily 02:00 | Process pending payouts | original |
| `ltms_weekly_tax_report` | weekly Monday | Generate fiscal reports | original |
| `ltms_hourly_waf_cleanup` | hourly | Clean expired WAF blocks | original |
| `ltms_sync_commission_rates` | daily 06:00 | Sync rates from config | original |
| `ltms_posgold_scheduled_sync` | daily 03:00 | Sync PosGold catalog for enrolled vendors | v2.9.35 |
| `ltms_kyc_expiry_reminder` | daily 09:00 | Send KYC expiry reminder emails | v2.9.61 P3-12 |

Verificar crons:
```bash
wp cron event list --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html | grep ltms
```

---

## APIs configuradas

| Servicio | Estado | Notas |
|----------|--------|-------|
| OpenPay (MX) | ✅ Configurada | `mjnd8chjd6ujvwstd57k` |
| OpenPay (CO) | ✅ Configurada | |
| Stripe | ✅ Configurada | Pagos internacionales |
| Backblaze B2 | ✅ Configurada | `0054d7a9c46fe290000000001` — storage KYC docs |
| XCover | ✅ Configurada | Insurance policies |
| PosGold | ⚠️ Por vendor | Configurar `ltms_posgold_api_url` + `ltms_posgold_api_token` en `view-posgold.php` |
| Aveonline | ✅ Configurada | Logistics CO |
| Siigo | ✅ Configurada | E-invoicing CO (DIAN) |
| Addi | ✅ Configurada | BNPL CO/MX |
| ZapSign | ✅ Configurada | E-signatures |
| TPTC | ✅ Configurada | MLM network sync |
| ReDi | ✅ Configurada | Reverse logistics (toggle `ltms_redi_enabled = 'yes'`) |
| Cloudflare Turnstile | ⚠️ Opcional | Bot protection — configurar `ltms_turnstile_enabled`, site key, secret key |
| Google OAuth | ⚠️ Opcional | Login con Google — configurar client ID + secret |

---

## Nonce Actions Estandarizados (v2.9.61 P3-5)

| Contexto | Nonce Action |
|----------|-------------|
| Vendor dashboard | `ltms_dashboard_nonce` |
| Storefront | `ltms_ux_nonce` |
| Admin panel | `ltms_admin_nonce` |
| Auth (login/register/2FA/OAuth) | `ltms_auth_nonce` |

> ⚠️ **NO** uses `ltms_storefront_nonce` (era el nombre antiguo, ya no funciona — todos los AJAX del storefront retornarían 403).

---

## Endpoints AJAX (acumulativos)

### v2.9.35 (6 nuevos)
| Action | Propósito | Nonce |
|--------|-----------|-------|
| `ltms_backorder_notify` | Notificar al cliente cuando un producto vuelva a tener stock | `ltms_ux_nonce` |
| `ltms_get_invoices` | Listar facturas del vendedor | `ltms_ux_nonce` |
| `ltms_review_helpful` | Votar si una reseña fue útil | `ltms_ux_nonce` |
| `ltms_save_push_subscription` | Guardar suscripción Web Push (VAPID) | `ltms_ux_nonce` |
| `ltms_submit_question` | Enviar pregunta Q&A sobre un producto | `ltms_ux_nonce` |
| `ltms_submit_return` | Solicitar devolución de producto | `ltms_ux_nonce` |

### v2.9.60 REG-AUDIT-001 (registro)
- `ltms_register_vendor` — registro de vendedor (con Turnstile, honeypot, rate limiting)
- `ltms_google_oauth` — login con Google + profile completion
- `ltms_resend_verification` — reenviar email de verificación
- `ltms_validate_phone_e164` — validación teléfono E.164

### v2.9.61 DEEP-AUDIT-002 (security hardening)
- `ltms_submit_kyc` — KYC submission (con IDOR protection)
- `ltms_upload_kyc_document` — upload KYC doc (con ownership verification)
- `ltms_request_payout` — payout request (con bank validation)
- `ltms_2fa_verify` — TOTP verification (con rate limiting)
- `ltms_2fa_recovery` — recovery code usage

### v2.9.98 (drivers + insurance)
- `ltms_save_driver` — crear/editar repartidor (con ownership + AES-256 encrypt)
- `ltms_delete_driver` — eliminar repartidor (con ownership)
- `ltms_toggle_driver_active` — toggle status active/inactive
- `ltms_toggle_driver_available` — toggle disponibilidad (transient)
- `ltms_save_delivery_settings` — guardar config de entrega propia
- `ltms_get_insurance_data` — listar pólizas XCover

---

## Vistas del Dashboard (25 total, v2.9.98)

### v2.9.35 (4 nuevas)
| Vista | Archivo | Propósito |
|-------|---------|-----------|
| Marketing | `view-marketing.php` | Gestión de banners promocionales |
| Security | `view-security.php` | TOTP 2FA + códigos de recuperación |
| Donations | `view-donations.php` | Transparencia de donaciones |
| PosGold | `view-posgold.php` | Sincronización de catálogo PosGold |

### v2.9.77-98 UIUX-AUDIT-001 (expansiones mayores)
| Vista | Líneas (antes → después) | Cambios principales |
|-------|--------------------------|---------------------|
| view-home.php | 105 → expandido | KPIs, widgets pedidos recientes + top productos, onboarding checklist |
| view-orders.php | 64 → expandido | KPIs, search, date range, skeleton loading, empty state SVG |
| view-products.php | 655 → expandido | Pagination, search, gallery upload (5 imgs), ReDi toggle SPA |
| view-wallet.php | 388 → expandido | Tax breakdown, CSV export, bank account masking |
| view-shipping-statement.php | 222 → 257 | `data-action` CSP, CSV export, progress bar |
| view-insurance.php | 113 → 365 | KPIs, filtros, búsqueda, CSV export, empty state SVG |
| view-drivers.php | 226 → 744 | KPIs, search, edit, delete modal, inline DOM updates, empty state SVG |
| view-bookings.php | 841 → expandido | Calendar view, 3 tabs, CSV export |
| view-kitchen.php | 79 → expandido | Audio alerts, polling 10s, KPIs, action buttons |
| view-settings.php | 350 → expandido | Vacation mode, store logo, schedule, social links |
| view-sellers-landing.php | 114 → expandido | Testimonials, calculator, FAQ |
| view-redi.php | 388 → expandido | SPA puro (0 reloads) |
| dashboard-wrapper.php | 260 → 447 | SVG icons, mobile bottom nav, dark mode, keyboard shortcuts, skip-link, Seguros + Domiciliarios nav tabs |

---

## Shortcodes (v2.9.98)

| Shortcode | Vista | Versión |
|-----------|-------|---------|
| `[ltms_vendor_dashboard]` | SPA completo (25 vistas) | original |
| `[ltms_vendor_login]` | form-login.php | original |
| `[ltms_vendor_register]` | form-register.php (3-step wizard) | original |
| `[ltms_vendor_store]` | vendor storefront | original |
| `[ltms_vendor_orders]` | view-orders.php standalone | original |
| `[ltms_vendor_wallet]` | view-wallet.php standalone | original |
| `[ltms_vendor_kyc]` | view-kyc.php standalone | original |
| `[ltms_vendor_insurance]` | view-insurance.php standalone | original |
| `[ltms_vendor_bookings]` | view-bookings.php standalone | v2.9.35 |
| `[ltms_vendor_rnt]` | RNT/SECTUR form | v2.9.35 |
| `[ltms_vendor_drivers]` | view-drivers.php standalone | **v2.9.98 (nuevo)** |

---

## Bug Fixes Históricos (resumen)

### v2.9.35
| Issue | Causa | Fix |
|-------|-------|-----|
| WSOD en admin Security page | Falta `<?php` al inicio del template | Tag de apertura añadido |
| `LTMS_PATH` undefined | Constante inexistente | Migración a `LTMS_PLUGIN_DIR` |
| `derive_key()` declared twice | Duplicado en `class-ltms-security.php` | Declaración duplicada eliminada |
| `continue 2` illegal en `logistics-compliance.php` | Loop de 1 solo nivel | Cambiado a `continue;` |
| `get_client_ip()` WSOD desde `LTMS_Data_Masking` | Método `private` | Cambiado a `public` |
| Cross-Border settings section not found | Slug `cross-border` vs `cross_border` | Slug normalizado |
| `e.target.closest is not a function` | `e.target` es text node | Guard `e.target.nodeType === 1` añadido |
| Submenu "Logística / Costos" duplicado | Registro doble en `add_submenu_page()` | Segundo registro eliminado |
| Toaster "Algo salió mal" en cada AJAX | `SHOW_ERROR_TOASTS=false` | Deshabilitados globalmente |
| Composer install failure | `dompdf ^2.0.9` no existe | Cambiado a `^2.0` |
| 35+ clases missing from autoloader | Classmap incompleto | Entradas añadidas a `$exceptions_npart` |
| `.min.css` ignorados por git | Estaban en `.gitignore` | Removidos del `.gitignore`, force-tracked |

### v2.9.36-59 (cart + cookie banner + perf)
- Cart drawer subtotal HTML crudo → `html_entity_decode()` + `innerHTML`
- Cart drawer +/- buttons no funcionaban → nonce fix + parámetros correctos
- Guests no podían usar cart drawer → `wp_ajax_nopriv_*`
- SiteGround removía scripts inline → output buffering
- JS viejo causaba doble-binding → NO-OP en funciones viejas
- Cookie banner texto HTML crudo → JS inline unificado
- 40+ errores de sintaxis en `.min.js` → arreglados
- CAPI async, cart drawer skip upsells, bundle discount O(N)→O(1)

### v2.9.60 REG-AUDIT-001 (registro vendedores)
- Google OAuth nonce fix (`ltms_admin_nonce` → `ltms_auth_nonce`)
- Rate limiting atómico (`INSERT ON DUPLICATE KEY UPDATE`)
- E.164 phone validation
- Whitelists: business_type, document_type, vendor_country
- HTML email templates for KYC
- `wp_login_url()` for verification URL
- `set_role()` → `add_role()`
- Cloudflare Turnstile (optional)
- Admin notification on new registration
- Resend verification endpoint
- Google OAuth profile completion

### v2.9.61-77 DEEP-AUDIT-002 (56 findings, 100% P0+P1+P2)
- P0: ReDi pause/resume, PosGold token masking, Aveonline OC access, KYC IDOR, bank account decrypt
- P1: 2FA rate limit, nopriv abuse vectors, payout bank validation, PosGold SSRF, KYC doc validation
- P2: Custom tables (backorder + review votes), bank account sync, PosGold cron, onboarding store check
- P3: Dead code cleanup, nonce standardization, PosGold JSON categories, KYC expiry cron

### v2.9.77-98 UIUX-AUDIT-001 (62 findings, 100% resueltos)
- 7 P0: SPA reloads, PII leak, memory leak, undefined var, broken metrics, no progress UI, no a11y
- 15 P1: gallery upload, CSP compliance, toast system, mobile nav, bell a11y, global search, etc.
- 22 P2: store schedule, social links, breadcrumbs, dark mode, CSV export, home widgets, etc.
- 13 P3: SVG icons, keyboard help, skip-link, focus-visible, localized dates, etc.

---

## Post-deploy obligatorio (v2.9.98)

```bash
# 1. Limpiar cache del SiteGround Optimizer
rm -rf /home/customer/www/lo-tengo.com.co/public_html/wp-content/uploads/siteground-optimizer-assets/*
rm -rf /home/customer/www/lo-tengo.com.co/public_html/wp-content/cache/supercache/*

# 2. Flush object cache de WordPress
wp cache flush --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html

# 3. Flush OPcache (pool web en SiteGround — preferido vía HTTP)
curl 'https://lo-tengo.com.co/wp-content/plugins/lt-marketplace-suite/deploy/ltms-opcache-flush.php?token=ltms_opcache_2026'

# 4. Verificar tablas DB nuevas
wp db query 'SHOW TABLES LIKE "bkr_lt_vendor_drivers";' --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html
wp db query 'SHOW TABLES LIKE "bkr_lt_insurance_policies";' --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html

# 5. Verificar migración SAT México
wp db query 'DESCRIBE lt_commissions;' --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html | grep -E 'cfdi|rfc|regimen'

# 6. Verificar crons
wp cron event list --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html | grep ltms

# 7. Verificar versión del plugin
grep "Version:" /home/customer/www/lo-tengo.com.co/public_html/wp-content/plugins/lt-marketplace-suite/lt-marketplace-suite.php
# Debe mostrar: 2.9.98

# 8. Verificar CSP compliance (0 inline handlers)
grep -rn 'onclick=\|onchange=\|onfocus=\|onsubmit=\|onload=' /home/customer/www/lo-tengo.com.co/public_html/wp-content/plugins/lt-marketplace-suite/includes/frontend/views/
# Debe devolver 0 resultados
```

---

## Configuración Opcional (v2.9.60+)

### Cloudflare Turnstile (bot protection)
1. Crear cuenta en Cloudflare → Turnstile
2. Obtener Site Key + Secret Key
3. En admin: LT Marketplace > Configuración > Security:
   - `ltms_turnstile_enabled` = `yes`
   - `ltms_turnstile_site_key` = `<site-key>`
   - `ltms_turnstile_secret_key` = `<secret-key>`

### Google OAuth (login con Google)
1. Crear proyecto en Google Cloud Console
2. Configurar OAuth consent screen
3. Crear credenciales OAuth 2.0 Client ID
4. En admin: LT Marketplace > Configuración > Auth:
   - `ltms_google_client_id` = `<client-id>`
   - `ltms_google_client_secret` = `<client-secret>`

### ReDi (reverse logistics)
1. Contactar ReDi para obtener credenciales
2. En admin: LT Marketplace > Configuración > Logística:
   - `ltms_redi_enabled` = `yes`
   - Configurar credenciales ReDi

### Kitchen Display (restaurantes)
- Por vendor: `update_user_meta($vendor_id, 'ltms_is_restaurant', 'yes')` → habilita tab "Cocina"

### Ordenes de Compra Aveonline
- En admin: `update_option('ltms_ordenes_compra_enabled', 'yes')` → habilita tab "Órdenes de Compra"
