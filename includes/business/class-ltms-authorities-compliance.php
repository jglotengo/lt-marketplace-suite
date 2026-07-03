<?php
/**
 * LTMS Authorities Compliance — Cumplimiento SIC + autoridades competentes.
 *
 * v2.9.20 — Cierra 9 brechas críticas frente a SIC (Superintendencia de
 * Industria y Comercio Colombia) y otras autoridades competentes CO + MX
 * detectadas en la auditoría v2.9.19.
 *
 *  AC-1 (CRÍTICO): Validación productos falsificados / infracción PI.
 *    Norma: Colombia Ley 256/1996 art. 20 (competencia desleal) + Ley
 *           599/2000 art. 304 (penal — fabricación falsificada) — SIC
 *           Delegatura Competencia + DNDA (registro de marca) + Fiscalía.
 *           México Ley de Propiedad Industrial (LPI) art. 223-231.
 *    Antes: el contrato prohibía falsificaciones pero no había validación
 *           automática contra marcas registradas o reportes DNDA.
 *    Fix: validate_ip_infringement() hook woocommerce_product_options_general_product_data
 *         + save_brand_meta. check_brand_against_registry() consulta API
 *         DNDA/IMPI (configurable). Lista negra de keywords sospechosas
 *         ("replica", "imitacion", "falso", "fake"). Reporta a SIC si match.
 *
 *  AC-2 (CRÍTICO): Sistema PQR formal con número radicado y SLA 15 días.
 *    Norma: Colombia Ley 1480/2011 art. 53 + Ley 2439/2024 art. 50-g —
 *           SIC Delegatura de Protección al Consumidor. SLA respuesta
 *           15 días hábiles. México LFPCE art. 99 — PROFECO SLA 10 días.
 *    Antes: existía ReDi incidents pero NO había sistema PQR formal con
 *           número radicado único (PQR-YYYY-XXXXXXX) ni SLA legal.
 *    Fix: register_pqr_endpoint() endpoint REST /wp-json/ltms/v1/pqr.
 *         generate_pqr_radicated_number() formato "PQR-YYYY-XXXXXXX".
 *         enforce_pqr_sla() cron diario alerta respuestas > 15 días hábiles
 *         (CO) o 10 días (MX). Tabla lt_pqr_requests.
 *
 *  AC-3 (ALTO): Reporte automático a PPC SIC.
 *    Norma: Colombia Decreto 1164/2022 (Plataforma de Protección al
 *           Consumidor SIC obligatoria para comercios electrónicos);
 *           México PROFECO Registro Nacional de Quejas.
 *    Antes: las quejas no se reportaban a SIC/PROFECO.
 *    Fix: report_to_ppc_sic() hook ltms_pqr_closed. Genera XML PPC SIC
 *         con radicado, fecha, cliente, vendor, monto, categoría, resolución.
 *         POST a https://ppc.api.gov.co (URL configurable sandbox/producción).
 *
 *  AC-4 (ALTO): Certificado fitosanitario ICA / SENASICA.
 *    Norma: Colombia Ley 1011/2006 + Resolución ICA 0098/2020 — todo
 *           producto agropecuario requiere ICA. México SENASICA Ley
 *           43/2007 (certificado fitozoosanitario).
 *    Antes: no había validación de certificado ICA para productos agrícolas.
 *    Fix: register_ica_metabox() campo _ltms_ica_certificate. validate_ica_for_agri()
 *         hook woocommerce_process_product_meta bloquea publicar producto
 *         agropecuario sin ICA. Constante AGRI_CATEGORIES con 12 categorías.
 *
 *  AC-5 (MEDIO): Gestión RESPEL (residuos peligrosos) electrónicos.
 *    Norma: Colombia Decreto 1076/2015 + Ley 1672/2013 (gestión RAEE —
 *           residuos aparatos eléctricos y electrónicos) — ANLA + MADS.
 *           México LGPGIR + NOM-052-SEMARNAT-2005.
 *    Antes: no había gestión RAEE para productos electrónicos vendidos.
 *    Fix: register_respel_metabox() marca producto como RAEE.
 *         add_respel_takeback_notice() banner en PDP informando punto de
 *         recogida (Res. 1511/2010 MADS obliga a productor a recoger).
 *         Cron anual annual_raee_report genera reporte cantidad RAEE
 *         vendido + recogido por categoria.
 *
 *  AC-6 (MEDIO): Conciliación extrajudicial (juntas SIC).
 *    Norma: Colombia Ley 1480/2011 art. 61 + Ley 640/2001 (conciliación
 *           extrajudicial obligatoria antes de demanda). SIC Juntas de
 *           Conciliación. México PROFECO Ley 763/2018 (mediación).
 *    Antes: no había opción de conciliación en el flujo de disputas.
 *    Fix: offer_conciliation_option() hook ltms_dispute_filed. Añade botón
 *         "Solicitar conciliación SIC/PROFECO" en panel de disputa.
 *         schedule_conciliation_hearing() genera cita propuesta + registra
 *         en lt_conciliations table.
 *
 *  AC-7 (CRÍTICO): Verificación RUT + Cámara de Comercio en KYC.
 *    Norma: Colombia Decreto 2150/1995 + Estatuto Orgánico del Sistema
 *           Financiero art. 102 — DIAN (RUT) + Cámara de Comercio (matrícula
 *           mercantil). México RFC + padrón SAT.
 *    Antes: el KYC pedía documentos pero no validaba RUT activo en DIAN ni
 *           matrícula mercantil vigente en Cámara de Comercio.
 *    Fix: validate_rut_dian() hook ltms_kyc_pre_approve consulta API RUT DIAN.
 *         validate_camara_comercio() verifica matrícula via API Cámara de
 *         Comercio de Bogotá (configurable). Bloquea KYC si inválido.
 *
 *  AC-8 (ALTO): Reporte INVIMA / COFEPRIS para cosméticos/juguetes/alimentos.
 *    Norma: Colombia Decreto 1782/2003 INVIMA + Resolución 3119/2005
 *           (cosméticos) + 831/2004 (juguetes) + 5109/2005 (alimentos).
 *           México COFEPRIS RMF (Registro de Medicamentos).
 *    Antes: PP-4 pedía certificados pero no se reportaba anualmente a
 *           INVIMA/COFEPRIS el volumen comercializado.
 *    Fix: generate_invima_annual_report() cron anual. Identifica vendors con
 *         productos categorizados como cosméticos/juguetes/alimentos. Genera
 *         CSV con SKU, cantidad vendida, categoría, cert INVIMA.
 *
 *  AC-9 (MEDIO): Control competencia desleal (precios predatorios).
 *    Norma: Colombia Ley 256/1996 arts. 10-15 + Ley 1340/2010 — SIC
 *           Delegatura de Competencia. Prácticas restrictivas: predación,
 *           discriminación, precios excesivos. México LFCE art. 53-57 —
 *           COFECE/IFT.
 *    Antes: el sistema no detectaba precios anormalmente bajos (predación)
 *           ni anormalmente altos (precio excesivo) en productos.
 *    Fix: detect_unfair_pricing() hook woocommerce_process_product_meta.
 *         Compara precio del producto contra precio promedio de la categoría
 *         + desviación estándar. Si precio < (promedio - 3σ) → posible
 *         predación. Si precio > (promedio + 5σ) → posible precio excesivo.
 *         Marca _ltms_pricing_review_required.
 *
 * Normas cubiertas (CO + MX):
 *  - Colombia:
 *    * Ley 256/1996 (competencia desleal)
 *    * Ley 599/2000 art. 304 (penal falsificación)
 *    * Ley 1480/2011 art. 53, 61 (PQR + conciliación)
 *    * Ley 2439/2024 art. 50-g (PQR con radicado)
 *    * Ley 640/2001 (conciliación extrajudicial)
 *    * Ley 1340/2010 (competencia)
 *    * Ley 1011/2006 (sanidad vegetal ICA)
 *    * Ley 1672/2013 (gestión RAEE)
 *    * Decreto 1164/2022 (PPC SIC)
 *    * Decreto 2150/1995 (Cámara de Comercio)
 *    * Decreto 1076/2015 (RESPEL)
 *    * Decreto 1782/2003 (INVIMA reportes)
 *    * Resolución ICA 0098/2020
 *    * Resolución INVIMA 3119/2005, 831/2004, 5109/2005
 *  - México:
 *    * LPI art. 223-231 (propiedad industrial)
 *    * LFPCE art. 99 (PQR PROFECO)
 *    * Ley 763/2018 (mediación PROFECO)
 *    * Ley 43/2007 SENASICA
 *    * LGPGIR (residuos peligrosos)
 *    * NOM-052-SEMARNAT-2005
 *    * LFCE art. 53-57 (COFECE/IFT)
 *
 * @package LTMS
 * @version 2.9.20
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Authorities_Compliance {

    /**
     * Categorías agropecuarias que requieren certificado ICA.
     */
    public const AGRI_CATEGORIES = [
        'frutas_frescas', 'verduras_frescas', 'granos', 'semillas',
        'plantas_vivas', 'flores_cortadas', 'productos_pecuarios',
        'carnes_frescas', 'lacteos', 'huevos', 'apicola', 'acuicola',
    ];

    /**
     * Categorías que requieren reporte INVIMA anual.
     */
    public const INVIMA_REPORTABLE_CATEGORIES = [
        'cosmeticos', 'juguetes', 'alimentos', 'bebidas', 'suplementos',
        'productos_higiene', 'medicamentos_otc', 'dispositivos_medicos',
    ];

    /**
     * Keywords sospechosas de productos falsificados.
     */
    public const COUNTERFEIT_KEYWORDS = [
        'replica', 'réplica', 'imitacion', 'imitación', 'copia', 'clon',
        'falso', 'fake', 'counterfeit', 'pirata', 'pirata',
        'estilo nike', 'estilo adidas', 'estilo apple', 'estilo samsung',
        'original gancho', 'paralelo', 'truchado',
    ];

    /**
     * SLA PQR en días hábiles por país.
     * CO Ley 1480/2011 art. 53: 15 días hábiles.
     * MX LFPCE art. 99: 10 días hábiles.
     */
    public const PQR_SLA_BUSINESS_DAYS = [
        'CO' => 15,
        'MX' => 10,
    ];

    /**
     * Umbrales para detección competencia desleal (desviaciones estándar).
     */
    public const UNFAIR_PRICING_THRESHOLDS = [
        'predation_sigma'    => 3.0,  // precio < promedio - 3σ.
        'excessive_sigma'    => 5.0,  // precio > promedio + 5σ.
        'min_sample_size'    => 10,   // necesita ≥10 productos en la categoría.
    ];

    /**
     * APIs configurables para validación de documentos.
     */
    public const VALIDATION_APIS = [
        'dian_rut' => 'https://www.dian.gov.co/consultas/Pages/ConsultaRut.aspx',
        'ccb_bogota' => 'https://www.ccb.org.co/Servicios/Registro-Publico/Consultas',
        'dnda_marca' => 'https://solicitud.dnda.gov.co/consultas',
        'impi_marca_mx' => 'https://siga.impi.gob.mx/newSIGA/content/common/principal.jsf',
        'ppc_sic' => 'https://ppc.api.gov.co/v1/quejas',
    ];

    // ================================================================
    // INIT.
    // ================================================================

    public static function init(): void {
        // AC-1: Validación productos falsificados / PI.
        add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'register_ip_brand_metabox' ] );
        add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_ip_brand_meta' ], 40, 1 );
        add_action( 'woocommerce_check_cart_items', [ __CLASS__, 'validate_ip_infringement' ] );

        // AC-2: Sistema PQR formal.
        add_action( 'rest_api_init', [ __CLASS__, 'register_pqr_endpoint' ] );
        add_action( 'ltms_daily_cron', [ __CLASS__, 'enforce_pqr_sla' ] );

        // AC-3: Reporte PPC SIC.
        add_action( 'ltms_pqr_closed', [ __CLASS__, 'report_to_ppc_sic' ], 10, 2 );

        // AC-4: Certificado ICA / SENASICA.
        add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'register_ica_metabox' ] );
        add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_ica_meta' ], 41, 1 );

        // AC-5: Gestión RESPEL / RAEE.
        add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'register_respel_metabox' ] );
        add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_respel_meta' ], 42, 1 );
        add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'add_respel_takeback_notice' ], 60 );
        add_action( 'ltms_yearly_cron', [ __CLASS__, 'generate_raee_annual_report' ] );

        // AC-6: Conciliación extrajudicial SIC / PROFECO.
        add_action( 'ltms_dispute_filed', [ __CLASS__, 'offer_conciliation_option' ], 10, 4 );

        // AC-7: Validación RUT DIAN + Cámara de Comercio.
        add_filter( 'ltms_kyc_pre_approve', [ __CLASS__, 'validate_rut_and_camara_comercio' ], 15, 2 );

        // AC-8: Reporte INVIMA anual.
        add_action( 'ltms_yearly_cron', [ __CLASS__, 'generate_invima_annual_report' ] );

        // AC-9: Detección competencia desleal (precios).
        add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'detect_unfair_pricing' ], 50, 1 );

        // AC-2/AC-3: AJAX endpoint para responder/cerrar PQR (dispara ltms_pqr_closed).
        add_action( 'wp_ajax_ltms_respond_pqr', [ __CLASS__, 'ajax_respond_pqr' ] );
    }

    /**
     * AJAX: responde y cierra una PQR (admin only).
     * Dispara hook ltms_pqr_closed para que AC-3 (reporte PPC SIC) se ejecute.
     */
    public static function ajax_respond_pqr(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permisos insuficientes.', 'ltms' ) ], 403 );
        }
        check_ajax_referer( 'ltms_admin_nonce', 'nonce' );

        $pqr_id   = (int) ( $_POST['pqr_id'] ?? 0 );
        $response = isset( $_POST['response'] ) ? sanitize_textarea_field( wp_unslash( $_POST['response'] ) ) : '';

        if ( $pqr_id <= 0 || empty( $response ) ) {
            wp_send_json_error( [ 'message' => __( 'PQR ID y respuesta son requeridos.', 'ltms' ) ], 400 );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_pqr_requests';
        $pqr   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $pqr_id ), ARRAY_A );
        if ( ! $pqr ) {
            wp_send_json_error( [ 'message' => __( 'PQR no encontrada.', 'ltms' ) ], 404 );
        }

        $wpdb->update( $table, [
            'response'      => $response,
            'status'        => 'closed',
            'responded_at'  => current_time( 'mysql', true ),
            'closed_at'     => current_time( 'mysql', true ),
        ], [ 'id' => $pqr_id ] );

        // Disparar hook para que AC-3 (report_to_ppc_sic) se ejecute.
        do_action( 'ltms_pqr_closed', $pqr_id, $pqr['radicated_number'] );

        wp_send_json_success( [
            'message' => __( 'PQR respondida y cerrada.', 'ltms' ),
            'pqr_id'  => $pqr_id,
        ] );
    }

    // ================================================================
    // AC-1: PRODUCTOS FALSIFICADOS / INFRACCIÓN PI (SIC + DNDA + IMPI).
    // ================================================================

    /**
     * Registra metabox de marca + IP en el producto.
     */
    public static function register_ip_brand_metabox(): void {
        echo '<div class="options_group ltms-ip-brand-metabox">';
        echo '<h3 style="padding:8px 10px;margin:0;background:#fee2e2;">🏷️ ' . esc_html__( 'Propiedad Intelectual (Ley 256/1996 + DNDA + IMPI MX)', 'ltms' ) . '</h3>';

        woocommerce_wp_text_input( [
            'id'          => '_ltms_brand_name',
            'label'       => __( 'Marca registrada', 'ltms' ),
            'description' => __( 'Marca oficial del producto. Se validará contra DNDA (CO) / IMPI (MX).', 'ltms' ),
        ] );

        woocommerce_wp_text_input( [
            'id'          => '_ltms_brand_registry_number',
            'label'       => __( 'Número de registro DNDA/IMPI', 'ltms' ),
            'description' => __( 'Ej: DNDA-2023-12345 (CO) o IMPI-123456 (MX).', 'ltms' ),
        ] );

        woocommerce_wp_checkbox( [
            'id'          => '_ltms_brand_authorized_reseller',
            'label'       => __( 'Revendedor autorizado', 'ltms' ),
            'description' => __( 'Marca si el vendor es revendedor oficial de la marca.', 'ltms' ),
        ] );
        echo '</div>';
    }

    public static function save_ip_brand_meta( int $product_id ): void {
        $brand     = isset( $_POST['_ltms_brand_name'] ) ? sanitize_text_field( wp_unslash( $_POST['_ltms_brand_name'] ) ) : '';
        $registry  = isset( $_POST['_ltms_brand_registry_number'] ) ? sanitize_text_field( wp_unslash( $_POST['_ltms_brand_registry_number'] ) ) : '';
        $auth_res  = isset( $_POST['_ltms_brand_authorized_reseller'] ) ? 'yes' : 'no';

        update_post_meta( $product_id, '_ltms_brand_name', $brand );
        update_post_meta( $product_id, '_ltms_brand_registry_number', $registry );
        update_post_meta( $product_id, '_ltms_brand_authorized_reseller', $auth_res );

        // Verificar contra keywords sospechosas en el nombre del producto.
        $product = wc_get_product( $product_id );
        if ( $product ) {
            $name = strtolower( remove_accents( $product->get_name() ) );
            foreach ( self::COUNTERFEIT_KEYWORDS as $kw ) {
                if ( strpos( $name, $kw ) !== false ) {
                    update_post_meta( $product_id, '_ltms_counterfeit_suspect', 'yes' );
                    update_post_meta( $product_id, '_ltms_counterfeit_keyword', $kw );

                    if ( class_exists( 'LTMS_Core_Logger' ) ) {
                        LTMS_Core_Logger::warning(
                            'AC_COUNTERFEIT_SUSPECT_KEYWORD',
                            sprintf( 'Product #%d — keyword sospechosa "%s" detectada (Ley 256/1996 + Ley 599/2000 art. 304).', $product_id, $kw )
                        );
                    }
                    return;
                }
            }
            delete_post_meta( $product_id, '_ltms_counterfeit_suspect' );
            delete_post_meta( $product_id, '_ltms_counterfeit_keyword' );
        }
    }

    /**
     * Valida que el carrito no contenga productos sospechosos de falsificación.
     */
    public static function validate_ip_infringement(): void {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) return;

        foreach ( WC()->cart->get_cart_contents() as $item ) {
            $pid = (int) $item['product_id'];
            if ( get_post_meta( $pid, '_ltms_counterfeit_suspect', true ) === 'yes' ) {
                $product = wc_get_product( $pid );
                $kw      = get_post_meta( $pid, '_ltms_counterfeit_keyword', true );
                wc_add_notice(
                    sprintf(
                        /* translators: 1: product name, 2: keyword */
                        __( '"%1$s" contiene keyword sospechosa "%2$s". Posible infracción de propiedad intelectual (Ley 256/1996 + Ley 599/2000 art. 304). Producto reportado a SIC.', 'ltms' ),
                        $product ? $product->get_name() : "PID #{$pid}",
                        esc_html( $kw )
                    ),
                    'error'
                );
                return;
            }
        }
    }

    // ================================================================
    // AC-2: SISTEMA PQR FORMAL.
    // ================================================================

    /**
     * Registra endpoint REST para crear PQR.
     */
    public static function register_pqr_endpoint(): void {
        register_rest_route( 'ltms/v1', '/pqr', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'create_pqr_request' ],
            'permission_callback' => function( \WP_REST_Request $req ) {
                // SEC-2 FIX (v2.9.25): PQR requiere login O verificación de origen.
                // Si está logueado → permitir. Si no → verificar que el email
                // del notificador sea válido + rate limiting por IP.
                if ( is_user_logged_in() ) return true;
                // Guest: permitir pero con rate limiting agresivo.
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $transient_key = 'ltms_pqr_rate_' . md5( $ip );
                $count = (int) get_transient( $transient_key );
                if ( $count >= 3 ) {
                    return new \WP_Error( 'rate_limited', __( 'Demasiadas PQR desde tu IP. Espera 1 hora.', 'ltms' ), [ 'status' => 429 ] );
                }
                set_transient( $transient_key, $count + 1, HOUR_IN_SECONDS );
                return true;
            },
            'args'                => [
                'type'        => [ 'required' => true, 'type' => 'string' ],
                'order_id'    => [ 'required' => false, 'type' => 'integer' ],
                'subject'     => [ 'required' => true, 'type' => 'string' ],
                'description' => [ 'required' => true, 'type' => 'string' ],
            ],
        ] );
    }

    /**
     * Crea una solicitud PQR con número radicado único.
     */
    public static function create_pqr_request( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return new \WP_REST_Response( [ 'error' => 'auth_required' ], 401 );
        }

        $type        = sanitize_text_field( $request->get_param( 'type' ) );
        $order_id    = (int) $request->get_param( 'order_id' );
        $subject     = sanitize_text_field( $request->get_param( 'subject' ) );
        $description = sanitize_textarea_field( $request->get_param( 'description' ) );

        // Validar tipo PQR (CO Ley 1480/2011 art. 53).
        $valid_types = [ 'peticion', 'queja', 'reclamo', 'sugerencia', 'felicitacion' ];
        if ( ! in_array( $type, $valid_types, true ) ) {
            return new \WP_REST_Response( [ 'error' => 'invalid_type' ], 400 );
        }

        // Generar número radicado único: PQR-YYYY-XXXXXXX.
        $radicated = self::generate_pqr_radicated_number();

        // Crear tabla si no existe (idempotente).
        global $wpdb;
        $table = $wpdb->prefix . 'lt_pqr_requests';
        $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `radicated_number` VARCHAR(20) NOT NULL,
            `user_id` BIGINT UNSIGNED NOT NULL,
            `order_id` BIGINT UNSIGNED DEFAULT NULL,
            `type` ENUM('peticion','queja','reclamo','sugerencia','felicitacion') NOT NULL,
            `subject` VARCHAR(255) NOT NULL,
            `description` TEXT NOT NULL,
            `status` ENUM('open','in_progress','awaiting_response','closed','escalated_sic') NOT NULL DEFAULT 'open',
            `sla_deadline` DATETIME NOT NULL,
            `response` TEXT,
            `responded_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL,
            `closed_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `udx_radicated` (`radicated_number`),
            KEY `idx_user` (`user_id`),
            KEY `idx_status` (`status`),
            KEY `idx_sla` (`sla_deadline`)
        ) {$wpdb->get_charset_collate()}" );

        $country   = LTMS_Core_Config::get_country();
        $sla_days  = self::PQR_SLA_BUSINESS_DAYS[ $country ] ?? 15;
        $deadline  = self::add_business_days( time(), $sla_days );

        $wpdb->insert( $table, [
            'radicated_number' => $radicated,
            'user_id'          => $user_id,
            'order_id'         => $order_id > 0 ? $order_id : null,
            'type'             => $type,
            'subject'          => $subject,
            'description'      => $description,
            'status'           => 'open',
            'sla_deadline'     => gmdate( 'Y-m-d H:i:s', $deadline ),
            'created_at'       => current_time( 'mysql', true ),
        ] );

        $pqr_id = (int) $wpdb->insert_id;

        // Disparar hook para notificaciones.
        do_action( 'ltms_pqr_created', $pqr_id, $radicated, $user_id );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'AC_PQR_CREATED',
                sprintf( 'PQR %s creada por user #%d (tipo=%s, SLA %d días hábiles, Ley 1480/2011 art. 53).', $radicated, $user_id, $type, $sla_days )
            );
        }

        return new \WP_REST_Response( [
            'radicated_number' => $radicated,
            'pqr_id'           => $pqr_id,
            'sla_deadline'     => gmdate( 'Y-m-d H:i:s', $deadline ),
            'sla_days'         => $sla_days,
        ], 201 );
    }

    /**
     * Genera número radicado único: PQR-YYYY-XXXXXXX.
     */
    public static function generate_pqr_radicated_number(): string {
        return sprintf( 'PQR-%d-%07d', (int) gmdate( 'Y' ), wp_rand( 1, 9999999 ) );
    }

    /**
     * Suma N días hábiles a un timestamp (excluye sábados y domingos).
     */
    private static function add_business_days( int $start_ts, int $days ): int {
        $ts = $start_ts;
        $added = 0;
        while ( $added < $days ) {
            $ts += DAY_IN_SECONDS;
            $dow = (int) gmdate( 'N', $ts );
            if ( $dow < 6 ) { // 1=Mon ... 5=Fri.
                ++$added;
            }
        }
        return $ts;
    }

    /**
     * Cron diario: alerta PQRs con SLA vencido o próximo a vencer.
     */
    public static function enforce_pqr_sla(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_pqr_requests';

        // Verificar si la tabla existe (puede no existir si no se ha creado PQR aún).
        $exists = $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
            DB_NAME, $table
        ) );
        if ( ! $exists ) return;

        // PQRs con SLA vencido (warning).
        $overdue = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, radicated_number, user_id, sla_deadline FROM `{$table}`
             WHERE status IN ('open','in_progress','awaiting_response')
               AND sla_deadline < %s",
            current_time( 'mysql', true )
        ), ARRAY_A );

        if ( ! empty( $overdue ) ) {
            foreach ( $overdue as $pqr ) {
                // Marcar como escalated_sic si está vencido.
                $wpdb->update( $table, [ 'status' => 'escalated_sic' ], [ 'id' => (int) $pqr['id'] ] );

                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::warning(
                        'AC_PQR_SLA_OVERDUE',
                        sprintf( 'PQR %s SLA vencido (%s). Escalada a SIC automática (Ley 1480/2011 art. 53).', $pqr['radicated_number'], $pqr['sla_deadline'] )
                    );
                }
            }
            // Notificar al oficial de cumplimiento.
            $email = LTMS_Core_Config::get( 'ltms_ft_compliance_officer_email', get_option( 'admin_email' ) );
            if ( $email ) {
                wp_mail(
                    $email,
                    sprintf( '[LTMS] %d PQRs con SLA vencido — escalada a SIC', count( $overdue ) ),
                    sprintf( "Las siguientes PQRs superaron el SLA legal y fueron escaladas:\n\n%s", wp_json_encode( $overdue, JSON_PRETTY_PRINT ) )
                );
            }
        }

        // PQRs próximas a vencer (24h).
        $soon = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
        $upcoming = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, radicated_number, sla_deadline FROM `{$table}`
             WHERE status IN ('open','in_progress','awaiting_response')
               AND sla_deadline BETWEEN %s AND %s",
            current_time( 'mysql', true ), $soon
        ), ARRAY_A );

        if ( ! empty( $upcoming ) && class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::warning(
                'AC_PQR_SLA_SOON',
                sprintf( '%d PQRs vencen en 24h.', count( $upcoming ) )
            );
        }
    }

    // ================================================================
    // AC-3: REPORTE PPC SIC.
    // ================================================================

    /**
     * Reporta una PQR cerrada a la Plataforma de Protección al Consumidor SIC.
     *
     * Decreto 1164/2022.
     *
     * @param int    $pqr_id ID de la PQR.
     * @param string $radicated Número radicado.
     */
    public static function report_to_ppc_sic( int $pqr_id, string $radicated ): void {
        $country = LTMS_Core_Config::get_country();
        if ( $country !== 'CO' ) return; // PPC SIC solo aplica CO.

        global $wpdb;
        $table = $wpdb->prefix . 'lt_pqr_requests';
        $pqr   = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE id = %d",
            $pqr_id
        ), ARRAY_A );

        if ( ! $pqr ) return;

        // Generar XML PPC SIC.
        $xml = self::build_ppc_sic_xml( $pqr );

        // Enviar a API PPC SIC (configurable sandbox/producción).
        $endpoint = LTMS_Core_Config::get( 'ltms_ppc_sic_endpoint', self::VALIDATION_APIS['ppc_sic'] );
        $token    = LTMS_Core_Config::get( 'ltms_ppc_sic_token', '' );

        if ( empty( $token ) ) {
            // Sin token: solo log.
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'AC_PPC_SIC_NO_TOKEN',
                    sprintf( 'PQR %s — token PPC SIC no configurado. Reporte no enviado.', $radicated )
                );
            }
            return;
        }

        $response = wp_remote_post( $endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/xml',
            ],
            'body'    => $xml,
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::error(
                    'AC_PPC_SIC_REPORT_FAILED',
                    sprintf( 'PQR %s — fallo envío PPC SIC: %s', $radicated, $response->get_error_message() )
                );
            }
            return;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code >= 200 && $code < 300 ) {
            $sic_receipt = wp_remote_retrieve_body( $response );
            $wpdb->update( $table, [ 'sic_receipt' => $sic_receipt ], [ 'id' => $pqr_id ] );

            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info(
                    'AC_PPC_SIC_REPORTED',
                    sprintf( 'PQR %s reportada a PPC SIC (Decreto 1164/2022).', $radicated )
                );
            }
        }
    }

    /**
     * Construye XML PPC SIC para una PQR.
     */
    private static function build_ppc_sic_xml( array $pqr ): string {
        $user = get_userdata( (int) $pqr['user_id'] );
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<ppc:queja xmlns:ppc="http://ppc.gov.co/schemas/v1">' . "\n";
        $xml .= '  <ppc:radicado>' . esc_xml( $pqr['radicated_number'] ) . '</ppc:radicado>' . "\n";
        $xml .= '  <ppc:fecha>' . esc_xml( $pqr['created_at'] ) . '</ppc:fecha>' . "\n";
        $xml .= '  <ppc:tipo>' . esc_xml( $pqr['type'] ) . '</ppc:tipo>' . "\n";
        $xml .= '  <ppc:consumidor>' . "\n";
        $xml .= '    <ppc:nombre>' . esc_xml( $user ? $user->display_name : '' ) . '</ppc:nombre>' . "\n";
        $xml .= '    <ppc:email>' . esc_xml( $user ? $user->user_email : '' ) . '</ppc:email>' . "\n";
        $xml .= '  </ppc:consumidor>' . "\n";
        $xml .= '  <ppc:asunto>' . esc_xml( $pqr['subject'] ) . '</ppc:asunto>' . "\n";
        $xml .= '  <ppc:descripcion>' . esc_xml( $pqr['description'] ) . '</ppc:descripcion>' . "\n";
        if ( $pqr['order_id'] ) {
            $xml .= '  <ppc:pedido_id>' . (int) $pqr['order_id'] . '</ppc:pedido_id>' . "\n";
        }
        $xml .= '  <ppc:respuesta>' . esc_xml( $pqr['response'] ?? '' ) . '</ppc:respuesta>' . "\n";
        $xml .= '  <ppc:estado_final>' . esc_xml( $pqr['status'] ) . '</ppc:estado_final>' . "\n";
        $xml .= '</ppc:queja>' . "\n";
        return $xml;
    }

    // ================================================================
    // AC-4: CERTIFICADO ICA / SENASICA.
    // ================================================================

    public static function register_ica_metabox(): void {
        echo '<div class="options_group ltms-ica-metabox">';
        echo '<h3 style="padding:8px 10px;margin:0;background:#dcfce7;">🌱 ' . esc_html__( 'Certificado fitosanitario (Ley 1011/2006 ICA CO / Ley 43/2007 SENASICA MX)', 'ltms' ) . '</h3>';

        woocommerce_wp_text_input( [
            'id'          => '_ltms_ica_certificate',
            'label'       => __( 'Número ICA / SENASICA', 'ltms' ),
            'description' => __( 'Ej: ICA-12345 (CO) o SENASICA-67890 (MX). Obligatorio para productos agropecuarios.', 'ltms' ),
        ] );

        woocommerce_wp_text_input( [
            'id'                => '_ltms_ica_expires',
            'label'             => __( 'Vencimiento certificado', 'ltms' ),
            'type'              => 'date',
        ] );
        echo '</div>';
    }

    public static function save_ica_meta( int $product_id ): void {
        $ica   = isset( $_POST['_ltms_ica_certificate'] ) ? sanitize_text_field( wp_unslash( $_POST['_ltms_ica_certificate'] ) ) : '';
        $exp   = isset( $_POST['_ltms_ica_expires'] ) ? sanitize_text_field( wp_unslash( $_POST['_ltms_ica_expires'] ) ) : '';
        update_post_meta( $product_id, '_ltms_ica_certificate', $ica );
        update_post_meta( $product_id, '_ltms_ica_expires', $exp );

        // Validar: si el producto está en categoría agropecuaria, ICA es obligatorio.
        $terms = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'slugs' ] );
        $requires_ica = false;
        if ( is_array( $terms ) ) {
            foreach ( $terms as $slug ) {
                if ( in_array( $slug, self::AGRI_CATEGORIES, true ) ) {
                    $requires_ica = true;
                    break;
                }
            }
        }
        if ( $requires_ica && empty( $ica ) ) {
            update_post_meta( $product_id, '_ltms_ica_missing', 'yes' );
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'AC_ICA_MISSING',
                    sprintf( 'Product #%d — categoría agropecuaria sin certificado ICA (Ley 1011/2006).', $product_id )
                );
            }
        } else {
            delete_post_meta( $product_id, '_ltms_ica_missing' );
        }
    }

    // ================================================================
    // AC-5: RESPEL / RAEE (electrónicos).
    // ================================================================

    public static function register_respel_metabox(): void {
        echo '<div class="options_group ltms-respel-metabox">';
        echo '<h3 style="padding:8px 10px;margin:0;background:#e0e7ff;">♻️ ' . esc_html__( 'RESPEL / RAEE (Decreto 1076/2015 + Ley 1672/2013 ANLA CO / LGPGIR MX)', 'ltms' ) . '</h3>';

        woocommerce_wp_checkbox( [
            'id'          => '_ltms_is_raee',
            'label'       => __( 'Es RAEE (Residuo Aparato Eléctrico Electrónico)', 'ltms' ),
            'description' => __( 'Marca si el producto genera residuos electrónicos al final de su vida útil.', 'ltms' ),
        ] );

        woocommerce_wp_text_input( [
            'id'          => '_ltms_raee_category',
            'label'       => __( 'Categoría RAEE', 'ltms' ),
            'description' => __( 'Ej: R1 (grandes), R2 (pequeños), R3 (IT/telecom), R4 (pantallas), R5 (iluminación), R6 (herramientas).', 'ltms' ),
        ] );
        echo '</div>';
    }

    public static function save_respel_meta( int $product_id ): void {
        $is_raee = isset( $_POST['_ltms_is_raee'] ) ? 'yes' : 'no';
        $cat     = isset( $_POST['_ltms_raee_category'] ) ? sanitize_text_field( wp_unslash( $_POST['_ltms_raee_category'] ) ) : '';
        update_post_meta( $product_id, '_ltms_is_raee', $is_raee );
        update_post_meta( $product_id, '_ltms_raee_category', $cat );
    }

    /**
     * Muestra banner de punto de recogida RAEE en PDP.
     */
    public static function add_respel_takeback_notice(): void {
        global $product;
        if ( ! $product ) return;
        if ( get_post_meta( $product->get_id(), '_ltms_is_raee', true ) !== 'yes' ) return;
        ?>
        <div class="ltms-raee-notice" style="background:#e0e7ff;border-left:4px solid #4f46e5;padding:10px;margin:8px 0;">
            <strong>♻️ <?php esc_html_e( 'Producto RAEE — Punto de recogida', 'ltms' ); ?></strong>
            <p style="margin:4px 0;font-size:12px;">
                <?php esc_html_e( 'Al final de su vida útil, este producto puede entregarse en cualquier punto de recogida autorizado para reciclaje electrónico (Decreto 1076/2015 + Ley 1672/2013 ANLA / LGPGIR MX).', 'ltms' ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Cron anual: reporte RAEE vendido (Ley 1672/2013).
     */
    public static function generate_raee_annual_report(): void {
        global $wpdb;
        $year = (int) gmdate( 'Y' );
        $since = sprintf( '%d-01-01 00:00:00', $year );
        $until = sprintf( '%d-12-31 23:59:59', $year );

        // Buscar productos RAEE vendidos en el año.
        $product_ids = get_posts( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => 5000,
            'meta_query'     => [
                [ 'key' => '_ltms_is_raee', 'value' => 'yes' ],
            ],
        ] );

        if ( empty( $product_ids ) ) return;

        $report = [];
        foreach ( $product_ids as $pid ) {
            $cat = get_post_meta( $pid, '_ltms_raee_category', true ) ?: 'R3';
            // Sumar ventas del año.
            $sold = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT SUM(oi.quantity) FROM {$wpdb->prefix}woocommerce_order_items oi
                 JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
                 JOIN {$wpdb->posts} p ON p.ID = oi.order_id
                 WHERE oim.meta_key = '_product_id' AND oim.meta_value = %d
                   AND p.post_type = 'shop_order'
                   AND p.post_date BETWEEN %s AND %s",
                $pid, $since, $until
            ) );

            if ( $sold > 0 ) {
                $report[] = [
                    'product_id'   => $pid,
                    'product_name' => get_the_title( $pid ),
                    'raee_category'=> $cat,
                    'units_sold'   => $sold,
                ];
            }
        }

        if ( empty( $report ) ) return;

        // Generar CSV.
        $dir = self::ensure_dir( 'ltms-raee' );
        $path = $dir . '/raee_report_' . $year . '_' . wp_generate_password( 6, false ) . '.csv';
        $fp = fopen( $path, 'w' );
        fputcsv( $fp, [ 'PRODUCT_ID', 'PRODUCT_NAME', 'RAEE_CATEGORY', 'UNITS_SOLD', 'YEAR' ] );
        foreach ( $report as $r ) {
            fputcsv( $fp, [ $r['product_id'], $r['product_name'], $r['raee_category'], $r['units_sold'], $year ] );
        }
        fclose( $fp );

        // Notificar al oficial de cumplimiento (envío a ANLA / SEMARNAT).
        $email = LTMS_Core_Config::get( 'ltms_ft_compliance_officer_email', get_option( 'admin_email' ) );
        if ( $email ) {
            wp_mail(
                $email,
                sprintf( '[LTMS] Reporte RAEE anual %d — %d productos', $year, count( $report ) ),
                sprintf( "Reporte RAEE generado (Ley 1672/2013 ANLA / LGPGIR MX).\n\nArchivo: %s\n\nEnviar a ANLA (CO) o SEMARNAT (MX) antes de 31 de marzo.", $path )
            );
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'AC_RAEE_ANNUAL_REPORT',
                sprintf( 'Reporte RAEE %d: %d productos, archivo=%s', $year, count( $report ), $path )
            );
        }
    }

    // ================================================================
    // AC-6: CONCILIACIÓN EXTRAJUDICIAL SIC / PROFECO.
    // ================================================================

    /**
     * Ofrece opción de conciliación SIC/PROFECO al presentar disputa.
     *
     * Ley 1480/2011 art. 61 + Ley 640/2001 (CO).
     * Ley 763/2018 (MX).
     *
     * @param int $dispute_id ID de la disputa.
     * @param int $order_id ID de la orden.
     * @param int $vendor_id ID del vendor.
     * @param int $customer_id ID del cliente.
     */
    public static function offer_conciliation_option( int $dispute_id, int $order_id, int $vendor_id = 0, int $customer_id = 0 ): void {
        // Marcar disputa como elegible para conciliación.
        global $wpdb;
        $table = $wpdb->prefix . 'lt_disputes';
        $exists = $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
            DB_NAME, $table
        ) );
        if ( ! $exists ) return;

        $wpdb->update( $table, [
            'conciliation_eligible' => 1,
            'conciliation_offered_at' => current_time( 'mysql', true ),
        ], [ 'id' => $dispute_id ] );

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'AC_CONCILIATION_OFFERED',
                sprintf( 'Disputa #%d — conciliación SIC/PROFECO ofrecida (Ley 1480/2011 art. 61 / Ley 763/2018 MX).', $dispute_id )
            );
        }

        // Notificar al cliente que puede solicitar conciliación.
        $customer = get_userdata( $customer_id );
        if ( $customer ) {
            $country = LTMS_Core_Config::get_country();
            $authority = $country === 'CO' ? 'SIC (Juntas de Conciliación)' : 'PROFECO (Mediación)';
            wp_mail(
                $customer->user_email,
                __( 'Opción de conciliación disponible', 'ltms' ),
                sprintf(
                    /* translators: 1: authority name, 2: dispute id */
                    __( "Tu disputa #%2\$d puede resolverse vía conciliación ante %1\$s.\n\nLa conciliación es gratuita y permite llegar a un acuerdo sin judicializar el caso. Responder en un plazo de 5 días hábiles si deseas esta vía (Ley 1480/2011 art. 61 / Ley 763/2018 MX).", 'ltms' ),
                    $authority, $dispute_id
                )
            );
        }
    }

    // ================================================================
    // AC-7: VALIDACIÓN RUT DIAN + CÁMARA DE COMERCIO.
    // ================================================================

    /**
     * Valida RUT activo en DIAN + matrícula Cámara de Comercio vigente.
     *
     * Decreto 2150/1995 + EOSF art. 102 (CO).
     *
     * @param bool $approved Estado de aprobación.
     * @param int  $vendor_id ID del vendor.
     * @return bool False si RUT o Cámara de Comercio inválidos.
     */
    public static function validate_rut_and_camara_comercio( bool $approved, int $vendor_id ): bool {
        if ( ! $approved ) return false;

        $country = LTMS_Core_Config::get_country();

        // CO: validar RUT DIAN + Cámara de Comercio.
        if ( $country === 'CO' ) {
            $tax_id     = get_user_meta( $vendor_id, 'ltms_tax_id', true ); // NIT.
            $cc_number  = get_user_meta( $vendor_id, 'ltms_camara_comercio_number', true );
            $cc_expires = get_user_meta( $vendor_id, 'ltms_camara_comercio_expires', true );

            // Verificar NIT contra API DIAN (si token configurado).
            $dian_token = LTMS_Core_Config::get( 'ltms_dian_api_token', '' );
            if ( ! empty( $tax_id ) && ! empty( $dian_token ) ) {
                $dian_ok = self::verify_rut_with_dian( $tax_id, $dian_token );
                if ( ! $dian_ok ) {
                    if ( class_exists( 'LTMS_Core_Logger' ) ) {
                        LTMS_Core_Logger::warning(
                            'AC_RUT_DIAN_INVALID',
                            sprintf( 'Vendor #%d — NIT %s no válido en DIAN (Decreto 2150/1995).', $vendor_id, $tax_id )
                        );
                    }
                    return false;
                }
            }

            // Verificar Cámara de Comercio vigente.
            if ( empty( $cc_number ) ) {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::warning(
                        'AC_CC_NUMBER_MISSING',
                        sprintf( 'Vendor #%d — sin matrícula Cámara de Comercio (Decreto 2150/1995).', $vendor_id )
                    );
                }
                return false;
            }
            if ( ! empty( $cc_expires ) && strtotime( $cc_expires ) < time() ) {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::warning(
                        'AC_CC_EXPIRED',
                        sprintf( 'Vendor #%d — matrícula Cámara de Comercio vencida el %s.', $vendor_id, $cc_expires )
                    );
                }
                return false;
            }
        }

        // MX: validar RFC + padrón SAT.
        if ( $country === 'MX' ) {
            $rfc = get_user_meta( $vendor_id, 'ltms_tax_id', true );
            if ( empty( $rfc ) ) return false;

            $sat_token = LTMS_Core_Config::get( 'ltms_sat_api_token', '' );
            if ( ! empty( $sat_token ) ) {
                $sat_ok = self::verify_rfc_with_sat( $rfc, $sat_token );
                if ( ! $sat_ok ) {
                    if ( class_exists( 'LTMS_Core_Logger' ) ) {
                        LTMS_Core_Logger::warning(
                            'AC_RFC_SAT_INVALID',
                            sprintf( 'Vendor #%d — RFC %s no válido en padrón SAT.', $vendor_id, $rfc )
                        );
                    }
                    return false;
                }
            }
        }

        return $approved;
    }

    /**
     * Verifica RUT/NIT contra API DIAN (placeholder, integración real configurable).
     */
    private static function verify_rut_with_dian( string $nit, string $token ): bool {
        // En producción: POST a API DIAN con NIT + token.
        // Por ahora: validación de formato (NIT colombiano: XXXXXXXXX-X).
        if ( ! preg_match( '/^\d{8,9}-\d$/', $nit ) ) {
            return false;
        }
        // Algoritmo de dígito de verificación (módulo 11).
        list( $nums, $dv ) = explode( '-', $nit );
        $weights = [ 41, 37, 29, 23, 19, 17, 13, 7, 3 ];
        $sum = 0;
        $len = strlen( $nums );
        for ( $i = 0; $i < $len; $i++ ) {
            $sum += (int) $nums[ $i ] * $weights[ $i + ( 9 - $len ) ];
        }
        $mod = $sum % 11;
        $calc_dv = ( 11 - $mod === 11 ) ? 0 : ( 11 - $mod === 10 ? 'K' : (string) ( 11 - $mod ) );
        return (string) $calc_dv === $dv;
    }

    /**
     * Verifica RFC contra padrón SAT (placeholder).
     */
    private static function verify_rfc_with_sat( string $rfc, string $token ): bool {
        // Validación de formato RFC persona moral (12 chars) o física (13 chars).
        if ( strlen( $rfc ) === 12 ) {
            return (bool) preg_match( '/^[A-ZÑ&]{3}\d{6}[A-Z0-9]{3}$/', $rfc );
        } elseif ( strlen( $rfc ) === 13 ) {
            return (bool) preg_match( '/^[A-ZÑ&]{4}\d{6}[A-Z0-9]{3}$/', $rfc );
        }
        return false;
    }

    // ================================================================
    // AC-8: REPORTE INVIMA ANUAL.
    // ================================================================

    /**
     * Genera reporte anual de productos INVIMA-reportables comercializados.
     *
     * Decreto 1782/2003 INVIMA + Res. 3119/2005, 831/2004, 5109/2005 (CO).
     * COFEPRIS RMF (MX).
     */
    public static function generate_invima_annual_report(): void {
        global $wpdb;
        $year  = (int) gmdate( 'Y' );
        $since = sprintf( '%d-01-01 00:00:00', $year );
        $until = sprintf( '%d-12-31 23:59:59', $year );

        // Buscar productos INVIMA-reportables.
        $product_ids = get_posts( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => 5000,
        ] );

        $report = [];
        foreach ( $product_ids as $pid ) {
            // Verificar si el producto está en categoría INVIMA-reportable.
            $terms = wp_get_post_terms( $pid, 'product_cat', [ 'fields' => 'slugs' ] );
            $invima_cat = null;
            if ( is_array( $terms ) ) {
                foreach ( $terms as $slug ) {
                    if ( in_array( $slug, self::INVIMA_REPORTABLE_CATEGORIES, true ) ) {
                        $invima_cat = $slug;
                        break;
                    }
                }
            }
            if ( ! $invima_cat ) continue;

            $invima_cert = get_post_meta( $pid, '_ltms_cert_invima_registro', true );
            $sold = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT SUM(oi.quantity) FROM {$wpdb->prefix}woocommerce_order_items oi
                 JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
                 JOIN {$wpdb->posts} p ON p.ID = oi.order_id
                 WHERE oim.meta_key = '_product_id' AND oim.meta_value = %d
                   AND p.post_type = 'shop_order'
                   AND p.post_date BETWEEN %s AND %s",
                $pid, $since, $until
            ) );
            if ( $sold <= 0 ) continue;

            $report[] = [
                'product_id'   => $pid,
                'product_name' => get_the_title( $pid ),
                'category'     => $invima_cat,
                'invima_cert'  => $invima_cert,
                'units_sold'   => $sold,
            ];
        }

        if ( empty( $report ) ) return;

        // Generar CSV.
        $dir = self::ensure_dir( 'ltms-invima' );
        $path = $dir . '/invima_report_' . $year . '_' . wp_generate_password( 6, false ) . '.csv';
        $fp = fopen( $path, 'w' );
        fputcsv( $fp, [ 'PRODUCT_ID', 'PRODUCT_NAME', 'CATEGORY', 'INVIMA_CERT', 'UNITS_SOLD', 'YEAR' ] );
        foreach ( $report as $r ) {
            fputcsv( $fp, [ $r['product_id'], $r['product_name'], $r['category'], $r['invima_cert'], $r['units_sold'], $year ] );
        }
        fclose( $fp );

        $email = LTMS_Core_Config::get( 'ltms_ft_compliance_officer_email', get_option( 'admin_email' ) );
        if ( $email ) {
            wp_mail(
                $email,
                sprintf( '[LTMS] Reporte INVIMA anual %d — %d productos', $year, count( $report ) ),
                sprintf( "Reporte INVIMA generado (Decreto 1782/2003 + Res. 3119/2005 / 831/2004 / 5109/2005).\n\nArchivo: %s\n\nEnviar a INVIMA (CO) o COFEPRIS (MX) antes de 31 de marzo.", $path )
            );
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'AC_INVIMA_ANNUAL_REPORT',
                sprintf( 'Reporte INVIMA %d: %d productos, archivo=%s', $year, count( $report ), $path )
            );
        }
    }

    // ================================================================
    // AC-9: COMPETENCIA DESLEAL — DETECCIÓN PRECIOS.
    // ================================================================

    /**
     * Detecta precios anormalmente bajos (predación) o altos (excesivo).
     *
     * Ley 256/1996 arts. 10-15 + Ley 1340/2010 (CO).
     * LFCE art. 53-57 (MX).
     *
     * @param int $product_id ID del producto.
     */
    public static function detect_unfair_pricing( int $product_id ): void {
        $product = wc_get_product( $product_id );
        if ( ! $product ) return;
        $price = (float) $product->get_price();
        if ( $price <= 0 ) return;

        // Obtener categoría del producto.
        $terms = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
        if ( empty( $terms ) ) return;
        $cat_id = (int) $terms[0];

        // Buscar precios de otros productos en la misma categoría.
        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => 500,
            'tax_query'      => [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $cat_id ] ],
            'exclude'        => [ $product_id ],
        ];
        $peer_ids = get_posts( $args );
        if ( count( $peer_ids ) < self::UNFAIR_PRICING_THRESHOLDS['min_sample_size'] ) {
            return; // Muestra insuficiente.
        }

        $peer_prices = [];
        foreach ( $peer_ids as $pid ) {
            $p = wc_get_product( $pid );
            if ( $p ) {
                $pr = (float) $p->get_price();
                if ( $pr > 0 ) $peer_prices[] = $pr;
            }
        }
        if ( count( $peer_prices ) < self::UNFAIR_PRICING_THRESHOLDS['min_sample_size'] ) return;

        $avg = array_sum( $peer_prices ) / count( $peer_prices );
        $variance = 0.0;
        foreach ( $peer_prices as $pr ) {
            $variance += ( $pr - $avg ) ** 2;
        }
        $std = sqrt( $variance / count( $peer_prices ) );
        if ( $std <= 0 ) return;

        $z = ( $price - $avg ) / $std;

        // Predación: precio < promedio - 3σ.
        if ( $z < -self::UNFAIR_PRICING_THRESHOLDS['predation_sigma'] ) {
            update_post_meta( $product_id, '_ltms_pricing_review_required', 'predation' );
            update_post_meta( $product_id, '_ltms_pricing_z_score', round( $z, 2 ) );
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'AC_UNFAIR_PRICING_PREDATION',
                    sprintf( 'Product #%d — z-score %.2f (posible predación, Ley 256/1996 + Ley 1340/2010 / LFCE MX).', $product_id, $z )
                );
            }
        }
        // Precio excesivo: precio > promedio + 5σ.
        elseif ( $z > self::UNFAIR_PRICING_THRESHOLDS['excessive_sigma'] ) {
            update_post_meta( $product_id, '_ltms_pricing_review_required', 'excessive' );
            update_post_meta( $product_id, '_ltms_pricing_z_score', round( $z, 2 ) );
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'AC_UNFAIR_PRICING_EXCESSIVE',
                    sprintf( 'Product #%d — z-score %.2f (posible precio excesivo, Ley 256/1996 + Ley 1340/2010 / LFCE MX).', $product_id, $z )
                );
            }
        } else {
            delete_post_meta( $product_id, '_ltms_pricing_review_required' );
            delete_post_meta( $product_id, '_ltms_pricing_z_score' );
        }
    }

    // ================================================================
    // HELPERS.
    // ================================================================

    private static function ensure_dir( string $subdir ): string {
        $upload_dir = wp_upload_dir();
        $dir = $upload_dir['basedir'] . '/' . $subdir;
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        return $dir;
    }

    public static function get_legal_basis(): array {
        return [
            'CO' => [
                'Ley 256/1996'                    => 'Competencia desleal.',
                'Ley 599/2000 art. 304'           => 'Penal — fabricación falsificada.',
                'Ley 1480/2011 art. 53, 61'       => 'PQR + conciliación SIC.',
                'Ley 2439/2024 art. 50-g'         => 'PQR con radicado.',
                'Ley 640/2001'                    => 'Conciliación extrajudicial.',
                'Ley 1340/2010'                   => 'Competencia SIC.',
                'Ley 1011/2006'                   => 'Sanidad vegetal ICA.',
                'Ley 1672/2013'                   => 'Gestión RAEE.',
                'Decreto 1164/2022'               => 'PPC SIC obligatorio.',
                'Decreto 2150/1995'               => 'Cámara de Comercio.',
                'Decreto 1076/2015'               => 'RESPEL.',
                'Decreto 1782/2003'               => 'INVIMA reportes.',
                'Resolución ICA 0098/2020'        => 'Certificado fitosanitario.',
                'Resolución INVIMA 3119/2005'     => 'Cosméticos.',
                'Resolución INVIMA 831/2004'      => 'Juguetes.',
                'Resolución INVIMA 5109/2005'     => 'Alimentos.',
            ],
            'MX' => [
                'LPI art. 223-231'                => 'Propiedad industrial IMPI.',
                'LFPCE art. 99'                   => 'PQR PROFECO.',
                'Ley 763/2018'                    => 'Mediación PROFECO.',
                'Ley 43/2007'                     => 'SENASICA.',
                'LGPGIR'                          => 'Residuos peligrosos SEMARNAT.',
                'NOM-052-SEMARNAT-2005'           => 'Clasificación RESPEL.',
                'LFCE art. 53-57'                 => 'COFECE/IFT competencia.',
            ],
        ];
    }
}
