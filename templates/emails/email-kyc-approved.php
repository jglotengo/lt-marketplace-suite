<?php
/**
 * Email Template: KYC Aprobado
 *
 * @package    LTMS\Templates\Emails
 * @var array  $data   { vendor_name, dashboard_url, store_name }
 * @version    1.5.0
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php esc_html_e( 'Tu cuenta fue verificada', 'ltms' ); ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; background: #f4f7f9; margin: 0; padding: 0; color: #374151; }
        .wrap { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 12px; overflow: hidden; }
        .header { background: linear-gradient(135deg, #1a5276 0%, #154360 100%); padding: 40px 32px; text-align: center; }
        .header .badge { font-size: 56px; display: block; margin-bottom: 12px; }
        .header h1 { color: #fff; font-size: 22px; margin: 0 0 8px; }
        .header p { color: rgba(255,255,255,.8); margin: 0; font-size: 14px; }
        .body { padding: 32px; }
        .verified-card { background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border: 1.5px solid #86efac; border-radius: 12px; padding: 20px; text-align: center; margin-bottom: 24px; }
        .verified-title { font-size: 18px; font-weight: 700; color: #14532d; margin-bottom: 8px; }
        .verified-sub { font-size: 14px; color: #16a34a; }
        .benefits { list-style: none; padding: 0; margin: 0 0 24px; }
        .benefits li { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid #f3f4f6; font-size: 15px; color: #374151; }
        .benefits li:last-child { border-bottom: none; }
        .benefits .icon { font-size: 20px; flex-shrink: 0; }
        .cta { display: block; width: fit-content; margin: 24px auto; background: #1a5276; color: #fff; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-weight: 700; font-size: 15px; }
        .footer { background: #f9fafb; padding: 20px 32px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>
<div style="padding:24px;">
<div class="wrap">
    <div class="header">
        <span class="badge">🏅</span>
        <h1><?php esc_html_e( '¡Tu cuenta fue verificada!', 'ltms' ); ?></h1>
        <p><?php esc_html_e( 'Verificación KYC completada exitosamente', 'ltms' ); ?></p>
    </div>

    <div class="body">
        <p style="font-size:17px; font-weight:600; color:#111827; margin-bottom:12px;">
            <?php printf( esc_html__( '¡Felicitaciones, %s!', 'ltms' ), esc_html( $data['vendor_name'] ?? '' ) ); ?>
        </p>
        <p style="color:#6b7280; font-size:15px; line-height:1.6; margin-bottom:20px;">
            <?php esc_html_e( 'Hemos revisado y aprobado tu documentación KYC. Tu cuenta ahora tiene acceso completo a todas las funcionalidades de la plataforma.', 'ltms' ); ?>
        </p>

        <div class="verified-card">
            <p class="verified-title">✅ <?php esc_html_e( 'Identidad Verificada', 'ltms' ); ?></p>
            <p class="verified-sub"><?php esc_html_e( 'Tu cuenta tiene el sello de vendedor verificado', 'ltms' ); ?></p>
        </div>

        <h3 style="font-size:15px; color:#111827; margin-bottom:12px;"><?php esc_html_e( 'Ahora puedes:', 'ltms' ); ?></h3>
        <ul class="benefits">
            <li><span class="icon">💰</span> <?php esc_html_e( 'Solicitar retiros de tu billetera', 'ltms' ); ?></li>
            <li><span class="icon">📦</span> <?php esc_html_e( 'Publicar productos sin restricciones', 'ltms' ); ?></li>
            <li><span class="icon">📊</span> <?php esc_html_e( 'Acceder a reportes y analytics completos', 'ltms' ); ?></li>
            <li><span class="icon">🤝</span> <?php esc_html_e( 'Invitar a otros vendedores y ganar comisiones de referido', 'ltms' ); ?></li>
            <li><span class="icon">⭐</span> <?php esc_html_e( 'Apareces como "Vendedor Verificado" ante los compradores', 'ltms' ); ?></li>
        </ul>

        <a href="<?php echo esc_url( $data['dashboard_url'] ?? '#' ); ?>" class="cta">
            <?php esc_html_e( 'Ir a mi Panel', 'ltms' ); ?>
        </a>
    </div>

    <div class="footer">
        <p><?php echo esc_html( $data['store_name'] ?? get_bloginfo( 'name' ) ); ?></p>
        <p><?php esc_html_e( 'Gracias por confiar en nuestra plataforma.', 'ltms' ); ?></p>
    </div>
</div>
</div>
</body>
</html>
