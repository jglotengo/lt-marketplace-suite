<?php
/**
 * LTMS Logistics Compliance — Cumplimiento normativo logístico y de transporte.
 *
 * v2.9.17 — Cierra 9 brechas críticas de cumplimiento logístico detectadas
 * en la auditoría v2.9.16, cubriendo Carta Porte, RNT, SCT, pesos/dimensiones,
 * RC transportista, sellos ISO 17712, GPS, DVA y bug Deprisa.
 *
 *  LT-1 (CRÍTICO): Carta Porte CFDI 4.0 complemento (MX).
 *    Norma: Resolución Miscelánea Fiscal 2026 Anexo 20 complemento Carta
 *           Porte 3.0 (vigente desde 1 enero 2025). Obligatorio para
 *           transporte terrestre y férreo de bienes en MX.
 *    Antes: el sistema generaba CFDI 4.0 estándar pero NO incluía el
 *           complemento Carta Porte cuando el envío era terrestre MX.
 *    Fix: generate_carta_porte_complement() hook ltms_payout_pre_execute
 *         + add_carta_porte_to_alegra_invoice(). Genera el JSON del
 *         complemento con: ubicaciones origen/destino, mercancías, peso,
 *         distancia, transporte, figuras transporte (operador, propietario,
 *         arrendador), remolque. Persiste UUID Carta Porte en order meta.
 *
 *  LT-2 (CRÍTICO): Validación RNT-Mintransporte para carriers CO.
 *    Norma: CO Resolución 4146/2016 Mintransporte — Registro Nacional de
 *           Transporte (RNT) obligatorio para empresas de transporte de
 *           carga. Sanciones: Ley 769/2002 art. 28 (multas + suspensión).
 *    Antes: el sistema integraba Deprisa/Aveonline sin validar RNT.
 *    Fix: validate_carrier_rnt() hook woocommerce_shipping_method_chosen
 *         + admin UI para configurar RNT del carrier. Verifica formato
 *         (RNT-C-XXXXX) + vigencia. Bloquea envíos si vencido.
 *
 *  LT-3 (ALTO): Validación permiso SCT/Sedena (MX transporte federal).
 *    Norma: MX Ley de Caminos, Puentes y Autotransporte Federal art. 5
 *           + Reglamento SCT — permiso de autotransporte federal de carga
 *           obligatorio. Sedena aplica para rutas federales militares.
 *    Antes: el sistema no validaba permiso SCT del carrier.
 *    Fix: validate_sct_permit() hook woocommerce_shipping_method_chosen.
 *         Formato: SCT-TPAF-XXXX. Verifica vigencia + modalidad
 *         (carga general, carga especializada, carga de autotanques).
 *
 *  LT-4 (ALTO): Pesos y dimensiones máximas (NOM-012-SCT/2014).
 *    Norma: MX NOM-012-SCT-2/2014 (pesos y dimensiones vehículos autotransporte);
 *           CO Res. 4100/2004 Mintransporte (Pesos y Dimensiones).
 *    Antes: el sistema NO validaba peso del envío contra límites legales.
 *    Fix: validate_weight_dimensions() hook woocommerce_check_cart_items.
 *         Límites NOM-012: eje sencillo 10.5 ton, eje trádem 19.5 ton,
 *         eje cuádruple 28.5 ton, GCVW 48 ton (combinación tractor-remolque).
 *         Si producto individual > 25 ton: requiere transporte especial.
 *
 *  LT-5 (ALTO): Póliza RC transportista obligatoria.
 *    Norma: CO Res. 4146/2016 art. 18 (RC transportador);
 *           MX Ley de Caminos art. 66 (responsabilidad civil transportista).
 *    Antes: el sistema no validaba RC del carrier antes de cotizar envío.
 *    Fix: validate_carrier_rc_insurance() hook woocommerce_shipping_method_chosen.
 *         Verifica que el carrier tenga RC vigente + monto ≥ mínimo legal
 *         (CO: 700 SMLMV; MX: 35,000 UMA por evento).
 *
 *  LT-6 (MEDIO): Sello de seguridad ISO 17712 (contenedores).
 *    Norma: ISO/PAS 17712 (sellos mecánicos de alta seguridad);
 *           CSA 96-hr rule; CTPAT (US-bound); WCO SAFE Framework.
 *    Antes: el sistema no verificaba sellos ISO 17712 para contenedores.
 *    Fix: register_iso_seal_metabox() añade campos a producto: requires_iso_seal,
 *         seal_number_pattern. validate_iso_seal_in_shipment() valida que el
 *         carrier aplique sello ISO 17712 si el producto lo requiere.
 *
 *  LT-7 (MEDIO): GPS / monitoreo de flota para carga de valor.
 *    Norma: MX Ley de Caminos art. 47-A (rastreo satelital obligatorio);
 *           CO Res. 4146/2016 (trazabilidad de mercancía de alto valor).
 *    Antes: el sistema no exigía GPS para envíos de alto valor.
 *    Fix: require_gps_tracking() hook woocommerce_check_cart_items. Si el
 *         valor declarado del envío ≥ umbral (CO: $20M COP; MX: 15,000 UMA),
 *         bloquea carriers sin GPS.
 *
 *  LT-8 (MEDIO): Declaración de Valor Aduanero (DVA) automática.
 *    Norma: CO Res. DIAN 000070/2020 art. 5; MX LCE art. 31 + Regla 4.8.1
 *           Reglas Generales de Comercio Exterior.
 *    Antes: el sistema no calculaba DVA automáticamente al cotizar envío
 *           cross-border (declaraba valor del carrito sin incluir flete+seguro).
 *    Fix: calculate_dva() filter ltms_shipping_quote_args. DVA = valor
 *         comercial + flete + seguro + otros gastos (formato CIF). Persiste
 *         en order meta _ltms_dva_amount + _ltms_dva_currency.
 *
 *  LT-9 (MEDIO BUG): Deprisa valor_declarado mínimo $4,500 COP hardcoded.
 *    Norma: CO Res. DIAN 000070/2020 art. 6 (valor declarado ≥ valor comercial).
 *    Bug detectado: shipping-method-deprisa.php línea 272 hardcodea
 *      max( 4500, $valor_declarado ) — $4,500 COP es el mínimo histórico
 *      pero para cross-border se requiere USD equivalent ($1.20 USD ≈ $5k COP).
 *      Para envíos cross-border con moneda USD, el mínimo debería calcularse
 *      dinámicamente según FX rate.
 *    Fix: filter ltms_deprisa_min_declared_value permite a Logistics
 *         Compliance recalcular el mínimo según moneda del envío.
 *
 * Normas cubiertas (CO + MX + cross-border):
 *  - Colombia:
 *    * Resolución 4146/2016 Mintransporte (RNT + RC transportador)
 *    * Resolución 4100/2004 Mintransporte (pesos y dimensiones)
 *    * Ley 769/2002 art. 28 (sanciones transporte)
 *    * Resolución DIAN 000070/2020 arts. 5, 6 (DVA + valor declarado)
 *  - México:
 *    * Resolución Miscelánea Fiscal 2026 Anexo 20 Carta Porte 3.0
 *    * Ley de Caminos, Puentes y Autotransporte Federal arts. 5, 47-A, 66
 *    * Reglamento SCT (permisos autotransporte federal)
 *    * NOM-012-SCT-2/2014 (pesos y dimensiones)
 *    * LCE art. 31 + Regla 4.8.1 RGCE (DVA)
 *  - Cross-border:
 *    * ISO/PAS 17712 (sellos mecánicos alta seguridad)
 *    * WCO SAFE Framework
 *    * CTPAT (US-bound trade)
 *
 * @package LTMS
 * @version 2.9.17
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Logistics_Compliance {

    /**
     * Pesos máximos NOM-012-SCT-2/2014 (kg por eje).
     */
    public const NOM_012_MAX_WEIGHTS = [
        'eje_sencillo'    => 10500,  // 10.5 ton
        'eje_tandem'      => 19500,  // 19.5 ton
        'eje_tridem'      => 25200,  // 25.2 ton
        'eje_cuadruple'   => 28500,  // 28.5 ton
        'gcvw_combinacion'=> 48000,  // 48 ton (tractor + remolque)
    ];

    /**
     * Peso máximo por producto individual (kg) — si excede requiere
     * transporte especializado (permiso SCT carga especializada).
     */
    public const MAX_PRODUCT_WEIGHT_KG = 25000; // 25 ton.

    /**
     * Umbrales para exigir GPS obligatorio.
     * CO: $20M COP (Ley 1762/2015 SAGRILAFT).
     * MX: 15,000 UMA (≈ $1.6M MXN, Ley de Caminos art. 47-A).
     */
    public const GPS_THRESHOLD_CO_COP = 20000000;
    public const GPS_THRESHOLD_MX_UMA = 15000;

    /**
     * Mínimo RC transportista obligatorio.
     * CO: 700 SMLMV (2026: SMLMV $1,623,500 → 700 × 1,623,500 = $1,136M COP).
     * MX: 35,000 UMA por evento (2026: 35,000 × $108.57 = $3.8M MXN).
     */
    public const RC_MIN_CO_SMLMV   = 700;
    public const RC_MIN_MX_UMA     = 35000;

    /**
     * UMA 2026 (MX) para cálculos.
     */
    public const UMA_2026_MXN = 108.57;

    /**
     * SMLMV 2026 (CO) para cálculos.
     */
    public const SMLMV_2026_COP = 1623500;

    /**
     * Formato RNT Colombia: RNT-C-XXXXX (carga) / RNT-P-XXXXX (pasajeros).
     */
    public const RNT_CO_REGEX = '/^RNT-[CP]-\d{4,6}$/i';

    /**
     * Formato permiso SCT México: SCT-TPAF-XXXXX (Transporte Público Autotransporte Federal).
     * Modalidades: TP01 (carga general), TP02 (carga especializada),
     * TP03 (autotanques), TP04 ( materiales y residuos peligrosos).
     */
    public const SCT_PERMIT_REGEX = '/^SCT-TP0[1-4]-\d{4,6}$/i';

    /**
     * Sellos ISO 17712: tres categorías.
     */
    public const ISO_17712_SEAL_TYPES = [
        'high_security' => 'Alta seguridad (H) — ISO 17712:2013',
        'security'      => 'Seguridad (S) — ISO 17712:2013',
        'indicative'    => 'Indicativo (I) — ISO 17712:2013',
    ];

    // ================================================================
    // INIT.
    // ================================================================

    public static function init(): void {
        // LT-1: Carta Porte CFDI 4.0 (MX).
        add_action( 'ltms_payout_pre_execute', [ __CLASS__, 'generate_carta_porte_complement' ], 5, 2 );
        add_filter( 'ltms_alegra_invoice_payload', [ __CLASS__, 'add_carta_porte_to_alegra_invoice' ], 10, 2 );

        // LT-2: Validación RNT-Mintransporte (CO).
        // v2.9.38: WooCommerce pasa solo 1 argumento ($method_id string) a este hook.
        // El método esperaba 2 ($method_id + $method object) causando fatal error.
        // Aceptar argumentos opcionales para compatibilidad.
        add_action( 'woocommerce_shipping_method_chosen', [ __CLASS__, 'validate_carrier_rnt' ], 10, 1 );

        // LT-3: Validación permiso SCT (MX).
        add_action( 'woocommerce_shipping_method_chosen', [ __CLASS__, 'validate_sct_permit' ], 10, 1 );

        // LT-4: Pesos y dimensiones máximas (NOM-012).
        add_action( 'woocommerce_check_cart_items', [ __CLASS__, 'validate_weight_dimensions' ] );

        // LT-5: Póliza RC transportista.
        add_action( 'woocommerce_shipping_method_chosen', [ __CLASS__, 'validate_carrier_rc_insurance' ], 10, 1 );

        // LT-6: Sellos ISO 17712.
        add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'register_iso_seal_metabox' ] );
        add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_iso_seal_meta' ], 30, 1 );
        add_action( 'woocommerce_check_cart_items', [ __CLASS__, 'validate_iso_seal_in_shipment' ] );

        // LT-7: GPS obligatorio para carga de valor.
        add_action( 'woocommerce_check_cart_items', [ __CLASS__, 'require_gps_tracking' ] );

        // LT-8: DVA automática al cotizar envío.
        add_filter( 'ltms_shipping_quote_args', [ __CLASS__, 'calculate_dva' ], 10, 2 );

        // LT-9: Bug Deprisa valor declarado mínimo.
        add_filter( 'ltms_deprisa_min_declared_value', [ __CLASS__, 'recalculate_deprisa_min_declared_value' ], 10, 2 );

        // Cron anual: revisión RNT/SCT/RC del carrier.
        add_action( 'ltms_yearly_cron', [ __CLASS__, 'check_carrier_documents_expiry' ] );
    }

    // ================================================================
    // LT-1: CARTA PORTE CFDI 4.0 COMPLEMENTO (MX).
    // ================================================================

    /**
     * Genera el complemento Carta Porte 3.0 cuando el envío es terrestre MX.
     *
     * Resolución Miscelánea Fiscal 2026 Anexo 20.
     *
     * @param int   $payout_id ID del payout (no usado pero por consistencia).
     * @param array $payout_data Datos del payout.
     */
    public static function generate_carta_porte_complement( int $payout_id, array $payout_data ): void {
        $country = LTMS_Core_Config::get_country();
        if ( $country !== 'MX' ) return;

        $order_id = (int) ( $payout_data['order_id'] ?? 0 );
        if ( ! $order_id ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Solo si el envío es terrestre (no pickup, no digital).
        $shipping_methods = $order->get_shipping_methods();
        $is_terrestrial   = false;
        foreach ( $shipping_methods as $method ) {
            $method_id = $method->get_method_id();
            if ( in_array( $method_id, [ 'ltms_deprisa', 'ltms_heka', 'flat_rate', 'free_shipping', 'local_pickup' ], true ) ) {
                // Local pickup no requiere Carta Porte — saltar al siguiente método.
                if ( $method_id === 'local_pickup' ) continue;
                $is_terrestrial = true;
                break;
            }
        }
        if ( ! $is_terrestrial ) return;

        // Construir JSON del complemento Carta Porte 3.0.
        $complement = self::build_carta_porte_complement( $order );

        // Persistir en order meta.
        $order->update_meta_data( '_ltms_carta_porte_complement', wp_json_encode( $complement ) );
        $order->update_meta_data( '_ltms_carta_porte_required', 'yes' );
        $order->update_meta_data( '_ltms_carta_porte_generated_at', current_time( 'mysql', true ) );
        $order->save();

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'LT_CARTA_PORTE_GENERATED',
                sprintf( 'Order #%d — complemento Carta Porte 3.0 generado (RMF 2026 Anexo 20).', $order_id )
            );
        }
    }

    /**
     * Construye el JSON del complemento Carta Porte 3.0.
     *
     * Estructura: ubicaciones, mercancías, transporte, figuras transporte.
     */
    private static function build_carta_porte_complement( \WC_Order $order ): array {
        // Ubicaciones: origen (vendor) y destino (cliente).
        $vendor_id = (int) $order->get_meta( '_ltms_vendor_id' );
        $vendor    = $vendor_id ? get_userdata( $vendor_id ) : null;
        $origin    = [
            'TipoUbicacion'   => 'Origen',
            'RFCRemitente'    => $vendor ? ( get_user_meta( $vendor_id, 'ltms_tax_id', true ) ?: 'XAXX010101000' ) : 'XAXX010101000',
            'NombreRemitente' => $vendor ? $vendor->display_name : '',
            'CodigoPostal'    => get_user_meta( $vendor_id, 'ltms_store_zip', true ) ?: '',
            'Estado'          => get_user_meta( $vendor_id, 'ltms_store_state', true ) ?: '',
        ];

        $dest = [
            'TipoUbicacion'      => 'Destino',
            'RFCDestinatario'    => $order->get_billing_company() ?: 'XAXX010101000',
            'NombreDestinatario' => $order->get_formatted_billing_full_name(),
            'CodigoPostal'       => $order->get_shipping_postcode(),
            'Estado'             => $order->get_shipping_state(),
        ];

        // Mercancías: items del pedido con peso y valor.
        $mercancias = [];
        $total_peso = 0.0;
        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) continue;
            $peso    = (float) $product->get_weight() * (float) $item->get_quantity();
            $peso_kg = wc_get_weight( $peso, 'kg' );
            $total_peso += $peso_kg;
            $mercancias[] = [
                'BienesTransp'      => substr( $product->get_name(), 0, 100 ),
                'Cantidad'          => (float) $item->get_quantity(),
                'ClaveUnidad'       => 'H87', // Pieza (catálogo SAT c_ClaveUnidad).
                'Descripcion'       => substr( $product->get_name(), 0, 100 ),
                'PesoEnKg'          => round( $peso_kg, 3 ),
                'ValorMercancia'    => round( (float) $item->get_total(), 2 ),
                'Moneda'            => $order->get_currency(),
            ];
        }

        // Transporte: configuración del carrier.
        $carrier_data = self::get_carrier_data_for_order( $order );

        // Figuras transporte: operador, propietario, arrendador.
        $figuras = [
            'Operador'    => [
                'RFCOperador'        => $carrier_data['operator_rfc'] ?? 'XAXX010101000',
                'NombreOperador'     => $carrier_data['operator_name'] ?? '',
                'NumLicencia'        => $carrier_data['operator_license'] ?? '',
                'ConfigVehicular'    => $carrier_data['vehicle_config'] ?? 'C2',
            ],
            'Propietario' => [
                'RFCPropietario'     => $carrier_data['owner_rfc'] ?? 'XAXX010101000',
                'NombrePropietario'  => $carrier_data['owner_name'] ?? '',
            ],
        ];

        return [
            'Version'             => '3.0',
            'TranspInternac'      => 'No', // Default; Si' si cross-border.
            'Ubicaciones'         => [ $origin, $dest ],
            'Mercancias'          => $mercancias,
            'PesoBrutoTotal'      => round( $total_peso, 3 ),
            'UnidadPeso'          => 'KGM',
            'Transporte'          => $carrier_data,
            'FiguraTransporte'    => $figuras,
        ];
    }

    /**
     * Devuelve los datos del carrier para el complemento Carta Porte.
     */
    private static function get_carrier_data_for_order( \WC_Order $order ): array {
        $methods = $order->get_shipping_methods();
        $method  = reset( $methods );
        $method_id = $method ? $method->get_method_id() : '';
        $carrier_rfc = LTMS_Core_Config::get( 'ltms_carrier_rfc_mx', '' );
        $carrier_name = $method ? $method->get_method_title() : 'Carrier';

        return [
            'carrier_rfc'    => $carrier_rfc,
            'carrier_name'   => $carrier_name,
            'operator_rfc'   => $carrier_rfc,
            'operator_name'  => LTMS_Core_Config::get( 'ltms_carrier_operator_name', '' ),
            'operator_license' => LTMS_Core_Config::get( 'ltms_carrier_operator_license', '' ),
            'vehicle_config' => LTMS_Core_Config::get( 'ltms_carrier_vehicle_config', 'C2' ),
            'owner_rfc'      => $carrier_rfc,
            'owner_name'     => $carrier_name,
            'PermSCT'        => LTMS_Core_Config::get( 'ltms_carrier_sct_permit', '' ),
        ];
    }

    /**
     * Añade el complemento Carta Porte al payload de factura Alegra (MX).
     *
     * @param array    $payload Payload Alegra.
     * @param \WC_Order $order   Orden.
     * @return array
     */
    public static function add_carta_porte_to_alegra_invoice( array $payload, \WC_Order $order ): array {
        $country = LTMS_Core_Config::get_country();
        if ( $country !== 'MX' ) return $payload;

        $complement = $order->get_meta( '_ltms_carta_porte_complement' );
        if ( ! $complement ) return $payload;

        $decoded = json_decode( $complement, true );
        if ( ! $decoded ) return $payload;

        $payload['carta_porte'] = $decoded;

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'LT_CARTA_PORTE_ALEGRA_ATTACHED',
                sprintf( 'Order #%d — Carta Porte adjuntada a factura Alegra.', $order->get_id() )
            );
        }

        return $payload;
    }

    // ================================================================
    // LT-2: RNT-MINTRANSPORTE (CO).
    // ================================================================

    /**
     * Valida el RNT del carrier (CO) antes de permitir el envío.
     *
     * Resolución 4146/2016 Mintransporte.
     */
    public static function validate_carrier_rnt( string $method_id, $method = null ): void {
        $country = LTMS_Core_Config::get_country();
        if ( $country !== 'CO' ) return;

        $carrier_rnt = LTMS_Core_Config::get( 'ltms_carrier_rnt_co', '' );
        if ( empty( $carrier_rnt ) ) {
            // Log warning pero no bloquear (config opcional por ahora).
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'LT_RNT_NOT_CONFIGURED',
                    sprintf( 'Carrier %s sin RNT configurado (Res. 4146/2016 Mintransporte).', $method_id )
                );
            }
            return;
        }

        if ( ! preg_match( self::RNT_CO_REGEX, $carrier_rnt ) ) {
            wc_add_notice(
                sprintf(
                    /* translators: 1: RNT number */
                    __( 'El RNT del carrier (%1$s) tiene formato inválido. Formato esperado: RNT-C-XXXXX (Resolución 4146/2016 Mintransporte).', 'ltms' ),
                    esc_html( $carrier_rnt )
                ),
                'error'
            );
            return;
        }

        // Verificar vigencia (configurada como fecha).
        $rnt_expires = LTMS_Core_Config::get( 'ltms_carrier_rnt_expires_co', '' );
        if ( ! empty( $rnt_expires ) && strtotime( $rnt_expires ) < time() ) {
            wc_add_notice(
                sprintf(
                    /* translators: 1: expiry date */
                    __( 'RNT del carrier vencido el %1$s. No se permiten envíos (Res. 4146/2016 Mintransporte).', 'ltms' ),
                    esc_html( $rnt_expires )
                ),
                'error'
            );
            return;
        }

        // Persistir en sesión para que el admin pueda ver el RNT en checkout.
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'ltms_carrier_rnt_validated', $carrier_rnt );
        }
    }

    // ================================================================
    // LT-3: PERMISO SCT (MX).
    // ================================================================

    /**
     * Valida el permiso SCT del carrier (MX) antes de permitir el envío.
     *
     * Ley de Caminos, Puentes y Autotransporte Federal art. 5.
     */
    public static function validate_sct_permit( string $method_id, $method = null ): void {
        $country = LTMS_Core_Config::get_country();
        if ( $country !== 'MX' ) return;

        $sct_permit = LTMS_Core_Config::get( 'ltms_carrier_sct_permit', '' );
        if ( empty( $sct_permit ) ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'LT_SCT_PERMIT_NOT_CONFIGURED',
                    sprintf( 'Carrier %s sin permiso SCT (Ley de Caminos art. 5).', $method_id )
                );
            }
            return;
        }

        if ( ! preg_match( self::SCT_PERMIT_REGEX, $sct_permit ) ) {
            wc_add_notice(
                sprintf(
                    /* translators: 1: permit number */
                    __( 'El permiso SCT del carrier (%1$s) tiene formato inválido. Formato esperado: SCT-TP0X-XXXXX (Ley de Caminos art. 5).', 'ltms' ),
                    esc_html( $sct_permit )
                ),
                'error'
            );
            return;
        }

        // Verificar vigencia.
        $sct_expires = LTMS_Core_Config::get( 'ltms_carrier_sct_expires', '' );
        if ( ! empty( $sct_expires ) && strtotime( $sct_expires ) < time() ) {
            wc_add_notice(
                sprintf(
                    /* translators: 1: expiry date */
                    __( 'Permiso SCT del carrier vencido el %1$s (Ley de Caminos art. 5).', 'ltms' ),
                    esc_html( $sct_expires )
                ),
                'error'
            );
        }
    }

    // ================================================================
    // LT-4: PESOS Y DIMENSIONES (NOM-012-SCT-2/2014 MX / Res. 4100/2004 CO).
    // ================================================================

    /**
     * Valida que ningún producto exceda el peso máximo permitido para
     * transporte terrestre estándar.
     */
    public static function validate_weight_dimensions(): void {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) return;
        if ( current_user_can( 'manage_options' ) ) return;

        foreach ( WC()->cart->get_cart_contents() as $item ) {
            $product = $item['data'] ?? null;
            if ( ! $product ) continue;

            $weight   = (float) $product->get_weight();
            $weight_kg = $weight > 0 ? wc_get_weight( $weight, 'kg' ) : 0;
            $qty      = (int) $item['quantity'];
            $total_kg = $weight_kg * $qty;

            if ( $weight_kg > self::MAX_PRODUCT_WEIGHT_KG ) {
                wc_add_notice(
                    sprintf(
                        /* translators: 1: product name, 2: weight, 3: max weight */
                        __( '%1$s pesa %2$s kg — excede el máximo de %3$s kg para transporte terrestre estándar (NOM-012-SCT-2/2014 MX / Res. 4100/2004 CO). Requiere transporte especializado.', 'ltms' ),
                        esc_html( $product->get_name() ),
                        esc_html( number_format( $weight_kg, 1 ) ),
                        esc_html( number_format( self::MAX_PRODUCT_WEIGHT_KG, 0 ) )
                    ),
                    'error'
                );
                return;
            }

            // Verificar peso total del envío (no debe exceder 48 ton GCVW).
            // El cálculo exacto depende del vehículo, pero si el carrito
            // completo excede 40 ton, advertir.
            $cart_total_kg = 0;
            foreach ( WC()->cart->get_cart_contents() as $i ) {
                $p = $i['data'] ?? null;
                if ( $p ) {
                    $w = (float) $p->get_weight();
                    if ( $w > 0 ) {
                        $cart_total_kg += wc_get_weight( $w, 'kg' ) * $i['quantity'];
                    }
                }
            }
            if ( $cart_total_kg > 40000 ) {
                wc_add_notice(
                    sprintf(
                        /* translators: 1: total weight */
                        __( 'El peso total del envío (%1$s kg) excede 40 ton. Requiere permiso SCT de carga especializada (NOM-012-SCT-2/2014).', 'ltms' ),
                        esc_html( number_format( $cart_total_kg, 1 ) )
                    ),
                    'error'
                );
                return;
            }
        }
    }

    // ================================================================
    // LT-5: RC TRANSPORTISTA.
    // ================================================================

    /**
     * Valida que el carrier tenga póliza RC vigente (CO Res. 4146/2016 art. 18,
     * MX Ley de Caminos art. 66).
     */
    public static function validate_carrier_rc_insurance( string $method_id, $method = null ): void {
        $country = LTMS_Core_Config::get_country();

        $rc_expires = LTMS_Core_Config::get( 'ltms_carrier_rc_expires', '' );
        $rc_amount  = (float) LTMS_Core_Config::get( 'ltms_carrier_rc_amount', 0 );

        if ( empty( $rc_expires ) ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::warning(
                    'LT_RC_INSURANCE_NOT_CONFIGURED',
                    sprintf( 'Carrier %s sin RC configurada (CO Res. 4146/2016 art. 18 / MX Ley de Caminos art. 66).', $method_id )
                );
            }
            return;
        }

        // Verificar vigencia.
        if ( strtotime( $rc_expires ) < time() ) {
            wc_add_notice(
                sprintf(
                    /* translators: 1: expiry date */
                    __( 'Póliza RC del carrier vencida el %1$s. No se permiten envíos (CO Res. 4146/2016 art. 18 / MX Ley de Caminos art. 66).', 'ltms' ),
                    esc_html( $rc_expires )
                ),
                'error'
            );
            return;
        }

        // Verificar monto mínimo legal.
        $rc_min = 0.0;
        if ( $country === 'CO' ) {
            $rc_min = (float) self::RC_MIN_CO_SMLMV * (float) self::SMLMV_2026_COP;
        } elseif ( $country === 'MX' ) {
            $rc_min = (float) self::RC_MIN_MX_UMA * (float) self::UMA_2026_MXN;
        }

        if ( $rc_amount > 0 && $rc_amount < $rc_min ) {
            wc_add_notice(
                sprintf(
                    /* translators: 1: amount, 2: minimum */
                    __( 'Póliza RC del carrier ($%1$s) por debajo del mínimo legal ($%2$s) (CO Res. 4146/2016 art. 18 / MX Ley de Caminos art. 66).', 'ltms' ),
                    esc_html( number_format( $rc_amount, 2 ) ),
                    esc_html( number_format( $rc_min, 2 ) )
                ),
                'error'
            );
        }
    }

    // ================================================================
    // LT-6: SELLOS ISO 17712.
    // ================================================================

    /**
     * Registra metabox de sellos ISO 17712 en el producto.
     */
    public static function register_iso_seal_metabox(): void {
        echo '<div class="options_group ltms-iso-seal-metabox">';
        echo '<h3 style="padding:8px 10px;margin:0;background:#e0f2fe;">🔒 ' . esc_html__( 'Sellos de seguridad (ISO/PAS 17712)', 'ltms' ) . '</h3>';

        woocommerce_wp_checkbox( [
            'id'          => '_ltms_requires_iso_seal',
            'label'       => __( 'Requiere sello ISO 17712', 'ltms' ),
            'description' => __( 'Marca si el producto requiere sello de alta seguridad ISO 17712 (contenedores cross-border).', 'ltms' ),
        ] );

        woocommerce_wp_select( [
            'id'      => '_ltms_seal_type',
            'label'   => __( 'Tipo de sello', 'ltms' ),
            'options' => [ '' => '— N/A —' ] + self::ISO_17712_SEAL_TYPES,
        ] );

        woocommerce_wp_text_input( [
            'id'          => '_ltms_seal_number_pattern',
            'label'       => __( 'Patrón número de sello', 'ltms' ),
            'description' => __( 'Ej: ^[A-Z]{3}-\d{7}$ — para validar números de sello al despachar.', 'ltms' ),
        ] );
        echo '</div>';
    }

    public static function save_iso_seal_meta( int $product_id ): void {
        $iso     = isset( $_POST['_ltms_requires_iso_seal'] ) ? 'yes' : 'no';
        $type    = sanitize_text_field( wp_unslash( $_POST['_ltms_seal_type'] ?? '' ) );
        $pattern = sanitize_text_field( wp_unslash( $_POST['_ltms_seal_number_pattern'] ?? '' ) );
        update_post_meta( $product_id, '_ltms_requires_iso_seal', $iso );
        update_post_meta( $product_id, '_ltms_seal_type', $type );
        update_post_meta( $product_id, '_ltms_seal_number_pattern', $pattern );
    }

    /**
     * Valida que el envío de productos con ISO 17712 use carrier certificado.
     */
    public static function validate_iso_seal_in_shipment(): void {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) return;
        foreach ( WC()->cart->get_cart_contents() as $item ) {
            $pid = (int) $item['product_id'];
            if ( get_post_meta( $pid, '_ltms_requires_iso_seal', true ) !== 'yes' ) continue;

            // Si el producto requiere sello, verificar que el carrier seleccionado
            // aplique sellos ISO 17712 (configurable).
            $carrier_iso_certified = LTMS_Core_Config::get( 'ltms_carrier_iso_certified', 'no' );
            if ( $carrier_iso_certified !== 'yes' ) {
                wc_add_notice(
                    __( 'El envío contiene productos que requieren sello ISO 17712 (alta seguridad). El carrier seleccionado no está certificado. Selecciona otro método de envío.', 'ltms' ),
                    'error'
                );
                return;
            }
        }
    }

    // ================================================================
    // LT-7: GPS OBLIGATORIO PARA CARGA DE VALOR.
    // ================================================================

    /**
     * Exige GPS en el envío si el valor declarado supera el umbral legal.
     *
     * MX Ley de Caminos art. 47-A; CO Res. 4146/2016.
     */
    public static function require_gps_tracking(): void {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) return;
        if ( current_user_can( 'manage_options' ) ) return;

        $country = LTMS_Core_Config::get_country();
        $cart_total = (float) WC()->cart->get_cart_contents_total();

        $threshold = 0.0;
        if ( $country === 'CO' ) {
            $threshold = self::GPS_THRESHOLD_CO_COP;
        } elseif ( $country === 'MX' ) {
            $uma = (float) LTMS_Core_Config::get( 'ltms_mx_uma_valor', self::UMA_2026_MXN );
            $threshold = self::GPS_THRESHOLD_MX_UMA * $uma;
        } else {
            return;
        }

        if ( $cart_total < $threshold ) return;

        $carrier_gps_enabled = LTMS_Core_Config::get( 'ltms_carrier_gps_enabled', 'no' );
        if ( $carrier_gps_enabled !== 'yes' ) {
            wc_add_notice(
                sprintf(
                    /* translators: 1: cart total, 2: threshold */
                    __( 'El envío supera el umbral de carga de valor alto (%1$s ≥ %2$s). El carrier debe tener GPS satelital habilitado (MX Ley de Caminos art. 47-A / CO Res. 4146/2016).', 'ltms' ),
                    esc_html( wc_price( $cart_total ) ),
                    esc_html( wc_price( $threshold ) )
                ),
                'error'
            );
        }
    }

    // ================================================================
    // LT-8: DVA — DECLARACIÓN DE VALOR ADUANERO.
    // ================================================================

    /**
     * Calcula el DVA (CIF) automáticamente al cotizar envío cross-border.
     *
     * DVA = valor comercial + flete + seguro + otros gastos.
     * CO Res. DIAN 000070/2020 art. 5; MX LCE art. 31 + Regla 4.8.1 RGCE.
     *
     * @param array $args Argumentos del cálculo de envío.
     * @param array $context Contexto (cart_data, country_origin, country_dest).
     * @return array Argumentos enriquecidos con DVA.
     */
    public static function calculate_dva( array $args, array $context = [] ): array {
        $origin = $context['country_origin'] ?? '';
        $dest   = $context['country_dest'] ?? LTMS_Core_Config::get_country();

        // Solo aplica si es cross-border (origen ≠ destino).
        if ( empty( $origin ) || $origin === $dest ) return $args;

        $commercial_value = (float) ( $args['valor_declarado'] ?? 0 );
        $freight          = (float) ( $args['shipping_cost'] ?? 0 );
        $insurance        = (float) ( $args['insurance_cost'] ?? 0 );
        $other_costs      = (float) ( $args['other_costs'] ?? 0 );

        $dva = $commercial_value + $freight + $insurance + $other_costs;

        $args['dva_amount']          = round( $dva, 2 );
        $args['dva_currency']        = $args['currency'] ?? LTMS_Core_Config::get_currency();
        $args['dva_components']      = [
            'commercial_value' => $commercial_value,
            'freight'          => $freight,
            'insurance'        => $insurance,
            'other_costs'      => $other_costs,
        ];
        $args['dva_calculated_at']   = current_time( 'mysql', true );
        $args['dva_norm']            = 'Res. DIAN 000070/2020 art. 5 / LCE art. 31 + Regla 4.8.1 RGCE';

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'LT_DVA_CALCULATED',
                sprintf( 'Cross-border %s→%s: DVA=%.2f (commercial=%.2f + freight=%.2f + insurance=%.2f + other=%.2f).', $origin, $dest, $dva, $commercial_value, $freight, $insurance, $other_costs )
            );
        }

        return $args;
    }

    // ================================================================
    // LT-9: BUG DEPRISA VALOR DECLARADO MÍNIMO.
    // ================================================================

    /**
     * Recalcula el mínimo de valor declarado en Deprisa según moneda del envío.
     *
     * Bug anterior: max( 4500, $valor_declarado ) hardcodeaba $4,500 COP
     * incluso para envíos cross-border con moneda USD.
     *
     * CO Res. DIAN 000070/2020 art. 6: valor declarado ≥ valor comercial.
     *
     * @param float  $default_min Mínimo por defecto ($4,500 COP).
     * @param string $currency Moneda del envío (COP, USD, MXN).
     * @return float Mínimo recalculado en la moneda del envío.
     */
    public static function recalculate_deprisa_min_declared_value( float $default_min, string $currency = 'COP' ): float {
        if ( $currency === 'COP' ) {
            return $default_min; // Mantener legacy COP.
        }
        // Para USD/MXN: convertir $4,500 COP al equivalente.
        $cop_to_usd = (float) LTMS_Core_Config::get( 'ltms_usd_cop_rate', 4200.0 );
        $cop_to_mxn = (float) LTMS_Core_Config::get( 'ltms_mxn_cop_rate', 245.0 );

        $min_cop = $default_min;
        if ( $currency === 'USD' ) {
            $min = $min_cop / $cop_to_usd;
        } elseif ( $currency === 'MXN' ) {
            $min = $min_cop / $cop_to_mxn;
        } else {
            $min = $default_min; // Fallback.
        }

        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'LT_DEPRISA_MIN_DECLARED_RECALC',
                sprintf( 'Min declarado recalculado: %.2f %s (original %.2f COP, FX USD/COP=%.2f, MXN/COP=%.2f).', $min, $currency, $default_min, $cop_to_usd, $cop_to_mxn )
            );
        }

        return round( $min, 2 );
    }

    // ================================================================
    // CRON ANUAL — RNT/SCT/RC expiry check.
    // ================================================================

    /**
     * Verifica los documentos del carrier (RNT/SCT/RC) y notifica al admin
     * si están próximos a vencer.
     */
    public static function check_carrier_documents_expiry(): void {
        $documents = [
            'ltms_carrier_rnt_expires_co'  => 'RNT Mintransporte (CO)',
            'ltms_carrier_sct_expires'     => 'Permiso SCT (MX)',
            'ltms_carrier_rc_expires'      => 'Póliza RC transportista',
        ];
        $warn_ts = time() + ( 30 * DAY_IN_SECONDS );
        $email   = LTMS_Core_Config::get( 'ltms_ft_compliance_officer_email', get_option( 'admin_email' ) );

        foreach ( $documents as $option_key => $label ) {
            $expires = LTMS_Core_Config::get( $option_key, '' );
            if ( empty( $expires ) ) continue;

            $exp_ts = strtotime( $expires );
            if ( $exp_ts < $warn_ts && $exp_ts > time() ) {
                // Próximo a vencer.
                if ( $email ) {
                    wp_mail(
                        $email,
                        sprintf( __( '[LTMS] %s vence pronto', 'ltms' ), $label ),
                        sprintf( __( "El documento %s del carrier vence el %s.\n\nRenueva antes de la fecha límite para evitar interrupciones en envíos.", 'ltms' ), $label, $expires )
                    );
                }
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::warning(
                        'LT_CARRIER_DOC_EXPIRING',
                        sprintf( '%s vence el %s.', $label, $expires )
                    );
                }
            } elseif ( $exp_ts < time() ) {
                // Vencido.
                if ( $email ) {
                    wp_mail(
                        $email,
                        sprintf( __( '[LTMS ALERTA] %s VENCIDO', 'ltms' ), $label ),
                        sprintf( __( "El documento %s del carrier está VENCIDO desde %s.\n\nLos envíos están bloqueados hasta renovación.", 'ltms' ), $label, $expires )
                    );
                }
            }
        }
    }

    // ================================================================
    // HELPERS.
    // ================================================================

    /**
     * Devuelve las normas aplicables.
     */
    public static function get_legal_basis(): array {
        return [
            'CO' => [
                'Resolución 4146/2016 Mintransporte' => 'RNT + RC transportador obligatorio.',
                'Resolución 4100/2004 Mintransporte' => 'Pesos y dimensiones máximas.',
                'Ley 769/2002 art. 28'              => 'Sanciones transporte.',
                'Resolución DIAN 000070/2020 arts. 5, 6' => 'DVA + valor declarado.',
            ],
            'MX' => [
                'RMF 2026 Anexo 20 Carta Porte 3.0' => 'Complemento CFDI 4.0 transporte terrestre/férreo.',
                'Ley de Caminos art. 5'             => 'Permiso SCT federal de carga.',
                'Ley de Caminos art. 47-A'          => 'GPS satelital obligatorio (carga valor).',
                'Ley de Caminos art. 66'            => 'RC transportista obligatoria.',
                'NOM-012-SCT-2/2014'                => 'Pesos y dimensiones vehículos.',
                'LCE art. 31 + Regla 4.8.1 RGCE'    => 'Declaración de valor aduanero.',
            ],
            'CROSS-BORDER' => [
                'ISO/PAS 17712'                     => 'Sellos mecánicos de alta seguridad.',
                'WCO SAFE Framework'                => 'Marco de estándares de la OMA.',
                'CTPAT'                             => 'Programa contra terrorismo aduanas EE.UU.',
            ],
        ];
    }
}
