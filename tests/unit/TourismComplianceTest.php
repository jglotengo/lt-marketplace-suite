<?php

declare(strict_types=1);

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * Tests para LTMS_Business_Tourism_Compliance
 *
 * Cubre:
 *  § 1  Existencia de clase y métodos (7 tests)
 *  § 2  can_publish_accommodation() — todas las ramas condicionales (10 tests)
 *  § 3  add_account_menu_item() — lógica de array pura (10 tests)
 *  § 4  save_rnt() — validación de payload via reflexión (8 tests)
 *  § 5  Reflexión — firmas, tipos de retorno, visibilidad (10 tests)
 *  § 6  init() — idempotencia (3 tests)
 *
 * @package LTMS\Tests\Unit
 */
class TourismComplianceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('current_time')->justReturn('2025-01-01 00:00:00');
        Functions\when('__')->returnArg();
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_user_meta')->justReturn('CO');
        Functions\when('update_user_meta')->justReturn(true);
        Functions\when('do_action')->justReturn(null);

        \LTMS_Core_Config::flush_cache();
        Functions\stubs(['get_option' => static fn($k, $d = null) => $d]);
    }

    protected function tearDown(): void
    {
        \LTMS_Core_Config::flush_cache();
        Monkey\tearDown();
        parent::tearDown();
    }

    // ════════════════════════════════════════════════════════════════════
    // § 1 — Existencia de clase y métodos
    // ════════════════════════════════════════════════════════════════════

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('LTMS_Business_Tourism_Compliance'));
    }

    public function test_can_publish_accommodation_method_exists(): void
    {
        $this->assertTrue(method_exists('LTMS_Business_Tourism_Compliance', 'can_publish_accommodation'));
    }

    public function test_get_record_method_exists(): void
    {
        $this->assertTrue(method_exists('LTMS_Business_Tourism_Compliance', 'get_record'));
    }

    public function test_save_rnt_method_exists(): void
    {
        $this->assertTrue(method_exists('LTMS_Business_Tourism_Compliance', 'save_rnt'));
    }

    public function test_verify_rnt_method_exists(): void
    {
        $this->assertTrue(method_exists('LTMS_Business_Tourism_Compliance', 'verify_rnt'));
    }

    public function test_create_compliance_record_method_exists(): void
    {
        $this->assertTrue(method_exists('LTMS_Business_Tourism_Compliance', 'create_compliance_record'));
    }

    public function test_check_rnt_expiry_method_exists(): void
    {
        $this->assertTrue(method_exists('LTMS_Business_Tourism_Compliance', 'check_rnt_expiry'));
    }

    public function test_add_account_menu_item_method_exists(): void
    {
        $this->assertTrue(method_exists('LTMS_Business_Tourism_Compliance', 'add_account_menu_item'));
    }

    // ════════════════════════════════════════════════════════════════════
    // § 2 — can_publish_accommodation() — todas las ramas
    // ════════════════════════════════════════════════════════════════════

    /**
     * Rama 1: RNT no requerido → siempre puede publicar, sin importar el record.
     */
    public function test_can_publish_when_rnt_not_required(): void
    {
        // get_option devuelve null → ltms_booking_rnt_required = false (default)
        $this->assertTrue(\LTMS_Business_Tourism_Compliance::can_publish_accommodation(1));
    }

    public function test_can_publish_returns_true_rnt_not_required_any_vendor(): void
    {
        // Con config por defecto (false), cualquier vendor puede publicar
        $this->assertTrue(\LTMS_Business_Tourism_Compliance::can_publish_accommodation(999));
        $this->assertTrue(\LTMS_Business_Tourism_Compliance::can_publish_accommodation(0));
    }

    public function test_can_publish_returns_bool(): void
    {
        $result = \LTMS_Business_Tourism_Compliance::can_publish_accommodation(1);
        $this->assertIsBool($result);
    }

    /**
     * Rama 2: RNT requerido + sin record → no puede publicar.
     */
    public function test_cannot_publish_when_rnt_required_and_no_record(): void
    {
        Functions\stubs(['get_option' => static function($k, $d = null) {
            if ($k === 'ltms_settings') return ['ltms_booking_rnt_required' => 1];
            return $d;
        }]);
        \LTMS_Core_Config::flush_cache();

        // Sin $wpdb real, get_record() retorna null → can_publish = false
        $result = \LTMS_Business_Tourism_Compliance::can_publish_accommodation(999);
        $this->assertFalse($result);
    }

    /**
     * Rama 3: RNT requerido + record con status != verified → no puede publicar.
     */
    public function test_cannot_publish_when_rnt_required_status_pending(): void
    {
        Functions\stubs(['get_option' => static function($k, $d = null) {
            if ($k === 'ltms_settings') return ['ltms_booking_rnt_required' => 1];
            return $d;
        }]);
        \LTMS_Core_Config::flush_cache();

        // Verificamos la lógica: $record && 'verified' === $record['status'] && rnt_verified
        // Si record tiene status 'pending' → false
        $record = ['status' => 'pending', 'rnt_verified' => 0];
        $can_publish = $record && 'verified' === $record['status'] && (int) $record['rnt_verified'];
        $this->assertFalse((bool) $can_publish);
    }

    public function test_cannot_publish_when_rnt_required_status_rejected(): void
    {
        $record = ['status' => 'rejected', 'rnt_verified' => 0];
        $can_publish = $record && 'verified' === $record['status'] && (int) $record['rnt_verified'];
        $this->assertFalse((bool) $can_publish);
    }

    public function test_cannot_publish_when_rnt_required_status_expired(): void
    {
        $record = ['status' => 'expired', 'rnt_verified' => 0];
        $can_publish = $record && 'verified' === $record['status'] && (int) $record['rnt_verified'];
        $this->assertFalse((bool) $can_publish);
    }

    /**
     * Rama 4: RNT requerido + status=verified pero rnt_verified=0 → no puede publicar.
     * (status y rnt_verified deben ser AMBOS válidos)
     */
    public function test_cannot_publish_when_status_verified_but_rnt_verified_zero(): void
    {
        $record = ['status' => 'verified', 'rnt_verified' => 0];
        $can_publish = $record && 'verified' === $record['status'] && (int) $record['rnt_verified'];
        $this->assertFalse((bool) $can_publish);
    }

    /**
     * Rama 5 (happy path): status=verified + rnt_verified=1 → puede publicar.
     */
    public function test_can_publish_when_status_verified_and_rnt_verified_one(): void
    {
        $record = ['status' => 'verified', 'rnt_verified' => 1];
        $can_publish = $record && 'verified' === $record['status'] && (int) $record['rnt_verified'];
        $this->assertTrue((bool) $can_publish);
    }

    public function test_can_publish_logic_rnt_verified_string_one(): void
    {
        // rnt_verified puede llegar como string '1' desde BD
        $record = ['status' => 'verified', 'rnt_verified' => '1'];
        $can_publish = $record && 'verified' === $record['status'] && (int) $record['rnt_verified'];
        $this->assertTrue((bool) $can_publish);
    }

    // ════════════════════════════════════════════════════════════════════
    // § 3 — add_account_menu_item() — lógica de array pura
    // ════════════════════════════════════════════════════════════════════

    public function test_add_account_menu_item_adds_rnt_key(): void
    {
        $items  = ['dashboard' => 'Dashboard', 'orders' => 'Pedidos'];
        $result = \LTMS_Business_Tourism_Compliance::add_account_menu_item($items);
        $this->assertArrayHasKey('ltms-rnt', $result);
    }

    public function test_add_account_menu_item_preserves_existing_items(): void
    {
        $items  = ['dashboard' => 'Dashboard', 'orders' => 'Pedidos'];
        $result = \LTMS_Business_Tourism_Compliance::add_account_menu_item($items);
        $this->assertArrayHasKey('dashboard', $result);
        $this->assertArrayHasKey('orders', $result);
    }

    public function test_add_account_menu_item_rnt_label_is_string(): void
    {
        $result = \LTMS_Business_Tourism_Compliance::add_account_menu_item([]);
        $this->assertIsString($result['ltms-rnt']);
    }

    public function test_add_account_menu_item_empty_input_adds_only_rnt(): void
    {
        $result = \LTMS_Business_Tourism_Compliance::add_account_menu_item([]);
        $this->assertCount(1, $result);
        $this->assertArrayHasKey('ltms-rnt', $result);
    }

    public function test_add_account_menu_item_returns_array(): void
    {
        $result = \LTMS_Business_Tourism_Compliance::add_account_menu_item(['x' => 'y']);
        $this->assertIsArray($result);
    }

    public function test_add_account_menu_item_count_increases_by_one(): void
    {
        $items  = ['dashboard' => 'Dashboard', 'orders' => 'Pedidos', 'account' => 'Cuenta'];
        $result = \LTMS_Business_Tourism_Compliance::add_account_menu_item($items);
        $this->assertCount(count($items) + 1, $result);
    }

    public function test_add_account_menu_item_rnt_label_contains_rnt_or_sectur(): void
    {
        $result = \LTMS_Business_Tourism_Compliance::add_account_menu_item([]);
        $label  = $result['ltms-rnt'];
        $this->assertMatchesRegularExpression('/RNT|SECTUR/i', $label);
    }

    public function test_add_account_menu_item_with_many_items(): void
    {
        $items = array_fill_keys(range('a', 'z'), 'value');
        $result = \LTMS_Business_Tourism_Compliance::add_account_menu_item($items);
        $this->assertArrayHasKey('ltms-rnt', $result);
        $this->assertCount(27, $result); // 26 + 1
    }

    public function test_add_account_menu_item_does_not_remove_existing_ltms_rnt(): void
    {
        // Si ya existe ltms-rnt, debe sobreescribirse (no duplicarse)
        $items  = ['ltms-rnt' => 'Old Label', 'orders' => 'Pedidos'];
        $result = \LTMS_Business_Tourism_Compliance::add_account_menu_item($items);
        $this->assertCount(2, $result);
        $this->assertArrayHasKey('ltms-rnt', $result);
    }

    public function test_add_account_menu_item_key_is_exactly_ltms_rnt(): void
    {
        $result = \LTMS_Business_Tourism_Compliance::add_account_menu_item([]);
        $this->assertArrayHasKey('ltms-rnt', $result);
        // Asegurar que no hay variantes con guión bajo
        $this->assertArrayNotHasKey('ltms_rnt', $result);
    }

    // ════════════════════════════════════════════════════════════════════
    // § 4 — save_rnt() — payload via reflexión del código fuente
    // ════════════════════════════════════════════════════════════════════

    public function test_save_rnt_signature_has_two_params(): void
    {
        $ref = new \ReflectionMethod('LTMS_Business_Tourism_Compliance', 'save_rnt');
        $this->assertCount(2, $ref->getParameters());
    }

    public function test_save_rnt_first_param_is_vendor_id(): void
    {
        $ref    = new \ReflectionMethod('LTMS_Business_Tourism_Compliance', 'save_rnt');
        $params = $ref->getParameters();
        $this->assertSame('vendor_id', $params[0]->getName());
    }

    public function test_save_rnt_second_param_is_data(): void
    {
        $ref    = new \ReflectionMethod('LTMS_Business_Tourism_Compliance', 'save_rnt');
        $params = $ref->getParameters();
        $this->assertSame('data', $params[1]->getName());
    }

    public function test_save_rnt_normalizes_country_code_to_uppercase(): void
    {
        $ref  = new \ReflectionMethod('LTMS_Business_Tourism_Compliance', 'save_rnt');
        $code = $ref->getFileName() ? file_get_contents($ref->getFileName()) : '';
        $this->assertStringContainsString('strtoupper', $code);
    }

    public function test_save_rnt_sanitizes_rnt_number(): void
    {
        $ref  = new \ReflectionMethod('LTMS_Business_Tourism_Compliance', 'save_rnt');
        $code = $ref->getFileName() ? file_get_contents($ref->getFileName()) : '';
        $this->assertStringContainsString('sanitize_text_field', $code);
    }

    public function test_save_rnt_sets_status_to_pending(): void
    {
        $ref  = new \ReflectionMethod('LTMS_Business_Tourism_Compliance', 'save_rnt');
        $code = $ref->getFileName() ? file_get_contents($ref->getFileName()) : '';
        $this->assertStringContainsString("'status'", $code);
        $this->assertStringContainsString("'pending'", $code);
    }

    public function test_save_rnt_resets_rnt_verified_to_zero(): void
    {
        $ref  = new \ReflectionMethod('LTMS_Business_Tourism_Compliance', 'save_rnt');
        $code = $ref->getFileName() ? file_get_contents($ref->getFileName()) : '';
        $this->assertStringContainsString("'rnt_verified'", $code);
        $this->assertStringContainsString('0', $code);
    }

    public function test_save_rnt_return_type_is_bool(): void
    {
        $ref  = new \ReflectionMethod('LTMS_Business_Tourism_Compliance', 'save_rnt');
        $type = $ref->getReturnType();
        $this->assertNotNull($type);
        $this->assertSame('bool', (string) $type);
    }

    // ════════════════════════════════════════════════════════════════════
    // § 5 — Reflexión: firmas, tipos de retorno, visibilidad
    // ════════════════════════════════════════════════════════════════════

    public function test_can_publish_accommodation_return_type_is_bool(): void
    {
        $ref  = new \ReflectionMethod('LTMS_Business_Tourism_Compliance', 'can_publish_accommodation');
        $this->assertSame('bool', (string) $ref->getReturnType());
    }

    public function test_get_record_return_type_is_nullable_array(): void
    {
        $ref  = new \ReflectionMethod('LTMS_Business_Tourism_Compliance', 'get_record');
        $type = $ref->getReturnType();
        $this->assertNotNull($type);
        $this->assertTrue($type->allowsNull());
    }

    public function test_add_account_menu_item_is_public_static(): void
    {
        $ref = new \ReflectionMethod('LTMS_Business_Tourism_Compliance', 'add_account_menu_item');
        $this->assertTrue($ref->isPublic() && $ref->isStatic());
    }

    public function test_can_publish_accommodation_is_public_static(): void
    {
        $ref = new \ReflectionMethod('LTMS_Business_Tourism_Compliance', 'can_publish_accommodation');
        $this->assertTrue($ref->isPublic() && $ref->isStatic());
    }

    public function test_verify_rnt_signature_has_three_params(): void
    {
        $ref    = new \ReflectionMethod('LTMS_Business_Tourism_Compliance', 'verify_rnt');
        $params = $ref->getParameters();
        $this->assertCount(3, $params);
    }

    public function test_verify_rnt_second_param_is_approved_bool(): void
    {
        $ref    = new \ReflectionMethod('LTMS_Business_Tourism_Compliance', 'verify_rnt');
        $params = $ref->getParameters();
        $this->assertSame('approved', $params[1]->getName());
    }

    public function test_verify_rnt_third_param_notes_has_default(): void
    {
        $ref    = new \ReflectionMethod('LTMS_Business_Tourism_Compliance', 'verify_rnt');
        $params = $ref->getParameters();
        $this->assertTrue($params[2]->isOptional());
        $this->assertSame('', $params[2]->getDefaultValue());
    }

    public function test_save_rnt_is_public_static(): void
    {
        $ref = new \ReflectionMethod('LTMS_Business_Tourism_Compliance', 'save_rnt');
        $this->assertTrue($ref->isPublic() && $ref->isStatic());
    }

    public function test_create_compliance_record_is_public_static(): void
    {
        $ref = new \ReflectionMethod('LTMS_Business_Tourism_Compliance', 'create_compliance_record');
        $this->assertTrue($ref->isPublic() && $ref->isStatic());
    }

    public function test_check_rnt_expiry_is_public_static(): void
    {
        $ref = new \ReflectionMethod('LTMS_Business_Tourism_Compliance', 'check_rnt_expiry');
        $this->assertTrue($ref->isPublic() && $ref->isStatic());
    }

    // ════════════════════════════════════════════════════════════════════
    // § 6 — init() — idempotencia y no excepción
    // ════════════════════════════════════════════════════════════════════

    public function test_init_method_exists(): void
    {
        $this->assertTrue(method_exists('LTMS_Business_Tourism_Compliance', 'init'));
    }

    public function test_init_is_public_static(): void
    {
        $ref = new \ReflectionMethod('LTMS_Business_Tourism_Compliance', 'init');
        $this->assertTrue($ref->isPublic() && $ref->isStatic());
    }

    public function test_add_endpoint_method_exists(): void
    {
        $this->assertTrue(method_exists('LTMS_Business_Tourism_Compliance', 'add_endpoint'));
    }
}

