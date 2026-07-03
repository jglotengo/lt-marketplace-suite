<?php
/**
 * LTMS — Settings: Donaciones Fundación Cardio Infantil
 *
 * @package LTMS
 * @subpackage LTMS/includes/admin/views/settings
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$donation_enabled         = LTMS_Core_Config::get( 'ltms_donation_enabled', 'no' );
$donation_percentage      = (float) LTMS_Core_Config::get( 'ltms_donation_percentage', 0.0 );
$donation_min_amount      = (float) LTMS_Core_Config::get( 'ltms_donation_min_amount', 0.0 );
$donation_max_amount      = (float) LTMS_Core_Config::get( 'ltms_donation_max_amount', 0.0 );
$donation_basis           = LTMS_Core_Config::get( 'ltms_donation_basis', 'platform_fee' );
$donation_rounding        = LTMS_Core_Config::get( 'ltms_donation_rounding', 'none' );
$donation_foundation_name = LTMS_Core_Config::get( 'ltms_donation_foundation_name', 'Fundación Cardio Infantil' );
$donation_foundation_nit  = LTMS_Core_Config::get( 'ltms_donation_foundation_nit', '' );
$donation_foundation_contact = LTMS_Core_Config::get( 'ltms_donation_foundation_contact', '' );
$donation_foundation_email   = LTMS_Core_Config::get( 'ltms_donation_foundation_email', '' );
$donation_alegra_account_id  = (int) LTMS_Core_Config::get( 'ltms_donation_alegra_account_id', 0 );
$donation_payout_frequency   = LTMS_Core_Config::get( 'ltms_donation_payout_frequency', 'monthly' );
$donation_payout_day         = (int) LTMS_Core_Config::get( 'ltms_donation_payout_day', 15 );
$donation_vendor_transparency = LTMS_Core_Config::get( 'ltms_donation_vendor_transparency', 'yes' );
$donation_customer_opt_in    = LTMS_Core_Config::get( 'ltms_donation_customer_opt_in', 'no' );
$donation_tax_deductible     = LTMS_Core_Config::get( 'ltms_donation_tax_deductible', 'yes' );
$donation_certificate_enabled = LTMS_Core_Config::get( 'ltms_donation_certificate_enabled', 'yes' );
?>

<div class="ltms-settings-section">
    <h2><?php esc_html_e( 'Donaciones — Fundación Cardio Infantil', 'ltms' ); ?></h2>
    <p class="description"><?php esc_html_e( 'Configura la donación automática a la Fundación Cardio Infantil. El porcentaje se calcula sobre la base seleccionada y se deduce de la comisión del marketplace.', 'ltms' ); ?></p>

    <table class="form-table" role="presentation">
        <!-- Habilitar donaciones -->
        <tr>
            <th scope="row"><label for="ltms_donation_enabled"><?php esc_html_e( 'Habilitar donaciones', 'ltms' ); ?></label></th>
            <td>
                <select name="ltms_donation_enabled" id="ltms_donation_enabled">
                    <option value="no"  <?php selected( $donation_enabled, 'no' ); ?>><?php esc_html_e( 'No', 'ltms' ); ?></option>
                    <option value="yes" <?php selected( $donation_enabled, 'yes' ); ?>><?php esc_html_e( 'Sí', 'ltms' ); ?></option>
                </select>
                <p class="description"><?php esc_html_e( 'Habilita el motor de donaciones automático.', 'ltms' ); ?></p>
            </td>
        </tr>

        <!-- Porcentaje de donación -->
        <tr>
            <th scope="row"><label for="ltms_donation_percentage"><?php esc_html_e( 'Porcentaje de donación (%)', 'ltms' ); ?></label></th>
            <td>
                <input type="number" step="0.01" min="0" max="100" name="ltms_donation_percentage" id="ltms_donation_percentage" value="<?php echo esc_attr( $donation_percentage ); ?>" class="regular-text" />
                <p class="description"><?php esc_html_e( 'Porcentaje determinado por la administración. Ej: 5.00 = 5% de la base seleccionada.', 'ltms' ); ?></p>
            </td>
        </tr>

        <!-- Base de cálculo -->
        <tr>
            <th scope="row"><label for="ltms_donation_basis"><?php esc_html_e( 'Base de cálculo', 'ltms' ); ?></label></th>
            <td>
                <select name="ltms_donation_basis" id="ltms_donation_basis">
                    <option value="platform_fee"   <?php selected( $donation_basis, 'platform_fee' ); ?>><?php esc_html_e( 'Comisión del marketplace (platform_fee)', 'ltms' ); ?></option>
                    <option value="order_total"    <?php selected( $donation_basis, 'order_total' ); ?>><?php esc_html_e( 'Total de la orden', 'ltms' ); ?></option>
                    <option value="vendor_net"     <?php selected( $donation_basis, 'vendor_net' ); ?>><?php esc_html_e( 'Neto del vendedor', 'ltms' ); ?></option>
                    <option value="platform_profit"<?php selected( $donation_basis, 'platform_profit' ); ?>><?php esc_html_e( 'Ganancia neta del marketplace (platform_fee - costos)', 'ltms' ); ?></option>
                </select>
                <p class="description"><?php esc_html_e( 'Base sobre la cual se calcula el porcentaje de donación.', 'ltms' ); ?></p>
            </td>
        </tr>

        <!-- Monto mínimo/máximo -->
        <tr>
            <th scope="row"><label for="ltms_donation_min_amount"><?php esc_html_e( 'Donación mínima por orden', 'ltms' ); ?></label></th>
            <td>
                <input type="number" step="0.01" min="0" name="ltms_donation_min_amount" id="ltms_donation_min_amount" value="<?php echo esc_attr( $donation_min_amount ); ?>" class="regular-text" />
                <p class="description"><?php esc_html_e( 'Si la donación calculada es menor a este monto, se dona este valor. 0 = sin mínimo.', 'ltms' ); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="ltms_donation_max_amount"><?php esc_html_e( 'Donación máxima por orden', 'ltms' ); ?></label></th>
            <td>
                <input type="number" step="0.01" min="0" name="ltms_donation_max_amount" id="ltms_donation_max_amount" value="<?php echo esc_attr( $donation_max_amount ); ?>" class="regular-text" />
                <p class="description"><?php esc_html_e( 'Tope por orden. 0 = sin tope.', 'ltms' ); ?></p>
            </td>
        </tr>

        <!-- Redondeo -->
        <tr>
            <th scope="row"><label for="ltms_donation_rounding"><?php esc_html_e( 'Redondeo', 'ltms' ); ?></label></th>
            <td>
                <select name="ltms_donation_rounding" id="ltms_donation_rounding">
                    <option value="none"  <?php selected( $donation_rounding, 'none' ); ?>><?php esc_html_e( 'Sin redondeo (2 decimales)', 'ltms' ); ?></option>
                    <option value="up_50" <?php selected( $donation_rounding, 'up_50' ); ?>><?php esc_html_e( 'Redondear hacia arriba al múltiplo de 50', 'ltms' ); ?></option>
                    <option value="up_100" <?php selected( $donation_rounding, 'up_100' ); ?>><?php esc_html_e( 'Redondear hacia arriba al múltiplo de 100', 'ltms' ); ?></option>
                    <option value="up_500" <?php selected( $donation_rounding, 'up_500' ); ?>><?php esc_html_e( 'Redondear hacia arriba al múltiplo de 500', 'ltms' ); ?></option>
                </select>
                <p class="description"><?php esc_html_e( 'Redondeo del monto de donación para facilitar la transferencia.', 'ltms' ); ?></p>
            </td>
        </tr>

        <!-- Datos de la fundación -->
        <tr>
            <th scope="row"><label for="ltms_donation_foundation_name"><?php esc_html_e( 'Nombre de la fundación', 'ltms' ); ?></label></th>
            <td>
                <input type="text" name="ltms_donation_foundation_name" id="ltms_donation_foundation_name" value="<?php echo esc_attr( $donation_foundation_name ); ?>" class="regular-text" />
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="ltms_donation_foundation_nit"><?php esc_html_e( 'NIT/RFC de la fundación', 'ltms' ); ?></label></th>
            <td>
                <input type="text" name="ltms_donation_foundation_nit" id="ltms_donation_foundation_nit" value="<?php echo esc_attr( $donation_foundation_nit ); ?>" class="regular-text" />
                <p class="description"><?php esc_html_e( 'NIT (Colombia) o RFC (México) para certificados de donación deducibles.', 'ltms' ); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="ltms_donation_foundation_contact"><?php esc_html_e( 'Persona de contacto', 'ltms' ); ?></label></th>
            <td>
                <input type="text" name="ltms_donation_foundation_contact" id="ltms_donation_foundation_contact" value="<?php echo esc_attr( $donation_foundation_contact ); ?>" class="regular-text" />
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="ltms_donation_foundation_email"><?php esc_html_e( 'Email de la fundación', 'ltms' ); ?></label></th>
            <td>
                <input type="email" name="ltms_donation_foundation_email" id="ltms_donation_foundation_email" value="<?php echo esc_attr( $donation_foundation_email ); ?>" class="regular-text" />
                <p class="description"><?php esc_html_e( 'Email para envío de certificados de donación mensuales.', 'ltms' ); ?></p>
            </td>
        </tr>

        <!-- Integración contable -->
        <tr>
            <th scope="row"><label for="ltms_donation_alegra_account_id"><?php esc_html_e( 'Cuenta contable Alegra (donaciones)', 'ltms' ); ?></label></th>
            <td>
                <input type="number" min="0" name="ltms_donation_alegra_account_id" id="ltms_donation_alegra_account_id" value="<?php echo esc_attr( $donation_alegra_account_id ); ?>" class="regular-text" />
                <p class="description"><?php esc_html_e( 'ID de la cuenta contable en Alegra para registrar donaciones (ej: 520 - Donaciones). 0 = no sincroniza con Alegra.', 'ltms' ); ?></p>
            </td>
        </tr>

        <!-- Frecuencia de transferencia -->
        <tr>
            <th scope="row"><label for="ltms_donation_payout_frequency"><?php esc_html_e( 'Frecuencia de transferencia', 'ltms' ); ?></label></th>
            <td>
                <select name="ltms_donation_payout_frequency" id="ltms_donation_payout_frequency">
                    <option value="weekly"    <?php selected( $donation_payout_frequency, 'weekly' ); ?>><?php esc_html_e( 'Semanal', 'ltms' ); ?></option>
                    <option value="monthly"   <?php selected( $donation_payout_frequency, 'monthly' ); ?>><?php esc_html_e( 'Mensual (recomendado)', 'ltms' ); ?></option>
                    <option value="quarterly" <?php selected( $donation_payout_frequency, 'quarterly' ); ?>><?php esc_html_e( 'Trimestral', 'ltms' ); ?></option>
                    <option value="manual"    <?php selected( $donation_payout_frequency, 'manual' ); ?>><?php esc_html_e( 'Manual (solo admin)', 'ltms' ); ?></option>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="ltms_donation_payout_day"><?php esc_html_e( 'Día de transferencia', 'ltms' ); ?></label></th>
            <td>
                <input type="number" min="1" max="28" name="ltms_donation_payout_day" id="ltms_donation_payout_day" value="<?php echo esc_attr( $donation_payout_day ); ?>" class="small-text" />
                <p class="description"><?php esc_html_e( 'Día del mes para transferencia mensual (1-28). Para semanal = día de la semana (1=Lun, 7=Dom).', 'ltms' ); ?></p>
            </td>
        </tr>

        <!-- Transparencia -->
        <tr>
            <th scope="row"><label for="ltms_donation_vendor_transparency"><?php esc_html_e( 'Transparencia para vendedores', 'ltms' ); ?></label></th>
            <td>
                <select name="ltms_donation_vendor_transparency" id="ltms_donation_vendor_transparency">
                    <option value="no"  <?php selected( $donation_vendor_transparency, 'no' ); ?>><?php esc_html_e( 'No mostrar', 'ltms' ); ?></option>
                    <option value="yes" <?php selected( $donation_vendor_transparency, 'yes' ); ?>><?php esc_html_e( 'Mostrar en dashboard del vendedor', 'ltms' ); ?></option>
                </select>
                <p class="description"><?php esc_html_e( 'Los vendedores pueden ver cuánto se donó de sus ventas.', 'ltms' ); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="ltms_donation_customer_opt_in"><?php esc_html_e( 'Opt-in del cliente (checkout)', 'ltms' ); ?></label></th>
            <td>
                <select name="ltms_donation_customer_opt_in" id="ltms_donation_customer_opt_in">
                    <option value="no"  <?php selected( $donation_customer_opt_in, 'no' ); ?>><?php esc_html_e( 'No — automático (sin opt-in)', 'ltms' ); ?></option>
                    <option value="yes" <?php selected( $donation_customer_opt_in, 'yes' ); ?>><?php esc_html_e( 'Sí — cliente puede sumar donación extra', 'ltms' ); ?></option>
                </select>
                <p class="description"><?php esc_html_e( 'Si es "Sí", el cliente puede redondear su compra para donar extra.', 'ltms' ); ?></p>
            </td>
        </tr>

        <!-- Certificados -->
        <tr>
            <th scope="row"><label for="ltms_donation_tax_deductible"><?php esc_html_e( 'Donación deducible de impuestos', 'ltms' ); ?></label></th>
            <td>
                <select name="ltms_donation_tax_deductible" id="ltms_donation_tax_deductible">
                    <option value="no"  <?php selected( $donation_tax_deductible, 'no' ); ?>><?php esc_html_e( 'No', 'ltms' ); ?></option>
                    <option value="yes" <?php selected( $donation_tax_deductible, 'yes' ); ?>><?php esc_html_e( 'Sí — genera certificado deducible', 'ltms' ); ?></option>
                </select>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="ltms_donation_certificate_enabled"><?php esc_html_e( 'Generar certificados de donación', 'ltms' ); ?></label></th>
            <td>
                <select name="ltms_donation_certificate_enabled" id="ltms_donation_certificate_enabled">
                    <option value="no"  <?php selected( $donation_certificate_enabled, 'no' ); ?>><?php esc_html_e( 'No', 'ltms' ); ?></option>
                    <option value="yes" <?php selected( $donation_certificate_enabled, 'yes' ); ?>><?php esc_html_e( 'Sí — PDF mensual para fundación', 'ltms' ); ?></option>
                </select>
                <p class="description"><?php esc_html_e( 'Genera un certificado PDF mensual con el total donado para la fundación.', 'ltms' ); ?></p>
            </td>
        </tr>
    </table>

    <?php wp_nonce_field( 'ltms_save_donations_settings', 'ltms_donations_nonce' ); ?>
</div>
