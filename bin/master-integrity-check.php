#!/usr/bin/env php
<?php
/**
 * LTMS Master Integrity Check
 *
 * Verifica la integridad de los archivos críticos del plugin
 * comparando hashes SHA-256 actuales vs hashes de referencia.
 *
 * Uso: php bin/master-integrity-check.php [--generate] [--path=/path/to/plugin]
 *
 * Opciones:
 *   --generate  Genera un nuevo archivo de hashes de referencia.
 *               Ejecutar después de cada release de producción.
 *   --path=...  Ruta al directorio del plugin (por defecto: directorio padre).
 *
 * @package LTMS
 * @version 1.7.0
 */

declare(strict_types=1);

// ── Configuration ─────────────────────────────────────────────────
$plugin_dir    = dirname(__DIR__);
$hashes_file   = $plugin_dir . '/.integrity-hashes.json';
$generate_mode = false;

foreach ($argv as $arg) {
    if ($arg === '--generate') {
        $generate_mode = true;
    }
    if (str_starts_with($arg, '--path=')) {
        $plugin_dir = substr($arg, 7);
    }
}

// ── Critical files to verify ───────────────────────────────────────
const CRITICAL_FILES = [
    // Bootloader & kernel
    'lt-marketplace-suite.php',
    'uninstall.php',
    'includes/core/class-ltms-kernel.php',
    // Security infrastructure
    'includes/core/class-ltms-security.php',
    'includes/core/class-ltms-firewall.php',
    'includes/core/class-ltms-logger.php',
    'includes/core/class-ltms-data-masking.php',
    // Database
    'includes/core/migrations/class-ltms-db-migrations.php',
    'includes/core/services/class-ltms-activator.php',
    // Financial ledger
    'includes/business/class-ltms-wallet.php',
    'includes/business/class-ltms-tax-engine.php',
    'includes/business/class-ltms-payout-scheduler.php',
    // v1.7.0 — new high-value targets
    'includes/business/class-ltms-commission-strategy.php',
    'includes/business/class-ltms-payment-orchestrator.php',
    'includes/business/strategies/class-ltms-tax-strategy-colombia.php',
    'includes/business/strategies/class-ltms-tax-strategy-mexico.php',
    // API layer
    'includes/api/factories/class-ltms-api-factory.php',
    'includes/api/class-ltms-abstract-api-client.php',
    'includes/api/webhooks/class-ltms-stripe-webhook-handler.php',
    'includes/api/webhooks/class-ltms-uber-direct-webhook-handler.php',
    // Admin & roles
    'includes/admin/class-ltms-admin.php',
    'includes/admin/class-ltms-admin-payouts.php',
    'includes/roles/class-ltms-roles.php',
];

// ── Colors ────────────────────────────────────────────────────────
function c(string $color, string $text): string {
    $colors = [
        'red'    => "\033[0;31m",
        'green'  => "\033[0;32m",
        'yellow' => "\033[1;33m",
        'cyan'   => "\033[0;36m",
        'reset'  => "\033[0m",
    ];
    return ($colors[$color] ?? '') . $text . $colors['reset'];
}

// ── Generate mode ──────────────────────────────────────────────────
if ($generate_mode) {
    echo c('cyan', "\nLTMS Integrity: Generating reference hashes...\n\n");

    $hashes = [];
    $missing = [];

    foreach (CRITICAL_FILES as $file) {
        $full_path = $plugin_dir . '/' . $file;
        if (file_exists($full_path)) {
            $hashes[$file] = hash_file('sha256', $full_path);
            echo c('green', "  ✓ ") . $file . "\n";
        } else {
            $missing[] = $file;
            echo c('yellow', "  ⚠ MISSING: ") . $file . "\n";
        }
    }

    $manifest = [
        'generated_at' => date('c'),
        'php_version'  => PHP_VERSION,
        'plugin_version' => get_plugin_version($plugin_dir),
        'files'        => $hashes,
    ];

    file_put_contents($hashes_file, json_encode($manifest, JSON_PRETTY_PRINT));

    echo "\n" . c('green', "✅ Hash file written to: $hashes_file\n");
    if (!empty($missing)) {
        echo c('yellow', "⚠️  " . count($missing) . " files were missing during hash generation.\n");
    }
    echo "\n";
    exit(0);
}

// ── Verify mode ────────────────────────────────────────────────────
echo c('cyan', "\nLTMS Master Integrity Check\n");
echo str_repeat('─', 60) . "\n\n";

if (!file_exists($hashes_file)) {
    echo c('yellow', "⚠️  No reference hashes found.\n");
    echo "   Run with --generate to create reference hashes first.\n\n";
    exit(2);
}

$manifest = json_decode(file_get_contents($hashes_file), true);
if (!is_array($manifest) || empty($manifest['files'])) {
    echo c('red', "❌ Invalid or corrupt hash file.\n\n");
    exit(3);
}

echo "Reference generated: " . ($manifest['generated_at'] ?? 'unknown') . "\n";
echo "Plugin version:      " . ($manifest['plugin_version'] ?? 'unknown') . "\n";
echo "Files to verify:     " . count($manifest['files']) . "\n\n";

$passed   = 0;
$failed   = 0;
$missing  = 0;
$failures = [];

foreach ($manifest['files'] as $file => $expected_hash) {
    $full_path = $plugin_dir . '/' . $file;

    if (!file_exists($full_path)) {
        echo c('red', "  ✗ MISSING: ") . $file . "\n";
        $missing++;
        $failures[] = ['file' => $file, 'reason' => 'File not found'];
        continue;
    }

    $current_hash = hash_file('sha256', $full_path);

    if ($current_hash === $expected_hash) {
        echo c('green', "  ✓ OK: ") . $file . "\n";
        $passed++;
    } else {
        echo c('red', "  ✗ MODIFIED: ") . $file . "\n";
        echo "     Expected: " . substr($expected_hash, 0, 16) . "...\n";
        echo "     Current:  " . substr($current_hash, 0, 16) . "...\n";
        $failed++;
        $failures[] = [
            'file'     => $file,
            'reason'   => 'Hash mismatch',
            'expected' => $expected_hash,
            'current'  => $current_hash,
        ];
    }
}

// ── Summary ────────────────────────────────────────────────────────
echo "\n" . str_repeat('─', 60) . "\n";
echo "Results: ";
echo c('green', "$passed passed") . " | ";
echo c('red', "$failed modified") . " | ";
echo c('yellow', "$missing missing") . "\n\n";

$total_issues = $failed + $missing;

if ($total_issues === 0) {
    echo c('green', "✅ All integrity checks passed. Plugin files are unmodified.\n\n");
    exit(0);
} else {
    echo c('red', "❌ INTEGRITY CHECK FAILED: $total_issues issue(s) found.\n\n");

    if ($failed > 0) {
        echo c('yellow', "⚠️  Modified files may indicate unauthorized changes, hacking, or malware injection.\n");
        echo "   Recommended actions:\n";
        echo "   1. Compare modified files against the official release\n";
        echo "   2. Check server access logs for suspicious activity\n";
        echo "   3. Restore from a clean backup if tampering is confirmed\n";
        echo "   4. Run: php bin/master-integrity-check.php --generate after a clean restore\n\n";
    }

    // Write failure report
    $report_path = $plugin_dir . '/integrity-report-' . date('Y-m-d-His') . '.json';
    file_put_contents($report_path, json_encode([
        'checked_at' => date('c'),
        'total_issues' => $total_issues,
        'failures' => $failures,
    ], JSON_PRETTY_PRINT));

    echo "Report written to: $report_path\n\n";
    exit(1);
}

// ── Helper ────────────────────────────────────────────────────────
function get_plugin_version(string $plugin_dir): string {
    $main_file = $plugin_dir . '/lt-marketplace-suite.php';
    if (!file_exists($main_file)) return 'unknown';
    $content = file_get_contents($main_file, false, null, 0, 2000);
    preg_match('/\s*\*\s*Version:\s*(.+)/', $content, $matches);
    return trim($matches[1] ?? 'unknown');
}
