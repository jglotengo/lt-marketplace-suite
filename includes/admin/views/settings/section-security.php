<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$fields = [
    'ltms_waf_enabled'            => [ 'label' => 'WAF Activo',                  'type' => 'checkbox', 'desc' => 'Activar firewall de aplicación web' ],
    'ltms_rate_limit_enabled'     => [ 'label' => 'Rate Limiting Activo',        'type' => 'checkbox', 'desc' => 'Limitar peticiones por IP' ],
    'ltms_rate_limit_per_minute'  => [ 'label' => 'Peticiones por minuto (max)', 'type' => 'number', 'default' => '60' ],
    'ltms_ip_whitelist'           => [ 'label' => 'IPs en lista blanca',         'type' => 'textarea', 'desc' => 'Una IP por línea. Estas IPs no se bloquean.' ],
    'ltms_deploy_token'           => [ 'label' => 'Token de Deploy (webhook)',   'type' => 'text', 'default' => 'ltms_deploy_2026_s3cur3_t0k3n_x9z', 'desc' => 'Token para el webhook de deploy automático desde GitHub Actions.' ],
];
?>
<div class="ltms-settings-section">
    <h2 style="margin-top:24px;">🛡️ Seguridad</h2>
    <table class="form-table" role="presentation"><tbody>
    <?php foreach ( $fields as $key => $field ) : $value = get_option($key, $field['default']??''); ?>
    <tr>
        <th scope="row"><?php echo esc_html($field['label']); ?></th>
        <td>
        <?php if($field['type']==='checkbox'):?>
            <label><input type="checkbox" name="<?php echo esc_attr($key);?>" value="1" <?php checked($value,'1');?>> <?php echo esc_html($field['desc']??'');?></label>
        <?php elseif($field['type']==='textarea'):?>
            <textarea name="<?php echo esc_attr($key);?>" rows="4" class="regular-text"><?php echo esc_textarea($value);?></textarea>
            <?php if(!empty($field['desc'])):?><p class="description"><?php echo esc_html($field['desc']);?></p><?php endif;?>
        <?php else:?>
            <input type="text" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($value);?>" class="regular-text">
            <?php if(!empty($field['desc'])):?><p class="description"><?php echo esc_html($field['desc']);?></p><?php endif;?>
        <?php endif;?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody></table>
</div>
