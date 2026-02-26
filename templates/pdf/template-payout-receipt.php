<?php
/**
 * PDF Template: Comprobante de Retiro
 *
 * @package    LTMS\Templates\PDF
 * @var array  $data {
 *     payout_id, payout_date, vendor_name, vendor_document, vendor_email,
 *     amount, currency, method, bank_name, account_last4, reference,
 *     tax_applied, tax_amount, net_amount,
 *     site_name, site_url, admin_name,
 * }
 * @version    1.5.0
 */

defined( 'ABSPATH' ) || exit;

$currency = $data['currency'] ?? 'COP';
$fmt      = fn( $v ) => number_format( (float) $v, 2, '.', ',' ) . ' ' . $currency;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?php echo esc_html( 'Comprobante de Retiro #' . ( $data['payout_id'] ?? '' ) ); ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 10pt; color: #222; }
        .page { padding: 16mm; max-width: 148mm; margin: 0 auto; }
        .header { text-align: center; border-bottom: 2pt solid #1a5276; padding-bottom: 10pt; margin-bottom: 14pt; }
        .header h1 { font-size: 14pt; color: #1a5276; margin-bottom: 4pt; }
        .header .sub { font-size: 9pt; color: #777; }
        .amount-block { background: #f0fdf4; border: 1pt solid #86efac; border-radius: 6pt; padding: 14pt; text-align: center; margin-bottom: 14pt; }
        .amount-value { font-size: 26pt; font-weight: 800; color: #15803d; }
        .amount-label { font-size: 8pt; color: #4ade80; text-transform: uppercase; letter-spacing: .06em; margin-top: 3pt; }
        .info-table { width: 100%; border-collapse: collapse; font-size: 9pt; margin-bottom: 12pt; }
        .info-table td { padding: 5pt 6pt; border-bottom: 0.5pt solid #e5e7eb; }
        .info-table td:first-child { color: #777; width: 42%; }
        .info-table td:last-child { font-weight: 600; color: #111; }
        .section-title { font-size: 8pt; font-weight: bold; text-transform: uppercase; color: #1a5276; letter-spacing: .05em; margin-bottom: 5pt; border-bottom: 0.5pt solid #e5e7eb; padding-bottom: 3pt; }
        .footer { border-top: 0.5pt solid #e5e7eb; padding-top: 8pt; text-align: center; font-size: 7.5pt; color: #9ca3af; margin-top: 14pt; }
        .stamp { border: 2pt solid #15803d; border-radius: 50%; width: 70pt; height: 70pt; margin: 12pt auto; display: flex; align-items: center; justify-content: center; text-align: center; color: #15803d; font-size: 7pt; font-weight: bold; line-height: 1.3; }
        .tax-note { background: #fffbeb; border: 0.5pt solid #fde68a; border-radius: 4pt; padding: 7pt; font-size: 8pt; color: #713f12; margin-bottom: 10pt; }
    </style>
</head>
<body>
<div class="page">

    <!-- Header -->
    <div class="header">
        <h1><?php echo esc_html( $data['site_name'] ?? get_bloginfo( 'name' ) ); ?></h1>
        <div class="sub">
            <?php esc_html_e( 'COMPROBANTE DE RETIRO', 'ltms' ); ?> · #<?php echo esc_html( $data['payout_id'] ?? '' ); ?>
        </div>
        <div class="sub"><?php echo esc_html( $data['payout_date'] ?? '' ); ?></div>
    </div>

    <!-- Amount -->
    <div class="amount-block">
        <div class="amount-value"><?php echo esc_html( $fmt( $data['net_amount'] ?? $data['amount'] ?? 0 ) ); ?></div>
        <div class="amount-label"><?php esc_html_e( 'Monto Neto Transferido', 'ltms' ); ?></div>
    </div>

    <!-- Vendor info -->
    <div class="section-title"><?php esc_html_e( 'Datos del Beneficiario', 'ltms' ); ?></div>
    <table class="info-table">
        <tr>
            <td><?php esc_html_e( 'Nombre', 'ltms' ); ?></td>
            <td><?php echo esc_html( $data['vendor_name'] ?? '' ); ?></td>
        </tr>
        <?php if ( ! empty( $data['vendor_document'] ) ) : ?>
        <tr>
            <td><?php esc_html_e( 'Documento', 'ltms' ); ?></td>
            <td><?php echo esc_html( $data['vendor_document'] ); ?></td>
        </tr>
        <?php endif; ?>
        <tr>
            <td><?php esc_html_e( 'Email', 'ltms' ); ?></td>
            <td><?php echo esc_html( $data['vendor_email'] ?? '' ); ?></td>
        </tr>
    </table>

    <!-- Transfer info -->
    <div class="section-title" style="margin-top:10pt;"><?php esc_html_e( 'Datos de la Transferencia', 'ltms' ); ?></div>
    <table class="info-table">
        <tr>
            <td><?php esc_html_e( 'Monto bruto', 'ltms' ); ?></td>
            <td><?php echo esc_html( $fmt( $data['amount'] ?? 0 ) ); ?></td>
        </tr>
        <?php if ( ! empty( $data['tax_applied'] ) && (float) $data['tax_amount'] > 0 ) : ?>
        <tr>
            <td><?php echo esc_html( $data['tax_applied'] ); ?></td>
            <td>- <?php echo esc_html( $fmt( $data['tax_amount'] ) ); ?></td>
        </tr>
        <?php endif; ?>
        <tr>
            <td><?php esc_html_e( 'Monto neto', 'ltms' ); ?></td>
            <td style="color:#15803d;"><?php echo esc_html( $fmt( $data['net_amount'] ?? $data['amount'] ?? 0 ) ); ?></td>
        </tr>
        <tr>
            <td><?php esc_html_e( 'Método', 'ltms' ); ?></td>
            <td><?php echo esc_html( $data['method'] ?? '' ); ?></td>
        </tr>
        <?php if ( ! empty( $data['bank_name'] ) ) : ?>
        <tr>
            <td><?php esc_html_e( 'Banco', 'ltms' ); ?></td>
            <td><?php echo esc_html( $data['bank_name'] ); ?></td>
        </tr>
        <?php endif; ?>
        <?php if ( ! empty( $data['account_last4'] ) ) : ?>
        <tr>
            <td><?php esc_html_e( 'Cuenta', 'ltms' ); ?></td>
            <td>****<?php echo esc_html( $data['account_last4'] ); ?></td>
        </tr>
        <?php endif; ?>
        <?php if ( ! empty( $data['reference'] ) ) : ?>
        <tr>
            <td><?php esc_html_e( 'Referencia', 'ltms' ); ?></td>
            <td style="font-family:monospace; font-size:8.5pt;"><?php echo esc_html( $data['reference'] ); ?></td>
        </tr>
        <?php endif; ?>
        <?php if ( ! empty( $data['admin_name'] ) ) : ?>
        <tr>
            <td><?php esc_html_e( 'Aprobado por', 'ltms' ); ?></td>
            <td><?php echo esc_html( $data['admin_name'] ); ?></td>
        </tr>
        <?php endif; ?>
    </table>

    <!-- Tax note -->
    <?php if ( ! empty( $data['tax_applied'] ) ) : ?>
    <div class="tax-note">
        ⚠️ <?php printf(
            esc_html__( 'Se aplicó %s por valor de %s según normativa fiscal vigente. Este comprobante tiene validez tributaria.', 'ltms' ),
            esc_html( $data['tax_applied'] ),
            esc_html( $fmt( $data['tax_amount'] ?? 0 ) )
        ); ?>
    </div>
    <?php endif; ?>

    <!-- Stamp -->
    <div class="stamp">
        ✓<br><?php esc_html_e( 'PAGADO', 'ltms' ); ?>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p><?php echo esc_html( $data['site_name'] ?? '' ); ?> · <?php echo esc_html( $data['site_url'] ?? '' ); ?></p>
        <p style="margin-top:4pt;">
            <?php esc_html_e( 'Documento generado automáticamente por el sistema. Este comprobante tiene validez como soporte contable.', 'ltms' ); ?>
        </p>
    </div>

</div>
</body>
</html>
