<?php
/**
 * LTMS — Backfill de columnas fiscales por-impuesto en lt_commissions
 *
 * TRIB-BACKFILL-2026-11-26 (ciclo TRIB-2026-11-26, auditoría tributaria MX/CO).
 *
 * CONTEXTO: antes del fix TRIB-003, el commission-writer fabricaba cifras
 * fiscales propias (CO IVA 19% fijo, ReteIVA incondicional, ISR MX 1% fijo)
 * y las escribía en lt_commissions.reteiva_amount / isr_amount, mientras el
 * Order Split calculaba el desglose canónico con LTMS_Tax_Engine (y antes de
 * TRIB-001, SIEMPRE como 'physical'). Los reportes SAT Art. 30-B CFF (MX) y
 * DIAN (CO) consumen esas columnas → filas históricas con desglose incorrecto.
 *
 * QUÉ HACE: para cada fila de lt_commissions con order_id, recalcula el
 * desglose fiscal con el engine (mismo código que el split usa en producción:
 * LTMS_Business_Order_Split::get_vendor_data + product type real del pedido)
 * y alinea SOLO las columnas del desglose fiscal:
 *   iva_amount, retefuente_amount/rate, reteiva_amount/rate,
 *   reteica_amount/rate, isr_amount/rate, ieps_amount, impoconsumo_amount.
 *
 * QUÉ NO TOCA (dinero ya liquidado a wallets — no reescribir historia de pagos):
 *   tax_withholding, vendor_amount, commission_amount/rate, gross_amount.
 * El desglose fiscal y la liquidación de wallet pueden divergir en filas
 * históricas: la liquidación refleja lo que REALMENTE se pagó; el desglose,
 * lo que el engine determina que correspondía. Este script corrige lo segundo.
 *
 * PRE-REQUISITO OBLIGATORIO (backup de la BD):
 *   cd /home/customer/www/lo-tengo.com.co/public_html
 *   mkdir -p ~/backups && wp db export - --allow-root | gzip > ~/backups/ltms-db-backup-$(date +%Y%m%d-%H%M).sql.gz
 *   ls -la ~/backups/   # VERIFICAR que el .gz existe y pesa >5MB antes de seguir
 *
 * Uso:
 *   # 1) Dry-run (no escribe nada, solo reporta):
 *   LTMS_BACKFILL_DRY_RUN=1 wp eval-file wp-content/plugins/lt-marketplace-suite/deploy/ltms-backfill-fiscal-tax-columns-2026-11-26.php --allow-root
 *   # 2) Ejecución real:
 *   wp eval-file wp-content/plugins/lt-marketplace-suite/deploy/ltms-backfill-fiscal-tax-columns-2026-11-26.php --allow-root
 *
 * Idempotente — seguro correr de nuevo: filas ya alineadas se saltan
 * (tolerancia 0.001 en montos, 0.0001 en tasas).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 'Ejecutar via WP-CLI eval-file.' );
}

/**
 * TRIB-BACKFILL-2026-11-26: núcleo testeable del backfill.
 *
 * El cómputo por fila está aislado en compute_per_tax_update() (puro, sin
 * wpdb) para poder verificarlo en PHPUnit sin WP/DB — el runner procedural
 * de abajo solo orquesta lecturas/escrituras y logging.
 */
final class LTMS_Backfill_Fiscal_Tax_20261126 {

    /** Tolerancia de comparación (floats desde DB string). */
    private const TOL_AMOUNT = 0.001;
    private const TOL_RATE   = 0.0001;

    /**
     * Calcula las columnas del desglose fiscal para una fila, con el engine.
     *
     * @param float  $gross        gross_amount de la fila (se respeta: no se recalcula).
     * @param string $product_type Tipo LTMS normalizado del pedido.
     * @param array  $vendor_data  Datos del vendor (mismo formato que Order_Split::get_vendor_data).
     * @param array  $order_data   Contexto del pedido (buyer_is_gran_contribuyente, municipality_code, is_booking...).
     * @param string $country      Código de país ('CO'/'MX').
     * @return array<string,float> columnas => valores (solo desglose fiscal).
     */
    public static function compute_per_tax_update( float $gross, string $product_type, array $vendor_data, array $order_data, string $country ): array {
        $order_data = array_merge( [
            'product_type' => $product_type,
            'is_booking'   => ( 'booking' === $product_type ),
        ], $order_data );

        // Excepción esperada solo con país no soportado — el runner la captura.
        $breakdown = LTMS_Tax_Engine::calculate( $gross, $order_data, $vendor_data, $country );

        return [
            'iva_amount'         => (float) ( $breakdown['iva'] ?? 0.0 ),
            'retefuente_amount'  => (float) ( $breakdown['retefuente'] ?? 0.0 ),
            'retefuente_rate'    => (float) ( $breakdown['retefuente_rate'] ?? 0.0 ),
            'reteiva_amount'     => (float) ( $breakdown['reteiva'] ?? 0.0 ),
            'reteiva_rate'       => (float) ( $breakdown['reteiva_rate'] ?? 0.0 ),
            'reteica_amount'     => (float) ( $breakdown['reteica'] ?? 0.0 ),
            'reteica_rate'       => (float) ( $breakdown['reteica_rate'] ?? 0.0 ),
            'isr_amount'         => (float) ( $breakdown['isr'] ?? 0.0 ),
            'isr_rate'           => (float) ( $breakdown['isr_rate'] ?? 0.0 ),
            'ieps_amount'        => (float) ( $breakdown['ieps'] ?? 0.0 ),
            'impoconsumo_amount' => (float) ( $breakdown['impoconsumo'] ?? 0.0 ),
        ];
    }

    /**
     * Compara las columnas actuales de la fila contra el cómputo del engine.
     *
     * @param object $row      Fila cruda de lt_commissions (stdClass con propiedades numéricas).
     * @param array  $computed Resultado de compute_per_tax_update().
     * @return bool true si ya está alineada (skip).
     */
    public static function row_is_aligned( $row, array $computed ): bool {
        foreach ( $computed as $col => $value ) {
            $tol = str_ends_with( $col, '_rate' ) ? self::TOL_RATE : self::TOL_AMOUNT;
            $current = (float) ( $row->$col ?? 0.0 );
            if ( abs( $current - $value ) >= $tol ) {
                return false;
            }
        }
        return true;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Runner — SOLO bajo WP-CLI real (nunca en autoload del plugin ni en tests).
// ─────────────────────────────────────────────────────────────────────────────
if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'LTMS_Tax_Engine' ) ) {

    global $wpdb;

    $dry_run = ( '1' === getenv( 'LTMS_BACKFILL_DRY_RUN' ) );

    echo "=== LTMS - TRIB-BACKFILL-2026-11-26 (columnas fiscales por-impuesto) ===\n";
    echo $dry_run ? "MODO: DRY-RUN (sin escrituras)\n" : "MODO: EJECUCION REAL\n";

    // Defensa: el split y el engine deben estar cargados (plugin activo).
    if ( ! class_exists( 'LTMS_Business_Order_Split' ) ) {
        $split_file = WP_PLUGIN_DIR . '/lt-marketplace-suite/includes/business/class-ltms-order-split.php';
        if ( file_exists( $split_file ) ) {
            require_once $split_file;
        }
    }
    if ( ! class_exists( 'LTMS_Business_Order_Split' ) || ! class_exists( 'LTMS_Tax_Engine' ) ) {
        exit( "FATAL: LTMS_Business_Order_Split o LTMS_Tax_Engine no disponibles — plugin activo?\n" );
    }

    $country = LTMS_Core_Config::get_country();
    echo "Pais fiscal: {$country}\n";

    $rows = $wpdb->get_results(
        "SELECT id, order_id, vendor_id, gross_amount, iva_amount,
                retefuente_amount, retefuente_rate, reteiva_amount, reteiva_rate,
                reteica_amount, reteica_rate, isr_amount, isr_rate,
                ieps_amount, impoconsumo_amount
         FROM {$wpdb->prefix}lt_commissions
         WHERE order_id > 0
         ORDER BY id ASC",
        'OBJECT'
    );

    $total     = is_array( $rows ) ? count( $rows ) : 0;
    $updated   = 0;
    $skipped   = 0;
    $errors    = 0;
    $row_num   = 0;

    echo "Filas a procesar: {$total}\n";

    foreach ( (array) $rows as $row ) {
        $row_num++;

        $order = wc_get_order( (int) $row->order_id );
        if ( ! $order ) {
            $errors++;
            echo "  [{$row_num}/{$total}] comision #{$row->id}: pedido #{$row->order_id} inexistente — SKIP (error)\n";
            continue;
        }

        // Tipo LTMS real: mismo orden de prioridad que el split (order meta
        // override > post meta del primer producto > 'physical').
        $first_product_id = 0;
        foreach ( $order->get_items() as $item ) {
            $first_product_id = (int) $item->get_product_id();
            break;
        }
        $product_type = LTMS_Business_Order_Split::normalize_ltms_product_type(
            (string) $order->get_meta( '_ltms_product_type' )
            ?: (string) get_post_meta( $first_product_id, '_ltms_product_type', true )
        );

        // Mismos datos de vendor que usa el split en producción (get_vendor_data
        // es pública desde TRIB-BACKFILL: régimen, CIIU, municipio DANE, ingreso).
        $vendor_data = LTMS_Business_Order_Split::get_vendor_data( (int) $row->vendor_id );

        $order_data = [
            'order_id'                    => (int) $row->order_id,
            'buyer_type'                  => $order->get_meta( '_ltms_buyer_type' ) ?: 'person',
            'buyer_regime'                => $order->get_meta( '_ltms_buyer_regime' ) ?: 'persona_natural',
            'buyer_is_gran_contribuyente' => (bool) $order->get_meta( '_ltms_buyer_is_gran_contribuyente' ),
            'municipality'                => $order->get_billing_city(),
            'buyer_municipality_code'     => (string) $order->get_meta( '_ltms_billing_municipality_code' ),
            'items'                       => [],
        ];

        try {
            $computed = LTMS_Backfill_Fiscal_Tax_20261126::compute_per_tax_update(
                (float) $row->gross_amount,
                $product_type,
                $vendor_data,
                $order_data,
                $country
            );
        } catch ( \Throwable $e ) {
            $errors++;
            echo "  [{$row_num}/{$total}] comision #{$row->id}: engine fallo ({$e->getMessage()}) — SKIP (error)\n";
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::error(
                    'TRIB_BACKFILL_ROW_FAILED',
                    sprintf( 'Comision #%d pedido #%d: %s', $row->id, $row->order_id, $e->getMessage() ),
                    [ 'commission_id' => $row->id, 'order_id' => $row->order_id ]
                );
            }
            continue;
        }

        if ( LTMS_Backfill_Fiscal_Tax_20261126::row_is_aligned( $row, $computed ) ) {
            $skipped++;
            continue;
        }

        $diff = [];
        foreach ( $computed as $col => $val ) {
            $old = (float) ( $row->$col ?? 0.0 );
            if ( abs( $old - $val ) >= ( str_ends_with( $col, '_rate' ) ? 0.0001 : 0.001 ) ) {
                $diff[] = sprintf( '%s: %s -> %s', $col, number_format( $old, 2, '.', '' ), number_format( $val, 2, '.', '' ) );
            }
        }

        if ( $dry_run ) {
            $updated++;
            echo "  [{$row_num}/{$total}] comision #{$row->id} (pedido #{$row->order_id}, tipo={$product_type}): " . implode( '; ', $diff ) . "\n";
            continue;
        }

        $result = $wpdb->update(
            "{$wpdb->prefix}lt_commissions",
            $computed,
            [ 'id' => (int) $row->id ],
            array_fill( 0, count( $computed ), '%f' ),
            [ '%d' ]
        );

        if ( false === $result ) {
            $errors++;
            echo "  [{$row_num}/{$total}] comision #{$row->id}: UPDATE fallo ({$wpdb->last_error}) — error\n";
            continue;
        }

        $updated++;
        echo "  [{$row_num}/{$total}] comision #{$row->id} (tipo={$product_type}): " . implode( '; ', $diff ) . "\n";
    }

    echo "\n=== RESUMEN ===\n";
    echo "Filas procesadas: {$total}\n";
    echo "Alineadas (UPDATE" . ( $dry_run ? ' proyectado' : '' ) . "): {$updated}\n";
    echo "Ya consistentes (skip): {$skipped}\n";
    echo "Errores: {$errors}\n";
    echo $dry_run ? "DRY-RUN — no se escribió nada. Correr sin LTMS_BACKFILL_DRY_RUN para ejecutar.\n" : "Backfill completado.\n";

    if ( class_exists( 'LTMS_Core_Logger' ) ) {
        LTMS_Core_Logger::info(
            'TRIB_BACKFILL_COMPLETED',
            sprintf(
                'Backfill fiscal lt_commissions (dry_run=%s): %d filas, %d actualizadas, %d skip, %d errores.',
                $dry_run ? 'yes' : 'no',
                $total,
                $updated,
                $skipped,
                $errors
            ),
            [ 'total' => $total, 'updated' => $updated, 'skipped' => $skipped, 'errors' => $errors, 'dry_run' => $dry_run ]
        );
    }
}
