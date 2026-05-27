<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$fields = [
    'ltms_deprisa_enabled'             => [ 'label' => 'Deprisa Activo',               'type' => 'checkbox' ],
    'ltms_deprisa_sandbox'             => [ 'label' => 'Modo Sandbox (Pruebas)',        'type' => 'checkbox' ],
    'ltms_deprisa_username'            => [ 'label' => 'Usuario API (ej: WS00011111)', 'type' => 'text' ],
    'ltms_deprisa_password'            => [ 'label' => 'Contraseña API',               'type' => 'password' ],
    'ltms_deprisa_cliente_remitente'   => [ 'label' => 'Código Cliente Alertran',      'type' => 'text' ],
    'ltms_deprisa_centro_remitente'    => [ 'label' => 'Centro Remitente',             'type' => 'text', 'default' => '01' ],
    'ltms_deprisa_ciudad_remitente'    => [ 'label' => 'Ciudad Origen',                'type' => 'text', 'default' => 'BOGOTA' ],
    'ltms_deprisa_direccion_remitente' => [ 'label' => 'Dirección Origen',             'type' => 'text' ],
    'ltms_deprisa_cp_remitente'        => [ 'label' => 'Código Postal Origen',         'type' => 'text' ],
    'ltms_deprisa_nit_remitente'       => [ 'label' => 'NIT / Documento',              'type' => 'text' ],
    'ltms_deprisa_contacto_remitente'  => [ 'label' => 'Persona de Contacto',          'type' => 'text' ],
    'ltms_deprisa_telefono_remitente'  => [ 'label' => 'Teléfono de Contacto',         'type' => 'text' ],
    'ltms_deprisa_servicio_default'    => [ 'label' => 'Código Servicio (3005=Estándar / 3027=Contraentrega)', 'type' => 'text', 'default' => '3005' ],
];
?>
<div class="ltms-settings-section">
    <h2 style="margin-top:24px;">🚚 Deprisa (Latín Logistics) — Envíos Colombia</h2>
    <p style="color:#555;">Autenticación Basic Auth sobre REST/XML. Sandbox: <code>conectadoslatincopre.alertran.net</code> | Producción: <code>conectados.deprisa.com</code></p>
    <table class="form-table" role="presentation"><tbody>
    <?php foreach ( $fields as $key => $field ) : $value = get_option($key,$field['default']??''); ?>
    <tr>
        <th><?php echo esc_html($field['label']); ?></th>
        <td><?php if($field['type']==='checkbox'):?><input type="checkbox" name="<?php echo esc_attr($key);?>" value="yes" <?php checked($value,'yes');?>><?php elseif($field['type']==='password'):?><input type="password" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($value);?>" class="regular-text" autocomplete="new-password"><?php else:?><input type="text" name="<?php echo esc_attr($key);?>" value="<?php echo esc_attr($value);?>" class="regular-text"><?php endif;?></td>
    </tr>
    <?php endforeach; ?>
    </tbody></table>
</div>
