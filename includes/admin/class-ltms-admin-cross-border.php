<?php
/**
 * LTMS Admin Cross-Border - Controlador AJAX del Panel Cross-Border
 *
 * Gestiona las acciones AJAX para el panel de administración de comercio
 * internacional (cross-border):
 *   - ajax_get_fx_rates          — tasas FX actuales para todos los pares
 *   - ajax_refresh_fx_rates      — fuerza refresh (LTMS_FX_Rate_Provider::refresh_rates)
 *   - ajax_get_customs_estimate  — calcula aranceles para una orden de prueba
 *   - ajax_get_cross_border_stats— estadísticas del dashboard
 *   - ajax_export_cross_border_csv — exportación de transacciones cross-border
 *
 * Tablas DB (creadas por Task 63-C en class-ltms-db-migrations.php):
 *   - {prefix}lt_fx_rates              — tasas FX cacheadas + overrides manuales
 *   - {prefix}lt_customs_declarations  — declaraciones aduaneras por orden
 *
 * @package    LTMS
 * @subpackage LTMS/includes/admin
 * @version    1.0.0
 * @since      3.1.0  Task 63-C — Cross-Border Settings + Migration + Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

/**
 * Class LTMS_Admin_Cross_Border
 *
 * AJAX + view renderer para el panel "Cross-Border" del admin.
 */
final class LTMS_Admin_Cross_Border {

        use LTMS_Logger_Aware;

        /**
         * Nonce action usada por todos los handlers AJAX de este controlador.
         */
        const NONCE_ACTION = 'ltms_admin_cross_border';

        /**
         * Capacidad requerida para todas las acciones del panel.
         */
        const REQUIRED_CAP = 'manage_options';

        /**
         * Lista blanca de proveedores FX aceptados.
         */
        const ALLOWED_FX_PROVIDERS = [ 'frankfurter', 'exchangerate', 'ecb', 'manual' ];

        /**
         * Lista blanca de incoterms aceptados.
         */
        const ALLOWED_INCOTERMS = [ 'DDP', 'DDU' ];

        /**
         * Monedas soportadas por el sistema cross-border.
         */
        const SUPPORTED_CURRENCIES = [ 'COP', 'MXN', 'USD', 'EUR', 'BRL', 'ARS', 'CLP', 'PEN', 'GBP', 'CAD' ];

        /**
         * Registra los hooks AJAX del controlador y, si la petición es admin-ajax,
         * no hace nada más (la renderización la dispara LTMS_Admin::render_cross_border()).
         *
         * @return void
         */
        public static function init(): void {
                $instance = new self();
                add_action( 'wp_ajax_ltms_get_fx_rates',           [ $instance, 'ajax_get_fx_rates' ] );
                add_action( 'wp_ajax_ltms_refresh_fx_rates',       [ $instance, 'ajax_refresh_fx_rates' ] );
                add_action( 'wp_ajax_ltms_get_customs_estimate',   [ $instance, 'ajax_get_customs_estimate' ] );
                add_action( 'wp_ajax_ltms_get_cross_border_stats', [ $instance, 'ajax_get_cross_border_stats' ] );
                add_action( 'wp_ajax_ltms_export_cross_border_csv',[ $instance, 'ajax_export_cross_border_csv' ] );
        }

        /**
         * Renderiza la página de administración de cross-border.
         * Carga la vista `html-admin-cross-border.php` desde el directorio admin/views/.
         *
         * @return void
         */
        public static function render_dashboard(): void {
                if ( ! current_user_can( self::REQUIRED_CAP ) ) {
                        wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'ltms' ) );
                }

                $view_path = LTMS_INCLUDES_DIR . 'admin/views/html-admin-cross-border.php';
                if ( file_exists( $view_path ) ) {
                        include_once $view_path;
                } else {
                        echo '<div class="wrap"><h1>' . esc_html__( 'Cross-Border', 'ltms' ) . '</h1><p>'
                                . esc_html__( 'Vista no encontrada: html-admin-cross-border.php', 'ltms' )
                                . '</p></div>';
                }
        }

        // ───────────────────────────────────────────────────────────────
        //  AJAX HANDLERS
        // ───────────────────────────────────────────────────────────────

        /**
         * AJAX: Devuelve las tasas FX actuales para todos los pares relevantes.
         *
         * Genera la matriz completa de pares base→quote para todas las monedas
         * habilitadas (ltms_enabled_currencies). Si la clase LTMS_FX_Rate_Provider
         * está disponible, usa su método get_rate(); si no, consulta directamente
         * la tabla lt_fx_rates.
         *
         * Body params:
         *   - nonce (string) Nonce de ltms_admin_cross_border
         *
         * @return void
         */
        public function ajax_get_fx_rates(): void {
                $this->verify();
                if ( ! current_user_can( self::REQUIRED_CAP ) ) {
                        wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
                }

                $enabled = $this->get_enabled_currencies();
                $base    = strtoupper( (string) LTMS_Core_Config::get( 'ltms_base_currency', 'USD' ) );
                $spread  = (float) LTMS_Core_Config::get( 'ltms_fx_spread_percentage', 1.5 );

                // Asegurar que la base esté siempre presente en la matriz.
                if ( ! in_array( $base, $enabled, true ) ) {
                        $enabled[] = $base;
                }
                $enabled = array_unique( $enabled );

                $rates    = [];
                $provider = class_exists( 'LTMS_FX_Rate_Provider' );

                foreach ( $enabled as $from ) {
                        foreach ( $enabled as $to ) {
                                if ( $from === $to ) {
                                        continue;
                                }
                                $mid = $provider ? LTMS_FX_Rate_Provider::get_rate( $from, $to ) : $this->get_rate_from_db( $from, $to );
                                $applied = null;
                                if ( $mid !== null && $mid > 0 ) {
                                        $applied = round( $mid * ( 1.0 - ( $spread / 100.0 ) ), 6 );
                                }
                                $rates[] = [
                                        'base'        => $from,
                                        'quote'       => $to,
                                        'rate_mid'    => $mid !== null ? (float) $mid : null,
                                        'rate_applied'=> $applied,
                                        'spread_pct'  => $spread,
                                        'is_manual'   => $this->is_manual_override( $from, $to ),
                                        'fetched_at'  => $this->get_rate_fetched_at( $from, $to ),
                                ];
                        }
                }

                wp_send_json_success( [
                        'base_currency'     => $base,
                        'enabled_currencies'=> $enabled,
                        'spread_percentage' => $spread,
                        'rates'             => $rates,
                        'provider'          => LTMS_Core_Config::get( 'ltms_fx_provider', 'frankfurter' ),
                ] );
        }

        /**
         * AJAX: Fuerza el refresh de las tasas FX.
         *
         * Llama a LTMS_FX_Rate_Provider::refresh_rates() (limpia transients) y,
         * adicionalmente, marca como "stale" las filas cacheadas en lt_fx_rates
         * borrando el fetched_at (la próxima lectura disparará fetch desde el
         * provider activo).
         *
         * Body params:
         *   - nonce (string)
         *
         * @return void
         */
        public function ajax_refresh_fx_rates(): void {
                $this->verify();
                if ( ! current_user_can( self::REQUIRED_CAP ) ) {
                        wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
                }

                // 1. Limpiar transients del FX provider.
                if ( class_exists( 'LTMS_FX_Rate_Provider' ) && method_exists( 'LTMS_FX_Rate_Provider', 'refresh_rates' ) ) {
                        LTMS_FX_Rate_Provider::refresh_rates();
                }

                // 2. Invalidar filas cacheadas en lt_fx_rates (no manuales) — la próxima
                //    lectura las refrescará desde el provider activo.
                global $wpdb;
                $table = $wpdb->prefix . 'lt_fx_rates';
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->query(
                        "UPDATE `{$table}` SET fetched_at = '1970-01-01 00:00:01' WHERE is_manual = 0"
                );

                $this->log_info(
                        'CROSS_BORDER_FX_REFRESH',
                        sprintf( 'Refresh de tasas FX disparado por admin #%d', get_current_user_id() )
                );

                wp_send_json_success( [
                        'message'     => __( 'Caché FX invalidada. Las tasas se refrescarán en la próxima lectura.', 'ltms' ),
                        'refreshed_at'=> gmdate( 'Y-m-d H:i:s' ),
                ] );
        }

        /**
         * AJAX: Calcula un estimado de aranceles para una orden de prueba.
         *
         * Body params:
         *   - nonce               (string)
         *   - origin_country      (string) ISO 3166-1 alpha-2
         *   - destination_country (string) ISO 3166-1 alpha-2
         *   - cif_value           (float)  Valor CIF en USD
         *   - currency            (string) Moneda del pago (ISO 4217)
         *   - incoterm            (string) DDP|DDU (opcional, usa default si vacío)
         *   - hs_code             (string) Harmonized System code (opcional)
         *
         * @return void
         */
        public function ajax_get_customs_estimate(): void {
                $this->verify();
                if ( ! current_user_can( self::REQUIRED_CAP ) ) {
                        wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
                }

                $origin = strtoupper( sanitize_text_field( wp_unslash( $_POST['origin_country'] ?? '' ) ) ); // phpcs:ignore
                $dest   = strtoupper( sanitize_text_field( wp_unslash( $_POST['destination_country'] ?? '' ) ) ); // phpcs:ignore
                $cif    = (float) ( $_POST['cif_value'] ?? 0 ); // phpcs:ignore
                $curr   = strtoupper( sanitize_text_field( wp_unslash( $_POST['currency'] ?? 'USD' ) ) ); // phpcs:ignore
                $inc    = strtoupper( sanitize_text_field( wp_unslash( $_POST['incoterm'] ?? '' ) ) ); // phpcs:ignore
                $hs     = sanitize_text_field( wp_unslash( $_POST['hs_code'] ?? '' ) ); // phpcs:ignore

                if ( $origin === '' || $dest === '' || $origin === $dest ) {
                        wp_send_json_error( __( 'País de origen y destino son obligatorios y deben ser distintos.', 'ltms' ) );
                }
                if ( $cif <= 0 ) {
                        wp_send_json_error( __( 'El valor CIF debe ser mayor a cero.', 'ltms' ) );
                }
                if ( ! in_array( $inc, self::ALLOWED_INCOTERMS, true ) ) {
                        $inc = LTMS_Core_Config::get( 'ltms_default_incoterm', 'DDU' );
                }

                // Aranceles y honorarios por país.
                $duty_rates = $this->parse_kv_pairs( (string) LTMS_Core_Config::get( 'ltms_customs_duty_rates', '' ) );
                $fees_cfg   = $this->parse_customs_fees( (string) LTMS_Core_Config::get( 'ltms_customs_fees', '' ) );
                $de_minimis = $this->parse_kv_pairs( (string) LTMS_Core_Config::get( 'ltms_de_minimis_thresholds', '' ) );

                $duty_rate_pct = isset( $duty_rates[ $dest ] ) ? (float) $duty_rates[ $dest ] : 0.0;
                $broker_flat   = $fees_cfg[ $dest ]['flat'] ?? 0.0;
                $broker_pct    = $fees_cfg[ $dest ]['pct']  ?? 0.0;
                $threshold     = isset( $de_minimis[ $dest ] ) ? (float) $de_minimis[ $dest ] : 0.0;

                // Verificar si aplica de minimis (exento de arancel).
                $de_minimis_applies = ( $threshold > 0 && $cif <= $threshold );

                // Conversión CIF → USD (si la moneda del pago no es USD).
                $cif_usd = $cif;
                if ( $curr !== 'USD' && class_exists( 'LTMS_FX_Rate_Provider' ) ) {
                        $converted = LTMS_FX_Rate_Provider::get_rate( $curr, 'USD' );
                        if ( $converted !== null && $converted > 0 ) {
                                $cif_usd = $cif * $converted;
                        }
                }

                // Cálculo de aranceles y honorarios.
                $duty_amount   = $de_minimis_applies ? 0.0 : round( $cif_usd * ( $duty_rate_pct / 100.0 ), 2 );
                $broker_amount = round( $broker_flat + ( $cif_usd * ( $broker_pct / 100.0 ) ), 2 );
                // IVA promedio 16% (MX) / 19% (CO) / 0% (US) — simple proxy basado en destino.
                $vat_rate = $this->get_vat_rate_for_country( $dest );
                $vat_base = $cif_usd + $duty_amount; // base gravable = CIF + arancel
                $vat_amount = round( $vat_base * ( $vat_rate / 100.0 ), 2 );
                $total_duties = round( $duty_amount + $vat_amount + $broker_amount, 2 );

                // Quién paga según incoterm.
                $paid_by = ( $inc === 'DDP' ) ? 'marketplace' : 'buyer';

                wp_send_json_success( [
                        'origin_country'      => $origin,
                        'destination_country' => $dest,
                        'currency'            => $curr,
                        'cif_value'           => round( $cif, 2 ),
                        'cif_value_usd'       => round( $cif_usd, 2 ),
                        'incoterm'            => $inc,
                        'hs_code'             => $hs,
                        'de_minimis_threshold'=> $threshold,
                        'de_minimis_applies'  => $de_minimis_applies,
                        'duty_rate_pct'       => $duty_rate_pct,
                        'duty_amount'         => $duty_amount,
                        'vat_rate_pct'        => $vat_rate,
                        'vat_amount'          => $vat_amount,
                        'broker_flat'         => $broker_flat,
                        'broker_pct'          => $broker_pct,
                        'broker_amount'       => $broker_amount,
                        'total_duties'        => $total_duties,
                        'paid_by'             => $paid_by,
                        'estimated_landed_cost'=> round( $cif_usd + $total_duties, 2 ),
                ] );
        }

        /**
         * AJAX: Estadísticas para el dashboard cross-border.
         *
         * Body params:
         *   - nonce  (string)
         *   - period (string) month|quarter|year|all (default month)
         *
         * @return void
         */
        public function ajax_get_cross_border_stats(): void {
                $this->verify();
                if ( ! current_user_can( self::REQUIRED_CAP ) ) {
                        wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
                }

                $period = sanitize_key( $_POST['period'] ?? 'month' ); // phpcs:ignore

                global $wpdb;
                $decls_table = $wpdb->prefix . 'lt_customs_declarations';
                $fx_table    = $wpdb->prefix . 'lt_fx_rates';

                // Verificar que las tablas existan (defensive — pueden no estar migradas aún).
                $decls_exists = $this->table_exists( $decls_table );
                $fx_exists    = $this->table_exists( $fx_table );

                list( $date_from, $date_to ) = $this->get_period_range( $period );
                $base_currency = LTMS_Core_Config::get( 'ltms_base_currency', 'USD' );

                // ── Summary cards ──────────────────────────────────────────
                $cross_border_orders = 0;
                $duties_collected    = 0.0;
                $fx_conversions      = 0;
                $avg_spread_revenue  = 0.0;

                if ( $decls_exists ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $cross_border_orders = (int) $wpdb->get_var(
                                $wpdb->prepare(
                                        "SELECT COUNT(*) FROM `{$decls_table}` WHERE DATE(created_at) BETWEEN %s AND %s",
                                        $date_from, $date_to
                                )
                        );

                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $duties_collected = (float) $wpdb->get_var(
                                $wpdb->prepare(
                                        "SELECT COALESCE(SUM(total_duties), 0) FROM `{$decls_table}` WHERE DATE(created_at) BETWEEN %s AND %s",
                                        $date_from, $date_to
                                )
                        );

                        // Avg spread revenue: tomamos el promedio de (cif_value * spread%).
                        $spread = (float) LTMS_Core_Config::get( 'ltms_fx_spread_percentage', 1.5 );
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $avg_cif = (float) $wpdb->get_var(
                                $wpdb->prepare(
                                        "SELECT COALESCE(AVG(cif_value), 0) FROM `{$decls_table}` WHERE DATE(created_at) BETWEEN %s AND %s",
                                        $date_from, $date_to
                                )
                        );
                        $avg_spread_revenue = round( $avg_cif * ( $spread / 100.0 ), 2 );
                }

                // FX conversions: número de órdenes que no estaban en la moneda base.
                if ( $decls_exists ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $fx_conversions = (int) $wpdb->get_var(
                                $wpdb->prepare(
                                        "SELECT COUNT(*) FROM `{$decls_table}` WHERE currency <> %s AND DATE(created_at) BETWEEN %s AND %s",
                                        $base_currency, $date_from, $date_to
                                )
                        );
                }

                // ── Country breakdown: top origins ─────────────────────────
                $top_origins = [];
                $top_destinations = [];
                if ( $decls_exists ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $top_origins = $wpdb->get_results(
                                $wpdb->prepare(
                                        "SELECT origin_country, COUNT(*) AS cnt, COALESCE(SUM(total_duties),0) AS duties
                                           FROM `{$decls_table}`
                                          WHERE DATE(created_at) BETWEEN %s AND %s
                                          GROUP BY origin_country
                                          ORDER BY cnt DESC LIMIT 10",
                                        $date_from, $date_to
                                ),
                                ARRAY_A
                        );
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $top_destinations = $wpdb->get_results(
                                $wpdb->prepare(
                                        "SELECT destination_country, COUNT(*) AS cnt, COALESCE(SUM(total_duties),0) AS duties
                                           FROM `{$decls_table}`
                                          WHERE DATE(created_at) BETWEEN %s AND %s
                                          GROUP BY destination_country
                                          ORDER BY cnt DESC LIMIT 10",
                                        $date_from, $date_to
                                ),
                                ARRAY_A
                        );
                }

                // ── Recent declarations ────────────────────────────────────
                $recent = [];
                if ( $decls_exists ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $recent = $wpdb->get_results(
                                $wpdb->prepare(
                                        "SELECT id, order_id, origin_country, destination_country, currency,
                                                cif_value, duty_amount, vat_amount, customs_fee, total_duties,
                                                incoterm, declaration_status, created_at
                                           FROM `{$decls_table}`
                                          WHERE DATE(created_at) BETWEEN %s AND %s
                                          ORDER BY created_at DESC LIMIT 50",
                                        $date_from, $date_to
                                ),
                                ARRAY_A
                        );
                }

                // ── FX rates snapshot ──────────────────────────────────────
                $fx_rows = [];
                if ( $fx_exists ) {
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                        $fx_rows = $wpdb->get_results(
                                "SELECT base_currency, quote_currency, rate, provider, is_manual, fetched_at
                                   FROM `{$fx_table}`
                                  ORDER BY base_currency, quote_currency LIMIT 200",
                                ARRAY_A
                        );
                }

                wp_send_json_success( [
                        'period'            => $period,
                        'date_from'         => $date_from,
                        'date_to'           => $date_to,
                        'base_currency'     => $base_currency,
                        'summary'           => [
                                'cross_border_orders' => $cross_border_orders,
                                'duties_collected'    => round( $duties_collected, 2 ),
                                'fx_conversions'      => $fx_conversions,
                                'avg_spread_revenue'  => $avg_spread_revenue,
                        ],
                        'top_origins'       => is_array( $top_origins ) ? $top_origins : [],
                        'top_destinations'  => is_array( $top_destinations ) ? $top_destinations : [],
                        'recent_declarations' => is_array( $recent ) ? array_map( [ $this, 'format_declaration_row' ], $recent ) : [],
                        'fx_rates'          => is_array( $fx_rows ) ? $fx_rows : [],
                        'tables_ready'      => [
                                'lt_fx_rates'             => $fx_exists,
                                'lt_customs_declarations' => $decls_exists,
                        ],
                ] );
        }

        /**
         * AJAX: Exporta las declaraciones cross-border a CSV.
         *
         * Body params:
         *   - nonce      (string)
         *   - period     (string) month|quarter|year|all
         *   - date_from  (string) Y-m-d (opcional, sobreescribe period)
         *   - date_to    (string) Y-m-d (opcional, sobreescribe period)
         *
         * @return void
         */
        public function ajax_export_cross_border_csv(): void {
                $this->verify();
                if ( ! current_user_can( self::REQUIRED_CAP ) ) {
                        wp_send_json_error( __( 'Permisos insuficientes.', 'ltms' ), 403 );
                }

                $period = sanitize_key( $_POST['period'] ?? 'month' ); // phpcs:ignore
                $override_from = sanitize_text_field( $_POST['date_from'] ?? '' ); // phpcs:ignore
                $override_to   = sanitize_text_field( $_POST['date_to'] ?? '' ); // phpcs:ignore

                if ( $override_from && $override_to ) {
                        $date_from = $override_from;
                        $date_to   = $override_to;
                } else {
                        list( $date_from, $date_to ) = $this->get_period_range( $period );
                }

                global $wpdb;
                $decls_table = $wpdb->prefix . 'lt_customs_declarations';
                if ( ! $this->table_exists( $decls_table ) ) {
                        wp_send_json_error( __( 'La tabla de declaraciones cross-border aún no ha sido creada. Ejecuta la migración.', 'ltms' ) );
                }

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $items = $wpdb->get_results(
                        $wpdb->prepare(
                                "SELECT id, order_id, origin_country, destination_country, hs_code,
                                        cif_value, duty_rate, duty_amount, vat_rate, vat_amount,
                                        customs_fee, other_taxes, total_duties, incoterm, paid_by,
                                        currency, declaration_number, declaration_status, created_at, cleared_at
                                   FROM `{$decls_table}`
                                  WHERE DATE(created_at) BETWEEN %s AND %s
                                  ORDER BY created_at DESC LIMIT 5000",
                                $date_from, $date_to
                        ),
                        ARRAY_A
                );

                // Sanitiza cada campo para prevenir formula injection en Excel/Sheets
                // y escapa comillas dobles como "" (RFC 4180).
                //
                // ADM-BUG-3 FIX (Task 65-C): the previous implementation
                // unconditionally prepended "'" to any value starting with
                // '=', '+', '-', '@', '\t', or '\r'. This corrupted legitimate
                // NEGATIVE NUMBERS (e.g. duty_amount = -123.45 → "'-123.45"),
                // which Excel/Sheets then interpreted as TEXT instead of a
                // number — silently breaking downstream financial analysis.
                //
                // The fix: pure-numeric values (integers, decimals, scientific
                // notation, with optional leading '-') bypass the formula
                // injection check — a number cannot be a formula. Only string
                // values that are not purely numeric get the leading-quote
                // escape for `=`, `+`, `-`, `@`, `\t`, `\r`.
                $csv_field = static function ( $v ): string {
                        $v = (string) $v;
                        $v = str_replace( '"', '""', $v );
                        if ( '' === $v ) {
                                return $v;
                        }
                        // Pure numeric (integer, decimal, scientific notation, with
                        // optional leading '-') → no formula injection risk.
                        if ( preg_match( '/^-?\d++(?:\.\d++)?(?:[eE][+-]?\d++)?$/', $v ) ) {
                                return $v;
                        }
                        // Non-numeric value — apply formula injection protection
                        // for the chars that Excel/Sheets treat as formula triggers.
                        if ( preg_match( '/^[=+\-@]/', $v ) || "\t" === $v[0] || "\r" === $v[0] ) {
                                $v = "'" . $v;
                        }
                        return $v;
                };

                $csv  = "\xEF\xBB\xBF"; // BOM UTF-8 para Excel.
                $csv .= "ID,Order,Origin,Destination,HS Code,CIF Value,Duty Rate,Duty Amount,VAT Rate,VAT Amount,Customs Fee,Other Taxes,Total Duties,Incoterm,Paid By,Currency,Declaration Number,Status,Created At,Cleared At\n";
                foreach ( (array) $items as $row ) {
                        $csv .= sprintf(
                                '"%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s","%s"' . "\n",
                                $csv_field( $row['id'] ?? '' ),
                                $csv_field( $row['order_id'] ?? '' ),
                                $csv_field( $row['origin_country'] ?? '' ),
                                $csv_field( $row['destination_country'] ?? '' ),
                                $csv_field( $row['hs_code'] ?? '' ),
                                $csv_field( $row['cif_value'] ?? 0 ),
                                $csv_field( $row['duty_rate'] ?? 0 ),
                                $csv_field( $row['duty_amount'] ?? 0 ),
                                $csv_field( $row['vat_rate'] ?? 0 ),
                                $csv_field( $row['vat_amount'] ?? 0 ),
                                $csv_field( $row['customs_fee'] ?? 0 ),
                                $csv_field( $row['other_taxes'] ?? 0 ),
                                $csv_field( $row['total_duties'] ?? 0 ),
                                $csv_field( $row['incoterm'] ?? '' ),
                                $csv_field( $row['paid_by'] ?? '' ),
                                $csv_field( $row['currency'] ?? '' ),
                                $csv_field( $row['declaration_number'] ?? '' ),
                                $csv_field( $row['declaration_status'] ?? '' ),
                                $csv_field( $row['created_at'] ?? '' ),
                                $csv_field( $row['cleared_at'] ?? '' )
                        );
                }

                $this->log_info(
                        'CROSS_BORDER_EXPORT',
                        sprintf( 'Exportación CSV de %d declaraciones cross-border por admin #%d', count( $items ), get_current_user_id() ),
                        [ 'count' => count( $items ), 'period' => $period ]
                );

                wp_send_json_success( [
                        'csv'      => base64_encode( $csv ), // phpcs:ignore
                        'filename' => 'ltms-cross-border-' . gmdate( 'Y-m-d-His' ) . '.csv',
                        'count'    => count( $items ),
                ] );
        }

        // ───────────────────────────────────────────────────────────────
        //  HELPERS
        // ───────────────────────────────────────────────────────────────

        /**
         * Verifica nonce AJAX de manera centralizada.
         *
         * @return void
         */
        private function verify(): void {
                check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        }

        /**
         * Obtiene la lista de monedas habilitadas desde la configuración.
         *
         * @return array<int,string>
         */
        private function get_enabled_currencies(): array {
                $raw = LTMS_Core_Config::get( 'ltms_enabled_currencies', [ 'COP', 'MXN', 'USD' ] );
                if ( is_array( $raw ) ) {
                        $currencies = $raw;
                } else {
                        $decoded = json_decode( (string) $raw, true );
                        $currencies = is_array( $decoded ) ? $decoded : [ 'COP', 'MXN', 'USD' ];
                }
                // Sanitizar: solo códigos de 3 letras en mayúsculas.
                $clean = [];
                foreach ( $currencies as $code ) {
                        $code = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $code ), 0, 3 ) );
                        if ( $code && in_array( $code, self::SUPPORTED_CURRENCIES, true ) ) {
                                $clean[] = $code;
                        }
                }
                return $clean ? $clean : [ 'USD' ];
        }

        /**
         * Obtiene la tasa FX de la tabla lt_fx_rates (fallback si el provider no está).
         *
         * @param string $from Moneda base.
         * @param string $to   Moneda quote.
         * @return float|null
         */
        private function get_rate_from_db( string $from, string $to ): ?float {
                global $wpdb;
                $table = $wpdb->prefix . 'lt_fx_rates';
                if ( ! $this->table_exists( $table ) ) {
                        return null;
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $rate = $wpdb->get_var(
                        $wpdb->prepare(
                                "SELECT rate FROM `{$table}` WHERE base_currency = %s AND quote_currency = %s LIMIT 1",
                                strtoupper( $from ),
                                strtoupper( $to )
                        )
                );
                return $rate !== null ? (float) $rate : null;
        }

        /**
         * Indica si un par FX tiene override manual en la configuración.
         *
         * @param string $from Moneda base.
         * @param string $to   Moneda quote.
         * @return bool
         */
        private function is_manual_override( string $from, string $to ): bool {
                $overrides = $this->parse_fx_overrides( (string) LTMS_Core_Config::get( 'ltms_fx_manual_overrides', '' ) );
                return isset( $overrides[ $from . '_' . $to ] );
        }

        /**
         * Obtiene la fecha de la última actualización de la tasa en lt_fx_rates.
         *
         * @param string $from Moneda base.
         * @param string $to   Moneda quote.
         * @return string Fecha Y-m-d H:i:s o '' si no hay registro.
         */
        private function get_rate_fetched_at( string $from, string $to ): string {
                global $wpdb;
                $table = $wpdb->prefix . 'lt_fx_rates';
                if ( ! $this->table_exists( $table ) ) {
                        return '';
                }
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $ts = $wpdb->get_var(
                        $wpdb->prepare(
                                "SELECT fetched_at FROM `{$table}` WHERE base_currency = %s AND quote_currency = %s LIMIT 1",
                                strtoupper( $from ),
                                strtoupper( $to )
                        )
                );
                return $ts ? (string) $ts : '';
        }

        /**
         * Parsea un texto de pares clave=valor (uno por línea) a un array asociativo.
         * Usado para ltms_customs_duty_rates, ltms_de_minimis_thresholds.
         *
         * @param string $raw Texto crudo (ej: "US=3.4\nBR=11.0").
         * @return array<string,string>
         */
        private function parse_kv_pairs( string $raw ): array {
                $out = [];
                $lines = preg_split( '/\r\n|\r|\n/', $raw );
                if ( ! is_array( $lines ) ) {
                        return $out;
                }
                foreach ( $lines as $line ) {
                        $line = trim( $line );
                        if ( $line === '' || strpos( $line, '=' ) === false ) {
                                continue;
                        }
                        list( $k, $v ) = array_map( 'trim', explode( '=', $line, 2 ) );
                        $k = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $k ) );
                        if ( $k !== '' && $v !== '' ) {
                                $out[ $k ] = $v;
                        }
                }
                return $out;
        }

        /**
         * Parsea las overrides FX manuales del textarea de configuración.
         * Formato: USD_COP=3800 (una línea por par).
         *
         * @param string $raw Texto crudo.
         * @return array<string,float> Clave "FROM_TO" → tasa.
         */
        private function parse_fx_overrides( string $raw ): array {
                $out = [];
                $lines = preg_split( '/\r\n|\r|\n/', $raw );
                if ( ! is_array( $lines ) ) {
                        return $out;
                }
                foreach ( $lines as $line ) {
                        $line = trim( $line );
                        if ( $line === '' || strpos( $line, '=' ) === false ) {
                                continue;
                        }
                        list( $pair, $rate ) = array_map( 'trim', explode( '=', $line, 2 ) );
                        $pair = strtoupper( preg_replace( '/[^A-Za-z_]/', '', $pair ) );
                        $rate = (float) $rate;
                        if ( $pair !== '' && $rate > 0 ) {
                                $out[ $pair ] = $rate;
                        }
                }
                return $out;
        }

        /**
         * Parsea las tarifas del broker aduanero por país.
         * Formato: US=flat:6.50,pct:0.346
         *
         * @param string $raw Texto crudo.
         * @return array<string,array{flat:float,pct:float}>
         */
        private function parse_customs_fees( string $raw ): array {
                $out = [];
                $lines = preg_split( '/\r\n|\r|\n/', $raw );
                if ( ! is_array( $lines ) ) {
                        return $out;
                }
                foreach ( $lines as $line ) {
                        $line = trim( $line );
                        if ( $line === '' || strpos( $line, '=' ) === false ) {
                                continue;
                        }
                        list( $cc, $cfg ) = array_map( 'trim', explode( '=', $line, 2 ) );
                        $cc = strtoupper( preg_replace( '/[^A-Za-z]/', '', $cc ) );
                        if ( $cc === '' ) {
                                continue;
                        }
                        $flat = 0.0;
                        $pct  = 0.0;
                        // Separar componentes por coma.
                        foreach ( explode( ',', $cfg ) as $part ) {
                                $part = trim( $part );
                                if ( strpos( $part, 'flat:' ) === 0 ) {
                                        $flat = (float) substr( $part, 5 );
                                } elseif ( strpos( $part, 'pct:' ) === 0 ) {
                                        $pct = (float) substr( $part, 4 );
                                }
                        }
                        $out[ $cc ] = [ 'flat' => $flat, 'pct' => $pct ];
                }
                return $out;
        }

        /**
         * Devuelve el IVA promedio aplicable a un país destino.
         * Heurística simple — el admin puede refinarla con ltms_customs_duty_rates.
         *
         * @param string $country_code ISO 3166-1 alpha-2.
         * @return float
         */
        private function get_vat_rate_for_country( string $country_code ): float {
                $rates = [
                        'CO' => 19.0,
                        'MX' => 16.0,
                        'BR' => 17.0,
                        'AR' => 21.0,
                        'CL' => 19.0,
                        'PE' => 18.0,
                        'EC' => 12.0,
                        'US' => 0.0,
                        'CA' => 5.0,
                        'GB' => 20.0,
                        'ES' => 21.0,
                        'DE' => 19.0,
                        'FR' => 20.0,
                        'IT' => 22.0,
                        'PT' => 23.0,
                ];
                return $rates[ strtoupper( $country_code ) ] ?? 0.0;
        }

        /**
         * Calcula el rango de fechas Y-m-d para un período dado.
         *
         * @param string $period month|quarter|year|all.
         * @return array{0:string,1:string}
         */
        private function get_period_range( string $period ): array {
                $today = gmdate( 'Y-m-d' );
                switch ( $period ) {
                        case 'quarter':
                                $from = gmdate( 'Y-m-d', strtotime( '-3 months', strtotime( $today ) ) );
                                return [ $from, $today ];
                        case 'year':
                                $from = gmdate( 'Y-m-d', strtotime( '-1 year', strtotime( $today ) ) );
                                return [ $from, $today ];
                        case 'all':
                                return [ '2000-01-01', $today ];
                        case 'month':
                        default:
                                $from = gmdate( 'Y-m-01' );
                                return [ $from, $today ];
                }
        }

        /**
         * Verifica si una tabla existe en la BD.
         *
         * @param string $table_name Nombre completo (con prefijo).
         * @return bool
         */
        private function table_exists( string $table_name ): bool {
                global $wpdb;
                return (int) $wpdb->get_var(
                        $wpdb->prepare(
                                "SELECT COUNT(*) FROM information_schema.TABLES
                                  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                                DB_NAME,
                                $table_name
                        )
                ) > 0;
        }

        /**
         * Formatea una fila de declaración aduanera para respuesta JSON.
         * Aplica casting de tipos y normaliza fechas vacías.
         *
         * @param array<string,mixed> $row Fila cruda de la DB.
         * @return array<string,mixed>
         */
        private function format_declaration_row( array $row ): array {
                return [
                        'id'                  => (int) ( $row['id'] ?? 0 ),
                        'order_id'            => (int) ( $row['order_id'] ?? 0 ),
                        'origin_country'      => $row['origin_country'] ?? '',
                        'destination_country' => $row['destination_country'] ?? '',
                        'currency'            => $row['currency'] ?? 'USD',
                        'cif_value'           => (float) ( $row['cif_value'] ?? 0 ),
                        'duty_amount'         => (float) ( $row['duty_amount'] ?? 0 ),
                        'vat_amount'          => (float) ( $row['vat_amount'] ?? 0 ),
                        'customs_fee'         => (float) ( $row['customs_fee'] ?? 0 ),
                        'total_duties'        => (float) ( $row['total_duties'] ?? 0 ),
                        'incoterm'            => $row['incoterm'] ?? 'DDU',
                        'declaration_status'  => $row['declaration_status'] ?? 'pending',
                        'created_at'          => $row['created_at'] ?? '',
                ];
        }
}
