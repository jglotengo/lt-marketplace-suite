<?php
if ( ! defined( 'ABSPATH' ) ) die;
echo "ltms_vendor_register registrado: " . (shortcode_exists('ltms_vendor_register')?'SI':'NO') . "\n";
echo "ltms_vendor_login registrado: " . (shortcode_exists('ltms_vendor_login')?'SI':'NO') . "\n";
echo "ltms_sellers_landing registrado: " . (shortcode_exists('ltms_sellers_landing')?'SI':'NO') . "\n";

// Test render
$out = do_shortcode('[ltms_vendor_register]');
echo "Render ltms_vendor_register: " . strlen($out) . " chars\n";
echo "Primeros 100: " . substr($out,0,100) . "\n";
