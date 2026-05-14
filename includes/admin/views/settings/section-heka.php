<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$fields = [
    'ltms_heka_enabled'          => [ 'label' => 'Heka Entrega Activo',    'type' => 'checkbox' ],
    'ltms_heka_api_key'          => [ 'label' => 'API Key',                'type' => 'password' ],
    'ltms_heka_sender_city'      => [ 'label' => 'Ciudad de origen',       'type' => 'text', 'default' => 'Bogotá' ],
];
?>
<div class="ltms-settings-section">
    <h2 style="margin-top:24px;">📦 Heka Entrega — Envíos Colombia</h2>
    <table class="form-table" role="presentation"><tbody>
    <?php foreach ( $fields as $key => $field ) : $value = get_option($key,$field['default']??''); ?>
    <tr>
        <th><?php echo esc_html($field['label']); ?></th>
        <td><?php if($field['type']==='checkbox'):?><input type="checkbox" name="<?php echo esc_attr($key);?>" value="yes" <?php checked($value,'yes');?>><?php elseif($field['type']==='password'):?><input type="password" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($value);?>" class="regular-text" autocomplete="new-password"><?php else:?><input type="text" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($value);?>" class="regular-text"><?php endif;?></td>
    </tr>
    <?php endforeach; ?>
    </tbody></table>
</div>
