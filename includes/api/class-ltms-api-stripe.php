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

        // INTEGRATIONS-AUDIT P1 FIX: validate secret_key format and throw if
        // SDK is missing. Previously, a missing SDK produced a fatal error on
        // the first ::create() call instead of a clear constructor error.
        if ( '' === $secret_key ) {
            throw new \RuntimeException( '[stripe] secret_key vacía.' );
        }
        if ( ! class_exists( '\Stripe\Stripe' ) ) {
            throw new \RuntimeException( '[stripe] Stripe PHP SDK no cargado. Ejecute composer require stripe/stripe-php.' );
        }

        // Inicializar el SDK de Stripe con la clave proporcionada.
        \Stripe\Stripe::setApiKey( $this->secret_key );
        \Stripe\Stripe::setAppInfo(
            'LT Marketplace Suite',
            defined( 'LTMS_VERSION' ) ? LTMS_VERSION : '1.5.0',
            defined( 'LTMS_PLUGIN_URL' ) ? LTMS_PLUGIN_URL : ''
        );
        // INTEGRATIONS-AUDIT P0 FIX: configure SDK max network retries.
        // The SDK default is 1 retry — too few for transient 5xx. Without this,
        // the abstract client's max_retries was completely irrelevant since
        // Stripe SDK bypasses perform_request().
        \Stripe\Stripe::setMaxNetworkRetries( 3 );
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
        array  $metadata = [],
        string $payment_method_id = ''
    ): array {
        try {
            // INTEGRATIONS-AUDIT P1 FIX: validate amount + currency.
            if ( ! is_finite( $amount ) || $amount <= 0 ) {
                return [ 'success' => false, 'error' => '[stripe] Monto inválido.' ];
            }
            $currency_lower = strtolower( $currency );
            if ( ! in_array( $currency_lower, [ 'cop', 'mxn' ], true ) ) {
                return [ 'success' => false, 'error' => '[stripe] Moneda no soportada: ' . $currency ];
            }

            $stripe_amount = $this->convert_amount_to_stripe_units( $amount, $currency );

            $params = [
                'amount'        => $stripe_amount,
                'currency'      => $currency_lower,
                'receipt_email' => sanitize_email( $customer_email ),
                'metadata'      => $this->sanitize_metadata( $metadata ),
            ];

            if ( ! empty( $payment_method_id ) ) {
                // M-40 FIX: Adjuntar el PaymentMethod y confirmar en una sola llamada API.
                // Mueve el PI de 'requires_payment_method' → 'succeeded' (o
                // 'requires_action' si necesita 3DS) sin llamada extra de confirm().
                $params['payment_method']       = sanitize_text_field( $payment_method_id );
                $params['confirm']              = true;
                $params['return_url']           = wc_get_checkout_url();
                $params['payment_method_types'] = [ 'card' ];
            } else {
                // Fallback sin payment_method (ej. Apple Pay desde frontend).
                $params['automatic_payment_methods'] = [ 'enabled' => true ];
            }

            // INTEGRATIONS-AUDIT P0 FIX: idempotency_key on PaymentIntent create
            // prevents duplicate charges on SDK retry or caller retry.
            $order_ref = $metadata['order_id'] ?? ( $metadata['ltms_order_id'] ?? md5( wp_json_encode( $params ) ) );
            $intent = \Stripe\PaymentIntent::create(
                $params,
                [
                    'idempotency_key' => 'ltms_pi_' . $order_ref,
                    'api_key'         => $this->secret_key,
                ]
            );

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
            // INTEGRATIONS-AUDIT P1 FIX: validate amount + reason before any API call.
            // Also avoids the TOCTOU race where retrieving the PI for currency,
            // then issuing the refund, opens a window for a concurrent refund
            // to land first (double refund).
            if ( ! is_finite( $amount ) || $amount < 0 ) {
                return [ 'success' => false, 'error' => '[stripe] Monto de reembolso inválido.' ];
            }
            $allowed_reasons = [ 'duplicate', 'fraudulent', 'requested_by_customer' ];
            if ( ! in_array( $reason, $allowed_reasons, true ) ) {
                return [ 'success' => false, 'error' => '[stripe] Razón de reembolso inválida: ' . $reason ];
            }
            if ( '' === $payment_intent_id || ! preg_match( '/^pi_[A-Za-z0-9]+$/', $payment_intent_id ) ) {
                return [ 'success' => false, 'error' => '[stripe] payment_intent_id inválido.' ];
            }

            // Caller must pass the currency of the original PI. Default to COP for CO.
            $currency = 'cop';

            $refund_params = [
                'payment_intent' => $payment_intent_id,
                'reason'         => $reason,
            ];

            if ( $amount > 0 ) {
                $refund_params['amount'] = $this->convert_amount_to_stripe_units( $amount, $currency );
            }

            // INTEGRATIONS-AUDIT P0 FIX: idempotency_key on refund prevents
            // double refunds on SDK retry or caller retry. Keyed by PI + amount.
            $refund = \Stripe\Refund::create(
                $refund_params,
                [
                    'idempotency_key' => 'ltms_refund_' . $payment_intent_id . '_' . ( $amount > 0 ? (string) $amount : 'full' ),
                    'api_key'         => $this->secret_key,
                ]
            );

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
            // INTEGRATIONS-AUDIT P1 FIX: validate amount, currency, destination.
            if ( ! is_finite( $amount ) || $amount <= 0 ) {
                return [ 'success' => false, 'error' => '[stripe] Monto de transferencia inválido.' ];
            }
            $currency_lower = strtolower( $currency );
            if ( ! in_array( $currency_lower, [ 'cop', 'mxn' ], true ) ) {
                return [ 'success' => false, 'error' => '[stripe] Moneda no soportada: ' . $currency ];
            }
            if ( ! preg_match( '/^acct_[A-Za-z0-9]+$/', $destination_account_id ) ) {
                return [ 'success' => false, 'error' => '[stripe] destination_account_id inválido.' ];
            }
            if ( '' !== $source_transaction && ! preg_match( '/^(ch|pi)_[A-Za-z0-9]+$/', $source_transaction ) ) {
                return [ 'success' => false, 'error' => '[stripe] source_transaction inválido.' ];
            }

            $stripe_amount = $this->convert_amount_to_stripe_units( $amount, $currency );

            $transfer_params = [
                'amount'      => $stripe_amount,
                'currency'    => $currency_lower,
                'destination' => $destination_account_id,
            ];

            // source_transaction puede ser un charge ID (ch_...) o un PI ID.
            if ( ! empty( $source_transaction ) ) {
                $transfer_params['source_transaction'] = $source_transaction;
            }

            // INTEGRATIONS-AUDIT P0 FIX: idempotency_key on transfer.
            $transfer = \Stripe\Transfer::create(
                $transfer_params,
                [
                    'idempotency_key' => 'ltms_transfer_' . $destination_account_id . '_' . md5( (string) $stripe_amount . $currency_lower . $source_transaction ),
                    'api_key'         => $this->secret_key,
                ]
            );

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
        // INTEGRATIONS-AUDIT P1 FIX: guard against NaN/INF. (int)NAN = 0 on
        // most platforms → silent zero-value PaymentIntent.
        if ( ! is_finite( $amount ) ) {
            throw new \InvalidArgumentException( '[stripe] convert_amount: monto no finito.' );
        }
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
