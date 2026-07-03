<?php
/**
 * Email: Depósito Liberado (vendedor)
 *
 * @var array $booking
 * @var float $deposit_amount
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

do_action( 'woocommerce_email_header', __( 'Depósito liberado a tu billetera', 'ltms' ), null ); ?>

<p><?php printf( esc_html__( 'Hola %s,', 'ltms' ), esc_html( get_userdata( (int) $booking['vendor_id'] )->display_name ?? '' ) ); ?></p>
<p><?php printf(
    esc_html__( 'El depósito de la reserva #%d ha sido liberado a tu billetera por %s.', 'ltms' ),
    (int) $booking['id'],
    '<strong>' . esc_html( number_format( (float) $deposit_amount, 0, ',', '.' ) . ' ' . $booking['currency'] ) . '</strong>'
); ?></p>
<p><?php esc_html_e( 'El período de disputa ha vencido sin reclamaciones.', 'ltms' ); ?></p>

<?php do_action( 'woocommerce_email_footer', null ); ?>
