<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// CICLO22-P1-AD-SET-113 FIX: el handler central sanitize_settings()
// (class-ltms-admin-settings.php:115) cifra ltms_siigo_access_key con AES-256 via
// LTMS_Core_Security::encrypt() — los valores cifrados tienen prefijo 'v1:'. Si el
// valor crudo (incl. el hash 'v1:...') se muestra en el atributo value= del input
// password, queda expuesto en el DOM admin (visible via DevTools o capturado por
// password managers). El renderer generico de html-admin-settings.php:290 ya aplica
// el patron correcto: vaciar el value cuando detecta prefijo 'v1:'. Este view custom
// (renderer dinamico local con $fields + foreach) NO replicaba ese patron — lo
// agrego aqui para cerrar el leak del Siigo Access Key en el DOM. El Access Key
// Siigo concede acceso a la API de facturación electrónica (creacion de facturas,
// consultas contables). Patron identico al aplicado en section-zapsign.php
// AD-SET-107, section-google_oauth.php AD-SET-108, section-backblaze.php AD-SET-112,
// section-payments.php AD-SET-114 (mismo ciclo).
$fields = [
    'ltms_siigo_enabled'        => [ 'label' => 'Siigo Activo',                'type' => 'checkbox', 'desc' => 'Activar integración con Siigo ERP' ],
    'ltms_siigo_username'       => [ 'label' => 'Usuario (email)',              'type' => 'email' ],
    'ltms_siigo_access_key'          => [ 'label' => 'Contraseña / Access Key',     'type' => 'password', 'desc' => 'Se guarda encriptado.' ],
    'ltms_siigo_account_id'          => [ 'label' => 'Account ID (Partner)',        'type' => 'text' ],
    'ltms_siigo_invoice_document_id' => [ 'label' => 'ID Documento Factura',        'type' => 'text', 'default' => '', 'desc' => 'ID numérico del tipo de documento en Siigo (ej: 1 para FV)' ],
    'ltms_siigo_seller_id'           => [ 'label' => 'ID Vendedor Siigo',           'type' => 'text', 'desc' => 'ID del vendedor por defecto en Siigo' ],
    'ltms_siigo_payment_method_id'   => [ 'label' => 'ID Método de Pago Siigo',    'type' => 'text', 'default' => '5396' ],
    'ltms_siigo_tax_id'              => [ 'label' => 'ID Impuesto IVA Siigo',       'type' => 'text', 'default' => '29', 'desc' => 'ID del impuesto IVA en Siigo (por defecto 29 = IVA 19%)' ],
    'ltms_siigo_webhook_token'       => [ 'label' => 'Token Webhook Siigo',         'type' => 'text', 'desc' => 'Token secreto para validar webhooks entrantes de Siigo' ],
    'ltms_siigo_auto_invoice'   => [ 'label' => 'Facturación Automática',      'type' => 'checkbox', 'desc' => 'Crear factura en Siigo al completarse un pedido' ],
    'ltms_siigo_sandbox'        => [ 'label' => 'Modo Sandbox Siigo',          'type' => 'checkbox', 'desc' => 'Usar API de pruebas de Siigo' ],
    'ltms_siigo_webhook_url'    => [ 'label' => 'URL Webhook (Siigo → LTMS)', 'type' => 'url', 'readonly' => true, 'value_fn' => fn() => rest_url('ltms/v1/webhook/siigo') ],
];
?>
<div class="ltms-settings-section">
    <h2 style="margin-top:24px;">🧾 Siigo ERP — Facturación Electrónica</h2>
    <div class="notice notice-info inline" style="margin:8px 0;"><p>
        Para obtener las credenciales de Siigo, ve a <strong>siigo.com → API → Integración de partners</strong>.
        El Access Key se genera desde el portal de desarrolladores de Siigo.
    </p></div>
    <table class="form-table" role="presentation"><tbody>
    <?php foreach ( $fields as $key => $field ) :
        $value = isset($field['value_fn']) ? ($field['value_fn'])() : get_option($key, $field['default'] ?? '');
        // AD-SET-113: si el campo es password y el valor ya esta cifrado (prefijo
        // 'v1:'), vaciar el value para no exponer el hash en el DOM. El handler
        // mantiene el valor cifrado original si el input llega vacio (linea 124-130
        // del handler central).
        $is_password_encrypted = ( ($field['type'] ?? '') === 'password' )
            && is_string( $value ) && strpos( $value, 'v1:' ) === 0;
        $display_value = $is_password_encrypted ? '' : $value;
        $display_placeholder = $is_password_encrypted
            ? __( '(guardado — dejar vacío para mantener)', 'ltms' )
            : ( $field['placeholder'] ?? '' );
    ?>
    <tr>
        <th scope="row"><label for="<?php echo esc_attr($key);?>"><?php echo esc_html($field['label']);?></label></th>
        <td>
        <?php if($field['type']==='checkbox'):?>
            <label><input type="checkbox" name="<?php echo esc_attr($key);?>" value="yes" <?php checked($value,'yes');?>> <?php echo esc_html($field['desc']??'');?></label>
        <?php elseif($field['type']==='password'):?>
            <input type="password" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($display_value);?>" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr($display_placeholder); ?>">
            <?php if(!empty($field['desc'])):?><p class="description"><?php echo esc_html($field['desc']);?></p><?php endif;?>
        <?php else:?>
            <input type="<?php echo esc_attr($field['type']);?>" name="<?php echo esc_attr($key);?>"
                   value="<?php echo esc_attr($value);?>" class="regular-text"
                   <?php echo !empty($field['readonly']) ? 'readonly style="background:#f5f5f5;"' : ''; ?>>
            <?php if(!empty($field['desc'])):?><p class="description"><?php echo esc_html($field['desc']);?></p><?php endif;?>
        <?php endif;?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody></table>
</div>
