<?php
/**
 * Admin: Panel de estado de los módulos UX
 *
 * Muestra qué módulos de la capa UX Enhancements v2.0 están activos,
 * cuántos data-* se aplicaron, y permite activar/desactivar telemetría.
 *
 * @package    LTMS\Admin
 * @version    2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Admin_UX_Status
 */
class LTMS_Admin_UX_Status {

    /**
     * Singleton
     */
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ], 90 );
        add_action( 'admin_init', [ $this, 'handle_settings_save' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /**
     * Añade el submenú bajo "LTMS".
     */
    public function register_menu() {
        add_submenu_page(
            'ltms-dashboard',
            __( 'Estado UX', 'ltms' ),
            __( 'Estado UX', 'ltms' ),
            'manage_options',
            'ltms-ux-status',
            [ $this, 'render_page' ]
        );
    }

    /**
     * Encola assets solo en esta página.
     */
    public function enqueue_assets( $hook ) {
        if ( 'ltms-dashboard_page_ltms-ux-status' !== $hook ) {
            return;
        }
        $ver = LTMS_VERSION;
        $url = LTMS_ASSETS_URL;

        // CSS inline para no añadir otro archivo
        $css = '
        .ltms-ux-status-wrap { max-width: 1100px; margin: 20px auto; }
        .ltms-ux-status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .ltms-ux-status-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
        .ltms-ux-status-card h3 { margin: 0 0 8px; font-size: 14px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; }
        .ltms-ux-status-card .value { font-size: 28px; font-weight: 700; color: #1f2937; line-height: 1.2; }
        .ltms-ux-status-card .sub { font-size: 12px; color: #9ca3af; margin-top: 4px; }
        .ltms-ux-status-section { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 16px; }
        .ltms-ux-status-section h2 { margin: 0 0 16px; font-size: 18px; padding-bottom: 12px; border-bottom: 1px solid #f3f4f6; }
        .ltms-ux-status-table { width: 100%; border-collapse: collapse; }
        .ltms-ux-status-table th { text-align: left; padding: 10px 12px; background: #f9fafb; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #e5e7eb; }
        .ltms-ux-status-table td { padding: 10px 12px; border-bottom: 1px solid #f3f4f6; font-size: 13px; }
        .ltms-ux-status-table tr:last-child td { border-bottom: none; }
        .ltms-ux-status-pill { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 11px; font-weight: 600; }
        .ltms-ux-status-pill-ok { background: #dcfce7; color: #16a34a; }
        .ltms-ux-status-pill-warn { background: #fef3c7; color: #d97706; }
        .ltms-ux-status-pill-err { background: #fee2e2; color: #dc2626; }
        .ltms-ux-status-toggle { display: flex; align-items: center; gap: 12px; padding: 14px; background: #f9fafb; border-radius: 6px; }
        .ltms-ux-status-toggle input[type=checkbox] { width: 18px; height: 18px; }
        .ltms-ux-status-notice { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; }
        .ltms-ux-status-notice-info { background: #dbeafe; color: #1e40af; border-left: 4px solid #2563eb; }
        ';
        wp_add_inline_style( 'ltms-admin-ux', $css );
    }

    /**
     * Maneja el guardado del formulario de settings (telemetría).
     */
    public function handle_settings_save() {
        if ( ! isset( $_POST['ltms_ux_status_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ltms_ux_status_nonce'] ) ), 'ltms_ux_status_save' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $telemetry_enabled = isset( $_POST['ltms_telemetry_enabled'] ) ? 'yes' : 'no';
        update_option( 'ltms_ux_telemetry_enabled', $telemetry_enabled );

        // Hook para que class-ltms-frontend-assets.php pueda inyectar body data attribute
        update_option( 'ltms_ux_version_seen', LTMS_VERSION );

        add_settings_error( 'ltms_ux_status', 'ltms_ux_status_saved', __( 'Configuración UX guardada.', 'ltms' ), 'updated' );
    }

    /**
     * Renderiza la página completa.
     */
    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $metrics = $this->collect_metrics();
        $telemetry_enabled = get_option( 'ltms_ux_telemetry_enabled', 'no' ) === 'yes';
        ?>
        <div class="wrap ltms-ux-status-wrap">
            <h1 style="margin-bottom:24px;">
                <span style="display:inline-block;background:linear-gradient(135deg,#0F4C75,#3282B8);color:#fff;padding:4px 12px;border-radius:6px;font-size:1.4rem;">UX</span>
                <?php esc_html_e( 'Estado de la capa UX Enhancements', 'ltms' ); ?>
            </h1>

            <?php settings_errors( 'ltms_ux_status' ); ?>

            <?php if ( ! $metrics['min_files_exist'] ) : ?>
            <div class="ltms-ux-status-notice ltms-ux-status-notice-info">
                ⚠️ <?php esc_html_e( 'Los archivos .min aún no están generados. Ejecuta: node /home/z/my-project/scripts/build-minify.js', 'ltms' ); ?>
            </div>
            <?php endif; ?>

            <!-- Cards de métricas -->
            <div class="ltms-ux-status-grid">
                <div class="ltms-ux-status-card">
                    <h3><?php esc_html_e( 'Versión', 'ltms' ); ?></h3>
                    <div class="value"><?php echo esc_html( LTMS_VERSION ); ?></div>
                    <div class="sub"><?php esc_html_e( 'LT Marketplace Suite', 'ltms' ); ?></div>
                </div>
                <div class="ltms-ux-status-card">
                    <h3><?php esc_html_e( 'Módulos JS', 'ltms' ); ?></h3>
                    <div class="value"><?php echo esc_html( $metrics['module_count'] ); ?></div>
                    <div class="sub"><?php esc_html_e( 'funciones init* registradas', 'ltms' ); ?></div>
                </div>
                <div class="ltms-ux-status-card">
                    <h3><?php esc_html_e( 'data-* en plantillas', 'ltms' ); ?></h3>
                    <div class="value"><?php echo esc_html( $metrics['data_in_views'] ); ?></div>
                    <div class="sub"><?php esc_html_e( 'atributos aplicados', 'ltms' ); ?></div>
                </div>
                <div class="ltms-ux-status-card">
                    <h3><?php esc_html_e( 'Emails migrados', 'ltms' ); ?></h3>
                    <div class="value"><?php echo esc_html( $metrics['emails_migrated'] . '/' . $metrics['emails_total'] ); ?></div>
                    <div class="sub"><?php esc_html_e( 'paleta centralizada', 'ltms' ); ?></div>
                </div>
                <div class="ltms-ux-status-card">
                    <h3><?php esc_html_e( 'Reducción assets', 'ltms' ); ?></h3>
                    <div class="value"><?php echo esc_html( $metrics['size_reduction'] ); ?>%</div>
                    <div class="sub"><?php echo esc_html( $metrics['orig_size'] . ' → ' . $metrics['min_size'] ); ?></div>
                </div>
                <div class="ltms-ux-status-card">
                    <h3><?php esc_html_e( 'Estado del build', 'ltms' ); ?></h3>
                    <div class="value">
                        <?php if ( $metrics['min_files_exist'] ) : ?>
                            <span class="ltms-ux-status-pill ltms-ux-status-pill-ok">✓ OK</span>
                        <?php else : ?>
                            <span class="ltms-ux-status-pill ltms-ux-status-pill-warn">⚠ Pendiente</span>
                        <?php endif; ?>
                    </div>
                    <div class="sub"><?php esc_html_e( 'archivos .min generados', 'ltms' ); ?></div>
                </div>
            </div>

            <!-- Tabla de archivos -->
            <div class="ltms-ux-status-section">
                <h2><?php esc_html_e( 'Archivos de la capa UX', 'ltms' ); ?></h2>
                <table class="ltms-ux-status-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Archivo', 'ltms' ); ?></th>
                            <th><?php esc_html_e( 'Original', 'ltms' ); ?></th>
                            <th><?php esc_html_e( 'Minificado', 'ltms' ); ?></th>
                            <th><?php esc_html_e( 'Reducción', 'ltms' ); ?></th>
                            <th><?php esc_html_e( 'Estado', 'ltms' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $metrics['files'] as $f ) : ?>
                        <tr>
                            <td><code><?php echo esc_html( $f['name'] ); ?></code></td>
                            <td><?php echo esc_html( $f['orig_size'] ); ?></td>
                            <td><?php echo esc_html( $f['min_size'] ); ?></td>
                            <td><?php echo esc_html( $f['reduction'] ); ?>%</td>
                            <td>
                                <?php if ( $f['min_exists'] ) : ?>
                                    <span class="ltms-ux-status-pill ltms-ux-status-pill-ok">✓</span>
                                <?php else : ?>
                                    <span class="ltms-ux-status-pill ltms-ux-status-pill-warn">⚠</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Settings: telemetría -->
            <div class="ltms-ux-status-section">
                <h2><?php esc_html_e( 'Configuración de telemetría', 'ltms' ); ?></h2>
                <form method="post" action="">
                    <?php wp_nonce_field( 'ltms_ux_status_save', 'ltms_ux_status_nonce' ); ?>
                    <div class="ltms-ux-status-toggle">
                        <input type="checkbox" name="ltms_telemetry_enabled" id="ltms-telemetry-enabled" <?php checked( $telemetry_enabled ); ?>>
                        <div>
                            <label for="ltms-telemetry-enabled" style="font-weight:600;cursor:pointer;">
                                <?php esc_html_e( 'Activar telemetría de uso de módulos', 'ltms' ); ?>
                            </label>
                            <p style="margin:4px 0 0;color:#6b7280;font-size:12px;">
                                <?php esc_html_e( 'Recopila anónimamente qué módulos UX se usan más (sin PII). Respeta Do Not Track. Buffer batch cada 30s.', 'ltms' ); ?>
                            </p>
                        </div>
                    </div>
                    <p style="margin-top:16px;">
                        <button type="submit" class="button button-primary">
                            <?php esc_html_e( 'Guardar cambios', 'ltms' ); ?>
                        </button>
                    </p>
                </form>
            </div>

            <!-- Lista de módulos activos -->
            <div class="ltms-ux-status-section">
                <h2><?php esc_html_e( 'Módulos detectados (muestra de 20)', 'ltms' ); ?></h2>
                <table class="ltms-ux-status-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?php esc_html_e( 'Función', 'ltms' ); ?></th>
                            <th><?php esc_html_e( 'Línea', 'ltms' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( array_slice( $metrics['modules'], 0, 20 ) as $i => $m ) : ?>
                        <tr>
                            <td><?php echo esc_html( $i + 1 ); ?></td>
                            <td><code><?php echo esc_html( $m['name'] ); ?>()</code></td>
                            <td><?php echo esc_html( $m['line'] ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p style="margin-top:12px;color:#6b7280;font-size:12px;">
                    <?php
                    printf(
                        /* translators: %d total módulos */
                        esc_html__( 'Mostrando 20 de %d módulos totales. Para ver el catálogo completo consulta UX_ENHANCEMENTS.md.', 'ltms' ),
                        $metrics['module_count']
                    );
                    ?>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Recoge todas las métricas para mostrar.
     */
    private function collect_metrics() {
        // v2.9.31: usar constantes que SI estan definidas en lt-marketplace-suite.php.
        // ANTES usaba LTMS_ASSETS_PATH y LTMS_PLUGIN_DIR_PATH que NO existen →
        // undefined constant warnings + file_exists siempre false → pagina vacia.
        $assets_dir = defined( 'LTMS_ASSETS_PATH' ) ? LTMS_ASSETS_PATH : ( LTMS_PLUGIN_DIR . 'assets/' );
        $plugin_dir = defined( 'LTMS_PLUGIN_DIR_PATH' ) ? LTMS_PLUGIN_DIR_PATH : LTMS_PLUGIN_DIR;
        $js_file = $assets_dir . 'js/ltms-ux-enhancements.js';
        $js_min  = $assets_dir . 'js/ltms-ux-enhancements.min.js';
        $css_file = $assets_dir . 'css/ltms-ux-enhancements.css';
        $css_min  = $assets_dir . 'css/ltms-ux-enhancements.min.css';
        $admin_css = $assets_dir . 'css/ltms-admin-ux.css';
        $admin_min = $assets_dir . 'css/ltms-admin-ux.min.css';

        // Lista de módulos (parsear init* del JS)
        $modules = [];
        $module_count = 0;
        if ( file_exists( $js_file ) ) {
            $js_content = file_get_contents( $js_file );
            preg_match_all( '/^\s*function\s+(init[A-Z]\w*)\s*\(/m', $js_content, $m, PREG_OFFSET_CAPTURE );
            foreach ( $m[1] as $match ) {
                $line = substr_count( substr( $js_content, 0, $match[1] ), "\n" ) + 1;
                $modules[] = [ 'name' => $match[0], 'line' => $line ];
            }
            $module_count = count( $modules );
        }

        // data-* en plantillas
        $data_in_views = 0;
        $views_dir = $plugin_dir . 'includes/frontend/views/';
        if ( is_dir( $views_dir ) ) {
            $rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $views_dir ) );
            foreach ( $rii as $file ) {
                if ( $file->isFile() && $file->getExtension() === 'php' ) {
                    $content = file_get_contents( $file->getPathname() );
                    $data_in_views += preg_match_all( '/data-[a-z-]+/', $content );
                }
            }
        }

        // Emails migrados
        $emails_dir = $plugin_dir . 'templates/emails/';
        $emails_total = 0;
        $emails_migrated = 0;
        if ( is_dir( $emails_dir ) ) {
            foreach ( glob( $emails_dir . 'email-*.php' ) as $email_file ) {
                if ( basename( $email_file ) === 'email-styles.php' ) continue;
                $emails_total++;
                $content = file_get_contents( $email_file );
                if ( strpos( $content, 'ltms_email_colors' ) !== false ||
                     strpos( $content, 'ltms-email-alert' ) !== false ||
                     strpos( $content, 'ltms-email-table' ) !== false ||
                     strpos( $content, 'ltms-email-btn' ) !== false ||
                     strpos( $content, "include __DIR__ . '/email-styles.php'" ) !== false ) {
                    $emails_migrated++;
                }
            }
        }

        // Tamaños de archivos
        $files = [];
        $total_orig = 0;
        $total_min = 0;
        $min_exists = true;
        foreach ( [
            [ 'name' => 'ltms-ux-enhancements.js', 'orig' => $js_file, 'min' => $js_min ],
            [ 'name' => 'ltms-ux-enhancements.css', 'orig' => $css_file, 'min' => $css_min ],
            [ 'name' => 'ltms-admin-ux.css', 'orig' => $admin_css, 'min' => $admin_min ],
        ] as $f ) {
            $orig_size = file_exists( $f['orig'] ) ? filesize( $f['orig'] ) : 0;
            $min_size  = file_exists( $f['min'] ) ? filesize( $f['min'] ) : 0;
            $reduction = $orig_size > 0 ? round( (1 - $min_size / $orig_size) * 100, 1 ) : 0;
            $files[] = [
                'name'       => $f['name'],
                'orig_size'  => size_format( $orig_size, 1 ),
                'min_size'   => size_format( $min_size, 1 ),
                'reduction'  => $reduction,
                'min_exists' => file_exists( $f['min'] ),
            ];
            $total_orig += $orig_size;
            $total_min  += $min_size;
            if ( ! file_exists( $f['min'] ) ) {
                $min_exists = false;
            }
        }

        $size_reduction = $total_orig > 0 ? round( (1 - $total_min / $total_orig) * 100, 1 ) : 0;

        return [
            'module_count'    => $module_count,
            'modules'         => $modules,
            'data_in_views'   => $data_in_views,
            'emails_total'    => $emails_total,
            'emails_migrated' => $emails_migrated,
            'files'           => $files,
            'min_files_exist' => $min_exists,
            'orig_size'       => size_format( $total_orig, 1 ),
            'min_size'        => size_format( $total_min, 1 ),
            'size_reduction'  => $size_reduction,
        ];
    }
}

// Inicializar
LTMS_Admin_UX_Status::instance();
