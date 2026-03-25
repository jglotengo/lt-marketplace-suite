<?php
/**
 * Vista: Admin Security - Logs de Seguridad y WAF
 *
 * @package LTMS
 * @version 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;

// Últimos eventos de seguridad
$security_table = $wpdb->prefix . 'lt_security_events';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$security_events = $wpdb->get_results(
    $wpdb->prepare( "SELECT * FROM `{$security_table}` ORDER BY created_at DESC LIMIT %d", 50 ),
    ARRAY_A
);

// IPs bloqueadas
$blocked_ips_table = $wpdb->prefix . 'lt_waf_blocked_ips';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$blocked_ips = $wpdb->get_results(
    $wpdb->prepare( "SELECT * FROM `{$blocked_ips_table}` WHERE blocked_until > NOW() ORDER BY blocked_at DESC LIMIT %d", 20 ),
    ARRAY_A
);
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <h1><?php esc_html_e( 'Seguridad / Logs', 'ltms' ); ?></h1>
    </div>

    <!-- IPs bloqueadas -->
    <?php if ( ! empty( $blocked_ips ) ) : ?>
    <div class="ltms-alert ltms-alert-danger" style="margin-bottom:20px;">
        <strong><?php echo esc_html( count( $blocked_ips ) ); ?> <?php esc_html_e( 'IPs actualmente bloqueadas', 'ltms' ); ?></strong>
    </div>
    <?php endif; ?>

    <!-- Eventos de seguridad -->
    <div class="ltms-table-wrap">
        <div class="ltms-table-title"><?php esc_html_e( 'Últimos Eventos de Seguridad', 'ltms' ); ?></div>
        <table class="ltms-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Nivel', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Tipo', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'IP', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Descripción', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $security_events ) ) : ?>
                <tr><td colspan="5" style="text-align:center;padding:30px;color:#888;">
                    <?php esc_html_e( 'No hay eventos de seguridad registrados.', 'ltms' ); ?>
                </td></tr>
                <?php else : ?>
                <?php foreach ( $security_events as $event ) :
                    $level_class = [
                        'CRITICAL' => 'ltms-badge-danger',
                        'ERROR'    => 'ltms-badge-danger',
                        'WARNING'  => 'ltms-badge-warning',
                        'INFO'     => 'ltms-badge-info',
                    ][ strtoupper( $event['level'] ?? '' ) ] ?? 'ltms-badge-pending';
                ?>
                <tr>
                    <td><span class="ltms-badge <?php echo esc_attr( $level_class ); ?>"><?php echo esc_html( strtoupper( $event['level'] ?? '?' ) ); ?></span></td>
                    <td><code><?php echo esc_html( $event['event_type'] ?? '—' ); ?></code></td>
                    <td><?php echo esc_html( $event['ip_address'] ?? '—' ); ?></td>
                    <td><?php echo esc_html( mb_strimwidth( $event['description'] ?? '', 0, 100, '...' ) ); ?></td>
                    <td><?php echo esc_html( $event['created_at'] ? gmdate( 'd/m/Y H:i', strtotime( $event['created_at'] ) ) : '—' ); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>
