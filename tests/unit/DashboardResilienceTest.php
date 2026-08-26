<?php
/**
 * Tests estructurales de resiliencia de red del dashboard del vendedor.
 *
 * AUDIT-DASH-NET-01: el SPA del panel tenia 24 llamadas $.ajax sin handler
 * .fail y sin timeout — cuando la red del cliente se suspende
 * (net::ERR_NETWORK_IO_SUSPENDED: pestana congelada en segundo plano,
 * ahorro de bateria/datos, AV/proxy) las cargas quedaban girando para
 * siempre. El fix anade timeout global via ajaxPrefilter, red ajaxError
 * con toast accionable y pausa de polling con document.hidden.
 *
 * Filesystem-only (mismo patron que PlazaVivaDesignSystemAuditTest):
 * determinista en LTMS_UNIT_ONLY=true.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

final class DashboardResilienceTest extends LTMS_Unit_Test_Case {

	private string $js_path;
	private string $js_min_path;

	protected function setUp(): void {
		parent::setUp();
		$this->js_path     = dirname( __DIR__, 2 ) . '/assets/js/ltms-dashboard.js';
		$this->js_min_path = dirname( __DIR__, 2 ) . '/assets/js/ltms-dashboard.min.js';
	}

	/**
	 * NET-01 (1): toda llamada AJAX del panel debe tener techo temporal.
	 * El prefilter global cubre los 24 bloques existentes y los futuros.
	 */
	public function test_001_timeout_global_via_ajaxprefilter(): void {
		$src = file_get_contents( $this->js_path );
		$this->assertNotSame( '', $src );

		$this->assertStringContainsString(
			'ajaxPrefilter',
			$src,
			'NET-01 fix: falta el ajaxPrefilter de timeout global'
		);
		$this->assertMatchesRegularExpression(
			'/ajaxPrefilter[\s\S]{0,400}ltms_ajax=1[\s\S]{0,200}timeout\s*=\s*20000/',
			$src,
			'NET-01 fix: el prefilter debe fijar timeout 20s para rutas ltms_ajax/admin-ajax'
		);
	}

	/**
	 * NET-01 (2): los fallos de red deben ser visibles para el usuario
	 * (toast accionable, una vez por racha) y limpiar loaders pegados.
	 */
	public function test_002_red_ajaxerror_con_aviso_y_limpieza(): void {
		$src = file_get_contents( $this->js_path );

		$this->assertMatchesRegularExpression(
			"/on\('ajaxError'/",
			$src,
			'NET-01 fix: falta la red global ajaxError'
		);
		$this->assertStringContainsString(
			'Sin conexión con el servidor',
			$src,
			'NET-01 fix: falta el mensaje accionable para el vendedor'
		);
		$this->assertStringContainsString(
			'.ltms-view-loader\').hide()',
			$src,
			'NET-01 fix: ajaxError debe limpiar el loader visible'
		);
	}

	/**
	 * NET-01 (3): el polling NO debe dispararse con la pestana oculta
	 * (origen del ERR_NETWORK_IO_SUSPENDED) y al volver al frente debe
	 * reponer nonce + vista actual.
	 */
	public function test_003_polling_pausado_en_segundo_plano(): void {
		$src = file_get_contents( $this->js_path );

		// Guard en ambos pollers (nonce-refresh y notificaciones). El ancla
		// funcional ($ .post / fetchNotifications) evita confundirlos con
		// el guard de visibilitychange y es tolerante a comentarios
		// multilínea entre medias.
		$this->assertMatchesRegularExpression(
			'/setInterval\(function \(\) \{[\s\S]{0,400}?if \(document\.hidden\) \{\s*return;\s*\}\s*\$\.post\(ltmsDashboard\.ajax_url/',
			$src,
			'NET-01 fix: el polling de nonce-refresh debe saltar su tick con document.hidden'
		);
		$this->assertMatchesRegularExpression(
			'/notifTimer = setInterval\(\(\) => \{[\s\S]{0,200}?if \(document\.hidden\) \{\s*return;\s*\}\s*this\.fetchNotifications\(\)/',
			$src,
			'NET-01 fix: el polling de notificaciones debe saltar su tick con document.hidden'
		);

		// Reposicion al volver al frente.
		$this->assertMatchesRegularExpression(
			"/visibilitychange[\s\S]{0,300}initNonceRefresh\(\)[\s\S]{0,200}loadView\(self\.currentView,\s*true\)/",
			$src,
			'NET-01 fix: visibilitychange debe refrescar nonce y recargar la vista actual'
		);
	}

	/**
	 * NET-01 (4): el .min.js del panel queda sincronizado con la fuente.
	 */
	public function test_004_min_sincronizado(): void {
		$this->assertFileExists( $this->js_min_path );
		$min = file_get_contents( $this->js_min_path );
		foreach ( array( 'ajaxPrefilter', 'visibilitychange', 'Sin conexión con el servidor' ) as $needle ) {
			$this->assertStringContainsString(
				$needle,
				$min,
				'NET-01 fix: ltms-dashboard.min.js desincronizado — correr node scripts/build.js --js-only'
			);
		}
	}
}
