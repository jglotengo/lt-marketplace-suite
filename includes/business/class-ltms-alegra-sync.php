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
        19 => 3,  // IVA 19%
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

        // Payout completado → registrar egreso en Alegra
        add_action( 'ltms_payout_completed', [ $instance, 'on_payout_completed' ], 20, 2 );

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
                $order->add_order_note(
                    sprintf( __( '📄 Factura Alegra creada: %s', 'ltms' ), $invoice_number )
                );
                $order->save();

                $this->log_info(
                    'alegra_invoice_created',
                    sprintf( 'Factura Alegra #%d (%s) para pedido WC #%d', $invoice_id, $invoice_number, $order_id )
                );

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

            $result = $client->create_credit_note( $payload );

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
            }
        } catch ( \Throwable $e ) {
            $this->log_error( 'alegra_sync', 'Error nota de crédito: ' . $e->getMessage() );
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
    public function on_payout_completed( int $vendor_id, float $net_amount ): void {
        $bank_account_id   = (int) LTMS_Core_Config::get( 'ltms_alegra_bank_account_id', 0 );
        $alegra_contact_id = (int) get_user_meta( $vendor_id, '_ltms_alegra_contact_id', true );

        if ( ! $bank_account_id || ! $alegra_contact_id || $net_amount <= 0 ) {
            return;
        }

        try {
            $client  = LTMS_Api_Factory::get( 'alegra' );
            $payment = $client->create_payment( [
                'date'            => current_time( 'Y-m-d' ),
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
            ] );

            $this->log_info( 'alegra_payout_registered',
                sprintf( 'Egreso Alegra #%d — vendedor #%d — $%s', $payment['id'] ?? 0, $vendor_id, $net_amount ) );
        } catch ( \Throwable $e ) {
            $this->log_warning( 'alegra_payout_failed',
                sprintf( 'No se pudo registrar egreso Alegra para vendedor #%d: %s', $vendor_id, $e->getMessage() ) );
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

        $vendor_id = (int) $order->get_meta( '_ltms_vendor_id' );
        if ( ! $vendor_id ) {
            // Fallback: extraer del primer item (mismo patrón que Order_Split).
            foreach ( $order->get_items() as $item ) {
                $pid       = $item->get_product_id();
                $vendor_id = (int) get_post_meta( $pid, '_ltms_vendor_id', true );
                if ( $vendor_id ) {
                    break;
                }
            }
        }

        if ( ! $vendor_id ) {
            throw new \RuntimeException(
                sprintf( '[AlegraSync] Pedido #%d sin vendor_id — no se factura comisión.', $order->get_id() )
            );
        }

        $commission = (float) $order->get_meta( '_ltms_platform_fee' );
        if ( $commission <= 0 ) {
            // Para órdenes registradas vía lt_commissions (camino actual), leer ahí.
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $commission = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT commission_amount FROM `{$wpdb->prefix}lt_commissions` WHERE order_id = %d AND vendor_id = %d LIMIT 1",
                $order->get_id(), $vendor_id
            ) );
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
        if ( $currency && $currency !== 'COP' ) {
            $invoice_data['currency']      = $currency;
            $invoice_data['exchange_rate'] = (float) LTMS_Core_Config::get( 'ltms_alegra_exchange_rate', 1 );
        }

        $invoice_data['anotation'] = 'WC-' . $order->get_order_number() . '-COMM';

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
     * @param \WC_Order $order      Pedido.
     * @param float     $commission Monto de la comisión.
     * @return array
     */
    private function prepare_commission_items( \WC_Order $order, float $commission ): array {
        $country     = strtoupper( LTMS_Core_Config::get_country() );
        $iva_rate    = (float) LTMS_Core_Config::get( 'ltms_iva_general', 0.19 );
        $tax_map     = $country === 'MX' ? self::TAX_MAP_MX : self::TAX_MAP_CO;

        $item_id = (int) LTMS_Core_Config::get( 'ltms_alegra_commission_item_id', 0 );

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

        if ( $item_id ) {
            $line['id'] = $item_id;
        }

        // IVA sobre la comisión (servicio gravado en CO; MX similar al 16%).
        if ( $iva_rate > 0 ) {
            $iva_key = (string) round( $iva_rate * 100 );
            $iva_tax_id = $tax_map[ $iva_key ] ?? null;
            if ( $iva_tax_id ) {
                $line['tax'] = [ [ 'id' => (int) $iva_tax_id ] ];
            }
        }

        return [ $line ];
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
                'kindOfPerson'   => 'PERSON_ENTITY',
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
            'kindOfPerson'   => 'PERSON_ENTITY',
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

        foreach ( $order->get_items() as $item ) {
            /** @var \WC_Order_Item_Product $item */
            $product_id     = $item->get_product_id();
            $alegra_item_id = (int) get_post_meta( $product_id, '_ltms_alegra_item_id', true );

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

            if ( $alegra_item_id ) {
                $entry['alegra_id'] = $alegra_item_id;
            }

            // ── IVA real del item ──────────────────────────────────
            $item_taxes = $item->get_taxes();
            $total_tax  = array_sum( $item_taxes['total'] ?? [] );

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
            // AUDIT-FIX A11: agregar retefuente si aplica
            $retefuente = $item->get_meta( '_retefuente_amount' );
            if ( $retefuente && (float) $retefuente > 0 ) {
                $retefuente_tax_id = (int) LTMS_Core_Config::get( 'ltms_alegra_retefuente_tax_id', 0 );
                if ( $retefuente_tax_id ) {
                    $entry['tax'][] = [ 'id' => $retefuente_tax_id ];
                }
            }

            $items[] = $entry;
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
            $payment = $client->create_payment( [
                'date'            => current_time( 'Y-m-d' ),
                'bank_account_id' => $bank_account_id,
                'payment_method'  => $alegra_method,
                'type'            => 'in',
                'invoice_id'      => $invoice_id,
                'amount'          => (float) $order->get_total(),
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
}
