<?php
/**
 * LTMS Audit Listeners — Listeners for actions added during audit
 *
 * Escucha los actions/filters añadidos durante las auditorías v2.9.114-131:
 * - ltms_wallet_frozen → notificación al vendor + fraud scoring log
 * - ltms_wallet_unfrozen → notificación al vendor
 * - ltms_payout_rejected → reversal contable log
 * - ltms_payout_pre_create → sanctions screening al request time
 * - ltms_booking_cancelled → commission reversal flag
 *
 * @package LTMS
 * @version 2.9.132
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Audit_Listeners {

    public static function init(): void {
        // Wallet freeze/unfreeze
        add_action( 'ltms_wallet_frozen', [ __CLASS__, 'on_wallet_frozen' ], 10, 3 );
        add_action( 'ltms_wallet_unfrozen', [ __CLASS__, 'on_wallet_unfrozen' ], 10, 3 );

        // Payout rejected
        add_action( 'ltms_payout_rejected', [ __CLASS__, 'on_payout_rejected' ], 10, 4 );

        // Payout pre-create filter (sanctions screening at request time)
        add_filter( 'ltms_payout_pre_create', [ __CLASS__, 'screen_payout_request' ], 10, 4 );

        // Booking cancelled (commission reversal flag)
        add_action( 'ltms_booking_cancelled', [ __CLASS__, 'on_booking_cancelled' ], 20, 3 );
    }

    // ── Wallet Freeze/Unfreeze ──────────────────────────────────────────

    public static function on_wallet_frozen( int $vendor_id, string $reason, int $frozen_by ): void {
        // Notificar al vendor que su billetera fue congelada
        $vendor = get_userdata( $vendor_id );
        if ( $vendor && $vendor->user_email ) {
            $subject = __( '[Lo Tengo] Tu billetera ha sido congelada', 'ltms' );
            $message = sprintf(
                __( "Hola %s,\n\nTu billetera en Lo Tengo ha sido congelada temporalmente.\n\nMotivo: %s\n\nSi tienes preguntas, contacta a soporte@lo-tengo.com.co.\n\nEquipo Lo Tengo", 'ltms' ),
                $vendor->display_name,
                $reason
            );
            wp_mail( $vendor->user_email, $subject, $message );
        }

        // Crear notificación in-app
        if ( class_exists( 'LTMS_Frontend_Notifications' ) ) {
            LTMS_Frontend_Notifications::create(
                $vendor_id,
                'wallet_frozen',
                __( 'Billetera Congelada', 'ltms' ),
                sprintf( __( 'Tu billetera ha sido congelada. Motivo: %s', 'ltms' ), $reason ),
                [ 'reason' => $reason, 'frozen_by' => $frozen_by ]
            );
        }

        // Log para fraud scoring
        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::security(
                'WALLET_FROZEN_NOTIFICATION',
                sprintf( 'Vendor #%d notificado de congelamiento. Motivo: %s. Congelado por: #%d', $vendor_id, $reason, $frozen_by ),
                [ 'vendor_id' => $vendor_id, 'reason' => $reason, 'frozen_by' => $frozen_by ]
            );
        }
    }

    public static function on_wallet_unfrozen( int $vendor_id, string $reason, int $unfrozen_by ): void {
        // Notificar al vendor que su billetera fue descongelada
        $vendor = get_userdata( $vendor_id );
        if ( $vendor && $vendor->user_email ) {
            $subject = __( '[Lo Tengo] Tu billetera ha sido reactivada', 'ltms' );
            $message = sprintf(
                __( "Hola %s,\n\n¡Buenas noticias! Tu billetera en Lo Tengo ha sido reactivada.\n\nYa puedes solicitar retiros normalmente.\n\nEquipo Lo Tengo", 'ltms' ),
                $vendor->display_name
            );
            wp_mail( $vendor->user_email, $subject, $message );
        }

        // Crear notificación in-app
        if ( class_exists( 'LTMS_Frontend_Notifications' ) ) {
            LTMS_Frontend_Notifications::create(
                $vendor_id,
                'wallet_unfrozen',
                __( 'Billetera Reactivada', 'ltms' ),
                __( 'Tu billetera ha sido reactivada. Ya puedes solicitar retiros.', 'ltms' ),
                [ 'reason' => $reason, 'unfrozen_by' => $unfrozen_by ]
            );
        }
    }

    // ── Payout Rejected ─────────────────────────────────────────────────

    public static function on_payout_rejected( int $payout_id, int $vendor_id, float $amount, string $reason ): void {
        // Log para reversal contable (un listener contable puede consumir este log)
        if ( class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'PAYOUT_REJECTED_LISTENER',
                sprintf( 'Payout #%d rechazado para vendor #%d. Monto: %.2f. Motivo: %s', $payout_id, $vendor_id, $amount, $reason ),
                [
                    'payout_id' => $payout_id,
                    'vendor_id' => $vendor_id,
                    'amount'    => $amount,
                    'reason'    => $reason,
                    'requires_accounting_reversal' => true,
                ]
            );
        }

        // Crear notificación in-app
        if ( class_exists( 'LTMS_Frontend_Notifications' ) ) {
            LTMS_Frontend_Notifications::create(
                $vendor_id,
                'payout_rejected',
                __( 'Retiro Rechazado', 'ltms' ),
                sprintf( __( 'Tu retiro #%d por fue rechazado. Motivo: %s', 'ltms' ), $payout_id, $reason ),
                [ 'payout_id' => $payout_id, 'amount' => $amount, 'reason' => $reason ]
            );
        }

        // Disparar action para que un listener contable futuro haga el reversal
        do_action( 'ltms_payout_reversal_needed', $payout_id, $vendor_id, $amount );
    }

    // ── Payout Pre-Create (sanctions screening) ─────────────────────────

    public static function screen_payout_request( bool $allow, int $vendor_id, float $amount, string $method ): bool {
        // Si ya fue bloqueado por otro filter, mantener bloqueado
        if ( ! $allow ) {
            return false;
        }

        // Screen vendor contra listas restrictivas al momento del request
        if ( class_exists( 'LTMS_Fintech_Compliance' ) ) {
            $is_clean = LTMS_Fintech_Compliance::screen_against_sanctions_lists( true, $vendor_id );
            if ( ! $is_clean ) {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::security(
                        'PAYOUT_REQUEST_BLOCKED_SANCTIONS',
                        sprintf( 'Vendor #%d bloqueado al solicitar retiro (sanctions match). Monto: %.2f, Método: %s', $vendor_id, $amount, $method ),
                        [ 'vendor_id' => $vendor_id, 'amount' => $amount, 'method' => $method ]
                    );
                }
                return false;
            }
        }

        // Verificar que el vendor no esté en una lista de bloqueo manual
        $is_blacklisted = (bool) get_user_meta( $vendor_id, '_ltms_payout_blacklisted', true );
        if ( $is_blacklisted ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::security(
                    'PAYOUT_REQUEST_BLOCKED_BLACKLIST',
                    sprintf( 'Vendor #%d bloqueado al solicitar retiro (blacklist manual).', $vendor_id ),
                    [ 'vendor_id' => $vendor_id, 'amount' => $amount ]
                );
            }
            return false;
        }

        return true;
    }

    // ── Booking Cancelled (commission reversal flag) ────────────────────

    public static function on_booking_cancelled( int $booking_id, array $booking, string $cancelled_by ): void {
        // Marcar las comisiones asociadas a este booking para reversal
        // El commission reversal real lo hace el cron de accounting o un listener contable
        $order_id = (int) ( $booking['wc_order_id'] ?? 0 );
        if ( ! $order_id ) {
            return;
        }

        global $wpdb;
        $commissions_table = $wpdb->prefix . 'lt_commissions';

        // Marcar comisiones como 'reversal_pending' para que el cron contable las procese
        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE `{$commissions_table}` 
             SET status = 'reversal_pending',
                 updated_at = NOW()
             WHERE order_id = %d 
               AND status IN ('paid', 'pending')",
            $order_id
        ) );

        if ( $updated && class_exists( 'LTMS_Core_Logger' ) ) {
            LTMS_Core_Logger::info(
                'BOOKING_CANCELLED_COMMISSION_FLAG',
                sprintf( 'Booking #%d cancelado — %d comisiones marcadas como reversal_pending para order #%d', $booking_id, $updated, $order_id ),
                [
                    'booking_id' => $booking_id,
                    'order_id'   => $order_id,
                    'cancelled_by' => $cancelled_by,
                    'commissions_flagged' => $updated,
                ]
            );
        }
    }
}

// Registrar listeners
LTMS_Audit_Listeners::init();
