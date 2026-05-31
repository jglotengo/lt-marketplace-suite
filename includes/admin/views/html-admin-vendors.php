<?php
/**
 * Vista: Admin Vendors - Gestión de Vendedores
 *
 * @package LTMS
 * @version 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$page_num   = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore
$per_page   = 20;
$search     = sanitize_text_field( $_GET['s'] ?? '' );         // phpcs:ignore
$filter_kyc = sanitize_key( $_GET['kyc'] ?? '' );              // phpcs:ignore  approved|pending|rejected
$filter_plan= sanitize_key( $_GET['plan'] ?? '' );             // phpcs:ignore  basic|premium
$base_url   = admin_url( 'admin.php?page=ltms-vendors' );

$user_query_args = [
    'role__in' => [ 'ltms_vendor', 'ltms_vendor_premium' ],
    'number'   => $per_page,
    'paged'    => $page_num,
    'orderby'  => 'registered',
    'order'    => 'DESC',
];

// Filtro plan
if ( 'premium' === $filter_plan ) {
    $user_query_args['role__in'] = [ 'ltms_vendor_premium' ];
} elseif ( 'basic' === $filter_plan ) {
    $user_query_args['role__in'] = [ 'ltms_vendor' ];
}

// Filtro KYC via meta_query
if ( in_array( $filter_kyc, [ 'approved', 'pending', 'rejected' ], true ) ) {
    $user_query_args['meta_query'] = [ // phpcs:ignore
        [
            'key'     => 'ltms_kyc_status',
            'value'   => $filter_kyc,
            'compare' => '=',
        ],
    ];
}

if ( $search ) {
    $user_query_args['search']         = '*' . $search . '*';
    $user_query_args['search_columns'] = [ 'user_login', 'user_email', 'display_name' ];
}

$user_query = new WP_User_Query( $user_query_args );
$vendors    = $user_query->get_results();
$total      = $user_query->get_total();
$total_pages= (int) ceil( $total / $per_page );

/**
 * Construye URL conservando filtros activos + cambia un parámetro.
 */
function ltms_vendors_url( array $overrides = [] ): string {
    $params = array_filter( [
        'page'  => 'ltms-vendors',
        'paged' => (int) ( $_GET['paged'] ?? 1 ), // phpcs:ignore
        's'     => sanitize_text_field( $_GET['s'] ?? '' ),     // phpcs:ignore
        'kyc'   => sanitize_key( $_GET['kyc'] ?? '' ),          // phpcs:ignore
        'plan'  => sanitize_key( $_GET['plan'] ?? '' ),         // phpcs:ignore
    ] );
    $params = array_merge( $params, $overrides );
    return admin_url( 'admin.php?' . http_build_query( $params ) );
}
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <h1><?php esc_html_e( 'Vendedores', 'ltms' ); ?></h1>
    </div>

    <!-- ── Barra de búsqueda + filtros ── -->
    <form method="get" style="margin-bottom:16px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
        <input type="hidden" name="page" value="ltms-vendors">

        <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>"
               placeholder="<?php esc_attr_e( 'Buscar vendedor...', 'ltms' ); ?>"
               style="padding:8px 12px;border:1px solid #ddd;border-radius:4px;width:240px;">

        <!-- Filtro KYC -->
        <select name="kyc" style="padding:8px;border:1px solid #ddd;border-radius:4px;">
            <option value=""><?php esc_html_e( 'Todos los KYC', 'ltms' ); ?></option>
            <option value="approved"  <?php selected( $filter_kyc, 'approved' ); ?>><?php esc_html_e( '✅ Aprobado', 'ltms' ); ?></option>
            <option value="pending"   <?php selected( $filter_kyc, 'pending' ); ?>><?php esc_html_e( '⏳ Pendiente', 'ltms' ); ?></option>
            <option value="rejected"  <?php selected( $filter_kyc, 'rejected' ); ?>><?php esc_html_e( '❌ Rechazado', 'ltms' ); ?></option>
        </select>

        <!-- Filtro Plan -->
        <select name="plan" style="padding:8px;border:1px solid #ddd;border-radius:4px;">
            <option value=""><?php esc_html_e( 'Todos los planes', 'ltms' ); ?></option>
            <option value="basic"   <?php selected( $filter_plan, 'basic' ); ?>><?php esc_html_e( 'Básico', 'ltms' ); ?></option>
            <option value="premium" <?php selected( $filter_plan, 'premium' ); ?>><?php esc_html_e( 'Premium', 'ltms' ); ?></option>
        </select>

        <button type="submit" class="ltms-btn ltms-btn-primary ltms-btn-sm">
            🔍 <?php esc_html_e( 'Filtrar', 'ltms' ); ?>
        </button>

        <?php if ( $search || $filter_kyc || $filter_plan ) : ?>
        <a href="<?php echo esc_url( $base_url ); ?>" class="ltms-btn ltms-btn-outline ltms-btn-sm">
            ✕ <?php esc_html_e( 'Limpiar filtros', 'ltms' ); ?>
        </a>
        <?php endif; ?>

        <!-- Resumen filtros activos -->
        <?php if ( $filter_kyc || $filter_plan ) : ?>
        <span style="font-size:12px;color:#666;margin-left:4px;">
            <?php
            $active = [];
            if ( $filter_kyc )  $active[] = 'KYC: ' . ucfirst( $filter_kyc );
            if ( $filter_plan ) $active[] = 'Plan: ' . ucfirst( $filter_plan );
            echo esc_html( 'Filtros: ' . implode( ' · ', $active ) );
            ?>
        </span>
        <?php endif; ?>
    </form>

    <div class="ltms-table-wrap">
        <div class="ltms-table-title" style="display:flex;justify-content:space-between;align-items:center;">
            <span>
                <?php printf( esc_html__( '%d vendedores registrados', 'ltms' ), $total ); ?>
                <?php if ( $total_pages > 1 ) : ?>
                — <?php printf( esc_html__( 'Página %1$d de %2$d', 'ltms' ), $page_num, $total_pages ); ?>
                <?php endif; ?>
            </span>
            <!-- Paginación superior (solo si hay más de una página) -->
            <?php if ( $total_pages > 1 ) : ?>
            <span style="display:flex;gap:4px;font-size:13px;">
                <?php if ( $page_num > 1 ) : ?>
                <a href="<?php echo esc_url( ltms_vendors_url( [ 'paged' => $page_num - 1 ] ) ); ?>"
                   class="ltms-btn ltms-btn-outline ltms-btn-sm">‹ <?php esc_html_e( 'Anterior', 'ltms' ); ?></a>
                <?php endif; ?>
                <?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
                    <?php if ( abs( $p - $page_num ) <= 2 || $p === 1 || $p === $total_pages ) : ?>
                    <a href="<?php echo esc_url( ltms_vendors_url( [ 'paged' => $p ] ) ); ?>"
                       class="ltms-btn ltms-btn-sm <?php echo $p === $page_num ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?>"
                       style="min-width:30px;text-align:center;"><?php echo esc_html( $p ); ?></a>
                    <?php elseif ( abs( $p - $page_num ) === 3 ) : ?>
                    <span style="padding:4px 2px;color:#888;">…</span>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ( $page_num < $total_pages ) : ?>
                <a href="<?php echo esc_url( ltms_vendors_url( [ 'paged' => $page_num + 1 ] ) ); ?>"
                   class="ltms-btn ltms-btn-outline ltms-btn-sm"><?php esc_html_e( 'Siguiente', 'ltms' ); ?> ›</a>
                <?php endif; ?>
            </span>
            <?php endif; ?>
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
                    $wallet      = LTMS_Business_Wallet::get_or_create( $vendor->ID );
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
                    <td style="display:flex;flex-wrap:wrap;gap:4px;">
                        <a href="<?php echo esc_url( get_edit_user_link( $vendor->ID ) ); ?>" class="ltms-btn ltms-btn-outline ltms-btn-sm">
                            ✏️ <?php esc_html_e( 'Editar', 'ltms' ); ?>
                        </a>
                        <?php if ( 'pending' === $kyc_status && current_user_can( 'ltms_manage_kyc' ) ) : ?>
                        <button type="button" class="ltms-btn ltms-btn-success ltms-btn-sm"
                                onclick="if(confirm('<?php esc_attr_e( '¿Aprobar KYC de este vendedor?', 'ltms' ); ?>')) LTMS.Admin.ajaxAction('ltms_quick_approve_kyc', {vendor_id: <?php echo esc_js( $vendor->ID ); ?>}, function(r){ if(r.success){ location.reload(); } else { alert(r.data||'Error'); } })">
                            ✅ <?php esc_html_e( 'Aprobar KYC', 'ltms' ); ?>
                        </button>
                        <?php endif; ?>
                        <?php if ( $wallet['is_frozen'] ) : ?>
                        <button type="button" class="ltms-btn ltms-btn-warning ltms-btn-sm"
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

        <!-- ── Paginación inferior ── -->
        <?php if ( $total_pages > 1 ) : ?>
        <div style="display:flex;justify-content:center;align-items:center;gap:6px;padding:16px;flex-wrap:wrap;">
            <?php if ( $page_num > 1 ) : ?>
            <a href="<?php echo esc_url( ltms_vendors_url( [ 'paged' => 1 ] ) ); ?>"
               class="ltms-btn ltms-btn-outline ltms-btn-sm">« <?php esc_html_e( 'Primera', 'ltms' ); ?></a>
            <a href="<?php echo esc_url( ltms_vendors_url( [ 'paged' => $page_num - 1 ] ) ); ?>"
               class="ltms-btn ltms-btn-outline ltms-btn-sm">‹ <?php esc_html_e( 'Anterior', 'ltms' ); ?></a>
            <?php endif; ?>

            <?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
                <?php if ( abs( $p - $page_num ) <= 2 || $p === 1 || $p === $total_pages ) : ?>
                <a href="<?php echo esc_url( ltms_vendors_url( [ 'paged' => $p ] ) ); ?>"
                   class="ltms-btn ltms-btn-sm <?php echo $p === $page_num ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?>"
                   style="min-width:32px;text-align:center;"><?php echo esc_html( $p ); ?></a>
                <?php elseif ( abs( $p - $page_num ) === 3 ) : ?>
                <span style="padding:6px 2px;color:#888;">…</span>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ( $page_num < $total_pages ) : ?>
            <a href="<?php echo esc_url( ltms_vendors_url( [ 'paged' => $page_num + 1 ] ) ); ?>"
               class="ltms-btn ltms-btn-outline ltms-btn-sm"><?php esc_html_e( 'Siguiente', 'ltms' ); ?> ›</a>
            <a href="<?php echo esc_url( ltms_vendors_url( [ 'paged' => $total_pages ] ) ); ?>"
               class="ltms-btn ltms-btn-outline ltms-btn-sm"><?php esc_html_e( 'Última', 'ltms' ); ?> »</a>
            <?php endif; ?>

            <span style="font-size:12px;color:#666;margin-left:8px;">
                <?php printf(
                    esc_html__( 'Mostrando %1$d–%2$d de %3$d vendedores', 'ltms' ),
                    ( ( $page_num - 1 ) * $per_page ) + 1,
                    min( $page_num * $per_page, $total ),
                    $total
                ); ?>
            </span>
        </div>
        <?php endif; ?>
    </div>

</div>
