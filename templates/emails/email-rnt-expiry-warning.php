<?php
/**
 * Email: Aviso vencimiento RNT (vendedor)
 *
 * @var array $compliance_record
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

$days = max( 0, (int) floor( ( strtotime( $compliance_record['rnt_expiry_date'] ) - time() ) / DAY_IN_SECONDS ) );
do_action( 'woocommerce_email_header', __( 'Tu RNT está próximo a vencer', 'ltms' ), null ); ?>

<p><?php printf( esc_html__( 'Hola %s,', 'ltms' ), esc_html( get_userdata( (int) $compliance_record['vendor_id'] )->display_name ?? '' ) ); ?></p>
<p><?php printf( esc_html__( 'Tu Registro Nacional de Turismo (RNT) vence en %d días (%s).', 'ltms' ), $days, esc_html( $compliance_record['rnt_expiry_date'] ) ); ?></p>
<p><?php esc_html_e( 'Por favor renueva tu registro para continuar operando en la plataforma.', 'ltms' ); ?></p>

<p><a href="<?php echo esc_url( wc_get_account_endpoint_url( 'ltms-rnt' ) ); ?>" style="background:#ffc107;color:#333;padding:10px 20px;text-decoration:none;border-radius:4px;font-weight:600;">
    <?php esc_html_e( 'Renovar RNT', 'ltms' ); ?>
</a></p>

<?php do_action( 'woocommerce_email_footer', null ); ?>
