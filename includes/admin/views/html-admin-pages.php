<?php
/**
 * Vista de Administracion: Paginas del Plugin
 *
 * @package LTMS
 * @version 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'No tienes permiso para acceder a esta pagina.', 'ltms' ) );

if ( class_exists( 'LTMS_Core_Activator' ) ) {
    LTMS_Core_Activator::register_hooks();
}

$required_pages = [
    'ltms-vendor-register' => [ 'title' => __( 'Registro de Vendedor',      'ltms' ), 'slug' => 'registro-vendedor',    'icon' => '📝' ],
    'ltms-dashboard'       => [ 'title' => __( 'Panel del Vendedor',        'ltms' ), 'slug' => 'panel-vendedor',       'icon' => '📊' ],
    'ltms-login'           => [ 'title' => __( 'Iniciar Sesion',            'ltms' ), 'slug' => 'login-vendedor',       'icon' => '🔐' ],
    'ltms-store'           => [ 'title' => __( 'Tienda del Vendedor',       'ltms' ), 'slug' => 'tienda',               'icon' => '🏪' ],
    'ltms-orders'          => [ 'title' => __( 'Mis Pedidos',               'ltms' ), 'slug' => 'mis-pedidos',          'icon' => '📦' ],
    'ltms-wallet'          => [ 'title' => __( 'Mi Billetera',              'ltms' ), 'slug' => 'mi-billetera',         'icon' => '💳' ],
    'ltms-kyc'             => [ 'title' => __( 'Verificacion de Identidad', 'ltms' ), 'slug' => 'verificacion-identidad','icon' => '🪪' ],
    'ltms-insurance'       => [ 'title' => __( 'Mis Seguros',               'ltms' ), 'slug' => 'mis-seguros',          'icon' => '🛡' ],
    'ltms-bookings'        => [ 'title' => __( 'Mis Reservas',              'ltms' ), 'slug' => 'mis-reservas',         'icon' => '🏨' ],
    'ltms-rnt'             => [ 'title' => __( 'RNT / Turismo',             'ltms' ), 'slug' => 'rnt-turismo',          'icon' => '🏔' ],
];

$installed = get_option( 'ltms_installed_pages', [] );
if ( ! is_array( $installed ) ) $installed = [];

$missing_count = 0;
$pages_data    = [];
foreach ( $required_pages as $key => $def ) {
    $page_id  = isset( $installed[ $key ] ) ? absint( $installed[ $key ] ) : 0;
    $post_obj = $page_id ? get_post( $page_id ) : null;
    $exists   = null !== $post_obj;
    if ( ! $exists ) ++$missing_count;
    $pages_data[ $key ] = [ 'def' => $def, 'page_id' => $page_id, 'exists' => $exists ];
}

$total   = count( $required_pages );
$ok      = $total - $missing_count;
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <h1>📄 <?php esc_html_e( 'Paginas del Plugin', 'ltms' ); ?></h1>
    </div>

    <!-- Stats -->
    <div class="ltms-stats-grid" style="margin-bottom:24px;">
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Total paginas', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( $total ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Existentes', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:#16a34a;"><?php echo esc_html( $ok ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Faltantes', 'ltms' ); ?></span>
            <span class="ltms-stat-value" style="color:<?php echo $missing_count > 0 ? '#dc2626' : '#16a34a'; ?>;">
                <?php echo esc_html( $missing_count ); ?>
            </span>
        </div>
    </div>

    <?php if ( ! empty( $_GET['ltms_pages_recreated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
    <div class="notice notice-success is-dismissible">
        <p><strong><?php esc_html_e( 'Paginas recreadas correctamente.', 'ltms' ); ?></strong>
        <?php esc_html_e( 'Las paginas faltantes han sido creadas con exito.', 'ltms' ); ?></p>
    </div>
    <?php endif; ?>

    <?php if ( 0 === $missing_count ) : ?>
    <div class="notice notice-success" style="margin-bottom:16px;">
        <p>✅ <?php esc_html_e( 'Todas las paginas requeridas existen. No hay nada que recrear.', 'ltms' ); ?></p>
    </div>
    <?php else : ?>
    <div class="notice notice-warning" style="margin-bottom:16px;">
        <p>⚠️ <?php printf( esc_html( _n( 'Falta %d pagina requerida del plugin.', 'Faltan %d paginas requeridas del plugin.', $missing_count, 'ltms' ) ), (int) $missing_count ); ?></p>
    </div>
    <?php endif; ?>

    <!-- Tabla -->
    <div class="ltms-table-wrap">
        <div class="ltms-table-title"><?php esc_html_e( 'Estado de paginas', 'ltms' ); ?></div>
        <table class="ltms-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Pagina', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Slug', 'ltms' ); ?></th>
                    <th style="width:70px;"><?php esc_html_e( 'ID', 'ltms' ); ?></th>
                    <th style="width:110px;"><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                    <th style="width:140px;"><?php esc_html_e( 'Accion', 'ltms' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $pages_data as $key => $data ) :
                $def     = $data['def'];
                $page_id = $data['page_id'];
                $exists  = $data['exists'];
            ?>
            <tr>
                <td>
                    <span style="margin-right:6px;"><?php echo esc_html( $def['icon'] ); ?></span>
                    <strong><?php echo esc_html( $def['title'] ); ?></strong>
                </td>
                <td>
                    <code style="font-size:11px;background:#f1f5f9;padding:2px 6px;border-radius:3px;color:#334155;">
                        <?php echo esc_html( $def['slug'] ); ?>
                    </code>
                </td>
                <td style="font-size:12px;color:#6b7280;">
                    <?php echo $exists ? esc_html( (string) $page_id ) : '—'; ?>
                </td>
                <td>
                    <?php if ( $exists ) : ?>
                    <span class="ltms-badge ltms-badge-success">✓ <?php esc_html_e( 'Existe', 'ltms' ); ?></span>
                    <?php else : ?>
                    <span class="ltms-badge ltms-badge-danger">✗ <?php esc_html_e( 'Faltante', 'ltms' ); ?></span>
                    <?php endif; ?>
                </td>
                <td style="display:flex;gap:4px;">
                    <?php if ( $exists ) : ?>
                    <a href="<?php echo esc_url( get_edit_post_link( $page_id ) ); ?>" class="ltms-btn ltms-btn-outline ltms-btn-sm">
                        ✏️ <?php esc_html_e( 'Editar', 'ltms' ); ?>
                    </a>
                    <a href="<?php echo esc_url( get_permalink( $page_id ) ); ?>" class="ltms-btn ltms-btn-outline ltms-btn-sm" target="_blank" rel="noopener noreferrer">
                        👁 <?php esc_html_e( 'Ver', 'ltms' ); ?>
                    </a>
                    <?php else : ?>
                    <span style="font-size:12px;color:#9ca3af;"><?php esc_html_e( 'Se recreara con el boton', 'ltms' ); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Boton recrear -->
    <div style="margin-top:20px;">
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="ltms_recreate_pages">
            <?php wp_nonce_field( 'ltms_recreate_pages' ); ?>
            <button type="submit" class="ltms-btn ltms-btn-primary"
                    <?php echo 0 === $missing_count ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : ''; ?>>
                🔄 <?php esc_html_e( 'Recrear paginas faltantes', 'ltms' ); ?>
            </button>
            <?php if ( 0 === $missing_count ) : ?>
            <span style="margin-left:10px;font-size:0.85rem;color:#9ca3af;">
                <?php esc_html_e( '(No hay paginas faltantes)', 'ltms' ); ?>
            </span>
            <?php endif; ?>
        </form>
        <p style="margin-top:10px;font-size:0.8rem;color:#9ca3af;">
            <?php esc_html_e( 'LTMS crea estas 10 páginas automáticamente al activarse. Si alguna fue borrada, usa el botón para recrearla. Las páginas Mis Reservas y RNT / Turismo se crearon en la versión 2.8.0 — si no aparecen como "Existe", haz clic en Recrear.', 'ltms' ); ?>
        </p>
    </div>

</div>
