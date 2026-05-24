<?php
/**
 * LTMS API Gateways — Openpay y Addi (WC_Payment_Gateway)
 *
 * Integra Openpay y Addi como pasarelas de pago nativas en WooCommerce.
 * Son registradas por LTMS_Core_Kernel::register_payment_gateways().
 *
 * @package    LTMS
 * @subpackage LTMS/includes/api/gateways
 * @version    1.7.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// OPENPAY GATEWAY
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Class LTMS_Api_Gateway_Openpay
 *
 * Pasarela de pago Openpay para Colombia y México.
 * Soporta: tarjetas de crédito/débito, PSE, efectivo (Bancolombia, OXXO).
 */
class LTMS_Api_Gateway_Openpay extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'ltms_openpay';
        $this->has_fields         = true;
        $this->method_title       = __( 'Openpay — LT Marketplace', 'ltms' );
        $this->method_description = __( 'Pago con tarjeta, PSE y efectivo vía Openpay.', 'ltms' );
        $this->supports           = [ 'products', 'refunds' ];

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title', __( 'Pago con tarjeta / PSE (Openpay)', 'ltms' ) );
        $this->description = $this->get_option( 'description', __( 'Paga de forma segura con Openpay.', 'ltms' ) );
        $this->enabled     = $this->get_option( 'enabled', 'yes' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_scripts' ] );

        // A-9 FIX: Aviso en wp-admin si el gateway está activo pero sin credenciales
        if ( is_admin() && 'yes' === $this->enabled ) {
            add_action( 'admin_notices', [ $this, 'maybe_show_credentials_notice' ] );
        }
    }

    /**
     * Muestra un aviso en wp-admin si Openpay está activo pero sin credenciales configuradas.
     *
     * @return void
     */
    public function maybe_show_credentials_notice(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $merchant_id = $this->get_option( 'merchant_id', '' );
        $private_key = $this->get_option( 'private_key', '' );

        if ( empty( $merchant_id ) || empty( $private_key ) ) {
            $config_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=ltms_openpay' );
            echo '<div class="notice notice-error is-dismissible"><p>' .
                 sprintf(
                     // translators: %s: URL de configuración
                     wp_kses(
                         __( '<strong>LTMS Openpay:</strong> El gateway de pago está activo pero las credenciales no están configuradas. Los compradores verán un mensaje de "método no disponible". <a href="%s">Configura las credenciales aquí</a>.', 'ltms' ),
                         [ 'strong' => [], 'a' => [ 'href' => [] ] ]
                     ),
                     esc_url( $config_url )
                 ) .
                 '</p></div>';
        }
    }

    /**
     * Saves WC gateway settings AND syncs credentials to LTMS config options
     * so that LTMS_Api_Openpay (which reads from LTMS_Core_Config) finds them.
     *
     * @return void
     */
    public function process_admin_options(): void {
        parent::process_admin_options();

        $country = LTMS_Core_Config::get_country();
        update_option( "ltms_openpay_{$country}_merchant_id", $this->get_option( 'merchant_id', '' ) );
        update_option( "ltms_openpay_{$country}_private_key",  $this->get_option( 'private_key', '' ) );
        update_option( 'ltms_openpay_merchant_id', $this->get_option( 'merchant_id', '' ) );
        update_option( 'ltms_openpay_private_key',  $this->get_option( 'private_key', '' ) );
        update_option( 'ltms_openpay_public_key',   $this->get_option( 'public_key', '' ) );
    }

    /**
     * Encola Openpay.js y el script de tokenización en el checkout.
     *
     * @return void
     */
    public function enqueue_checkout_scripts(): void {
        if ( ! is_checkout() || $this->enabled !== 'yes' ) {
            return;
        }

        $country = LTMS_Core_Config::get_country();
        $sdk_url = $country === 'MX' ? 'https://js.openpay.mx/' : 'https://js.openpay.co/';

        if ( ! wp_script_is( 'openpay-js', 'enqueued' ) ) {
            wp_enqueue_script( 'openpay-js',   $sdk_url . 'openpay.v1.min.js',      [], '1.0', true );
            wp_enqueue_script( 'openpay-data', $sdk_url . 'openpay-data.v1.min.js', [ 'openpay-js' ], '1.0', true );
        }

        wp_enqueue_script(
            'ltms-openpay-gateway',
            LTMS_ASSETS_URL . 'js/ltms-openpay-gateway.js',
            [ 'jquery', 'openpay-data' ],
            LTMS_VERSION,
            true
        );

        wp_localize_script( 'ltms-openpay-gateway', 'ltmsOpenpay', [
            'merchant_id' => $this->get_option( 'merchant_id', '' ),
            'public_key'  => $this->get_option( 'public_key', '' ),
            'is_sandbox'  => $this->get_option( 'testmode', 'yes' ) === 'yes',
            'i18n'        => [
                'fill_all_fields'    => __( 'Por favor completa todos los datos de la tarjeta.', 'ltms' ),
                'card_error'         => __( 'Error al procesar la tarjeta. Verifica los datos.', 'ltms' ),
                'sdk_unavailable'    => __( 'El módulo de pago no está disponible. Recarga la página.', 'ltms' ),
                'invalid_card'       => __( 'Número de tarjeta inválido.', 'ltms' ),
                'card_declined'      => __( 'La tarjeta fue rechazada.', 'ltms' ),
                'card_expired'       => __( 'La tarjeta ha vencido.', 'ltms' ),
                'insufficient_funds' => __( 'Fondos insuficientes.', 'ltms' ),
                'card_blocked'       => __( 'Tarjeta bloqueada por sospecha de fraude.', 'ltms' ),
            ],
        ] );
    }

    /**
     * Renderiza el formulario de tarjeta de crédito en el checkout de WooCommerce.
     *
     * @return void
     */
    public function payment_fields(): void {
        // A-9 FIX: Mostrar aviso de credenciales faltantes solo a admins, no al comprador.
        // Antes el error del constructor (RuntimeException) llegaba al wc_add_notice y el
        // comprador veía "Credenciales no configuradas para país CO" en el checkout.
        $merchant_id = $this->get_option( 'merchant_id', '' );
        $private_key = $this->get_option( 'private_key', '' );

        if ( empty( $merchant_id ) || empty( $private_key ) ) {
            if ( current_user_can( 'manage_woocommerce' ) ) {
                echo '<div class="woocommerce-info">' .
                     '<strong>LTMS Admin:</strong> ' .
                     esc_html__( 'Las credenciales de Openpay no están configuradas. Ve a WooCommerce → Pagos → LTMS Openpay → Configurar.', 'ltms' ) .
                     '</div>';
            } else {
                // Al comprador: mostrar mensaje amigable sin detalles técnicos
                echo '<p class="woocommerce-info">' .
                     esc_html__( 'Este método de pago no está disponible en este momento. Por favor selecciona otro método.', 'ltms' ) .
                     '</p>';
            }
            return;
        }

        if ( $this->description ) {
            echo '<p>' . wp_kses_post( $this->description ) . '</p>';
        }
        ?>
        <fieldset id="ltms-openpay-fields" style="border:0;padding:0;margin:0;">
            <p class="form-row form-row-wide">
                <label for="ltms-card-number"><?php esc_html_e( 'Número de tarjeta', 'ltms' ); ?> <span class="required">*</span></label>
                <input
                    id="ltms-card-number"
                    type="text"
                    class="input-text"
                    maxlength="19"
                    placeholder="1234 5678 9012 3456"
                    autocomplete="cc-number"
                    inputmode="numeric"
                />
            </p>
            <p class="form-row form-row-wide">
                <label for="ltms-card-name"><?php esc_html_e( 'Nombre del titular', 'ltms' ); ?> <span class="required">*</span></label>
                <input
                    id="ltms-card-name"
                    type="text"
                    class="input-text"
                    placeholder="<?php esc_attr_e( 'Como aparece en la tarjeta', 'ltms' ); ?>"
                    autocomplete="cc-name"
                />
            </p>
            <p class="form-row form-row-first">
                <label for="ltms-card-expiry"><?php esc_html_e( 'Vencimiento (MM/AA)', 'ltms' ); ?> <span class="required">*</span></label>
                <input
                    id="ltms-card-expiry"
                    type="text"
                    class="input-text"
                    maxlength="5"
                    placeholder="MM/AA"
                    autocomplete="cc-exp"
                    inputmode="numeric"
                />
            </p>
            <p class="form-row form-row-last">
                <label for="ltms-card-cvv"><?php esc_html_e( 'CVV', 'ltms' ); ?> <span class="required">*</span></label>
                <input
                    id="ltms-card-cvv"
                    type="password"
                    class="input-text"
                    maxlength="4"
                    placeholder="•••"
                    autocomplete="cc-csc"
                    inputmode="numeric"
                />
            </p>
            <div id="ltms-openpay-card-errors" role="alert" style="display:none;color:#dc3232;padding:8px;margin-top:4px;font-size:13px;background:#fbeaea;border-radius:3px;"></div>
            <input type="hidden" name="openpay_token" id="ltms_openpay_token" value="" />
            <input type="hidden" name="openpay_device_session_id" id="ltms_openpay_device" value="" />
        </fieldset>
        <?php
    }

    /**
     * Valida que el token de Openpay fue generado antes de continuar.
     *
     * @return bool
     */
    public function validate_fields(): bool {
        $token = isset( $_POST['openpay_token'] )
            ? sanitize_text_field( wp_unslash( $_POST['openpay_token'] ) )
            : '';

        if ( empty( $token ) ) {
            wc_add_notice( __( 'Por favor completa los datos de tu tarjeta para continuar.', 'ltms' ), 'error' );
            return false;
        }

        return true;
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled'    => [
                'title'   => __( 'Habilitar', 'ltms' ),
                'type'    => 'checkbox',
                'label'   => __( 'Habilitar Openpay', 'ltms' ),
                'default' => 'yes',
            ],
            'title'      => [
                'title'   => __( 'Título', 'ltms' ),
                'type'    => 'text',
                'default' => __( 'Pago con tarjeta / PSE (Openpay)', 'ltms' ),
            ],
            'description' => [
                'title'   => __( 'Descripción', 'ltms' ),
                'type'    => 'textarea',
                'default' => __( 'Paga de forma segura con Openpay.', 'ltms' ),
            ],
            'testmode'   => [
                'title'   => __( 'Modo sandbox', 'ltms' ),
                'type'    => 'checkbox',
                'label'   => __( 'Activar sandbox', 'ltms' ),
                'default' => 'yes',
            ],
            'merchant_id' => [
                'title'   => __( 'Merchant ID', 'ltms' ),
                'type'    => 'text',
                'default' => '',
            ],
            'public_key' => [
                'title'   => __( 'Llave Pública', 'ltms' ),
                'type'    => 'text',
                'default' => '',
            ],
            'private_key' => [
                'title'   => __( 'Llave Privada', 'ltms' ),
                'type'    => 'password',
                'default' => '',
            ],
        ];
    }

    /**
     * Procesa el pago de un pedido.
     *
     * @param int $order_id
     * @return array{result:string, redirect:string}
     */
    public function process_payment( $order_id ): array {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wc_add_notice( __( 'Pedido no encontrado.', 'ltms' ), 'error' );
            return [ 'result' => 'failure', 'redirect' => '' ];
        }

        try {
            if ( ! class_exists( 'LTMS_Api_Openpay' ) ) {
                throw new \RuntimeException( __( 'Módulo Openpay no disponible.', 'ltms' ) );
            }

            // Sync gateway credentials to LTMS_Core_Config options so LTMS_Api_Openpay finds them.
            // The factory caches instances, so reset it to force fresh instantiation with new creds.
            $country = LTMS_Core_Config::get_country();
            update_option( "ltms_openpay_{$country}_merchant_id", $this->get_option( 'merchant_id', '' ) );
            update_option( "ltms_openpay_{$country}_private_key",  $this->get_option( 'private_key', '' ) );
            update_option( 'ltms_openpay_merchant_id', $this->get_option( 'merchant_id', '' ) );
            update_option( 'ltms_openpay_private_key',  $this->get_option( 'private_key', '' ) );
            LTMS_Api_Factory::reset( 'openpay' );

            $client  = LTMS_Api_Factory::get( 'openpay' );
            // phpcs:ignore WordPress.Security.NonceVerification
            $token   = sanitize_text_field( wp_unslash( $_POST['openpay_token'] ?? '' ) );
            // phpcs:ignore WordPress.Security.NonceVerification
            $device  = sanitize_text_field( wp_unslash( $_POST['openpay_device_session_id'] ?? '' ) );

            $result = $client->charge( [
                'order_id'         => $order_id,
                'amount'           => $order->get_total(),
                'currency'         => get_woocommerce_currency(),
                'source_id'        => $token,
                'device_session_id'=> $device,
                'description'      => sprintf( __( 'Pedido #%s', 'ltms' ), $order->get_order_number() ),
                'customer'         => [
                    'name'         => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'email'        => $order->get_billing_email(),
                    'phone_number' => $order->get_billing_phone(),
                ],
            ] );

            $order->payment_complete( $result['id'] ?? '' );
            $order->update_meta_data( '_openpay_transaction_id', $result['id'] ?? '' );
            $order->save();

            WC()->cart->empty_cart();

            return [
                'result'   => 'success',
                'redirect' => $this->get_return_url( $order ),
            ];

        } catch ( \Throwable $e ) {
            wc_add_notice( $e->getMessage(), 'error' );
            return [ 'result' => 'failure', 'redirect' => '' ];
        }
    }

    /**
     * Procesa un reembolso.
     *
     * @param int    $order_id
     * @param float  $amount
     * @param string $reason
     * @return bool|WP_Error
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        $order  = wc_get_order( $order_id );
        $txn_id = $order ? $order->get_meta( '_openpay_transaction_id' ) : '';

        if ( ! $txn_id ) {
            return new WP_Error( 'no_txn', __( 'No se encontró la transacción Openpay para reembolsar.', 'ltms' ) );
        }

        try {
            $client = LTMS_Api_Factory::get( 'openpay' );
            $client->refund( $txn_id, (float) $amount, $reason );
            return true;
        } catch ( \Throwable $e ) {
            return new WP_Error( 'refund_failed', $e->getMessage() );
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ADDI GATEWAY
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Class LTMS_Api_Gateway_Addi
 *
 * Pasarela BNPL (Buy Now Pay Later) de Addi para Colombia.
 * Redirige al cliente al flujo de Addi y recibe confirmación por webhook.
 */
class LTMS_Api_Gateway_Addi extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'ltms_addi';
        $this->has_fields         = false;
        $this->method_title       = __( 'Addi — Compra ahora, paga después', 'ltms' );
        $this->method_description = __( 'Permite a tus clientes financiar sus compras con Addi.', 'ltms' );
        $this->supports           = [ 'products' ];

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title', __( 'Paga en cuotas con Addi', 'ltms' ) );
        $this->description = $this->get_option( 'description', __( 'Financia tu compra sin tarjeta de crédito.', 'ltms' ) );
        $this->enabled     = $this->get_option( 'enabled', 'yes' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled'       => [
                'title'   => __( 'Habilitar', 'ltms' ),
                'type'    => 'checkbox',
                'label'   => __( 'Habilitar Addi BNPL', 'ltms' ),
                'default' => 'yes',
            ],
            'title'         => [
                'title'   => __( 'Título', 'ltms' ),
                'type'    => 'text',
                'default' => __( 'Paga en cuotas con Addi', 'ltms' ),
            ],
            'description'   => [
                'title'   => __( 'Descripción', 'ltms' ),
                'type'    => 'textarea',
                'default' => __( 'Financia tu compra sin tarjeta de crédito.', 'ltms' ),
            ],
            'testmode'      => [
                'title'   => __( 'Modo sandbox', 'ltms' ),
                'type'    => 'checkbox',
                'label'   => __( 'Activar sandbox', 'ltms' ),
                'default' => 'yes',
            ],
            'client_id'     => [
                'title'   => __( 'Client ID', 'ltms' ),
                'type'    => 'text',
                'default' => '',
            ],
            'client_secret' => [
                'title'   => __( 'Client Secret', 'ltms' ),
                'type'    => 'password',
                'default' => '',
            ],
            'webhook_token' => [
                'title'       => __( 'Webhook Token', 'ltms' ),
                'type'        => 'text',
                'description' => __( 'Token para verificar los webhooks de Addi.', 'ltms' ),
                'default'     => wp_generate_password( 32, false ),
            ],
        ];
    }

    /**
     * Procesa el pago: crea la sesión de Addi y redirige al cliente.
     *
     * @param int $order_id
     * @return array
     */
    public function process_payment( $order_id ): array {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wc_add_notice( __( 'Pedido no encontrado.', 'ltms' ), 'error' );
            return [ 'result' => 'failure', 'redirect' => '' ];
        }

        try {
            if ( ! class_exists( 'LTMS_Api_Addi' ) ) {
                throw new \RuntimeException( __( 'Módulo Addi no disponible.', 'ltms' ) );
            }

            $client  = LTMS_Api_Factory::get( 'addi' );
            $session = $client->create_application( [
                'orderId'     => $order_id,
                'totalAmount' => [ 'value' => (float) $order->get_total(), 'currency' => get_woocommerce_currency() ],
                'client'      => [
                    'firstName' => $order->get_billing_first_name(),
                    'lastName'  => $order->get_billing_last_name(),
                    'email'     => $order->get_billing_email(),
                    'cellphone' => $order->get_billing_phone(),
                ],
                'redirectUrl' => $this->get_return_url( $order ),
                'webhookUrl'  => rest_url( 'ltms/v1/webhooks/addi' ),
            ] );

            // Marcar como pendiente en WooCommerce
            $order->update_status( 'pending', __( 'Esperando confirmación de Addi.', 'ltms' ) );
            $order->update_meta_data( '_ltms_addi_application_id', $session['applicationId'] ?? '' );
            $order->save();

            return [
                'result'   => 'success',
                'redirect' => $session['url'] ?? $this->get_return_url( $order ),
            ];

        } catch ( \Throwable $e ) {
            wc_add_notice( $e->getMessage(), 'error' );
            return [ 'result' => 'failure', 'redirect' => '' ];
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PSE GATEWAY (Openpay CO — débito bancario)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Class LTMS_Api_Gateway_PSE
 *
 * Pasarela PSE (Pagos Seguros en Línea) vía Openpay Colombia.
 * Redirige al cliente al banco seleccionado y recibe confirmación por webhook.
 *
 * @extends WC_Payment_Gateway
 * @version 1.0.0
 */
class LTMS_Api_Gateway_PSE extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'ltms_pse';
        $this->has_fields         = true;
        $this->method_title       = __( 'PSE — Débito bancario', 'ltms' );
        $this->method_description = __( 'Pago directo desde tu cuenta bancaria mediante PSE (Openpay).', 'ltms' );
        $this->supports           = [ 'products' ];

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title', __( 'PSE — Débito bancario', 'ltms' ) );
        $this->description = $this->get_option( 'description', __( 'Paga directamente desde tu cuenta bancaria en Colombia.', 'ltms' ) );
        $this->enabled     = $this->get_option( 'enabled', 'no' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_scripts' ] );
    }

    public function init_form_fields(): void {
        $this->form_fields = [
            'enabled' => [
                'title'   => __( 'Habilitar', 'ltms' ),
                'type'    => 'checkbox',
                'label'   => __( 'Habilitar PSE (Openpay)', 'ltms' ),
                'default' => 'no',
            ],
            'title' => [
                'title'   => __( 'Título', 'ltms' ),
                'type'    => 'text',
                'default' => __( 'PSE — Débito bancario', 'ltms' ),
            ],
            'description' => [
                'title'   => __( 'Descripción', 'ltms' ),
                'type'    => 'textarea',
                'default' => __( 'Paga directamente desde tu cuenta bancaria en Colombia.', 'ltms' ),
            ],
            'testmode' => [
                'title'   => __( 'Modo sandbox', 'ltms' ),
                'type'    => 'checkbox',
                'label'   => __( 'Activar sandbox', 'ltms' ),
                'default' => 'yes',
            ],
        ];
    }

    /**
     * Renderiza selector de banco y tipo de persona en el checkout.
     */
    public function payment_fields(): void {
        if ( $this->description ) {
            echo '<p>' . wp_kses_post( $this->description ) . '</p>';
        }

        // Obtener lista de bancos desde Openpay
        $banks = $this->get_pse_banks();
        ?>
        <fieldset id="ltms-pse-fields" style="border:0;padding:0;margin:0;">
            <p class="form-row form-row-wide">
                <label for="ltms_pse_bank"><?php esc_html_e( 'Banco', 'ltms' ); ?> <span class="required">*</span></label>
                <select id="ltms_pse_bank" name="ltms_pse_bank_code" class="input-text" style="width:100%;">
                    <option value=""><?php esc_html_e( '— Selecciona tu banco —', 'ltms' ); ?></option>
                    <?php foreach ( $banks as $bank ) : ?>
                        <option value="<?php echo esc_attr( $bank['bankCode'] ?? $bank['id'] ?? '' ); ?>">
                            <?php echo esc_html( $bank['name'] ?? $bank['bankName'] ?? '' ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
            <p class="form-row form-row-wide">
                <label for="ltms_pse_person_type"><?php esc_html_e( 'Tipo de persona', 'ltms' ); ?> <span class="required">*</span></label>
                <select id="ltms_pse_person_type" name="ltms_pse_person_type" class="input-text" style="width:100%;">
                    <option value=""><?php esc_html_e( '— Selecciona —', 'ltms' ); ?></option>
                    <option value="1"><?php esc_html_e( 'Natural', 'ltms' ); ?></option>
                    <option value="2"><?php esc_html_e( 'Jurídica', 'ltms' ); ?></option>
                </select>
            </p>
            <p class="form-row form-row-wide">
                <label for="ltms_pse_doc_type"><?php esc_html_e( 'Tipo de documento', 'ltms' ); ?> <span class="required">*</span></label>
                <select id="ltms_pse_doc_type" name="ltms_pse_doc_type" class="input-text" style="width:100%;">
                    <option value=""><?php esc_html_e( '— Selecciona —', 'ltms' ); ?></option>
                    <option value="CC"><?php esc_html_e( 'Cédula de ciudadanía', 'ltms' ); ?></option>
                    <option value="CE"><?php esc_html_e( 'Cédula de extranjería', 'ltms' ); ?></option>
                    <option value="NIT"><?php esc_html_e( 'NIT', 'ltms' ); ?></option>
                    <option value="TI"><?php esc_html_e( 'Tarjeta de identidad', 'ltms' ); ?></option>
                    <option value="PP"><?php esc_html_e( 'Pasaporte', 'ltms' ); ?></option>
                </select>
            </p>
            <p class="form-row form-row-wide">
                <label for="ltms_pse_doc_number"><?php esc_html_e( 'Número de documento', 'ltms' ); ?> <span class="required">*</span></label>
                <input
                    id="ltms_pse_doc_number"
                    type="text"
                    name="ltms_pse_doc_number"
                    class="input-text"
                    placeholder="<?php esc_attr_e( 'Ej: 123456789', 'ltms' ); ?>"
                    inputmode="numeric"
                />
            </p>
        </fieldset>
        <?php
    }

    public function validate_fields(): bool {
        // phpcs:disable WordPress.Security.NonceVerification
        $bank        = sanitize_text_field( wp_unslash( $_POST['ltms_pse_bank_code']   ?? '' ) );
        $person_type = sanitize_text_field( wp_unslash( $_POST['ltms_pse_person_type'] ?? '' ) );
        $doc_type    = sanitize_text_field( wp_unslash( $_POST['ltms_pse_doc_type']    ?? '' ) );
        $doc_number  = sanitize_text_field( wp_unslash( $_POST['ltms_pse_doc_number']  ?? '' ) );
        // phpcs:enable

        $errors = [];
        if ( empty( $bank ) )        $errors[] = __( 'Selecciona tu banco.', 'ltms' );
        if ( empty( $person_type ) ) $errors[] = __( 'Selecciona el tipo de persona.', 'ltms' );
        if ( empty( $doc_type ) )    $errors[] = __( 'Selecciona el tipo de documento.', 'ltms' );
        if ( empty( $doc_number ) )  $errors[] = __( 'Ingresa tu número de documento.', 'ltms' );

        foreach ( $errors as $err ) {
            wc_add_notice( $err, 'error' );
        }

        return empty( $errors );
    }

    /**
     * Procesa el pago PSE: crea la transacción en Openpay y redirige al banco.
     */
    public function process_payment( $order_id ): array {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wc_add_notice( __( 'Pedido no encontrado.', 'ltms' ), 'error' );
            return [ 'result' => 'failure', 'redirect' => '' ];
        }

        try {
            if ( ! class_exists( 'LTMS_Api_Openpay' ) ) {
                throw new \RuntimeException( __( 'Módulo Openpay no disponible.', 'ltms' ) );
            }

            // Sync credenciales
            $openpay_gw = new LTMS_Api_Gateway_Openpay();
            $country    = LTMS_Core_Config::get_country();
            update_option( "ltms_openpay_{$country}_merchant_id", $openpay_gw->get_option( 'merchant_id', '' ) );
            update_option( "ltms_openpay_{$country}_private_key",  $openpay_gw->get_option( 'private_key', '' ) );
            LTMS_Api_Factory::reset( 'openpay' );

            $client = LTMS_Api_Factory::get( 'openpay' );

            // phpcs:disable WordPress.Security.NonceVerification
            $bank_code   = sanitize_text_field( wp_unslash( $_POST['ltms_pse_bank_code']   ?? '' ) );
            $person_type = sanitize_text_field( wp_unslash( $_POST['ltms_pse_person_type'] ?? '' ) );
            $doc_type    = sanitize_text_field( wp_unslash( $_POST['ltms_pse_doc_type']    ?? '' ) );
            $doc_number  = sanitize_text_field( wp_unslash( $_POST['ltms_pse_doc_number']  ?? '' ) );
            // phpcs:enable

            $result = $client->create_pse_charge(
                (float) $order->get_total(),
                sprintf( __( 'Pedido #%s', 'ltms' ), $order->get_order_number() ),
                [
                    'name'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'email' => $order->get_billing_email(),
                    'city'  => $order->get_billing_city() ?: 'Bogotá',
                ],
                $bank_code,
                $this->get_return_url( $order ),
                (string) $order_id
            );

            $order->update_status( 'pending', __( 'Esperando confirmación PSE.', 'ltms' ) );
            $order->update_meta_data( '_ltms_openpay_pse_transaction_id', $result['id'] ?? '' );
            $order->update_meta_data( '_ltms_openpay_pse_redirect_url',   $result['payment_method']['url'] ?? '' );
            $order->save();

            WC()->cart->empty_cart();

            // Redirigir al portal PSE del banco
            $redirect = $result['payment_method']['url'] ?? $this->get_return_url( $order );

            return [
                'result'   => 'success',
                'redirect' => $redirect,
            ];

        } catch ( \Throwable $e ) {
            wc_add_notice( $e->getMessage(), 'error' );
            return [ 'result' => 'failure', 'redirect' => '' ];
        }
    }

    /**
     * Encola el JS de Openpay en el checkout.
     */
    public function enqueue_checkout_scripts(): void {
        if ( ! is_checkout() || $this->enabled !== 'yes' ) {
            return;
        }

        if ( ! wp_script_is( 'openpay-js', 'enqueued' ) ) {
            wp_enqueue_script( 'openpay-js',   'https://js.openpay.co/openpay.v1.min.js',      [], '1.0', true );
            wp_enqueue_script( 'openpay-data', 'https://js.openpay.co/openpay-data.v1.min.js', [ 'openpay-js' ], '1.0', true );
        }
    }

    /**
     * Obtiene la lista de bancos PSE disponibles desde Openpay.
     *
     * @return array<int, array<string, string>>
     */
    private function get_pse_banks(): array {
        $cached = get_transient( 'ltms_pse_banks' );
        if ( is_array( $cached ) && ! empty( $cached ) ) {
            return $cached;
        }

        try {
            // Leer credenciales desde las mismas opciones que usa LTMS_Api_Openpay
            $merchant_id = get_option( 'ltms_openpay_merchant_id', '' );
            $private_key = get_option( 'ltms_openpay_private_key', '' );

            if ( empty( $merchant_id ) || empty( $private_key ) ) {
                return [];
            }

            $resp = wp_remote_get(
                "https://api.openpay.co/v1/{$merchant_id}/pseBanks",
                [
                    'headers' => [ 'Authorization' => 'Basic ' . base64_encode( $private_key . ':' ) ],
                    'timeout' => 8,
                ]
            );

            if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) === 200 ) {
                $banks = json_decode( wp_remote_retrieve_body( $resp ), true );
                if ( is_array( $banks ) && ! empty( $banks ) ) {
                    set_transient( 'ltms_pse_banks', $banks, HOUR_IN_SECONDS * 6 );
                    return $banks;
                }
            }
        } catch ( \Throwable $e ) {
            // Silencioso — retornar lista vacía
        }

        return [];
    }
}
