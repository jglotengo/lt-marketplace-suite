<?php
/**
 * Migración de vendedores específicos al nuevo proyecto.
 *
 * Ejecutar en producción vía WP-CLI:
 *   wp eval-file /home/customer/www/lo-tengo.com.co/public_html/wp-content/plugins/lt-marketplace-suite/scripts/migrate-vendors.php --allow-root --path=/home/customer/www/lo-tengo.com.co/public_html
 *
 * Vendedores a migrar:
 *   1. Katherin Caro Moreno — macrabuaccesorios@gmail.com
 *   2. Jugueteria Taiwan — jugueteriataiwan27@gmail.com
 *
 * @package LTMS
 * @version 2.9.31
 */

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/home/customer/www/lo-tengo.com.co/public_html/' );
}

// Cargar WordPress.
require_once ABSPATH . 'wp-load.php';

echo "=== Migración de Vendedores v2.9.31 ===\n\n";

$vendors_to_migrate = [
    [
        'display_name'  => 'Katherin Caro Moreno',
        'user_email'    => 'macrabuaccesorios@gmail.com',
        'store_name'    => 'Macrabu Accesorios',
        'business_type' => 'physical',
    ],
    [
        'display_name'  => 'Jugueteria Taiwan',
        'user_email'    => 'jugueteriataiwan27@gmail.com',
        'store_name'    => 'Jugueteria Taiwan',
        'business_type' => 'physical',
    ],
];

global $wpdb;

foreach ( $vendors_to_migrate as $vendor_data ) {
    echo "Procesando: {$vendor_data['display_name']} ({$vendor_data['user_email']})\n";

    // 1. Verificar si el usuario ya existe.
    $user = get_user_by( 'email', $vendor_data['user_email'] );

    if ( ! $user ) {
        echo "  → Usuario no encontrado. Creando...\n";

        // Generar username desde el email.
        $username = sanitize_user( explode( '@', $vendor_data['user_email'] )[0] );
        if ( username_exists( $username ) ) {
            $username = $username . '_' . wp_rand( 100, 999 );
        }

        // Generar password temporal.
        $password = wp_generate_password( 16, true, true );

        // Crear usuario.
        $user_id = wp_insert_user( [
            'user_login'      => $username,
            'user_email'      => $vendor_data['user_email'],
            'display_name'    => $vendor_data['display_name'],
            'user_pass'       => $password,
            'role'            => 'vendor',
            'show_admin_bar'  => 'false',
        ] );

        if ( is_wp_error( $user_id ) ) {
            echo "  ❌ Error creando usuario: " . $user_id->get_error_message() . "\n";
            continue;
        }

        echo "  ✅ Usuario creado: ID #{$user_id} ({$username})\n";
        echo "  📧 Password temporal generado (enviar email de reset)\n";

        // Enviar email de reset de password.
        wp_send_new_user_notifications( $user_id, 'user' );

    } else {
        $user_id = $user->ID;
        echo "  ✅ Usuario ya existe: ID #{$user_id}\n";

        // Asegurar que tenga rol vendor.
        $user_obj = new WP_User( $user_id );
        if ( ! in_array( 'vendor', $user_obj->roles, true ) ) {
            $user_obj->add_role( 'vendor' );
            echo "  → Rol 'vendor' añadido\n";
        }
    }

    // 2. Actualizar metadatos del vendor.
    $meta_updates = [
        'ltms_store_name'      => $vendor_data['store_name'],
        'ltms_business_type'   => $vendor_data['business_type'],
        'ltms_kyc_status'      => 'pending', // Requiere re-verificación.
        'ltms_is_restaurant'   => 'no',
        'ltms_2fa_enabled'     => 'no',
        'ltms_terms_version'   => '4.1',
        'ltms_privacy_version' => '1.3',
        'ltms_sagrilaft_version' => '2.0',
    ];

    foreach ( $meta_updates as $key => $value ) {
        $existing = get_user_meta( $user_id, $key, true );
        if ( empty( $existing ) ) {
            update_user_meta( $user_id, $key, $value );
            echo "  → Meta '{$key}' = '{$value}'\n";
        } else {
            echo "  → Meta '{$key}' ya tiene valor (preservado)\n";
        }
    }

    // 3. Verificar wallet.
    $wallet_table = $wpdb->prefix . 'lt_vendor_wallets';
    $wallet = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM `{$wallet_table}` WHERE vendor_id = %d",
        $user_id
    ), ARRAY_A );

    if ( ! $wallet ) {
        $currency = function_exists( 'LTMS_Core_Config' ) && class_exists( 'LTMS_Core_Config' )
            ? LTMS_Core_Config::get_currency()
            : 'COP';

        $wpdb->insert( $wallet_table, [
            'vendor_id'       => $user_id,
            'currency'        => $currency,
            'balance'         => 0.00,
            'balance_pending' => 0.00,
            'balance_held'    => 0.00,
            'created_at'      => current_time( 'mysql', true ),
        ] );
        echo "  → Wallet creada (currency: {$currency})\n";
    } else {
        echo "  → Wallet ya existe (balance: {$wallet['balance']})\n";
    }

    // 4. Verificar productos.
    $products = wc_get_products( [
        'status'   => 'any',
        'limit'    => -1,
        'meta_key' => '_ltms_vendor_id',
        'meta_value' => $user_id,
    ] );

    echo "  → Productos asociados: " . count( $products ) . "\n";

    // Si hay productos, actualizar vendor_id meta.
    if ( count( $products ) === 0 ) {
        // Buscar productos por autor.
        $authored = wc_get_products( [
            'status'  => 'any',
            'limit'   => -1,
            'author'  => $user_id,
        ] );
        foreach ( $authored as $product ) {
            update_post_meta( $product->get_id(), '_ltms_vendor_id', $user_id );
            echo "  → Producto #{$product->get_id()} vinculado\n";
        }
    }

    echo "\n";
}

// 5. Flush rewrite rules.
flush_rewrite_rules( true );
echo "→ Rewrite rules flushed\n";

// 6. Clear cache.
wp_cache_flush();
echo "→ Cache flushed\n";

echo "\n=== Migración completada ===\n";
echo "Acciones requeridas:\n";
echo "1. Enviar email de reset de password a los vendedores nuevos\n";
echo "2. Completar KYC desde el panel admin\n";
echo "3. Verificar que los productos aparecen en /vendedor/{slug}/\n";
echo "4. Activar 2FA desde el panel del vendor\n";
