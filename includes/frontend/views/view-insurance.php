<?php
/**
 * Vista Vendor — Mis Seguros (XCover / Parcel Protection).
 *
 * Muestra al vendedor:
 *  - KPIs: total pólizas, activas, prima acumulada, tasa de reclamación.
 *  - Filtro por estado + búsqueda libre (número de póliza / pedido).
 *  - Cobertura explicada (qué protege cada tipo de póliza).
 *  - Tabla de pólizas con badge CSS, fechas localizadas, exportación CSV.
 *
 * @package LTMS
 * @version 2.9.97
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$vendor_id = get_current_user_id();
if ( ! $vendor_id ) {
    return;
}

global $wpdb;
$table_name   = $wpdb->prefix . 'lt_insurance_policies';
$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ); // phpcs:ignore WordPress.DB

// Estado + tipo labels localizados.
$status_labels = [
    'active'    => __( 'Activa', 'ltms' ),
    'cancelled' => __( 'Cancelada', 'ltms' ),
    'claimed'   => __( 'Reclamada', 'ltms' ),
    'expired'   => __( 'Expirada', 'ltms' ),
];

$type_labels = [
    'parcel_protection'    => __( 'Protección de envío', 'ltms' ),
    'purchase_protection'  => __( 'Protección de compra', 'ltms' ),
    'other'                => __( 'Otro', 'ltms' ),
];

$currency = class_exists( 'LTMS_Core_Config' ) ? LTMS_Core_Config::get_currency() : 'COP';
$fmt      = function ( $v ) use ( $currency ) {
    return '$ ' . number_format( (float) $v, 0, ',', '.' ) . ' ' . $currency;
};

// ── Lectura de datos (sólo si la tabla existe) ─────────────────────────
$policies = [];
$kpis     = [
    'total'       => 0,
    'active'      => 0,
    'claimed'     => 0,
    'premium_sum' => 0.0,
];

if ( $table_exists ) {
    $policies = $wpdb->get_results( // phpcs:ignore WordPress.DB
        $wpdb->prepare(
            "SELECT * FROM `{$table_name}` WHERE vendor_id = %d ORDER BY created_at DESC LIMIT 100", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $vendor_id
        )
    );

    // KPIs agregados (últimos 12 meses para evitar escanear todo el histórico).
    $aggr = $wpdb->get_row( // phpcs:ignore WordPress.DB
        $wpdb->prepare(
            "SELECT
                COUNT(*)                                                AS total,
                SUM(CASE WHEN status = 'active'  THEN 1 ELSE 0 END)    AS active,
                SUM(CASE WHEN status = 'claimed' THEN 1 ELSE 0 END)    AS claimed,
                COALESCE(SUM(premium_amount), 0)                       AS premium_sum
             FROM `{$table_name}`
             WHERE vendor_id = %d
               AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $vendor_id
        ),
        ARRAY_A
    );
    if ( $aggr ) {
        $kpis['total']       = (int) $aggr['total'];
        $kpis['active']      = (int) $aggr['active'];
        $kpis['claimed']     = (int) $aggr['claimed'];
        $kpis['premium_sum'] = (float) $aggr['premium_sum'];
    }
}

$claim_rate = $kpis['total'] > 0 ? round( ( $kpis['claimed'] / $kpis['total'] ) * 100, 1 ) : 0.0;
?>

<div class="ltms-tab-content" id="ltms-tab-insurance">
    <section class="ltms-view ltms-view-insurance">

        <!-- ── Cabecera ───────────────────────────────────────────── -->
        <div class="ltms-view-header">
            <h2><?php esc_html_e( 'Mis Seguros', 'ltms' ); ?></h2>
            <p class="ltms-view-desc">
                <?php esc_html_e( 'Trazabilidad de las pólizas XCover asociadas a tus pedidos. Cada póliza protege el envío contra pérdida, robo o daño durante el transporte.', 'ltms' ); ?>
            </p>
        </div>

        <!-- ── KPIs ───────────────────────────────────────────────── -->
        <div class="ltms-stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:24px;">
            <div class="ltms-stat-card">
                <span class="ltms-stat-label"><?php esc_html_e( 'Pólizas (12 meses)', 'ltms' ); ?></span>
                <span class="ltms-stat-value"><?php echo esc_html( number_format( $kpis['total'] ) ); ?></span>
            </div>
            <div class="ltms-stat-card">
                <span class="ltms-stat-label"><?php esc_html_e( 'Activas', 'ltms' ); ?></span>
                <span class="ltms-stat-value" style="color:#16a34a;"><?php echo esc_html( number_format( $kpis['active'] ) ); ?></span>
            </div>
            <div class="ltms-stat-card">
                <span class="ltms-stat-label"><?php esc_html_e( 'Prima acumulada', 'ltms' ); ?></span>
                <span class="ltms-stat-value"><?php echo esc_html( $fmt( $kpis['premium_sum'] ) ); ?></span>
            </div>
            <div class="ltms-stat-card">
                <span class="ltms-stat-label"><?php esc_html_e( 'Tasa de reclamación', 'ltms' ); ?></span>
                <span class="ltms-stat-value" style="color:<?php echo $claim_rate > 5 ? '#dc2626' : '#6b7280'; ?>;">
                    <?php echo esc_html( number_format( $claim_rate, 1 ) ); ?>%
                </span>
            </div>
        </div>

        <!-- ── Tarjeta informativa: cobertura ─────────────────────── -->
        <details class="ltms-card ltms-coverage-card" style="margin-bottom:24px;padding:16px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;">
            <summary style="cursor:pointer;font-weight:600;color:#374151;">
                <?php esc_html_e( '¿Qué cubren mis pólizas?', 'ltms' ); ?>
            </summary>
            <div style="margin-top:12px;display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;color:#4b5563;font-size:0.9rem;line-height:1.5;">
                <div>
                    <strong style="color:#111827;display:block;margin-bottom:4px;">
                        <?php esc_html_e( 'Protección de envío', 'ltms' ); ?>
                    </strong>
                    <?php esc_html_e( 'Cubre pérdida total, robo o daño del paquete mientras está en tránsito con la transportadora. Vigencia: desde la recolección hasta la entrega al cliente.', 'ltms' ); ?>
                </div>
                <div>
                    <strong style="color:#111827;display:block;margin-bottom:4px;">
                        <?php esc_html_e( 'Protección de compra', 'ltms' ); ?>
                    </strong>
                    <?php esc_html_e( 'Cubre defectos de fabricación o no conformidad del producto durante los 30 días posteriores a la entrega. Requiere evidencia fotográfica.', 'ltms' ); ?>
                </div>
                <div>
                    <strong style="color:#111827;display:block;margin-bottom:4px;">
                        <?php esc_html_e( 'Cómo reclamar', 'ltms' ); ?>
                    </strong>
                    <?php esc_html_e( 'Descarga el certificado, contáctanos con el número de póliza y la evidencia. Respondemos en máximo 5 días hábiles.', 'ltms' ); ?>
                </div>
            </div>
        </details>

        <?php if ( ! $table_exists ) : ?>
            <!-- ── Estado: tabla no existe ─────────────────────────── -->
            <div class="ltms-empty-state" style="text-align:center;padding:60px 24px;">
                <svg class="ltms-empty-icon" width="56" height="56" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                     style="opacity:0.3;color:#6b7280;margin-bottom:16px;">
                    <path d="M12 2L3 7v6c0 5 4 9 9 10 5-1 9-5 9-10V7l-9-5z"/>
                    <path d="M9 12l2 2 4-4"/>
                </svg>
                <h3><?php esc_html_e( 'Seguros no disponibles', 'ltms' ); ?></h3>
                <p><?php esc_html_e( 'El módulo de seguros aún no está activado en tu cuenta. Vuelve más tarde o contáctanos si crees que es un error.', 'ltms' ); ?></p>
            </div>

        <?php elseif ( empty( $policies ) ) : ?>
            <!-- ── Estado: sin pólizas ─────────────────────────────── -->
            <div class="ltms-empty-state" style="text-align:center;padding:60px 24px;">
                <svg class="ltms-empty-icon" width="56" height="56" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                     style="opacity:0.3;color:#6b7280;margin-bottom:16px;">
                    <path d="M12 2L3 7v6c0 5 4 9 9 10 5-1 9-5 9-10V7l-9-5z"/>
                    <path d="M9 12l2 2 4-4"/>
                </svg>
                <h3><?php esc_html_e( 'Aún no tienes pólizas de seguro', 'ltms' ); ?></h3>
                <p><?php esc_html_e( 'Cuando un cliente contrate seguro en uno de tus pedidos, la póliza aparecerá aquí automáticamente.', 'ltms' ); ?></p>
            </div>

        <?php else : ?>
            <!-- ── Barra de filtros ────────────────────────────────── -->
            <div class="ltms-filter-bar" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
                <div style="position:relative;flex:1;min-width:200px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                         style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;pointer-events:none;">
                        <circle cx="11" cy="11" r="8"/>
                        <path d="M21 21l-4.35-4.35"/>
                    </svg>
                    <input type="search" id="ltms-insurance-search"
                           placeholder="<?php esc_attr_e( 'Buscar por # pedido o # póliza...', 'ltms' ); ?>"
                           style="width:100%;padding:8px 12px 8px 34px;border:1px solid #d1d5db;border-radius:8px;font-size:0.9rem;">
                </div>
                <select id="ltms-insurance-status-filter"
                        style="padding:8px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:0.9rem;">
                    <option value=""><?php esc_html_e( 'Todos los estados', 'ltms' ); ?></option>
                    <option value="active"><?php esc_html_e( 'Activas', 'ltms' ); ?></option>
                    <option value="cancelled"><?php esc_html_e( 'Canceladas', 'ltms' ); ?></option>
                    <option value="claimed"><?php esc_html_e( 'Reclamadas', 'ltms' ); ?></option>
                    <option value="expired"><?php esc_html_e( 'Expiradas', 'ltms' ); ?></option>
                </select>
                <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" id="ltms-insurance-export-csv"
                        style="margin-left:auto;">
                    📥 <?php esc_html_e( 'Exportar CSV', 'ltms' ); ?>
                </button>
            </div>

            <!-- ── Tabla de pólizas ────────────────────────────────── -->
            <div class="ltms-table-responsive">
                <table class="ltms-table ltms-insurance-table" id="ltms-insurance-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( '# Pedido', 'ltms' ); ?></th>
                            <th><?php esc_html_e( '# Póliza', 'ltms' ); ?></th>
                            <th><?php esc_html_e( 'Tipo', 'ltms' ); ?></th>
                            <th style="text-align:right;"><?php esc_html_e( 'Prima', 'ltms' ); ?></th>
                            <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                            <th><?php esc_html_e( 'Certificado', 'ltms' ); ?></th>
                            <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $policies as $policy ) :
                            $order_id    = isset( $policy->order_id ) ? (int) $policy->order_id : 0;
                            $policy_num  = isset( $policy->policy_id ) ? $policy->policy_id : ( $policy->policy_number ?? '' );
                            $policy_type = $type_labels[ $policy->insurance_type ?? 'other' ] ?? __( 'Otro', 'ltms' );
                            $prima       = isset( $policy->premium_amount ) ? (float) $policy->premium_amount : 0.0;
                            $status_key  = $policy->status ?? '';
                            $status_lbl  = $status_labels[ $status_key ] ?? ucfirst( $status_key );
                            $cert_url    = $policy->certificate_url ?? '';
                            $created_ts  = $policy->created_at ? strtotime( $policy->created_at ) : 0;
                            $created_lbl = $created_ts ? wp_date( 'd M Y', $created_ts ) : '—';
                            // CSS class for status badge.
                            $badge_cls = [
                                'active'    => 'delivered',
                                'cancelled' => 'cancelled',
                                'claimed'   => 'pending',
                                'expired'   => 'failed',
                            ][ $status_key ] ?? 'pending';
                        ?>
                        <tr data-status="<?php echo esc_attr( $status_key ); ?>"
                            data-search="<?php echo esc_attr( strtolower( '#' . $order_id . ' ' . $policy_num ) ); ?>">
                            <td>
                                <?php if ( $order_id ) : ?>
                                    <a href="<?php echo esc_url( add_query_arg( [ 'tab' => 'orders', 'order_id' => $order_id ] ) ); ?>">
                                        #<?php echo esc_html( $order_id ); ?>
                                    </a>
                                <?php else : ?>
                                    <?php esc_html_e( 'N/D', 'ltms' ); ?>
                                <?php endif; ?>
                            </td>
                            <td><code style="font-size:0.85em;color:#374151;"><?php echo esc_html( $policy_num ?: '—' ); ?></code></td>
                            <td><?php echo esc_html( $policy_type ); ?></td>
                            <td style="text-align:right;font-variant-numeric:tabular-nums;">
                                <?php echo esc_html( $fmt( $prima ) ); ?>
                            </td>
                            <td>
                                <span class="ltms-status-badge <?php echo esc_attr( $badge_cls ); ?>">
                                    <?php echo esc_html( $status_lbl ); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ( $cert_url ) : ?>
                                    <a href="<?php echo esc_url( $cert_url ); ?>" target="_blank" rel="noopener noreferrer"
                                       style="display:inline-flex;align-items:center;gap:4px;color:#2563eb;">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                                            <polyline points="7 10 12 15 17 10"/>
                                            <line x1="12" y1="15" x2="12" y2="3"/>
                                        </svg>
                                        <?php esc_html_e( 'Descargar', 'ltms' ); ?>
                                    </a>
                                <?php else : ?>
                                    <span style="color:#9ca3af;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap;color:#6b7280;"><?php echo esc_html( $created_lbl ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p class="ltms-table-footer-note" style="margin-top:12px;font-size:0.8rem;color:#6b7280;">
                <?php
                printf(
                    /* translators: %d: número de pólizas mostradas */
                    esc_html__( 'Mostrando las %d pólizas más recientes.', 'ltms' ),
                    count( $policies )
                );
                ?>
            </p>
        <?php endif; ?>

    </section>
</div>

<?php
// FASE2B P0 FIX (CSP): inline <script> moved to external assets/js/ltms-insurance-view.js
wp_enqueue_script( 'ltms-insurance-view', LTMS_ASSETS_URL . 'js/ltms-insurance-view.js', [], LTMS_VERSION, true );
wp_add_inline_script( 'ltms-insurance-view', 'document.addEventListener("DOMContentLoaded",function(){var b=document.getElementById("ltms-insurance-export-csv");if(b){b.dataset.filename="seguros_' + new Date().toISOString().slice(0,10) + '.csv";}});', 'before' );
?>
