<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
trait LTMS_Logger_Aware {
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
    protected static function log_error_static( string $event, string $msg, array $ctx = [] ): void {
        if ( class_exists( 'LTMS_Core_Logger' ) ) { LTMS_Core_Logger::log( $event, $msg, $ctx, 'ERROR' ); }
    }
    protected static function log_info_static( string $event, string $msg, array $ctx = [] ): void {
        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::log( $event, $msg, $ctx, 'INFO' );
        }
    }
}