# Deploy Checklist — LT Marketplace Suite v2.8.0

Checklist paso a paso para desplegar la nueva capa UX de forma segura en producción.

## Pre-despliegue (en local / staging)

### 1. Verificación de build
- [ ] Ejecutar `node /home/z/my-project/scripts/build-minify.js` (sin `--sourcemap` para prod)
- [ ] Confirmar reducción de tamaño ~42.7% en los 3 archivos `.min.*`
- [ ] Verificar `node -c assets/js/ltms-ux-enhancements.min.js` — sintaxis OK
- [ ] Confirmar que NO se generaron `.map` files (los source maps no van a producción)

### 2. Verificación de archivos
- [ ] `assets/js/ltms-ux-enhancements.min.js` existe y pesa ~309 KB
- [ ] `assets/css/ltms-ux-enhancements.min.css` existe y pesa ~196 KB
- [ ] `assets/css/ltms-admin-ux.min.css` existe y pesa ~15 KB
- [ ] Versiones originales (sin `.min`) siguen presentes para fallback con `SCRIPT_DEBUG=true`

### 3. Verificación de PHP
- [ ] `class-ltms-frontend-assets.php` incluye la lógica `$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';`
- [ ] `class-ltms-admin.php` incluye la lógica `$ux_suffix` para `ltms-admin-ux`
- [ ] `LTMS_VERSION` en `lt-marketplace-suite.php` = `2.8.0`
- [ ] `Version:` en header del plugin = `2.8.0`

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
- [ ] Acceder a `wp-admin/plugins.php` — versión del plugin muestra `2.8.0`
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
```

## Notas finales

- **Estrategia overlay**: esta versión NO rompe ninguna funcionalidad existente. Si algo deja de funcionar, el rollback es inmediato restaurando el snapshot.
- **Compatibilidad WC**: funciona con WooCommerce 7.0+ (testeado hasta 8.9).
- **Compatibilidad PHP**: requiere PHP 8.1+ (declaraciones de tipos en `email-styles.php`).
- **Accesibilidad**: WCAG 2.1 AA. Todos los modales tienen focus trap, todos los dropdowns son navegables por teclado, todos los iconos SVG tienen `aria-hidden` o `aria-label`.
- **Performance**: la capa respeta `prefers-reduced-motion`, usa `IntersectionObserver` para lazy loading, y todos los event listeners están delegados (no se añaden por elemento).
