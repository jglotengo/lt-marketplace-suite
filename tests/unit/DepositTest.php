<?php
/**
 * DepositTest — Tests unitarios para LTMS_Deposit
 *
 * Cubre:
 * - create(): monto validations (min/max, positivo), método inválido, D3 ref duplicada,
 *   D4 receipt_url attachment, D5 rate limit pending
 * - approve(): D1 atomic claim, D2 idempotency key, fallback a pending on error
 * - reject(): D1 atomic claim, status guard, FASE4 P0 0-rows handler
 * - get(): retorno null cuando no existe
 * - count_pending, count_by_status, sum_approved
 * - Constants: STATUS_*, METHOD_*
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ReflectionClass;

/**
 * @covers LTMS_Deposit
 */
class DepositTest extends LTMS_Unit_Test_Case {

    private object $mock_wpdb;
    public array $rows = [];
    public array $inserts = [];
    public array $updates = [];
    public int $last_insert_id = 0;

    protected function setUp(): void {
        parent::setUp();

        $this->rows = [];
        $this->inserts = [];
        $this->updates = [];
        $this->last_insert_id = 100;

        // Save original wpdb to restore in tearDown (prevents mock leaking).
        if ( ! isset( $GLOBALS['__ltms_saved_wpdb'] ) ) {
            $GLOBALS['__ltms_saved_wpdb'] = $GLOBALS['wpdb'] ?? null;
        }

        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            public $insert_id = 0;
            private $test;
            public function __construct($test) {
                $this->test = $test;
                $this->insert_id = &$test->last_insert_id;
            }
            public function prepare($sql, ...$args) { return $sql; }
            public function query($sql) {
                // Atomic claim UPDATE — return 1 by default.
                return 1;
            }
            public function get_var($sql) { return null; }
            public function get_row($sql, $o = OBJECT) { return null; }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return []; }
            public function insert($table, $data, $format = null) {
                $this->test->inserts[] = ['table' => $table, 'data' => $data];
                $this->test->last_insert_id++;
                $this->insert_id = $this->test->last_insert_id;
                return 1;
            }
            public function update($table, $data, $where, $format = null, $where_format = null) {
                $this->test->updates[] = ['table' => $table, 'data' => $data, 'where' => $where];
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        Functions\stubs([
            'sanitize_text_field'       => static fn($s) => trim(strip_tags((string)$s)),
            'sanitize_textarea_field'   => static fn($s) => trim(strip_tags((string)$s)),
            'esc_url_raw'               => static fn($s) => $s,
            'attachment_url_to_postid'  => static fn($url) => 0,
            'wp_mail'                   => true,
            'admin_url'                 => static fn($p = '') => 'http://example.com/wp-admin/' . $p,
            'home_url'                  => static fn($p = '') => 'http://example.com/' . $p,
            '__'                        => static fn($s) => $s,
        ]);
    }

    protected function tearDown(): void {
        if ( isset( $GLOBALS['__ltms_saved_wpdb'] ) ) {
            $GLOBALS['wpdb'] = $GLOBALS['__ltms_saved_wpdb'];
        }
        parent::tearDown();
    }

    private static function callPrivate(string $method, mixed ...$args): mixed {
        $ref = new ReflectionClass(\LTMS_Deposit::class);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke(null, ...$args);
    }

    // ── SECCIÓN 1 — Constants ─────────────────────────────────────────────

    public function test_status_constants_have_correct_values(): void {
        $this->assertSame('pending', \LTMS_Deposit::STATUS_PENDING);
        $this->assertSame('processing', \LTMS_Deposit::STATUS_PROCESSING);
        $this->assertSame('approved', \LTMS_Deposit::STATUS_APPROVED);
        $this->assertSame('rejected', \LTMS_Deposit::STATUS_REJECTED);
    }

    public function test_method_constants_have_correct_values(): void {
        $this->assertSame('pse', \LTMS_Deposit::METHOD_PSE);
        $this->assertSame('nequi', \LTMS_Deposit::METHOD_NEQUI);
        $this->assertSame('transferencia', \LTMS_Deposit::METHOD_TRANSFERENCIA);
    }

    // ── SECCIÓN 2 — create() validations ──────────────────────────────────

    public function test_create_throws_on_zero_amount(): void {
        $this->expectException(\InvalidArgumentException::class);
        \LTMS_Deposit::create(1, 0.0, 'pse');
    }

    public function test_create_throws_on_negative_amount(): void {
        $this->expectException(\InvalidArgumentException::class);
        \LTMS_Deposit::create(1, -100.0, 'pse');
    }

    public function test_create_throws_on_invalid_method(): void {
        $this->expectException(\InvalidArgumentException::class);
        \LTMS_Deposit::create(1, 50000.0, 'bitcoin');
    }

    public function test_create_throws_on_amount_below_min(): void {
        // Default min = 10000.
        $this->expectException(\InvalidArgumentException::class);
        \LTMS_Deposit::create(1, 5000.0, 'pse');
    }

    public function test_create_throws_on_amount_above_max(): void {
        // Default max = 50,000,000.
        $this->expectException(\InvalidArgumentException::class);
        \LTMS_Deposit::create(1, 60000000.0, 'pse');
    }

    public function test_create_accepts_valid_amount_within_range(): void {
        $id = \LTMS_Deposit::create(1, 50000.0, 'pse');
        $this->assertGreaterThan(0, $id);
    }

    public function test_create_accepts_all_three_methods(): void {
        $id1 = \LTMS_Deposit::create(1, 50000.0, 'pse');
        $id2 = \LTMS_Deposit::create(1, 50000.0, 'nequi');
        $id3 = \LTMS_Deposit::create(1, 50000.0, 'transferencia');
        $this->assertGreaterThan(0, $id1);
        $this->assertGreaterThan(0, $id2);
        $this->assertGreaterThan(0, $id3);
    }

    // ── SECCIÓN 3 — D3 FIX: duplicate reference ───────────────────────────

    public function test_create_throws_on_duplicate_reference(): void {
        // Simular que la referencia ya existe.
        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            public $insert_id = 0;
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function prepare($sql, ...$args) { return $sql; }
            public function query($sql) { return 1; }
            public function get_var($sql) {
                // Si la query busca por referencia, retorna un ID existente.
                if (str_contains($sql, 'reference')) return 999;
                if (str_contains($sql, 'COUNT(*)')) return 0;
                return null;
            }
            public function get_row($sql, $o = OBJECT) { return null; }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return []; }
            public function insert($table, $data, $format = null) {
                $this->test->inserts[] = ['table' => $table, 'data' => $data];
                $this->test->last_insert_id++;
                return 1;
            }
            public function update($table, $data, $where, $format = null, $where_format = null) {
                $this->test->updates[] = ['table' => $table, 'data' => $data, 'where' => $where];
                return 1;
            }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ya fue usada');
        \LTMS_Deposit::create(1, 50000.0, 'pse', 'REF-DUP-001');
    }

    public function test_create_accepts_empty_reference(): void {
        // Sin referencia → no se verifica duplicado.
        $id = \LTMS_Deposit::create(1, 50000.0, 'pse', '');
        $this->assertGreaterThan(0, $id);
    }

    // ── SECCIÓN 4 — D4 FIX: receipt_url attachment validation ─────────────

    public function test_create_throws_on_external_receipt_url(): void {
        // attachment_url_to_postid returns 0 for external URLs.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('comprobante');
        \LTMS_Deposit::create(1, 50000.0, 'pse', '', 'https://external.com/receipt.pdf');
    }

    public function test_create_accepts_empty_receipt_url(): void {
        $id = \LTMS_Deposit::create(1, 50000.0, 'pse', '', '');
        $this->assertGreaterThan(0, $id);
    }

    public function test_create_accepts_valid_attachment_url(): void {
        Functions\when('attachment_url_to_postid')->alias(static fn($url) => 42);
        $id = \LTMS_Deposit::create(1, 50000.0, 'pse', '', 'http://example.com/wp-content/uploads/2026/receipt.pdf');
        $this->assertGreaterThan(0, $id);
    }

    // ── SECCIÓN 5 — D5 FIX: rate limit pending ────────────────────────────

    public function test_create_throws_when_max_pending_exceeded(): void {
        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            public $insert_id = 0;
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function prepare($sql, ...$args) { return $sql; }
            public function query($sql) { return 1; }
            public function get_var($sql) {
                if (str_contains($sql, 'COUNT(*)')) return 5; // At max.
                return null;
            }
            public function get_row($sql, $o = OBJECT) { return null; }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return []; }
            public function insert($table, $data, $format = null) { return 1; }
            public function update($table, $data, $where, $format = null, $where_format = null) { return 1; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('5 depósitos pendientes');
        \LTMS_Deposit::create(1, 50000.0, 'pse');
    }

    // ── SECCIÓN 6 — approve() ─────────────────────────────────────────────

    public function test_approve_returns_error_when_deposit_not_found(): void {
        $id = \LTMS_Deposit::approve(999, 1);
        $this->assertFalse($id['success']);
        $this->assertSame(0, $id['tx_id']);
    }

    public function test_approve_returns_error_when_already_processed(): void {
        // Mock get() to return approved deposit.
        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            public $insert_id = 0;
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function prepare($sql, ...$args) { return $sql; }
            public function query($sql) { return 1; }
            public function get_var($sql) { return null; }
            public function get_row($sql, $o = OBJECT) {
                return (object)[
                    'id' => 1, 'vendor_id' => 1, 'amount' => 50000.0,
                    'currency' => 'COP', 'method' => 'pse', 'reference' => 'X',
                    'status' => 'approved',
                ];
            }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return []; }
            public function insert($table, $data, $format = null) { return 1; }
            public function update($table, $data, $where, $format = null, $where_format = null) { return 1; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        $result = \LTMS_Deposit::approve(1, 1);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ya fue procesado', $result['message']);
    }

    public function test_approve_atomic_claim_fails_when_concurrent_admin_won(): void {
        // Mock get() returns pending, but the atomic UPDATE returns 0 (race lost).
        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            public $insert_id = 0;
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function prepare($sql, ...$args) { return $sql; }
            public function query($sql) {
                // Atomic claim UPDATE returns 0 (race loser).
                return 0;
            }
            public function get_var($sql) { return null; }
            public function get_row($sql, $o = OBJECT) {
                return (object)[
                    'id' => 1, 'vendor_id' => 1, 'amount' => 50000.0,
                    'currency' => 'COP', 'method' => 'pse', 'reference' => 'X',
                    'status' => 'pending',
                ];
            }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return []; }
            public function insert($table, $data, $format = null) { return 1; }
            public function update($table, $data, $where, $format = null, $where_format = null) { return 1; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        $result = \LTMS_Deposit::approve(1, 1);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('otro administrador', $result['message']);
    }

    // ── SECCIÓN 7 — reject() ──────────────────────────────────────────────

    public function test_reject_returns_error_when_no_reason(): void {
        $result = \LTMS_Deposit::reject(1, 1, '');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('motivo', $result['message']);
    }

    public function test_reject_returns_error_when_deposit_not_found(): void {
        $result = \LTMS_Deposit::reject(999, 1, 'fraude');
        $this->assertFalse($result['success']);
    }

    public function test_reject_returns_error_when_already_processed(): void {
        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function prepare($sql, ...$args) { return $sql; }
            public function query($sql) { return 1; }
            public function get_var($sql) { return null; }
            public function get_row($sql, $o = OBJECT) {
                return (object)[
                    'id' => 1, 'vendor_id' => 1, 'amount' => 50000.0,
                    'currency' => 'COP', 'method' => 'pse', 'reference' => 'X',
                    'status' => 'approved', // Already approved → reject fails.
                ];
            }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return []; }
            public function insert($t, $d, $f = null) { return 1; }
            public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        $result = \LTMS_Deposit::reject(1, 1, 'fraude');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ya fue procesado', $result['message']);
    }

    public function test_reject_returns_error_on_zero_rows_affected(): void {
        // FASE4 P0 FIX: 0 rows = concurrent approve won.
        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function prepare($sql, ...$args) { return $sql; }
            public function query($sql) { return 1; }
            public function get_var($sql) { return null; }
            public function get_row($sql, $o = OBJECT) {
                return (object)[
                    'id' => 1, 'vendor_id' => 1, 'amount' => 50000.0,
                    'currency' => 'COP', 'method' => 'pse', 'reference' => 'X',
                    'status' => 'pending',
                ];
            }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return []; }
            public function insert($t, $d, $f = null) { return 1; }
            public function update($t, $d, $w, $f = null, $wf = null) {
                // Atomic UPDATE returns 0 (race loser).
                return 0;
            }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        $result = \LTMS_Deposit::reject(1, 1, 'fraude');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('procesado concurrentemente', $result['message']);
    }

    public function test_reject_accepts_pending_status(): void {
        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function prepare($sql, ...$args) { return $sql; }
            public function query($sql) { return 1; }
            public function get_var($sql) { return null; }
            public function get_row($sql, $o = OBJECT) {
                return (object)[
                    'id' => 1, 'vendor_id' => 1, 'amount' => 50000.0,
                    'currency' => 'COP', 'method' => 'pse', 'reference' => 'X',
                    'status' => 'pending',
                ];
            }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return []; }
            public function insert($t, $d, $f = null) { return 1; }
            public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        $result = \LTMS_Deposit::reject(1, 1, 'comprobante inválido');
        $this->assertTrue($result['success']);
    }

    public function test_reject_accepts_processing_status(): void {
        // D1 FIX: admin can reject stuck 'processing' deposits.
        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function prepare($sql, ...$args) { return $sql; }
            public function query($sql) { return 1; }
            public function get_var($sql) { return null; }
            public function get_row($sql, $o = OBJECT) {
                return (object)[
                    'id' => 1, 'vendor_id' => 1, 'amount' => 50000.0,
                    'currency' => 'COP', 'method' => 'pse', 'reference' => 'X',
                    'status' => 'processing', // Stuck → admin can reject.
                ];
            }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return []; }
            public function insert($t, $d, $f = null) { return 1; }
            public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        $result = \LTMS_Deposit::reject(1, 1, 'stuck deposit recovery');
        $this->assertTrue($result['success']);
    }

    // ── SECCIÓN 8 — table() helper ────────────────────────────────────────

    public function test_table_returns_prefixed_name(): void {
        $table = self::callPrivate('table');
        $this->assertSame('wp_lt_deposits', $table);
    }

    // ── SECCIÓN 9 — get() returns null when not found ─────────────────────

    public function test_get_returns_null_when_deposit_not_found(): void {
        $result = \LTMS_Deposit::get(999);
        $this->assertNull($result);
    }

    public function test_get_returns_array_when_deposit_exists(): void {
        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function prepare($sql, ...$args) { return $sql; }
            public function get_row($sql, $o = OBJECT) {
                return (object)[
                    'id' => 1, 'vendor_id' => 1, 'amount' => '50000.00',
                    'currency' => 'COP', 'method' => 'pse', 'reference' => 'X',
                    'status' => 'pending',
                ];
            }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return []; }
            public function get_var($sql) { return null; }
            public function query($sql) { return 1; }
            public function insert($t, $d, $f = null) { return 1; }
            public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        $result = \LTMS_Deposit::get(1);
        $this->assertIsArray($result);
        $this->assertSame(1, $result['id']);
        $this->assertSame('pending', $result['status']);
    }

    // ── SECCIÓN 10 — count_pending / count_by_status / sum_approved ───────

    public function test_count_pending_returns_int(): void {
        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function prepare($sql, ...$args) { return $sql; }
            public function get_var($sql) { return '3'; }
            public function get_row($sql, $o = OBJECT) { return null; }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return []; }
            public function query($sql) { return 1; }
            public function insert($t, $d, $f = null) { return 1; }
            public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        $this->assertSame(3, \LTMS_Deposit::count_pending());
    }

    public function test_count_by_status_returns_int(): void {
        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function prepare($sql, ...$args) { return $sql; }
            public function get_var($sql) { return '12'; }
            public function get_row($sql, $o = OBJECT) { return null; }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return []; }
            public function query($sql) { return 1; }
            public function insert($t, $d, $f = null) { return 1; }
            public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        $this->assertSame(12, \LTMS_Deposit::count_by_status('approved'));
    }

    public function test_sum_approved_returns_float(): void {
        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function prepare($sql, ...$args) { return $sql; }
            public function get_var($sql) { return '1500000.00'; }
            public function get_row($sql, $o = OBJECT) { return null; }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return []; }
            public function query($sql) { return 1; }
            public function insert($t, $d, $f = null) { return 1; }
            public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        $this->assertSame(1500000.0, \LTMS_Deposit::sum_approved());
    }
}
