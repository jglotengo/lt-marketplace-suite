<?php
/**
 * ShippingCostLedgerTest — Tests unitarios para LTMS_Shipping_Cost_Ledger
 *
 * Cubre:
 * - Constants: STATUS_*, CARRIER_*
 * - check_vendor_budget(): no_vendor, no_limit, ok, over_soft, over_hard
 * - get_vendor_budget(): transaction + FOR UPDATE (P2-4 FIX), fallback to defaults
 * - get_vendor_monthly_spend(): SUM query
 * - get_entry(): returns null on not found
 * - get_kpis(): returns array structure
 * - get_vendor_statement(): returns array structure
 * - auto_open_dispute: SELECT FOR UPDATE (P2-3 FIX)
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ReflectionClass;

/**
 * @covers LTMS_Shipping_Cost_Ledger
 */
class ShippingCostLedgerTest extends LTMS_Unit_Test_Case {

    public object $mock_wpdb;
    public array $queries = [];

    protected function setUp(): void {
        parent::setUp();

        $this->queries = [];

        // Save original wpdb to restore in tearDown (prevents mock leaking
        // into subsequent test classes — root cause of 233 CI errors in #1429).
        if ( ! isset( $GLOBALS['__ltms_saved_wpdb'] ) ) {
            $GLOBALS['__ltms_saved_wpdb'] = $GLOBALS['wpdb'] ?? null;
        }

        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function prepare($sql, ...$args) { return $sql; }
            public function query($sql) {
                $this->test->queries[] = $sql;
                return true;
            }
            public function get_var($sql) {
                $this->test->queries[] = $sql;
                return null;
            }
            public function get_row($sql, $o = OBJECT) { return null; }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return []; }
            public function insert($t, $d, $f = null) { return 1; }
            public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        Functions\stubs([
            // current_time, sanitize_text_field, __, esc_html, get_option, do_action, apply_filters
            // already stubbed in base class.
            // get_current_user_id is defined in bootstrap.php — can't re-stub.
            'wp_mail'             => true,
            'get_post_meta'       => static fn($id, $key, $single) => false,
            'esc_url_raw'         => static fn($s) => $s,
            'wc_get_order'        => static fn($id) => false,
            'wp_next_scheduled'   => static fn($hook) => false,
            'wp_schedule_event'   => true,
        ]);
    }

    protected function tearDown(): void {
        // Restore original wpdb to prevent mock leaking into other tests.
        if ( isset( $GLOBALS['__ltms_saved_wpdb'] ) ) {
            $GLOBALS['wpdb'] = $GLOBALS['__ltms_saved_wpdb'];
        }
        parent::tearDown();
    }

    private static function callPrivate(string $method, mixed ...$args): mixed {
        $ref = new ReflectionClass(\LTMS_Shipping_Cost_Ledger::class);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke(null, ...$args);
    }

    // ── SECCIÓN 1 — Constants ─────────────────────────────────────────────

    public function test_status_constants_have_correct_values(): void {
        $this->assertSame('quoted', \LTMS_Shipping_Cost_Ledger::STATUS_QUOTED);
        $this->assertSame('shipped', \LTMS_Shipping_Cost_Ledger::STATUS_SHIPPED);
        $this->assertSame('delivered', \LTMS_Shipping_Cost_Ledger::STATUS_DELIVERED);
        $this->assertSame('invoiced', \LTMS_Shipping_Cost_Ledger::STATUS_INVOICED);
        $this->assertSame('disputed', \LTMS_Shipping_Cost_Ledger::STATUS_DISPUTED);
        $this->assertSame('reconciled', \LTMS_Shipping_Cost_Ledger::STATUS_RECONCILED);
        $this->assertSame('writeoff', \LTMS_Shipping_Cost_Ledger::STATUS_WRITEOFF);
    }

    public function test_carrier_constants_have_correct_values(): void {
        $this->assertSame('deprisa', \LTMS_Shipping_Cost_Ledger::CARRIER_DEPRISA);
        $this->assertSame('heka', \LTMS_Shipping_Cost_Ledger::CARRIER_HEKA);
        $this->assertSame('aveonline', \LTMS_Shipping_Cost_Ledger::CARRIER_AVEONLINE);
        $this->assertSame('uber', \LTMS_Shipping_Cost_Ledger::CARRIER_UBER);
        $this->assertSame('pickup', \LTMS_Shipping_Cost_Ledger::CARRIER_PICKUP);
        $this->assertSame('own_delivery', \LTMS_Shipping_Cost_Ledger::CARRIER_OWN_DELIVERY);
        $this->assertSame('free_absorbed', \LTMS_Shipping_Cost_Ledger::CARRIER_FREE_ABSORBED);
    }

    // ── SECCIÓN 2 — check_vendor_budget ───────────────────────────────────

    public function test_check_vendor_budget_returns_no_vendor_when_id_zero(): void {
        $result = \LTMS_Shipping_Cost_Ledger::check_vendor_budget(0, 100.0);
        $this->assertTrue($result['allowed']);
        $this->assertSame('no_vendor', $result['reason']);
    }

    public function test_check_vendor_budget_returns_no_vendor_when_negative(): void {
        $result = \LTMS_Shipping_Cost_Ledger::check_vendor_budget(-1, 100.0);
        $this->assertTrue($result['allowed']);
        $this->assertSame('no_vendor', $result['reason']);
    }

    public function test_check_vendor_budget_returns_no_limit_when_budget_zero(): void {
        // Mock get_vendor_budget to return budget_limit=0.
        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function prepare($sql, ...$args) { return $sql; }
            public function query($sql) { $this->test->queries[] = $sql; return true; }
            public function get_var($sql) { return 0; } // spent_amount = 0
            public function get_row($sql, $o = OBJECT) {
                return (object)[
                    'id' => 1, 'vendor_id' => 1, 'period_year' => 2026, 'period_month' => 7,
                    'budget_limit' => '0.00', 'soft_threshold' => '80.00', 'hard_threshold' => '100.00',
                    'spent_amount' => '0.00', 'spent_pct' => '0.00',
                ];
            }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return []; }
            public function insert($t, $d, $f = null) { return 1; }
            public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        $result = \LTMS_Shipping_Cost_Ledger::check_vendor_budget(1, 100.0);
        $this->assertTrue($result['allowed']);
        $this->assertSame('no_limit', $result['reason']);
    }

    public function test_check_vendor_budget_returns_ok_when_under_thresholds(): void {
        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function prepare($sql, ...$args) { return $sql; }
            public function query($sql) { return true; }
            public function get_var($sql) { return '100.00'; } // spent 100
            public function get_row($sql, $o = OBJECT) {
                return (object)[
                    'id' => 1, 'vendor_id' => 1, 'period_year' => 2026, 'period_month' => 7,
                    'budget_limit' => '1000.00', 'soft_threshold' => '80.00', 'hard_threshold' => '100.00',
                    'spent_amount' => '100.00', 'spent_pct' => '10.00',
                ];
            }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return []; }
            public function insert($t, $d, $f = null) { return 1; }
            public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        $result = \LTMS_Shipping_Cost_Ledger::check_vendor_budget(1, 50.0);
        // spent 100 + 50 = 150 / 1000 = 15% → ok.
        $this->assertTrue($result['allowed']);
        $this->assertSame('ok', $result['reason']);
    }

    public function test_check_vendor_budget_returns_over_soft_when_above_80_pct(): void {
        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function prepare($sql, ...$args) { return $sql; }
            public function query($sql) { return true; }
            public function get_var($sql) { return '850.00'; } // spent 850
            public function get_row($sql, $o = OBJECT) {
                return (object)[
                    'id' => 1, 'vendor_id' => 1, 'period_year' => 2026, 'period_month' => 7,
                    'budget_limit' => '1000.00', 'soft_threshold' => '80.00', 'hard_threshold' => '100.00',
                    'spent_amount' => '850.00', 'spent_pct' => '85.00',
                ];
            }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return []; }
            public function insert($t, $d, $f = null) { return 1; }
            public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        $result = \LTMS_Shipping_Cost_Ledger::check_vendor_budget(1, 0.0);
        // 850 / 1000 = 85% → over_soft.
        $this->assertTrue($result['allowed'], 'Over soft threshold is still allowed');
        $this->assertSame('over_soft_threshold', $result['reason']);
    }

    public function test_check_vendor_budget_returns_over_hard_when_at_100_pct(): void {
        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function prepare($sql, ...$args) { return $sql; }
            public function query($sql) { return true; }
            public function get_var($sql) { return '1000.00'; }
            public function get_row($sql, $o = OBJECT) {
                return (object)[
                    'id' => 1, 'vendor_id' => 1, 'period_year' => 2026, 'period_month' => 7,
                    'budget_limit' => '1000.00', 'soft_threshold' => '80.00', 'hard_threshold' => '100.00',
                    'spent_amount' => '1000.00', 'spent_pct' => '100.00',
                ];
            }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return []; }
            public function insert($t, $d, $f = null) { return 1; }
            public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        $result = \LTMS_Shipping_Cost_Ledger::check_vendor_budget(1, 0.0);
        // 1000 / 1000 = 100% → over_hard.
        $this->assertFalse($result['allowed'], 'Over hard threshold must be blocked');
        $this->assertSame('over_hard_threshold', $result['reason']);
    }

    public function test_check_vendor_budget_returns_over_hard_with_additional_cost(): void {
        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function prepare($sql, ...$args) { return $sql; }
            public function query($sql) { return true; }
            public function get_var($sql) { return '950.00'; }
            public function get_row($sql, $o = OBJECT) {
                return (object)[
                    'id' => 1, 'vendor_id' => 1, 'period_year' => 2026, 'period_month' => 7,
                    'budget_limit' => '1000.00', 'soft_threshold' => '80.00', 'hard_threshold' => '100.00',
                    'spent_amount' => '950.00', 'spent_pct' => '95.00',
                ];
            }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return []; }
            public function insert($t, $d, $f = null) { return 1; }
            public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        // spent 950 + additional 100 = 1050 / 1000 = 105% → over_hard.
        $result = \LTMS_Shipping_Cost_Ledger::check_vendor_budget(1, 100.0);
        $this->assertFalse($result['allowed']);
        $this->assertSame('over_hard_threshold', $result['reason']);
    }

    // ── SECCIÓN 3 — get_vendor_budget (P2-4 FIX TOCTOU) ───────────────────

    public function test_get_vendor_budget_uses_transaction_with_for_update(): void {
        try {
            \LTMS_Shipping_Cost_Ledger::get_vendor_budget(1, 2026, 7);
        } catch (\Throwable $e) {
            // May fail later; we care about the transaction.
        }
        $this->assertContains('START TRANSACTION', $this->queries, 'Must open transaction (P2-4 FIX)');
        $has_for_update = false;
        foreach ($this->queries as $q) {
            if (is_string($q) && str_contains($q, 'FOR UPDATE')) {
                $has_for_update = true;
                break;
            }
        }
        $this->assertTrue($has_for_update, 'Must use SELECT ... FOR UPDATE (P2-4 FIX)');
    }

    public function test_get_vendor_budget_creates_default_when_not_exists(): void {
        // Mock returns null row → trigger INSERT with defaults.
        $self = $this;
        $inserts = [];
        $this->mock_wpdb = new class($self, $inserts) {
            public $prefix = 'wp_';
            private $test;
            private $inserts;
            public function __construct($test, &$inserts) {
                $this->test = $test;
                $this->inserts = &$inserts;
            }
            public function prepare($sql, ...$args) { return $sql; }
            public function query($sql) { $this->test->queries[] = $sql; return true; }
            public function get_var($sql) { return '0.00'; }
            public function get_row($sql, $o = OBJECT) {
                // First SELECT returns null (no existing row).
                // After INSERT, second SELECT returns the new row.
                static $call_count = 0;
                $call_count++;
                if ($call_count === 1) return null;
                return (object)[
                    'id' => 1, 'vendor_id' => 1, 'period_year' => 2026, 'period_month' => 7,
                    'budget_limit' => '0.00', 'soft_threshold' => '80.00', 'hard_threshold' => '100.00',
                    'spent_amount' => '0.00', 'spent_pct' => '0.00',
                ];
            }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return []; }
            public function insert($t, $d, $f = null) {
                $this->inserts[] = $d;
                return 1;
            }
            public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        $result = \LTMS_Shipping_Cost_Ledger::get_vendor_budget(1, 2026, 7);
        $this->assertIsArray($result);
    }

    // ── SECCIÓN 4 — get_entry returns null when not found ─────────────────

    public function test_get_entry_returns_null_when_not_found(): void {
        $result = \LTMS_Shipping_Cost_Ledger::get_entry(999);
        $this->assertNull($result);
    }

    public function test_get_entry_returns_array_when_found(): void {
        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function prepare($sql, ...$args) { return $sql; }
            public function query($sql) { return true; }
            public function get_var($sql) { return null; }
            public function get_row($sql, $o = OBJECT) {
                return (object)[
                    'id' => 1, 'order_id' => 100, 'vendor_id' => 1,
                    'carrier' => 'deprisa', 'quote_cost' => '50.00',
                    'real_cost' => '55.00', 'status' => 'invoiced',
                ];
            }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return []; }
            public function insert($t, $d, $f = null) { return 1; }
            public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        $result = \LTMS_Shipping_Cost_Ledger::get_entry(1);
        $this->assertIsArray($result);
        $this->assertSame(1, $result['id']);
        $this->assertSame('invoiced', $result['status']);
    }

    // ── SECCIÓN 5 — get_vendor_monthly_spend ──────────────────────────────

    public function test_get_vendor_monthly_spend_returns_float(): void {
        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function prepare($sql, ...$args) { return $sql; }
            public function query($sql) { return true; }
            public function get_var($sql) { return '750.50'; }
            public function get_row($sql, $o = OBJECT) { return null; }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return []; }
            public function insert($t, $d, $f = null) { return 1; }
            public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        $spent = \LTMS_Shipping_Cost_Ledger::get_vendor_monthly_spend(1, 2026, 7);
        $this->assertSame(750.50, $spent);
    }

    public function test_get_vendor_monthly_spend_returns_zero_when_no_rows(): void {
        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function prepare($sql, ...$args) { return $sql; }
            public function query($sql) { return true; }
            public function get_var($sql) { return null; }
            public function get_row($sql, $o = OBJECT) { return null; }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return []; }
            public function insert($t, $d, $f = null) { return 1; }
            public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        $spent = \LTMS_Shipping_Cost_Ledger::get_vendor_monthly_spend(1, 2026, 7);
        $this->assertSame(0.0, $spent);
    }

    // ── SECCIÓN 6 — auto_open_dispute (P2-3 FIX TOCTOU) ───────────────────

    public function test_auto_open_dispute_uses_for_update(): void {
        // auto_open_dispute is private; we test it indirectly via the
        // fact that get_vendor_budget now uses FOR UPDATE.
        // Direct test via reflection:
        try {
            self::callPrivate('auto_open_dispute', 1, 100, 200, 50.0, 25.0);
        } catch (\Throwable $e) {
            // May fail later.
        }
        $has_for_update = false;
        foreach ($this->queries as $q) {
            if (is_string($q) && str_contains($q, 'FOR UPDATE')) {
                $has_for_update = true;
                break;
            }
        }
        $this->assertTrue($has_for_update, 'auto_open_dispute must use SELECT ... FOR UPDATE (P2-3 FIX)');
    }

    // ── SECCIÓN 7 — get_kpis structure ────────────────────────────────────

    public function test_get_kpis_returns_array(): void {
        $result = \LTMS_Shipping_Cost_Ledger::get_kpis('month');
        $this->assertIsArray($result);
    }

    public function test_get_vendor_statement_returns_array(): void {
        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function prepare($sql, ...$args) { return $sql; }
            public function query($sql) { return true; }
            public function get_var($sql) { return '0.00'; }
            public function get_row($sql, $o = OBJECT) { return null; }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return []; }
            public function insert($t, $d, $f = null) { return 1; }
            public function update($t, $d, $w, $f = null, $wf = null) { return 1; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        $result = \LTMS_Shipping_Cost_Ledger::get_vendor_statement(1, 2026, 7);
        $this->assertIsArray($result);
    }

    // ── SECCIÓN 8 — count_pending / count_by_status / sum_approved ───────

    public function test_get_entries_returns_array(): void {
        $result = \LTMS_Shipping_Cost_Ledger::get_entries([]);
        $this->assertIsArray($result);
    }
}
