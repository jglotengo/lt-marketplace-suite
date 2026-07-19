<?php
/**
 * LTMS Vendor Invoicing Settings
 *
 * v2.9.222: Permite a cada vendedor configurar sus propias credenciales
 * de Alegra o Siigo para emitir facturas electrónicas directamente desde
 * su panel del marketplace.
 *
 * Las credenciales se guardan CIFRADAS en user_meta (ltms_vendor_alegra_email,
 * ltms_vendor_alegra_token, ltms_vendor_siigo_username, ltms_vendor_siigo_key)
 * usando LTMS_Core_Security::encrypt().
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @version    2.9.222
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Vendor_Invoicing_Settings {

    public static function init(): void {
        // AJAX endpoints (vendor dashboard).
        add_action( 'wp_ajax_ltms_vendor_save_invoicing_creds', [ __CLASS__, 'ajax_save_credentials' ] );
        add_action( 'wp_ajax_ltms_vendor_test_invoicing_connection', [ __CLASS__, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_ltms_vendor_generate_invoice', [ __CLASS__, 'ajax_generate_invoice' ] );
    }

    /**
     * Obtiene el proveedor de facturación configurado por el vendor.
     *
     * @param int $vendor_id ID del usuario vendedor.
     * @return string 'alegra' | 'siigo' | ''
     */
    public static function get_provider( int $vendor_id ): string {
        return (string) get_user_meta( $vendor_id, 'ltms_vendor_invoice_provider', true );
    }

    /**
     * Obtiene las credenciales descifradas del vendor.
     *
     * @param int $vendor_id ID del usuario vendedor.
     * @return array{email:string,token:string}|array{username:string,key:string}|array{}
     */
    public static function get_credentials( int $vendor_id ): array {
        $provider = self::get_provider( $vendor_id );
        if ( $provider === 'alegra' ) {
            $email = (string) get_user_meta( $vendor_id, 'ltms_vendor_alegra_email', true );
            $enc   = (string) get_user_meta( $vendor_id, 'ltms_vendor_alegra_token', true );
            if ( empty( $email ) || empty( $enc ) ) {
                return [];
            }
            $token = class_exists( 'LTMS_Core_Security' )
                ? LTMS_Core_Security::decrypt( $enc )
                : $enc;
            return [ 'email' => $email, 'token' => $token ];
        }
        if ( $provider === 'siigo' ) {
            $enc_u = (string) get_user_meta( $vendor_id, 'ltms_vendor_siigo_username', true );
            $enc_k = (string) get_user_meta( $vendor_id, 'ltms_vendor_siigo_key', true );
            if ( empty( $enc_u ) || empty( $enc_k ) ) {
                return [];
            }
            $u = class_exists( 'LTMS_Core_Security' ) ? LTMS_Core_Security::decrypt( $enc_u ) : $enc_u;
            $k = class_exists( 'LTMS_Core_Security' ) ? LTMS_Core_Security::decrypt( $enc_k ) : $enc_k;
            return [ 'username' => $u, 'key' => $k ];
        }
        return [];
    }

    /**
     * ¿El vendor tiene credenciales configuradas?
     */
    public static function is_configured( int $vendor_id ): bool {
        return ! empty( self::get_credentials( $vendor_id ) );
    }

    /**
     * AJAX: guarda las credenciales del vendor (cifradas).
     */
    public static function ajax_save_credentials(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Debes iniciar sesión.', 'ltms' ) ], 401 );
        }

        $vendor_id = get_current_user_id();
        if ( ! user_can( $vendor_id, 'edit_products' ) ) {
            wp_send_json_error( [ 'message' => __( 'Sin permisos.', 'ltms' ) ], 403 );
        }

        $provider = sanitize_text_field( $_POST['provider'] ?? '' );
        if ( ! in_array( $provider, [ 'alegra', 'siigo', '' ], true ) ) {
            wp_send_json_error( [ 'message' => __( 'Proveedor inválido.', 'ltms' ) ], 400 );
        }

        update_user_meta( $vendor_id, 'ltms_vendor_invoice_provider', $provider );

        if ( $provider === 'alegra' ) {
            $email = sanitize_email( $_POST['alegra_email'] ?? '' );
            $token = sanitize_text_field( $_POST['alegra_token'] ?? '' );
            if ( empty( $email ) || empty( $token ) ) {
                wp_send_json_error( [ 'message' => __( 'Email y token son obligatorios.', 'ltms' ) ], 400 );
            }
            update_user_meta( $vendor_id, 'ltms_vendor_alegra_email', $email );
            if ( class_exists( 'LTMS_Core_Security' ) ) {
                $token = LTMS_Core_Security::encrypt( $token );
            }
            update_user_meta( $vendor_id, 'ltms_vendor_alegra_token', $token );
        } elseif ( $provider === 'siigo' ) {
            $username = sanitize_text_field( $_POST['siigo_username'] ?? '' );
            $key      = sanitize_text_field( $_POST['siigo_key'] ?? '' );
            if ( empty( $username ) || empty( $key ) ) {
                wp_send_json_error( [ 'message' => __( 'Username y access key son obligatorios.', 'ltms' ) ], 400 );
            }
            if ( class_exists( 'LTMS_Core_Security' ) ) {
                $username = LTMS_Core_Security::encrypt( $username );
                $key      = LTMS_Core_Security::encrypt( $key );
            }
            update_user_meta( $vendor_id, 'ltms_vendor_siigo_username', $username );
            update_user_meta( $vendor_id, 'ltms_vendor_siigo_key', $key );
        }

        wp_send_json_success( [
            'message' => __( 'Credenciales guardadas correctamente.', 'ltms' ),
        ] );
    }

    /**
     * AJAX: prueba la conexión con las credenciales configuradas.
     */
    public static function ajax_test_connection(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Debes iniciar sesión.', 'ltms' ) ], 401 );
        }

        $vendor_id = get_current_user_id();
        $provider  = self::get_provider( $vendor_id );
        $creds     = self::get_credentials( $vendor_id );

        if ( empty( $provider ) || empty( $creds ) ) {
            wp_send_json_error( [ 'message' => __( 'Configura credenciales primero.', 'ltms' ) ], 400 );
        }

        if ( $provider === 'alegra' ) {
            $result = self::test_alegra( $creds['email'], $creds['token'] );
        } else {
            $result = self::test_siigo( $creds['username'], $creds['key'] );
        }

        if ( $result['success'] ) {
            wp_send_json_success( [
                'message' => sprintf(
                    /* translators: %s: nombre de la empresa desde la API */
                    __( '✅ Conectado a %s', 'ltms' ),
                    $result['company'] ?? $provider
                ),
            ] );
        } else {
            wp_send_json_error( [
                'message' => sprintf(
                    /* translators: %s: mensaje de error de la API */
                    __( '❌ Error: %s', 'ltms' ),
                    $result['error']
                ),
            ], 400 );
        }
    }

    /**
     * AJAX: genera factura para un pedido usando las credenciales del vendor.
     */
    public static function ajax_generate_invoice(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Debes iniciar sesión.', 'ltms' ) ], 401 );
        }

        $vendor_id = get_current_user_id();
        $order_id  = (int) ( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json_error( [ 'message' => __( 'Pedido inválido.', 'ltms' ) ], 400 );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => __( 'Pedido no encontrado.', 'ltms' ) ], 404 );
        }

        // Verificar que el vendor tiene items en este pedido.
        $has_items = false;
        foreach ( $order->get_items() as $item ) {
            $pid = $item->get_product_id();
            $vid = (int) get_post_meta( $pid, '_ltms_vendor_id', true );
            if ( ! $vid ) {
                $vid = (int) get_post_field( 'post_author', $pid );
            }
            if ( $vid === $vendor_id ) {
                $has_items = true;
                break;
            }
        }
        if ( ! $has_items ) {
            wp_send_json_error( [ 'message' => __( 'No tienes productos en este pedido.', 'ltms' ) ], 403 );
        }

        // Verificar que no se haya generado ya factura.
        $existing = $order->get_meta( '_ltms_vendor_invoice_' . $vendor_id );
        if ( ! empty( $existing ) ) {
            wp_send_json_error( [
                'message' => __( 'Ya generaste una factura para este pedido.', 'ltms' ),
                'invoice' => $existing,
            ], 409 );
        }

        $provider = self::get_provider( $vendor_id );
        $creds    = self::get_credentials( $vendor_id );
        if ( empty( $provider ) || empty( $creds ) ) {
            wp_send_json_error( [
                'message' => __( 'Configura tus credenciales de facturación primero.', 'ltms' ),
            ], 400 );
        }

        // Generar factura.
        if ( $provider === 'alegra' ) {
            $result = LTMS_Vendor_Invoicing_Generator::generate_alegra_invoice( $vendor_id, $order, $creds );
        } else {
            $result = LTMS_Vendor_Invoicing_Generator::generate_siigo_invoice( $vendor_id, $order, $creds );
        }

        if ( $result['success'] ) {
            // Guardar referencia en order meta.
            $order->update_meta_data( '_ltms_vendor_invoice_' . $vendor_id, [
                'provider'       => $provider,
                'invoice_id'     => $result['invoice_id'],
                'invoice_number' => $result['invoice_number'],
                'generated_at'   => gmdate( 'Y-m-d H:i:s' ),
            ] );
            $order->add_order_note( sprintf(
                /* translators: 1: proveedor, 2: número de factura */
                __( '📄 Factura %1$s generada por el vendedor: %2$s', 'ltms' ),
                strtoupper( $provider ),
                $result['invoice_number']
            ) );
            $order->save();

            wp_send_json_success( [
                'message'        => __( 'Factura generada correctamente.', 'ltms' ),
                'invoice_id'     => $result['invoice_id'],
                'invoice_number' => $result['invoice_number'],
                'provider'       => $provider,
            ] );
        } else {
            wp_send_json_error( [
                'message' => sprintf( __( 'Error al generar factura: %s', 'ltms' ), $result['error'] ),
            ], 500 );
        }
    }

    /**
     * Prueba conexión con Alegra (GET /api/v1/company).
     */
    private static function test_alegra( string $email, string $token ): array {
        $response = wp_remote_get( 'https://api.alegra.com/api/v1/company', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $email . ':' . $token ),
                'Accept'        => 'application/json',
            ],
            'timeout' => 20,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 ) {
            $msg = $body['message'] ?? $body['error'] ?? __( 'HTTP ', 'ltms' ) . $code;
            return [ 'success' => false, 'error' => $msg ];
        }
        return [
            'success' => true,
            'company' => $body['name'] ?? $body['company']['name'] ?? 'Alegra',
        ];
    }

    /**
     * Prueba conexión con Siigo (POST /auth/token-b2b/v1).
     */
    private static function test_siigo( string $username, string $key ): array {
        $response = wp_remote_post( 'https://api.siigo.com/auth/token-b2b/v1', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Partner-Id'   => 'ltms',
            ],
            'body' => wp_json_encode( [
                'username'   => $username,
                'access_key' => $key,
            ] ),
            'timeout' => 20,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code !== 200 || empty( $body['access_token'] ) ) {
            $msg = $body['message'] ?? $body['error'] ?? __( 'HTTP ', 'ltms' ) . $code;
            return [ 'success' => false, 'error' => $msg ];
        }
        return [ 'success' => true, 'company' => 'Siigo' ];
    }
}
