<?php
/**
 * LTMS Alegra Sync - Sincronización con Contabilidad Alegra
 *
 * Orquesta la sincronización bidireccional entre LTMS/WooCommerce y Alegra:
 *
 * 1. Pedido pagado → Crear factura en Alegra al cliente
 * 2. Registro de vendedor → Crear/actualizar contacto en Alegra
 * 3. Retiro aprobado → Registrar pago en Alegra
 * 4. Productos → Sincronizar como items Alegra (lazy, al crear factura)
 *
 * Los IDs de Alegra se almacenan como meta de WooCommerce/WP:
 *   - Pedido: _ltms_alegra_invoice_id, _ltms_alegra_invoice_status
 *   - Usuario: _ltms_alegra_contact_id
 *   - Producto: _ltms_alegra_item_id
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Alegra_Sync
 */
final class LTMS_Alegra_Sync {

    use LTMS_Logger_Aware;

    /**
     * Registra hooks de WordPress/WooCommerce.
     *
     * @return void
     */
    public static function init(): void {
        // Solo sincronizar si Alegra está habilitado
        if ( LTMS_Core_Config::get( 'ltms_alegra_enabled', 'no' ) !== 'yes' ) {
            return;
        }

        $instance = new self();

        // Pedido completado → crear factura
        add_action(
            'woocommerce_order_status_completed',
            [ $instance, 'on_order_completed' ],
            20,
            1
        );

        // También en estado 'processing' si está configurado
        if ( LTMS_Core_Config::get( 'ltms_alegra_invoice_on_processing', 'no' ) === 'yes' ) {
            add_action(
                'woocommerce_order_status_processing',
                [ $instance, 'on_order_completed' ],
                20,
                1
            );
        }

        // Registro de vendedor → crear contacto
        add_action( 'ltms_vendor_registered', [ $instance, 'on_vendor_registered' ], 20, 2 );

        // Payout aprobado → registrar en Alegra
        add_action( 'ltms_payout_completed', [ $instance, 'on_payout_completed' ], 20, 2 );
    }

    // ── Handlers de eventos ───────────────────────────────────────

    /**
     * Cuando un pedido se completa: crear factura en Alegra para el comprador.
     *
     * @param int $order_id ID del pedido WooCommerce.
     * @return void
     */
    public function on_order_completed( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Evitar crear factura duplicada
        if ( $order->get_meta( '_ltms_alegra_invoice_id' ) ) {
            return;
        }

        try {
            $result = $this->create_invoice_for_order( $order );

            if ( ! empty( $result['id'] ) ) {
                $order->update_meta_data( '_ltms_alegra_invoice_id', (int) $result['id'] );
                $order->update_meta_data( '_ltms_alegra_invoice_status', $result['status'] ?? 'open' );

                $invoice_number = $result['numberTemplate']['fullNumber'] ?? '#' . $result['id'];
                $order->update_meta_data( '_ltms_alegra_invoice_number', $invoice_number );

                $order->add_order_note(
                    sprintf(
                        /* translators: %s: número de factura Alegra */
                        __( 'Factura Alegra creada: %s', 'ltms' ),
                        $invoice_number
                    )
                );
                $order->save();

                $this->log_info(
                    'alegra_invoice_created',
                    sprintf( 'Factura Alegra #%d (%s) para pedido WC #%d', $result['id'], $invoice_number, $order_id ),
                    [ 'alegra_invoice_id' => $result['id'], 'wc_order_id' => $order_id ]
                );

                // Enviar factura por email si está configurado
                if ( LTMS_Core_Config::get( 'ltms_alegra_send_invoice_email', 'no' ) === 'yes' ) {
                    $this->maybe_send_invoice_email( (int) $result['id'], $order );
                }
            }
        } catch ( \Throwable $e ) {
            $this->log_error(
                'alegra_invoice_failed',
                sprintf( 'Error creando factura Alegra para pedido #%d: %s', $order_id, $e->getMessage() ),
                [ 'wc_order_id' => $order_id, 'error' => $e->getMessage() ]
            );

            // No interrumpir el flujo del pedido por un fallo de Alegra
        }
    }

    /**
     * Cuando se registra un vendedor: crear contacto en Alegra.
     *
     * @param int    $vendor_id    ID del vendedor.
     * @param string $referral_code Código de referido (no usado aquí).
     * @return void
     */
    public function on_vendor_registered( int $vendor_id, string $referral_code = '' ): void {
        // Si ya tiene ID en Alegra, no duplicar
        if ( get_user_meta( $vendor_id, '_ltms_alegra_contact_id', true ) ) {
            return;
        }

        try {
            $contact_id = $this->sync_user_as_contact( $vendor_id, 'provider' );

            if ( $contact_id ) {
                $this->log_info(
                    'alegra_vendor_contact_created',
                    sprintf( 'Contacto Alegra #%d creado para vendedor #%d', $contact_id, $vendor_id )
                );
            }
        } catch ( \Throwable $e ) {
            $this->log_warning(
                'alegra_vendor_contact_failed',
                sprintf( 'No se pudo crear contacto Alegra para vendedor #%d: %s', $vendor_id, $e->getMessage() )
            );
        }
    }

    /**
     * Cuando se completa un payout: registrar pago en Alegra.
     *
     * @param int   $vendor_id  ID del vendedor.
     * @param float $net_amount Monto neto del retiro.
     * @return void
     */
    public function on_payout_completed( int $vendor_id, float $net_amount ): void {
        $bank_account_id = (int) LTMS_Core_Config::get( 'ltms_alegra_bank_account_id', 0 );
        if ( ! $bank_account_id ) {
            return; // Sin cuenta bancaria configurada, omitir
        }

        $alegra_contact_id = (int) get_user_meta( $vendor_id, '_ltms_alegra_contact_id', true );
        if ( ! $alegra_contact_id ) {
            return;
        }

        try {
            $client = LTMS_Api_Factory::get( 'alegra' );

            $payment = $client->create_payment( [
                'date'            => current_time( 'Y-m-d' ),
                'bank_account_id' => $bank_account_id,
                'payment_method'  => 'transfer',
                'type'            => 'out',
                'client_id'       => $alegra_contact_id,
                'observations'    => sprintf(
                    /* translators: %d: vendor ID */
                    __( 'Pago de comisiones a vendedor #%d — LTMS', 'ltms' ),
                    $vendor_id
                ),
            ] );

            $this->log_info(
                'alegra_payout_registered',
                sprintf( 'Pago Alegra #%d registrado para vendedor #%d', $payment['id'] ?? 0, $vendor_id ),
                [ 'alegra_payment_id' => $payment['id'] ?? 0, 'vendor_id' => $vendor_id, 'amount' => $net_amount ]
            );
        } catch ( \Throwable $e ) {
            $this->log_warning(
                'alegra_payout_failed',
                sprintf( 'No se pudo registrar pago Alegra para vendedor #%d: %s', $vendor_id, $e->getMessage() )
            );
        }
    }

    // ── Lógica de negocio ─────────────────────────────────────────

    /**
     * Crea la factura en Alegra para un pedido WooCommerce.
     *
     * Flujo:
     * 1. Obtener o crear el contacto Alegra del comprador
     * 2. Para cada line item: obtener o crear el item Alegra
     * 3. Crear la factura con esos datos
     *
     * @param \WC_Order $order Pedido WooCommerce.
     * @return array Respuesta de Alegra con la factura creada.
     * @throws \RuntimeException Si no se puede crear la factura.
     */
    public function create_invoice_for_order( \WC_Order $order ): array {
        $client = LTMS_Api_Factory::get( 'alegra' );

        // 1. Obtener o crear contacto para el comprador
        $alegra_contact_id = $this->get_or_create_buyer_contact( $order, $client );

        // 2. Preparar items de la factura
        $invoice_items = $this->prepare_invoice_items( $order, $client );

        if ( empty( $invoice_items ) ) {
            throw new \RuntimeException(
                sprintf( '[AlegraSync] Pedido #%d sin items válidos para factura', $order->get_id() )
            );
        }

        // 3. Preparar datos de la factura
        $invoice_data = [
            'date'       => current_time( 'Y-m-d' ),
            'due_date'   => current_time( 'Y-m-d' ),
            'client_id'  => $alegra_contact_id,
            'items'      => $invoice_items,
            'observations' => sprintf(
                /* translators: %s: número de pedido */
                __( 'Pedido WooCommerce #%s — LT Marketplace Suite', 'ltms' ),
                $order->get_order_number()
            ),
        ];

        // Numeración por defecto si está configurada
        $template_id = (int) LTMS_Core_Config::get( 'ltms_alegra_default_number_template', 0 );
        if ( $template_id ) {
            $invoice_data['number_template_id'] = $template_id;
        }

        // Moneda si no es COP
        $currency = $order->get_currency();
        if ( $currency && $currency !== 'COP' ) {
            $invoice_data['currency']      = $currency;
            $invoice_data['exchange_rate'] = 1; // Ajustar si hay multi-currency real
        }

        return $client->create_invoice( $invoice_data );
    }

    /**
     * Sincroniza un usuario de WordPress como contacto en Alegra.
     *
     * @param int    $user_id ID del usuario WP.
     * @param string $type    Tipo de contacto: 'client' o 'provider'.
     * @return int ID del contacto en Alegra, 0 si falla.
     */
    public function sync_user_as_contact( int $user_id, string $type = 'client' ): int {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return 0;
        }

        try {
            $client = LTMS_Api_Factory::get( 'alegra' );

            $contact_data = [
                'name'           => trim( $user->first_name . ' ' . $user->last_name ) ?: $user->display_name,
                'email'          => $user->user_email,
                'identification' => get_user_meta( $user_id, 'ltms_nit', true )
                                 ?: get_user_meta( $user_id, 'ltms_document_number', true )
                                 ?: '',
                'phone'          => get_user_meta( $user_id, 'ltms_phone', true )
                                 ?: get_user_meta( $user_id, 'billing_phone', true )
                                 ?: '',
                'type'           => [ $type ],
            ];

            $contact = $client->get_or_create_contact( $contact_data );
            $contact_id = (int) ( $contact['id'] ?? 0 );

            if ( $contact_id ) {
                update_user_meta( $user_id, '_ltms_alegra_contact_id', $contact_id );
            }

            return $contact_id;

        } catch ( \Throwable $e ) {
            $this->log_warning(
                'alegra_contact_sync_error',
                sprintf( 'Error sincronizando usuario #%d con Alegra: %s', $user_id, $e->getMessage() )
            );
            return 0;
        }
    }

    /**
     * Sincroniza un producto WooCommerce como item en Alegra.
     *
     * @param int $product_id ID del producto WC.
     * @return int ID del item en Alegra, 0 si falla.
     */
    public function sync_product_as_item( int $product_id ): int {
        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return 0;
        }

        // Verificar si ya fue sincronizado
        $existing_id = (int) get_post_meta( $product_id, '_ltms_alegra_item_id', true );
        if ( $existing_id ) {
            return $existing_id;
        }

        try {
            $client = LTMS_Api_Factory::get( 'alegra' );

            $item_data = [
                'name'        => $product->get_name(),
                'price'       => (float) $product->get_price(),
                'type'        => $product->is_virtual() ? 'service' : 'product',
                'description' => wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ),
            ];

            $item       = $client->create_item( $item_data );
            $item_id    = (int) ( $item['id'] ?? 0 );

            if ( $item_id ) {
                update_post_meta( $product_id, '_ltms_alegra_item_id', $item_id );
            }

            return $item_id;

        } catch ( \Throwable $e ) {
            $this->log_warning(
                'alegra_item_sync_error',
                sprintf( 'Error sincronizando producto #%d con Alegra: %s', $product_id, $e->getMessage() )
            );
            return 0;
        }
    }

    // ── Helpers privados ──────────────────────────────────────────

    /**
     * Obtiene o crea el contacto Alegra del comprador del pedido.
     *
     * @param \WC_Order      $order  Pedido WC.
     * @param \LTMS_Api_Alegra $client Cliente Alegra.
     * @return int ID del contacto en Alegra.
     */
    private function get_or_create_buyer_contact( \WC_Order $order, LTMS_Api_Alegra $client ): int {
        $customer_id = $order->get_customer_id();

        // Verificar si el usuario ya tiene ID Alegra
        if ( $customer_id ) {
            $cached = (int) get_user_meta( $customer_id, '_ltms_alegra_contact_id', true );
            if ( $cached ) {
                return $cached;
            }
        }

        // Construir datos del contacto desde el billing del pedido
        $contact_data = [
            'name'           => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() )
                                ?: $order->get_billing_company()
                                ?: __( 'Cliente Final', 'ltms' ),
            'email'          => $order->get_billing_email(),
            'phone'          => $order->get_billing_phone(),
            'identification' => $order->get_meta( '_billing_identification' )
                             ?: $order->get_meta( '_billing_nit' )
                             ?: '',
            'type'           => [ 'client' ],
            'kindOfPerson'   => 'PERSON_ENTITY',
            'regime'         => 'SIMPLIFIED_REGIME',
            'address'        => [
                'address' => $order->get_billing_address_1(),
                'city'    => $order->get_billing_city(),
            ],
        ];

        $contact    = $client->get_or_create_contact( $contact_data );
        $contact_id = (int) ( $contact['id'] ?? 0 );

        // Guardar para futuras facturas del mismo cliente
        if ( $customer_id && $contact_id ) {
            update_user_meta( $customer_id, '_ltms_alegra_contact_id', $contact_id );
        }

        return $contact_id;
    }

    /**
     * Prepara el array de items para la factura Alegra a partir de los items del pedido.
     *
     * Intenta usar el ID de Alegra guardado en cada producto. Si no existe,
     * sincroniza el producto primero (lazy sync).
     *
     * @param \WC_Order       $order  Pedido WC.
     * @param \LTMS_Api_Alegra $client Cliente Alegra.
     * @return array Items formateados para la API de Alegra.
     */
    private function prepare_invoice_items( \WC_Order $order, LTMS_Api_Alegra $client ): array {
        $items = [];

        foreach ( $order->get_items() as $item ) {
            /** @var \WC_Order_Item_Product $item */
            $product_id = $item->get_product_id();
            $alegra_item_id = (int) get_post_meta( $product_id, '_ltms_alegra_item_id', true );

            // Sincronización lazy: si el producto no tiene ID Alegra, crearlo ahora
            if ( ! $alegra_item_id && $product_id ) {
                $alegra_item_id = $this->sync_product_as_item( $product_id );
            }

            $entry = [
                'quantity' => $item->get_quantity(),
                'price'    => (float) $item->get_subtotal() / max( 1, $item->get_quantity() ),
                'name'     => $item->get_name(),
            ];

            if ( $alegra_item_id ) {
                $entry['alegra_id'] = $alegra_item_id;
            }

            $items[] = $entry;
        }

        // Agregar costos de envío como ítem de servicio
        $shipping_total = (float) $order->get_shipping_total();
        if ( $shipping_total > 0 ) {
            $items[] = [
                'name'     => __( 'Envío', 'ltms' ),
                'price'    => $shipping_total,
                'quantity' => 1,
            ];
        }

        return $items;
    }

    /**
     * Envía la factura Alegra por email al comprador del pedido.
     *
     * @param int       $alegra_invoice_id ID de la factura en Alegra.
     * @param \WC_Order $order             Pedido WC.
     * @return void
     */
    private function maybe_send_invoice_email( int $alegra_invoice_id, \WC_Order $order ): void {
        $email = $order->get_billing_email();
        if ( ! $email ) {
            return;
        }

        try {
            $client = LTMS_Api_Factory::get( 'alegra' );
            $client->send_invoice_email( $alegra_invoice_id, [ $email ] );

            $this->log_info(
                'alegra_invoice_email_sent',
                sprintf( 'Factura Alegra #%d enviada por email a %s', $alegra_invoice_id, $email )
            );
        } catch ( \Throwable $e ) {
            $this->log_warning(
                'alegra_invoice_email_failed',
                sprintf( 'No se pudo enviar email de factura Alegra #%d: %s', $alegra_invoice_id, $e->getMessage() )
            );
        }
    }
}
