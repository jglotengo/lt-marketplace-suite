<?php
/**
 * Vista: Admin Marketing - Gestión de Banners y MLM
 *
 * @package LTMS
 * @version 2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$banners_table = $wpdb->prefix . 'lt_marketing_banners';
$mlm_enabled   = LTMS_Core_Config::get( 'ltms_mlm_enabled', 'no' ) === 'yes';

$type_filter = sanitize_key( $_GET['type'] ?? '' );    // phpcs:ignore
$page_num    = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore
$per_page    = 20;
$offset      = ( $page_num - 1 ) * $per_page;
$base_url    = admin_url( 'admin.php?page=ltms-marketing' );
$nonce       = wp_create_nonce( 'ltms_admin_nonce' );

$valid_types = [ 'banner', 'flyer', 'social_post', 'email_template', 'video' ];
$type_labels = [
    'banner'         => '🖼 Banner',
    'flyer'          => '📄 Flyer',
    'social_post'    => '📱 Social',
    'email_template' => '✉ Email',
    'video'          => '🎬 Video',
];

$type_dims = [
    'banner'         => '1200×400 px — máx 500 KB',
    'flyer'          => '1080×1080 px — máx 2 MB',
    'social_post'    => '1080×1080 px — máx 1 MB',
    'email_template' => '600×200 px — máx 200 KB',
    'video'          => '1080×1920 px — máx 50 MB',
];

// phpcs:disable WordPress.DB.DirectDatabaseQuery
$where      = $type_filter && in_array( $type_filter, $valid_types, true )
              ? $wpdb->prepare( 'WHERE type = %s', $type_filter )
              : '';
$total      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$banners_table}` {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$total_pages = max( 1, (int) ceil( $total / $per_page ) );

$banners = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $wpdb->prepare(
        "SELECT * FROM `{$banners_table}` {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $per_page, $offset
    ),
    ARRAY_A
);

$type_counts_raw = $wpdb->get_results( "SELECT type, COUNT(*) as total FROM `{$banners_table}` GROUP BY type", ARRAY_A );
$type_counts = array_column( $type_counts_raw, 'total', 'type' );
$total_downloads = (int) $wpdb->get_var( "SELECT SUM(download_count) FROM `{$banners_table}`" );

$total_nodes = 0; $avg_depth = 0;
if ( $mlm_enabled ) {
    $ref_table   = $wpdb->prefix . 'lt_referral_network';
    $total_nodes = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$ref_table}`" );
    $avg_depth   = (int) $wpdb->get_var( "SELECT AVG(level) FROM `{$ref_table}`" );
}
// phpcs:enable
?>
<div class="wrap ltms-admin-wrap">

    <div class="ltms-header">
        <h1><?php esc_html_e( 'Marketing', 'ltms' ); ?></h1>
    </div>

    <!-- ── Red de Referidos MLM ── -->
    <div class="ltms-form-section" style="margin-bottom:24px;">
        <h2 style="margin-top:0;"><?php esc_html_e( 'Red de Referidos (MLM)', 'ltms' ); ?>
            <span class="ltms-badge <?php echo $mlm_enabled ? 'ltms-badge-success' : 'ltms-badge-pending'; ?>" style="font-size:0.8rem;margin-left:8px;">
                <?php echo $mlm_enabled ? esc_html__( 'ACTIVO', 'ltms' ) : esc_html__( 'INACTIVO', 'ltms' ); ?>
            </span>
        </h2>
        <?php if ( $mlm_enabled ) : ?>
        <div class="ltms-stats-grid">
            <div class="ltms-stat-card">
                <span class="ltms-stat-label"><?php esc_html_e( 'Nodos en la Red', 'ltms' ); ?></span>
                <span class="ltms-stat-value"><?php echo esc_html( number_format( $total_nodes ) ); ?></span>
            </div>
            <div class="ltms-stat-card">
                <span class="ltms-stat-label"><?php esc_html_e( 'Profundidad Promedio', 'ltms' ); ?></span>
                <span class="ltms-stat-value"><?php echo esc_html( $avg_depth ); ?> <?php esc_html_e( 'niveles', 'ltms' ); ?></span>
            </div>
        </div>
        <?php else : ?>
        <p style="color:#888;margin:0;">
            <?php esc_html_e( 'La red de referidos está desactivada.', 'ltms' ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-settings&tab=mlm' ) ); ?>" class="ltms-btn ltms-btn-outline ltms-btn-sm" style="margin-left:8px;">
                <?php esc_html_e( 'Activar MLM', 'ltms' ); ?>
            </a>
        </p>
        <?php endif; ?>
    </div>

    <!-- ── Banners Promocionales ── -->
    <div class="ltms-form-section">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:16px;">
            <h2 style="margin:0;"><?php esc_html_e( 'Banners Promocionales', 'ltms' ); ?></h2>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <span style="font-size:12px;color:#888;">
                    <?php printf( esc_html__( '%d materiales · %d descargas totales', 'ltms' ), $total, $total_downloads ); ?>
                </span>
                <button type="button" id="ltms-open-upload-modal" class="ltms-btn ltms-btn-primary ltms-btn-sm">
                    + <?php esc_html_e( 'Subir material', 'ltms' ); ?>
                </button>
            </div>
        </div>

        <!-- Filtros por tipo -->
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;">
            <a href="<?php echo esc_url( $base_url ); ?>"
               class="ltms-btn ltms-btn-sm <?php echo ! $type_filter ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?>">
                <?php esc_html_e( 'Todos', 'ltms' ); ?>
                <span style="margin-left:4px;opacity:.7;">(<?php echo esc_html( $total ); ?>)</span>
            </a>
            <?php foreach ( $type_labels as $t => $label ) : ?>
            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'ltms-marketing', 'type' => $t ], admin_url( 'admin.php' ) ) ); ?>"
               class="ltms-btn ltms-btn-sm <?php echo $type_filter === $t ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?>">
                <?php echo esc_html( $label ); ?>
                <span style="margin-left:4px;opacity:.7;">(<?php echo esc_html( $type_counts[ $t ] ?? 0 ); ?>)</span>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if ( empty( $banners ) ) : ?>
        <div style="text-align:center;padding:48px;color:#9ca3af;">
            <div style="font-size:48px;margin-bottom:12px;">🖼</div>
            <p style="margin:0 0 12px;"><?php esc_html_e( 'No hay banners configurados.', 'ltms' ); ?></p>
            <button type="button" id="ltms-open-upload-modal-empty" class="ltms-btn ltms-btn-primary">
                + <?php esc_html_e( 'Subir primer material', 'ltms' ); ?>
            </button>
        </div>
        <?php else : ?>

        <!-- Grid de banners -->
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;margin-bottom:20px;">
            <?php foreach ( $banners as $banner ) :
                $thumb = $banner['thumbnail_url'] ?: $banner['file_url'];
                $is_img = preg_match( '/\.(jpg|jpeg|png|gif|webp|svg)(\?.*)?$/i', $banner['file_url'] );
            ?>
            <div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06);">
                <?php if ( $thumb && $is_img ) : ?>
                <div style="height:130px;overflow:hidden;background:#f3f4f6;">
                    <img src="<?php echo esc_url( $thumb ); ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
                </div>
                <?php else : ?>
                <div style="height:130px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;font-size:40px;">📁</div>
                <?php endif; ?>

                <div style="padding:10px 12px;">
                    <div style="font-weight:600;font-size:0.85rem;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?php echo esc_html( $banner['title'] ); ?>
                    </div>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px;">
                        <span style="font-size:11px;color:#6b7280;">
                            <?php echo esc_html( $type_labels[ $banner['type'] ] ?? $banner['type'] ); ?>
                            <?php if ( $banner['dimensions'] ) : ?>
                             · <?php echo esc_html( $banner['dimensions'] ); ?>
                            <?php endif; ?>
                        </span>
                        <span style="font-size:11px;color:#6b7280;">⬇ <?php echo esc_html( number_format( (int) $banner['download_count'] ) ); ?></span>
                    </div>
                    <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap;">
                        <a href="<?php echo esc_url( $banner['file_url'] ); ?>" target="_blank"
                           class="ltms-btn ltms-btn-outline ltms-btn-sm" style="flex:1;text-align:center;">
                            ⬇ <?php esc_html_e( 'Descargar', 'ltms' ); ?>
                        </a>
                        <button type="button"
                                class="ltms-btn ltms-btn-sm <?php echo (int) $banner['is_active'] ? 'ltms-btn-success' : 'ltms-btn-outline'; ?>"
                                onclick="ltmsToggleBanner(<?php echo esc_js( $banner['id'] ); ?>, this)">
                            <?php echo (int) $banner['is_active'] ? esc_html__( 'Activo', 'ltms' ) : esc_html__( 'Inactivo', 'ltms' ); ?>
                        </button>
                        <button type="button"
                                class="ltms-btn ltms-btn-danger ltms-btn-sm"
                                onclick="ltmsDeleteBanner(<?php echo esc_js( $banner['id'] ); ?>, this)">
                            🗑
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ( $total_pages > 1 ) : ?>
        <div style="display:flex;justify-content:center;align-items:center;gap:6px;flex-wrap:wrap;">
            <?php if ( $page_num > 1 ) : ?>
            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'ltms-marketing', 'type' => $type_filter, 'paged' => 1 ], admin_url( 'admin.php' ) ) ); ?>"
               class="ltms-btn ltms-btn-outline ltms-btn-sm">« <?php esc_html_e( 'Primera', 'ltms' ); ?></a>
            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'ltms-marketing', 'type' => $type_filter, 'paged' => $page_num - 1 ], admin_url( 'admin.php' ) ) ); ?>"
               class="ltms-btn ltms-btn-outline ltms-btn-sm">‹ <?php esc_html_e( 'Anterior', 'ltms' ); ?></a>
            <?php endif; ?>
            <?php for ( $p = 1; $p <= $total_pages; $p++ ) : ?>
                <?php if ( abs( $p - $page_num ) <= 2 || $p === 1 || $p === $total_pages ) : ?>
                <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'ltms-marketing', 'type' => $type_filter, 'paged' => $p ], admin_url( 'admin.php' ) ) ); ?>"
                   class="ltms-btn ltms-btn-sm <?php echo $p === $page_num ? 'ltms-btn-primary' : 'ltms-btn-outline'; ?>"
                   style="min-width:32px;text-align:center;"><?php echo esc_html( $p ); ?></a>
                <?php elseif ( abs( $p - $page_num ) === 3 ) : ?>
                <span style="padding:6px 2px;color:#888;">…</span>
                <?php endif; ?>
            <?php endfor; ?>
            <?php if ( $page_num < $total_pages ) : ?>
            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'ltms-marketing', 'type' => $type_filter, 'paged' => $page_num + 1 ], admin_url( 'admin.php' ) ) ); ?>"
               class="ltms-btn ltms-btn-outline ltms-btn-sm"><?php esc_html_e( 'Siguiente', 'ltms' ); ?> ›</a>
            <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'ltms-marketing', 'type' => $type_filter, 'paged' => $total_pages ], admin_url( 'admin.php' ) ) ); ?>"
               class="ltms-btn ltms-btn-outline ltms-btn-sm"><?php esc_html_e( 'Última', 'ltms' ); ?> »</a>
            <?php endif; ?>
            <span style="font-size:12px;color:#666;margin-left:8px;">
                <?php printf(
                    esc_html__( 'Mostrando %1$d–%2$d de %3$d', 'ltms' ),
                    ( ( $page_num - 1 ) * $per_page ) + 1,
                    min( $page_num * $per_page, $total ),
                    $total
                ); ?>
            </span>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div><!-- .ltms-form-section -->

</div><!-- .wrap -->

<!-- ══════════════════════════════════════════════════
     MODAL: Subir material a Backblaze B2
═══════════════════════════════════════════════════ -->
<div id="ltms-upload-modal" style="display:none;position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.55);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:10px;width:520px;max-width:95vw;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);">

        <!-- Header -->
        <div style="padding:20px 24px 16px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;">
            <h2 style="margin:0;font-size:1.1rem;">📤 <?php esc_html_e( 'Subir material promocional', 'ltms' ); ?></h2>
            <button type="button" id="ltms-close-modal" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#6b7280;line-height:1;">×</button>
        </div>

        <!-- Form -->
        <div style="padding:24px;">
            <!-- Título -->
            <div style="margin-bottom:16px;">
                <label style="display:block;font-weight:600;margin-bottom:6px;font-size:0.875rem;">
                    <?php esc_html_e( 'Título *', 'ltms' ); ?>
                </label>
                <input type="text" id="ltms-banner-title" placeholder="<?php esc_attr_e( 'Ej: Banner Verano 2026', 'ltms' ); ?>"
                       style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:0.9rem;box-sizing:border-box;">
            </div>

            <!-- Tipo -->
            <div style="margin-bottom:16px;">
                <label style="display:block;font-weight:600;margin-bottom:6px;font-size:0.875rem;">
                    <?php esc_html_e( 'Tipo de material *', 'ltms' ); ?>
                </label>
                <select id="ltms-banner-type" style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:0.9rem;">
                    <?php foreach ( $type_labels as $t => $label ) : ?>
                    <option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <!-- Hint de dimensiones -->
                <p id="ltms-dims-hint" style="margin:6px 0 0;font-size:11px;color:#6b7280;">
                    <?php echo esc_html( $type_dims['banner'] ); ?>
                </p>
            </div>

            <!-- Dimensiones (opcional) -->
            <div style="margin-bottom:16px;">
                <label style="display:block;font-weight:600;margin-bottom:6px;font-size:0.875rem;">
                    <?php esc_html_e( 'Dimensiones reales (opcional)', 'ltms' ); ?>
                </label>
                <input type="text" id="ltms-banner-dims" placeholder="<?php esc_attr_e( 'Ej: 1200×400', 'ltms' ); ?>"
                       style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:0.9rem;box-sizing:border-box;">
            </div>

            <!-- Categoría (opcional) -->
            <div style="margin-bottom:16px;">
                <label style="display:block;font-weight:600;margin-bottom:6px;font-size:0.875rem;">
                    <?php esc_html_e( 'Categoría (opcional)', 'ltms' ); ?>
                </label>
                <input type="text" id="ltms-banner-cat" placeholder="<?php esc_attr_e( 'Ej: temporada, promo, marca', 'ltms' ); ?>"
                       style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:0.9rem;box-sizing:border-box;">
            </div>

            <!-- Archivo -->
            <div style="margin-bottom:20px;">
                <label style="display:block;font-weight:600;margin-bottom:6px;font-size:0.875rem;">
                    <?php esc_html_e( 'Archivo *', 'ltms' ); ?>
                </label>
                <div id="ltms-dropzone"
                     style="border:2px dashed #d1d5db;border-radius:8px;padding:32px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;"
                     ondragover="event.preventDefault();this.style.borderColor='#6366f1';this.style.background='#f5f3ff';"
                     ondragleave="this.style.borderColor='#d1d5db';this.style.background='';"
                     ondrop="ltmsHandleDrop(event)"
                     onclick="document.getElementById('ltms-file-input').click()">
                    <div style="font-size:2rem;margin-bottom:8px;">📁</div>
                    <p style="margin:0;color:#6b7280;font-size:0.875rem;">
                        <?php esc_html_e( 'Arrastra el archivo aquí o haz clic para seleccionar', 'ltms' ); ?>
                    </p>
                    <p style="margin:4px 0 0;font-size:11px;color:#9ca3af;">
                        <?php esc_html_e( 'JPG, PNG, GIF, WEBP, SVG, PDF, MP4 — máx 50 MB', 'ltms' ); ?>
                    </p>
                </div>
                <input type="file" id="ltms-file-input" style="display:none;"
                       accept=".jpg,.jpeg,.png,.gif,.webp,.svg,.pdf,.mp4,.mov">
                <div id="ltms-file-name" style="margin-top:8px;font-size:12px;color:#374151;display:none;"></div>
            </div>

            <!-- Barra de progreso -->
            <div id="ltms-upload-progress" style="display:none;margin-bottom:16px;">
                <div style="background:#e5e7eb;border-radius:4px;height:8px;overflow:hidden;">
                    <div id="ltms-progress-bar" style="height:100%;background:#6366f1;width:0%;transition:width .3s;"></div>
                </div>
                <p id="ltms-progress-msg" style="margin:6px 0 0;font-size:12px;color:#6b7280;"></p>
            </div>

            <!-- Mensaje de error/éxito -->
            <div id="ltms-upload-msg" style="display:none;padding:10px 14px;border-radius:6px;font-size:0.875rem;margin-bottom:16px;"></div>

            <!-- Botones -->
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" id="ltms-cancel-upload" class="ltms-btn ltms-btn-outline">
                    <?php esc_html_e( 'Cancelar', 'ltms' ); ?>
                </button>
                <button type="button" id="ltms-submit-upload" class="ltms-btn ltms-btn-primary">
                    📤 <?php esc_html_e( 'Subir a Backblaze', 'ltms' ); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
    var nonce = '<?php echo esc_js( $nonce ); ?>';

    var typeDims = <?php echo wp_json_encode( $type_dims ); ?>;

    // Abrir modal
    function openModal() {
        document.getElementById('ltms-upload-modal').style.display = 'flex';
        document.getElementById('ltms-banner-title').focus();
    }
    function closeModal() {
        document.getElementById('ltms-upload-modal').style.display = 'none';
        resetForm();
    }

    document.getElementById('ltms-open-upload-modal').addEventListener('click', openModal);
    var emptyBtn = document.getElementById('ltms-open-upload-modal-empty');
    if (emptyBtn) emptyBtn.addEventListener('click', openModal);
    document.getElementById('ltms-close-modal').addEventListener('click', closeModal);
    document.getElementById('ltms-cancel-upload').addEventListener('click', closeModal);
    document.getElementById('ltms-upload-modal').addEventListener('click', function(e){
        if (e.target === this) closeModal();
    });

    // Cambio de tipo → actualizar hint dimensiones
    document.getElementById('ltms-banner-type').addEventListener('change', function(){
        document.getElementById('ltms-dims-hint').textContent = typeDims[this.value] || '';
    });

    // Selección de archivo
    var selectedFile = null;
    document.getElementById('ltms-file-input').addEventListener('change', function(){
        if (this.files[0]) setFile(this.files[0]);
    });

    function setFile(f) {
        selectedFile = f;
        var nameEl = document.getElementById('ltms-file-name');
        nameEl.textContent = '📎 ' + f.name + ' (' + (f.size / 1024 / 1024).toFixed(2) + ' MB)';
        nameEl.style.display = 'block';
        var dz = document.getElementById('ltms-dropzone');
        dz.style.borderColor = '#6366f1';
        dz.style.background = '#f5f3ff';
    }

    window.ltmsHandleDrop = function(e) {
        e.preventDefault();
        var dz = document.getElementById('ltms-dropzone');
        dz.style.borderColor = '#d1d5db';
        dz.style.background = '';
        if (e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]);
    };

    // Reset
    function resetForm() {
        document.getElementById('ltms-banner-title').value = '';
        document.getElementById('ltms-banner-type').value = 'banner';
        document.getElementById('ltms-banner-dims').value = '';
        document.getElementById('ltms-banner-cat').value = '';
        document.getElementById('ltms-file-input').value = '';
        document.getElementById('ltms-file-name').style.display = 'none';
        document.getElementById('ltms-file-name').textContent = '';
        document.getElementById('ltms-upload-progress').style.display = 'none';
        document.getElementById('ltms-progress-bar').style.width = '0%';
        document.getElementById('ltms-upload-msg').style.display = 'none';
        var dz = document.getElementById('ltms-dropzone');
        dz.style.borderColor = '#d1d5db';
        dz.style.background = '';
        selectedFile = null;
    }

    function showMsg(msg, isError) {
        var el = document.getElementById('ltms-upload-msg');
        el.textContent = msg;
        el.style.display = 'block';
        el.style.background = isError ? '#fef2f2' : '#f0fdf4';
        el.style.color     = isError ? '#dc2626' : '#16a34a';
        el.style.border    = isError ? '1px solid #fca5a5' : '1px solid #86efac';
    }

    // Subir archivo
    document.getElementById('ltms-submit-upload').addEventListener('click', function(){
        var title = document.getElementById('ltms-banner-title').value.trim();
        if (!title) { showMsg('El título es obligatorio.', true); return; }
        if (!selectedFile) { showMsg('Selecciona un archivo.', true); return; }

        var btn = this;
        btn.disabled = true;
        btn.textContent = '⏳ Subiendo...';

        var formData = new FormData();
        formData.append('action', 'ltms_upload_banner');
        formData.append('nonce', nonce);
        formData.append('title', title);
        formData.append('type', document.getElementById('ltms-banner-type').value);
        formData.append('dimensions', document.getElementById('ltms-banner-dims').value.trim());
        formData.append('category', document.getElementById('ltms-banner-cat').value.trim());
        formData.append('banner_file', selectedFile);

        document.getElementById('ltms-upload-progress').style.display = 'block';
        document.getElementById('ltms-progress-msg').textContent = 'Subiendo a Backblaze B2...';

        var xhr = new XMLHttpRequest();
        xhr.open('POST', ajaxurl);

        xhr.upload.addEventListener('progress', function(e){
            if (e.lengthComputable) {
                var pct = Math.round(e.loaded / e.total * 100);
                document.getElementById('ltms-progress-bar').style.width = pct + '%';
                document.getElementById('ltms-progress-msg').textContent = 'Subiendo... ' + pct + '%';
            }
        });

        xhr.addEventListener('load', function(){
            btn.disabled = false;
            btn.textContent = '📤 Subir a Backblaze';
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success) {
                    document.getElementById('ltms-progress-bar').style.width = '100%';
                    document.getElementById('ltms-progress-msg').textContent = '✅ Completado';
                    showMsg('✅ ' + (res.data.message || 'Material subido exitosamente.'), false);
                    setTimeout(function(){ location.reload(); }, 1400);
                } else {
                    showMsg('❌ ' + (res.data || 'Error al subir.'), true);
                }
            } catch(err) {
                showMsg('❌ Respuesta inesperada del servidor.', true);
            }
        });

        xhr.addEventListener('error', function(){
            btn.disabled = false;
            btn.textContent = '📤 Subir a Backblaze';
            showMsg('❌ Error de red al subir.', true);
        });

        xhr.send(formData);
    });

    // Toggle activo/inactivo
    window.ltmsToggleBanner = function(id, btn) {
        btn.disabled = true;
        var formData = new FormData();
        formData.append('action', 'ltms_toggle_banner');
        formData.append('nonce', nonce);
        formData.append('banner_id', id);
        fetch(ajaxurl, { method: 'POST', body: formData })
            .then(function(r){ return r.json(); })
            .then(function(res){
                btn.disabled = false;
                if (res.success) location.reload();
                else alert(res.data || 'Error');
            });
    };

    // Eliminar banner
    window.ltmsDeleteBanner = function(id, btn) {
        if (!confirm('¿Eliminar este material? Se borrará de Backblaze B2 también.')) return;
        btn.disabled = true;
        var formData = new FormData();
        formData.append('action', 'ltms_delete_banner');
        formData.append('nonce', nonce);
        formData.append('banner_id', id);
        fetch(ajaxurl, { method: 'POST', body: formData })
            .then(function(r){ return r.json(); })
            .then(function(res){
                btn.disabled = false;
                if (res.success) location.reload();
                else alert(res.data || 'Error al eliminar');
            });
    };

})();
</script>
