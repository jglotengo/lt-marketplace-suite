<?php
/**
 * Bootstrap para PHPUnit — LT Marketplace Suite
 *
 * Orden de carga CRÍTICO:
 * 1. Constantes mínimas (ANTES de Composer/Patchwork)
 * 2. Composer autoloader (vendor/autoload.php) — incluye Patchwork
 * 3. WordPress test suite (modo integración) o return (modo unit)
 *
 * Para tests UNITARIOS puros:
 *   LTMS_UNIT_ONLY=true vendor/bin/phpunit --testsuite=unit
 *
 * @package LTMS\Tests
 */

declare( strict_types=1 );

// ══════════════════════════════════════════════════════════════════════════════
// PASO 1: Solo constantes PHP puras — NUNCA funciones WP aquí.
// Brain\Monkey registrará __(), apply_filters(), etc. en setUp() de cada test.
// ══════════════════════════════════════════════════════════════════════════════

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// ══════════════════════════════════════════════════════════════════════════════
// PASO 1b: Clases LTMS reales inline — ANTES del autoloader de Composer.
// ══════════════════════════════════════════════════════════════════════════════

defined( 'AUTH_SALT' )        || define( 'AUTH_SALT',        str_repeat( 'ltms_test_salt_', 4 ) );
defined( 'SECURE_AUTH_SALT' ) || define( 'SECURE_AUTH_SALT', str_repeat( 'ltms_sec_salt__', 4 ) );

if ( ! class_exists( 'LTMS_Core_Config', false ) ) {
    final class LTMS_Core_Config {
        private static array $cache           = [];
        private static array $settings        = [];
        private static bool  $settings_loaded = false;

        public static function init(): void { self::load_settings(); }

        private static function load_settings(): void {
            if ( self::$settings_loaded ) return;
            $raw                   = get_option( 'ltms_settings', [] );
            self::$settings        = is_array( $raw ) ? $raw : [];
            self::$settings_loaded = true;
        }

        public static function get( string $key, mixed $default = null ): mixed {
            if ( isset( self::$cache[ $key ] ) ) return self::$cache[ $key ];
            $const = strtoupper( $key );
            if ( defined( $const ) ) {
                return self::$cache[ $key ] = constant( $const );
            }
            self::load_settings();
            if ( isset( self::$settings[ $key ] ) ) {
                return self::$cache[ $key ] = self::$settings[ $key ];
            }
            $value = get_option( $key, null );
            if ( $value !== null ) {
                return self::$cache[ $key ] = $value;
            }
            return $default;
        }

        public static function set( string $key, mixed $value ): bool {
            self::load_settings();
            self::$settings[ $key ] = $value;
            self::$cache[ $key ]    = $value;
            return update_option( 'ltms_settings', self::$settings, true );
        }

        public static function flush_cache(): void {
            self::$cache           = [];
            self::$settings        = [];
            self::$settings_loaded = false;
        }

        public static function get_country(): string {
            $c = self::get( 'LTMS_COUNTRY', defined( 'LTMS_COUNTRY' ) ? LTMS_COUNTRY : 'CO' );
            return in_array( strtoupper( (string) $c ), [ 'CO', 'MX' ], true )
                ? strtoupper( (string) $c ) : 'CO';
        }

        public static function get_context_country(): string { return self::get_country(); }

        public static function is_production(): bool {
            $env = self::get( 'LTMS_ENVIRONMENT', defined( 'LTMS_ENVIRONMENT' ) ? LTMS_ENVIRONMENT : 'test' );
            return $env === 'production';
        }

        public static function is_development(): bool { return ! self::is_production(); }

        public static function get_currency(): string {
            return self::get_country() === 'MX' ? 'MXN' : 'COP';
        }

        public static function get_encryption_key(): string {
            if ( defined( 'LTMS_ENCRYPTION_KEY' ) && ! empty( LTMS_ENCRYPTION_KEY ) ) {
                return LTMS_ENCRYPTION_KEY;
            }
            if ( defined( 'AUTH_KEY' ) && strlen( AUTH_KEY ) >= 32 ) {
                return substr( AUTH_KEY, 0, 32 );
            }
            throw new \RuntimeException( 'LTMS: LTMS_ENCRYPTION_KEY no definida.' );
        }

        public static function get_all_safe(): array {
            self::load_settings();
            $sensitive = [ 'api_key', 'secret', 'password', 'token', 'private', 'encryption_key' ];
            $safe      = [];
            foreach ( self::$settings as $key => $value ) {
                $is_sensitive = false;
                foreach ( $sensitive as $s ) {
                    if ( str_contains( strtolower( $key ), $s ) ) { $is_sensitive = true; break; }
                }
                $safe[ $key ] = $is_sensitive ? '***REDACTED***' : $value;
            }
            return $safe;
        }
    }
}

if ( ! class_exists( 'LTMS_Core_Security', false ) ) {
    final class LTMS_Core_Security {
        private const CIPHER_ALGO        = 'aes-256-cbc';
        private const IV_LENGTH          = 16;
        private const ENCRYPTION_VERSION = 'v1';

        public static function encrypt( string $plaintext ): string {
            if ( empty( $plaintext ) ) return '';
            $key       = self::derive_key( LTMS_Core_Config::get_encryption_key() );
            $iv        = random_bytes( self::IV_LENGTH );
            $encrypted = openssl_encrypt( $plaintext, self::CIPHER_ALGO, $key, OPENSSL_RAW_DATA, $iv );
            if ( $encrypted === false ) {
                throw new \RuntimeException( 'LTMS: Error al cifrar.' );
            }
            return self::ENCRYPTION_VERSION . ':' . base64_encode( $iv ) . ':' . base64_encode( $encrypted );
        }

        public static function decrypt( string $ciphertext ): string {
            if ( empty( $ciphertext ) ) return '';
            $parts = explode( ':', $ciphertext, 3 );
            if ( count( $parts ) !== 3 ) {
                throw new \InvalidArgumentException( 'LTMS: Formato de datos cifrados inválido.' );
            }
            [ $version, $iv_b64, $cipher_b64 ] = $parts;
            if ( $version !== self::ENCRYPTION_VERSION ) {
                throw new \InvalidArgumentException( "LTMS: Versión de cifrado desconocida: {$version}" );
            }
            $key       = self::derive_key( LTMS_Core_Config::get_encryption_key() );
            $iv        = base64_decode( $iv_b64, true );
            $encrypted = base64_decode( $cipher_b64, true );
            if ( $iv === false || $encrypted === false ) {
                throw new \InvalidArgumentException( 'LTMS: Datos cifrados corruptos.' );
            }
            $decrypted = openssl_decrypt( $encrypted, self::CIPHER_ALGO, $key, OPENSSL_RAW_DATA, $iv );
            if ( $decrypted === false ) {
                throw new \RuntimeException( 'LTMS: Descifrado fallido.' );
            }
            return $decrypted;
        }

        public static function hash( string $value, string $pepper = '' ): string {
            if ( defined( 'SECURE_AUTH_SALT' ) && '' !== SECURE_AUTH_SALT ) {
                $salt = SECURE_AUTH_SALT;
            } elseif ( defined( 'AUTH_SALT' ) && '' !== AUTH_SALT ) {
                $salt = AUTH_SALT;
            } elseif ( defined( 'AUTH_KEY' ) && '' !== AUTH_KEY ) {
                $salt = AUTH_KEY;
            } else {
                $salt = hash( 'sha256', ( defined( 'DB_NAME' ) ? DB_NAME : '' )
                            . ( defined( 'DB_USER' ) ? DB_USER : '' )
                            . get_option( 'siteurl', 'ltms' ) );
            }
            return hash_hmac( 'sha256', $value . $pepper, $salt );
        }

        public static function verify_hash( string $value, string $hash, string $pepper = '' ): bool {
            return hash_equals( self::hash( $value, $pepper ), $hash );
        }

        public static function generate_token( int $length = 32 ): string {
            return bin2hex( random_bytes( $length ) );
        }

        public static function generate_referral_code(): string {
            $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
            $code  = '';
            $max   = strlen( $chars ) - 1;
            for ( $i = 0; $i < 8; $i++ ) {
                $code .= $chars[ random_int( 0, $max ) ];
            }
            return $code;
        }

        public static function verify_webhook_signature(
            string $payload, string $signature, string $secret, string $prefix = 'sha256='
        ): bool {
            if ( ! empty( $prefix ) ) $signature = str_replace( $prefix, '', $signature );
            return hash_equals( hash_hmac( 'sha256', $payload, $secret ), strtolower( $signature ) );
        }

        public static function sanitize_email( string $email ): string {
            $clean = sanitize_email( $email );
            return is_email( $clean ) ? $clean : '';
        }

        public static function sanitize_document_number( string $doc ): string {
            return preg_replace( '/[^a-zA-Z0-9\-\.]/', '', trim( $doc ) );
        }

        public static function sanitize_phone( string $phone ): string {
            return preg_replace( '/[^0-9+\-\(\) ]/', '', trim( $phone ) );
        }

        public static function current_user_can( string $capability ): bool {
            return (bool) current_user_can( $capability );
        }

        private static function derive_key( string $master_key ): string {
            if ( defined( 'SECURE_AUTH_SALT' ) && '' !== SECURE_AUTH_SALT ) {
                $site_salt = SECURE_AUTH_SALT;
            } elseif ( defined( 'AUTH_SALT' ) && '' !== AUTH_SALT ) {
                $site_salt = AUTH_SALT;
            } else {
                $site_salt = 'ltms_fallback_salt';
            }
            return hash_pbkdf2( 'sha256', $master_key, $site_salt, 600000, 32, true );
        }
    }
}

// ── Stub LTMS_API_Client_Interface ─────────────────────────────────────────────
if ( ! interface_exists( 'LTMS_API_Client_Interface', false ) ) {
    interface LTMS_API_Client_Interface {
        public function health_check(): array;
        public function get_provider_slug(): string;
    }
}

// ── Stub LTMS_Abstract_API_Client con __construct() ────────────────────────────
if ( ! class_exists( 'LTMS_Abstract_API_Client', false ) ) {
    abstract class LTMS_Abstract_API_Client implements LTMS_API_Client_Interface {

        protected string $api_url         = '';
        protected string $provider_slug   = '';
        protected int    $timeout         = 30;
        protected int    $max_retries     = 3;
        protected int    $retry_delay     = 1;
        protected string $base_url        = '';
        protected array  $default_headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ];

        public function __construct() {
            if ( $this->base_url !== '' && $this->api_url === '' ) {
                $this->api_url = $this->base_url;
            }
        }

        protected function get_default_headers(): array {
            return $this->default_headers;
        }

        protected function init_configurable_settings(): void {
            if ( class_exists( 'LTMS_Core_Config' ) ) {
                $this->timeout     = (int) LTMS_Core_Config::get( 'ltms_api_timeout_seconds', 30 );
                $this->max_retries = (int) LTMS_Core_Config::get( 'ltms_api_max_retries', 3 );
                $this->retry_delay = (int) LTMS_Core_Config::get( 'ltms_api_retry_delay_seconds', 1 );
            }
        }

        protected function perform_request(
            string $method,
            string $endpoint,
            array  $data    = [],
            array  $headers = [],
            bool   $retry   = true
        ): array {
            $url     = rtrim( $this->api_url, '/' ) . '/' . ltrim( $endpoint, '/' );
            $method  = strtoupper( $method );
            $headers = array_merge( $this->default_headers, $headers );
            $args    = [
                'method'    => $method,
                'headers'   => $headers,
                'timeout'   => $this->timeout,
                'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),
            ];
            if ( ! empty( $data ) && in_array( $method, [ 'POST', 'PUT', 'PATCH' ], true ) ) {
                $args['body'] = wp_json_encode( $data );
            }
            $attempts   = 0;
            $last_error = null;
            do {
                $attempts++;
                $response = wp_remote_request( $url, $args );
                if ( is_wp_error( $response ) ) {
                    $last_error = $response->get_error_message();
                    if ( ! $retry || $attempts >= $this->max_retries ) {
                        throw new \RuntimeException(
                            sprintf( '[%s] Error de red: %s (intentos: %d)', $this->provider_slug, $last_error, $attempts )
                        );
                    }
                    continue;
                }
                $status_code   = wp_remote_retrieve_response_code( $response );
                $response_body = wp_remote_retrieve_body( $response );
                $decoded       = json_decode( $response_body, true ) ?? [];
                if ( $status_code >= 200 && $status_code < 300 ) { return $decoded; }
                if ( $status_code >= 500 && $retry && $attempts < $this->max_retries ) {
                    $last_error = sprintf( 'HTTP %d del servidor (reintentando...)', $status_code );
                    continue;
                }
                $error_message = $this->extract_error_message( $decoded, $status_code );
                throw new \RuntimeException(
                    sprintf( '[%s] Error HTTP %d: %s | URL: %s', $this->provider_slug, $status_code, $error_message, $url ),
                    $status_code
                );
            } while ( $attempts < $this->max_retries );
            throw new \RuntimeException(
                sprintf( '[%s] Máximo de reintentos alcanzado (%d). Último error: %s', $this->provider_slug, $this->max_retries, $last_error ?? 'Error desconocido' )
            );
        }

        protected function extract_error_message( array $response, int $status_code ): string {
            foreach ( [ 'message', 'error_message', 'error', 'description', 'detail', 'msg', 'errorMessage' ] as $key ) {
                if ( isset( $response[ $key ] ) && is_string( $response[ $key ] ) ) {
                    return $response[ $key ];
                }
            }
            return "HTTP Error {$status_code}";
        }

        protected function redact_sensitive_data( array $data ): array {
            $sensitive = [ 'card_number', 'cvv', 'cvv2', 'expiry', 'pin', 'password', 'secret', 'private_key', 'api_key', 'document', 'document_number', 'nit', 'rfc', 'curp', 'cedula', 'nuip' ];
            $redacted  = [];
            foreach ( $data as $key => $value ) {
                $is_sensitive = false;
                foreach ( $sensitive as $s ) {
                    if ( str_contains( strtolower( (string) $key ), $s ) ) { $is_sensitive = true; break; }
                }
                $redacted[ $key ] = is_array( $value ) ? $this->redact_sensitive_data( $value ) : ( $is_sensitive ? '[REDACTED]' : $value );
            }
            return $redacted;
        }

        public function get_provider_slug(): string { return $this->provider_slug; }

        public function health_check(): array {
            $start = microtime( true );
            try {
                $this->perform_request( 'GET', '/health', [], [], false );
                return [ 'status' => 'ok', 'message' => 'Conectado', 'latency_ms' => (int) round( ( microtime( true ) - $start ) * 1000 ) ];
            } catch ( \Throwable $e ) {
                return [ 'status' => 'error', 'message' => $e->getMessage() ];
            }
        }
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// PASO 2: Composer autoloader (incluye Patchwork + Brain\Monkey)
// ══════════════════════════════════════════════════════════════════════════════
$ltms_composer_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! file_exists( $ltms_composer_autoload ) ) {
    echo "\n❌  ERROR: vendor/autoload.php no encontrado.\n";
    echo "   Ejecuta: composer install --ignore-platform-reqs\n\n";
    exit( 1 );
}

require_once $ltms_composer_autoload;

// ── FIX: flush cache estático de LTMS_Core_Config después de Composer ──────
if ( class_exists( 'LTMS_Core_Config' ) ) {
    LTMS_Core_Config::flush_cache();
}

// ══════════════════════════════════════════════════════════════════════════════
// PASO 3: Modo Unit Only vs Integración
// ══════════════════════════════════════════════════════════════════════════════
$ltms_unit_only = getenv( 'LTMS_UNIT_ONLY' ) === 'true'
    || ( defined( 'LTMS_TESTS_UNIT_ONLY' ) && LTMS_TESTS_UNIT_ONLY );

if ( $ltms_unit_only ) {
    echo "\n[LTMS Test Bootstrap] Modo: UNIT ONLY (sin WordPress)\n";

    defined( 'ARRAY_A' )           || define( 'ARRAY_A',           'ARRAY_A' );
    defined( 'ARRAY_N' )           || define( 'ARRAY_N',           'ARRAY_N' );
    defined( 'OBJECT' )            || define( 'OBJECT',            'OBJECT' );
    defined( 'OBJECT_K' )          || define( 'OBJECT_K',          'OBJECT_K' );
    defined( 'DAY_IN_SECONDS' )    || define( 'DAY_IN_SECONDS',    86400 );
    defined( 'HOUR_IN_SECONDS' )   || define( 'HOUR_IN_SECONDS',   3600 );
    defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
    defined( 'WEEK_IN_SECONDS' )   || define( 'WEEK_IN_SECONDS',   604800 );
    defined( 'MONTH_IN_SECONDS' )  || define( 'MONTH_IN_SECONDS',  2592000 );
    defined( 'YEAR_IN_SECONDS' )   || define( 'YEAR_IN_SECONDS',   31536000 );
    defined( 'WP_CONTENT_DIR' )    || define( 'WP_CONTENT_DIR',    dirname( __DIR__ ) . '/wp-content' );
    defined( 'WP_PLUGIN_DIR' )     || define( 'WP_PLUGIN_DIR',     WP_CONTENT_DIR . '/plugins' );
    defined( 'WPINC' )             || define( 'WPINC',             'wp-includes' );
    defined( 'DB_HOST' )           || define( 'DB_HOST',           '127.0.0.1' );
    defined( 'DB_NAME' )           || define( 'DB_NAME',           'test' );
    defined( 'DB_USER' )           || define( 'DB_USER',           'root' );
    defined( 'DB_PASSWORD' )       || define( 'DB_PASSWORD',       '' );

    // ── Stub $wpdb global ──────────────────────────────────────────────────────
    if ( ! isset( $GLOBALS['wpdb'] ) ) {
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public function get_var( mixed $query = null ): mixed { return null; }
            public function get_results( mixed $query = null, string $output = 'OBJECT' ): array { return []; }
            public function get_row( mixed $query = null, string $output = 'OBJECT', int $y = 0 ): mixed { return null; }
            public function prepare( string $query, mixed ...$args ): string { return $query; }
            public function query( string $query ): int|bool { return false; }
            public function insert( string $table, array $data, mixed $format = null ): int|bool { return false; }
            public function update( string $table, array $data, array $where, mixed $format = null, mixed $where_format = null ): int|bool { return false; }
            public function delete( string $table, array $where, mixed $where_format = null ): int|bool { return false; }
            public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
            public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
        };
    }

    // ── Stub WP_Error ──────────────────────────────────────────────────────────
    if ( ! class_exists( 'WP_Error' ) ) {
        class WP_Error {
            private string $code;
            private string $message;
            private mixed  $data;
            public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
                $this->code    = $code;
                $this->message = $message;
                $this->data    = $data;
            }
            public function get_error_code(): string    { return $this->code; }
            public function get_error_message(): string { return $this->message; }
            public function get_error_data(): mixed     { return $this->data; }
        }
    }

    // ── Cargar clases fiscales ─────────────────────────────────────────────────
    $ltms_base = dirname( __DIR__ ) . '/includes';

    require_once $ltms_base . '/core/interfaces/interface-ltms-tax-strategy.php';
    require_once $ltms_base . '/business/strategies/class-ltms-tax-strategy-colombia.php';
    require_once $ltms_base . '/business/strategies/class-ltms-tax-strategy-mexico.php';
    require_once $ltms_base . '/business/class-ltms-tax-engine.php';

    // ── Utilidades y Logger ────────────────────────────────────────────────────
    require_once $ltms_base . '/core/utils/class-ltms-utils.php';
    require_once $ltms_base . '/core/class-ltms-logger.php';

    // ── Cargar clases de negocio ───────────────────────────────────────────────
    require_once $ltms_base . '/business/class-ltms-wallet.php';

    if ( ! class_exists( 'LTMS_Wallet' ) && class_exists( 'LTMS_Business_Wallet' ) ) {
        class_alias( 'LTMS_Business_Wallet', 'LTMS_Wallet' );
    }

    require_once $ltms_base . '/business/class-ltms-shipping-mode.php';

    // ── Comisiones ─────────────────────────────────────────────────────────────
    require_once $ltms_base . '/core/traits/trait-ltms-logger-aware.php';
    require_once $ltms_base . '/business/class-ltms-commission-strategy.php';
    require_once $ltms_base . '/frontend/class-ltms-seo-manager.php';

    if ( ! class_exists( 'LTMS_Seo_Manager' ) && class_exists( 'LTMS_SEO_Manager' ) ) {
        class_alias( 'LTMS_SEO_Manager', 'LTMS_Seo_Manager' );
    }

    // ── API Factory ────────────────────────────────────────────────────────────
    if ( ! interface_exists( 'LTMS_API_Client_Interface', false ) ) {
        require_once $ltms_base . '/core/interfaces/interface-ltms-api-client.php';
    }
    require_once $ltms_base . '/api/factories/class-ltms-api-factory.php';

    // ── Payment Orchestrator ───────────────────────────────────────────────────
    require_once $ltms_base . '/business/class-ltms-payment-orchestrator.php';

    // ── Referral Tree & Order Split ────────────────────────────────────────────
    require_once $ltms_base . '/business/class-ltms-referral-tree.php';
    require_once $ltms_base . '/business/class-ltms-order-split.php';

    // ── Constantes del plugin ──────────────────────────────────────────────────
    $plugin_root = dirname( __DIR__ ) . DIRECTORY_SEPARATOR;
    defined( 'LTMS_VERSION' )         || define( 'LTMS_VERSION',         '2.0.0' );
    defined( 'LTMS_DB_VERSION' )      || define( 'LTMS_DB_VERSION',      '2.0.0' );
    defined( 'LTMS_MIN_PHP' )         || define( 'LTMS_MIN_PHP',         '8.1' );
    defined( 'LTMS_MIN_WP' )          || define( 'LTMS_MIN_WP',          '6.0' );
    defined( 'LTMS_MIN_WC' )          || define( 'LTMS_MIN_WC',          '7.0' );
    defined( 'LTMS_PLUGIN_FILE' )     || define( 'LTMS_PLUGIN_FILE',     $plugin_root . 'lt-marketplace-suite.php' );
    defined( 'LTMS_PLUGIN_DIR' )      || define( 'LTMS_PLUGIN_DIR',      $plugin_root );
    defined( 'LTMS_PLUGIN_URL' )      || define( 'LTMS_PLUGIN_URL',      'http://localhost/wp-content/plugins/lt-marketplace-suite/' );
    defined( 'LTMS_PLUGIN_BASENAME' ) || define( 'LTMS_PLUGIN_BASENAME', 'lt-marketplace-suite/lt-marketplace-suite.php' );
    defined( 'LTMS_INCLUDES_DIR' )    || define( 'LTMS_INCLUDES_DIR',    $plugin_root . 'includes' . DIRECTORY_SEPARATOR );
    defined( 'LTMS_ASSETS_URL' )      || define( 'LTMS_ASSETS_URL',      'http://localhost/wp-content/plugins/lt-marketplace-suite/assets/' );
    defined( 'LTMS_TEMPLATES_DIR' )   || define( 'LTMS_TEMPLATES_DIR',   $plugin_root . 'templates' . DIRECTORY_SEPARATOR );
    defined( 'LTMS_LOG_DIR' )         || define( 'LTMS_LOG_DIR',         sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ltms-logs' . DIRECTORY_SEPARATOR );
    defined( 'LTMS_ENVIRONMENT' )     || define( 'LTMS_ENVIRONMENT',     'test' );
    defined( 'LTMS_COUNTRY' )         || define( 'LTMS_COUNTRY',         'CO' );
    defined( 'LTMS_ENCRYPTION_KEY' )  || define( 'LTMS_ENCRYPTION_KEY',  str_repeat( 'x', 32 ) );

    // ── Stubs de funciones WordPress usadas por Logger/Config ─────────────────
    if ( ! function_exists( 'is_email' ) ) {
        function is_email( string $email ): bool { return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL ); }
    }
    if ( ! function_exists( 'sanitize_textarea_field' ) ) {
        function sanitize_textarea_field( string $str ): string { return $str; }
    }
    if ( ! function_exists( 'sanitize_url' ) ) {
        function sanitize_url( string $url ): string { return $url; }
    }
    if ( ! function_exists( 'home_url' ) ) {
        function home_url( string $path = '' ): string { return 'http://localhost' . $path; }
    }
    if ( ! function_exists( 'get_current_user_id' ) ) {
        function get_current_user_id(): int { return 0; }
    }
    if ( ! function_exists( 'wp_mkdir_p' ) ) {
        function wp_mkdir_p( string $dir ): bool { return is_dir( $dir ) || mkdir( $dir, 0755, true ); }
    }

    // ── Autoloader mínimo ──────────────────────────────────────────────────────
    if ( ! function_exists( 'ltms_load_autoloader' ) ) {
        function ltms_load_autoloader(): void {
            spl_autoload_register( function( string $class_name ): void {
                if ( strpos( $class_name, 'LTMS_' ) !== 0 ) return;
                $class_file   = strtolower( str_replace( '_', '-', $class_name ) );
                $parts        = explode( '-', $class_file );
                $filename     = 'class-' . $class_file . '.php';
                $two_part_map = [
                    'ltms-config'   => 'core/class-ltms-config.php',
                    'ltms-logger'   => 'core/class-ltms-logger.php',
                    'ltms-security' => 'core/class-ltms-security.php',
                    'ltms-kernel'   => 'core/class-ltms-kernel.php',
                    'ltms-utils'    => 'core/utils/class-ltms-utils.php',
                    'ltms-wallet'   => 'business/class-ltms-wallet.php',
                    'ltms-roles'    => 'roles/class-ltms-roles.php',
                    'ltms-admin'    => 'admin/class-ltms-admin.php',
                ];
                $alias_map = [
                    'LTMS_Config'   => 'LTMS_Core_Config',
                    'LTMS_Logger'   => 'LTMS_Core_Logger',
                    'LTMS_Security' => 'LTMS_Core_Security',
                    'LTMS_Kernel'   => 'LTMS_Core_Kernel',
                    'LTMS_Wallet'   => 'LTMS_Business_Wallet',
                ];
                if ( isset( $alias_map[ $class_name ] ) ) {
                    $real = $alias_map[ $class_name ];
                    if ( ! class_exists( $real ) ) { spl_autoload_call( $real ); }
                    if ( class_exists( $real ) && ! class_exists( $class_name ) ) { class_alias( $real, $class_name ); }
                    return;
                }
                if ( isset( $two_part_map[ $class_file ] ) ) {
                    $path = LTMS_INCLUDES_DIR . $two_part_map[ $class_file ];
                    if ( file_exists( $path ) ) { require_once $path; return; }
                }
                if ( count( $parts ) === 2 ) {
                    foreach ( [ $parts[1], 'core', 'business', 'frontend', 'admin', 'roles', 'api', 'shipping', 'gateway' ] as $dir ) {
                        $path = LTMS_INCLUDES_DIR . $dir . '/' . $filename;
                        if ( file_exists( $path ) ) { require_once $path; return; }
                    }
                }
                if ( count( $parts ) >= 3 ) {
                    $path = LTMS_INCLUDES_DIR . $parts[1] . '/' . $filename;
                    if ( file_exists( $path ) ) { require_once $path; return; }
                    foreach ( [ 'core', 'business', 'frontend', 'admin', 'api', 'shipping', 'gateway', 'roles' ] as $dir ) {
                        $path = LTMS_INCLUDES_DIR . $dir . '/' . $filename;
                        if ( file_exists( $path ) ) { require_once $path; return; }
                    }
                }
            } );
        }
    }

    // ── Stub WC_Payment_Gateway ────────────────────────────────────────────────
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        class WC_Payment_Gateway {
            public string $id                 = '';
            public string $icon               = '';
            public bool   $has_fields         = false;
            public string $method_title       = '';
            public string $method_description = '';
            public array  $supports           = [];
            public string $title              = '';
            public string $description        = '';
            public string $enabled            = 'yes';
            protected array $form_fields      = [];
            protected array $settings         = [];
            public function init_form_fields(): void {}
            public function init_settings(): void {}
            public function process_admin_options(): void {}
            public function get_option( string $key, mixed $default = '' ): mixed { return $this->settings[ $key ] ?? $default; }
            public function set_option( string $key, mixed $value ): void { $this->settings[ $key ] = $value; }
            public function update_option( string $key, mixed $value ): void { $this->settings[ $key ] = $value; }
        }
    }

    // ── Stub WC_Product ────────────────────────────────────────────────────────
    if ( ! class_exists( 'WC_Product' ) ) {
        class WC_Product {
            protected int    $id    = 0;
            protected string $name  = '';
            protected string $sku   = '';
            protected array  $props = [];
            public function get_id(): int              { return $this->id; }
            public function get_name(): string         { return $this->name; }
            public function get_sku(): string          { return $this->sku; }
            public function set_id( int $id ): void    { $this->id = $id; }
            public function set_status( string $s ): void {}
            public function set_date_created( mixed $d ): void {}
            public function set_date_modified( mixed $d ): void {}
            public function set_name( string $n ): void { $this->name = $n; }
            public function set_slug( string $s ): void {}
            public function save(): int { return $this->id > 0 ? $this->id : 1; }
            public function get_prop( string $prop, string $context = 'view' ): mixed { return $this->props[ $prop ] ?? null; }
            public function set_prop( string $prop, mixed $value ): void { $this->props[ $prop ] = $value; }
        }
    }

    // ── Stub WC_Order ──────────────────────────────────────────────────────────
    if ( ! class_exists( 'WC_Order' ) ) {
        class WC_Order {
            public function get_id(): int                    { return 1; }
            public function get_billing_email(): string      { return ''; }
            public function get_billing_first_name(): string { return ''; }
            public function get_billing_last_name(): string  { return ''; }
            public function get_billing_phone(): string      { return ''; }
            public function get_total(): float               { return 0.0; }
            public function get_currency(): string           { return 'COP'; }
            public function get_order_number(): string       { return '1'; }
            public function get_items(): array               { return []; }
            public function get_shipping_total(): float      { return 0.0; }
            public function get_meta( string $key, bool $single = true ): mixed { return ''; }
            public function add_order_note( string $note ): int { return 1; }
            public function update_meta_data( string $key, mixed $value ): void {}
            public function save(): int                      { return 1; }
            public function update_status( string $status, string $note = '' ): bool { return true; }
        }
    }

    // ── Stub LTMS_Utils ───────────────────────────────────────────────────────
    if ( ! class_exists( 'LTMS_Utils', false ) ) {
        class LTMS_Utils {
            public static function format_phone_e164( string $phone, string $country = 'CO' ): string {
                return $phone;
            }
            public static function now_utc(): string {
                return gmdate( 'Y-m-d\TH:i:s\Z' );
            }
            public static function format_price( float $amount, string $currency = 'COP' ): string {
                return number_format( $amount, 2 ) . ' ' . $currency;
            }
        }
    }

    // ── Clase base de tests unitarios ──────────────────────────────────────────
    require_once __DIR__ . '/unit/class-ltms-unit-test-case.php';

    // Brain\Monkey se inicializa en cada test via setUp()/tearDown()
    return;
}

// ══════════════════════════════════════════════════════════════════════════════
// MODO INTEGRACIÓN (con WordPress real)
// ══════════════════════════════════════════════════════════════════════════════
$ltms_wp_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $ltms_wp_tests_dir ) {
    $ltms_possible_dirs = [
        '/tmp/wordpress-tests-lib',
        getenv( 'HOME' ) . '/wordpress-tests-lib',
        sys_get_temp_dir() . '/wordpress-tests-lib',
    ];
    foreach ( $ltms_possible_dirs as $dir ) {
        if ( $dir && is_dir( $dir ) ) {
            $ltms_wp_tests_dir = $dir;
            break;
        }
    }
}

if ( ! $ltms_wp_tests_dir || ! is_dir( $ltms_wp_tests_dir ) ) {
    echo "\n❌  ERROR: WP Test Suite no encontrada.\n";
    echo "   Ejecuta primero:\n";
    echo "   bash bin/install-wp-tests.sh wordpress_test root root 127.0.0.1 latest\n";
    echo "   O exporta: export WP_TESTS_DIR=/ruta/a/wordpress-tests-lib\n\n";
    exit( 1 );
}

echo "\n[LTMS Test Bootstrap] WP Tests Dir: {$ltms_wp_tests_dir}\n";

$ltms_wp_tests_config = $ltms_wp_tests_dir . '/wp-tests-config.php';
if ( ! file_exists( $ltms_wp_tests_config ) ) {
    ltms_bootstrap_generate_wp_config( $ltms_wp_tests_dir );
}

// ── FIX: PHPUnit Polyfills — requerido por WP test suite ──────────────────────
// Debe cargarse ANTES de require bootstrap.php de WordPress.
$ltms_polyfills_autoload = dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
if ( file_exists( $ltms_polyfills_autoload ) ) {
    require_once $ltms_polyfills_autoload;
} else {
    // Fallback: indicar la ruta via constante para que WP bootstrap lo encuentre
    defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' )
        || define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
}

require_once $ltms_wp_tests_dir . '/includes/functions.php';
tests_add_filter( 'muplugins_loaded', 'ltms_bootstrap_load_plugin' );
require $ltms_wp_tests_dir . '/includes/bootstrap.php';

echo "[LTMS Test Bootstrap] WordPress cargado. Iniciando tests...\n\n";

function ltms_bootstrap_load_plugin(): void {
    $wc_plugin = ltms_bootstrap_find_woocommerce();
    if ( $wc_plugin ) {
        echo "[LTMS Bootstrap] Cargando WooCommerce desde: {$wc_plugin}\n";
        require_once $wc_plugin;
    } else {
        echo "[LTMS Bootstrap] ⚠    WooCommerce no encontrado.\n";
        ltms_bootstrap_stub_woocommerce();
    }
    $ltms_plugin_file = dirname( __DIR__ ) . '/lt-marketplace-suite.php';
    if ( ! file_exists( $ltms_plugin_file ) ) {
        echo "❌  ERROR: Plugin principal no encontrado: {$ltms_plugin_file}\n";
        exit( 1 );
    }
    if ( ! defined( 'ABSPATH' ) ) {
        define( 'ABSPATH', trailingslashit( getenv( 'WP_CORE_DIR' ) ?: '/tmp/wordpress' ) );
    }
    require_once $ltms_plugin_file;
    echo "[LTMS Bootstrap] Plugin LTMS cargado. v" . ( defined( 'LTMS_VERSION' ) ? LTMS_VERSION : '?' ) . "\n";
}

function ltms_bootstrap_find_woocommerce(): string|false {
    $wp_core = getenv( 'WP_CORE_DIR' ) ?: '/tmp/wordpress';
    foreach ( [
        $wp_core . '/wp-content/plugins/woocommerce/woocommerce.php',
        dirname( __DIR__, 3 ) . '/woocommerce/woocommerce.php',
        dirname( __DIR__ ) . '/vendor/woocommerce/woocommerce/woocommerce.php',
    ] as $path ) {
        if ( file_exists( $path ) ) { return $path; }
    }
    return false;
}

function ltms_bootstrap_stub_woocommerce(): void {
    if ( ! function_exists( 'WC' ) ) {
        function WC(): object {
            static $i = null;
            if ( null === $i ) { $i = new stdClass(); $i->version = '8.0.0-stub'; }
            return $i;
        }
    }
    defined( 'WC_VERSION' ) || define( 'WC_VERSION', '8.0.0-stub' );
    defined( 'WC_ABSPATH' ) || define( 'WC_ABSPATH', '/tmp/wc-stub/' );
}

function ltms_bootstrap_generate_wp_config( string $tests_dir ): void {
    $db_name   = getenv( 'WP_TESTS_DB_NAME' )      ?: 'wordpress_test';
    $db_user   = getenv( 'WP_TESTS_DB_USER' )      ?: 'root';
    $db_pass   = getenv( 'WP_TESTS_DB_PASS' )      ?: 'root';
    $db_host   = getenv( 'WP_TESTS_DB_HOST' )      ?: '127.0.0.1';
    $table_pfx = getenv( 'WP_TESTS_TABLE_PREFIX' ) ?: 'wptests_';
    $wp_core   = getenv( 'WP_CORE_DIR' )           ?: '/tmp/wordpress';
    $config    = "<?php\ndefine('ABSPATH','{$wp_core}/');\ndefine('DB_NAME','{$db_name}');\ndefine('DB_USER','{$db_user}');\ndefine('DB_PASSWORD','{$db_pass}');\ndefine('DB_HOST','{$db_host}');\ndefine('DB_CHARSET','utf8');\ndefine('DB_COLLATE','');\n\$table_prefix='{$table_pfx}';\ndefine('WP_TESTS_DOMAIN','example.org');\ndefine('WP_TESTS_EMAIL','admin@example.org');\ndefine('WP_TESTS_TITLE','LTMS Test Site');\ndefine('WP_PHP_BINARY','php');\ndefine('WPLANG','');\ndefine('WP_DEBUG',true);\n";
    file_put_contents( $tests_dir . '/wp-tests-config.php', $config );
    echo "[LTMS Bootstrap] wp-tests-config.php generado.\n";
}
