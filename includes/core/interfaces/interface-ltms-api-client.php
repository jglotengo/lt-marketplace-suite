<?php
/**
 * LTMS API Client Interface
 *
 * Contrato que deben implementar todos los clientes de API externa.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/core/interfaces
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Interface LTMS_API_Client_Interface
 */
interface LTMS_API_Client_Interface {

    /**
     * Obtiene el estado de salud de la conexión con la API.
     *
     * @return array{status: string, message: string, latency_ms?: int}
     */
    public function health_check(): array;

    /**
     * Obtiene el slug identificador del proveedor.
     *
     * @return string Ej: 'siigo', 'openpay', 'addi'
     */
    public function get_provider_slug(): string;
}
