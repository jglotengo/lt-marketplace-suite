<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$fields = [
    'ltms_backblaze_enabled'          => [ 'label' => 'Backblaze B2 Activo',               'type' => 'checkbox' ],
    'ltms_backblaze_key_id'           => [ 'label' => 'Key ID',                             'type' => 'text' ],
    'ltms_backblaze_app_key'          => [ 'label' => 'Application Key',                    'type' => 'password' ],
    'ltms_backblaze_endpoint'         => [ 'label' => 'Endpoint',                           'type' => 'text',     'placeholder' => 'https://s3.us-east-005.backblazeb2.com' ],
    'ltms_backblaze_bucket_name'      => [ 'label' => 'Bucket KYC (documentos identidad)',  'type' => 'text',     'placeholder' => 'lotengo-kyc-docs' ],
    'ltms_backblaze_bucket_id'        => [ 'label' => 'Bucket ID (KYC)',                    'type' => 'text',     'placeholder' => 'f45d874aa95c34a69fee0219' ],
    'ltms_backblaze_contratos_bucket' => [ 'label' => 'Bucket Contratos (ZapSign PDF)',     'type' => 'text',     'placeholder' => 'lotengo-contratos' ],
    'ltms_backblaze_marketing_bucket' => [ 'label' => 'Bucket Marketing (banners/flyers)',  'type' => 'text',     'placeholder' => 'lotengo-marketing' ],
    'ltms_backblaze_default_bucket'   => [ 'label' => 'Bucket por defecto (health check)',  'type' => 'text',     'placeholder' => 'lotengo-contratos' ],
];
?>
<div class="ltms-settings-section">
    <h2 style="margin-top:24px;">☁️ Backblaze B2 — Almacenamiento de Archivos</h2>
    <p style="color:#6b7280;font-size:0.875rem;margin-bottom:16px;">
        Endpoint: <code>https://s3.us-east-005.backblazeb2.com</code> &nbsp;|&nbsp;
        Key ID: <code>0054d7a9c46fe290000000001</code>
    </p>
    <table class="form-table" role="presentation"><tbody>
    <?php foreach ( $fields as $key => $field ) : $value = get_option($key,''); ?>
    <tr>
        <th><?php echo esc_html($field['label']); ?></th>
        <td>
        <?php if($field['type']==='checkbox'): ?>
            <input type="checkbox" name="<?php echo esc_attr($key);?>" value="yes" <?php checked($value,'yes');?>>
        <?php elseif($field['type']==='password'): ?>
            <input type="password" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($value);?>" class="regular-text" autocomplete="new-password">
        <?php else: ?>
            <input type="text" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($value);?>" class="regular-text"
                   placeholder="<?php echo esc_attr($field['placeholder'] ?? ''); ?>">
        <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody></table>
</div>
