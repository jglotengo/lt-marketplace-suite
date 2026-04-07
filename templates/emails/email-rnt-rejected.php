<?php
/**
 * Email: RNT Rechazado (vendedor)
 *
 * @var int    $vendor_id
 * @var string $notes
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

do_action( 'woocommerce_email_header', __( 'Tu RNT requiere correcciones', 'ltms' ), null ); ?>

<p><?php printf( esc_html__( 'Hola %s,', 'ltms' ), esc_html( get_userdata( $vendor_id )->display_name ?? '' ) ); ?></p>
<p><?php esc_html_e( 'Tu Registro Nacional de Turismo (RNT) ha sido revisado y requiere correcciones antes de ser aprobado.', 'ltms' ); ?></p>

<?php if ( $notes ) : ?>
    <p><strong><?php esc_html_e( 'Motivo:', 'ltms' ); ?></strong><br><?php echo esc_html( $notes ); ?></p>
<?php endif; ?>

<p><a href="<?php echo esc_url( wc_get_account_endpoint_url( 'ltms-rnt' ) ); ?>" style="background:#0073aa;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;">
    <?php esc_html_e( 'Actualizar información RNT', 'ltms' ); ?>
</a></p>

<?php do_action( 'woocommerce_email_footer', null ); ?>
