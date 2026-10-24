<?php
/**
 * LTMS — Fix títulos duplicados en páginas legales (Términos / Privacidad)
 *
 * LEGAL-TITLES-FIX-2026-10-24: el contenido de las páginas legales creadas
 * por el activator llevaba su PROPIO <h1> ("Términos y Condiciones" /
 * "Política de Privacidad") mientras el tema/Elementor ya renderiza el
 * título de la página (post_title) → el visitante veía el título DOS
 * veces (H1 duplicado, reportado por el operador).
 *
 * Este script quita el <h1> inicial del contenido de:
 *  - Las páginas registradas en ltms_installed_pages (ltms-terms,
 *    ltms-privacy) — las que la home enlaza de verdad (hoy #14524
 *    /terminos-y-condiciones-2/ y #14525 /politica-de-privacidad-2/).
 *  - Las twins por slug (terminos-y-condiciones, politica-de-privacidad)
 *    por si también traen el h1 heredado.
 *
 * La corrección de la fuente (el activator ya no emite <h1>) viaja en el
 * mismo commit — este script solo repara el contenido YA publicado.
 *
 * Uso:
 *   wp --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html \
 *      eval-file wp-content/plugins/lt-marketplace-suite/deploy/ltms-fix-legal-titles-2026-10-24.php
 *
 * Idempotente — seguro de correr varias veces: solo quita el <h1> si el
 * contenido COMIENZA con uno; el resto del contenido no se toca.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 'Ejecutar via WP-CLI eval-file.' );
}

echo "=== LTMS — Fix títulos duplicados páginas legales (2026-10-24) ===\n";

/**
 * Quita el <h1> inicial del post_content de una página si existe.
 *
 * @param int    $page_id ID de la página.
 * @param string $label   Etiqueta para el log.
 * @return void
 */
function ltms_strip_leading_h1( int $page_id, string $label ): void {
    $post = get_post( $page_id );
    if ( ! $post || 'page' !== $post->post_type ) {
        echo "SKIP — {$label}: página #{$page_id} no encontrada.\n";
        return;
    }
    $content = (string) $post->post_content;
    $trimmed = ltrim( $content );
    if ( 0 !== stripos( $trimmed, '<h1' ) ) {
        echo "OK — {$label}: sin <h1> inicial (nada que hacer) /" . $post->post_name . "/\n";
        return;
    }
    // Quitar SOLO el primer <h1>...</h1> (con el whitespace que lo sigue).
    $fixed = preg_replace( '/^\s*<h1[^>]*>.*?<\/h1>\s*/is', '', $content, 1 );
    if ( null === $fixed || $fixed === $content ) {
        echo "SKIP — {$label}: el patrón no matcheó (contenido ya corregido o inesperado).\n";
        return;
    }
    $result = wp_update_post( array( 'ID' => $page_id, 'post_content' => $fixed ), true );
    if ( is_wp_error( $result ) ) {
        echo "ERROR — {$label}: " . $result->get_error_message() . "\n";
        return;
    }
    echo "FIXED — {$label}: <h1> inicial eliminado /{$post->post_name}/ (nuevo inicio: '" . substr( trim( preg_replace( '/\s+/', ' ', $fixed ) ), 0, 60 ) . "...')\n";
}

/* 1. Las páginas que la home enlaza (fuente de verdad: ltms_installed_pages). */
$installed = get_option( 'ltms_installed_pages', array() );
foreach ( array( 'ltms-terms' => 'Términos (installed)', 'ltms-privacy' => 'Privacidad (installed)' ) as $key => $label ) {
    $id = ( is_array( $installed ) && ! empty( $installed[ $key ] ) ) ? (int) $installed[ $key ] : 0;
    if ( $id ) {
        ltms_strip_leading_h1( $id, $label );
    } else {
        echo "SKIP — {$label}: sin ID registrado en ltms_installed_pages.\n";
    }
}

/* 2. Twins por slug (por si heredaron el mismo contenido con <h1>). */
foreach ( array( 'terminos-y-condiciones' => 'Términos (slug twin)', 'politica-de-privacidad' => 'Privacidad (slug twin)' ) as $slug => $label ) {
    $twin = get_page_by_path( $slug, OBJECT, 'page' );
    if ( $twin ) {
        ltms_strip_leading_h1( (int) $twin->ID, $label );
    } else {
        echo "SKIP — {$label}: no existe twin /{$slug}/.\n";
    }
}

echo "=== Fin ===\n";
