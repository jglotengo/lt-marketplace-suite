<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$fields = [
    'ltms_xcover_enabled'        => [ 'label' => 'XCover Seguros Activo',  'type' => 'checkbox' ],
    'ltms_xcover_api_key'        => [ 'label' => 'API Key',                'type' => 'password' ],
    'ltms_xcover_partner_code'   => [ 'label' => 'Partner Code',           'type' => 'text' ],
    'ltms_xcover_sandbox'        => [ 'label' => 'Modo Sandbox',           'type' => 'checkbox' ],
];
?>
<div class="ltms-settings-section">
    <h2 style="margin-top:24px;">🛡️ Seguros XCover</h2>
    <table class="form-table" role="presentation"><tbody>
    <?php foreach ( $fields as $key => $field ) : $value = get_option($key,''); ?>
    <tr>
        <th><?php echo esc_html($field['label']); ?></th>
        <td><?php if($field['type']==='checkbox'):?><input type="checkbox" name="<?php echo esc_attr($key);?>" value="yes" <?php checked($value,'yes');?>><?php elseif($field['type']==='password'):?><input type="password" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($value);?>" class="regular-text" autocomplete="new-password"><?php else:?><input type="text" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($value);?>" class="regular-text"><?php endif;?></td>
    </tr>
    <?php endforeach; ?>
    </tbody></table>
</div>
