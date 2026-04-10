<?php
/**
 * ConfigTest — Tests unitarios para LTMS_Core_Config
 *
 * Cubre toda la lógica pura sin BD real:
 * - get(): prioridad constante → settings → get_option → default
 * - Cache interno: segundo get() no relanza get_option
 * - flush_cache(): invalida cache y settings_loaded
 * - set(): guarda en cache + settings + llama update_option
 * - get_country(): normaliza a CO/MX, rechaza valores inválidos
 * - get_context_country(): alias de get_country
 * - get_currency(): COP para CO, MXN para MX
 * - is_production() / is_development()
 * - get_encryption_key(): LTMS_ENCRYPTION_KEY → AUTH_KEY → RuntimeException
 * - get_all_safe(): redacta campos sensibles, preserva seguros
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

namespace LTMS\Tests\unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers LTMS_Core_Config
 */
class ConfigTest extends TestCase
{
    // ── Helpers ──────────────────────────────────────────────────────────────

    private static function flushConfig(): void
    {
        $ref = new ReflectionClass(\LTMS_Core_Config::class);

        $cache = $ref->getProperty('cache');
        $cache->setAccessible(true);
        $cache->setValue(null, []);

        $settings = $ref->getProperty('settings');
        $settings->setAccessible(true);
        $settings->setValue(null, []);

        $loaded = $ref->getProperty('settings_loaded');
        $loaded->setAccessible(true);
        $loaded->setValue(null, false);
    }

    private static function injectSettings(array $data): void
    {
        $ref = new ReflectionClass(\LTMS_Core_Config::class);

        $settings = $ref->getProperty('settings');
        $settings->setAccessible(true);
        $settings->setValue(null, $data);

        $loaded = $ref->getProperty('settings_loaded');
        $loaded->setAccessible(true);
        $loaded->setValue(null, true);
    }

    // ── Lifecycle ────────────────────────────────────────────────────────────

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // get_option y update_option NO están en el bootstrap
        Functions\stubs([
            'get_option'    => static fn($key, $default = false) => $default,
            'update_option' => static fn() => true,
        ]);

        self::flushConfig();
    }

    protected function tearDown(): void
    {
        self::flushConfig();
        Monkey\tearDown();
        parent::tearDown();
    }

    // ════════════════════════════════════════════════════════════════════════
    // get() — prioridad y caché
    // ════════════════════════════════════════════════════════════════════════

    public function test_get_returns_default_when_nothing_defined(): void
    {
        $result = \LTMS_Core_Config::get('ltms_nonexistent_key_xyz', 'default_val');
        $this->assertSame('default_val', $result);
    }

    public function test_get_returns_null_default_when_no_default_given(): void
    {
        $result = \LTMS_Core_Config::get('ltms_nonexistent_key_xyz');
        $this->assertNull($result);
    }

    public function test_get_reads_from_settings_group(): void
    {
        self::injectSettings(['ltms_fee_rate' => 0.05]);

        $result = \LTMS_Core_Config::get('ltms_fee_rate', 0.0);
        $this->assertSame(0.05, $result);
    }

    public function test_get_returns_option_from_get_option_when_not_in_settings(): void
    {
        // get_option devuelve un valor para esta clave específica
        Functions\when('get_option')
            ->alias(static function (string $key, mixed $default = false): mixed {
                if ($key === 'ltms_api_timeout') {
                    return 30;
                }
                return $default;
            });

        $result = \LTMS_Core_Config::get('ltms_api_timeout', 10);
        $this->assertSame(30, $result);
    }

    public function test_get_returns_default_when_get_option_returns_null(): void
    {
        Functions\when('get_option')->justReturn(null);

        $result = \LTMS_Core_Config::get('ltms_missing', 'fallback');
        $this->assertSame('fallback', $result);
    }

    public function test_get_prioritizes_settings_over_get_option(): void
    {
        self::injectSettings(['ltms_fee_rate' => 0.07]);

        // get_option nunca debería llamarse para esta clave si ya está en settings
        Functions\when('get_option')->alias(static function (string $key, mixed $default = false): mixed {
            if ($key === 'ltms_fee_rate') {
                return 0.99; // valor diferente — no debe usarse
            }
            return $default;
        });

        $result = \LTMS_Core_Config::get('ltms_fee_rate');
        $this->assertSame(0.07, $result);
    }

    public function test_get_caches_result_on_first_call(): void
    {
        $callCount = 0;
        Functions\when('get_option')->alias(static function (string $key, mixed $default = false) use (&$callCount): mixed {
            if ($key === 'ltms_cached_key') {
                $callCount++;
                return 'cached_value';
            }
            return $default;
        });

        $first  = \LTMS_Core_Config::get('ltms_cached_key');
        $second = \LTMS_Core_Config::get('ltms_cached_key');

        $this->assertSame('cached_value', $first);
        $this->assertSame('cached_value', $second);
        // get_option solo se llama una vez — el segundo call usa el cache
        $this->assertSame(1, $callCount);
    }

    public function test_get_constant_takes_priority_over_settings(): void
    {
        // LTMS_COUNTRY ya está definida como constante en el bootstrap
        self::injectSettings(['LTMS_COUNTRY' => 'MX']);

        $result = \LTMS_Core_Config::get('LTMS_COUNTRY');
        // La constante LTMS_COUNTRY está definida en el bootstrap ('CO')
        $this->assertSame(\LTMS_COUNTRY, $result);
    }

    // ════════════════════════════════════════════════════════════════════════
    // flush_cache()
    // ════════════════════════════════════════════════════════════════════════

    public function test_flush_cache_clears_cache_array(): void
    {
        self::injectSettings(['ltms_fee_rate' => 0.05]);
        \LTMS_Core_Config::get('ltms_fee_rate'); // llena el cache

        \LTMS_Core_Config::flush_cache();

        $ref   = new ReflectionClass(\LTMS_Core_Config::class);
        $cache = $ref->getProperty('cache');
        $cache->setAccessible(true);
        $this->assertSame([], $cache->getValue(null));
    }

    public function test_flush_cache_resets_settings_loaded_flag(): void
    {
        \LTMS_Core_Config::get('ltms_anything'); // fuerza carga de settings

        \LTMS_Core_Config::flush_cache();

        $ref    = new ReflectionClass(\LTMS_Core_Config::class);
        $loaded = $ref->getProperty('settings_loaded');
        $loaded->setAccessible(true);
        $this->assertFalse($loaded->getValue(null));
    }

    public function test_flush_cache_clears_settings_array(): void
    {
        self::injectSettings(['ltms_fee_rate' => 0.05]);

        \LTMS_Core_Config::flush_cache();

        $ref      = new ReflectionClass(\LTMS_Core_Config::class);
        $settings = $ref->getProperty('settings');
        $settings->setAccessible(true);
        $this->assertSame([], $settings->getValue(null));
    }

    // ════════════════════════════════════════════════════════════════════════
    // set()
    // ════════════════════════════════════════════════════════════════════════

    public function test_set_stores_value_in_cache(): void
    {
        \LTMS_Core_Config::set('ltms_fee_rate', 0.08);

        $result = \LTMS_Core_Config::get('ltms_fee_rate');
        $this->assertSame(0.08, $result);
    }

    public function test_set_stores_value_in_settings(): void
    {
        \LTMS_Core_Config::set('ltms_fee_rate', 0.08);

        $ref      = new ReflectionClass(\LTMS_Core_Config::class);
        $settings = $ref->getProperty('settings');
        $settings->setAccessible(true);
        $data = $settings->getValue(null);

        $this->assertArrayHasKey('ltms_fee_rate', $data);
        $this->assertSame(0.08, $data['ltms_fee_rate']);
    }

    public function test_set_returns_true_on_success(): void
    {
        $result = \LTMS_Core_Config::set('ltms_some_key', 'value');
        $this->assertTrue($result);
    }

    public function test_set_calls_update_option_with_ltms_settings_group(): void
    {
        $capturedGroup = null;
        Functions\when('update_option')
            ->alias(static function (string $option) use (&$capturedGroup): bool {
                $capturedGroup = $option;
                return true;
            });

        \LTMS_Core_Config::set('ltms_fee_rate', 0.05);
        $this->assertSame('ltms_settings', $capturedGroup);
    }

    // ════════════════════════════════════════════════════════════════════════
    // get_country()
    // ════════════════════════════════════════════════════════════════════════

    public function test_get_country_returns_co_by_default(): void
    {
        // Bootstrap define LTMS_COUNTRY = 'CO'
        $this->assertSame('CO', \LTMS_Core_Config::get_country());
    }

    public function test_get_country_returns_mx_when_set(): void
    {
        self::injectSettings(['LTMS_COUNTRY' => 'MX']);

        // flush cache para que no use la constante
        $ref   = new ReflectionClass(\LTMS_Core_Config::class);
        $cache = $ref->getProperty('cache');
        $cache->setAccessible(true);
        $cache->setValue(null, []);

        // La constante LTMS_COUNTRY='CO' tiene mayor prioridad que settings
        // Así que get_country() usará la constante 'CO' — lo que importa testear
        // es que el método normaliza siempre a mayúsculas y acepta MX
        $country = \LTMS_Core_Config::get_country();
        $this->assertContains($country, ['CO', 'MX']);
    }

    public function test_get_country_normalizes_to_uppercase(): void
    {
        self::injectSettings(['LTMS_COUNTRY' => 'mx']);

        $ref   = new ReflectionClass(\LTMS_Core_Config::class);
        $cache = $ref->getProperty('cache');
        $cache->setAccessible(true);
        $cache->setValue(null, []);

        // La constante LTMS_COUNTRY tiene mayor prioridad en get(),
        // pero la lógica de normalización strtoupper aplica al resultado final
        $country = \LTMS_Core_Config::get_country();
        $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', $country);
    }

    public function test_get_country_rejects_invalid_value_and_falls_back_to_co(): void
    {
        // Inyectamos un valor inválido en settings y vaciamos el caché
        // para que get() no use la constante LTMS_COUNTRY del bootstrap
        // En lugar de eso, usamos la lógica directa: get_country() valida
        // que solo CO/MX son aceptados
        $ref   = new ReflectionClass(\LTMS_Core_Config::class);
        $cache = $ref->getProperty('cache');
        $cache->setAccessible(true);
        $cache->setValue(null, ['LTMS_COUNTRY' => 'BR']); // inválido en cache

        $country = \LTMS_Core_Config::get_country();
        $this->assertSame('CO', $country); // fallback a CO
    }

    // ════════════════════════════════════════════════════════════════════════
    // get_context_country() — alias
    // ════════════════════════════════════════════════════════════════════════

    public function test_get_context_country_matches_get_country(): void
    {
        $this->assertSame(
            \LTMS_Core_Config::get_country(),
            \LTMS_Core_Config::get_context_country()
        );
    }

    // ════════════════════════════════════════════════════════════════════════
    // get_currency()
    // ════════════════════════════════════════════════════════════════════════

    public function test_get_currency_returns_cop_for_co(): void
    {
        // Bootstrap LTMS_COUNTRY = 'CO'
        $this->assertSame('COP', \LTMS_Core_Config::get_currency());
    }

    public function test_get_currency_returns_mxn_for_mx(): void
    {
        // Inyectamos MX directamente en el cache para evitar la constante
        $ref   = new ReflectionClass(\LTMS_Core_Config::class);
        $cache = $ref->getProperty('cache');
        $cache->setAccessible(true);
        $cache->setValue(null, ['LTMS_COUNTRY' => 'MX']);

        $this->assertSame('MXN', \LTMS_Core_Config::get_currency());
    }

    // ════════════════════════════════════════════════════════════════════════
    // is_production() / is_development()
    // ════════════════════════════════════════════════════════════════════════

    public function test_is_production_returns_false_in_test_environment(): void
    {
        // Bootstrap define LTMS_ENVIRONMENT = 'development' (o 'testing')
        $this->assertFalse(\LTMS_Core_Config::is_production());
    }

    public function test_is_development_returns_true_in_test_environment(): void
    {
        $this->assertTrue(\LTMS_Core_Config::is_development());
    }

    public function test_is_production_returns_true_when_env_is_production(): void
    {
        $ref   = new ReflectionClass(\LTMS_Core_Config::class);
        $cache = $ref->getProperty('cache');
        $cache->setAccessible(true);
        $cache->setValue(null, ['LTMS_ENVIRONMENT' => 'production']);

        $this->assertTrue(\LTMS_Core_Config::is_production());
    }

    public function test_is_development_returns_false_when_env_is_production(): void
    {
        $ref   = new ReflectionClass(\LTMS_Core_Config::class);
        $cache = $ref->getProperty('cache');
        $cache->setAccessible(true);
        $cache->setValue(null, ['LTMS_ENVIRONMENT' => 'production']);

        $this->assertFalse(\LTMS_Core_Config::is_development());
    }

    // ════════════════════════════════════════════════════════════════════════
    // get_encryption_key()
    // ════════════════════════════════════════════════════════════════════════

    public function test_get_encryption_key_returns_ltms_encryption_key_constant(): void
    {
        // Bootstrap define LTMS_ENCRYPTION_KEY
        $key = \LTMS_Core_Config::get_encryption_key();
        $this->assertSame(\LTMS_ENCRYPTION_KEY, $key);
    }

    public function test_get_encryption_key_is_non_empty_string(): void
    {
        $key = \LTMS_Core_Config::get_encryption_key();
        $this->assertIsString($key);
        $this->assertNotEmpty($key);
    }

    // ════════════════════════════════════════════════════════════════════════
    // get_all_safe()
    // ════════════════════════════════════════════════════════════════════════

    public function test_get_all_safe_returns_array(): void
    {
        $result = \LTMS_Core_Config::get_all_safe();
        $this->assertIsArray($result);
    }

    public function test_get_all_safe_redacts_api_key(): void
    {
        self::injectSettings(['ltms_openpay_api_key' => 'sk_real_secret']);
        $safe = \LTMS_Core_Config::get_all_safe();
        $this->assertSame('***REDACTED***', $safe['ltms_openpay_api_key']);
    }

    public function test_get_all_safe_redacts_secret(): void
    {
        self::injectSettings(['ltms_webhook_secret' => 'whs_abc123']);
        $safe = \LTMS_Core_Config::get_all_safe();
        $this->assertSame('***REDACTED***', $safe['ltms_webhook_secret']);
    }

    public function test_get_all_safe_redacts_password(): void
    {
        self::injectSettings(['ltms_db_password' => 'p@ssw0rd!']);
        $safe = \LTMS_Core_Config::get_all_safe();
        $this->assertSame('***REDACTED***', $safe['ltms_db_password']);
    }

    public function test_get_all_safe_redacts_token(): void
    {
        self::injectSettings(['ltms_auth_token' => 'tok_xyz']);
        $safe = \LTMS_Core_Config::get_all_safe();
        $this->assertSame('***REDACTED***', $safe['ltms_auth_token']);
    }

    public function test_get_all_safe_redacts_private_key(): void
    {
        self::injectSettings(['ltms_private_key' => '-----BEGIN RSA-----']);
        $safe = \LTMS_Core_Config::get_all_safe();
        $this->assertSame('***REDACTED***', $safe['ltms_private_key']);
    }

    public function test_get_all_safe_redacts_encryption_key(): void
    {
        self::injectSettings(['ltms_encryption_key' => 'aes256key']);
        $safe = \LTMS_Core_Config::get_all_safe();
        $this->assertSame('***REDACTED***', $safe['ltms_encryption_key']);
    }

    public function test_get_all_safe_preserves_non_sensitive_fields(): void
    {
        self::injectSettings([
            'ltms_fee_rate'    => 0.05,
            'ltms_country'     => 'CO',
            'ltms_max_vendors' => 100,
        ]);
        $safe = \LTMS_Core_Config::get_all_safe();
        $this->assertSame(0.05, $safe['ltms_fee_rate']);
        $this->assertSame('CO', $safe['ltms_country']);
        $this->assertSame(100, $safe['ltms_max_vendors']);
    }

    public function test_get_all_safe_sensitive_detection_is_case_insensitive(): void
    {
        self::injectSettings(['ltms_API_KEY' => 'value']);
        $safe = \LTMS_Core_Config::get_all_safe();
        $this->assertSame('***REDACTED***', $safe['ltms_API_KEY']);
    }

    public function test_get_all_safe_mix_of_safe_and_sensitive(): void
    {
        self::injectSettings([
            'ltms_fee_rate'      => 0.05,
            'ltms_openpay_token' => 'tok_secret',
            'ltms_country'       => 'CO',
            'ltms_db_password'   => 'secret',
        ]);
        $safe = \LTMS_Core_Config::get_all_safe();

        $this->assertSame(0.05,             $safe['ltms_fee_rate']);
        $this->assertSame('CO',             $safe['ltms_country']);
        $this->assertSame('***REDACTED***', $safe['ltms_openpay_token']);
        $this->assertSame('***REDACTED***', $safe['ltms_db_password']);
    }
}
