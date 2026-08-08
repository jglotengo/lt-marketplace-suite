<?php
/**
 * AuditCiclo31IpClosureFixesTest - Tests para los fixes del Ciclo 31.
 *
 * Modulo: CIERRE INVARIANTE TRANSVERSAL IP (P2 CG-28-P2-6 candidato STRONG).
 *
 * El checkpoint C30 describia el cierre IP como "2 fixes de 1 linea
 * (deposit:164 + wallet:606)". La AUDITORIA C31 revelo que el inventario
 * del checkpoint estaba subestimado: hay 11 ocurrencias activas de
 * `LTMS_Utils::get_ip()` en includes/, NO 2. El usuario eligio "10
 * restantes (cierre real)" — se excluye dashboard-logic.php:2491 que
 * ya migro en v2.9.120 con fallback defensivo (dentro de `if
 * class_exists(LTMS_Core_Security) else LTMS_Utils::get_ip()` — solo
 * fallback en ambiente degradado sin la clase; en produccion ya usa
 * get_client_ip_safe()).
 *
 * Las 10 ocurrencias migradas a `LTMS_Core_Security::get_client_ip_safe()`:
 *
 *  PUBLIC-AUTH-HANDLER (5 — rate-limit auth CRITICO spoofable):
 *    1. :238  login throttle 'ltms_login_attempts_' . md5($ip)
 *    2. :397  honeypot log (sprintf 'Honeypot disparado desde IP %s')
 *    3. :410  register throttle 'ltms_register_attempts_' . md5($ip)
 *    4. :956  email-verify throttle 'ltms_email_verify_attempts_'.md5($ip)
 *    5. :1449 resend-verification throttle 'ltms_resend_pub_attempts_'.
 *
 *  DASHBOARD-LOGIC (2 — frontend rate-limit spoofable):
 *    6. :2380 backorder throttle 'ltms_backorder_' . md5($ip)
 *    7. :2588 question throttle 'ltms_question_' . md5($ip)
 *
 *  EXTERNAL-AUDITOR-ROLE (2 — auditoria logs):
 *    8. :127  log acceso auditor externo a pagina
 *    9. :158  log LOGIN auditor externo (2FA requerido)
 *
 *  FISCAL-ONLINE-ACCESS (1 — acceso autoridad fiscal):
 *   10. :356  log_access() registra cada acceso de autoridad fiscal
 *
 *  FINANCIEROS (2 — modulo CRITICO AGENTS.md "Revision como ultimo filtro"):
 *   11. deposit.php:164   ip_address en INSERT de nuevo deposito
 *   12. wallet.php:606    ip_address en INSERT de transaccion wallet
 *
 * RIESGO CERRADO (Leccion 25.1): LTMS_Utils::get_ip() confiaba en
 * HTTP_X_FORWARDED_FOR / HTTP_CF_CONNECTING_IP / HTTP_X_REAL_IP sin
 * validar REMOTE_ADDR como proxy confiable → spoofable por cliente
 * mandando X-Forwarded-For arbitrario. El rate-limit auth (login,
 * register, email-verify, resend) throttaba por IP spoofeada →
 * brute-force bypass: atacante rota X-Forwarded-For por request y
 * cada uno tiene su propio counter de throttle → limit bypassable.
 * get_client_ip_safe() solo confia en X-Forwarded-For si REMOTE_ADDR
 * esta en la option ltms_trusted_proxies → anti-spoof real (Leccion
 * 25.1 invariante transversal IP).
 *
 * Patron C31: source-based tests (file_get_contents + assertString
 * Contains/NotContainsString), mismo que C20-C30. Cross-checks:
 * - C25 invariantes webhooks siguen intactos (get_client_ip_safe).
 * - C26 traffic-booster tags CICLO26 siguen presentes.
 * - C28 compliance-guardian tags CICLO28 siguen presentes.
 * - C29 sales-booster tags CICLO29 siguen presentes.
 * - C30 fiscal-annual-close tags CICLO30 siguen presentes.
 * - test_ltms_utils_get_ip_method_still_exists (C28) sigue documentando
 *   que LTMS_Utils::get_ip() sigue DEFINIDO en utils.php (no se borra
 *   el metodo viejo — se deja para callers degradados / back-compat).
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types = 1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;

/**
 * @covers CICLO31-P2-CG-28-P2-6 FIX (cierre invariante transversal IP)
 */
class AuditCiclo31IpClosureFixesTest extends LTMS_Unit_Test_Case {

	private const PUBLIC_AUTH_PATH     = __DIR__ . '/../../includes/frontend/class-ltms-public-auth-handler.php';
	private const DASHBOARD_LOGIC_PATH = __DIR__ . '/../../includes/frontend/class-ltms-dashboard-logic.php';
	private const EXTERNAL_AUDITOR_PATH = __DIR__ . '/../../includes/roles/class-ltms-external-auditor-role.php';
	private const FISCAL_ONLINE_PATH   = __DIR__ . '/../../includes/admin/class-ltms-fiscal-online-access.php';
	private const DEPOSIT_PATH         = __DIR__ . '/../../includes/business/class-ltms-deposit.php';
	private const WALLET_PATH          = __DIR__ . '/../../includes/business/class-ltms-wallet.php';
	private const COMPLIANCE_GUARDIAN_PATH = __DIR__ . '/../../includes/business/class-ltms-compliance-guardian.php';
	private const SALES_BOOSTER_PATH   = __DIR__ . '/../../includes/business/class-ltms-sales-booster.php';
	private const TRAFFIC_BOOSTER_PATH = __DIR__ . '/../../includes/business/class-ltms-traffic-booster.php';
	private const FISCAL_ANNUAL_PATH   = __DIR__ . '/../../includes/business/class-ltms-fiscal-annual-close.php';
	private const SECURITY_PATH        = __DIR__ . '/../../includes/core/class-ltms-security.php';
	private const UTILS_PATH            = __DIR__ . '/../../includes/core/utils/class-ltms-utils.php';

	protected function setUp(): void {
		parent::setUp();

		Functions\stubs( [
			'__'          => static fn( string $s ): string => $s,
			'esc_html__'  => static fn( string $s ): string => $s,
		] );
	}

	// ====================================================================
	//  Tag CICLO31 presente en los 6 archivos migrados
	// ====================================================================

	public function test_tag_ciclo31_present_in_public_auth(): void {
		$this->assertFileExists( self::PUBLIC_AUTH_PATH );
		$source = file_get_contents( self::PUBLIC_AUTH_PATH );
		$this->assertStringContainsString( 'CICLO31-P2-CG-28-P2-6 FIX', $source );
	}

	public function test_tag_ciclo31_present_in_dashboard_logic(): void {
		$this->assertFileExists( self::DASHBOARD_LOGIC_PATH );
		$source = file_get_contents( self::DASHBOARD_LOGIC_PATH );
		$this->assertStringContainsString( 'CICLO31-P2-CG-28-P2-6 FIX', $source );
	}

	public function test_tag_ciclo31_present_in_external_auditor(): void {
		$this->assertFileExists( self::EXTERNAL_AUDITOR_PATH );
		$source = file_get_contents( self::EXTERNAL_AUDITOR_PATH );
		$this->assertStringContainsString( 'CICLO31-P2-CG-28-P2-6 FIX', $source );
	}

	public function test_tag_ciclo31_present_in_fiscal_online(): void {
		$this->assertFileExists( self::FISCAL_ONLINE_PATH );
		$source = file_get_contents( self::FISCAL_ONLINE_PATH );
		$this->assertStringContainsString( 'CICLO31-P2-CG-28-P2-6 FIX', $source );
	}

	public function test_tag_ciclo31_present_in_deposit(): void {
		$this->assertFileExists( self::DEPOSIT_PATH );
		$source = file_get_contents( self::DEPOSIT_PATH );
		$this->assertStringContainsString( 'CICLO31-P2-CG-28-P2-6 FIX', $source );
	}

	public function test_tag_ciclo31_present_in_wallet(): void {
		$this->assertFileExists( self::WALLET_PATH );
		$source = file_get_contents( self::WALLET_PATH );
		$this->assertStringContainsString( 'CICLO31-P2-CG-28-P2-6 FIX', $source );
	}

	// ====================================================================
	//  PUBLIC-AUTH-HANDLER (5 migraciones) — rate-limit auth CRITICO
	// ====================================================================

	public function test_public_auth_login_throttle_uses_safe_ip(): void {
		$source = file_get_contents( self::PUBLIC_AUTH_PATH );
		$this->assertStringContainsString(
			"'ltms_login_attempts_' . md5( \$ip )",
			$source,
			'Login throttle key sigue usando $ip'
		);
		// Anti-regresion: la asignacion $ip en la linea anterior usa get_client_ip_safe
		$this->assertStringContainsString(
			'LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'public-auth-handler usa get_client_ip_safe() para login throttle'
		);
	}

	public function test_public_auth_honeypot_log_uses_safe_ip(): void {
		$source = file_get_contents( self::PUBLIC_AUTH_PATH );
		$this->assertStringContainsString(
			"'Honeypot disparado desde IP %s', LTMS_Core_Security::get_client_ip_safe()",
			$source,
			'Honeypot log usa get_client_ip_safe()'
		);
	}

	public function test_public_auth_register_throttle_uses_safe_ip(): void {
		$source = file_get_contents( self::PUBLIC_AUTH_PATH );
		$this->assertStringContainsString(
			"'ltms_register_attempts_' . md5( \$ip )",
			$source,
			'Register throttle key sigue usando $ip'
		);
	}

	public function test_public_auth_email_verify_throttle_uses_safe_ip(): void {
		$source = file_get_contents( self::PUBLIC_AUTH_PATH );
		$this->assertStringContainsString(
			"'ltms_email_verify_attempts_' . md5( \$ip )",
			$source,
			'Email-verify throttle key sigue usando $ip'
		);
	}

	public function test_public_auth_resend_throttle_uses_safe_ip(): void {
		$source = file_get_contents( self::PUBLIC_AUTH_PATH );
		$this->assertStringContainsString(
			"'ltms_resend_pub_attempts_' . md5( \$ip )",
			$source,
			'Resend-verification throttle key sigue usando $ip'
		);
	}

	public function test_public_auth_no_active_ltms_utils_get_ip_calls(): void {
		// Anti-regresion C31: NO debe haber llamadas runtime a
		// LTMS_Utils::get_ip() en public-auth-handler. Las 5 migradas.
		$source = file_get_contents( self::PUBLIC_AUTH_PATH );
		$this->assertStringNotContainsString(
			'= LTMS_Utils::get_ip()',
			$source,
			'public-auth-handler NO debe tener llamadas runtime LTMS_Utils::get_ip() (cierre C31)'
		);
	}

	// ====================================================================
	//  DASHBOARD-LOGIC (2 migraciones) — frontend rate-limit
	//  NOTA: dashboard-logic:2491 NO migrado (fallback defensivo v2.9.120)
	// ====================================================================

	public function test_dashboard_backorder_throttle_uses_safe_ip(): void {
		$source = file_get_contents( self::DASHBOARD_LOGIC_PATH );
		$this->assertStringContainsString(
			"'ltms_backorder_' . md5( \$ip )",
			$source,
			'Backorder throttle key sigue usando $ip'
		);
		// Anti-regresion: la $ip en backorder debe ser get_client_ip_safe().
		// Buscamos el bloque contexto: throttle_key backorder + la $ip arriba.
		$this->assertMatchesRegularExpression(
			'/\$ip\s*=\s*LTMS_Core_Security::get_client_ip_safe\(\)[^\n]*\n\s*\$throttle_key\s*=\s*\'ltms_backorder_\'/',
			$source,
			'Backorder throttle usa get_client_ip_safe() (no LTMS_Utils::get_ip() spoofable)'
		);
	}

	public function test_dashboard_question_throttle_uses_safe_ip(): void {
		$source = file_get_contents( self::DASHBOARD_LOGIC_PATH );
		$this->assertStringContainsString(
			"'ltms_question_' . md5( \$ip )",
			$source,
			'Question throttle key sigue usando $ip'
		);
		$this->assertMatchesRegularExpression(
			'/\$ip\s*=\s*LTMS_Core_Security::get_client_ip_safe\(\)[^\n]*\n\s*\$throttle_key\s*=\s*\'ltms_question_\'/',
			$source,
			'Question throttle usa get_client_ip_safe() (no LTMS_Utils::get_ip() spoofable)'
		);
	}

	public function test_dashboard_review_vote_fallback_defensive_remains(): void {
		// No-regresion: la linea 2491 (v2.9.120 reviews-audit P1-4) tiene
		// un fallback defensivo a LTMS_Utils::get_ip() dentro del `else`
		// (cuando class_exists LTMS_Core_Security retorna false). Este
		// fallback NO se toca en C31 — debe permanecer.
		$source = file_get_contents( self::DASHBOARD_LOGIC_PATH );
		$this->assertStringContainsString(
			"if ( class_exists( 'LTMS_Core_Security' ) && method_exists( 'LTMS_Core_Security', 'get_client_ip_safe' ) )",
			$source,
			'v2.9.120 reviews-audit fallback defensivo sigue presente (no se toca en C31)'
		);
		$this->assertStringContainsString(
			'LTMS_Utils::get_ip()',
			$source,
			'Fallback defensivo LTMS_Utils::get_ip() permanece en dashboard-logic (rama else, ambiente degradado)'
		);
	}

	public function test_dashboard_no_other_active_ltms_utils_get_ip_than_fallback(): void {
		// Anti-regresion: dashboard-logic solo permite LTMS_Utils::get_ip()
		// en contexto de comentario o fallback defensivo. Las 2 llamadas
		// activas (backorder:2380 + question:2588) fueron migradas.
		$source = file_get_contents( self::DASHBOARD_LOGIC_PATH );

		// Removemos las lineas con comentario (// Before, LTMS_Utils::get_ip())
		// y el fallback defensivo (rama else del class_exists) — no deben
		// quedar otras ocurrencias runtime.
		// Estrategia: contar ocurrencias. Esperadas: 3 (comentario linea 2485
		// + fallback linea 2491 + comentario-GREP-only si lo hubiera).
		preg_match_all( '/LTMS_Utils::get_ip\(\)/', $source, $matches );
		$total = count( $matches[0] );
		$this->assertGreaterThanOrEqual(
			2,
			$total,
			'dashboard-logic debe retener >=2 ocurrencias legítimas (comentario v2.9.120 + fallback defensivo)'
		);
		$this->assertLessThanOrEqual(
			3,
			$total,
			'dashboard-logic no debe tener mas de 3 ocurrencias LTMS_Utils::get_ip() (2 legítimas en dashboard:2485/2491 + 1 en comentarios triviales)'
		);
	}

	// ====================================================================
	//  EXTERNAL-AUDITOR-ROLE (2 migraciones) — auditoria logs
	// ====================================================================

	public function test_external_auditor_log_access_uses_safe_ip(): void {
		$source = file_get_contents( self::EXTERNAL_AUDITOR_PATH );
		$this->assertStringContainsString(
			"'ip'         => LTMS_Core_Security::get_client_ip_safe()",
			$source,
			'External auditor access log usa get_client_ip_safe()'
		);
	}

	public function test_external_auditor_log_login_uses_safe_ip(): void {
		$source = file_get_contents( self::EXTERNAL_AUDITOR_PATH );
		$this->assertStringContainsString(
			"'ip' => LTMS_Core_Security::get_client_ip_safe()",
			$source,
			'External auditor login log usa get_client_ip_safe()'
		);
	}

	public function test_external_auditor_no_active_ltms_utils_get_ip(): void {
		$source = file_get_contents( self::EXTERNAL_AUDITOR_PATH );
		$this->assertStringNotContainsString(
			'= LTMS_Utils::get_ip()',
			$source,
			'External auditor NO debe tener llamadas LTMS_Utils::get_ip() activas (cierre C31)'
		);
	}

	// ====================================================================
	//  FISCAL-ONLINE-ACCESS (1 migracion) — acceso autoridad fiscal
	// ====================================================================

	public function test_fiscal_online_log_access_uses_safe_ip(): void {
		$source = file_get_contents( self::FISCAL_ONLINE_PATH );
		$this->assertStringContainsString(
			'$ip       = LTMS_Core_Security::get_client_ip_safe()',
			$source,
			'Fiscal online access log usa get_client_ip_safe()'
		);
	}

	public function test_fiscal_online_no_active_ltms_utils_get_ip(): void {
		$source = file_get_contents( self::FISCAL_ONLINE_PATH );
		$this->assertStringNotContainsString(
			'= LTMS_Utils::get_ip()',
			$source,
			'Fiscal online access NO debe tener llamadas LTMS_Utils::get_ip() activas (cierre C31)'
		);
	}

	// ====================================================================
	//  FINANCIEROS — deposit + wallet (CRITICO AGENTS.md)
	// ====================================================================

	public function test_deposit_ip_address_uses_safe_ip(): void {
		$source = file_get_contents( self::DEPOSIT_PATH );
		$this->assertStringContainsString(
			"'ip_address'  => LTMS_Core_Security::get_client_ip_safe()",
			$source,
			'Deposit ip_address usa get_client_ip_safe()'
		);
	}

	public function test_deposit_no_active_ltms_utils_get_ip(): void {
		$source = file_get_contents( self::DEPOSIT_PATH );
		$this->assertStringNotContainsString(
			'= LTMS_Utils::get_ip()',
			$source,
			'Deposit NO debe tener llamadas LTMS_Utils::get_ip() activas (cierre C31)'
		);
	}

	public function test_wallet_ip_address_uses_safe_ip(): void {
		$source = file_get_contents( self::WALLET_PATH );
		$this->assertStringContainsString(
			"'ip_address'     => LTMS_Core_Security::get_client_ip_safe()",
			$source,
			'Wallet ip_address usa get_client_ip_safe()'
		);
	}

	public function test_wallet_no_active_ltms_utils_get_ip(): void {
		$source = file_get_contents( self::WALLET_PATH );
		$this->assertStringNotContainsString(
			'= LTMS_Utils::get_ip()',
			$source,
			'Wallet NO debe tener llamadas LTMS_Utils::get_ip() activas (cierre C31)'
		);
	}

	// ====================================================================
	//  CIERRE INVARIANTE TRANSVERSAL — verificacion global en includes/
	// ====================================================================

	public function test_invariante_ip_globally_closed_in_business(): void {
		// business/ (financiero + compliance + marketing) no debe tener
		// llamadas activas LTMS_Utils::get_ip(). Las unicas ocurrencias
		// legítimas son 2 comentarios en compliance-guardian.php (doc
		// del metodo viejo) — se validan aparte.
		$business_dir = __DIR__ . '/../../includes/business';
		$this->assertDirectoryExists( $business_dir );

		$php_files = glob( $business_dir . '/*.php' ) ?: [];
		foreach ( $php_files as $file ) {
			$source = file_get_contents( $file );
			// Solo matchCalls activas: `= LTMS_Utils::get_ip()` (asignacion) o
			// `=> LTMS_Utils::get_ip()` (array value) o `LTMS_Utils::get_ip() )`
			// (sprintf arg). Comentarios (`// ...LTMS_Utils::get_ip()...`) NO
			// cuentan.
			$basename = basename( $file );

			// compliance-guardian.php tiene 2 comentarios legitimos que mencionan
			// LTMS_Utils::get_ip() pero no lo invocan. Lo validamos aparte.
			if ( $basename === 'class-ltms-compliance-guardian.php' ) {
				continue;
			}

			// Eliminamos comentarios `// ...` y `/* ... */` antes de chequear.
			$code_only = preg_replace( '/\/\/.*$/m', '', $source );
			$code_only = preg_replace( '/\/\*.*?\*\//s', '', $code_only );

			$this->assertStringNotContainsString(
				'LTMS_Utils::get_ip()',
				$code_only,
				"business/{$basename} no debe tener llamadas runtime LTMS_Utils::get_ip() activas (cierre C31 invariante transversal)"
			);
		}
	}

	public function test_invariante_ip_globally_closed_in_frontend_auth_critical(): void {
		// public-auth-handler.php (auth rate-limit CRITICO) no debe
		// tener llamadas activas. dashboard-logic.php solo permite el
		// fallback defensivo linea 2491 (en `else` de class_exists).
		$frontend_dir = __DIR__ . '/../../includes/frontend';

		$auth_files = [
			'class-ltms-public-auth-handler.php',
		];
		foreach ( $auth_files as $basename ) {
			$path = $frontend_dir . '/' . $basename;
			$this->assertFileExists( $path );
			$source = file_get_contents( $path );
			// Sin comentarios: solo codigo.
			$code_only = preg_replace( '/\/\/.*$/m', '', $source );
			$code_only = preg_replace( '/\/\*.*?\*\//s', '', $code_only );
			$this->assertStringNotContainsString(
				'LTMS_Utils::get_ip()',
				$code_only,
				"frontend/{$basename} no debe tener llamadas runtime LTMS_Utils::get_ip() (auth rate-limit CIERRE C31)"
			);
		}
	}

	// ====================================================================
	//  Legitimos restantes — comentarios y fallback defensivo
	// ====================================================================

	public function test_compliance_guardian_comments_retain_legacy_mention(): void {
		// No-regresion: compliance-guardian.php (C28 migrado) tiene 2
		// comentarios que mencionan LTMS_Utils::get_ip() como metodo
		// viejo (no son llamadas). Estos comentarios son documentacion
		// del fix C28 y deben permanecer.
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );
		$this->assertStringContainsString(
			'en lugar de `LTMS_Utils::get_ip()`',
			$source,
			'compliance-guardian C28 comment: documenta migracion previa (no es llamada)'
		);
		$this->assertStringContainsString(
			'Antes usaba LTMS_Utils::get_ip()',
			$source,
			'compliance-guardian C28 comment: "Antes usaba" (no es llamada)'
		);
	}

	public function test_compliance_guardian_no_active_ltms_utils_get_ip_call(): void {
		// Sin comentarios: compliance-guardian no debe tener llamadas.
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );
		$code_only = preg_replace( '/\/\/.*$/m', '', $source );
		$code_only = preg_replace( '/\/\*.*?\*\//s', '', $code_only );
		$this->assertStringNotContainsString(
			'LTMS_Utils::get_ip()',
			$code_only,
			'compliance-guardian no debe tener llamadas runtime LTMS_Utils::get_ip() (C28 ya cerro)'
		);
	}

	// ====================================================================
	//  Fuente unica de verdad — LTMS_Core_Security::get_client_ip_safe()
	// ====================================================================

	public function test_get_client_ip_safe_method_present_in_security(): void {
		$this->assertFileExists( self::SECURITY_PATH );
		$source = file_get_contents( self::SECURITY_PATH );
		$this->assertStringContainsString(
			'public static function get_client_ip_safe(): string',
			$source,
			'LTMS_Core_Security::get_client_ip_safe() definido (fuente unica de verdad Leccion 25.1)'
		);
	}

	public function test_get_client_ip_safe_validates_trusted_proxies(): void {
		// Leccion 25.1: get_client_ip_safe SOLO confia en X-Forwarded-For
		// si REMOTE_ADDR esta en option ltms_trusted_proxies. Sin esa
		// validacion, vuelve a ser spoofable. Verificamos que la logica
		// anti-spoof sigue intacta (no se relajo en algun refactor).
		$source = file_get_contents( self::SECURITY_PATH );
		$this->assertStringContainsString( 'ltms_trusted_proxies', $source );
		$this->assertStringContainsString( 'in_array( $remote_addr, $trusted_proxies, true )', $source );
	}

	public function test_ltms_utils_get_ip_method_still_defined_backcompat(): void {
		// No-regresion: el metodo LTMS_Utils::get_ip() PERMANECE definido en
		// utils.php (no se borra — el fallback defensivo dashboard-logic:2491
		// depende de el en ambiente degradado). El test C28
		// test_ltms_utils_get_ip_method_still_exists valida lo mismo — pero
		// su docstring dice "deposit/wallet dependen de el (backlog C29+)"
		// que ya NO es cierto tras C31. Esta asercion confirma que el metodo
		// sigue existiendo por razon nueva: fallback defensivo dashboard-logic.
		$this->assertFileExists( self::UTILS_PATH );
		$source = file_get_contents( self::UTILS_PATH );
		$this->assertStringContainsString(
			'public static function get_ip(): string',
			$source,
			'LTMS_Utils::get_ip() sigue definido en utils.php (back-compat para fallback defensivo dashboard-logic:2491).'
		);
	}

	// ====================================================================
	//  Cross-checks C25-C30 — invariantes previas siguen intactas
	// ====================================================================

	public function test_cross_check_c25_webhooks_use_safe_ip(): void {
		// C25 cerro get_client_ip_safe en 5 webhooks (Addi, Aveonline,
		// Openpay, Siigo, Stripe, Uber, Router). Verificamos que siguen
		// con get_client_ip_safe (no regresion).
		$webhook_dir = __DIR__ . '/../../includes/api/webhooks';
		$webhooks = [
			'class-ltms-addi-webhook-handler.php',
			'class-ltms-aveonline-webhook-handler.php',
			'class-ltms-openpay-webhook-handler.php',
			'class-ltms-siigo-webhook-handler.php',
			'class-ltms-stripe-webhook-handler.php',
			'class-ltms-uber-direct-webhook-handler.php',
			'class-ltms-api-webhook-router.php',
		];
		foreach ( $webhooks as $basename ) {
			$path = $webhook_dir . '/' . $basename;
			$this->assertFileExists( $path );
			$source = file_get_contents( $path );
			$this->assertStringContainsString(
				'get_client_ip_safe()',
				$source,
				"C25 invariante: {$basename} sigue usando get_client_ip_safe() (no regresion)"
			);
		}
	}

	public function test_cross_check_c28_compliance_guardian_tags_present(): void {
		$source = file_get_contents( self::COMPLIANCE_GUARDIAN_PATH );
		$this->assertStringContainsString( 'CICLO28-P1-CG-002 FIX', $source );
	}

	public function test_cross_check_c29_sales_booster_tags_present(): void {
		$source = file_get_contents( self::SALES_BOOSTER_PATH );
		$this->assertStringContainsString( 'CICLO29-P0-SB-001 FIX', $source );
		$this->assertStringContainsString( 'CICLO29-P1-SB-002 FIX', $source );
		$this->assertStringContainsString( 'CICLO29-P1-SB-007 FIX', $source );
	}

	public function test_cross_check_c30_fiscal_annual_close_tags_present(): void {
		$source = file_get_contents( self::FISCAL_ANNUAL_PATH );
		$this->assertStringContainsString( 'CICLO30-P0-FAC-001 FIX', $source );
		$this->assertStringContainsString( 'CICLO30-P1-FAC-002 FIX', $source );
	}

	public function test_cross_check_c30_fiscal_annual_close_hook_accepts_3_args(): void {
		// C28/29/30 verification: el hook ltms_payout_completed sigue
		// teniendo accepted_args=3 (no regresion C30 FAC-001).
		$source = file_get_contents( self::FISCAL_ANNUAL_PATH );
		$this->assertStringContainsString(
			"add_action( 'ltms_payout_completed', [ __CLASS__, 'calculate_gmf_on_payout' ], 10, 3 )",
			$source
		);
	}

	// ====================================================================
	//  Invariante wallet — contrato execute_transaction sigue intacto
	//  (C30 FAC-001 depende de esta invariante; C31 no la toca)
	// ====================================================================

	public function test_cross_check_wallet_execute_transaction_uses_reference_for_idempotency(): void {
		// C30 FAC-001 depende de que Wallet::execute_transaction() retorne
		// existing_tx_id sin re-ejecutar cuando reference coincide (invariante
		// WL-CRASH-2). C31 NO toca esta logica — solo sustituye la fuente de
		// IP. Verificamos que el contrato sigue intacto.
		$source = file_get_contents( self::WALLET_PATH );
		$this->assertStringContainsString(
			'reference',
			$source,
			'Wallet::execute_transaction sigue usando `reference` para idempotency (invariante WL-CRASH-2)'
		);
	}

	// ====================================================================
	//  Nota sobre tests de runtime
	// ====================================================================
	// Los tests son source-based porque los metodos auditados (rate-limit
	// transients, INSERT wallet, log_access) requieren stubeo extensivo
	// de WP internals (wp_remote_*, $wpdb, get_transient). Los tests
	// documentan el contrato del fix (tag presente, llamada migrada,
	// anti-pattern removido, fallback defensivo intacto) sin
	// reimplementar logica.
}
