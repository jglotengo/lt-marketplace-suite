<?php
/**
 * LT Marketplace Suite — Fragmento para wp-config.php
 *
 * INSTRUCCIONES:
 * 1. Pega este bloque en tu wp-config.php ANTES de la línea:
 *    /* That's all, stop editing! * /
 * 2. La LTMS_ENCRYPTION_KEY ya viene generada de forma segura.
 *    ⚠️  IMPORTANTE: Una vez que el plugin cifre datos con esta clave,
 *    NO la cambies jamás — los registros KYC, cuentas bancarias y NIT/RFC
 *    existentes se volverán ilegibles.
 * 3. Completa las credenciales reales de cada servicio antes de activar.
 *
 * @generated 2025 — LTMS v2.0.0-proships
 */

// ─────────────────────────────────────────────────────────────────────────────
// LTMS: CLAVE MAESTRA DE CIFRADO AES-256  ← OBLIGATORIA
// ─────────────────────────────────────────────────────────────────────────────
define( 'LTMS_ENCRYPTION_KEY', 'HZ1l8b5Twy8aPSuqeSawlB20knF4ZPo2BBqKgKRUdFWgZBsGRzCb5dUtdHW6ygE1' );

// ─────────────────────────────────────────────────────────────────────────────
// LTMS: PROXY / CDN DE CONFIANZA (WAF IP Resolution)
// Si estás detrás de Cloudflare, AWS ALB u otro proxy, declara sus IPs/CIDR.
// Ejemplo Cloudflare: '173.245.48.0/20,103.21.244.0/22,103.22.200.0/22'
// Sin proxy (hosting directo): dejar cadena vacía ''
// ─────────────────────────────────────────────────────────────────────────────
define( 'LTMS_TRUSTED_PROXY_IPS', '' );

// ─────────────────────────────────────────────────────────────────────────────
// LTMS: OPENPAY COLOMBIA
// Dashboard: https://dashboard.openpay.co
// ─────────────────────────────────────────────────────────────────────────────
define( 'WP_LTMS_OPENPAY_MERCHANT_ID_CO', 'REEMPLAZAR_CON_TU_MERCHANT_ID' );
define( 'WP_LTMS_OPENPAY_PRIVATE_KEY_CO', 'REEMPLAZAR_CON_TU_PRIVATE_KEY' );
define( 'WP_LTMS_OPENPAY_PUBLIC_KEY_CO',  'REEMPLAZAR_CON_TU_PUBLIC_KEY' );
define( 'WP_LTMS_OPENPAY_SANDBOX_CO',     true ); // Cambiar a false en producción

// ─────────────────────────────────────────────────────────────────────────────
// LTMS: SIIGO (Facturación Electrónica DIAN - Colombia)
// Documentación: https://developer.siigo.com
// ─────────────────────────────────────────────────────────────────────────────
define( 'WP_LTMS_SIIGO_USERNAME',   'REEMPLAZAR_usuario@empresa.co' );
define( 'WP_LTMS_SIIGO_ACCESS_KEY', 'REEMPLAZAR_SIIGO_ACCESS_KEY' );
define( 'WP_LTMS_SIIGO_SANDBOX',    true );

// ─────────────────────────────────────────────────────────────────────────────
// LTMS: ADDI (Compra Ahora Paga Después)
// ─────────────────────────────────────────────────────────────────────────────
define( 'WP_LTMS_ADDI_CLIENT_ID_CO',     'REEMPLAZAR' );
define( 'WP_LTMS_ADDI_CLIENT_SECRET_CO', 'REEMPLAZAR' );
define( 'WP_LTMS_ADDI_SANDBOX',          true );

// ─────────────────────────────────────────────────────────────────────────────
// LTMS: AVEONLINE (Logística / Envíos)
// ─────────────────────────────────────────────────────────────────────────────
define( 'WP_LTMS_AVEONLINE_API_KEY',    'REEMPLAZAR' );
define( 'WP_LTMS_AVEONLINE_ACCOUNT_ID', 'REEMPLAZAR' );
define( 'WP_LTMS_AVEONLINE_SANDBOX',    true );

// ─────────────────────────────────────────────────────────────────────────────
// LTMS: ZAPSIGN (Firma Electrónica)
// ─────────────────────────────────────────────────────────────────────────────
define( 'WP_LTMS_ZAPSIGN_TOKEN',   'REEMPLAZAR' );
define( 'WP_LTMS_ZAPSIGN_SANDBOX', true );

// ─────────────────────────────────────────────────────────────────────────────
// LTMS: BACKBLAZE B2 (Almacenamiento documentos KYC)
// ─────────────────────────────────────────────────────────────────────────────
define( 'WP_LTMS_B2_KEY_ID',      'REEMPLAZAR' );
define( 'WP_LTMS_B2_APP_KEY',     'REEMPLAZAR' );
define( 'WP_LTMS_B2_BUCKET_NAME', 'ltms-kyc-documents' );
define( 'WP_LTMS_B2_BUCKET_ID',   'REEMPLAZAR' );

// ─────────────────────────────────────────────────────────────────────────────
// WORDPRESS: Seguridad adicional recomendada
// ─────────────────────────────────────────────────────────────────────────────
define( 'FORCE_SSL_ADMIN',      true );
define( 'DISALLOW_FILE_EDIT',   true );  // Bloquea editor de temas/plugins en admin
define( 'WP_AUTO_UPDATE_CORE',  'minor' );
