<?php
/**
 * LTMS Production Diagnostic Script
 *
 * Usage (WP-CLI):
 *   wp eval-file wp-content/plugins/lt-marketplace-suite/bin/ltms-diagnose.php --allow-root
 *
 * Or via SSH one-liner:
 *   wp --path=/home/customer/www/lo-tengo.com.co/public_html \
 *      eval-file wp-content/plugins/lt-marketplace-suite/bin/ltms-diagnose.php --allow-root
 *
 * @package LTMS
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_CLI' ) ) {
    // Prevent direct web access
    die( 'Run via WP-CLI: wp eval-file ltms-diagnose.php' );
}

$sep  = str_repeat( '=', 70 );
$sep2 = str_repeat( '-', 70 );

echo "\n{$sep}\n";
echo " LTMS PRODUCTION DIAGNOSTIC — " . date( 'Y-m-d H:i:s T' ) . "\n";
echo "{$sep}\n\n";

$errors   = [];
$warnings = [];
$ok       = [];

// ─────────────────────────────────────────────────────────────────────────────
// 1. PHP ENVIRONMENT
// ─────────────────────────────────────────────────────────────────────────────
echo "── 1. PHP ENVIRONMENT\n";
echo "   PHP Version : " . PHP_VERSION . "\n";
echo "   OS          : " . PHP_OS . " " . php_uname('r') . "\n";
echo "   memory_limit: " . ini_get('memory_limit') . "\n";
echo "   max_exec    : " . ini_get('max_execution_time') . "s\n";

$required_ext = ['openssl', 'curl', 'mbstring', 'json', 'intl', 'bcmath', 'pdo_mysql'];
foreach ( $required_ext as $ext ) {
    if ( extension_loaded( $ext ) ) {
        $ok[] = "ext_{$ext}";
        echo "   ext/{$ext}   : OK\n";
    } else {
        $errors[] = "MISSING PHP extension: {$ext}";
        echo "   ext/{$ext}   : MISSING !!!\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 2. WORDPRESS ENVIRONMENT
// ─────────────────────────────────────────────────────────────────────────────
echo "\n── 2. WORDPRESS ENVIRONMENT\n";
echo "   WP Version  : " . get_bloginfo('version') . "\n";
echo "   Site URL    : " . site_url() . "\n";
echo "   ABSPATH     : " . ABSPATH . "\n";
echo "   WP_DEBUG    : " . ( defined('WP_DEBUG') && WP_DEBUG ? 'true' : 'false' ) . "\n";
echo "   DISABLE_WP_CRON: " . ( defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'true (real cron required)' : 'false' ) . "\n";

// WooCommerce
if ( function_exists('WC') ) {
    echo "   WooCommerce : " . WC()->version . " — OK\n";
    $ok[] = 'woocommerce';
} else {
    $errors[] = 'WooCommerce NOT active';
    echo "   WooCommerce : NOT ACTIVE !!!\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// 3. LTMS CONSTANTS
// ─────────────────────────────────────────────────────────────────────────────
echo "\n── 3. LTMS CONSTANTS\n";

$constants = [
    'LTMS_VERSION'         => true,
    'LTMS_PLUGIN_DIR'      => true,
    'LTMS_ENCRYPTION_KEY'  => false,  // optional (falls back to AUTH_KEY)
    'LTMS_ENVIRONMENT'     => false,
    'LTMS_COUNTRY'         => false,
    'LTMS_TRUSTED_PROXY_IPS' => false,
];

foreach ( $constants as $const => $required ) {
    if ( defined( $const ) ) {
        $val = constant( $const );
        // Mask encryption key
        if ( strpos( $const, 'KEY' ) !== false && strlen( $val ) > 8 ) {
            $val = substr( $val, 0, 4 ) . '****' . substr( $val, -4 );
        }
        echo "   {$const}: {$val}\n";
        $ok[] = "const_{$const}";
    } elseif ( $required ) {
        $errors[] = "MISSING required constant: {$const}";
        echo "   {$const}: NOT DEFINED !!!\n";
    } else {
        echo "   {$const}: not defined (will use default)\n";
    }
}

// Check for the OLD wrong constant name
if ( defined('WP_LTMS_MASTER_KEY') ) {
    $warnings[] = 'wp-config.php uses OLD constant WP_LTMS_MASTER_KEY — rename to LTMS_ENCRYPTION_KEY';
    echo "   WP_LTMS_MASTER_KEY: DEFINED (OLD NAME — rename to LTMS_ENCRYPTION_KEY!)\n";
}

// AUTH_KEY fallback check
if ( ! defined('LTMS_ENCRYPTION_KEY') ) {
    $auth_key = defined('AUTH_KEY') ? AUTH_KEY : '';
    if ( strlen( $auth_key ) >= 32 ) {
        echo "   Encryption fallback: AUTH_KEY (" . strlen($auth_key) . " chars) — OK\n";
        $ok[] = 'encryption_fallback_ok';
    } else {
        $errors[] = 'LTMS_ENCRYPTION_KEY not defined AND AUTH_KEY < 32 chars — encryption will throw RuntimeException';
        echo "   Encryption fallback: AUTH_KEY too short (" . strlen($auth_key) . " chars) — CRITICAL !!!\n";
    }
} else {
    $enc_key = constant('LTMS_ENCRYPTION_KEY');
    if ( strlen( $enc_key ) < 32 ) {
        $warnings[] = 'LTMS_ENCRYPTION_KEY is less than 32 characters (recommended: 64+)';
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 4. AUTOLOADER / COMPOSER
// ─────────────────────────────────────────────────────────────────────────────
echo "\n── 4. AUTOLOADER\n";

if ( ! defined('LTMS_PLUGIN_DIR') ) {
    echo "   LTMS_PLUGIN_DIR not defined — cannot check autoloader\n";
} else {
    $vendor_autoload = LTMS_PLUGIN_DIR . 'vendor/autoload.php';
    $vendor_dir      = LTMS_PLUGIN_DIR . 'vendor/';

    if ( file_exists( $vendor_autoload ) ) {
        echo "   vendor/autoload.php : EXISTS — Composer autoloader active\n";
        $ok[] = 'composer_autoloader';

        // Check key Composer packages
        $packages = [
            'stripe/stripe-php'   => LTMS_PLUGIN_DIR . 'vendor/stripe/stripe-php/lib/Stripe.php',
            'dompdf/dompdf'       => LTMS_PLUGIN_DIR . 'vendor/dompdf/dompdf/src/Dompdf.php',
            'firebase/php-jwt'    => LTMS_PLUGIN_DIR . 'vendor/firebase/php-jwt/src/JWT.php',
            'ramsey/uuid'         => LTMS_PLUGIN_DIR . 'vendor/ramsey/uuid/src/Uuid.php',
        ];
        foreach ( $packages as $pkg => $file ) {
            if ( file_exists( $file ) ) {
                echo "   {$pkg}: OK\n";
            } else {
                $warnings[] = "Composer package missing: {$pkg} — run composer install";
                echo "   {$pkg}: MISSING (run composer install) !!!\n";
            }
        }
    } else {
        $errors[] = 'vendor/autoload.php MISSING — Composer not installed. Fallback autoloader will fail for 2-part class names (LTMS_Admin, LTMS_Roles, etc.)';
        echo "   vendor/autoload.php : MISSING — CRITICAL BUG\n";
        echo "   → LTMS_Admin (2 parts) will NEVER load via fallback\n";
        echo "   → LTMS_Roles (2 parts) will NEVER load via fallback\n";
        echo "   → Fix: run 'composer install --no-dev' in plugin directory\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 5. CLASS LOADING TEST
// ─────────────────────────────────────────────────────────────────────────────
echo "\n── 5. CLASS LOADING\n";

$critical_classes = [
    // 2-part names (fail without Composer)
    'LTMS_Admin'          => 'admin menu',
    'LTMS_Roles'          => 'role capabilities',
    // 3-part names
    'LTMS_Core_Kernel'    => 'bootloader',
    'LTMS_Core_Config'    => 'configuration',
    'LTMS_Core_Logger'    => 'logging',
    'LTMS_Core_Security'  => 'security',
    'LTMS_Core_Firewall'  => 'WAF',
    'LTMS_DB_Migrations'  => 'DB migrations',
    // Business
    'LTMS_Business_Wallet'              => 'wallet engine',
    'LTMS_Business_Commission_Strategy' => 'commissions',
    'LTMS_Business_Tax_Engine'          => 'tax engine',
    'LTMS_Order_Paid_Listener'          => 'order hook',
    // Admin
    'LTMS_Admin_Settings'  => 'admin settings',
    'LTMS_Admin_Payouts'   => 'admin payouts',
    // Frontend
    'LTMS_Dashboard_Logic' => 'vendor dashboard',
    // API
    'LTMS_Api_Webhook_Router' => 'webhook router',
    // Gateway
    'LTMS_Gateway_Stripe'     => 'Stripe gateway',
];

$class_failures = [];
foreach ( $critical_classes as $class => $purpose ) {
    if ( class_exists( $class ) ) {
        echo "   {$class}: OK ({$purpose})\n";
        $ok[] = "class_{$class}";
    } else {
        $class_failures[] = $class;
        $errors[] = "Class not loaded: {$class} ({$purpose})";
        echo "   {$class}: NOT LOADED !!!\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 6. ADMINISTRATOR ROLE CAPABILITIES
// ─────────────────────────────────────────────────────────────────────────────
echo "\n── 6. ADMINISTRATOR CAPABILITIES (RBAC)\n";

$admin_role = get_role('administrator');
if ( ! $admin_role ) {
    $errors[] = 'administrator role not found in database';
    echo "   administrator role: NOT FOUND !!!\n";
} else {
    $ltms_caps = [
        'ltms_access_dashboard',     // gates the ENTIRE admin menu
        'ltms_manage_all_vendors',
        'ltms_view_wallet_ledger',
        'ltms_manage_payouts',
        'ltms_manage_commissions',
        'ltms_view_audit_logs',
        'ltms_manage_settings',
    ];

    $missing_caps = [];
    foreach ( $ltms_caps as $cap ) {
        if ( $admin_role->has_cap( $cap ) ) {
            echo "   {$cap}: OK\n";
        } else {
            $missing_caps[] = $cap;
            $errors[] = "administrator missing capability: {$cap}";
            echo "   {$cap}: MISSING !!!\n";
        }
    }

    if ( ! empty( $missing_caps ) ) {
        echo "\n   → ROOT CAUSE: LTMS activation never completed or roles were not installed.\n";
        echo "   → The menu is hidden because 'ltms_access_dashboard' is missing.\n";
        echo "   → Fix: run LTMS_Roles::install() via WP-CLI\n";
    } else {
        $ok[] = 'administrator_caps';
    }
}

// Also check current user
$current_user = wp_get_current_user();
if ( $current_user->ID ) {
    echo "\n   Current user: {$current_user->user_login} (ID:{$current_user->ID})\n";
    echo "   Has ltms_access_dashboard: " . ( user_can($current_user->ID, 'ltms_access_dashboard') ? 'YES' : 'NO' ) . "\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// 7. DATABASE TABLES
// ─────────────────────────────────────────────────────────────────────────────
echo "\n── 7. DATABASE TABLES\n";

global $wpdb;
$prefix = $wpdb->prefix . 'lt_';
echo "   Expected prefix: {$prefix}\n\n";

$expected_tables = [
    'vendors', 'wallets', 'wallet_transactions', 'commissions',
    'referral_tree', 'payouts', 'kyc_documents', 'notifications',
    'audit_logs', 'waf_blocked_ips', 'waf_logs', 'api_logs',
    'coupons', 'consumer_protection', 'job_queue', 'tracking',
    'insurance_policies', 'redi_requests',
];

$missing_tables = [];
foreach ( $expected_tables as $table ) {
    $full = $prefix . $table;
    $exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $full ) );
    if ( $exists ) {
        echo "   {$full}: EXISTS\n";
    } else {
        $missing_tables[] = $full;
        $errors[] = "DB table missing: {$full}";
        echo "   {$full}: MISSING !!!\n";
    }
}

if ( ! empty( $missing_tables ) ) {
    echo "\n   → " . count($missing_tables) . " tables missing.\n";
    echo "   → Fix: run LTMS_DB_Migrations::run() via WP-CLI\n";
} else {
    $ok[] = 'db_tables';
    echo "\n   All " . count($expected_tables) . " tables present.\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// 8. CRON JOBS
// ─────────────────────────────────────────────────────────────────────────────
echo "\n── 8. CRON JOBS\n";

$ltms_crons = [
    'ltms_process_payouts',
    'ltms_sync_siigo',
    'ltms_integrity_check',
    'ltms_clean_logs',
    'ltms_process_job_queue',
    'ltms_send_notifications',
    'ltms_update_tracking',
];

$missing_crons = [];
foreach ( $ltms_crons as $hook ) {
    $next = wp_next_scheduled( $hook );
    if ( $next ) {
        $diff = $next - time();
        echo "   {$hook}: next in " . human_time_diff( time(), $next ) . " (" . date('Y-m-d H:i:s', $next) . ")\n";
    } else {
        $missing_crons[] = $hook;
        $warnings[] = "Cron not scheduled: {$hook}";
        echo "   {$hook}: NOT SCHEDULED\n";
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 9. BOOT SIMULATION
// ─────────────────────────────────────────────────────────────────────────────
echo "\n── 9. KERNEL BOOT STATUS\n";

if ( class_exists('LTMS_Core_Kernel') ) {
    try {
        $kernel = LTMS_Core_Kernel::get_instance();
        echo "   Kernel instance: OK\n";

        // Check if kernel already booted (it should be if plugin loaded normally)
        $ref = new ReflectionClass( $kernel );
        $prop = $ref->getProperty('booted');
        $prop->setAccessible(true);
        $booted = $prop->getValue($kernel);
        echo "   Kernel booted: " . ( $booted ? 'YES' : 'NO (boot() never ran!)' ) . "\n";

        if ( ! $booted ) {
            $errors[] = 'LTMS_Core_Kernel::boot() never completed — plugin did not initialize';
            echo "\n   → Attempting boot() now to capture error...\n";
            try {
                $kernel->boot();
                echo "   → boot() succeeded on retry!\n";
                $warnings[] = 'Kernel only boots on manual retry — check plugins_loaded hook priority';
            } catch ( \Throwable $e ) {
                $err_msg = $e->getMessage();
                $errors[] = "Kernel boot() exception: {$err_msg}";
                echo "   → boot() threw: " . get_class($e) . ": {$err_msg}\n";
                echo "   → File: " . $e->getFile() . " line " . $e->getLine() . "\n";
            }
        }
    } catch ( \Throwable $e ) {
        $errors[] = 'Cannot instantiate LTMS_Core_Kernel: ' . $e->getMessage();
        echo "   Kernel: FAILED — " . $e->getMessage() . "\n";
    }
} else {
    $errors[] = 'LTMS_Core_Kernel class not loaded — autoloader failed';
    echo "   Kernel class: NOT LOADED\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// 10. ADMIN MENU CHECK
// ─────────────────────────────────────────────────────────────────────────────
echo "\n── 10. ADMIN MENU HOOKS\n";

global $menu, $submenu;
$ltms_menu_found = false;
if ( ! empty( $menu ) ) {
    foreach ( $menu as $item ) {
        if ( isset($item[2]) && strpos($item[2], 'ltms') !== false ) {
            $ltms_menu_found = true;
            echo "   Menu found: '{$item[0]}' (slug: {$item[2]}, cap: {$item[1]})\n";
        }
    }
}
if ( ! $ltms_menu_found ) {
    $errors[] = 'No LTMS menu registered in $GLOBALS[menu]';
    echo "   No LTMS menu items registered !!!\n";
}

// Check if admin_menu action ran
$has_admin_menu_hook = has_action( 'admin_menu', [ 'LTMS_Admin', 'register_menus' ] );
echo "   admin_menu hook: " . ( $has_admin_menu_hook ? "registered (priority {$has_admin_menu_hook})" : 'NOT registered' ) . "\n";

// ─────────────────────────────────────────────────────────────────────────────
// 11. RECENT DEBUG LOG TAIL
// ─────────────────────────────────────────────────────────────────────────────
echo "\n── 11. RECENT DEBUG LOG (last 30 LTMS lines)\n";

$log_file = WP_CONTENT_DIR . '/debug.log';
if ( file_exists( $log_file ) ) {
    $lines = file( $log_file );
    $ltms_lines = array_filter( $lines, function( $l ) {
        return stripos( $l, 'LTMS' ) !== false || stripos( $l, 'ltms' ) !== false;
    });
    $ltms_lines = array_slice( $ltms_lines, -30 );
    if ( empty( $ltms_lines ) ) {
        echo "   No LTMS entries in debug.log\n";
    } else {
        foreach ( $ltms_lines as $line ) {
            echo "   " . trim( $line ) . "\n";
        }
    }
} else {
    echo "   debug.log not found at: {$log_file}\n";
}

// ─────────────────────────────────────────────────────────────────────────────
// SUMMARY
// ─────────────────────────────────────────────────────────────────────────────
echo "\n{$sep}\n";
echo " DIAGNOSTIC SUMMARY\n";
echo "{$sep}\n\n";

if ( empty($errors) && empty($warnings) ) {
    echo " STATUS: ALL CHECKS PASSED\n\n";
} else {
    echo " ERRORS (" . count($errors) . "):\n";
    foreach ( $errors as $i => $e ) {
        echo "   [E" . ($i+1) . "] {$e}\n";
    }

    if ( ! empty($warnings) ) {
        echo "\n WARNINGS (" . count($warnings) . "):\n";
        foreach ( $warnings as $i => $w ) {
            echo "   [W" . ($i+1) . "] {$w}\n";
        }
    }
}

echo "\n OK checks: " . count($ok) . "\n";
echo " Errors   : " . count($errors) . "\n";
echo " Warnings : " . count($warnings) . "\n";

// RECOMMENDED FIX SCRIPT
echo "\n{$sep}\n";
echo " RECOMMENDED FIX (run fix-production.sh next)\n";
echo "{$sep}\n";

if ( ! empty($missing_tables) ) {
    echo " 1. Run DB migrations\n";
}
if ( ! empty($missing_caps) ?? false ) {
    echo " 2. Install LTMS role capabilities\n";
}
if ( ! file_exists( LTMS_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
    echo " 3. Run composer install\n";
}
if ( ! empty($missing_crons) ) {
    echo " 4. Reschedule cron jobs\n";
}

echo "\n Run: bash wp-content/plugins/lt-marketplace-suite/bin/fix-production.sh\n\n";
echo "{$sep}\n";
echo " END OF DIAGNOSTIC\n";
echo "{$sep}\n\n";
