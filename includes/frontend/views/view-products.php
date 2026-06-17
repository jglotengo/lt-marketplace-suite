<?php
/**
 * Vista SPA: Productos del Vendedor
 *
 * @package LTMS
 * @version 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$vendor_id = get_current_user_id();
$products  = wc_get_products([
    'author'   => $vendor_id,
    'limit'    => 50,
    'orderby'  => 'date',
    'order'    => 'DESC',
    'status'   => [ 'publish', 'draft', 'pending' ],
]);
?>
<div class="ltms-view-pad">

    <div class="ltms-view-header">
        <h2><?php esc_html_e( 'Mis Productos', 'ltms' ); ?></h2>
        <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-add-product-btn">
            ➕ <?php esc_html_e( 'Nuevo Producto', 'ltms' ); ?>
        </button>
    </div>

    <?php if ( empty( $products ) ) : ?>
    <div class="ltms-empty-state">
        <div class="ltms-empty-icon">🛍️</div>
        <h3><?php esc_html_e( 'Aún no tienes productos', 'ltms' ); ?></h3>
        <p><?php esc_html_e( 'Agrega tu primer producto para comenzar a vender.', 'ltms' ); ?></p>
        <button type="button" class="ltms-btn ltms-btn-primary" id="ltms-add-product-btn-empty">
            <?php esc_html_e( 'Agregar Producto', 'ltms' ); ?>
        </button>
    </div>
    <?php else : ?>

    <!-- Grid de productos -->
    <div class="ltms-products-grid">
        <?php foreach ( $products as $product ) : ?>
        <div class="ltms-product-card">
            <div class="ltms-product-img">
                <?php if ( $product->get_image_id() ) : ?>
                <img src="<?php echo esc_url( wp_get_attachment_image_url( $product->get_image_id(), 'medium' ) ); ?>"
                     alt="<?php echo esc_attr( $product->get_name() ); ?>" loading="lazy">
                <?php else : ?>
                <span style="font-size:2rem;color:#d1d5db;">📷</span>
                <?php endif; ?>
            </div>
            <div class="ltms-product-body">
                <div class="ltms-product-name"><?php echo esc_html( $product->get_name() ); ?></div>
                <div class="ltms-product-price">
                    <?php echo esc_html( LTMS_Utils::format_money( (float) $product->get_price() ) ); ?>
                </div>
                <div style="margin-top:6px;">
                    <span class="ltms-badge <?php echo $product->get_status() === 'publish' ? 'ltms-badge-success' : 'ltms-badge-warning'; ?>" style="font-size:0.7rem;">
                        <?php echo esc_html( $product->get_status() === 'publish' ? __( 'Publicado', 'ltms' ) : __( 'Borrador', 'ltms' ) ); ?>
                    </span>
                    <?php
                    $ltms_tipo = get_post_meta( $product->get_id(), '_ltms_product_type', true ) ?: 'physical';
                    // CS-04: mapeo legacy 'product' → 'physical'
                    if ( $ltms_tipo === 'product' ) { $ltms_tipo = 'physical'; }
                    $ltms_tipo_map = [
                        'physical' => [ 'label' => __( 'Físico',   'ltms' ), 'icon' => '📦' ],
                        'digital'  => [ 'label' => __( 'Digital',  'ltms' ), 'icon' => '💾' ],
                        'service'  => [ 'label' => __( 'Servicio', 'ltms' ), 'icon' => '🔧' ],
                        'booking'  => [ 'label' => __( 'Turismo',  'ltms' ), 'icon' => '🏨' ],
                    ];
                    $ltms_tipo_label = $ltms_tipo_map[ $ltms_tipo ]['label'] ?? __( 'Físico', 'ltms' );
                    $ltms_tipo_icon  = $ltms_tipo_map[ $ltms_tipo ]['icon']  ?? '📦';
                    ?>
                    <span class="ltms-badge ltms-badge-info" style="font-size:0.7rem;margin-left:4px;background:#e0f2fe;color:#0369a1;">
                        <?php echo $ltms_tipo_icon . ' ' . esc_html( $ltms_tipo_label ); ?>
                    </span>
                </div>
            </div>
            <div class="ltms-product-actions">
                <!-- CS-07: Edición inline en panel vendedor (no redirige a wp-admin) -->
                <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-edit-product-btn"
                        data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
                    ✏️ <?php esc_html_e( 'Editar', 'ltms' ); ?>
                </button>
                <a href="<?php echo esc_url( get_permalink( $product->get_id() ) ); ?>"
                   class="ltms-btn ltms-btn-outline ltms-btn-sm" target="_blank">
                    👁 <?php esc_html_e( 'Ver', 'ltms' ); ?>
                </a>
                <button type="button" class="ltms-btn ltms-btn-danger ltms-btn-sm ltms-delete-product-btn"
                        data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
                        data-product-name="<?php echo esc_attr( $product->get_name() ); ?>">
                    🗑 <?php esc_html_e( 'Eliminar', 'ltms' ); ?>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>

</div>

<!-- ═══════════════════════════════════════════════════════════════
     MODAL: Nuevo Producto  (id="ltms-modal-new-product")
     Requerido por los botones data-ltms-modal-open="ltms-modal-new-product"
     ═══════════════════════════════════════════════════════════════ -->
<div class="ltms-modal" id="ltms-modal-new-product">
    <div class="ltms-modal-backdrop"></div>
    <div class="ltms-modal-inner" style="max-width:560px;background:#fff;border-radius:12px;padding:28px;margin:auto;position:relative;z-index:1;max-height:90vh;overflow-y:auto;">

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="margin:0;font-size:1.1rem;"><?php esc_html_e( 'Nuevo Producto', 'ltms' ); ?></h3>
            <button type="button" class="ltms-modal-close" style="background:none;border:none;cursor:pointer;font-size:1.1rem;" aria-label="<?php esc_attr_e( 'Cerrar', 'ltms' ); ?>">✕</button>
        </div>

        <div id="ltms-np-notice" class="ltms-modal-error" style="display:none;margin-bottom:12px;padding:10px 14px;border-radius:6px;font-size:0.875rem;"></div>

        <!-- Imagen del producto -->
        <div style="margin-bottom:16px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Imagen del Producto', 'ltms' ); ?></label>
            <div id="ltms-np-img-preview" style="width:100%;height:140px;border:2px dashed #d1d5db;border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;background:#f9fafb;margin-bottom:8px;overflow:hidden;">
                <span style="color:#9ca3af;font-size:2rem;">📷</span>
            </div>
            <input type="file" id="ltms-np-img-input" accept="image/*" style="display:none;">
            <input type="hidden" id="ltms-np-image-id" value="">
            <button type="button" style="padding:6px 14px;border:1.5px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer;font-size:0.85rem;" id="ltms-np-img-btn">
                📁 <?php esc_html_e( 'Seleccionar imagen', 'ltms' ); ?>
            </button>
            <span id="ltms-np-img-status" style="font-size:0.8rem;color:#6b7280;margin-left:8px;"></span>
        </div>

        <!-- Nombre -->
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Nombre del Producto *', 'ltms' ); ?></label>
            <input type="text" id="ltms-np-name" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;" required placeholder="<?php esc_attr_e( 'Ej: Camiseta azul talla M', 'ltms' ); ?>">
        </div>

        <!-- Descripción -->
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Descripción', 'ltms' ); ?></label>
            <textarea id="ltms-np-desc" rows="3" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;resize:vertical;" placeholder="<?php esc_attr_e( 'Describe tu producto...', 'ltms' ); ?>"></textarea>
        </div>

        <!-- Precio y Stock en fila -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
            <div>
                <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Precio *', 'ltms' ); ?></label>
                <input type="number" id="ltms-np-price" min="0" step="0.01" required style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;" placeholder="0.00">
            </div>
            <div>
                <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Stock', 'ltms' ); ?></label>
                <input type="number" id="ltms-np-stock" min="0" step="1" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;" placeholder="<?php esc_attr_e( 'Dejar vacío = ilimitado', 'ltms' ); ?>">
            </div>
        </div>

        <!-- CS-07: Tipo — grilla 2×2 con los 4 tipos definidos por el marketplace -->
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;">
                <?php esc_html_e( 'Tipo de Producto', 'ltms' ); ?>
            </label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <label style="display:flex;align-items:center;gap:8px;padding:10px 14px;border:1.5px solid #d1d5db;border-radius:8px;cursor:pointer;background:#f9fafb;" id="ltms-np-tipo-physical-lbl">
                    <input type="radio" name="ltms_np_tipo" id="ltms-np-tipo-physical" value="physical" checked style="accent-color:#1a5276;">
                    <span>📦 <?php esc_html_e( 'Físico', 'ltms' ); ?></span>
                </label>
                <label style="display:flex;align-items:center;gap:8px;padding:10px 14px;border:1.5px solid #d1d5db;border-radius:8px;cursor:pointer;background:#f9fafb;" id="ltms-np-tipo-digital-lbl">
                    <input type="radio" name="ltms_np_tipo" id="ltms-np-tipo-digital" value="digital" style="accent-color:#1a5276;">
                    <span>💾 <?php esc_html_e( 'Digital', 'ltms' ); ?></span>
                </label>
                <label style="display:flex;align-items:center;gap:8px;padding:10px 14px;border:1.5px solid #d1d5db;border-radius:8px;cursor:pointer;background:#f9fafb;" id="ltms-np-tipo-service-lbl">
                    <input type="radio" name="ltms_np_tipo" id="ltms-np-tipo-service" value="service" style="accent-color:#1a5276;">
                    <span>🔧 <?php esc_html_e( 'Servicio', 'ltms' ); ?></span>
                </label>
                <label style="display:flex;align-items:center;gap:8px;padding:10px 14px;border:1.5px solid #d1d5db;border-radius:8px;cursor:pointer;background:#f9fafb;" id="ltms-np-tipo-booking-lbl">
                    <input type="radio" name="ltms_np_tipo" id="ltms-np-tipo-booking" value="booking" style="accent-color:#1a5276;">
                    <span>🏨 <?php esc_html_e( 'Turismo', 'ltms' ); ?></span>
                </label>
            </div>
        </div>

        <!-- Categoría -->
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Categoría', 'ltms' ); ?></label>
            <select id="ltms-np-category" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;">
                <option value=""><?php esc_html_e( 'Sin categoría', 'ltms' ); ?></option>
                <?php
                $np_terms = get_terms([ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 100 ]);
                if ( ! is_wp_error( $np_terms ) ) :
                    foreach ( $np_terms as $np_term ) :
                ?>
                <option value="<?php echo esc_attr( $np_term->term_id ); ?>"><?php echo esc_html( $np_term->name ); ?></option>
                <?php endforeach; endif; ?>
            </select>
        </div>

        <!-- Estado -->
        <div style="margin-bottom:20px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Estado al Publicar', 'ltms' ); ?></label>
            <select id="ltms-np-status" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;">
                <option value="pending"><?php esc_html_e( 'Pendiente de revisión', 'ltms' ); ?></option>
                <option value="draft"><?php esc_html_e( 'Borrador', 'ltms' ); ?></option>
                <option value="publish"><?php esc_html_e( 'Publicado directamente', 'ltms' ); ?></option>
            </select>
        </div>

        <!-- CS-08: ReDi toggle + tasa -->
        <?php
        // M-QA-11: ltms_redi_min_rate / max_rate / default_rate siempre se guardan en DB
        // como decimal [0,1] (todo campo "*_rate" pasa por el sanitizador de
        // class-ltms-admin-settings.php, que normaliza a decimal). Deben multiplicarse
        // por 100 para mostrarse como porcentaje en min/max/value/label del input —
        // de lo contrario el campo queda con rango "mín 0.05%, máx 0.4%" (ver QA sesión).
        $ltms_redi_min     = round( (float) get_option( 'ltms_redi_min_rate', 0.05 ) * 100, 2 );
        $ltms_redi_max     = round( (float) get_option( 'ltms_redi_max_rate', 0.40 ) * 100, 2 );
        $ltms_redi_default = round( (float) get_option( 'ltms_redi_default_rate', 0.15 ) * 100, 2 );
        if ( 'yes' === get_option('ltms_redi_enabled') ) : ?>
        <div style="margin-bottom:16px;padding:14px;background:#f0f7ff;border:1.5px solid #bfdbfe;border-radius:8px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                <input type="checkbox" id="ltms-np-redi-enabled" style="width:18px;height:18px;accent-color:#1a5276;cursor:pointer;">
                <label for="ltms-np-redi-enabled" style="font-weight:600;font-size:0.9rem;cursor:pointer;">
                    🔁 <?php esc_html_e( 'Habilitar distribución ReDi', 'ltms' ); ?>
                </label>
            </div>
            <div id="ltms-np-redi-rate-wrap" style="display:none;">
                <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;">
                    <?php printf( esc_html__( 'Comisión para revendedor (%% — mín %s%%, máx %s%%)', 'ltms' ), $ltms_redi_min, $ltms_redi_max ); ?>
                </label>
                <input type="number" id="ltms-np-redi-rate"
                    min="<?php echo esc_attr( $ltms_redi_min ); ?>"
                    max="<?php echo esc_attr( $ltms_redi_max ); ?>"
                    step="1"
                    placeholder="<?php echo esc_attr( $ltms_redi_default ); ?>"
                    value="<?php echo esc_attr( $ltms_redi_default ); ?>"
                    style="width:100%;padding:9px 12px;border:1.5px solid #93c5fd;border-radius:6px;box-sizing:border-box;">
                <p style="font-size:0.8rem;color:#4b5563;margin-top:4px;">
                    <?php esc_html_e( 'Porcentaje del precio de venta que recibirá el revendedor al distribuir tu producto.', 'ltms' ); ?>
                </p>
            </div>
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:12px;justify-content:flex-end;">
            <button type="button" class="ltms-modal-close" style="padding:10px 20px;border:1.5px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;">
                <?php esc_html_e( 'Cancelar', 'ltms' ); ?>
            </button>
            <button type="button" id="ltms-np-submit" style="padding:10px 22px;background:#1a5276;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;">
                ➕ <span id="ltms-np-btn-text"><?php esc_html_e( 'Crear Producto', 'ltms' ); ?></span>
            </button>
        </div>
    </div>
</div>

<script>
(function($){
    'use strict';

    // ── Imagen: click en preview o botón ─────────────────────────
    $('#ltms-np-img-preview, #ltms-np-img-btn').on('click', function(){
        $('#ltms-np-img-input').trigger('click');
        // CS-08: toggle ReDi rate field
        $('#ltms-np-redi-enabled').on('change', function(){
            $('#ltms-np-redi-rate-wrap').toggle($(this).is(':checked'));
        });
        $('#ltms-ep-redi-enabled').on('change', function(){
            $('#ltms-ep-redi-rate-wrap').toggle($(this).is(':checked'));
        });
    });

    $('#ltms-np-img-input').on('change', function(){
        const file = this.files[0];
        if (!file) return;

        const $status = $('#ltms-np-img-status');
        $status.text('<?php esc_js( __( 'Subiendo...', 'ltms' ) ); ?>');

        const formData = new FormData();
        formData.append('action', 'ltms_upload_product_image');
        formData.append('nonce',  ltmsDashboard.nonce);
        formData.append('image',  file);

        $.ajax({
            url: ltmsDashboard.ajax_url,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(res){
                if (res.success){
                    $('#ltms-np-image-id').val(res.data.attachment_id);
                    $('#ltms-np-img-preview').html(
                        '<img src="' + res.data.url + '" style="width:100%;height:100%;object-fit:cover;">'
                    );
                    $status.text('✓');
                } else {
                    $status.text('<?php esc_js( __( 'Error al subir imagen', 'ltms' ) ); ?>');
                }
            },
            error: function(){
                $status.text('<?php esc_js( __( 'Error de conexión', 'ltms' ) ); ?>');
            }
        });
    });

    // ── Crear producto ────────────────────────────────────────────
    $('#ltms-np-submit').on('click', function(){
        const name  = $('#ltms-np-name').val().trim();
        const price = parseFloat($('#ltms-np-price').val());
        const $notice = $('#ltms-np-notice');

        if (!name || isNaN(price) || price <= 0){
            $notice.removeClass('ltms-notice-success')
                   .addClass('ltms-notice-error')
                   .text('<?php esc_js( __( 'Nombre y precio son obligatorios.', 'ltms' ) ); ?>')
                   .show();
            return;
        }

        const $btn     = $(this);
        const origText = $btn.html();
        $btn.prop('disabled', true).text('Guardando...');

        $.ajax({
            url: ltmsDashboard.ajax_url,
            method: 'POST',
            data: {
                action:       'ltms_create_product',
                nonce:        ltmsDashboard.nonce,
                name:         name,
                description:  $('#ltms-np-desc').val(),
                price:        price,
                stock:        $('#ltms-np-stock').val(),
                category_id:  $('#ltms-np-category').val(),
                image_id:     $('#ltms-np-image-id').val(),
                status:       $('#ltms-np-status').val(),
                product_type:    $('input[name="ltms_np_tipo"]:checked').val() || 'physical',
                redi_enabled:    $('#ltms-np-redi-enabled').is(':checked') ? 'yes' : 'no',
                redi_rate:       parseFloat($('#ltms-np-redi-rate').val()) || 0,
            },
            success: function(res){
                $btn.prop('disabled', false).html(origText);

                if (res.success){
                    $notice.removeClass('ltms-notice-error')
                           .addClass('ltms-notice-success')
                           .text('✅ <?php esc_js( __( 'Producto creado exitosamente. Recargando...', 'ltms' ) ); ?>')
                           .show();
                    // Reset form
                    $('#ltms-np-name,#ltms-np-desc,#ltms-np-stock').val('');
                    $('#ltms-np-price').val('');
                    $('#ltms-np-image-id').val('');
                    $('#ltms-np-img-preview').html('<span style="color:#9ca3af;font-size:2rem;">📷</span>');
                    $('#ltms-np-img-status').text('');
                    $('input[name="ltms_np_tipo"][value="physical"]').prop('checked', true).trigger('change');
                    setTimeout(function(){ location.reload(); }, 1500);
                } else {
                    $notice.removeClass('ltms-notice-success')
                           .addClass('ltms-notice-error')
                           .text(res.data || '<?php esc_js( __( 'Error al crear el producto.', 'ltms' ) ); ?>')
                           .show();
                }
            },
            error: function(){
                $btn.prop('disabled', false).html(origText);
                $notice.removeClass('ltms-notice-success')
                       .addClass('ltms-notice-error')
                       .text('<?php esc_js( __( 'Error de conexión. Intenta de nuevo.', 'ltms' ) ); ?>')
                       .show();
            }
        });
    });

    // ── Botones del estado inicial PHP → SPA fallback ────────────
    $(document).on('click', '#ltms-add-product-btn, #ltms-add-product-btn-empty', function(e){
        e.preventDefault();
        if (typeof LTMS !== 'undefined' && LTMS.Dashboard && typeof LTMS.Dashboard.loadNewProductView === 'function') {
            LTMS.Dashboard.loadNewProductView();
        } else {
            LTMS.Modal.open('ltms-modal-new-product');
        }
    });

    // ── Limpiar modal al cerrar ───────────────────────────────────
    $(document).on('click', '.ltms-modal-backdrop, .ltms-modal-close', function(){
        $('#ltms-np-notice').hide().text('');
        $('#ltms-np-name, #ltms-np-desc, #ltms-np-stock, #ltms-np-price').val('');
        $('#ltms-np-image-id').val('');
        $('#ltms-np-img-preview').html('<span style="color:#9ca3af;font-size:2rem;">📷</span>');
        $('#ltms-np-img-status').text('');
        $('input[name="ltms_np_tipo"][value="physical"]').prop('checked', true);
        // CS-04: reset visual de los 4 tipos
        ['physical','digital','service','booking'].forEach(function(t){
            $('#ltms-np-tipo-'+t+'-lbl').css({'border-color':'#d1d5db','background':'#f9fafb'});
        });
        $('#ltms-np-tipo-physical-lbl').css({'border-color':'#1a5276','background':'#eff6ff'});
    });

    // CS-04/CS-06: highlight para los 4 tipos
    $(document).on('change', 'input[name="ltms_np_tipo"]', function(){
        ['physical','digital','service','booking'].forEach(function(t){
            $('#ltms-np-tipo-'+t+'-lbl').css({'border-color':'#d1d5db','background':'#f9fafb'});
        });
        $('#ltms-np-tipo-'+$(this).val()+'-lbl').css({'border-color':'#1a5276','background':'#eff6ff'});
    });
    // Estado inicial del highlight
    var _initTipo = $('input[name="ltms_np_tipo"]:checked').val() || 'physical';
    $('#ltms-np-tipo-'+_initTipo+'-lbl').css({'border-color':'#1a5276','background':'#eff6ff'});

    // ── CS-07: Editar producto inline ────────────────────────────
    $(document).on('click', '.ltms-edit-product-btn', function(){
        var pid = $(this).data('product-id');
        $('#ltms-ep-notice').hide().text('');
        $('#ltms-ep-product-id').val(pid);
        $('#ltms-ep-name,#ltms-ep-desc,#ltms-ep-price,#ltms-ep-stock').val('');
        $('#ltms-ep-img-preview').html('<span style="color:#9ca3af;font-size:2rem;">📷</span>');
        $('#ltms-ep-image-id').val('');
        $.ajax({
            url: ltmsDashboard.ajax_url,
            method: 'POST',
            data: { action:'ltms_get_product', nonce:ltmsDashboard.nonce, product_id:pid },
            success: function(res){
                if(!res.success) return;
                var d = res.data;
                $('#ltms-ep-name').val(d.name);
                $('#ltms-ep-desc').val(d.description);
                $('#ltms-ep-price').val(d.price);
                $('#ltms-ep-stock').val(d.stock !== null ? d.stock : '');
                $('#ltms-ep-category').val(d.category_id);
                $('#ltms-ep-status').val(d.status);
                $('#ltms-ep-image-id').val(d.image_id);
                if(d.image_url){ $('#ltms-ep-img-preview').html('<img src="'+d.image_url+'" style="width:100%;height:100%;object-fit:cover;">'); }
                // Tipo
                var tipo = d.product_type || 'physical';
                $('input[name="ltms_ep_tipo"][value="'+tipo+'"]').prop('checked',true).trigger('change');
                // M-QA-11: poblar estado real de ReDi (antes el modal siempre arrancaba
                // sin marcar, ignorando si el producto ya tenía ReDi activo y a qué tasa).
                // d.redi_rate llega ya en porcentaje (backend hace ×100 en get_product()).
                var rediOn = d.redi_enabled === 'yes';
                $('#ltms-ep-redi-enabled').prop('checked', rediOn);
                $('#ltms-ep-redi-rate-wrap').toggle(rediOn);
                if (d.redi_rate) { $('#ltms-ep-redi-rate').val(d.redi_rate); }
                LTMS.Modal.open('ltms-modal-edit-product');
            }
        });
    });

    // Imagen modal edición
    $('#ltms-ep-img-preview, #ltms-ep-img-btn').on('click', function(){ $('#ltms-ep-img-input').trigger('click'); });
    $('#ltms-ep-img-input').on('change', function(){
        var file = this.files[0]; if(!file) return;
        var $s = $('#ltms-ep-img-status'); $s.text('Subiendo...');
        var fd = new FormData();
        fd.append('action','ltms_upload_product_image');
        fd.append('nonce', ltmsDashboard.nonce);
        fd.append('image', file);
        $.ajax({ url:ltmsDashboard.ajax_url, method:'POST', data:fd, processData:false, contentType:false,
            success:function(r){ if(r.success){ $('#ltms-ep-image-id').val(r.data.attachment_id); $('#ltms-ep-img-preview').html('<img src="'+r.data.url+'" style="width:100%;height:100%;object-fit:cover;">'); $s.text('✓'); } else { $s.text('Error'); } },
            error:function(){ $s.text('Error'); }
        });
    });

    // Highlight tipo en modal edición
    $(document).on('change','input[name="ltms_ep_tipo"]',function(){
        ['physical','digital','service','booking'].forEach(function(t){ $('#ltms-ep-tipo-'+t+'-lbl').css({'border-color':'#d1d5db','background':'#f9fafb'}); });
        $('#ltms-ep-tipo-'+$(this).val()+'-lbl').css({'border-color':'#1a5276','background':'#eff6ff'});
    });

    // Guardar cambios edición
    $('#ltms-ep-submit').on('click', function(){
        var name  = $('#ltms-ep-name').val().trim();
        var price = parseFloat($('#ltms-ep-price').val());
        var $n    = $('#ltms-ep-notice');
        if(!name || isNaN(price) || price<=0){ $n.removeClass('ltms-notice-success').addClass('ltms-notice-error').text('Nombre y precio son obligatorios.').show(); return; }
        var $btn = $(this); $btn.prop('disabled',true).text('Guardando...');
        $.ajax({
            url: ltmsDashboard.ajax_url, method:'POST',
            data:{
                action:'ltms_update_product', nonce:ltmsDashboard.nonce,
                product_id: $('#ltms-ep-product-id').val(),
                name:name, description:$('#ltms-ep-desc').val(),
                price:price, stock:$('#ltms-ep-stock').val(),
                category_id:$('#ltms-ep-category').val(),
                status:$('#ltms-ep-status').val(),
                image_id:$('#ltms-ep-image-id').val(),
                product_type:$('input[name="ltms_ep_tipo"]:checked').val()||'physical',
                redi_enabled:$('#ltms-ep-redi-enabled').is(':checked') ? 'yes' : 'no',
                redi_rate:parseFloat($('#ltms-ep-redi-rate').val())||0,
            },
            success:function(res){
                $btn.prop('disabled',false).text('Guardar Cambios');
                if(res.success){
                    $n.removeClass('ltms-notice-error').addClass('ltms-notice-success').text('✅ Cambios guardados. Recargando...').show();
                    setTimeout(function(){ location.reload(); },1500);
                } else {
                    $n.removeClass('ltms-notice-success').addClass('ltms-notice-error').text(res.data||'Error al guardar.').show();
                }
            },
            error:function(){ $btn.prop('disabled',false).text('Guardar Cambios'); $n.addClass('ltms-notice-error').text('Error de conexión.').show(); }
        });
    });

    // CS-07: Eliminar producto
    $(document).on('click', '.ltms-delete-product-btn', function(){
        var pid  = $(this).data('product-id');
        var name = $(this).data('product-name');
        if(!confirm('¿Eliminar el producto "'+name+'"? Esta acción no se puede deshacer.')) return;
        $.ajax({
            url:ltmsDashboard.ajax_url, method:'POST',
            data:{action:'ltms_delete_product', nonce:ltmsDashboard.nonce, product_id:pid},
            success:function(res){ if(res.success){ location.reload(); } else { alert(res.data||'No se pudo eliminar.'); } },
            error:function(){ alert('Error de conexión.'); }
        });
    });

})(jQuery);
</script>

<!-- ═══════════════════════════════════════════════════════════════
     MODAL: Editar Producto  (id="ltms-modal-edit-product")
     CS-07: edición inline en panel vendedor sin redirigir a wp-admin
     ═══════════════════════════════════════════════════════════════ -->
<div class="ltms-modal" id="ltms-modal-edit-product">
    <div class="ltms-modal-backdrop"></div>
    <div class="ltms-modal-inner" style="max-width:560px;background:#fff;border-radius:12px;padding:28px;margin:auto;position:relative;z-index:1;max-height:90vh;overflow-y:auto;">

        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="margin:0;font-size:1.1rem;"><?php esc_html_e( 'Editar Producto', 'ltms' ); ?></h3>
            <button type="button" class="ltms-modal-close" style="background:none;border:none;cursor:pointer;font-size:1.1rem;" aria-label="Cerrar">✕</button>
        </div>

        <input type="hidden" id="ltms-ep-product-id" value="">
        <div id="ltms-ep-notice" class="ltms-modal-error" style="display:none;margin-bottom:12px;padding:10px 14px;border-radius:6px;font-size:0.875rem;"></div>

        <!-- Imagen -->
        <div style="margin-bottom:16px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Imagen del Producto', 'ltms' ); ?></label>
            <div id="ltms-ep-img-preview" style="width:100%;height:140px;border:2px dashed #d1d5db;border-radius:8px;display:flex;align-items:center;justify-content:center;cursor:pointer;background:#f9fafb;overflow:hidden;">
                <span style="color:#9ca3af;font-size:2rem;">📷</span>
            </div>
            <input type="file" id="ltms-ep-img-input" accept="image/*" style="display:none;">
            <input type="hidden" id="ltms-ep-image-id" value="">
            <button type="button" style="margin-top:8px;padding:6px 14px;border:1.5px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer;font-size:0.85rem;" id="ltms-ep-img-btn">
                📁 <?php esc_html_e( 'Cambiar imagen', 'ltms' ); ?>
            </button>
            <span id="ltms-ep-img-status" style="font-size:0.8rem;color:#6b7280;margin-left:8px;"></span>
        </div>

        <!-- Nombre -->
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Nombre del Producto *', 'ltms' ); ?></label>
            <input type="text" id="ltms-ep-name" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;">
        </div>

        <!-- Descripción -->
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Descripción', 'ltms' ); ?></label>
            <textarea id="ltms-ep-desc" rows="3" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;resize:vertical;"></textarea>
        </div>

        <!-- Precio y Stock -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
            <div>
                <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Precio *', 'ltms' ); ?></label>
                <input type="number" id="ltms-ep-price" min="0" step="0.01" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;">
            </div>
            <div>
                <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Stock', 'ltms' ); ?></label>
                <input type="number" id="ltms-ep-stock" min="0" step="1" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;" placeholder="<?php esc_attr_e( 'Vacío = ilimitado', 'ltms' ); ?>">
            </div>
        </div>

        <!-- Tipo — grilla 2×2 -->
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Tipo de Producto', 'ltms' ); ?></label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <label style="display:flex;align-items:center;gap:8px;padding:10px 14px;border:1.5px solid #d1d5db;border-radius:8px;cursor:pointer;background:#f9fafb;" id="ltms-ep-tipo-physical-lbl">
                    <input type="radio" name="ltms_ep_tipo" value="physical" style="accent-color:#1a5276;"> <span>📦 <?php esc_html_e( 'Físico', 'ltms' ); ?></span>
                </label>
                <label style="display:flex;align-items:center;gap:8px;padding:10px 14px;border:1.5px solid #d1d5db;border-radius:8px;cursor:pointer;background:#f9fafb;" id="ltms-ep-tipo-digital-lbl">
                    <input type="radio" name="ltms_ep_tipo" value="digital" style="accent-color:#1a5276;"> <span>💾 <?php esc_html_e( 'Digital', 'ltms' ); ?></span>
                </label>
                <label style="display:flex;align-items:center;gap:8px;padding:10px 14px;border:1.5px solid #d1d5db;border-radius:8px;cursor:pointer;background:#f9fafb;" id="ltms-ep-tipo-service-lbl">
                    <input type="radio" name="ltms_ep_tipo" value="service" style="accent-color:#1a5276;"> <span>🔧 <?php esc_html_e( 'Servicio', 'ltms' ); ?></span>
                </label>
                <label style="display:flex;align-items:center;gap:8px;padding:10px 14px;border:1.5px solid #d1d5db;border-radius:8px;cursor:pointer;background:#f9fafb;" id="ltms-ep-tipo-booking-lbl">
                    <input type="radio" name="ltms_ep_tipo" value="booking" style="accent-color:#1a5276;"> <span>🏨 <?php esc_html_e( 'Turismo', 'ltms' ); ?></span>
                </label>
            </div>
        </div>

        <!-- Categoría -->
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Categoría', 'ltms' ); ?></label>
            <select id="ltms-ep-category" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;">
                <option value=""><?php esc_html_e( 'Sin categoría', 'ltms' ); ?></option>
                <?php
                $ep_terms = get_terms([ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 100 ]);
                if ( ! is_wp_error( $ep_terms ) ) :
                    foreach ( $ep_terms as $ep_term ) :
                ?>
                <option value="<?php echo esc_attr( $ep_term->term_id ); ?>"><?php echo esc_html( $ep_term->name ); ?></option>
                <?php endforeach; endif; ?>
            </select>
        </div>

        <!-- Estado -->
        <div style="margin-bottom:20px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Estado', 'ltms' ); ?></label>
            <select id="ltms-ep-status" style="width:100%;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:6px;box-sizing:border-box;">
                <option value="pending"><?php esc_html_e( 'Pendiente de revisión', 'ltms' ); ?></option>
                <option value="draft"><?php esc_html_e( 'Borrador', 'ltms' ); ?></option>
                <option value="publish"><?php esc_html_e( 'Publicado', 'ltms' ); ?></option>
            </select>
        </div>

        <!-- CS-08: ReDi toggle + tasa (edición) -->
        <?php if ( 'yes' === get_option('ltms_redi_enabled') ) : ?>
        <div id="ltms-ep-redi-wrap" style="margin-bottom:16px;padding:14px;background:#f0f7ff;border:1.5px solid #bfdbfe;border-radius:8px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                <input type="checkbox" id="ltms-ep-redi-enabled" style="width:18px;height:18px;accent-color:#1a5276;cursor:pointer;">
                <label for="ltms-ep-redi-enabled" style="font-weight:600;font-size:0.9rem;cursor:pointer;">
                    🔁 <?php esc_html_e( 'Habilitar distribución ReDi', 'ltms' ); ?>
                </label>
            </div>
            <div id="ltms-ep-redi-rate-wrap" style="display:none;">
                <?php
                // M-QA-11: misma normalización decimal→porcentaje que en el modal de creación.
                $ltms_redi_min = round( (float) get_option( 'ltms_redi_min_rate', 0.05 ) * 100, 2 );
                $ltms_redi_max = round( (float) get_option( 'ltms_redi_max_rate', 0.40 ) * 100, 2 );
                ?>
                <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;">
                    <?php printf( esc_html__( 'Comisión para revendedor (%% — mín %s%%, máx %s%%)', 'ltms' ), $ltms_redi_min, $ltms_redi_max ); ?>
                </label>
                <input type="number" id="ltms-ep-redi-rate"
                    min="<?php echo esc_attr( $ltms_redi_min ); ?>"
                    max="<?php echo esc_attr( $ltms_redi_max ); ?>"
                    step="1"
                    style="width:100%;padding:9px 12px;border:1.5px solid #93c5fd;border-radius:6px;box-sizing:border-box;">
                <p style="font-size:0.8rem;color:#4b5563;margin-top:4px;">
                    <?php esc_html_e( 'Porcentaje del precio de venta que recibirá el revendedor al distribuir tu producto.', 'ltms' ); ?>
                </p>
            </div>
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:12px;justify-content:flex-end;">
            <button type="button" class="ltms-modal-close" style="padding:10px 20px;border:1.5px solid #d1d5db;border-radius:8px;background:#fff;cursor:pointer;">
                <?php esc_html_e( 'Cancelar', 'ltms' ); ?>
            </button>
            <button type="button" id="ltms-ep-submit" style="padding:10px 22px;background:#1a5276;color:#fff;border:none;border-radius:8px;cursor:pointer;font-weight:600;">
                💾 <?php esc_html_e( 'Guardar Cambios', 'ltms' ); ?>
            </button>
        </div>
    </div>
</div>

