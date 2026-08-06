<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class LTMS_XCover_Policy_Listener
 * Creates and cancels XCover insurance policies on order events.
 */
class LTMS_XCover_Policy_Listener {

    use LTMS_Logger_Aware;

    public static function init(): void {
        add_action( 'woocommerce_payment_complete', [ __CLASS__, 'on_order_paid' ], 20 );
        add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'on_order_paid' ], 20 );
        add_action( 'woocommerce_order_status_cancelled', [ __CLASS__, 'on_order_cancelled' ], 10 );
        add_action( 'woocommerce_order_status_refunded', [ __CLASS__, 'on_order_cancelled' ], 10 );
        // v2.9.179: Register handler for ltms_xcover_file_claim — previously
        // the do_action was fired by ConsumerProtection::maybe_trigger_insurance_claim
        // but no listener was registered, so claims were never filed automatically.
        // CICLO19-P0-XP-046 FIX: accepted_args cambia de 3 a 4 — el caller pasa
        // ($policy_id, $dispute_id, $order_id, $reason) y la firma del listener
        // debe reflejar ese contrato (ver maybe_trigger_insurance_claim linea 1473).
        // Antes (3 args), PHP mapeaba $policy_id (string "pol_xxx") al primer
        // param int $dispute_id — TypeError fatal en disputas damaged/lost.
        add_action( 'ltms_xcover_file_claim', [ __CLASS__, 'on_file_claim' ], 10, 4 );
    }

    /**
     * Files an XCover insurance claim when a dispute is approved.
     *
     * Hooked to: ltms_xcover_file_claim (4 args)
     * Fired by: LTMS_Business_Consumer_Protection::maybe_trigger_insurance_claim()
     *
     * CICLO19-P0-XP-046 FIX: signature alineada al contrato del caller. Antes
     * aceptaba ($dispute_id, $order_id, $reason) (3 args) pero do_action pasa
     * ($policy_id, $dispute_id, $order_id, $reason) — el string policy_id se
     * casteaba a int $dispute_id y moria con TypeError.
     *
     * CICLO19-P0-XP-047 FIX: policy_id llega del caller (vacio si la orden no
     * tiene poliza), NO se re-busca con meta key mismatch (_ltms_xcover_policy_id
     * vs _ltms_insurance_policy_id). El caller ya valido la existencia y pasa
     * un string no-vacio. Si llega vacio, bail silencioso.
     *
     * @param string $policy_id XCover policy ID (vacio si caller no encontro poliza).
     * @param int    $dispute_id ID de la disputa recien creada.
     * @param int    $order_id   WooCommerce order ID.
     * @param string $reason     Motivo de la disputa (damaged|lost|...).
     * @return void
     */
    public static function on_file_claim( string $policy_id, int $dispute_id, int $order_id, string $reason ): void {
        // CICLO19-P0-XP-047 FIX: el caller (maybe_trigger_insurance_claim)
        // lee el meta _ltms_xcover_policy_id y SOLO dispara el action si
        // existe. Si llega string vacio es porque el caller lo permite pero
        // la poliza no existe — bail silencioso.
        if ( ! $policy_id ) return;

        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Idempotency: don't file claim twice for the same dispute.
        $existing_claim = $order->get_meta( '_ltms_xcover_claim_filed_' . $dispute_id );
        if ( $existing_claim ) return;

        try {
            $xcover = LTMS_Api_Factory::get( 'xcover' );

            // CICLO19-P1-XP-053 FIX: idempotency_key determinista para
            // file_claim() — xCover dedupe server-side si 5xx retry dispara
            // el handler 2da vez. Antes no habia key, podia resultar en
            // double-claim en retries.
            $idem_key = 'ltms_claim_dispute_' . $dispute_id . '_order_' . $order_id;

            // Build claim data from order + dispute info.
            $claim_data = [
                'policy_id'      => $policy_id,
                'reason'         => $reason,
                'description'    => sprintf(
                    'Dispute #%d filed by customer. Reason: %s. Order #%d.',
                    $dispute_id,
                    $reason,
                    $order_id
                ),
                'incident_date'  => current_time( 'mysql', true ),
                'amount'         => (float) $order->get_total(),
                'currency'       => $order->get_currency(),
                // CICLO19-P1-XP-053 FIX: propagar idempotency_key al API
                // client (LTMS_Api_XCover::file_claim debe leerlo).
                'idempotency_key' => $idem_key,
            ];

            // Attempt to file the claim via the XCover API.
            // CICLO19-P1-XP-049 FIX: si file_claim() falta en el API client,
            // logear warning pero NO marcar el meta de idempotency —
            // proxima disputa reintentara en cuanto se implemente el metodo
            // (evita falsear el "already filed" en un feature incompleto).
            if ( method_exists( $xcover, 'file_claim' ) ) {
                $result   = $xcover->file_claim( $claim_data );
                $claim_id = $result['claim_id'] ?? $result['id'] ?? '';

                $order->update_meta_data( '_ltms_xcover_claim_filed_' . $dispute_id, $claim_id );
                $order->update_meta_data( '_ltms_xcover_claim_id', $claim_id );

                // CICLO19-P1-XP-054 FIX: mostrar contexto full (dispute_id +
                // order_id + policy_id + claim_id) para diagnostico traceability.
                // CICLO19-P1-XP-049 FIX: $order->save() verificado — si falla
                // la persistencia del meta, el claim SI se fileo en XCover pero
                // la marca de idempotency no se persiste — proxima ejecucion
                // reintentaria y causaria double-claim.
                $saved = $order->save();

                if ( false === $saved ) {
                    LTMS_Core_Logger::error(
                        'XCOVER_CLAIM_META_SAVE_FAILED',
                        sprintf(
                            'Claim %s filed at XCover for dispute #%d (policy %s, order #%d) — order->save() failed, idempotency meta not persisted. Manual SQL: UPDATE %spostmeta SET meta_value=\'%s\' WHERE post_id=%d AND meta_key=\'_ltms_xcover_claim_filed_%d\';',
                            $claim_id,
                            $dispute_id,
                            $policy_id,
                            $order_id,
                            $GLOBALS['wpdb']->prefix,
                            $claim_id,
                            $order_id,
                            $dispute_id
                        ),
                        [
                            'dispute_id' => $dispute_id,
                            'order_id'   => $order_id,
                            'policy_id'  => $policy_id,
                            'claim_id'   => $claim_id,
                        ]
                    );
                    // CICLO19 RE-AUDITORIA: no continuar al log info — el meta
                    // no se persistio, claim en limbo. Pausa aqui para no
                    // falsear "FILED" en logs del admin.
                    return;
                }

                LTMS_Core_Logger::info(
                    'XCOVER_CLAIM_FILED',
                    sprintf(
                        'Claim %s filed for dispute #%d (policy %s, order #%d)',
                        $claim_id,
                        $dispute_id,
                        $policy_id,
                        $order_id
                    ),
                    [
                        'dispute_id' => $dispute_id,
                        'order_id'   => $order_id,
                        'policy_id'  => $policy_id,
                        'claim_id'   => $claim_id,
                    ]
                );
            } else {
                LTMS_Core_Logger::warning(
                    'XCOVER_CLAIM_METHOD_MISSING',
                    sprintf(
                        'XCover API client does not implement file_claim() — claim for dispute #%d (policy %s, order #%d) not filed. Implement LTMS_Api_XCover::file_claim().',
                        $dispute_id,
                        $policy_id,
                        $order_id
                    ),
                    [ 'dispute_id' => $dispute_id, 'order_id' => $order_id, 'policy_id' => $policy_id ]
                );
            }
        } catch ( \Throwable $e ) {
            // CICLO19-P1-XP-054 FIX: contexto full en catch (no solo order+msg).
            LTMS_Core_Logger::error(
                'XCOVER_CLAIM_FILE_FAILED',
                sprintf(
                    'Dispute #%d, Order #%d, Policy %s: %s',
                    $dispute_id,
                    $order_id,
                    $policy_id,
                    $e->getMessage()
                ),
                [
                    'dispute_id' => $dispute_id,
                    'order_id'   => $order_id,
                    'policy_id'  => $policy_id,
                    'error'      => $e->getMessage(),
                ]
            );
        }
    }

    public static function on_order_paid( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Idempotency guard
        if ( $order->get_meta( '_ltms_insurance_policy_created' ) ) return;

        if ( $order->get_meta( '_ltms_insurance_selected' ) !== 'yes' ) return;

        $quote_id = $order->get_meta( '_ltms_insurance_quote_id' );
        if ( ! $quote_id ) return;

        $policy_data = self::build_policy_data( $order );
        $quote_id    = $policy_data['quote_id']; // M-113: preservar antes de pasar a la API

        try {
            $xcover = LTMS_Api_Factory::get( 'xcover' );
            // M-110/M-112: create_policy(quote_id, holder_data) — separar quote_id del payload de cliente
            $result = $xcover->create_policy( $quote_id, [
                'first_name' => $policy_data['customer']['first_name'],
                'last_name'  => $policy_data['customer']['last_name'],
                'email'      => $policy_data['customer']['email'],
                'phone'      => $policy_data['customer']['phone'],
                'order_id'   => $policy_data['order_id'],
            ] );

            $policy_id     = $result['policy_id'] ?? $result['id'] ?? '';
            $policy_number = $result['policy_number'] ?? $result['certificate_number'] ?? '';
            $cert_url      = $result['certificate_url'] ?? $result['certificate_download_url'] ?? '';
            $premium       = (float) ( $result['premium'] ?? 0 );

            $order->update_meta_data( '_ltms_insurance_policy_created', true );
            $order->update_meta_data( '_ltms_insurance_policy_id', $policy_id );
            $order->update_meta_data( '_ltms_insurance_policy_number', $policy_number );
            $order->update_meta_data( '_ltms_insurance_certificate_url', $cert_url );

            // CICLO19-P1-XP-050 FIX: $order->save() verificado — si falla la
            // persistencia del meta de idempotency (_ltms_insurance_policy_created),
            // la politica SI se creo en XCover pero NO se persiste localmente →
            // proxima ejecucion del handler (re-entry por WC hooks, retry, etc.)
            // re-crea la politica via create_policy() → double policy + double
            // premium al vendor. Log critico con SQL de reconciliacion manual.
            $saved = $order->save();
            if ( false === $saved ) {
                LTMS_Core_Logger::error(
                    'XCOVER_POLICY_META_SAVE_FAILED',
                    sprintf(
                        'Policy %s created at XCover for order #%d but order->save() returned false — idempotency meta NOT persisted. Manual SQL: INSERT INTO %spostmeta (post_id, meta_key, meta_value) VALUES (%d, \'_ltms_insurance_policy_created\', \'1\'), (%d, \'_ltms_insurance_policy_id\', \'%s\');',
                        $policy_id,
                        $order_id,
                        $GLOBALS['wpdb']->prefix,
                        $order_id,
                        $order_id,
                        $policy_id
                    ),
                    [
                        'order_id'   => $order_id,
                        'policy_id'  => $policy_id,
                    ]
                );
                // No continuar con record_policy — la politica no se persistio
                // localmente y re-entry crearia otra politica. Bail y dejar que
                // el admin reconcilie manualmente con el SQL del log.
                return;
            }

            $vendor_id = (int) $order->get_meta( '_ltms_vendor_id' );
            $result['quote_id'] = $quote_id; // M-113: inyectar quote_id en result para record_policy
            self::record_policy( $order_id, $vendor_id, $result, $premium );

            LTMS_Core_Logger::info( 'XCOVER_POLICY_CREATED', sprintf( 'Policy %s created for order #%d', $policy_id, $order_id ) );

        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error( 'XCOVER_POLICY_CREATE_FAILED', sprintf( 'Order #%d: %s', $order_id, $e->getMessage() ) );
        }
    }

    public static function on_order_cancelled( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $policy_id = $order->get_meta( '_ltms_insurance_policy_id' );
        if ( ! $policy_id ) return;

        // Already cancelled?
        if ( $order->get_meta( '_ltms_insurance_policy_cancelled' ) ) return;

        try {
            $xcover = LTMS_Api_Factory::get( 'xcover' );
            // M-111: cancel_policy(policy_id, reason) requiere dos argumentos
            $result = $xcover->cancel_policy( $policy_id, 'order_cancelled' );

            $order->update_meta_data( '_ltms_insurance_policy_cancelled', true );

            // CICLO19-P1-XP-052 FIX: $order->save() verificado ANTES de tocar
            // la tabla lt_insurance_policies. Si save() falla, el meta
            // _ltms_insurance_policy_cancelled no se persiste y la tabla
            // local tampoco se actualiza — consistente. Si lo persistimos
            // anyway, retry no ejecuta cancel_policy() (bail en idempotency
            // check), pero la tabla lt_insurance_policies sigue en status='active'
            // → reconciliacion inconsistente.
            $saved = $order->save();
            if ( false === $saved ) {
                LTMS_Core_Logger::error(
                    'XCOVER_POLICY_CANCEL_META_SAVE_FAILED',
                    sprintf(
                        'Policy %s cancelled at XCover for order #%d but order->save() returned false — idempotency meta NOT persisted. Manual SQL: INSERT INTO %spostmeta (post_id, meta_key, meta_value) VALUES (%d, \'_ltms_insurance_policy_cancelled\', \'1\');',
                        $policy_id,
                        $order_id,
                        $GLOBALS['wpdb']->prefix,
                        $order_id
                    ),
                    [ 'order_id' => $order_id, 'policy_id' => $policy_id ]
                );
                return;
            }

            // Update lt_insurance_policies
            global $wpdb;
            $refund = (float) ( $result['refund_amount'] ?? 0 );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $updated = $wpdb->update(
                $wpdb->prefix . 'lt_insurance_policies',
                [
                    'status'           => 'cancelled',
                    'cancellation_ref' => $result['cancellation_id'] ?? '',
                    'cancelled_at'     => LTMS_Utils::now_utc(),
                    'cancel_reason'    => 'order_cancelled',
                    'refund_amount'    => $refund,
                    'updated_at'       => LTMS_Utils::now_utc(),
                ],
                [ 'policy_id' => $policy_id ],
                [ '%s', '%s', '%s', '%s', '%f', '%s' ],
                [ '%s' ]
            );

            // CICLO19-P1-XP-052 FIX: $wpdb->update verificado — false = error
            // DB, 0 = no rows matched (poliza no esta en tabla local, ya
            // borrada o policy_id invalido). Distinguir ambos: false requiere
            // log critico + reconciliacion manual; 0 = warning porque la poliza
            // se cancelo en XCover pero no hay mirror local (posible si nunca
            // paso por record_policy, ej: feature migrado a mitad de vida).
            if ( false === $updated ) {
                LTMS_Core_Logger::error(
                    'XCOVER_POLICY_CANCEL_DB_UPDATE_FAILED',
                    sprintf(
                        'Policy %s cancelled at XCover + meta persisted for order #%d, but $wpdb->update on lt_insurance_policies returned false (DB error: %s). Manual SQL: UPDATE %slt_insurance_policies SET status=\'cancelled\', cancelled_at=UTC_TIMESTAMP(), cancel_reason=\'order_cancelled\', refund_amount=%f, updated_at=UTC_TIMESTAMP() WHERE policy_id=\'%s\';',
                        $policy_id,
                        $order_id,
                        $wpdb->last_error,
                        $wpdb->prefix,
                        $refund,
                        $policy_id
                    ),
                    [ 'order_id' => $order_id, 'policy_id' => $policy_id, 'refund' => $refund ]
                );
            } elseif ( 0 === $updated ) {
                LTMS_Core_Logger::warning(
                    'XCOVER_POLICY_CANCEL_NO_LOCAL_ROW',
                    sprintf(
                        'Policy %s cancelled at XCover + meta persisted for order #%d, but no row matched in lt_insurance_policies (policy not mirrored locally — maybe never passed through record_policy).',
                        $policy_id,
                        $order_id
                    ),
                    [ 'order_id' => $order_id, 'policy_id' => $policy_id ]
                );
            }

            LTMS_Core_Logger::info(
                'XCOVER_POLICY_CANCELLED',
                sprintf( 'Policy %s cancelled for order #%d (refund: %f)', $policy_id, $order_id, $refund ),
                [ 'order_id' => $order_id, 'policy_id' => $policy_id, 'refund' => $refund ]
            );

        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error( 'XCOVER_POLICY_CANCEL_FAILED', sprintf( 'Order #%d: %s', $order_id, $e->getMessage() ) );
        }
    }

    public static function build_policy_data( \WC_Order $order ): array {
        $items = [];
        foreach ( $order->get_items() as $item ) {
            $items[] = [
                'name'  => $item->get_name(),
                'price' => (float) $item->get_total(),
                'qty'   => $item->get_quantity(),
            ];
        }

        return [
            'quote_id'       => $order->get_meta( '_ltms_insurance_quote_id' ),
            'insurance_type' => $order->get_meta( '_ltms_insurance_type' ) ?: 'parcel_protection',
            'order_id'       => (string) $order->get_id(),
            'customer'       => [
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'email'      => $order->get_billing_email(),
                'phone'      => $order->get_billing_phone(),
            ],
            'items'          => $items,
            'total'          => (float) $order->get_total(),
            'currency'       => $order->get_currency(),
        ];
    }

    public static function record_policy( int $order_id, int $vendor_id, array $result, float $premium ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_insurance_policies';

        // v2.9.121 INSURANCE-AUDIT P0-2 FIX: check for duplicate before INSERT.
        // Before, if on_order_paid was called twice (race between
        // woocommerce_payment_complete and woocommerce_order_status_completed),
        // the idempotency guard (_ltms_insurance_policy_created) might not be
        // set yet → double INSERT in lt_insurance_policies.
        $policy_id = $result['policy_id'] ?? $result['id'] ?? '';
        if ( $policy_id ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE policy_id = %s",
                $policy_id
            ) );
            if ( $existing > 0 ) {
                LTMS_Core_Logger::warning(
                    'XCOVER_POLICY_DUPLICATE_SKIP',
                    sprintf( 'Policy %s already recorded for order #%d — skipping duplicate INSERT', $policy_id, $order_id ),
                    [ 'order_id' => $order_id, 'policy_id' => $policy_id ]
                );
                return;
            }
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $inserted = $wpdb->insert(
            $table,
            [
                'order_id'        => $order_id,
                'vendor_id'       => $vendor_id,
                'quote_id'        => $result['quote_id'] ?? '',
                'policy_id'       => $policy_id,
                'policy_number'   => $result['policy_number'] ?? '',
                'certificate_url' => $result['certificate_url'] ?? '',
                'insurance_type'  => $result['insurance_type'] ?? 'parcel_protection',
                'premium_amount'  => $premium,
                'currency'        => LTMS_Core_Config::get_currency(),
                'status'          => 'active',
                'metadata'        => wp_json_encode( $result ),
                'created_at'      => LTMS_Utils::now_utc(),
                'updated_at'      => LTMS_Utils::now_utc(),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' ]
        );

        // CICLO19-P1-XP-051 FIX: $wpdb->insert verificado. false = error DB
        // (timeout, deadlock, replica lag) — la poliza SI se creo en XCover Y
        // el meta _ltms_insurance_policy_id SI se persistio localmente, pero
        // el mirror en lt_insurance_policies no existe → cancel_policy() no
        // encontrara la fila (warning XCOVER_POLICY_CANCEL_NO_LOCAL_ROW en
        // on_order_cancelled) → refund/reconciliacion perdido silenciosamente.
        // Log critico + SQL de reconciliacion manual para no perder el ledger.
        if ( false === $inserted ) {
            LTMS_Core_Logger::error(
                'XCOVER_POLICY_RECORD_INSERT_FAILED',
                sprintf(
                    'Policy %s created at XCover + meta persisted for order #%d, but $wpdb->insert on lt_insurance_policies returned false (DB error: %s). Manual SQL: INSERT INTO %slt_insurance_policies (order_id, vendor_id, quote_id, policy_id, policy_number, certificate_url, insurance_type, premium_amount, currency, status, metadata, created_at, updated_at) VALUES (%d, %d, \'%s\', \'%s\', \'%s\', \'%s\', \'%s\', %f, \'%s\', \'active\', \'%s\', UTC_TIMESTAMP(), UTC_TIMESTAMP());',
                    $policy_id,
                    $order_id,
                    $wpdb->last_error,
                    $wpdb->prefix,
                    $order_id,
                    $vendor_id,
                    $result['quote_id'] ?? '',
                    $policy_id,
                    $result['policy_number'] ?? '',
                    $result['certificate_url'] ?? '',
                    $result['insurance_type'] ?? 'parcel_protection',
                    $premium,
                    LTMS_Core_Config::get_currency(),
                    wp_json_encode( $result )
                ),
                [
                    'order_id'   => $order_id,
                    'vendor_id'  => $vendor_id,
                    'policy_id'  => $policy_id,
                    'premium'    => $premium,
                ]
            );
        }
    }
}
