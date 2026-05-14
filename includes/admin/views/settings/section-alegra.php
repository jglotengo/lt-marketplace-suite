<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$fields = [
    'ltms_alegra_enabled'          => [ 'label' => 'Alegra Activo',                    'type' => 'checkbox', 'desc' => 'Activar integración con Alegra Contabilidad' ],
    'ltms_alegra_email'            => [ 'label' => 'Email de la cuenta Alegra',        'type' => 'email', 'desc' => 'El mismo email con el que inicias sesión en Alegra.' ],
    'ltms_alegra_token'            => [ 'label' => 'Token API de Alegra',              'type' => 'password', 'desc' => 'Ve a Alegra → Mi perfil → API → Token de acceso.' ],
    'ltms_alegra_numbering_id'     => [ 'label' => 'ID de Numeración (Factura)',       'type' => 'text', 'desc' => 'ID de la resolución de facturación en Alegra. Ej: 1' ],
    'ltms_alegra_bank_account_id'  => [ 'label' => 'ID Cuenta Bancaria',               'type' => 'text', 'desc' => 'ID de la cuenta bancaria en Alegra para registrar pagos.' ],
    'ltms_alegra_auto_invoice'     => [ 'label' => 'Facturación Automática',           'type' => 'checkbox', 'desc' => 'Crear factura en Alegra al completarse un pedido' ],
    'ltms_alegra_auto_payment'     => [ 'label' => 'Registrar Pago Automático',        'type' => 'checkbox', 'desc' => 'Registrar el pago en Alegra después de crear la factura' ],
    'ltms_alegra_sandbox'          => [ 'label' => 'Modo Sandbox',                     'type' => 'checkbox', 'desc' => 'Usar el ambiente de pruebas de Alegra (no emite facturas reales)' ],
    'ltms_alegra_webhook_url'      => [ 'label' => 'URL Webhook (Alegra → LTMS)',      'type' => 'url', 'readonly' => true, 'value_fn' => fn() => rest_url('ltms/v1/webhook/alegra') ],
];
$test_result = get_transient('ltms_alegra_test_result');
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
        $value = isset($field['value_fn']) ? ($field['value_fn'])() : get_option($key, '');
    ?>
    <tr>
        <th scope="row"><label for="<?php echo esc_attr($key);?>"><?php echo esc_html($field['label']);?></label></th>
        <td>
        <?php if($field['type']==='checkbox'):?>
            <label><input type="checkbox" name="<?php echo esc_attr($key);?>" value="yes" <?php checked($value,'yes');?>> <?php echo esc_html($field['desc']??'');?></label>
        <?php elseif($field['type']==='password'):?>
            <input type="password" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($value);?>" class="regular-text" autocomplete="new-password">
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

    <?php if ( get_option('ltms_alegra_token') ) : ?>
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
            result.textContent = d.success ? '✅ ' + (d.data?.message||'Conexión OK') : '❌ ' + (d.data||'Error');
            result.style.color = d.success ? '#27ae60' : '#c0392b';
        }).catch(()=>{ btn.disabled=false; result.textContent='❌ Error de red'; result.style.color='#c0392b'; });
    });
    </script>
    <?php endif; ?>
</div>
