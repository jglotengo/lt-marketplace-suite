<?php
/**
 * LTMS — Deprisa Multi-Origin Order Split
 *
 * Divide un pedido WooCommerce en sub-envíos agrupados por vendedor/bodega,
 * genera una guía Deprisa independiente por cada origen y persiste los
 * resultados en meta del pedido para el metabox de admin.
 *
 * Arquitectura:
 *   WC_Order → split por vendor_id → [ SubEnvio, SubEnvio, … ]
 *              ↓ cada SubEnvio
 *   LTMS_Api_Deprisa::admitir_envio() → numero_envio + etiqueta PDF (base64)
 *
 * Hooks registrados (llamar desde ltms-deprisa-loader.php):
 *   add_action( 'woocommerce_order_status_processing', [ LTMS_Deprisa_Order_Split::class, 'on_order_processing' ] );
 *   add_action( 'wp_ajax_ltms_deprisa_split_manual',  [ LTMS_Deprisa_Order_Split::class, 'ajax_manual_split' ] );
 *
 * @package LTMS
 * @since   1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Deprisa_Order_Split {

    /* ------------------------------------------------------------------ */
    /* Constantes de meta keys                                              */
    /* ------------------------------------------------------------------ */

    /** Array serializado con los resultados de cada sub-envío. */
    const META_GUIAS        = '_ltms_deprisa_guias';

    /** Timestamp de última ejecución del split. */
    const META_SPLIT_TS     = '_ltms_deprisa_split_at';

    /** Flag para evitar doble ejecución. */
    const META_SPLIT_DONE   = '_ltms_deprisa_split_done';

    /* ------------------------------------------------------------------ */
    /* Hook: woocommerce_order_status_processing                            */
    /* ------------------------------------------------------------------ */

    /**
     * Se dispara cuando el pedido pasa a "En proceso".
     * Sólo actúa si Deprisa está habilitado y si el pedido aún no fue procesado.
     */
    public static function on_order_processing( int $order_id ): void {
        if ( ! self::is_enabled() ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Evitar doble ejecución (retry de webhook, etc.)
        if ( $order->get_meta( self::META_SPLIT_DONE ) === 'yes' ) {
            return;
        }

        self::process_order( $order );
    }

    /* ------------------------------------------------------------------ */
    /* Hook AJAX: re-ejecución manual desde el metabox                     */
    /* ------------------------------------------------------------------ */

    public static function ajax_manual_split(): void {
        check_ajax_referer( 'ltms_deprisa_split', '_wpnonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permisos.' ] );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) {
            wp_send_json_error( [ 'message' => 'Pedido no encontrado.' ] );
        }

        // Forzar re-ejecución borrando el flag
        $order->delete_meta_data( self::META_SPLIT_DONE );
        $order->delete_meta_data( self::META_GUIAS );
        $order->save();

        $result = self::process_order( $order );

        wp_send_json_success( [
            'message' => sprintf(
                'Split ejecutado: %d guía(s) generadas, %d error(es).',
                count( array_filter( $result, fn( $r ) => $r['ok'] ) ),
                count( array_filter( $result, fn( $r ) => ! $r['ok'] ) )
            ),
            'guias'   => $result,
        ] );
    }

    /* ------------------------------------------------------------------ */
    /* Motor principal                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Procesa el split completo para un pedido.
     *
     * @param  WC_Order $order
     * @return array    Resultados indexados por vendor_id
     */
    public static function process_order( WC_Order $order ): array {
        $order_id = $order->get_id();

        // 1. Agrupar ítems por vendedor
        $groups = self::group_items_by_vendor( $order );

        if ( empty( $groups ) ) {
            self::log( $order_id, 'No hay ítems agrupables por vendedor.' );
            return [];
        }

        // 2. Instanciar cliente API
        $username = get_option( 'ltms_deprisa_username', '' );
        $password = get_option( 'ltms_deprisa_password', '' );
        $sandbox  = (bool) get_option( 'ltms_deprisa_sandbox', true );

        if ( empty( $username ) || empty( $password ) ) {
            self::log( $order_id, 'Credenciales Deprisa no configuradas.' );
            return [];
        }

        try {
            $api = new LTMS_Api_Deprisa( $username, $password, $sandbox );
        } catch ( \Throwable $e ) {
            self::log( $order_id, 'Error instanciando API: ' . $e->getMessage() );
            return [];
        }

        // 3. Destino del pedido (siempre 1 en WC nativo)
        $dest = self::extract_destination( $order );

        // 4. Generar una guía por grupo/origen
        $resultados = [];
        foreach ( $groups as $vendor_id => $group ) {
            $resultados[ $vendor_id ] = self::admitir_grupo( $api, $order, $vendor_id, $group, $dest );
        }

        // 5. Persistir resultados
        $order->update_meta_data( self::META_GUIAS,      $resultados );
        $order->update_meta_data( self::META_SPLIT_DONE, 'yes' );
        $order->update_meta_data( self::META_SPLIT_TS,   current_time( 'mysql' ) );
        $order->save();

        // 6. Nota en el pedido
        $ok_count  = count( array_filter( $resultados, fn( $r ) => $r['ok'] ) );
        $err_count = count( $resultados ) - $ok_count;
        $order->add_order_note(
            sprintf(
                '🚚 Deprisa split multi-origen: %d guía(s) generadas, %d error(es). Vendedores: %s',
                $ok_count,
                $err_count,
                implode( ', ', array_keys( $groups ) )
            )
        );

        return $resultados;
    }

    /* ------------------------------------------------------------------ */
    /* Agrupación de ítems por vendedor                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Agrupa los line items del pedido por vendor_id.
     * Compatible con WCFM, Dokan y metadatos propios de LTMS.
     *
     * @return array [ vendor_id => [ 'vendor_data' => […], 'items' => [WC_Order_Item_Product] ] ]
     */
    private static function group_items_by_vendor( WC_Order $order ): array {
        $groups = [];

        foreach ( $order->get_items() as $item_id => $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            $product   = $item->get_product();
            $vendor_id = self::get_vendor_id_for_item( $item, $product );

            if ( ! $vendor_id ) {
                // Ítems sin vendedor van a la tienda principal (vendor_id = 0)
                $vendor_id = 0;
            }

            if ( ! isset( $groups[ $vendor_id ] ) ) {
                $groups[ $vendor_id ] = [
                    'vendor_data' => self::get_vendor_data( $vendor_id ),
                    'items'       => [],
                    'peso_total'  => 0.0,
                    'bultos'      => 0,
                    'valor_total' => 0.0,
                ];
            }

            $groups[ $vendor_id ]['items'][]     = $item;
            $groups[ $vendor_id ]['bultos']      += (int) $item->get_quantity();
            $groups[ $vendor_id ]['peso_total']  += self::get_item_weight( $item, $product );
            $groups[ $vendor_id ]['valor_total'] += (float) $item->get_total();
        }

        return $groups;
    }

    /**
     * Obtiene el vendor_id de un ítem.
     * Prioridad: meta _vendor_id → post_author del producto → 0
     */
    private static function get_vendor_id_for_item( WC_Order_Item_Product $item, $product ): int {
        // Meta puesto por WCFM / Dokan / LTMS
        $vendor_id = (int) $item->get_meta( '_vendor_id' );
        if ( $vendor_id ) {
            return $vendor_id;
        }

        // WCFM guarda _wcfmmp_vendor en el line item
        $vendor_id = (int) $item->get_meta( '_wcfmmp_vendor' );
        if ( $vendor_id ) {
            return $vendor_id;
        }

        // Dokan
        $vendor_id = (int) $item->get_meta( '_dokan_vendor_id' );
        if ( $vendor_id ) {
            return $vendor_id;
        }

        // Fallback: post_author del producto
        if ( $product && $product->get_id() ) {
            $post      = get_post( $product->get_id() );
            $vendor_id = $post ? (int) $post->post_author : 0;
            if ( $vendor_id > 1 ) { // > 1 descarta al admin principal
                return $vendor_id;
            }
        }

        return 0;
    }

    /**
     * Construye los datos del remitente para un vendor_id.
     * Busca primero meta LTMS propios, luego user_meta estándar.
     */
    private static function get_vendor_data( int $vendor_id ): array {
        // Si vendor_id = 0 → usar configuración global de la tienda
        if ( $vendor_id === 0 ) {
            return self::get_store_remitente_data();
        }

        // Meta específicos de bodega registrados en LTMS
        $meta_prefix = '_ltms_vendor_deprisa_';
        $get = fn( string $key, string $default = '' ) => get_user_meta( $vendor_id, $meta_prefix . $key, true ) ?: $default;

        $cliente = $get( 'cliente_remitente' );
        $centro  = $get( 'centro_remitente' );

        // Si el vendedor no tiene datos Deprisa propios → usar los globales
        if ( empty( $cliente ) || empty( $centro ) ) {
            return self::get_store_remitente_data();
        }

        return [
            'cliente_remitente'      => $cliente,
            'centro_remitente'       => $centro,
            'nombre_remitente'       => $get( 'nombre_remitente', get_user_meta( $vendor_id, 'billing_first_name', true ) . ' ' . get_user_meta( $vendor_id, 'billing_last_name', true ) ),
            'direccion_remitente'    => $get( 'direccion_remitente', get_user_meta( $vendor_id, 'billing_address_1', true ) ),
            'pais_remitente'         => $get( 'pais_remitente', '057' ),
            'cp_remitente'           => $get( 'cp_remitente', get_user_meta( $vendor_id, 'billing_postcode', true ) ),
            'ciudad_remitente'       => strtoupper( $get( 'ciudad_remitente', get_user_meta( $vendor_id, 'billing_city', true ) ?: 'BOGOTA' ) ),
            'tipo_doc_remitente'     => $get( 'tipo_doc_remitente', 'NIT' ),
            'nit_remitente'          => $get( 'nit_remitente' ),
            'contacto_remitente'     => $get( 'contacto_remitente', get_userdata( $vendor_id )->display_name ?? '' ),
            'telefono_remitente'     => $get( 'telefono_remitente', get_user_meta( $vendor_id, 'billing_phone', true ) ),
            'source'                 => 'vendor_meta',
        ];
    }

    /** Remitente desde la configuración global de la tienda (options Deprisa). */
    private static function get_store_remitente_data(): array {
        return [
            'cliente_remitente'   => get_option( 'ltms_deprisa_cliente_remitente', '' ),
            'centro_remitente'    => get_option( 'ltms_deprisa_centro_remitente',  '01' ),
            'nombre_remitente'    => get_option( 'ltms_deprisa_contacto_remitente', get_bloginfo( 'name' ) ),
            'direccion_remitente' => get_option( 'ltms_deprisa_direccion_remitente', '' ),
            'pais_remitente'      => '057',
            'cp_remitente'        => get_option( 'ltms_deprisa_cp_remitente', '' ),
            'ciudad_remitente'    => strtoupper( get_option( 'ltms_deprisa_ciudad_remitente', 'BOGOTA' ) ),
            'tipo_doc_remitente'  => get_option( 'ltms_deprisa_tipo_doc_remitente', 'NIT' ),
            'nit_remitente'       => get_option( 'ltms_deprisa_nit_remitente', '' ),
            'contacto_remitente'  => get_option( 'ltms_deprisa_contacto_remitente', '' ),
            'telefono_remitente'  => get_option( 'ltms_deprisa_telefono_remitente', '' ),
            'source'              => 'store_options',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Extracción del destino desde el pedido                              */
    /* ------------------------------------------------------------------ */

    private static function extract_destination( WC_Order $order ): array {
        return [
            'nombre_destinatario'    => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
            'direccion_destinatario' => $order->get_shipping_address_1() . ( $order->get_shipping_address_2() ? ', ' . $order->get_shipping_address_2() : '' ),
            'pais_destinatario'      => '057',
            'cp_destinatario'        => $order->get_shipping_postcode() ?: '',
            'ciudad_destinatario'    => strtoupper( $order->get_shipping_city() ?: $order->get_billing_city() ?: 'BOGOTA' ),
            'tipo_doc_destinatario'  => 'CC',
            'nit_destinatario'       => preg_replace( '/\D/', '', $order->get_billing_phone() ?: '0' ),
            'contacto_destinatario'  => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
            'telefono_destinatario'  => preg_replace( '/\D/', '', $order->get_billing_phone() ?: '' ),
            'email_destinatario'     => $order->get_billing_email(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Llamada a la API por grupo                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Admite un sub-envío y retorna el resultado estructurado.
     */
    private static function admitir_grupo(
        LTMS_Api_Deprisa $api,
        WC_Order         $order,
        int              $vendor_id,
        array            $group,
        array            $dest
    ): array {
        $order_id   = $order->get_id();
        $rem        = $group['vendor_data'];
        $peso       = max( 0.1, round( $group['peso_total'], 3 ) );
        $bultos     = max( 1, $group['bultos'] );
        $valor      = round( $group['valor_total'] );
        $cod_admision = LTMS_Api_Deprisa::generar_codigo_admision( $order_id . '-' . $vendor_id );

        // Determinar si es contraentrega
        $servicio = self::get_servicio_code( $order );

        $payload = [
            // Control
            'grabar_envio'               => 'S',
            'codigo_admision'            => $cod_admision,
            'numero_bultos'              => $bultos,

            // Remitente (viene del vendedor o la tienda)
            'cliente_remitente'          => $rem['cliente_remitente'],
            'centro_remitente'           => $rem['centro_remitente'],
            'nombre_remitente'           => $rem['nombre_remitente']    ?? '',
            'direccion_remitente'        => $rem['direccion_remitente'] ?? '',
            'pais_remitente'             => $rem['pais_remitente']      ?? '057',
            'codigo_postal_remitente'    => $rem['cp_remitente']        ?? '',
            'poblacion_remitente'        => $rem['ciudad_remitente']    ?? 'BOGOTA',
            'tipo_doc_remitente'         => $rem['tipo_doc_remitente']  ?? 'NIT',
            'documento_identidad_remitente' => $rem['nit_remitente']   ?? '',
            'persona_contacto_remitente' => $rem['contacto_remitente'] ?? '',
            'telefono_contacto_remitente'=> $rem['telefono_remitente'] ?? '',

            // Destinatario (siempre el comprador)
            'cliente_destinatario'       => '99999999',
            'centro_destinatario'        => '99',
            'nombre_destinatario'        => $dest['nombre_destinatario'],
            'direccion_destinatario'     => $dest['direccion_destinatario'],
            'pais_destinatario'          => $dest['pais_destinatario'],
            'codigo_postal_destinatario' => $dest['cp_destinatario'],
            'poblacion_destinatario'     => $dest['ciudad_destinatario'],
            'tipo_doc_destinatario'      => $dest['tipo_doc_destinatario'],
            'documento_identidad_destinatario' => $dest['nit_destinatario'],
            'persona_contacto_destinatario'    => $dest['contacto_destinatario'],
            'telefono_contacto_destinatario'   => $dest['telefono_destinatario'],
            'email_destinatario'         => $dest['email_destinatario'],

            // Envío
            'codigo_servicio'            => $servicio,
            'kilos'                      => $peso,
            'importe_valor_declarado'    => $valor,
            'tipo_portes'                => 'P',   // pago en origen
            'asegurar_envio'             => $valor > 0 ? 'S' : 'N',
            'tipo_moneda'                => 'COP',
            'numero_referencia'          => (string) $order_id,
        ];

        // Agregar campos de contraentrega si aplica
        if ( $servicio === '3027' ) {
            $payload['importe_reembolso']      = $valor;
            $payload['tipo_porte_reembolsos']  = 'D';
            $payload['tipo_portes']            = 'P';
        }

        try {
            $resp = $api->admitir_envio( $payload );

            if ( ! $resp['ok'] ) {
                $errores = implode( ', ', array_column( $resp['errors'] ?? [], 'descripcion' ) );
                self::log( $order_id, "Vendor {$vendor_id} — Error API: {$errores}" );

                return [
                    'ok'          => false,
                    'vendor_id'   => $vendor_id,
                    'codigo_admision' => $cod_admision,
                    'errors'      => $resp['errors'] ?? [],
                    'items_count' => count( $group['items'] ),
                    'peso'        => $peso,
                    'bultos'      => $bultos,
                    'remitente'   => $rem['ciudad_remitente'] ?? '—',
                    'source'      => $rem['source'],
                ];
            }

            $numero_envio = $resp['numero_envio'] ?? '';
            self::log( $order_id, "Vendor {$vendor_id} — Guía generada: {$numero_envio}" );

            // Obtener etiqueta PDF
            $etiqueta_b64 = '';
            try {
                $etq = $api->obtener_etiquetas( [ $numero_envio ] );
                if ( $etq['ok'] ) {
                    $etiqueta_b64 = $etq['etiquetas'][ $numero_envio ] ?? '';
                }
            } catch ( \Throwable $e ) {
                self::log( $order_id, "Vendor {$vendor_id} — Etiqueta no obtenida: " . $e->getMessage() );
            }

            return [
                'ok'              => true,
                'vendor_id'       => $vendor_id,
                'codigo_admision' => $cod_admision,
                'numero_envio'    => $numero_envio,
                'etiqueta_b64'    => $etiqueta_b64,
                'items_count'     => count( $group['items'] ),
                'peso'            => $peso,
                'bultos'          => $bultos,
                'remitente'       => $rem['ciudad_remitente'] ?? '—',
                'destino'         => $dest['ciudad_destinatario'],
                'servicio'        => $servicio,
                'source'          => $rem['source'],
                'generated_at'    => current_time( 'mysql' ),
            ];

        } catch ( LTMS_Deprisa_Exception $e ) {
            self::log( $order_id, "Vendor {$vendor_id} — Excepción: " . $e->getMessage() );

            return [
                'ok'          => false,
                'vendor_id'   => $vendor_id,
                'codigo_admision' => $cod_admision,
                'errors'      => [ [ 'descripcion' => $e->getMessage(), 'codigo' => $e->getCode() ] ],
                'items_count' => count( $group['items'] ),
                'peso'        => $peso,
                'bultos'      => $bultos,
                'remitente'   => $rem['ciudad_remitente'] ?? '—',
                'source'      => $rem['source'],
            ];
        }
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                              */
    /* ------------------------------------------------------------------ */

    /** ¿Está Deprisa activo? */
    private static function is_enabled(): bool {
        return (bool) get_option( 'ltms_deprisa_enabled', false );
    }

    /** Código de servicio: 3027 si el método de pago es contraentrega, si no 3005 (o el configurado). */
    private static function get_servicio_code( WC_Order $order ): string {
        $payment_method = $order->get_payment_method();
        // Métodos que indican contraentrega en Lo Tengo
        $cod_methods = [ 'cod', 'ltms_cod', 'cash_on_delivery' ];

        if ( in_array( $payment_method, $cod_methods, true ) ) {
            return '3027';
        }

        return get_option( 'ltms_deprisa_servicio_default', '3005' );
    }

    /** Peso del ítem en kg. Si no tiene peso declarado, asume 0.5 kg por unidad. */
    private static function get_item_weight( WC_Order_Item_Product $item, $product ): float {
        $qty    = max( 1, (int) $item->get_quantity() );
        $weight = $product ? (float) $product->get_weight() : 0.0;

        if ( $weight <= 0 ) {
            $weight = 0.5; // fallback por unidad
        }

        return $weight * $qty;
    }

    /** Logger interno (usa LTMS_Logger si existe, si no error_log). */
    private static function log( int $order_id, string $msg ): void {
        $entry = "[LTMS_Deprisa_Order_Split] Order #{$order_id}: {$msg}";

        if ( class_exists( 'LTMS_Logger' ) ) {
            LTMS_Logger::info( $entry );
        } else {
            error_log( $entry );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Lectura de resultados (para el metabox)                             */
    /* ------------------------------------------------------------------ */

    /**
     * Devuelve los resultados guardados de un pedido.
     *
     * @return array[]
     */
    public static function get_guias( WC_Order $order ): array {
        $raw = $order->get_meta( self::META_GUIAS );
        return is_array( $raw ) ? $raw : [];
    }

    /**
     * Descarga la etiqueta PDF de una guía ya generada.
     * Decodifica el base64 guardado en meta y lo sirve como descarga.
     */
    public static function serve_etiqueta_pdf( WC_Order $order, string $numero_envio ): void {
        $guias = self::get_guias( $order );

        foreach ( $guias as $resultado ) {
            if ( ( $resultado['numero_envio'] ?? '' ) === $numero_envio && ! empty( $resultado['etiqueta_b64'] ) ) {
                $pdf = base64_decode( $resultado['etiqueta_b64'] );
                header( 'Content-Type: application/pdf' );
                header( 'Content-Disposition: attachment; filename="deprisa-' . $numero_envio . '.pdf"' );
                header( 'Content-Length: ' . strlen( $pdf ) );
                echo $pdf;
                exit;
            }
        }

        wp_die( 'Etiqueta no disponible.', 'Error', [ 'response' => 404 ] );
    }
}
