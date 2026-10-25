<?php
/**
 * LTMS — Purga de TODOS los pedidos de prueba del marketplace
 *
 * PURGE-TEST-ORDERS-2026-10-24 (operador: "elimina todos los pedidos,
 * todos son pedidos de prueba").
 *
 * Verificado en el inventario previo (37 filas de evidencia):
 *  - 38 pedidos 2024-05→2026-06, todos del equipo (test-seller@prueba.com,
 *    vendedor@demo.co, juanguillermo@lotengo.market, asistente de ventas).
 *  - CERO cargos reales de pasarela (0 stripe/openpay charge ids; los 12
 *    _transaction_id son de pasarelas de prueba).
 *  - Derivados 100% QA: comisiones (2, del pedido #14491), movimientos
 *    de billetera ("Crédito QA"/"Hold QA"/retención Ley 1480 del pedido
 *    #14491), 26 wallets con saldos heredados.
 *  - Notificaciones NO son de pedidos (kyc_block/posgold_sync) — se conservan.
 *  - HPOS desactivado: bkr_wc_orders tiene 34 filas HUÉRFANAS de una
 *    migración (la verdad vive en posts) — se limpian también.
 *
 * PRE-REQUISITO OBLIGATORIO (backup completo de la BD — 86.6 MB):
 *   cd /home/customer/www/lo-tengo.com.co/public_html
 *   mkdir -p ~/backups && wp db export - --allow-root | gzip > ~/backups/ltms-db-backup-$(date +%Y%m%d-%H%M).sql.gz
 *   ls -la ~/backups/   # VERIFICAR que el .gz existe y pesa >5MB antes de seguir
 *
 * PASO 1 (WC oficial — borra pedidos + items + notas + logs de WC):
 *   wp wc tool run delete_transactions --user=1 --allow-root
 *
 * PASO 2 (este script — limpia los residuos que la herramienta de WC no toca):
 *   wp eval-file wp-content/plugins/lt-marketplace-suite/deploy/ltms-purge-test-orders-2026-10-24.php --allow-root
 *
 * Residuos que limpia PASO 2:
 *  1. Filas huérfanas de HPOS (bkr_wc_orders + bkr_wc_orders_meta).
 *  2. Comisiones de pedidos (bkr_lt_commissions).
 *  3. Movimientos de billetera (transactions/journal/holds — todo QA).
 *  4. Saldos de las 26 wallets de vendedor → 0 (regenerables por el
 *     sistema limpio; las filas se conservan para no romper referencias).
 *  5. total_sales de productos (inflado por los 13 pedidos completados).
 *
 * Idempotente — seguro correr de nuevo: solo reporta "0" en cada conteo.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 'Ejecutar via WP-CLI eval-file.' );
}

global $wpdb;

echo "=== LTMS — PURGA-TEST-ORDERS-2026-10-24 (paso 2: residuos) ===\n";

$report = array();

/* 0. Estado de entrada: los pedidos en posts deben haber sido borrados por el PASO 1. */
$posts_orders = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('shop_order','shop_order_refund','shop_order_placehold')" );
$report['pedidos_en_posts'] = $posts_orders;
if ( $posts_orders > 0 ) {
    echo "ATENCIÓN: aún hay $posts_orders pedidos en posts — corre primero el PASO 1 (wp wc tool run delete_transactions). ABORTADO.\n";
    return;
}
echo "OK — pedidos en posts: 0 (PASO 1 completado)\n";

/* 1. Residuos HPOS (huérfanos — HPOS está desactivado). */
$n_meta  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders_meta" );
$n_hpos  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}wc_orders_meta" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}wc_orders" );
echo "OK — HPOS huérfanos: $n_hpos filas de wc_orders + $n_meta de wc_orders_meta eliminadas\n";

/* 2. Order items residuales (si el PASO 1 dejó algo). */
$n_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_items" );
if ( $n_items > 0 ) {
    $wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_itemmeta" );
    $wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_items" );
}
echo "OK — order items residuales: $n_items eliminados\n";

/* 3. Comisiones de pedidos de prueba. */
$n_comm = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lt_commissions" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}lt_commissions" );
echo "OK — comisiones: $n_comm eliminadas\n";

/* 4. Billetera: movimientos QA a cero + saldos de wallets en 0. */
$n_tx    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lt_wallet_transactions" );
$n_jr    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lt_wallet_journal" );
$n_holds = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lt_wallet_holds" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}lt_wallet_transactions" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}lt_wallet_journal" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}lt_wallet_holds" );
$n_wal = $wpdb->query(
    "UPDATE {$wpdb->prefix}lt_vendor_wallets
     SET balance=0, balance_pending=0, balance_reserved=0, total_earned=0, total_withdrawn=0, last_transaction=NULL, checksum=NULL, updated_at=NOW()"
);
echo "OK — billetera: $n_tx transacciones + $n_jr journal + $n_holds holds eliminados; $n_wal wallets con saldo en 0\n";

/* 5. total_sales de productos (inflado por pedidos de prueba completados). */
$n_sales = $wpdb->query( "UPDATE {$wpdb->postmeta} SET meta_value='0' WHERE meta_key='total_sales' AND meta_value<>'0'" );
echo "OK — total_sales de productos: $n_sales reseteados a 0\n";

/* 6. Caches. */
wp_cache_flush();
wc_delete_product_transients();
echo "OK — object cache + transients de producto limpiados\n";

/* Reporte final. */
echo "=== Verificación post-purga ===\n";
echo "  pedidos posts:      " . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('shop_order','shop_order_refund','shop_order_placehold')" ) . "\n";
echo "  wc_orders (HPOS):   " . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders" ) . "\n";
echo "  order items:        " . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_order_items" ) . "\n";
echo "  comisiones:         " . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lt_commissions" ) . "\n";
echo "  wallet transacciones: " . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lt_wallet_transactions" ) . "\n";
echo "  wallets con saldo>0: " . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}lt_vendor_wallets WHERE balance<>0 OR balance_pending<>0 OR balance_reserved<>0 OR total_earned<>0" ) . "\n";
echo "=== Fin ===\n";
