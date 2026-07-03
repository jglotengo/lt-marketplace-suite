<?php
/**
 * LTMS Emergency Data Patch — Fix Flexible policy partial-refund window (M-QA-02)
 *
 * Repara las políticas "Flexible" existentes que quedaron creadas con
 * partial_refund_hours (48) MAYOR que free_cancel_hours (24) — orden
 * invertido que dejaba la rama de reembolso parcial de
 * calculate_refund_amount() matemáticamente inalcanzable (ver commit
 * 5dca827 en class-ltms-booking-policy-handler.php para el fix del seed
 * que afecta a vendedores NUEVOS; este script repara los vendedores que
 * YA tenían la política Flexible creada con los valores rotos).
 *
 * Acotado estrictamente a filas que coinciden con el patrón exacto del
 * bug (policy_type = 'flexible' AND partial_refund_hours > free_cancel_hours)
 * — no toca ninguna otra política, incluida "Moderada", que ya funciona
 * correctamente.
 *
 * Idempotente — seguro de correr varias veces (la segunda vez no encuentra
 * filas que coincidan con la condición y no hace nada).
 *
 * Uso:
 *   wp --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html \
 *      eval-file deploy/ltms-patch-flexible-refund-2026-06-23.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 'Ejecutar via WP-CLI eval-file.' );
}

global $wpdb;
$table = $wpdb->prefix . 'lt_booking_policies';

echo "=== LTMS Patch: Flexible policy partial-refund window ===\n";

// 1. Mostrar las filas que se van a tocar, ANTES de tocarlas.
$affected = $wpdb->get_results(
    "SELECT id, vendor_id, name, free_cancel_hours, partial_refund_hours, partial_refund_pct
       FROM `{$table}`
      WHERE policy_type = 'flexible'
        AND partial_refund_hours > free_cancel_hours"
);

if ( empty( $affected ) ) {
    echo "OK — no hay políticas Flexible con el patrón roto. Nada que hacer.\n";
} else {
    echo 'Filas afectadas: ' . count( $affected ) . "\n";
    foreach ( $affected as $row ) {
        echo "  id={$row->id} vendor_id={$row->vendor_id} free={$row->free_cancel_hours} "
           . "partial_hours={$row->partial_refund_hours} partial_pct={$row->partial_refund_pct}\n";
    }

    // 2. Reparar: sin ventana de reembolso parcial, igual que el seed
    //    corregido (Airbnb Flexible: 100% hasta free_cancel_hours, 0% después).
    $result = $wpdb->query(
        "UPDATE `{$table}`
            SET partial_refund_pct = 0,
                partial_refund_hours = 0,
                updated_at = NOW()
          WHERE policy_type = 'flexible'
            AND partial_refund_hours > free_cancel_hours"
    );

    if ( false === $result ) {
        echo 'ERROR: ' . $wpdb->last_error . "\n";
    } else {
        echo "OK — {$result} fila(s) reparada(s): partial_refund_pct=0, partial_refund_hours=0\n";
    }
}

// 3. Verificación post-patch.
$remaining = $wpdb->get_var(
    "SELECT COUNT(*) FROM `{$table}` WHERE policy_type = 'flexible' AND partial_refund_hours > free_cancel_hours"
);
echo "Verificación: {$remaining} fila(s) restantes con el patrón roto (debe ser 0).\n";
echo "=== Patch completado ===\n";
