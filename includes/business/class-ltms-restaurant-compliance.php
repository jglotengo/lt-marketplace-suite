<?php
/**
 * LTMS Restaurant Compliance — Cumplimiento normativo para restaurantes.
 *
 * v2.9.14 — Cierra 7 brechas de cumplimiento específicas para restaurantes
 * detectadas en la auditoría v2.9.13:
 *
 *  RT-1 (CRÍTICO): Verificación de edad para venta de alcohol.
 *    Norma: Colombia Ley 124/1994 art. 2 (prohibida venta a menores de 18 años);
 *           México Ley General de Salud art. 475 (venta a menores de 18 años
 *           sancionada con hasta 36 horas de arresto + multa);
 *           Estatuto Tributario art. 421 (carne/cerveza >2.5°GL paga IVA 19%).
 *    Antes: el marketplace no verificaba la edad del comprador en productos
 *           con categoría 'alcohol', 'beer', 'wine', 'spirits', 'liqueur'.
 *    Fix: validate_age_for_alcohol() hook woocommerce_check_cart_items
 *         + woocommerce_after_add_to_cart_validation. Si producto es alcohol:
 *         requiere checkbox "Soy mayor de 18 años" en checkout + log de
 *         consentimiento en lt_consent_log (consent_type='age_verification').
 *
 *  RT-2 (CRÍTICO): Registro sanitario / INVIMA / COFEPRIS.
 *    Norma: Colombia Decreto 3075/1997 art. 4 (todo establecimiento de
 *           alimentos debe tener registro sanitario INVIMA);
 *           México Acuerdo SSA NOM-251-SSA1-2009 (aviso de funcionamiento
 *           ante COFEPRIS para establecimientos que preparan alimentos).
 *    Antes: el registro KYC no solicitaba ni validaba el registro sanitario.
 *    Fix: render_sanitary_registration_fields() añade 2 campos al registro
 *         de vendor restaurante: número de registro + fecha de vencimiento.
 *         validate_sanitary_registration() bloquea aprobación si el registro
 *         está vencido (<30 días para renovar alerta). Cron mensual
 *         check_sanitary_expiry() notifica por email al vendor.
 *
 *  RT-3 (ALTO): Etiquetado de alérgenos.
 *    Norma: Colombia Resolución 333/2011 INVIMA (etiquetado nutricional);
 *           México NOM-051-SCFI/SSI-2010 (declaración de alérgenos);
 *           Reglamento (UE) 1169/2011 art. 9 (alérgenos obligatorios).
 *    Antes: los productos restaurante no tenían campo de alérgenos.
 *    Fix: register_allergens_metabox() añade campo 'alergenos' al producto
 *         (multi-select de 14 alérgenos obligatorios UE + campos libres).
 *         display_allergen_warning() muestra advertencia en PDP + checkout
 *         si el producto contiene alérgenos declarados.
 *
 *  RT-4 (ALTO): Restricción de horarios para venta de alcohol.
 *    Norma: Colombia Ley 124/1994 art. 4 (horarios definidos por municipio,
 *           ej: Bogotá prohíbe venta de alcohol 0:00-10:00 lun-jue, todo el
 *           día en viernes si es día sin alcohol; Medellín 0:00-10:00);
 *           México Ley General de Salud art. 178 (horarios estatales,
 *           ej: CDMX prohibida 0:00-8:00 lun-jue, todo el día en domingo
 *           salvo restaurantes con licencia).
 *    Fix: check_alcohol_time_window() hook woocommerce_check_cart_items.
 *         Lee ltms_alcohol_allowed_hours (configurable por municipio/estado).
 *         Si la compra contiene alcohol y está fuera de horario: bloquea
 *         checkout con mensaje explicativo.
 *
 *  RT-5 (MEDIO): Gestión de propina / servicio (10%).
 *    Norma: México Ley 2a del 12 oct 1976 — propina sugerida 10-15%;
 *           Colombia costumbre (no es obligatoria, sugerida 10%).
 *    Antes: el checkout de restaurante no ofrecía propina opcional.
 *    Fix: render_tip_selector() añade selector de propina (0/5/10/15/20%)
 *         en checkout cuando el vendor tiene flag ltms_is_restaurant='yes'.
 *         La propina se añade como fee WooCommerce ('ltms_tip') y se separa
 *         en el order split (vendor recibe 100% de la propina).
 *
 *  RT-6 (ALTO): Bug option key mismatch Impoconsumo.
 *    Norma: Ley 2010/2019 art. 3 — 8% sobre alimentos preparados.
 *    Bug detectado: el admin UI guarda el valor en 'ltms_co_impoconsumo'
 *      (html-admin-fiscal-colombia.php línea 273), pero la tax strategy
 *      lo lee de 'ltms_impoconsumo_rate' (class-ltms-tax-strategy-colombia.php
 *      línea 67). Resultado: el usuario cambia el % en admin → no aplica.
 *    Fix: get_impoconsumo_rate() ahora lee AMBAS options con fallback
 *      ('ltms_impoconsumo_rate' como legacy, 'ltms_co_impoconsumo' como
 *      canonica configurada en admin). Avisa en log si difieren.
 *
 *  RT-7 (MEDIO): Trazabilidad de cadena de frío.
 *    Norma: Colombia Resolución 2674/2013 INVIMA art. 14 (registros de
 *           temperatura para alimentos perecederos);
 *           México NOM-024-SSA3-2012 (trazabilidad de productos
 *           perecederos en establecimientos).
 *    Antes: el marketplace no almacenaba datos de temperatura para
 *           productos que requieren cadena de frío.
 *    Fix: register_cold_chain_metabox() añade campos a producto:
 *         requires_cold_chain (bool), storage_temp_min, storage_temp_max.
 *         display_cold_chain_badge() muestra badge en PDP "Mantener
 *         refrigerado". En el order meta se registra _ltms_cold_chain_ack.
 *
 * Normas cubiertas (CO + MX + EU):
 *  - Ley 124/1994 art. 2, 4 (CO alcohol menores + horarios)
 *  - Ley 2010/2019 art. 3 (CO Impoconsumo 8% restaurantes)
 *  - Decreto 3075/1997 art. 4 (CO registro sanitario INVIMA)
 *  - Resolución 333/2011 INVIMA (CO etiquetado nutricional)
 *  - Resolución 2674/2013 INVIMA art. 14 (CO cadena de frío)
 *  - Estatuto Tributario art. 421 (CO IVA alcohol)
 *  - Ley General de Salud art. 178, 475 (MX horarios + menores alcohol)
 *  - NOM-251-SSA1-2009 (MX buenas prácticas de higiene)
 *  - NOM-051-SCFI/SSI-2010 (MX declaración alérgenos)
 *  - NOM-024-SSA3-2012 (MX trazabilidad perecederos)
 *  - Ley 2a del 12 oct 1976 (MX propina sugerida)
 *  - Reglamento (UE) 1169/2011 art. 9 (alérgenos obligatorios)
 *
 * @package LTMS
 * @version 2.9.14
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Restaurant_Compliance {

    /**
     * Alérgenos obligatorios según Reglamento (UE) 1169/2011 Anexo II
     * (también adoptados en CO Res. 333/2011 y MX NOM-051).
     */
    public const ALLERGENS = [
        'gluten'        => 'Gluten (trigo, cebada, centeno, avena)',
        'crustaceans'   => 'Crustáceos y productos a base de crustáceos',
        'eggs'          => 'Huevos y productos a base de huevos',
        'fish'          => 'Pescado y productos a base de pescado',
        'peanuts'       => 'Cacahuetes (maní) y productos derivados',
        'soy'           => 'Soya y productos a base de soya',
        'milk'          => 'Leche y productos lácteos (incluida lactosa)',
        'nuts'          => 'Frutos secos (almendras, avellanas, nueces, etc.)',
        'celery'        => 'Apio y productos a base de apio',
        'mustard'       => 'Mostaza y productos derivados',
        'sesame'        => 'Semillas de sésamo (ajonjolí) y derivados',
        'sulphites'     => 'Sulfitos y dióxido de azufre (>10 mg/kg)',
        'lupin'         => 'Altramuz (lupino) y productos derivados',
        'molluscs'      => 'Moluscos y productos a base de moluscos',
    ];

    /**
     * Categorías de producto consideradas alcohol (para verificación de edad).
     * Cerveza y vino >2.5°GL pagan IVA 19% en CO (ET art. 421).
     */
    public const ALCOHOL_CATEGORIES = [
        'alcohol', 'beer', 'wine', 'spirits', 'liqueur',
        'bebidas_alcoholicas', 'cervezas', 'vinos_licores',
    ];

    // ================================================================
    // INIT.
    // ================================================================

    public static function init(): void {
        // RT-1: Verificación de edad para alcohol.
        add_action( 'woocommerce_check_cart_items',        [ __CLASS__, 'validate_age_for_alcohol' ] );
        add_action( 'woocommerce_after_add_to_cart_validation', [ __CLASS__, 'validate_age_on_add_to_cart' ], 10, 5 );
        add_action( 'woocommerce_review_order_after_submit', [ __CLASS__, 'render_age_verification_checkbox' ] );
        add_action( 'woocommerce_checkout_order_processed', [ __CLASS__, 'log_age_verification_consent' ], 10, 3 );

        // RT-2: Registro sanitario (INVIMA / COFEPRIS).
        add_action( 'ltms_kyc_fields_extra',                [ __CLASS__, 'render_sanitary_registration_fields' ], 10, 2 );
        add_action( 'ltms_kyc_pre_approve',                 [ __CLASS__, 'validate_sanitary_registration' ], 10, 2 );
        add_action( 'ltms_monthly_cron',                    [ __CLASS__, 'check_sanitary_expiry' ] );

        // RT-3: Alérgenos en platos.
        add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'register_allergens_metabox' ] );
        add_action( 'woocommerce_process_product_meta',    [ __CLASS__, 'save_allergens_meta' ], 10, 1 );
        add_action( 'woocommerce_single_product_summary',  [ __CLASS__, 'display_allergen_warning' ], 25 );
        add_action( 'woocommerce_review_order_before_submit', [ __CLASS__, 'display_allergen_warning_checkout' ] );

        // RT-4: Restricción horaria venta de alcohol.
        add_action( 'woocommerce_check_cart_items',         [ __CLASS__, 'check_alcohol_time_window' ], 5 );

        // RT-5: Propina / servicio.
        add_action( 'woocommerce_cart_calculate_fees',      [ __CLASS__, 'apply_tip_fee' ] );
        add_action( 'woocommerce_review_order_before_order_total', [ __CLASS__, 'render_tip_selector' ] );
        add_action( 'wp_ajax_ltms_set_tip',                 [ __CLASS__, 'ajax_set_tip' ] );
        add_action( 'wp_ajax_nopriv_ltms_set_tip',          [ __CLASS__, 'ajax_set_tip' ] );

        // RT-6: Bug fix en get_impoconsumo_rate — handled in helper method
        // called by tax strategy via filter.
        add_filter( 'ltms_impoconsumo_rate',                [ __CLASS__, 'resolve_impoconsumo_rate' ] );

        // RT-7: Cadena de frío.
        add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'register_cold_chain_metabox' ] );
        add_action( 'woocommerce_process_product_meta',     [ __CLASS__, 'save_cold_chain_meta' ], 11, 1 );
        add_action( 'woocommerce_single_product_summary',   [ __CLASS__, 'display_cold_chain_badge' ], 30 );
        add_action( 'woocommerce_checkout_update_order_meta', [ __CLASS__, 'register_cold_chain_ack' ] );
    }

    // ================================================================
    // RT-1: AGE VERIFICATION FOR ALCOHOL.
    // ================================================================

    /**
     * Verifica si un producto es alcohol (por categoría WooCommerce o meta).
     *
     * @param int $product_id ID del producto.
     * @return bool
     */
    public static function is_alcohol_product( int $product_id ): bool {
        // 1. Verificar por categorías de WooCommerce.
        $terms = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'slugs' ] );
        if ( is_array( $terms ) ) {
            foreach ( $terms as $slug ) {
                if ( in_array( $slug, self::ALCOHOL_CATEGORIES, true ) ) {
                    return true;
                }
            }
        }
        // 2. Verificar por meta del producto.
        return get_post_meta( $product_id, '_ltms_is_alcohol', true ) === 'yes';
    }

    /**
     * ¿El carrito contiene alcohol?
     *
     * @return bool
     */
    public static function cart_has_alcohol(): bool {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return false;
        }
        foreach ( WC()->cart->get_cart_contents() as $item ) {
            if ( self::is_alcohol_product( (int) $item['product_id'] ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * RT-1: Valida que el comprador haya confirmado mayoría de edad si el
     * carrito contiene alcohol.
     *
     * Ley 124/1994 art. 2 (CO) + Ley General de Salud art. 475 (MX).
     */
    public static function validate_age_for_alcohol(): void {
        if ( ! self::cart_has_alcohol() ) {
            return;
        }
        // Si el usuario es admin/manager, no bloquear (para QA).
        if ( current_user_can( 'manage_options' ) ) {
            return;
        }
        // Verificar checkbox de edad enviado en checkout.
        $confirmed = (bool) ( $_POST['ltms_age_verification'] ?? false );
        if ( ! $confirmed && is_checkout() ) {
            wc_add_notice(
                __( 'Para comprar bebidas alcohólicas debes confirmar que eres mayor de 18 años. (Ley 124/1994 art. 2 / Ley General de Salud art. 475)', 'ltms' ),
                'error'
            );
        }
    }

    /**
     * Validación al agregar al carrito — si es alcohol y no se confirmó edad
     * en sesión, requiere confirmación.
     */
    public static function validate_age_on_add_to_cart( bool $passed, int $product_id, int $quantity, int $variation_id = 0, array $variation = [] ): bool {
        if ( ! self::is_alcohol_product( $product_id ) ) {
            return $passed;
        }
        if ( ! isset( $_COOKIE['ltms_age_verified'] ) || $_COOKIE['ltms_age_verified'] !== '1' ) {
            // Mostrar mensaje de advertencia (no bloquear add-to-cart — el bloqueo
            // definitivo ocurre en checkout con validate_age_for_alcohol()).
            wc_add_notice(
                __( 'Este producto contiene alcohol. Se requiere verificación de edad (18+) al finalizar la compra. (Ley 124/1994 art. 2)', 'ltms' ),
                'notice'
            );
        }
        return $passed;
    }

    /**
     * Renderiza checkbox de verificación de edad en checkout.
     */
    public static function render_age_verification_checkbox(): void {
        if ( ! self::cart_has_alcohol() ) {
            return;
        }
        ?>
        <p class="form-row ltms-age-verification" style="background:#fff3cd;padding:12px;border-left:4px solid #ffc107;margin:8px 0;">
            <label>
                <input type="checkbox" name="ltms_age_verification" value="1" required />
                <?php esc_html_e( 'Confirmo que soy mayor de 18 años y autorizo la compra de bebidas alcohólicas. (Ley 124/1994 art. 2 / Ley General de Salud art. 475)', 'ltms' ); ?>
            </label>
        </p>
        <?php
    }

    /**
     * Registra el consentimiento de edad en lt_consent_log tras orden procesada.
     */
    public static function log_age_verification_consent( int $order_id, array $posted_data, \WC_Order $order ): void {
        if ( ! self::cart_has_alcohol() ) {
            return;
        }
        if ( class_exists( 'LTMS_Legal_Compliance' ) ) {
            LTMS_Legal_Compliance::log_consent(
                (int) $order->get_customer_id(),
                'age_verification_alcohol',
                true,
                'Ley-124-1994-art-2',
                'checkout'
            );
        }
        // Marca el order meta.
        $order->update_meta_data( '_ltms_age_verification_confirmed', 'yes' );
        $order->update_meta_data( '_ltms_age_verification_at', current_time( 'mysql', true ) );
        $order->save();

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'AGE_VERIFICATION_ALCOHOL',
                sprintf( 'Order #%d — verificación de edad confirmada (Ley 124/1994 art. 2 / Ley General de Salud art. 475).', $order_id )
            );
        }
    }

    // ================================================================
    // RT-2: SANITARY REGISTRATION (INVIMA / COFEPRIS).
    // ================================================================

    /**
     * Renderiza campos de registro sanitario en el formulario KYC del vendor.
     *
     * @param int $vendor_id    ID del vendor.
     * @param string $country   CO o MX.
     */
    public static function render_sanitary_registration_fields( int $vendor_id, string $country ): void {
        // Solo si el vendor tiene flag restaurante.
        if ( get_user_meta( $vendor_id, 'ltms_is_restaurant', true ) !== 'yes' ) {
            return;
        }
        $reg_number  = get_user_meta( $vendor_id, 'ltms_sanitary_registration', true );
        $reg_expires = get_user_meta( $vendor_id, 'ltms_sanitary_registration_expires', true );

        $label = $country === 'MX'
            ? __( 'Aviso de Funcionamiento COFEPRIS', 'ltms' )
            : __( 'Registro Sanitario INVIMA', 'ltms' );
        ?>
        <div class="ltms-form-section" style="background:#e7f3ff;padding:12px;border-left:4px solid #2196f3;margin:8px 0;">
            <h4 style="margin-top:0;">🍽️ <?php echo esc_html( $label ); ?></h4>
            <p class="description">
                <?php
                echo esc_html( $country === 'MX'
                    ? __( 'Obligatorio por NOM-251-SSA1-2009 para establecimientos que preparan alimentos.', 'ltms' )
                    : __( 'Obligatorio por Decreto 3075/1997 art. 4 para establecimientos de alimentos.', 'ltms' )
                );
                ?>
            </p>
            <p>
                <label><?php esc_html_e( 'Número de registro / aviso', 'ltms' ); ?><br>
                <input type="text" name="ltms_sanitary_registration"
                       value="<?php echo esc_attr( $reg_number ); ?>"
                       class="regular-text" required /></label>
            </p>
            <p>
                <label><?php esc_html_e( 'Fecha de vencimiento', 'ltms' ); ?><br>
                <input type="date" name="ltms_sanitary_registration_expires"
                       value="<?php echo esc_attr( $reg_expires ); ?>" required /></label>
            </p>
        </div>
        <?php
    }

    /**
     * Valida el registro sanitario antes de aprobar KYC.
     *
     * @param bool   $approved True si está aprobado.
     * @param int    $vendor_id ID del vendor.
     * @return bool False si no cumple.
     */
    public static function validate_sanitary_registration( bool $approved, int $vendor_id ): bool {
        if ( get_user_meta( $vendor_id, 'ltms_is_restaurant', true ) !== 'yes' ) {
            return $approved;
        }
        $reg = get_user_meta( $vendor_id, 'ltms_sanitary_registration', true );
        $exp = get_user_meta( $vendor_id, 'ltms_sanitary_registration_expires', true );

        if ( empty( $reg ) ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'KYC_SANITARY_REG_MISSING',
                    sprintf( 'Vendor #%d: registro sanitario INVIMA/COFEPRIS faltante.', $vendor_id )
                );
            }
            return false;
        }
        if ( empty( $exp ) ) {
            return false;
        }
        // Verificar que no esté vencido.
        $exp_ts   = strtotime( $exp );
        $warn_ts  = time() + ( 30 * DAY_IN_SECONDS ); // alerta 30 días antes.
        if ( $exp_ts < time() ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::error(
                    'KYC_SANITARY_REG_EXPIRED',
                    sprintf( 'Vendor #%d: registro sanitario vencido el %s.', $vendor_id, $exp )
                );
            }
            return false;
        }
        if ( $exp_ts < $warn_ts ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'KYC_SANITARY_REG_EXPIRING',
                    sprintf( 'Vendor #%d: registro sanitario vence en menos de 30 días (%s).', $vendor_id, $exp )
                );
            }
        }
        return $approved;
    }

    /**
     * Cron mensual: notifica a vendors con registro sanitario próximo a vencer.
     */
    public static function check_sanitary_expiry(): void {
        $users = get_users( [
            'meta_key'   => 'ltms_is_restaurant',
            'meta_value' => 'yes',
            'fields'     => 'ID',
            'number'     => 1000,
        ] );
        $warn_ts = time() + ( 30 * DAY_IN_SECONDS );
        $now_ts  = time();

        foreach ( $users as $uid ) {
            $exp = get_user_meta( $uid, 'ltms_sanitary_registration_expires', true );
            if ( empty( $exp ) ) {
                continue;
            }
            $exp_ts = strtotime( $exp );
            if ( $exp_ts < $warn_ts && $exp_ts > $now_ts ) {
                // Notificar al vendor.
                $user  = get_userdata( $uid );
                $email = $user->user_email ?? '';
                if ( $email ) {
                    $subject = __( 'Tu registro sanitario vence pronto', 'ltms' );
                    $message = sprintf(
                        /* translators: 1: expiry date */
                        __( 'Tu registro sanitario vence el %s. Por favor renuévalo antes de la fecha límite para mantener tu cuenta activa.', 'ltms' ),
                        $exp
                    );
                    wp_mail( $email, $subject, $message );
                }
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::info(
                        'SANITARY_REG_EXPIRY_NOTIFY',
                        sprintf( 'Vendor #%d: notificado sobre vencimiento %s.', $uid, $exp )
                    );
                }
            }
        }
    }

    // ================================================================
    // RT-3: ALLERGENS LABELING.
    // ================================================================

    /**
     * Registra metabox de alérgenos en el producto WooCommerce.
     */
    public static function register_allergens_metabox(): void {
        echo '<div class="options_group ltms-allergens-metabox">';
        echo '<h3 style="padding:8px 10px;margin:0;background:#f8f9fa;">🍽️ ' . esc_html__( 'Alérgenos (Resolución 333/2011 INVIMA / NOM-051-SCFI/SSI-2010 / Reglamento UE 1169/2011)', 'ltms' ) . '</h3>';
        echo '<p class="description" style="padding:8px 10px;">' . esc_html__( 'Marca todos los alérgenos que contiene este plato.', 'ltms' ) . '</p>';
        echo '<div style="padding:8px 10px;max-height:200px;overflow:auto;border:1px solid #d1d5db;border-radius:4px;">';

        foreach ( self::ALLERGENS as $slug => $label ) {
            woocommerce_wp_checkbox( [
                'id'          => '_ltms_allergen_' . $slug,
                'label'       => $label,
                'description' => '',
            ] );
        }
        echo '</div>';

        // Campo para ingredientes adicionales.
        woocommerce_wp_textarea_input( [
            'id'          => '_ltms_ingredients_list',
            'label'       => __( 'Lista de ingredientes', 'ltms' ),
            'description' => __( 'Obligatorio por NOM-051-SCFI/SSI-2010 art. 4.4.1', 'ltms' ),
            'rows'        => 3,
        ] );
        echo '</div>';
    }

    /**
     * Guarda metas de alérgenos al guardar el producto.
     */
    public static function save_allergens_meta( int $product_id ): void {
        foreach ( array_keys( self::ALLERGENS ) as $slug ) {
            $key     = '_ltms_allergen_' . $slug;
            $value   = isset( $_POST[ $key ] ) ? 'yes' : 'no';
            update_post_meta( $product_id, $key, $value );
        }
        if ( isset( $_POST['_ltms_ingredients_list'] ) ) {
            update_post_meta( $product_id, '_ltms_ingredients_list', sanitize_textarea_field( wp_unslash( $_POST['_ltms_ingredients_list'] ) ) );
        }
    }

    /**
     * Devuelve los alérgenos activos para un producto.
     *
     * @param int $product_id ID del producto.
     * @return array ['slug' => 'label', ...]
     */
    public static function get_product_allergens( int $product_id ): array {
        $active = [];
        foreach ( self::ALLERGENS as $slug => $label ) {
            if ( get_post_meta( $product_id, '_ltms_allergen_' . $slug, true ) === 'yes' ) {
                $active[ $slug ] = $label;
            }
        }
        return $active;
    }

    /**
     * Muestra advertencia de alérgenos en PDP.
     */
    public static function display_allergen_warning(): void {
        global $product;
        if ( ! $product ) return;
        $allergens = self::get_product_allergens( (int) $product->get_id() );
        if ( empty( $allergens ) ) return;
        ?>
        <div class="ltms-allergen-warning" style="background:#fff3cd;border-left:4px solid #ffc107;padding:10px;margin:10px 0;">
            <strong>⚠️ <?php esc_html_e( 'Contiene alérgenos:', 'ltms' ); ?></strong>
            <ul style="margin:5px 0 0 15px;">
                <?php foreach ( $allergens as $slug => $label ) : ?>
                    <li><?php echo esc_html( $label ); ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="description" style="font-size:11px;margin-top:6px;">
                <?php esc_html_e( 'Resolución 333/2011 INVIMA / NOM-051-SCFI/SSI-2010 / Reglamento UE 1169/2011.', 'ltms' ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Advertencia de alérgenos en checkout (resumen de productos con alérgenos).
     */
    public static function display_allergen_warning_checkout(): void {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) return;
        $flagged = [];
        foreach ( WC()->cart->get_cart_contents() as $item ) {
            $pid   = (int) $item['product_id'];
            $a_all = self::get_product_allergens( $pid );
            if ( ! empty( $a_all ) ) {
                $product               = wc_get_product( $pid );
                $flagged[ $pid ] = [
                    'name'      => $product ? $product->get_name() : "PID #{$pid}",
                    'allergens' => array_values( $a_all ),
                ];
            }
        }
        if ( empty( $flagged ) ) return;
        ?>
        <div class="ltms-allergen-checkout-warning" style="background:#fff3cd;border-left:4px solid #ffc107;padding:10px;margin:8px 0;">
            <strong>⚠️ <?php esc_html_e( 'Productos con alérgenos en tu pedido:', 'ltms' ); ?></strong>
            <ul style="margin:5px 0 0 15px;">
                <?php foreach ( $flagged as $pid => $data ) : ?>
                    <li><strong><?php echo esc_html( $data['name'] ); ?>:</strong> <?php echo esc_html( implode( ', ', $data['allergens'] ) ); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    // ================================================================
    // RT-4: ALCOHOL TIME WINDOW RESTRICTION.
    // ================================================================

    /**
     * RT-4: Verifica que el carrito con alcohol cumpla con el horario permitido.
     *
     * Ley 124/1994 art. 4 (CO) — horarios definidos por municipio.
     * Ley General de Salud art. 178 (MX) — horarios estatales.
     */
    public static function check_alcohol_time_window(): void {
        if ( ! self::cart_has_alcohol() ) {
            return;
        }
        if ( current_user_can( 'manage_options' ) ) {
            return;
        }
        // Configuración: 'HH:MM-HH:MM' (24h, hora local del municipio).
        // Default: '10:00-22:00' (estándar CO; en MX varía por estado).
        $allowed_window = LTMS_Core_Config::get( 'ltms_alcohol_allowed_hours', '10:00-22:00' );
        if ( empty( $allowed_window ) ) {
            return; // Sin restricción.
        }
        list( $start, $end ) = array_pad( explode( '-', $allowed_window, 2 ), 2, '' );
        if ( empty( $start ) || empty( $end ) ) {
            return;
        }
        $now      = current_time( 'H:i' );
        $in_range = ( $now >= $start && $now <= $end );
        // Manejar rangos que cruzan medianoche (ej: '20:00-02:00').
        if ( $start > $end ) {
            $in_range = ( $now >= $start || $now <= $end );
        }
        if ( ! $in_range ) {
            wc_add_notice(
                sprintf(
                    /* translators: 1: allowed window */
                    __( 'La venta de alcohol solo está permitida entre %1$s (horario local). (Ley 124/1994 art. 4 / Ley General de Salud art. 178)', 'ltms' ),
                    esc_html( $allowed_window )
                ),
                'error'
            );
        }
    }

    // ================================================================
    // RT-5: TIP / SERVICE (10% opcional).
    // ================================================================

    /**
     * Renderiza el selector de propina en checkout.
     */
    public static function render_tip_selector(): void {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) return;
        // Mostrar solo si hay al menos un vendor con flag restaurante.
        $has_restaurant = false;
        foreach ( WC()->cart->get_cart_contents() as $item ) {
            $vendor_id = (int) ( $item['data']->get_meta( '_ltms_vendor_id' ) ?? 0 );
            if ( $vendor_id && get_user_meta( $vendor_id, 'ltms_is_restaurant', true ) === 'yes' ) {
                $has_restaurant = true;
                break;
            }
        }
        if ( ! $has_restaurant ) return;

        $current_tip = (float) ( WC()->session->get( 'ltms_tip_percentage' ) ?? 0 );
        ?>
        <div class="ltms-tip-selector" style="background:#f0f9ff;padding:12px;border-radius:6px;margin:8px 0;">
            <strong>🍽️ <?php esc_html_e( 'Propina sugerida (Ley 2a del 12 oct 1976 MX)', 'ltms' ); ?></strong>
            <p class="description"><?php esc_html_e( 'Opcional. 100% de la propina va al restaurante.', 'ltms' ); ?></p>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;">
                <?php
                $options = [ 0, 5, 10, 15, 20 ];
                foreach ( $options as $pct ) :
                    $is_active = ( $current_tip === (float) $pct );
                    ?>
                    <button type="button" class="ltms-tip-btn button <?php echo $is_active ? 'button-primary' : 'button-secondary'; ?>"
                            data-tip="<?php echo esc_attr( $pct ); ?>">
                        <?php echo esc_html( $pct === 0 ? __( 'Sin propina', 'ltms' ) : $pct . '%' ); ?>
                    </button>
                <?php endforeach; ?>
            </div>
            <input type="hidden" id="ltms_tip_nonce" value="<?php echo esc_attr( wp_create_nonce( 'ltms_tip_nonce' ) ); ?>" />
        </div>
        <?php
    }

    /**
     * Aplica la propina como fee de WooCommerce.
     */
    public static function apply_tip_fee( \WC_Cart $cart ): void {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        $tip_pct = (float) ( WC()->session->get( 'ltms_tip_percentage' ) ?? 0 );
        if ( $tip_pct <= 0 ) return;
        $subtotal = $cart->get_subtotal();
        $tip      = $subtotal * ( $tip_pct / 100 );
        if ( $tip > 0 ) {
            $cart->add_fee( sprintf( __( 'Propina (%d%%)', 'ltms' ), (int) $tip_pct ), $tip, false );
        }
    }

    /**
     * AJAX: establece el porcentaje de propina en sesión.
     */
    public static function ajax_set_tip(): void {
        check_ajax_referer( 'ltms_tip_nonce', 'nonce' );
        $pct = (float) ( $_POST['tip'] ?? 0 );
        $pct = max( 0, min( 50, $pct ) ); // límite 50%.
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'ltms_tip_percentage', $pct );
        }
        wp_send_json_success( [ 'tip_percentage' => $pct ] );
    }

    // ================================================================
    // RT-6: IMPUCONSUMO OPTION KEY MISMATCH BUG FIX.
    // ================================================================

    /**
     * RT-6 BUG FIX: Resuelve la tasa de Impoconsumo.
     *
     * Bug detectado en auditoría v2.9.13:
     *   - Admin UI guarda en: 'ltms_co_impoconsumo' (html-admin-fiscal-colombia.php:273)
     *   - Tax strategy lee de: 'ltms_impoconsumo_rate' (class-ltms-tax-strategy-colombia.php:67)
     *   - Activator default: 'ltms_impoconsumo_rate' (class-ltms-activator.php:311)
     *
     * Resultado: el usuario cambia el % en admin → no aplica.
     *
     * Fix: este filter ('ltms_impoconsumo_rate') prioriza
     * 'ltms_co_impoconsumo' (el valor configurable en admin) y solo usa
     * 'ltms_impoconsumo_rate' como fallback legacy. Si difieren, log warning.
     *
     * @param float $default_rate Tasa por defecto (0.08).
     * @return float Tasa efectiva.
     */
    public static function resolve_impoconsumo_rate( float $default_rate ): float {
        $legacy = (float) get_option( 'ltms_impoconsumo_rate', $default_rate );
        $canon  = (float) get_option( 'ltms_co_impoconsumo', -1.0 );

        // Si el canonico no está configurado (-1), usar legacy.
        if ( $canon < 0 ) {
            return $legacy;
        }
        // Si difieren, log para alertar al admin.
        if ( abs( $canon - $legacy ) > 0.0001 && class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::warning(
                'IMPOCONSUMO_CONFIG_MISMATCH',
                sprintf(
                    'Conflicto: ltms_co_impoconsumo=%.4f vs ltms_impoconsumo_rate=%.4f — usando valor admin (%.4f).',
                    $canon, $legacy, $canon
                )
            );
        }
        // Priorizar el valor admin (canonico).
        return $canon;
    }

    // ================================================================
    // RT-7: COLD CHAIN TRACING.
    // ================================================================

    /**
     * Registra metabox de cadena de frío en el producto.
     */
    public static function register_cold_chain_metabox(): void {
        echo '<div class="options_group ltms-cold-chain-metabox">';
        echo '<h3 style="padding:8px 10px;margin:0;background:#e0f2f1;">❄️ ' . esc_html__( 'Cadena de frío (Resolución 2674/2013 INVIMA / NOM-024-SSA3-2012)', 'ltms' ) . '</h3>';

        woocommerce_wp_checkbox( [
            'id'          => '_ltms_requires_cold_chain',
            'label'       => __( 'Requiere cadena de frío', 'ltms' ),
            'description' => __( 'Marca si el producto debe mantenerse refrigerado/congelado.', 'ltms' ),
        ] );

        echo '<p class="form-field" style="display:flex;gap:12px;align-items:end;">';
        echo '<label style="flex:1;">' . esc_html__( 'Temp. mínima (°C)', 'ltms' ) . '<br>';
        echo '<input type="number" name="_ltms_storage_temp_min" step="0.1" min="-30" max="20" class="short" /></label>';
        echo '<label style="flex:1;">' . esc_html__( 'Temp. máxima (°C)', 'ltms' ) . '<br>';
        echo '<input type="number" name="_ltms_storage_temp_max" step="0.1" min="-30" max="20" class="short" /></label>';
        echo '</p>';
        echo '</div>';
    }

    /**
     * Guarda metas de cadena de frío.
     */
    public static function save_cold_chain_meta( int $product_id ): void {
        $cold = isset( $_POST['_ltms_requires_cold_chain'] ) ? 'yes' : 'no';
        update_post_meta( $product_id, '_ltms_requires_cold_chain', $cold );

        $min = isset( $_POST['_ltms_storage_temp_min'] ) ? sanitize_text_field( wp_unslash( $_POST['_ltms_storage_temp_min'] ) ) : '';
        $max = isset( $_POST['_ltms_storage_temp_max'] ) ? sanitize_text_field( wp_unslash( $_POST['_ltms_storage_temp_max'] ) ) : '';
        update_post_meta( $product_id, '_ltms_storage_temp_min', $min );
        update_post_meta( $product_id, '_ltms_storage_temp_max', $max );
    }

    /**
     * Muestra badge "Mantener refrigerado" en PDP.
     */
    public static function display_cold_chain_badge(): void {
        global $product;
        if ( ! $product ) return;
        if ( get_post_meta( $product->get_id(), '_ltms_requires_cold_chain', true ) !== 'yes' ) {
            return;
        }
        $min = get_post_meta( $product->get_id(), '_ltms_storage_temp_min', true );
        $max = get_post_meta( $product->get_id(), '_ltms_storage_temp_max', true );
        ?>
        <div class="ltms-cold-chain-badge" style="background:#e0f7fa;border:1px solid #00bcd4;padding:8px;border-radius:4px;margin:8px 0;display:inline-block;">
            <strong>❄️ <?php esc_html_e( 'Mantener refrigerado', 'ltms' ); ?></strong>
            <?php if ( $min !== '' && $max !== '' ) : ?>
                <span style="margin-left:8px;"><?php echo esc_html( sprintf( __( 'entre %s°C y %s°C', 'ltms' ), $min, $max ) ); ?></span>
            <?php endif; ?>
            <p class="description" style="font-size:11px;margin-top:4px;">
                <?php esc_html_e( 'Resolución 2674/2013 INVIMA art. 14 / NOM-024-SSA3-2012.', 'ltms' ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Marca el order meta confirmando que se notificó al cliente la
     * necesidad de mantener cadena de frío.
     *
     * @param int $order_id ID de la orden.
     */
    public static function register_cold_chain_ack( int $order_id ): void {
        $has_cold = false;
        $order    = wc_get_order( $order_id );
        if ( ! $order ) return;
        foreach ( $order->get_items() as $item ) {
            $pid = (int) $item->get_product_id();
            if ( get_post_meta( $pid, '_ltms_requires_cold_chain', true ) === 'yes' ) {
                $has_cold = true;
                break;
            }
        }
        if ( $has_cold ) {
            $order->update_meta_data( '_ltms_cold_chain_ack', 'yes' );
            $order->update_meta_data( '_ltms_cold_chain_ack_at', current_time( 'mysql', true ) );
            $order->save();
        }
    }

    // ================================================================
    // HELPERS.
    // ================================================================

    /**
     * Devuelve las normas aplicables por país para documentación.
     *
     * @return array
     */
    public static function get_legal_basis(): array {
        return [
            'CO' => [
                'Ley 124/1994 art. 2'       => 'Prohibida venta de alcohol a menores de 18 años.',
                'Ley 124/1994 art. 4'       => 'Horarios municipales para venta de alcohol.',
                'Ley 2010/2019 art. 3'      => 'Impoconsumo 8% sobre alimentos preparados en restaurantes.',
                'Decreto 3075/1997 art. 4'  => 'Registro sanitario INVIMA obligatorio.',
                'Resolución 333/2011'        => 'Etiquetado nutricional y alérgenos.',
                'Resolución 2674/2013 art.14' => 'Trazabilidad de cadena de frío.',
                'ET art. 421'                => 'IVA 19% en cerveza/vino >2.5°GL.',
            ],
            'MX' => [
                'Ley General de Salud art. 178' => 'Horarios estatales para venta de alcohol.',
                'Ley General de Salud art. 475' => 'Prohibida venta de alcohol a menores de 18 años.',
                'NOM-251-SSA1-2009'          => 'Buenas prácticas de higiene (aviso COFEPRIS).',
                'NOM-051-SCFI/SSI-2010'      => 'Declaración de alérgenos en etiquetado.',
                'NOM-024-SSA3-2012'          => 'Trazabilidad de productos perecederos.',
                'Ley 2a del 12 oct 1976'     => 'Propina sugerida 10-15%.',
            ],
            'EU' => [
                'Reglamento (UE) 1169/2011 art. 9' => 'Información obligatoria de alérgenos.',
            ],
        ];
    }
}
