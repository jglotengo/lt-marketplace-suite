<?php
/**
 * Email Template: Retiro Aprobado
 *
 * @package    LTMS\Templates\Emails
 * @var array  $data   { vendor_name, payout_id, amount, currency, method, reference, estimated_date, dashboard_url, store_name }
 * @version    1.5.0
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php esc_html_e( 'Tu retiro fue aprobado', 'ltms' ); ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; background: #f4f7f9; margin: 0; padding: 0; color: #374151; }
        .wrap { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 12px; overflow: hidden; }
        .header { background: linear-gradient(135deg, #15803d 0%, #14532d 100%); padding: 40px 32px; text-align: center; }
        .header h1 { color: #fff; font-size: 22px; margin: 0 0 8px; }
        .header p { color: rgba(255,255,255,.8); margin: 0; font-size: 14px; }
        .body { padding: 32px; }
        .amount-box { background: #f0fdf4; border: 1px solid #86efac; border-radius: 12px; padding: 24px; text-align: center; margin: 20px 0; }
        .amount { font-size: 40px; font-weight: 800; color: #15803d; margin: 0; }
        .amount-sub { font-size: 13px; color: #6b7280; margin-top: 6px; }
        .info-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #f3f4f6; font-size: 14px; }
        .info-row:last-child { border-bottom: none; }
        .info-label { color: #6b7280; }
        .info-value { font-weight: 600; color: #111827; text-align: right; }
        .cta { display: block; width: fit-content; margin: 24px auto; background: #15803d; color: #fff; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-weight: 700; font-size: 15px; }
        .footer { background: #f9fafb; padding: 20px 32px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb; }
        .check-icon { font-size: 48px; margin-bottom: 12px; display: block; }
    </style>
</head>
<body>
<div style="padding:24px;">
<div class="wrap">
    <div class="header">
        <span class="check-icon">✅</span>
        <h1><?php esc_html_e( '¡Tu retiro fue aprobado!', 'ltms' ); ?></h1>
        <p><?php esc_html_e( 'Hemos procesado tu solicitud de retiro exitosamente.', 'ltms' ); ?></p>
    </div>

    <div class="body">
        <p style="font-size:17px; font-weight:600; color:#111827; margin-bottom:8px;">
            <?php printf( esc_html__( 'Hola, %s', 'ltms' ), esc_html( $data['vendor_name'] ?? '' ) ); ?>
        </p>
        <p style="color:#6b7280; font-size:15px; line-height:1.6; margin-bottom:20px;">
            <?php esc_html_e( 'Tu solicitud de retiro ha sido aprobada y procesada. El dinero estará disponible en tu cuenta bancaria pronto.', 'ltms' ); ?>
        </p>

        <div class="amount-box">
            <p class="amount">
                <?php echo esc_html( number_format( (float) ( $data['amount'] ?? 0 ), 2 ) ); ?>
                <span style="font-size:1.2rem;"><?php echo esc_html( $data['currency'] ?? 'COP' ); ?></span>
            </p>
            <p class="amount-sub"><?php esc_html_e( 'Monto transferido', 'ltms' ); ?></p>
        </div>

        <div style="margin-bottom:24px;">
            <div class="info-row">
                <span class="info-label"><?php esc_html_e( 'ID Retiro', 'ltms' ); ?></span>
                <span class="info-value">#<?php echo esc_html( $data['payout_id'] ?? '' ); ?></span>
            </div>
            <div class="info-row">
                <span class="info-label"><?php esc_html_e( 'Método de pago', 'ltms' ); ?></span>
                <span class="info-value"><?php echo esc_html( $data['method'] ?? '' ); ?></span>
            </div>
            <?php if ( ! empty( $data['reference'] ) ) : ?>
            <div class="info-row">
                <span class="info-label"><?php esc_html_e( 'Referencia', 'ltms' ); ?></span>
                <span class="info-value" style="font-family:monospace;"><?php echo esc_html( $data['reference'] ); ?></span>
            </div>
            <?php endif; ?>
            <div class="info-row">
                <span class="info-label"><?php esc_html_e( 'Fecha estimada de acreditación', 'ltms' ); ?></span>
                <span class="info-value"><?php echo esc_html( $data['estimated_date'] ?? '' ); ?></span>
            </div>
        </div>

        <a href="<?php echo esc_url( $data['dashboard_url'] ?? '#' ); ?>" class="cta">
            <?php esc_html_e( 'Ver historial de retiros', 'ltms' ); ?>
        </a>
    </div>

    <div class="footer">
        <p><?php echo esc_html( $data['store_name'] ?? get_bloginfo( 'name' ) ); ?></p>
        <p><?php esc_html_e( 'Si tienes algún problema, contacta al soporte desde tu panel.', 'ltms' ); ?></p>
    </div>
</div>
</div>
</body>
</html>
