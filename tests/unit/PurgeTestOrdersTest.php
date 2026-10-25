<?php
/**
 * PurgeTestOrdersTest — PURGE-TEST-ORDERS-2026-10-24.
 *
 * El operador ordenó eliminar TODOS los pedidos del marketplace ("todos
 * son pedidos de prueba"). La operación es destructiva en producción, por
 * lo que viaja con protocolo completo:
 *
 *   1. Inventario previo (38 pedidos 2024-2026, todos del equipo, CERO
 *      cargos reales de pasarela; derivados 100% QA).
 *   2. BACKUP obligatorio de la BD (wp db export | gzip, ~87MB) antes de
 *      tocar nada — documentado como pre-requisito del script.
 *   3. Script AUTOCONTENIDO (WC ya no trae la herramienta
 *      delete_transactions en esta versión): borra pedidos + items +
 *      notas + meta + HPOS huérfano + analítica de WC + comisiones +
 *      billetera + saldos + total_sales.
 *
 * Se conservan (NO son pedidos): notificaciones (kyc/posgold), KYC de
 * vendedores, carritos abandonados, logs de auditoría.
 *
 * @package LTMS\Tests\Unit
 * @group purge-test-orders
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

/**
 * Class PurgeTestOrdersTest
 *
 * @group purge-test-orders
 */
final class PurgeTestOrdersTest extends LTMS_Unit_Test_Case {

	private string $script_path;
	private string $webhook_path;

	protected function setUp(): void {
		parent::setUp();
		$this->script_path = dirname( __DIR__, 2 ) . '/deploy/ltms-purge-test-orders-2026-10-24.php';
		$this->webhook_path = dirname( __DIR__, 2 ) . '/deploy/ltms-deploy-webhook.php';
	}

	/**
	 * PURGE-A: el script exige el backup previo y es autocontenido (la
	 * herramienta delete_transactions de WC ya no existe en esta versión).
	 */
	public function test_001_requiere_backup_y_autocontenido(): void {
		$this->assertFileExists( $this->script_path );
		$src = (string) file_get_contents( $this->script_path );

		// Backup documentado como pre-requisito obligatorio.
		$this->assertStringContainsString( 'wp db export', $src, 'PURGE-A: el script debe documentar el backup obligatorio de la BD antes de borrar.' );
		$this->assertStringContainsString( 'VERIFICAR que el .gz existe', $src, 'PURGE-A: el script debe exigir verificar el backup antes de continuar.' );
		// Autocontenido: borra los pedidos él mismo (posts + items + notas + meta).
		$this->assertStringContainsString( "post_type IN ('shop_order','shop_order_refund','shop_order_placehold')", $src, 'PURGE-A: recoge TODOS los tipos de post de pedido.' );
		$this->assertStringContainsString( 'order_note', $src, 'PURGE-A: borra las notas de pedido (comments).' );
		$this->assertStringContainsString( 'woocommerce_order_itemmeta', $src, 'PURGE-A: borra los metas de los order items.' );
	}

	/**
	 * PURGE-B: alcance exacto de la limpieza — WC analytics, HPOS huérfano,
	 * comisiones, billetera, saldos, total_sales — y nada más.
	 */
	public function test_002_alcance_de_limpieza(): void {
		$src = (string) file_get_contents( $this->script_path );

		foreach ( array(
			'wc_order_stats'            => 'estadísticas de analítica de WC',
			'wc_order_product_lookup'    => 'lookup de productos por pedido',
			'wc_orders'                  => 'HPOS huérfano',
			'lt_commissions'             => 'comisiones de pedidos',
			'lt_wallet_transactions'      => 'movimientos de billetera',
			'lt_wallet_journal'          => 'journal de billetera',
			'lt_wallet_holds'            => 'holds de billetera',
			"meta_key='total_sales'"     => 'ventas acumuladas de productos',
		) as $needle => $label ) {
			$this->assertStringContainsString( $needle, $src, "PURGE-B: el script limpia {$label}." );
		}
		// Saldos a 0 (conserva las filas de wallet — no rompe referencias).
		$this->assertStringContainsString( 'SET balance=0, balance_pending=0, balance_reserved=0, total_earned=0, total_withdrawn=0', $src, 'PURGE-B: resetea los saldos de las wallets a 0 conservando las filas.' );
		// Lo que NO se toca (no son pedidos).
		$this->assertStringNotContainsString( 'lt_notifications', $src, 'PURGE-B: las notificaciones NO se tocan (no son de pedidos — kyc/posgold).' );
		$this->assertStringNotContainsString( 'lt_vendor_kyc', $src, 'PURGE-B: el KYC de vendedores NO se toca.' );
	}

	/**
	 * PURGE-C: idempotente + reporte verificable post-purga.
	 */
	public function test_003_idempotente_y_reporte(): void {
		$src = (string) file_get_contents( $this->script_path );

		$this->assertStringContainsString( 'Idempotente', $src, 'PURGE-C: el script declara ser idempotente (re-run reporta ceros).' );
		$this->assertStringContainsString( 'Verificación post-purga', $src, 'PURGE-C: reporta el estado final verificable (conteos esperados en 0).' );
		$this->assertStringContainsString( 'PURGE-TEST-ORDERS-2026-10-24', $src, 'PURGE-C: tag de trazabilidad.' );
	}

	/**
	 * PURGE-D: whitelist del deploy webhook (lección #177).
	 */
	public function test_004_whitelist_webhook(): void {
		$webhook = (string) file_get_contents( $this->webhook_path );

		$this->assertStringContainsString( "'deploy/ltms-purge-test-orders-2026-10-24.php',", $webhook, 'PURGE-D: el script debe estar en la whitelist del webhook.' );
		$this->assertStringContainsString( "'tests/unit/PurgeTestOrdersTest.php',", $webhook, 'PURGE-D: este test debe estar en la whitelist del webhook (lección #177).' );
	}
}
