<?php
/**
 * Vista: Admin Security - Logs de Seguridad y WAF
 *
 * @package LTMS
 * @version 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$nonce = wp_create_nonce( 'ltms_admin_nonce' );

if ( current_user_can( 'manage_options' ) ) :
?>
<div class="notice notice-info" style="padding:12px;">
    <p style="margin:0 0 8px;"><strong>Diagnóstico:</strong> Reset de OPcache (proceso PHP-FPM web real)</p>
    <button type="button" id="ltms-opcache-reset-btn" class="ltms-btn ltms-btn-outline ltms-btn-sm">Limpiar OPcache</button>
    <span id="ltms-opcache-reset-result" style="margin-left:8px;font-size:12px;"></span>
</div>
<script>
(function($){
    $('#ltms-opcache-reset-btn').on('click', function(){
        var $btn = $(this), $res = $('#ltms-opcache-reset-result');
        $btn.prop('disabled', true);
        $res.text('Procesando...');
        $.post(ajaxurl, {
            action: 'ltms_opcache_reset',
            nonce: '<?php echo esc_js( $nonce ); ?>'
        }).done(function(resp){
            $res.text(resp.success ? 'OK — OPcache reiniciado.' : 'Error: ' + (resp.data || ''));
        }).fail(function(){
            $res.text('Error de red.');
        }).always(function(){
            $btn.prop('disabled', false);
        });
    });
})(jQuery);
</script>
<?php endif; ?>

// Acciones manuales
if ( isset( $_POST['ltms_block_ip'] ) && check_admin_referer( 'ltms_security_action' ) ) {
    $ip_to_block = sanitize_text_field( $_POST['block_ip'] ?? '' );
    if ( filter_var( $ip_to_block, FILTER_VALIDATE_IP ) ) {
        LTMS_Core_Firewall::block_ip( $ip_to_block, 'Bloqueada manualmente por admin' );
        echo '<div class="notice notice-success"><p>IP ' . esc_html( $ip_to_block ) . ' bloqueada.</p></div>';
    }
}
if ( isset( $_POST['ltms_unblock_ip'] ) && check_admin_referer( 'ltms_security_action' ) ) {
    $ip_to_unblock = sanitize_text_field( $_POST['unblock_ip'] ?? '' );
    $wpdb->delete( $wpdb->prefix . 'lt_waf_blocked_ips', [ 'ip_address' => $ip_to_unblock ], [ '%s' ] ); // phpcs:ignore
    delete_transient( 'ltms_blocked_' . md5( $ip_to_unblock ) );
    echo '<div class="notice notice-success"><p>IP ' . esc_html( $ip_to_unblock ) . ' desbloqueada.</p></div>';
}

$security_table = $wpdb->prefix . 'lt_security_events';
$blocked_table  = $wpdb->prefix . 'lt_waf_blocked_ips';

$filter_ip   = sanitize_text_field( $_GET['filter_ip']   ?? '' ); // phpcs:ignore
$filter_type = sanitize_key(        $_GET['filter_type'] ?? '' ); // phpcs:ignore
$page_num    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );           // phpcs:ignore
$per_page    = 50;
$offset      = ( $page_num - 1 ) * $per_page;

$where_parts = [];
$where_vals  = [];
if ( $filter_ip ) {
    $where_parts[] = 'ip_address = %s';
    $where_vals[]  = $filter_ip;
}
if ( $filter_type ) {
    $where_parts[] = 'event_type = %s';
    $where_vals[]  = strtoupper( $filter_type );
}
$where_sql = $where_parts ? 'WHERE ' . implode( ' AND ', $where_parts ) : '';

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$total_events = (int) $wpdb->get_var(
    $where_vals
        ? $wpdb->prepare( "SELECT COUNT(*) FROM `{$security_table}` {$where_sql}", ...$where_vals )
        : "SELECT COUNT(*) FROM `{$security_table}`"
);

$security_events = $wpdb->get_results(
    $where_vals
        ? $wpdb->prepare( "SELECT * FROM `{$security_table}` {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d", ...array_merge( $where_vals, [ $per_page, $offset ] ) )
        : $wpdb->prepare( "SELECT * FROM `{$security_table}` ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset ),
    ARRAY_A
);

$blocked_ips = $wpdb->get_results(
    $wpdb->prepare( "SELECT * FROM `{$blocked_table}` WHERE (expires_at IS NULL OR expires_at > NOW()) ORDER BY created_at DESC LIMIT %d", 50 ),
    ARRAY_A
);

$stats = $wpdb->get_row( "SELECT
    COUNT(*) as total,
    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) as last_24h,
    COUNT(DISTINCT ip_address) as unique_ips,
    SUM(CASE WHEN event_type LIKE 'SQL%' THEN 1 ELSE 0 END) as sql_attacks
    FROM `{$security_table}`", ARRAY_A );

$top_ips = $wpdb->get_results(
    $wpdb->prepare( "SELECT ip_address, COUNT(*) as count, MAX(created_at) as last_seen FROM `{$security_table}` GROUP BY ip_address ORDER BY count DESC LIMIT %d", 5 ),
    ARRAY_A
);
// phpcs:enable

$total_pages = max( 1, (int) ceil( $total_events / $per_page ) );
$base_url    = admin_url( 'admin.php?page=ltms-security' );
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <h1>&#x1F6E1; <?php esc_html_e( 'Seguridad / Logs', 'ltms' ); ?></h1>
    </div>

    <div class="ltms-stats-grid" style="margin-bottom:24px;">
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Total ataques', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#dc2626;"><?php echo esc_html( number_format( (int) ( $stats['total'] ?? 0 ) ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Ultimas 24h', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#f59e0b;"><?php echo esc_html( number_format( (int) ( $stats['last_24h'] ?? 0 ) ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'IPs unicas', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( number_format( (int) ( $stats['unique_ips'] ?? 0 ) ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'IPs bloqueadas', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#16a34a;"><?php echo esc_html( count( $blocked_ips ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'SQL Injection', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#dc2626;"><?php echo esc_html( number_format( (int) ( $stats['sql_attacks'] ?? 0 ) ) ); ?></span>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">

        <div class="ltms-form-section">
            <h3 style="margin-top:0;">&#x26A0;&#xFE0F; <?php esc_html_e( 'Top IPs atacantes', 'ltms' ); ?></h3>
            <?php if ( empty( $top_ips ) ) : ?>
            <p style="color:#888;"><?php esc_html_e( 'Sin ataques registrados.', 'ltms' ); ?></p>
            <?php else : ?>
            <table style="width:100%;font-size:0.875rem;border-collapse:collapse;">
                <thead><tr style="background:#f9fafb;">
                    <th style="padding:6px 10px;text-align:left;border-bottom:1px solid #e5e7eb;">IP</th>
                    <th style="padding:6px 10px;text-align:center;border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Ataques', 'ltms' ); ?></th>
                    <th style="padding:6px 10px;text-align:left;border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Ultimo', 'ltms' ); ?></th>
                    <th style="padding:6px 10px;border-bottom:1px solid #e5e7eb;"></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $top_ips as $tip ) :
                    $is_blocked = false;
                    foreach ( $blocked_ips as $bip ) {
                        if ( $bip['ip_address'] === $tip['ip_address'] ) { $is_blocked = true; break; }
                    }
                ?>
                <tr style="border-bottom:1px solid #f3f4f6;">
                    <td style="padding:6px 10px;font-family:monospace;"><?php echo esc_html( $tip['ip_address'] ); ?></td>
                    <td style="padding:6px 10px;text-align:center;">
                        <span class="ltms-badge ltms-badge-danger"><?php echo esc_html( $tip['count'] ); ?></span>
                    </td>
                    <td style="padding:6px 10px;font-size:11px;color:#6b7280;">
                        <?php echo esc_html( $tip['last_seen'] ? gmdate( 'd/m/Y H:i', strtotime( $tip['last_seen'] ) ) : '--' ); ?>
                    </td>
                    <td style="padding:6px 10px;">
                        <?php if ( $is_blocked ) : ?>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field( 'ltms_security_action' ); ?>
                            <input type="hidden" name="unblock_ip" value="<?php echo esc_attr( $tip['ip_address'] ); ?>">
                            <button type="submit" name="ltms_unblock_ip" class="ltms-btn ltms-btn-outline ltms-btn-sm" style="color:#16a34a;border-color:#16a34a;">
                                &#x2713; <?php esc_html_e( 'Desbloqueada', 'ltms' ); ?>
                            </button>
                        </form>
                        <?php else : ?>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field( 'ltms_security_action' ); ?>
                            <input type="hidden" name="block_ip" value="<?php echo esc_attr( $tip['ip_address'] ); ?>">
                            <button type="submit" name="ltms_block_ip" class="ltms-btn ltms-btn-danger ltms-btn-sm">
                                &#x1F6AB; <?php esc_html_e( 'Bloquear', 'ltms' ); ?>
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <div class="ltms-form-section">
            <h3 style="margin-top:0;">&#x1F6AB; <?php esc_html_e( 'Gestion de IPs', 'ltms' ); ?></h3>
            <form method="post" style="display:flex;gap:8px;margin-bottom:16px;">
                <?php wp_nonce_field( 'ltms_security_action' ); ?>
                <input type="text" name="block_ip" placeholder="190.x.x.x"
                       style="flex:1;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-family:monospace;">
                <button type="submit" name="ltms_block_ip" class="ltms-btn ltms-btn-danger ltms-btn-sm">
                    &#x1F6AB; <?php esc_html_e( 'Bloquear IP', 'ltms' ); ?>
                </button>
            </form>
            <?php if ( empty( $blocked_ips ) ) : ?>
            <p style="color:#888;font-size:0.875rem;"><?php esc_html_e( 'No hay IPs bloqueadas actualmente.', 'ltms' ); ?></p>
            <?php else : ?>
            <div style="max-height:200px;overflow-y:auto;">
            <table style="width:100%;font-size:0.8rem;border-collapse:collapse;">
                <thead><tr style="background:#f9fafb;">
                    <th style="padding:5px 8px;text-align:left;border-bottom:1px solid #e5e7eb;">IP</th>
                    <th style="padding:5px 8px;text-align:left;border-bottom:1px solid #e5e7eb;"><?php esc_html_e( 'Expira', 'ltms' ); ?></th>
                    <th style="padding:5px 8px;border-bottom:1px solid #e5e7eb;"></th>
                </tr></thead>
                <tbody>
                <?php foreach ( $blocked_ips as $bip ) : ?>
                <tr style="border-bottom:1px solid #f3f4f6;">
                    <td style="padding:5px 8px;font-family:monospace;"><?php echo esc_html( $bip['ip_address'] ); ?></td>
                    <td style="padding:5px 8px;font-size:10px;color:#6b7280;">
                        <?php echo esc_html( $bip['expires_at'] ? gmdate( 'd/m H:i', strtotime( $bip['expires_at'] ) ) : 'Permanente' ); ?>
                    </td>
                    <td style="padding:5px 8px;">
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field( 'ltms_security_action' ); ?>
                            <input type="hidden" name="unblock_ip" value="<?php echo esc_attr( $bip['ip_address'] ); ?>">
                            <button type="submit" name="ltms_unblock_ip" class="ltms-btn ltms-btn-outline ltms-btn-sm" style="font-size:10px;padding:2px 6px;">
                                <?php esc_html_e( 'Desbloquear', 'ltms' ); ?>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="ltms-table-wrap">
        <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">
            <input type="hidden" name="page" value="ltms-security">
            <input type="text" name="filter_ip" value="<?php echo esc_attr( $filter_ip ); ?>"
                   placeholder="Filtrar por IP..."
                   style="padding:7px 12px;border:1px solid #ddd;border-radius:4px;font-family:monospace;width:160px;">
            <input type="text" name="filter_type" value="<?php echo esc_attr( $filter_type ); ?>"
                   placeholder="Tipo (ej: SQL_INJECTION_COMMENT)"
                   style="padding:7px 12px;border:1px solid #ddd;border-radius:4px;width:240px;">
            <button type="submit" class="ltms-btn ltms-btn-primary ltms-btn-sm">&#x1F50D; <?php esc_html_e( 'Filtrar', 'ltms' ); ?></button>
            <?php if ( $filter_ip || $filter_type ) : ?>
            <a href="<?php echo esc_url( $base_url ); ?>" class="ltms-btn ltms-btn-outline ltms-btn-sm">&#x2715; <?php esc_html_e( 'Limpiar', 'ltms' ); ?></a>
            <?php endif; ?>
            <span style="font-size:12px;color:#888;margin-left:auto;">
                <?php printf( esc_html__( '%d eventos', 'ltms' ), $total_events ); ?>
            </span>
        </form>

        <div class="ltms-table-title"><?php esc_html_e( 'Ultimos Eventos de Seguridad', 'ltms' ); ?></div>
        <table class="ltms-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Severidad', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Tipo', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'IP', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Regla / Parametro', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'URI', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $security_events ) ) : ?>
                <tr><td colspan="7" style="text-align:center;padding:30px;color:#888;">
                    <?php esc_html_e( 'No hay eventos de seguridad registrados.', 'ltms' ); ?>
                </td></tr>
                <?php else : ?>
                <?php foreach ( $security_events as $event ) :
                    $severity    = strtolower( $event['severity'] ?? 'low' );
                    $badge_class = [
                        'critical' => 'ltms-badge-danger',
                        'high'     => 'ltms-badge-danger',
                        'medium'   => 'ltms-badge-warning',
                        'low'      => 'ltms-badge-info',
                    ][ $severity ] ?? 'ltms-badge-pending';
                    $ip_blocked = false;
                    foreach ( $blocked_ips as $bip ) {
                        if ( $bip['ip_address'] === $event['ip_address'] ) { $ip_blocked = true; break; }
                    }
                ?>
                <tr>
                    <td>
                        <span class="ltms-badge <?php echo esc_attr( $badge_class ); ?>">
                            <?php echo esc_html( strtoupper( $severity ) ); ?>
                        </span>
                    </td>
                    <td><code style="font-size:11px;"><?php echo esc_html( $event['event_type'] ?? '--' ); ?></code></td>
                    <td style="font-family:monospace;font-size:12px;">
                        <?php echo esc_html( $event['ip_address'] ?? '--' ); ?>
                        <?php if ( $ip_blocked ) : ?>
                        <span style="font-size:10px;color:#dc2626;display:block;">&#x1F6AB; bloqueada</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:11px;color:#6b7280;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?php echo esc_html( $event['rule_matched'] ?? '--' ); ?>
                    </td>
                    <td style="font-size:11px;color:#6b7280;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                        title="<?php echo esc_attr( $event['request_uri'] ?? '' ); ?>">
                        <?php echo esc_html( mb_strimwidth( $event['request_uri'] ?? '--', 0, 40, '...' ) ); ?>
                    </td>
                    <td style="white-space:nowrap;font-size:12px;">
                        <?php echo esc_html( $event['created_at'] ? gmdate( 'd/m/Y H:i', strtotime( $event['created_at'] ) ) : '--' ); ?>
                    </td>
                    <td>
                        <?php if ( ! $ip_blocked && ! empty( $event['ip_address'] ) ) : ?>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field( 'ltms_security_action' ); ?>
                            <input type="hidden" name="block_ip" value="<?php echo esc_attr( $event['ip_address'] ); ?>">
                            <button type="submit" name="ltms_block_ip"
                                    class="ltms-btn ltms-btn-danger ltms-btn-sm"
                                    onclick="return confirm('Bloquear esta IP?')">
                                &#x1F6AB;
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
        <div style="display:flex;justify-content:center;gap:6px;padding:16px;flex-wrap:wrap;">
            <?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'ltms-security', 'paged' => $p, 'filter_ip' => $filter_ip, 'filter_type' => $filter_type ], admin_url( 'admin.php' ) ) ); ?>"
               class="ltms-btn ltms-btn-sm <?php echo $p === $page_num ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?>"
               style="min-width:32px;text-align:center;"><?php echo esc_html( $p ); ?></a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

    </div>

</div>
