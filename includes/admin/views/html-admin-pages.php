<?php
/**
 * Vista de Administración: Páginas del Plugin
 *
 * Muestra el estado de todas las páginas requeridas por LTMS y permite
 * recrear las que hayan sido eliminadas accidentalmente.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/admin/views
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'ltms' ) );
}

// Registrar el handler de admin-post si no se hizo desde el bootstrap.
// (Seguro llamarlo múltiples veces — WordPress ignora duplicados dentro de la misma petición.)
if ( class_exists( 'LTMS_Core_Activator' ) ) {
    LTMS_Core_Activator::register_hooks();
}

// ── Definición canónica de las 8 páginas requeridas ──────────────────────────
$required_pages = [
    'ltms-vendor-register' => [
        'title' => __( 'Registro de Vendedor', 'ltms' ),
        'slug'  => 'registro-vendedor',
    ],
    'ltms-dashboard'       => [
        'title' => __( 'Panel del Vendedor', 'ltms' ),
        'slug'  => 'panel-vendedor',
    ],
    'ltms-login'           => [
        'title' => __( 'Iniciar Sesión', 'ltms' ),
        'slug'  => 'login-vendedor',
    ],
    'ltms-store'           => [
        'title' => __( 'Tienda del Vendedor', 'ltms' ),
        'slug'  => 'tienda',
    ],
    'ltms-orders'          => [
        'title' => __( 'Mis Pedidos', 'ltms' ),
        'slug'  => 'mis-pedidos',
    ],
    'ltms-wallet'          => [
        'title' => __( 'Mi Billetera', 'ltms' ),
        'slug'  => 'mi-billetera',
    ],
    'ltms-kyc'             => [
        'title' => __( 'Verificación de Identidad', 'ltms' ),
        'slug'  => 'verificacion-identidad',
    ],
    'ltms-insurance'       => [
        'title' => __( 'Mis Seguros', 'ltms' ),
        'slug'  => 'mis-seguros',
    ],
];

// ltms_installed_pages es un array asociativo: page_key => page_id.
$installed = get_option( 'ltms_installed_pages', [] );
if ( ! is_array( $installed ) ) {
    $installed = [];
}

// Contar páginas faltantes para el botón.
$missing_count = 0;
foreach ( $required_pages as $key => $_ ) {
    $page_id = isset( $installed[ $key ] ) ? absint( $installed[ $key ] ) : 0;
    if ( ! $page_id || ! get_post( $page_id ) ) {
        ++$missing_count;
    }
}
?>
<div class="wrap">

    <h1><?php esc_html_e( 'Páginas del Plugin', 'ltms' ); ?></h1>

    <p>
        <?php esc_html_e( 'LTMS crea estas páginas automáticamente al activarse. Si alguna fue borrada, usa el botón para recrearla.', 'ltms' ); ?>
    </p>

    <?php // ── Aviso de éxito tras recreación ────────────────────────────────── ?>
    <?php if ( ! empty( $_GET['ltms_pages_recreated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
    <div class="notice notice-success is-dismissible">
        <p>
            <strong><?php esc_html_e( 'Paginas recreadas correctamente.', 'ltms' ); ?></strong>
            <?php esc_html_e( 'Las páginas faltantes han sido creadas con éxito.', 'ltms' ); ?>
        </p>
    </div>
    <?php endif; ?>

    <?php if ( 0 === $missing_count ) : ?>
    <div class="notice notice-success">
        <p><?php esc_html_e( 'Todas las páginas requeridas existen. No hay nada que recrear.', 'ltms' ); ?></p>
    </div>
    <?php else : ?>
    <div class="notice notice-warning">
        <p>
            <?php
            printf(
                /* translators: %d: número de páginas faltantes */
                esc_html( _n(
                    'Falta %d página requerida del plugin.',
                    'Faltan %d páginas requeridas del plugin.',
                    $missing_count,
                    'ltms'
                ) ),
                (int) $missing_count
            );
            ?>
        </p>
    </div>
    <?php endif; ?>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col" style="width:24%"><?php esc_html_e( 'Página', 'ltms' ); ?></th>
                <th scope="col" style="width:20%"><?php esc_html_e( 'Slug', 'ltms' ); ?></th>
                <th scope="col" style="width:8%"><?php esc_html_e( 'ID', 'ltms' ); ?></th>
                <th scope="col" style="width:18%"><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                <th scope="col"><?php esc_html_e( 'Acción', 'ltms' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $required_pages as $key => $page_def ) : ?>
                <?php
                $page_id   = isset( $installed[ $key ] ) ? absint( $installed[ $key ] ) : 0;
                $post_obj  = $page_id ? get_post( $page_id ) : null;
                $exists    = null !== $post_obj;
                ?>
                <tr>
                    <td><strong><?php echo esc_html( $page_def['title'] ); ?></strong></td>
                    <td><code><?php echo esc_html( $page_def['slug'] ); ?></code></td>
                    <td><?php echo $exists ? esc_html( (string) $page_id ) : '—'; ?></td>
                    <td>
                        <?php if ( $exists ) : ?>
                            <span style="color:#27ae60;font-weight:600;">&#10003; <?php esc_html_e( 'Existe', 'ltms' ); ?></span>
                        <?php else : ?>
                            <span style="color:#c0392b;font-weight:600;">&#10007; <?php esc_html_e( 'Faltante', 'ltms' ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( $exists ) : ?>
                            <a href="<?php echo esc_url( get_edit_post_link( $page_id ) ); ?>" class="button button-small">
                                <?php esc_html_e( 'Editar', 'ltms' ); ?>
                            </a>
                            <a href="<?php echo esc_url( get_permalink( $page_id ) ); ?>" class="button button-small" target="_blank" rel="noopener noreferrer">
                                <?php esc_html_e( 'Ver', 'ltms' ); ?>
                            </a>
                        <?php else : ?>
                            <span class="description"><?php esc_html_e( 'Se recreará con el botón', 'ltms' ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <br>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="ltms_recreate_pages">
        <?php wp_nonce_field( 'ltms_recreate_pages' ); ?>
        <p class="submit">
            <input
                type="submit"
                class="button button-primary"
                value="<?php esc_attr_e( 'Recrear páginas faltantes', 'ltms' ); ?>"
                <?php disabled( 0, $missing_count ); ?>
            >
            <?php if ( 0 === $missing_count ) : ?>
            <span class="description" style="margin-left:8px;">
                <?php esc_html_e( '(No hay páginas faltantes)', 'ltms' ); ?>
            </span>
            <?php endif; ?>
        </p>
    </form>

</div>
