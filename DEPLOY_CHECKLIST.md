# Deploy Checklist — LT Marketplace Suite v2.9.35

Checklist paso a paso para desplegar la nueva capa UX de forma segura en producción.

> **Notas v2.9.35 (2026-07-06):** añadir verificaciones de endpoints AJAX nuevos (6), vistas nuevas del dashboard de vendedor (4), columnas SAT México (11) y límite de tamaño de `.min.js` para SiteGround.

## Pre-despliegue (en local / staging)

### 1. Verificación de build
- [ ] Ejecutar `node /home/z/my-project/scripts/build-minify.js` (sin `--sourcemap` para prod)
- [ ] Confirmar reducción de tamaño ~42.7% en los 3 archivos `.min.*`
- [ ] Verificar `node -c assets/js/ltms-ux-enhancements.min.js` — sintaxis OK
- [ ] Confirmar que NO se generaron `.map` files (los source maps no van a producción)

### 2. Verificación de archivos
- [ ] `assets/js/ltms-ux-enhancements.min.js` existe y pesa ~309 KB
- [ ] **VERIFICAR que `.min.js` NO pese más de 350 KB** (límite de memoria de SiteGround para assets combinados). Si excede, reducir módulos o dividir bundle.
- [ ] `assets/css/ltms-ux-enhancements.min.css` existe y pesa ~196 KB
- [ ] `assets/css/ltms-admin-ux.min.css` existe y pesa ~15 KB
- [ ] Versiones originales (sin `.min`) siguen presentes para fallback con `SCRIPT_DEBUG=true`
- [ ] `.min.js` y `.min.css` están **sincronizados** con sus fuentes `.js` / `.css` (mismo contenido, solo minificado)
- [ ] `.min.*` NO están en `.gitignore` (fueron removidos en v2.9.35, force-tracked)

### 3. Verificación de PHP
- [ ] `class-ltms-frontend-assets.php` incluye la lógica `$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';`
- [ ] `class-ltms-admin.php` incluye la lógica `$ux_suffix` para `ltms-admin-ux`
- [ ] `LTMS_VERSION` en `lt-marketplace-suite.php` = `2.9.35`
- [ ] `Version:` en header del plugin = `2.9.35`
- [ ] Constante `LTMS_PLUGIN_DIR` definida y usada (NO `LTMS_PATH` ni `LTMS_PLUGIN_DIR_PATH`)
- [ ] Nonce action `ltms_ux_nonce` usado en todos los handlers AJAX del storefront (NO `ltms_storefront_nonce`)
- [ ] `LTMS_Core_Firewall::get_client_ip()` declarado `public` (no `private`)
- [ ] No quedan `continue 2` en loops de 1 solo nivel

### 4. Verificación de plantillas PHP (data-* aplicados)
- [ ] `dashboard-wrapper.php` — botón `data-tour-start` presente en topbar
- [ ] `view-products.php` — `data-lightbox`, `data-quick-view`, `data-stock-level`, `data-export-table`
- [ ] `view-orders.php` — `data-search-autocomplete`, `data-export-table`
- [ ] `view-wallet.php` — `data-export-table`, `id="ltms-ledger-table"`
- [ ] `view-envios.php` — `id="ltms-envios-table"` + botón export
- [ ] `form-login.php` — `data-validate` en username + password, `data-strength`
- [ ] `form-register.php` — `data-validate` en 7 campos, `data-strength` en password
- [ ] `view-sellers-landing.php` — `data-share-buttons`, `data-accordion-group`

### 5. Verificación de emails
- [ ] Las 17 plantillas en `templates/emails/email-*.php` incluyen o usan `email-styles.php`
- [ ] Las 4 plantillas grandes tienen `$ltms_email_palettes_path = __DIR__ . '/email-styles.php';`
- [ ] Las plantillas pequeñas tienen `include __DIR__ . '/email-styles.php';`

## Despliegue (en producción)

### 6. Backup
- [ ] Backup completo de la base de datos (`wp ltms-backup` o `mysqldump`)
- [ ] Snapshot del directorio `wp-content/plugins/lt-marketplace-suite/` actual
- [ ] Confirmar que el rollback es posible en < 5 minutos

### 7. Subida de archivos
- [ ] Subir por SFTP/rsync:
  - `lt-marketplace-suite.php` (versión bump)
  - `includes/frontend/class-ltms-frontend-assets.php`
  - `includes/admin/class-ltms-admin.php`
  - `includes/frontend/views/*.php` (9 plantillas modificadas)
  - `templates/emails/email-*.php` (17 plantillas migradas)
  - `assets/js/ltms-ux-enhancements.js` (si se quiere dev mode)
  - `assets/js/ltms-ux-enhancements.min.js` (PROD)
  - `assets/css/ltms-ux-enhancements.css` (dev mode)
  - `assets/css/ltms-ux-enhancements.min.css` (PROD)
  - `assets/css/ltms-admin-ux.css` + `.min.css`
- [ ] **No subir** los `.map` files (solo para desarrollo)
- [ ] Actualizar `UX_ENHANCEMENTS.md`, `QA_REPORT.md`, `CHANGELOG.md`

### 8. Verificación post-despliegue
- [ ] Acceder a `wp-admin/plugins.php` — versión del plugin muestra `2.9.35`
- [ ] No hay errores PHP en `wp-content/debug.log`
- [ ] Abrir una ventana incógnito y visitar:
  - `/login-vendedor/` → formulario con validación en vivo, password strength, toggle password
  - `/registro-vendedor/` → wizard 3 pasos con validación
  - `/sellers/` → landing con FAQ acordeón funcional, botones compartir
  - `/mi-tienda/` (como vendedor logueado) → dashboard con tour, command palette, theme toggle
  - Vista productos → lightbox + quick view + indicador de stock
  - Vista pedidos → búsqueda con autocompletar, export CSV
  - Vista billetera → export CSV
  - Vista envíos → export CSV
- [ ] **Verificar 4 nuevos items del menú del dashboard de vendedor**: Marketing, Security, Donations, PosGold
- [ ] **Verificar 6 nuevos endpoints AJAX** (cada uno debe responder 200 con nonce válido):
  - `ltms_backorder_notify` — notificar cliente cuando producto vuelva a tener stock
  - `ltms_get_invoices` — listar facturas del vendedor
  - `ltms_review_helpful` — votar si una reseña fue útil
  - `ltms_save_push_subscription` — guardar suscripción Web Push (VAPID)
  - `ltms_submit_question` — enviar pregunta Q&A sobre un producto
  - `ltms_submit_return` — solicitar devolución de producto
- [ ] **Verificar migración SAT México**: ejecutar en DB `DESCRIBE lt_commissions;` y confirmar que las **11 columnas nuevas** están presentes (cfdi_uuid, rfc, regimen_fiscal, uso_cfdi, etc.)
- [ ] Verificar que la actividad del vendedor (activity feed) carga en `view-home.php`
- [ ] **Limpiar cache de SiteGround Optimizer**: `rm -rf wp-content/uploads/siteground-optimizer-assets/*`
- [ ] **Flush WordPress cache + OPcache del pool web**:
  ```bash
  wp cache flush --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html
  wp eval 'opcache_reset();' --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html
  ```
  - Alternativa HTTP (preferida en SiteGround): `curl 'https://lo-tengo.com.co/wp-content/plugins/lt-marketplace-suite/deploy/ltms-opcache-flush.php?token=ltms_opcache_2026'`

### 9. Verificación de performance
- [ ] En DevTools → Network, confirmar que se cargan `ltms-ux-enhancements.min.js` y `.min.css`
- [ ] Comparar tamaño transferido vs versión anterior (debe ser ~43% menor)
- [ ] Lighthouse audit en `/mi-tienda/`:
  - Performance ≥ 80 (mobile)
  - Accessibility ≥ 95
  - Best Practices ≥ 90

### 10. Verificación de emails
- [ ] Enviar email de prueba desde WP Mail SMTP o desde el panel:
  - welcome-vendor (crear vendedor de prueba)
  - commission-credited (crear pedido de prueba y marcar como completado)
  - payout-approved (solicitar retiro pequeño y aprobarlo)
- [ ] Verificar rendering en Gmail web, Outlook desktop, Apple Mail
- [ ] Confirmar que la paleta de colores coincide con el frontend

## Post-despliegue

### 11. Monitorización (24-48h)
- [ ] Revisar `debug.log` cada 4 horas durante el primer día
- [ : ] Monitorizar New Relic / Query Monitor por warnings PHP
- [ ] Revisar Google Analytics por caída de conversión en registro
- [ ] Revisar Hotjar/Clarity por frustración en checkout

### 12. Rollback (si es necesario)
- [ ] Restaurar snapshot del directorio del plugin
- [ ] Reactivar plugin
- [ ] Confirmar `LTMS_VERSION` vuelve a la versión anterior
- [ ] Documentar el motivo del rollback en `worklog.md`

### 13. Comunicación
- [ ] Notificar al equipo de soporte sobre las nuevas funcionalidades
- [ ] Actualizar base de conocimiento con screenshots de los nuevos módulos
- [ ] Si hay issues conocidos, documentar workarounds en `TROUBLESHOOTING.txt`

## Comandos útiles

```bash
# Re-ejecutar build (después de cualquier cambio a los assets)
node /home/z/my-project/scripts/build-minify.js

# Build con source maps (solo para staging/debug)
node /home/z/my-project/scripts/build-minify.js --sourcemap

# Verificación de sintaxis JS
node -c /home/z/my-project/lt-marketplace-suite/assets/js/ltms-ux-enhancements.min.js

# Verificación de balance de llaves CSS
grep -oE '\{' assets/css/ltms-ux-enhancements.css | wc -l
grep -oE '\}' assets/css/ltms-ux-enhancements.css | wc -l

# Forzar carga de assets no minificados (en wp-config.php)
define( 'SCRIPT_DEBUG', true );

# === Comandos post-deploy v2.9.35 ===

# Limpiar cache del SiteGround Optimizer (assets combinados/minificados)
rm -rf wp-content/uploads/siteground-optimizer-assets/*

# Flush object cache de WordPress
wp cache flush --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html

# Flush OPcache del pool CLI (no afecta al pool web en SiteGround, ver nota abajo)
wp eval 'opcache_reset();' --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html

# Flush OPcache del pool WEB (preferido en SiteGround)
curl 'https://lo-tengo.com.co/wp-content/plugins/lt-marketplace-suite/deploy/ltms-opcache-flush.php?token=ltms_opcache_2026'

# Verificar 11 columnas SAT México en lt_commissions
wp db query 'DESCRIBE lt_commissions;' --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html | grep -E 'cfdi|rfc|regimen|uso_cfdi'
```

## Notas finales

- **Estrategia overlay**: esta versión NO rompe ninguna funcionalidad existente. Si algo deja de funcionar, el rollback es inmediato restaurando el snapshot.
- **Compatibilidad WC**: funciona con WooCommerce 7.0+ (testeado hasta 8.9).
- **Compatibilidad PHP**: requiere PHP 8.1+ (declaraciones de tipos en `email-styles.php`).
- **Accesibilidad**: WCAG 2.1 AA. Todos los modales tienen focus trap, todos los dropdowns son navegables por teclado, todos los iconos SVG tienen `aria-hidden` o `aria-label`.
- **Performance**: la capa respeta `prefers-reduced-motion`, usa `IntersectionObserver` para lazy loading, y todos los event listeners están delegados (no se añaden por elemento).
