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
        // M-TURISMO-01: solo vendedores con business_type='tourism' entran en el flujo RNT/SECTUR.
        // Los de physical/digital/services no necesitan RNT y no deben aparecer en el panel de
        // Compliance con estado pending sin motivo.
        $btype = get_user_meta( $vendor_id, 'ltms_business_type', true );
        if ( 'tourism' !== $btype ) {
            return;
        }

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
                [ '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' ],
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
                'rnt_verified'    => $approved ? 1 : 0,
                'status'          => $approved ? 'verified' : 'rejected',
                'admin_notes'     => sanitize_textarea_field( $notes ),
                'rnt_verified_at' => $approved ? current_time( 'mysql' ) : null,
                'updated_at'      => current_time( 'mysql' ),
            ],
            [ 'vendor_id' => $vendor_id ],
            [ '%d', '%s', '%s', '%s', '%s' ],
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
        // REG-02 FIX: antes, esta función retornaba true si ltms_booking_rnt_required
        // era false (el default), lo que permitía a cualquier vendor de turismo
        // publicar alojamiento SIN RNT vigente — violando la Ley 2068/2020 (CO)
        // que exige RNT de FONTUR para servicios de hospedaje. Además, la función
        // era código muerto: nadie la llamaba. Ahora:
        // 1. El default es exigir RNT (cumplimiento legal por defecto).
        // 2. Solo se puede saltar si el admin explícitamente configura
        //    ltms_booking_rnt_required=false (para entornos de testing/staging).
        $rnt_required = LTMS_Core_Config::get( 'ltms_booking_rnt_required', true );
        if ( ! $rnt_required ) return true;
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
        $status    = $record['status'] ?? 'none';
        $country   = $record['country_code'] ?? strtoupper( (string) get_user_meta( $vendor_id, '_ltms_country_code', true ) ?: 'CO' );
        $ajaxurl   = admin_url( 'admin-ajax.php' );

        $status_labels = [
            'none'     => '— Sin registro',
            'pending'  => '⏳ En revisión',
            'verified' => '✓ Verificado',
            'rejected' => '✗ Rechazado',
            'expired'  => '⚠️ Vencido',
        ];
        $status_colors = [
            'none'     => '#9ca3af',
            'pending'  => '#f59e0b',
            'verified' => '#10b981',
            'rejected' => '#ef4444',
            'expired'  => '#6b7280',
        ];
        $badge_label = $status_labels[ $status ] ?? $status;
        $badge_color = $status_colors[ $status ] ?? '#9ca3af';
        ?>
        <style>
            .ltms-rnt-wrap{max-width:680px;font-family:inherit}
            .ltms-rnt-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
            .ltms-rnt-header h2{margin:0;font-size:1.25rem;color:#111827}
            .ltms-rnt-badge{display:inline-block;padding:4px 14px;border-radius:99px;font-size:.8rem;font-weight:700}
            .ltms-rnt-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px;margin-bottom:20px}
            .ltms-rnt-card .ltms-rnt-card-title{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:16px;border-bottom:1px solid #f3f4f6;padding-bottom:8px}
            .ltms-rnt-steps{display:flex;gap:0;margin-bottom:28px;counter-reset:step}
            .ltms-rnt-step{flex:1;text-align:center;position:relative;font-size:.78rem;color:#9ca3af}
            .ltms-rnt-step::before{counter-increment:step;content:counter(step);display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:#e5e7eb;color:#6b7280;font-weight:700;margin:0 auto 6px;font-size:.85rem;position:relative;z-index:1}
            .ltms-rnt-step::after{content:'';position:absolute;top:16px;left:calc(50% + 20px);width:calc(100% - 40px);height:2px;background:#e5e7eb}
            .ltms-rnt-step:last-child::after{display:none}
            .ltms-rnt-step.done::before{background:#10b981;color:#fff}
            .ltms-rnt-step.done::after{background:#10b981}
            .ltms-rnt-step.active::before{background:#2563eb;color:#fff;box-shadow:0 0 0 4px #dbeafe}
            .ltms-rnt-step.current{color:#1d2327;font-weight:600}
            .ltms-rnt-fg{margin-bottom:18px}
            .ltms-rnt-fg label{display:block;font-size:.875rem;font-weight:600;color:#374151;margin-bottom:6px}
            .ltms-rnt-fg .hint{font-size:.78rem;color:#6b7280;margin-top:4px;display:block}
            .ltms-rnt-fg input[type="text"],.ltms-rnt-fg input[type="date"],.ltms-rnt-fg select{width:100%;border:1.5px solid #d1d5db;border-radius:8px;padding:9px 13px;font-size:.9rem;color:#111827;transition:border-color .15s;box-sizing:border-box;background:#fff}
            .ltms-rnt-fg input:focus,.ltms-rnt-fg select:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px #dbeafe}
            .ltms-rnt-sworn{display:flex;align-items:flex-start;gap:10px;padding:16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;cursor:pointer;font-size:.875rem;color:#166534}
            .ltms-rnt-sworn input{margin-top:2px;flex-shrink:0;width:auto}
            .ltms-rnt-notice{padding:14px 18px;border-radius:8px;margin-bottom:20px;font-size:.9rem;line-height:1.5}
            .ltms-rnt-notice.success{background:#f0fdf4;border:1px solid #86efac;color:#166534}
            .ltms-rnt-notice.error{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
            .ltms-rnt-notice.warning{background:#fffbeb;border:1px solid #fde68a;color:#92400e}
            .ltms-rnt-notice.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}
            .ltms-rnt-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:11px 28px;border-radius:8px;font-size:.9rem;font-weight:600;border:none;cursor:pointer;transition:all .15s}
            .ltms-rnt-btn-primary{background:#2563eb;color:#fff;width:100%}
            .ltms-rnt-btn-primary:hover:not(:disabled){background:#1d4ed8}
            .ltms-rnt-btn-primary:disabled{opacity:.5;cursor:not-allowed}
            #ltms-rnt-save-notice{display:none;margin-top:14px}
        </style>

        <div class="ltms-rnt-wrap">

            <div class="ltms-rnt-header">
                <h2>&#127968; <?php esc_html_e( 'Registro Nacional de Turismo / SECTUR', 'ltms' ); ?></h2>
                <span class="ltms-rnt-badge"
                      style="background:<?php echo esc_attr( $badge_color ); ?>22;color:<?php echo esc_attr( $badge_color ); ?>;">
                    <?php echo esc_html( $badge_label ); ?>
                </span>
            </div>

            <?php
            $s1 = $s2 = $s3 = '';
            if ( 'none' === $status ) {
                $s1 = 'active current';
            } elseif ( in_array( $status, [ 'pending', 'rejected', 'expired' ], true ) ) {
                $s1 = 'done'; $s2 = 'done current';
            } elseif ( 'verified' === $status ) {
                $s1 = $s2 = $s3 = 'done';
            }
            ?>
            <div class="ltms-rnt-steps">
                <div class="ltms-rnt-step <?php echo esc_attr( $s1 ); ?>"><?php esc_html_e( 'Llenar datos RNT', 'ltms' ); ?></div>
                <div class="ltms-rnt-step <?php echo esc_attr( $s2 ); ?>"><?php esc_html_e( 'Revisión admin', 'ltms' ); ?></div>
                <div class="ltms-rnt-step <?php echo esc_attr( $s3 ); ?>"><?php esc_html_e( 'Verificado', 'ltms' ); ?></div>
            </div>

            <?php if ( 'verified' === $status ) : ?>
                <div class="ltms-rnt-notice success">
                    <strong>&#9989; <?php esc_html_e( 'Tu RNT está verificado y activo.', 'ltms' ); ?></strong>
                    <?php if ( ! empty( $record['rnt_expiry_date'] ) ) : ?>
                        <br><span style="font-size:.8rem;"><?php printf( esc_html__( 'Vigente hasta: %s', 'ltms' ), esc_html( $record['rnt_expiry_date'] ) ); ?></span>
                    <?php endif; ?>
                    <br><span style="font-size:.8rem;color:#166534;"><?php esc_html_e( 'Ya puedes publicar alojamientos turísticos en la plataforma.', 'ltms' ); ?></span>
                </div>
            <?php elseif ( 'pending' === $status ) : ?>
                <div class="ltms-rnt-notice info">
                    <strong>&#9203; <?php esc_html_e( 'Tu solicitud está en revisión.', 'ltms' ); ?></strong><br>
                    <?php esc_html_e( 'El equipo de Lo Tengo verificará tu número RNT con FONTUR/SECTUR. Te notificaremos por correo cuando haya una respuesta (normalmente 1–2 días hábiles).', 'ltms' ); ?>
                </div>
            <?php elseif ( 'rejected' === $status ) : ?>
                <div class="ltms-rnt-notice error">
                    <strong>&#10007; <?php esc_html_e( 'Tu solicitud fue rechazada.', 'ltms' ); ?></strong>
                    <?php if ( ! empty( $record['admin_notes'] ) ) : ?><br><?php echo esc_html( $record['admin_notes'] ); ?><?php endif; ?>
                    <br><span style="font-size:.8rem;"><?php esc_html_e( 'Corrige los datos y vuelve a enviar.', 'ltms' ); ?></span>
                </div>
            <?php elseif ( 'expired' === $status ) : ?>
                <div class="ltms-rnt-notice warning">
                    <strong>&#9888; <?php esc_html_e( 'Tu RNT ha vencido.', 'ltms' ); ?></strong><br>
                    <?php esc_html_e( 'Actualiza tu número de registro y la nueva fecha de vencimiento para renovar la verificación.', 'ltms' ); ?>
                </div>
            <?php else : ?>
                <div class="ltms-rnt-notice info">
                    <strong>&#8505; <?php esc_html_e( '¿Qué es el RNT / SECTUR?', 'ltms' ); ?></strong><br>
                    <?php esc_html_e( 'Para publicar servicios de alojamiento turístico en Colombia necesitas el Registro Nacional de Turismo (RNT) de FONTUR (Ley 2068/2020). En México se requiere el folio SECTUR. Completa el formulario y nuestro equipo lo verificará.', 'ltms' ); ?>
                </div>
            <?php endif; ?>

            <?php if ( 'verified' !== $status ) : ?>
            <form id="ltms-rnt-form" autocomplete="off">

                <div class="ltms-rnt-card">
                    <p class="ltms-rnt-card-title"><?php esc_html_e( 'País y tipo de registro', 'ltms' ); ?></p>
                    <div class="ltms-rnt-fg">
                        <label for="ltms-rnt-country"><?php esc_html_e( 'País donde opera tu alojamiento', 'ltms' ); ?></label>
                        <select name="country_code" id="ltms-rnt-country">
                            <option value="CO" <?php selected( $country, 'CO' ); ?>>&#127464;&#127476; Colombia — RNT (FONTUR)</option>
                            <option value="MX" <?php selected( $country, 'MX' ); ?>>&#127474;&#127485; México — Folio SECTUR</option>
                        </select>
                    </div>
                </div>

                <div class="ltms-rnt-card">
                    <p class="ltms-rnt-card-title"><?php esc_html_e( 'Datos del registro turístico', 'ltms' ); ?></p>
                    <div id="ltms-rnt-co-fields">
                        <div class="ltms-rnt-fg">
                            <label for="ltms-rnt-number"><?php esc_html_e( 'Número RNT (FONTUR Colombia)', 'ltms' ); ?> <span style="color:#ef4444;">*</span></label>
                            <input type="text" id="ltms-rnt-number" name="rnt_number"
                                   value="<?php echo esc_attr( $record['rnt_number'] ?? '' ); ?>"
                                   placeholder="Ej: CO-BOG-12345678">
                            <span class="hint">
                                <?php esc_html_e( 'Búscalo en el portal oficial de CONFECÁMARAS:', 'ltms' ); ?>
                                <a href="https://rnt.confecamaras.co/establecimientos" target="_blank" rel="noopener"
                                   style="color:#2563eb;"><?php esc_html_e( 'Consultar RNT', 'ltms' ); ?></a>.
                                <?php esc_html_e( '¿Aún no tienes RNT?', 'ltms' ); ?>
                                <a href="https://rnt.confecamaras.co/registrar" target="_blank" rel="noopener"
                                   style="color:#2563eb;"><?php esc_html_e( 'Regístralo aquí', 'ltms' ); ?></a>.
                            </span>
                        </div>
                    </div>
                    <div id="ltms-rnt-mx-fields" style="display:none;">
                        <div class="ltms-rnt-fg">
                            <label for="ltms-rnt-sectur"><?php esc_html_e( 'Folio SECTUR (México)', 'ltms' ); ?> <span style="color:#ef4444;">*</span></label>
                            <input type="text" id="ltms-rnt-sectur" name="sectur_folio"
                                   value="<?php echo esc_attr( $record['sectur_folio'] ?? '' ); ?>"
                                   placeholder="Ej: SECTUR-CDMX-78901">
                            <span class="hint"><?php esc_html_e( 'Folio emitido por SECTUR o Secretaría de Turismo de tu estado.', 'ltms' ); ?></span>
                        </div>
                    </div>
                    <div class="ltms-rnt-fg">
                        <label for="ltms-rnt-expiry"><?php esc_html_e( 'Fecha de vencimiento del registro', 'ltms' ); ?></label>
                        <input type="date" id="ltms-rnt-expiry" name="rnt_expiry_date"
                               value="<?php echo esc_attr( $record['rnt_expiry_date'] ?? '' ); ?>"
                               min="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>">
                        <span class="hint"><?php esc_html_e( 'Te avisaremos 30 días antes de que venza para que lo renueves a tiempo.', 'ltms' ); ?></span>
                    </div>
                </div>

                <div class="ltms-rnt-card">
                    <p class="ltms-rnt-card-title"><?php esc_html_e( 'Declaración jurada', 'ltms' ); ?></p>
                    <label class="ltms-rnt-sworn">
                        <input type="checkbox" name="sworn_declaration_signed" value="1" id="ltms-rnt-sworn"
                               <?php checked( ! empty( $record['sworn_declaration_signed'] ) ); ?>>
                        <span><?php esc_html_e( 'Declaro bajo juramento que la información suministrada es verídica, que el número de registro turístico pertenece a mi establecimiento y que cumplo con todos los requisitos legales vigentes para operar como prestador de servicios turísticos.', 'ltms' ); ?></span>
                    </label>
                </div>

                <button type="submit" id="ltms-rnt-submit" class="ltms-rnt-btn ltms-rnt-btn-primary">
                    <?php echo in_array( $status, [ 'rejected', 'expired' ], true )
                        ? esc_html__( 'Volver a enviar', 'ltms' )
                        : esc_html__( 'Enviar para verificación', 'ltms' ); ?>
                </button>
                <div id="ltms-rnt-save-notice" class="ltms-rnt-notice"></div>

            </form>
            <?php endif; ?>

        </div>

        <script>
        jQuery( function( $ ) {
            var ajaxUrl = '<?php echo esc_js( $ajaxurl ); ?>';
            var nonce   = '<?php echo esc_js( $nonce ); ?>';
            var $notice = $( '#ltms-rnt-save-notice' );
            function toggleCountry() {
                var co = $( '#ltms-rnt-country' ).val() === 'CO';
                $( '#ltms-rnt-co-fields' ).toggle( co );
                $( '#ltms-rnt-mx-fields' ).toggle( ! co );
            }
            $( '#ltms-rnt-country' ).on( 'change', toggleCountry );
            toggleCountry();
            function showNotice( msg, type ) {
                $notice.attr( 'class', 'ltms-rnt-notice ' + type ).html( msg ).show();
                if ( type === 'success' ) { setTimeout( function() { location.reload(); }, 2200 ); }
            }
            $( '#ltms-rnt-form' ).on( 'submit', function( e ) {
                e.preventDefault();
                var country = $( '#ltms-rnt-country' ).val();
                var rntVal  = $.trim( $( '#ltms-rnt-number' ).val() );
                var secVal  = $.trim( $( '#ltms-rnt-sectur' ).val() );
                var sworn   = $( '#ltms-rnt-sworn' ).is( ':checked' );
                if ( country === 'CO' && ! rntVal ) { showNotice( '&#9888; <?php echo esc_js( __( "El número RNT es obligatorio.", "ltms" ) ); ?>', 'warning' ); return; }
                if ( country === 'MX' && ! secVal ) { showNotice( '&#9888; <?php echo esc_js( __( "El folio SECTUR es obligatorio.", "ltms" ) ); ?>', 'warning' ); return; }
                if ( ! sworn ) { showNotice( '&#9888; <?php echo esc_js( __( "Debes aceptar la declaración jurada.", "ltms" ) ); ?>', 'warning' ); return; }
                var $btn = $( '#ltms-rnt-submit' ).prop( 'disabled', true ).text( '<?php echo esc_js( __( "Enviando...", "ltms" ) ); ?>' );
                $.post( ajaxUrl, { action: 'ltms_save_rnt', nonce: nonce, data: $( this ).serialize() }, function( r ) {
                    $btn.prop( 'disabled', false ).text( '<?php echo esc_js( __( "Enviar para verificación", "ltms" ) ); ?>' );
                    r.success ? showNotice( '&#10003; ' + r.data, 'success' ) : showNotice( '&#10007; ' + ( r.data || '<?php echo esc_js( __( "Error inesperado.", "ltms" ) ); ?>' ), 'error' );
                } );
            } );
        } );
        </script>
        <?php
    }

        public static function ajax_save_rnt(): void {
        try {
            check_ajax_referer( 'ltms_save_rnt', 'nonce' );
            // M-46: sanitizar cada campo tras parse_str para evitar XSS/injection en save_rnt.
            parse_str( wp_unslash( $_POST['data'] ?? '' ), $raw );
            $data = [
                'rnt_number'               => sanitize_text_field( $raw['rnt_number']               ?? '' ),
                'rnt_expiry_date'          => sanitize_text_field( $raw['rnt_expiry_date']          ?? '' ),
                'sectur_folio'             => sanitize_text_field( $raw['sectur_folio']             ?? '' ),
                'country_code'             => sanitize_text_field( $raw['country_code']             ?? '' ),
                'sworn_declaration_signed' => (bool) ( $raw['sworn_declaration_signed']            ?? false ),
            ];
            $vendor_id = get_current_user_id();
            if ( ! $vendor_id ) { wp_send_json_error( __( 'No autenticado.', 'ltms' ) ); return; }
            // Guard: solo vendedores activos pueden registrar compliance turístico.
            if ( class_exists( 'LTMS_Utils' ) && ! LTMS_Utils::is_ltms_vendor( $vendor_id ) && ! current_user_can( 'manage_options' ) ) {
                wp_send_json_error( __( 'Acceso denegado.', 'ltms' ) ); return;
            }
            self::save_rnt( $vendor_id, $data );
            wp_send_json_success( __( 'Información guardada. Pendiente de verificación por el administrador.', 'ltms' ) );
        } catch ( \Throwable $e ) {
            wp_send_json_error( $e->getMessage() );
        }
    }
}
