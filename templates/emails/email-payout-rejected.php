<?php
/**
 * Email Template: Retiro Rechazado
 *
 * @package    LTMS\Templates\Emails
 * @var array  $data   { vendor_name, payout_id, amount, currency, reason, dashboard_url, store_name }
 * @version    1.5.0
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php esc_html_e( 'Solicitud de retiro rechazada', 'ltms' ); ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; background: #f4f7f9; margin: 0; padding: 0; color: #374151; }
        .wrap { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 12px; overflow: hidden; }
        .header { background: linear-gradient(135deg, #b91c1c 0%, #7f1d1d 100%); padding: 40px 32px; text-align: center; }
        .header h1 { color: #fff; font-size: 22px; margin: 0 0 8px; }
        .header p { color: rgba(255,255,255,.8); margin: 0; font-size: 14px; }
        .body { padding: 32px; }
        .rejected-box { background: #fef2f2; border: 1px solid #fca5a5; border-radius: 12px; padding: 20px; margin: 20px 0; }
        .reason-title { font-weight: 700; color: #7f1d1d; margin-bottom: 8px; font-size: 14px; }
        .reason-text { color: #991b1b; font-size: 15px; }
        .amount { font-size: 28px; font-weight: 700; color: #374151; text-align: center; margin: 16px 0; }
        .refund-note { background: #f0fdf4; border: 1px solid #86efac; border-radius: 8px; padding: 14px; font-size: 14px; color: #14532d; margin-bottom: 20px; }
        .cta { display: block; width: fit-content; margin: 24px auto; background: #1a5276; color: #fff; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-weight: 700; font-size: 15px; }
        .footer { background: #f9fafb; padding: 20px 32px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>
<div style="padding:24px;">
<div class="wrap">
    <div class="header">
        <h1><?php esc_html_e( 'Solicitud de retiro rechazada', 'ltms' ); ?></h1>
        <p><?php esc_html_e( 'Tu saldo ha sido restituido automáticamente.', 'ltms' ); ?></p>
    </div>

    <div class="body">
        <p style="font-size:17px; font-weight:600; color:#111827; margin-bottom:12px;">
            <?php printf( esc_html__( 'Hola, %s', 'ltms' ), esc_html( $data['vendor_name'] ?? '' ) ); ?>
        </p>
        <p style="color:#6b7280; font-size:15px; line-height:1.6; margin-bottom:20px;">
            <?php esc_html_e( 'Lamentamos informarte que tu solicitud de retiro #%s no pudo ser procesada.', 'ltms' ); ?>
        </p>

        <p class="amount">
            <?php echo esc_html( number_format( (float) ( $data['amount'] ?? 0 ), 2 ) ); ?>
            <?php echo esc_html( $data['currency'] ?? 'COP' ); ?>
        </p>

        <div class="rejected-box">
            <p class="reason-title"><?php esc_html_e( 'Motivo del rechazo:', 'ltms' ); ?></p>
            <p class="reason-text"><?php echo esc_html( $data['reason'] ?? esc_html__( 'No especificado.', 'ltms' ) ); ?></p>
        </div>

        <div class="refund-note">
            ✅ <?php esc_html_e( 'Tu saldo ha sido restituido a tu billetera. Puedes intentar nuevamente una vez que hayas resuelto el inconveniente.', 'ltms' ); ?>
        </div>

        <a href="<?php echo esc_url( $data['dashboard_url'] ?? '#' ); ?>" class="cta">
            <?php esc_html_e( 'Ir a mi panel', 'ltms' ); ?>
        </a>

        <p style="font-size:13px; color:#9ca3af; text-align:center; margin-top:16px;">
            <?php esc_html_e( 'Si crees que esto es un error, por favor contacta al soporte desde tu panel.', 'ltms' ); ?>
        </p>
    </div>

    <div class="footer">
        <p><?php echo esc_html( $data['store_name'] ?? get_bloginfo( 'name' ) ); ?></p>
        <p><?php esc_html_e( 'Este es un correo automático.', 'ltms' ); ?></p>
    </div>
</div>
</div>
</body>
</html>
