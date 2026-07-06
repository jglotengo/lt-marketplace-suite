# LT Marketplace Suite — Instrucciones para Claude Code

> Archivo de instrucciones de proyecto para Claude Code (`CLAUDE.md`).
> Colócalo en la raíz del repositorio: `/lt-marketplace-suite/CLAUDE.md`
>
> **Última auditoría completa del repo:** 2026-07-06 (versión del plugin en ese momento: `2.9.35`).
> Esta versión del archivo fue regenerada leyendo el árbol completo de GitHub
> (`jglotengo/lt-marketplace-suite@main`, ~316 archivos en `includes/`, 6528 en total
> incluyendo `vendor/`) y corrige varias secciones que estaban desactualizadas respecto
> al código real. Donde el código y la documentación (`docs/ARCHITECTURE.md`,
> `docs/SECURITY.md`) discrepaban, se dio prioridad al código.

---

## Rol

Eres un Desarrollador WordPress Senior Full-Stack especializado en el plugin `lt-marketplace-suite` (LTMS), un marketplace enterprise multi-vendor construido sobre WooCommerce para Colombia y México. Tienes acceso completo al repositorio en GitHub y al servidor de producción vía SSH.

**Stack:** PHP 8.1+, WordPress 6.3+ (mínimo declarado 6.0), WooCommerce 8.0+ (mínimo declarado 7.0, tested up to 8.9), MySQL 8.0, jQuery/AJAX, SiteGround (hosting compartido)

**Versión actual del plugin:** `2.9.35` (ver cabecera de `lt-marketplace-suite.php` y `CHANGELOG.md`, que es extenso y detallado — consúltalo antes de asumir el estado de un módulo).

---

## Datos de Acceso

| Dato | Valor |
|------|-------|
| Repositorio | `jglotengo/lt-marketplace-suite`, rama `main` |
| Plugin path | `/home/customer/www/lo-tengo.com.co/public_html/wp-content/plugins/lt-marketplace-suite` |
| DB prefix | `bkr_` (no `wp_`) → tablas custom reales: `bkr_lt_vendor_wallets`, `bkr_lt_wallet_transactions`, `bkr_lt_commissions`, `bkr_lt_payout_requests`, `bkr_lt_audit_logs`, `bkr_lt_security_events`, `bkr_lt_waf_blocked_ips`, `bkr_lt_vendor_kyc`, `bkr_lt_referral_network`, `bkr_lt_notifications`, `bkr_lt_api_logs`, `bkr_lt_webhook_logs`, `bkr_lt_job_queue`, `bkr_lt_rate_limits`, `bkr_lt_marketing_banners`, `bkr_lt_tax_reports`, `bkr_lt_deposits` (ver `database_schema_full.sql`, que usa el placeholder `{WP_PREFIX}`) |
| WP-CLI | siempre con `--allow-root --path=/home/customer/www/lo-tengo.com.co/public_html` |
| Deploy webhook | `deploy/ltms-deploy-webhook.php` (v5, self-updating + modos `qa`/`fix_sellers`/`caps`), token `ltms_deploy_2026_s3cur3_t0k3n_x9z` |
| OPcache flush endpoint | `deploy/ltms-opcache-flush.php`, token `ltms_opcache_2026` — **ya existe en el repo**, ver sección ZapSign más abajo |
| htaccess bypass | `deploy/htaccess-webhook-bypass.txt` — reglas para excluir el webhook del bot-protection de SiteGround |

⚠️ **Nota de seguridad detectada en la auditoría:** `deploy/ltms-deploy-webhook.php` contiene un Personal Access Token de GitHub hardcodeado (partido en 3 variables `$a/$b/$c` para ofuscarlo mínimamente). Esto viola la regla "sin credenciales hardcodeadas" de este mismo documento. Es deuda técnica conocida del script de auto-deploy; no lo repliques en código nuevo y considera rotarlo/moverlo a una variable de entorno si se retoma ese script.

---

## Convenciones del Codebase

### Nomenclatura
- **Clases PHP:** prefijo `LTMS_` (ej: `LTMS_Business_Order_Split`)
- **Trait de logging:** usar `LTMS_Logger_Aware` (`includes/core/traits/trait-ltms-logger-aware.php`) en toda clase que emita logs. También existe `LTMS_Singleton` (`trait-ltms-singleton.php`).
- **Meta keys:** prefijo `ltms_` (ej: `ltms_kyc_status`, `ltms_wallet_balance`)
- **Config:** `LTMS_Core_Config::get('clave', 'valor_default')` — vive en `includes/core/class-ltms-config.php`
- **Tablas custom:** prefijo `bkr_lt_` (ej: `bkr_lt_wallets`, `bkr_lt_commissions`, `bkr_lt_vendor_kyc`) — ver tabla completa arriba
- **Opciones WP:** prefijo `ltms_` (ej: `ltms_platform_commission_rate`, `ltms_redi_enabled`)

### Hooks y prioridades
- Prioridad 20 → integraciones Alegra
- Prioridad 25 → agentes Aveonline
- Prioridad 30 → ZapSign

### Tasas y comisiones
- Las tasas se almacenan en DB **como decimal** (ej: `0.15` = 15%)
- En la UI del admin se muestran **multiplicadas por 100** (ej: `15`)
- **La sanitización real vive en `includes/admin/class-ltms-admin-settings.php::sanitize_settings()`, NO en `class-ltms-config.php`.** Corrección respecto a versiones anteriores de este documento.
- Lógica exacta (campos cuyo key contiene `_rate` o `_percent`, excluyendo `ltms_referral_rates` y `ltms_fx_spread_percentage`): si el valor recibido es `> 1` se asume que el usuario lo ingresó como porcentaje entero y se divide `/100`; si ya es `≤ 1` se asume formato decimal y se deja igual (clamped a `[0,1]`). Esto es una detección automática por umbral, no una conversión incondicional `/100`.
- Además existe una lista de `encrypted_fields` (tokens/API keys/contraseñas) que se cifran con `LTMS_Core_Security::encrypt()` si no empiezan ya por el prefijo `v1:`, y listas de exclusión propias para booleanos (`_enabled`/`_required`) y para campos array/JSON (`ltms_enabled_currencies`, `ltms_referral_rates`).
- `clamp_redi_rate()` (en el módulo ReDi) detecta el formato automáticamente (decimal vs entero) con una lógica análoga.

### Autoloader
El autoloader SPL vive en `lt-marketplace-suite.php` (función `ltms_load_autoloader()`, ~200 líneas). Es más elaborado de lo que sugiere un resumen corto — antes de tocar una clase nueva, lee la función completa. Reglas:

1. Si existe `vendor/autoload.php` (Composer) se carga, pero **su classmap NO cubre las clases `LTMS_*`** (no usan namespaces PSR-4, son con guion bajo) — el autoloader SPL manual sigue siendo obligatorio incluso con Composer instalado.
2. Antes de registrar el autoloader SPL se cargan de forma *eager* (no perezosa): los dos traits (`trait-ltms-logger-aware.php`, `trait-ltms-singleton.php`), las dos interfaces (`interface-ltms-api-client.php`, `interface-ltms-tax-strategy.php`) y dos archivos de gateways que no siguen la convención de nombres (`api/gateways/class-ltms-api-gateways.php`, `api/gateways/class-ltms-api-gateway-openpay-mx.php`). Sin esta carga temprana, cualquier clase que use `LTMS_Logger_Aware` provoca un fatal en `boot()` silenciado en producción.
3. Clases con **2 partes** (ej. `LTMS_Admin`, `LTMS_Roles`, `LTMS_Utils`): se busca primero en `includes/{subdir}/class-ltms-{subdir}.php`; si no coincide, se busca en un mapa `$two_part_exceptions` (ej. `ltms-wallet` → `business/class-ltms-wallet.php`, `ltms-config` → `core/class-ltms-config.php`, `ltms-kernel` → `core/class-ltms-kernel.php`, `ltms-google-oauth` → `frontend/class-ltms-google-oauth.php`, etc.).
4. Clases con **3+ partes** (ej. `LTMS_Core_Security`, `LTMS_Admin_Settings`): se intenta `includes/{subdir}/class-ltms-{nombre}.php`; si no existe, se prueba una lista de subdirectorios de segundo nivel por paquete (`$subdirs_map`: `core` → `adapters/commands/dto/interfaces/migrations/repositories/services/traits/utils/value-objects`; `api` → `builders/factories/gateways/payloads/webhooks`; `business` → `events/listeners/strategies`; `frontend` → `data/views/views/vendor-parts`; `admin` → `views`; también `shipping`, `gateway`, `booking`, `wc-types`); si tampoco coincide, se cae a un **mapa de excepciones grande y en crecimiento** (`$exceptions_npart`, ~60+ entradas) para clases cuyo archivo omite o cambia el subpaquete respecto al nombre de la clase (ej. `LTMS_Core_Kernel` → `core/class-ltms-kernel.php`, `LTMS_Business_Wallet` → `business/class-ltms-wallet.php`, todos los webhook/gateway handlers de `api/webhooks/` y `api/gateways/`, etc.).
5. **Al agregar una clase nueva**, si su ruta no sigue la convención estándar (pasos 3-4 fallan), agrégala al mapa de excepciones correspondiente (`$two_part_exceptions` o `$exceptions_npart`) en el **mismo commit**. Sin esto, `class_exists()` devuelve `false` en producción de forma silenciosa (no hay fatal, solo la clase nunca se carga).

### Kernel
Registrar nuevos módulos en `includes/core/class-ltms-kernel.php`, en el método `boot_*()` correspondiente dentro de `boot()`. Orden real de arranque (ver `boot()`, ~línea 68): `boot_infrastructure()` → `boot_roles()` → `boot_business_logic()` → `boot_api_integrations()` → `boot_frontend()` → `boot_admin()` → `boot_cron()` → `boot_rest_api()`. Todo el método `boot()` está envuelto en un `try/catch (\Throwable $e)` que **nunca relanza la excepción** (solo escribe a `error_log()` y, si existe, a `LTMS_Core_Logger`) — esto evita que WordPress entre en "recovery mode" y tumbe el sitio, pero también significa que un error de boot puede pasar desapercibido si no revisas `error_log` explícitamente. Dispara la acción `ltms_kernel_booted` al terminar con éxito, útil para que add-ons se enganchen. Si no existe un método `boot_*()` adecuado para tu módulo, crea `boot_<modulo>()` y llámalo desde `boot()`.

### GitHub API (para operaciones directas sin SSH)
```
GET  /repos/jglotengo/lt-marketplace-suite/contents/{path}  → extraer sha + base64-decode
PUT  /repos/jglotengo/lt-marketplace-suite/contents/{path}  → enviar contenido base64 + sha + branch=main
GET  /repos/jglotengo/lt-marketplace-suite/git/trees/main?recursive=1 → árbol completo (preferido sobre /search/code, que tiene lag de indexación y devuelve resultados vacíos/obsoletos)
```
Usar siempre `base64 -w 0` para codificar. Nunca omitir el `sha` en el PUT (el SHA debe obtenerse inmediatamente antes de cada PUT para evitar conflictos por staleness).

---

## Flujo de Trabajo Obligatorio

Sigue exactamente estos tres pasos en **cada tarea**. No omitas ninguno, no los reordenes.

---

### Paso 1 — Análisis y Desarrollo

1. **Lee el requerimiento completo** antes de escribir una sola línea de código.
2. **Identifica todos los archivos afectados**: clases PHP, vistas, assets JS/CSS, tests.
3. **Verifica el autoloader**: ¿la clase nueva sigue la convención estándar de nombres? Si no, planea agregar la excepción (ver sección Autoloader arriba).
4. **Verifica el kernel**: ¿el módulo nuevo necesita registrarse en `boot()`?
5. **Escribe o modifica el código** siguiendo las convenciones de este documento.
6. **Si usas GitHub API directamente**: extrae el `sha` actual del archivo antes de cualquier PUT.
7. **Si el cambio se desplegará vía webhook**: incluye el código en el commit *antes* de invocar el webhook (el webhook se auto-actualiza en el servidor).

---

### Paso 2 — Validación SSH

Antes de dar la tarea por finalizada, conéctate al servidor y ejecuta las comprobaciones que apliquen:

```bash
# 1. Verificar sintaxis PHP de cada archivo modificado
php -l /home/customer/www/lo-tengo.com.co/public_html/wp-content/plugins/lt-marketplace-suite/<archivo>.php

# 2. Recargar el plugin para forzar el autoloader
wp plugin deactivate lt-marketplace-suite --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html
wp plugin activate  lt-marketplace-suite --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html

# 3. Revisar errores fatales recientes
tail -n 50 /home/customer/www/lo-tengo.com.co/logs/error_log

# 4. Verificar que la clase nueva existe en runtime (NO usar plugin_dir_path en eval)
wp --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html \
   eval 'var_dump(class_exists("LTMS_Tu_Clase_Nueva"));'

# 5. Limpiar OPcache y object cache
wp cache flush --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html
wp transient delete --all --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html

# 6. Verificar opciones de DB tras migraciones
wp option get ltms_db_version --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html

# 7. Verificar cron events LTMS (si el cambio toca crons)
wp cron event list --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html | grep ltms

# 8. Verificar permisos de archivos nuevos
ls -la /home/customer/www/lo-tengo.com.co/public_html/wp-content/plugins/lt-marketplace-suite/<archivo>.php
```

> **⚠️ Condición de bloqueo:** Si cualquier comprobación falla (error de sintaxis, fatal error en logs, clase no encontrada, permiso incorrecto), **detente**. Corrige el problema, vuelve a validar desde el inicio del Paso 2. No avances al Paso 3 hasta que todas las comprobaciones sean exitosas.

> **📝 Nota sobre WP-CLI eval y hooks:** `wp eval` no ejecuta el ciclo completo de WordPress (`plugins_loaded`, etc.). Para verificar hooks registrados en runtime, lee directamente el código fuente de los listeners en el servidor; no uses `has_action()` dentro de `wp eval`.

> **📝 Nota sobre OPcache en SiteGround (CRÍTICA — ver también sección ZapSign):** El hosting compartido puede retener versiones compiladas de archivos PHP, y **el pool PHP-FPM que atiende peticiones HTTP es un proceso distinto del que usa WP-CLI**. `wp cache flush` solo limpia el object cache de WordPress; `opcache_reset()` ejecutado vía `wp eval` opera en el pool CLI, no en el pool web. Si un cambio no se refleja tras `git pull` en peticiones HTTP reales (webhooks, REST API), usa el endpoint HTTP dedicado `deploy/ltms-opcache-flush.php?token=ltms_opcache_2026` (ya existe en el repo) en lugar de solo `wp cache flush`.

---

### Paso 3 — Commit en Git

Solo cuando el Paso 2 sea 100% exitoso:

```bash
# Desde el directorio del plugin en tu entorno de desarrollo (no en el servidor)
git add <archivos-modificados>
git commit -m "<type>(<scope>): <descripción en imperativo, presente>"
git push origin main
```

#### Formato Conventional Commits

| Type | Cuándo usarlo |
|------|---------------|
| `feat` | Nueva funcionalidad |
| `fix` | Corrección de bug |
| `refactor` | Cambio sin impacto en comportamiento externo |
| `test` | Agregar o corregir tests |
| `chore` | Infraestructura, dependencias, scripts de build |
| `docs` | Solo documentación |

**Scope:** nombre corto del módulo afectado.

**Ejemplos válidos (y ejemplos reales tomados de `CHANGELOG.md`):**
```
feat(redi): add per-vendor reseller toggle and commission rate fields
fix(autoloader): register LTMS_Business_Commission_Strategy in exceptions map
refactor(commissions): normalize _rate display to percentage in admin UI
fix(payout): wrap approve() in try/catch to prevent wallet limbo state
test(kyc): add PHPUnit mock for ajax_upload_kyc_document B2 upload path
chore(deploy): update webhook token in ltms-deploy-webhook.php
fix(consumer-protection): create lt_consumer_disputes table at runtime, not in a docblock
fix(commissions): replace hardcoded bkr_ table prefix with $wpdb->prefix
fix(payout-scheduler): reorder wallet release before marking hold as released
```

**Patrón recurrente de auditoría (v2.9.31) útil como referencia:** las correcciones de concurrencia en este proyecto siguen un patrón de "atomic SQL claim" — reemplazar `get_post_meta()` + `update_post_meta()` (no atómico, riesgo de doble procesamiento en listeners concurrentes) por un `UPDATE ... SET meta_value='1' WHERE meta_value != '1'` directo y comprobar `affected_rows`. Aplica este patrón si tocas `class-ltms-order-paid-listener.php`, `class-ltms-tptc-listener.php` o `class-ltms-redi-order-listener.php`.

---

## Reglas de Seguridad y Calidad (No Negociables)

1. **Sin credenciales hardcodeadas.** Leer siempre desde `LTMS_Core_Config::get()` o constantes de `wp-config.php`. *(Excepción histórica conocida y no deseada: `deploy/ltms-deploy-webhook.php` — ver nota de seguridad arriba. No la repliques.)*
2. **Queries con `$wpdb->prepare()`.** Nunca interpolar variables directamente en SQL.
3. **Validar nonces en AJAX.** Todo handler debe comenzar con `check_ajax_referer('ltms_nonce', 'nonce')`.
4. **Sanitizar toda entrada.** Usar `sanitize_text_field()`, `absint()`, `wp_unslash()`, `sanitize_email()` según el tipo.
5. **Respuestas AJAX con helpers de WP.** Solo `wp_send_json_success()` / `wp_send_json_error()`; nunca `echo` directo ni `die()`.
6. **Guard en plantillas PHP.** Todo archivo de plantilla debe abrir con `if ( ! defined( 'ABSPATH' ) ) exit;`.
7. **Sin debug en producción.** Antes del commit: eliminar `var_dump`, `print_r`, `die()`, `error_log()` de diagnóstico temporal.
8. **Autoloader + commit atómico.** Si agregas una clase nueva, registra la excepción en el autoloader en el mismo commit.
9. **Webhook = commit primero.** Si el cambio se despliega vía webhook, el código debe estar en el commit antes de invocar el webhook.
10. **Evitar secuencias de escape problemáticas.** No usar `\f`, `\n` en strings PHP de producción cuando puedan interferir con el parser; usar `\x0c` u otras formas explícitas.
11. **Fail-closed en webhooks.** Todo handler de webhook (`includes/api/webhooks/`) debe rechazar (401/403) si el secreto/token esperado no está configurado, en vez de aceptar la petición por defecto (patrón `fail-open`, ya corregido en ZapSign en v2.9.1 tras una vulnerabilidad real de auto-aprobación de KYC forjando webhooks — ver `class-ltms-zapsign-webhook-handler.php`).

---

## Tests

El proyecto usa PHPUnit 9.6 (`phpunit.xml`), con Brain Monkey/Mockery para mocking de WordPress. Bootstrap: `tests/bootstrap.php`.

**Importante:** el proyecto NO usa `@group` de PHPUnit para agrupar por módulo (kyc/commissions/aveonline); los tests están organizados **un archivo por clase** bajo `tests/unit/` (ej. `KYCComplianceTest.php`, `CommissionStrategyTest.php`, `AveonlineApiTest.php`, `ZapsignApiTest.php`, `BackblazeApiTest.php`, ~65 archivos) y `tests/integration/` (`KernelIntegrationTest.php`, `CapsRolesIntegrationTest.php`). Usa `--filter` para ejecutar un subconjunto, o el testsuite `unit`/`integration`/`all` definido en `phpunit.xml`.

```bash
# Suite completa (equivalente a testsuite "all": unit + integration)
./vendor/bin/phpunit --configuration phpunit.xml --testsuite all

# Solo unit o solo integration
./vendor/bin/phpunit --testsuite unit
./vendor/bin/phpunit --testsuite integration

# Filtrar por clase de test (reemplaza el patrón antiguo --group)
./vendor/bin/phpunit --filter KYCComplianceTest
./vendor/bin/phpunit --filter CommissionStrategyTest
./vendor/bin/phpunit --filter AveonlineApiTest
```

También hay scripts de QA "manuales" fuera de PHPUnit en `tests/` (`tests/qa-*.php`, ejecutados vía WP-CLI o browser, no vía `phpunit`) y en `bin/` (decenas de `ltms-qa-*.php`, `ltms-diag-*.php` — ver sección Deploy/Bin abajo).

**Regla:** si la suite pasaba antes de tu cambio, debe seguir pasando después. Si introduces funcionalidad nueva, agrega el test correspondiente en el mismo commit.

---

## Checklist Pre-Commit

Antes de ejecutar `git commit`, confirma mentalmente cada ítem:

- [ ] `php -l` sin errores en **todos** los archivos `.php` modificados
- [ ] Clase nueva registrada en el autoloader (si aplica)
- [ ] Módulo nuevo registrado en el kernel (si aplica)
- [ ] Sin `var_dump`, `print_r`, `die()`, ni `error_log()` de diagnóstico temporal
- [ ] Queries SQL usan `$wpdb->prepare()`
- [ ] Handlers AJAX tienen `check_ajax_referer()` al inicio
- [ ] Handlers de webhook son fail-closed si falta configuración de token/secreto
- [ ] Sin credenciales ni tokens hardcodeados
- [ ] Logs del servidor sin nuevos errores fatales relacionados al cambio
- [ ] PHPUnit pasa (o los tests nuevos cubren el cambio)
- [ ] Mensaje de commit sigue Conventional Commits con scope correcto

---

## Referencia Rápida de Módulos

Estructura real verificada en `includes/` (316 archivos). Más granular que un resumen genérico — usar esta tabla para decidir en qué subdirectorio vive una clase nueva:

| Módulo | Directorio | Propósito | Notas |
|--------|-----------|-----------|-------|
| Core / Kernel | `includes/core/` | Boot, config, logger, security, firewall, GDPR, cache, cron manager, HPOS compat, TOTP 2FA, retención de datos | Subdirs: `adapters/, commands/, dto/, interfaces/, migrations/, repositories/, services/, traits/, utils/, value-objects/` |
| Business | `includes/business/` | Wallet, comisiones, payouts, ReDi, MLM, order-split, cumplimiento fiscal/legal/tributario (muchas clases `*-compliance.php`), Aveonline (agentes/carriers/ciudades/guías/oficinas/orden-compra/sandbox), Alegra, Deprisa, PosGold, donaciones, XCover, ZapSign manager | Subdirs: `events/, listeners/, strategies/` |
| Admin | `includes/admin/` | Paneles WP-admin, AJAX admin, KYC, auditoría, settings, payouts, bookings, cross-border, fiscal SAT, shipping ledger, legal evidence | `views/` con subcarpeta `views/settings/` (una vista por integración: alegra, aveonline, backblaze, deprisa, google_oauth, heka, kyc, mlm, siigo, uber_direct, xcover, zapsign, etc.) |
| Frontend | `includes/frontend/` | Dashboard SPA (jQuery), vendor panel, páginas públicas, live search, wishlist, SEO/sitemap, quick view, trust badges, storefront de vendedor | `views/` con subcarpeta `views/vendor-parts/` (login/register) |
| API | `includes/api/` | Clientes HTTP a servicios externos (Addi, Alegra, Aveonline, Backblaze, Deprisa, Heka, Openpay, PosGold, Siigo, Stripe, TPTC, Uber, XCover, ZapSign) | Subdirs: `builders/, factories/, gateways/, payloads/, webhooks/` (un handler de webhook por proveedor) |
| Booking | `includes/booking/` | Calendario de reservas, gestor de temporadas, políticas de cancelación, notificaciones | |
| Shipping | `includes/shipping/` | Métodos de envío WooCommerce: Deprisa, Aveonline, envío gratis absorbido, delivery propio, pickup, Uber Direct, cotizador paralelo | |
| Gateway | `includes/gateway/` | Pasarelas de pago genéricas (Stripe) | Ver también `api/gateways/` para Openpay/Addi/PSE |
| Deprisa | `includes/deprisa/` | Módulo Deprisa "legado" con su propio loader, settings, devoluciones, order-split y metabox | ⚠️ Existe **duplicación parcial** con `includes/shipping/class-ltms-deprisa-shipping-method.php` y `includes/business/class-ltms-deprisa-shipping.php` — confirma cuál está realmente enganchado en el kernel antes de modificar lógica de Deprisa |
| Roles | `includes/roles/` | Registro de roles custom (`ltms_vendor`, `ltms_external_auditor`, etc.) y reparación de capabilities | |
| Settings | `includes/settings/` | `class-ltms-settings-deprisa.php` (duplicado también en `includes/deprisa/`) | |
| WC Types | `includes/wc-types/` | `class-ltms-product-bookable.php` — tipo de producto reservable | |
| Templates | `templates/` | Plantillas de email, PDF | |
| Deploy | `deploy/` | Webhook de auto-deploy, scripts de diagnóstico/parche puntuales, endpoint de flush de OPcache | Ver detalle abajo |
| Bin | `bin/` | Scripts CLI de diagnóstico, QA manual y fixes puntuales (decenas de archivos `ltms-diag-*`, `ltms-qa-*`, `ltms-fix-*`) | Muchos son de un solo uso histórico; no asumas que siguen aplicando sin revisarlos |
| Tests | `tests/` (directorio real, no solo `.rar`) | PHPUnit (`tests/unit/`, `tests/integration/`), QA manual (`tests/qa-*.php`), E2E Cypress (`tests/e2e/`) | Ver sección Tests arriba |
| Docs | `docs/` | `docs/architecture/ARCHITECTURE.md`, `docs/SECURITY.md`, `docs/api/openapi.yaml` | Ambos docs actualizados a 2.9.35 (2026-07-06) en esta revisión — ahora incluyen PosGold pattern, TOTP 2FA, SAT México columns y autoloader. Útiles como referencia conceptual (hexagonal architecture, patrón ACID del wallet, RBAC) |

### Contenido de `deploy/` (relevante para el issue de ZapSign 401)

| Archivo | Propósito |
|---------|-----------|
| `ltms-deploy-webhook.php` | Webhook principal de auto-deploy (v5). Modos: sin parámetros = deploy normal (descarga archivos de una lista fija desde GitHub, se auto-actualiza, invalida OPcache por archivo, resetea OPcache global, purga cache de SiteGround); `?qa=1` = corre diagnóstico de KYC/Backblaze; `?fix_sellers=1` = diagnóstico/fix de contenido de la landing de vendedores; `?caps=1` = repara capabilities de WooCommerce en el rol administrator directamente vía SQL. |
| `ltms-opcache-flush.php` | **Endpoint HTTP dedicado a invalidar OPcache en el pool PHP-FPM web** (token `ltms_opcache_2026`). Actualmente hardcodeado para invalidar específicamente `includes/api/class-ltms-api-zapsign.php` y reportar si el override de `perform_request` está presente en el archivo cargado. Es exactamente el mecanismo que la investigación del 401 de ZapSign necesitaba — generalízalo (parámetro de archivo) si se vuelve a necesitar para otra clase. |
| `htaccess-webhook-bypass.txt` | Snippet a pegar en el `.htaccess` de `public_html` (antes de las reglas de WordPress) para excluir `ltms-deploy-webhook.php` del bot-protection/CAPTCHA de SiteGround. |
| `ltms-patch-db-v2-3-0.php` … `ltms-patch-db-v2-7-0.php` | Parches de migración de DB puntuales por versión. |
| `ltms-caps-fix.php`, `ltms-kyc-patch.php`, `ltms-fix-sellers-now.php`, `ltms-force-update.php`, `ltms-panel-diag.php`, `ltms-js-diag.php`, `ltms-backfill-store-slugs.php`, `ltms-seed-dane.php`, `ltms-qa.php` | Scripts puntuales de parche/diagnóstico/seed, históricos — revisar si siguen vigentes antes de reutilizarlos. |

---

## Estado conocido de ZapSign / OPcache (actualizado tras lectura del código real)

El handler `includes/api/webhooks/class-ltms-zapsign-webhook-handler.php` (v2.9.1, comentario "FU1 FIX CRÍTICO") **ya es fail-closed** y, desde ese fix, **registra un log detallado en cada 401 por mismatch de token**: busca `ZAPSIGN_WEBHOOK_401_DEBUG` en los logs de `LTMS_Core_Logger` (no en `error_log` de PHP) — incluye el token esperado y recibido enmascarados, la fuente (`ltms_zapsign_webhook_secret` vs fallback a `ltms_zapsign_api_token`), las cabeceras recibidas y las claves del body. **Antes de seguir asumiendo que la causa raíz es staleness de OPcache, revisa esos logs**: si aparecen entradas `ZAPSIGN_WEBHOOK_401_DEBUG`, el problema es de configuración/mismatch de token (posiblemente cuál opción se está usando como fuente), no de OPcache. Si NO aparecen entradas en absoluto pese a llegar webhooks, eso sí apunta a que el código servido por PHP-FPM es una versión anterior sin este logging — ahí es donde `deploy/ltms-opcache-flush.php` es la herramienta correcta.

El fix del bucket vacío de Backblaze (`ltms_backblaze_contratos_bucket`) **ya está en el código actual**: `class-ltms-zapsign-manager.php` línea ~317 usa `LTMS_Core_Config::get('ltms_backblaze_contratos_bucket', 'lotengo-contratos') ?: 'lotengo-contratos'` con el comentario `BC-01-FIX`. Confirma en producción que este es el código realmente cargado (mismo problema de OPcache que aplica a todo lo demás).

---

## Referencia Rápida de Arquitectura

*(Resumen de `docs/architecture/ARCHITECTURE.md`, actualizado a versión 2.9.35 / 2026-07-06. Verifica detalles contra el código antes de citarlos como hechos actuales.)*

- **Patrón general:** Arquitectura Hexagonal (Ports & Adapters). Lado "driving": hooks de WordPress, REST API, WP-Admin, SPA de vendedor. Núcleo de aplicación: `LTMS_Wallet`, `LTMS_Tax_Engine`, `LTMS_Commission_Strategy`, `LTMS_Referral_Tree`, `LTMS_Payout_Scheduler`. Lado "driven": MySQL/wpdb + APIs externas (Openpay, Siigo, Addi, ZapSign, XCover, TPTC, Aveonline, Backblaze).
- **Wallet ACID:** usa `SELECT ... FOR UPDATE` (locking pesimista) + transacción MySQL explícita para debitar/creditar saldo y escribir el ledger en la misma operación.
- **Tax Engine:** patrón Strategy por país (`LTMS_Tax_Strategy_Colombia`, `LTMS_Tax_Strategy_Mexico`, y también EU/BR/US en `business/strategies/`). Colombia: ReteFuente servicios 4% (base > 4 UVT), ReteIVA 15% del IVA, ReteICA 11.04‰ variable por CIIU, Impoconsumo 8% (restaurantes). México: ISR art. 113-A escalonado 2/4/6/10% según ingreso mensual, IVA 16%, IEPS variable. **Estas tasas están fechadas a 2025 en el doc — verifica contra la normativa vigente antes de asumirlas correctas para el año en curso.**
- **Roles RBAC:** `ltms_vendor`, `ltms_vendor_premium`, `ltms_external_auditor` (solo lectura de audit logs), `ltms_compliance_officer` (KYC + SAGRILAFT), `ltms_support_agent`.
- **Seguridad:** cifrado AES-256-CBC con derivación PBKDF2 (10,000 iteraciones) para cuentas bancarias, números de documento y credenciales de API — clave maestra en constante `WP_LTMS_MASTER_KEY` de `wp-config.php`, nunca en DB. WAF (`LTMS_Firewall`) inspeccionando en `init` prioridad 1. Log forense inmutable con trigger MySQL `prevent_log_update` sobre `lt_security_events`.
- **MLM:** árbol de referidos con `ancestor_path` tipo materialized path (ej. `"1/5/12"`) para distribución de comisión en O(1). Reparto documentado: nivel 1 = 40%, nivel 2 = 20%, nivel 3 = 10% del platform fee (verificar contra `class-ltms-referral-tree.php` si se va a modificar).

---

## Patrones Frecuentes

### Agregar un nuevo campo de configuración

1. Agregar la opción con valor default en la activación (`includes/core/services/class-ltms-activator.php`) o en `ltms_init_default_options()`.
2. Agregar el campo en la vista admin correspondiente (`includes/admin/views/settings/section-<integracion>.php` — ya existe una vista por integración, revisa si tu campo encaja en una existente antes de crear una nueva).
3. Agregar el sanitizador en `includes/admin/class-ltms-admin-settings.php` → `sanitize_settings()` (no en `class-ltms-config.php`; ver sección "Tasas y comisiones" arriba para las reglas de detección automática de porcentaje/booleano/campo cifrado).
4. Leer con `LTMS_Core_Config::get('nueva_opcion', 'default')` en el código de negocio.

### Agregar un nuevo handler AJAX

```php
// En la clase correspondiente, método init():
add_action( 'wp_ajax_ltms_mi_accion',        [ $this, 'ajax_mi_accion' ] );
add_action( 'wp_ajax_nopriv_ltms_mi_accion', [ $this, 'ajax_mi_accion' ] ); // solo si aplica a no-logueados

// Handler:
public function ajax_mi_accion(): void {
    check_ajax_referer( 'ltms_nonce', 'nonce' );
    $param = sanitize_text_field( wp_unslash( $_POST['param'] ?? '' ) );
    // lógica...
    wp_send_json_success( [ 'resultado' => $param ] );
}
```

### Agregar un nuevo handler de webhook

Sigue el patrón fail-closed de `class-ltms-zapsign-webhook-handler.php` (referencia post-fix v2.9.1):
1. Si el secreto/token esperado no está configurado → rechazar con 401 y loguear (`LTMS_Core_Logger::warning`), nunca aceptar por defecto.
2. Comparar con `hash_equals()`, nunca `===` ni `==`.
3. En el mismatch, loguear un log de debug con valores **enmascarados** (no el secreto completo) para diagnosticar sin exponer credenciales — ver el patrón `$mask()` en el handler de ZapSign.
4. Idempotencia vía transient (`ltms_wh_seen_{proveedor}_{hash(event_id)}`) porque la mayoría de proveedores reintentan varias veces.
5. Rate limiting per-IP igual que los demás handlers.

### Deploy manual de emergencia (si el webhook falla)

```bash
ssh -p 18765 u1549-ruo8hvwpk9dt@ssh.lo-tengo.com.co
cd /home/customer/www/lo-tengo.com.co/public_html/wp-content/plugins/lt-marketplace-suite
git fetch origin && git reset --hard origin/main
wp cache flush --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html
wp plugin deactivate lt-marketplace-suite --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html
wp plugin activate  lt-marketplace-suite --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html
tail -n 30 /home/customer/www/lo-tengo.com.co/logs/error_log
```

> **Advertencia OPcache:** En SiteGround compartido, `git reset --hard` puede no reflejar cambios si OPcache retiene el bytecode compilado **en el pool PHP-FPM web**, que es distinto del pool que usa WP-CLI/SSH. `wp cache flush` no basta para ese pool. Usa `deploy/ltms-opcache-flush.php?token=ltms_opcache_2026` (ver tabla de `deploy/` arriba) para forzar el flush vía HTTP, o contacta soporte de SiteGround / usa la herramienta de purge del panel si el problema persiste.

---

## Lecciones aprendidas en v2.9.35 (corregidas, no repetir)

- **Constante de path del plugin:** usar siempre `LTMS_PLUGIN_DIR`. Las constantes `LTMS_PATH` y `LTMS_PLUGIN_DIR_PATH` NO existen y provocan fatal en cualquier código que las referencie. Si ves `LTMS_PATH` en código heredado, reemplázalo por `LTMS_PLUGIN_DIR`.
- **Nonce action para AJAX del storefront:** el action correcto es `ltms_ux_nonce`, NO `ltms_storefront_nonce`. Si un handler usa `check_ajax_referer('ltms_storefront_nonce', 'nonce')`, todos los AJAX del storefront fallan con 403 silencioso.
- **Visibilidad de métodos importa:** `LTMS_Core_Firewall::get_client_ip()` debe ser `public` (no `private`). Es llamada desde `LTMS_Data_Masking` para enmascarar IPs en logs. Si la declaras `private`, el WSOD es inmediato en cualquier página que dispare data masking.
- **`continue 2` requiere 2+ niveles de loop anidado.** Usar `continue 2` dentro de un `foreach` simple (1 nivel) es un error fatal: `Fatal error: 'continue 2' is in the wrong context`. Usa `continue;` (sin número) si solo hay 1 loop.
- **Sincronización `.min.js` / `.min.css`:** los archivos minificados deben regenerarse y commitearse en el mismo commit que sus fuentes `.js` / `.css`. Si solo actualizas la fuente y olvidas el `.min`, en producción se sirve la versión vieja (SiteGround sirve el `.min` por defecto si `SCRIPT_DEBUG` no está definido). Los `.min.*` están force-tracked (fueron removidos de `.gitignore` en v2.9.35).
- **Nuevas vistas del dashboard de vendedor (v2.9.35):** `view-marketing.php`, `view-security.php`, `view-donations.php`, `view-posgold.php` en `includes/frontend/views/`. Cada una tiene su endpoint AJAX y su entrada en el menú lateral del dashboard SPA.

---

## Notas de auditoría abiertas (a validar, no confirmadas en producción)

- `ltms-caps-fix.php` (parche de capabilities de administrador) — el propio Claude no puede verificar en runtime porque todo el tráfico HTTP saliente hacia `lo-tengo.com.co` desde este entorno devuelve 403; Active Soy debe confirmar desde su navegador o SSH.
- KYC funcional end-to-end pero con columnas `file_path`, `rut_path`, `camara_path`, `selfie_path` vacías en `lt_vendor_kyc` — posible fallo silencioso de subida a Backblaze B2 (credenciales no configuradas o tipo de archivo rechazado). El script `bin/ltms-qa-backblaze.php` / `deploy/ltms-deploy-webhook.php?qa=1` (modo QA, hace un upload de prueba de 1px PNG) son el punto de partida para diagnosticar esto.
- Vitrina Pública (páginas públicas de tienda/producto) — pendiente de auditoría completa.
- Error 403 de SiteGround en páginas de Temporadas/Políticas — pendiente de investigar.
- Duplicación de módulo Deprisa entre `includes/deprisa/`, `includes/shipping/`, `includes/business/` y `includes/settings/` — confirma cuál instancia está realmente enganchada en el kernel (`boot_business_logic()` / `boot_frontend()`) antes de modificar lógica de envíos Deprisa, para no editar una copia muerta.
