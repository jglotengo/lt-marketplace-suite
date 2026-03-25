#!/usr/bin/env bash
# =============================================================================
# LTMS Production Fix Script — lo-tengo.com.co
# =============================================================================
# Run on the production server via SSH:
#   bash /home/customer/www/lo-tengo.com.co/public_html/wp-content/plugins/lt-marketplace-suite/bin/fix-production.sh
#
# Or copy & paste each section individually.
# =============================================================================

set -euo pipefail

WP_ROOT="/home/customer/www/lo-tengo.com.co/public_html"
PLUGIN_DIR="${WP_ROOT}/wp-content/plugins/lt-marketplace-suite"
WP_CLI="wp --path=${WP_ROOT} --allow-root"

echo "============================================================"
echo " LTMS Production Fix — $(date)"
echo "============================================================"

# ─────────────────────────────────────────────────────────────────────────────
# STEP 0 — VERIFY WP-CLI AND WORDPRESS
# ─────────────────────────────────────────────────────────────────────────────
echo ""
echo "── STEP 0: Environment Check"
wp --version 2>/dev/null || { echo "WP-CLI not in PATH. Trying /usr/local/bin/wp..."; WP_CLI="/usr/local/bin/wp --path=${WP_ROOT} --allow-root"; }
${WP_CLI} core version
${WP_CLI} plugin status lt-marketplace-suite

# ─────────────────────────────────────────────────────────────────────────────
# STEP 1 — VIEW DEBUG LOG (last 150 lines, LTMS-related)
# ─────────────────────────────────────────────────────────────────────────────
echo ""
echo "── STEP 1: Debug Log (LTMS entries)"
LOG_FILE="${WP_ROOT}/wp-content/debug.log"
if [ -f "${LOG_FILE}" ]; then
    echo "  Last 150 lines of debug.log:"
    tail -150 "${LOG_FILE}" | grep -i "ltms\|fatal\|error\|exception\|warning" || echo "  (no relevant entries)"
else
    echo "  debug.log not found at ${LOG_FILE}"
    # Try alternate location
    LOG_FILE="${WP_ROOT}/wp-content/uploads/ltms-logs/ltms-$(date +%Y-%m).log"
    [ -f "${LOG_FILE}" ] && tail -50 "${LOG_FILE}" || echo "  No LTMS log file found either."
fi

# ─────────────────────────────────────────────────────────────────────────────
# STEP 2 — CHECK PLUGIN DIRECTORY STRUCTURE
# ─────────────────────────────────────────────────────────────────────────────
echo ""
echo "── STEP 2: Plugin Directory Structure"
echo "  Plugin dir: ${PLUGIN_DIR}"
ls -la "${PLUGIN_DIR}/" 2>/dev/null || echo "  Plugin directory not found!"
echo ""
echo "  vendor/ directory:"
ls -la "${PLUGIN_DIR}/vendor/" 2>/dev/null && echo "  ✓ vendor/ exists" || echo "  ✗ vendor/ MISSING — Composer not installed"
echo ""
echo "  includes/ top-level:"
ls "${PLUGIN_DIR}/includes/" 2>/dev/null || echo "  includes/ missing!"

# ─────────────────────────────────────────────────────────────────────────────
# STEP 3 — CHECK DATABASE TABLES
# ─────────────────────────────────────────────────────────────────────────────
echo ""
echo "── STEP 3: Database Tables"
${WP_CLI} db query "SHOW TABLES LIKE '%lt_%';" 2>/dev/null || echo "  Cannot query DB"

# Count expected tables
echo ""
echo "  Expected tables (prefix = \$(wp db prefix)lt_):"
DB_PREFIX=$(${WP_CLI} db prefix 2>/dev/null || echo "bkr_")
echo "  DB prefix detected: ${DB_PREFIX}"
TABLES=(vendors wallets wallet_transactions commissions referral_tree payouts kyc_documents notifications audit_logs waf_blocked_ips waf_logs api_logs coupons consumer_protection job_queue tracking insurance_policies redi_requests)
for TABLE in "${TABLES[@]}"; do
    FULL="${DB_PREFIX}lt_${TABLE}"
    EXISTS=$(${WP_CLI} db query "SHOW TABLES LIKE '${FULL}';" 2>/dev/null | grep -c "${FULL}" || true)
    if [ "${EXISTS}" -gt 0 ]; then
        echo "  ✓ ${FULL}"
    else
        echo "  ✗ ${FULL} MISSING"
    fi
done

# ─────────────────────────────────────────────────────────────────────────────
# STEP 4 — CHECK ADMINISTRATOR CAPABILITIES
# ─────────────────────────────────────────────────────────────────────────────
echo ""
echo "── STEP 4: Administrator Role Capabilities"
${WP_CLI} eval '
$role = get_role("administrator");
$caps = ["ltms_access_dashboard","ltms_manage_all_vendors","ltms_view_wallet_ledger",
         "ltms_manage_payouts","ltms_manage_commissions","ltms_view_audit_logs","ltms_manage_settings"];
foreach ($caps as $cap) {
    echo ($role && $role->has_cap($cap) ? "✓" : "✗ MISSING") . " {$cap}\n";
}
'

# ─────────────────────────────────────────────────────────────────────────────
# STEP 5 — CHECK CLASS LOADING
# ─────────────────────────────────────────────────────────────────────────────
echo ""
echo "── STEP 5: Class Loading"
${WP_CLI} eval '
$classes = [
    "LTMS_Admin"          => "admin menu",
    "LTMS_Roles"          => "capabilities",
    "LTMS_Core_Kernel"    => "bootloader",
    "LTMS_Core_Config"    => "config",
    "LTMS_Core_Logger"    => "logger",
    "LTMS_Core_Firewall"  => "WAF",
    "LTMS_DB_Migrations"  => "migrations",
    "LTMS_Business_Wallet"=> "wallet",
    "LTMS_Admin_Settings" => "settings",
    "LTMS_Gateway_Stripe" => "stripe",
];
foreach ($classes as $class => $purpose) {
    echo (class_exists($class) ? "✓" : "✗ NOT LOADED") . " {$class} ({$purpose})\n";
}
'

# ─────────────────────────────────────────────────────────────────────────────
# STEP 6 — CHECK WP-CONFIG CONSTANTS
# ─────────────────────────────────────────────────────────────────────────────
echo ""
echo "── STEP 6: wp-config.php Constants"
${WP_CLI} eval '
$constants = ["LTMS_ENCRYPTION_KEY","LTMS_ENVIRONMENT","LTMS_COUNTRY",
              "LTMS_TRUSTED_PROXY_IPS","DISABLE_WP_CRON","WP_DEBUG"];
foreach ($constants as $c) {
    if (defined($c)) {
        $v = constant($c);
        if (strpos($c,"KEY") !== false && strlen((string)$v) > 8)
            $v = substr($v,0,4)."****".substr($v,-4);
        echo "✓ {$c} = " . var_export($v, true) . "\n";
    } else {
        echo "  {$c} = (not defined)\n";
    }
}
// Check old constant name
if (defined("WP_LTMS_MASTER_KEY")) {
    echo "⚠ WP_LTMS_MASTER_KEY is defined (OLD NAME — should be LTMS_ENCRYPTION_KEY)\n";
}
'

# ─────────────────────────────────────────────────────────────────────────────
# STEP 7 — CHECK CRON JOBS
# ─────────────────────────────────────────────────────────────────────────────
echo ""
echo "── STEP 7: Cron Jobs"
${WP_CLI} cron event list --fields=hook,next_run_gmt,schedule 2>/dev/null | grep ltms || echo "  No LTMS cron events found"

# ─────────────────────────────────────────────────────────────────────────────
# STEP 8 — CAPTURE BOOT EXCEPTION
# ─────────────────────────────────────────────────────────────────────────────
echo ""
echo "── STEP 8: Boot Exception Capture"
${WP_CLI} eval '
if (!class_exists("LTMS_Core_Kernel")) {
    echo "LTMS_Core_Kernel NOT loaded — autoloader failed\n";
} else {
    try {
        $k = LTMS_Core_Kernel::get_instance();
        $ref = new ReflectionClass($k);
        $p = $ref->getProperty("booted");
        $p->setAccessible(true);
        echo "Kernel booted: " . ($p->getValue($k) ? "YES" : "NO") . "\n";
    } catch (Throwable $e) {
        echo "Kernel error: " . get_class($e) . ": " . $e->getMessage() . "\n";
        echo "File: " . $e->getFile() . " line " . $e->getLine() . "\n";
    }
}
'

echo ""
echo "============================================================"
echo " DIAGNOSTICS COMPLETE — Review output above, then run FIX"
echo " To apply fixes: run this script with --fix flag"
echo "============================================================"

# ─────────────────────────────────────────────────────────────────────────────
# FIX SECTION — Only runs if --fix flag passed
# ─────────────────────────────────────────────────────────────────────────────
if [[ "${1:-}" != "--fix" ]]; then
    echo ""
    echo " Pass --fix to apply all repairs automatically:"
    echo "   bash fix-production.sh --fix"
    exit 0
fi

echo ""
echo "============================================================"
echo " APPLYING FIXES"
echo "============================================================"

# FIX A — Composer install (if vendor/ missing)
if [ ! -f "${PLUGIN_DIR}/vendor/autoload.php" ]; then
    echo ""
    echo "── FIX A: Running composer install"
    cd "${PLUGIN_DIR}"
    if command -v composer &>/dev/null; then
        composer install --no-dev --optimize-autoloader
        echo "  ✓ Composer install complete"
    else
        echo "  ✗ composer not found in PATH"
        echo "    Manual fix: cd ${PLUGIN_DIR} && composer install --no-dev --optimize-autoloader"
        echo "    Or download vendor.zip from your repo and extract here"
    fi
fi

# FIX B — Run DB migrations
echo ""
echo "── FIX B: Running DB Migrations"
${WP_CLI} eval '
if (class_exists("LTMS_DB_Migrations")) {
    LTMS_DB_Migrations::run();
    echo "✓ Migrations ran successfully\n";
} else {
    echo "✗ LTMS_DB_Migrations class not available — autoloader issue\n";
}
'

# FIX C — Install role capabilities
echo ""
echo "── FIX C: Installing Role Capabilities"
${WP_CLI} eval '
if (class_exists("LTMS_Roles")) {
    LTMS_Roles::install();
    echo "✓ LTMS_Roles::install() completed\n";
} else {
    // Manual fallback
    $admin = get_role("administrator");
    if ($admin) {
        $caps = [
            "ltms_access_dashboard","ltms_manage_all_vendors","ltms_view_wallet_ledger",
            "ltms_manage_payouts","ltms_manage_commissions","ltms_view_audit_logs",
            "ltms_manage_settings","ltms_export_reports","ltms_manage_api_keys",
            "ltms_manage_kyc","ltms_manage_waf","ltms_impersonate_vendor",
        ];
        foreach ($caps as $cap) { $admin->add_cap($cap, true); }
        echo "✓ Capabilities added manually to administrator role\n";
    } else {
        echo "✗ administrator role not found\n";
    }
}
if (class_exists("LTMS_External_Auditor_Role")) {
    LTMS_External_Auditor_Role::install();
    echo "✓ LTMS_External_Auditor_Role::install() completed\n";
}
'

# FIX D — Re-run activator (pages + crons + options)
echo ""
echo "── FIX D: Re-running Activator"
${WP_CLI} eval '
if (class_exists("LTMS_Core_Activator")) {
    LTMS_Core_Activator::activate();
    echo "✓ Activator::activate() completed\n";
} else {
    echo "✗ LTMS_Core_Activator not loaded\n";
}
'

# FIX E — Flush rewrite rules
echo ""
echo "── FIX E: Flushing Rewrite Rules"
${WP_CLI} rewrite structure '/%postname%/' --hard
${WP_CLI} rewrite flush --hard
echo "  ✓ Rewrite rules flushed"

# FIX F — Clear all caches
echo ""
echo "── FIX F: Clearing Caches"
${WP_CLI} cache flush
${WP_CLI} transient delete --all 2>/dev/null || true
echo "  ✓ Object cache and transients cleared"

# FIX G — Deactivate and reactivate plugin (last resort)
echo ""
echo "── FIX G: Plugin Reactivation"
${WP_CLI} plugin deactivate lt-marketplace-suite
sleep 2
${WP_CLI} plugin activate lt-marketplace-suite
echo "  ✓ Plugin reactivated"

# ─────────────────────────────────────────────────────────────────────────────
# VERIFY FIXES
# ─────────────────────────────────────────────────────────────────────────────
echo ""
echo "============================================================"
echo " VERIFICATION AFTER FIX"
echo "============================================================"

echo ""
echo "── Capabilities (post-fix):"
${WP_CLI} eval '
$role = get_role("administrator");
$caps = ["ltms_access_dashboard","ltms_manage_all_vendors","ltms_view_wallet_ledger",
         "ltms_manage_payouts","ltms_manage_commissions"];
foreach ($caps as $cap) {
    echo ($role && $role->has_cap($cap) ? "✓" : "✗ STILL MISSING") . " {$cap}\n";
}
'

echo ""
echo "── LTMS Tables (post-fix):"
${WP_CLI} db query "SHOW TABLES LIKE '%lt_%';"

echo ""
echo "── Cron Events (post-fix):"
${WP_CLI} cron event list --fields=hook,next_run_gmt,schedule | grep ltms

echo ""
echo "── Kernel boot (post-fix):"
${WP_CLI} eval '
if (class_exists("LTMS_Core_Kernel")) {
    $k = LTMS_Core_Kernel::get_instance();
    $ref = new ReflectionClass($k);
    $p = $ref->getProperty("booted");
    $p->setAccessible(true);
    echo "Kernel booted: " . ($p->getValue($k) ? "YES ✓" : "NO ✗") . "\n";
}
'

echo ""
echo "============================================================"
echo " FIX COMPLETE — Verify admin menu at:"
echo "   https://lo-tengo.com.co/wp-admin/"
echo "============================================================"
echo ""
