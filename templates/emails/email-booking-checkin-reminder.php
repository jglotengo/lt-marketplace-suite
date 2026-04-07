<?php
/**
 * Email: Recordatorio Check-in (cliente)
 *
 * @var array $booking
 * @var int   $hours_until_checkin
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

do_action( 'woocommerce_email_header', __( 'Tu check-in es mañana', 'ltms' ), null ); ?>

<p><?php printf( esc_html__( 'Hola %s,', 'ltms' ), esc_html( get_userdata( (int) $booking['customer_id'] )->display_name ?? '' ) ); ?></p>
<p><?php printf( esc_html__( 'Te recordamos que tu check-in para la reserva #%d está programado para mañana.', 'ltms' ), (int) $booking['id'] ); ?></p>

<table cellspacing="0" cellpadding="6" style="width:100%;border:1px solid #eee;margin:16px 0;">
    <tr>
        <th style="text-align:left;padding:8px 12px;background:#f8f8f8;border-bottom:1px solid #eee;"><?php esc_html_e( 'Alojamiento', 'ltms' ); ?></th>
        <td style="padding:8px 12px;"><?php echo esc_html( $booking['product_name'] ?? '—' ); ?></td>
    </tr>
    <tr>
        <th style="text-align:left;padding:8px 12px;background:#f8f8f8;border-bottom:1px solid #eee;"><?php esc_html_e( 'Check-in', 'ltms' ); ?></th>
        <td style="padding:8px 12px;"><strong><?php echo esc_html( $booking['checkin_date'] ); ?></strong></td>
    </tr>
    <tr>
        <th style="text-align:left;padding:8px 12px;background:#f8f8f8;"><?php esc_html_e( 'Check-out', 'ltms' ); ?></th>
        <td style="padding:8px 12px;"><?php echo esc_html( $booking['checkout_date'] ); ?></td>
    </tr>
</table>

<p><?php esc_html_e( '¡Que disfrutes tu estadía!', 'ltms' ); ?></p>

<?php do_action( 'woocommerce_email_footer', null ); ?>
