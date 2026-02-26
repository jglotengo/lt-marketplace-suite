<?php
/**
 * LTMS Core Utils - Utilidades Generales
 *
 * Funciones helper de uso transversal en todo el plugin:
 * formato de moneda, fechas, strings, IPs, etc.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/core/utils
 * @version    1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Utils
 */
final class LTMS_Utils {

    /**
     * Formatea un monto monetario según el país de operación.
     *
     * @param float  $amount       Monto a formatear.
     * @param string $currency     Código ISO 4217 (COP, MXN, USD).
     * @param bool   $show_symbol  Mostrar símbolo de moneda.
     * @return string Monto formateado.
     */
    public static function format_money( float $amount, string $currency = '', bool $show_symbol = true ): string {
        if ( empty( $currency ) ) {
            $currency = LTMS_Core_Config::get_currency();
        }

        $formats = [
            'COP' => [ 'symbol' => '$', 'decimals' => 0,  'thousands' => '.', 'decimal' => ',' ],
            'MXN' => [ 'symbol' => '$', 'decimals' => 2,  'thousands' => ',', 'decimal' => '.' ],
            'USD' => [ 'symbol' => '$', 'decimals' => 2,  'thousands' => ',', 'decimal' => '.' ],
        ];

        $fmt      = $formats[ $currency ] ?? $formats['USD'];
        $number   = number_format( $amount, $fmt['decimals'], $fmt['decimal'], $fmt['thousands'] );
        $prefix   = $show_symbol ? $fmt['symbol'] : '';

        return $prefix . $number . ' ' . $currency;
    }

    /**
     * Obtiene la IP real del cliente.
     *
     * @return string
     */
    public static function get_ip(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = trim( sanitize_text_field( $_SERVER[ $header ] ) );
                if ( str_contains( $ip, ',' ) ) {
                    $ip = trim( explode( ',', $ip )[0] );
                }
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Convierte un número de teléfono al formato internacional E.164.
     *
     * @param string $phone        Teléfono (puede tener o no el prefijo internacional).
     * @param string $country_code Código de país ('CO' o 'MX').
     * @return string Teléfono en formato E.164 (+573001234567).
     */
    public static function format_phone_e164( string $phone, string $country_code = '' ): string {
        if ( empty( $country_code ) ) {
            $country_code = LTMS_Core_Config::get_country();
        }

        $clean  = preg_replace( '/[^0-9]/', '', $phone );
        $prefix = match ( strtoupper( $country_code ) ) {
            'MX'    => '52',
            default => '57', // Colombia
        };

        // Si ya tiene el prefijo internacional, retornar tal cual
        if ( str_starts_with( $clean, $prefix ) && strlen( $clean ) > 10 ) {
            return '+' . $clean;
        }

        return '+' . $prefix . $clean;
    }

    /**
     * Genera un número de referencia único para pedidos/transacciones.
     *
     * @param string $prefix Prefijo (ej: 'PAY', 'COMM', 'REF').
     * @return string Referencia única (ej: PAY-20250225-A1B2C3).
     */
    public static function generate_reference( string $prefix = 'LTMS' ): string {
        $date   = gmdate( 'ymd' ); // 250225
        $random = strtoupper( substr( bin2hex( random_bytes( 3 ) ), 0, 6 ) );
        return strtoupper( $prefix ) . '-' . $date . '-' . $random;
    }

    /**
     * Trunca un texto a un máximo de caracteres, añadiendo ellipsis.
     *
     * @param string $text      Texto original.
     * @param int    $max_length Longitud máxima.
     * @param string $suffix     Sufijo (por defecto '...').
     * @return string
     */
    public static function truncate( string $text, int $max_length = 100, string $suffix = '...' ): string {
        if ( mb_strlen( $text ) <= $max_length ) {
            return $text;
        }
        return mb_substr( $text, 0, $max_length - mb_strlen( $suffix ) ) . $suffix;
    }

    /**
     * Sanitiza un nombre de archivo para almacenamiento seguro.
     *
     * @param string $filename Nombre de archivo original.
     * @return string Nombre sanitizado.
     */
    public static function sanitize_filename( string $filename ): string {
        // Eliminar caracteres peligrosos
        $clean = preg_replace( '/[^a-zA-Z0-9\-_\.]/', '_', $filename );
        // Evitar path traversal
        $clean = str_replace( [ '..', '/', '\\' ], '_', $clean );
        // Limitar longitud
        return substr( $clean, 0, 255 );
    }

    /**
     * Verifica si una cadena es un JSON válido.
     *
     * @param string $string Cadena a verificar.
     * @return bool
     */
    public static function is_json( string $string ): bool {
        json_decode( $string );
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Convierte un monto de centavos a unidades decimales (para Openpay).
     *
     * @param int $cents Monto en centavos.
     * @return float Monto en unidades.
     */
    public static function cents_to_decimal( int $cents ): float {
        return round( $cents / 100, 2 );
    }

    /**
     * Convierte un monto decimal a centavos (para algunas APIs).
     *
     * @param float $amount Monto en unidades.
     * @return int Monto en centavos (entero).
     */
    public static function decimal_to_cents( float $amount ): int {
        return (int) round( $amount * 100 );
    }

    /**
     * Verifica si el usuario actual es un vendedor de LTMS.
     *
     * @param int|null $user_id ID del usuario (null = usuario actual).
     * @return bool
     */
    public static function is_ltms_vendor( ?int $user_id = null ): bool {
        if ( $user_id === null ) {
            $user_id = get_current_user_id();
        }
        if ( ! $user_id ) {
            return false;
        }
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return false;
        }
        return in_array( 'ltms_vendor', (array) $user->roles, true ) ||
               in_array( 'ltms_vendor_premium', (array) $user->roles, true );
    }

    /**
     * Obtiene la fecha/hora actual en UTC formateada para MySQL.
     *
     * @return string Fecha en formato 'Y-m-d H:i:s'.
     */
    public static function now_utc(): string {
        return gmdate( 'Y-m-d H:i:s' );
    }

    /**
     * Calcula la diferencia en días entre dos fechas.
     *
     * @param string $date1 Fecha inicio (Y-m-d).
     * @param string $date2 Fecha fin (Y-m-d). Default = hoy.
     * @return int Diferencia en días.
     */
    public static function days_between( string $date1, string $date2 = '' ): int {
        if ( empty( $date2 ) ) {
            $date2 = gmdate( 'Y-m-d' );
        }
        $d1   = new \DateTime( $date1 );
        $d2   = new \DateTime( $date2 );
        $diff = $d1->diff( $d2 );
        return (int) $diff->days;
    }

    /**
     * Convierte un array a un formato de tabla HTML básico (para emails).
     *
     * @param array  $data    Array asociativo key => value.
     * @param string $caption Título de la tabla (opcional).
     * @return string HTML de la tabla.
     */
    public static function array_to_html_table( array $data, string $caption = '' ): string {
        if ( empty( $data ) ) {
            return '';
        }

        $html = '<table style="border-collapse:collapse;width:100%;">';
        if ( $caption ) {
            $html .= '<caption>' . esc_html( $caption ) . '</caption>';
        }
        foreach ( $data as $label => $value ) {
            $html .= '<tr>';
            $html .= '<th style="text-align:left;padding:6px 12px;border:1px solid #ddd;">' . esc_html( $label ) . '</th>';
            $html .= '<td style="padding:6px 12px;border:1px solid #ddd;">' . esc_html( (string) $value ) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';

        return $html;
    }
}
