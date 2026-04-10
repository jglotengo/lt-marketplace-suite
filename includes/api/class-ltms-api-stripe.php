<?php
/**
 * LTMS API Stripe - Cliente de Pasarela de Pago
 *
 * Integración con la API de Stripe para Colombia (COP) y México (MXN).
 * Soporta: PaymentIntents, reembolsos, Connect, transferencias y clientes.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/api
 * @version    1.5.0
 * @see        https://stripe.com/docs/api
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Api_Stripe
 *
 * Extiende LTMS_Abstract_API_Client pero delega las llamadas HTTP
 * al SDK oficial de Stripe (que ya maneja reintentos y autenticación).
 * perform_request() no se usa; en su lugar se llaman directamente los
 * métodos del SDK dentro de bloques try/catch.
 */
final class LTMS_Api_Stripe extends LTMS_Abstract_API_Client {

    /**
     * Clave secreta de Stripe (sk_test_... o sk_live_...).
     *
     * @var string
     */
    private string $secret_key;

    /**
     * Indica si se opera en modo live (true) o sandbox/test (false).
     *
     * @var bool
     */
    private bool $is_live;

    /**
     * Constructor.
     *
     * @param string $secret_key Clave secreta de Stripe.
     * @param bool   $is_live    true = producción, false = sandbox.
     */
    public function __construct( string $secret_key, bool $is_live = false ) {
        $this->provider_slug = 'stripe';
        $this->api_url       = 'https://api.stripe.com/v1'; // No usado directamente; requerido por padre.
        $this->secret_key    = $secret_key;
        $this->is_live       = $is_live;

        // Inicializar el SDK de Stripe con la clave proporcionada.
        if ( class_exists( '\Stripe\Stripe' ) ) {
            \Stripe\Stripe::setApiKey( $this->secret_key );
            \Stripe\Stripe::setAppInfo(
                'LT Marketplace Suite',
                defined( 'LTMS_VERSION' ) ? LTMS_VERSION : '1.5.0',
                defined( 'LTMS_PLUGIN_URL' ) ? LTMS_PLUGIN_URL : ''
            );
        }
    }

    /**
     * Crea un PaymentIntent en Stripe.
     *
     * @param float  $amount         Monto en moneda local (COP enteros, MXN con 2 decimales).
     * @param string $currency       Código ISO 4217 en minúsculas ('cop' o 'mxn').
     * @param string $customer_email Email del cliente para el recibo.
     * @param array  $metadata       Metadatos adicionales (ej: order_id, vendor_id).
     * @return array{success: bool, data?: array, error?: string}
     */
    public function create_payment_intent(
        float  $amount,
        string $currency,
        string $customer_email,
        array  $metadata = []
    ): array {
        try {
            $stripe_amount = $this->convert_amount_to_stripe_units( $amount, $currency );

            $intent = \Stripe\PaymentIntent::create( [
                'amount'               => $stripe_amount,
                'currency'             => strtolower( $currency ),
                'receipt_email'        => sanitize_email( $customer_email ),
                'metadata'             => $this->sanitize_metadata( $metadata ),
                'automatic_payment_methods' => [ 'enabled' => true ],
            ] );

            return [
                'success' => true,
                'data'    => $intent->toArray(),
            ];

        } catch ( \Stripe\Exception\CardException $e ) {
            LTMS_Core_Logger::error(
                'STRIPE_CARD_ERROR',
                $e->getMessage(),
                [ 'code' => $e->getDeclineCode(), 'currency' => $currency ]
            );
            return [ 'success' => false, 'error' => $e->getMessage() ];

        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            LTMS_Core_Logger::error(
                'STRIPE_API_ERROR',
                $e->getMessage(),
                [ 'type' => $e->getError()->type ?? 'unknown', 'currency' => $currency ]
            );
            return [ 'success' => false, 'error' => $e->getMessage() ];

        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error( 'STRIPE_UNEXPECTED_ERROR', $e->getMessage() );
            return [ 'success' => false, 'error' => $e->getMessage() ];
        }
    }

    /**
     * Confirma un PaymentIntent existente.
     *
     * @param string $payment_intent_id ID del PaymentIntent (pi_...).
     * @return array{success: bool, data?: array, error?: string}
     */
    public function confirm_payment_intent( string $payment_intent_id ): array {
        try {
            $intent = \Stripe\PaymentIntent::retrieve( $payment_intent_id );
            $confirmed = $intent->confirm();

            return [
                'success' => true,
                'data'    => $confirmed->toArray(),
            ];

        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            LTMS_Core_Logger::error(
                'STRIPE_CONFIRM_ERROR',
                $e->getMessage(),
                [ 'payment_intent_id' => $payment_intent_id ]
            );
            return [ 'success' => false, 'error' => $e->getMessage() ];

        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error( 'STRIPE_UNEXPECTED_ERROR', $e->getMessage() );
            return [ 'success' => false, 'error' => $e->getMessage() ];
        }
    }

    /**
     * Crea un reembolso total o parcial sobre un PaymentIntent.
     *
     * @param string $payment_intent_id ID del PaymentIntent original.
     * @param float  $amount            Monto a reembolsar (en moneda local del PI).
     * @param string $reason            Motivo: 'duplicate'|'fraudulent'|'requested_by_customer'.
     * @return array{success: bool, data?: array, error?: string}
     */
    public function create_refund(
        string $payment_intent_id,
        float  $amount,
        string $reason = 'requested_by_customer'
    ): array {
        try {
            // Obtener el PaymentIntent para conocer la moneda y calcular el monto.
            $intent  = \Stripe\PaymentIntent::retrieve( $payment_intent_id );
            $currency = $intent->currency ?? 'cop';

            $refund_params = [
                'payment_intent' => $payment_intent_id,
                'reason'         => $reason,
            ];

            if ( $amount > 0 ) {
                $refund_params['amount'] = $this->convert_amount_to_stripe_units( $amount, $currency );
            }

            $refund = \Stripe\Refund::create( $refund_params );

            return [
                'success' => true,
                'data'    => $refund->toArray(),
            ];

        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            LTMS_Core_Logger::error(
                'STRIPE_REFUND_ERROR',
                $e->getMessage(),
                [ 'payment_intent_id' => $payment_intent_id, 'amount' => $amount ]
            );
            return [ 'success' => false, 'error' => $e->getMessage() ];

        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error( 'STRIPE_UNEXPECTED_ERROR', $e->getMessage() );
            return [ 'success' => false, 'error' => $e->getMessage() ];
        }
    }

    /**
     * Crea o recupera un Customer de Stripe.
     *
     * @param array $data Datos del cliente: email, name, phone, metadata.
     * @return array{success: bool, data?: array, error?: string}
     */
    public function create_customer( array $data ): array {
        try {
            $params = [
                'email'    => sanitize_email( $data['email'] ?? '' ),
                'name'     => sanitize_text_field( $data['name'] ?? '' ),
                'metadata' => $this->sanitize_metadata( $data['metadata'] ?? [] ),
            ];

            if ( ! empty( $data['phone'] ) ) {
                $params['phone'] = sanitize_text_field( $data['phone'] );
            }

            $customer = \Stripe\Customer::create( $params );

            return [
                'success' => true,
                'data'    => $customer->toArray(),
            ];

        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            LTMS_Core_Logger::error(
                'STRIPE_CUSTOMER_ERROR',
                $e->getMessage(),
                [ 'email' => $data['email'] ?? 'unknown' ]
            );
            return [ 'success' => false, 'error' => $e->getMessage() ];

        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error( 'STRIPE_UNEXPECTED_ERROR', $e->getMessage() );
            return [ 'success' => false, 'error' => $e->getMessage() ];
        }
    }

    /**
     * Recupera un PaymentIntent por su ID.
     *
     * @param string $payment_intent_id ID del PaymentIntent (pi_...).
     * @return array{success: bool, data?: array, error?: string}
     */
    public function get_payment_intent( string $payment_intent_id ): array {
        try {
            $intent = \Stripe\PaymentIntent::retrieve( $payment_intent_id );

            return [
                'success' => true,
                'data'    => $intent->toArray(),
            ];

        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            LTMS_Core_Logger::error(
                'STRIPE_GET_INTENT_ERROR',
                $e->getMessage(),
                [ 'payment_intent_id' => $payment_intent_id ]
            );
            return [ 'success' => false, 'error' => $e->getMessage() ];

        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error( 'STRIPE_UNEXPECTED_ERROR', $e->getMessage() );
            return [ 'success' => false, 'error' => $e->getMessage() ];
        }
    }

    /**
     * Crea una cuenta de Stripe Connect para un vendedor.
     *
     * @param array $vendor_data Datos del vendedor: email, country ('CO'|'MX'), business_type, etc.
     * @return array{success: bool, data?: array, error?: string}
     */
    public function create_connect_account( array $vendor_data ): array {
        try {
            // Mapear código de país LTMS a código ISO-3166-1 alpha-2 que acepta Stripe.
            $country_code = strtoupper( $vendor_data['country'] ?? 'CO' );
            if ( ! in_array( $country_code, [ 'CO', 'MX' ], true ) ) {
                $country_code = 'CO';
            }

            $account = \Stripe\Account::create( [
                'type'         => 'express',
                'country'      => $country_code,
                'email'        => sanitize_email( $vendor_data['email'] ?? '' ),
                'capabilities' => [
                    'card_payments' => [ 'requested' => true ],
                    'transfers'     => [ 'requested' => true ],
                ],
                'business_type' => sanitize_text_field( $vendor_data['business_type'] ?? 'individual' ),
                'metadata'      => $this->sanitize_metadata( $vendor_data['metadata'] ?? [] ),
            ] );

            return [
                'success' => true,
                'data'    => $account->toArray(),
            ];

        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            LTMS_Core_Logger::error(
                'STRIPE_CONNECT_CREATE_ERROR',
                $e->getMessage(),
                [ 'email' => $vendor_data['email'] ?? 'unknown' ]
            );
            return [ 'success' => false, 'error' => $e->getMessage() ];

        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error( 'STRIPE_UNEXPECTED_ERROR', $e->getMessage() );
            return [ 'success' => false, 'error' => $e->getMessage() ];
        }
    }

    /**
     * Crea una transferencia hacia una cuenta de Stripe Connect.
     *
     * @param float  $amount                Monto a transferir (en moneda local).
     * @param string $currency              Código ISO 4217 ('cop' o 'mxn').
     * @param string $destination_account_id ID de la cuenta Connect destino (acct_...).
     * @param string $source_transaction    ID del charge o PaymentIntent fuente.
     * @return array{success: bool, data?: array, error?: string}
     */
    public function create_transfer(
        float  $amount,
        string $currency,
        string $destination_account_id,
        string $source_transaction
    ): array {
        try {
            $stripe_amount = $this->convert_amount_to_stripe_units( $amount, $currency );

            $transfer_params = [
                'amount'      => $stripe_amount,
                'currency'    => strtolower( $currency ),
                'destination' => $destination_account_id,
            ];

            // source_transaction puede ser un charge ID (ch_...) o un PI ID.
            if ( ! empty( $source_transaction ) ) {
                $transfer_params['source_transaction'] = $source_transaction;
            }

            $transfer = \Stripe\Transfer::create( $transfer_params );

            return [
                'success' => true,
                'data'    => $transfer->toArray(),
            ];

        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            LTMS_Core_Logger::error(
                'STRIPE_TRANSFER_ERROR',
                $e->getMessage(),
                [
                    'destination' => $destination_account_id,
                    'amount'      => $amount,
                    'currency'    => $currency,
                ]
            );
            return [ 'success' => false, 'error' => $e->getMessage() ];

        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error( 'STRIPE_UNEXPECTED_ERROR', $e->getMessage() );
            return [ 'success' => false, 'error' => $e->getMessage() ];
        }
    }

    /**
     * Consulta el balance de una cuenta de Stripe Connect.
     *
     * @param string $account_id ID de la cuenta Connect (acct_...).
     * @return array{success: bool, data?: array, error?: string}
     */
    public function get_account_balance( string $account_id ): array {
        try {
            $balance = \Stripe\Balance::retrieve(
                [],
                [ 'stripe_account' => $account_id ]
            );

            return [
                'success' => true,
                'data'    => $balance->toArray(),
            ];

        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            LTMS_Core_Logger::error(
                'STRIPE_BALANCE_ERROR',
                $e->getMessage(),
                [ 'account_id' => $account_id ]
            );
            return [ 'success' => false, 'error' => $e->getMessage() ];

        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error( 'STRIPE_UNEXPECTED_ERROR', $e->getMessage() );
            return [ 'success' => false, 'error' => $e->getMessage() ];
        }
    }

    /**
     * Verifica la conectividad con Stripe recuperando el balance de la cuenta principal.
     *
     * @return array{status: string, message: string, latency_ms?: int}
     */
    public function health_check(): array {
        $start = microtime( true );
        try {
            \Stripe\Balance::retrieve();
            $latency = (int) round( ( microtime( true ) - $start ) * 1000 );
            return [ 'status' => 'ok', 'message' => 'Conectado', 'latency_ms' => $latency ];
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error( 'STRIPE_HEALTH_CHECK_FAILED', $e->getMessage() );
            return [ 'status' => 'error', 'message' => $e->getMessage() ];
        }
    }

    /**
     * Convierte un monto en moneda local a la unidad mínima que espera Stripe.
     *
     * COP: Stripe requiere el monto en pesos enteros (0 decimales en Stripe).
     *      Se pasa el valor tal como viene (redondear a entero).
     * MXN: Stripe requiere centavos (monto × 100).
     *
     * @param float  $amount   Monto en moneda local.
     * @param string $currency Código ISO 4217 en cualquier capitalización.
     * @return int Monto en la unidad mínima de la moneda para Stripe.
     */
    private function convert_amount_to_stripe_units( float $amount, string $currency ): int {
        $currency_upper = strtoupper( $currency );

        // COP no tiene sub-unidades en Stripe (zero-decimal currency).
        if ( $currency_upper === 'COP' ) {
            return (int) round( $amount );
        }

        // MXN y otras monedas de 2 decimales → centavos.
        return (int) round( $amount * 100 );
    }

    /**
     * Sanitiza el array de metadatos para Stripe.
     * Stripe acepta hasta 50 claves, cada una máximo 40 chars (clave) y 500 chars (valor).
     *
     * @param array $metadata Metadatos crudos.
     * @return array Metadatos sanitizados.
     */
    private function sanitize_metadata( array $metadata ): array {
        $clean  = [];
        $count  = 0;

        foreach ( $metadata as $key => $value ) {
            if ( $count >= 50 ) {
                break;
            }
            $safe_key   = substr( sanitize_key( (string) $key ), 0, 40 );
            $safe_value = substr( sanitize_text_field( (string) $value ), 0, 500 );
            if ( $safe_key !== '' ) {
                $clean[ $safe_key ] = $safe_value;
                $count++;
            }
        }

        return $clean;
    }
}
