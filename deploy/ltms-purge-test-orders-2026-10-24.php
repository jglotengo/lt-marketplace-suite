<?php
/**
 * LTMS — Purga de TODOS los pedidos de prueba del marketplace
 *
 * PURGE-TEST-ORDERS-2026-10-24 (operador: "elimina todos los pedidos,
 * todos son pedidos de prueba").
 *
 * Verificado en el inventario previo:
 *  - 38 pedidos 2024-05→2026-06, todos del equipo (test-seller@prueba.com,
 *    vendedor@demo.co, juanguillermo@lotengo.market, asistente de ventas).
 *  - CERO cargos reales de pasarela (0 stripe/openpay charge ids; los 12
 *    _transaction_id son de pasarelas de prueba).
 *  - Derivados 100% QA: comisiones (2, del pedido #14491), movimientos
 *    de billetera ("Crédito QA"/"Hold QA"/retención Ley 1480 del #14491),
 *    26 wallets con saldos heredados.
 *  - Notificaciones NO son de pedidos (kyc_block/posgold_sync) — se conservan.
 *  - HPOS desactivado: bkr_wc_orders tiene 34 filas HUÉRFANAS de una
 *    migración (la verdad vive en posts) — se limpian también.
 *
 * NOTA: WC ya NO trae la herramienta "delete_transactions" en esta
 * versión (eliminada de WC Admin) — por eso este script es AUTOCONTENIDO:
 * borra pedidos + items + notas + meta + HPOS + analítica + derivados LTMS.
 *
 * PRE-REQUISITO OBLIGATORIO (backup completo de la BD — ~87MB):
 *   cd /home/customer/www/lo-tengo.com.co/public_html
 *   mkdir -p ~/backups && wp db export - --allow-root | gzip > ~/backups/ltms-db-backup-$(date +%Y%m%d-%H%M).sql.gz
 *   ls -la ~/backups/   # VERIFICAR que el .gz existe y pesa >5MB antes de seguir
 *
 * Uso (tras el backup):
 *   wp eval-file wp-content/plugins/lt-marketplace-suite/deploy/ltms-purge-test-orders-2026-10-24.php --allow-root
 *
 * Idempotente — seguro correr de nuevo: solo reporta "0" en cada conteo.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 'Ejecutar via WP-CLI eval-file.' );
}

global $wpdb;

echo "=== LTMS — PURGE-TEST-ORDERS-2026-10-24 ===\n";

$pfx = $wpdb->prefix;

/* 1. Recolección de TODOS los pedidos (posts). */
$order_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('shop_order','shop_order_refund','shop_order_placehold')" );
$n_orders  = count( $order_ids );
echo "Pedidos a borrar: $n_orders\n";
if ( $n_orders > 0 ) {
    $ids_csv = implode( ',', array_map( 'intval', $order_ids ) );

    /* 1a. Notas de pedido (comments) + su meta. */
    $n_notes = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type='order_note' AND comment_post_ID IN ($ids_csv)" );
    $wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN (SELECT comment_ID FROM {$wpdb->comments} WHERE comment_type='order_note' AND comment_post_ID IN ($ids_csv))" );
    $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_type='order_note' AND comment_post_ID IN ($ids_csv)" );
    echo "  notas de pedido: $n_notes eliminadas\n";

    /* 1b. Order items + itemmeta. */
    $n_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pfx}woocommerce_order_items" );
    $wpdb->query( "DELETE FROM {$pfx}woocommerce_order_itemmeta" );
    $wpdb->query( "DELETE FROM {$pfx}woocommerce_order_items" );
    echo "  order items: $n_items eliminados\n";

    /* 1c. Postmeta de los pedidos + posts. */
    $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($ids_csv)" );
    $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ($ids_csv)" );
    echo "  posts + meta: $n_orders eliminados\n";
}

/* 2. HPOS huérfano (HPOS desactivado — filas de una migración vieja). */
$n_meta = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pfx}wc_orders_meta" );
$n_hpos = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pfx}wc_orders" );
$wpdb->query( "DELETE FROM {$pfx}wc_orders_meta" );
$wpdb->query( "DELETE FROM {$pfx}wc_orders" );
echo "HPOS huérfano: $n_hpos filas wc_orders + $n_meta wc_orders_meta eliminadas\n";

/* 3. Analítica de WC (lookup tables — quedan huérfanas tras borrar pedidos). */
foreach ( array( 'wc_order_stats', 'wc_order_product_lookup', 'wc_order_coupon_lookup', 'wc_order_tax_lookup', 'wc_order_addresses', 'wc_order_operational_data' ) as $lt ) {
    $table = $pfx . $lt;
    if ( $wpdb->get_var( "SHOW TABLES LIKE '$table'" ) === $table ) {
        $n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
        $wpdb->query( "DELETE FROM `$table`" );
        echo "  $lt: $n eliminadas\n";
    }
}

/* 4. Comisiones de pedidos de prueba. */
$n_comm = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pfx}lt_commissions" );
$wpdb->query( "DELETE FROM {$pfx}lt_commissions" );
echo "Comisiones: $n_comm eliminadas\n";

/* 5. Billetera: movimientos QA fuera + saldos de wallets en 0. */
$n_tx    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pfx}lt_wallet_transactions" );
$n_jr    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pfx}lt_wallet_journal" );
$n_holds = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pfx}lt_wallet_holds" );
$wpdb->query( "DELETE FROM {$pfx}lt_wallet_transactions" );
$wpdb->query( "DELETE FROM {$pfx}lt_wallet_journal" );
$wpdb->query( "DELETE FROM {$pfx}lt_wallet_holds" );
$n_wal = $wpdb->query(
    "UPDATE {$pfx}lt_vendor_wallets
     SET balance=0, balance_pending=0, balance_reserved=0, total_earned=0, total_withdrawn=0, last_transaction=NULL, checksum=NULL, updated_at=NOW()"
);
echo "Billetera: $n_tx transacciones + $n_jr journal + $n_holds holds eliminados; $n_wal wallets con saldo en 0\n";

/* 6. total_sales de productos (inflado por pedidos de prueba completados). */
$n_sales = $wpdb->query( "UPDATE {$wpdb->postmeta} SET meta_value='0' WHERE meta_key='total_sales' AND meta_value<>'0'" );
echo "total_sales de productos: $n_sales reseteados a 0\n";

/* 7. Caches. */
wp_cache_flush();
wc_delete_product_transients();
echo "Object cache + transients de producto limpiados\n";

/* ── Verificación post-purga ───────────────────────────────────────── */
echo "=== Verificación post-purga ===\n";
echo "  pedidos posts:        " . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('shop_order','shop_order_refund','shop_order_placehold')" ) . "\n";
echo "  wc_orders (HPOS):     " . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pfx}wc_orders" ) . "\n";
echo "  order items:          " . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pfx}woocommerce_order_items" ) . "\n";
echo "  order stats (analytics): " . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pfx}wc_order_stats" ) . "\n";
echo "  comisiones:           " . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pfx}lt_commissions" ) . "\n";
echo "  wallet transacciones: " . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pfx}lt_wallet_transactions" ) . "\n";
echo "  wallets con saldo>0: " . (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$pfx}lt_vendor_wallets WHERE balance<>0 OR balance_pending<>0 OR balance_reserved<>0 OR total_earned<>0" ) . "\n";
echo "=== Fin ===\n";
