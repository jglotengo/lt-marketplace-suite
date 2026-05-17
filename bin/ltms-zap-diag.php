<?php
/**
 * ZapSign Quick Diagnostic — verifica conectividad, template y webhook
 * Ejecutar: wp --path=/home/customer/www/lo-tengo.com.co/public_html eval-file bin/ltms-zap-diag.php --allow-root
 */
if ( ! defined( 'ABSPATH' ) ) die;

$ok   = fn( $msg ) => print "  ✅ $msg\n";
$fail = fn( $msg ) => print "  ❌ $msg\n";
$info = fn( $msg ) => print "  ℹ️  $msg\n";

echo "\n=== ZAPSIGN QUICK DIAGNOSTIC ===\n\n";

// 1. Token
$raw_token = get_option('ltms_zapsign_api_token', '');
if ( ! $raw_token ) $raw_token = LTMS_Core_Config::get('ltms_zapsign_api_token','');
$token = '';
if ( $raw_token && class_exists('LTMS_Core_Security') ) {
    try { $token = LTMS_Core_Security::decrypt( $raw_token ); } catch(Exception $e){ $token = $raw_token; }
}
$token_len = strlen($token);
$token_len > 0 ? $ok("Token API: {$token_len} chars — " . substr($token,0,8) . '...' ) : $fail('Token API: VACÍO');

// 2. Template ID
$tmpl = get_option('ltms_zapsign_vendor_template_id','');
if ( ! $tmpl ) $tmpl = LTMS_Core_Config::get('ltms_zapsign_vendor_template_id','');
$tmpl ? $ok("Template ID: {$tmpl}") : $fail('Template ID: VACÍO — configura en LT Marketplace → ZapSign');

// 3. Health check directo — llama al endpoint correcto
$url  = 'https://api.zapsign.com.br/api/v1/docs/';
$resp = wp_remote_get($url, [
    'headers'   => ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json'],
    'timeout'   => 20,
    'sslverify' => true,
]);
if ( is_wp_error($resp) ) {
    $fail("Conectividad ZapSign: " . $resp->get_error_message() . ' (code: ' . $resp->get_error_code() . ')');
} else {
    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    if ( $code === 200 || $code === 201 ) {
        $ok("Conectividad ZapSign: HTTP {$code} — API responde ✓");
    } elseif ( $code === 401 ) {
        $fail("HTTP {$code} — Token inválido o expirado");
        echo "       Body: " . substr($body, 0, 200) . "\n";
    } else {
        $fail("HTTP {$code}");
        echo "       Body: " . substr($body, 0, 300) . "\n";
    }
}

// 4. Verificar que el template existe en ZapSign
if ( $tmpl ) {
    $resp2 = wp_remote_get("https://api.zapsign.com.br/api/v1/templates/{$tmpl}/", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'timeout' => 15,
    ]);
    if ( is_wp_error($resp2) ) {
        $fail("Template en ZapSign: " . $resp2->get_error_message());
    } else {
        $code2 = wp_remote_retrieve_response_code($resp2);
        $body2 = json_decode(wp_remote_retrieve_body($resp2), true);
        if ( $code2 === 200 ) {
            $name = $body2['name'] ?? '?';
            $ok("Template en ZapSign: '{$name}' (ID={$tmpl})");
        } else {
            $fail("Template NO encontrado en ZapSign — HTTP {$code2}");
            echo "       Body: " . substr(wp_remote_retrieve_body($resp2), 0, 200) . "\n";
        }
    }
}

// 5. Webhook URL
$webhook_url = home_url('/wp-json/ltms/v1/webhooks/zapsign');
$info("Webhook URL configurar en ZapSign: {$webhook_url}");

// 6. Auto-aprobación KYC
$auto = get_option('ltms_zapsign_auto_approve_kyc', 'no');
$auto === 'yes' ? $ok("Auto-aprobación KYC: activada") : $info("Auto-aprobación KYC: desactivada");

// 7. Test envío de contrato a vendedor existente (dry-run)
if ( $tmpl && $token_len > 0 ) {
    $info("Intentando envío de prueba a test-seller@prueba.com...");
    if ( class_exists('LTMS_Api_Zapsign') ) {
        $zap = LTMS_Api_Factory::get('zapsign');
        $vendor_id = (int) email_exists('test-seller@prueba.com');
        if ( $vendor_id ) {
            try {
                $result = $zap->send_vendor_contract( $vendor_id, get_userdata($vendor_id)->user_email, 'Test Seller QA' );
                if ( ! empty($result['doc_token']) || ! empty($result['token']) ) {
                    $tok = $result['doc_token'] ?? $result['token'];
                    $ok("send_vendor_contract() — doc_token=" . substr($tok,0,16) . '...');
                } else {
                    $fail("send_vendor_contract() — respuesta sin token: " . wp_json_encode($result));
                }
            } catch(Throwable $e) {
                $fail("send_vendor_contract(): " . $e->getMessage());
            }
        } else {
            $info("test-seller no existe — omitiendo envío de prueba");
        }
    }
}

echo "\n=== DONE ===\n\n";
