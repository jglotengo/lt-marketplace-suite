<?php
/**
 * ZapSign Quick Diagnostic v2 — verifica token, template, webhook, PDF url y envío
 * Ejecutar: wp --path=/home/customer/www/lo-tengo.com.co/public_html eval-file bin/ltms-zap-diag.php --allow-root
 */
if ( ! defined( 'ABSPATH' ) ) die;

$ok   = fn( $msg ) => print "  ✅ $msg\n";
$fail = fn( $msg ) => print "  ❌ $msg\n";
$info = fn( $msg ) => print "  ℹ️  $msg\n";
$warn = fn( $msg ) => print "  ⚠️  $msg\n";

echo "\n=== ZAPSIGN QUICK DIAGNOSTIC v2 ===\n\n";

// 1. Token
$raw_token = LTMS_Core_Config::get( 'ltms_zapsign_api_token', '' );
$token = '';
if ( $raw_token && class_exists( 'LTMS_Core_Security' ) ) {
    try { $token = LTMS_Core_Security::decrypt( $raw_token ); } catch(Exception $e){ $token = $raw_token; }
}
$tlen = strlen($token);
$tlen > 0 ? $ok("Token API: {$tlen} chars — " . substr($token,0,8) . '...') : $fail('Token API: VACÍO');

// 2. Template ID
$tmpl = LTMS_Core_Config::get( 'ltms_zapsign_vendor_template_id', '' )
     ?: get_option( 'ltms_zapsign_vendor_template_id', '' );
$tmpl ? $ok("Template ID: {$tmpl}") : $warn('Template ID: no configurado (se usará PDF directo)');

// 3. PDF URL / attachment
$pdf_url       = LTMS_Core_Config::get( 'ltms_zapsign_contract_pdf_url', '' );
$attachment_id = (int) LTMS_Core_Config::get( 'ltms_zapsign_contract_attachment_id', 0 );
if ( ! empty( $pdf_url ) ) {
    $ok("PDF URL configurada: " . substr( $pdf_url, 0, 60 ) . '...');
} elseif ( $attachment_id > 0 ) {
    $att_url = wp_get_attachment_url( $attachment_id );
    $att_url ? $ok("PDF desde Media Library ID={$attachment_id}: {$att_url}") : $fail("Attachment ID={$attachment_id} no retorna URL");
} else {
    if ( $tmpl ) {
        $info("PDF URL: no configurada — se usará template (puede dar 402 sin plan ZapSign)");
    } else {
        $fail("PDF URL: VACÍA y sin template — configura en LT Marketplace → Configuración → ZapSign");
    }
}

// 4. Auto-aprobación KYC
$auto = LTMS_Core_Config::get( 'ltms_zapsign_auto_approve_kyc', 'no' );
$auto === 'yes'
    ? $ok("Auto-aprobación KYC al firmar: ACTIVADA")
    : $warn("Auto-aprobación KYC: desactivada — actívala en LT Marketplace → Configuración → ZapSign");

// 5. Conectividad real
echo "\n--- Conectividad ZapSign ---\n";
$resp = wp_remote_get( 'https://api.zapsign.com.br/api/v1/docs/', [
    'headers'   => ['Authorization' => "Bearer {$token}", 'Content-Type' => 'application/json'],
    'timeout'   => 20,
    'sslverify' => true,
]);
if ( is_wp_error($resp) ) {
    $fail("wp_remote_get: " . $resp->get_error_message() . ' (code: ' . $resp->get_error_code() . ')');
} else {
    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    if ( $code >= 200 && $code < 300 ) {
        $ok("HTTP {$code} — API responde OK");
    } elseif ( $code === 401 ) {
        $fail("HTTP 401 — Token inválido. Verifica en ZapSign → Cuenta → API Token");
    } elseif ( $code === 402 ) {
        $warn("HTTP 402 — Plan de pago requerido para este endpoint en producción");
    } else {
        $fail("HTTP {$code} — " . substr($body, 0, 200));
    }
}

// 6. Template en ZapSign (si configurado)
if ( $tmpl ) {
    echo "\n--- Verificando Template ---\n";
    $resp2 = wp_remote_get( "https://api.zapsign.com.br/api/v1/templates/{$tmpl}/", [
        'headers' => ['Authorization' => "Bearer {$token}"],
        'timeout' => 15,
    ]);
    if ( is_wp_error($resp2) ) {
        $fail("Template check: " . $resp2->get_error_message());
    } else {
        $code2 = wp_remote_retrieve_response_code($resp2);
        $body2 = json_decode(wp_remote_retrieve_body($resp2), true);
        if ( $code2 === 200 ) {
            $name = $body2['name'] ?? $body2['title'] ?? '?';
            $ok("Template existe en ZapSign: '{$name}'");
        } elseif ( $code2 === 402 ) {
            $warn("HTTP 402 — Template existe pero requiere plan de pago para usarlo");
            $info("Solución: configura una URL de PDF del contrato como fallback");
        } elseif ( $code2 === 404 ) {
            $fail("Template ID={$tmpl} NO existe en ZapSign — borra el ID en la configuración");
        } else {
            $warn("HTTP {$code2} verificando template");
        }
    }
}

// 7. Envío real de prueba
echo "\n--- Envío de prueba ---\n";
$vendor_id = (int) email_exists('test-seller@prueba.com');
if ( ! $vendor_id ) {
    $info("test-seller@prueba.com no existe — buscando cualquier vendor para prueba...");
    global $wpdb;
    $vendor_id = (int) $wpdb->get_var("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='wp_capabilities' AND meta_value LIKE '%ltms_vendor%' ORDER BY user_id ASC LIMIT 1");
}

if ( $vendor_id && class_exists('LTMS_Api_Zapsign') ) {
    $user = get_userdata($vendor_id);
    $info("Probando con vendedor #{$vendor_id} — {$user->user_email}");
    try {
        $zap    = LTMS_Api_Factory::get('zapsign');
        $result = $zap->send_vendor_contract( $vendor_id, $pdf_url ?: '' );
        if ( ! empty($result['doc_token']) || ! empty($result['token']) ) {
            $tok = $result['doc_token'] ?? $result['token'];
            $ok("send_vendor_contract() EXITOSO — doc_token=" . substr($tok,0,20) . '...');
            $ok("Link firma: " . ($result['sign_url'] ?? $result['signers'][0]['sign_url'] ?? 'N/A'));
        } else {
            $fail("Respuesta sin token: " . wp_json_encode(array_keys($result)));
            echo "       " . substr(wp_json_encode($result), 0, 300) . "\n";
        }
    } catch(Throwable $e) {
        $fail("send_vendor_contract(): " . $e->getMessage());
    }
} else {
    $warn("No hay vendedores en el sistema para hacer prueba de envío");
}

// 8. Webhook URL
echo "\n--- Webhook ---\n";
$wh = home_url('/wp-json/ltms/v1/webhooks/zapsign');
$info("URL para configurar en ZapSign → Integraciones → Webhook: {$wh}");

echo "\n=== DONE ===\n\n";
