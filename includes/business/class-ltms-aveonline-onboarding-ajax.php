<?php
/**
 * LTMS Aveonline Onboarding — AJAX Handlers
 *
 * Expone los 4 pasos del onboarding de Aveonline como endpoints AJAX
 * para ser consumidos desde el panel del vendedor o el formulario de registro.
 *
 * Actions registradas:
 *   wp_ajax_ltms_aveonline_onboarding_step1   — acceptTerms
 *   wp_ajax_ltms_aveonline_onboarding_step2   — createLead (retorna seed)
 *   wp_ajax_ltms_aveonline_onboarding_step3   — companyStepOne (docs + CIFIN)
 *   wp_ajax_ltms_aveonline_onboarding_step4   — companyStepTwo (datos comerciales)
 *   wp_ajax_ltms_aveonline_onboarding_full    — flujo completo en un solo request
 *   wp_ajax_ltms_aveonline_onboarding_status  — estado del onboarding de un vendedor
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Aveonline_Onboarding_Ajax
 */
class LTMS_Aveonline_Onboarding_Ajax {

    // Meta key donde se guarda el id_empresa_ave del vendedor en WP
    const META_ID_EMPRESA_AVE = '_ltms_ave_empresa_id';
    const META_ONBOARDING_SEED = '_ltms_ave_onboarding_seed';
    const META_ONBOARDING_STATUS = '_ltms_ave_onboarding_status';
    // Posibles valores de status: 'pending' | 'step1' | 'step2' | 'step3' | 'completed' | 'cifin_failed'

    // ── Init ──────────────────────────────────────────────────────────────────

    public static function init(): void {
        $actions = [
            'ltms_aveonline_onboarding_step1'  => 'ajax_step1',
            'ltms_aveonline_onboarding_step2'  => 'ajax_step2',
            'ltms_aveonline_onboarding_step3'  => 'ajax_step3',
            'ltms_aveonline_onboarding_step4'  => 'ajax_step4',
            'ltms_aveonline_onboarding_full'   => 'ajax_full',
            'ltms_aveonline_onboarding_status' => 'ajax_status',
        ];

        foreach ( $actions as $action => $method ) {
            add_action( 'wp_ajax_' . $action, [ __CLASS__, $method ] );
        }
    }

    // ── Paso 1 ────────────────────────────────────────────────────────────────

    public static function ajax_step1(): void {
        self::verify_nonce();

        $data = self::collect([
            'email', 'phone', 'name', 'phoneCode',
            'numberShipments', 'ecommerce', 'codeIso',
        ]);

        $result = LTMS_Api_Aveonline_Onboarding::instance()->accept_terms( $data );

        if ( $result['success'] ) {
            // Marcar al usuario actual como "términos aceptados"
            $user_id = get_current_user_id();
            if ( $user_id ) {
                update_user_meta( $user_id, self::META_ONBOARDING_STATUS, 'step1' );
            }
        }

        self::respond( $result );
    }

    // ── Paso 2 ────────────────────────────────────────────────────────────────

    public static function ajax_step2(): void {
        self::verify_nonce();

        $user_id = get_current_user_id();
        $user    = get_userdata( $user_id );

        $data = self::collect([
            'name', 'email', 'phone', 'password',
            'phoneCode', 'numberShipments', 'ecommerce',
        ]);

        // Autocompletar email si no viene del form
        if ( empty( $data['email'] ) && $user ) {
            $data['email'] = $user->user_email;
        }

        // v2.9.63 DEEP-AUDIT-002 P3-10: Redactar password en logs.
        // La password se envía a Aveonline pero NUNCA debe guardarse en user_meta
        // ni loguearse en texto plano (Ley 1581/2012 — datos sensibles).
        if ( isset( $data['password'] ) ) {
            // La password se usa solo para el API call, no se persiste.
            $password_for_api = $data['password'];
            unset( $data['password'] ); // Remover del array que se podría loguear
            $data['password'] = '[REDACTED]'; // Placeholder para logs
        }

        // Reconstruir data para el API call con la password real.
        if ( isset( $password_for_api ) ) {
            $data['password'] = $password_for_api;
        }

        $result = LTMS_Api_Aveonline_Onboarding::instance()->create_lead( $data );

        if ( $result['success'] && ! empty( $result['seed'] ) ) {
            update_user_meta( $user_id, self::META_ONBOARDING_SEED,   $result['seed'] );
            update_user_meta( $user_id, self::META_ONBOARDING_STATUS, 'step2' );
        }

        // v2.9.63 P3-10: Log sin password (usar array sin password para el log).
        $log_data = $data;
        if ( isset( $log_data['password'] ) ) {
            $log_data['password'] = '[REDACTED]';
        }
        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info( 'AVE_ONBOARDING_STEP2', sprintf( 'Vendor #%d completed step 2', $user_id ), $log_data );
        }

        self::respond( $result );
    }

    // ── Paso 3 ────────────────────────────────────────────────────────────────

    public static function ajax_step3(): void {
        self::verify_nonce();

        $user_id = get_current_user_id();

        // Recuperar seed guardado
        $seed = get_user_meta( $user_id, self::META_ONBOARDING_SEED, true );
        if ( empty( $seed ) ) {
            wp_send_json_error( 'No se encontró el seed. Completa el Paso 2 primero.' );
        }

        $document_type = (int) sanitize_text_field( $_POST['documentType'] ?? '1' );
        $is_juridica   = $document_type === 3;

        $data = [
            'seed'         => $seed,
            'documentType' => $document_type,
            'idDocument'   => sanitize_text_field( $_POST['idDocument'] ?? '' ),
            'phone'        => sanitize_text_field( $_POST['phone'] ?? '' ),
            'aveMetrics'   => false,
        ];

        if ( $is_juridica ) {
            $data['businessName']  = sanitize_text_field( $_POST['businessName']  ?? '' );
            $data['nombrelegal']   = sanitize_text_field( $_POST['nombrelegal']   ?? '' );
            $data['cedulalegal']   = sanitize_text_field( $_POST['cedulalegal']   ?? '' );

            // Documentos jurídicos (base64 enviado desde JS)
            $data['rut']             = self::get_document_field( 'rut' );
            $data['camara_comercio'] = self::get_document_field( 'camara_comercio' );
        } else {
            $data['fullName'] = sanitize_text_field( $_POST['fullName'] ?? '' );
            $data['lastname'] = sanitize_text_field( $_POST['lastname'] ?? '' );

            // Documentos personales
            $data['cedulaFront'] = self::get_document_field( 'cedulaFront' );
            $data['cedulaBack']  = self::get_document_field( 'cedulaBack' );
        }

        $result = LTMS_Api_Aveonline_Onboarding::instance()->company_step_one( $data );

        if ( $result['success'] ) {
            update_user_meta( $user_id, self::META_ONBOARDING_STATUS, 'step3' );
        } elseif ( ! empty( $result['cifin_failed'] ) ) {
            update_user_meta( $user_id, self::META_ONBOARDING_STATUS, 'cifin_failed' );
        }

        self::respond( $result );
    }

    // ── Paso 4 ────────────────────────────────────────────────────────────────

    public static function ajax_step4(): void {
        self::verify_nonce();

        $user_id = get_current_user_id();
        $seed    = get_user_meta( $user_id, self::META_ONBOARDING_SEED, true );

        if ( empty( $seed ) ) {
            wp_send_json_error( 'No se encontró el seed. Completa los pasos anteriores primero.' );
        }

        $data = [
            'seed'      => $seed,
            'tradename' => sanitize_text_field( $_POST['tradename'] ?? '' ),
            'address'   => sanitize_text_field( $_POST['address']   ?? '' ),
            'city'      => sanitize_text_field( $_POST['city']      ?? '' ),
        ];

        $result = LTMS_Api_Aveonline_Onboarding::instance()->company_step_two( $data );

        if ( $result['success'] ) {
            update_user_meta( $user_id, self::META_ONBOARDING_STATUS, 'completed' );

            // Guardar id_empresa_ave como meta del vendedor
            if ( ! empty( $result['id_empresa_ave'] ) ) {
                update_user_meta( $user_id, self::META_ID_EMPRESA_AVE, (int) $result['id_empresa_ave'] );
            }
        }

        self::respond( $result );
    }

    // ── Flujo completo ────────────────────────────────────────────────────────

    /**
     * Para uso programático desde el admin o scripts de importación.
     * Recibe todos los datos en un solo POST y ejecuta los 4 pasos.
     */
    public static function ajax_full(): void {
                // SEC-3 FIX (v2.9.26): CSRF protection.
                check_ajax_referer( 'ltms_admin_nonce', 'nonce' );
        self::verify_nonce();

        // Solo admin o automatización interna
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Sin permisos.', 403 );
        }

        $step1 = self::collect(['email','phone','name','phoneCode','numberShipments','ecommerce','codeIso']);
        $step2 = self::collect(['name','email','phone','password','phoneCode','numberShipments']);
        $step3 = json_decode( wp_unslash( $_POST['step3'] ?? '{}' ), true ) ?: [];
        $step4 = self::collect(['tradename','address','city']);

        $result = LTMS_Api_Aveonline_Onboarding::instance()->register_full(
            $step1, $step2, $step3, $step4
        );

        // Guardar resultados si se especificó un user_id
        $target_user_id = (int) ( $_POST['target_user_id'] ?? 0 );
        // SEC-7 FIX (v2.9.25): Validar IDOR — solo el propio usuario o un admin
        // pueden guardar onboarding para otro user_id.
        if ( $target_user_id && $target_user_id !== get_current_user_id() && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Sin permisos para modificar el onboarding de otro usuario.', 403 );
        }
        if ( $result['success'] && $target_user_id ) {
            update_user_meta( $target_user_id, self::META_ONBOARDING_STATUS, 'completed' );
            update_user_meta( $target_user_id, self::META_ONBOARDING_SEED,   $result['seed'] );
            if ( ! empty( $result['id_empresa_ave'] ) ) {
                update_user_meta( $target_user_id, self::META_ID_EMPRESA_AVE, (int) $result['id_empresa_ave'] );
            }
        }

        self::respond( $result );
    }

    // ── Estado del onboarding ─────────────────────────────────────────────────

    public static function ajax_status(): void {
        self::verify_nonce();

        $user_id = (int) ( $_POST['user_id'] ?? get_current_user_id() );

        // Solo el propio usuario o un admin puede consultar
        if ( $user_id !== get_current_user_id() && ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Sin permisos.', 403 );
        }

        $status       = get_user_meta( $user_id, self::META_ONBOARDING_STATUS, true ) ?: 'pending';
        $seed         = get_user_meta( $user_id, self::META_ONBOARDING_SEED,   true ) ?: null;
        $id_empresa   = (int) get_user_meta( $user_id, self::META_ID_EMPRESA_AVE, true );

        wp_send_json_success([
            'status'       => $status,
            'seed'         => $seed,
            'id_empresa_ave' => $id_empresa ?: null,
            'completed'    => $status === 'completed',
            'cifin_failed' => $status === 'cifin_failed',
            'labels'       => [
                'pending'      => 'Sin iniciar',
                'step1'        => 'Términos aceptados',
                'step2'        => 'Lead creado',
                'step3'        => 'Identidad verificada',
                'completed'    => 'Registro completo en Aveonline',
                'cifin_failed' => 'No calificó en CIFIN',
            ][ $status ] ?? $status,
        ]);
    }

    // ── Helpers privados ──────────────────────────────────────────────────────

    private static function verify_nonce(): void {
        $nonce = sanitize_text_field( $_POST['nonce'] ?? $_POST['_wpnonce'] ?? '' );
        if ( ! wp_verify_nonce( $nonce, 'ltms_vendor_nonce' ) ) {
            wp_send_json_error( 'Sesión inválida. Recarga la página.', 403 );
        }
    }

    /**
     * Recopila y sanitiza campos del $_POST.
     */
    private static function collect( array $fields ): array {
        $data = [];
        foreach ( $fields as $field ) {
            if ( isset( $_POST[ $field ] ) && $_POST[ $field ] !== '' ) {
                $data[ $field ] = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
            }
        }
        return $data;
    }

    /**
     * Extrae un campo de documento del POST.
     * El JS debe enviar: documentField[base64] y documentField[name]
     *
     * @param string $field_name Ej: 'cedulaFront'
     * @return array|null { base64: string, name: string }
     */
    private static function get_document_field( string $field_name ): ?array {
        $field = $_POST[ $field_name ] ?? null;

        if ( is_array( $field ) ) {
            $b64  = sanitize_text_field( wp_unslash( $field['base64'] ?? '' ) );
            $name = sanitize_file_name( $field['name'] ?? 'document.jpg' );

            // Validar prefijo MIME mínimo
            if ( str_starts_with( $b64, 'data:image/' ) || str_starts_with( $b64, 'data:application/pdf' ) ) {
                return [ 'base64' => $b64, 'name' => $name ];
            }
        }

        return null;
    }

    /**
     * Normaliza la respuesta del cliente PHP a wp_send_json_*.
     */
    private static function respond( array $result ): void {
        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result['message'] ?? 'Error desconocido.', 422 );
        }
    }
}
