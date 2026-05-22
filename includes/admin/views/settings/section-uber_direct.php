<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$fields = [
    'ltms_uber_direct_enabled'          => [ 'label' => 'Uber Direct Activo',     'type' => 'checkbox' ],
    'ltms_uber_direct_client_id'        => [ 'label' => 'Client ID',              'type' => 'text' ],
    'ltms_uber_direct_client_secret'    => [ 'label' => 'Client Secret',          'type' => 'password' ],
    'ltms_uber_direct_customer_id'      => [ 'label' => 'Customer ID',            'type' => 'text' ],
    'ltms_uber_direct_sandbox'          => [ 'label' => 'Modo Sandbox',           'type' => 'checkbox' ],
];
?>
<div class="ltms-settings-section">
    <h2 style="margin-top:24px;">🚗 Uber Direct — Logística</h2>
    <table class="form-table" role="presentation"><tbody>
    <?php foreach ( $fields as $key => $field ) : $value = get_option($key,''); ?>
    <tr>
        <th><?php echo esc_html($field['label']); ?></th>
        <td><?php if($field['type']==='checkbox'):?><input type="checkbox" name="<?php echo esc_attr($key);?>" value="yes" <?php checked($value,'yes');?>><?php elseif($field['type']==='password'):?><input type="password" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($value);?>" class="regular-text" autocomplete="new-password"><?php else:?><input type="text" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($value);?>" class="regular-text"><?php endif;?></td>
    </tr>
    <?php endforeach; ?>
    </tbody></table>
</div>
