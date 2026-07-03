<?php
/**
 * Vista: Admin Wallets - Billeteras de Vendedores
 *
 * @package LTMS
 * @version 1.6.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$table    = $wpdb->prefix . 'lt_vendor_wallets';
$page_num = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore
$per_page = 25;
$offset   = ( $page_num - 1 ) * $per_page;
$search   = sanitize_text_field( $_GET['s'] ?? '' );  // phpcs:ignore
$base_url = admin_url( 'admin.php?page=ltms-wallets' );

// ── Conteo total ──────────────────────────────────────────────────────────
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$where = '';
$where_args = [];
if ( $search ) {
    $where = 'WHERE u.display_name LIKE %s OR u.user_email LIKE %s';
    $like  = '%' . $wpdb->esc_like( $search ) . '%';
    $where_args = [ $like, $like ];
}

$total = (int) $wpdb->get_var(
    $where
        ? $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` w LEFT JOIN `{$wpdb->users}` u ON u.ID = w.vendor_id {$where}", ...$where_args )
        : "SELECT COUNT(*) FROM `{$table}` w LEFT JOIN `{$wpdb->users}` u ON u.ID = w.vendor_id"
);
$total_pages = (int) ceil( $total / $per_page );

// ── Query paginada ─────────────────────────────────────────────────────────
$base_query = "SELECT w.*, u.display_name, u.user_email FROM `{$table}` w LEFT JOIN `{$wpdb->users}` u ON u.ID = w.vendor_id {$where} ORDER BY w.balance DESC LIMIT %d OFFSET %d";
$query_args = array_merge( $where_args, [ $per_page, $offset ] );
$wallets = $wpdb->get_results(
    $wpdb->prepare( $base_query, ...$query_args ),
    ARRAY_A
);

$total_balance = (float) $wpdb->get_var( "SELECT SUM(balance) FROM `{$table}`" );
$total_held    = (float) $wpdb->get_var( "SELECT SUM(balance_pending) FROM `{$table}`" );
// phpcs:enable

// ── URL helper ────────────────────────────────────────────────────────────
function ltms_wallets_url( array $overrides = [] ): string {
    $params = array_filter( [
        'page'  => 'ltms-wallets',
        'paged' => (int) ( $_GET['paged'] ?? 1 ), // phpcs:ignore
        's'     => sanitize_text_field( $_GET['s'] ?? '' ), // phpcs:ignore
    ] );
    $params = array_merge( $params, $overrides );
    return admin_url( 'admin.php?' . http_build_query( $params ) );
}
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <h1><?php esc_html_e( 'Billeteras de Vendedores', 'ltms' ); ?></h1>
    </div>

    <!-- Resumen total -->
    <div class="ltms-stats-grid" style="margin-bottom:24px;">
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Total en Billeteras', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( LTMS_Utils::format_money( $total_balance ) ); ?></span>
        </div>
        <div class="ltms-stat-card">
            <span class="ltms-stat-label"><?php esc_html_e( 'Total en Tránsito', 'ltms' ); ?></span>
            <span class="ltms-stat-value"><?php echo esc_html( LTMS_Utils::format_money( $total_held ) ); ?></span>
        </div>
    </div>

    <!-- ── Barra de búsqueda ── -->
    <form method="get" style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <input type="hidden" name="page" value="ltms-wallets">
        <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>"
               placeholder="<?php esc_attr_e( 'Buscar vendedor...', 'ltms' ); ?>"
               style="padding:8px 12px;border:1px solid #ddd;border-radius:4px;width:280px;">
        <button type="submit" class="ltms-btn ltms-btn-primary ltms-btn-sm">
            🔍 <?php esc_html_e( 'Buscar', 'ltms' ); ?>
        </button>
        <?php if ( $search ) : ?>
        <a href="<?php echo esc_url( $base_url ); ?>" class="ltms-btn ltms-btn-outline ltms-btn-sm">
            ✕ <?php esc_html_e( 'Limpiar', 'ltms' ); ?>
        </a>
        <?php endif; ?>
    </form>

    <div class="ltms-table-wrap">

        <!-- ── Cabecera con total + paginación superior ── -->
        <div class="ltms-table-title" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <span>
                <?php printf( esc_html__( '%d billeteras', 'ltms' ), $total ); ?>
                <?php if ( $total_pages > 1 ) : ?>
                — <?php printf( esc_html__( 'Página %1$d de %2$d', 'ltms' ), $page_num, $total_pages ); ?>
                <?php endif; ?>
            </span>

            <?php if ( $total_pages > 1 ) : ?>
            <span style="display:flex;gap:4px;flex-wrap:wrap;">
                <?php if ( $page_num > 1 ) : ?>
                <a href="<?php echo esc_url( ltms_wallets_url( [ 'paged' => $page_num - 1 ] ) ); ?>"
                   class="ltms-btn ltms-btn-outline ltms-btn-sm">‹ <?php esc_html_e( 'Anterior', 'ltms' ); ?></a>
                <?php endif; ?>
                <?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
                    <?php if ( abs( $p - $page_num ) <= 2 || $p === 1 || $p === $total_pages ) : ?>
                    <a href="<?php echo esc_url( ltms_wallets_url( [ 'paged' => $p ] ) ); ?>"
                       class="ltms-btn ltms-btn-sm <?php echo $p === $page_num ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?>"
                       style="min-width:30px;text-align:center;"><?php echo esc_html( $p ); ?></a>
                    <?php elseif ( abs( $p - $page_num ) === 3 ) : ?>
                    <span style="padding:4px 2px;color:#888;">…</span>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ( $page_num < $total_pages ) : ?>
                <a href="<?php echo esc_url( ltms_wallets_url( [ 'paged' => $page_num + 1 ] ) ); ?>"
                   class="ltms-btn ltms-btn-outline ltms-btn-sm"><?php esc_html_e( 'Siguiente', 'ltms' ); ?> ›</a>
                <?php endif; ?>
            </span>
            <?php endif; ?>
        </div>

        <table class="ltms-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Vendedor', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Balance', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'En Tránsito', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Disponible', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Acciones', 'ltms' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $wallets ) ) : ?>
                <tr><td colspan="6" style="text-align:center;padding:30px;color:#888;">
                    <?php esc_html_e( 'No hay billeteras registradas.', 'ltms' ); ?>
                </td></tr>
                <?php else : ?>
                <?php foreach ( $wallets as $wallet ) :
                    $held      = (float) ( $wallet['balance_pending'] ?? $wallet['balance_reserved'] ?? 0 );
                    $available = (float) $wallet['balance'] - $held;
                    $is_frozen = (int) $wallet['is_frozen'];
                ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html( $wallet['display_name'] ?: '—' ); ?></strong><br>
                        <small style="color:#888;"><?php echo esc_html( $wallet['user_email'] ); ?></small>
                    </td>
                    <td><strong><?php echo esc_html( LTMS_Utils::format_money( (float) $wallet['balance'] ) ); ?></strong></td>
                    <td><?php echo esc_html( LTMS_Utils::format_money( $held ) ); ?></td>
                    <td><?php echo esc_html( LTMS_Utils::format_money( $available ) ); ?></td>
                    <td>
                        <?php if ( $is_frozen ) : ?>
                        <span class="ltms-badge ltms-badge-danger"><?php esc_html_e( 'Congelada', 'ltms' ); ?></span>
                        <?php else : ?>
                        <span class="ltms-badge ltms-badge-success"><?php esc_html_e( 'Activa', 'ltms' ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( $is_frozen ) : ?>
                        <button type="button" class="ltms-btn ltms-btn-success ltms-btn-sm"
                                onclick="if(confirm('<?php esc_attr_e( '¿Descongelar esta billetera?', 'ltms' ); ?>')) LTMS.Admin.ajaxAction('ltms_unfreeze_wallet',{vendor_id:<?php echo esc_js( $wallet['vendor_id'] ); ?>},function(r){if(r.success)location.reload();else alert(r.data||'Error');})">
                            🔓 <?php esc_html_e( 'Descongelar', 'ltms' ); ?>
                        </button>
                        <?php else : ?>
                        <button type="button" class="ltms-btn ltms-btn-danger ltms-btn-sm ltms-freeze-wallet"
                                data-vendor-id="<?php echo esc_attr( $wallet['vendor_id'] ); ?>">
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
            <a href="<?php echo esc_url( ltms_wallets_url( [ 'paged' => 1 ] ) ); ?>"
               class="ltms-btn ltms-btn-outline ltms-btn-sm">« <?php esc_html_e( 'Primera', 'ltms' ); ?></a>
            <a href="<?php echo esc_url( ltms_wallets_url( [ 'paged' => $page_num - 1 ] ) ); ?>"
               class="ltms-btn ltms-btn-outline ltms-btn-sm">‹ <?php esc_html_e( 'Anterior', 'ltms' ); ?></a>
            <?php endif; ?>
            <?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
                <?php if ( abs( $p - $page_num ) <= 2 || $p === 1 || $p === $total_pages ) : ?>
                <a href="<?php echo esc_url( ltms_wallets_url( [ 'paged' => $p ] ) ); ?>"
                   class="ltms-btn ltms-btn-sm <?php echo $p === $page_num ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?>"
                   style="min-width:32px;text-align:center;"><?php echo esc_html( $p ); ?></a>
                <?php elseif ( abs( $p - $page_num ) === 3 ) : ?>
                <span style="padding:6px 2px;color:#888;">…</span>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ( $page_num < $total_pages ) : ?>
            <a href="<?php echo esc_url( ltms_wallets_url( [ 'paged' => $page_num + 1 ] ) ); ?>"
               class="ltms-btn ltms-btn-outline ltms-btn-sm"><?php esc_html_e( 'Siguiente', 'ltms' ); ?> ›</a>
            <a href="<?php echo esc_url( ltms_wallets_url( [ 'paged' => $total_pages ] ) ); ?>"
               class="ltms-btn ltms-btn-outline ltms-btn-sm"><?php esc_html_e( 'Última', 'ltms' ); ?> »</a>
            <?php endif; ?>
            <span style="font-size:12px;color:#666;margin-left:8px;">
                <?php printf(
                    esc_html__( 'Mostrando %1$d–%2$d de %3$d billeteras', 'ltms' ),
                    ( ( $page_num - 1 ) * $per_page ) + 1,
                    min( $page_num * $per_page, $total ),
                    $total
                ); ?>
            </span>
        </div>
        <?php endif; ?>

    </div><!-- .ltms-table-wrap -->

</div><!-- .wrap -->
