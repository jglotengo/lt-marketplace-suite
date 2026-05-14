<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$fields = [
    'ltms_siigo_enabled'        => [ 'label' => 'Siigo Activo',                'type' => 'checkbox', 'desc' => 'Activar integración con Siigo ERP' ],
    'ltms_siigo_username'       => [ 'label' => 'Usuario (email)',              'type' => 'email' ],
    'ltms_siigo_password'       => [ 'label' => 'Contraseña / Access Key',     'type' => 'password', 'desc' => 'Se guarda encriptado.' ],
    'ltms_siigo_account_id'     => [ 'label' => 'Account ID (Partner)',        'type' => 'text' ],
    'ltms_siigo_document_type'  => [ 'label' => 'Tipo Documento Factura',      'type' => 'text', 'default' => 'FV', 'desc' => 'Ej: FV = Factura de Venta' ],
    'ltms_siigo_seller_id'      => [ 'label' => 'ID Vendedor Siigo',           'type' => 'text', 'desc' => 'ID del vendedor por defecto en Siigo' ],
    'ltms_siigo_payment_method' => [ 'label' => 'ID Método de Pago Siigo',    'type' => 'text', 'default' => '5396' ],
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
                   <?php echo !empty($field['readonly']) ? 'readonly style="background:#f5f5f5;"' : ''; ?>>
            <?php if(!empty($field['desc'])):?><p class="description"><?php echo esc_html($field['desc']);?></p><?php endif;?>
        <?php endif;?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody></table>
</div>
