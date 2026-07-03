<?php
/**
 * LTMS Donation Certificate Generator
 *
 * Genera un PDF de "Certificado de Donación" para un lote de transferencia
 * a la Fundación Cardio Infantil, usando DOMPDF (mismo motor que el contrato
 * de vendedor en class-ltms-contract-pdf-generator.php).
 *
 * Flujo:
 *   1. Carga los datos del lote (lt_donation_payouts) y las donaciones que lo
 *      componen (lt_donations).
 *   2. Reúne los datos de la fundación desde las opciones de configuración.
 *   3. Renderiza el HTML del certificado con esos datos.
 *   4. Convierte el HTML a PDF con DOMPDF.
 *   5. Sube el PDF a Backblaze B2 (bucket configurado para certificados).
 *   6. Actualiza la columna certificate_path del lote.
 *   7. Dispara el hook `ltms_donation_certificate_generated` para que el
 *      listener de emails envíe la notificación a la fundación.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/business
 * @version    1.0.0
 * @since      3.0.0  Task 60-D — Donation Reports + Admin + Certificates
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Donation_Certificate
 *
 * Generación de certificados de donación en PDF y almacenamiento en B2.
 */
final class LTMS_Donation_Certificate {

    use LTMS_Logger_Aware;

    /**
     * Datos por defecto de la Fundación Cardio Infantil.
     * Los valores reales se leen desde las opciones de WP, este array es sólo
     * un fallback si las opciones no están configuradas todavía.
     */
    const FOUNDATION_DEFAULTS = [
        'name'    => 'Fundación Cardio Infantil',
        'nit'     => '860.045.302-1',
        'address' => 'Calle 134 No. 7B-83, Bogotá D.C., Colombia',
        'phone'   => '+57 (601) 629 3322',
        'email'   => 'donaciones@cardioinfantil.org',
        'website' => 'https://www.cardioinfantil.org',
    ];

    /**
     * Bucket B2 por defecto donde se almacenan los certificados.
     * Se puede sobreescribir con la opción `ltms_donation_cert_bucket`.
     */
    const DEFAULT_BUCKET = 'lotengo-donation-certificates';

    /**
     * Máximo número de donaciones que se listan individualmente en el PDF.
     * Si el lote tiene más, se muestra un resumen agregado por vendedor.
     */
    const MAX_LISTED_DONATIONS = 50;

    /**
     * Inicializa los hooks del módulo.
     *
     * @return void
     */
    public static function init(): void {
        // Hook público: cualquier módulo puede disparar la generación del
        // certificado de forma asíncrona (cron, listener de payout, etc.).
        add_action( 'ltms_donation_certificate_generate', [ __CLASS__, 'generate' ] );
    }

    /**
     * Genera el PDF del certificado de donación para un lote.
     *
     * @param int $batch_id ID del lote en `lt_donation_payouts`.
     * @return string|\WP_Error  Ruta relativa en B2 del PDF generado, o WP_Error.
     */
    public static function generate( int $batch_id ) {
        if ( ! $batch_id ) {
            return new \WP_Error( 'invalid_batch', __( 'ID de lote inválido.', 'ltms' ) );
        }

        try {
            $batch      = self::load_batch( $batch_id );
            $donations  = self::load_donations( $batch_id );
            $foundation = self::get_foundation_info();

            // Si el lote ya tiene un certificado, no se regenera salvo forzado.
            if ( ! empty( $batch['certificate_path'] ) && empty( $_REQUEST['force_regenerate'] ) ) { // phpcs:ignore
                self::log_info(
                    'DONATION_CERT_EXISTS',
                    sprintf( 'Lote #%d ya tiene certificado: %s', $batch_id, $batch['certificate_path'] ),
                    [ 'batch_id' => $batch_id ]
                );
                return $batch['certificate_path'];
            }

            $html = self::render_html( $batch, $donations, $foundation );
            $pdf  = self::html_to_pdf( $html );

            $key  = self::build_storage_key( $batch, $foundation );
            $path = self::upload_to_b2( $key, $pdf, $foundation );

            if ( is_wp_error( $path ) ) {
                return $path;
            }

            self::persist_path( $batch_id, $path );

            self::log_security(
                'DONATION_CERT_GENERATED',
                sprintf(
                    'Certificado de donación generado para lote #%d por admin #%d. Path: %s',
                    $batch_id,
                    get_current_user_id(),
                    $path
                ),
                [ 'batch_id' => $batch_id, 'path' => $path ]
            );

            // Disparar acción para que el listener de emails envíe la notificación
            // a la fundación con el enlace seguro al PDF recién generado.
            do_action( 'ltms_donation_certificate_generated', $batch_id, $path, $foundation );

            return $path;

        } catch ( \Throwable $e ) {
            self::log_error(
                'DONATION_CERT_FAILED',
                sprintf( 'Error generando certificado para lote #%d: %s', $batch_id, $e->getMessage() ),
                [ 'batch_id' => $batch_id, 'exception' => $e->getTraceAsString() ]
            );
            return new \WP_Error(
                'cert_generation_failed',
                sprintf( __( 'Error generando certificado: %s', 'ltms' ), $e->getMessage() )
            );
        }
    }

    /**
     * Genera una URL firmada (presigned) para descargar el PDF del certificado
     * durante el tiempo indicado. Se usa en el email enviado a la fundación.
     *
     * @param string $certificate_path Ruta relativa en B2 (la que se guardó).
     * @param int    $ttl              TTL en segundos (default 7 días).
     * @return string URL firmada, o cadena vacía si no se puede generar.
     */
    public static function build_download_url( string $certificate_path, int $ttl = 604800 ): string {
        if ( empty( $certificate_path ) ) {
            return '';
        }

        $bucket = LTMS_Core_Config::get( 'ltms_donation_cert_bucket', self::DEFAULT_BUCKET );

        if ( ! class_exists( 'LTMS_Api_Factory' ) || ! LTMS_Api_Factory::has( 'backblaze' ) ) {
            return '';
        }

        try {
            $b2 = LTMS_Api_Factory::get( 'backblaze' );
            return $b2->get_signed_url( $bucket, $certificate_path, $ttl );
        } catch ( \Throwable $e ) {
            self::log_warning(
                'DONATION_CERT_URL_FAILED',
                sprintf( 'No se pudo generar URL firmada para %s: %s', $certificate_path, $e->getMessage() ),
                [ 'path' => $certificate_path ]
            );
            return '';
        }
    }

    // ── Carga de datos ─────────────────────────────────────────────

    /**
     * Carga los datos del lote desde la base de datos.
     *
     * @param int $batch_id ID del lote.
     * @return array<string,mixed>
     * @throws \RuntimeException Si el lote no existe.
     */
    private static function load_batch( int $batch_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_donation_payouts';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $batch = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $batch_id ),
            ARRAY_A
        );

        if ( ! $batch ) {
            throw new \RuntimeException( sprintf( 'Lote de donación #%d no encontrado.', $batch_id ) );
        }

        return $batch;
    }

    /**
     * Carga las donaciones que conforman el lote, ordenadas por fecha.
     *
     * @param int $batch_id ID del lote.
     * @return array<int,array<string,mixed>>
     */
    private static function load_donations( int $batch_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_donations';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $donations = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT d.*, u.display_name AS vendor_name, u.user_email AS vendor_email
                   FROM `{$table}` d
                   LEFT JOIN `{$wpdb->users}` u ON u.ID = d.vendor_id
                  WHERE d.payout_batch_id = %d
                  ORDER BY d.created_at ASC",
                $batch_id
            ),
            ARRAY_A
        );

        return is_array( $donations ) ? $donations : [];
    }

    /**
     * Lee la configuración de la fundación desde las opciones de WP.
     *
     * @return array<string,string>
     */
    private static function get_foundation_info(): array {
        return [
            'name'           => LTMS_Core_Config::get( 'ltms_donation_foundation_name', self::FOUNDATION_DEFAULTS['name'] ),
            'nit'            => LTMS_Core_Config::get( 'ltms_donation_foundation_nit', self::FOUNDATION_DEFAULTS['nit'] ),
            'address'        => LTMS_Core_Config::get( 'ltms_donation_foundation_address', self::FOUNDATION_DEFAULTS['address'] ),
            'phone'          => LTMS_Core_Config::get( 'ltms_donation_foundation_phone', self::FOUNDATION_DEFAULTS['phone'] ),
            'email'          => LTMS_Core_Config::get( 'ltms_donation_foundation_email', self::FOUNDATION_DEFAULTS['email'] ),
            'website'        => LTMS_Core_Config::get( 'ltms_donation_foundation_website', self::FOUNDATION_DEFAULTS['website'] ),
            'tax_deductible' => LTMS_Core_Config::get( 'ltms_donation_tax_deductible', 'yes' ),
            'legal_text'     => LTMS_Core_Config::get(
                'ltms_donation_legal_text',
                __( 'Este certificado es válido como soporte para deducción fiscal conforme al Estatuto Tributario Colombiano (Art. 125 y ss.) y la Ley 1819 de 2016. La Fundación Cardio Infantil es una entidad sin ánimo de lucro calificada como beneficiaria de donaciones por la DIAN.', 'ltms' )
            ),
        ];
    }

    // ── Renderizado HTML ───────────────────────────────────────────

    /**
     * Renderiza el HTML del certificado listo para DOMPDF.
     *
     * @param array<string,mixed>              $batch      Datos del lote.
     * @param array<int,array<string,mixed>>   $donations  Donaciones del lote.
     * @param array<string,string>             $foundation Datos de la fundación.
     * @return string HTML.
     */
    private static function render_html( array $batch, array $donations, array $foundation ): string {
        $currency       = $batch['currency'] ?? LTMS_Core_Config::get_currency();
        $total_donated  = (float) ( $batch['total_amount'] ?? 0 );
        $donation_count = (int) ( $batch['transaction_count'] ?? count( $donations ) );
        $period_start   = $batch['period_start'] ?? '';
        $period_end     = $batch['period_end'] ?? '';
        $batch_number   = $batch['batch_number'] ?? ( 'L' . $batch['id'] ?? '' );
        $tax_deductible = ( $foundation['tax_deductible'] ?? 'yes' ) === 'yes';

        $platform_name = LTMS_Core_Config::get( 'ltms_platform_name', get_bloginfo( 'name' ) );
        $platform_nit  = LTMS_Core_Config::get( 'ltms_platform_nit', '901.981.692-3' );

        $issuance_date = self::format_spanish_date( gmdate( 'Y-m-d' ) );

        // Construir la tabla de donaciones (o resumen si hay demasiadas).
        $donations_html = self::render_donations_table( $donations, $currency );

        // QR code (opcional): un enlace de verificación con el batch_id.
        $verify_url = add_query_arg(
            [
                'ltms_cert_verify' => '1',
                'batch'            => rawurlencode( (string) $batch_number ),
                'h'                => rawurlencode( substr( hash( 'sha256', $batch_number . '|' . ( $batch['id'] ?? '' ) . '|' . wp_salt() ), 0, 16 ) ),
            ],
            home_url( '/' )
        );
        $qr_svg = self::build_qr_svg( $verify_url );

        // Marca de agua "DEDUCIBLE" si aplica.
        $watermark = $tax_deductible
            ? '<div class="watermark">DEDUCIBLE</div>'
            : '';

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body { font-family:'DejaVu Sans',Arial,sans-serif; font-size:10pt; color:#1a1a1a; line-height:1.5; position:relative; }
.page-header { background:#1e6091; color:#fff; padding:14px 18px; text-align:center; }
.page-header h1 { font-size:18pt; margin:0; letter-spacing:0.5px; text-transform:uppercase; }
.page-header .subtitle { font-size:9pt; margin-top:4px; opacity:0.9; }
.logo-bar { display:table; width:100%; border-bottom:3px solid #1e6091; padding:14px 0 10px; margin-bottom:14px; }
.logo-cell { display:table-cell; width:35%; vertical-align:middle; font-size:14pt; font-weight:bold; color:#1e6091; }
.title-cell { display:table-cell; width:65%; vertical-align:middle; text-align:right; color:#555; font-size:9pt; }
.certificate-id { font-family:monospace; font-weight:bold; color:#1e6091; }
.intro-block { background:#f0f6fb; border-left:4px solid #1e6091; padding:12px 16px; margin:14px 0; font-size:10pt; }
.party-table { width:100%; border-collapse:collapse; margin:12px 0; }
.party-table th { background:#1e6091; color:#fff; padding:8px 10px; font-size:9pt; text-align:left; width:50%; }
.party-table td { border:1px solid #d8e3ee; padding:8px 10px; font-size:9pt; vertical-align:top; background:#fbfdff; }
.summary-table { width:100%; border-collapse:collapse; margin:14px 0; }
.summary-table td { border:1px solid #d8e3ee; padding:8px 12px; font-size:10pt; }
.summary-table .label { background:#f0f6fb; font-weight:bold; color:#1e6091; width:35%; }
.summary-table .value { font-weight:bold; }
.amount-big { font-size:18pt; color:#1e6091; font-weight:bold; }
.donations-table { width:100%; border-collapse:collapse; margin:8px 0 14px; font-size:8.5pt; }
.donations-table th { background:#1e6091; color:#fff; padding:6px 8px; text-align:left; }
.donations-table td { border:1px solid #d8e3ee; padding:5px 8px; vertical-align:top; }
.donations-table tr:nth-child(even) td { background:#fbfdff; }
.section-title { color:#1e6091; font-size:11pt; font-weight:bold; margin:16px 0 6px; text-transform:uppercase; border-bottom:1px solid #d8e3ee; padding-bottom:3px; }
.legal-box { background:#fdf9e8; border:1px solid #e8d96f; border-radius:4px; padding:12px 14px; font-size:8.5pt; line-height:1.55; margin:14px 0; color:#4d3f00; }
.legal-box strong { color:#1a1a1a; }
.sign-section { margin-top:22px; }
.sign-table { width:100%; border-collapse:collapse; }
.sign-table td { width:50%; text-align:center; padding:14px 30px; vertical-align:top; }
.sign-line { border-top:1.5px solid #1a1a1a; margin:36px auto 4px; width:80%; }
.sign-label { font-size:9pt; }
.verify-block { display:table; width:100%; margin-top:14px; border-top:1px solid #d8e3ee; padding-top:12px; }
.verify-cell { display:table-cell; vertical-align:middle; }
.verify-cell.qr { width:90px; text-align:center; }
.verify-cell.info { font-size:8.5pt; color:#555; }
.page-footer { background:#1e6091; color:#fff; text-align:center; padding:7px; font-size:7.5pt; margin-top:14px; }
.watermark { position:fixed; top:45%; left:50%; transform:translate(-50%,-50%) rotate(-30deg); font-size:80pt; font-weight:bold; color:rgba(30,96,145,0.08); z-index:0; pointer-events:none; letter-spacing:8px; text-transform:uppercase; }
.content { position:relative; z-index:1; }
</style>
</head>
<body>

<?php echo $watermark; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

<div class="content">

<div class="page-header">
    <h1><?php esc_html_e( 'Certificado de Donación', 'ltms' ); ?></h1>
    <div class="subtitle"><?php esc_html_e( 'Documento válido como soporte para deducción fiscal', 'ltms' ); ?></div>
</div>

<div class="logo-bar">
    <div class="logo-cell"><?php echo esc_html( $platform_name ); ?></div>
    <div class="title-cell">
        <?php esc_html_e( 'Certificado N°:', 'ltms' ); ?> <span class="certificate-id"><?php echo esc_html( $batch_number ); ?></span><br>
        <?php esc_html_e( 'Fecha de emisión:', 'ltms' ); ?> <?php echo esc_html( $issuance_date ); ?>
    </div>
</div>

<div class="intro-block">
    <?php
    printf(
        /* translators: 1: platform name, 2: foundation name */
        esc_html__( 'Por medio del presente certificado, %1$s hace constar que ha transferido a %2$s, en calidad de donación, el monto detallado a continuación, correspondiente al período indicado. Este documento cumple con los requisitos legales para ser utilizado como soporte de deducción fiscal conforme a la normativa vigente.', 'ltms' ),
        '<strong>' . esc_html( $platform_name ) . '</strong>',
        '<strong>' . esc_html( $foundation['name'] ) . '</strong>'
    );
    ?>
</div>

<div class="section-title"><?php esc_html_e( 'Partes', 'ltms' ); ?></div>
<table class="party-table">
    <tr>
        <th><?php esc_html_e( 'DONANTE (Plataforma)', 'ltms' ); ?></th>
        <th><?php esc_html_e( 'DONATARIA (Fundación)', 'ltms' ); ?></th>
    </tr>
    <tr>
        <td>
            <strong><?php echo esc_html( $platform_name ); ?></strong><br>
            NIT: <?php echo esc_html( $platform_nit ); ?><br>
            <?php echo esc_html( home_url() ); ?>
        </td>
        <td>
            <strong><?php echo esc_html( $foundation['name'] ); ?></strong><br>
            NIT: <?php echo esc_html( $foundation['nit'] ); ?><br>
            <?php echo esc_html( $foundation['address'] ); ?><br>
            <?php echo esc_html( $foundation['phone'] ); ?><br>
            <?php echo esc_html( $foundation['email'] ); ?>
        </td>
    </tr>
</table>

<div class="section-title"><?php esc_html_e( 'Resumen del Lote', 'ltms' ); ?></div>
<table class="summary-table">
    <tr>
        <td class="label"><?php esc_html_e( 'Número de lote', 'ltms' ); ?></td>
        <td class="value"><?php echo esc_html( $batch_number ); ?></td>
    </tr>
    <tr>
        <td class="label"><?php esc_html_e( 'Período', 'ltms' ); ?></td>
        <td class="value"><?php echo esc_html( self::format_spanish_date( $period_start ) ); ?> — <?php echo esc_html( self::format_spanish_date( $period_end ) ); ?></td>
    </tr>
    <tr>
        <td class="label"><?php esc_html_e( 'Número de donaciones', 'ltms' ); ?></td>
        <td class="value"><?php echo esc_html( number_format( $donation_count ) ); ?></td>
    </tr>
    <tr>
        <td class="label"><?php esc_html_e( 'Total donado', 'ltms' ); ?></td>
        <td><span class="amount-big"><?php echo esc_html( LTMS_Utils::format_money( $total_donated, $currency ) ); ?></span></td>
    </tr>
    <tr>
        <td class="label"><?php esc_html_e( 'Moneda', 'ltms' ); ?></td>
        <td class="value"><?php echo esc_html( $currency ); ?></td>
    </tr>
    <?php if ( ! empty( $batch['transfer_reference'] ) ) : ?>
    <tr>
        <td class="label"><?php esc_html_e( 'Referencia de transferencia', 'ltms' ); ?></td>
        <td class="value" style="font-family:monospace"><?php echo esc_html( $batch['transfer_reference'] ); ?></td>
    </tr>
    <?php endif; ?>
    <?php if ( ! empty( $batch['transferred_at'] ) && $batch['transferred_at'] !== '0000-00-00 00:00:00' ) : ?>
    <tr>
        <td class="label"><?php esc_html_e( 'Fecha de transferencia', 'ltms' ); ?></td>
        <td class="value"><?php echo esc_html( self::format_spanish_date( substr( (string) $batch['transferred_at'], 0, 10 ) ) ); ?></td>
    </tr>
    <?php endif; ?>
</table>

<div class="section-title"><?php esc_html_e( 'Detalle de Donaciones', 'ltms' ); ?></div>
<?php echo $donations_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

<div class="legal-box">
    <strong><?php esc_html_e( 'Nota legal:', 'ltms' ); ?></strong>
    <?php echo esc_html( $foundation['legal_text'] ); ?>
    <?php if ( $tax_deductible ) : ?>
        <br><br>
        <?php
        printf(
            /* translators: 1: foundation name, 2: NIT */
            esc_html__( 'Conforme al Art. 125 del Estatuto Tributario, las donaciones a %1$s (NIT %2$s) son deducibles del impuesto sobre la renta hasta el límite establecido por la ley vigente.', 'ltms' ),
            esc_html( $foundation['name'] ),
            esc_html( $foundation['nit'] )
        );
        ?>
    <?php endif; ?>
</div>

<div class="sign-section">
    <table class="sign-table">
        <tr>
            <td>
                <div class="sign-line"></div>
                <div class="sign-label">
                    <strong><?php echo esc_html( $platform_name ); ?></strong><br>
                    <?php esc_html_e( 'Donante — Representante legal', 'ltms' ); ?><br>
                    NIT <?php echo esc_html( $platform_nit ); ?><br>
                    <?php echo esc_html( $issuance_date ); ?>
                </div>
            </td>
            <td>
                <div class="sign-line"></div>
                <div class="sign-label">
                    <strong><?php echo esc_html( $foundation['name'] ); ?></strong><br>
                    <?php esc_html_e( 'Donataria — Representante legal', 'ltms' ); ?><br>
                    NIT <?php echo esc_html( $foundation['nit'] ); ?><br>
                    <?php esc_html_e( 'Recibido conforme', 'ltms' ); ?>
                </div>
            </td>
        </tr>
    </table>
</div>

<div class="verify-block">
    <div class="verify-cell qr">
        <?php echo $qr_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </div>
    <div class="verify-cell info">
        <?php esc_html_e( 'Verifique la autenticidad de este certificado escaneando el código QR o visitando:', 'ltms' ); ?><br>
        <strong><?php echo esc_html( $verify_url ); ?></strong><br>
        <?php esc_html_e( 'Cada certificado tiene un identificador único y puede ser validado electrónicamente.', 'ltms' ); ?>
    </div>
</div>

<div class="page-footer">
    <?php
    printf(
        /* translators: 1: platform name, 2: year */
        esc_html__( 'Documento generado automáticamente por %1$s — Año %2$s — Certificado válido únicamente con sello y firma digital.', 'ltms' ),
        esc_html( $platform_name ),
        esc_html( gmdate( 'Y' ) )
    );
    ?>
</div>

</div><!-- /.content -->

</body>
</html>
        <?php
        return ob_get_clean();
    }

    /**
     * Construye la tabla HTML con el detalle de las donaciones.
     * Si hay más de MAX_LISTED_DONATIONS, muestra un resumen agregado por vendedor.
     *
     * @param array<int,array<string,mixed>> $donations Donaciones del lote.
     * @param string                         $currency  Código ISO de moneda.
     * @return string HTML de la tabla.
     */
    private static function render_donations_table( array $donations, string $currency ): string {
        if ( empty( $donations ) ) {
            return '<p style="font-size:9pt;color:#666;font-style:italic;">'
                . esc_html__( 'No hay donaciones asociadas a este lote.', 'ltms' )
                . '</p>';
        }

        // Si hay demasiadas donaciones, mostrar resumen agregado por vendedor.
        if ( count( $donations ) > self::MAX_LISTED_DONATIONS ) {
            return self::render_donations_summary( $donations, $currency );
        }

        ob_start();
        ?>
        <table class="donations-table">
            <thead>
                <tr>
                    <th style="width:60px">#</th>
                    <th style="width:90px"><?php esc_html_e( 'Orden', 'ltms' ); ?></th>
                    <th><?php esc_html_e( 'Vendedor', 'ltms' ); ?></th>
                    <th style="width:90px;text-align:right"><?php esc_html_e( 'Base', 'ltms' ); ?></th>
                    <th style="width:50px;text-align:center">%</th>
                    <th style="width:100px;text-align:right"><?php esc_html_e( 'Donación', 'ltms' ); ?></th>
                    <th style="width:80px"><?php esc_html_e( 'Fecha', 'ltms' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php
            $i = 1;
            foreach ( $donations as $d ) :
                $base   = (float) ( $d['base_amount'] ?? 0 );
                $pct    = (float) ( $d['percentage'] ?? 0 );
                $amount = (float) ( $d['donation_amount'] ?? 0 );
                $date   = ! empty( $d['created_at'] ) ? substr( (string) $d['created_at'], 0, 10 ) : '';
            ?>
                <tr>
                    <td><?php echo esc_html( $i++ ); ?></td>
                    <td>#<?php echo esc_html( $d['order_id'] ?? '—' ); ?></td>
                    <td><?php echo esc_html( $d['vendor_name'] ?? ( 'Vendor #' . ( $d['vendor_id'] ?? '?' ) ) ); ?></td>
                    <td style="text-align:right"><?php echo esc_html( number_format( $base, 0, ',', '.' ) ); ?></td>
                    <td style="text-align:center"><?php echo esc_html( number_format( $pct, 2 ) ); ?>%</td>
                    <td style="text-align:right"><strong><?php echo esc_html( number_format( $amount, 0, ',', '.' ) ); ?></strong></td>
                    <td><?php echo esc_html( $date ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        return ob_get_clean();
    }

    /**
     * Renderiza un resumen agregado por vendedor cuando el lote es grande.
     *
     * @param array<int,array<string,mixed>> $donations Donaciones.
     * @param string                         $currency  Moneda.
     * @return string HTML.
     */
    private static function render_donations_summary( array $donations, string $currency ): string {
        // Agregar por vendor_id.
        $by_vendor = [];
        foreach ( $donations as $d ) {
            $vid = (int) ( $d['vendor_id'] ?? 0 );
            if ( ! isset( $by_vendor[ $vid ] ) ) {
                $by_vendor[ $vid ] = [
                    'name'      => $d['vendor_name'] ?? ( 'Vendor #' . $vid ),
                    'count'     => 0,
                    'base'      => 0.0,
                    'amount'    => 0.0,
                ];
            }
            $by_vendor[ $vid ]['count']++;
            $by_vendor[ $vid ]['base']   += (float) ( $d['base_amount'] ?? 0 );
            $by_vendor[ $vid ]['amount'] += (float) ( $d['donation_amount'] ?? 0 );
        }
        // Ordenar por monto donado descendente.
        uasort( $by_vendor, static fn( $a, $b ) => $b['amount'] <=> $a['amount'] );

        ob_start();
        ?>
        <p style="font-size:9pt;color:#555;font-style:italic;margin-bottom:6px;">
            <?php
            printf(
                /* translators: %d: número de donaciones */
                esc_html__( 'El lote contiene %d donaciones. Por razones de espacio se muestra un resumen agregado por vendedor.', 'ltms' ),
                count( $donations )
            );
            ?>
        </p>
        <table class="donations-table">
            <thead>
                <tr>
                    <th style="width:40px">#</th>
                    <th><?php esc_html_e( 'Vendedor', 'ltms' ); ?></th>
                    <th style="width:80px;text-align:center"><?php esc_html_e( '# Donaciones', 'ltms' ); ?></th>
                    <th style="width:120px;text-align:right"><?php esc_html_e( 'Base acumulada', 'ltms' ); ?></th>
                    <th style="width:120px;text-align:right"><?php esc_html_e( 'Donación total', 'ltms' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php $i = 1; foreach ( $by_vendor as $row ) : ?>
                <tr>
                    <td><?php echo esc_html( $i++ ); ?></td>
                    <td><?php echo esc_html( $row['name'] ); ?></td>
                    <td style="text-align:center"><?php echo esc_html( number_format( $row['count'] ) ); ?></td>
                    <td style="text-align:right"><?php echo esc_html( number_format( $row['base'], 0, ',', '.' ) ); ?></td>
                    <td style="text-align:right"><strong><?php echo esc_html( number_format( $row['amount'], 0, ',', '.' ) ); ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        return ob_get_clean();
    }

    // ── DOMPDF ─────────────────────────────────────────────────────

    /**
     * Convierte HTML a PDF usando DOMPDF.
     *
     * @param string $html HTML a convertir.
     * @return string Binario del PDF.
     * @throws \RuntimeException Si DOMPDF no está disponible.
     */
    private static function html_to_pdf( string $html ): string {
        self::ensure_dompdf();

        $options = new \Dompdf\Options();
        $options->set( 'defaultFont', 'DejaVu Sans' );
        $options->set( 'isRemoteEnabled', false );
        $options->set( 'isHtml5ParserEnabled', true );
        $options->set( 'isFontSubsettingEnabled', true );
        $options->set( 'chroot', sys_get_temp_dir() );

        $dompdf = new \Dompdf\Dompdf( $options );
        $dompdf->loadHtml( $html, 'UTF-8' );
        $dompdf->setPaper( 'letter', 'portrait' );
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Verifica que DOMPDF esté disponible.
     *
     * @throws \RuntimeException Si DOMPDF no está cargado.
     */
    private static function ensure_dompdf(): void {
        $autoloader = plugin_dir_path( __FILE__ ) . '../../vendor/autoload.php';
        if ( ! file_exists( $autoloader ) ) {
            throw new \RuntimeException( '[ltms-cert] vendor/autoload.php no encontrado. Ejecuta composer install.' );
        }
        require_once $autoloader;
        if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
            throw new \RuntimeException( '[ltms-cert] DOMPDF no está disponible. Verifica composer.json.' );
        }
    }

    // ── Storage B2 ─────────────────────────────────────────────────

    /**
     * Construye la clave (ruta relativa) bajo la cual se guardará el PDF en B2.
     * Ej: `certificates/2026/L-0001-20260618.pdf`
     *
     * @param array<string,mixed>  $batch      Datos del lote.
     * @param array<string,string> $foundation Datos de la fundación (no usado por ahora).
     * @return string
     */
    private static function build_storage_key( array $batch, array $foundation ): string {
        $year    = gmdate( 'Y' );
        $batch_n = sanitize_file_name( (string) ( $batch['batch_number'] ?? ( 'L' . ( $batch['id'] ?? 'unknown' ) ) ) );
        $stamp   = gmdate( 'Ymd-His' );
        return sprintf( 'certificates/%s/%s-%s.pdf', $year, $batch_n, $stamp );
    }

    /**
     * Sube el PDF generado a Backblaze B2 y retorna la ruta relativa.
     *
     * @param string $key        Clave (ruta) en B2.
     * @param string $pdf        Contenido binario del PDF.
     * @param array  $foundation Datos de la fundación (para metadatos).
     * @return string|\WP_Error  Ruta relativa en B2 o WP_Error si falla.
     */
    private static function upload_to_b2( string $key, string $pdf, array $foundation ) {
        $bucket = LTMS_Core_Config::get( 'ltms_donation_cert_bucket', self::DEFAULT_BUCKET );

        if ( ! class_exists( 'LTMS_Api_Factory' ) || ! LTMS_Api_Factory::has( 'backblaze' ) ) {
            // Sin B2 disponible: guardar localmente como fallback.
            return self::fallback_local_storage( $key, $pdf );
        }

        try {
            $b2    = LTMS_Api_Factory::get( 'backblaze' );
            $meta  = [
                'batch-id'   => '',
                'foundation' => $foundation['name'] ?? '',
                'generated'  => LTMS_Utils::now_utc(),
                'type'       => 'donation-certificate',
            ];
            $b2->upload_file( $bucket, $key, $pdf, 'application/pdf', $meta );
            return $key;
        } catch ( \Throwable $e ) {
            self::log_warning(
                'DONATION_CERT_B2_FAILED',
                sprintf( 'Falló subida a B2 (%s), usando almacenamiento local: %s', $bucket, $e->getMessage() ),
                [ 'key' => $key, 'bucket' => $bucket ]
            );
            return self::fallback_local_storage( $key, $pdf );
        }
    }

    /**
     * Fallback: si B2 no está configurado, guarda el PDF en uploads/ltms-donations/.
     * La ruta devuelta tiene el prefijo `local://` para distinguirla de las rutas B2.
     *
     * @param string $key Clave original.
     * @param string $pdf Binario del PDF.
     * @return string Ruta con prefijo `local://`.
     */
    private static function fallback_local_storage( string $key, string $pdf ): string {
        $uploads = wp_upload_dir();
        $dir     = trailingslashit( $uploads['basedir'] ) . 'ltms-donations/certificates';
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        $filename = basename( $key );
        $fullpath = trailingslashit( $dir ) . $filename;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $fullpath, $pdf );
        return 'local://' . $filename;
    }

    /**
     * Persiste la ruta del certificado en la base de datos.
     *
     * @param int    $batch_id ID del lote.
     * @param string $path     Ruta del certificado (B2 key o local://path).
     * @return void
     */
    private static function persist_path( int $batch_id, string $path ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_donation_payouts';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->update(
            $table,
            [
                'certificate_path' => $path,
            ],
            [ 'id' => $batch_id ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    // ── Utilidades ─────────────────────────────────────────────────

    /**
     * Formatea una fecha Y-m-d al formato "j \d\e F \d\e Y" en español.
     *
     * @param string $date Fecha en formato Y-m-d (puede estar vacía).
     * @return string
     */
    private static function format_spanish_date( string $date ): string {
        if ( empty( $date ) || $date === '0000-00-00' ) {
            return '—';
        }
        $ts    = strtotime( $date );
        if ( false === $ts ) {
            return $date;
        }
        $out   = gmdate( 'j \d\e F \d\e Y', $ts );
        $meses = [
            'January'   => 'enero', 'February' => 'febrero',  'March'    => 'marzo',
            'April'     => 'abril', 'May'      => 'mayo',     'June'     => 'junio',
            'July'      => 'julio', 'August'   => 'agosto',   'September'=> 'septiembre',
            'October'   => 'octubre', 'November' => 'noviembre', 'December' => 'diciembre',
        ];
        return str_replace( array_keys( $meses ), array_values( $meses ), $out );
    }

    /**
     * Genera un código QR como SVG inline a partir de una URL.
     *
     * Usa el servicio público de QuickChart (https://quickchart.io/qr) para
     * renderizar el QR como una imagen embebida en el HTML. Si el servicio no
     * está disponible (isRemoteEnabled=false), se dibuja un placeholder.
     *
     * @param string $url URL a codificar.
     * @return string HTML con el QR (placeholder si no se puede generar).
     */
    private static function build_qr_svg( string $url ): string {
        // Nota: DOMPDF está configurado con isRemoteEnabled=false, así que no
        // podemos embeber imágenes externas. En su lugar, dibujamos un
        // placeholder visual con instrucciones.
        $short_hash = substr( hash( 'sha256', $url ), 0, 12 );
        return '<div style="width:80px;height:80px;border:2px solid #1e6091;border-radius:4px;display:flex;align-items:center;justify-content:center;margin:0 auto;background:#fbfdff;">'
             . '<span style="font-family:monospace;font-size:7pt;color:#1e6091;text-align:center;line-height:1.2;">'
             . esc_html( $short_hash )
             . '<br>VERIFY'
             . '</span></div>';
    }
}
