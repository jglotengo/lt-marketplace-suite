<?php
/**
 * LTMS — Metabox "Guías Deprisa" en el pedido WooCommerce v1.10.0
 *
 * @package LTMS
 * @since   1.9.0 / 1.10.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LTMS_Deprisa_Order_Metabox {

    public static function register(): void {
        if ( ! get_option( 'ltms_deprisa_enabled' ) ) return;
        foreach ( [ 'shop_order', 'woocommerce_page_wc-orders' ] as $screen ) {
            add_meta_box( 'ltms_deprisa_guias', '🚚 Guías Deprisa', [ self::class, 'render' ], $screen, 'normal', 'high' );
        }
    }

    public static function enqueue_scripts( string $hook ): void {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' ], true ) ) return;

        $css = '
            .ltms-deprisa-metabox { font-size:13px; }
            .ltms-deprisa-metabox table { width:100%; border-collapse:collapse; }
            .ltms-deprisa-metabox th, .ltms-deprisa-metabox td { padding:6px 8px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
            .ltms-deprisa-metabox th { background:#f9f9f9; font-weight:600; text-align:left; }
            .ltms-deprisa-metabox .ltms-ok   { color:#2e7d32; font-weight:600; }
            .ltms-deprisa-metabox .ltms-err  { color:#c62828; font-weight:600; }
            .ltms-deprisa-metabox .ltms-warn { color:#e65100; font-weight:600; }
            .ltms-deprisa-metabox .ltms-btn { display:inline-block; padding:3px 10px; font-size:12px; line-height:1.6; cursor:pointer; border-radius:3px; text-decoration:none; margin:2px 2px 0 0; }
            .ltms-deprisa-metabox .ltms-btn-pdf   { background:#1976d2; color:#fff; border:none; }
            .ltms-deprisa-metabox .ltms-btn-split { background:#f57f17; color:#fff; border:none; }
            .ltms-deprisa-metabox .ltms-btn-track { background:#00796b; color:#fff; border:none; }
            .ltms-deprisa-metabox .ltms-btn-dev   { background:#6a1b9a; color:#fff; border:none; }
            .ltms-deprisa-metabox .ltms-btn-devcancel { background:#999; color:#fff; border:none; font-size:11px; padding:2px 7px; }
            .ltms-deprisa-metabox .ltms-spinner { display:none; margin-left:8px; vertical-align:middle; }
            .ltms-deprisa-metabox .ltms-msg { margin-top:8px; padding:6px 10px; border-radius:3px; font-size:13px; display:none; }
            .ltms-deprisa-metabox .ltms-msg-ok  { background:#e8f5e9; color:#2e7d32; border:1px solid #a5d6a7; }
            .ltms-deprisa-metabox .ltms-msg-err { background:#ffebee; color:#c62828; border:1px solid #ef9a9a; }
            .ltms-deprisa-metabox .ltms-tracking-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; }
        ';
        wp_add_inline_style( 'woocommerce_admin_styles', $css );

        $js = '
        jQuery(function($){
            function ltms_ajax(action, data, $btn, $spin, $msg, reload_delay) {
                $btn.prop("disabled", true);
                $spin.css("display", "inline-block");
                $msg.hide().removeClass("ltms-msg-ok ltms-msg-err");
                $.post(ajaxurl, $.extend({ action: action, _wpnonce: $btn.data("nonce") }, data), function(r) {
                    $btn.prop("disabled", false);
                    $spin.hide();
                    $msg.addClass("ltms-msg " + (r.success ? "ltms-msg-ok" : "ltms-msg-err")).text(r.data.message || "Error.").show();
                    if (r.success && reload_delay) setTimeout(function(){ location.reload(); }, reload_delay);
                }).fail(function(){
                    $btn.prop("disabled", false);
                    $spin.hide();
                    $msg.addClass("ltms-msg ltms-msg-err").text("Error de conexión.").show();
                });
            }

            $(document).on("click", ".ltms-btn-split", function(){
                var $b = $(this), $inside = $b.closest(".inside");
                ltms_ajax("ltms_deprisa_split_manual", { order_id: $b.data("order-id") }, $b, $b.siblings(".ltms-spinner"), $inside.find(".ltms-msg"), 1800);
            });

            $(document).on("click", ".ltms-btn-track", function(){
                var $b = $(this), $inside = $b.closest(".inside");
                ltms_ajax("ltms_deprisa_tracking_manual", { order_id: $b.data("order-id") }, $b, $b.siblings(".ltms-spinner"), $inside.find(".ltms-msg"), 1800);
            });

            $(document).on("click", ".ltms-btn-dev", function(){
                var $b = $(this);
                var motivo = prompt("Motivo de la devolución:", "Devolución solicitada por el cliente");
                if (motivo === null) return;
                var $inside = $b.closest(".inside");
                ltms_ajax("ltms_deprisa_generar_devolucion", { order_id: $b.data("order-id"), numero_envio: $b.data("numero-envio"), motivo: motivo }, $b, $b.siblings(".ltms-spinner"), $inside.find(".ltms-msg"), 2000);
            });

            $(document).on("click", ".ltms-btn-devcancel", function(){
                if (!confirm("¿Cancelar el registro de devolución de esta guía?")) return;
                var $b = $(this), $inside = $b.closest(".inside");
                ltms_ajax("ltms_deprisa_cancelar_devolucion", { order_id: $b.data("order-id"), numero_envio: $b.data("numero-envio") }, $b, $b.siblings(".ltms-spinner"), $inside.find(".ltms-msg"), 1500);
            });
        });
        ';
        wp_add_inline_script( 'jquery', $js );
    }

    public static function render( $post_or_order ): void {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
        if ( ! $order ) { echo '<p>Pedido no disponible.</p>'; return; }

        $order_id    = $order->get_id();
        $guias       = LTMS_Deprisa_Order_Split::get_guias( $order );
        $split_at    = $order->get_meta( LTMS_Deprisa_Order_Split::META_SPLIT_TS );
        $tracking_at = $order->get_meta( '_ltms_deprisa_tracking_last' );
        $nonce_split = wp_create_nonce( 'ltms_deprisa_split' );
        $nonce_track = wp_create_nonce( 'ltms_deprisa_tracking' );
        $nonce_dev   = wp_create_nonce( 'ltms_deprisa_devolucion' );

        echo '<div class="ltms-deprisa-metabox">';
        echo '<p style="margin:0 0 10px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">';
        if ( $split_at )    echo '<span style="color:#555;font-size:12px;">Split: <strong>' . esc_html( $split_at ) . '</strong></span>';
        if ( $tracking_at ) echo '<span style="color:#555;font-size:12px;">Tracking: <strong>' . esc_html( $tracking_at ) . '</strong></span>';

        echo '<button type="button" class="ltms-btn ltms-btn-split" data-order-id="' . esc_attr( $order_id ) . '" data-nonce="' . esc_attr( $nonce_split ) . '">'
            . ( $guias ? '🔄 Regenerar guías' : '🚀 Generar guías' ) . '</button>';
        if ( $guias ) {
            echo '<button type="button" class="ltms-btn ltms-btn-track" data-order-id="' . esc_attr( $order_id ) . '" data-nonce="' . esc_attr( $nonce_track ) . '">📡 Actualizar tracking</button>';
        }
        echo '<span class="ltms-spinner"><img src="' . esc_url( admin_url( 'images/spinner.gif' ) ) . '" width="16" height="16" alt=""></span>';
        echo '</p>';
        echo '<div class="ltms-msg"></div>';

        if ( empty( $guias ) ) {
            echo '<p style="color:#777;font-style:italic;">No hay guías generadas para este pedido.</p></div>';
            return;
        }

        echo '<table><thead><tr>'
            . '<th>Vendedor</th><th>Guía</th><th>Origen → Destino</th>'
            . '<th>Servicio</th><th>Bultos / kg</th><th>Tracking</th><th>Acciones</th>'
            . '</tr></thead><tbody>';

        foreach ( $guias as $r ) {
            $vendor_id    = $r['vendor_id']    ?? 0;
            $ok           = ! empty( $r['ok'] );
            $numero_envio = $r['numero_envio'] ?? '';
            $remitente    = $r['remitente']    ?? '—';
            $destino      = $r['destino']      ?? '—';
            $servicio     = $r['servicio']     ?? '—';
            $bultos       = $r['bultos']       ?? '—';
            $peso         = $r['peso']         ?? '—';
            $errors       = $r['errors']       ?? [];
            $etiqueta     = $r['etiqueta_b64'] ?? '';
            $gen_at       = $r['generated_at'] ?? '';

            $vendor_name = $vendor_id === 0
                ? '<em>Tienda</em>'
                : '<a href="' . esc_url( get_edit_user_link( $vendor_id ) ) . '">' . esc_html( get_userdata( $vendor_id )->display_name ?? "#$vendor_id" ) . '</a>';

            // Tracking badge
            $tracking_html = '—';
            if ( $ok && $numero_envio ) {
                $tk_estado = $order->get_meta( '_ltms_deprisa_tracking_' . $numero_envio );
                if ( $tk_estado ) {
                    $color = self::tracking_color( $tk_estado );
                    $tracking_html = '<span class="ltms-tracking-badge" style="background:' . $color . ';color:#fff;">' . esc_html( $tk_estado ) . '</span>';
                    $tk_detail = $order->get_meta( '_ltms_deprisa_tracking_' . $numero_envio . '_detail' );
                    if ( $tk_detail ) {
                        $tk = json_decode( $tk_detail, true );
                        $estados = $tk['estados'] ?? [];
                        if ( $estados ) {
                            $last = end( $estados );
                            $tracking_html .= '<br><small style="color:#666;">' . esc_html( $last['descripcion'] ?? '' ) . '</small>';
                            if ( ! empty( $last['fechaEvento'] ) ) $tracking_html .= '<br><small style="color:#999;">' . esc_html( $last['fechaEvento'] ) . '</small>';
                        }
                    }
                } else {
                    $tracking_html = '<span style="color:#999;font-size:12px;">Sin consultar</span>';
                }
            } elseif ( ! $ok ) {
                $desc_err = array_column( $errors, 'descripcion' );
                $tracking_html = '<span class="ltms-err">❌ ' . esc_html( implode( ', ', array_slice( $desc_err, 0, 2 ) ) ) . '</span>';
            }

            // Devolución
            $devolucion = $ok && $numero_envio && class_exists( 'LTMS_Deprisa_Devoluciones' )
                ? LTMS_Deprisa_Devoluciones::get_devolucion( $order, $numero_envio )
                : null;

            // Acciones
            $acciones = '';
            if ( $ok && $numero_envio ) {
                if ( $etiqueta ) {
                    $pdf_url = wp_nonce_url(
                        admin_url( 'admin-ajax.php?action=ltms_deprisa_download_etiqueta&order_id=' . $order_id . '&numero_envio=' . urlencode( $numero_envio ) ),
                        'ltms_deprisa_dl_' . $order_id
                    );
                    $acciones .= '<a href="' . esc_url( $pdf_url ) . '" class="ltms-btn ltms-btn-pdf" target="_blank">📥 Etiqueta</a>';
                }
                if ( $devolucion ) {
                    $num_dev = $devolucion['numero_envio_devolucion'] ?? '';
                    $acciones .= ' <span class="ltms-warn">↩️ Dev: <code>' . esc_html( $num_dev ) . '</code></span>';
                    if ( ! empty( $devolucion['etiqueta_b64'] ) ) {
                        $dev_pdf_url = wp_nonce_url(
                            admin_url( 'admin-ajax.php?action=ltms_deprisa_download_etiqueta&order_id=' . $order_id . '&numero_envio=' . urlencode( $num_dev ) ),
                            'ltms_deprisa_dl_' . $order_id
                        );
                        $acciones .= ' <a href="' . esc_url( $dev_pdf_url ) . '" class="ltms-btn ltms-btn-pdf" target="_blank">📥 PDF Dev.</a>';
                    }
                    $acciones .= ' <button type="button" class="ltms-btn ltms-btn-devcancel" data-order-id="' . esc_attr( $order_id ) . '" data-numero-envio="' . esc_attr( $numero_envio ) . '" data-nonce="' . esc_attr( $nonce_dev ) . '">✕ Cancelar dev.</button>';
                } else {
                    $acciones .= ' <button type="button" class="ltms-btn ltms-btn-dev" data-order-id="' . esc_attr( $order_id ) . '" data-numero-envio="' . esc_attr( $numero_envio ) . '" data-nonce="' . esc_attr( $nonce_dev ) . '">↩️ Devolución</button>';
                }
                $acciones .= '<span class="ltms-spinner"><img src="' . esc_url( admin_url( 'images/spinner.gif' ) ) . '" width="14" height="14" alt=""></span>';
            }

            echo '<tr title="' . esc_attr( $gen_at ? "Generado: $gen_at" : '' ) . '">';
            echo '<td>' . $vendor_name . '</td>';
            echo '<td><code>' . esc_html( $numero_envio ?: ( $r['codigo_admision'] ?? '—' ) ) . '</code></td>';
            echo '<td>' . esc_html( $remitente ) . ' → ' . esc_html( $destino ) . '</td>';
            echo '<td>' . esc_html( $servicio ) . '</td>';
            echo '<td>' . esc_html( $bultos ) . ' / ' . esc_html( $peso ) . ' kg</td>';
            echo '<td>' . $tracking_html . '</td>';
            echo '<td>' . $acciones . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    public static function ajax_download_etiqueta(): void {
        check_ajax_referer( 'ltms_deprisa_dl_' . absint( $_GET['order_id'] ?? 0 ) );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Sin permisos.', '', [ 'response' => 403 ] );

        $order_id     = absint( $_GET['order_id']     ?? 0 );
        $numero_envio = sanitize_text_field( $_GET['numero_envio'] ?? '' );
        $order        = wc_get_order( $order_id );

        if ( ! $order || ! $numero_envio ) wp_die( 'Parámetros inválidos.', '', [ 'response' => 400 ] );

        LTMS_Deprisa_Order_Split::serve_etiqueta_pdf( $order, $numero_envio );
    }

    private static function tracking_color( string $estado ): string {
        $map = [
            'ENTREGADO'   => '#2e7d32', 'ENTREGA'     => '#2e7d32',
            'EN_TRANSITO' => '#1565c0', 'EN TRÁNSITO' => '#1565c0', 'CLASIFICADO' => '#1565c0',
            'DEVUELTO'    => '#e65100', 'CANCELADO'   => '#c62828',
            'INCIDENCIA'  => '#b71c1c', 'ALTA'        => '#555',
        ];
        return $map[ strtoupper( $estado ) ] ?? '#555';
    }
}
