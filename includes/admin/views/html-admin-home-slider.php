<?php
/**
 * Vista de administración: Home Slider del marketplace.
 *
 * HOME-SLIDER FIX (2026-09-08): carrusel de banners del home gestionado por
 * LTMS (reemplaza el widget Slides de Elementor). El admin carga las imágenes
 * con las dimensiones recomendadas y estas se actualizan en el home.
 *
 * @package LTMS
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) wp_die( esc_html__( 'No tienes permiso para acceder a esta pagina.', 'ltms' ) );

$slider = new LTMS_Frontend_Home_Slider();
$slides = $slider->get_slides( false );
$autoplay = (int) get_option( 'ltms_home_slider_autoplay', 5000 );
?>

<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <h1>🖼 <?php esc_html_e( 'Home Slider', 'ltms' ); ?></h1>
        <p style="color:#6b7280;margin:8px 0 0;">
            <?php esc_html_e( 'Carrusel de banners de la página de inicio. Reemplaza el widget de Elementor y se gestiona 100% desde aquí.', 'ltms' ); ?>
        </p>
    </div>

    <!-- Guía de dimensiones -->
    <div class="notice notice-info" style="margin:16px 0;">
        <p>
            <strong>📐 <?php esc_html_e( 'Dimensiones recomendadas:', 'ltms' ); ?></strong><br>
            • <?php esc_html_e( 'Desktop (panorámico):', 'ltms' ); ?> <code>1920 × 600–800 px</code> <?php esc_html_e( '(relación 16:5 a 16:6)', 'ltms' ); ?><br>
            • <?php esc_html_e( 'Mobile (opcional):', 'ltms' ); ?> <code>600 × 600 px</code> <?php esc_html_e( '(relación 1:1, se muestra en pantallas &lt;768px)', 'ltms' ); ?><br>
            • <?php esc_html_e( 'Peso máximo:', 'ltms' ); ?> <code>2 MB</code> · <?php esc_html_e( 'Formatos:', 'ltms' ); ?> <code>JPG, PNG, WebP</code><br>
            • <?php esc_html_e( 'Deja el texto/logo importante en el 20–30% central de la imagen (zona segura).', 'ltms' ); ?>
        </p>
    </div>

    <div id="ltms-hs-notice" style="display:none;margin:12px 0;padding:12px 16px;border-radius:8px;font-weight:600;"></div>

    <!-- Lista de slides -->
    <div id="ltms-hs-list" style="display:flex;flex-direction:column;gap:14px;margin-bottom:20px;">
        <?php if ( empty( $slides ) ) : ?>
        <div id="ltms-hs-empty" style="text-align:center;padding:48px 24px;background:#fff;border:2px dashed #d1d5db;border-radius:12px;color:#6b7280;">
            <?php esc_html_e( 'Aún no hay banners. Usa "Añadir banner" para comenzar.', 'ltms' ); ?>
        </div>
        <?php else : ?>
        <?php foreach ( $slides as $i => $s ) : ?>
        <div class="ltms-hs-card" data-ltms-hs-card data-index="<?php echo esc_attr( $i ); ?>">
            <div class="ltms-hs-card__thumb">
                <?php if ( ! empty( $s['image_desktop'] ) ) : ?>
                    <img src="<?php echo esc_url( $s['image_desktop'] ); ?>" alt="">
                <?php else : ?>
                    <span style="color:#9ca3af;"><?php esc_html_e( 'Sin imagen', 'ltms' ); ?></span>
                <?php endif; ?>
            </div>
            <div class="ltms-hs-card__fields" style="flex:1;display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div>
                    <label style="font-size:12px;font-weight:600;"><?php esc_html_e( 'Título / Alt', 'ltms' ); ?></label>
                    <input type="text" class="ltms-hs-field" data-field="title" value="<?php echo esc_attr( $s['title'] ?? '' ); ?>" style="width:100%;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;">
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;"><?php esc_html_e( 'Orden', 'ltms' ); ?></label>
                    <input type="number" class="ltms-hs-field" data-field="order" value="<?php echo esc_attr( $s['order'] ?? 0 ); ?>" min="0" style="width:100%;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;">
                </div>
                <div style="grid-column:1/-1;">
                    <label style="font-size:12px;font-weight:600;"><?php esc_html_e( 'Imagen Desktop (1920×600-800)', 'ltms' ); ?></label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="hidden" class="ltms-hs-field" data-field="image_desktop" value="<?php echo esc_attr( $s['image_desktop'] ?? '' ); ?>">
                        <input type="text" class="ltms-hs-img-preview" value="<?php echo esc_attr( $s['image_desktop'] ?? '' ); ?>" readonly style="flex:1;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:11px;color:#6b7280;">
                        <button type="button" class="button ltms-hs-upload" data-target="image_desktop"><?php esc_html_e( 'Subir', 'ltms' ); ?></button>
                    </div>
                </div>
                <div style="grid-column:1/-1;">
                    <label style="font-size:12px;font-weight:600;"><?php esc_html_e( 'Imagen Mobile opcional (600×600)', 'ltms' ); ?></label>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <input type="hidden" class="ltms-hs-field" data-field="image_mobile" value="<?php echo esc_attr( $s['image_mobile'] ?? '' ); ?>">
                        <input type="text" class="ltms-hs-img-preview" value="<?php echo esc_attr( $s['image_mobile'] ?? '' ); ?>" readonly style="flex:1;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:11px;color:#6b7280;">
                        <button type="button" class="button ltms-hs-upload" data-target="image_mobile"><?php esc_html_e( 'Subir', 'ltms' ); ?></button>
                    </div>
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;"><?php esc_html_e( 'Texto del botón (CTA)', 'ltms' ); ?></label>
                    <input type="text" class="ltms-hs-field" data-field="cta_text" value="<?php echo esc_attr( $s['cta_text'] ?? '' ); ?>" style="width:100%;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;">
                </div>
                <div>
                    <label style="font-size:12px;font-weight:600;"><?php esc_html_e( 'URL del botón', 'ltms' ); ?></label>
                    <input type="text" class="ltms-hs-field" data-field="cta_url" value="<?php echo esc_attr( $s['cta_url'] ?? '' ); ?>" placeholder="https://..." style="width:100%;padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;">
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <label style="font-size:12px;font-weight:600;margin:0;">
                        <input type="checkbox" class="ltms-hs-field" data-field="active" <?php checked( ( $s['active'] ?? '1' ) !== '0' ); ?> value="1">
                        <?php esc_html_e( 'Activo', 'ltms' ); ?>
                    </label>
                </div>
                <div style="text-align:right;">
                    <button type="button" class="button button-link-delete ltms-hs-delete" data-index="<?php echo esc_attr( $i ); ?>"><?php esc_html_e( 'Eliminar banner', 'ltms' ); ?></button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
        <button type="button" class="button button-primary" id="ltms-hs-add">➕ <?php esc_html_e( 'Añadir banner', 'ltms' ); ?></button>
        <button type="button" class="button button-primary button-hero" id="ltms-hs-save" style="background:#16a34a;border-color:#16a34a;">
            💾 <?php esc_html_e( 'Guardar slider', 'ltms' ); ?>
        </button>
        <span id="ltms-hs-save-status" style="font-size:13px;color:#6b7280;"></span>
    </div>

    <p style="margin-top:16px;font-size:12px;color:#6b7280;">
        <?php esc_html_e( 'El slider se renderiza en el home automáticamente y el widget de Elementor queda oculto. Si no hay banners activos, no se muestra nada.', 'ltms' ); ?>
    </p>

</div>