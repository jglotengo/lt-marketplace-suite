<?php
/**
 * ConsoleCleanTest - CONSOLE-CLEAN (2026-09-19).
 *
 * La consola de Chrome mostraba mensajes de debug/perf en TODAS las páginas
 * públicas (warnings de "Página lenta", "AJAX lento", debug de "Inicializado").
 * Estos logs informativos ensucian la consola de producción y no aportan al
 * usuario final. Fix: gate con `CONFIG.debug` (default false) en
 * ltms-ux-enhancements.js (monolito fuente de verdad para los bundles) y
 * `PV.config.debug` en ltms-plaza-viva.js.
 *
 * Los console.error de errores REALES (catch de excepciones, handlers) se
 * MANTIENEN sin gate — solo se silencian los avisos informativos.
 *
 * NOTA de integridad: los edits al monolito NO deben cambiar el número de
 * líneas (el SECTION_MAP de bin/build-ux-bundles.js usa líneas hardcodeadas) —
 * por eso el fix se aplica en la misma línea (sin insertar/borrar líneas).
 *
 * Tests source-based (file_get_contents + asserts), deterministas en
 * LTMS_UNIT_ONLY=true.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

final class ConsoleCleanTest extends LTMS_Unit_Test_Case {

	private const MONOLITH_JS = __DIR__ . '/../../assets/js/ltms-ux-enhancements.js';
	private const SHARED_JS   = __DIR__ . '/../../assets/js/ltms-ux-shared.js';
	private const PLAZA_JS    = __DIR__ . '/../../assets/js/ltms-plaza-viva.js';

	public function test_monolith_has_debug_flag_in_config(): void {
		$src = (string) file_get_contents( self::MONOLITH_JS );

		$this->assertStringContainsString(
			'debug: false,',
			$src,
			'CONSOLE-CLEAN: el CONFIG del monolito DEBE tener debug:false por defecto (producción).'
		);
	}

	public function test_perf_warns_are_gated(): void {
		$src = (string) file_get_contents( self::MONOLITH_JS );

		$this->assertStringContainsString(
			"if (CONFIG.debug) console.warn('[LTMS.UX] Página lenta:",
			$src,
			'CONSOLE-CLEAN: el warn de Página lenta DEBE estar gateado por CONFIG.debug.'
		);
		$this->assertStringContainsString(
			"if (CONFIG.debug) console.warn('[LTMS.UX] AJAX lento:",
			$src,
			'CONSOLE-CLEAN: el warn de AJAX lento DEBE estar gateado por CONFIG.debug.'
		);
	}

	public function test_init_debug_is_gated(): void {
		$src = (string) file_get_contents( self::MONOLITH_JS );

		$this->assertStringContainsString(
			'if (CONFIG.debug && window.console && console.debug)',
			$src,
			'CONSOLE-CLEAN: el debug de Inicializado DEBE estar gateado por CONFIG.debug.'
		);
	}

	public function test_real_error_catches_are_kept(): void {
		$src = (string) file_get_contents( self::MONOLITH_JS );

		// Los console.error de catch de excepciones reales NO deben gatearse.
		$this->assertStringContainsString(
			"console.error('[LTMS.UX] Error inicializando:', err)",
			$src,
			'CONSOLE-CLEAN: el error real de init DEBE conservarse sin gate.'
		);
		$this->assertStringContainsString(
			"console.error('[LTMS.UX] Error capturado:', e.error || e.message)",
			$src,
			'CONSOLE-CLEAN: el error capturado DEBE conservarse sin gate.'
		);
	}

	public function test_shared_bundle_contains_gates(): void {
		$src = (string) file_get_contents( self::SHARED_JS );

		$this->assertStringContainsString(
			'CONFIG.debug',
			$src,
			'CONSOLE-CLEAN: el bundle shared (que corre en todas las páginas) DEBE incluir los gates de debug.'
		);
	}

	public function test_plaza_viva_chat_warn_gated(): void {
		$src = (string) file_get_contents( self::PLAZA_JS );

		$this->assertStringContainsString(
			"PV.config.debug && window.console && window.console.warn",
			$src,
			'CONSOLE-CLEAN: el warn de chat no disponible en plaza-viva DEBE estar gateado por PV.config.debug.'
		);
	}
}