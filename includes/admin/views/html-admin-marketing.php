<?php
/**
 * Vista: Admin Marketing - Gestión de Banners y MLM
 *
 * @package LTMS
 * @version 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$banners_table = $wpdb->prefix . 'lt_marketing_banners';
$mlm_enabled   = LTMS_Core_Config::get( 'ltms_mlm_enabled', 'no' ) === 'yes';

$type_filter = sanitize_key( $_GET['type'] ?? '' );    // phpcs:ignore
$page_num    = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore
$per_page    = 20;
$offset      = ( $page_num - 1 ) * $per_page;
$base_url    = admin_url( 'admin.php?page=ltms-marketing' );
$nonce       = wp_create_nonce( 'ltms_admin_nonce' );

$valid_types = [ 'banner', 'flyer', 'social_post', 'email_template', 'video' ];
$type_labels = [
    'banner'         => '🖼 Banner',
    'flyer'          => '📄 Flyer',
    'social_post'    => '📱 Social',
    'email_template' => '✉ Email',
    'video'          => '🎬 Video',
];

// phpcs:disable WordPress.DB.DirectDatabaseQuery
$where      = $type_filter && in_array( $type_filter, $valid_types, true )
              ? $wpdb->prepare( 'WHERE type = %s', $type_filter )
              : '';
$total      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$banners_table}` {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$total_pages = max( 1, (int) ceil( $total / $per_page ) );

$banners = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $wpdb->prepare(
        "SELECT * FROM `{$banners_table}` {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $per_page, $offset
    ),
    ARRAY_A
);

// Contadores por tipo
$type_counts_raw = $wpdb->get_results( "SELECT type, COUNT(*) as total FROM `{$banners_table}` GROUP BY type", ARRAY_A );
$type_counts = array_column( $type_counts_raw, 'total', 'type' );

// Descargas totales
$total_downloads = (int) $wpdb->get_var( "SELECT SUM(download_count) FROM `{$banners_table}`" );

// MLM stats si activo
$total_nodes = 0; $avg_depth = 0;
if ( $mlm_enabled ) {
    $ref_table   = $wpdb->prefix . 'lt_referral_network';
    $total_nodes = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$ref_table}`" );
    $avg_depth   = (int) $wpdb->get_var( "SELECT AVG(level) FROM `{$ref_table}`" );
}
// phpcs:enable
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <h1><?php esc_html_e( 'Marketing', 'ltms' ); ?></h1>
    </div>

    <!-- ── Red de Referidos MLM ── -->
    <div class="ltms-form-section" style="margin-bottom:24px;">
        <h2 style="margin-top:0;"><?php esc_html_e( 'Red de Referidos (MLM)', 'ltms' ); ?>
            <span class="ltms-badge <?php echo $mlm_enabled ? 'ltms-badge-success' : 'ltms-badge-pending'; ?>" style="font-size:0.8rem;margin-left:8px;">
                <?php echo $mlm_enabled ? esc_html__( 'Activo', 'ltms' ) : esc_html__( 'Inactivo', 'ltms' ); ?>
            </span>
        </h2>
        <?php if ( $mlm_enabled ) : ?>
        <div class="ltms-stats-grid">
            <div class="ltms-stat-card">
                <span class="ltms-stat-label"><?php esc_html_e( 'Nodos en la Red', 'ltms' ); ?></span>
                <span class="ltms-stat-value"><?php echo esc_html( number_format( $total_nodes ) ); ?></span>
            </div>
            <div class="ltms-stat-card">
                <span class="ltms-stat-label"><?php esc_html_e( 'Profundidad Promedio', 'ltms' ); ?></span>
                <span class="ltms-stat-value"><?php echo esc_html( $avg_depth ); ?> <?php esc_html_e( 'niveles', 'ltms' ); ?></span>
            </div>
        </div>
        <?php else : ?>
        <p style="color:#888;margin:0;">
            <?php esc_html_e( 'La red de referidos está desactivada.', 'ltms' ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-settings&tab=mlm' ) ); ?>" class="ltms-btn ltms-btn-outline ltms-btn-sm" style="margin-left:8px;">
                <?php esc_html_e( 'Activar MLM', 'ltms' ); ?>
            </a>
        </p>
        <?php endif; ?>
    </div>

    <!-- ── Banners Promocionales ── -->
    <div class="ltms-form-section">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
            <h2 style="margin:0;"><?php esc_html_e( 'Banners Promocionales', 'ltms' ); ?></h2>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <span style="font-size:12px;color:#888;">
                    <?php printf( esc_html__( '%d materiales · %d descargas totales', 'ltms' ), $total, $total_downloads ); ?>
                </span>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-settings&tab=mlm' ) ); ?>"
                   class="ltms-btn ltms-btn-primary ltms-btn-sm">
                    + <?php esc_html_e( 'Subir material', 'ltms' ); ?>
                </a>
            </div>
        </div>

        <!-- Filtros por tipo -->
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;">
            <a href="<?php echo esc_url( $base_url ); ?>"
               class="ltms-btn ltms-btn-sm <?php echo ! $type_filter ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?>">
                <?php esc_html_e( 'Todos', 'ltms' ); ?>
                <span style="margin-left:4px;opacity:.7;">(<?php echo esc_html( $total ); ?>)</span>
            </a>
            <?php foreach ( $type_labels as $t => $label ) : ?>
            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'ltms-marketing', 'type' => $t ], admin_url( 'admin.php' ) ) ); ?>"
               class="ltms-btn ltms-btn-sm <?php echo $type_filter === $t ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?>">
                <?php echo esc_html( $label ); ?>
                <span style="margin-left:4px;opacity:.7;">(<?php echo esc_html( $type_counts[ $t ] ?? 0 ); ?>)</span>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if ( empty( $banners ) ) : ?>
        <div style="text-align:center;padding:48px;color:#9ca3af;">
            <div style="font-size:48px;margin-bottom:12px;">🖼</div>
            <p style="margin:0;"><?php esc_html_e( 'No hay banners configurados.', 'ltms' ); ?></p>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-settings&tab=mlm' ) ); ?>"
               class="ltms-btn ltms-btn-primary" style="margin-top:12px;display:inline-block;">
                + <?php esc_html_e( 'Subir primer material', 'ltms' ); ?>
            </a>
        </div>
        <?php else : ?>

        <!-- Grid de banners -->
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;margin-bottom:20px;">
            <?php foreach ( $banners as $banner ) :
                $thumb = $banner['thumbnail_url'] ?: $banner['file_url'];
                $is_img = preg_match( '/\.(jpg|jpeg|png|gif|webp|svg)(\?.*)?$/i', $banner['file_url'] );
            ?>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);">
                <!-- Preview -->
                <?php if ( $thumb && $is_img ) : ?>
                <div style="height:130px;overflow:hidden;background:#f3f4f6;">
                    <img src="<?php echo esc_url( $thumb ); ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
                </div>
                <?php else : ?>
                <div style="height:130px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;font-size:40px;">
                    <?php echo esc_html( $type_labels[ $banner['type'] ][0] ?? '📁' ); ?>
                </div>
                <?php endif; ?>

                <div style="padding:10px 12px;">
                    <div style="font-weight:600;font-size:0.85rem;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?php echo esc_html( $banner['title'] ); ?>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px;">
                        <span style="font-size:11px;color:#6b7280;">
                            <?php echo esc_html( $type_labels[ $banner['type'] ] ?? $banner['type'] ); ?>
                            <?php if ( $banner['dimensions'] ) : ?>
                             · <?php echo esc_html( $banner['dimensions'] ); ?>
                            <?php endif; ?>
                        </span>
                        <span style="font-size:11px;color:#6b7280;">⬇ <?php echo esc_html( number_format( (int) $banner['download_count'] ) ); ?></span>
                    </div>
                    <div style="display:flex;gap:6px;margin-top:8px;">
                        <a href="<?php echo esc_url( $banner['file_url'] ); ?>" target="_blank"
                           class="ltms-btn ltms-btn-outline ltms-btn-sm" style="flex:1;text-align:center;">
                            ⬇ <?php esc_html_e( 'Descargar', 'ltms' ); ?>
                        </a>
                        <span class="ltms-badge <?php echo (int) $banner['is_active'] ? 'ltms-badge-success' : 'ltms-badge-pending'; ?>"
                              style="font-size:10px;padding:2px 6px;align-self:center;">
                            <?php echo (int) $banner['is_active'] ? esc_html__( 'Activo', 'ltms' ) : esc_html__( 'Inactivo', 'ltms' ); ?>
                        </span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Paginación -->
        <?php if ( $total_pages > 1 ) : ?>
        <div style="display:flex;justify-content:center;align-items:center;gap:6px;flex-wrap:wrap;">
            <?php if ( $page_num > 1 ) : ?>
            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'ltms-marketing', 'type' => $type_filter, 'paged' => 1 ], admin_url( 'admin.php' ) ) ); ?>"
               class="ltms-btn ltms-btn-outline ltms-btn-sm">« <?php esc_html_e( 'Primera', 'ltms' ); ?></a>
            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'ltms-marketing', 'type' => $type_filter, 'paged' => $page_num - 1 ], admin_url( 'admin.php' ) ) ); ?>"
               class="ltms-btn ltms-btn-outline ltms-btn-sm">‹ <?php esc_html_e( 'Anterior', 'ltms' ); ?></a>
            <?php endif; ?>
            <?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
                <?php if ( abs( $p - $page_num ) <= 2 || $p === 1 || $p === $total_pages ) : ?>
                <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'ltms-marketing', 'type' => $type_filter, 'paged' => $p ], admin_url( 'admin.php' ) ) ); ?>"
                   class="ltms-btn ltms-btn-sm <?php echo $p === $page_num ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?>"
                   style="min-width:32px;text-align:center;"><?php echo esc_html( $p ); ?></a>
                <?php elseif ( abs( $p - $page_num ) === 3 ) : ?>
                <span style="padding:6px 2px;color:#888;">…</span>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ( $page_num < $total_pages ) : ?>
            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'ltms-marketing', 'type' => $type_filter, 'paged' => $page_num + 1 ], admin_url( 'admin.php' ) ) ); ?>"
               class="ltms-btn ltms-btn-outline ltms-btn-sm"><?php esc_html_e( 'Siguiente', 'ltms' ); ?> ›</a>
            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'ltms-marketing', 'type' => $type_filter, 'paged' => $total_pages ], admin_url( 'admin.php' ) ) ); ?>"
               class="ltms-btn ltms-btn-outline ltms-btn-sm"><?php esc_html_e( 'Última', 'ltms' ); ?> »</a>
            <?php endif; ?>
            <span style="font-size:12px;color:#666;margin-left:8px;">
                <?php printf(
                    esc_html__( 'Mostrando %1$d–%2$d de %3$d', 'ltms' ),
                    ( ( $page_num - 1 ) * $per_page ) + 1,
                    min( $page_num * $per_page, $total ),
                    $total
                ); ?>
            </span>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div><!-- .ltms-form-section -->

</div><!-- .wrap -->
