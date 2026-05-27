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
     * @param WC_Order $order
     * @param string   $numero_envio  Guía original
     * @param string   $motivo        Motivo de la devolución
     * @return array|WP_Error
     */
    public static function generar( WC_Order $order, string $numero_envio, string $motivo = '' ): array|WP_Error {
        // Recuperar la guía original
        $guias  = LTMS_Deprisa_Order_Split::get_guias( $order );
        $guia   = null;

        foreach ( $guias as $g ) {
            if ( ( $g['numero_envio'] ?? '' ) === $numero_envio ) {
                $guia = $g;
                break;
            }
        }

        if ( ! $guia ) {
            return new WP_Error( 'not_found', "Guía {$numero_envio} no encontrada en el pedido." );
        }

        // Preparar datos: invertir remitente / destinatario
        $api     = new LTMS_Deprisa_API();
        $order_id = $order->get_id();

        $payload = $api->build_devolucion_payload( $order, $guia, $motivo );

        if ( is_wp_error( $payload ) ) return $payload;

        $resultado = $api->admitir_envio( $payload );

        if ( is_wp_error( $resultado ) ) return $resultado;

        if ( empty( $resultado['ok'] ) ) {
            $errors = $resultado['errors'] ?? [];
            $desc   = implode( ', ', array_column( $errors, 'descripcion' ) );
            return new WP_Error( 'deprisa_error', "Error al generar devolución: $desc" );
        }

        // Solicitar etiqueta de la devolución
        $num_dev = $resultado['numero_envio'] ?? '';
        if ( $num_dev ) {
            $etiqueta = $api->obtener_etiqueta( $num_dev );
            if ( ! is_wp_error( $etiqueta ) ) {
                $resultado['etiqueta_b64'] = $etiqueta;
            }
        }

        // Guardar en meta del pedido
        $devoluciones = self::get_devoluciones_raw( $order );
        $devoluciones[ $numero_envio ] = array_merge( $resultado, [
            'numero_envio_original'    => $numero_envio,
            'numero_envio_devolucion'  => $num_dev,
            'motivo'                   => $motivo,
            'generated_at'             => current_time( 'mysql' ),
        ] );

        $order->update_meta_data( self::META_DEVOLUCIONES, wp_json_encode( $devoluciones ) );
        $order->add_order_note( "↩️ Devolución generada: guía #{$num_dev} (origen: #{$numero_envio}). Motivo: {$motivo}" );
        $order->save();

        return $devoluciones[ $numero_envio ];
    }

    /* ------------------------------------------------------------------ */
    /* Cancelar devolución                                                  */
    /* ------------------------------------------------------------------ */

    public static function cancelar( WC_Order $order, string $numero_envio_original ): bool|WP_Error {
        $devoluciones = self::get_devoluciones_raw( $order );

        if ( ! isset( $devoluciones[ $numero_envio_original ] ) ) {
            return new WP_Error( 'not_found', 'No existe devolución para esta guía.' );
        }

        unset( $devoluciones[ $numero_envio_original ] );
        $order->update_meta_data( self::META_DEVOLUCIONES, wp_json_encode( $devoluciones ) );
        $order->add_order_note( "↩️ Registro de devolución eliminado para guía #{$numero_envio_original}." );
        $order->save();

        return true;
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
