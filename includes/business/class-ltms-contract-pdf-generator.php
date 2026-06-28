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
 * @version    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LTMS_Contract_PDF_Generator
 */
class LTMS_Contract_PDF_Generator {

	use LTMS_Logger_Aware;

	const CONTRACT_VERSION = '4.0 Ultra-Blindado';
	const CONTRACT_DATE    = '18 de mayo de 2026';
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

		// Teléfono
		$phone = get_user_meta( $vendor_id, 'ltms_phone', true )
				?: get_user_meta( $vendor_id, 'billing_phone', true )
				?: '';

		// Dirección
		$address = get_user_meta( $vendor_id, 'billing_address_1', true ) ?: '';
		$addr2   = get_user_meta( $vendor_id, 'billing_address_2', true );
		if ( $addr2 ) {
			$address .= ' ' . $addr2;
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
			'sagrilaft_uvt'     => '450 UVT (~$22.4M COP ' . gmdate( 'Y' ) . ')',
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
		$global = LTMS_Core_Config::get( 'ltms_platform_commission_rate', 0.05 );
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
<div class="sub-title">Versión <?php echo esc_html( $version ); ?> — Jurisprudencia SIC 2019-2024 + Ley 2439/2024 + Ley 2300/2023</div>
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
<p class="clause-body">El presente instrumento regula la vinculación del VENDEDOR a la plataforma digital <strong>Lo Tengo Colombia</strong>, operada por GRUPO LO TENGO S.A.S. como intermediario tecnológico de comercio electrónico, conforme a los artículos 823 C.Co., Ley 527/1999, Ley 1480/2011, Ley 2439/2024 y la Res. 40/2018 SIC. El VENDEDOR publica, promociona y comercializa sus productos/servicios a través de la Plataforma. El OPERADOR actúa <strong>exclusivamente como intermediario tecnológico</strong> y <strong>NO</strong> como parte compradora, vendedora, empleadora ni socia.</p>

<div class="clause-title">Cláusula Tercera — Naturaleza Jurídica — Blindaje de Responsabilidad del Operador</div>
<p class="clause-body"><strong>3.1</strong> El OPERADOR cumple los 5 requisitos jurisprudenciales SIC (sent. 20-75269 del 01/03/2021) para ser calificado como portal de contacto exento de responsabilidad solidaria.</p>
<p class="clause-body"><strong>3.2</strong> El OPERADOR NO responde por: calidad/autenticidad/legalidad de productos del VENDEDOR; obligaciones tributarias, laborales o de seguridad social del VENDEDOR; vínculo laboral (relación estrictamente mercantil-comisional); disputas entre VENDEDOR y comprador; fallas de terceros (Openpay, Addi, Stripe, operadores logísticos); pérdidas por fuerza mayor o ciberataques de terceros.</p>

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
  <li>Respetar el derecho de retracto del comprador (5 días hábiles desde recepción, art. 47 Ley 1480/2011 mod. Ley 2439/2024).</li>
  <li>No contactar directamente a los compradores fuera de la Plataforma con fines de eludir comisiones.</li>
</ul>

<div class="clause-title">Cláusula Sexta — Productos y Conductas Prohibidas</div>
<ul class="clause-list">
  <li>Armas de fuego, municiones, explosivos (Decreto 2535/1993).</li>
  <li>Estupefacientes, sustancias psicotrópicas o precursores químicos (Ley 30/1986).</li>
  <li>Bienes hurtados, de procedencia ilícita o sin título de dominio válido.</li>
  <li>Contenido que vulnere derechos de autor, marcas o patentes sin autorización expresa (Ley 23/1982, Ley 1915/2018).</li>
  <li>Esquemas financieros no autorizados por SFC (Ponzi, pirámides, captación masiva).</li>
  <li>Artículos falsificados o que induzcan a confusión sobre origen comercial (Ley 256/1996).</li>
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
  <tr><td>Método de pago</td><td>Transferencia bancaria / PSE a cuenta registrada y verificada</td></tr>
  <tr><td>ReteFuente</td><td>Según régimen tributario del VENDEDOR (art. 368 E.T.)</td></tr>
  <tr><td>Período mínimo de hold</td><td>5 días hábiles desde confirmación de entrega (art. 47, Ley 1480/2011 mod. 2439/2024)</td></tr>
  <tr><td>SAGRILAFT — umbral</td><td>Transacciones ≥ <?php echo esc_html( $v['sagrilaft_uvt'] ); ?> sujetas a monitoreo UIAF</td></tr>
</table>

<div class="clause-title">Cláusula Octava — Módulos Especiales</div>
<table class="data-table">
  <tr><th>Módulo</th><th>Descripción</th><th>Condición</th></tr>
  <tr><td>ReDi (Reventa Distribuida)</td><td>Permite que otros vendedores adopten y vendan los productos del VENDEDOR.</td><td>Opt-in desde el panel</td></tr>
  <tr><td>Red MLM / Afiliados</td><td>Sistema de referidos en 3 niveles (0.75%, 1.5%, 0.5%)</td><td>Activación en panel</td></tr>
  <tr><td>Reservas (Bookings)</td><td>Para productos/servicios con calendario. Políticas de cancelación configurables.</td><td>Aplicable a servicios</td></tr>
  <tr><td>Firma Digital ZapSign</td><td>Los contratos se firman via ZapSign (Ley 527/1999). La firma electrónica es legalmente equivalente a la manuscrita.</td><td>Obligatorio para KYC</td></tr>
</table>

<div class="clause-title">Cláusula Novena — Vigencia y Terminación</div>
<p class="clause-body">Vigencia indefinida desde la fecha de firma digital. Terminación con <strong>30 días</strong> de preaviso escrito. El OPERADOR puede terminar de forma inmediata en caso de: venta de productos prohibidos; conducta fraudulenta; instrucción judicial; inclusión en listas de riesgo OFAC/ONU/UIAF; evasión de comisiones documentada.</p>

<div class="clause-title">Cláusula Décima — Penalidades (Art. 1592 C.C.)</div>
<table class="data-table penalty-table">
  <tr><th>Conducta Infractora</th><th>Sanción Pactada</th></tr>
  <tr><td>Evasión de comisiones</td><td>300% de la comisión evadida por transacción</td></tr>
  <tr><td>Publicación de producto falsificado/prohibido</td><td>$5.000.000 COP por cada SKU infractor</td></tr>
  <tr><td>Contacto directo con comprador para eludir comisión</td><td>$2.000.000 COP por caso documentado</td></tr>
  <tr><td>Incumplimiento de despacho (&gt;5 días hábiles)</td><td>2% del valor del pedido/día, máx. 30%</td></tr>
</table>

<div class="clause-title">Cláusula Décima Primera — SAGRILAFT / SIPLAFT</div>
<p class="clause-body">El VENDEDOR declara que los recursos con que comercializa en la Plataforma son de <strong>origen lícito</strong>, no provienen de lavado de activos, financiación del terrorismo, corrupción ni actividades ilícitas (Ley 526/1999, Ley 1762/2015). El OPERADOR podrá suspender o terminar el contrato y reportar a la UIAF si encuentra indicios razonables de origen ilícito en transacciones ≥ <?php echo esc_html( $v['sagrilaft_uvt'] ); ?>.</p>

<div class="clause-title">Cláusula Décima Segunda — Protección al Consumidor y Derecho de Retracto</div>
<ul class="clause-list">
  <li>El comprador tiene derecho de retracto dentro de los <strong>5 días hábiles</strong> siguientes a la recepción del producto (art. 47 Ley 1480/2011 mod. Ley 2439/2024).</li>
  <li>El VENDEDOR es el obligado principal frente al comprador en caso de retracto, garantía legal o producto defectuoso.</li>
  <li>La devolución del dinero al comprador debe completarse en <strong>15 días calendario</strong> desde el ejercicio del retracto (Ley 2439/2024).</li>
</ul>

<div class="clause-title">Cláusula Décima Tercera — Datos Personales</div>
<p class="clause-body">El tratamiento de datos personales se rige por la Política de Privacidad de Lo Tengo Colombia y la Ley 1581/2012 (Habeas Data). El VENDEDOR autoriza expresamente el tratamiento de sus datos para: ejecución del contrato; cumplimiento de obligaciones tributarias y SAGRILAFT; comunicaciones comerciales; transferencias a aliados tecnológicos (Openpay, ZapSign, Heka, Aveonline, Stripe, AWS, SiteGround) bajo acuerdos de confidencialidad.</p>

<div class="clause-title">Cláusula Décima Cuarta — Ley Aplicable y Resolución de Controversias</div>
<table class="data-table">
  <tr><th>Etapa</th><th>Mecanismo</th><th>Plazo</th></tr>
  <tr><td>1</td><td>Negociación directa (comunicación escrita al correo registrado)</td><td>15 días hábiles</td></tr>
  <tr><td>2</td><td>Mediación/conciliación — Centro de Arbitraje CCC Cali</td><td>30 días siguientes</td></tr>
  <tr><td>3</td><td>Jueces civiles competentes de Cali, Valle del Cauca</td><td>Sin plazo límite</td></tr>
  <tr><td>Monto ≤ 40 SMMLV</td><td>SIC — Delegatura Protección al Consumidor</td><td>Según agenda SIC</td></tr>
</table>

<div class="clause-title">Cláusula Décima Quinta — Declaraciones Bajo Juramento (Art. 442 C.P.)</div>
<p class="clause-body">El VENDEDOR declara bajo la gravedad del juramento: que tiene plena capacidad legal para contratar; que la información suministrada en el proceso de KYC y en este contrato es verídica y completa; que los productos que comercializará son de lícita procedencia; que cumple todas sus obligaciones tributarias ante la DIAN; que no se encuentra en listas de riesgo OFAC, ONU, UIAF ni Fiscalía General de la Nación; que ha leído y acepta íntegramente este contrato, la Política de Privacidad, los Términos y Condiciones y el Manual del Vendedor.</p>

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

<div class="page-footer">Documento generado automáticamente por GRUPO LO TENGO S.A.S. · NIT 901.981.692-3 · Versión <?php echo esc_html( $version ); ?> · <?php echo esc_html( $date ); ?></div>

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
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'isHtml5ParserEnabled', true );
		$options->set( 'isFontSubsettingEnabled', true );
		$options->set( 'chroot', sys_get_temp_dir() );

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
