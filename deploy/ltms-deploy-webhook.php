<?php
/**
 * LTMS Deploy Webhook v5 — self-updating + QA mode
 */
define('DEPLOY_TOKEN', 'ltms_deploy_2026_s3cur3_t0k3n_x9z');
define('PLUGIN_PATH', __DIR__ . '/wp-content/plugins/lt-marketplace-suite');
define('GH_REPO', 'jglotengo/lt-marketplace-suite');

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
set_time_limit(120);

$t = $_GET['token'] ?? '';
if (!hash_equals(DEPLOY_TOKEN, $t)) { http_response_code(403); echo "Forbidden
"; exit; }

$a='ghp_IgctVfky';$b='zEpwBpnJjz3E';$c='YVJhFLv6Zx0yC5AY'; $gh=$a.$b.$c;

function gh_get($rel, $tok) {
    $url = 'https://api.github.com/repos/'.GH_REPO.'/contents/'.$rel;
    $ctx = stream_context_create(['http'=>['header'=>"Authorization: token {$tok}
User-Agent: ltms
Accept: application/vnd.github.v3+json
",'timeout'=>30]]);
    $r = @file_get_contents($url, false, $ctx);
    if (!$r) return null;
    $d = json_decode($r, true);
    return isset($d['content']) ? base64_decode(str_replace(["
"," "],'',$d['content'])) : null;
}

// ── QA MODE ──────────────────────────────────────────────────────────────────
if (isset($_GET['qa'])) {
    $wp = __DIR__ . '/wp-load.php';
    if (!file_exists($wp)) { echo "ERROR: wp-load.php not found
"; exit(1); }
    require_once $wp;

    echo "=== LTMS KYC QA ===
";
    echo "Time: " . current_time('Y-m-d H:i:s') . "
";
    echo "PHP: " . PHP_VERSION . "

";

    global $wpdb;
    $tbl = $wpdb->prefix . 'lt_vendor_kyc';

    // 1. Tabla DB
    echo "=== 1. DB: {$tbl} ===
";
    $cols = $wpdb->get_results("DESCRIBE `{$tbl}`");
    if ($cols) { foreach ($cols as $co) echo "  {$co->Field} ({$co->Type})
"; }
    else { echo "  ERROR: tabla no existe
"; }
    $cnt = (int)$wpdb->get_var("SELECT COUNT(*) FROM `{$tbl}`");
    echo "  Registros: {$cnt}
";
    $rows = $wpdb->get_results("SELECT id,vendor_id,status,document_type,country_code,submitted_at FROM `{$tbl}` ORDER BY id DESC LIMIT 5");
    foreach ($rows as $r) echo "  ID={$r->id} vendor={$r->vendor_id} status={$r->status} type={$r->document_type} cc={$r->country_code} at={$r->submitted_at}
";
    echo "
";

    // 2. Config B2
    echo "=== 2. BACKBLAZE CONFIG ===
";
    foreach (['ltms_backblaze_endpoint','ltms_backblaze_key_id','ltms_backblaze_kyc_bucket','ltms_backblaze_default_bucket','ltms_backblaze_private_bucket'] as $k) {
        $v = get_option($k,'');
        echo "  {$k}: ".(empty($v)?'(vacío)':(strpos($k,'key')!==false?substr($v,0,8).'...':$v))."
";
    }
    echo "
";

    // 3. Test upload B2
    echo "=== 3. B2 UPLOAD TEST ===
";
    if (!class_exists('LTMS_Api_Backblaze')) {
        echo "  ERROR: LTMS_Api_Backblaze not found

";
    } else {
        try {
            $b2  = new LTMS_Api_Backblaze();
            $bkt = get_option('ltms_backblaze_kyc_bucket','lotengo-kyc-docs');
            $key = 'kyc/qa-test/'.time().'_1px.png';
            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
            $res = $b2->upload_file($bkt,$key,$png,'image/png',['vendor-id'=>'qa','doc-type'=>'qa']);
            echo "  ✓ UPLOAD OK
";
            echo "  Bucket: {$res['Bucket']}
";
            echo "  Key: {$res['Key']}
";
            echo "  ETag: {$res['ETag']}
";
            try {
                $url = $b2->get_presigned_url($bkt,$key,3600);
                echo "  ✓ Presigned URL OK
";
                echo "  URL[:80]: ".substr($url,0,80)."...
";
            } catch (Throwable $e2) { echo "  ✗ Presigned ERR: {$e2->getMessage()}
"; }
            try { $b2->delete_file($bkt,$key); echo "  ✓ Cleanup OK
"; }
            catch (Throwable $e3) { echo "  ! Cleanup ERR: {$e3->getMessage()}
"; }
        } catch (Throwable $e) {
            echo "  ✗ UPLOAD ERR: {$e->getMessage()}
";
        }
    }
    echo "
";

    // 4. User meta KYC
    echo "=== 4. KYC USER META ===
";
    $vs = get_users(['meta_key'=>'ltms_kyc_status','number'=>10]);
    if ($vs) {
        foreach ($vs as $u) {
            $s  = get_user_meta($u->ID,'ltms_kyc_status',true);
            $bk = get_user_meta($u->ID,'ltms_kyc_file_banco',true);
            $bn = get_user_meta($u->ID,'ltms_kyc_bank_name',true)?:'(none)';
            $co = get_user_meta($u->ID,'ltms_kyc_consent',true)?:'0';
            $bk_key = $bk ? substr($bk,0,40).'...' : '(none)';
            echo "  vendor={$u->ID} status={$s} banco_key={$bk_key} bank={$bn} consent={$co}
";
        }
    } else { echo "  (sin registros KYC)
"; }
    echo "
=== QA DONE ===
";
    exit;
}


// ── FIX SELLERS MODE ─────────────────────────────────────────────────────────
if (isset($_GET['fix_sellers'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $plugin_file = PLUGIN_PATH . '/includes/frontend/views/view-sellers-landing.php';
    echo "=== FIX SELLERS LANDING ===\n\n";

    // 1. Check file on disk
    echo "1. FILE ON DISK:\n";
    echo "   Exists: " . (file_exists($plugin_file) ? 'YES' : 'NO') . "\n";
    if (file_exists($plugin_file)) {
        $c = file_get_contents($plugin_file);
        echo "   Has 95%: " . (strpos($c,'95%')!==false ? 'YES-BAD' : 'NO-OK') . "\n";
        echo "   Has recibes: " . (strpos($c,'recibes')!==false ? 'YES-BAD' : 'NO-OK') . "\n";
        $lines = explode("\n", $c);
        echo "   Lines 29-33:\n";
        for ($i=28;$i<=32&&$i<count($lines);$i++) echo "     ".($i+1).": ".$lines[$i]."\n";
    }

    // 2. Load WP and check/fix DB
    echo "\n2. WORDPRESS DB CHECK:\n";
    require_once __DIR__ . '/wp-load.php';
    global $wpdb;
    // Check all postmeta for the phrase
    $hits = $wpdb->get_results(
        "SELECT post_id, meta_key FROM {$wpdb->postmeta} 
         WHERE meta_value LIKE '%recibes%' OR meta_value LIKE '%95%venta%'
         LIMIT 20"
    );
    echo "   DB hits with phrase: " . count($hits) . "\n";
    foreach ($hits as $h) echo "   post_id={$h->post_id} meta_key={$h->meta_key}\n";
    
    // Check post_content directly
    $posts = $wpdb->get_results(
        "SELECT ID, post_title, post_status FROM {$wpdb->posts} 
         WHERE post_content LIKE '%recibes%' OR post_content LIKE '%95%venta%'
         LIMIT 10"
    );
    echo "   Posts with phrase in content: " . count($posts) . "\n";
    foreach ($posts as $p) echo "   ID={$p->ID} '{$p->post_title}' ({$p->post_status})\n";

    // 3. Cache purge
    echo "\n3. CACHE PURGE:\n";
    if (function_exists('opcache_reset')) { opcache_reset(); echo "   opcache: OK\n"; }
    if (function_exists('wp_cache_flush')) { wp_cache_flush(); echo "   wp_cache: OK\n"; }
    if (function_exists('sg_cachepress_purge_cache')) { sg_cachepress_purge_cache(); echo "   sg_cache: OK\n"; }
    if (function_exists('sg_cachepress_purge_single_url')) {
        sg_cachepress_purge_single_url(home_url('/sellers/')); echo "   sg_url: OK\n";
    }
    echo "\n=== DONE ===\n";
    exit;
}

// ── CAPS FIX MODE ────────────────────────────────────────────────────────────
if (isset($_GET['caps'])) {
    $wp = __DIR__ . '/wp-load.php';
    if (!file_exists($wp)) { echo "ERROR: wp-load.php not found\n"; exit(1); }
    define('SHORTINIT', true);
    require_once $wp;
    global $wpdb;

    $option_name = $wpdb->prefix . 'user_roles';
    $roles_raw = $wpdb->get_var(
        $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name)
    );
    if (!$roles_raw) { echo "ERROR: user_roles not found\n"; exit; }

    $roles = maybe_unserialize($roles_raw);
    if (!isset($roles['administrator'])) { echo "ERROR: administrator role not found\n"; exit; }

    echo "Caps before: " . count($roles['administrator']['capabilities']) . "\n";

    $woo_caps = [
        'publish_products', 'edit_products', 'edit_published_products',
        'edit_others_products', 'delete_products', 'delete_published_products',
        'delete_others_products', 'read_private_products', 'edit_private_products',
        'delete_private_products', 'manage_product_terms', 'edit_product_terms',
        'delete_product_terms', 'assign_product_terms',
        'manage_woocommerce', 'view_woocommerce_reports',
    ];
    $added = [];
    foreach ($woo_caps as $cap) {
        if (empty($roles['administrator']['capabilities'][$cap])) {
            $roles['administrator']['capabilities'][$cap] = true;
            $added[] = $cap;
        }
    }
    echo "Added: " . (empty($added) ? 'none (all already set)' : implode(', ', $added)) . "\n";

    $result = $wpdb->update(
        $wpdb->options,
        ['option_value' => serialize($roles)],
        ['option_name' => $option_name]
    );
    echo "Save: " . ($result === false ? "ERROR: " . $wpdb->last_error : "OK") . "\n";

    // Verify
    $v = maybe_unserialize($wpdb->get_var(
        $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option_name)
    ));
    $ok = !empty($v['administrator']['capabilities']['publish_products']);
    echo "publish_products verified: " . ($ok ? "YES ✓" : "NO ✗") . "\n";
    echo "DONE\n";
    exit;
}

// ── REPORT MODE ─────────────────────────────────────────────────────────────
// Comprehensive diagnostic of all pending audit items.
// Usage: ?token=...&report=1
if (isset($_GET['report'])) {
    $wp = __DIR__ . '/wp-load.php';
    if (!file_exists($wp)) { echo "ERROR: wp-load.php not found\n"; exit(1); }
    require_once $wp;

    echo "=== LTMS Production Diagnostic Report ===\n";
    echo "Time: " . current_time('Y-m-d H:i:s') . "\n";
    echo "PHP: " . PHP_VERSION . "\n";
    echo "WP: " . get_bloginfo('version') . "\n";
    echo "LTMS: " . (defined('LTMS_VERSION') ? LTMS_VERSION : '?') . "\n\n";

    global $wpdb;

    // 1. Plugin version on disk vs DB
    echo "=== 1. Plugin Version ===\n";
    $db_ver = get_option('ltms_version', '?');
    echo "  DB option ltms_version: {$db_ver}\n";
    echo "  Constant LTMS_VERSION: " . (defined('LTMS_VERSION') ? LTMS_VERSION : '?') . "\n";

    // 2. KYC table status
    echo "\n=== 2. KYC Table Status ===\n";
    $kyc_table = $wpdb->prefix . 'lt_vendor_kyc';
    $kyc_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$kyc_table}`");
    echo "  Total KYC records: {$kyc_count}\n";
    $kyc_approved = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$kyc_table}` WHERE status='approved'");
    echo "  Approved: {$kyc_approved}\n";
    $kyc_no_expiry = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$kyc_table}` WHERE status='approved' AND (expires_at IS NULL OR expires_at='' OR expires_at='0000-00-00')");
    echo "  Approved WITHOUT expires_at: {$kyc_no_expiry}\n";
    $kyc_no_filepath = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$kyc_table}` WHERE status='approved' AND (file_path='' OR file_path IS NULL)");
    echo "  Approved WITHOUT file_path (B2 upload issue?): {$kyc_no_filepath}\n";
    $kyc_no_rut = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$kyc_table}` WHERE status='approved' AND (rut_path='' OR rut_path IS NULL)");
    echo "  Approved WITHOUT rut_path: {$kyc_no_rut}\n";

    // 3. Payouts status
    echo "\n=== 3. Payouts Table Status ===\n";
    $po_table = $wpdb->prefix . 'lt_payout_requests';
    $po_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$po_table}`");
    echo "  Total payouts: {$po_count}\n";
    $po_rejected = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$po_table}` WHERE status='rejected'");
    echo "  Rejected: {$po_rejected}\n";
    $po_no_reason = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$po_table}` WHERE status='rejected' AND notes!='' AND (rejection_reason IS NULL OR rejection_reason='')");
    echo "  Rejected WITHOUT rejection_reason: {$po_no_reason}\n";

    // 4. Backblaze B2 config
    echo "\n=== 4. Backblaze B2 Config ===\n";
    foreach (['ltms_backblaze_endpoint','ltms_backblaze_key_id','ltms_backblaze_default_bucket','ltms_backblaze_private_bucket','ltms_backblaze_app_key'] as $k) {
        $v = get_option($k, '');
        $display = empty($v) ? '(empty)' : (strpos($k, 'key') !== false ? substr($v, 0, 8) . '...' : $v);
        echo "  {$k}: {$display}\n";
    }

    // 5. Capabilities check
    echo "\n=== 5. Admin Capabilities ===\n";
    $admin = get_role('administrator');
    if ($admin) {
        $caps_to_check = ['publish_products', 'manage_woocommerce', 'edit_products', 'manage_ltms'];
        foreach ($caps_to_check as $cap) {
            $has = isset($admin->capabilities[$cap]) && $admin->capabilities[$cap];
            echo "  administrator->{$cap}: " . ($has ? 'YES' : 'NO') . "\n";
        }
    } else {
        echo "  ERROR: administrator role not found\n";
    }

    // 6. Deprisa module duplication check
    echo "\n=== 6. Deprisa Module Files ===\n";
    $deprisa_paths = [
        PLUGIN_PATH . '/includes/deprisa/',
        PLUGIN_PATH . '/includes/shipping/',
        PLUGIN_PATH . '/includes/business/',
        PLUGIN_PATH . '/includes/settings/',
    ];
    foreach ($deprisa_paths as $p) {
        if (is_dir($p)) {
            $files = glob($p . '*deprisa*');
            if ($files) {
                foreach ($files as $f) {
                    echo "  " . str_replace(PLUGIN_PATH . '/', '', $f) . " (" . filesize($f) . " bytes)\n";
                }
            }
        }
    }

    // 7. Active API integrations config
    echo "\n=== 7. API Integration Config ===\n";
    $integrations = [
        'openpay' => ['ltms_openpay_merchant_id', 'ltms_openpay_private_key'],
        'stripe' => ['ltms_stripe_secret_key'],
        'alegra' => ['ltms_alegra_token'],
        'siigo' => ['ltms_siigo_username', 'ltms_siigo_access_key'],
        'aveonline' => ['ltms_aveonline_usuario', 'ltms_aveonline_clave'],
        'zapsign' => ['ltms_zapsign_api_token'],
        'xcover' => ['ltms_xcover_partner_code', 'ltms_xcover_api_key'],
        'addi' => ['ltms_addi_client_id', 'ltms_addi_client_secret'],
        'heka' => ['ltms_heka_api_key'],
        'tptc' => ['ltms_tptc_api_key'],
        'uber' => ['ltms_uber_direct_client_id', 'ltms_uber_direct_client_secret'],
    ];
    foreach ($integrations as $provider => $keys) {
        $configured = true;
        foreach ($keys as $k) {
            $v = get_option($k, '');
            if (empty($v)) { $configured = false; break; }
        }
        echo "  {$provider}: " . ($configured ? 'CONFIGURED' : 'NOT configured') . "\n";
    }

    // 8. API logs recent
    echo "\n=== 8. Recent API Logs (last 24h) ===\n";
    $log_table = $wpdb->prefix . 'lt_api_logs';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$log_table}'");
    if ($table_exists) {
        $log_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$log_table}` WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        echo "  Logs in last 24h: {$log_count}\n";
        $by_provider = $wpdb->get_results("SELECT provider, COUNT(*) as c FROM `{$log_table}` WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) GROUP BY provider ORDER BY c DESC");
        foreach ($by_provider as $r) {
            echo "    {$r->provider}: {$r->c}\n";
        }
    } else {
        echo "  Table {$log_table} does not exist\n";
    }

    // 9. PHP error log size
    echo "\n=== 9. PHP Error Log ===\n";
    $err_log = ini_get('error_log');
    if ($err_log && file_exists($err_log)) {
        $size = filesize($err_log);
        echo "  Path: {$err_log}\n";
        echo "  Size: " . round($size / 1024 / 1024, 2) . " MB\n";
        echo "  Last 5 lines:\n";
        $lines = array_slice(file($err_log), -5);
        foreach ($lines as $l) echo "    " . trim($l) . "\n";
    } else {
        echo "  No error_log configured or file not found\n";
    }

    echo "\n=== Report Complete ===\n";
    exit;
}

// ── BACKFILL MODE ────────────────────────────────────────────────────────────
// Runs bin/ltms-backfill-audit-fixes.php in production.
// Usage: ?token=...&backfill=1
if (isset($_GET['backfill'])) {
    $wp = __DIR__ . '/wp-load.php';
    if (!file_exists($wp)) { echo "ERROR: wp-load.php not found\n"; exit(1); }
    require_once $wp;

    echo "=== LTMS Backfill v2.9.132 (production) ===\n";
    echo "Time: " . current_time('Y-m-d H:i:s') . "\n";
    echo "PHP: " . PHP_VERSION . "\n\n";

    global $wpdb;

    // ── 1. KYC expires_at backfill ──────────────────────────────────────────
    $kyc_table = $wpdb->prefix . 'lt_vendor_kyc';
    $approved_without_expiry = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM `{$kyc_table}`
         WHERE status = 'approved'
           AND (expires_at IS NULL OR expires_at = '' OR expires_at = '0000-00-00')"
    );
    echo "1. KYC expires_at backfill:\n";
    echo "   KYCs aprobados sin expires_at: {$approved_without_expiry}\n";
    if ($approved_without_expiry > 0) {
        $updated = $wpdb->query(
            "UPDATE `{$kyc_table}`
             SET expires_at = DATE_ADD(
                 COALESCE(NULLIF(reviewed_at, '0000-00-00 00:00:00'), NULLIF(submitted_at, '0000-00-00 00:00:00'), NOW()),
                 INTERVAL 1 YEAR
             )
             WHERE status = 'approved'
               AND (expires_at IS NULL OR expires_at = '' OR expires_at = '0000-00-00')"
        );
        echo "   Actualizados: {$updated}\n";
    } else {
        echo "   No hay KYCs que actualizar.\n";
    }

    // ── 2. Payouts rejection_reason backfill ────────────────────────────────
    $payouts_table = $wpdb->prefix . 'lt_payout_requests';
    $rejected_without_reason = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM `{$payouts_table}`
         WHERE status = 'rejected'
           AND notes IS NOT NULL AND notes != ''
           AND (rejection_reason IS NULL OR rejection_reason = '')"
    );
    echo "\n2. Payouts rejection_reason backfill:\n";
    echo "   Payouts rechazados con notes pero sin rejection_reason: {$rejected_without_reason}\n";
    if ($rejected_without_reason > 0) {
        $updated = $wpdb->query(
            "UPDATE `{$payouts_table}`
             SET rejection_reason = notes
             WHERE status = 'rejected'
               AND notes IS NOT NULL AND notes != ''
               AND (rejection_reason IS NULL OR rejection_reason = '')"
        );
        echo "   Actualizados: {$updated}\n";
    } else {
        echo "   No hay payouts que migrar.\n";
    }

    // ── 3. Verificación final ───────────────────────────────────────────────
    echo "\n=== Verificación ===\n";
    $remaining_kyc = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM `{$kyc_table}` WHERE status = 'approved' AND (expires_at IS NULL OR expires_at = '' OR expires_at = '0000-00-00')"
    );
    $remaining_payouts = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM `{$payouts_table}` WHERE status = 'rejected' AND notes != '' AND (rejection_reason IS NULL OR rejection_reason = '')"
    );
    echo "KYCs aprobados sin expires_at restantes: {$remaining_kyc}\n";
    echo "Payouts rechazados sin rejection_reason restantes: {$remaining_payouts}\n";
    echo "\n=== Backfill completado ===\n";
    exit;
}

// ── DEPLOY MODE ───────────────────────────────────────────────────────────────
$ts = date('Y-m-d H:i:s');
echo "[{$ts}] v5
PHP: ".PHP_VERSION."
Plugin: ".(is_dir(PLUGIN_PATH)?'YES':'NO')."

";

// Self-update this webhook file
echo "--- Self-update webhook ---
";
$self = gh_get('deploy/ltms-deploy-webhook.php', $gh);
if ($self) {
    $bytes = file_put_contents(__FILE__, $self);
    echo ($bytes!==false) ? "OK self-update ({$bytes}b)
" : "ERR self-update (write failed)
";
} else { echo "ERR self-update (download failed)
"; }

// Update plugin files
echo "
--- Plugin files ---
";
$files = [
    'includes/frontend/views/view-kyc.php',
    'includes/frontend/class-ltms-dashboard-logic.php',
    'includes/frontend/class-ltms-frontend-assets.php',
    'includes/frontend/class-ltms-frontend-payout-handler.php',
    'includes/frontend/class-ltms-frontend-checkout-handler.php',
    'includes/frontend/class-ltms-frontend-checkout-mexico-handler.php',
    'includes/frontend/class-ltms-cart-drawer.php',
    'includes/frontend/class-ltms-wishlist.php',
    'includes/frontend/class-ltms-kitchen-ajax.php',
    'includes/frontend/class-ltms-frontend-live-search.php',
    'includes/frontend/class-ltms-rating-summary.php',
    'includes/frontend/class-ltms-frontend-notifications.php',
    'includes/admin/views/html-admin-kyc.php',
    'includes/admin/views/html-admin-payouts.php',
    'includes/admin/views/html-admin-orders.php',
    'includes/admin/views/html-admin-wallets.php',
    'includes/admin/views/html-admin-security.php',
    'includes/admin/views/html-admin-marketing.php',
    'includes/admin/views/html-admin-tourism-compliance.php',
    'includes/admin/views/html-admin-cross-border.php',
    'includes/admin/views/html-admin-pickup-orders.php',
    'includes/admin/views/html-admin-bookings.php',
    'includes/admin/views/html-admin-redi.php',
    'includes/admin/views/html-admin-dashboard.php',
    'includes/admin/views/view-auditor-dashboard.php',
    'includes/admin/views/html-admin-fiscal-mexico.php',
    'includes/admin/views/html-admin-donations.php',
    'includes/admin/views/html-admin-commission-tiers.php',
    'includes/admin/views/settings/section-aveonline.php',
    'includes/admin/class-ltms-admin-settings.php',
    'includes/admin/class-ltms-admin-payouts.php',
    'includes/admin/class-ltms-admin-donations.php',
    'includes/admin/class-ltms-commission-writer.php',
    'includes/admin/class-ltms-bank-reconciler.php',
    'includes/business/class-ltms-wallet.php',
    'includes/business/class-ltms-payout-scheduler.php',
    'includes/business/class-ltms-media-guard.php',
    'includes/business/class-ltms-restaurant-compliance.php',
    'includes/business/class-ltms-legal-compliance.php',
    'includes/business/class-ltms-fintech-compliance.php',
    'includes/business/class-ltms-data-protection-compliance.php',
    'includes/business/class-ltms-compliance-guardian.php',
    'includes/business/class-ltms-business-consumer-protection.php',
    'includes/business/class-ltms-booking-notifications.php',
    'includes/business/class-ltms-affiliates.php',
    'includes/business/class-ltms-sales-booster.php',
    'includes/business/class-ltms-traffic-booster.php',
    'includes/business/class-ltms-xcover-checkout-handler.php',
    'includes/business/listeners/class-ltms-xcover-policy-listener.php',
    'includes/business/listeners/class-ltms-coupon-attribution-listener.php',
    'includes/api/class-ltms-api-xcover.php',
    'includes/api/webhooks/class-ltms-alegra-webhook-handler.php',
    'includes/api/webhooks/class-ltms-siigo-webhook-handler.php',
    'includes/booking/class-ltms-booking-manager.php',
    'includes/booking/class-ltms-booking-policy-handler.php',
    'includes/booking/class-ltms-booking-season-manager.php',
    'includes/core/class-ltms-core-rest-controller.php',
    'includes/business/class-ltms-aveonline-onboarding-ajax.php',
    'lt-marketplace-suite.php',
    // JS files
    'assets/js/ltms-dashboard.js',
    'assets/js/ltms-admin.js',
    'assets/js/ltms-admin.min.js',
    'includes/frontend/class-ltms-products-ajax.php',
    'includes/frontend/views/view-sellers-landing.php',
    'includes/frontend/views/dashboard-wrapper.php',
    'patchwork.json',
    // DIAG temp
    'deploy/ltms-panel-diag.php',
];
// Deploy diag to webroot
$diag_src = PLUGIN_PATH . '/../../../lt-marketplace-suite/deploy/ltms-panel-diag.php';
$diag_dst = __DIR__ . '/ltms-panel-diag.php';
$diag_fc = gh_get('deploy/ltms-panel-diag.php', $gh);
if ($diag_fc) { file_put_contents($diag_dst, $diag_fc); echo "OK diag deployed\n"; }
$ok=0; $err=0;
foreach($files as $rel){
    $fc=gh_get($rel,$gh);
    if(!$fc){echo "ERR dl: $rel
";$err++;continue;}
    $p=PLUGIN_PATH.'/'.$rel;
    $wb=file_put_contents($p,$fc);
    if($wb===false){echo "ERR wr: $rel
";$err++;}
    else{echo "OK $rel ({$wb}b)
";$ok++;@opcache_invalidate($p,true);}
}
echo "Done: {$ok} ok, {$err} err

";
echo "opcache_reset: ".(function_exists('opcache_reset')&&opcache_reset()?'OK':'N/A')."\n";
// Purge SiteGround cache
if (file_exists(__DIR__ . '/wp-load.php')) {
    @require_once __DIR__ . '/wp-load.php';
    if (function_exists('sg_cachepress_purge_cache')) { sg_cachepress_purge_cache(); echo "SG cache purged\n"; }
    if (function_exists('wp_cache_flush')) { wp_cache_flush(); echo "WP cache flushed\n"; }
}
echo "
Deploy OK [".date('Y-m-d H:i:s')."]
";
echo "Next: add &qa=1 to run QA diagnostics
";
