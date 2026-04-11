<?php
class LTMS_Frontend_Live_Search {

    /**
     * Registra los hooks.
     *
     * @return void
     */
    public static function init(): void {
        $instance = new self();
        add_action( 'wp_ajax_ltms_live_search',        [ $instance, 'handle_search' ] );
        add_action( 'wp_ajax_nopriv_ltms_live_search', [ $instance, 'handle_search' ] );
    }

    /**
     * AJAX: ejecuta la búsqueda y devuelve resultados JSON.
     *
     * @return void
     */
    public function handle_search(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $query  = sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) );
        $type   = sanitize_key( $_POST['type'] ?? 'products' );
        $limit  = min( 20, absint( $_POST['limit'] ?? 10 ) );

        if ( mb_strlen( $query ) < 2 ) {
            wp_send_json_success( [ 'results' => [] ] );
        }

        $results = [];

        switch ( $type ) {
            case 'products':
                $results = $this->search_products( $query, $limit );
                break;
            case 'vendors':
                $results = $this->search_vendors( $query, $limit );
                break;
            case 'orders':
                if ( is_user_logged_in() ) {
                    $results = $this->search_orders( $query, $limit, get_current_user_id() );
                }
                break;
        }

        wp_send_json_success( [ 'results' => $results ] );
    }

    private function search_products( string $q, int $limit ): array {
        $products = wc_get_products( [
            'status' => 'publish',
            's'      => $q,
            'limit'  => $limit,
        ] );

        return array_map( fn( $p ) => [
            'id'    => $p->get_id(),
            'label' => $p->get_name(),
            'price' => wc_price( $p->get_price() ),
            'url'   => get_permalink( $p->get_id() ),
            'type'  => 'product',
        ], $products );
    }

    private function search_vendors( string $q, int $limit ): array {
        $users = get_users( [
            'role__in'   => [ 'ltms_vendor', 'ltms_vendor_premium' ],
            'search'     => '*' . esc_attr( $q ) . '*',
            'search_columns' => [ 'display_name', 'user_email' ],
            'number'     => $limit,
        ] );

        return array_map( fn( $u ) => [
            'id'    => $u->ID,
            'label' => get_user_meta( $u->ID, 'ltms_store_name', true ) ?: $u->display_name,
            'url'   => '#',
            'type'  => 'vendor',
        ], $users );
    }

    private function search_orders( string $q, int $limit, int $vendor_id ): array {
        $orders = wc_get_orders( [
            'limit'      => $limit,
            'search'     => $q,
            'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery
                [
                    'key'   => '_ltms_vendor_id',
                    'value' => $vendor_id,
                    'type'  => 'NUMERIC',
                ],
            ],
        ] );

        return array_map( fn( $o ) => [
            'id'     => $o->get_id(),
            'label'  => sprintf( '#%s — %s', $o->get_order_number(), $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
            'status' => $o->get_status(),
            'type'   => 'order',
        ], $orders );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// VENDOR SETTINGS SAVER
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Class LTMS_Vendor_Settings_Saver
 *
 * Guarda la configuración del perfil del vendedor desde el dashboard frontend.
 * Acción AJAX: ltms_save_vendor_profile
 *
 * Nota: ltms_save_vendor_settings está registrada en LTMS_Dashboard_Logic.
 * Esta clase maneja ltms_save_vendor_profile para no generar conflicto.
 */
