<?php
/**
 * GeoDetectorTest — Tests unitarios para LTMS_Geo_Detector
 *
 * E2E-BUYER-GEO-DEAD (2026-11-24, P3): la superficie de la clase se redujo —
 * se eliminaron get_visitor_location() (llamada síncrona a ip-api.com que
 * añadía hasta 2s de TTFB por visitante sin cookie, con API limitada a
 * 45 req/min), register_city_rewrite_rules() (rewrites/query vars sin
 * consumer) y enqueue_city_selector() (localize ltmsGeo sin JS consumidor).
 * La suite original cubría los métodos eliminados — reescrita a la superficie
 * viva (lección #119: los tests de superficies retiradas se actualizan en el
 * mismo commit):
 *  - init()              — flag de idempotencia + guard de config
 *  - init_session_location — sync ?ltms_city= → cookie+sesión (cookie
 *    documentada en el catálogo de privacidad)
 *  - invariante anti-regresión: la clase NO hace llamadas HTTP remotas
 *    (ni wp_remote_get) y NO registra wiring muerto (rewrites/localize).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * @covers LTMS_Geo_Detector
 */
class GeoDetectorTest extends LTMS_Unit_Test_Case {

	private const GEO_PATH = __DIR__ . '/../../includes/frontend/class-ltms-geo-detector.php';

	private \ReflectionClass $ref;

	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'LTMS_Geo_Detector' ) ) {
			$this->markTestSkipped( 'LTMS_Geo_Detector no disponible.' );
		}

		$this->ref = new \ReflectionClass( 'LTMS_Geo_Detector' );

		$init = $this->ref->getProperty( 'initialized' );
		$init->setAccessible( true );
		$init->setValue( null, false );

		unset( $_COOKIE['ltms_city'] );
	}

	protected function tearDown(): void {
		unset( $_COOKIE['ltms_city'] );

		if ( class_exists( 'LTMS_Geo_Detector' ) ) {
			$init = $this->ref->getProperty( 'initialized' );
			$init->setAccessible( true );
			$init->setValue( null, false );
		}

		parent::tearDown();
	}

	// ════════════════════════════════════════════════════════════════════════
	// init() — idempotencia
	// ════════════════════════════════════════════════════════════════════════

	public function test_init_is_idempotent(): void {
		$prop = $this->ref->getProperty( 'initialized' );
		$prop->setAccessible( true );

		\LTMS_Geo_Detector::init();
		$this->assertTrue( $prop->getValue() );

		// Segunda llamada: el flag corta re-registros.
		\LTMS_Geo_Detector::init();
		$this->assertTrue( $prop->getValue() );
	}

	public function test_init_session_location_exists_public(): void {
		$m = $this->ref->getMethod( 'init_session_location' );
		$this->assertTrue( $m->isPublic() );
		$this->assertTrue( $m->isStatic() );
	}

	// ════════════════════════════════════════════════════════════════════════
	// Invariantes anti-regresión (E2E-BUYER-GEO-DEAD)
	// ════════════════════════════════════════════════════════════════════════

	public function test_no_blocking_remote_calls(): void {
		$src = (string) file_get_contents( self::GEO_PATH );

		$this->assertStringNotContainsString(
			'wp_remote_get',
			$src,
			'El geo-detector NO debe hacer llamadas HTTP síncronas en el request path (hasta 2s de TTFB por visitante sin cookie, E2E-BUYER-GEO-DEAD).'
		);
		$this->assertStringNotContainsString(
			'ip-api.com',
			$src,
			'La llamada a ip-api.com fue eliminada — no debe reintroducirse de forma síncrona.'
		);
	}

	public function test_no_dead_wiring(): void {
		$src = (string) file_get_contents( self::GEO_PATH );

		$this->assertStringNotContainsString( 'register_city_rewrite_rules', $src, 'Las rewrites ^productos/{ciudad} no tenían consumer — eliminadas.' );
		$this->assertStringNotContainsString( 'enqueue_city_selector', $src, 'El localize ltmsGeo no tenía JS consumidor — eliminado.' );
		$this->assertStringNotContainsString( 'ltmsGeo', $src, 'El objeto JS fantasma ltmsGeo no debe persistir.' );
		$this->assertStringNotContainsString( 'ltms_geo_productos', $src, 'Query var muerta eliminada.' );
	}

	public function test_cookie_sync_preserved_for_privacy_catalog(): void {
		$src = (string) file_get_contents( self::GEO_PATH );

		$this->assertStringContainsString( 'init_session_location', $src, 'El sync ?ltms_city= → cookie+sesión se conserva.' );
		$this->assertStringContainsString( "'ltms_city'", $src, 'La cookie ltms_city está documentada en el catálogo de privacidad (LTMS_Legal_Compliance) — no eliminar sin actualizar el catálogo.' );
		$this->assertStringContainsString( 'E2E-BUYER-GEO-DEAD', $src, 'El fix debe estar marcado con su ID de tarea.' );
	}
}
