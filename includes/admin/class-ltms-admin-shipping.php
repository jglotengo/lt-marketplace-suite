<?php
/**
 * LTMS Admin Shipping — F-08
 * Panel admin para configurar modos de envío globales y por vendedor.
 *
 * @package LTMS\Admin
 * @version 2.2.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Admin_Shipping {

    public static function init(): void {
        add_action( 'ltms_admin_shipping_settings', [ self::class, 'render_settings' ] );
        add_action( 'wp_ajax_ltms_save_shipping_mode', [ self::class, 'ajax_save_mode' ] );
        add_action( 'wp_ajax_ltms_get_vendor_shipping', [ self::class, 'ajax_get_vendor' ] );
        add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
    }

    public static function add_submenu(): void {
        add_submenu_page(
            'ltms-dashboard',
            __('Modos de Envío', 'ltms'),
            __('Envíos', 'ltms'),
            'manage_woocommerce',
            'ltms-shipping',
            [ self::class, 'render_page' ]
        );
    }

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( __('Acceso denegado', 'ltms') );
        }

        $global_mode = class_exists('LTMS_Shipping_Mode')
            ? LTMS_Shipping_Mode::get_global_mode()
            : 'flat';
        $modes       = class_exists('LTMS_Shipping_Mode')
            ? LTMS_Shipping_Mode::valid_modes()
            : ['quoted','flat','free','free_absorbed','hybrid'];
        $flat_rate   = class_exists('LTMS_Core_Config')
            ? (float) LTMS_Core_Config::get('ltms_shipping_flat_rate', 8500)
            : 8500.0;
        $threshold   = class_exists('LTMS_Core_Config')
            ? (float) LTMS_Core_Config::get('ltms_shipping_hybrid_threshold', 100000)
            : 100000.0;

        $mode_labels = [
            'quoted'       => 'Cotización paralela (Aveonline, Heka, Uber Direct)',
            'flat'         => 'Tarifa fija',
            'free'         => 'Envío gratis',
            'free_absorbed' => 'Precio todo incluido (vendedor absorbe el flete)',
            'hybrid'       => 'Gratis desde un monto mínimo',
        ];

        ?>
        <div class="wrap">
            <h1><?php _e('Configuración de Modos de Envío', 'ltms'); ?></h1>

            <div class="ltms-shipping-config" style="max-width:800px;">

                <!-- MODO GLOBAL -->
                <div class="postbox" style="padding:20px;margin-top:20px;">
                    <h2><?php _e('Modo Global de Envío', 'ltms'); ?></h2>
                    <p class="description"><?php _e('Este modo aplica a todos los vendedores que no tengan un modo personalizado.', 'ltms'); ?></p>

                    <table class="form-table">
                        <tr>
                            <th><?php _e('Modo', 'ltms'); ?></th>
                            <td>
                                <select id="ltms-global-mode" style="min-width:350px;">
                                    <?php foreach ( $modes as $m ) : ?>
                                        <option value="<?php echo esc_attr($m); ?>" <?php selected($global_mode, $m); ?>>
                                            <?php echo esc_html( $mode_labels[$m] ?? $m ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr id="row-flat-rate" style="<?php echo $global_mode !== 'flat' && $global_mode !== 'hybrid' ? 'display:none' : ''; ?>">
                            <th><?php _e('Tarifa fija (COP)', 'ltms'); ?></th>
                            <td>
                                <input type="number" id="ltms-flat-rate" value="<?php echo esc_attr($flat_rate); ?>" min="0" step="100" style="width:150px;" />
                                <p class="description"><?php _e('Costo de envío cuando el modo es Tarifa fija o como fallback en Híbrido.', 'ltms'); ?></p>
                            </td>
                        </tr>
                        <tr id="row-threshold" style="<?php echo $global_mode !== 'hybrid' ? 'display:none' : ''; ?>">
                            <th><?php _e('Monto mínimo para envío gratis (COP)', 'ltms'); ?></th>
                            <td>
                                <input type="number" id="ltms-threshold" value="<?php echo esc_attr($threshold); ?>" min="0" step="1000" style="width:150px;" />
                                <p class="description"><?php _e('Pedidos iguales o mayores a este monto tienen envío gratis.', 'ltms'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <p>
                        <button id="ltms-save-global" class="button button-primary"><?php _e('Guardar configuración global', 'ltms'); ?></button>
                        <span id="ltms-save-msg" style="margin-left:10px;color:green;display:none;">✅ Guardado</span>
                    </p>
                </div>

                <!-- CALCULADORA DE FLETE -->
                <div class="postbox" style="padding:20px;margin-top:20px;">
                    <h2><?php _e('Calculadora de Flete para Vendedor', 'ltms'); ?></h2>
                    <p class="description"><?php _e('Estima el costo de envío antes de publicar un producto.', 'ltms'); ?></p>

                    <table class="form-table">
                        <tr>
                            <th><?php _e('Peso (kg)', 'ltms'); ?></th>
                            <td><input type="number" id="calc-weight" value="0.5" min="0.1" step="0.1" style="width:100px;" /></td>
                        </tr>
                        <tr>
                            <th><?php _e('Dimensiones (cm)', 'ltms'); ?></th>
                            <td>
                                L: <input type="number" id="calc-length" value="20" min="1" style="width:70px;" />
                                A: <input type="number" id="calc-width"  value="15" min="1" style="width:70px;" />
                                H: <input type="number" id="calc-height" value="10" min="1" style="width:70px;" />
                            </td>
                        </tr>
                        <tr>
                            <th><?php _e('Vendedor (opcional)', 'ltms'); ?></th>
                            <td><input type="number" id="calc-vendor" value="0" min="0" style="width:100px;" placeholder="ID vendedor" /></td>
                        </tr>
                    </table>

                    <p>
                        <button id="ltms-calc-estimate" class="button"><?php _e('Calcular estimado', 'ltms'); ?></button>
                    </p>

                    <div id="ltms-estimate-result" style="display:none;margin-top:10px;">
                        <table class="widefat striped" style="max-width:500px;">
                            <thead>
                                <tr>
                                    <th><?php _e('Ciudad destino', 'ltms'); ?></th>
                                    <th><?php _e('Costo estimado', 'ltms'); ?></th>
                                    <th><?php _e('Proveedor', 'ltms'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="ltms-estimate-rows"></tbody>
                        </table>
                    </div>
                </div>

                <!-- MODO POR VENDEDOR -->
                <div class="postbox" style="padding:20px;margin-top:20px;">
                    <h2><?php _e('Modo por Vendedor', 'ltms'); ?></h2>
                    <p class="description"><?php _e('Configura un modo de envío diferente para un vendedor específico.', 'ltms'); ?></p>

                    <table class="form-table">
                        <tr>
                            <th><?php _e('ID Vendedor', 'ltms'); ?></th>
                            <td>
                                <input type="number" id="vendor-id-input" min="1" style="width:120px;" placeholder="ej: 42" />
                                <button id="ltms-load-vendor" class="button"><?php _e('Cargar', 'ltms'); ?></button>
                            </td>
                        </tr>
                        <tr id="vendor-mode-row" style="display:none;">
                            <th><?php _e('Modo de envío', 'ltms'); ?></th>
                            <td>
                                <select id="vendor-mode-select" style="min-width:350px;">
                                    <option value=""><?php _e('— Usar modo global —', 'ltms'); ?></option>
                                    <?php foreach ( $modes as $m ) : ?>
                                        <option value="<?php echo esc_attr($m); ?>">
                                            <?php echo esc_html( $mode_labels[$m] ?? $m ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr id="vendor-flat-row" style="display:none;">
                            <th><?php _e('Tarifa fija vendedor (COP)', 'ltms'); ?></th>
                            <td><input type="number" id="vendor-flat-rate" value="0" min="0" step="100" style="width:150px;" /></td>
                        </tr>
                        <tr id="vendor-threshold-row" style="display:none;">
                            <th><?php _e('Umbral vendedor (COP)', 'ltms'); ?></th>
                            <td><input type="number" id="vendor-threshold" value="0" min="0" step="1000" style="width:150px;" /></td>
                        </tr>
                    </table>

                    <p id="vendor-save-row" style="display:none;">
                        <button id="ltms-save-vendor" class="button button-primary"><?php _e('Guardar modo del vendedor', 'ltms'); ?></button>
                        <span id="ltms-vendor-msg" style="margin-left:10px;color:green;display:none;">✅ Guardado</span>
                    </p>
                </div>

            </div><!-- .ltms-shipping-config -->
        </div>

        <script>
        jQuery(document).ready(function($) {
            const nonce = '<?php echo wp_create_nonce("ltms_shipping_nonce"); ?>';
            const api   = '<?php echo esc_url( rest_url("ltms/v1/shipping") ); ?>';

            // Mostrar/ocultar campos según modo
            function toggleModeFields(mode, prefix) {
                $('#' + prefix + '-flat-rate').closest('tr').toggle(mode === 'flat' || mode === 'hybrid');
                $('#' + prefix + '-threshold').closest('tr').toggle(mode === 'hybrid');
            }

            $('#ltms-global-mode').on('change', function() {
                toggleModeFields($(this).val(), 'ltms');
            });
            toggleModeFields($('#ltms-global-mode').val(), 'ltms');

            // Guardar configuración global
            $('#ltms-save-global').on('click', function() {
                const data = {
                    action:    'ltms_save_shipping_mode',
                    nonce:     nonce,
                    mode:      $('#ltms-global-mode').val(),
                    flat_rate: $('#ltms-flat-rate').val(),
                    threshold: $('#ltms-threshold').val(),
                    vendor_id: 0,
                };
                $.post(ajaxurl, data, function(r) {
                    if (r.success) {
                        $('#ltms-save-msg').show().delay(3000).fadeOut();
                    }
                });
            });

            // Calculadora de flete
            $('#ltms-calc-estimate').on('click', function() {
                const btn = $(this).prop('disabled', true).text('Calculando...');
                $.ajax({
                    url:    api + '/estimate',
                    method: 'POST',
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', nonce);
                    },
                    contentType: 'application/json',
                    data: JSON.stringify({
                        weight:    parseFloat($('#calc-weight').val()),
                        length:    parseFloat($('#calc-length').val()),
                        width:     parseFloat($('#calc-width').val()),
                        height:    parseFloat($('#calc-height').val()),
                        vendor_id: parseInt($('#calc-vendor').val()) || 0,
                    }),
                    success: function(r) {
                        const rows = r.estimates.map(function(e) {
                            const cost = e.cost === 0
                                ? '<strong style="color:green">GRATIS</strong>'
                                : '$ ' + parseInt(e.cost).toLocaleString('es-CO') + ' COP';
                            return '<tr><td>' + e.city + '</td><td>' + cost + '</td><td>' + e.provider + '</td></tr>';
                        }).join('');
                        $('#ltms-estimate-rows').html(rows);
                        $('#ltms-estimate-result').show();
                    },
                    complete: function() {
                        btn.prop('disabled', false).text('Calcular estimado');
                    }
                });
            });

            // Cargar modo de vendedor
            $('#ltms-load-vendor').on('click', function() {
                const vid = parseInt($('#vendor-id-input').val());
                if (!vid) return;
                $.ajax({
                    url:    api + '/mode?vendor_id=' + vid,
                    method: 'GET',
                    beforeSend: function(xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', nonce);
                    },
                    success: function(r) {
                        $('#vendor-mode-select').val(r.vendor_mode || '');
                        $('#vendor-mode-row, #vendor-save-row').show();
                        toggleModeFields(r.vendor_mode || '', 'vendor');
                    }
                });
            });

            $('#vendor-mode-select').on('change', function() {
                toggleModeFields($(this).val(), 'vendor');
            });

            // Guardar modo de vendedor
            $('#ltms-save-vendor').on('click', function() {
                const data = {
                    action:    'ltms_save_shipping_mode',
                    nonce:     nonce,
                    mode:      $('#vendor-mode-select').val(),
                    flat_rate: $('#vendor-flat-rate').val(),
                    threshold: $('#vendor-threshold').val(),
                    vendor_id: parseInt($('#vendor-id-input').val()),
                };
                $.post(ajaxurl, data, function(r) {
                    if (r.success) {
                        $('#ltms-vendor-msg').show().delay(3000).fadeOut();
                    }
                });
            });
        });
        </script>
        <?php
    }

    public static function ajax_save_mode(): void {
        check_ajax_referer( 'ltms_shipping_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Acceso denegado' );
        }

        $mode      = sanitize_text_field( $_POST['mode'] ?? '' );
        $vendor_id = (int) ( $_POST['vendor_id'] ?? 0 );
        $flat_rate = (float) ( $_POST['flat_rate'] ?? 0 );
        $threshold = (float) ( $_POST['threshold'] ?? 0 );

        if ( ! class_exists('LTMS_Shipping_Mode') || ! in_array( $mode, LTMS_Shipping_Mode::valid_modes(), true ) ) {
            // Permitir vacío para reset de vendedor
            if ( $mode !== '' ) {
                wp_send_json_error( 'Modo inválido' );
            }
        }

        if ( $vendor_id > 0 ) {
            if ( $mode === '' ) {
                delete_user_meta( $vendor_id, '_ltms_shipping_mode' );
            } else {
                update_user_meta( $vendor_id, '_ltms_shipping_mode', $mode );
            }
            if ( $flat_rate > 0 ) update_user_meta( $vendor_id, '_ltms_flat_shipping_rate', $flat_rate );
            if ( $threshold > 0 ) update_user_meta( $vendor_id, '_ltms_hybrid_threshold',   $threshold );
        } else {
            if ( class_exists( 'LTMS_Core_Config' ) ) {
                if ( $mode )        LTMS_Core_Config::set( 'ltms_shipping_mode',             $mode );
                if ( $flat_rate )   LTMS_Core_Config::set( 'ltms_shipping_flat_rate',        $flat_rate );
                if ( $threshold )   LTMS_Core_Config::set( 'ltms_shipping_hybrid_threshold', $threshold );
            }
        }

        wp_send_json_success( [ 'saved' => true ] );
    }

    public static function ajax_get_vendor(): void {
        check_ajax_referer( 'ltms_shipping_nonce', 'nonce' );
        $vendor_id = (int) ( $_GET['vendor_id'] ?? 0 );
        $mode      = class_exists('LTMS_Shipping_Mode')
            ? LTMS_Shipping_Mode::get_vendor_mode( $vendor_id )
            : 'flat';
        wp_send_json_success( [ 'mode' => $mode ] );
    }
}
