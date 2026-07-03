<?php
/**
 * Email Template: Certificado de Donación Generado
 *
 * Enviado a la fundación cuando se genera un nuevo certificado PDF de
 * donación. Contiene un resumen del lote, un enlace seguro (presigned URL)
 * para descargar el PDF, la nota legal, y los enlaces de preferencias
 * (GDPR / Ley 1581/2012).
 *
 * Variables esperadas (en $data):
 *   - foundation_name    (string)  Nombre de la fundación destinataria.
 *   - foundation_email   (string)  Email de la fundación.
 *   - batch_id           (int)     ID del lote.
 *   - batch_number       (string)  Número legible del lote.
 *   - period_start       (string)  Y-m-d.
 *   - period_end         (string)  Y-m-d.
 *   - total_donated      (float)   Monto total donado.
 *   - currency           (string)  COP | MXN | USD.
 *   - donation_count     (int)     Número de donaciones en el lote.
 *   - download_url       (string)  URL firmada (B2 presigned) del PDF.
 *   - expires_at         (string)  Fecha/hora de expiración del enlace.
 *   - tax_deductible     (bool)    Si las donaciones son deducibles.
 *   - legal_text         (string)  Texto legal a mostrar.
 *   - platform_name      (string)  Nombre de la plataforma donante.
 *   - platform_email     (string)  Email de contacto de la plataforma.
 *   - verify_url         (string)  URL pública para verificar el certificado.
 *   - preferences_url    (string)  URL para gestionar preferencias (GDPR).
 *
 * @package    LTMS\Templates\Emails
 * @version    1.0.0
 * @since      3.0.0  Task 60-D — Donation Reports + Admin + Certificates
 */

defined( 'ABSPATH' ) || exit;

if ( ! isset( $data ) || ! is_array( $data ) ) {
    $data = [];
}

// Defaults
$foundation_name = $data['foundation_name']    ?? __( 'Fundación Cardio Infantil', 'ltms' );
$batch_number    = $data['batch_number']       ?? '';
$period_start    = $data['period_start']       ?? '';
$period_end      = $data['period_end']         ?? '';
$total_donated   = (float) ( $data['total_donated'] ?? 0 );
$currency        = $data['currency']           ?? 'COP';
$donation_count  = (int) ( $data['donation_count'] ?? 0 );
$download_url    = $data['download_url']       ?? '#';
$expires_at      = $data['expires_at']         ?? '';
$tax_deductible  = (bool) ( $data['tax_deductible'] ?? true );
$legal_text      = $data['legal_text']         ?? '';
$platform_name   = $data['platform_name']      ?? get_bloginfo( 'name' );
$platform_email  = $data['platform_email']     ?? get_option( 'admin_email' );
$verify_url      = $data['verify_url']         ?? home_url( '/' );
$preferences_url = $data['preferences_url']    ?? home_url( '/' );

// Formatear montos y fechas
$total_fmt = function_exists( 'LTMS_Utils::format_money' )
    ? LTMS_Utils::format_money( $total_donated, $currency )
    : number_format( $total_donated, 2 ) . ' ' . $currency;

$fmt_date = static function ( string $date ): string {
    if ( empty( $date ) || $date === '0000-00-00' ) return '—';
    $ts = strtotime( $date );
    if ( false === $ts ) return $date;
    $meses = [
        'January'=>'enero', 'February'=>'febrero', 'March'=>'marzo', 'April'=>'abril',
        'May'=>'mayo', 'June'=>'junio', 'July'=>'julio', 'August'=>'agosto',
        'September'=>'septiembre', 'October'=>'octubre', 'November'=>'noviembre', 'December'=>'diciembre',
    ];
    $out = gmdate( 'j \d\e F \d\e Y', $ts );
    return str_replace( array_keys( $meses ), array_values( $meses ), $out );
};

$period_label = $fmt_date( $period_start ) . ' — ' . $fmt_date( $period_end );
$expires_label = '';
if ( ! empty( $expires_at ) && strtotime( $expires_at ) ) {
    $expires_label = sprintf(
        /* translators: %s: fecha de expiración */
        __( 'Enlace válido hasta: %s', 'ltms' ),
        $fmt_date( substr( $expires_at, 0, 10 ) )
    );
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php esc_html_e( 'Certificado de Donación generado', 'ltms' ); ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; background: #f4f7f9; margin: 0; padding: 0; color: #374151; }
        .wrap { max-width: 620px; margin: 0 auto; background: #fff; border-radius: 12px; overflow: hidden; }
        .header { background: linear-gradient(135deg, #1e6091 0%, #0f4c75 100%); padding: 36px 32px; text-align: center; }
        .header .heart { font-size: 40px; display: block; margin-bottom: 8px; }
        .header h1 { color: #fff; font-size: 22px; margin: 0 0 6px; }
        .header p { color: rgba(255,255,255,.85); margin: 0; font-size: 14px; }
        .body { padding: 28px 32px; }
        .greeting { font-size: 17px; font-weight: 600; color: #111827; margin-bottom: 8px; }
        .lead { color: #6b7280; font-size: 15px; line-height: 1.6; margin-bottom: 20px; }
        .summary-box { background: #f0f6fb; border: 1px solid #cce0ef; border-radius: 12px; padding: 20px; margin: 20px 0; }
        .amount-big { font-size: 32px; font-weight: 800; color: #1e6091; text-align: center; margin: 0 0 6px; }
        .amount-sub { text-align: center; font-size: 13px; color: #6b7280; }
        .info-grid { display: table; width: 100%; margin-top: 14px; }
        .info-row { display: table-row; }
        .info-label { display: table-cell; padding: 6px 0; color: #6b7280; font-size: 13px; width: 50%; }
        .info-value { display: table-cell; padding: 6px 0; text-align: right; font-weight: 600; color: #111827; font-size: 13px; }
        .cta { display: block; width: fit-content; margin: 24px auto; background: linear-gradient(135deg, #1e6091 0%, #0f4c75 100%); color: #fff; text-decoration: none; padding: 14px 32px; border-radius: 8px; font-weight: 700; font-size: 15px; }
        .cta:hover { opacity: 0.95; }
        .expires-note { text-align: center; font-size: 12px; color: #9ca3af; margin: -8px 0 16px; }
        .legal-box { background: #fdf9e8; border: 1px solid #e8d96f; border-radius: 8px; padding: 14px 16px; font-size: 12.5px; line-height: 1.6; color: #4d3f00; margin: 20px 0; }
        .legal-box strong { color: #1a1a1a; }
        .deductible-badge { display: inline-block; padding: 4px 12px; border-radius: 99px; background: #d1fae5; color: #065f46; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; margin-top: 8px; }
        .verify-box { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 16px; font-size: 12.5px; color: #4b5563; margin: 20px 0; text-align: center; }
        .verify-box a { color: #1e6091; font-weight: 600; word-break: break-all; }
        .footer { background: #f9fafb; padding: 24px 32px; text-align: center; font-size: 12px; color: #9ca3af; border-top: 1px solid #e5e7eb; }
        .footer a { color: #6b7280; }
        .footer .contact { margin-bottom: 10px; }
        .footer .prefs { font-size: 11px; }
        .heart-divider { color: #1e6091; letter-spacing: 4px; margin: 8px 0; }
    </style>
</head>
<body>
<div style="padding:24px;">
<div class="wrap">
    <div class="header">
        <span class="heart">❤️</span>
        <h1><?php esc_html_e( 'Certificado de Donación generado', 'ltms' ); ?></h1>
        <p><?php echo esc_html( $foundation_name ); ?></p>
    </div>

    <div class="body">
        <p class="greeting">
            <?php printf( esc_html__( 'Estimado equipo de %s', 'ltms' ), esc_html( $foundation_name ) ); ?>,
        </p>
        <p class="lead">
            <?php
            printf(
                /* translators: 1: platform name, 2: period */
                esc_html__( 'Nos complace informar que se ha generado su certificado de donación correspondiente al período %1$s. Este documento incluye el detalle de las donaciones transferidas por %2$s a su fundación durante el rango indicado.', 'ltms' ),
                '<strong>' . esc_html( $period_label ) . '</strong>',
                esc_html( $platform_name )
            );
            ?>
        </p>

        <div class="summary-box">
            <p class="amount-big"><?php echo esc_html( $total_fmt ); ?></p>
            <p class="amount-sub"><?php esc_html_e( 'Total donado en este lote', 'ltms' ); ?></p>

            <div class="info-grid">
                <div class="info-row">
                    <div class="info-label"><?php esc_html_e( 'Número de lote', 'ltms' ); ?></div>
                    <div class="info-value"><?php echo esc_html( $batch_number ); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label"><?php esc_html_e( 'Número de donaciones', 'ltms' ); ?></div>
                    <div class="info-value"><?php echo esc_html( number_format( $donation_count ) ); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label"><?php esc_html_e( 'Período', 'ltms' ); ?></div>
                    <div class="info-value"><?php echo esc_html( $period_label ); ?></div>
                </div>
                <div class="info-row">
                    <div class="info-label"><?php esc_html_e( 'Donante', 'ltms' ); ?></div>
                    <div class="info-value"><?php echo esc_html( $platform_name ); ?></div>
                </div>
            </div>

            <?php if ( $tax_deductible ) : ?>
            <div style="text-align:center;">
                <span class="deductible-badge">✓ <?php esc_html_e( 'Deducible de impuestos', 'ltms' ); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <a href="<?php echo esc_url( $download_url ); ?>" class="cta">
            📄 <?php esc_html_e( 'Descargar certificado (PDF)', 'ltms' ); ?>
        </a>
        <?php if ( ! empty( $expires_label ) ) : ?>
        <p class="expires-note"><?php echo esc_html( $expires_label ); ?></p>
        <?php endif; ?>

        <?php if ( ! empty( $legal_text ) ) : ?>
        <div class="legal-box">
            <strong>⚖️ <?php esc_html_e( 'Nota legal:', 'ltms' ); ?></strong><br>
            <?php echo esc_html( $legal_text ); ?>
        </div>
        <?php endif; ?>

        <div class="verify-box">
            <strong><?php esc_html_e( 'Verificación de autenticidad', 'ltms' ); ?></strong><br>
            <?php esc_html_e( 'Puede verificar la autenticidad de este certificado en:', 'ltms' ); ?><br>
            <a href="<?php echo esc_url( $verify_url ); ?>"><?php echo esc_html( $verify_url ); ?></a>
        </div>

        <div class="heart-divider">· · · ❤️ · · ·</div>

        <p style="font-size:14px; color:#6b7280; line-height:1.6; text-align:center; margin-bottom:0;">
            <?php
            printf(
                /* translators: 1: platform name, 2: platform email */
                esc_html__( 'Si tiene alguna pregunta sobre este certificado o las donaciones realizadas, contáctenos en %2$s. Gracias por su labor y por permitirnos ser parte de su misión.', 'ltms' ),
                esc_html( $platform_name ),
                '<a href="mailto:' . esc_attr( $platform_email ) . '">' . esc_html( $platform_email ) . '</a>'
            );
            ?>
        </p>
    </div>

    <div class="footer">
        <p class="contact">
            <strong><?php echo esc_html( $platform_name ); ?></strong><br>
            <a href="mailto:<?php echo esc_attr( $platform_email ); ?>"><?php echo esc_html( $platform_email ); ?></a>
        </p>
        <p>
            <?php esc_html_e( 'Este es un correo automático enviado por el sistema de donaciones de Lo Tengo Colombia.', 'ltms' ); ?><br>
            <?php esc_html_e( 'Por favor no responda directamente a este mensaje.', 'ltms' ); ?>
        </p>
        <p class="prefs">
            <?php
            printf(
                /* translators: 1: opening link tag, 2: closing link tag */
                esc_html__( 'Gestione sus preferencias de comunicación: %1$sCancelar suscripción%2$s · %1$sPreferencias%2$s', 'ltms' ),
                '<a href="' . esc_url( $preferences_url ) . '">',
                '</a>'
            );
            ?>
            <br>
            <?php esc_html_e( 'Conforme a la Ley 1581 de 2012 (Habeas Data) y el Reglamento General de Protección de Datos (GDPR).', 'ltms' ); ?>
        </p>
    </div>
</div>
</div>
</body>
</html>
