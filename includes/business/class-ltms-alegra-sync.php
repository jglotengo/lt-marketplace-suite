<?php
/**
 * LTMS Alegra Sync - Sincronización con Contabilidad Alegra
 *
 * Orquesta la sincronización bidireccional entre LTMS/WooCommerce y Alegra:
 *
 * 1. Pedido pagado → Crear factura en Alegra al cliente
 * 2. Registro de vendedor → Crear/actualizar contacto en Alegra (como proveedor)
 * 3. Retiro aprobado → Registrar egreso en Alegra (pago a proveedor)
 * 4. Reembolso WC → Nota de crédito en Alegra
 * 5. Productos → Sincronizar como items Alegra (lazy, al crear factura)
 * 6. Comisión de plataforma → Registrar como ingreso separado en Alegra
 * 7. Retención en la fuente → Incluida en factura como descuento/impuesto
 * 8. Pago de factura automático → Registrar pago al crear factura si está configurado
 *
 * IDs de Alegra almacenados en meta de WooCommerce/WP:
 *   Pedido:   _ltms_alegra_invoice_id, _ltms_alegra_invoice_status,
 *             _ltms_alegra_invoice_number, _ltms_alegra_credit_note_id,
 *             _ltms_alegra_payment_id, _ltms_alegra_synced_at
 *   Usuario:  _ltms_alegra_contact_id, _ltms_alegra_contact_type
 *   Producto: _ltms_alegra_item_id
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class LTMS_Alegra_Sync {

    use LTMS_Logger_Aware;

    // ── Constantes de configuración ───────────────────────────────

    /** IVA estándar Colombia DIAN → ID de impuesto en Alegra */
    private const TAX_MAP_CO = [
        19 => 4,  // IVA 19%
        5  => 2,  // IVA 5%
        0  => 1,  // Excluido/Exento
    ];

    /** IVA México → ID de impuesto en Alegra */
    private const TAX_MAP_MX = [
        16 => 5,  // IVA 16%
        8  => 6,  // IVA 8% (frontera)
        0  => 4,  // Exento
    ];

    // ── Boot ──────────────────────────────────────────────────────

    public static function init(): void {
        if ( LTMS_Core_Config::get( 'ltms_alegra_enabled', 'no' ) !== 'yes' ) {
            return;
        }

        $instance = new self();

        // Pedido completado → crear factura
        add_action( 'woocommerce_order_status_completed', [ $instance, 'on_order_completed' ], 20 );

        // También en 'processing' si está configurado
        if ( LTMS_Core_Config::get( 'ltms_alegra_invoice_on_processing', 'no' ) === 'yes' ) {
            add_action( 'woocommerce_order_status_processing', [ $instance, 'on_order_completed' ], 20 );
        }

        // Reembolso → nota de crédito
        add_action( 'woocommerce_order_refunded', [ $instance, 'on_order_refunded' ], 20, 2 );

        // Registro de vendedor → contacto Alegra (tipo proveedor)
        add_action( 'ltms_vendor_registered', [ $instance, 'on_vendor_registered' ], 20, 2 );

        // KYC aprobado → actualizar tipo de contacto si ya existe
        add_action( 'ltms_vendor_approved', [ $instance, 'on_vendor_approved' ], 20 );

        // Payout completado → registrar egreso en Alegra.
        // FU2 FIX (v2.9.1): aceptar 3 args (vendor_id, amount, payout_id).
        add_action( 'ltms_payout_completed', [ $instance, 'on_payout_completed' ], 20, 3 );

        // 60-C — Donations: registrar asiento contable (gasto) y transferencia bancaria.
        // 'ltms_donation_credited' dispara cuando el motor de donaciones (Task 60-B)
        // acredita una donación individual a la wallet de la fundación.
        // 'ltms_donation_payout_completed' dispara cuando se ejecuta el batch de
        // transferencia bancaria mensual/trimestral a la fundación.
        add_action( 'ltms_donation_credited', [ $instance, 'on_donation_credited' ], 10, 4 );
        add_action( 'ltms_donation_payout_completed', [ $instance, 'on_donation_payout_completed' ], 10, 3 );

        // v3.1.0 — Cross-Border motor (Task 63-D): listen for cross-border
        // orders so we can add customs duties as a separate Alegra line item
        // and annotate the FX conversion (display → base currency). The
        // primary invoice is still created by on_order_completed above; this
        // listener only enriches the existing invoice with cross-border
        // metadata (it does NOT create a second invoice).
        add_action( 'ltms_cross_border_order', [ $instance, 'on_cross_border_order' ], 10, 4 );

        // Cron de reintentos para facturas fallidas
        add_action( 'ltms_alegra_retry_failed', [ $instance, 'retry_failed_invoices' ] );
    }

    // ── Handlers de eventos ───────────────────────────────────────

    /**
     * Pedido completado/processing → crear factura en Alegra.
     */
    public function on_order_completed( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Idempotencia — evitar factura duplicada
        if ( $order->get_meta( '_ltms_alegra_invoice_id' ) ) {
            return;
        }

        try {
            $result = $this->create_invoice_for_order( $order );

            if ( ! empty( $result['id'] ) ) {
                $invoice_id     = (int) $result['id'];
                $invoice_number = $result['numberTemplate']['fullNumber'] ?? '#' . $invoice_id;
                $invoice_status = $result['status'] ?? 'open';

                $order->update_meta_data( '_ltms_alegra_invoice_id',     $invoice_id );
                $order->update_meta_data( '_ltms_alegra_invoice_status',  $invoice_status );
                $order->update_meta_data( '_ltms_alegra_invoice_number',  $invoice_number );
                $order->update_meta_data( '_ltms_alegra_synced_at',       current_time( 'mysql' ) );
                // M-63: guardar estado de timbre DIAN y CUFE para trazabilidad de facturación electrónica.
                if ( ! empty( $result['stamp']['status'] ) ) {
                    $order->update_meta_data( '_ltms_alegra_stamp_status', sanitize_text_field( $result['stamp']['status'] ) );
                }
                if ( ! empty( $result['stamp']['cufe'] ) ) {
                    $order->update_meta_data( '_ltms_alegra_cufe', sanitize_text_field( $result['stamp']['cufe'] ) );
                }
                $order->add_order_note(
                    sprintf( __( '📄 Factura Alegra creada: %s', 'ltms' ), $invoice_number )
                );
                $order->save();

                $this->log_info(
                    'alegra_invoice_created',
                    sprintf( 'Factura Alegra #%d (%s) para pedido WC #%d', $invoice_id, $invoice_number, $order_id )
                );

                // NC-3 FIX (v2.9.12): disparar action para que los listeners
                // (LTMS_Accounting_Compliance::persist_dian_resolution) puedan
                // persistir la resolución DIAN + rango de numeración en el order
                // meta para cumplimiento Res. DIAN 000042/2020 art. 5.
                //
                // ANTES: el hook estaba registrado pero NUNCA se disparaba →
                // persist_dian_resolution nunca se ejecutaba → las facturas no
                // tenían trazabilidad de resolución DIAN en el order meta.
                do_action( 'ltms_alegra_invoice_created', $invoice_id, $order, $result );

                // ── A. Registrar pago automático si está configurado ─────────
                if ( LTMS_Core_Config::get( 'ltms_alegra_auto_payment', 'no' ) === 'yes' ) {
                    $this->register_invoice_payment( $invoice_id, $order );
                }

                // ── B. Enviar factura por email ──────────────────────────────
                if ( LTMS_Core_Config::get( 'ltms_alegra_send_invoice_email', 'no' ) === 'yes' ) {
                    $this->maybe_send_invoice_email( $invoice_id, $order );
                }

                // ── C. M-201: la factura YA es de comisión al vendedor (no del total al comprador).
                // register_platform_commission queda como legacy/dead-code; no llamar para evitar duplicar
                // el ingreso en Alegra. Se mantiene la función para rollback si hace falta.
            } else {
                // AL-5 FIX (AUDIT-AL): 200 OK de Alegra PERO sin `id` en el
                // body. Esto ocurre cuando Alegra devuelve un 200 con un
                // cuerpo de error (ej.: `{"code": 400, "message": "..."}`),
                // un cuerpo vacío, o un cuerpo con estructura inesperada.
                // Antes: el código caía silenciosamente al final del try
                // sin guardar meta, sin flag para reintento, sin log →
                // retry_failed_invoices() nunca lo re-procesaba → factura
                // perdida en limbo. El meta `_ltms_alegra_invoice_id`
                // quedaba vacío así que el guard de idempotencia no barraba
                // re-llamadas manuales, pero el cron no las hacía.
                // Ahora: flag + log ERROR para que el cron reintente y el
                // admin pueda ver el body devuelto por Alegra.
                $body_preview = function_exists( 'wp_json_encode' )
                    ? substr( wp_json_encode( $result ), 0, 500 )
                    : '(no serializable)';
                $this->log_error(
                    'ALEGRA_INVOICE_NO_ID',
                    sprintf( 'Pedido #%d: Alegra respondió 200 pero sin id en el body. Posible error body: %s', $order_id, $body_preview ),
                    [ 'order_id' => $order_id, 'response' => $result ]
                );
                $order->update_meta_data( '_ltms_alegra_invoice_failed', 1 );
                $order->update_meta_data( '_ltms_alegra_invoice_error',
                    sprintf( '200 OK sin id — body: %s', $body_preview ) );
                $order->save();
            }
        } catch ( \Throwable $e ) {
            $this->log_error(
                'alegra_invoice_failed',
                sprintf( 'Error creando factura Alegra para pedido #%d: %s', $order_id, $e->getMessage() )
            );
            // Marcar para reintento vía cron
            $order->update_meta_data( '_ltms_alegra_invoice_failed', 1 );
            $order->update_meta_data( '_ltms_alegra_invoice_error',  $e->getMessage() );
            $order->save();
        }
    }

    /**
     * Reembolso WooCommerce → nota de crédito en Alegra.
     */
    public function on_order_refunded( int $order_id, int $refund_id ): void {
        // AUDIT-FIX A1: usar HPOS-compatible get_meta, no get_post_meta
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $alegra_invoice_id = (int) $order->get_meta( '_ltms_alegra_invoice_id' );
        if ( ! $alegra_invoice_id ) {
            $this->log_info( 'alegra_sync', "Reembolso #{$refund_id}: sin factura Alegra para orden #{$order_id}" );
            return;
        }

        // Idempotencia: si ya existe nota de crédito para este reembolso, omitir
        if ( $order->get_meta( '_ltms_alegra_credit_note_' . $refund_id ) ) {
            return;
        }

        $refund = wc_get_order( $refund_id );
        if ( ! $refund ) {
            return;
        }

        $refund_amount = abs( (float) $refund->get_amount() );
        if ( $refund_amount <= 0 ) {
            return;
        }

        // AUDIT-FIX A2: usar LTMS_Api_Factory en vez de new LTMS_Api_Alegra() directamente
        try {
            $client = LTMS_Api_Factory::get( 'alegra' );
        } catch ( \RuntimeException $e ) {
            $this->log_warning( 'alegra_sync', 'Alegra no configurado — nota crédito omitida: ' . $e->getMessage() );
            return;
        }

        try {
            // AUDIT-FIX A3: incluir items reales del reembolso en la nota de crédito
            $credit_items = $this->prepare_refund_items( $refund, $order );

            $payload = [
                'invoice_id'   => $alegra_invoice_id,
                'amount'       => $refund_amount,
                'observations' => sprintf(
                    __( 'Reembolso WC #%d — Orden #%d — %s', 'ltms' ),
                    $refund_id,
                    $order_id,
                    $refund->get_reason() ?: __( 'Sin motivo especificado', 'ltms' )
                ),
                'items'        => $credit_items,
            ];

            // AL-4 FIX (AUDIT-AL): pasar Idempotency-Key PER-REFUND. El
            // código anterior no pasaba el parámetro $idempotency_key, así
            // que create_credit_note() derivaba el key como
            // `'ltms_credit_note_invoice_' . invoice_id` — IDENTICO para
            // todos los reembolsos de la misma factura. En reembolsos
            // parciales múltiples (común en WC: refund #1 por $50, refund #2
            // por $30), Alegra dedupeaba el segundo contra el primero y
            // DEVOLVÍA la misma credit-note #A en vez de crear #B → el
            // segundo reembolso ($30) NO se reflejaba en Alegra. El cliente
            // quedaba short-changed $30 en su contabilidad. El meta check
            // local (_ltms_alegra_credit_note_{refund_id}) NO detectaba el
            // problema porque el segundo result['id'] = #A (no vacío) →
            // guardaba _ltms_alegra_credit_note_{refund_id_2} = #A (un
            // ID duplicado engañoso).
            // El nuevo key es determinístico por (invoice_id, refund_id) →
            // Alegra dedupe solo retries del MISMO reembolso, no reembolsos
            // distintos de la misma factura.
            $idem_key = 'ltms_credit_note_refund_' . $refund_id . '_inv_' . $alegra_invoice_id;

            $result = $client->create_credit_note( $payload, $idem_key );

            if ( ! empty( $result['id'] ) ) {
                // Guardar por refund_id para idempotencia
                $order->update_meta_data( '_ltms_alegra_credit_note_' . $refund_id, $result['id'] );
                // También mantener el último (backward compat)
                $order->update_meta_data( '_ltms_alegra_credit_note_id', $result['id'] );
                $order->add_order_note(
                    sprintf( __( '📝 Nota de crédito Alegra #%d creada para reembolso #%d', 'ltms' ), $result['id'], $refund_id )
                );
                $order->save();
                $this->log_info( 'alegra_sync', "Nota crédito #{$result['id']} para reembolso #{$refund_id}" );
            } else {
                // AL-5 FIX (AUDIT-AL): 200 sin id → flag para reintento manual.
                $order->update_meta_data( '_ltms_alegra_credit_note_failed', 1 );
                $order->update_meta_data( '_ltms_alegra_credit_note_error',
                    sprintf( 'Reembolso #%d: respuesta Alegra sin id (200 OK con body inesperado)', $refund_id ) );
                $order->save();
                $this->log_warning( 'ALEGRA_CREDIT_NOTE_NO_ID',
                    sprintf( 'Reembolso #%d (order #%d): Alegra devolvió 200 sin id — body: %s', $refund_id, $order_id, wp_json_encode( $result ) )
                );
            }
        } catch ( \Throwable $e ) {
            $this->log_error( 'alegra_sync', 'Error nota de crédito: ' . $e->getMessage() );
            // AL-5 FIX (AUDIT-AL): flag para reintento manual.
            $order->update_meta_data( '_ltms_alegra_credit_note_failed', 1 );
            $order->update_meta_data( '_ltms_alegra_credit_note_error', $e->getMessage() );
            $order->save();
        }
    }

    /**
     * Registro de vendedor → crear contacto tipo 'provider' en Alegra.
     */
    public function on_vendor_registered( int $vendor_id, string $referral_code = '' ): void {
        if ( get_user_meta( $vendor_id, '_ltms_alegra_contact_id', true ) ) {
            return;
        }

        try {
            $contact_id = $this->sync_user_as_contact( $vendor_id, 'provider' );
            if ( $contact_id ) {
                $this->log_info( 'alegra_vendor_contact_created',
                    sprintf( 'Contacto Alegra #%d creado para vendedor #%d', $contact_id, $vendor_id ) );
            }
        } catch ( \Throwable $e ) {
            $this->log_warning( 'alegra_vendor_contact_failed',
                sprintf( 'No se pudo crear contacto Alegra para vendedor #%d: %s', $vendor_id, $e->getMessage() ) );
        }
    }

    /**
     * KYC aprobado → asegurar contacto actualizado con régimen correcto.
     * AUDIT-FIX A4: vendedores con RUT/NIT deben sincronizarse como COMMON_REGIME.
     */
    public function on_vendor_approved( int $vendor_id ): void {
        $existing_id = (int) get_user_meta( $vendor_id, '_ltms_alegra_contact_id', true );
        $nit         = get_user_meta( $vendor_id, 'ltms_nit', true )
                    ?: get_user_meta( $vendor_id, 'ltms_document_number', true );

        // Si tiene NIT, es contribuyente del régimen común
        if ( $nit && $existing_id ) {
            try {
                $client = LTMS_Api_Factory::get( 'alegra' );
                $client->perform_request( 'PUT', '/contacts/' . $existing_id, [
                    'regime'         => 'COMMON_REGIME',
                    'identification' => $nit,
                    'identificationObject' => [
                        'type'   => 'NIT',
                        'number' => preg_replace( '/\D/', '', $nit ),
                        'dv'     => null,
                    ],
                ] );
            } catch ( \Throwable $e ) {
                $this->log_warning( 'alegra_vendor_regime_update', $e->getMessage() );
            }
        } elseif ( ! $existing_id ) {
            $this->on_vendor_registered( $vendor_id );
        }
    }

    /**
     * Payout completado → registrar egreso en Alegra.
     * AUDIT-FIX A5: incluir el monto del pago en el payload.
     */
    /**
     * FU2 FIX (v2.9.1): aceptar payout_id como 3er argumento para idempotency correcta.
     * Antes: solo (vendor_id, amount) → key = vendor_id+amount+fecha → colisión
     * si 2 payouts al mismo vendor por el mismo monto en el mismo día.
     * Ahora: (vendor_id, amount, payout_id) → key único por payout.
     *
     * @param int   $vendor_id   ID del vendedor.
     * @param float $net_amount  Monto neto del payout.
     * @param int   $payout_id   ID del payout (0 si no está disponible, para compat).
     */
    public function on_payout_completed( int $vendor_id, float $net_amount, int $payout_id = 0 ): void {
        $bank_account_id   = (int) LTMS_Core_Config::get( 'ltms_alegra_bank_account_id', 0 );
        $alegra_contact_id = (int) get_user_meta( $vendor_id, '_ltms_alegra_contact_id', true );

        if ( ! $bank_account_id || ! $alegra_contact_id || $net_amount <= 0 ) {
            return;
        }

        // AL-3 FIX (AUDIT-AL): idempotency-key determinístico por
        // (vendor_id, net_amount, fecha). El código anterior NO pasaba
        // $idempotency_key a create_payment(), y como tampoco se pasa
        // 'invoice_id' en el payload, create_payment() derivaba el key
        // fallback como `'ltms_payment_invoice_0_out'` — IDENTICO para cada
        // payout a cada vendedor. Alegra dedupeaba por Idempotency-Key en
        // server-side → solo el PRIMER payout (al primer vendedor, en la
        // primera ejecución) quedaba registrado en Alegra. Todos los pagos
        // posteriores a cualquier vendedor recibían el mismo ID de vuelta
        // (dedupe) y NO se creaban en Alegra → egresos fantasma: la wallet
        // LTMS debitaba pero Alegra no reflejaba el egreso contable.
        //
        // No tenemos payout_id en este hook (ver AL-3a en worklog —
        // ltms_payout_completed firea con (vendor_id, amount) desde el
        // scheduler y con (payout_id, vendor_id) desde Openpay webhook).
        // Usamos (vendor_id, amount, fecha YYYY-MM-DD) como key. Dos payouts
        // al mismo vendedor por el mismo monto en el mismo día colisionarían
        // (dedupe incorrecto) — caso raro pero posible. Mitigado por el
        // meta check abajo: si ya existe un Alegra payment ID para este
        // key, se omite la llamada.
        // FU2 FIX (v2.9.1): si tenemos payout_id, usarlo en la key para garantizar
        // idempotency absoluta (sin colisión entre payouts del mismo vendor/monto/día).
        $payout_date = current_time( 'Y-m-d' );
        if ( $payout_id > 0 ) {
            $idem_key = 'ltms_payout_' . $payout_id;
        } else {
            // Fallback legacy: vendor_id + amount + fecha (puede colisionar en casos raros).
            $idem_key = 'ltms_vendor_payout_' . $vendor_id . '_' . md5( $net_amount . '_' . $payout_date );
        }
        $local_meta  = '_ltms_alegra_payout_' . md5( $idem_key );
        $existing_id = (int) get_user_meta( $vendor_id, $local_meta, true );
        if ( $existing_id > 0 ) {
            // Ya sincronizado — short-circuit idempotente (ahorra API call).
            $this->log_info( 'alegra_payout_skip',
                sprintf( 'Payout vendedor #%d ($%s) ya registrado en Alegra como pago #%d — skip.', $vendor_id, $net_amount, $existing_id ) );
            return;
        }

        try {
            $client  = LTMS_Api_Factory::get( 'alegra' );
            $payment = $client->create_payment( [
                'date'            => $payout_date,
                'bank_account_id' => $bank_account_id,
                'payment_method'  => 'transfer',
                'type'            => 'out',
                'client_id'       => $alegra_contact_id,
                'amount'          => round( $net_amount, 2 ),
                'observations'    => sprintf(
                    __( 'Pago comisiones a vendedor #%d — $%s COP — LTMS', 'ltms' ),
                    $vendor_id,
                    number_format( $net_amount, 0, '.', '.' )
                ),
            ], $idem_key );

            // Persistir el payment ID en user meta para idempotencia local
            // (evita re-llamar a la API en re-fire del action; el Idempotency-Key
            // igual deduparía server-side, pero esto ahorra una red round-trip).
            if ( ! empty( $payment['id'] ) ) {
                update_user_meta( $vendor_id, $local_meta, (int) $payment['id'] );
                $this->log_info( 'alegra_payout_registered',
                    sprintf( 'Egreso Alegra #%d — vendedor #%d — $%s', $payment['id'], $vendor_id, $net_amount ) );
            } else {
                // AL-5 (relacionado): 200 sin id → flag para revisión manual.
                $this->log_warning( 'alegra_payout_no_id',
                    sprintf( 'Alegra devolvió 200 sin id para payout vendedor #%d ($%s) — posible error body.', $vendor_id, $net_amount ) );
            }
        } catch ( \Throwable $e ) {
            $this->log_warning( 'alegra_payout_failed',
                sprintf( 'No se pudo registrar egreso Alegra para vendedor #%d: %s', $vendor_id, $e->getMessage() ) );
        }
    }

    /**
     * 60-C — Donación acreditada → asiento contable (gasto) en Alegra.
     *
     * Cuando el motor de donaciones (Task 60-B) acredita una donación a la wallet
     * de la fundación, se registra como un asiento de diario (journal entry) en
     * Alegra con doble entrada:
     *   - Débito:  cuenta de gasto "Donaciones" (ltms_donation_alegra_account_id)
     *   - Crédito: cuenta de banco/caja (ltms_alegra_bank_account_id)
     *
     * El ID del asiento se persiste en `lt_donations.alegra_entry_id` para
     * idempotencia (no se recrea el asiento si ya existe) y para conciliación.
     *
     * NOTE: el endpoint de Alegra para asientos contables es POST /journal-entries.
     * LTMS_Api_Alegra no expone aún un método create_journal_entry() dedicado, así
     * que se invoca el endpoint vía perform_request() (public en Abstract_API_Client).
     * Si una futura versión de class-ltms-api-alegra.php añade create_journal_entry(),
     * se debe reemplazar esta llamada por el método dedicado para ganancia de
     * validación de payload e idempotency-key.
     *
     * @param int    $donation_id ID de la fila en {prefix}lt_donations.
     * @param int    $order_id    ID del pedido WC que originó la donación.
     * @param float  $amount      Monto de la donación.
     * @param string $currency    Moneda (COP, MXN).
     * @return void
     */
    public function on_donation_credited( int $donation_id, int $order_id, float $amount, string $currency ): void {
        $account_id = (int) LTMS_Core_Config::get( 'ltms_donation_alegra_account_id', 0 );
        if ( ! $account_id || $amount <= 0 ) {
            return;
        }

        // INT-BUG-7 / Task 62-C: short-circuit if this donation has already been
        // synced to Alegra. Avoids a needless API call on every retry/re-fire of
        // `ltms_donation_credited` (the Idempotency-Key would dedupe at Alegra's
        // side anyway, but this saves a network round-trip).
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $existing_entry_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT alegra_entry_id FROM `{$wpdb->prefix}lt_donations` WHERE id = %d",
                $donation_id
            )
        );
        if ( $existing_entry_id > 0 ) {
            return; // Already synced — idempotent short-circuit.
        }

        // ltms_alegra_enabled ya está validado por init(), pero la acción puede
        // dispararse desde cualquier contexto (cron, webhook). Si init() no corrió
        // (porque Alegra está deshabilitado), la factory lanzará RuntimeException.
        try {
            $alegra = LTMS_Api_Factory::get( 'alegra' );
        } catch ( \Throwable $e ) {
            $this->log_warning( 'DONATION_ALEGRA_SKIP',
                sprintf( 'Alegra no disponible — donación #%d omitida: %s', $donation_id, $e->getMessage() )
            );
            return;
        }

        $bank_account_id = (int) LTMS_Core_Config::get( 'ltms_alegra_bank_account_id', 0 );

        // INT-BUG-4 / Task 62-C: Si no está configurado el bank_account_id, NO
        // incluir una fila de crédito con account.id=0 (Alegra la rechazaría con
        // "account ID 0 is invalid"). Se construye el asiento con una sola fila
        // (débito) y se loguea un WARNING para que el admin configure la cuenta
        // bancaria. Un asiento de una sola fila puede ser rechazado por Alegra
        // por descuadre (double-entry), pero al menos no falla por account.id=0.
        if ( ! $bank_account_id ) {
            $this->log_warning( 'DONATION_ALEGRA_BANK_MISSING',
                sprintf( 'No hay bank_account_id configurado (ltms_alegra_bank_account_id=0) — el asiento para donación #%d se enviará sin fila de crédito.', $donation_id )
            );
        }

        // Asiento contable (Alegra POST /journal-entries).
        // Payload adaptado al schema de Alegra: cada línea referencia una cuenta
        // por ID con debit/credit en valores absolutos. La suma de débitos debe
        // igualar la suma de créditos (double-entry).
        $rows = [
            // Débito: gasto Donaciones.
            [
                'account' => [ 'id' => $account_id ],
                'debit'   => round( $amount, 2 ),
                'credit'  => 0,
            ],
        ];

        // Crédito: Banco/Caja — solo se agrega si hay bank_account_id válido
        // (INT-BUG-4). Sin esto, Alegra rechazaría la fila con account.id=0.
        if ( $bank_account_id > 0 ) {
            $rows[] = [
                'account' => [ 'id' => $bank_account_id ],
                'debit'   => 0,
                'credit'  => round( $amount, 2 ),
            ];
        }

        $entry = [
            'date'        => gmdate( 'Y-m-d' ),
            'description' => sprintf(
                'Donación orden #%d — %s',
                $order_id,
                LTMS_Core_Config::get( 'ltms_donation_foundation_name', 'Fundación' )
            ),
            'rows'        => $rows,
        ];

        try {
            // perform_request() es público en LTMS_Abstract_API_Client.
            // Si la API Alegra añade create_journal_entry() en el futuro,
            // sustituir esta línea por: $result = $alegra->create_journal_entry( $entry );
            $result = $alegra->perform_request(
                'POST',
                '/journal-entries',
                $entry,
                [
                    // Idempotency-Key determinístico por donation_id — evita
                    // duplicar el asiento si el cron o el webhook reintentan.
                    'Idempotency-Key' => 'ltms_donation_' . $donation_id,
                ]
            );

            if ( ! empty( $result['id'] ) ) {
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update(
                    $wpdb->prefix . 'lt_donations',
                    [ 'alegra_entry_id' => (int) $result['id'] ],
                    [ 'id' => $donation_id ]
                );

                $this->log_info( 'DONATION_ALEGRA_SYNCED',
                    sprintf( 'Donación #%d sincronizada con Alegra (entry #%d)', $donation_id, $result['id'] )
                );
            }
        } catch ( \Throwable $e ) {
            // Fail-loud: loguear pero no propagar — la donación ya está acreditada
            // en la wallet de la fundación; la sincronización con Alegra es best-effort.
            // El administrador puede re-sincronizar manualmente desde el panel.
            $this->log_error( 'DONATION_ALEGRA_FAILED',
                sprintf( 'Error sincronizando donación #%d con Alegra: %s', $donation_id, $e->getMessage() ),
                [ 'donation_id' => $donation_id, 'order_id' => $order_id, 'amount' => $amount ]
            );
        }
    }

    /**
     * 60-C — Batch de pago a la fundación → transferencia bancaria en Alegra.
     *
     * Cuando el cron ltms_donation_payout_cron dispara el batch de transferencia
     * bancaria mensual/trimestral a la fundación (ltms_donation_payout_completed),
     * se registra como un egreso (payment 'out') en Alegra — mismo modelo que
     * on_payout_completed() para vendedores, pero con la fundación como contacto.
     *
     * La fundación debe existir como contacto Alegra (tipo provider). Su ID se
     * almacena en la opción ltms_donation_alegra_contact_id.
     *
     * @param int    $batch_id ID del batch de pago (tabla lt_donation_payouts).
     * @param float  $total    Monto total transferido a la fundación.
     * @param string $currency Moneda (COP, MXN).
     * @return void
     */
    public function on_donation_payout_completed( int $batch_id, float $total, string $currency ): void {
        $bank_account_id = (int) LTMS_Core_Config::get( 'ltms_alegra_bank_account_id', 0 );
        $contact_id      = (int) LTMS_Core_Config::get( 'ltms_donation_alegra_contact_id', 0 );

        if ( ! $bank_account_id || ! $contact_id || $total <= 0 ) {
            return;
        }

        try {
            $alegra = LTMS_Api_Factory::get( 'alegra' );
        } catch ( \Throwable $e ) {
            $this->log_warning( 'DONATION_PAYOUT_ALEGRA_SKIP',
                sprintf( 'Alegra no disponible — batch de donaciones #%d omitido: %s', $batch_id, $e->getMessage() )
            );
            return;
        }

        try {
            $payment = $alegra->create_payment( [
                'date'            => current_time( 'Y-m-d' ),
                'bank_account_id' => $bank_account_id,
                'payment_method'  => 'transfer',
                'type'            => 'out',
                'client_id'       => $contact_id,
                'amount'          => round( $total, 2 ),
                'observations'    => sprintf(
                    /* translators: 1: batch ID, 2: foundation name */
                    __( 'Transferencia batch donaciones #%1$d — %2$s — LTMS', 'ltms' ),
                    $batch_id,
                    LTMS_Core_Config::get( 'ltms_donation_foundation_name', 'Fundación' )
                ),
            ], 'ltms_donation_payout_' . $batch_id ); // INT-BUG-2 / Task 62-C: per-batch idempotency key.

            // INT-BUG-6 / Task 62-C: persist the Alegra payment ID on the batch
            // row so admins can cross-reference batches → Alegra payment records.
            // Before this fix, `lt_donation_payouts.alegra_entry_id` was always 0.
            $alegra_payment_id = (int) ( $payment['id'] ?? 0 );
            if ( $alegra_payment_id > 0 ) {
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->update(
                    $wpdb->prefix . 'lt_donation_payouts',
                    [ 'alegra_entry_id' => $alegra_payment_id ],
                    [ 'id' => $batch_id ]
                );
            }

            $this->log_info( 'DONATION_PAYOUT_ALEGRA_SYNCED',
                sprintf( 'Egreso Alegra #%d — batch donaciones #%d — $%s',
                    $payment['id'] ?? 0, $batch_id, number_format( $total, 2, '.', '.' ) )
            );
        } catch ( \Throwable $e ) {
            $this->log_error( 'DONATION_PAYOUT_ALEGRA_FAILED',
                sprintf( 'Error registrando batch de donaciones #%d en Alegra: %s', $batch_id, $e->getMessage() ),
                [ 'batch_id' => $batch_id, 'total' => $total, 'currency' => $currency ]
            );
        }
    }

    /**
     * Reintenta crear facturas que fallaron (ejecutado por cron ltms_alegra_retry_failed).
     * AUDIT-FIX A6: mecanismo de reintento para facturas no creadas.
     */
    public function retry_failed_invoices(): void {
        $failed_orders = wc_get_orders( [
            'meta_key'     => '_ltms_alegra_invoice_failed',
            'meta_value'   => '1',
            'meta_compare' => '=',
            'limit'        => 20,
            'status'       => [ 'wc-completed', 'wc-processing' ],
        ] );

        foreach ( $failed_orders as $order ) {
            // Limpiar flag antes de reintentar para evitar loop si falla de nuevo
            $order->delete_meta_data( '_ltms_alegra_invoice_failed' );
            $order->save();
            $this->on_order_completed( $order->get_id() );
        }
    }

    // ── Lógica de negocio principal ───────────────────────────────

    /**
     * Crea la factura de COMISIÓN del marketplace para un pedido WooCommerce.
     *
     * Modelo legal (M-201): el marketplace emite factura electrónica DIAN únicamente
     * por su comisión de intermediación, dirigida al VENDEDOR como cliente Alegra.
     * El vendedor emite por separado su propia factura del producto al comprador final
     * (modelo "cada vendedor factura propio" — fuera del scope LTMS).
     *
     * Si el pedido no tiene vendor_id o platform_fee > 0, no se crea factura.
     *
     * @param \WC_Order $order Pedido completado.
     * @return array Respuesta de la API Alegra (con id, numberTemplate, status).
     * @throws \RuntimeException
     */
    public function create_invoice_for_order( \WC_Order $order ): array {
        $client = LTMS_Api_Factory::get( 'alegra' );

        // ── Resolución del vendor_id ─────────────────────────────────────────
        // Orden de prioridad:
        //   1. Meta directa del pedido (_ltms_vendor_id)
        //   2. Meta de los productos del pedido (primer item con vendor)
        //   3. Releer desde BD si el objeto tiene caché stale (HPOS)
        $vendor_id = (int) $order->get_meta( '_ltms_vendor_id' );

        if ( ! $vendor_id ) {
            // Fallback 1: extraer del primer item (mismo patrón que Order_Split).
            foreach ( $order->get_items() as $item ) {
                $pid       = $item->get_product_id();
                $vendor_id = (int) get_post_meta( $pid, '_ltms_vendor_id', true );
                if ( $vendor_id ) {
                    break;
                }
            }
        }

        if ( ! $vendor_id ) {
            // Fallback 2: forzar relecture desde BD por si el objeto tiene caché stale.
            $fresh_order = wc_get_order( $order->get_id() );
            if ( $fresh_order ) {
                $vendor_id = (int) $fresh_order->get_meta( '_ltms_vendor_id' );
                if ( $vendor_id ) {
                    $order = $fresh_order; // usar objeto fresco para todo lo que sigue
                }
            }
        }

        if ( ! $vendor_id ) {
            throw new \RuntimeException(
                sprintf( '[AlegraSync] Pedido #%d sin vendor_id — no se factura comisión.', $order->get_id() )
            );
        }

        // ── Resolución de la comisión ────────────────────────────────────────
        $commission = (float) $order->get_meta( '_ltms_platform_fee' );

        if ( $commission <= 0 ) {
            // Fallback 1: leer de lt_commissions (camino de producción normal).
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $commission = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT commission_amount FROM `{$wpdb->prefix}lt_commissions` WHERE order_id = %d AND vendor_id = %d LIMIT 1",
                $order->get_id(), $vendor_id
            ) );
        }

        if ( $commission <= 0 ) {
            // Fallback 2: calcular la comisión sobre el total del pedido según la tasa configurada.
            $fee_pct    = (float) LTMS_Core_Config::get( 'ltms_platform_fee_pct', 0 );
            $commission = $fee_pct > 0
                ? round( (float) $order->get_total() * $fee_pct / 100, 2 )
                : 0.0;
        }

        if ( $commission <= 0 ) {
            throw new \RuntimeException(
                sprintf( '[AlegraSync] Pedido #%d sin platform_fee — no hay comisión para facturar.', $order->get_id() )
            );
        }

        $alegra_contact_id = $this->get_or_create_vendor_contact( $vendor_id );
        if ( ! $alegra_contact_id ) {
            throw new \RuntimeException(
                sprintf( '[AlegraSync] No se pudo obtener/crear contacto Alegra para vendedor #%d', $vendor_id )
            );
        }

        $invoice_items = $this->prepare_commission_items( $order, $commission );

        $invoice_data = [
            'date'         => $order->get_date_paid()
                ? $order->get_date_paid()->format( 'Y-m-d' )
                : current_time( 'Y-m-d' ),
            'due_date'     => current_time( 'Y-m-d' ),
            'client_id'    => $alegra_contact_id,
            'items'        => $invoice_items,
            'observations' => sprintf(
                /* translators: 1: WC order number, 2: vendor id */
                __( 'Comisión marketplace pedido WC #%1$s (vendedor #%2$d) — Lo Tengo', 'ltms' ),
                $order->get_order_number(),
                $vendor_id
            ),
        ];

        $template_id = (int) LTMS_Core_Config::get( 'ltms_alegra_default_number_template', 0 );
        if ( $template_id ) {
            $invoice_data['number_template_id'] = $template_id;
        }

        $currency = $order->get_currency();
        $base_currency = class_exists( 'LTMS_Currency_Manager' )
            ? LTMS_Currency_Manager::get_base_currency()
            : LTMS_Core_Config::get_currency();

        // v3.1.0 — Cross-Border motor (Task 63-D): multi-currency entries.
        //
        // When the order currency differs from the platform base currency, we
        // include the FX rate in the Alegra payload AND add an observation
        // note documenting the conversion ("Order in {currency} at rate {rate},
        // settled in {base_currency}"). This keeps the Alegra audit trail
        // consistent with the LTMS wallet ledger (which always credits the
        // vendor in the settlement currency).
        if ( $currency && $currency !== $base_currency ) {
            $invoice_data['currency'] = $currency;

            // Resolve the FX rate: prefer the snapshot stored on the order at
            // checkout time (most accurate), fall back to the live rate from
            // the FX provider, finally fall back to the legacy config value.
            $fx_rate = (float) $order->get_meta( '_ltms_display_currency_rate' );
            if ( $fx_rate <= 0 && class_exists( 'LTMS_FX_Rate_Provider' ) ) {
                $live_rate = LTMS_FX_Rate_Provider::get_rate( $base_currency, $currency );
                if ( $live_rate !== null ) {
                    $fx_rate = (float) $live_rate;
                }
            }
            if ( $fx_rate <= 0 ) {
                $fx_rate = (float) LTMS_Core_Config::get( 'ltms_alegra_exchange_rate', 1 );
            }
            $invoice_data['exchange_rate'] = $fx_rate;

            // Append a multi-currency note to the observations field.
            $fx_note = sprintf(
                /* translators: 1: order currency, 2: fx rate, 3: base currency */
                __( 'Order in %1$s at rate %2$.4f, settled in %3$s.', 'ltms' ),
                $currency,
                $fx_rate,
                $base_currency
            );
            $existing_obs = $invoice_data['observations'] ?? '';
            $invoice_data['observations'] = trim( $existing_obs . ' || ' . $fx_note );

            // Store the FX note on the order so downstream consumers (Alegra
            // webhook, accounting reports) can read it without recomputing.
            if ( ! $order->get_meta( '_ltms_alegra_fx_note' ) ) {
                $order->update_meta_data( '_ltms_alegra_fx_note', $fx_note );
                $order->update_meta_data( '_ltms_alegra_fx_rate', $fx_rate );
            }
        }

        // v3.1.0 — Cross-Border motor (Task 63-D): add customs duties as a
        // separate Alegra line item for cross-border orders. The duties were
        // already collected from the buyer (DDP) or are due on delivery (DDU);
        // either way, Alegra needs a line to track them for accounting.
        $customs_declaration = $order->get_meta( '_ltms_customs_declaration' );
        if ( is_array( $customs_declaration ) && ! empty( $customs_declaration['total_duties_taxes'] ) ) {
            $duty_amount = (float) $customs_declaration['total_duties_taxes'];
            if ( $duty_amount > 0 ) {
                $invoice_data['items'][] = [
                    'name'        => sprintf(
                        /* translators: 1: origin country, 2: destination country, 3: incoterm */
                        __( 'Customs duties — %1$s → %2$s (%3$s)', 'ltms' ),
                        $customs_declaration['origin_country'] ?? '',
                        $customs_declaration['destination_country'] ?? '',
                        $customs_declaration['incoterm'] ?? 'DDU'
                    ),
                    'description' => sprintf(
                        __( 'Duty: %s; VAT: %s; Fees: %s. Paid by: %s.', 'ltms' ),
                        $customs_declaration['duty_amount'] ?? 0,
                        $customs_declaration['vat_amount'] ?? 0,
                        $customs_declaration['customs_fee'] ?? 0,
                        $customs_declaration['paid_by'] ?? 'buyer'
                    ),
                    'price'       => round( $duty_amount, 2 ),
                    'quantity'    => 1,
                ];
            }
        }

        $invoice_data['anotation'] = 'WC-' . $order->get_order_number() . '-COMM';

        // RB-7 FIX (v2.9.19): Disparar filter ltms_alegra_invoice_payload para
        // que los listeners (CB-1 attach cert origin, LT-1 attach carta porte)
        // puedan adjuntar complementos al payload Alegra. Antes de este fix,
        // el payload se pasaba directamente a $client->create_invoice() sin
        // filter → CB-1 y LT-1 eran silent dead code desde v2.9.17.
        $invoice_data = apply_filters( 'ltms_alegra_invoice_payload', $invoice_data, $order );

        // AL-1 FIX (AUDIT-AL): usar $client->create_invoice() en vez del inline
        // build + reflection. El inline build anterior perdía:
        //   1. Header `Idempotency-Key` — Alegra no dedupaba reintentos cron.
        //      Si on_order_completed tenía éxito en Alegra (200 + id) pero el
        //      meta update posterior fallaba (DB lock, process kill, OOM), el
        //      meta `_ltms_alegra_invoice_id` quedaba vacío y el cron
        //      retry_failed_invoices() volvía a llamar on_order_completed →
        //      segunda factura creada en Alegra (sin Idempotency-Key no hay
        //      dedupe server-side). Factura duplicada → doble ingreso contable.
        //   2. Campos `currency` + `exchangeRate` — facturas multi-currency se
        //      registraban en COP al valor facial de la comisión, ocultando la
        //      conversión FX y la ganancia/pérdida cambiaria (NIIF 9 / NIF B-15).
        //   3. Campo `numberTemplate` — numeración corporativa no respetada.
        //   4. Sanitización de observations/anotation (skipped en inline build).
        // create_invoice() hace todo lo anterior y deriva el Idempotency-Key
        // del `anotation` (WC-{order_number}-COMM), determinístico por pedido.
        return $client->create_invoice( $invoice_data );
    }

    /**
     * Obtiene el contacto Alegra del vendedor, sincronizándolo si aún no existe.
     *
     * @param int $vendor_id ID del usuario vendedor.
     * @return int Contact ID en Alegra (0 si falló).
     */
    private function get_or_create_vendor_contact( int $vendor_id ): int {
        $cached = (int) get_user_meta( $vendor_id, '_ltms_alegra_contact_id', true );
        if ( $cached ) {
            return $cached;
        }
        // No estaba sincronizado — crearlo on-demand.
        return $this->sync_user_as_contact( $vendor_id, 'provider' );
    }

    /**
     * Construye los items de la factura de comisión (M-201).
     *
     * Una sola línea: "Comisión marketplace pedido #X" con monto = platform_fee.
     * IVA 19% sobre el servicio de intermediación (Colombia) — el comprador (vendedor)
     * paga IVA al marketplace y lo descuenta como gasto en su declaración.
     *
     * NC-1 FIX (v2.9.12) CRÍTICO FISCAL — Aplicar ReteIVA, ReteICA y ReteFuente a la
     * factura de comisión cuando el vendor sea gran contribuyente (CO) o persona moral
     * (MX). ANTES solo se aplicaba IVA, lo que subreportaba las retenciones que el
     * vendor (como comprador del servicio de intermediación) debe practicar al
     * marketplace. Esto es violación del ET art. 437-2 (ReteIVA) y del régimen
     * municipal de ICA (ReteICA).
     *
     * Modelo legal:
     *  - Marketplace EMITE factura de comisión + IVA al vendor.
     *  - Vendor (si es gran contribuyente/autorretenedor en CO) PRACTICA retenciones:
     *      * ReteIVA = 15% del IVA (ET art. 437-2)
     *      * ReteICA = tasa municipal sobre la base (Estatuto municipal, varía 0.4-1.1%)
     *      * ReteFuente = 4% sobre servicios si base >= 27 UVT (ET art. 392 + art. 103)
     *  - Vendor (si es persona moral en MX) PRACTICA retención:
     *      * IVA retenido = 2/3 del IVA (4% sobre base, art. 1-A LIVA)
     *
     * Las retenciones se aplican como `tax[]` adicional en el line item de Alegra.
     * Los tax IDs deben estar configurados en Alegra con su signo correcto (negativo
     * para retenciones). LTMS solo referencia el ID — Alegra hace el cálculo.
     *
     * @param \WC_Order $order      Pedido.
     * @param float     $commission Monto de la comisión.
     * @return array
     */
    private function prepare_commission_items( \WC_Order $order, float $commission ): array {
        $country     = strtoupper( LTMS_Core_Config::get_country() );
        $iva_rate    = (float) LTMS_Core_Config::get( 'ltms_iva_general', 0.19 );
        $tax_map     = $country === 'MX' ? self::TAX_MAP_MX : self::TAX_MAP_CO;

        wp_cache_delete( 'ltms_alegra_commission_item_id', 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        $item_id = (int) get_option( 'ltms_alegra_commission_item_id', 0 ); // sin cache, fuerza BD

        $line = [
            'name'        => sprintf(
                /* translators: %s: order number */
                __( 'Comisión marketplace — pedido #%s', 'ltms' ),
                $order->get_order_number()
            ),
            'description' => sprintf(
                /* translators: 1: pedido, 2: total bruto */
                __( 'Servicio de intermediación. Pedido bruto: %2$s.', 'ltms' ),
                $order->get_order_number(),
                wc_price( $order->get_total(), [ 'decimals' => 0 ] )
            ),
            'price'       => round( $commission, 2 ),
            'quantity'    => 1,
        ];

        if ( ! $item_id ) {
            // Auto-crear ítem de comisión en Alegra si no está configurado
            try {
                $alegra_client = LTMS_Api_Factory::get( 'alegra' );
                $new_item = $alegra_client->create_item( [
                    'name'  => __( 'Comisión Marketplace Lo Tengo', 'ltms' ),
                    'price' => round( $commission, 2 ),
                    'type'  => 'service',
                ] );
                $item_id = (int) ( $new_item['id'] ?? 0 );
                if ( $item_id ) {
                    update_option( 'ltms_alegra_commission_item_id', $item_id );
                }
            } catch ( \Throwable $e ) {
                // No fatal — Alegra puede rechazar el item sin id en algunos planes
                LTMS_Core_Logger::error( 'ALEGRA_ITEM_AUTOCREATE', $e->getMessage() );
            }
        }
        if ( $item_id ) {
            $line['id'] = $item_id;
        }

        // FU5 FIX (v2.9.1) CRÍTICO FISCAL: aplicar IVA a la línea de comisión.
        if ( $iva_rate > 0 ) {
            $iva_key = (string) round( $iva_rate * 100 );
            $iva_tax_id = $tax_map[ $iva_key ] ?? null;

            if ( $iva_tax_id && (int) $iva_tax_id > 0 ) {
                $line['tax'] = [ [ 'id' => (int) $iva_tax_id ] ];
            } else {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::warning(
                        'ALEGRA_COMMISSION_NO_IVA',
                        sprintf(
                            'Factura para pedido #%d: IVA %.0f%% no aplicado a comisión — tax_id no configurado en tax_map (país=%s, key=%s). Configurar ltms_alegra_tax_map_co/mx.',
                            $order->get_id(),
                            $iva_rate * 100,
                            $country,
                            $iva_key
                        )
                    );
                }
            }
        }

        // NC-1 FIX (v2.9.12) CRÍTICO: aplicar retenciones (ReteIVA, ReteICA,
        // ReteFuente en CO; IVA retenido en MX) a la factura de comisión.
        //
        // Las retenciones se calculan y aplican solo cuando el vendor (como
        // comprador del servicio de intermediación) es agente retenedor según
        // la legislación fiscal de cada país.
        $withholding_tax_ids = $this->resolve_commission_withholdings( $order, $commission, $iva_rate, $country );
        if ( ! empty( $withholding_tax_ids ) ) {
            if ( ! isset( $line['tax'] ) ) {
                $line['tax'] = [];
            }
            foreach ( $withholding_tax_ids as $tax_id ) {
                $line['tax'][] = [ 'id' => (int) $tax_id ];
            }
        }

        return [ $line ];
    }

    /**
     * NC-1 FIX (v2.9.12) — Resuelve qué retenciones aplican a la factura de
     * comisión emitida por el marketplace al vendor.
     *
     * La factura de comisión es un servicio de intermediación prestado por el
     * marketplace al vendor. Cuando el vendor es agente retenedor (gran
     * contribuyente en CO, persona moral en MX), debe practicar retenciones
     * sobre el IVA y/o la base de la comisión.
     *
     * Las retenciones se devuelven como una lista de tax IDs configurados en
     * Alegra. Cada tax ID en Alegra debe tener configurada su tarifa con signo
     * negativo (ej.: ReteIVA = -15% sobre base IVA). LTMS solo referencia el
     * ID — Alegra aplica el cálculo.
     *
     * Reglas por país:
     *
     * COLOMBIA (ET art. 437-2, art. 392, régimen municipal ICA):
     *  - ReteIVA: 15% del IVA si vendor es gran contribuyente Y es responsable
     *    de IVA (régimen común/especial/gran_contribuyente).
     *  - ReteICA: aplica siempre que el vendor tenga CIIU y municipio configurados.
     *    Tasa varía por municipio + CIIU (lookup en lt_co_reteica_rates_municipal).
     *  - ReteFuente: 4% sobre servicios si comisión >= 27 UVT (umbral servicios).
     *    Solo aplica si vendor es agente retenedor.
     *
     * MEXICO (LIVA art. 1-A, LISR art. 113-A):
     *  - IVA retenido: 2/3 del IVA (4% sobre base) si vendor es persona moral.
     *    Personas físicas con actividad empresarial retienen 100% (no aplica aquí
     *    porque el marketplace es persona moral).
     *  - ISR retenido plataformas: art. 113-A LISR — se maneja en Tax_Strategy_MX,
     *    no se duplica aquí.
     *
     * @param \WC_Order $order        Pedido (para extraer vendor_id).
     * @param float     $commission   Monto de la comisión (base imponible).
     * @param float     $iva_rate     Tasa de IVA aplicada (0.19 CO, 0.16 MX).
     * @param string    $country      Código de país ('CO' o 'MX').
     * @return array<int,int> Lista de tax IDs de Alegra para retenciones.
     */
    private function resolve_commission_withholdings( \WC_Order $order, float $commission, float $iva_rate, string $country ): array {

        $vendor_id = (int) $order->get_meta( '_ltms_vendor_id' );
        if ( ! $vendor_id ) {
            // Sin vendor identificado no podemos resolver régimen fiscal.
            return [];
        }

        $tax_ids = [];

        if ( $country === 'CO' ) {
            $tax_ids = $this->resolve_co_commission_withholdings( $vendor_id, $commission, $iva_rate );
        } elseif ( $country === 'MX' ) {
            $tax_ids = $this->resolve_mx_commission_withholdings( $vendor_id, $commission, $iva_rate );
        }

        if ( ! empty( $tax_ids ) && class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'ALEGRA_COMMISSION_WITHHOLDINGS',
                sprintf(
                    'Pedido #%d (vendor #%d, %s): aplicando retenciones tax_ids=%s sobre comisión $%.2f',
                    $order->get_id(),
                    $vendor_id,
                    $country,
                    implode( ',', $tax_ids ),
                    $commission
                )
            );
        }

        return $tax_ids;
    }

    /**
     * NC-1 FIX (v2.9.12) — Retenciones Colombia para factura de comisión.
     *
     * Verifica el régimen fiscal del vendor y aplica:
     *  1. ReteIVA (15% del IVA) si vendor es gran contribuyente + responsable IVA.
     *  2. ReteICA (tasa municipal) si vendor tiene CIIU + municipio.
     *  3. ReteFuente (4% servicios) si comisión >= umbral servicios (27 UVT).
     *
     * @param int   $vendor_id   ID del vendor.
     * @param float $commission  Monto de la comisión.
     * @param float $iva_rate    Tasa de IVA (0.19).
     * @return array<int,int>
     */
    private function resolve_co_commission_withholdings( int $vendor_id, float $commission, float $iva_rate ): array {
        $tax_ids = [];

        $tax_regime           = (string) get_user_meta( $vendor_id, 'ltms_tax_regime', true ) ?: 'simplified';
        $is_gran_contribuyente = (bool) get_user_meta( $vendor_id, 'ltms_is_gran_contribuyente', true );

        // Mapear tax_regime de UI → strategy input.
        // UI guarda: 'iva' (responsable), 'no_iva' (no responsable), 'gran_contribuyente', 'simplified'.
        // Strategy espera: 'simplified', 'common', 'special', 'gran_contribuyente'.
        $normalized_regime = $tax_regime;
        if ( $tax_regime === 'iva' ) {
            $normalized_regime = 'common';
        } elseif ( $tax_regime === 'no_iva' ) {
            $normalized_regime = 'simplified';
        } elseif ( $tax_regime === 'gran_contribuyente' ) {
            $normalized_regime = 'gran_contribuyente';
            $is_gran_contribuyente = true;
        }

        $is_responsible_iva = in_array( $normalized_regime, [ 'common', 'special', 'gran_contribuyente' ], true );

        // 1. ReteIVA: solo si vendor es gran contribuyente Y responsable de IVA.
        // El marketplace (como vendedor del servicio) es el retenido.
        // El vendor (como gran contribuyente) es el agente retenedor.
        if ( $is_gran_contribuyente && $is_responsible_iva && $iva_rate > 0 ) {
            $reteiva_tax_id = (int) LTMS_Core_Config::get( 'ltms_alegra_reteiva_tax_id', 0 );
            if ( $reteiva_tax_id > 0 ) {
                $tax_ids[] = $reteiva_tax_id;
            } else {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::warning(
                        'ALEGRA_RETEIVA_NO_CONFIG',
                        sprintf(
                            'Vendor #%d es gran contribuyente + responsable IVA pero ltms_alegra_reteiva_tax_id=0 — ReteIVA NO aplicada a factura de comisión. Configurar en Alegra → Impuestos.',
                            $vendor_id
                        )
                    );
                }
            }
        }

        // 2. ReteICA: aplica siempre que el vendor tenga CIIU y municipio.
        // El marketplace realiza la actividad de "intermediación" (CIIU 7490 en CO),
        // pero la retención se practica en el municipio del comprador (vendor) si
        // es agente retenedor, o en el municipio del vendedor (marketplace) si no.
        // Para simplificar: usamos el CIIU y municipio del vendor (comúnmente
        // configurado en su perfil fiscal).
        $ciiu_code    = (string) get_user_meta( $vendor_id, 'ltms_ciiu_code', true );
        $municipality = (string) get_user_meta( $vendor_id, 'ltms_municipality', true );

        if ( $ciiu_code && $municipality ) {
            $reteica_tax_id = (int) LTMS_Core_Config::get( 'ltms_alegra_reteica_tax_id', 0 );
            if ( $reteica_tax_id > 0 ) {
                $tax_ids[] = $reteica_tax_id;
            } else {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::warning(
                        'ALEGRA_RETEICA_NO_CONFIG',
                        sprintf(
                            'Vendor #%d tiene CIIU=%s + municipio=%s pero ltms_alegra_reteica_tax_id=0 — ReteICA NO aplicada. Configurar en Alegra → Impuestos.',
                            $vendor_id, $ciiu_code, $municipality
                        )
                    );
                }
            }
        }

        // 3. ReteFuente: 4% servicios si comisión >= umbral (27 UVT en 2026 ≈ $1.42M COP).
        // Aplica cuando el vendor es agente retenedor (gran contribuyente) o cuando
        // el marketplace está catalogado como gran contribuyente (autorretenedor).
        $uvt = (float) LTMS_Core_Config::get( 'ltms_uvt_valor', 52752.0 );
        $retefuente_min_servicios = $uvt * (float) LTMS_Core_Config::get( 'ltms_retefuente_min_servicios_uvt', 2.666 );

        if ( $commission >= $retefuente_min_servicios && $is_gran_contribuyente ) {
            $retefuente_tax_id = (int) LTMS_Core_Config::get( 'ltms_alegra_retefuente_tax_id', 0 );
            if ( $retefuente_tax_id > 0 ) {
                $tax_ids[] = $retefuente_tax_id;
            } else {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::warning(
                        'ALEGRA_RETEFUENTE_NO_CONFIG',
                        sprintf(
                            'Vendor #%d: comisión $%.2f >= umbral ReteFuente servicios ($%.2f) pero ltms_alegra_retefuente_tax_id=0 — ReteFuente NO aplicada.',
                            $vendor_id, $commission, $retefuente_min_servicios
                        )
                    );
                }
            }
        }

        return $tax_ids;
    }

    /**
     * NC-1 FIX (v2.9.12) — Retenciones México para factura de comisión.
     *
     * Aplica IVA retenido al 4% (2/3 del 16% de IVA) cuando el vendor es
     * persona moral (LIVA art. 1-A fracción II). Las personas físicas con
     * actividad empresarial también retienen (fracción III), pero el marketplace
     * no aplica retención sobre personas físicas que emiten CFDI con IVA
     * trasladado (se asume régimen simplificado).
     *
     * Para plataformas tecnológicas (art. 113-A LISR), la retención de ISR
     * se calcula en Tax_Strategy_Mexico y se aplica al payout del vendor, no
     * a la factura de comisión del marketplace.
     *
     * @param int   $vendor_id   ID del vendor.
     * @param float $commission  Monto de la comisión.
     * @param float $iva_rate    Tasa de IVA (0.16).
     * @return array<int,int>
     */
    private function resolve_mx_commission_withholdings( int $vendor_id, float $commission, float $iva_rate ): array {
        $tax_ids = [];

        // Detectar si el vendor es persona moral (SA de CV) o persona física.
        // La config en MX usa 'ltms_tax_regime' con valores: 'morale' (persona moral),
        // 'fisica' (persona física con actividad empresarial), 'simplificado' (RIF).
        $tax_regime = (string) get_user_meta( $vendor_id, 'ltms_tax_regime', true ) ?: 'fisica';

        // Solo aplicar retención de IVA si el vendor es persona moral.
        // Personas físicas (incluyendo RIF) NO retienen IVA al marketplace.
        if ( $tax_regime === 'morale' || $tax_regime === 'moral' ) {
            $iva_retenido_tax_id = (int) LTMS_Core_Config::get( 'ltms_alegra_iva_retenido_mx_tax_id', 0 );
            if ( $iva_retenido_tax_id > 0 ) {
                $tax_ids[] = $iva_retenido_tax_id;
            } else {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::warning(
                        'ALEGRA_IVA_RETENIDO_MX_NO_CONFIG',
                        sprintf(
                            'Vendor #%d es persona moral pero ltms_alegra_iva_retenido_mx_tax_id=0 — IVA retenido NO aplicado. Configurar en Alegra → Impuestos.',
                            $vendor_id
                        )
                    );
                }
            }
        }

        return $tax_ids;
    }

    /**
     * Sincroniza un usuario de WordPress como contacto en Alegra.
     */
    public function sync_user_as_contact( int $user_id, string $type = 'client' ): int {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return 0;
        }

        try {
            $client         = LTMS_Api_Factory::get( 'alegra' );
            $full_name      = trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name;
            $name_words     = explode( ' ', $full_name );
            $fn             = $name_words[0] ?? $full_name;
            $ln             = count( $name_words ) > 1 ? implode( ' ', array_slice( $name_words, 1 ) ) : $fn;
            $nit            = get_user_meta( $user_id, 'ltms_nit', true )
                           ?: get_user_meta( $user_id, 'ltms_document_number', true )
                           ?: '';
            // AUDIT-FIX A8: régimen correcto según si tiene NIT/RUT
            $regime         = $nit ? 'COMMON_REGIME' : 'SIMPLIFIED_REGIME';
            $id_type        = $nit ? 'NIT' : 'CC';

            $contact_data = [
                'name'           => $full_name,
                'nameObject'     => [
                    'firstName'      => $fn,
                    'secondName'     => null,
                    'lastName'       => $ln,
                    'secondLastName' => null,
                ],
                'email'          => $user->user_email,
                'identification' => $nit,
                'identificationObject' => $nit ? [
                    'type'   => $id_type,
                    'number' => preg_replace( '/\D/', '', $nit ),
                    'dv'     => null,
                ] : null,
                'phone'          => get_user_meta( $user_id, 'ltms_phone', true )
                                 ?: get_user_meta( $user_id, 'billing_phone', true )
                                 ?: '',
                'type'           => [ $type ],
                // AUDIT-FIX: NIT → empresa (LEGAL_ENTITY), CC/CE → persona natural
                'kindOfPerson'   => $nit ? 'LEGAL_ENTITY' : 'PERSON_ENTITY',
                'regime'         => $regime,
            ];

            $contact    = $client->get_or_create_contact( $contact_data );
            $contact_id = (int) ( $contact['id'] ?? 0 );

            if ( $contact_id ) {
                update_user_meta( $user_id, '_ltms_alegra_contact_id',   $contact_id );
                update_user_meta( $user_id, '_ltms_alegra_contact_type', $type );
            }

            return $contact_id;

        } catch ( \Throwable $e ) {
            $this->log_warning( 'alegra_contact_sync_error',
                sprintf( 'Error sincronizando usuario #%d: %s', $user_id, $e->getMessage() ) );
            return 0;
        }
    }

    /**
     * Sincroniza un producto WooCommerce como item en Alegra.
     */
    public function sync_product_as_item( int $product_id ): int {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return 0;
        }

        $existing_id = (int) get_post_meta( $product_id, '_ltms_alegra_item_id', true );
        if ( $existing_id ) {
            return $existing_id;
        }

        try {
            $client  = LTMS_Api_Factory::get( 'alegra' );
            $item    = $client->create_item( [
                'name'        => $product->get_name(),
                'price'       => (float) $product->get_price(),
                'type'        => $product->is_virtual() ? 'service' : 'product',
                'description' => wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ),
            ] );
            $item_id = (int) ( $item['id'] ?? 0 );

            if ( $item_id ) {
                update_post_meta( $product_id, '_ltms_alegra_item_id', $item_id );
            }

            return $item_id;

        } catch ( \Throwable $e ) {
            $this->log_warning( 'alegra_item_sync_error',
                sprintf( 'Error sincronizando producto #%d: %s', $product_id, $e->getMessage() ) );
            return 0;
        }
    }

    // ── Helpers privados ──────────────────────────────────────────

    /**
     * Obtiene o crea el contacto Alegra del comprador.
     *
     * @deprecated M-201: el marketplace ya no factura al comprador. Modelo legal "cada vendedor
     * factura propio" — la factura de comisión se emite al vendedor (ver get_or_create_vendor_contact).
     * Esta función se mantiene como dead-code para rollback rápido en caso de revertir el cambio.
     */
    private function get_or_create_buyer_contact( \WC_Order $order, LTMS_Api_Alegra $client ): int {
        $customer_id = $order->get_customer_id();

        if ( $customer_id ) {
            $cached = (int) get_user_meta( $customer_id, '_ltms_alegra_contact_id', true );
            if ( $cached ) {
                return $cached;
            }
        }

        $first_name = $order->get_billing_first_name() ?: 'Cliente';
        $last_name  = $order->get_billing_last_name()  ?: 'Final';
        $full_name  = trim( "$first_name $last_name" ) ?: $order->get_billing_company() ?: 'Cliente Final';
        $name_words = explode( ' ', $full_name );
        $fn         = $name_words[0] ?? $full_name;
        $ln         = count( $name_words ) > 1 ? implode( ' ', array_slice( $name_words, 1 ) ) : $fn;

        // AUDIT-FIX A9: leer identificación del pedido con múltiples meta keys
        $identification = $order->get_meta( '_billing_identification' )
                       ?: $order->get_meta( '_billing_nit' )
                       ?: $order->get_meta( '_billing_document' )
                       ?: '';

        $contact_data = [
            'name'           => $full_name,
            'nameObject'     => [
                'firstName'      => $fn,
                'secondName'     => null,
                'lastName'       => $ln,
                'secondLastName' => null,
            ],
            'email'          => $order->get_billing_email(),
            'phone'          => $order->get_billing_phone(),
            'identification' => $identification,
            // AUDIT-FIX: empresa (tiene company) → LEGAL_ENTITY; persona natural → PERSON_ENTITY
            'kindOfPerson'   => ! empty( $order->get_billing_company() ) ? 'LEGAL_ENTITY' : 'PERSON_ENTITY',
            'regime'         => $identification ? 'COMMON_REGIME' : 'SIMPLIFIED_REGIME',
        ];

        // AUDIT-FIX: Alegra Colombia requiere código DANE para ciudad, no nombre en texto libre.
        // Si la ciudad del pedido tiene código DANE, incluirla. Si no, omitir address
        // para evitar el 400 "Ha ocurrido un error inesperado" por ciudad inválida.
        $city_raw    = $order->get_billing_city();
        $dane_code   = $city_raw ? LTMS_Business_DANE_Catalog::get_city_code( $city_raw ) : '';
        $address_raw = $order->get_billing_address_1();
        if ( $dane_code && $address_raw ) {
            $contact_data['address'] = [
                'address' => sanitize_text_field( $address_raw ),
                'city'    => [ 'id' => $dane_code ],
            ];
        } elseif ( $address_raw ) {
            // Sin código DANE — solo enviar dirección textual sin ciudad
            $contact_data['address'] = [
                'address' => sanitize_text_field( $address_raw ),
            ];
        }

        $contact    = $client->get_or_create_contact( $contact_data );
        $contact_id = (int) ( $contact['id'] ?? 0 );

        if ( $customer_id && $contact_id ) {
            update_user_meta( $customer_id, '_ltms_alegra_contact_id', $contact_id );
        }

        return $contact_id;
    }

    /**
     * Prepara los items de la factura con IVA y retención correctos.
     * AUDIT-FIX A10: IVA mapeado por tasa real + envío como servicio con IVA.
     *
     * @deprecated M-201: factura del 100% del pedido descontinuada. La nueva factura de
     * comisión usa prepare_commission_items(). Esta función queda como dead-code para rollback.
     */
    private function prepare_invoice_items( \WC_Order $order, LTMS_Api_Alegra $client ): array {
        $items   = [];
        $country = strtoupper( LTMS_Core_Config::get_country() );
        $tax_map = $country === 'MX' ? self::TAX_MAP_MX : self::TAX_MAP_CO;

        // AUDIT-BOOKING-ENGINE #10 FIX: detectar si el pedido contiene items
        // de turismo/hospedaje para aplicar tratamiento fiscal especial.
        $has_booking_items = false;
        $booking_rnt       = '';
        $booking_sectur    = '';

        foreach ( $order->get_items() as $item ) {
            /** @var \WC_Order_Item_Product $item */
            $product_id     = $item->get_product_id();
            $product        = $item->get_product();
            $alegra_item_id = (int) get_post_meta( $product_id, '_ltms_alegra_item_id', true );

            // Detectar producto bookable (turismo/hospedaje).
            $is_bookable = $product && method_exists( $product, 'get_type' ) && $product->get_type() === 'ltms_bookable';
            if ( $is_bookable ) {
                $has_booking_items = true;
                // Capturar RNT/SECTUR del vendor para incluir en la factura.
                $vendor_id = (int) get_post_meta( $product_id, '_ltms_vendor_id', true );
                if ( $vendor_id && ! $booking_rnt ) {
                    $booking_rnt = get_user_meta( $vendor_id, 'ltms_rnt_number', true ) ?: '';
                }
                if ( $vendor_id && ! $booking_sectur ) {
                    $booking_sectur = get_user_meta( $vendor_id, 'ltms_sectur_folio', true ) ?: '';
                }
            }

            if ( ! $alegra_item_id && $product_id ) {
                $alegra_item_id = $this->sync_product_as_item( $product_id );
            }

            $qty      = max( 1, $item->get_quantity() );
            $subtotal = (float) $item->get_subtotal();
            $unit_price = round( $subtotal / $qty, 4 );

            $entry = [
                'quantity' => $qty,
                'price'    => $unit_price,
                'name'     => $item->get_name(),
            ];

            // AUDIT-BOOKING-ENGINE #10: agregar datos de reserva al description.
            if ( $is_bookable ) {
                $checkin  = $item->get_meta( '_ltms_checkin_date' ) ?: '';
                $checkout = $item->get_meta( '_ltms_checkout_date' ) ?: '';
                $guests   = (int) ( $item->get_meta( '_ltms_guests' ) ?: 1 );
                $entry['description'] = sprintf(
                    __( 'Reserva: %s a %s, %d huésped(es).%s%s', 'ltms' ),
                    $checkin, $checkout, $guests,
                    $booking_rnt ? ' RNT: ' . $booking_rnt . '.' : '',
                    $booking_sectur ? ' SECTUR: ' . $booking_sectur . '.' : ''
                );
            }

            if ( $alegra_item_id ) {
                $entry['alegra_id'] = $alegra_item_id;
            }

            // ── IVA real del item ──────────────────────────────────
            $item_taxes = $item->get_taxes();
            $total_tax  = array_sum( $item_taxes['total'] ?? [] );

            // AUDIT-BOOKING-ENGINE #10: Colombia — IVA reducido para turismo.
            // Ley 1819/2016 Art. 115: servicios hotelistas prestados a
            // no residentes tienen IVA del 0%. Paquetes turísticos
            // calificados pueden tener IVA del 7% (no 19%).
            if ( $is_bookable && $country === 'CO' ) {
                $iva_turismo = (float) LTMS_Core_Config::get( 'ltms_iva_turismo_co', 0.07 );
                $iva_key_tur = (string) round( $iva_turismo * 100 );
                if ( isset( $tax_map[ $iva_key_tur ] ) ) {
                    $entry['tax'] = [ [ 'id' => $tax_map[ $iva_key_tur ] ] ];
                    $items[] = $entry;
                    continue; // Skip el cálculo automático de IVA abajo.
                }
            }

            if ( $subtotal > 0 && $total_tax >= 0 ) {
                $tax_rate_pct = (int) round( ( $total_tax / $subtotal ) * 100 );

                // Buscar la tasa más cercana en el mapa
                $alegra_tax_id = end( $tax_map ); // default: último (exento)
                foreach ( $tax_map as $rate => $tax_id ) {
                    if ( $tax_rate_pct >= $rate ) {
                        $alegra_tax_id = $tax_id;
                        break;
                    }
                }
                $entry['tax'] = [ [ 'id' => $alegra_tax_id ] ];
            }

            // ── Retención en la fuente (Colombia) ──────────────────
            $retefuente = $item->get_meta( '_retefuente_amount' );
            if ( $retefuente && (float) $retefuente > 0 ) {
                $retefuente_tax_id = (int) LTMS_Core_Config::get( 'ltms_alegra_retefuente_tax_id', 0 );
                if ( $retefuente_tax_id ) {
                    $entry['tax'][] = [ 'id' => $retefuente_tax_id ];
                }
            }

            // NC-1 FIX (v2.9.12): ReteIVA y ReteICA en factura Alegra.
            // ANTES: solo ReteFuente se incluía como tax en la factura.
            // Faltaba ReteIVA (15% del IVA) y ReteICA (municipal).
            $reteiva = $item->get_meta( '_reteiva_amount' );
            if ( $reteiva && (float) $reteiva > 0 ) {
                $reteiva_tax_id = (int) LTMS_Core_Config::get( 'ltms_alegra_reteiva_tax_id', 0 );
                if ( $reteiva_tax_id ) {
                    $entry['tax'][] = [ 'id' => $reteiva_tax_id ];
                }
            }

            $reteica = $item->get_meta( '_reteica_amount' );
            if ( $reteica && (float) $reteica > 0 ) {
                $reteica_tax_id = (int) LTMS_Core_Config::get( 'ltms_alegra_reteica_tax_id', 0 );
                if ( $reteica_tax_id ) {
                    $entry['tax'][] = [ 'id' => $reteica_tax_id ];
                }
            }

            // NC-5 FIX (v2.9.12): Impoconsumo (INC 8%) para restaurantes CO.
            // Ley 2010/2019 art. 3: 8% sobre alimentos preparados en restaurantes.
            $inc = $item->get_meta( '_ltms_impoconsumo_amount' );
            if ( $inc && (float) $inc > 0 ) {
                $inc_tax_id = (int) LTMS_Core_Config::get( 'ltms_alegra_inc_tax_id', 0 );
                if ( $inc_tax_id ) {
                    $entry['tax'][] = [ 'id' => $inc_tax_id ];
                }
            }

            $items[] = $entry;
        }

        // AUDIT-BOOKING-ENGINE #10: México — ISH (Impuesto Sobre Hospedaje).
        // Aplica a servicios de hospedaje. Tasa varía por estado (2-5%).
        // Se agrega como un item adicional en la factura.
        if ( $has_booking_items && $country === 'MX' ) {
            $ish_rate = (float) LTMS_Core_Config::get( 'ltms_ish_rate_mx', 0.03 ); // default 3%.
            $ish_tax_id = (int) LTMS_Core_Config::get( 'ltms_alegra_ish_tax_id', 0 );

            if ( $ish_rate > 0 ) {
                // Calcular base: solo items de hospedaje (no envío, no descuentos).
                $ish_base = 0.0;
                foreach ( $order->get_items() as $item ) {
                    $p = $item->get_product();
                    if ( $p && method_exists( $p, 'get_type' ) && $p->get_type() === 'ltms_bookable' ) {
                        $ish_base += (float) $item->get_subtotal();
                    }
                }

                if ( $ish_base > 0 ) {
                    $ish_amount = round( $ish_base * $ish_rate, 2 );
                    $ish_item = [
                        'name'     => __( 'ISH (Impuesto Sobre Hospedaje)', 'ltms' ),
                        'price'    => $ish_amount,
                        'quantity' => 1,
                    ];
                    if ( $ish_tax_id ) {
                        $ish_item['tax'] = [ [ 'id' => $ish_tax_id ] ];
                    }
                    $items[] = $ish_item;
                }
            }
        }

        // ── Envío como item de servicio ────────────────────────────
        $shipping_total = (float) $order->get_shipping_total();
        if ( $shipping_total > 0 ) {
            $shipping_item = [
                'name'     => __( 'Costo de envío', 'ltms' ),
                'price'    => $shipping_total,
                'quantity' => 1,
            ];
            // IVA de envío (Colombia: exento por defecto)
            $shipping_tax_id = (int) LTMS_Core_Config::get( 'ltms_alegra_shipping_tax_id', $tax_map[0] ?? 1 );
            $shipping_item['tax'] = [ [ 'id' => $shipping_tax_id ] ];
            $items[] = $shipping_item;
        }

        // ── Descuentos del pedido ──────────────────────────────────
        // AUDIT-FIX A12: incluir descuentos como item negativo
        $discount_total = (float) $order->get_discount_total();
        if ( $discount_total > 0 ) {
            $items[] = [
                'name'     => __( 'Descuento aplicado', 'ltms' ),
                'price'    => -abs( $discount_total ),
                'quantity' => 1,
            ];
        }

        return $items;
    }

    /**
     * Prepara items de la nota de crédito desde el reembolso.
     * AUDIT-FIX A3 (implementación): items reales del reembolso.
     */
    private function prepare_refund_items( \WC_Order_Refund $refund, \WC_Order $order ): array {
        $items = [];

        foreach ( $refund->get_items() as $item ) {
            $qty    = abs( $item->get_quantity() );
            $amount = abs( (float) $item->get_subtotal() );
            if ( $amount <= 0 || $qty <= 0 ) {
                continue;
            }
            $items[] = [
                'name'     => $item->get_name() ?: __( 'Producto devuelto', 'ltms' ),
                'price'    => round( $amount / $qty, 4 ),
                'quantity' => $qty,
            ];
        }

        // Si el reembolso no tiene items detallados, usar monto global
        if ( empty( $items ) ) {
            $items[] = [
                'name'     => sprintf( __( 'Reembolso Orden #%d', 'ltms' ), $order->get_id() ),
                'price'    => abs( (float) $refund->get_amount() ),
                'quantity' => 1,
            ];
        }

        return $items;
    }

    /**
     * Registra el pago de la factura en Alegra (tipo 'in').
     * AUDIT-FIX A13: pago inmediato al crear factura cuando está configurado.
     */
    private function register_invoice_payment( int $invoice_id, \WC_Order $order ): void {
        $bank_account_id = (int) LTMS_Core_Config::get( 'ltms_alegra_bank_account_id', 0 );
        if ( ! $bank_account_id ) {
            return;
        }

        // Mapear método de pago WC a Alegra
        $wc_method = $order->get_payment_method();
        $method_map = [
            'stripe'  => 'credit-card',
            'openpay' => 'credit-card',
            'addi'    => 'credit-card',
            'pse'     => 'transfer',
            'bacs'    => 'transfer',
            'cheque'  => 'check',
            'cod'     => 'cash',
        ];
        $alegra_method = $method_map[ $wc_method ] ?? 'transfer';

        try {
            $client  = LTMS_Api_Factory::get( 'alegra' );

            // AL-2 FIX (AUDIT-AL): la factura Alegra creada por
            // create_invoice_for_order() es la factura de COMISIÓN (M-201),
            // cuyo total = platform_fee (+ IVA si aplica). Antes se usaba
            // $order->get_total() (total del pedido WC) como monto del pago.
            // Como total WC > comisión (siempre, salvo edge case de fee=100%),
            // Alegra marcaba la factura como sobrepagada: el excedente quedaba
            // como saldo a favor en el contacto del vendedor, descuadrando la
            // cuenta por cobrar y generando un pasivo fantasma.
            // Orden de resolución del monto correcto:
            //   1. _ltms_platform_fee (meta persistido por Order_Split::process)
            //   2. lt_commissions.commission_amount (camino de producción)
            //   3. GET /invoices/{id} a Alegra ( fuente de verdad final )
            $payment_amount = (float) $order->get_meta( '_ltms_platform_fee' );
            if ( $payment_amount <= 0 ) {
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $payment_amount = (float) $wpdb->get_var( $wpdb->prepare(
                    "SELECT commission_amount FROM `{$wpdb->prefix}lt_commissions` WHERE order_id = %d LIMIT 1",
                    $order->get_id()
                ) );
            }
            if ( $payment_amount <= 0 ) {
                // Último recurso: consultar el total real de la factura en Alegra.
                try {
                    $inv_data       = $client->get_invoice( $invoice_id );
                    $payment_amount = (float) ( $inv_data['total'] ?? 0 );
                } catch ( \Throwable $e ) {
                    $payment_amount = 0.0;
                }
            }
            if ( $payment_amount <= 0 ) {
                // No persistir un pago por 0 — Alegra lo rechazaría.
                $this->log_warning( 'alegra_payment_skip',
                    sprintf( 'No se pudo determinar el monto de la factura Alegra #%d (pedido #%d) — pago omitido.', $invoice_id, $order->get_id() ) );
                return;
            }

            $payment = $client->create_payment( [
                'date'            => current_time( 'Y-m-d' ),
                'bank_account_id' => $bank_account_id,
                'payment_method'  => $alegra_method,
                'type'            => 'in',
                'invoice_id'      => $invoice_id,
                'amount'          => $payment_amount,
                'observations'    => sprintf(
                    __( 'Pago pedido WC #%s — método: %s', 'ltms' ),
                    $order->get_order_number(),
                    $order->get_payment_method_title()
                ),
            ] );

            if ( ! empty( $payment['id'] ) ) {
                $order->update_meta_data( '_ltms_alegra_payment_id', $payment['id'] );
                $order->save();
                $this->log_info( 'alegra_payment_registered',
                    sprintf( 'Pago Alegra #%d para factura #%d (pedido #%d)', $payment['id'], $invoice_id, $order->get_id() ) );
            }
        } catch ( \Throwable $e ) {
            $this->log_warning( 'alegra_payment_failed',
                sprintf( 'No se pudo registrar pago Alegra para pedido #%d: %s', $order->get_id(), $e->getMessage() ) );
        }
    }

    /**
     * Registra la comisión de la plataforma como ingreso separado en Alegra.
     * AUDIT-FIX A14: comisión del marketplace = ingreso real del negocio.
     *
     * @deprecated M-201: la comisión ahora ES la factura principal (emitida al vendedor),
     * no un payment-in separado. Llamarla duplicaría el ingreso en Alegra. Mantenida
     * como dead-code para rollback rápido si revertimos el cambio.
     */
    private function register_platform_commission( int $invoice_id, \WC_Order $order ): void {
        $commission_account_id = (int) LTMS_Core_Config::get( 'ltms_alegra_commission_account_id', 0 );
        if ( ! $commission_account_id ) {
            return;
        }

        $commission = (float) $order->get_meta( '_ltms_platform_fee' );
        if ( $commission <= 0 ) {
            return;
        }

        try {
            $client = LTMS_Api_Factory::get( 'alegra' );
            $client->create_payment( [
                'date'            => current_time( 'Y-m-d' ),
                'bank_account_id' => $commission_account_id,
                'payment_method'  => 'transfer',
                'type'            => 'in',
                'amount'          => $commission,
                'observations'    => sprintf(
                    __( 'Comisión plataforma — Pedido WC #%s (%.1f%%)', 'ltms' ),
                    $order->get_order_number(),
                    (float) LTMS_Core_Config::get( 'ltms_platform_fee_pct', 0 )
                ),
            ] );
        } catch ( \Throwable $e ) {
            $this->log_warning( 'alegra_commission_failed', $e->getMessage() );
        }
    }

    /**
     * Envía factura por email al comprador.
     */
    private function maybe_send_invoice_email( int $alegra_invoice_id, \WC_Order $order ): void {
        $email = $order->get_billing_email();
        if ( ! $email ) {
            return;
        }

        try {
            $client = LTMS_Api_Factory::get( 'alegra' );
            $client->send_invoice_email( $alegra_invoice_id, [ $email ] );
            $this->log_info( 'alegra_invoice_email_sent',
                sprintf( 'Factura Alegra #%d enviada a %s', $alegra_invoice_id, $email ) );
        } catch ( \Throwable $e ) {
            $this->log_warning( 'alegra_invoice_email_failed',
                sprintf( 'No se pudo enviar email factura Alegra #%d: %s', $alegra_invoice_id, $e->getMessage() ) );
        }
    }

    // =========================================================================
    // v3.1.0 — Cross-Border motor (Task 63-D)
    // =========================================================================

    /**
     * Cross-Border listener: reacts to `ltms_cross_border_order` fired by
     * the Order Split when a vendor's slice of an order is cross-border.
     *
     * Responsibilities:
     *   - Log the cross-border event on the Alegra invoice (if it exists) as
     *     an order note so accountants can see the customs declaration.
     *   - If the primary Alegra invoice hasn't been created yet, this method
     *     does NOT create it — that's done by on_order_completed when the
     *     order transitions to processing/completed.
     *   - If the primary Alegra invoice already exists, add a comment to
     *     the invoice via the Alegra API (best-effort — failures are logged
     *     but do not block the order flow).
     *
     * @param int   $order_id       WooCommerce order ID.
     * @param int   $vendor_id      Vendor ID.
     * @param array $customs        Customs calculation result.
     * @param array $context        Additional context (origin, destination, etc.).
     * @return void
     */
    public function on_cross_border_order( int $order_id, int $vendor_id, array $customs, array $context = [] ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $origin      = $context['origin_country']      ?? '';
        $destination = $context['destination_country'] ?? '';
        $incoterm    = $context['incoterm']            ?? 'DDU';
        $display_ccy = $context['display_currency']    ?? $order->get_currency();
        $settle_ccy  = $context['settlement_currency'] ?? $display_ccy;
        $duties      = (float) ( $customs['total_duties_taxes'] ?? 0 );

        // Order note — always added (visible to admins in the WC order screen).
        $note = sprintf(
            /* translators: 1: origin, 2: destination, 3: incoterm, 4: duties, 5: currency, 6: settlement currency */
            __( '🌍 Cross-border order: %1$s → %2$s (%3$s). Duties: %4$s %5$s. Settlement: %6$s.', 'ltms' ),
            $origin,
            $destination,
            $incoterm,
            number_format( $duties, 2 ),
            $display_ccy,
            $settle_ccy
        );
        $order->add_order_note( $note );

        // If the Alegra invoice already exists, append a comment to it so the
        // accountant can see the customs breakdown alongside the commission line.
        $alegra_invoice_id = (int) $order->get_meta( '_ltms_alegra_invoice_id' );
        if ( ! $alegra_invoice_id ) {
            return;
        }

        try {
            $client = LTMS_Api_Factory::get( 'alegra' );

            // Alegra comments are added via the /invoices/{id}/comments endpoint.
            // We use the perform_request method via reflection (same pattern as
            // create_invoice_for_order above) because the API client does not
            // expose a public add_comment method.
            $comment = sprintf(
                "Cross-border: %s→%s (%s)\nDuties: %s %s\nSettlement: %s",
                $origin,
                $destination,
                $incoterm,
                number_format( $duties, 2 ),
                $display_ccy,
                $settle_ccy
            );

            $ref = new \ReflectionClass( $client );
            $m   = $ref->getMethod( 'perform_request' );
            $m->setAccessible( true );
            $m->invoke( $client, 'POST', '/invoices/' . $alegra_invoice_id . '/comments', [
                'description' => substr( $comment, 0, 500 ),
            ] );

            $this->log_info( 'alegra_cross_border_note', sprintf( 'Cross-border comment added to Alegra invoice #%d (order #%d)', $alegra_invoice_id, $order_id ) );
        } catch ( \Throwable $e ) {
            $this->log_warning( 'alegra_cross_border_note_failed',
                sprintf( 'Could not add cross-border comment to Alegra invoice #%d: %s', $alegra_invoice_id, $e->getMessage() )
            );
        }
    }
}
