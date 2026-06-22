<?php
/**
 * LTMS — Backfill fiscal fields + KYC blocking enforcement
 * Archivo: includes/admin/class-ltms-backfill-kyc.php
 * Versión: 1.0.0  |  28/05/2026
 *
 * Dos responsabilidades:
 *   1. LTMS_Backfill_Fiscal  — rellena campos vacíos en bkr_lt_commissions
 *      para órdenes históricas (service_type, payment_method_buyer/vendor/platform).
 *   2. LTMS_KYC_Guard        — impide que un vendedor con KYC incompleto
 *      pueda tener productos publicados activos en WooCommerce.
 *
 * Normativa: Art. 30-B CFF · Regla 12.2.10 RMF 2025 · SAGRILAFT Res. 314/2021
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* =========================================================
   1. BACKFILL DE CAMPOS FISCALES EN ÓRDENES HISTÓRICAS
   ========================================================= */

class LTMS_Backfill_Fiscal {

    /** Campos KYC requeridos en usermeta para Frac. II */
    private static array $kyc_meta = [
        'ltms_rfc',
        'ltms_nit',
        'ltms_domicilio_fiscal',
        'ltms_clabe',
        'ltms_banco',
    ];

    public static function init(): void {
        add_action( 'wp_ajax_ltms_backfill_fiscal_fields', [ __CLASS__, 'handle_ajax' ] );
    }

    /**
     * Endpoint AJAX:
     *   /wp-admin/admin-ajax.php?action=ltms_backfill_fiscal_fields&nonce=TU_NONCE
     * Nonce: wp_create_nonce('ltms_backfill')
     */
    public static function handle_ajax(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permisos.' ], 403 );
        }
        if ( ! check_ajax_referer( 'ltms_backfill', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Nonce inválido.' ], 403 );
        }

        $result = self::run_backfill();
        wp_send_json_success( $result );
    }

    /**
     * Recorre todas las filas de bkr_lt_commissions donde algún campo fiscal
     * está vacío y los rellena desde la orden WooCommerce y el usermeta.
     */
    public static function run_backfill(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_commissions';

        // Órdenes con algún campo fiscal vacío
        $rows = $wpdb->get_results(
            "SELECT id, order_id, vendor_id
             FROM {$table}
             WHERE service_type IS NULL
                OR payment_method IS NULL
                OR payment_method_buyer IS NULL
                OR payment_method_vendor IS NULL
                OR payment_method_platform IS NULL",
            ARRAY_A
        );

        $processed = 0;
        $errors    = [];

        foreach ( $rows as $row ) {
            $order = wc_get_order( (int) $row['order_id'] );
            if ( ! $order ) {
                $errors[] = "order_id {$row['order_id']} no encontrada";
                continue;
            }

            $update = [];

            // --- service_type ---
            if ( empty( $row['service_type'] ) ) {
                $update['service_type'] = self::detect_service_type( $order );
            }

            // --- payment_method (adquiriente) ---
            $pm = self::normalize_payment_method( $order->get_payment_method() );
            if ( empty( $row['payment_method'] ) ) {
                $update['payment_method'] = $pm;
            }
            if ( empty( $row['payment_method_buyer'] ) ) {
                $update['payment_method_buyer'] = $pm;
            }

            // --- payment_method_vendor (payout del vendedor) ---
            if ( empty( $row['payment_method_vendor'] ) ) {
                $update['payment_method_vendor'] = self::get_vendor_payout_method( (int) $row['vendor_id'] );
            }

            // --- payment_method_platform (siempre wallet_marketplace) ---
            if ( empty( $row['payment_method_platform'] ) ) {
                $update['payment_method_platform'] = 'wallet_marketplace';
            }

            if ( ! empty( $update ) ) {
                $wpdb->update( $table, $update, [ 'id' => (int) $row['id'] ] );
                $processed++;
            }
        }

        return [
            'message'   => "Backfill completado: {$processed} comisiones actualizadas.",
            'processed' => $processed,
            'errors'    => $errors,
            'total'     => count( $rows ),
        ];
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    /**
     * Detecta el tipo de servicio según las categorías del producto en la orden.
     * Retorna: hospedaje | importacion | servicio_digital | producto
     */
    private static function detect_service_type( WC_Order $order ): string {
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }
            $cats = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'slugs' ] );
            if ( array_intersect( [ 'hospedaje', 'alojamiento', 'alquiler', 'renta' ], (array) $cats ) ) {
                return 'hospedaje';
            }
            if ( array_intersect( [ 'importacion', 'importación', 'internacional' ], (array) $cats ) ) {
                return 'importacion';
            }
            if ( array_intersect( [ 'digital', 'software', 'descargable', 'servicio-digital' ], (array) $cats ) ) {
                return 'servicio_digital';
            }
        }
        return 'producto';
    }

    /**
     * Normaliza el slug de método de pago de WooCommerce a una etiqueta estándar.
     */
    private static function normalize_payment_method( string $slug ): string {
        $map = [
            'stripe'          => 'tarjeta',
            'stripe_cc'       => 'tarjeta',
            'paypal'          => 'paypal',
            'bacs'            => 'transferencia_bancaria',
            'cheque'          => 'cheque',
            'cod'             => 'efectivo_contraentrega',
            'oxxo'            => 'oxxo',
            'mercadopago'     => 'mercadopago',
            'wompi'           => 'wompi',
            'pagali'          => 'pagali',
            'pse'             => 'pse',
            'nequi'           => 'nequi',
            'daviplata'       => 'daviplata',
        ];
        $slug = strtolower( trim( $slug ) );
        foreach ( $map as $key => $label ) {
            if ( str_contains( $slug, $key ) ) {
                return $label;
            }
        }
        return $slug ?: 'desconocido';
    }

    /**
     * Obtiene el método de payout del vendedor desde usermeta.
     * Infiere desde la CLABE si no está explícito.
     */
    private static function get_vendor_payout_method( int $vendor_id ): string {
        $method = get_user_meta( $vendor_id, 'ltms_payout_method', true );
        if ( $method ) {
            return (string) $method;
        }
        // Inferir desde CLABE: 18 dígitos = CLABE MX → transferencia_spei
        $clabe = get_user_meta( $vendor_id, 'ltms_clabe', true );
        if ( $clabe && strlen( preg_replace( '/\D/', '', $clabe ) ) === 18 ) {
            return 'transferencia_spei';
        }
        // Si tiene cuenta bancaria CO
        if ( $clabe && strlen( preg_replace( '/\D/', '', $clabe ) ) >= 10 ) {
            return 'transferencia_bancaria_co';
        }
        return 'wallet_marketplace';
    }
}

LTMS_Backfill_Fiscal::init();


/* =========================================================
   2. KYC GUARD — BLOQUEO BLOQUEANTE DE VENDEDORES SIN KYC
   ========================================================= */

class LTMS_KYC_Guard {

    /**
     * Campos KYC mínimos obligatorios para activar un vendedor.
     * Sin estos, Frac. II incisos b), d), e) exportan vacíos.
     */
    private static array $required_meta = [
        'ltms_rfc'              => 'RFC o NIT fiscal',
        'ltms_domicilio_fiscal' => 'Domicilio fiscal',
        'ltms_clabe'            => 'CLABE o número de cuenta bancaria',
        'ltms_banco'            => 'Institución financiera (banco)',
    ];

    public static function init(): void {
        // Verificar KYC al publicar producto
        add_action( 'transition_post_status', [ __CLASS__, 'block_publish_without_kyc' ], 10, 3 );
        // Mostrar aviso en el panel del vendedor
        add_action( 'admin_notices',          [ __CLASS__, 'show_kyc_notice' ] );
        // API REST: bloquear publicación de producto sin KYC
        add_filter( 'woocommerce_rest_pre_insert_product_object', [ __CLASS__, 'block_rest_publish_without_kyc' ], 10, 2 );
    }

    /**
     * Bloquea la transición a "publish" si el vendedor no tiene KYC completo.
     * Fuerza el estado a "pending" y registra el bloqueo en bkr_lt_logs.
     */
    public static function block_publish_without_kyc( string $new, string $old, WP_Post $post ): void {
        if ( $new !== 'publish' || $old === 'publish' ) {
            return;
        }
        if ( $post->post_type !== 'product' ) {
            return;
        }

        $vendor_id = (int) $post->post_author;

        // Solo aplicar a vendedores — si el autor es admin, dejar pasar
        $author = get_userdata( $vendor_id );
        if ( ! $author || in_array( 'administrator', (array) $author->roles, true ) ) {
            return;
        }
        // Si el KYC fue aprobado en la tabla ltms_kyc, permitir publicar
        global $wpdb;
        $kyc_table = $wpdb->prefix . 'lt_vendor_kyc';
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$kyc_table}'" ) === $kyc_table ) {
            $kyc_status = $wpdb->get_var( $wpdb->prepare(
                "SELECT status FROM {$kyc_table} WHERE vendor_id = %d ORDER BY id DESC LIMIT 1",
                $vendor_id
            ) );
            if ( $kyc_status === 'approved' ) {
                return; // KYC aprobado en tabla — OK
            }
        }

        $missing   = self::get_missing_kyc_fields( $vendor_id );

        if ( empty( $missing ) ) {
            return; // KYC completo — OK
        }

        // Forzar a "pending" en lugar de "publish"
        remove_action( 'transition_post_status', [ __CLASS__, 'block_publish_without_kyc' ], 10 );
        wp_update_post( [ 'ID' => $post->ID, 'post_status' => 'pending' ] );
        add_action( 'transition_post_status', [ __CLASS__, 'block_publish_without_kyc' ], 10, 3 );

        // Registrar en bkr_lt_logs
        self::log_kyc_block( $vendor_id, $post->ID, $missing );

        // Aviso admin
        set_transient(
            'ltms_kyc_block_' . $vendor_id,
            [
                'product_id' => $post->ID,
                'missing'    => $missing,
            ],
            300
        );
    }

    /**
     * Bloquea publicación vía WooCommerce REST API.
     */
    public static function block_rest_publish_without_kyc( $product, WP_REST_Request $request ) {
        if ( $request->get_param( 'status' ) !== 'publish' ) {
            return $product;
        }
        $vendor_id = get_current_user_id();
        $missing   = self::get_missing_kyc_fields( $vendor_id );
        if ( ! empty( $missing ) ) {
            return new WP_Error(
                'ltms_kyc_incomplete',
                'KYC incompleto. Campos faltantes: ' . implode( ', ', array_values( $missing ) )
                . '. (Art. 30-B CFF / SAGRILAFT Res. 314/2021)',
                [ 'status' => 403 ]
            );
        }
        return $product;
    }

    /**
     * Muestra aviso en el panel de WordPress cuando un producto fue bloqueado.
     */
    public static function show_kyc_notice(): void {
        $vendor_id = get_current_user_id();
        $data      = get_transient( 'ltms_kyc_block_' . $vendor_id );
        if ( ! $data ) {
            return;
        }
        delete_transient( 'ltms_kyc_block_' . $vendor_id );
        $missing_list = implode( ', ', array_values( $data['missing'] ) );
        echo '<div class="notice notice-error"><p>'
            . '<strong>LTMS — KYC incompleto:</strong> Tu producto no puede publicarse hasta que completes '
            . 'los siguientes datos fiscales en tu perfil: <strong>' . esc_html( $missing_list ) . '</strong>. '
            . 'Requerido por Art. 30-B CFF y SAGRILAFT Res. 314/2021.</p></div>';
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    /**
     * Retorna array con los campos KYC faltantes para un vendor_id dado.
     * Array vacío = KYC completo.
     */
    public static function get_missing_kyc_fields( int $vendor_id ): array {
        $missing = [];
        foreach ( self::$required_meta as $meta_key => $label ) {
            $value = get_user_meta( $vendor_id, $meta_key, true );
            if ( empty( $value ) ) {
                $missing[ $meta_key ] = $label;
            }
        }
        return $missing;
    }

    /**
     * Registra el bloqueo en bkr_lt_logs.
     */
    private static function log_kyc_block( int $vendor_id, int $product_id, array $missing ): void {
        // M-LOGS-02: el insert directo a lt_logs (esquema level/module/message/details)
        // con columnas event_type/user_id/object_id provocaba "Unknown column" en cada
        // bloqueo de publicación. Se usa LTMS_Core_Logger::log() con class_exists()
        // por el mismo motivo que commission-writer.php: esta clase se carga via
        // require_once directo (fuera del kernel), antes del eager-load de traits.
        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::log(
                'KYC_BLOCK',
                sprintf( 'Publicación bloqueada para producto #%d (vendor #%d). Campos faltantes: %s', $product_id, $vendor_id, implode( ', ', array_keys( $missing ) ) ),
                [
                    'vendor_id'  => $vendor_id,
                    'product_id' => $product_id,
                    'missing'    => array_keys( $missing ),
                ],
                'WARNING'
            );
        }
    }
}

LTMS_KYC_Guard::init();
