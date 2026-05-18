<?php
/**
 * Vista: Admin KYC — Verificación de Identidad de Vendedores
 * Modal de documentos, contadores por pestaña, vault log (Ley 1581/2012).
 *
 * @package LTMS
 * @version 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

global $wpdb;
$table  = $wpdb->prefix . 'lt_vendor_kyc';
$status = sanitize_key( $_GET['status'] ?? 'pending' ); // phpcs:ignore

// Contadores
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$counts   = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM `{$table}` GROUP BY status", ARRAY_A );
$count_map = [ 'pending' => 0, 'approved' => 0, 'rejected' => 0 ];
foreach ( $counts as $row ) { $count_map[ $row['status'] ] = (int) $row['total']; }

// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$kyc_records = $wpdb->get_results(
    $wpdb->prepare( "SELECT k.*, u.display_name, u.user_email FROM `{$table}` k LEFT JOIN `{$wpdb->users}` u ON u.ID = k.vendor_id WHERE k.status = %s ORDER BY k.submitted_at DESC LIMIT 50", $status ),
    ARRAY_A
);
?>
<div class="wrap ltms-admin-wrap">
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
    <h1 style="margin:0;">🪪 <?php esc_html_e( 'Verificación KYC de Vendedores', 'ltms' ); ?></h1>
    <span style="font-size:12px;color:#666;">Vault Log activo · Ley 1581/2012</span>
</div>

<!-- Pestañas con contadores -->
<div style="display:flex;gap:6px;margin-bottom:20px;">
<?php
$tabs = [
    'pending'  => [ 'label' => 'Pendientes',  'color' => '#f59e0b' ],
    'approved' => [ 'label' => 'Aprobados',   'color' => '#10b981' ],
    'rejected' => [ 'label' => 'Rechazados',  'color' => '#ef4444' ],
];
foreach ( $tabs as $s => $tab ) :
    $active = $status === $s;
?>
<a href="<?php echo esc_url( admin_url( 'admin.php?page=ltms-kyc&status=' . $s ) ); ?>"
   style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:6px;font-size:13px;font-weight:500;text-decoration:none;
          background:<?php echo $active ? $tab['color'] : '#f3f4f6'; ?>;
          color:<?php echo $active ? '#fff' : '#374151'; ?>;
          border:2px solid <?php echo $active ? $tab['color'] : '#e5e7eb'; ?>;">
    <?php echo esc_html( $tab['label'] ); ?>
    <span style="background:rgba(0,0,0,.15);border-radius:12px;padding:1px 8px;font-size:11px;font-weight:700;">
        <?php echo esc_html( $count_map[$s] ); ?>
    </span>
</a>
<?php endforeach; ?>
</div>

<!-- Tabla -->
<div style="background:#fff;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.08);overflow:hidden;">
<?php if ( empty( $kyc_records ) ) : ?>
<div style="text-align:center;padding:48px;color:#9ca3af;">
    <div style="font-size:48px;margin-bottom:12px;">📭</div>
    <p style="margin:0;"><?php esc_html_e( 'No hay registros en este estado.', 'ltms' ); ?></p>
</div>
<?php else : ?>
<table class="widefat" style="border:none;">
    <thead>
        <tr style="background:#f8fafc;border-bottom:2px solid #e5e7eb;">
            <th style="padding:12px 14px;font-size:12px;color:#6b7280;text-transform:uppercase;">Vendedor</th>
            <th style="padding:12px 14px;font-size:12px;color:#6b7280;text-transform:uppercase;">Email</th>
            <th style="padding:12px 14px;font-size:12px;color:#6b7280;text-transform:uppercase;">Doc.</th>
            <th style="padding:12px 14px;font-size:12px;color:#6b7280;text-transform:uppercase;">Enviado</th>
            <th style="padding:12px 14px;font-size:12px;color:#6b7280;text-transform:uppercase;">Estado</th>
            <th style="padding:12px 14px;text-align:right;font-size:12px;color:#6b7280;text-transform:uppercase;">Acciones</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ( $kyc_records as $i => $kyc ) :
        $vid = (int) $kyc['vendor_id'];
        $doc_type = strtoupper( get_user_meta( $vid, 'ltms_document_type', true ) ?: 'CC' );
        $sc = match($kyc['status']) { 'approved' => '#10b981', 'rejected' => '#ef4444', default => '#f59e0b' };
        $sl = match($kyc['status']) { 'approved' => 'Aprobado', 'rejected' => 'Rechazado', default => 'Pendiente' };
    ?>
    <tr style="background:<?php echo $i%2===0?'#fff':'#fafafa'; ?>;border-bottom:1px solid #f3f4f6;">
        <td style="padding:12px 14px;">
            <div style="font-weight:600;color:#111827;"><?php echo esc_html($kyc['display_name']?:'—'); ?></div>
            <div style="font-size:11px;color:#9ca3af;">ID <?php echo esc_html($vid); ?> · KYC #<?php echo esc_html($kyc['id']); ?></div>
        </td>
        <td style="padding:12px 14px;font-size:13px;"><?php echo esc_html($kyc['user_email']?:'—'); ?></td>
        <td style="padding:12px 14px;">
            <span style="background:#eff6ff;color:#1d4ed8;border-radius:4px;padding:2px 8px;font-size:12px;font-weight:600;"><?php echo esc_html($doc_type); ?></span>
        </td>
        <td style="padding:12px 14px;font-size:12px;color:#6b7280;">
            <?php echo esc_html($kyc['submitted_at'] ? wp_date('d/m/Y H:i', strtotime($kyc['submitted_at'])) : '—'); ?>
        </td>
        <td style="padding:12px 14px;">
            <span style="background:<?php echo esc_attr($sc); ?>22;color:<?php echo esc_attr($sc); ?>;border-radius:12px;padding:2px 10px;font-size:12px;font-weight:600;">
                <?php echo esc_html($sl); ?>
            </span>
        </td>
        <td style="padding:12px 14px;text-align:right;white-space:nowrap;">
            <button type="button" class="button ltms-kyc-view-docs" data-kyc-id="<?php echo esc_attr($kyc['id']); ?>" style="margin-right:4px;">
                🔍 Ver docs
            </button>
            <?php if ( $kyc['status'] === 'pending' ) : ?>
            <button type="button" class="button button-primary ltms-approve-kyc" data-kyc-id="<?php echo esc_attr($kyc['id']); ?>" style="background:#10b981;border-color:#059669;margin-right:4px;">
                ✓ Aprobar
            </button>
            <button type="button" class="button ltms-reject-kyc" data-kyc-id="<?php echo esc_attr($kyc['id']); ?>" style="color:#ef4444;border-color:#ef4444;">
                ✗ Rechazar
            </button>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>
</div>
</div>

<!-- MODAL -->
<div id="ltms-kyc-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center;">
<div style="background:#fff;border-radius:12px;width:90%;max-width:860px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);">
    <div style="padding:18px 24px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;background:#f8fafc;border-radius:12px 12px 0 0;">
        <h2 id="ltms-modal-title" style="margin:0;font-size:16px;color:#111827;">🪪 Detalle KYC</h2>
        <button type="button" id="ltms-kyc-modal-close" style="background:none;border:none;font-size:22px;cursor:pointer;color:#6b7280;">&times;</button>
    </div>
    <div id="ltms-modal-loading" style="text-align:center;padding:48px;">
        <div style="font-size:32px;margin-bottom:12px;">⏳</div>
        <p style="color:#6b7280;">Cargando documentos…</p>
    </div>
    <div id="ltms-modal-body" style="display:none;padding:24px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;background:#f8fafc;border-radius:8px;padding:16px;">
            <div><span style="font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Vendedor</span><br><strong id="mdl-name"></strong></div>
            <div><span style="font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Email</span><br><strong id="mdl-email"></strong></div>
            <div><span style="font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Tienda</span><br><strong id="mdl-store"></strong></div>
            <div><span style="font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Ciudad</span><br><strong id="mdl-city"></strong></div>
            <div><span style="font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Teléfono</span><br><strong id="mdl-phone"></strong></div>
            <div><span style="font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Documento (enmascarado)</span><br><strong id="mdl-doc"></strong></div>
            <div><span style="font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Enviado el</span><br><strong id="mdl-submitted"></strong></div>
            <div><span style="font-size:10px;color:#9ca3af;text-transform:uppercase;letter-spacing:.05em;">Consentimiento KYC</span><br><strong id="mdl-consent" style="font-size:11px;"></strong></div>
        </div>
        <h3 style="font-size:13px;font-weight:600;color:#374151;margin:0 0 12px;text-transform:uppercase;letter-spacing:.05em;">📄 Documentos</h3>
        <div id="ltms-docs-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px;margin-bottom:20px;"></div>
        <div id="mdl-notes-block" style="display:none;background:#fef3c7;border:1px solid #fbbf24;border-radius:6px;padding:12px;margin-bottom:12px;">
            <strong style="color:#92400e;font-size:12px;">📝 Notas:</strong>
            <p id="mdl-notes" style="margin:4px 0 0;color:#92400e;font-size:13px;"></p>
        </div>
        <div id="mdl-rejection-block" style="display:none;background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;padding:12px;margin-bottom:12px;">
            <strong style="color:#991b1b;font-size:12px;">✗ Motivo rechazo:</strong>
            <p id="mdl-rejection" style="margin:4px 0 0;color:#991b1b;font-size:13px;"></p>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;border-top:1px solid #e5e7eb;padding-top:16px;">
            <button type="button" id="mdl-btn-approve" class="button button-primary" style="background:#10b981;border-color:#059669;">✓ Aprobar KYC</button>
            <button type="button" id="mdl-btn-reject" class="button" style="color:#ef4444;border-color:#ef4444;">✗ Rechazar KYC</button>
            <button type="button" id="ltms-kyc-modal-close-2" class="button">Cerrar</button>
        </div>
    </div>
</div>
</div>

<script>
(function($){
'use strict';
const nonce   = (typeof ltmsAdmin !== 'undefined' && ltmsAdmin.nonce) ? ltmsAdmin.nonce : '<?php echo esc_js(wp_create_nonce('ltms_admin_nonce')); ?>';
const ajaxUrl = (typeof ltmsAdmin !== 'undefined' && ltmsAdmin.ajax_url) ? ltmsAdmin.ajax_url : ajaxurl;
let currentKycId = null;

$(document).on('click','.ltms-kyc-view-docs',function(){
    currentKycId = $(this).data('kyc-id');
    $('#ltms-modal-loading').show(); $('#ltms-modal-body').hide();
    $('#ltms-kyc-modal').css('display','flex');
    $.post(ajaxUrl,{action:'ltms_get_kyc_details',nonce:nonce,kyc_id:currentKycId},function(res){
        $('#ltms-modal-loading').hide();
        if(!res.success){alert('Error: '+(res.data&&res.data.message||'Desconocido'));return;}
        const d=res.data;
        $('#mdl-name').text(d.display_name||'—');$('#mdl-email').text(d.email||'—');
        $('#mdl-store').text(d.store_name||'—');$('#mdl-city').text(d.city||'—');
        $('#mdl-phone').text(d.phone||'—');$('#mdl-doc').text((d.doc_type||'CC')+' '+(d.doc_masked||'****'));
        $('#mdl-submitted').text(d.submitted_at||'—');
        $('#mdl-consent').text(d.kyc_consent_at?('✅ '+d.kyc_consent_at+(d.kyc_consent_ip?' · IP '+d.kyc_consent_ip:'')):'No registrado');
        $('#ltms-modal-title').text('🪪 KYC #'+d.kyc_id+' — '+(d.display_name||'Vendedor'));
        const docs=d.docs||{},lbl={cedula:'Cédula',rut:'RUT',camara:'Cámara de Comercio',selfie:'Selfie'},icn={cedula:'🪪',rut:'📋',camara:'🏢',selfie:'🤳'};
        let html='';
        $.each(lbl,function(key,label){
            const url=docs[key];
            if(url){
                const isImg=/\.(jpg|jpeg|png|gif|webp)(\?.*)?$/i.test(url);
                html+='<div style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">';
                html+=isImg?'<a href="'+url+'" target="_blank"><img src="'+url+'" alt="'+label+'" style="width:100%;height:130px;object-fit:cover;display:block;"></a>':'<a href="'+url+'" target="_blank" style="display:flex;align-items:center;justify-content:center;height:130px;background:#eff6ff;font-size:40px;">📄</a>';
                html+='<div style="padding:8px 10px 4px;font-size:12px;font-weight:600;color:#374151;">'+icn[key]+' '+label+'</div>';
                html+='<div style="padding:0 10px 10px;"><a href="'+url+'" target="_blank" class="button button-small" style="font-size:11px;">⬇ Ver</a></div></div>';
            } else {
                html+='<div style="border:1px dashed #d1d5db;border-radius:8px;display:flex;flex-direction:column;align-items:center;justify-content:center;height:175px;color:#9ca3af;">';
                html+='<span style="font-size:32px;margin-bottom:6px;">'+icn[key]+'</span><span style="font-size:12px;">'+label+'</span><span style="font-size:10px;margin-top:4px;">No subido</span></div>';
            }
        });
        $('#ltms-docs-grid').html(html);
        if(d.notes){$('#mdl-notes').text(d.notes);$('#mdl-notes-block').show();}else{$('#mdl-notes-block').hide();}
        if(d.rejection_reason){$('#mdl-rejection').text(d.rejection_reason);$('#mdl-rejection-block').show();}else{$('#mdl-rejection-block').hide();}
        if(d.status==='pending'){$('#mdl-btn-approve,#mdl-btn-reject').show();}else{$('#mdl-btn-approve,#mdl-btn-reject').hide();}
        $('#ltms-modal-body').show();
    });
});
$(document).on('click','#ltms-kyc-modal-close,#ltms-kyc-modal-close-2',function(){$('#ltms-kyc-modal').hide();});
$(document).on('click','#ltms-kyc-modal',function(e){if($(e.target).is('#ltms-kyc-modal'))$('#ltms-kyc-modal').hide();});
$(document).on('click','#mdl-btn-approve',function(){
    if(!currentKycId||!confirm('¿Confirmar aprobación KYC?'))return;
    const $b=$(this).prop('disabled',true).text('…');
    $.post(ajaxUrl,{action:'ltms_approve_kyc',nonce:nonce,kyc_id:currentKycId},function(res){
        if(res.success){$('#ltms-kyc-modal').hide();location.reload();}
        else{alert(res.data&&res.data.message||'Error');$b.prop('disabled',false).text('✓ Aprobar KYC');}
    });
});
$(document).on('click','#mdl-btn-reject',function(){
    if(!currentKycId)return;const r=prompt('Motivo del rechazo:');if(!r)return;
    const $b=$(this).prop('disabled',true).text('…');
    $.post(ajaxUrl,{action:'ltms_reject_kyc',nonce:nonce,kyc_id:currentKycId,reason:r},function(res){
        if(res.success){$('#ltms-kyc-modal').hide();location.reload();}
        else{alert(res.data&&res.data.message||'Error');$b.prop('disabled',false).text('✗ Rechazar KYC');}
    });
});
$(document).on('click','.ltms-approve-kyc',function(){
    const id=$(this).data('kyc-id');if(!confirm('¿Aprobar KYC #'+id+'?'))return;
    const $b=$(this).prop('disabled',true).text('…');
    $.post(ajaxUrl,{action:'ltms_approve_kyc',nonce:nonce,kyc_id:id},function(res){
        if(res.success)location.reload();else{alert(res.data&&res.data.message||'Error');$b.prop('disabled',false).text('✓ Aprobar');}
    });
});
$(document).on('click','.ltms-reject-kyc',function(){
    const id=$(this).data('kyc-id');const r=prompt('Motivo del rechazo:');if(!r)return;
    $.post(ajaxUrl,{action:'ltms_reject_kyc',nonce:nonce,kyc_id:id,reason:r},function(res){
        if(res.success)location.reload();else alert(res.data&&res.data.message||'Error');
    });
});
})(jQuery);
</script>
