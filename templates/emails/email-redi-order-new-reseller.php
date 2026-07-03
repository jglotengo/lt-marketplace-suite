<?php
/**
 * Email Template: Tu producto ReDi vendió — Reseller.
 *
 * AUDIT-REDI-UX-GAPS GAP-8 FIX: template HTML para notificar al reseller
 * que su listing ReDi vendió + monto de comisión. NO incluye dirección
 * del cliente (el origin vendor envía directamente).
 *
 * Variables:
 *   $order       \WC_Order
 *   $commission  float
 *   $role        string 'reseller'
 *
 * @package LTMS\Templates\Emails
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$order = $data['order'] ?? null;
$commission = $data['commission'] ?? 0;
$order_number = $order ? $order->get_order_number() : '—';
$dashboard_url = home_url( '/dashboard?view=orders' );
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>¡Tu producto ReDi vendió!</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; background: #f4f7f9; margin: 0; padding: 0; color: #374151; }
        .email-wrap { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 12px; overflow: hidden; }
        .email-header { background: linear-gradient(135deg, #15803d 0%, #166534 100%); padding: 40px 32px; text-align: center; }
        .email-header h1 { color: #fff; font-size: 22px; margin: 0 0 8px; font-weight: 700; }
        .email-header p { color: rgba(255,255,255,.8); margin: 0; font-size: 14px; }
        .email-body { padding: 32px; }
        .email-greeting { font-size: 18px; font-weight: 600; color: #111827; margin-bottom: 12px; }
        .email-text { font-size: 15px; line-height: 1.6; color: #6b7280; margin-bottom: 20px; }
        .commission-box { background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border: 1px solid #86efac; border-radius: 12px; padding: 24px; margin-bottom: 24px; text-align: center; }
        .commission-amount { font-size: 36px; font-weight: 800; color: #15803d; margin: 0; }
        .commission-label { font-size: 12px; color: #4ade80; text-transform: uppercase; letter-spacing: .08em; margin-top: 4px; }
        .info-box { background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 16px; margin-bottom: 20px; font-size: 14px; }
        .info-box strong { color: #1A1A4E; }
        .cta-btn { display: block; width: fit-content; margin: 24px auto; background: #15803d; color: #fff; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-weight: 700; font-size: 15px; }
        .email-footer { background: #f9fafb; padding: 20px 32px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>
<div style="padding: 24px;">
<div class="email-wrap">

    <div class="email-header">
        <h1>🎉 ¡Tu producto ReDi vendió!</h1>
        <p>Pedido #<?php echo esc_html( $order_number ); ?></p>
    </div>

    <div class="email-body">
        <p class="email-greeting">¡Felicidades! 👋</p>
        <p class="email-text">
            Un cliente ha comprado un producto que distribuyes a través del programa ReDi.
            Tu comisión se ha acreditado a tu billetera. El <strong>origin vendor</strong>
            se encargará del envío al cliente — tú no necesitas hacer nada.
        </p>

        <div class="commission-box">
            <p class="commission-amount"><?php echo wp_kses_post( wc_price( $commission, [ 'currency' => $order ? $order->get_currency() : 'COP' ] ) ); ?></p>
            <p class="commission-label">Tu comisión ReDi</p>
        </div>

        <div class="info-box">
            <strong>Pedido:</strong> #<?php echo esc_html( $order_number ); ?><br>
            <strong>Estado:</strong> El origin vendor está preparando el envío.<br>
            <strong>Comisión:</strong> Sujeta a período de retención (Ley 1480 / LFPCE). Se liberará automáticamente.
        </div>

        <a href="<?php echo esc_url( $dashboard_url ); ?>" class="cta-btn">
            Ver Pedido →
        </a>
    </div>

    <div class="email-footer">
        <p>Recibes este email porque eres revendedor ReDi.</p>
        <p>Para dejar de recibir estas notificaciones, desactívalas desde tu panel → Configuración.</p>
    </div>

</div>
</div>
</body>
</html>
