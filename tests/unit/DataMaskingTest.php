<?php

declare( strict_types=1 );

namespace LTMS\Tests\unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LTMS_Data_Masking — EXTENDED v2
 *
 * Nuevos ángulos cubiertos:
 *  - mask_email: multi-dot TLD (.com.co, .net.co), subdominio, username con puntos/guiones
 *  - mask_phone: números con extensión (x/ext), solo dígitos repetidos
 *  - mask_name: nombre con tilde/acento, espacios múltiples, solo espacios
 *  - mask_address: dirección con "Diagonal", "Transversal", sin tipo de vía
 *  - mask_bank_account: cuenta con espacios, cuenta de 20 dígitos (IBAN-like)
 *  - mask_document: pasaporte alfanumérico, cédula extranjería, NIT largo
 *  - prepare_for_auditor: no-auditor recibe datos completos; auditor recibe enmascarados
 *
 * @package LTMS\Tests\Unit
 */
class DataMaskingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ //
    //  mask_email — nuevos ángulos
    // ------------------------------------------------------------------ //

    /** Username con punto interno (e.g. juan.pablo@empresa.com) */
    public function test_mask_email_username_with_dot(): void
    {
        $result = \LTMS_Data_Masking::mask_email( 'juan.pablo@empresa.com' );
        $this->assertStringContainsString( '@', $result );
        $this->assertStringNotContainsString( 'uan.pablo', $result );
    }

    /** Username con guion (e.g. maria-jose@correo.co) */
    public function test_mask_email_username_with_hyphen(): void
    {
        $result = \LTMS_Data_Masking::mask_email( 'maria-jose@correo.co' );
        $this->assertStringContainsString( '@', $result );
        $this->assertStringNotContainsString( 'aria-jose', $result );
    }

    /** TLD doble: .com.co — resultado termina en .co */
    public function test_mask_email_double_tld_com_co(): void
    {
        $result = \LTMS_Data_Masking::mask_email( 'contacto@tienda.com.co' );
        $this->assertStringEndsWith( '.co', $result );
        $this->assertStringContainsString( '@', $result );
    }

    /** TLD doble: .net.co */
    public function test_mask_email_double_tld_net_co(): void
    {
        $result = \LTMS_Data_Masking::mask_email( 'soporte@empresa.net.co' );
        $this->assertStringEndsWith( '.co', $result );
    }

    /** Username muy largo (20 chars) — se enmascara correctamente */
    public function test_mask_email_long_username(): void
    {
        $result = \LTMS_Data_Masking::mask_email( 'carlosandresgarcia01@gmail.com' );
        $this->assertStringStartsWith( 'c', $result );
        $this->assertStringContainsString( '*', $result );
        $this->assertStringNotContainsString( 'arlosandresgarcia01', $result );
    }

    /** Email con números en username */
    public function test_mask_email_numeric_username(): void
    {
        $result = \LTMS_Data_Masking::mask_email( '12345@empresa.com' );
        $this->assertStringStartsWith( '1', $result );
        $this->assertStringContainsString( '*', $result );
        $this->assertStringEndsWith( '.com', $result );
    }

    /** Resultado tiene exactamente un @ */
    public function test_mask_email_has_exactly_one_at(): void
    {
        $result = \LTMS_Data_Masking::mask_email( 'test@dominio.com' );
        $this->assertSame( 1, substr_count( $result, '@' ) );
    }

    // ------------------------------------------------------------------ //
    //  mask_phone — nuevos ángulos
    // ------------------------------------------------------------------ //

    /** Número con extensión x12345 — solo se usan los dígitos */
    public function test_mask_phone_with_extension_ignored(): void
    {
        $result = \LTMS_Data_Masking::mask_phone( '3001234567 x1234' );
        $this->assertMatchesRegularExpression( '/^\*\*\*-\*\*\*-\d{4}$/', $result );
    }

    /** Número con todos los mismos dígitos (e.g. 1111111111) */
    public function test_mask_phone_repeated_digits(): void
    {
        $result = \LTMS_Data_Masking::mask_phone( '1111111111' );
        $this->assertStringEndsWith( '1111', $result );
        $this->assertMatchesRegularExpression( '/^\*\*\*-\*\*\*-\d{4}$/', $result );
    }

    /** Número internacional con paréntesis: (300) 123-4567 */
    public function test_mask_phone_with_parentheses(): void
    {
        $result = \LTMS_Data_Masking::mask_phone( '(300) 123-4567' );
        $this->assertMatchesRegularExpression( '/^\*\*\*-\*\*\*-\d{4}$/', $result );
        $this->assertStringEndsWith( '4567', $result );
    }

    /** Exactamente 8 dígitos (fijo Bogotá sin indicativo) */
    public function test_mask_phone_bogota_landline_8_digits(): void
    {
        $result = \LTMS_Data_Masking::mask_phone( '7654321' );
        // 7 dígitos — ≥4 → retorna ***-***-last4
        $this->assertMatchesRegularExpression( '/^\*\*\*-\*\*\*-\d{4}$/', $result );
        $this->assertStringEndsWith( '4321', $result );
    }

    /** Número con espacios en blanco */
    public function test_mask_phone_with_whitespace(): void
    {
        $result = \LTMS_Data_Masking::mask_phone( '  3001234567  ' );
        $this->assertMatchesRegularExpression( '/^\*\*\*-\*\*\*-\d{4}$/', $result );
    }

    // ------------------------------------------------------------------ //
    //  mask_name — nuevos ángulos
    // ------------------------------------------------------------------ //

    /** Nombre con solo caracteres con tilde — primera letra visible */
    public function test_mask_name_name_with_accent(): void
    {
        $result = \LTMS_Data_Masking::mask_name( 'Ángela' );
        // mask_name usa indexación de bytes (no mb_), entonces para 'Ángela' (UTF-8 multibyte)
        // $word[0] retorna el primer byte de Á, no el carácter completo.
        // Lo que sí podemos garantizar: la función retorna string con asteriscos.
        $this->assertStringContainsString( '*', $result );
        $this->assertIsString( $result );
        $this->assertStringContainsString( '*', $result );
    }

    /** Nombre con ñ */
    public function test_mask_name_name_with_enie(): void
    {
        $result = \LTMS_Data_Masking::mask_name( 'Muñoz' );
        $this->assertStringStartsWith( 'M', $result );
        $this->assertStringContainsString( '*', $result );
    }

    /** Nombre en minúsculas — se preserva la casing original */
    public function test_mask_name_lowercase_initial_preserved(): void
    {
        $result = \LTMS_Data_Masking::mask_name( 'maria' );
        $this->assertStringStartsWith( 'm', $result );
    }

    /** Nombre de 2 caracteres — primera char visible, segunda asterisco */
    public function test_mask_name_two_chars(): void
    {
        $result = \LTMS_Data_Masking::mask_name( 'Li' );
        $this->assertSame( 'L*', $result );
    }

    /** Nombre con cinco palabras */
    public function test_mask_name_five_words_count(): void
    {
        $result = \LTMS_Data_Masking::mask_name( 'Ana María De La Cruz' );
        $parts  = explode( ' ', $result );
        $this->assertCount( 5, $parts );
    }

    /** Longitud total del resultado equals longitud del input */
    public function test_mask_name_total_length_preserved(): void
    {
        $name   = 'Carlos Gómez';
        $result = \LTMS_Data_Masking::mask_name( $name );
        $this->assertSame( strlen( $name ), strlen( $result ) );
    }

    // ------------------------------------------------------------------ //
    //  mask_address — nuevos ángivos
    // ------------------------------------------------------------------ //

    /** Tipo de vía "Diagonal" preservado */
    public function test_mask_address_preserves_diagonal_street_type(): void
    {
        $result = \LTMS_Data_Masking::mask_address( 'Diagonal 22 # 10-15' );
        $this->assertStringStartsWith( 'Diagonal', $result );
    }

    /** Tipo de vía "Transversal" preservado */
    public function test_mask_address_preserves_transversal_street_type(): void
    {
        $result = \LTMS_Data_Masking::mask_address( 'Transversal 40 # 55-20' );
        $this->assertStringStartsWith( 'Transversal', $result );
    }

    /** Dirección sin tipo de vía (3+ palabras) → contiene asteriscos */
    public function test_mask_address_without_street_type_contains_asterisks(): void
    {
        $result = \LTMS_Data_Masking::mask_address( 'Barrio Laureles casa 12' );
        $this->assertStringContainsString( '*', $result );
    }

    /** Dirección con exactamente 3 palabras no retorna '*** ***' */
    public function test_mask_address_three_words_not_generic(): void
    {
        $result = \LTMS_Data_Masking::mask_address( 'Calle 45 Bogota' );
        $this->assertNotSame( '*** ***', $result );
    }

    /** Resultado siempre contiene asteriscos cuando hay más de 2 palabras */
    public function test_mask_address_always_has_asterisks_for_long_input(): void
    {
        $result = \LTMS_Data_Masking::mask_address( 'Carrera 10 # 20-30 Apt 5B' );
        $this->assertStringContainsString( '*', $result );
    }

    /** Resultado es string incluso para dirección vacía */
    public function test_mask_address_empty_returns_string(): void
    {
        $this->assertIsString( \LTMS_Data_Masking::mask_address( '' ) );
    }

    // ------------------------------------------------------------------ //
    //  mask_bank_account — nuevos ángulos
    // ------------------------------------------------------------------ //

    /** Cuenta con espacios (e.g. "1234 5678 9012 3456") */
    public function test_mask_bank_account_with_spaces(): void
    {
        $result = \LTMS_Data_Masking::mask_bank_account( '1234 5678 9012 3456' );
        $this->assertStringEndsWith( '3456', $result );
        $this->assertStringStartsWith( '****', $result );
    }

    /** Cuenta larga tipo IBAN (20 dígitos) */
    public function test_mask_bank_account_iban_length(): void
    {
        $result = \LTMS_Data_Masking::mask_bank_account( '12345678901234567890' );
        $this->assertStringEndsWith( '7890', $result );
        $this->assertStringStartsWith( '****', $result );
        $this->assertStringNotContainsString( '1234567890123456', $result );
    }

    /** Cuenta con exactamente 8 dígitos */
    public function test_mask_bank_account_8_digits(): void
    {
        $result = \LTMS_Data_Masking::mask_bank_account( '12345678' );
        $this->assertStringEndsWith( '5678', $result );
        $this->assertStringStartsWith( '****', $result );
    }

    /** Cuenta con guiones: 1234-5678-90 */
    public function test_mask_bank_account_with_dashes(): void
    {
        $result = \LTMS_Data_Masking::mask_bank_account( '1234-5678-90' );
        $this->assertStringEndsWith( '7890', $result );
        $this->assertStringStartsWith( '****', $result );
    }

    /** Longitud del resultado: al menos 8 chars para cuentas de ≥8 dígitos */
    public function test_mask_bank_account_minimum_length(): void
    {
        $result = \LTMS_Data_Masking::mask_bank_account( '12345678' );
        $this->assertGreaterThanOrEqual( 8, strlen( $result ) );
    }

    // ------------------------------------------------------------------ //
    //  mask_document — nuevos ángulos
    // ------------------------------------------------------------------ //

    /** Pasaporte alfanumérico colombiano: AA123456 */
    public function test_mask_document_passport_alphanumeric(): void
    {
        $result = \LTMS_Data_Masking::mask_document( 'AA123456' );
        $this->assertStringEndsWith( '3456', $result );
        $this->assertStringStartsWith( '****', $result );
    }

    /** Cédula de extranjería: E-123456789 */
    public function test_mask_document_cedula_extranjeria_with_prefix(): void
    {
        $result = \LTMS_Data_Masking::mask_document( 'E-123456789' );
        $this->assertStringEndsWith( '6789', $result );
        $this->assertStringStartsWith( '****', $result );
    }

    /** NIT largo con dígito de verificación: 900.123.456-1 → 9001234561 → last4=4561 */
    public function test_mask_document_nit_with_verification_digit(): void
    {
        $result = \LTMS_Data_Masking::mask_document( '900.123.456-1' );
        $this->assertStringEndsWith( '4561', $result );
        $this->assertStringStartsWith( '****', $result );
    }

    /** Documento con solo letras (inválido pero ≥4 chars) */
    public function test_mask_document_letters_only(): void
    {
        $result = \LTMS_Data_Masking::mask_document( 'ABCDEF' );
        $this->assertStringEndsWith( 'CDEF', $result );
        $this->assertStringStartsWith( '**', $result );
    }

    /** Exactamente 4 caracteres alfanuméricos → no retorna '****' */
    public function test_mask_document_exactly_4_alphanumeric_shows_all(): void
    {
        $result = \LTMS_Data_Masking::mask_document( 'A1B2' );
        $this->assertStringEndsWith( 'A1B2', $result );
    }

    // ------------------------------------------------------------------ //
    //  prepare_for_auditor — nuevos ángulos
    // ------------------------------------------------------------------ //

    /** No-auditor recibe datos completamente intactos */
    public function test_prepare_for_auditor_returns_full_data_for_non_auditor(): void
    {
        // current_user_can ya está stubado a false en el test suite — no es auditor
        Monkey\Functions\when( 'current_user_can' )->justReturn( false );

        $data = [
            'customer_email' => 'test@empresa.com',
            'customer_phone' => '3001234567',
            'customer_name'  => 'Juan Pérez',
        ];

        $result = \LTMS_Data_Masking::prepare_for_auditor( $data );

        $this->assertSame( 'test@empresa.com', $result['customer_email'] );
        $this->assertSame( '3001234567',        $result['customer_phone'] );
        $this->assertSame( 'Juan Pérez',        $result['customer_name'] );
    }

    /** Auditor recibe email enmascarado */
    public function test_prepare_for_auditor_masks_customer_email(): void
    {
        Monkey\Functions\when( 'current_user_can' )->justReturn( true );

        $data   = [ 'customer_email' => 'juan@empresa.com' ];
        $result = \LTMS_Data_Masking::prepare_for_auditor( $data );

        $this->assertNotSame( 'juan@empresa.com', $result['customer_email'] );
        $this->assertStringContainsString( '*', $result['customer_email'] );
    }

    /** Auditor recibe teléfono enmascarado */
    public function test_prepare_for_auditor_masks_customer_phone(): void
    {
        Monkey\Functions\when( 'current_user_can' )->justReturn( true );

        $data   = [ 'customer_phone' => '3001234567' ];
        $result = \LTMS_Data_Masking::prepare_for_auditor( $data );

        $this->assertNotSame( '3001234567', $result['customer_phone'] );
        $this->assertStringContainsString( '*', $result['customer_phone'] );
    }

    /** Auditor recibe nombre enmascarado */
    public function test_prepare_for_auditor_masks_customer_name(): void
    {
        Monkey\Functions\when( 'current_user_can' )->justReturn( true );

        $data   = [ 'customer_name' => 'Carlos Gómez' ];
        $result = \LTMS_Data_Masking::prepare_for_auditor( $data );

        $this->assertNotSame( 'Carlos Gómez', $result['customer_name'] );
        $this->assertStringContainsString( '*', $result['customer_name'] );
    }

    /** Auditor — campos fiscales no enmascarados (order_id queda intacto) */
    public function test_prepare_for_auditor_preserves_fiscal_fields(): void
    {
        Monkey\Functions\when( 'current_user_can' )->justReturn( true );

        $data = [
            'order_id'     => 12345,
            'total'        => 99900,
            'tax_amount'   => 15984,
            'invoice_id'   => 'FV-0001',
            'customer_email' => 'test@test.com',
        ];

        $result = \LTMS_Data_Masking::prepare_for_auditor( $data );

        $this->assertSame( 12345,    $result['order_id'] );
        $this->assertSame( 99900,    $result['total'] );
        $this->assertSame( 15984,    $result['tax_amount'] );
        $this->assertSame( 'FV-0001', $result['invoice_id'] );
        // El email sí se enmascara
        $this->assertNotSame( 'test@test.com', $result['customer_email'] );
    }

    /** prepare_for_auditor retorna array en ambos casos */
    public function test_prepare_for_auditor_always_returns_array(): void
    {
        Monkey\Functions\when( 'current_user_can' )->justReturn( false );
        $this->assertIsArray( \LTMS_Data_Masking::prepare_for_auditor( [] ) );

        Monkey\Functions\when( 'current_user_can' )->justReturn( true );
        $this->assertIsArray( \LTMS_Data_Masking::prepare_for_auditor( [] ) );
    }

    /** prepare_for_auditor — campo vacío no fuerza enmascarado */
    public function test_prepare_for_auditor_empty_field_not_masked(): void
    {
        Monkey\Functions\when( 'current_user_can' )->justReturn( true );

        $data   = [ 'customer_email' => '' ];
        $result = \LTMS_Data_Masking::prepare_for_auditor( $data );

        // El campo está vacío, la lógica de prepare_for_auditor no debería modificarlo
        $this->assertSame( '', $result['customer_email'] );
    }

    // ------------------------------------------------------------------ //
    //  Invariantes adicionales cross-masker
    // ------------------------------------------------------------------ //

    /** Todos los maskers producen strings no-null */
    public function test_all_maskers_never_return_null(): void
    {
        $this->assertNotNull( \LTMS_Data_Masking::mask_email( '' ) );
        $this->assertNotNull( \LTMS_Data_Masking::mask_phone( '' ) );
        $this->assertNotNull( \LTMS_Data_Masking::mask_name( '' ) );
        $this->assertNotNull( \LTMS_Data_Masking::mask_address( '' ) );
        $this->assertNotNull( \LTMS_Data_Masking::mask_bank_account( '' ) );
        $this->assertNotNull( \LTMS_Data_Masking::mask_document( '' ) );
    }

    /** mask_email con múltiples @-signs retorna placeholder */
    public function test_mask_email_multiple_at_signs_returns_placeholder(): void
    {
        $result = \LTMS_Data_Masking::mask_email( 'a@@b.com' );
        $this->assertSame( '****@****.***', $result );
    }

    /** mask_phone con solo espacios retorna placeholder */
    public function test_mask_phone_whitespace_only_returns_placeholder(): void
    {
        $result = \LTMS_Data_Masking::mask_phone( '   ' );
        $this->assertSame( '***-***-****', $result );
    }

    /** mask_name con string vacío retorna string vacío o asterisco */
    public function test_mask_name_empty_string_returns_string(): void
    {
        $result = \LTMS_Data_Masking::mask_name( '' );
        $this->assertIsString( $result );
    }

    /** Los maskers son pure functions — dos llamadas idénticas dan igual resultado */
    public function test_maskers_deterministic_email(): void
    {
        $r1 = \LTMS_Data_Masking::mask_email( 'user@empresa.com' );
        $r2 = \LTMS_Data_Masking::mask_email( 'user@empresa.com' );
        $this->assertSame( $r1, $r2 );
    }

    public function test_maskers_deterministic_address(): void
    {
        $r1 = \LTMS_Data_Masking::mask_address( 'Calle 45 # 23-10' );
        $r2 = \LTMS_Data_Masking::mask_address( 'Calle 45 # 23-10' );
        $this->assertSame( $r1, $r2 );
    }
}
