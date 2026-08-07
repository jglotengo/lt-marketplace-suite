<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// CICLO22-P1-AD-SET-112 FIX: el handler central sanitize_settings()
// (class-ltms-admin-settings.php:117) cifra ltms_backblaze_app_key con AES-256 via
// LTMS_Core_Security::encrypt() — los valores cifrados tienen prefijo 'v1:'. Si el
// valor crudo (incl. el hash 'v1:...') se muestra en el atributo value= del input
// password, queda expuesto en el DOM admin (visible via DevTools o capturado por
// password managers). El renderer generico de html-admin-settings.php:290 ya aplica
// el patron correcto: vaciar el value cuando detecta prefijo 'v1:'. Este view custom
// (renderer dinamico local con $fields + foreach) NO replicaba ese patron — lo
// agrego aqui para cerrar el leak del Application Key Backblaze B2 en el DOM. El
// Application Key B2 concede acceso S3 a los buckets de KYC (.documentos identidad)
// y contratos ZapSign — leak critico. Patron identico al aplicado en
// section-zapsign.php AD-SET-107, section-google_oauth.php AD-SET-108,
// section-siigo.php AD-SET-113, section-payments.php AD-SET-114 (mismo ciclo).
// El checkbox reset + sanitize_text_field del handler mantienen el valor cifrado
// existente si el admin deja el campo vacio.
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
    <?php foreach ( $fields as $key => $field ) :
        $value = get_option($key,'');
        // AD-SET-112: si el campo es password y el valor ya esta cifrado (prefijo 'v1:'),
        // vaciar el value para no exponer el hash en el DOM. El handler mantiene el
        // valor original si el input llega vacio (linea 124-130 del handler central).
        $is_password_encrypted = ( ($field['type'] ?? '') === 'password' )
            && is_string( $value ) && strpos( $value, 'v1:' ) === 0;
        $display_value = $is_password_encrypted ? '' : $value;
        $display_placeholder = $is_password_encrypted
            ? __( '(guardado — dejar vacío para mantener)', 'ltms' )
            : ( $field['placeholder'] ?? '' );
    ?>
    <tr>
        <th><?php echo esc_html($field['label']); ?></th>
        <td>
        <?php if($field['type']==='checkbox'): ?>
            <input type="checkbox" name="<?php echo esc_attr($key);?>" value="yes" <?php checked($value,'yes');?>>
        <?php elseif($field['type']==='password'): ?>
            <input type="password" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($display_value);?>" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr($display_placeholder); ?>">
        <?php else: ?>
            <input type="text" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($value);?>" class="regular-text"
                   placeholder="<?php echo esc_attr($field['placeholder'] ?? ''); ?>">
        <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody></table>
</div>
