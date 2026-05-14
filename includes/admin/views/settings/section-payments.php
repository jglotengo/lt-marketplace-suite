<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$groups = [
    'Openpay (Colombia)' => [
        'ltms_openpay_enabled'      => [ 'label' => 'Openpay Activo',       'type' => 'checkbox' ],
        'ltms_openpay_merchant_id'  => [ 'label' => 'Merchant ID',          'type' => 'text' ],
        'ltms_openpay_public_key'   => [ 'label' => 'Public Key',           'type' => 'text' ],
        'ltms_openpay_private_key'  => [ 'label' => 'Private Key',          'type' => 'password', 'desc' => 'Se guarda encriptado.' ],
        'ltms_openpay_pse_enabled'  => [ 'label' => 'PSE Activo',           'type' => 'checkbox' ],
        'ltms_openpay_webhook_token'=> [ 'label' => 'Token Webhook',        'type' => 'text' ],
    ],
    'Addi (BNPL — Compra Ahora Paga Después)' => [
        'ltms_addi_enabled'         => [ 'label' => 'Addi Activo',          'type' => 'checkbox' ],
        'ltms_addi_client_id'       => [ 'label' => 'Client ID',            'type' => 'text' ],
        'ltms_addi_client_secret'   => [ 'label' => 'Client Secret',        'type' => 'password' ],
        'ltms_addi_ally_slug'       => [ 'label' => 'Ally Slug',            'type' => 'text' ],
    ],
    'Stripe (Internacional)' => [
        'ltms_stripe_enabled'       => [ 'label' => 'Stripe Activo',        'type' => 'checkbox' ],
        'ltms_stripe_public_key'    => [ 'label' => 'Publishable Key',      'type' => 'text' ],
        'ltms_stripe_secret_key'    => [ 'label' => 'Secret Key',           'type' => 'password' ],
        'ltms_stripe_webhook_secret'=> [ 'label' => 'Webhook Secret',       'type' => 'password' ],
    ],
    'Openpay México' => [
        'ltms_openpay_mx_enabled'   => [ 'label' => 'Openpay MX Activo',   'type' => 'checkbox' ],
        'ltms_openpay_mx_merchant'  => [ 'label' => 'Merchant ID MX',      'type' => 'text' ],
        'ltms_openpay_mx_pub_key'   => [ 'label' => 'Public Key MX',       'type' => 'text' ],
        'ltms_openpay_mx_priv_key'  => [ 'label' => 'Private Key MX',      'type' => 'password' ],
    ],
];
?>
<div class="ltms-settings-section">
    <h2 style="margin-top:24px;">💳 Pasarelas de Pago</h2>
    <?php foreach ( $groups as $group_name => $fields ) : ?>
    <h3 style="margin:20px 0 4px;padding-bottom:6px;border-bottom:1px solid #ddd;"><?php echo esc_html($group_name); ?></h3>
    <table class="form-table" role="presentation"><tbody>
    <?php foreach ( $fields as $key => $field ) :
        $value = get_option($key, '');
    ?>
    <tr>
        <th scope="row"><label for="<?php echo esc_attr($key);?>"><?php echo esc_html($field['label']);?></label></th>
        <td>
        <?php if($field['type']==='checkbox'):?>
            <input type="checkbox" name="<?php echo esc_attr($key);?>" value="yes" <?php checked($value,'yes');?>>
        <?php elseif($field['type']==='password'):?>
            <input type="password" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($value);?>" class="regular-text" autocomplete="new-password">
            <?php if(!empty($field['desc'])):?><p class="description"><?php echo esc_html($field['desc']);?></p><?php endif;?>
        <?php else:?>
            <input type="text" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($value);?>" class="regular-text">
        <?php endif;?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody></table>
    <?php endforeach; ?>
</div>
