<?php
/**
 * Vista: Admin Marketing - Gestión de Banners y MLM
 *
 * @package LTMS
 * @version 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$banners_table = $wpdb->prefix . 'lt_marketing_banners';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$banners = $wpdb->get_results(
    $wpdb->prepare( "SELECT * FROM `{$banners_table}` ORDER BY created_at DESC LIMIT %d", 20 ),
    ARRAY_A
);

$mlm_enabled = LTMS_Core_Config::get( 'ltms_mlm_enabled', 'no' ) === 'yes';
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <h1><?php esc_html_e( 'Marketing', 'ltms' ); ?></h1>
    </div>

    <!-- Red de Referidos / MLM -->
    <div class="ltms-form-section">
        <h2><?php esc_html_e( 'Red de Referidos (MLM)', 'ltms' ); ?>
            <span class="ltms-badge <?php echo $mlm_enabled ? 'ltms-badge-success' : 'ltms-badge-pending'; ?>" style="font-size:0.8rem;margin-left:8px;">
                <?php echo $mlm_enabled ? esc_html__( 'Activo', 'ltms' ) : esc_html__( 'Inactivo', 'ltms' ); ?>
            </span>
        </h2>
        <?php if ( $mlm_enabled ) : ?>
        <?php
        global $wpdb;
        $ref_table = $wpdb->prefix . 'lt_referral_network';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery
        $total_nodes = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$ref_table}`" );
        $avg_depth   = (int) $wpdb->get_var( "SELECT AVG(level) FROM `{$ref_table}`" );
        // phpcs:enable
        ?>
        <div class="ltms-stats-grid">
            <div class="ltms-stat-card">
                <span class="ltms-stat-label"><?php esc_html_e( 'Nodos en la Red', 'ltms' ); ?></span>
                <span class="ltms-stat-value"><?php echo esc_html( number_format( $total_nodes ) ); ?></span>
            </div>
            <div class="ltms-stat-card">
                <span class="ltms-stat-label"><?php esc_html_e( 'Profundidad Promedio', 'ltms' ); ?></span>
                <span class="ltms-stat-value"><?php echo esc_html( $avg_depth ); ?> niveles</span>
            </div>
        </div>
        <?php else : ?>
        <p><?php esc_html_e( 'La red de referidos está desactivada.', 'ltms' ); ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-settings&tab=mlm' ) ); ?>" class="ltms-btn ltms-btn-outline ltms-btn-sm">
            <?php esc_html_e( 'Activar MLM', 'ltms' ); ?>
        </a></p>
        <?php endif; ?>
    </div>

    <!-- Banners -->
    <div class="ltms-form-section">
        <h2><?php esc_html_e( 'Banners Promocionales', 'ltms' ); ?></h2>
        <?php if ( empty( $banners ) ) : ?>
        <p style="color:#888;"><?php esc_html_e( 'No hay banners configurados.', 'ltms' ); ?></p>
        <?php else : ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px;">
            <?php foreach ( $banners as $banner ) : ?>
            <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
                <?php if ( $banner['image_url'] ) : ?>
                <img src="<?php echo esc_url( $banner['image_url'] ); ?>" style="width:100%;height:120px;object-fit:cover;" alt="">
                <?php endif; ?>
                <div style="padding:12px;">
                    <div style="font-weight:600;font-size:0.875rem;"><?php echo esc_html( $banner['title'] ); ?></div>
                    <div style="font-size:0.75rem;color:#888;"><?php echo esc_html( $banner['placement'] ?? 'general' ); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</div>
