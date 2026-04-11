<?php
/**
 * Plugin Name:       LT Marketplace Suite (LTMS)
 * Plugin URI:        https://ltmarketplace.co
 * Description:       Plataforma Enterprise Multi-Vendor para WooCommerce. Marketplace, MLM, Fintech, Insurtech, Logística y Cumplimiento Fiscal para Colombia y México.
 * Version:           2.0.0
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
 * @version 2.0.0
 */

// ============================================================
// SEGURIDAD: Bloqueo de acceso directo (Abortamos si no es WP)
// ============================================================
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Guard: prevenir carga doble si hay dos copias del plugin activas simultáneamente.
// Sin este guard, PHP lanza "Cannot redeclare function ltms_run()" — error crítico.
if ( defined( 'LTMS_LOADED' ) ) {
    return;
}
define( 'LTMS_LOADED', true );

// ============================================================
// CONSTANTES GLOBALES DEL PLUGIN
// ============================================================
define( 'LTMS_VERSION',          '2.0.0' );
define( 'LTMS_DB_VERSION',       '2.0.0' );
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
// CAPTURA DE ERRORES FATALES — Diagnóstico sin email
// ============================================================
// Registrado lo antes posible para capturar cualquier fatal PHP.
// El resultado se muestra en la siguiente carga de wp-admin.
register_shutdown_function( static function() {
    $error = error_get_last();
    if ( ! $error ) {
        return;
    }
    $fatal_types = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ];
    if ( ! in_array( $error['type'], $fatal_types, true ) ) {
        return;
    }
    $content = sprintf(
        "[%s] %s\nArchivo: %s línea %d\n",
        gmdate( 'Y-m-d H:i:s' ),
        $error['message'],
        $error['file'],
        (int) $error['line']
    );
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
    file_put_contents( __DIR__ . '/ltms-fatal-debug.txt', $content, LOCK_EX );
} );

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
        // No retornar — Composer maneja vendor packages pero su classmap NO incluye
        // las clases LTMS_* (nombres con guion-bajo, sin namespace PSR-4).
        // El fallback SPL a continuación cubre todas las clases LTMS_*.
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

            // ── Excepciones 3+ partes ────────────────────────────────────────
            // Clases cuyo archivo OMITE el subpaquete en el nombre:
            // LTMS_Core_Kernel → class-ltms-kernel.php (no class-ltms-core-kernel.php)
            // Sin este mapa el Kernel nunca se carga → cero menús en wp-admin.
            $exceptions_npart = [
                // Core — archivo no incluye 'core-' en el nombre
                'ltms-core-kernel'              => 'core/class-ltms-kernel.php',
                'ltms-core-config'              => 'core/class-ltms-config.php',
                'ltms-core-logger'              => 'core/class-ltms-logger.php',
                'ltms-core-security'            => 'core/class-ltms-security.php',
                'ltms-core-firewall'            => 'core/class-ltms-firewall.php',
                'ltms-core-activator'           => 'core/services/class-ltms-activator.php',
                'ltms-core-deactivator'         => 'core/services/class-ltms-deactivator.php',
                // Business — archivo no incluye 'business-'
                'ltms-business-wallet'          => 'business/class-ltms-wallet.php',
                'ltms-business-order-split'     => 'business/class-ltms-order-split.php',
                // Business — subdir derivado de la clase no existe; archivo en business/
                'ltms-commission-strategy'      => 'business/class-ltms-commission-strategy.php',
                'ltms-tax-engine'               => 'business/class-ltms-tax-engine.php',
                'ltms-referral-tree'            => 'business/class-ltms-referral-tree.php',
                'ltms-payout-scheduler'         => 'business/class-ltms-payout-scheduler.php',
                'ltms-payment-orchestrator'     => 'business/class-ltms-payment-orchestrator.php',
                'ltms-media-guard'              => 'business/class-ltms-media-guard.php',
                'ltms-xcover-checkout-handler'  => 'business/class-ltms-xcover-checkout-handler.php',
                // Business listeners
                'ltms-order-paid-listener'      => 'business/listeners/class-ltms-order-paid-listener.php',
                'ltms-redi-order-listener'      => 'business/listeners/class-ltms-redi-order-listener.php',
                'ltms-xcover-policy-listener'   => 'business/listeners/class-ltms-xcover-policy-listener.php',
                // Business strategies
                'ltms-tax-strategy-colombia'    => 'business/strategies/class-ltms-tax-strategy-colombia.php',
                'ltms-tax-strategy-mexico'      => 'business/strategies/class-ltms-tax-strategy-mexico.php',
                // API — clase base abstracta (subdir 'abstract' no existe)
                'ltms-abstract-api-client'      => 'api/class-ltms-abstract-api-client.php',
                // API webhooks — subdir 'stripe'/'uber' no existe; archivo en api/webhooks/
                'ltms-stripe-webhook-handler'       => 'api/webhooks/class-ltms-stripe-webhook-handler.php',
                'ltms-uber-direct-webhook-handler'  => 'api/webhooks/class-ltms-uber-direct-webhook-handler.php',
                // API webhooks v1.7.4 -- handlers individuales
                'ltms-addi-webhook-handler'         => 'api/webhooks/class-ltms-addi-webhook-handler.php',
                'ltms-openpay-webhook-handler'      => 'api/webhooks/class-ltms-openpay-webhook-handler.php',
                'ltms-siigo-webhook-handler'        => 'api/webhooks/class-ltms-siigo-webhook-handler.php',
                'ltms-aveonline-webhook-handler'    => 'api/webhooks/class-ltms-aveonline-webhook-handler.php',
                'ltms-zapsign-webhook-handler'      => 'api/webhooks/class-ltms-zapsign-webhook-handler.php',
                // API gateways v1.7.4
                'ltms-api-gateway-openpay'          => 'api/gateways/class-ltms-api-gateway-openpay.php',
                'ltms-api-gateway-addi'             => 'api/gateways/class-ltms-api-gateway-addi.php',
                // Business listeners v1.7.4
                'ltms-tptc-listener'                => 'business/listeners/class-ltms-tptc-listener.php',
                'ltms-coupon-attribution-listener'  => 'business/listeners/class-ltms-coupon-attribution-listener.php',
                // Frontend v1.7.4
                'ltms-kitchen-ajax'                 => 'frontend/class-ltms-kitchen-ajax.php',
                'ltms-vendor-settings-saver'        => 'frontend/class-ltms-vendor-settings-saver.php',
                'ltms-secure-downloads'             => 'frontend/class-ltms-secure-downloads.php',
                // Admin v1.7.4
                'ltms-bank-reconciler'              => 'admin/class-ltms-bank-reconciler.php',
                'ltms-legal-evidence-handler'       => 'admin/class-ltms-legal-evidence-handler.php',
                // Frontend — subdir derivado ('dashboard', 'public') no coincide con 'frontend/'
                'ltms-dashboard-logic'          => 'frontend/class-ltms-dashboard-logic.php',
                'ltms-public-auth-handler'      => 'frontend/class-ltms-public-auth-handler.php',
                // Roles — subdir derivado ('external') no existe; archivo en roles/
                'ltms-external-auditor-role'    => 'roles/class-ltms-external-auditor-role.php',
                // Data/DB — subdir incorrecto en nombre de clase
                'ltms-data-masking'             => 'core/class-ltms-data-masking.php',
                'ltms-db-migrations'            => 'core/migrations/class-ltms-db-migrations.php',
            ];

            if ( isset( $exceptions_npart[ $class_file ] ) ) {
                $filepath = LTMS_INCLUDES_DIR . $exceptions_npart[ $class_file ];
                if ( file_exists( $filepath ) ) {
                    require_once $filepath;
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

    try {
        if ( class_exists( 'LTMS_Core_Activator' ) ) {
            LTMS_Core_Activator::activate();
        }
    } catch ( \Throwable $e ) {
        wp_die(
            '<p><strong>LT Marketplace Suite — Error de Activación</strong></p>' .
            '<p>' . esc_html( $e->getMessage() ) . '</p>' .
            '<p>Archivo: <code>' . esc_html( $e->getFile() ) . ':' . (int) $e->getLine() . '</code></p>' .
            '<p><a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">&larr; Volver a Plugins</a></p>',
            'Error de Activación LTMS',
            [ 'response' => 500 ]
        );
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

    // Mostrar en admin cualquier fatal PHP capturado del request anterior.
    $ltms_fatal_file = LTMS_PLUGIN_DIR . 'ltms-fatal-debug.txt';
    if ( is_admin() && file_exists( $ltms_fatal_file ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $ltms_fatal_msg = (string) file_get_contents( $ltms_fatal_file );
        @unlink( $ltms_fatal_file ); // borrar para no repetir el aviso
        add_action( 'admin_notices', static function() use ( $ltms_fatal_msg ) {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>⚠ LTMS — Error PHP fatal capturado (request anterior):</strong><br>';
            echo '<code style="display:block;white-space:pre-wrap;background:#f1f1f1;padding:8px;margin-top:6px">'
                . esc_html( $ltms_fatal_msg )
                . '</code>';
            echo '</p></div>';
        } );
    }

    // ── Garantías de arranque ─────────────────────────────────────────────────
    // Registradas aquí, fuera del Kernel, para que funcionen aunque el boot
    // falle por cualquier causa (WooCommerce ausente, excepción silenciada,
    // clases no encontradas, etc.)
    //
    // 1. Caps: asegurar que el rol administrator tenga todas las caps LTMS.
    //    Se ejecuta en init@1 (antes de admin_menu) en cada request hasta que
    //    las caps estén todas guardadas en la BD.
    add_action( 'init', 'ltms_direct_ensure_caps', 1 );
    //
    // 1b. Filtro dinámico de caps: WordPress cachea $allcaps del usuario ANTES
    //     de que init@1 agregue las caps al rol en la BD. Si el cache aún no
    //     tiene 'ltms_access_dashboard', add_menu_page() retorna false y el
    //     menú real nunca se registra. Este filtro concede todas las caps ltms_*
    //     a administrators en tiempo real, sin depender del cache de roles.
    add_filter( 'user_has_cap', static function ( array $allcaps, array $caps, array $args ): array {
        if ( ! empty( $allcaps['manage_options'] ) ) {
            foreach ( $caps as $cap ) {
                if ( str_starts_with( (string) $cap, 'ltms_' ) ) {
                    $allcaps[ $cap ] = true;
                }
            }
        }
        return $allcaps;
    }, 1, 3 );
    //
    // 2. Menú de emergencia: si el Kernel falló y LTMS_Admin no registró el
    //    menú principal, este fallback lo registra en admin_menu@99 con
    //    manage_options para que siempre sea visible al administrador.
    if ( is_admin() ) {
        add_action( 'admin_menu', 'ltms_emergency_menu_fallback', 99 );
    }

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
        try {
            LTMS_Core_Kernel::get_instance()->boot();
        } catch ( \Throwable $e ) {
            // Capa de seguridad externa: si el catch del Kernel falla por cualquier
            // razón, este catch previene que la excepción escape a plugins_loaded
            // (lo que causaría WordPress recovery mode y tumbaría el sitio).
            error_log( 'LTMS UNCAUGHT KERNEL ERROR: ' . $e->getMessage()
                . ' in ' . $e->getFile() . ':' . $e->getLine() );
        }
    }
}

// Arrancar en 'plugins_loaded' con prioridad 15 (después de WooCommerce @ 10)
add_action( 'plugins_loaded', 'ltms_run', 15 );

// ============================================================
// GARANTÍAS DIRECTAS — sin depender del Kernel ni de clases
// ============================================================

/**
 * Asegura que el rol administrator tenga todas las caps LTMS.
 * Corre en init@1 en cada request; la escritura en BD ocurre solo cuando
 * falta alguna cap (después de eso los has_cap() devuelven true y no escribe).
 */
function ltms_direct_ensure_caps(): void {
    $role = get_role( 'administrator' );
    if ( ! $role ) {
        return;
    }

    $required = [
        'ltms_access_dashboard',
        'ltms_manage_all_vendors',
        'ltms_approve_payouts',
        'ltms_manage_platform_settings',
        'ltms_view_tax_reports',
        'ltms_view_wallet_ledger',
        'ltms_view_all_orders',
        'ltms_manage_kyc',
        'ltms_view_security_logs',
        'ltms_view_audit_log',
        'ltms_view_compliance_logs',
        'ltms_export_reports',
        'ltms_compliance',
        'ltms_manage_roles',
        'ltms_freeze_wallets',
        'ltms_generate_legal_evidence',
    ];

    foreach ( $required as $cap ) {
        if ( ! $role->has_cap( $cap ) ) {
            $role->add_cap( $cap, true );
        }
    }
}

/**
 * Red de seguridad: registra el menú LT Marketplace con manage_options si
 * LTMS_Admin::register_menus() no lo hizo (el Kernel falló).
 * Corre en admin_menu@99 — después de que LTMS_Admin habría corrido en @10.
 */
function ltms_emergency_menu_fallback(): void {
    global $menu;
    foreach ( (array) $menu as $item ) {
        if ( isset( $item[2] ) && 'ltms-dashboard' === $item[2] ) {
            return; // LTMS_Admin ya registró el menú — nada que hacer
        }
    }

    // El Kernel no cargó. Registrar menú de emergencia con manage_options.
    add_menu_page(
        'LT Marketplace Suite',
        'LT Marketplace',
        'manage_options',
        'ltms-dashboard',
        'ltms_emergency_dashboard_page',
        'dashicons-store',
        30
    );
}

/**
 * Página de emergencia — solo visible si el Kernel no pudo cargar.
 * Intenta arrancar el Kernel de nuevo para capturar y mostrar el error exacto.
 */
function ltms_emergency_dashboard_page(): void {
    echo '<div class="wrap">';
    echo '<h1>LT Marketplace Suite v' . esc_html( LTMS_VERSION ) . '</h1>';

    // ── Diagnóstico inline: intentar boot y capturar excepción ───────────────
    $boot_error   = null;
    $boot_success = false;

    // Verificaciones previas al boot
    $checks = [];
    $checks['PHP ' . PHP_VERSION . ' >= 8.1']  = version_compare( PHP_VERSION, '8.1', '>=' );
    $checks['WooCommerce activo']               = function_exists( 'WC' );
    $checks['LTMS_Core_Kernel cargada']         = class_exists( 'LTMS_Core_Kernel' );
    $checks['vendor/autoload.php existe']       = file_exists( LTMS_PLUGIN_DIR . 'vendor/autoload.php' );

    // Rutas clave
    echo '<h3>Rutas del plugin</h3>';
    echo '<pre style="background:#f0f0f0;padding:8px;font-size:12px">';
    echo 'LTMS_PLUGIN_DIR  = ' . esc_html( LTMS_PLUGIN_DIR ) . "\n";
    echo 'LTMS_INCLUDES_DIR= ' . esc_html( LTMS_INCLUDES_DIR ) . "\n";
    echo 'kernel file      = ' . esc_html( LTMS_INCLUDES_DIR . 'core/class-ltms-kernel.php' ) . "\n";
    echo 'kernel exists    = ' . ( file_exists( LTMS_INCLUDES_DIR . 'core/class-ltms-kernel.php' ) ? 'SI' : 'NO' ) . "\n";
    echo 'includes/ exists = ' . ( is_dir( LTMS_INCLUDES_DIR ) ? 'SI' : 'NO' ) . "\n";
    echo 'vendor/ exists   = ' . ( is_dir( LTMS_PLUGIN_DIR . 'vendor' ) ? 'SI' : 'NO' ) . "\n";
    // Listar archivos en la carpeta raíz del plugin
    $root_files = @scandir( LTMS_PLUGIN_DIR );
    echo 'Archivos en raíz = ' . ( $root_files ? esc_html( implode( ', ', array_diff( $root_files, ['.', '..'] ) ) ) : '(error leyendo directorio)' ) . "\n";
    echo '</pre>';

    echo '<table class="widefat" style="margin:12px 0;max-width:600px"><tbody>';
    foreach ( $checks as $label => $ok ) {
        $icon = $ok ? '✅' : '❌';
        echo '<tr><td>' . esc_html( $icon . ' ' . $label ) . '</td></tr>';
    }
    echo '</tbody></table>';

    if ( $checks['LTMS_Core_Kernel cargada'] ) {
        try {
            // Forzar un boot fresh (el singleton ya está marcado como no-booted
            // porque falló antes — si no, usamos reflexión para resetear)
            $kernel = LTMS_Core_Kernel::get_instance();
            $ref    = new ReflectionClass( $kernel );
            $prop   = $ref->getProperty( 'booted' );
            $prop->setAccessible( true );
            $prop->setValue( $kernel, false ); // reset para poder re-intentar

            $kernel->boot();
            $boot_success = true;
        } catch ( \Throwable $e ) {
            $boot_error = $e;
        }
    }

    if ( $boot_success ) {
        echo '<div class="notice notice-success inline"><p>';
        echo '<strong>✅ Boot exitoso en el segundo intento.</strong> Recarga la página.';
        echo '</p></div>';
    } elseif ( $boot_error ) {
        echo '<div class="notice notice-error inline" style="padding:12px">';
        echo '<p><strong>❌ Excepción durante Kernel::boot():</strong></p>';
        echo '<pre style="background:#1d2327;color:#f0f0f1;padding:12px;overflow:auto;border-radius:4px">';
        echo esc_html( get_class( $boot_error ) . ': ' . $boot_error->getMessage() );
        echo "\n\n" . esc_html( $boot_error->getFile() . ':' . $boot_error->getLine() );
        echo "\n\nTrace:\n" . esc_html( $boot_error->getTraceAsString() );
        echo '</pre>';
        echo '</div>';
    } else {
        echo '<div class="notice notice-warning inline"><p>';
        echo '<strong>El Kernel no pudo inicializar</strong> — la clase LTMS_Core_Kernel no se cargó. ';
        echo 'El autoloader no puede encontrar el archivo. ';
        echo 'Verifica que el ZIP se descomprimió completo en el servidor.';
        echo '</p></div>';
    }

    echo '</div>';
}

class_alias('LTMS_Business_Wallet', 'LTMS_Wallet');
