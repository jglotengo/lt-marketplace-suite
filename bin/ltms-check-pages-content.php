<?php
if ( ! defined( 'ABSPATH' ) ) die;

$slugs = ['registro-vendedor','login-vendedor','panel-vendedor'];
foreach($slugs as $slug) {
    $p = get_page_by_path($slug);
    if(!$p) { echo "$slug: NO EXISTE\n\n"; continue; }
    echo "=== $slug (ID:{$p->ID}) ===\n";
    echo "post_content: " . $p->post_content . "\n";
    $el_data = get_post_meta($p->ID,'_elementor_data',true);
    echo "_elementor_data: " . (empty($el_data)?'VACIO':'TIENE DATOS ('.strlen($el_data).' chars)') . "\n";
    $el_status = get_post_meta($p->ID,'_elementor_edit_mode',true);
    echo "_elementor_edit_mode: " . ($el_status?$el_status:'no set') . "\n\n";
}
