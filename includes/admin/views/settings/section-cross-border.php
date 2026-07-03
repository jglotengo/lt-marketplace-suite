<?php
/**
 * LTMS — Settings: Cross-Border Commerce
 *
 * Configuration for international (cross-border) marketplace operations:
 *   - Multi-currency support (COP, MXN, USD, EUR, BRL, ARS, CLP, PEN, GBP, CAD)
 *   - FX rate providers (Frankfurter, exchangerate, ECB, manual)
 *   - FX spread percentage (0–5%)
 *   - Customs duty & fees per destination country
 *   - Incoterms (DDP / DDU)
 *   - De minimis thresholds per country
 *   - KYC + fraud screening for cross-border orders
 *   - International shipping carriers
 *   - Customs broker contact
 *
 * @package    LTMS
 * @subpackage LTMS/includes/admin/views/settings
 * @version    1.0.0
 * @since      3.1.0  Task 63-C — Cross-Border Settings + Migration + Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Pull current values (with safe defaults).
$cross_border_enabled        = LTMS_Core_Config::get( 'ltms_cross_border_enabled', 'no' );
$base_currency               = LTMS_Core_Config::get( 'ltms_base_currency', 'USD' );
$enabled_currencies_raw      = LTMS_Core_Config::get( 'ltms_enabled_currencies', [ 'COP', 'MXN', 'USD' ] );
$enabled_currencies          = is_array( $enabled_currencies_raw ) ? $enabled_currencies_raw : (array) json_decode( (string) $enabled_currencies_raw, true );
$fx_spread_percentage        = (float) LTMS_Core_Config::get( 'ltms_fx_spread_percentage', 1.5 );
$fx_provider                 = LTMS_Core_Config::get( 'ltms_fx_provider', 'frankfurter' );
$fx_cache_ttl_hours          = (int) LTMS_Core_Config::get( 'ltms_fx_cache_ttl_hours', 6 );
$fx_manual_overrides         = LTMS_Core_Config::get( 'ltms_fx_manual_overrides', '' );
if ( is_array( $fx_manual_overrides ) ) {
	// Convert array form to textual representation for the textarea.
	$lines = [];
	foreach ( $fx_manual_overrides as $pair => $rate ) {
		$lines[] = $pair . '=' . $rate;
	}
	$fx_manual_overrides = implode( "\n", $lines );
}
$default_incoterm            = LTMS_Core_Config::get( 'ltms_default_incoterm', 'DDU' );
$customs_duty_rates          = LTMS_Core_Config::get( 'ltms_customs_duty_rates', '' );
if ( is_array( $customs_duty_rates ) ) {
	$lines = [];
	foreach ( $customs_duty_rates as $cc => $rate ) {
		$lines[] = $cc . '=' . $rate;
	}
	$customs_duty_rates = implode( "\n", $lines );
}
$customs_fees                = LTMS_Core_Config::get( 'ltms_customs_fees', '' );
if ( is_array( $customs_fees ) ) {
	$lines = [];
	foreach ( $customs_fees as $cc => $fee ) {
		$lines[] = $cc . '=' . $fee;
	}
	$customs_fees = implode( "\n", $lines );
}
$supported_origins_raw       = LTMS_Core_Config::get( 'ltms_cross_border_origin_countries', [] );
$supported_origins           = is_array( $supported_origins_raw ) ? $supported_origins_raw : (array) json_decode( (string) $supported_origins_raw, true );
$supported_destinations_raw  = LTMS_Core_Config::get( 'ltms_cross_border_destination_countries', [] );
$supported_destinations      = is_array( $supported_destinations_raw ) ? $supported_destinations_raw : (array) json_decode( (string) $supported_destinations_raw, true );
$de_minimis_thresholds       = LTMS_Core_Config::get( 'ltms_de_minimis_thresholds', '' );
if ( is_array( $de_minimis_thresholds ) ) {
	$lines = [];
	foreach ( $de_minimis_thresholds as $cc => $amount ) {
		$lines[] = $cc . '=' . $amount;
	}
	$de_minimis_thresholds = implode( "\n", $lines );
}
$kyc_required                = LTMS_Core_Config::get( 'ltms_cross_border_kyc_required', 'yes' );
$fraud_screening             = LTMS_Core_Config::get( 'ltms_cross_border_fraud_screening', 'yes' );
$carriers_raw                = LTMS_Core_Config::get( 'ltms_international_shipping_carriers', [] );
$carriers                    = is_array( $carriers_raw ) ? $carriers_raw : (array) json_decode( (string) $carriers_raw, true );
$broker_contact              = LTMS_Core_Config::get( 'ltms_customs_broker_contact', '' );
$broker_email                = LTMS_Core_Config::get( 'ltms_customs_broker_email', '' );

// Build the list of available currencies from the FX provider (with safe fallback).
$available_currencies = [
	'COP' => __( 'COP — Peso Colombiano', 'ltms' ),
	'MXN' => __( 'MXN — Peso Mexicano', 'ltms' ),
	'USD' => __( 'USD — US Dollar', 'ltms' ),
	'EUR' => __( 'EUR — Euro', 'ltms' ),
	'BRL' => __( 'BRL — Real Brasileño', 'ltms' ),
	'ARS' => __( 'ARS — Peso Argentino', 'ltms' ),
	'CLP' => __( 'CLP — Peso Chileno', 'ltms' ),
	'PEN' => __( 'PEN — Sol Peruano', 'ltms' ),
	'GBP' => __( 'GBP — Libra Esterlina', 'ltms' ),
	'CAD' => __( 'CAD — Dólar Canadiense', 'ltms' ),
];

// ISO 3166-1 alpha-2 countries available for origin/destination multi-selects.
$available_countries = [
	'CO' => __( 'Colombia', 'ltms' ),
	'MX' => __( 'México', 'ltms' ),
	'US' => __( 'Estados Unidos', 'ltms' ),
	'BR' => __( 'Brasil', 'ltms' ),
	'AR' => __( 'Argentina', 'ltms' ),
	'CL' => __( 'Chile', 'ltms' ),
	'PE' => __( 'Perú', 'ltms' ),
	'EC' => __( 'Ecuador', 'ltms' ),
	'UY' => __( 'Uruguay', 'ltms' ),
	'PY' => __( 'Paraguay', 'ltms' ),
	'BO' => __( 'Bolivia', 'ltms' ),
	'VE' => __( 'Venezuela', 'ltms' ),
	'CA' => __( 'Canadá', 'ltms' ),
	'GB' => __( 'Reino Unido', 'ltms' ),
	'ES' => __( 'España', 'ltms' ),
	'DE' => __( 'Alemania', 'ltms' ),
	'FR' => __( 'Francia', 'ltms' ),
	'IT' => __( 'Italia', 'ltms' ),
	'PT' => __( 'Portugal', 'ltms' ),
];

// International shipping carriers available for the multi-select.
$available_carriers = [
	'dhl'         => __( 'DHL Express', 'ltms' ),
	'fedex'       => __( 'FedEx', 'ltms' ),
	'ups'         => __( 'UPS', 'ltms' ),
	'usps'        => __( 'USPS', 'ltms' ),
	'canadapost'  => __( 'Canada Post', 'ltms' ),
	'royalmail'   => __( 'Royal Mail', 'ltms' ),
	'correos'     => __( 'Correos (España/Colombia)', 'ltms' ),
	'sedex'       => __( 'Sedex (Brasil)', 'ltms' ),
	'andercol'    => __( 'Andercol (Latam)', 'ltms' ),
	'deprisa'     => __( 'Deprisa', 'ltms' ),
	'aveonline'   => __( 'Aveonline', 'ltms' ),
];
?>

<div class="ltms-settings-section">
	<h2>🌍 <?php esc_html_e( 'Cross-Border Commerce', 'ltms' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Configura la operativa internacional del marketplace: divisas, proveedores FX, aranceles aduaneros, incoterms y transportadoras internacionales.', 'ltms' ); ?></p>

	<table class="form-table" role="presentation">

		<!-- ── General ───────────────────────────────────────────────────── -->

		<tr>
			<th scope="row"><label for="ltms_cross_border_enabled"><?php esc_html_e( 'Habilitar comercio cross-border', 'ltms' ); ?></label></th>
			<td>
				<select name="ltms_cross_border_enabled" id="ltms_cross_border_enabled">
					<option value="no"  <?php selected( $cross_border_enabled, 'no' ); ?>><?php esc_html_e( 'No', 'ltms' ); ?></option>
					<option value="yes" <?php selected( $cross_border_enabled, 'yes' ); ?>><?php esc_html_e( 'Sí', 'ltms' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Activa el módulo de comercio internacional (multi-moneda, aranceles, aduana).', 'ltms' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="ltms_base_currency"><?php esc_html_e( 'Moneda base', 'ltms' ); ?></label></th>
			<td>
				<select name="ltms_base_currency" id="ltms_base_currency">
					<?php foreach ( [ 'USD', 'COP', 'MXN', 'EUR' ] as $curr ) : ?>
						<option value="<?php echo esc_attr( $curr ); ?>" <?php selected( $base_currency, $curr ); ?>>
							<?php echo esc_html( $curr . ' — ' . ( $available_currencies[ $curr ] ?? $curr ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Moneda en la que se liquidan comisiones y se calculan los reportes fiscales.', 'ltms' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="ltms_enabled_currencies"><?php esc_html_e( 'Monedas habilitadas', 'ltms' ); ?></label></th>
			<td>
				<select name="ltms_enabled_currencies[]" id="ltms_enabled_currencies" multiple size="6" style="min-width:260px;">
					<?php foreach ( $available_currencies as $code => $label ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php echo in_array( $code, $enabled_currencies, true ) ? 'selected' : ''; ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Monedas en las que los clientes pueden pagar. Mantén pulsado Ctrl/Cmd para seleccionar varias.', 'ltms' ); ?></p>
			</td>
		</tr>

		<!-- ── FX ─────────────────────────────────────────────────────────── -->

		<tr>
			<th scope="row"><label for="ltms_fx_spread_percentage"><?php esc_html_e( 'Spread FX (%)', 'ltms' ); ?></label></th>
			<td>
				<input type="number" step="0.01" min="0" max="5" name="ltms_fx_spread_percentage" id="ltms_fx_spread_percentage" value="<?php echo esc_attr( $fx_spread_percentage ); ?>" class="small-text" />
				<p class="description"><?php esc_html_e( 'Margen aplicado sobre la tasa FX mid-market (0–5%). El cliente paga más, el marketplace retiene la diferencia.', 'ltms' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="ltms_fx_provider"><?php esc_html_e( 'Proveedor FX', 'ltms' ); ?></label></th>
			<td>
				<select name="ltms_fx_provider" id="ltms_fx_provider">
					<option value="frankfurter"   <?php selected( $fx_provider, 'frankfurter' ); ?>><?php esc_html_e( 'Frankfurter (gratuito, ECB)', 'ltms' ); ?></option>
					<option value="exchangerate"  <?php selected( $fx_provider, 'exchangerate' ); ?>><?php esc_html_e( 'exchangerate.host', 'ltms' ); ?></option>
					<option value="ecb"           <?php selected( $fx_provider, 'ecb' ); ?>><?php esc_html_e( 'Banco Central Europeo', 'ltms' ); ?></option>
					<option value="manual"        <?php selected( $fx_provider, 'manual' ); ?>><?php esc_html_e( 'Manual (overrides obligatorios)', 'ltms' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Fuente de las tasas de cambio. "Manual" requiere definir todos los pares en el campo siguiente.', 'ltms' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="ltms_fx_cache_ttl_hours"><?php esc_html_e( 'TTL caché FX (horas)', 'ltms' ); ?></label></th>
			<td>
				<input type="number" min="1" max="168" name="ltms_fx_cache_ttl_hours" id="ltms_fx_cache_ttl_hours" value="<?php echo esc_attr( $fx_cache_ttl_hours ); ?>" class="small-text" />
				<p class="description"><?php esc_html_e( 'Cada cuántas horas se refrescan las tasas cacheadas. Recomendado: 6h.', 'ltms' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="ltms_fx_manual_overrides"><?php esc_html_e( 'Overrides manuales FX', 'ltms' ); ?></label></th>
			<td>
				<textarea name="ltms_fx_manual_overrides" id="ltms_fx_manual_overrides" rows="4" cols="60" placeholder="USD_COP=3800&#10;USD_MXN=17.5&#10;USD_BRL=5.10"><?php echo esc_textarea( $fx_manual_overrides ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Una línea por par. Formato: USD_COP=3800. Sobreescribe la tasa del proveedor para ese par.', 'ltms' ); ?></p>
			</td>
		</tr>

		<!-- ── Customs ────────────────────────────────────────────────────── -->

		<tr>
			<th scope="row"><label for="ltms_default_incoterm"><?php esc_html_e( 'Incoterm por defecto', 'ltms' ); ?></label></th>
			<td>
				<select name="ltms_default_incoterm" id="ltms_default_incoterm">
					<option value="DDU" <?php selected( $default_incoterm, 'DDU' ); ?>><?php esc_html_e( 'DDU — Delivered Duty Unpaid (comprador paga aranceles)', 'ltms' ); ?></option>
					<option value="DDP" <?php selected( $default_incoterm, 'DDP' ); ?>><?php esc_html_e( 'DDP — Delivered Duty Paid (marketplace paga aranceles)', 'ltms' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Define quién asume los costos aduaneros cuando no se especifica uno en la orden.', 'ltms' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="ltms_customs_duty_rates"><?php esc_html_e( 'Aranceles por país destino (%)', 'ltms' ); ?></label></th>
			<td>
				<textarea name="ltms_customs_duty_rates" id="ltms_customs_duty_rates" rows="4" cols="60" placeholder="US=3.4&#10;BR=11.0&#10;MX=5.0&#10;CO=15.0"><?php echo esc_textarea( $customs_duty_rates ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Tasa arancelaria promedio por país destino (código ISO 3166-1 alpha-2). Formato: US=3.4', 'ltms' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="ltms_customs_fees"><?php esc_html_e( 'Honorarios aduaneros por país', 'ltms' ); ?></label></th>
			<td>
				<textarea name="ltms_customs_fees" id="ltms_customs_fees" rows="4" cols="60" placeholder="US=flat:6.50,pct:0.346&#10;BR=flat:15.00,pct:0.50"><?php echo esc_textarea( $customs_fees ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Tarifa del broker aduanero: flat (monto fijo) + pct (% del valor CIF). Formato: US=flat:6.50,pct:0.346', 'ltms' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="ltms_de_minimis_thresholds"><?php esc_html_e( 'Umbrales de minimis (USD)', 'ltms' ); ?></label></th>
			<td>
				<textarea name="ltms_de_minimis_thresholds" id="ltms_de_minimis_thresholds" rows="4" cols="60" placeholder="US=800&#10;BR=50&#10;MX=50&#10;CO=200"><?php echo esc_textarea( $de_minimis_thresholds ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Valor CIF por debajo del cual el envío está exento de arancel. Formato: US=800.', 'ltms' ); ?></p>
			</td>
		</tr>

		<!-- ── Origin / Destination countries ─────────────────────────────── -->

		<tr>
			<th scope="row"><label for="ltms_cross_border_origin_countries"><?php esc_html_e( 'Países de origen soportados', 'ltms' ); ?></label></th>
			<td>
				<select name="ltms_cross_border_origin_countries[]" id="ltms_cross_border_origin_countries" multiple size="6" style="min-width:260px;">
					<?php foreach ( $available_countries as $cc => $label ) : ?>
						<option value="<?php echo esc_attr( $cc ); ?>" <?php echo in_array( $cc, $supported_origins, true ) ? 'selected' : ''; ?>>
							<?php echo esc_html( $cc . ' — ' . $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Países desde los que el marketplace permite despachar.', 'ltms' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="ltms_cross_border_destination_countries"><?php esc_html_e( 'Países destino soportados', 'ltms' ); ?></label></th>
			<td>
				<select name="ltms_cross_border_destination_countries[]" id="ltms_cross_border_destination_countries" multiple size="6" style="min-width:260px;">
					<?php foreach ( $available_countries as $cc => $label ) : ?>
						<option value="<?php echo esc_attr( $cc ); ?>" <?php echo in_array( $cc, $supported_destinations, true ) ? 'selected' : ''; ?>>
							<?php echo esc_html( $cc . ' — ' . $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Países hacia los que el marketplace permite enviar.', 'ltms' ); ?></p>
			</td>
		</tr>

		<!-- ── Compliance ─────────────────────────────────────────────────── -->

		<tr>
			<th scope="row"><label for="ltms_cross_border_kyc_required"><?php esc_html_e( 'KYC obligatorio (cross-border)', 'ltms' ); ?></label></th>
			<td>
				<select name="ltms_cross_border_kyc_required" id="ltms_cross_border_kyc_required">
					<option value="no"  <?php selected( $kyc_required, 'no' ); ?>><?php esc_html_e( 'No', 'ltms' ); ?></option>
					<option value="yes" <?php selected( $kyc_required, 'yes' ); ?>><?php esc_html_e( 'Sí', 'ltms' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Requerir verificación KYC adicional para compradores en órdenes cross-border.', 'ltms' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="ltms_cross_border_fraud_screening"><?php esc_html_e( 'Fraud screening cross-border', 'ltms' ); ?></label></th>
			<td>
				<select name="ltms_cross_border_fraud_screening" id="ltms_cross_border_fraud_screening">
					<option value="no"  <?php selected( $fraud_screening, 'no' ); ?>><?php esc_html_e( 'No', 'ltms' ); ?></option>
					<option value="yes" <?php selected( $fraud_screening, 'yes' ); ?>><?php esc_html_e( 'Sí', 'ltms' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Ejecuta screening anti-fraude reforzado en órdenes internacionales.', 'ltms' ); ?></p>
			</td>
		</tr>

		<!-- ── Shipping carriers ──────────────────────────────────────────── -->

		<tr>
			<th scope="row"><label for="ltms_international_shipping_carriers"><?php esc_html_e( 'Transportadoras internacionales', 'ltms' ); ?></label></th>
			<td>
				<select name="ltms_international_shipping_carriers[]" id="ltms_international_shipping_carriers" multiple size="6" style="min-width:260px;">
					<?php foreach ( $available_carriers as $code => $label ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php echo in_array( $code, $carriers, true ) ? 'selected' : ''; ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Carriers habilitados para envíos internacionales.', 'ltms' ); ?></p>
			</td>
		</tr>

		<!-- ── Customs broker ─────────────────────────────────────────────── -->

		<tr>
			<th scope="row"><label for="ltms_customs_broker_contact"><?php esc_html_e( 'Contacto broker aduanero', 'ltms' ); ?></label></th>
			<td>
				<input type="text" name="ltms_customs_broker_contact" id="ltms_customs_broker_contact" value="<?php echo esc_attr( $broker_contact ); ?>" class="regular-text" />
				<p class="description"><?php esc_html_e( 'Nombre y teléfono del broker aduanero de referencia.', 'ltms' ); ?></p>
			</td>
		</tr>

		<tr>
			<th scope="row"><label for="ltms_customs_broker_email"><?php esc_html_e( 'Email broker aduanero', 'ltms' ); ?></label></th>
			<td>
				<input type="email" name="ltms_customs_broker_email" id="ltms_customs_broker_email" value="<?php echo esc_attr( $broker_email ); ?>" class="regular-text" />
				<p class="description"><?php esc_html_e( 'Email donde se notifican los despachos aduaneros pendientes.', 'ltms' ); ?></p>
			</td>
		</tr>

	</table>

	<?php wp_nonce_field( 'ltms_save_cross_border_settings', 'ltms_cross_border_nonce' ); ?>
</div>
