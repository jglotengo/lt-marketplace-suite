<?php
/**
 * LTMS Aveonline Onboarding — Auto-Trigger & Dashboard Integration
 *
 * Se ejecuta cuando un vendedor se registra (ltms_vendor_registered) o cuando
 * el KYC es aprobado (ltms_vendor_approved). Si el vendedor no tiene onboarding
 * de Aveonline, muestra el wizard en el dashboard.
 *
 * @package LTMS
 * @version 2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Aveonline_Onboarding_Trigger {

    /** Meta key que registra el timestamp (Unix) de inicio del onboarding. */
    const META_ONBOARDING_STARTED_AT = '_ltms_ave_onboarding_started_at';

    /** Meta keys para deduplicar recordatorios enviados. */
    const META_REMINDER_3_SENT  = '_ltms_ave_reminder_3_sent';
    const META_REMINDER_7_SENT  = '_ltms_ave_reminder_7_sent';
    const META_REMINDER_14_SENT = '_ltms_ave_reminder_14_sent';

    /** Meta key que bloquea la creación de envíos tras 14 días sin completar onboarding. */
    const META_SHIPMENTS_BLOCKED = '_ltms_ave_shipments_blocked';

    public static function init(): void {
        // Mostrar el wizard en el dashboard del vendedor (vista envíos)
        add_action( 'ltms_dashboard_view_envios_before', [ __CLASS__, 'maybe_show_wizard' ] );

        // También mostrarlo en la vista home si no ha completado
        add_action( 'ltms_dashboard_view_home_before', [ __CLASS__, 'maybe_show_wizard' ] );

        // Auto-iniciar paso 1 cuando el vendedor se registra (si Aveonline está activo)
        add_action( 'ltms_vendor_registered', [ __CLASS__, 'auto_start_onboarding' ], 40, 2 );

        // Verificar estado cuando el KYC es aprobado
        add_action( 'ltms_vendor_approved', [ __CLASS__, 'check_onboarding_after_kyc' ], 40 );

        // Cron diario de recordatorios (3d / 7d / 14d) + bloqueo de envíos a los 14d.
        add_action( 'ltms_check_aveonboarding_reminders', [ __CLASS__, 'check_onboarding_reminders' ] );
        if ( ! wp_next_scheduled( 'ltms_check_aveonboarding_reminders' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ltms_check_aveonboarding_reminders' );
        }
    }

    /**
     * Muestra el wizard de onboarding si:
     * - Aveonline está activo
     * - El JWT de onboarding está configurado
     * - El vendedor no ha completado el onboarding
     */
    public static function maybe_show_wizard(): void {
        if ( ! self::is_onboarding_available() ) {
            return;
        }

        $user_id = get_current_user_id();
        $status  = get_user_meta( $user_id, '_ltms_ave_onboarding_status', true ) ?: 'pending';
        $id_ave  = (int) get_user_meta( $user_id, '_ltms_ave_empresa_id', true );

        // Si ya completó, no mostrar
        if ( $status === 'completed' && $id_ave ) {
            return;
        }

        // Incluir la vista del wizard
        $wizard_path = LTMS_INCLUDES_DIR . 'frontend/views/view-aveonline-onboarding.php';
        if ( file_exists( $wizard_path ) ) {
            include $wizard_path;
        }
    }

    /**
     * Auto-inicia el paso 1 del onboarding cuando un vendedor se registra.
     * Solo si Aveonline está activo y el JWT está configurado.
     *
     * @param int    $vendor_id     ID del vendedor.
     * @param string $referral_code Código de referido (opcional).
     */
    public static function auto_start_onboarding( int $vendor_id, string $referral_code = '' ): void {
        if ( ! self::is_onboarding_available() ) {
            return;
        }

        // Verificar que no tenga ya un onboarding iniciado
        $status = get_user_meta( $vendor_id, '_ltms_ave_onboarding_status', true );
        if ( $status && $status !== 'pending' ) {
            return; // Ya tiene un proceso iniciado
        }

        $user = get_userdata( $vendor_id );
        if ( ! $user ) {
            return;
        }

        // Pre-llenar datos del vendedor
        $phone = get_user_meta( $vendor_id, 'ltms_phone', true )
              ?: get_user_meta( $vendor_id, 'billing_phone', true )
              ?: '';

        $data = [
            'email'          => $user->user_email,
            'phone'          => $phone,
            'name'           => $user->display_name,
            'ecommerce'      => 'WooCommerce',
            'urlLeadSource'  => get_site_url(),
            'codeIso'        => LTMS_Core_Config::get_country() === 'MX' ? 'MX' : 'CO',
        ];

        try {
            $result = LTMS_Api_Aveonline_Onboarding::instance()->accept_terms( $data );

            if ( $result['success'] || in_array( $result['code'], [ 211, 212 ], true ) ) {
                update_user_meta( $vendor_id, '_ltms_ave_onboarding_status', 'step1' );
                // Registrar timestamp de inicio para el cron de recordatorios.
                if ( ! get_user_meta( $vendor_id, self::META_ONBOARDING_STARTED_AT, true ) ) {
                    update_user_meta( $vendor_id, self::META_ONBOARDING_STARTED_AT, time() );
                }
                LTMS_Core_Logger::info(
                    'AVE_ONBOARDING_AUTO_STARTED',
                    sprintf( 'Paso 1 auto-iniciado para vendedor #%d', $vendor_id )
                );
            }
        } catch ( \Throwable $e ) {
            LTMS_Core_Logger::warning(
                'AVE_ONBOARDING_AUTO_FAIL',
                sprintf( 'Error auto-iniciando onboarding para vendedor #%d: %s', $vendor_id, $e->getMessage() )
            );
        }
    }

    /**
     * Verifica el estado del onboarding después de que el KYC es aprobado.
     * Si el onboarding está pendiente, notifica al vendedor.
     *
     * @param int $vendor_id ID del vendedor.
     */
    public static function check_onboarding_after_kyc( int $vendor_id ): void {
        if ( ! self::is_onboarding_available() ) {
            return;
        }

        $status = get_user_meta( $vendor_id, '_ltms_ave_onboarding_status', true ) ?: 'pending';
        $id_ave = (int) get_user_meta( $vendor_id, '_ltms_ave_empresa_id', true );

        if ( $status !== 'completed' || ! $id_ave ) {
            // Enviar email recordando completar el registro en Aveonline
            $user = get_userdata( $vendor_id );
            if ( $user && $user->user_email ) {
                $subject = __( 'Completa tu registro en Aveonline para enviar paquetes', 'ltms' );
                $message = sprintf(
                    __( "Hola %s,\n\nTu verificación KYC fue aprobada. Para poder enviar paquetes a través de Aveonline, necesitas completar tu registro.\n\nIngresa a tu panel → Envíos para completar el registro (4 pasos rápidos).\n\nSaludos,\nEquipo %s", 'ltms' ),
                    $user->display_name,
                    get_bloginfo( 'name' )
                );
                wp_mail( $user->user_email, $subject, $message );
            }
        }
    }

    /**
     * Verifica si el onboarding de Aveonline está disponible.
     *
     * @return bool True si Aveonline está activo y el JWT está configurado.
     */
    private static function is_onboarding_available(): bool {
        // Verificar que Aveonline esté activo
        if ( LTMS_Core_Config::get( 'ltms_aveonline_enabled', 'no' ) !== 'yes' ) {
            return false;
        }

        // Verificar que la clase exista
        if ( ! class_exists( 'LTMS_Api_Aveonline_Onboarding' ) ) {
            return false;
        }

        // Verificar que el JWT esté configurado
        return LTMS_Api_Aveonline_Onboarding::instance()->has_token();
    }

    /**
     * Cron diario de recordatorios de onboarding.
     *
     * Para cada vendedor con onboarding pendiente (status 'pending' o step < 4):
     *   - Día  3: primer recordatorio por email (meta `_ltms_ave_reminder_3_sent`).
     *   - Día  7: segundo recordatorio (meta `_ltms_ave_reminder_7_sent`).
     *   - Día 14: notificación final + bloqueo de envíos (meta `_ltms_ave_shipments_blocked=true`).
     *
     * El blocking meta es leído por el shipping method de Aveonline (fuera de este
     * scope) para impedir la creación de nuevas guías. Aquí solo se setea el flag
     * y se loggea para auditoría.
     */
    public static function check_onboarding_reminders(): void {
        if ( ! self::is_onboarding_available() ) {
            return;
        }

        // Vendedores con onboarding pendiente o parcialmente completado.
        // Un vendedor sin status meta se considera 'pending' y también se incluye.
        $users = get_users( [
            'role__in'   => [ 'ltms_vendor', 'ltms_vendor_premium' ],
            'number'     => 500,
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key'     => '_ltms_ave_onboarding_status',
                    'value'   => [ 'pending', 'step1', 'step2', 'step3' ],
                    'compare' => 'IN',
                ],
                [
                    'key'     => '_ltms_ave_onboarding_status',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ] );

        foreach ( $users as $user ) {
            self::maybe_send_reminder( $user );
        }
    }

    /**
     * Evalúa un vendedor individual y dispara el recordatorio correspondiente.
     *
     * @param \WP_User $user Vendedor.
     */
    private static function maybe_send_reminder( \WP_User $user ): void {
        $status = get_user_meta( $user->ID, '_ltms_ave_onboarding_status', true ) ?: 'pending';

        // Si completó o falló CIFIN, no enviar más recordatorios.
        if ( in_array( $status, [ 'completed', 'cifin_failed' ], true ) ) {
            return;
        }

        // Determinar fecha de inicio del onboarding.
        $started_at = (int) get_user_meta( $user->ID, self::META_ONBOARDING_STARTED_AT, true );
        if ( ! $started_at ) {
            // Fallback al timestamp de registro del usuario.
            $registered = strtotime( $user->user_registered ?: 'now' );
            $started_at = $registered ?: time();
        }

        $days_since = (int) floor( ( time() - $started_at ) / DAY_IN_SECONDS );

        // Cada recordatorio es independiente y se dispara si su umbral de días
        // se alcanzó y aún no se había enviado (dedup por meta). Esto permite
        // recuperar correctamente si el cron estuvo caído varios días: el
        // próximo tick envía todos los recordatorios pendientes de una vez.

        // Día 14: notificación final + bloqueo de envíos.
        if ( $days_since >= 14 && ! get_user_meta( $user->ID, self::META_REMINDER_14_SENT, true ) ) {
            update_user_meta( $user->ID, self::META_REMINDER_14_SENT, true );
            update_user_meta( $user->ID, self::META_SHIPMENTS_BLOCKED, true );
            self::send_reminder_email( $user, 14 );
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info(
                    'AVE_ONBOARDING_REMINDER_14',
                    sprintf( 'Vendedor #%d bloqueado por onboarding incompleto (14 días).', $user->ID )
                );
            }
        }

        // Día 7: segundo recordatorio.
        if ( $days_since >= 7 && ! get_user_meta( $user->ID, self::META_REMINDER_7_SENT, true ) ) {
            update_user_meta( $user->ID, self::META_REMINDER_7_SENT, true );
            self::send_reminder_email( $user, 7 );
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info(
                    'AVE_ONBOARDING_REMINDER_7',
                    sprintf( 'Recordatorio 7d enviado a vendedor #%d.', $user->ID )
                );
            }
        }

        // Día 3: primer recordatorio.
        if ( $days_since >= 3 && ! get_user_meta( $user->ID, self::META_REMINDER_3_SENT, true ) ) {
            update_user_meta( $user->ID, self::META_REMINDER_3_SENT, true );
            self::send_reminder_email( $user, 3 );
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::info(
                    'AVE_ONBOARDING_REMINDER_3',
                    sprintf( 'Recordatorio 3d enviado a vendedor #%d.', $user->ID )
                );
            }
        }
    }

    /**
     * Envía el email de recordatorio de onboarding al vendedor.
     *
     * @param \WP_User $user Vendedor.
     * @param int      $day  Día del recordatorio (3, 7 o 14).
     */
    private static function send_reminder_email( \WP_User $user, int $day ): void {
        if ( empty( $user->user_email ) ) {
            return;
        }

        $site_name = get_bloginfo( 'name' );
        $dashboard = home_url( '/mi-cuenta/envios' );

        $subjects = [
            3  => __( 'Recordatorio: completa tu registro en Aveonline', 'ltms' ),
            7  => __( 'Últimos días: completa tu registro en Aveonline', 'ltms' ),
            14 => __( 'URGENTE: tus envíos han sido bloqueados', 'ltms' ),
        ];

        $bodies = [
            3  => __(
                "Hola %1\$s,\n\nTe recordamos que aún no completas tu registro en Aveonline. Sin este registro no podrás crear envíos.\n\nCompleta los 4 pasos rápidos aquí:\n%2\$s\n\nSaludos,\nEquipo %3\$s",
                'ltms'
            ),
            7  => __(
                "Hola %1\$s,\n\nEsta es tu última notificación antes de que tus envíos sean bloqueados. Completa tu registro en Aveonline ahora.\n\nCompleta los 4 pasos rápidos aquí:\n%2\$s\n\nSaludos,\nEquipo %3\$s",
                'ltms'
            ),
            14 => __(
                "Hola %1\$s,\n\nHan pasado 14 días desde que iniciaste tu registro en Aveonline y no lo has completado. A partir de ahora NO PODRÁS CREAR NUEVOS ENVÍOS hasta completar tu registro.\n\nCompleta los 4 pasos rápidos aquí para desbloquear tus envíos:\n%2\$s\n\nSaludos,\nEquipo %3\$s",
                'ltms'
            ),
        ];

        $subject = $subjects[ $day ] ?? sprintf( 'Recordatorio (%dd)', $day );
        $body    = sprintf(
            $bodies[ $day ] ?? 'Completa tu registro: %2$s',
            $user->display_name ?: $user->user_login,
            $dashboard,
            $site_name
        );

        wp_mail( $user->user_email, $subject, $body );
    }
}

// Inicializar
LTMS_Aveonline_Onboarding_Trigger::init();
