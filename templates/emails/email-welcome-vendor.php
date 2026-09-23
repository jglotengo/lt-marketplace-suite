<?php
/**
 * Email Template: Bienvenida al Vendedor
 *
 * @package    LTMS\Templates\Emails
 * @var array  $data   { vendor_name, username, login_url, store_name, referral_code, dashboard_url, site_name, country }
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
        /* CICLO33-P1-AUTH-EMAIL-FLOW FIX (2026-09-23): caja de datos de acceso —
           el vendor no conocía su usuario ni el flujo de login (evidencia: vendor
           #246, LOGIN_THROTTLE con credenciales correctas). Se muestra el username
           y los 2 pasos del acceso ANTES de cualquier mención de KYC. */
        .access-box { background: #fefce8; border: 1.5px solid #f59e0b; border-radius: 12px; padding: 18px 20px; margin-bottom: 24px; }
        .access-title { font-size: 14px; color: #92400e; font-weight: 700; margin: 0 0 10px; }
        .access-credential { font-size: 14px; color: #374151; margin: 0 0 6px; line-height: 1.5; }
        .access-username { font-family: 'Courier New', monospace; font-size: 16px; font-weight: 800; color: #92400e; background: #fff; border: 1.5px dashed #f59e0b; border-radius: 6px; padding: 3px 10px; display: inline-block; }
        .access-steps { font-size: 13.5px; color: #374151; line-height: 1.7; margin: 10px 0 0; }
        .access-steps a { color: #1a5276; word-break: break-all; }
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
                esc_html__( 'Hola %s, ¡nos alegra que te hayas unido! Ahora eres parte de la red de vendedores de %s. Para entrar a tu panel primero verifica tu email — es un solo clic.', 'ltms' ),
                esc_html( $data['vendor_name'] ?? '' ),
                esc_html( $data['site_name'] ?? get_bloginfo( 'name' ) )
            ); ?>
        </p>

        <?php if ( ! empty( $data['username'] ) ) : ?>
        <div class="access-box">
            <p class="access-title">🔑 <?php esc_html_e( 'Tus datos de acceso', 'ltms' ); ?></p>
            <p class="access-credential">
                <?php esc_html_e( 'Usuario:', 'ltms' ); ?>
                <span class="access-username"><?php echo esc_html( $data['username'] ); ?></span>
            </p>
            <p class="access-credential">
                <?php esc_html_e( 'Contraseña:', 'ltms' ); ?>
                <?php esc_html_e( 'la que registraste en el formulario.', 'ltms' ); ?>
            </p>
            <p class="access-steps">
                <strong><?php esc_html_e( '1.', 'ltms' ); ?></strong>
                <?php esc_html_e( 'Haz clic en el botón de abajo para verificar tu email.', 'ltms' ); ?><br>
                <strong><?php esc_html_e( '2.', 'ltms' ); ?></strong>
                <?php
                $access_login_url = ! empty( $data['login_url'] ) ? $data['login_url'] : '';
                printf(
                    /* translators: %s: login URL */
                    esc_html__( 'Inicia sesión en el panel con tu usuario o email y contraseña: %s', 'ltms' ),
                    $access_login_url ? '<a href="' . esc_url( $access_login_url ) . '">' . esc_html( $access_login_url ) . '</a>' : esc_html__( 'página de inicio de sesión', 'ltms' )
                );
                ?>
            </p>
        </div>
        <?php endif; ?>

        <div class="steps-grid">
            <div class="step-card">
                <span class="step-icon">✅</span>
                <div class="step-title"><?php esc_html_e( 'Verificar tu Email', 'ltms' ); ?></div>
                <div class="step-desc"><?php esc_html_e( 'Con el botón de abajo. Sin verificar no podrás iniciar sesión.', 'ltms' ); ?></div>
            </div>
            <div class="step-card">
                <span class="step-icon">📦</span>
                <div class="step-title"><?php esc_html_e( 'Publicar Productos', 'ltms' ); ?></div>
                <div class="step-desc"><?php esc_html_e( 'Crea tu catálogo y empieza a recibir pedidos.', 'ltms' ); ?></div>
            </div>
            <div class="step-card">
                <span class="step-icon">📋</span>
                <div class="step-title"><?php esc_html_e( 'Verificar Identidad (KYC)', 'ltms' ); ?></div>
                <div class="step-desc"><?php esc_html_e( 'Después de tu primer acceso, desde tu panel: desbloquea retiros.', 'ltms' ); ?></div>
            </div>
            <div class="step-card">
                <span class="step-icon">📢</span>
                <div class="step-title"><?php esc_html_e( 'Invitar Vendedores', 'ltms' ); ?></div>
                <div class="step-desc"><?php esc_html_e( 'Usa tu código de referido y gana comisiones extra.', 'ltms' ); ?></div>
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
            ✅ <?php esc_html_e( 'Verificar mi email e ir a mi Panel', 'ltms' ); ?>
        </a>
        <p style="text-align:center;font-size:12px;color:#9ca3af;margin:4px 0 24px;">
            <?php esc_html_e( 'Al hacer clic en este botón, verificamos tu correo y te llevamos a tu panel de vendedor. El KYC y la configuración de pagos se completan dentro del panel, después de tu primer acceso.', 'ltms' ); ?>
        </p>

    </div>

    <div class="footer">
        <p><?php echo esc_html( $data['site_name'] ?? get_bloginfo( 'name' ) ); ?> · <?php echo esc_html( home_url() ); ?></p>
        <p><?php esc_html_e( 'Recibes este correo porque te registraste como vendedor. Si reconoces este registro, haz clic en el botón de arriba. Si no fuiste tú, ignora este mensaje y la cuenta no será verificada.', 'ltms' ); ?></p>
    </div>
</div>
</div>
</body>
</html>
