<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$fields = [
    'ltms_kyc_required'          => [ 'label' => 'KYC Obligatorio',             'type' => 'checkbox', 'desc' => 'Requerir verificación de identidad antes de permitir retiros' ],
    'ltms_kyc_auto_approve'      => [ 'label' => 'Auto-aprobación KYC',         'type' => 'checkbox', 'desc' => 'Aprobar KYC automáticamente (solo para pruebas)' ],
    'ltms_sagrilaft_enabled'     => [ 'label' => 'SAGRILAFT/SIPLAFT Activo',    'type' => 'checkbox', 'desc' => 'Habilitar cumplimiento normativo colombiano' ],
    'ltms_kyc_max_file_size_mb'  => [ 'label' => 'Tamaño Máximo Documento (MB)', 'type' => 'number', 'default' => '5' ],
    // K-01 FIX: clave corregida — payout-scheduler lee ltms_kyc_required_for_payout, no ltms_payout_kyc_required.
    'ltms_kyc_required_for_payout' => [ 'label' => 'KYC requerido para retiros', 'type' => 'checkbox', 'desc' => 'Bloquear retiros hasta que KYC sea aprobado' ],
];
?>
<div class="ltms-settings-section">
    <h2 style="margin-top:24px;">🪪 KYC / Compliance</h2>
    <table class="form-table" role="presentation"><tbody>
    <?php foreach ( $fields as $key => $field ) : $value = get_option($key, $field['default']??''); ?>
    <tr>
        <th scope="row"><?php echo esc_html($field['label']); ?></th>
        <td>
        <?php if($field['type']==='checkbox'):?>
            <label><input type="checkbox" name="<?php echo esc_attr($key);?>" value="yes" <?php checked($value,'yes');?>> <?php echo esc_html($field['desc']??'');?></label>
        <?php else:?>
            <input type="number" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($value);?>" class="small-text">
        <?php endif;?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody></table>
</div>
