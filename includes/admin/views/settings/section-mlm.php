<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$fields = [
    'ltms_mlm_enabled'            => [ 'label' => 'MLM Activo',                  'type' => 'checkbox', 'desc' => 'Activar sistema de referidos multi-nivel' ],
    'ltms_mlm_levels'             => [ 'label' => 'Niveles de Comisión',         'type' => 'select', 'default' => '2', 'options' => ['1'=>'1 nivel','2'=>'2 niveles','3'=>'3 niveles'] ],
    'ltms_mlm_l1_rate'            => [ 'label' => 'Comisión Nivel 1 (%)',        'type' => 'number', 'default' => '5' ],
    'ltms_mlm_l2_rate'            => [ 'label' => 'Comisión Nivel 2 (%)',        'type' => 'number', 'default' => '2' ],
    'ltms_mlm_l3_rate'            => [ 'label' => 'Comisión Nivel 3 (%)',        'type' => 'number', 'default' => '1' ],
    'ltms_mlm_min_sales_activate' => [ 'label' => 'Ventas mínimas para activar red', 'type' => 'number', 'default' => '1' ],
];
?>
<div class="ltms-settings-section">
    <h2 style="margin-top:24px;">🤝 Marketing / Red de Afiliados (MLM)</h2>
    <table class="form-table" role="presentation"><tbody>
    <?php foreach ( $fields as $key => $field ) : $value = get_option($key, $field['default']??''); ?>
    <tr>
        <th scope="row"><?php echo esc_html($field['label']); ?></th>
        <td>
        <?php if($field['type']==='checkbox'):?>
            <label><input type="checkbox" name="<?php echo esc_attr($key);?>" value="1" <?php checked($value,'1');?>> <?php echo esc_html($field['desc']??'');?></label>
        <?php elseif($field['type']==='select'):?>
            <select name="<?php echo esc_attr($key);?>">
                <?php foreach($field['options'] as $k=>$v):?><option value="<?php echo esc_attr($k);?>" <?php selected($value,$k);?>><?php echo esc_html($v);?></option><?php endforeach;?>
            </select>
        <?php else:?>
            <input type="number" step="0.1" min="0" max="100" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($value);?>" class="small-text"> %
        <?php endif;?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody></table>
</div>
