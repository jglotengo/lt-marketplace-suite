<?php
/**
 * LTMS Admin Product Metabox
 *
 * Añade una caja de metadatos "LT Marketplace Suite" en el editor de producto
 * de WooCommerce (/wp-admin/post.php) con los campos que el plugin gestiona
 * pero que WooCommerce no expone por defecto:
 *
 *   1. Tipo de producto LTMS  (_ltms_product_type)   physical/digital/service/booking
 *   2. Tasa de comisión individual (_ltms_commission_rate)  sobrescribe la cascada
 *
 * Los campos de ReDi (habilitar + tasa) ya se renderizan en la pestaña
 * "General" de datos del producto mediante LTMS_Business_Redi_Manager::render_redi_product_fields(),
 * así que no se duplican aquí.
 *
 * @package LTMS\Admin
 * @since   2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class LTMS_Admin_Product_Meta {

    /** Nonce action para el guardado */
    private const NONCE_ACTION = 'ltms_product_meta_save';
    private const NONCE_FIELD  = 'ltms_product_meta_nonce';

    /** Tipos de producto válidos */
    private const PRODUCT_TYPES = [
        'physical' => '📦 Físico',
        'digital'  => '💾 Digital',
        'service'  => '🔧 Servicio',
        'booking'  => '🏨 Turismo / Reserva',
    ];

    public static function init(): void {
        add_action( 'add_meta_boxes', [ __CLASS__, 'register_metabox' ] );
        add_action( 'save_post_product', [ __CLASS__, 'save_fields' ], 20, 2 );
    }

    /**
     * Registra la meta box en el editor de producto.
     */
    public static function register_metabox(): void {
        add_meta_box(
            'ltms_product_meta',
            __( 'LT Marketplace Suite', 'ltms' ),
            [ __CLASS__, 'render' ],
            'product',
            'side',      // columna lateral, igual que Categorías
            'default'
        );
    }

    /**
     * Renderiza la meta box.
     *
     * @param WP_Post $post Post del producto.
     */
    public static function render( WP_Post $post ): void {
        $product_id = (int) $post->ID;

        // Leer valores actuales
        $raw_type = get_post_meta( $product_id, '_ltms_product_type', true );
        // Mapeo legacy: 'product' → 'physical'
        $current_type = ( $raw_type === '' || $raw_type === 'product' ) ? 'physical' : $raw_type;

        $commission_rate_raw = get_post_meta( $product_id, '_ltms_commission_rate', true );
        // Mostrar como porcentaje (× 100) si está guardado en decimal
        $commission_display = '';
        if ( $commission_rate_raw !== '' && is_numeric( $commission_rate_raw ) ) {
            $rate_f = (float) $commission_rate_raw;
            $commission_display = ( $rate_f > 0 && $rate_f <= 1 )
                ? number_format( $rate_f * 100, 2, '.', '' )
                : number_format( $rate_f, 2, '.', '' );
        }

        wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
        ?>
        <style>
            #ltms_product_meta .ltms-meta-row { margin-bottom:14px; }
            #ltms_product_meta label.ltms-label {
                display:block; font-weight:600; font-size:12px;
                text-transform:uppercase; letter-spacing:.4px;
                color:#1d2327; margin-bottom:5px;
            }
            #ltms_product_meta select,
            #ltms_product_meta input[type="number"] {
                width:100%; border:1px solid #8c8f94;
                border-radius:3px; padding:5px 8px; font-size:13px;
            }
            #ltms_product_meta .ltms-meta-help {
                font-size:11px; color:#646970; margin-top:4px; line-height:1.4;
            }
            #ltms_product_meta .ltms-meta-tip {
                background:#f0f6fc; border-left:3px solid #2271b1;
                padding:6px 9px; font-size:11px; color:#1d2327;
                margin-top:8px; border-radius:0 3px 3px 0;
            }
        </style>

        <?php /* ── 1. TIPO DE PRODUCTO ── */ ?>
        <div class="ltms-meta-row">
            <label class="ltms-label" for="ltms_product_type_select">
                <?php esc_html_e( 'Tipo LTMS', 'ltms' ); ?>
            </label>
            <select id="ltms_product_type_select" name="_ltms_product_type">
                <?php foreach ( self::PRODUCT_TYPES as $val => $label ) : ?>
                    <option value="<?php echo esc_attr( $val ); ?>"
                        <?php selected( $current_type, $val ); ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <p class="ltms-meta-help">
                <?php esc_html_e( 'Determina la cascada de comisiones (físico 15%, servicio 10%, etc.).', 'ltms' ); ?>
            </p>
        </div>

        <?php /* ── 2. COMISIÓN INDIVIDUAL ── */ ?>
        <div class="ltms-meta-row">
            <label class="ltms-label" for="ltms_commission_rate_pct">
                <?php esc_html_e( 'Comisión individual (%)', 'ltms' ); ?>
            </label>
            <input
                type="number"
                id="ltms_commission_rate_pct"
                name="_ltms_commission_rate_pct"
                value="<?php echo esc_attr( $commission_display ); ?>"
                min="0" max="100" step="0.01"
                placeholder="<?php esc_attr_e( 'Dejar vacío = cascada', 'ltms' ); ?>"
            />
            <p class="ltms-meta-help">
                <?php esc_html_e( 'Opcional. Sobrescribe la tasa de comisión de la cascada (tipo, plan, global) solo para este producto. Ingresa el valor como porcentaje (ej: 12 = 12%).', 'ltms' ); ?>
            </p>
            <?php if ( $commission_display !== '' ) : ?>
                <div class="ltms-meta-tip">
                    <?php printf(
                        esc_html__( 'Tasa activa: %s%% (guardada como %s en DB)', 'ltms' ),
                        esc_html( $commission_display ),
                        esc_html( $commission_rate_raw )
                    ); ?>
                </div>
            <?php endif; ?>
        </div>

        <?php /* ── 3. INFO DE COMISIÓN EFECTIVA (solo lectura) ── */ ?>
        <?php
        $vendor_id = (int) get_post_meta( $product_id, '_ltms_vendor_id', true );
        if ( $vendor_id && class_exists( 'LTMS_Commission_Strategy' ) ) :
            $summary = LTMS_Commission_Strategy::get_rate_summary( $vendor_id );
            ?>
            <hr style="border:none;border-top:1px solid #dcdcde;margin:10px 0;">
            <div class="ltms-meta-row">
                <label class="ltms-label"><?php esc_html_e( 'Tasa efectiva del vendedor', 'ltms' ); ?></label>
                <div class="ltms-meta-tip">
                    <?php
                    $pct    = number_format( $summary['current_rate'] * 100, 2 );
                    $source = $summary['rate_source'] === 'custom_contract'
                        ? __( 'contrato individual', 'ltms' )
                        : __( 'cascada global', 'ltms' );
                    printf(
                        esc_html__( '%s%% (fuente: %s, tier: %s)', 'ltms' ),
                        esc_html( $pct ),
                        esc_html( $source ),
                        esc_html( $summary['tier'] )
                    );
                    ?>
                </div>
                <p class="ltms-meta-help">
                    <?php esc_html_e( 'Tasa que se aplicará al próximo pedido de este vendedor (si no hay comisión individual por producto activa arriba).', 'ltms' ); ?>
                </p>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Guarda los campos del metabox al guardar el producto.
     *
     * @param int     $post_id ID del producto.
     * @param WP_Post $post    Post del producto.
     */
    public static function save_fields( int $post_id, WP_Post $post ): void {
        // Guards estándar
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) return; // phpcs:ignore
        if ( ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION ) ) return; // phpcs:ignore
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        // ── 1. Tipo de producto LTMS ──
        if ( isset( $_POST['_ltms_product_type'] ) ) { // phpcs:ignore
            $type = sanitize_key( $_POST['_ltms_product_type'] ); // phpcs:ignore
            // Mapeo legacy
            if ( $type === 'product' ) $type = 'physical';
            if ( in_array( $type, array_keys( self::PRODUCT_TYPES ), true ) ) {
                update_post_meta( $post_id, '_ltms_product_type', $type );
            }
        }

        // ── 2. Comisión individual por producto ──
        if ( isset( $_POST['_ltms_commission_rate_pct'] ) ) { // phpcs:ignore
            $pct_raw = sanitize_text_field( wp_unslash( $_POST['_ltms_commission_rate_pct'] ) ); // phpcs:ignore
            if ( $pct_raw === '' ) {
                // Campo vacío = eliminar la tasa individual (cae a la cascada)
                delete_post_meta( $post_id, '_ltms_commission_rate' );
            } else {
                $pct  = (float) $pct_raw;
                // Guardar como decimal (0.12) desde porcentaje (12)
                $rate = max( 0.0, min( 1.0, $pct / 100 ) );
                update_post_meta( $post_id, '_ltms_commission_rate', $rate );
            }
        }
    }
}
