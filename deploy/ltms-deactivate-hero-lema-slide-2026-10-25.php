<?php
/**
 * LTMS — Desactivar el banner del hero con el lema del marketplace
 *
 * UIUX-BATCH-2026-10-25c (operador, 3er reporte: "sigue viéndose el lema
 * 'tu marketplace de confianza en colombia'").
 *
 * CAUSA RAÍZ REAL (investigación con evidencia): el lema NO estaba en el
 * código (el bloque de texto HTML del hero y el inyector JS ya se
 * eliminaron en UIUX-BATCH-2026-10-25/25b) — está EN LA IMAGEN del slide
 * #1 del Home Slider: 'WhatsApp-Image-2026-10-15-at-1.31.59-AM.jpeg'
 * (order=1 = banner principal, justo bajo el header; subido el día del
 * brief de branding BRAND-2026-001, 15/10/2026). El texto viaja horneado
 * en el JPEG (title/cta_text vacíos en los datos del slide) — por eso
 * ninguna corrección de código lo hacía desaparecer.
 *
 * Este script DESACTIVA ese slide (active=0 — reversible desde el admin
 * del Home Slider o re-activando con otro run). El hero reorganiza los
 * slides restantes: banner2_Escritorio pasa a principal + banner1 como
 * tarjeta.
 *
 * Uso:
 *   wp eval-file wp-content/plugins/lt-marketplace-suite/deploy/ltms-deactivate-hero-lema-slide-2026-10-25.php --allow-root
 *
 * Idempotente — seguro correr de nuevo: si el slide ya está inactivo,
 * solo reporta.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 'Ejecutar via WP-CLI eval-file.' );
}

echo "=== LTMS — Desactivar banner del hero con el lema (UIUX-BATCH-2026-10-25c) ===\n";

$slides = get_option( 'ltms_home_slides', array() );
if ( ! is_array( $slides ) || ! $slides ) {
    echo "Sin slides en ltms_home_slides — nada que hacer.\n";
    return;
}

$changed = false;
foreach ( $slides as $i => &$s ) {
    $img = basename( (string) ( $s['image_desktop'] ?? '' ) );
    /* Match por nombre de archivo del banner del lema (upload del día del
     * brief de marca). Si el operador lo reemplaza en el futuro por otra
     * imagen SIN el lema, puede re-activar el slide desde el admin. */
    if ( false !== strpos( $img, 'WhatsApp-Image-2026-10-15' ) ) {
        if ( ( $s['active'] ?? '1' ) === '0' ) {
            echo "Slide #$i ($img): ya estaba inactivo — nada que hacer.\n";
        } else {
            $s['active'] = '0';
            $changed = true;
            echo "Slide #$i ($img): DESACTIVADO (era el banner principal con el lema).\n";
        }
    } else {
        echo "Slide #$i ($img): activo (se conserva).\n";
    }
}
unset( $s );

if ( $changed ) {
    update_option( 'ltms_home_slides', $slides );
    echo "Opción ltms_home_slides actualizada.\n";
}

/* Reporte final: cómo queda el hero. */
$active = array();
foreach ( get_option( 'ltms_home_slides', array() ) as $s ) {
    if ( ( $s['active'] ?? '1' ) !== '0' ) {
        $active[] = basename( (string) ( $s['image_desktop'] ?? '' ) );
    }
}
echo "Slides activos tras el cambio: " . count( $active ) . "\n";
foreach ( $active as $a ) { echo "  - $a\n"; }
if ( $active ) {
    echo "Hero resultante: banner principal = {$active[0]}";
    if ( isset( $active[1] ) ) { echo " + tarjeta = {$active[1]}"; }
    echo "\n";
}
echo "=== Fin ===\n";
