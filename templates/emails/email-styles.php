<?php
/**
 * Email: Estilos modernos inline reutilizables
 *
 * Este partial define estilos CSS inline (compatibles con clientes de email)
 * que pueden aplicarse a cualquier plantilla de correo de LTMS.
 *
 * Para usarlo, renderizar antes del contenido del email:
 *   include __DIR__ . '/email-styles.php';
 *
 * Los estilos se aplican via <style> en el <head> del email o inline
 * en cada elemento.
 *
 * @package LTMS
 * @version 2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Paleta de colores corporativa (alineada con design tokens del frontend)
$ltms_email_colors = [
    'primary'    => '#0F4C75',
    'secondary'  => '#3282B8',
    'accent'     => '#F39C12',
    'success'    => '#16A34A',
    'success_bg' => '#DCFCE7',
    'warning'    => '#F59E0B',
    'warning_bg' => '#FEF3C7',
    'danger'     => '#DC2626',
    'danger_bg'  => '#FEE2E2',
    'info'       => '#2563EB',
    'info_bg'    => '#DBEAFE',
    'dark'       => '#1F2937',
    'gray_700'   => '#374151',
    'gray_500'   => '#6B7280',
    'gray_300'   => '#D1D5DB',
    'gray_200'   => '#E5E7EB',
    'gray_100'   => '#F3F4F6',
    'gray_50'    => '#F9FAFB',
    'white'      => '#FFFFFF',
];

// Estilos base que se inyectan en el <head> del email
$ltms_email_base_styles = '
    body { margin:0; padding:0; background:' . $ltms_email_colors['gray_100'] . '; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif; color:' . $ltms_email_colors['dark'] . '; }
    .ltms-email-container { max-width:600px; margin:0 auto; padding:20px; }
    .ltms-email-card { background:' . $ltms_email_colors['white'] . '; border-radius:12px; overflow:hidden; box-shadow:0 4px 12px rgba(0,0,0,0.05); }
    .ltms-email-header { background:linear-gradient(135deg,' . $ltms_email_colors['primary'] . ',' . $ltms_email_colors['secondary'] . '); color:' . $ltms_email_colors['white'] . '; padding:32px 24px; text-align:center; }
    .ltms-email-header h1 { margin:0; font-size:24px; font-weight:800; letter-spacing:-0.5px; }
    .ltms-email-header p { margin:8px 0 0; font-size:14px; opacity:0.9; }
    .ltms-email-body { padding:32px 24px; }
    .ltms-email-body p { margin:0 0 16px; font-size:15px; line-height:1.6; color:' . $ltms_email_colors['gray_700'] . '; }
    .ltms-email-body p:last-child { margin-bottom:0; }
    .ltms-email-body strong { color:' . $ltms_email_colors['dark'] . '; }
    .ltms-email-body a { color:' . $ltms_email_colors['secondary'] . '; text-decoration:none; font-weight:600; }
    .ltms-email-table { width:100%; border-collapse:collapse; margin:16px 0; border:1px solid ' . $ltms_email_colors['gray_200'] . '; border-radius:8px; overflow:hidden; }
    .ltms-email-table th { text-align:left; padding:12px 16px; background:' . $ltms_email_colors['gray_50'] . '; color:' . $ltms_email_colors['gray_700'] . '; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; border-bottom:1px solid ' . $ltms_email_colors['gray_200'] . '; }
    .ltms-email-table td { padding:12px 16px; border-bottom:1px solid ' . $ltms_email_colors['gray_200'] . '; font-size:14px; color:' . $ltms_email_colors['gray_700'] . '; }
    .ltms-email-table tr:last-child td { border-bottom:none; }
    .ltms-email-table .ltms-email-total td { font-weight:700; color:' . $ltms_email_colors['dark'] . '; background:' . $ltms_email_colors['gray_50'] . '; }
    .ltms-email-btn { display:inline-block; padding:14px 32px; background:linear-gradient(135deg,' . $ltms_email_colors['primary'] . ',' . $ltms_email_colors['secondary'] . '); color:' . $ltms_email_colors['white'] . '!important; text-decoration:none!important; border-radius:8px; font-weight:700; font-size:15px; margin:16px 0; }
    .ltms-email-btn:hover { opacity:0.95; }
    .ltms-email-alert { padding:14px 16px; border-radius:8px; margin:16px 0; font-size:14px; line-height:1.5; border-left:4px solid; }
    .ltms-email-alert-success { background:' . $ltms_email_colors['success_bg'] . '; border-color:' . $ltms_email_colors['success'] . '; color:#166534; }
    .ltms-email-alert-warning { background:' . $ltms_email_colors['warning_bg'] . '; border-color:' . $ltms_email_colors['warning'] . '; color:#92400E; }
    .ltms-email-alert-danger { background:' . $ltms_email_colors['danger_bg'] . '; border-color:' . $ltms_email_colors['danger'] . '; color:#991B1B; }
    .ltms-email-alert-info { background:' . $ltms_email_colors['info_bg'] . '; border-color:' . $ltms_email_colors['info'] . '; color:#1E40AF; }
    .ltms-email-footer { padding:24px; text-align:center; color:' . $ltms_email_colors['gray_500'] . '; font-size:13px; line-height:1.5; }
    .ltms-email-footer a { color:' . $ltms_email_colors['gray_500'] . '; }
    .ltms-email-divider { height:1px; background:' . $ltms_email_colors['gray_200'] . '; margin:24px 0; border:none; }
    .ltms-email-status-badge { display:inline-block; padding:4px 12px; border-radius:99px; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.3px; }
    .ltms-email-status-success { background:' . $ltms_email_colors['success_bg'] . '; color:' . $ltms_email_colors['success'] . '; }
    .ltms-email-status-warning { background:' . $ltms_email_colors['warning_bg'] . '; color:' . $ltms_email_colors['warning'] . '; }
    .ltms-email-status-danger { background:' . $ltms_email_colors['danger_bg'] . '; color:' . $ltms_email_colors['danger'] . '; }
    .ltms-email-status-info { background:' . $ltms_email_colors['info_bg'] . '; color:' . $ltms_email_colors['info'] . '; }
    @media (max-width:480px) {
        .ltms-email-container { padding:12px; }
        .ltms-email-header { padding:24px 16px; }
        .ltms-email-header h1 { font-size:20px; }
        .ltms-email-body { padding:24px 16px; }
        .ltms-email-table th, .ltms-email-table td { padding:10px 12px; font-size:13px; }
    }
';

// Función helper para estilos inline (para clientes de email que no soportan <style>)
if ( ! function_exists( 'ltms_email_inline_style' ) ) {
    function ltms_email_inline_style( $element ) {
        $styles = [
            'card'   => 'background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,0.05);',
            'header' => 'background:linear-gradient(135deg,#0F4C75,#3282B8);color:#fff;padding:32px 24px;text-align:center;',
            'body'   => 'padding:32px 24px;',
            'footer' => 'padding:24px;text-align:center;color:#6B7280;font-size:13px;line-height:1.5;',
            'btn'    => 'display:inline-block;padding:14px 32px;background:#0F4C75;color:#fff!important;text-decoration:none!important;border-radius:8px;font-weight:700;font-size:15px;',
            'table'  => 'width:100%;border-collapse:collapse;margin:16px 0;border:1px solid #E5E7EB;',
            'th'     => 'text-align:left;padding:12px 16px;background:#F9FAFB;color:#374151;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;border-bottom:1px solid #E5E7EB;',
            'td'     => 'padding:12px 16px;border-bottom:1px solid #E5E7EB;font-size:14px;color:#374151;',
            'alert_success' => 'padding:14px 16px;border-radius:8px;margin:16px 0;font-size:14px;border-left:4px solid #16A34A;background:#DCFCE7;color:#166534;',
            'alert_warning' => 'padding:14px 16px;border-radius:8px;margin:16px 0;font-size:14px;border-left:4px solid #F59E0B;background:#FEF3C7;color:#92400E;',
            'alert_danger'  => 'padding:14px 16px;border-radius:8px;margin:16px 0;font-size:14px;border-left:4px solid #DC2626;background:#FEE2E2;color:#991B1B;',
            'alert_info'    => 'padding:14px 16px;border-radius:8px;margin:16px 0;font-size:14px;border-left:4px solid #2563EB;background:#DBEAFE;color:#1E40AF;',
        ];
        return $styles[ $element ] ?? '';
    }
}
?>
<style>
<?php echo $ltms_email_base_styles; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</style>
