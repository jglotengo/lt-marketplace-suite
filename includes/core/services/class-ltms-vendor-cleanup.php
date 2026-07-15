<?php
/**
 * LTMS Vendor Cleanup — Cleanup orphaned records when a vendor is deleted
 *
 * v2.9.136 DATA-INTEGRITY-AUDIT: When a vendor (user) is deleted from WordPress,
 * their records in custom tables remain as orphans. This class hooks into
 * 'delete_user' and cleans up all vendor-related data.
 *
 * Tables cleaned:
 * - lt_vendor_wallets, lt_wallet_transactions, lt_wallet_holds
 * - lt_payout_requests, lt_commissions
 * - lt_bookings, lt_booking_slots (for vendor's products)
 * - lt_referral_network
 * - lt_notifications, lt_vendor_kyc, lt_vendor_drivers
 * - lt_insurance_policies, lt_donations
 * - lt_consumer_disputes (as customer)
 * - lt_review_votes, lt_wishlists
 *
 * @package LTMS
 * @version 2.9.136
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Vendor_Cleanup {

    public static function init(): void {
        add_action( 'delete_user', [ __CLASS__, 'cleanup_vendor_data' ], 10, 2 );
    }

    /**
     * Cleans up all vendor-related data when a user is deleted.
     *
     * @param int      $user_id  ID of the deleted user.
     * @param int|null $reassign ID of the user to reassign posts to (null = delete posts).
     * @return void
     */
    public static function cleanup_vendor_data( int $user_id, $reassign ): void {
        if ( ! $user_id ) {
            return;
        }

        global $wpdb;
        $prefix = $wpdb->prefix;

        // Log the cleanup
        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'VENDOR_CLEANUP_START',
                sprintf( 'Cleaning up data for deleted user #%d', $user_id ),
                [ 'user_id' => $user_id, 'reassign' => $reassign ]
            );
        }

        // Tables to clean by vendor_id
        $vendor_tables = [
            'lt_vendor_wallets',
            'lt_wallet_transactions',
            'lt_wallet_holds',
            'lt_payout_requests',
            'lt_commissions',
            'lt_bookings',
            'lt_referral_network',
            'lt_notifications',
            'lt_vendor_kyc',
            'lt_vendor_drivers',
            'lt_insurance_policies',
            'lt_donations',
            'lt_review_votes',
            'lt_wishlists',
            'lt_wallet_journal',
            'lt_data_protection_training',
        ];

        foreach ( $vendor_tables as $table_suffix ) {
            $table = $prefix . $table_suffix;
            // Check if table exists before deleting
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            if ( $exists === $table ) {
                // Delete by vendor_id
                $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE vendor_id = %d", $user_id ) );
                // Also try user_id column (some tables use user_id instead of vendor_id)
                $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE user_id = %d", $user_id ) );
            }
        }

        // Clean consumer disputes (customer_id column)
        $disputes_table = $prefix . 'lt_consumer_disputes';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $disputes_table ) );
        if ( $exists === $disputes_table ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM `{$disputes_table}` WHERE customer_id = %d", $user_id ) );
        }

        // Clean booking slots for vendor's products
        $slots_table = $prefix . 'lt_booking_slots';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $slots_table ) );
        if ( $exists === $slots_table ) {
            // Get product IDs authored by this vendor
            $product_ids = get_posts( [
                'author'         => $user_id,
                'post_type'      => 'product',
                'post_status'    => 'any',
                'fields'         => 'ids',
                'posts_per_page' => -1,
            ] );
            if ( ! empty( $product_ids ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM `{$slots_table}` WHERE product_id IN ({$placeholders})",
                    ...$product_ids
                ) );
            }
        }

        // Clean consent log
        $consent_table = $prefix . 'lt_consent_log';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $consent_table ) );
        if ( $exists === $consent_table ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM `{$consent_table}` WHERE user_id = %d", $user_id ) );
        }

        // Clean vault access log
        $vault_table = $prefix . 'lt_vault_access_log';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $vault_table ) );
        if ( $exists === $vault_table ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM `{$vault_table}` WHERE user_id = %d", $user_id ) );
        }

        // Clean personal data access log
        $pdal_table = $prefix . 'lt_personal_data_access_log';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pdal_table ) );
        if ( $exists === $pdal_table ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM `{$pdal_table}` WHERE subject_user_id = %d", $user_id ) );
        }

        // Update referral network: remove deleted vendor from ancestor_paths
        $referral_table = $prefix . 'lt_referral_network';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $referral_table ) );
        if ( $exists === $referral_table ) {
            // Replace vendor_id in ancestor_path strings (e.g., "1/5/12" → remove "/12")
            $descendants = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT vendor_id, ancestor_path FROM `{$referral_table}` WHERE ancestor_path LIKE %s",
                    '%/' . $user_id . '/%'
                ),
                ARRAY_A
            );
            foreach ( (array) $descendants as $desc ) {
                $new_path = str_replace( '/' . $user_id . '/', '/', $desc['ancestor_path'] );
                $new_path = ltrim( $new_path, '/' );
                $wpdb->update(
                    $referral_table,
                    [ 'ancestor_path' => $new_path ],
                    [ 'vendor_id' => $desc['vendor_id'] ]
                );
            }
        }

        // Log completion
        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'VENDOR_CLEANUP_COMPLETE',
                sprintf( 'Cleanup completed for deleted user #%d', $user_id ),
                [ 'user_id' => $user_id ]
            );
        }
    }
}

LTMS_Vendor_Cleanup::init();
