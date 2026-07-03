<?php
/**
 * Email Template: Comisión Acreditada al Vendedor
 *
 * @package    LTMS\Templates\Emails
 * @var array  $data   { vendor_name, order_number, gross_amount, platform_fee, vendor_net, currency, dashboard_url, order_url, store_name }
 * @version    1.5.0
 */

defined( 'ABSPATH' ) || exit;

// Get WC mailer styles if available
if ( function_exists( 'wc_get_template' ) ) {
    do_action( 'woocommerce_email_header', __( 'Comisión Acreditada', 'ltms' ), null );
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php esc_html_e( 'Comisión Acreditada', 'ltms' ); ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; background: #f4f7f9; margin: 0; padding: 0; color: #374151; }
        .email-wrap { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 12px; overflow: hidden; }
        .email-header { background: linear-gradient(135deg, #1a5276 0%, #154360 100%); padding: 40px 32px; text-align: center; }
        .email-header h1 { color: #fff; font-size: 22px; margin: 0 0 8px; font-weight: 700; }
        .email-header p { color: rgba(255,255,255,.8); margin: 0; font-size: 14px; }
        .email-body { padding: 32px; }
        .email-greeting { font-size: 18px; font-weight: 600; color: #111827; margin-bottom: 12px; }
        .email-text { font-size: 15px; line-height: 1.6; color: #6b7280; margin-bottom: 20px; }
        .commission-box { background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border: 1px solid #86efac; border-radius: 12px; padding: 24px; margin-bottom: 24px; text-align: center; }
        .commission-amount { font-size: 36px; font-weight: 800; color: #15803d; margin: 0; }
        .commission-label { font-size: 12px; color: #4ade80; text-transform: uppercase; letter-spacing: .08em; margin-top: 4px; }
        .breakdown-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; font-size: 14px; }
        .breakdown-table td { padding: 8px 0; border-bottom: 1px solid #f3f4f6; }
        .breakdown-table td:last-child { text-align: right; font-weight: 600; color: #111827; }
        .breakdown-table .deduction td:last-child { color: #dc2626; }
        .breakdown-table .total td { border-bottom: none; font-size: 15px; font-weight: 700; padding-top: 12px; }
        .breakdown-table .total td:last-child { color: #15803d; font-size: 18px; }
        .cta-btn { display: block; width: fit-content; margin: 24px auto; background: #1a5276; color: #fff; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-weight: 700; font-size: 15px; }
        .email-footer { background: #f9fafb; padding: 20px 32px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb; }
        .order-ref { background: #f3f4f6; border-radius: 6px; padding: 6px 12px; font-family: monospace; font-size: 13px; color: #374151; display: inline-block; margin-bottom: 20px; }
    </style>
</head>
<body>
<div style="padding: 24px;">
<div class="email-wrap">

    <div class="email-header">
        <h1>💰 <?php esc_html_e( 'Comisión Acreditada', 'ltms' ); ?></h1>
        <p><?php esc_html_e( 'Tu cuenta ha recibido un nuevo abono', 'ltms' ); ?></p>
    </div>

    <div class="email-body">

        <p class="email-greeting">
            <?php printf( esc_html__( 'Hola, %s', 'ltms' ), esc_html( $data['vendor_name'] ?? '' ) ); ?> 👋
        </p>

        <p class="email-text">
            <?php esc_html_e( 'Se ha acreditado una comisión en tu billetera LTMS por la siguiente venta:', 'ltms' ); ?>
        </p>

        <div style="text-align:center; margin-bottom: 16px;">
            <a href="<?php echo esc_url( $data['order_url'] ?? '#' ); ?>" class="order-ref">
                <?php printf( esc_html__( 'Pedido #%s', 'ltms' ), esc_html( $data['order_number'] ?? '' ) ); ?>
            </a>
        </div>

        <div class="commission-box">
            <p class="commission-amount">
                <?php echo esc_html( number_format( (float) ( $data['vendor_net'] ?? 0 ), 2 ) ); ?>
                <?php echo esc_html( $data['currency'] ?? 'COP' ); ?>
            </p>
            <p class="commission-label"><?php esc_html_e( 'Neto acreditado en tu billetera', 'ltms' ); ?></p>
        </div>

        <table class="breakdown-table">
            <tr>
                <td><?php esc_html_e( 'Valor bruto del pedido', 'ltms' ); ?></td>
                <td><?php echo esc_html( number_format( (float) ( $data['gross_amount'] ?? 0 ), 2 ) ); ?> <?php echo esc_html( $data['currency'] ?? 'COP' ); ?></td>
            </tr>
            <tr class="deduction">
                <td><?php esc_html_e( 'Comisión de la plataforma', 'ltms' ); ?></td>
                <td>- <?php echo esc_html( number_format( (float) ( $data['platform_fee'] ?? 0 ), 2 ) ); ?> <?php echo esc_html( $data['currency'] ?? 'COP' ); ?></td>
            </tr>
            <?php if ( ! empty( $data['tax_deductions'] ) ) :
                foreach ( $data['tax_deductions'] as $tax_label => $tax_amount ) : ?>
            <tr class="deduction">
                <td><?php echo esc_html( $tax_label ); ?></td>
                <td>- <?php echo esc_html( number_format( (float) $tax_amount, 2 ) ); ?> <?php echo esc_html( $data['currency'] ?? 'COP' ); ?></td>
            </tr>
            <?php endforeach; endif; ?>
            <tr class="total">
                <td><?php esc_html_e( 'Neto acreditado', 'ltms' ); ?></td>
                <td><?php echo esc_html( number_format( (float) ( $data['vendor_net'] ?? 0 ), 2 ) ); ?> <?php echo esc_html( $data['currency'] ?? 'COP' ); ?></td>
            </tr>
        </table>

        <a href="<?php echo esc_url( $data['dashboard_url'] ?? '#' ); ?>" class="cta-btn">
            <?php esc_html_e( 'Ver mi Billetera', 'ltms' ); ?>
        </a>

        <p class="email-text" style="font-size:13px; color:#9ca3af; text-align:center;">
            <?php esc_html_e( 'Puedes solicitar un retiro en cualquier momento desde tu panel de vendedor.', 'ltms' ); ?>
        </p>

    </div>

    <div class="email-footer">
        <p><?php echo esc_html( $data['store_name'] ?? get_bloginfo( 'name' ) ); ?> · <?php echo esc_html( home_url() ); ?></p>
        <p><?php esc_html_e( 'Este es un correo automático. No respondas a este mensaje.', 'ltms' ); ?></p>
    </div>

</div>
</div>
</body>
</html>

<?php
if ( function_exists( 'wc_get_template' ) ) {
    do_action( 'woocommerce_email_footer', null );
}
