<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$fields = [
    'ltms_commission_rate'        => [ 'label' => 'Comisión de la Plataforma (%)', 'type' => 'number', 'default' => '10', 'desc' => 'Porcentaje que retiene la plataforma por cada venta.' ],
    'ltms_min_payout_amount'      => [ 'label' => 'Monto Mínimo de Retiro (COP)', 'type' => 'number', 'default' => '50000' ],
    'ltms_payout_schedule'        => [ 'label' => 'Frecuencia de Pagos',           'type' => 'select', 'default' => 'weekly', 'options' => [ 'daily' => 'Diario', 'weekly' => 'Semanal', 'biweekly' => 'Quincenal', 'monthly' => 'Mensual', 'manual' => 'Manual (solo admin)' ] ],
    'ltms_mlm_enabled'            => [ 'label' => 'Red de Afiliados (MLM)',         'type' => 'checkbox', 'desc' => 'Activar sistema de comisiones por referido' ],
    'ltms_mlm_l1_rate'            => [ 'label' => 'Comisión Nivel 1 (%)',           'type' => 'number', 'default' => '5' ],
    'ltms_mlm_l2_rate'            => [ 'label' => 'Comisión Nivel 2 (%)',           'type' => 'number', 'default' => '2' ],
    'ltms_redi_enabled'           => [ 'label' => 'ReDi (Reventa Distribuida)',     'type' => 'checkbox', 'desc' => 'Permitir que vendedores adopten productos de otros' ],
    'ltms_redi_default_rate'      => [ 'label' => 'Comisión ReDi por Defecto (%)', 'type' => 'number', 'default' => '15' ],
];
?>
<div class="ltms-settings-section">
    <h2 style="margin-top:24px;">💰 Comisiones y Pagos</h2>
    <table class="form-table" role="presentation"><tbody>
    <?php foreach ( $fields as $key => $field ) :
        $value = get_option( $key, $field['default'] ?? '' );
    ?>
    <tr>
        <th scope="row"><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($field['label']); ?></label></th>
        <td>
        <?php if ( $field['type'] === 'select' ) : ?>
            <select name="<?php echo esc_attr($key); ?>" class="regular-text">
                <?php foreach ( $field['options'] as $k => $v ) : ?>
                    <option value="<?php echo esc_attr($k); ?>" <?php selected($value,$k); ?>><?php echo esc_html($v); ?></option>
                <?php endforeach; ?>
            </select>
        <?php elseif ( $field['type'] === 'checkbox' ) : ?>
            <label><input type="checkbox" name="<?php echo esc_attr($key); ?>" value="yes" <?php checked($value,'yes'); ?>> <?php echo esc_html($field['desc']??''); ?></label>
        <?php else : ?>
            <input type="number" step="0.01" min="0" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>" class="small-text">
            <?php if(!empty($field['desc'])):?><p class="description"><?php echo esc_html($field['desc']);?></p><?php endif;?>
        <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody></table>
</div>
