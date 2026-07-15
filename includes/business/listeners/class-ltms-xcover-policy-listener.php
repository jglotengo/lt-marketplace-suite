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
        add_action( 'ltms_xcover_file_claim', [ __CLASS__, 'on_file_claim' ], 10, 3 );
    }

    /**
     * Files an XCover insurance claim when a dispute is approved.
     *
     * Hooked to: ltms_xcover_file_claim
     * Fired by: LTMS_Business_Consumer_Protection::maybe_trigger_insurance_claim()
     *
     * @param int    $dispute_id The dispute ID.
     * @param int    $order_id   The WooCommerce order ID.
     * @param string $reason     The dispute reason (damaged, lost, not_as_described, etc.).
     * @return void
     */
    public static function on_file_claim( int $dispute_id, int $order_id, string $reason ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Only file claim if an insurance policy exists for this order.
        $policy_id = $order->get_meta( '_ltms_insurance_policy_id' );
        if ( ! $policy_id ) return;

        // Idempotency: don't file claim twice for the same dispute.
        $existing_claim = $order->get_meta( '_ltms_xcover_claim_filed_' . $dispute_id );
        if ( $existing_claim ) return;

        try {
            $xcover = LTMS_Api_Factory::get( 'xcover' );

            // Build claim data from order + dispute info.
            $claim_data = [
                'policy_id'    => $policy_id,
                'reason'       => $reason,
                'description'  => sprintf(
                    'Dispute #%d filed by customer. Reason: %s. Order #%d.',
                    $dispute_id,
                    $reason,
                    $order_id
                ),
                'incident_date' => current_time( 'mysql', true ),
                'amount'        => (float) $order->get_total(),
                'currency'      => $order->get_currency(),
            ];

            // Attempt to file the claim via the XCover API.
            // If the API client doesn't have a file_claim method, log and skip.
            if ( method_exists( $xcover, 'file_claim' ) ) {
                $result = $xcover->file_claim( $claim_data );
                $claim_id = $result['claim_id'] ?? $result['id'] ?? '';

                $order->update_meta_data( '_ltms_xcover_claim_filed_' . $dispute_id, $claim_id );
                $order->update_meta_data( '_ltms_xcover_claim_id', $claim_id );
                $order->save();

                LTMS_Core_Logger::info(
                    'XCOVER_CLAIM_FILED',
                    sprintf( 'Claim %s filed for dispute #%d (policy %s, order #%d)', $claim_id, $dispute_id, $policy_id, $order_id ),
                    [ 'dispute_id' => $dispute_id, 'order_id' => $order_id, 'policy_id' => $policy_id, 'claim_id' => $claim_id ]
                );
            } else {
                LTMS_Core_Logger::warning(
                    'XCOVER_CLAIM_METHOD_MISSING',
                    sprintf( 'XCover API client does not implement file_claim() — claim for dispute #%d not filed. Implement LTMS_Api_XCover::file_claim().', $dispute_id ),
                    [ 'dispute_id' => $dispute_id, 'order_id' => $order_id ]
                );
            }
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::error(
                'XCOVER_CLAIM_FILE_FAILED',
                sprintf( 'Dispute #%d, Order #%d: %s', $dispute_id, $order_id, $e->getMessage() ),
                [ 'dispute_id' => $dispute_id, 'order_id' => $order_id, 'error' => $e->getMessage() ]
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
            $order->save();

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
            $order->save();

            // Update lt_insurance_policies
            global $wpdb;
            $refund = (float) ( $result['refund_amount'] ?? 0 );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update(
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

            LTMS_Core_Logger::info( 'XCOVER_POLICY_CANCELLED', sprintf( 'Policy %s cancelled for order #%d', $policy_id, $order_id ) );

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
        $wpdb->insert(
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
    }
}
