#!/usr/bin/env php
<?php
/**
 * Version Bump Script — LTMS v2.0.0
 *
 * Uso: php bin/version-bump.php <nueva-versión>
 * Ejemplo: php bin/version-bump.php 2.1.0
 */

if ( $argc < 2 ) {
    fwrite( STDERR, "Uso: php bin/version-bump.php <nueva-versión>\n" );
    exit( 1 );
}

$new_version = trim( $argv[1] );
if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $new_version ) ) {
    fwrite( STDERR, "Versión inválida. Debe ser X.Y.Z\n" );
    exit( 1 );
}

$root = dirname( __DIR__ );

// ── lt-marketplace-suite.php ───────────────────────────────────────────────
$main_file = $root . '/lt-marketplace-suite.php';
$content = file_get_contents( $main_file );
$content = preg_replace( '/(\* Version:\s*)[\d.]+/', '${1}' . $new_version, $content );
$content = preg_replace( '/(@version\s*)[\d.]+/', '${1}' . $new_version, $content );
$content = preg_replace( "/(define\s*\(\s*'LTMS_VERSION'\s*,\s*')[^']+(')/", '${1}' . $new_version . '${2}', $content );
$content = preg_replace( "/(define\s*\(\s*'LTMS_DB_VERSION'\s*,\s*')[^']+(')/", '${1}' . $new_version . '${2}', $content );
file_put_contents( $main_file, $content );
echo "Updated: $main_file\n";

// ── composer.json ─────────────────────────────────────────────────────────
$composer_file = $root . '/composer.json';
if ( file_exists( $composer_file ) ) {
    $composer = json_decode( file_get_contents( $composer_file ), true );
    $composer['version'] = $new_version;
    file_put_contents( $composer_file, json_encode( $composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
    echo "Updated: $composer_file\n";
}

// ── CHANGELOG.md — prepend entry ──────────────────────────────────────────
$changelog_file = $root . '/CHANGELOG.md';
$date = date( 'Y-m-d' );
$entry = "## [{$new_version}] — {$date}\n\n### Added\n- \n\n### Fixed\n- \n\n### Changed\n- \n\n";

if ( file_exists( $changelog_file ) ) {
    $existing = file_get_contents( $changelog_file );
    // Insert after first line (# Changelog header).
    $lines = explode( "\n", $existing, 3 );
    $new_content = ( $lines[0] ?? '' ) . "\n\n" . $entry . ( $lines[2] ?? '' );
    file_put_contents( $changelog_file, $new_content );
} else {
    file_put_contents( $changelog_file, "# Changelog\n\n" . $entry );
}
echo "Updated: $changelog_file\n";

echo "\nVersion bumped to {$new_version}. Review CHANGELOG.md and commit.\n";
