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
     * FASE3 P0 FIX: Whitelist of LTMS-prefixed option keys that can be saved.
     * Without this, the settings save loop at L401 calls update_option() for
     * ANY key in $sanitized — including WordPress core options like admin_email,
     * siteurl, default_role, active_plugins. A CSRF or compromised admin could
     * overwrite these and fully compromise the site.
     */
    private const ALLOWED_OPTION_PREFIX = 'ltms_';
    private const ALLOWED_WC_OPTION_KEYS = [
        'woocommerce_ltms_openpay_settings',
    ];

    /**
     * Registra los hooks para la gestión de configuración.
     *
     * @return void
     */
    public static function init(): void {
        $instance = new self();
        add_action( 'admin_init', [ $instance, 'register_settings' ] );
        add_action( 'wp_ajax_ltms_save_settings_section', [ $instance, 'ajax_save_section' ] );

        // E-03 / E-04 FIX: aplicar from_name y from_address guardados en Emails al envío real de wp_mail.
        add_filter( 'wp_mail_from', static function ( string $from ): string {
            $custom = get_option( 'ltms_email_from_address', '' );
            return ( $custom && is_email( $custom ) ) ? $custom : $from;
        } );
        add_filter( 'wp_mail_from_name', static function ( string $name ): string {
            $custom = get_option( 'ltms_email_from_name', '' );
            return ( '' !== trim( $custom ) ) ? $custom : $name;
        } );
        add_action( 'wp_ajax_ltms_test_api_connection', [ $instance, 'ajax_test_api_connection' ] );
        add_action( 'wp_ajax_ltms_aveonline_hub_test_connection', [ $instance, 'ajax_aveonline_hub_test_connection' ] );
        add_action( 'wp_ajax_ltms_aveonline_hub_get_logs', [ $instance, 'ajax_aveonline_hub_get_logs' ] );
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
            // v2.7.0 — Donaciones Fundación Cardio Infantil
            'ltms_donations_settings',
            // v3.1.0 — Cross-Border Commerce (Task 63-C)
            'ltms_cross_border_settings',
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
                'ltms_siigo_access_key', 'ltms_openpay_private_key', 'ltms_addi_client_secret',
                'ltms_aveonline_clave', 'ltms_aveonline_clave_guia', 'ltms_aveonline_onboarding_token', 'ltms_zapsign_api_token', 'ltms_tptc_api_key',
                'ltms_xcover_api_key', 'ltms_backblaze_app_key',
                'ltms_uber_direct_client_secret', 'ltms_heka_api_key',
                'ltms_alegra_token',          // v2.1.0
                'ltms_google_client_secret',  // v2.2.0 (M-62)
                'ltms_deprisa_password',      // AUDIT-SHIPPING-ENGINE #5 — Deprisa password encryption.
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

            // A-6 FIX: Campos de porcentaje — el UI muestra el valor como porcentaje (0-100).
            // Solo dividir entre 100 si el valor es > 1, lo que indica que el usuario
            // lo ingresó como porcentaje. Si ya es ≤ 1, ya está en formato decimal correcto.
            // C-02b FIX: ltms_referral_rates es JSON array, NO un float — excluir del conversor.
            // v3.1.0 (Task 63-C): ltms_fx_spread_percentage se trata como % directo
            // (0-5) y se sanitiza en su propio bloque más abajo — excluirlo aquí.
            $pct_excludes = [ 'ltms_referral_rates', 'ltms_fx_spread_percentage' ];
            if ( ! in_array( $key, $pct_excludes, true )
                 && ( strpos( $key, '_rate' ) !== false || strpos( $key, '_percent' ) !== false ) ) {
                $float_val = (float) $value;
                if ( $float_val > 1 ) {
                    $sanitized[ $key ] = max( 0, min( 1, $float_val / 100 ) );
                } else {
                    $sanitized[ $key ] = max( 0, min( 1, $float_val ) );
                }
                continue;
            }

            // Campos booleanos (yes/no)
            // v3.1.0 (Task 63-C): ltms_enabled_currencies es un array multi-select,
            // NO un booleano — excluirlo aquí (se sanitiza en su bloque propio más abajo).
            $bool_excludes = [ 'ltms_enabled_currencies' ];
            if ( ! in_array( $key, $bool_excludes, true )
                 && ( strpos( $key, '_enabled' ) !== false || strpos( $key, '_required' ) !== false ) ) {
                $sanitized[ $key ] = in_array( $value, [ 'yes', '1', 'true' ], true ) ? 'yes' : 'no';
                continue;
            }

            // v2.7.0 — Donaciones Fundación Cardio Infantil.
            // Estos campos requieren sanitización específica ANTES del bloque genérico
            // `_amount` / `_limit` (que invoca absint) porque los montos de donación
            // son DECIMAL(12,2) y absint los truncaría perdiendo la fracción
            // (ej: 1000.50 → 1000). Adicionalmente, normaliza los select yes/no
            // que no terminan en `_enabled` (vendor_transparency, customer_opt_in,
            // tax_deductible) y valida el email de la fundación.
            $donation_yes_no_fields = [
                'ltms_donation_vendor_transparency',
                'ltms_donation_customer_opt_in',
                'ltms_donation_tax_deductible',
            ];
            if ( in_array( $key, $donation_yes_no_fields, true ) ) {
                $sanitized[ $key ] = in_array( $value, [ 'yes', '1', 'true' ], true ) ? 'yes' : 'no';
                continue;
            }
            if ( in_array( $key, [ 'ltms_donation_percentage' ], true ) ) {
                // Porcentaje 0-100 con 2 decimales. NO se divide entre 100 aquí:
                // el motor de donaciones lee el valor tal cual y lo usa como %.
                $sanitized[ $key ] = max( 0.0, min( 100.0, (float) $value ) );
                continue;
            }
            if ( in_array( $key, [ 'ltms_donation_min_amount', 'ltms_donation_max_amount' ], true ) ) {
                // Montos monetarios decimales (no negativos). absint los truncaría.
                $sanitized[ $key ] = max( 0.0, (float) $value );
                continue;
            }
            if ( in_array( $key, [ 'ltms_donation_alegra_account_id', 'ltms_donation_payout_day' ], true ) ) {
                $sanitized[ $key ] = absint( $value );
                continue;
            }
            if ( $key === 'ltms_donation_foundation_email' ) {
                $sanitized[ $key ] = is_email( $value ) ? sanitize_email( $value ) : '';
                continue;
            }
            if ( in_array( $key, [ 'ltms_donation_basis', 'ltms_donation_rounding', 'ltms_donation_payout_frequency' ], true ) ) {
                // Enums controlados por <select> — lista blanca estricta.
                $allowed_enums = [
                    'ltms_donation_basis'           => [ 'platform_fee', 'order_total', 'vendor_net', 'platform_profit' ],
                    'ltms_donation_rounding'        => [ 'none', 'up_50', 'up_100', 'up_500' ],
                    'ltms_donation_payout_frequency'=> [ 'weekly', 'monthly', 'quarterly', 'manual' ],
                ];
                $sanitized[ $key ] = in_array( $value, $allowed_enums[ $key ], true ) ? $value : '';
                continue;
            }

            // Campos numéricos enteros con valor absoluto (_amount, _limit)
            if ( strpos( $key, '_amount' ) !== false || strpos( $key, '_limit' ) !== false ) {
                $sanitized[ $key ] = absint( $value );
                continue;
            }

            // M-03 FIX: ltms_mlm_min_sales_activate es un entero que debe clampear a 0
            // (no valor absoluto — un valor negativo no tiene sentido como mínimo de ventas).
            // K-04 FIX: ltms_kyc_max_file_size_mb es un número decimal — sanitizar como float clampeado.
            $positive_float_fields = [ 'ltms_kyc_max_file_size_mb' ];
            if ( in_array( $key, $positive_float_fields, true ) ) {
                $sanitized[ $key ] = max( 0.1, (float) $value );
                continue;
            }

            if ( $key === 'ltms_mlm_min_sales_activate' ) {
                $sanitized[ $key ] = max( 0, intval( $value ) );
                continue;
            }

            // C-02b FIX: ltms_referral_rates es JSON array — validar y sanitizar como JSON.
            if ( $key === 'ltms_referral_rates' ) {
                $decoded = json_decode( $value, true );
                if ( is_array( $decoded ) ) {
                    // Solo números decimales entre 0 y 1
                    $clean = array_map( static fn( $v ) => max( 0.0, min( 1.0, (float) $v ) ), $decoded );
                    $sanitized[ $key ] = wp_json_encode( $clean );
                } else {
                    $sanitized[ $key ] = ''; // JSON inválido → vaciar para usar defaults
                }
                continue;
            }

            // E-02 FIX: ltms_email_from_address debe ser un email válido.
            if ( $key === 'ltms_email_from_address' ) {
                $sanitized[ $key ] = is_email( $value ) ? sanitize_email( $value ) : get_option( 'admin_email', '' );
                continue;
            }

            // v3.1.0 — Cross-Border Commerce (Task 63-C).
            // Sanitización específica para los campos de configuración
            // cross-border antes del fallback genérico. Los campos multi-select
            // llegan como array; los textareas como strings multilinea; los
            // enums yes/no y los proveedores FX pasan por lista blanca.
            $cross_border_yes_no = [
                'ltms_cross_border_enabled',
                'ltms_cross_border_kyc_required',
                'ltms_cross_border_fraud_screening',
            ];
            if ( in_array( $key, $cross_border_yes_no, true ) ) {
                $sanitized[ $key ] = in_array( $value, [ 'yes', '1', 'true' ], true ) ? 'yes' : 'no';
                continue;
            }
            if ( $key === 'ltms_base_currency' ) {
                $allowed = [ 'USD', 'COP', 'MXN', 'EUR' ];
                $sanitized[ $key ] = in_array( strtoupper( (string) $value ), $allowed, true ) ? strtoupper( (string) $value ) : 'USD';
                continue;
            }
            if ( $key === 'ltms_enabled_currencies'
                 || $key === 'ltms_cross_border_origin_countries'
                 || $key === 'ltms_cross_border_destination_countries'
                 || $key === 'ltms_international_shipping_carriers' ) {
                // Multi-select: array de strings — sanitizar cada uno.
                if ( is_array( $value ) ) {
                    $clean = array_map( 'sanitize_text_field', $value );
                    $clean = array_filter( $clean, static fn( $v ) => $v !== '' );
                    $sanitized[ $key ] = array_values( $clean );
                } else {
                    $sanitized[ $key ] = [];
                }
                continue;
            }
            if ( $key === 'ltms_fx_spread_percentage' ) {
                // Spread FX: 0-5% con 2 decimales. NO se divide entre 100 aquí.
                $sanitized[ $key ] = max( 0.0, min( 5.0, (float) $value ) );
                continue;
            }
            if ( $key === 'ltms_fx_cache_ttl_hours' ) {
                $sanitized[ $key ] = max( 1, min( 168, absint( $value ) ) );
                continue;
            }
            if ( $key === 'ltms_fx_provider' ) {
                $allowed = [ 'frankfurter', 'exchangerate', 'ecb', 'manual' ];
                $sanitized[ $key ] = in_array( $value, $allowed, true ) ? $value : 'frankfurter';
                continue;
            }
            if ( $key === 'ltms_default_incoterm' ) {
                $allowed = [ 'DDP', 'DDU' ];
                $sanitized[ $key ] = in_array( strtoupper( (string) $value ), $allowed, true ) ? strtoupper( (string) $value ) : 'DDU';
                continue;
            }
            if ( $key === 'ltms_customs_broker_email' ) {
                $sanitized[ $key ] = is_email( $value ) ? sanitize_email( $value ) : '';
                continue;
            }
            // Textareas multi-línea con pares clave=valor (FX overrides, duty rates,
            // customs fees, de minimis). sanitize_text_field() rompería las nuevas
            // líneas — usar sanitize_textarea_field() en su lugar.
            $cross_border_textareas = [
                'ltms_fx_manual_overrides',
                'ltms_customs_duty_rates',
                'ltms_customs_fees',
                'ltms_de_minimis_thresholds',
            ];
            if ( in_array( $key, $cross_border_textareas, true ) ) {
                $sanitized[ $key ] = sanitize_textarea_field( $value );
                continue;
            }
            if ( $key === 'ltms_customs_broker_contact' ) {
                $sanitized[ $key ] = sanitize_text_field( $value );
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

        // M-116 + FIX: los checkboxes desmarcados no se envían en el POST — resetear a 'no'
        // antes de guardar los valores recibidos para que un checkbox desmarcado persista.
        //
        // BUG CRÍTICO CORREGIDO: $checkbox_keys agrupaba TODAS las casillas de TODAS
        // las secciones del plugin. Como el formulario solo envía los campos de la
        // pestaña activa, guardar cualquier sección (ej. "zapsign") reseteaba a 'no'
        // los checkboxes de TODAS las demás secciones (ej. "backblaze") que no estaban
        // presentes en ese POST — aunque esas otras casillas estuvieran marcadas 'yes'
        // en la base de datos. Esto causaba que un toggle activado en una pestaña se
        // revirtiera silenciosamente al guardar cualquier otra pestaña.
        //
        // Fix: agrupar por sección y solo resetear los checkboxes que pertenecen a la
        // sección que realmente se está guardando ($section).
        $checkbox_keys_by_section = [
            'general'     => [ 'ltms_waf_enabled', 'ltms_rate_limit_enabled', 'ltms_ordenes_compra_enabled' ],
            'payments'    => [ 'ltms_openpay_enabled', 'ltms_addi_enabled', 'ltms_tptc_enabled', 'ltms_stripe_enabled' ],
            'siigo'       => [ 'ltms_siigo_enabled', 'ltms_siigo_sandbox', 'ltms_siigo_auto_invoice' ],
            'kyc'         => [ 'ltms_kyc_zapsign_enabled', 'ltms_kyc_require_document' ],
            'mlm'         => [ 'ltms_mlm_enabled' ],
            'security'    => [ 'ltms_waf_enabled', 'ltms_rate_limit_enabled' ],
            'backblaze'   => [ 'ltms_backblaze_enabled' ],
            'uber_direct' => [ 'ltms_uber_direct_enabled' ],
            'heka'        => [ 'ltms_heka_enabled' ],
            'aveonline'   => [ 'ltms_aveonline_enabled' ],
            'xcover'      => [ 'ltms_xcover_parcel_protection', 'ltms_xcover_purchase_protection' ],
            'alegra'      => [
                'ltms_alegra_enabled', 'ltms_alegra_auto_invoice',
                'ltms_alegra_auto_payment', 'ltms_alegra_sandbox',
                'ltms_alegra_invoice_on_processing', 'ltms_alegra_send_invoice_email',
            ],
            'zapsign'     => [ 'ltms_zapsign_enabled', 'ltms_zapsign_sandbox' ],
            'deprisa'     => [ 'ltms_deprisa_enabled' ],
            // v2.7.0 — Donaciones: los campos yes/no se renderizan como <select>
            // (siempre envían un valor), pero se registran aquí por consistencia
            // y para soportar una futura migración a checkboxes.
            'donations'   => [
                'ltms_donation_enabled',
                'ltms_donation_vendor_transparency',
                'ltms_donation_customer_opt_in',
                'ltms_donation_tax_deductible',
                'ltms_donation_certificate_enabled',
            ],
            // v3.1.0 — Cross-Border: los campos yes/no se renderizan como <select>
            // (siempre envían un valor), pero se registran aquí por consistencia
            // y para soportar una futura migración a checkboxes.
            'cross_border' => [
                'ltms_cross_border_enabled',
                'ltms_cross_border_kyc_required',
                'ltms_cross_border_fraud_screening',
            ],
        ];
        $checkbox_keys = $checkbox_keys_by_section[ $section ] ?? [];
        foreach ( $checkbox_keys as $cb_key ) {
            if ( ! array_key_exists( $cb_key, $data ) ) {
                update_option( $cb_key, 'no' );
            }
        }

        // Guardar cada opción individualmente (para get_option() compatibility)
        // y también en ltms_settings (para LTMS_Core_Config::get() directo)
        $sanitized = $this->sanitize_settings( $data );
        $ltms_settings = get_option( 'ltms_settings', [] );
        if ( ! is_array( $ltms_settings ) ) {
            $ltms_settings = [];
        }
        foreach ( $sanitized as $key => $value ) {
            // FASE3 P0 FIX: only save LTMS-prefixed options. Reject any key
            // that doesn't start with 'ltms_' to prevent overwriting WordPress
            // core options (admin_email, siteurl, default_role, active_plugins).
            if ( ! str_starts_with( $key, self::ALLOWED_OPTION_PREFIX ) ) {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::security(
                        'SETTINGS_OPTION_KEY_REJECTED',
                        sprintf( 'Rejected attempt to save non-LTMS option key: %s', $key ),
                        [ 'key' => $key ]
                    );
                }
                continue;
            }
            update_option( $key, $value );
            $ltms_settings[ $key ] = $value; // espejo en ltms_settings
        }
        update_option( 'ltms_settings', $ltms_settings, true );

        // Sincronizar credenciales Openpay CO hacia woocommerce_ltms_openpay_settings
        $wc_op = get_option( 'woocommerce_ltms_openpay_settings', [] );
        foreach ( [ 'merchant_id' => 'ltms_openpay_merchant_id', 'public_key' => 'ltms_openpay_public_key', 'private_key' => 'ltms_openpay_private_key' ] as $wc_key => $opt_key ) {
            $v = get_option( $opt_key, '' );
            if ( ! empty( $v ) ) { $wc_op[ $wc_key ] = $v; }
        }
        update_option( 'woocommerce_ltms_openpay_settings', $wc_op );

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
        // M-118: el form de settings usa 'ltms_settings_nonce' (no 'ltms_admin_nonce')
        // Aceptar ambos para compatibilidad con otros contextos que llamen este endpoint.
        $nonce = sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ?? '' ) ); // phpcs:ignore
        if ( ! wp_verify_nonce( $nonce, 'ltms_settings_nonce' ) &&
             ! wp_verify_nonce( $nonce, 'ltms_admin_nonce' ) ) {
            wp_send_json_error( __( 'Nonce inválido.', 'ltms' ), 403 );
        }

        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
        }

        $provider = sanitize_key( $_POST['provider'] ?? '' ); // phpcs:ignore
        $allowed  = [ 'siigo', 'openpay', 'addi', 'aveonline', 'zapsign', 'tptc', 'xcover', 'backblaze', 'uber', 'heka', 'alegra', 'deprisa' ];

        if ( ! in_array( $provider, $allowed, true ) ) {
            wp_send_json_error( __( 'Proveedor no válido.', 'ltms' ) );
        }

        try {
            // M-118: resetear instancia cacheada y caché de config para que las
            // credenciales recién guardadas se lean desde la BD, no desde caché.
            LTMS_Api_Factory::reset( $provider );
            if ( class_exists( 'LTMS_Core_Config' ) ) {
                LTMS_Core_Config::flush_cache();
            }
            $client  = LTMS_Api_Factory::get( $provider );
            $start   = microtime( true );
            $result  = $client->health_check();
            $latency = (int) round( ( microtime( true ) - $start ) * 1000 );
            $success = ( $result['status'] ?? '' ) === 'ok';

            // Registrar en lt_provider_health para que Salud APIs muestre datos reales
            if ( class_exists( 'LTMS_Payment_Orchestrator' ) ) {
                // M-117: record_provider_event arg#4 debe ser string, no null
                LTMS_Payment_Orchestrator::record_provider_event(
                    $provider,
                    $success ? 'success' : 'error',
                    $latency,
                    $success ? '' : ( $result['message'] ?? 'health_check_failed' )
                );
            } else {
                global $wpdb;
                $wpdb->insert(
                    $wpdb->prefix . 'lt_provider_health',
                    [
                        'provider'   => $provider,
                        'status'     => $success ? 'success' : 'error',
                        'latency_ms' => $latency,
                        'error_code' => $success ? null : 'health_check_failed',
                        'created_at' => gmdate( 'Y-m-d H:i:s' ),
                    ],
                    [ '%s', '%s', '%d', '%s', '%s' ]
                );
            }

            if ( $success ) {
                wp_send_json_success( array_merge( $result, [ 'latency_ms' => $latency ] ) );
            } else {
                wp_send_json_error( $result['message'] ?? __( 'Error de conexión.', 'ltms' ) );
            }
        } catch ( \Throwable $e ) {
            // M-118 debug: capturar detalle exacto de la excepción
            $err_detail = sprintf( '%s en %s:%d', $e->getMessage(), basename( $e->getFile() ), $e->getLine() );
            LTMS_Core_Logger::info( 'TEST_CONN_EXCEPTION', $err_detail );
            // Registrar el error también
            global $wpdb;
            $wpdb->insert(
                $wpdb->prefix . 'lt_provider_health',
                [
                    'provider'   => $provider,
                    'status'     => 'error',
                    'latency_ms' => 0,
                    'error_code' => substr( $e->getMessage(), 0, 100 ),
                    'created_at' => gmdate( 'Y-m-d H:i:s' ),
                ],
                [ '%s', '%s', '%d', '%s', '%s' ]
            );
            wp_send_json_error( $err_detail ?: get_class( $e ) );
        }
    }

    /**
     * AJAX: Prueba la conexión con Ave-Hub (login + obtención de token JWT).
     *
     * No usa LTMS_Api_Factory porque LTMS_Api_Aveonline_Hub no implementa
     * LTMS_Abstract_API_Client (es un cliente auxiliar, no un proveedor
     * logístico registrado en el factory).
     *
     * @return void
     */
    public function ajax_aveonline_hub_test_connection(): void {
        $nonce = sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ?? '' ) ); // phpcs:ignore
        if ( ! wp_verify_nonce( $nonce, 'ltms_admin_nonce' ) &&
             ! wp_verify_nonce( $nonce, 'ltms_settings_nonce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Nonce inválido.', 'ltms' ) ], 403 );
        }

        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'ltms' ) ], 403 );
        }

        if ( ! class_exists( 'LTMS_Api_Aveonline_Hub' ) ) {
            wp_send_json_error( [ 'message' => __( 'Clase LTMS_Api_Aveonline_Hub no disponible.', 'ltms' ) ] );
        }

        try {
            $client = new LTMS_Api_Aveonline_Hub();
            $token  = $client->refresh_token();
            $expires = (int) get_option( 'ltms_aveonline_hub_token_expires', 0 );

            wp_send_json_success( [
                'message'    => sprintf(
                    /* translators: %s: fecha de expiración del token */
                    __( 'Conexión exitosa. Token válido hasta %s.', 'ltms' ),
                    $expires ? wp_date( 'd/m/Y H:i', $expires ) : '—'
                ),
                'token_preview' => substr( $token, 0, 12 ) . '…',
            ] );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    /**
     * AJAX: Consulta los logs remotos de Ave-Hub (LTMS_Api_Aveonline_Hub::get_logs()).
     *
     * Acepta filtros opcionales: id_envio, fecha_inicio, fecha_fin (AAAA-MM-DD), hoy.
     *
     * @return void
     */
    public function ajax_aveonline_hub_get_logs(): void {
        $nonce = sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ?? '' ) ); // phpcs:ignore
        if ( ! wp_verify_nonce( $nonce, 'ltms_admin_nonce' ) &&
             ! wp_verify_nonce( $nonce, 'ltms_settings_nonce' ) ) {
            wp_send_json_error( [ 'message' => __( 'Nonce inválido.', 'ltms' ) ], 403 );
        }

        if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'ltms' ) ], 403 );
        }

        if ( ! class_exists( 'LTMS_Api_Aveonline_Hub' ) ) {
            wp_send_json_error( [ 'message' => __( 'Clase LTMS_Api_Aveonline_Hub no disponible.', 'ltms' ) ] );
        }

        $filters = [];

        $id_envio = sanitize_text_field( wp_unslash( $_REQUEST['id_envio'] ?? '' ) ); // phpcs:ignore
        if ( '' !== $id_envio ) {
            $filters['id_envio'] = $id_envio;
        }

        $fecha_inicio = sanitize_text_field( wp_unslash( $_REQUEST['fecha_inicio'] ?? '' ) ); // phpcs:ignore
        if ( '' !== $fecha_inicio ) {
            $filters['fecha_inicio'] = $fecha_inicio;
        }

        $fecha_fin = sanitize_text_field( wp_unslash( $_REQUEST['fecha_fin'] ?? '' ) ); // phpcs:ignore
        if ( '' !== $fecha_fin ) {
            $filters['fecha_fin'] = $fecha_fin;
        }

        if ( ! empty( $_REQUEST['hoy'] ) ) { // phpcs:ignore
            $filters['hoy'] = true;
        }

        try {
            $client = new LTMS_Api_Aveonline_Hub();
            $result = $client->get_logs( $filters );

            wp_send_json_success( [
                'meta' => $result['meta'] ?? [],
                'rows' => $result['data'] ?? [],
            ] );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }
    /**
     * Obtiene datos del gráfico vía AJAX.
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
                $column   = $type === 'commissions' ? 'vendor_amount' : 'gross_amount';
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
                $column   = $type === 'commissions' ? 'vendor_amount' : 'gross_amount';
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



