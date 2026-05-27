<?php
/**
 * LTMS — Loader de la integración Deprisa
 *
 * Incluye todas las clases Deprisa y registra sus hooks en WordPress /
 * WooCommerce. Llamar este archivo desde el bootstrap del plugin principal:
 *
 *   require_once plugin_dir_path(__FILE__) . 'includes/ltms-deprisa-loader.php';
 *
 * Estructura de archivos asumida (relativa al plugin):
 *
 *   includes/
 *     api/         class-ltms-api-deprisa.php
 *     business/    class-ltms-deprisa-shipping.php
 *     settings/    class-ltms-settings-deprisa.php
 *     admin/       class-ltms-deprisa-order-metabox.php
 *     shipping/    class-ltms-deprisa-shipping-method.php
 *
 * @package LTMS
 * @since   1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ======================================================================
   1. CARGAR CLASES
   ====================================================================== */

$ltms_deprisa_base = plugin_dir_path( __FILE__ );

require_once $ltms_deprisa_base . 'api/class-ltms-api-deprisa.php';
require_once $ltms_deprisa_base . 'business/class-ltms-deprisa-shipping.php';
require_once $ltms_deprisa_base . 'settings/class-ltms-settings-deprisa.php';
require_once $ltms_deprisa_base . 'admin/class-ltms-deprisa-order-metabox.php';
require_once $ltms_deprisa_base . 'shipping/class-ltms-deprisa-shipping-method.php';

/* ======================================================================
   2. PANEL DE AJUSTES — pestaña Deprisa en LTMS Settings
   ====================================================================== */

// Agregar la pestaña al panel LTMS (si tu plugin usa estos filtros/acciones)
add_filter( 'ltms_settings_tabs',        [ 'LTMS_Settings_Deprisa', 'register_tab'  ] );
add_action( 'ltms_settings_tab_deprisa', [ 'LTMS_Settings_Deprisa', 'render' ] );
add_action( 'ltms_settings_save_deprisa',[ 'LTMS_Settings_Deprisa', 'save'   ] );

// AJAX: test de conexión desde el panel
add_action( 'wp_ajax_ltms_deprisa_test_connection', [ 'LTMS_Settings_Deprisa', 'ajax_test_connection' ] );

/* ======================================================================
   3. METABOX EN PEDIDO WOOCOMMERCE
   ====================================================================== */

LTMS_Deprisa_Order_Metabox::init();

/* ======================================================================
   4. MÉTODO DE ENVÍO WOOCOMMERCE
   ====================================================================== */

// Registrar la clase del método de envío en WooCommerce
add_filter( 'woocommerce_shipping_methods', function( array $methods ): array {
	$methods['ltms_deprisa'] = 'LTMS_Deprisa_Shipping_Method';
	return $methods;
} );

/* ======================================================================
   5. COLUMNA "GUÍA DEPRISA" EN LA LISTA DE PEDIDOS
   ====================================================================== */

// Agregar columna en la lista de pedidos (solo si la integración está activa)
if ( (bool) get_option( 'ltms_deprisa_enabled', false ) ) {

	// Soporte clásico (WP_List_Table)
	add_filter( 'manage_edit-shop_order_columns', function( array $columns ): array {
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === 'order_status' ) {
				$new['deprisa_guia'] = '📦 Guía';
			}
		}
		return $new;
	} );

	add_action( 'manage_shop_order_posts_custom_column', function( string $column, int $post_id ): void {
		if ( $column !== 'deprisa_guia' ) return;
		$order = wc_get_order( $post_id );
		$guia  = $order ? $order->get_meta( '_deprisa_guia' ) : '';
		if ( $guia ) {
			echo '<code style="font-size:11px;">' . esc_html( $guia ) . '</code>';
		} else {
			echo '<span style="color:#ccc;">—</span>';
		}
	}, 10, 2 );

	// Soporte HPOS (WooCommerce > 8.x)
	add_filter( 'woocommerce_shop_order_list_table_columns', function( array $columns ): array {
		$new = [];
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === 'order_status' ) {
				$new['deprisa_guia'] = '📦 Guía';
			}
		}
		return $new;
	} );

	add_action( 'woocommerce_shop_order_list_table_custom_column', function( string $column, \WC_Order $order ): void {
		if ( $column !== 'deprisa_guia' ) return;
		$guia = $order->get_meta( '_deprisa_guia' );
		if ( $guia ) {
			echo '<code style="font-size:11px;">' . esc_html( $guia ) . '</code>';
		} else {
			echo '<span style="color:#ccc;">—</span>';
		}
	}, 10, 2 );
}

/* ======================================================================
   6. ACCIÓN BULK: "Crear guías Deprisa" en la lista de pedidos
   ====================================================================== */

// Agregar la acción bulk en ambos contextos (clásico + HPOS)
add_filter( 'bulk_actions-edit-shop_order', function( array $actions ): array {
	$actions['ltms_deprisa_crear_guias_bulk'] = '📦 Deprisa — Crear guías';
	return $actions;
} );
add_filter( 'bulk_actions-woocommerce_page_wc-orders', function( array $actions ): array {
	$actions['ltms_deprisa_crear_guias_bulk'] = '📦 Deprisa — Crear guías';
	return $actions;
} );

// Handler de la acción bulk
add_filter( 'handle_bulk_actions-edit-shop_order', 'ltms_deprisa_handle_bulk_action', 10, 3 );
add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', 'ltms_deprisa_handle_bulk_action', 10, 3 );

function ltms_deprisa_handle_bulk_action( string $redirect_url, string $action, array $order_ids ): string {
	if ( $action !== 'ltms_deprisa_crear_guias_bulk' ) {
		return $redirect_url;
	}

	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		return $redirect_url;
	}

	$shipping = new LTMS_Deprisa_Shipping();
	$ok       = 0;
	$fail     = 0;

	foreach ( $order_ids as $order_id ) {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) { $fail++; continue; }

		// Solo crear guía si aún no tiene una
		if ( $order->get_meta( '_deprisa_guia' ) ) { $ok++; continue; }

		$result = $shipping->crear_guia_para_pedido( (int) $order_id );
		$result['ok'] ? $ok++ : $fail++;
	}

	$redirect_url = add_query_arg( [
		'ltms_deprisa_bulk_ok'   => $ok,
		'ltms_deprisa_bulk_fail' => $fail,
	], $redirect_url );

	return $redirect_url;
}

// Mostrar el resultado del bulk action como admin notice
add_action( 'admin_notices', function(): void {
	if ( isset( $_GET['ltms_deprisa_bulk_ok'] ) || isset( $_GET['ltms_deprisa_bulk_fail'] ) ) {
		$ok   = (int) ( $_GET['ltms_deprisa_bulk_ok']   ?? 0 );
		$fail = (int) ( $_GET['ltms_deprisa_bulk_fail'] ?? 0 );
		echo '<div class="notice notice-' . ( $fail > 0 ? 'warning' : 'success' ) . ' is-dismissible"><p>';
		echo "📦 <strong>Deprisa bulk:</strong> {$ok} guía(s) generadas correctamente";
		if ( $fail > 0 ) echo ", {$fail} con error(es).";
		echo '</p></div>';
	}
} );

/* ======================================================================
   7. EMAIL DE CONFIRMACIÓN: incluir número de guía
   ====================================================================== */

add_action( 'woocommerce_email_order_meta', function( \WC_Order $order, bool $sent_to_admin ): void {
	if ( $sent_to_admin ) return;

	$guia = $order->get_meta( '_deprisa_guia' );
	if ( ! $guia ) return;

	echo '<h2 style="color:#333; font-size:16px; margin:20px 0 10px;">📦 Tu envío con Deprisa</h2>';
	echo '<table cellspacing="0" cellpadding="6" style="width:100%; border:1px solid #eee; margin-bottom:20px;">';
	echo '<tr><th style="text-align:left; background:#f8f8f8; padding:8px;">Número de guía</th>';
	echo '<td style="padding:8px;"><strong>' . esc_html( $guia ) . '</strong></td></tr>';

	$fecha = $order->get_meta( '_deprisa_fecha_objetivo' );
	if ( $fecha ) {
		echo '<tr><th style="text-align:left; background:#f8f8f8; padding:8px;">Entrega estimada</th>';
		echo '<td style="padding:8px;">' . esc_html( $fecha ) . '</td></tr>';
	}

	echo '<tr><th style="text-align:left; background:#f8f8f8; padding:8px;">Tracking</th>';
	echo '<td style="padding:8px;"><a href="https://www.deprisa.com/rastrea-tu-envio/?codigo=' . urlencode( $guia ) . '">';
	echo 'Rastrear en deprisa.com →</a></td></tr>';
	echo '</table>';
}, 10, 2 );
