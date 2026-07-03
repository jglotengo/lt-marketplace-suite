<?php
/**
 * LTMS Contract PDF Generator
 *
 * Genera dinámicamente el PDF del Contrato de Vinculación de Vendedor
 * con los datos reales del KYC, usando DOMPDF (ya incluido en vendor/).
 *
 * Flujo:
 *   1. Reúne datos del vendedor desde user_meta y opciones de la plataforma.
 *   2. Renderiza el HTML del contrato con esos datos.
 *   3. Convierte el HTML a PDF con DOMPDF.
 *   4. Retorna el PDF como string base64 listo para ZapSign (base64_pdf).
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    5.0 Ultra-Blindado v2.9.24
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

/**
 * Class LTMS_Contract_PDF_Generator
 */
class LTMS_Contract_PDF_Generator {

        use LTMS_Logger_Aware;

        const CONTRACT_VERSION = '5.0 Ultra-Blindado v2.9.24';
        const CONTRACT_DATE    = '03 de julio de 2026';
        const OPERATOR         = [
                'name'      => 'GRUPO LO TENGO S.A.S.',
                'nit'       => '901.981.692-3',
                'rep_legal' => 'Ricardo Andrés Gutiérrez Molina',
                'cc'        => '94.534.855',
                'matricula' => '1263517-16 (C.Comercio Cali)',
                'ciiu'      => '4791 / 6312',
                'email'     => 'operaciones@lo-tengo.com.co',
                'url'       => 'https://lo-tengo.com.co',
                'ciudad'    => 'Cali, Valle del Cauca',
        ];

        // ── API pública ───────────────────────────────────────────────

        /**
         * Genera el PDF del contrato y retorna su contenido en base64.
         *
         * @param int $vendor_id ID del usuario vendedor en WordPress.
         * @return string Base64 del PDF generado.
         * @throws \RuntimeException Si DOMPDF no está disponible o el vendedor no existe.
         */
        public function generate_base64( int $vendor_id ): string {
                $this->ensure_dompdf();
                $vendor = $this->get_vendor_data( $vendor_id );
                $html   = $this->render_html( $vendor );
                $pdf    = $this->html_to_pdf( $html );
                return base64_encode( $pdf );
        }

        /**
         * Genera el PDF y lo guarda en una ruta temporal del servidor.
         *
         * @param int    $vendor_id ID del vendedor.
         * @param string $path      Ruta donde guardar el PDF (vacío = /tmp).
         * @return string Ruta absoluta del archivo generado.
         */
        public function generate_file( int $vendor_id, string $path = '' ): string {
                $this->ensure_dompdf();
                $vendor   = $this->get_vendor_data( $vendor_id );
                $html     = $this->render_html( $vendor );
                $pdf      = $this->html_to_pdf( $html );
                $filename = $path ?: ( sys_get_temp_dir() . '/ltms-contrato-' . $vendor_id . '-' . time() . '.pdf' );
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
                file_put_contents( $filename, $pdf );
                return $filename;
        }

        // ── Recolección de datos del vendedor ─────────────────────────

        /**
         * Reúne todos los datos del vendedor necesarios para el contrato.
         *
         * @param int $vendor_id ID del vendedor.
         * @return array<string, string|int> Mapa de variables del contrato.
         * @throws \InvalidArgumentException Si el vendedor no existe.
         */
        private function get_vendor_data( int $vendor_id ): array {
                $user = get_userdata( $vendor_id );
                if ( ! $user ) {
                        throw new \InvalidArgumentException( "[ltms-pdf] Vendedor #$vendor_id no encontrado." );
                }

                // Documento de identidad (desencriptado si aplica)
                $doc_number = get_user_meta( $vendor_id, 'ltms_document_number', true ) ?: '';
                if ( class_exists( 'LTMS_Core_Security' ) && ! empty( $doc_number ) ) {
                        try {
                                $doc_number = LTMS_Core_Security::decrypt( $doc_number );
                        } catch ( \Throwable $e ) {
                                // Si falla el decrypt, usar el valor tal como está
                        }
                }

                // Tipo de documento
                $doc_type_raw = get_user_meta( $vendor_id, 'ltms_document_type', true ) ?: 'CC';
                $doc_type_map = [
                        'CC'  => 'Cédula de Ciudadanía',
                        'CE'  => 'Cédula de Extranjería',
                        'NIT' => 'NIT',
                        'PA'  => 'Pasaporte',
                        'TI'  => 'Tarjeta de Identidad',
                ];
                $doc_type = $doc_type_map[ $doc_type_raw ] ?? $doc_type_raw;

                // Municipio DANE
                $dane_code  = get_user_meta( $vendor_id, 'ltms_dane_municipality_code', true )
                                        ?: get_user_meta( $vendor_id, 'billing_state', true )
                                        ?: '';
                $city_name  = get_user_meta( $vendor_id, 'billing_city', true ) ?: '';
                $dane_label = $dane_code ? "$dane_code ($city_name)" : $city_name;

                // Régimen tributario
                $regime_raw = get_user_meta( $vendor_id, 'ltms_tax_regime', true ) ?: '';
                $regime_map = [
                        'simplificado'       => 'Persona Natural / Resp. IVA',
                        'comun'              => 'Persona Natural / Gran Contribuyente',
                        'responsable_iva'    => 'Persona Natural / Resp. IVA',
                        'no_responsable_iva' => 'Persona Natural / No Resp. IVA',
                        'persona_juridica'   => 'Persona Jurídica / Resp. IVA',
                ];
                $regime = $regime_map[ $regime_raw ] ?? ( $regime_raw ?: 'Persona Natural / Resp. IVA' );

                // Comisión
                $commission_rate = $this->get_vendor_commission_rate( $vendor_id );
                $vendor_pct      = 100 - $commission_rate;

                // NIT vendedor
                $vendor_nit       = get_user_meta( $vendor_id, 'ltms_nit', true ) ?: '';
                $vendor_nit_label = $vendor_nit
                        ? $doc_number . '-' . get_user_meta( $vendor_id, 'ltms_nit_dv', true )
                        : $doc_number;

                $cc_number = get_user_meta( $vendor_id, 'ltms_camara_comercio_number', true ) ?: '';

                // Teléfono — ltms_phone es el guardado por el handler de registro (formato E.164)
                $phone = get_user_meta( $vendor_id, 'ltms_phone', true )
                                ?: get_user_meta( $vendor_id, 'billing_phone', true )
                                ?: get_user_meta( $vendor_id, 'ltms_store_phone', true )
                                ?: '';

                // Dirección — billing_address_1 es la que guarda el registro (store_address → billing_address_1)
                $address = get_user_meta( $vendor_id, 'billing_address_1', true ) ?: '';
                $addr2   = get_user_meta( $vendor_id, 'billing_address_2', true );
                if ( $addr2 ) {
                        $address .= ' ' . $addr2;
                }
                // Fallback: ltms_store_address (campo antiguo, por compatibilidad)
                if ( empty( $address ) ) {
                        $address = get_user_meta( $vendor_id, 'ltms_store_address', true ) ?: '';
                }

                // Fecha en español
                $fecha_contrato = gmdate( 'j \d\e F \d\e Y' );
                $meses          = [
                        'January' => 'enero', 'February' => 'febrero', 'March'     => 'marzo',
                        'April'   => 'abril', 'May'      => 'mayo',    'June'      => 'junio',
                        'July'    => 'julio', 'August'   => 'agosto',  'September' => 'septiembre',
                        'October' => 'octubre', 'November' => 'noviembre', 'December' => 'diciembre',
                ];
                $fecha_contrato = str_replace( array_keys( $meses ), array_values( $meses ), $fecha_contrato );

                return [
                        'vendor_name'       => $user->display_name,
                        'vendor_email'      => $user->user_email,
                        'vendor_doc_type'   => $doc_type,
                        'vendor_doc_number' => $doc_number,
                        'vendor_nit_label'  => $vendor_nit_label,
                        'vendor_address'    => $address,
                        'vendor_city'       => $city_name . ', ' . $this->get_department_name( $dane_code ),
                        'vendor_phone'      => $phone,
                        'vendor_store'      => get_user_meta( $vendor_id, 'ltms_store_name', true ) ?: $user->display_name,
                        'vendor_regime'     => $regime,
                        'vendor_dane'       => $dane_label,
                        'vendor_cc_number'  => $cc_number,
                        'commission_rate'   => $commission_rate,
                        'vendor_pct'        => $vendor_pct,
                        'fecha_generacion'  => gmdate( 'd/m/Y' ),
                        'fecha_contrato'    => $fecha_contrato,
                        'year'              => gmdate( 'Y' ),
                        'sagrilaft_uvt'     => '450 UVT (~$23.7M COP ' . gmdate( 'Y' ) . ')',
                ];
        }

        /**
         * Obtiene la tasa de comisión del vendedor (personalizada o global).
         * Retorna el porcentaje como entero (ej: 12 para 12%).
         */
        private function get_vendor_commission_rate( int $vendor_id ): int {
                $custom = get_user_meta( $vendor_id, 'ltms_custom_commission_rate', true );
                if ( $custom !== '' && $custom !== false ) {
                        $rate = (float) $custom;
                        return $rate < 1 ? (int) round( $rate * 100 ) : (int) $rate;
                }
                $global = LTMS_Core_Config::get( 'ltms_platform_commission_rate', 0.15 );
                $rate   = (float) $global;
                return $rate < 1 ? (int) round( $rate * 100 ) : (int) $rate;
        }

        /**
         * Retorna el nombre del departamento colombiano según el código DANE (2 dígitos).
         */
        private function get_department_name( string $dane_code ): string {
                if ( empty( $dane_code ) || strlen( $dane_code ) < 2 ) {
                        return 'Colombia';
                }
                $dept_code = substr( $dane_code, 0, 2 );
                $depts     = [
                        '05' => 'Antioquia',    '08' => 'Atlántico',          '11' => 'Bogotá D.C.',
                        '13' => 'Bolívar',      '15' => 'Boyacá',             '17' => 'Caldas',
                        '18' => 'Caquetá',      '19' => 'Cauca',              '20' => 'Cesar',
                        '23' => 'Córdoba',      '25' => 'Cundinamarca',       '27' => 'Chocó',
                        '41' => 'Huila',        '44' => 'La Guajira',         '47' => 'Magdalena',
                        '50' => 'Meta',         '52' => 'Nariño',             '54' => 'Norte de Santander',
                        '63' => 'Quindío',      '66' => 'Risaralda',          '68' => 'Santander',
                        '70' => 'Sucre',        '73' => 'Tolima',             '76' => 'Valle del Cauca',
                        '81' => 'Arauca',       '85' => 'Casanare',           '86' => 'Putumayo',
                        '88' => 'San Andrés',   '91' => 'Amazonas',           '94' => 'Guainía',
                        '95' => 'Guaviare',     '97' => 'Vaupés',             '99' => 'Vichada',
                ];
                return $depts[ $dept_code ] ?? 'Colombia';
        }

        // ── Renderizado HTML ──────────────────────────────────────────

        /**
         * Renderiza el HTML completo del contrato con los datos del vendedor.
         *
         * @param array<string, string|int> $v Variables del contrato.
         * @return string HTML del contrato listo para DOMPDF.
         */
        private function render_html( array $v ): string {
                $op      = self::OPERATOR;
                $version = self::CONTRACT_VERSION;
                $date    = self::CONTRACT_DATE;

                $commission_note = ( (int) $v['commission_rate'] !== 5 )
                        ? ', tarifa acordada de forma especial para el VENDEDOR <strong>' . esc_html( $v['vendor_store'] ) . '</strong>'
                          . ( ! empty( $v['vendor_cc_number'] ) ? ' (matrícula Cámara de Comercio ' . esc_html( $v['vendor_cc_number'] ) . ', NIT ' . esc_html( $v['vendor_nit_label'] ) . ')' : '' )
                          . ', aplicable a todas las categorías de productos comercializados en la Plataforma'
                        : '';

                ob_start();
                ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'DejaVu Sans',Arial,sans-serif; font-size:9pt; color:#1a1a1a; line-height:1.45; }
.page-header { background:#c0392b; color:white; padding:8px 14px; font-size:7.5pt; text-align:center; margin-bottom:0; }
.logo-bar { display:table; width:100%; border-bottom:2px solid #c0392b; padding:8px 0 6px; margin-bottom:10px; }
.logo-cell { display:table-cell; width:30%; vertical-align:middle; }
.title-cell { display:table-cell; width:70%; vertical-align:middle; text-align:right; color:#c0392b; font-weight:bold; font-size:10pt; }
.logo-placeholder { font-size:18pt; font-weight:bold; color:#c0392b; letter-spacing:-1px; }
.main-title { text-align:center; color:#c0392b; font-size:13pt; font-weight:bold; margin:8px 0 2px; text-transform:uppercase; }
.sub-title { text-align:center; font-size:10pt; font-weight:bold; margin-bottom:3px; }
.sub-meta { text-align:center; font-size:7.5pt; color:#555; margin-bottom:10px; }
.notice-box { background:#f5f5f5; border-left:4px solid #c0392b; padding:7px 10px; font-size:8pt; margin-bottom:12px; line-height:1.4; }
.clause-title { color:#c0392b; font-size:9.5pt; font-weight:bold; margin:12px 0 5px; text-transform:uppercase; border-bottom:1px solid #e0e0e0; padding-bottom:2px; }
.parties-table { width:100%; border-collapse:collapse; margin-bottom:10px; }
.parties-table th { background:#2c3e50; color:white; padding:6px 8px; font-size:8.5pt; text-align:left; }
.parties-table td { padding:5px 8px; border:1px solid #ddd; font-size:8pt; vertical-align:top; }
.parties-table tr:nth-child(even) td { background:#fafafa; }
table.data-table { width:100%; border-collapse:collapse; margin:6px 0 10px; font-size:8pt; }
table.data-table th { background:#c0392b; color:white; padding:5px 7px; text-align:left; }
table.data-table td { border:1px solid #ddd; padding:4px 7px; vertical-align:top; }
table.data-table tr:nth-child(even) td { background:#fafafa; }
ul.clause-list { margin:4px 0 6px 16px; font-size:8.5pt; }
ul.clause-list li { margin-bottom:3px; }
p.clause-body { font-size:8.5pt; margin-bottom:6px; }
.penalty-table th { background:#2c3e50; }
.sign-section { margin-top:20px; border-top:1px solid #ccc; padding-top:14px; }
.sign-table { width:100%; border-collapse:collapse; }
.sign-table td { width:50%; text-align:center; padding:10px 20px; vertical-align:top; }
.sign-line { border-top:1px solid #333; margin:30px auto 4px; width:80%; }
.sign-label { font-size:8pt; }
.footer-note { background:#f9f9f9; border:1px solid #ddd; border-radius:3px; padding:7px 10px; font-size:7.5pt; margin-top:10px; text-align:center; }
.page-footer { background:#c0392b; color:white; text-align:center; padding:5px; font-size:7pt; margin-top:14px; }
.annex-title { text-align:center; font-weight:bold; font-size:9.5pt; margin:16px 0 6px; color:#2c3e50; }
</style>
</head>
<body>

<div class="logo-bar">
  <div class="logo-cell"><div class="logo-placeholder">Lo<span style="color:#e67e22">T</span>engo</div></div>
  <div class="title-cell">CONTRATO DE VINCULACIÓN DE VENDEDOR<br>
    <span style="font-size:8pt;color:#555;">Versión <?php echo esc_html( $version ); ?> | <?php echo esc_html( $date ); ?></span>
  </div>
</div>

<div class="main-title">Contrato de Vinculación de Vendedor<br>al Marketplace Lo Tengo Colombia</div>
<div class="sub-title">Versión <?php echo esc_html( $version ); ?> — Jurisprudencia SIC 2019-2024 + Ley 2439/2024 + Ley 2300/2023 + 12 sentencias marketplace + GDPR + ISO 27001 + FATF Rec. 8</div>
<div class="sub-meta">Fecha de generación: <?php echo esc_html( $v['fecha_generacion'] ); ?> | Este documento tiene validez jurídica como contrato de adhesión (art. 37, Ley 1480/2011)</div>

<div class="notice-box">
  <strong>■■ PROTECCIÓN JURÍDICA DEL OPERADOR:</strong> La SIC ha establecido mediante sentencia (radicado 20-75269 del 01/03/2021, <em>Zapata vs. MercadoLibre</em>) que un portal de contacto queda exento de responsabilidad solidaria cuando: (1) actúa como mero intermediario tecnológico, (2) informa clara y verazmente su condición de intermediario, y (3) no interviene en la fijación de precios ni en las condiciones de la transacción.
</div>

<div class="clause-title">Cláusula Primera — Partes Contratantes</div>
<table class="parties-table">
  <tr><th>EL OPERADOR (Plataforma)</th><th>EL VENDEDOR (Comerciante)</th></tr>
  <tr>
    <td><?php echo esc_html( $op['name'] ); ?><br>NIT: <?php echo esc_html( $op['nit'] ); ?><br>Rep. Legal: <?php echo esc_html( $op['rep_legal'] ); ?><br>CC: <?php echo esc_html( $op['cc'] ); ?><br>Matrícula: <?php echo esc_html( $op['matricula'] ); ?><br>CIIU: <?php echo esc_html( $op['ciiu'] ); ?><br>Correo: <?php echo esc_html( $op['email'] ); ?><br>Plataforma: <?php echo esc_html( $op['url'] ); ?></td>
    <td><strong><?php echo esc_html( strtoupper( $v['vendor_name'] ) ); ?></strong><br>Tipo doc: <?php echo esc_html( $v['vendor_doc_type'] ); ?><br>Número: <?php echo esc_html( $v['vendor_doc_number'] ); ?><br>Dirección: <?php echo esc_html( $v['vendor_address'] ); ?><br>Ciudad: <?php echo esc_html( $v['vendor_city'] ); ?><br>Email: <?php echo esc_html( $v['vendor_email'] ); ?><br>Teléfono: <?php echo esc_html( $v['vendor_phone'] ); ?><br>Nombre de tienda: <strong><?php echo esc_html( strtoupper( $v['vendor_store'] ) ); ?></strong><br>Régimen tributario: <?php echo esc_html( $v['vendor_regime'] ); ?><br>Código DANE municipio: <?php echo esc_html( $v['vendor_dane'] ); ?></td>
  </tr>
</table>

<div class="clause-title">Cláusula Segunda — Objeto del Contrato</div>
<p class="clause-body">El presente instrumento regula la vinculación del VENDEDOR a la plataforma digital <strong>Lo Tengo Colombia</strong>, operada por GRUPO LO TENGO S.A.S. como intermediario tecnológico de comercio electrónico, conforme a los artículos 823 C.Co., Ley 527/1999, Ley 1480/2011, Ley 2439/2024 y la Res. 40/2018 SIC (Colombia); y la Ley Federal de Protección al Consumidor (LFPCE), Ley Federal de Competencia Económica (LFCE), Código de Comercio y Código Civil Federal (México). El VENDEDOR publica, promociona y comercializa sus productos/servicios a través de la Plataforma. El OPERADOR actúa <strong>exclusivamente como intermediario tecnológico</strong> y <strong>NO</strong> como parte compradora, vendedora, empleadora ni socia. Para operaciones en México, el OPERADOR actúa como marketplace facilitator conforme a la SCJN 437/2023 (Amazon MX) y recauda IVA conforme al LIVA art. 18-C.</p>

<div class="clause-title">Cláusula Tercera — Naturaleza Jurídica — Blindaje de Responsabilidad del Operador</div>
<p class="clause-body"><strong>3.1</strong> El OPERADOR cumple los 5 requisitos jurisprudenciales SIC (sent. 20-75269 del 01/03/2021) para ser calificado como portal de contacto exento de responsabilidad solidaria.</p>
<p class="clause-body"><strong>3.2</strong> El OPERADOR NO responde por: calidad/autenticidad/legalidad de productos del VENDEDOR; obligaciones tributarias, laborales o de seguridad social del VENDEDOR; vínculo laboral (relación estrictamente mercantil-comisional); disputas entre VENDEDOR y comprador; fallas de terceros (Openpay, Addi, Stripe, operadores logísticos); pérdidas por fuerza mayor o ciberataques de terceros.</p>
<p class="clause-body"><strong>3.3</strong> El OPERADOR implementa filtros razonables para prevenir productos peligrosos conforme a la sentencia SIC Rad. 23-064189 (2023). Los filtros activos incluyen: screening de keywords de falsificación (Ley 256/1996), validación de certificaciones sanitarias (Res. 831/2004 INVIMA, NOM-015-SCFI-1998), verificación ICA agropecuario (Ley 1011/2006), detección de precios predatorios (Ley 1340/2010), validación hazmat/IATA DGR (NOM-002-SCT/2011), y notice-and-takedown en 48h (SIC Rad. 21-184521). El panel admin "Defensa Marketplace" documenta estos filtros como evidencia de debido diligencia.</p>
<p class="clause-body"><strong>3.4</strong> El OPERADOR coopera con autoridades judiciales colombianas (SIC, DIAN, Fiscalía, UIAF), mexicanas (PROFECO, SAT, PGR, COFECE, IFT) e internacionales (OFAC, INTERPOL) conforme a la jurisprudencia CJEU Damache (2018). Las solicitudes deben presentarse vía oficio formal con respuesta en 15 días hábiles (CO) / 10 días hábiles (MX, LFPCE art. 99).</p>
<p class="clause-body"><strong>3.5</strong> Para operaciones en México, el OPERADOR actúa como intermediario tecnológico conforme al Amparo 163/2022 (MercadoLibre MX), no siendo considerado proveedor del producto. El OPERADOR cumple la LFCE art. 53-57 (COFECE/IFT) en materia de competencia económica, evitando prácticas restrictivas como predación de precios, discriminación y precios excesivos.</p>

<div class="clause-title">Cláusula Cuarta — Derechos del Vendedor</div>
<ul class="clause-list">
  <li>Publicar productos con fotos, descripciones y precios en la Plataforma.</li>
  <li>Recibir el <?php echo esc_html( $v['vendor_pct'] ); ?>% del valor neto de cada venta efectiva, deducida comisión del <?php echo esc_html( $v['commission_rate'] ); ?>%, impuestos y retenciones de ley.</li>
  <li>Acceder al panel de vendedor con estadísticas en tiempo real, gestión de pedidos, historial de pagos y wallet digital.</li>
  <li>Solicitar soporte por correo (<?php echo esc_html( $op['email'] ); ?>) con trazabilidad de radicado, hora y fecha (Ley 2439/2024, art. 50-g).</li>
  <li>Conocer y objetar, dentro de los 5 días hábiles siguientes a la notificación, cualquier sanción, retención o bloqueo.</li>
</ul>

<div class="clause-title">Cláusula Quinta — Obligaciones del Vendedor</div>
<ul class="clause-list">
  <li>Garantizar que los productos son de su propiedad o cuenta con autorización legal para comercializarlos.</li>
  <li>Proporcionar información veraz, completa y actualizada conforme a Ley 2439/2024, art. 50-b.</li>
  <li>Emitir factura electrónica DIAN de cada venta (modelo 'cada vendedor factura su propio producto', Res. DIAN 42/2020).</li>
  <li>Completar el proceso KYC: cédula, RUT, Cámara de Comercio (si aplica), foto del documento, firma digital del presente contrato vía ZapSign.</li>
  <li>Responder reclamaciones de compradores dentro de 3 días hábiles con número de radicado (Ley 2439/2024, art. 50-g).</li>
  <li>Respetar el derecho de retracto del comprador: 5 días hábiles desde recepción en Colombia (art. 47 Ley 1480/2011 mod. Ley 2439/2024) o 10 días naturales en México (LFPCE art. 92, PROFECO). Este derecho es irrenunciable (Corte Const. C-939/2016).</li>
  <li>Para México: emitir CFDI 4.0 por cada venta (CFF art. 29-A), incluir complemento Carta Porte 3.0 para envíos terrestres (RMF 2026 Anexo 20), y aceptar pagos vía OXXO y SPEI.</li>
  <li>No contactar directamente a los compradores fuera de la Plataforma con fines de eludir comisiones.</li>
</ul>

<div class="clause-title">Cláusula Sexta — Productos y Conductas Prohibidas</div>
<ul class="clause-list">
  <li>Armas de fuego, municiones, explosivos (Decreto 2535/1993 CO; Ley Federal de Armas de Fuego y Explosivos MX).</li>
  <li>Estupefacientes, sustancias psicotrópicas o precursores químicos (Ley 30/1986 CO; Ley General de Salud MX art. 475).</li>
  <li>Bienes hurtados, de procedencia ilícita o sin título de dominio válido.</li>
  <li>Contenido que vulnere derechos de autor, marcas o patentes sin autorización expresa (Ley 23/1982, Ley 1915/2018 CO; Ley Federal del Derecho de Autor CO; Ley de Propiedad Industrial LPI art. 223-231 MX, IMPI).</li>
  <li>Esquemas financieros no autorizados por SFC (Ponzi, pirámides, captación masiva) (CO); CNBV y SHCP (MX).</li>
  <li>Artículos falsificados o que induzcan a confusión sobre origen comercial (Ley 256/1996 CO art. 20; LPI art. 223 MX).</li>
  <li>Productos que incumplan NOM-051-SCFI/SSI-2010 (etiquetado nutricional MX) o Resolución 333/2011 INVIMA (etiquetado CO) sin Nutri-Score declarado.</li>
</ul>

<div class="clause-title">Cláusula Séptima — Comisiones, Retenciones y Liquidaciones</div>
<p class="clause-body">El OPERADOR percibirá una comisión del <strong><?php echo esc_html( $v['commission_rate'] ); ?>% sobre el precio de venta</strong> de cada producto efectivamente transaccionado<?php echo wp_kses_post( $commission_note ); ?>.</p>
<table class="data-table">
  <tr><th>Concepto</th><th>Condición / Norma</th></tr>
  <tr><td>Comisión OPERADOR</td><td><?php echo esc_html( $v['commission_rate'] ); ?>% sobre precio de venta al público</td></tr>
  <tr><td>Pago al VENDEDOR</td><td><?php echo esc_html( $v['vendor_pct'] ); ?>% del valor neto (precio - comisión - retenciones de ley)</td></tr>
  <?php if ( ! empty( $v['vendor_cc_number'] ) ) : ?>
  <tr><td>Matrícula Cámara de Comercio</td><td><?php echo esc_html( $v['vendor_cc_number'] ); ?> | Renovada <?php echo esc_html( $v['year'] ); ?></td></tr>
  <?php endif; ?>
  <tr><td>Frecuencia de pago</td><td>Quincenal: días 15 y último hábil de cada mes</td></tr>
  <tr><td>Método de pago CO</td><td>Transferencia bancaria / PSE / Nequi / Daviplata a cuenta registrada y verificada</td></tr>
  <tr><td>Método de pago MX</td><td>SPEI / OXXO / tarjeta (Openpay MX) a cuenta CLABE registrada</td></tr>
  <tr><td>UMA 2026 (MX)</td><td>$108.57 MXN — referencia para cálculos LFPIDRPI, LFPCE, LIVA</td></tr>
  <tr><td>ReteFuente (CO)</td><td>Según régimen tributario del VENDEDOR (ET art. 368-369, ET art. 392)</td></tr>
  <tr><td>RESICO (MX)</td><td>1.25%-3% según ingresos mensuales (LISR art. 113-A MX)</td></tr>
  <tr><td>IVA retenido MX</td><td>100% del IVA si vendor es persona moral (LIVA art. 1-A fr. II)</td></tr>
  <tr><td>IEPS (MX)</td><td>8%-160% según categoría: tabaco, alcohol, bebidas azucaradas (LIEPS art. 2)</td></tr>
  <tr><td>ISH (MX)</td><td>3% hospedaje por estado (impuesto local)</td></tr>
  <tr><td>ICE (CO)</td><td>35% alcohol, 75%+cuota tabaco (ET art. 468-469)</td></tr>
  <tr><td>GMF 4x1000 (CO)</td><td>0.4% sobre retiros bancarios, exención 350 UVT mensual (ET art. 871)</td></tr>
  <tr><td>Período mínimo de hold</td><td>5 días hábiles desde entrega (CO, Ley 1480 art. 47) / 10 días naturales (MX, LFPCE art. 92)</td></tr>
  <tr><td>SAGRILAFT — umbral</td><td>Transacciones ≥ <?php echo esc_html( $v['sagrilaft_uvt'] ); ?> sujetas a monitoreo UIAF</td></tr>
  <tr><td>Screening OFAC/ONU/UE</td><td>Verificación automática pre-KYC + re-screen mensual (FT-2)</td></tr>
  <tr><td>Límite operativo diario</td><td>USD $5,000 equivalentes por VENDEDOR (FT-3)</td></tr>
  <tr><td>Límite operativo mensual</td><td>USD $20,000 equivalentes por VENDEDOR (FT-3)</td></tr>
  <tr><td>Travel Rule</td><td>Transferencias ≥ USD $1,000 con datos originante/beneficiario (FATF Rec. 16)</td></tr>
  <tr><td>PCI DSS</td><td>SAQ-A v4.0 — tokenización Openpay/Stripe, PAN no almacenado (FT-5)</td></tr>
  <tr><td>2FA Vendedores</td><td>Obligatorio para VENDEDORES con payouts en últimos 30 días (FT-6)</td></tr>
  <tr><td>Retención IVA no residentes</td><td>100% del IVA si vendor país ≠ país operativo (CB-6)</td></tr>
  <tr><td>IOSS UE</td><td>IVA país destino para ventas < €150 (CB-3)</td></tr>
  <tr><td>Incoterms 2020</td><td>11 reglas ICC soportadas: EXW, FCA, FAS, FOB, CFR, CIF, CPT, CIP, DAP, DPU, DDP (CB-2)</td></tr>
  <tr><td>Carta Porte CFDI 4.0</td><td>Complemento obligatorio para transporte terrestre MX (LT-1)</td></tr>
  <tr><td>Nutri-Score / NOM-051</td><td>Obligatorio para productos alimenticios (JU-7, PROFECO 2024)</td></tr>
</table>

<div class="clause-title">Cláusula Octava — Módulos Especiales</div>
<table class="data-table">
  <tr><th>Módulo</th><th>Descripción</th><th>Condición</th></tr>
  <tr><td>ReDi (Reventa Distribuida)</td><td>Permite que otros vendedores adopten y vendan los productos del VENDEDOR.</td><td>Opt-in desde el panel</td></tr>
  <tr><td>Red MLM / Afiliados</td><td>Sistema de referidos en 3 niveles (0.75%, 1.5%, 0.5%)</td><td>Activación en panel</td></tr>
  <tr><td>Reservas (Bookings)</td><td>Para productos/servicios con calendario. Políticas de cancelación configurables.</td><td>Aplicable a servicios</td></tr>
  <tr><td>Firma Digital ZapSign</td><td>Los contratos se firman via ZapSign (Ley 527/1999). La firma electrónica es legalmente equivalente a la manuscrita.</td><td>Obligatorio para KYC</td></tr>
  <tr><td>KDS (Cocina/Preparación)</td><td>Sistema de pantalla de pedidos para vendedores de alimentos.</td><td>Solo categoría alimentos</td></tr>
  <tr><td>Seguros XCover</td><td>Seguro de producto integrado. El comprador es el asegurado.</td><td>Opt-in por categoría</td></tr>
  <tr><td>Comercio Cross-Border</td><td>Venta internacional con cálculo de aranceles (DDP/DDU), FX multi-moneda y declaraciones aduaneras.</td><td>Opt-in; Ley 2439/2024 art. 50-j</td></tr>
  <tr><td>Turismo (RNT/SECTUR)</td><td>Venta de servicios turísticos requiere RNT vigente (FONTUR CO) o registro SECTUR (MX). Ley 2068/2020 CO, LFT MX.</td><td>Obligatorio para categoría turismo</td></tr>
  <tr><td>TPTC (Te Paga Tus Compras)</td><td>Programa de lealtad operado por TPTC S.A.S. (entidad independiente). Bonificaciones: N1=0.75%, N2=1.5%, N3=0.5%. Compra mínima $511/mes.</td><td>Activación independiente</td></tr>
  <tr><td>Donaciones Fundación</td><td>Donación automática de comisiones a fundación RTE (Decreto 832/2019). Certificado deducible ET art. 125 (máx 1,000 UVT, 25% neto).</td><td>Opt-out del VENDEDOR</td></tr>
  <tr><td>Sistema PQR</td><td>PQR formal con número radicado PQR-YYYY-XXXXXXX. SLA: 15 días hábiles (CO) / 10 días (MX). Conciliación SIC/PROFECO disponible.</td><td>Ley 1480/2011 art. 53</td></tr>
  <tr><td>Nutri-Score / NOM-051</td><td>Etiquetado nutricional obligatorio para productos alimenticios (PROFECO 2024, NOM-051-SCFI/SSI-2010).</td><td>Categoría alimentos</td></tr>
  <tr><td>Notice-and-Takedown 48h</td><td>Sistema de retiro de productos infractores en 48h tras notificación (SIC Rad. 21-184521/2021).</td><td>Automático</td></tr>
  <tr><td>Transparencia ESAL</td><td>Página pública /transparencia/ con donaciones agregadas (Res. 0280/2016 DAFP).</td><td>Automático</td></tr>
</table>

<div class="clause-title">Cláusula Novena — Vigencia y Terminación</div>
<p class="clause-body">Vigencia indefinida desde la fecha de firma digital. Terminación con <strong>30 días</strong> de preaviso escrito. El OPERADOR puede terminar de forma inmediata en caso de: venta de productos prohibidos; conducta fraudulenta; instrucción judicial; inclusión en listas de riesgo OFAC/ONU/UIAF; evasión de comisiones documentada; publicación reiterada de productos falsificados (Ley 256/1996 + Ley 599/2000 art. 304); infracción de propiedad intelectual no subsanada tras notice-and-takedown (SIC Rad. 21-184521); publicidad engañosa reiterada (SIC Res. 40/2018); incumplimiento de obligaciones SAGRILAFT/SARLAFT; revocación del RUT por DIAN o del RFC por SAT; vencimiento de registro sanitario INVIMA/COFEPRIS sin renovación.</p>

<div class="clause-title">Cláusula Décima — Penalidades (Art. 1592 C.C.)</div>
<table class="data-table penalty-table">
  <tr><th>Conducta Infractora</th><th>Sanción Pactada</th></tr>
  <tr><td>Evasión de comisiones</td><td>300% de la comisión evadida por transacción</td></tr>
  <tr><td>Publicación de producto falsificado/prohibido</td><td>$5.000.000 COP por cada SKU infractor</td></tr>
  <tr><td>Contacto directo con comprador para eludir comisión</td><td>$2.000.000 COP por caso documentado</td></tr>
  <tr><td>Incumplimiento de despacho (&gt;5 días hábiles)</td><td>2% del valor del pedido/día, máx. 30%</td></tr>
</table>

<div class="clause-title">Cláusula Décima Primera — SAGRILAFT / SIPLAFT / AML / CTF</div>
<p class="clause-body">El VENDEDOR declara que los recursos con que comercializa en la Plataforma son de <strong>origen lícito</strong>, no provienen de lavado de activos, financiación del terrorismo, corrupción ni actividades ilícitas (Ley 526/1999, Ley 1762/2015, FATF Recomendación 8). El OPERADOR realiza screening del VENDEDOR contra listas restrictivas OFAC SDN, UN Consolidated y EU Restrictive Measures antes de aprobar el KYC, y re-screening mensual de todos los VENDEDORES activos. El OPERADOR podrá suspender o terminar el contrato y reportar a la UIAF/SHCP si encuentra indicios razonables de origen ilícito en transacciones ≥ <?php echo esc_html( $v['sagrilaft_uvt'] ); ?>. El OPERADOR genera reportes SOS mensuales a la UIAF (Colombia) y a 24h a la SHCP (México) conforme a la Resolución UIAF 029/2014 y la LFPIDRPI art. 17-18.</p>

<div class="clause-title">Cláusula Décima Segunda — Protección al Consumidor y Derecho de Retracto</div>
<ul class="clause-list">
  <li>El comprador tiene derecho de retracto dentro de los <strong>5 días hábiles</strong> siguientes a la recepción del producto (art. 47 Ley 1480/2011 mod. Ley 2439/2024). Este derecho es <strong>irrenunciable</strong> conforme a la Corte Constitucional C-939/2016 y no puede ser limitado por ningún término del presente contrato.</li>
  <li>El VENDEDOR es el obligado principal frente al comprador en caso de retracto, garantía legal o producto defectuoso. La Plataforma ofrece cauce de PQR específico por vendor (SIC Rad. 22-152704/2022 Rappi vs SIC).</li>
  <li>La devolución del dinero al comprador debe completarse en <strong>15 días calendario</strong> desde el ejercicio del retracto (Ley 2439/2024).</li>
  <li>El OPERADOR implementará notice-and-takedown en 48h para productos infractores (SIC Rad. 21-184521/2021 MercadoLibre vs SIC). El VENDEDOR acepta que sus productos pueden ser despublicados sin previo aviso si se detectan infracciones de propiedad intelectual.</li>
</ul>

<div class="clause-title">Cláusula Décima Tercera — Protección de Datos Personales y Habeas Data</div>
<p class="clause-body">El tratamiento de datos personales se rige por la Política de Privacidad de Lo Tengo Colombia, la Ley 1581/2012 (Habeas Data CO), el Decreto 1377/2013, el Decreto 1727/2024 (Registro SIC como Responsable de Tratamiento CO), la Ley Federal de Protección de Datos Personales en Posesión de los Particulares (LFPDPPP MX) y sus Lineamientos del Aviso de Privacidad (INAI 2017), y el Reglamento General de Protección de Datos (GDPR, UE). El OPERADOR tiene designado Encargado de Protección de Datos (DPO) contacto disponible en el pie de página del sitio web. Para México, el OPERADOR mantiene aviso de privacidad simplificado (LFPDPPP art. 17) e integral (art. 16) diferenciados.</p>
<p class="clause-body">El VENDEDOR autoriza expresamente el tratamiento de sus datos para: ejecución del contrato; cumplimiento de obligaciones tributarias y SAGRILAFT; comunicaciones comerciales; transferencias internacionales a aliados tecnológicos (Openpay MX, ZapSign BR, Stripe US, AWS US, Backblaze US, Uber Direct US, XCover AU) bajo Cláusulas Contractuales Tipo UE art. 46, Ley 1581/2012 art. 26 y LFPDPPP art. 37. Los datos sensibles (documento de identidad, cuenta bancaria, NIT/RUT) se cifran con AES-256-GCM.</p>
<p class="clause-body">El VENDEDOR conoce y acepta sus derechos ARCO (Acceso, Rectificación, Cancelación, Oposición) ejercibles vía endpoint REST <code>/wp-json/ltms/v1/arco/access</code>. El OPERADOR mantiene bitácora de acceso a datos personales (Ley 1581/2012 art. 15) consultable por el titular. Las brechas de seguridad se notificarán a la SIC y a los afectados dentro de las 72 horas siguientes (GDPR art. 33-34, Ley 1581/2012 art. 22, LFPDPPP art. 20).</p>

<div class="clause-title">Cláusula Décima Cuarta — Ley Aplicable y Resolución de Controversias</div>
<table class="data-table">
  <tr><th>Etapa</th><th>Mecanismo</th><th>Plazo</th></tr>
  <tr><td>1</td><td>Negociación directa (comunicación escrita al correo registrado)</td><td>15 días hábiles (CO) / 10 días (MX)</td></tr>
  <tr><td>2 CO</td><td>Mediación/conciliación — Centro de Arbitraje CCC Cali (Ley 640/2001)</td><td>30 días siguientes</td></tr>
  <tr><td>2 MX</td><td>Mediación PROFECO (Ley 763/2018) — Sede Electrónica PROFECO</td><td>30 días siguientes</td></tr>
  <tr><td>3 CO</td><td>Jueces civiles competentes de Cali, Valle del Cauca, Colombia</td><td>Sin plazo límite</td></tr>
  <tr><td>3 MX</td><td>Tribunales competentes de la Ciudad de México (fuero federal/comercial)</td><td>Sin plazo límite</td></tr>
  <tr><td>CO — Monto ≤ 40 SMMLV</td><td>SIC — Delegatura Protección al Consumidor</td><td>Según agenda SIC</td></tr>
  <tr><td>MX — Cualquier monto</td><td>PROFECO — Delegación federal del consumidor</td><td>Según agenda PROFECO</td></tr>
</table>
<p class="clause-body">La ley aplicable es la colombiana para operaciones en Colombia (Ley 1480/2011, Código de Comercio) y la mexicana para operaciones en México (LFPCE, Código de Comercio, Código Civil Federal). Para disputas cross-border, las partes someten a la jurisdicción del país del comprador.</p>

<div class="clause-title">Cláusula Décima Quinta — Declaraciones Bajo Juramento (Art. 442 C.P. CO / Art. 442 C.P. Federal MX)</div>
<p class="clause-body">El VENDEDOR declara bajo la gravedad del juramento: que tiene plena capacidad legal para contratar; que es mayor de 18 años (COPPA + Decreto 886/2014 CO; LFPDPPP art. 17 MX); que la información suministrada en el proceso de KYC y en este contrato es verídica y completa; que los productos que comercializará son de lícita procedencia; que cumple todas sus obligaciones tributarias ante la DIAN (Colombia) y el SAT (México); que su RUT (CO) o RFC (MX) se encuentra activo y vigente; que no se encuentra en listas de riesgo OFAC, ONU, UIAF (CO), SHCP (MX) ni Fiscalía General de la Nación; que ha leído y acepta íntegramente este contrato, la Política de Privacidad, los Términos y Condiciones y el Manual del Vendedor; que para México acepta la jurisdicción de PROFECO y COFECE, y que su información ante el padrón SAT es correcta y actualizada.</p>

<div class="clause-title">Cláusula Décima Sexta — Cumplimiento AML/CTF y SARLAFT</div>
<p class="clause-body">El OPERADOR cumple la Ley 526/1999 (SARLAFT), la FATF Recomendación 8 (sector NPO), y la Ley Fintech art. 87 (México). El OPERADOR realiza screening de todos los VENDEDORES contra las listas restrictivas OFAC SDN (USA), UN Consolidated (ONU) y EU Restrictive Measures (UE) antes de aprobar el KYC. Los VENDEDORES que aparezcan en dichas listas serán bloqueados y reportados a las autoridades competentes. El OPERADOR aplica límites operativos diarios (USD $5,000) y mensuales (USD $20,000) por VENDEDOR, y Travel Rule para transferencias ≥ USD $1,000 (FATF Rec. 16, Banxico Anexo 25, Circular SFC 029/2014).</p>

<div class="clause-title">Cláusula Décima Séptima — Cumplimiento Fintech y PCI DSS</div>
<p class="clause-body">El OPERADOR cumple el estándar PCI DSS v4.0 SAQ-A (tokenización vía Openpay/Stripe, PAN nunca almacena en servidores LTMS). El OPERADOR mantiene declaración formal SAQ-A con firma anual. Los datos de tarjetas se tokenizan en el cliente (browser) antes de ser enviados a los servidores. El OPERADOR cumple la Ley Fintech art. 95 (México) sobre controles de seguridad (2FA obligatorio para VENDEDORES con payouts activos). El OPERADOR reporta anualmente a DIAN (Forma 4, operaciones FX > USD $10,000) y a SAT (Aviso Banxico, México).</p>

<div class="clause-title">Cláusula Décima Octava — Comercio Cross-Border</div>
<p class="clause-body">Para operaciones cross-border (origen ≠ destino), el OPERADOR cumple: Res. DIAN 000070/2020 (país de origen + DVA), Ley de Comercio Exterior art. 31 (MX), Reglamento (UE) 1169/2011 art. 9 (país de origen), US 15 CFR 740 + 19 CFR 30.1 (AES/EEI exports > $2,500 USD). El OPERADOR aplica automáticamente aranceles preferenciales TLC (ACE 65 CAN-MX, T-MEC, TPA CO-US, Acuerdo CO-UE). Los VENDEDORES no residentes están sujetos a retención del 100% del IVA generado (ET art. 437-3 CO, LIVA art. 3 fr. III MX). Para ventas a UE < €150 se aplica IOSS (Reglamento UE 2017/2455) con IVA del país destino.</p>

<div class="clause-title">Cláusula Décima Novena — Cumplimiento Logístico y Transporte</div>
<p class="clause-body">El OPERADOR verifica el RNT del carrier (Res. 4146/2016 Mintransporte CO) y el permiso SCT (Ley de Caminos art. 5 MX) del operador logístico contratado. Para transporte terrestre en México se emite complemento Carta Porte CFDI 4.0 (RMF 2026 Anexo 20). El OPERADOR valida pesos y dimensiones máximas (NOM-012-SCT-2/2014 MX, Res. 4100/2004 CO). El OPERADOR exige póliza RC transportista (Res. 4146/2016 art. 18 CO, Ley de Caminos art. 66 MX). Para carga de alto valor se exige GPS satelital (Ley de Caminos art. 47-A MX). Para contenedores cross-border se exigen sellos ISO/PAS 17712.</p>

<div class="clause-title">Cláusula Vigésima — Seguridad de la Información</div>
<p class="clause-body">El OPERADOR implementa controles de seguridad conforme a ISO 27001 (A.7.2.2 concientización, A.10.1.1 cifrado, A.10.1.2 gestión de claves, A.12.4.1 logging, A.14.2.5 desarrollo seguro, A.16 gestión de incidentes) y NIST SP 800-53 SC-28 (cifrado at rest) + SP 800-57 (gestión de claves). Los headers de seguridad incluyen: Content-Security-Policy (OWASP A05:2021), HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy. Las claves criptográficas se rotan anualmente. El OPERADOR mantiene Content-Security-Policy estricta para prevenir XSS.</p>

<div class="sign-section">
  <div class="clause-title">Firmas de las Partes</div>
  <p class="clause-body">Las partes suscriben este contrato como señal de aceptación íntegra. La firma digital vía ZapSign tiene plena validez jurídica conforme a los artículos 7 y 28 de la Ley 527/1999 y la Corte Constitucional (C-662/2000).</p>
  <table class="sign-table">
    <tr>
      <td>
        <div class="sign-line"></div>
        <div class="sign-label"><strong>EL OPERADOR</strong><br><?php echo esc_html( $op['name'] ); ?><br>NIT: <?php echo esc_html( $op['nit'] ); ?><br><?php echo esc_html( $op['rep_legal'] ); ?><br>Rep. Legal — CC <?php echo esc_html( $op['cc'] ); ?><br><?php echo esc_html( $op['ciudad'] ); ?></div>
      </td>
      <td>
        <div class="sign-line"></div>
        <div class="sign-label"><strong>EL VENDEDOR</strong><br><?php echo esc_html( strtoupper( $v['vendor_name'] ) ); ?><br><?php echo esc_html( $v['vendor_doc_type'] ); ?>: <?php echo esc_html( $v['vendor_doc_number'] ); ?><br><?php echo esc_html( $v['vendor_city'] ); ?>, <?php echo esc_html( $v['fecha_contrato'] ); ?><br><?php echo esc_html( $v['vendor_email'] ); ?></div>
      </td>
    </tr>
  </table>
  <div class="footer-note"><strong>■</strong> Este contrato se firma digitalmente vía ZapSign. El token de firma tiene validez legal (Ley 527/1999, art. 7). La firma electrónica equivale a firma manuscrita (Corte Constitucional, C-662/2000).</div>
</div>

<div class="page-footer">Documento generado automáticamente por GRUPO LO TENGO S.A.S. · NIT 901.981.692-3 · Versión <?php echo esc_html( $version ); ?> · <?php echo esc_html( $date ); ?> · 120+ normas cubiertas · 12 sentencias marketplace · 17 módulos de compliance</div>

</body>
</html>
                <?php
                return ob_get_clean();
        }

        // ── DOMPDF ───────────────────────────────────────────────────

        /**
         * Convierte HTML a PDF usando DOMPDF y retorna el contenido binario.
         */
        private function html_to_pdf( string $html ): string {
                $autoloader = plugin_dir_path( __FILE__ ) . '../../vendor/autoload.php';
                if ( file_exists( $autoloader ) ) {
                        require_once $autoloader;
                }

                $options = new \Dompdf\Options();
                $options->set( 'defaultFont', 'DejaVu Sans' );
                $options->set( 'isRemoteEnabled', false );     // CVE-2023-6020 mitigation
                $options->set( 'isPhpEnabled', false );        // SEC-14 FIX (v2.9.27): explicit disable PHP execution in PDF
                $options->set( 'isHtml5ParserEnabled', true );
                $options->set( 'isFontSubsettingEnabled', true );
                $options->set( 'chroot', sys_get_temp_dir() ); // CVE-2024-55853 mitigation: restrict file access

                $dompdf = new \Dompdf\Dompdf( $options );
                $dompdf->loadHtml( $html, 'UTF-8' );
                $dompdf->setPaper( 'letter', 'portrait' );
                $dompdf->render();

                return $dompdf->output();
        }

        /**
         * Verifica que DOMPDF esté disponible; lanza excepción si no.
         *
         * @throws \RuntimeException Si DOMPDF no está instalado.
         */
        private function ensure_dompdf(): void {
                $autoloader = plugin_dir_path( __FILE__ ) . '../../vendor/autoload.php';
                if ( ! file_exists( $autoloader ) ) {
                        throw new \RuntimeException( '[ltms-pdf] vendor/autoload.php no encontrado. Ejecuta composer install.' );
                }
                require_once $autoloader;
                if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
                        throw new \RuntimeException( '[ltms-pdf] DOMPDF no está disponible. Verifica composer.json.' );
                }
        }
}
