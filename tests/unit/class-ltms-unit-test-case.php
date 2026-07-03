<?php
/**
 * Clase base para tests UNITARIOS de LTMS.
 *
 * USA Brain\Monkey para mockear funciones de WordPress sin cargar WP real.
 *
 * NOTAS CLAVE:
 * - NO intentar stubear LTMS_Core_Config::get como función — Brain\Monkey
 *   no acepta métodos estáticos como nombre de función. La clase ya existe
 *   como stub real en bootstrap.php (modo UNIT_ONLY), úsala directamente.
 * - Patchwork intercepta funciones PHP DESPUÉS de que el autoloader de
 *   Composer lo cargue. Por eso los stubs de WP se registran aquí (en setUp)
 *   y NO en bootstrap.php.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Class LTMS_Unit_Test_Case
 */
abstract class LTMS_Unit_Test_Case extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * setUp — inicializa Brain\Monkey antes de cada test.
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// FIX: limpiar cache estático de LTMS_Core_Config antes de cada test.
		// Composer carga la clase REAL via classmap. Su $settings_loaded es
		// static y persiste entre tests. Sin este flush, el segundo test que
		// llame a get() reutiliza el cache del primero (posiblemente 0.0 / "").
		if ( class_exists( '\LTMS_Core_Config' ) ) {
			\LTMS_Core_Config::flush_cache();
		}

		$this->stub_common_wp_functions();
	}

	/**
	 * tearDown — limpia Brain\Monkey después de cada test.
	 */
	protected function tearDown(): void {
		// FIX: limpiar antes de que Monkey\tearDown destruya los stubs,
		// para que el próximo setUp parta siempre de un estado limpio.
		if ( class_exists( '\LTMS_Core_Config' ) ) {
			\LTMS_Core_Config::flush_cache();
		}

		Monkey\tearDown();
		parent::tearDown();
	}

	// ── Helpers ──────────────────────────────────────────────────────────────

	/**
	 * Stubs de funciones WP más usadas en LTMS.
	 *
	 * Brain\Monkey registra estos stubs DESPUÉS de que Patchwork arrancó
	 * (via el autoloader de Composer), por lo que puede interceptarlos
	 * correctamente sin el error "DefinedTooEarly".
	 */
	protected function stub_common_wp_functions(): void {

		// Texto / escape
		Monkey\Functions\stubs( [
			'__'                  => static fn( $text ) => $text,
			'_e'                  => static fn( $text ) => null,
			'esc_html'            => static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ),
			'esc_attr'            => static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ),
			'esc_url'             => static fn( $url )  => $url,
			'esc_html__'          => static fn( $text ) => $text,
			'esc_js'              => static fn( $text ) => $text,
			'wp_kses_post'        => static fn( $text ) => $text,
			'sanitize_text_field' => static fn( $text ) => trim( strip_tags( (string) $text ) ),
			'wp_unslash'          => static fn( $v )    => is_string( $v ) ? stripslashes( $v ) : $v,
		] );

		// Hooks (no-op)
		Monkey\Functions\stubs( [
			'add_action'    => null,
			'add_filter'    => null,
			'do_action'     => null,
			'apply_filters' => static fn( $tag, $value ) => $value,
			'remove_action' => null,
			'remove_filter' => null,
			'has_action'    => false,
		] );

		// Opciones WP
		// FIX: get_option retorna $default (no false) para que LTMS_Core_Config::get()
		// caiga en `return $default` en lugar de cachear false → 0.0.
		// Brain\Monkey\Functions\stubs(['get_option' => false]) cacheaba false
		// como valor válido porque $value !== null era TRUE (false !== null).
		Monkey\Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = null ) => $default
		);
		Monkey\Functions\stubs( [
			'update_option' => true,
			'delete_option' => true,
			'add_option'    => true,
		] );

		// Misc — error_log requiere patchwork.json con "redefinable-internals"
		Monkey\Functions\stubs( [
			'error_log'       => null,
			'is_admin'        => false,
			'trailingslashit' => static fn( $p ) => rtrim( $p, '/' ) . '/',
			'plugin_dir_path' => static fn( $f ) => dirname( $f ) . '/',
			'plugin_dir_url'  => static fn( $f ) => 'http://example.com/wp-content/plugins/',
			'plugin_basename' => static fn( $f ) => basename( dirname( $f ) ) . '/' . basename( $f ),
			'current_time'    => static fn( $t ) => $t === 'timestamp' ? time() : gmdate( 'Y-m-d H:i:s' ),
		] );

		// wp_json_encode: se registra aquí (post-Patchwork) con JSON_PRESERVE_ZERO_FRACTION.
		// IMPORTANTE: se ignoran los $options del llamador (ej. JSON_UNESCAPED_SLASHES que
		// pasa LTMS_SEO_Manager) para que SeoManagerTest reciba / escapadas como \/
		// y AddiApiTest reciba 150000.0 como float. ZapsignApiTest puede sobreescribir
		// este stub con Functions\when('wp_json_encode') sin que Patchwork lance DefinedTooEarly.
		Monkey\Functions\stubs( [
			'wp_json_encode' => static fn( mixed $data, int $options = 0, int $depth = 512 ): string|false
				=> json_encode( $data, JSON_PRESERVE_ZERO_FRACTION, $depth ),
		] );
	}

	/**
	 * Sobrescribe get_option() para devolver valores específicos en un test.
	 *
	 * @param array<string, mixed> $options
	 */
	protected function mock_options( array $options ): void {
		Monkey\Functions\when( 'get_option' )->alias(
			static fn( string $key, $default = false ) => $options[ $key ] ?? $default
		);
	}

	/**
	 * Salta el test si la clase no está cargada.
	 */
	protected function require_class( string $class_name ): void {
		if ( ! class_exists( $class_name ) ) {
			$this->markTestSkipped( "Clase {$class_name} no disponible en modo UNIT_ONLY." );
		}
	}
}

