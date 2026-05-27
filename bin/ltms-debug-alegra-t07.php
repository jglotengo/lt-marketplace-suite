<?php
/**
 * ltms-debug-alegra-t07.php - Diagnóstico del fallo T-07
 */

$plugin = WP_CONTENT_DIR . '/plugins/lt-marketplace-suite/';

// 1. Leer item_id desde config
$item_id_config = LTMS_Core_Config::get( 'ltms_alegra_commission_item_id', 0 );
echo "1. item_id en LTMS_Core_Config: $item_id_config\n";

// 2. Leer item_id desde get_option directamente (en caso de caché stale)
$item_id_option = get_option( 'ltms_alegra_commission_item_id', 0 );
echo "2. item_id en get_option: $item_id_option\n";

// 3. Leer contact_id del usuario 18
$contact_id = get_user_meta( 18, '_ltms_alegra_contact_id', true );
echo "3. contact_id user 18: $contact_id\n";

// 4. Simular prepare_commission_items
// Creamos un mock del orden con platform_fee
$commission = 15000.0;
$country    = strtoupper( LTMS_Core_Config::get_country() );
$item_id    = (int) LTMS_Core_Config::get( 'ltms_alegra_commission_item_id', 0 );
echo "4. item_id en prepare_commission_items: $item_id\n";

$line = [
    'name'     => 'Comisión QA test',
    'quantity' => 1,
    'price'    => $commission,
];

if ( ! $item_id ) {
    echo "   -> item_id es 0! El auto-create va a ejecutarse\n";
    // Simular el auto-create
    try {
        $alegra_client = LTMS_Api_Factory::get( 'alegra' );
        echo "   -> LTMS_Api_Factory::get OK\n";
    } catch ( \Throwable $e ) {
        echo "   -> LTMS_Api_Factory::get FALLO: " . $e->getMessage() . "\n";
    }
} else {
    $line['id'] = $item_id;
    echo "   -> item_id=$item_id agregado al line['id'] OK\n";
}

echo "\n5. Payload line: " . json_encode( $line ) . "\n";

// 6. Verificar si LTMS_Api_Factory vs LTMS_Core_Factory
echo "\n6. LTMS_Api_Factory existe: " . ( class_exists('LTMS_Api_Factory') ? 'SI' : 'NO' ) . "\n";
echo "   LTMS_Core_Factory existe: " . ( class_exists('LTMS_Core_Factory') ? 'SI' : 'NO' ) . "\n";

// 7. Intentar la factura directamente con datos conocidos
echo "\n7. Intentando create_invoice directo con item_id=$item_id_config, contact=$contact_id...\n";
if ( $item_id_config > 0 && $contact_id > 0 ) {
    $alegra = LTMS_Api_Factory::get( 'alegra' );
    $payload = [
        'date'         => date( 'Y-m-d' ),
        'due_date'     => date( 'Y-m-d' ),
        'client_id'    => (int) $contact_id,
        'items'        => [[
            'id'       => (int) $item_id_config,
            'quantity' => 1,
            'price'    => 15000.0,
        ]],
        'observations' => 'Debug T-07',
    ];
    try {
        $result = $alegra->create_invoice( $payload );
        echo "   -> OK: invoice_id=" . ( $result['id'] ?? 'sin id' ) . "\n";
    } catch ( \Throwable $e ) {
        echo "   -> FAIL: " . $e->getMessage() . "\n";
        // Ver el raw response si es posible
        echo "   -> Payload enviado: " . json_encode( $payload ) . "\n";
    }
} else {
    echo "   -> Skipped: item_id=$item_id_config contact=$contact_id\n";
}
