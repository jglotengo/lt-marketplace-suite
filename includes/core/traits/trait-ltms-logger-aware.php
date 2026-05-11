<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
trait LTMS_Logger_Aware {
    // --- Métodos de INSTANCIA ($this->log_info()) ---
    protected function log( string $event_code, string $message, array $context = [], string $level = 'INFO' ): void {
        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::log( $event_code, $message, $context, $level );
        }
    }
    protected function log_debug( string $event, string $msg, array $ctx = [] ): void { $this->log( $event, $msg, $ctx, 'DEBUG' ); }
    protected function log_info( string $event, string $msg, array $ctx = [] ): void { $this->log( $event, $msg, $ctx, 'INFO' ); }
    protected function log_warning( string $event, string $msg, array $ctx = [] ): void { $this->log( $event, $msg, $ctx, 'WARNING' ); }
    protected function log_error( string $event, string $msg, array $ctx = [] ): void { $this->log( $event, $msg, $ctx, 'ERROR' ); }
    protected function log_critical( string $event, string $msg, array $ctx = [] ): void { $this->log( $event, $msg, $ctx, 'CRITICAL' ); }
    protected function log_security( string $event, string $msg, array $ctx = [] ): void { $this->log( $event, $msg, $ctx, 'SECURITY' ); }

    // --- Métodos ESTÁTICOS (self::log_info_static()) — para clases sin instancia ---
    protected static function log_static( string $event_code, string $message, array $context = [], string $level = 'INFO' ): void {
        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::log( $event_code, $message, $context, $level );
        }
    }
    protected static function log_debug_static( string $event, string $msg, array $ctx = [] ): void { self::log_static( $event, $msg, $ctx, 'DEBUG' ); }
    protected static function log_info_static( string $event, string $msg, array $ctx = [] ): void { self::log_static( $event, $msg, $ctx, 'INFO' ); }
    protected static function log_warning_static( string $event, string $msg, array $ctx = [] ): void { self::log_static( $event, $msg, $ctx, 'WARNING' ); }
    protected static function log_error_static( string $event, string $msg, array $ctx = [] ): void { self::log_static( $event, $msg, $ctx, 'ERROR' ); }
    protected static function log_critical_static( string $event, string $msg, array $ctx = [] ): void { self::log_static( $event, $msg, $ctx, 'CRITICAL' ); }
    protected static function log_security_static( string $event, string $msg, array $ctx = [] ): void { self::log_static( $event, $msg, $ctx, 'SECURITY' ); }
}
