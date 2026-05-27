<?php
/**
 * LTMS — Metabox "Guías Deprisa" en el pedido WooCommerce
 *
 * Muestra en la pantalla de edición del pedido las guías generadas
 * por LTMS_Deprisa_Order_Split, con estado, origen, destino y botones
 * para descargar cada etiqueta PDF o relanzar el split manualmente.
 *
 * Hooks registrados (llamar desde ltms-deprisa-loader.php):
 *   add_action( 'add_meta_boxes',            [ LTMS_Deprisa_Order_Metabox::class, 'register' ] );
 *   add_action( 'admin_enqueue_scripts',     [ LTMS_Deprisa_Order_Metabox::class, 'enqueue_scripts' ] );
 *   add_action( 'wp_ajax_ltms_deprisa_download_etiqueta', [ LTMS_Deprisa_Order_Metabox::class, 'ajax_download_etiqueta' ] );
 *
 * @package LTMS
 * @since   1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LTMS_Deprisa_Order_Metabox {

    /* ------------------------------------------------------------------ */
    /* Registro del metabox                                                 */
    /* ------------------------------------------------------------------ */

    public static function register(): void {
        if ( ! get_option( 'ltms_deprisa_enabled' ) ) {
            return;
        }

        // WooCommerce HPOS (Custom Order Tables) y editor clásico
        $screens = [ 'shop_order', 'woocommerce_page_wc-orders' ];

        foreach ( $screens as $screen ) {
            add_meta_box(
                'ltms_deprisa_guias',
                '🚚 Guías Deprisa',
                [ self::class, 'render' ],
                $screen,
                'normal',
                'high'
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Scripts / estilos inline                                             */
    /* ------------------------------------------------------------------ */

    public static function enqueue_scripts( string $hook ): void {
        $order_screens = [ 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' ];
        if ( ! in_array( $hook, $order_screens, true ) ) {
            return;
        }

        // CSS inline mínimo
        $css = '
            .ltms-deprisa-metabox { font-size:13px; }
            .ltms-deprisa-metabox table { width:100%; border-collapse:collapse; }
            .ltms-deprisa-metabox th,
            .ltms-deprisa-metabox td { padding:6px 8px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
            .ltms-deprisa-metabox th { background:#f9f9f9; font-weight:600; text-align:left; }
            .ltms-deprisa-metabox .ltms-ok  { color:#2e7d32; font-weight:600; }
            .ltms-deprisa-metabox .ltms-err { color:#c62828; font-weight:600; }
            .ltms-deprisa-metabox .ltms-errors-list { color:#c62828; font-size:12px; margin:2px 0 0; padding-left:16px; }
            .ltms-deprisa-metabox .ltms-btn { display:inline-block; padding:3px 10px; font-size:12px; line-height:1.6; cursor:pointer; border-radius:3px; text-decoration:none; }
            .ltms-deprisa-metabox .ltms-btn-pdf  { background:#1976d2; color:#fff; border:none; }
            .ltms-deprisa-metabox .ltms-btn-pdf:hover { background:#1565c0; }
            .ltms-deprisa-metabox .ltms-btn-split { background:#f57f17; color:#fff; border:none; }
            .ltms-deprisa-metabox .ltms-btn-split:hover { background:#e65100; }
            .ltms-deprisa-metabox .ltms-spinner { display:none; margin-left:8px; vertical-align:middle; }
            .ltms-deprisa-metabox .ltms-msg { margin-top:8px; padding:6px 10px; border-radius:3px; font-size:13px; }
            .ltms-deprisa-metabox .ltms-msg-ok  { background:#e8f5e9; color:#2e7d32; border:1px solid #a5d6a7; }
            .ltms-deprisa-metabox .ltms-msg-err { background:#ffebee; color:#c62828; border:1px solid #ef9a9a; }
        ';
        wp_add_inline_style( 'woocommerce_admin_styles', $css );

        // JS inline para acciones AJAX
        $js = '
            jQuery(function($){
                // Relanzar split
                $(document).on("click", ".ltms-btn-split", function(){
                    var $btn = $(this);
                    var orderId = $btn.data("order-id");
                    var $spinner = $btn.siblings(".ltms-spinner");
                    var $msg    = $btn.closest(".inside").find(".ltms-msg");

                    $btn.prop("disabled", true);
                    $spinner.css("display","inline-block");
                    $msg.hide();

                    $.post(ajaxurl, {
                        action:   "ltms_deprisa_split_manual",
                        order_id: orderId,
                        _wpnonce: $btn.data("nonce")
                    }, function(resp){
                        $btn.prop("disabled", false);
                        $spinner.hide();
                        if(resp.success){
                            $msg.removeClass("ltms-msg-err").addClass("ltms-msg ltms-msg-ok").text(resp.data.message).show();
                            setTimeout(function(){ location.reload(); }, 1800);
                        } else {
                            $msg.removeClass("ltms-msg-ok").addClass("ltms-msg ltms-msg-err").text(resp.data.message || "Error desconocido.").show();
                        }
                    }).fail(function(){
                        $btn.prop("disabled", false);
                        $spinner.hide();
                        $msg.addClass("ltms-msg ltms-msg-err").text("Error de conexión.").show();
                    });
                });
            });
        ';
        wp_add_inline_script( 'jquery', $js );
    }

    /* ------------------------------------------------------------------ */
    /* Render del metabox                                                   */
    /* ------------------------------------------------------------------ */

    public static function render( $post_or_order ): void {
        // Compatibilidad HPOS vs clásico
        $order = $post_or_order instanceof WC_Order
            ? $post_or_order
            : wc_get_order( $post_or_order->ID );

        if ( ! $order ) {
            echo '<p>Pedido no disponible.</p>';
            return;
        }

        $order_id = $order->get_id();
        $guias    = LTMS_Deprisa_Order_Split::get_guias( $order );
        $split_at = $order->get_meta( LTMS_Deprisa_Order_Split::META_SPLIT_TS );
        $nonce    = wp_create_nonce( 'ltms_deprisa_split' );

        echo '<div class="ltms-deprisa-metabox">';

        /* ---- Cabecera con botón de relanzar ---- */
        echo '<p style="margin:0 0 10px;">';
        if ( $split_at ) {
            echo '<span style="color:#555;">Última ejecución: <strong>' . esc_html( $split_at ) . '</strong></span>&nbsp;&nbsp;';
        }
        echo '<button type="button" class="ltms-btn ltms-btn-split" '
            . 'data-order-id="' . esc_attr( $order_id ) . '" '
            . 'data-nonce="' . esc_attr( $nonce ) . '">'
            . ( $guias ? '🔄 Regenerar guías' : '🚀 Generar guías' )
            . '</button>';
        echo '<span class="ltms-spinner"><img src="' . esc_url( admin_url( 'images/spinner.gif' ) ) . '" width="16" height="16" alt=""></span>';
        echo '</p>';
        echo '<div class="ltms-msg" style="display:none;"></div>';

        /* ---- Sin guías todavía ---- */
        if ( empty( $guias ) ) {
            echo '<p style="color:#777; font-style:italic;">No hay guías generadas para este pedido.</p>';
            echo '</div>';
            return;
        }

        /* ---- Tabla de guías ---- */
        echo '<table>';
        echo '<thead><tr>'
            . '<th>Vendedor</th>'
            . '<th>Guía</th>'
            . '<th>Origen → Destino</th>'
            . '<th>Servicio</th>'
            . '<th>Bultos / kg</th>'
            . '<th>Ítems</th>'
            . '<th>Estado</th>'
            . '<th>Etiqueta</th>'
            . '</tr></thead><tbody>';

        foreach ( $guias as $resultado ) {
            $vendor_id    = $resultado['vendor_id'] ?? '—';
            $ok           = ! empty( $resultado['ok'] );
            $numero_envio = $resultado['numero_envio'] ?? '';
            $remitente    = $resultado['remitente']    ?? '—';
            $destino      = $resultado['destino']      ?? '—';
            $servicio     = $resultado['servicio']     ?? '—';
            $bultos       = $resultado['bultos']       ?? '—';
            $peso         = $resultado['peso']         ?? '—';
            $items        = $resultado['items_count']  ?? '—';
            $errors       = $resultado['errors']       ?? [];
            $etiqueta     = $resultado['etiqueta_b64'] ?? '';
            $source       = $resultado['source']       ?? '';
            $gen_at       = $resultado['generated_at'] ?? '';

            // Nombre del vendedor
            $vendor_name = $vendor_id === 0
                ? '<em>Tienda</em>'
                : '<a href="' . esc_url( get_edit_user_link( $vendor_id ) ) . '">' . esc_html( get_userdata( $vendor_id )->display_name ?? "#{$vendor_id}" ) . '</a>';

            // Estado
            $estado_html = $ok
                ? '<span class="ltms-ok">✅ OK</span>'
                : '<span class="ltms-err">❌ Error</span>';

            // Errores detalle
            $errores_html = '';
            if ( ! $ok && ! empty( $errors ) ) {
                $errores_html .= '<ul class="ltms-errors-list">';
                foreach ( $errors as $err ) {
                    $desc = is_array( $err ) ? ( $err['descripcion'] ?? json_encode( $err ) ) : $err;
                    $errores_html .= '<li>' . esc_html( $desc ) . '</li>';
                }
                $errores_html .= '</ul>';
            }

            // Botón PDF
            $pdf_html = '—';
            if ( $ok && $numero_envio ) {
                if ( $etiqueta ) {
                    $pdf_url = wp_nonce_url(
                        admin_url( 'admin-ajax.php?action=ltms_deprisa_download_etiqueta&order_id=' . $order_id . '&numero_envio=' . urlencode( $numero_envio ) ),
                        'ltms_deprisa_dl_' . $order_id
                    );
                    $pdf_html = '<a href="' . esc_url( $pdf_url ) . '" class="ltms-btn ltms-btn-pdf" target="_blank">📥 PDF</a>';
                } else {
                    $pdf_html = '<em style="color:#999;">Sin etiqueta</em>';
                }
            }

            // Tooltip con info extra
            $title = $source ? "Fuente: {$source}" : '';
            if ( $gen_at ) {
                $title .= ( $title ? ' | ' : '' ) . "Generado: {$gen_at}";
            }

            echo '<tr title="' . esc_attr( $title ) . '">';
            echo '<td>' . $vendor_name . '</td>';
            echo '<td><code>' . esc_html( $numero_envio ?: ( $resultado['codigo_admision'] ?? '—' ) ) . '</code></td>';
            echo '<td>' . esc_html( $remitente ) . ' → ' . esc_html( $destino ) . '</td>';
            echo '<td>' . esc_html( $servicio ) . '</td>';
            echo '<td>' . esc_html( $bultos ) . ' / ' . esc_html( $peso ) . ' kg</td>';
            echo '<td>' . esc_html( $items ) . '</td>';
            echo '<td>' . $estado_html . $errores_html . '</td>';
            echo '<td>' . $pdf_html . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    /* ------------------------------------------------------------------ */
    /* AJAX: descarga de etiqueta PDF                                       */
    /* ------------------------------------------------------------------ */

    public static function ajax_download_etiqueta(): void {
        check_ajax_referer( 'ltms_deprisa_dl_' . absint( $_GET['order_id'] ?? 0 ) );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Sin permisos.', '', [ 'response' => 403 ] );
        }

        $order_id     = absint( $_GET['order_id']     ?? 0 );
        $numero_envio = sanitize_text_field( $_GET['numero_envio'] ?? '' );
        $order        = wc_get_order( $order_id );

        if ( ! $order || ! $numero_envio ) {
            wp_die( 'Parámetros inválidos.', '', [ 'response' => 400 ] );
        }

        LTMS_Deprisa_Order_Split::serve_etiqueta_pdf( $order, $numero_envio );
    }
}
