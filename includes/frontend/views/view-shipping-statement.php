<?php
/**
 * Vista Vendor — Estado de Cuenta de Fletes.
 *
 * Muestra al vendedor:
 *  - Presupuesto mensual de envíos absorbed.
 *  - Gasto acumulado del mes y % utilizado.
 *  - Últimos 50 movimientos de flete.
 *  - Resumen mensual (últimos 6 meses).
 *
 * @package LTMS
 * @version 2.8.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$vendor_id = get_current_user_id();
if ( ! $vendor_id ) {
    return;
}

// v2.9.70 P3-6: Verificar nonce del GET form si se envió.
$ss_nonce = $_GET['ltms_ss_nonce'] ?? '';
if ( ! empty( $ss_nonce ) && ! wp_verify_nonce( $ss_nonce, 'ltms_shipping_statement' ) ) {
    wp_die( __( 'Token inválido. Recarga la página.', 'ltms' ) );
}

$year  = (int) ( $_GET['year']  ?? current_time( 'Y' ) ); // phpcs:ignore
$month = (int) ( $_GET['month'] ?? current_time( 'n' ) ); // phpcs:ignore

$statement = LTMS_Shipping_Cost_Ledger::get_vendor_statement( $vendor_id, $year, $month );
$budget    = $statement['budget'];
$spent     = $statement['spent'];
$remaining = $statement['remaining'];
$entries   = $statement['entries'];
$monthly   = $statement['monthly'];
$limit     = (float) $budget['budget_limit'];
$spent_pct = (float) $budget['spent_pct'];

$currency = function_exists( 'LTMS_Core_Config::get_currency' ) ? LTMS_Core_Config::get_currency() : 'COP';
$fmt = function( $v ) use ( $currency ) {
    return '$ ' . number_format( (float) $v, 0, ',', '.' ) . ' ' . $currency;
};
?>
<div class="ltms-vendor-shipping-statement">

    <h2><?php esc_html_e( 'Estado de Cuenta — Fletes Absorbidos', 'ltms' ); ?></h2>
    <p class="description">
        <?php esc_html_e( 'Trazabilidad de los costos de envío que estás absorbiendo (modo envío gratis).', 'ltms' ); ?>
    </p>

    <?php if ( $limit <= 0 ) : ?>
        <div class="ltms-notice ltms-notice-info">
            <?php esc_html_e( 'Tu cuenta no tiene límite de presupuesto mensual configurado. Los envíos absorbed son ilimitados, pero asegúrate de tener saldo suficiente en tu billetera.', 'ltms' ); ?>
        </div>
    <?php elseif ( $spent_pct >= (float) $budget['hard_threshold'] ) : ?>
        <div class="ltms-notice ltms-notice-error">
            <strong><?php esc_html_e( 'Crédito logístico suspendido.', 'ltms' ); ?></strong>
            <?php esc_html_e( 'Has superado el 100% de tu presupuesto mensual. Tus próximos pedidos se cotizarán con costo visible para el cliente. Recarga tu billetera o contacta al administrador.', 'ltms' ); ?>
        </div>
    <?php elseif ( $spent_pct >= (float) $budget['soft_threshold'] ) : ?>
        <div class="ltms-notice ltms-notice-warning">
            <strong><?php esc_html_e( 'Estás al ' . number_format( $spent_pct, 0 ) . '% de tu presupuesto.', 'ltms' ); ?></strong>
            <?php esc_html_e( 'Te recomendamos recargar tu billetera pronto para evitar interrupciones.', 'ltms' ); ?>
        </div>
    <?php endif; ?>

    <!-- Selector de período -->
    <form method="get" style="margin:16px 0;">
        <?php wp_nonce_field( 'ltms_shipping_statement', 'ltms_ss_nonce' ); ?>
        <input type="hidden" name="year" value="<?php echo esc_attr( $year ); ?>" />
        <select name="month" onchange="this.form.submit()">
            <?php for ( $m = 1; $m <= 12; $m++ ) : ?>
                <option value="<?php echo esc_attr( $m ); ?>" <?php selected( $month, $m ); ?>>
                    <?php echo esc_html( wp_date( 'F Y', mktime( 0, 0, 0, $m, 1, $year ) ) ); ?>
                </option>
            <?php endfor; ?>
        </select>
        <select name="year" onchange="this.form.submit()">
            <?php for ( $y = (int) current_time( 'Y' ); $y >= 2024; $y-- ) : ?>
                <option value="<?php echo esc_attr( $y ); ?>" <?php selected( $year, $y ); ?>><?php echo esc_html( $y ); ?></option>
            <?php endfor; ?>
        </select>
    </form>

    <!-- KPIs -->
    <div class="ltms-stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:24px;">
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Presupuesto', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( $limit > 0 ? $fmt( $limit ) : __( 'Ilimitado', 'ltms' ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Gastado', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#dc2626;"><?php echo esc_html( $fmt( $spent ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Disponible', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#16a34a;"><?php echo esc_html( $limit > 0 ? $fmt( $remaining ) : '—' ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( '% Utilizado', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:<?php echo $spent_pct >= 100 ? '#dc2626' : ( $spent_pct >= 80 ? '#f97316' : '#16a34a' ); ?>;">
                <?php echo esc_html( number_format( $spent_pct, 1 ) ); ?>%
            </span>
        </div>
    </div>

    <!-- Barra de progreso -->
    <?php if ( $limit > 0 ) : ?>
    <div style="background:#e5e7eb;border-radius:8px;height:24px;margin-bottom:24px;overflow:hidden;">
        <div style="background:<?php echo $spent_pct >= 100 ? '#dc2626' : ( $spent_pct >= 80 ? '#f97316' : '#16a34a' ); ?>;height:100%;width:<?php echo esc_attr( min( 100, $spent_pct ) ); ?>%;transition:width 0.5s;"></div>
    </div>
    <?php endif; ?>

    <!-- Resumen 6 meses -->
    <h3><?php esc_html_e( 'Resumen últimos 6 meses', 'ltms' ); ?></h3>
    <table class="ltms-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Mes', 'ltms' ); ?></th>
                <th style="text-align:right;"><?php esc_html_e( 'Envíos', 'ltms' ); ?></th>
                <th style="text-align:right;"><?php esc_html_e( 'Debitado', 'ltms' ); ?></th>
                <th style="text-align:right;"><?php esc_html_e( 'Cotizado', 'ltms' ); ?></th>
                <th style="text-align:right;"><?php esc_html_e( 'Costo Real', 'ltms' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $monthly ) ) : ?>
                <tr><td colspan="5"><?php esc_html_e( 'Sin actividad registrada.', 'ltms' ); ?></td></tr>
            <?php else : foreach ( $monthly as $row ) : ?>
                <tr>
                    <td><?php echo esc_html( wp_date( 'F Y', mktime( 0, 0, 0, (int) $row['m'], 1, (int) $row['y'] ) ) ); ?></td>
                    <td style="text-align:right;"><?php echo esc_html( number_format( (int) $row['cnt'] ) ); ?></td>
                    <td style="text-align:right;font-weight:600;"><?php echo esc_html( $fmt( $row['charged'] ) ); ?></td>
                    <td style="text-align:right;"><?php echo esc_html( $fmt( $row['quote'] ) ); ?></td>
                    <td style="text-align:right;color:#6b7280;">
                        <?php echo $row['real_cost'] ? esc_html( $fmt( $row['real_cost'] ) ) : '—'; ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>

    <!-- Movimientos recientes -->
    <h3 style="margin-top:32px;"><?php esc_html_e( 'Movimientos recientes', 'ltms' ); ?></h3>
    <table class="ltms-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Pedido', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Carrier', 'ltms' ); ?></th>
                <th style="text-align:right;"><?php esc_html_e( 'Debitado', 'ltms' ); ?></th>
                <th style="text-align:right;"><?php esc_html_e( 'Real', 'ltms' ); ?></th>
                <th style="text-align:right;"><?php esc_html_e( 'Diferencia', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $entries ) ) : ?>
                <tr><td colspan="7"><?php esc_html_e( 'Sin movimientos.', 'ltms' ); ?></td></tr>
            <?php else : foreach ( $entries as $e ) :
                $variance = (float) ( $e['variance'] ?? 0 );
                ?>
                <tr>
                    <td><?php echo esc_html( wp_date( 'Y-m-d', strtotime( $e['created_at'] ) ) ); ?></td>
                    <td>#<?php echo esc_html( $e['order_id'] ); ?></td>
                    <td><?php echo esc_html( strtoupper( $e['carrier'] ) ); ?></td>
                    <td style="text-align:right;font-weight:600;color:#dc2626;">-<?php echo esc_html( $fmt( $e['vendor_charged'] ) ); ?></td>
                    <td style="text-align:right;color:#6b7280;">
                        <?php echo $e['real_cost'] ? esc_html( $fmt( $e['real_cost'] ) ) : '—'; ?>
                    </td>
                    <td style="text-align:right;color:<?php echo $variance > 0 ? '#dc2626' : ( $variance < 0 ? '#16a34a' : '#6b7280' ); ?>;">
                        <?php if ( $e['variance'] !== null ) : ?>
                            <?php echo esc_html( $fmt( $variance ) ); ?>
                        <?php else : ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $labels = [
                            'quoted'     => __( 'Cotizado', 'ltms' ),
                            'shipped'    => __( 'Enviado', 'ltms' ),
                            'delivered'  => __( 'Entregado', 'ltms' ),
                            'invoiced'   => __( 'Facturado', 'ltms' ),
                            'disputed'   => __( 'En disputa', 'ltms' ),
                            'reconciled' => __( 'Conciliado', 'ltms' ),
                            'writeoff'   => __( 'Pérdida', 'ltms' ),
                        ];
                        echo esc_html( $labels[ $e['status'] ] ?? $e['status'] );
                        ?>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>

    <?php if ( $spent_pct >= 80 && $limit > 0 ) : ?>
    <div style="background:#fef3c7;border:1px solid #fcd34d;padding:16px;border-radius:6px;margin-top:24px;">
        <h4 style="margin-top:0;color:#92400e;"><?php esc_html_e( 'Acciones recomendadas', 'ltms' ); ?></h4>
        <ul style="margin-left:20px;color:#92400e;">
            <li><?php esc_html_e( 'Recarga tu billetera desde la sección "Mi Billetera".', 'ltms' ); ?></li>
            <li><?php esc_html_e( 'Considera ajustar los precios de tus productos para incluir el flete.', 'ltms' ); ?></li>
            <li><?php esc_html_e( 'Si el problema persiste, contacta al administrador para ajustar tu presupuesto mensual.', 'ltms' ); ?></li>
        </ul>
    </div>
    <?php endif; ?>

</div>
