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
<div style="padding:24px;">

    <div class="ltms-view-header">
        <h2><?php esc_html_e( 'Mis Productos', 'ltms' ); ?></h2>
        <button type="button" class="ltms-btn ltms-btn-primary" data-ltms-modal-open="ltms-modal-new-product">
            ➕ <?php esc_html_e( 'Nuevo Producto', 'ltms' ); ?>
        </button>
    </div>

    <?php if ( empty( $products ) ) : ?>
    <div class="ltms-empty-state">
        <div class="ltms-empty-icon">🛍️</div>
        <h3><?php esc_html_e( 'Aún no tienes productos', 'ltms' ); ?></h3>
        <p><?php esc_html_e( 'Agrega tu primer producto para comenzar a vender.', 'ltms' ); ?></p>
        <button type="button" class="ltms-btn ltms-btn-primary" data-ltms-modal-open="ltms-modal-new-product">
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
