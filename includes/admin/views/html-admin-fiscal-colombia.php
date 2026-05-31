<?php
/**
 * Vista: Admin Fiscal Colombia - Configuracion Fiscal
 *
 * @package LTMS
 * @version 2.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Guardar ─────────────────────────────────────────────────────── */
$notice = '';
if ( isset( $_POST['ltms_fiscal_co_save'] ) ) {
    check_admin_referer( 'ltms_fiscal_co' );

    $fields = [
        'ltms_co_decreto_ref'       => 'sanitize_text_field',
        'ltms_co_vigencia_desde'    => 'sanitize_text_field',
        'ltms_co_uvt'               => 'floatval',
        'ltms_co_sagrilaft_uvt'     => 'floatval',
        'ltms_co_iva_general'       => 'floatval',
        'ltms_co_iva_reducido'      => 'floatval',
        'ltms_co_rete_honorarios'   => 'floatval',
        'ltms_co_rete_servicios'    => 'floatval',
        'ltms_co_rete_compras'      => 'floatval',
        'ltms_co_rete_tech'         => 'floatval',
        'ltms_co_rete_umbral_compras' => 'floatval',
        'ltms_co_rete_umbral_servicios' => 'floatval',
        'ltms_co_rete_iva'          => 'floatval',
        'ltms_co_impoconsumo'       => 'floatval',
    ];

    $prev = [];
    foreach ( $fields as $key => $fn ) {
        $prev[ $key ] = get_option( $key );
    }

    foreach ( $fields as $key => $fn ) {
        $val = $fn( $_POST[ $key ] ?? '' ); // phpcs:ignore
        update_option( $key, $val );
    }

    // Historial
    global $wpdb;
    $hist_table = $wpdb->prefix . 'lt_tax_rates_history';
    $hist_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hist_table ) ); // phpcs:ignore
    if ( $hist_exists ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert( $hist_table, [
            'country'    => 'CO',
            'decreto'    => sanitize_text_field( $_POST['ltms_co_decreto_ref'] ?? '' ), // phpcs:ignore
            'changed_by' => get_current_user_id(),
            'snapshot'   => wp_json_encode( array_map( fn( $k ) => get_option( $k ), array_flip( $fields ) ) ),
            'created_at' => gmdate( 'Y-m-d H:i:s' ),
        ], [ '%s', '%s', '%d', '%s', '%s' ] );
    }

    $notice = __( 'Configuracion fiscal Colombia guardada correctamente.', 'ltms' );
}

/* ── Leer valores actuales ───────────────────────────────────────── */
$uvt              = (float) get_option( 'ltms_co_uvt',               52752 );
$sagrilaft_uvt    = (float) get_option( 'ltms_co_sagrilaft_uvt',     10000 );
$iva_gen          = (float) get_option( 'ltms_co_iva_general',       0.19  );
$iva_red          = (float) get_option( 'ltms_co_iva_reducido',      0.05  );
$rete_hon         = (float) get_option( 'ltms_co_rete_honorarios',   0.11  );
$rete_svc         = (float) get_option( 'ltms_co_rete_servicios',    0.04  );
$rete_cmp         = (float) get_option( 'ltms_co_rete_compras',      0.025 );
$rete_tech        = (float) get_option( 'ltms_co_rete_tech',         0.035 );
$umbral_cmp       = (float) get_option( 'ltms_co_rete_umbral_compras',   27 );
$umbral_svc       = (float) get_option( 'ltms_co_rete_umbral_servicios', 4  );
$rete_iva         = (float) get_option( 'ltms_co_rete_iva',          0.15  );
$impoconsumo      = (float) get_option( 'ltms_co_impoconsumo',       0.08  );
$decreto_ref      = get_option( 'ltms_co_decreto_ref',    '' );
$vigencia_desde   = get_option( 'ltms_co_vigencia_desde', '' );

$sagrilaft_cop    = number_format( $uvt * $sagrilaft_uvt, 0, ',', '.' );
$umbral_cmp_cop   = number_format( $uvt * $umbral_cmp,   0, ',', '.' );
$umbral_svc_cop   = number_format( $uvt * $umbral_svc,   0, ',', '.' );

$hist_url = admin_url( 'admin.php?page=ltms-fiscal-co&tab=history' );
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <h1>🇨🇴 <?php esc_html_e( 'Configuracion Fiscal — Colombia', 'ltms' ); ?></h1>
        <a href="<?php echo esc_url( $hist_url ); ?>" class="ltms-btn ltms-btn-outline ltms-btn-sm">
            📋 <?php esc_html_e( 'Ver historial de cambios', 'ltms' ); ?>
        </a>
    </div>

    <p style="color:#6b7280;margin-bottom:20px;">
        <?php esc_html_e( 'Actualiza las tasas tributarias vigentes. Los cambios se registran en el historial con el decreto de referencia.', 'ltms' ); ?>
    </p>

    <?php if ( $notice ) : ?>
    <div class="notice notice-success is-dismissible" style="margin-bottom:20px;">
        <p><?php echo esc_html( $notice ); ?></p>
    </div>
    <?php endif; ?>

    <!-- Stats resumen -->
    <div class="ltms-stats-grid" style="margin-bottom:24px;">
        <div class="ltms-stat-card">
            <span class="ltms-stat-label">UVT <?php echo esc_html( gmdate( 'Y' ) ); ?></span>
            <span class="ltms-stat-value">$<?php echo esc_html( number_format( $uvt, 0, ',', '.' ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label">IVA General</span>
            <span class="ltms-stat-value"><?php echo esc_html( number_format( $iva_gen * 100, 0 ) ); ?>%</span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label">ReteFuente Servicios</span>
            <span class="ltms-stat-value"><?php echo esc_html( number_format( $rete_svc * 100, 1 ) ); ?>%</span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label">SAGRILAFT umbral</span>
            <span class="ltms-stat-value" style="font-size:1rem;">$<?php echo esc_html( $sagrilaft_cop ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label">ReteIVA</span>
            <span class="ltms-stat-value"><?php echo esc_html( number_format( $rete_iva * 100, 0 ) ); ?>%</span>
        </div>
    </div>

    <form method="post">
        <?php wp_nonce_field( 'ltms_fiscal_co' ); ?>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

            <!-- Columna izquierda -->
            <div>

                <!-- Decreto -->
                <div class="ltms-form-section" style="margin-bottom:20px;">
                    <h3 style="margin-top:0;">📄 <?php esc_html_e( 'Referencia del decreto', 'ltms' ); ?></h3>
                    <div class="ltms-form-field">
                        <label><?php esc_html_e( 'Decreto / Resolucion', 'ltms' ); ?></label>
                        <input type="text" name="ltms_co_decreto_ref"
                               value="<?php echo esc_attr( $decreto_ref ); ?>"
                               placeholder="<?php esc_attr_e( 'Ej: Decreto 2229/2024', 'ltms' ); ?>"
                               style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                    </div>
                    <div class="ltms-form-field" style="margin-top:12px;">
                        <label><?php esc_html_e( 'Vigencia desde', 'ltms' ); ?></label>
                        <input type="date" name="ltms_co_vigencia_desde"
                               value="<?php echo esc_attr( $vigencia_desde ); ?>"
                               style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                    </div>
                </div>

                <!-- UVT + SAGRILAFT -->
                <div class="ltms-form-section" style="margin-bottom:20px;">
                    <h3 style="margin-top:0;">💰 <?php esc_html_e( 'Unidad de Valor Tributario (UVT)', 'ltms' ); ?></h3>
                    <div class="ltms-form-field">
                        <label><?php esc_html_e( 'Valor UVT (COP)', 'ltms' ); ?></label>
                        <input type="number" name="ltms_co_uvt" step="1" min="0"
                               value="<?php echo esc_attr( $uvt ); ?>"
                               style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                        <span class="description">UVT 2026 = $52.752 (Resolucion DIAN 000187/2025)</span>
                    </div>
                    <div class="ltms-form-field" style="margin-top:12px;">
                        <label><?php esc_html_e( 'Umbral SAGRILAFT (# UVT)', 'ltms' ); ?></label>
                        <input type="number" name="ltms_co_sagrilaft_uvt" step="1" min="0"
                               value="<?php echo esc_attr( $sagrilaft_uvt ); ?>"
                               style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                        <span class="description">
                            <?php printf(
                                esc_html__( 'Retiros >= (UVT x este valor) = alerta SAGRILAFT. Actual: %s UVT = ~$%s COP', 'ltms' ),
                                number_format( $sagrilaft_uvt, 0 ),
                                $sagrilaft_cop
                            ); ?>
                        </span>
                    </div>
                </div>

                <!-- IVA -->
                <div class="ltms-form-section">
                    <h3 style="margin-top:0;">🧾 <?php esc_html_e( 'IVA', 'ltms' ); ?></h3>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="ltms-form-field">
                            <label><?php esc_html_e( 'IVA General (decimal)', 'ltms' ); ?></label>
                            <input type="number" name="ltms_co_iva_general" step="0.001" min="0" max="1"
                                   value="<?php echo esc_attr( $iva_gen ); ?>"
                                   style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                            <span class="description"><?php echo esc_html( number_format( $iva_gen * 100, 0 ) . '% = ' . $iva_gen ); ?></span>
                        </div>
                        <div class="ltms-form-field">
                            <label><?php esc_html_e( 'IVA Reducido (decimal)', 'ltms' ); ?></label>
                            <input type="number" name="ltms_co_iva_reducido" step="0.001" min="0" max="1"
                                   value="<?php echo esc_attr( $iva_red ); ?>"
                                   style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                            <span class="description"><?php echo esc_html( number_format( $iva_red * 100, 0 ) . '% = ' . $iva_red ); ?></span>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Columna derecha -->
            <div>

                <!-- ReteFuente -->
                <div class="ltms-form-section" style="margin-bottom:20px;">
                    <h3 style="margin-top:0;">✂️ <?php esc_html_e( 'Retencion en la Fuente', 'ltms' ); ?></h3>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="ltms-form-field">
                            <label><?php esc_html_e( 'Honorarios (decimal)', 'ltms' ); ?></label>
                            <input type="number" name="ltms_co_rete_honorarios" step="0.001" min="0" max="1"
                                   value="<?php echo esc_attr( $rete_hon ); ?>"
                                   style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                            <span class="description"><?php echo esc_html( number_format( $rete_hon * 100, 1 ) . '% = ' . $rete_hon ); ?></span>
                        </div>
                        <div class="ltms-form-field">
                            <label><?php esc_html_e( 'Servicios (decimal)', 'ltms' ); ?></label>
                            <input type="number" name="ltms_co_rete_servicios" step="0.001" min="0" max="1"
                                   value="<?php echo esc_attr( $rete_svc ); ?>"
                                   style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                            <span class="description"><?php echo esc_html( number_format( $rete_svc * 100, 1 ) . '% = ' . $rete_svc ); ?></span>
                        </div>
                        <div class="ltms-form-field">
                            <label><?php esc_html_e( 'Compras (decimal)', 'ltms' ); ?></label>
                            <input type="number" name="ltms_co_rete_compras" step="0.001" min="0" max="1"
                                   value="<?php echo esc_attr( $rete_cmp ); ?>"
                                   style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                            <span class="description"><?php echo esc_html( number_format( $rete_cmp * 100, 1 ) . '% = ' . $rete_cmp ); ?></span>
                        </div>
                        <div class="ltms-form-field">
                            <label><?php esc_html_e( 'Servicios tecnologicos (decimal)', 'ltms' ); ?></label>
                            <input type="number" name="ltms_co_rete_tech" step="0.001" min="0" max="1"
                                   value="<?php echo esc_attr( $rete_tech ); ?>"
                                   style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                            <span class="description">
                                <?php echo esc_html( number_format( $rete_tech * 100, 1 ) . '% = ' . $rete_tech ); ?>
                                — <?php esc_html_e( 'Art. 365 ET (3.5% plataformas digitales)', 'ltms' ); ?>
                            </span>
                        </div>
                        <div class="ltms-form-field">
                            <label><?php esc_html_e( 'Umbral compras (# UVT)', 'ltms' ); ?></label>
                            <input type="number" name="ltms_co_rete_umbral_compras" step="0.001" min="0"
                                   value="<?php echo esc_attr( $umbral_cmp ); ?>"
                                   style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                            <span class="description">
                                UVT x <?php echo esc_html( $umbral_cmp ); ?> = ~$<?php echo esc_html( $umbral_cmp_cop ); ?> COP.
                                <?php esc_html_e( 'Default: 27 UVT', 'ltms' ); ?>
                            </span>
                        </div>
                        <div class="ltms-form-field">
                            <label><?php esc_html_e( 'Umbral servicios (# UVT)', 'ltms' ); ?></label>
                            <input type="number" name="ltms_co_rete_umbral_servicios" step="0.001" min="0"
                                   value="<?php echo esc_attr( $umbral_svc ); ?>"
                                   style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                            <span class="description">
                                UVT x <?php echo esc_html( $umbral_svc ); ?> = ~$<?php echo esc_html( $umbral_svc_cop ); ?> COP.
                                <?php esc_html_e( 'Default: 4 UVT', 'ltms' ); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- ReteIVA + Impoconsumo -->
                <div class="ltms-form-section">
                    <h3 style="margin-top:0;">🔄 <?php esc_html_e( 'ReteIVA e Impoconsumo', 'ltms' ); ?></h3>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="ltms-form-field">
                            <label><?php esc_html_e( 'ReteIVA (decimal)', 'ltms' ); ?></label>
                            <input type="number" name="ltms_co_rete_iva" step="0.001" min="0" max="1"
                                   value="<?php echo esc_attr( $rete_iva ); ?>"
                                   style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                            <span class="description"><?php echo esc_html( number_format( $rete_iva * 100, 0 ) . '% del IVA = ' . $rete_iva ); ?></span>
                        </div>
                        <div class="ltms-form-field">
                            <label><?php esc_html_e( 'Impoconsumo (decimal)', 'ltms' ); ?></label>
                            <input type="number" name="ltms_co_impoconsumo" step="0.001" min="0" max="1"
                                   value="<?php echo esc_attr( $impoconsumo ); ?>"
                                   style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                            <span class="description"><?php echo esc_html( number_format( $impoconsumo * 100, 0 ) . '% = ' . $impoconsumo ); ?>
                                (<?php esc_html_e( 'restaurantes, bebidas, etc.', 'ltms' ); ?>)
                            </span>
                        </div>
                    </div>
                </div>

            </div>
        </div><!-- grid -->

        <div style="margin-top:24px;padding-top:16px;border-top:1px solid #e5e7eb;display:flex;gap:12px;align-items:center;">
            <button type="submit" name="ltms_fiscal_co_save" class="ltms-btn ltms-btn-primary">
                💾 <?php esc_html_e( 'Guardar configuracion fiscal Colombia', 'ltms' ); ?>
            </button>
            <a href="<?php echo esc_url( $hist_url ); ?>" style="font-size:13px;color:#6b7280;">
                📋 <?php esc_html_e( 'Ver historial de cambios — Colombia', 'ltms' ); ?> →
            </a>
        </div>

    </form>

</div>
