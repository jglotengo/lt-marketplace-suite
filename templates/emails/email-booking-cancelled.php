<?php
/**
 * Email: Reserva Cancelada (cliente)
 *
 * @var array $booking
 * @var float $refund_amount
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

do_action( 'woocommerce_email_header', __( 'Reserva Cancelada', 'ltms' ), null ); ?>

<p><?php printf( esc_html__( 'Hola %s,', 'ltms' ), esc_html( get_userdata( (int) $booking['customer_id'] )->display_name ?? '' ) ); ?></p>
<p><?php printf( esc_html__( 'Lamentamos informarte que la reserva #%d ha sido cancelada.', 'ltms' ), (int) $booking['id'] ); ?></p>

<?php if ( ! empty( $refund_amount ) && $refund_amount > 0 ) : ?>
    <p><?php printf( esc_html__( 'Se procesará un reembolso de %s según la política de cancelación.', 'ltms' ),
        '<strong>' . esc_html( number_format( (float) $refund_amount, 0, ',', '.' ) . ' ' . $booking['currency'] ) . '</strong>' ); ?></p>
<?php else : ?>
    <p><?php esc_html_e( 'Según la política de cancelación aplicable, no procede reembolso.', 'ltms' ); ?></p>
<?php endif; ?>

<p><?php esc_html_e( 'Si tienes preguntas, por favor contáctanos.', 'ltms' ); ?></p>

<?php do_action( 'woocommerce_email_footer', null ); ?>
