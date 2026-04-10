<?php
/**
 * Tests unitarios del Autoloader LTMS.
 *
 * Verifica que el SPL fallback resuelve correctamente todas las clases
 * crÃ­ticas del plugin sin depender de Composer.
 *
 * HISTORIA: 8 bugs de autoloader encontrados en sesiones anteriores.
 * Estos tests previenen regresiones.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\unit;

/**
 * Class AutoloaderTest
 */
class AutoloaderTest extends LTMS_Unit_Test_Case {

    /**
     * Directorio raÃ­z del plugin (en el contexto real).
     */
    private string $plugin_dir;

    protected function setUp(): void {
        parent::setUp();
        $this->plugin_dir = dirname( __DIR__, 2 ) . '/';
    }

    // â”€â”€â”€ Tests de archivos fÃ­sicos â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Verifica que los archivos de clases crÃ­ticas existen en disco.
     *
     * @dataProvider provider_critical_class_files
     */
    public function test_critical_class_files_exist( string $relative_path ): void {
        $full_path = $this->plugin_dir . $relative_path;
        $this->assertFileExists(
            $full_path,
            "Archivo crÃ­tico no encontrado: {$relative_path}"
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provider_critical_class_files(): array {
        return [
            // Core
            'Kernel'       => [ 'includes/core/class-ltms-kernel.php' ],
            'Config'       => [ 'includes/core/class-ltms-config.php' ],
            'Logger'       => [ 'includes/core/class-ltms-logger.php' ],
            'Security'     => [ 'includes/core/class-ltms-security.php' ],
            'Firewall'     => [ 'includes/core/class-ltms-firewall.php' ],
            // Services
            'Activator'    => [ 'includes/core/services/class-ltms-activator.php' ],
            'Deactivator'  => [ 'includes/core/services/class-ltms-deactivator.php' ],
            // Traits
            'Trait Logger Aware' => [ 'includes/core/traits/trait-ltms-logger-aware.php' ],
            'Trait Singleton'    => [ 'includes/core/traits/trait-ltms-singleton.php' ],
            // Interfaces
            'Interface API Client'   => [ 'includes/core/interfaces/interface-ltms-api-client.php' ],
            'Interface Tax Strategy' => [ 'includes/core/interfaces/interface-ltms-tax-strategy.php' ],
            // Admin
            'Admin'        => [ 'includes/admin/class-ltms-admin.php' ],
            'Roles'        => [ 'includes/roles/class-ltms-roles.php' ],
            // Business
            'Wallet'       => [ 'includes/business/class-ltms-wallet.php' ],
            'Tax Engine'   => [ 'includes/business/class-ltms-tax-engine.php' ],
            'Referral Tree'=> [ 'includes/business/class-ltms-referral-tree.php' ],
            // Frontend
            'Dashboard Logic'     => [ 'includes/frontend/class-ltms-dashboard-logic.php' ],
            'Public Auth Handler' => [ 'includes/frontend/class-ltms-public-auth-handler.php' ],
        ];
    }

    // â”€â”€â”€ Tests de la funciÃ³n ltms_load_autoloader() â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Verifica que ltms_load_autoloader() existe y es callable.
     * Solo aplica en modo integraciÃ³n (plugin principal cargado).
     */
    public function test_autoloader_function_exists(): void {
        if ( ! function_exists( 'ltms_load_autoloader' ) ) {
            $this->markTestSkipped(
                'ltms_load_autoloader() no estÃ¡ definida â€” plugin principal no cargado en modo UNIT_ONLY.'
            );
        }
        $this->assertTrue( function_exists( 'ltms_load_autoloader' ) );
    }

    /**
     * Verifica que NO hay un return prematuro en ltms_load_autoloader().
     * Solo aplica en modo integraciÃ³n (plugin principal cargado).
     */
    public function test_autoloader_registers_spl_handler(): void {
        if ( ! function_exists( 'ltms_load_autoloader' ) ) {
            $this->markTestSkipped( 'Plugin no cargado â€” modo UNIT_ONLY.' );
        }

        ltms_load_autoloader();

        $handlers_after = spl_autoload_functions();

        $this->assertGreaterThan(
            0,
            count( $handlers_after ),
            'spl_autoload_register() nunca fue llamado â€” posible return prematuro en ltms_load_autoloader()'
        );

        $ltms_handlers = array_filter(
            $handlers_after,
            static fn( $h ) => $h instanceof \Closure
        );

        $this->assertNotEmpty(
            $ltms_handlers,
            'No se encontrÃ³ ninguna Closure en spl_autoload_functions() â€” el fallback SPL de LTMS no se registrÃ³'
        );
    }

    /**
     * Verifica que el autoloader resuelve clases de 2 partes (Bug #6).
     */
    public function test_autoloader_resolves_two_part_classes(): void {
        if ( ! function_exists( 'ltms_load_autoloader' ) ) {
            $this->markTestSkipped( 'Plugin no cargado â€” modo UNIT_ONLY.' );
        }

        ltms_load_autoloader();

        $two_part_classes = [
            'LTMS_Admin'    => 'includes/admin/class-ltms-admin.php',
            'LTMS_Roles'    => 'includes/roles/class-ltms-roles.php',
            'LTMS_Wallet'   => 'includes/business/class-ltms-wallet.php',
            'LTMS_Config'   => 'includes/core/class-ltms-config.php',
            'LTMS_Logger'   => 'includes/core/class-ltms-logger.php',
            'LTMS_Security' => 'includes/core/class-ltms-security.php',
            'LTMS_Kernel'   => 'includes/core/class-ltms-kernel.php',
        ];

        foreach ( $two_part_classes as $class => $relative_file ) {
            $file = $this->plugin_dir . $relative_file;
            if ( file_exists( $file ) ) {
                $this->assertTrue(
                    class_exists( $class, true ),
                    "Clase {$class} no fue cargada por el autoloader â€” archivo existe pero class_exists() retornÃ³ false"
                );
            }
        }
    }

    /**
     * Verifica que el autoloader resuelve clases de 3+ partes con excepciones.
     */
    public function test_autoloader_resolves_exception_map_classes(): void {
        if ( ! function_exists( 'ltms_load_autoloader' ) ) {
            $this->markTestSkipped( 'Plugin no cargado â€” modo UNIT_ONLY.' );
        }

        ltms_load_autoloader();

        $exception_classes = [
            'LTMS_Core_Kernel'      => 'core/class-ltms-kernel.php',
            'LTMS_Core_Config'      => 'core/class-ltms-config.php',
            'LTMS_Core_Logger'      => 'core/class-ltms-logger.php',
            'LTMS_Core_Security'    => 'core/class-ltms-security.php',
            'LTMS_Core_Activator'   => 'core/services/class-ltms-activator.php',
            'LTMS_Core_Deactivator' => 'core/services/class-ltms-deactivator.php',
            'LTMS_Business_Wallet'  => 'business/class-ltms-wallet.php',
        ];

        foreach ( $exception_classes as $class => $expected_file_rel ) {
            $file = $this->plugin_dir . 'includes/' . $expected_file_rel;
            if ( ! file_exists( $file ) ) {
                continue;
            }
            $this->assertTrue(
                class_exists( $class, true ),
                "Clase excepciÃ³n {$class} no resuelta â€” deberÃ­a mapear a {$expected_file_rel}"
            );
        }
    }

    // â”€â”€â”€ Tests de constantes del plugin â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /**
     * Verifica que todas las constantes globales estÃ¡n definidas.
     * Solo aplica en modo integraciÃ³n (plugin principal cargado).
     */
    public function test_plugin_constants_defined(): void {
        if ( ! defined( 'LTMS_VERSION' ) ) {
            $this->markTestSkipped(
                'Constantes LTMS no definidas â€” plugin principal no cargado en modo UNIT_ONLY.'
            );
        }

        $required_constants = [
            'LTMS_VERSION',
            'LTMS_DB_VERSION',
            'LTMS_MIN_PHP',
            'LTMS_MIN_WP',
            'LTMS_MIN_WC',
            'LTMS_PLUGIN_FILE',
            'LTMS_PLUGIN_DIR',
            'LTMS_PLUGIN_URL',
            'LTMS_INCLUDES_DIR',
            'LTMS_ASSETS_URL',
            'LTMS_TEMPLATES_DIR',
            'LTMS_LOG_DIR',
        ];

        foreach ( $required_constants as $const ) {
            $this->assertTrue(
                defined( $const ),
                "Constante {$const} no estÃ¡ definida â€” el plugin no se cargÃ³ correctamente"
            );
        }
    }

    /**
     * Verifica el valor de la versiÃ³n actual del plugin.
     */
    public function test_plugin_version_is_valid_semver(): void {
        if ( ! defined( 'LTMS_VERSION' ) ) {
            $this->markTestSkipped( 'Plugin no cargado â€” modo UNIT_ONLY.' );
        }

        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+/',
            LTMS_VERSION,
            'LTMS_VERSION no tiene formato semver vÃ¡lido'
        );
    }

    /**
     * Verifica que LTMS_INCLUDES_DIR apunta a un directorio real.
     */
    public function test_includes_dir_exists(): void {
        if ( ! defined( 'LTMS_INCLUDES_DIR' ) ) {
            $this->markTestSkipped( 'Plugin no cargado â€” modo UNIT_ONLY.' );
        }

        $this->assertDirectoryExists(
            LTMS_INCLUDES_DIR,
            'LTMS_INCLUDES_DIR apunta a un directorio que no existe'
        );
    }

    // ── Tests de archivos de negocio críticos ──────────────────────

    /**
     * Archivos de clases de negocio criticas.
     * @dataProvider provider_business_class_files
     */
    public function test_business_class_files_exist( string $relative_path ): void {
        $this->assertFileExists( $this->plugin_dir . $relative_path,
            "Archivo de negocio no encontrado: {$relative_path}" );
    }

    public static function provider_business_class_files(): array {
        return [
            'Affiliates'           => [ 'includes/business/class-ltms-affiliates.php' ],
            'Commission Strategy'  => [ 'includes/business/class-ltms-commission-strategy.php' ],
            'Order Split'          => [ 'includes/business/class-ltms-order-split.php' ],
            'Payment Orchestrator' => [ 'includes/business/class-ltms-payment-orchestrator.php' ],
            'Payout Scheduler'     => [ 'includes/business/class-ltms-payout-scheduler.php' ],
            'Booking Policy'       => [ 'includes/booking/class-ltms-booking-policy-handler.php' ],
            'Booking Manager'      => [ 'includes/booking/class-ltms-booking-manager.php' ],
            'Tax Engine'           => [ 'includes/business/class-ltms-tax-engine.php' ],
            'Tax Strategy CO'      => [ 'includes/business/strategies/class-ltms-tax-strategy-colombia.php' ],
            'Tax Strategy MX'      => [ 'includes/business/strategies/class-ltms-tax-strategy-mexico.php' ],
            'Data Masking'         => [ 'includes/core/class-ltms-data-masking.php' ],
            'Analytics Manager'    => [ 'includes/frontend/class-ltms-analytics-manager.php' ],
            'Referral Tree'        => [ 'includes/business/class-ltms-referral-tree.php' ],
            'Geo Detector'         => [ 'includes/frontend/class-ltms-geo-detector.php' ],
        ];
    }

    /**
     * Directorios principales del plugin.
     * @dataProvider provider_required_directories
     */
    public function test_required_directories_exist( string $relative_dir ): void {
        $this->assertDirectoryExists( $this->plugin_dir . $relative_dir,
            "Directorio requerido no encontrado: {$relative_dir}" );
    }

    public static function provider_required_directories(): array {
        return [
            'includes'          => [ 'includes' ],
            'includes/core'     => [ 'includes/core' ],
            'includes/business' => [ 'includes/business' ],
            'includes/admin'    => [ 'includes/admin' ],
            'includes/frontend' => [ 'includes/frontend' ],
            'includes/roles'    => [ 'includes/roles' ],
            'includes/shipping' => [ 'includes/shipping' ],
            'tests'             => [ 'tests' ],
            'tests/Unit'        => [ 'tests/Unit' ],
        ];
    }

    /**
     * composer.json debe existir y ser JSON valido.
     */
    public function test_composer_json_is_valid(): void {
        $path = $this->plugin_dir . 'composer.json';
        $this->assertFileExists( $path );
        $data = json_decode( file_get_contents( $path ), true );
        $this->assertNotNull( $data, 'composer.json no es JSON valido' );
        $this->assertArrayHasKey( 'name', $data );
    }

    /**
     * phpunit.xml debe existir y contener la suite unit.
     */
    public function test_phpunit_xml_defines_unit_suite(): void {
        $path = $this->plugin_dir . 'phpunit.xml';
        $this->assertFileExists( $path );
        $this->assertStringContainsString( 'unit', file_get_contents( $path ),
            'phpunit.xml no define la suite unit' );
    }

    /**
     * El archivo principal del plugin debe existir y ser legible.
     */
    public function test_main_plugin_file_exists_and_readable(): void {
        $path = $this->plugin_dir . 'lt-marketplace-suite.php';
        $this->assertFileExists( $path );
        $this->assertFileIsReadable( $path );
    }

    /**
     * Clases criticas cargadas en modo UNIT_ONLY.
     * @dataProvider provider_classes_loaded_in_unit_only
     */
    public function test_class_is_loaded_in_unit_only( string $class_name ): void {
        $this->assertTrue( class_exists( $class_name ),
            "Clase {$class_name} no cargada en modo UNIT_ONLY" );
    }

    public static function provider_classes_loaded_in_unit_only(): array {
        return [
            'LTMS_Core_Firewall'   => [ 'LTMS_Core_Firewall' ],
            'LTMS_Tax_Engine'      => [ 'LTMS_Tax_Engine' ],
            'LTMS_Business_Wallet' => [ 'LTMS_Business_Wallet' ],
            'LTMS_Core_Config'     => [ 'LTMS_Core_Config' ],
            'LTMS_Core_Logger'     => [ 'LTMS_Core_Logger' ],
            'LTMS_Tax_Strategy_CO' => [ 'LTMS_Tax_Strategy_Colombia' ],
            'LTMS_Tax_Strategy_MX' => [ 'LTMS_Tax_Strategy_Mexico' ],
        ];
    }

    /**
     * Aliases de clase disponibles en modo UNIT_ONLY.
     * @dataProvider provider_class_aliases
     */
    public function test_class_alias_resolves_correctly( string $alias, string $real ): void {
        $this->assertTrue( class_exists( $alias ),
            "Alias {$alias} no resuelve" );
        $this->assertSame(
            ( new \ReflectionClass( $alias ) )->getName(),
            ( new \ReflectionClass( $real ) )->getName()
        );
    }

    public static function provider_class_aliases(): array {
        return [
            'LTMS_Wallet'   => [ 'LTMS_Wallet',   'LTMS_Business_Wallet' ],
            'LTMS_Config'   => [ 'LTMS_Config',   'LTMS_Core_Config'     ],
            'LTMS_Logger'   => [ 'LTMS_Logger',   'LTMS_Core_Logger'     ],
            'LTMS_Kernel'   => [ 'LTMS_Kernel',   'LTMS_Core_Kernel'     ],
            'LTMS_Security' => [ 'LTMS_Security', 'LTMS_Core_Security'   ],
        ];
    }

    /**
     * Interfaces criticas deben existir como archivos.
     * @dataProvider provider_interface_files
     */
    public function test_interface_files_exist( string $relative_path ): void {
        $this->assertFileExists( $this->plugin_dir . $relative_path,
            "Interfaz no encontrada: {$relative_path}" );
    }

    public static function provider_interface_files(): array {
        return [
            'API Client Interface'   => [ 'includes/core/interfaces/interface-ltms-api-client.php' ],
            'Tax Strategy Interface' => [ 'includes/core/interfaces/interface-ltms-tax-strategy.php' ],
        ];
    }
}
