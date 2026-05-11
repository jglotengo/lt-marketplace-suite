<?php
/**
 * LTMS Driver Ajax Handler
 *
 * Gestiona las operaciones CRUD de domiciliarios propios del vendedor
 * y la configuración de entrega propia.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @version    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Driver_Ajax
 */
class LTMS_Driver_Ajax {

    use LTMS_Logger_Aware;

    /** Tipos de vehículo permitidos (coinciden con ENUM de la BD). */
    private const VEHICLE_TYPES = [ 'moto', 'bici', 'carro', 'pie', 'bicycle', 'walking', 'car' ];

    /** Límite de domiciliarios por vendedor. */
    private const MAX_DRIVERS = 50;

    public static function init(): void {
        $instance = new self();
        add_action( 'wp_ajax_ltms_save_driver',             [ $instance, 'ajax_save_driver' ] );
        add_action( 'wp_ajax_ltms_delete_driver',           [ $instance, 'ajax_delete_driver' ] );
        add_action( 'wp_ajax_ltms_toggle_driver_active',    [ $instance, 'ajax_toggle_active' ] );
        add_action( 'wp_ajax_ltms_toggle_driver_available', [ $instance, 'ajax_toggle_available' ] );
        add_action( 'wp_ajax_ltms_save_delivery_settings',  [ $instance, 'ajax_save_delivery_settings' ] );
    }

    // ── Guardar / crear domiciliario ─────────────────────────────────────

    public function ajax_save_driver(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $vendor_id = get_current_user_id();
        if ( ! $vendor_id || ! LTMS_Utils::is_ltms_vendor( $vendor_id ) ) {
            wp_send_json_error( __( 'No autorizado.', 'ltms' ), 401 );
            return;
        }

        $driver_id   = (int) ( $_POST['driver_id'] ?? 0 );
        $name        = sanitize_text_field( wp_unslash( $_POST['driver_name']           ?? '' ) );
        $phone       = sanitize_text_field( wp_unslash( $_POST['driver_phone']          ?? '' ) );
        $vehicle     = sanitize_key( $_POST['driver_vehicle_type']                      ?? '' );
        $doc_raw     = sanitize_text_field( wp_unslash( $_POST['driver_document_number'] ?? '' ) );
        $plate_raw   = sanitize_text_field( wp_unslash( $_POST['driver_vehicle_plate']  ?? '' ) );

        if ( ! $name || ! $phone || ! $vehicle ) {
            wp_send_json_error( __( 'Nombre, teléfono y tipo de vehículo son obligatorios.', 'ltms' ) );
            return;
        }

        if ( ! in_array( $vehicle, self::VEHICLE_TYPES, true ) ) {
            wp_send_json_error( __( 'Tipo de vehículo no válido.', 'ltms' ) );
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_drivers';

        // Encrypt sensitive fields if encryption helper available.
        $doc_stored   = class_exists( 'LTMS_Encryption' ) && $doc_raw   ? LTMS_Encryption::encrypt( $doc_raw )   : '';
        $plate_stored = class_exists( 'LTMS_Encryption' ) && $plate_raw ? LTMS_Encryption::encrypt( $plate_raw ) : $plate_raw;

        if ( $driver_id > 0 ) {
            // Update — verify ownership first.
            $existing_vendor = (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT vendor_id FROM `$table` WHERE id = %d", $driver_id )
            );
            if ( $existing_vendor !== $vendor_id ) {
                wp_send_json_error( __( 'No tienes permiso para editar este repartidor.', 'ltms' ), 403 );
                return;
            }

            $data   = [ 'name' => $name, 'phone' => $phone, 'vehicle_type' => $vehicle, 'updated_at' => current_time( 'mysql' ) ];
            $format = [ '%s', '%s', '%s', '%s' ];
            if ( $doc_stored )   { $data['document_number'] = $doc_stored;  $format[] = '%s'; }
            if ( $plate_stored ) { $data['vehicle_plate']   = $plate_stored; $format[] = '%s'; }

            $wpdb->update( $table, $data, [ 'id' => $driver_id ], $format, [ '%d' ] );
            wp_send_json_success( [ 'driver_id' => $driver_id, 'message' => __( 'Repartidor actualizado.', 'ltms' ) ] );
            return;
        }

        // Create — check limit.
        $count = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM `$table` WHERE vendor_id = %d", $vendor_id )
        );
        if ( $count >= self::MAX_DRIVERS ) {
            wp_send_json_error( sprintf( __( 'Límite de %d repartidores alcanzado.', 'ltms' ), self::MAX_DRIVERS ) );
            return;
        }

        $wpdb->insert(
            $table,
            [
                'vendor_id'       => $vendor_id,
                'name'            => $name,
                'phone'           => $phone,
                'vehicle_type'    => $vehicle,
                'document_number' => $doc_stored,
                'vehicle_plate'   => $plate_stored,
                'is_active'       => 1,
                'is_available'    => 1,
                'created_at'      => current_time( 'mysql' ),
                'updated_at'      => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
        );

        if ( ! $wpdb->insert_id ) {
            wp_send_json_error( __( 'Error al guardar el repartidor. Intenta de nuevo.', 'ltms' ) );
            return;
        }

        wp_send_json_success( [ 'driver_id' => (int) $wpdb->insert_id, 'message' => __( 'Repartidor agregado.', 'ltms' ) ] );
    }

    // ── Eliminar domiciliario ────────────────────────────────────────────

    public function ajax_delete_driver(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $vendor_id = get_current_user_id();
        if ( ! $vendor_id || ! LTMS_Utils::is_ltms_vendor( $vendor_id ) ) {
            wp_send_json_error( __( 'No autorizado.', 'ltms' ), 401 );
            return;
        }

        $driver_id = (int) ( $_POST['driver_id'] ?? 0 );
        if ( ! $driver_id ) {
            wp_send_json_error( __( 'ID de repartidor inválido.', 'ltms' ) );
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_drivers';

        // Verify ownership.
        $existing_vendor = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT vendor_id FROM `$table` WHERE id = %d", $driver_id )
        );
        if ( $existing_vendor !== $vendor_id ) {
            wp_send_json_error( __( 'No tienes permiso para eliminar este repartidor.', 'ltms' ), 403 );
            return;
        }

        $deleted = $wpdb->delete( $table, [ 'id' => $driver_id, 'vendor_id' => $vendor_id ], [ '%d', '%d' ] );
        if ( false === $deleted ) {
            wp_send_json_error( __( 'Error al eliminar el repartidor.', 'ltms' ) );
            return;
        }

        wp_send_json_success( __( 'Repartidor eliminado.', 'ltms' ) );
    }

    // ── Toggle activo ────────────────────────────────────────────────────

    public function ajax_toggle_active(): void {
        $this->toggle_flag( 'is_active' );
    }

    // ── Toggle disponible ────────────────────────────────────────────────

    public function ajax_toggle_available(): void {
        $this->toggle_flag( 'is_available' );
    }

    // ── Configuración de entrega propia ──────────────────────────────────

    public function ajax_save_delivery_settings(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $vendor_id = get_current_user_id();
        if ( ! $vendor_id || ! LTMS_Utils::is_ltms_vendor( $vendor_id ) ) {
            wp_send_json_error( __( 'No autorizado.', 'ltms' ), 401 );
            return;
        }

        $price   = abs( (float) ( $_POST['delivery_price']       ?? 0 ) );
        $eta     = max( 1, min( 480, (int) ( $_POST['delivery_eta_minutes'] ?? 60 ) ) );
        $zones   = sanitize_textarea_field( wp_unslash( $_POST['delivery_zones']   ?? '' ) );
        $message = sanitize_text_field( wp_unslash( $_POST['delivery_message']     ?? '' ) );

        update_user_meta( $vendor_id, 'ltms_own_delivery_price',        $price );
        update_user_meta( $vendor_id, 'ltms_own_delivery_eta_minutes',  $eta );
        update_user_meta( $vendor_id, 'ltms_own_delivery_zones',        $zones );
        update_user_meta( $vendor_id, 'ltms_own_delivery_message',      wp_kses_post( $message ) );

        wp_send_json_success( __( 'Configuración de entrega guardada.', 'ltms' ) );
    }

    // ── Helper privado ───────────────────────────────────────────────────

    private function toggle_flag( string $flag ): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        $vendor_id = get_current_user_id();
        if ( ! $vendor_id || ! LTMS_Utils::is_ltms_vendor( $vendor_id ) ) {
            wp_send_json_error( __( 'No autorizado.', 'ltms' ), 401 );
            return;
        }

        $driver_id = (int) ( $_POST['driver_id'] ?? 0 );
        if ( ! $driver_id ) {
            wp_send_json_error( __( 'ID de repartidor inválido.', 'ltms' ) );
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_vendor_drivers';

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT vendor_id, $flag FROM `$table` WHERE id = %d", $driver_id ), // phpcs:ignore
            ARRAY_A
        );

        if ( ! $row || (int) $row['vendor_id'] !== $vendor_id ) {
            wp_send_json_error( __( 'No tienes permiso para modificar este repartidor.', 'ltms' ), 403 );
            return;
        }

        $new_value = $row[ $flag ] ? 0 : 1;
        $wpdb->update(
            $table,
            [ $flag => $new_value, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $driver_id ],
            [ '%d', '%s' ],
            [ '%d' ]
        );

        wp_send_json_success( [ 'new_value' => $new_value ] );
    }
}
