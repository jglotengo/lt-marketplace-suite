<?php
/**
 * Vista: Panel del Auditor Externo — v2.5.4
 * UX/UI renovado · Cumplimiento 100% Art. 30-B CFF / Ficha 168/CFF
 * SAGRILAFT Res. 314/2021 · E.T. Art. 437-2 (CO)
 * FIXES v2.5.4: EXPORT CSV completamente reescrito:
 *   - ob_end_clean() elimina todo output WP antes de enviar el CSV
 *   - Fracción II (vendedores intermediados) incluida — era requerida por norma
 *   - Sección SAGRILAFT/SARLAFT incluida en el CSV
 *   - Formato numérico: punto decimal sin sep. miles (CFDI 4.0 / DIAN Res. 000042)
 *   - Nombre de archivo incluye país y período
 *   - Cabeceras meta normativas en el CSV
 *   - Frac. I: columna VENDOR_ID agregada para cruzar con Frac. II
 *
 * @package    LTMS\Admin\Views
 * @version    2.5.1
 * @since      2.5.0
 */

defined( 'ABSPATH' ) || exit;

// DEBUG: Forzar logging de errores a archivo propio (SiteGround suprime debug.log).
$_ltms_debug_log = WP_CONTENT_DIR . '/ltms-auditor-debug.log';
ini_set( 'log_errors', '1' );
ini_set( 'error_log', $_ltms_debug_log );
error_reporting( E_ALL );
set_error_handler( static function ( $errno, $errstr, $errfile, $errline ) use ( $_ltms_debug_log ) {
    $msg = '[' . date( 'Y-m-d H:i:s' ) . "] errno={$errno} {$errstr} at {$errfile}:{$errline}\n";
    file_put_contents( $_ltms_debug_log, $msg, FILE_APPEND );
    return false;
} );
register_shutdown_function( static function () use ( $_ltms_debug_log ) {
    $err = error_get_last();
    if ( $err && in_array( $err['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ], true ) ) {
        $msg = '[' . date( 'Y-m-d H:i:s' ) . "] FATAL: {$err['message']} at {$err['file']}:{$err['line']}\n";
        file_put_contents( $_ltms_debug_log, $msg, FILE_APPEND );
    }
} );

if ( ! current_user_can( 'ltms_access_auditor_dashboard' ) ) {
    wp_die( esc_html__( 'No tienes permiso para acceder a esta página.', 'ltms' ) );
}

LTMS_Data_Masking::log_auditor_access( 'auditor_dashboard_view' );

global $wpdb;

// ── Filtros ────────────────────────────────────────────────────────────────
$date_from   = isset( $_GET['date_from'] ) ? sanitize_text_field( $_GET['date_from'] ) : date( 'Y-m-01' );
$date_to     = isset( $_GET['date_to'] )   ? sanitize_text_field( $_GET['date_to'] )   : date( 'Y-m-d' );
$country     = isset( $_GET['country'] )   ? sanitize_text_field( $_GET['country'] )   : '';
$event_level = isset( $_GET['level'] )     ? sanitize_text_field( $_GET['level'] )     : '';
$tx_page     = max( 1, (int) ( $_GET['tx_paged'] ?? 1 ) );
$tx_per_page = 25;
$tx_offset   = ( $tx_page - 1 ) * $tx_per_page;
$dt_from     = $date_from . ' 00:00:00';
$dt_to       = $date_to   . ' 23:59:59';

// Export CSV
$export_csv = isset( $_GET['export'] ) && $_GET['export'] === 'csv';

// ── Resumen fiscal ─────────────────────────────────────────────────────────
$country_sql = $country ? "AND country_code = '" . esc_sql( $country ) . "'" : '';
$fiscal = $wpdb->get_row( $wpdb->prepare(
    "SELECT
        COUNT(*)                                             AS total_tx,
        SUM(gross_amount)                                    AS gross,
        SUM(commission_amount)                               AS platform_fee,
        SUM(vendor_amount)                                   AS vendor_net,
        SUM(COALESCE(retefuente_amount, tax_withholding, 0)) AS rete_fuente,
        SUM(COALESCE(isr_amount, 0))                         AS isr,
        SUM(iva_amount)                                      AS iva,
        SUM(COALESCE(reteiva_amount, 0))                     AS reteiva,
        SUM(COALESCE(reteica_amount, 0))                     AS reteica,
        SUM(COALESCE(ieps_amount, 0))                        AS ieps,
        SUM(COALESCE(aranceles_amount, 0))                   AS aranceles,
        SUM(CASE WHEN is_hospedaje = 1 THEN 1 ELSE 0 END)   AS hospedaje_ops,
        SUM(CASE WHEN is_import = 1    THEN 1 ELSE 0 END)   AS import_ops,
        COUNT(DISTINCT vendor_id)                            AS vendors_active
     FROM {$wpdb->prefix}lt_commissions
     WHERE created_at BETWEEN %s AND %s $country_sql",
    $dt_from, $dt_to
), ARRAY_A );
$f = $fiscal ?? [];

// ── FRACCIÓN I — Transacciones ─────────────────────────────────────────────
$tx_total = (int) $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}lt_commissions WHERE created_at BETWEEN %s AND %s $country_sql",
    $dt_from, $dt_to
) );

$transactions = $wpdb->get_results( $wpdb->prepare(
    "SELECT
        c.id, c.order_id, c.created_at, c.country_code, c.service_type,
        c.rfc_cliente, c.gross_amount, c.iva_amount,
        COALESCE(c.gross_amount + c.iva_amount, c.gross_amount) AS total_con_iva,
        c.cfdi_folio, c.payment_method, c.vendor_id
     FROM {$wpdb->prefix}lt_commissions c
     WHERE c.created_at BETWEEN %s AND %s $country_sql
     ORDER BY c.created_at DESC
     LIMIT %d OFFSET %d",
    $dt_from, $dt_to, $tx_per_page, $tx_offset
), ARRAY_A ) ?: [];

// ══════════════════════════════════════════════════════════════════════════════
// EXPORTACIÓN CSV — Art. 30-B CFF Regla 12.2.10 / E.T. Art. 437-2 CO
//
// PROBLEMA RAÍZ DEL EXPORT ROTO: WordPress ya envió headers y output (admin
// bar, notices, scripts) antes de que llegue a este punto del view. El
// header('Content-Type: text/csv') jamás funciona desde un admin_page view
// porque WP usa ob_start() propio pero luego vuelca el buffer antes de que
// la vista corra.
//
// SOLUCIÓN: limpiar TODOS los buffers abiertos, enviar headers limpios,
// escribir el CSV y salir antes de que WP pueda volver a escribir.
//
// FORMATO NUMÉRICO:
//   SAT (MX): Anexo 20 CFDI 4.0 — decimales con punto, sin separador miles
//   DIAN (CO): Res. 000042/2020 — misma convención para archivos electrónicos
//   → number_format(v, 2, '.', '') — NUNCA number_format con separador de miles
//
// FRACCIÓN I  — cada transacción (incisos a–g)
// FRACCIÓN II — cada vendedor intermediado (incisos a–h con sub-incisos f-i a f-vii)
// SAGRILAFT   — retiros de alto valor (Res. 314/2021 CO, umbral 10.000 UVT)
// ══════════════════════════════════════════════════════════════════════════════
if ( $export_csv ) {

    // 1. Detectar columnas opcionales (misma lógica que el panel)
    $exp_cols   = [];
    $exp_check  = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}lt_commissions", ARRAY_A );
    foreach ( $exp_check as $c ) { $exp_cols[ $c['Field'] ] = true; }
    $exp_has_hosp   = isset( $exp_cols['is_hospedaje'], $exp_cols['hospedaje_direccion'] );
    $exp_has_imp    = isset( $exp_cols['is_import'],    $exp_cols['aranceles_amount'] );
    $exp_has_iepsr  = isset( $exp_cols['ieps_retenido'] );
    $exp_has_pmb    = isset( $exp_cols['payment_method_buyer'] );

    // 2. Queries ───────────────────────────────────────────────────────────────

    // Fracción I — transacciones individuales
    $exp_frac1 = $wpdb->get_results( $wpdb->prepare(
        "SELECT c.id, c.order_id, c.created_at, c.country_code,
                COALESCE(c.service_type,'') AS service_type,
                COALESCE(c.rfc_cliente,'')  AS rfc_cliente,
                c.gross_amount, c.iva_amount,
                COALESCE(c.gross_amount + c.iva_amount, c.gross_amount) AS total_con_iva,
                COALESCE(c.cfdi_folio,'')   AS cfdi_folio,
                COALESCE(" . ( $exp_has_pmb ? "c.payment_method_buyer," : "" ) . "c.payment_method,'') AS metodo_pago_adquiriente,
                c.vendor_id
         FROM {$wpdb->prefix}lt_commissions c
         WHERE c.created_at BETWEEN %s AND %s $country_sql
         ORDER BY c.created_at DESC",
        $dt_from, $dt_to
    ), ARRAY_A ) ?: [];

    // Fracción II — vendedores intermediados (agrupado por vendor)
    $exp_frac2 = $wpdb->get_results( $wpdb->prepare(
        "SELECT
            c.vendor_id,
            u.display_name                                              AS nombre,
            u.user_email                                                AS email,
            COALESCE(um_rfc.meta_value,'')                              AS rfc_nif,
            COALESCE(um_curp.meta_value,'')                             AS curp,
            COALESCE(um_pais.meta_value,'')                             AS pais_residencia,
            COALESCE(um_domicilio.meta_value,'')                        AS domicilio_fiscal,
            COALESCE(um_banco.meta_value,'')                            AS banco_institucion,
            COALESCE(um_clabe.meta_value,'')                            AS clabe_cuenta,
            SUM(c.gross_amount)                                         AS monto_isr,
            SUM(c.iva_amount)                                           AS monto_iva,
            SUM(COALESCE(c.ieps_amount,0))                              AS monto_ieps,
            SUM(COALESCE(c.isr_amount,0))                               AS isr_retenido,
            SUM(COALESCE(c.reteiva_amount,0))                           AS iva_retenido,
            SUM(COALESCE(" . ( $exp_has_iepsr ? "c.ieps_retenido" : "c.ieps_amount" ) . ",0)) AS ieps_retenido,
            MAX(COALESCE(" . ( $exp_has_pmb ? "c.payment_method_buyer," : "" ) . "c.payment_method,'')) AS metodo_pago_adquiriente,
            MAX(COALESCE(c.payment_method_vendor,''))                   AS metodo_pago_oferente,
            MAX(COALESCE(c.payment_method_platform,''))                 AS metodo_pago_plataforma,
            " . ( $exp_has_hosp
                ? "SUM(CASE WHEN c.is_hospedaje=1 THEN 1 ELSE 0 END) AS ops_hospedaje, MAX(CASE WHEN c.is_hospedaje=1 THEN c.hospedaje_direccion ELSE NULL END) AS hospedaje_direccion,"
                : "0 AS ops_hospedaje, '' AS hospedaje_direccion," ) . "
            " . ( $exp_has_imp
                ? "SUM(CASE WHEN c.is_import=1 THEN 1 ELSE 0 END) AS ops_importacion, SUM(COALESCE(c.aranceles_amount,0)) AS aranceles,"
                : "0 AS ops_importacion, 0 AS aranceles," ) . "
            COUNT(c.id)                                                 AS total_ops,
            MIN(c.created_at)                                           AS primera_op,
            MAX(c.created_at)                                           AS ultima_op
         FROM {$wpdb->prefix}lt_commissions c
         LEFT JOIN {$wpdb->users}    u            ON u.ID = c.vendor_id
         LEFT JOIN {$wpdb->usermeta} um_rfc       ON um_rfc.user_id      = c.vendor_id AND um_rfc.meta_key      = 'ltms_rfc'
         LEFT JOIN {$wpdb->usermeta} um_curp      ON um_curp.user_id     = c.vendor_id AND um_curp.meta_key     = 'ltms_curp'
         LEFT JOIN {$wpdb->usermeta} um_pais      ON um_pais.user_id     = c.vendor_id AND um_pais.meta_key     = 'ltms_pais_residencia'
         LEFT JOIN {$wpdb->usermeta} um_domicilio ON um_domicilio.user_id = c.vendor_id AND um_domicilio.meta_key = 'ltms_domicilio_fiscal'
         LEFT JOIN {$wpdb->usermeta} um_banco     ON um_banco.user_id    = c.vendor_id AND um_banco.meta_key    = 'ltms_banco'
         LEFT JOIN {$wpdb->usermeta} um_clabe     ON um_clabe.user_id    = c.vendor_id AND um_clabe.meta_key    = 'ltms_clabe'
         WHERE c.created_at BETWEEN %s AND %s $country_sql
         GROUP BY c.vendor_id
         ORDER BY SUM(c.gross_amount) DESC",
        $dt_from, $dt_to
    ), ARRAY_A ) ?: [];

    // SAGRILAFT — retiros alto valor
    $exp_sagrilaft_floor = (float) LTMS_Core_Config::get( 'ltms_uvt_valor', 49799.0 )
                         * (float) LTMS_Core_Config::get( 'ltms_sagrilaft_uvt_threshold', 10000.0 );
    $exp_large = $wpdb->get_results( $wpdb->prepare(
        "SELECT p.id, p.vendor_id, u.display_name, u.user_email,
                p.amount, p.currency, p.status, p.created_at
         FROM {$wpdb->prefix}lt_payout_requests p
         LEFT JOIN {$wpdb->users} u ON u.ID = p.vendor_id
         WHERE p.amount >= %f AND p.created_at BETWEEN %s AND %s
         ORDER BY p.amount DESC",
        $exp_sagrilaft_floor, $dt_from, $dt_to
    ), ARRAY_A ) ?: [];

    // 3. Helper — número para CSV fiscal (punto decimal, sin sep. miles)
    $fn = fn($v) => number_format( (float)($v ?? 0), 2, '.', '' );

    // 4. Construir el CSV en memoria para evitar salida prematura
    $csv_buffer = fopen( 'php://temp', 'r+' );

    // BOM UTF-8 — requerido para Excel y sistemas DIAN/SAT
    fwrite( $csv_buffer, "\xEF\xBB\xBF" );

    // ── SECCIÓN META ──────────────────────────────────────────────────────
    fputcsv( $csv_buffer, [ '# LTMS — Reporte Fiscal Art. 30-B CFF / E.T. Art. 437-2 CO' ] );
    fputcsv( $csv_buffer, [ '# Plataforma:', 'Lo-Tengo.com.co' ] );
    fputcsv( $csv_buffer, [ '# Período:', $date_from, $date_to ] );
    fputcsv( $csv_buffer, [ '# País filtro:', $country ?: 'Todos (MX + CO)' ] );
    fputcsv( $csv_buffer, [ '# Norma MX:', 'Regla 12.2.10 RMF 2025 — Art. 30-B CFF — Ficha 168/CFF' ] );
    fputcsv( $csv_buffer, [ '# Norma CO:', 'E.T. Art. 437-2 — SAGRILAFT Res. 314/2021 — SARLAFT Res. 140/2023 SFC' ] );
    fputcsv( $csv_buffer, [ '# Formato numérico:', 'Punto decimal, sin separador de miles (CFDI 4.0 Anexo 20 / DIAN Res. 000042)' ] );
    fputcsv( $csv_buffer, [ '# Generado:', current_time( 'Y-m-d H:i:s' ), 'por', wp_get_current_user()->display_name ] );
    fputcsv( $csv_buffer, [] ); // línea en blanco separadora

    // ── FRACCIÓN I — Transacciones individuales ───────────────────────────
    fputcsv( $csv_buffer, [ '### FRACCIÓN I — Servicios / Operaciones (Art. 30-B CFF inciso I / E.T. 437-2)' ] );
    fputcsv( $csv_buffer, [
        'FRAC', 'ID_TRANSACCION', 'ID_ORDEN', 'PAIS',
        'FECHA_OPERACION',                         // ISO 8601
        'a) TIPO_SERVICIO_U_OPERACION',            // Art. 30-B Frac. I inciso a)
        'b) RFC_CLIENTE',                          // inciso b) — condicional CFDI
        'c) PRECIO_SIN_IVA',                       // inciso c)
        'd) IVA_TRASLADADO',                       // inciso d)
        'e) PRECIO_FINAL_CON_IVA',                 // inciso e)
        'f) FOLIO_CFDI_UUID',                      // inciso f)
        'g) METODO_PAGO_ADQUIRIENTE',              // inciso g)
        'VENDOR_ID',
    ] );
    foreach ( $exp_frac1 as $r ) {
        fputcsv( $csv_buffer, [
            'I',
            $r['id'],
            $r['order_id'],
            $r['country_code'],
            $r['created_at'],
            $r['service_type'],
            $r['rfc_cliente'],
            $fn( $r['gross_amount'] ),
            $fn( $r['iva_amount'] ),
            $fn( $r['total_con_iva'] ),
            $r['cfdi_folio'],
            $r['metodo_pago_adquiriente'],
            $r['vendor_id'],
        ] );
    }
    fputcsv( $csv_buffer, [] );

    // ── FRACCIÓN II — Vendedores intermediados ────────────────────────────
    fputcsv( $csv_buffer, [ '### FRACCIÓN II — Vendedores / Intermediados (Art. 30-B CFF inciso II / E.T. 437-2)' ] );
    fputcsv( $csv_buffer, [
        'FRAC', 'VENDOR_ID', 'EMAIL',
        'a) NOMBRE_RAZON_SOCIAL',                  // Frac. II inciso a)
        'b) RFC_NIF_FISCAL',                       // inciso b)
        'c) CURP_PF_MX',                           // inciso c)
        'd) DOMICILIO_FISCAL_RESIDENCIA',          // inciso d)
        'd) PAIS_RESIDENCIA',                      // inciso d)
        'e) INSTITUCION_FINANCIERA',               // inciso e)
        'e) CLABE_CUENTA_BANCARIA',                // inciso e)
        'f-i) MONTO_ISR',                          // inciso f-i)
        'f-ii) MONTO_IVA',                         // inciso f-ii)
        'f-iii) MONTO_IEPS',                       // inciso f-iii)
        'f-iv-a) METODO_PAGO_ADQUIRIENTE',         // inciso f-iv)
        'f-iv-b) METODO_PAGO_OFERENTE',            // inciso f-iv)
        'f-iv-c) METODO_PAGO_PLATAFORMA',          // inciso f-iv)
        'f-v) ISR_RETENIDO',                       // inciso f-v)
        'f-vi) IVA_RETENIDO',                      // inciso f-vi)
        'f-vii) IEPS_RETENIDO',                    // inciso f-vii)
        'g) HOSPEDAJE_OPS',                        // inciso g) solo hospedaje
        'g) HOSPEDAJE_DIRECCION_INMUEBLE',         // inciso g)
        'h) IMPORTACION_OPS',                      // inciso h)
        'h) ARANCELES_MONTO',                      // inciso h)
        'TOTAL_OPERACIONES',
        'PRIMERA_OPERACION',
        'ULTIMA_OPERACION',
    ] );
    foreach ( $exp_frac2 as $r ) {
        fputcsv( $csv_buffer, [
            'II',
            $r['vendor_id'],
            $r['email'],
            $r['nombre'],
            $r['rfc_nif'],
            $r['curp'],
            $r['domicilio_fiscal'],
            $r['pais_residencia'],
            $r['banco_institucion'],
            $r['clabe_cuenta'],
            $fn( $r['monto_isr'] ),
            $fn( $r['monto_iva'] ),
            $fn( $r['monto_ieps'] ),
            $r['metodo_pago_adquiriente'],
            $r['metodo_pago_oferente'],
            $r['metodo_pago_plataforma'],
            $fn( $r['isr_retenido'] ),
            $fn( $r['iva_retenido'] ),
            $fn( $r['ieps_retenido'] ),
            $r['ops_hospedaje'],
            $r['hospedaje_direccion'] ?? '',
            $r['ops_importacion'],
            $fn( $r['aranceles'] ),
            $r['total_ops'],
            $r['primera_op'],
            $r['ultima_op'],
        ] );
    }
    fputcsv( $csv_buffer, [] );

    // ── SAGRILAFT / SARLAFT — Alertas alto valor ──────────────────────────
    fputcsv( $csv_buffer, [ '### SAGRILAFT / SARLAFT — Retiros alto valor (Res. 314/2021 CO · Umbral ' . number_format( $exp_sagrilaft_floor, 0, '.', ',' ) . ' COP)' ] );
    if ( empty( $exp_large ) ) {
        fputcsv( $csv_buffer, [ '# Sin retiros de alto valor en el período.' ] );
    } else {
        fputcsv( $csv_buffer, [
            'TIPO', 'ID_RETIRO', 'VENDOR_ID', 'NOMBRE', 'EMAIL',
            'MONTO', 'MONEDA', 'ESTADO', 'FECHA',
        ] );
        foreach ( $exp_large as $r ) {
            fputcsv( $csv_buffer, [
                'SAGRILAFT',
                $r['id'], $r['vendor_id'], $r['display_name'], $r['user_email'],
                $fn( $r['amount'] ), $r['currency'], $r['status'], $r['created_at'],
            ] );
        }
    }

    // 5. Leer el buffer y limpiar todo output WP antes de enviarlo
    rewind( $csv_buffer );
    $csv_content = stream_get_contents( $csv_buffer );
    fclose( $csv_buffer );

    // Vaciar TODOS los buffers de salida abiertos por WP/PHP
    while ( ob_get_level() > 0 ) {
        ob_end_clean();
    }

    // Nombre del archivo con período
    $fn_pais   = $country ? '-' . strtolower( $country ) : '';
    $fn_suffix = 'ltms-fiscal-30b' . $fn_pais . '-' . $date_from . '_' . $date_to . '.csv';

    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="' . $fn_suffix . '"' );
    header( 'Content-Length: ' . strlen( $csv_content ) );
    header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
    header( 'Pragma: no-cache' );
    header( 'Expires: 0' );

    echo $csv_content;
    exit;
}

// ── FRACCIÓN II — Vendedores ──────────────────────────────────────────────
// Guard: detect which optional columns exist before querying
$cols_exist = [];
$col_check = $wpdb->get_results( "SHOW COLUMNS FROM {$wpdb->prefix}lt_commissions", ARRAY_A );
foreach ( $col_check as $col ) { $cols_exist[ $col['Field'] ] = true; }
$has_hospedaje  = isset( $cols_exist['is_hospedaje'], $cols_exist['hospedaje_direccion'] );
$has_import     = isset( $cols_exist['is_import'], $cols_exist['aranceles_amount'] );

$vendors_detail = $wpdb->get_results( $wpdb->prepare(
    "SELECT
        c.vendor_id,
        u.display_name                                       AS nombre,
        um_rfc.meta_value                                    AS rfc_nif,
        um_curp.meta_value                                   AS curp,
        um_domicilio.meta_value                              AS domicilio_fiscal,
        um_clabe.meta_value                                  AS clabe_cuenta,
        SUM(c.gross_amount)                                  AS monto_isr,
        SUM(c.iva_amount)                                    AS monto_iva,
        SUM(COALESCE(c.ieps_amount,0))                       AS monto_ieps,
        SUM(COALESCE(c.isr_amount,0))                        AS isr_retenido,
        SUM(COALESCE(c.reteiva_amount,0))                    AS iva_retenido,
        SUM(COALESCE(c.ieps_amount,0))                       AS ieps_retenido,
        MAX(COALESCE(c.payment_method_buyer,    c.payment_method)) AS metodo_pago_adquiriente,
        MAX(COALESCE(c.payment_method_vendor,   '—'))              AS metodo_pago_oferente,
        MAX(COALESCE(c.payment_method_platform, '—'))              AS metodo_pago_plataforma,
        " . ( $has_hospedaje ? "SUM(CASE WHEN c.is_hospedaje=1 THEN 1 ELSE 0 END) AS ops_hospedaje," : "0 AS ops_hospedaje," ) . "
        " . ( $has_hospedaje ? "MAX(CASE WHEN c.is_hospedaje=1 THEN c.hospedaje_direccion ELSE NULL END) AS hospedaje_direccion," : "NULL AS hospedaje_direccion," ) . "
        um_banco.meta_value                                   AS banco_institucion,
        " . ( $has_import ? "SUM(CASE WHEN c.is_import=1 THEN 1 ELSE 0 END) AS ops_importacion," : "0 AS ops_importacion," ) . "
        " . ( $has_import ? "SUM(COALESCE(c.aranceles_amount,0)) AS aranceles," : "0 AS aranceles," ) . "
        u.user_email                                         AS email,
        um_pais.meta_value                                   AS pais_residencia
     FROM {$wpdb->prefix}lt_commissions c
     LEFT JOIN {$wpdb->users}    u           ON u.ID = c.vendor_id
     LEFT JOIN {$wpdb->usermeta} um_rfc      ON um_rfc.user_id      = c.vendor_id AND um_rfc.meta_key      = 'ltms_rfc'
     LEFT JOIN {$wpdb->usermeta} um_curp     ON um_curp.user_id     = c.vendor_id AND um_curp.meta_key     = 'ltms_curp'
     LEFT JOIN {$wpdb->usermeta} um_domicilio ON um_domicilio.user_id = c.vendor_id AND um_domicilio.meta_key = 'ltms_domicilio_fiscal'
     LEFT JOIN {$wpdb->usermeta} um_clabe    ON um_clabe.user_id    = c.vendor_id AND um_clabe.meta_key    = 'ltms_clabe'
     LEFT JOIN {$wpdb->usermeta} um_pais     ON um_pais.user_id     = c.vendor_id AND um_pais.meta_key     = 'ltms_pais_residencia'
     LEFT JOIN {$wpdb->usermeta} um_banco    ON um_banco.user_id    = c.vendor_id AND um_banco.meta_key    = 'ltms_banco'
     WHERE c.created_at BETWEEN %s AND %s $country_sql
     GROUP BY c.vendor_id
     ORDER BY SUM(c.gross_amount) DESC",
    $dt_from, $dt_to
), ARRAY_A ) ?: [];

// ── SAT log ────────────────────────────────────────────────────────────────
$sat_log = $wpdb->get_results( $wpdb->prepare(
    "SELECT auditor_rfc, auditor_name, access_type, filter_period, filter_vendor, rows_returned, ip_address, accessed_at
     FROM {$wpdb->prefix}lt_sat_online_access
     WHERE accessed_at BETWEEN %s AND %s ORDER BY accessed_at DESC LIMIT 20",
    $dt_from, $dt_to
), ARRAY_A ) ?: [];

// ── DIAN log ───────────────────────────────────────────────────────────────
$dian_log = $wpdb->get_results( $wpdb->prepare(
    "SELECT auditor_nit, auditor_name, access_type, filter_from, filter_vendor, rows_returned, ip_address, accessed_at
     FROM {$wpdb->prefix}lt_dian_online_access
     WHERE accessed_at BETWEEN %s AND %s ORDER BY accessed_at DESC LIMIT 20",
    $dt_from, $dt_to
), ARRAY_A ) ?: [];

// ── Eventos seguridad ──────────────────────────────────────────────────────
$sec_q      = "SELECT severity AS level, event_type, user_id, ip_address, created_at,
               CONCAT(request_method,' ',request_uri) AS summary
               FROM {$wpdb->prefix}lt_security_events
               WHERE created_at BETWEEN %s AND %s";
$sec_params = [ $dt_from, $dt_to ];
if ( $event_level ) { $sec_q .= ' AND severity = %s'; $sec_params[] = $event_level; }
$sec_q          .= ' ORDER BY created_at DESC LIMIT 50';
$security_events = $wpdb->get_results( $wpdb->prepare( $sec_q, ...$sec_params ), ARRAY_A ) ?: [];

// ── KYC pendiente ──────────────────────────────────────────────────────────
$kyc_pending = $wpdb->get_results( $wpdb->prepare(
    "SELECT k.*, u.display_name, u.user_email,
            um.meta_value AS document_type
     FROM {$wpdb->prefix}lt_vendor_kyc k
     LEFT JOIN {$wpdb->users} u ON u.ID = k.vendor_id
     LEFT JOIN {$wpdb->usermeta} um ON um.user_id = k.vendor_id AND um.meta_key = 'ltms_document_type'
     WHERE k.status IN ('pending','under_review')
       AND k.submitted_at BETWEEN %s AND %s
     ORDER BY k.submitted_at DESC",
    $dt_from, $dt_to
), ARRAY_A ) ?: [];

// ── SAGRILAFT ─────────────────────────────────────────────────────────────
$sagrilaft_uvt   = (float) LTMS_Core_Config::get( 'ltms_uvt_valor', 49799.0 );
$sagrilaft_uvts  = (float) LTMS_Core_Config::get( 'ltms_sagrilaft_uvt_threshold', 10000.0 );
$sagrilaft_floor = $sagrilaft_uvt * $sagrilaft_uvts;
$large_payouts   = $wpdb->get_results( $wpdb->prepare(
    "SELECT p.*, u.display_name, u.user_email
     FROM {$wpdb->prefix}lt_payout_requests p
     LEFT JOIN {$wpdb->users} u ON u.ID = p.vendor_id
     WHERE p.amount >= %f AND p.created_at BETWEEN %s AND %s ORDER BY p.amount DESC",
    $sagrilaft_floor, $dt_from, $dt_to
), ARRAY_A ) ?: [];

// ── Helpers ────────────────────────────────────────────────────────────────
function ltms_money( $v ) { return number_format( (float)( $v ?? 0 ), 2, '.', ',' ); }
function ltms_int( $v )   { return number_format( (int)  ( $v ?? 0 ), 0, '.', ',' ); }
function ltms_level_badge( $level ) {
    $map = [ 'critical' => 'danger', 'high' => 'warning', 'medium' => 'secondary', 'low' => 'info' ];
    $cls = $map[ strtolower( $level ) ] ?? 'info';
    return '<span class="ltms-badge ltms-badge-' . esc_attr( $cls ) . '">' . esc_html( strtoupper( $level ) ) . '</span>';
}
function ltms_na( $v ) { return ( $v !== null && $v !== '' ) ? esc_html( $v ) : '<span class="ltms-null">—</span>'; }
function ltms_country_flag( $code ) {
    return $code === 'MX' ? '🇲🇽' : ( $code === 'CO' ? '🇨🇴' : '🌐' );
}

$current_user  = wp_get_current_user();
$auditor_label = esc_html( $current_user->display_name );
$tx_pages      = $tx_total > 0 ? ceil( $tx_total / $tx_per_page ) : 1;
$now_label     = esc_html( date_i18n( 'd M Y · H:i', current_time( 'timestamp' ) ) );
$base_url      = add_query_arg( [ 'page' => 'ltms-auditor', 'date_from' => $date_from, 'date_to' => $date_to, 'country' => $country, 'level' => $event_level ] );
$export_url    = esc_url( add_query_arg( 'export', 'csv', $base_url ) );
$critical_sec  = count( array_filter( $security_events, fn($e) => strtolower($e['level']) === 'critical' ) );
?>
<style>
/* ═══════════════════════════════════════════════════════════════════════════
   LTMS Auditor Panel v2.5.4 — Design System
   Fixes: table overflow, responsive header, sticky filters, min-width KPI
   ═══════════════════════════════════════════════════════════════════════════ */

/* ── Tokens ── */
:root {
    --ltms-navy:      #0d1b2a;
    --ltms-navy-2:    #1a2d42;
    --ltms-navy-3:    #1e3a5f;
    --ltms-blue:      #2563eb;
    --ltms-blue-lt:   #3b82f6;
    --ltms-green:     #047857;
    --ltms-green-lt:  #d1fae5;
    --ltms-teal:      #0891b2;
    --ltms-amber:     #b45309;
    --ltms-red:       #b91c1c;
    --ltms-red-lt:    #fee2e2;
    --ltms-slate-1:   #f1f5f9;
    --ltms-slate-2:   #e2e8f0;
    --ltms-slate-3:   #cbd5e1;
    --ltms-slate-4:   #94a3b8;
    --ltms-slate-5:   #64748b;
    --ltms-slate-6:   #475569;
    --ltms-slate-7:   #334155;
    --ltms-slate-8:   #1e293b;
    --ltms-radius:    10px;
    --ltms-shadow:    0 1px 3px rgba(0,0,0,.08), 0 4px 16px rgba(0,0,0,.06);
    --ltms-shadow-md: 0 4px 12px rgba(0,0,0,.12), 0 8px 32px rgba(0,0,0,.08);
}

/* ══════════════════════════════════════════════════════════════════════════
   v2.5.4 — ROOT LAYOUT FIX
   
   Problema: el header se cortaba por la izquierda porque WordPress aplica
   padding-left al #wpbody-content y el .wrap tiene margin: 0 10px.
   La combinación de contain:layout + overflow-x:clip en el chain de WP
   seguía causando el recorte del header.
   
   Solución: NO tocar el chain de WP con overflow. Solo asegurarse de que
   .ltms-ap sea un bloque normal sin contain ni overflow propio, y que las
   tablas se contengan dentro de .ltms-tw con overflow-x:auto.
   El sticky funciona mientras ningún ancestro tenga overflow != visible
   EXCEPTO .ltms-tw (que sí tiene overflow-x:auto pero los filtros están
   FUERA de él, así que no son afectados).
   ══════════════════════════════════════════════════════════════════════════ */

/* ── Reset & Base ── */
.ltms-ap * { box-sizing: border-box; }
.ltms-ap {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    color: var(--ltms-slate-8);
    line-height: 1.5;
    word-break: break-word;
    /* Sin overflow, sin contain, sin max-width fijo — WP gestiona el ancho */
}

/* ── WP layout — corrección mínima sin tocar overflow del chain ── */
.woo-lc-pointer-wrapper, .woocommerce-marketplace-suggestions,
.notice.woo-lc-pointer, .notice[class*="order-status"],
.notice[class*="woocommerce-cot"] { display: none !important; }
/* Solo esto: asegurar que el .wrap no tenga float que rompa el layout */
.wrap.ltms-ap { float: none !important; }

/* ═══════════════════════════════════════════════════════════════════════════
   HEADER — v2.5.4: contraste mejorado + flex-wrap correcto
   ═══════════════════════════════════════════════════════════════════════════ */
.ltms-header {
    background: linear-gradient(135deg, var(--ltms-navy) 0%, var(--ltms-navy-2) 100%);
    border-radius: var(--ltms-radius);
    padding: 20px 24px;
    margin-bottom: 20px;
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    align-items: flex-start;
    gap: 14px;
    box-shadow: var(--ltms-shadow-md);
    position: relative;
    overflow: hidden;
}
.ltms-header::after {
    content: '';
    position: absolute;
    right: -60px; top: -60px;
    width: 220px; height: 220px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(59,130,246,.15) 0%, transparent 70%);
    pointer-events: none;
}
.ltms-header-title {
    font-size: 22px;
    font-weight: 800;
    /* v2.5.4: blanco puro para máximo contraste sobre navy */
    color: #ffffff;
    margin: 0 0 8px;
    padding: 0;
    letter-spacing: -.3px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.ltms-header-sub {
    font-size: 12.5px;
    /* v2.5.4: de slate-4 (#94a3b8) a slate-2 (#e2e8f0) — ratio 7.5:1 sobre navy */
    color: #cbd5e1;
    line-height: 2;
    word-break: break-word;
}
/* v2.5.4: nombre del auditor en blanco puro, no slate-4 */
.ltms-header-sub strong { color: #ffffff; font-weight: 700; }
.ltms-header-badges {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 8px;
    flex-shrink: 0;
}
.ltms-badge-readonly {
    background: rgba(37,99,235,.4);
    /* v2.5.4: texto más claro para contraste */
    color: #bfdbfe;
    border: 1px solid rgba(96,165,250,.6);
    padding: 7px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 700;
    white-space: nowrap;
    letter-spacing: .3px;
}
.ltms-badge-compliance {
    background: rgba(4,120,87,.4);
    /* v2.5.4: verde más luminoso */
    color: #a7f3d0;
    border: 1px solid rgba(52,211,153,.5);
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}
.ltms-badge-alert-header {
    background: rgba(185,28,28,.4);
    color: #fecaca;
    border: 1px solid rgba(248,113,113,.5);
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    animation: ltms-pulse 2s ease-in-out infinite;
}
@keyframes ltms-pulse {
    0%, 100% { opacity: 1; } 50% { opacity: .7; }
}

/* ═══════════════════════════════════════════════════════════════════════════
   FILTER BAR — v2.5.4: sticky funciona ahora que no hay overflow en ancestros
   ═══════════════════════════════════════════════════════════════════════════ */
.ltms-filters {
    background: #fff;
    border: 1px solid var(--ltms-slate-2);
    border-radius: var(--ltms-radius);
    padding: 14px 18px;
    margin-bottom: 22px;
    display: flex !important;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 12px;
    box-shadow: var(--ltms-shadow);
    position: sticky;
    top: 32px;   /* altura de la WP admin bar */
    z-index: 200;
}
.ltms-fg { display: flex; flex-direction: column; gap: 5px; }
.ltms-fg label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    color: var(--ltms-slate-5);
    letter-spacing: .7px;
}
.ltms-fg input, .ltms-fg select {
    border: 1.5px solid var(--ltms-slate-3);
    border-radius: 7px;
    padding: 8px 10px;
    font-size: 13px;
    color: var(--ltms-slate-8);
    background: var(--ltms-slate-1);
    /* FIX: min-width so inputs don't collapse */
    min-width: 130px;
    width: auto;
    transition: border-color .15s, box-shadow .15s;
}
.ltms-fg input:focus, .ltms-fg select:focus {
    border-color: var(--ltms-blue-lt);
    box-shadow: 0 0 0 3px rgba(59,130,246,.15);
    outline: none;
    background: #fff;
}
.ltms-btn-filter {
    background: var(--ltms-navy-3);
    color: #fff;
    border: none;
    border-radius: 7px;
    padding: 9px 18px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s, transform .1s;
    align-self: flex-end;
    white-space: nowrap;
}
.ltms-btn-filter:hover { background: var(--ltms-blue); transform: translateY(-1px); }
.ltms-btn-export {
    background: transparent;
    color: var(--ltms-green);
    border: 1.5px solid var(--ltms-green);
    border-radius: 7px;
    padding: 8px 14px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: background .15s, color .15s;
    align-self: flex-end;
    white-space: nowrap;
}
.ltms-btn-export:hover { background: var(--ltms-green); color: #fff; }

/* ═══════════════════════════════════════════════════════════════════════════
   KPI GRID — FIX: min 140px so cards don't squeeze, max prevents giant gaps
   ═══════════════════════════════════════════════════════════════════════════ */
.ltms-kpi-grid {
    display: grid !important;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 12px;
    margin-bottom: 30px;
}
.ltms-kpi {
    border-radius: var(--ltms-radius);
    padding: 16px 14px 14px;
    position: relative;
    overflow: hidden;
    cursor: default;
    transition: transform .15s, box-shadow .15s;
    background: var(--ltms-navy-3);
    color: #fff;
    box-shadow: var(--ltms-shadow);
    /* FIX: min-width prevents cards from collapsing in narrow viewports */
    min-width: 0;
}
.ltms-kpi:hover { transform: translateY(-3px); box-shadow: var(--ltms-shadow-md); }
.ltms-kpi-icon {
    position: absolute;
    right: 10px; top: 10px;
    font-size: 24px;
    opacity: .2;
}
.ltms-kpi-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .8px;
    color: rgba(255,255,255,.6);
    margin-bottom: 8px;
    line-height: 1.3;
    /* FIX: allow label to wrap instead of overflow */
    word-break: break-word;
    padding-right: 28px; /* avoid icon overlap */
}
.ltms-kpi-value {
    font-size: 22px;
    font-weight: 800;
    color: #f1f5f9;
    line-height: 1;
    letter-spacing: -.5px;
    margin-bottom: 8px;
    /* FIX: allow large numbers to wrap */
    word-break: break-all;
    overflow-wrap: anywhere;
}
.ltms-kpi-sub {
    font-size: 10px;
    color: rgba(255,255,255,.45);
    line-height: 1.4;
}
.ltms-kpi.kpi-gross    { background: linear-gradient(135deg, #1e3a5f 0%, #1d4ed8 100%); }
.ltms-kpi.kpi-net      { background: linear-gradient(135deg, #065f46 0%, #059669 100%); }
.ltms-kpi.kpi-fee      { background: linear-gradient(135deg, #0e7490 0%, #0891b2 100%); }
.ltms-kpi.kpi-isr      { background: linear-gradient(135deg, #064e3b 0%, #047857 100%); }
.ltms-kpi.kpi-iva      { background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%); }
.ltms-kpi.kpi-reteiva  { background: linear-gradient(135deg, #312e81 0%, #6d28d9 100%); }
.ltms-kpi.kpi-rete     { background: linear-gradient(135deg, #1e3a8a 0%, #3730a3 100%); }
.ltms-kpi.kpi-ieps     { background: linear-gradient(135deg, #4c1d95 0%, #7c3aed 100%); }
.ltms-kpi.kpi-aranceles{ background: linear-gradient(135deg, #78350f 0%, #b45309 100%); }
.ltms-kpi.kpi-hospedaje{ background: linear-gradient(135deg, #7f1d1d 0%, #b91c1c 100%); }
.ltms-kpi.kpi-import   { background: linear-gradient(135deg, #422006 0%, #92400e 100%); }
.ltms-kpi.kpi-tx       { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); border: 1px solid rgba(255,255,255,.08); }

/* ═══════════════════════════════════════════════════════════════════════════
   SECTION HEADERS
   ═══════════════════════════════════════════════════════════════════════════ */
.ltms-sh {
    display: flex !important;
    align-items: center;
    gap: 10px;
    margin: 32px 0 0;
    flex-wrap: wrap;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--ltms-slate-2);
    position: relative;
}
.ltms-sh::after {
    content: '';
    position: absolute;
    bottom: -2px; left: 0;
    width: 80px; height: 2px;
    background: var(--ltms-blue-lt);
    border-radius: 2px;
}
.ltms-sh.mx-accent::after  { background: #006847; }
.ltms-sh.co-accent::after  { background: #003087; }
.ltms-sh.red-accent::after { background: #e11d48; }
.ltms-sh.sec-accent::after { background: #7c3aed; }
.ltms-sh h2 {
    font-size: 15px;
    font-weight: 700;
    /* v2.5.4: #1e293b → garantiza ratio 12:1 sobre fondo blanco */
    color: #0f172a;
    margin: 0;
    padding: 0;
    line-height: 1;
}
.ltms-sh-icon { font-size: 18px; }
.ltms-sh-desc {
    font-size: 11px;
    /* v2.5.4: slate-6 (#475569) → ratio 5.9:1, cumple AA */
    color: #475569;
    margin-left: auto;
    text-align: right;
    line-height: 1.4;
    max-width: 40%;
    word-break: break-word;
}
.ltms-compliance-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: var(--ltms-green-lt);
    color: var(--ltms-green);
    border: 1px solid #6ee7b7;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 800;
    padding: 3px 10px;
    letter-spacing: .4px;
    white-space: nowrap;
}

/* ═══════════════════════════════════════════════════════════════════════════
   TABS
   ═══════════════════════════════════════════════════════════════════════════ */
.ltms-tabs {
    display: flex;
    gap: 4px;
    margin-top: 18px;
    border-bottom: 2px solid var(--ltms-slate-2);
    padding-bottom: 0;
    flex-wrap: wrap;           /* FIX: tabs wrap on mobile */
}
.ltms-tab-btn {
    padding: 10px 18px;
    border: 1px solid transparent;
    border-bottom: none;
    border-radius: 8px 8px 0 0;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    background: var(--ltms-slate-1);
    color: var(--ltms-slate-5);
    position: relative;
    bottom: -2px;
    transition: background .15s, color .15s;
    white-space: nowrap;
}
.ltms-tab-btn:hover { background: var(--ltms-slate-2); color: var(--ltms-slate-7); }
.ltms-tab-btn.active {
    background: #fff;
    color: var(--ltms-navy-3);
    border-color: var(--ltms-slate-2);
    border-bottom-color: #fff;
    z-index: 1;
}
.ltms-tab-pane { display: none; padding-top: 18px; }
.ltms-tab-pane.active { display: block; }
.ltms-tab-count {
    display: inline-block;
    background: var(--ltms-blue-lt);
    color: #fff;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 800;
    padding: 1px 7px;
    margin-left: 6px;
}
.ltms-tab-btn.active .ltms-tab-count { background: var(--ltms-navy-3); }

/* ═══════════════════════════════════════════════════════════════════════════
   TABLES — v2.5.4: overflow horizontal aislado en .ltms-tw
   Los filtros están FUERA de .ltms-tw así que su sticky no se ve afectado
   por el overflow-x:auto de las tablas.
   ═══════════════════════════════════════════════════════════════════════════ */
.ltms-tw {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    border-radius: 8px;
    border: 1px solid var(--ltms-slate-2);
    margin: 12px 0 20px;
    box-shadow: var(--ltms-shadow);
    max-width: 100%;
    position: relative;
}
.ltms-tw table.widefat {
    border: none;
    margin: 0;
    border-radius: 8px;
    overflow: hidden;
    /* FIX: table must not shrink below its content */
    min-width: 100%;
    width: max-content;   /* FIX: allows scrolling instead of collapsing */
}
.ltms-tw table.widefat thead th {
    background: var(--ltms-slate-1);
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    color: var(--ltms-slate-5);
    letter-spacing: .5px;
    white-space: nowrap;
    padding: 10px 13px;
    border-bottom: 2px solid var(--ltms-slate-2);
    /* v2.5.4: sticky thead inside overflow:auto container — z-index 1 is enough
       because isolation:isolate on .ltms-tw creates its own stacking context */
    position: sticky;
    top: 0;
    z-index: 1;
    background-clip: padding-box;
}
.ltms-tw table.widefat tbody td {
    font-size: 12.5px;
    padding: 10px 13px;
    vertical-align: middle;
    border-bottom: 1px solid var(--ltms-slate-2);
    max-width: 260px;
    overflow-wrap: break-word;
    word-break: break-word;
    /* v2.5.4: color explícito para garantizar contraste */
    color: #1e293b;
}
.ltms-tw table.widefat tbody tr:last-child td { border-bottom: none; }
.ltms-tw table.widefat tbody tr:hover td { background: #f8fafc; }
.ltms-tw code {
    background: var(--ltms-slate-1);
    padding: 2px 7px;
    border-radius: 4px;
    font-size: 11px;
    color: var(--ltms-slate-7);
    font-family: 'SF Mono', 'Fira Mono', monospace;
    /* FIX: prevent code blocks from pushing table wide */
    word-break: break-all;
    display: inline-block;
    max-width: 220px;
    overflow: hidden;
    text-overflow: ellipsis;
    vertical-align: bottom;
}
.ltms-num {
    text-align: right !important;
    font-variant-numeric: tabular-nums;
    font-family: 'SF Mono', 'Fira Mono', monospace;
    white-space: nowrap;
}
.ltms-row-alert td { background: #fff5f5 !important; }
.ltms-null { color: var(--ltms-slate-4); }
.ltms-field-label {
    display: block;
    font-size: 9px;
    font-weight: 700;
    text-transform: uppercase;
    color: var(--ltms-slate-4);
    letter-spacing: .5px;
    margin-bottom: 2px;
}

.ltms-date { white-space: nowrap; font-size: 11px !important; font-variant-numeric: tabular-nums; }
.ltms-small { font-size: 11.5px; }
.ltms-domicilio { max-width: 160px; word-break: break-word; font-size: 11.5px; }

/* FIX: Frac. II table has 17 columns — allow horizontal scroll, compress wisely */
#frac2 .ltms-tw table.widefat tbody td {
    font-size: 11.5px;
    padding: 8px 10px;
    max-width: 180px;
}
#frac2 .ltms-tw table.widefat thead th {
    font-size: 9.5px;
    padding: 9px 10px;
}

/* ═══════════════════════════════════════════════════════════════════════════
   BADGES
   ═══════════════════════════════════════════════════════════════════════════ */
.ltms-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .3px;
    white-space: nowrap;
}
.ltms-badge-warning   { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
.ltms-badge-danger    { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
.ltms-badge-info      { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
.ltms-badge-secondary { background: var(--ltms-slate-1); color: var(--ltms-slate-6); border: 1px solid var(--ltms-slate-3); }
.ltms-badge-ok        { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
.ltms-status {
    display: inline-block;
    padding: 3px 9px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 700;
    background: var(--ltms-slate-1);
    color: var(--ltms-slate-6);
    border: 1px solid var(--ltms-slate-3);
    white-space: nowrap;
}
.ltms-status-pending, .ltms-status-under_review { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
.ltms-status-approved { background: #d1fae5; color: #065f46; border-color: #6ee7b7; }
.ltms-status-rejected { background: #fee2e2; color: #b91c1c; border-color: #fca5a5; }
.ltms-status-paid     { background: #dbeafe; color: #1e40af; border-color: #93c5fd; }

/* ═══════════════════════════════════════════════════════════════════════════
   PAGINATION
   ═══════════════════════════════════════════════════════════════════════════ */
.ltms-pag {
    display: flex;
    gap: 4px;
    align-items: center;
    margin: 8px 0 20px;
    flex-wrap: wrap;
}
.ltms-pag a, .ltms-pag span {
    padding: 5px 11px;
    border-radius: 6px;
    border: 1px solid var(--ltms-slate-3);
    font-size: 12px;
    text-decoration: none;
    color: var(--ltms-slate-7);
    background: #fff;
    font-weight: 500;
    transition: background .1s, border-color .1s;
}
.ltms-pag a:hover { background: var(--ltms-slate-1); border-color: var(--ltms-blue-lt); }
.ltms-pag span.current { background: var(--ltms-navy-3); color: #fff; border-color: var(--ltms-navy-3); font-weight: 700; }
.ltms-pag-info { font-size: 12px; color: var(--ltms-slate-5); margin-right: 8px; }

/* ═══════════════════════════════════════════════════════════════════════════
   EMPTY STATE
   ═══════════════════════════════════════════════════════════════════════════ */
.ltms-empty {
    text-align: center;
    padding: 44px 24px;
    background: var(--ltms-slate-1);
    border-radius: 8px;
    border: 1.5px dashed var(--ltms-slate-3);
    margin: 12px 0 20px;
}
.ltms-empty-icon { font-size: 36px; margin-bottom: 10px; }
.ltms-empty p { color: var(--ltms-slate-5); font-size: 13px; margin: 0; }

/* ═══════════════════════════════════════════════════════════════════════════
   INFO CARD
   ═══════════════════════════════════════════════════════════════════════════ */
.ltms-info-card {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-left: 4px solid var(--ltms-blue-lt);
    border-radius: 7px;
    padding: 10px 14px;
    font-size: 12px;
    color: #1e40af;
    margin-bottom: 12px;
    line-height: 1.7;
    /* FIX: allow text to wrap */
    word-break: break-word;
}
.ltms-info-card.warn   { background: #fffbeb; border-color: #fde68a; border-left-color: #f59e0b; color: #78350f; }
.ltms-info-card.danger { background: #fff1f2; border-color: #fecdd3; border-left-color: #e11d48; color: #9f1239; }

/* ═══════════════════════════════════════════════════════════════════════════
   SAGRILAFT ALERT BANNER
   ═══════════════════════════════════════════════════════════════════════════ */
.ltms-sagrilaft-banner {
    background: linear-gradient(135deg, #450a0a 0%, #7f1d1d 100%);
    border-radius: var(--ltms-radius);
    padding: 14px 20px;
    display: flex;
    flex-wrap: wrap;           /* FIX: wrap on mobile */
    align-items: center;
    gap: 12px;
    margin-bottom: 0;
    color: #fff;
}
.ltms-sagrilaft-banner .ltms-sb-icon { font-size: 24px; flex-shrink: 0; }
.ltms-sagrilaft-banner .ltms-sb-text { font-size: 13px; font-weight: 600; color: #fecaca; flex: 1; min-width: 180px; }
.ltms-sagrilaft-banner .ltms-sb-count {
    background: #e11d48;
    color: #fff;
    border-radius: 20px;
    padding: 4px 14px;
    font-size: 13px;
    font-weight: 800;
    white-space: nowrap;
}

/* ═══════════════════════════════════════════════════════════════════════════
   BASE NORMATIVA FOOTER — FIX: word-break so long article refs don't overflow
   ═══════════════════════════════════════════════════════════════════════════ */
.ltms-norma {
    background: #f8fafc;
    border: 1px solid var(--ltms-slate-2);
    border-radius: var(--ltms-radius);
    padding: 16px 20px;
    font-size: 12px;
    color: var(--ltms-slate-6);
    margin: 32px 0 12px;
    line-height: 2.2;
    word-break: break-word;    /* FIX: break long refs */
    overflow-wrap: break-word;
}
.ltms-norma strong { color: var(--ltms-slate-8); }
.ltms-norma .ltms-norma-sub { font-size: 11px; color: var(--ltms-slate-4); display: block; margin-top: 6px; line-height: 1.7; }

.ltms-panel-footer {
    background: var(--ltms-navy);
    color: var(--ltms-slate-5);
    text-align: center;
    padding: 16px 20px;
    border-radius: var(--ltms-radius);
    font-size: 12px;
    margin-top: 8px;
    line-height: 2;
    word-break: break-word;    /* FIX: footer text wraps */
}
.ltms-panel-footer strong { color: var(--ltms-slate-4); }

/* ═══════════════════════════════════════════════════════════════════════════
   RESPONSIVE BREAKPOINTS
   ═══════════════════════════════════════════════════════════════════════════ */
@media screen and (max-width: 900px) {
    .ltms-header { flex-direction: column; }
    .ltms-header-badges { align-items: flex-start; flex-direction: row; flex-wrap: wrap; }
    .ltms-sh-desc { max-width: 100%; text-align: left; margin-left: 0; margin-top: 4px; }
    .ltms-kpi-grid { grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); }
}
@media screen and (max-width: 600px) {
    .ltms-filters { position: static; }  /* disable sticky on very small screens */
    .ltms-kpi-grid { grid-template-columns: repeat(2, 1fr); }
    .ltms-header-title { font-size: 18px; }
}
</style>

<div class="wrap ltms-ap">

    <!-- ══ HEADER ══════════════════════════════════════════════════════════ -->
    <div class="ltms-header">
        <div>
            <div class="ltms-header-title">
                <span>📊</span>
                <?php esc_html_e( 'Panel Auditor LTMS', 'ltms' ); ?>
                <sup style="font-size:11px;background:rgba(59,130,246,.3);color:#93c5fd;padding:2px 8px;border-radius:10px;font-weight:700;">v2.5.4</sup>
            </div>
            <div class="ltms-header-sub">
                <?php esc_html_e( 'Acceso de solo lectura · Sesión registrada en log forense inmutable', 'ltms' ); ?><br>
                <strong><?php echo $auditor_label; ?></strong>
                &nbsp;·&nbsp; <?php echo $now_label; ?>
                &nbsp;·&nbsp; <?php esc_html_e( 'Datos disponibles 5 años — Art. 30-B CFF / RMF 2025 Regla 12.2.10', 'ltms' ); ?>
            </div>
        </div>
        <div class="ltms-header-badges">
            <span class="ltms-badge-readonly">🔒 <?php esc_html_e( 'Solo lectura · Sesión registrada', 'ltms' ); ?></span>
            <span class="ltms-badge-compliance">✅ Art. 30-B CFF / Ficha 168/CFF</span>
            <span class="ltms-badge-compliance" style="background:rgba(37,99,235,.3);color:#93c5fd;border-color:rgba(59,130,246,.4)">🇨🇴 SAGRILAFT · E.T. Art. 437-2</span>
            <?php if ( $critical_sec > 0 ) : ?>
                <span class="ltms-badge-alert-header">⚠️ <?php printf( esc_html__( '%d evento(s) CRITICAL', 'ltms' ), $critical_sec ); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══ FILTROS ══════════════════════════════════════════════════════════ -->
    <form method="get" action="" class="ltms-filters">
        <input type="hidden" name="page" value="ltms-auditor">
        <div class="ltms-fg">
            <label for="lf-from"><?php esc_html_e( 'Desde', 'ltms' ); ?></label>
            <input type="date" id="lf-from" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
        </div>
        <div class="ltms-fg">
            <label for="lf-to"><?php esc_html_e( 'Hasta', 'ltms' ); ?></label>
            <input type="date" id="lf-to" name="date_to" value="<?php echo esc_attr( $date_to ); ?>">
        </div>
        <div class="ltms-fg">
            <label for="lf-country"><?php esc_html_e( 'País', 'ltms' ); ?></label>
            <select id="lf-country" name="country">
                <option value=""><?php esc_html_e( 'Todos', 'ltms' ); ?></option>
                <option value="CO" <?php selected( $country, 'CO' ); ?>>🇨🇴 Colombia</option>
                <option value="MX" <?php selected( $country, 'MX' ); ?>>🇲🇽 México</option>
            </select>
        </div>
        <div class="ltms-fg">
            <label for="lf-level"><?php esc_html_e( 'Nivel evento', 'ltms' ); ?></label>
            <select id="lf-level" name="level">
                <option value=""><?php esc_html_e( 'Todos', 'ltms' ); ?></option>
                <option value="critical" <?php selected( $event_level, 'critical' ); ?>>🔴 CRITICAL</option>
                <option value="high"     <?php selected( $event_level, 'high' );     ?>>🟠 HIGH</option>
                <option value="medium"   <?php selected( $event_level, 'medium' );   ?>>🟡 MEDIUM</option>
                <option value="low"      <?php selected( $event_level, 'low' );      ?>>🟢 LOW</option>
            </select>
        </div>
        <button type="submit" class="ltms-btn-filter">🔍 <?php esc_html_e( 'Filtrar', 'ltms' ); ?></button>
        <a href="<?php echo $export_url; ?>" class="ltms-btn-export">⬇️ <?php esc_html_e( 'Exportar CSV', 'ltms' ); ?></a>
    </form>

    <!-- ══ KPI CARDS ════════════════════════════════════════════════════════ -->
    <div class="ltms-kpi-grid">

        <div class="ltms-kpi kpi-tx">
            <div class="ltms-kpi-icon">🔢</div>
            <div class="ltms-kpi-label"><?php esc_html_e( 'Transacciones', 'ltms' ); ?></div>
            <div class="ltms-kpi-value"><?php echo ltms_int( $f['total_tx'] ?? 0 ); ?></div>
            <div class="ltms-kpi-sub"><?php echo ltms_int( $f['vendors_active'] ?? 0 ); ?> <?php esc_html_e( 'vendedores activos', 'ltms' ); ?></div>
        </div>

        <div class="ltms-kpi kpi-gross">
            <div class="ltms-kpi-icon">📦</div>
            <div class="ltms-kpi-label"><?php esc_html_e( 'Bruto vendedor', 'ltms' ); ?></div>
            <div class="ltms-kpi-value"><?php echo ltms_money( $f['gross'] ?? 0 ); ?></div>
            <div class="ltms-kpi-sub"><?php esc_html_e( 'Suma gross_amount', 'ltms' ); ?></div>
        </div>

        <div class="ltms-kpi kpi-fee">
            <div class="ltms-kpi-icon">🏛️</div>
            <div class="ltms-kpi-label"><?php esc_html_e( 'Fee plataforma', 'ltms' ); ?></div>
            <div class="ltms-kpi-value"><?php echo ltms_money( $f['platform_fee'] ?? 0 ); ?></div>
            <div class="ltms-kpi-sub"><?php esc_html_e( 'commission_amount', 'ltms' ); ?></div>
        </div>

        <div class="ltms-kpi kpi-net">
            <div class="ltms-kpi-icon">💚</div>
            <div class="ltms-kpi-label"><?php esc_html_e( 'Neto vendedor', 'ltms' ); ?></div>
            <div class="ltms-kpi-value"><?php echo ltms_money( $f['vendor_net'] ?? 0 ); ?></div>
            <div class="ltms-kpi-sub"><?php esc_html_e( 'vendor_amount', 'ltms' ); ?></div>
        </div>

        <div class="ltms-kpi kpi-isr">
            <div class="ltms-kpi-icon">🇲🇽</div>
            <div class="ltms-kpi-label"><?php esc_html_e( 'ISR retenido', 'ltms' ); ?></div>
            <div class="ltms-kpi-value"><?php echo ltms_money( $f['isr'] ?? 0 ); ?></div>
            <div class="ltms-kpi-sub">Art. 113-A LISR</div>
        </div>

        <div class="ltms-kpi kpi-iva">
            <div class="ltms-kpi-icon">📋</div>
            <div class="ltms-kpi-label"><?php esc_html_e( 'IVA trasladado', 'ltms' ); ?></div>
            <div class="ltms-kpi-value"><?php echo ltms_money( $f['iva'] ?? 0 ); ?></div>
            <div class="ltms-kpi-sub">LIVA Art. 1-A BIS</div>
        </div>

        <div class="ltms-kpi kpi-reteiva">
            <div class="ltms-kpi-icon">🔷</div>
            <div class="ltms-kpi-label"><?php esc_html_e( 'ReteIVA', 'ltms' ); ?></div>
            <div class="ltms-kpi-value"><?php echo ltms_money( $f['reteiva'] ?? 0 ); ?></div>
            <div class="ltms-kpi-sub">LIVA Art. 18-B</div>
        </div>

        <div class="ltms-kpi kpi-rete">
            <div class="ltms-kpi-icon">🇨🇴</div>
            <div class="ltms-kpi-label"><?php esc_html_e( 'ReteFuente', 'ltms' ); ?></div>
            <div class="ltms-kpi-value"><?php echo ltms_money( $f['rete_fuente'] ?? 0 ); ?></div>
            <div class="ltms-kpi-sub">E.T. Art. 437-2</div>
        </div>

        <div class="ltms-kpi kpi-ieps">
            <div class="ltms-kpi-icon">🧾</div>
            <div class="ltms-kpi-label"><?php esc_html_e( 'ReteICA / IEPS', 'ltms' ); ?></div>
            <div class="ltms-kpi-value"><?php echo ltms_money( ( $f['reteica'] ?? 0 ) + ( $f['ieps'] ?? 0 ) ); ?></div>
            <div class="ltms-kpi-sub">LIEPS Art. 2</div>
        </div>

        <div class="ltms-kpi kpi-aranceles">
            <div class="ltms-kpi-icon">🚢</div>
            <div class="ltms-kpi-label"><?php esc_html_e( 'Aranceles', 'ltms' ); ?></div>
            <div class="ltms-kpi-value"><?php echo ltms_money( $f['aranceles'] ?? 0 ); ?></div>
            <div class="ltms-kpi-sub">Art. 30-B frac. II h)</div>
        </div>

        <div class="ltms-kpi kpi-hospedaje">
            <div class="ltms-kpi-icon">🏨</div>
            <div class="ltms-kpi-label"><?php esc_html_e( 'Ops. hospedaje', 'ltms' ); ?></div>
            <div class="ltms-kpi-value"><?php echo ltms_int( $f['hospedaje_ops'] ?? 0 ); ?></div>
            <div class="ltms-kpi-sub">Art. 30-B frac. II g)</div>
        </div>

        <div class="ltms-kpi kpi-import">
            <div class="ltms-kpi-icon">📦</div>
            <div class="ltms-kpi-label"><?php esc_html_e( 'Ops. importación', 'ltms' ); ?></div>
            <div class="ltms-kpi-value"><?php echo ltms_int( $f['import_ops'] ?? 0 ); ?></div>
            <div class="ltms-kpi-sub">Art. 30-B frac. II h)</div>
        </div>

    </div><!-- /.ltms-kpi-grid -->

    <!-- ══ ART. 30-B CFF — FRACCIONES I y II ════════════════════════════════ -->
    <div class="ltms-sh mx-accent">
        <span class="ltms-sh-icon">🇲🇽</span>
        <h2><?php esc_html_e( 'Información Fiscal — Art. 30-B CFF', 'ltms' ); ?></h2>
        <span class="ltms-compliance-pill">✅ Ficha 168/CFF</span>
        <span class="ltms-sh-desc"><?php esc_html_e( 'Detalle por transacción · 5 años disponibles · RMF 2025 Regla 12.2.10', 'ltms' ); ?></span>
    </div>

    <div class="ltms-tabs">
        <button class="ltms-tab-btn active" onclick="ltmsTab(this,'frac1')">
            📋 <?php esc_html_e( 'Frac. I — Servicios / Operaciones', 'ltms' ); ?>
            <span class="ltms-tab-count"><?php echo ltms_int( $tx_total ); ?></span>
        </button>
        <button class="ltms-tab-btn" onclick="ltmsTab(this,'frac2')">
            🏪 <?php esc_html_e( 'Frac. II — Vendedores Intermediados', 'ltms' ); ?>
            <span class="ltms-tab-count"><?php echo count( $vendors_detail ); ?></span>
        </button>
    </div>

    <!-- FRACCIÓN I -->
    <div class="ltms-tab-pane active" id="frac1">
        <div class="ltms-info-card">
            <?php esc_html_e( 'Campos obligatorios: a) tipo de servicio · b) RFC cliente · c) precio sin IVA · d) IVA trasladado · e) precio final (c+d) · f) folio CFDI · g) método de pago', 'ltms' ); ?>
        </div>
        <?php if ( empty( $transactions ) ) : ?>
            <div class="ltms-empty"><div class="ltms-empty-icon">📭</div><p><?php esc_html_e( 'No hay transacciones en este período.', 'ltms' ); ?></p></div>
        <?php else : ?>
        <div class="ltms-tw">
        <table class="widefat striped">
            <thead><tr>
                <th>ID</th>
                <th><?php esc_html_e( 'Orden', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'País', 'ltms' ); ?></th>
                <th>a) <?php esc_html_e( 'Tipo servicio', 'ltms' ); ?></th>
                <th>b) <?php esc_html_e( 'RFC cliente', 'ltms' ); ?></th>
                <th class="ltms-num">c) <?php esc_html_e( 'Precio s/IVA', 'ltms' ); ?></th>
                <th class="ltms-num">d) <?php esc_html_e( 'IVA trasladado', 'ltms' ); ?></th>
                <th class="ltms-num">e) <?php esc_html_e( 'Precio final', 'ltms' ); ?></th>
                <th>f) <?php esc_html_e( 'Folio CFDI', 'ltms' ); ?></th>
                <th>g) <?php esc_html_e( 'Método pago', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $transactions as $tx ) : ?>
                <tr>
                    <td><code>#<?php echo esc_html( $tx['id'] ); ?></code></td>
                    <td><?php echo ltms_na( $tx['order_id'] ); ?></td>
                    <td><?php echo ltms_country_flag( $tx['country_code'] ); ?> <?php echo ltms_na( $tx['country_code'] ); ?></td>
                    <td><?php echo ltms_na( $tx['service_type'] ); ?></td>
                    <td><code><?php echo ltms_na( $tx['rfc_cliente'] ); ?></code></td>
                    <td class="ltms-num"><?php echo ltms_money( $tx['gross_amount'] ); ?></td>
                    <td class="ltms-num"><?php echo ltms_money( $tx['iva_amount'] ); ?></td>
                    <td class="ltms-num"><strong><?php echo ltms_money( $tx['total_con_iva'] ); ?></strong></td>
                    <td><code><?php echo ltms_na( $tx['cfdi_folio'] ); ?></code></td>
                    <td><?php echo ltms_na( $tx['payment_method'] ); ?></td>
                    <td class="ltms-num ltms-date"><?php echo esc_html( $tx['created_at'] ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ( $tx_pages > 1 ) : ?>
        <div class="ltms-pag">
            <span class="ltms-pag-info"><?php printf( esc_html__( 'Página %d de %d · %d registros', 'ltms' ), $tx_page, $tx_pages, $tx_total ); ?></span>
            <?php
            for ( $p = 1; $p <= min( $tx_pages, 12 ); $p++ ) :
                if ( $p === $tx_page ) : ?>
                    <span class="current"><?php echo $p; ?></span>
                <?php else : ?>
                    <a href="<?php echo esc_url( add_query_arg( 'tx_paged', $p, $base_url ) ); ?>"><?php echo $p; ?></a>
                <?php endif;
            endfor; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div><!-- #frac1 -->

    <!-- FRACCIÓN II -->
    <div class="ltms-tab-pane" id="frac2">
        <div class="ltms-info-card">
            <?php esc_html_e( 'Campos obligatorios: a) nombre/razón social · b) RFC/NIF · c) CURP · d) domicilio fiscal · e) institución financiera + CLABE · f-i a f-vii) montos ISR/IVA/IEPS y retenciones · métodos de pago adquiriente/oferente/plataforma · g) hospedaje · h) importación/aranceles', 'ltms' ); ?>
        </div>
        <?php if ( empty( $vendors_detail ) ) : ?>
            <div class="ltms-empty"><div class="ltms-empty-icon">📭</div><p><?php esc_html_e( 'No hay vendedores con operaciones en este período.', 'ltms' ); ?></p></div>
        <?php else : ?>
        <div class="ltms-tw">
        <table class="widefat striped">
            <thead><tr>
                <th>a) <?php esc_html_e( 'Nombre', 'ltms' ); ?></th>
                <th>b) RFC / NIF</th>
                <th>c) CURP</th>
                <th>d) <?php esc_html_e( 'Domicilio', 'ltms' ); ?></th>
                <th>e) <?php esc_html_e( 'Banco', 'ltms' ); ?></th>
                <th>e) CLABE</th>
                <th class="ltms-num">f-i) ISR</th>
                <th class="ltms-num">f-ii) IVA</th>
                <th class="ltms-num">f-iii) IEPS</th>
                <th>f-iv·a) <?php esc_html_e( 'Pago adquiriente', 'ltms' ); ?></th>
                <th>f-iv·b) <?php esc_html_e( 'Pago oferente', 'ltms' ); ?></th>
                <th>f-iv·c) <?php esc_html_e( 'Pago plataforma', 'ltms' ); ?></th>
                <th class="ltms-num">f-v) ISR ret.</th>
                <th class="ltms-num">f-vi) IVA ret.</th>
                <th class="ltms-num">f-vii) IEPS ret.</th>
                <th>g) <?php esc_html_e( 'Hospedaje', 'ltms' ); ?></th>
                <th class="ltms-num">h) <?php esc_html_e( 'Aranceles', 'ltms' ); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ( $vendors_detail as $v ) :
                $hosp = $v['ops_hospedaje'] > 0
                    ? esc_html( $v['hospedaje_direccion'] ?: __( 'Sí — ver detalle', 'ltms' ) )
                    : '<span class="ltms-null">—</span>';
                $imp  = $v['ops_importacion'] > 0
                    ? '<strong>' . ltms_money( $v['aranceles'] ) . '</strong>'
                    : '<span class="ltms-null">—</span>';
            ?>
                <tr>
                    <td><strong><?php echo esc_html( $v['nombre'] ?: $v['email'] ); ?></strong><br><small style="color:var(--ltms-slate-5)"><?php echo esc_html( $v['email'] ); ?></small></td>
                    <td><code><?php echo ltms_na( $v['rfc_nif'] ); ?></code></td>
                    <td><code style="font-size:10px"><?php echo ltms_na( $v['curp'] ); ?></code></td>
                    <td class="ltms-domicilio"><?php echo ltms_na( $v['domicilio_fiscal'] ); ?></td>
                    <td class="ltms-small"><?php echo ltms_na( $v['banco_institucion'] ); ?></td>
                    <td><code style="font-size:10px"><?php echo ltms_na( $v['clabe_cuenta'] ); ?></code></td>
                    <td class="ltms-num"><?php echo ltms_money( $v['monto_isr'] ); ?></td>
                    <td class="ltms-num"><?php echo ltms_money( $v['monto_iva'] ); ?></td>
                    <td class="ltms-num"><?php echo ltms_money( $v['monto_ieps'] ); ?></td>
                    <td class="ltms-small"><?php echo ltms_na( $v['metodo_pago_adquiriente'] ); ?></td>
                    <td class="ltms-small"><?php echo ltms_na( $v['metodo_pago_oferente'] ); ?></td>
                    <td class="ltms-small"><?php echo ltms_na( $v['metodo_pago_plataforma'] ); ?></td>
                    <td class="ltms-num"><?php echo ltms_money( $v['isr_retenido'] ); ?></td>
                    <td class="ltms-num"><?php echo ltms_money( $v['iva_retenido'] ); ?></td>
                    <td class="ltms-num"><?php echo ltms_money( $v['ieps_retenido'] ); ?></td>
                    <td class="ltms-small"><?php echo $hosp; ?></td>
                    <td class="ltms-num"><?php echo $imp; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div><!-- #frac2 -->

    <!-- ══ KYC PENDIENTE ════════════════════════════════════════════════════ -->
    <div class="ltms-sh">
        <span class="ltms-sh-icon">📋</span>
        <h2><?php esc_html_e( 'KYC Pendiente de Revisión', 'ltms' ); ?></h2>
        <?php if ( ! empty( $kyc_pending ) ) : ?>
            <span class="ltms-badge ltms-badge-warning"><?php echo count( $kyc_pending ); ?> <?php esc_html_e( 'pendiente(s)', 'ltms' ); ?></span>
        <?php else : ?>
            <span class="ltms-badge ltms-badge-ok">✅ <?php esc_html_e( 'Sin pendientes', 'ltms' ); ?></span>
        <?php endif; ?>
        <span class="ltms-sh-desc"><?php esc_html_e( 'Documentos KYC en espera de validación', 'ltms' ); ?></span>
    </div>

    <?php if ( empty( $kyc_pending ) ) : ?>
        <div class="ltms-empty"><div class="ltms-empty-icon">✅</div><p><?php esc_html_e( 'No hay documentos KYC pendientes en este período.', 'ltms' ); ?></p></div>
    <?php else : ?>
    <div class="ltms-tw">
    <table class="widefat striped">
        <thead><tr>
            <th><?php esc_html_e( 'Vendedor', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Email', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Tipo documento', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Enviado', 'ltms' ); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ( $kyc_pending as $kyc ) : ?>
            <tr>
                <td><strong><?php echo esc_html( $kyc['display_name'] ); ?></strong></td>
                <td><?php echo esc_html( $kyc['user_email'] ); ?></td>
                <td><?php echo esc_html( $kyc['document_type'] ?? '—' ); ?></td>
                <td><span class="ltms-status ltms-status-<?php echo esc_attr( $kyc['status'] ); ?>"><?php echo esc_html( $kyc['status'] ); ?></span></td>
                <td class="ltms-date"><?php echo esc_html( $kyc['submitted_at'] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <!-- ══ SAGRILAFT — siempre visible ══════════════════════════════════════ -->
    <div class="ltms-sh red-accent">
        <span class="ltms-sh-icon">⚠️</span>
        <h2 style="color:#b91c1c"><?php esc_html_e( 'SAGRILAFT — Alertas de Alto Valor', 'ltms' ); ?></h2>
        <?php if ( ! empty( $large_payouts ) ) : ?>
            <span class="ltms-badge ltms-badge-danger"><?php echo count( $large_payouts ); ?> <?php esc_html_e( 'alerta(s)', 'ltms' ); ?></span>
        <?php else : ?>
            <span class="ltms-badge ltms-badge-ok">✅ <?php esc_html_e( 'Sin alertas', 'ltms' ); ?></span>
        <?php endif; ?>
        <span class="ltms-sh-desc"><?php printf( __( 'Umbral: $%s COP (%s UVT) · Res. 314/2021 UIAF', 'ltms' ), number_format( $sagrilaft_floor, 0, ',', '.' ), number_format( $sagrilaft_uvts, 0 ) ); ?></span>
    </div>

    <?php if ( ! empty( $large_payouts ) ) : ?>
    <div class="ltms-sagrilaft-banner" style="margin-top:12px">
        <span class="ltms-sb-icon">🚨</span>
        <div class="ltms-sb-text">
            <?php esc_html_e( 'Se detectaron retiros de alto valor que requieren revisión bajo SAGRILAFT / SARLAFT. Registro obligatorio ante UIAF.', 'ltms' ); ?>
        </div>
        <span class="ltms-sb-count"><?php echo count( $large_payouts ); ?> <?php esc_html_e( 'operacion(es)', 'ltms' ); ?></span>
    </div>
    <div class="ltms-tw" style="margin-top:12px">
    <table class="widefat striped">
        <thead><tr>
            <th>ID</th>
            <th><?php esc_html_e( 'Vendedor', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Email', 'ltms' ); ?></th>
            <th class="ltms-num"><?php esc_html_e( 'Monto (COP)', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Método', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Fecha solicitud', 'ltms' ); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ( $large_payouts as $p ) : ?>
            <tr class="ltms-row-alert">
                <td><code>#<?php echo esc_html( $p['id'] ); ?></code></td>
                <td><strong><?php echo esc_html( $p['display_name'] ); ?></strong></td>
                <td><?php echo esc_html( $p['user_email'] ); ?></td>
                <td class="ltms-num"><strong style="color:#b91c1c"><?php echo ltms_money( $p['amount'] ); ?></strong></td>
                <td><?php echo esc_html( $p['method'] ?? '—' ); ?></td>
                <td><span class="ltms-status ltms-status-<?php echo esc_attr( $p['status'] ); ?>"><?php echo esc_html( $p['status'] ); ?></span></td>
                <td class="ltms-date"><?php echo esc_html( $p['created_at'] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else : ?>
        <div class="ltms-empty" style="margin-top:12px"><div class="ltms-empty-icon">✅</div><p><?php esc_html_e( 'No hay retiros de alto valor en este período.', 'ltms' ); ?></p></div>
    <?php endif; ?>

    <!-- ══ LOG SAT — Art. 30-B CFF ══════════════════════════════════════════ -->
    <div class="ltms-sh mx-accent">
        <span class="ltms-sh-icon">🇲🇽</span>
        <h2><?php esc_html_e( 'Log de Accesos SAT — Art. 30-B CFF', 'ltms' ); ?></h2>
        <?php if ( $sat_log ) : ?><span class="ltms-badge ltms-badge-info"><?php echo count( $sat_log ); ?></span><?php endif; ?>
        <span class="ltms-sh-desc"><?php esc_html_e( 'Registro inmutable · Ficha 168/CFF · credenciales entregadas a la AGP del SAT', 'ltms' ); ?></span>
    </div>

    <?php if ( empty( $sat_log ) ) : ?>
        <div class="ltms-empty"><div class="ltms-empty-icon">📭</div><p><?php esc_html_e( 'No hay accesos SAT registrados en este período.', 'ltms' ); ?></p></div>
    <?php else : ?>
    <div class="ltms-tw">
    <table class="widefat striped">
        <thead><tr>
            <th><?php esc_html_e( 'RFC Auditor', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Nombre', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Tipo acceso', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Período filtrado', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'RFC filtrado', 'ltms' ); ?></th>
            <th class="ltms-num"><?php esc_html_e( 'Filas', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'IP', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Fecha / hora', 'ltms' ); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ( $sat_log as $log ) : ?>
            <tr>
                <td><code><?php echo esc_html( $log['auditor_rfc'] ?: '—' ); ?></code></td>
                <td><?php echo esc_html( $log['auditor_name'] ?: '—' ); ?></td>
                <td><code><?php echo esc_html( $log['access_type'] ); ?></code></td>
                <td><?php echo esc_html( $log['filter_period'] ?: '—' ); ?></td>
                <td><?php echo esc_html( $log['filter_vendor'] ?: 'todos' ); ?></td>
                <td class="ltms-num"><?php echo esc_html( $log['rows_returned'] ); ?></td>
                <td><code><?php echo esc_html( $log['ip_address'] ?: '—' ); ?></code></td>
                <td class="ltms-date"><?php echo esc_html( $log['accessed_at'] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <!-- ══ LOG DIAN ═════════════════════════════════════════════════════════ -->
    <div class="ltms-sh co-accent">
        <span class="ltms-sh-icon">🇨🇴</span>
        <h2><?php esc_html_e( 'Log de Accesos DIAN — Información Exógena', 'ltms' ); ?></h2>
        <?php if ( $dian_log ) : ?><span class="ltms-badge ltms-badge-info"><?php echo count( $dian_log ); ?></span><?php endif; ?>
        <span class="ltms-sh-desc"><?php esc_html_e( 'Registro inmutable · E.T. Art. 437-2', 'ltms' ); ?></span>
    </div>

    <?php if ( empty( $dian_log ) ) : ?>
        <div class="ltms-empty"><div class="ltms-empty-icon">📭</div><p><?php esc_html_e( 'No hay accesos DIAN registrados en este período.', 'ltms' ); ?></p></div>
    <?php else : ?>
    <div class="ltms-tw">
    <table class="widefat striped">
        <thead><tr>
            <th><?php esc_html_e( 'NIT Auditor', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Nombre', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Tipo acceso', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Desde', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'NIT filtrado', 'ltms' ); ?></th>
            <th class="ltms-num"><?php esc_html_e( 'Filas', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'IP', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Fecha / hora', 'ltms' ); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ( $dian_log as $log ) : ?>
            <tr>
                <td><code><?php echo esc_html( $log['auditor_nit'] ?: '—' ); ?></code></td>
                <td><?php echo esc_html( $log['auditor_name'] ?: '—' ); ?></td>
                <td><code><?php echo esc_html( $log['access_type'] ); ?></code></td>
                <td><?php echo esc_html( $log['filter_from'] ?: '—' ); ?></td>
                <td><?php echo esc_html( $log['filter_vendor'] ?: 'todos' ); ?></td>
                <td class="ltms-num"><?php echo esc_html( $log['rows_returned'] ); ?></td>
                <td><code><?php echo esc_html( $log['ip_address'] ?: '—' ); ?></code></td>
                <td class="ltms-date"><?php echo esc_html( $log['accessed_at'] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <!-- ══ EVENTOS DE SEGURIDAD ═════════════════════════════════════════════ -->
    <div class="ltms-sh sec-accent">
        <span class="ltms-sh-icon">🛡️</span>
        <h2><?php esc_html_e( 'Registro de Eventos de Seguridad', 'ltms' ); ?></h2>
        <?php if ( $security_events ) :
            $badge_class = $critical_sec > 0 ? 'ltms-badge-danger' : 'ltms-badge-secondary';
        ?>
            <span class="ltms-badge <?php echo $badge_class; ?>"><?php echo count( $security_events ); ?> <?php esc_html_e( 'evento(s)', 'ltms' ); ?></span>
        <?php else : ?>
            <span class="ltms-badge ltms-badge-ok">✅ <?php esc_html_e( 'Sin eventos', 'ltms' ); ?></span>
        <?php endif; ?>
        <span class="ltms-sh-desc"><?php esc_html_e( 'Log forense inmutable · últimos 50 eventos', 'ltms' ); ?></span>
    </div>

    <?php if ( ! empty( $security_events ) ) : ?>
    <?php if ( $critical_sec > 0 ) : ?>
    <div class="ltms-info-card danger" style="margin-top:12px">
        ⚠️ <?php printf( esc_html__( 'Se detectaron %d evento(s) CRITICAL en este período. Revisión inmediata requerida.', 'ltms' ), $critical_sec ); ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ( empty( $security_events ) ) : ?>
        <div class="ltms-empty"><div class="ltms-empty-icon">✅</div><p><?php esc_html_e( 'No hay eventos de seguridad en este período.', 'ltms' ); ?></p></div>
    <?php else : ?>
    <div class="ltms-tw">
    <table class="widefat striped">
        <thead><tr>
            <th><?php esc_html_e( 'Nivel', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Tipo', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Usuario', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'IP', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Resumen', 'ltms' ); ?></th>
            <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ( $security_events as $ev ) : ?>
            <tr <?php echo strtolower($ev['level']) === 'critical' ? 'class="ltms-row-alert"' : ''; ?>>
                <td><?php echo ltms_level_badge( $ev['level'] ); ?></td>
                <td><?php echo esc_html( $ev['event_type'] ); ?></td>
                <td><?php $ud = $ev['user_id'] ? get_userdata( (int) $ev['user_id'] ) : false; echo esc_html( $ud ? $ud->user_login : '—' ); ?></td>
                <td><code><?php echo esc_html( LTMS_Data_Masking::mask_ip( $ev['ip_address'] ) ); ?></code></td>
                <td class="ltms-small"><?php echo esc_html( wp_trim_words( $ev['summary'] ?? '', 12 ) ); ?></td>
                <td class="ltms-date"><?php echo esc_html( $ev['created_at'] ); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <!-- ══ BASE NORMATIVA ═══════════════════════════════════════════════════ -->
    <div class="ltms-norma">
        <strong>📜 Base normativa:</strong>
        LIVA Art. 1-A BIS y 18-B &nbsp;·&nbsp;
        LISR Art. 113-A &nbsp;·&nbsp;
        LIEPS Art. 2 &nbsp;·&nbsp;
        CFF Art. 30-B &nbsp;·&nbsp;
        RMF 2025 Regla 12.2.10 &nbsp;·&nbsp;
        <strong>Ficha 168/CFF</strong> &nbsp;·&nbsp;
        E.T. Art. 437-2 (CO) &nbsp;·&nbsp;
        Res. DIAN 42/2020 &nbsp;·&nbsp;
        SAGRILAFT Res. 314/2021 (CO) &nbsp;·&nbsp;
        SARLAFT Res. 140/2023 SFC
        <span class="ltms-norma-sub">
            <?php esc_html_e( 'Información disponible en línea permanente por 5 años contados a partir de cada transacción — Art. 30-B párrafo tercero CFF / RMF 2025 Regla 12.2.10.', 'ltms' ); ?>
        </span>
    </div>

    <!-- ══ FOOTER ════════════════════════════════════════════════════════════ -->
    <div class="ltms-panel-footer">
        🔒 <?php esc_html_e( 'Esta vista es de solo lectura. Todos los accesos son registrados en el log forense inmutable.', 'ltms' ); ?>
        &nbsp;·&nbsp; <strong>Ficha 168/CFF</strong>
        &nbsp;·&nbsp; <?php esc_html_e( 'Credenciales entregadas a la Administración General de Planeación del SAT', 'ltms' ); ?>
        &nbsp;·&nbsp; <strong>LTMS v2.5.4</strong>
        &nbsp;·&nbsp; <?php echo $now_label; ?>
    </div>

</div><!-- .wrap.ltms-ap -->

<script>
function ltmsTab(btn, paneId) {
    document.querySelectorAll('.ltms-tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.ltms-tab-pane').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(paneId).classList.add('active');
}
</script>
