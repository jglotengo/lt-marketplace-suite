<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LTMS_Auditor_Export {
    public static function generate_csv( array $args ): array {
        global $wpdb;
        $date_from = $args['date_from'] ?? '2000-01-01';
        $date_to   = $args['date_to']   ?? date('Y-m-d');
        $country   = $args['country']   ?? '';
        $limit     = intval( $args['limit'] ?? 100 );

        $where = "WHERE created_at BETWEEN %s AND %s";
        $params = [ $date_from . ' 00:00:00', $date_to . ' 23:59:59' ];
        if ( $country ) { $where .= " AND country_code = %s"; $params[] = $country; }

        $rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}lt_commissions $where ORDER BY id DESC LIMIT $limit", ...$params ),
            ARRAY_A
        );

        if ( empty( $rows ) ) return [ 'error' => 'Sin datos' ];

        $upload = wp_upload_dir();
        $file   = $upload['basedir'] . '/ltms-export-' . date('YmdHis') . '.csv';
        $fp     = fopen( $file, 'w' );
        fputcsv( $fp, array_keys( $rows[0] ) );
        foreach ( $rows as $row ) fputcsv( $fp, $row );
        fclose( $fp );

        return [ 'file' => $file, 'rows' => count( $rows ) ];
    }
}
