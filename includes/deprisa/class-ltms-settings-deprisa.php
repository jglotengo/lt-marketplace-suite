<?php
/**
 * LTMS — Pestaña de configuración Deprisa en el panel LTMS Settings
 *
 * Registra la pestaña "Deprisa" junto a Heka Entrega, Uber Direct, etc.
 * Guarda las opciones con get_option / update_option bajo el prefijo
 * ltms_deprisa_* para que sean leídas por LTMS_Api_Deprisa y
 * LTMS_Deprisa_Shipping sin cambios.
 *
 * Ubicación en el plugin:
 *   includes/settings/class-ltms-settings-deprisa.php
 *
 * Registro (en el archivo principal del plugin o en el loader):
 *   add_filter( 'ltms_settings_tabs',  [ 'LTMS_Settings_Deprisa', 'register_tab'  ] );
 *   add_action( 'ltms_settings_tab_deprisa', [ 'LTMS_Settings_Deprisa', 'render'  ] );
 *   add_action( 'ltms_settings_save_deprisa', [ 'LTMS_Settings_Deprisa', 'save'   ] );
 *
 * @package LTMS
 * @since   1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LTMS_Settings_Deprisa {

	/* ------------------------------------------------------------------ */
	/* Registro de la pestaña                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Agrega "Deprisa" al array de tabs del panel LTMS Settings.
	 * Se engancha en el filtro ltms_settings_tabs.
	 *
	 * @param array $tabs  [ slug => label ]
	 * @return array
	 */
	public static function register_tab( array $tabs ): array {
		$tabs['deprisa'] = __( 'Deprisa', 'ltms' );
		return $tabs;
	}

	/* ------------------------------------------------------------------ */
	/* Render del formulario                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Muestra el formulario de configuración de Deprisa.
	 * Se engancha en la acción ltms_settings_tab_deprisa.
	 */
	public static function render(): void {
		// Leer opciones actuales
		$username            = get_option( 'ltms_deprisa_username',            '' );
		$password            = get_option( 'ltms_deprisa_password',            '' );
		$sandbox             = (bool) get_option( 'ltms_deprisa_sandbox',      true );
		$cliente_remitente   = get_option( 'ltms_deprisa_cliente_remitente',   '' );
		$centro_remitente    = get_option( 'ltms_deprisa_centro_remitente',    '01' );
		$direccion_remitente = get_option( 'ltms_deprisa_direccion_remitente', '' );
		$ciudad_remitente    = get_option( 'ltms_deprisa_ciudad_remitente',    'BOGOTA' );
		$cp_remitente        = get_option( 'ltms_deprisa_cp_remitente',        '' );
		$tipo_doc_remitente  = get_option( 'ltms_deprisa_tipo_doc_remitente',  'NIT' );
		$nit_remitente       = get_option( 'ltms_deprisa_nit_remitente',       '' );
		$contacto_remitente  = get_option( 'ltms_deprisa_contacto_remitente',  '' );
		$telefono_remitente  = get_option( 'ltms_deprisa_telefono_remitente',  '' );
		$servicio_default    = get_option( 'ltms_deprisa_servicio_default',    '3005' );
		$enabled             = (bool) get_option( 'ltms_deprisa_enabled',      false );

		// Verificar si las credenciales están activas
		$credenciales_ok = ! empty( $username ) && ! empty( $password );
		?>

		<div class="ltms-settings-section">

			<!-- ══ CABECERA ══════════════════════════════════════════════════ -->
			<div style="display:flex; align-items:center; gap:16px; margin-bottom:24px;">
				<img src="https://www.deprisa.com/favicon.ico"
					 onerror="this.style.display='none'"
					 style="width:40px; height:40px; border-radius:6px;"
					 alt="Deprisa">
				<div>
					<h2 style="margin:0; font-size:20px;">
						Deprisa <span style="font-size:13px; color:#888; font-weight:400;">(Latín Logistics)</span>
					</h2>
					<p style="margin:4px 0 0; color:#555; font-size:13px;">
						Integración de mensajería nacional con admisión, etiquetas, tracking y recogidas.
					</p>
				</div>
				<span style="margin-left:auto; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600;
							 background:<?php echo $credenciales_ok ? '#d4edda' : '#fff3cd'; ?>;
							 color:<?php echo $credenciales_ok ? '#155724' : '#856404'; ?>;">
					<?php echo $credenciales_ok ? '● Credenciales configuradas' : '○ Sin credenciales'; ?>
				</span>
			</div>

			<form method="post" action="">
				<?php wp_nonce_field( 'ltms_save_deprisa_settings', 'ltms_deprisa_nonce' ); ?>
				<input type="hidden" name="ltms_tab" value="deprisa">

				<!-- ══ SECCIÓN 1: Activación ════════════════════════════════ -->
				<h3 class="ltms-section-title">⚡ Activación</h3>
				<table class="form-table ltms-form-table">
					<tr>
						<th><label for="ltms_deprisa_enabled">Habilitar integración</label></th>
						<td>
							<label class="ltms-toggle">
								<input type="checkbox" id="ltms_deprisa_enabled"
									   name="ltms_deprisa_enabled" value="1"
									   <?php checked( $enabled ); ?>>
								<span class="ltms-toggle-slider"></span>
							</label>
							<p class="description">Activa el módulo Deprisa para la generación de guías en pedidos.</p>
						</td>
					</tr>
					<tr>
						<th><label for="ltms_deprisa_sandbox">Entorno</label></th>
						<td>
							<select id="ltms_deprisa_sandbox" name="ltms_deprisa_sandbox" class="regular-text">
								<option value="1" <?php selected( $sandbox, true ); ?>>🧪 Sandbox (Pruebas)</option>
								<option value="0" <?php selected( $sandbox, false ); ?>>🚀 Producción</option>
							</select>
							<p class="description">
								Sandbox apunta a <code>conectadoslatincopre.alertran.net</code> —
								Producción apunta a <code>conectados.deprisa.com</code>.
							</p>
						</td>
					</tr>
				</table>

				<!-- ══ SECCIÓN 2: Credenciales API ══════════════════════════ -->
				<h3 class="ltms-section-title">🔑 Credenciales API</h3>
				<p class="description" style="margin-bottom:12px;">
					Las credenciales son asignadas por Deprisa a través de tu ejecutivo de cuenta.
					Formato de usuario: <code>WS00011111</code>.
				</p>
				<table class="form-table ltms-form-table">
					<tr>
						<th><label for="ltms_deprisa_username">Usuario (Basic Auth)</label></th>
						<td>
							<input type="text" id="ltms_deprisa_username"
								   name="ltms_deprisa_username"
								   value="<?php echo esc_attr( $username ); ?>"
								   class="regular-text" placeholder="WS00011111" autocomplete="off">
						</td>
					</tr>
					<tr>
						<th><label for="ltms_deprisa_password">Contraseña</label></th>
						<td>
							<input type="password" id="ltms_deprisa_password"
								   name="ltms_deprisa_password"
								   value="<?php echo esc_attr( $password ); ?>"
								   class="regular-text" autocomplete="new-password">
							<button type="button" class="button button-small"
									onclick="var f=document.getElementById('ltms_deprisa_password');f.type=f.type==='password'?'text':'password';">
								👁 Ver
							</button>
						</td>
					</tr>
				</table>

				<!-- ══ SECCIÓN 3: Datos del Remitente ════════════════════════ -->
				<h3 class="ltms-section-title">🏢 Datos del Remitente (tu tienda)</h3>
				<p class="description" style="margin-bottom:12px;">
					Estos datos se envían en cada admisión de envío como origen del paquete.
				</p>
				<table class="form-table ltms-form-table">
					<tr>
						<th><label for="ltms_deprisa_cliente_remitente">Código Cliente Alertran</label></th>
						<td>
							<input type="text" id="ltms_deprisa_cliente_remitente"
								   name="ltms_deprisa_cliente_remitente"
								   value="<?php echo esc_attr( $cliente_remitente ); ?>"
								   class="regular-text" placeholder="00000011" maxlength="8">
							<p class="description">8 dígitos. Asignado por Deprisa en Alertran.</p>
						</td>
					</tr>
					<tr>
						<th><label for="ltms_deprisa_centro_remitente">Centro Remitente</label></th>
						<td>
							<input type="text" id="ltms_deprisa_centro_remitente"
								   name="ltms_deprisa_centro_remitente"
								   value="<?php echo esc_attr( $centro_remitente ); ?>"
								   class="small-text" placeholder="01" maxlength="4">
							<p class="description">Código del centro cliente (generalmente <code>01</code>).</p>
						</td>
					</tr>
					<tr>
						<th><label for="ltms_deprisa_tipo_doc_remitente">Tipo de Documento</label></th>
						<td>
							<select id="ltms_deprisa_tipo_doc_remitente" name="ltms_deprisa_tipo_doc_remitente">
								<option value="NIT"  <?php selected( $tipo_doc_remitente, 'NIT' );  ?>>NIT</option>
								<option value="CC"   <?php selected( $tipo_doc_remitente, 'CC' );   ?>>Cédula de Ciudadanía</option>
								<option value="CE"   <?php selected( $tipo_doc_remitente, 'CE' );   ?>>Cédula de Extranjería</option>
								<option value="PASS" <?php selected( $tipo_doc_remitente, 'PASS' ); ?>>Pasaporte</option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="ltms_deprisa_nit_remitente">NIT / Documento</label></th>
						<td>
							<input type="text" id="ltms_deprisa_nit_remitente"
								   name="ltms_deprisa_nit_remitente"
								   value="<?php echo esc_attr( $nit_remitente ); ?>"
								   class="regular-text" placeholder="900123456">
						</td>
					</tr>
					<tr>
						<th><label for="ltms_deprisa_direccion_remitente">Dirección</label></th>
						<td>
							<input type="text" id="ltms_deprisa_direccion_remitente"
								   name="ltms_deprisa_direccion_remitente"
								   value="<?php echo esc_attr( $direccion_remitente ); ?>"
								   class="large-text" placeholder="Cra 7 # 100-23">
						</td>
					</tr>
					<tr>
						<th><label for="ltms_deprisa_ciudad_remitente">Ciudad</label></th>
						<td>
							<input type="text" id="ltms_deprisa_ciudad_remitente"
								   name="ltms_deprisa_ciudad_remitente"
								   value="<?php echo esc_attr( $ciudad_remitente ); ?>"
								   class="regular-text" placeholder="BOGOTA">
							<p class="description">En mayúsculas, tal como aparece en Alertran.</p>
						</td>
					</tr>
					<tr>
						<th><label for="ltms_deprisa_cp_remitente">Código Postal</label></th>
						<td>
							<input type="text" id="ltms_deprisa_cp_remitente"
								   name="ltms_deprisa_cp_remitente"
								   value="<?php echo esc_attr( $cp_remitente ); ?>"
								   class="small-text" placeholder="110911" maxlength="7">
						</td>
					</tr>
					<tr>
						<th><label for="ltms_deprisa_contacto_remitente">Persona de Contacto</label></th>
						<td>
							<input type="text" id="ltms_deprisa_contacto_remitente"
								   name="ltms_deprisa_contacto_remitente"
								   value="<?php echo esc_attr( $contacto_remitente ); ?>"
								   class="regular-text" placeholder="Bodega Principal">
						</td>
					</tr>
					<tr>
						<th><label for="ltms_deprisa_telefono_remitente">Teléfono de Contacto</label></th>
						<td>
							<input type="text" id="ltms_deprisa_telefono_remitente"
								   name="ltms_deprisa_telefono_remitente"
								   value="<?php echo esc_attr( $telefono_remitente ); ?>"
								   class="regular-text" placeholder="6012345678">
						</td>
					</tr>
				</table>

				<!-- ══ SECCIÓN 4: Configuración de Envío ══════════════════════ -->
				<h3 class="ltms-section-title">📦 Configuración de Envío</h3>
				<table class="form-table ltms-form-table">
					<tr>
						<th><label for="ltms_deprisa_servicio_default">Código de Servicio por Defecto</label></th>
						<td>
							<select id="ltms_deprisa_servicio_default" name="ltms_deprisa_servicio_default">
								<option value="3005" <?php selected( $servicio_default, '3005' ); ?>>3005 — Deprisa Estándar B2B</option>
								<option value="3027" <?php selected( $servicio_default, '3027' ); ?>>3027 — Contraentrega</option>
							</select>
							<p class="description">
								Código usado cuando no se especifica uno en el pedido.
								Para contraentrega siempre se sobreescribe con <code>3027</code>.
							</p>
						</td>
					</tr>
				</table>

				<!-- ══ SECCIÓN 5: Test de conexión ══════════════════════════ -->
				<h3 class="ltms-section-title">🔌 Test de Conexión</h3>
				<table class="form-table ltms-form-table">
					<tr>
						<th>Verificar credenciales</th>
						<td>
							<button type="button" id="ltms-deprisa-test-btn" class="button button-secondary"
									<?php echo $credenciales_ok ? '' : 'disabled title="Guarda las credenciales primero"'; ?>>
								🔍 Probar conexión con Deprisa
							</button>
							<span id="ltms-deprisa-test-result" style="margin-left:12px; font-size:13px;"></span>
							<p class="description">
								Realiza una cotización de prueba (Bogotá → Cali, 1 kg) para verificar
								que las credenciales son válidas.
							</p>
						</td>
					</tr>
				</table>

				<!-- ══ BOTÓN GUARDAR ════════════════════════════════════════ -->
				<p class="submit">
					<input type="submit" name="ltms_save_deprisa" class="button-primary"
						   value="💾 Guardar configuración Deprisa">
				</p>

			</form><!-- /form -->

		</div><!-- /.ltms-settings-section -->

		<!-- ══ SCRIPT: Test de conexión vía AJAX ════════════════════════════ -->
		<script>
		(function($){
			$('#ltms-deprisa-test-btn').on('click', function(){
				var $btn    = $(this);
				var $result = $('#ltms-deprisa-test-result');

				$btn.prop('disabled', true).text('⏳ Probando...');
				$result.text('').css('color','');

				$.post(ajaxurl, {
					action : 'ltms_deprisa_test_connection',
					_wpnonce: '<?php echo wp_create_nonce( "ltms_deprisa_test" ); ?>'
				}, function(res){
					if ( res.success ) {
						$result.css('color','#155724').html('✅ ' + res.data.message);
					} else {
						$result.css('color','#721c24').html('❌ ' + res.data.message);
					}
					$btn.prop('disabled', false).text('🔍 Probar conexión con Deprisa');
				}).fail(function(){
					$result.css('color','#721c24').text('❌ Error de red. Intenta de nuevo.');
					$btn.prop('disabled', false).text('🔍 Probar conexión con Deprisa');
				});
			});
		})(jQuery);
		</script>

		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Guardar opciones                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Procesa el POST y guarda las opciones.
	 * Se engancha en la acción ltms_settings_save_deprisa.
	 */
	public static function save(): void {
		// Verificar nonce
		if ( ! isset( $_POST['ltms_deprisa_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ltms_deprisa_nonce'] ) ), 'ltms_save_deprisa_settings' )
		) {
			add_settings_error( 'ltms_deprisa', 'nonce_fail', 'Error de seguridad. Intenta de nuevo.', 'error' );
			return;
		}

		// Sanitizar y guardar
		update_option( 'ltms_deprisa_enabled',
			isset( $_POST['ltms_deprisa_enabled'] ) ? 1 : 0 );

		update_option( 'ltms_deprisa_sandbox',
			isset( $_POST['ltms_deprisa_sandbox'] ) && $_POST['ltms_deprisa_sandbox'] === '1' ? 1 : 0 );

		$campos_texto = [
			'ltms_deprisa_username',
			'ltms_deprisa_password',
			'ltms_deprisa_cliente_remitente',
			'ltms_deprisa_centro_remitente',
			'ltms_deprisa_tipo_doc_remitente',
			'ltms_deprisa_nit_remitente',
			'ltms_deprisa_direccion_remitente',
			'ltms_deprisa_ciudad_remitente',
			'ltms_deprisa_cp_remitente',
			'ltms_deprisa_contacto_remitente',
			'ltms_deprisa_telefono_remitente',
			'ltms_deprisa_servicio_default',
		];

		foreach ( $campos_texto as $key ) {
			$value = isset( $_POST[ $key ] )
				? sanitize_text_field( wp_unslash( $_POST[ $key ] ) )
				: '';
			update_option( $key, $value );
		}

		add_settings_error( 'ltms_deprisa', 'saved', '✅ Configuración de Deprisa guardada correctamente.', 'updated' );
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: Test de conexión                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Handler AJAX para probar la conexión con Deprisa.
	 * Registrar con:
	 *   add_action( 'wp_ajax_ltms_deprisa_test_connection', [ 'LTMS_Settings_Deprisa', 'ajax_test_connection' ] );
	 */
	public static function ajax_test_connection(): void {
		check_ajax_referer( 'ltms_deprisa_test', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Sin permisos.' ] );
		}

		$username = get_option( 'ltms_deprisa_username', '' );
		$password = get_option( 'ltms_deprisa_password', '' );
		$sandbox  = (bool) get_option( 'ltms_deprisa_sandbox', true );

		if ( empty( $username ) || empty( $password ) ) {
			wp_send_json_error( [ 'message' => 'Faltan credenciales. Guarda primero.' ] );
		}

		try {
			$api = new LTMS_Api_Deprisa( $username, $password, $sandbox );

			$result = $api->cotizar( [
				'tipoEnvio'             => 'N',
				'numeroBultos'          => 1,
				'kilos'                 => 1.0,
				'clienteRemitente'      => get_option( 'ltms_deprisa_cliente_remitente', '00000011' ),
				'centroRemitente'       => get_option( 'ltms_deprisa_centro_remitente',  '01' ),
				'paisRemitente'         => '057',
				'poblacionRemitente'    => get_option( 'ltms_deprisa_ciudad_remitente', 'BOGOTA' ),
				'paisDestinatario'      => '057',
				'poblacionDestinatario' => 'CALI',
				'importeValorDeclarado' => 50000,
				'tipoMoneda'            => 'COP',
			] );

			if ( $result['ok'] ) {
				$n   = count( $result['cotizaciones'] );
				$env = $sandbox ? 'Sandbox' : 'Producción';
				wp_send_json_success( [
					'message' => "Conexión exitosa en {$env}. Se encontraron {$n} producto(s) disponibles.",
				] );
			} else {
				$err = implode( ', ', array_column( $result['errors'], 'descripcion' ) );
				wp_send_json_error( [ 'message' => "API respondió con errores: {$err}" ] );
			}
		} catch ( LTMS_Deprisa_Exception $e ) {
			wp_send_json_error( [ 'message' => 'Excepción: ' . $e->getMessage() . ' (HTTP ' . $e->getCode() . ')' ] );
		}
	}
}
