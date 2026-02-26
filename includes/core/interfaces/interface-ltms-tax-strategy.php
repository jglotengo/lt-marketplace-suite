<?php
/**
 * LTMS Tax Strategy Interface
 *
 * Contrato del patrón Strategy para el cálculo de impuestos
 * por país (Colombia IVA/ReteIVA/ReteFuente, México IVA/ISR/IEPS).
 *
 * @package    LTMS
 * @subpackage LTMS/includes/core/interfaces
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Interface LTMS_Tax_Strategy_Interface
 */
interface LTMS_Tax_Strategy_Interface {

    /**
     * Calcula los impuestos aplicables a una transacción.
     *
     * @param float $gross_amount  Monto bruto antes de impuestos.
     * @param array $order_data    Datos del pedido (tipo de producto, régimen fiscal vendedor).
     * @param array $vendor_data   Datos del vendedor (régimen, NIT/RFC, responsabilidad fiscal).
     * @return array{
     *     iva: float,
     *     iva_rate: float,
     *     retefuente: float,
     *     retefuente_rate: float,
     *     reteiva: float,
     *     reteiva_rate: float,
     *     ica: float,
     *     ica_rate: float,
     *     isr: float,
     *     isr_rate: float,
     *     ieps: float,
     *     ieps_rate: float,
     *     total_taxes: float,
     *     total_withholding: float,
     *     net_to_vendor: float,
     *     strategy: string
     * }
     */
    public function calculate( float $gross_amount, array $order_data, array $vendor_data ): array;

    /**
     * Obtiene el código del país que este motor maneja.
     *
     * @return string 'CO' o 'MX'
     */
    public function get_country_code(): string;

    /**
     * Determina si aplica retención de IVA para este vendedor.
     *
     * @param array $vendor_data Datos del vendedor.
     * @return bool
     */
    public function should_apply_withholding( array $vendor_data ): bool;
}
