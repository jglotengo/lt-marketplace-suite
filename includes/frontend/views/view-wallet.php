<?php
/**
 * Vista SPA: Billetera del Vendedor
 *
 * @package LTMS
 * @version 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$vendor_id    = get_current_user_id();
$wallet       = LTMS_Business_Wallet::get_or_create( $vendor_id );
$balance      = (float) $wallet['balance'];
$held         = (float) ( $wallet['balance_pending'] ?? $wallet['balance_reserved'] ?? 0 );
$available    = max( 0, $balance - $held );
$saved_bank        = get_user_meta( $vendor_id, 'ltms_bank_name',           true );
$saved_bank_acc_raw = get_user_meta( $vendor_id, 'ltms_bank_account_number', true );
$saved_bank_type   = get_user_meta( $vendor_id, 'ltms_bank_account_type',   true ) ?: 'ahorros';
$saved_bank_holder = get_user_meta( $vendor_id, 'ltms_bank_account_holder', true );

// v2.9.61 DEEP-AUDIT-002 P0-6 FIX: Desencriptar el número de cuenta.
$saved_bank_acc = '';
if ( ! empty( $saved_bank_acc_raw ) ) {
    if ( class_exists( 'LTMS_Core_Security' ) && method_exists( 'LTMS_Core_Security', 'decrypt' ) ) {
        $decrypted = LTMS_Core_Security::decrypt( $saved_bank_acc_raw );
        $saved_bank_acc = ( $decrypted !== false && $decrypted !== '' ) ? $decrypted : $saved_bank_acc_raw;
    } else {
        $saved_bank_acc = $saved_bank_acc_raw;
    }
    if ( preg_match( '/^v[0-9]+:/', $saved_bank_acc ) ) {
        $saved_bank_acc = '';
    }
}
$has_bank_data     = ! empty( $saved_bank_acc );
?>
<div class="ltms-view-pad">

    <div class="ltms-view-header">
        <h2><?php esc_html_e( 'Billetera', 'ltms' ); ?></h2>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <!-- v2.9.82 P2: CSV Export -->
            <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" id="ltms-wallet-export-csv">
                📥 <?php esc_html_e( 'Exportar CSV', 'ltms' ); ?>
            </button>
            <!-- v2.9.82 P2: Dark mode toggle -->
            <button type="button" class="ltms-btn ltms-btn-outline ltms-btn-sm" id="ltms-dark-mode-toggle" aria-label="<?php esc_attr_e( 'Cambiar tema', 'ltms' ); ?>">
                🌙
            </button>
            <button type="button" class="ltms-btn ltms-btn-secondary" data-ltms-modal-open="ltms-modal-deposit">
                💳 <?php esc_html_e( 'Depositar', 'ltms' ); ?>
            </button>
            <button type="button" class="ltms-btn ltms-btn-primary" data-ltms-modal-open="ltms-modal-payout">
                💸 <?php esc_html_e( 'Solicitar Retiro', 'ltms' ); ?>
            </button>
        </div>
    </div>

    <!-- Widget de Balance -->
    <div class="ltms-wallet-widget" style="margin-bottom:24px;">
        <div class="ltms-wallet-label"><?php esc_html_e( 'Balance Total', 'ltms' ); ?></div>
        <div class="ltms-wallet-balance"><?php echo esc_html( LTMS_Utils::format_money( $balance ) ); ?></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:12px;">
            <div>
                <div style="font-size:0.75rem;opacity:0.8;"><?php esc_html_e( 'Disponible', 'ltms' ); ?></div>
                <div style="font-size:1.1rem;font-weight:600;"><?php echo esc_html( LTMS_Utils::format_money( $available ) ); ?></div>
            </div>
            <div>
                <div style="font-size:0.75rem;opacity:0.8;"><?php esc_html_e( 'En Tránsito', 'ltms' ); ?></div>
                <div style="font-size:1.1rem;font-weight:600;"><?php echo esc_html( LTMS_Utils::format_money( $held ) ); ?></div>
            </div>
        </div>
        <!-- v2.9.86 P2: Tax breakdown display -->
        <div style="margin-top:16px;padding-top:12px;border-top:1px solid rgba(0,0,0,0.06);">
            <div style="font-size:0.75rem;font-weight:600;margin-bottom:8px;color:#6b7280;text-transform:uppercase;letter-spacing:0.5px;"><?php esc_html_e( 'Resumen Fiscal', 'ltms' ); ?></div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;font-size:0.8rem;">
                <div>
                    <div style="color:#9ca3af;">Comisiones</div>
                    <div style="font-weight:600;" id="ltms-wallet-total-commissions">—</div>
                </div>
                <div>
                    <div style="color:#9ca3af;">Retenciones</div>
                    <div style="font-weight:600;" id="ltms-wallet-total-withholdings">—</div>
                </div>
                <div>
                    <div style="color:#9ca3af;">Retiros</div>
                    <div style="font-weight:600;" id="ltms-wallet-total-payouts">—</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Historial de movimientos -->
    <div class="ltms-card">
        <div class="ltms-card-header"><?php esc_html_e( 'Últimos Movimientos', 'ltms' ); ?></div>
        <div class="ltms-card-body ltms-table-scroll" style="padding:0;">
            <table class="ltms-dtable ltms-ledger-table" style="width:100%;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Descripción', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Tipo', 'ltms' ); ?></th>
                        <th><?php esc_html_e( 'Monto', 'ltms' ); ?></th>
                    </tr>
                </thead>
                <tbody id="ltms-wallet-tbody">
                    <tr><td colspan="4" style="text-align:center;padding:30px;color:#9ca3af;">
                        <?php esc_html_e( 'Cargando movimientos...', 'ltms' ); ?>
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>

</div>

    <!-- Historial de Depósitos Manuales -->
    <?php
    $my_deposits = LTMS_Deposit::get_by_vendor( $vendor_id, '', 10, 0 );
    ?>
    <div class="ltms-card" style="margin-top:20px;">
        <div class="ltms-card-header" style="display:flex;justify-content:space-between;align-items:center;">
            <span>💳 <?php esc_html_e( 'Mis Depósitos', 'ltms' ); ?></span>
            <button type="button" class="ltms-btn ltms-btn-secondary ltms-btn-sm"
                    data-ltms-modal-open="ltms-modal-deposit">
                + <?php esc_html_e( 'Nuevo depósito', 'ltms' ); ?>
            </button>
        </div>
        <div class="ltms-card-body" style="padding:0;">
            <?php if ( empty( $my_deposits ) ) : ?>
            <p style="text-align:center;padding:24px;color:#9ca3af;margin:0;">
                <?php esc_html_e( 'Aún no tienes depósitos registrados.', 'ltms' ); ?>
            </p>
            <?php else : ?>
            <table class="ltms-dtable" style="width:100%;">
                <thead><tr>
                    <th><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Monto', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Método', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Referencia', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Comprobante', 'ltms' ); ?></th>
                </tr></thead>
                <tbody>
                <?php
                $dep_badge = [
                    'pending'  => 'style="background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:99px;font-size:.75rem;"',
                    'approved' => 'style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:99px;font-size:.75rem;"',
                    'rejected' => 'style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:99px;font-size:.75rem;"',
                ];
                $dep_label = [
                    'pending'  => '⏳ ' . __( 'Pendiente', 'ltms' ),
                    'approved' => '✅ ' . __( 'Aprobado', 'ltms' ),
                    'rejected' => '❌ ' . __( 'Rechazado', 'ltms' ),
                ];
                foreach ( $my_deposits as $dep ) :
                    $st  = $dep['status'] ?? 'pending';
                    $bdg = $dep_badge[ $st ] ?? '';
                    $lbl = $dep_label[ $st ] ?? esc_html( $st );
                ?>
                    <tr>
                        <td style="white-space:nowrap;font-size:.82rem;"><?php echo esc_html( substr( $dep['created_at'], 0, 10 ) ); ?></td>
                        <td><strong><?php echo esc_html( LTMS_Utils::format_money( (float) $dep['amount'] ) ); ?></strong></td>
                        <td style="font-size:.82rem;"><?php echo esc_html( strtoupper( $dep['method'] ) ); ?></td>
                        <td style="font-size:.82rem;"><?php echo esc_html( $dep['reference'] ?: '—' ); ?></td>
                        <td>
                            <span <?php echo $bdg; ?>><?php echo esc_html( $lbl ); ?></span>
                            <?php if ( $st === 'rejected' && ! empty( $dep['reject_reason'] ) ) : ?>
                            <br><small style="color:#6b7280;font-size:.72rem;"
                                       title="<?php echo esc_attr( $dep['reject_reason'] ); ?>">
                                ℹ️ <?php esc_html_e( 'Ver motivo', 'ltms' ); ?>
                            </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ( ! empty( $dep['receipt_url'] ) ) : ?>
                            <a href="<?php echo esc_url( $dep['receipt_url'] ); ?>" target="_blank" rel="noopener"
                               style="font-size:.8rem;color:#2563eb;">
                                📎 <?php esc_html_e( 'Ver', 'ltms' ); ?>
                            </a>
                            <?php else : ?>
                            <span style="color:#9ca3af;font-size:.78rem;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>


<!-- Modal de Retiro -->
<div class="ltms-modal" id="ltms-modal-payout" role="dialog" aria-modal="true" aria-labelledby="ltms-payout-title">
    <div class="ltms-modal-backdrop"></div>
    <div class="ltms-modal-inner" style="max-width:440px;background:#fff;border-radius:12px;padding:28px;margin:auto;position:relative;z-index:1;">
        <div style="display:flex;justify-content:space-between;margin-bottom:20px;">
            <h3 id="ltms-payout-title" style="margin:0;font-size:1.1rem;"><?php esc_html_e( 'Solicitar Retiro', 'ltms' ); ?></h3>
            <button type="button" class="ltms-modal-close" aria-label="<?php esc_attr_e( 'Cerrar', 'ltms' ); ?>" style="background:none;border:none;cursor:pointer;font-size:1.1rem;">✕</button>
        </div>

        <div class="ltms-balance-display" style="text-align:center;background:linear-gradient(135deg,#1a5276,#2980b9);color:#fff;border-radius:8px;padding:16px;margin-bottom:20px;">
            <div style="font-size:0.8rem;opacity:0.8;"><?php esc_html_e( 'Balance disponible', 'ltms' ); ?></div>
            <div id="ltms-payout-balance-display" style="font-size:1.8rem;font-weight:800;"><?php echo esc_html( LTMS_Utils::format_money( $available ) ); ?></div>
        </div>

        <div class="ltms-modal-error" style="display:none;color:#e74c3c;font-size:0.875rem;margin-bottom:12px;"></div>

        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Monto a retirar', 'ltms' ); ?></label>
            <input type="number" id="ltms-payout-amount" min="1" step="1000"
                   max="<?php echo esc_attr( $available ); ?>"
                   placeholder="<?php echo esc_attr( LTMS_Utils::format_money( $available ) ); ?>"
                   style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:0.9rem;">
        </div>

        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Método de pago', 'ltms' ); ?></label>
            <select id="ltms-payout-method" style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;">
                <option value="bank_transfer"><?php esc_html_e( 'Transferencia Bancaria', 'ltms' ); ?></option>
                <option value="nequi"><?php esc_html_e( 'Nequi', 'ltms' ); ?></option>
            </select>
        </div>

        <div style="margin-bottom:20px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Cuenta bancaria', 'ltms' ); ?></label>
            <?php if ( $has_bank_data ) : ?>
            <?php
            // v2.9.77 P0-UI-2: Enmascarar el número de cuenta — mostrar solo últimos 4 dígitos.
            $masked_acc = '****' . substr( preg_replace( '/\D/', '', $saved_bank_acc ), -4 );

            // FIX-P1-BATCH-A: #ltms-payout-account must send the REAL account
            // identifier (the encrypted user_meta blob stored in
            // `ltms_bank_account_number`), NOT the masked display string.
            // Sending `****1234` polluted the payouts table with unusable
            // masked values and broke server-side reconciliation/audit. The
            // Payout_Scheduler already decrypts this blob server-side to
            // verify the last-4 digits, so we pass the encrypted blob through.
            // If the raw meta is missing (legacy plaintext accounts), fall
            // back to the decrypted value — still better than the mask.
            $payout_account_value = ! empty( $saved_bank_acc_raw ) ? $saved_bank_acc_raw : $saved_bank_acc;
            ?>
            <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:8px;padding:10px 14px;margin-bottom:8px;font-size:0.82rem;color:#166534;">
                <strong><?php echo esc_html( $saved_bank ); ?></strong>
                · <?php echo esc_html( ucfirst( $saved_bank_type ) ); ?>
                · <?php echo esc_html( $masked_acc ); ?>
                <?php if ( $saved_bank_holder ) : ?> · <?php echo esc_html( $saved_bank_holder ); ?><?php endif; ?>
                <br><span style="font-size:0.75rem;color:#4ade80;">✓ <?php esc_html_e( 'Cuenta guardada en Configuración', 'ltms' ); ?></span>
            </div>
            <input type="hidden" id="ltms-payout-account" value="<?php echo esc_attr( $payout_account_value ); ?>">
            <?php else : ?>
            <input type="text" id="ltms-payout-account"
                   placeholder="<?php esc_attr_e( 'Número de cuenta', 'ltms' ); ?>"
                   style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:0.9rem;">
            <p style="font-size:0.75rem;color:#f59e0b;margin-top:6px;">
                ⚠️ <a href="#" data-action="load-view" data-view="settings" style="color:#f59e0b;"><?php esc_html_e( 'Configura tu cuenta bancaria en Configuración para agilizar tus retiros.', 'ltms' ); ?></a>
            </p>
            <?php endif; ?>
        </div>

        <button type="button" data-action="submit-payout"
                class="ltms-btn ltms-btn-primary" style="width:100%;justify-content:center;padding:12px;">
            <?php esc_html_e( 'Confirmar Retiro', 'ltms' ); ?>
        </button>
    </div>
</div>

<!-- Modal de Depósito Manual -->
<div class="ltms-modal" id="ltms-modal-deposit" role="dialog" aria-modal="true" aria-labelledby="ltms-deposit-title">
    <div class="ltms-modal-backdrop"></div>
    <div class="ltms-modal-inner" style="max-width:480px;background:#fff;border-radius:12px;padding:28px;margin:auto;position:relative;z-index:1;">
        <div style="display:flex;justify-content:space-between;margin-bottom:20px;">
            <h3 id="ltms-deposit-title" style="margin:0;font-size:1.1rem;">💳 <?php esc_html_e( 'Depositar en Billetera', 'ltms' ); ?></h3>
            <button type="button" class="ltms-modal-close" aria-label="<?php esc_attr_e( 'Cerrar', 'ltms' ); ?>" style="background:none;border:none;cursor:pointer;font-size:1.1rem;">✕</button>
        </div>

        <div class="ltms-deposit-error" style="display:none;color:#e74c3c;font-size:0.875rem;margin-bottom:12px;padding:10px;background:#fdf0ef;border-radius:6px;"></div>
        <div class="ltms-deposit-success" style="display:none;color:#27ae60;font-size:0.875rem;margin-bottom:12px;padding:10px;background:#eafaf1;border-radius:6px;"></div>

        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Monto a depositar (COP)', 'ltms' ); ?></label>
            <input type="number" id="ltms-deposit-amount" min="<?php echo esc_attr( get_option('ltms_min_deposit_amount', 10000) ); ?>" step="1000"
                   placeholder="Ej: 100000"
                   style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:0.9rem;">
            <small style="color:#9ca3af;font-size:0.75rem;">
                Mínimo: <?php echo esc_html( LTMS_Utils::format_money( (float) get_option('ltms_min_deposit_amount', 10000) ) ); ?>
            </small>
        </div>

        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Método de pago', 'ltms' ); ?></label>
            <select id="ltms-deposit-method" style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;">
                <option value="pse">PSE - Pago Seguro en Línea</option>
                <option value="nequi">Nequi</option>
                <option value="transferencia">Transferencia Bancaria</option>
            </select>
        </div>

        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Número de referencia / comprobante', 'ltms' ); ?></label>
            <input type="text" id="ltms-deposit-reference"
                   placeholder="Ej: TXN-20260529-001"
                   style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:0.9rem;">
        </div>

        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Subir comprobante (JPG, PNG, PDF)', 'ltms' ); ?></label>
            <input type="file" id="ltms-deposit-receipt" accept=".jpg,.jpeg,.png,.webp,.pdf"
                   style="width:100%;padding:8px;border:1.5px dashed #d1d5db;border-radius:8px;">
            <div id="ltms-deposit-receipt-status" style="font-size:0.8rem;color:#6b7280;margin-top:4px;"></div>
        </div>

        <div style="margin-bottom:20px;">
            <label style="display:block;font-size:0.875rem;font-weight:500;margin-bottom:6px;"><?php esc_html_e( 'Notas adicionales (opcional)', 'ltms' ); ?></label>
            <textarea id="ltms-deposit-notes" rows="2"
                      placeholder="Cualquier información adicional para el equipo..."
                      style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;resize:vertical;font-size:0.9rem;"></textarea>
        </div>

        <!-- Info bancaria para transferencias -->
        <div style="background:#f0f9ff;border-radius:8px;padding:14px;margin-bottom:20px;font-size:0.82rem;color:#0369a1;">
            <strong>Datos bancarios Lo Tengo:</strong><br>
            Banco: <?php echo esc_html( get_option('ltms_bank_name', 'Bancolombia') ); ?><br>
            Cuenta: <?php echo esc_html( get_option('ltms_bank_account', 'xxx-xxx-xxx') ); ?><br>
            Titular: Lo Tengo Colombia S.A.S.<br>
            NIT: <?php echo esc_html( get_option('ltms_company_nit', '900.xxx.xxx-x') ); ?>
        </div>

        <button type="button" id="ltms-deposit-submit"
                class="ltms-btn ltms-btn-primary" style="width:100%;justify-content:center;padding:12px;">
            <?php esc_html_e( 'Enviar Solicitud de Depósito', 'ltms' ); ?>
        </button>

        <p style="font-size:0.75rem;color:#9ca3af;text-align:center;margin-top:12px;">
            <?php esc_html_e( 'Tu depósito será revisado y aprobado en menos de 24 horas hábiles.', 'ltms' ); ?>
        </p>
    </div>
</div>

<script>
(function($) {
    'use strict';

    var depositReceiptUrl = '';

    // Subir comprobante al seleccionar archivo
    $('#ltms-deposit-receipt').on('change', function() {
        var file = this.files[0];
        if (!file) return;

        var statusEl = $('#ltms-deposit-receipt-status');
        statusEl.text('Subiendo comprobante...');

        var formData = new FormData();
        formData.append('action', 'ltms_upload_receipt');
        formData.append('nonce', ltmsDashboard.nonce);
        formData.append('receipt', file);

        $.ajax({
            url: ltmsDashboard.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(res) {
                if (res.success) {
                    depositReceiptUrl = res.data.url;
                    statusEl.css('color', '#27ae60').text('✅ Comprobante subido correctamente.');
                } else {
                    statusEl.css('color', '#e74c3c').text('❌ Error: ' + (res.data || 'No se pudo subir el archivo.'));
                }
            },
            error: function() {
                statusEl.css('color', '#e74c3c').text('❌ Error de conexión al subir el comprobante.');
            }
        });
    });

    // Enviar solicitud de depósito
    $('#ltms-deposit-submit').on('click', function() {
        var amount    = parseFloat($('#ltms-deposit-amount').val());
        var method    = $('#ltms-deposit-method').val();
        var reference = $('#ltms-deposit-reference').val().trim();
        var notes     = $('#ltms-deposit-notes').val().trim();
        var errEl     = $('.ltms-deposit-error');
        var okEl      = $('.ltms-deposit-success');
        var btn       = $(this);

        errEl.hide();
        okEl.hide();

        if (!amount || amount <= 0) {
            errEl.text('Ingresa un monto válido.').show();
            return;
        }

        btn.prop('disabled', true).text('Enviando...');

        $.post(ltmsDashboard.ajax_url, {
            action:      'ltms_create_deposit',
            nonce:       ltmsDashboard.nonce,
            amount:      amount,
            method:      method,
            reference:   reference,
            receipt_url: depositReceiptUrl,
            notes:       notes,
        }, function(res) {
            if (res.success) {
                okEl.text(res.data.message).show();
                // Limpiar formulario
                $('#ltms-deposit-amount, #ltms-deposit-reference, #ltms-deposit-notes').val('');
                $('#ltms-deposit-receipt').val('');
                $('#ltms-deposit-receipt-status').text('');
                depositReceiptUrl = '';
                btn.text('✅ Solicitud enviada');
                // Recargar la vista tras 3s
                setTimeout(function() { LTMS.Dashboard.loadView('wallet', true); }, 3000);
            } else {
                errEl.text(res.data || 'Error desconocido.').show();
                btn.prop('disabled', false).text('Enviar Solicitud de Depósito');
            }
        }).fail(function() {
            errEl.text('Error de conexión.').show();
            btn.prop('disabled', false).text('Enviar Solicitud de Depósito');
        });
    });
})(jQuery);
</script>

