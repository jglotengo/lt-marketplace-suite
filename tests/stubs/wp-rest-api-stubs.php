<?php
/**
 * Stubs mínimos de WP REST API para tests unitarios de webhooks.
 *
 * Solo se definen si WordPress no está cargado (modo UNIT_ONLY). En modo
 * integración las clases reales de WP ya existen y estos guards no aplican.
 *
 * @package LTMS\Tests\Stubs
 */

if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        public mixed $data;
        public int $status;
        public array $headers;

        public function __construct( mixed $data = null, int $status = 200, array $headers = [] ) {
            $this->data    = $data;
            $this->status  = $status;
            $this->headers = $headers;
        }

        public function get_data(): mixed {
            return $this->data;
        }

        public function get_status(): int {
            return $this->status;
        }

        public function get_headers(): array {
            return $this->headers;
        }
    }
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        private string $method;
        private string $route;
        private string $body    = '';
        private array  $headers = [];

        public function __construct( string $method = '', string $route = '', array $attributes = [] ) {
            $this->method = $method;
            $this->route  = $route;
        }

        public function get_method(): string {
            return $this->method;
        }

        public function get_route(): string {
            return $this->route;
        }

        public function get_body(): string {
            return $this->body;
        }

        public function set_body( string $body ): void {
            $this->body = $body;
        }

        public function get_header( string $key ): string {
            return $this->headers[ strtolower( $key ) ] ?? '';
        }

        public function set_header( string $key, string $value ): void {
            $this->headers[ strtolower( $key ) ] = $value;
        }

        public function get_json_params(): ?array {
            $decoded = json_decode( $this->body, true );
            return is_array( $decoded ) ? $decoded : null;
        }
    }
}
