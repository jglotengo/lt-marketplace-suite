<?php
/**
 * Vista SPA: Billetera del Vendedor
 *
 * @package LTMS
 * @version 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$vendor_id    = get_current_user_id();
$wallet       = LTMS_Wallet::get_or_create( $vendor_id );
$balance      = (float) $wallet['balance'];
$held         = (float) $wallet['held_balance'];
$available    = max( 0, $balance - $held );
?>
<div style="padding:24px;">

    <div class="ltms-view-header">
        <h2><?php esc_html_e( 'Billetera', 'ltms' ); ?></h2>
        <button type="button" class="ltms-btn ltms-btn-primary" data-ltms-modal-open="ltms-modal-payout">
            💸 <?php esc_html_e( 'Solicitar Retiro', 'ltms' ); ?>
        </button>
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
    </div>

    <!-- Historial de movimientos -->
    <div class="ltms-card">
        <div class="ltms-card-header"><?php esc_html_e( 'Últimos Movimientos', 'ltms' ); ?></div>
        <div class="ltms-card-body" style="padding:0;">
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

<!-- Modal de Retiro -->
<div class="ltms-modal" id="ltms-modal-payout">
    <div class="ltms-modal-backdrop"></div>
    <div class="ltms-modal-inner" style="max-width:440px;background:#fff;border-radius:12px;padding:28px;margin:auto;position:relative;z-index:1;">
        <div style="display:flex;justify-content:space-between;margin-bottom:20px;">
            <h3 style="margin:0;font-size:1.1rem;"><?php esc_html_e( 'Solicitar Retiro', 'ltms' ); ?></h3>
            <button type="button" class="ltms-modal-close" style="background:none;border:none;cursor:pointer;font-size:1.1rem;">✕</button>
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
            <input type="text" id="ltms-payout-account"
                   placeholder="<?php esc_attr_e( 'Número de cuenta', 'ltms' ); ?>"
                   style="width:100%;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;font-size:0.9rem;">
        </div>

        <button type="button" onclick="LTMS.Dashboard.submitPayoutRequest()"
                class="ltms-btn ltms-btn-primary" style="width:100%;justify-content:center;padding:12px;">
            <?php esc_html_e( 'Confirmar Retiro', 'ltms' ); ?>
        </button>
    </div>
</div>
