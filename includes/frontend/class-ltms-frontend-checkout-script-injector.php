<?php
/**
 * LTMS Frontend Checkout Inline Script Injector
 *
 * v2.9.219: SiteGround Optimizer strips inline <script> tags from template
 * files like checkout.php. To work around this, we use output buffering to
 * inject the script directly into the final HTML after all SG Optimizer
 * filters have run.
 *
 * Same pattern used by LTMS_Cart_Drawer::start_output_buffer() (v2.9.59).
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @version    2.9.219
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Frontend_Checkout_Script_Injector {

    public static function init(): void {
        // v2.9.225: Switched from output buffering to wp_footer.
        // SG Optimizer was stripping inline <script> tags injected via
        // ob_start callback. wp_footer with PHP_INT_MAX priority runs
        // AFTER SG Optimizer's HTML processing, so the script survives.
        add_action( 'wp_footer', [ __CLASS__, 'print_checkout_script' ], PHP_INT_MAX );
    }

    /**
     * v2.9.225: Prints the checkout script directly via wp_footer.
     * This runs after all SG Optimizer HTML processing.
     */
    public static function print_checkout_script(): void {
        if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
            return;
        }
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
            return;
        }
        echo self::get_checkout_script_html();
    }

    /**
     * Callback del output buffer — inyecta el script inline antes de </body>.
     */
    public static function inject_script_into_html( string $html ): string {
        if ( stripos( $html, '<html' ) === false ) {
            return $html;
        }

        $script = self::get_checkout_script_html();

        // Intentar inyectar antes de </body> (preferred).
        $pos = strripos( $html, '</body>' );
        if ( $pos !== false ) {
            return substr( $html, 0, $pos ) . $script . substr( $html, $pos );
        }

        // Fallback: antes de </html>.
        $pos = strripos( $html, '</html>' );
        if ( $pos !== false ) {
            return substr( $html, 0, $pos ) . $script . substr( $html, $pos );
        }

        return $html;
    }

    /**
     * Devuelve el HTML del script inline como string.
     */
    public static function get_checkout_script_html(): string {
        $country = class_exists( 'LTMS_Core_Config' ) ? LTMS_Core_Config::get_country() : 'CO';

        return '<!-- LTMS-CHECKOUT-SCRIPT-v2.9.219-START -->' . "\n" .
            '<script>' . "\n" .
            self::get_checkout_script_js( $country ) . "\n" .
            '</script>' . "\n" .
            '<!-- LTMS-CHECKOUT-SCRIPT-v2.9.219-END -->' . "\n";
    }

    /**
     * Devuelve el código JS del script inline.
     *
     * @param string $country Código de país (CO, MX).
     */
    private static function get_checkout_script_js( string $country ): string {
        $country_js = esc_js( $country );

        return <<<JS
(function(){
    'use strict';
    var scope = document.querySelector('.pv-scope.pv-checkout');
    if (!scope) return;

    /* ===================================================================
     * v2.9.224: Inyectar CSS para botón Confirmar pedido + otros estilos.
     * SG Optimizer strippa <style> tags del template. Lo inyectamos via JS.
     * =================================================================== */
    if (!document.getElementById('ltms-checkout-fixes-css')) {
        var style = document.createElement('style');
        style.id = 'ltms-checkout-fixes-css';
        style.textContent = [
            '.pv-scope.pv-checkout .pv-btn--brand{background:#E80001 !important;color:#fff !important;border:1px solid #E80001 !important;font-weight:800 !important;letter-spacing:.01em;}',
            '.pv-scope.pv-checkout .pv-btn--brand:hover{background:#B80001 !important;border-color:#B80001 !important;transform:translateY(-1px);box-shadow:0 6px 16px rgba(232,0,1,0.28) !important;}',
            '.pv-scope.pv-checkout .pv-btn--brand:active{transform:translateY(0);box-shadow:0 2px 6px rgba(232,0,1,0.20) !important;}',
            '.pv-scope.pv-checkout .pv-checkout__submit{height:60px !important;font-size:17px !important;}',
            '.pv-scope.pv-checkout .woocommerce-shipping-fields{display:none !important;}',
            '.pv-scope.pv-checkout .woocommerce-shipping-fields.shipping-fields--visible{display:block !important;}',
            '.pv-scope.pv-checkout #billing_address_1_field label .optional,' +
            '.pv-scope.pv-checkout #billing_state_field label .optional,' +
            '.pv-scope.pv-checkout #shipping_address_1_field label .optional,' +
            '.pv-scope.pv-checkout #shipping_state_field label .optional{display:none !important;}'
        ].join('\\n');
        document.head.appendChild(style);
    }

    /* ===================================================================
     * v2.9.217: Fix field labels (bypass WOOCCM via JS DOM manipulation)
     * =================================================================== */
    var ltmsCountry = '{$country_js}';
    var labelMap = {};
    if (ltmsCountry === 'CO') {
        labelMap = {
            'billing_state': 'Departamento',
            'shipping_state': 'Departamento',
            'billing_city': 'Municipio',
            'shipping_city': 'Municipio',
            'billing_country': 'País',
            'shipping_country': 'País',
            'billing_postcode': 'Código postal',
            'shipping_postcode': 'Código postal',
            'billing_address_1': 'Dirección',
            'shipping_address_1': 'Dirección',
            'billing_address_2': 'Apartamento, suite, etc. (opcional)',
            'shipping_address_2': 'Apartamento, suite, etc. (opcional)'
        };
    } else if (ltmsCountry === 'MX') {
        labelMap = {
            'billing_state': 'Estado',
            'shipping_state': 'Estado',
            'billing_city': 'Municipio / Alcaldía',
            'shipping_city': 'Municipio / Alcaldía',
            'billing_country': 'País',
            'shipping_country': 'País',
            'billing_postcode': 'Código postal',
            'shipping_postcode': 'Código postal',
            'billing_address_1': 'Dirección',
            'shipping_address_1': 'Dirección',
            'billing_address_2': 'Apartamento, suite, etc. (opcional)',
            'shipping_address_2': 'Apartamento, suite, etc. (opcional)'
        };
    }

    function fixFieldLabels() {
        Object.keys(labelMap).forEach(function(fieldKey) {
            var newLabel = labelMap[fieldKey];
            var labelEl = scope.querySelector('label[for="' + fieldKey + '"]');
            if (!labelEl) return;
            if (labelEl.getAttribute('data-ltms-label-fixed') === '1') return;
            var abbr = labelEl.querySelector('abbr.required, abbr');
            var optionalSpan = labelEl.querySelector('span.optional, .optional');
            // v2.9.220: Limpiar cualquier '(opcional)' duplicado del texto del label.
            // El nuevo label ya puede contener '(opcional)' si lo definimos así,
            // y WOOCCM añade OTRO span.optional — esto causaba '(opcional)(opcional)'.
            labelEl.innerHTML = '';
            labelEl.appendChild(document.createTextNode(newLabel));
            if (abbr) {
                labelEl.appendChild(document.createTextNode(' '));
                labelEl.appendChild(abbr);
            }
            // Solo añadir el span.optional si el nuevo label NO contiene '(opcional)'.
            if (optionalSpan && newLabel.toLowerCase().indexOf('(opcional)') === -1 && newLabel.toLowerCase().indexOf('opcional') === -1) {
                labelEl.appendChild(document.createTextNode(' '));
                labelEl.appendChild(optionalSpan);
            }
            labelEl.setAttribute('data-ltms-label-fixed', '1');
        });

        // Ocultar campos duplicados.
        // v2.9.220: Heurística mejorada — solo ocultar si hay DOS campos
        // billing_phone o DOS campos billing_email visibles. Antes ocultábamos
        // basándonos solo en el texto del label, lo que causaba falsos positivos
        // (ej. ocultábamos el campo correcto si WOOCCM cambiaba el label).
        var phoneFields = scope.querySelectorAll('#billing_phone_field, #shipping_phone_field');
        if (phoneFields.length > 1) {
            // Hay 2+ campos phone — mantener solo el primero, ocultar el resto.
            for (var p = 1; p < phoneFields.length; p++) {
                phoneFields[p].style.display = 'none';
            }
        }
        var emailFields = scope.querySelectorAll('#billing_email_field, #shipping_email_field');
        if (emailFields.length > 1) {
            // Hay 2+ campos email — mantener solo el primero, ocultar el resto.
            for (var e = 1; e < emailFields.length; e++) {
                emailFields[e].style.display = 'none';
            }
        }

        // Auto-seleccionar país.
        var countrySelect = scope.querySelector('#billing_country, #shipping_country');
        if (countrySelect && countrySelect.value !== ltmsCountry) {
            countrySelect.value = ltmsCountry;
            countrySelect.dispatchEvent(new Event('change', { bubbles: true }));
        }

        // v2.9.223: Estilizar el checkbox nativo '¿Enviar a una dirección
        // diferente?' de WC para que coincida con el design system.
        var shipDiffWrap = scope.querySelector('#ship-to-different-address');
        if (shipDiffWrap && !shipDiffWrap.getAttribute('data-ltms-styled')) {
            shipDiffWrap.setAttribute('data-ltms-styled', '1');
            shipDiffWrap.style.cssText = 'display:flex;align-items:flex-start;gap:10px;padding:14px 16px;margin:16px 0 12px;background:#FFF9F9;border:1px solid #FFD6D6;border-left:4px solid #E80001;border-radius:10px;cursor:pointer;transition:all .15s';
            shipDiffWrap.addEventListener('mouseenter', function() {
                this.style.background = '#FFF1F1';
                this.style.borderColor = '#E80001';
            });
            shipDiffWrap.addEventListener('mouseleave', function() {
                this.style.background = '#FFF9F9';
                this.style.borderColor = '#FFD6D6';
            });
            var shipDiffLabel = shipDiffWrap.querySelector('label');
            if (shipDiffLabel) {
                shipDiffLabel.style.cssText = 'font-size:14px;font-weight:600;color:#1A1F2E;line-height:1.5;cursor:pointer;display:flex;align-items:flex-start;gap:8px;width:100%';
                // Añadir descripción bajo el label.
                var desc = document.createElement('span');
                desc.style.cssText = 'display:block;font-size:12px;font-weight:400;color:#565C66;margin-top:4px;line-height:1.45';
                desc.textContent = 'Marca si quieres que el pedido se entregue en una dirección diferente a la de facturación.';
                shipDiffLabel.appendChild(desc);
            }
            var shipDiffCheckbox = shipDiffWrap.querySelector('#ship-to-different-address-checkbox');
            if (shipDiffCheckbox) {
                shipDiffCheckbox.style.cssText = 'width:20px;height:20px;margin-top:2px;accent-color:#E80001;cursor:pointer;flex-shrink:0';
            }
        }

        // v2.9.223: Cambiar el heading "Detalles de facturación" a algo más claro.
        var billingHeading = scope.querySelector('.woocommerce-billing-fields h3, .woocommerce-billing-fields legend');
        if (billingHeading && !billingHeading.getAttribute('data-ltms-renamed')) {
            billingHeading.setAttribute('data-ltms-renamed', '1');
            billingHeading.textContent = 'Datos de facturación';
            billingHeading.style.cssText = 'font-size:15px;font-weight:700;color:#1A1F2E;margin:0 0 12px;padding-bottom:8px;border-bottom:1px solid #E7E5EC';
        }
        // Cambiar el heading "Enviar a una dirección diferente" también.
        var shippingHeading = scope.querySelector('.woocommerce-shipping-fields h3, .woocommerce-shipping-fields legend');
        if (shippingHeading && !shippingHeading.getAttribute('data-ltms-renamed')) {
            shippingHeading.setAttribute('data-ltms-renamed', '1');
            shippingHeading.textContent = 'Dirección de entrega (envío)';
            shippingHeading.style.cssText = 'font-size:15px;font-weight:700;color:#1A1F2E;margin:0 0 12px;padding-bottom:8px;border-bottom:1px solid #E7E5EC';
        }
    }

    /* ===================================================================
     * v2.9.218: Sync billing_state from billing_city (DANE municipio)
     * =================================================================== */
    function syncStateFromCity() {
        var citySelect = scope.querySelector('#billing_city, #ltms-municipality-select');
        var stateSelect = scope.querySelector('#billing_state');
        if (!citySelect || !stateSelect) return;
        if (!citySelect.value || citySelect.value.length < 2) return;

        var selectedOption = citySelect.options[citySelect.selectedIndex];
        if (!selectedOption) return;
        var optionText = selectedOption.textContent || '';
        var deptName = '';
        var dashIdx = optionText.indexOf('—');
        if (dashIdx !== -1) {
            deptName = optionText.substring(dashIdx + 1).trim();
        } else if (optionText.indexOf('-') !== -1) {
            deptName = optionText.substring(optionText.indexOf('-') + 1).trim();
        }

        if (!deptName) return;

        var bestMatch = null;
        var bestScore = 0;
        for (var i = 0; i < stateSelect.options.length; i++) {
            var opt = stateSelect.options[i];
            var optText = (opt.textContent || '').trim();
            var normOpt = optText.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            var normDept = deptName.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            if (normOpt === normDept) {
                bestMatch = opt;
                bestScore = 100;
                break;
            }
            if (normOpt.indexOf(normDept) !== -1 || normDept.indexOf(normOpt) !== -1) {
                var score = Math.min(normOpt.length, normDept.length);
                if (score > bestScore) {
                    bestScore = score;
                    bestMatch = opt;
                }
            }
        }

        if (bestMatch && bestScore > 0) {
            stateSelect.value = bestMatch.value;
            stateSelect.dispatchEvent(new Event('change', { bubbles: true }));
            if (typeof jQuery !== 'undefined') {
                jQuery(document.body).trigger('update_checkout');
            }
        }
    }

    /* ===================================================================
     * v2.9.224: Toggle de campos de envío según checkbox.
     * WC debería hacerlo nativamente, pero WOOCCM lo rompe. Lo forzamos via JS.
     * =================================================================== */
    function syncShipFieldsVisibility() {
        var shipCheckbox = scope.querySelector('#ship-to-different-address-checkbox');
        var shipFieldsWrap = scope.querySelector('.woocommerce-shipping-fields');
        if (!shipCheckbox || !shipFieldsWrap) return;
        if (shipCheckbox.checked) {
            shipFieldsWrap.style.display = 'block';
            shipFieldsWrap.classList.add('shipping-fields--visible');
        } else {
            shipFieldsWrap.style.display = 'none';
            shipFieldsWrap.classList.remove('shipping-fields--visible');
        }
    }

    /* ===================================================================
     * v2.9.224: Marcar campos como required (WOOCCM los pone optional).
     * =================================================================== */
    function markRequiredFields() {
        var requiredFields = ['billing_first_name', 'billing_last_name', 'billing_country',
                              'billing_address_1', 'billing_state',
                              'shipping_first_name', 'shipping_last_name', 'shipping_country',
                              'shipping_address_1', 'shipping_state'];
        requiredFields.forEach(function(fid) {
            var el = scope.querySelector('#' + fid);
            if (el && !el.required) el.setAttribute('required', 'required');
        });
    }

    /* ===================================================================
     * Ejecutar fixes con múltiples retries (defense against WOOCCM)
     * =================================================================== */
    fixFieldLabels();
    markRequiredFields();
    syncShipFieldsVisibility();
    setTimeout(function() { fixFieldLabels(); markRequiredFields(); syncShipFieldsVisibility(); }, 500);
    setTimeout(function() { fixFieldLabels(); markRequiredFields(); syncShipFieldsVisibility(); }, 1500);
    setTimeout(function() { fixFieldLabels(); markRequiredFields(); syncShipFieldsVisibility(); }, 3000);

    if ('MutationObserver' in window) {
        var observer = new MutationObserver(function(mutations) {
            var shouldFix = false;
            mutations.forEach(function(m) {
                if (m.type === 'childList' || m.type === 'characterData') {
                    shouldFix = true;
                }
            });
            if (shouldFix) {
                fixFieldLabels();
                markRequiredFields();
                syncShipFieldsVisibility();
            }
        });
        observer.observe(scope, { childList: true, subtree: true, characterData: true });
        setTimeout(function() { observer.disconnect(); }, 8000);
    }

    // v2.9.224: Enganchar al change del checkbox 'enviar a dirección diferente'.
    var shipCheckboxForToggle = scope.querySelector('#ship-to-different-address-checkbox');
    if (shipCheckboxForToggle) {
        shipCheckboxForToggle.addEventListener('change', syncShipFieldsVisibility);
    }

    // Sync billing_state from billing_city.
    var citySelectForSync = scope.querySelector('#billing_city, #ltms-municipality-select');
    if (citySelectForSync) {
        citySelectForSync.addEventListener('change', function() {
            setTimeout(syncStateFromCity, 100);
        });
        setTimeout(syncStateFromCity, 800);
    }
})();
JS;
    }
}
