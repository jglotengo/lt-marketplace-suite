<?php
/**
 * Email Template: Listing ReDi pausado.
 *
 * AUDIT-REDI-UX-GAPS GAP-8/GAP-10 FIX: template HTML para notificar al
 * reseller que su listing ReDi fue pausado porque el origin vendor
 * desactivó la distribución ReDi (soft pause o producto → private/trash).
 *
 * Variables:
 *   $origin_product_name  string
 *   $reason               string  'manual_pause' | 'product_visibility_private' | 'product_visibility_trash'
 *
 * @package LTMS\Templates\Emails
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$origin_product_name = $data['origin_product_name'] ?? '';
$reason = $data['reason'] ?? 'manual_pause';
$reason_text = [
    'manual_pause'              => 'El origin vendor pausó temporalmente la distribución ReDi.',
    'product_visibility_private'=> 'El origin vendor despublicó el producto.',
    'product_visibility_trash'  => 'El origin vendor eliminó el producto.',
][$reason] ?? 'El origin vendor desactivó la distribución ReDi.';
$dashboard_url = home_url( '/dashboard?view=redi' );
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tu listing ReDi fue pausado</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; background: #f4f7f9; margin: 0; padding: 0; color: #374151; }
        .email-wrap { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 12px; overflow: hidden; }
        .email-header { background: linear-gradient(135deg, #F0B500 0%, #D4A017 100%); padding: 40px 32px; text-align: center; }
        .email-header h1 { color: #fff; font-size: 22px; margin: 0 0 8px; font-weight: 700; }
        .email-header p { color: rgba(255,255,255,.9); margin: 0; font-size: 14px; }
        .email-body { padding: 32px; }
        .email-greeting { font-size: 18px; font-weight: 600; color: #111827; margin-bottom: 12px; }
        .email-text { font-size: 15px; line-height: 1.6; color: #6b7280; margin-bottom: 20px; }
        .product-box { background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 16px; margin-bottom: 20px; }
        .product-box h3 { margin: 0 0 8px; font-size: 15px; color: #92400e; }
        .product-box p { margin: 4px 0; font-size: 14px; color: #78350f; }
        .cta-btn { display: block; width: fit-content; margin: 24px auto; background: #F0B500; color: #fff; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-weight: 700; font-size: 15px; }
        .email-footer { background: #f9fafb; padding: 20px 32px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>
<div style="padding: 24px;">
<div class="email-wrap">

    <div class="email-header">
        <h1>⏸️ Tu listing ReDi fue pausado</h1>
        <p>Tu copia del producto ha sido marcada como agotada</p>
    </div>

    <div class="email-body">
        <p class="email-greeting">Hola 👋</p>
        <p class="email-text">
            Tu listing ReDi del producto <strong>"<?php echo esc_html( $origin_product_name ); ?>"</strong>
            ha sido pausado temporalmente.
        </p>

        <div class="product-box">
            <h3>📋 Detalles</h3>
            <p><strong>Producto:</strong> <?php echo esc_html( $origin_product_name ); ?></p>
            <p><strong>Motivo:</strong> <?php echo esc_html( $reason_text ); ?></p>
            <p><strong>Estado de tu listing:</strong> Marcado como agotado (no se pueden hacer nuevas ventas)</p>
        </div>

        <p class="email-text">
            <strong>¿Qué significa esto?</strong><br>
            • Los clientes ya no pueden comprar tu copia de este producto.<br>
            • Las ventas en proceso seguirán normalmente — el origin vendor enviará los pedidos ya pagados.<br>
            • Te notificaremos por email cuando el origin vendor reactive la distribución.
        </p>

        <a href="<?php echo esc_url( $dashboard_url ); ?>" class="cta-btn">
            Ver mis listings ReDi →
        </a>
    </div>

    <div class="email-footer">
        <p>Recibes este email porque eres revendedor ReDi del producto mencionado.</p>
    </div>

</div>
</div>
</body>
</html>
