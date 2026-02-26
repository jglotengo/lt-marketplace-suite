<?php
/**
 * LTMS LoggerAware Trait
 *
 * Provee acceso conveniente al logger forense para todas las
 * clases del plugin que necesiten registrar eventos.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/core/traits
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Trait LTMS_Logger_Aware
 */
trait LTMS_Logger_Aware {

    /**
     * Registra un evento de log usando la clase del trait como fuente.
     *
     * @param string $event_code Código del evento.
     * @param string $message    Mensaje descriptivo.
     * @param array  $context    Contexto adicional.
     * @param string $level      Nivel de severidad.
     * @return void
     */
    protected function log(
        string $event_code,
        string $message,
        array  $context = [],
        string $level   = 'INFO'
    ): void {
        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::log( $event_code, $message, $context, $level );
        }
    }

    /**
     * @param string $event
     * @param string $msg
     * @param array  $ctx
     */
    protected function log_debug( string $event, string $msg, array $ctx = [] ): void {
        $this->log( $event, $msg, $ctx, 'DEBUG' );
    }

    /**
     * @param string $event
     * @param string $msg
     * @param array  $ctx
     */
    protected function log_info( string $event, string $msg, array $ctx = [] ): void {
        $this->log( $event, $msg, $ctx, 'INFO' );
    }

    /**
     * @param string $event
     * @param string $msg
     * @param array  $ctx
     */
    protected function log_warning( string $event, string $msg, array $ctx = [] ): void {
        $this->log( $event, $msg, $ctx, 'WARNING' );
    }

    /**
     * @param string $event
     * @param string $msg
     * @param array  $ctx
     */
    protected function log_error( string $event, string $msg, array $ctx = [] ): void {
        $this->log( $event, $msg, $ctx, 'ERROR' );
    }

    /**
     * @param string $event
     * @param string $msg
     * @param array  $ctx
     */
    protected function log_critical( string $event, string $msg, array $ctx = [] ): void {
        $this->log( $event, $msg, $ctx, 'CRITICAL' );
    }

    /**
     * @param string $event
     * @param string $msg
     * @param array  $ctx
     */
    protected function log_security( string $event, string $msg, array $ctx = [] ): void {
        $this->log( $event, $msg, $ctx, 'SECURITY' );
    }
}
