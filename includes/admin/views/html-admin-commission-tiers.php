<?php
/**
 * Vista: Admin Commission Tiers - Niveles de Comision
 *
 * @package LTMS
 * @version 2.0.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Sin permiso.', 'ltms' ) );

global $wpdb;
$table = $wpdb->prefix . 'lt_commission_tiers';

/* ── Handle POST ─────────────────────────────────────────────────── */
$notice      = '';
$notice_type = 'success';
if ( isset( $_POST['ltms_tier_action'] ) ) {
    check_admin_referer( 'ltms_commission_tiers' );
    $action  = sanitize_key( $_POST['ltms_tier_action'] );
    $tier_id = (int) ( $_POST['tier_id'] ?? 0 );

    if ( $action === 'save' ) {
        $data = [
            'country'    => strtoupper( sanitize_text_field( $_POST['country']    ?? 'CO' ) ),
            'min_amount' => (float) ( $_POST['min_amount'] ?? 0 ),
            'max_amount' => (float) ( $_POST['max_amount'] ?? 0 ),
            'rate'       => (float) ( $_POST['rate']       ?? 0 ),
            'label'      => sanitize_text_field( $_POST['label']      ?? '' ),
            'currency'   => strtoupper( sanitize_text_field( $_POST['currency']   ?? 'COP' ) ),
            'is_active'  => isset( $_POST['is_active'] ) ? 1 : 0,
            'sort_order' => (int) ( $_POST['sort_order'] ?? 0 ),
        ];
        if ( $tier_id > 0 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update( $table, $data, [ 'id' => $tier_id ], [ '%s','%f','%f','%f','%s','%s','%d','%d' ], [ '%d' ] );
            $notice = __( 'Nivel actualizado correctamente.', 'ltms' );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert( $table, $data, [ '%s','%f','%f','%f','%s','%s','%d','%d' ] );
            $notice = __( 'Nuevo nivel creado correctamente.', 'ltms' );
        }
    } elseif ( $action === 'delete' && $tier_id > 0 ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete( $table, [ 'id' => $tier_id ], [ '%d' ] );
        $notice = __( 'Nivel eliminado.', 'ltms' );
    }
}

/* ── Fetch tiers ─────────────────────────────────────────────────── */
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$tiers = $wpdb->get_results(
    $wpdb->prepare( "SELECT * FROM `{$table}` ORDER BY country ASC, sort_order ASC LIMIT %d", 500 ),
    ARRAY_A
);

/* ── Stats ───────────────────────────────────────────────────────── */
$total_tiers  = count( $tiers );
$active_tiers = count( array_filter( $tiers, fn( $t ) => (int) $t['is_active'] === 1 ) );
$countries    = array_unique( array_column( $tiers, 'country' ) );
$by_country   = [];
foreach ( $tiers as $t ) {
    $by_country[ $t['country'] ][] = $t;
}

/* ── Edit mode ───────────────────────────────────────────────────── */
$editing = null;
if ( isset( $_GET['edit'] ) ) { // phpcs:ignore
    $edit_id = (int) $_GET['edit']; // phpcs:ignore
    foreach ( $tiers as $t ) {
        if ( (int) $t['id'] === $edit_id ) { $editing = $t; break; }
    }
}

$base_url = admin_url( 'admin.php?page=ltms-commission-tiers' );
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <h1>📊 <?php esc_html_e( 'Niveles de Comision', 'ltms' ); ?></h1>
    </div>

    <?php if ( $notice ) : ?>
    <div class="notice notice-<?php echo esc_attr( $notice_type ); ?> is-dismissible" style="margin-bottom:16px;">
        <p><?php echo esc_html( $notice ); ?></p>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="ltms-stats-grid" style="margin-bottom:24px;">
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Total niveles', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( $total_tiers ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Niveles activos', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#16a34a;"><?php echo esc_html( $active_tiers ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Paises configurados', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( count( $countries ) ); ?></span>
        </div>
        <?php foreach ( $by_country as $country => $ctiers ) : ?>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php echo esc_html( $country ); ?> — <?php esc_html_e( 'niveles', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( count( $ctiers ) ); ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <div style="display:grid;grid-template-columns:1fr 380px;gap:24px;align-items:start;" class="ltms-commission-tiers-grid">

        <!-- ── Tabla de niveles ── -->
        <div class="ltms-table-wrap">
            <div class="ltms-table-title" style="display:flex;justify-content:space-between;align-items:center;">
                <span><?php esc_html_e( 'Niveles configurados', 'ltms' ); ?></span>
                <a href="<?php echo esc_url( $base_url ); ?>#ltms-tier-form" class="ltms-btn ltms-btn-primary ltms-btn-sm">
                    + <?php esc_html_e( 'Agregar nivel', 'ltms' ); ?>
                </a>
            </div>
            <table class="ltms-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Pais', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Volumen min.', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Volumen max.', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Tasa (%)', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Etiqueta', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Moneda', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Activo', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Orden', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( empty( $tiers ) ) : ?>
                <tr><td colspan="9" style="text-align:center;padding:30px;color:#888;">
                    <?php esc_html_e( 'No hay niveles configurados. Agrega el primero.', 'ltms' ); ?>
                </td></tr>
                <?php else : ?>
                <?php
                $prev_country = null;
                foreach ( $tiers as $tier ) :
                    $is_new_country = $tier['country'] !== $prev_country;
                    $prev_country   = $tier['country'];
                ?>
                <?php if ( $is_new_country && count( $countries ) > 1 ) : ?>
                <tr style="background:#f0f4ff;">
                    <td colspan="9" style="padding:6px 12px;font-weight:700;font-size:12px;color:#3b82f6;letter-spacing:1px;">
                        🌎 <?php echo esc_html( $tier['country'] ); ?>
                        <span style="font-weight:400;color:#6b7280;margin-left:8px;">
                            — <?php echo esc_html( $tier['currency'] ); ?>
                        </span>
                    </td>
                </tr>
                <?php endif; ?>
                <tr <?php echo $editing && (int) $editing['id'] === (int) $tier['id'] ? 'style="background:#fffbeb;"' : ''; ?>>
                    <td>
                        <span class="ltms-badge ltms-badge-info" style="font-size:11px;">
                            <?php echo esc_html( $tier['country'] ); ?>
                        </span>
                    </td>
                    <td style="font-family:monospace;"><?php echo esc_html( number_format( (float) $tier['min_amount'], 0, ',', '.' ) ); ?></td>
                    <td style="font-family:monospace;"><?php echo esc_html( number_format( (float) $tier['max_amount'], 0, ',', '.' ) ); ?></td>
                    <td>
                        <strong style="color:<?php echo (float) $tier['rate'] <= 6 ? '#16a34a' : ( (float) $tier['rate'] <= 10 ? '#f59e0b' : '#dc2626' ); ?>;">
                            <?php echo esc_html( number_format( (float) $tier['rate'], 2 ) ); ?>%
                        </strong>
                    </td>
                    <td><?php echo esc_html( $tier['label'] ); ?></td>
                    <td><?php echo esc_html( $tier['currency'] ); ?></td>
                    <td>
                        <?php if ( (int) $tier['is_active'] ) : ?>
                        <span class="ltms-badge ltms-badge-success">✓ Activo</span>
                        <?php else : ?>
                        <span class="ltms-badge ltms-badge-danger">Inactivo</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;"><?php echo esc_html( $tier['sort_order'] ); ?></td>
                    <td style="display:flex;gap:4px;">
                        <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'ltms-commission-tiers', 'edit' => $tier['id'] ], admin_url( 'admin.php' ) ) . '#ltms-tier-form' ); ?>"
                           class="ltms-btn ltms-btn-outline ltms-btn-sm">
                            ✏ <?php esc_html_e( 'Editar', 'ltms' ); ?>
                        </a>
                        <form method="post" style="display:inline;"
                              onsubmit="return confirm('<?php esc_attr_e( '¿Eliminar este nivel?', 'ltms' ); ?>')">
                            <?php wp_nonce_field( 'ltms_commission_tiers' ); ?>
                            <input type="hidden" name="ltms_tier_action" value="delete">
                            <input type="hidden" name="tier_id" value="<?php echo esc_attr( $tier['id'] ); ?>">
                            <button type="submit" class="ltms-btn ltms-btn-danger ltms-btn-sm">
                                🗑 <?php esc_html_e( 'Eliminar', 'ltms' ); ?>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- ── Formulario agregar / editar ── -->
        <div class="ltms-form-section" id="ltms-tier-form" style="position:sticky;top:32px;">
            <h3 style="margin-top:0;">
                <?php echo $editing ? esc_html__( '✏ Editar nivel', 'ltms' ) : esc_html__( '+ Agregar nuevo nivel', 'ltms' ); ?>
            </h3>
            <form method="post">
                <?php wp_nonce_field( 'ltms_commission_tiers' ); ?>
                <input type="hidden" name="ltms_tier_action" value="save">
                <input type="hidden" name="tier_id" value="<?php echo esc_attr( $editing ? $editing['id'] : 0 ); ?>">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">

                    <div class="ltms-form-field">
                        <label><?php esc_html_e( 'Pais', 'ltms' ); ?> <span style="color:#dc2626">*</span></label>
                        <select name="country" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                            <?php foreach ( [ 'CO' => 'Colombia (CO)', 'MX' => 'Mexico (MX)', 'US' => 'USA (US)', 'AR' => 'Argentina (AR)', 'CL' => 'Chile (CL)' ] as $code => $label ) : ?>
                            <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $editing['country'] ?? 'CO', $code ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="ltms-form-field">
                        <label><?php esc_html_e( 'Moneda', 'ltms' ); ?> <span style="color:#dc2626">*</span></label>
                        <select name="currency" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                            <?php foreach ( [ 'COP' => 'COP', 'MXN' => 'MXN', 'USD' => 'USD', 'ARS' => 'ARS', 'CLP' => 'CLP' ] as $code => $label ) : ?>
                            <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $editing['currency'] ?? 'COP', $code ); ?>>
                                <?php echo esc_html( $label ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="ltms-form-field">
                        <label><?php esc_html_e( 'Volumen min.', 'ltms' ); ?></label>
                        <input type="number" name="min_amount" step="0.01" min="0"
                               value="<?php echo esc_attr( $editing['min_amount'] ?? 0 ); ?>"
                               style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                    </div>

                    <div class="ltms-form-field">
                        <label><?php esc_html_e( 'Volumen max.', 'ltms' ); ?></label>
                        <input type="number" name="max_amount" step="0.01" min="0"
                               value="<?php echo esc_attr( $editing['max_amount'] ?? 0 ); ?>"
                               style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                    </div>

                    <div class="ltms-form-field">
                        <label><?php esc_html_e( 'Tasa (%)', 'ltms' ); ?> <span style="color:#dc2626">*</span></label>
                        <input type="number" name="rate" step="0.01" min="0" max="100"
                               value="<?php echo esc_attr( $editing['rate'] ?? 10 ); ?>"
                               style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                    </div>

                    <div class="ltms-form-field">
                        <label><?php esc_html_e( 'Orden', 'ltms' ); ?></label>
                        <input type="number" name="sort_order" min="0"
                               value="<?php echo esc_attr( $editing['sort_order'] ?? 0 ); ?>"
                               style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                    </div>
                </div>

                <div class="ltms-form-field" style="margin-top:12px;">
                    <label><?php esc_html_e( 'Etiqueta', 'ltms' ); ?></label>
                    <input type="text" name="label"
                           value="<?php echo esc_attr( $editing['label'] ?? '' ); ?>"
                           placeholder="<?php esc_attr_e( 'Ej: Tier 1 — Inicio', 'ltms' ); ?>"
                           style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;">
                </div>

                <div style="display:flex;align-items:center;gap:8px;margin:12px 0;">
                    <input type="checkbox" name="is_active" id="tier_is_active" value="1"
                           <?php checked( $editing ? (int) $editing['is_active'] : 1, 1 ); ?>>
                    <label for="tier_is_active" style="margin:0;font-weight:500;">
                        <?php esc_html_e( 'Nivel activo', 'ltms' ); ?>
                    </label>
                </div>

                <div style="display:flex;gap:8px;margin-top:16px;">
                    <button type="submit" class="ltms-btn ltms-btn-primary">
                        <?php echo $editing ? esc_html__( '💾 Guardar cambios', 'ltms' ) : esc_html__( '+ Crear nivel', 'ltms' ); ?>
                    </button>
                    <?php if ( $editing ) : ?>
                    <a href="<?php echo esc_url( $base_url ); ?>" class="ltms-btn ltms-btn-outline">
                        ✕ <?php esc_html_e( 'Cancelar', 'ltms' ); ?>
                    </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

    </div><!-- grid -->

</div><!-- .wrap -->
