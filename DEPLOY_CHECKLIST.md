# Deploy Checklist — LT Marketplace Suite v2.9.98

Checklist paso a paso para desplegar la nueva capa UX de forma segura en producción.

> **Notas v2.9.98 (2026-07-08):** versión que completa 3 auditorías (REG-AUDIT-001, DEEP-AUDIT-002, UIUX-AUDIT-001) con 100% de findings P0+P1+P2 resueltos. Incluye 25 vistas del dashboard SPA, 2 nuevos tabs de nav (Seguros + Domiciliarios), CSP compliance (0 inline handlers), toast system, dark mode, mobile bottom nav, keyboard shortcuts, y mucho más.

---

## Pre-despliegue (en local / staging)

### 1. Verificación de versión y sintaxis PHP
- [ ] `LTMS_VERSION` en `lt-marketplace-suite.php` = `2.9.98`
- [ ] `Version:` en header del plugin = `2.9.98`
- [ ] Validar todos los archivos PHP modificados con parser real (no balance-checker ingenuo):
  ```bash
  node /home/z/my-project/scripts/php_check.js \
    lt-marketplace-suite.php \
    includes/frontend/views/dashboard-wrapper.php \
    includes/frontend/views/view-insurance.php \
    includes/frontend/views/view-drivers.php \
    includes/frontend/views/view-orders.php \
    includes/frontend/views/view-products.php \
    includes/frontend/views/view-wallet.php \
    includes/frontend/views/view-shipping-statement.php \
    includes/frontend/views/view-bookings.php \
    includes/frontend/views/view-kitchen.php \
    includes/frontend/views/view-home.php \
    includes/frontend/class-ltms-dashboard-logic.php \
    includes/frontend/class-ltms-driver-ajax.php \
    includes/frontend/class-ltms-frontend-assets.php
  ```
  Todos deben mostrar `OK`.

### 2. Verificación de CSP compliance (0 inline handlers)
- [ ] Ejecutar y confirmar **0 resultados** en cada grep:
  ```bash
  # No debe haber ningún match en views PHP
  grep -rn 'onclick=\|onchange=\|onfocus=\|onsubmit=\|onload=\|onmouseover=' includes/frontend/views/
  # No debe haber ningún alert() en views PHP
  grep -rn 'alert(' includes/frontend/views/
  # location.reload() solo permitido en view-drivers (create/edit, documentado)
  grep -rn 'location\.reload(' includes/frontend/views/
  ```
- [ ] Resultado esperado: `onclick/onchange/onfocus/onsubmit/onload`: 0 matches. `alert(`: 0 matches. `location.reload(`: solo 1 en `view-drivers.php` (comentado como necesario para create/edit).

### 3. Verificación de estructura de archivos
- [ ] `includes/frontend/views/` contiene **25 vistas** (incluyendo `view-insurance.php` y `view-drivers.php` expandidas en v2.9.97-98)
- [ ] `dashboard-wrapper.php` incluye las secciones SPA `#ltms-view-insurance` y `#ltms-view-drivers`
- [ ] `dashboard-wrapper.php` incluye los SVG icons `'insurance'` y `'drivers'` en `$svg_icons`
- [ ] `dashboard-wrapper.php` incluye la lógica condicional para mostrar el tab "Domiciliarios" (basada en `_ltms_drivers_count_cache` o `ltms_own_delivery_zones`)
- [ ] `class-ltms-dashboard-logic.php` registra el shortcode `[ltms_vendor_drivers]`
- [ ] `class-ltms-driver-ajax.php` actualiza `_ltms_drivers_count_cache` en `ajax_save_driver()` y `ajax_delete_driver()`

### 4. Verificación de nonces estandarizados (DEEP-AUDIT-002 P3-5)
- [ ] Todos los handlers AJAX del vendor dashboard usan `ltms_dashboard_nonce`
- [ ] Los handlers del storefront usan `ltms_ux_nonce`
- [ ] Los handlers de auth/OAuth usan `ltms_auth_nonce`
- [ ] Los handlers del admin usan `ltms_admin_nonce`
- [ ] **No quedan** referencias a `ltms_storefront_nonce` (legacy)

### 5. Verificación de IDOR protection (DEEP-AUDIT-002 P0-4)
- [ ] `ajax_submit_kyc`, `ajax_upload_kyc_document` verifican ownership antes de leer/escribir
- [ ] `ajax_save_driver`, `ajax_delete_driver`, `ajax_toggle_active`, `ajax_toggle_available` verifican ownership
- [ ] Bank account endpoints desencriptan en servidor y aplican masking antes de enviar al cliente
- [ ] Payout endpoints validan `vendor_id` del request vs `get_current_user_id()`

### 6. Verificación de assets
- [ ] `assets/js/ltms-dashboard.js` no ha sido modificado sin bump de versión en `class-ltms-frontend-assets.php`
- [ ] `assets/css/ltms-ux-enhancements.css` incluye las clases `.ltms-empty-state`, `.ltms-status-badge`, `.ltms-stats-grid`, `.ltms-stat-card`, `.ltms-skeleton`, `.ltms-bottom-nav`, `.ltms-dark-mode`, `.ltms-skip-link`
- [ ] `assets/css/ltms-dashboard.css` está sincronizado con cualquier cambio de layout
- [ ] `.min.js` y `.min.css` están regenerados si se modificó la fuente
- [ ] **VERIFICAR que `.min.js` NO pese más de 350 KB** (límite de memoria de SiteGround para assets combinados)

### 7. Verificación de migraciones DB
- [ ] Tabla `lt_vendor_drivers` existe (columnas: id, vendor_id, full_name, document_number, phone, vehicle_type, vehicle_plate, status, created_at, updated_at)
- [ ] Tabla `lt_insurance_policies` existe (con `insurance_type` ENUM y `status` ENUM)
- [ ] Tabla `lt_shipping_cost_ledger` existe
- [ ] Tabla `lt_backorder_subscriptions` existe (v2.9.61 P2-24)
- [ ] Tabla `lt_review_votes` existe (v2.9.61 P2-25)
- [ ] Tabla `lt_consent_log` existe (Habeas Data Ley 1581/2012)

### 8. Verificación de plantillas PHP (CSP-compliant)
- [ ] `dashboard-wrapper.php` — sin `onclick`, sin `onchange`, sin `onfocus`, sin `onsubmit`
- [ ] `view-insurance.php` — filtros vía `addEventListener`, CSV export vía `addEventListener`
- [ ] `view-drivers.php` — todos los botones (add/edit/toggle/delete) vía `addEventListener`, modales accesibles
- [ ] `view-orders.php` — KPIs, search, date range, skeleton loading
- [ ] `view-products.php` — pagination, search, gallery upload (5 imágenes), ReDi toggle
- [ ] `view-wallet.php` — tax breakdown, CSV export
- [ ] `view-shipping-statement.php` — `data-action="submit-form"` (no inline onchange)
- [ ] `view-bookings.php` — calendar view, modales
- [ ] `view-kitchen.php` — audio alerts, polling 10s, KPIs
- [ ] `view-home.php` — widgets de pedidos recientes + top productos, onboarding checklist
- [ ] `view-settings.php` — vacation mode, store logo, schedule, social links
- [ ] `view-sellers-landing.php` — testimonials, calculator, FAQ
- [ ] `view-redi.php` — SPA puro (no reloads)
- [ ] `view-envios.php` — delete modal WCAG 2.1 AA (gold standard para todos los modales)

### 9. Verificación de emails
- [ ] Las plantillas en `templates/emails/email-*.php` incluyen `email-styles.php`
- [ ] Email de KYC submission (REG-AUDIT-001 REG-08) usa template HTML
- [ ] Email de nueva registración admin (MISSING-04) funciona

---

## Despliegue (en producción)

### 10. Backup
- [ ] Backup completo de la base de datos (`wp ltms-backup` o `mysqldump`)
- [ ] Snapshot del directorio `wp-content/plugins/lt-marketplace-suite/` actual
- [ ] Confirmar que el rollback es posible en < 5 minutos

### 11. Deploy vía git (preferido)
```bash
ssh -p 18765 u1549-ruo8hvwpk9dt@ssh.lo-tengo.com.co
cd /home/customer/www/lo-tengo.com.co/public_html/wp-content/plugins/lt-marketplace-suite
git fetch origin
git reset --hard origin/main
grep "Version:" lt-marketplace-suite.php  # Debe mostrar 2.9.98
grep "LTMS_VERSION" lt-marketplace-suite.php | head -1  # Debe mostrar 2.9.98
```

### 12. Flush de caches (CRÍTICO en SiteGround)
```bash
# WordPress object cache
wp cache flush --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html

# SiteGround Optimizer (assets combinados/minificados)
rm -rf /home/customer/www/lo-tengo.com.co/public_html/wp-content/cache/supercache/*
rm -rf /home/customer/www/lo-tengo.com.co/public_html/wp-content/uploads/siteground-optimizer-assets/*

# OPcache del pool CLI (no afecta al pool web en SiteGround)
php -r 'opcache_reset();'

# OPcache del pool WEB (preferido en SiteGround, vía HTTP)
curl 'https://lo-tengo.com.co/wp-content/plugins/lt-marketplace-suite/deploy/ltms-opcache-flush.php?token=ltms_opcache_2026'
```

### 13. Verificación post-despliegue
- [ ] Acceder a `wp-admin/plugins.php` — versión del plugin muestra `2.9.98`
- [ ] No hay errores PHP en `wp-content/debug.log` ni en `error_log` de SiteGround
- [ ] Abrir una ventana incógnito y visitar como vendor logueado:
  - `/mi-tienda/` → dashboard SPA carga sin reloads
  - **Tab "Seguros"** visible después de "Billetera" → click muestra KPIs + tabla + filtros
  - **Tab "Domiciliarios"** visible después de "Envíos" (si el vendor tiene own-delivery o drivers) → click muestra KPIs + tabla + CRUD
  - Tab "Inicio" → widgets de pedidos recientes + top productos cargan
  - Tab "Pedidos" → KPIs + search + date range funcionan
  - Tab "Productos" → pagination + search + gallery upload
  - Tab "Billetera" → tax breakdown + CSV export
  - Tab "Configuración" → vacation mode + store logo + schedule + social links
  - **Modales** (envíos delete, driver add/edit/delete) — focus trap, ESC cierra, click overlay cierra
  - **Toast notifications** — aparecen al guardar/eliminar (no alerts)
  - **Dark mode toggle** — funciona y persiste
  - **Mobile bottom nav** — visible en pantallas ≤768px
  - **Keyboard shortcuts** — `?` abre help modal, `g+h` va a home, `/` enfoca search

### 14. Verificación de endpoints AJAX (cada uno debe responder 200 con nonce válido)
- [ ] `ltms_save_driver` — crear repartidor
- [ ] `ltms_delete_driver` — eliminar repartidor (con ownership check)
- [ ] `ltms_toggle_driver_active` — toggle status
- [ ] `ltms_toggle_driver_available` — toggle disponibilidad
- [ ] `ltms_save_delivery_settings` — guardar config de entrega
- [ ] `ltms_get_insurance_data` — listar pólizas
- [ ] `ltms_get_dashboard_data` — métricas home
- [ ] `ltms_get_orders_data` — pedidos con paginación/search
- [ ] `ltms_get_activity_feed` — activity feed home
- [ ] Todos los endpoints de PosGold (`save_credentials`, `test_connection`, `sync_products`, `save_categories`, `save_rules`, `save_seo`)

### 15. Verificación de seguridad
- [ ] Intentar acceder al KYC de otro vendor → debe devolver 403
- [ ] Intentar eliminar driver de otro vendor → debe devolver 403
- [ ] Intentar toggle driver de otro vendor → debe devolver 403
- [ ] Verificar que el bank account number se muestra enmascarado (`****1234`) en el DOM, no plaintext
- [ ] Verificar que el PosGold token se muestra enmascarado en la UI
- [ ] Rate limiting: intentar registrar 5 veces seguidas → debe bloquear después del límite
- [ ] 2FA rate limit: intentar 6 códigos TOTP inválidos → debe bloquear temporalmente

### 16. Verificación de performance
- [ ] Lighthouse audit en `/mi-tienda/`:
  - Performance ≥ 80 (mobile)
  - Accessibility ≥ 95
  - Best Practices ≥ 90
- [ ] DevTools → Network: confirmar que `ltms-dashboard.js` y `ltms-ux-enhancements.css` cargan
- [ ] Sin warnings de mixed content o CORS
- [ ] Tiempo de carga del dashboard < 3s en mobile 4G

### 17. Verificación de accesibilidad (WCAG 2.1 AA)
- [ ] Skip-link visible al hacer Tab desde la URL
- [ ] Todos los botones de nav tienen `:focus-visible` outline
- [ ] Modales: focus trap, ESC cierra, focus vuelve al trigger
- [ ] Todos los SVG icons tienen `aria-hidden="true"` o `aria-label`
- [ ] Bottom nav items tienen `aria-label`
- [ ] Inputs tienen `<label for>` asociado
- [ ] Status badges tienen texto legible (no solo color)

---

## Post-despliegue

### 18. Monitorización (24-48h)
- [ ] Revisar `debug.log` cada 4 horas durante el primer día
- [ ] Monitorizar Query Monitor por warnings PHP
- [ ] Revisar Google Analytics por caída de conversión en registro
- [ ] Revisar Hotjar/Clarity por frustración en checkout
- [ ] Verificar que los crons siguen ejecutándose:
  ```bash
  wp cron event list --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html | grep ltms
  ```

### 19. Rollback (si es necesario)
```bash
cd /home/customer/www/lo-tengo.com.co/public_html/wp-content/plugins/lt-marketplace-suite
git log --oneline -5  # Ver commits recientes
git reset --hard <commit-anterior>  # Rollback a versión previa
wp cache flush --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html
curl 'https://lo-tengo.com.co/wp-content/plugins/lt-marketplace-suite/deploy/ltms-opcache-flush.php?token=ltms_opcache_2026'
```
- [ ] Confirmar `LTMS_VERSION` vuelve a la versión anterior
- [ ] Documentar el motivo del rollback en `worklog.md`

### 20. Comunicación
- [ ] Notificar al equipo de soporte sobre las nuevas funcionalidades (Seguros, Domiciliarios, dark mode, mobile nav)
- [ ] Actualizar base de conocimiento con screenshots de los nuevos módulos
- [ ] Si hay issues conocidos, documentar workarounds en `TROUBLESHOOTING.txt`

---

## Comandos útiles

```bash
# === Validación PHP (parser real, no ingenuo) ===
node /home/z/my-project/scripts/php_check.js <file.php> [...]

# === Verificación CSP compliance ===
grep -rn 'onclick=\|onchange=\|onfocus=\|onsubmit=\|onload=' includes/frontend/views/
grep -rn 'alert(' includes/frontend/views/

# === Flush de caches en SiteGround ===
wp cache flush --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html
rm -rf /home/customer/www/lo-tengo.com.co/public_html/wp-content/cache/supercache/*
rm -rf /home/customer/www/lo-tengo.com.co/public_html/wp-content/uploads/siteground-optimizer-assets/*
curl 'https://lo-tengo.com.co/wp-content/plugins/lt-marketplace-suite/deploy/ltms-opcache-flush.php?token=ltms_opcache_2026'

# === Verificar tablas DB ===
wp db query 'SHOW TABLES LIKE "bkr_lt_vendor_drivers";' --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html
wp db query 'SHOW TABLES LIKE "bkr_lt_insurance_policies";' --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html
wp db query 'SHOW TABLES LIKE "bkr_lt_shipping_cost_ledger";' --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html

# === Verificar crons ===
wp cron event list --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html | grep ltms

# === Forzar carga de assets no minificados (en wp-config.php, solo para debug) ===
define( 'SCRIPT_DEBUG', true );
```

---

## Notas finales

- **Estrategia overlay**: esta versión NO rompe ninguna funcionalidad existente. Si algo deja de funcionar, el rollback es inmediato restaurando el commit anterior.
- **Compatibilidad WC**: funciona con WooCommerce 7.0+ (testeado hasta 8.9).
- **Compatibilidad PHP**: requiere PHP 8.1+ (declaraciones de tipos en `email-styles.php`).
- **Accesibilidad**: WCAG 2.1 AA. Todos los modales tienen focus trap, todos los dropdowns son navegables por teclado, todos los iconos SVG tienen `aria-hidden` o `aria-label`, skip-link presente.
- **CSP Ready**: 0 inline handlers. El plugin está listo para headers `Content-Security-Policy: default-src 'self'` estrictos.
- **Performance**: la capa respeta `prefers-reduced-motion`, usa `IntersectionObserver` para lazy loading, y todos los event listeners están delegados (no se añaden por elemento).
- **Auditorías completas**: REG-AUDIT-001 (registro, 11+3 fixes), DEEP-AUDIT-002 (onboarding+panel, 56 findings 100% P0+P1+P2 resueltos), UIUX-AUDIT-001 (62 findings, 100% resueltos).
