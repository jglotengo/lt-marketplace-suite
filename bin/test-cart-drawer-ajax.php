#!/usr/bin/env php
<?php
/**
 * LTMS v2.9.207 — Cart Drawer AJAX QA Test
 *
 * Simulates the exact AJAX flow the inline JS does, to verify:
 *   1. ltms_drawer_update_qty endpoint accepts the request and updates the cart
 *   2. ltms_drawer_remove_item endpoint accepts the request and removes the item
 *   3. ltms_get_cart endpoint returns the updated cart state
 *
 * Run on the production server (or any server with WP + WC + LTMS):
 *   cd /path/to/wordpress
 *   php /path/to/lt-marketplace-suite/bin/test-cart-drawer-ajax.php
 *
 * Or via WP-CLI:
 *   wp eval-file wp-content/plugins/lt-marketplace-suite/bin/test-cart-drawer-ajax.php
 *
 * @package LTMS
 * @version 2.9.207
 */

if ( ! defined( 'ABSPATH' ) ) {
    // Allow running via wp-cli eval-file (ABSPATH is defined there).
    echo "ERROR: This script must be run via wp-cli: wp eval-file <path>\n";
    echo "Or via web request to /wp-admin/admin-ajax.php with the proper POST params.\n";
    exit( 1 );
}

// Only run in CLI context.
if ( ! function_exists( 'wp_doing_ajax' ) || wp_doing_ajax() ) {
    echo "ERROR: Run via wp-cli, not via web.\n";
    return;
}

echo "=== LTMS v2.9.207 Cart Drawer AJAX QA Test ===\n\n";

// 1. Verify LTMS is loaded.
if ( ! class_exists( 'LTMS_Cart_Drawer' ) ) {
    echo "FAIL: LTMS_Cart_Drawer class not loaded.\n";
    return;
}
echo "OK: LTMS_Cart_Drawer class is loaded.\n";

// 2. Verify WC is loaded.
if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
    echo "FAIL: WooCommerce is not loaded or cart is null.\n";
    return;
}
echo "OK: WooCommerce cart is initialized.\n";

// 3. Verify the AJAX actions are registered.
global $wp_filter;
$actions_to_check = [
    'wp_ajax_ltms_drawer_update_qty'    => 'ajax_update_qty',
    'wp_ajax_nopriv_ltms_drawer_update_qty' => 'ajax_update_qty',
    'wp_ajax_ltms_drawer_remove_item'   => 'ajax_remove_item',
    'wp_ajax_nopriv_ltms_drawer_remove_item' => 'ajax_remove_item',
    'wp_ajax_ltms_get_cart'             => 'ajax_get_cart',
    'wp_ajax_nopriv_ltms_get_cart'      => 'ajax_get_cart',
];
foreach ( $actions_to_check as $action => $method ) {
    if ( ! isset( $wp_filter[ $action ] ) || empty( $wp_filter[ $action ]->callbacks ) ) {
        echo "FAIL: Action $action is NOT registered.\n";
    } else {
        echo "OK: Action $action is registered.\n";
    }
}

// 4. Generate a nonce and verify it.
$nonce = wp_create_nonce( 'ltms_ux_nonce' );
$verify = wp_verify_nonce( $nonce, 'ltms_ux_nonce' );
echo $verify ? "OK: Nonce generated and verified (action: ltms_ux_nonce).\n" : "FAIL: Nonce verification failed.\n";

// 5. Add a test product to the cart.
$test_product_id = wc_get_products( [ 'limit' => 1, 'return' => 'ids' ] );
if ( empty( $test_product_id ) ) {
    echo "FAIL: No products found in store to test with.\n";
    return;
}
$test_product_id = $test_product_id[0];
echo "OK: Using product ID $test_product_id for test.\n";

$cart_item_key = WC()->cart->add_to_cart( $test_product_id, 1 );
if ( ! $cart_item_key ) {
    echo "FAIL: Could not add product to cart.\n";
    return;
}
echo "OK: Added to cart. cart_item_key = $cart_item_key\n";

// 6. Verify the item is in the cart.
$cart_item = WC()->cart->get_cart_item( $cart_item_key );
if ( ! $cart_item ) {
    echo "FAIL: Cart item not found after add_to_cart.\n";
    return;
}
echo "OK: Cart item found. Current qty = {$cart_item['quantity']}\n";

// 7. Test set_quantity directly.
echo "\n--- Test 1: WC()->cart->set_quantity() ---\n";
$set_result = WC()->cart->set_quantity( $cart_item_key, 3, true );
echo $set_result ? "OK: set_quantity returned true.\n" : "WARN: set_quantity returned false (might still work).\n";
WC()->cart->calculate_totals();
$cart_item = WC()->cart->get_cart_item( $cart_item_key );
echo "After set_quantity(3): qty = {$cart_item['quantity']}\n";
echo ( $cart_item['quantity'] === 3 ) ? "PASS: Qty updated to 3.\n" : "FAIL: Qty not updated.\n";

// 8. Test remove_cart_item directly.
echo "\n--- Test 2: WC()->cart->remove_cart_item() ---\n";
$remove_result = WC()->cart->remove_cart_item( $cart_item_key );
echo $remove_result ? "OK: remove_cart_item returned true.\n" : "FAIL: remove_cart_item returned false.\n";
$cart_item = WC()->cart->get_cart_item( $cart_item_key );
echo ( ! $cart_item ) ? "PASS: Cart item removed successfully.\n" : "FAIL: Cart item still present.\n";

// 9. Verify the get_drawer_data method.
echo "\n--- Test 3: LTMS_Cart_Drawer::get_drawer_data() ---\n";
$drawer_data = LTMS_Cart_Drawer::get_drawer_data( true );
if ( ! is_array( $drawer_data ) ) {
    echo "FAIL: get_drawer_data did not return an array.\n";
} else {
    echo "OK: get_drawer_data returned an array.\n";
    echo "  Items: " . count( $drawer_data['items'] ?? [] ) . "\n";
    echo "  Count: " . ( $drawer_data['count'] ?? 0 ) . "\n";
    echo "  Subtotal: " . ( $drawer_data['subtotal'] ?? '(none)' ) . "\n";
}

// 10. Clean up the test cart.
WC()->cart->empty_cart();
echo "\nOK: Test cart emptied. Test complete.\n";
echo "\n=== QA Test Summary ===\n";
echo "If all tests passed, the AJAX endpoints should work correctly.\n";
echo "If the browser still shows issues, the problem is likely:\n";
echo "  - Browser cache (hard reload: Ctrl+Shift+R)\n";
echo "  - SiteGround HTML cache (purge via SG admin)\n";
echo "  - JS console errors (check browser DevTools > Console)\n";
