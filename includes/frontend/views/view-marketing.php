<?php
/**
 * Vista SPA: Marketing — Banners Promocionales para Vendedores
 *
 * Permite a los vendedores ver y descargar material promocional
 * subido por el admin desde admin.php?page=ltms-marketing
 *
 * @package LTMS
 * @version 2.9.31
 */

if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$banners_table = $wpdb->prefix . 'lt_marketing_banners';

$type_filter = sanitize_key( $_GET['type'] ?? '' ); // phpcs:ignore
$page_num    = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore
$per_page    = 24;
$offset      = ( $page_num - 1 ) * $per_page;

$valid_types = [ 'banner', 'flyer', 'social_post', 'email_template', 'video' ];
$type_labels = [
    'banner'         => '🖼 Banner',
    'flyer'          => '📄 Flyer',
    'social_post'    => '📱 Social',
    'email_template' => '✉ Email',
    'video'          => '🎬 Video',
];

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
$where       = $type_filter && in_array( $type_filter, $valid_types, true )
              ? $wpdb->prepare( 'WHERE type = %s AND is_active = 1', $type_filter )
              : 'WHERE is_active = 1';
$total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$banners_table}` {$where}" );
$total_pages = max( 1, (int) ceil( $total / $per_page ) );

$banners = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT * FROM `{$banners_table}` {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
        $per_page, $offset
    ),
    ARRAY_A
);

$type_counts_raw = $wpdb->get_results( "SELECT type, COUNT(*) as total FROM `{$banners_table}` WHERE is_active = 1 GROUP BY type", ARRAY_A );
$type_counts = array_column( $type_counts_raw, 'total', 'type' );
// phpcs:enable
?>
<div style="padding:24px;" id="ltms-marketing-view">

    <div class="ltms-view-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;flex-wrap:wrap;gap:12px;">
        <h2 style="margin:0;">🎨 Material Promocional</h2>
        <span style="font-size:12px;color:#6b7280;">
            <?php printf( esc_html__( '%d materiales disponibles', 'ltms' ), $total ); ?>
        </span>
    </div>

    <?php if ( empty( $banners ) ) : ?>
    <div style="text-align:center;padding:64px 24px;background:#fff;border-radius:12px;border:1px solid #e5e7eb;">
        <div style="font-size:64px;margin-bottom:16px;">📭</div>
        <h3 style="margin:0 0 8px;color:#374151;"><?php esc_html_e( 'No hay material promocional disponible', 'ltms' ); ?></h3>
        <p style="color:#9ca3af;margin:0;">
            <?php esc_html_e( 'El administrador aún no ha subido banners, flyers ni otros materiales. Vuelve pronto.', 'ltms' ); ?>
        </p>
    </div>
    <?php else : ?>

    <!-- Filtros por tipo -->
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:20px;">
        <button type="button" class="ltms-btn ltms-btn-sm ltms-btn-primary ltms-mkt-filter" data-type="">
            <?php esc_html_e( 'Todos', 'ltms' ); ?>
            <span style="margin-left:4px;opacity:.7;">(<?php echo esc_html( $total ); ?>)</span>
        </button>
        <?php foreach ( $type_labels as $t => $label ) : ?>
        <button type="button" class="ltms-btn ltms-btn-sm ltms-btn-outline ltms-mkt-filter" data-type="<?php echo esc_attr( $t ); ?>">
            <?php echo esc_html( $label ); ?>
            <span style="margin-left:4px;opacity:.7;">(<?php echo esc_html( $type_counts[ $t ] ?? 0 ); ?>)</span>
        </button>
        <?php endforeach; ?>
    </div>

    <!-- Grid de banners -->
    <div id="ltms-marketing-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;">
        <?php foreach ( $banners as $banner ) :
            $thumb = $banner['thumbnail_url'] ?: $banner['file_url'];
            $is_img = preg_match( '/\.(jpg|jpeg|png|gif|webp|svg)(\?.*)?$/i', $banner['file_url'] );
            $is_video = preg_match( '/\.(mp4|webm|ogg)(\?.*)?$/i', $banner['file_url'] );
        ?>
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);display:flex;flex-direction:column;">
            <?php if ( $thumb && $is_img ) : ?>
            <div style="height:130px;overflow:hidden;background:#f3f4f6;">
                <img src="<?php echo esc_url( $thumb ); ?>" alt="" style="width:100%;height:100%;object-fit:cover;" loading="lazy">
            </div>
            <?php elseif ( $is_video ) : ?>
            <div style="height:130px;background:#000;display:flex;align-items:center;justify-content:center;">
                <video src="<?php echo esc_url( $banner['file_url'] ); ?>" style="width:100%;height:100%;object-fit:cover;" preload="metadata" muted></video>
            </div>
            <?php else : ?>
            <div style="height:130px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;font-size:40px;">📁</div>
            <?php endif; ?>

            <div style="padding:10px 12px;flex:1;display:flex;flex-direction:column;">
                <div style="font-weight:600;font-size:0.85rem;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                    <?php echo esc_html( $banner['title'] ); ?>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px;">
                    <span style="font-size:11px;color:#6b7280;">
                        <?php echo esc_html( $type_labels[ $banner['type'] ] ?? $banner['type'] ); ?>
                    </span>
                    <?php if ( ! empty( $banner['dimensions'] ) ) : ?>
                    <span style="font-size:11px;color:#9ca3af;"><?php echo esc_html( $banner['dimensions'] ); ?></span>
                    <?php endif; ?>
                </div>
                <div style="display:flex;gap:6px;margin-top:auto;padding-top:10px;">
                    <a href="<?php echo esc_url( $banner['file_url'] ); ?>"
                       target="_blank"
                       download
                       class="ltms-btn ltms-btn-primary ltms-btn-sm ltms-mkt-download"
                       data-banner-id="<?php echo esc_attr( $banner['id'] ); ?>"
                       style="flex:1;text-align:center;text-decoration:none;">
                        ⬇ <?php esc_html_e( 'Descargar', 'ltms' ); ?>
                    </a>
                    <?php if ( $is_img ) : ?>
                    <button type="button"
                            class="ltms-btn ltms-btn-outline ltms-btn-sm ltms-mkt-copy-url"
                            data-url="<?php echo esc_attr( $banner['file_url'] ); ?>"
                            title="<?php esc_attr_e( 'Copiar URL', 'ltms' ); ?>">
                        🔗
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Paginación -->
    <?php if ( $total_pages > 1 ) : ?>
    <div style="display:flex;justify-content:center;gap:6px;margin-top:24px;flex-wrap:wrap;">
        <?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
        <button type="button"
                class="ltms-btn ltms-btn-sm <?php echo $i === $page_num ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?> ltms-mkt-page"
                data-page="<?php echo esc_attr( $i ); ?>">
            <?php echo esc_html( $i ); ?>
        </button>
        <?php endfor; ?>
    </div>
    <?php endif; ?>

    <?php endif; ?>

</div>

<?php
// FASE2B P0 FIX (CSP): inline <script> moved to external assets/js/ltms-marketing.js
wp_enqueue_script( 'ltms-marketing', LTMS_ASSETS_URL . 'js/ltms-marketing.js', [ 'jquery' ], LTMS_VERSION, true );
?>
