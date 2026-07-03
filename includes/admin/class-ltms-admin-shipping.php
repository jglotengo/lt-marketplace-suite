<?php
/**
 * LTMS Admin Shipping — F-08
 * Panel admin para configurar modos de envío globales y por vendedor.
 *
 * @package LTMS\Admin
 * @version 2.3.0
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Admin_Shipping {

    public static function init(): void {
        add_action( 'ltms_admin_shipping_settings', [ self::class, 'render_settings' ] );
        add_action( 'wp_ajax_ltms_save_shipping_mode', [ self::class, 'ajax_save_mode' ] );
        add_action( 'wp_ajax_ltms_get_vendor_shipping', [ self::class, 'ajax_get_vendor' ] );
        // v2.8.4: nuevos handlers AJAX para shared shipping y override por categoría.
        add_action( 'wp_ajax_ltms_save_shared_pct', [ self::class, 'ajax_save_shared_pct' ] );
        add_action( 'wp_ajax_ltms_save_category_mode', [ self::class, 'ajax_save_category_mode' ] );
        add_action( 'admin_menu', [ self::class, 'add_submenu' ] );
    }

    public static function add_submenu(): void {
        add_submenu_page(
            'ltms-dashboard',
            __( 'Modos de Envío', 'ltms' ),
            __( 'Envíos', 'ltms' ),
            'manage_woocommerce',
            'ltms-shipping',
            [ self::class, 'render_page' ]
        );
    }

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( __( 'Acceso denegado', 'ltms' ) );
        }

        $global_mode = class_exists( 'LTMS_Shipping_Mode' )
            ? LTMS_Shipping_Mode::get_global_mode()
            : 'flat';
        $modes = class_exists( 'LTMS_Shipping_Mode' )
            ? LTMS_Shipping_Mode::valid_modes()
            : [ 'quoted', 'flat', 'free', 'free_absorbed', 'hybrid' ];
        $flat_rate = class_exists( 'LTMS_Core_Config' )
            ? (float) LTMS_Core_Config::get( 'ltms_shipping_flat_rate', 8500 )
            : 8500.0;
        $threshold = class_exists( 'LTMS_Core_Config' )
            ? (float) LTMS_Core_Config::get( 'ltms_shipping_hybrid_threshold', 100000 )
            : 100000.0;

        $mode_labels = [
            'quoted'        => 'Cotización paralela (Aveonline, Heka, Uber Direct)',
            'flat'          => 'Tarifa fija',
            'free'          => 'Envío gratis',
            'free_absorbed' => 'Precio todo incluido (vendedor absorbe el flete)',
            'hybrid'        => 'Gratis desde un monto mínimo (recomendado)',
            'shared'        => 'Compartido (cliente paga %, vendor absorbe resto)',
        ];

        $mode_icons = [
            'quoted'        => '&#x1F4E6;',
            'flat'          => '&#x1F4B0;',
            'free'          => '&#x1F381;',
            'free_absorbed' => '&#x1F91D;',
            'hybrid'        => '&#x1F500;',
            'shared'        => '&#x1F91A;',
        ];
        ?>
        <div class="wrap ltms-admin-wrap">

            <div class="ltms-header">
                <h1>&#x1F69A; <?php esc_html_e( 'Configuración de Modos de Envío', 'ltms' ); ?></h1>
            </div>

            <!-- Stats: modo actual -->
            <div class="ltms-stats-grid" style="margin-bottom:24px;">
                <div class="ltms-stat-card">
                    <span class="ltms-stat-label"><?php esc_html_e( 'Modo global activo', 'ltms' ); ?></span>
                    <span class="ltms-stat-value" style="font-size:14px;font-weight:700;color:#2563eb;">
                        <?php echo esc_html( $mode_icons[ $global_mode ] ?? '&#x1F4E6;' ); ?>
                        <?php echo esc_html( $mode_labels[ $global_mode ] ?? $global_mode ); ?>
                    </span>
                </div>
                <div class="ltms-stat-card">
                    <span class="ltms-stat-label"><?php esc_html_e( 'Tarifa fija (COP)', 'ltms' ); ?></span>
                    <span class="ltms-stat-value" style="color:#16a34a;">$<?php echo esc_html( number_format( $flat_rate, 0, ',', '.' ) ); ?></span>
                </div>
                <div class="ltms-stat-card">
                    <span class="ltms-stat-label"><?php esc_html_e( 'Umbral envío gratis (COP)', 'ltms' ); ?></span>
                    <span class="ltms-stat-value" style="color:#f59e0b;">$<?php echo esc_html( number_format( $threshold, 0, ',', '.' ) ); ?></span>
                </div>
            </div>

            <div style="max-width:800px;">

                <!-- MODO GLOBAL -->
                <div class="ltms-table-wrap" style="margin-bottom:20px;padding:0;">
                    <div style="padding:16px 20px;border-bottom:1px solid #e5e7eb;">
                        <h2 style="margin:0;font-size:15px;font-weight:700;">&#x1F30E; <?php esc_html_e( 'Modo Global de Envío', 'ltms' ); ?></h2>
                        <p style="margin:4px 0 0;font-size:12px;color:#6b7280;"><?php esc_html_e( 'Este modo aplica a todos los vendedores que no tengan un modo personalizado.', 'ltms' ); ?></p>
                    </div>
                    <div style="padding:20px;">
                        <div style="margin-bottom:16px;">
                            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;"><?php esc_html_e( 'Modo', 'ltms' ); ?></label>
                            <select id="ltms-global-mode" style="min-width:380px;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                                <?php foreach ( $modes as $m ) : ?>
                                <option value="<?php echo esc_attr( $m ); ?>" <?php selected( $global_mode, $m ); ?>>
                                    <?php echo esc_html( ( $mode_icons[ $m ] ?? '' ) . ' ' . ( $mode_labels[ $m ] ?? $m ) ); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="row-flat-rate" style="margin-bottom:16px;<?php echo $global_mode !== 'flat' && $global_mode !== 'hybrid' ? 'display:none' : ''; ?>">
                            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;"><?php esc_html_e( 'Tarifa fija (COP)', 'ltms' ); ?></label>
                            <input type="number" id="ltms-flat-rate" value="<?php echo esc_attr( $flat_rate ); ?>" min="0" step="100"
                                   style="width:160px;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;" />
                            <span style="font-size:12px;color:#6b7280;margin-left:8px;"><?php esc_html_e( 'Costo de envío en modo tarifa fija o fallback híbrido.', 'ltms' ); ?></span>
                        </div>
                        <div id="row-threshold" style="margin-bottom:16px;<?php echo $global_mode !== 'hybrid' ? 'display:none' : ''; ?>">
                            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;"><?php esc_html_e( 'Monto mínimo para envío gratis (COP)', 'ltms' ); ?></label>
                            <input type="number" id="ltms-threshold" value="<?php echo esc_attr( $threshold ); ?>" min="0" step="1000"
                                   style="width:160px;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;" />
                            <span style="font-size:12px;color:#6b7280;margin-left:8px;"><?php esc_html_e( 'Pedidos ≥ este monto tienen envío gratis.', 'ltms' ); ?></span>
                        </div>
                        <button id="ltms-save-global" class="ltms-btn ltms-btn-primary">
                            &#x1F4BE; <?php esc_html_e( 'Guardar configuración global', 'ltms' ); ?>
                        </button>
                        <span id="ltms-save-msg" style="margin-left:12px;color:#16a34a;font-size:13px;display:none;">&#x2705; <?php esc_html_e( 'Guardado', 'ltms' ); ?></span>
                    </div>
                </div>

                <!-- CALCULADORA DE FLETE -->
                <div class="ltms-table-wrap" style="margin-bottom:20px;padding:0;">
                    <div style="padding:16px 20px;border-bottom:1px solid #e5e7eb;">
                        <h2 style="margin:0;font-size:15px;font-weight:700;">&#x1F9EE; <?php esc_html_e( 'Calculadora de Flete para Vendedor', 'ltms' ); ?></h2>
                        <p style="margin:4px 0 0;font-size:12px;color:#6b7280;"><?php esc_html_e( 'Estima el costo de envío antes de publicar un producto.', 'ltms' ); ?></p>
                    </div>
                    <div style="padding:20px;">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                            <div>
                                <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;"><?php esc_html_e( 'Peso (kg)', 'ltms' ); ?></label>
                                <input type="number" id="calc-weight" value="0.5" min="0.1" step="0.1"
                                       style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;" />
                            </div>
                            <div>
                                <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;"><?php esc_html_e( 'Vendedor (opcional)', 'ltms' ); ?></label>
                                <input type="number" id="calc-vendor" value="0" min="0"
                                       style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;"
                                       placeholder="<?php esc_attr_e( 'ID vendedor', 'ltms' ); ?>" />
                            </div>
                        </div>
                        <div style="margin-bottom:16px;">
                            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;"><?php esc_html_e( 'Dimensiones (cm)', 'ltms' ); ?></label>
                            <div style="display:flex;gap:10px;align-items:center;">
                                <span style="font-size:12px;color:#6b7280;">L:</span>
                                <input type="number" id="calc-length" value="20" min="1" style="width:80px;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;" />
                                <span style="font-size:12px;color:#6b7280;">A:</span>
                                <input type="number" id="calc-width"  value="15" min="1" style="width:80px;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;" />
                                <span style="font-size:12px;color:#6b7280;">H:</span>
                                <input type="number" id="calc-height" value="10" min="1" style="width:80px;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;" />
                            </div>
                        </div>
                        <button id="ltms-calc-estimate" class="ltms-btn ltms-btn-primary">
                            &#x1F4CA; <?php esc_html_e( 'Calcular estimado', 'ltms' ); ?>
                        </button>

                        <div id="ltms-estimate-result" style="display:none;margin-top:16px;">
                            <div class="ltms-table-wrap" style="padding:0;">
                                <table class="ltms-table">
                                    <thead><tr>
                                        <th><?php esc_html_e( 'Ciudad destino', 'ltms' ); ?></th>
                                        <th><?php esc_html_e( 'Costo estimado', 'ltms' ); ?></th>
                                        <th><?php esc_html_e( 'Proveedor', 'ltms' ); ?></th>
                                    </tr></thead>
                                    <tbody id="ltms-estimate-rows"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- MODO POR VENDEDOR -->
                <div class="ltms-table-wrap" style="padding:0;">
                    <div style="padding:16px 20px;border-bottom:1px solid #e5e7eb;">
                        <h2 style="margin:0;font-size:15px;font-weight:700;">&#x1F464; <?php esc_html_e( 'Modo por Vendedor', 'ltms' ); ?></h2>
                        <p style="margin:4px 0 0;font-size:12px;color:#6b7280;"><?php esc_html_e( 'Configura un modo de envío diferente para un vendedor específico.', 'ltms' ); ?></p>
                    </div>
                    <div style="padding:20px;">
                        <div style="display:flex;gap:10px;align-items:flex-end;margin-bottom:16px;">
                            <div>
                                <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;"><?php esc_html_e( 'ID Vendedor', 'ltms' ); ?></label>
                                <input type="number" id="vendor-id-input" min="1"
                                       style="width:120px;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;"
                                       placeholder="<?php esc_attr_e( 'ej: 42', 'ltms' ); ?>" />
                            </div>
                            <button id="ltms-load-vendor" class="ltms-btn ltms-btn-outline">
                                &#x1F50D; <?php esc_html_e( 'Cargar', 'ltms' ); ?>
                            </button>
                        </div>

                        <div id="vendor-mode-row" style="display:none;margin-bottom:16px;">
                            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;"><?php esc_html_e( 'Modo de envío', 'ltms' ); ?></label>
                            <select id="vendor-mode-select" style="min-width:380px;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;">
                                <option value=""><?php esc_html_e( '— Usar modo global —', 'ltms' ); ?></option>
                                <?php foreach ( $modes as $m ) : ?>
                                <option value="<?php echo esc_attr( $m ); ?>">
                                    <?php echo esc_html( ( $mode_icons[ $m ] ?? '' ) . ' ' . ( $mode_labels[ $m ] ?? $m ) ); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="vendor-flat-row" style="display:none;margin-bottom:16px;">
                            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;"><?php esc_html_e( 'Tarifa fija vendedor (COP)', 'ltms' ); ?></label>
                            <input type="number" id="vendor-flat-rate" value="0" min="0" step="100"
                                   style="width:160px;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;" />
                        </div>
                        <div id="vendor-threshold-row" style="display:none;margin-bottom:16px;">
                            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;"><?php esc_html_e( 'Umbral vendedor (COP)', 'ltms' ); ?></label>
                            <input type="number" id="vendor-threshold" value="0" min="0" step="1000"
                                   style="width:160px;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;" />
                        </div>
                        <div id="vendor-save-row" style="display:none;">
                            <button id="ltms-save-vendor" class="ltms-btn ltms-btn-primary">
                                &#x1F4BE; <?php esc_html_e( 'Guardar modo del vendedor', 'ltms' ); ?>
                            </button>
                            <span id="ltms-vendor-msg" style="margin-left:12px;color:#16a34a;font-size:13px;display:none;">&#x2705; <?php esc_html_e( 'Guardado', 'ltms' ); ?></span>
                        </div>
                    </div>
                </div>

                <!-- v2.8.4: MODO SHARED — % que paga el cliente -->
                <div class="ltms-table-wrap" style="margin-bottom:20px;padding:0;">
                    <div style="padding:16px 20px;border-bottom:1px solid #e5e7eb;">
                        <h2 style="margin:0;font-size:15px;font-weight:700;">&#x1F91A; <?php esc_html_e( 'Modo Compartido (Shared Shipping)', 'ltms' ); ?></h2>
                        <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">
                            <?php esc_html_e( 'Configura el % del flete que paga el cliente cuando una categoría está en modo "shared". El resto lo absorbe el vendedor.', 'ltms' ); ?>
                        </p>
                    </div>
                    <div style="padding:20px;">
                        <div style="margin-bottom:16px;">
                            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;"><?php esc_html_e( '% que paga el cliente', 'ltms' ); ?></label>
                            <input type="number" id="ltms-shared-pct" value="<?php echo esc_attr( class_exists('LTMS_Shipping_Mode') ? LTMS_Shipping_Mode::get_shared_customer_pct() : 60 ); ?>"
                                   min="0" max="100" step="5"
                                   style="width:120px;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;" />
                            <span style="font-size:12px;color:#6b7280;margin-left:8px;">
                                <?php esc_html_e( 'Ej: 60% = cliente paga 60% del flete, vendor absorbe 40%.', 'ltms' ); ?>
                            </span>
                        </div>
                        <button id="ltms-save-shared" class="ltms-btn ltms-btn-primary">
                            &#x1F4BE; <?php esc_html_e( 'Guardar % compartido', 'ltms' ); ?>
                        </button>
                        <span id="ltms-shared-msg" style="margin-left:12px;color:#16a34a;font-size:13px;display:none;">&#x2705; <?php esc_html_e( 'Guardado', 'ltms' ); ?></span>
                    </div>
                </div>

                <!-- v2.8.4: OVERRIDE POR CATEGORÍA -->
                <div class="ltms-table-wrap" style="margin-bottom:20px;padding:0;">
                    <div style="padding:16px 20px;border-bottom:1px solid #e5e7eb;">
                        <h2 style="margin:0;font-size:15px;font-weight:700;">&#x1F3A7; <?php esc_html_e( 'Override por Categoría', 'ltms' ); ?></h2>
                        <p style="margin:4px 0 0;font-size:12px;color:#6b7280;">
                            <?php esc_html_e( 'Asigna un modo de envío específico a categorías de productos. Prioridad: categoría > vendedor > global.', 'ltms' ); ?>
                        </p>
                    </div>
                    <div style="padding:20px;">
                        <?php
                        // Obtener todas las categorías de producto.
                        $all_cats = get_terms( [
                            'taxonomy'   => 'product_cat',
                            'hide_empty' => false,
                            'number'     => 0,
                        ] );
                        $overrides = class_exists( 'LTMS_Shipping_Mode' ) ? LTMS_Shipping_Mode::get_all_category_overrides() : [];
                        ?>
                        <table class="ltms-table" style="width:100%;">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Categoría', 'ltms' ); ?></th>
                                    <th><?php esc_html_e( 'Modo Actual', 'ltms' ); ?></th>
                                    <th><?php esc_html_e( 'Acción', 'ltms' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ( is_wp_error( $all_cats ) || empty( $all_cats ) ) : ?>
                                    <tr><td colspan="3"><?php esc_html_e( 'No hay categorías de producto configuradas.', 'ltms' ); ?></td></tr>
                                <?php else : foreach ( $all_cats as $cat ) :
                                    $current_mode = $overrides[ $cat->term_id ]['mode'] ?? '';
                                    ?>
                                    <tr>
                                        <td><strong><?php echo esc_html( $cat->name ); ?></strong></td>
                                        <td>
                                            <select id="cat-mode-<?php echo esc_attr( $cat->term_id ); ?>" style="min-width:280px;">
                                                <option value=""><?php esc_html_e( '— Usar modo global —', 'ltms' ); ?></option>
                                                <?php foreach ( $mode_labels as $key => $label ) : ?>
                                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current_mode, $key ); ?>>
                                                        <?php echo esc_html( $label ); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <button class="ltms-btn ltms-btn-outline ltms-save-cat-mode" data-cat-id="<?php echo esc_attr( $cat->term_id ); ?>">
                                                &#x1F4BE; <?php esc_html_e( 'Guardar', 'ltms' ); ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div><!-- max-width wrapper -->
        </div><!-- .ltms-admin-wrap -->

        <script type="text/javascript">
        /* global jQuery, ajaxurl */
        jQuery( document ).ready( function( $ ) {
            var nonce = '<?php echo wp_create_nonce( 'ltms_shipping_nonce' ); ?>';
            var api   = '<?php echo esc_url( rest_url( 'ltms/v1/shipping' ) ); ?>';

            function toggleModeFields( mode, prefix ) {
                $( '#' + prefix + '-flat-rate' ).closest( 'div[id]' ).toggle( mode === 'flat' || mode === 'hybrid' );
                $( '#' + prefix + '-threshold' ).closest( 'div[id]' ).toggle( mode === 'hybrid' );
            }

            $( '#ltms-global-mode' ).on( 'change', function() {
                toggleModeFields( $( this ).val(), 'ltms' );
            } );
            toggleModeFields( $( '#ltms-global-mode' ).val(), 'ltms' );

            // Guardar configuración global
            $( '#ltms-save-global' ).on( 'click', function() {
                var $btn = $( this ).prop( 'disabled', true );
                $.post( ajaxurl, {
                    action:    'ltms_save_shipping_mode',
                    nonce:     nonce,
                    mode:      $( '#ltms-global-mode' ).val(),
                    flat_rate: $( '#ltms-flat-rate' ).val(),
                    threshold: $( '#ltms-threshold' ).val(),
                    vendor_id: 0,
                }, function( r ) {
                    if ( r.success ) { $( '#ltms-save-msg' ).show().delay( 3000 ).fadeOut(); }
                } ).always( function() { $btn.prop( 'disabled', false ); } );
            } );

            // Calculadora de flete
            $( '#ltms-calc-estimate' ).on( 'click', function() {
                var $btn = $( this ).prop( 'disabled', true ).text( '<?php echo esc_js( __( "Calculando...", "ltms" ) ); ?>' );
                $.ajax( {
                    url:         api + '/estimate',
                    method:      'POST',
                    beforeSend:  function( xhr ) { xhr.setRequestHeader( 'X-WP-Nonce', nonce ); },
                    contentType: 'application/json',
                    data: JSON.stringify( {
                        weight:    parseFloat( $( '#calc-weight' ).val() ),
                        length:    parseFloat( $( '#calc-length' ).val() ),
                        width:     parseFloat( $( '#calc-width' ).val() ),
                        height:    parseFloat( $( '#calc-height' ).val() ),
                        vendor_id: parseInt( $( '#calc-vendor' ).val() ) || 0,
                    } ),
                    success: function( r ) {
                        var rows = r.estimates.map( function( e ) {
                            var cost = e.cost === 0
                                ? '<strong style="color:#16a34a">GRATIS</strong>'
                                : '$ ' + parseInt( e.cost ).toLocaleString( 'es-CO' ) + ' COP';
                            return '<tr><td>' + e.city + '</td><td>' + cost + '</td><td>' + e.provider + '</td></tr>';
                        } ).join( '' );
                        $( '#ltms-estimate-rows' ).html( rows );
                        $( '#ltms-estimate-result' ).show();
                    },
                    complete: function() {
                        $btn.prop( 'disabled', false ).text( '<?php echo esc_js( __( "📊 Calcular estimado", "ltms" ) ); ?>' );
                    }
                } );
            } );

            // Cargar modo de vendedor
            $( '#ltms-load-vendor' ).on( 'click', function() {
                var vid = parseInt( $( '#vendor-id-input' ).val() );
                if ( ! vid ) return;
                $.ajax( {
                    url:        api + '/mode?vendor_id=' + vid,
                    method:     'GET',
                    beforeSend: function( xhr ) { xhr.setRequestHeader( 'X-WP-Nonce', nonce ); },
                    success:    function( r ) {
                        $( '#vendor-mode-select' ).val( r.vendor_mode || '' );
                        $( '#vendor-mode-row, #vendor-save-row' ).show();
                        toggleModeFields( r.vendor_mode || '', 'vendor' );
                    }
                } );
            } );

            $( '#vendor-mode-select' ).on( 'change', function() {
                toggleModeFields( $( this ).val(), 'vendor' );
            } );

            // Guardar modo de vendedor
            $( '#ltms-save-vendor' ).on( 'click', function() {
                var $btn = $( this ).prop( 'disabled', true );
                $.post( ajaxurl, {
                    action:    'ltms_save_shipping_mode',
                    nonce:     nonce,
                    mode:      $( '#vendor-mode-select' ).val(),
                    flat_rate: $( '#vendor-flat-rate' ).val(),
                    threshold: $( '#vendor-threshold' ).val(),
                    vendor_id: parseInt( $( '#vendor-id-input' ).val() ),
                }, function( r ) {
                    if ( r.success ) { $( '#ltms-vendor-msg' ).show().delay( 3000 ).fadeOut(); }
                } ).always( function() { $btn.prop( 'disabled', false ); } );
            } );

            // v2.8.4: Guardar % compartido
            $( '#ltms-save-shared' ).on( 'click', function() {
                var $btn = $( this ).prop( 'disabled', true );
                $.post( ajaxurl, {
                    action:    'ltms_save_shared_pct',
                    nonce:     nonce,
                    pct:       parseFloat( $( '#ltms-shared-pct' ).val() ),
                }, function( r ) {
                    if ( r.success ) { $( '#ltms-shared-msg' ).show().delay( 3000 ).fadeOut(); }
                } ).always( function() { $btn.prop( 'disabled', false ); } );
            } );

            // v2.8.4: Guardar modo por categoría
            $( '.ltms-save-cat-mode' ).on( 'click', function() {
                var $btn = $( this ).prop( 'disabled', true );
                var catId = $( this ).data( 'cat-id' );
                var mode = $( '#cat-mode-' + catId ).val();
                $.post( ajaxurl, {
                    action:   'ltms_save_category_mode',
                    nonce:    nonce,
                    cat_id:   catId,
                    mode:     mode,
                }, function( r ) {
                    if ( r.success ) {
                        $btn.text( '<?php echo esc_js( __( "Guardado", "ltms" ) ); ?>' ).delay( 2000 )
                            .queue( function() { $btn.text( '<?php echo esc_js( __( "Guardar", "ltms" ) ); ?>' ).dequeue(); } );
                    }
                } ).fail( function() {
                    alert( '<?php echo esc_js( __( "Error al guardar. Intenta de nuevo.", "ltms" ) ); ?>' );
                } ).always( function() { $btn.prop( 'disabled', false ); } );
            } );
        } );
        </script>
        <?php
    }

    /**
     * v2.8.4: AJAX handler para guardar el % compartido del modo SHARED.
     */
    public static function ajax_save_shared_pct(): void {
        check_ajax_referer( 'ltms_shipping_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Acceso denegado' );
        }
        $pct = (float) ( $_POST['pct'] ?? 60 ); // phpcs:ignore
        if ( $pct < 0 || $pct > 100 ) {
            wp_send_json_error( '% debe estar entre 0 y 100' );
        }
        if ( ! class_exists( 'LTMS_Shipping_Mode' ) ) {
            wp_send_json_error( 'Clase LTMS_Shipping_Mode no disponible' );
        }
        $ok = LTMS_Shipping_Mode::set_shared_customer_pct( $pct );
        wp_send_json_success( [ 'saved' => $ok, 'pct' => $pct ] );
    }

    /**
     * v2.8.4: AJAX handler para guardar el modo de envío de una categoría.
     */
    public static function ajax_save_category_mode(): void {
        check_ajax_referer( 'ltms_shipping_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Acceso denegado' );
        }
        $cat_id = (int) ( $_POST['cat_id'] ?? 0 ); // phpcs:ignore
        $mode   = sanitize_text_field( $_POST['mode'] ?? '' ); // phpcs:ignore
        if ( ! $cat_id ) {
            wp_send_json_error( 'Falta cat_id' );
        }
        if ( ! class_exists( 'LTMS_Shipping_Mode' ) ) {
            wp_send_json_error( 'Clase LTMS_Shipping_Mode no disponible' );
        }
        $ok = LTMS_Shipping_Mode::set_category_mode( $cat_id, $mode );
        wp_send_json_success( [ 'saved' => $ok, 'cat_id' => $cat_id, 'mode' => $mode ] );
    }

    public static function ajax_save_mode(): void {
        check_ajax_referer( 'ltms_shipping_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Acceso denegado' );
        }

        $mode      = sanitize_text_field( $_POST['mode'] ?? '' ); // phpcs:ignore
        $vendor_id = (int) ( $_POST['vendor_id'] ?? 0 ); // phpcs:ignore
        $flat_rate = (float) ( $_POST['flat_rate'] ?? 0 ); // phpcs:ignore
        $threshold = (float) ( $_POST['threshold'] ?? 0 ); // phpcs:ignore

        if ( ! class_exists( 'LTMS_Shipping_Mode' ) || ! in_array( $mode, LTMS_Shipping_Mode::valid_modes(), true ) ) {
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
                if ( $mode )      LTMS_Core_Config::set( 'ltms_shipping_mode',             $mode );
                if ( $flat_rate ) LTMS_Core_Config::set( 'ltms_shipping_flat_rate',        $flat_rate );
                if ( $threshold ) LTMS_Core_Config::set( 'ltms_shipping_hybrid_threshold', $threshold );
            }
        }

        wp_send_json_success( [ 'saved' => true ] );
    }

    public static function ajax_get_vendor(): void {
        check_ajax_referer( 'ltms_shipping_nonce', 'nonce' );
        $vendor_id = (int) ( $_GET['vendor_id'] ?? 0 ); // phpcs:ignore
        $mode      = class_exists( 'LTMS_Shipping_Mode' )
            ? LTMS_Shipping_Mode::get_vendor_mode( $vendor_id )
            : 'flat';
        wp_send_json_success( [ 'mode' => $mode ] );
    }
}
