<?php
/**
 * GdprEraserTest — Tests unitarios para LTMS_GDPR_Eraser
 *
 * Cubre:
 * - register_eraser(): agrega entry al array de erasers de WP
 * - erase_kyc_data(): usuario no existe → done=true, sin acciones
 * - erase_kyc_data(): legal_hold activo → items_retained=true, NO borra
 * - erase_kyc_data(): borrado completo → items_removed=true, marca ltms_gdpr_erased_at
 * - erase_kyc_data(): fallo parcial B2 → items_retained=true, NO marca erased_at
 * - GDPR-1 FIX: borra 17+ meta keys incluyendo zapsign contract
 * - GDPR-2 FIX: borra 'ltms_document_number' (sin prefix kyc_)
 * - GDPR-3 FIX: borra contrato firmado en B2 via bucket/key meta
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers LTMS_GDPR_Eraser
 */
class GdprEraserTest extends LTMS_Unit_Test_Case {

    private object $mock_wpdb;
    public array $deleted_meta_keys = [];
    public array $updated_meta = [];
    public array $b2_deletes = [];
    private ?object $mock_b2 = null;
    private array $b2_init_errors = [];

    protected function setUp(): void {
        parent::setUp();

        $this->deleted_meta_keys = [];
        $this->updated_meta = [];
        $this->b2_deletes = [];
        $this->b2_init_errors = [];
        $this->mock_b2 = null;

        // Save original wpdb to restore in tearDown (prevents mock leaking).
        if ( ! isset( $GLOBALS['__ltms_saved_wpdb'] ) ) {
            $GLOBALS['__ltms_saved_wpdb'] = $GLOBALS['wpdb'] ?? null;
        }

        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function prepare($sql, ...$args) { return $sql; }
            public function get_results($sql, $output = OBJECT) { return []; }
            public function delete($table, $where, $format = null) { return 1; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        // Mock get_user_meta — track reads.
        Functions\when('get_user_meta')->alias(function($user_id, $key, $single) use (&$self) {
            // Default: return false (no meta set).
            return false;
        });

        // Mock delete_user_meta — track deletes.
        Functions\when('delete_user_meta')->alias(function($user_id, $key, $value = '') use (&$self) {
            $self->deleted_meta_keys[] = $key;
            return true;
        });

        // Mock update_user_meta — track updates.
        Functions\when('update_user_meta')->alias(function($user_id, $key, $value, $prev = '') use (&$self) {
            $self->updated_meta[$key] = $value;
            return true;
        });

        Functions\stubs([
            // current_time, esc_html already stubbed in base class.
        ]);
    }

    private function make_user(string $email, int $id = 1): object {
        return new class($email, $id) {
            public $ID;
            public $user_email;
            public $user_login;
            public function __construct($email, $id) {
                $this->ID = $id;
                $this->user_email = $email;
                $this->user_login = $email;
            }
        };
    }

    private function set_user_meta(int $user_id, array $meta): void {
        Functions\when('get_user_meta')->alias(function($uid, $key, $single) use ($meta, $user_id) {
            if ((int)$uid === $user_id && array_key_exists($key, $meta)) {
                return $meta[$key];
            }
            return false;
        });
    }

    private function set_b2_files(array $rows): void {
        $this->mock_wpdb = new class($rows) {
            public $prefix = 'wp_';
            private $rows;
            public function __construct($rows) { $this->rows = $rows; }
            public function prepare($sql, ...$args) { return $sql; }
            public function get_results($sql, $output = OBJECT) { return $this->rows; }
            public function delete($table, $where, $format = null) { return 1; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;
    }

    private function set_b2_client(?object $b2): void {
        $this->mock_b2 = $b2;
        // Inject mock into LTMS_Api_Factory's static $instances cache via reflection.
        // This way, LTMS_Api_Factory::get('backblaze') returns our mock instead of
        // trying to instantiate a real LTMS_Api_Backblaze client (which requires
        // API keys and would throw).
        if (class_exists('LTMS_Api_Factory')) {
            $ref = new \ReflectionClass('LTMS_Api_Factory');
            $prop = $ref->getProperty('instances');
            $prop->setAccessible(true);
            if ($b2 === null) {
                $prop->setValue(null, []);
            } else {
                $prop->setValue(null, ['backblaze' => $b2]);
            }
        }
    }

    /**
     * Create a B2 mock that extends LTMS_Abstract_API_Client (required because
     * LTMS_Api_Factory::get() has return type LTMS_Abstract_API_Client —
     * PHP 8.1 enforces return types and would TypeError on a plain stdClass).
     */
    private function make_b2_mock(bool $fail_on_delete = false, ?string $fail_key = null): object {
        $self = $this;
        return new class($self, $fail_on_delete, $fail_key) extends \LTMS_Abstract_API_Client {
            private $test;
            private $fail_on_delete;
            private $fail_key;
            public function __construct($test, $fail_on_delete, $fail_key) {
                $this->test = $test;
                $this->fail_on_delete = $fail_on_delete;
                $this->fail_key = $fail_key;
                // Skip parent constructor to avoid config dependency.
            }
            public function health_check(): array {
                return ['status' => 'ok', 'message' => 'mock'];
            }
            public function get_provider_slug(): string {
                return 'backblaze';
            }
            public $calls = [];
            public function delete_file($bucket, $key) {
                $this->calls[] = ['bucket' => $bucket, 'key' => $key];
                if ($this->fail_on_delete && ($this->fail_key === null || $this->fail_key === $key)) {
                    throw new \RuntimeException('B2 delete failed (mock)');
                }
            }
        };
    }

    protected function tearDown(): void {
        // Clear LTMS_Api_Factory instances cache to prevent bleed between tests.
        if (class_exists('LTMS_Api_Factory')) {
            $ref = new \ReflectionClass('LTMS_Api_Factory');
            $prop = $ref->getProperty('instances');
            $prop->setAccessible(true);
            $prop->setValue(null, []);
        }
        if ( isset( $GLOBALS['__ltms_saved_wpdb'] ) ) {
            $GLOBALS['wpdb'] = $GLOBALS['__ltms_saved_wpdb'];
        }
        parent::tearDown();
    }

    // ── SECCIÓN 1 — register_eraser ───────────────────────────────────────

    public function test_register_eraser_adds_ltms_entry(): void {
        $result = \LTMS_GDPR_Eraser::register_eraser([]);
        $this->assertArrayHasKey('ltms-kyc-eraser', $result);
        $this->assertArrayHasKey('eraser_friendly_name', $result['ltms-kyc-eraser']);
        $this->assertArrayHasKey('callback', $result['ltms-kyc-eraser']);
        $this->assertIsArray($result['ltms-kyc-eraser']['callback']);
    }

    public function test_register_eraser_preserves_existing_erasers(): void {
        $existing = ['other' => ['eraser_friendly_name' => 'Other', 'callback' => 'foo']];
        $result = \LTMS_GDPR_Eraser::register_eraser($existing);
        $this->assertArrayHasKey('other', $result);
        $this->assertArrayHasKey('ltms-kyc-eraser', $result);
    }

    // ── SECCIÓN 2 — User not found ────────────────────────────────────────

    public function test_erase_returns_done_true_when_user_not_found(): void {
        Functions\when('get_user_by')->alias(fn($field, $value) => false);

        $result = \LTMS_GDPR_Eraser::erase_kyc_data('nobody@example.com');
        $this->assertFalse($result['items_removed']);
        $this->assertFalse($result['items_retained']);
        $this->assertTrue($result['done']);
        $this->assertSame([], $result['messages']);
    }

    // ── SECCIÓN 3 — Legal hold bypass ─────────────────────────────────────

    public function test_erase_skips_when_legal_hold_active(): void {
        $user = $this->make_user('hold@example.com', 5);
        Functions\when('get_user_by')->alias(fn($f, $v) => $user);
        $this->set_user_meta(5, ['ltms_legal_hold' => '1']);

        $result = \LTMS_GDPR_Eraser::erase_kyc_data('hold@example.com');
        $this->assertFalse($result['items_removed'], 'Must NOT remove anything under legal hold');
        $this->assertTrue($result['items_retained'], 'Must flag as retained under legal hold');
        $this->assertTrue($result['done']);
        $this->assertNotEmpty($result['messages']);
        // NO debe haber deletes.
        $this->assertSame([], $this->deleted_meta_keys);
        // NO debe marcar erased_at.
        $this->assertArrayNotHasKey('ltms_gdpr_erased_at', $this->updated_meta);
    }

    public function test_erase_proceeds_when_no_legal_hold(): void {
        $user = $this->make_user('ok@example.com', 6);
        Functions\when('get_user_by')->alias(fn($f, $v) => $user);
        $this->set_user_meta(6, ['ltms_legal_hold' => '']);
        // Sin archivos B2.
        $this->set_b2_files([]);

        $result = \LTMS_GDPR_Eraser::erase_kyc_data('ok@example.com');
        $this->assertTrue($result['done']);
        $this->assertFalse($result['items_retained']);
    }

    // ── SECCIÓN 4 — Full successful erase ─────────────────────────────────

    public function test_erase_marks_erased_at_when_no_retention(): void {
        $user = $this->make_user('full@example.com', 7);
        Functions\when('get_user_by')->alias(fn($f, $v) => $user);
        $this->set_user_meta(7, ['ltms_legal_hold' => '']);
        $this->set_b2_files([]);
        // Sin B2 contract backup meta.

        $result = \LTMS_GDPR_Eraser::erase_kyc_data('full@example.com');
        $this->assertArrayHasKey('ltms_gdpr_erased_at', $this->updated_meta);
        $this->assertArrayHasKey('ltms_retention_deleted_at', $this->updated_meta);
        $this->assertTrue($result['items_removed']);
        $this->assertFalse($result['items_retained']);
    }

    // ── SECCIÓN 5 — Partial B2 failure ────────────────────────────────────

    public function test_erase_does_not_mark_erased_at_on_partial_failure(): void {
        $user = $this->make_user('partial@example.com', 8);
        Functions\when('get_user_by')->alias(fn($f, $v) => $user);
        $this->set_user_meta(8, ['ltms_legal_hold' => '']);

        // Set up B2 file that throws on delete.
        $this->set_b2_files([
            (object)['id' => 1, 'file_key' => 'kyc/doc.pdf', 'bucket' => 'ltms-kyc'],
        ]);
        $failing_b2 = new class {
            public function delete_file($bucket, $key) {
                throw new \RuntimeException('B2 unavailable');
            }
        };
        $this->set_b2_client($failing_b2);

        $result = \LTMS_GDPR_Eraser::erase_kyc_data('partial@example.com');
        $this->assertTrue($result['items_retained'], 'Must flag as retained on B2 failure');
        $this->assertArrayNotHasKey('ltms_gdpr_erased_at', $this->updated_meta, 'Must NOT mark as erased');
        $this->assertNotEmpty($result['messages']);
    }

    // ── SECCIÓN 6 — GDPR-1: ZapSign contract meta keys ────────────────────

    public function test_erase_deletes_zapsign_contract_meta_keys(): void {
        $user = $this->make_user('zapsign@example.com', 9);
        Functions\when('get_user_by')->alias(fn($f, $v) => $user);

        $meta = ['ltms_legal_hold' => ''];
        foreach ([
            'ltms_contract_token', 'ltms_contract_status', 'ltms_contract_sent_at',
            'ltms_contract_signed_at', 'ltms_contract_sign_url',
            'ltms_contract_status_verified_at',
            '_ltms_zapsign_doc_token', '_ltms_zapsign_signed_at',
        ] as $k) {
            $meta[$k] = 'value';
        }
        $this->set_user_meta(9, $meta);
        $this->set_b2_files([]);

        \LTMS_GDPR_Eraser::erase_kyc_data('zapsign@example.com');

        foreach ([
            'ltms_contract_token', 'ltms_contract_status', 'ltms_contract_sent_at',
            'ltms_contract_signed_at', 'ltms_contract_sign_url',
            'ltms_contract_status_verified_at',
            '_ltms_zapsign_doc_token', '_ltms_zapsign_signed_at',
        ] as $expected_key) {
            $this->assertContains($expected_key, $this->deleted_meta_keys, "Must delete $expected_key (GDPR-1 FIX)");
        }
    }

    // ── SECCIÓN 7 — GDPR-2: ltms_document_number without prefix ───────────

    public function test_erase_deletes_document_number_without_kyc_prefix(): void {
        $user = $this->make_user('doc@example.com', 10);
        Functions\when('get_user_by')->alias(fn($f, $v) => $user);
        $this->set_user_meta(10, [
            'ltms_legal_hold' => '',
            'ltms_document_number' => '12345678',
        ]);
        $this->set_b2_files([]);

        \LTMS_GDPR_Eraser::erase_kyc_data('doc@example.com');
        $this->assertContains('ltms_document_number', $this->deleted_meta_keys, 'Must delete ltms_document_number (GDPR-2 FIX)');
    }

    // ── SECCIÓN 8 — GDPR-3: B2 contract backup ────────────────────────────

    public function test_erase_deletes_signed_contract_from_b2(): void {
        $user = $this->make_user('contract@example.com', 11);
        Functions\when('get_user_by')->alias(fn($f, $v) => $user);

        $this->set_user_meta(11, [
            'ltms_legal_hold' => '',
            'ltms_contract_b2_bucket' => 'ltms-contracts',
            'ltms_contract_b2_key' => 'signed/contract_11.pdf',
        ]);
        $this->set_b2_files([]);

        $b2 = $this->make_b2_mock();
        $this->set_b2_client($b2);

        $result = \LTMS_GDPR_Eraser::erase_kyc_data('contract@example.com');
        $this->assertNotEmpty($b2->calls, 'Must call B2 delete_file for signed contract');
        $this->assertSame('ltms-contracts', $b2->calls[0]['bucket']);
        $this->assertSame('signed/contract_11.pdf', $b2->calls[0]['key']);
        $this->assertTrue($result['items_removed']);
    }

    public function test_erase_skips_b2_contract_when_no_meta(): void {
        $user = $this->make_user('nocontract@example.com', 12);
        Functions\when('get_user_by')->alias(fn($f, $v) => $user);
        $this->set_user_meta(12, ['ltms_legal_hold' => '']);
        $this->set_b2_files([]);

        $b2 = $this->make_b2_mock();
        $this->set_b2_client($b2);

        \LTMS_GDPR_Eraser::erase_kyc_data('nocontract@example.com');
        $this->assertEmpty($b2->calls, 'Must NOT call B2 when no contract meta');
    }

    public function test_erase_flags_retained_on_b2_contract_failure(): void {
        $user = $this->make_user('fail@example.com', 13);
        Functions\when('get_user_by')->alias(fn($f, $v) => $user);
        $this->set_user_meta(13, [
            'ltms_legal_hold' => '',
            'ltms_contract_b2_bucket' => 'ltms-contracts',
            'ltms_contract_b2_key' => 'signed/fail.pdf',
        ]);
        $this->set_b2_files([]);

        $failing_b2 = $this->make_b2_mock(true);
        $this->set_b2_client($failing_b2);

        $result = \LTMS_GDPR_Eraser::erase_kyc_data('fail@example.com');
        $this->assertTrue($result['items_retained']);
        $this->assertArrayNotHasKey('ltms_gdpr_erased_at', $this->updated_meta);
    }

    // ── SECCIÓN 9 — B2 file deletion flow ─────────────────────────────────

    public function test_erase_deletes_b2_files_listed_in_media_table(): void {
        $user = $this->make_user('files@example.com', 14);
        Functions\when('get_user_by')->alias(fn($f, $v) => $user);
        $this->set_user_meta(14, ['ltms_legal_hold' => '']);

        $this->set_b2_files([
            (object)['id' => 101, 'file_key' => 'kyc/front.pdf', 'bucket' => 'ltms-kyc'],
            (object)['id' => 102, 'file_key' => 'kyc/back.pdf', 'bucket' => 'ltms-kyc'],
        ]);

        $b2 = $this->make_b2_mock();
        $this->set_b2_client($b2);

        $result = \LTMS_GDPR_Eraser::erase_kyc_data('files@example.com');
        $this->assertCount(2, $b2->calls);
        $this->assertTrue($result['items_removed']);
    }

    public function test_erase_continues_on_individual_file_failure(): void {
        $user = $this->make_user('mix@example.com', 15);
        Functions\when('get_user_by')->alias(fn($f, $v) => $user);
        $this->set_user_meta(15, ['ltms_legal_hold' => '']);

        $this->set_b2_files([
            (object)['id' => 201, 'file_key' => 'kyc/ok.pdf', 'bucket' => 'ltms-kyc'],
            (object)['id' => 202, 'file_key' => 'kyc/bad.pdf', 'bucket' => 'ltms-kyc'],
        ]);

        $b2 = $this->make_b2_mock(true, 'kyc/bad.pdf');
        $this->set_b2_client($b2);

        $result = \LTMS_GDPR_Eraser::erase_kyc_data('mix@example.com');
        $this->assertTrue($result['items_removed'], 'First file deleted successfully');
        $this->assertTrue($result['items_retained'], 'Second file failed → retained');
        $this->assertCount(2, $b2->calls, 'Must attempt both files');
    }
}
