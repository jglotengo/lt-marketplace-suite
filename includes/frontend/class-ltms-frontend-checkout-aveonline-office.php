<?php
/**
 * Campo de selección de oficina de transportadora Aveonline en el checkout.
 *
 * Muestra un <select> debajo del campo de método de envío cuando la tarifa
 * activa es de Aveonline (rate ID contiene "ltms_aveonline"). El comprador
 * elige la sucursal a la que desea llevar/retirar su paquete.
 *
 * Flujo:
 *   1. La página de checkout carga — se renderiza el campo vacío (oculto).
 *   2. WC dispara `updated_checkout` cuando se selecciona un método de envío.
 *   3. El JS detecta si el rate activo es Aveonline y, de ser así, hace AJAX
 *      para cargar las oficinas disponibles para la ciudad del comprador.
 *   4. El comprador selecciona una oficina → se guarda en order meta al hacer
 *      el pedido.
 *
 * @package LTMS
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LTMS_Frontend_Checkout_Aveonline_Office
 */
class LTMS_Frontend_Checkout_Aveonline_Office {

	/** Clave de order meta donde se guarda la oficina elegida. */
	public const META_KEY_OFFICE  = '_ltms_aveonline_office';

	/** Clave de order meta donde se guarda el código de transportadora. */
	public const META_KEY_CARRIER = '_ltms_aveonline_carrier_code';

	/** Acción AJAX (pública y privada). */
	private const AJAX_ACTION = 'ltms_get_aveonline_offices';

	/** @var self|null */
	private static ?self $instance = null;

	/**
	 * Registra todos los hooks necesarios.
	 */
	public static function init(): void {
		if ( null !== self::$instance ) {
			return;
		}
		self::$instance = new self();

		// Renderiza el campo en el checkout (después del bloque de envío).
		add_action( 'woocommerce_review_order_after_shipping',     [ self::$instance, 'render_field' ] );

		// Encola el JS del campo.
		add_action( 'wp_enqueue_scripts',                          [ self::$instance, 'enqueue_scripts' ] );

		// Valida al hacer el pedido.
		add_action( 'woocommerce_checkout_process',                [ self::$instance, 'validate' ] );

		// Guarda en order meta.
		add_action( 'woocommerce_checkout_update_order_meta',      [ self::$instance, 'save_meta' ] );

		// AJAX: carga oficinas según ciudad y transportadora.
		add_action( 'wp_ajax_'        . self::AJAX_ACTION, [ self::$instance, 'ajax_get_offices' ] );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, [ self::$instance, 'ajax_get_offices' ] );

		// Muestra la oficina elegida en el detalle del pedido (admin).
		add_action( 'woocommerce_admin_order_data_after_shipping_address', [ self::$instance, 'display_in_order_admin' ] );
		add_filter( 'woocommerce_order_details_after_order_table',         [ self::$instance, 'display_in_order_customer' ] );
	}

	// -------------------------------------------------------------------------
	// Rendering
	// -------------------------------------------------------------------------

	/**
	 * Renderiza el <select> de oficinas (vacío inicialmente, poblado vía JS).
	 */
	public function render_field(): void {
		echo '<tr class="ltms-aveonline-office-row" style="display:none;">
			<th>' . esc_html__( 'Oficina / Punto de entrega', 'ltms' ) . '</th>
			<td>
				<select name="ltms_aveonline_office" id="ltms_aveonline_office" class="ltms-aveonline-office-select">
					<option value="">' . esc_html__( '— Cargando oficinas… —', 'ltms' ) . '</option>
				</select>
				<p class="ltms-aveonline-office-note" style="font-size:.85em;color:#666;margin:.4em 0 0;"></p>
			</td>
		</tr>';
	}

	// -------------------------------------------------------------------------
	// Scripts
	// -------------------------------------------------------------------------

	/**
	 * Encola el JS del campo solo en la página de checkout.
	 */
	public function enqueue_scripts(): void {
		if ( ! is_checkout() ) {
			return;
		}

		$handle = 'ltms-checkout-aveonline-office';
		wp_register_script(
			$handle,
			false, // inline
			[ 'jquery', 'wc-checkout' ],
			LTMS_VERSION,
			true
		);
		wp_enqueue_script( $handle );
		wp_add_inline_script( $handle, $this->inline_js() );
	}

	/**
	 * JS inline que observa el método de envío y carga las oficinas vía AJAX.
	 *
	 * @return string
	 */
	private function inline_js(): string {
		$nonce = wp_create_nonce( self::AJAX_ACTION );
		$ajax  = admin_url( 'admin-ajax.php' );

		ob_start();
		?>
(function($) {
	'use strict';

	var ltmsOffice = {
		ajaxUrl : <?php echo wp_json_encode( $ajax ); ?>,
		nonce   : <?php echo wp_json_encode( $nonce ); ?>,
		$row    : null,
		$select : null,
		$note   : null,
		loading : false,

		init: function() {
			this.$row    = $('.ltms-aveonline-office-row');
			this.$select = $('#ltms_aveonline_office');
			this.$note   = $('.ltms-aveonline-office-note');

			$(document.body).on('updated_checkout', function() {
				ltmsOffice.onCheckoutUpdate();
			});
		},

		onCheckoutUpdate: function() {
			var $chosen = $('input[name="shipping_method[0]"]:checked, input[name="shipping_method"]:checked').first();
			if ( ! $chosen.length ) {
				$chosen = $('input[name^="shipping_method"]').first();
			}
			var rateId = $chosen.val() || '';
			var isAveonline = rateId.indexOf('ltms_aveonline') !== -1;

			if ( ! isAveonline ) {
				this.$row.hide();
				this.$select.val('');
				return;
			}

			this.$row.show();
			this.loadOffices( rateId );
		},

		loadOffices: function( rateId ) {
			if ( this.loading ) return;
			this.loading = true;
			this.$select.prop('disabled', true).html('<option value=""><?php echo esc_js( __( '— Cargando oficinas… —', 'ltms' ) ); ?></option>');
			this.$note.text('');

			// Extraer carrier code del rate ID si está codificado (ltms_aveonline_1016_...)
			var carrierMatch = rateId.match(/ltms_aveonline[_\-]?(\d+)/);
			var carrier = carrierMatch ? carrierMatch[1] : '';

			$.ajax({
				url    : this.ajaxUrl,
				method : 'POST',
				data   : {
					action   : '<?php echo esc_js( self::AJAX_ACTION ); ?>',
					_wpnonce : this.nonce,
					rate_id  : rateId,
					carrier  : carrier,
				},
				success: function( res ) {
					ltmsOffice.loading = false;
					ltmsOffice.$select.prop('disabled', false);

					if ( ! res.success || ! res.data ) {
						ltmsOffice.$row.hide();
						return;
					}

					var options = res.data.options || {};
					var keys    = Object.keys( options );

					if ( ! keys.length ) {
						ltmsOffice.$note.text('<?php echo esc_js( __( 'No hay oficinas disponibles para tu ciudad.', 'ltms' ) ); ?>');
						ltmsOffice.$select.html('<option value=""><?php echo esc_js( __( '— Sin oficinas disponibles —', 'ltms' ) ); ?></option>');
						return;
					}

					var html = '<option value=""><?php echo esc_js( __( '— Selecciona una oficina —', 'ltms' ) ); ?></option>';
					$.each( options, function( val, label ) {
						html += '<option value="' + $('<div>').text(val).html() + '">' + $('<div>').text(label).html() + '</option>';
					});
					ltmsOffice.$select.html( html );

					if ( res.data.carrier_name ) {
						ltmsOffice.$note.text( '<?php echo esc_js( __( 'Transportadora:', 'ltms' ) ); ?> ' + res.data.carrier_name );
					}
				},
				error: function() {
					ltmsOffice.loading = false;
					ltmsOffice.$select.prop('disabled', false).html('<option value=""><?php echo esc_js( __( '— Error al cargar oficinas —', 'ltms' ) ); ?></option>');
				}
			});
		}
	};

	$(function() {
		ltmsOffice.init();
		// Dispara inmediatamente si ya hay un método seleccionado
		ltmsOffice.onCheckoutUpdate();
	});

})(jQuery);
		<?php
		return ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// AJAX
	// -------------------------------------------------------------------------

	/**
	 * Handler AJAX: devuelve las opciones de oficinas para la ciudad y transportadora activas.
	 */
	public function ajax_get_offices(): void {
		check_ajax_referer( self::AJAX_ACTION );

		$carrier = sanitize_text_field( wp_unslash( $_POST['carrier'] ?? '' ) );
		$rate_id = sanitize_text_field( wp_unslash( $_POST['rate_id'] ?? '' ) );

		// Si no viene carrier explícito, intentar leerlo de la opción de configuración.
		if ( '' === $carrier ) {
			$carrier = (string) get_option( 'ltms_aveonline_idtransportador', '' );
		}

		// Validar que es una transportadora con soporte de oficinas.
		if ( '' === $carrier || ! LTMS_Business_Aveonline_Offices::is_valid_carrier( $carrier ) ) {
			// Transportadora sin soporte de oficinas (ej: Envía) → ocultar campo.
			wp_send_json_success( [ 'options' => [], 'carrier_name' => '' ] );
		}

		// Obtener ciudad del comprador desde la sesión WC.
		$city_name = '';
		if ( WC()->customer ) {
			$city_name = (string) WC()->customer->get_billing_city();
		}

		// Resolver código DANE de 8 dígitos a partir del nombre de ciudad.
		$city_id = '';
		if ( '' !== $city_name && class_exists( 'LTMS_Business_Aveonline_Cities' ) ) {
			$city_id = LTMS_Business_Aveonline_Cities::get_dane_code( $city_name );
		}

		// Cargar opciones (con caché de 6h integrada en la clase de negocio).
		$options      = LTMS_Business_Aveonline_Offices::get_select_options( $carrier, $city_id ?: null );
		$carrier_name = LTMS_Business_Aveonline_Offices::carrier_name( $carrier );

		wp_send_json_success( [
			'options'      => $options,
			'carrier_name' => $carrier_name,
			'city_id'      => $city_id,
		] );
	}

	// -------------------------------------------------------------------------
	// Validación y guardado
	// -------------------------------------------------------------------------

	/**
	 * Valida que se haya elegido una oficina cuando el envío es Aveonline.
	 */
	public function validate(): void {
		$chosen = WC()->session ? WC()->session->get( 'chosen_shipping_methods' ) : [];
		$rate   = is_array( $chosen ) ? ( $chosen[0] ?? '' ) : '';

		if ( false === strpos( $rate, 'ltms_aveonline' ) ) {
			return; // No es envío Aveonline — no validar.
		}

		$office = sanitize_text_field( wp_unslash( $_POST['ltms_aveonline_office'] ?? '' ) );
		if ( '' === $office ) {
			wc_add_notice(
				__( 'Por favor selecciona una oficina o punto de entrega de la transportadora.', 'ltms' ),
				'error'
			);
		}
	}

	/**
	 * Guarda la oficina elegida y el código de transportadora en order meta.
	 *
	 * @param int $order_id ID del pedido creado.
	 */
	public function save_meta( int $order_id ): void {
		$office  = sanitize_text_field( wp_unslash( $_POST['ltms_aveonline_office']  ?? '' ) );
		$carrier = (string) get_option( 'ltms_aveonline_idtransportador', '' );

		if ( '' !== $office ) {
			update_post_meta( $order_id, self::META_KEY_OFFICE,  $office );
		}
		if ( '' !== $carrier ) {
			update_post_meta( $order_id, self::META_KEY_CARRIER, $carrier );
		}
	}

	// -------------------------------------------------------------------------
	// Visualización en pedidos
	// -------------------------------------------------------------------------

	/**
	 * Muestra la oficina elegida en el panel de admin del pedido.
	 *
	 * @param \WC_Order $order Pedido actual.
	 */
	public function display_in_order_admin( \WC_Order $order ): void {
		$office = get_post_meta( $order->get_id(), self::META_KEY_OFFICE, true );
		if ( ! $office ) {
			return;
		}
		$parts = explode( '||', $office, 2 );
		$name  = $parts[0] ?? $office;
		$addr  = $parts[1] ?? '';
		echo '<p><strong>' . esc_html__( 'Oficina Aveonline:', 'ltms' ) . '</strong><br>'
			. esc_html( $name )
			. ( $addr ? '<br><small>' . esc_html( $addr ) . '</small>' : '' )
			. '</p>';
	}

	/**
	 * Muestra la oficina elegida en el detalle del pedido del cliente (mi cuenta / gracias).
	 *
	 * @param \WC_Order $order Pedido actual.
	 */
	public function display_in_order_customer( \WC_Order $order ): void {
		$office = get_post_meta( $order->get_id(), self::META_KEY_OFFICE, true );
		if ( ! $office ) {
			return;
		}
		$parts = explode( '||', $office, 2 );
		$name  = $parts[0] ?? $office;
		$addr  = $parts[1] ?? '';
		echo '<section class="ltms-aveonline-office-summary">'
			. '<h2 class="woocommerce-column__title">' . esc_html__( 'Punto de entrega', 'ltms' ) . '</h2>'
			. '<address>' . esc_html( $name )
			. ( $addr ? '<br>' . esc_html( $addr ) : '' )
			. '</address></section>';
	}
}
