<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LTMS_Auditor_Export {

    public static function generate_csv( array $args ): array {
        global $wpdb;

        $date_from = $args['date_from'] ?? '2000-01-01';
        $date_to   = $args['date_to']   ?? date('Y-m-d');
        $country   = $args['country']   ?? '';
        $limit     = intval( $args['limit'] ?? 500 );

        $where  = "WHERE c.status != 'sandbox' AND c.created_at BETWEEN %s AND %s";
        $params = [ $date_from . ' 00:00:00', $date_to . ' 23:59:59' ];
        if ( $country ) { $where .= " AND c.country_code = %s"; $params[] = $country; }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.*,
                        u.user_email   AS vendor_email,
                        u.display_name AS vendor_name,
                        um_rfc.meta_value   AS vendor_rfc,
                        um_curp.meta_value  AS vendor_curp,
                        um_dom.meta_value   AS vendor_domicilio,
                        um_pais.meta_value  AS vendor_pais,
                        um_banco.meta_value AS vendor_banco,
                        um_clabe.meta_value AS vendor_clabe
                 FROM {$wpdb->prefix}lt_commissions c
                 LEFT JOIN {$wpdb->users} u        ON u.ID = c.vendor_id
                 LEFT JOIN {$wpdb->usermeta} um_rfc   ON um_rfc.user_id  = c.vendor_id AND um_rfc.meta_key   = 'ltms_vendor_rfc'
                 LEFT JOIN {$wpdb->usermeta} um_curp  ON um_curp.user_id = c.vendor_id AND um_curp.meta_key  = 'ltms_vendor_curp'
                 LEFT JOIN {$wpdb->usermeta} um_dom   ON um_dom.user_id  = c.vendor_id AND um_dom.meta_key   = 'ltms_vendor_domicilio'
                 LEFT JOIN {$wpdb->usermeta} um_pais  ON um_pais.user_id = c.vendor_id AND um_pais.meta_key  = 'ltms_vendor_pais'
                 LEFT JOIN {$wpdb->usermeta} um_banco ON um_banco.user_id= c.vendor_id AND um_banco.meta_key = 'ltms_vendor_banco'
                 LEFT JOIN {$wpdb->usermeta} um_clabe ON um_clabe.user_id= c.vendor_id AND um_clabe.meta_key = 'ltms_vendor_clabe'
                 $where ORDER BY c.id DESC LIMIT $limit",
                ...$params
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) return [ 'error' => 'Sin datos' ];

        $upload = wp_upload_dir();
        $date_tag = sanitize_file_name( $date_from . '_' . $date_to );
        $file = $upload['basedir'] . '/ltms-fiscal-30b-' . $date_tag . '.csv';
        $fp   = fopen( $file, 'w' );

        // — Encabezado del reporte —
        $plataforma = get_bloginfo('url');
        $generado   = current_time('Y-m-d H:i:s');
        $usuario    = wp_get_current_user()->display_name ?? 'sistema';

        fputcsv( $fp, [ '# LTMS — Reporte Fiscal Art. 30-B CFF / E.T. Art. 437-2 CO' ] );
        fputcsv( $fp, [ '# Plataforma:', $plataforma ] );
        fputcsv( $fp, [ '# Período:', $date_from, $date_to ] );
        fputcsv( $fp, [ '# País filtro:', $country ?: 'Todos (MX + CO)' ] );
        fputcsv( $fp, [ '# Norma MX:', 'Regla 12.2.10 RMF 2025 — Art. 30-B CFF — Ficha 168/CFF' ] );
        fputcsv( $fp, [ '# Norma CO:', 'E.T. Art. 437-2 — SAGRILAFT Res. 314/2021 — SARLAFT Res. 140/2023 SFC' ] );
        fputcsv( $fp, [ '# Formato numérico:', 'Punto decimal, sin separador de miles (CFDI 4.0 Anexo 20 / DIAN Res. 000042)' ] );
        fputcsv( $fp, [ '# Generado:', $generado, 'por', $usuario ] );
        fputcsv( $fp, [] );

        // — FRACCIÓN I —
        fputcsv( $fp, [ '### FRACCIÓN I — Servicios / Operaciones (Art. 30-B CFF inciso I / E.T. 437-2)' ] );
        fputcsv( $fp, [
            'FRAC', 'ID_TRANSACCION', 'ID_ORDEN', 'PAIS', 'FECHA_OPERACION',
            'a) TIPO_SERVICIO_U_OPERACION',
            'b) RFC_CLIENTE',
            'c) PRECIO_SIN_IVA',
            'd) IVA_TRASLADADO',
            'e) PRECIO_FINAL_CON_IVA',
            'f) FOLIO_CFDI_UUID',
            'g) METODO_PAGO_ADQUIRIENTE',
            'VENDOR_ID',
        ] );

        foreach ( $rows as $r ) {
            fputcsv( $fp, [
                'I',
                $r['id'],
                $r['order_id'],
                $r['country_code'],
                $r['created_at'],
                $r['service_type']            ?: '',
                $r['rfc_cliente']             ?: '',
                number_format( (float)$r['gross_amount'] - (float)$r['iva_retenido'], 2, '.', '' ),
                number_format( (float)$r['iva_retenido'], 2, '.', '' ),
                number_format( (float)$r['gross_amount'], 2, '.', '' ),
                $r['cfdi_folio']              ?: '',
                $r['payment_method_buyer']    ?: '',
                $r['vendor_id'],
            ] );
        }

        fputcsv( $fp, [] );

        // — FRACCIÓN II — agrupar por vendor —
        fputcsv( $fp, [ '### FRACCIÓN II — Vendedores / Intermediados (Art. 30-B CFF inciso II / E.T. 437-2)' ] );
        fputcsv( $fp, [
            'FRAC', 'VENDOR_ID', 'EMAIL',
            'a) NOMBRE_RAZON_SOCIAL',
            'b) RFC_NIF_FISCAL',
            'c) CURP_PF_MX',
            'd) DOMICILIO_FISCAL_RESIDENCIA',
            'd) PAIS_RESIDENCIA',
            'e) INSTITUCION_FINANCIERA',
            'e) CLABE_CUENTA_BANCARIA',
            'f-i) MONTO_ISR',
            'f-ii) MONTO_IVA',
            'f-iii) MONTO_IEPS',
            'f-iv-a) METODO_PAGO_ADQUIRIENTE',
            'f-iv-b) METODO_PAGO_OFERENTE',
            'f-iv-c) METODO_PAGO_PLATAFORMA',
            'f-v) ISR_RETENIDO',
            'f-vi) IVA_RETENIDO',
            'f-vii) IEPS_RETENIDO',
            'g) HOSPEDAJE_OPS',
            'g) HOSPEDAJE_DIRECCION_INMUEBLE',
            'h) IMPORTACION_OPS',
            'h) ARANCELES_MONTO',
            'TOTAL_OPERACIONES',
            'PRIMERA_OPERACION',
            'ULTIMA_OPERACION',
        ] );

        // Agrupar por vendor_id
        $vendors = [];
        foreach ( $rows as $r ) {
            $vid = $r['vendor_id'];
            if ( ! isset( $vendors[ $vid ] ) ) {
                $vendors[ $vid ] = [
                    'vendor_id'          => $vid,
                    'email'              => $r['vendor_email']    ?: '',
                    'nombre'             => $r['vendor_name']     ?: '',
                    'rfc'                => $r['vendor_rfc']      ?: '',
                    'curp'               => $r['vendor_curp']     ?: '',
                    'domicilio'          => $r['vendor_domicilio'] ?: '',
                    'pais'               => $r['vendor_pais']     ?: $r['country_code'],
                    'banco'              => $r['vendor_banco']    ?: '',
                    'clabe'              => $r['vendor_clabe']    ?: '',
                    'isr'                => 0.0,
                    'iva'                => 0.0,
                    'ieps'               => 0.0,
                    'metodo_adquiriente' => [],
                    'metodo_oferente'    => [],
                    'metodo_plataforma'  => [],
                    'isr_ret'            => 0.0,
                    'iva_ret'            => 0.0,
                    'ieps_ret'           => 0.0,
                    'hospedaje_ops'      => 0,
                    'hospedaje_dir'      => '',
                    'importacion_ops'    => 0,
                    'aranceles'          => 0.0,
                    'total_ops'          => 0,
                    'primera'            => $r['created_at'],
                    'ultima'             => $r['created_at'],
                ];
            }
            $v = &$vendors[ $vid ];
            $v['isr']     += (float)$r['isr_amount'];
            $v['iva']     += (float)$r['iva_retenido'];
            $v['ieps']    += (float)$r['ieps_amount'];
            $v['isr_ret'] += (float)$r['isr_amount'];
            $v['iva_ret'] += (float)$r['iva_retenido'];
            $v['ieps_ret']+= (float)$r['ieps_retenido'];
            if ( $r['payment_method_buyer'] )    $v['metodo_adquiriente'][] = $r['payment_method_buyer'];
            if ( $r['payment_method_vendor'] )   $v['metodo_oferente'][]    = $r['payment_method_vendor'];
            if ( $r['payment_method_platform'] ) $v['metodo_plataforma'][]  = $r['payment_method_platform'];
            if ( $r['service_type'] === 'hospedaje' ) {
                $v['hospedaje_ops']++;
                $v['hospedaje_dir'] = $r['metadata'] ?? '';
            }
            $v['total_ops']++;
            if ( $r['created_at'] < $v['primera'] ) $v['primera'] = $r['created_at'];
            if ( $r['created_at'] > $v['ultima']  ) $v['ultima']  = $r['created_at'];
        }

        foreach ( $vendors as $v ) {
            fputcsv( $fp, [
                'II',
                $v['vendor_id'],
                $v['email'],
                $v['nombre'],
                $v['rfc'],
                $v['curp'],
                $v['domicilio'],
                $v['pais'],
                $v['banco'],
                $v['clabe'],
                number_format( $v['isr'],     2, '.', '' ),
                number_format( $v['iva'],     2, '.', '' ),
                number_format( $v['ieps'],    2, '.', '' ),
                implode( '|', array_unique( $v['metodo_adquiriente'] ) ),
                implode( '|', array_unique( $v['metodo_oferente'] ) ),
                implode( '|', array_unique( $v['metodo_plataforma'] ) ),
                number_format( $v['isr_ret'],  2, '.', '' ),
                number_format( $v['iva_ret'],  2, '.', '' ),
                number_format( $v['ieps_ret'], 2, '.', '' ),
                $v['hospedaje_ops'],
                $v['hospedaje_dir'],
                $v['importacion_ops'],
                number_format( $v['aranceles'], 2, '.', '' ),
                $v['total_ops'],
                $v['primera'],
                $v['ultima'],
            ] );
        }

        fputcsv( $fp, [] );

        // — SAGRILAFT —
        $umbral = 470650000;
        $alto_valor = array_filter( $rows, fn($r) => (float)$r['gross_amount'] >= $umbral );
        fputcsv( $fp, [ '### SAGRILAFT / SARLAFT — Retiros alto valor (Res. 314/2021 CO · Umbral 470,650,000 COP)' ] );
        if ( empty( $alto_valor ) ) {
            fputcsv( $fp, [ '# Sin retiros de alto valor en el período.' ] );
        } else {
            fputcsv( $fp, [ 'ID', 'VENDOR_ID', 'MONTO', 'FECHA' ] );
            foreach ( $alto_valor as $r ) {
                fputcsv( $fp, [ $r['id'], $r['vendor_id'], $r['gross_amount'], $r['created_at'] ] );
            }
        }

        fclose( $fp );
        return [ 'file' => $file, 'rows' => count( $rows ) ];
    }
}
