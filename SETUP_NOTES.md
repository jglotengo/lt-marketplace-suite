# LTMS Setup Notes — 2026-07-06

> Notas operativas de la instancia de producción de LT Marketplace Suite v2.9.35.

## Estado BD

- `lt_commissions`: 9 filas (6 CO + 3 MX prueba) — v2.9.35 añade 11 columnas SAT México (cfdi_uuid, cfdi_serie, cfdi_folio, rfc_emisor, rfc_receptor, regimen_fiscal, uso_cfdi, forma_pago, metodo_pago, fecha_certificacion, estado_cfdi)
- `lt_mx_isr_tramos`: 4 tramos vigentes Art. 113-A
- `lt_mx_ieps_rates`: 7 categorías LIEPS
- `lt_co_reteica_rates`: 10 ciudades CO
- `lt_vendor_kyc`: vendor 30 (friDker) — pending
- `lt_vendor_wallets`: 46 wallets
- `lt_payout_requests`: 13 solicitudes
- `lt_posgold_sync_log` (v2.9.35): vacío hasta que un vendor sincronice por primera vez
- `lt_security_events` (v2.9.35): incluye eventos TOTP 2FA (enrollment, challenge failure, recovery code usage)

## Migraciones v2.9.35

- `ALTER TABLE lt_commissions ADD COLUMN IF NOT EXISTS cfdi_uuid VARCHAR(64) ...` (11 columnas, idempotente)
- Verificar: `wp db query 'DESCRIBE lt_commissions;' --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html | grep -E 'cfdi|rfc|regimen|uso_cfdi|forma_pago|metodo_pago|fecha_certificacion|estado_cfdi'`

## Crons

- `ltms_retention_sweep`: daily
- `ltms_daily_maintenance`: daily
- `ltms_posgold_scheduled_sync` (v2.9.35, nuevo): daily 03:00 — sincronización automática de catálogo PosGold para vendors con sync programada

## APIs configuradas

- OpenPay: mjnd8chjd6ujvwstd57k (MX)
- Backblaze B2: 0054d7a9c46fe290000000001
- xCover: configurada
- **PosGold (v2.9.35)**: configurar `ltms_posgold_api_url` + `ltms_posgold_api_token` por vendor en `view-posgold.php`

## Endpoints AJAX v2.9.35 (6 nuevos)

| Action | Propósito | Nonce |
|--------|-----------|-------|
| `ltms_backorder_notify` | Notificar al cliente cuando un producto vuelva a tener stock | `ltms_ux_nonce` |
| `ltms_get_invoices` | Listar facturas del vendedor | `ltms_ux_nonce` |
| `ltms_review_helpful` | Votar si una reseña fue útil | `ltms_ux_nonce` |
| `ltms_save_push_subscription` | Guardar suscripción Web Push (VAPID) | `ltms_ux_nonce` |
| `ltms_submit_question` | Enviar pregunta Q&A sobre un producto | `ltms_ux_nonce` |
| `ltms_submit_return` | Solicitar devolución de producto | `ltms_ux_nonce` |

> ⚠️ El action de nonce correcto es `ltms_ux_nonce`. **NO** uses `ltms_storefront_nonce` (era el nombre antiguo, ya no funciona — todos los AJAX del storefront retornarían 403).

## Vistas del dashboard de vendedor (v2.9.35 — 4 nuevas)

| Vista | Archivo | Propósito |
|-------|---------|-----------|
| Marketing | `includes/frontend/views/view-marketing.php` | Gestión de banners promocionales |
| Security | `includes/frontend/views/view-security.php` | TOTP 2FA + códigos de recuperación |
| Donations | `includes/frontend/views/view-donations.php` | Transparencia de donaciones |
| PosGold | `includes/frontend/views/view-posgold.php` | Sincronización de catálogo PosGold |

## Bug fixes v2.9.35 (resumen)

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
| PHP code visible on admin Security page | Missing `<?php` tag | Tag de apertura añadido |
| Toaster "Algo salió mal" en cada AJAX | `SHOW_ERROR_TOASTS=false` + `SHOW_AJAX_ERROR_TOASTS=false` | Deshabilitados globalmente |
| Product page button deformed | CSS `.single_add_to_cart_button` sin `min-width` | Regla CSS añadida |
| Quantity field too small | CSS `input.qty` sin `width: 60px` | Regla CSS añadida |
| Upsell items as giant buttons | CSS `.upsells .button` con `width: 100%` | Cambiado a `width: auto` |
| Composer install failure | `dompdf ^2.0.9` no existe en packagist | Cambiado a `^2.0` |
| 35+ clases missing from autoloader | Classmap incompleto | Entradas añadidas a `$exceptions_npart` |
| `.min.css` ignorados por git | Estaban en `.gitignore` | Removidos del `.gitignore`, force-tracked |

## Post-deploy obligatorio

```bash
# 1. Limpiar cache del SiteGround Optimizer
rm -rf wp-content/uploads/siteground-optimizer-assets/*

# 2. Flush object cache de WordPress
wp cache flush --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html

# 3. Flush OPcache (pool web en SiteGround — preferido vía HTTP)
curl 'https://lo-tengo.com.co/wp-content/plugins/lt-marketplace-suite/deploy/ltms-opcache-flush.php?token=ltms_opcache_2026'

# 4. Verificar migración SAT México
wp db query 'DESCRIBE lt_commissions;' --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html | grep -E 'cfdi|rfc|regimen'
```
