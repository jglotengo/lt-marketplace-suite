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
                    $ltms_tipo = get_post_meta( $product->get_id(), '_ltms_product_type', true ) ?: 'product';
                    $ltms_tipo_label = $ltms_tipo === 'service' ? __( 'Servicio', 'ltms' ) : __( 'Producto', 'ltms' );
                    $ltms_tipo_icon  = $ltms_tipo === 'service' ? '🔧' : '📦';
                    ?>
                    <span class="ltms-badge ltms-badge-info" style="font-size:0.7rem;margin-left:4px;background:#e0f2fe;color:#0369a1;">
                        <?php echo $ltms_tipo_icon . ' ' . esc_html( $ltms_tipo_label ); ?>
                    </span>
                </div>
            </div>
            <div class="ltms-product-actions">
                <a href="<?php echo esc_url( get_edit_post_link( $product->get_id() ) ); ?>"
                   class="ltms-btn ltms-btn-outline ltms-btn-sm" target="_blank">
                    ✏️ <?php esc_html_e( 'Editar', 'ltms' ); ?>
                </a>
                <a href="<?php echo esc_url( get_permalink( $product->get_id() ) ); ?>"
                   class="ltms-btn ltms-btn-outline ltms-btn-sm" target="_blank">
                    👁 <?php esc_html_e( 'Ver', 'ltms' ); ?>
                </a>
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

        <!-- Tipo: Producto o Servicio -->
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Tipo', 'ltms' ); ?> <span style="font-size:0.75rem;color:#6b7280;font-weight:400;"><?php esc_html_e( '(afecta el cálculo de comisiones)', 'ltms' ); ?></span></label>
            <div style="display:flex;gap:10px;">
                <label style="flex:1;display:flex;align-items:center;gap:8px;padding:10px 14px;border:1.5px solid #d1d5db;border-radius:8px;cursor:pointer;background:#f9fafb;transition:border-color .15s;" id="ltms-np-tipo-product-lbl">
                    <input type="radio" name="ltms_np_tipo" id="ltms-np-tipo-product" value="product" checked style="accent-color:#1a5276;">
                    <span>📦 <?php esc_html_e( 'Producto físico', 'ltms' ); ?></span>
                </label>
                <label style="flex:1;display:flex;align-items:center;gap:8px;padding:10px 14px;border:1.5px solid #d1d5db;border-radius:8px;cursor:pointer;background:#f9fafb;transition:border-color .15s;" id="ltms-np-tipo-service-lbl">
                    <input type="radio" name="ltms_np_tipo" id="ltms-np-tipo-service" value="service" style="accent-color:#1a5276;">
                    <span>🔧 <?php esc_html_e( 'Servicio', 'ltms' ); ?></span>
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
                product_type: $('input[name="ltms_np_tipo"]:checked').val() || 'product',
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
                    $('input[name="ltms_np_tipo"][value="product"]').prop('checked', true);
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
        $('input[name="ltms_np_tipo"][value="product"]').prop('checked', true);
        $('#ltms-np-tipo-product-lbl').css({'border-color':'#1a5276','background':'#eff6ff'});
        $('#ltms-np-tipo-service-lbl').css({'border-color':'#d1d5db','background':'#f9fafb'});
    });

    // ── Highlight visual para selector de tipo ───────────────────
    $(document).on('change', 'input[name="ltms_np_tipo"]', function(){
        const val = $(this).val();
        if (val === 'product') {
            $('#ltms-np-tipo-product-lbl').css({'border-color':'#1a5276','background':'#eff6ff'});
            $('#ltms-np-tipo-service-lbl').css({'border-color':'#d1d5db','background':'#f9fafb'});
        } else {
            $('#ltms-np-tipo-service-lbl').css({'border-color':'#1a5276','background':'#eff6ff'});
            $('#ltms-np-tipo-product-lbl').css({'border-color':'#d1d5db','background':'#f9fafb'});
        }
    });
    // Estado inicial del highlight
    $('#ltms-np-tipo-product-lbl').css({'border-color':'#1a5276','background':'#eff6ff'});

})(jQuery);
</script>
