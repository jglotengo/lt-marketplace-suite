<?php
/**
 * Admin View: Configuracion Fiscal - Mexico
 *
 * @package LTMS
 * @version 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Sin permiso.', 'ltms' ) );

global $wpdb;
$table_tramos = $wpdb->prefix . 'lt_mx_isr_tramos';
$table_ieps   = $wpdb->prefix . 'lt_mx_ieps_rates';
$table_hist   = $wpdb->prefix . 'lt_tax_rates_history';

/* ── Handle save scalar rates ──────────────────────────────────── */
if ( isset( $_POST['ltms_fiscal_mx_save'] ) ) {
    check_admin_referer( 'ltms_fiscal_mx' );
    $fields = [
        'ltms_mx_iva_general'      => (float) ( $_POST['ltms_mx_iva_general']      ?? 0.16 ),
        'ltms_mx_iva_frontera'     => (float) ( $_POST['ltms_mx_iva_frontera']     ?? 0.08 ),
        'ltms_mx_isr_honorarios'   => (float) ( $_POST['ltms_mx_isr_honorarios']   ?? 0.10 ),
        'ltms_mx_retencion_iva_pm' => (float) ( $_POST['ltms_mx_retencion_iva_pm'] ?? 0.1067 ),
    ];
    $decree     = sanitize_text_field( $_POST['decree_reference'] ?? '' );
    $valid_from = sanitize_text_field( $_POST['valid_from'] ?? current_time( 'Y-m-d' ) );
    foreach ( $fields as $key => $new_value ) {
        $old_value = (float) LTMS_Core_Config::get( $key, 0 );
        update_option( $key, $new_value );
        if ( abs( $old_value - $new_value ) > 0.000001 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert( $table_hist, [ 'country' => 'MX', 'rate_key' => $key, 'old_value' => $old_value, 'new_value' => $new_value, 'decree_reference' => $decree, 'changed_by' => get_current_user_id(), 'valid_from' => $valid_from ], [ '%s', '%s', '%f', '%f', '%s', '%d', '%s' ] );
        }
    }
    $notice = __( 'Configuracion fiscal de Mexico guardada.', 'ltms' );
}

/* ── Handle ISR tramo ──────────────────────────────────────────── */
if ( isset( $_POST['ltms_isr_tramo_action'] ) ) {
    check_admin_referer( 'ltms_fiscal_mx' );
    $act = sanitize_key( $_POST['ltms_isr_tramo_action'] );
    if ( $act === 'save' ) {
        $row    = [ 'min_amount' => (float) ( $_POST['tramo_min'] ?? 0 ), 'max_amount' => (float) ( $_POST['tramo_max'] ?? 0 ), 'rate' => (float) ( $_POST['tramo_rate'] ?? 0 ), 'valid_from' => sanitize_text_field( $_POST['tramo_valid_from'] ?? current_time( 'Y-m-d' ) ) ];
        $row_id = (int) ( $_POST['tramo_id'] ?? 0 );
        if ( $row_id > 0 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update( $table_tramos, $row, [ 'id' => $row_id ], [ '%f', '%f', '%f', '%s' ], [ '%d' ] );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert( $table_tramos, $row, [ '%f', '%f', '%f', '%s' ] );
        }
        $notice_tramos = __( 'Tramo ISR guardado.', 'ltms' );
    } elseif ( $act === 'delete' ) {
        $row_id = (int) ( $_POST['tramo_id'] ?? 0 );
        if ( $row_id > 0 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete( $table_tramos, [ 'id' => $row_id ], [ '%d' ] );
            $notice_tramos = __( 'Tramo ISR eliminado.', 'ltms' );
        }
    }
}

/* ── Handle IEPS ───────────────────────────────────────────────── */
if ( isset( $_POST['ltms_ieps_action'] ) ) {
    check_admin_referer( 'ltms_fiscal_mx' );
    $act = sanitize_key( $_POST['ltms_ieps_action'] );
    if ( $act === 'save' ) {
        $row     = [ 'category' => sanitize_text_field( $_POST['ieps_category'] ?? '' ), 'rate' => (float) ( $_POST['ieps_rate'] ?? 0 ), 'unit' => sanitize_text_field( $_POST['ieps_unit'] ?? 'ad_valorem' ), 'valid_from' => sanitize_text_field( $_POST['ieps_valid_from'] ?? current_time( 'Y-m-d' ) ), 'notes' => sanitize_text_field( $_POST['ieps_notes'] ?? '' ) ];
        $ieps_id = (int) ( $_POST['ieps_id'] ?? 0 );
        if ( $ieps_id > 0 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update( $table_ieps, $row, [ 'id' => $ieps_id ], [ '%s', '%f', '%s', '%s', '%s' ], [ '%d' ] );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert( $table_ieps, $row, [ '%s', '%f', '%s', '%s', '%s' ] );
        }
        $notice_ieps = __( 'Tasa IEPS guardada.', 'ltms' );
    } elseif ( $act === 'delete' ) {
        $ieps_id = (int) ( $_POST['ieps_id'] ?? 0 );
        if ( $ieps_id > 0 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete( $table_ieps, [ 'id' => $ieps_id ], [ '%d' ] );
            $notice_ieps = __( 'Tasa IEPS eliminada.', 'ltms' );
        }
    }
}

/* ── Fetch data ────────────────────────────────────────────────── */
// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$tramos = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table_tramos}` ORDER BY min_amount ASC LIMIT %d", 50 ), ARRAY_A );
$ieps_rates = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table_ieps}` ORDER BY category ASC LIMIT %d", 100 ), ARRAY_A );
// phpcs:enable

$v = static function( string $key, float $default ) : float {
    return (float) LTMS_Core_Config::get( $key, $default );
};

$iva_general  = $v( 'ltms_mx_iva_general', 0.16 );
$iva_frontera = $v( 'ltms_mx_iva_frontera', 0.08 );
$isr_honor    = $v( 'ltms_mx_isr_honorarios', 0.10 );
$ret_iva_pm   = $v( 'ltms_mx_retencion_iva_pm', 0.1067 );
$hist_url     = admin_url( 'admin.php?page=ltms-fiscal-history&country=MX' );
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <h1>&#x1F1F2;&#x1F1FD; <?php esc_html_e( 'Configuracion Fiscal - Mexico', 'ltms' ); ?></h1>
        <a href="<?php echo esc_url( $hist_url ); ?>" class="ltms-btn ltms-btn-outline ltms-btn-sm">
            &#x1F4CB; <?php esc_html_e( 'Ver historial de cambios - Mexico', 'ltms' ); ?> &rarr;
        </a>
    </div>

    <!-- ── Stats tarjetas ── -->
    <div class="ltms-stats-grid" style="margin-bottom:24px;">
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'IVA General', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( number_format( $iva_general * 100, 0 ) . '%' ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'IVA Zona Fronteriza', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( number_format( $iva_frontera * 100, 0 ) . '%' ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'ISR Honorarios', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( number_format( $isr_honor * 100, 0 ) . '%' ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Ret. IVA PM', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( number_format( $ret_iva_pm * 100, 2 ) . '%' ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Tramos ISR 113-A', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( count( $tramos ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Categorias IEPS', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( count( $ieps_rates ) ); ?></span>
        </div>
    </div>

    <?php if ( isset( $notice ) ) : ?>
    <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
    <?php endif; ?>

    <!-- ── Layout 2 columnas ── -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start;">

        <!-- Columna izquierda: Referencia + IVA -->
        <div>
            <div class="ltms-form-section" style="margin-bottom:20px;">
                <h3 style="margin-top:0;font-size:1rem;color:#1e293b;border-bottom:1px solid #e5e7eb;padding-bottom:8px;">
                    &#x1F4DC; <?php esc_html_e( 'Referencia legal', 'ltms' ); ?>
                </h3>
                <form method="post" id="ltms-mx-general-form">
                    <?php wp_nonce_field( 'ltms_fiscal_mx' ); ?>
                    <div style="margin-bottom:14px;">
                        <label style="display:block;font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:4px;">
                            <?php esc_html_e( 'Decreto / Articulo', 'ltms' ); ?>
                        </label>
                        <input type="text" name="decree_reference" class="ltms-input" style="width:100%;"
                               placeholder="<?php esc_attr_e( 'Ej: DOF Art. 113-A LISR 2024', 'ltms' ); ?>">
                    </div>
                    <div style="margin-bottom:14px;">
                        <label style="display:block;font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:4px;">
                            <?php esc_html_e( 'Vigencia desde', 'ltms' ); ?>
                        </label>
                        <input type="date" name="valid_from" class="ltms-input"
                               value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>">
                    </div>

                    <h3 style="font-size:1rem;color:#1e293b;border-bottom:1px solid #e5e7eb;padding-bottom:8px;margin-top:20px;">
                        &#x1F4B0; <?php esc_html_e( 'IVA', 'ltms' ); ?>
                    </h3>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
                        <div>
                            <label style="display:block;font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:4px;">
                                <?php esc_html_e( 'IVA General (decimal)', 'ltms' ); ?>
                            </label>
                            <input type="number" name="ltms_mx_iva_general" step="0.01" min="0" max="1"
                                   value="<?php echo esc_attr( $iva_general ); ?>"
                                   class="ltms-input" id="mx-iva-gen"
                                   oninput="document.getElementById('mx-iva-gen-hint').textContent=Math.round(this.value*10000)/100+'% = '+this.value">
                            <small id="mx-iva-gen-hint" style="color:#6b7280;">
                                <?php echo esc_html( number_format( $iva_general * 100, 0 ) . '% = ' . $iva_general ); ?>
                            </small>
                        </div>
                        <div>
                            <label style="display:block;font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:4px;">
                                <?php esc_html_e( 'IVA Zona Fronteriza (decimal)', 'ltms' ); ?>
                            </label>
                            <input type="number" name="ltms_mx_iva_frontera" step="0.01" min="0" max="1"
                                   value="<?php echo esc_attr( $iva_frontera ); ?>"
                                   class="ltms-input" id="mx-iva-fron"
                                   oninput="document.getElementById('mx-iva-fron-hint').textContent=Math.round(this.value*10000)/100+'% = '+this.value">
                            <small id="mx-iva-fron-hint" style="color:#6b7280;">
                                <?php echo esc_html( number_format( $iva_frontera * 100, 0 ) . '% = ' . $iva_frontera ); ?>
                            </small>
                        </div>
                    </div>

                    <h3 style="font-size:1rem;color:#1e293b;border-bottom:1px solid #e5e7eb;padding-bottom:8px;margin-top:20px;">
                        &#x1F4CA; <?php esc_html_e( 'ISR y Retencion IVA PM', 'ltms' ); ?>
                    </h3>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;">
                        <div>
                            <label style="display:block;font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:4px;">
                                <?php esc_html_e( 'ISR Honorarios (decimal)', 'ltms' ); ?>
                            </label>
                            <input type="number" name="ltms_mx_isr_honorarios" step="0.001" min="0" max="1"
                                   value="<?php echo esc_attr( $isr_honor ); ?>"
                                   class="ltms-input" id="mx-isr-hon"
                                   oninput="document.getElementById('mx-isr-hon-hint').textContent=Math.round(this.value*10000)/100+'% = '+this.value">
                            <small id="mx-isr-hon-hint" style="color:#6b7280;">
                                <?php echo esc_html( number_format( $isr_honor * 100, 0 ) . '% = ' . $isr_honor ); ?>
                            </small>
                        </div>
                        <div>
                            <label style="display:block;font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:4px;">
                                <?php esc_html_e( 'Retencion IVA PM (decimal)', 'ltms' ); ?>
                            </label>
                            <input type="number" name="ltms_mx_retencion_iva_pm" step="0.0001" min="0" max="1"
                                   value="<?php echo esc_attr( $ret_iva_pm ); ?>"
                                   class="ltms-input" id="mx-ret-iva"
                                   oninput="document.getElementById('mx-ret-iva-hint').textContent=Math.round(this.value*100000)/1000+'% = '+this.value">
                            <small id="mx-ret-iva-hint" style="color:#6b7280;">
                                <?php echo esc_html( number_format( $ret_iva_pm * 100, 2 ) . '% = ' . $ret_iva_pm ); ?>
                            </small>
                            <br><small style="color:#9ca3af;font-size:10px;"><?php esc_html_e( 'Art. 1-A BIS LIVA — 2/3 del IVA 16%', 'ltms' ); ?></small>
                        </div>
                    </div>

                    <button type="submit" name="ltms_fiscal_mx_save" class="ltms-btn ltms-btn-primary" style="width:100%;">
                        &#x1F4BE; <?php esc_html_e( 'Guardar tasas generales Mexico', 'ltms' ); ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- Columna derecha: Tramos ISR 113-A -->
        <div>
            <div class="ltms-form-section">
                <h3 style="margin-top:0;font-size:1rem;color:#1e293b;border-bottom:1px solid #e5e7eb;padding-bottom:8px;">
                    &#x1F4C8; <?php esc_html_e( 'Tramos ISR Art. 113-A (Plataformas digitales)', 'ltms' ); ?>
                </h3>
                <p style="font-size:0.8rem;color:#6b7280;margin-bottom:12px;">
                    <?php esc_html_e( 'Define los rangos de ingreso mensual y sus tasas de retencion ISR.', 'ltms' ); ?>
                </p>

                <?php if ( isset( $notice_tramos ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice_tramos ); ?></p></div>
                <?php endif; ?>

                <!-- Tabla tramos -->
                <table class="ltms-table" style="margin-bottom:16px;">
                    <thead><tr>
                        <th><?php esc_html_e( 'Min (MXN)', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Max (MXN)', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Tasa', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Vigencia', 'ltms' ); ?></th>
                        <th></th>
                    </tr></thead>
                    <tbody>
                    <?php if ( empty( $tramos ) ) : ?>
                    <tr><td colspan="5" style="text-align:center;color:#888;padding:20px;">
                        <?php esc_html_e( 'Sin tramos registrados.', 'ltms' ); ?>
                    </td></tr>
                    <?php else : ?>
                    <?php foreach ( $tramos as $tramo ) :
                        $tasa_pct = (float) $tramo['rate'] * 100;
                        $color    = $tasa_pct <= 2 ? '#16a34a' : ( $tasa_pct <= 3 ? '#f59e0b' : '#dc2626' );
                    ?>
                    <tr>
                        <td style="font-size:12px;">$<?php echo esc_html( number_format( (float) $tramo['min_amount'], 0 ) ); ?></td>
                        <td style="font-size:12px;">$<?php echo esc_html( number_format( (float) $tramo['max_amount'], 0 ) ); ?></td>
                        <td><span class="ltms-badge" style="background:<?php echo esc_attr( $color ); ?>20;color:<?php echo esc_attr( $color ); ?>;border:1px solid <?php echo esc_attr( $color ); ?>40;">
                            <?php echo esc_html( number_format( $tasa_pct, 2 ) . '%' ); ?>
                        </span></td>
                        <td style="font-size:11px;color:#6b7280;"><?php echo esc_html( $tramo['valid_from'] ); ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'ltms_fiscal_mx' ); ?>
                                <input type="hidden" name="ltms_isr_tramo_action" value="delete">
                                <input type="hidden" name="tramo_id" value="<?php echo esc_attr( $tramo['id'] ); ?>">
                                <button type="submit" class="ltms-btn ltms-btn-danger ltms-btn-sm"
                                        onclick="return confirm('<?php esc_attr_e( 'Eliminar tramo?', 'ltms' ); ?>')">
                                    &#x1F5D1;
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>

                <!-- Agregar tramo -->
                <details style="margin-top:8px;">
                    <summary style="cursor:pointer;font-size:0.85rem;font-weight:600;color:#2563eb;padding:6px 0;">
                        + <?php esc_html_e( 'Agregar tramo ISR', 'ltms' ); ?>
                    </summary>
                    <form method="post" style="margin-top:12px;display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                        <?php wp_nonce_field( 'ltms_fiscal_mx' ); ?>
                        <input type="hidden" name="ltms_isr_tramo_action" value="save">
                        <input type="hidden" name="tramo_id" value="0">
                        <div>
                            <label style="display:block;font-size:0.75rem;font-weight:600;color:#374151;margin-bottom:3px;"><?php esc_html_e( 'Min (MXN)', 'ltms' ); ?></label>
                            <input type="number" name="tramo_min" step="1" min="0" value="0" class="ltms-input">
                        </div>
                        <div>
                            <label style="display:block;font-size:0.75rem;font-weight:600;color:#374151;margin-bottom:3px;"><?php esc_html_e( 'Max (MXN)', 'ltms' ); ?></label>
                            <input type="number" name="tramo_max" step="1" min="0" value="999999999" class="ltms-input">
                            <small style="color:#9ca3af;font-size:10px;"><?php esc_html_e( 'Usa 999999999 para sin limite.', 'ltms' ); ?></small>
                        </div>
                        <div>
                            <label style="display:block;font-size:0.75rem;font-weight:600;color:#374151;margin-bottom:3px;"><?php esc_html_e( 'Tasa (decimal)', 'ltms' ); ?></label>
                            <input type="number" name="tramo_rate" step="0.0001" min="0" max="1" value="0.02" class="ltms-input">
                            <small style="color:#9ca3af;font-size:10px;"><?php esc_html_e( 'Ej: 2% = 0.02', 'ltms' ); ?></small>
                        </div>
                        <div>
                            <label style="display:block;font-size:0.75rem;font-weight:600;color:#374151;margin-bottom:3px;"><?php esc_html_e( 'Vigencia desde', 'ltms' ); ?></label>
                            <input type="date" name="tramo_valid_from" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" class="ltms-input">
                        </div>
                        <div style="grid-column:1/-1;">
                            <button type="submit" class="ltms-btn ltms-btn-primary ltms-btn-sm" style="width:100%;">
                                + <?php esc_html_e( 'Agregar tramo', 'ltms' ); ?>
                            </button>
                        </div>
                    </form>
                </details>
            </div>
        </div>
    </div>

    <!-- ── IEPS tabla completa ── -->
    <div class="ltms-form-section" style="margin-top:24px;">
        <h3 style="margin-top:0;font-size:1rem;color:#1e293b;border-bottom:1px solid #e5e7eb;padding-bottom:8px;">
            &#x1F6D2; <?php esc_html_e( 'IEPS por categoria de producto', 'ltms' ); ?>
        </h3>

        <?php if ( isset( $notice_ieps ) ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice_ieps ); ?></p></div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start;">

            <!-- Tabla IEPS -->
            <div>
                <table class="ltms-table">
                    <thead><tr>
                        <th><?php esc_html_e( 'Categoria', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Tasa (%)', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Tipo', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Vigencia', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Notas', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Accion', 'ltms' ); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php if ( empty( $ieps_rates ) ) : ?>
                    <tr><td colspan="6" style="text-align:center;color:#888;padding:24px;">
                        <?php esc_html_e( 'Sin tasas IEPS registradas.', 'ltms' ); ?>
                    </td></tr>
                    <?php else : ?>
                    <?php foreach ( $ieps_rates as $ieps ) :
                        $pct   = (float) $ieps['rate'] * 100;
                        $color = $pct >= 100 ? '#dc2626' : ( $pct >= 25 ? '#f59e0b' : '#2563eb' );
                    ?>
                    <tr>
                        <td style="font-family:monospace;font-size:12px;"><?php echo esc_html( $ieps['category'] ); ?></td>
                        <td>
                            <span class="ltms-badge" style="background:<?php echo esc_attr( $color ); ?>15;color:<?php echo esc_attr( $color ); ?>;border:1px solid <?php echo esc_attr( $color ); ?>30;">
                                <?php echo esc_html( number_format( $pct, 2 ) . '%' ); ?>
                            </span>
                        </td>
                        <td style="font-size:11px;color:#6b7280;"><?php echo esc_html( $ieps['unit'] ); ?></td>
                        <td style="font-size:11px;color:#6b7280;"><?php echo esc_html( $ieps['valid_from'] ); ?></td>
                        <td style="font-size:11px;color:#6b7280;"><?php echo esc_html( $ieps['notes'] ?? '' ); ?></td>
                        <td>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field( 'ltms_fiscal_mx' ); ?>
                                <input type="hidden" name="ltms_ieps_action" value="delete">
                                <input type="hidden" name="ieps_id" value="<?php echo esc_attr( $ieps['id'] ); ?>">
                                <button type="submit" class="ltms-btn ltms-btn-danger ltms-btn-sm"
                                        onclick="return confirm('<?php esc_attr_e( 'Eliminar IEPS?', 'ltms' ); ?>')">
                                    &#x1F5D1;
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Formulario agregar IEPS (sticky) -->
            <div class="ltms-form-section" style="position:sticky;top:32px;background:#f8fafc;border:1px solid #e2e8f0;">
                <h4 style="margin:0 0 12px;font-size:0.9rem;color:#1e293b;">
                    + <?php esc_html_e( 'Agregar tasa IEPS', 'ltms' ); ?>
                </h4>
                <form method="post">
                    <?php wp_nonce_field( 'ltms_fiscal_mx' ); ?>
                    <input type="hidden" name="ltms_ieps_action" value="save">
                    <input type="hidden" name="ieps_id" value="0">
                    <div style="margin-bottom:10px;">
                        <label style="display:block;font-size:0.75rem;font-weight:600;color:#374151;margin-bottom:3px;"><?php esc_html_e( 'Categoria del producto', 'ltms' ); ?></label>
                        <input type="text" name="ieps_category" class="ltms-input" style="width:100%;"
                               placeholder="<?php esc_attr_e( 'Ej: bebidas_azucaradas, cigarros', 'ltms' ); ?>">
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px;">
                        <div>
                            <label style="display:block;font-size:0.75rem;font-weight:600;color:#374151;margin-bottom:3px;"><?php esc_html_e( 'Tasa (decimal)', 'ltms' ); ?></label>
                            <input type="number" name="ieps_rate" step="0.001" min="0" max="5" value="0.08" class="ltms-input">
                            <small style="color:#9ca3af;font-size:10px;"><?php esc_html_e( 'Ej: 8% = 0.08', 'ltms' ); ?></small>
                        </div>
                        <div>
                            <label style="display:block;font-size:0.75rem;font-weight:600;color:#374151;margin-bottom:3px;"><?php esc_html_e( 'Tipo de tasa', 'ltms' ); ?></label>
                            <select name="ieps_unit" class="ltms-input" style="width:100%;">
                                <option value="ad_valorem"><?php esc_html_e( 'Ad valorem (%)', 'ltms' ); ?></option>
                                <option value="specific"><?php esc_html_e( 'Especifica ($/unidad)', 'ltms' ); ?></option>
                            </select>
                        </div>
                    </div>
                    <div style="margin-bottom:10px;">
                        <label style="display:block;font-size:0.75rem;font-weight:600;color:#374151;margin-bottom:3px;"><?php esc_html_e( 'Vigencia desde', 'ltms' ); ?></label>
                        <input type="date" name="ieps_valid_from" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" class="ltms-input" style="width:100%;">
                    </div>
                    <div style="margin-bottom:12px;">
                        <label style="display:block;font-size:0.75rem;font-weight:600;color:#374151;margin-bottom:3px;"><?php esc_html_e( 'Notas', 'ltms' ); ?></label>
                        <input type="text" name="ieps_notes" class="ltms-input" style="width:100%;"
                               placeholder="<?php esc_attr_e( 'Ej: Art. 2 LIEPS', 'ltms' ); ?>">
                    </div>
                    <button type="submit" class="ltms-btn ltms-btn-primary" style="width:100%;">
                        + <?php esc_html_e( 'Agregar IEPS', 'ltms' ); ?>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- ── Reporte SAT Art. 30-B ── -->
    <div class="ltms-form-section" style="margin-top:24px;">
        <h3 style="margin-top:0;font-size:1rem;color:#1e293b;border-bottom:1px solid #e5e7eb;padding-bottom:8px;">
            &#x1F4CA; <?php esc_html_e( 'Reporte SAT - Art. 30-B CFF (Acceso en linea)', 'ltms' ); ?>
        </h3>
        <p style="font-size:0.85rem;color:#6b7280;margin-bottom:16px;">
            <?php esc_html_e( 'Genera el reporte de transacciones con todos los campos requeridos por el Art. 30-B CFF y la ficha 168/CFF para acceso en linea del auditor SAT.', 'ltms' ); ?>
        </p>
        <form method="post" style="display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap;">
            <?php wp_nonce_field( 'ltms_fiscal_mx' ); ?>
            <div>
                <label style="display:block;font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:4px;"><?php esc_html_e( 'Periodo (YYYY-MM)', 'ltms' ); ?></label>
                <input type="month" name="sat_periodo" value="<?php echo esc_attr( current_time( 'Y-m' ) ); ?>"
                       style="padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;">
            </div>
            <div>
                <label style="display:block;font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:4px;"><?php esc_html_e( 'Filtrar por RFC vendedor', 'ltms' ); ?></label>
                <input type="text" name="sat_rfc" maxlength="13" style="padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;width:180px;"
                       placeholder="<?php esc_attr_e( 'Opcional - 12 o 13 caracteres', 'ltms' ); ?>">
            </div>
            <button type="submit" name="ltms_sat_report" class="ltms-btn ltms-btn-primary">
                &#x1F4CA; <?php esc_html_e( 'Generar reporte SAT (JSON)', 'ltms' ); ?>
            </button>
        </form>
        <p style="font-size:0.75rem;color:#9ca3af;margin-top:16px;margin-bottom:0;">
            <?php esc_html_e( 'Base normativa MX: LIVA Art. 1-A BIS y 18-B · LISR Art. 113-A · LIEPS Art. 2 · CFF Art. 30-B · RMF 2025 Regla 12.2.10 · Ficha 168/CFF', 'ltms' ); ?>
        </p>
        <a href="<?php echo esc_url( $hist_url ); ?>" style="font-size:0.8rem;color:#2563eb;text-decoration:none;">
            <?php esc_html_e( 'Ver historial de cambios - Mexico', 'ltms' ); ?> &rarr;
        </a>
    </div>

</div>
