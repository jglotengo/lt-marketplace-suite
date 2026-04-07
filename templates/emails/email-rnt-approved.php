<?php
/**
 * Email: RNT Aprobado (vendedor)
 *
 * @var int $vendor_id
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

do_action( 'woocommerce_email_header', __( '¡Tu RNT ha sido verificado!', 'ltms' ), null ); ?>

<p><?php printf( esc_html__( 'Hola %s,', 'ltms' ), esc_html( get_userdata( $vendor_id )->display_name ?? '' ) ); ?></p>
<p><?php esc_html_e( '¡Buenas noticias! Tu Registro Nacional de Turismo (RNT) / SECTUR ha sido verificado y aprobado.', 'ltms' ); ?></p>
<p><?php esc_html_e( 'Ya puedes publicar y comercializar tus alojamientos en la plataforma.', 'ltms' ); ?></p>

<?php do_action( 'woocommerce_email_footer', null ); ?>
