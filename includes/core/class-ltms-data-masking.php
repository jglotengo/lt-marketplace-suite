<?php
/**
 * LTMS Core Data Masking - Privacidad para Auditoría Externa
 *
 * Implementa el "Clean Room Protocol": enmascara datos comerciales sensibles
 * cuando el usuario actual es un auditor externo (ltms_external_auditor),
 * manteniendo visibles los datos fiscales requeridos por DIAN/SAT.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/core
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Data_Masking
 */
final class LTMS_Data_Masking {

    /**
     * Verifica si el usuario actual es un auditor externo.
     *
     * @return bool
     */
    public static function is_external_auditor(): bool {
        return current_user_can( 'ltms_external_auditor' );
    }

    /**
     * Prepara un array de datos para ser mostrado al auditor externo.
     * Los datos comerciales se enmascaran; los fiscales permanecen intactos.
     *
     * @param array $data Datos originales.
     * @return array Datos preparados.
     */
    public static function prepare_for_auditor( array $data ): array {
        if ( ! self::is_external_auditor() ) {
            return $data; // Datos completos para admins normales
        }

        // Enmascarar campos de privacidad comercial
        $mask_map = [
            'customer_email'    => fn( $v ) => self::mask_email( $v ),
            'billing_email'     => fn( $v ) => self::mask_email( $v ),
            'vendor_email'      => fn( $v ) => self::mask_email( $v ),
            'customer_phone'    => fn( $v ) => self::mask_phone( $v ),
            'billing_phone'     => fn( $v ) => self::mask_phone( $v ),
            'vendor_phone'      => fn( $v ) => self::mask_phone( $v ),
            'customer_name'     => fn( $v ) => self::mask_name( $v ),
            'billing_first_name' => fn( $v ) => self::mask_name( $v ),
            'billing_last_name'  => fn( $v ) => self::mask_name( $v ),
            'billing_address_1'  => fn( $v ) => self::mask_address( $v ),
            'billing_address_2'  => fn( $v ) => '***',
            'bank_account'      => fn( $v ) => self::mask_bank_account( $v ),
            'document_number'   => fn( $v ) => self::mask_document( $v ),
        ];

        foreach ( $mask_map as $field => $masker ) {
            if ( isset( $data[ $field ] ) && ! empty( $data[ $field ] ) ) {
                $data[ $field ] = $masker( $data[ $field ] );
            }
        }

        // MANTENER visibles (datos que el auditor fiscal NECESITA):
        // - order_id, order_number, order_date, order_status
        // - total, subtotal, tax_amount, iva_amount, retefuente
        // - vendor_id (como número, no nombre)
        // - nit_vendor, rfc_vendor (identificadores fiscales)
        // - invoice_id, cfdi_uuid (documentos fiscales)
        // - payment_method, transaction_id (trazabilidad)

        return $data;
    }

    /**
     * Enmascara un email: c*****@gmail.com
     *
     * @param string $email Email completo.
     * @return string Email enmascarado.
     */
    public static function mask_email( string $email ): string {
        if ( ! is_email( $email ) ) {
            return '****@****.***';
        }

        $parts    = explode( '@', $email );
        $username = $parts[0];
        $domain   = $parts[1];

        $visible  = strlen( $username ) > 1 ? $username[0] : '*';
        $masked   = $visible . str_repeat( '*', max( 3, strlen( $username ) - 1 ) );

        $domain_parts   = explode( '.', $domain );
        $masked_domain  = $domain_parts[0][0] . str_repeat( '*', max( 2, strlen( $domain_parts[0] ) - 1 ) );
        $tld            = end( $domain_parts );

        return $masked . '@' . $masked_domain . '.' . $tld;
    }

    /**
     * Enmascara un número de teléfono: ***-***-**42
     *
     * @param string $phone Teléfono completo.
     * @return string Teléfono enmascarado.
     */
    public static function mask_phone( string $phone ): string {
        $clean = preg_replace( '/[^0-9]/', '', $phone );
        if ( strlen( $clean ) < 4 ) {
            return '***-***-****';
        }
        $last4 = substr( $clean, -4 );
        return '***-***-' . $last4;
    }

    /**
     * Enmascara un nombre: J*** D***
     *
     * @param string $name Nombre completo.
     * @return string Nombre enmascarado.
     */
    public static function mask_name( string $name ): string {
        $words  = explode( ' ', trim( $name ) );
        $masked = [];
        foreach ( $words as $word ) {
            if ( strlen( $word ) > 1 ) {
                $masked[] = $word[0] . str_repeat( '*', strlen( $word ) - 1 );
            } else {
                $masked[] = '*';
            }
        }
        return implode( ' ', $masked );
    }

    /**
     * Enmascara una dirección: C*** #** - **
     *
     * @param string $address Dirección completa.
     * @return string Dirección enmascarada.
     */
    public static function mask_address( string $address ): string {
        $words = explode( ' ', trim( $address ) );
        if ( count( $words ) <= 2 ) {
            return '*** ***';
        }
        // Mantener el tipo de vía (Calle, Carrera, Av) pero enmascarar el número
        return $words[0] . ' ' . str_repeat( '*', strlen( $words[1] ?? '***' ) ) . ' ***';
    }

    /**
     * Enmascara un número de cuenta bancaria: ****1234
     *
     * @param string $account Número de cuenta.
     * @return string Cuenta enmascarada.
     */
    public static function mask_bank_account( string $account ): string {
        // Si es JSON (estructura completa), extraer solo el número
        if ( str_starts_with( trim( $account ), '{' ) ) {
            return '****' . substr( $account, -4 );
        }
        $clean = preg_replace( '/[^0-9]/', '', $account );
        if ( strlen( $clean ) < 4 ) {
            return '****';
        }
        return str_repeat( '*', max( 4, strlen( $clean ) - 4 ) ) . substr( $clean, -4 );
    }

    /**
     * Enmascara un número de documento (NIT/Cédula/RFC).
     * Para el auditor fiscal, se muestra parcialmente (últimos 4 dígitos).
     *
     * @param string $doc Número de documento.
     * @return string Documento parcialmente visible.
     */
    public static function mask_document( string $doc ): string {
        $clean = preg_replace( '/[^a-zA-Z0-9]/', '', $doc );
        if ( strlen( $clean ) < 4 ) {
            return '****';
        }
        $visible = substr( $clean, -4 );
        return str_repeat( '*', max( 4, strlen( $clean ) - 4 ) ) . $visible;
    }

    /**
     * Registra en el log forense que un auditor externo accedió a una sección.
     *
     * @param string $section Sección o reporte accedido.
     * @return void
     */
    public static function log_auditor_access( string $section ): void {
        if ( ! self::is_external_auditor() ) {
            return;
        }

        // Use the firewall's IP resolution so the audit log records the actual
        // client IP even when the site sits behind a trusted proxy / CDN.
        $ip = class_exists( 'LTMS_Firewall' ) && method_exists( 'LTMS_Firewall', 'get_client_ip' )
            ? LTMS_Firewall::get_client_ip()
            : sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );

        LTMS_Core_Logger::security(
            'AUDITOR_EXTERNAL_ACCESS',
            sprintf( 'Auditor externo (ID: %d) accedió a: %s', get_current_user_id(), $section ),
            [
                'user_id'  => get_current_user_id(),
                'section'  => $section,
                'ip'       => $ip,
                'time'     => current_time( 'mysql', true ),
            ]
        );
    }
}
