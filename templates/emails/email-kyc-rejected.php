<?php
/**
 * Email Template: KYC Rechazado
 *
 * @package    LTMS\Templates\Emails
 * @var array  $data   { vendor_name, reason, dashboard_url, store_name }
 * @var string $source Origen del rechazo: 'manual' | 'auto_dian' | 'auto_sat' | 'auto_other'
 * @version    1.6.0
 */

defined( 'ABSPATH' ) || exit;

// v2.9.310: distinguir rechazo manual (admin) de automatico (API DIAN/SAT).
$source     = $source ?? 'manual';
$source_lbl = [
    'manual'     => __( 'nuestro equipo de revisión', 'ltms' ),
    'auto_dian'  => __( 'la validación automática contra el registro RUT de la DIAN (Colombia)', 'ltms' ),
    'auto_sat'   => __( 'la validación automática contra el padrón RFC del SAT (México)', 'ltms' ),
    'auto_other' => __( 'una validación automática de compliance', 'ltms' ),
];
$source_txt = $source_lbl[ $source ] ?? $source_lbl['manual'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php esc_html_e( 'Verificación KYC requiere correcciones', 'ltms' ); ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; background: #f4f7f9; margin: 0; padding: 0; color: #374151; }
        .wrap { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 12px; overflow: hidden; }
        .header { background: linear-gradient(135deg, #d97706 0%, #92400e 100%); padding: 40px 32px; text-align: center; }
        .header h1 { color: #fff; font-size: 22px; margin: 0 0 8px; }
        .header p { color: rgba(255,255,255,.8); margin: 0; font-size: 14px; }
        .body { padding: 32px; }
        .reason-box { background: #fef9c3; border: 1px solid #fde047; border-radius: 12px; padding: 20px; margin-bottom: 24px; }
        .reason-title { font-weight: 700; color: #713f12; margin-bottom: 8px; font-size: 14px; }
        .reason-text { color: #78350f; font-size: 15px; }
        .source-box { background: #f3f4f6; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; font-size: 13px; color: #4b5563; line-height: 1.5; }
        .steps { list-style: none; padding: 0; margin: 0 0 24px; counter-reset: step; }
        .steps li { counter-increment: step; display: flex; gap: 12px; padding: 10px 0; border-bottom: 1px solid #f3f4f6; font-size: 15px; color: #374151; align-items: flex-start; }
        .steps li:last-child { border-bottom: none; }
        .steps li::before { content: counter(step); display: flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 50%; background: #d97706; color: #fff; font-size: 12px; font-weight: 700; flex-shrink: 0; margin-top: 1px; }
        .cta { display: block; width: fit-content; margin: 24px auto; background: #d97706; color: #fff; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-weight: 700; font-size: 15px; }
        .footer { background: #f9fafb; padding: 20px 32px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb; }
    </style>
</head>
<body>
<div style="padding:24px;">
<div class="wrap">
    <div class="header">
        <h1><?php esc_html_e( 'Tu verificación de identidad necesita correcciones', 'ltms' ); ?></h1>
        <p><?php esc_html_e( 'Te explicamos qué corregir y cómo', 'ltms' ); ?></p>
    </div>

    <div class="body">
        <p style="font-size:17px; font-weight:600; color:#111827; margin-bottom:12px;">
            <?php printf( esc_html__( 'Hola, %s', 'ltms' ), esc_html( $data['vendor_name'] ?? '' ) ); ?>
        </p>
        <p style="color:#6b7280; font-size:15px; line-height:1.6; margin-bottom:20px;">
            <?php esc_html_e( 'Revisamos tu documentación KYC y necesita correcciones para poder aprobarla. A continuación encontrarás el motivo y los pasos para resolverlo.', 'ltms' ); ?>
        </p>

        <div class="source-box">
            <?php printf( esc_html__( 'Origen del rechazo: %s.', 'ltms' ), '<strong>' . esc_html( $source_txt ) . '</strong>' ); ?>
            <?php if ( $source === 'manual' ) : ?>
                <?php esc_html_e( ' Un revisor evaluó tus documentos y detectó el problema.', 'ltms' ); ?>
            <?php else : ?>
                <?php esc_html_e( ' Tu documentación no superó la validación automática. Corrige los datos y vuelve a enviar.', 'ltms' ); ?>
            <?php endif; ?>
        </div>

        <div class="reason-box">
            <p class="reason-title">⚠️ <?php esc_html_e( 'Motivo:', 'ltms' ); ?></p>
            <p class="reason-text"><?php echo esc_html( $data['reason'] ?? esc_html__( 'Documentos ilegibles o incompletos.', 'ltms' ) ); ?></p>
        </div>

        <h3 style="font-size:15px; color:#111827; margin-bottom:12px;"><?php esc_html_e( 'Pasos para resolverlo:', 'ltms' ); ?></h3>
        <ol class="steps">
            <li><?php esc_html_e( 'Ingresa a tu panel de vendedor.', 'ltms' ); ?></li>
            <li><?php esc_html_e( 'Ve a la sección "Configuración" → "Documentos KYC".', 'ltms' ); ?></li>
            <li><?php esc_html_e( 'Sube nuevamente los documentos solicitados en buena calidad (nítidos, completos, sin reflejos).', 'ltms' ); ?></li>
            <li><?php esc_html_e( 'El equipo revisará tu nueva solicitud en un plazo de 1-2 días hábiles.', 'ltms' ); ?></li>
        </ol>

        <a href="<?php echo esc_url( $data['dashboard_url'] ?? '#' ); ?>" class="cta">
            <?php esc_html_e( 'Actualizar Documentos', 'ltms' ); ?>
        </a>

        <p style="font-size:13px; color:#9ca3af; text-align:center; margin-top:16px;">
            <?php esc_html_e( '¿Tienes dudas? Contacta a nuestro equipo de soporte desde el panel.', 'ltms' ); ?>
        </p>
    </div>

    <div class="footer">
        <p><?php echo esc_html( $data['store_name'] ?? get_bloginfo( 'name' ) ); ?></p>
    </div>
</div>
</div>
</body>
</html>
