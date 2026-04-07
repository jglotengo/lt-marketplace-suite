<?php
/**
 * Email: Reserva Confirmada (cliente)
 *
 * @var array $booking
 * @var array $product
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

do_action( 'woocommerce_email_header', __( 'Reserva Confirmada', 'ltms' ), null ); ?>

<p><?php printf( esc_html__( 'Hola %s,', 'ltms' ), esc_html( get_userdata( (int) $booking['customer_id'] )->display_name ?? '' ) ); ?></p>
<p><?php esc_html_e( 'Tu reserva ha sido confirmada. Aquí están los detalles:', 'ltms' ); ?></p>

<table cellspacing="0" cellpadding="6" style="width:100%;border:1px solid #eee;margin:16px 0;">
    <tr>
        <th style="text-align:left;padding:8px 12px;background:#f8f8f8;border-bottom:1px solid #eee;"><?php esc_html_e( 'N° Reserva', 'ltms' ); ?></th>
        <td style="padding:8px 12px;">#<?php echo (int) $booking['id']; ?></td>
    </tr>
    <tr>
        <th style="text-align:left;padding:8px 12px;background:#f8f8f8;border-bottom:1px solid #eee;"><?php esc_html_e( 'Alojamiento', 'ltms' ); ?></th>
        <td style="padding:8px 12px;"><?php echo esc_html( $booking['product_name'] ?? '—' ); ?></td>
    </tr>
    <tr>
        <th style="text-align:left;padding:8px 12px;background:#f8f8f8;border-bottom:1px solid #eee;"><?php esc_html_e( 'Check-in', 'ltms' ); ?></th>
        <td style="padding:8px 12px;"><?php echo esc_html( $booking['checkin_date'] ); ?></td>
    </tr>
    <tr>
        <th style="text-align:left;padding:8px 12px;background:#f8f8f8;border-bottom:1px solid #eee;"><?php esc_html_e( 'Check-out', 'ltms' ); ?></th>
        <td style="padding:8px 12px;"><?php echo esc_html( $booking['checkout_date'] ); ?></td>
    </tr>
    <tr>
        <th style="text-align:left;padding:8px 12px;background:#f8f8f8;border-bottom:1px solid #eee;"><?php esc_html_e( 'Huéspedes', 'ltms' ); ?></th>
        <td style="padding:8px 12px;"><?php echo (int) $booking['guests']; ?></td>
    </tr>
    <tr>
        <th style="text-align:left;padding:8px 12px;background:#f8f8f8;"><?php esc_html_e( 'Total', 'ltms' ); ?></th>
        <td style="padding:8px 12px;"><strong><?php echo esc_html( number_format( (float) $booking['total_price'], 0, ',', '.' ) . ' ' . $booking['currency'] ); ?></strong></td>
    </tr>
</table>

<p><?php esc_html_e( '¡Esperamos que disfrutes tu estadía!', 'ltms' ); ?></p>

<?php do_action( 'woocommerce_email_footer', null ); ?>
