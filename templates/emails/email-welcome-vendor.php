<?php
/**
 * Email Template: Bienvenida al Vendedor
 *
 * @package    LTMS\Templates\Emails
 * @var array  $data   { vendor_name, store_name, referral_code, dashboard_url, kyc_url, site_name, country }
 * @version    1.5.0
 */

defined( 'ABSPATH' ) || exit;

$country = $data['country'] ?? 'CO';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php esc_html_e( 'Bienvenido a LT Marketplace', 'ltms' ); ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; background: #f4f7f9; margin: 0; padding: 0; color: #374151; }
        .wrap { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 12px; overflow: hidden; }
        .header { background: linear-gradient(135deg, #1a5276 0%, #2980b9 50%, #1a5276 100%); padding: 48px 32px; text-align: center; }
        .header .logo { font-size: 48px; display: block; margin-bottom: 16px; }
        .header h1 { color: #fff; font-size: 26px; margin: 0 0 10px; font-weight: 800; }
        .header p { color: rgba(255,255,255,.85); margin: 0; font-size: 15px; }
        .body { padding: 32px; }
        .welcome-msg { font-size: 17px; line-height: 1.7; color: #374151; margin-bottom: 24px; }
        .steps-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 24px; }
        .step-card { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 16px; text-align: center; }
        .step-card .step-icon { font-size: 28px; display: block; margin-bottom: 8px; }
        .step-card .step-title { font-size: 13px; font-weight: 700; color: #111827; margin-bottom: 4px; }
        .step-card .step-desc { font-size: 12px; color: #6b7280; line-height: 1.4; }
        .referral-box { background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border: 1px solid #bfdbfe; border-radius: 12px; padding: 20px; text-align: center; margin-bottom: 24px; }
        .referral-title { font-size: 14px; color: #1e40af; font-weight: 600; margin-bottom: 8px; }
        .referral-code { font-family: 'Courier New', monospace; font-size: 22px; font-weight: 800; color: #1d4ed8; letter-spacing: .12em; background: #fff; border: 1.5px dashed #bfdbfe; border-radius: 8px; padding: 10px 20px; display: inline-block; margin-bottom: 8px; }
        .referral-desc { font-size: 13px; color: #3b82f6; }
        .cta-primary { display: block; width: fit-content; margin: 24px auto; background: #1a5276; color: #fff; text-decoration: none; padding: 16px 40px; border-radius: 10px; font-weight: 800; font-size: 16px; letter-spacing: .02em; }
        .cta-secondary { display: block; width: fit-content; margin: 8px auto 24px; background: transparent; color: #1a5276; text-decoration: underline; padding: 8px 20px; font-size: 14px; }
        .social-links { display: flex; gap: 16px; justify-content: center; margin-bottom: 16px; }
        .social-link { display: inline-flex; align-items: center; gap: 6px; color: #6b7280; text-decoration: none; font-size: 13px; }
        .footer { background: #f9fafb; padding: 20px 32px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb; }
        .divider { border: none; border-top: 1px solid #f3f4f6; margin: 24px 0; }
        @media (max-width: 480px) { .steps-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div style="padding:24px;">
<div class="wrap">
    <div class="header">
        <span class="logo">🚀</span>
        <h1><?php esc_html_e( '¡Bienvenido a la plataforma!', 'ltms' ); ?></h1>
        <p>
            <?php printf(
                esc_html__( 'Tu tienda "%s" ha sido creada exitosamente.', 'ltms' ),
                esc_html( $data['store_name'] ?? '' )
            ); ?>
        </p>
    </div>

    <div class="body">
        <p class="welcome-msg">
            <?php printf(
                esc_html__( 'Hola %s, ¡nos alegra que te hayas unido! Ahora eres parte de la red de vendedores de %s. Aquí te explicamos los primeros pasos para comenzar a vender.', 'ltms' ),
                esc_html( $data['vendor_name'] ?? '' ),
                esc_html( $data['site_name'] ?? get_bloginfo( 'name' ) )
            ); ?>
        </p>

        <div class="steps-grid">
            <div class="step-card">
                <span class="step-icon">📋</span>
                <div class="step-title"><?php esc_html_e( 'Verificar Identidad', 'ltms' ); ?></div>
                <div class="step-desc"><?php esc_html_e( 'Sube tus documentos KYC para desbloquear retiros.', 'ltms' ); ?></div>
            </div>
            <div class="step-card">
                <span class="step-icon">📦</span>
                <div class="step-title"><?php esc_html_e( 'Publicar Productos', 'ltms' ); ?></div>
                <div class="step-desc"><?php esc_html_e( 'Crea tu catálogo y empieza a recibir pedidos.', 'ltms' ); ?></div>
            </div>
            <div class="step-card">
                <span class="step-icon">💳</span>
                <div class="step-title"><?php esc_html_e( 'Configurar Pagos', 'ltms' ); ?></div>
                <div class="step-desc">
                    <?php echo 'CO' === $country
                        ? esc_html__( 'Agrega tu cuenta bancaria colombiana para retiros.', 'ltms' )
                        : esc_html__( 'Agrega tu CLABE para transferencias SPEI.', 'ltms' ); ?>
                </div>
            </div>
            <div class="step-card">
                <span class="step-icon">📢</span>
                <div class="step-title"><?php esc_html_e( 'Invitar Vendedores', 'ltms' ); ?></div>
                <div class="step-desc"><?php esc_html_e( 'Usa tu código de referido y gana comisiones extra.', 'ltms' ); ?></div>
            </div>
        </div>

        <hr class="divider">

        <?php if ( ! empty( $data['referral_code'] ) ) : ?>
        <div class="referral-box">
            <p class="referral-title">🎁 <?php esc_html_e( 'Tu Código de Referido', 'ltms' ); ?></p>
            <div class="referral-code"><?php echo esc_html( $data['referral_code'] ); ?></div>
            <p class="referral-desc">
                <?php esc_html_e( 'Comparte este código y gana una comisión por cada vendedor que se una con él.', 'ltms' ); ?>
            </p>
        </div>
        <?php endif; ?>

        <a href="<?php echo esc_url( $data['dashboard_url'] ?? '#' ); ?>" class="cta-primary">
            🚀 <?php esc_html_e( 'Ir a mi Panel', 'ltms' ); ?>
        </a>

        <?php if ( ! empty( $data['kyc_url'] ) ) : ?>
        <a href="<?php echo esc_url( $data['kyc_url'] ); ?>" class="cta-secondary">
            <?php esc_html_e( 'Iniciar verificación KYC →', 'ltms' ); ?>
        </a>
        <?php endif; ?>

    </div>

    <div class="footer">
        <p><?php echo esc_html( $data['site_name'] ?? get_bloginfo( 'name' ) ); ?> · <?php echo esc_html( home_url() ); ?></p>
        <p><?php esc_html_e( 'Si no creaste esta cuenta, ignora este mensaje.', 'ltms' ); ?></p>
    </div>
</div>
</div>
</body>
</html>
