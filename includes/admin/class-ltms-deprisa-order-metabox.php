<?php
/**
 * LTMS — Metabox de Deprisa en la pantalla del pedido WooCommerce
 *
 * Muestra en el pedido:
 *  - Estado de la guía (si ya existe)
 *  - Formulario para crear guía
 *  - Botón para descargar etiqueta (PDF Base64)
 *  - Tracking en tiempo real
 *  - Crear / cancelar recogida
 *
 * Ubicación:
 *   includes/admin/class-ltms-deprisa-order-metabox.php
 *
 * Registro (en el loader o archivo principal del plugin):
 *   LTMS_Deprisa_Order_Metabox::init();
 *
 * @package LTMS
 * @since   1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LTMS_Deprisa_Order_Metabox {

	/* ------------------------------------------------------------------ */
	/* Init: registrar hooks                                               */
	/* ------------------------------------------------------------------ */

	public static function init(): void {
		add_action( 'add_meta_boxes',       [ __CLASS__, 'register_metabox' ] );
		add_action( 'wp_ajax_ltms_deprisa_crear_guia',       [ __CLASS__, 'ajax_crear_guia' ] );
		add_action( 'wp_ajax_ltms_deprisa_get_tracking',     [ __CLASS__, 'ajax_get_tracking' ] );
		add_action( 'wp_ajax_ltms_deprisa_get_etiqueta',     [ __CLASS__, 'ajax_get_etiqueta' ] );
		add_action( 'wp_ajax_ltms_deprisa_crear_recogida',   [ __CLASS__, 'ajax_crear_recogida' ] );
		add_action( 'wp_ajax_ltms_deprisa_cancelar_recogida',[ __CLASS__, 'ajax_cancelar_recogida' ] );
	}

	/* ------------------------------------------------------------------ */
	/* Registro del metabox                                                */
	/* ------------------------------------------------------------------ */

	public static function register_metabox(): void {
		// Soporta tanto HPOS (woocommerce_page_wc-orders) como el editor clásico
		$screens = [ 'shop_order', 'woocommerce_page_wc-orders' ];

		foreach ( $screens as $screen ) {
			add_meta_box(
				'ltms_deprisa_metabox',
				'📦 Deprisa — Envío y Logística',
				[ __CLASS__, 'render' ],
				$screen,
				'side',
				'high'
			);
		}
	}

	/* ------------------------------------------------------------------ */
	/* Render del metabox                                                  */
	/* ------------------------------------------------------------------ */

	public static function render( $post_or_order ): void {
		// Compatibilidad HPOS: el argumento puede ser WC_Order o WP_Post
		$order = $post_or_order instanceof \WC_Order
			? $post_or_order
			: wc_get_order( $post_or_order->ID );

		if ( ! $order ) return;

		$order_id  = $order->get_id();
		$guia      = $order->get_meta( '_deprisa_guia' );
		$etiqueta  = $order->get_meta( '_deprisa_etiqueta_base64' );
		$fecha_obj = $order->get_meta( '_deprisa_fecha_objetivo' );
		$delegacion= $order->get_meta( '_deprisa_delegacion_destino' );
		$recogida  = $order->get_meta( '_deprisa_recogida_codigo' );
		$enabled   = (bool) get_option( 'ltms_deprisa_enabled', false );
		$nonce     = wp_create_nonce( 'ltms_deprisa_order_' . $order_id );

		if ( ! $enabled ) {
			echo '<p style="color:#856404; background:#fff3cd; padding:8px; border-radius:4px; margin:0;">';
			echo '⚠️ La integración Deprisa está <strong>deshabilitada</strong>. Actívala en <a href="' . esc_url( admin_url( 'admin.php?page=ltms-settings&tab=deprisa' ) ) . '">LTMS → Ajustes → Deprisa</a>.';
			echo '</p>';
			return;
		}
		?>
		<div id="ltms-deprisa-box" style="font-size:13px;">

			<?php if ( $guia ) : ?>
			<!-- ═══ GUÍA EXISTENTE ═══ -->
			<div style="background:#d4edda; border:1px solid #c3e6cb; border-radius:6px; padding:10px; margin-bottom:12px;">
				<strong>✅ Guía generada</strong><br>
				<code style="font-size:14px; letter-spacing:1px;"><?php echo esc_html( $guia ); ?></code><br>
				<?php if ( $fecha_obj ) : ?>
					<small>📅 Entrega estimada: <strong><?php echo esc_html( $fecha_obj ); ?></strong></small><br>
				<?php endif; ?>
				<?php if ( $delegacion ) : ?>
					<small>🏢 Delegación destino: <?php echo esc_html( $delegacion ); ?></small>
				<?php endif; ?>
			</div>

			<!-- Acciones rápidas -->
			<div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:12px;">

				<?php if ( $etiqueta ) : ?>
				<button type="button" class="button button-small"
					onclick="ltmsDeprisa.descargarEtiqueta('<?php echo esc_js( $guia ); ?>', '<?php echo esc_js( $etiqueta ); ?>')"
					title="Descargar etiqueta PDF">
					🖨 Etiqueta
				</button>
				<?php else : ?>
				<button type="button" class="button button-small"
					onclick="ltmsDeprisa.getEtiqueta(<?php echo $order_id; ?>, '<?php echo esc_js( $nonce ); ?>')"
					title="Descargar etiqueta de Deprisa">
					🖨 Etiqueta
				</button>
				<?php endif; ?>

				<button type="button" class="button button-small"
					onclick="ltmsDeprisa.getTracking(<?php echo $order_id; ?>, '<?php echo esc_js( $nonce ); ?>')"
					title="Consultar tracking en Deprisa">
					📍 Tracking
				</button>

			</div>

			<!-- Tracking resultado -->
			<div id="ltms-deprisa-tracking" style="display:none; margin-bottom:12px;
				background:#f8f9fa; border:1px solid #dee2e6; border-radius:6px; padding:10px;
				max-height:200px; overflow-y:auto; font-size:12px;"></div>

			<!-- ═══ RECOGIDA ═══ -->
			<?php if ( $recogida ) : ?>
			<div style="background:#d1ecf1; border:1px solid #bee5eb; border-radius:6px; padding:8px; margin-bottom:10px;">
				<strong>🚚 Recogida programada</strong><br>
				<code><?php echo esc_html( $recogida ); ?></code>
				<button type="button" class="button button-small" style="margin-left:8px; color:#721c24;"
					onclick="ltmsDeprisa.cancelarRecogida(<?php echo $order_id; ?>, '<?php echo esc_js( $recogida ); ?>', '<?php echo esc_js( $nonce ); ?>')">
					✖ Cancelar
				</button>
			</div>
			<?php else : ?>
			<button type="button" class="button button-small"
				onclick="ltmsDeprisa.toggleRecogida()"
				style="margin-bottom:10px; width:100%;">
				🚚 Programar recogida
			</button>

			<div id="ltms-recogida-form" style="display:none; margin-bottom:10px;
				background:#f8f9fa; border:1px solid #dee2e6; border-radius:6px; padding:10px;">
				<label style="display:block; margin-bottom:6px;">
					<span style="font-size:12px; color:#555;">Fecha de recogida</span>
					<input type="date" id="ltms_fecha_recogida"
						value="<?php echo esc_attr( date( 'Y-m-d', strtotime( '+1 weekday' ) ) ); ?>"
						min="<?php echo esc_attr( date( 'Y-m-d' ) ); ?>"
						style="width:100%; margin-top:3px;">
				</label>
				<label style="display:block; margin-bottom:6px;">
					<span style="font-size:12px; color:#555;">Rango horario</span>
					<select id="ltms_rango_horario" style="width:100%; margin-top:3px;">
						<option value="09:00-13:00">09:00 – 13:00</option>
						<option value="13:00-17:00">13:00 – 17:00</option>
						<option value="14:00-19:00">14:00 – 19:00</option>
					</select>
				</label>
				<label style="display:block; margin-bottom:8px;">
					<span style="font-size:12px; color:#555;">Bultos / Peso (kg)</span>
					<div style="display:flex; gap:6px; margin-top:3px;">
						<input type="number" id="ltms_recogida_bultos" value="1" min="1" max="99"
							style="width:50%;" placeholder="Bultos">
						<input type="number" id="ltms_recogida_kilos" value="1" min="0.1" step="0.1"
							style="width:50%;" placeholder="Kg">
					</div>
				</label>
				<button type="button" class="button button-primary button-small"
					onclick="ltmsDeprisa.crearRecogida(<?php echo $order_id; ?>, '<?php echo esc_js( $guia ); ?>', '<?php echo esc_js( $nonce ); ?>')"
					style="width:100%;">
					Confirmar recogida
				</button>
			</div>
			<?php endif; ?>

			<hr style="border:none; border-top:1px solid #ddd; margin:12px 0;">

			<!-- Re-generar guía (solo si se necesita) -->
			<details style="margin-bottom:4px;">
				<summary style="cursor:pointer; font-size:12px; color:#555;">⚙️ Opciones avanzadas</summary>
				<div style="margin-top:8px;">
					<?php self::render_form_crear_guia( $order_id, $nonce, true ); ?>
				</div>
			</details>

			<?php else : ?>
			<!-- ═══ SIN GUÍA — CREAR ═══ -->
			<?php self::render_form_crear_guia( $order_id, $nonce, false ); ?>
			<?php endif; ?>

			<!-- Mensaje de estado -->
			<div id="ltms-deprisa-msg" style="display:none; margin-top:10px; padding:8px;
				border-radius:4px; font-size:12px;"></div>

		</div><!-- /#ltms-deprisa-box -->

		<?php self::render_script( $order_id, $nonce ); ?>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Formulario crear guía                                               */
	/* ------------------------------------------------------------------ */

	private static function render_form_crear_guia( int $order_id, string $nonce, bool $regenerar ): void {
		$btn_label = $regenerar ? '🔄 Re-generar guía' : '📦 Crear guía Deprisa';
		$btn_class = $regenerar ? 'button button-small' : 'button button-primary';
		?>
		<div style="margin-bottom:8px;">
			<?php if ( ! $regenerar ) : ?>
			<p style="margin:0 0 10px; color:#555;">
				Completa los datos y genera la guía de envío Deprisa para este pedido.
			</p>
			<?php endif; ?>

			<label style="display:block; margin-bottom:6px;">
				<span style="font-size:12px; color:#555;">Código de servicio</span>
				<select name="ltms_servicio" id="ltms_servicio_<?php echo $order_id; ?>"
					style="width:100%; margin-top:3px;">
					<option value="3005">3005 — Estándar B2B</option>
					<option value="3027">3027 — Contraentrega</option>
				</select>
			</label>

			<label style="display:block; margin-bottom:6px;">
				<span style="font-size:12px; color:#555;">Peso (kg)</span>
				<input type="number" id="ltms_kilos_<?php echo $order_id; ?>"
					value="1" min="0.1" step="0.1"
					style="width:100%; margin-top:3px;" placeholder="Ej: 1.5">
			</label>

			<label style="display:block; margin-bottom:8px;">
				<span style="font-size:12px; color:#555;">Observaciones</span>
				<input type="text" id="ltms_obs_<?php echo $order_id; ?>"
					maxlength="255" placeholder="Frágil, manejar con cuidado"
					style="width:100%; margin-top:3px;">
			</label>

			<!-- Asegurar envío -->
			<label style="display:flex; align-items:center; gap:6px; margin-bottom:10px; cursor:pointer;">
				<input type="checkbox" id="ltms_asegurar_<?php echo $order_id; ?>" value="S">
				<span style="font-size:12px; color:#555;">Asegurar envío</span>
			</label>

			<button type="button" class="<?php echo $btn_class; ?>"
				onclick="ltmsDeprisa.crearGuia(<?php echo $order_id; ?>, '<?php echo esc_js( $nonce ); ?>')"
				style="width:100%;">
				<?php echo $btn_label; ?>
			</button>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Script JS inline                                                    */
	/* ------------------------------------------------------------------ */

	private static function render_script( int $order_id, string $nonce ): void {
		?>
		<script>
		var ltmsDeprisa = (function(){

			function msg(text, type){
				var el = document.getElementById('ltms-deprisa-msg');
				el.style.display = 'block';
				el.style.background = type === 'ok' ? '#d4edda' : (type === 'warn' ? '#fff3cd' : '#f8d7da');
				el.style.color      = type === 'ok' ? '#155724' : (type === 'warn' ? '#856404' : '#721c24');
				el.innerHTML = text;
			}

			return {
				crearGuia: function(orderId, nonce){
					var servicio = document.getElementById('ltms_servicio_' + orderId).value;
					var kilos    = document.getElementById('ltms_kilos_'    + orderId).value;
					var obs      = document.getElementById('ltms_obs_'      + orderId).value;
					var asegurar = document.getElementById('ltms_asegurar_' + orderId).checked ? 'S' : 'N';

					msg('⏳ Creando guía...', 'warn');

					jQuery.post(ajaxurl, {
						action:   'ltms_deprisa_crear_guia',
						order_id: orderId,
						servicio: servicio,
						kilos:    kilos,
						obs:      obs,
						asegurar: asegurar,
						_wpnonce: nonce
					}, function(res){
						if(res.success){
							msg('✅ ' + res.data.message, 'ok');
							setTimeout(function(){ location.reload(); }, 1500);
						} else {
							msg('❌ ' + res.data.message, 'error');
						}
					}).fail(function(){
						msg('❌ Error de red. Intenta de nuevo.', 'error');
					});
				},

				getTracking: function(orderId, nonce){
					var box = document.getElementById('ltms-deprisa-tracking');
					box.style.display = 'block';
					box.innerHTML = '⏳ Consultando tracking...';

					jQuery.post(ajaxurl, {
						action:   'ltms_deprisa_get_tracking',
						order_id: orderId,
						_wpnonce: nonce
					}, function(res){
						if(res.success){
							box.innerHTML = res.data.html;
						} else {
							box.innerHTML = '❌ ' + res.data.message;
						}
					}).fail(function(){
						box.innerHTML = '❌ Error de red.';
					});
				},

				getEtiqueta: function(orderId, nonce){
					msg('⏳ Descargando etiqueta...', 'warn');

					jQuery.post(ajaxurl, {
						action:   'ltms_deprisa_get_etiqueta',
						order_id: orderId,
						_wpnonce: nonce
					}, function(res){
						if(res.success){
							ltmsDeprisa.descargarEtiqueta(res.data.guia, res.data.base64);
							msg('✅ Etiqueta descargada.', 'ok');
						} else {
							msg('❌ ' + res.data.message, 'error');
						}
					}).fail(function(){
						msg('❌ Error de red.', 'error');
					});
				},

				descargarEtiqueta: function(guia, base64){
					var blob = ltmsDeprisa.b64toBlob(base64, 'application/pdf');
					var url  = URL.createObjectURL(blob);
					var a    = document.createElement('a');
					a.href   = url;
					a.download = 'etiqueta-deprisa-' + guia + '.pdf';
					document.body.appendChild(a);
					a.click();
					document.body.removeChild(a);
					URL.revokeObjectURL(url);
				},

				b64toBlob: function(b64Data, contentType){
					var byteCharacters = atob(b64Data);
					var byteArrays = [];
					for(var offset = 0; offset < byteCharacters.length; offset += 512){
						var slice = byteCharacters.slice(offset, offset + 512);
						var byteNumbers = new Array(slice.length);
						for(var i = 0; i < slice.length; i++){
							byteNumbers[i] = slice.charCodeAt(i);
						}
						byteArrays.push(new Uint8Array(byteNumbers));
					}
					return new Blob(byteArrays, {type: contentType});
				},

				toggleRecogida: function(){
					var f = document.getElementById('ltms-recogida-form');
					f.style.display = f.style.display === 'none' ? 'block' : 'none';
				},

				crearRecogida: function(orderId, guia, nonce){
					var fecha  = document.getElementById('ltms_fecha_recogida').value;
					var rango  = document.getElementById('ltms_rango_horario').value;
					var bultos = document.getElementById('ltms_recogida_bultos').value;
					var kilos  = document.getElementById('ltms_recogida_kilos').value;

					if(!fecha){ msg('❌ Selecciona una fecha de recogida.', 'error'); return; }

					msg('⏳ Programando recogida...', 'warn');

					jQuery.post(ajaxurl, {
						action:   'ltms_deprisa_crear_recogida',
						order_id: orderId,
						guia:     guia,
						fecha:    fecha,
						rango:    rango,
						bultos:   bultos,
						kilos:    kilos,
						_wpnonce: nonce
					}, function(res){
						if(res.success){
							msg('✅ ' + res.data.message, 'ok');
							setTimeout(function(){ location.reload(); }, 1500);
						} else {
							msg('❌ ' + res.data.message, 'error');
						}
					}).fail(function(){
						msg('❌ Error de red.', 'error');
					});
				},

				cancelarRecogida: function(orderId, codigoRecogida, nonce){
					if(!confirm('¿Cancelar recogida ' + codigoRecogida + '? Esta acción no se puede deshacer.')){ return; }

					msg('⏳ Cancelando recogida...', 'warn');

					jQuery.post(ajaxurl, {
						action:          'ltms_deprisa_cancelar_recogida',
						order_id:        orderId,
						codigo_recogida: codigoRecogida,
						motivo:          'Cancelado desde LTMS',
						_wpnonce:        nonce
					}, function(res){
						if(res.success){
							msg('✅ ' + res.data.message, 'ok');
							setTimeout(function(){ location.reload(); }, 1500);
						} else {
							msg('❌ ' + res.data.message, 'error');
						}
					}).fail(function(){
						msg('❌ Error de red.', 'error');
					});
				}
			};
		})();
		</script>
		<?php
	}

	/* ================================================================== */
	/* AJAX HANDLERS                                                       */
	/* ================================================================== */

	/* ── Crear guía ───────────────────────────────────────────────────── */

	public static function ajax_crear_guia(): void {
		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		check_ajax_referer( 'ltms_deprisa_order_' . $order_id, '_wpnonce' );
		self::check_permissions();

		$shipping = new LTMS_Deprisa_Shipping();
		$result   = $shipping->crear_guia_para_pedido( $order_id, [
			'codigoServicio' => sanitize_text_field( $_POST['servicio'] ?? '3005' ),
			'kilos'          => (float) ( $_POST['kilos'] ?? 1 ),
			'observaciones'  => sanitize_text_field( $_POST['obs'] ?? '' ),
			'asegurarEnvio'  => sanitize_text_field( $_POST['asegurar'] ?? 'N' ),
			'contraentrega'  => ( ( $_POST['servicio'] ?? '' ) === '3027' ),
		] );

		if ( $result['ok'] ) {
			wp_send_json_success( [
				'message' => "Guía {$result['guia']} creada correctamente.",
				'guia'    => $result['guia'],
			] );
		} else {
			wp_send_json_error( [ 'message' => $result['error'] ?? 'Error desconocido al crear guía.' ] );
		}
	}

	/* ── Tracking ─────────────────────────────────────────────────────── */

	public static function ajax_get_tracking(): void {
		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		check_ajax_referer( 'ltms_deprisa_order_' . $order_id, '_wpnonce' );
		self::check_permissions();

		$shipping = new LTMS_Deprisa_Shipping();
		$tracking = $shipping->tracking_pedido( $order_id );

		if ( $tracking === null ) {
			wp_send_json_error( [ 'message' => 'No hay guía o tracking no disponible.' ] );
		}

		if ( isset( $tracking['error'] ) ) {
			wp_send_json_error( [ 'message' => $tracking['error'] ] );
		}

		// Construir HTML del tracking
		$html  = '<strong>' . esc_html( $tracking['numeroEnvio'] ?? '' ) . '</strong>';
		$html .= ' — ' . esc_html( $tracking['descripcionServicio'] ?? '' ) . '<br>';
		$html .= '<small>📍 ' . esc_html( $tracking['poblacionDestinatario'] ?? '' ) . '</small><br><br>';

		$estados = $tracking['estados'] ?? [];
		if ( $estados ) {
			$html .= '<strong>Eventos:</strong><ul style="margin:4px 0 8px 16px; padding:0;">';
			foreach ( array_slice( array_reverse( $estados ), 0, 8 ) as $estado ) {
				$html .= '<li><small><strong>' . esc_html( $estado['fechaEvento'] ?? '' ) . '</strong>';
				$html .= ' — ' . esc_html( $estado['descripcion'] ?? '' );
				$html .= ' <em>(' . esc_html( $estado['delegacionNombre'] ?? '' ) . ')</em></small></li>';
			}
			$html .= '</ul>';
		} else {
			$html .= '<em>Sin eventos registrados aún.</em>';
		}

		$incidencias = $tracking['incidencias'] ?? [];
		if ( $incidencias ) {
			$html .= '<strong>⚠️ Incidencias:</strong><ul style="margin:4px 0 0 16px; padding:0; color:#856404;">';
			foreach ( $incidencias as $inc ) {
				$html .= '<li><small>' . esc_html( $inc['descripcion'] ?? '' ) . ' (' . esc_html( $inc['fechaAlta'] ?? '' ) . ')</small></li>';
			}
			$html .= '</ul>';
		}

		wp_send_json_success( [ 'html' => $html ] );
	}

	/* ── Etiqueta ─────────────────────────────────────────────────────── */

	public static function ajax_get_etiqueta(): void {
		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		check_ajax_referer( 'ltms_deprisa_order_' . $order_id, '_wpnonce' );
		self::check_permissions();

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( [ 'message' => 'Pedido no encontrado.' ] );
		}

		$guia     = $order->get_meta( '_deprisa_guia' );
		$etiqueta = $order->get_meta( '_deprisa_etiqueta_base64' );

		if ( ! $guia ) {
			wp_send_json_error( [ 'message' => 'No hay guía registrada para este pedido.' ] );
		}

		// Si ya está en meta, devolver directamente
		if ( $etiqueta ) {
			wp_send_json_success( [ 'guia' => $guia, 'base64' => $etiqueta ] );
		}

		// Pedirla a Deprisa
		try {
			$username = get_option( 'ltms_deprisa_username', '' );
			$password = get_option( 'ltms_deprisa_password', '' );
			$sandbox  = (bool) get_option( 'ltms_deprisa_sandbox', true );
			$api      = new LTMS_Api_Deprisa( $username, $password, $sandbox );

			$etiquetas = $api->obtener_etiquetas( [
				[ 'numeroEnvio' => $guia, 'tipoImpresora' => 'T' ],
			] );

			if ( ! empty( $etiquetas[0]['etiquetaBase64'] ) ) {
				$b64 = $etiquetas[0]['etiquetaBase64'];
				// Guardar en meta para evitar llamadas repetidas
				$order->update_meta_data( '_deprisa_etiqueta_base64', $b64 );
				$order->save();
				wp_send_json_success( [ 'guia' => $guia, 'base64' => $b64 ] );
			} else {
				wp_send_json_error( [ 'message' => 'Deprisa no devolvió PDF para esta guía.' ] );
			}
		} catch ( LTMS_Deprisa_Exception $e ) {
			wp_send_json_error( [ 'message' => 'Error API: ' . $e->getMessage() ] );
		}
	}

	/* ── Crear recogida ───────────────────────────────────────────────── */

	public static function ajax_crear_recogida(): void {
		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		check_ajax_referer( 'ltms_deprisa_order_' . $order_id, '_wpnonce' );
		self::check_permissions();

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( [ 'message' => 'Pedido no encontrado.' ] );
		}

		// Convertir fecha Y-m-d → DD/MM/YYYY para Deprisa
		$fecha_raw = sanitize_text_field( $_POST['fecha'] ?? '' );
		$fecha_dt  = \DateTime::createFromFormat( 'Y-m-d', $fecha_raw );
		if ( ! $fecha_dt ) {
			wp_send_json_error( [ 'message' => 'Fecha inválida.' ] );
		}
		$fecha_deprisa = LTMS_Api_Deprisa::formatear_fecha( $fecha_dt );

		$guia    = sanitize_text_field( $_POST['guia'] ?? '' );
		$rango   = sanitize_text_field( $_POST['rango']  ?? '09:00-13:00' );
		$bultos  = max( 1, (int) ( $_POST['bultos'] ?? 1 ) );
		$kilos   = max( 0.1, (float) ( $_POST['kilos'] ?? 1 ) );

		$params = [
			'codigoAdmision'               => LTMS_Api_Deprisa::generar_codigo_admision( 'REC' ),
			'clienteRemitente'             => get_option( 'ltms_deprisa_cliente_remitente', '' ),
			'centroRemitente'              => get_option( 'ltms_deprisa_centro_remitente', '01' ),
			'nombreRemitente'              => get_option( 'ltms_deprisa_contacto_remitente', 'Lo-Tengo' ),
			'direccionRemitente'           => get_option( 'ltms_deprisa_direccion_remitente', '' ),
			'codigoPostalRemitente'        => get_option( 'ltms_deprisa_cp_remitente', '' ),
			'poblacionRemitente'           => get_option( 'ltms_deprisa_ciudad_remitente', 'BOGOTA' ),
			'tipoDocRemitente'             => get_option( 'ltms_deprisa_tipo_doc_remitente', 'NIT' ),
			'documentoIdentidadRemitente'  => get_option( 'ltms_deprisa_nit_remitente', '' ),
			'personaContactoRemitente'     => get_option( 'ltms_deprisa_contacto_remitente', '' ),
			'telefonoContactoRemitente'    => get_option( 'ltms_deprisa_telefono_remitente', '' ),
			'fechaRecogida'                => $fecha_deprisa,
			'rangoHorario'                 => $rango,
			'codigoServicio'               => get_option( 'ltms_deprisa_servicio_default', '3005' ),
			'embalaje'                     => 'C',
			'observaciones'                => 'Pedido #' . $order_id . ' Lo-Tengo',
			'numeroBultos'                 => $bultos,
			'kilos'                        => $kilos,
		];

		$shipping = new LTMS_Deprisa_Shipping();
		$result   = $shipping->crear_y_asociar_recogida( $params, $guia );

		if ( $result['ok'] ) {
			$codigo = $result['codigoRecogida'];
			// Guardar código de recogida en el meta del pedido
			$order->update_meta_data( '_deprisa_recogida_codigo', $codigo );
			$order->update_meta_data( '_deprisa_recogida_fecha',  $fecha_deprisa );
			$order->save();
			$order->add_order_note(
				"[Deprisa] Recogida programada: {$codigo} | Fecha: {$fecha_deprisa} | Horario: {$rango}"
			);

			wp_send_json_success( [
				'message' => "Recogida {$codigo} programada para el {$fecha_deprisa} ({$rango}).",
			] );
		} else {
			$errs = implode( ' | ', array_column( $result['errors'] ?? [], 'descripcion' ) );
			wp_send_json_error( [ 'message' => $errs ?: 'Error al programar recogida.' ] );
		}
	}

	/* ── Cancelar recogida ────────────────────────────────────────────── */

	public static function ajax_cancelar_recogida(): void {
		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		check_ajax_referer( 'ltms_deprisa_order_' . $order_id, '_wpnonce' );
		self::check_permissions();

		$order          = wc_get_order( $order_id );
		$codigo_recogida= sanitize_text_field( $_POST['codigo_recogida'] ?? '' );
		$motivo         = sanitize_text_field( $_POST['motivo'] ?? 'Cancelado desde LTMS' );

		if ( ! $order || ! $codigo_recogida ) {
			wp_send_json_error( [ 'message' => 'Datos insuficientes para cancelar.' ] );
		}

		try {
			$username = get_option( 'ltms_deprisa_username', '' );
			$password = get_option( 'ltms_deprisa_password', '' );
			$sandbox  = (bool) get_option( 'ltms_deprisa_sandbox', true );
			$api      = new LTMS_Api_Deprisa( $username, $password, $sandbox );

			$result = $api->cancelar_recogidas( [
				[ 'codigoRecogida' => $codigo_recogida, 'motivo' => $motivo ],
			] );

			if ( $result['ok'] ) {
				$order->delete_meta_data( '_deprisa_recogida_codigo' );
				$order->delete_meta_data( '_deprisa_recogida_fecha' );
				$order->save();
				$order->add_order_note( "[Deprisa] Recogida {$codigo_recogida} cancelada. Motivo: {$motivo}" );

				wp_send_json_success( [ 'message' => "Recogida {$codigo_recogida} cancelada correctamente." ] );
			} else {
				$errs = implode( ' | ', array_column( $result['errors'] ?? [], 'descripcion' ) );
				wp_send_json_error( [ 'message' => $errs ?: 'Error al cancelar recogida.' ] );
			}
		} catch ( LTMS_Deprisa_Exception $e ) {
			wp_send_json_error( [ 'message' => 'Error API: ' . $e->getMessage() ] );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Helper: verificar permisos                                         */
	/* ------------------------------------------------------------------ */

	private static function check_permissions(): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( [ 'message' => 'Sin permisos suficientes.' ], 403 );
		}
	}
}
