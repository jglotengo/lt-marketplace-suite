<?php
/**
 * Email Template: Novedad ReDi abierta.
 *
 * AUDIT-REDI-UX-GAPS GAP-8/GAP-9 FIX: template HTML para notificar a
 * vendors (origin + reseller) y al admin que se ha abierto una novedad
 * (incidente) sobre un pedido ReDi. Incluye SLA de 48h para primera
 * respuesta.
 *
 * Variables:
 *   $incident_id    int
 *   $order_id       int
 *   $type           string  stockout|complaint|quality|shipping|payment|other
 *   $description    string
 *   $sla_due_at     string  datetime
 *   $role           string  'origin' | 'reseller' | 'admin'
 *
 * @package LTMS\Templates\Emails
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$incident_id = $data['incident_id'] ?? 0;
$order_id = $data['order_id'] ?? 0;
$type = $data['type'] ?? 'other';
$description = $data['description'] ?? '';
$sla_due_at = $data['sla_due_at'] ?? '';
$role = $data['role'] ?? '';
$dashboard_url = home_url( '/dashboard?view=incidents&incident=' . $incident_id );
$admin_url = admin_url( 'admin.php?page=ltms-redi&tab=incidents&incident=' . $incident_id );

$type_labels = [
    'stockout'   => 'Sin stock para enviar',
    'complaint'  => 'Queja del cliente',
    'quality'    => 'Producto defectuoso',
    'shipping'   => 'Envío fallido',
    'payment'    => 'Problema de pago',
    'other'      => 'Otro',
];
$type_label = $type_labels[ $type ] ?? $type;
$link = $role === 'admin' ? $admin_url : $dashboard_url;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Novedad ReDi #<?php echo esc_html( $incident_id ); ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; background: #f4f7f9; margin: 0; padding: 0; color: #374151; }
        .email-wrap { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 12px; overflow: hidden; }
        .email-header { background: linear-gradient(135deg, #E80001 0%, #B80001 100%); padding: 40px 32px; text-align: center; }
        .email-header h1 { color: #fff; font-size: 22px; margin: 0 0 8px; font-weight: 700; }
        .email-header p { color: rgba(255,255,255,.9); margin: 0; font-size: 14px; }
        .email-body { padding: 32px; }
        .email-greeting { font-size: 18px; font-weight: 600; color: #111827; margin-bottom: 12px; }
        .email-text { font-size: 15px; line-height: 1.6; color: #6b7280; margin-bottom: 20px; }
        .incident-box { background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 16px; margin-bottom: 20px; }
        .incident-box h3 { margin: 0 0 8px; font-size: 15px; color: #dc2626; }
        .incident-box p { margin: 4px 0; font-size: 14px; color: #991b1b; }
        .sla-box { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border: 1px solid #f59e0b; border-radius: 8px; padding: 16px; margin-bottom: 20px; text-align: center; }
        .sla-box h3 { margin: 0 0 4px; font-size: 14px; color: #92400e; }
        .sla-box p { margin: 0; font-size: 24px; font-weight: 800; color: #b45309; }
        .cta-btn { display: block; width: fit-content; margin: 24px auto; background: #E80001; color: #fff; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-weight: 700; font-size: 15px; }
        .email-footer { background: #f9fafb; padding: 20px 32px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>
<div style="padding: 24px;">
<div class="email-wrap">

    <div class="email-header">
        <h1>⚠️ Novedad ReDi #<?php echo esc_html( $incident_id ); ?></h1>
        <p>Se ha abierto una novedad sobre el pedido #<?php echo esc_html( $order_id ); ?></p>
    </div>

    <div class="email-body">
        <p class="email-greeting">Hola 👋</p>
        <p class="email-text">
            Se ha abierto una novedad sobre un pedido ReDi en el que participas.
            <?php if ( $role === 'admin' ) : ?>
                <strong>Como administrador, debes hacer triage de esta novedad.</strong>
            <?php else : ?>
                <strong>Tienes 48 horas para responder.</strong>
            <?php endif; ?>
        </p>

        <div class="incident-box">
            <h3>📋 Detalles de la Novedad</h3>
            <p><strong>ID:</strong> #<?php echo esc_html( $incident_id ); ?></p>
            <p><strong>Pedido:</strong> #<?php echo esc_html( $order_id ); ?></p>
            <p><strong>Tipo:</strong> <?php echo esc_html( $type_label ); ?></p>
            <p><strong>Descripción:</strong><br><?php echo esc_html( $description ); ?></p>
        </div>

        <div class="sla-box">
            <h3>⏰ SLA de primera respuesta</h3>
            <p>48 horas</p>
            <p style="font-size: 12px; margin-top: 4px;">Vence: <?php echo esc_html( $sla_due_at ); ?></p>
        </div>

        <p class="email-text">
            Si no respondes dentro del SLA, la novedad será escalada automáticamente
            al administrador del marketplace. La mediación debe resolverse en 15 días
            (Ley 640/2001 CO / PROFECO CONCILIA MX).
        </p>

        <a href="<?php echo esc_url( $link ); ?>" class="cta-btn">
            Ver Novedad →
        </a>
    </div>

    <div class="email-footer">
        <p>Recibes este email porque participas en el programa ReDi.</p>
    </div>

</div>
</div>
</body>
</html>
