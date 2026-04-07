<?php
/**
 * Email: Nueva Reserva (vendedor)
 *
 * @var array $booking
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$nights = max( 0, (int) round( ( strtotime( $booking['checkout_date'] ) - strtotime( $booking['checkin_date'] ) ) / DAY_IN_SECONDS ) );
do_action( 'woocommerce_email_header', __( '¡Tienes una nueva reserva!', 'ltms' ), null ); ?>

<p><?php printf( esc_html__( 'Hola %s,', 'ltms' ), esc_html( get_userdata( (int) $booking['vendor_id'] )->display_name ?? '' ) ); ?></p>
<p><?php esc_html_e( 'Has recibido una nueva reserva. Aquí están los detalles:', 'ltms' ); ?></p>

<table cellspacing="0" cellpadding="6" style="width:100%;border:1px solid #eee;margin:16px 0;">
    <tr>
        <th style="text-align:left;padding:8px 12px;background:#f8f8f8;border-bottom:1px solid #eee;"><?php esc_html_e( 'N° Reserva', 'ltms' ); ?></th>
        <td style="padding:8px 12px;">#<?php echo (int) $booking['id']; ?></td>
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
        <th style="text-align:left;padding:8px 12px;background:#f8f8f8;border-bottom:1px solid #eee;"><?php esc_html_e( 'Noches', 'ltms' ); ?></th>
        <td style="padding:8px 12px;"><?php echo (int) $nights; ?></td>
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

<?php do_action( 'woocommerce_email_footer', null ); ?>
