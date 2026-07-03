<?php
/**
 * Email Template: Pedido ReDi cancelado — comisión reversada.
 *
 * AUDIT-REDI-UX-GAPS GAP-8 FIX: template HTML para notificar a AMBOS
 * vendors (origin + reseller) que un pedido ReDi fue cancelado/reembolsado
 * y las comisiones fueron reversadas.
 *
 * Variables:
 *   $order  \WC_Order
 *   $role   string 'origin' | 'reseller'
 *
 * @package LTMS\Templates\Emails
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$order = $data['order'] ?? null;
$role = $data['role'] ?? '';
$order_number = $order ? $order->get_order_number() : '—';
$dashboard_url = home_url( '/dashboard?view=orders' );
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedido ReDi Cancelado</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; background: #f4f7f9; margin: 0; padding: 0; color: #374151; }
        .email-wrap { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 12px; overflow: hidden; }
        .email-header { background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%); padding: 40px 32px; text-align: center; }
        .email-header h1 { color: #fff; font-size: 22px; margin: 0 0 8px; font-weight: 700; }
        .email-header p { color: rgba(255,255,255,.8); margin: 0; font-size: 14px; }
        .email-body { padding: 32px; }
        .email-greeting { font-size: 18px; font-weight: 600; color: #111827; margin-bottom: 12px; }
        .email-text { font-size: 15px; line-height: 1.6; color: #6b7280; margin-bottom: 20px; }
        .warning-box { background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 16px; margin-bottom: 20px; }
        .warning-box h3 { margin: 0 0 8px; font-size: 15px; color: #dc2626; }
        .warning-box p { margin: 4px 0; font-size: 14px; color: #991b1b; }
        .cta-btn { display: block; width: fit-content; margin: 24px auto; background: #dc2626; color: #fff; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-weight: 700; font-size: 15px; }
        .email-footer { background: #f9fafb; padding: 20px 32px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>
<div style="padding: 24px;">
<div class="email-wrap">

    <div class="email-header">
        <h1>⚠️ Pedido ReDi Cancelado</h1>
        <p>Pedido #<?php echo esc_html( $order_number ); ?></p>
    </div>

    <div class="email-body">
        <p class="email-greeting">Hola 👋</p>
        <p class="email-text">
            El pedido ReDi <strong>#<?php echo esc_html( $order_number ); ?></strong>
            ha sido cancelado o reembolsado. Las comisiones ReDi asociadas
            han sido reversadas de tu billetera.
        </p>

        <div class="warning-box">
            <?php if ( $role === 'origin' ) : ?>
                <h3>📍 Acción requerida (Origin Vendor)</h3>
                <p>Si ya habías preparado el envío, <strong>suspéndelo inmediatamente</strong>.</p>
                <p>El stock del producto ha sido restaurado automáticamente.</p>
            <?php else : ?>
                <h3>🔁 Información (Reseller)</h3>
                <p>Tu comisión ReDi por este pedido ha sido reversada de tu billetera.</p>
                <p>El origin vendor ha sido notificado para suspender el envío.</p>
            <?php endif; ?>
        </div>

        <p class="email-text">
            Si tienes preguntas sobre esta cancelación, puedes abrir una novedad
            desde tu panel.
        </p>

        <a href="<?php echo esc_url( $dashboard_url ); ?>" class="cta-btn">
            Ver Pedido →
        </a>
    </div>

    <div class="email-footer">
        <p>Recibes este email porque participas en el programa ReDi.</p>
    </div>

</div>
</div>
</body>
</html>
