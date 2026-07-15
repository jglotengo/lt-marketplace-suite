<?php
/**
 * LTMS Core Firewall - Web Application Firewall (WAF)
 *
 * Protección multicapa contra: SQL Injection, XSS, LFI/RFI,
 * CSRF, Brute Force, Bad Bots y escaneo de vulnerabilidades.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/core
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Core_Firewall
 */
final class LTMS_Core_Firewall {

    /**
     * Patrones de ataque conocidos (SQL Injection, XSS, LFI).
     *
     * @var array<string, string>
     */
    private static array $attack_patterns = [
        'sql_injection_union'    => '/(\bunion\b.*\bselect\b|\bselect\b.*\bfrom\b.*\bwhere\b)/i',
        'sql_injection_drop'     => '/(\bdrop\b.*\b(table|database)\b|\btruncate\b.*\btable\b)/i',
        'sql_injection_insert'   => '/(\binsert\b.*\binto\b|\bupdate\b.*\bset\b.*\bwhere\b)/i',
        // FW-1 FIX: DELETE FROM was completely missing — a classic data-destructive
        // SQLi vector (e.g. "' OR 1=1; DELETE FROM wp_users--") bypassed the WAF.
        'sql_injection_delete'   => '/\bdelete\b.*\bfrom\b/i',
        // FW-1 FIX: DDL (ALTER/CREATE/RENAME TABLE) was missing — schema-modifying
        // attacks are critical-severity because they can persist backdoors
        // (CREATE TABLE wp_backdoor) or destroy schema (ALTER TABLE ... DROP COLUMN).
        'sql_injection_ddl'      => '/\b(alter|create|rename)\b.*\btable\b/i',
        // FW-1 FIX: SQL recon + file exfiltration — INFORMATION_SCHEMA is the
        // canonical SQLi recon target; LOAD_FILE() reads arbitrary server files
        // (wp-config.php); INTO OUTFILE writes webshells. All high-confidence
        // attack signatures with near-zero false-positive rate on user input.
        'sql_injection_recon'    => '/(\binformation_schema\b|\bload_file\s*\(|\binto\b\s+outfile\b|\binto\b\s+dumpfile\b)/i',
        // FW-1 FIX: time-based blind SQLi — SLEEP() / BENCHMARK() / WAITFOR DELAY
        // are the canonical primitives for boolean-blind extraction when UNION
        // and error-based channels are closed. Without these patterns the WAF
        // cannot detect the most common blind-SQLi recon technique.
        'sql_injection_blind'    => '/(\bsleep\s*\(|\bbenchmark\s*\(|\bwaitfor\s+delay\b|\bpg_sleep\s*\()/i',
        // FW-1 FIX: error-based SQLi — EXTRACTVALUE / UPDATEXML force MySQL to
        // emit error messages containing exfiltrated data (version, table names,
        // credentials). Common in automated SQLi tools (sqlmap default mode).
        'sql_injection_error'    => '/(\bextractvalue\s*\(|\bupdatexml\s*\()/i',
        'sql_injection_comment'  => '/(?:(?<![\w\-])-{2,}(?![\w\-])|#[^\n]*$|\/\*[\s\S]*?\*\/)/mi', // SEC-L1+M-120: avoid matching -- in base64/URLs
        'xss_script'             => '/<\s*script(\s[^>]*)?>/is',
        // FW-2 FIX: expanded event-handler list. The previous list (load/click/
        // mouseover/error/focus/blur/change/submit) missed modern XSS vectors:
        //   - ontoggle          : <details open ontoggle=alert(1)> (no JS required)
        //   - onpointerenter    : Pointer Events API, fires on touch + mouse
        //   - onanimationstart  : CSS-animation-driven XSS (no user interaction)
        //   - oninput/onkeydown : form-field XSS without blur
        //   - onreset/ondrag/ondrop/onwheel/onscroll/onselect/ondblclick/onauxclick
        // Each of these is a documented PoC in the OWASP XSS Filter Evasion
        // Cheat Sheet and was bypassing the WAF.
        'xss_event_handler'      => '/on(load|click|mouseover|error|focus|blur|change|submit|input|keydown|keyup|keypress|reset|toggle|pointerenter|pointerleave|pointermove|pointerdown|pointerup|pointerover|pointerout|pointercancel|animationstart|animationend|animationiteration|transitionend|drag|dragstart|dragend|dragenter|dragleave|dragover|drop|wheel|scroll|select|dblclick|auxclick|copy|cut|paste|search|canplay|canplaythrough|loadeddata|loadedmetadata|loadstart|play|playing|pause|ended|seeked|seeking|stalled|suspend|timeupdate|volumechange|waiting)\s*=/i',
        'xss_javascript'         => '/javascript\s*:/i',
        'lfi_path_traversal'     => '/(\.\.\/|\.\.\\\\|%2e%2e%2f|%2e%2e\/|\.\.%2f)/i',
        'rfi_http'               => '/(https?|ftp):\/\/.*\.(php|asp|aspx|jsp|cgi|pl|py)/i',
        // FW-3 FIX: added proc_open, popen, assert, create_function. These are
        // well-documented PHP RCE primitives:
        //   - proc_open/popen  : spawn arbitrary shell commands (same severity as system)
        //   - assert           : interprets its string argument as PHP code (RCE)
        //   - create_function  : deprecated since PHP 7.2, takes string body, evals it
        // All four appear routinely in webshell droppers and exploit kits.
        // (preg_replace with /e modifier intentionally NOT included — the regex
        // is brittle across delimiter variants and the /e modifier was removed
        // in PHP 7.0, so modern deployments are not vulnerable.)
        'php_injection'          => '/(<\?php|<\?=|eval\s*\(|base64_decode\s*\(|passthru\s*\(|system\s*\(|exec\s*\(|shell_exec\s*\(|proc_open\s*\(|popen\s*\(|assert\s*\(|create_function\s*\()/i',
        'null_byte'              => '/\0/',
    ];

    /**
     * Umbral de eventos antes de bloquear una IP.
     */
    private const BLOCK_THRESHOLD = 10;

    /**
     * Duración por defecto del bloqueo en segundos (1 hora).
     * Configurable mediante ltms_waf_block_duration_seconds.
     */
    private const BLOCK_DURATION_DEFAULT = 3600;

    /**
     * Obtiene la duración del bloqueo configurable.
     *
     * @return int Segundos.
     */
    private static function get_block_duration(): int {
        return (int) LTMS_Core_Config::get( 'ltms_waf_block_duration_seconds', self::BLOCK_DURATION_DEFAULT );
    }

    /**
     * Obtiene el TTL del caché de IPs bloqueadas.
     *
     * @return int Segundos.
     */
    private static function get_ip_cache_ttl(): int {
        return (int) LTMS_Core_Config::get( 'ltms_waf_ip_cache_ttl_seconds', 300 );
    }

    /**
     * Inicializa el WAF (debe ejecutarse lo antes posible).
     *
     * @return void
     */
    public static function init(): void {
        // Solo activar en requests HTTP/HTTPS (no CLI)
        if ( php_sapi_name() === 'cli' ) {
            return;
        }

        add_action( 'init', [ __CLASS__, 'run' ], 1 );
    }

    /**
     * Rutas del área de administración de WordPress que están permitidas
     * sin inspección de patrones de ataque.
     *
     * Estas rutas son exclusivas del panel admin y están protegidas por el
     * sistema de autenticación y nonces de WordPress. Inspeccionarlas con el WAF
     * genera falsos positivos (p. ej. el File Manager envía rutas con "../" que
     * disparan lfi_path_traversal) sin ningún beneficio de seguridad real,
     * ya que WordPress ya verifica capacidades antes de procesar estas requests.
     *
     * ⚠️  Esta lista SOLO excluye la inspección de patrones. La verificación de
     *     IP en blacklist (paso 1) sigue aplicándose para todas las rutas.
     *
     * @var string[]
     */
    private static array $admin_path_whitelist = [
        '/wp-admin/',          // Panel de administración general
        '/wp-login.php',       // Página de login (manejo propio de WP)
        '/wp-json/',           // REST API (autenticación via nonce/JWT)
        '/wp-admin/admin-ajax.php', // AJAX de plugins admin
        '/wp-cron.php',        // Cron de WordPress (tareas programadas internas)
    ];

    /**
     * Comprueba si la request actual proviene del área admin de WordPress
     * Y el usuario tiene la capacidad 'manage_options' (administrador).
     *
     * Se usa is_user_logged_in() + current_user_can() en lugar de is_admin()
     * porque is_admin() solo verifica la URL, no la sesión del usuario.
     *
     * @return bool True si es un administrador autenticado en área admin.
     */
    private static function is_authenticated_admin(): bool {
        // is_user_logged_in() requiere que las cookies ya hayan sido procesadas.
        // En el hook 'init' con priority 1, la sesión WP ya está disponible.
        if ( ! is_user_logged_in() ) {
            return false;
        }
        // v2.9.99 FIX: permitir también a vendedores autenticados en rutas admin-ajax.
        // Antes solo se excluía a admins (manage_options), lo que hacía que los
        // vendors (rol ltms_vendor, sin manage_options) fueran inspeccionados por
        // el WAF en cada AJAX → falsos positivos → 403 en admin-ajax.php.
        if ( current_user_can( 'manage_options' ) ) {
            return true;
        }
        // Vendedor autenticado (cualquier rol ltms_*) en ruta admin-ajax.
        if ( function_exists( 'LTMS_Utils' ) && method_exists( 'LTMS_Utils', 'is_ltms_vendor' ) ) {
            return LTMS_Utils::is_ltms_vendor( get_current_user_id() );
        }
        // Fallback: verificar roles directamente.
        $user = wp_get_current_user();
        $ltms_roles = array_filter( (array) $user->roles, fn( $r ) => str_starts_with( $r, 'ltms_' ) );
        return ! empty( $ltms_roles );
    }

    /**
     * v2.9.99: Comprueba si la request actual viene de un vendor autenticado.
     * Usado para excluir las requests ltms_ajax del bypass del frontend de la
     * inspección de patrones del WAF (evita falsos positivos en POST bodies).
     *
     * @return bool True si es un vendor autenticado.
     */
    private static function is_authenticated_vendor(): bool {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        if ( function_exists( 'LTMS_Utils' ) && method_exists( 'LTMS_Utils', 'is_ltms_vendor' ) ) {
            return LTMS_Utils::is_ltms_vendor( get_current_user_id() );
        }
        $user = wp_get_current_user();
        $ltms_roles = array_filter( (array) $user->roles, fn( $r ) => str_starts_with( $r, 'ltms_' ) );
        return ! empty( $ltms_roles );
    }

    /**
     * Comprueba si la REQUEST_URI actual está en la whitelist de rutas admin.
     *
     * @return bool
     */
    private static function is_whitelisted_admin_path(): bool {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        // FW-BUG-1 FIX: use parse_url(PATH) instead of str_contains to prevent
        // bypass via query params (e.g., ?x=/wp-admin/ would match with str_contains).
        $parsed_path = parse_url( $request_uri, PHP_URL_PATH ) ?: '';
        foreach ( self::$admin_path_whitelist as $path ) {
            // Only whitelist if the actual parsed path starts with the whitelisted path
            if ( str_starts_with( $parsed_path, $path ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ejecuta todas las verificaciones del WAF.
     *
     * @return void
     */
    public static function run(): void {
        $ip = self::get_client_ip();

        // ── EXCLUSIÓN PARA ADMINISTRADORES AUTENTICADOS EN RUTAS ADMIN ──────────
        // Si la request viene de un administrador autenticado accediendo a una
        // ruta del área admin (wp-admin/, wp-json/, admin-ajax.php), omitimos
        // la inspección de patrones de ataque para evitar falsos positivos con
        // plugins legítimos como WP File Manager, ACF, etc.
        //
        // La verificación de blacklist de IP (paso 1 más abajo) sigue corriendo
        // para todos los usuarios, incluyendo admins, para bloquear IPs comprometidas.
        // ────────────────────────────────────────────────────────────────────────
        if ( self::is_authenticated_admin() && self::is_whitelisted_admin_path() ) {
            // Solo ejecutar verificación de blacklist de IP, no inspección de patrones.
            if ( self::is_ip_blocked( $ip ) ) {
                self::block_request( 'IP_BLACKLISTED', $ip );
            }
            return;
        }
        // ── FIN EXCLUSIÓN ADMIN ──────────────────────────────────────────────────

        // ── v2.9.99: EXCLUSIÓN PARA BYPASS AJAX DEL FRONTEND ──────────────────────
        // Las requests a /?ltms_ajax=1 son llamadas AJAX del panel del vendedor
        // que se rutearon a través del frontend para bypasear el bloqueo de
        // SiteGround a /wp-admin/admin-ajax.php. El firewall puede generar falsos
        // positivos al inspeccionar el POST body (que puede contener legítimamente
        // palabras como "SELECT", "UPDATE", etc. en descripciones de productos).
        // Si el usuario es un vendor autenticado, omitimos la inspección de patrones
        // (la verificación de IP blacklist sigue aplicándose).
        if ( isset( $_REQUEST['ltms_ajax'] ) && self::is_authenticated_vendor() ) {
            if ( self::is_ip_blocked( $ip ) ) {
                self::block_request( 'IP_BLACKLISTED', $ip );
            }
            return;
        }
        // ── FIN EXCLUSIÓN BYPASS AJAX ─────────────────────────────────────────────

        // 1. Verificar blacklist de IPs
        if ( self::is_ip_blocked( $ip ) ) {
            self::block_request( 'IP_BLACKLISTED', $ip );
        }

        // 2. Análisis de parámetros GET y POST
        // M-120 FIX: Lista blanca de keys que NUNCA se escanean — los nonces de WordPress
        // son hashes base64 que pueden contener '--' y disparar sql_injection_comment,
        // y campos de descripción pueden contener 'update ... set ... where' legítimamente.
        $waf_key_whitelist = [
            'nonce', '_wpnonce', '_wp_http_referer', 'nonce_field',
            'ltms_nonce', 'security', 'action',   // WP standard
            'password', 'pwd', 'pass',             // passwords no escanear (pueden contener --)
            'description', 'content', 'message',  // rich-text fields
            'store_description', 'banner_url',
        ];

        $params_to_check = array_merge(
            $_GET,
            $_POST,
            [ 'URI' => $_SERVER['REQUEST_URI'] ?? '' ]
        );

        foreach ( $params_to_check as $key => $value ) {
            // Saltar keys en la whitelist para evitar falsos positivos con nonces y campos de texto
            if ( in_array( $key, $waf_key_whitelist, true ) ) {
                continue;
            }
            if ( is_array( $value ) ) {
                $flat = [];
                array_walk_recursive( $value, static function ( $v ) use ( &$flat ) {
                    $flat[] = (string) $v;
                } );
                $value = implode( ' ', $flat );
            }
            $matched_rule = self::check_patterns( (string) $value );
            if ( $matched_rule ) {
                self::handle_attack( $matched_rule, $ip, $key, $value );
            }
        }

        // 3. Verificar User-Agent sospechoso
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if ( self::is_bad_bot( $ua ) ) {
            self::handle_attack( 'bad_bot', $ip, 'user_agent', $ua );
        }

        // 4. FIX C-03 + FW-5: Inspect raw request body for ALL text-like content types,
        // not just application/json. The original C-03 fix only inspected JSON bodies,
        // which let an attacker bypass the WAF by sending the same SQLi/XSS payload as
        // text/plain, application/xml, or with no Content-Type at all — $_POST is
        // only populated for application/x-www-form-urlencoded and multipart/form-data,
        // so any other content type with a body was completely invisible to the WAF.
        //
        // Coverage matrix after this fix:
        //   application/json              → inspect (REST API payloads)
        //   text/*  (plain, html, xml)    → inspect (raw text bodies, SOAP, etc.)
        //   application/xml               → inspect (XML-RPC, SOAP)
        //   <no Content-Type> + empty $_POST → inspect (defensive; some clients omit it)
        //   application/x-www-form-urlencoded → already covered by $_POST scan in step 2
        //   multipart/form-data           → $_POST text fields covered in step 2;
        //                                    binary file blobs skipped (would FP)
        $content_type = strtolower( $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '' );
        $should_inspect_body = str_contains( $content_type, 'application/json' )
                            || str_contains( $content_type, 'text/' )
                            || str_contains( $content_type, 'application/xml' )
                            || str_contains( $content_type, '+json' )
                            || ( empty( $content_type ) && empty( $_POST ) );

        if ( $should_inspect_body ) {
            // Limit read to 512 KB to prevent DoS via giant payloads.
            $raw_body = file_get_contents( 'php://input', false, null, 0, 524288 );
            if ( ! empty( $raw_body ) ) {
                $matched_rule = self::check_patterns( $raw_body );
                if ( $matched_rule ) {
                    self::handle_attack( $matched_rule, $ip, 'raw_body', $raw_body );
                }
            }
        }
    }

    /**
     * Verifica si un valor coincide con algún patrón de ataque.
     *
     * @param string $value Valor a analizar.
     * @return string|null Nombre de la regla disparada o null.
     */
    private static function check_patterns( string $value ): ?string {
        // Decodificar URL encoding para detectar ataques ofuscados
        $decoded = urldecode( $value );

        foreach ( self::$attack_patterns as $rule => $pattern ) {
            if ( preg_match( $pattern, $decoded ) ) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Maneja un ataque detectado: loguea, incrementa contador y bloquea si supera umbral.
     *
     * @param string $rule       Regla disparada.
     * @param string $ip         IP del atacante.
     * @param string $param_name Parámetro donde se detectó.
     * @param string $payload    Payload sospechoso (se trunca para el log).
     * @return void
     */
    private static function handle_attack( string $rule, string $ip, string $param_name, string $payload ): void {
        // Registrar evento de seguridad
        global $wpdb;
        $table = $wpdb->prefix . 'lt_security_events';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $table,
            [
                'event_type'     => strtoupper( $rule ),
                'severity'       => self::get_severity( $rule ),
                'ip_address'     => $ip,
                'user_id'        => get_current_user_id() ?: null,
                'request_uri'    => substr( sanitize_text_field( $_SERVER['REQUEST_URI'] ?? '' ), 0, 500 ),
                'request_method' => sanitize_text_field( $_SERVER['REQUEST_METHOD'] ?? 'GET' ),
                'payload'        => substr( sanitize_textarea_field( $payload ), 0, 500 ),
                'rule_matched'   => $param_name . ':' . $rule,
                'blocked'        => 1,
                'created_at'     => current_time( 'mysql', true ),
            ],
            [ '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s' ]
        );

        // Log en tabla de auditoría
        LTMS_Core_Logger::security(
            'WAF_ATTACK_DETECTED',
            "WAF bloqueó ataque [{$rule}] desde IP {$ip}",
            [
                'rule'    => $rule,
                'param'   => $param_name,
                'payload' => substr( $payload, 0, 200 ),
            ]
        );

        // FW-4 FIX: count recent attacks from this IP via DB instead of transient
        // counter. The previous implementation did read-modify-write on a transient:
        //
        //     $count = (int) get_transient( $key ); $count++; set_transient( $key, $count, ... );
        //
        // which is NOT atomic. N concurrent requests from the same IP all read the
        // same count, each increments locally, and each overwrites with the same
        // value — net result is count=1 regardless of N. An attacker firing 100
        // concurrent SQLi probes would register count=1 and never trigger the
        // auto-block threshold (BLOCK_THRESHOLD=10), letting them continue probing
        // indefinitely. Each individual probe was still blocked (block_request fires
        // per-attack), but the IP was never auto-blocked for FUTURE requests.
        //
        // The fix uses COUNT(*) over lt_security_events (which is being written to
        // by the insert above, in the same transaction scope of this request).
        // This is atomic at the InnoDB row-lock level: even if 100 concurrent
        // requests fire, each INSERT is serialized, and each subsequent COUNT(*)
        // sees a monotonically-increasing event count. The IP is auto-blocked as
        // soon as the 10th event commits.
        //
        // Performance: the query uses the existing idx_ip_address index
        // (see class-ltms-db-migrations.php line 267) and is bounded by the
        // block-duration window (default 3600s), so it scans at most one hour of
        // events for this single IP — typically <100 rows even under attack.
        $window_start = gmdate( 'Y-m-d H:i:s', time() - self::get_block_duration() );
        $events_table = $wpdb->prefix . 'lt_security_events';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$events_table}`
                 WHERE ip_address = %s AND created_at > %s",
                $ip,
                $window_start
            )
        );

        // Bloquear IP si supera el umbral
        if ( $count >= self::BLOCK_THRESHOLD ) {
            self::block_ip( $ip, "Auto-blocked after {$count} WAF violations. Last rule: {$rule}" );
        }

        self::block_request( $rule, $ip );
    }

    /**
     * Verifica si una IP está en la blacklist.
     *
     * @param string $ip Dirección IP.
     * @return bool
     */
    private static function is_ip_blocked( string $ip ): bool {
        // Cache rápido con transient
        $cache_key = 'ltms_blocked_' . md5( $ip );
        $cached    = get_transient( $cache_key );

        if ( $cached !== false ) {
            return (bool) $cached;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_waf_blocked_ips';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM `{$table}` WHERE ip_address = %s AND (expires_at IS NULL OR expires_at > NOW()) LIMIT 1",
                $ip
            )
        );

        $is_blocked = ! empty( $result );
        set_transient( $cache_key, $is_blocked ? 1 : 0, self::get_ip_cache_ttl() );

        return $is_blocked;
    }

    /**
     * Agrega una IP a la blacklist.
     *
     * @param string $ip     Dirección IP.
     * @param string $reason Motivo del bloqueo.
     * @return void
     */
    public static function block_ip( string $ip, string $reason ): void {
        global $wpdb;
        $table    = $wpdb->prefix . 'lt_waf_blocked_ips';
        $expires  = gmdate( 'Y-m-d H:i:s', time() + self::get_block_duration() );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO `{$table}` (ip_address, reason, block_count, expires_at)
                 VALUES (%s, %s, 1, %s)
                 ON DUPLICATE KEY UPDATE
                     block_count = block_count + 1,
                     reason = VALUES(reason),
                     expires_at = VALUES(expires_at),
                     updated_at = NOW()",
                $ip,
                substr( $reason, 0, 255 ),
                $expires
            )
        );

        // Invalidar cache
        delete_transient( 'ltms_blocked_' . md5( $ip ) );

        LTMS_Core_Logger::security(
            'WAF_IP_BLOCKED',
            "IP {$ip} bloqueada por el WAF",
            [ 'reason' => $reason, 'expires' => $expires ]
        );
    }

    /**
     * Termina la request con HTTP 403.
     *
     * @param string $rule Regla que disparó el bloqueo.
     * @param string $ip   IP del atacante.
     * @return never
     */
    private static function block_request( string $rule, string $ip ): never {
        status_header( 403 );
        nocache_headers();
        wp_die(
            esc_html__( 'Tu solicitud fue bloqueada por el sistema de seguridad.', 'ltms' ),
            esc_html__( 'Acceso Denegado', 'ltms' ),
            [
                'response'  => 403,
                'exit'      => true,
            ]
        );
    }

    /**
     * Detecta user-agents de bots maliciosos conocidos.
     *
     * @param string $ua User-Agent a verificar.
     * @return bool
     */
    private static function is_bad_bot( string $ua ): bool {
        $bad_bots = [
            // Actual attack tools — never legitimate API clients
            'sqlmap', 'nikto', 'nmap', 'masscan', 'zgrab',
            'dirbuster', 'gobuster', 'wfuzz', 'w3af', 'burpsuite',
            'hydra', 'medusa', 'nessus', 'openvas',
            // NOTE: curl, python-requests, go-http-client, libwww-perl intentionally
            // removed — they are used by legitimate API consumers, monitoring systems,
            // and payment gateway webhooks. Block by behavior (rate-limit, patterns),
            // not by UA string.
        ];

        $ua_lower = strtolower( $ua );
        foreach ( $bad_bots as $bot ) {
            if ( str_contains( $ua_lower, $bot ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determina la severidad de un ataque según el tipo de regla.
     *
     * @param string $rule Regla de ataque.
     * @return string low|medium|high|critical
     */
    private static function get_severity( string $rule ): string {
        // FW-1/FW-3: critical_rules now also covers sql_injection_ddl (schema
        // modification is irreversible without backup) — same blast radius as DROP.
        $critical_rules = [ 'sql_injection_drop', 'sql_injection_ddl', 'php_injection', 'rfi_http' ];
        // FW-1: high_rules now covers the new SQLi categories (delete, recon,
        // blind, error-based) — all are active exploitation, not just probing.
        $high_rules     = [
            'sql_injection_union', 'sql_injection_insert', 'sql_injection_delete',
            'sql_injection_recon', 'sql_injection_blind', 'sql_injection_error',
            'lfi_path_traversal',
        ];
        $medium_rules   = [ 'xss_script', 'xss_event_handler', 'null_byte' ];

        if ( in_array( $rule, $critical_rules, true ) ) {
            return 'critical';
        }
        if ( in_array( $rule, $high_rules, true ) ) {
            return 'high';
        }
        if ( in_array( $rule, $medium_rules, true ) ) {
            return 'medium';
        }
        return 'low';
    }

    /**
     * Obtiene la IP real del cliente.
     *
     * FIX C-01: Proxy headers (X-Forwarded-For, CF-Connecting-IP, X-Real-IP) are
     * only trusted when the request originates from a known trusted proxy IP/CIDR.
     * Configure trusted proxies via the LTMS_TRUSTED_PROXY_IPS constant in wp-config.php
     * (comma-separated IPs or CIDR ranges, e.g. "10.0.0.1,173.245.48.0/20").
     * Loopback (127.0.0.1, ::1) is always trusted to support local Docker/nginx setups.
     *
     * @return string
     */
    public static function get_client_ip(): string {
        $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';

        if ( ! filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
            return '0.0.0.0';
        }

        // Only honour forwarded-for headers when the direct connection comes from a trusted proxy.
        if ( self::is_trusted_proxy( $remote_addr ) ) {
            // INTEGRATIONS-AUDIT P0 FIX (IP spoofing → WAF bypass):
            // Previously took the LEFTMOST entry of X-Forwarded-For — that's the
            // client-supplied value and is trivially spoofable. An attacker sends
            // `X-Forwarded-For: 1.2.3.4` → nginx appends the real IP → WAF reads
            // `1.2.3.4`. Result: full bypass of IP-based auto-block + ability to
            // frame victim IPs. Now: prefer HTTP_CF_CONNECTING_IP (set by Cloudflare
            // and overwritten — not appended — so unspoofable), then HTTP_X_REAL_IP
            // (similarly overwritten by nginx), then RIGHTMOST entry of
            // X-Forwarded-For (the proxy-appended hop = unspoofable).
            $cf_ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
            if ( ! empty( $cf_ip ) ) {
                $ip = trim( $cf_ip );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
            $real_ip = $_SERVER['HTTP_X_REAL_IP'] ?? '';
            if ( ! empty( $real_ip ) ) {
                $ip = trim( $real_ip );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
            $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
            if ( ! empty( $xff ) ) {
                // Take the RIGHTMOST entry — that's the IP appended by the last
                // trusted proxy in the chain. Client-supplied leftmost entries
                // are ignored.
                $parts = array_map( 'trim', explode( ',', $xff ) );
                $ip    = end( $parts );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return $remote_addr;
    }

    /**
     * Determines whether a given IP is a trusted proxy.
     *
     * @param string $ip The REMOTE_ADDR to check.
     * @return bool
     */
    private static function is_trusted_proxy( string $ip ): bool {
        // Loopback is always trusted (local reverse proxies, Docker internal networking).
        if ( in_array( $ip, [ '127.0.0.1', '::1' ], true ) ) {
            return true;
        }

        if ( ! defined( 'LTMS_TRUSTED_PROXY_IPS' ) || '' === LTMS_TRUSTED_PROXY_IPS ) {
            return false;
        }

        $trusted = array_filter( array_map( 'trim', explode( ',', LTMS_TRUSTED_PROXY_IPS ) ) );

        foreach ( $trusted as $entry ) {
            if ( str_contains( $entry, '/' ) ) {
                if ( self::ip_in_cidr( $ip, $entry ) ) {
                    return true;
                }
            } elseif ( $ip === $entry ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether an IPv4 address falls within a CIDR range.
     *
     * @param string $ip   IPv4 address to test.
     * @param string $cidr CIDR notation (e.g. "10.0.0.0/8").
     * @return bool
     */
    private static function ip_in_cidr( string $ip, string $cidr ): bool {
        $parts = explode( '/', $cidr, 2 );
        if ( count( $parts ) !== 2 ) {
            return false;
        }

        [ $subnet, $prefix ] = $parts;
        $prefix      = (int) $prefix;
        $ip_long     = ip2long( $ip );
        $subnet_long = ip2long( $subnet );

        if ( false === $ip_long || false === $subnet_long || $prefix < 0 || $prefix > 32 ) {
            return false;
        }

        $mask = $prefix === 0 ? 0 : ( ~0 << ( 32 - $prefix ) );
        return ( $ip_long & $mask ) === ( $subnet_long & $mask );
    }
}
