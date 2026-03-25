<?php
/**
 * LTMS Admin Settings - Controlador de Configuración
 *
 * Gestiona la página de configuración del plugin:
 * - General (nombre, país, moneda, cifrado)
 * - Comisiones y pagos
 * - Integraciones API (Siigo, Openpay, Addi, etc.)
 * - KYC y compliance
 * - Emails y notificaciones
 * - Seguridad (WAF, rate limiting)
 * - Marketing (MLM/referidos, cupones)
 *
 * @package    LTMS
 * @subpackage LTMS/includes/admin
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Admin_Settings
 */
final class LTMS_Admin_Settings {

    use LTMS_Logger_Aware;

    /**
     * Registra los hooks para la gestión de configuración.
     *
     * @return void
     */
    public static function init(): void {
        $instance = new self();
        add_action( 'admin_init', [ $instance, 'register_settings' ] );
        add_action( 'wp_ajax_ltms_save_settings_section', [ $instance, 'ajax_save_section' ] );
        add_action( 'wp_ajax_ltms_test_api_connection', [ $instance, 'ajax_test_api_connection' ] );
        add_action( 'wp_ajax_ltms_get_chart_data', [ $instance, 'ajax_get_chart_data' ] );
        add_action( 'wp_ajax_ltms_fix_admin_caps', [ $instance, 'ajax_fix_admin_caps' ] );
    }

    /**
     * Registra todos los grupos de configuración de WordPress Settings API.
     *
     * @return void
     */
    public function register_settings(): void {
        $option_groups = [
            'ltms_general_settings',
            'ltms_commission_settings',
            'ltms_payment_settings',
            'ltms_siigo_settings',
            'ltms_kyc_settings',
            'ltms_mlm_settings',
            'ltms_security_settings',
            'ltms_email_settings',
        ];

        foreach ( $option_groups as $group ) {
            register_setting(
                $group,
                $group,
                [ $this, 'sanitize_settings' ]
            );
        }
    }

    /**
     * Sanitiza los valores de configuración antes de guardar.
     *
     * @param mixed $input Datos enviados.
     * @return mixed
     */
    public function sanitize_settings( $input ) {
        if ( ! is_array( $input ) ) {
            return [];
        }

        $sanitized = [];
        foreach ( $input as $key => $value ) {
            $key = sanitize_key( $key );

            // Campos que requieren cifrado
            $encrypted_fields = [
                'ltms_siigo_password', 'ltms_openpay_private_key', 'ltms_addi_client_secret',
                'ltms_aveonline_api_key', 'ltms_zapsign_api_token', 'ltms_tptc_api_key',
                'ltms_xcover_api_key', 'ltms_backblaze_app_key',
                'ltms_uber_direct_client_secret', 'ltms_heka_api_key',
            ];

            if ( in_array( $key, $encrypted_fields, true ) && ! empty( $value ) ) {
                // Solo cifrar si no está ya cifrado (no empieza con 'v1:')
                if ( strpos( $value, 'v1:' ) !== 0 ) {
                    $sanitized[ $key ] = LTMS_Core_Security::encrypt( sanitize_text_field( $value ) );
                } else {
                    $sanitized[ $key ] = $value; // Ya cifrado, mantener
                }
                continue;
            }

            // Campos de porcentaje (0-100)
            if ( strpos( $key, '_rate' ) !== false || strpos( $key, '_percent' ) !== false ) {
                $sanitized[ $key ] = max( 0, min( 100, (float) $value ) ) / 100;
                continue;
            }

            // Campos booleanos (yes/no)
            if ( strpos( $key, '_enabled' ) !== false || strpos( $key, '_required' ) !== false ) {
                $sanitized[ $key ] = in_array( $value, [ 'yes', '1', 'true' ], true ) ? 'yes' : 'no';
                continue;
            }

            // Campos numéricos
            if ( strpos( $key, '_amount' ) !== false || strpos( $key, '_limit' ) !== false ) {
                $sanitized[ $key ] = absint( $value );
                continue;
            }

            // Default: sanitizar como texto
            $sanitized[ $key ] = sanitize_text_field( $value );
        }

        return $sanitized;
    }

    /**
     * AJAX: Guarda una sección de configuración.
     *
     * @return void
     */
    public function ajax_save_section(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $section = sanitize_key( $_POST['section'] ?? '' ); // phpcs:ignore
        $data    = $_POST['data'] ?? []; // phpcs:ignore

        if ( ! is_array( $data ) ) {
            wp_send_json_error( __( 'Datos inválidos.', 'ltms' ) );
        }

        // Guardar cada opción individualmente
        $sanitized = $this->sanitize_settings( $data );
        foreach ( $sanitized as $key => $value ) {
            update_option( $key, $value );
        }

        // Invalidar caché de config
        if ( class_exists( 'LTMS_Core_Config' ) ) {
            LTMS_Core_Config::flush_cache();
        }

        LTMS_Core_Logger::info(
            'SETTINGS_SAVED',
            sprintf( 'Sección "%s" guardada por usuario #%d', $section, get_current_user_id() )
        );

        wp_send_json_success( [ 'message' => __( 'Configuración guardada exitosamente.', 'ltms' ) ] );
    }

    /**
     * AJAX: Prueba la conexión con una API.
     *
     * @return void
     */
    public function ajax_test_api_connection(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $provider = sanitize_key( $_POST['provider'] ?? '' ); // phpcs:ignore
        $allowed  = [ 'siigo', 'openpay', 'addi', 'aveonline', 'zapsign', 'tptc', 'xcover', 'backblaze', 'uber', 'heka' ];

        if ( ! in_array( $provider, $allowed, true ) ) {
            wp_send_json_error( __( 'Proveedor no válido.', 'ltms' ) );
        }

        try {
            $client = LTMS_Api_Factory::get( $provider );
            $result = $client->health_check();

            if ( ( $result['status'] ?? '' ) === 'ok' ) {
                wp_send_json_success( $result );
            } else {
                wp_send_json_error( $result['message'] ?? __( 'Error de conexión.', 'ltms' ) );
            }
        } catch ( \Throwable $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }

    /**
     * AJAX: Devuelve datos de gráficos para el dashboard admin.
     *
     * @return void
     */
    public function ajax_get_chart_data(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ltms_access_dashboard' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $type    = sanitize_key( $_POST['type'] ?? 'sales' ); // phpcs:ignore
        $period  = sanitize_text_field( $_POST['period'] ?? '30days' ); // phpcs:ignore

        $data = $this->get_chart_data( $type, $period );
        wp_send_json_success( $data );
    }

    /**
     * Construye los datos del gráfico según el tipo.
     *
     * @param string $type   Tipo de gráfico (sales, commissions).
     * @param string $period Período.
     * @return array
     */
    private function get_chart_data( string $type, string $period ): array {
        global $wpdb;
        $table  = $wpdb->prefix . 'lt_commissions';
        $labels = [];
        $values = [];

        $days = $period === '7days' ? 7 : ( $period === '12months' ? 0 : 30 );

        if ( $days > 0 ) {
            for ( $i = $days - 1; $i >= 0; $i-- ) {
                $date     = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
                $labels[] = $date;
                $column   = $type === 'commissions' ? 'vendor_net' : 'gross_amount';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $values[] = (float) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT SUM(`{$column}`) FROM `{$table}` WHERE DATE(created_at) = %s",
                        $date
                    )
                );
            }
        } else {
            for ( $i = 11; $i >= 0; $i-- ) {
                $month    = gmdate( 'Y-m', strtotime( "-{$i} months" ) );
                $labels[] = $month;
                $column   = $type === 'commissions' ? 'vendor_net' : 'gross_amount';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $values[] = (float) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT SUM(`{$column}`) FROM `{$table}` WHERE DATE_FORMAT(created_at, '%%Y-%%m') = %s",
                        $month
                    )
                );
            }
        }

        return [
            'chart_type' => 'bar',
            'chart_data' => [
                'labels'   => $labels,
                'datasets' => [[
                    'label'           => $type === 'commissions'
                        ? __( 'Comisiones Vendedores', 'ltms' )
                        : __( 'Ventas Totales', 'ltms' ),
                    'data'            => $values,
                    'backgroundColor' => $type === 'commissions' ? '#27ae60' : '#1a5276',
                    'borderRadius'    => 4,
                ]],
            ],
        ];
    }

    /**
     * AJAX: Fuerza la re-asignación de todas las caps LTMS al rol administrator.
     *
     * Requiere: manage_options + nonce ltms_admin_nonce.
     * Acción JS: wp.ajax.post('ltms_fix_admin_caps', { nonce: ltmsAdmin.nonce })
     *
     * @return void
     */
    public function ajax_fix_admin_caps(): void {
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $role = get_role( 'administrator' );
        if ( ! $role ) {
            wp_send_json_error( __( 'Rol administrator no encontrado.', 'ltms' ) );
        }

        if ( ! class_exists( 'LTMS_Roles' ) ) {
            wp_send_json_error( __( 'Clase LTMS_Roles no disponible — verifica que Composer esté instalado.', 'ltms' ) );
        }

        $caps    = LTMS_Roles::ADMIN_CAPABILITIES;
        $added   = [];
        $already = [];

        foreach ( $caps as $cap ) {
            if ( $role->has_cap( $cap ) ) {
                $already[] = $cap;
            } else {
                $role->add_cap( $cap, true );
                $added[] = $cap;
            }
        }

        // Invalidar el transient de auto-healing para que se re-verifique.
        delete_transient( 'ltms_admin_caps_ok_' . md5( LTMS_VERSION ) );

        LTMS_Core_Logger::info(
            'CAPS_FIXED',
            sprintf(
                'Caps fix ejecutado por usuario #%d: %d añadidas, %d ya existían.',
                get_current_user_id(),
                count( $added ),
                count( $already )
            )
        );

        wp_send_json_success( [
            'added'   => $added,
            'already' => $already,
            'message' => sprintf(
                /* translators: 1: caps añadidas, 2: caps ya existentes */
                __( '%1$d capacidades añadidas, %2$d ya existían. Recarga la página.', 'ltms' ),
                count( $added ),
                count( $already )
            ),
        ] );
    }
}
