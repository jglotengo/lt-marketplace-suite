<?php
/**
 * Vista: Panel del Auditor Externo
 *
 * @package    LTMS\Admin\Views
 * @version    1.5.0
 */

defined( 'ABSPATH' ) || exit;

// Verificar permisos de auditor
if ( ! current_user_can( 'ltms_access_auditor_dashboard' ) ) {
    wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'ltms' ) );
}

// Registrar acceso del auditor
LTMS_Data_Masking::log_auditor_access( 'auditor_dashboard_view' );

global $wpdb;

// Filtros
$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( $_GET['date_from'] ) : date( 'Y-m-01' );
$date_to   = isset( $_GET['date_to'] )   ? sanitize_text_field( $_GET['date_to'] )   : date( 'Y-m-d' );
$country   = isset( $_GET['country'] )   ? sanitize_text_field( $_GET['country'] )   : '';
$event_level = isset( $_GET['level'] )   ? sanitize_text_field( $_GET['level'] )     : '';

// Resumen fiscal — incluye campos SAT (Art. 30-B CFF) y DIAN (Exógena)
$fiscal_summary = $wpdb->get_row( $wpdb->prepare(
    "SELECT
        COUNT(*) AS total_transactions,
        SUM(gross_amount)                               AS total_gross,
        SUM(commission_amount)                          AS total_platform_fee,
        SUM(vendor_amount)                              AS total_vendor_net,
        SUM(COALESCE(retefuente_amount, tax_withholding, 0)) AS total_rete_fuente,
        SUM(iva_amount)                                 AS total_iva_fee,
        SUM(COALESCE(reteiva_amount, 0))                AS total_reteiva,
        SUM(COALESCE(reteica_amount, 0))                AS total_rete_ica,
        SUM(COALESCE(impoconsumo_amount, 0))            AS total_impoconsumo,
        SUM(COALESCE(isr_amount, 0))                    AS total_isr,
        SUM(COALESCE(ieps_amount, 0))                   AS total_ieps,
        SUM(COALESCE(aranceles_amount, 0))              AS total_aranceles,
        SUM(CASE WHEN is_hospedaje = 1 THEN 1 ELSE 0 END) AS total_hospedaje_ops,
        SUM(CASE WHEN is_import    = 1 THEN 1 ELSE 0 END) AS total_import_ops,
        COUNT(DISTINCT vendor_id)                       AS total_vendors_active
     FROM {$wpdb->prefix}lt_commissions
     WHERE created_at BETWEEN %s AND %s
     " . ( $country ? "AND country_code = %s" : '' ),
    $date_from . ' 00:00:00',
    $date_to . ' 23:59:59',
    ...( $country ? [ $country ] : [] )
), ARRAY_A );

// Log de accesos SAT (lt_sat_online_access) — últimos 20
$sat_access_log = $wpdb->get_results( $wpdb->prepare(
    "SELECT auditor_rfc, auditor_name, access_type, filter_period, filter_vendor, rows_returned, ip_address, accessed_at
      FROM {$wpdb->prefix}lt_sat_online_access
      WHERE accessed_at BETWEEN %s AND %s
      ORDER BY accessed_at DESC LIMIT 20",
    $date_from . ' 00:00:00',
    $date_to . ' 23:59:59'
), ARRAY_A ) ?: [];

// Log de accesos DIAN (lt_dian_online_access) — últimos 20
$dian_access_log = $wpdb->get_results( $wpdb->prepare(
    "SELECT auditor_nit, auditor_name, access_type, filter_from, filter_vendor, rows_returned, ip_address, accessed_at
      FROM {$wpdb->prefix}lt_dian_online_access
      WHERE accessed_at BETWEEN %s AND %s
      ORDER BY accessed_at DESC LIMIT 20",
    $date_from . ' 00:00:00',
    $date_to . ' 23:59:59'
), ARRAY_A ) ?: [];

// Últimos eventos de seguridad
$security_events_query = "
    SELECT severity AS level, event_type, user_id, ip_address, created_at,
           CONCAT(request_method, ' ', request_uri) AS summary
    FROM {$wpdb->prefix}lt_security_events
    WHERE created_at BETWEEN %s AND %s
";
$security_params = [ $date_from . ' 00:00:00', $date_to . ' 23:59:59' ];

if ( $event_level ) {
    $security_events_query .= " AND severity = %s";
    $security_params[] = $event_level;
}
$security_events_query .= " ORDER BY created_at DESC LIMIT 50";

$security_events = $wpdb->get_results(
    $wpdb->prepare( $security_events_query, ...$security_params ),
    ARRAY_A
);

// KYC pendientes con posibles flags SAGRILAFT
$kyc_flagged = $wpdb->get_results( $wpdb->prepare(
    "SELECT k.*, u.display_name, u.user_email,
            um.meta_value AS document_type
     FROM {$wpdb->prefix}lt_vendor_kyc k
     LEFT JOIN {$wpdb->users} u ON u.ID = k.vendor_id
     LEFT JOIN {$wpdb->usermeta} um ON um.user_id = k.vendor_id AND um.meta_key = 'ltms_document_type'
     WHERE k.status IN ('pending', 'under_review')
       AND k.submitted_at BETWEEN %s AND %s
     ORDER BY k.submitted_at DESC",
    $date_from . ' 00:00:00',
    $date_to . ' 23:59:59'
), ARRAY_A );

// Retiros grandes (> umbral SAGRILAFT configurable: ltms_sagrilaft_uvt_threshold UVT × UVT)
$sagrilaft_uvt   = (float) LTMS_Core_Config::get( 'ltms_uvt_valor', 49799.0 );
$sagrilaft_uvts  = (float) LTMS_Core_Config::get( 'ltms_sagrilaft_uvt_threshold', 10000.0 );
$sagrilaft_floor = $sagrilaft_uvt * $sagrilaft_uvts;

$large_payouts = $wpdb->get_results( $wpdb->prepare(
    "SELECT p.*, u.display_name, u.user_email
     FROM {$wpdb->prefix}lt_payout_requests p
     LEFT JOIN {$wpdb->users} u ON u.ID = p.vendor_id
     WHERE p.amount >= %f
       AND p.created_at BETWEEN %s AND %s
     ORDER BY p.amount DESC",
    $sagrilaft_floor,
    $date_from . ' 00:00:00',
    $date_to . ' 23:59:59'
), ARRAY_A );
?>

<div class="wrap ltms-auditor-panel">

    <h1><?php esc_html_e( 'Panel Auditor LTMS', 'ltms' ); ?></h1>
    <p class="description">
        <?php esc_html_e( 'Acceso de solo lectura al registro de transacciones, eventos de seguridad y datos KYC.', 'ltms' ); ?>
        <strong><?php echo esc_html( sprintf( __( 'Auditor: %s | Sesión registrada.', 'ltms' ), wp_get_current_user()->display_name ) ); ?></strong>
    </p>

    <!-- ── Filtros ────────────────────────────────────────────────── -->
    <form method="get" class="ltms-audit-filters">
        <input type="hidden" name="page" value="ltms-auditor">
        <table class="form-table" style="max-width:800px;">
            <tr>
                <th><?php esc_html_e( 'Desde', 'ltms' ); ?></th>
                <td><input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>"></td>
                <th><?php esc_html_e( 'Hasta', 'ltms' ); ?></th>
                <td><input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>"></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'País', 'ltms' ); ?></th>
                <td>
                    <select name="country">
                        <option value=""><?php esc_html_e( 'Todos', 'ltms' ); ?></option>
                        <option value="CO" <?php selected( $country, 'CO' ); ?>>Colombia</option>
                        <option value="MX" <?php selected( $country, 'MX' ); ?>>México</option>
                    </select>
                </td>
                <th><?php esc_html_e( 'Nivel de Evento', 'ltms' ); ?></th>
                <td>
                    <select name="level">
                        <option value=""><?php esc_html_e( 'Todos', 'ltms' ); ?></option>
                        <option value="critical" <?php selected( $event_level, 'critical' ); ?>>CRITICAL</option>
                        <option value="high"     <?php selected( $event_level, 'high' ); ?>>HIGH</option>
                        <option value="medium"   <?php selected( $event_level, 'medium' ); ?>>MEDIUM</option>
                        <option value="low"      <?php selected( $event_level, 'low' ); ?>>LOW</option>
                    </select>
                </td>
            </tr>
        </table>
        <p><?php submit_button( __( 'Filtrar', 'ltms' ), 'secondary', 'filter', false ); ?></p>
    </form>

    <!-- ── Resumen Fiscal ─────────────────────────────────────────── -->
    <h2><?php esc_html_e( 'Resumen Fiscal del Período', 'ltms' ); ?></h2>
    <table class="widefat striped ltms-audit-summary">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Total Transacciones', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Bruto Vendedor', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Fee Plataforma', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Neto Vendedor', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'ReteFuente / ISR', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'IVA', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'ReteIVA', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'ReteICA / IEPS', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Aranceles', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Ops. Hospedaje', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Ops. Importación', 'ltms' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?php echo esc_html( number_format( (int) ( $fiscal_summary['total_transactions'] ?? 0 ) ) ); ?></td>
                <td><?php echo esc_html( number_format( (float) ( $fiscal_summary['total_gross'] ?? 0 ), 2 ) ); ?></td>
                <td><?php echo esc_html( number_format( (float) ( $fiscal_summary['total_platform_fee'] ?? 0 ), 2 ) ); ?></td>
                <td><?php echo esc_html( number_format( (float) ( $fiscal_summary['total_vendor_net'] ?? 0 ), 2 ) ); ?></td>
                <td><?php echo esc_html( number_format( (float) ( $fiscal_summary['total_rete_fuente'] ?? 0 ), 2 ) ); ?></td>
                <td><?php echo esc_html( number_format( (float) ( $fiscal_summary['total_iva_fee'] ?? 0 ), 2 ) ); ?></td>
                <td><?php echo esc_html( number_format( (float) ( $fiscal_summary['total_reteiva'] ?? 0 ), 2 ) ); ?></td>
                <td><?php echo esc_html( number_format( (float) ( $fiscal_summary['total_rete_ica'] ?? 0 ) + (float) ( $fiscal_summary['total_ieps'] ?? 0 ), 2 ) ); ?></td>
                <td><?php echo esc_html( number_format( (float) ( $fiscal_summary['total_aranceles'] ?? 0 ), 2 ) ); ?></td>
                <td><?php echo esc_html( (int) ( $fiscal_summary['total_hospedaje_ops'] ?? 0 ) ); ?></td>
                <td><?php echo esc_html( (int) ( $fiscal_summary['total_import_ops'] ?? 0 ) ); ?></td>
            </tr>
        </tbody>
    </table>

    <!-- ── Log de Accesos SAT (ficha 168/CFF) ─────────────────────── -->
    <?php if ( ! empty( $sat_access_log ) ) : ?>
    <h2><?php esc_html_e( '🇲🇽 Log de Accesos SAT — Art. 30-B CFF (Ficha 168/CFF)', 'ltms' ); ?></h2>
    <p class="description"><?php esc_html_e( 'Registro inmutable de cada consulta del auditor SAT al sistema de acceso en línea.', 'ltms' ); ?></p>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'RFC Auditor', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Nombre', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Tipo acceso', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Período', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'RFC filtrado', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Filas', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'IP', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Fecha/hora', 'ltms' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $sat_access_log as $log ) : ?>
            <tr>
                <td><?php echo esc_html( $log['auditor_rfc'] ?: '—' ); ?></td>
                <td><?php echo esc_html( $log['auditor_name'] ?: '—' ); ?></td>
                <td><code><?php echo esc_html( $log['access_type'] ); ?></code></td>
                <td><?php echo esc_html( $log['filter_period'] ?: '—' ); ?></td>
                <td><?php echo esc_html( $log['filter_vendor'] ?: 'todos' ); ?></td>
                <td><?php echo esc_html( $log['rows_returned'] ); ?></td>
                <td><?php echo esc_html( $log['ip_address'] ?: '—' ); ?></td>
                <td><?php echo esc_html( $log['accessed_at'] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- ── Log de Accesos DIAN (Exógena CO) ──────────────────────── -->
    <?php if ( ! empty( $dian_access_log ) ) : ?>
    <h2><?php esc_html_e( '🇨🇴 Log de Accesos DIAN — Exógena Colombia', 'ltms' ); ?></h2>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'NIT Auditor', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Nombre', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Tipo acceso', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Desde', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'NIT filtrado', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Filas', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'IP', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Fecha/hora', 'ltms' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $dian_access_log as $log ) : ?>
            <tr>
                <td><?php echo esc_html( $log['auditor_nit'] ?: '—' ); ?></td>
                <td><?php echo esc_html( $log['auditor_name'] ?: '—' ); ?></td>
                <td><code><?php echo esc_html( $log['access_type'] ); ?></code></td>
                <td><?php echo esc_html( $log['filter_from'] ?: '—' ); ?></td>
                <td><?php echo esc_html( $log['filter_vendor'] ?: 'todos' ); ?></td>
                <td><?php echo esc_html( $log['rows_returned'] ); ?></td>
                <td><?php echo esc_html( $log['ip_address'] ?: '—' ); ?></td>
                <td><?php echo esc_html( $log['accessed_at'] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- ── Alertas SAGRILAFT ──────────────────────────────────────── -->
    <?php if ( ! empty( $large_payouts ) ) : ?>
    <h2 class="ltms-alert-heading">
        <?php esc_html_e( 'Retiros de Alto Valor (Alertas SAGRILAFT)', 'ltms' ); ?>
        <span class="ltms-badge ltms-badge-danger"><?php echo count( $large_payouts ); ?></span>
    </h2>
    <table class="widefat striped">
        <thead>
            <tr>
                <th>ID</th>
                <th><?php esc_html_e( 'Vendedor', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Email', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Monto', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Método', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $large_payouts as $payout ) : ?>
            <tr>
                <td><?php echo esc_html( $payout['id'] ); ?></td>
                <td><?php echo esc_html( $payout['display_name'] ); ?></td>
                <td><?php echo esc_html( $payout['user_email'] ); ?></td>
                <td><strong><?php echo esc_html( number_format( (float) $payout['amount'], 2 ) ); ?></strong></td>
                <td><?php echo esc_html( $payout['method'] ?? '—' ); ?></td>
                <td><span class="ltms-status-badge ltms-status-<?php echo esc_attr( $payout['status'] ); ?>"><?php echo esc_html( $payout['status'] ); ?></span></td>
                <td><?php echo esc_html( $payout['created_at'] ); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- ── KYC Pendiente ──────────────────────────────────────────── -->
    <h2><?php esc_html_e( 'KYC Pendiente de Revisión', 'ltms' ); ?></h2>
    <?php if ( empty( $kyc_flagged ) ) : ?>
        <p><?php esc_html_e( 'No hay documentos KYC pendientes en este período.', 'ltms' ); ?></p>
    <?php else : ?>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Vendedor', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Email', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Tipo Documento', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Enviado', 'ltms' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $kyc_flagged as $kyc ) : ?>
            <tr>
                <td><?php echo esc_html( $kyc['display_name'] ); ?></td>
                <td><?php echo esc_html( $kyc['user_email'] ); ?></td>
                <td><?php echo esc_html( $kyc['document_type'] ?? '—' ); ?></td>
                <td><span class="ltms-status-badge ltms-status-<?php echo esc_attr( $kyc['status'] ); ?>"><?php echo esc_html( $kyc['status'] ); ?></span></td>
                <td><?php echo esc_html( $kyc['submitted_at'] ); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- ── Eventos de Seguridad ───────────────────────────────────── -->
    <h2><?php esc_html_e( 'Registro de Eventos de Seguridad', 'ltms' ); ?></h2>
    <?php if ( empty( $security_events ) ) : ?>
        <p><?php esc_html_e( 'No hay eventos de seguridad en este período.', 'ltms' ); ?></p>
    <?php else : ?>
    <table class="widefat striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Nivel', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Tipo', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Usuario', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'IP', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Resumen', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $security_events as $event ) :
                $level_class = match( strtolower( $event['level'] ) ) {
                    'critical' => 'ltms-badge-danger',
                    'high'     => 'ltms-badge-warning',
                    'medium'   => 'ltms-badge-secondary',
                    default    => 'ltms-badge-info',
                };
            ?>
            <tr>
                <td><span class="ltms-badge <?php echo esc_attr( $level_class ); ?>"><?php echo esc_html( $event['level'] ); ?></span></td>
                <td><?php echo esc_html( $event['event_type'] ); ?></td>
                <td><?php echo esc_html( $event['user_id'] ? get_userdata( (int) $event['user_id'] )->user_login : '—' ); ?></td>
                <td><?php echo esc_html( LTMS_Data_Masking::mask_ip( $event['ip_address'] ) ); ?></td>
                <td><?php echo esc_html( wp_trim_words( $event['summary'] ?? '', 15 ) ); ?></td>
                <td><?php echo esc_html( $event['created_at'] ); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <p class="description" style="margin-top:20px;">
        <em><?php esc_html_e( 'Esta vista es de solo lectura. Todos los accesos son registrados en el log forense inmutable.', 'ltms' ); ?></em>
    </p>

</div><!-- .ltms-auditor-panel -->
