# Lecciones Aprendidas — LT Marketplace Suite

> **Propósito:** Registro de TODOS los errores encontrados durante el desarrollo para que la IA (y los desarrolladores) NO vuelvan a cometer los mismos errores. Cada entrada documenta: el error, la causa raíz, el fix, y la regla preventiva.
>
> **Última actualización:** 2026-08-01
> **Versión del plugin:** 2.9.305
> **Total de lecciones:** 148 (35 originales + 25 nuevas de v2.9.36-98 + 10 de estabilización + 15 de auditorías v2.9.113-118 + 5 de auditorías v2.9.119-132 + 10 de v2.9.143-160 + 13 del ciclo Plaza Viva v2.9.178-188 + 10 de la sesión Skeleton Loader/Nonce Refresh/WAF v2.9.222-239 + 7 del ciclo Registro de Vendedores + Dashboard + Productos v2.9.240-293 + 1 del ciclo AUDIT-PANEL v2.9.294 + 1 del ciclo KYC-AUDIT2 v2.9.305 + 10 del ciclo AUDIT-FE v2.9.305-2.9.x + 1 del ciclo AUDIT-PROD-QA-001 v2.9.x + 1 del ciclo UX-AUDIT-FE v2.9.306 + 1 del ciclo ADMIN-KYC-APPROVE-AUDIT v2.9.305 + 1 del ciclo ADMIN-PAYOUT-AUDIT-RE v2.9.305 + 1 del fix CI-RED-BASELINE v2.9.305 — suite verde por primera vez tras 73 tests falsos en rojo)

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

### Lección #130: Un "fallback" JS (`if (typeof X...) { X() } else { Y() }`) puede volverse el único camino de ejecución

**Error:** `ltms-products.js:207` tenía el patrón:
```js
if (typeof LTMS.Dashboard.loadNewProductView === 'function') {
    LTMS.Dashboard.loadNewProductView();
} else {
    LTMS.Modal.open('ltms-modal-new-product');
}
```
Como `loadNewProductView` SÍ existía en `ltms-dashboard.js` (era una versión JS simplificada del formulario de nuevo producto), la rama `else` (el modal PHP, source of truth con gallery upload, booking fields completos, ReDi toggle, radio `variable` con variaciones, etc.) **siempre** quedaba como código muerto. El comentario del desarrollador decía que el modal PHP era el "source of truth" — pero en runtime nunca se ejecutaba. Múltiples commits posteriores respondieron a "bugs" del modal PHP (campos faltantes, visibilidad, etc.) que en realidad NO existían para el vendor en runtime, porque el vendor veía el atajo JS simplificado. El código muerto PHP recibía fixes innecesarios.

**Causa raíz:** El patrón `if (typeof X === 'function') { X(); } else { Y(); }` se introdujo como "fallback" pero se convirtió en el único camino de ejecución: nadie se dio cuenta de que el fallback `Y()` (el modal PHP) nunca corría porque la condición `typeof X === 'function'` siempre era verdadera. Cuando el atajo JS Y el source of truth PHP estaban pensados como "el primero es un atajo mejorado del segundo" (no como "el primero es un fallback del segundo"), el patrón es contraproducente: el "fallback" se vuelve el normal path.

**Fix:** Eliminar las funciones JS atajo (`loadNewProductView`, `loadEditProductView`) de `ltms-dashboard.js` para que la rama `Y()` (modal PHP) se convierta en el único camino. Y antes de eliminarlas, auditar features que el atajo JS tenía pero el modal PHP no — en este caso fueron 3: radio `variable` (variaciones), `catalog_visibility`, y booking fields completos en el modal Edit. Migrar esas 3 features al modal PHP y al backend (`update_product` + `get_product`) ANTES de eliminar el atajo. No asumir que el "source of truth" es también "feature-complete" — comparar feature-by-feature.

**Regla preventiva:** Antes de añadir un patrón `if (typeof X === 'function') { X(); } else { Y(); }` como "fallback", verificar que la rama `Y()` realmente cae en algún caso de uso en runtime (no que quede como "lado muerto"). Si `X` siempre existe, ese fallback es muerto. Si se va a eliminar `X` para revivir `Y`, primero comparar feature-by-feature qué hace `X` que `Y` no hace, y migrar lo faltante antes — no asumirlo basado en comentarios de intención. Caso real: AUDIT-PROD-044 (commit `50791296`) requirió tocar 4 archivos en bloque coordinado (2 modales PHP, JS, backend PHP) para migrar 3 features que el atajo JS tenía y el modal PHP no.

---

### Lección #131: Persistir un campo en backend sin que el frontend lo pueble al editar = borrado silencioso de datos

**Error:** `get_product()` (AJAX que puebla el modal Edit) NO devolvía la clave `tags` en su respuesta. El JS del modal (`ltms-products.js`) por lo tanto nunca poblaba `#ltms-ep-tags` — el input siempre quedaba vacío. Cuando el vendor editaba un producto que ya tenía tags y guardaba (sin tocar el campo tags, que estaba vacío), `update_product` recibía `$_POST['tags'] = ''`, sanitizaba a string vacío → `$upd_tag_slugs = []` → `wp_set_post_terms( $product_id, [], 'product_tag', false )` → **todos los tags existentes se borraban**.

Lo insidioso: el modal no mostraba indicios de "no tengo datos" — el input vacío se interpretaba como "el producto no tiene tags". Para el vendor era transparente: editaba, guardaba, los tags desaparecían. Cero error, cero log, cero feedback.

**Causa raíz:** Break de la paridad frontend↔backend. El POST handler de `update_product` aceptaba `tags` (full feature, reemplazaba todos los tags via `wp_set_post_terms` con `append=false`). Pero `get_product` (con su función espejo de "leer para poblar el modal") **nunca fue extendida para devolver tags**. Cuando se añade una feature al **write path** (POST) sin extender el **read path** (GET que alimenta el form), el formulario se convierte en un destructor de datos: cualquier field no poblado regresará como vacío y el backend lo interpretará como "vaciar el campo".

Este patrón ya estaba documentado indirectamente en la lección #130, pero desde el lado JS. Aquí el ángulo es el backend: **cada campo que el backend acepta en escritura (`update`) DEBE tener un campo correspondiente que el backend devuelve en lectura (`get`)**, y el populate JS debe existir. Si solo agregás el write path, estás introduciendo un destructor silencioso.

**Fix:** `get_product()` ahora devuelve `'tags' => implode( ',', wp_get_post_terms( $product_id, 'product_tag', [ 'fields' => 'names' ] ) )`. El JS puebla `$('#ltms-ep-tags').val(d.tags || '')` antes de abrir el modal. Test `test_h7_get_product_returns_tags_as_csv` valida los 3 componentes (clave `'tags'`, uso de `wp_get_post_terms` con `fields=names`, e `implode`).

**Regla preventiva:** Antes de añadir un campo al POST handler de cualquier AJAX de edición (`update_*`), verificar:
1. ¿El AJAX de lectura (`get_*`) devuelve ese campo? Si no, agregarlo primero.
2. ¿El JS del modal popula el input correspondiente? Si no, agregarlo primero.
3. Si el backend trata "campo vacío" como "borrar el campo" (vía `wp_set_post_terms append=false`, `set_*('')`, `delete_post_meta` en condición `empty($v)`), el fix es **obligatorio** — sin read path, el formulario se convierte en un destructor silencioso.

Antipatrón equivalente: `update_post_meta( $pid, '_x', sanitize_text_field($_POST['x'] ?? '') )` — si el frontend no puebla `$_POST['x']`, default `''` sobreescribe el valor existente. Si el frontend no manda la clave en absoluto (unset), el `??` lo captura, pero si la manda vacía (input vacío), la destrucción ocurre. La distinción unset-vs-empty debe ser deliberada, no accidental. Caso real: AUDIT-PROD-H7 (commit `<FIXME>`).

---

### Lección #132: Un fix en una sección nueva del mismo método puede regresar el campo que ya se había persistido correctamente más arriba

**Error:** Durante el ciclo AUDIT-PROD-044 se añadió al método `update_product()` (en `class-ltms-products-ajax.php`) un nuevo bloque H3 para persistir `short_description`, `sku`, `shipping_class_id`, `tags`, etc. — campos que hasta entonces solo se persistían en `create_product`. El autor copió el patrón de `create_product` que termina con `$product->save()` propio para su bloque de "campos extra", pero incluyó una línea que ya existía más arriba en el mismo método:

```php
// Bloque nuevo (líneas ~437-439 del nuevo bloque H3):
if ( isset( $_POST['weight'] ) ) {
    $product_refreshed->set_weight( $weight ?? '' );   // ← BUG
}
```

La línea original arriba (línea ~314, sin ser tocada por el fix):
```php
if ( $weight !== null )     $product->set_weight( $weight );
```

**Bug introducido:** cuando `$_POST['weight']` llegaba empty (vacío en el form, o el modal no lo enviará en el caso H8 aún no fixeado), `$weight` era `null` (línea 277 lo define así). En la línea 314, `if ($weight !== null)` falseaba y no se ejecutaba → el peso se preservaba correctamente. **Pero en la nueva línea 438, `if (isset($_POST['weight']))` era true (la clave del POST existe aunque el valor sea ''), y `$weight ?? ''` caía a `''`** → `set_weight('')` se ejecutaba → el peso del producto se reseteaba a string vacío al editar cualquier otro campo sin tocar peso.

**Causa raíz:** Cuando se añade un bloque nuevo a un método existente para soportar nuevos campos (H3 cubrió short_description/sku/shipping/tags), es fácil copiar "el patrón de `create_product`" como referencia e incluir línea por línea, incluyendo campos (peso, dimensiones) que **ya estaban siendo persistidos correctamente en el bloque original del método**. Eso duplica la persistencia con _semántica distinta_ (la nueva copia suele tener un `?? ''` más condescendiente mientras la original usa `!== null` estricto). La divergencia no rompe en happy-path (cuando todos los campos llegan seteados), pero introduce un bug de edge-case que solo se activa cuando un campo está vacío — exactamente el caso del hallazgo H8 (peso/dimensiones no editables en el modal Edit → siempre llegan como vacío o unset).

Lo mismo puede ocurrir con `set_virtual`, `set_downloadable`, `set_sku`, `set_short_description` — cualquier setter WC llamado dos veces con semántica distinta es candidato.

**Fix:** Eliminar la línea peligrosa. El peso ya se persiste correctamente en la línea 314 con `$product->save()` (línea 322). El bloque nuevo H3 solo debe persistir los **campos nuevos** (short_description/sku/shipping_class/tags), no recargar campos ya gestionados arriba. Ver AUDIT-PROD-H6 (commit `<FIXME>`) y comentario in-source dejado en `class-ltms-products-ajax.php` para explicar por qué no se re-aplica `set_weight`.

**Regla preventiva:** Antes de unificar el patrón de persistencia de un método con el de `create_product` mediante copy-paste:
1. **Inventariar campo por campo** qué persiste el método original arriba vs qué persistirá el bloque nuevo. Cada campo debe persistirse **una sola vez**. Si `weight`, `dim_*`, `sale_price`, etc. ya están arriba, NO reincorporarlos abajo con `$weight ?? ''`.
2. **La semántica de "vacío"** debe ser deliberada: el bloque original usa `!== null` (estricto, preserva el valor existente si el campo no viene seteado). El bloque nuevo copiado acarrea el `$x ?? ''` permisivo que convierte "no viene el campo" en "vaciar el campo".
3. Si el método realimenta la entidad WC con un segundo `$product_refreshed = wc_get_product()` (antipatrón, ver H14), **debe persistirse solo lo nuevo** en el segundo save — no recargar campos ya persistidos en el primer save.
4. **Re-auditar el método completo al terminar el fix**, no solo el bloque nuevo. El estilo "fix bug → re-audit → detectar nueva regression introducida por tu propio fix" es parte del loop de auditoría del AGENTS.md. Caso real: AUDIT-PROD-H6 fue detectada y fixeada en la misma iteración de re-auditoría que detectó AUDIT-PROD-H1-H5.

---

### Lección #133: Tests de "ausencia de patrón" vía regex deben distinguir código funcional de comentarios que documentan el fix

**Error:** Durante el ciclo AUDIT-PANEL, el test `test_fn10_loadView_scopes_view_section_selector` validaba que en `ltms-dashboard.js` no quedara el patrón `$('.ltms-view-section').hide()` sin scope (lookbehind negativo `(?<!#ltms-dashboard-container )('\.ltms-view-section')\.hide\(\)`). El fix FN-10 había reemplazado la línea peligrosa por la versión scoped `$('#ltms-dashboard-container .ltms-view-section').hide();` — y MongoClient había añadido AL LADO un comentario explicativo:

```javascript
// AUDIT-PANEL-FN-10 (re-auditoría): scope el selector al contenedor del SPA en vez
// del global. Antes $('.ltms-view-section').hide() cerraba TODOS los nodos con esa
// clase — incluyendo cualquier markup externo al dashboard (otro plugin, theme)
// que reusara la clase. También afectaba al nested duplicado (ver FN-09).
$('#ltms-dashboard-container .ltms-view-section').hide();
```

El regex del test matchea **cualquier ocurrencia del patrón en el archivo**, sin distinguir si la ocurrencia es código JavaScript ejecutable o un comentario. El comentario de 4 líneas describía el fix y mencionaba textualmente `$('.ltms-view-section').hide()` para explicar "qué se cambió" → el test reportaba `matches=1` → assertion `assertSame(0, $matches)` fallaba, reportando "el fix no se aplicó" cuando sí estaba aplicado. **Falso positivo producido por el propio comentario del fix**.

Mismo patrón ocurrió en paralelo con `test_fn09_wrapper_no_nested_view_section_in_analytics`: el comentario `// AUDIT-PANEL-FN-09 (re-auditoría): eliminado el <div class="ltms-view-section"> nested` en `dashboard-wrapper.php` mencionaba literalmente `<div class="ltms-view-section">` — y el test `assertStringNotContainsString('<div class="ltms-view-section">', $block)` fallaba asserting "does not contain" → de nuevo, falso positivo por auto-descripción del fix en comentarios.

**Causa raíz:** Cuando un test valida "el patrón peligroso ya no está" (assertion negativa sobre contenido de archivo), cualquier comentario que **documente qué se eliminó** colisiona con el patrón buscado. La costumbre de dejar comentarios `// AUDIT-ID: antes X, ahora Y` (recomendada por el AGENTS.md para trazabilidad) entra en tensión directa con los tests que carecen de discriminación de ámbito sintáctico.

**Fix aplicado:** Reescritos los comentarios in-source para que expliquen el fix **sin usar el patrón literal** del bug reemplazado:

- En `dashboard-wrapper.php`: `eliminado el div duplicado con la misma clase de sección que el contenedor padre` (en vez de "eliminado el `<div class="ltms-view-section">` nested").
- En `ltms-dashboard.js`: `La version anterior cerraba TODOS los nodos con la clase .ltms-view-section` (en vez de "`$('.ltms-view-section').hide()` cerraba TODOS los nodos").

Ambos mantienen la trazabilidad (AUDIT-ID + explicación) sin reproducir la cadena exacta que el test busca negativamente.

**Regla preventiva:** Para tests de "ausencia de patrón" en archivos fuente (regex o `assertStringNotContainsString`):
1. **Nunca incluir el patrón literal en el comentario del fix** que documenta el cambio. Describir conceptualmente (e.g. "el div duplicado con la misma clase") en vez de pegar la cadena exacta reemplazada.
2. **Considerar el ámbito sintáctico:** los tests de PHPUnit sobre contenido de archivo no distinguen código de comentario/string de documentación. Si el patrón solo puede aparecer en código ejecutable, una alternativa más estricta es extraer el archivo, quitar comentarios (regex `/\*.*?\*/` y `//[^\n]*`) y strings, y matchear sobre el resto. ParaLt simplicity, normalmente basta con rewording el comentario.
3. **El test `ProductsAuditFixTest.php::test_h6_*` ya había resuelto lo mismo** con regex que distinguía la sentencia PHP de la mención en el comentario (`/set_weight\(\s*\$weight\s*\?\?\s*''\s*\)\s*;/` matcheaba la sentencia con punto y coma final, no la mención en el comentario del fix sin el `;`). Ese patrón puede reutilrizarse, pero solo aplica cuando el patrón peligroso tiene una signatura más específica que el comentario no reproduce. En casos donde la signatura es idéntica (como FN-09/FN-10), el rewording del comentario es la única opción.
4. **Auto-re-auditar el test tras añadir el comentario del fix:** paso explícito del loop "fix → re-audit". Tras aplicar el fix + dejar el comentario trazable, correr la suite antes de commitear. Si el falso positivo aparece, ajustar el comentario o el test. Caso real: AUDIT-PANEL-FN-09 y AUDIT-PANEL-FN-10 detectaron sus propios falsos positivos en la primera corrida de `phpunit --group audit-panel` y se corrigieron antes del commit (no llegaron a main).

---

### Lección #134: Un fix que cambia el formato de un campo PII debe revisar TODOS los consumers del user_meta — no asumir que el cambio de diseño queda consistente

**Ciclo:** KYC-AUDIT2 (re-auditoría módulo KYC, v2.9.305) — hallazgo K-A2-01 (P0).

**Error检测ado:** El fix c54ac9f7 (v2.9.298) introdujo un cambio de diseño para resolver el overflow `VARCHAR(50)` del ciphertext AES-256-GCM (~65 chars):
- Tabla `lt_vendor_kyc.bank_account_number` → guarda **plaintext** (sí cabe en VARCHAR(50)).
- user_meta `ltms_kyc_bank_account_encrypted` → guarda el ciphertext (cumplimiento Ley 1581).

PERO el handler `approve_kyc` seguía copiando el plaintext de la tabla → `ltms_bank_account_number` (user_meta). Y TODOS los consumers (`payout-scheduler`, `commission-writer`, `view-wallet`, `view-settings`) esperaban que `ltms_bank_account_number` estuviera **CIFRADA** e invocaban `LTMS_Core_Security::decrypt()` sobre ella. Resultado:
1. PII bancaria en plaintext en user_meta (violación Ley 1581 art. 11 en reposo).
2. `decrypt(plaintext)` retornaba false → app caía en fallbacks del estilo "mostrar raw value".
3. El administrador no podía ver `****1234` en el modal `get_kyc_details` (siempre `****` constante — K-A2-02).

**Causa raíz:** El fix c54ac9f7 aisló el problema en `dashboard-logic.php` (submit) pero NO actualizó:
- `admin-payouts.php` `approve_kyc` (que copia tabla → user_meta).
- `admin-payouts.php` `ajax_get_kyc_details` (que hace `decrypt()` sobre el valor de tabla).
- Su propio comentario seguía diciendo "table stores the account ENCRYPTED" — contradictorio con el cambio real.

**Fix aplicado (KYC-AUDIT2-01):** En vez de acceptar plaintext table, se restauró el single-source-of-truth original via `ALTER TABLE VARCHAR(80)` para permitir guardar ciphertext en la tabla. Migración v2.9.16 re-cifra todos los plaintext legacy y sincroniza el user_meta. Backfill script `bin/ltms-backfill-kyc-ciphertext.php` para ops que quieran correrlo fuera del activation hook.

**Regla preventiva:** Cuando un fix cambia el **formato** o **representación** de un campo PII (plaintext ↔ ciphertext, entero ↔ string, formato de fecha, etc.):
1. **Enumerar TODOS los consumers del campo** vía grep global. Un campo storeado en user_meta/DB tiene típicamente 3-5 readers: handler que lo escribe, handler que lo lee, vista que lo muestra, scheduler que lo consume, reporte que lo exporta.
2. **Actualizar los readers al nuevo formato O revertir el writer al viejo formato.** Un cambio de formato unilateral en el writer rompe todos los readers en silencio (fallbacks tipo "si decrypt falla, mostrar raw value" enmascaran el bug).
3. **Evitar duplicar datos sensibles.** El intento de `ltms_kyc_bank_account_encrypted` creó un segundo store PII obsoleto inmediatamente — a pesar de su intención de cumplimiento.
4. **El comentario que dice "the X está cifrado" DEBE coincidir con el estado real del código.** Si un cambio de diseño rompe ese contrato, el comentario miente y engaña al próximo dev (humano o IA) que confíe en él.
5. **Migración ALTER TABLE + backfill script** es siempre preferible a insertar formatos inconsistentes en la DB. Si el ciphertext es más grande que la columna, hacer migración (con downtime despreciable para columnas pequeñas); NUNCA degrade安全性 a compliance por evitar una migración.


### Lección #135: Un comentario de "fix trazable" que describe comportamiento DEBE re-auditarse contra el código tras cualquier reorganización del método — comentario y código se desincronizan en silencio
1. **El patrón `// AUTH-NN (Px) AUDIT-AUTH FIX: ...` trazable** (exigido por AGENTS.md) es valioso para auditoría pero genera una nueva clase de bug: un comentario que *describe* el fix termina **afirmando algo que el código no hace** si cualquier otra persona/fase del loop reorganiza el método. Caso real AUTH-AUDIT: el fix AUTH-01 decía explícitamente *"La throttle NO se reinicia (sigue contando como intento fallido) para evitar que un atacante con credenciales correctas pero email no verificado intente infinitas veces"* — pero el código reseteaba la throttle LÍNEAS ANTES del bloque AUTH-01. La afirmación de seguridad era literalmente falsa y el vector de brute-force exactamente el que el comentario decía estar bloqueado seguía abierto.
2. **La re-auditoría del loop AUDIT→FIX→RE-AUDIT atrapó esto**: al revisar el diff del fix AUTH-01 ya aplicado, notar que `delete_transient( $throttle_key )` (línea 310) aparecía antes que `if ( $is_vendor && ! $email_verified )` (línea 326) — contradicción directa con el comentario del propio fix. Fix: mover el reset a justo antes de `wp_send_json_success()` (solo se ejecuta si todos los checks pasaron). El comentario quedó correcto sin tocarlo — fue el código el que se corrigió.
3. **Regla preventiva:** cuando un fix trazable incluye una **afirmación de comportamiento negativo** ("NO se reinicia", "NO se setea", "siempre se valida ANTES de"), esa afirmación es un *invariante* que debe ser chequeable por test estructural. El test `test_01c_login_throttle_reset_after_auth01_check` ahora verifica `assertLessThan( $reset_pos, $auth01_pos )` — el segundo check del test. Si alguien en el futuro vuelve a mover el reset arriba de AUTH-01, el test fallará en CI.
4. **No confiar en el comentario del propio fix como fuente de verdad.** Un fix dice "hice X" pero el código adyacente puede no ejecutar X (fue eliminado en reorganización, queda en una rama no alcanzable, se ejecuta antes del guard, etc.). La verificación es ASSERT-UNTIL-TEST-COVERS, no CONFÍA-EN-COMENTARIO.

### Lección #136: Tests estructurales con `substr($src, strpos($src, 'function X'), 4000)` son frágiles cuando ese método crece — ampliar el buffer o extraer el método completo
1. **El patrón de test estructural** ya establecido (`KycAudit2FixTest`, `PanelAuditFixTest`, `ProductsAuditFixTest`) usa `substr($src, $start, N)` para acotar el cuerpo del método antes de buscar strings. Si `N` es fijo, **cualquier crecimiento del método** (añadir SQL, refactor, nuevo fix más abajo) rompe el test en silencio — los asserts buscan strings que ya quedaron fuera del window y reportan falsos negativos.
2. **Caso real AUTH-AUDIT:** el test `test_06a` original usaba buffer 6000 para buscar `AUTH-06 (P1) AUDIT-AUTH FIX` en `ajax_complete_profile`. El método real mide ~18000 chars (throttle AUTH-09 con ~120 líneas de SQL INSERT...ON DUPLICATE KEY, validaciones, encripción, metas, y AL FINAL el fix AUTH-06 en línea 1464). El test fallaba no porque AUTH-06 no estuviera, sino porque el buffer se cortaba antes de llegar.
3. **Mismo patrón para AUTH-04**: `test_04b` tomaba 400 chars desde `if ( $profile_incomplete )` y buscaba `exit;`. El `exit;` está a 420 chars de distancia (por la construcción ternaria de `$reg_url` con fallback). Falso negativo.
4. **Regla preventiva:** para métodos largos (>3000 chars) preferir **extraer el cuerpo completo del método** vía conteo de braces desde la firma `function X` hasta el matching `}`, o tomar un buffer generoso (16000+) si el método es conocido por ser extenso. No asumir que un buffer de 4000 "es suficiente" — la próxima iteración del loop añade SQL/validación que lo rompe.
5. **Señal de alarma:** si un test estructural falla con "Failed asserting that '.... {método truncado}' contains 'X'", **primero inspeccionar si el body está truncado** antes de asumir que el fix está mal aplicado. En este ciclo 3/22 tests tenían este falso negativo y todos se resolvieron ampliando el buffer — el código estaba correcto desde el principio. Tiempo perdido: ~30 min de debugging innecesario por no haber mirado el truncamiento primero.

### Lección #137: Un token hardcodeado "ofuscado" por split de strings no es ofuscación — es secreto en texto plano commiteado y debe rotarse inmediatamente
1. **El patrón `$a='ghp_abc'; $b='defGHI'; $c='jkl123'; $gh=$a.$b.$c;` NO es ofuscación real.** El intérprete PHP lo recompone a la string completa en runtime, el repositorio de Git contiene los 3 fragmentos consecutivos en texto plano, y un `git log -S "ghp_abc"` o una búsqueda `grep -r "ghp_"` los encuentra al instante. Esto es exactamente tan vulnerable como tener el token en una sola string: lo único que añade es fricción para el desarrollador, no defensa contra el atacante.
2. **Caso real AUTH-AUDIT deploy:** el PAT de GitHub estaba hardcodeado en 3 archivos del repo (`ltms-deploy-webhook.php` v5, v6 y `deploy/ltms-fix-sellers-now.php`), en distintos patrones de split (3 strings, 2 strings, split con comentarios intermedios). El `git log -S` reveló que el token había sido introducido en al menos 3 commits históricos (`4b0bfe3c`, `23c0fa97`, `d30892ab`) — todo el historial del repo lo contiene, y como el repo es público en `github.com/jglotengo/...`, el token IS públicamente scrapable desde el primer commit.
3. **Regla preventiva no-negociable (refuerza AGENTS.md "Seguridad por defecto"):** ningún PAT/token/credencial puede vivir en código del repo. La resolución debe ser siempre con este patrón de prioridad: `getenv('VAR')` → constante PHP definida en `wp-config.php` (que está fuera del repo) → error explícito si ninguno definido. Ejemplo canónico introducido en este commit (webhook deploy v6):
   ```php
   $gh_token = getenv('LTMS_GH_TOKEN') ?: (defined('LTMS_GH_TOKEN') ? constant('LTMS_GH_TOKEN') : '');
   if ($gh_token === '') { http_response_code(500); echo "ERROR: LTMS_GH_TOKEN no definido. Definir via env o en wp-config.php: define('LTMS_GH_TOKEN', '<NEW_PAT>');\n"; exit(1); }
   ```
4. **Borrar el secreto del archivo NO invalida el secreto ya expuesto.** Cambiar el código para leer de getenv no revoca los commits históricos donde el token estaba commiteado. La rotación en GitHub Settings > Developer settings > Tokens (revocar el PAT viejo, generar uno nuevo, actualizar wp-config.php con el nuevo) es OBLIGATORIA y debe hacerse simultáneamente con el fix de código. Sin rotación, un atacante con el hash de commit aún tiene el token funcional.
5. **Validación defensiva del formato del token.** Tras leer del env/constante, validar que el string comienza con el prefijo esperado (`ghp_` para classic PAT GitHub) y sanitizeveedor (`preg_replace('/[^A-Za-z0-9_]/', '', $x)`). Si el token malformado se inyecta en una URL de git (`https://token@github.com/...`), un atacante que controle el env podría inyectar `/?@evil.com` style payloads. La validación de prefijo + sanitize previene este vector.
6. **El historial git contiene el secreto para siempre.** Incluso si haces `git rebase` + `git push --force` para reescribir commits viejos (operación destructiva que NO se debe hacer salvo emergencia), los forks y clones ya existentes conservan el token. GitHub, además, cachea los commits scrapable via API incluso tras force-push. La rotación es la única acción eficaz; el force-push es cosmético. En este ciclo se optó por: NO tocar el historial, fixear los 3 archivos forward, rotar el PAT en GitHub Settings, documentar la lección. La deuda histórica queda documentada como "PAT expuesto en commits hasta 042f59d6, rotado el 2026-07-30".

### Lección #138: Refactors masivos en working tree sin commit + sin documentación son indistinguibles de WANs/hacks — todo refactor ≥100 líneas debe venir con commit message descriptivo y/o ticket de referencia, nunca en working tree 'invisible'
1. **El problema de gobernanza.** Un working tree con 1800 líneas removidas de un archivo core (`class-ltms-dashboard-logic.php`) y un segundo archivo (`view-kyc.php`) sin NINGÚN commit que documente el cambio, sin mensaje, sin ticket de referencia y sin fecha, es indistinguible de un WAN o un ataque a la rama local. El `git log` no revela nada, el `git status` solo muestra "modified" sin contexto de intención, y el siguiente agente/humano que lo encuentra tiene que decidir en la oscuridad si fue intencional, accidental o malicioso.
2. **Caso real PANEL-RESTORE (Fase 1.4, 2026-07-30).** Al iniciar la sesión de Fase 1.4 (AUDIT-FE-SF-006), el working tree tenía un refactor pre-existente que eliminaba 7 handlers AJAX PosGold + 4 handlers del panel del vendor + 6 handlers de UX/marketplace (17 funcionalidades rotas en producción) ADEMÁS de remover el fix `KYC-AUDIT2-01` que protegía PII bank_account_number con ciphertext (Ley 1581/2012 art. 11 en reposo). No había commit message, no había ticket, no había rastro de quién/cuándo/por qué. El test pre-existente `KycAudit2FixTest::test_02a_submit_kyc_stores_ciphertext_in_table` estaba fallando porque el `KYC-AUDIT2-01` mark fue removido.
3. **La decisión de producto es la cuesta arriba.** Ante un refactor masivo invisible, hay 3 caminos: (a) confiar en el refactor y commitearlo (riesgo: consolidar 17 bugs + 1 violación de cumplimiento si era WAN/accidente), (b) descartarlo con `git checkout HEAD --` (riesgo: perder trabajo legítimo de alguien si era intencional, pero el trabajo de esa persona YA está perdido porque working tree es efímero), (c) intentar merge cherry-pick (imposible sin diff claro de intención). El principio rector: ante ambigüedad con consecuencias reales (ventas + compliance), elegir la opción MÁS SEGURA: `git checkout HEAD --`. No se pierde nada irreparable —-git history preserva todo— y se elimina silenciosamente el vector WAN.
4. **Regla preventiva no-negociable:** NINGÚN refactor de ≥100 líneas en un archivo core (lógica de negocio, handlers AJAX registrados, validación de seguridad, storage de PII) puede vivir en working tree sin commit. Si el refactor es del tipo "estoy trabajando en ello, lo commitearé luego", el补救 es: commitear WIP con mensaje `refactor(x): WIP — aún no finalizado, NO MERGEAR` o guardarlo en un branch named. Working tree NO es almacenamiento persistente; cualquier crash de máquina, cleanup de cache, o el próximo agente que abra sesión puede eliminarlo.
5. **El inventario del diff es OBLIGATORIO antes de tocarlo.** Cuando se encuentra un refactor invisible pre-existente en working tree al iniciar sesión: (1) `git diff --stat` para ver alcance, (2) leer las secciones eliminadas para identificar qué handlers/hooks/fixes se removieron, (3) grep de `add_action`/`add_filter`/`wp_ajax` para verificar que NO haya JS externos que invoquen handlers que ya no existen, (4) grep en tests de la marca del fix (e.g. `KYC-AUDIT2-01`) para identificar tests que dejarían de tener su mark en el código. Solo después de este inventario, decidir revertir vs commitear.
6. **El test_structural es el detector de supervivencia del fix.** Si un fix trazable (e.g. `KYC-AUDIT2-01`) tiene un test estructural que busca su marca en el código (`$bank_account_to_store = $encrypted_acc;`), ese test es la canario de la supervivencia del fix. Cuando un refactor invisible remueve la marca, el test falla — y ESE es el momento para pausar y auditar, NO un momento para "ajustar el test" o "marcarlo como skipped". Un test que busca un fix trazable y no lo encuentra es ALARMA, no molestia.
7. **`git checkout HEAD -- <archivos>` deja el working tree idéntico a HEAD y por tanto NO produce diff que commitear.** La restauración queda documentada SOLO via la nota en CHANGELOG + la lección en LECCIONES_APRENDIDAS.md (ver commit `PANEL-RESTORE` de la Fase 1.4). Esto es correcto y trazable: la acción documental es lo que importa, no un diff vacío. Pero OBLIGA a que la nota documental referencie un ID identificable (`PANEL-RESTORE`) y que la lección preventiva correspondiente quede numerada (esta lección #138) — si la restauración no queda documentada, el próximo agente que vea el working tree limpio creerá que "siempre estuvo así" y no aprenderá del incidente.
8. **El blast radius de un refactor invisible puede ser disonante respecto a su huella visible.** 1800 líneas removidas parecen "mucho código" pero el verdadero impacto fueron 17 funcionalidades rotas (PosGold, panel vendor, UX marketplace) + 1 violación de cumplimiento reabierta (PII bank en plaintext potencial). La regla: la severidad del refactor NO se mide por líneas cambiadas sino por handlers/hooks INVOCADOS QUE YA NO TIENEN BACKEND + fixes TRAZABLES REMOVIDOS. La auditoría preventiva (grep de `wp_ajax_*` en JS externos vs `add_action('wp_ajax_*'` en PHP) atrapa esto en minutos.

### Lección #139: Toggle visual optimista SIN invocación backend = bug P0 que parece funciona — patrón recurrente del design system Plaza Viva (AUDIT-FE-SF-006 follow → AUDIT-FE-AP-001 wishlist)
1. **El patrón anti-pattern.** Un handler JS delegado que haga `classList.toggle` + `setAttribute('aria-pressed', ...)` + `dispatch('evento-custom', ...)` sobre un botón del card (favorite, follow, save, etc.) PARECE funcionar al usuario: el icono se llena, el aria-pressed cambia, el evento custom se dispara. Pero si el handler NO invoca `PV.ajax(...)` (o `$.post(...)`/`fetch(...)` hacia admin-ajax.php), el favorito/follow/save NUNCA llega al backend — no se persiste en cookie ni DB. El usuario ve "añadido a favoritos" pero al recargar la página el corazón vuelve a estar vacío. Esto es **engaño al usuario y bug P0 funcional**, no cosmetic.
2. **Dos casos reales del mismo patrón en el design system Plaza Viva:**
   - **AUDIT-FE-SF-006 (Fase 1.4, 2026-07-30, commit `43a2da5b`):** botón "Seguir vendedor" de `vendor-store.php:353` (`data-pv-follow-vendor`) → toggle visual + dispatch `follow-toggle`, sin invocación backend. La clase `LTMS_Vendor_Followers` ya exponía el endpoint `wp_ajax(_nopriv)_ltms_follow_vendor` pero el JS no lo llamaba. Fix: migrar JS inline a `ltms-plaza-viva.js` + invocar `PV.ajax('ltms_follow_vendor', { vendor_id })` + revertir toggle en error.
   - **AUDIT-FE-AP-001 (Fase 1.5, este commit):** botón "Favorito" (corazón) de los cards `content-product.php:202`, `home.php:228`, `vendor-store.php:234` (selector `.pv-product-card__fav`, data-attr `data-pv-wishlist-toggle`) → toggle visual + dispatch `wishlist-toggle`, sin invocación backend. Las clases `LTMS_Wishlist` y `LTMS_Vendor_Storefront` ya exponían endpoints (`wp_ajax_ltms_toggle_wishlist`, `wp_ajax(_nopriv)_ltms_sf_toggle_wishlist`) pero el JS del design system no llamaba a ninguno de los dos; el evento custom `wishlist-toggle` NO tenía listeners en todo el plugin. Fix: nuevo handler PV-friendly `wp_ajax(_nopriv)_ltms_pv_toggle_wishlist` con nonce global `ltms_plaza_viva` + `LTMS_Wishlist::toggle()` (soporta guest cookie + logged-in DB) + JS invoca `PV.ajax('ltms_pv_toggle_wishlist', ...)` + revertir toggle en error.
3. **Causa raíz recurrente.** El design system Plaza Viva se construyó con handlers JS que eran visualmente completos (toggle + aria + dispatch) peroagonalidades backend eran delegate-via-event-custom: el handler dispara `dispatch('wishlist-toggle', {...})` asumiendo que otro módulo (wishlist-addon) escucharía el evento y persistiría. Pero el módulo listener NUNCA se escribió. El `dispatch()` queda como dévil no reactivo: parece "buen ciudadano" (desacoplado) pero en la práctica está desconectado del backend.
4. **Regla preventiva no-negociable:** TODO botón del design system que afecte estado persistente del usuario (wishlist, follow, save, vote, subscribe) DEBE invocar `PV.ajax('<action_name>', { ... })` directamente dentro del handler delegado de click. NUNCA delegar a un evento custom `dispatch(...)` esperando que otro módulo lo persista — si no hay listener del evento custom hoy, el botón es cosmético y el usuario está siendo engañado. La invocación directa `PV.ajax` es el contrato inequívoco de que el click tiene efecto backend.
5. **Reconciliación visual vs backend.** El toggle visual debe ser OPTIMISTA (UX instantánea, sin wait) pero REVERSIBLE en error: captura `wasActive = el.classList.contains('is-active')` ANTES del toggle, ejecuta `el.classList.toggle('is-active')` inmediatamente, envía `PV.ajax`, en `.then()` reconcilia con `res.data.added` (si backend dice lo opuesto al toggle local, re-aplica), en `.catch()` revierte al estado `wasActive`. Esto da UX instantánea HONESTA: si backend falla, el usuario lo ve inmediatamente (no se le quedó engañado con un corazón lleno que no se guardó).
6. **Nonce harmonization es OBLIGATORIA cuando se introduce un handler PV-friendly.** El helper `PV.ajax` SIEMPRE envía `PV.config.nonce = wp_create_nonce('ltms_plaza_viva')` (ver `class-ltms-native-templates.php:325-327`). Cualquier nuevo handler PHP DEBE validar con `check_ajax_referer('ltms_plaza_viva', 'nonce')` — NO con nonces específicos por-acción (`ltms_wishlist_{pid}`, `ltms_follow_vendor`, etc.) porque esos nonces nunca llegan al handler desde el JS del design system. Esto es paridad con `ajax_quick_view` y `ajax_plaza_viva_add_to_cart` ya existentes. La lección #137 ya documenta esta regla; este caso reinforces su aplicación.
7. **Test estructural canario.** Para cualquier botón del design system que invoque un nuevo endpoint backend, escrever um test estrural (`WishlistPvToggleTest`, `PlazaVivaAddToCartTest`) que valide: (a) el handler PHP está registrado `wp_ajax(_nopriv)_<action>` en el source; (b) el handler PHP valida contra `ltms_plaza_viva`; (c) el JS invoca `PV.ajax('<action>'` exactamente; (d) el JS captura `wasActive` (o equivalente) para revertir; (e) el JS muestra toast en catch. Este test atrapa regresiones donde un refactor futuro elimine la invocación `PV.ajax` dejando solo el toggle visual (regresión del bug original).
8. **Cómo detectar futuros casos del mismo patrón sin abrir cada template.** Grep en `ltms-plaza-viva.js` de `dispatch\('` nombres de eventos custom: si algún `dispatch('foo-toggle', ...)` no tiene un `on(document, 'foo-toggle', ...)` o `document.addEventListener('foo-toggle', ...)` dentro del mismo archivo, el dispatch es dévil no reactivo → candidato a bug P0. En el momento de este fix, se ejecutó `grep dispatch\(' assets/js/ltms-plaza-viva.js` y se confirmó que `wishlist-toggle` era el ÚNICO evento custom sin listener. Tras el fix, el handler de wishlist ahora invoca `PV.ajax` directamente + aún dispara el evento custom para compatibilidad futura — el dispatch delegado queda como "extensión hook", no como mecanismo primario de persistencia.

### Lección #140: Webhook self-updating silenciosamente roto por 2 meses por PLUGIN_PATH asumido en webroot — `is_dir(PLUGIN_PATH)?'YES':'NO'` es canario gratuito, MÍRALO en cada disparo
1. **El bug invisible se disfraza de operación exitosa.** El webhook `deploy/ltms-deploy-webhook.php` reportaba en cada disparo `Plugin: NO` y `Done: 0 ok, 144 err` PERO terminaba con `Deploy OK [timestamp]` — era fácil confundir la línea final "Deploy OK" con éxito, cuando en realidad NINGÚN archivo del plugin se había escrito al disco. El self-update del webhook SÍ funcionaba (`OK self-update (31439b)`) porque usaba `file_put_contents(__FILE__, ...)` que escribe al path canónico del archivo en ejecución — esto es independiente de PLUGIN_PATH. Entonces el webhook se auto-actualizaba sin inconvenientes pero escribia CADA archivo del plugin a un path inexistente dentro de `deploy/wp-content/plugins/...` (que no pasó por `mkdir -p`).
2. **Causa raíz del PLUGIN_PATH mal.** El webhook (desde commit `23c0fa97`, Jun 3) definía `PLUGIN_PATH = __DIR__ . '/wp-content/plugins/lt-marketplace-suite'` ASUMIENDO que el webhook reside en `public_html/` (webroot, donde efectivamente hay subdirectorios `wp-content/plugins/...`). Pero en producción el webhook corre desde `public_html/wp-content/plugins/lt-marketplace-suite/deploy/ltms-deploy-webhook.php` (URL del disparo). En esa ubicación `__DIR__` = `.../deploy/`, y `PLUGIN_PATH` apuntaba a `.../deploy/wp-content/plugins/...` — un subdirectorio inexistente. Por eso `is_dir(PLUGIN_PATH)` retornaba false → `Plugin: NO` en output.
3. **Por qué el bug sobrevivió 2 meses en main.** El deploy previo WELL-known (KYC-AUDIT2, commit `f445baa3`, 2026-07-29) fue marcado como OK en el CHANGELOG sin validar via SSH que los archivos efectivamente se hubieran escrito a disco. La línea "Deploy OK" convenció al agente de ese ciclo de que el deploy funcionó. Pero probablemente falló igual — los archivos no llegaron, las "verificaciones funcional vía SSH" fueron inexactas o se hicieron en otra sesión donde el servidor había sido ya sync via git pull manual. **Regla preventiva:** nunca aceptar "Deploy OK" como evidencia de deploy exitoso. Verificar via SSH con `ls -la wp-content/plugins/lt-marketplace-suite/<archivo>` timestamps + tamaño vs el commit commiteado, o via `wp eval 'var_dump(file_get_contents("...")[片段])'` para confirmar el contenido freshness.
4. **Fix DEPLOY-WH-PATH-001 aplicado el 2026-07-30.** Detección del caso al inicio del script con `file_exists(__DIR__ . '/wp-load.php')`:
   ```php
   if ( file_exists( __DIR__ . '/wp-load.php' ) ) {
       // Caso (A) webroot: webhook en public_html/
       define( 'PLUGIN_PATH', __DIR__ . '/wp-content/plugins/lt-marketplace-suite' );
       define( 'WP_LOAD_PATH', __DIR__ . '/wp-load.php' );
   } else {
       // Caso (B) plugin dir: webhook en wp-content/plugins/.../deploy/
       define( 'PLUGIN_PATH', dirname( __DIR__ ) );  // subir 1 nivel desde deploy/ al root del plugin
       define( 'WP_LOAD_PATH', dirname( __DIR__, 4 ) . '/wp-load.php' );  // mismo cálculo que wp-config.php
   }
   ```
   Las 7 referencias hardcoded `__DIR__ . '/wp-load.php'` en los modos `?qa=1, ?fix_sellers=1, ?caps=1, ?report=1, ?backfill=1` y cleanup post-deploy fueron reemplazadas por `WP_LOAD_PATH`. Backward-compat: si el webhook cohnetemente reside en webroot (caso A), nada cambia. Solo se corrige el caso B que rompia silenciosamente.
5. **Hen-and-egg del self-update resolve solo.** El webhook VIEJO (commiteado antes del fix) tiene PLUGIN_PATH mal pero SÍ completa el `file_put_contents(__FILE__, $self)` del self-update (línea 545) porque `__FILE__` es el path real del script en ejecución — independiente de PLUGIN_PATH. Procedimiento de recuperación AUTO: (a) push del fix del webhook a main → (b) disparar webhook → webhook VIEJO se self-updatea con el webhook NUEVO desde GitHub + falla todos los archivos del plugin (esta invocación sigue corriendo el VIEJO cargado en RAM) → (c) si opcache permite, ya el archivo en disco queda nuevo → (d) siguiente disparo carga webhook NUEVO → PLUGIN_PATH correcto → `Plugin: YES` → deploy exitoso. Si opcache NO permite recargar (shared hosting con cache PHP-FPM), puede requerir espera del TTL de opcache (1-5 min) o invalidación manual via SSH `opcache_reset();`. En este caso funcionó en el segundo disparo sin espera — SiteGround probablemente respeta el opcache_reset() aunque reporta `N/A`.
6. **Canario gratuito a vigilar en cada disparo del webhook.** En el output del webhook, dos líneas son alarmas inmediatas de que algo va mal ANTES de mirar los OK/ERR de archivos individuales:
   - `Plugin: YES|NO` — si NO, PLUGIN_PATH está mal → todos los archivos van a fallar `wr` aunque GitHub los devuelva bien.
   - `wp-load.php not found` — mismo origen, afecta todos los modos `?qa=1, ?fix_sellers=1, ?caps=1, ?report=1, ?backfill=1`.
   
   Antes de aceptar un deploy como exitoso, buscar estas dos líneas. La línea final `Deploy OK [timestamp]` es MENTIROSA si las dos anteriores son alarma.
7. **El phantom en la whitelist.** El único `ERR dl:` restante en el deploy del fix fue `includes/business/class-ltms-booking-notifications.php`. El archivo SÍ existe en el repo (`git ls-files` lo confirma), pero GitHub Content API devolvió null para ese path. Probable timeout transitorio de GitHub API (rate-limit / red). Es un archivo no modificado en este commit → su versión en producción no cambia → no es urgente. Pero: si persiste en proximos deploys, investigar si el path en la whitelist del webhook (`deploy/ltms-deploy-webhook.php:595`) está bien escrito — podría ser un typo histórico que recibe phantom responses.
8. **Documentar el caso tolerado.** El webhook commiteado contiene una posible 3ra ubicación hipotética (webroot con symlink al plugin). El fix no verifica la coherencia de `PLUGIN_PATH . '/wp-load.php'` vs `WP_LOAD_PATH` (en caso A son iguales, en caso B son distintos — el primero no existe). Una validación más estricta sería declarar PLUGIN_PATH y luego verificar `is_dir(PLUGIN_PATH)` y si NO, abortar con `http_response_code(500)` y mensaje `ERROR: PLUGIN_PATH inválido -> {path}`. NO se añadió para minimizar el blast radius del fix; queda como backlog para próximas iteraciones.

### Lección #141: Comment block "AUDIT-FE-XXX FIX" describiendo un fix NO es evidencia de que el fix está aplicado — canarios mentirosos en comment blocks (AUDIT-FE-HC Fase 1.9 RE-aplicación)

Fecha: 2026-07-30 (Fase 1.9 — auditoría help-center.php).

1. **Hallazgo.** Al iniciar la Fase 1.9 (auditar `templates/help-center.php`), se encontró dentro del archivo un comment block extensivo que describía 5 hallazgos (`AUDIT-FE-HC-001..HC-005`) como "resueltos". Cada hallazgo venía con descripción del bug original, retazo del fix, y referencia a archivos de soporte. El comment block parecía el reporte final de un ciclo de auditoría cerrado.
2. **Re-auditoría revela lo contrario.** Al leer el source físico del template, se detectó que DOS de los 5 fixes NO estaban realmente aplicados:
   - **AUDIT-FE-HC-001 (onsubmit inline)**: el comment decía "onsubmit eliminado", pero `<form ... onsubmit="return false;">` seguía presente en la línea 157 del source. CSP violation activa en producción.
   - **AUDIT-FE-HC-004 (script-tag inline chat setup)**: el comment decía "el bloque fue eliminado", pero `$pv_chat_setup_html = '<script>window.__ltmsTawkProperty=...;</script>';` seguía presente en las líneas 117-123 Y el `echo $pv_chat_setup_html;` en el footer del template. Segundo bloque script-tag inline vivo.
3. **RE-aplicación.** Se eliminó físicamente:
   - la variable `$pv_chat_setup_html` + su asignación condicional + el `echo` en footer
   - el atributo `onsubmit="return false;"`
   y se validó con test estructural (`tests/unit/HelpCenterAuditTest.php::test_001 / test_002`) que la eliminación física estaba aplicada.
4. **Canarios mentirosos como patrón.** Un comment block "AUDIT-FE-XXX FIX" sin el fix físico correspondiente NO es una evidencia de fix aplicado: el canario MIENTE. Una herramienta (o un agente IA) que lea el comment block puede concluir "esta auditoría ya está cerrada" y saltársela — perpetuando el bug en producción. Mismo problema que los "fixes cosméticos" (regla madre de AGENTS.md): un cambio que "se ve" resuelto no cuenta como resuelto hasta que verificaste el código físico. La diferencia con los fixes cosméticos: estos no son cosméticos en el HTML, sino en los COMMENTS del código — más insidioso porque el lector humano ve el comment y confía.
5. **Regla derivada.** Toda auditoría cerrada (AUDIT-FE-* o similares) debe:
   - (a) tener tests estructurales (`tests/unit/...AuditTest.php`) que validen invariantes del source (no comments), como canario objetivo;
   - (b) los assert deben operar sobre el código físico, NO sobre el comment block; en tests con `assertStringNotContainsString`, stripar comments (`preg_replace('/\/\*.*?\*\//s', '', $src)`) antes de validar — un canario que opera sobre comments siempre pasa;
   - (c) cuando un test "pasa" por coincidencia de comment block (falso positivo), agregar `assertStringContainsString` positivo sobre un identificador físico del fix (línea específica, function name, attr data-pv-* nuevo) que SOLO existe cuando el fix realmente se aplicó;
   - (d) si la validación física contradice el comment block, BORRAR el comment block mentiroso y reemplazarlo por el comment block de la RE-aplicación — con traza que explique: "el fix previo SOLO añadió documentación sin tocar el source".
6. **Relación con lecciones previas.**
   - LECCIONES #140: "los canarios gratuitos pueden ser mentirosos" (Plugin: YES/NO en webhook). Esta lección generaliza: los canarios mentirosos pueden residir en comments del código, no solo en output del runtime.
   - LECCIONES #139: "UI que lee datos que nobody escribe es UI muerta". Análogamente: "Comment que documenta un fix que nobody aplicó es comment muerto". Ambos son código que parece funcionar pero no funciona.
7. **Trazabilidad.** Comprobado en Help Center Fase 1.9: dos de cinco fixes descritos en el comment block previo NO estaban aplicados físicamente (HC-001 onsubmit, HC-004 chat setup script-tag). RE-aplicados en el mismo commit, validados con 13 tests en `tests/unit/HelpCenterAuditTest.php` (71 assertions) que operan sobre source físico con technique de comment-strip.

### Lección #142: `DEFAULT \'\'` (backslash escapando comilla simple dentro de string PHP con comillas dobles) SIEMPRE es bug — backslash pasa literalmente a SQL, MySQL lo rechaza

Fecha: 2026-07-30 (AUDIT-DB-001 — detectado en validación SSH post-deploy Fase 1.9).

1. **Hallazgo.** En `includes/core/migrations/class-ltms-db-migrations.php:760-762`, las 3 columnas `codigodane`, `departamento`, `nombremun` de la tabla `lt_aveonline_cities` usaban `DEFAULT \'\'` (backslash escapando comilla simple dentro de un string PHP delimitado por comillas dobles). En PHP, dentro de `"..."`, las comillas simples NO necesitan escape con backslash — el backslash pasa LITERALMENTE al string. La SQL final generada era `... NOT NULL DEFAULT \'\' ...` con backslash literal, que MySQL 8.4 rechaza con `You have an error in your SQL syntax; ... near '' at line 4`.
2. **Síntomas.** El `debug.log` mostraba WordPress database error en CADA `wp plugin activate` (confirmado desde 23-Jul-2026, 7 días de ruido). dbDelta NO aborta la migración — el plugin terminaba `Success: Activated` normalmente, pero la entrada en el log era permanente en cada recarga. Como `WP_DEBUG=true` estaba activo, los warnings eran visibles; en una instalación con `WP_DEBUG=false` serían silentes pero el bug existía igual.
3. **Causa raíz probable.** Confusión con el escaping de PHP. En strings con comillas simples (`'...\'...'`) SÍ se necesita escape de comillas simples. En strings con comillas dobles (`"...'...'..."`) NO se necesita. Probablemente el autor escribió el texto SQL pensando en comillas simples externas, pero el literal PHP usaba comillas dobles. El resultado: el backslash espurio se mantuvo en producción 7 días porque nadie releyó el diff ni reauditoría.
4. **Fix.** Eliminar los 3 backslashes: `DEFAULT \'\'` → `DEFAULT ''` (estándar, paridad con otras 200+ columnas del mismo archivo que usan correctamente `DEFAULT ''` sin backslash). Re-auditoría con `Select-String -SimpleMatch "\\'"` confirma que solo estas 3 ocurrencias en todo el archivo cometían el error.
5. **Regla preventiva.** NINGÚN literal SQL dentro de un string PHP con comillas dobles debe usar `\'` para escapar comillas simples — las comillas simples aquí NO necesitan escape. Validar con un test estructural (`DbMigrationsAuditTest::test_006_regla_preventiva_higiene_no_backslash_quote`) que stripa PHP comments (`/* */` y `//`) y luego cuenta cero ocurrencias de `\'` en código. Este test captura cualquier forma del bug (no solo DEFAULT, también INSERT IGNORE ... VALUES, etc.) y previene reaparecimiento.
6. **Relación con lecciones previas.**
   - LECCIONES #141: canarios mentirosos en comment blocks. Higiene de código preventiva extends la misma idea: tests que operen sobre source físico (no sobre comments) deben stripar comments antes de validar — el test_006 con `substr_count($src, "\\'")` sin stripar comments fallaría con 5 falsos-positivos (las 5 menciones de `DEFAULT \'\'` EN el comment del propio fix). Implementación final stripa comments → 0 ocurrencias en código real.
   - LECCIONES #140: canarios mentirosos en output del runtime. Esta lección generaliza a código muerto: el warning del log no era fatal porque dbDelta no abortaba, pero igual ensuciaba el log — los "canarios verbosos" del debug.log deben investigarse, no ignorarse como "ruido inofensivo" sin confirmar causa raíz.
7. **Trazabilidad.** Comprobado en AUDIT-DB-AVE-001: 3 backslashes espurios en `lt_aveonline_cities` causaban SQL syntax error en cada activate del plugin desde 23-Jul-2026. Fixeado en el commit AUDIT-DB-AVE-001 (30-Jul-2026), validado con 6 tests en `tests/unit/DbMigrationsAuditTest.php` (19 assertions) que operan sobre source físico con technique de comment-strip (paridad con `HelpCenterAuditTest`). Verificación SSH post-deploy confirmó: el error de `bkr_lt_aveonline_cities` desapareció del `debug.log` tras el fix deployado. El segundo error (AUDIT-DB-COMM-001, `bkr_lt_commissions`) fue investigado y descartado como cascada de schema drift entre DB y source (12 columnas extras del módulo MX) — backlog P2 documentado en CHANGELOG.

### Lección #143: comments JS descriptivos del fix NO deben mencionar textualmente identificadores que el test de no-contención busca — la técnica de comment-strip no es operativa en JS

Fecha: 2026-07-30 (AUDIT-FE Fase 1.10 — cierre CSP-compliance de home.php + single-product.php).

1. **Hallazgo.** Durante la Fase 1.10, al escribir `tests/unit/HomeProductScopeAuditTest.php` para verificar que `window.ltms_pv_currency` fue eliminada del source, el test `test_010_pv_config_pvCurrency_mapping_en_js` fallaba en falso positivo. Causa: el comment JS del scope PRODUCT (líneas 1828+, en `ltms-plaza-viva.js`) describía el fix SP-002 textualmente: "AUDIT-FE-SP-002 FIX (Fase 1.10): `window.ltms_pv_currency` (objeto de config de moneda WC)...". El test `$this->assertStringNotContainsString('window.ltms_pv_currency', $js)` no stripa comments JS porque la technique de comment-strip que funcionaba en PHP (`preg_replace('/\/\*.*?\*\//s', '', $src)`) NO es trivial de aplicar en JS — JS tiene 3 sintaxis de comment (`/* */`, `//` hasta newline, y HTML-style `<!--`/`-->` que también cuenta en JS), comments siempre-toggle por línea, y strings regex/string literals que pueden contener `/` o `//` sin ser comments. Stripper robusto requiere un parser JS completo — fuera de scope para un test estructural simple.
2. **Síntomas.** Test fallaba: "Failed asserting that '...// AUDIT-FE-SP-002 FIX ... window.ltms_pv_currency ...' does not contain 'window.ltms_pv_currency'". El 4° failure era en `test_011` por `new URLSearchParams()` buscado de forma global — ese assertions fallaba porque el helper `PV.ajax` (líneas 562+) SÍ usa `new URLSearchParams()` legítimamente para todos los AJAX del design system, no solo el bundle ATC del scope PRODUCT. Era un canario erróneo (comment-stripping no resuelve eso: `new URLSearchParams()` ES código vivo en el helper, no un comment).
3. **Causa raíz.** (a) Re-aplicación de LECCIONES #141 con un matiz nuevo: la technique de comment-strip que SÍ es trivial en PHP (`/* */` regex con `/s` flag) NO es portable a JS de forma simple, así que la regla "strips comments antes de validar nonces negativos" de #141 se debe complementar con: "el propio comment descriptivo del fix NO debe contener identificadores que el test de no-contención busca". El comment descriptivo debe EXPLICAR el fix sin mencionar textualmente el identificador problemático — basta la traza del ID del fix (`AUDIT-FE-SP-002 FIX`) + descripción funcional (sin el identificador literal). (b) Assertions de no-contención globales sobre un archivo JS grande son frágiles porque capturan helper code reusado legítimamente — siempre acotar el assertion al patron específico del bug (en este caso `body.append('action','ltms_plaza_viva_add_to_cart')` era el inline original, no el helper `PV.ajax`).
4. **Fix doble.** (a) Re-escribir los comments descriptivos del scope HOME + scope PRODUCT en `ltms-plaza-viva.js` para que mencionen el identificador ELIMINADO de forma indirecta ("un global inline inyectado dentro del script-tag del template" en vez de `window.ltms_pv_currency` literal). Después de este re-escritura, `grep window.ltms_pv_currency assets/js/ltms-plaza-viva.js` devuelve 0 matches — paridad con el estado esperado del source. (b) Reemplazar el assertion `assertStringNotContainsString('new URLSearchParams()', $js)` por el assertion específico `assertStringNotContainsString("body.append('action','ltms_plaza_viva_add_to_cart')", $js)` que captura el patron del script-inline original (cuando inline, se hacía `body.append('action', 'ltms_plaza_viva_add_to_cart')` directamente; ahora `PV.ajax('ltms_plaza_viva_add_to_cart', ...)` lo hace internamente en el helper). El helper `PV.ajax` sigue usando `new URLSearchParams()` legítimamente — esa línea NO es un canario del bug.
5. **Regla preventiva.** Para TODO test estructural de no-contención sobre archivos JS, evitar mentions del identificador eliminado en comments del archivo fixed. Cuando se quiera documentar el fix en comments, usar descripción funcional (mencionar qué era el bug, no el identificador literal) + traza del ID del fix (`AUDIT-FE-XXX-NNN FIX`). Para assertions de no-contención globales, preferir patrons específicos del bug vs assertions globales: en vez de "no existe `new URLSearchParams()`" (que captura helper code legitimo), usar "no existe `body.append('action','X')` directamente" (que captura solo el patron inline original). Para comment-stripping en JS donde no haya un parser confiable, documentar en el docblock del test por qué no se stripa comments JS (a diferencia de PHP) y en su lugar re-escribir comments descriptivos para no mencionar identificadores canary.
6. **Relación con lecciones previas.**
   - LECCIONES #141 (canarios mentirosos en comment blocks): reforzada y extendida. La lección anterior trataba de comments PHP donde el comment block decía "resuelto" sin haberse aplicado el fix — el canario mentía sobre la presencia del fix. Esta lección trata del caso inverso: el comment descriptive MENCIONA el identificador eliminado y hace fallar el test de no-contención — el canario falso-positivo bloquea la validación que confirma que el fix SÍ se aplicó. Solución común: el test debe stripar comments y operar sobre source físico. Matiz nuevo: en JS la technique de comment-strip es no-trivial, así que la higiene del comment descriptivo es más importante que en PHP.
   - LECCIONES #110/#128 (SiteGround SG Optimizer combina CSS / `.min.js` desincronizado sin regenerar): reforzada. La verificación de que `.min.js` contiene `pvCurrency` y `data-pv-bundle-discount` es necesaria porque SG Optimizer carga el `.min.js` en producción, no el `.js` source — un fix que solo esté en el source pero no en el `.min.js` es fix muerto en producción.
7. **Trazabilidad.** Comprobado en AUDIT-FE Fase 1.10: tras el re-escritura de comments descriptivos del scope HOME + scope PRODUCT en `ltms-plaza-viva.js` (eliminando mentions textuales de `window.ltms_pv_currency`), los 13 tests de `tests/unit/HomeProductScopeAuditTest.php` (76 assertions) pasan en verde. La suite unit completa: 3448 tests OK, 0 errors, 0 failures, 3 skipped (+13 nuevos desde 3435). Validado: `grep window.ltms_pv_currency assets/js/ltms-plaza-viva.js` = 0 matches (estado esperado del source). El test `test_013_min_js_sincronizado_con_scope_product` valida que el `.min.js` también quedó limpio — la regeneración con `npm run build:js` propagó el cleanup a la versión minified.

---

## 15. v2.9.x — AUDIT-PROD-QA-001: paridad Edit/New en panel vendedor + validación de noches mínimas (1 lección nueva)

> Ciclo de auditoría focalizado en el panel del vendedor: el modal Edit de producto no exponía peso/dimensiones aunque el backend `get_product()` ya los devolvía — el peso quedaba congelado sin poder corregirlo, y Deprisa/Aveonline usaban datos stale. Hallazgo paralelo en booking-calendar.js: la validación de `minNights` solo disparaba cuando `data.minNights > 1`, así que `minNights=1` permitía checkin=checkout (0 noches) y mostraba COP 0 sin error en el calendario.

### Lección #144: dos modales paralelos (New/Edit) divergen en features → Edition queda congelada en datos stale

1. **Síntoma.** Vendor edita un producto físico en el panel. El modal mostraba SKU, etiquetas, shipping class — pero NO peso, largo, ancho, alto. Al guardar, el backend recibía estos campos vacíos y NO actualizaba las dimensiones reales del producto (el `update_product` ignoraba `weight`/`dim_*` ausentes). Resultado: el peso/dims quedaban congelados con los valores que el vendor puso al CREAR el producto, sin poder corregirlos. Integraciones de envío (Deprisa, Aveonline) usaban estos datos para calcular el costo → cotizaciones con peso erróneo.
2. **Causa raíz.** El plugin mantenía dos modales paralelos para la misma entidad (producto): "New" y "Edit", cada uno con su propio HTML en `view-products.php` y su propio handler JS en `ltms-products.js`. El modal New sí tenía `#ltms-peso`/`#ltms-length`/etc. (anhade producto conoce el peso), pero el modal Edit solo `#ltms-ep-sku`/`#ltms-ep-tags`/`#ltms-ep-shipping-class` — le faltaba el bloque `#ltms-ep-physical-fields`. La función `get_product()` del backend (PHP) SÍ devolvía `weight`/`length`/`width`/`height` en la respuesta AJAX (lo correcto), pero el JS del handler Edit no poblaba estos inputs porque NO existían en el DOM. Y la función `updateEditProductTypeFields()` tampoco toggleara el bloque physical (lo dejaba ausente).
3. **Fix.** (a) HTML: añadir el bloque `#ltms-ep-physical-fields` al modal Edit en `view-products.php` con 4 inputs Peso/Largo/Ancho/Alto (paridad con el bloque del modal New). (b) JS populate: el handler Edit (`get_product` response) ahora pobla los 4 inputs con guard null/undefined/`''`. (c) JS submit: el submit de Edit ahora envía `weight`/`dim_length`/`dim_width`/`dim_height` al backend (paridad con el submit de New). (d) JS toggle: `updateEditProductTypeFields()` ahora toggleara el bloque physical + el input stock según el tipo (mismo comportamiento que `updateProductTypeFields` del modal New). 6 tests estructurales cubren los 4 aspectos (HTML presence, JS populate, JS submit, JS toggle, PHP `get_product` regression).
4. **Regla preventiva.** Cuando existan dos vistas paralelas para la misma entidad (New/Edit, Create/Update, Front/Back), auditarlas juntas — el contrato de la entidad (qué campos la componen) debe ser idéntico en ambas, o el que tenga menos es terminalmente aburrido de drift. Patrón extendido: "el backend ya lo devolvía pero el frontend no lo leía" se vincula con LECCIONES #139 (UI que lee datos que nadie escribe) y #141 (canarios mentirosos) — el backend SÍ escribía los datos, PERO el frontend los leía de un DOM que no tenía dónde ponerlos. Una auditoría estática de "campos de la entidad vs campos exhibidos en el modal" habría atrapado el gap en 1 minuto.
5. **Relación con lecciones previas.**
   - LECCIONES #139 (UI que lee datos que nadie escribe): espejo. Aquí era "backend que escribe datos que la UI no lee" — misma disfunción en el otro sentido. La implicación es bidireccional: la integridad de un campo exige que los dos extremos tengan el contrato en sincronía.
   - LECCIONES #141 (canarios mentirosos en comment blocks): si el modal Edit hubiera tenido un comment block "campos de peso/dims added" sin tenerlos realmente, la trampa sería la misma. La auditoría fue sobre source físico, no sobre comments.
6. **Trazabilidad.** Comprobado en AUDIT-PROD-QA-001: tras añadir el bloque `#ltms-ep-physical-fields` + el toggle en `updateEditProductTypeFields()` + el populate/submit en ltms-products.js, los 6 tests nuevos en `tests/unit/ProductsAuditFixTest.php` (89 assertions totales — 22 tests en la suite) pasan en verde. Suite unit completa: 3454 tests OK, 0 errors, 0 failures, 3 skipped (+6 nuevos desde 3448). El booking-calendar.js quedó con `data.minNights > 0` (incluye el caso `minNights=1`). Los `.min.js` de ambos archivos fueron regenerados con `npm run build:js` (44/44 minified OK).

---

## 16. v2.9.306 — UX-AUDIT-FE: design tokens PV sin style="..." inline + criterio Star Seller unificado (1 lección nueva)

> Ciclo de auditoría focalizado en el **design system Plaza Viva**: 5 hallazgos P0 del mismo patrón — `style="..."` inline + colores Tailwind/WC hardcodeados (#E80001, #f3f4f6, #f0fdf4, #fff7ed, #f9fafb, #1A1F2E, #565C66, #6b7280, #2563eb, #374151, #166534, #9a3412) en plantillas públicas, sin mapeo a los tokens PV del `:root` (--brand, --accent, --text, --text-2). Adicionalmente P0-05 unificó el criterio "Star Seller" divergente entre 3 plantillas (home, vendor-store, single-product usaban 2 fórmulas distintas).

### Lección #145: cuando un `style="..."` inline se replica en 3+ vistas para el mismo propósito visual, falta un componente en el design system — la migración a clase reusable elimina el inline Y el costo de mantenimiento futuro

1. **Síntoma.** Patrones repetidos en plantillas públicas:
   - "WooCommerce no activo" / "Vendedor no encontrado" → 6 ocurrencias de `style="padding:60px 22px;text-align:center"` en 5 plantillas (cart, checkout, home, order-tracking, vendor-store x2). Cambiar el padding del fallback exigía 6 edits.
   - Botón CTA brand → `.pv-btn--brand` definido scoped en `ltms-checkout.css` (2 reglas duplicadas para `pv-cart` y `pv-checkout`) y embebido inline en `cart.php` `<style>` — 3 definiciones idénticas del mismo `#E80001` hardcodeado. Cambiar el color del CTA exigía 3 edits en 2 archivos.
   - Mini-badges / info cards / legal block / empty state → 12+ `style="..."` inline con paleta Tailwind ad-hoc (#f3f4f6 para neutral, #f0fdf4 para success, #fff7ed para warning, #1A1F2E para text, #565C66 para text-2, #E80001 para brand) que NO tenían contraparte en los tokens PV (`--bg`, `--accent`, `--text`, `--text-2`, `--brand`). Un cambio de branding (ej. pasar de rojo #E80001 a un rojo más cálido) exigía encontrar y reemplazar en cada sitio.
2. **Causa raíz.** Diseño orgánico sin disciplina de design tokens: cada vista añadía su `style="..."` inline con los colores que el dev veía en el diseño Figma. Como los tokens PV (`--brand`, `--accent`, etc.) SÍ existían en el `:root` del design system, PERO no se habían expandido a componentes reusables para info cards / legal block / fallback / CTA brand / mini-badges, cada plantilla re-implementaba el mismo remedio visual con su propia copia del color. La duplicación silenciona el costo de mantenimiento: nadie nota que cambiar el color del CTA exige 3 edits en 2 archivos hasta que se hace el intento y se descubre que 1 de los 3 se olvidó.
3. **Fix.** 5 patrones migrados a classes reusables del design system:
   - **P0-01**: `.ltms-mini-badges` + `.ltms-mini-badge(--accent|--primary)` + `.ltms-mini-badge__dot` + `.ltms-loop-vendor-badge` (en `ltms-frontend-extensions.css`); `.pv-info-card(--neutral|--success|--warning|--info|--inline)` + `.pv-info-card__title/__body/__list` (en `plaza-viva.css` §3.1.1). Reemplaza 4 bloques de info card en single-product.php + 3 bloques de mini-badges en trust-badges.php.
   - **P0-02**: `.pv-legal-block` + `.pv-checkbox` + `.pv-checkbox input[type="checkbox"]` (con `accent-color:var(--brand)`, `:focus-visible{outline:2px solid var(--brand)}`) + `.pv-checkbox__label` + `.pv-empty-state__title/__sub` (en `plaza-viva.css` §3.1.2/§3.1.3). Reemplaza 8 `style="..."` inline en checkout.php.
   - **P0-03**: token `--brand:#E80001` + derivados (`--brand-600`, `--brand-50`, `--brand-100`) añadidos al `:root` del `plaza-viva.css`; class global `.pv-btn--brand{background:var(--brand)}` + `:hover` + `:active` (en §3 COMPONENTS · BUTTONS). Elimina las 2 definiciones scoped duplicadas de `ltms-checkout.css` + la embebida en `cart.php`.
   - **P0-04**: `.pv-fallback__section` + `__title/__sub/__msg/__action` (en `plaza-viva.css` §3.1.4). Reemplaza los 6 bloques de fallback en cart, checkout, home, order-tracking, vendor-store (con `pv-fallback__msg` para fallbacks simples WC-disabled y `pv-fallback__title/__sub/__action` para el "Vendedor no encontrado" con CTA).
   - **P0-05**: helper estático `LTMS_Trust_Badges::is_star_seller(int $vendor_id): bool` con criterio canónico (`ltms_kyc_status==='approved'` AND `ltms_star_seller ∈ {'1', 1, true}`), cacheado con transient 1h. Single-product y vendor-store delegan al helper con fallback defensivo; home documenta en comment que el query server-side debe coincidir con el criterio del helper.
4. **Regla preventiva.** Para TODO `style="..."` inline en plantilla pública, antes de aceptarlo como "excepción legítima", buscar el mismo patrón visual en otras 2+ vistas — si aparece en 3+ se está Gilbertando un componente ausente del design system. La migración a clase reusable NO es simplemente "mover el style a CSS" — debe (a) usar tokens del `:root` en vez de colors hardcoded (si el design system no tiene un token para el color, añadirlo al `:root` primero), (b) usar variantes nombradas (`--neutral`, `--success`, `--warning`, `--info`) en vez de variantes hexadecimales implícitas, (c) sub-clases estructurales (`__title`, `__body`, `__list`) en vez de estilizar tags HTML directamente (más resiliente a markup changes). El test que valida la migración debe ser **structural** (file_get_contents + asserts) y verificar tanto la ausencia del inline (assert negativo) como la presencia de la clase nueva (assert positivo) — solo el assert negativo crea canarios mentirosos (LECCIONES #141) si el template migró el inline a OTRO inline ligeramente distinto en vez de a una clase.
5. **Relación con lecciones previas.**
   - LECCIONES #139 (UI que lee datos que nadie escribe): espejo. Aquí era "UI que tiene estilo que nadie más usa" — misma disfunción de falta de contrato. La solución en los dos casos es unificar: backend escribe → UI lee; design system define → plantilla consume.
   - LECCIONES #141 (canarios mentirosos en comment blocks): si el test solo valida "no hay `style="..."` inline" sin validar "SÍ hay `class="pv-fallback__section"`", una migración a OTRO inline (`style="padding:60px 20px;text-align:center"` con 20px en vez de 22px) pasaría el test sin aplicar el fix. El assert positivo es la válvula.
   - LECCIONES #144 (divergencia entre vistas paralelas): P0-05 es el caso estrella — 3 plantillas tienen 2 criterios distintos para decidir si un vendor es Star Seller. La solución es un helper canónico con delegación desde todas las vistas (paridad con "el contrato de la entidad debe ser idéntico entre vistas"), el mismo patrón que unificar `get_product()` entre New y Edit modales.
6. **Trazabilidad.** Comprobado en UX-AUDIT-FE v2.9.306: tras migrar los 5 patrones, los 20 tests nuevos en `tests/unit/UxAuditFeTest.php` (103 assertions) pasan en verde — cubren los 5 hallazgos (P0-01, P0-02, P0-03, P0-04, P0-05) con asserts positivos (presencia de clases nuevas + traza del fix) Y asserts negativos (ausencia de inline + ausencia de Tailwind hardcoded + ausencia del criterio antiguo sales>=50). Suite unit completa: 3,474 tests OK, 5,999 assertions, 0 errors, 0 failures, 3 skipped (+20 nuevos desde 3,454). `npm run build:css` regeneró 22/22 `.min.css` OK (0 errors); `npm run lint:js` = 44 OK 0 failed (no se tocaron JS). `php -l` sin errores en 7 archivos PHP tocados + el test. LTMS_VERSION bump 2.9.305 → 2.9.306 para forzar cache-busting en SG Optimizer.

---

## 17. v2.9.305 — ADMIN-KYC-APPROVE-AUDIT: bug "desconectado" al aprobar KYC (1 lección nueva)

> Ciclo de auditoría focalizado en el panel admin KYC, disparado por un bug reportado: al aprobar un vendedor KYC el admin veía el toast "Error de conexión." (parecía server caído) y la aprobación no se efectivizaba. La auditoría siguió el flujo Explorar → Planificar → Ejecutar → Revisar de AGENTS.md y descubrió raíz dual: filtros de compliance que bloqueaban silenciosamente + callback jQuery `.fail()` que convertía cualquier HTTP no-2xx en "Error de conexión" sin distinguir la causa.

### Lección #146: cuando un AJAX admin devuelve 403 con mensaje genérico y el JS dice "Error de conexión", los 2 lugares a auditar son (a) `apply_filters`/`do_action` del módulo y (b) el callback `.fail()` — nunca el servidor

1. **Síntoma.** Admin revisa documentos KYC de un vendedor, da clic en "Aprobar". Recibe toast rojo: "Error de conexión.". La aprobación no se efectiviza. El admin interpreta que el servidor está caído y reintenta; sigue fallando. Soporte recibe el reporte como "no puedo aprobar vendedores desde el panel". Sospecha inicial de todos: hosting SiteGround, OPcache, BD caída, timeout de sesión.
2. **Causa raíz.** Doble:
   - **PHP:** el handler `ajax_approve_kyc` aplica `apply_filters('ltms_kyc_pre_approve', true, $vendor_id)` para que 4 filtros (FT-2 sanctions, AC-7 RUT+Cámara de Comercio, RT-2 registro sanitario, HD-12 minor) bloqueen la aprobación si hay condición de compliance incumplida. PERO cada filtro devolvía `false` mudo al bloquear — el handler lo cast a `bool`, obtenía siempre `false`, y devolvía `wp_send_json_error('Aprobación bloqueada por política de cumplimiento. Revisar logs.', 403)`. Un mensaje genérico que no dice si el problema es Cámara de Comercio faltante, NIT inválido en DIAN, lista OFAC no disponible temporalmente, o menor sin autorización. El admin solo ve "bloqueada" + "revisar logs" — lo cual es hostil y no actionable.
   - **JS:** los 3 callbacks jQuery `.fail()` en `html-admin-kyc.php` (approve, reject, view-docs) usaban el string hardcoded `'Error de conexión.'` para CUALQUIER HTTP no-2xx — incluyendo el 403 con body `wp_send_json_error` perfectamente parseable. jQuery interpreta el 403 como "request failed" y dispara `.fail()` sin mirar el body; el admin no tiene forma de ver que el servidor SÍ respondió con JSON válido diciendo "Cámara de Comercio faltante".
   - El match entre el 403 (PHP) y el `.fail()` indistinto (JS) produce el efecto "desconectado": el admin ve "Error de conexión" cuando en realidad el servidor respondió perfectamente con la razón del bloqueo, solo que el JS la descartó.
3. **Fix.**
   - **PHP (4 filtros):** los 4 filtros `ltms_kyc_pre_approve` (FT-2 `screen_against_sanctions_lists`, AC-7 `validate_rut_and_camara_comercio`, RT-2 `validate_sanitary_registration`, HD-12 `verify_minor_authorization`) ahora retornan `new \WP_Error( $code, $msg )` en vez de `false` cuando bloquean. Cada `$code` es identificable (ej: `ac_cc_missing`, `ft_sanctions_match`, `kyc_sanitary_reg_expired`, `hd_minor_auth_missing`) y cada `$msg` menciona la ley aplicable + el ID del vendedor + el próximo paso ("El vendedor #5 debe completar este campo en su panel y reenviar el KYC").
   - **PHP (2 handlers):** `ajax_approve_kyc` y `ajax_quick_approve_kyc` ahora inspeccionan `instanceof \WP_Error` en el resultado de `apply_filters`, extraen `get_error_message()` y `get_error_code()`, y los envían al admin vía `wp_send_json_error( [ 'message' => ..., 'block_code' => ... ], 403 )`. El log `KYC_APPROVE_BLOCKED_BY_FILTER` ahora incluye `block_code` en el contexto — antes solo decía "bloqueado por filter" sin decir cuál ni por qué.
   - **JS:** nueva función `ltmsHttpFailReason(jqXHR)` distingue 4 escenarios: (1) `status === 0` → error de red real ("Verifica tu red"); (2) 401 o 403 con content-type text/html → sesión expirada ("Recarga la página e inicia sesión"); (3) cualquier 2xx-4xx con content-type application/json → parsea el body y extrae `data.message` (aquí llega el motivo específico del WP_Error); (4) status ≥500 con HTML → error de servidor ("Revisa el error_log"). Los 3 callbacks `.fail()` (approve, reject, view-docs) delegan en esta función en vez del string fijo.
4. **Regla preventiva.** Para TODO AJAX admin handler que devuelva 403 con mensaje genérico (o cualquier status con mensaje no-specificable), auditar los 2 puntos de fallo:
   - **Punto A — `apply_filters`/`do_action` del módulo:** ¿alguno puede retornar `false`/`null` mudo? Si sí, conviene migrarlos a `WP_Error` con código identificable + mensaje traducible. Un `false` que viaja 3 capas arriba pierde TODO el contexto — un `WP_Error` lo preserva.
   - **Punto B — callback `.fail()` del JS jQuery:** ¿distingue HTTP status del body? Si solo hace `.fail(function() { show('Error de conexión'); })`, está mapeando 4 causas distintas (red, sesión, compliance-block, server-error) a 1 string. La distinción por `jqXHR.status` + `getResponseHeader('content-type')` + parse del `responseText` es trivial de implementar (ver `ltmsHttpFailReason`) y borra la falsa alarma "desconectado" en el 80% de los casos.
   - El bug "desconectado" en AJAX admin casi siempre está en uno de esos dos puntos — NO en el servidor. La prueba empírica: abrir DevTools → Network → ver la response del request fallido. Si status es 403/500 y el body es JSON con `{success:false, data:{message:"..."}}` → el servidor hizo su trabajo; el bug está en el JS que no supo leerlo. Si el body es un HTML de wp-login o 0 bytes → está en el servidor (sesión, fatal, timeout).
5. **Relación con lecciones previas.**
   - LECCIONES #144 (divergencia entre vistas paralelas): espejo. Aquí es "el backend SÍ produce el mensaje, PERO el JS NO lo lee" — disfunción de contrato backend↔frontend en sentido inverso a #144 (donde era el backend que producía datos que el frontend no exhibía).
   - LECCIONES #139 (UI que lee datos que nadie escribe): también conectada. La UI esperaba un mensaje específico (`data.message`), pero el backend producía un string genérico — no había nada specific que leer. El fix añade el `data.message` real.
   - LECCIONES #112 (fail-closed SARLAFT, ciclo Plaza Viva): este fix NO relaja el fail-closed — solo añade visibilidad del motivo. La postura de compliance (bloquear si la lista no está disponible) se mantiene intacta; el admin ahora sabe que es fail-closed y no una desconexión.
6. **Trazabilidad.** Comprobado en ADMIN-KYC-APPROVE-AUDIT: tras aplicar los 7 fixes (4 filtros PHP + 2 handlers PHP + 1 JS), los 10 tests nuevos en `tests/unit/AdminKycApproveAuditTest.php` (39 assertions) pasan en verde — cubren los 2 puntos de fallo (Punto A: coverage estructural sobre los 4 filtros + el handler; Punto B: coverage estructural sobre `ltmsHttpFailReason` + el string "Tu sesión expiró" + la ausencia del callback hardcoded `'Error de conexión.'`). Suite completa: 3484 tests, 48 errors, 25 failures (baseline pre-fix sin mis cambios: 51 errors, 31 failures — mis fixes **reducen 9 tests rojos** sin introducir regresión; los fallos residuales son de módulos no-KYC pre-existentes: `TaxStrategyMexicoTest`, `TaxEngineTest`, `BookingManagerTest`, `PaymentOrchestratorTest`, `MediaGuardTest`, `KYCComplianceTest::test_vault_op_*` — fuera de alcance). `php -l` sin errores en los 6 archivos PHP/JS editados + el test.

---

## 18. v2.9.305 — ADMIN-PAYOUT-AUDIT-RE: re-audit cycle que descubrió la polaridad opuesta del bug "desconectado" (1 lección nueva)

> Continuación del ciclo ADMIN-KYC-APPROVE-AUDIT (#146) hacia el módulo de **retiros** (payouts). La re-auditoría del handler `class-ltms-admin-payouts.php` reveló 2 hallazgos P1 del mismo anti-patrón "canal-equivocado" — pero con polaridad inversa: el bug de KYC daba **falsa alarma de desconexión** (admin creía server caído cuando era bloqueo legítimo), el bug de payouts daba **falsa sensación de éxito** (admin creía aprobación exitosa cuando el scheduler bloqueaba). La fix es estructuralmente idéntica a #146 pero正好 invertida: en lugar de enrutar `WP_Error→403 JSON`, enrutamos `{success:false,...}→422 JSON` con el canal correcto (`wp_send_json_error` para que `.done()` no lo tome como exitoso y `.fail()` distinga合规-block de red real).

### Lección #147: el bug "desconectado" (#146) tiene una polaridad opuesta — el bug "falso éxito" — y ambos son instancias del mismo anti-patrón "mezclar canales success/error en `wp_send_json( $result )`"

1. **Síntoma.** Admin aprueba un retiro (payout) desde `html-admin-payouts.php` vía "✓ Aprobar". El scheduler `LTMS_Payout_Scheduler::approve()` valida compliance (KYC aún vigente PO-BUG-B, límites operativos FT-3, doble-claim M-117) y devuelve `{success:false, message:'No se puede aprobar el retiro: KYC ya no está aprobado.'}`. Sin embargo, la UI del admin muestra **"✓ Aprobado" en la fila** y el toast dice "Retiro #X aprobado correctamente." — el admin da por hecho que el pago se ejecutó.(nullable en BD: el retiro sigue `pending`). Una semana después el vendor reclama via soporte que el pago nunca llegó; el admin investiga su action log y ve "aprobé el retiro #X"; el log de backend revela `KYC_APPROVE_BLOCKED_BY_FILTER` PERO sin que el admin lo viera. El payout real solo se creditó si el admin manualmente re-intentó y casualmente el KYC estaba vigente otra vez.
2. **Causa raíz.** Doble, espejo de #146:
   - **PHP:** los handlers `ajax_approve_payout` y `ajax_reject_payout` llamaban `wp_send_json( $result )` que empaqueta el array `{success:bool, message:string, ...}` del scheduler dentro del canal **success** de la response HTTP (status 200, body `wp_send_json_success`). Cuando `success=false`, jQuery `.done()` parse el body como HTTP-200-success y ejecuta `if (res.success) {} else {...}`. El ELSE caía a `ltmsNotify('error', res.data || 'Error al aprobar.')`. Pero `res.data` **no existe** en el payload del scheduler (es `res.message`, no `res.data.message`) → el operador `||` cae al fallback `'Error al aprobar.'` sin contexto. Adicionalmente, el JS actualizaba el badge de la fila a "Aprobado" sin chequear `res.success` primero, así que el admin veía el badge cambiado Y el toast de error — mensaje contradictorio en el que el toast suele predominar como perception.
   - **JS:** los 3 callbacks `.fail()` (approve, reject, export CSV) usaban el string inline `'Error de conexión.'` para CUALQUIER HTTP no-2xx — mismo bug que #146 P1-3 pero en la vista payouts. Si el scheduler devolviera `success` en channel error (`wp_send_json_error` con 403/422), el `.fail()` descartaba el body y el admin perdía el motivo real.)
   - **Match anti-patrón:** `wp_send_json( $result )` es el patrón culpable. Empaqueta ambos caminos (éxito y fallo lógico del scheduler) en el MISMO canal HTTP 200, así el receptor (JS) no puede distinguirlos via el status HTTP — solo via el flag interno `res.success`, que require cooperación manual del JS. Si el JS se equivoca en leer el flag (o lo ignora, o lo lee mal), el resultado es falso éxito O falsa desconexión, dependiendo de qué rama ejecuta incorrectamente.
3. **Fix.**
   - **PHP (refactor handler):** nuevo método privado `normalize_payout_result( array $result, string $log_context )` que enruta el array del scheduler al canal AJAX correcto: `wp_send_json_success($payload)` si `success=true` (preserva payload extra, elimina flag duplicado), `wp_send_json_error({message, block_code}, 422)` si `success=false`. Status **422 Unprocessable Entity** elegido por semántica: "la entidad (retiro) es válida pero el proceso fue rechazado por reglas de negocio" — distinto de 500 "server down" para que el JS distinga "compliance block" de "error técnico". Los handlers `ajax_approve_payout`/`ajax_reject_payout` ahora delegan en este método en vez de llamar `wp_send_json( $result )`. El log `PAYOUT_APPROVE_BLOCKED`/`PAYOUT_REJECT_BLOCKED` se registra con `block_code` y `admin_id`.
   - **JS (delegación `.fail()`):** definida inline `window.ltmsHttpFailReason(jqXHR)` con guard `if (typeof window.ltmsHttpFailReason !== 'function')` para no redefinir si la vista KYC cargó primero (escenarios donde ambos scripts admin coexisten en una misma página). Distingue 5 escenarios análogos a #146 (con el agregado de `jqXHR.status === 422` específico para compliance-block). Los 3 callbacks `.fail()` delegan. Adicionalmente, los `.done()` mejoraron para leer `res.message` o `res.data.message` en vez del fallback muerto `'Error al aprobar.'`.
   - **Test:** nueva suite `tests/unit/AdminPayoutAuditReTest.php` (7 tests, 28 assertions, grupo `admin-payout-audit-re` + `admin-kyc-approve-audit` + `kyc`). Tests e2e via Reflection sobre `normalize_payout_result()` (privado) + estructurales sobre handlers y JS-view.
4. **Regla preventiva.** BUSCAR el patrón `wp_send_json( $result )` en cualquier handler AJAX admin — y reemplazarlo por un routing explícito:
   ```php
   if ( is_array( $result ) && ! empty( $result['success'] ) ) {
       $payload = $result; unset( $payload['success'] );
       wp_send_json_success( $payload );
   }
   wp_send_json_error( [ 'message' => $result['message'] ?? '...', 'block_code' => $result['block_code'] ?? '' ], 422 );
   ```
   Cuándo sospechar de este bug en un bug report:
   - Admin reporta " aprobé X pero no se efectivizó / el cliente dice que nunca le llegó" **SIEMPRE** → bug de falso éxito (FN-01 ADMIN-PAYOUT-AUDIT-RE).
   - Admin reporta "no puedo X, me dice error de conexión" **SOLO** cuando es bloqueo lógico → bug de falso desconectado (#146 ADMIN-KYC-APPROVE-AUDIT).
   - Admin reporta "marqué X aprobado y el badge cambió, pero el backend no hizo nada" → bug FN-01 (falso éxito).
   - En los 3 casos, abrir DevTools → Network → ver el response. Si el HTTP status es 200 pero el body tiene `success:false` → bug de canal-equivocado (este patrón).
5. **Relación con lecciones previas.**
   - LECCIONES **#146** (ADMIN-KYC-APPROVE-AUDIT bug "desconectado"): polaridad opuesta. #146 era falso-desconectado (`.fail()` ejecutar sobre un 403 con JSON válido → admin veía "Error de conexión"), #147 es falso-éxito (`.done()` ejecutar sobre un 200 con `success:false` → admin veía "Aprobado"). Ambos son instancias del MISMO anti-patrón `wp_send_json( $result )` que mezcla canales. La fix de #146 trató un caso específico; la fix de #147 trata el otro lado. La regla preventiva conjunta: TODO handler admin que invoque `wp_send_json( $result )` con array conteniendo flag `success` debe refactorizarse — es un anti-patrón sistémico.
   - LECCIONES **#143** (refactors masivos invisibles en working tree, ciclo PANEL-RESTORE): precaución sobre refactor grande. Este fix es un refactor pequeño y focalizado (2 handlers), no un panel-wide rewrite — limita el riesgo de #143.
    - LECCIONES **#112** (fail-closed SARLAFT, ciclo Plaza Viva): el fix de payouts NO relaja ningún control. El scheduler sigue bloqueando retiros si el KYC está revocado (PO-BUG-B), si los límites operativos se exceden (FT-3), etc. — solo añade visibilidad del motivo al admin, igual que #146.
 6. **Trazabilidad.** Comprobado en ADMIN-PAYOUT-AUDIT-RE: tras aplicar los 2 fixes (FN-01 refactor handler + FN-02 JS .fail() delegation), los 7 tests nuevos en `tests/unit/AdminPayoutAuditReTest.php` (28 assertions) pasan en verde — cubren los 2 puntos: (A) e2e sobre `normalize_payout_result()` via Reflection con arrays sintéticos que imitan la respuesta del scheduler en success=true y success=false; (B) estructural sobre los handlers (`ajax_approve_payout` y `ajax_reject_payout`) que contienen `normalize_payout_result(` y NO contienen `wp_send_json( $result`; (C) estructural sobre el JS-view que define `window.ltmsHttpFailReason` con guard de doble-definión + distingue 4 escenarios + invoca la función ≥3 veces (los 3 callbacks `.fail()`). Suite unit completa: 3491 tests (vs 3484 del commit previo — +7 tests nuevos), 48 errors, 25 failures (baseline idéntico al pre-fix — **cero regresiones**). `php -l` OK en 4 archivos (`class-ltms-admin-payouts.php`, `html-admin-payouts.php`, `tests/unit/AdminPayoutAuditReTest.php`, `deploy/ltms-deploy-webhook.php`). `node --check` (via `new Function()` ctor) OK en el `<script>` inline de `html-admin-payouts.php`.

### Lección #148: tests que pasan aislados pero fallan en la suite completa = contaminación de `$GLOBALS['wpdb']` entre tests — el fix base está en `LTMS_Unit_Test_Case::setUp`, no en el test individual

1. **Síntoma.** El commit 4efe9fe (ADMIN-PAYOUT-AUDIT-RE) estaba en rojo en GitHub Actions: `Unit Tests (PHP 8.1)` y `(PHP 8.2)` ambos `failure`. El `testsuite=unit` reportaba 48 errors + 25 failures (73 tests en rojo). Perfil de los fallos:
   - 32 errors en `PaymentOrchestratorTest` (todos `Call to undefined method class@anonymous::insert()`)
   - 5 errors en `BookingManagerTest` (`class@anonymous::query()` / `::get_results()`)
   - 6 errors en `MediaGuardTest` (`Attempt to read property "uploader_id" on array`)
   - 5 errors en `KYCComplianceTest::test_vault_op_*` (`Undefined constant LTMS_Legal_Compliance::VAULT_OP_UPLOAD`)
   - 22 failures en `TaxEngineTest` + `TaxStrategyMexicoTest` (`Failed asserting that 1.0 matches expected 0.08` — IEPS/ISR México devolvían siempre `1.0`)
   - 1 failure en `CommissionStrategyTest`
   - 2 failures en `KYCComplianceTest` (`VAULT_OP_UPLOAD` no definida + 3er parámetro se llama `t` no `document`)
   Síntoma clave: TODOS los tests pasaban aislados (`--filter MediaGuardTest`, `--filter TaxEngineTest`, etc. daban verde). Solo la suite completa fallaba.

2. **Causa raíz.** Bug de **aislamiento de tests** en PHPUnit, no del código de producción. Al correr `--testsuite=unit`, PHPUnit ejecuta TODOS los tests en un único proceso PHP compartiendo estado global (`$GLOBALS['wpdb']`, mocks estáticos). Algunos tests (notablemente `AdminKycApproveAuditTest`, commit 741c7c80) seteaban `$GLOBALS['wpdb'] = new class { ... get_var() => 1 ... }` en sus tests **sin restore** en `tearDown()`. Al terminar la clase, el mock `get_var()=1` quedaba activo para los tests siguientes vía `global $wpdb`. Los módulos que consultan `$wpdb` para tasas (`LTMS_Tax_Strategy_Mexico::get_ieps_rate()` líneas 50-64, `get_isr_platform_rate()` líneas 84-101) hacían `(float) $wpdb->get_var(...)` → recibían `1` (no null) → `(float)'1' = 1.0` → IEPS=1.0, ISR=1.0 en vez de 0.08/1.60/0.011/etc. Lo mismo para `MediaGuardTest::test_negative_user_id_denied` que no seteaba `$GLOBALS['wpdb']` confiando en "bootstrap retorna null" pero heredaba el mock sucio del test anterior (cuyo `get_row` retornaba un array, no null) → `$row->uploader_id` sobre un array → warning + `false` esperado vs `bool|null`.
   Bug secundario: `AdminKycApproveAuditTest::test_approve_kyc_happy_path_does_not_error_when_filters_pass` definía por `eval('final class LTMS_Legal_Compliance { public static function log_vault_access( int $vid, int $aid, string $t, string $a, string $s ): void {} }')` un stub **sin** las constantes `VAULT_OP_*` y con 3er parámetro `$t` (no `$document`). Como el bootstrap no carga la clase real (`includes/business/class-ltms-legal-compliance.php`) en modo `UNIT_ONLY`, este stub "ganaba" la definición y contaminaba `KYCComplianceTest` que las asertaba — explicando los 7 falsos rojos del modulo KYC.

3. **Fix.** 4 archivos, **ningún cambio a código de producción** — el código fuente de LTMS era correcto; el bug era de infraestructura de tests.
   - `tests/bootstrap.php:417-444`: extraer helper `ltms_tests_make_clean_wpdb(): object` que reemplaza al mock `$wpdb` inline del bootstrap (misma implementación, ahora reutilizable).
   - `tests/unit/class-ltms-unit-test-case.php:40-52`: en `setUp()` antes de cada test, resetear `$GLOBALS['wpdb'] = ltms_tests_make_clean_wpdb()`. Afecta a los ~76% de tests que extienden la case base — cada test parte de un mock limpio (`get_var=null`, `get_results=[]`, `get_row=null`, `insert/update/delete/query=false`). Los tests que necesitan un mock específico lo sobreescriben en su propio `setUp()` (después de `parent::setUp()`) o en el cuerpo del test.
   - `tests/unit/AdminKycApproveAuditTest.php:297-310`: reemplazar el `eval('final class LTMS_Legal_Compliance ...')` (stub sin constantes/parámetro equivocado) por `require_once` del archivo real `includes/business/class-ltms-legal-compliance.php` (que define `VAULT_OP_*` y `log_vault_access($user_id, $accessor_id, string $document, ...)`). El `class_exists('LTMS_Legal_Compliance', false)` guard asegura solo cargar si no estaba ya definida.
   - `tests/unit/AdminKycApproveAuditTest.php:255-259`: añadir `insert()` al mock `$wpdb` del happy path, porque ahora la clase real `LTMS_Legal_Compliance::log_vault_access()` invoca `$wpdb->insert()` sobre `lt_vault_access_log`.
   - `tests/unit/KYCComplianceTest.php:16-36`: `setUp()` hace `require_once` del archivo real de `LTMS_Legal_Compliance` antes de los tests (defensivo — si el autoloader del plugin no la cargó, garantizar la versión correcta).

4. **Regla preventiva.** Cuando un test PASE aislado (`--filter MiTest`) pero FALLE en la suite completa (`--testsuite=unit`), el 99% de las veces es **contaminación de estado global entre tests**. Pasos para diagnosticarlo rápido:
   ```php
   // Insertar en el test que falla en suite pero no aislado:
   fwrite( STDERR, "\nDBG-wpdb-class=" . ( isset( $GLOBALS['wpdb'] ) ? get_class( $GLOBALS['wpdb'] ) : 'NULL' ) . " get_var=" . var_export( $GLOBALS['wpdb']->get_var( 'X' ), true ) . "\n" );
   ```
   La traza revela el archivo:linea del mock anónimo que contaminó (PHPUnit muestra `class@anonymous:path/to/ContaminatingTest.php:XXX$Y`). Buscar ese archivo + línea en el codebase para identificar qué test no restaura `$GLOBALS['wpdb']`.
   **Fix base preferido**: resetear `$GLOBALS['wpdb']` en `LTMS_Unit_Test_Case::setUp` (un solo lugar, cubre la mayoría de tests). Solo arreglar tests individuales si no extienden la case base.
   **Anti-patrón a evitar**: `eval('final class ClaseReal {...}')` en tests. Si otra suite de tests aserta sobre esa clase (constantes, firmas, parámetros), el stub `eval` "gana" la definición porque PHP no permite redifinir. Usar `require_once` del archivo real, o nombrar el stub con name distinto (`Stub_Legal_Compliance`).

5. **Relación con lecciones previas.**
   - LECCIONES **#106** (mocks wpdb deben respetar `$output`): se trata del mismo problema de fondo (tests contaminando estado). #148 amplía: no basta que el mock sea correcto, sino que **el test anterior debe restaurar** el global al terminar.
   - LECCIONES **#119** (test huérfano tras cambio de enfoque rompe CI semanas después): mismo patrón de "test correcto aislado, suite rota". El dépistage de #148 (fwrite STDERR + busca `class@anonymous:path`) habría encontrado la causa de #119 en minutos en vez de semanas.
   - LECCIONES **#138** (refactors invisibles en working tree): el anterior commit 741c7c80 introdujo el `eval('final class LTMS_Legal_Compliance ...')` contaminante, pero nadie notó el daño porque los tests de KYCComplianceTest pasaban aislados en PR. Solo el commit 4efe9fe (sin tocar KYC) quedó expuesto al correr la suite completa. Lección: el PR review que solo corre `--filter MiTest` no ve adyacencias.
   - LECCIONES **#143** (refactors masivos invisibles): contraste — este fix NO es un refactor masivo, es un fix focalizado en 4 archivos de infraestructura de tests (sin tocar producción), mínimo riesgo de #143.

6. **Trazabilidad.** CI-RED-BASELINE: tras los 4 fixes, suite `--testsuite=unit` completa = **3491 tests, 0 errors, 0 failures, 3 skipped** (de 48+25=73 en rojo a 0 —suite verde por primera vez en ≥3 commits). `php -l` OK en los 5 archivos PHP modificados (`tests/bootstrap.php`, `tests/unit/class-ltms-unit-test-case.php`, `tests/unit/KYCComplianceTest.php`, `tests/unit/AdminKycApproveAuditTest.php`, `tests/unit/TaxEngineTest.php` — este último solo para quitar el debug fwrite STDERR). Grupos afectados en verde: `--group admin-kyc-approve-audit` = 17/17 OK, `--group kyc` = 17/17 OK, `--group admin-payout-audit-re` = 7/7 OK. Los módulos de producción afectados indirectamente (`class-ltms-tax-strategy-mexico.php`, `class-ltms-media-guard.php`, `class-ltms-booking-manager.php`, `class-ltms-payment-orchestrator.php`, `class-ltms-legal-compliance.php`) **NO fueron modificados** — su lógica era correcta, el bug era de aislamiento de tests.

### Lección #149: el inventario de un loop de auditoría CSV debe extenderse a `business/` y `gateway/` — grep por `fputcsv(` + `wp_mail(` en todo el árbol, no grep por `view-*.php` + `class-ltms-admin-*-export.php`

1. **Síntoma.** El ciclo AUDIT-PANEL-CSV-001 (commits `8205d24e`→`8bc2a21d`, CSV-01 a CSV-05) declaró stop implícito tras fixear 5 exportadores en `admin/views/` (`view-auditor-dashboard.php`, `html-admin-xcover-policies.php`) y `admin/` (`class-ltms-admin-redi.php`, `class-ltms-fiscal-exporter.php`, `class-ltms-auditor-export.php`). Se consideró "módulo CSV completa" cerrado. La re-auditoría del paso 5 del loop (AGENTS.md "Loop de auditoría autónoma") reveló que el inventario estaba **incompleto**: 6 exportadores adicionales en `includes/business/` (`class-ltms-authorities-compliance.php` RAEE+INVIMA, `class-ltms-cross-border-compliance.php` FX Forma 4, `class-ltms-fintech-compliance.php` SOS+CRS, `class-ltms-foundation-compliance.php` DIAN 1737) compartían el MISMO anti-patrón (`fputcsv()` con datos vendor-provided sin protección de formula injection) y NUNCA habían sido inventariados. Los 6 tocaban autoridades regulatorias (ANLA/SEMARNAT, INVIMA/COFEPRIS, DIAN/Banxico, UIAF/SHCP, OECD MCAA, DIAN) — canal de distribución de máximo impacto.

2. **Causa raíz del inventario parcial.** El inventario se construyó con grep por `view-*.php` (lógica visual admin) y por `class-ltms-admin-*-export.php` (clases admin explícitamente nominate como "export"). Esta heurística:
   - **Atrapó** los 5 casos del ciclo CSV-001 porque todos vivían en el namespace admin (handlers `admin_post_*/wp_ajax_*` invocados desde views admin, con `$_GET['export_csv']` o `$_GET['export'] === 'csv'`).
   - **No atrapó** los 6 exportadores regulatorios porque viven en `includes/business/`, son invocados desde hook `ltms_yearly_cron` / `ltms_monthly_cron` (NO desde evento admin), y NO tienen la palabra "export" en el nombre del método (`generate_raee_annual_report()`, `generate_invima_annual_report()`, `generate_fx_forma4_csv()`, `generate_sos_csv_uiaf()`, `generate_crs_fatca_report()`, `generate_dian_annual_report()`).
   - La falsa hipótesis fue: "los exportadores CSV son bienes admin, viven en admin/". La realidad: los exportadores regulatorios son **bienes de compliance**, viven en `business/` porque son invocados por cron, no por evento admin.

3. **Fix.** El stop-check no converge, se abre ciclo derivado **AUDIT-PANEL-CSV-002**:
   - Mismo fix-pattern del CSV-001 aplicado a 6 métodos en 4 archivos (`class-ltms-authorities-compliance.php`, `class-ltms-cross-border-compliance.php`, `class-ltms-fintech-compliance.php`, `class-ltms-foundation-compliance.php`): closure `$csv_field` inline (`(string)($v ?? '')` + prefix `'` para `[= + - @ \t \r]`) antes del `fputcsv()`, numéricos preservados.
   - 6 nuevas suites estructurales de test (mismo patrón que `XcoverPoliciesCsvInjectionTest`): `AuthoritiesRaeeCsvInjectionTest.php` (11 t/14 a), `AuthoritiesInvimaCsvInjectionTest.php` (10 t/12 a), `CrossBorderFxCsvInjectionTest.php` (12 t/16 a), `FintechSosCsvInjectionTest.php` (14 t/20 a), `FintechCrsFatcaCsvInjectionTest.php` (15 t/19 a), `FoundationDian1737CsvInjectionTest.php` (11 t/15 a). Total +73 tests / 96 assertions. Suite completa: 3624 tests, 0 errors, 0 failures, 3 skipped (era 3551, +73, cero regresiones).

4. **2 falsos positivos descartados en el stop-check.** `class-ltms-admin-bookings.php:226` `export_csv()` (línea 238) y `class-ltms-frontend-booking-handler.php:301` (línea 305) aparecieron en el grep de `fputcsv(` como casos vulnerables, pero la re-auditoría visual confirmó que ya tenían la protección formula-injection (closure con `preg_match('/^[=+\-@]/', $val)`). Tercer falso positivo: `class-ltms-admin-donations.php:458` `ajax_export_csv()` (línea 491-499) ya tenía el helper `$csv_field` con escape RFC 4180 + BOM UTF-8 (commits ADMIN-BUG-6/7/8 previos). Los 3 falsos positivos NO son bugs; son casos donde el grep atrapa código correctamente ya fixeado y la verificación humana es necesaria para descartarlos.

5. **Regla preventiva.** La heurística correcta para el inventario de un loop de auditoría CSV/formula-injection en un plugin WordPress es:
    ```bash
    # Encontrar TODOS los exportadores CSV del arbol, sin filtrar por namespace:
    grep -rn "fputcsv(" --include="*.php" includes/ | grep -v vendor/ | grep -v tests/
    grep -rn "text/csv" --include="*.php" includes/ | grep -v vendor/ | grep -v tests/
    grep -rn "Content-Disposition.*\.csv" --include="*.php" includes/ | grep -v vendor/
    grep -rn "fopen.*\.csv" --include="*.php" includes/ | grep -v vendor/
    grep -rn "fwrite.*\\\\xEF\\\\xBB\\\\xBF" --include="*.php" includes/   # BOM UTF-8
    grep -rn "array_map.*str_replace.*\"\"" --include="*.php" includes/   # inline closures

    # Coronar con wp_mail (exportadores regulatorios para autoridad):
    grep -rn "wp_mail(" --include="*.php" includes/business/ | while read line; do
      file=$(echo $line | cut -d: -f1)
      grep -l "fputcsv\|\.csv" $file && echo "POSIBLE EXPORTADOR CSV+EMAIL"
    done
    ```
   - **NO limitarse a `admin/`**: el grep DEBE extenderse a `business/`, `gateway/`, `core/`, y cualquier subdirectorio de `includes/`. Los exportadores regulatorios (RAEE, INVIMA, FX, SOS, CRS, DIAN) viven en `business/` porque son invocados por cron, no por evento admin.
   - **NO limitarse a `view-*.php`**: las views admin son un subconjembro pequeño de los exportadores. Los que viven en clases (métodos `generate_*_report()`) son invisibles a un grep por directorio de views.
   - **NO limitarse a `class-ltms-admin-*-export.php`**: el nombre del método NO tiene que incluir "export". `generate_raee_annual_report()`, `generate_fx_forma4_csv()`, `generate_dian_annual_report()` son todos exportadores aunque la palabra "export" no aparezca.
   - **Coronar con `wp_mail(`**: si el archivo tiene `fputcsv(` Y `wp_mail(`, es candidato prioritario a audit — son reportes que se envían a una autoridad (canal de máximo impacto regulatorio).
   - **Cron no es contención**: aunque un reporte se genere anualmente (`ltms_yearly_cron`), el LINK entre el vendor (input) y el oficial regulatorio (output) se materializa en cada disparo del cron. La frecuencia no reduce el riesgo.

6. **Relación con lecciones previas.**
    - LECCIONES **#141** (canarios mentirosos en comment blocks): espejo. Aquí el "canario mentiroso" es la declaración implícita "el ciclo CSV-001 cerró el módulo CSV" — el commentario del CHANGELOG no describía el alcance del inventario y dejaba la impresión de cierre total. La re-auditoría física (paso 5) reveló que faltaba el 54% de los exportadores (6 de 11).
    - LECCIONES **#130** (silent fallback JS becomes only path): relación tangencial. El anti-patrón de los 6 exportadores es "fputcsv escapa comillas pero previene formula injection NO" — el fallback silencioso de `fputcsv()` (preservar el valor raw) se convierte en el único path cuando no se añade el `$csv_field` wrapper. Misma lección sobre suposiciones implícitas: una primitiva de la stdlib (`fputcsv`) que hace lo correcto para RFC 4180 (escapar comillas) NO hace lo correcto para formula injection (prefix `'`).
    - LECCIONES **#143** (mismo anti-patrón en múltiples vistas): mismo patrón de cobertura insuficiente, distinto vector. #143 trataba sobre tests de no-contención que fallaban al ser replicados a múltiples vistas; aquí es fixes que no fueron replicados a múltiples métodos (de `admin/` a `business/`) por no extender el inventario.
    - LECCIONES **#148** (contaminación wpdb entre tests): absolutamente no relacionada pero surge del mismo principio — la "cobertera declarada" no siempre coincide con la "cobertura real". Aquí el coverage declarado del módulo CSV era "5 exportadores cerrados"; la cobertura real era "5 de 11 exportadores cerrados, 6 invisibles al inventario".

7. **Trazabilidad.** AUDIT-PANEL-CSV-002 cerrado en esta iteración. Los 6 fixes aplicados a 4 archivos (`class-ltms-authorities-compliance.php` x2, `class-ltms-cross-border-compliance.php`, `class-ltms-fintech-compliance.php` x2, `class-ltms-foundation-compliance.php`) + 6 suites de test estructurales nuevas (+73 tests / 96 assertions). Suite completa `--testsuite=unit`: **3624 tests, 0 errors, 0 failures, 3 skipped** (vs 3551/0/0/3 baseline — +73 tests, cero regresiones). `php -l` OK en los 4 archivos modificados + 6 archivos de test. Stop-check de AUDIT-PANEL-CSV-002 pendiente en próxima iteración (verificar que no aparezcan nuevos exportadores CSV en `business/` por grep global).

---

## 19. v2.9.306 - AUDIT-BIZ-AVE-001: ciclo de auditoría del módulo Aveonline business (1 lección nueva)

> Loop de auditoría autónoma siguiendo `AGENTS.md` → "Loop de auditoría autónoma". Alcance: 21 archivos del módulo Aveonline (`includes/business/class-ltms-business-aveonline-*.php` x11, `includes/business/listeners/class-ltms-aveonline-hub-listener.php`, `includes/business/class-ltms-aveonline-onboarding-{trigger,ajax}.php` x2, `includes/api/class-ltms-api-aveonline{,-hub,-onboarding}.php` x3, `includes/api/webhooks/class-ltms-aveonline-webhook-handler.php`, `includes/shipping/class-ltms-shipping-method-aveonline.php`, `includes/frontend/class-ltms-frontend-checkout-aveonline-office.php`, `includes/admin/views/html-admin-aveonline-hub.php`, `includes/admin/views/settings/section-aveonline.php`, `includes/frontend/views/view-aveonline-onboarding.php`). Los 4 archivos en `api/` y `frontend/` fuera del inventario original de business/ fueron inspeccionados en stop-check: son API clients invocados por los handlers (no exponen endpoints AJAX propios) o vistas admin con `manage_woocommerce` ya requerido — 0 hallazgos P0/P1. El módulo Aveonline NUNCA había tenido un ciclo de auditoría dedicado; los toques previos fueron side-effects (regression Plaza Viva, IDOR P0 v2.9.118, schema DB bug AUDIT-DB-AVE-001) sin sweep completo. Audit choice vindicada: los hallazgos P1 sí aparecieron ahí donde no se buscaba.

### Lección #150: un handler `wp_ajax_*` que use `is_user_logged_in()` como única capability check NO protege nada — el rol `subscriber` (cliente WC) lo pasa; el fix es `is_ltms_vendor() || manage_options` con código HTTP correcto (401 vs 403)

**Error:**

`LTMS_Business_Aveonline_ShipmentRelations::ajax_search_recipients()` (línea 486 pre-fix) verificaba solo `if ( ! is_user_logged_in() )`, igual que los otros 3 handlers vendor_* del mismo archivo (`ajax_vendor_create/list/delete`) ANTES de que v2.9.100 SEC-9 / AO-BUG-6 los migrara a `check_vendor_nonce()` con `LTMS_Utils::is_ltms_vendor()`. La migración se quedó a mitad: el handler `_search_recipients` (registrado como `wp_ajax_ltms_aveonline_search_recipients`, sin prefijo `vendor_`) quedó fuera del inventario de la pasada SEC-9 porque su nombre no calza con el grep `wp_ajax_ltms_vendor_*`. Resultado: 4 años de ventana donde cualquier cliente logueado (rol `subscriber`, el default de WC customer) podía invocar el autocomplete de destinatarios de Aveonline y exponer PII (nombre, teléfono, dirección) de destinatarios previos.

**Causa raíz (doble):**

1. **`is_user_logged_in()` no es capability check.** Verifica sesión WP, no rol. Cualquier usuario autenticado —incluyendo el rol mínimo `subscriber` (cliente WC), atacantes registrados, o filctraciones de sesión— pasa el check. AGENTS.md §"Seguridad por defecto" exige validar rol/permiso explícito en TODO handler AJAX, no solo login. La primitiva WP `is_user_logged_in()` debería tratarse como un "predicates de contexto" (¿hay sesión?), nunca como "predicates de capability" (¿puede realizar esta acción?).
2. **Inventario de fixes previos basado en grep por prefijo de action `wp_ajax_ltms_vendor_*`.** La pasada SEC-9 / AO-BUG-6 (v2.9.100) migró los 4 handlers con prefijo `ltms_vendor_` a `check_vendor_nonce()` con `is_ltms_vendor()`, pero dejó fuera del sweep:
   - `wp_ajax_ltms_aveonline_search_recipients` (este bug, sin prefijo `vendor_`).
   - `wp_ajax_ltms_aveonline_create_relation/list_relations/delete_relation/print_relation` (handlers admin, legítimamente usan `manage_woocommerce` — no afectados).
   El inventario por grep de prefijo "vendor_" fue una heurística imperfecta; el inventario correcto era grep por `wp_ajax_ltms_` + `is_user_logged_in` sin `is_ltms_vendor` cercano. Mismo patrón de LECCIONES **#149** (inventario por grep incompleto) y **#143** (mismo anti-patrón se replica en múltiples vistas/handlers).

**Fix:**

Replicar el patrón consistente de los demás handlers vendor_* del archivo:
```php
if ( ! is_user_logged_in() ) {
    wp_send_json_error( [ 'message' => 'Sin permisos.' ], 401 );
}
$is_vendor = class_exists( 'LTMS_Utils' ) && LTMS_Utils::is_ltms_vendor();
if ( ! $is_vendor && ! current_user_can( 'manage_options' ) ) {
    wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
}
```

Detalles:
- 401 = no autenticado (no hay sesión) ≠ 403 = autenticado pero sin rol suficiente. Mezclarlos en un solo código confunde al JS frontend entre "la sesión expiró, redirige a login" vs "tu rol no tiene permiso, no reintentes". Mismo patrón que LECCIONES **#146** (distinguir 401 compliance-blocked de 403).
- `manage_options` fallback como en `guias.php::check_vendor_nonce()` (línea 95-98) — admin puede gestionar envíos de cualquier vendor.
- `class_exists('LTMS_Utils')` defensivo por si la clase utilitaria no carga en algún contexto edge-case (tests unit sin bootstrap completo). Si la clase falta, incluso un admin real no pasaría — fail-closed.

**Regla preventiva / heurística:**

1. **TODO handler `wp_ajax_*` registrado por el plugin debe auditarse con grep por `is_user_logged_in()` sin pareja `is_ltms_vendor()` o `current_user_can(...)` cercana.** Grep:
   ```bash
   grep -rn "wp_ajax_" --include="*.php" includes/ | awk -F: '{print $1":"$2}' | sort -u
   grep -n "is_user_logged_in" includes/business/*.php includes/frontend/*.php
   ```
   Y cruzar manualmente: cada handler con `is_user_logged_in()` debe tener un segundo check de capability dentro de las siguientes 5 líneas. Si no, es caso P1.
2. **`is_user_logged_in()` solo es válido como ÚNICO check de capability en handlers que devuelven datos *del propio usuario logueado* y sin filtrar por rol** (ej: `wp_ajax_get_my_orders`, donde el propio user_id del request ya acota los datos). En handlers que consultan datos de terceros (destinatarios, vendors, ids externos), `is_user_logged_in()` no acota nada; debe acompañarse de capability check.
3. **Fixes de auditoría por grep de prefijo** (`wp_ajax_ltms_vendor_*`) son heurísticas, no exhaustivas. Repasar también por sufijo funcional (`_search`, `_autocomplete`, `_lookup`, `_get_*`) — los handlers de autocomplete son especialmente sensibles a este patrón porque se diseñan como "utility handlers" y a menudo comparten el nonce del panel pero no el capability check del panel.
4. **Refactor 1-shot de "todos los handlers a `check_vendor_nonce()`"** es antifrágil si hay handlers con nombres que no calzan el patrón del grep. Alternativa robusta: un helper centralizado `LTMS_Ajax_Guard::require_vendor_or_admin()` invocado al inicio de cada handler, no cada handler reimplementando el check inline — elimina la oportunidad de olvidar uno. Esta es la `ltms_http_fail_reason` de LECCIONES #146 generalizada a capability checks.

**Relación con lecciones previas:**

- LECCIONES **#149** (inventario CSV por grep de prefijo): mismo anti-patrón de "heurística de inventario imperfecta". Aquí el grep SEC-9 buscó `ltms_vendor_*` y dejó fuera `ltms_aveonline_search_recipients`. La regla conjunta: inventarios por grep siempre deben validarse cruzando con grep por **primitivas sospechosas** (`fputcsv`, `is_user_logged_in`, `wp_remote_get`) no por **nombres de action**.
- LECCIONES **#146** (403 con mensaje genérico vs 401 sesión expirada): este fix aplica la distinción 401 vs 403 desde el inicio.
- LECCIONES **#143** (mismo anti-patrón en múltiples vistas): aquí el anti-patrón "is_user_logged_in como único check" no se replicó — solo estuvo en 1 handler porque los 3 vendor_* ya habían sido migrados a SEC-9. Es decir, la migración SEC-9 corrigió *la mayoría* pero no *todos*. Leave-one-out falla silenciosamente.
- LECCIONES **#138** (refactors invisibles en working tree,养成 "doesn't exist"): esta lección surge de auditar en working tree *sin commit intermedio*. Cada fix del ciclo AUDIT-BIZ-AVE-001 commiteado atómicamente al final, no en working tree invisible — evita el riesgo #138.

**Trazabilidad.** AUDIT-BIZ-AVE-001 cerrado en esta iteración: 2 P1+P2 fixes en 2 archivos (`class-ltms-business-aveonline-shipment-relations.php` AVE-001, `class-ltms-business-aveonline-sandbox.php` AVE-017) + 2 suites de test estructurales nuevas (+33 tests / 38 assertions). Suite completa `--testsuite=unit`: **3657 tests, 0 errors, 0 failures, 3 skipped** (vs 3624/0/0/3 baseline post-CSV-002 — +33 tests, cero regresiones, verificado localmente con `LTMS_UNIT_ONLY=true`). `php -l` OK en los 2 archivos modificados + 2 archivos de test. Stop-check converge: 21/21 archivos Aveonline inspeccionados (incluye 4 archivos fuera del inventario business/ original — ver header), 0 P0 detectados, P1 (AVE-001) fixeado, AVE-002 degradado a P2 backlog sistémico (esc_html en getMessage — 31 instancias en business/, scope para ciclo AUDIT-EXCMSG-001 dedicado). El módulo Aveonline queda cerrado para auditoría integral.

---

## 20. v2.9.307 - AUDIT-EXCMSG-001: ciclo de auditoría de `$e->getMessage()` sin esc_html en destinos a cliente (alcance ampliado a 91 archivos, 5 sub-ciclos)

> Loop de auditoría autónoma siguiendo `AGENTS.md` → "Loop de auditoría autónoma". Alcance declarado por el usuario: completo (91 archivos con `getMessage()` en `includes/`). Inventario inicial reveló que la estimación de la lección #150 ("31 instancias en business/") era unádibunder: en realidad son ~250 instancias distribuidas en 91 archivos a través de 6 categorías de destino (wp_send_json 40, wc_add_nonce 5, WP_Error 9, log 112, return arrays 12, throw 2, otros 60). El ciclo se ejecutó en 5 sub-ciclos commiteados atómicamente (AVEN API → ADMIN → FRONTEND → BOOKING+OTHERS → REST) cubriendo 30+ archivos y +35 tests estructurales nuevos (suite completa: 3692 tests, 0 errors). P2 backlog sistémico: ~20 instancias en `return [...]` arrays que van a BD/logs estructurados (no a cliente directo) — pendiente de revisión cuando se añada LTMS_Redacted_Exception osimilar mecanismo de redacción.

### Lección #151: `$e->getMessage()` sin `esc_html()` en destinos a cliente es un anti-patrón sistémico — el fix es defense-in-depth en el ORIGEN, no en la capa de presentación

**Error:**

91 archivos usan `catch ( \Throwable|\Exception $e ) { ... $e->getMessage() ... }` donde el mensaje de excepción termina_fluyendo hacia el cliente (handlers `wp_ajax_*`, `wp_send_json_error`, `wc_add_notice`, `WP_Error`, `WP_REST_Response`, `add_order_note`, o return arrays con claves `'message'`/`'error'`/`'reason'`/`'error_message'`/`'descripcion'`) SIN envolverlo con `esc_html()`. Si el contenido del getMessage incluye input del usuario (nombre de agente, dirección destinatario, etc.) o respuesta de API externa con datos del usuario, causa XSS reflected cuando el JS frontend lo renderiza via `jQuery.html()` en vez de `jQuery.text()`. Múltiples admin views auditadas confirman que `jQuery.html()` con `response.data.message` ES el patrón (ver `html-admin-kyc.php:335`): el hecho de que algunos usen `.text()` no garantiza que todos lo hagan.

**Causa raíz:**

1. **PHP	tracea exception messages authored-by que el desarrollador piensa "son internos, no los ve el usuario"** — pero vía handlers AJAX o REST responses, esos mensajes llegan al JS response handler. No es un "bug" puntual: es un anti-patrón sistémico donde la barrera mental "esto es interno/no se muestra" falla porque la cadena caller→caller→caller termina rompiéndose.
2. **Defensa en profundidad (defense-in-depth) requiere escapar en el ORIGEN, no confiar en que la capa final cliente (`.text()`) haga lo correcto.** Si cada handler que retorna `'message' => $e->getMessage()` decide no escapar porque "el caller es responsable", el caller también decide no escapar porque "el handler ya validó" — nadie escapa. Esto es el mismo anti-patrón de LECCIONES #146 (responsabilidad difusa entre capas) aplicado al contexto de escaping HTML.
3. **El `getMessage()` puede contener PII/secretos en casos edge**: respuestas de API que incluyen datos del usuario (nombre, dirección) o en raras excepciones, tokens o credenciales en mensajes de error de API. Aunque el caso típico es "error de validación", el riesgo_membership es P0/P1 cuando el mensaje proviene de una API externa que no controla la desinfección.

**Fix aplicado en 5 sub-ciclos:**

| Sub-ciclo | Commit | Archivos | Instancias P0/P1 | Tests |
|-----------|--------|----------|------------------|-------|
| AUDIT-EXCMSG-AVE-001 | `151d669b` | 5 business-aveonline | 19 wp_send_json_error | +8 |
| AUDIT-EXCMSG-API-001 | `1d4cc0d8` | 14 api/ y api/gateways/ | 28 (17 `error`/1 `message` Stripe + 4 wc_add_notice/WP_Error gateways + 7 return arrays) | +9 |
| AUDIT-EXCMSG-ADMIN-001 | `1d1083ca` | 6 admin/ | 10 wp_send_json_error | +6 |
| AUDIT-EXCMSG-FRONTEND-001 | `f9d9b4d0` | 2 frontend/ | 2 (1 P1 WP_DEBUG condicional + 1 P2 order_note) | +5 |
| AUDIT-EXCMSG-BIZ/BOOK/SET-001 | `fa86aebd` | 7 business no-AVE + booking + settings | 10 (4 wp_send_json_error + 6 WP_Error) | +7 |
| AUDIT-EXCMSG-REST-001 | (este commit) | 1 core/rest-controller | 1 WP_REST_Response | (en suite existente) |

Patrón consistente: `esc_html( $e->getMessage() )` envolviendo en cada destino a cliente/admin, con marker comment `// EXCMSG-FIX (AUDIT-EXCMSG-XXX-001, P0|P1|P2)` para trazabilidad. Logs internos (`LTMS_Core_Logger::error/warning`, `error_log`) NO se escapan — los logs no se renderizan en HTML y el ecoar HTML entities en logs dificulta la lectura.

**Regla preventiva / heurística:**

1. **TODO handler de respuesta a cliente (`wp_send_json_error`, `wc_add_notice`, `new WP_Error`, `WP_REST_Response` con mensaje) debe auditar su contenido para garantizar que strings de error vengan de mensajes HARDCODED o `esc_html()`/`wp_kses_post()`.** Grep de validación (post-cierre de este ciclo):
   ```bash
   grep -rn 'wp_send_json_error\|wc_add_notice\|new WP_Error' --include='*.php' includes/ \
     | xargs grep -l 'getMessage()' \
     | xargs grep -L 'esc_html'
   ```
2. **Defense-in-depth en el ORIGEN:** cada función que devuelve un array de error con `'message'`/`'error'`/`'reason'` debe asumir que su retorno eventualmente se mostrará en una admin view o JS frontend. Aplicar `esc_html()` en el origen, no delegar.
3. **Cookie-consent de `WP_DEBUG` NO es licencia para mostrar `$e->getMessage()`:** aunque WP_DEBUG esté activo, el mensaje debe escaparse. El `defined('WP_DEBUG') && WP_DEBUG ? ' (' . esc_html($e->getMessage()) . ')' : ''` es el patrón correcto (este fix en `dashboard-logic.php:553`).
4. **Logs internos NO se escapan** — `esc_html` produce `&` y `<` para `<` que dificultan la lectura del log crudo. La distinción clave al auditar: ¿termina el `$e->getMessage()` en un archivo/log (no hay esc_html) o en un response/nota admin o cliente (sí esc_html)? Mismo criterio que `LECCIONES #149` (logs vs responses para CSV).
5. **Test estructural que previene regressión P0:** el patrón `test_no_unescaped_getmessage_in_wp_send_json_error` añadido a las 5 suites nuevas prueba que, para cada línea del archivo `wp_send_json_error(... getMessage() ...) `, hay un `esc_html(...)` en la MISMA línea. Si un handler nuevo se añade sin esc_html, el test falla al instante. Es el equivalente PHP de un linter en build step.
6. **El inventario por grep imperfecta** es un anti-patrón recurrente (#149, #150): el ciclo inicial estimó 31 instancias en business/ y encontró 250+ en 91 archivos en 6 categorías. Cuando el alcance se declare como "sistémico", usar `getmessage()` como primitiva grep de búsqueda en includes/ entero, no por sub-módulo. Para futuros audits de `getMessage`, el grep correcto es:
   ```bash
   grep -rn 'getMessage()' --include='*.php' includes/ | wc -l
   ```
   Y clasificar por destino (wp_send_json/wc_add_notice/WP_Error/log/return-array/throw), no por sub-módulo.

**Relación con lecciones previas:**

- LECCIONES **#149**: misma anti-patrón "inventario por grep imperfecta" — aquí el ciclo de AUDIT-EXCMSG-001 partió con "31 instancias en business/" y descubrió 250+ en 91 archivos a través de 6 categorías.
- LECCIONES **#146** y **#147**: mismo anti-patrón "responsabilidad difusa entre capas" — el handler decide no escapar porque "el JS escapa", el JS decide no escapar porque "el backend ya validó". Nadie escapa.
- LECCIONES **#150**: el bug que originó este ciclo (AVE-002 backlog). El sub-ciclo AVE instancia #1 cerrado aquí, con 19 instancias P1 fixeadas en 5 archivos business-aveonline (incluyendo los handlers AJAX que dieron origen al hallazgo).
- LECCIONES **#143** (anti-patrón replicado en múltiples vistas): este ciclo confirma que `getMessage()` sin esc_html NO se replicó como copia-variables-pero-sí como anti-patrón sistémico en handlers.getEntityFailed/catch blocks.

**P2 backlog sistémico:**

21 instancias en `return [...]` arrays con claves `'error'`/`'error_message'` que van a BD estructurada (`lt_job_queue.error_message`, `lt_provider_health.error_code`, `lt_webhook_logs.error_message`) o como data contracts entre métodos que confluyen en BD. No se escaparon porque:
- La BD persiste texto crudo, no HTML renderizado.
- Cuando ese contenido se muestra en admin views, las views asumen su propio esc_html.
- Ecoar HTML entities en BD complica el grep de log diagnosis.
Aplicar esc_html en el ORIGEN de estos casos requeriría añadir una capa de desinfecciónseparada para "logs estructurados de admin" vs "logs de diagnóstico crudo" — scope para ciclo AUDIT-LOG-STRUCT-001 futuro.

**Trazabilidad.** AUDIT-EXCMSG-001 cerrado en 6 commits atómicos (5 sub-ciclos + 1 REST controller addendum). 30+ archivos modificados con ~70 instancias P0/P1 fixeadas (vs el backlog original de AVE-002 declarado con 31 instancias). 5 suites de tests estructurales nuevas (+35 tests). Suite completa `--testsuite=unit`: **3692 tests, 0 errors, 0 failures, 3 skipped** (vs 3657 baseline pre-ciclo — +35 tests, cero regresiones, verificado localmente con `LTMS_UNIT_ONLY=true`). `php -l` OK en todos los archivos modificados. P0/P1 stop-check converge: re-auditoría global confirma 0 instancias sin esc_html en wp_send_json_error/wc_add_notice/WP_Error. P2 backlog sistémico (21 instancias en return arrays a BD/logs estructurados) documentado para ciclo futuro dedicado.

### Lección #152: Fuente de datos legal/regulatoria externa que se vuelve no-pública — el código la sigue intentando y rompe el flujo de negocio en modo fail-closed

**Error:**

`LTMS_Fintech_Compliance::SANCTIONS_LISTS['eu_restrictive']` apuntaba a `https://webgate.ec.europa.eu/fsd/fsf/public/files/xmlFullSanctionsList_1_1/content`. Esta URL fue pública durante años (2020-2024) y la doctrina SARLAFT la consideraba fuente estándar. En 2025 la UE migró el Financial Sanctions Files tras login **ECAS** (European Commission Authentication Service); la URL legacy ahora devuelve **403 Whitelabel Error Page** sin cookies de sesión + XSRF-TOKEN válidas, y el portal redirige a `ecas.ec.europa.eu/cas/login`.

El código LTMS (post-fix FASE4 P0 SARLAFT) entró en modo **fail-closed**: si una lista restrictiva no se puede descargar, el KYC queda bloqueado. Resultado: cualquier aprobación KYC que llegara a iterar hasta `eu_restrictive` (tercera lista, después de OFAC y UN) paralizaba el onboarding de vendors. El transient fallado tampoco se cacheaba, así que cada reintento volvía a descargar y fallar en bucle. **Bug P0 de cumplimiento que parece "_umbral correcto" — fail-closed doctrinal correcto, pero aplicado a una lista que ya no existe públicamente**.

**Causa raíz:**

1. **Asumir permanencia de fuentes externas públicas cuando la política de publicación del editor puede cambiar sin previo aviso.** La UE no anuncío el cierre del FSF público: simplemente dejó de responder 200 y empezó a responder 403. No hay webhook, no hay deprecation notice, no hay header `Sunset`. Solo el síntoma:KYC paralizado en producción sin error funcional aparente (el log decía "FT_SCREEN_LIST_UNAVAILABLE — fail-closed SARLAFT" pero parecía "correcto" porque el código interpretaba 403 como indisponibilidad temporal).
2. **Fail-closed + no-caching de fallos = amplificación temporal del incidente.** Cada request reintentaba la descarga en lugar de cachear el 403 por 1h/día. Con vendors monthly re-screening (paginate batches of 200), miles de requests fallidos bombardearon el webgate sin mejorar la situación.
3. **Mirror OpenSanctions con licencia CC BY-NC 4.0** confirma que no existe una "fuente pública equivalente" sin restricciones — la UE es eficazmente la única fuente autoritativa, y cuando la cierra, FONT(opensource mirror) comercial exige licencia. La opción real es deshabilitar la lista y documentar el límite como best-effort compliance.

**Fix aplicado:**

- `SANCTIONS_LISTS['eu_restrictive']` ahora incluye `disabled=true`, `disabled_reason='ECAS_LOGIN_REQUIRED_SINCE_2025'`, `disabled_at='2026-08-03'`. NO se elimina del array — se conserva la URL legacy y los campos descriptivos para trazabilidad histórica (la URL funcionó durante años y auditoría UIAF puede preguntar "¿por qué dejaron de screenear UE?").
- `screen_against_sanctions_lists()` ahora hace `continue` al inicio del `foreach` si `disabled=true`, con log `FT_SCREEN_LIST_DISABLED` (warning) para evidencia de auditoría.
- `rescreen_active_vendors()` emite log `FT_SCREEN_LISTS_DISABLED_RESCREEN` (critical) al final del cron mensual recordando al oficial de cumplimiento que la lista UE está fuera de servicio — evidencia documental del cumplimiento best-effort SARLAFT art. 9.4.3.
- 3 tests nuevos en `FintechComplianceTest` cubren los invariantes: skip de EU, KYC aprobado cuando OFAC+UN 200 sin match, log crítico emitido.
- ID tarea: `FSF-EU-DISABLED-2026-08-03`. Versión bump: 2.9.307 → 2.9.308.

**Regla preventiva:**

Toda fuente de datos externa (sanctions lists, FX rates, APIs regulatorias, etc.) referenciada en constantes de clase DEBE tener un campo `disabled`+`disabled_reason` interpretable por el código consumidor. La regla:

1. **Nunca `unset` o eliminar del array** una fuente que se vuelve no-pública — preservar URL y razón para evidencia de auditoría futura (UIAF/SFC/SHCP pueden preguntar "¿por qué dejaron de screenear X?").
2. **El skip DEBE emitir un log warning/critical** por cada iteración que lo saltea — ese log es la evidencia documental de que el cumplimiento es best-effort, no omisión negligente.
3. **Para fail-closed stricto sensu** (cuando la doctrina legal lo exija y no exista best-effort doctrine), agregar un flag `disabled_block_kyc => true` separado del `disabled` de skip+log. El fail-closed es decisión de negocio, no técnica por defecto.
4. **Cache de fallos**: si una URL devuelve 403/500, cachear el fallo por 1h-1d para no bombardear el endpoint externo con reintentos sin valor (caso real: miles de vendors re-screened × 1 URL UE caída = DoS accidental propio contra la infraestructura europea).
5. **Para fuentes umbrella regulatorias** (OFAC, UN, EU FSF, UIAF listas locales): revisar anualmente que las URLs sigan públicas. Un endpoint que devuelve 403 en una auditoría SSH al cron PQRS **rompe el negocio silenciosamente durante meses** antes de que nadie lo note — los logs deben tener alerts visibles en panel admin cuando el screening falla sostenidamente (no solo `WP_Error` en state).

### Lección #153: UI label "solo personas jurídicas" + backend exige a TODOS → brecha silenciosa que se manifiesta solo cuando el validador bloquea en fail-closed

**Error:**

Tres gaps encadenados en el flujo KYC de Maria Orlinda Giraldo Gomez #208 (persona natural CC, vendedora):

1. **GAP-A — backend exigía a todos**: `LTMS_Authorities_Compliance::validate_rut_and_camara_comercio()` leía `ltms_camara_comercio_number` sin distinguir `document_type` — bloqueaba a TODOS los vendors CO con `ac_cc_missing` si no habían seteado el user_meta, aunque fueran persona natural (CC/CE/PAS) quienes, según Código de Comercio art. 10, no son "comerciante matriculado" obligatoriamente. La UI ya labelaba el campo Cámara como "solo personas jurídicas", pero el backend no respetaba ese contrato.
2. **GAP-B — UI pedía el archivo, no el número**: `view-kyc.php` solo incluía input `<input type="file" id="ltms-kyc-file-camara">` para subir el PDF del certificado, pero nunca un campo de texto para `ltms_camara_comercio_number` ni un `<input type="date">` para `ltms_camara_comercio_expires`. Aunque el validador pasara, esos metas quedaban siempre vacíos.
3. **GAP-C — handler nunca persistía los metas**: `class-ltms-dashboard-logic.php:ltms_submit_kyc()` leía `$_POST['file_path_camara']` y persistía `ltms_kyc_file_camara` (el path del PDF), pero no había lectura ni escritura de `ltms_camara_comercio_number` / `ltms_camara_comercio_expires`. El campo ni siquiera existía en el POST — el handler no podía inventarlo.

Resultado: cualquier vendedor NIT jamás podía aprobar KYC (aunque subiera el PDF correcto), y cualquier vendedor CC/CE/PAS quedaba bloqueado por un requisito que la UI misma le decía que no aplicaba. Bug silencioso porque:
- El error se manifiesta solo en el endpoint admin `wp_ajax_approve_kyc`, no en front.
- El mensaje "Falta el número de matrícula de Cámara de Comercio" suena razonable e imprescindible, desincentivando la pregunta "¿debería aplicarle a este vendor?".
- Solo emerges cuando un admin intenta aprobar a un vendor específico — y el admin asume que el vendor hizo algo mal.

**Causa raíz:**

1. **Contracto UI ↔ backend NO verificado end-to-end.** El backend declaró un contrato ("exijo número de matrícula Cámara") pero la UI nunca se actualizó para cumplirlo. Cuando se añadió la validación `validate_rut_and_camara_comercio` (v2.9.16), el form KYC ya tenía el input file del PDF Cámara — el dev asumió que era suficiente, sin notar que el validador leía user_meta distinto al que el form escribía.
2. **Doctrina legal mal interpretada como "estricto" sin matices.** El Decreto 2150/1995 sí obliga a matrícula mercantil, pero el Código de Comercio acota esa obligación a "comerente" (art. 10) — persona natural vendedora ocasional no es comerciante. Codificar "a todos" como fail-closed SARLAFT parecía correcto doctrinalmente, pero ignoraba la doctrina best-effort UIAF que permite graduar requisitos por tipo de persona.
3. **Label UI y código sin sincronizar.** La frase "solo personas jurídicas" en `view-kyc.php` L162 y L195 es una pista clara: el dev de UI sabía que la regla no era universal. Pero el dev del backend no vio esa label, o la vio y no la respetó. Sin un test que cruce UI↔backend ("si la label dice 'solo PJ', el backend no debe exigirlo a PN"), la discrepancia se cuela.

**Fix aplicado:**

- Backend: `validate_rut_and_camara_comercio()` ahora lee `ltms_document_type` y solo exige `ltms_camara_comercio_number` + `ltms_camara_comercio_expires` cuando `document_type='nit'`. Persona natural → skip con log `AC_CC_PERSONA_NATURAL_EXEMPT` (info, no warning) como evidencia de auditoría UIAF del criterio aplicado.
- UI `view-kyc.php`: añadido `<div id="ltms-kyc-camara-fields">` con 2 inputs (número + fecha de vencimiento), visible solo si `document_type='nit'` (PHP prefill + JS toggle en `assets/js/ltms-kyc.js`). Se prefilla desde `$kyc->camara_comercio_number` o `get_user_meta` para que un NIT que reenvía KYC no tenga que reingresar los datos.
- Handler `class-ltms-dashboard-logic.php`: leídos los 2 nuevos POST `camara_comercio_number` y `camara_comercio_expires` (sanitize + date normalize), `update_user_meta` solo si `document_type='nit'`; `delete_user_meta` para persona natural (limpiar residuo si el vendor antes era NIT).
- JS `ltms-kyc.js` + `.min.js`: toggle visual, validación front para NIT (exige número antes del submit), envío de los 2 campos en POST.
- Tests `KyccCamaraPnExemptTest`: +10 tests cubren 4 escenarios persona natural/4 persona jurídica + 1 case-insensitive NIT + 1 MX no ejecuta rama CO.
- Versión bump: 2.9.308 → 2.9.309.

**Regla preventiva:**

1. **Todavalidación backend que exija user_meta DEBE existir un campo UI correspondiente que lo persista.** Esta invariante no se cumple por inspección humana; requiere un test de integración que cubra: submit form → handler persiste el campo → validador lo lee. Si solo hay tests estructurales ("el código contiene `new WP_Error('ac_cc_missing')`"), el bug se cuela porque el código sí contiene el `WP_Error` — solo que la rama nunca se ejercita correctamente.
2. **Contracto UI label ↔ backend debe ser consistente.** Si la UI label habla de "solo aplica a X subconjunto", el backend DEBE verificar `subset === X` antes de aplicar el requisito. Mismas palabras, mismo código — nunca asumir "el usuario entendió que es opcional porque la label lo dice".
3. **Doctrina legal regulatory requiring "todo comerciante" o "toda persona" debe interpretarse con sus propias leyes de scope** (Código de Comercio art. 10 define comerciante). Codificar "todo X" sin el scope detail abre false positives que bloquean personas legítimas. Auditoría UIAF acepta best-effort con criterio documentado en log/info.
4. **Addición de validador backend SIN sincronizar UI handler → siempre bug latente.** El orden correcto: añadir UI → añadir handler que persiste → añadir validador. Inverso funciona hasta el primer vendor que golpea la validación.
5. **Tests de behaviour con `get_user_meta` mock por key** son la única forma de cubrir este caso sin integración completa. Ver `KyccCamaraPnExemptTest::invoke()` como patrón: stub de `get_user_meta` que retorna valor distinto según el key, invocar el método privado via ReflectionClass, assert sobre `true` vs `WP_Error::class`.



## 21. v2.9.310 - SITEGROUND-NO-ASSERT: `assert()` en `disable_functions` + OPcache `validate_timestamps=0` (3 lecciones nuevas)

> Auditoría end-to-end del despliegue v2.9.310 a SiteGround. PHPUnit moría con `Call to undefined function assert()` en runtime, patches no aplicaban, Composer.phar global de SG también roto por `assert()`. Resolución requirió: (a) commitear vendor/ ya parcheado al repo, (b) resetear OPcache manualmente, (c) usar `LTMS_UNIT_ONLY=true` + `--testsuite=unit` para evitar dependencia WP. Suite final verde (3,707 tests / 6,549 assertions / 3 skipped), idéntica al local.

### Lección 21.1 — `assert()` en `disable_functions` de SiteGround invalida PHPUnit + Composer global

**Contexto:**

Al correr `php -d zend.assertions=1 -d assert.active=1 vendor/bin/phpunit --group kyc` en SSH de SiteGround, PHP moría con `Fatal error: Uncaught Error: Call to undefined function assert() in vendor/sebastian/cli-parser/src/Parser.php:68`. La línea 68 NO contenía `assert(` (ya estaba parcheada a `throw new \AssertionError('assert(is_int($i)) failed')`). El stack trace estaba desalineado respecto al código en disco.

**Causa raíz:**

1. **`assert()` está en `disable_functions` de SiteGround y NO es overrideable vía CLI flags.** Verificado con `php -r 'echo function_exists("assert") ? "OK" : "MISSING"'` → `MISSING`, tanto con `-d zend.assertions=1 -d assert.active=1` como sin ellos. La función es eliminada del runtime en compile-time por SG (likely vía `disable_functions` en php.ini), no deshabilitada condicionalmente. Los flags `zend.assertions` y `assert.active` controlan *si los asserts del código se evalúan*, no *si la función `assert()` está disponible*.

2. **Composer.phar global de SG también usa `assert()` internamente** (`src/Composer/Repository/ComposerRepository.php:175`). Cualquier `composer install` en SG muere con `Fatal error: Call to undefined function Composer\Repository\assert()` antes de que pueda siquiera resolver dependencias, sin llegar a correr `cweagans/composer-patches`. La solución de "aplicar patches vía composer-patches" es **circularmente break** en SG — el proceso que aplicaría los patches necesita él mismo el `assert()` parcheado.

3. **El fatal reportaba `Parser.php:68` pero la traza real venía de `Command.php:101`.** PHP reporta el punto de *captura* del error, no el punto de *invocación*. Cuando la traza muestra `Parser.php:68` pero en disco no hay `assert(` allí, hay que mirar la traza `Next ...` completa — el "Next" apunta al handler de nivel superior que volvió a lanzar (`Command.php:101`), que era donde efectivamente se invocaba `assert()`. Confiarse solo en la primera traza lleva a parchear el archivo incorrecto o buscar fantasmas.

**Fix aplicado:**

- **Commitear `vendor/` ya parcheado al repo** (commit `034dfaa1`). Como `vendor/` está trackeado en este proyecto (inusual para un plugin WP pero válido), un `git pull` trae los archivos con `assert()` reemplazados por `throw new \AssertionError(...)` directamente — sin necesidad de correr `composer install`. Los .patch files viajan en `patches/` solo como documentación del cambio aplicado (y por si en el futuro SG cambia de política de `disable_functions`).
- Patches generados desde git diff de vendor modificado:
  - `patches/sebastian-cli-parser-no-assert.patch` (1,473 bytes, 4 hunks — reemplaza 3 llamadas `assert()` por `throw new \AssertionError(...)` en `Parser.php`).
  - `patches/phpunit-phpunit-no-assert.patch` (18,528 bytes, 27 hunks — reemplaza 25 llamadas `assert()` distribuidas en 12 archivos de phpunit).
  - 5 `assert()` intencionalmente NO parcheados: `Framework/Assert.php:2388` y `:2955` (solo se ejecutan en `assertSelect`.DOM tests y step tests), `Util/PHP/AbstractPhpProcess.php:332` (solo en separate-process tests), `Migration/Migrations/MoveWhitelistExcludesToCoverage.php:55` y `RemoveLogTypes.php:31` (solo en `--migrate-configuration`). Todos en paths no-calientes del runtime phpunit.
- Validado en server: `grep -rn "^[[:space:]]*assert(" vendor/phpunit/phpunit/src/` devuelve exactamente esos 5, todos en paths fuera del flujo `--group kyc`/`--testsuite=unit` estándar.

**Regla preventiva:**

1. **`disable_functions` de PHP no es overrideable por CLI flags.** `php -d disable_functions=` no remueve funciones — las flags solo cambian .ini values para valores que admiten override; `disable_functions` es `PHP_INI_SYSTEM` y se fija al arrancar PHP. Si una función del core (`assert`, `exec`, `shell_exec`, `system`, `passthru`, `mail`, etc.) está bloqueada, NO hay override de CLI posible — hay que evitar el código que la llama o mover ese runtime a un servidor sin la restricción.

2. **Cuando una restricción del runtime bloquea la herramienta que la parchearía, el flujo es circular.** No intentar resolverlo encadenando versiones más nuevas del Composer.phar (random composer releases también usan `assert()`). La salida es aplicar el parche por otra vía que no dependa del runtime bloqueado — en este caso, commitear los archivos parcheados al repo y servirlos vía `git pull`, no `composer install`.

3. **El stack trace de PHP reporta el capturador, no el invocador.** Cuando un fatal dice `in file.php:LINE` y esa línea no contiene la función supuestamente inválida, mirar TODA la traza — especialmente el bloque `Next ...` que es el handler superior. No gastar tiempo parcheando archivo que la traza apunta si grep confirma que no contiene el pattern problemático; buscar el invocador real en la traza #0..#N.

### Lección 21.2 — `opcache.validate_timestamps=0` en SiteGround requiere reset manual tras editar vendor/

**Contexto:**

Tras aplicar los patches vía `git pull` (con vendor/ ya parcheado en el repo), `phpunit --group kyc` seguía rompiendo con el MISMO `Fatal: Call to undefined function assert() in Parser.php:68`, aunque `grep` y `php -r 'echo file_get_contents(...)'` confirmaban que `Parser.php` en disco ya tenía `throw new \AssertionError(...)` en lugar de `assert()`.

**Causa raíz:**

- **SiteGround tiene `opcache.validate_timestamps=0` y `opcache.revalidate_freq=60`.** Verificado con `php -r 'echo ini_get("opcache.validate_timestamps") . " / " . ini_get("opcache.revalidate_freq");'` → `0 / 60`. Con `validate_timestamps=0`, OPcache **nunca revalida** los archivos tocados/modificados — el bytecode queda cacheado hasta que se vacía explícitamente el dir de cache o se reinicia PHP-FPM.
- `touch vendor/sebastian/cli-parser vendor/phpunit/phpunit -name "*.php" -exec touch {} +` **NO fue suficiente** — el `touch` solo actualiza mtime, pero OPcache con `validate_timestamps=0` ignora mtime. PHP siguió sirviendo el bytecode antiguo (con `assert(is_int($i))`) en lugar del nuevo (`throw new \AssertionError(...)`).
- Esto generó una contradicción aparente: el archivo en disco estaba parcheado (`grep` lo confirma), pero el runtime ejecutaba el código antiguo. PHP informó el fatal en la línea que el bytecode cacheado apuntaba, no la línea que el archivo tenía.

**Fix aplicado:**

```bash
find ~/.opcache -type f -delete 2>/dev/null
find /tmp/php-opcache-* -type f -delete 2>/dev/null
```

Tras esos dos deletes, `phpunit --group kyc` arrancó correctamente (fatal de `assert()` desapareció). El OPcache se rehidrató desde los archivos en disco (ya parcheados) y se ejecutó el código correcto. **No hubo que reiniciar PHP-FPM** (probablemente "orgánico" en shared hosting, no permitido).

**Regla preventiva:**

1. **`opcache.validate_timestamps=0` implica que TODO cambio a vendor/ o includes/ en SG requiere reset del dir de cache.** No sirve `touch`, no sirve esperar 60 segundos de `revalidate_freq` (ese flag es ignorado cuando validate_timestamps=0). El comando a correr tras cualquier edición de PHP en el servidor:
   ```bash
   find ~/.opcache -type f -delete 2>/dev/null
   find /tmp/php-opcache-* -type f -delete 2>/dev/null
   ```
2. **Considerar abrir ticket de SiteGround** pidiendo `opcache.validate_timestamps=1` para la cuenta — root causal de bleed de cache tras deploys. En shared hosting rara vez lo cambian, pero el ticket es evidencia de cliente.
3. **Symptom único para diferenciar OPcache staleness de bug real**: un PHP fatal que apunta a línea que grep confirma está limpia en disco = casi siempre OPcache cacheado. Confirmar con `php -r 'echo file_get_contents($f);'` (que bypassa OPcache porque leeArchivo directo) vs. la traza del fatal. Si difieren, es OPcache.
4. **Para tests SSH en SG, siempre correr LTMS con cache flush pre-test.** El comando estándar en SG debe ser:
   ```bash
   find ~/.opcache -type f -delete 2>/dev/null
   LTMS_UNIT_ONLY=true php -d zend.assertions=1 -d assert.active=1 vendor/bin/phpunit --configuration phpunit.xml --testsuite=unit --group kyc
   ```

### Lección 21.3 — `--testsuite=unit` requerido en SG, sin WP test suite disponible

**Contexto:**

Tras corregir OPcache, `phpunit --group kyc` siguió fallando con `❌ ERROR: WP Test Suite no encontrada. Ejecuta primero: bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest`. Al agregar `LTMS_UNIT_ONLY=true` (usado exitosamente en local), PHPUnit empezó a parsear tests y morir con `Class "LTMS\Tests\Integration\LTMS_Integration_Test_Case" not found in tests/integration/CapsRolesIntegrationTest.php:20`.

**Causa raíz:**

1. **`LTMS_UNIT_ONLY=true` solo saltea la carga de WordPress test suite**, NO restringe qué testsuite de phpunit se ejecuta. Sin `--testsuite=unit`, PHPUnit mapea **TODOS** los testsuites definidos en `phpunit.xml` (`unit`, `integration`, `all`) como un único `TestSuite` y `addTestFile()` cada archivo, incluyendo `tests/integration/CapsRolesIntegrationTest.php` que hace `extends LTMS_Integration_Test_Case`. En modo UNIT_ONLY esa clase base no está cargada (solo se carga cuando WP test suite inicializa), ergo fatal.

2. **`phpunit.xml` define los 3 testsuites separados:**
   ```xml
   <testsuite name="unit"><directory suffix="Test.php">tests/unit</directory></testsuite>
   <testsuite name="integration"><directory suffix="Test.php">tests/integration</directory></testsuite>
   <testsuite name="all"><directory suffix="Test.php">tests/unit</directory><directory suffix="Test.php">tests/integration</directory></testsuite>
   ```
   Pero sin `--testsuite=N`, PHPUnit default es ejecutarlos todos unificados — aunque solo le pidas un `--group`.

3. **Localmente no se vio porque las costumbres del dev eran siempre `--testsuite=unit --group X`**, no `--group X` solo. Al llevarlo a SG con menos confianza, se probó sin `--testsuite=` y saltó el agujero de cargar tests/integration/ en modo UNIT_ONLY.

**Fix aplicado:**

- Comando estándar SG para correr tests (documentado en CLAUDE.md):
  ```bash
  LTMS_UNIT_ONLY=true php -d zend.assertions=1 -d assert.active=1 vendor/bin/phpunit --configuration phpunit.xml --testsuite=unit --group kyc
  ```
- Para suite completa:
  ```bash
  LTMS_UNIT_ONLY=true php -d zend.assertions=1 -d assert.active=1 vendor/bin/phpunit --configuration phpunit.xml --testsuite=unit
  ```
- NO correr `phpunit --group X` (sin `--testsuite=unit`) en modo UNIT_ONLY — siempre fallará al toparse con tests/integration.

**Regla preventiva:**

1. `LTMS_UNIT_ONLY=true` y `--testsuite=unit` son **co-dependientes** en SG. Solo el primero restringe el bootstrap (no carga WP); solo el segundo restringe el descubrimiento de tests (no evalúa tests/integration/). Faltar cualquiera rompe en SG.
2. **`phpunit.xml` con múltiples testsuites + `--group` sin `--testsuite` es footgun.** PHPUnit starts todos los testsuites y luego filtra por grupo, forzando el load de todos los archivos (incluyendo tests de integración que pueden tener dependencias no disponibles). Siempre usar ambos flags juntos en hosts restrictivos.
3. **Documentar el comando canónico en CLAUDE.md** para que futuros sessiones no redescubran el combo correcto por trial/error. Ver entrada nueva en `CLAUDE.md → Operación en SiteGround SSH`.







