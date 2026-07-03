<?php
/**
 * ltms-patch-zapsign-final.php
 * wp --path=/home/customer/www/lo-tengo.com.co/public_html \
 *    eval-file bin/ltms-patch-zapsign-final.php --allow-root 2>/dev/null
 */
echo "=== LTMS ZapSign — Patch Final ===\n\n";

$plugin_dir = WP_CONTENT_DIR . '/plugins/lt-marketplace-suite/';
$api_file   = $plugin_dir . 'includes/api/class-ltms-api-zapsign.php';
$pdf_dest   = $plugin_dir . 'assets/contracts/contrato-vendedor.pdf';

// 1. Verificar PDF
if ( file_exists( $pdf_dest ) && filesize( $pdf_dest ) > 500 ) {
    echo "✅ PDF contrato presente: " . number_format(filesize($pdf_dest)) . " bytes\n";
} else {
    echo "❌ PDF NO encontrado en: $pdf_dest\n";
    echo "   Ejecuta primero el script bash de preparación.\n";
    exit(1);
}

// 2. Leer el API file
if ( ! file_exists( $api_file ) ) {
    echo "❌ No encontrado: $api_file\n";
    exit(1);
}
$content  = file_get_contents( $api_file );
$original = $content;

// 3. Nuevo código de los métodos
$new_methods = "\n    /**\n     * Envía el contrato de vinculación al vendedor via ZapSign.\n     * Sandbox: pdf_base64 (sin Plan API). Producción: template_id.\n     *\n     * @param array $args {vendor_id, name, email, document}\n     * @return array|WP_Error\n     */\n    public function send_vendor_contract( array $args ): array|\\WP_Error {\n        $vendor_id = (int)   ( $args['vendor_id'] ?? 0 );\n        $name      = sanitize_text_field( $args['name']  ?? '' );\n        $email     = sanitize_email(      $args['email'] ?? '' );\n\n        if ( empty( $email ) || ! is_email( $email ) ) {\n            return new \\WP_Error( 'invalid_email', __( 'Email del vendedor inválido.', 'ltms' ) );\n        }\n\n        $is_sandbox  = $this->is_sandbox();\n        $template_id = $this->get_template_id();\n        $pdf_local   = WP_CONTENT_DIR . '/plugins/lt-marketplace-suite/assets/contracts/contrato-vendedor.pdf';\n\n        $payload = [\n            'sandbox'     => $is_sandbox,\n            'signers'     => [[\n                'name'                 => $name ?: 'Vendedor',\n                'email'                => $email,\n                'send_automatic_email' => true,\n            ]],\n            'name'        => sprintf( 'Contrato Vendedor — %s', $name ),\n            'lang'        => 'es',\n            'external_id' => 'ltms-vendor-' . ( $vendor_id ?: uniqid() ),\n        ];\n\n        // Sandbox ⟶ siempre base64 (templates requieren Plan API de ZapSign)\n        // Producción con template ⟶ template_id\n        if ( ! $is_sandbox && ! empty( $template_id ) ) {\n            $payload['template_id'] = $template_id;\n        } else {\n            $b64 = $this->get_contract_pdf_base64( $pdf_local );\n            if ( is_wp_error( $b64 ) ) return $b64;\n            $payload['pdf_base64'] = $b64;\n        }\n\n        $response = $this->perform_request( 'POST', '/api/v1/docs/', $payload, 3 );\n        if ( is_wp_error( $response ) ) return $response;\n\n        $doc_token  = $response['token']                  ?? '';\n        $sign_url   = $response['signers'][0]['sign_url'] ?? '';\n        $sign_token = $response['signers'][0]['token']    ?? '';\n\n        if ( empty( $doc_token ) ) {\n            return new \\WP_Error( 'zapsign_no_token', 'ZapSign no devolvió token.', $response );\n        }\n\n        if ( $vendor_id > 0 ) {\n            update_user_meta( $vendor_id, 'ltms_zapsign_contract', [\n                'document_token' => $doc_token,\n                'sign_url'       => $sign_url,\n                'signer_token'   => $sign_token,\n                'sent_at'        => current_time( 'mysql' ),\n                'sandbox'        => $is_sandbox,\n            ]);\n            update_user_meta( $vendor_id, 'ltms_kyc_status', 'pending_signature' );\n        }\n\n        return compact( 'doc_token', 'sign_url', 'sign_token' );\n    }\n\n    /**\n     * Obtiene base64 del contrato PDF.\n     * 1) Archivo local del plugin  2) WP attachment  3) URL pública\n     *\n     * @param string $pdf_local Ruta local al PDF del contrato.\n     * @return string|WP_Error\n     */\n    private function get_contract_pdf_base64( string $pdf_local ): string|\\WP_Error {\n        if ( file_exists( $pdf_local ) && filesize( $pdf_local ) > 500 ) {\n            return base64_encode( file_get_contents( $pdf_local ) );\n        }\n\n        $attach_id = (int) get_option( 'ltms_zapsign_contract_attachment_id', 0 );\n        if ( $attach_id > 0 ) {\n            $f = get_attached_file( $attach_id );\n            if ( $f && file_exists( $f ) ) return base64_encode( file_get_contents( $f ) );\n        }\n\n        $url = get_option( 'ltms_zapsign_contract_pdf_url', '' );\n        if ( ! empty( $url ) && filter_var( $url, FILTER_VALIDATE_URL ) ) {\n            $r = wp_remote_get( $url, [ 'timeout' => 15, 'sslverify' => false ] );\n            if ( ! is_wp_error( $r ) && 200 === wp_remote_retrieve_response_code( $r ) ) {\n                $body = wp_remote_retrieve_body( $r );\n                if ( strlen( $body ) > 500 ) return base64_encode( $body );\n            }\n        }\n\n        return new \\WP_Error( 'no_contract_pdf',\n            'PDF del contrato no encontrado. Colócalo en: ' . dirname( $pdf_local ) . '/contrato-vendedor.pdf'\n        );\n    }\n";

// 4. Eliminar get_contract_pdf_base64 si ya existe
$content = preg_replace(
    '/[ \t]*\/\*\*[^*]*get_contract_pdf_base64[\s\S]*?\n    \}\n/m',
    "",
    $content
);

// 5. Localizar y reemplazar send_vendor_contract usando conteo de llaves
$needle = 'function send_vendor_contract';
$method_start = strrpos($content, '/**', strpos($content, $needle) - 800 ?: 0);
if ($method_start === false) {
    $method_start = strpos($content, 'public function send_vendor_contract');
}

if ($method_start !== false) {
    // Encontrar el inicio de la llave del método
    $brace_open = strpos($content, '{', strpos($content, $needle));
    $brace_count = 0;
    $method_end = $brace_open;
    for ($i = $brace_open; $i < strlen($content); $i++) {
        if ($content[$i] === '{') $brace_count++;
        elseif ($content[$i] === '}') {
            $brace_count--;
            if ($brace_count === 0) { $method_end = $i + 1; break; }
        }
    }
    $content = substr($content, 0, $method_start) . $new_methods . substr($content, $method_end);
    echo "✅ send_vendor_contract reemplazado (pos=$method_start...$method_end)\n";
} else {
    // Insertar antes del cierre de clase
    $last = strrpos($content, '}');
    $content = substr($content, 0, $last) . "\n" . $new_methods . "\n" . substr($content, $last);
    echo "✅ Métodos insertados al final de clase\n";
}

if ($content !== $original) {
    file_put_contents($api_file, $content);
    echo "✅ Archivo guardado\n";
    if (function_exists('opcache_invalidate')) opcache_invalidate($api_file, true);
} else {
    echo "⚠️  Archivo sin cambios — método puede tener firma diferente\n";
}

// 6. Git commit + push
chdir($plugin_dir);
system('git add assets/contracts/ includes/api/class-ltms-api-zapsign.php');
system('git -c user.email=bot@ltms.co -c user.name="LTMS Bot" commit -m "fix(zapsign): pdf_base64 en sandbox; get_contract_pdf_base64 con 3 fallbacks; contrato PDF en assets/contracts/"');
system('git remote set-url origin https://jglotengo:GITHUB_TOKEN_REMOVED@github.com/jglotengo/lt-marketplace-suite.git');
system('git push origin main && echo "PUSH_OK"');

// 7. Test
echo "\nProbando...\n";
if (class_exists('LTMS_Core_Factory')) {
    $api = LTMS_Core_Factory::get_api('zapsign');
    $r = $api->send_vendor_contract(['vendor_id'=>0,'name'=>'QA Test','email'=>'test@prueba.com']);
    echo is_wp_error($r)
        ? "❌ " . $r->get_error_message() . "\n"
        : "✅ OK — token=" . $r['document_token'] . "\n    sign_url=" . $r['sign_url'] . "\n";
} else {
    echo "ℹ️  Verificar con: wp eval-file bin/ltms-zap-diag.php --allow-root\n";
}

echo "\n=== FIN ===\n";
