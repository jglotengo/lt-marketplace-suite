<?php
/**
 * LTMS Vendor Invoicing Generator
 *
 * v2.9.222: Genera facturas electrónicas en Alegra o Siigo usando las
 * credenciales del vendedor (no las del marketplace).
 *
 * Solo factura los items del pedido que pertenecen al vendor_id dado.
 * Si el comprador NO marcó "Necesito factura", genera una factura
 * genérica (Consumidor Final / Venta al público en general).
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @version    2.9.222
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Vendor_Invoicing_Generator {

    /**
     * Genera factura en Alegra usando credenciales del vendor.
     *
     * @param int       $vendor_id ID del vendedor.
     * @param \WC_Order $order     Pedido WooCommerce.
     * @param array     $creds     Credenciales {email, token}.
     * @return array{success:bool, invoice_id?:int, invoice_number?:string, error?:string}
     */
    public static function generate_alegra_invoice( int $vendor_id, \WC_Order $order, array $creds ): array {
        // 1. Construir payload del cliente (receptor de la factura).
        $buyer = self::build_buyer_payload( $order );

        // 2. Construir items (solo los del vendor).
        $items = self::build_vendor_items( $vendor_id, $order );
        if ( empty( $items ) ) {
            return [ 'success' => false, 'error' => __( 'No se encontraron items del vendedor en el pedido.', 'ltms' ) ];
        }

        // 3. Crear o recuperar contacto en Alegra.
        $contact_id = self::alegra_get_or_create_contact( $creds, $buyer );
        if ( ! $contact_id ) {
            return [ 'success' => false, 'error' => __( 'No se pudo crear el contacto en Alegra.', 'ltms' ) ];
        }

        // 4. Construir payload de factura.
        $payload = [
            'client'        => $contact_id,
            'items'         => $items,
            'date'          => gmdate( 'Y-m-d' ),
            'dueDate'       => gmdate( 'Y-m-d', strtotime( '+30 days' ) ),
            'observations'  => sprintf(
                'Pedido Lo Tengo #%s — Vendor #%d',
                $order->get_order_number(),
                $vendor_id
            ),
            'anotation'     => 'WC-' . $order->get_order_number() . '-V' . $vendor_id,
        ];

        // 5. Llamar a POST /api/v1/invoices.
        $response = wp_remote_post( 'https://api.alegra.com/api/v1/invoices', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $creds['email'] . ':' . $creds['token'] ),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 201 || empty( $body['id'] ) ) {
            $msg = $body['message'] ?? $body['error'] ?? __( 'HTTP ', 'ltms' ) . $code;
            if ( ! empty( $body['errors'] ) ) {
                $details = [];
                foreach ( (array) $body['errors'] as $err ) {
                    $details[] = $err['message'] ?? $err['error'] ?? wp_json_encode( $err );
                }
                $msg .= ' — ' . implode( '; ', $details );
            }
            return [ 'success' => false, 'error' => $msg ];
        }

        return [
            'success'        => true,
            'invoice_id'     => (int) $body['id'],
            'invoice_number' => $body['numberTemplate']['fullNumber'] ?? (string) $body['id'],
        ];
    }

    /**
     * Genera factura en Siigo usando credenciales del vendor.
     *
     * @param int       $vendor_id ID del vendedor.
     * @param \WC_Order $order     Pedido WooCommerce.
     * @param array     $creds     Credenciales {username, key}.
     * @return array{success:bool, invoice_id?:string, invoice_number?:string, error?:string}
     */
    public static function generate_siigo_invoice( int $vendor_id, \WC_Order $order, array $creds ): array {
        // 1. Autenticar y obtener JWT.
        $token = self::siigo_authenticate( $creds['username'], $creds['key'] );
        if ( empty( $token ) ) {
            return [ 'success' => false, 'error' => __( 'No se pudo autenticar con Siigo.', 'ltms' ) ];
        }

        // 2. Construir payload del cliente.
        $buyer = self::build_buyer_payload( $order );

        // 3. Crear o recuperar customer en Siigo.
        $customer_id = self::siigo_get_or_create_customer( $token, $buyer );
        if ( empty( $customer_id ) ) {
            return [ 'success' => false, 'error' => __( 'No se pudo crear el cliente en Siigo.', 'ltms' ) ];
        }

        // 4. Construir items (solo los del vendor).
        $items = self::build_vendor_items_siigo( $vendor_id, $order );
        if ( empty( $items ) ) {
            return [ 'success' => false, 'error' => __( 'No se encontraron items del vendedor en el pedido.', 'ltms' ) ];
        }

        // 5. Construir payload de factura.
        $payload = [
            'customer'   => [ 'id' => $customer_id ],
            'document'   => [ 'id' => 1 ], // ID de tipo de documento (1 = Factura)
            'date'       => gmdate( 'Y-m-d' ),
            'items'      => $items,
            'payments'   => [
                [
                    'id'          => 1, // 1 = Efectivo
                    'value'       => $order->get_total(),
                    'due_date'    => gmdate( 'Y-m-d' ),
                ],
            ],
            'observations' => sprintf(
                'Pedido Lo Tengo #%s — Vendor #%d',
                $order->get_order_number(),
                $vendor_id
            ),
        ];

        // 6. Llamar a POST /v1/invoices.
        $response = wp_remote_post( 'https://api.siigo.com/v1/invoices', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'Partner-Id'    => 'ltms',
            ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 60,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'error' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 201 || empty( $body['id'] ) ) {
            $msg = $body['message'] ?? $body['error'] ?? __( 'HTTP ', 'ltms' ) . $code;
            if ( ! empty( $body['errors'] ) ) {
                $details = [];
                foreach ( (array) $body['errors'] as $err ) {
                    $details[] = $err['detail'] ?? $err['message'] ?? wp_json_encode( $err );
                }
                $msg .= ' — ' . implode( '; ', $details );
            }
            return [ 'success' => false, 'error' => $msg ];
        }

        return [
            'success'        => true,
            'invoice_id'     => (string) $body['id'],
            'invoice_number' => $body['number'] ?? $body['id'],
        ];
    }

    // ── HELPERS ──────────────────────────────────────────────────────────

    /**
     * Construye el payload del comprador (receptor) a partir del order meta
     * guardado por LTMS_Frontend_Checkout_Optional_Invoice_Fields.
     */
    private static function build_buyer_payload( \WC_Order $order ): array {
        $needs_invoice = $order->get_meta( '_ltms_buyer_needs_invoice' ) === '1';
        $tax_id        = (string) $order->get_meta( '_ltms_buyer_tax_id' );
        $company       = (string) $order->get_meta( '_ltms_buyer_company_name' );

        if ( $needs_invoice && ! empty( $tax_id ) && ! empty( $company ) ) {
            return [
                'name'          => $company,
                'identification'=> $tax_id,
                'email'         => $order->get_billing_email(),
                'phone'         => $order->get_billing_phone(),
                'address'       => $order->get_billing_address_1(),
                'needs_invoice' => true,
            ];
        }
        // Factura genérica (consumidor final).
        return [
            'name'          => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'identification'=> '222222222222', // Genérico Colombia
            'email'         => $order->get_billing_email(),
            'phone'         => $order->get_billing_phone(),
            'address'       => $order->get_billing_address_1(),
            'needs_invoice' => false,
        ];
    }

    /**
     * Construye los items de Alegra (solo los del vendor_id dado).
     */
    private static function build_vendor_items( int $vendor_id, \WC_Order $order ): array {
        $items = [];
        foreach ( $order->get_items() as $item ) {
            $pid = $item->get_product_id();
            $vid = (int) get_post_meta( $pid, '_ltms_vendor_id', true );
            if ( ! $vid ) {
                $vid = (int) get_post_field( 'post_author', $pid );
            }
            if ( $vid !== $vendor_id ) {
                continue;
            }
            $product = $item->get_product();
            $price   = $product ? (float) $product->get_price() : (float) $item->get_total();
            $items[] = [
                'id'          => 1, // Alegra asigna el id real
                'name'        => $item->get_name(),
                'description' => sprintf( 'SKU: %s', $product ? $product->get_sku() : 'N/A' ),
                'price'       => $price,
                'quantity'    => $item->get_quantity(),
                'tax'         => [ [ 'id' => 1 ] ], // IVA 19% por defecto (configurable)
            ];
        }
        return $items;
    }

    /**
     * Construye los items de Siigo (formato diferente).
     */
    private static function build_vendor_items_siigo( int $vendor_id, \WC_Order $order ): array {
        $items = [];
        foreach ( $order->get_items() as $item ) {
            $pid = $item->get_product_id();
            $vid = (int) get_post_meta( $pid, '_ltms_vendor_id', true );
            if ( ! $vid ) {
                $vid = (int) get_post_field( 'post_author', $pid );
            }
            if ( $vid !== $vendor_id ) {
                continue;
            }
            $product = $item->get_product();
            $price   = $product ? (float) $product->get_price() : (float) $item->get_total();
            $items[] = [
                'code'        => $product ? $product->get_sku() : 'N/A',
                'description' => $item->get_name(),
                'price'       => $price,
                'quantity'    => $item->get_quantity(),
                'taxes'       => [ [ 'id' => '11856' ] ], // IVA 16% MX (configurable)
            ];
        }
        return $items;
    }

    /**
     * Busca o crea un contacto en Alegra.
     */
    private static function alegra_get_or_create_contact( array $creds, array $buyer ): ?int {
        // Buscar por identification.
        $email_enc = rawurlencode( $buyer['email'] );
        $response = wp_remote_get( 'https://api.alegra.com/api/v1/contacts?identification=' . rawurlencode( $buyer['identification'] ), [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $creds['email'] . ':' . $creds['token'] ),
                'Accept'        => 'application/json',
            ],
            'timeout' => 20,
        ] );
        if ( ! is_wp_error( $response ) ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! empty( $body[0]['id'] ) ) {
                return (int) $body[0]['id'];
            }
        }

        // Crear contacto nuevo.
        $name_parts = explode( ' ', $buyer['name'], 2 );
        $payload = [
            'name'           => $buyer['name'],
            'identification' => $buyer['identification'],
            'email'          => [ $buyer['email'] ],
            'phone'          => [ $buyer['phone'] ],
            'address'        => [ 'address' => $buyer['address'] ],
            'nameObject'     => [
                'firstName'  => $name_parts[0] ?? $buyer['name'],
                'secondName' => null,
                'lastName'   => $name_parts[1] ?? $name_parts[0] ?? $buyer['name'],
                'secondLastName' => null,
            ],
            'kindOfPerson'   => 'PERSON_ENTITY',
            'regime'         => 'SIMPLIFIED_REGIME',
        ];
        $response = wp_remote_post( 'https://api.alegra.com/api/v1/contacts', [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $creds['email'] . ':' . $creds['token'] ),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 30,
        ] );
        if ( is_wp_error( $response ) ) {
            return null;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return ! empty( $body['id'] ) ? (int) $body['id'] : null;
    }

    /**
     * Autentica con Siigo y devuelve el JWT.
     */
    private static function siigo_authenticate( string $username, string $key ): string {
        $cache_key = 'ltms_vendor_siigo_token_' . md5( $username );
        $cached = get_transient( $cache_key );
        if ( $cached ) {
            return $cached;
        }
        $response = wp_remote_post( 'https://api.siigo.com/auth/token-b2b/v1', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Partner-Id'   => 'ltms',
            ],
            'body' => wp_json_encode( [
                'username'   => $username,
                'access_key' => $key,
            ] ),
            'timeout' => 30,
        ] );
        if ( is_wp_error( $response ) ) {
            return '';
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $token = $body['access_token'] ?? '';
        if ( $token ) {
            set_transient( $cache_key, $token, 3300 ); // 55 min (token dura 1h)
        }
        return $token;
    }

    /**
     * Busca o crea un customer en Siigo.
     */
    private static function siigo_get_or_create_customer( string $token, array $buyer ): ?string {
        // Buscar por identificación.
        $response = wp_remote_get( 'https://api.siigo.com/v1/customers?identification=' . rawurlencode( $buyer['identification'] ), [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
                'Partner-Id'    => 'ltms',
            ],
            'timeout' => 30,
        ] );
        if ( ! is_wp_error( $response ) ) {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! empty( $body['results'][0]['id'] ) ) {
                return (string) $body['results'][0]['id'];
            }
        }

        // Crear customer nuevo.
        $name_parts = explode( ' ', $buyer['name'], 2 );
        $payload = [
            'type'            => [ 'id' => 1, 'name' => 'Customer' ],
            'person_type'     => [ 'id' => 1, 'name' => 'Person' ],
            'identification'  => [
                'type'          => [ 'id' => 13 ], // Cédula de ciudadanía
                'number'        => $buyer['identification'],
            ],
            'name'            => [
                [ $name_parts[0] ?? $buyer['name'] ],
            ],
            'contacts'        => [
                [
                    'first_name'  => $name_parts[0] ?? $buyer['name'],
                    'last_name'   => $name_parts[1] ?? '',
                    'email'       => $buyer['email'],
                    'phone'       => [ $buyer['phone'] ],
                ],
            ],
            'address'         => [ 'address' => $buyer['address'] ],
        ];
        $response = wp_remote_post( 'https://api.siigo.com/v1/customers', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'Partner-Id'    => 'ltms',
            ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 30,
        ] );
        if ( is_wp_error( $response ) ) {
            return null;
        }
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return ! empty( $body['id'] ) ? (string) $body['id'] : null;
    }
}
