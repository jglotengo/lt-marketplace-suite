<?php
if ( ! defined( 'ABSPATH' ) ) die;

echo "=== DEBUG SHORTCODE REGISTER ===\n\n";
echo "is_user_logged_in: " . (is_user_logged_in()?'SI':'NO') . "\n";
$u = wp_get_current_user();
echo "Usuario: " . $u->user_login . " | Roles: " . implode(',',$u->roles) . "\n";
echo "LTMS_INCLUDES_DIR: " . (defined('LTMS_INCLUDES_DIR')?LTMS_INCLUDES_DIR:'NO DEFINIDA') . "\n";

$view = LTMS_INCLUDES_DIR . 'frontend/views/vendor-parts/form-register.php';
echo "View existe: " . (file_exists($view)?'SI':'NO') . "\n";
echo "View path: $view\n\n";

// Simular el callback manualmente
ob_start();
if(file_exists($view)) { include $view; }
$result = ob_get_clean();
echo "Result length: " . strlen($result) . "\n";
echo "Result type: " . gettype($result) . "\n";
echo "Primeros 200: " . substr($result,0,200) . "\n";
