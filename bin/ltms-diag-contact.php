<?php
/**
 * Diagnóstico create_contact Alegra — probar variantes de payload
 * wp --path=/home/customer/www/lo-tengo.com.co/public_html eval-file bin/ltms-diag-contact.php --allow-root 2>/dev/null
 */

LTMS_Core_Config::flush_cache();
LTMS_Api_Factory::reset('alegra');
$alegra = LTMS_Api_Factory::get('alegra');

// Usar perform_request directo para ver la respuesta raw
$ts = date('His');

$variants = [
    'type string client' => [
        'name' => "QA Diag $ts A",
        'type' => 'client',
    ],
    'type array [client]' => [
        'name' => "QA Diag $ts B",
        'type' => ['client'],
    ],
    'sin type' => [
        'name' => "QA Diag $ts C",
    ],
    'type string + email único' => [
        'name'  => "QA Diag $ts D",
        'type'  => 'client',
        'email' => "qa-diag-$ts@test.lo-tengo.com.co",
    ],
    'phonePrimary solo' => [
        'name'         => "QA Diag $ts E",
        'type'         => 'client',
        'phonePrimary' => '3001234567',
    ],
    'identification numérico' => [
        'name'           => "QA Diag $ts F",
        'type'           => 'client',
        'identification' => '1234567890',
    ],
];

foreach ($variants as $label => $payload) {
    echo "\n--- $label ---\n";
    echo "Payload: " . json_encode($payload) . "\n";
    try {
        // Llamar perform_request directamente con logging
        $result = $alegra->create_contact($payload);
        echo "✅ OK — ID=" . ($result['id'] ?? '?') . " nombre=" . ($result['name'] ?? '?') . "\n";
        // Si creó, mostrar ID para borrar
        if (!empty($result['id'])) {
            echo "   (contacto de prueba — ID {$result['id']} — borrar en Alegra)\n";
        }
    } catch (Throwable $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

echo "\n--- Consultar contactos existentes (primeros 3) ---\n";
try {
    $contacts = $alegra->find_contact_by_email(''); // hack para listar
} catch(Throwable $e) {}

// Directo
try {
    // Usar reflection o método público para hacer GET /contacts
    $r = new ReflectionClass($alegra);
    $m = $r->getMethod('perform_request');
    $m->setAccessible(true);
    $resp = $m->invoke($alegra, 'GET', '/contacts', [], [], false);
    $list = $resp['data'] ?? (is_array($resp) ? $resp : []);
    echo "Total contactos en Alegra: " . count($list) . "\n";
    foreach(array_slice($list, 0, 3) as $c) {
        echo "  ID={$c['id']} | nombre={$c['name']} | email=" . ($c['email'] ?? 'sin email') . " | tipo=" . json_encode($c['type'] ?? '') . "\n";
    }
} catch(Throwable $e) {
    echo "Error listando: " . $e->getMessage() . "\n";
}
