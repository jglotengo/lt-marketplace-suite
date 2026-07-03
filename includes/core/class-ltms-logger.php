<?php
/**
 * LTMS Core Logger - Logging Forense con Integridad
 *
 * Registra eventos del sistema en la BD (lt_audit_logs) y en archivos
 * físicos cifrados. Los registros son INMUTABLES por diseño (triggers DB).
 *
 * @package    LTMS
 * @subpackage LTMS/includes/core
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Core_Logger
 */
final class LTMS_Core_Logger {

    /**
     * Niveles de log válidos.
     */
    public const LEVELS = [ 'DEBUG', 'INFO', 'WARNING', 'ERROR', 'CRITICAL', 'SECURITY' ];

    /**
     * Nivel mínimo a registrar en producción.
     */
    private const PROD_MIN_LEVEL = 'INFO';

    /**
     * Buffer de logs para escritura en batch (evita múltiples queries por request).
     *
     * @var array<int, array>
     */
    private static array $buffer = [];

    /**
     * Indica si el shutdown handler está registrado.
     *
     * @var bool
     */
    private static bool $shutdown_registered = false;

    /**
     * Inicializa el logger.
     *
     * @return void
     */
    public static function init(): void {
        if ( ! self::$shutdown_registered ) {
            register_shutdown_function( [ __CLASS__, 'flush_buffer' ] );
            self::$shutdown_registered = true;
        }

        // Crear directorio de logs si no existe
        self::ensure_log_directory();
    }

    /**
     * Registra un evento de log.
     *
     * @param string $event_code Código único del evento (SNAKE_UPPER_CASE).
     * @param string $message    Mensaje descriptivo.
     * @param array  $context    Datos adicionales (se almacenan como JSON).
     * @param string $level      Nivel: DEBUG|INFO|WARNING|ERROR|CRITICAL|SECURITY.
     * @return void
     */
    public static function log(
        string $event_code,
        string $message,
        array  $context = [],
        string $level   = 'INFO'
    ): void {
        $level = strtoupper( $level );

        if ( ! in_array( $level, self::LEVELS, true ) ) {
            $level = 'INFO';
        }

        // En producción, ignorar DEBUG
        $is_production = class_exists( 'LTMS_Core_Config' )
            ? LTMS_Core_Config::is_production()
            : ( defined( 'LTMS_ENVIRONMENT' ) && 'production' === LTMS_ENVIRONMENT );
        if ( $is_production && $level === 'DEBUG' ) {
            return;
        }

        $log_entry = [
            'event_code' => sanitize_text_field( $event_code ),
            'message'    => sanitize_textarea_field( $message ),
            'context'    => self::sanitize_context( $context ),
            'level'      => $level,
            'user_id'    => get_current_user_id() ?: null,
            'ip_address' => self::get_ip(),
            'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] )
                ? substr( sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ), 0, 500 )
                : null,
            'url'        => isset( $_SERVER['REQUEST_URI'] )
                ? substr( sanitize_url( home_url( $_SERVER['REQUEST_URI'] ) ), 0, 2048 )
                : null,
            'source'     => self::get_caller_class(),
        ];

        // Agregar al buffer
        self::$buffer[] = $log_entry;

        // Escribir inmediatamente los eventos CRITICAL y SECURITY
        if ( in_array( $level, [ 'CRITICAL', 'SECURITY', 'ERROR' ], true ) ) {
            self::flush_buffer();
        }

        // Siempre escribir en archivo físico para logs SECURITY y CRITICAL
        if ( in_array( $level, [ 'CRITICAL', 'SECURITY' ], true ) ) {
            self::write_to_file( $log_entry );
        }
    }

    /**
     * Atajos de nivel de log.
     */
    public static function debug( string $event, string $msg, array $ctx = [] ): void {
        self::log( $event, $msg, $ctx, 'DEBUG' );
    }

    public static function info( string $event, string $msg, array $ctx = [] ): void {
        self::log( $event, $msg, $ctx, 'INFO' );
    }

    public static function warning( string $event, string $msg, array $ctx = [] ): void {
        self::log( $event, $msg, $ctx, 'WARNING' );
    }

    public static function error( string $event, string $msg, array $ctx = [] ): void {
        self::log( $event, $msg, $ctx, 'ERROR' );
    }

    public static function critical( string $event, string $msg, array $ctx = [] ): void {
        self::log( $event, $msg, $ctx, 'CRITICAL' );
    }

    public static function security( string $event, string $msg, array $ctx = [] ): void {
        self::log( $event, $msg, $ctx, 'SECURITY' );
    }

    /**
     * Escribe todos los logs del buffer en la BD.
     * Llamado automáticamente al finalizar el request (shutdown).
     *
     * @return void
     */
    public static function flush_buffer(): void {
        if ( empty( self::$buffer ) ) {
            return;
        }

        global $wpdb;

        if ( ! $wpdb instanceof \wpdb ) {
            return;
        }

        $table = $wpdb->prefix . 'lt_audit_logs';

        foreach ( self::$buffer as $entry ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert(
                $table,
                [
                    'event_code' => $entry['event_code'],
                    'message'    => $entry['message'],
                    'context'    => wp_json_encode( $entry['context'] ),
                    'level'      => $entry['level'],
                    'user_id'    => $entry['user_id'],
                    'ip_address' => $entry['ip_address'],
                    'user_agent' => $entry['user_agent'],
                    'url'        => $entry['url'],
                    'source'     => $entry['source'],
                    'created_at' => current_time( 'mysql', true ),
                ],
                [ '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
            );
        }

        self::$buffer = [];
    }

    /**
     * Obtiene los últimos N logs del sistema.
     *
     * @param int    $limit     Cantidad de logs.
     * @param string $level     Filtrar por nivel (opcional).
     * @param string $event     Filtrar por event_code (opcional).
     * @return array
     */
    public static function get_recent_logs( int $limit = 100, string $level = '', string $event = '' ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_audit_logs';

        $where_clauses = [];
        $values        = [];

        if ( ! empty( $level ) ) {
            $where_clauses[] = 'level = %s';
            $values[]        = strtoupper( $level );
        }

        if ( ! empty( $event ) ) {
            $where_clauses[] = 'event_code LIKE %s';
            $values[]        = '%' . $wpdb->esc_like( $event ) . '%';
        }

        $where_sql = empty( $where_clauses ) ? '' : 'WHERE ' . implode( ' AND ', $where_clauses );
        $values[]  = absint( $limit );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` {$where_sql} ORDER BY id DESC LIMIT %d",
                $values
            ),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Obtiene la IP del cliente real (compatible con proxies y CloudFlare).
     *
     * @return string
     */
    private static function get_ip(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = sanitize_text_field( $_SERVER[ $header ] );
                // Tomar solo la primera IP si hay lista
                if ( str_contains( $ip, ',' ) ) {
                    $ip = trim( explode( ',', $ip )[0] );
                }
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Detecta qué clase invocó al logger (para el campo 'source').
     *
     * @return string
     */
    private static function get_caller_class(): string {
        // SEC-27 FIX (v2.9.26): debug_backtrace es costoso y puede exponer
        // información sensible del stack en producción. Solo ejecutar si
        // WP_DEBUG está activo; en producción usar 'LTMS_System' como fallback.
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return 'LTMS_System';
        }
        $trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 5 );
        foreach ( $trace as $frame ) {
            if ( isset( $frame['class'] ) && $frame['class'] !== self::class ) {
                return $frame['class'];
            }
        }
        return 'LTMS_Unknown';
    }

    /**
     * Sanitiza el contexto eliminando datos sensibles antes de loguear.
     *
     * @param array $context Contexto original.
     * @return array Contexto sanitizado.
     */
    private static function sanitize_context( array $context ): array {
        $sensitive = [ 'password', 'secret', 'api_key', 'private_key', 'token', 'credit_card', 'cvv', 'pin', 'bank_account', 'clabe', 'iban', 'swift' ];
        $sanitized = [];

        foreach ( $context as $key => $value ) {
            $is_sensitive = false;
            foreach ( $sensitive as $s ) {
                if ( str_contains( strtolower( (string) $key ), $s ) ) {
                    $is_sensitive = true;
                    break;
                }
            }
            if ( $is_sensitive ) {
                $sanitized[ $key ] = '[REDACTED]';
            } elseif ( is_array( $value ) ) {
                // LOG-BUG-1 FIX: recurse into nested arrays to redact sensitive keys at every level
                $sanitized[ $key ] = self::sanitize_context( $value );
            } else {
                // MASK-BUG-2 FIX: mask PII (email/phone/document) in log context values
                $sanitized[ $key ] = self::mask_pii_value( $value );
            }
        }

        return $sanitized;
    }

    /**
     * MASK-BUG-2 FIX: Mask PII (email/phone/document) in a scalar value before logging.
     *
     * @param mixed $value Value to mask.
     * @return mixed Masked value (or original if not PII).
     */
    private static function mask_pii_value( $value ) {
        if ( ! is_string( $value ) ) {
            return $value;
        }
        // Mask email
        if ( preg_match( '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $value ) ) {
            $parts = explode( '@', $value );
            $local = $parts[0];
            $masked_local = strlen( $local ) > 2 ? substr( $local, 0, 2 ) . str_repeat( '*', max( 3, strlen( $local ) - 2 ) ) : '***';
            return $masked_local . '@' . $parts[1];
        }
        // Mask phone (10+ digits)
        if ( preg_match( '/^[\+]?[0-9]{10,15}$/', preg_replace( '/[\s\-\(\)]/', '', $value ) ) ) {
            $clean = preg_replace( '/[^0-9]/', '', $value );
            if ( strlen( $clean ) >= 8 ) {
                return str_repeat( '*', strlen( $clean ) - 4 ) . substr( $clean, -4 );
            }
        }
        // Mask document number (8-18 alphanumeric, likely CC/NIT/CURP/RFC)
        if ( preg_match( '/^[a-zA-Z0-9]{8,18}$/', $value ) && ! is_numeric( $value ) === false ) {
            // Only mask if it looks like a document (not a normal word)
            if ( preg_match( '/[0-9]/', $value ) && strlen( $value ) >= 8 ) {
                return str_repeat( '*', max( 4, strlen( $value ) - 4 ) ) . substr( $value, -4 );
            }
        }
        return $value;
    }

    /**
     * Escribe un log crítico en archivo físico como respaldo.
     *
     * @param array $entry Entrada de log.
     * @return void
     */
    private static function write_to_file( array $entry ): void {
        $log_dir  = LTMS_LOG_DIR;
        $log_file = $log_dir . 'ltms-' . gmdate( 'Y-m-d' ) . '.log.php';

        $line = sprintf(
            "[%s] [%s] [%s] [IP:%s] %s | Context: %s\n",
            gmdate( 'Y-m-d H:i:s' ),
            $entry['level'],
            $entry['event_code'],
            $entry['ip_address'] ?? '?',
            $entry['message'],
            wp_json_encode( $entry['context'] ?? [] )
        );

        // Crear archivo con PHP die header si es nuevo (previene acceso web directo)
        if ( ! file_exists( $log_file ) ) {
            $header = "<?php die('Access Denied'); ?>\n";
            file_put_contents( $log_file, $header, LOCK_EX );
        }

        file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX );
    }

    /**
     * Crea el directorio de logs si no existe y lo protege.
     *
     * @return void
     */
    private static function ensure_log_directory(): void {
        $log_dir = LTMS_LOG_DIR;

        if ( ! is_dir( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
        }

        // Crear .htaccess para bloquear acceso web directo
        $htaccess = $log_dir . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Order Deny,Allow\nDeny from all\n", LOCK_EX );
        }

        // Crear index.php de silencio
        $index = $log_dir . 'index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, '<?php // Silence is golden.', LOCK_EX );
        }
    }
}
