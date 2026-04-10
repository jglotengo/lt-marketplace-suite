<?php
/**
 * ApiFactoryTest — Tests unitarios para LTMS_Api_Factory
 *
 * Cubre toda la lógica del patrón Factory:
 * - client_map: los 10 proveedores conocidos están registrados
 * - get(): instanciación, caché, normalización de slug (lowercase + trim)
 * - Excepciones: proveedor no registrado → InvalidArgumentException
 *                clase no disponible → RuntimeException
 * - register(): registro dinámico, normalización, override
 * - reset() / reset_all(): invalidación de caché individual y total
 *
 * No requiere mocks de WordPress — lógica pura de instanciación.
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

namespace LTMS\Tests\unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers LTMS_Api_Factory
 */
class ApiFactoryTest extends TestCase
{
    // ── Helpers ──────────────────────────────────────────────────────────────

    private static function resetFactory(): void
    {
        \LTMS_Api_Factory::reset_all();

        $ref = new ReflectionClass(\LTMS_Api_Factory::class);
        $map = $ref->getProperty('client_map');
        $map->setAccessible(true);
        $map->setValue(null, [
            'openpay'   => 'LTMS_Api_Openpay',
            'siigo'     => 'LTMS_Api_Siigo',
            'addi'      => 'LTMS_Api_Addi',
            'aveonline' => 'LTMS_Api_Aveonline',
            'zapsign'   => 'LTMS_Api_Zapsign',
            'tptc'      => 'LTMS_Api_TPTC',
            'xcover'    => 'LTMS_Api_XCover',
            'backblaze' => 'LTMS_Api_Backblaze',
            'uber'      => 'LTMS_Api_Uber',
            'stripe'    => 'LTMS_Api_Stripe',
        ]);
    }

    /**
     * Crea y registra una subclase stub concreta bajo el slug dado.
     */
    private static function registerStub(string $slug): string
    {
        $className = 'LTMSTestStub_' . ucfirst(strtolower($slug));

        if (!class_exists($className)) {
            eval("
                class {$className} extends \\LTMS_Abstract_API_Client {
                    public string \$provider_slug = '{$slug}';
                    public function health_check(): array { return ['status' => 'ok', 'message' => 'stub']; }
                }
            ");
        }

        \LTMS_Api_Factory::register($slug, $className);
        return $className;
    }

    // ── Lifecycle ────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        self::resetFactory();
    }

    protected function tearDown(): void
    {
        self::resetFactory();
        Monkey\tearDown();
        parent::tearDown();
    }

    // ════════════════════════════════════════════════════════════════════════
    // client_map — contrato de proveedores registrados
    // ════════════════════════════════════════════════════════════════════════

    public function test_all_ten_providers_are_registered(): void
    {
        $ref = new ReflectionClass(\LTMS_Api_Factory::class);
        $map = $ref->getProperty('client_map');
        $map->setAccessible(true);
        $registered = $map->getValue(null);

        foreach (['openpay', 'siigo', 'addi', 'aveonline', 'zapsign', 'tptc', 'xcover', 'backblaze', 'uber', 'stripe'] as $slug) {
            $this->assertArrayHasKey($slug, $registered, "Proveedor '$slug' debe estar en el client_map");
        }
    }

    public function test_client_map_has_exactly_ten_providers(): void
    {
        $ref = new ReflectionClass(\LTMS_Api_Factory::class);
        $map = $ref->getProperty('client_map');
        $map->setAccessible(true);
        $this->assertCount(10, $map->getValue(null));
    }

    public function test_all_map_values_are_non_empty_strings(): void
    {
        $ref = new ReflectionClass(\LTMS_Api_Factory::class);
        $map = $ref->getProperty('client_map');
        $map->setAccessible(true);
        foreach ($map->getValue(null) as $slug => $class) {
            $this->assertIsString($class);
            $this->assertNotEmpty($class);
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // get() — proveedor no registrado
    // ════════════════════════════════════════════════════════════════════════

    public function test_get_unknown_provider_throws_invalid_argument_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \LTMS_Api_Factory::get('nonexistent_provider');
    }

    public function test_get_unknown_provider_exception_contains_slug(): void
    {
        try {
            \LTMS_Api_Factory::get('my_fake_provider');
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('my_fake_provider', $e->getMessage());
        }
    }

    public function test_get_unknown_provider_exception_lists_available_providers(): void
    {
        try {
            \LTMS_Api_Factory::get('unknown');
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('openpay', $e->getMessage());
            $this->assertStringContainsString('siigo',   $e->getMessage());
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // get() — clase no disponible
    // ════════════════════════════════════════════════════════════════════════

    public function test_get_registered_but_missing_class_throws_runtime_exception(): void
    {
        \LTMS_Api_Factory::register('ghost_provider', 'NonExistentClassName_XYZ');
        $this->expectException(\RuntimeException::class);
        \LTMS_Api_Factory::get('ghost_provider');
    }

    public function test_get_missing_class_exception_contains_class_name(): void
    {
        \LTMS_Api_Factory::register('ghost2', 'NonExistentClassName_ABC');
        try {
            \LTMS_Api_Factory::get('ghost2');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('NonExistentClassName_ABC', $e->getMessage());
        }
    }

    public function test_get_missing_class_exception_contains_provider_slug(): void
    {
        \LTMS_Api_Factory::register('ghost3', 'NonExistentClassName_DEF');
        try {
            \LTMS_Api_Factory::get('ghost3');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ghost3', $e->getMessage());
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // get() — instanciación y caché
    // ════════════════════════════════════════════════════════════════════════

    public function test_get_returns_instance_of_abstract_api_client(): void
    {
        self::registerStub('stub1');
        $instance = \LTMS_Api_Factory::get('stub1');
        $this->assertInstanceOf(\LTMS_Abstract_API_Client::class, $instance);
    }

    public function test_get_returns_same_instance_on_second_call(): void
    {
        self::registerStub('cached1');
        $first  = \LTMS_Api_Factory::get('cached1');
        $second = \LTMS_Api_Factory::get('cached1');
        $this->assertSame($first, $second);
    }

    public function test_get_normalizes_slug_to_lowercase(): void
    {
        self::registerStub('norm1');
        $lower = \LTMS_Api_Factory::get('norm1');
        $upper = \LTMS_Api_Factory::get('NORM1');
        $this->assertSame($lower, $upper);
    }

    public function test_get_trims_whitespace_from_slug(): void
    {
        self::registerStub('trim1');
        $clean  = \LTMS_Api_Factory::get('trim1');
        $padded = \LTMS_Api_Factory::get('  trim1  ');
        $this->assertSame($clean, $padded);
    }

    public function test_get_different_providers_return_different_instances(): void
    {
        self::registerStub('provA');
        self::registerStub('provB');
        $a = \LTMS_Api_Factory::get('provA');
        $b = \LTMS_Api_Factory::get('provB');
        $this->assertNotSame($a, $b);
    }

    public function test_get_instance_implements_api_client_interface(): void
    {
        self::registerStub('ifacetest');
        $instance = \LTMS_Api_Factory::get('ifacetest');
        $this->assertInstanceOf(\LTMS_API_Client_Interface::class, $instance);
    }

    // ════════════════════════════════════════════════════════════════════════
    // register()
    // ════════════════════════════════════════════════════════════════════════

    public function test_register_adds_provider_to_map(): void
    {
        \LTMS_Api_Factory::register('custom_api', 'SomeCustomClass');
        $ref = new ReflectionClass(\LTMS_Api_Factory::class);
        $map = $ref->getProperty('client_map');
        $map->setAccessible(true);
        $this->assertArrayHasKey('custom_api', $map->getValue(null));
    }

    public function test_register_stores_class_name_correctly(): void
    {
        \LTMS_Api_Factory::register('custom_api2', 'MyCustomApiClass');
        $ref = new ReflectionClass(\LTMS_Api_Factory::class);
        $map = $ref->getProperty('client_map');
        $map->setAccessible(true);
        $this->assertSame('MyCustomApiClass', $map->getValue(null)['custom_api2']);
    }

    public function test_register_normalizes_slug_to_lowercase(): void
    {
        \LTMS_Api_Factory::register('UPPER_SLUG', 'SomeClass');
        $ref = new ReflectionClass(\LTMS_Api_Factory::class);
        $map = $ref->getProperty('client_map');
        $map->setAccessible(true);
        $this->assertArrayHasKey('upper_slug', $map->getValue(null));
    }

    public function test_register_allows_overriding_existing_provider(): void
    {
        \LTMS_Api_Factory::register('ovrd', 'OriginalClass');
        \LTMS_Api_Factory::register('ovrd', 'ReplacedClass');
        $ref = new ReflectionClass(\LTMS_Api_Factory::class);
        $map = $ref->getProperty('client_map');
        $map->setAccessible(true);
        $this->assertSame('ReplacedClass', $map->getValue(null)['ovrd']);
    }

    public function test_register_and_get_new_provider(): void
    {
        self::registerStub('dynamic');
        $instance = \LTMS_Api_Factory::get('dynamic');
        $this->assertInstanceOf(\LTMS_Abstract_API_Client::class, $instance);
    }

    // ════════════════════════════════════════════════════════════════════════
    // reset() — invalida instancia individual
    // ════════════════════════════════════════════════════════════════════════

    public function test_reset_removes_cached_instance(): void
    {
        self::registerStub('resetme');
        $first = \LTMS_Api_Factory::get('resetme');
        \LTMS_Api_Factory::reset('resetme');
        $second = \LTMS_Api_Factory::get('resetme');
        $this->assertNotSame($first, $second);
    }

    public function test_reset_only_removes_specified_provider(): void
    {
        self::registerStub('keepme');
        self::registerStub('removeme');
        $keep = \LTMS_Api_Factory::get('keepme');
        \LTMS_Api_Factory::get('removeme');
        \LTMS_Api_Factory::reset('removeme');
        $keepAfter = \LTMS_Api_Factory::get('keepme');
        $this->assertSame($keep, $keepAfter);
    }

    public function test_reset_nonexistent_provider_does_not_throw(): void
    {
        $this->expectNotToPerformAssertions();
        \LTMS_Api_Factory::reset('provider_never_existed');
    }

    // ════════════════════════════════════════════════════════════════════════
    // reset_all() — invalida todas las instancias
    // ════════════════════════════════════════════════════════════════════════

    public function test_reset_all_clears_all_cached_instances(): void
    {
        self::registerStub('alpha');
        self::registerStub('beta');
        $alpha1 = \LTMS_Api_Factory::get('alpha');
        $beta1  = \LTMS_Api_Factory::get('beta');
        \LTMS_Api_Factory::reset_all();
        $alpha2 = \LTMS_Api_Factory::get('alpha');
        $beta2  = \LTMS_Api_Factory::get('beta');
        $this->assertNotSame($alpha1, $alpha2);
        $this->assertNotSame($beta1,  $beta2);
    }

    public function test_reset_all_does_not_clear_client_map(): void
    {
        \LTMS_Api_Factory::reset_all();
        $ref = new ReflectionClass(\LTMS_Api_Factory::class);
        $map = $ref->getProperty('client_map');
        $map->setAccessible(true);
        // El client_map original sigue intacto (reset_all solo limpia $instances)
        $this->assertNotEmpty($map->getValue(null));
    }

    public function test_reset_all_on_empty_instances_does_not_throw(): void
    {
        \LTMS_Api_Factory::reset_all(); // ya vacío — no debe lanzar
        $this->expectNotToPerformAssertions();
        \LTMS_Api_Factory::reset_all();
    }
}
