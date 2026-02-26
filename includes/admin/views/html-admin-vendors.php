<?php
/**
 * Vista: Admin Vendors - Gestión de Vendedores
 *
 * @package LTMS
 * @version 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$page_num = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore
$per_page = 20;
$search   = sanitize_text_field( $_GET['s'] ?? '' ); // phpcs:ignore

$user_query_args = [
    'role__in' => [ 'ltms_vendor', 'ltms_vendor_premium' ],
    'number'   => $per_page,
    'paged'    => $page_num,
    'orderby'  => 'registered',
    'order'    => 'DESC',
];

if ( $search ) {
    $user_query_args['search']         = '*' . $search . '*';
    $user_query_args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
}

$user_query = new WP_User_Query( $user_query_args );
$vendors    = $user_query->get_results();
$total      = $user_query->get_total();
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <h1><?php esc_html_e( 'Vendedores', 'ltms' ); ?></h1>
    </div>

    <!-- Buscador -->
    <form method="get" style="margin-bottom:16px;display:flex;gap:8px;">
        <input type="hidden" name="page" value="ltms-vendors">
        <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>"
               placeholder="<?php esc_attr_e( 'Buscar vendedor...', 'ltms' ); ?>"
               style="padding:8px 12px;border:1px solid #ddd;border-radius:4px;width:280px;">
        <button type="submit" class="ltms-btn ltms-btn-primary ltms-btn-sm">
            🔍 <?php esc_html_e( 'Buscar', 'ltms' ); ?>
        </button>
        <?php if ( $search ) : ?>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-vendors' ) ); ?>" class="ltms-btn ltms-btn-outline ltms-btn-sm">
            ✕ <?php esc_html_e( 'Limpiar', 'ltms' ); ?>
        </a>
        <?php endif; ?>
    </form>

    <div class="ltms-table-wrap">
        <div class="ltms-table-title">
            <?php printf( esc_html__( '%d vendedores registrados', 'ltms' ), $total ); ?>
        </div>
        <table class="ltms-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Vendedor', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Tienda', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Plan', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'KYC', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Billetera', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Registro', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $vendors ) ) : ?>
                <tr><td colspan="7" style="text-align:center;padding:30px;color:#888;">
                    <?php esc_html_e( 'No se encontraron vendedores.', 'ltms' ); ?>
                </td></tr>
                <?php else : ?>
                <?php foreach ( $vendors as $vendor ) :
                    $store_name  = get_user_meta( $vendor->ID, 'ltms_store_name', true ) ?: '—';
                    $kyc_status  = get_user_meta( $vendor->ID, 'ltms_kyc_status', true ) ?: 'pending';
                    $wallet      = LTMS_Wallet::get_or_create( $vendor->ID );
                    $is_premium  = in_array( 'ltms_vendor_premium', $vendor->roles, true );
                    $kyc_classes = [ 'approved' => 'ltms-badge-success', 'pending' => 'ltms-badge-warning', 'rejected' => 'ltms-badge-danger' ];
                ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html( $vendor->display_name ); ?></strong><br>
                        <small style="color:#888;"><?php echo esc_html( $vendor->user_email ); ?></small>
                    </td>
                    <td><?php echo esc_html( $store_name ); ?></td>
                    <td>
                        <span class="ltms-badge <?php echo $is_premium ? 'ltms-badge-primary' : 'ltms-badge-pending'; ?>">
                            <?php echo $is_premium ? esc_html__( 'Premium', 'ltms' ) : esc_html__( 'Básico', 'ltms' ); ?>
                        </span>
                    </td>
                    <td>
                        <span class="ltms-badge <?php echo esc_attr( $kyc_classes[ $kyc_status ] ?? 'ltms-badge-pending' ); ?>">
                            <?php echo esc_html( ucfirst( $kyc_status ) ); ?>
                        </span>
                    </td>
                    <td><strong><?php echo esc_html( LTMS_Utils::format_money( (float) $wallet['balance'] ) ); ?></strong></td>
                    <td><?php echo esc_html( gmdate( 'd/m/Y', strtotime( $vendor->user_registered ) ) ); ?></td>
                    <td>
                        <a href="<?php echo esc_url( get_edit_user_link( $vendor->ID ) ); ?>" class="ltms-btn ltms-btn-outline ltms-btn-sm">
                            ✏️ <?php esc_html_e( 'Editar', 'ltms' ); ?>
                        </a>
                        <?php if ( $wallet['is_frozen'] ) : ?>
                        <button type="button" class="ltms-btn ltms-btn-warning ltms-btn-sm"
                                data-vendor-id="<?php echo esc_attr( $vendor->ID ); ?>"
                                onclick="LTMS.Admin.unfreezeWallet(<?php echo esc_js( $vendor->ID ); ?>)">
                            🔓 <?php esc_html_e( 'Descongelar', 'ltms' ); ?>
                        </button>
                        <?php else : ?>
                        <button type="button" class="ltms-btn ltms-btn-danger ltms-btn-sm ltms-freeze-wallet"
                                data-vendor-id="<?php echo esc_attr( $vendor->ID ); ?>">
                            🔒 <?php esc_html_e( 'Congelar', 'ltms' ); ?>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>
