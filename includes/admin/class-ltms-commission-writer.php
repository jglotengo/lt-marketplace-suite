<?php
/**
 * LTMS — Commission Writer Hook
 * Versión: 1.0.0
 * Cumplimiento: Art. 30-B CFF · Regla 12.2.10 RMF 2025 · Ficha 168/CFF · E.T. Art. 437-2 CO
 *
 * Registra en bkr_lt_commissions, al momento del checkout/pago, todos los campos
 * obligatorios de la Fracción I y Fracción II que antes quedaban vacíos:
 *
 *   Frac. I  a)  service_type
 *   Frac. I  g)  payment_method  (adquiriente)
 *   Frac. II f-iv-a)  payment_method_buyer
 *   Frac. II f-iv-b)  payment_method_vendor
 *   Frac. II f-iv-c)  payment_method_platform
 *
 * Despliegue:
 *   scp -P 18765 class-ltms-commission-writer.php \
 *     u1549-ruo8hvwpk9dt@ssh.lo-tengo.com.co:/home/customer/www/lo-tengo.com.co/public_html \
 *     /wp-content/plugins/lt-marketplace-suite/includes/admin/class-ltms-commission-writer.php
 *
 * Luego incluir desde el plugin principal:
 *   require_once LTMS_PLUGIN_DIR . 'includes/admin/class-ltms-commission-writer.php';
 *   new LTMS_Commission_Writer();
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Commission_Writer {

    // ── Constantes de plataforma ─────────────────────────────────────────────
    const PLATFORM_NAME    = 'Lo-Tengo.com.co';
    const PLATFORM_METHOD  = 'wallet_marketplace'; // método de pago de la plataforma (Frac. II f-iv-c)

    /**
     * Returns the fully-qualified commissions table name, honouring the
     * site's configured $wpdb->prefix. C-2 FIX: previously this was a
     * hard-coded `bkr_lt_commissions` constant which broke on sites whose
     * DB prefix is not `bkr_` (staging, multisite, dev). All callers must
     * use this helper instead of the removed `LTMS_TABLE` constant.
     *
     * @return string
     */
    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'lt_commissions';
    }

    // Tasa IVA Colombia
    const IVA_CO = 0.19;
    // Tasa ISR retención MX (Art. 113-A LISR) — 1% sobre ingreso bruto
    const ISR_MX_RATE = 0.01;
    // Tasa RETEIVA Colombia (Art. 437-2 E.T.) — 15% del IVA facturado
    const RETEIVA_CO_RATE = 0.15;

    public function __construct() {
        // Hook principal: se dispara cuando WooCommerce crea la comisión de YITH
        // o cuando el pago se completa (flujo marketplace normal).
        add_action( 'woocommerce_payment_complete',           [ $this, 'on_payment_complete' ], 20, 1 );
        add_action( 'woocommerce_order_status_processing',    [ $this, 'on_payment_complete' ], 20, 1 );
        add_action( 'woocommerce_checkout_order_processed',   [ $this, 'on_order_created' ],    20, 3 );

        // Hook de respaldo: si YITH Vendors crea/actualiza la comisión
        add_action( 'yith_wcmv_commission_saved',             [ $this, 'on_yith_commission_saved' ], 20, 2 );

        // Admin AJAX: rellenar registros históricos vacíos (ejecutar una sola vez)
        add_action( 'wp_ajax_ltms_backfill_fiscal_fields',    [ $this, 'ajax_backfill' ] );
    }

    // ════════════════════════════════════════════════════════════════════════
    // HOOK: Orden procesada en checkout (captura datos de pago del adquiriente)
    // ════════════════════════════════════════════════════════════════════════
    public function on_order_created( $order_id, $posted_data, $order ) {
        $this->write_fiscal_fields( $order_id );
    }

    public function on_payment_complete( $order_id ) {
        $this->write_fiscal_fields( $order_id );
    }

    // ════════════════════════════════════════════════════════════════════════
    // HOOK: YITH Vendors guarda una comisión (llamado con objeto comisión)
    // ════════════════════════════════════════════════════════════════════════
    public function on_yith_commission_saved( $commission_id, $commission ) {
        if ( ! $commission ) return;

        $order_id = method_exists( $commission, 'get_order_id' )
            ? $commission->get_order_id()
            : ( $commission->order_id ?? 0 );

        if ( ! $order_id ) return;

        $this->write_fiscal_fields( $order_id );
    }

    // ════════════════════════════════════════════════════════════════════════
    // NÚCLEO: construye y escribe todos los campos fiscales
    // ════════════════════════════════════════════════════════════════════════
    private function write_fiscal_fields( $order_id ) {
        global $wpdb;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // ── 1. Datos del adquiriente (comprador) ────────────────────────────
        $payment_method_buyer = $this->normalize_payment_method( $order->get_payment_method() );
        $rfc_cliente          = $order->get_meta( '_billing_rfc', true )
                                ?: $order->get_meta( '_cfdi_rfc', true )
                                ?: '';
        $cfdi_folio           = $order->get_meta( '_cfdi_uuid', true )
                                ?: $order->get_meta( '_cfdi_folio', true )
                                ?: '';
        $country_code         = strtoupper( $order->get_billing_country() ?: 'CO' );

        // ── 2. Tipo de servicio (Frac. I a) ─────────────────────────────────
        $service_type = $this->detect_service_type( $order );

        // ── 3. Método de pago de la plataforma (Frac. II f-iv-c) ────────────
        $payment_method_platform = self::PLATFORM_METHOD;

        // ── 4. Por cada ítem de orden, localizar la comisión y actualizar ────
        foreach ( $order->get_items() as $item_id => $item ) {
            $product    = $item->get_product();
            $vendor_id  = $this->get_vendor_for_item( $item, $order );

            if ( ! $vendor_id ) continue;

            // Método de pago del oferente (vendedor) — cómo Lo-Tengo le paga
            $payment_method_vendor = $this->get_vendor_payout_method( $vendor_id );

            // Cálculos fiscales por ítem
            $gross         = (float) $item->get_subtotal();
            $iva           = $country_code === 'MX'
                             ? (float) $item->get_subtotal_tax()
                             : round( $gross * self::IVA_CO, 2 );
            $isr_retenido  = $country_code === 'MX' ? round( $gross * self::ISR_MX_RATE, 2 ) : 0.00;
            $reteiva       = $country_code === 'CO' ? round( $iva * self::RETEIVA_CO_RATE, 2 )  : 0.00;

            // service_type específico por ítem si hay override en product meta
            $item_service_type = get_post_meta( $item->get_product_id(), '_ltms_service_type', true )
                                 ?: $service_type;

            // ── Buscar fila existente en {prefix}lt_commissions ────────────
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT id FROM `" . self::table() . "`
                  WHERE order_id = %d AND vendor_id = %d
                  LIMIT 1",
                $order_id, $vendor_id
            ) );

            $data = [
                'service_type'            => $item_service_type,
                'payment_method'          => $payment_method_buyer,
                'payment_method_buyer'    => $payment_method_buyer,
                'payment_method_vendor'   => $payment_method_vendor,
                'payment_method_platform' => $payment_method_platform,
                'rfc_cliente'             => $rfc_cliente,
                'cfdi_folio'              => $cfdi_folio,
                'isr_amount'              => $isr_retenido,
                'reteiva_amount'          => $reteiva,
                'country_code'            => $country_code,
                'updated_at'              => current_time( 'mysql' ),
            ];

            $formats = [
                '%s', '%s', '%s', '%s', '%s',
                '%s', '%s',
                '%f', '%f',
                '%s', '%s',
            ];

            if ( $row ) {
                // Actualizar fila existente
                $wpdb->update(
                    self::table(),
                    $data,
                    [ 'id' => $row->id ],
                    $formats,
                    [ '%d' ]
                );
            } else {
                // Insertar nueva comisión si no existe (fallback)
                $data = array_merge( [
                    'order_id'          => $order_id,
                    'vendor_id'         => $vendor_id,
                    'gross_amount'      => $gross,
                    'iva_amount'        => $iva,
                    'commission_rate'   => 0.0,
                    'commission_amount' => 0.0,
                    'vendor_amount'     => $gross - $isr_retenido - $reteiva,
                    'tax_withholding'   => $isr_retenido + $reteiva,
                    'currency'          => $country_code === 'MX' ? 'MXN' : 'COP',
                    'status'            => 'pending',
                    'type'              => 'commission',
                    'created_at'        => current_time( 'mysql' ),
                ], $data );

                $wpdb->insert( self::table(), $data );
            }

            // Log forense vía LTMS_Core_Logger (bkr_lt_audit_logs)
            $this->log_fiscal_write( $order_id, $vendor_id, $data );
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Detecta el tipo de servicio para Frac. I inciso a).
     * Prioridad: meta de orden > categoría de producto > default 'producto'
     */
    private function detect_service_type( $order ) {
        // Override manual en meta de orden
        $override = $order->get_meta( '_ltms_service_type', true );
        if ( $override ) return $override;

        // Detectar hospedaje por categoría WC
        foreach ( $order->get_items() as $item ) {
            $categories = wp_get_post_terms( $item->get_product_id(), 'product_cat', [ 'fields' => 'slugs' ] );
            if ( ! is_wp_error( $categories ) ) {
                if ( array_intersect( [ 'hospedaje', 'alojamiento', 'accommodation', 'hotel', 'alquiler' ], $categories ) ) {
                    return 'hospedaje';
                }
                if ( array_intersect( [ 'importacion', 'importación', 'import', 'envio-internacional' ], $categories ) ) {
                    return 'importacion';
                }
                if ( array_intersect( [ 'servicio', 'service', 'digital', 'software', 'suscripcion' ], $categories ) ) {
                    return 'servicio_digital';
                }
            }
        }

        return 'producto'; // default Frac. I a)
    }

    /**
     * Normaliza el método de pago de WooCommerce a etiqueta legible.
     */
    private function normalize_payment_method( $raw ) {
        $map = [
            'stripe'                 => 'tarjeta_credito_debito',
            'stripe_cc'              => 'tarjeta_credito_debito',
            'paypal'                 => 'paypal',
            'wompi'                  => 'pse_wompi',
            'pse'                    => 'pse',
            'nequi'                  => 'nequi',
            'daviplata'              => 'daviplata',
            'oxxo'                   => 'oxxo',
            'spei'                   => 'transferencia_spei',
            'bacs'                   => 'transferencia_bancaria',
            'cheque'                 => 'cheque',
            'cod'                    => 'contra_entrega',
            'wc_payment_gateway'     => 'pasarela_generica',
            'mercadopago'            => 'mercado_pago',
            'payu'                   => 'payu',
            'wc_openpay_gateway'      => 'tarjeta_openpay',
            'openpay_pse'            => 'pse_openpay',
            'openpay_card'           => 'tarjeta_openpay',
            'openpay_store'          => 'efectivo_openpay',
        ];

        $raw = strtolower( trim( $raw ) );
        return $map[ $raw ] ?? ( $raw ?: 'no_especificado' );
    }

    /**
     * Obtiene el vendor_id para un ítem de orden.
     * Compatible con YITH WooCommerce Multi Vendor y la tabla bkr_yith_vendors_commissions.
     */
    private function get_vendor_for_item( $item, $order ) {
        global $wpdb;

        // Intentar desde meta del ítem (YITH Multi Vendor)
        $vendor_id = $item->get_meta( '_vendor_id', true )
                     ?: $item->get_meta( 'vendor_id', true );

        if ( $vendor_id ) return (int) $vendor_id;

        // Intentar desde la tabla YITH commissions
        $vendor_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT vendor_id FROM {$wpdb->prefix}yith_vendors_commissions
              WHERE order_id = %d AND order_item_id = %d
              LIMIT 1",
            $order->get_id(), $item->get_id()
        ) );

        if ( $vendor_id ) return (int) $vendor_id;

        // Fallback: vendor del producto
        $product_id = $item->get_product_id();
        $vendor_id  = get_post_meta( $product_id, '_vendor', true )
                      ?: get_post_field( 'post_author', $product_id );

        return $vendor_id ? (int) $vendor_id : 0;
    }

    /**
     * Determina cómo La Plataforma paga al vendedor (Frac. II f-iv-b).
     * Lee usermeta ltms_payout_method; default 'transferencia_bancaria'.
     */
    private function get_vendor_payout_method( $vendor_id ) {
        $method = get_user_meta( $vendor_id, 'ltms_payout_method', true );

        if ( ! $method ) {
            // Inferir desde datos bancarios registrados
            $clabe = get_user_meta( $vendor_id, 'ltms_clabe', true );
            if ( $clabe ) {
                $method = strlen( preg_replace( '/\D/', '', $clabe ) ) === 18
                    ? 'transferencia_clabe_mx'  // CLABE 18 dígitos → MX
                    : 'transferencia_bancaria_co';
            }
        }

        return $method ?: 'transferencia_bancaria';
    }

    /**
     * Escribe un registro de auditoría forense vía LTMS_Core_Logger.
     */
    private function log_fiscal_write( $order_id, $vendor_id, $data ) {
        // M-LOGS-01: el insert directo original a 'lt_logs' (esquema
        // level/module/message/details, sin las columnas event_type/
        // object_id/object_type/user_id que este método esperaba) causaba
        // "Unknown column" en cada checkout.
        //
        // IMPORTANTE: este archivo se carga vía require_once directo desde
        // lt-marketplace-suite.php (fuera del kernel), en un punto donde el
        // eager-load de traits (core/traits/trait-ltms-logger-aware.php) aún
        // no ha corrido. Declarar `use LTMS_Logger_Aware;` en la clase rompe
        // el sitio con un fatal "Trait not found" en tiempo de parseo.
        // Por eso se llama a LTMS_Core_Logger::log() directamente (resuelve
        // en tiempo de ejecución del hook, no de declaración de clase) en
        // vez de usar el trait.
        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::log(
                'FISCAL_FIELDS_WRITTEN',
                sprintf( 'Campos fiscales escritos para order #%d (vendor #%d)', $order_id, $vendor_id ),
                [
                    'order_id'     => $order_id,
                    'vendor_id'    => $vendor_id,
                    'service_type' => $data['service_type'] ?? '',
                    'pm_buyer'     => $data['payment_method_buyer'] ?? '',
                    'pm_vendor'    => $data['payment_method_vendor'] ?? '',
                    'pm_platform'  => $data['payment_method_platform'] ?? '',
                ],
                'INFO'
            );
        }
    }

    // ════════════════════════════════════════════════════════════════════════
    // AJAX: Backfill de órdenes históricas (ejecutar UNA SOLA VEZ como admin)
    // URL: /wp-admin/admin-ajax.php?action=ltms_backfill_fiscal_fields&nonce=XXXX
    // ════════════════════════════════════════════════════════════════════════
    public function ajax_backfill() {
        check_ajax_referer( 'ltms_backfill', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        global $wpdb;

        // Obtener órdenes que tienen comisiones con service_type o payment_method vacíos
        $order_ids = $wpdb->get_col( "
            SELECT DISTINCT order_id
            FROM `" . self::table() . "`
            WHERE service_type IS NULL
               OR service_type = ''
               OR payment_method IS NULL
               OR payment_method = ''
            LIMIT 500
        " );

        $updated = 0;
        foreach ( $order_ids as $order_id ) {
            $this->write_fiscal_fields( (int) $order_id );
            $updated++;
        }

        wp_send_json_success( [
            'message' => "Backfill completado: {$updated} órdenes procesadas.",
            'orders'  => $updated,
        ] );
    }
}

// ── Inicializar ───────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function () {
    if ( class_exists( 'WooCommerce' ) ) {
        new LTMS_Commission_Writer();
    }
}, 15 );
