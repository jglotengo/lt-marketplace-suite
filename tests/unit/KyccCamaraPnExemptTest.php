<?php
/**
 * KyccCamaraPnExemptTest — Tests unitarios para KYC-CAMARA-PN-EXEMPT-2026-08-03.
 *
 * Cubre el fix en LTMS_Authorities_Compliance::validate_rut_and_camara_comercio():
 * solo exigir matrícula Cámara de Comercio a persona jurídica (NIT). Persona
 * natural (CC/CE/PAS) queda exenta bajo Código de Comercio art. 10.
 *
 * Antes del fix, el backend exigía ltms_camara_comercio_number a TODOS los
 * vendors CO sin distinguir tipo de persona — bloqueaba a vendedores persona
 * natural (ej. Maria Orlinda Giraldo Gomez #208, CC) con ac_cc_missing
 * aunque la UI labelaba el campo como "solo personas jurídicas".
 *
 * @package LTMS\Tests\Unit
 */

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use ReflectionClass;

/**
 * @covers LTMS_Authorities_Compliance::validate_rut_and_camara_comercio
 */
class KyccCamaraPnExemptTest extends LTMS_Unit_Test_Case {

    /**
     * Invoca el método estático privado validate_rut_and_camara_comercio
     * con un perfil de vendor mockeado.
     */
    private function invoke( string $doc_type, string $cc_number = '', string $cc_expires = '', string $tax_id = '', string $country = 'CO' ): mixed {
        // Stubs estáticos: país + DIAN token + logs.
        Functions\stubs( [
            'get_user_meta' => static function ( $uid, $key, $single = false ) use ( $doc_type, $cc_number, $cc_expires, $tax_id ) {
                switch ( $key ) {
                    case 'ltms_document_type':         return $doc_type;
                    case 'ltms_camara_comercio_number': return $cc_number;
                    case 'ltms_camara_comercio_expires':return $cc_expires;
                    case 'ltms_tax_id':                return $tax_id;
                    default:                           return '';
                }
            },
            'update_user_meta' => static fn() => true,
            'delete_user_meta' => static fn() => true,
        ] );

        // LTMS_Core_Config::get_country() lee get_option; stub de fallback.
        Functions\when('get_option')->alias(static fn( $key, $default = null ) => $default );

        // Forzar país via reflect sobre cache estática de LTMS_Core_Config.
        \LTMS_Core_Config::flush_cache();
        \LTMS_Core_Config::set( 'ltms_country', $country );

        $rc = new ReflectionClass( \LTMS_Authorities_Compliance::class );
        $m  = $rc->getMethod( 'validate_rut_and_camara_comercio' );
        $m->setAccessible( true );
        return $m->invoke( null, true, 208 );
    }

    // ── Persona natural (CC/CE/PAS) está exenta ──────────────────────────

    public function test_persona_natural_cc_sin_camara_pasa(): void {
        // Maria Orlinda Giraldo Gomez #208, CC, sin matrícula Cámara.
        $result = $this->invoke( 'cc', '', '', '' );
        $this->assertTrue( $result, 'Persona natural CC sin matrícula Cámara debe aprobar (exenta)' );
    }

    public function test_persona_natural_ce_sin_camara_pasa(): void {
        $result = $this->invoke( 'ce', '', '', '' );
        $this->assertTrue( $result, 'Cédula de Extranjería sin matrícula debe aprobar' );
    }

    public function test_persona_natural_pasaporte_sin_camara_pasa(): void {
        $result = $this->invoke( 'passport', '', '', '' );
        $this->assertTrue( $result, 'Pasaporte sin matrícula debe aprobar' );
    }

    public function test_persona_natural_cc_con_camara_tambien_pasa(): void {
        // Si por robustez la persona natural ya tenía seteada una matrícula,
        // el validador no debe bloquearla (dato residual irrelevante).
        $result = $this->invoke( 'cc', '12345-6', '2030-01-01', '' );
        $this->assertTrue( $result );
    }

    // ── Persona jurídica (NIT) sigue obligada ───────────────────────────

    public function test_persona_juridica_nit_sin_camara_bloquea(): void {
        $result = $this->invoke( 'nit', '', '', '900123456-1' );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'ac_cc_missing', $result->get_error_code() );
    }

    public function test_persona_juridica_nit_camara_vencida_pasa_con_warning(): void {
        // MATRICULA-FLEX-2026-09-03: la matrícula vencida ya NO bloquea la
        // aprobación (pasa a warning best-effort UIAF). Decreto 2150/1995 exige
        // matrícula al comerciante, pero lo que vence es la renovación anual y
        // el certificado (90 días), no la matrícula como dato permanente.
        $result = $this->invoke( 'nit', '12345-6', '2020-01-01', '900123456-1' );
        $this->assertTrue( $result, 'NIT con matrícula vencida debe aprobar con warning (best-effort)' );
    }

    public function test_persona_juridica_nit_camara_vigente_pasa(): void {
        $future_date = gmdate( 'Y-m-d', time() + YEAR_IN_SECONDS );
        $result = $this->invoke( 'nit', '12345-6', $future_date, '900123456-1' );
        $this->assertTrue( $result, 'NIT con matrícula vigente debe aprobar' );
    }

    public function test_persona_juridica_nit_camara_sin_vencimiento_pasa(): void {
        // Vencimiento opcional: si está vacío, no bloquear por expired.
        $result = $this->invoke( 'nit', '12345-6', '', '900123456-1' );
        $this->assertTrue( $result, 'NIT con número pero sin vencimiento debe aprobar (vencimiento es opcional)' );
    }

    // ── NIT en mayúsculas/minúsculas (case-insensitive) ─────────────────

    public function test_nit_mayusculas_tambien_se_valida_como_juridica(): void {
        $result = $this->invoke( 'NIT', '', '', '900123456-1' );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'ac_cc_missing', $result->get_error_code() );
    }

    // ── MX no toca la rama CO (no regresa ac_cc_*) ───────────────────────

    public function test_mx_no_ejecuta_validacion_camara_co(): void {
        // En MX el flujo es distinto (RFC); la rama CO de Cámara no aplica.
        // El método debe retornar true sin bloquear por cc_missing o cc_expired.
        Functions\when('get_user_meta')->alias(static function( $uid, $key, $single = false ) {
            if ( $key === 'ltms_tax_id' ) return 'XAXX010101000'; // RFC válido formato
            return '';
        });
        \LTMS_Core_Config::flush_cache();
        \LTMS_Core_Config::set( 'ltms_country', 'MX' );

        $rc = new ReflectionClass( \LTMS_Authorities_Compliance::class );
        $m  = $rc->getMethod( 'validate_rut_and_camara_comercio' );
        $m->setAccessible( true );
        $result = $m->invoke( null, true, 999 );
        $this->assertTrue( $result, 'MX no debe ejecutar validación Cámara CO' );
    }
}
