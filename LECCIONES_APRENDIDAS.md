# Lecciones Aprendidas — LT Marketplace Suite

> **Propósito:** Registro de TODOS los errores encontrados durante el desarrollo para que la IA (y los desarrolladores) NO vuelvan a cometer los mismos errores. Cada entrada documenta: el error, la causa raíz, el fix, y la regla preventiva.
>
> **Última actualización:** 2026-07-17
> **Versión del plugin:** 2.9.187
> **Total de lecciones:** 110 (35 originales + 25 nuevas de v2.9.36-98 + 10 de estabilización + 15 de auditorías v2.9.113-118 + 5 de auditorías v2.9.119-132 + 10 de v2.9.143-160 + 10 nuevas del ciclo Plaza Viva v2.9.178-187)

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
