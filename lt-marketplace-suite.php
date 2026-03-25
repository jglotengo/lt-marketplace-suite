<?php
/**
 * Plugin Name:       LT Marketplace Suite (LTMS)
 * Plugin URI:        https://ltmarketplace.co
 * Description:       Plataforma Enterprise Multi-Vendor para WooCommerce. Marketplace, MLM, Fintech, Insurtech, Logística y Cumplimiento Fiscal para Colombia y México.
 * Version:           1.7.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            LT Marketplace Team
 * Author URI:        https://ltmarketplace.co
 * License:           Proprietary
 * License URI:       https://ltmarketplace.co/eula
 * Text Domain:       ltms
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:      8.9
 * Requires Plugins:     woocommerce
 *
 * @package LTMS
 * @version 1.7.0
 */

// ============================================================
// SEGURIDAD: Bloqueo de acceso directo (Abortamos si no es WP)
// ============================================================
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================
// CONSTANTES GLOBALES DEL PLUGIN
// ============================================================
define( 'LTMS_VERSION',          '1.7.0' );
define( 'LTMS_DB_VERSION',       '1.7.0' );
define( 'LTMS_MIN_PHP',          '8.1' );
define( 'LTMS_MIN_WP',           '6.0' );
define( 'LTMS_MIN_WC',           '7.0' );
define( 'LTMS_PLUGIN_FILE',      __FILE__ );
define( 'LTMS_PLUGIN_DIR',       plugin_dir_path( __FILE__ ) );
define( 'LTMS_PLUGIN_URL',       plugin_dir_url( __FILE__ ) );
define( 'LTMS_PLUGIN_BASENAME',  plugin_basename( __FILE__ ) );
define( 'LTMS_INCLUDES_DIR',     LTMS_PLUGIN_DIR . 'includes/' );
define( 'LTMS_ASSETS_URL',       LTMS_PLUGIN_URL . 'assets/' );
define( 'LTMS_TEMPLATES_DIR',    LTMS_PLUGIN_DIR . 'templates/' );
define( 'LTMS_LANGUAGES_DIR',    LTMS_PLUGIN_DIR . 'languages/' );
define( 'LTMS_LOG_DIR',          WP_CONTENT_DIR . '/uploads/ltms-logs/' );
define( 'LTMS_VAULT_DIR',        WP_CONTENT_DIR . '/uploads/ltms-secure-vault/' );

// Tabla prefixes (usamos constantes para facilitar búsquedas en código)
define( 'LTMS_TABLE_PREFIX',     'lt_' );

// Entorno de ejecución (puede sobreescribirse en wp-config.php)
if ( ! defined( 'LTMS_ENVIRONMENT' ) ) {
    define( 'LTMS_ENVIRONMENT', 'production' ); // 'production' | 'staging' | 'development'
}

// País de operación primaria (puede sobreescribirse en wp-config.php)
if ( ! defined( 'LTMS_COUNTRY' ) ) {
    define( 'LTMS_COUNTRY', 'CO' ); // 'CO' (Colombia) | 'MX' (México)
}

// Cifrado AES-256: Salt secundaria (la primaria viene de wp-config.php via LTMS_ENCRYPTION_KEY)
if ( ! defined( 'LTMS_CIPHER_ALGO' ) ) {
    define( 'LTMS_CIPHER_ALGO', 'aes-256-cbc' );
}

// ============================================================
// VERIFICACIÓN DE COMPATIBILIDAD PRE-ARRANQUE
// ============================================================
/**
 * Verifica los requisitos mínimos antes de arrancar el plugin.
 * Si no se cumplen, muestra un aviso admin y NO carga el plugin.
 */
function ltms_check_requirements(): bool {
    if ( version_compare( PHP_VERSION, LTMS_MIN_PHP, '<' ) ) {
        add_action( 'admin_notices', function() {
            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                sprintf(
                    /* translators: 1: PHP version required, 2: current PHP version */
                    esc_html__( 'LT Marketplace Suite requiere PHP %1$s o superior. Versión actual: %2$s. Por favor, actualiza PHP.', 'ltms' ),
                    esc_html( LTMS_MIN_PHP ),
                    esc_html( PHP_VERSION )
                )
            );
        });
        return false;
    }

    if ( ! function_exists( 'WC' ) ) {
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-error"><p>' .
                 esc_html__( 'LT Marketplace Suite requiere WooCommerce activo para funcionar.', 'ltms' ) .
                 '</p></div>';
        });
        return false;
    }

    return true;
}

// ============================================================
// AUTOLOADER PSR-4 (Composer + Fallback Manual)
// ============================================================
/**
 * Carga el autoloader de Composer si existe.
 * Si no, usa un autoloader manual como fallback de emergencia.
 */
function ltms_load_autoloader(): void {
    $composer_autoload = LTMS_PLUGIN_DIR . 'vendor/autoload.php';

    if ( file_exists( $composer_autoload ) ) {
        require_once $composer_autoload;
        return;
    }

    // Fallback: cargar traits e interfaces de forma eager antes del autoloader.
    // El autoloader SPL solo maneja clases; los traits/interfaces tienen prefijo
    // 'trait-' o 'interface-' y no se encuentran con la convención 'class-*'.
    // Sin estas cargas tempranas, cualquier clase que use LTMS_Logger_Aware
    // provoca un fatal en boot() que queda silenciado en producción.
    $eager_files = [
        LTMS_INCLUDES_DIR . 'core/traits/trait-ltms-logger-aware.php',
        LTMS_INCLUDES_DIR . 'core/traits/trait-ltms-singleton.php',
        LTMS_INCLUDES_DIR . 'core/interfaces/interface-ltms-api-client.php',
        LTMS_INCLUDES_DIR . 'core/interfaces/interface-ltms-tax-strategy.php',
    ];
    foreach ( $eager_files as $file ) {
        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }

    // Fallback: Autoloader manual basado en convención de nombres LTMS_*
    spl_autoload_register( function( string $class_name ): void {
        // Solo procesar clases LTMS_*
        if ( strpos( $class_name, 'LTMS_' ) !== 0 ) {
            return;
        }

        // Convertir nombre de clase a ruta de archivo:
        // LTMS_Core_Security => includes/core/class-ltms-core-security.php
        $class_file = strtolower( str_replace( '_', '-', $class_name ) );
        $parts      = explode( '-', $class_file );
        $filename   = 'class-' . $class_file . '.php';

        // ── Clases de 2 partes (ej: LTMS_Admin, LTMS_Roles, LTMS_Utils) ──
        // El guard original `count >= 3` las excluía silenciosamente, causando
        // que add_menu_page nunca se registrara sin Composer instalado.
        if ( count( $parts ) === 2 ) {
            $subdir   = $parts[1];

            // Ruta estándar: includes/{subdir}/class-ltms-{subdir}.php
            // Cubre: LTMS_Admin → admin/, LTMS_Roles → roles/
            $filepath = LTMS_INCLUDES_DIR . $subdir . '/' . $filename;
            if ( file_exists( $filepath ) ) {
                require_once $filepath;
                return;
            }

            // Rutas no estándar: clases cuyo directorio no coincide con su nombre
            $two_part_exceptions = [
                'ltms-wallet'     => 'business/class-ltms-wallet.php',
                'ltms-utils'      => 'core/utils/class-ltms-utils.php',
                'ltms-activator'  => 'core/services/class-ltms-activator.php',
                'ltms-deactivator'=> 'core/services/class-ltms-deactivator.php',
                'ltms-config'     => 'core/class-ltms-config.php',
                'ltms-kernel'     => 'core/class-ltms-kernel.php',
                'ltms-logger'     => 'core/class-ltms-logger.php',
                'ltms-security'   => 'core/class-ltms-security.php',
                'ltms-firewall'   => 'core/class-ltms-firewall.php',
                'ltms-affiliates' => 'business/class-ltms-affiliates.php',
            ];

            if ( isset( $two_part_exceptions[ $class_file ] ) ) {
                $filepath = LTMS_INCLUDES_DIR . $two_part_exceptions[ $class_file ];
                if ( file_exists( $filepath ) ) {
                    require_once $filepath;
                }
            }

            return; // Clase de 2 partes procesada (encontrada o no)
        }

        // ── Clases de 3+ partes (ej: LTMS_Core_Security, LTMS_Admin_Settings) ──
        // Detectar subdirectorio (segundo segmento después de "ltms")
        // Ej: ltms-core-security => includes/core/class-ltms-core-security.php
        // Ej: ltms-api-siigo     => includes/api/class-ltms-api-siigo.php
        if ( count( $parts ) >= 3 ) {
            $subdir   = $parts[1]; // core, api, business, admin, frontend, roles
            $filepath = LTMS_INCLUDES_DIR . $subdir . '/' . $filename;

            if ( file_exists( $filepath ) ) {
                require_once $filepath;
                return;
            }

            // Buscar en subdirectorios de segundo nivel
            $subdirs_map = [
                'core'     => [ 'adapters', 'commands', 'dto', 'interfaces', 'migrations', 'repositories', 'services', 'traits', 'utils', 'value-objects' ],
                'api'      => [ 'builders', 'factories', 'gateways', 'payloads', 'webhooks' ],
                'business' => [ 'events', 'listeners', 'strategies' ],
                'frontend' => [ 'data', 'views', 'views/vendor-parts' ],
                'admin'    => [ 'views' ],
                'shipping' => [],
                'gateway'  => [],
            ];

            if ( isset( $subdirs_map[ $subdir ] ) ) {
                foreach ( $subdirs_map[ $subdir ] as $deep_dir ) {
                    $filepath = LTMS_INCLUDES_DIR . $subdir . '/' . $deep_dir . '/' . $filename;
                    if ( file_exists( $filepath ) ) {
                        require_once $filepath;
                        return;
                    }
                }
            }
        }
    });
}

// ============================================================
// HOOKS DE CICLO DE VIDA DEL PLUGIN
// ============================================================
register_activation_hook( LTMS_PLUGIN_FILE,   'ltms_on_activation' );
register_deactivation_hook( LTMS_PLUGIN_FILE, 'ltms_on_deactivation' );

/**
 * Hook de Activación: Instala tablas DB, roles, páginas y cron jobs.
 */
function ltms_on_activation(): void {
    if ( ! ltms_check_requirements() ) {
        wp_die(
            esc_html__( 'No se pudo activar LT Marketplace Suite: no se cumplen los requisitos mínimos.', 'ltms' ),
            esc_html__( 'Error de Activación', 'ltms' ),
            [ 'back_link' => true ]
        );
    }

    ltms_load_autoloader();

    if ( class_exists( 'LTMS_Core_Activator' ) ) {
        LTMS_Core_Activator::activate();
    }

    // Flush rewrite rules para nuevos endpoints
    flush_rewrite_rules();
}

/**
 * Hook de Desactivación: Elimina cron jobs y limpia transients.
 * NO elimina datos (eso lo hace uninstall.php).
 */
function ltms_on_deactivation(): void {
    if ( class_exists( 'LTMS_Core_Deactivator' ) ) {
        LTMS_Core_Deactivator::deactivate();
    }
    flush_rewrite_rules();
}

// ============================================================
// ARRANQUE PRINCIPAL
// ============================================================
/**
 * Función principal de arranque del plugin.
 * Se ejecuta en el hook 'plugins_loaded' para garantizar que
 * WooCommerce y WordPress estén completamente inicializados.
 */
function ltms_run(): void {
    // Autoloader SIEMPRE primero — necesario incluso para mostrar avisos admin.
    ltms_load_autoloader();

    if ( ! ltms_check_requirements() ) {
        // Sin WooCommerce: registrar menú mínimo para que el plugin sea visible.
        if ( is_admin() ) {
            add_action( 'admin_menu', function() {
                add_menu_page(
                    'LT Marketplace Suite',
                    'LT Marketplace',
                    'manage_options',
                    'ltms-dashboard',
                    function() {
                        echo '<div class="wrap"><h1>LT Marketplace Suite v' . esc_html( LTMS_VERSION ) . '</h1>';
                        echo '<div class="notice notice-error inline"><p>' .
                             esc_html__( 'LT Marketplace Suite requiere WooCommerce activo para funcionar correctamente. Por favor instala y activa WooCommerce.', 'ltms' ) .
                             '</p></div></div>';
                    },
                    'dashicons-store',
                    30
                );
            }, 5 );
        }
        return;
    }

    // Cargar traducciones antes de cualquier otra cosa
    load_plugin_textdomain(
        'ltms',
        false,
        dirname( LTMS_PLUGIN_BASENAME ) . '/languages/'
    );

    // Declarar compatibilidad HPOS (High-Performance Order Storage) de WooCommerce
    add_action( 'before_woocommerce_init', function() {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                LTMS_PLUGIN_FILE,
                true
            );
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'cart_checkout_blocks',
                LTMS_PLUGIN_FILE,
                true
            );
        }
    });

    // Inicializar el Kernel principal
    if ( class_exists( 'LTMS_Core_Kernel' ) ) {
        LTMS_Core_Kernel::get_instance()->boot();
    }
}

// Arrancar en 'plugins_loaded' con prioridad 15 (después de WooCommerce @ 10)
add_action( 'plugins_loaded', 'ltms_run', 15 );
