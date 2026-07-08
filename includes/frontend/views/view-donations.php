<?php
/**
 * Vista SPA: Donaciones — Transparencia para Vendedores
 *
 * Muestra a los vendedores las donaciones recaudadas en sus órdenes
 * para la Fundación Cardio Infantil (u otra configurada).
 *
 * @package LTMS
 * @version 2.9.31
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$user_id = get_current_user_id();

$per_page = 20;
$page_num = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$offset   = ( $page_num - 1 ) * $per_page;

$donations      = [];
$total_donation = 0.0;
$total_orders   = 0;
$currency       = LTMS_Core_Config::get_currency();

if ( class_exists( 'LTMS_Donation_Manager' ) ) {
    $donations      = LTMS_Donation_Manager::get_vendor_donations( $user_id, $per_page, $offset );
    $stats          = LTMS_Donation_Manager::get_vendor_donation_stats( $user_id );
    $total_donation = (float) ( $stats['total'] ?? 0 );
    $total_orders   = (int) ( $stats['orders'] ?? 0 );
}

$total_pages = max( 1, (int) ceil( $total_orders / $per_page ) );
?>
<div style="padding:24px;" id="ltms-donations-view">

    <div class="ltms-view-header" style="margin-bottom:24px;">
        <h2 style="margin:0;">❤️ Donaciones</h2>
        <p style="color:#6b7280;margin:8px 0 0;font-size:0.875rem;">
            <?php esc_html_e( 'Transparencia de las donaciones recaudadas en tus órdenes para la Fundación Cardio Infantil.', 'ltms' ); ?>
        </p>
    </div>

    <!-- Stats -->
    <div class="ltms-stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;">
        <div class="ltms-stat-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;text-align:center;">
            <div style="font-size:0.75rem;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">
                <?php esc_html_e( 'Total donado', 'ltms' ); ?>
            </div>
            <div style="font-size:1.75rem;font-weight:700;color:#dc2626;">
                <?php echo esc_html( LTMS_Utils::format_money( $total_donation ) ); ?>
            </div>
        </div>
        <div class="ltms-stat-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px;text-align:center;">
            <div style="font-size:0.75rem;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">
                <?php esc_html_e( 'Órdenes con donación', 'ltms' ); ?>
            </div>
            <div style="font-size:1.75rem;font-weight:700;color:#16a34a;">
                <?php echo esc_html( number_format( $total_orders ) ); ?>
            </div>
        </div>
    </div>

    <?php if ( empty( $donations ) ) : ?>
    <div style="text-align:center;padding:64px 24px;background:#fff;border-radius:12px;border:1px solid #e5e7eb;">
        <div style="font-size:64px;margin-bottom:16px;">💝</div>
        <h3 style="margin:0 0 8px;color:#374151;">
            <?php esc_html_e( 'Aún no has recaudado donaciones', 'ltms' ); ?>
        </h3>
        <p style="color:#9ca3af;margin:0;max-width:400px;margin:0 auto;">
            <?php esc_html_e( 'Cuando tus clientes agreguen una donación en sus compras, aparecerá aquí el registro para que veas el impacto de tu tienda.', 'ltms' ); ?>
        </p>
    </div>
    <?php else : ?>

    <!-- Tabla de donaciones -->
    <div class="ltms-table-wrap">
        <div class="ltms-table-title">
            <span><?php esc_html_e( 'Donaciones de tus órdenes', 'ltms' ); ?></span>
        </div>
        <table class="ltms-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Pedido', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Cliente', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Monto donado', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $donations as $don ) :
                    // FIX-P1-BATCH-A: $don['customer_name'] doesn't exist in the
                    // LTMS_Donation_Manager::get_vendor_donations() result (the
                    // query selects d.* + order_title only — no customer column).
                    // Fetch the billing name directly from the WC order so the
                    // vendor sees the actual customer; fall back to 'Cliente'
                    // when the order is gone or has no billing name.
                    $donation_customer_name = '';
                    if ( ! empty( $don['order_id'] ) ) {
                        $donation_order = wc_get_order( (int) $don['order_id'] );
                        if ( $donation_order && method_exists( $donation_order, 'get_billing_name' ) ) {
                            $donation_customer_name = trim( $donation_order->get_billing_name() );
                        }
                    }
                ?>
                <tr>
                    <td style="white-space:nowrap;">
                        <?php echo esc_html( date_i18n( 'd M Y', strtotime( $don['created_at'] ) ) ); ?>
                    </td>
                    <td>
                        <a href="<?php echo esc_url( site_url( '/mi-cuenta/pedidos/?view_order=' . $don['order_id'] ) ); ?>" style="color:#3b82f6;text-decoration:none;">
                            #<?php echo esc_html( $don['order_id'] ); ?>
                        </a>
                    </td>
                    <td>
                        <?php echo esc_html( $donation_customer_name !== '' ? $donation_customer_name : __( 'Cliente', 'ltms' ) ); ?>
                    </td>
                    <td style="font-weight:600;color:#dc2626;">
                        <?php echo esc_html( LTMS_Utils::format_money( (float) $don['total_donation'] ) ); ?>
                    </td>
                    <td>
                        <?php
                        $status = $don['status'] ?? 'pending';
                        $status_labels = [
                            'pending'   => [ 'label' => __( 'Pendiente', 'ltms' ), 'class' => 'ltms-badge-pending' ],
                            'processed' => [ 'label' => __( 'Procesada', 'ltms' ), 'class' => 'ltms-badge-success' ],
                            'reversed'  => [ 'label' => __( 'Reversada', 'ltms' ), 'class' => 'ltms-badge-danger' ],
                        ];
                        $s = $status_labels[ $status ] ?? $status_labels['pending'];
                        ?>
                        <span class="ltms-badge <?php echo esc_attr( $s['class'] ); ?>">
                            <?php echo esc_html( $s['label'] ); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginación -->
    <?php if ( $total_pages > 1 ) : ?>
    <div style="display:flex;justify-content:center;gap:6px;margin-top:24px;flex-wrap:wrap;">
        <?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
        <button type="button"
                class="ltms-btn ltms-btn-sm <?php echo $i === $page_num ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?> ltms-donations-page"
                data-page="<?php echo esc_attr( $i ); ?>">
            <?php echo esc_html( $i ); ?>
        </button>
        <?php endfor; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>

</div>

<script>
(function($){
    'use strict';

    $('.ltms-donations-page').on('click', function(){
        var page = $(this).data('page');
        var url = new URL(window.location.href);
        url.searchParams.set('paged', page);
        window.location.href = url.toString();
    });

})(jQuery);
</script>
