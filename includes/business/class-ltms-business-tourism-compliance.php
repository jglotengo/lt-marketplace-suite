<?php
/**
 * LTMS Business Tourism Compliance
 *
 * Gestión RNT (FONTUR Colombia, Ley 2068/2020) y SECTUR (México).
 * Almacena número, vencimiento y estado de verificación por vendedor.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Business_Tourism_Compliance
 */
class LTMS_Business_Tourism_Compliance {

    private static bool $initialized = false;

    public static function init(): void {
        if ( self::$initialized ) return;
        self::$initialized = true;

        add_action( 'ltms_vendor_approved',      [ self::class, 'create_compliance_record' ] );
        add_action( 'woocommerce_account_menu_items', [ self::class, 'add_account_menu_item' ] );
        add_action( 'init', [ self::class, 'add_endpoint' ] );
        add_action( 'woocommerce_account_ltms-rnt_endpoint', [ self::class, 'render_account_rnt_form' ] );
        add_action( 'wp_ajax_ltms_save_rnt',     [ self::class, 'ajax_save_rnt' ] );
    }

    // ── Public API ───────────────────────────────────────────────────────

    /**
     * Crea el registro de compliance cuando se aprueba el vendedor.
     */
    public static function create_compliance_record( int $vendor_id ): void {
        global $wpdb;
        $exists = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}lt_tourism_compliance WHERE vendor_id = %d", $vendor_id )
        );
        if ( $exists ) return;

        $country = strtoupper( sanitize_text_field( get_user_meta( $vendor_id, '_ltms_country_code', true ) ?: 'CO' ) );
        $wpdb->insert(
            $wpdb->prefix . 'lt_tourism_compliance',
            [
                'vendor_id'    => $vendor_id,
                'country_code' => $country,
                'status'       => 'pending',
                'created_at'   => current_time( 'mysql' ),
                'updated_at'   => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s' ]
        );
    }

    /**
     * Obtiene el registro de compliance de un vendedor.
     */
    public static function get_record( int $vendor_id ): ?array {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}lt_tourism_compliance WHERE vendor_id = %d",
                $vendor_id
            ),
            ARRAY_A
        ) ?: null;
    }

    /**
     * Guarda / actualiza datos RNT del vendedor.
     *
     * @param int   $vendor_id
     * @param array $data  Keys: rnt_number, rnt_expiry_date, sectur_folio, country_code, sworn_declaration_signed
     * @return bool
     */
    public static function save_rnt( int $vendor_id, array $data ): bool {
        global $wpdb;

        $record = self::get_record( $vendor_id );

        $payload = [
            'rnt_number'              => sanitize_text_field( $data['rnt_number'] ?? '' ),
            'rnt_expiry_date'         => sanitize_text_field( $data['rnt_expiry_date'] ?? '' ) ?: null,
            'sectur_folio'            => sanitize_text_field( $data['sectur_folio'] ?? '' ),
            'country_code'            => strtoupper( sanitize_text_field( $data['country_code'] ?? 'CO' ) ),
            'sworn_declaration_signed'=> (int) ( $data['sworn_declaration_signed'] ?? 0 ),
            'status'                  => 'pending',
            'rnt_verified'            => 0,
            'updated_at'              => current_time( 'mysql' ),
        ];

        if ( $record ) {
            return (bool) $wpdb->update(
                $wpdb->prefix . 'lt_tourism_compliance',
                $payload,
                [ 'vendor_id' => $vendor_id ],
                null,
                [ '%d' ]
            );
        }

        $payload['vendor_id']  = $vendor_id;
        $payload['created_at'] = current_time( 'mysql' );
        return (bool) $wpdb->insert( $wpdb->prefix . 'lt_tourism_compliance', $payload );
    }

    /**
     * Verifica o rechaza un RNT (acción admin).
     *
     * @param int    $vendor_id
     * @param bool   $approved
     * @param string $notes
     */
    public static function verify_rnt( int $vendor_id, bool $approved, string $notes = '' ): void {
        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'lt_tourism_compliance',
            [
                'rnt_verified' => $approved ? 1 : 0,
                'status'       => $approved ? 'verified' : 'rejected',
                'admin_notes'  => sanitize_textarea_field( $notes ),
                'verified_at'  => $approved ? current_time( 'mysql' ) : null,
                'updated_at'   => current_time( 'mysql' ),
            ],
            [ 'vendor_id' => $vendor_id ],
            null,
            [ '%d' ]
        );

        if ( $approved ) {
            update_user_meta( $vendor_id, '_ltms_rnt_verified', 1 );
            do_action( 'ltms_rnt_approved', $vendor_id );
        } else {
            update_user_meta( $vendor_id, '_ltms_rnt_verified', 0 );
            do_action( 'ltms_rnt_rejected', $vendor_id, $notes );
        }
    }

    /**
     * Verifica si el vendedor puede publicar alojamientos (RNT activo).
     */
    public static function can_publish_accommodation( int $vendor_id ): bool {
        if ( ! LTMS_Core_Config::get( 'ltms_booking_rnt_required', false ) ) return true;
        $record = self::get_record( $vendor_id );
        return $record && 'verified' === $record['status'] && (int) $record['rnt_verified'];
    }

    /**
     * Cron: verifica vencimiento de RNT y marca expirados.
     */
    public static function check_rnt_expiry(): void {
        global $wpdb;

        $expired = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}lt_tourism_compliance
             WHERE rnt_expiry_date IS NOT NULL
               AND rnt_expiry_date < CURDATE()
               AND rnt_verified = 1",
            ARRAY_A
        ) ?: [];

        foreach ( $expired as $row ) {
            $wpdb->update(
                $wpdb->prefix . 'lt_tourism_compliance',
                [ 'status' => 'expired', 'rnt_verified' => 0, 'updated_at' => current_time( 'mysql' ) ],
                [ 'id' => (int) $row['id'] ],
                [ '%s', '%d', '%s' ],
                [ '%d' ]
            );
            update_user_meta( (int) $row['vendor_id'], '_ltms_rnt_verified', 0 );
            do_action( 'ltms_rnt_expired', (int) $row['vendor_id'], $row );
        }
    }

    // ── Frontend (My Account) ────────────────────────────────────────────

    public static function add_endpoint(): void {
        add_rewrite_endpoint( 'ltms-rnt', EP_ROOT | EP_PAGES );
    }

    public static function add_account_menu_item( array $items ): array {
        $items['ltms-rnt'] = __( 'RNT / SECTUR', 'ltms' );
        return $items;
    }

    public static function render_account_rnt_form(): void {
        $vendor_id = get_current_user_id();
        $record    = self::get_record( $vendor_id ) ?? [];
        $nonce     = wp_create_nonce( 'ltms_save_rnt' );
        ?>
        <h3><?php esc_html_e( 'Registro Nacional de Turismo / SECTUR', 'ltms' ); ?></h3>
        <?php if ( ! empty( $record['status'] ) && 'verified' === $record['status'] ) : ?>
            <div class="woocommerce-message">
                <?php esc_html_e( '✓ Tu RNT está verificado y activo.', 'ltms' ); ?>
            </div>
        <?php elseif ( ! empty( $record['status'] ) && 'rejected' === $record['status'] ) : ?>
            <div class="woocommerce-error">
                <?php esc_html_e( '✗ Tu RNT fue rechazado. Por favor corrige la información.', 'ltms' ); ?>
                <?php if ( ! empty( $record['admin_notes'] ) ) : ?>
                    <p><strong><?php esc_html_e( 'Motivo:', 'ltms' ); ?></strong> <?php echo esc_html( $record['admin_notes'] ); ?></p>
                <?php endif; ?>
            </div>
        <?php elseif ( ! empty( $record['status'] ) && 'expired' === $record['status'] ) : ?>
            <div class="woocommerce-error">
                <?php esc_html_e( '⚠️ Tu RNT ha vencido. Actualiza la información para renovar.', 'ltms' ); ?>
            </div>
        <?php endif; ?>
        <form id="ltms-rnt-form">
            <p>
                <label><?php esc_html_e( 'País', 'ltms' ); ?></label><br>
                <select name="country_code">
                    <option value="CO" <?php selected( $record['country_code'] ?? 'CO', 'CO' ); ?>>Colombia (RNT)</option>
                    <option value="MX" <?php selected( $record['country_code'] ?? '', 'MX' ); ?>>México (SECTUR)</option>
                </select>
            </p>
            <p id="ltms-rnt-field">
                <label><?php esc_html_e( 'Número RNT (Colombia)', 'ltms' ); ?></label><br>
                <input type="text" name="rnt_number" value="<?php echo esc_attr( $record['rnt_number'] ?? '' ); ?>" class="input-text">
            </p>
            <p id="ltms-sectur-field" style="display:none;">
                <label><?php esc_html_e( 'Folio SECTUR (México)', 'ltms' ); ?></label><br>
                <input type="text" name="sectur_folio" value="<?php echo esc_attr( $record['sectur_folio'] ?? '' ); ?>" class="input-text">
            </p>
            <p>
                <label><?php esc_html_e( 'Fecha de vencimiento RNT', 'ltms' ); ?></label><br>
                <input type="date" name="rnt_expiry_date" value="<?php echo esc_attr( $record['rnt_expiry_date'] ?? '' ); ?>" class="input-text">
            </p>
            <p>
                <label>
                    <input type="checkbox" name="sworn_declaration_signed" value="1" <?php checked( ! empty( $record['sworn_declaration_signed'] ) ); ?>>
                    <?php esc_html_e( 'Declaro bajo juramento que la información es verídica.', 'ltms' ); ?>
                </label>
            </p>
            <button type="submit" class="button"><?php esc_html_e( 'Guardar', 'ltms' ); ?></button>
        </form>
        <script>
        jQuery(function($){
            var form = $('#ltms-rnt-form');
            function toggleFields(){ var co=$('[name=country_code]').val()==='CO'; $('#ltms-rnt-field').toggle(co); $('#ltms-sectur-field').toggle(!co); }
            $('[name=country_code]').on('change', toggleFields).trigger('change');
            form.on('submit', function(e){
                e.preventDefault();
                $.post(wc_add_to_cart_params.ajax_url, { action:'ltms_save_rnt', nonce:'<?php echo esc_js( $nonce ); ?>', data:form.serialize() }, function(r){
                    r.success ? location.reload() : alert(r.data);
                });
            });
        });
        </script>
        <?php
    }

    public static function ajax_save_rnt(): void {
        try {
            check_ajax_referer( 'ltms_save_rnt', 'nonce' );
            parse_str( $_POST['data'] ?? '', $data );
            $vendor_id = get_current_user_id();
            if ( ! $vendor_id ) { wp_send_json_error( __( 'No autenticado.', 'ltms' ) ); return; }
            self::save_rnt( $vendor_id, $data );
            wp_send_json_success( __( 'Información guardada. Pendiente de verificación por el administrador.', 'ltms' ) );
        } catch ( \Throwable $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }
}
