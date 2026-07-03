<?php
/**
 * LTMS Admin Bookable Product Metabox
 *
 * Expone en el editor de producto WC (/wp-admin/post.php) todos los
 * campos de LTMS_Product_Bookable que no tienen UI por defecto. Estos
 * campos se persisten como post meta con prefijo `_ltms_` + nombre de
 * la prop, exactamente como hace LTMS_Product_Bookable::save_extra_data()
 * — no se usa $product->set_X()/save() para evitar doble-escritura;
 * WC reconstruye el objeto en su propio hook save_post (que corre
 * después de save_post_product) y vuelve a persistir esos mismos
 * valores sin cambios, así que el round-trip es seguro.
 *
 *   Sección Reservas:
 *     - Tipo de servicio    (booking_type)
 *     - Capacidad           (capacity)
 *     - Mín / Máx noches    (min_nights / max_nights)
 *     - Check-in / Check-out times
 *     - Anticipación mínima / máxima (advance_booking_days / max_advance_days)
 *     - Reserva instantánea (instant_booking)
 *
 *   Sección Pago y política:
 *     - Modo de pago        (payment_mode)
 *     - % Depósito          (deposit_pct)
 *     - Política de cancel. (policy_id)
 *
 *   Sección Compliance turístico:
 *     - País                (country_code)
 *     - Número RNT (CO)     (rnt_number)
 *     - Folio SECTUR (MX)   (sectur_folio)
 *
 *   Sección Amenidades / Reglas:
 *     - Amenidades          (amenities)
 *     - Reglas de la casa   (rules_text)
 *
 * La caja se registra siempre (para todos los tipos de producto) y se
 * muestra/oculta vía JS al cambiar el selector "Tipo de producto" de
 * WooCommerce — si solo se registrara cuando el tipo guardado ya es
 * `ltms_bookable`, no existiría en el DOM al crear un producto nuevo
 * o al cambiar el tipo sin recargar la página.
 *
 * @package LTMS\Admin
 * @since   2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class LTMS_Admin_Bookable_Meta {

    private const NONCE_ACTION = 'ltms_bookable_meta_save';
    private const NONCE_FIELD  = 'ltms_bookable_meta_nonce';

    private const BOOKING_TYPES = [
        'accommodation'        => '🏨 Alojamiento',
        'experience'           => '🎭 Experiencia',
        'rental'                => '🚗 Alquiler',
        'professional_service'  => '💼 Servicio profesional',
        'space'                 => '🏢 Espacio',
        'restaurant'            => '🍽️ Restaurante',
    ];

    private const AMENITIES_LIST = [
        'wifi'      => 'WiFi',
        'parking'   => 'Parqueadero',
        'pool'      => 'Piscina',
        'ac'        => 'Aire acondicionado',
        'kitchen'   => 'Cocina',
        'washer'    => 'Lavadora',
        'tv'        => 'TV',
        'gym'       => 'Gimnasio',
        'breakfast' => 'Desayuno incluido',
        'pets'      => 'Mascotas permitidas',
    ];

    public static function init(): void {
        add_action( 'add_meta_boxes_product', [ __CLASS__, 'register_metabox' ] );
        add_action( 'save_post_product',      [ __CLASS__, 'save_fields' ], 25, 2 );
        add_action( 'admin_head',             [ __CLASS__, 'toggle_metabox_js' ] );
    }

    /**
     * Registra la metabox para TODOS los productos (no solo los ya
     * marcados como ltms_bookable). La visibilidad la controla el JS.
     */
    public static function register_metabox(): void {
        add_meta_box(
            'ltms_bookable_meta',
            __( '🏨 Configuración de Reserva LTMS', 'ltms' ),
            [ __CLASS__, 'render' ],
            'product',
            'normal',
            'high'
        );
    }

    public static function toggle_metabox_js(): void {
        $screen = get_current_screen();
        if ( ! $screen || 'product' !== $screen->id ) return;
        ?>
        <script>
        jQuery(function($){
            function ltmsToggleBookingMeta(){
                var box = $('#ltms_bookable_meta');
                if (!box.length) return;
                ( $('#product-type').val() === 'ltms_bookable' ) ? box.show() : box.hide();
            }
            ltmsToggleBookingMeta();
            $('#product-type').on('change', ltmsToggleBookingMeta);
        });
        </script>
        <?php
    }

    public static function render( \WP_Post $post ): void {
        $pid = (int) $post->ID;
        $p   = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : false;

        $btype     = ( $p && method_exists( $p, 'get_booking_type' ) )           ? $p->get_booking_type()           : 'accommodation';
        $min_n     = ( $p && method_exists( $p, 'get_min_nights' ) )             ? $p->get_min_nights()             : 1;
        $max_n     = ( $p && method_exists( $p, 'get_max_nights' ) )             ? $p->get_max_nights()             : 0;
        $capacity  = ( $p && method_exists( $p, 'get_capacity' ) )               ? $p->get_capacity()               : 1;
        $cin_time  = ( $p && method_exists( $p, 'get_checkin_time' ) )           ? $p->get_checkin_time()           : '15:00';
        $cout_time = ( $p && method_exists( $p, 'get_checkout_time' ) )          ? $p->get_checkout_time()          : '11:00';
        $adv_days  = ( $p && method_exists( $p, 'get_advance_booking_days' ) )   ? $p->get_advance_booking_days()   : 0;
        $max_adv   = ( $p && method_exists( $p, 'get_max_advance_days' ) )       ? $p->get_max_advance_days()       : 365;
        $instant   = ( $p && method_exists( $p, 'is_instant_booking' ) )         ? $p->is_instant_booking()         : false;
        $pay_mode  = ( $p && method_exists( $p, 'get_payment_mode' ) )           ? $p->get_payment_mode()           : 'full';
        $dep_pct   = ( $p && method_exists( $p, 'get_deposit_pct' ) )            ? $p->get_deposit_pct()            : 0.0;
        $policy_id = ( $p && method_exists( $p, 'get_policy_id' ) )              ? $p->get_policy_id()              : 0;
        $country   = ( $p && method_exists( $p, 'get_country_code' ) )           ? $p->get_country_code()           : 'CO';
        $rnt       = ( $p && method_exists( $p, 'get_rnt_number' ) )             ? $p->get_rnt_number()             : '';
        $sectur    = ( $p && method_exists( $p, 'get_sectur_folio' ) )           ? $p->get_sectur_folio()           : '';
        $amenities = ( $p && method_exists( $p, 'get_amenities' ) )              ? $p->get_amenities()              : [];
        $rules     = ( $p && method_exists( $p, 'get_rules_text' ) )             ? $p->get_rules_text()             : '';

        $vendor_id = (int) get_post_meta( $pid, '_ltms_vendor_id', true );
        $policies  = ( $vendor_id && class_exists( 'LTMS_Booking_Policy_Handler' ) )
            ? LTMS_Booking_Policy_Handler::get_vendor_policies( $vendor_id )
            : [];

        wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
        ?>
        <style>
            #ltms_bookable_meta .ltms-bm-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px 24px; }
            #ltms_bookable_meta .ltms-bm-grid.ltms-bm-3col { grid-template-columns:1fr 1fr 1fr; }
            #ltms_bookable_meta .ltms-bm-section { margin-bottom:20px; }
            #ltms_bookable_meta .ltms-bm-section h4 {
                font-size:12px; font-weight:700; text-transform:uppercase;
                letter-spacing:.5px; color:#646970; border-bottom:1px solid #dcdcde;
                padding-bottom:6px; margin-bottom:14px;
            }
            #ltms_bookable_meta label.ltms-lbl {
                display:block; font-size:12px; font-weight:600; color:#1d2327; margin-bottom:4px;
            }
            #ltms_bookable_meta input[type="number"],
            #ltms_bookable_meta input[type="time"],
            #ltms_bookable_meta input[type="text"],
            #ltms_bookable_meta select {
                width:100%; border:1px solid #8c8f94; border-radius:3px; padding:5px 8px; font-size:13px;
            }
            #ltms_bookable_meta textarea {
                width:100%; border:1px solid #8c8f94; border-radius:3px; padding:5px 8px; font-size:13px; resize:vertical;
            }
            #ltms_bookable_meta .ltms-bm-help { font-size:11px; color:#646970; margin-top:3px; }
            #ltms_bookable_meta .ltms-bm-amenities { display:flex; flex-wrap:wrap; gap:8px; }
            #ltms_bookable_meta .ltms-bm-amenities label {
                background:#f6f7f7; border:1px solid #dcdcde; border-radius:4px;
                padding:4px 10px; font-size:12px; cursor:pointer; font-weight:normal;
            }
            #ltms_bookable_meta .ltms-bm-amenities input:checked + span { color:#2271b1; font-weight:600; }
        </style>

        <?php /* ─── SECCIÓN 1: TIPO Y CONFIGURACIÓN BÁSICA ─── */ ?>
        <div class="ltms-bm-section">
            <h4><?php esc_html_e( 'Tipo y configuración básica', 'ltms' ); ?></h4>
            <div class="ltms-bm-grid">

                <div>
                    <label class="ltms-lbl" for="ltms_bm_booking_type"><?php esc_html_e( 'Tipo de servicio', 'ltms' ); ?></label>
                    <select id="ltms_bm_booking_type" name="_ltms_bm_booking_type">
                        <?php foreach ( self::BOOKING_TYPES as $val => $lbl ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $btype, $val ); ?>><?php echo esc_html( $lbl ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="ltms-lbl" for="ltms_bm_capacity"><?php esc_html_e( 'Capacidad máxima (huéspedes)', 'ltms' ); ?></label>
                    <input type="number" id="ltms_bm_capacity" name="_ltms_bm_capacity" value="<?php echo esc_attr( $capacity ); ?>" min="1" step="1">
                </div>

                <div>
                    <label class="ltms-lbl" for="ltms_bm_min_nights"><?php esc_html_e( 'Mínimo de noches', 'ltms' ); ?></label>
                    <input type="number" id="ltms_bm_min_nights" name="_ltms_bm_min_nights" value="<?php echo esc_attr( $min_n ); ?>" min="1" step="1">
                </div>

                <div>
                    <label class="ltms-lbl" for="ltms_bm_max_nights"><?php esc_html_e( 'Máximo de noches (0 = sin límite)', 'ltms' ); ?></label>
                    <input type="number" id="ltms_bm_max_nights" name="_ltms_bm_max_nights" value="<?php echo esc_attr( $max_n ); ?>" min="0" step="1">
                </div>

                <div>
                    <label class="ltms-lbl" for="ltms_bm_checkin_time"><?php esc_html_e( 'Hora de Check-in', 'ltms' ); ?></label>
                    <input type="time" id="ltms_bm_checkin_time" name="_ltms_bm_checkin_time" value="<?php echo esc_attr( $cin_time ); ?>">
                </div>

                <div>
                    <label class="ltms-lbl" for="ltms_bm_checkout_time"><?php esc_html_e( 'Hora de Check-out', 'ltms' ); ?></label>
                    <input type="time" id="ltms_bm_checkout_time" name="_ltms_bm_checkout_time" value="<?php echo esc_attr( $cout_time ); ?>">
                </div>

                <div>
                    <label class="ltms-lbl" for="ltms_bm_advance_booking_days"><?php esc_html_e( 'Anticipación mínima (días)', 'ltms' ); ?></label>
                    <input type="number" id="ltms_bm_advance_booking_days" name="_ltms_bm_advance_booking_days" value="<?php echo esc_attr( $adv_days ); ?>" min="0" step="1">
                    <p class="ltms-bm-help"><?php esc_html_e( 'Con cuántos días de anticipación se puede reservar como mínimo.', 'ltms' ); ?></p>
                </div>

                <div>
                    <label class="ltms-lbl" for="ltms_bm_max_advance_days"><?php esc_html_e( 'Anticipación máxima (días)', 'ltms' ); ?></label>
                    <input type="number" id="ltms_bm_max_advance_days" name="_ltms_bm_max_advance_days" value="<?php echo esc_attr( $max_adv ); ?>" min="1" step="1">
                </div>

                <div style="grid-column:span 2;">
                    <label class="ltms-lbl">
                        <input type="checkbox" name="_ltms_bm_instant_booking" value="1" <?php checked( $instant, true ); ?> style="width:auto;margin-right:6px;">
                        <?php esc_html_e( 'Reserva instantánea (sin aprobación manual)', 'ltms' ); ?>
                    </label>
                    <p class="ltms-bm-help"><?php esc_html_e( 'Si está activo, la reserva se confirma automáticamente al pagar. Si no, queda en "pending" hasta que el vendedor confirme.', 'ltms' ); ?></p>
                </div>

            </div>
        </div>

        <?php /* ─── SECCIÓN 2: PAGO Y POLÍTICA ─── */ ?>
        <div class="ltms-bm-section">
            <h4><?php esc_html_e( 'Pago y política de cancelación', 'ltms' ); ?></h4>
            <div class="ltms-bm-grid">

                <div>
                    <label class="ltms-lbl" for="ltms_bm_payment_mode"><?php esc_html_e( 'Modo de pago', 'ltms' ); ?></label>
                    <select id="ltms_bm_payment_mode" name="_ltms_bm_payment_mode">
                        <option value="full"         <?php selected( $pay_mode, 'full' ); ?>><?php esc_html_e( 'Pago completo al reservar', 'ltms' ); ?></option>
                        <option value="deposit"      <?php selected( $pay_mode, 'deposit' ); ?>><?php esc_html_e( 'Depósito + saldo al llegar', 'ltms' ); ?></option>
                        <option value="reserve_only" <?php selected( $pay_mode, 'reserve_only' ); ?>><?php esc_html_e( 'Solo reservar (sin cobro)', 'ltms' ); ?></option>
                    </select>
                </div>

                <div>
                    <label class="ltms-lbl" for="ltms_bm_deposit_pct"><?php esc_html_e( '% de depósito (si aplica)', 'ltms' ); ?></label>
                    <input type="number" id="ltms_bm_deposit_pct" name="_ltms_bm_deposit_pct" value="<?php echo esc_attr( $dep_pct ); ?>" min="0" max="100" step="1">
                    <p class="ltms-bm-help"><?php esc_html_e( 'Porcentaje del total que se cobra al reservar. Solo aplica si el modo es "Depósito".', 'ltms' ); ?></p>
                </div>

                <div style="grid-column:span 2;">
                    <label class="ltms-lbl" for="ltms_bm_policy_id"><?php esc_html_e( 'Política de cancelación', 'ltms' ); ?></label>
                    <select id="ltms_bm_policy_id" name="_ltms_bm_policy_id">
                        <option value="0"><?php esc_html_e( '— Política por defecto del vendedor —', 'ltms' ); ?></option>
                        <?php foreach ( $policies as $pol ) : ?>
                        <option value="<?php echo esc_attr( $pol['id'] ); ?>" <?php selected( $policy_id, (int) $pol['id'] ); ?>>
                            <?php echo esc_html( $pol['name'] . ' (' . $pol['policy_type'] . ')' ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ( $vendor_id && empty( $policies ) ) : ?>
                        <p class="ltms-bm-help" style="color:#b32d2e;">⚠️ <?php esc_html_e( 'El vendedor aún no tiene políticas configuradas. Se usará la política del sistema.', 'ltms' ); ?></p>
                    <?php elseif ( ! $vendor_id ) : ?>
                        <p class="ltms-bm-help">ℹ️ <?php esc_html_e( 'Asigna primero un vendedor a este producto para ver sus políticas disponibles.', 'ltms' ); ?></p>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <?php /* ─── SECCIÓN 3: COMPLIANCE TURÍSTICO ─── */ ?>
        <div class="ltms-bm-section">
            <h4><?php esc_html_e( 'Compliance turístico (RNT / SECTUR)', 'ltms' ); ?></h4>
            <div class="ltms-bm-grid ltms-bm-3col">

                <div>
                    <label class="ltms-lbl" for="ltms_bm_country_code"><?php esc_html_e( 'País', 'ltms' ); ?></label>
                    <select id="ltms_bm_country_code" name="_ltms_bm_country_code">
                        <option value="CO" <?php selected( $country, 'CO' ); ?>>🇨🇴 Colombia</option>
                        <option value="MX" <?php selected( $country, 'MX' ); ?>>🇲🇽 México</option>
                    </select>
                </div>

                <div>
                    <label class="ltms-lbl" for="ltms_bm_rnt_number"><?php esc_html_e( 'N° RNT (Colombia)', 'ltms' ); ?></label>
                    <input type="text" id="ltms_bm_rnt_number" name="_ltms_bm_rnt_number" value="<?php echo esc_attr( $rnt ); ?>" placeholder="Ej: CO-123456">
                    <p class="ltms-bm-help"><?php esc_html_e( 'Registro Nacional de Turismo FONTUR.', 'ltms' ); ?></p>
                </div>

                <div>
                    <label class="ltms-lbl" for="ltms_bm_sectur_folio"><?php esc_html_e( 'Folio SECTUR (México)', 'ltms' ); ?></label>
                    <input type="text" id="ltms_bm_sectur_folio" name="_ltms_bm_sectur_folio" value="<?php echo esc_attr( $sectur ); ?>" placeholder="Ej: MX-789012">
                </div>

            </div>
        </div>

        <?php /* ─── SECCIÓN 4: AMENIDADES Y REGLAS ─── */ ?>
        <div class="ltms-bm-section">
            <h4><?php esc_html_e( 'Amenidades y reglas de la casa', 'ltms' ); ?></h4>

            <label class="ltms-lbl" style="margin-bottom:8px;"><?php esc_html_e( 'Amenidades disponibles', 'ltms' ); ?></label>
            <div class="ltms-bm-amenities" style="margin-bottom:16px;">
                <?php foreach ( self::AMENITIES_LIST as $key => $label ) : ?>
                <label>
                    <input type="checkbox" name="_ltms_bm_amenities[]" value="<?php echo esc_attr( $key ); ?>"
                        <?php checked( in_array( $key, $amenities, true ), true ); ?> style="width:auto;margin-right:4px;">
                    <span><?php echo esc_html( $label ); ?></span>
                </label>
                <?php endforeach; ?>
            </div>

            <label class="ltms-lbl" for="ltms_bm_rules_text"><?php esc_html_e( 'Reglas de la casa / Condiciones', 'ltms' ); ?></label>
            <textarea id="ltms_bm_rules_text" name="_ltms_bm_rules_text" rows="4"
                      placeholder="<?php esc_attr_e( 'Ej: No se permiten mascotas, No fumar, Silencio después de las 22h…', 'ltms' ); ?>"><?php echo esc_textarea( $rules ); ?></textarea>
        </div>
        <?php
    }

    public static function save_fields( int $post_id, \WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) return; // phpcs:ignore
        if ( ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION ) ) return; // phpcs:ignore
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        // Mapa: clave de meta real (_ltms_ + nombre de prop en LTMS_Product_Bookable)
        // => [ campo del formulario sin el prefijo _ltms_bm_, sanitizador ]
        $map = [
            '_ltms_booking_type'         => [ 'field' => 'booking_type',         'fn' => 'sanitize_key'          ],
            '_ltms_capacity'             => [ 'field' => 'capacity',             'fn' => 'absint'                ],
            '_ltms_min_nights'           => [ 'field' => 'min_nights',           'fn' => 'absint'                ],
            '_ltms_max_nights'           => [ 'field' => 'max_nights',           'fn' => 'absint'                ],
            '_ltms_checkin_time'         => [ 'field' => 'checkin_time',         'fn' => 'sanitize_text_field'   ],
            '_ltms_checkout_time'        => [ 'field' => 'checkout_time',        'fn' => 'sanitize_text_field'   ],
            '_ltms_advance_booking_days' => [ 'field' => 'advance_booking_days', 'fn' => 'absint'                ],
            '_ltms_max_advance_days'     => [ 'field' => 'max_advance_days',     'fn' => 'absint'                ],
            '_ltms_payment_mode'         => [ 'field' => 'payment_mode',         'fn' => 'sanitize_key'          ],
            '_ltms_deposit_pct'          => [ 'field' => 'deposit_pct',          'fn' => 'floatval'              ],
            '_ltms_policy_id'            => [ 'field' => 'policy_id',            'fn' => 'absint'                ],
            '_ltms_country_code'         => [ 'field' => 'country_code',         'fn' => 'sanitize_text_field'   ],
            '_ltms_rnt_number'           => [ 'field' => 'rnt_number',           'fn' => 'sanitize_text_field'   ],
            '_ltms_sectur_folio'         => [ 'field' => 'sectur_folio',         'fn' => 'sanitize_text_field'   ],
            '_ltms_rules_text'           => [ 'field' => 'rules_text',           'fn' => 'wp_kses_post'          ],
        ];

        foreach ( $map as $meta_key => $cfg ) {
            $post_field = '_ltms_bm_' . $cfg['field'];
            if ( isset( $_POST[ $post_field ] ) ) { // phpcs:ignore
                $value = call_user_func( $cfg['fn'], wp_unslash( $_POST[ $post_field ] ) ); // phpcs:ignore
                update_post_meta( $post_id, $meta_key, $value );
            }
        }

        // instant_booking (checkbox: ausente en $_POST si no está marcado)
        $instant = isset( $_POST['_ltms_bm_instant_booking'] ) && '1' === $_POST['_ltms_bm_instant_booking']; // phpcs:ignore
        update_post_meta( $post_id, '_ltms_instant_booking', $instant );

        // amenities (array; ausente en $_POST si ninguna está marcada)
        $raw_amenities = ( isset( $_POST['_ltms_bm_amenities'] ) && is_array( $_POST['_ltms_bm_amenities'] ) ) // phpcs:ignore
            ? array_map( 'sanitize_key', (array) $_POST['_ltms_bm_amenities'] ) // phpcs:ignore
            : [];
        update_post_meta( $post_id, '_ltms_amenities', $raw_amenities );
    }
}
