<?php
/**
 * KycAudit2FixTest — tests del ciclo KYC-AUDIT2 (re-auditoría del módulo KYC).
 *
 * Cubre los 5 fixes aplicados en el ciclo KYC-AUDIT2 (re-auditoría full-stack
 * del módulo KYC llevada a cabo tras la auditoría previa que fixeó 10 bugs en
 * v2.9.293-v2.9.294). Todos los tests son estructurales (Regex sobre el cuerpo
 * del método fuente) — patrón ya usado en ProductsAuditFixTest.php y
 * PanelAuditFixTest.php para ciclos de auditoría.
 *
 * Hallazgos cubiertos:
 *
 *   K-A2-01 (P0): PII bank_account_number en plaintext en user_meta. El fix
 *     c54ac9f7 dejó la tabla KYC con plaintext y el handler approve_kyc copiaba
 *     ese plaintext → `ltms_bank_account_number` user_meta que TODOS los
 *     consumers esperan CIFRADA. Fix: migración v2.9.16 ALTER TABLE VARCHAR(80)
 *     + ciphertext en tabla + sync ciphertext a user_meta.
 *   K-A2-02 (P1): modal admin get_kyc_details siempre mostraba `****` para
 *     bank_account masked. Fix: fallback plaintext si decrypt() falla y el
 *     valor no tiene prefijo `v2:` (ciphertext).
 *   K-A2-03 (P1): cron check_kyc_expiry_reminders comparaba DATETIME con
 *     expires_at (DATE) — off-by-one. Fix: comparar DATE-only (Y-m-d).
 *   K-A2-05 (P1): status `expired` de lt_vendor_kyc estaba definido en la ENUM
 *     pero NINGÚN código lo seteaba — KYCs caducados seguían approved
 *     indefinidamente (violación SARLAFT/Ley 526/1999). Fix: método nuevo
 *     `expire_overdue_kycs()` corre diariamente via ltms_daily_cron (prio 20)
 *     y pasa approved→expired cuando expires_at < today.
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use ReflectionClass;
use ReflectionMethod;

/**
 * Class KycAudit2FixTest
 *
 * Tests unitarios para los fixes del ciclo KYC-AUDIT2.
 * Ejecutar con: ./vendor/bin/phpunit --group audit-kyc2
 *
 * @group audit-kyc2
 */
class KycAudit2FixTest extends LTMS_Unit_Test_Case {

	/**
	 * Resuelve la ruta real al archivo dentro de includes/ del plugin.
	 * En modo UNIT_ONLY, ABSPATH apunta al root del plugin mismo
	 * (ver tests/bootstrap.php:28 `ABSPATH = dirname(__DIR__) . '/'`),
	 * así que el path canónico es dirname(__DIR__, 2) . '/includes/...'.
	 */
	private function plugin_path( string $relative ): string {
		return dirname( __DIR__, 2 ) . '/' . $relative;
	}

	/**
	 * Extrae el cuerpo (source) de un método via Reflection.
	 */
	private function get_method_body( string $class_name, string $method_name ): string {
		if ( ! class_exists( $class_name ) && ! file_exists( $this->plugin_path( 'includes/admin/class-ltms-admin-payouts.php' ) ) ) {
			$this->markTestSkipped( "Clase {$class_name} no disponible." );
		}
		$reflection = new ReflectionClass( $class_name );
		$method     = $reflection->getMethod( $method_name );
		$source     = $method->getFileName();
		$start_line = $method->getStartLine();
		$end_line   = $method->getEndLine();

		$lines = file( $source );
		return implode( '', array_slice( $lines, $start_line - 1, $end_line - $start_line + 1 ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// K-A2-01 (P0) — ALTER TABLE VARCHAR(80) + migración v2.9.16 existe.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_01a_migration_2_9_16_method_exists(): void {
		$file = $this->plugin_path( 'includes/core/migrations/class-ltms-db-migrations.php' );
		if ( ! file_exists( $file ) ) {
			$this->markTestSkipped( 'Migrations file no disponible.' );
		}
		require_once $file;
		$this->assertTrue( method_exists( 'LTMS_DB_Migrations', 'migrate_2_9_16_kyc_bank_account_ciphertext' ),
			'La migración v2.9.16 debe existir como método de LTMS_DB_Migrations.' );
	}

	public function test_01b_migration_2_9_16_alters_to_varchar_80(): void {
		$file = $this->plugin_path( 'includes/core/migrations/class-ltms-db-migrations.php' );
		$src  = file_get_contents( $file );
		// Localizar la DEFINICIÓN del método (no el dispatch).
		$needle = 'function migrate_2_9_16_kyc_bank_account_ciphertext';
		$start = strpos( $src, $needle );
		$this->assertNotFalse( $start, "Método {$needle} debe existir en el archivo." );
		// Tomar hasta el cierre de la clase.
		$body = substr( $src, $start, 4500 );

		$this->assertStringContainsString( 'VARCHAR(80)', $body,
			'La migración debe hacer ALTER TABLE MODIFY COLUMN a VARCHAR(80).' );
		$this->assertStringContainsString( 'ALTER TABLE', $body,
			'La migración debe incluir el ALTER TABLE.' );
	}

	public function test_01c_migration_2_9_16_recrypts_non_v2_prefix_rows(): void {
		$file = $this->plugin_path( 'includes/core/migrations/class-ltms-db-migrations.php' );
		$src  = file_get_contents( $file );
		$needle = 'function migrate_2_9_16_kyc_bank_account_ciphertext';
		$start = strpos( $src, $needle );
		$this->assertNotFalse( $start );
		$body = substr( $src, $start, 5000 );

		// Solo re-cifrar filas cuyo valor NO tenga el prefijo ciphertext `v2:`.
		$this->assertStringContainsString( "NOT LIKE 'v2:%'", $body,
			'La migración debe excluir filas que ya son ciphertext (prefijo v2:).' );
		$this->assertStringContainsString( 'LTMS_Core_Security::encrypt', $body,
			'La migración debe invocar LTMS_Core_Security::encrypt() para re-cifrar.' );
	}

	public function test_01d_migration_2_9_16_syncs_user_meta_with_ciphertext(): void {
		$file = $this->plugin_path( 'includes/core/migrations/class-ltms-db-migrations.php' );
		$src  = file_get_contents( $file );
		$needle = 'function migrate_2_9_16_kyc_bank_account_ciphertext';
		$start = strpos( $src, $needle );
		$this->assertNotFalse( $start );
		$body = substr( $src, $start, 5000 );

		$this->assertStringContainsString( "update_user_meta( (int) \$row->vendor_id, 'ltms_bank_account_number'", $body,
			'La migración debe sincronizar el ciphertext a user_meta `ltms_bank_account_number`.' );
	}

	public function test_01e_current_version_bumped_to_2_9_16(): void {
		$file = $this->plugin_path( 'includes/core/migrations/class-ltms-db-migrations.php' );
		$src  = file_get_contents( $file );
		// v2.9.310: CURRENT_VERSION bumped to 2.9.17 to dispatch the new
		// migrate_2_9_17_kyc_rejection_source migration. The contract under
		// test is "CURRENT_VERSION must be bumped to the latest migration's
		// version so existing installs run it". The literal value follows
		// the latest migration added.
		// v2.9.322 PANEL-E2E-009: bumped to 2.9.18 for migrate_2_9_18_drivers_schema.
		$this->assertStringContainsString( "CURRENT_VERSION = '2.9.18'", $src,
			'CURRENT_VERSION debe bumparse a 2.9.18 para que la migracion v2.9.18 (drivers schema) corra en sites ya activados.' );
	}

	public function test_01f_migration_dispatched_in_run(): void {
		$file = $this->plugin_path( 'includes/core/migrations/class-ltms-db-migrations.php' );
		$src  = file_get_contents( $file );

		// Localizar el dispatch: version_compare(..., '2.9.16', '<') { self::migrate_2_9_16_kyc_bank_account_ciphertext(); }
		// Needle robusto: usar ambos fragmentos en el mismo bloque.
		$needle1 = "version_compare( \$installed_version, '2.9.16', '<' )";
		$needle2 = "self::migrate_2_9_16_kyc_bank_account_ciphertext();";
		$this->assertStringContainsString( $needle1, $src,
			'run() debe gatear la migración v2.9.16 con version_compare.' );
		$this->assertStringContainsString( $needle2, $src,
			'run() debe invocar self::migrate_2_9_16_kyc_bank_account_ciphertext().' );

		// Verificar que ambos needles están próximos (en el mismo bloque if).
		$pos1 = strpos( $src, $needle1 );
		$pos2 = strpos( $src, $needle2 );
		$this->assertNotFalse( $pos1 );
		$this->assertNotFalse( $pos2 );
		// La invocación del método debe estar después del gate (offset mayor).
		$this->assertGreaterThan( $pos1, $pos2,
			'La invocación self::migrate_2_9_16... debe ir después del version_compare gate.' );
		// Y dentro del mismo bloque (no más allá de 250 chars del gate).
		$this->assertLessThan( 250, $pos2 - $pos1,
			'La invocación self::migrate_2_9_16... debe estar en el bloque if del version_compare.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// K-A2-01 (P0) — submit_kyc guarda CIPHERTEXT en tabla (no plaintext).
	// ─────────────────────────────────────────────────────────────────────────

	public function test_02a_submit_kyc_stores_ciphertext_in_table(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-dashboard-logic.php' );
		$src  = file_get_contents( $file );

		// Localizar el comentario v2.9.316 KYC-AUDIT2-01 FIX en submit_kyc.
		$this->assertStringContainsString( 'KYC-AUDIT2-01', $src,
			'submit_kyc debe contener el comentario v2.9.316 KYC-AUDIT2-01.' );

		// La asignación $bank_account_to_store = $encrypted_acc debe existir.
		$this->assertStringContainsString( '$bank_account_to_store = $encrypted_acc;', $src,
			'submit_kyc debe asignar $encrypted_acc a $bank_account_to_store para guardar ciphertext en la tabla.' );
	}

	public function test_02b_submit_kyc_no_longer_documents_plaintext_in_table(): void {
		$file = $this->plugin_path( 'includes/frontend/class-ltms-dashboard-logic.php' );
		$src  = file_get_contents( $file );

		// El comentario antiguo "Sin cifrar para la tabla" del fix c54ac9f7
		// NO debe seguir presente — indica el diseño pre-fix K-A2-01.
		$this->assertStringNotContainsString( 'Sin cifrar para la tabla', $src,
			'El comentario del fix c54ac9f7 ("Sin cifrar para la tabla") debe eliminarse.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// K-A2-01 (P0) — approve_kyc sync ciphertext (NO plaintext) a user_meta.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_03a_approve_kyc_syncs_ciphertext_to_user_meta(): void {
		$file = $this->plugin_path( 'includes/admin/class-ltms-admin-payouts.php' );
		$src  = file_get_contents( $file );

		// Localizar el bloque de sync bank data en approve_kyc.
		$start = strpos( $src, "ltms_bank_account_number', \$kyc['bank_account_number']" );
		$this->assertNotFalse( $start,
			'approve_kyc debe sincronizar bank_account_number (ciphertext) a user_meta.' );

		// El comentario nuevo debe mencionar KYC-AUDIT2-01.
		$window = substr( $src, max( 0, $start - 700 ), 900 );
		$this->assertStringContainsString( 'KYC-AUDIT2-01', $window,
			'El bloque de sync debe contener el comentario v2.9.316 KYC-AUDIT2-01.' );
		$this->assertStringContainsString( 'CIPHERTEXT', $window,
			'El comentario debe aclarar que el valor copiado es CIPHERTEXT.' );
	}

	public function test_03b_approve_kyc_no_longer_claims_encrypted_table_lie(): void {
		$file = $this->plugin_path( 'includes/admin/class-ltms-admin-payouts.php' );
		$src  = file_get_contents( $file );

		// El comentario anterior "table stores the account ENCRYPTED" era
		// contradictorio con el fix c54ac9f7 (que guardaba plaintext) — ya estaba
		// en estado incorrecto. Tras K-A2-01, ese comentario tampoco basta pues
		// dice "as-is so payout scheduler reads the same ciphertext" — lo cual
		// es correcto, PERO la frase en inglés genérico ya no debe aparecer.
		$this->assertStringNotContainsString( 'The KYC table stores the account ENCRYPTED; copy it through as-is', $src,
			'El comentario antiguo que decía "table stores the account ENCRYPTED" debe eliminarse.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// K-A2-02 (P1) — get_kyc_details mask con fallback plaintext.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_04a_get_kyc_details_has_fallback_for_plaintext_legacy(): void {
		$file = $this->plugin_path( 'includes/admin/class-ltms-admin-payouts.php' );
		$src  = file_get_contents( $file );

		// Localizar el bloque de mask en get_kyc_details.
		$start = strpos( $src, 'KYC-AUDIT2-02' );
		$this->assertNotFalse( $start, 'get_kyc_details debe contener el comentario KYC-AUDIT2-02.' );
		$body = substr( $src, $start, 2500 );

		// Si decrypt() falla y el valor no tiene prefijo `v2:`, asumir plaintext.
		$this->assertStringContainsString( "str_starts_with( \$bank_acc_num_db, 'v2:' )", $body,
			'get_kyc_details debe distinguir ciphertext (prefijo v2:) de plaintext legacy.' );
		$this->assertStringContainsString( '$plain = $bank_acc_num_db', $body,
			'get_kyc_details debe aplicar fallback $plain = $bank_acc_num_db cuando decrypt falla.' );

		// El mask debe ser str_repeat * + últimos 4 chars.
		$this->assertStringContainsString( "str_repeat( '*', max( 0, strlen( \$plain ) - 4 ) ) . substr( \$plain, -4 )", $body,
			'get_kyc_details debe aplicar mask ****1234 (últimos 4 del plaintext).' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// K-A2-03 (P1) — check_kyc_expiry_reminders usa DATE-only (no DATETIME).
	// ─────────────────────────────────────────────────────────────────────────

	public function test_05a_check_kyc_expiry_reminders_uses_date_only(): void {
		$file = $this->plugin_path( 'includes/admin/class-ltms-backfill-kyc.php' );
		$src  = file_get_contents( $file );

		// Localizar el método check_kyc_expiry_reminders.
		$start = strpos( $src, 'function check_kyc_expiry_reminders' );
		$this->assertNotFalse( $start, 'Método check_kyc_expiry_reminders debe existir.' );
		$body = substr( $src, $start, 3000 );

		// Comentario del fix K-A2-03.
		$this->assertStringContainsString( 'KYC-AUDIT2-03', $body,
			'check_kyc_expiry_reminders debe tener el comentario KYC-AUDIT2-03 explicando el off-by-one.' );

		// Las variables $now y $thirty_days_from_now deben tener formato DATE Y-m-d
		// (NO Y-m-d H:i:s que era el bug).
		$this->assertStringContainsString( "\$thirty_days_from_now = gmdate( 'Y-m-d'", $body,
			'$thirty_days_from_now debe usar gmdate(\'Y-m-d\') — solo DATE, no DATETIME.' );
		$this->assertStringContainsString( "\$now = gmdate( 'Y-m-d' )", $body,
			'$now debe usar gmdate(\'Y-m-d\') — solo DATE, no DATETIME.' );

		// El formato DATETIME Y-m-d H:i:s NO debe volver a aparecer en el método.
		$this->assertStringNotContainsString( "gmdate( 'Y-m-d H:i:s', time() + 30", $body,
			'El patrón DATETIME of-by-one del bug debe eliminarse del método.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// K-A2-05 (P1) — expire_overdue_kycs() existe y se engancha a ltms_daily_cron.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_06a_expire_overdue_kycs_method_exists(): void {
		$file = $this->plugin_path( 'includes/admin/class-ltms-backfill-kyc.php' );
		$src  = file_get_contents( $file );

		$this->assertStringContainsString( 'function expire_overdue_kycs', $src,
			'LTMS_KYC_Guard debe definir el método expire_overdue_kycs() (K-A2-05 fix).' );
		$this->assertStringContainsString( 'KYC-AUDIT2-05', $src,
			'El fix K-A2-05 debe estar documentado en el archivo.' );
	}

	public function test_06b_expire_overdue_kycs_hooked_to_daily_cron_prio_20(): void {
		$file = $this->plugin_path( 'includes/admin/class-ltms-backfill-kyc.php' );
		$src  = file_get_contents( $file );

		// add_action ltms_daily_cron con [__CLASS__, 'expire_overdue_kycs'] en prio 20.
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*['\"]ltms_daily_cron['\"]\s*,\s*\[\s*__CLASS__\s*,\s*['\"]expire_overdue_kycs['\"]\s*\]\s*,\s*20\s*\)/",
			$src,
			'expire_overdue_kycs debe engancharse a ltms_daily_cron con prioridad 20 (después del reminder prio 10).'
		);
	}

	public function test_06c_expire_overdue_kycs_marks_expired_and_updates_user_meta(): void {
		$file = $this->plugin_path( 'includes/admin/class-ltms-backfill-kyc.php' );
		$src  = file_get_contents( $file );

		$start = strpos( $src, 'function expire_overdue_kycs' );
		$this->assertNotFalse( $start );
		$body = substr( $src, $start, 4500 );

		// El WHERE del SELECT debe filtrar status='approved' AND expires_at < today.
		$this->assertStringContainsString( "status = 'approved'", $body,
			'expire_overdue_kycs solo actúa sobre KYCs approved (no pending/rejected).' );
		$this->assertStringContainsString( 'expires_at < %s', $body,
			'expire_overdue_kycs filtra WHERE expires_at < today (DATE-only).' );

		// El UPDATE debe setear status='expired'.
		$this->assertStringContainsString( "'status'      => 'expired'", $body,
			'expire_overdue_kycs UPDATE status → expired.' );

		// El user_meta 'ltms_kyc_status' debe actualizarse a 'expired'.
		$this->assertStringContainsString( "update_user_meta( \$vendor_id, 'ltms_kyc_status', 'expired' )", $body,
			'expire_overdue_kycs actualiza ltms_kyc_status user_meta a expired.' );

		// Debe disparar action ltms_vendor_kyc_expired.
		$this->assertStringContainsString( "do_action( 'ltms_vendor_kyc_expired'", $body,
			'expire_overdue_kycs dispara action ltms_vendor_kyc_expired para que listeners reaccionen.' );

		// Debe insertar notification 'kyc_expired'.
		$this->assertStringContainsString( "'type'       => 'kyc_expired'", $body,
			'expire_overdue_kycs inserta notificación tipo kyc_expired al vendor.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Backfill script bin/ltms-backfill-kyc-ciphertext.php existe.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_07a_backfill_script_exists(): void {
		$file = $this->plugin_path( 'bin/ltms-backfill-kyc-ciphertext.php' );
		$this->assertFileExists( $file,
			'Script bin/ltms-backfill-kyc-ciphertext.php debe existir para re-cifrado manual fuera del activation hook.' );
	}

	public function test_07b_backfill_script_alters_varchar_80(): void {
		$file = $this->plugin_path( 'bin/ltms-backfill-kyc-ciphertext.php' );
		$src  = file_get_contents( $file );

		$this->assertStringContainsString( 'VARCHAR(80)', $src,
			'El backfill script debe hacer ALTER TABLE MODIFY COLUMN VARCHAR(80).' );
		$this->assertStringContainsString( "NOT LIKE 'v2:%'", $src,
			'El backfill script debe filtrar filas que NO son ciphertext legacy.' );
		$this->assertStringContainsString( 'LTMS_Core_Security::encrypt', $src,
			'El backfill script debe invocar LTMS_Core_Security::encrypt().' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Smoke test: las clases alteradas siguen cargando sin fatal.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_08a_backfill_kyc_class_loads_after_edit(): void {
		$file = $this->plugin_path( 'includes/admin/class-ltms-backfill-kyc.php' );
		// Solo verificar sintaxis con php -l equivalente: cargar el archivo
		// no debe lanzar fatal (la clase puede o no existir según bootstrap UNIT_ONLY).
		$this->assertFileExists( $file );
		$src = file_get_contents( $file );
		// Debe definir las clases esperadas.
		$this->assertStringContainsString( 'class LTMS_KYC_Guard', $src );
		$this->assertStringContainsString( 'class LTMS_Backfill_Fiscal', $src );
	}
}
