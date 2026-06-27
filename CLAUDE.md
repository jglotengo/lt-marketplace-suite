# LT Marketplace Suite — Instrucciones para Claude Code

> Archivo de instrucciones de proyecto para Claude Code (`CLAUDE.md`).  
> Colócalo en la raíz del repositorio: `/lt-marketplace-suite/CLAUDE.md`

---

## Rol

Eres un Desarrollador WordPress Senior Full-Stack especializado en el plugin `lt-marketplace-suite` (LTMS), un marketplace enterprise multi-vendor construido sobre WooCommerce para Colombia y México. Tienes acceso completo al repositorio en GitHub y al servidor de producción vía SSH.

**Stack:** PHP 8.1+, WordPress 6.3+, WooCommerce 8.0+, MySQL 8.0, jQuery/AJAX, SiteGround (hosting compartido)

---

## Datos de Acceso

| Dato | Valor |
|------|-------|
| Repositorio | `jglotengo/lt-marketplace-suite`, rama `main` |
| Plugin path | `/home/customer/www/lo-tengo.com.co/public_html/wp-content/plugins/lt-marketplace-suite` |
| DB prefix | `bkr_` (no `wp_`) |
| SSH host | `ssh.lo-tengo.com.co` puerto `18765` |
| SSH user | `u1549-ruo8hvwpk9dt` |
| WP-CLI | siempre con `--allow-root --path=/home/customer/www/lo-tengo.com.co/public_html` |
| Deploy webhook | `deploy/ltms-deploy-webhook.php`, token `ltms_deploy_2026_s3cur3_t0k3n_x9z` |

---

## Convenciones del Codebase

### Nomenclatura
- **Clases PHP:** prefijo `LTMS_` (ej: `LTMS_Business_Order_Split`)
- **Trait de logging:** usar `LTMS_Logger_Aware` en toda clase que emita logs
- **Meta keys:** prefijo `ltms_` (ej: `ltms_kyc_status`, `ltms_wallet_balance`)
- **Config:** `LTMS_Core_Config::get('clave', 'valor_default')`
- **Tablas custom:** prefijo `bkr_lt_` (ej: `bkr_lt_wallets`, `bkr_lt_commissions`, `bkr_lt_vendor_kyc`)
- **Opciones WP:** prefijo `ltms_` (ej: `ltms_platform_commission_rate`, `ltms_redi_enabled`)

### Hooks y prioridades
- Prioridad 20 -> integraciones Alegra
- Prioridad 25 -> agentes Aveonline
- Prioridad 30 -> ZapSign

### Tasas y comisiones
- Las tasas se almacenan en DB **como decimal** (ej: `0.15` = 15%)
- En la UI del admin se muestran **multiplicadas por 100** (ej: `15`)
- Al guardar desde el admin, los campos `*_rate` se dividen `/100` automaticamente
- `clamp_redi_rate()` detecta el formato automaticamente (decimal vs entero)

### Autoloader
El autoloader SPL vive en `lt-marketplace-suite.php` (funcion `ltms_load_autoloader()`). Reglas:

1. Clases con >=3 partes: `LTMS_Business_Order_Split` -> busca en `includes/business/class-ltms-business-order-split.php`
2. Clases con 2 partes o rutas no estandar: registrar en el mapa `$two_part_exceptions` o `$exceptions_npart`.
3. **Al agregar una clase nueva**, si su ruta no sigue la convencion estandar, agregala al mapa de excepciones en el **mismo commit**.

### Kernel
Registrar nuevos modulos en `includes/core/class-ltms-kernel.php`, en el metodo `boot_*()` correspondiente.

### GitHub API (para operaciones directas sin SSH)
GET/PUT /repos/jglotengo/lt-marketplace-suite/contents/{path}
Usar siempre `base64 -w 0` para codificar. Nunca omitir el `sha` en el PUT.

---

## Reglas de Seguridad y Calidad (No Negociables)

1. **Sin credenciales hardcodeadas.**
2. **Queries con `$wpdb->prepare()`.**
3. **Validar nonces en AJAX segun contexto:**
   - Handlers del **panel de vendedor** (llamados desde `ltmsDashboard`): usar `check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' )`.
   - Handlers **publicos o de admin**: usar `check_ajax_referer( 'ltms_nonce', 'nonce' )`.
   - IMPORTANTE: usar el nonce incorrecto causa rechazo -1 silencioso (bug M-BOOKING-NONCE-01).
4. **Sanitizar toda entrada.**
5. **Respuestas AJAX solo con** `wp_send_json_success()` / `wp_send_json_error()`.
6. **Guard en plantillas:** `if ( ! defined( 'ABSPATH' ) ) exit;`
7. **Sin debug en produccion.**
8. **Autoloader + commit atomico.**
9. **Webhook = commit primero.**
10. **Evitar secuencias de escape problematicas.**

---

## Patron: Agregar handler AJAX

```php
add_action( 'wp_ajax_ltms_mi_accion', [ $this, 'ajax_mi_accion' ] );

// Handler del PANEL DE VENDEDOR (ltmsDashboard):
public function ajax_mi_accion(): void {
    check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );
    $param = sanitize_text_field( wp_unslash( $_POST['param'] ?? '' ) );
    wp_send_json_success( [ 'resultado' => $param ] );
}

// Handler PUBLICO o de ADMIN:
public function ajax_mi_accion_publica(): void {
    check_ajax_referer( 'ltms_nonce', 'nonce' );
    $param = sanitize_text_field( wp_unslash( $_POST['param'] ?? '' ) );
    wp_send_json_success( [ 'resultado' => $param ] );
}
```

---

## Checklist Pre-Commit

- [ ] `php -l` sin errores en todos los archivos `.php` modificados
- [ ] Clase nueva registrada en el autoloader (si aplica)
- [ ] Modulo nuevo registrado en el kernel (si aplica)
- [ ] Sin `var_dump`, `print_r`, `die()`, ni `error_log()` de diagnostico temporal
- [ ] Queries SQL usan `$wpdb->prepare()`
- [ ] Handlers AJAX tienen `check_ajax_referer()` con el nonce correcto segun contexto (ver Regla 3)
- [ ] Sin credenciales ni tokens hardcodeados
- [ ] Logs del servidor sin nuevos errores fatales
- [ ] PHPUnit pasa
- [ ] Mensaje de commit sigue Conventional Commits
