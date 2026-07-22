<?php
/**
 * ltms-patch-kyc-docs.php
 * Agrega visualización de documentos KYC en el panel admin.
 *
 * wp --path=/home/customer/www/lo-tengo.com.co/public_html \
 *    eval-file bin/ltms-patch-kyc-docs.php --allow-root 2>/dev/null
 */

echo "=== LTMS — Patch Visualización Documentos KYC ===\n\n";

$plugin   = WP_CONTENT_DIR . '/plugins/lt-marketplace-suite/';
$kyc_view = $plugin . 'includes/admin/views/html-admin-kyc.php';
$ajax_cls = $plugin . 'includes/admin/class-ltms-admin-kyc.php';

// ── 1. Verificar que existen los archivos ─────────────────────────────────────
foreach ( [ $kyc_view, $ajax_cls ] as $f ) {
    if ( ! file_exists( $f ) ) {
        echo "❌ No encontrado: $f\n";
        exit(1);
    }
}
echo "✅ Archivos encontrados\n";

// ── 2. Reescribir html-admin-kyc.php ─────────────────────────────────────────
$kyc_html = <<<'HTML'
<?php
/**
 * Vista admin KYC — Listado + Modal de documentos
 * Generado por ltms-patch-kyc-docs.php
 */
if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;

$status_filter = sanitize_text_field( $_GET['kyc_status'] ?? 'pending' );
$allowed       = [ 'pending', 'approved', 'rejected' ];
if ( ! in_array( $status_filter, $allowed, true ) ) {
    $status_filter = 'pending';
}

$prefix = $wpdb->prefix;

// Obtener KYC con datos del usuario
$rows = $wpdb->get_results( $wpdb->prepare(
    "SELECT k.*, u.user_email, u.display_name,
            um1.meta_value AS first_name,
            um2.meta_value AS last_name,
            um3.meta_value AS store_name,
            um4.meta_value AS phone
     FROM {$prefix}lt_vendor_kyc k
     INNER JOIN {$prefix}users u ON u.ID = k.user_id
     LEFT JOIN  {$prefix}usermeta um1 ON um1.user_id = k.user_id AND um1.meta_key = 'first_name'
     LEFT JOIN  {$prefix}usermeta um2 ON um2.user_id = k.user_id AND um2.meta_key = 'last_name'
     LEFT JOIN  {$prefix}usermeta um3 ON um3.user_id = k.user_id AND um3.meta_key = 'ltms_store_name'
     LEFT JOIN  {$prefix}usermeta um4 ON um4.user_id = k.user_id AND um4.meta_key = 'ltms_phone'
     WHERE k.status = %s
     ORDER BY k.submitted_at DESC
     LIMIT 200",
    $status_filter
) );

$status_labels = [
    'pending'  => [ 'label' => 'Pendiente', 'color' => '#F7B731' ],
    'approved' => [ 'label' => 'Aprobado',  'color' => '#27AE60' ],
    'rejected' => [ 'label' => 'Rechazado', 'color' => '#E74C3C' ],
];
?>
<div class="wrap ltms-kyc-admin">
<style>
.ltms-kyc-admin h1{color:#C0392B;margin-bottom:16px}
.ltms-kyc-tabs{display:flex;gap:4px;margin-bottom:18px;border-bottom:2px solid #E74C3C}
.ltms-kyc-tabs a{padding:8px 18px;border-radius:6px 6px 0 0;text-decoration:none;font-weight:600;color:#555;background:#f5f5f5;transition:.15s}
.ltms-kyc-tabs a.active{background:#C0392B;color:#fff}
.ltms-kyc-table{width:100%;border-collapse:collapse;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.1)}
.ltms-kyc-table th{background:#1A1A1A;color:#fff;padding:10px 12px;text-align:left;font-size:12px}
.ltms-kyc-table td{padding:10px 12px;border-bottom:1px solid #f0f0f0;vertical-align:middle;font-size:13px}
.ltms-kyc-table tr:hover td{background:#FEF9F0}
.ltms-badge{display:inline-block;padding:3px 10px;border-radius:50px;font-size:11px;font-weight:700;color:#fff}
.btn-kyc{padding:5px 12px;border-radius:4px;border:none;cursor:pointer;font-size:12px;font-weight:600;margin:2px}
.btn-approve{background:#27AE60;color:#fff}.btn-reject{background:#E74C3C;color:#fff}
.btn-docs{background:#2980B9;color:#fff}
/* Modal */
#ltms-kyc-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:100000;align-items:center;justify-content:center}
#ltms-kyc-modal.open{display:flex}
.ltms-modal-box{background:#fff;border-radius:10px;width:780px;max-width:96vw;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,.25)}
.ltms-modal-header{background:#C0392B;color:#fff;padding:14px 20px;border-radius:10px 10px 0 0;display:flex;align-items:center;justify-content:space-between}
.ltms-modal-header h2{margin:0;font-size:16px}
.ltms-modal-close{cursor:pointer;font-size:20px;line-height:1;background:none;border:none;color:#fff;font-weight:700}
.ltms-modal-body{padding:20px}
.ltms-doc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;margin-top:14px}
.ltms-doc-card{border:1px solid #ddd;border-radius:8px;overflow:hidden;background:#f9f9f9}
.ltms-doc-card .doc-label{background:#1A1A1A;color:#fff;padding:6px 10px;font-size:11px;font-weight:700;text-transform:uppercase}
.ltms-doc-card .doc-preview{padding:10px;text-align:center;min-height:120px;display:flex;align-items:center;justify-content:center}
.ltms-doc-card .doc-preview img{max-width:100%;max-height:150px;border-radius:4px;cursor:pointer}
.ltms-doc-card .doc-preview a.pdf-link{display:flex;flex-direction:column;align-items:center;color:#C0392B;text-decoration:none;font-size:12px}
.ltms-doc-card .doc-preview .no-doc{color:#999;font-size:12px;font-style:italic}
.ltms-doc-card .doc-actions{padding:8px 10px;border-top:1px solid #eee;text-align:right}
.ltms-doc-card .doc-actions a{color:#2980B9;font-size:11px}
.ltms-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px}
.ltms-info-item label{font-size:11px;color:#888;display:block;margin-bottom:2px}
.ltms-info-item span{font-weight:600;font-size:13px}
.ltms-modal-footer{padding:14px 20px;border-top:1px solid #eee;display:flex;gap:8px;justify-content:flex-end}
.spinner-wrap{text-align:center;padding:40px;color:#888}
</style>

<h1>🔍 Verificación KYC</h1>

<!-- Pestañas de estado -->
<div class="ltms-kyc-tabs">
<?php foreach ( $status_labels as $s => $info ) : ?>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-kyc&kyc_status=' . $s ) ); ?>"
       class="<?php echo $s === $status_filter ? 'active' : ''; ?>">
        <?php echo esc_html( $info['label'] ); ?>
        <?php
        $cnt = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}lt_vendor_kyc WHERE status = %s", $s
        ) );
        if ( $cnt > 0 ) echo " ($cnt)";
        ?>
    </a>
<?php endforeach; ?>
</div>

<!-- Tabla de KYC -->
<table class="ltms-kyc-table">
    <thead>
        <tr>
            <th>#</th>
            <th>Vendedor</th>
            <th>Email</th>
            <th>Tipo Doc.</th>
            <th>Fecha Envío</th>
            <th>Estado</th>
            <th>Acciones</th>
        </tr>
    </thead>
    <tbody>
    <?php if ( empty( $rows ) ) : ?>
        <tr><td colspan="7" style="text-align:center;padding:30px;color:#888">
            No hay solicitudes KYC en estado "<?php echo esc_html( $status_labels[ $status_filter ]['label'] ); ?>".
        </td></tr>
    <?php else : ?>
        <?php foreach ( $rows as $i => $r ) :
            $nombre    = trim( ( $r->first_name ?? '' ) . ' ' . ( $r->last_name ?? '' ) ) ?: $r->display_name;
            $tienda    = $r->store_name ?? '';
            $doc_type  = $r->document_type ?? 'nit';
            $submitted = $r->submitted_at ? date_i18n( 'd/m/Y H:i', strtotime( $r->submitted_at ) ) : '—';
            $badge_bg  = $status_labels[ $r->status ]['color'] ?? '#999';
            $badge_lbl = $status_labels[ $r->status ]['label'] ?? $r->status;
        ?>
        <tr>
            <td><?php echo (int) $r->id; ?></td>
            <td>
                <strong><?php echo esc_html( $nombre ); ?></strong><br>
                <?php if ( $tienda ) : ?><small style="color:#888"><?php echo esc_html( $tienda ); ?></small><?php endif; ?>
            </td>
            <td><?php echo esc_html( $r->user_email ); ?></td>
            <td style="text-transform:uppercase;font-size:12px"><?php echo esc_html( $doc_type ); ?></td>
            <td><?php echo esc_html( $submitted ); ?></td>
            <td>
                <span class="ltms-badge" style="background:<?php echo esc_attr( $badge_bg ); ?>">
                    <?php echo esc_html( $badge_lbl ); ?>
                </span>
            </td>
            <td>
                <!-- Ver documentos -->
                <button class="btn-kyc btn-docs ltms-kyc-open-modal"
                        data-kyc-id="<?php echo (int) $r->id; ?>"
                        data-vendor-id="<?php echo (int) $r->user_id; ?>"
                        data-vendor-name="<?php echo esc_attr( $nombre ); ?>">
                    📄 Ver docs
                </button>
                <?php if ( $r->status === 'pending' ) : ?>
                <button class="btn-kyc btn-approve ltms-kyc-approve"
                        data-kyc-id="<?php echo (int) $r->id; ?>"
                        data-vendor-id="<?php echo (int) $r->user_id; ?>">
                    ✓ Aprobar
                </button>
                <button class="btn-kyc btn-reject ltms-kyc-reject"
                        data-kyc-id="<?php echo (int) $r->id; ?>"
                        data-vendor-id="<?php echo (int) $r->user_id; ?>">
                    ✕ Rechazar
                </button>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    </tbody>
</table>

<!-- Modal de documentos -->
<div id="ltms-kyc-modal">
    <div class="ltms-modal-box">
        <div class="ltms-modal-header">
            <h2 id="ltms-modal-title">Documentos KYC</h2>
            <button class="ltms-modal-close" id="ltms-modal-close-btn">&times;</button>
        </div>
        <div class="ltms-modal-body" id="ltms-modal-body">
            <div class="spinner-wrap">Cargando...</div>
        </div>
        <div class="ltms-modal-footer">
            <button class="button" id="ltms-modal-close-btn2">Cerrar</button>
        </div>
    </div>
</div>

<script>
(function($){
    var nonce = '<?php echo wp_create_nonce( 'ltms_kyc_nonce' ); ?>';
    var ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

    // Abrir modal con documentos
    $(document).on('click', '.ltms-kyc-open-modal', function(){
        var kycId    = $(this).data('kyc-id');
        var vendorId = $(this).data('vendor-id');
        var name     = $(this).data('vendor-name');

        $('#ltms-modal-title').text('Documentos KYC — ' + name);
        $('#ltms-modal-body').html('<div class="spinner-wrap">⏳ Cargando documentos...</div>');
        $('#ltms-kyc-modal').addClass('open');

        $.post(ajaxUrl, {
            action:    'ltms_get_kyc_details',
            nonce:     nonce,
            kyc_id:    kycId,
            vendor_id: vendorId
        }, function(r){
            if(r.success){
                $('#ltms-modal-body').html(r.data.html);
            } else {
                $('#ltms-modal-body').html('<p style="color:red">Error: ' + (r.data || 'Error desconocido') + '</p>');
            }
        }).fail(function(){
            $('#ltms-modal-body').html('<p style="color:red">Error de conexión</p>');
        });
    });

    // Cerrar modal
    $(document).on('click', '#ltms-modal-close-btn, #ltms-modal-close-btn2, #ltms-kyc-modal', function(e){
        if(e.target === this) $('#ltms-kyc-modal').removeClass('open');
    });

    // Aprobar KYC
    $(document).on('click', '.ltms-kyc-approve', function(){
        if(!confirm('¿Aprobar este KYC?')) return;
        var btn = $(this);
        btn.prop('disabled', true).text('...');
        $.post(ajaxUrl, {
            action:    'ltms_approve_kyc',
            nonce:     nonce,
            kyc_id:    btn.data('kyc-id'),
            vendor_id: btn.data('vendor-id')
        }, function(r){
            if(r.success){ location.reload(); }
            else { alert('Error: ' + (r.data || 'Error')); btn.prop('disabled',false).text('✓ Aprobar'); }
        });
    });

    // Rechazar KYC
    $(document).on('click', '.ltms-kyc-reject', function(){
        var motivo = prompt('Motivo del rechazo (obligatorio):');
        if(!motivo) return;
        var btn = $(this);
        btn.prop('disabled', true).text('...');
        $.post(ajaxUrl, {
            action:    'ltms_reject_kyc',
            nonce:     nonce,
            kyc_id:    btn.data('kyc-id'),
            vendor_id: btn.data('vendor-id'),
            notes:     motivo
        }, function(r){
            if(r.success){ location.reload(); }
            else { alert('Error: ' + (r.data || 'Error')); btn.prop('disabled',false).text('✕ Rechazar'); }
        });
    });

})(jQuery);
</script>
</div>
HTML;

file_put_contents( $kyc_view, $kyc_html );
echo "✅ html-admin-kyc.php reescrito\n";

// ── 3. Agregar el handler AJAX ltms_get_kyc_details a class-ltms-admin-kyc.php ──
$ajax_content  = file_get_contents( $ajax_cls );
$original_ajax = $ajax_content;

// Nuevo método a inyectar
$new_method = <<<'PHPMETHOD'

    /**
     * AJAX: Devuelve el HTML con los documentos del KYC para el modal admin.
     * Acción: ltms_get_kyc_details
     */
    public function ajax_get_kyc_details(): void {
        check_ajax_referer( 'ltms_kyc_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'ltms_manage_kyc' ) ) {
            wp_send_json_error( 'Sin permiso', 403 );
        }

        global $wpdb;
        $prefix    = $wpdb->prefix;
        $kyc_id    = (int) ( $_POST['kyc_id']    ?? 0 );
        $vendor_id = (int) ( $_POST['vendor_id'] ?? 0 );

        if ( ! $kyc_id || ! $vendor_id ) {
            wp_send_json_error( 'Datos incompletos' );
        }

        // Datos del KYC
        $kyc = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$prefix}lt_vendor_kyc WHERE id = %d AND user_id = %d",
            $kyc_id, $vendor_id
        ) );

        if ( ! $kyc ) {
            wp_send_json_error( 'KYC no encontrado' );
        }

        // Datos del usuario
        $user      = get_userdata( $vendor_id );
        $first     = get_user_meta( $vendor_id, 'first_name',      true );
        $last      = get_user_meta( $vendor_id, 'last_name',       true );
        $phone     = get_user_meta( $vendor_id, 'ltms_phone',      true );
        $doc_num   = get_user_meta( $vendor_id, 'ltms_doc_number', true );
        $store     = get_user_meta( $vendor_id, 'ltms_store_name', true );
        $kyc_meta  = get_user_meta( $vendor_id, 'ltms_kyc_docs',   true ); // array de URLs
        $nombre    = trim( "$first $last" ) ?: $user->display_name;

        // Documentos desde lt_kyc_documents si existe, sino desde user_meta
        $docs = [];
        $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$prefix}lt_kyc_documents'" );
        if ( $table_exists ) {
            $docs = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$prefix}lt_kyc_documents WHERE kyc_id = %d ORDER BY id ASC",
                $kyc_id
            ) );
        }

        // Fallback: buscar archivos en user_meta (ltms_kyc_doc_*)
        $meta_docs = [];
        $doc_types = [ 'cedula_front', 'cedula_back', 'rut', 'camara_comercio', 'selfie', 'contrato' ];
        foreach ( $doc_types as $dtype ) {
            $attach_id = (int) get_user_meta( $vendor_id, "ltms_kyc_{$dtype}_id", true );
            $url       = get_user_meta( $vendor_id, "ltms_kyc_{$dtype}_url", true );
            if ( ! $url && $attach_id ) {
                $url = wp_get_attachment_url( $attach_id );
            }
            if ( $url ) {
                $meta_docs[] = (object)[
                    'document_type' => $dtype,
                    'file_url'      => $url,
                    'attachment_id' => $attach_id,
                ];
            }
        }

        // También buscar en ltms_kyc_docs (array guardado por ajax_submit_kyc)
        if ( is_array( $kyc_meta ) ) {
            foreach ( $kyc_meta as $dtype => $url ) {
                if ( $url ) {
                    $meta_docs[] = (object)[
                        'document_type' => $dtype,
                        'file_url'      => $url,
                        'attachment_id' => 0,
                    ];
                }
            }
        }

        // Combinar fuentes de documentos
        $all_docs = ! empty( $docs ) ? $docs : $meta_docs;

        // Labels de tipos de documento
        $doc_labels = [
            'cedula_front'    => 'Cédula (Frente)',
            'cedula_back'     => 'Cédula (Dorso)',
            'rut'             => 'RUT',
            'camara_comercio' => 'Cámara de Comercio',
            'selfie'          => 'Selfie con Documento',
            'contrato'        => 'Contrato Firmado',
            'nit'             => 'NIT',
            'passport'        => 'Pasaporte',
            'ce'              => 'Cédula Extranjería',
        ];

        // ── Generar HTML del modal ──
        ob_start();
        ?>
        <div class="ltms-info-grid">
            <div class="ltms-info-item">
                <label>Nombre completo</label>
                <span><?php echo esc_html( $nombre ); ?></span>
            </div>
            <div class="ltms-info-item">
                <label>Email</label>
                <span><?php echo esc_html( $user->user_email ); ?></span>
            </div>
            <div class="ltms-info-item">
                <label>Tienda</label>
                <span><?php echo esc_html( $store ?: '—' ); ?></span>
            </div>
            <div class="ltms-info-item">
                <label>Teléfono</label>
                <span><?php echo esc_html( $phone ?: '—' ); ?></span>
            </div>
            <div class="ltms-info-item">
                <label>Tipo de documento</label>
                <span style="text-transform:uppercase"><?php echo esc_html( $kyc->document_type ?? '—' ); ?></span>
            </div>
            <div class="ltms-info-item">
                <label>Número de documento</label>
                <span><?php echo esc_html( $doc_num ? '****' . substr( $doc_num, -4 ) : ( $kyc->document_number ?? '—' ) ); ?></span>
            </div>
            <div class="ltms-info-item">
                <label>Fecha de envío</label>
                <span><?php echo esc_html( $kyc->submitted_at ? date_i18n( 'd/m/Y H:i', strtotime( $kyc->submitted_at ) ) : '—' ); ?></span>
            </div>
            <div class="ltms-info-item">
                <label>Estado KYC</label>
                <span><?php echo esc_html( $kyc->status ?? '—' ); ?></span>
            </div>
            <?php if ( ! empty( $kyc->notes ) ) : ?>
            <div class="ltms-info-item" style="grid-column:span 2">
                <label>Notas / Motivo de rechazo</label>
                <span style="color:#E74C3C"><?php echo esc_html( $kyc->notes ); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <hr style="margin:12px 0;border-color:#eee">
        <h3 style="margin:0 0 10px;font-size:14px">📎 Documentos adjuntos</h3>

        <?php if ( empty( $all_docs ) ) : ?>
            <div style="background:#FEF9F0;border:1px solid #F7B731;border-radius:6px;padding:14px;color:#7B5800;font-size:13px">
                ⚠️ Este vendedor no ha subido documentos todavía, o los documentos están pendientes de procesamiento.
                <br><small>ID KYC: <?php echo (int) $kyc_id; ?> | Usuario ID: <?php echo (int) $vendor_id; ?></small>
            </div>
        <?php else : ?>
            <div class="ltms-doc-grid">
            <?php foreach ( $all_docs as $doc ) :
                $dtype   = $doc->document_type ?? 'documento';
                $dlabel  = $doc_labels[ $dtype ] ?? ucfirst( str_replace( '_', ' ', $dtype ) );
                $file_url = $doc->file_url ?? '';
                $attach_id = (int) ( $doc->attachment_id ?? 0 );

                // Si no hay URL directa, construirla desde attachment_id
                if ( ! $file_url && $attach_id ) {
                    $file_url = wp_get_attachment_url( $attach_id );
                }

                // Detectar si es imagen o PDF
                $ext = strtolower( pathinfo( parse_url( $file_url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
                $is_image = in_array( $ext, [ 'jpg', 'jpeg', 'png', 'gif', 'webp' ], true );
                $is_pdf   = $ext === 'pdf';
            ?>
                <div class="ltms-doc-card">
                    <div class="doc-label"><?php echo esc_html( $dlabel ); ?></div>
                    <div class="doc-preview">
                        <?php if ( ! $file_url ) : ?>
                            <span class="no-doc">No subido</span>
                        <?php elseif ( $is_image ) : ?>
                            <a href="<?php echo esc_url( $file_url ); ?>" target="_blank">
                                <img src="<?php echo esc_url( $file_url ); ?>"
                                     alt="<?php echo esc_attr( $dlabel ); ?>"
                                     title="Click para ver en tamaño completo">
                            </a>
                        <?php elseif ( $is_pdf ) : ?>
                            <a href="<?php echo esc_url( $file_url ); ?>" target="_blank" class="pdf-link">
                                <span style="font-size:40px">📄</span>
                                <span>Ver PDF</span>
                            </a>
                        <?php else : ?>
                            <a href="<?php echo esc_url( $file_url ); ?>" target="_blank" class="pdf-link">
                                <span style="font-size:40px">📎</span>
                                <span>Ver archivo</span>
                            </a>
                        <?php endif; ?>
                    </div>
                    <?php if ( $file_url ) : ?>
                    <div class="doc-actions">
                        <a href="<?php echo esc_url( $file_url ); ?>" target="_blank">
                            ↗ Abrir en nueva pestaña
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php
        $html = ob_get_clean();
        wp_send_json_success( [ 'html' => $html ] );
    }
PHPMETHOD;

// Verificar si el método ya existe
if ( strpos( $ajax_content, 'ajax_get_kyc_details' ) !== false ) {
    echo "ℹ️  ajax_get_kyc_details ya existe en la clase\n";
} else {
    // Insertar antes del último cierre de clase
    $last_brace = strrpos( $ajax_content, '}' );
    $ajax_content = substr( $ajax_content, 0, $last_brace ) . $new_method . "\n}" ;
    file_put_contents( $ajax_cls, $ajax_content );
    echo "✅ Método ajax_get_kyc_details agregado\n";
}

// ── 4. Registrar el hook AJAX si no existe ────────────────────────────────────
// Buscar dónde se registran los otros hooks AJAX en el archivo
if ( strpos( $ajax_content, 'ltms_get_kyc_details' ) === false ) {
    // Agregar el registro del hook en el método init() o en el constructor
    $hook_line = "\n        add_action( 'wp_ajax_ltms_get_kyc_details', [ \$this, 'ajax_get_kyc_details' ] );";

    // Buscar add_action wp_ajax en el archivo para insertar junto a los demás
    $insert_after = "add_action( 'wp_ajax_ltms_approve_kyc'";
    $pos = strpos( $ajax_content, $insert_after );
    if ( $pos !== false ) {
        $end_of_line = strpos( $ajax_content, "\n", $pos );
        $ajax_content = substr( $ajax_content, 0, $end_of_line ) . $hook_line . substr( $ajax_content, $end_of_line );
        file_put_contents( $ajax_cls, $ajax_content );
        echo "✅ Hook wp_ajax_ltms_get_kyc_details registrado\n";
    } else {
        echo "⚠️  No se encontró punto de inserción para el hook — agregar manualmente:\n";
        echo "    add_action('wp_ajax_ltms_get_kyc_details', [\$this,'ajax_get_kyc_details']);\n";
    }
}

// ── 5. OPcache ────────────────────────────────────────────────────────────────
foreach ( [ $kyc_view, $ajax_cls ] as $f ) {
    if ( function_exists( 'opcache_invalidate' ) ) {
        opcache_invalidate( $f, true );
    }
}
echo "✅ OPcache invalidado\n";

// ── 6. Git commit + push ──────────────────────────────────────────────────────
chdir( $plugin );
system( 'git add includes/admin/views/html-admin-kyc.php includes/admin/class-ltms-admin-kyc.php' );
system( 'git -c user.email=dircomercialcol@lo-tengo.com.co -c user.name="LTMS Bot" commit -m "feat(kyc-admin): modal visualización documentos + ajax_get_kyc_details con 3 fuentes de docs"' );
system( 'git remote set-url origin https://jglotengo:GITHUB_TOKEN_REMOVED@github.com/jglotengo/lt-marketplace-suite.git' );
system( 'git push origin main && echo "PUSH_OK"' );

echo "\n✅ Patch completo.\n";
echo "Recarga: https://lo-tengo.com.co/wp-admin/admin.php?page=ltms-kyc\n";
echo "Haz clic en '📄 Ver docs' para ver los documentos del vendedor.\n";
