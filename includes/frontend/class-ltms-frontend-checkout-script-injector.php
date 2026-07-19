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
        // v2.9.219: output buffering to inject checkout JS after SG Optimizer.
        add_action( 'template_redirect', [ __CLASS__, 'start_output_buffer' ] );
    }

    /**
     * Inicia output buffering solo en páginas de checkout.
     */
    public static function start_output_buffer(): void {
        if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
            return;
        }
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
            return;
        }
        ob_start( [ __CLASS__, 'inject_script_into_html' ] );
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
     * Ejecutar fixes con múltiples retries (defense against WOOCCM)
     * =================================================================== */
    fixFieldLabels();
    setTimeout(fixFieldLabels, 500);
    setTimeout(fixFieldLabels, 1500);
    setTimeout(fixFieldLabels, 3000);

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
            }
        });
        observer.observe(scope, { childList: true, subtree: true, characterData: true });
        setTimeout(function() { observer.disconnect(); }, 8000);
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
