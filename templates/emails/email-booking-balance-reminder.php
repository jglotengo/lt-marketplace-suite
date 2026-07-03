<?php
/**
 * Email: Recordatorio Saldo Pendiente (cliente)
 *
 * @var array $booking
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

do_action( 'woocommerce_email_header', __( 'Recordatorio: Saldo pendiente de tu reserva', 'ltms' ), null ); ?>

<p><?php printf( esc_html__( 'Hola %s,', 'ltms' ), esc_html( get_userdata( (int) $booking['customer_id'] )->display_name ?? '' ) ); ?></p>
<p><?php esc_html_e( 'Tu check-in está en 7 días y tienes un saldo pendiente por pagar:', 'ltms' ); ?></p>

<p style="font-size:1.2em;"><strong>
    <?php echo esc_html( number_format( (float) $booking['balance_amount'], 0, ',', '.' ) . ' ' . $booking['currency'] ); ?>
</strong></p>

<p><?php esc_html_e( 'Por favor realiza el pago del saldo para confirmar tu reserva.', 'ltms' ); ?></p>
<p><a href="<?php echo esc_url( wc_get_page_permalink( 'myaccount' ) ); ?>" style="background:#0073aa;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;">
    <?php esc_html_e( 'Ver mi reserva', 'ltms' ); ?>
</a></p>

<?php do_action( 'woocommerce_email_footer', null ); ?>
