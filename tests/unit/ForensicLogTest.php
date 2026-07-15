<?php
/**
 * ForensicLogTest — Tests unitarios para LTMS_Forensic_Log
 *
 * Cubre:
 * - compute_entry_hash(): determinismo SHA-256, ksort de context, inmutabilidad
 * - get_client_ip(): proxy-aware (loopback, CIDR 10/8, 172.16/12), X-Forwarded-For
 * - log(): transacción + FOR UPDATE serialization, fallback graceful sin columnas
 * - verify_chain(): detección de 3 tipos de tampering
 *   (hash_mismatch, broken_chain_linkage, missing_earlier_entries)
 * - GENESIS_HASH: 64 ceros, primera entry linka a genesis
 * - Sanitización: user_agent/request_uri truncados a 500/2048 chars
 *
 * @package LTMS\Tests\Unit
 */

declare(strict_types=1);

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ReflectionClass;

/**
 * @covers LTMS_Forensic_Log
 */
class ForensicLogTest extends LTMS_Unit_Test_Case {

    private object $mock_wpdb;
    public array $inserted_rows = [];
    public array $queries = [];

    protected function setUp(): void {
        parent::setUp();

        $this->inserted_rows = [];
        $this->queries = [];

        // Save original wpdb to restore in tearDown (prevents mock leaking).
        if ( ! isset( $GLOBALS['__ltms_saved_wpdb'] ) ) {
            $GLOBALS['__ltms_saved_wpdb'] = $GLOBALS['wpdb'] ?? null;
        }

        // Mock wpdb — captura INSERTs para inspección.
        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            public $last_result = [];
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function query($sql) {
                $this->test->queries[] = $sql;
                return true;
            }
            public function get_var($sql) {
                $this->test->queries[] = $sql;
                // Simular cadena vacía — no hay entries previos (chain tip = GENESIS).
                return null;
            }
            public function get_results($sql, $output = OBJECT) {
                $this->test->queries[] = $sql;
                return [];
            }
            public function get_col($sql) {
                $this->test->queries[] = $sql;
                // Retornar todas las columnas — simula que la migración corrió.
                return ['id', 'action', 'user_id', 'ip', 'created_at', 'user_agent', 'request_uri', 'context', 'entry_hash'];
            }
            public function insert($table, $data, $format = null) {
                $this->test->inserted_rows[] = ['table' => $table, 'data' => $data];
                return 1;
            }
            public function prepare($sql, ...$args) {
                return $sql;
            }
        };

        $GLOBALS['wpdb'] = $this->mock_wpdb;

        // Stubs adicionales específicos.
        Functions\stubs([
            // sanitize_text_field, current_time, wp_json_encode already stubbed in base class.
        ]);

        // Reset $_SERVER
        $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
        $_SERVER['HTTP_USER_AGENT'] = 'PHPUnit/Test';
        $_SERVER['REQUEST_URI'] = '/test';
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    protected function tearDown(): void {
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        if ( isset( $GLOBALS['__ltms_saved_wpdb'] ) ) {
            $GLOBALS['wpdb'] = $GLOBALS['__ltms_saved_wpdb'];
        }
        parent::tearDown();
    }

    // ── Helpers de reflexión ──────────────────────────────────────────────

    private static function callPrivate(string $method, mixed ...$args): mixed {
        $ref = new ReflectionClass(\LTMS_Forensic_Log::class);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke(null, ...$args);
    }

    private static function getConstant(string $name): mixed {
        $ref = new ReflectionClass(\LTMS_Forensic_Log::class);
        return $ref->getConstant($name);
    }

    // ── SECCIÓN 1 — GENESIS_HASH ──────────────────────────────────────────

    public function test_genesis_hash_is_64_zeros(): void {
        $genesis = self::getConstant('GENESIS_HASH');
        $this->assertSame(str_repeat('0', 64), $genesis);
        $this->assertSame(64, strlen($genesis));
    }

    // ── SECCIÓN 2 — compute_entry_hash determinismo ───────────────────────

    public function test_compute_entry_hash_is_deterministic(): void {
        $ctx = ['event' => 'login', 'severity' => 'info'];
        $h1 = self::callPrivate('compute_entry_hash',
            'prev123', 'USER_LOGIN', 42, '192.0.2.1', '2026-07-15 10:00:00', 'UA', '/wp-login.php', $ctx);
        $h2 = self::callPrivate('compute_entry_hash',
            'prev123', 'USER_LOGIN', 42, '192.0.2.1', '2026-07-15 10:00:00', 'UA', '/wp-login.php', $ctx);
        $this->assertSame($h1, $h2, 'Same inputs must produce same hash');
    }

    public function test_compute_entry_hash_is_64_hex_chars(): void {
        $h = self::callPrivate('compute_entry_hash',
            'prev', 'ACT', 1, '127.0.0.1', '2026-01-01', 'UA', '/', []);
        $this->assertSame(64, strlen($h));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $h);
    }

    public function test_compute_entry_hash_ksort_context_for_determinism(): void {
        // Dos contextos con mismas keys pero distinto orden deben producir mismo hash.
        $ctx_a = ['z' => 1, 'a' => 2, 'm' => 3];
        $ctx_b = ['a' => 2, 'm' => 3, 'z' => 1];
        $args = ['prev', 'ACT', 1, '127.0.0.1', '2026', 'UA', '/'];
        // PHP 8.0+: spread must be the last argument, so merge context into args.
        $h_a = self::callPrivate('compute_entry_hash', ...array_merge($args, [$ctx_a]));
        $h_b = self::callPrivate('compute_entry_hash', ...array_merge($args, [$ctx_b]));
        $this->assertSame($h_a, $h_b, 'ksort must make context key order irrelevant');
    }

    public function test_compute_entry_hash_changes_on_any_field_change(): void {
        $base = ['prev', 'ACT', 1, '127.0.0.1', '2026', 'UA', '/', ['k' => 'v']];
        $h_base = self::callPrivate('compute_entry_hash', ...$base);

        // Cada campo modificado debe producir hash distinto.
        $variants = [
            'prev_hash' => ['prevX', 'ACT', 1, '127.0.0.1', '2026', 'UA', '/', ['k' => 'v']],
            'action'    => ['prev', 'ACT2', 1, '127.0.0.1', '2026', 'UA', '/', ['k' => 'v']],
            'user_id'   => ['prev', 'ACT', 2, '127.0.0.1', '2026', 'UA', '/', ['k' => 'v']],
            'ip'        => ['prev', 'ACT', 1, '127.0.0.2', '2026', 'UA', '/', ['k' => 'v']],
            'time'      => ['prev', 'ACT', 1, '127.0.0.1', '2027', 'UA', '/', ['k' => 'v']],
            'ua'        => ['prev', 'ACT', 1, '127.0.0.1', '2026', 'UA2', '/', ['k' => 'v']],
            'uri'       => ['prev', 'ACT', 1, '127.0.0.1', '2026', 'UA', '/x', ['k' => 'v']],
            'ctx'       => ['prev', 'ACT', 1, '127.0.0.1', '2026', 'UA', '/', ['k' => 'w']],
        ];
        foreach ($variants as $field => $args) {
            $h = self::callPrivate('compute_entry_hash', ...$args);
            $this->assertNotSame($h_base, $h, "Changing $field must change hash");
        }
    }

    // ── SECCIÓN 3 — get_client_ip proxy-aware ─────────────────────────────

    public function test_get_client_ip_returns_remote_addr_when_no_proxy(): void {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5';
        $ip = self::callPrivate('get_client_ip');
        $this->assertSame('203.0.113.5', $ip);
    }

    public function test_get_client_ip_uses_xff_when_loopback_proxy(): void {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7, 10.0.0.1';
        $ip = self::callPrivate('get_client_ip');
        $this->assertSame('198.51.100.7', $ip, 'Must take first hop of X-Forwarded-For');
    }

    public function test_get_client_ip_uses_xff_when_cidr_10_proxy(): void {
        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.10';
        $ip = self::callPrivate('get_client_ip');
        $this->assertSame('198.51.100.10', $ip);
    }

    public function test_get_client_ip_uses_xff_when_cidr_172_16_proxy(): void {
        $_SERVER['REMOTE_ADDR'] = '172.16.0.5';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20';
        $ip = self::callPrivate('get_client_ip');
        $this->assertSame('198.51.100.20', $ip);
    }

    public function test_get_client_ip_ignores_invalid_xff(): void {
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';
        $ip = self::callPrivate('get_client_ip');
        $this->assertSame('127.0.0.1', $ip, 'Invalid XFF must fall back to REMOTE_ADDR');
    }

    public function test_get_client_ip_ignores_xff_when_remote_is_not_trusted(): void {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.5'; // No es trusted proxy
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.30';
        $ip = self::callPrivate('get_client_ip');
        $this->assertSame('203.0.113.5', $ip, 'XFF must be ignored if REMOTE_ADDR is not a trusted proxy');
    }

    public function test_get_client_ip_returns_zero_when_no_server_vars(): void {
        unset($_SERVER['REMOTE_ADDR']);
        $ip = self::callPrivate('get_client_ip');
        $this->assertSame('0.0.0.0', $ip);
    }

    // ── SECCIÓN 4 — log() transacción + insert ────────────────────────────

    public function test_log_opens_transaction_with_for_update(): void {
        \LTMS_Forensic_Log::log('TEST_ACTION', 99, '', ['foo' => 'bar']);

        // Debe haber START TRANSACTION.
        $this->assertContains('START TRANSACTION', $this->queries, 'log() must open a transaction');
        // Debe haber COMMIT (no ROLLBACK, porque el mock de insert devuelve 1).
        $this->assertContains('COMMIT', $this->queries, 'log() must commit on success');
        // Debe haber SELECT ... FOR UPDATE (H-6 FIX serialization).
        $has_for_update = false;
        foreach ($this->queries as $q) {
            if (is_string($q) && str_contains($q, 'FOR UPDATE')) {
                $has_for_update = true;
                break;
            }
        }
        $this->assertTrue($has_for_update, 'log() must use SELECT ... FOR UPDATE on the chain tip');
    }

    public function test_log_rolls_back_on_insert_failure(): void {
        // Override wpdb para que insert() falle.
        $self = $this;
        $this->mock_wpdb = new class($self) {
            public $prefix = 'wp_';
            private $test;
            public function __construct($test) { $this->test = $test; }
            public function query($sql) { $this->test->queries[] = $sql; return true; }
            public function get_var($sql) { $this->test->queries[] = $sql; return null; }
            public function get_results($sql, $o = OBJECT) { return []; }
            public function get_col($sql) { return ['id','action','user_id','ip','created_at','user_agent','request_uri','context','entry_hash']; }
            public function insert($t, $d, $f = null) { return false; }
            public function prepare($sql, ...$a) { return $sql; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;

        \LTMS_Forensic_Log::log('FAIL_TEST', 1);

        $this->assertContains('START TRANSACTION', $this->queries);
        $this->assertContains('ROLLBACK', $this->queries, 'Must rollback on insert failure');
        $this->assertNotContains('COMMIT', $this->queries, 'Must NOT commit on failure');
    }

    public function test_log_inserts_with_correct_action_and_user_id(): void {
        \LTMS_Forensic_Log::log('PAYOUT_APPROVE', 777, '203.0.113.99');
        $this->assertCount(1, $this->inserted_rows);
        $data = $this->inserted_rows[0]['data'];
        $this->assertSame('PAYOUT_APPROVE', $data['action']);
        $this->assertSame(777, $data['user_id']);
        $this->assertSame('203.0.113.99', $data['ip']);
    }

    public function test_log_uses_explicit_ip_when_provided(): void {
        \LTMS_Forensic_Log::log('ACT', 1, '198.51.100.42');
        $data = $this->inserted_rows[0]['data'];
        $this->assertSame('198.51.100.42', $data['ip']);
    }

    public function test_log_falls_back_to_client_ip_when_empty(): void {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.50';
        \LTMS_Forensic_Log::log('ACT', 1, '');
        $data = $this->inserted_rows[0]['data'];
        $this->assertSame('203.0.113.50', $data['ip']);
    }

    public function test_log_stores_entry_hash_in_dedicated_column_when_present(): void {
        \LTMS_Forensic_Log::log('ACT', 1);
        $data = $this->inserted_rows[0]['data'];
        $this->assertArrayHasKey('entry_hash', $data);
        $this->assertSame(64, strlen($data['entry_hash']));
    }

    public function test_log_embeds_chain_hashes_in_context_json(): void {
        \LTMS_Forensic_Log::log('ACT', 1, '', ['event' => 'test']);
        $data = $this->inserted_rows[0]['data'];
        $this->assertArrayHasKey('context', $data);
        $decoded = json_decode($data['context'], true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('__prev_hash', $decoded);
        $this->assertArrayHasKey('__entry_hash', $decoded);
        $this->assertArrayHasKey('event', $decoded, 'Caller context must be preserved');
        $this->assertSame('test', $decoded['event']);
    }

    public function test_log_first_entry_links_to_genesis(): void {
        // Mock ya retorna null en get_var → simula chain vacío → prev = GENESIS.
        \LTMS_Forensic_Log::log('FIRST_ENTRY', 1);
        $data = $this->inserted_rows[0]['data'];
        $decoded = json_decode($data['context'], true);
        $this->assertSame(str_repeat('0', 64), $decoded['__prev_hash']);
    }

    public function test_log_truncates_user_agent_to_500_chars(): void {
        $_SERVER['HTTP_USER_AGENT'] = str_repeat('X', 800);
        \LTMS_Forensic_Log::log('ACT', 1);
        $data = $this->inserted_rows[0]['data'];
        $this->assertSame(500, strlen($data['user_agent']));
    }

    public function test_log_truncates_request_uri_to_2048_chars(): void {
        $_SERVER['REQUEST_URI'] = '/' . str_repeat('a', 3000);
        \LTMS_Forensic_Log::log('ACT', 1);
        $data = $this->inserted_rows[0]['data'];
        $this->assertLessThanOrEqual(2048, strlen($data['request_uri']));
    }

    // ── SECCIÓN 5 — verify_chain detección de tampering ───────────────────

    public function test_verify_chain_returns_empty_when_no_rows(): void {
        $result = \LTMS_Forensic_Log::verify_chain(100);
        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['broken']);
        $this->assertNull($result['first_id']);
    }

    public function test_verify_chain_detects_broken_chain_linkage(): void {
        // Simular 2 entries: la segunda no linkea al hash de la primera.
        $entry1_hash = self::callPrivate('compute_entry_hash',
            str_repeat('0', 64), 'ACT1', 1, '1.1.1.1', '2026-01-01', 'UA', '/', []);
        $entry1_ctx = ['__prev_hash' => str_repeat('0', 64), '__entry_hash' => $entry1_hash];

        // entry2 prev_hash NO es entry1_hash (broken linkage).
        $entry2_hash = self::callPrivate('compute_entry_hash',
            'deadbeef', 'ACT2', 2, '2.2.2.2', '2026-01-02', 'UA', '/', []);
        $entry2_ctx = ['__prev_hash' => 'deadbeef', '__entry_hash' => $entry2_hash];

        $rows = [
            ['id' => 1, 'action' => 'ACT1', 'user_id' => 1, 'ip' => '1.1.1.1',
             'created_at' => '2026-01-01', 'user_agent' => 'UA', 'request_uri' => '/',
             'context' => json_encode($entry1_ctx), 'entry_hash' => $entry1_hash],
            ['id' => 2, 'action' => 'ACT2', 'user_id' => 2, 'ip' => '2.2.2.2',
             'created_at' => '2026-01-02', 'user_agent' => 'UA', 'request_uri' => '/',
             'context' => json_encode($entry2_ctx), 'entry_hash' => $entry2_hash],
        ];

        $this->mock_results($rows);
        $result = \LTMS_Forensic_Log::verify_chain(100);
        $this->assertSame(2, $result['total']);
        $issues = array_column($result['broken'], 'issue');
        $this->assertContains('broken_chain_linkage', $issues, 'Must detect entry2 prev_hash mismatch');
    }

    public function test_verify_chain_detects_hash_mismatch_on_field_modification(): void {
        // Una entry con action modificado después del insert → hash re-computado no coincide.
        $original_hash = self::callPrivate('compute_entry_hash',
            str_repeat('0', 64), 'ORIGINAL', 1, '1.1.1.1', '2026-01-01', 'UA', '/', []);
        $ctx = ['__prev_hash' => str_repeat('0', 64), '__entry_hash' => $original_hash];

        $rows = [
            ['id' => 1, 'action' => 'TAMPERED', 'user_id' => 1, 'ip' => '1.1.1.1',
             'created_at' => '2026-01-01', 'user_agent' => 'UA', 'request_uri' => '/',
             'context' => json_encode($ctx), 'entry_hash' => $original_hash],
        ];
        $this->mock_results($rows);
        $result = \LTMS_Forensic_Log::verify_chain(100);
        $issues = array_column($result['broken'], 'issue');
        $this->assertContains('hash_mismatch', $issues, 'Must detect field tampering via hash recomputation');
    }

    public function test_verify_chain_detects_missing_earlier_entries(): void {
        // Primer row visible NO linkea a GENESIS → se borraron entries anteriores.
        $some_hash = self::callPrivate('compute_entry_hash',
            str_repeat('0', 64), 'X', 1, '1.1.1.1', '2026-01-01', 'UA', '/', []);
        $ctx = ['__prev_hash' => 'not_genesis_hash_here_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', '__entry_hash' => $some_hash];

        $rows = [
            ['id' => 5, 'action' => 'X', 'user_id' => 1, 'ip' => '1.1.1.1',
             'created_at' => '2026-01-01', 'user_agent' => 'UA', 'request_uri' => '/',
             'context' => json_encode($ctx), 'entry_hash' => $some_hash],
        ];
        $this->mock_results($rows);
        $result = \LTMS_Forensic_Log::verify_chain(100);
        $issues = array_column($result['broken'], 'issue');
        $this->assertContains('missing_earlier_entries', $issues);
    }

    public function test_verify_chain_passes_clean_chain(): void {
        // Cadena perfecta de 2 entries, todo consistente.
        $e1_hash = self::callPrivate('compute_entry_hash',
            str_repeat('0', 64), 'A', 1, '1.1.1.1', '2026-01-01', 'UA', '/', []);
        $e2_hash = self::callPrivate('compute_entry_hash',
            $e1_hash, 'B', 2, '2.2.2.2', '2026-01-02', 'UA', '/', []);

        $rows = [
            ['id' => 1, 'action' => 'A', 'user_id' => 1, 'ip' => '1.1.1.1',
             'created_at' => '2026-01-01', 'user_agent' => 'UA', 'request_uri' => '/',
             'context' => json_encode(['__prev_hash' => str_repeat('0', 64), '__entry_hash' => $e1_hash]),
             'entry_hash' => $e1_hash],
            ['id' => 2, 'action' => 'B', 'user_id' => 2, 'ip' => '2.2.2.2',
             'created_at' => '2026-01-02', 'user_agent' => 'UA', 'request_uri' => '/',
             'context' => json_encode(['__prev_hash' => $e1_hash, '__entry_hash' => $e2_hash]),
             'entry_hash' => $e2_hash],
        ];
        $this->mock_results($rows);
        $result = \LTMS_Forensic_Log::verify_chain(100);
        $this->assertSame(2, $result['total']);
        $this->assertSame([], $result['broken'], 'Clean chain must yield no broken entries');
        $this->assertSame(1, $result['first_id']);
    }

    // ── SECCIÓN 6 — get_recent ────────────────────────────────────────────

    public function test_get_recent_returns_rows_from_db(): void {
        $rows = [['id' => 1, 'action' => 'X'], ['id' => 2, 'action' => 'Y']];
        $this->mock_results($rows);
        $result = \LTMS_Forensic_Log::get_recent(2);
        $this->assertCount(2, $result);
    }

    public function test_get_recent_returns_empty_array_on_no_rows(): void {
        $this->mock_results([]);
        $result = \LTMS_Forensic_Log::get_recent(10);
        $this->assertSame([], $result);
    }

    // ── Helper: mock wpdb->get_results ────────────────────────────────────

    private function mock_results(array $rows): void {
        $self = $this;
        $this->mock_wpdb = new class($rows, $self) {
            public $prefix = 'wp_';
            private $rows;
            private $test;
            public function __construct($rows, $test) { $this->rows = $rows; $this->test = $test; }
            public function query($sql) { return true; }
            public function get_var($sql) { return null; }
            public function get_results($sql, $output = OBJECT) { return $this->rows; }
            public function get_col($sql) { return ['id','action','user_id','ip','created_at','user_agent','request_uri','context','entry_hash']; }
            public function insert($t, $d, $f = null) { return 1; }
            public function prepare($sql, ...$a) { return $sql; }
        };
        $GLOBALS['wpdb'] = $this->mock_wpdb;
    }
}
