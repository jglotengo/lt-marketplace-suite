<?php
/**
 * Vista: Panel del Auditor Externo
 *
 * @package    LTMS\Admin\Views
 * @version    2.3.1
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( 'ltms_access_auditor_dashboard' ) ) {
    wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'ltms' ) );
}

LTMS_Data_Masking::log_auditor_access( 'auditor_dashboard_view' );

global $wpdb;

// ── Filtros ───────────────────────────────────────────────────────────────────
$date_from   = isset( $_GET['date_from'] ) ? sanitize_text_field( $_GET['date_from'] ) : date( 'Y-m-01' );
$date_to     = isset( $_GET['date_to'] )   ? sanitize_text_field( $_GET['date_to'] )   : date( 'Y-m-d' );
$country     = isset( $_GET['country'] )   ? sanitize_text_field( $_GET['country'] )   : '';
$event_level = isset( $_GET['level'] )     ? sanitize_text_field( $_GET['level'] )     : '';
$dt_from     = $date_from . ' 00:00:00';
$dt_to       = $date_to   . ' 23:59:59';

// ── Resumen fiscal ─────────────────────────────────────────────────────────
$country_sql = $country ? "AND country_code = '" . esc_sql( $country ) . "'" : '';
$fiscal = $wpdb->get_row( $wpdb->prepare(
    "SELECT
        COUNT(*)                                             AS total_tx,
        SUM(gross_amount)                                    AS gross,
        SUM(commission_amount)                               AS platform_fee,
        SUM(vendor_amount)                                   AS vendor_net,
        SUM(COALESCE(retefuente_amount, tax_withholding, 0)) AS rete_fuente,
        SUM(COALESCE(isr_amount, 0))                         AS isr,
        SUM(iva_amount)                                      AS iva,
        SUM(COALESCE(reteiva_amount, 0))                     AS reteiva,
        SUM(COALESCE(reteica_amount, 0))                     AS reteica,
        SUM(COALESCE(ieps_amount, 0))                        AS ieps,
        SUM(COALESCE(aranceles_amount, 0))                   AS aranceles,
        SUM(CASE WHEN is_hospedaje = 1 THEN 1 ELSE 0 END)   AS hospedaje_ops,
        SUM(CASE WHEN is_import = 1    THEN 1 ELSE 0 END)   AS import_ops,
        COUNT(DISTINCT vendor_id)                            AS vendors_active
     FROM {$wpdb->prefix}lt_commissions
     WHERE created_at BETWEEN %s AND %s $country_sql",
    $dt_from, $dt_to
), ARRAY_A );
$f = $fiscal ?? [];

// ── SAT log ────────────────────────────────────────────────────────────────
$sat_log = $wpdb->get_results( $wpdb->prepare(
    "SELECT auditor_rfc, auditor_name, access_type, filter_period, filter_vendor, rows_returned, ip_address, accessed_at
     FROM {$wpdb->prefix}lt_sat_online_access
     WHERE accessed_at BETWEEN %s AND %s ORDER BY accessed_at DESC LIMIT 20",
    $dt_from, $dt_to
), ARRAY_A ) ?: [];

// ── DIAN log ───────────────────────────────────────────────────────────────
$dian_log = $wpdb->get_results( $wpdb->prepare(
    "SELECT auditor_nit, auditor_name, access_type, filter_from, filter_vendor, rows_returned, ip_address, accessed_at
     FROM {$wpdb->prefix}lt_dian_online_access
     WHERE accessed_at BETWEEN %s AND %s ORDER BY accessed_at DESC LIMIT 20",
    $dt_from, $dt_to
), ARRAY_A ) ?: [];

// ── Eventos seguridad ──────────────────────────────────────────────────────
$sec_q      = "SELECT severity AS level, event_type, user_id, ip_address, created_at,
               CONCAT(request_method,' ',request_uri) AS summary
               FROM {$wpdb->prefix}lt_security_events
               WHERE created_at BETWEEN %s AND %s";
$sec_params = [ $dt_from, $dt_to ];
if ( $event_level ) { $sec_q .= ' AND severity = %s'; $sec_params[] = $event_level; }
$sec_q           .= ' ORDER BY created_at DESC LIMIT 50';
$security_events  = $wpdb->get_results( $wpdb->prepare( $sec_q, ...$sec_params ), ARRAY_A ) ?: [];

// ── KYC pendiente ──────────────────────────────────────────────────────────
$kyc_pending = $wpdb->get_results( $wpdb->prepare(
    "SELECT k.*, u.display_name, u.user_email,
            um.meta_value AS document_type
     FROM {$wpdb->prefix}lt_vendor_kyc k
     LEFT JOIN {$wpdb->users} u ON u.ID = k.vendor_id
     LEFT JOIN {$wpdb->usermeta} um ON um.user_id = k.vendor_id AND um.meta_key = 'ltms_document_type'
     WHERE k.status IN ('pending','under_review')
       AND k.submitted_at BETWEEN %s AND %s
     ORDER BY k.submitted_at DESC",
    $dt_from, $dt_to
), ARRAY_A ) ?: [];

// ── SAGRILAFT ─────────────────────────────────────────────────────────────
$sagrilaft_uvt   = (float) LTMS_Core_Config::get( 'ltms_uvt_valor', 49799.0 );
$sagrilaft_uvts  = (float) LTMS_Core_Config::get( 'ltms_sagrilaft_uvt_threshold', 10000.0 );
$sagrilaft_floor = $sagrilaft_uvt * $sagrilaft_uvts;
$large_payouts   = $wpdb->get_results( $wpdb->prepare(
    "SELECT p.*, u.display_name, u.user_email
     FROM {$wpdb->prefix}lt_payout_requests p
     LEFT JOIN {$wpdb->users} u ON u.ID = p.vendor_id
     WHERE p.amount >= %f AND p.created_at BETWEEN %s AND %s ORDER BY p.amount DESC",
    $sagrilaft_floor, $dt_from, $dt_to
), ARRAY_A ) ?: [];

// ── Helpers ───────────────────────────────────────────────────────────────
function ltms_money( $v ) { return number_format( (float)( $v ?? 0 ), 2 ); }
function ltms_int( $v )   { return number_format( (int)  ( $v ?? 0 ) ); }
function ltms_level_badge( $level ) {
    $map = [ 'critical' => 'danger', 'high' => 'warning', 'medium' => 'secondary', 'low' => 'info' ];
    $cls = $map[ strtolower( $level ) ] ?? 'info';
    return '<span class="ltms-badge ltms-badge-' . esc_attr( $cls ) . '">' . esc_html( strtoupper( $level ) ) . '</span>';
}

$current_user  = wp_get_current_user();
$auditor_label = esc_html( $current_user->display_name );
?>
<div class="wrap ltms-auditor-panel">

    <!-- ══ HEADER ══════════════════════════════════════════════════════════ -->
    <div class="ltms-page-header">
        <div>
            <h1><?php esc_html_e( 'Panel Auditor LTMS', 'ltms' ); ?></h1>
            <div class="ltms-header-meta">
                <?php esc_html_e( 'Acceso de solo lectura · Registro de transacciones, KYC y eventos de seguridad', 'ltms' ); ?><br>
                <strong><?php echo $auditor_label; ?></strong> &nbsp;·&nbsp; <?php echo esc_html( date_i18n( 'd/m/Y H:i', current_time( 'timestamp' ) ) ); ?>
            </div>
        </div>
        <span class="ltms-readonly-badge">🔒 <?php esc_html_e( 'Solo lectura · Sesión registrada', 'ltms' ); ?></span>
    </div>

    <!-- ══ FILTROS ══════════════════════════════════════════════════════════ -->
    <form method="get" action="" class="ltms-filter-bar">
        <input type="hidden" name="page" value="ltms-auditor">

        <div class="ltms-filter-group">
            <label for="lf-from"><?php esc_html_e( 'Desde', 'ltms' ); ?></label>
            <input type="date" id="lf-from" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
        </div>
        <div class="ltms-filter-group">
            <label for="lf-to"><?php esc_html_e( 'Hasta', 'ltms' ); ?></label>
            <input type="date" id="lf-to" name="date_to" value="<?php echo esc_attr( $date_to ); ?>">
        </div>
        <div class="ltms-filter-group">
            <label for="lf-country"><?php esc_html_e( 'País', 'ltms' ); ?></label>
            <select id="lf-country" name="country">
                <option value=""><?php esc_html_e( 'Todos', 'ltms' ); ?></option>
                <option value="CO" <?php selected( $country, 'CO' ); ?>>🇨🇴 Colombia</option>
                <option value="MX" <?php selected( $country, 'MX' ); ?>>🇲🇽 México</option>
            </select>
        </div>
        <div class="ltms-filter-group">
            <label for="lf-level"><?php esc_html_e( 'Nivel de evento', 'ltms' ); ?></label>
            <select id="lf-level" name="level">
                <option value=""><?php esc_html_e( 'Todos', 'ltms' ); ?></option>
                <option value="critical" <?php selected( $event_level, 'critical' ); ?>>CRITICAL</option>
                <option value="high"     <?php selected( $event_level, 'high' ); ?>>HIGH</option>
                <option value="medium"   <?php selected( $event_level, 'medium' ); ?>>MEDIUM</option>
                <option value="low"      <?php selected( $event_level, 'low' ); ?>>LOW</option>
            </select>
        </div>

        <button type="submit" class="button button-secondary"><?php esc_html_e( 'Filtrar', 'ltms' ); ?></button>
    </form>

    <!-- ══ KPI CARDS ════════════════════════════════════════════════════════ -->
    <div class="ltms-kpi-grid">
        <div class="ltms-kpi-card">
            <div class="ltms-kpi-label"><?php esc_html_e( 'Transacciones', 'ltms' ); ?></div>
            <div class="ltms-kpi-value"><?php echo ltms_int( $f['total_tx'] ?? 0 ); ?></div>
            <div class="ltms-kpi-sub"><?php echo ltms_int( $f['vendors_active'] ?? 0 ); ?> <?php esc_html_e( 'vendedores', 'ltms' ); ?></div>
        </div>
        <div class="ltms-kpi-card">
            <div class="ltms-kpi-label"><?php esc_html_e( 'Bruto vendedor', 'ltms' ); ?></div>
            <div class="ltms-kpi-value"><?php echo ltms_money( $f['gross'] ?? 0 ); ?></div>
        </div>
        <div class="ltms-kpi-card">
            <div class="ltms-kpi-label"><?php esc_html_e( 'Fee plataforma', 'ltms' ); ?></div>
            <div class="ltms-kpi-value"><?php echo ltms_money( $f['platform_fee'] ?? 0 ); ?></div>
        </div>
        <div class="ltms-kpi-card accent-mint">
            <div class="ltms-kpi-label"><?php esc_html_e( 'Neto vendedor', 'ltms' ); ?></div>
            <div class="ltms-kpi-value"><?php echo ltms_money( $f['vendor_net'] ?? 0 ); ?></div>
        </div>
        <div class="ltms-kpi-card accent-mx">
            <div class="ltms-kpi-label"><?php esc_html_e( 'ISR retenido', 'ltms' ); ?></div>
            <div class="ltms-kpi-value"><?php echo ltms_money( $f['isr'] ?? 0 ); ?></div>
            <div class="ltms-kpi-sub">Art. 113-A LISR</div>
        </div>
        <div class="ltms-kpi-card">
            <div class="ltms-kpi-label"><?php esc_html_e( 'IVA', 'ltms' ); ?></div>
            <div class="ltms-kpi-value"><?php echo ltms_money( $f['iva'] ?? 0 ); ?></div>
        </div>
        <div class="ltms-kpi-card">
            <div class="ltms-kpi-label"><?php esc_html_e( 'ReteIVA', 'ltms' ); ?></div>
            <div class="ltms-kpi-value"><?php echo ltms_money( $f['reteiva'] ?? 0 ); ?></div>
        </div>
        <div class="ltms-kpi-card accent-co">
            <div class="ltms-kpi-label"><?php esc_html_e( 'ReteFuente', 'ltms' ); ?></div>
            <div class="ltms-kpi-value"><?php echo ltms_money( $f['rete_fuente'] ?? 0 ); ?></div>
            <div class="ltms-kpi-sub">E.T. Art. 437-2</div>
        </div>
        <div class="ltms-kpi-card">
            <div class="ltms-kpi-label"><?php esc_html_e( 'ReteICA / IEPS', 'ltms' ); ?></div>
            <div class="ltms-kpi-value"><?php echo ltms_money( ( $f['reteica'] ?? 0 ) + ( $f['ieps'] ?? 0 ) ); ?></div>
        </div>
        <div class="ltms-kpi-card">
            <div class="ltms-kpi-label"><?php esc_html_e( 'Aranceles', 'ltms' ); ?></div>
            <div class="ltms-kpi-value"><?php echo ltms_money( $f['aranceles'] ?? 0 ); ?></div>
        </div>
        <div class="ltms-kpi-card accent-warn">
            <div class="ltms-kpi-label"><?php esc_html_e( 'Ops. hospedaje', 'ltms' ); ?></div>
            <div class="ltms-kpi-value"><?php echo ltms_int( $f['hospedaje_ops'] ?? 0 ); ?></div>
            <div class="ltms-kpi-sub">Art. 30-B frac. II g)</div>
        </div>
        <div class="ltms-kpi-card">
            <div class="ltms-kpi-label"><?php esc_html_e( 'Ops. importación', 'ltms' ); ?></div>
            <div class="ltms-kpi-value"><?php echo ltms_int( $f['import_ops'] ?? 0 ); ?></div>
            <div class="ltms-kpi-sub">Art. 30-B frac. II h)</div>
        </div>
    </div>

    <!-- ══ KYC PENDIENTE ════════════════════════════════════════════════════ -->
    <div class="ltms-section-header">
        <span class="ltms-section-icon">📋</span>
        <h2><?php esc_html_e( 'KYC Pendiente de Revisión', 'ltms' ); ?></h2>
        <?php if ( ! empty( $kyc_pending ) ) : ?>
            <span class="ltms-badge ltms-badge-warning"><?php echo count( $kyc_pending ); ?></span>
        <?php endif; ?>
        <span class="ltms-section-desc"><?php esc_html_e( 'Documentos enviados por vendedores en espera de validación', 'ltms' ); ?></span>
    </div>
    <hr class="ltms-section-divider">

    <?php if ( empty( $kyc_pending ) ) : ?>
        <div class="ltms-empty-state">
            <div class="ltms-empty-icon">✅</div>
            <p><?php esc_html_e( 'No hay documentos KYC pendientes en este período.', 'ltms' ); ?></p>
        </div>
    <?php else : ?>
    <div class="ltms-table-wrap">
    <table class="widefat striped">
        <thead><tr>
            <th><?php esc_html_e( 'Vendedor', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Email', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Tipo doc.', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Enviado', 'ltms' ); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ( $kyc_pending as $kyc ) : ?>
            <tr>
                <td><strong><?php echo esc_html( $kyc['display_name'] ); ?></strong></td>
                <td><?php echo esc_html( $kyc['user_email'] ); ?></td>
                <td><?php echo esc_html( $kyc['document_type'] ?? '—' ); ?></td>
                <td><span class="ltms-status-badge ltms-status-<?php echo esc_attr( $kyc['status'] ); ?>"><?php echo esc_html( $kyc['status'] ); ?></span></td>
                <td><?php echo esc_html( $kyc['submitted_at'] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php if ( ! empty( $large_payouts ) ) : ?>
    <!-- ══ SAGRILAFT ══════════════════════════════════════════════════════ -->
    <div class="ltms-section-header">
        <span class="ltms-section-icon">⚠️</span>
        <h2 style="color:#b91c1c"><?php esc_html_e( 'Alertas SAGRILAFT — Retiros de Alto Valor', 'ltms' ); ?></h2>
        <span class="ltms-badge ltms-badge-danger"><?php echo count( $large_payouts ); ?></span>
        <span class="ltms-section-desc"><?php printf( __( 'Umbral: $%s COP (%s UVT)', 'ltms' ), number_format( $sagrilaft_floor, 0, ',', '.' ), number_format( $sagrilaft_uvts, 0 ) ); ?></span>
    </div>
    <hr class="ltms-section-divider" style="background:linear-gradient(90deg,#e11d48 0%,transparent 70%)">
    <div class="ltms-table-wrap">
    <table class="widefat striped">
        <thead><tr>
            <th>ID</th><th><?php esc_html_e( 'Vendedor', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Email', 'ltms' ); ?></th>
            <th class="ltms-num"><?php esc_html_e( 'Monto', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Método', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ( $large_payouts as $p ) : ?>
            <tr class="ltms-row-alert">
                <td>#<?php echo esc_html( $p['id'] ); ?></td>
                <td><strong><?php echo esc_html( $p['display_name'] ); ?></strong></td>
                <td><?php echo esc_html( $p['user_email'] ); ?></td>
                <td class="ltms-num"><strong><?php echo ltms_money( $p['amount'] ); ?></strong></td>
                <td><?php echo esc_html( $p['method'] ?? '—' ); ?></td>
                <td><span class="ltms-status-badge ltms-status-<?php echo esc_attr( $p['status'] ); ?>"><?php echo esc_html( $p['status'] ); ?></span></td>
                <td><?php echo esc_html( $p['created_at'] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <!-- ══ LOG SAT — Ficha 168/CFF ══════════════════════════════════════════ -->
    <div class="ltms-section-header">
        <span class="ltms-section-icon">🇲🇽</span>
        <h2><?php esc_html_e( 'Log de Accesos SAT — Art. 30-B CFF', 'ltms' ); ?></h2>
        <?php if ( $sat_log ) : ?><span class="ltms-badge ltms-badge-info"><?php echo count( $sat_log ); ?></span><?php endif; ?>
        <span class="ltms-section-desc"><?php esc_html_e( 'Registro inmutable · Ficha 168/CFF', 'ltms' ); ?></span>
    </div>
    <hr class="ltms-section-divider" style="background:linear-gradient(90deg,#006847 0%,transparent 70%)">

    <?php if ( empty( $sat_log ) ) : ?>
        <div class="ltms-empty-state"><div class="ltms-empty-icon">📭</div><p><?php esc_html_e( 'No hay accesos SAT registrados en este período.', 'ltms' ); ?></p></div>
    <?php else : ?>
    <div class="ltms-table-wrap">
    <table class="widefat striped">
        <thead><tr>
            <th><?php esc_html_e( 'RFC Auditor', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Nombre', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Tipo acceso', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Período', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'RFC filtrado', 'ltms' ); ?></th>
            <th class="ltms-num"><?php esc_html_e( 'Filas', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'IP', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Fecha / hora', 'ltms' ); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ( $sat_log as $log ) : ?>
            <tr>
                <td><code><?php echo esc_html( $log['auditor_rfc'] ?: '—' ); ?></code></td>
                <td><?php echo esc_html( $log['auditor_name'] ?: '—' ); ?></td>
                <td><code><?php echo esc_html( $log['access_type'] ); ?></code></td>
                <td><?php echo esc_html( $log['filter_period'] ?: '—' ); ?></td>
                <td><?php echo esc_html( $log['filter_vendor'] ?: 'todos' ); ?></td>
                <td class="ltms-num"><?php echo esc_html( $log['rows_returned'] ); ?></td>
                <td><code><?php echo esc_html( $log['ip_address'] ?: '—' ); ?></code></td>
                <td><?php echo esc_html( $log['accessed_at'] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <!-- ══ LOG DIAN ═════════════════════════════════════════════════════════ -->
    <div class="ltms-section-header">
        <span class="ltms-section-icon">🇨🇴</span>
        <h2><?php esc_html_e( 'Log de Accesos DIAN — Información Exógena', 'ltms' ); ?></h2>
        <?php if ( $dian_log ) : ?><span class="ltms-badge ltms-badge-info"><?php echo count( $dian_log ); ?></span><?php endif; ?>
        <span class="ltms-section-desc"><?php esc_html_e( 'Registro inmutable · E.T. Art. 437-2', 'ltms' ); ?></span>
    </div>
    <hr class="ltms-section-divider" style="background:linear-gradient(90deg,#003087 0%,transparent 70%)">

    <?php if ( empty( $dian_log ) ) : ?>
        <div class="ltms-empty-state"><div class="ltms-empty-icon">📭</div><p><?php esc_html_e( 'No hay accesos DIAN registrados en este período.', 'ltms' ); ?></p></div>
    <?php else : ?>
    <div class="ltms-table-wrap">
    <table class="widefat striped">
        <thead><tr>
            <th><?php esc_html_e( 'NIT Auditor', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Nombre', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Tipo acceso', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Desde', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'NIT filtrado', 'ltms' ); ?></th>
            <th class="ltms-num"><?php esc_html_e( 'Filas', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'IP', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Fecha / hora', 'ltms' ); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ( $dian_log as $log ) : ?>
            <tr>
                <td><code><?php echo esc_html( $log['auditor_nit'] ?: '—' ); ?></code></td>
                <td><?php echo esc_html( $log['auditor_name'] ?: '—' ); ?></td>
                <td><code><?php echo esc_html( $log['access_type'] ); ?></code></td>
                <td><?php echo esc_html( $log['filter_from'] ?: '—' ); ?></td>
                <td><?php echo esc_html( $log['filter_vendor'] ?: 'todos' ); ?></td>
                <td class="ltms-num"><?php echo esc_html( $log['rows_returned'] ); ?></td>
                <td><code><?php echo esc_html( $log['ip_address'] ?: '—' ); ?></code></td>
                <td><?php echo esc_html( $log['accessed_at'] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <!-- ══ EVENTOS DE SEGURIDAD ═════════════════════════════════════════════ -->
    <div class="ltms-section-header">
        <span class="ltms-section-icon">🛡️</span>
        <h2><?php esc_html_e( 'Registro de Eventos de Seguridad', 'ltms' ); ?></h2>
        <?php if ( $security_events ) : ?><span class="ltms-badge ltms-badge-secondary"><?php echo count( $security_events ); ?></span><?php endif; ?>
        <span class="ltms-section-desc"><?php esc_html_e( 'Log forense inmutable · últimos 50 eventos', 'ltms' ); ?></span>
    </div>
    <hr class="ltms-section-divider">

    <?php if ( empty( $security_events ) ) : ?>
        <div class="ltms-empty-state"><div class="ltms-empty-icon">✅</div><p><?php esc_html_e( 'No hay eventos de seguridad en este período.', 'ltms' ); ?></p></div>
    <?php else : ?>
    <div class="ltms-table-wrap">
    <table class="widefat striped">
        <thead><tr>
            <th><?php esc_html_e( 'Nivel', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Tipo', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Usuario', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'IP', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Resumen', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ( $security_events as $ev ) : ?>
            <tr>
                <td><?php echo ltms_level_badge( $ev['level'] ); ?></td>
                <td><?php echo esc_html( $ev['event_type'] ); ?></td>
                <td><?php $ud = $ev['user_id'] ? get_userdata( (int) $ev['user_id'] ) : false; echo esc_html( $ud ? $ud->user_login : '—' ); ?></td>
                <td><code><?php echo esc_html( LTMS_Data_Masking::mask_ip( $ev['ip_address'] ) ); ?></code></td>
                <td><?php echo esc_html( wp_trim_words( $ev['summary'] ?? '', 12 ) ); ?></td>
                <td><?php echo esc_html( $ev['created_at'] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <!-- ══ BASE NORMATIVA ═══════════════════════════════════════════════════ -->
    <div class="ltms-norma-footer">
        <strong>📜 Base normativa:</strong>
        LIVA Art. 1-A BIS y 18-B &nbsp;·&nbsp; LISR Art. 113-A &nbsp;·&nbsp; LIEPS Art. 2 &nbsp;·&nbsp;
        CFF Art. 30-B &nbsp;·&nbsp; RMF 2025 Regla 12.2.10 &nbsp;·&nbsp; Ficha 168/CFF &nbsp;·&nbsp;
        E.T. Art. 437-2 (CO) &nbsp;·&nbsp; Res. DIAN 42/2020
    </div>

    <!-- ══ FOOTER ════════════════════════════════════════════════════════════ -->
    <div class="ltms-panel-footer">
        <?php esc_html_e( 'Esta vista es de solo lectura. Todos los accesos son registrados en el log forense inmutable.', 'ltms' ); ?>
    </div>

</div><!-- .wrap.ltms-auditor-panel -->
