<?php
/**
 * RegChoiceE2ETest — auditoría de confusión comprador/vendedor (REG-CHOICE,
 * 2026-10-22). El operador reportó que los usuarios del home se confunden con
 * el proceso de registro para vendedores y para compradores.
 *
 * Hallazgos corregidos (verificados en producción vía SSH):
 *  - REG-CHOICE-001 (P0): los CTAs "Registrarme como vendedor" apuntaban a
 *    /vendedor/registro (slug fantasma — solo funcionaba vía un redirect 301
 *    manual ajeno al plugin) y "Ver vendedores" a /vendedores (404 real en
 *    producción). Ahora se resuelven por ID desde ltms_installed_pages con
 *    fallback al slug canónico, preservando los filtros.
 *  - REG-CHOICE-002 (P1): sin CTA de registro para compradores — el icono
 *    "Cuenta" y el botón guest del header nav no comunicaban que ahí se crea
 *    la cuenta de comprador. Ahora "Ingresar / Registrarse" para guests.
 *  - REG-CHOICE-003 (P1): la franja "Vende con nosotros" tenía un único CTA
 *    de vendedor — el único registro rotulado de la home. Ahora es dual:
 *    "Quiero vender" (primario dorado) + "Quiero comprar" (ghost secundario
 *    hacia /mi-cuenta/).
 *  - REG-CHOICE-004 (P1): el wizard de registro de vendedor no tenía escape
 *    para quien entró por error solo queriendo comprar. Ahora footer con
 *    link "Crea tu cuenta de comprador" (solo en modo registro normal, no en
 *    complete_profile de Google OAuth donde el usuario ya es vendor).
 *
 * Tests PURAMENTE estructurales (file_get_contents + asserts): deterministas
 * en LTMS_UNIT_ONLY=true (mismo patrón que HomeTrustSellTest).
 *
 * @package LTMS\Tests\Unit
 * @group reg-choice-e2e
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class RegChoiceE2ETest
 *
 * @group reg-choice-e2e
 */
final class RegChoiceE2ETest extends LTMS_Unit_Test_Case {

	private string $home_path;
	private string $register_form_path;
	private string $header_js_path;
	private string $header_js_min_path;
	private string $ux_css_path;
	private string $ux_css_min_path;
	private string $my_account_path;
	private string $pv_css_path;
	private string $pv_css_min_path;

	protected function setUp(): void {
		parent::setUp();
		$this->home_path          = dirname( __DIR__, 2 ) . '/includes/frontend/templates/home.php';
		$this->register_form_path = dirname( __DIR__, 2 ) . '/includes/frontend/views/vendor-parts/form-register.php';
		$this->header_js_path     = dirname( __DIR__, 2 ) . '/assets/js/ltms-header-nav.js';
		$this->header_js_min_path = dirname( __DIR__, 2 ) . '/assets/js/ltms-header-nav.min.js';
		$this->ux_css_path        = dirname( __DIR__, 2 ) . '/assets/css/ltms-ux-enhancements.css';
		$this->ux_css_min_path    = dirname( __DIR__, 2 ) . '/assets/css/ltms-ux-enhancements.min.css';
		$this->my_account_path    = dirname( __DIR__, 2 ) . '/includes/frontend/templates/my-account.php';
		$this->pv_css_path        = dirname( __DIR__, 2 ) . '/assets/css/ltms-plaza-viva.css';
		$this->pv_css_min_path    = dirname( __DIR__, 2 ) . '/assets/css/ltms-plaza-viva.min.css';
	}

	/**
	 * Helper: stripear los comentarios PHP de tipo slash-asterisco (LECCIONES #141).
	 * Necesario para no dar por bueno un slug fantasma que solo viva en un comentario.
	 */
	private function strip_php_comments( string $src ): string {
		return (string) preg_replace( '/\/\*.*?\*\//s', '', $src );
	}

	/**
	 * REG-CHOICE-001-A: home.php sin slugs fantasma en código ejecutable.
	 * /vendedor/registro era 301 manual y /vendedores era 404 real (SG).
	 */
	public function test_001_home_sin_slugs_fantasma(): void {
		$this->assertFileExists( $this->home_path );
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		$this->assertStringNotContainsString(
			"/vendedor/registro",
			$src,
			'REG-CHOICE-001-A: el slug fantasma /vendedor/registro no debe quedar en home.php (404 latente si el redirect manual desaparece).'
		);
		$this->assertStringNotContainsString(
			"/vendedores",
			$src,
			'REG-CHOICE-001-A: el slug fantasma /vendedores (404 real en producción) no debe quedar en home.php.'
		);
	}

	/**
	 * REG-CHOICE-001-B: resolución canónica por ID desde ltms_installed_pages
	 * (mapa del activator) con fallback al slug real, preservando los filtros
	 * originales (los tests HomeTrustSellTest/HomeFooterTest los exigen).
	 */
	public function test_002_home_resuelve_urls_por_installed_pages(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		// Fuente de verdad: mapa del activator + get_permalink.
		$this->assertMatchesRegularExpression(
			"/\\\$pv_installed_pages\s*=\s*get_option\(\s*'ltms_installed_pages'/",
			$src,
			'REG-CHOICE-001-B: las URLs deben resolverse desde ltms_installed_pages.'
		);
		$this->assertStringContainsString(
			"\$pv_installed_pages['ltms-vendor-register']",
			$src,
			'REG-CHOICE-001-B: debe leerse la clave ltms-vendor-register del mapa.'
		);
		$this->assertStringContainsString(
			"\$pv_installed_pages['ltms-sellers']",
			$src,
			'REG-CHOICE-001-B: debe leerse la clave ltms-sellers del mapa.'
		);
		// Fallbacks a los slugs CANÓNICOS (creados por el activator, 200 en SG).
		$this->assertStringContainsString(
			"home_url( '/registro-vendedor/' )",
			$src,
			'REG-CHOICE-001-B: fallback del registro de vendedor = /registro-vendedor/ (slug real).'
		);
		$this->assertStringContainsString(
			"home_url( '/sellers/' )",
			$src,
			'REG-CHOICE-001-B: fallback de la landing de vendedores = /sellers/ (slug real).'
		);
		// Filtros originales preservados (customizaciones y tests legacy).
		$this->assertStringContainsString( 'ltms_become_seller_url', $src );
		$this->assertStringContainsString( 'ltms_sellers_page_url', $src );
	}

	/**
	 * REG-CHOICE-001-C: los 4 CTAs consumen las variables canónicas.
	 */
	public function test_003_home_ctas_usan_variables_canonicas(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		// Franja "Vende con nosotros": CTA vendedor.
		$this->assertMatchesRegularExpression(
			'/class="pv-home-sell__cta"\s*href="<\?php echo esc_url\( \$pv_seller_register_url \); \?>">/',
			$src,
			'REG-CHOICE-001-C: el CTA de la franja debe usar $pv_seller_register_url.'
		);
		// Políticas: Regístrate como vendedor + Ver vendedores.
		$this->assertMatchesRegularExpression(
			'/href="<\?php echo esc_url\( \$pv_seller_register_url \); \?>">.*Regístrate como vendedor/s',
			$src,
			'REG-CHOICE-001-C: el link de políticas "Regístrate como vendedor" debe usar la URL canónica.'
		);
		$this->assertMatchesRegularExpression(
			'/href="<\?php echo esc_url\( \$pv_sellers_landing_url \); \?>">.*Ver vendedores/s',
			$src,
			'REG-CHOICE-001-C: el link de políticas "Ver vendedores" debe usar la URL canónica.'
		);
		// Star sellers "Ver todos".
		$this->assertMatchesRegularExpression(
			'/class="pv-section__more" href="<\?php echo esc_url\( \$pv_sellers_landing_url \); \?>">/',
			$src,
			'REG-CHOICE-001-C: el link "Ver todos" de vendedores destacados debe usar la URL canónica.'
		);
	}

	/**
	 * REG-CHOICE-003-A: franja dual — dos CTAs con roles distintos.
	 */
	public function test_004_franja_dual_dos_botones(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		// Contenedor dual presente.
		$this->assertStringContainsString(
			'class="pv-home-sell__ctas"',
			$src,
			'REG-CHOICE-003-A: la franja debe tener el contenedor dual de CTAs.'
		);
		// Botón vendedor (primario dorado).
		$this->assertStringContainsString(
			"esc_html_e( 'Quiero vender', 'ltms' )",
			$src,
			'REG-CHOICE-003-A: el CTA vendedor debe rotularse "Quiero vender".'
		);
		// Botón comprador (secundario ghost) hacia /mi-cuenta/.
		$this->assertStringContainsString(
			"esc_html_e( 'Quiero comprar', 'ltms' )",
			$src,
			'REG-CHOICE-003-A: debe existir el CTA comprador "Quiero comprar".'
		);
		$this->assertMatchesRegularExpression(
			'/class="pv-home-sell__cta pv-home-sell__cta--ghost"\s*href="<\?php echo esc_url\( \$pv_account_url \); \?>">/',
			$src,
			'REG-CHOICE-003-A: el CTA comprador debe ser ghost y apuntar a $pv_account_url (/mi-cuenta/).'
		);
	}

	/**
	 * REG-CHOICE-003-B: CSS del contenedor dual + botón ghost, con la
	 * variante dorada base y los media queries legacy intactos.
	 */
	public function test_005_css_franja_dual(): void {
		$src = file_get_contents( $this->home_path );

		// Contenedor: columna + stretch en móvil/tablet, fila en desktop.
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-home \.pv-home-sell__ctas\{[^}]*flex-direction:column;[^}]*align-items:stretch;/s',
			$src,
			'REG-CHOICE-003-B: el contenedor dual debe apilar los botones a todo el ancho en móvil.'
		);
		$this->assertMatchesRegularExpression(
			'/@media \(min-width:1024px\)\{[^@]*\.pv-scope\.pv-home \.pv-home-sell__ctas\{flex-direction:row;/s',
			$src,
			'REG-CHOICE-003-B: en escritorio los dos CTAs van en fila junto al texto.'
		);
		// Ghost: fondo oscuro translúcido + borde blanco (contraste AA sobre el rojo).
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-home \.pv-home-sell__cta--ghost\{[^}]*background:rgba\(0,0,0,\.22\);color:#fff;[^}]*border:1\.5px solid rgba\(255,255,255,\.75\);/s',
			$src,
			'REG-CHOICE-003-B: el botón comprador debe ser ghost (fondo oscuro translúcido + borde blanco).'
		);
		// Legacy BRAND-2026-001 preservado: base dorada y gradiente rojo intactos.
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-home \.pv-home-sell__cta\{[^}]*background:var\(--ltms-gold\);color:#000;/s',
			$src,
			'REG-CHOICE-003-B: el CTA vendedor conserva el dorado de marca (BRAND-2026-001).'
		);
		$this->assertMatchesRegularExpression(
			'/@media \(max-width:560px\)\{[^@]*\.pv-scope\.pv-home \.pv-home-sell__cta\{width:100%;/s',
			$src,
			'REG-CHOICE-003-B: en móvil los CTAs siguen a todo el ancho (regla legacy intacta).'
		);
	}

	/**
	 * REG-CHOICE-002-A: label guest "Ingresar / Registrarse" en el icono de
	 * cuenta de la home (logueados siguen viendo "Cuenta").
	 */
	public function test_006_home_label_guest_condicional(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		$this->assertMatchesRegularExpression(
			"/is_user_logged_in\(\)\s*\?\s*esc_html__\(\s*'Cuenta',\s*'ltms'\s*\)\s*:\s*esc_html__\(\s*'Ingresar \/ Registrarse',\s*'ltms'\s*\)/",
			$src,
			'REG-CHOICE-002-A: el label del icono cuenta debe ser condicional (guest: "Ingresar / Registrarse").'
		);
	}

	/**
	 * REG-CHOICE-002-B: el botón guest del header nav global (que REEMPLAZA
	 * al icono en runtime) usa el mismo label, sincronizado en el .min.js
	 * que sirve SG Optimizer.
	 */
	public function test_007_header_nav_label_guest_sincronizado(): void {
		$this->assertFileExists( $this->header_js_path );
		$src = file_get_contents( $this->header_js_path );

		$this->assertMatchesRegularExpression(
			'/ltms-btn-label">Ingresar \/ Registrarse<\/span>/',
			$src,
			'REG-CHOICE-002-B: buildClienteBtn() debe rotular el botón guest "Ingresar / Registrarse".'
		);
		$this->assertStringNotContainsString(
			'ltms-btn-label">Mi Cuenta</span>',
			$src,
			'REG-CHOICE-002-B: el label guest "Mi Cuenta" debe eliminarse de buildClienteBtn().'
		);

		// Sincronización .min.js (SG Optimizer carga el min en producción).
		$min = file_get_contents( $this->header_js_min_path );
		$this->assertStringContainsString(
			'Ingresar / Registrarse',
			$min,
			'REG-CHOICE-002-B: el .min.js debe contener el nuevo label (regenerado).'
		);
		$this->assertStringNotContainsString(
			'ltms-btn-label">Mi Cuenta',
			$min,
			'REG-CHOICE-002-B: el .min.js no debe conservar el label guest viejo.'
		);
	}

	/**
	 * REG-CHOICE-004-A: escape hatch comprador en el wizard de vendedor.
	 * Solo en modo registro normal — en complete_profile (Google OAuth) el
	 * usuario YA es vendor y preguntarle sería absurdo.
	 */
	public function test_008_escape_hatch_wizard_vendedor(): void {
		$this->assertFileExists( $this->register_form_path );
		$src = file_get_contents( $this->register_form_path );

		// Condición: solo registro normal (no complete_profile ni profile_incomplete).
		$this->assertMatchesRegularExpression(
			'/if \( ! \$complete_profile && ! \$profile_incomplete \) :/',
			$src,
			'REG-CHOICE-004-A: el escape hatch debe ocultarse en el modo complete_profile (usuario ya es vendor por OAuth).'
		);
		$this->assertStringContainsString(
			'ltms-auth-switch-buyer',
			$src,
			'REG-CHOICE-004-A: debe existir el bloque de escape comprador en el footer del wizard.'
		);
		$this->assertStringContainsString(
			"esc_html_e( '¿Solo quieres comprar?', 'ltms' )",
			$src,
			'REG-CHOICE-004-A: el escape hatch debe rotularse "¿Solo quieres comprar?".'
		);
		$this->assertStringContainsString(
			"esc_html_e( 'Crea tu cuenta de comprador', 'ltms' )",
			$src,
			'REG-CHOICE-004-A: el link debe rotularse "Crea tu cuenta de comprador".'
		);
		// URL: myaccount de WooCommerce con guard (patrón defensivo del proyecto).
		$this->assertMatchesRegularExpression(
			"/function_exists\(\s*'wc_get_page_permalink'\s*\)\s*\?\s*wc_get_page_permalink\(\s*'myaccount'\s*\)/",
			$src,
			'REG-CHOICE-004-A: la URL del comprador debe resolverse con wc_get_page_permalink(myaccount) bajo guard.'
		);
	}

	/**
	 * REG-CHOICE-004-B: CSS del escape hatch sincronizado con el .min.css.
	 */
	public function test_009_css_escape_hatch_sincronizado(): void {
		$this->assertFileExists( $this->ux_css_path );
		$src = file_get_contents( $this->ux_css_path );

		$this->assertMatchesRegularExpression(
			'/\.ltms-auth-footer \.ltms-auth-switch-buyer\s*\{[^}]*margin-top:\s*10px;/s',
			$src,
			'REG-CHOICE-004-B: el escape hatch debe tener separación respecto al párrafo de login.'
		);

		// Sincronización .min.css (SG Optimizer sirve el min en producción).
		$min = file_get_contents( $this->ux_css_min_path );
		$this->assertStringContainsString(
			'ltms-auth-switch-buyer',
			$min,
			'REG-CHOICE-004-B: el .min.css debe contener la regla del escape hatch (regenerado).'
		);
	}

	/**
	 * REG-CHOICE-005: bump de LTMS_VERSION (cache-busting de CSS/JS en SG).
	 * Rango >= 2.9.427 (lección #188: pin exacto frágil ante bumps futuros).
	 */
	public function test_010_version_bump_cache_busting(): void {
		$main = (string) file_get_contents( dirname( __DIR__, 2 ) . '/lt-marketplace-suite.php' );
		$this->assertMatchesRegularExpression(
			"/define\(\s*'LTMS_VERSION',\s*'2\.9\.4(2[7-9]|[3-9]\d|\d{3,})'\s*\)/",
			$main,
			'REG-CHOICE-005: LTMS_VERSION debe ser >= 2.9.427 para forzar cache-busting de los assets tocados.'
		);
	}

	/**
	 * REG-CHOICE-005-A (2026-10-23): botón "Vender" dorado ESTÁTICO en el
	 * header de la home — captación de vendedores arriba del pliegue (la
	 * franja vive al final) y sin depender de la inyección JS. Corrige la
	 * regresión: el header-nav JS secuestraba por substring el link "Ver
	 * todos" (href /sellers/) y la home perdía el botón Vender del header.
	 */
	public function test_011_header_home_boton_vender_estatico(): void {
		$src = $this->strip_php_comments( file_get_contents( $this->home_path ) );

		// Markup: botón dorado como PRIMER hijo de la zona de acciones, href
		// canónico ($pv_sellers_landing_url) + badge GRATIS.
		$this->assertMatchesRegularExpression(
			'/<a class="pv-home-header__sell" href="<\?php echo esc_url\( \$pv_sellers_landing_url \); \?>"/',
			$src,
			'REG-CHOICE-005-A: el header de la home debe tener el botón Vender estático con URL canónica.'
		);
		$this->assertStringContainsString(
			'pv-home-header__sell-free',
			$src,
			'REG-CHOICE-005-A: el botón Vender debe llevar el badge GRATIS.'
		);

		// CSS: dorado de marca + texto negro (BRAND-2026-001, AA 11.8:1) y
		// compactación móvil.
		$css = file_get_contents( $this->home_path );
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-home \.pv-home-header__sell\{[^}]*background:var\(--ltms-gold\);color:#000;/s',
			$css,
			'REG-CHOICE-005-A: el botón Vender del header debe ser dorado de marca con texto negro.'
		);
		$this->assertMatchesRegularExpression(
			'/@media \(max-width:479px\)\{[^@]*\.pv-scope\.pv-home \.pv-home-header__sell-free\{display:none;\}/s',
			$css,
			'REG-CHOICE-005-A: en móvil el badge GRATIS se oculta (header compacto).'
		);
	}

	/**
	 * REG-CHOICE-005-B: el header-nav JS NO secuestra los CTAs de la home
	 * nativa (guard isNativeHome) y NO inyecta un botón Vender duplicado
	 * (el $wrap solo lleva cuenta en la home). Sincronizado con el .min.js.
	 */
	public function test_012_header_nav_guard_home_nativa(): void {
		$src = file_get_contents( $this->header_js_path );

		$this->assertMatchesRegularExpression(
			"/var isNativeHome = !!document\.querySelector\('\.pv-scope\.pv-home'\);/",
			$src,
			'REG-CHOICE-005-B: injectButtons() debe detectar la home nativa antes de inyectar.'
		);
		$this->assertMatchesRegularExpression(
			"/if \(!isNativeHome\) \\\$wrap\.append\(buildSellerBtn\(sellerUrl\)\);/",
			$src,
			'REG-CHOICE-005-B: en la home nativa el wrap NO debe incluir el botón Vender (evita duplicado).'
		);
		$this->assertMatchesRegularExpression(
			"/if \(isNativeHome\) return false;/",
			$src,
			'REG-CHOICE-005-B: el match por substring de sellerEl debe excluir los CTAs de la home (fin del secuestro).'
		);
		$this->assertMatchesRegularExpression(
			"/if \(document\.querySelector\('\.pv-scope\.pv-home'\)\) return;\s*\n\s*var \\\$btn/",
			$src,
			'REG-CHOICE-005-B: el re-flow móvil de resize debe saltarse la home nativa.'
		);

		// Sincronización .min.js (SG Optimizer carga el min en producción).
		$min = file_get_contents( $this->header_js_min_path );
		$this->assertStringContainsString(
			'.pv-scope.pv-home',
			$min,
			'REG-CHOICE-005-B: el .min.js debe contener el guard de la home nativa (regenerado).'
		);
	}

	/**
	 * REG-CHOICE-007 (2026-10-23): red de captura de vendedores en la vista
	 * guest de /mi-cuenta/ — banner ANTES del form login/registro con link
	 * directo al wizard de vendedor (resolución canónica por installed_pages).
	 * Un vendedor nuevo que se registra aquí queda atrapado como customer
	 * (no existe conversión customer->vendor por email).
	 */
	public function test_013_banner_vendedor_mi_cuenta_guest(): void {
		$this->assertFileExists( $this->my_account_path );
		$src = file_get_contents( $this->my_account_path );

		// Dentro de la rama guest (el banner vive en el bloque !is_user_logged_in).
		$this->assertMatchesRegularExpression(
			"/if \( ! is_user_logged_in\(\) \) \{[\s\S]*?pv-account__seller-net[\s\S]*?wc_get_template\( 'myaccount\/form-login\.php' \)/",
			$src,
			'REG-CHOICE-007: el banner debe estar en la rama guest, ANTES del form de login de WC.'
		);
		$this->assertMatchesRegularExpression(
			"/\\\$pv_installed_pages\['ltms-vendor-register'\]/",
			$src,
			'REG-CHOICE-007: la URL del wizard debe resolverse desde ltms_installed_pages.'
		);
		$this->assertStringContainsString(
			"home_url( '/registro-vendedor/' )",
			$src,
			'REG-CHOICE-007: fallback del wizard = /registro-vendedor/ (slug real).'
		);
		$this->assertStringContainsString(
			"esc_html_e( 'Crea tu cuenta de vendedor gratis', 'ltms' )",
			$src,
			'REG-CHOICE-007: el CTA del banner debe rotularse "Crea tu cuenta de vendedor gratis".'
		);

		// CSS en el design system PV (plaza-viva.css) + .min sincronizado.
		$pv_css = file_get_contents( $this->pv_css_path );
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-account \.pv-account__seller-net\{[^}]*background:radial-gradient\(120% 100% at 0% 0%,var\(--brand\) 0%,var\(--brand-600\) 100%\);[^}]*border-top:3px solid var\(--ltms-gold\);/s',
			$pv_css,
			'REG-CHOICE-007: el banner debe usar el patrón de marca (gradiente rojo + línea dorada).'
		);
		$this->assertMatchesRegularExpression(
			'/\.pv-scope\.pv-account \.pv-account__seller-net-cta\{[^}]*background:var\(--ltms-gold\);color:#000;/s',
			$pv_css,
			'REG-CHOICE-007: el CTA del banner debe ser dorado con texto negro (AA 11.8:1).'
		);
		$pv_min = file_get_contents( $this->pv_css_min_path );
		$this->assertStringContainsString(
			'pv-account__seller-net',
			$pv_min,
			'REG-CHOICE-007: el .min.css de plaza-viva debe contener el banner (regenerado).'
		);
	}
}
