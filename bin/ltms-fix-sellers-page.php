<?php
/**
 * Diagnose and fix the /sellers/ page — ensure it has [ltms_sellers_landing] shortcode.
 * Run: wp --path=/path/to/wp eval-file bin/ltms-fix-sellers-page.php --allow-root
 */
if ( ! defined( 'ABSPATH' ) ) { die; }

// Flush OPcache first
if ( function_exists( 'opcache_reset' ) ) { opcache_reset(); }
$utils_file = LTMS_PLUGIN_DIR . 'includes/core/utils/class-ltms-utils.php';
if ( function_exists( 'opcache_invalidate' ) ) { opcache_invalidate( $utils_file, true ); }

echo "=== LTMS Sellers Page Fix ===\n\n";

// 1. Find the sellers page
$sellers_page = null;

// Try by slug 'sellers'
$by_slug = get_page_by_path( 'sellers' );
if ( $by_slug ) {
    $sellers_page = $by_slug;
    echo "[INFO] Página encontrada por slug 'sellers': ID={$sellers_page->ID}\n";
}

// Try by path stored in options
if ( ! $sellers_page ) {
    $installed = get_option( 'ltms_installed_pages', [] );
    if ( isset( $installed['ltms-sellers'] ) ) {
        $sellers_page = get_post( $installed['ltms-sellers'] );
        echo "[INFO] Página encontrada en ltms_installed_pages: ID={$sellers_page->ID}\n";
    }
}

if ( ! $sellers_page ) {
    echo "[WARN] No se encontró la página /sellers/. Creándola...\n";
    $page_id = wp_insert_post([
        'post_title'   => 'Sellers',
        'post_name'    => 'sellers',
        'post_content' => '[ltms_sellers_landing]',
        'post_status'  => 'publish',
        'post_type'    => 'page',
    ]);
    if ( $page_id && ! is_wp_error( $page_id ) ) {
        echo "[OK] Página creada con ID=$page_id\n";
        $sellers_page = get_post( $page_id );
    } else {
        echo "[ERROR] No se pudo crear la página\n";
        exit;
    }
}

// 2. Check if shortcode is in content
$content = $sellers_page->post_content;
echo "[INFO] Contenido actual: " . ( $content ? substr( $content, 0, 100 ) : '(vacío)' ) . "\n";

if ( strpos( $content, 'ltms_sellers_landing' ) === false ) {
    echo "[FIX] Agregando shortcode [ltms_sellers_landing]...\n";
    wp_update_post([
        'ID'           => $sellers_page->ID,
        'post_content' => '[ltms_sellers_landing]',
        'post_status'  => 'publish',
    ]);
    echo "[OK] Shortcode agregado. URL: " . get_permalink( $sellers_page->ID ) . "\n";
} else {
    echo "[OK] Shortcode ya presente en la página.\n";
    echo "[DIAG] El problema puede ser que el shortcode no está registrado.\n";
    echo "[DIAG] Shortcode ltms_sellers_landing registrado: " . 
         ( shortcode_exists( 'ltms_sellers_landing' ) ? 'SÍ' : 'NO' ) . "\n";
}

// 3. Check page template
$template = get_post_meta( $sellers_page->ID, '_wp_page_template', true );
echo "[INFO] Template: " . ( $template ?: 'default' ) . "\n";

// 4. Verify shortcode registration
echo "\n[DIAG] Shortcodes LTMS registrados:\n";
$ltms_shortcodes = [ 'ltms_sellers_landing', 'ltms_vendor_dashboard', 'ltms_vendor_login', 'ltms_vendor_register' ];
foreach ( $ltms_shortcodes as $sc ) {
    echo "  " . ( shortcode_exists( $sc ) ? '✓' : '✗' ) . " [$sc]\n";
}

echo "\n[DONE]\n";
