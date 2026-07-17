# Deploy Checklist — LT Marketplace Suite v2.9.187

Checklist paso a paso para desplegar de forma segura en producción.

> **Notas v2.9.187 (2026-07-17):** Cierre del ciclo Plaza Viva. 9 templates nativos WC activos en producción vía `LTMS_Native_Templates` (template_include override). Design system CSS/JS (724 + 647 líneas). 178 test methods nuevos en 9 módulos. 2 migrations formalizadas (`lt_consumer_disputes` + `lt_customs_declarations`). XCover claim listener registrado. Vendor rating calculation con peso exponencial. SiteGround WAF confirmado por Contra Cultura.
>
> **Notas v2.9.101 (2026-07-13):** Build pipeline + CI + security hardening. 20 commits con 9 vulnerabilidades de seguridad arregladas, QA de 21/21 vistas, GitHub Actions CI, y 4 vistas refactorizadas (inline JS → external files).

---

## Pre-despliegue (en local / desarrollo)

### 1. Build — generar .min files
```bash
cd /home/z/my-project/lt-marketplace-suite
npm install        # si es primera vez
npm run build      # genera todos los .min.js y .min.css
```
Verificar: 0 errores, todos los .min generados. **Incluye `ltms-plaza-viva.min.css` y `ltms-plaza-viva.min.js` (NUEVOS en v2.9.178).**

### 2. Lint — validar sintaxis
```bash
npm run lint       # PHP + JS syntax check
```
Verificar: 0 errores.

### 3. CI — verificar que GitHub Actions pasa verde
Ir a: https://github.com/jglotengo/lt-marketplace-suite/actions
Verificar: ✅ en el último commit. **Target actual: 3,283 tests passing (CI 100% verde).**

### 4. Verificar CSP compliance
```bash
grep -rn 'onclick=\|onchange=\|onfocus=\|onsubmit=\|onload=' includes/frontend/views/
grep -rn 'onclick=\|onchange=\|onfocus=\|onsubmit=\|onload=' templates/
# Ambos deben devolver 0 resultados
```

### 5. Verificar que no hay alert()/confirm() nativos
```bash
grep -rn 'alert(' includes/frontend/views/ templates/ | grep -v '//'
grep -rn ' confirm(' includes/frontend/views/ templates/ | grep -v 'data-confirm\|ltms-confirm\|confirmation\|//\|FIX'
# Ambos deben devolver 0 resultados
```

### 6. Verificar que los 9 templates nativos Plaza Viva existen
```bash
ls -1 templates/ | grep -E '^(single-product|home|archive|cart|checkout|order-tracking|vendor-store|help-center|content-product)\.php$'
# Debe listar 9 archivos
```

### 7. Verificar que el design system Plaza Viva está build-eado
```bash
ls -la assets/css/ltms-plaza-viva.css assets/css/ltms-plaza-viva.min.css
ls -la assets/js/ltms-plaza-viva.js assets/js/ltms-plaza-viva.min.js
# Todos deben existir y los .min deben tener mtime >= al source
```

### 8. Verificar migrations DB (Plaza Viva)
```bash
# Localmente (o vía wp-cli en producción post-deploy)
wp db query 'SHOW TABLES LIKE "bkr_lt_consumer_disputes";' --allow-root
wp db query 'SHOW TABLES LIKE "bkr_lt_customs_declarations";' --allow-root
# Ambas deben retornar 1 row
```

---

## Despliegue (en producción)

### 9. Deploy automático
```bash
bash /home/z/my-project/scripts/deploy.sh
```
El script hace automáticamente:
- `git push origin main`
- SSH al servidor
- `git fetch origin && git reset --hard origin/main`
- `wp cache flush`
- `rm -rf wp-content/cache/supercache/* wp-content/uploads/siteground-optimizer-assets/*`
- `wp eval 'opcache_reset();'`
- Verificación HTTP 200

### 9b. Deploy manual (si el script falla — ver Lección #110)
```bash
# Local: push
cd /home/z/my-project/lt-marketplace-suite
git push origin main

# SSH al servidor
ssh -p 18765 u1549-ruo8hvwpk9dt@ssh.lo-tengo.com.co
cd /home/customer/www/lo-tengo.com.co/public_html/wp-content/plugins/lt-marketplace-suite
git fetch origin && git reset --hard origin/main

# Verificar versión
grep "Version:" lt-marketplace-suite.php | head -1
# Debe mostrar: 2.9.187

# Flush de caches (CRÍTICO en SiteGround — Lección #109)
cd /home/customer/www/lo-tengo.com.co/public_html
wp cache flush --allow-root
rm -rf wp-content/cache/supercache/* wp-content/uploads/siteground-optimizer-assets/*
wp eval 'opcache_reset();' --allow-root
```

### 9c. Trigger webhook (si SSH falla — Lección #110)
Si el deploy webhook devuelve 403 (captcha de SiteGround):
1. Abrir navegador logado en `wp-admin`
2. Pegar la URL del webhook:
   ```
   https://lo-tengo.com.co/wp-content/plugins/lt-marketplace-suite/deploy/ltms-deploy-webhook.php?token=ltms_deploy_2026_s3cur3_t0k3n_x9z
   ```
3. Si funciona desde browser pero no desde curl, el fix es htaccess bypass (ver `deploy/htaccess-webhook-bypass.txt`).

---

## Post-despliegue — Verificación específica de Plaza Viva

### 10. Verificar que `LTMS_Native_Templates` está activo
- [ ] Visitar `https://lo-tengo.com.co/producto/{cualquier-producto}/` en incógnito
- [ ] View Page Source — buscar `<!-- LTMS Native Template: single-product -->` (comentario HTML marker)
- [ ] Si NO aparece el marker, Elementor sigue ganando. Verificar que `class-ltms-native-templates.php` está cargado:
  ```bash
  wp eval 'echo class_exists("LTMS_Native_Templates") ? "yes" : "no";' --allow-root
  # Debe imprimir: yes
  ```

### 11. Verificar add-to-cart fix (938px → 48px) — Lección #101
- [ ] Visitar single-product en mobile y desktop
- [ ] Inspeccionar botón "Añadir al carrito" con DevTools
- [ ] Verificar que el computed style del botón tiene `height: 48px` (NO 938px)
- [ ] Verificar que `form.cart` tiene `align-items: center` (NO stretch)
- [ ] Click en botón → debe agregar al carrito sin errores AJAX

### 12. Verificar design system Plaza Viva cargado
- [ ] DevTools → Network → filtrar por `plaza-viva`
- [ ] Deben aparecer 2 archivos: `ltms-plaza-viva.min.css` y `ltms-plaza-viva.min.js`
- [ ] Ambos con status 200 (no 404)
- [ ] Verificar CSS variables aplicadas: en DevTools → Elements → `<html>` → computed styles, buscar `--pv-primary` (debe ser `#00867d`)

### 13. Verificar templates nativos WC individuales
- [ ] **single-product** (`/producto/{slug}/`) — breadcrumb + gallery + add-to-cart + related
- [ ] **home** (`/`) — hero + featured categories + testimonials + newsletter
- [ ] **archive** (`/tienda/` o `/categoria/{cat}/`) — title + price filter + sort + load more
- [ ] **cart** (`/carrito/`) — empty state + qty clamping + cross-sells
- [ ] **checkout** (`/finalizar-compra/`) — login prompt + payment labels + order review aria-live
- [ ] **order-tracking** (`/seguimiento-pedido/`) — form + loading state + empty state
- [ ] **vendor-store** (`/vendedor/{slug}/`) — vacation banner + breadcrumb + schema.org
- [ ] **help-center** (`/centro-ayuda/`) — FAQ accesible + honeypot + categorías dinámicas
- [ ] **content-product** (loop items en archive) — visibility check + stock logic + lazy loading

### 14. Verificar migrations DB en producción
- [ ] Tabla `bkr_lt_consumer_disputes` existe con schema correcto:
  ```bash
  wp db query 'DESCRIBE bkr_lt_consumer_disputes;' --allow-root
  # Debe mostrar 13 columnas: id, order_id, customer_id, vendor_id, dispute_type, status, amount, evidence_urls, resolution, created_at, updated_at, resolved_at, resolved_by
  ```
- [ ] Tabla `bkr_lt_customs_declarations` existe con schema correcto:
  ```bash
  wp db query 'DESCRIBE bkr_lt_customs_declarations;' --allow-root
  # Debe mostrar 11 columnas: id, order_id, declaration_number, country, regime, customs_value, duties, pdf_url, status, filed_at, created_at
  ```

### 15. Verificar XCover claim listener enganchado
- [ ] En `wp-admin` → WC → Orders, cambiar una orden con seguro XCover de `completed` a `refunded`
- [ ] Verificar en DB que se creó un claim en `lt_insurance_policies` con `status='claim_filed'`:
  ```bash
  wp db query 'SELECT id, policy_number, status FROM bkr_lt_insurance_policies WHERE order_id={ORDER_ID};' --allow-root
  ```

### 16. Verificar vendor rating cache
- [ ] Visitar `/vendedor/{slug}/` con un vendor que tenga reviews
- [ ] Verificar que el rating se muestra y está cacheado:
  ```bash
  wp db query 'SELECT user_id, meta_key, meta_value FROM bkr_usermeta WHERE meta_key="_ltms_vendor_rating_cache" AND user_id={VENDOR_ID};' --allow-root
  # Debe retornar 1 row con un JSON
  ```

---

## Post-despliegue — Verificación básica (heredada)

### 17. Verificación básica
- [ ] `curl -sI -A "Mozilla/5.0" "https://lo-tengo.com.co/" | head -3` → HTTP/2 200
- [ ] Panel del vendedor (`/mi-tienda/`) carga sin errores
- [ ] Hard refresh (Ctrl+Shift+R) en navegador incógnito
- [ ] Console (F12) sin errores 403/404/500

### 18. Verificación de vistas del dashboard
- [ ] Inicio: KPIs y widgets cargan
- [ ] Pedidos: tabla de pedidos carga
- [ ] Productos: lista de productos carga
- [ ] Billetera: balance y transacciones cargan
- [ ] Configuración: guardar cambios funciona
- [ ] ReDi: productos disponibles cargan
- [ ] Novedades: lista de incidentes carga
- [ ] Seguros: KPIs + filtros + CSV
- [ ] Domiciliarios: KPIs + CRUD + delete modal

### 19. Rollback (si es necesario)
```bash
bash /home/z/my-project/scripts/rollback.sh [commit-hash]
```
O manualmente:
```bash
cd /home/customer/www/lo-tengo.com.co/public_html/wp-content/plugins/lt-marketplace-suite
git log --oneline -5           # ver commits recientes
git reset --hard <commit-hash> # rollback
cd /home/customer/www/lo-tengo.com.co/public_html
wp cache flush --allow-root
rm -rf wp-content/cache/supercache/* wp-content/uploads/siteground-optimizer-assets/*
wp eval 'opcache_reset();' --allow-root
curl 'https://lo-tengo.com.co/wp-content/plugins/lt-marketplace-suite/deploy/ltms-opcache-flush.php?token=ltms_opcache_2026'
```

---

## Notas importantes

### SiteGround Anti-Bot Bypass
El panel del vendedor usa `/?ltms_ajax=1` en lugar de `/wp-admin/admin-ajax.php` para evitar el bloqueo del WAF de SiteGround. **SiteGround WAF confirmado por Contra Cultura (julio 2026).** El WAF está activo y funcionando como se espera. Cuando SiteGround desactive el anti-bot:
```bash
bash /home/z/my-project/scripts/remove-ajax-bypass.sh
```

### SG Optimizer (Lección #109 — CRÍTICO para Plaza Viva)
SiteGround Optimizer combina TODOS los CSS/JS en archivos cacheados en `wp-content/uploads/siteground-optimizer-assets/`. Tras modificar cualquier `.css` o `.js`, **SIEMPRE** purgar este cache o los cambios no se reflejarán:
```bash
rm -rf /home/customer/www/lo-tengo.com.co/public_html/wp-content/uploads/siteground-optimizer-assets/*
```
- `combine_javascript`: ✅ activado
- `optimize_javascript`: ✅ activado
- `combine_css`: ✅ activado (afecta Plaza Viva)
- Si algo se rompe después de un deploy, desactivar temporalmente:
```bash
wp option update siteground_optimizer_combine_javascript 0 --allow-root
wp option update siteground_optimizer_optimize_javascript 0 --allow-root
wp option update siteground_optimizer_combine_css 0 --allow-root
wp cache flush --allow-root
rm -rf wp-content/uploads/siteground-optimizer-assets/*
```

### Deploy Webhook (Lección #110)
Si el deploy webhook devuelve 403 desde curl, NO es un bug del código — es el captcha de SiteGround Anti-Bot. Workarounds:
1. **Browser context:** Abrir la URL del webhook en el navegador logado en `wp-admin`.
2. **htaccess bypass:** Ver `deploy/htaccess-webhook-bypass.txt`.

### Templates nativos WC (NUEVO en v2.9.178-187)
El sistema `LTMS_Native_Templates` intercepta 9 templates WC via `template_include` filter y los reemplaza por los del plugin en `templates/`. Esto permite al plugin controlar 100% del markup, eliminando la pelea con Elementor (Lección #102: Elementor SIEMPRE gana en head). Para desactivar temporalmente (debug):
```php
// En wp-config.php
define('LTMS_DISABLE_NATIVE_TEMPLATES', true);
```

### Vistas condicionales
| Vista | Condición |
|-------|-----------|
| Domiciliarios | `ltms_own_delivery_zones` no vacío O drivers registrados |
| ReDi | `ltms_redi_enabled = 'yes'` |
| Novedades | `ltms_redi_enabled = 'yes'` |
| Cocina | `ltms_is_restaurant = 'yes'` |
| Órdenes de Compra | `ltms_ordenes_compra_enabled = 'yes'` |
| Analytics | Rol `ltms_vendor_premium` |
| Vendor store vacation banner | `ltms_vacation_mode = 'on'` en vendor meta |
| Help center categories | Taxonomy `ltms_help_category` con términos |
| Customs declaration file option | `ltms_customs_enabled = 'yes'` AND order status = `completed` |

### Verificaciones finales de versión
- [ ] `LTMS_VERSION` en `lt-marketplace-suite.php` = `2.9.187`
- [ ] `Version:` en header del plugin = `2.9.187`
- [ ] Validar todos los archivos PHP modificados con parser real (no balance-checker ingenuo):
  ```bash
  node /home/z/my-project/scripts/php_check.js \
    lt-marketplace-suite.php \
    includes/frontend/class-ltms-native-templates.php \
    includes/business/class-ltms-vendor-rating.php \
    includes/business/listeners/class-ltms-xcover-claim-listener.php \
    templates/single-product.php \
    templates/home.php \
    templates/archive.php \
    templates/cart.php \
    templates/checkout.php \
    templates/order-tracking.php \
    templates/vendor-store.php \
    templates/help-center.php \
    templates/content-product.php
  ```
  Todos deben mostrar `OK`.

---

## Comandos útiles

```bash
# === Validación PHP (parser real, no ingenuo) ===
node /home/z/my-project/scripts/php_check.js <file.php> [...]

# === Verificación CSP compliance ===
grep -rn 'onclick=\|onchange=\|onfocus=\|onsubmit=\|onload=' includes/frontend/views/ templates/
grep -rn 'alert(' includes/frontend/views/ templates/

# === Flush de caches en SiteGround (CRÍTICO — Lección #109) ===
wp cache flush --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html
rm -rf /home/customer/www/lo-tengo.com.co/public_html/wp-content/cache/supercache/*
rm -rf /home/customer/www/lo-tengo.com.co/public_html/wp-content/uploads/siteground-optimizer-assets/*
curl 'https://lo-tengo.com.co/wp-content/plugins/lt-marketplace-suite/deploy/ltms-opcache-flush.php?token=ltms_opcache_2026'

# === Verificar tablas DB (incluye Plaza Viva migrations) ===
wp db query 'SHOW TABLES LIKE "bkr_lt_vendor_drivers";' --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html
wp db query 'SHOW TABLES LIKE "bkr_lt_insurance_policies";' --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html
wp db query 'SHOW TABLES LIKE "bkr_lt_shipping_cost_ledger";' --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html
wp db query 'SHOW TABLES LIKE "bkr_lt_consumer_disputes";' --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html
wp db query 'SHOW TABLES LIKE "bkr_lt_customs_declarations";' --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html

# === Verificar crons ===
wp cron event list --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html | grep ltms

# === Verificar que LTMS_Native_Templates está activo ===
wp eval 'echo class_exists("LTMS_Native_Templates") ? "yes" : "no";' --allow-root

# === Verificar version del plugin ===
grep "Version:" lt-marketplace-suite.php | head -1
grep "LTMS_VERSION" lt-marketplace-suite.php | head -1

# === Forzar carga de assets no minificados (en wp-config.php, solo para debug) ===
define( 'SCRIPT_DEBUG', true );

# === Desactivar native templates (debug temporal) ===
define( 'LTMS_DISABLE_NATIVE_TEMPLATES', true );
```

---

## Notas finales

- **Estrategia overlay**: esta versión NO rompe ninguna funcionalidad existente. Si algo deja de funcionar, el rollback es inmediato restaurando el commit anterior.
- **Compatibilidad WC**: funciona con WooCommerce 7.0+ (testeado hasta 8.9).
- **Compatibilidad PHP**: requiere PHP 8.1+ (declaraciones de tipos en `email-styles.php`).
- **Accesibilidad**: WCAG 2.1 AA. Todos los modales tienen focus trap, todos los dropdowns son navegables por teclado, todos los iconos SVG tienen `aria-hidden` o `aria-label`, skip-link presente.
- **CSP Ready**: 0 inline handlers. El plugin está listo para headers `Content-Security-Policy: default-src 'self'` estrictos.
- **Performance**: la capa respeta `prefers-reduced-motion`, usa `IntersectionObserver` para lazy loading, y todos los event listeners están delegados (no se añaden por elemento).
- **Auditorías completas**: REG-AUDIT-001 (registro, 11+3 fixes), DEEP-AUDIT-002 (onboarding+panel, 56 findings 100% P0+P1+P2 resueltos), UIUX-AUDIT-001 (62 findings, 100% resueltos), 30+ auditorías adicionales hasta v2.9.187.
- **Plaza Viva**: 9 templates nativos WC + design system CSS/JS (724 + 647 líneas) activos en producción vía `LTMS_Native_Templates`. Ver `PLAN_IMPLEMENTACION_PLAZA_VIVA.md` para el plan completo.

