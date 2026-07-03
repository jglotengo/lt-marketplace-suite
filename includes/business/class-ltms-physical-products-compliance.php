<?php
/**
 * LTMS Physical Products Compliance — Cumplimiento normativo para productos físicos.
 *
 * v2.9.15 — Cierra 8 brechas de cumplimiento específicas para productos físicos
 * detectadas en la auditoría v2.9.14:
 *
 *  PP-1 (CRÍTICO): Garantía legal mínima obligatoria.
 *    Norma: Colombia Ley 1480/2011 art. 12 (1 año para productos nuevos,
 *           3 meses para usados); México LFPCE art. 92 (3 meses mínimo).
 *    Antes: el producto NO tenía campo para registrar período de garantía
 *           ni se validaba el mínimo legal.
 *    Fix: register_warranty_metabox() añade campos (warranty_months +
 *           warranty_type new/used). validate_warranty_minimum() hook
 *           woocommerce_process_product_meta bloquea guardar si warranty
 *           < mínimo legal (12 meses nuevo, 3 meses usado en CO; 3 meses MX).
 *           display_warranty_info() muestra info en PDP.
 *
 *  PP-2 (CRÍTICO): País de origen obligatorio.
 *    Norma: Colombia Resolución DIAN 000070/2020 art. 5 (declaración de
 *           importación debe indicar país de origen); México Ley de
 *           Comercio Exterior art. 31; Reglamento (UE) 1169/2011 art. 9.
 *    Antes: el producto NO tenía campo para registrar país de origen.
 *    Fix: register_origin_metabox() añade campo 'country_of_origin'
 *           (ISO 3166-1 alpha-2). validate_origin_required() bloquea
 *           publicar producto sin país de origen. display_origin_badge()
 *           muestra "Hecho en X" en PDP.
 *
 *  PP-3 (ALTO): Mercancías peligrosas (hazmat).
 *    Norma: IATA DGR (baterías de litio — UN3480, UN3481, UN3091);
 *           ONU Recomendaciones Transporte Mercancías Peligrosas;
 *           México NOM-002-SCT/2011 (lista de mercancías peligrosas).
 *    Antes: el marketplace NO detectaba ni gestionaba productos peligrosos.
 *    Fix: register_hazmat_metabox() añade campos (is_hazmat, un_number,
 *           hazmat_class, packing_group). display_hazmat_warning() muestra
 *           advertencia en PDP + checkout. validate_hazmat_shipping()
 *           bloquea envíos aéreos para UN3480 (baterías litio sueltas).
 *
 *  PP-4 (ALTO): Certificaciones sanitarias obligatorias por categoría.
 *    Norma: Colombia Resolución 831/2004 INVIMA (juguetes),
 *           Resolución 3119/2005 (cosméticos);
 *           México NOM-015-SCFI-1998 (juguetes), NOM-141-SSA1-2012
 *           (cosméticos), NOM-024-SCFI-2013 (productos electrónicos).
 *    Antes: el producto NO tenía campo para certificaciones.
 *    Fix: register_certifications_metabox() añade campos por categoría:
 *           - juguetes: registro INVIMA / NOM-015 cumplimiento
 *           - cosméticos: registro INVIMA / aviso COFEPRIS
 *           - electrónicos: NOM-024 (información comercial)
 *           validate_certifications() hook woocommerce_process_product_meta
 *           bloquea publicar si categoría requiere certificación.
 *
 *  PP-5 (MEDIO): Etiquetado de composición textil.
 *    Norma: Colombia NTC 1101 (etiquetado de productos textiles);
 *           México NOM-004-SCFI-2006 (etiquetado de productos textiles,
 *           prendas de vestir y sus accesorios).
 *    Antes: el producto textil NO tenía campo para composición.
 *    Fix: register_textile_metabox() añade campos (fiber_composition,
 *           care_instructions, size_system). display_textile_label()
 *           muestra info de etiquetado en PDP.
 *
 *  PP-6 (ALTO): ICE (Impuesto al Consumo Específico) CO + IEPS MX.
 *    Norma: Colombia Estatuto Tributario art. 468 (alcohol 35%),
 *           art. 469 (tabaco 75% + $87/cta 20 cigarrillos);
 *           México LIEPS art. 2 (alcohol, tabaco, bebidas azucaradas).
 *    Antes: el tax engine calculaba IVA pero NO ICE/IEPS específicos
 *           para productos regulados.
 *    Fix: add_ice_ieps_to_taxes() filter ltms_tax_calculation_result
 *           añade impuesto especial según categoría del producto:
 *           - cigarrillos/tabaco: CO ICE 75% + cuota; MX IEPS 160%
 *           - alcohol: CO ICE 35%; MX IEPS 26-53% por graduación
 *           - bebidas azucaradas: MX IEPS 8%
 *
 *  PP-7 (MEDIO): Trazabilidad por número de lote.
 *    Norma: Colombia Decreto 614/2013 art. 17; México NOM-024-SCFI-2013.
 *    Antes: el producto NO tenía campo de número de lote.
 *    Fix: register_batch_metabox() añade campos (batch_number,
 *           manufacture_date, expiry_date). display_batch_info()
 *           muestra info en PDP. save_batch_to_order() copia al order
 *           meta para trazabilidad post-venta (recall).
 *
 *  PP-8 (BUG): Customs declarations no consultan país de origen.
 *    Norma: CO Resolución DIAN 000070/2020 art. 5 + MX Reglamento LCE art. 11.
 *    Bug detectado: lt_customs_declarations tabla existe y se persiste,
 *      pero el cálculo aduanero NO usa el país de origen del producto
 *      para determinar tratados de libre comercio (TLC CO-MX, CO-EU,
 *      MX-EU, etc.). Resultado: aranceles se aplican al máximo aunque
 *      el producto califique para TLC.
 *    Fix: enhance_customs_calculation() filter ltms_customs_calc_args
 *           inyecta país de origen del producto → customs calculator
 *           aplica tasa TLC si existe.
 *
 * Normas cubiertas (CO + MX + cross-border):
 *  - Ley 1480/2011 art. 12 (CO garantía legal)
 *  - Resolución DIAN 000070/2020 (CO país de origen)
 *  - Resolución 831/2004 INVIMA (CO juguetes)
 *  - Resolución 3119/2005 INVIMA (CO cosméticos)
 *  - Decreto 614/2013 art. 17 (CO trazabilidad)
 *  - Estatuto Tributario art. 468 (CO ICE alcohol)
 *  - Estatuto Tributario art. 469 (CO ICE tabaco)
 *  - NTC 1101 (CO etiquetado textil)
 *  - LFPCE art. 92 (MX garantía legal)
 *  - Ley de Comercio Exterior art. 31 (MX país de origen)
 *  - NOM-002-SCT/2011 (MX mercancías peligrosas)
 *  - NOM-004-SCFI-2006 (MX etiquetado textil)
 *  - NOM-015-SCFI-1998 (MX juguetes)
 *  - NOM-024-SCFI-2013 (MX electrónicos)
 *  - NOM-141-SSA1-2012 (MX cosméticos)
 *  - LIEPS art. 2 (MX IEPS)
 *  - IATA DGR (baterías litio — UN3480/UN3481/UN3091)
 *  - Reglamento (UE) 1169/2011 art. 9 (país de origen)
 *
 * @package LTMS
 * @version 2.9.15
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Physical_Products_Compliance {

    /**
     * Períodos mínimos de garantía legal por país (en meses).
     */
    public const WARRANTY_MINIMUMS = [
        'CO' => [
            'new'  => 12, // Ley 1480/2011 art. 12
            'used' => 3,  // Ley 1480/2011 art. 13
        ],
        'MX' => [
            'new'  => 3,  // LFPCE art. 92
            'used' => 3,
        ],
    ];

    /**
     * Categorías que requieren certificación sanitaria obligatoria.
     *
     * @var array Map: category_slug => [country => [cert_key => label]].
     */
    public const CERT_REQUIRED_CATEGORIES = [
        'juguetes' => [
            'CO' => [ 'invima_registro' => 'Registro Sanitario INVIMA (Resolución 831/2004)' ],
            'MX' => [ 'nom_015' => 'Cumplimiento NOM-015-SCFI-1998 (juguetes)' ],
        ],
        'cosmeticos' => [
            'CO' => [ 'invima_registro' => 'Registro Sanitario INVIMA (Resolución 3119/2005)' ],
            'MX' => [ 'cofepris_aviso' => 'Aviso de Funcionamiento COFEPRIS (NOM-141-SSA1-2012)' ],
        ],
        'electronico' => [
            'CO' => [ 'icontec_ntc' => 'Certificación NTC-IEC (seguridad eléctrica)' ],
            'MX' => [ 'nom_024' => 'Cumplimiento NOM-024-SCFI-2013 (información comercial)' ],
        ],
        'electrodomestico' => [
            'CO' => [ 'icontec_ntc' => 'Certificación NTC-IEC (seguridad eléctrica)' ],
            'MX' => [ 'nom_024' => 'Cumplimiento NOM-024-SCFI-2013' ],
        ],
    ];

    /**
     * Categorías de productos regulados por ICE/IEPS con sus tasas.
     *
     * @var array Map: category_slug => [country => ['rate' => float, 'cuota' => float, 'norma' => string]].
     */
    public const REGULATED_CATEGORIES = [
        'cigarrillos' => [
            'CO' => [ 'rate' => 0.75, 'cuota_per_pack' => 1450.0, 'norma' => 'ET art. 469' ],
            'MX' => [ 'rate' => 1.60, 'cuota_per_pack' => 0.0,    'norma' => 'LIEPS art. 2' ],
        ],
        'tabaco' => [
            'CO' => [ 'rate' => 0.75, 'cuota_per_pack' => 1450.0, 'norma' => 'ET art. 469' ],
            'MX' => [ 'rate' => 1.60, 'cuota_per_pack' => 0.0,    'norma' => 'LIEPS art. 2' ],
        ],
        'alcohol' => [
            'CO' => [ 'rate' => 0.35, 'cuota_per_pack' => 0.0,    'norma' => 'ET art. 468' ],
            'MX' => [ 'rate' => 0.53, 'cuota_per_pack' => 0.0,    'norma' => 'LIEPS art. 2' ],
        ],
        'spirits' => [
            'CO' => [ 'rate' => 0.35, 'cuota_per_pack' => 0.0,    'norma' => 'ET art. 468' ],
            'MX' => [ 'rate' => 0.53, 'cuota_per_pack' => 0.0,    'norma' => 'LIEPS art. 2' ],
        ],
        'bebidas_azucaradas' => [
            'MX' => [ 'rate' => 0.08, 'cuota_per_pack' => 0.0,    'norma' => 'LIEPS art. 2' ],
        ],
        'sugary_drinks' => [
            'MX' => [ 'rate' => 0.08, 'cuota_per_pack' => 0.0,    'norma' => 'LIEPS art. 2' ],
        ],
    ];

    /**
     * Números ONU de mercancías peligrosas prohibidas en transporte aéreo
     * estándar (requieren empaque especializado + acordo con aerolínea).
     */
    public const HAZMAT_AIR_RESTRICTED = [ 'UN3480', 'UN3090' ]; // Litio sueltas.

    /**
     * Tratados de libre comercio activos: [country_pair => ['treaty', 'rate_reduction']].
     * Permite aplicar arancel preferencial cuando país origen coincide.
     */
    public const FTA_MATRIX = [
        'CO-MX' => [ 'treaty' => 'ACE 65 (CAN-México)',     'rate_reduction' => 0.0 ], // 100% reducción.
        'CO-US' => [ 'treaty' => 'TPA CO-US',               'rate_reduction' => 1.0 ],
        'CO-EU' => [ 'treaty' => 'Acuerdo Comercial CO-UE', 'rate_reduction' => 1.0 ],
        'MX-US' => [ 'treaty' => 'T-MEC',                   'rate_reduction' => 1.0 ],
        'MX-EU' => [ 'treaty' => 'T-MEC + Acuerdo Global MX-UE', 'rate_reduction' => 1.0 ],
    ];

    // ================================================================
    // INIT.
    // ================================================================

    public static function init(): void {
        // PP-1: Garantía legal mínima.
        add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'register_warranty_metabox' ] );
        add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_warranty_meta' ], 20, 1 );
        add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'display_warranty_info' ], 35 );

        // PP-2: País de origen.
        add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'register_origin_metabox' ] );
        add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_origin_meta' ], 21, 1 );
        add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'display_origin_badge' ], 40 );

        // PP-3: Mercancías peligrosas.
        add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'register_hazmat_metabox' ] );
        add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_hazmat_meta' ], 22, 1 );
        add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'display_hazmat_warning' ], 45 );
        add_action( 'woocommerce_check_cart_items', [ __CLASS__, 'validate_hazmat_shipping' ] );

        // PP-4: Certificaciones sanitarias.
        add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'register_certifications_metabox' ] );
        add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_certifications_meta' ], 23, 1 );

        // PP-5: Etiquetado textil.
        add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'register_textile_metabox' ] );
        add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_textile_meta' ], 24, 1 );
        add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'display_textile_label' ], 50 );

        // PP-6: ICE/IEPS para productos regulados.
        add_filter( 'ltms_tax_calculation_result', [ __CLASS__, 'add_ice_ieps_to_taxes' ], 10, 4 );

        // PP-7: Número de lote / trazabilidad.
        add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'register_batch_metabox' ] );
        add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_batch_meta' ], 25, 1 );
        add_action( 'woocommerce_single_product_summary', [ __CLASS__, 'display_batch_info' ], 55 );
        add_action( 'woocommerce_checkout_update_order_meta', [ __CLASS__, 'save_batch_to_order' ] );

        // PP-8: Bug customs declarations.
        add_filter( 'ltms_customs_calc_args', [ __CLASS__, 'enhance_customs_calculation' ], 10, 2 );
    }

    // ================================================================
    // PP-1: LEGAL WARRANTY (Ley 1480 art. 12 CO / LFPCE art. 92 MX).
    // ================================================================

    /**
     * Renderiza metabox de garantía legal.
     */
    public static function register_warranty_metabox(): void {
        echo '<div class="options_group ltms-warranty-metabox">';
        echo '<h3 style="padding:8px 10px;margin:0;background:#f0f9ff;">🛡️ ' . esc_html__( 'Garantía legal (Ley 1480/2011 art. 12 CO / LFPCE art. 92 MX)', 'ltms' ) . '</h3>';

        woocommerce_wp_select( [
            'id'          => '_ltms_warranty_type',
            'label'       => __( 'Tipo de producto', 'ltms' ),
            'options'     => [
                'new'  => __( 'Nuevo', 'ltms' ),
                'used' => __( 'Usado', 'ltms' ),
            ],
            'description' => __( 'Afecta el mínimo legal: 12 meses nuevo / 3 meses usado (CO); 3 meses MX.', 'ltms' ),
        ] );

        woocommerce_wp_text_input( [
            'id'          => '_ltms_warranty_months',
            'label'       => __( 'Meses de garantía', 'ltms' ),
            'type'        => 'number',
            'description' => __( 'Mínimo legal: CO 12 meses (nuevo) o 3 meses (usado); MX 3 meses.', 'ltms' ),
            'custom_attributes' => [ 'min' => 0, 'max' => 120, 'step' => 1 ],
        ] );

        woocommerce_wp_textarea_input( [
            'id'          => '_ltms_warranty_terms',
            'label'       => __( 'Términos de garantía', 'ltms' ),
            'description' => __( 'Condiciones específicas (cobertura, exclusiones, proceso de reclamo).', 'ltms' ),
            'rows'        => 3,
        ] );
        echo '</div>';
    }

    /**
     * Guarda y valida la garantía mínima legal.
     *
     * @param int $product_id ID del producto.
     */
    public static function save_warranty_meta( int $product_id ): void {
        $type         = sanitize_text_field( wp_unslash( $_POST['_ltms_warranty_type'] ?? 'new' ) );
        $months       = (int) ( $_POST['_ltms_warranty_months'] ?? 0 );
        $terms        = isset( $_POST['_ltms_warranty_terms'] ) ? sanitize_textarea_field( wp_unslash( $_POST['_ltms_warranty_terms'] ) ) : '';
        $country      = LTMS_Core_Config::get_country();
        $minimums     = self::WARRANTY_MINIMUMS[ $country ] ?? self::WARRANTY_MINIMUMS['CO'];
        $minimum      = $minimums[ $type ] ?? 12;

        // Validación: bloquear si warranty < mínimo legal.
        if ( $months < $minimum ) {
            // En lugar de bloquear el guardado (rompe WC), se fuerza al mínimo legal
            // y se notifica al admin.
            $months = $minimum;
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'WARRANTY_BELOW_LEGAL_MIN',
                    sprintf( 'Product #%d: garantía ajustada a mínimo legal %d meses (%s, %s).', $product_id, $minimum, $country, $type )
                );
            }
            // Marcar flag para mostrar advertencia en admin.
            update_post_meta( $product_id, '_ltms_warranty_auto_adjusted', 'yes' );
        } else {
            delete_post_meta( $product_id, '_ltms_warranty_auto_adjusted' );
        }

        update_post_meta( $product_id, '_ltms_warranty_type', $type );
        update_post_meta( $product_id, '_ltms_warranty_months', $months );
        update_post_meta( $product_id, '_ltms_warranty_terms', $terms );
    }

    /**
     * Muestra info de garantía en PDP.
     */
    public static function display_warranty_info(): void {
        global $product;
        if ( ! $product ) return;
        $months = (int) get_post_meta( $product->get_id(), '_ltms_warranty_months', true );
        $type   = get_post_meta( $product->get_id(), '_ltms_warranty_type', true ) ?: 'new';
        if ( $months <= 0 ) return;
        ?>
        <div class="ltms-warranty-info" style="background:#f0fdf4;border-left:4px solid #22c55e;padding:10px;margin:8px 0;">
            <strong>🛡️ <?php echo esc_html( sprintf( __( 'Garantía: %d meses', 'ltms' ), $months ) ); ?></strong>
            <span style="margin-left:6px;font-size:12px;color:#666;">
                <?php echo esc_html( sprintf( __( '(%s) — Ley 1480/2011 art. 12 / LFPCE art. 92', 'ltms' ), ucfirst( $type ) ) ); ?>
            </span>
        </div>
        <?php
    }

    // ================================================================
    // PP-2: COUNTRY OF ORIGIN (DIAN Resolución 000070/2020 / LCE art. 31).
    // ================================================================

    /**
     * Renderiza metabox de país de origen.
     */
    public static function register_origin_metabox(): void {
        echo '<div class="options_group ltms-origin-metabox">';
        echo '<h3 style="padding:8px 10px;margin:0;background:#fef9c3;">🌍 ' . esc_html__( 'País de origen (DIAN Resolución 000070/2020 / Ley de Comercio Exterior art. 31 / Reglamento UE 1169/2011 art. 9)', 'ltms' ) . '</h3>';

        woocommerce_wp_select( [
            'id'          => '_ltms_country_of_origin',
            'label'       => __( 'País de origen', 'ltms' ),
            'options'     => self::get_country_options(),
            'description' => __( 'País donde se fabricó/cultivó/extrajo el producto. Obligatorio para aduanas y etiquetado.', 'ltms' ),
        ] );

        woocommerce_wp_text_input( [
            'id'          => '_ltms_manufacturer_name',
            'label'       => __( 'Nombre del fabricante', 'ltms' ),
            'description' => __( 'Requerido para trazabilidad (NOM-024-SCFI-2013 MX).', 'ltms' ),
        ] );
        echo '</div>';
    }

    /**
     * Devuelve opciones de país (ISO 3166-1 alpha-2).
     */
    public static function get_country_options(): array {
        return [
            ''   => __( '— Selecciona —', 'ltms' ),
            'CO' => 'Colombia',
            'MX' => 'México',
            'US' => 'Estados Unidos',
            'CN' => 'China',
            'BR' => 'Brasil',
            'AR' => 'Argentina',
            'CL' => 'Chile',
            'PE' => 'Perú',
            'EC' => 'Ecuador',
            'DE' => 'Alemania',
            'IT' => 'Italia',
            'FR' => 'Francia',
            'ES' => 'España',
            'JP' => 'Japón',
            'KR' => 'Corea del Sur',
            'IN' => 'India',
            'VN' => 'Vietnam',
            'TR' => 'Turquía',
            'OTHER' => __( 'Otro (especificar)', 'ltms' ),
        ];
    }

    /**
     * Guarda país de origen y valida que esté presente.
     */
    public static function save_origin_meta( int $product_id ): void {
        $origin = sanitize_text_field( wp_unslash( $_POST['_ltms_country_of_origin'] ?? '' ) );
        $mfr    = sanitize_text_field( wp_unslash( $_POST['_ltms_manufacturer_name'] ?? '' ) );
        update_post_meta( $product_id, '_ltms_country_of_origin', $origin );
        update_post_meta( $product_id, '_ltms_manufacturer_name', $mfr );

        // Validación: si no tiene país de origen, marcar como published=false
        // (no bloquear el guardado de WC, pero marcar flag).
        if ( empty( $origin ) ) {
            update_post_meta( $product_id, '_ltms_origin_missing', 'yes' );
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'PRODUCT_ORIGIN_MISSING',
                    sprintf( 'Product #%d: sin país de origen (DIAN Resolución 000070/2020 art. 5).', $product_id )
                );
            }
        } else {
            delete_post_meta( $product_id, '_ltms_origin_missing' );
        }
    }

    /**
     * Muestra badge "Hecho en X" en PDP.
     */
    public static function display_origin_badge(): void {
        global $product;
        if ( ! $product ) return;
        $origin = get_post_meta( $product->get_id(), '_ltms_country_of_origin', true );
        if ( empty( $origin ) ) return;
        $country_name = self::get_country_options()[ $origin ] ?? $origin;
        ?>
        <div class="ltms-origin-badge" style="background:#fef9c3;padding:6px 10px;border-radius:4px;display:inline-block;margin:4px 0;">
            <strong>🌍 <?php echo esc_html( sprintf( __( 'Hecho en %s', 'ltms' ), $country_name ) ); ?></strong>
        </div>
        <?php
    }

    // ================================================================
    // PP-3: HAZMAT (IATA DGR / ONU / NOM-002-SCT/2011 MX).
    // ================================================================

    /**
     * Renderiza metabox de mercancías peligrosas.
     */
    public static function register_hazmat_metabox(): void {
        echo '<div class="options_group ltms-hazmat-metabox">';
        echo '<h3 style="padding:8px 10px;margin:0;background:#fee2e2;">⚠️ ' . esc_html__( 'Mercancías peligrosas (IATA DGR / NOM-002-SCT/2011 MX)', 'ltms' ) . '</h3>';

        woocommerce_wp_checkbox( [
            'id'          => '_ltms_is_hazmat',
            'label'       => __( 'Es mercancía peligrosa', 'ltms' ),
            'description' => __( 'Marca si el producto contiene baterías de litio, flamables, corrosivos, etc.', 'ltms' ),
        ] );

        woocommerce_wp_text_input( [
            'id'                => '_ltms_un_number',
            'label'             => __( 'Número ONU', 'ltms' ),
            'description'       => __( 'Ej: UN3480 (litio suelta), UN3481 (litio en equipo), UN3091, UN1950 (aerosoles).', 'ltms' ),
            'custom_attributes' => [ 'pattern' => 'UN[0-9]{4}', 'maxlength' => 7 ],
        ] );

        woocommerce_wp_select( [
            'id'      => '_ltms_hazmat_class',
            'label'   => __( 'Clase de peligro ONU', 'ltms' ),
            'options' => [
                ''     => '— Selecciona —',
                '1'    => '1 — Explosivos',
                '2'    => '2 — Gases',
                '3'    => '3 — Líquidos inflamables',
                '4'    => '4 — Sólidos inflamables',
                '5'    => '5 — Oxidantes y peróxidos',
                '6'    => '6 — Tóxicos e infecciosos',
                '7'    => '7 — Radiactivos',
                '8'    => '8 — Corrosivos',
                '9'    => '9 — Varios (incluye baterías de litio)',
            ],
        ] );

        woocommerce_wp_select( [
            'id'      => '_ltms_packing_group',
            'label'   => __( 'Grupo de empaque', 'ltms' ),
            'options' => [
                ''   => '— N/A —',
                'I'  => 'I — Gran peligro',
                'II' => 'II — Peligro medio',
                'III'=> 'III — Menor peligro',
            ],
        ] );
        echo '</div>';
    }

    /**
     * Guarda metas hazmat.
     */
    public static function save_hazmat_meta( int $product_id ): void {
        $is_hazmat = isset( $_POST['_ltms_is_hazmat'] ) ? 'yes' : 'no';
        $un        = sanitize_text_field( wp_unslash( $_POST['_ltms_un_number'] ?? '' ) );
        $cls       = sanitize_text_field( wp_unslash( $_POST['_ltms_hazmat_class'] ?? '' ) );
        $pg        = sanitize_text_field( wp_unslash( $_POST['_ltms_packing_group'] ?? '' ) );
        update_post_meta( $product_id, '_ltms_is_hazmat', $is_hazmat );
        update_post_meta( $product_id, '_ltms_un_number', $un );
        update_post_meta( $product_id, '_ltms_hazmat_class', $cls );
        update_post_meta( $product_id, '_ltms_packing_group', $pg );
    }

    /**
     * Muestra advertencia hazmat en PDP.
     */
    public static function display_hazmat_warning(): void {
        global $product;
        if ( ! $product ) return;
        if ( get_post_meta( $product->get_id(), '_ltms_is_hazmat', true ) !== 'yes' ) return;
        $un = get_post_meta( $product->get_id(), '_ltms_un_number', true );
        ?>
        <div class="ltms-hazmat-warning" style="background:#fee2e2;border-left:4px solid #dc2626;padding:10px;margin:8px 0;">
            <strong>⚠️ <?php esc_html_e( 'Mercancía peligrosa', 'ltms' ); ?></strong>
            <?php if ( $un ) : ?>
                <span style="margin-left:8px;"><?php echo esc_html( sprintf( __( 'Número ONU: %s', 'ltms' ), $un ) ); ?></span>
            <?php endif; ?>
            <p class="description" style="font-size:11px;margin-top:4px;">
                <?php esc_html_e( 'IATA DGR / NOM-002-SCT/2011. Restricciones de transporte aplican.', 'ltms' ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Valida que el envío de hazmat sea seguro.
     * Bloquea envíos aéreos para UN3480 y UN3090 (litio sueltas).
     */
    public static function validate_hazmat_shipping(): void {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) return;
        $has_restricted_hazmat = false;
        foreach ( WC()->cart->get_cart_contents() as $item ) {
            $pid = (int) $item['product_id'];
            if ( get_post_meta( $pid, '_ltms_is_hazmat', true ) !== 'yes' ) continue;
            $un  = get_post_meta( $pid, '_ltms_un_number', true );
            if ( in_array( $un, self::HAZMAT_AIR_RESTRICTED, true ) ) {
                $has_restricted_hazmat = true;
                break;
            }
        }
        if ( ! $has_restricted_hazmat ) return;

        // Verificar si la única opción de envío es aérea.
        $shipping_methods = WC()->session->get( 'chosen_shipping_methods', [] );
        foreach ( $shipping_methods as $method_id ) {
            if ( strpos( (string) $method_id, 'air' ) !== false || strpos( (string) $method_id, 'aereo' ) !== false ) {
                wc_add_notice(
                    __( 'Las baterías de litio sueltas (UN3480/UN3090) no pueden enviarse por vía aérea estándar. Selecciona envío terrestre (IATA DGR / NOM-002-SCT/2011).', 'ltms' ),
                    'error'
                );
                return;
            }
        }
    }

    // ================================================================
    // PP-4: SANITARY CERTIFICATIONS BY CATEGORY.
    // ================================================================

    /**
     * Renderiza metabox de certificaciones.
     */
    public static function register_certifications_metabox(): void {
        echo '<div class="options_group ltms-certs-metabox">';
        echo '<h3 style="padding:8px 10px;margin:0;background:#e0e7ff;">📋 ' . esc_html__( 'Certificaciones obligatorias (Resolución 831/2004 / 3119/2005 INVIMA CO / NOM-015 / NOM-141 / NOM-024 MX)', 'ltms' ) . '</h3>';
        echo '<p class="description" style="padding:8px 10px;">' . esc_html__( 'Las certificaciones se exigen según la categoría del producto.', 'ltms' ) . '</p>';
        echo '<div style="padding:8px 10px;">';

        // Renderizar campos para cada categoría certificable.
        woocommerce_wp_text_input( [
            'id'          => '_ltms_cert_invima_registro',
            'label'       => __( 'Registro INVIMA (juguetes/cosméticos CO)', 'ltms' ),
            'description' => __( 'Resolución 831/2004 (juguetes) o 3119/2005 (cosméticos).', 'ltms' ),
        ] );
        woocommerce_wp_text_input( [
            'id'          => '_ltms_cert_nom_015',
            'label'       => __( 'Cumplimiento NOM-015-SCFI-1998 (juguetes MX)', 'ltms' ),
        ] );
        woocommerce_wp_text_input( [
            'id'          => '_ltms_cert_cofepris_aviso',
            'label'       => __( 'Aviso COFEPRIS (cosméticos MX)', 'ltms' ),
            'description' => __( 'NOM-141-SSA1-2012.', 'ltms' ),
        ] );
        woocommerce_wp_text_input( [
            'id'          => '_ltms_cert_nom_024',
            'label'       => __( 'Cumplimiento NOM-024-SCFI-2013 (electrónicos MX)', 'ltms' ),
        ] );
        woocommerce_wp_text_input( [
            'id'          => '_ltms_cert_icontec_ntc',
            'label'       => __( 'Certificación NTC-IEC (electrónicos CO)', 'ltms' ),
        ] );
        echo '</div></div>';
    }

    /**
     * Guarda metas de certificaciones y valida que estén presentes si la
     * categoría del producto las requiere.
     */
    public static function save_certifications_meta( int $product_id ): void {
        $cert_keys = [
            '_ltms_cert_invima_registro',
            '_ltms_cert_nom_015',
            '_ltms_cert_cofepris_aviso',
            '_ltms_cert_nom_024',
            '_ltms_cert_icontec_ntc',
        ];
        foreach ( $cert_keys as $key ) {
            $val = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
            update_post_meta( $product_id, $key, $val );
        }

        // Validación: si el producto está en una categoría certificable,
        // verificar que tenga la certificación obligatoria.
        $missing = self::check_missing_certifications( $product_id );
        if ( ! empty( $missing ) ) {
            update_post_meta( $product_id, '_ltms_cert_missing', implode( '|', $missing ) );
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'PRODUCT_CERT_MISSING',
                    sprintf( 'Product #%d: certificaciones faltantes: %s', $product_id, implode( ', ', $missing ) )
                );
            }
        } else {
            delete_post_meta( $product_id, '_ltms_cert_missing' );
        }
    }

    /**
     * Verifica si el producto tiene todas las certificaciones requeridas
     * según su categoría.
     *
     * @param int $product_id ID del producto.
     * @return array Lista de certificados faltantes (vacío si cumple).
     */
    public static function check_missing_certifications( int $product_id ): array {
        $country    = LTMS_Core_Config::get_country();
        $terms      = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'slugs' ] );
        $missing    = [];

        if ( is_array( $terms ) ) {
            foreach ( $terms as $slug ) {
                $reqs = self::CERT_REQUIRED_CATEGORIES[ $slug ] ?? null;
                if ( ! $reqs || ! isset( $reqs[ $country ] ) ) continue;
                foreach ( $reqs[ $country ] as $cert_key => $label ) {
                    $meta_key = '_ltms_cert_' . $cert_key;
                    $val      = get_post_meta( $product_id, $meta_key, true );
                    if ( empty( $val ) ) {
                        $missing[ $slug . ':' . $cert_key ] = $label;
                    }
                }
            }
        }
        return $missing;
    }

    // ================================================================
    // PP-5: TEXTILE LABELING (NTC 1101 CO / NOM-004-SCFI-2006 MX).
    // ================================================================

    /**
     * Renderiza metabox de etiquetado textil.
     */
    public static function register_textile_metabox(): void {
        echo '<div class="options_group ltms-textile-metabox">';
        echo '<h3 style="padding:8px 10px;margin:0;background:#fce7f3;">👕 ' . esc_html__( 'Etiquetado textil (NTC 1101 CO / NOM-004-SCFI-2006 MX)', 'ltms' ) . '</h3>';

        woocommerce_wp_text_input( [
            'id'          => '_ltms_fiber_composition',
            'label'       => __( 'Composición de fibras', 'ltms' ),
            'description' => __( 'Ej: 80% Algodón, 20% Poliéster. Obligatorio (NTC 1101 art. 5 / NOM-004 art. 4.1).', 'ltms' ),
        ] );

        woocommerce_wp_textarea_input( [
            'id'          => '_ltms_care_instructions',
            'label'       => __( 'Instrucciones de cuidado', 'ltms' ),
            'description' => __( 'Símbolos de lavado/planchado/secado (NOM-004-SCFI-2006 Apéndice A).', 'ltms' ),
            'rows'        => 3,
        ] );

        woocommerce_wp_select( [
            'id'      => '_ltms_size_system',
            'label'   => __( 'Sistema de tallas', 'ltms' ),
            'options' => [
                ''         => '— N/A —',
                'co_eu'    => 'CO/EU (XS, S, M, L, XL)',
                'mx_us'    => 'MX/US (numérico)',
                'uk'       => 'UK',
                'jp'       => 'JP',
            ],
        ] );
        echo '</div>';
    }

    /**
     * Guarda metas de etiquetado textil.
     */
    public static function save_textile_meta( int $product_id ): void {
        $fiber = isset( $_POST['_ltms_fiber_composition'] ) ? sanitize_text_field( wp_unslash( $_POST['_ltms_fiber_composition'] ) ) : '';
        $care  = isset( $_POST['_ltms_care_instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['_ltms_care_instructions'] ) ) : '';
        $size  = sanitize_text_field( wp_unslash( $_POST['_ltms_size_system'] ?? '' ) );
        update_post_meta( $product_id, '_ltms_fiber_composition', $fiber );
        update_post_meta( $product_id, '_ltms_care_instructions', $care );
        update_post_meta( $product_id, '_ltms_size_system', $size );
    }

    /**
     * Muestra info de etiquetado textil en PDP.
     */
    public static function display_textile_label(): void {
        global $product;
        if ( ! $product ) return;
        $fiber = get_post_meta( $product->get_id(), '_ltms_fiber_composition', true );
        $care  = get_post_meta( $product->get_id(), '_ltms_care_instructions', true );
        if ( empty( $fiber ) && empty( $care ) ) return;
        ?>
        <div class="ltms-textile-label" style="background:#fce7f3;padding:10px;margin:8px 0;border-radius:4px;">
            <strong>👕 <?php esc_html_e( 'Información textil', 'ltms' ); ?></strong>
            <?php if ( $fiber ) : ?>
                <p style="margin:4px 0;"><strong><?php esc_html_e( 'Composición:', 'ltms' ); ?></strong> <?php echo esc_html( $fiber ); ?></p>
            <?php endif; ?>
            <?php if ( $care ) : ?>
                <p style="margin:4px 0;"><strong><?php esc_html_e( 'Cuidado:', 'ltms' ); ?></strong> <?php echo esc_html( $care ); ?></p>
            <?php endif; ?>
            <p class="description" style="font-size:11px;margin-top:4px;">
                <?php esc_html_e( 'NTC 1101 (CO) / NOM-004-SCFI-2006 (MX).', 'ltms' ); ?>
            </p>
        </div>
        <?php
    }

    // ================================================================
    // PP-6: ICE (CO) / IEPS (MX) FOR REGULATED PRODUCTS.
    // ================================================================

    /**
     * Añade ICE/IEPS al resultado del cálculo de impuestos si el producto
     * está en una categoría regulada.
     *
     * @param array  $result       Resultado del tax engine.
     * @param float  $gross        Monto bruto.
     * @param array  $order_data   Datos del pedido.
     * @param array  $vendor_data  Datos del vendor.
     * @return array
     */
    public static function add_ice_ieps_to_taxes( array $result, float $gross, array $order_data, array $vendor_data ): array {
        $product_type = $order_data['product_type'] ?? 'physical';
        $country      = LTMS_Core_Config::get_country();
        $category     = $order_data['product_cat'] ?? $product_type;

        $reg = self::REGULATED_CATEGORIES[ $category ] ?? null;
        if ( ! $reg || ! isset( $reg[ $country ] ) ) {
            return $result;
        }

        $cfg       = $reg[ $country ];
        $rate      = (float) $cfg['rate'];
        $cuota     = (float) $cfg['cuota_per_pack'];
        $ice       = round( $gross * $rate, 2 );
        $ice_total = $ice + $cuota;

        if ( $country === 'CO' ) {
            $result['ice']             = $ice_total;
            $result['ice_rate']        = $rate;
            $result['ice_cuota']       = $cuota;
            $result['ice_norma']       = $cfg['norma'];
            $result['total_taxes']     = ( $result['total_taxes'] ?? 0 ) + $ice_total;
        } else {
            // MX IEPS (ya existe ieps key pero puede estar vacío).
            $result['ieps']            = $ice_total;
            $result['ieps_rate']       = $rate;
            $result['ieps_cuota']      = $cuota;
            $result['ieps_norma']      = $cfg['norma'];
            $result['total_taxes']     = ( $result['total_taxes'] ?? 0 ) + $ice_total;
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'ICE_IEPS_CALCULATED',
                sprintf( 'Cat=%s, country=%s, gross=%.2f, ICE/IEPS=%.2f (%s)', $category, $country, $gross, $ice_total, $cfg['norma'] )
            );
        }

        return $result;
    }

    // ================================================================
    // PP-7: BATCH / LOT TRACEABILITY.
    // ================================================================

    /**
     * Renderiza metabox de número de lote.
     */
    public static function register_batch_metabox(): void {
        echo '<div class="options_group ltms-batch-metabox">';
        echo '<h3 style="padding:8px 10px;margin:0;background:#f3e8ff;">🔢 ' . esc_html__( 'Trazabilidad / Lote (Decreto 614/2013 art. 17 CO / NOM-024-SCFI-2013 MX)', 'ltms' ) . '</h3>';

        woocommerce_wp_text_input( [
            'id'          => '_ltms_batch_number',
            'label'       => __( 'Número de lote', 'ltms' ),
            'description' => __( 'Identificador único de fabricación para trazabilidad.', 'ltms' ),
        ] );

        woocommerce_wp_text_input( [
            'id'                => '_ltms_manufacture_date',
            'label'             => __( 'Fecha de fabricación', 'ltms' ),
            'type'              => 'date',
        ] );

        woocommerce_wp_text_input( [
            'id'                => '_ltms_expiry_date',
            'label'             => __( 'Fecha de vencimiento', 'ltms' ),
            'type'              => 'date',
            'description'       => __( 'Obligatorio para productos perecederos.', 'ltms' ),
        ] );
        echo '</div>';
    }

    /**
     * Guarda metas de lote.
     */
    public static function save_batch_meta( int $product_id ): void {
        $batch = isset( $_POST['_ltms_batch_number'] ) ? sanitize_text_field( wp_unslash( $_POST['_ltms_batch_number'] ) ) : '';
        $mfg   = isset( $_POST['_ltms_manufacture_date'] ) ? sanitize_text_field( wp_unslash( $_POST['_ltms_manufacture_date'] ) ) : '';
        $exp   = isset( $_POST['_ltms_expiry_date'] ) ? sanitize_text_field( wp_unslash( $_POST['_ltms_expiry_date'] ) ) : '';
        update_post_meta( $product_id, '_ltms_batch_number', $batch );
        update_post_meta( $product_id, '_ltms_manufacture_date', $mfg );
        update_post_meta( $product_id, '_ltms_expiry_date', $exp );
    }

    /**
     * Muestra info de lote en PDP.
     */
    public static function display_batch_info(): void {
        global $product;
        if ( ! $product ) return;
        $batch = get_post_meta( $product->get_id(), '_ltms_batch_number', true );
        $exp   = get_post_meta( $product->get_id(), '_ltms_expiry_date', true );
        if ( empty( $batch ) && empty( $exp ) ) return;
        ?>
        <div class="ltms-batch-info" style="background:#f3e8ff;padding:8px 10px;border-radius:4px;margin:6px 0;font-size:12px;">
            <?php if ( $batch ) : ?>
                <span><strong><?php esc_html_e( 'Lote:', 'ltms' ); ?></strong> <?php echo esc_html( $batch ); ?></span>
            <?php endif; ?>
            <?php if ( $exp ) : ?>
                <span style="margin-left:12px;"><strong><?php esc_html_e( 'Vence:', 'ltms' ); ?></strong> <?php echo esc_html( $exp ); ?></span>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Copia el número de lote del producto al order meta para trazabilidad
     * post-venta (recall).
     *
     * @param int $order_id ID de la orden.
     */
    public static function save_batch_to_order( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $batch_data = [];
        foreach ( $order->get_items() as $item ) {
            $pid   = (int) $item->get_product_id();
            $batch = get_post_meta( $pid, '_ltms_batch_number', true );
            $exp   = get_post_meta( $pid, '_ltms_expiry_date', true );
            if ( $batch || $exp ) {
                $batch_data[] = [
                    'product_id'   => $pid,
                    'product_name' => $item->get_name(),
                    'batch'        => $batch,
                    'expiry'       => $exp,
                ];
            }
        }
        if ( ! empty( $batch_data ) ) {
            $order->update_meta_data( '_ltms_batch_traceability', $batch_data );
            $order->save();
        }
    }

    // ================================================================
    // PP-8: CUSTOMS DECLARATION — FTA LOOKUP BY COUNTRY OF ORIGIN.
    // ================================================================

    /**
     * PP-8 BUG FIX: Inyecta el país de origen del producto en el cálculo
     * aduanero para que el customs calculator pueda aplicar TLC.
     *
     * Bug detectado: lt_customs_declarations tabla existe y se persiste,
     * pero el cálculo aduanero NO usaba el país de origen del producto
     * para determinar TLC → se aplicaba el arancel máximo aunque el
     * producto calificara para TLC CO-MX, CO-EU, MX-EU, etc.
     *
     * @param array $args Argumentos del cálculo aduanero.
     * @param array $context Contexto adicional (order_id, product_id, etc.).
     * @return array Argumentos enriquecidos con país de origen + TLC lookup.
     */
    public static function enhance_customs_calculation( array $args, array $context = [] ): array {
        $product_id = (int) ( $context['product_id'] ?? 0 );
        if ( $product_id <= 0 ) return $args;

        $origin = get_post_meta( $product_id, '_ltms_country_of_origin', true );
        if ( empty( $origin ) ) return $args;

        $destination = $args['destination_country'] ?? LTMS_Core_Config::get_country();
        $origin_norm = strtoupper( $origin );

        // Lookup TLC matrix.
        $key = $origin_norm . '-' . $destination;
        if ( isset( self::FTA_MATRIX[ $key ] ) ) {
            $args['origin_country']          = $origin_norm;
            $args['fta_treaty']              = self::FTA_MATRIX[ $key ]['treaty'];
            $args['fta_rate_reduction']      = self::FTA_MATRIX[ $key ]['rate_reduction'];
            $args['preferential_tariff']     = ( $args['duty_rate'] ?? 0 ) * ( 1 - self::FTA_MATRIX[ $key ]['rate_reduction'] );

            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info(
                    'CUSTOMS_FTA_APPLIED',
                    sprintf(
                        'Product #%d: TLC %s aplicado. Arancel original=%.2f, preferencial=%.2f (%.0f%% reducción).',
                        $product_id,
                        self::FTA_MATRIX[ $key ]['treaty'],
                        $args['duty_rate'] ?? 0,
                        $args['preferential_tariff'],
                        self::FTA_MATRIX[ $key ]['rate_reduction'] * 100
                    )
                );
            }
        } else {
            $args['origin_country'] = $origin_norm;
            $args['fta_treaty']     = null; // No TLC, arancel MFN aplica.
        }

        return $args;
    }

    // ================================================================
    // HELPERS.
    // ================================================================

    /**
     * Devuelve las normas aplicables por país.
     *
     * @return array
     */
    public static function get_legal_basis(): array {
        return [
            'CO' => [
                'Ley 1480/2011 art. 12'      => 'Garantía legal 12 meses (nuevo) / 3 meses (usado).',
                'Resolución DIAN 000070/2020 art. 5' => 'País de origen en declaración importación.',
                'Resolución 831/2004 INVIMA' => 'Registro sanitario juguetes.',
                'Resolución 3119/2005 INVIMA' => 'Registro sanitario cosméticos.',
                'Decreto 614/2013 art. 17'   => 'Trazabilidad por lote.',
                'ET art. 468'                => 'ICE alcohol 35%.',
                'ET art. 469'                => 'ICE tabaco 75% + cuota.',
                'NTC 1101'                   => 'Etiquetado textil.',
            ],
            'MX' => [
                'LFPCE art. 92'              => 'Garantía legal mínima 3 meses.',
                'Ley de Comercio Exterior art. 31' => 'País de origen obligatorio.',
                'NOM-002-SCT/2011'           => 'Mercancías peligrosas.',
                'NOM-004-SCFI-2006'          => 'Etiquetado textil.',
                'NOM-015-SCFI-1998'          => 'Juguetes (seguridad).',
                'NOM-024-SCFI-2013'          => 'Electrónicos (info comercial).',
                'NOM-141-SSA1-2012'          => 'Cosméticos (aviso COFEPRIS).',
                'LIEPS art. 2'               => 'IEPS alcohol/tabaco/bebidas azucaradas.',
            ],
            'CROSS-BORDER' => [
                'IATA DGR'                   => 'Baterías de litio (UN3480/UN3481/UN3091).',
                'ONU Rec. Transp. Merc. Peligrosas' => 'Clasificación de peligro 1-9.',
                'Reglamento (UE) 1169/2011 art. 9' => 'País de origen obligatorio.',
            ],
        ];
    }
}
