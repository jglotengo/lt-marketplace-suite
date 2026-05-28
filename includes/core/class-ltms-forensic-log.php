<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LTMS_Forensic_Log {
    public static function log( string $action, int $user_id = 0, string $ip = '' ): void {
        global $wpdb;
        if ( empty( $ip ) ) $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $wpdb->insert( $wpdb->prefix . 'lt_forensic_log', [
            'action'     => $action,
            'user_id'    => $user_id,
            'ip'         => $ip,
            'created_at' => current_time( 'mysql' ),
        ] );
    }
    public static function get_recent( int $limit = 10 ): array {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}lt_forensic_log ORDER BY id DESC LIMIT %d", $limit ),
            ARRAY_A
        ) ?: [];
    }
}
