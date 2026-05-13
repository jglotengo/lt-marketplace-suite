<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$fields = [
    'ltms_email_from_name'       => [ 'label' => 'Nombre del Remitente',     'type' => 'text',  'default' => get_bloginfo('name') ],
    'ltms_email_from_address'    => [ 'label' => 'Email del Remitente',      'type' => 'email', 'default' => get_option('admin_email') ],
    'ltms_email_vendor_approved' => [ 'label' => 'Email: Vendedor aprobado', 'type' => 'checkbox', 'desc' => 'Enviar email cuando se aprueba un vendedor' ],
    'ltms_email_payout_approved' => [ 'label' => 'Email: Retiro aprobado',   'type' => 'checkbox', 'desc' => 'Enviar email cuando se aprueba un retiro' ],
    'ltms_email_kyc_approved'    => [ 'label' => 'Email: KYC aprobado',      'type' => 'checkbox', 'desc' => 'Enviar email cuando se aprueba el KYC' ],
    'ltms_email_new_order'       => [ 'label' => 'Email: Nuevo pedido',      'type' => 'checkbox', 'desc' => 'Notificar al vendedor cuando llega un pedido' ],
];
?>
<div class="ltms-settings-section">
    <h2 style="margin-top:24px;">📧 Configuración de Emails</h2>
    <table class="form-table" role="presentation"><tbody>
    <?php foreach ( $fields as $key => $field ) : $value = get_option($key, $field['default']??'1'); ?>
    <tr>
        <th scope="row"><?php echo esc_html($field['label']); ?></th>
        <td>
        <?php if($field['type']==='checkbox'):?>
            <label><input type="checkbox" name="<?php echo esc_attr($key);?>" value="1" <?php checked($value,'1');?>> <?php echo esc_html($field['desc']??'');?></label>
        <?php else:?>
            <input type="<?php echo esc_attr($field['type']);?>" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($value);?>" class="regular-text">
        <?php endif;?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody></table>
</div>
