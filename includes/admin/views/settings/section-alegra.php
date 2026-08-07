<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Leer valores via LTMS_Core_Config::get() que hace fallback correcto:
// 1) constante PHP  2) ltms_settings  3) get_option() individual
$fields = [
    'ltms_alegra_enabled'          => [ 'label' => 'Alegra Activo',                    'type' => 'checkbox', 'desc' => 'Activar integración con Alegra Contabilidad' ],
    'ltms_alegra_email'            => [ 'label' => 'Email de la cuenta Alegra',        'type' => 'email',    'desc' => 'El mismo email con el que inicias sesión en Alegra.' ],
    'ltms_alegra_token'            => [ 'label' => 'Token API de Alegra',              'type' => 'password', 'desc' => 'Ve a Alegra → Mi perfil → API → Token de acceso.' ],
    'ltms_alegra_numbering_id'     => [ 'label' => 'ID de Numeración (Factura)',       'type' => 'text',     'desc' => 'ID de la resolución de facturación en Alegra. Ej: 1' ],
    'ltms_alegra_bank_account_id'  => [ 'label' => 'ID Cuenta Bancaria',               'type' => 'text',     'desc' => 'ID de la cuenta bancaria en Alegra para registrar pagos.' ],
    'ltms_alegra_auto_invoice'     => [ 'label' => 'Facturación Automática',           'type' => 'checkbox', 'desc' => 'Crear factura en Alegra al completarse un pedido' ],
    'ltms_alegra_auto_payment'     => [ 'label' => 'Registrar Pago Automático',        'type' => 'checkbox', 'desc' => 'Registrar el pago en Alegra después de crear la factura' ],
    'ltms_alegra_sandbox'          => [ 'label' => 'Modo Sandbox',                     'type' => 'checkbox', 'desc' => 'Usar el ambiente de pruebas de Alegra (no emite facturas reales)' ],
    'ltms_alegra_webhook_url'      => [ 'label' => 'URL Webhook (Alegra → LTMS)',      'type' => 'url',      'readonly' => true, 'value_fn' => fn() => rest_url( 'ltms/v1/webhook/alegra' ) ],
];

// Usar LTMS_Core_Config::get() para consistencia con el backend
$get_value = function( string $key, $field ): string {
    if ( isset( $field['value_fn'] ) ) {
        return (string) ( $field['value_fn'] )();
    }
    if ( class_exists( 'LTMS_Core_Config' ) ) {
        return (string) LTMS_Core_Config::get( $key, '' );
    }
    return (string) get_option( $key, '' );
};

// CICLO22-P2-AD-SET-115 FIX: el handler central sanitize_settings()
// (class-ltms-admin-settings.php:119) cifra ltms_alegra_token con AES-256 via
// LTMS_Core_Security::encrypt() — los valores cifrados tienen prefijo 'v1:'.
// Antes, este view emitia en value= el placeholder visual '•••••••••••••••••'
// (29 bullets) cuando detectaba el prefijo. Eso tenia 2 problemas: (1) el admin
// ve un campo "lleno" — confusion UX: cree que el token esta disponible para
// inspeccionar y se sorprende al ver solo bullets. (2) Si el admin guarda la
// forma sin tocar el campo, el navegador envia los bullets como valor del
// input; el handler central recibe '••••...' como nuevo token, lo pasa por
// sanitize_text_field() (no empty()), NO esta en $encrypted_fields "is_v1" check
// (porque los bullets no empiezan con 'v1:') y LO CIFRA COMO NUEVO TOKEN
// perdiendo el valor original. Bug silencioso de "Guardar sin tocar" que
// rompia integracion Alegra sin diagnostic claro. Fix: alinear al patron
// estandar C22 (vaciar value= y usar placeholder '(guardado — dejar vacío para
// mantener)') — identico a section-zapsign.php AD-SET-107, section-google_oauth
// AD-SET-108, section-backblaze.php AD-SET-112, section-siigo.php AD-SET-113,
// section-payments.php AD-SET-114. El handler mantiene el valor cifrado
// original si el input llega vacio (linea 124-130).
$token_val = $get_value( 'ltms_alegra_token', [] );
$token_is_encrypted = is_string( $token_val ) && str_starts_with( $token_val, 'v1:' );
$token_display = $token_is_encrypted ? '' : $token_val;
$token_placeholder = $token_is_encrypted
    ? __( '(guardado — dejar vacío para mantener)', 'ltms' )
    : __( 'Ingresa el Token de acceso Alegra', 'ltms' );

$test_result = get_transient( 'ltms_alegra_test_result' );
?>
<div class="ltms-settings-section">
    <h2 style="margin-top:24px;">📊 Alegra Contabilidad</h2>
    <div class="notice notice-info inline" style="margin:8px 0;"><p>
        Para obtener el Token de API: inicia sesión en <strong>app.alegra.com</strong> → 
        Configuración → API → copia el Token de acceso. 
        El ID de Numeración lo encuentras en Configuración → Numeración de documentos.
    </p></div>

    <?php if ( $test_result ) : ?>
    <div class="notice <?php echo $test_result['success'] ? 'notice-success' : 'notice-error'; ?> inline" style="margin:8px 0;">
        <p><strong>Último test:</strong> <?php echo esc_html($test_result['message']); ?>
        (<?php echo esc_html(date_i18n('d/m/Y H:i', $test_result['time'])); ?>)</p>
    </div>
    <?php endif; ?>

    <table class="form-table" role="presentation"><tbody>
    <?php foreach ( $fields as $key => $field ) :
        $value = $get_value( $key, $field );
        // Token cifrado: vaciar el value para que el admin no envie los bullets como
        // nuevo token al guardar (bug silencioso AD-SET-115). El handler mantiene el
        // valor cifrado original si el input llega vacio.
        $is_token_encrypted = ( $key === 'ltms_alegra_token' )
            && is_string( $value ) && str_starts_with( $value, 'v1:' );
        if ( $is_token_encrypted ) {
            $value = '';
        }
        $current_placeholder = $is_token_encrypted ? $token_placeholder : ( $field['placeholder'] ?? '' );
    ?>
    <tr>
        <th scope="row"><label for="<?php echo esc_attr($key);?>"><?php echo esc_html($field['label']);?></label></th>
        <td>
        <?php if($field['type']==='checkbox'):?>
            <label><input type="checkbox" name="<?php echo esc_attr($key);?>" value="yes" <?php checked($value,'yes');?>> <?php echo esc_html($field['desc']??'');?></label>
        <?php elseif($field['type']==='password'):?>
            <input type="password" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($value);?>" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr($current_placeholder); ?>">
            <?php if(!empty($field['desc'])):?><p class="description"><?php echo esc_html($field['desc']);?></p><?php endif;?>
        <?php else:?>
            <input type="<?php echo esc_attr($field['type']);?>" name="<?php echo esc_attr($key);?>"
                   value="<?php echo esc_attr($value);?>" class="regular-text"
                   <?php echo !empty($field['readonly']) ? 'readonly style="background:#f5f5f5;color:#555;"' : ''; ?>>
            <?php if(!empty($field['desc'])):?><p class="description"><?php echo esc_html($field['desc']);?></p><?php endif;?>
        <?php endif;?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody></table>

    <?php
    $has_token = class_exists( 'LTMS_Core_Config' )
        ? ! empty( LTMS_Core_Config::get( 'ltms_alegra_token', '' ) )
        : ! empty( get_option( 'ltms_alegra_token' ) );
    if ( $has_token ) : ?>
    <p style="margin-top:16px;">
        <button type="button" class="button button-secondary" id="ltms-test-alegra-btn">
            🔌 Probar Conexión con Alegra
        </button>
        <span id="ltms-alegra-test-result" style="margin-left:12px;"></span>
    </p>
    <script>
    document.getElementById('ltms-test-alegra-btn')?.addEventListener('click', function() {
        var btn = this, result = document.getElementById('ltms-alegra-test-result');
        btn.disabled = true; btn.textContent = 'Probando...';
        fetch(ajaxurl, { method: 'POST', headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: 'action=ltms_test_api_connection&provider=alegra&nonce=' + (document.getElementById('ltms_nonce')?.value||'')
        }).then(r=>r.json()).then(d => {
            btn.disabled = false; btn.textContent = '🔌 Probar Conexión con Alegra';
            var errMsg = typeof d.data === 'string' ? d.data : (d.data?.message || JSON.stringify(d.data) || 'Error desconocido');
            result.textContent = d.success ? '✅ ' + (d.data?.message||'Conexión OK') : '❌ ' + errMsg;
            result.style.color = d.success ? '#27ae60' : '#c0392b';
        }).catch(()=>{ btn.disabled=false; result.textContent='❌ Error de red'; result.style.color='#c0392b'; });
    });
    </script>
    <?php endif; ?>
</div>
