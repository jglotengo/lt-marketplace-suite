# Lecciones Aprendidas — LT Marketplace Suite

> **Propósito:** Registro de TODOS los errores encontrados durante el desarrollo para que la IA (y los desarrolladores) NO vuelvan a cometer los mismos errores. Cada entrada documenta: el error, la causa raíz, el fix, y la regla preventiva.
>
> **Última actualización:** 2026-07-06
> **Versión del plugin:** 2.9.35
> **Total de lecciones:** 35

---

## Tabla de Contenidos

1. [PHP — Errores de Sintaxis y Lenguaje](#1-php--errores-de-sintaxis-y-lenguaje)
2. [PHP — Errores de Arquitectura y Autoloader](#2-php--errores-de-arquitectura-y-autoloader)
3. [PHP — Errores de Visibilidad y Métodos](#3-php--errores-de-visibilidad-y-metodos)
4. [PHP — Errores de Constantes y Variables](#4-php--errores-de-constantes-y-variables)
5. [WordPress — Errores de Hooks y Nonces](#5-wordpress--errores-de-hooks-y-nonces)
6. [JavaScript — Errores de Runtime](#6-javascript--errores-de-runtime)
7. [CSS — Errores de Layout y UX](#7-css--errores-de-layout-y-ux)
8. [Git — Errores de Deployment](#8-git--errores-de-deployment)
9. [SiteGround — Errores de Cache y Producción](#9-siteground--errores-de-cache-y-producción)
10. [Base de Datos — Errores de Migración](#10-base-de-datos--errores-de-migración)
11. [Reglas Preventivas para la IA](#11-reglas-preventivas-para-la-ia)

---

## 1. PHP — Errores de Sintaxis y Lenguaje

### LECCIÓN #1: `continue 2` en loop de un solo nivel

**Error:**
```
PHP Fatal error: Cannot 'continue' 2 levels in class-ltms-logistics-compliance.php:250
```

**Causa raíz:** El código usaba `continue 2` dentro de un `foreach` de un solo nivel. PHP 8.0+ lanza fatal error porque no hay 2 niveles de loop para saltar.

**Fix:** Cambiar `continue 2` → `continue`.

**Regla preventiva:** `continue N` solo se puede usar cuando hay N niveles de loops anidados. Verificar el contexto del loop antes de usar `continue 2` o `continue 3`.

---

### LECCIÓN #2: Código PHP fuera de tags `<?php`

**Error:** Código PHP visible como texto plano en la página de Seguridad del admin.

**Causa raíz:** Después de un bloque `<?php endif; ?>`, el código PHP siguiente no tenía su tag `<?php` de apertura. PHP lo imprimía como texto HTML.

**Fix:** Añadir `<?php` antes del comentario `// Acciones manuales`.

**Regla preventiva:** Después de CUALQUIER cierre `?>`, verificar que el siguiente bloque PHP tenga su `<?php` de apertura. Usar `php -l` para validar sintaxis.

---

### LECCIÓN #3: Método declarado dos veces en la misma clase

**Error:**
```
PHP Fatal error: Cannot redeclare LTMS_Core_Security::derive_key()
```

**Causa raíz:** El release v2.9.31 dejó dos declaraciones del método `derive_key()` en la misma clase `LTMS_Core_Security` (líneas 289 y 485).

**Fix:** Eliminar la declaración duplicada (la obsoleta).

**Regla preventiva:** Al hacer merge de branches o rebasar commits, buscar duplicados de métodos con `grep -n "function " archivo.php | sort | uniq -d`.

---

## 2. PHP — Errores de Arquitectura y Autoloader

### LECCIÓN #4: Clases faltantes en el autoloader classmap

**Error:** Múltiples páginas del admin daban WSOD (pantalla blanca) porque las clases no se cargaban.

**Causa raíz:** El release v2.9.31 añadió 35+ archivos PHP nuevos (compliance, donations, cross-border, PosGold, etc.) pero NUNCA los registró en el array `$exceptions_npart` del autoloader custom en `lt-marketplace-suite.php`.

**Fix:** Añadir las 35+ entradas al classmap.

**Regla preventiva:** **SIEMPRE** que se cree un nuevo archivo PHP en `includes/`, verificar que el autoloader puede cargarlo. El autoloader custom usa convención de nombres: `LTMS_Clase_Name` → busca `class-ltms-clase-name.php`. Si la ruta no coincide, añadir entrada al `$exceptions_npart`.

**Comando de verificación:**
```bash
# Listar archivos PHP que NO están en el autoloader
find includes/ -name "*.php" -type f | while read f; do
  cls=$(basename "$f" .php | sed 's/class-//' | tr '-' '_')
  slug=$(basename "$f" .php)
  grep -q "$slug" lt-marketplace-suite.php || echo "FALTA: $f"
done
```

---

### LECCIÓN #5: Registro duplicado de submenús

**Error:** El submenú "Logística / Costos" aparecía dos veces en el admin.

**Causa raíz:** La clase `LTMS_Admin_Shipping_Ledger` se auto-registraba via `init()` llamado desde `class-ltms-kernel.php:266`, Y también estaba registrada en el array `$submenus` de `class-ltms-admin.php`.

**Fix:** Quitar el registro del array `$submenus` y dejar solo el del kernel.

**Regla preventiva:** Antes de registrar un submenú en el array `$submenus`, verificar si la clase correspondiente tiene un `init()` que se llama desde el kernel. Si lo tiene, NO duplicar el registro.

**Comando de verificación:**
```bash
# Buscar clases que se auto-registran
grep -n "::init()" includes/core/class-ltms-kernel.php | grep -i admin
```

---

### LECCIÓN #6: Slug mismatch underscore vs hyphen

**Error:** La pestaña "Cross-Border" mostraba "Sección no encontrada".

**Causa raíz:** El tab registrado era `cross_border` (underscore) pero el archivo se llamaba `section-cross-border.php` (hyphen). El código buscaba `section-cross_border.php` que no existía.

**Fix:** Buscar primero con el slug tal cual, luego probando underscores → hyphens.

**Regla preventiva:** Cuando un tab usa underscores y el archivo usa hyphens, implementar fallback. NUNCA asumir que el slug coincide exactamente con el nombre del archivo.

---

## 3. PHP — Errores de Visibilidad y Métodos

### LECCIÓN #7: Método `private` llamado desde otra clase

**Error:**
```
PHP Fatal error: Call to private method LTMS_Core_Firewall::get_client_ip() from scope LTMS_Data_Masking
```

**Causa raíz:** `LTMS_Core_Firewall::get_client_ip()` era `private static`, pero `LTMS_Data_Masking::log_auditor_access()` la llamaba desde otra clase. `method_exists()` retorna `true` para métodos private, así que el check no protegía.

**Fix:** Cambiar visibility de `private static` → `public static`.

**Regla preventiva:** `method_exists()` NO verifica visibilidad. Si una clase A llama un método de clase B, el método DEBE ser `public`. Verificar con:
```bash
grep -rn "private static function" includes/ | while read line; do
  method=$(echo "$line" | grep -oP "function \K\w+")
  grep -rn "$method" includes/ | grep -v "class-ltms" | grep -v "$method:" | head -3
done
```

---

## 4. PHP — Errores de Constantes y Variables

### LECCIÓN #8: Constante inexistente `LTMS_PATH`

**Error:**
```
PHP Fatal error: Undefined constant "LTMS_PATH"
```

**Causa raíz:** Varios archivos usaban `LTMS_PATH` que NO está definida en ningún lugar. La constante correcta es `LTMS_PLUGIN_DIR` (definida en `lt-marketplace-suite.php:67`).

**Fix:** Cambiar `LTMS_PATH` → `LTMS_PLUGIN_DIR`.

**Regla preventiva:** Las constantes del plugin son:
- `LTMS_PLUGIN_DIR` — ruta del directorio del plugin (con trailing slash)
- `LTMS_PLUGIN_URL` — URL del directorio del plugin
- `LTMS_INCLUDES_DIR` — ruta de includes/ (con trailing slash)
- `LTMS_ASSETS_URL` — URL de assets/
- `LTMS_VERSION` — versión del plugin

**NUNCA** usar `LTMS_PATH`, `LTMS_PLUGIN_DIR_PATH`, o `LTMS_ASSETS_PATH` — no existen.

**Comando de verificación:**
```bash
grep -rn "LTMS_PATH\b\|LTMS_PLUGIN_DIR_PATH\b\|LTMS_ASSETS_PATH\b" includes/ | grep -v "defined\|//\|coverage"
```

---

### LECCIÓN #9: Acceso a constante de clase que no existe

**Error:**
```
PHP Fatal error: Undefined class constant 'RETENTION_DEFAULTS'
```

**Causa raíz:** El código usaba `class_exists('LTMS_Privacy_Toolkit') ? ::get_config() : ::RETENTION_DEFAULTS`. El `else` también accedía a la clase, causando fatal si no existía.

**Fix:** Usar defaults hardcodeados en el `else` en vez de acceder a la constante de clase.

**Regla preventiva:** En un ternario `class_exists(A) ? A::method() : A::CONSTANT`, el `else` NO puede acceder a `A` si `A` no existe. Usar valores literales en el `else`.

---

## 5. WordPress — Errores de Hooks y Nonces

### LECCIÓN #10: Nonce action incorrecto

**Error:** Todos los AJAX del storefront fallaban con 403 (nonce verification failed).

**Causa raíz:** Los handlers verificaban `check_ajax_referer('ltms_storefront_nonce', 'nonce')` pero el JS del storefront envía el nonce `ltms_ux_nonce` (localizado como `ltmsUX.nonce`).

**Fix:** Cambiar `check_ajax_referer('ltms_storefront_nonce', 'nonce')` → `check_ajax_referer('ltms_ux_nonce', 'nonce')`.

**Regla preventiva:** Los nonces del plugin son:
- `ltms_admin_nonce` — admin pages
- `ltms_dashboard_nonce` — vendor dashboard (SPA)
- `ltms_ux_nonce` — storefront público (ltmsUX.nonce en JS)
- `ltms_settings_nonce` — settings forms

**Verificar qué nonce envía el JS antes de escribir el handler PHP:**
```bash
grep -n "nonce" assets/js/ltms-ux-enhancements.js | head -10
```

---

### LECCIÓN #11: Endpoint AJAX llamado desde JS pero no registrado en PHP

**Error:** Popup "Algo salió mal" en el storefront.

**Causa raíz:** El JS llamaba a `ltms_get_activity_feed` (y otros 5 endpoints) que no existían en PHP. El AJAX fallaba con 400/0, y el error boundary del JS mostraba un toast.

**Fix:** Crear los 6 handlers AJAX faltantes.

**Regla preventiva:** **SIEMPRE** que el JS llame a un `action: 'ltms_*'`, verificar que exista el `add_action('wp_ajax_ltms_*', ...)` correspondiente en PHP.

**Comando de verificación:**
```bash
# Endpoints llamados desde JS pero NO registrados en PHP
comm -23 \
  <(grep -roh "action: *'ltms_[a-z_]*'" assets/js/*.js | sed "s/action: *'//;s/'//" | sort -u) \
  <(grep -roh "wp_ajax_ltms_[a-z_]*" includes/ | sed "s/wp_ajax_//" | sort -u)
```

---

### LECCIÓN #12: `register_shutdown_function` no captura todos los errores

**Error:** WSOD en página del Auditor, pero el `register_shutdown_function` no escribía al log.

**Causa raíz:** SiteGround suprime `display_errors` y `log_errors` a nivel PHP. Ni siquiera con `WP_DEBUG=true` se loguean errores.

**Fix:** Usar `try/catch` con `\Throwable` + `file_put_contents` a archivo propio (no `debug.log`).

**Regla preventiva:** En SiteGround, para capturar WSOD:
```php
$_ltms_dbg = WP_CONTENT_DIR . '/ltms-debug.log';
ini_set( 'log_errors', '1' );
ini_set( 'error_log', $_ltms_dbg );
set_error_handler( function( $errno, $errstr, $errfile, $errline ) use ( $_ltms_dbg ) {
    file_put_contents( $_ltms_dbg, "[" . date('Y-m-d H:i:s') . "] $errstr at $errfile:$errline\n", FILE_APPEND );
    return false;
} );
register_shutdown_function( function() use ( $_ltms_dbg ) {
    $err = error_get_last();
    if ( $err ) {
        file_put_contents( $_ltms_dbg, "[" . date('Y-m-d H:i:s') . "] FATAL: {$err['message']} at {$err['file']}:{$err['line']}\n", FILE_APPEND );
    }
} );
```

---

## 6. JavaScript — Errores de Runtime

### LECCIÓN #13: `e.target.closest is not a function`

**Error:**
```
TypeError: e.target.closest is not a function
```

**Causa raíz:** El event listener `mousemove` se dispara para todos los elementos, incluyendo text nodes y `Document`, que no tienen `.closest()`.

**Fix:** Añadir guard: `if (!e.target || typeof e.target.closest !== 'function') return;`

**Regla preventiva:** En event listeners globales (`document.addEventListener`), SIEMPRE verificar que `e.target` tenga el método antes de llamarlo.

---

### LECCIÓN #14: Error toasts intrusivos saturan al usuario

**Error:** Popup "Algo salió mal" aparecía constantemente por errores JS menores y AJAX de terceros.

**Causa raíz:** El `initAjaxErrorInterceptor()` capturaba TODOS los errores AJAX (incluyendo WooCommerce, plugins de terceros) y mostraba un toast por cada uno. El `initErrorBoundaries()` hacía lo mismo con errores JS.

**Fix:** Desactivar toasts con flags `SHOW_ERROR_TOASTS = false` y `SHOW_AJAX_ERROR_TOASTS = false`. Solo loguear a `console.error`.

**Regla preventiva:** Los error boundaries del frontend NO deben mostrar toasts por cada error. Solo loguear a consola. Reservar toasts para errores que el usuario puede accionar (ej: "No se pudo agregar al carrito").

---

## 7. CSS — Errores de Layout y UX

### LECCIÓN #15: `display: flex` en `form.cart` rompe elementos anidados

**Error:** Botones de upsell y gift options se deformaban a tamaño gigante.

**Causa raíz:** El CSS `display: flex` en `.woocommerce div.product form.cart` hacía que TODOS los elementos dentro del form (incluyendo upsell items y gift checkbox) se volvieran flex items, rompiendo su layout.

**Fix:** Quitar `display: flex` del form. Usar `display: inline-block` para cantidad y botón. CSS específico para `.ltms-addon-items` y `.ltms-addon-add-btn`.

**Regla preventiva:** NUNCA aplicar `display: flex` a contenedores de WooCommerce que tienen elementos anidados complejos (upsells, cross-sells, gift options). Usar selectores específicos para los elementos que se quieren alinear.

---

### LECCIÓN #16: Selector demasiado broad afecta elementos no deseados

**Error:** Los botones de upsell "+ Añadir" se veían gigantes.

**Causa raíz:** El selector `.woocommerce div.product form.cart .button` afectaba TODOS los botones dentro del form, incluyendo los de upsell.

**Fix:** Usar selector directo `.woocommerce div.product form.cart > .button` (solo hijos directos).

**Regla preventiva:** En CSS de WooCommerce, usar `>` (child combinator) para limitar la afectación a elementos directos, no anidados.

---

### LECCIÓN #17: `.min.css` no se actualiza con cambios del `.css`

**Error:** Los fixes CSS no se reflejaban en producción.

**Causa raíz:** Producción carga `.min.css` pero los cambios se hicieron solo en `.css`. El `.min.css` nunca se actualizó.

**Fix:** Copiar `.css` → `.min.css` después de cada cambio. O usar un minifier real.

**Regla preventiva:** **SIEMPRE** que se modifique un `.css` o `.js` que tiene versión `.min`, actualizar AMBOS archivos. Verificar con:
```bash
diff <(wc -c assets/css/file.css) <(wc -c assets/css/file.min.css)
```
Si el `.min` es significativamente más pequeño, está minificado correctamente. Si son del mismo tamaño, el `.min` no está minificado pero al menos tiene el contenido actualizado.

---

## 8. Git — Errores de Deployment

### LECCIÓN #18: `.gitignore` excluye archivos necesarios

**Error:** `ltms-admin-ux.min.css` no llegaba a producción (0 bytes).

**Causa raíz:** `.gitignore` línea 88 tenía `*.min.css` que excluía TODOS los archivos `.min.css` del tracking.

**Fix:** `git add -f` para forzar el tracking de los `.min.css` específicos.

**Regla preventiva:** Revisar `.gitignore` antes de asumir que un archivo está en el repo. Si un archivo existe en disco pero no en producción, verificar:
```bash
git check-ignore -v archivo.min.css
```

---

### LECCIÓN #19: `git pull` falla por modificaciones locales de SiteGround

**Error:** `git pull origin main` falla con "Your local changes would be overwritten by merge".

**Causa raíz:** SiteGround Optimizer modifica `.min.css` y `.min.js` localmente en el servidor. Git detecta las modificaciones y se niega a sobrescribirlas.

**Fix:**
```bash
git fetch origin
git checkout origin/main -- .
git reset --hard origin/main
```

**Regla preventiva:** En SiteGround, el optimizador puede modificar assets. Antes de `git pull`, hacer `git stash` o usar `git checkout origin/main -- .` para forzar.

---

### LECCIÓN #20: Commits sin push quedan en el agente

**Error:** Cambios hechos en el agente no llegaban a GitHub.

**Causa raíz:** El agente Linux no tiene credenciales de GitHub configuradas. `git push` falla silenciosamente o pide usuario/password.

**Fix:** Usar PAT (Personal Access Token) en la URL de push:
```bash
git push https://jglotengo:ghp_TOKEN@github.com/jglotengo/lt-marketplace-suite.git main
```

**Regla preventiva:** Después de cada commit, verificar con `git log origin/main..HEAD --oneline`. Si hay commits sin push, hacer push inmediatamente.

---

### LECCIÓN #21: Rebase con conflictos de permisos

**Error:** `git rebase` falla con conflictos en archivos de coverage/ y .phpunit.cache/.

**Causa raíz:** Los directorios `coverage/` y `.phpunit.cache/` están tracked pero son artefactos de tests que cambian constantemente.

**Fix:** `git rm --cached coverage/ .phpunit.cache/ -r` para quitarlos del tracking.

**Regla preventiva:** Los directorios de artefactos de tests NO deben estar tracked. Añadir a `.gitignore`:
```
coverage/
.phpunit.cache/
```

---

## 9. SiteGround — Errores de Cache y Producción

### LECCIÓN #22: `wp cache flush` NO limpia el cache de SiteGround

**Error:** Después de `wp cache flush`, el sitio seguía sirviendo contenido viejo.

**Causa raíz:** `wp cache flush` solo limpia el cache de objetos de WordPress. SiteGround tiene su propio SuperCacher (cache de página completa) y Optimizer (combinación de CSS/JS) que NO se limpian con `wp cache flush`.

**Fix:**
```bash
# Cache de objetos
wp cache flush --allow-root --path=...

# Cache de página completa (SuperCacher)
rm -rf /home/customer/www/.../wp-content/cache/supercache/*
rm -rf /home/customer/www/.../wp-content/cache/page_enhanced/*

# Cache del optimizador (CSS/JS combinados)
rm -rf /home/customer/www/.../wp-content/uploads/siteground-optimizer-assets/*

# OPcache
wp eval 'opcache_reset();' --allow-root --path=...
```

**Regla preventiva:** En SiteGround, SIEMPRE ejecutar los 4 comandos anteriores después de un deploy. `wp cache flush` solo NO es suficiente.

---

### LECCIÓN #23: `.min.js` demasiado grande causa HTTP 500

**Error:** HTTP 500 después de copiar `.js` (613KB) → `.min.js`.

**Causa raíz:** SiteGround tiene un límite de memoria para procesar archivos JS. Un `.min.js` de 613KB (sin minificar) excede el límite.

**Fix:** Restaurar `.min.js` al tamaño original (318KB) y aplicar solo los fixes específicos con `sed`.

**Regla preventiva:** El `.min.js` NUNCA debe ser más grande que el `.js` original. Si se necesita actualizar el `.min.js`, aplicar los cambios específicos con `sed` en vez de copiar el archivo completo. Tamaño máximo recomendado: 350KB.

---

### LECCIÓN #24: Cache de navegador persiste después de deploy

**Error:** Los usuarios ven la versión vieja del sitio después de un deploy.

**Causa raíz:** El navegador cachea CSS/JS por URL. Si la URL no cambia (`?ver=2.9.34`), el navegador sirve la versión cacheada.

**Fix:** Bump de versión del plugin (`LTMS_VERSION`) para que WordPress genere URLs nuevas con `?ver=2.9.35`.

**Regla preventiva:** Después de cambios CSS/JS, SIEMPRE hacer bump de `LTMS_VERSION` en `lt-marketplace-suite.php`. Esto fuerza a los navegadores a descargar los archivos nuevos.

---

### LECCIÓN #25: SiteGuard CAPTCHA bloquea curl

**Error:** `curl` recibe HTTP 202 con 167 bytes en vez del HTML de la página.

**Causa raíz:** SiteGuard CAPTCHA (protección anti-bot) intercepta requests de curl y devuelve un redirect a `/.well-known/sgcaptcha/`.

**Fix:** Usar `-A "Mozilla/5.0"` (User-Agent de navegador) en curl. O mejor, hacer las verificaciones desde SSH con `wp eval`.

**Regla preventiva:** NO usar curl para verificar el sitio desde fuera. Usar `wp eval` desde SSH, o pedir al usuario que verifique en su navegador.

---

## 10. Base de Datos — Errores de Migración

### LECCIÓN #26: Migración saltada por version comparison

**Error:** Las columnas SAT México no se añadieron a `lt_commissions` aunque se ejecutó `LTMS_DB_Migrations::run()`.

**Causa raíz:** La migración `migrate_2_2_0_fiscal_sat_mexico()` estaba protegida por `version_compare($installed_version, '2.2.0', '<')`. Como el servidor estaba en 2.7.0 (mayor que 2.2.0), la migración se saltó.

**Fix:** Ejecutar los `ALTER TABLE` manualmente con `wp eval`.

**Regla preventiva:** Las migraciones condicionadas por versión NO se re-ejecutan si la versión instalada es mayor. Si una migración se saltó, ejecutar los ALTER TABLE manualmente o ajustar la condición.

---

### LECCIÓN #27: Columna faltante causa error silencioso

**Error:** `WordPress database error Unknown column 'aranceles_amount' in 'field list'`

**Causa raíz:** El release v2.9.31 añadió queries que referencian `aranceles_amount`, `is_hospedaje`, `is_import` pero la migración de BD que las crea no se ejecutó.

**Fix:** Ejecutar los 11 `ALTER TABLE` manualmente.

**Regla preventiva:** Después de un deploy, verificar que las columnas esperadas existen:
```bash
wp eval 'global $wpdb; $cols = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}lt_commissions"); echo implode(", ", $cols);' --allow-root --path=...
```

---

## 11. Reglas Preventivas para la IA

### REGLA #1: Verificar autoloader
Al crear un nuevo archivo PHP en `includes/`, verificar que el autoloader puede cargarlo. Si la convención de nombres no coincide, añadir entrada a `$exceptions_npart`.

### REGLA #2: Verificar constantes
Usar SOLO las constantes definidas: `LTMS_PLUGIN_DIR`, `LTMS_PLUGIN_URL`, `LTMS_INCLUDES_DIR`, `LTMS_ASSETS_URL`, `LTMS_VERSION`. NUNCA inventar constantes como `LTMS_PATH`.

### REGLA #3: Verificar visibilidad de métodos
Si una clase A llama un método de clase B, el método DEBE ser `public`. `method_exists()` NO verifica visibilidad.

### REGLA #4: Verificar nonces
Los nonces del plugin son: `ltms_admin_nonce` (admin), `ltms_dashboard_nonce` (vendor dashboard), `ltms_ux_nonce` (storefront). Verificar qué nonce envía el JS antes de escribir el handler.

### REGLA #5: Sincronizar .min.js y .min.css
Después de cambiar `.js` o `.css`, actualizar también `.min.js` y `.min.css`. Producción carga las versiones `.min`.

### REGLA #6: Bump de versión para cache-bust
Después de cambios CSS/JS, hacer bump de `LTMS_VERSION` para forzar a los navegadores a descargar archivos nuevos.

### REGLA #7: Limpiar caches de SiteGround
Después de un deploy en SiteGround, ejecutar: `wp cache flush` + `rm -rf siteground-optimizer-assets/*` + `opcache_reset()` + `rm -rf cache/supercache/*`.

### REGLA #8: No usar display:flex en contenedores de WooCommerce
WooCommerce tiene elementos anidados complejos en `form.cart`. Usar selectores específicos (`> .button`) en vez de `display: flex` en el contenedor.

### REGLA #9: Verificar endpoints AJAX
Después de añadir un `action: 'ltms_*'` en JS, verificar que existe el `add_action('wp_ajax_ltms_*', ...)` en PHP.

### REGLA #10: No copiar .js → .min.js directamente
El `.min.js` no debe ser más grande que el `.js` original. Aplicar fixes específicos con `sed` en vez de copiar el archivo completo. Tamaño máximo: 350KB.

### REGLA #11: Usar try/catch para WSOD
En SiteGround, los errores no se loguean. Usar `try/catch` con `\Throwable` + `file_put_contents` a archivo propio para capturar WSOD.

### REGLA #12: Verificar `continue N`
`continue 2` solo funciona con 2+ loops anidados. En un loop de un solo nivel, usar `continue` (sin número).

### REGLA #13: No mostrar toasts por errores AJAX
Los error boundaries del frontend NO deben mostrar toasts por cada error AJAX (incluye third-party plugins). Solo loguear a `console.error`.

### REGLA #14: Verificar git status antes de pull
En SiteGround, el optimizador puede modificar assets. Antes de `git pull`, hacer `git stash` o usar `git checkout origin/main -- .`.

### REGLA #15: Borrar artefactos de tests del tracking
`coverage/` y `.phpunit.cache/` NO deben estar tracked en git. Añadir a `.gitignore` y `git rm --cached`.

---

## Apéndice: Comandos de Verificación Rápida

```bash
# Verificar autoloader
find includes/ -name "*.php" -type f | while read f; do
  slug=$(basename "$f" .php)
  grep -q "$slug" lt-marketplace-suite.php || echo "FALTA: $f"
done

# Verificar endpoints AJAX
comm -23 \
  <(grep -roh "action: *'ltms_[a-z_]*'" assets/js/*.js | sed "s/action: *'//;s/'//" | sort -u) \
  <(grep -roh "wp_ajax_ltms_[a-z_]*" includes/ | sed "s/wp_ajax_//" | sort -u)

# Verificar constantes inexistentes
grep -rn "LTMS_PATH\b\|LTMS_PLUGIN_DIR_PATH\b\|LTMS_ASSETS_PATH\b" includes/ | grep -v "defined\|//\|coverage"

# Verificar métodos private llamados desde fuera
grep -rn "private static function" includes/ | while read line; do
  method=$(echo "$line" | grep -oP "function \K\w+")
  callers=$(grep -rn "::$method(" includes/ | grep -v "function $method" | grep -v "class-ltms" | head -1)
  [ -n "$callers" ] && echo "PRIVATE CALL: $method desde $callers"
done

# Verificar .min sincronizados
for f in assets/js/*.min.js; do
  orig="${f%.min.js}.js"
  [ -f "$orig" ] && [ "$(stat -c%s "$f")" -gt "$(stat -c%s "$orig")" ] && echo "MIN MAYOR: $f"
done
```

---

*Este documento se actualiza cada vez que se encuentra un nuevo error durante el desarrollo. La última actualización fue el 2026-07-06 con 35 lecciones documentadas.*
