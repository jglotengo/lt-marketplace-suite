<?php
/**
 * LTMS — Devoluciones Deprisa v1.10.0
 *
 * Genera guías de retorno usando el mismo endpoint de admisión_envios
 * con los campos de remitente/destinatario invertidos y RETORNO_ENVIO=S.
 *
 * @package LTMS
 * @since   1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LTMS_Deprisa_Devoluciones {

    const META_DEVOLUCIONES = '_ltms_deprisa_devoluciones';

    /**
     * Meta flag (1 = devolución ya creada para esta guía) para verificación
     * rápida de idempotencia sin tener que deserializar todo el JSON.
     */
    const META_DEVOLUCION_GUIA = '_ltms_deprisa_devolucion_guia';

    /* ------------------------------------------------------------------ */
    /* Registro de AJAX                                                     */
    /* ------------------------------------------------------------------ */

    public static function register(): void {
        add_action( 'wp_ajax_ltms_deprisa_generar_devolucion',  [ self::class, 'ajax_generar' ] );
        add_action( 'wp_ajax_ltms_deprisa_cancelar_devolucion', [ self::class, 'ajax_cancelar' ] );
    }

    /* ------------------------------------------------------------------ */
    /* Generar devolución                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Genera una guía de devolución para un número de envío dado.
     *
     * DP-BUG-6: Idempotencia — si ya existe una devolución para este
     *           order+numero_envio, se retorna WP_Error en lugar de crear
     *           una segunda guía facturable en Deprisa.
     * DP-BUG-2: build_devolucion_payload() espera un array de datos, no un
     *           WC_Order — se ensambla el array antes de llamar.
     * DP-BUG-3: El API devuelve 'exito'/'errores' (no 'ok'/'errors').
     * DP-BUG-4: etiqueta_b64 debe almacenar sólo el string base64, no el
     *           arreglo completo de obtener_etiqueta().
     *
     * @param WC_Order $order
     * @param string   $numero_envio  Guía original
     * @param string   $motivo        Motivo de la devolución
     * @return array|WP_Error
     */
    public static function generar( WC_Order $order, string $numero_envio, string $motivo = '' ): array|WP_Error {
        $order_id = $order->get_id();

        // DP-BUG-6: Idempotencia — verificar flag y registro antes de llamar al API.
        $existing_flag = $order->get_meta( self::META_DEVOLUCION_GUIA . '_' . $numero_envio, true );
        if ( $existing_flag ) {
            $existing_dev = self::get_devolucion( $order, $numero_envio );
            $num_dev_ex   = $existing_dev['numero_envio_devolucion'] ?? '?';
            return new WP_Error(
                'devolucion_exists',
                sprintf( 'Ya existe una devolución para la guía %s (devolución #%s).', $numero_envio, $num_dev_ex )
            );
        }

        // Recuperar la guía original desde el meta del split
        $guias = LTMS_Deprisa_Order_Split::get_guias( $order );
        $guia  = null;
        foreach ( $guias as $g ) {
            if ( ( $g['numero_envio'] ?? '' ) === $numero_envio ) {
                $guia = $g;
                break;
            }
        }
        if ( ! $guia ) {
            return new WP_Error( 'not_found', "Guía {$numero_envio} no encontrada en el pedido." );
        }

        // DP-BUG-2: Ensambolar array de datos original (NO pasar WC_Order a build_devolucion_payload).
        $datos_pedido_original = self::build_datos_pedido_original( $order, $guia );

        // Preparar datos: invertir remitente / destinatario
        // AUDIT-SHIPPING-ENGINE #6 FIX: usar LTMS_Api_Deprisa (clase canónica)
        // en vez de LTMS_Deprisa_API (clase duplicada que causaba Fatal Error).
        $username = get_option( 'ltms_deprisa_username', '' );
        $password_raw = get_option( 'ltms_deprisa_password', '' );
        $password = ( str_starts_with( $password_raw, 'v1:' ) && class_exists( 'LTMS_Core_Security' ) )
            ? LTMS_Core_Security::decrypt( $password_raw ) : $password_raw;
        $sandbox = (bool) get_option( 'ltms_deprisa_sandbox', true );
        $api = new LTMS_Api_Deprisa( $username, $password, $sandbox );
        $payload = $api->build_devolucion_payload( $datos_pedido_original, $motivo );
        if ( is_wp_error( $payload ) ) return $payload;

        $resultado = $api->admitir_envio( $payload );
        if ( is_wp_error( $resultado ) ) return $resultado;

        // DP-BUG-3: El API devuelve 'exito'/'errores' (no 'ok'/'errors').
        if ( empty( $resultado['exito'] ) ) {
            $errors = $resultado['errores'] ?? [];
            $desc   = implode( ', ', array_column( $errors, 'descripcion' ) );
            return new WP_Error( 'deprisa_error', "Error al generar devolución: $desc" );
        }

        // Solicitar etiqueta de la devolución
        $num_dev = $resultado['numero_envio'] ?? '';
        if ( $num_dev ) {
            $etiqueta = $api->obtener_etiqueta( $num_dev );
            if ( ! is_wp_error( $etiqueta ) ) {
                // DP-BUG-4: Sólo almacenar el string base64, no el arreglo completo.
                $resultado['etiqueta_b64'] = $etiqueta['base64'] ?? '';
            }
        }

        // DP-BUG-7 (DEV-BUG-7): Eliminar 'raw' y 'http_code' antes de persistir
        // para evitar inflar el meta del pedido con el body XML completo.
        unset( $resultado['raw'], $resultado['http_code'] );

        // Guardar en meta del pedido
        $devoluciones = self::get_devoluciones_raw( $order );
        $devoluciones[ $numero_envio ] = array_merge( $resultado, [
            'numero_envio_original'    => $numero_envio,
            'numero_envio_devolucion'  => $num_dev,
            'motivo'                   => $motivo,
            'generated_at'             => current_time( 'mysql' ),
            'generated_by'             => get_current_user_id(),
        ] );

        $order->update_meta_data( self::META_DEVOLUCIONES, wp_json_encode( $devoluciones ) );
        // DP-BUG-6: Marca de idempotencia (post-creación exitosa).
        $order->update_meta_data( self::META_DEVOLUCION_GUIA . '_' . $numero_envio, $num_dev ?: '1' );
        $order->add_order_note( "↩️ Devolución generada: guía #{$num_dev} (origen: #{$numero_envio}). Motivo: {$motivo}" );
        $order->save();

        return $devoluciones[ $numero_envio ];
    }

    /**
     * Ensambola el array $datos_pedido_original que build_devolucion_payload()
     * espera, combinando el meta del split (bultos/peso/numero_envio), el
     * WC_Order (destinatario) y los datos Deprisa del vendedor (remitente).
     *
     * @param WC_Order $order
     * @param array    $guia  Entrada del meta _ltms_deprisa_guias
     * @return array
     */
    private static function build_datos_pedido_original( WC_Order $order, array $guia ): array {
        $vendor_id = (int) ( $guia['vendor_id'] ?? 0 );
        $rem       = self::get_remitente_data( $vendor_id );

        $nombre_dest    = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
        $direccion_dest = trim( $order->get_shipping_address_1() . ( $order->get_shipping_address_2() ? ', ' . $order->get_shipping_address_2() : '' ) );
        $telefono_dest  = preg_replace( '/\D/', '', $order->get_billing_phone() ?: '' );

        return array(
            // Datos del envío original (desde el meta del split)
            'numero_bultos'             => $guia['bultos'] ?? 1,
            'kilos'                     => $guia['peso'] ?? 1,
            'numero_envio'              => $guia['numero_envio'] ?? '',
            'importe_valor_declarado'   => 0, // devoluciones no declaran valor

            // Remitente original (= vendedor / tienda) — será invertido a destinatario
            'cliente_remitente'         => $rem['cliente_remitente'],
            'centro_remitente'          => $rem['centro_remitente'],
            'nombre_remitente'          => $rem['nombre_remitente'],
            'direccion_remitente'       => $rem['direccion_remitente'],
            'pais_remitente'            => $rem['pais_remitente'],
            'codigo_postal_remitente'   => $rem['codigo_postal_remitente'],
            'poblacion_remitente'       => $rem['poblacion_remitente'],
            'tipo_doc_remitente'        => $rem['tipo_doc_remitente'],
            'documento_remitente'       => $rem['documento_remitente'],
            'persona_contacto_remitente'=> $rem['persona_contacto_remitente'],
            'telefono_remitente'        => $rem['telefono_remitente'],
            'email_remitente'           => $rem['email_remitente'],

            // Destinatario original (= cliente) — será invertido a remitente
            'cliente_destinatario'      => '99999999',
            'centro_destinatario'       => '99',
            'nombre_destinatario'       => $nombre_dest,
            'direccion_destinatario'    => $direccion_dest,
            'pais_destinatario'         => '057',
            'codigo_postal_destinatario'=> $order->get_shipping_postcode() ?: '',
            'poblacion_destinatario'    => strtoupper( $order->get_shipping_city() ?: $order->get_billing_city() ?: 'BOGOTA' ),
            'tipo_doc_destinatario'     => 'CC',
            'documento_destinatario'    => preg_replace( '/\D/', '', $order->get_billing_phone() ?: '0' ),
            'persona_contacto_destinatario' => $nombre_dest,
            'telefono_destinatario'     => $telefono_dest,
            'email_destinatario'        => $order->get_billing_email() ?: '',
        );
    }

    /**
     * Lee los datos Deprisa del remitente (vendedor o tienda si vendor_id=0).
     * Duplica la lógica privada de LTMS_Deprisa_Order_Split::get_vendor_data()
     * para no acoplar el módulo de devoluciones al interno del split.
     *
     * @param int $vendor_id
     * @return array
     */
    private static function get_remitente_data( int $vendor_id ): array {
        if ( $vendor_id > 0 ) {
            $prefix  = '_ltms_vendor_deprisa_';
            $get     = static function ( string $key, string $default = '' ) use ( $vendor_id, $prefix ) {
                $val = get_user_meta( $vendor_id, $prefix . $key, true );
                return $val ? $val : $default;
            };
            $cliente = $get( 'cliente_remitente' );
            $centro  = $get( 'centro_remitente' );
            if ( $cliente && $centro ) {
                $userdata = get_userdata( $vendor_id );
                $display  = $userdata ? ( $userdata->display_name ?: '' ) : '';
                return array(
                    'cliente_remitente'         => $cliente,
                    'centro_remitente'          => $centro,
                    'nombre_remitente'          => $get( 'nombre_remitente', trim( get_user_meta( $vendor_id, 'billing_first_name', true ) . ' ' . get_user_meta( $vendor_id, 'billing_last_name', true ) ) ),
                    'direccion_remitente'       => $get( 'direccion_remitente', get_user_meta( $vendor_id, 'billing_address_1', true ) ),
                    'pais_remitente'            => '057',
                    'codigo_postal_remitente'   => $get( 'cp_remitente', get_user_meta( $vendor_id, 'billing_postcode', true ) ),
                    'poblacion_remitente'       => strtoupper( $get( 'ciudad_remitente', get_user_meta( $vendor_id, 'billing_city', true ) ?: 'BOGOTA' ) ),
                    'tipo_doc_remitente'        => $get( 'tipo_doc_remitente', 'NIT' ),
                    'documento_remitente'       => $get( 'nit_remitente' ),
                    'persona_contacto_remitente'=> $get( 'contacto_remitente', $display ),
                    'telefono_remitente'        => $get( 'telefono_remitente', get_user_meta( $vendor_id, 'billing_phone', true ) ),
                    'email_remitente'           => get_user_meta( $vendor_id, 'billing_email', true ) ?: '',
                );
            }
        }
        // Fallback: datos globales de la tienda
        return array(
            'cliente_remitente'         => get_option( 'ltms_deprisa_cliente_remitente', '' ),
            'centro_remitente'          => get_option( 'ltms_deprisa_centro_remitente', '01' ),
            'nombre_remitente'          => get_option( 'ltms_deprisa_contacto_remitente', get_bloginfo( 'name' ) ),
            'direccion_remitente'       => get_option( 'ltms_deprisa_direccion_remitente', '' ),
            'pais_remitente'            => '057',
            'codigo_postal_remitente'   => get_option( 'ltms_deprisa_cp_remitente', '' ),
            'poblacion_remitente'       => strtoupper( get_option( 'ltms_deprisa_ciudad_remitente', 'BOGOTA' ) ),
            'tipo_doc_remitente'        => get_option( 'ltms_deprisa_tipo_doc_remitente', 'NIT' ),
            'documento_remitente'       => get_option( 'ltms_deprisa_nit_remitente', '' ),
            'persona_contacto_remitente'=> get_option( 'ltms_deprisa_contacto_remitente', '' ),
            'telefono_remitente'        => get_option( 'ltms_deprisa_telefono_remitente', '' ),
            'email_remitente'           => get_option( 'admin_email' ),
        );
    }

    /* ------------------------------------------------------------------ */
    /* Cancelar devolución                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Cancela una devolución: primero intenta anular la guía de devolución
     * en Deprisa (para evitar cobros) y luego elimina el registro local.
     *
     * DP-BUG-5: Antes de eliminar el meta local, llama a la API de Deprisa
     *           para cancelar la guía de devolución. Si la llamada falla,
     *           se registra el error pero se permite la cancelación local
     *           (no se bloquea al admin).
     *
     * @param WC_Order $order
     * @param string   $numero_envio_original
     * @return bool|WP_Error
     */
    public static function cancelar( WC_Order $order, string $numero_envio_original ): bool|WP_Error {
        $devoluciones = self::get_devoluciones_raw( $order );

        if ( ! isset( $devoluciones[ $numero_envio_original ] ) ) {
            return new WP_Error( 'not_found', 'No existe devolución para esta guía.' );
        }

        $dev     = $devoluciones[ $numero_envio_original ];
        $num_dev = $dev['numero_envio_devolucion'] ?? '';

        // DP-BUG-5: Llamar a Deprisa para cancelar la guía de devolución real.
        if ( $num_dev ) {
            try {
                $api        = new LTMS_Api_Deprisa( $username, $password, $sandbox );
                // Re-use credentials from the create path above.
                $username_d = get_option( 'ltms_deprisa_username', '' );
                $password_raw_d = get_option( 'ltms_deprisa_password', '' );
                $password_d = ( str_starts_with( $password_raw_d, 'v1:' ) && class_exists( 'LTMS_Core_Security' ) )
                    ? LTMS_Core_Security::decrypt( $password_raw_d ) : $password_raw_d;
                $sandbox_d = (bool) get_option( 'ltms_deprisa_sandbox', true );
                $api = new LTMS_Api_Deprisa( $username_d, $password_d, $sandbox_d );
                $motivo_api = $dev['motivo'] ?? 'Cancelación administrativa';
                $resp       = $api->cancelar_envio( $num_dev, $motivo_api );

                if ( is_wp_error( $resp ) ) {
                    self::log_cancel_error( $order, $num_dev, $resp->get_error_message() );
                } elseif ( empty( $resp['exito'] ) ) {
                    $errs = $resp['errores'] ?? [];
                    $desc = implode( ', ', array_column( $errs, 'descripcion' ) );
                    self::log_cancel_error( $order, $num_dev, $desc ?: "HTTP {$resp['http_code']}" );
                }
            } catch ( \Throwable $e ) {
                self::log_cancel_error( $order, $num_dev, $e->getMessage() );
            }
        }

        unset( $devoluciones[ $numero_envio_original ] );
        $order->update_meta_data( self::META_DEVOLUCIONES, wp_json_encode( $devoluciones ) );
        $order->delete_meta_data( self::META_DEVOLUCION_GUIA . '_' . $numero_envio_original );
        $order->add_order_note( "↩️ Registro de devolución eliminado para guía #{$numero_envio_original}." );
        $order->save();

        return true;
    }

    /**
     * Registra en nota del pedido + LTMS_Core_Logger un fallo al cancelar la
     * devolución en Deprisa. No bloquea la cancelación local.
     */
    private static function log_cancel_error( WC_Order $order, string $num_dev, string $msg ): void {
        $order->add_order_note(
            sprintf( '⚠️ Cancelación Deprisa falló para devolución #%s: %s (se eliminó el registro local).', $num_dev, $msg )
        );
        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::warning(
                'DEPRISA_DEVOLUCION_CANCEL_FAILED',
                sprintf( 'Order #%d — Devolución %s: %s', $order->get_id(), $num_dev, $msg )
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Helpers de lectura                                                   */
    /* ------------------------------------------------------------------ */

    public static function get_devolucion( WC_Order $order, string $numero_envio ): ?array {
        $all = self::get_devoluciones_raw( $order );
        return $all[ $numero_envio ] ?? null;
    }

    private static function get_devoluciones_raw( WC_Order $order ): array {
        $raw = $order->get_meta( self::META_DEVOLUCIONES );
        if ( ! $raw ) return [];
        $decoded = json_decode( $raw, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /* ------------------------------------------------------------------ */
    /* AJAX handlers                                                        */
    /* ------------------------------------------------------------------ */

    public static function ajax_generar(): void {
        check_ajax_referer( 'ltms_deprisa_devolucion' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permisos.' ] );
        }

        $order_id     = absint( $_POST['order_id']      ?? 0 );
        $numero_envio = sanitize_text_field( $_POST['numero_envio'] ?? '' );
        $motivo       = sanitize_text_field( $_POST['motivo']       ?? 'Devolución solicitada por el cliente' );
        $order        = wc_get_order( $order_id );

        if ( ! $order || ! $numero_envio ) {
            wp_send_json_error( [ 'message' => 'Parámetros inválidos.' ] );
        }

        $resultado = self::generar( $order, $numero_envio, $motivo );

        if ( is_wp_error( $resultado ) ) {
            wp_send_json_error( [ 'message' => $resultado->get_error_message() ] );
        }

        $num_dev = $resultado['numero_envio_devolucion'] ?? '?';
        wp_send_json_success( [ 'message' => "Devolución generada: guía #{$num_dev}" ] );
    }

    public static function ajax_cancelar(): void {
        check_ajax_referer( 'ltms_deprisa_devolucion' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permisos.' ] );
        }

        $order_id     = absint( $_POST['order_id']      ?? 0 );
        $numero_envio = sanitize_text_field( $_POST['numero_envio'] ?? '' );
        $order        = wc_get_order( $order_id );

        if ( ! $order || ! $numero_envio ) {
            wp_send_json_error( [ 'message' => 'Parámetros inválidos.' ] );
        }

        $result = self::cancelar( $order, $numero_envio );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [ 'message' => 'Registro de devolución eliminado.' ] );
    }
}
