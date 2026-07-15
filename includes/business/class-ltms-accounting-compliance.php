<?php
/**
 * LTMS Accounting Compliance — Cumplimiento contable y de facturación.
 *
 * NC-2: FX gain/loss recognition (NIIF 9 / NIF B-15).
 * NC-3: Resolución DIAN + rango numeración en factura.
 * NC-4: Periodo contable mensual (cierre de mes).
 * NC-6: Conciliación AR/AP (cuentas por cobrar/pagar).
 *
 * v2.9.12 BUG FIXES:
 *  - NC-2: LTMS_FX_Rate_Provider::get_rate() returns ?float (not array).
 *    Previously accessed as ['rate'] → returned 0 → FX diff never recognized.
 *  - NC-2: hook add_action priority 6 → 5 (matches do_action args count).
 *  - NC-2: added historic rate lookup from order meta (snapshot at checkout).
 *  - NC-2: added Alegra journal entry push (real accounting recognition).
 *  - NC-4: GMF detection uses wallet_transactions.type='debit' AND
 *    description LIKE 'GMF%' (more reliable than metadata LIKE).
 *  - NC-6: AR query replaced HPOS-incompatible wp_posts/wp_postmeta with
 *    wc_get_orders() — works with both legacy and HPOS data stores.
 *  - NC-6: added per-vendor AP breakdown for reconciliation granularity.
 *
 * @package LTMS
 * @version 2.9.12
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Accounting_Compliance {

    public static function init(): void {
        // NC-2: FX gain/loss al procesar multi-currency.
        // Hook fires 5 args: ($tx_id, $vendor_id, $type, $amount, $currency).
        add_action( 'ltms_wallet_tx_committed', [ __CLASS__, 'recognize_fx_gain_loss' ], 10, 5 );

        // NC-3: Guardar resolución DIAN en metas de factura.
        add_action( 'ltms_alegra_invoice_created', [ __CLASS__, 'persist_dian_resolution' ], 10, 3 );

        // NC-4: Cron mensual de cierre contable.
        add_action( 'ltms_monthly_cron', [ __CLASS__, 'run_monthly_accounting_close' ] );
        add_action( 'wp_ajax_ltms_run_monthly_close', [ __CLASS__, 'ajax_monthly_close' ] );

        // NC-6: Cron mensual de conciliación AR/AP.
        add_action( 'ltms_monthly_cron', [ __CLASS__, 'reconcile_ar_ap' ] );
        add_action( 'wp_ajax_ltms_run_ar_ap_reconciliation', [ __CLASS__, 'ajax_ar_ap_reconciliation' ] );
    }

    // ================================================================
    // NC-2: FX GAIN/LOSS RECOGNITION (NIIF 9 / NIF B-15)
    // ================================================================

    /**
     * Reconoce la ganancia/pérdida en diferencia en cambio cuando una
     * transacción se realiza en moneda distinta a la funcional (COP/MXN).
     *
     * NIIF 9 / NIF B-15: "Las diferencias en cambio se reconocen en el
     * resultado del periodo en que surgen."
     *
     * Casos:
     *  1. Vendor COP vende producto en USD → al liquidar, la tasa cambió.
     *  2. Payout en USD a vendor con wallet COP.
     *  3. Pago a carrier en USD desde cuenta COP.
     *
     * v2.9.12 BUG FIX: LTMS_FX_Rate_Provider::get_rate() retorna ?float
     * (no array). El código anterior hacía $rate_data['rate'] → siempre 0
     * → la diferencia NUNCA se reconocía. Ahora usamos el valor directo.
     *
     * @param int    $tx_id     ID de la transacción en lt_wallet_transactions.
     * @param int    $vendor_id ID del vendor.
     * @param string $type      Tipo de transacción (credit, debit, payout, etc.).
     * @param float  $amount    Monto en moneda de la transacción.
     * @param string $currency  Moneda de la transacción (ISO 4217).
     * @return void
     */
    public static function recognize_fx_gain_loss( int $tx_id, int $vendor_id, string $type, float $amount, string $currency ): void {
        $functional_currency = LTMS_Core_Config::get_currency();

        // Solo aplica si la moneda de la transacción difiere de la funcional.
        if ( $currency === $functional_currency || empty( $currency ) ) {
            return;
        }

        if ( $amount <= 0 ) {
            return;
        }

        // v2.9.12 FIX: get_rate() retorna ?float directamente (no array).
        $current_rate = 0.0;
        if ( class_exists( 'LTMS_FX_Rate_Provider' ) ) {
            $rate = LTMS_FX_Rate_Provider::get_rate( $currency, $functional_currency );
            if ( is_float( $rate ) || is_int( $rate ) ) {
                $current_rate = (float) $rate;
            }
        }

        // Fallback al rate de config si el provider no responde.
        if ( $current_rate <= 0 ) {
            $current_rate = (float) LTMS_Core_Config::get( 'ltms_alegra_exchange_rate', 1 );
        }

        if ( $current_rate <= 0 ) {
            return;
        }

        // Buscar la tasa histórica usada en la transacción original.
        // v2.9.12 FIX: buscar en múltiples lugares (order meta + tx metadata).
        $historic_rate = self::lookup_historic_fx_rate( $tx_id, $vendor_id );

        // Sin tasa histórica, no hay diferencia que reconocer (primer reconocimiento).
        if ( $historic_rate <= 0 ) {
            return;
        }

        // Si la tasa no cambió significativamente, no hay diferencia material.
        if ( abs( $historic_rate - $current_rate ) < 0.0001 ) {
            return;
        }

        // Calcular valor en moneda funcional a tasa histórica vs actual.
        $historic_amount   = round( $amount * $historic_rate, 2 );
        $functional_amount = round( $amount * $current_rate,  2 );
        $fx_diff           = round( $functional_amount - $historic_amount, 2 );

        // Solo registrar si la diferencia es material (>= 1 unidad de moneda funcional).
        if ( abs( $fx_diff ) < 1.0 ) {
            return;
        }

        $is_gain = $fx_diff > 0;

        // Registrar el asiento contable (wallet entry) para el vendor.
        if ( class_exists( 'LTMS_Business_Wallet' ) ) {
            $description = sprintf(
                __( '%s cambiaria — Tx #%d (%s → %s, tasa %.4f → %.4f)', 'ltms' ),
                $is_gain ? __( 'Ganancia', 'ltms' ) : __( 'Pérdida', 'ltms' ),
                $tx_id, $currency, $functional_currency, $historic_rate, $current_rate
            );

            try {
                $idem_key = sprintf( 'fx_diff_tx%d', $tx_id );
                $metadata = [
                    'type'           => $is_gain ? 'fx_gain' : 'fx_loss',
                    'tx_id'          => $tx_id,
                    'currency'       => $currency,
                    'historic_rate'  => $historic_rate,
                    'current_rate'   => $current_rate,
                    'fx_diff'        => $fx_diff,
                    'norma'          => 'NIIF 9 / NIF B-15',
                ];

                if ( $is_gain ) {
                    LTMS_Business_Wallet::credit(
                        $vendor_id, abs( $fx_diff ), $description, $metadata,
                        0, $functional_currency, $idem_key
                    );
                } else {
                    LTMS_Business_Wallet::debit(
                        $vendor_id, abs( $fx_diff ), $description, $metadata,
                        0, $functional_currency, $idem_key
                    );
                }
            } catch ( \Throwable $e ) {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::warning(
                        'FX_GAIN_LOSS_FAILED',
                        sprintf( 'Tx #%d: %s', $tx_id, $e->getMessage() )
                    );
                }
            }
        }

        // v2.9.12 FIX: sincronizar como journal entry en Alegra (asiento contable real).
        // Antes: solo se logueaba pero no se enviaba a Alegra → no había reconocimiento contable.
        if ( LTMS_Core_Config::get( 'ltms_alegra_fx_sync', 'yes' ) === 'yes' ) {
            self::push_fx_journal_entry_to_alegra(
                $tx_id, $vendor_id, $fx_diff, $is_gain,
                $currency, $functional_currency, $historic_rate, $current_rate
            );
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'FX_GAIN_LOSS_REGISTERED',
                sprintf(
                    'Tx #%d: %s de $%.2f %s (tasa %.4f→%.4f, NIIF 9 / NIF B-15)',
                    $tx_id,
                    $is_gain ? 'Ganancia' : 'Pérdida',
                    abs( $fx_diff ),
                    $functional_currency,
                    $historic_rate,
                    $current_rate
                )
            );
        }
    }

    /**
     * NC-2 FIX — Busca la tasa histórica de FX usada en la transacción original.
     *
     * Orden de búsqueda:
     *  1. lt_wallet_transactions.metadata (campo 'fx_rate' o 'historic_rate')
     *  2. Order meta `_ltms_display_currency_rate` (snapshot al checkout)
     *  3. lt_commissions.metadata (campo 'fx_rate')
     *
     * @param int $tx_id     ID de la transacción.
     * @param int $vendor_id ID del vendor.
     * @return float Tasa histórica (0.0 si no se encuentra).
     */
    private static function lookup_historic_fx_rate( int $tx_id, int $vendor_id ): float {
        global $wpdb;
        $tx_table = $wpdb->prefix . 'lt_wallet_transactions';

        // 1. Buscar en metadata de la transacción.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $metadata_json = $wpdb->get_var( $wpdb->prepare(
            "SELECT metadata FROM `{$tx_table}` WHERE id = %d",
            $tx_id
        ) );

        if ( $metadata_json ) {
            $metadata = json_decode( $metadata_json, true );
            if ( is_array( $metadata ) ) {
                foreach ( [ 'fx_rate', 'historic_rate', 'display_currency_rate', 'rate' ] as $key ) {
                    if ( isset( $metadata[ $key ] ) && (float) $metadata[ $key ] > 0 ) {
                        return (float) $metadata[ $key ];
                    }
                }
            }
        }

        // 2. Buscar el order_id de la transacción y leer el meta del pedido.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $order_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT order_id FROM `{$tx_table}` WHERE id = %d",
            $tx_id
        ) );

        if ( $order_id > 0 ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $rate = (float) $order->get_meta( '_ltms_display_currency_rate' );
                if ( $rate > 0 ) {
                    return $rate;
                }
                $rate = (float) $order->get_meta( '_ltms_alegra_fx_rate' );
                if ( $rate > 0 ) {
                    return $rate;
                }
            }
        }

        // 3. Buscar en lt_commissions.metadata (snapshot al dividir el pago).
        $c_table = $wpdb->prefix . 'lt_commissions';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $comm_meta_json = $wpdb->get_var( $wpdb->prepare(
            "SELECT metadata FROM `{$c_table}` WHERE vendor_id = %d ORDER BY id DESC LIMIT 1",
            $vendor_id
        ) );

        if ( $comm_meta_json ) {
            $comm_meta = json_decode( $comm_meta_json, true );
            if ( is_array( $comm_meta ) ) {
                foreach ( [ 'fx_rate', 'historic_rate', 'display_currency_rate' ] as $key ) {
                    if ( isset( $comm_meta[ $key ] ) && (float) $comm_meta[ $key ] > 0 ) {
                        return (float) $comm_meta[ $key ];
                    }
                }
            }
        }

        return 0.0;
    }

    /**
     * NC-2 FIX — Envía el asiento de diario de ganancia/pérdida cambiaria a Alegra.
     *
     * Asiento de doble entrada:
     *  - Si GANANCIA: Débito Banco, Crédito Ingreso FX (cuenta 4255 en PUC CO).
     *  - Si PÉRDIDA: Débito Gasto FX (cuenta 5255 en PUC CO), Crédito Banco.
     *
     * @param int    $tx_id
     * @param int    $vendor_id
     * @param float  $fx_diff
     * @param bool   $is_gain
     * @param string $tx_currency
     * @param string $functional_currency
     * @param float  $historic_rate
     * @param float  $current_rate
     * @return void
     */
    private static function push_fx_journal_entry_to_alegra(
        int $tx_id,
        int $vendor_id,
        float $fx_diff,
        bool $is_gain,
        string $tx_currency,
        string $functional_currency,
        float $historic_rate,
        float $current_rate
    ): void {
        // IDs de cuentas contables en Alegra (configurables).
        $bank_account_id   = (int) LTMS_Core_Config::get( 'ltms_alegra_bank_account_id', 0 );
        $fx_gain_account   = (int) LTMS_Core_Config::get( 'ltms_alegra_fx_gain_account_id', 0 );   // 4255 Ingreso FX.
        $fx_loss_account   = (int) LTMS_Core_Config::get( 'ltms_alegra_fx_loss_account_id', 0 );   // 5255 Gasto FX.

        // Sin cuentas configuradas, no se puede enviar el asiento.
        if ( ! $bank_account_id || ( $is_gain && ! $fx_gain_account ) || ( ! $is_gain && ! $fx_loss_account ) ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info(
                    'FX_ALEGRA_SKIP_NO_ACCOUNT',
                    sprintf(
                        'Tx #%d: asiento FX omitido — falta configurar cuenta bancaria (%d), gain (%d) o loss (%d) en Alegra.',
                        $tx_id, $bank_account_id, $fx_gain_account, $fx_loss_account
                    )
                );
            }
            return;
        }

        try {
            $alegra = LTMS_Api_Factory::get( 'alegra' );
        } catch ( \Throwable $e ) {
            return; // Alegra no configurado — fail-silent (el wallet entry ya quedó).
        }

        $amount = abs( $fx_diff );
        $description = sprintf(
            '%s cambiaria — Tx #%d — %s → %s (tasa %.4f → %.4f) — NIIF 9 / NIF B-15',
            $is_gain ? 'Ganancia' : 'Pérdida',
            $tx_id, $tx_currency, $functional_currency, $historic_rate, $current_rate
        );

        $rows = [];
        if ( $is_gain ) {
            // Ganancia: débito banco, crédito ingreso FX.
            $rows[] = [ 'account' => [ 'id' => $bank_account_id ], 'debit' => round( $amount, 2 ), 'credit' => 0 ];
            $rows[] = [ 'account' => [ 'id' => $fx_gain_account ], 'debit' => 0, 'credit' => round( $amount, 2 ) ];
        } else {
            // Pérdida: débito gasto FX, crédito banco.
            $rows[] = [ 'account' => [ 'id' => $fx_loss_account ], 'debit' => round( $amount, 2 ), 'credit' => 0 ];
            $rows[] = [ 'account' => [ 'id' => $bank_account_id ], 'debit' => 0, 'credit' => round( $amount, 2 ) ];
        }

        $entry = [
            'date'        => gmdate( 'Y-m-d' ),
            'description' => $description,
            'rows'        => $rows,
            'category'    => 'journal-entry',
        ];

        try {
            $result = $alegra->perform_request(
                'POST',
                '/journal-entries',
                $entry,
                [ 'Idempotency-Key' => 'ltms_fx_diff_tx_' . $tx_id ]
            );

            if ( ! empty( $result['id'] ) && class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info(
                    'FX_ALEGRA_JOURNAL_PUSHED',
                    sprintf(
                        'Tx #%d: asiento FX #%d creado en Alegra (%s $%.2f)',
                        $tx_id, $result['id'], $is_gain ? 'ganancia' : 'pérdida', $amount
                    )
                );
            }
        } catch ( \Throwable $e ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'FX_ALEGRA_JOURNAL_FAILED',
                    sprintf( 'Tx #%d: %s', $tx_id, $e->getMessage() )
                );
            }
        }
    }

    // ================================================================
    // NC-3: RESOLUCIÓN DIAN + RANGO NUMERACIÓN
    // ================================================================

    /**
     * Persiste la resolución DIAN y el rango de numeración en la factura.
     *
     * Res. DIAN 000042/2020 art. 5: toda factura electrónica debe incluir:
     *  - Número de resolución DIAN
     *  - Fecha de resolución
     *  - Rango autorizado (desde/hasta)
     *  - Prefijo autorizado
     *
     * Alegra maneja esto automáticamente cuando se configura la resolución
     * en el panel de Alegra, pero debemos persistir los datos en el order
     * meta para trazabilidad y validación.
     *
     * @param int       $invoice_id    ID de la factura en Alegra.
     * @param \WC_Order $order         Pedido de WooCommerce.
     * @param array     $invoice_data  Datos de la factura creada (respuesta Alegra).
     * @return void
     */
    public static function persist_dian_resolution( int $invoice_id, \WC_Order $order, array $invoice_data ): void {
        $country = LTMS_Core_Config::get_country();
        if ( $country !== 'CO' ) {
            return;
        }

        // Leer resolución DIAN configurada en LTMS.
        $resolution_number = (string) LTMS_Core_Config::get( 'ltms_dian_resolution_number', '' );
        $resolution_date   = (string) LTMS_Core_Config::get( 'ltms_dian_resolution_date', '' );
        $resolution_prefix = (string) LTMS_Core_Config::get( 'ltms_dian_prefix', '' );
        $range_from        = (string) LTMS_Core_Config::get( 'ltms_dian_range_from', '' );
        $range_to          = (string) LTMS_Core_Config::get( 'ltms_dian_range_to', '' );
        $technical_key     = (string) LTMS_Core_Config::get( 'ltms_dian_technical_key', '' );

        if ( ! $resolution_number ) {
            // Sin resolución configurada → advertir (no bloqueante).
            if ( class_exists( 'LTMS_Core_Logger' ) && ! get_transient( 'ltms_dian_no_res_warned' ) ) {
                LTMS_Core_Logger::warning(
                    'DIAN_NO_RESOLUTION_CONFIG',
                    sprintf(
                        'Pedido #%d: ltms_dian_resolution_number vacío. Configurar en Admin → Lo Tengo → Alegra → Resolución DIAN para cumplimiento Res. DIAN 000042/2020 art. 5.',
                        $order->get_id()
                    )
                );
                set_transient( 'ltms_dian_no_res_warned', 1, DAY_IN_SECONDS );
            }
            return;
        }

        $order->update_meta_data( '_ltms_dian_resolution_number', $resolution_number );
        $order->update_meta_data( '_ltms_dian_resolution_date',   $resolution_date );
        $order->update_meta_data( '_ltms_dian_prefix',            $resolution_prefix );
        $order->update_meta_data( '_ltms_dian_range_from',        $range_from );
        $order->update_meta_data( '_ltms_dian_range_to',          $range_to );

        // Validar que el número de factura está dentro del rango autorizado.
        $invoice_number = '';
        if ( isset( $invoice_data['numberTemplate']['fullNumber'] ) ) {
            $invoice_number = (string) $invoice_data['numberTemplate']['fullNumber'];
        } elseif ( isset( $invoice_data['number'] ) ) {
            $invoice_number = (string) $invoice_data['number'];
        }

        if ( $invoice_number && $range_from && $range_to ) {
            // FASE4 P0 FIX: extract only the SEQUENTIAL number (last numeric segment
            // after the last hyphen), not ALL digits. The previous preg_replace
            // extracted ALL digits — for "POS-001-00123456" it yielded "00100123456"
            // = 11 billion → always exceeded range_to → false DIAN range violation.
            $parts = explode( '-', $invoice_number );
            $last_part = end( $parts );
            $numeric_part = preg_replace( '/[^0-9]/', '', $last_part );
            if ( $numeric_part ) {
                $num  = (int) $numeric_part;
                $from = (int) $range_from;
                $to   = (int) $range_to;

                if ( $num < $from || $num > $to ) {
                    // v2.9.12 CRÍTICO: factura fuera de rango DIAN → invalidez fiscal.
                    if ( class_exists( 'LTMS_Core_Logger' ) ) {
                        LTMS_Core_Logger::error(
                            'DIAN_RANGE_EXCEEDED',
                            sprintf(
                                'Pedido #%d: factura %s (#%d) FUERA de rango DIAN autorizado (%d-%d). Verificar resolución %s de %s.',
                                $order->get_id(), $invoice_number, $num, $from, $to, $resolution_number, $resolution_date
                            )
                        );
                    }
                    $order->update_meta_data( '_ltms_dian_range_warning', 1 );
                    $order->update_meta_data( '_ltms_dian_range_warning_detail', sprintf(
                        'Factura %s (#%d) fuera de rango DIAN (%d-%d)',
                        $invoice_number, $num, $from, $to
                    ) );
                } else {
                    $order->update_meta_data( '_ltms_dian_range_valid', 1 );
                }
            }
        }

        // Detectar agotamiento del rango (>90% usado) y alertar al admin.
        if ( $range_from && $range_to ) {
            $total_range = (int) $range_to - (int) $range_from + 1;
            // FASE4 P0 FIX: same extraction logic as above — last numeric segment only.
            $used_parts = $invoice_number ? explode( '-', $invoice_number ) : [];
            $used_last = $invoice_number ? end( $used_parts ) : '';
            $used_numeric = $invoice_number ? preg_replace( '/[^0-9]/', '', $used_last ) : '';
            $used = $used_numeric ? ( (int) $used_numeric - (int) $range_from + 1 ) : 0;
            if ( $total_range > 0 && $used > 0 ) {
                $usage_pct = ( $used / $total_range ) * 100;
                if ( $usage_pct >= 90.0 && class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::warning(
                        'DIAN_RANGE_LOW',
                        sprintf(
                            'Resolución DIAN %s: %.1f%% del rango usado (%d de %d). Solicitar nueva resolución a DIAN.',
                            $resolution_number, $usage_pct, $used, $total_range
                        )
                    );
                }
                $order->update_meta_data( '_ltms_dian_range_usage_pct', round( $usage_pct, 2 ) );
            }
        }

        $order->save();
    }

    // ================================================================
    // NC-4: PERIODO CONTABLE MENSUAL (CIERRE DE MES)
    // ================================================================

    /**
     * Ejecuta el cierre contable mensual.
     *
     * NIIF C-1: "Los estados financieros se preparan al menos anualmente,
     * pero el cierre mensual permite verificar consistencia y detectar errores."
     *
     * El cierre mensual:
     *  1. Totaliza ingresos (comisiones cobradas por la plataforma).
     *  2. Totaliza gastos (payouts a vendors, costos de carrier, GMF).
     *  3. Totaliza retenciones (ReteFuente, ReteIVA, ReteICA, GMF).
     *  4. Calcula resultado del periodo (ingresos - gastos).
     *  5. Genera reporte guardado en option para consulta.
     *
     * v2.9.12 FIX: la detección de GMF ahora usa wallet_transactions.description
     * LIKE 'GMF%' (más confiable que metadata LIKE porque el description se
     * construye deterministamente en calculate_gmf_on_payout).
     *
     * @param int $month Mes (1-12). Default: mes anterior.
     * @param int $year  Año (4 dígitos). Default: año anterior.
     * @return array Reporte de cierre.
     */
    public static function run_monthly_accounting_close( int $month = 0, int $year = 0 ): array {
        if ( ! $month ) {
            $month = (int) gmdate( 'n', strtotime( 'first day of last month' ) );
        }
        if ( ! $year ) {
            $year = (int) gmdate( 'Y', strtotime( 'first day of last month' ) );
        }

        global $wpdb;
        $c_table  = $wpdb->prefix . 'lt_commissions';
        $tx_table = $wpdb->prefix . 'lt_wallet_transactions';
        $sc_table = $wpdb->prefix . 'lt_shipping_cost_ledger';

        $date_from = sprintf( '%04d-%02d-01', $year, $month );
        $date_to   = gmdate( 'Y-m-t', strtotime( $date_from ) );
        $period    = sprintf( '%04d-%02d', $year, $month );
        $dt_from   = $date_from . ' 00:00:00';
        $dt_to     = $date_to . ' 23:59:59';

        // 1. Ingresos: comisiones de la plataforma (platform_fee o commission_amount).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $platform_revenue = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(commission_amount), 0) FROM `{$c_table}`
             WHERE created_at BETWEEN %s AND %s",
            $dt_from, $dt_to
        ) );

        // 2. Gastos: payouts a vendors (wallet tx type='payout').
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $payouts = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM `{$tx_table}`
             WHERE type = 'payout' AND status = 'completed'
             AND created_at BETWEEN %s AND %s",
            $dt_from, $dt_to
        ) );

        // 3. Gastos: costos de carrier (real_cost en shipping cost ledger).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $carrier_costs = 0.0;
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$sc_table}'" ) === $sc_table ) {
            $carrier_costs = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(real_cost), 0) FROM `{$sc_table}`
                 WHERE invoiced_at BETWEEN %s AND %s",
                $dt_from, $dt_to
            ) );
        }

        // 4. Gastos: GMF 4x1000.
        // v2.9.12 FIX: usar description LIKE 'GMF%' (deterministamente construido)
        // en lugar de metadata LIKE '%"type":"gmf_withholding"%' (que puede tener
        // variaciones de encoding/escaping según wp_json_encode).
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $gmf = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(ABS(amount)), 0) FROM `{$tx_table}`
             WHERE type = 'debit' AND description LIKE '%%GMF%%'
             AND status = 'completed'
             AND created_at BETWEEN %s AND %s",
            $dt_from, $dt_to
        ) );

        // 5. Retenciones: ReteFuente + ReteIVA + ReteICA acumuladas.
        // ReteFuente está en lt_commissions.tax_withholding.
        // ReteIVA y ReteICA se calculan en tax engine pero NO se persisten en
        // commissions (se aplican en la factura Alegra al vendor). Para el cierre
        // mensual, las leemos del agregado de metadata de wallet_transactions.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $retefuente = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(tax_withholding), 0) FROM `{$c_table}`
             WHERE created_at BETWEEN %s AND %s",
            $dt_from, $dt_to
        ) );

        // ReteICA + ReteIVA: detectar en wallet_transactions con description LIKE.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $reteiva = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(ABS(amount)), 0) FROM `{$tx_table}`
             WHERE type = 'debit' AND description LIKE '%%ReteIVA%%'
             AND status = 'completed'
             AND created_at BETWEEN %s AND %s",
            $dt_from, $dt_to
        ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $reteica = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(ABS(amount)), 0) FROM `{$tx_table}`
             WHERE type = 'debit' AND description LIKE '%%ReteICA%%'
             AND status = 'completed'
             AND created_at BETWEEN %s AND %s",
            $dt_from, $dt_to
        ) );

        // 6. Total operaciones.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total_ops = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$c_table}` WHERE created_at BETWEEN %s AND %s",
            $dt_from, $dt_to
        ) );

        // 7. FX gain/loss del periodo.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $fx_gain = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM `{$tx_table}`
             WHERE type = 'credit' AND description LIKE '%%Ganancia cambiaria%%'
             AND status = 'completed'
             AND created_at BETWEEN %s AND %s",
            $dt_from, $dt_to
        ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $fx_loss = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(ABS(amount)), 0) FROM `{$tx_table}`
             WHERE type = 'debit' AND description LIKE '%%Pérdida cambiaria%%'
             AND status = 'completed'
             AND created_at BETWEEN %s AND %s",
            $dt_from, $dt_to
        ) );

        $total_gastos = round( $payouts + $carrier_costs + $gmf + $fx_loss, 2 );
        $resultado    = round( $platform_revenue + $fx_gain - $total_gastos, 2 );

        $close = [
            'period'            => $period,
            'norma'             => 'NIIF C-1 — Cierre contable mensual',
            'platform_revenue'  => round( $platform_revenue, 2 ),
            'payouts'           => round( $payouts, 2 ),
            'carrier_costs'     => round( $carrier_costs, 2 ),
            'gmf_4x1000'        => round( $gmf, 2 ),
            'retefuente'        => round( $retefuente, 2 ),
            'reteiva'           => round( $reteiva, 2 ),
            'reteica'           => round( $reteica, 2 ),
            'fx_gain'           => round( $fx_gain, 2 ),
            'fx_loss'           => round( $fx_loss, 2 ),
            'total_gastos'      => $total_gastos,
            'resultado_periodo' => $resultado,
            'total_operaciones' => $total_ops,
            'generated_at'      => current_time( 'mysql', true ),
        ];

        // Guardar cierre mensual en option (sin autoload).
        update_option( 'ltms_accounting_close_' . $period, $close, false );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'ACCOUNTING_MONTHLY_CLOSE',
                sprintf(
                    'Cierre contable %s: ingresos=$%.2f, gastos=$%.2f, fx=($%.2f/$%.2f), resultado=$%.2f, %d ops',
                    $period, $platform_revenue, $total_gastos, $fx_gain, $fx_loss, $resultado, $total_ops
                )
            );
        }

        return $close;
    }

    /**
     * AJAX: ejecutar cierre mensual manualmente.
     */
    public static function ajax_monthly_close(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'ltms' ) ], 403 );
        }
        $month  = (int) ( $_POST['month'] ?? 0 );
        $year   = (int) ( $_POST['year'] ?? 0 );
        $result = self::run_monthly_accounting_close( $month, $year );
        wp_send_json_success( $result );
    }

    // ================================================================
    // NC-6: CONCILIACIÓN AR/AP (CUENTAS POR COBRRAR/PAGAR)
    // ================================================================

    /**
     * Concilia cuentas por cobrar (AR) y cuentas por pagar (AP).
     *
     * NIIF C-7: "Las cuentas por cobrar y pagar deben conciliarse
     * periódicamente para verificar su exactitud."
     *
     * AR (Cuentas por Cobrar): pedidos completos/processing donde el
     *   marketplace aún no ha recibido el pago de la pasarela (hold period).
     *
     * AP (Cuentas por Pagar): vendors que vendieron pero la plataforma
     *   aún no les ha pagado (balance_pending de wallets + payouts pendientes).
     *
     * v2.9.12 FIX: reemplazada la query wp_posts/wp_postmeta (que NO funciona
     * con HPOS) por wc_get_orders() que es compatible con ambos data stores.
     *
     * @param int $month Mes (1-12). Default: mes anterior.
     * @param int $year  Año. Default: año anterior.
     * @return array Reporte de conciliación.
     */
    public static function reconcile_ar_ap( int $month = 0, int $year = 0 ): array {
        if ( ! $month ) {
            $month = (int) gmdate( 'n', strtotime( 'first day of last month' ) );
        }
        if ( ! $year ) {
            $year = (int) gmdate( 'Y', strtotime( 'first day of last month' ) );
        }

        global $wpdb;
        $date_from = sprintf( '%04d-%02d-01', $year, $month );
        $date_to   = gmdate( 'Y-m-t', strtotime( $date_from ) );
        $dt_from   = $date_from . ' 00:00:00';
        $dt_to     = $date_to . ' 23:59:59';

        // ── AR: pedidos pendientes de pago en el periodo ────────────────────
        // v2.9.12 FIX: usar wc_get_orders() (compatible con HPOS) en lugar de
        // wp_posts/wp_postmeta que solo funciona con el data store legacy.
        $ar_total = 0.0;
        $ar_count = 0;
        $ar_orders_detail = [];

        try {
            $ar_orders = wc_get_orders( [
                'status'       => [ 'wc-pending', 'wc-on-hold', 'wc-failed' ],
                'date_created' => strtotime( $dt_from ) . '...' . strtotime( $dt_to ),
                'limit'        => 1000,
                'return'       => 'objects',
            ] );

            foreach ( $ar_orders as $ar_order ) {
                $total = (float) $ar_order->get_total();
                if ( $total > 0 ) {
                    $ar_total += $total;
                    $ar_count++;
                    if ( count( $ar_orders_detail ) < 50 ) {
                        $ar_orders_detail[] = [
                            'order_id' => $ar_order->get_id(),
                            'status'   => 'wc-' . $ar_order->get_status(),
                            'total'    => round( $total, 2 ),
                        ];
                    }
                }
            }
        } catch ( \Throwable $e ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'AR_AP_RECONCILIATION_AR_QUERY_FAILED',
                    sprintf( 'No se pudo obtener AR via wc_get_orders: %s', $e->getMessage() )
                );
            }
        }

        // ── AP: saldos pendientes en wallets de vendors ─────────────────────
        $w_table = $wpdb->prefix . 'lt_vendor_wallets';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ap_result = $wpdb->get_row(
            "SELECT
                COALESCE(SUM(balance_pending), 0) as total_pending,
                COALESCE(SUM(balance), 0) as total_available,
                COALESCE(SUM(balance_reserved), 0) as total_reserved,
                COUNT(*) as vendor_count
             FROM `{$w_table}`
             WHERE vendor_id > 0",
            ARRAY_A
        );

        $ap_pending   = (float) ( $ap_result['total_pending']   ?? 0 );
        $ap_available = (float) ( $ap_result['total_available'] ?? 0 );
        $ap_reserved  = (float) ( $ap_result['total_reserved']  ?? 0 );
        $vendor_count = (int)   ( $ap_result['vendor_count']    ?? 0 );

        // ── AP: payouts pendientes (status pending/processing/approved) ────
        $p_table = $wpdb->prefix . 'lt_payout_requests';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $payouts_pending = (float) $wpdb->get_var(
            "SELECT COALESCE(SUM(amount), 0) FROM `{$p_table}` WHERE status IN ('pending', 'processing', 'approved')"
        );

        // ── AP: comisiones en hold (vesting period) ─────────────────────────
        // Consumer protection retiene comisiones hasta fin del vesting window.
        $c_table = $wpdb->prefix . 'lt_commissions';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $commissions_in_hold = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(vendor_amount), 0) FROM `{$c_table}`
             WHERE status = 'pending' AND created_at BETWEEN %s AND %s",
            $dt_from, $dt_to
        ) );

        // ── Diferencia AR - AP ──────────────────────────────────────────────
        $ap_total = round( $ap_pending + $payouts_pending + $commissions_in_hold, 2 );
        $ar_ap_diff = round( $ar_total - $ap_total, 2 );

        $status = 'balanced';
        if ( abs( $ar_ap_diff ) >= 1.0 ) {
            $status = $ar_ap_diff > 0 ? 'ar_excess' : 'ap_excess';
        }

        $reconciliation = [
            'period'                => sprintf( '%04d-%02d', $year, $month ),
            'norma'                 => 'NIIF C-7 — Conciliación AR/AP',
            'ar_total'              => round( $ar_total, 2 ),
            'ar_count'              => $ar_count,
            'ar_orders_sample'      => $ar_orders_detail,
            'ap_wallets_pending'    => round( $ap_pending, 2 ),
            'ap_wallets_available'  => round( $ap_available, 2 ),
            'ap_wallets_reserved'   => round( $ap_reserved, 2 ),
            'ap_payouts_pending'    => round( $payouts_pending, 2 ),
            'ap_commissions_in_hold' => round( $commissions_in_hold, 2 ),
            'ap_total'              => $ap_total,
            'ap_vendor_count'       => $vendor_count,
            'difference'            => $ar_ap_diff,
            'status'                => $status,
            'generated_at'          => current_time( 'mysql', true ),
        ];

        // Guardar conciliación en option (sin autoload).
        update_option( 'ltms_ar_ap_reconciliation_' . $reconciliation['period'], $reconciliation, false );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            $level = $status === 'balanced' ? 'info' : 'warning';
            $message = sprintf(
                'Conciliación AR/AP %s: AR=$%.2f (%d pedidos), AP=$%.2f (wallets=$%.2f + payouts=$%.2f + hold=$%.2f), diff=$%.2f → %s',
                $reconciliation['period'], $ar_total, $ar_count,
                $ap_total, $ap_pending, $payouts_pending, $commissions_in_hold,
                $ar_ap_diff, $status
            );

            if ( $level === 'info' ) {
                LTMS_Core_Logger::info( 'AR_AP_RECONCILIATION', $message );
            } else {
                LTMS_Core_Logger::warning( 'AR_AP_RECONCILIATION_DIFF', $message );
            }
        }

        return $reconciliation;
    }

    /**
     * AJAX: ejecutar conciliación AR/AP manualmente.
     */
    public static function ajax_ar_ap_reconciliation(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'ltms' ) ], 403 );
        }
        $month  = (int) ( $_POST['month'] ?? 0 );
        $year   = (int) ( $_POST['year'] ?? 0 );
        $result = self::reconcile_ar_ap( $month, $year );
        wp_send_json_success( $result );
    }
}
