<?php
/**
 * LTMS Shipping Cost Ledger & Reconciliation Engine.
 *
 * Motor central para registro, conciliación y control de costos logísticos
 * absorbidos por la plataforma (Lo Tengo) o debitados al vendedor.
 *
 * Cierra las brechas críticas identificadas en el análisis financiero:
 *  B-01: FOUNDATION_VENDOR_ID bug (constante de clase, no global)
 *  B-02: Sin tabla dedicada para ledger logístico
 *  B-03: Multi-vendor: debito al vendor equivocado
 *  B-04: Sin captura del costo REAL del carrier (solo cotización)
 *  B-05: Sin import de facturas del carrier
 *  B-06: Sin presupuestos por vendedor
 *  B-07: Sin verificación pre-checkout
 *  B-08: Sin workflow de disputas
 *  B-09: Sin alertas
 *  B-10: Sin estado de cuenta vendor
 *  B-11: Sin idempotency key en foundation ledger
 *  B-12: Sin tracking de costo real vs cotización por carrier
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    2.8.3
 * @since      2.8.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Shipping_Cost_Ledger
 *
 * API pública:
 *  - record_shipping_entry()          — Crea/actualiza un entry del ledger al pagar/cotizar.
 *  - mark_shipped()                   — Carrier generó la guía.
 *  - mark_delivered()                 — Carrier entregó.
 *  - set_real_cost_from_invoice_line() — Conciliación: actualiza real_cost + varianza.
 *  - get_vendor_monthly_spend()       — Gasto mensual acumulado del vendor.
 *  - check_vendor_budget()            — Verifica presupuesto antes de permitir envío absorbed.
 *  - get_kpis()                       — KPIs agregados para el dashboard admin.
 *  - open_dispute() / resolve_dispute()
 *  - import_carrier_invoice()         — Parser CSV/JSON + auto-match.
 *
 * Tablas:
 *  - lt_shipping_cost_ledger       (1 fila = 1 servicio logístico)
 *  - lt_carrier_invoices           (facturas mensuales carrier)
 *  - lt_carrier_invoice_lines      (líneas de factura = guías)
 *  - lt_shipping_disputes          (disputas con carrier)
 *  - lt_vendor_shipping_budgets    (presupuesto mensual vendor)
 */
class LTMS_Shipping_Cost_Ledger {

    // ── Status del ledger entry ──────────────────────────────────────────
    const STATUS_QUOTED      = 'quoted';      // Solo cotización registrada (pre-pago).
    const STATUS_SHIPPED     = 'shipped';     // Carrier generó la guía.
    const STATUS_DELIVERED   = 'delivered';   // Carrier entregó.
    const STATUS_INVOICED    = 'invoiced';    // Carrier facturó (real_cost conocido).
    const STATUS_DISPUTED    = 'disputed';    // Disputa abierta con carrier.
    const STATUS_RECONCILED  = 'reconciled';  // Conciliación cerrada.
    const STATUS_WRITEOFF    = 'writeoff';    // Pérdida asumida (sin recuperar).

    // ── Carriers soportados ──────────────────────────────────────────────
    const CARRIER_DEPRISA      = 'deprisa';
    const CARRIER_HEKA         = 'heka';
    const CARRIER_AVEONLINE    = 'aveonline';
    const CARRIER_UBER         = 'uber';
    const CARRIER_PICKUP       = 'pickup';
    const CARRIER_OWN_DELIVERY = 'own_delivery';
    const CARRIER_FREE_ABSORBED = 'free_absorbed';

    /**
     * Inicialización: hooks de captura desde webhooks y cron de alertas.
     */
    public static function init(): void {
        // Captura costo REAL desde webhooks de carriers cuando entregan.
        add_action( 'ltms_shipping_delivered', [ __CLASS__, 'on_carrier_delivered' ], 5, 2 );
        add_action( 'ltms_shipping_failed',    [ __CLASS__, 'on_carrier_failed' ],    5, 2 );

        // Hook de creación de envío: marca el entry como 'shipped' con tracking_number.
        add_action( 'ltms_shipment_created', [ __CLASS__, 'on_shipment_created' ], 10, 3 );

        // Verificación pre-checkout: bloquea envíos absorbed si el vendor supera su presupuesto.
        add_filter( 'woocommerce_package_rates', [ __CLASS__, 'maybe_block_absorbed_for_over_budget_vendor' ], 99, 2 );

        // Cron diario de alertas presupuestales y SLA de disputas.
        add_action( 'ltms_shipping_ledger_daily_alerts', [ __CLASS__, 'run_daily_alerts' ] );
        if ( ! wp_next_scheduled( 'ltms_shipping_ledger_daily_alerts' ) ) {
            wp_schedule_event( time() + 600, 'daily', 'ltms_shipping_ledger_daily_alerts' );
        }
    }

    // =====================================================================
    // 1. CAPTURA — Crear/actualizar entries del ledger
    // =====================================================================

    /**
     * Registra (o actualiza) un entry del ledger cuando se paga un pedido.
     *
     * Reemplaza a LTMS_Order_Paid_Listener::record_carrier_shipping_cost().
     * A diferencia del método anterior:
     *  - Usa la tabla dedicada (no solo wallet_transactions).
     *  - Soporta multi-vendor (1 entry por shipping line item).
     *  - Aplica idempotency_key (re-ejecuciones no duplican).
     *  - Usa correctamente FOUNDATION_VENDOR_ID como class constant.
     *
     * @param \WC_Order $order
     * @return int[] IDs de los entries del ledger creados/actualizados.
     */
    public static function record_shipping_entry( \WC_Order $order ): array {
        global $wpdb;
        $table   = $wpdb->prefix . 'lt_shipping_cost_ledger';
        $entries = [];

        try {
            $order_id          = (int) $order->get_id();
            $absorbed_cost     = (float) $order->get_meta( '_ltms_absorbed_shipping_cost' );
            $absorbed_provider = (string) $order->get_meta( '_ltms_absorbed_shipping_provider' );
            $buyer_paid        = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
            $currency          = $order->get_currency() ?: LTMS_Core_Config::get_currency();
            $country           = LTMS_Core_Config::get_country();

            // v2.8.4: Detectar modo SHARED — el vendor absorbe una parte (vendor_pays).
            $shared_vendor_pays = (float) $order->get_meta( '_ltms_shared_vendor_pays' );
            $shared_total_cost  = (float) $order->get_meta( '_ltms_shared_shipping_cost' );
            $shared_provider    = (string) $order->get_meta( '_ltms_shared_provider' );
            $is_shared_mode     = ( $shared_vendor_pays > 0 || $shared_total_cost > 0 );

            // En modo SHARED, el "absorbed_cost" para el vendor es lo que él absorbe,
            // no el costo total. El buyer_paid ya está correctamente en shipping_total.
            if ( $is_shared_mode && $shared_vendor_pays > 0 ) {
                $absorbed_cost     = $shared_vendor_pays;
                $absorbed_provider = $shared_provider ?: 'shared';
            }

            // Resolver el vendor_id principal del pedido (single-vendor fallback).
            $primary_vendor_id = (int) $order->get_meta( '_ltms_vendor_id' );

            $shipping_methods = $order->get_shipping_methods();

            // CASO A: Sin shipping methods explícitos (algunos gateways no los crean).
            // Crear UN entry con el vendor_id del pedido.
            if ( empty( $shipping_methods ) ) {
                $carrier = self::resolve_carrier_from_order( $order, $absorbed_provider );
                $entry_id = self::upsert_entry( [
                    'order_id'        => $order_id,
                    'order_item_id'   => null,
                    'vendor_id'       => $primary_vendor_id,
                    'carrier'         => $carrier,
                    'tracking_number' => null,
                    'quote_cost'      => $absorbed_cost > 0 ? $absorbed_cost : $buyer_paid,
                    'buyer_paid'      => $buyer_paid,
                    'vendor_charged'  => $absorbed_cost,
                    'currency'        => $currency,
                    'country_code'    => $country,
                    'status'          => self::STATUS_QUOTED,
                    'quote_at'        => current_time( 'mysql', true ),
                ] );
                if ( $entry_id ) {
                    $entries[] = $entry_id;
                }
                self::sync_legacy_order_meta( $order, $carrier, $absorbed_cost, $buyer_paid, $primary_vendor_id );
                return $entries;
            }

            // CASO B: Multi-vendor — un entry por shipping line.
            // Cada shipping line tiene su propio vendor_id y su propio carrier.
            foreach ( $shipping_methods as $item_id => $method ) {
                $method_id = $method->get_method_id();
                $carrier   = self::normalize_carrier_id( $method_id, $absorbed_provider );

                // Resolver el vendor_id de ESTA línea específica.
                $line_vendor_id = (int) $method->get_meta( '_ltms_vendor_id' );
                if ( ! $line_vendor_id ) {
                    // Fallback: derivar del primer item del paquete (post_author del producto).
                    $line_vendor_id = self::resolve_vendor_from_shipping_line( $order, $method );
                }
                if ( ! $line_vendor_id ) {
                    $line_vendor_id = $primary_vendor_id;
                }

                // Calcular costos para esta línea.
                $line_total = (float) $method->get_total();
                $line_tax   = (float) $method->get_total_tax();
                $line_buyer_paid = $line_total + $line_tax;

                // En modo absorbed, el costo cotizado se reparte entre líneas proporcionalmente
                // al costo que cada línea tendría si se cotizara por separado. Como el sistema
                // actual guarda UN solo absorbed_cost, lo asignamos a la primera línea y dejamos
                // las demás en 0. En el futuro se puede mejorar cotizando por línea.
                $line_absorbed = 0.0;
                if ( $absorbed_cost > 0 ) {
                    if ( count( $shipping_methods ) === 1 ) {
                        $line_absorbed = $absorbed_cost;
                    } else {
                        // Reparto proporcional al shipping_total de cada línea.
                        $total_shipping = (float) $order->get_shipping_total();
                        if ( $total_shipping > 0 ) {
                            $line_absorbed = round( $absorbed_cost * ( $line_total / $total_shipping ), 2 );
                        } else {
                            $line_absorbed = round( $absorbed_cost / count( $shipping_methods ), 2 );
                        }
                    }
                }

                $quote_cost = $line_absorbed > 0 ? $line_absorbed : $line_buyer_paid;

                // v2.8.4: En modo SHARED, el quote_cost es el costo TOTAL cotizado
                // (no solo lo que absorbe el vendor), para conciliación correcta.
                if ( $is_shared_mode && $shared_total_cost > 0 ) {
                    if ( count( $shipping_methods ) === 1 ) {
                        $quote_cost = $shared_total_cost;
                    } else {
                        // Reparto proporcional (similar al absorbed).
                        $total_shipping = (float) $order->get_shipping_total();
                        if ( $total_shipping > 0 ) {
                            $quote_cost = round( $shared_total_cost * ( $line_total / $total_shipping ), 2 );
                        } else {
                            $quote_cost = round( $shared_total_cost / count( $shipping_methods ), 2 );
                        }
                    }
                }

                $entry_id = self::upsert_entry( [
                    'order_id'        => $order_id,
                    'order_item_id'   => (int) $item_id,
                    'vendor_id'       => $line_vendor_id,
                    'carrier'         => $carrier,
                    'tracking_number' => null,
                    'quote_cost'      => $quote_cost,
                    'buyer_paid'      => $line_buyer_paid,
                    'vendor_charged'  => $line_absorbed,
                    'currency'        => $currency,
                    'country_code'    => $country,
                    'status'          => self::STATUS_QUOTED,
                    'quote_at'        => current_time( 'mysql', true ),
                ] );

                if ( $entry_id ) {
                    $entries[] = $entry_id;

                    // Debitar al vendor (si aplica modo absorbed o shared).
                    if ( $line_absorbed > 0 && $line_vendor_id > 0 ) {
                        self::debit_vendor_for_shipping( $entry_id, $line_vendor_id, $line_absorbed, $order_id, $currency );
                    }
                }
            }

            // Registrar el costo en el ledger de la plataforma (FOUNDATION_VENDOR_ID = -1).
            // B-01 FIX: usar class constant, no defined() global.
            $total_platform_cost = $absorbed_cost > 0 ? $absorbed_cost : $buyer_paid;
            if ( $total_platform_cost > 0 && class_exists( 'LTMS_Business_Wallet' ) && class_exists( 'LTMS_Donation_Manager' ) ) {
                self::record_platform_ledger_entry( $order, $total_platform_cost, $currency );
            }

            // Sincronizar metas legacy para compatibilidad con código existente.
            $first_carrier = ! empty( $entries ) ? self::get_entry_carrier( $entries[0] ) : 'unknown';
            self::sync_legacy_order_meta( $order, $first_carrier, $absorbed_cost, $buyer_paid, $primary_vendor_id );

            // Actualizar presupuesto del vendor (si modo absorbed).
            if ( $absorbed_cost > 0 && $primary_vendor_id > 0 ) {
                self::increment_vendor_spend( $primary_vendor_id, $absorbed_cost );
            }

        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::warning(
                'SHIPPING_LEDGER_RECORD_FAILED',
                sprintf( 'Order #%d: %s', $order->get_id(), $e->getMessage() ),
                [ 'order_id' => $order->get_id(), 'trace' => $e->getTraceAsString() ]
            );
        }

        return $entries;
    }

    /**
     * Inserta o actualiza un entry del ledger. Idempotente por (order_id, order_item_id).
     *
     * @param array $data Campos del ledger.
     * @return int|null ID del entry, o null si falla.
     */
    private static function upsert_entry( array $data ): ?int {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_shipping_cost_ledger';

        $order_id     = (int) ( $data['order_id'] ?? 0 );
        $order_item_id = isset( $data['order_item_id'] ) ? (int) $data['order_item_id'] : 0;

        if ( ! $order_id ) {
            return null;
        }

        // Idempotencia: buscar entry existente por (order_id, order_item_id).
        // order_item_id = 0 significa "sin línea específica" (caso A).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM `{$table}` WHERE order_id = %d AND " .
                ( $order_item_id > 0 ? 'order_item_id = %d' : 'order_item_id IS NULL' ),
                $order_item_id > 0 ? [ $order_id, $order_item_id ] : [ $order_id ]
            )
        );

        if ( $existing ) {
            // Ya existe — no duplicar. Solo actualizar campos seguros.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update( $table, [
                'vendor_id'      => $data['vendor_id']      ?? -1,
                'carrier'        => $data['carrier']        ?? 'unknown',
                'quote_cost'     => $data['quote_cost']     ?? 0,
                'buyer_paid'     => $data['buyer_paid']     ?? 0,
                'vendor_charged' => $data['vendor_charged'] ?? 0,
                'currency'       => $data['currency']       ?? 'COP',
                'country_code'   => $data['country_code']   ?? 'CO',
                'updated_at'     => current_time( 'mysql', true ),
            ], [ 'id' => (int) $existing ] );
            return (int) $existing;
        }

        // Insertar nuevo.
        $now = current_time( 'mysql', true );
        $insert_data = array_merge( $data, [
            'created_at' => $now,
            'updated_at' => $now,
        ] );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ok = $wpdb->insert( $table, $insert_data );

        if ( false === $ok ) {
            LTMS_Core_Logger::warning(
                'SHIPPING_LEDGER_INSERT_FAILED',
                sprintf( 'Order #%d: %s', $order_id, $wpdb->last_error )
            );
            return null;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Marca un entry como 'shipped' con tracking_number (cuando el carrier genera la guía).
     *
     * @param int    $order_id
     * @param string $tracking_number
     * @param string $carrier
     * @return bool
     */
    public static function mark_shipped( int $order_id, string $tracking_number, string $carrier = '' ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_shipping_cost_ledger';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->query( $wpdb->prepare(
            "UPDATE `{$table}` SET
                status = %s,
                tracking_number = %s,
                shipped_at = %s,
                updated_at = %s
             WHERE order_id = %d AND status = %s",
            self::STATUS_SHIPPED,
            $tracking_number,
            current_time( 'mysql', true ),
            current_time( 'mysql', true ),
            $order_id,
            self::STATUS_QUOTED
        ) );

        if ( $carrier ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update( $table, [ 'carrier' => $carrier ], [ 'order_id' => $order_id ] );
        }

        return $rows > 0;
    }

    /**
     * Marca un entry como 'delivered' (cuando el carrier entrega).
     * Disparado por el hook `ltms_shipping_delivered`.
     *
     * @param int    $order_id
     * @param string $carrier
     */
    public static function on_carrier_delivered( int $order_id, string $carrier = '' ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_shipping_cost_ledger';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( $wpdb->prepare(
            "UPDATE `{$table}` SET
                status = %s,
                delivered_at = %s,
                updated_at = %s
             WHERE order_id = %d AND status IN (%s, %s)",
            self::STATUS_DELIVERED,
            current_time( 'mysql', true ),
            current_time( 'mysql', true ),
            $order_id,
            self::STATUS_SHIPPED,
            self::STATUS_QUOTED
        ) );

        LTMS_Core_Logger::info(
            'SHIPPING_LEDGER_DELIVERED',
            sprintf( 'Order #%d marcada como delivered en ledger (carrier=%s).', $order_id, $carrier )
        );
    }

    /**
     * Marca un entry como 'failed' (cuando el carrier reporta fallo).
     * En este caso, el entry entra en revisión para disputa.
     *
     * @param int    $order_id
     * @param string $carrier
     */
    public static function on_carrier_failed( int $order_id, string $carrier = '' ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_shipping_cost_ledger';

        // No cambiamos status a 'failed' (no existe en el ENUM). Marcamos como 'disputed'
        // automáticamente para que el equipo lo revise y abra disputa formal si aplica.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( $wpdb->prepare(
            "UPDATE `{$table}` SET
                status = %s,
                updated_at = %s,
                metadata = JSON_SET(COALESCE(metadata, '{}'), '$.failure_carrier', %s, '$.failure_at', %s)
             WHERE order_id = %d AND status IN (%s, %s, %s)",
            self::STATUS_DISPUTED,
            current_time( 'mysql', true ),
            $carrier,
            current_time( 'mysql', true ),
            $order_id,
            self::STATUS_QUOTED,
            self::STATUS_SHIPPED,
            self::STATUS_DELIVERED
        ) );

        LTMS_Core_Logger::warning(
            'SHIPPING_LEDGER_FAILED',
            sprintf( 'Order #%d marcada para revisión (carrier=%s failed). Posible disputa.', $order_id, $carrier )
        );
    }

    /**
     * Cuando el carrier genera la guía, capturamos el tracking_number.
     *
     * @param int    $order_id
     * @param string $tracking_number
     * @param string $carrier
     */
    public static function on_shipment_created( int $order_id, string $tracking_number, string $carrier = '' ): void {
        self::mark_shipped( $order_id, $tracking_number, $carrier );
    }

    // =====================================================================
    // 2. CONCILIACIÓN — Import de facturas del carrier + match
    // =====================================================================

    /**
     * Importa una factura del carrier (CSV o JSON) y procesa el auto-match.
     *
     * Formato CSV esperado (columnas):
     *   tracking_number, guide_number, order_ref, origin_city, destination_city,
     *   weight_kg, billed_amount, tax_amount, total_amount, currency
     *
     * Formato JSON:
     *   { "invoice_number": "...", "invoice_date": "Y-m-d", "period_start": "...",
     *     "period_end": "...", "total_amount": 1234.56, "currency": "COP",
     *     "lines": [ { "tracking_number": "...", "billed_amount": 123.45, ... } ] }
     *
     * @param string $carrier          Carrier ID (deprisa, heka, aveonline, uber).
     * @param array  $invoice_data     Datos de la factura (invoice_number, period, total).
     * @param array  $lines            Líneas (1 por guía).
     * @param int    $imported_by      User ID del admin que importa.
     * @param string $source_file      Nombre del archivo (auditoría).
     * @return array { invoice_id, lines_count, lines_matched, lines_unmatched, variance_total }
     */
    public static function import_carrier_invoice(
        string $carrier,
        array $invoice_data,
        array $lines,
        int $imported_by = 0,
        string $source_file = ''
    ): array {
        global $wpdb;
        $inv_table  = $wpdb->prefix . 'lt_carrier_invoices';
        $line_table = $wpdb->prefix . 'lt_carrier_invoice_lines';

        $result = [
            'invoice_id'      => 0,
            'lines_count'     => 0,
            'lines_matched'   => 0,
            'lines_unmatched' => 0,
            'variance_total'  => 0.0,
            'errors'          => [],
        ];

        try {
            // Validar datos mínimos de la factura.
            $invoice_number = sanitize_text_field( $invoice_data['invoice_number'] ?? '' );
            if ( ! $invoice_number ) {
                $result['errors'][] = 'Falta invoice_number';
                return $result;
            }

            $invoice_date = sanitize_text_field( $invoice_data['invoice_date'] ?? date( 'Y-m-d' ) );
            $period_start = sanitize_text_field( $invoice_data['period_start'] ?? date( 'Y-m-01' ) );
            $period_end   = sanitize_text_field( $invoice_data['period_end'] ?? date( 'Y-m-t' ) );
            $total_amount = (float) ( $invoice_data['total_amount'] ?? 0 );
            $currency     = sanitize_text_field( $invoice_data['currency'] ?? LTMS_Core_Config::get_currency() );
            $tax_amount   = (float) ( $invoice_data['tax_amount'] ?? 0 );
            $subtotal     = $total_amount - $tax_amount;

            // Idempotencia: si la factura ya existe, retornar sin duplicar.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $existing_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM `{$inv_table}` WHERE carrier = %s AND invoice_number = %s",
                $carrier, $invoice_number
            ) );
            if ( $existing_id ) {
                $result['invoice_id'] = (int) $existing_id;
                $result['errors'][] = 'Factura ya importada previamente';
                return $result;
            }

            // Insertar factura.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert( $inv_table, [
                'carrier'         => $carrier,
                'invoice_number'  => $invoice_number,
                'invoice_date'    => $invoice_date,
                'period_start'    => $period_start,
                'period_end'      => $period_end,
                'total_amount'    => $total_amount,
                'currency'        => $currency,
                'tax_amount'      => $tax_amount,
                'subtotal_amount' => $subtotal,
                'lines_count'     => count( $lines ),
                'status'          => 'matching',
                'source_file'     => $source_file,
                'imported_by'     => $imported_by,
                'imported_at'     => current_time( 'mysql', true ),
            ] );
            $invoice_id = (int) $wpdb->insert_id;
            $result['invoice_id'] = $invoice_id;

            // Insertar líneas + auto-match.
            $line_number = 1;
            $matched_count = 0;
            $unmatched_count = 0;
            $matched_amount = 0.0;
            $unmatched_amount = 0.0;
            $variance_total = 0.0;

            foreach ( $lines as $line ) {
                $tracking = sanitize_text_field( $line['tracking_number'] ?? '' );
                $guide    = sanitize_text_field( $line['guide_number'] ?? $tracking );
                $order_ref = sanitize_text_field( $line['order_ref'] ?? '' );
                $billed   = (float) ( $line['billed_amount'] ?? 0 );
                $line_tax = (float) ( $line['tax_amount'] ?? 0 );
                $total    = (float) ( $line['total_amount'] ?? ( $billed + $line_tax ) );

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->insert( $line_table, [
                    'invoice_id'       => $invoice_id,
                    'line_number'      => $line_number,
                    'tracking_number'  => $tracking ?: null,
                    'guide_number'     => $guide ?: null,
                    'order_ref'        => $order_ref ?: null,
                    'origin_city'      => sanitize_text_field( $line['origin_city'] ?? '' ) ?: null,
                    'destination_city' => sanitize_text_field( $line['destination_city'] ?? '' ) ?: null,
                    'weight_kg'        => (float) ( $line['weight_kg'] ?? 0 ) ?: null,
                    'billed_amount'    => $billed,
                    'tax_amount'       => $line_tax,
                    'total_amount'     => $total,
                    'currency'         => sanitize_text_field( $line['currency'] ?? $currency ),
                    'match_status'     => 'pending',
                ] );
                $line_id = (int) $wpdb->insert_id;

                // Auto-match.
                $match = self::match_invoice_line_to_ledger( $tracking, $order_ref, $carrier );
                if ( $match ) {
                    $ledger_id = $match['ledger_id'];
                    $match_method = $match['method'];

                    // Actualizar la línea con el match.
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $wpdb->update( $line_table, [
                        'ledger_id'    => $ledger_id,
                        'match_status' => 'matched',
                        'match_method' => $match_method,
                        'matched_at'   => current_time( 'mysql', true ),
                    ], [ 'id' => $line_id ] );

                    // Actualizar el ledger entry con el costo real + varianza.
                    self::set_real_cost_from_invoice_line( $ledger_id, $invoice_id, $line_id, $billed );

                    $matched_count++;
                    $matched_amount += $billed;
                    $variance_total += ( $billed - self::get_entry_quote_cost( $ledger_id ) );
                } else {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $wpdb->update( $line_table, [
                        'match_status' => 'unmatched',
                    ], [ 'id' => $line_id ] );
                    $unmatched_count++;
                    $unmatched_amount += $billed;

                    LTMS_Core_Logger::warning(
                        'SHIPPING_INVOICE_LINE_UNMATCHED',
                        sprintf(
                            'Invoice %s line %d: tracking=%s order_ref=%s — sin match en ledger. Phantom charge posible.',
                            $invoice_number, $line_number, $tracking, $order_ref
                        )
                    );
                }

                $line_number++;
            }

            // Actualizar totales de la factura.
            $status = ( $unmatched_count === 0 ) ? 'matched' : ( ( $matched_count > 0 ) ? 'partial' : 'imported' );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update( $inv_table, [
                'lines_matched'    => $matched_count,
                'lines_unmatched'  => $unmatched_count,
                'matched_amount'   => $matched_amount,
                'unmatched_amount' => $unmatched_amount,
                'variance_total'   => $variance_total,
                'status'           => $status,
            ], [ 'id' => $invoice_id ] );

            $result['lines_count']     = count( $lines );
            $result['lines_matched']   = $matched_count;
            $result['lines_unmatched'] = $unmatched_count;
            $result['variance_total']  = $variance_total;

            LTMS_Core_Logger::info(
                'SHIPPING_INVOICE_IMPORTED',
                sprintf(
                    'Carrier=%s Invoice=%s: %d líneas, %d matched, %d unmatched, varianza=%.2f',
                    $carrier, $invoice_number, count( $lines ), $matched_count, $unmatched_count, $variance_total
                ),
                $result
            );

        } catch ( \Throwable $e ) {
            $result['errors'][] = $e->getMessage();
            LTMS_Core_Logger::error(
                'SHIPPING_INVOICE_IMPORT_FAILED',
                sprintf( 'Carrier=%s: %s', $carrier, $e->getMessage() )
            );
        }

        return $result;
    }

    /**
     * Match de una línea de factura a un entry del ledger.
     *
     * Estrategia (en orden):
     *  1. Por tracking_number (más exacto).
     *  2. Por order_ref (numero de pedido).
     *  3. Sin match → phantom charge ( línea unmatched ).
     *
     * @param string $tracking
     * @param string $order_ref
     * @param string $carrier
     * @return array|null  [ 'ledger_id' => int, 'method' => string ]
     */
    private static function match_invoice_line_to_ledger( string $tracking, string $order_ref, string $carrier ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_shipping_cost_ledger';

        // 1. Por tracking_number.
        if ( $tracking ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $ledger_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM `{$table}` WHERE tracking_number = %s AND carrier = %s LIMIT 1",
                $tracking, $carrier
            ) );
            if ( $ledger_id ) {
                return [ 'ledger_id' => (int) $ledger_id, 'method' => 'tracking' ];
            }
        }

        // 2. Por order_ref (extraer el número del pedido).
        if ( $order_ref ) {
            $order_id = (int) preg_replace( '/[^0-9]/', '', $order_ref );
            if ( $order_id > 0 ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $ledger_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM `{$table}` WHERE order_id = %d AND carrier = %s LIMIT 1",
                    $order_id, $carrier
                ) );
                if ( $ledger_id ) {
                    return [ 'ledger_id' => (int) $ledger_id, 'method' => 'order_ref' ];
                }
            }
        }

        return null;
    }

    /**
     * Establece el costo REAL de un ledger entry a partir de una línea de factura.
     * Calcula la varianza (real_cost - quote_cost) y el %.
     *
     * @param int $ledger_id
     * @param int $invoice_id
     * @param int $invoice_line_id
     * @param float $real_cost
     * @return bool
     */
    public static function set_real_cost_from_invoice_line( int $ledger_id, int $invoice_id, int $invoice_line_id, float $real_cost ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_shipping_cost_ledger';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $entry = $wpdb->get_row( $wpdb->prepare(
            "SELECT quote_cost, vendor_charged FROM `{$table}` WHERE id = %d",
            $ledger_id
        ), ARRAY_A );

        if ( ! $entry ) {
            return false;
        }

        $quote_cost = (float) $entry['quote_cost'];
        $variance = round( $real_cost - $quote_cost, 2 );
        $variance_pct = $quote_cost > 0 ? round( ( $variance / $quote_cost ) * 100, 2 ) : 0.0;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ok = $wpdb->update( $table, [
            'real_cost'        => $real_cost,
            'variance'         => $variance,
            'variance_pct'     => $variance_pct,
            'invoice_id'       => $invoice_id,
            'invoice_line_id'  => $invoice_line_id,
            'status'           => self::STATUS_INVOICED,
            'invoiced_at'      => current_time( 'mysql', true ),
            'updated_at'       => current_time( 'mysql', true ),
        ], [ 'id' => $ledger_id ] );

        // Si la varianza es >tolerancia (default 5%), abrir disputa automáticamente.
        $tolerance_pct = (float) LTMS_Core_Config::get( 'ltms_shipping_variance_tolerance_pct', 5.0 );
        if ( $variance_pct > $tolerance_pct && $variance > 0 ) {
            self::auto_open_dispute( $ledger_id, $invoice_id, $invoice_line_id, $variance, $variance_pct );
        }

        // Si el costo real > lo debitado al vendor, el vendor debe la diferencia.
        // Si el costo real < lo debitado, reembolsar al vendor.
        $vendor_charged = (float) $entry['vendor_charged'];
        if ( $vendor_charged > 0 && abs( $real_cost - $vendor_charged ) > 0.01 ) {
            self::reconcile_vendor_charge( $ledger_id, $vendor_charged, $real_cost );
        }

        return $ok !== false;
    }

    /**
     * Abre una disputa automáticamente cuando la varianza supera la tolerancia.
     *
     * @param int   $ledger_id
     * @param int   $invoice_id
     * @param int   $invoice_line_id
     * @param float $variance
     * @param float $variance_pct
     */
    private static function auto_open_dispute( int $ledger_id, int $invoice_id, int $invoice_line_id, float $variance, float $variance_pct ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_shipping_disputes';

        // No abrir si ya existe una disputa para este ledger_id.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE ledger_id = %d AND status IN ('open', 'in_review') LIMIT 1",
            $ledger_id
        ) );
        if ( $existing ) {
            return;
        }

        $sla_days = (int) LTMS_Core_Config::get( 'ltms_shipping_dispute_sla_days', 15 );
        $sla_due = gmdate( 'Y-m-d H:i:s', time() + ( $sla_days * DAY_IN_SECONDS ) );

        // P2 FIX: fetch real_cost and quote_cost from the ledger entry so the
        // dispute reason and expected_amount have actual values instead of zeros.
        $entry = self::get_entry( $ledger_id );
        $real_cost = (float) ( $entry['real_cost'] ?? 0 );
        $quote_cost = (float) ( $entry['quote_cost'] ?? 0 );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert( $table, [
            'ledger_id'        => $ledger_id,
            'invoice_id'       => $invoice_id,
            'invoice_line_id'  => $invoice_line_id,
            'dispute_type'     => 'overcharge',
            'dispute_reason'   => sprintf(
                'Varianza automática: %.2f%% sobre la cotización (real=%.2f, quote=%.2f, diff=%.2f).',
                $variance_pct, $real_cost, $quote_cost, $variance
            ),
            'expected_amount'  => $quote_cost, // P2 FIX: was 0, now uses actual quote_cost.
            'disputed_amount'  => $variance,
            'status'           => 'open',
            'opened_by'        => 0, // System
            'opened_at'        => current_time( 'mysql', true ),
            'sla_due_at'       => $sla_due,
        ] );

        $dispute_id = (int) $wpdb->insert_id;

        // Marcar el ledger entry como disputed.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $wpdb->prefix . 'lt_shipping_cost_ledger',
            [ 'dispute_id' => $dispute_id, 'status' => self::STATUS_DISPUTED, 'updated_at' => current_time( 'mysql', true ) ],
            [ 'id' => $ledger_id ]
        );

        LTMS_Core_Logger::warning(
            'SHIPPING_DISPUTE_AUTO_OPENED',
            sprintf( 'Disputa #%d abierta automáticamente para ledger #%d (varianza=%.2f%%).', $dispute_id, $ledger_id, $variance_pct )
        );
    }

    // =====================================================================
    // 3. PRESUPUESTOS POR VENDOR
    // =====================================================================

    /**
     * Obtiene (o crea) el presupuesto mensual de un vendor.
     *
     * @param int $vendor_id
     * @param int $year
     * @param int $month
     * @return array
     */
    public static function get_vendor_budget( int $vendor_id, int $year = 0, int $month = 0 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_shipping_budgets';

        if ( ! $year )  $year  = (int) current_time( 'Y' );
        if ( ! $month ) $month = (int) current_time( 'n' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE vendor_id = %d AND period_year = %d AND period_month = %d",
            $vendor_id, $year, $month
        ), ARRAY_A );

        if ( ! $row ) {
            // Crear con defaults globales.
            $default_budget = (float) LTMS_Core_Config::get( 'ltms_shipping_vendor_default_budget', 0 );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert( $table, [
                'vendor_id'        => $vendor_id,
                'period_year'      => $year,
                'period_month'     => $month,
                'budget_limit'     => $default_budget,
                'soft_threshold'   => 80.00,
                'hard_threshold'   => 100.00,
                'spent_amount'     => 0.00,
                'spent_pct'        => 0.00,
            ] );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE vendor_id = %d AND period_year = %d AND period_month = %d",
                $vendor_id, $year, $month
            ), ARRAY_A );
        }

        // Recalcular spent_amount desde el ledger (source of truth).
        $spent = self::get_vendor_monthly_spend( $vendor_id, $year, $month );
        $row['spent_amount'] = $spent;
        $row['spent_pct'] = $row['budget_limit'] > 0 ? round( ( $spent / $row['budget_limit'] ) * 100, 2 ) : 0.0;

        // Actualizar la tabla con el spent recalculado (cache).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update( $table, [
            'spent_amount' => $spent,
            'spent_pct'    => $row['spent_pct'],
        ], [ 'id' => $row['id'] ] );

        return $row;
    }

    /**
     * Calcula el gasto mensual acumulado de un vendor (modo absorbed).
     *
     * @param int $vendor_id
     * @param int $year
     * @param int $month
     * @return float
     */
    public static function get_vendor_monthly_spend( int $vendor_id, int $year = 0, int $month = 0 ): float {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_shipping_cost_ledger';

        if ( ! $year )  $year  = (int) current_time( 'Y' );
        if ( ! $month ) $month = (int) current_time( 'n' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $spent = $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(vendor_charged), 0) FROM `{$table}`
             WHERE vendor_id = %d
             AND YEAR(quote_at) = %d AND MONTH(quote_at) = %d
             AND status != %s",
            $vendor_id, $year, $month, self::STATUS_WRITEOFF
        ) );

        return (float) $spent;
    }

    /**
     * Incrementa el spent_amount del vendor (recalculando desde el ledger).
     *
     * @param int   $vendor_id
     * @param float $amount
     */
    private static function increment_vendor_spend( int $vendor_id, float $amount ): void {
        // El spent se recalcula en get_vendor_budget() desde el ledger.
        // Aquí solo forzamos la actualización del cache.
        self::get_vendor_budget( $vendor_id );
    }

    /**
     * Verifica si un vendor puede seguir generando envíos absorbed.
     *
     * Reglas:
     *  - Si budget_limit = 0 → sin límite (allowed).
     *  - Si spent >= budget_limit * hard_threshold% → bloqueado.
     *  - Si spent >= budget_limit * soft_threshold% → alerta pero permitido.
     *
     * @param int $vendor_id
     * @param float $additional_cost Costo adicional a verificar (quote del envío actual).
     * @return array { allowed: bool, reason: string, spent_pct: float, budget_limit: float }
     */
    public static function check_vendor_budget( int $vendor_id, float $additional_cost = 0.0 ): array {
        if ( $vendor_id <= 0 ) {
            return [ 'allowed' => true, 'reason' => 'no_vendor', 'spent_pct' => 0, 'budget_limit' => 0 ];
        }

        $budget = self::get_vendor_budget( $vendor_id );
        $limit  = (float) $budget['budget_limit'];

        if ( $limit <= 0 ) {
            return [
                'allowed'      => true,
                'reason'       => 'no_limit',
                'spent_pct'    => 0.0,
                'budget_limit' => 0.0,
            ];
        }

        $spent     = (float) $budget['spent_amount'] + $additional_cost;
        $spent_pct = round( ( $spent / $limit ) * 100, 2 );
        $hard_pct  = (float) $budget['hard_threshold'];

        if ( $spent_pct >= $hard_pct ) {
            return [
                'allowed'      => false,
                'reason'       => 'over_hard_threshold',
                'spent_pct'    => $spent_pct,
                'budget_limit' => $limit,
                'spent_amount' => $spent,
            ];
        }

        $soft_pct = (float) $budget['soft_threshold'];
        if ( $spent_pct >= $soft_pct ) {
            return [
                'allowed'      => true,
                'reason'       => 'over_soft_threshold',
                'spent_pct'    => $spent_pct,
                'budget_limit' => $limit,
                'spent_amount' => $spent,
            ];
        }

        return [
            'allowed'      => true,
            'reason'       => 'ok',
            'spent_pct'    => $spent_pct,
            'budget_limit' => $limit,
            'spent_amount' => $spent,
        ];
    }

    /**
     * Hook en woocommerce_package_rates: si vendor supera hard threshold en modo absorbed,
     * oculta la tarifa "free absorbed" y fuerza cotización visible.
     *
     * v2.8.4 FIX: usa get_effective_mode_for_package() en lugar de get_global_mode()
     * para considerar override por categoría.
     *
     * @param array $rates
     * @param array $package
     * @return array
     */
    public static function maybe_block_absorbed_for_over_budget_vendor( array $rates, array $package ): array {
        // v2.8.4: usar modo efectivo del paquete (incluye override categoría).
        $mode = LTMS_Shipping_Mode::get_effective_mode_for_package( $package );
        if ( ! in_array( $mode, [ LTMS_Shipping_Mode::MODE_FREE_ABSORBED, LTMS_Shipping_Mode::MODE_HYBRID, LTMS_Shipping_Mode::MODE_SHARED ], true ) ) {
            return $rates;
        }

        $vendor_id = 0;
        foreach ( $package['contents'] ?? [] as $item ) {
            $product_id = (int) ( $item['product_id'] ?? 0 );
            if ( $product_id ) {
                $vendor_id = (int) get_post_field( 'post_author', $product_id );
                if ( $vendor_id ) break;
            }
        }

        if ( ! $vendor_id ) {
            return $rates;
        }

        // Estimar el costo del envío actual.
        $estimated_cost = 0.0;
        try {
            if ( class_exists( 'LTMS_Shipping_Parallel_Quoter' ) ) {
                $quote = LTMS_Shipping_Parallel_Quoter::get_cheapest_quote( $package );
                if ( $quote ) {
                    $estimated_cost = (float) $quote['cost'];
                }
            }
        } catch ( \Throwable $e ) {
            // Sin estimación, no bloquear (defensive).
        }

        $check = self::check_vendor_budget( $vendor_id, $estimated_cost );

        if ( ! $check['allowed'] ) {
            // Bloquear: remover las tarifas free_absorbed y dejar solo las cotizadas.
            $filtered = [];
            foreach ( $rates as $key => $rate ) {
                if ( strpos( $key, 'ltms_free_absorbed' ) !== false ) {
                    continue; // Ocultar la opción de envío gratis.
                }
                $filtered[ $key ] = $rate;
            }
            // Si no quedan tarifas, agregar una tarifa de fallback cotizada.
            if ( empty( $filtered ) && $estimated_cost > 0 ) {
                // El cliente verá el costo real.
                $fallback = new \WC_Shipping_Rate(
                    'ltms_over_budget_fallback',
                    __( 'Envío (vendedor sin crédito logístico)', 'ltms' ),
                    $estimated_cost,
                    [],
                    'flat_rate'
                );
                $filtered['ltms_over_budget_fallback'] = $fallback;
            }
            // Notificar al vendor (una vez por día).
            self::maybe_notify_vendor_blocked( $vendor_id, $check );
            return $filtered;
        }

        return $rates;
    }

    /**
     * Notifica al vendor cuando está sobre presupuesto (máx 1 vez por día).
     *
     * @param int   $vendor_id
     * @param array $check
     */
    private static function maybe_notify_vendor_blocked( int $vendor_id, array $check ): void {
        $transient_key = "ltms_vendor_blocked_notif_{$vendor_id}_" . current_time( 'Y_m_d' );
        if ( get_transient( $transient_key ) ) {
            return; // Ya notificado hoy.
        }
        set_transient( $transient_key, 1, DAY_IN_SECONDS );

        $vendor_email = '';
        $user = get_user_by( 'ID', $vendor_id );
        if ( $user ) {
            $vendor_email = $user->user_email;
        }

        if ( $vendor_email ) {
            $subject = sprintf(
                __( '[Lo Tengo] Crédito logístico suspendido — Vendor #%d', 'ltms' ),
                $vendor_id
            );
            $message = sprintf(
                __( "Hola,\n\nTu crédito logístico (envíos absorbidos) ha sido suspendido temporalmente.\n\nPresupuesto mensual: $%s\nGasto acumulado: $%s (%.2f%% del presupuesto)\n\nAcciones requeridas:\n1. Revisa tu panel de vendedor → Logística.\n2. Recarga tu billetera o espera al próximo ciclo mensual.\n3. Mientras tanto, tus pedidos se cotizarán con costo visible para el cliente.\n\n— Equipo Lo Tengo", 'ltms' ),
                number_format( $check['budget_limit'], 0, ',', '.' ),
                number_format( $check['spent_amount'], 0, ',', '.' ),
                $check['spent_pct']
            );
            wp_mail( $vendor_email, $subject, $message );
        }
    }

    // =====================================================================
    // 4. DISPUTAS
    // =====================================================================

    /**
     * Abre una disputa manual.
     *
     * @param array $data { ledger_id, dispute_type, dispute_reason, evidence_url, expected_amount, disputed_amount, opened_by }
     * @return int Dispute ID.
     */
    public static function open_dispute( array $data ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_shipping_disputes';

        $ledger_id = (int) ( $data['ledger_id'] ?? 0 );
        if ( ! $ledger_id ) {
            return 0;
        }

        $sla_days = (int) LTMS_Core_Config::get( 'ltms_shipping_dispute_sla_days', 15 );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert( $table, [
            'ledger_id'        => $ledger_id,
            'invoice_id'       => (int) ( $data['invoice_id'] ?? 0 ) ?: null,
            'invoice_line_id'  => (int) ( $data['invoice_line_id'] ?? 0 ) ?: null,
            'dispute_type'     => sanitize_text_field( $data['dispute_type'] ?? 'other' ),
            'dispute_reason'   => sanitize_text_field( $data['dispute_reason'] ?? '' ),
            'evidence_url'     => esc_url_raw( $data['evidence_url'] ?? '' ) ?: null,
            'expected_amount'  => (float) ( $data['expected_amount'] ?? 0 ),
            'disputed_amount'  => (float) ( $data['disputed_amount'] ?? 0 ),
            'status'           => 'open',
            'opened_by'        => (int) ( $data['opened_by'] ?? get_current_user_id() ),
            'opened_at'        => current_time( 'mysql', true ),
            'sla_due_at'       => gmdate( 'Y-m-d H:i:s', time() + ( $sla_days * DAY_IN_SECONDS ) ),
        ] );

        $dispute_id = (int) $wpdb->insert_id;

        if ( $dispute_id ) {
            // Marcar el ledger entry como disputed.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update(
                $wpdb->prefix . 'lt_shipping_cost_ledger',
                [ 'dispute_id' => $dispute_id, 'status' => self::STATUS_DISPUTED, 'updated_at' => current_time( 'mysql', true ) ],
                [ 'id' => $ledger_id ]
            );
        }

        return $dispute_id;
    }

    /**
     * Resuelve una disputa.
     *
     * @param int    $dispute_id
     * @param string $status       approved|rejected|credited|expired
     * @param float  $credit_amount Monto creditado por el carrier.
     * @param string $notes
     * @param int    $resolved_by
     * @return bool
     */
    public static function resolve_dispute( int $dispute_id, string $status, float $credit_amount = 0.0, string $notes = '', int $resolved_by = 0 ): bool {
        global $wpdb;
        $disp_table = $wpdb->prefix . 'lt_shipping_disputes';
        $ledg_table = $wpdb->prefix . 'lt_shipping_cost_ledger';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $dispute = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$disp_table}` WHERE id = %d",
            $dispute_id
        ), ARRAY_A );

        if ( ! $dispute ) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ok = $wpdb->update( $disp_table, [
            'status'           => $status,
            'credit_amount'    => $credit_amount,
            'resolution_notes' => $notes,
            'resolved_at'      => current_time( 'mysql', true ),
            'resolved_by'      => $resolved_by ?: get_current_user_id(),
        ], [ 'id' => $dispute_id ] );

        if ( false === $ok ) {
            return false;
        }

        // Si se creditó, ajustar el ledger entry.
        if ( $status === 'credited' && $credit_amount > 0 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update( $ledg_table, [
                'status'        => self::STATUS_RECONCILED,
                'reconciled_at' => current_time( 'mysql', true ),
                'updated_at'    => current_time( 'mysql', true ),
            ], [ 'id' => (int) $dispute['ledger_id'] ] );

            // Reembolsar al vendor si fue debitado.
            $entry = self::get_entry( (int) $dispute['ledger_id'] );
            if ( $entry && (float) $entry['vendor_charged'] > 0 && $entry['vendor_id'] > 0 ) {
                self::refund_vendor_for_dispute( (int) $dispute['ledger_id'], $entry['vendor_id'], $credit_amount );
            }
        } elseif ( $status === 'rejected' || $status === 'expired' ) {
            // Marcar como reconciliado (pérdida asumida o no recuperable).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update( $ledg_table, [
                'status'        => self::STATUS_WRITEOFF,
                'reconciled_at' => current_time( 'mysql', true ),
                'updated_at'    => current_time( 'mysql', true ),
            ], [ 'id' => (int) $dispute['ledger_id'] ] );
        }

        return true;
    }

    // =====================================================================
    // 5. ALERTAS — Cron diario
    // =====================================================================

    /**
     * Cron diario: revisa alertas pendientes.
     */
    public static function run_daily_alerts(): void {
        self::alert_vendors_over_budget();
        self::alert_disputes_near_sla();
        self::alert_high_variance_carriers();
        self::alert_shipping_over_pct_of_order();
    }

    /**
     * Alerta: vendors que superaron el soft threshold.
     */
    private static function alert_vendors_over_budget(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_shipping_budgets';

        $year  = (int) current_time( 'Y' );
        $month = (int) current_time( 'n' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $vendors = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}`
             WHERE period_year = %d AND period_month = %d
             AND budget_limit > 0
             AND alert_sent = 0
             AND (spent_amount / budget_limit * 100) >= soft_threshold",
            $year, $month
        ), ARRAY_A );

        foreach ( $vendors as $v ) {
            self::maybe_notify_vendor_blocked( (int) $v['vendor_id'], [
                'budget_limit' => (float) $v['budget_limit'],
                'spent_amount' => (float) $v['spent_amount'],
                'spent_pct'    => (float) $v['spent_pct'],
            ] );

            // Marcar alerta enviada.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update( $table, [ 'alert_sent' => 1 ], [ 'id' => $v['id'] ] );
        }
    }

    /**
     * Alerta: disputas próximas a vencer SLA.
     */
    private static function alert_disputes_near_sla(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_shipping_disputes';

        $threshold = gmdate( 'Y-m-d H:i:s', time() + ( 3 * DAY_IN_SECONDS ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $disputes = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}`
             WHERE status IN ('open', 'in_review')
             AND sla_due_at <= %s",
            $threshold
        ), ARRAY_A );

        if ( ! $disputes ) {
            return;
        }

        $admin_email = get_option( 'admin_email' );
        if ( ! $admin_email ) {
            return;
        }

        $body = __( "Las siguientes disputas logísticas están próximas a vencer SLA:\n\n", 'ltms' );
        foreach ( $disputes as $d ) {
            $body .= sprintf(
                "- Disputa #%d (ledger #%d): vence %s — motivo: %s\n",
                $d['id'], $d['ledger_id'], $d['sla_due_at'], $d['dispute_reason']
            );
        }
        $body .= "\nAccede al panel admin → Logística / Costos → Disputas para resolver.";

        wp_mail(
            $admin_email,
            sprintf( '[Lo Tengo] %d disputas logísticas próximas a vencer SLA', count( $disputes ) ),
            $body
        );
    }

    /**
     * Alerta: carriers con varianza promedio > tolerancia en el último mes.
     */
    private static function alert_high_variance_carriers(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_shipping_cost_ledger';

        $since = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
        $tolerance = (float) LTMS_Core_Config::get( 'ltms_shipping_variance_tolerance_pct', 5.0 );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $carriers = $wpdb->get_results( $wpdb->prepare(
            "SELECT carrier, COUNT(*) as cnt, AVG(variance_pct) as avg_var, SUM(variance) as sum_var
             FROM `{$table}`
             WHERE invoiced_at >= %s AND variance_pct > %f
             GROUP BY carrier
             HAVING avg_var > %f",
            $since, $tolerance, $tolerance
        ), ARRAY_A );

        if ( ! $carriers ) {
            return;
        }

        $admin_email = get_option( 'admin_email' );
        if ( ! $admin_email ) {
            return;
        }

        $body = __( "Los siguientes carriers tienen varianza promedio superior al tolerado:\n\n", 'ltms' );
        foreach ( $carriers as $c ) {
            $body .= sprintf(
                "- %s: %d envíos, varianza promedio %.2f%%, pérdida total $%s\n",
                $c['carrier'], $c['cnt'], $c['avg_var'], number_format( $c['sum_var'], 0, ',', '.' )
            );
        }
        $body .= "\nConsidera renegociar tarifas con estos carriers.";

        wp_mail(
            $admin_email,
            '[Lo Tengo] Carriers con varianza alta — revisar contratos',
            $body
        );
    }

    /**
     * Alerta: envíos individuales donde el costo > X% del valor del pedido.
     */
    private static function alert_shipping_over_pct_of_order(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_shipping_cost_ledger';
        $threshold_pct = (float) LTMS_Core_Config::get( 'ltms_shipping_cost_order_pct_alert', 30.0 );

        // Últimas 24h: entries nuevos donde shipping > threshold% del order total.
        $since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $entries = $wpdb->get_results( $wpdb->prepare(
            "SELECT l.*, o.get_total as order_total
             FROM `{$table}` l
             JOIN (SELECT ID FROM {$wpdb->posts} WHERE post_type='shop_order') p ON p.ID = l.order_id
             WHERE l.created_at >= %s AND l.quote_cost > 0
             AND l.carrier NOT IN ('pickup', 'own_delivery')",
            $since
        ), ARRAY_A );

        $flagged = [];
        foreach ( $entries as $e ) {
            $order = wc_get_order( (int) $e['order_id'] );
            if ( ! $order ) continue;
            $order_total = (float) $order->get_total();
            if ( $order_total <= 0 ) continue;
            $pct = ( (float) $e['quote_cost'] / $order_total ) * 100;
            if ( $pct >= $threshold_pct ) {
                $flagged[] = [
                    'order_id'    => (int) $e['order_id'],
                    'vendor_id'   => (int) $e['vendor_id'],
                    'carrier'     => $e['carrier'],
                    'shipping'    => (float) $e['quote_cost'],
                    'order_total' => $order_total,
                    'pct'         => round( $pct, 2 ),
                ];
            }
        }

        if ( ! $flagged ) {
            return;
        }

        $admin_email = get_option( 'admin_email' );
        if ( ! $admin_email ) return;

        $body = sprintf(
            __( "Se detectaron %d pedidos donde el envío supera el %.0f%% del valor del pedido:\n\n", 'ltms' ),
            count( $flagged ), $threshold_pct
        );
        foreach ( $flagged as $f ) {
            $body .= sprintf(
                "- Pedido #%d (vendor #%d, %s): envío $%s / total $%s = %.1f%%\n",
                $f['order_id'], $f['vendor_id'], $f['carrier'],
                number_format( $f['shipping'], 0, ',', '.' ),
                number_format( $f['order_total'], 0, ',', '.' ),
                $f['pct']
            );
        }
        $body .= "\nPosibles causas: productos pesados con precio bajo, zonas remotas, o errores de cotización.";

        wp_mail(
            $admin_email,
            sprintf( '[Lo Tengo] %d pedidos con envío desproporcionado', count( $flagged ) ),
            $body
        );
    }

    // =====================================================================
    // 6. KPIs Y CONSULTAS
    // =====================================================================

    /**
     * Obtiene el estado de cuenta de fletes de un vendedor (para su panel).
     *
     * @param int $vendor_id
     * @param int $year
     * @param int $month
     * @return array {
     *   @type array  $budget      Datos del presupuesto del mes.
     *   @type float  $spent       Total debitado en el mes.
     *   @type float  $remaining   Restante (budget_limit - spent).
     *   @type array  $entries     Últimos 50 entries del vendor (todos los meses).
     *   @type array  $monthly     Resumen de los últimos 6 meses.
     * }
     */
    public static function get_vendor_statement( int $vendor_id, int $year = 0, int $month = 0 ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_shipping_cost_ledger';

        if ( ! $year )  $year  = (int) current_time( 'Y' );
        if ( ! $month ) $month = (int) current_time( 'n' );

        $budget = self::get_vendor_budget( $vendor_id, $year, $month );
        $spent  = (float) $budget['spent_amount'];
        $limit  = (float) $budget['budget_limit'];
        $remaining = max( 0, $limit - $spent );

        // Últimos 50 entries del vendor.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $entries = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$table}`
             WHERE vendor_id = %d
             ORDER BY created_at DESC
             LIMIT 50",
            $vendor_id
        ), ARRAY_A );

        // Resumen mensual últimos 6 meses.
        $six_months_ago = gmdate( 'Y-m-01 00:00:00', strtotime( '-5 months', time() ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $monthly = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                YEAR(quote_at) AS y,
                MONTH(quote_at) AS m,
                COUNT(*) AS cnt,
                COALESCE(SUM(vendor_charged), 0) AS charged,
                COALESCE(SUM(quote_cost), 0) AS quote,
                COALESCE(SUM(real_cost), 0) AS real_cost
             FROM `{$table}`
             WHERE vendor_id = %d AND quote_at >= %s
             GROUP BY YEAR(quote_at), MONTH(quote_at)
             ORDER BY y DESC, m DESC",
            $vendor_id, $six_months_ago
        ), ARRAY_A );

        return [
            'budget'    => $budget,
            'spent'     => $spent,
            'remaining' => $remaining,
            'entries'   => $entries ?: [],
            'monthly'   => $monthly ?: [],
        ];
    }

    /**
     * Obtiene KPIs agregados para el dashboard admin.
     *
     * @param string $period 'today' | 'month' | 'last_30d' | 'ytd' | 'all'
     * @return array
     */
    public static function get_kpis( string $period = 'month' ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_shipping_cost_ledger';

        $where = '';
        switch ( $period ) {
            case 'today':
                $where = $wpdb->prepare( 'AND DATE(l.created_at) = %s', current_time( 'Y-m-d' ) );
                break;
            case 'last_30d':
                $where = $wpdb->prepare( 'AND l.created_at >= %s', gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) ) );
                break;
            case 'ytd':
                $where = $wpdb->prepare( 'AND YEAR(l.created_at) = %d', current_time( 'Y' ) );
                break;
            case 'all':
                $where = '';
                break;
            case 'month':
            default:
                $where = $wpdb->prepare( 'AND YEAR(l.created_at) = %d AND MONTH(l.created_at) = %d', current_time( 'Y' ), current_time( 'n' ) );
                break;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row(
            "SELECT
                COUNT(*)                                          AS total_entries,
                COALESCE(SUM(l.buyer_paid), 0)                    AS total_buyer_paid,
                COALESCE(SUM(l.vendor_charged), 0)                AS total_vendor_charged,
                COALESCE(SUM(l.quote_cost), 0)                    AS total_quote_cost,
                COALESCE(SUM(l.real_cost), 0)                     AS total_real_cost,
                COALESCE(SUM(CASE WHEN l.real_cost IS NOT NULL THEN l.variance ELSE 0 END), 0) AS total_variance,
                COUNT(CASE WHEN l.status = 'disputed' THEN 1 END) AS open_disputes,
                COUNT(CASE WHEN l.status = 'reconciled' THEN 1 END) AS reconciled,
                COUNT(CASE WHEN l.status = 'invoiced' THEN 1 END) AS invoiced,
                COUNT(CASE WHEN l.status = 'quoted' THEN 1 END)   AS quoted,
                COUNT(CASE WHEN l.status = 'writeoff' THEN 1 END) AS writeoffs
             FROM `{$table}` l
             WHERE 1=1 {$where}",
            ARRAY_A
        );

        $kpis = [
            'total_entries'      => (int) ( $row['total_entries'] ?? 0 ),
            'total_buyer_paid'   => (float) ( $row['total_buyer_paid'] ?? 0 ),
            'total_vendor_charged' => (float) ( $row['total_vendor_charged'] ?? 0 ),
            'total_quote_cost'   => (float) ( $row['total_quote_cost'] ?? 0 ),
            'total_real_cost'    => (float) ( $row['total_real_cost'] ?? 0 ),
            'total_variance'     => (float) ( $row['total_variance'] ?? 0 ),
            'open_disputes'      => (int) ( $row['open_disputes'] ?? 0 ),
            'reconciled'         => (int) ( $row['reconciled'] ?? 0 ),
            'invoiced'           => (int) ( $row['invoiced'] ?? 0 ),
            'quoted'             => (int) ( $row['quoted'] ?? 0 ),
            'writeoffs'          => (int) ( $row['writeoffs'] ?? 0 ),
        ];

        // P&L: net = buyer_paid + vendor_charged - real_cost.
        $kpis['net_pnl'] = round( $kpis['total_buyer_paid'] + $kpis['total_vendor_charged'] - $kpis['total_real_cost'], 2 );

        // Por carrier.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $carriers = $wpdb->get_results(
            "SELECT carrier,
                    COUNT(*) AS cnt,
                    COALESCE(SUM(quote_cost), 0)  AS quote,
                    COALESCE(SUM(real_cost), 0)   AS real,
                    COALESCE(SUM(variance), 0)    AS variance,
                    COALESCE(AVG(variance_pct), 0) AS avg_var_pct
             FROM `{$table}` l
             WHERE 1=1 {$where}
             GROUP BY carrier
             ORDER BY quote DESC",
            ARRAY_A
        );
        $kpis['by_carrier'] = $carriers;

        // Top vendors por gasto.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $top_vendors = $wpdb->get_results(
            "SELECT vendor_id,
                    COUNT(*) AS cnt,
                    COALESCE(SUM(vendor_charged), 0) AS charged,
                    COALESCE(SUM(quote_cost), 0)    AS quote
             FROM `{$table}` l
             WHERE vendor_id > 0 {$where}
             GROUP BY vendor_id
             ORDER BY charged DESC
             LIMIT 10",
            ARRAY_A
        );
        $kpis['top_vendors'] = $top_vendors;

        return $kpis;
    }

    /**
     * Obtiene un entry del ledger por ID.
     *
     * @param int $ledger_id
     * @return array|null
     */
    public static function get_entry( int $ledger_id ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_shipping_cost_ledger';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE id = %d",
            $ledger_id
        ), ARRAY_A );

        return $row ?: null;
    }

    /**
     * Obtiene entries del ledger con filtros.
     *
     * @param array $filters { vendor_id, carrier, status, date_from, date_to, per_page, page }
     * @return array
     */
    public static function get_entries( array $filters = [] ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_shipping_cost_ledger';

        $where = '1=1';
        $args  = [];

        if ( ! empty( $filters['vendor_id'] ) ) {
            $where .= ' AND vendor_id = %d';
            $args[] = (int) $filters['vendor_id'];
        }
        if ( ! empty( $filters['carrier'] ) ) {
            $where .= ' AND carrier = %s';
            $args[] = sanitize_text_field( $filters['carrier'] );
        }
        if ( ! empty( $filters['status'] ) ) {
            $where .= ' AND status = %s';
            $args[] = sanitize_text_field( $filters['status'] );
        }
        if ( ! empty( $filters['date_from'] ) ) {
            $where .= ' AND created_at >= %s';
            $args[] = sanitize_text_field( $filters['date_from'] );
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $where .= ' AND created_at <= %s';
            $args[] = sanitize_text_field( $filters['date_to'] );
        }

        $per_page = (int) ( $filters['per_page'] ?? 50 );
        $page     = (int) ( $filters['page'] ?? 1 );
        $offset   = ( $page - 1 ) * $per_page;

        $where .= ' ORDER BY created_at DESC LIMIT %d OFFSET %d';
        $args[] = $per_page;
        $args[] = $offset;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE {$where}", $args ),
            ARRAY_A
        );

        return $rows ?: [];
    }

    // =====================================================================
    // HELPERS — Internal
    // =====================================================================

    /**
     * Debita el costo absorbed al vendor y guarda el wallet_tx_id en el ledger.
     *
     * B-07 FIX: si el vendor no tiene saldo suficiente, en lugar de fallar,
     * registra un "crédito logístico" (deuda) usando execute_transaction con
     * type='debit' y monto negativo en balance (wallet puede quedar en negativo).
     * Esto permite que el pedido se procese (no se bloquea al cliente) y el
     * vendor debe recargar para futuros envíos.
     *
     * @param int    $ledger_id
     * @param int    $vendor_id
     * @param float  $amount
     * @param int    $order_id
     * @param string $currency
     */
    private static function debit_vendor_for_shipping( int $ledger_id, int $vendor_id, float $amount, int $order_id, string $currency ): void {
        if ( ! class_exists( 'LTMS_Business_Wallet' ) ) {
            return;
        }

        // Idempotency key para evitar doble débito en re-ejecuciones.
        $idempotency_key = sprintf( 'shipping_absorbed_o%d_l%d', $order_id, $ledger_id );

        try {
            // Intentar débito normal primero.
            $tx_id = LTMS_Business_Wallet::debit(
                $vendor_id,
                $amount,
                sprintf( __( 'Envío absorbido — Pedido #%d', 'ltms' ), $order_id ),
                [
                    'type'        => 'shipping_absorbed',
                    'order_id'    => $order_id,
                    'ledger_id'   => $ledger_id,
                    'carrier'     => 'free_absorbed',
                ],
                $order_id,
                $currency,
                $idempotency_key
            );

            if ( $tx_id ) {
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update(
                    $wpdb->prefix . 'lt_shipping_cost_ledger',
                    [ 'wallet_tx_id' => (int) $tx_id, 'updated_at' => current_time( 'mysql', true ) ],
                    [ 'id' => $ledger_id ]
                );
            }
        } catch ( \InvalidArgumentException $e ) {
            // Saldo insuficiente: registrar como crédito logístico (deuda).
            // El vendor queda con balance negativo y debe recargar.
            $msg = $e->getMessage();
            if ( false !== strpos( $msg, 'Saldo insuficiente' ) ) {
                self::record_vendor_shipping_debt( $ledger_id, $vendor_id, $amount, $order_id, $currency, $idempotency_key );
                return;
            }
            // Otro error de argumento: loguear y propagar.
            LTMS_Core_Logger::warning(
                'SHIPPING_LEDGER_VENDOR_DEBIT_FAILED',
                sprintf( 'Ledger #%d vendor #%d: %s', $ledger_id, $vendor_id, $msg )
            );
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::warning(
                'SHIPPING_LEDGER_VENDOR_DEBIT_FAILED',
                sprintf( 'Ledger #%d vendor #%d: %s', $ledger_id, $vendor_id, $e->getMessage() )
            );
        }
    }

    /**
     * B-07 FIX: cuando el vendor no tiene saldo para cubrir el envío absorbed,
     * registramos el débito directamente (wallet queda en negativo = deuda).
     * Esto permite que el pedido se procese, pero el vendor debe recargar.
     *
     * El admin ve el balance negativo en el panel y puede:
     *  - Cobrarle la deuda al vendor en el próximo payout.
     *  - Suspender el modo absorbed del vendor (forzar cotización visible).
     *
     * @param int    $ledger_id
     * @param int    $vendor_id
     * @param float  $amount
     * @param int    $order_id
     * @param string $currency
     * @param string $idempotency_key
     */
    private static function record_vendor_shipping_debt(
        int $ledger_id, int $vendor_id, float $amount,
        int $order_id, string $currency, string $idempotency_key
    ): void {
        try {
            // Bypass del check de saldo usando execute_transaction directamente.
            // execute_transaction no valida saldo — aplica la transacción sin más.
            $tx_id = LTMS_Business_Wallet::execute_transaction(
                $vendor_id,
                'debit',
                $amount,
                sprintf( __( 'Envío absorbido (crédito logístico) — Pedido #%d', 'ltms' ), $order_id ),
                [
                    'type'           => 'shipping_absorbed_debt',
                    'order_id'       => $order_id,
                    'ledger_id'      => $ledger_id,
                    'carrier'        => 'free_absorbed',
                    'is_debt'        => true,
                ],
                $order_id,
                $currency,
                $idempotency_key
            );

            if ( $tx_id ) {
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update(
                    $wpdb->prefix . 'lt_shipping_cost_ledger',
                    [
                        'wallet_tx_id' => (int) $tx_id,
                        'updated_at'   => current_time( 'mysql', true ),
                    ],
                    [ 'id' => $ledger_id ]
                );
            }

            LTMS_Core_Logger::warning(
                'SHIPPING_VENDOR_DEBT_RECORDED',
                sprintf(
                    'Vendor #%d wallet quedó en negativo (-$%.2f) por envío absorbed del pedido #%d. Crédito logístico registrado.',
                    $vendor_id, $amount, $order_id
                ),
                [ 'vendor_id' => $vendor_id, 'amount' => $amount, 'order_id' => $order_id, 'ledger_id' => $ledger_id ]
            );
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error(
                'SHIPPING_VENDOR_DEBT_FAILED',
                sprintf( 'Ledger #%d vendor #%d: %s', $ledger_id, $vendor_id, $e->getMessage() )
            );
        }
    }

    /**
     * Reembolsa al vendor cuando el costo real < debitado (o cuando se resuelve disputa con crédito).
     *
     * @param int   $ledger_id
     * @param int   $vendor_id
     * @param float $amount
     */
    private static function refund_vendor_for_dispute( int $ledger_id, int $vendor_id, float $amount ): void {
        if ( ! class_exists( 'LTMS_Business_Wallet' ) || $amount <= 0 ) {
            return;
        }

        $idempotency_key = sprintf( 'shipping_refund_dispute_l%d', $ledger_id );

        try {
            $tx_id = LTMS_Business_Wallet::credit(
                $vendor_id,
                $amount,
                sprintf( __( 'Reembolso por disputa logística — Ledger #%d', 'ltms' ), $ledger_id ),
                [
                    'type'      => 'shipping_refund',
                    'ledger_id' => $ledger_id,
                ],
                0,
                LTMS_Core_Config::get_currency(),
                $idempotency_key
            );

            LTMS_Core_Logger::info(
                'SHIPPING_DISPUTE_REFUND',
                sprintf( 'Vendor #%d reembolsado $%.2f por disputa (ledger #%d, tx #%d).', $vendor_id, $amount, $ledger_id, $tx_id )
            );

            // P2 FIX: store the refund tx_id in the ledger entry's metadata
            // so the refund is traceable from the ledger without querying
            // lt_wallet_transactions by idempotency key.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( $wpdb->prepare(
                "UPDATE `{$wpdb->prefix}lt_shipping_cost_ledger`
                 SET `metadata` = JSON_SET(COALESCE(metadata, '{}'), '$.refund_tx_id', %d, '$.refund_amount', %.2f, '$.refunded_at', %s),
                     `updated_at` = %s
                 WHERE `id` = %d",
                (int) $tx_id,
                $amount,
                current_time( 'mysql', true ),
                current_time( 'mysql', true ),
                $ledger_id
            ) );
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::warning(
                'SHIPPING_DISPUTE_REFUND_FAILED',
                sprintf( 'Ledger #%d vendor #%d: %s', $ledger_id, $vendor_id, $e->getMessage() )
            );
        }
    }

    /**
     * Ajusta el cargo al vendor cuando el costo real != debitado.
     * Si real > charged → debit adicional (vendor debe más).
     * Si real < charged → credit (reembolso).
     *
     * @param int   $ledger_id
     * @param float $vendor_charged
     * @param float $real_cost
     */
    private static function reconcile_vendor_charge( int $ledger_id, float $vendor_charged, float $real_cost ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_shipping_cost_ledger';

        $diff = round( $real_cost - $vendor_charged, 2 );
        if ( abs( $diff ) < 0.50 ) {
            // Diferencia insignificante (< 50 centavos) — no ajustar.
            return;
        }

        $entry = self::get_entry( $ledger_id );
        if ( ! $entry || (int) $entry['vendor_id'] <= 0 ) {
            return;
        }

        $vendor_id = (int) $entry['vendor_id'];

        if ( $diff > 0 ) {
            // Vendor debe más: debit adicional.
            $idempotency_key = sprintf( 'shipping_reconcile_diff_l%d', $ledger_id );
            try {
                LTMS_Business_Wallet::debit(
                    $vendor_id,
                    $diff,
                    sprintf( __( 'Ajuste conciliación — Ledger #%d (costo real > cotización)', 'ltms' ), $ledger_id ),
                    [ 'type' => 'shipping_reconcile_diff', 'ledger_id' => $ledger_id ],
                    (int) $entry['order_id'],
                    $entry['currency'],
                    $idempotency_key
                );
            } catch ( \Throwable $e ) {
                LTMS_Core_Logger::warning( 'SHIPPING_RECONCILE_DEBIT_FAILED', $e->getMessage() );
            }
        } else {
            // Reembolso al vendor.
            self::refund_vendor_for_dispute( $ledger_id, $vendor_id, abs( $diff ) );
        }

        // Actualizar vendor_charged en el ledger para reflejar el costo real.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update( $table, [
            'vendor_charged' => $real_cost,
            'updated_at'     => current_time( 'mysql', true ),
        ], [ 'id' => $ledger_id ] );
    }

    /**
     * Registra el costo en el ledger de la plataforma (FOUNDATION_VENDOR_ID = -1).
     * B-01 FIX: usa class constant correctamente.
     *
     * @param \WC_Order $order
     * @param float     $amount
     * @param string    $currency
     */
    private static function record_platform_ledger_entry( \WC_Order $order, float $amount, string $currency ): void {
        $foundation_id = LTMS_Donation_Manager::FOUNDATION_VENDOR_ID; // -1
        $idempotency_key = sprintf( 'platform_shipping_cost_o%d', $order->get_id() );

        try {
            $tx_id = LTMS_Business_Wallet::execute_transaction(
                $foundation_id,
                'fee',
                $amount,
                sprintf( 'Costo logístico carrier — Pedido #%d', $order->get_id() ),
                [
                    'type'     => 'shipping_carrier_cost',
                    'order_id' => $order->get_id(),
                ],
                $order->get_id(),
                $currency,
                $idempotency_key
            );

            // Guardar el tx_id en el primer ledger entry del pedido.
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update(
                $wpdb->prefix . 'lt_shipping_cost_ledger',
                [ 'platform_wallet_tx_id' => (int) $tx_id, 'updated_at' => current_time( 'mysql', true ) ],
                [ 'order_id' => $order->get_id() ]
            );
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::warning(
                'PLATFORM_LEDGER_ENTRY_FAILED',
                sprintf( 'Order #%d: %s', $order->get_id(), $e->getMessage() )
            );
        }
    }

    /**
     * Resuelve el carrier ID desde el método de envío del pedido.
     *
     * @param \WC_Order $order
     * @param string    $absorbed_provider Provider guardado en modo absorbed.
     * @return string
     */
    private static function resolve_carrier_from_order( \WC_Order $order, string $absorbed_provider ): string {
        foreach ( $order->get_shipping_methods() as $method ) {
            $method_id = $method->get_method_id() . ':' . $method->get_instance_id();
            return self::normalize_carrier_id( $method_id, $absorbed_provider );
        }
        return $absorbed_provider ?: 'unknown';
    }

    /**
     * Normaliza un method_id de WC a un carrier ID del ledger.
     *
     * @param string $method_id
     * @param string $absorbed_provider
     * @return string
     */
    private static function normalize_carrier_id( string $method_id, string $absorbed_provider = '' ): string {
        $method_id_lower = strtolower( $method_id );
        if ( strpos( $method_id_lower, 'deprisa' ) !== false )     return self::CARRIER_DEPRISA;
        if ( strpos( $method_id_lower, 'aveonline' ) !== false )   return self::CARRIER_AVEONLINE;
        if ( strpos( $method_id_lower, 'heka' ) !== false )        return self::CARRIER_HEKA;
        if ( strpos( $method_id_lower, 'uber' ) !== false )        return self::CARRIER_UBER;
        if ( strpos( $method_id_lower, 'pickup' ) !== false )      return self::CARRIER_PICKUP;
        if ( strpos( $method_id_lower, 'own' ) !== false )         return self::CARRIER_OWN_DELIVERY;
        if ( strpos( $method_id_lower, 'free_absorbed' ) !== false ) return self::CARRIER_FREE_ABSORBED;
        return $absorbed_provider ?: 'unknown';
    }

    /**
     * Resuelve el vendor_id desde una línea de envío específica.
     * Busca el vendor_id en el meta del shipping method, o deriva del primer
     * item del paquete asociado.
     *
     * @param \WC_Order        $order
     * @param \WC_Order_Item_Shipping $method
     * @return int
     */
    private static function resolve_vendor_from_shipping_line( \WC_Order $order, $method ): int {
        // 1. Meta del shipping item.
        $vendor_id = (int) $method->get_meta( '_ltms_vendor_id' );
        if ( $vendor_id ) return $vendor_id;

        // 2. Fallback: post_author del primer producto del pedido.
        foreach ( $order->get_items() as $item ) {
            $product_id = (int) ( $item->get_product_id() ?: $item->get_variation_id() );
            if ( $product_id ) {
                $author_id = (int) get_post_field( 'post_author', $product_id );
                if ( $author_id ) return $author_id;
            }
        }

        return 0;
    }

    /**
     * Obtiene el carrier de un entry del ledger.
     *
     * @param int $ledger_id
     * @return string
     */
    private static function get_entry_carrier( int $ledger_id ): string {
        $entry = self::get_entry( $ledger_id );
        return $entry['carrier'] ?? 'unknown';
    }

    /**
     * Obtiene el quote_cost de un entry.
     *
     * @param int $ledger_id
     * @return float
     */
    private static function get_entry_quote_cost( int $ledger_id ): float {
        $entry = self::get_entry( $ledger_id );
        return (float) ( $entry['quote_cost'] ?? 0 );
    }

    /**
     * Sincroniza las metas legacy del pedido para compatibilidad con código existente.
     *
     * @param \WC_Order $order
     * @param string    $carrier
     * @param float     $absorbed_cost
     * @param float     $buyer_paid
     * @param int       $vendor_id
     */
    private static function sync_legacy_order_meta( \WC_Order $order, string $carrier, float $absorbed_cost, float $buyer_paid, int $vendor_id ): void {
        $order->update_meta_data( '_ltms_shipping_collected_from_buyer', $buyer_paid );
        $order->update_meta_data( '_ltms_shipping_charged_to_vendor',    $absorbed_cost );
        $order->update_meta_data( '_ltms_shipping_carrier',              $carrier );
        $order->update_meta_data( '_ltms_shipping_carrier_cost',         $absorbed_cost > 0 ? $absorbed_cost : $buyer_paid );
        $order->update_meta_data( '_ltms_shipping_vendor_id',            $vendor_id );
        $order->update_meta_data( '_ltms_shipping_reconciled',           0 );
        $order->save();
    }

    // =====================================================================
    // CSV PARSER — Helper para import de facturas
    // =====================================================================

    /**
     * Parsea un archivo CSV de factura del carrier.
     *
     * @param string $csv_path Ruta al archivo CSV.
     * @return array { invoice_data, lines }
     */
    public static function parse_carrier_invoice_csv( string $csv_path ): array {
        $result = [
            'invoice_data' => [],
            'lines'        => [],
            'errors'       => [],
        ];

        if ( ! file_exists( $csv_path ) || ! is_readable( $csv_path ) ) {
            $result['errors'][] = 'Archivo no existe o no es legible';
            return $result;
        }

        $handle = fopen( $csv_path, 'r' );
        if ( ! $handle ) {
            $result['errors'][] = 'No se pudo abrir el archivo';
            return $result;
        }

        $headers = fgetcsv( $handle );
        if ( ! $headers ) {
            fclose( $handle );
            $result['errors'][] = 'CSV vacío o sin cabeceras';
            return $result;
        }

        // Normalizar headers.
        $headers = array_map( fn( $h ) => strtolower( trim( $h ) ), $headers );

        // Buscar metadatos de factura en las primeras filas (formato "key,value").
        // Si la primera columna de la primera fila es un campo conocido, asumir que
        // las cabeceras son normales y procesar líneas directamente.
        $invoice_keys = [ 'invoice_number', 'invoice_date', 'period_start', 'period_end', 'total_amount', 'currency', 'tax_amount' ];
        $has_invoice_meta = array_intersect( $headers, $invoice_keys );

        if ( empty( $has_invoice_meta ) ) {
            // Formato "key,value" en filas iniciales.
            rewind( $handle );
            $invoice_data = [];
            $line_num = 0;
            while ( ( $row = fgetcsv( $handle ) ) !== false ) {
                $line_num++;
                if ( count( $row ) < 2 ) continue;
                $key = strtolower( trim( $row[0] ) );
                $val = trim( $row[1] );
                if ( in_array( $key, $invoice_keys, true ) ) {
                    $invoice_data[ $key ] = $val;
                } elseif ( $key === 'lines_start' || $key === 'headers' ) {
                    // Siguiente fila son las cabeceras de líneas.
                    $headers = fgetcsv( $handle );
                    $headers = array_map( fn( $h ) => strtolower( trim( $h ) ), $headers );
                    break;
                }
            }
            $result['invoice_data'] = $invoice_data;
        }

        // Procesar líneas.
        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            if ( count( $row ) < count( $headers ) ) {
                // Padding con nulls.
                $row = array_pad( $row, count( $headers ), null );
            }
            $line = array_combine( $headers, $row );
            if ( $line === false ) continue;

            $result['lines'][] = [
                'tracking_number'  => $line['tracking_number']  ?? $line['guia'] ?? '',
                'guide_number'     => $line['guide_number']     ?? $line['guia'] ?? '',
                'order_ref'        => $line['order_ref']        ?? $line['pedido'] ?? '',
                'origin_city'      => $line['origin_city']      ?? $line['origen'] ?? '',
                'destination_city' => $line['destination_city'] ?? $line['destino'] ?? '',
                'weight_kg'        => (float) ( $line['weight_kg'] ?? $line['peso_kg'] ?? 0 ),
                'billed_amount'    => (float) ( $line['billed_amount'] ?? $line['monto'] ?? $line['costo'] ?? 0 ),
                'tax_amount'       => (float) ( $line['tax_amount'] ?? $line['iva'] ?? 0 ),
                'total_amount'     => (float) ( $line['total_amount'] ?? $line['total'] ?? 0 ),
                'currency'         => $line['currency'] ?? $line['moneda'] ?? 'COP',
            ];
        }

        fclose( $handle );
        return $result;
    }
}
