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
        'sql_injection_drop'     => '/(\bdrop\b.*\btable\b|\btruncate\b.*\btable\b)/i',
        'sql_injection_insert'   => '/(\binsert\b.*\binto\b|\bupdate\b.*\bset\b.*\bwhere\b)/i',
        'sql_injection_comment'  => '/(--|#|\/\*[\s\S]*?\*\/)/i', // SEC-L1: [\s\S] catches multi-line comment bypass
        'xss_script'             => '/<\s*script[^>]*>.*?<\s*\/\s*script\s*>/is',
        'xss_event_handler'      => '/on(load|click|mouseover|error|focus|blur|change|submit)\s*=/i',
        'xss_javascript'         => '/javascript\s*:/i',
        'lfi_path_traversal'     => '/(\.\.\/|\.\.\\\\|%2e%2e%2f|%2e%2e\/|\.\.%2f)/i',
        'rfi_http'               => '/(https?|ftp):\/\/.*\.(php|asp|aspx|jsp)/i',
        'php_injection'          => '/(<\?php|<\?=|eval\s*\(|base64_decode\s*\()/i',
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
        return is_user_logged_in() && current_user_can( 'manage_options' );
    }

    /**
     * Comprueba si la REQUEST_URI actual está en la whitelist de rutas admin.
     *
     * @return bool
     */
    private static function is_whitelisted_admin_path(): bool {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        foreach ( self::$admin_path_whitelist as $path ) {
            if ( str_contains( $request_uri, $path ) ) {
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

        // 1. Verificar blacklist de IPs
        if ( self::is_ip_blocked( $ip ) ) {
            self::block_request( 'IP_BLACKLISTED', $ip );
        }

        // 2. Análisis de parámetros GET y POST
        $params_to_check = array_merge(
            $_GET,
            $_POST,
            [ 'URI' => $_SERVER['REQUEST_URI'] ?? '' ]
        );

        foreach ( $params_to_check as $key => $value ) {
            if ( is_array( $value ) ) {
                $value = implode( ' ', array_map( 'strval', $value ) );
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

        // 4. FIX C-03: Inspect raw JSON request body.
        // REST API requests send payloads as application/json via php://input,
        // which never populates $_POST. Without this check, the WAF is completely
        // blind to injection attacks targeting /wp-json/ltms/v1/* endpoints.
        $content_type = strtolower( $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '' );
        if ( str_contains( $content_type, 'application/json' ) ) {
            // Limit read to 512 KB to prevent DoS via giant payloads.
            $raw_body = file_get_contents( 'php://input', false, null, 0, 524288 );
            if ( ! empty( $raw_body ) ) {
                $matched_rule = self::check_patterns( $raw_body );
                if ( $matched_rule ) {
                    self::handle_attack( $matched_rule, $ip, 'json_body', $raw_body );
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

        // Incrementar contador para esta IP
        $count_key = 'ltms_waf_' . md5( $ip );
        $count     = (int) get_transient( $count_key );
        $count++;
        set_transient( $count_key, $count, self::get_block_duration() );

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
        $critical_rules = [ 'sql_injection_drop', 'php_injection', 'rfi_http' ];
        $high_rules     = [ 'sql_injection_union', 'sql_injection_insert', 'lfi_path_traversal' ];
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
    private static function get_client_ip(): string {
        $remote_addr = $_SERVER['REMOTE_ADDR'] ?? '';

        if ( ! filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
            return '0.0.0.0';
        }

        // Only honour forwarded-for headers when the direct connection comes from a trusted proxy.
        if ( self::is_trusted_proxy( $remote_addr ) ) {
            $proxy_headers = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' ];
            foreach ( $proxy_headers as $h ) {
                if ( ! empty( $_SERVER[ $h ] ) ) {
                    // X-Forwarded-For may be a comma-separated list; take the leftmost (client) IP.
                    $ip = trim( explode( ',', $_SERVER[ $h ] )[0] );
                    if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                        return $ip;
                    }
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
