<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$fields = [
    // C-01 FIX: La clave correcta que lee el código de producción es ltms_platform_commission_rate.
    // Anteriormente la vista guardaba ltms_commission_rate, que nunca era leída.
    'ltms_platform_commission_rate' => [ 'label' => 'Comisión de la Plataforma (%)', 'type' => 'number', 'default' => '15', 'desc' => 'Porcentaje que retiene la plataforma por cada venta. El código espera un valor como porcentaje (ej: 10 = 10%).' ],
    // CS-03: tasas por tipo de producto — físico 10%, resto 15% por defecto
    'ltms_commission_physical' => [ 'label' => 'Comisión Producto Físico (%)',   'type' => 'number', 'default' => '10', 'desc' => 'Tasa para productos físicos (nivel 1 de la cascada). Ej: 10 = 10%.' ],
    'ltms_commission_digital'  => [ 'label' => 'Comisión Producto Digital (%)',  'type' => 'number', 'default' => '15', 'desc' => 'Tasa para productos digitales. Ej: 15 = 15%.' ],
    'ltms_commission_service'  => [ 'label' => 'Comisión Servicio (%)',          'type' => 'number', 'default' => '15', 'desc' => 'Tasa para servicios genéricos. Ej: 15 = 15%.' ],
    'ltms_commission_booking'  => [ 'label' => 'Comisión Turismo / Reserva (%)', 'type' => 'number', 'default' => '15', 'desc' => 'Tasa para alojamientos, tours y experiencias (productos bookable).' ],
    'ltms_min_payout_amount'        => [ 'label' => 'Monto Mínimo de Retiro (COP)', 'type' => 'number', 'default' => '50000' ],
    // C-04 NOTE: ltms_payout_schedule se guarda pero el cron usa wp_schedule_event con intervalos fijos.
    // Esta opción es informativa — el cron actual no la lee. Se mantiene para roadmap futuro.
    'ltms_payout_schedule'          => [ 'label' => 'Frecuencia de Pagos (referencial)', 'type' => 'select', 'default' => 'weekly', 'options' => [ 'daily' => 'Diario', 'weekly' => 'Semanal', 'biweekly' => 'Quincenal', 'monthly' => 'Mensual', 'manual' => 'Manual (solo admin)' ], 'desc' => 'El procesamiento automático de retiros ocurre diariamente por cron.' ],
    'ltms_mlm_enabled'              => [ 'label' => 'Red de Afiliados (MLM)',         'type' => 'checkbox', 'desc' => 'Activar sistema de comisiones por referido' ],
    // C-02 FIX: LTMS_Referral_Tree lee ltms_referral_rates (JSON array), no ltms_mlm_l1/l2_rate.
    // Se reemplaza el campo de texto por textarea JSON compatible con get_referral_rates().
    'ltms_referral_rates'           => [ 'label' => 'Tasas MLM por Nivel (JSON)', 'type' => 'textarea', 'default' => '[0.05,0.02]', 'desc' => 'Array JSON de tasas decimales por nivel. Ej: [0.05,0.02] = 5% nivel 1, 2% nivel 2. La plataforma retiene el resto.' ],
    'ltms_redi_enabled'             => [ 'label' => 'ReDi (Reventa Distribuida)',     'type' => 'checkbox', 'desc' => 'Permitir que vendedores adopten productos de otros' ],
    // C-03 NOTE: La tasa ReDi se configura por producto en _ltms_redi_rate, no globalmente.
    // Este campo es el default sugerido al crear un acuerdo ReDi, leído por LTMS_Business_Redi_Manager.
    'ltms_redi_default_rate'        => [ 'label' => 'Tasa ReDi por Defecto (%)',     'type' => 'number', 'default' => '15', 'desc' => 'Tasa sugerida al crear un acuerdo ReDi. Se guarda por producto en _ltms_redi_rate.' ],
    // CS-08: rango permitido para la tasa ReDi que propone el vendedor
    'ltms_redi_min_rate'            => [ 'label' => 'Tasa ReDi Mínima (%)',            'type' => 'number', 'default' => '5',  'desc' => 'Comisión mínima que el vendedor debe ofrecer al revendedor. Ej: 5 = 5%.' ],
    'ltms_redi_max_rate'            => [ 'label' => 'Tasa ReDi Máxima (%)',            'type' => 'number', 'default' => '40', 'desc' => 'Comisión máxima que el vendedor puede ofrecer al revendedor. Ej: 40 = 40%.' ],
];
?>
<div class="ltms-settings-section">
    <h2 style="margin-top:24px;">💰 Comisiones y Pagos</h2>
    <table class="form-table" role="presentation"><tbody>
    <?php foreach ( $fields as $key => $field ) :
        $raw_value = get_option( $key, $field['default'] ?? '' );
        // Campos _rate guardados como decimal (0.15) → mostrar como % (15) en el UI
        $is_rate_field = ( strpos( $key, '_rate' ) !== false || strpos( $key, '_percent' ) !== false )
                         && $key !== 'ltms_referral_rates';
        if ( $is_rate_field && $field['type'] === 'number' && is_numeric( $raw_value ) && (float) $raw_value < 1 && (float) $raw_value > 0 ) {
            $value = round( (float) $raw_value * 100, 4 );
        } else {
            $value = $raw_value;
        }
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
            <?php if(!empty($field['desc'])):?><p class="description"><?php echo esc_html($field['desc']);?></p><?php endif;?>
        <?php elseif ( $field['type'] === 'checkbox' ) : ?>
            <label><input type="checkbox" name="<?php echo esc_attr($key); ?>" value="yes" <?php checked($value,'yes'); ?>> <?php echo esc_html($field['desc']??''); ?></label>
        <?php elseif ( $field['type'] === 'textarea' ) : ?>
            <textarea name="<?php echo esc_attr($key); ?>" class="regular-text" rows="2"><?php echo esc_textarea($value); ?></textarea>
            <?php if(!empty($field['desc'])):?><p class="description"><?php echo esc_html($field['desc']);?></p><?php endif;?>
        <?php else : ?>
            <input type="number" step="0.01" min="0" name="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr($value); ?>" class="small-text">
            <?php if(!empty($field['desc'])):?><p class="description"><?php echo esc_html($field['desc']);?></p><?php endif;?>
        <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody></table>
</div>


