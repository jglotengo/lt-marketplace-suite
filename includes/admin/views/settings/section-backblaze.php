<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$fields = [
    'ltms_backblaze_enabled'     => [ 'label' => 'Backblaze B2 Activo',    'type' => 'checkbox' ],
    'ltms_backblaze_key_id'      => [ 'label' => 'Key ID',                 'type' => 'text' ],
    'ltms_backblaze_app_key'     => [ 'label' => 'Application Key',        'type' => 'password' ],
    'ltms_backblaze_bucket_name' => [ 'label' => 'Bucket Name',            'type' => 'text' ],
    'ltms_backblaze_bucket_id'   => [ 'label' => 'Bucket ID',              'type' => 'text' ],
];
?>
<div class="ltms-settings-section">
    <h2 style="margin-top:24px;">☁️ Backblaze B2 — Almacenamiento de Archivos</h2>
    <table class="form-table" role="presentation"><tbody>
    <?php foreach ( $fields as $key => $field ) : $value = get_option($key,''); ?>
    <tr>
        <th><?php echo esc_html($field['label']); ?></th>
        <td><?php if($field['type']==='checkbox'):?><input type="checkbox" name="<?php echo esc_attr($key);?>" value="yes" <?php checked($value,'yes');?>><?php elseif($field['type']==='password'):?><input type="password" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($value);?>" class="regular-text" autocomplete="new-password"><?php else:?><input type="text" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($value);?>" class="regular-text"><?php endif;?></td>
    </tr>
    <?php endforeach; ?>
    </tbody></table>
</div>
