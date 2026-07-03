<?php
/**
 * LTMS_Business_Aveonline_Sandbox
 *
 * Integración con los endpoints de Sandbox de Aveonline:
 *   - obtenerEstadoAuth : devuelve ejemplos de todos los estados posibles.
 *   - avanzarEstado     : mueve el estado de una guía sandbox al siguiente paso.
 *
 * Sólo funciona con las empresas de prueba 6077 y 25505.
 *
 * @package LTMS
 * @since   2.9.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Business_Aveonline_Sandbox {

    /** URL base del sandbox de Aveonline */
    const SANDBOX_URL = 'https://aveonline.co/api/nal/v1.0/sandbox/guia.php';

    /** IDs de empresa permitidos por Aveonline para sandbox */
    const ALLOWED_IDS = [ 6077, 25505 ];

    // ── Init ─────────────────────────────────────────────────────────────────

    public static function init(): void {
        add_action( 'wp_ajax_ltms_aveonline_sandbox_estados',  [ __CLASS__, 'ajax_obtener_estados' ] );
        add_action( 'wp_ajax_ltms_aveonline_sandbox_avanzar',  [ __CLASS__, 'ajax_avanzar_estado'  ] );
    }

    // ── AJAX handlers ────────────────────────────────────────────────────────

    /**
     * Handler: obtenerEstadoAuth
     * Devuelve los 7 estados de ejemplo del sandbox.
     */
    public static function ajax_obtener_estados(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'ltms' ) ], 403 );
        }

        $token = sanitize_text_field( wp_unslash( $_POST['token']    ?? '' ) );
        $id    = absint( $_POST['id'] ?? 0 );
        $guia  = sanitize_text_field( wp_unslash( $_POST['guia']     ?? '' ) );

        if ( empty( $token ) || ! $id ) {
            wp_send_json_error( [ 'message' => __( 'Token e ID son obligatorios.', 'ltms' ) ] );
        }

        $result = self::request( [
            'tipo'       => 'obtenerEstadoAuth',
            'token'      => $token,
            'id'         => $id,
            'guia'       => $guia,
            'ordencompra'=> '',
            'referencia' => '',
        ] );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( $result );
    }

    /**
     * Handler: avanzarEstado
     * Mueve el estado de una guía sandbox al siguiente paso (o fuerza uno).
     */
    public static function ajax_avanzar_estado(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'ltms' ) ], 403 );
        }

        $token      = sanitize_text_field( wp_unslash( $_POST['token']      ?? '' ) );
        $id         = absint( $_POST['id']          ?? 0 );
        $guia       = sanitize_text_field( wp_unslash( $_POST['guia']       ?? '' ) );
        $estado     = sanitize_text_field( wp_unslash( $_POST['estado']     ?? '' ) );
        $descripcion= sanitize_textarea_field( wp_unslash( $_POST['descripcion'] ?? '' ) );
        $aclaracion = sanitize_textarea_field( wp_unslash( $_POST['aclaracion']  ?? '' ) );

        if ( empty( $token ) || ! $id || empty( $guia ) ) {
            wp_send_json_error( [ 'message' => __( 'Token, ID y número de guía son obligatorios.', 'ltms' ) ] );
        }

        if ( ! in_array( $id, self::ALLOWED_IDS, true ) ) {
            wp_send_json_error( [ 'message' => sprintf(
                /* translators: %s: IDs permitidos */
                __( 'ID de empresa no autorizado. Sólo se permiten: %s', 'ltms' ),
                implode( ', ', self::ALLOWED_IDS )
            ) ] );
        }

        $payload = [
            'tipo'  => 'avanzarEstado',
            'token' => $token,
            'id'    => $id,
            'guia'  => $guia,
        ];

        if ( ! empty( $estado ) )      { $payload['estado']      = $estado; }
        if ( ! empty( $descripcion ) ) { $payload['descripcion'] = $descripcion; }
        if ( ! empty( $aclaracion ) )  { $payload['aclaracion']  = $aclaracion; }

        $result = self::request( $payload );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( $result );
    }

    // ── HTTP helper ──────────────────────────────────────────────────────────

    /**
     * Envía un POST al sandbox de Aveonline y devuelve el array decodificado
     * o un WP_Error en caso de fallo de red o respuesta no-200.
     *
     * @param  array $payload
     * @return array|WP_Error
     */
    private static function request( array $payload ) {
        $response = wp_remote_post( self::SANDBOX_URL, [
            'headers'     => [ 'Content-Type' => 'application/json; charset=utf-8' ],
            'body'        => wp_json_encode( $payload ),
            'data_format' => 'body',
            'timeout'     => 20,
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! is_array( $data ) ) {
            return new WP_Error( 'invalid_response',
                sprintf( __( 'Respuesta inesperada del sandbox (HTTP %d).', 'ltms' ), $code )
            );
        }

        // Aveonline usa HTTP 4xx con cuerpo JSON en los errores
        if ( $code >= 400 ) {
            $msg = $data['message'] ?? sprintf( __( 'Error HTTP %d del sandbox.', 'ltms' ), $code );
            return new WP_Error( 'sandbox_error', $msg, [ 'http_code' => $code, 'body' => $data ] );
        }

        return $data;
    }
}
