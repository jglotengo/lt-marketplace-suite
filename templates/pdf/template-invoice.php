<?php
/**
 * PDF Template: Factura / Comprobante de Venta
 *
 * Este template se renderiza con dompdf o wkhtmltopdf.
 * La clase LTMS_Utils::generate_pdf() pasa los datos como variables.
 *
 * @package    LTMS\Templates\PDF
 * @var array  $data {
 *     order_id, order_number, order_date, currency, country,
 *     vendor_name, vendor_nit, vendor_address, vendor_phone, vendor_email, vendor_city,
 *     buyer_name, buyer_document, buyer_email, buyer_address, buyer_city,
 *     items[]   { name, quantity, unit_price, subtotal, tax_rate, tax_amount },
 *     subtotal, tax_total, platform_fee, vendor_net,
 *     rete_fuente, rete_iva, rete_ica, impoconsumo,
 *     isr_amount, ieps_amount, cfdi_uuid,
 *     payment_method, payment_reference,
 *     site_name, site_logo_url, site_url,
 * }
 * @version    1.5.0
 */

defined( 'ABSPATH' ) || exit;

$country  = $data['country'] ?? 'CO';
$currency = $data['currency'] ?? ( 'CO' === $country ? 'COP' : 'MXN' );
$locale   = 'CO' === $country ? 'es-CO' : 'es-MX';

$fmt = function( $amount ) use ( $currency ) {
    return number_format( (float) $amount, 2, '.', ',' ) . ' ' . $currency;
};

$doc_title = 'CO' === $country ? 'COMPROBANTE DE VENTA' : 'COMPROBANTE FISCAL';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?php echo esc_html( $doc_title . ' #' . ( $data['order_number'] ?? '' ) ); ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 10pt; color: #222; line-height: 1.4; }
        .page { padding: 16mm; }
        /* Header */
        .doc-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2pt solid #1a5276; padding-bottom: 10pt; margin-bottom: 12pt; }
        .doc-logo { max-width: 130pt; max-height: 50pt; }
        .doc-title { text-align: right; }
        .doc-title h1 { font-size: 14pt; color: #1a5276; font-weight: 800; margin-bottom: 3pt; }
        .doc-title .doc-num { font-size: 10pt; color: #555; }
        .doc-title .doc-date { font-size: 9pt; color: #888; }
        /* Parties */
        .parties { display: flex; gap: 12pt; margin-bottom: 12pt; }
        .party-box { flex: 1; border: 0.5pt solid #ddd; border-radius: 4pt; padding: 8pt; font-size: 8.5pt; }
        .party-title { font-size: 7pt; font-weight: bold; text-transform: uppercase; color: #1a5276; border-bottom: 0.5pt solid #eee; padding-bottom: 4pt; margin-bottom: 6pt; letter-spacing: .04em; }
        .party-name { font-size: 10pt; font-weight: bold; margin-bottom: 2pt; }
        .party-detail { color: #555; }
        /* Items table */
        .items-table { width: 100%; border-collapse: collapse; margin-bottom: 10pt; font-size: 9pt; }
        .items-table th { background: #1a5276; color: #fff; padding: 5pt 6pt; text-align: left; font-size: 8.5pt; }
        .items-table td { padding: 4pt 6pt; border-bottom: 0.5pt solid #e5e7eb; vertical-align: top; }
        .items-table tr:nth-child(even) td { background: #f9f9f9; }
        .items-table .text-right { text-align: right; }
        /* Totals */
        .totals-wrap { display: flex; justify-content: flex-end; margin-bottom: 10pt; }
        .totals-table { width: 220pt; font-size: 9pt; border-collapse: collapse; }
        .totals-table td { padding: 3pt 4pt; border-bottom: 0.5pt solid #eee; }
        .totals-table td:last-child { text-align: right; font-weight: 600; }
        .totals-table .total-grand td { border-top: 1.5pt solid #1a5276; border-bottom: none; font-size: 11pt; font-weight: 800; color: #1a5276; padding-top: 5pt; }
        /* Tax section */
        .tax-box { border: 0.5pt solid #f97316; border-radius: 3pt; padding: 7pt; margin-bottom: 10pt; font-size: 8pt; }
        .tax-box-title { font-weight: bold; color: #c2410c; margin-bottom: 5pt; font-size: 8pt; text-transform: uppercase; }
        .tax-row { display: flex; justify-content: space-between; margin-bottom: 2pt; }
        .tax-row span:last-child { font-weight: 600; }
        /* CFDI */
        .cfdi-box { border: 0.5pt solid #16a34a; border-radius: 3pt; padding: 7pt; margin-bottom: 10pt; font-size: 7.5pt; }
        .cfdi-title { font-weight: bold; color: #15803d; margin-bottom: 4pt; font-size: 8pt; text-transform: uppercase; }
        .cfdi-uuid { font-family: 'Courier New', monospace; word-break: break-all; color: #374151; }
        /* Footer */
        .doc-footer { border-top: 0.5pt solid #e5e7eb; padding-top: 8pt; font-size: 7.5pt; color: #9ca3af; text-align: center; }
        .doc-footer-legal { margin-top: 4pt; font-size: 7pt; }
        .badge-verified { display: inline-block; background: #dcfce7; border: 0.5pt solid #86efac; border-radius: 10pt; padding: 1pt 6pt; color: #15803d; font-size: 7pt; font-weight: bold; }
    </style>
</head>
<body>
<div class="page">

    <!-- Header -->
    <div class="doc-header">
        <div>
            <?php if ( ! empty( $data['site_logo_url'] ) ) : ?>
            <img src="<?php echo esc_attr( $data['site_logo_url'] ); ?>" class="doc-logo" alt="<?php echo esc_attr( $data['site_name'] ?? '' ); ?>">
            <?php else : ?>
            <span style="font-size:14pt; font-weight:800; color:#1a5276;"><?php echo esc_html( $data['site_name'] ?? '' ); ?></span>
            <?php endif; ?>
            <p style="font-size:8pt; color:#888; margin-top:4pt;"><?php echo esc_html( $data['site_url'] ?? '' ); ?></p>
        </div>
        <div class="doc-title">
            <h1><?php echo esc_html( $doc_title ); ?></h1>
            <div class="doc-num"># <?php echo esc_html( $data['order_number'] ?? '' ); ?></div>
            <div class="doc-date"><?php echo esc_html( $data['order_date'] ?? '' ); ?></div>
            <br>
            <span class="badge-verified">✓ <?php echo esc_html( $data['vendor_name'] ?? '' ); ?></span>
        </div>
    </div>

    <!-- Parties -->
    <div class="parties">
        <div class="party-box">
            <div class="party-title"><?php esc_html_e( 'Vendedor / Emisor', 'ltms' ); ?></div>
            <div class="party-name"><?php echo esc_html( $data['vendor_name'] ?? '' ); ?></div>
            <?php if ( ! empty( $data['vendor_nit'] ) ) : ?>
            <div class="party-detail">
                <?php echo 'CO' === $country ? 'NIT: ' : 'RFC: '; ?>
                <?php echo esc_html( $data['vendor_nit'] ); ?>
            </div>
            <?php endif; ?>
            <div class="party-detail"><?php echo esc_html( $data['vendor_address'] ?? '' ); ?></div>
            <div class="party-detail"><?php echo esc_html( $data['vendor_city'] ?? '' ); ?></div>
            <div class="party-detail"><?php echo esc_html( $data['vendor_email'] ?? '' ); ?></div>
        </div>

        <div class="party-box">
            <div class="party-title"><?php esc_html_e( 'Comprador / Receptor', 'ltms' ); ?></div>
            <div class="party-name"><?php echo esc_html( $data['buyer_name'] ?? '' ); ?></div>
            <?php if ( ! empty( $data['buyer_document'] ) ) : ?>
            <div class="party-detail">
                <?php echo 'CO' === $country ? 'CC/NIT: ' : 'RFC: '; ?>
                <?php echo esc_html( $data['buyer_document'] ); ?>
            </div>
            <?php endif; ?>
            <div class="party-detail"><?php echo esc_html( $data['buyer_address'] ?? '' ); ?></div>
            <div class="party-detail"><?php echo esc_html( $data['buyer_city'] ?? '' ); ?></div>
            <div class="party-detail"><?php echo esc_html( $data['buyer_email'] ?? '' ); ?></div>
        </div>
    </div>

    <!-- Items -->
    <table class="items-table">
        <thead>
            <tr>
                <th style="width:40%;"><?php esc_html_e( 'Descripción', 'ltms' ); ?></th>
                <th class="text-right" style="width:8%;"><?php esc_html_e( 'Cant.', 'ltms' ); ?></th>
                <th class="text-right" style="width:18%;"><?php esc_html_e( 'Precio Unit.', 'ltms' ); ?></th>
                <th class="text-right" style="width:10%;"><?php esc_html_e( 'IVA', 'ltms' ); ?></th>
                <th class="text-right" style="width:18%;"><?php esc_html_e( 'Subtotal', 'ltms' ); ?></th>
                <th class="text-right" style="width:6%;"><?php esc_html_e( 'Total', 'ltms' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( (array) ( $data['items'] ?? [] ) as $item ) : ?>
            <tr>
                <td><?php echo esc_html( $item['name'] ?? '' ); ?></td>
                <td class="text-right"><?php echo esc_html( $item['quantity'] ?? 1 ); ?></td>
                <td class="text-right"><?php echo esc_html( $fmt( $item['unit_price'] ?? 0 ) ); ?></td>
                <td class="text-right"><?php echo esc_html( ( (float) ( $item['tax_rate'] ?? 0 ) ) . '%' ); ?></td>
                <td class="text-right"><?php echo esc_html( $fmt( $item['subtotal'] ?? 0 ) ); ?></td>
                <td class="text-right"><?php echo esc_html( $fmt( ( (float) ( $item['subtotal'] ?? 0 ) ) + ( (float) ( $item['tax_amount'] ?? 0 ) ) ) ); ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Totals -->
    <div class="totals-wrap">
        <table class="totals-table">
            <tr>
                <td><?php esc_html_e( 'Subtotal', 'ltms' ); ?></td>
                <td><?php echo esc_html( $fmt( $data['subtotal'] ?? 0 ) ); ?></td>
            </tr>
            <tr>
                <td><?php echo 'CO' === $country ? esc_html__( 'IVA (19%)', 'ltms' ) : esc_html__( 'IVA (16%)', 'ltms' ); ?></td>
                <td><?php echo esc_html( $fmt( $data['tax_total'] ?? 0 ) ); ?></td>
            </tr>
            <?php if ( 'MX' === $country && ! empty( $data['ieps_amount'] ) ) : ?>
            <tr>
                <td>IEPS</td>
                <td><?php echo esc_html( $fmt( $data['ieps_amount'] ) ); ?></td>
            </tr>
            <?php endif; ?>
            <tr class="total-grand">
                <td><?php esc_html_e( 'TOTAL', 'ltms' ); ?></td>
                <td><?php echo esc_html( $fmt( ( (float) ( $data['subtotal'] ?? 0 ) ) + ( (float) ( $data['tax_total'] ?? 0 ) ) ) ); ?></td>
            </tr>
        </table>
    </div>

    <!-- Colombia: Retenciones -->
    <?php if ( 'CO' === $country && ( ! empty( $data['rete_fuente'] ) || ! empty( $data['rete_ica'] ) ) ) : ?>
    <div class="tax-box">
        <div class="tax-box-title">📋 Retenciones Aplicadas (Colombia)</div>
        <?php if ( ! empty( $data['rete_fuente'] ) && (float) $data['rete_fuente'] > 0 ) : ?>
        <div class="tax-row"><span>ReteFuente (<?php echo esc_html( number_format( (float) ( $data['rete_fuente_rate'] ?? 3.5 ), 1 ) ); ?>%)</span><span>- <?php echo esc_html( $fmt( $data['rete_fuente'] ) ); ?></span></div>
        <?php endif; ?>
        <?php if ( ! empty( $data['rete_iva'] ) && (float) $data['rete_iva'] > 0 ) : ?>
        <div class="tax-row"><span>ReteIVA (15%)</span><span>- <?php echo esc_html( $fmt( $data['rete_iva'] ) ); ?></span></div>
        <?php endif; ?>
        <?php if ( ! empty( $data['rete_ica'] ) && (float) $data['rete_ica'] > 0 ) : ?>
        <div class="tax-row"><span>ReteICA</span><span>- <?php echo esc_html( $fmt( $data['rete_ica'] ) ); ?></span></div>
        <?php endif; ?>
        <?php if ( ! empty( $data['impoconsumo'] ) && (float) $data['impoconsumo'] > 0 ) : ?>
        <div class="tax-row"><span>Impoconsumo</span><span>- <?php echo esc_html( $fmt( $data['impoconsumo'] ) ); ?></span></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Mexico: CFDI -->
    <?php if ( 'MX' === $country && ! empty( $data['cfdi_uuid'] ) ) : ?>
    <div class="cfdi-box">
        <div class="cfdi-title">📄 Datos CFDI 4.0</div>
        <div class="tax-row"><span>UUID / Folio Fiscal:</span></div>
        <div class="cfdi-uuid"><?php echo esc_html( $data['cfdi_uuid'] ); ?></div>
        <?php if ( ! empty( $data['isr_amount'] ) && (float) $data['isr_amount'] > 0 ) : ?>
        <div class="tax-row" style="margin-top:5pt;"><span>ISR Art. 113-A LISR retenido:</span><span><?php echo esc_html( $fmt( $data['isr_amount'] ) ); ?></span></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Payment info -->
    <p style="font-size:8.5pt; color:#555; margin-bottom:8pt;">
        <?php esc_html_e( 'Método de pago:', 'ltms' ); ?> <?php echo esc_html( $data['payment_method'] ?? '—' ); ?>
        <?php if ( ! empty( $data['payment_reference'] ) ) : ?>
         · <?php esc_html_e( 'Ref:', 'ltms' ); ?> <?php echo esc_html( $data['payment_reference'] ); ?>
        <?php endif; ?>
    </p>

    <!-- Footer -->
    <div class="doc-footer">
        <p><?php echo esc_html( $data['site_name'] ?? '' ); ?> · <?php echo esc_html( $data['site_url'] ?? '' ); ?></p>
        <p class="doc-footer-legal">
            <?php if ( 'CO' === $country ) : ?>
                <?php esc_html_e( 'Documento generado por plataforma digital. Retenciones conforme al Estatuto Tributario colombiano (DIAN). Cumplimiento SAGRILAFT Ley 526/1999.', 'ltms' ); ?>
            <?php else : ?>
                <?php esc_html_e( 'Comprobante fiscal digital por Internet (CFDI). Retenciones conforme al art. 113-A de la Ley del ISR (Plataformas Tecnológicas). SAT México.', 'ltms' ); ?>
            <?php endif; ?>
        </p>
    </div>

</div>
</body>
</html>
