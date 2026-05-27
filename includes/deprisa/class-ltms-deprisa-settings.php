<?php
if ( ! defined( 'ABSPATH' ) ) exit;
if ( class_exists( 'LTMS_Settings_Deprisa' ) ) return;
$_f = defined('LTMS_INCLUDES_DIR')
    ? LTMS_INCLUDES_DIR . 'settings/class-ltms-settings-deprisa.php'
    : __DIR__ . '/../settings/class-ltms-settings-deprisa.php';
if ( file_exists( $_f ) ) { require_once $_f; return; }
class LTMS_Settings_Deprisa {
    public static function register_tab( array $tabs ): array {
        $tabs['deprisa'] = __( 'Deprisa', 'ltms' ); return $tabs;
    }
    public static function render(): void {
        echo '<p>⚠️ Settings file not found.</p>';
    }
    public static function save(): void {}
    public static function ajax_test_connection(): void {
        wp_send_json_error(['message' => 'Settings file not found.']);
    }
    public static function init(): void {
        add_action('wp_ajax_ltms_deprisa_test_connection', [self::class, 'ajax_test_connection']);
    }
}
