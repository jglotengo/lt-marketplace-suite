<?php
/**
 * Email Template: Pedido ReDi — Origin Vendor debe enviar al cliente.
 *
 * AUDIT-REDI-UX-GAPS GAP-8 FIX: template HTML para notificar al origin
 * vendor que un cliente ha comprado un producto ReDi y debe enviarlo.
 * Incluye la dirección de envío completa del cliente.
 *
 * Variables esperadas (vía $data del send_redi_email):
 *   $order              \WC_Order
 *   $items              array   [{product_name, gross}, ...]
 *   $reseller_store     string  Nombre de la tienda del reseller.
 *   $show_shipping_addr bool    Siempre true para origin.
 *   $role               string  'origin'
 *
 * @package LTMS\Templates\Emails
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$order = $data['order'] ?? null;
$items = $data['items'] ?? [];
$reseller_store = $data['reseller_store'] ?? '';
$show_shipping_addr = $data['show_shipping_addr'] ?? false;
$role = $data['role'] ?? 'origin';
$order_number = $order ? $order->get_order_number() : '—';
$dashboard_url = home_url( '/dashboard?view=orders' );
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pedido ReDi — Debes Enviar al Cliente</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; background: #f4f7f9; margin: 0; padding: 0; color: #374151; }
        .email-wrap { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 12px; overflow: hidden; }
        .email-header { background: linear-gradient(135deg, #1A1A4E 0%, #2D2D6E 100%); padding: 40px 32px; text-align: center; }
        .email-header h1 { color: #fff; font-size: 22px; margin: 0 0 8px; font-weight: 700; }
        .email-header p { color: rgba(255,255,255,.8); margin: 0; font-size: 14px; }
        .email-body { padding: 32px; }
        .email-greeting { font-size: 18px; font-weight: 600; color: #111827; margin-bottom: 12px; }
        .email-text { font-size: 15px; line-height: 1.6; color: #6b7280; margin-bottom: 20px; }
        .info-box { background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 16px; margin-bottom: 20px; }
        .info-box strong { color: #1A1A4E; }
        .shipping-box { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border: 1px solid #f59e0b; border-radius: 8px; padding: 16px; margin-bottom: 20px; }
        .shipping-box h3 { margin: 0 0 8px; font-size: 15px; color: #92400e; }
        .shipping-box p { margin: 2px 0; font-size: 14px; color: #78350f; }
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; font-size: 14px; }
        .items-table th { background: #f9fafb; text-align: left; padding: 8px 12px; border-bottom: 2px solid #e5e7eb; font-size: 12px; text-transform: uppercase; color: #6b7280; }
        .items-table td { padding: 10px 12px; border-bottom: 1px solid #f3f4f6; }
        .items-table td:last-child { text-align: right; font-weight: 600; }
        .cta-btn { display: block; width: fit-content; margin: 24px auto; background: #E80001; color: #fff; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-weight: 700; font-size: 15px; }
        .email-footer { background: #f9fafb; padding: 20px 32px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb; }
        .order-ref { background: #f3f4f6; border-radius: 6px; padding: 6px 12px; font-family: monospace; font-size: 13px; color: #374151; display: inline-block; margin-bottom: 20px; }
    </style>
</head>
<body>
<div style="padding: 24px;">
<div class="email-wrap">

    <div class="email-header">
        <h1>📦 Pedido ReDi #<?php echo esc_html( $order_number ); ?></h1>
        <p>Tienes un pedido ReDi para enviar al cliente</p>
    </div>

    <div class="email-body">
        <p class="email-greeting">Hola 👋</p>
        <p class="email-text">
            Un cliente ha comprado uno de tus productos a través del programa ReDi
            (revendido por <strong><?php echo esc_html( $reseller_store ); ?></strong>).
            Tú mantienes el inventario, por lo que <strong>debes preparar y enviar el producto al cliente</strong>.
        </p>

        <div class="order-ref">Pedido #<?php echo esc_html( $order_number ); ?></div>

        <table class="items-table">
            <thead>
                <tr>
                    <th>Producto a enviar</th>
                    <th style="text-align:right;">Monto</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $items as $item ) : ?>
                    <tr>
                        <td><?php echo esc_html( $item['product_name'] ); ?></td>
                        <td><?php echo wp_kses_post( wc_price( $item['gross'] ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ( $show_shipping_addr && $order ) : ?>
        <div class="shipping-box">
            <h3>📦 Dirección de Envío del Cliente</h3>
            <p><?php echo wp_kses_post( $order->get_formatted_shipping_address() ); ?></p>
            <?php $phone = $order->get_shipping_phone() ?: $order->get_billing_phone(); ?>
            <?php if ( $phone ) : ?>
                <p><strong>Tel:</strong> <?php echo esc_html( $phone ); ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <a href="<?php echo esc_url( $dashboard_url ); ?>" class="cta-btn">
            Gestionar Envío →
        </a>
    </div>

    <div class="email-footer">
        <p>Recibes este email porque eres el vendedor origin de un producto ReDi.</p>
        <p>Si ya no quieres recibir estas notificaciones, desactívalas desde tu panel → Configuración.</p>
    </div>

</div>
</div>
</body>
</html>
