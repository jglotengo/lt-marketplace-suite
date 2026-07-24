# Lecciones Aprendidas — LT Marketplace Suite

> **Propósito:** Registro de TODOS los errores encontrados durante el desarrollo para que la IA (y los desarrolladores) NO vuelvan a cometer los mismos errores. Cada entrada documenta: el error, la causa raíz, el fix, y la regla preventiva.
>
> **Última actualización:** 2026-07-23
> **Versión del plugin:** 2.9.239
> **Total de lecciones:** 123 (35 originales + 25 nuevas de v2.9.36-98 + 10 de estabilización + 15 de auditorías v2.9.113-118 + 5 de auditorías v2.9.119-132 + 10 de v2.9.143-160 + 13 del ciclo Plaza Viva v2.9.178-188 + 10 de la sesión Skeleton Loader/Nonce Refresh/WAF v2.9.222-239)

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
12. [**v2.9.36-98 — Lecciones Nuevas (REG/DEEP/UIUX Audits)**](#12-v2936-98--lecciones-nuevas-regdeepuiux-audits)
13. [**v2.9.178-187 — Lecciones Nuevas (Ciclo Plaza Viva)**](#13-v29178-187--lecciones-nuevas-ciclo-plaza-viva)
14. [**v2.9.222-239 — Skeleton Loader, Refresco de Nonce (Heartbeat→AJAX propio), WAF, Sincronía Test/Código**](#14-v29222-239--skeleton-loader-refresco-de-nonce-heartbeatajax-propio-waf-sincronía-testcódigo)

---

## 13. v2.9.178-187 — Lecciones Nuevas (Ciclo Plaza Viva)

> 10 lecciones nuevas (#101-110) extraídas del ciclo de desarrollo del design system "Plaza Viva" y los 9 templates nativos WC. Estas lecciones son CRÍTICAS y deben leerse antes de tocar código que involucre testing con Brain\Monkey, Elementor overrides, o deploys a SiteGround.

### Lección #101: `form.cart` con `display:flex` causa `align-items:stretch` — botón hereda altura de siblings

**Error:**
```
Botón add-to-cart en single-product template medía 938px de altura en producción.
```

**Causa raíz:** El form de WooCommerce `form.cart` tiene por defecto `display:flex` con `align-items:stretch` (que es el default de flexbox). Esto significa que TODOS los hijos del form (qty input, variation select, add-to-cart button) heredan la altura del sibling MÁS ALTO. En este caso, qty input + variation select combinados median 938px, y el botón heredaba esta altura por stretch.

**Fix:** Aplicar `align-items:center` al form (rompe el stretch) y `height:48px` explícito al button para garantizar altura consistente.

```css
form.cart {
    display: flex;
    align-items: center; /* override del default stretch */
    gap: 12px;
}
form.cart button.single_add_to_cart_button {
    height: 48px;
    flex: 0 0 auto;
}
```

**Regla preventiva:** NUNCA asumir que los hijos de un flex container mantienen su altura natural. El default es `align-items:stretch` — si un hijo es más alto que los demás, TODOS se estiran a esa altura. Siempre setear `align-items:center` o `align-items:flex-start` explícitamente cuando se usen flex containers con elementos de altura variable.

---

### Lección #102: Elementor CSS en body SIEMPRE gana sobre CSS en head — usar `template_include` override

**Error:**
```
CSS del plugin en <head> no aplicaba en producción, pero sí en local.
```

**Causa raíz:** Elementor inyecta sus estilos inline directamente en el `<body>` (no en `<head>`). Por especificidad del DOM, los estilos que aparecen MÁS TARDE en el documento HTML ganan sobre los del `<head>`, sin importar el orden de `wp_enqueue_style`. El plugin cargaba sus CSS en head con `wp_enqueue_style`, pero Elementor sobreescribía todo con sus estilos en body.

**Fix:** En vez de pelear con CSS en head, usar el filter `template_include` para reemplazar el template completo por uno del plugin:

```php
add_filter('template_include', function($template) {
    if (is_singular('product')) {
        $plugin_template = LTMS_PLUGIN_DIR . 'templates/single-product.php';
        if (file_exists($plugin_template)) {
            return $plugin_template;
        }
    }
    return $template;
});
```

Esto elimina por completo el HTML de Elementor para esa página, y el plugin controla 100% del markup y CSS.

**Regla preventiva:** Si estás construyendo templates nativos que deben reemplazar los de Elementor, NO intentes sobreescribir CSS en head. Usa `template_include` filter para reemplazar el template completo. Elementor SIEMPRE gana en head.

---

### Lección #103: Anonymous classes NO capturan variables del scope externo — usar constructor

**Error:**
```
PHP Notice: Undefined variable: $config in /tmp/anonymous_class_xxx.php
```

**Causa raíz:** Las clases anónimas en PHP NO capturan variables del scope externo automáticamente (a diferencia de los closures). Esto significa que este código NO funciona:

```php
$config = ['timeout' => 30];
$mock = new class extends BaseClient {
    public function getTimeout() {
        return $config['timeout']; // ERROR: $config no está definida aquí
    }
};
```

**Fix:** Pasar variables vía constructor:

```php
$config = ['timeout' => 30];
$mock = new class($config) extends BaseClient {
    private $config;
    public function __construct(array $config) {
        $this->config = $config;
        parent::__construct();
    }
    public function getTimeout() {
        return $this->config['timeout'];
    }
};
```

**Regla preventiva:** Las clases anónimas NO son closures. Para pasar datos del scope externo a una clase anónima, SIEMPRE usar el constructor. Si necesitas capturar muchas variables, considerar usar un closure con `Closure::bind()` en vez de una clase anónima.

---

### Lección #104: Brain\Monkey no puede stubear funciones PHP nativas (`file_exists`, `fopen`, etc.)

**Error:**
```
Brain\Monkey\Exception: Cannot stub function file_exists: it is a built-in PHP function.
```

**Causa raíz:** Brain\Monkey usa Patchwork para redefinir funciones, pero Patchwork NO puede redefinir funciones internas de PHP (built-in). Cualquier intento de `Functions\when('file_exists')->justReturn(true)` lanza excepción.

**Fix:** Hay 2 patrones válidos:

1. **Wrapper pattern:** envolver la función nativa en una función propia del namespace del plugin que SÍ puede ser stubeada:
```php
// En producción:
namespace LTMS\Core;
function file_exists($path) { return \file_exists($path); }

// En test:
Functions\when('LTMS\Core\file_exists')->justReturn(true);
```

2. **Real filesystem en test:** usar archivos reales temporales en `sys_get_temp_dir()`:
```php
$tmpFile = tempnam(sys_get_temp_dir(), 'ltms_test_');
$this->assertTrue($instance->process($tmpFile));
unlink($tmpFile);
```

**Regla preventiva:** NUNCA intentar stubear funciones PHP nativas (`file_exists`, `fopen`, `fread`, `fwrite`, `file_get_contents`, `glob`, `mkdir`, `unlink`, `is_dir`, `realpath`, etc.) con Brain\Monkey. Usar wrappers en namespace propio o filesystem real.

---

### Lección #105: Brain\Monkey no puede re-stubear funciones ya definidas como PHP reales en bootstrap

**Error:**
```
Brain\Monkey\Exception: Function wp_remote_get is already defined in the bootstrap.
```

**Causa raíz:** Si el `tests/bootstrap.php` define funciones de WordPress como PHP reales (porque Brain\Monkey no estaba instalado al inicio), Brain\Monkey no puede redefinirlas. Esto pasa típicamente cuando un proyecto empieza sin Brain\Monkey y luego lo añade.

**Fix:** Eliminar las definiciones de funciones de WP del bootstrap y dejar que Brain\Monkey las provea. Si alguna función se necesita como real en algún test, moverla a un helper file que se carga DESPUÉS de Brain\Monkey setup:

```php
// bootstrap.php
require_once 'vendor/autoload.php';
Brain\Monkey\setUp();
// AHORA sí se pueden definir helpers que usan functions\when()
require_once 'helpers/wp-helpers.php';
```

**Regla preventiva:** Cuando se adopta Brain\Monkey en un proyecto existente, auditar el `bootstrap.php` y eliminar TODAS las definiciones de funciones de WP (`wp_remote_get`, `wp_insert_post`, `get_option`, `update_option`, etc.). Brain\Monkey las provee via `Functions\when()` o `Functions\expect()`.

---

### Lección #106: Los mocks `wpdb` deben respetar el parámetro `$output` (OBJECT vs ARRAY_A)

**Error:**
```
Tests fallaban con "Trying to access property 'id' on array" en producción pero pasaban en test.
```

**Causa raíz:** El mock de `$wpdb->get_row()` en tests estaba configurado para retornar siempre un objeto (stdClass), pero el código real llamaba `$wpdb->get_row($sql, ARRAY_A)` para obtener un array. El test daba falso positivo.

**Fix:** Configurar el mock para respetar el parámetro `$output`:

```php
$wpdb = $this->getMockBuilder(stdClass::class)->getMock();
$wpdb->method('get_row')->willReturnCallback(function($sql, $output = OBJECT) {
    $row = ['id' => 1, 'name' => 'test'];
    return $output === ARRAY_A ? $row : (object) $row;
});
```

**Regla preventiva:** Al mockear `$wpdb->get_row()` o `$wpdb->get_results()`, SIEMPRE respetar el segundo parámetro `$output` (OBJECT default, ARRAY_A, ARRAY_N). Un mock que ignora `$output` da falsos positivos.

---

### Lección #107: `WP_User` ya está stubbeado en `RolesTest.php` — no redefinir

**Error:**
```
PHP Fatal error: Cannot redeclare class WP_User in tests/unit/RolesTest.php
```

**Causa raíz:** El archivo `tests/unit/RolesTest.php` define `WP_User` como un stub para testear la clase `LTMS_Roles`. Cualquier otro test que intente definir `WP_User` (en el mismo proceso de PHPUnit) causa fatal.

**Fix:** Reutilizar el stub de `RolesTest.php`. Si necesitas extender el comportamiento, usar `Mockery::mock(WP_User::class)` en vez de redefinir la clase.

```php
// MAL:
class WP_User {
    public $roles = ['ltms_vendor'];
    public $ID = 1;
}

// BIEN:
$user = Mockery::mock(WP_User::class);
$user->roles = ['ltms_vendor'];
$user->ID = 1;
```

**Regla preventiva:** Antes de definir una clase core de WP (`WP_User`, `WP_Post`, `WP_Error`, `WP_Query`) en un test, buscar si ya está definida en otro test del proyecto. Usar `class_exists()` check o `Mockery::mock()` para extenderla.

---

### Lección #108: `LTMS_PATH` no está definida en el plugin — usar `LTMS_PLUGIN_DIR`

**Error:**
```
PHP Warning: Use of undefined constant LTMS_PATH - assumed 'LTMS_PATH' (this will throw an Error in a future version of PHP)
```

**Causa raíz:** El plugin define `LTMS_PLUGIN_DIR` en `lt-marketplace-suite.php` (línea ~30) pero NUNCA define `LTMS_PATH` ni `LTMS_PLUGIN_DIR_PATH`. Código heredado o generado por IA suele usar `LTMS_PATH` por convención de WP, pero esa constante NO existe en este plugin.

**Fix:** Reemplazar TODAS las referencias a `LTMS_PATH` con `LTMS_PLUGIN_DIR`:

```php
// MAL:
require LTMS_PATH . 'includes/some-file.php';

// BIEN:
require LTMS_PLUGIN_DIR . 'includes/some-file.php';
```

**Regla preventiva:** Al escribir código nuevo en este plugin, SIEMPRE usar `LTMS_PLUGIN_DIR`. Si ves `LTMS_PATH` en código heredado, reemplázalo. Esta constante se define en `lt-marketplace-suite.php` con `define('LTMS_PLUGIN_DIR', plugin_dir_path(__FILE__));`.

---

### Lección #109: SiteGround Optimizer combina CSS en un archivo cacheado — purgar cache tras cambios

**Error:**
```
Cambios a ltms-plaza-viva.css no se reflejaban en producción, pero el archivo estaba actualizado en el servidor.
```

**Causa raíz:** SiteGround Optimizer combina TODOS los CSS en un solo archivo cacheado en `wp-content/uploads/siteground-optimizer-assets/combined-xxxxx.css`. Cuando se modifica un CSS source, el archivo combinado NO se regenera automáticamente — sigue sirviendo la versión vieja.

**Fix:** Tras cualquier cambio a CSS/JS en producción, ejecutar:

```bash
# Borrar cache de SG Optimizer (CSS/JS combinados)
rm -rf /home/customer/www/lo-tengo.com.co/public_html/wp-content/uploads/siteground-optimizer-assets/*

# Flush de WP object cache
wp cache flush --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html

# Flush OPcache (pool web, via HTTP)
curl 'https://lo-tengo.com.co/wp-content/plugins/lt-marketplace-suite/deploy/ltms-opcache-flush.php?token=ltms_opcache_2026'
```

**Regla preventiva:** Después de modificar cualquier `.css` o `.js` y deployar, SIEMPRE purgar el cache de SG Optimizer. NO basta con `wp cache flush` (eso solo afecta object cache, no los assets combinados). Si los cambios no se reflejan, este es el #1 culpable.

---

### Lección #110: Deploy webhook puede ser bloqueado por SiteGround captcha — usar browser context para trigger

**Error:**
```
curl https://lo-tengo.com.co/wp-content/plugins/lt-marketplace-suite/deploy/ltms-deploy-webhook.php?token=xxx
→ 403 Forbidden (SiteGround Anti-Bot)
```

**Causa raíz:** SiteGround Anti-Bot protege endpoints no-cacheados de WP. Si el User-Agent de curl es detectado como bot, lanza captcha. El webhook devuelve 403 sin siquiera ejecutarse.

**Fix:** Hay 2 soluciones:

1. **Browser context con cookies:** Abrir el navegador (logado en wp-admin), pegar la URL del webhook. Las cookies de auth hacen que SiteGround considere la request como legítima.

2. **htaccess bypass:** Añadir reglas en `.htaccess` para excluir el webhook del anti-bot (ver `deploy/htaccess-webhook-bypass.txt`):
```
<Files "ltms-deploy-webhook.php">
    SetEnvIfNoCase Request_URI "ltms-deploy-webhook.php" allow
    Order allow,deny
    Allow from env=allow
</Files>
```

**Alternativa larga-plazo:** Confirmar con Contra Cultura / soporte de SiteGround que el WAF permite el webhook. Esto ya está confirmado (julio 2026), pero la regla puede reactivarse tras updates de SiteGround.

**Regla preventiva:** Si un webhook en SiteGround devuelve 403 con un User-Agent de curl/Postman, NUNCA asumir que es un bug del código. Es el WAF. Probar primero con browser context autenticado. Si funciona, el fix es htaccess bypass o whitelist en SiteGround panel.

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

---

## 12. v2.9.36-98 — Lecciones Nuevas (REG/DEEP/UIUX Audits)

25 lecciones adicionales extraídas de los 60+ commits entre v2.9.36 y v2.9.98, organizadas por auditoría.

### LECCIÓN #36: Nonce action para Google OAuth (REG-AUDIT-001 REG-10)

**Error:** Google OAuth fallaba silenciosamente porque el nonce action era `ltms_admin_nonce` (para admin) en lugar de `ltms_auth_nonce` (para auth).

**Causa raíz:** Copy-paste de un handler admin sin ajustar el contexto.

**Fix:** Cambiar `check_ajax_referer('ltms_admin_nonce', 'nonce')` → `check_ajax_referer('ltms_auth_nonce', 'nonce')` en el handler de Google OAuth.

**Regla preventiva:** Usar el nonce action correcto según el contexto:
- `ltms_dashboard_nonce` — vendor dashboard
- `ltms_ux_nonce` — storefront
- `ltms_admin_nonce` — admin panel
- `ltms_auth_nonce` — auth (login, register, 2FA, OAuth)

### LECCIÓN #37: Rate limiting no atómico = race condition (REG-AUDIT-001 REG-01/02)

**Error:** El rate limiting usaba `SELECT count → if count < limit → INSERT` en dos pasos, permitiendo que múltiples requests concurrentes pasaran el check.

**Causa raíz:** No se consideró concurrencia en el diseño original.

**Fix:** Usar `INSERT INTO lt_rate_limits ... ON DUPLICATE KEY UPDATE count = count + 1` en una sola query atómica.

**Regla preventiva:** Todo rate limiting debe ser atómico. Nunca uses `SELECT + INSERT/UPDATE` en dos pasos para controlar concurrencia.

### LECCIÓN #38: `set_role()` sobreescribe roles (REG-AUDIT-001 REG-11)

**Error:** Al aprobar un vendedor, `set_role('ltms_vendor')` eliminaba cualquier otro rol que tuviera (ej. `subscriber`, `customer`).

**Causa raíz:** Confusión entre `WP_User::set_role()` (reemplaza) y `WP_User::add_role()` (añade).

**Fix:** Usar `$user->add_role('ltms_vendor')` en lugar de `$user->set_role('ltms_vendor')`.

**Regla preventiva:** En WordPress, `set_role()` REEMPLAZA todos los roles. Para AÑADIR un rol sin perder los existentes, usar `add_role()`.

### LECCIÓN #39: IDOR en endpoints de vendor (DEEP-AUDIT-002 P0-4)

**Error:** Cualquier vendor logueado podía leer/editar/eliminar datos de OTRO vendor (KYC, drivers, bank accounts, payouts) simplemente cambiando el ID en el request.

**Causa raíz:** Los endpoints verificaban `is_user_logged_in()` pero no verificaban que el `vendor_id` del request coincidiera con `get_current_user_id()`.

**Fix:** Antes de cada operación, verificar ownership:
```php
$existing_vendor = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT vendor_id FROM `{$table}` WHERE id = %d", $resource_id
));
if ( $existing_vendor !== get_current_user_id() ) {
    wp_send_json_error( __( 'No autorizado.', 'ltms' ), 403 );
    return;
}
```

**Regla preventiva:** TODO endpoint que lee/escribe datos de un vendor debe verificar ownership. `is_user_logged_in()` NO es suficiente.

### LECCIÓN #40: Bank account en plaintext en DOM (DEEP-AUDIT-002 P0-5)

**Error:** El número de cuenta bancaria se enviaba al browser en plaintext para mostrarlo en la UI, exponiéndolo a ataques XSS.

**Causa raíz:** Se desencriptaba para mostrar pero no se aplicaba masking.

**Fix:** Desencriptar server-side, aplicar masking (`****1234`), enviar solo el valor enmascarado al browser.

**Regla preventiva:** NUNCA enviar datos sensibles en plaintext al DOM. Siempre aplicar masking server-side antes de enviar al cliente.

### LECCIÓN #41: 2FA sin rate limiting (DEEP-AUDIT-002 P1)

**Error:** El challenge de TOTP 2FA no tenía rate limiting, permitiendo brute-force de códigos de 6 dígitos.

**Causa raíz:** Se asumió que el código de 30s + anti-replay era suficiente.

**Fix:** Rate limiting: 5 intentos fallidos por IP per 15 min → lockout temporal.

**Regla preventiva:** Todo endpoint de autenticación (login, 2FA, password reset) debe tener rate limiting. No confiar solo en la entropía del código.

### LECCIÓN #42: Nopriv abuse vectors (DEEP-AUDIT-002 P1)

**Error:** Varios endpoints sensibles estaban registrados como `wp_ajax_nopriv_*` (accesibles sin login), permitiendo abuso.

**Causa raíz:** Copy-paste de handlers sin revisar si necesitaban auth.

**Fix:** Auditar todos los `nopriv` y eliminar los que no son estrictamente necesarios (guest checkout, product questions, back-in-stock notifications). Los demás deben verificar `get_current_user_id()` internamente.

**Regla preventiva:** `wp_ajax_nopriv_*` es la excepción, no la regla. Solo usar para endpoints genuinamente públicos (checkout, product Q&A). Todo lo demás debe ser `wp_ajax_*` solo.

### LECCIÓN #43: PosGold SSRF (DEEP-AUDIT-002 P1)

**Error:** El cliente API de PosGold hacía requests HTTP a la URL configurada por el vendor sin validarla, permitiendo SSRF (Server-Side Request Forgery).

**Causa raíz:** No se validó la URL antes de hacer el request.

**Fix:** Validar URL contra allow-list antes de cada request HTTP outbound.

**Regla preventiva:** Todo request HTTP outbound que use una URL configurable por el usuario debe validar contra allow-list para prevenir SSRF.

### LECCIÓN #44: `onclick` inline rompe CSP (UIUX-AUDIT-001 P1)

**Error:** Todos los views tenían `onclick=""`, `onchange=""` inline, impidiendo usar headers CSP estrictos.

**Causa raíz:** Práctica común en WordPress legacy.

**Fix:** Reemplazar todos los inline handlers con `addEventListener()` + `data-action` delegation.

**Regla preventiva:** NUNCA usar `onclick=""`, `onchange=""`, `onfocus=""`, `onsubmit=""`, `onload=""` inline. Siempre usar `addEventListener()` en un `<script>` separado o `data-action` delegation.

### LECCIÓN #45: `alert()` es mala UX (UIUX-AUDIT-001 P1)

**Error:** Se usaba `alert()` para feedback al usuario, lo que bloquea el thread y es mala UX.

**Causa raíz:** Práctica común en código legacy.

**Fix:** Implementar toast system (slide-in, auto-dismiss 3s, color-coded success/error/info).

**Regla preventiva:** NUNCA usar `alert()`, `confirm()`, `prompt()`. Usar toasts para notificaciones y modales para confirmaciones.

### LECCIÓN #46: `location.reload()` rompe SPA (UIUX-AUDIT-001 P0)

**Error:** Después de cada operación CRUD, se llamaba `location.reload()`, rompiendo la experiencia SPA.

**Causa raíz:** Práctica común cuando no se quiere manejar actualización del DOM.

**Fix:** Para toggles y deletes, actualizar el DOM inline (badge + button + KPIs). Para create/edit, recargar solo si es estrictamente necesario (HTML server-rendered fresco).

**Regla preventiva:** Evitar `location.reload()` en SPAs. Para operaciones que cambian el estado, actualizar el DOM inline. Solo recargar cuando sea imprescindible (ej. crear/editar que necesita HTML server-rendered).

### LECCIÓN #47: Memory leak en event bindings (UIUX-AUDIT-001 P0-3)

**Error:** ReDi toggle estaba bound dentro de un click handler de img-preview, causando bindings duplicados en cada click.

**Causa raíz:** Anidación incorrecta de event listeners.

**Fix:** Mover el binding fuera del click handler, usar un solo listener con delegation.

**Regla preventiva:** NUNCA anidar `addEventListener()` dentro de otro event handler. Usar event delegation: `document.addEventListener('click', e => { if (e.target.closest('[data-action]')) {...} })`.

### LECCIÓN #48: `$country` undefined en do_action (UIUX-AUDIT-001 P0-4)

**Error:** `do_action('ltms_kyc_extension', $country)` fallaba porque `$country` no estaba definida en ese scope.

**Causa raíz:** Variable usada antes de ser inicializada.

**Fix:** Inicializar `$country = $_POST['country'] ?? 'CO';` antes del do_action.

**Regla preventiva:** Siempre inicializar variables antes de pasarlas a `do_action` o `apply_filters`. Usar null coalescing `??` con un default seguro.

### LECCIÓN #49: Métricas muestran `...` en primera carga (UIUX-AUDIT-001 P0-5)

**Error:** Las métricas del home mostraban `...` como valor inicial antes de que cargara el AJAX, dando impresión de que estaba roto.

**Causa raíz:** Placeholder feo en lugar de skeleton loading.

**Fix:** Usar skeleton loading animations (CSS shimmer) mientras carga el AJAX.

**Regla preventiva:** Para cualquier vista async, usar skeleton loading (shimmer animation) en lugar de placeholders estáticos como `...` o `Loading...`.

### LECCIÓN #50: Bell sin keyboard accessibility (UIUX-AUDIT-001 P0-7)

**Error:** El icono de notificaciones (bell) en el topbar solo funcionaba con click, no con keyboard.

**Causa raíz:** Era un `<div>` sin `role`, `tabindex`, ni handler de keyboard.

**Fix:** Cambiar a `role="button"`, `tabindex="0"`, `aria-expanded`, y agregar handlers para Enter y Space.

**Regla preventiva:** Todo elemento interactivo debe ser accesible por keyboard: `role="button"`, `tabindex="0"`, handlers para Enter y Space, `aria-expanded` para state.

### LECCIÓN #51: Validación PHP con balance-checker ingenuo

**Error:** El balance-checker de Python (`php_syntax_check.py`) reportaba falsos positivos de "IMBALANCED" en archivos con regex literals que contenían `"`.

**Causa raíz:** El stripper de strings/comments se confundía con `"` dentro de regex literals.

**Fix:** Crear `scripts/php_check.js` usando `php-parser` (npm) que hace parsing AST real.

**Regla preventiva:** Para validar sintaxis PHP, usar un parser AST real (php-parser), no un balance-checker de llaves/paréntesis. Los regex literals con `"` confunden a los balance-checkers ingenuos.

### LECCIÓN #52: Drivers count cache para nav visibility

**Error:** El nav del dashboard hacía un `SELECT COUNT(*)` en cada render para decidir si mostrar el tab "Domiciliarios".

**Causa raíz:** No había cache del count de drivers.

**Fix:** Cachear el count en `user_meta._ltms_drivers_count_cache` y actualizarlo en `ajax_save_driver()` y `ajax_delete_driver()`.

**Regla preventiva:** Para queries que se ejecutan en cada page load y dependen de datos que cambian infrecuentemente, usar cache en user_meta o transient. Invalidar el cache en cada operación que cambie los datos.

### LECCIÓN #53: SPA loadView normalización de nombres

**Error:** Views con guiones como `shipping-statement` generaban `loadShipping-statementView` que nunca existe como función.

**Causa raíz:** No se normalizaba el nombre del view antes de construir el método.

**Fix:** `view.split('-').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join('')` → `ShippingStatement` → `loadShippingStatementView`.

**Regla preventiva:** Al construir nombres de métodos dinámicamente desde strings con guiones, normalizar primero (PascalCase). Si no existe el método específico, caer a `loadGenericView(view)` que muestra `#ltms-view-<view>`.

### LECCIÓN #54: Output buffering para scripts inline (SiteGround)

**Error:** SiteGround removía scripts inline del `<head>`, rompiendo funcionalidades críticas del cart drawer.

**Causa raíz:** SiteGround Optimizer agresivo.

**Fix:** Usar `ob_start()` + `ob_end_flush()` para envolver el output y que SiteGround no pueda remover el script.

**Regla preventiva:** En SiteGround, si un script inline crítico se pierde, usar output buffering para protegerlo. Alternativamente, mover el script a un archivo `.js` externo.

### LECCIÓN #55: NO-OP en JS viejo para evitar doble-binding

**Error:** El JS externo viejo (cache-busteado) seguía ejecutando `updateCartQty` y `removeCartItem`, causando doble-binding con el nuevo JS.

**Causa raíz:** No se podía remover el JS viejo (estaba en cache del browser).

**Fix:** Hacer NO-OP en las funciones viejas: `window.updateCartQty = function() {}; window.removeCartItem = function() {};` al inicio del nuevo JS.

**Regla preventiva:** Cuando reemplazas JS legacy que está en cache del browser, hacer NO-OP en las funciones viejas al inicio del nuevo JS para evitar doble-binding.

### LECCIÓN #56: `html_entity_decode()` para subtotales

**Error:** El subtotal del cart drawer mostraba `&#36;&nbsp;735.000` (HTML crudo) en lugar de `$ 735.000`.

**Causa raíz:** `textContent` no interpreta entidades HTML; `innerHTML` sí.

**Causa raíz PHP:** `wc_price()` devuelve HTML con entidades.

**Fix:** En PHP, `html_entity_decode(wc_price($amount))` antes de enviar al cliente. En JS, usar `innerHTML` en lugar de `textContent` (con escape apropiado).

**Regla preventiva:** Cuando pases HTML con entidades desde PHP a JS, asegúrate de que el JS lo renderice con `innerHTML` (no `textContent`), o decodifica las entidades en PHP antes de enviar.

### LECCIÓN #57: Guests en cart drawer AJAX

**Error:** El cart drawer no funcionaba para guests (no logueados) porque el AJAX requería login.

**Causa raíz:** Endpoint registrado solo como `wp_ajax_*`, no `wp_ajax_nopriv_*`.

**Fix:** Registrar también como `wp_ajax_nopriv_*` (con los apropiados checks de seguridad: nonce sin user_id, rate limiting).

**Regla preventiva:** Para funcionalidades del carrito/checkout que deben funcionar para guests, registrar tanto `wp_ajax_*` como `wp_ajax_nopriv_*`. Pero SIEMPRE con nonce verification (sin user_id) y rate limiting.

### LECCIÓN #58: AES-256-GCM v2 con backward-compat v1

**Error:** Migrar de AES-256-CBC (v1) a AES-256-GCM (v2) rompía el decrypt de datos existentes.

**Causa raíz:** Cambio de algoritmo sin backward-compat.

**Fix:** Usar prefix `v1:` / `v2:` en el ciphertext. `decrypt()` auto-detecta la versión y usa el algoritmo correcto. Nuevos datos se cifran con v2, datos viejos se descifran con v1.

**Regla preventiva:** Al migrar algoritmos de cifrado, mantener backward-compat con un prefix de versión. Nunca re-cifrar datos existentes en un migration masivo (riesgo de pérdida). Dejar que los datos se re-cifren naturalmente cuando se re-escriban.

### LECCIÓN #59: `add_role()` no `set_role()` (confirmación LECCIÓN #38)

**Error:** Confirmación de la lección #38 — este error se repitió en múltiples lugares.

**Regla preventiva:** En WordPress, para AÑADIR un rol a un user sin perder los existentes, SIEMPRE usar `$user->add_role()`. `set_role()` REEMPLAZA todos los roles. Verificar con `grep -rn "set_role" includes/` periódicamente.

### LECCIÓN #60: CSP compliance requiere 0 inline handlers

**Error:** Múltiples vistas tenían inline handlers (`onclick`, `onchange`, etc.) que impedían usar headers CSP estrictos.

**Causa raíz:** Práctica común en WordPress legacy.

**Fix:** Auditoría completa (UIUX-AUDIT-001) que reemplazó TODOS los inline handlers con `addEventListener()` + `data-action` delegation.

**Regla preventiva:** El plugin está ahora 100% CSP-compliant (0 inline handlers). Para mantenerlo, NUNCA agregar `onclick=""`, `onchange=""`, etc. inline. Siempre usar `addEventListener()` en un `<script>` al final del view o en un archivo `.js` externo.

**Verificación periódica:**
```bash
grep -rn 'onclick=\|onchange=\|onfocus=\|onsubmit=\|onload=' includes/frontend/views/
# Debe devolver 0 resultados
```

---

*Este documento se actualiza cada vez que se encuentra un nuevo error durante el desarrollo. La última actualización fue el 2026-07-08 (v2.9.98) con 60 lecciones documentadas (35 originales + 25 nuevas de los audits REG/DEEP/UIUX).*

---

## 13. v2.9.99-102 — Lecciones de la sesión de estabilización (10 lecciones nuevas)

### LECCIÓN #61: `.min` suffix sin archivo — JS nunca carga en producción

**Error:** `LTMS_ENVIRONMENT='production'` hace que `$suffix='.min'`, pero la mayoría de JS no tenían versión `.min`. El navegador recibía 404 y el JS del dashboard nunca cargaba.

**Causa raíz:** No había build pipeline. Los `.min` se generaban manualmente y la mayoría nunca se crearon.

**Fix:** Crear `scripts/build.js` con terser + clean-css. CI verifica que todos los `.min` existan.

**Regla preventiva:** NUNCA usar `$suffix='.min'` en producción sin verificar que el archivo `.min` existe con `file_exists()`. El helper `get_suffix()` hace esta verificación.

### LECCIÓN #62: `current_user_can()` verifica capabilities, NO roles

**Error:** `current_user_can('ltms_vendor')` siempre devuelve `false` porque `ltms_vendor` es un ROL, no una CAPABILITY.

**Causa raíz:** Confusión común en WordPress. `current_user_can()` verifica capabilities, no roles.

**Fix:** Usar `LTMS_Utils::is_ltms_vendor(get_current_user_id())` que verifica el array de roles.

**Regla preventiva:** NUNCA usar `current_user_can('ltms_*')` para verificar roles. Usar `in_array('ltms_vendor', (array) $user->roles)` o `LTMS_Utils::is_ltms_vendor()`.

### LECCIÓN #63: `check_ajax_referer(..., false)` — el `false` significa "no morir", no "ignorar"

**Error:** 4 handlers en Mexico checkout llamaban `check_ajax_referer('nonce', 'nonce', false)` sin verificar el return value. El nonce se validaba pero el resultado se ignoraba → CSRF bypass.

**Causa raíz:** El tercer parámetro `$die=false` significa "no hacer wp_die() automáticamente", pero el return value debe verificarse manualmente.

**Fix:** `if ( ! check_ajax_referer('nonce', 'nonce', false) ) { wp_send_json_error(..., 403); }`

**Regla preventiva:** Siempre verificar el return de `check_ajax_referer()` cuando se usa `$die=false`.

### LECCIÓN #64: JS render*View sobreescribe vistas PHP

**Error:** El SPA tenía métodos `loadProductsView()`, `loadSettingsView()`, etc. que hacían `$('#ltms-view-xxx').html(...)` con versiones simplificadas, sobreescribiendo las vistas PHP cuidadosamente construidas.

**Causa raíz:** El JS y el PHP se desarrollaron por separado y el JS nunca se actualizó cuando las vistas PHP mejoraron.

**Fix:** Los métodos `load*View()` ahora solo llaman `showSection()` para mostrar el PHP renderizado.

**Regla preventiva:** Si una vista PHP tiene su propio JS inline, el método `load*View()` del SPA solo debe llamar `showSection()`. NUNCA renderizar HTML desde JS si ya existe una vista PHP.

### LECCIÓN #65: SiteGround anti-bot bloquea `/wp-admin/admin-ajax.php`

**Error:** SiteGround WAF bloquea POST requests a `/wp-admin/admin-ajax.php` cuando el User-Agent es de navegador. No se puede arreglar con `.htaccess` (es a nivel nginx).

**Causa raíz:** SiteGround escaló su anti-bot tras detectar tráfico anómalo.

**Fix:** Bypass vía frontend: rutear AJAX a `/?ltms_ajax=1` en lugar de `/wp-admin/admin-ajax.php`.

**Regla preventiva:** Si SiteGround bloquea `wp-admin`, rutear a través del frontend con un handler en `init`. Contactar a soporte para desactivar el anti-bot permanentemente.

### LECCIÓN #66: IIFE en JS externo se ejecuta antes de que el DOM esté listo

**Error:** Al extraer inline `<script>` a archivos JS externos, los IIFE se ejecutan inmediatamente pero los elementos del DOM pueden no existir todavía.

**Causa raíz:** `wp_enqueue_script` con `$in_footer=true` carga el JS después del HTML, pero no garantiza que `ltmsDashboard` esté disponible.

**Fix:** Envolver en `document.addEventListener('DOMContentLoaded', ...)` o `jQuery(function($) { ... })`.

**Regla preventiva:** TODO JS externo que referencia elementos del DOM debe estar envuelto en un DOM-ready handler.

### LECCIÓN #67: `LTMS_Encryption::encrypt()` no existe — la clase correcta es `LTMS_Core_Security`

**Error:** El handler de drivers llamaba `LTMS_Encryption::encrypt()` que no existe. Los document numbers se almacenaban en plaintext.

**Causa raíz:** Confusión de nombres. La clase de cifrado se llama `LTMS_Core_Security`, no `LTMS_Encryption`.

**Fix:** Usar `LTMS_Core_Security::encrypt()` con `class_exists()` guard.

**Regla preventiva:** Siempre usar `class_exists()` antes de llamar métodos estáticos. Verificar el nombre de la clase con `grep -rn "class LTMS_"`.

### LECCIÓN #68: `wpdb->insert` con format array desalineado

**Error:** 9 campos de datos pero 10 formatos, con `status='active'` (string) usando `%d` (integer). El INSERT silenciosamente guardaba `status=0`.

**Causa raíz:** El format array se copió de otra tabla sin verificar que los campos coincidieran.

**Fix:** 9 formatos correctos, `status='%s'`.

**Regla preventiva:** SIEMPRE verificar que el count de `$data` y `$format` coincidan en `$wpdb->insert()`. Usar PHPStan para detectar esto estáticamente.

### LECCIÓN #69: WAF inspecciona POST body de vendors autenticados

**Error:** El firewall del plugin inspeccionaba `$_POST` de vendors autenticados buscando patrones de ataque. Descripciones de productos legítimas con palabras como "SELECT" o "UPDATE" eran bloqueadas.

**Causa raíz:** El WAF no excluía a vendors autenticados de la inspección de patrones.

**Fix:** Agregar `is_authenticated_vendor()` check para saltar la inspección de patrones (la verificación de IP blacklist sigue activa).

**Regla preventiva:** El WAF solo debe inspeccionar patrones de usuarios no autenticados. Los usuarios autenticados ya tienen nonce + capability checks en cada handler.

### LECCIÓN #70: Sin CI = bugs críticos no detectados

**Error:** 10 bugs críticos (KDS roto, .min 404, encryption inexistente, etc.) llegaron a producción sin ser detectados.

**Causa raíz:** No había CI. Los commits iban directo a main sin verificación automática.

**Fix:** GitHub Actions CI con 5 checks: PHP syntax, JS syntax, CSP compliance, alert/confirm, .min sync.

**Regla preventiva:** TODO commit a main debe pasar CI. Los checks mínimos son: syntax (PHP+JS), CSP compliance, y .min sync. Considerar agregar PHPCS + PHPUnit en el futuro.

---

*Este documento se actualiza cada vez que se encuentra un nuevo error durante el desarrollo. La última actualización fue el 2026-07-15 (v2.9.116) con 80 lecciones documentadas.*

---

## 13. v2.9.113-116 — Lecciones de Auditorías de Ciclo de Vida (REG/KYC/PAYOUTS/WALLET)

> 10 lecciones nuevas de las 4 auditorías completas del ciclo de vida del vendedor: Registration (16 bugs), KYC (16 bugs), Payouts (14 bugs), Wallet/Comisiones (9 bugs). Total: 55 bugs fixeados en una sesión.

### LECCIÓN #71: `strpos() === 0` vs `strpos() === false` para checks de pertenencia

**Error:** El check IDOR de KYC usaba `strpos($path, $expected_prefix) !== 0` para validar que el path pertenece al vendor. Pero el upload handler retorna vault URLs como `https://site.com/ltms-vault/kyc/168/uuid.pdf`, donde `kyc/168/` aparece en posición 30, no 0. El check fallaba para el 100% de los submits.

**Causa raíz:** Confusión entre "empieza con" (`=== 0`) y "contiene" (`!== false`). El check fue escrito asumiendo que los paths serían bare B2 keys (`kyc/168/uuid.pdf`), pero el upload handler devuelve vault URLs.

**Fix:** Cambiar `strpos($path, $expected_prefix) !== 0` por `strpos($path, $expected_prefix) === false` (negar la pertenencia en vez de verificar el prefijo exacto).

**Regla preventiva:** Para checks de seguridad tipo "este path pertenece a este vendor", usar `strpos() === false` (no contiene) en vez de `strpos() !== 0` (no empieza con). El primero es más permisivo y soporta URLs completas.

### LECCIÓN #72: `current_user_can('ltms_vendor')` siempre false — los roles NO son capabilities

**Error:** 6 locations usaban `current_user_can('ltms_vendor')` para verificar si el usuario es vendor. Pero `ltms_vendor` es un ROL, no una CAPABILITY. `current_user_can()` solo devuelve true para capabilities, no para roles. Todos estos checks fallaban silenciosamente.

**Causa raíz:** Confusión entre roles (conjuntos de capabilities) y capabilities individuales. WordPress no expone roles via `current_user_can()`.

**Fix:** Usar `LTMS_Utils::is_ltms_vendor($user_id)` que hace `in_array('ltms_vendor', (array) $user->roles)`.

**Regla preventiva:** NUNCA usar `current_user_can('ltms_vendor')` o cualquier nombre de rol. Siempre usar `LTMS_Utils::is_ltms_vendor()` o `in_array('role_name', (array) $user->roles)`.

### LECCIÓN #73: NaN slips through every numeric comparison

**Error:** `execute_transaction()` en Wallet aceptaba montos NaN. `NaN > 0` es false, `NaN <= 0` es false, `NaN === 0` es false — NaN slips through every check. `bcadd('100', 'NaN')` retorna '0'. Resultado: wallet tx registra amount=NaN pero aplica 0 balance change → desbalances silenciosos.

**Causa raíz:** Las validaciones asumían que `> 0` y `<= 0` cubren todos los casos. Pero NaN no es ni > ni <= ni === a ningún número.

**Fix:** Agregar `if (is_nan($amount) || is_infinite($amount)) throw ...` al entry point de toda función que reciba montos.

**Regla preventiva:** Toda función que reciba `float $amount` debe validar `is_nan($amount) || is_infinite($amount)` ANTES de cualquier comparación. NaN e INF no son valores monetarios válidos.

### LECCIÓN #74: Montos negativos invierten credit/debit semantics

**Error:** `execute_transaction()` aceptaba montos negativos. `credit(-100)` actúa como `debit(100)` porque `bcadd('100', '-100')` = '0'. Un atacante podría extraer fondos via credit negativo.

**Causa raíz:** El check `$amount <= 0` estaba en `credit()` y `debit()` pero NO en `execute_transaction()` (el método interno). Los métodos `_within_transaction` (que llaman execute_transaction directamente) no validaban.

**Fix:** Agregar `if ($amount < 0) throw ...` al entry point de execute_transaction.

**Regla preventiva:** Toda función financiera que acepte montos debe rechazar negativos al entry point, sin importar si los callers ya validan. Defense in depth: validar en el método público Y en el interno.

### LECCIÓN #75: WHERE clause con columna inexistente = 0 rows affected = silent failure

**Error:** `ajax_unfreeze_wallet()` usaba `$wpdb->update($table, $data, ['user_id' => $vendor_id])` pero la columna en `lt_vendor_wallets` es `vendor_id`, no `user_id`. El UPDATE afectaba 0 rows. El admin veía "success" pero la wallet quedaba congelada PARA SIEMPRE.

**Causa raíz:** El developer copió el patrón de `wp_users` (que usa `ID` o `user_id`) sin verificar el schema de `lt_vendor_wallets`. MySQL no error si la columna del WHERE no existe — solo afecta 0 rows.

**Fix:** Usar `Wallet::unfreeze()` con la columna correcta `vendor_id`.

**Regla preventiva:** SIEMPRE verificar el schema de la tabla antes de escribir WHERE clauses. MySQL no errora en columnas inexistentes del WHERE — silenciosamente afecta 0 rows. Usar `$wpdb->update()` con `|| false` check no es suficiente; verificar `$result > 0` o usar `mysql_affected_rows()`.

### LECCIÓN #76: Handler AJAX registrado dos veces = race condition

**Error:** `wp_ajax_ltms_upload_kyc_document` estaba registrado en `LTMS_Media_Guard::init()` Y en `LTMS_Dashboard_Logic::init()`. Media_Guard se registraba primero (kernel order), así que ganaba. Dashboard_Logic era dead code que habría re-subido el archivo a B2 si Media_Guard fallaba.

**Causa raíz:** Dos clases independientes registraron el mismo action sin coordinación. No hay warning de PHP ni de WordPress cuando esto pasa.

**Fix:** Remover la registración duplicada en Dashboard_Logic.

**Regla preventiva:** Antes de registrar un `add_action('wp_ajax_X', ...)`, grep por `wp_ajax_X` en todo el codebase para verificar que no esté ya registrado. WordPress permite múltiples callbacks para el mismo action sin warning.

### LECCIÓN #77: Nonce action string mismatch = always fail for legit users

**Error:** `commission-writer ajax_backfill()` usaba `check_ajax_referer('ltms_backfill', 'nonce')` pero el nonce action `'ltms_backfill'` nunca se creaba via `wp_create_nonce()` en ningún lado. El check siempre fallaba para admins legítimos.

**Causa raíz:** El developer asumió que el nonce se crearía automáticamente, o planeaba crearlo en la UI admin pero nunca lo hizo.

**Fix:** Usar el nonce estándar `ltms_admin_nonce` que ya se crea en todas las views admin.

**Regla preventiva:** Toda llamada a `check_ajax_referer('action_name', 'nonce')` debe tener un `wp_create_nonce('action_name')` correspondiente en algún lugar del código (típicamente en la view que renderiza el form). Grep por ambos antes de commit.

### LECCIÓN #78: Vault URL vs bare B2 key — normalizar antes de comparar

**Error:** Múltiples lugares asumían que los paths de documentos KYC son bare B2 keys (`kyc/168/uuid.pdf`). Pero el upload handler devuelve vault URLs (`https://site.com/ltms-vault/kyc/168/uuid.pdf`). Las comparaciones fallaban.

**Causa raíz:** No había un helper central para normalizar paths. Cada lugar hacía su propio parsing (o no lo hacía).

**Fix:** Crear `extract_b2_key_from_path()` que acepta ambos formatos y devuelve el bare key.

**Regla preventiva:** Cuando un sistema tiene múltiples formatos de path (URL vs key vs path relativo), crear UN helper de normalización y usarlo en TODOS los lugares que comparan o almacenan paths. No dejar que cada caller haga su propio parsing.

### LECCIÓN #79: Country-aware validation — no asumir que el site country = vendor country

**Error:** KYC `country_code` se tomaba de `LTMS_COUNTRY` (site-wide constant), no del vendor meta. Un vendor MX registrándose en un site CO recibía `country_code='CO'` en su KYC. Lo mismo para document_type whitelist (siempre CO) y CLABE validation (6-20 dígitos en vez de 18 exactos para MX).

**Causa raíz:** El código fue escrito asumiendo un solo país por site. Cuando se agregó soporte MX, no se actualizaron todas las validaciones para ser country-aware.

**Fix:** Leer `ltms_country` del vendor meta con fallback a `LTMS_Core_Config::get_country()`. Tener whitelists y regex separados para CO y MX.

**Regla preventiva:** Toda validación que dependa del país (document types, formatos de cuenta bancaria, retenciones fiscales) debe leer el país del vendor meta, NO del site config. Un site CO puede tener vendors MX y viceversa.

### LECCIÓN #80: GitHub HTTP cache en SiteGround = fetch intermitente

**Error:** El deploy webhook hace `git fetch origin` en SiteGround. A veces el fetch trae el último commit, a veces trae un commit stale (varios commits atrás). Esto causó que el deploy de v2.9.116 requiriera múltiples triggers.

**Causa raíz:** SiteGround tiene un proxy HTTP que cachea las respuestas de GitHub. El cache tiene un TTL corto pero no determinístico — a veces sirve stale, a veces fresh.

**Fix:** Hacer un empty commit para forzar un nuevo ref en GitHub (invalida el cache). Re-trigger el webhook múltiples veces hasta que el fetch traiga el último commit.

**Regla preventiva:** Si el deploy webhook muestra `HEAD is now at <commit-viejo>` cuando GitHub ya tiene un commit más reciente, hacer un empty commit (`git commit --allow-empty -m "force refresh"`) y re-push. Esto invalida el cache HTTP de GitHub en SiteGround. Considerar agregar `git fetch --no-cache` o `git remote set-url` con un timestamp en el webhook futuro.

### LECCIÓN #81: Meta key mismatch — verificar consistencia entre writer y reader

**Error:** `get_policy_for_booking()` leía `_ltms_policy_id` pero `create_booking()` guarda en `_ltms_booking_policy_id` (different key). La política de cancelación SIEMPRE caía al default del vendor, las políticas específicas por producto NUNCA se aplicaban.

**Causa raíz:** Dos métodos independientes (writer y reader) usaban meta keys ligeramente diferentes sin coordinación. No hay warning de PHP ni de WordPress cuando esto pasa.

**Fix:** Probar ambas meta keys + booking row's `policy_id` column.

**Regla preventiva:** Toda meta key que se escribe en un lugar debe leerse con el MISMO nombre en otro. Grep por el meta key completo (`_ltms_policy_id` vs `_ltms_booking_policy_id`) para verificar consistencia entre writer y reader. Considerar centralizar meta keys en constantes de clase.

### LECCIÓN #82: Double refund sin idempotencia en cancelaciones

**Error:** `process_cancellation_refund()` no tenía protección contra double refund. Si `cancel_booking` se llamaba dos veces (race condition o retry), `wc_create_refund` creaba DOS refund objects → double money back al cliente.

**Causa raíz:** El método no verificaba si ya existía un refund para ese booking antes de crear uno nuevo. WooCommerce's `wc_create_refund` no es idempotente por defecto.

**Fix:** Verificar refunds existentes por reason prefix (que incluye el booking_id) antes de crear uno nuevo.

**Regla preventiva:** Toda función que cree refunds, payouts, o transacciones financieras debe ser idempotente. Verificar si ya existe una transacción con el mismo identificador (booking_id, order_id, idempotency_key) antes de crear una nueva. Para refunds de WC, buscar en `$order->get_refunds()` por reason prefix.

### LECCIÓN #83: AJAX handler sin verificación de rol específico

**Error:** `ajax_save_vendor_policy()` no verificaba que el usuario fuera vendor. Cualquier logged-in user (incluido customers con rol `subscriber` o `customer`) podía llamarlo y crear/editar políticas de cancelación.

**Causa raíz:** El handler solo tenía `check_ajax_referer` (que verifica sesión) pero no verificaba el rol. `is_user_logged_in()` no es suficiente — cualquier cuenta registrada pasa el check.

**Fix:** Agregar `LTMS_Utils::is_ltms_vendor()` check al inicio del handler.

**Regla preventiva:** TODO AJAX handler que modifique datos de vendor debe verificar `LTMS_Utils::is_ltms_vendor()` además de `check_ajax_referer`. `is_user_logged_in()` solo verifica sesión, no rol. Los customers tienen cuentas válidas pero no deben acceder a endpoints de vendor.

### LECCIÓN #84: IDOR en endpoints de save/update con ID numérico

**Error:** `ajax_save_vendor_policy()` IDOR — un vendor podía pasar `policy_id` de OTRA vendor's policy y el método `save_policy` intentaría UPDATE (con vendor_id en WHERE, 0 rows affected) luego INSERT. El risk real: probe policy_ids para descubrir nombres/tipos de políticas ajenas.

**Causa raíz:** El handler recibía `policy_id` del cliente pero no verificaba ownership antes de pasarlo a `save_policy`. Aunque `save_policy` tiene `vendor_id` en el WHERE del UPDATE, el INSERT fallback permite crear políticas duplicadas.

**Fix:** Verificar ownership del policy_id antes de update + log `BOOKING_POLICY_IDOR_ATTEMPT`.

**Regla preventiva:** TODO endpoint AJAX que acepte un ID numérico (policy_id, booking_id, order_id, driver_id) debe verificar ownership antes de procesar. El patrón: `SELECT vendor_id FROM table WHERE id = %d` → comparar con `get_current_user_id()`. Log security event si mismatch.

### LECCIÓN #85: valorrecaudo (cash-on-delivery) sin bound = fraude

**Error:** `ajax_generar_guia()` `valorrecaudo` (cash-on-delivery amount) no tenía bound. Vendor podía declarar recaudo inflado (customer paga más en delivery) o 0 recaudo para pedido pagado (vendor pocketing cash).

**Causa raíz:** El handler aceptaba cualquier valor entero de `$_POST['valorrecaudo']` sin verificar contra el total del pedido. Aveonline procesa el recaudo literalmente — si el vendor dice 500000, el transportador cobra 500000 al cliente.

**Fix:** Verificar `valorrecaudo <= order total` cuando el order existe.

**Regla preventiva:** TODO campo financiero que represente un monto a cobrar/pagar (valorrecaudo, delivery_price, payout_amount) debe tener bounds razonables. Para cash-on-delivery, el monto nunca debe superar el total del pedido. Para delivery_price, cap a un máximo configurable. Validar contra el order total cuando aplique.

### LECCIÓN #86: Webhook fail-open cuando secret/token no configurado

**Error:** Los webhook handlers de Alegra y Siigo eran fail-open: si el secret/token estaba vacío, el check de auth se skipeaba completamente. Cualquier atacante podía enviar webhooks forjados.

**Causa raíz:** El patrón `if ($expected) { ... check ... }` skipea el check cuando el secret está vacío. Otros handlers (Stripe, Openpay, Zapsign, Addi, Uber) ya eran fail-closed, pero Alegra y Siigo se quedaron con el patrón viejo.

**Fix:** Cambiar a fail-closed: `if (empty($expected)) { return 403; }` antes del check.

**Regla preventiva:** TODO webhook handler debe ser fail-closed: si el secret/token no está configurado, RECHAZAR el webhook con 403. Nunca usar `if ($expected) { check }` — usar `if (empty($expected)) { reject; } check;`.

### LECCIÓN #87: Inline onclick reemplazado por data-* SIN agregar JS = botones rotos

**Error:** Al reemplazar inline `onclick` con `data-action`/`data-confirm` attributes para cumplimiento CSP, NO se agregó el JavaScript que escucha esos attributes. Los botones de admin dejaron de funcionar.

**Causa raíz:** El fix CSP fue incompleto — se hizo el cambio de HTML pero no el cambio de JS correspondiente.

**Fix:** Agregar jQuery event delegation: `$(document).on('click', '[data-action]', function() { ... })` que dispatcha según el valor de `data-action`.

**Regla preventiva:** Al migrar de inline onclick a data-* attributes, SIEMPRE agregar el JS de event delegation en el MISMO commit. Un cambio CSP sin el JS correspondiente es una regresión.

### LECCIÓN #88: .min.js desactualizado después de modificar .js

**Error:** Se modificó `ltms-admin.js` pero no se regeneró `ltms-admin.min.js`. En producción, el plugin carga `.min.js` (via `get_suffix()`), así que los cambios no se reflejaban.

**Causa raíz:** Olvido de ejecutar `npm run build` después de modificar JS.

**Fix:** Ejecutar `npm run build` para regenerar todos los `.min.js` y `.min.css`.

**Regla preventiva:** DESPUÉS de modificar cualquier archivo `.js` o `.css`, ejecutar `npm run build` ANTES de commit. El CI verifica que `.min` files estén sincronizados, pero solo en GitHub Actions — localmente no hay check.

### LECCIÓN #89: Webhook file list hardcoded — archivos no se sincronizan

**Error:** El deploy webhook tiene una lista hardcoded de archivos a sincronizar desde GitHub. Si se modifica un archivo que NO está en la lista, ese archivo no se actualiza en producción via webhook.

**Causa raíz:** El webhook fue diseñado con una lista mínima de archivos críticos. A medida que se modificaron más archivos, la lista se quedó corta.

**Fix:** Actualizar la lista del webhook con TODOS los archivos modificados (de 16 a 56 archivos).

**Regla preventiva:** Al agregar archivos modificados al repo, verificar que estén en la lista del webhook `deploy/ltms-deploy-webhook.php`. Idealmente, el webhook debería hacer `git reset --hard origin/main` (que sí sincroniza todo) en lugar de descargar archivos individuales.

### LECCIÓN #90: Nonce action mismatch entre PHP y JS = endpoint roto

**Error:** 4 admin AJAX handlers en Donations tenían `check_ajax_referer('ltms_admin_nonce', 'nonce')` seguido de `$this->verify()` que usa `NONCE_ACTION='ltms_admin_donations'`. El JS enviaba nonce de `wp_create_nonce('ltms_admin_donations')`, así que el primer check SIEMPRE fallaba y `wp_die()`'d.

**Causa raíz:** Doble check de nonce con actions diferentes. El primer check (legacy SEC-3 FIX) usaba el nonce estándar del plugin, pero el JS usaba un nonce específico del módulo.

**Fix:** Remover el check duplicado, manteniendo solo `$this->verify()` con el nonce correcto.

**Regla preventiva:** NUNCA tener dos `check_ajax_referer` con actions diferentes en el mismo handler. Verificar qué nonce envía el JS (`wp_create_nonce`) y usar exactamente ese action en el `check_ajax_referer` del PHP.

---

## Lecciones v2.9.143 → v2.9.160 (Full-Stack Audit, 81 bugs fixeados)

### Lección #91: Regresiones por fixes incompletos
**Error:** El fix P0-1 de v2.9.115 (payout-scheduler) cambió `available = max(0, balance - held)` pero `hold()` ya resta de `balance` atómicamente — doble-resta bloqueaba todos los payouts después de cualquier hold.
**Fix:** Revertido a `available = balance`. El double-spend que P0-1 intentaba prevenir ya está bloqueado por el balance check dentro de la transacción de `hold()`.
**Regla preventiva:** Antes de cambiar cálculos de balance, trazar el flujo completo: `hold()` → `balance -= amount; balance_pending += amount`. El `balance` resultante YA es el free balance.

### Lección #92: Double-refund check por string matching
**Error:** El fix P0-2 de v2.9.117 (booking) prevenía double-refund buscando el booking_id en el REASON del refund via `stripos()`. Roto por (1) traducción (prefix español vs reason `__()`) y (2) colisión de substring (#1 matchea #11).
**Fix:** Almacenar `booking_id` como post meta del refund (`_ltms_booking_id`) y verificar via `get_post_meta()`.
**Regla preventiva:** NUNCA usar string matching para deduplicación. Usar metadatos estructurados (post meta, user meta, o columnas dedicadas).

### Lección #93: do_action dentro de try después de COMMIT
**Error:** En `wallet.php`, `do_action('ltms_wallet_tx_committed')` estaba dentro del try block DESPUÉS de `$wpdb->query('COMMIT')`. Si un listener lanzaba excepción, el catch llamaba ROLLBACK — pero la transacción ya estaba committed. La excepción se propagaba al caller, que creía que la operación falló y reintentaba → double credit.
**Fix:** Envolver el do_action + logging en un try/catch anidado que traga errores no-críticos.
**Regla preventiva:** NUNCA ejecutar hooks (`do_action`) dentro de un try block que tiene ROLLBACK en el catch, si la transacción ya está committed. Los hooks son side-effects que no deben afectar el resultado de la transacción.

### Lección #94: Role check incorrecto en 2FA enforcement
**Error:** `enforce_2fa_for_payout_vendors()` chequeaba el rol `'vendor'` que NO EXISTE en este plugin (los roles reales son `'ltms_vendor'` y `'ltms_vendor_premium'`). 2FA enforcement NUNCA se disparaba para vendors reales.
**Fix:** `array_intersect(['ltms_vendor', 'ltms_vendor_premium', 'vendor'], $user->roles)`.
**Regla preventiva:** Verificar los roles registrados en `class-ltms-roles.php` antes de escribir checks de `in_array('role_name', $user->roles)`.

### Lección #95: Fail-open en screening de sanciones
**Error:** Cuando la descarga de una lista de sanciones (OFAC/UN/EU) fallaba, el código hacía `continue` (saltaba la lista y aprobaba el vendor sin screening). Violación directa de SARLAFT.
**Fix:** FAIL-CLOSED — bloquear KYC hasta que la lista pueda ser obtenida.
**Regla preventiva:** En compliance, SIEMPRE fail-closed. Si un recurso externo no está disponible, bloquear la operación, no continuar.

### Lección #96: Arbitrary option overwrite via settings save
**Error:** El loop de guardado de settings llamaba `update_option($key, $value)` para CUALQUIER key en `$sanitized`, incluyendo opciones core de WordPress (admin_email, siteurl, default_role). Un CSRF podía comprometer el sitio completamente.
**Fix:** Solo keys con prefijo `ltms_` son guardadas.
**Regla preventiva:** NUNCA hacer `update_option` con keys arbitrarias del input del usuario. Usar whitelist o prefix check.

### Lección #97: TOCTOU en creación de guías
**Error:** El check de deduplicación de guías (`SELECT numguia WHERE order_id`) no era atómico con `create_shipment()` + `db_insert()`. Dos POSTs concurrentes ambos pasaban el check y ambos llamaban Aveonline → dos guías reales facturadas.
**Fix:** Transient lock antes del API call, liberado en success/error.
**Regla preventiva:** Para operaciones que llaman APIs pagas externas, SIEMPRE usar un lock (transient o DB) antes del API call, no solo un SELECT de deduplicación.

### Lección #98: Inline scripts y CSP compliance
**Error:** 22+ bloques `<script>` inline en views PHP violaban CSP `script-src 'self'`. Cada uno era una superficie de XSS potencial.
**Fix:** Extracción a 17 archivos JS externos + `wp_enqueue_script`. Variables PHP pasadas via `data-*` attributes o `ltmsDashboard` localized.
**Regla preventiva:** NUNCA escribir `<script>` inline en views PHP. Toda lógica JS debe estar en archivos externos en `assets/js/`.

### Lección #99: Ledger integrity — tx_id como boolean
**Error:** En `redi-order-split.php`, `origin_tx_id` y `reseller_tx_id` se almacenaban como `true`/`null` en vez del ID real de transacción del wallet. Ledger unreconcilable, audit trail roto, refund rollbacks imposibles.
**Fix:** Capturar `(int) Wallet::credit()` return value.
**Regla preventiva:** NUNCA descartar el return value de funciones que crean registros financieros. El ID de la transacción es crítico para auditoría y reconciliación.

### Lección #100: Dead code por type mismatch en filter callback
**Error:** `adjust_ica_for_pickup()` chequeaba `$order instanceof WC_Order` pero el filter `ltms_after_tax_calculate` pasa `$order_data` como ARRAY. El instanceof siempre fallaba → ICA tax para pickup orders NUNCA se ajustaba.
**Fix:** Manejar tanto array (tax engine) como WC_Order (legacy callers).
**Regla preventiva:** Al registrar un callback para un filter de WP, verificar el tipo del parámetro que el filter pasa — no asumir que es el tipo esperado.

### Lección #101: Flexbox stretch causa botón de 938px
**Error:** `form.cart { display: flex; flex-wrap: wrap; }` causaba `align-items: stretch` (default de flex), lo que estiraba TODOS los hijos del form a la altura del más alto (~938px, el grid de addon-items). El botón "Añadir al carrito" heredaba 938px de altura.
**Fix:** Cambiar `form.cart` a `display: block`. Solo el botón y el quantity usan `inline-flex` con `vertical-align: middle`.
**Regla preventiva:** NUNCA usar `display: flex` en un contenedor que tiene hijos de alturas muy diferentes (como un form con gift options, badges, grids de productos). Usar `display: block` y `inline-flex` solo en los elementos que necesitan alineación horizontal.

### Lección #102: Elementor CSS en body siempre gana sobre CSS en head
**Error:** Elementor inyecta estilos CSS directamente en el `<body>` (no en `<head>`). CSS en body SIEMPRE tiene prioridad sobre CSS en head, sin importar la especificidad o `!important`. Nuestro CSS crítico inline en `wp_head` era inútil contra Elementor.
**Fix:** Usar `template_include` filter para reemplazar completamente las plantillas de Elementor con templates nativos del plugin, eliminando el CSS de Elementor del body.
**Regla preventiva:** Cuando un theme builder (Elementor, Divi, WPBakery) inyecta CSS en body, NO intentar override desde head. Reemplazar el template completo via `template_include`.

### Lección #103: Anonymous classes NO capturan variables del scope externo
**Error:** En tests unitarios, anonymous classes definidas dentro de `setUp()` intentaban acceder a `$this->test->queries[]` pero `$test` no estaba capturado (las anonymous classes en PHP NO auto-capturan variables del scope externo como sí lo hacen las closures con `use`).
**Fix:** Pasar la referencia via constructor: `new class($self) { public function __construct($test) { $this->test = $test; } }`.
**Regla preventiva:** Las anonymous classes en PHP NO capturan variables del scope externo automáticamente. SIEMPRE pasar variables via constructor.

### Lección #104: Brain\Monkey no puede stubbear funciones PHP nativas
**Error:** Intentar stubbear `file_exists`, `fopen`, `fclose`, `fputcsv` con `Functions\stubs()` causaba `Patchwork\Exceptions\NotUserDefined` porque estas son funciones internas de PHP que Patchwork no puede redefinir sin configuración especial (`redefinable-internals`).
**Fix:** Remover las funciones PHP nativas de los stubs. La clase bajo test solo las llama en rutas no ejercidas por los tests.
**Regla preventiva:** NUNCA intentar stubbear funciones PHP nativas con Brain\Monkey. Solo se pueden stubbear funciones definidas por el usuario (WP functions).

### Lección #105: Mocks wpdb deben respetar el parámetro $output
**Error:** Los mocks de `$wpdb->get_row()` siempre retornaban `(object)[...]` sin importar el parámetro `$output`. Pero métodos como `LTMS_Deposit::get()` llaman `get_row($sql, ARRAY_A)` y tienen return type `?array`. Retornar un `stdClass` causaba `TypeError: Return value must be of type ?array, stdClass returned`.
**Fix:** Hacer que el mock sea condicional: `return $o === ARRAY_A ? $row : (object)$row;`.
**Regla preventiva:** Los mocks de `$wpdb->get_row()` y `get_results()` SIEMPRE deben respetar el parámetro `$output` (OBJECT, ARRAY_A, ARRAY_N).

### Lección #106: WP_User ya está stubbeado en RolesTest.php
**Error:** Se definió un stub `WP_User` en `FintechComplianceTest.php` con constructor `(int $id, string $name)`, pero `RolesTest.php` ya definía `WP_User` con constructor `(int $id, array $roles)`. Como ambos usaban `if (!class_exists())`, solo el primero cargado ganaba.
**Fix:** Remover el stub duplicado y reutilizar el de `RolesTest.php` con su firma de constructor.
**Regla preventiva:** ANTES de definir un stub de clase global, buscar si ya existe en otro archivo de test. Reutilizar, no redefinir.

### Lección #107: LTMS_PATH no existe — usar LTMS_PLUGIN_DIR
**Error:** `LTMS_Native_Templates::init()` usaba `LTMS_PATH` para resolver el directorio de templates, pero esa constante NUNCA se define en el plugin. El plugin define `LTMS_PLUGIN_DIR` (línea 63) pero no `LTMS_PATH`. El guard `if (!defined('LTMS_PATH')) return;` causaba que `init()` abortara silenciosamente en cada page load.
**Fix:** Usar `defined('LTMS_PATH') ? LTMS_PATH : (defined('LTMS_PLUGIN_DIR') ? LTMS_PLUGIN_DIR : dirname(__DIR__, 3) . '/')`.
**Regla preventiva:** ANTES de usar una constante del plugin, verificar que esté definida en el archivo principal con `grep -n "define.*CONSTANT_NAME"`.

### Lección #108: SiteGround Optimizer combina CSS en archivo cacheado
**Error:** SiteGround Optimizer combina todos los CSS en un solo archivo (`siteground-optimizer-combined-css-*.css`). Cuando se cambia un CSS source, el combined CSS NO se regenera automáticamente. El navegador recibe el CSS viejo combinado.
**Fix:** Purgar manualmente el cache de SiteGround (Site Tools → Speed → Caching → Clear Cache) después de cada cambio CSS.
**Regla preventiva:** En sitios con SiteGround Optimizer, los cambios CSS requieren purga manual del cache. El versionado de `wp_enqueue_style` NO es suficiente porque SG combina los archivos.

### Lección #109: Deploy webhook puede ser bloqueado por SiteGround captcha
**Error:** El deploy webhook (`/ltms-deploy-webhook.php`) puede ser bloqueado por el captcha de SiteGuard (SiteGround's bot protection). El webhook devuelve 202 (redirect a captcha) en vez de 200, y el deploy no se ejecuta.
**Fix:** Ejecutar el webhook desde el contexto de un navegador (que ya pasó el captcha) usando `fetch()` desde `agent-browser`.
**Regla preventiva:** Cuando un endpoint del servidor esté detrás de SiteGround captcha, ejecutarlo desde un contexto de navegador, no via curl directo.

### Lección #111: Deploy webhook descarga archivos individualmente — no hace git pull completo
**Error:** El deploy webhook NO hace `git pull`. Descarga archivos individuales desde la API de GitHub usando una lista hardcoded `$files`. Los nuevos archivos (Plaza Viva templates, CSS, JS) NO estaban en esa lista, por lo que nunca se desplegaron al servidor.
**Causa de la caída del sitio:** El `lt-marketplace-suite.php` (que SÍ estaba en la lista) se actualizó con `require_once class-ltms-native-templates.php`, pero el archivo `class-ltms-native-templates.php` NO estaba en la lista del webhook → el servidor tenía la versión vieja con `LTMS_PATH` hardcoded → PHP Fatal: Undefined constant "LTMS_PATH" → sitio caído.
**Fix:** Añadir todos los archivos Plaza Viva a la lista `$files` del webhook + envolver `require_once + init()` en try-catch para que un error en la clase no tumbe el sitio.
**Regla preventiva:** Cuando se añadan nuevos archivos PHP al plugin, SIEMPRE añadirlos a la lista `$files` en `deploy/ltms-deploy-webhook.php`. Además, envolver cualquier `require_once` de clases nuevas en `try-catch` para evitar que un error de carga tumbe el sitio completo.

### Lección #112: Git fetch en servidor puede quedarse atascado en un commit
**Error:** El `git fetch origin` en el servidor SiteGround no traía los commits más recientes. Se quedaba atascado en `621d338` aunque GitHub ya tenía commits `172a8ed`, `3d4d73c`, `19cea6b`, `057f784`. El `git reset --hard origin/main` reseteaba al commit obsoleto.
**Fix:** Ejecutar manualmente via SSH: `git fetch origin && git reset --hard origin/main`.
**Regla preventiva:** Cuando el deploy webhook muestre un commit obsoleto en "HEAD is now at...", conectar via SSH y ejecutar `git fetch origin && git reset --hard origin/main` manualmente.

### Lección #113: Elementor Pro + template_include + WC conditional functions = fatal
**Error:** El filter `template_include` a prioridad 99 llamaba `is_shop()`, `is_product_category()`, etc. Estas funciones de WC disparan WC query setup. Cuando Elementor Pro Theme Builder está activo, este query setup conflictúa con la carga de templates de Elementor, causando un PHP fatal en `/tienda/` y `/categoria-producto/*`.
**Causa raíz:** NO era nuestro template `archive-product.php`. Incluso la versión minimal (90 líneas, solo WC hooks) causaba el mismo error. El problema era que `is_shop()` dentro de `maybe_override()` disparaba el query setup de WC que chocaba con Elementor.
**Fix:** Detectar Elementor al inicio de `maybe_override()` con `did_action('elementor/loaded')`. Si Elementor está activo, SOLO override single-product, cart y checkout (páginas donde Elementor no tiene Theme Builder template). Para shop/category/tag/account, retornar el template original inmediatamente sin llamar ninguna función WC condicional.
**Regla preventiva:** Cuando uses `template_include` con Elementor Pro activo, SIEMPRE verificar `did_action('elementor/loaded')` antes de llamar `is_shop()`, `is_product_category()`, etc. Elementor tiene su propio handler de template_include que conflictúa con el query setup de WC.

---

## 14. v2.9.222-239 — Skeleton Loader, Refresco de Nonce (Heartbeat→AJAX propio), WAF, Sincronía Test/Código

> 10 lecciones nuevas (#114-#123) extraídas de la investigación y resolución del bug "panel de vendedor en blanco" (Asistente Ventas AI 3), el skeleton loader que se quedaba pegado en Inicio/Billetera/Envíos, y el fallo de CI causado por un test huérfano de una sesión anterior. Estas lecciones son especialmente relevantes para cualquier trabajo futuro sobre el dashboard SPA del vendedor, el router `?ltms_ajax=1`, o el WAF propio del plugin.

### Lección #114: `showSkeleton()` sin container explícito destruye el DOM de la sección visible

**Error:** `initSkeletonLoaders()` hace monkey-patch de `LTMS.Dashboard.loadView()` y llama `showSkeleton(view)` sin pasar el contenedor. La función buscaba genéricamente `.ltms-view-section` visible en ese momento y ejecutaba `target.innerHTML = template`, reemplazando (no cubriendo) el contenido real — métricas, tablas, `#ltms-wallet-tbody`, etc.

**Causa raíz:** Selector genérico ("cualquier sección visible") en vez de recibir el contenedor explícito de la vista que se está cargando.

**Fix:** `showSkeleton()` ahora inserta un `<div class="ltms-skeleton-overlay">` como hijo posicionado encima (`position:absolute; inset:0`), sin tocar el DOM original debajo.

**Regla preventiva:** Un loading-state NUNCA debe hacer `target.innerHTML = ...` sobre un contenedor con datos reales. Usar un overlay (elemento hijo posicionado encima) que se pueda remover sin haber destruido el contenido original.

### Lección #115: Función "consumidora" documentada en un comentario pero nunca escrita (dos veces en el mismo módulo)

**Error:** `initSkeletonLoaders()` mostraba el skeleton al navegar, pero `hideSkeleton()` — la función que debía revertirlo cuando llegaban los datos reales — no existía en ningún archivo del repo (cero ocurrencias). El `loadView()` original seguía su curso y actualizaba selectores que el skeleton ya había borrado; sin error visible, el skeleton se quedaba pegado para siempre. Este es el mismo patrón que causó por separado el bug de refresco de nonce (ver Lección #119): un comentario `FIX-403-NONCE` describía que "el JS ya consume esto en `initNonceRefresh()`", pero esa función tampoco existía todavía.

**Causa raíz:** Se escribió la mitad del mecanismo (mostrar/filtrar) y se asumió que la otra mitad (ocultar/consumir) ya existía o se agregaría después, sin confirmarlo.

**Fix:** Implementar `hideSkeleton(view)` real, enganchado vía `jQuery(document).on('ajaxComplete', ...)` filtrando por `action` que empiece con `ltms_get_`, más un timer de seguridad de 6s que auto-elimina el overlay si nada más lo hace.

**Regla preventiva:** Antes de dar por buena una función que "muestra" un estado temporal (loading, skeleton, modal, toast) o un filtro del lado servidor, hacer `grep` explícito por su contraparte (la función que lo oculta/revierte, o el JS que lo consume). Si no aparece con cero ocurrencias, no está implementada — no asumir que existe en otro archivo sin confirmarlo con evidencia.

### Lección #116: WP Heartbeat API puede estar desregistrado en hosting compartido con optimizadores

**Error:** Se implementó el refresco de nonce del dashboard vía `heartbeat_received` (WP Heartbeat API nativo). Al desplegar, las peticiones de `heartbeat.js` (que sigue corriendo en el núcleo de WP aunque nadie lo use explícitamente) empezaron a fallar con 400 `"Unknown action: heartbeat"` contra el router custom `?ltms_ajax=1`.

**Causa raíz:** SiteGround Optimizer (u otro plugin de rendimiento similar) desregistra `wp_ajax_heartbeat` en este hosting. Nada en el frontend usaba antes el Heartbeat API, así que el problema pasó desapercibido hasta que se agregó esta dependencia nueva.

**Fix:** Reemplazar por un endpoint AJAX propio (`wp_ajax_ltms_refresh_dashboard_nonce`), registrado y despachado por nuestro propio código, en vez de depender del ciclo de vida de Heartbeat.

**Regla preventiva:** En este hosting, NO asumir que el WP Heartbeat API nativo está disponible. Para cualquier polling periódico cliente-servidor nuevo, preferir un endpoint AJAX propio verificado end-to-end, no un hook del core que puede estar desactivado silenciosamente por el hosting.

### Lección #117: El router custom `?ltms_ajax=1` rechaza acciones de WP Core no contempladas en su whitelist

**Error:** El endpoint custom de bypass anti-bot (`?ltms_ajax=1`, creado para esquivar el bloqueo de SiteGround sobre `admin-ajax.php`) tiene su propia whitelist de acciones LTMS. Al depender de Heartbeat (Lección #116), WP Core empezó a mandar `action=heartbeat` por esa misma ruta reescrita (vía el filtro global de `admin_url`), y el router respondía `{"success":false,"data":{"message":"Unknown action: heartbeat"}}`.

**Causa raíz:** El filtro que reescribe `admin_url('admin-ajax.php')` hacia `?ltms_ajax=1` es global (aplica a CUALQUIER llamada AJAX del frontend, no solo a las de LTMS), pero el router del otro lado solo reconoce acciones LTMS explícitas.

**Fix:** Evitar depender de acciones nativas de WP Core (heartbeat, autosave, etc.) para código que pasará por este router.

**Regla preventiva:** Antes de agregar una dependencia nueva de script que dispara acciones AJAX nativas de WP, verificar si esas acciones pasan por el router custom `?ltms_ajax=1` y si están contempladas en su lista de acciones reconocidas.

### Lección #118: Nonce generado una sola vez en sesiones largas sin recarga = 403 diferido e intermitente

**Error:** El nonce del dashboard (`ltmsDashboard.nonce`) se generaba una sola vez al renderizar la página (`wp_create_nonce()` en `localize_dashboard_script()`) y nunca se refrescaba. Cuentas operadas por un asistente/agente que mantiene la pestaña abierta por horas sin recargar terminan con el nonce vencido (~24h), y desde ese punto cada AJAX del panel falla con 403.

**Causa raíz:** Falta de mecanismo de refresco activo — se asumió que el usuario recargaría la página periódicamente, como haría un humano típico.

**Fix:** Endpoint AJAX propio (`ajax_refresh_dashboard_nonce`) + polling desde `initNonceRefresh()` en JS que actualiza `ltmsDashboard.nonce` en memoria sin recargar la página.

**Regla preventiva:** Cualquier nonce embebido una sola vez al renderizar una SPA/dashboard de larga duración necesita un mecanismo de refresco activo — no asumir que "el usuario recargará antes de que expire", especialmente si la cuenta puede ser operada por un agente/bot que no cierra pestañas.

### Lección #119: Test desincronizado tras un cambio de enfoque a mitad de sesión rompe CI en un commit no relacionado, semanas después

**Error:** El primer intento de fix de nonce (Heartbeat, Lección #116) tenía un test (`FrontendAssetsNonceRefreshTest.php`) cubriendo el método `ltms_heartbeat_refresh_dashboard_nonce()`. Cuando se abandonó ese enfoque por el endpoint AJAX propio (`ajax_refresh_dashboard_nonce()`), el archivo de test NUNCA se actualizó ni se eliminó. Días después, un commit totalmente no relacionado (bump de `LTMS_VERSION` a 2.9.239 para el fix del skeleton loader) hizo fallar el CI completo porque la suite seguía ejecutando ese test huérfano contra un método que ya no existía.

**Causa raíz:** Al reemplazar una implementación a mitad de sesión, se actualizó el código de producción pero no su test — y como el test seguía siendo sintácticamente válido (solo fallaba en tiempo de ejecución al invocar un método inexistente), nada lo detectó localmente hasta la siguiente corrida completa de PHPUnit en CI.

**Fix:** Reescribir el test para cubrir el método real (`ajax_refresh_dashboard_nonce()`), reutilizando el patrón ya establecido en el proyecto (`Monkey\Functions\when()->alias()` + excepción para capturar `wp_send_json_success`/`wp_send_json_error`, ver `AdminPayoutsTest.php`) en vez de introducir un estilo nuevo.

**Regla preventiva:** Cuando se abandona un enfoque técnico a mitad de sesión (rename, refactor o pivote de diseño), el/los test(s) que cubrían el método viejo deben actualizarse o eliminarse en el MISMO commit que lo reemplaza — nunca dejarlos "para después". Un test huérfano no falla de inmediato si nadie corre la suite completa localmente, pero eventualmente rompe CI en un commit que no tiene nada que ver con el cambio original, generando una investigación costosa para encontrar una causa raíz vieja.

### Lección #120: `function_exists('NombreDeClase')` siempre es `false` — usar `class_exists()`

**Error:** En `class-ltms-firewall.php`, la excepción del WAF para vendedores autenticados en `?ltms_ajax=1` estaba guardada por `if ( function_exists( 'LTMS_Utils' ) && method_exists( 'LTMS_Utils', 'is_ltms_vendor' ) )`. `LTMS_Utils` es una `final class`, no una función — `function_exists()` sobre un nombre de clase SIEMPRE devuelve `false`, así que esa rama nunca se ejecutaba y el código caía siempre al fallback de roles (`str_starts_with($r, 'ltms_')`).

**Causa raíz:** Confusión entre `function_exists()` (funciones) y `class_exists()` (clases) al escribir un guard defensivo — al menos 2 ocurrencias idénticas del mismo error en el mismo archivo.

**Fix:** Cambiar a `class_exists( 'LTMS_Utils' ) && method_exists( 'LTMS_Utils', 'is_ltms_vendor' )` en ambas ocurrencias.

**Regla preventiva:** Antes de escribir un guard `function_exists()`/`class_exists()`, confirmar si el símbolo es una función o una clase (`grep -n "^class \|^final class "` vs `^function `). Un guard con la comprobación equivocada nunca lanza error — simplemente nunca se activa, y el código cae silenciosamente a una ruta alternativa que puede comportarse distinto al camino previsto, sin que nada lo señale como roto.

### Lección #121: El WAF no escribe en `error_log` — audita vía tabla propia `bkr_lt_security_events`

**Error:** Se buscó evidencia de bloqueos del WAF revisando `error_log`/`debug.log`, que estaban vacíos, lo que llevó a descartar erróneamente al WAF como causa de una serie de 403.

**Causa raíz:** `block_request()` no registra nada en los logs de texto de PHP — hace `wp_die()` con 403 directo. El único rastro real de un bloqueo por patrón queda en la tabla de auditoría `bkr_lt_security_events` (columnas: `created_at`, `event_type`, `ip_address`, `user_id`, `request_uri`, `rule_matched`, `payload`).

**Fix:** Consultar `bkr_lt_security_events` en vez de (o además de) los logs de archivo al investigar bloqueos del WAF: `SELECT created_at, event_type, ip_address, user_id, request_uri, rule_matched, payload FROM bkr_lt_security_events ORDER BY created_at DESC LIMIT 20;`.

**Regla preventiva:** "No hay nada en el `error_log`" NO significa "nada fue bloqueado". Antes de descartar el WAF/firewall propio como causa de un 403, verificar su tabla de auditoría dedicada, no solo los logs de PHP estándar.

### Lección #122: Alto volumen de peticiones fallidas de un endpoint no crítico puede enmascarar (o incluso causar) el diagnóstico de "todo está roto"

**Error:** El endpoint `ltms_get_social_proof` (toast de "compra reciente") fallaba con 403 en el 100% de sus llamadas porque el JS nunca enviaba el nonce requerido desde el fix de seguridad SEC-3. Al correr en un `setInterval` en cada página pública (incluido el panel de vendedor), generaba miles de peticiones fallidas por sesión, dando la falsa impresión de que "todo el panel estaba roto" cuando los endpoints core del dashboard (`ltms_get_dashboard_data`, etc.) en realidad respondían con éxito al probarlos de forma aislada.

**Causa raíz:** Un bug acotado y de bajo impacto (un toast decorativo sin nonce) generó tanto ruido en Network/Console que contaminó por completo la investigación de un síntoma distinto (panel en blanco), y en hipótesis posteriores se sospechó que ese mismo volumen de fallos podía disparar bloqueos de IP a nivel de WAF/hosting, afectando también a peticiones legítimas de la misma IP.

**Fix:** Usar `window.ltmsUX.nonce` (ya inyectado globalmente vía `wp_add_inline_script` sobre `jquery-core`) en el payload del `$.post`.

**Regla preventiva:** Al investigar "todo está roto", aislar cada `action` de AJAX fallido por separado antes de generalizar el diagnóstico. Un endpoint de bajo impacto con alto volumen de fallos puede parecer (y hasta causar, si dispara bloqueos por IP) una falla generalizada sin serlo — verificar cada `action` real de las peticiones fallidas, no asumir que todas comparten la misma causa.

### Lección #123: Verificar con un fetch directo en Console en vez de confiar en capturas sucesivas de Network/Payload

**Error:** Varias capturas de pantalla sucesivas del panel "Payload" de Chrome DevTools mostraban el mismo `action` (`ltms_get_social_proof`) aunque se pedía inspeccionar filas distintas — lo que sugiere que el panel no se había refrescado a la fila recién seleccionada, no que las peticiones fueran realmente idénticas.

**Causa raíz:** Depender de que una persona navegue manualmente DevTools y reporte un valor puntual es propenso a este tipo de desincronización de UI (el panel de detalle no siempre se actualiza al hacer clic en una fila nueva).

**Fix:** Pedir un one-liner ejecutable directo en la Console (`jQuery.post(ltmsDashboard.ajax_url, {...}).done(...).fail(...)`) que imprime el resultado real de una llamada específica, en vez de depender de que la persona encuentre y lea la fila correcta en la lista de Network.

**Regla preventiva:** Cuando se necesite un dato puntual de una petición AJAX específica y no haya acceso directo a un navegador, preferir pedirle a la persona que ejecute un script corto en la Console que devuelva la respuesta directamente, en vez de instrucciones de "haz clic en la fila X, mira el panel Y" — más simple, y evita falsos negativos por paneles de DevTools no refrescados.

---

## Sección 15 — Ciclo de auditoría panel del vendedor (2026-07-24, v2.9.240 → v2.9.242)

> Auditoría full-stack del panel del vendedor siguiendo `AGENTS.md` → "Loop de auditoría autónoma" y `prompt-engineering-loops.md`. 25 vistas SPA auditadas con VLM (glm-5v-turbo) en móvil 375px y desktop 1440px. 7 hallazgos (1 P0, 2 P1, 4 P2). Causa raíz del bug histórico "9 vistas en blanco" resuelta.

### Lección #124: El skeleton loader debe respetar el contrato de cada vista (AJAX vs estática)

**Error:** `initSkeletonLoaders()` en `ltms-ux-enhancements.js` monkey-patcheaba `LTMS.Dashboard.loadView()` para llamar `showSkeleton(view)` en TODAS las vistas, incluso las 10 que no tienen método `load<View>View` dedicado y por tanto no hacen AJAX `ltms_get_*` (insurance, bookings, marketing, security, donations, posgold, shipping-statement, ordenes-compra, sellers-landing, aveonline-onboarding). Como `hideSkeleton()` está enganchado a `ajaxComplete`, nunca se disparaba para esas vistas, dejando el overlay gris 6 segundos (failsafe) sobre contenido ya renderizado — síntoma idéntico al de "vista en blanco".

**Causa raíz:** El skeleton loader asumía que todas las vistas cargan datos vía AJAX, pero el dashboard tiene dos categorías de vistas: dinámicas (con `load*View` que hace fetch) y estáticas (PHP ya renderizado, solo `showSection()`). Aplicar el mismo loader a ambas rompe las estáticas.

**Fix:** Verificar `typeof this[load<View>View] === 'function'` antes de llamar `showSkeleton()`. Las vistas estáticas muestran su contenido PHP inmediatamente, sin overlay.

**Regla preventiva:** Antes de aplicar un mecanismo de loading a todas las vistas de un SPA, verificar si todas siguen el mismo contrato de carga. Si hay vistas estáticas y dinámicas, el loader debe respetar la distinción o se vuelve un bug para la categoría equivocada.

### Lección #125: Un `</div>` faltante en una view PHP hace que el browser parser reanide TODAS las vistas siguientes dentro de la sección display:none previa

**Error:** `view-envios.php` tenía 37 `<div>` abiertos pero solo 36 `</div>` cerrados. El `<div class="ltms-view-pad">` de la línea 23 nunca se cerraba. El browser parser, al encontrar el fin del archivo sin cerrar ese div, reanidaba TODAS las vistas siguientes (shipping-statement, redi, incidents, kitchen, ordenes-compra, bookings, marketing, security, donations, posgold, insurance, drivers, analytics — 13 vistas) dentro de `#ltms-view-envios` en el DOM parseado.

**Causa raíz:** `#ltms-view-envios` tiene `style="display:none;"` por defecto (el dashboard la muestra solo al hacer clic en "Envíos"). Las 13 vistas anidadas dentro heredaban `display:none` del padre, sin importar qué `display` les pusiera `loadView()` en su propio style. `getBoundingClientRect()` devolvía `w:0, h:0` para todas. El síntoma visible: 9 vistas aparecían "en blanco" sin error de JS, sin skeleton pegado, sin nada — solo el fondo gris del panel.

**Diagnóstico clave:** La inspección DOM reveló `#ltms-view-redi.parentElement.id === 'ltms-view-envios'` (debería ser `ltms-main-content`). Esto indicó inmediatamente un problema de nesting por HTML mal cerrado, no un bug de JS.

**Fix:** Agregar el `</div>` faltante al final de `view-envios.php` para cerrar `.ltms-view-pad`. Tras el fix, las 13 vistas volvieron a ser hermanas de `ltms-main-content` y todas renderizan correctamente.

**Regla preventiva:** Siempre validar div balance (`grep -c '<div' archivo.php` vs `grep -c '</div>' archivo.php`) en views PHP antes de hacer commit. Un solo `</div>` falso hace que el browser parser reanide todo el contenido siguiente, y el síntoma (vistas en blanco sin error de JS) no apunta obviamente a la causa (HTML mal cerrado en un archivo distinto al de la vista afectada). El diagnóstico "vistas en blanco" debe incluir SIEMPRE la verificación de `parentElement.id` de la vista afectada — si no es el contenedor esperado (`ltms-main-content`), el problema es estructural, no de JS.

**Herramienta de diagnóstico:** Un script Python simple puede detectar esto en CI:
```python
import re
with open('view-XXX.php') as f: c = f.read()
opens, closes = len(re.findall(r'<div\b', c)), len(re.findall(r'</div>', c))
if opens != closes: print(f'UNBALANCED: {opens} opens, {closes} closes')
```

### Lección #126: Al migrar inline `<script>` a JS externo por CSP, auditar que TODA la funcionalidad se migre

**Error:** El fix "FASE2B P0 FIX (CSP)" migró el `<script>` inline de `form-register.php` a `assets/js/ltms-login-register.js`, pero solo migró 2 de las 5 funcionalidades del script original (Turnstile + country/document). Las otras 3 (navegación del wizard, validación, submit AJAX) se perdieron silenciosamente. El wizard de registro de 3 pasos quedó completamente roto — los botones "Siguiente"/"Atrás" no hacían nada.

**Causa raíz:** La migración CSP se hizo script-por-script sin verificar funcionalidad end-to-end después. Los tests e2e (`tests/e2e/vendor-checkout.spec.js`) hacían clic en `.ltms-wizard-next` pero los tests e2e no corrían en CI (solo unit/integration), así que la regresión no se detectó.

**Fix:** Recrear el handler completo del wizard en `ltms-login-register.js` (271 líneas): navegación, validación por paso, submit AJAX, manejo de errores, honeypot, loading state.

**Regla preventiva:** Al migrar `<script>` inline a JS externo por CSP, SIEMPRE: (1) leer el script original completo antes de eliminarlo, (2) auditar que cada `addEventListener`/`onclick`/function del original tenga equivalente en el nuevo archivo, (3) verificar funcionalidad end-to-end en navegador después, (4) si hay tests e2e que cubren el flujo, correrlos o añadirlos al CI.

### Lección #127: Cumplimiento legal por defecto, no por configuración opt-in

**Error:** `can_publish_accommodation()` retornaba `true` si `ltms_booking_rnt_required` era `false` — y el DEFAULT era `false`. Esto significaba que cualquier vendor de turismo podía publicar alojamiento SIN RNT vigente, violando la Ley 2068/2020 (CO) que exige RNT de FONTUR para servicios de hospedaje.

**Causa raíz:** La configuración se diseñó como opt-in (el admin debe activar compliance) en vez de opt-out (el admin debe desactivarlo explícitamente). Para requisitos legales, el default seguro es "exigir"; solo se relaja con decisión explícita.

**Fix:** Cambiar el default a `true` (exigir RNT). Solo entornos de testing/staging pueden desactivarlo vía `ltms_booking_rnt_required=false`.

**Regla preventiva:** Toda configuración que controle cumplimiento legal (SAGRILAFT, RNT, INVIMA, GDPR, Ley 1581) debe tener como default el valor que cumpla la ley. La relajación debe ser opt-in explícita del admin, documentada, y solo para entornos no productivos.

### Lección #128: El registro de vendedor debe avisar DURANTE el wizard qué documentos extra necesitará

**Error:** Los vendors de restaurante solo se enteraban del requisito de INVIMA/COFEPRIS al llegar al paso de KYC post-registro. Los de turismo, del RNT. Esto generaba fricción, abandono, y frustración — el vendor ya había completado 3 pasos del wizard y creado su cuenta antes de descubrir que necesitaba un documento adicional.

**Causa raíz:** El wizard de registro mostraba los 5 business_types con iconos y hints cortos, pero NO avisaba qué requisitos adicionales implicaba cada uno. La información estaba dispersa en módulos de compliance que solo se activaban después.

**Fix:** Agregar avisos inline en el paso 2 del wizard que aparecen dinámicamente cuando se selecciona `restaurant` (INVIMA/COFEPRIS) o `tourism` (RNT/SECTUR), adelantando la información al momento de decisión.

**Regla preventiva:** Todo requisito documental post-registro debe anunciarse DURANTE el wizard de registro, no después. El vendor debe poder tomar una decisión informada antes de crear su cuenta: "¿tengo los documentos necesarios para este tipo de negocio?".

### Lección #129: Al togglear `display` via JS, usar valores explícitos ('block'/'none'), no string vacío

**Error:** `goToPage()` en `ltms-login-register.js` usaba `p.style.display = ''` (string vacío) para mostrar la página activa del wizard. Tras la validación exitosa del paso 1, el clic en "Siguiente" ejecutaba `goToPage(2)` que seteaba `p2.style.display = ''` — removiendo el inline style. Pero el CSS externo `.ltms-wizard-page { display: none; }` (línea 330 de `ltms-login-register.css`) tomaba control y ocultaba p2 de nuevo. El wizard nunca avanzaba visualmente aunque la validación pasara.

**Causa raíz:** String vacío en `style.display` NO significa "mostrar" — significa "remover inline style y heredar del CSS". Si el CSS externo tiene `display: none` por defecto, el elemento queda oculto.

**Fix:** Usar `'block'` explícito: `p.style.display = (pageNum === actual) ? 'block' : 'none'`.

**Regla preventiva:** Al manipular `style.display` via JS para mostrar/ocultar elementos, SIEMPRE usar valores explícitos (`'block'`, `'none'`, `'flex'`, `'grid'`), nunca string vacío (`''`). String vacío remueve el inline style y deja que el CSS externo decida, lo que puede causar bugs difíciles de diagnosticar cuando hay reglas CSS con `display: none` por defecto.
