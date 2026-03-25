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

// Resumen fiscal
$fiscal_summary = $wpdb->get_row( $wpdb->prepare(
    "SELECT
        COUNT(*) AS total_transactions,
        SUM(vendor_gross) AS total_gross,
        SUM(platform_fee) AS total_platform_fee,
        SUM(vendor_net) AS total_vendor_net,
        SUM(rete_fuente) AS total_rete_fuente,
        SUM(iva_fee) AS total_iva_fee,
        SUM(rete_ica) AS total_rete_ica,
        SUM(impoconsumo) AS total_impoconsumo
     FROM {$wpdb->prefix}lt_commissions
     WHERE created_at BETWEEN %s AND %s
     " . ( $country ? "AND country = %s" : '' ),
    $date_from . ' 00:00:00',
    $date_to . ' 23:59:59',
    ...( $country ? [ $country ] : [] )
), ARRAY_A );

// Últimos eventos de seguridad
$security_events_query = "
    SELECT level, event_type, user_id, ip_address, created_at, summary
    FROM {$wpdb->prefix}lt_security_events
    WHERE created_at BETWEEN %s AND %s
";
$security_params = [ $date_from . ' 00:00:00', $date_to . ' 23:59:59' ];

if ( $event_level ) {
    $security_events_query .= " AND level = %s";
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
                        <option value="CRITICAL" <?php selected( $event_level, 'CRITICAL' ); ?>>CRITICAL</option>
                        <option value="ERROR"    <?php selected( $event_level, 'ERROR' ); ?>>ERROR</option>
                        <option value="WARNING"  <?php selected( $event_level, 'WARNING' ); ?>>WARNING</option>
                        <option value="INFO"     <?php selected( $event_level, 'INFO' ); ?>>INFO</option>
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
                <th><?php esc_html_e( 'ReteFuente', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'IVA Fee', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'ReteICA', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Impoconsumo', 'ltms' ); ?></th>
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
                <td><?php echo esc_html( number_format( (float) ( $fiscal_summary['total_rete_ica'] ?? 0 ), 2 ) ); ?></td>
                <td><?php echo esc_html( number_format( (float) ( $fiscal_summary['total_impoconsumo'] ?? 0 ), 2 ) ); ?></td>
            </tr>
        </tbody>
    </table>

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
                $level_class = match( strtoupper( $event['level'] ) ) {
                    'CRITICAL' => 'ltms-badge-danger',
                    'ERROR'    => 'ltms-badge-warning',
                    'WARNING'  => 'ltms-badge-secondary',
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
