<?php
/**
 * KYCComplianceTest — QA Ronda 5: módulo KYC / Compliance
 *
 * Cubre:
 *  K-01: ltms_payout_kyc_required → ltms_kyc_required_for_payout (key mismatch)
 *  K-02: LTMS_Legal_Compliance::VAULT_OP_* constantes existen
 *  K-03: log_vault_access() firma correcta (user_id, accessor_id, document, action, context)
 *  K-04: ltms_kyc_max_file_size_mb se sanitiza como float, no string
 *
 * @package LTMS\Tests\Unit
 */

class KYCComplianceTest extends \LTMS\Tests\Unit\LTMS_Unit_Test_Case {

    // ── K-02: VAULT_OP_* constants exist ─────────────────────────────────────

    /** @test */
    public function test_vault_op_upload_constant_exists(): void {
        $this->assertTrue(
            defined( 'LTMS_Legal_Compliance::VAULT_OP_UPLOAD' ),
            'LTMS_Legal_Compliance::VAULT_OP_UPLOAD debe estar definida'
        );
    }

    /** @test */
    public function test_vault_op_upload_is_string_upload(): void {
        $this->assertSame( 'upload', LTMS_Legal_Compliance::VAULT_OP_UPLOAD );
    }

    /** @test */
    public function test_vault_op_view_constant_exists(): void {
        $this->assertSame( 'view', LTMS_Legal_Compliance::VAULT_OP_VIEW );
    }

    /** @test */
    public function test_vault_op_download_constant_exists(): void {
        $this->assertSame( 'download', LTMS_Legal_Compliance::VAULT_OP_DOWNLOAD );
    }

    /** @test */
    public function test_vault_op_delete_constant_exists(): void {
        $this->assertSame( 'delete', LTMS_Legal_Compliance::VAULT_OP_DELETE );
    }

    /** @test */
    public function test_vault_op_share_constant_exists(): void {
        $this->assertSame( 'share', LTMS_Legal_Compliance::VAULT_OP_SHARE );
    }

    // ── K-03: log_vault_access() tiene 5 parámetros, 2° es int ──────────────

    /** @test */
    public function test_log_vault_access_signature_has_5_params(): void {
        $ref    = new ReflectionMethod( LTMS_Legal_Compliance::class, 'log_vault_access' );
        $params = $ref->getParameters();
        $this->assertCount( 5, $params, 'log_vault_access() debe tener 5 parámetros' );
    }

    /** @test */
    public function test_log_vault_access_second_param_is_int(): void {
        $ref    = new ReflectionMethod( LTMS_Legal_Compliance::class, 'log_vault_access' );
        $params = $ref->getParameters();
        $type   = $params[1]->getType();
        $this->assertNotNull( $type );
        $this->assertSame( 'int', $type->getName(), '2° parámetro (accessor_id) debe ser int' );
    }

    /** @test */
    public function test_log_vault_access_third_param_is_document_string(): void {
        $ref    = new ReflectionMethod( LTMS_Legal_Compliance::class, 'log_vault_access' );
        $params = $ref->getParameters();
        $this->assertSame( 'document', $params[2]->getName(), '3° parámetro debe llamarse document' );
    }

    // ── K-01: ltms_kyc_required_for_payout es la clave correcta ─────────────

    /** @test */
    public function test_payout_scheduler_reads_kyc_required_for_payout_key(): void {
        // Verificar que el código del scheduler usa la clave correcta (no ltms_payout_kyc_required)
        $file = file_get_contents(
            dirname( __FILE__, 3 ) . '/includes/business/class-ltms-payout-scheduler.php'
        );
        $this->assertStringContainsString(
            'ltms_kyc_required_for_payout',
            $file,
            'PayoutScheduler debe leer ltms_kyc_required_for_payout'
        );
        $this->assertStringNotContainsString(
            'ltms_payout_kyc_required',
            $file,
            'PayoutScheduler NO debe leer ltms_payout_kyc_required (clave incorrecta)'
        );
    }

    /** @test */
    public function test_section_kyc_view_uses_correct_payout_key(): void {
        $file = file_get_contents(
            dirname( __FILE__, 3 ) . '/includes/admin/views/settings/section-kyc.php'
        );
        $this->assertStringContainsString(
            'ltms_kyc_required_for_payout',
            $file,
            'section-kyc.php debe guardar ltms_kyc_required_for_payout'
        );
        $this->assertStringNotContainsString(
            "'ltms_payout_kyc_required'",
            $file,
            'section-kyc.php NO debe usar la clave incorrecta ltms_payout_kyc_required'
        );
    }

    // ── K-04: ltms_kyc_max_file_size_mb se sanitiza como float ───────────────

    /** @test */
    public function test_kyc_max_file_size_mb_sanitized_as_float(): void {
        $settings = new LTMS_Admin_Settings();
        $result   = $settings->sanitize_settings( [ 'ltms_kyc_max_file_size_mb' => '10' ] );
        $this->assertIsFloat( $result['ltms_kyc_max_file_size_mb'], 'Debe ser float, no string' );
        $this->assertEqualsWithDelta( 10.0, $result['ltms_kyc_max_file_size_mb'], 0.001 );
    }

    /** @test */
    public function test_kyc_max_file_size_mb_negative_clamps_to_minimum(): void {
        $settings = new LTMS_Admin_Settings();
        $result   = $settings->sanitize_settings( [ 'ltms_kyc_max_file_size_mb' => '-5' ] );
        $this->assertGreaterThan( 0.0, $result['ltms_kyc_max_file_size_mb'], 'Valor negativo debe clampear a mínimo positivo' );
    }

    /** @test */
    public function test_kyc_max_file_size_mb_decimal_preserved(): void {
        $settings = new LTMS_Admin_Settings();
        $result   = $settings->sanitize_settings( [ 'ltms_kyc_max_file_size_mb' => '2.5' ] );
        $this->assertEqualsWithDelta( 2.5, $result['ltms_kyc_max_file_size_mb'], 0.001 );
    }

    /** @test */
    public function test_kyc_max_file_size_mb_not_divided_by_100(): void {
        // No tiene _rate ni _percent — no debe dividirse entre 100
        $settings = new LTMS_Admin_Settings();
        $result   = $settings->sanitize_settings( [ 'ltms_kyc_max_file_size_mb' => '5' ] );
        $this->assertGreaterThan( 1.0, $result['ltms_kyc_max_file_size_mb'], 'No debe dividirse entre 100' );
    }
}
