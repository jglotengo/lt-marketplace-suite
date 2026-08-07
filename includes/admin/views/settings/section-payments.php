<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// CICLO22-P1-AD-SET-114 FIX: el handler central sanitize_settings()
// (class-ltms-admin-settings.php:115) cifra ltms_openpay_private_key y
// ltms_addi_client_secret con AES-256 via LTMS_Core_Security::encrypt() — los
// valores cifrados tienen prefijo 'v1:'. Si el valor crudo (incl. el hash
// 'v1:...') se muestra en el atributo value= del input password, queda expuesto
// en el DOM admin (visible via DevTools o capturado por password managers). El
// renderer generico de html-admin-settings.php:290 ya aplica el patron correcto:
// vaciar el value cuando detecta prefijo 'v1:'. Este view custom (renderer
// dinamico local con $groups → $fields → campo) NO replicaba ese patron — lo
// agrego aqui para cerrar el leak de las credenciales Openpay + Addi en el DOM.
// Impacto: ltms_openpay_private_key permite operar reembolsos/capturas en la
// pasarela Openpay Colombia/MX; ltms_addi_client_secret es credencial OAuth del
// BNPL Addi (puede initiar financiamiento). Ambos leaked por el mismo view.
// Patron identico al aplicado en section-zapsign.php AD-SET-107,
// section-google_oauth.php AD-SET-108, section-backblaze.php AD-SET-112,
// section-siigo.php AD-SET-113 (mismo ciclo). NOTA: los campos password de
// Stripe (ltms_stripe_secret_key, ltms_stripe_webhook_secret) y Openpay MX
// (ltms_openpay_mx_priv_key) NO estan en $encrypted_fields del handler —
// presumiblemente se guardan en claro (legacy). El fix C22 no los toca (no hay
// hash v1: en sus values). Ese es un hallazgo P2 separado (AD-SET-116) — ver
// LECCIONES_APRENDIDAS.md.
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
        // AD-SET-114: si el campo es password y el valor ya esta cifrado (prefijo
        // 'v1:'), vaciar el value para no exponer el hash en el DOM. Aplica a
        // ltms_openpay_private_key + ltms_addi_client_secret (listados en
        // $encrypted_fields del handler central). Los campos password no-cifrados
        // (Stripe, openpay_mx) tienen valor vacio o texto plano — el strpos v1:
        // no matchea, se muestra como antes. El handler mantiene el valor cifrado
        // original si el input llega vacio (linea 124-130).
        $is_password_encrypted = ( ($field['type'] ?? '') === 'password' )
            && is_string( $value ) && strpos( $value, 'v1:' ) === 0;
        $display_value = $is_password_encrypted ? '' : $value;
        $display_placeholder = $is_password_encrypted
            ? __( '(guardado — dejar vacío para mantener)', 'ltms' )
            : ( $field['placeholder'] ?? '' );
    ?>
    <tr>
        <th scope="row"><label for="<?php echo esc_attr($key);?>"><?php echo esc_html($field['label']);?></label></th>
        <td>
        <?php if($field['type']==='checkbox'):?>
            <input type="checkbox" name="<?php echo esc_attr($key);?>" value="yes" <?php checked($value,'yes');?>>
        <?php elseif($field['type']==='password'):?>
            <input type="password" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($display_value);?>" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr($display_placeholder); ?>">
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
