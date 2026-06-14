<?php
/**
 * Admin View: Ave-Hub — Logs de reporte de estados de envíos
 *
 * Muestra dos tablas:
 *   1. Log local (lt_aveonline_hub_push_log) — registros de cada push
 *      realizado desde este sitio vía LTMS_Api_Aveonline_Hub::push_events().
 *   2. Log remoto — eventos confirmados por Ave-Hub, consultados en vivo
 *      vía AJAX (LTMS_Api_Aveonline_Hub::get_logs()).
 *
 * @package LTMS
 * @version 2.9.2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! current_user_can( 'ltms_manage_platform_settings' ) ) {
    wp_die( esc_html__( 'No tienes permisos para ver esta página.', 'ltms' ) );
}

// ── Filtros (GET, log local) ────────────────────────────────────────────────
$filter_id_envio     = sanitize_text_field( wp_unslash( $_GET['id_envio'] ?? '' ) ); // phpcs:ignore
$filter_status       = sanitize_key( $_GET['status'] ?? '' ); // phpcs:ignore
$filter_fecha_inicio = sanitize_text_field( wp_unslash( $_GET['fecha_inicio'] ?? '' ) ); // phpcs:ignore
$filter_fecha_fin    = sanitize_text_field( wp_unslash( $_GET['fecha_fin'] ?? '' ) ); // phpcs:ignore

$page     = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore
$per_page = 25;
$offset   = ( $page - 1 ) * $per_page;

$filters = array_filter( [
    'id_envio'     => $filter_id_envio,
    'status'       => $filter_status,
    'fecha_inicio' => $filter_fecha_inicio,
    'fecha_fin'    => $filter_fecha_fin,
] );

$local_logs  = class_exists( 'LTMS_Business_Aveonline_Hub_Log' )
    ? LTMS_Business_Aveonline_Hub_Log::get_recent( $per_page, $offset, $filters )
    : [];
$total_count = class_exists( 'LTMS_Business_Aveonline_Hub_Log' )
    ? LTMS_Business_Aveonline_Hub_Log::count_filtered( $filters )
    : 0;
$total_pages = (int) ceil( $total_count / $per_page );

$id_transportadora = get_option( 'ltms_aveonline_hub_idtransportadora', '' );
?>
<div class="wrap ltms-admin-wrap">
    <h1>
        🛰️ <?php esc_html_e( 'Ave-Hub — Logs de Reporte de Estados', 'ltms' ); ?>
    </h1>

    <?php if ( empty( $id_transportadora ) ) : ?>
        <div class="notice notice-warning">
            <p>
                <?php esc_html_e( 'No se ha configurado el ID de transportadora para Ave-Hub.', 'ltms' ); ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-settings&tab=aveonline' ) ); ?>">
                    <?php esc_html_e( 'Ir a Configuración → Aveonline', 'ltms' ); ?>
                </a>
            </p>
        </div>
    <?php endif; ?>

    <h2 class="title"><?php esc_html_e( 'Log local de envíos a Ave-Hub', 'ltms' ); ?></h2>
    <p class="description">
        <?php esc_html_e( 'Registro de cada intento de push_events() realizado desde este sitio (éxito o error).', 'ltms' ); ?>
    </p>

    <form method="get" style="margin:12px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
        <input type="hidden" name="page" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_GET['page'] ?? 'ltms-aveonline-hub' ) ) ); ?>">

        <div>
            <label for="ltms-hub-id-envio"><?php esc_html_e( 'ID Envío / Pedido', 'ltms' ); ?></label><br>
            <input type="text" id="ltms-hub-id-envio" name="id_envio" value="<?php echo esc_attr( $filter_id_envio ); ?>">
        </div>

        <div>
            <label for="ltms-hub-status"><?php esc_html_e( 'Estado', 'ltms' ); ?></label><br>
            <select id="ltms-hub-status" name="status">
                <option value=""><?php esc_html_e( 'Todos', 'ltms' ); ?></option>
                <option value="success" <?php selected( $filter_status, 'success' ); ?>><?php esc_html_e( 'Éxito', 'ltms' ); ?></option>
                <option value="error" <?php selected( $filter_status, 'error' ); ?>><?php esc_html_e( 'Error', 'ltms' ); ?></option>
            </select>
        </div>

        <div>
            <label for="ltms-hub-fecha-inicio"><?php esc_html_e( 'Desde', 'ltms' ); ?></label><br>
            <input type="date" id="ltms-hub-fecha-inicio" name="fecha_inicio" value="<?php echo esc_attr( $filter_fecha_inicio ); ?>">
        </div>

        <div>
            <label for="ltms-hub-fecha-fin"><?php esc_html_e( 'Hasta', 'ltms' ); ?></label><br>
            <input type="date" id="ltms-hub-fecha-fin" name="fecha_fin" value="<?php echo esc_attr( $filter_fecha_fin ); ?>">
        </div>

        <div>
            <button type="submit" class="button button-primary"><?php esc_html_e( 'Filtrar', 'ltms' ); ?></button>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-aveonline-hub' ) ); ?>" class="button">
                <?php esc_html_e( 'Limpiar', 'ltms' ); ?>
            </a>
        </div>
    </form>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Pedido', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'ID Envío', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Cód. Estado', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Nombre Estado', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Fecha Estado', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Resultado', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Mensaje', 'ltms' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $local_logs ) ) : ?>
                <tr>
                    <td colspan="8"><?php esc_html_e( 'No hay registros con los filtros aplicados.', 'ltms' ); ?></td>
                </tr>
            <?php else : ?>
                <?php foreach ( $local_logs as $log ) : ?>
                    <tr>
                        <td><?php echo esc_html( $log['created_at'] ?? '' ); ?></td>
                        <td>
                            <?php
                            $order_id = (int) ( $log['order_id'] ?? 0 );
                            if ( $order_id > 0 ) {
                                echo '<a href="' . esc_url( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ) . '">#' . esc_html( (string) $order_id ) . '</a>';
                            } else {
                                echo '—';
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html( $log['id_envio'] ?? '' ); ?></td>
                        <td><?php echo esc_html( $log['cod_estado'] ?? '' ); ?></td>
                        <td><?php echo esc_html( $log['nombre_estado'] ?? '' ); ?></td>
                        <td><?php echo esc_html( $log['fecha_estado'] ?? '' ); ?></td>
                        <td>
                            <?php if ( ( $log['status'] ?? '' ) === 'success' ) : ?>
                                <span style="color:#27ae60;font-weight:600;">✓ <?php esc_html_e( 'Éxito', 'ltms' ); ?></span>
                            <?php else : ?>
                                <span style="color:#c0392b;font-weight:600;">✗ <?php esc_html_e( 'Error', 'ltms' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $log['response_message'] ?? '' ); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <?php if ( $total_pages > 1 ) : ?>
        <div class="tablenav">
            <div class="tablenav-pages">
                <?php
                $base_url = admin_url( 'admin.php?page=ltms-aveonline-hub' );
                foreach ( $filters as $fkey => $fval ) {
                    $base_url = add_query_arg( $fkey, $fval, $base_url );
                }
                for ( $p = 1; $p <= $total_pages; $p++ ) {
                    $url = add_query_arg( 'paged', $p, $base_url );
                    if ( $p === $page ) {
                        echo '<span class="tablenav-pages-navspan button" style="font-weight:700;">' . esc_html( (string) $p ) . '</span> ';
                    } else {
                        echo '<a class="button" href="' . esc_url( $url ) . '">' . esc_html( (string) $p ) . '</a> ';
                    }
                }
                ?>
            </div>
        </div>
    <?php endif; ?>

    <hr style="margin:32px 0;">

    <h2 class="title"><?php esc_html_e( 'Log remoto de Ave-Hub', 'ltms' ); ?></h2>
    <p class="description">
        <?php esc_html_e( 'Consulta en vivo de los eventos que Ave-Hub confirma haber recibido para esta transportadora.', 'ltms' ); ?>
    </p>

    <form id="ltms-hub-remote-filters" style="margin:12px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
        <div>
            <label for="ltms-hub-remote-id-envio"><?php esc_html_e( 'ID Envío', 'ltms' ); ?></label><br>
            <input type="text" id="ltms-hub-remote-id-envio" name="id_envio">
        </div>
        <div>
            <label for="ltms-hub-remote-fecha-inicio"><?php esc_html_e( 'Desde', 'ltms' ); ?></label><br>
            <input type="date" id="ltms-hub-remote-fecha-inicio" name="fecha_inicio">
        </div>
        <div>
            <label for="ltms-hub-remote-fecha-fin"><?php esc_html_e( 'Hasta', 'ltms' ); ?></label><br>
            <input type="date" id="ltms-hub-remote-fecha-fin" name="fecha_fin">
        </div>
        <div>
            <label>
                <input type="checkbox" id="ltms-hub-remote-hoy" name="hoy"> <?php esc_html_e( 'Solo hoy', 'ltms' ); ?>
            </label>
        </div>
        <div>
            <button type="submit" class="button button-primary" id="ltms-hub-remote-btn">
                <?php esc_html_e( 'Consultar Ave-Hub', 'ltms' ); ?>
            </button>
        </div>
    </form>

    <div id="ltms-hub-remote-status" style="margin:8px 0;"></div>

    <table class="wp-list-table widefat fixed striped" id="ltms-hub-remote-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Creado', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'ID Envío', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Proveedor', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Cód. Estado', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Nombre Estado', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Fecha Estado', 'ltms' ); ?></th>
                <th><?php esc_html_e( 'Observaciones', 'ltms' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td colspan="7"><?php esc_html_e( 'Usa el formulario de arriba para consultar Ave-Hub.', 'ltms' ); ?></td>
            </tr>
        </tbody>
    </table>
</div>

<script>
jQuery(function ($) {
    $('#ltms-hub-remote-filters').on('submit', function (e) {
        e.preventDefault();

        var $btn    = $('#ltms-hub-remote-btn');
        var $status = $('#ltms-hub-remote-status');
        var $tbody  = $('#ltms-hub-remote-table tbody');

        $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Consultando…', 'ltms' ) ); ?>');
        $status.html('');

        $.post(ltmsAdmin.ajax_url, {
            action: 'ltms_aveonline_hub_get_logs',
            nonce: ltmsAdmin.nonce,
            id_envio: $('#ltms-hub-remote-id-envio').val(),
            fecha_inicio: $('#ltms-hub-remote-fecha-inicio').val(),
            fecha_fin: $('#ltms-hub-remote-fecha-fin').val(),
            hoy: $('#ltms-hub-remote-hoy').is(':checked') ? 1 : 0
        }, function (response) {
            $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Consultar Ave-Hub', 'ltms' ) ); ?>');

            if (!response.success) {
                var msg = (response.data && response.data.message) ? response.data.message : '<?php echo esc_js( __( 'Error desconocido.', 'ltms' ) ); ?>';
                $status.html('<div class="notice notice-error inline"><p>' + $('<div>').text(msg).html() + '</p></div>');
                return;
            }

            var rows = response.data.rows || [];
            var meta = response.data.meta || {};

            if (meta.count_events_received !== undefined) {
                $status.html('<p><strong><?php echo esc_js( __( 'Eventos recibidos por Ave-Hub:', 'ltms' ) ); ?></strong> ' + meta.count_events_received + '</p>');
            }

            if (rows.length === 0) {
                $tbody.html('<tr><td colspan="7"><?php echo esc_js( __( 'Sin resultados para estos filtros.', 'ltms' ) ); ?></td></tr>');
                return;
            }

            var html = '';
            rows.forEach(function (row) {
                var p = row.payload || {};
                html += '<tr>'
                    + '<td>' + $('<div>').text(row.created_at || '').html() + '</td>'
                    + '<td>' + $('<div>').text(row.id_envio || '').html() + '</td>'
                    + '<td>' + $('<div>').text(row.proveedor || '').html() + '</td>'
                    + '<td>' + $('<div>').text(p.cod_estado || '').html() + '</td>'
                    + '<td>' + $('<div>').text(p.nombre_estado || '').html() + '</td>'
                    + '<td>' + $('<div>').text(p.fecha_estado || '').html() + '</td>'
                    + '<td>' + $('<div>').text(p.observaciones || '').html() + '</td>'
                    + '</tr>';
            });
            $tbody.html(html);
        }).fail(function () {
            $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Consultar Ave-Hub', 'ltms' ) ); ?>');
            $status.html('<div class="notice notice-error inline"><p><?php echo esc_js( __( 'Error de red al consultar Ave-Hub.', 'ltms' ) ); ?></p></div>');
        });
    });
});
</script>
