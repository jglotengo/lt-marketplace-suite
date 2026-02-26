<?php
/**
 * LT Marketplace Suite - Fragmento de configuración para wp-config.php
 *
 * INSTRUCCIONES:
 * 1. Copia las constantes de abajo a tu wp-config.php ANTES de la línea:
 *    "¡Eso es todo! Deja de editar..." / "That's all, stop editing!"
 * 2. Reemplaza los valores placeholder con tus credenciales reales.
 * 3. NUNCA subas este archivo con credenciales reales a Git.
 *
 * @package LTMS
 * @version 1.5.0
 */

// ─────────────────────────────────────────────────────────────────────────────
// LTMS: CLAVE MAESTRA DE CIFRADO AES-256
// Genera una clave segura con: openssl rand -base64 32
// ─────────────────────────────────────────────────────────────────────────────
define( 'WP_LTMS_MASTER_KEY', 'REPLACE_WITH_64_CHAR_RANDOM_KEY_HERE_DO_NOT_USE_DEFAULT' );

// ─────────────────────────────────────────────────────────────────────────────
// LTMS: OPENPAY COLOMBIA
// Dashboard: https://dashboard.openpay.co
// ─────────────────────────────────────────────────────────────────────────────
define( 'WP_LTMS_OPENPAY_MERCHANT_ID_CO', 'mxxxxxxxxxxxxxxxxxxxxx' );
define( 'WP_LTMS_OPENPAY_PRIVATE_KEY_CO', 'sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' );
define( 'WP_LTMS_OPENPAY_PUBLIC_KEY_CO',  'pk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' );
define( 'WP_LTMS_OPENPAY_SANDBOX_CO',     true ); // false en producción

// ─────────────────────────────────────────────────────────────────────────────
// LTMS: OPENPAY MÉXICO
// Dashboard: https://dashboard.openpay.mx
// ─────────────────────────────────────────────────────────────────────────────
define( 'WP_LTMS_OPENPAY_MERCHANT_ID_MX', 'mxxxxxxxxxxxxxxxxxxxxx' );
define( 'WP_LTMS_OPENPAY_PRIVATE_KEY_MX', 'sk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' );
define( 'WP_LTMS_OPENPAY_PUBLIC_KEY_MX',  'pk_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' );
define( 'WP_LTMS_OPENPAY_SANDBOX_MX',     true );

// ─────────────────────────────────────────────────────────────────────────────
// LTMS: SIIGO (Facturación Electrónica DIAN - Colombia)
// Documentación: https://developer.siigo.com
// ─────────────────────────────────────────────────────────────────────────────
define( 'WP_LTMS_SIIGO_USERNAME',    'usuario@empresa.co' );
define( 'WP_LTMS_SIIGO_ACCESS_KEY',  'SIIGO_ACCESS_KEY_HERE' );
define( 'WP_LTMS_SIIGO_SANDBOX',     true );

// ─────────────────────────────────────────────────────────────────────────────
// LTMS: ADDI (BNPL - Compra Ahora Paga Después)
// Dashboard: https://comercios.addi.com
// ─────────────────────────────────────────────────────────────────────────────
define( 'WP_LTMS_ADDI_CLIENT_ID_CO',     'addi_client_id_co' );
define( 'WP_LTMS_ADDI_CLIENT_SECRET_CO', 'addi_secret_co' );
define( 'WP_LTMS_ADDI_CLIENT_ID_MX',     'addi_client_id_mx' );
define( 'WP_LTMS_ADDI_CLIENT_SECRET_MX', 'addi_secret_mx' );
define( 'WP_LTMS_ADDI_SANDBOX',          true );

// ─────────────────────────────────────────────────────────────────────────────
// LTMS: AVEONLINE (Logística / Envíos)
// Dashboard: https://app.aveonline.co
// ─────────────────────────────────────────────────────────────────────────────
define( 'WP_LTMS_AVEONLINE_API_KEY',    'aveonline_api_key' );
define( 'WP_LTMS_AVEONLINE_ACCOUNT_ID', 'aveonline_account_id' );
define( 'WP_LTMS_AVEONLINE_SANDBOX',    true );

// ─────────────────────────────────────────────────────────────────────────────
// LTMS: ZAPSIGN (Firma Electrónica de Contratos)
// Dashboard: https://app.zapsign.co
// ─────────────────────────────────────────────────────────────────────────────
define( 'WP_LTMS_ZAPSIGN_TOKEN',   'zapsign_api_token' );
define( 'WP_LTMS_ZAPSIGN_SANDBOX', true );

// ─────────────────────────────────────────────────────────────────────────────
// LTMS: TPTC (Red MLM)
// ─────────────────────────────────────────────────────────────────────────────
define( 'WP_LTMS_TPTC_API_KEY_CO', 'tptc_api_key_co' );
define( 'WP_LTMS_TPTC_API_KEY_MX', 'tptc_api_key_mx' );
define( 'WP_LTMS_TPTC_SANDBOX',    true );

// ─────────────────────────────────────────────────────────────────────────────
// LTMS: XCOVER (Seguro de Productos)
// Dashboard: https://partners.xcover.com
// ─────────────────────────────────────────────────────────────────────────────
define( 'WP_LTMS_XCOVER_PARTNER_CODE', 'xcover_partner_code' );
define( 'WP_LTMS_XCOVER_API_KEY',      'xcover_api_key' );
define( 'WP_LTMS_XCOVER_SANDBOX',      true );

// ─────────────────────────────────────────────────────────────────────────────
// LTMS: BACKBLAZE B2 (Almacenamiento de Documentos KYC)
// Dashboard: https://www.backblaze.com/b2/cloud-storage.html
// ─────────────────────────────────────────────────────────────────────────────
define( 'WP_LTMS_B2_KEY_ID',      'backblaze_key_id' );
define( 'WP_LTMS_B2_APP_KEY',     'backblaze_application_key' );
define( 'WP_LTMS_B2_BUCKET_NAME', 'ltms-kyc-documents' );
define( 'WP_LTMS_B2_BUCKET_ID',   'backblaze_bucket_id' );

// ─────────────────────────────────────────────────────────────────────────────
// LTMS: NOTIFICACIONES PUSH (Web Push VAPID)
// Genera claves con: web-push generate-vapid-keys
// ─────────────────────────────────────────────────────────────────────────────
define( 'WP_LTMS_VAPID_PUBLIC_KEY',  'VAPID_PUBLIC_KEY_BASE64URL' );
define( 'WP_LTMS_VAPID_PRIVATE_KEY', 'VAPID_PRIVATE_KEY_BASE64URL' );
define( 'WP_LTMS_VAPID_SUBJECT',     'mailto:admin@yoursite.com' );

// ─────────────────────────────────────────────────────────────────────────────
// WORDPRESS: Recomendaciones adicionales de seguridad
// ─────────────────────────────────────────────────────────────────────────────
define( 'FORCE_SSL_ADMIN', true );
define( 'DISALLOW_FILE_EDIT', true ); // Deshabilitar editor de archivos en admin
define( 'WP_AUTO_UPDATE_CORE', 'minor' );

// ─────────────────────────────────────────────────────────────────────────────
// WORDPRESS: Configuración de tabla de la base de datos
// Agrega el prefijo si es diferente al predeterminado 'wp_'
// ─────────────────────────────────────────────────────────────────────────────
// $table_prefix = 'ltms_'; // Descomenta si usas prefijo personalizado

// FIN DEL FRAGMENTO LTMS
// ─────────────────────────────────────────────────────────────────────────────
