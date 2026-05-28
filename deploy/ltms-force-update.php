<?php
/**
 * LTMS Force Update — Emergency file patcher
 * Coloca en: /home/customer/www/lo-tengo.com.co/public_html/ltms-force-update.php
 * ELIMINAR después de usar.
 */

define( 'SECRET', 'ltms_patch_2026_now' );
$token = $_GET['t'] ?? '';
if ( ! hash_equals( SECRET, $token ) ) { http_response_code(403); exit('Forbidden'); }

header('Content-Type: text/plain; charset=utf-8');
$base = __DIR__ . '/wp-content/plugins/lt-marketplace-suite';
echo "Base: $base\n";
echo "Exists: " . (is_dir($base) ? 'YES' : 'NO') . "\n\n";

// ── Patch 1: assets/css/ltms-auditor.css ─────────────────────────────────────
$css_path = $base . '/assets/css/ltms-auditor.css';
$css = <<<'ENDCSS'
/* LTMS Auditor Panel v2.3.0 */
.ltms-auditor-panel{max-width:1400px;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
.ltms-auditor-panel *,.ltms-auditor-panel *::before,.ltms-auditor-panel *::after{box-sizing:border-box}
.ltms-page-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;background:#0d1b2a;color:#fff;border-radius:8px;padding:18px 24px;margin-bottom:24px}
.ltms-page-header h1{margin:0;font-size:1.35rem;font-weight:700;color:#fff!important}
.ltms-page-header .ltms-header-meta{font-size:12.5px;color:#94a3b8;line-height:1.5}
.ltms-page-header .ltms-header-meta strong{color:#02c39a}
.ltms-readonly-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.18);border-radius:20px;padding:5px 14px;font-size:12px;color:#cbd5e1;white-space:nowrap}
.ltms-readonly-badge::before{content:"🔒";font-size:13px}
.ltms-filter-bar{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:16px 20px;margin-bottom:24px;box-shadow:0 1px 3px rgba(0,0,0,.06);display:flex;flex-wrap:wrap;align-items:flex-end;gap:16px}
.ltms-filter-group{display:flex;flex-direction:column;gap:4px;flex:1 1 160px;min-width:140px}
.ltms-filter-group label{font-size:11.5px;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.4px}
.ltms-filter-group input[type="date"],.ltms-filter-group select{height:36px;padding:0 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13.5px;color:#1e293b;background:#f8fafc;width:100%;transition:border-color .2s}
.ltms-filter-group input[type="date"]:focus,.ltms-filter-group select:focus{border-color:#028090;outline:none;background:#fff;box-shadow:0 0 0 3px rgba(2,128,144,.12)}
.ltms-filter-bar .button{height:36px;padding:0 20px;border-radius:6px!important;font-size:13.5px!important;font-weight:600!important;background:#028090!important;border-color:#028090!important;color:#fff!important;flex-shrink:0;align-self:flex-end}
.ltms-filter-bar .button:hover{background:#02c39a!important;border-color:#02c39a!important}
.ltms-kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:14px;margin-bottom:28px}
.ltms-kpi-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;box-shadow:0 1px 3px rgba(0,0,0,.06);border-top:3px solid #028090}
.ltms-kpi-card.accent-mx{border-top-color:#006847}
.ltms-kpi-card.accent-co{border-top-color:#003087}
.ltms-kpi-card.accent-warn{border-top-color:#f59e0b}
.ltms-kpi-card.accent-mint{border-top-color:#02c39a}
.ltms-kpi-card.accent-red{border-top-color:#e11d48}
.ltms-kpi-label{font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.4px;margin-bottom:6px}
.ltms-kpi-value{font-size:1.45rem;font-weight:700;color:#0d1b2a;line-height:1.1;font-family:"Courier New",monospace}
.ltms-kpi-sub{font-size:11px;color:#94a3b8;margin-top:3px}
.ltms-section-header{display:flex;align-items:center;gap:10px;margin:28px 0 12px}
.ltms-section-header h2{margin:0;padding:0;border:none;font-size:1rem;font-weight:700;color:#0d1b2a}
.ltms-section-header .ltms-section-icon{font-size:1.2rem}
.ltms-section-header .ltms-section-desc{font-size:12px;color:#64748b;margin-left:auto;font-style:italic}
.ltms-section-divider{height:2px;background:linear-gradient(90deg,#028090 0%,#e2e8f0 60%);border:none;margin:0 0 16px}
.ltms-table-wrap{overflow-x:auto;margin-bottom:28px;border-radius:8px;border:1px solid #e2e8f0}
.ltms-auditor-panel .widefat{border:none;border-collapse:collapse;width:100%;font-size:13px;margin:0!important}
.ltms-auditor-panel .widefat thead th{background:#f1f5f9;font-weight:600;color:#334155;padding:10px 14px;border-bottom:2px solid #e2e8f0;white-space:nowrap;font-size:12px;text-transform:uppercase;letter-spacing:.3px}
.ltms-auditor-panel .widefat tbody td{padding:9px 14px;border-bottom:1px solid #f1f5f9;vertical-align:middle;color:#334155}
.ltms-auditor-panel .widefat tbody tr:last-child td{border-bottom:none}
.ltms-auditor-panel .widefat tbody tr:hover td{background:#f8fafc}
.ltms-auditor-panel .widefat.striped tbody tr:nth-child(even) td{background:#fafbfc}
.ltms-num{text-align:right;font-family:"Courier New",monospace;font-size:12.5px}
.ltms-badge{display:inline-flex;align-items:center;padding:2px 9px;border-radius:12px;font-size:11px;font-weight:700;letter-spacing:.3px;text-transform:uppercase;line-height:1.5;white-space:nowrap}
.ltms-badge-danger{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
.ltms-badge-warning{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
.ltms-badge-secondary{background:#f1f5f9;color:#475569;border:1px solid #cbd5e1}
.ltms-badge-info{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}
.ltms-badge-success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.ltms-status-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:4px;font-size:11.5px;font-weight:600;text-transform:capitalize}
.ltms-status-badge::before{content:"●";font-size:8px}
.ltms-status-pending{background:#fffbeb;color:#92400e;border:1px solid #fde68a}
.ltms-status-under_review{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe}
.ltms-status-approved,.ltms-status-completed{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0}
.ltms-status-rejected{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}
.ltms-empty-state{text-align:center;padding:32px 20px;color:#94a3b8;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;margin-bottom:24px}
.ltms-empty-state .ltms-empty-icon{font-size:2rem;margin-bottom:8px}
.ltms-empty-state p{margin:0;font-size:13.5px}
.ltms-row-alert td{background:#fff7ed!important}
.ltms-panel-footer{background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 16px;font-size:12px;color:#64748b;display:flex;align-items:center;gap:8px;margin-top:32px}
.ltms-panel-footer::before{content:"🔒"}
.ltms-auditor-panel code{background:#f1f5f9;padding:1px 6px;border-radius:3px;font-size:12px;color:#0369a1}
ENDCSS;

$r1 = file_put_contents($css_path, $css);
echo "CSS write: " . ($r1 !== false ? "$r1 bytes OK" : "FAILED - " . (is_writable($css_path) ? 'file not writable' : 'dir not writable')) . "\n";

// ── Patch 2: class-ltms-admin.php — agregar enqueue auditor CSS ───────────────
$admin_path = $base . '/includes/admin/class-ltms-admin.php';
if (file_exists($admin_path)) {
    $content = file_get_contents($admin_path);
    $old = "        wp_enqueue_style( 'ltms-admin', \$url . 'css/ltms-admin.css', [], \$ver );\n        wp_enqueue_style( 'ltms-admin-enterprise', \$url . 'css/ltms-admin-enterprise.css', [], \$ver );";
    $new = "        wp_enqueue_style( 'ltms-admin', \$url . 'css/ltms-admin.css', [], \$ver );\n        wp_enqueue_style( 'ltms-admin-enterprise', \$url . 'css/ltms-admin-enterprise.css', [], \$ver );\n        if ( str_contains( \$hook_suffix, 'ltms-auditor' ) ) {\n            wp_enqueue_style( 'ltms-auditor', \$url . 'css/ltms-auditor.css', [], \$ver );\n        }";
    if (strpos($content, 'ltms-auditor') !== false) {
        echo "class-ltms-admin.php: enqueue already present OK\n";
    } elseif (strpos($content, $old) !== false) {
        $content = str_replace($old, $new, $content);
        file_put_contents($admin_path, $content);
        echo "class-ltms-admin.php: enqueue patch applied OK\n";
    } else {
        echo "class-ltms-admin.php: pattern not found — manual patch needed\n";
    }
} else {
    echo "class-ltms-admin.php: NOT FOUND\n";
}

// ── Patch 3: ltms-admin.css — remover conflicto .ltms-auditor-panel grid ──────
$ltms_admin_css = $base . '/assets/css/ltms-admin.css';
if (file_exists($ltms_admin_css)) {
    $c = file_get_contents($ltms_admin_css);
    if (strpos($c, 'grid-template-columns: repeat(auto-fill, minmax(220px') !== false) {
        $c = preg_replace('/\/\* ── Auditor panel.*?\.ltms-audit-filters \{[^}]+\}/s', '/* Auditor panel styles moved to ltms-auditor.css */', $c);
        file_put_contents($ltms_admin_css, $c);
        echo "ltms-admin.css: conflicting grid removed OK\n";
    } else {
        echo "ltms-admin.css: no conflict found (already clean) OK\n";
    }
}

// ── Flush OPcache ─────────────────────────────────────────────────────────────
if (function_exists('opcache_reset')) {
    echo "opcache_reset: " . (opcache_reset() ? "OK" : "FAILED") . "\n";
} else {
    echo "opcache_reset: not available\n";
}
if (function_exists('opcache_invalidate')) {
    opcache_invalidate($css_path, true);
    opcache_invalidate($admin_path, true);
}

// ── Flush WP object cache ─────────────────────────────────────────────────────
$wp_config = __DIR__ . '/wp-config.php';
if (file_exists($wp_config)) {
    // Touch plugin files to bust file cache
    touch($css_path);
    touch($admin_path);
    echo "touch() on patched files OK\n";
}

echo "\n=== RESULT ===\n";
echo "CSS size: " . (file_exists($css_path) ? filesize($css_path) . " bytes" : "MISSING") . "\n";
echo "CSS has v2.3.0 marker: " . (file_exists($css_path) && strpos(file_get_contents($css_path),'v2.3.0')!==false ? "YES ✓" : "NO ✗") . "\n";
echo "Admin has enqueue: " . (file_exists($admin_path) && strpos(file_get_contents($admin_path),'ltms-auditor')!==false ? "YES ✓" : "NO ✗") . "\n";
echo "\nDONE - delete this file now: https://lo-tengo.com.co/ltms-force-update.php\n";
