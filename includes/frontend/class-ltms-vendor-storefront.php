<?php
/**
 * LTMS Vendor Storefront — Vitrina pública del vendedor
 *
 * Sirve /vendedor/{slug} con perfil + grid de productos.
 *
 * v2.8.1: la ruta cambió de /tienda/{slug}/ a /vendedor/{slug}/ porque
 * "tienda" ya es el slug base de la tienda de WooCommerce (configuración
 * en español) — esa regla de WooCommerce capturaba la URL antes que la
 * nuestra, sin importar la prioridad 'top' del add_rewrite_rule().
 *
 * También se hizo robusto el lookup de vendedor: muchos vendedores legacy
 * tienen user_login con caracteres no aptos para URL (ej. "marco@dominio.com",
 * "Nombre Con Espacios"), así que ahora:
 *   1. Se busca primero por meta ltms_store_slug (la fuente de verdad).
 *   2. Si no existe, se intenta login exacto (compatibilidad histórica).
 *   3. Si tampoco, se compara el slug sanitizado del nombre de tienda/login
 *      contra vendedores sin slug guardado, y se persiste el match — así
 *      cada vendedor termina con un slug estable después de su primera visita.
 *
 * deploy/ltms-backfill-store-slugs.php hace este mismo trabajo de una sola
 * vez para todos los vendedores existentes, sin depender de que alguien
 * visite su URL primero.
 *
 * @package LTMS
 * @since   2.8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LTMS_Vendor_Storefront {

    const QUERY_VAR = 'ltms_vendor_slug';

    public static function init(): void {
        static $initialized = false;
        if ( $initialized ) return;
        $initialized = true;

        // IMPORTANTE: add_rewrite_rule() usa $wp_rewrite internamente, que
        // WordPress crea recién en el hook 'init' — no antes. El kernel
        // ejecuta este init() en un punto previo (boot_frontend()), así que
        // llamarlo aquí directo causa "Call to a member function add_rule()
        // on null". Por eso se difiere a register_rewrite_rule(), igual
        // patrón que ya usa LTMS_Geo_Detector::register_city_rewrite_rules().
        add_action( 'init', [ __CLASS__, 'register_rewrite_rule' ] );
        add_filter( 'query_vars', static function( array $vars ): array {
            $vars[] = self::QUERY_VAR;
            return $vars;
        } );
        add_action( 'template_redirect', [ __CLASS__, 'maybe_render' ] );
        add_filter( 'document_title_parts', [ __CLASS__, 'filter_title' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );

        // SiteGround Optimizer combina todos los CSS del tema en un archivo externo
        // que se carga ANTES del nuestro, rompiendo la cascada. Se registran en
        // 'wp' (no en init) para que $wp_query ya exista y detect_request_slug()
        // pueda usar get_query_var() de forma segura.
        add_action( 'wp', static function(): void {
            if ( ! self::detect_request_slug() ) {
                return;
            }
            add_filter( 'sgo_css_combine_exclude_ids',    [ self::class, 'sg_exclude_all_ids' ] );
            add_filter( 'sgo_js_combine_exclude_ids',     [ self::class, 'sg_exclude_all_ids' ] );
            add_filter( 'sgo_css_minify_exclude_ids',     [ self::class, 'sg_exclude_all_ids' ] );
            add_filter( 'sgo_js_minify_exclude_ids',      [ self::class, 'sg_exclude_all_ids' ] );
            add_filter( 'sgo_html_minify_exclude',        [ self::class, 'sg_optimizer_exclude_url' ] );
            add_filter( 'sgo_critical_css_exclude_list',  [ self::class, 'sg_optimizer_exclude_url' ] );
            add_filter( 'sgo_css_combine',    '__return_false' );
            add_filter( 'sgo_js_combine',     '__return_false' );
            add_filter( 'sgo_css_minify',     '__return_false' );
            add_filter( 'sgo_js_minify',      '__return_false' );
            add_filter( 'sgo_html_minify',    '__return_false' );
        } );
        add_filter( 'woocommerce_add_to_cart_fragments', [ __CLASS__, 'cart_count_fragment' ] );
    }

    public static function register_rewrite_rule(): void {
        add_rewrite_rule( '^vendedor/([\w-]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
    }

    /**
     * Lee el slug del vendedor desde REQUEST_URI directamente si
     * get_query_var() no lo resuelve — inmune a caché de objeto, OPcache
     * o CDN de borde de SiteGround que desincronicen rewrite_rules.
     */
    private static function detect_request_slug(): ?string {
        $qv = get_query_var( self::QUERY_VAR );
        if ( $qv ) {
            return sanitize_title( (string) $qv );
        }
        $uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        $path = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
        if ( preg_match( '#^vendedor/([\w-]+)$#', $path, $m ) ) {
            return sanitize_title( $m[1] );
        }
        return null;
    }

    public static function maybe_render(): void {
        $slug = self::detect_request_slug();
        if ( ! $slug ) return;

        $vendor = self::get_vendor_by_slug( $slug );
        if ( ! $vendor ) {
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            nocache_headers();
            include get_404_template();
            exit;
        }

        // WordPress ya marcó esta respuesta como 404 dentro de WP::main()
        // (la query var ltms_vendor_slug no resuelve a ningún post real,
        // así que handle_404() corre y fija $wp_query->is_404 = true ANTES
        // de que este hook 'template_redirect' se ejecute). El código de
        // estado HTTP ya se decidió en send_headers(), que corre antes en
        // el ciclo — por eso el navegador recibe 404 aunque el HTML que
        // sigue sea el correcto. Hay que revertir ese estado explícitamente
        // cuando el vendedor sí existe.
        global $wp_query;
        $wp_query->is_404 = false;
        status_header( 200 );

        self::render( $vendor );
        exit;
    }

    /**
     * Resuelve un vendedor a partir del slug de la URL.
     *
     * Tres niveles de búsqueda, en orden de costo creciente — ver docblock
     * de la clase para el razonamiento completo.
     */
    private static function get_vendor_by_slug( string $raw_slug ): ?object {
        $slug = sanitize_title( $raw_slug );
        if ( '' === $slug ) return null;

        // Nivel 1 — fuente de verdad: meta ltms_store_slug.
        $users = get_users( [
            'meta_key'   => 'ltms_store_slug',
            'meta_value' => $slug,
            'number'     => 1,
            'role'       => 'ltms_vendor',
        ] );

        // Nivel 2 — compatibilidad histórica: login exacto (solo funciona
        // si el login ya era un slug válido, ej. "tiendaejemplo").
        if ( ! $users ) {
            $user = get_user_by( 'login', $slug );
            if ( $user && in_array( 'ltms_vendor', (array) $user->roles, true ) ) {
                $users = [ $user ];
            }
        }

        // Nivel 3 — vendedores legacy sin slug guardado: comparar el slug
        // sanitizado de su nombre de tienda o login. Acotado a 300 filas
        // para no degradar rendimiento; el backfill cubre el resto de una vez.
        if ( ! $users ) {
            $legacy = get_users( [ 'role' => 'ltms_vendor', 'number' => 300 ] );
            foreach ( $legacy as $v ) {
                if ( get_user_meta( $v->ID, 'ltms_store_slug', true ) ) continue;

                $store_name = get_user_meta( $v->ID, 'ltms_store_name', true ) ?: $v->display_name ?: $v->user_login;
                $candidates = [ sanitize_title( $store_name ), sanitize_title( $v->user_login ) ];

                if ( in_array( $slug, $candidates, true ) ) {
                    update_user_meta( $v->ID, 'ltms_store_slug', $slug ); // se estabiliza para la próxima visita
                    $users = [ $v ];
                    break;
                }
            }
        }

        if ( ! $users ) return null;

        $u  = $users[0];
        $id = $u->ID;

        return (object) [
            'id'          => $id,
            'name'        => get_user_meta( $id, 'ltms_store_name', true ) ?: $u->display_name,
            'description' => get_user_meta( $id, 'ltms_store_description', true ),
            'city'        => get_user_meta( $id, 'ltms_store_city', true ),
            'logo'        => get_user_meta( $id, 'ltms_store_logo', true ),
            'banner'      => get_user_meta( $id, 'ltms_store_banner', true ),
            'rnt'         => get_user_meta( $id, 'ltms_rnt_number', true ),
            'kyc_status'  => get_user_meta( $id, 'ltms_kyc_status', true ),
            'slug'        => $slug,
        ];
    }

    /**
     * Genera un slug único para un vendedor a partir del nombre de su tienda.
     * Usado tanto en el registro de vendedores nuevos como en el backfill
     * de vendedores existentes (deploy/ltms-backfill-store-slugs.php).
     */
    public static function generate_unique_slug( string $store_name, int $exclude_user_id = 0 ): string {
        $base = sanitize_title( $store_name );
        if ( '' === $base ) {
            $base = 'tienda-' . ( $exclude_user_id ?: wp_rand( 1000, 9999 ) );
        }

        $slug = $base;
        $i    = 2;
        while ( self::slug_taken( $slug, $exclude_user_id ) ) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }

    private static function slug_taken( string $slug, int $exclude_user_id ): bool {
        $args = [
            'meta_key'   => 'ltms_store_slug',
            'meta_value' => $slug,
            'number'     => 1,
            'fields'     => 'ID',
        ];
        if ( $exclude_user_id ) {
            $args['exclude'] = [ $exclude_user_id ];
        }
        return ! empty( get_users( $args ) );
    }

    public static function filter_title( array $parts ): array {
        $slug = self::detect_request_slug();
        if ( ! $slug ) return $parts;
        $vendor = self::get_vendor_by_slug( $slug );
        if ( $vendor ) {
            $parts['title'] = esc_html( $vendor->name ) . ' — Lo Tengo';
        }
        return $parts;
    }

    public static function enqueue_assets(): void {
        if ( ! self::detect_request_slug() ) return;

        wp_enqueue_style(
            'ltms-storefront',
            LTMS_ASSETS_URL . 'css/ltms-storefront.css',
            [],
            LTMS_VERSION
        );

        // Habilita los botones "Agregar al carrito" sin recargar la página.
        if ( function_exists( 'wc_enqueue_js' ) || class_exists( 'WC_Frontend_Scripts' ) ) {
            wp_enqueue_script( 'wc-add-to-cart' );
        }

        // Actualiza el contador del carrito de nuestro mini-header propio
        // (ver print_topbar()) sin recargar la página — independiente de
        // cualquier drawer/plugin de carrito del tema.
        if ( function_exists( 'WC' ) ) {
            wp_enqueue_script( 'wc-cart-fragments' );
        }

        wp_enqueue_script(
            'ltms-storefront',
            LTMS_ASSETS_URL . 'js/ltms-storefront.js',
            [ 'jquery', 'wc-add-to-cart' ],
            LTMS_VERSION,
            true
        );

        // wp_add_inline_style garantiza que el CSS crítico llegue al browser
        // aunque SiteGround Optimizer combine/cachee el archivo .css externo.
        // Los inline styles nunca son combinados por SG Optimizer.
        wp_add_inline_style( 'ltms-storefront', <<<'LTMS_CSS'
/* LTMS Storefront critical — inline, no cacheable por SG Optimizer */
.ltms-sf-grid{display:grid!important;grid-template-columns:repeat(2,1fr)!important;gap:10px!important}
@media(min-width:768px){.ltms-sf-grid{grid-template-columns:repeat(4,1fr)!important;gap:20px!important}}
.ltms-sf-card{display:flex!important;flex-direction:column!important;background:#fff!important;border-radius:8px!important;overflow:hidden!important;text-align:left!important;float:none!important;width:auto!important;margin:0!important;padding:0!important}
.ltms-sf-card .ltms-sf-card-img{position:relative!important;width:100%!important;height:0!important;padding-bottom:100%!important;overflow:hidden!important;background:#F8F8F8!important}
.ltms-sf-card .ltms-sf-card-img-link{display:block!important;position:absolute!important;top:0!important;left:0!important;right:0!important;bottom:0!important;width:100%!important;height:100%!important;padding:0!important;margin:0!important}
.ltms-sf-card .ltms-sf-img-main,.ltms-sf-card .ltms-sf-img-hover{position:absolute!important;top:0!important;left:0!important;width:100%!important;height:100%!important;object-fit:contain!important;padding:12px!important;max-width:none!important;max-height:none!important;display:block!important}
.ltms-sf-card .ltms-sf-img-hover{opacity:0!important}
.ltms-sf-card:has(.ltms-sf-img-hover):hover .ltms-sf-img-main{opacity:0!important}
.ltms-sf-card:hover .ltms-sf-img-hover{opacity:1!important}
.ltms-sf-card .ltms-sf-card-body{padding:12px 14px 16px!important;display:flex!important;flex-direction:column!important;flex:1 1 auto!important;text-align:left!important}
.ltms-sf-card .ltms-sf-card-cat{font-size:11px!important;color:#767676!important;text-transform:uppercase!important;margin:0 0 4px!important;text-align:left!important}
.ltms-sf-card .ltms-sf-card-name{font-size:14px!important;font-weight:600!important;margin:0 0 6px!important;line-height:1.3!important;text-align:left!important;color:#242424!important}
.ltms-sf-card .ltms-sf-card-name a{color:#242424!important;text-decoration:none!important}
.ltms-sf-card .ltms-sf-card-name a:hover{color:#E80001!important}
.ltms-sf-card .ltms-sf-card-price{font-size:15px!important;font-weight:700!important;color:#E80001!important;margin-bottom:10px!important;text-align:left!important}
.ltms-sf-card .ltms-sf-add-to-cart,.ltms-sf-card a.ltms-sf-add-to-cart.button{display:flex!important;align-items:center!important;justify-content:center!important;width:100%!important;box-sizing:border-box!important;min-height:34px!important;padding:7px 4px!important;border-radius:7px!important;border:1.5px solid #E80001!important;background:#fff!important;color:#E80001!important;font-size:10.5px!important;font-weight:700!important;text-transform:none!important;letter-spacing:-0.01em!important;text-decoration:none!important;box-shadow:none!important;margin-top:auto!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;line-height:1.2!important;cursor:pointer!important}
@media(min-width:768px){.ltms-sf-card .ltms-sf-add-to-cart,.ltms-sf-card a.ltms-sf-add-to-cart.button{min-height:38px!important;padding:8px 10px!important;font-size:12.5px!important;letter-spacing:0!important}}
.ltms-sf-card .ltms-sf-add-to-cart:hover,.ltms-sf-card a.ltms-sf-add-to-cart.button:hover{background:#E80001!important;color:#fff!important}
LTMS_CSS
        );

        // Deshabilitar SiteGround Optimizer en la vitrina.
        // SG Optimizer extrae los <style> inline del <head> y los combina en su
        // archivo CSS externo, rompiendo el orden de cascada. Los filtros de abajo
        // usan la API oficial de SG Optimizer para desactivar la combinación/minificación
        // de CSS y JS únicamente en las páginas de vitrina.
        add_filter( 'sgo_css_combine_exclude',        [ __CLASS__, 'sg_optimizer_exclude' ] );
        add_filter( 'sgo_javascript_combine_exclude', [ __CLASS__, 'sg_optimizer_exclude' ] );
        add_filter( 'sgo_css_minify_exclude',         [ __CLASS__, 'sg_optimizer_exclude' ] );
        add_filter( 'sgo_js_minify_exclude',          [ __CLASS__, 'sg_optimizer_exclude' ] );
        add_filter( 'sgo_html_minify_exclude',        [ __CLASS__, 'sg_optimizer_exclude_url' ] );
        // Desactivar la combinación de critical inline CSS (SG puede extraer <style> tags)
        add_filter( 'sgo_critical_css_exclude_list',  [ __CLASS__, 'sg_optimizer_exclude_url' ] );


        // Elementor (Free o Pro) encola sus bundles en cualquier página.
        // En este contexto no hay post-context de Elementor, así que sus
        // scripts explotan con "elementorModules is not defined" /
        // "elementorFrontendConfig is not defined" — lo que puede romper
        // otros scripts que esperan que la cola JS esté limpia.
        //
        // Estrategia de doble capa:
        // 1. Desencolar todos los handles de Elementor antes de imprimir.
        // 2. Inyectar un stub JS mínimo que define los globales que
        //    Elementor espera, por si algún bundle ya fue inyectado
        //    antes de que podamos desencolarlo (p.ej. via wp_head inline).
        add_action( 'wp_print_scripts', [ __CLASS__, 'strip_elementor_assets' ], 1 );
        add_action( 'wp_print_styles',  [ __CLASS__, 'strip_elementor_assets' ], 1 );

        // Stub: garantiza que los globales de Elementor existan aunque
        // algún bundle se haya colado — evita el ReferenceError.
        wp_add_inline_script(
            'jquery-core',
            'window.elementorModules = window.elementorModules || {};'
            . 'window.elementorFrontend = window.elementorFrontend || { hooks: { addAction: function(){}, doAction: function(){} }, isEditMode: function(){ return false; } };'
            . 'window.elementorFrontendConfig = window.elementorFrontendConfig || { environmentMode: { edit: false }, is_rtl: false };',
            'before'
        );
    }

    /**
     * Excluye handles de CSS/JS de la combinación de SiteGround Optimizer.
     * Devuelve la lista con nuestro handle añadido — SG no combinará ltms-storefront.
     */
    public static function sg_optimizer_exclude( array $exclude_list ): array {
        $exclude_list[] = 'ltms-storefront';
        return $exclude_list;
    }

    /**
     * Devuelve todos los IDs de handles registrados para excluirlos de SG Optimizer.
     * Se usa cuando SG ignora los filtros por nombre de handle y necesitamos
     * desactivar la combinación completamente en la vitrina.
     */
    public static function sg_exclude_all_ids( array $exclude_list ): array {
        global $wp_styles, $wp_scripts;
        $reg = current_filter() && strpos( current_filter(), 'js' ) !== false ? $wp_scripts : $wp_styles;
        if ( $reg && ! empty( $reg->registered ) ) {
            foreach ( array_keys( $reg->registered ) as $handle ) {
                $exclude_list[] = $handle;
            }
        }
        return $exclude_list;
    }

    /**
     * Excluye la URL de la vitrina de las optimizaciones de página completa de SG Optimizer
     * (HTML minify, critical CSS extraction).
     */
    public static function sg_optimizer_exclude_url( array $exclude_list ): array {
        $exclude_list[] = home_url( '/vendedor/' );
        return $exclude_list;
    }

    public static function strip_elementor_assets(): void {
        global $wp_scripts, $wp_styles;
        foreach ( [ $wp_scripts, $wp_styles ] as $reg ) {
            if ( ! $reg || empty( $reg->queue ) ) continue;
            foreach ( array_values( $reg->queue ) as $handle ) {
                if ( false !== stripos( (string) $handle, 'elementor' ) ) {
                    $reg->dequeue( $handle );
                }
            }
        }
    }

    /**
     * Refresca el contador del carrito en nuestro mini-header vía
     * woocommerce_add_to_cart_fragments — el mecanismo estándar y
     * theme-agnostic de WooCommerce, sin depender de ningún drawer
     * o markup propio del tema.
     */
    public static function cart_count_fragment( array $fragments ): array {
        if ( function_exists( 'WC' ) && WC()->cart ) {
            $count = WC()->cart->get_cart_contents_count();
            $fragments['span.ltms-sf-cart-count'] = '<span class="ltms-sf-cart-count">' . esc_html( $count ) . '</span>';
        }
        return $fragments;
    }

    /**
     * Documento HTML propio para la vitrina — ver nota en render() sobre
     * por qué esta página no usa get_header() del tema.
     */
    private static function print_head( object $vendor ): void {
        ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<script>
/* Elementor globals stub — debe ejecutarse ANTES de wp_head() para que
   los bundles de Elementor que se inyectan ahí no generen ReferenceError.
   La vitrina no usa Elementor pero el tema lo encola en todas las páginas. */
window.elementorModules=window.elementorModules||{};
window.elementorFrontend=window.elementorFrontend||{hooks:{addAction:function(){},doAction:function(){},addFilter:function(){return arguments[2];},applyFilters:function(){return arguments[2];}},isEditMode:function(){return false;},utils:{},storage:{},config:{}};
window.elementorFrontendConfig=window.elementorFrontendConfig||{environmentMode:{edit:false,wpPreview:false},is_rtl:false,i18n:{},urls:{},settings:{},kit:{},post:{},user:{},rich_editing:false};
window.elementor=window.elementor||{modules:{}};
</script>
<?php wp_head(); ?>
<style id="ltms-sf-critical">
/* Critical overrides — deben ir DESPUÉS de wp_head() para ganar en cascada
   sobre cualquier estilo del tema WoodMart o WooCommerce. NO usar en el
   archivo .css externo donde el orden de carga no está garantizado. */

/* Grid: siempre 2 columnas en móvil, 4 en desktop */
.ltms-sf-grid{display:grid!important;grid-template-columns:repeat(2,1fr)!important;gap:10px!important;list-style:none!important;margin:0!important;padding:0!important}
@media(min-width:768px){.ltms-sf-grid{grid-template-columns:repeat(4,1fr)!important;gap:20px!important}}

/* Card: flex column, sin herencia de float/text-align del tema */
.ltms-sf-card{display:flex!important;flex-direction:column!important;background:#fff!important;border-radius:8px!important;overflow:hidden!important;text-align:left!important;float:none!important;width:auto!important;margin:0!important;padding:0!important}

/* Contenedor de imagen: padding-bottom hack 1:1 indestructible */
.ltms-sf-card .ltms-sf-card-img{position:relative!important;width:100%!important;height:0!important;padding-bottom:100%!important;overflow:hidden!important;background:#F8F8F8!important}
.ltms-sf-card .ltms-sf-card-img-link{display:block!important;position:absolute!important;inset:0!important;top:0!important;left:0!important;right:0!important;bottom:0!important;width:100%!important;height:100%!important;padding:0!important;margin:0!important}
.ltms-sf-card .ltms-sf-img-main,.ltms-sf-card .ltms-sf-img-hover{position:absolute!important;inset:0!important;top:0!important;left:0!important;width:100%!important;height:100%!important;object-fit:contain!important;padding:12px!important;max-width:none!important;max-height:none!important;display:block!important}
.ltms-sf-card .ltms-sf-img-hover{opacity:0!important}
.ltms-sf-card:has(.ltms-sf-img-hover):hover .ltms-sf-img-main{opacity:0!important}
.ltms-sf-card:hover .ltms-sf-img-hover{opacity:1!important}

/* Cuerpo: texto izquierda, colores correctos */
.ltms-sf-card .ltms-sf-card-body{padding:12px 14px 16px!important;display:flex!important;flex-direction:column!important;flex:1!important;text-align:left!important}
.ltms-sf-card .ltms-sf-card-cat{font-size:11px!important;color:#767676!important;text-transform:uppercase!important;margin:0 0 4px!important;text-align:left!important}
.ltms-sf-card .ltms-sf-card-name{font-size:14px!important;font-weight:600!important;margin:0 0 6px!important;line-height:1.3!important;text-align:left!important;color:#242424!important}
.ltms-sf-card .ltms-sf-card-name a{color:#242424!important;text-decoration:none!important}
.ltms-sf-card .ltms-sf-card-name a:hover{color:#E80001!important}
.ltms-sf-card .ltms-sf-card-price{font-size:15px!important;font-weight:700!important;color:#E80001!important;margin-bottom:10px!important;text-align:left!important}

/* Botón: anular .button del tema completamente. box-sizing:border-box es crítico
   para que width:100%+padding nunca exceda el ancho de la card en mobile. */
.ltms-sf-card .ltms-sf-add-to-cart,.ltms-sf-card a.ltms-sf-add-to-cart.button{display:flex!important;align-items:center!important;justify-content:center!important;width:100%!important;box-sizing:border-box!important;min-height:34px!important;padding:7px 4px!important;border-radius:7px!important;border:1.5px solid #E80001!important;background:#fff!important;color:#E80001!important;font-size:10.5px!important;font-weight:700!important;text-transform:none!important;letter-spacing:-0.01em!important;text-decoration:none!important;box-shadow:none!important;margin-top:auto!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;line-height:1.2!important;cursor:pointer!important}
@media(min-width:768px){.ltms-sf-card .ltms-sf-add-to-cart,.ltms-sf-card a.ltms-sf-add-to-cart.button{min-height:38px!important;padding:8px 10px!important;font-size:12.5px!important;letter-spacing:0!important}}
.ltms-sf-card .ltms-sf-add-to-cart:hover,.ltms-sf-card a.ltms-sf-add-to-cart.button:hover{background:#E80001!important;color:#fff!important;text-decoration:none!important}
</style>
</head>
<body <?php body_class( 'ltms-storefront-page' ); ?>>
<header class="ltms-sf-topbar">
    <div class="ltms-sf-topbar-inner">
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="ltms-sf-topbar-logo">
            <?php
            $logo_id = get_theme_mod( 'custom_logo' );
            $logo_url = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
            if ( $logo_url ) {
                echo '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '">';
            } else {
                echo esc_html( get_bloginfo( 'name' ) ?: 'Lo Tengo' );
            }
            ?>
        </a>
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="ltms-sf-topbar-back">&larr; Volver a la tienda</a>
        <a href="<?php echo esc_url( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/carrito/' ) ); ?>"
           class="ltms-sf-topbar-cart" aria-label="Ver carrito">
            🛒 <span class="ltms-sf-cart-count"><?php
                echo function_exists( 'WC' ) && WC()->cart ? (int) WC()->cart->get_cart_contents_count() : 0;
            ?></span>
        </a>
    </div>
</header>
        <?php
    }

    private static function print_foot(): void {
        ?>
<footer class="ltms-sf-footer">
    <div class="ltms-sf-footer-inner">
        <p>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> Lo Tengo &middot;
           <a href="<?php echo esc_url( home_url( '/' ) ); ?>">Volver a la tienda</a></p>
    </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
        <?php
    }

    private static function render( object $vendor ): void {
        // ── Parámetros de filtro/orden/paginación ──
        $paged      = max( 1, (int) ( $_GET['pg'] ?? 1 ) );
        $per_page   = 12;
        $cat_slug   = sanitize_title( $_GET['cat'] ?? '' );
        $orderby    = in_array( $_GET['order'] ?? '', [ 'price', 'price-desc', 'date' ], true )
                      ? sanitize_text_field( $_GET['order'] ) : 'date';
        $search_q   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $in_stock   = ! empty( $_GET['instock'] );
        $view_mode  = in_array( $_GET['view'] ?? '', [ 'grid', 'list' ], true ) ? $_GET['view'] : 'grid';

        // Filtros de edad (multi-select checkboxes) — almacenados como meta _ltms_age_range
        $ages_raw = isset( $_GET['age'] ) && is_array( $_GET['age'] )
                    ? array_map( 'sanitize_text_field', $_GET['age'] ) : [];

        $tax_query = [];
        if ( $cat_slug ) {
            $tax_query[] = [
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => $cat_slug,
            ];
        }

        $wc_order = match ( $orderby ) {
            'price'      => [ 'orderby' => 'meta_value_num', 'order' => 'ASC',  'meta_key' => '_price' ],
            'price-desc' => [ 'orderby' => 'meta_value_num', 'order' => 'DESC', 'meta_key' => '_price' ],
            default      => [ 'orderby' => 'date',           'order' => 'DESC' ],
        };

        $meta_query = [];
        if ( $in_stock ) {
            $meta_query[] = [ 'key' => '_stock_status', 'value' => 'instock' ];
        }
        if ( $ages_raw ) {
            $meta_query[] = [ 'key' => '_ltms_age_range', 'value' => $ages_raw, 'compare' => 'IN' ];
        }

        $args = array_merge( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'author'         => $vendor->id,
            'tax_query'      => $tax_query,
            'meta_query'     => $meta_query ?: [],
            's'              => $search_q,
        ], $wc_order );

        $query     = new WP_Query( $args );
        $total     = $query->found_posts;
        $pages     = $query->max_num_pages;
        $vendor_cats = self::get_vendor_categories( $vendor->id );
        $base_url  = home_url( '/vendedor/' . $vendor->slug . '/' );

        // Filtros activos para la pastilla de "Limpiar todo"
        $active_filters = array_filter( [ $cat_slug, $in_stock, $ages_raw, $search_q ] );

        self::print_head( $vendor );
        ?>
        <div class="ltms-storefront" itemscope itemtype="https://schema.org/Store">
            <meta itemprop="name" content="<?php echo esc_attr( $vendor->name ); ?>">

            <!-- BANNER -->
            <div class="ltms-sf-banner">
                <?php if ( $vendor->banner ) : ?>
                <img class="ltms-sf-banner-img"
                     src="<?php echo esc_url( $vendor->banner ); ?>"
                     alt="<?php echo esc_attr( $vendor->name ); ?>"
                     loading="eager"
                     decoding="async">
                <?php endif; ?>
                <div class="ltms-sf-banner-overlay<?php echo $vendor->banner ? ' ltms-sf-has-banner' : ''; ?>">
                    <div class="ltms-sf-header">
                        <?php if ( $vendor->logo ) : ?>
                            <img class="ltms-sf-logo" src="<?php echo esc_url( $vendor->logo ); ?>"
                                 alt="<?php echo esc_attr( $vendor->name ); ?>" loading="lazy">
                        <?php else : ?>
                            <div class="ltms-sf-logo ltms-sf-logo-placeholder">
                                <?php echo esc_html( mb_strtoupper( mb_substr( $vendor->name, 0, 1 ) ) ); ?>
                            </div>
                        <?php endif; ?>

                        <div class="ltms-sf-meta">
                            <h1 class="ltms-sf-name<?php echo $vendor->banner ? ' ltms-sf-sr-only' : ''; ?>" itemprop="name">
                                <?php echo esc_html( $vendor->name ); ?>
                                <?php if ( 'approved' === $vendor->kyc_status ) : ?>
                                    <span class="ltms-sf-verified" title="Vendedor verificado">✓</span>
                                <?php endif; ?>
                            </h1>
                            <div class="ltms-sf-meta-row">
                                <?php if ( $vendor->city ) : ?>
                                    <span class="ltms-sf-city">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                        <?php echo esc_html( $vendor->city ); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ( $vendor->rnt ) : ?>
                                    <span class="ltms-sf-rnt">RNT <?php echo esc_html( $vendor->rnt ); ?></span>
                                <?php endif; ?>
                                <span class="ltms-sf-count">
                                    <?php echo esc_html( number_format_i18n( $total ) ); ?>
                                    <?php echo 1 === $total ? 'producto' : 'productos'; ?>
                                </span>
                            </div>
                            <?php if ( $vendor->description ) : ?>
                                <p class="ltms-sf-description" itemprop="description">
                                    <?php echo esc_html( wp_trim_words( $vendor->description, 25 ) ); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div><!-- .ltms-sf-banner -->

            <!-- BARRA DE BÚSQUEDA -->
            <div class="ltms-sf-searchbar">
                <form method="get" action="" class="ltms-sf-search-form" role="search">
                    <input type="hidden" name="vendor_id" value="<?php echo esc_attr( $vendor->id ); ?>">
                    <svg class="ltms-sf-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="search" name="s" class="ltms-sf-search-input"
                           placeholder="Buscar producto"
                           value="<?php echo esc_attr( $search_q ); ?>"
                           aria-label="Buscar en <?php echo esc_attr( $vendor->name ); ?>">
                </form>
            </div>

            <!-- LAYOUT PRINCIPAL: sidebar + contenido -->
            <div class="ltms-sf-layout">

                <!-- SIDEBAR DE FILTROS -->
                <aside class="ltms-sf-sidebar" id="ltms-sf-sidebar" aria-label="Filtros">

                    <!-- Categoría -->
                    <div class="ltms-sf-filter-group">
                        <button class="ltms-sf-filter-heading" aria-expanded="true" aria-controls="ltms-filter-cat">
                            Categoría
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="18 15 12 9 6 15"/></svg>
                        </button>
                        <div class="ltms-sf-filter-body" id="ltms-filter-cat">
                            <label class="ltms-sf-filter-option">
                                <input type="radio" name="ltms_cat" value=""
                                    <?php checked( ! $cat_slug ); ?>
                                    data-ltms-nav-url="<?php echo esc_attr( $base_url ); ?>">
                                Todos
                                <span class="ltms-sf-filter-count"><?php echo esc_html( $total ); ?></span>
                            </label>
                            <?php foreach ( $vendor_cats as $cat ) :
                                $cat_url = add_query_arg( 'cat', $cat->slug, $base_url ); ?>
                                <label class="ltms-sf-filter-option">
                                    <input type="radio" name="ltms_cat" value="<?php echo esc_attr( $cat->slug ); ?>"
                                        <?php checked( $cat_slug, $cat->slug ); ?>
                                        data-ltms-nav-url="<?php echo esc_attr( $cat_url ); ?>">
                                    <?php echo esc_html( $cat->name ); ?>
                                    <span class="ltms-sf-filter-count"><?php echo esc_html( $cat->count ?? '' ); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Disponibilidad -->
                    <div class="ltms-sf-filter-group">
                        <button class="ltms-sf-filter-heading" aria-expanded="true" aria-controls="ltms-filter-stock">
                            Disponibilidad
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="18 15 12 9 6 15"/></svg>
                        </button>
                        <div class="ltms-sf-filter-body" id="ltms-filter-stock">
                            <?php
                            $stock_url = add_query_arg( array_merge(
                                [ 'cat' => $cat_slug ?: null, 'order' => $orderby !== 'date' ? $orderby : null ],
                                $in_stock ? [] : [ 'instock' => '1' ]
                            ), $base_url );
                            ?>
                            <label class="ltms-sf-filter-option">
                                <input type="checkbox" name="instock" value="1"
                                    <?php checked( $in_stock ); ?>
                                    data-ltms-nav-url="<?php echo esc_attr( $stock_url ); ?>">
                                En stock
                            </label>
                        </div>
                    </div>

                    <!-- Botones de acción -->
                    <div class="ltms-sf-filter-actions">
                        <?php if ( $active_filters ) : ?>
                            <a href="<?php echo esc_url( $base_url ); ?>" class="ltms-sf-btn-clear">Limpiar todo ×</a>
                        <?php endif; ?>
                    </div>

                </aside><!-- .ltms-sf-sidebar -->

                <!-- CONTENIDO PRINCIPAL -->
                <div class="ltms-sf-content">

                    <!-- BARRA SUPERIOR: conteo + orden + toggle vista -->
                    <div class="ltms-sf-topbar-content">
                        <span class="ltms-sf-result-count">
                            <?php echo esc_html( number_format_i18n( $total ) ); ?>
                            <?php echo 1 === $total ? 'producto' : 'productos'; ?>
                        </span>

                        <!-- Filtros activos como chips removibles -->
                        <?php if ( $active_filters ) : ?>
                        <div class="ltms-sf-chips">
                            <?php if ( $cat_slug ) :
                                $cat_obj = get_term_by( 'slug', $cat_slug, 'product_cat' );
                                $rm_cat = remove_query_arg( 'cat', add_query_arg( $_GET, $base_url ) );
                            ?>
                                <a href="<?php echo esc_url( $rm_cat ); ?>" class="ltms-sf-chip">
                                    <?php echo esc_html( $cat_obj ? $cat_obj->name : $cat_slug ); ?> ×
                                </a>
                            <?php endif; ?>
                            <?php if ( $in_stock ) :
                                $rm_stock = remove_query_arg( 'instock', add_query_arg( $_GET, $base_url ) );
                            ?>
                                <a href="<?php echo esc_url( $rm_stock ); ?>" class="ltms-sf-chip">En stock ×</a>
                            <?php endif; ?>
                            <?php if ( $active_filters ) : ?>
                                <a href="<?php echo esc_url( $base_url ); ?>" class="ltms-sf-chip ltms-sf-chip--clear">Limpiar todo ×</a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <div class="ltms-sf-topbar-right">
                            <!-- Selector de orden -->
                            <select class="ltms-sf-order-select" aria-label="Ordenar por" data-ltms-nav-select="1">
                                <?php
                                $order_opts = [
                                    'date'       => 'Más recientes',
                                    'price'      => 'Precio: menor a mayor',
                                    'price-desc' => 'Precio: mayor a menor',
                                ];
                                foreach ( $order_opts as $val => $label ) :
                                    $url = add_query_arg( [ 'order' => $val, 'cat' => $cat_slug ?: null ], $base_url );
                                ?>
                                    <option value="<?php echo esc_url( $url ); ?>" <?php selected( $orderby, $val ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <!-- Toggle grid / lista -->
                            <div class="ltms-sf-view-toggle" role="group" aria-label="Vista">
                                <a href="<?php echo esc_url( add_query_arg( 'view', 'grid', add_query_arg( $_GET, $base_url ) ) ); ?>"
                                   class="ltms-sf-view-btn <?php echo 'list' !== $view_mode ? 'active' : ''; ?>"
                                   aria-label="Vista cuadrícula">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                                </a>
                                <a href="<?php echo esc_url( add_query_arg( 'view', 'list', add_query_arg( $_GET, $base_url ) ) ); ?>"
                                   class="ltms-sf-view-btn <?php echo 'list' === $view_mode ? 'active' : ''; ?>"
                                   aria-label="Vista lista">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                                </a>
                            </div>
                        </div>
                    </div><!-- .ltms-sf-topbar-content -->

                    <!-- GRID / LISTA DE PRODUCTOS -->
                    <?php if ( $query->have_posts() ) : ?>
                        <div class="ltms-sf-grid ltms-sf-view-<?php echo esc_attr( $view_mode ); ?>">
                            <?php while ( $query->have_posts() ) : $query->the_post();
                                global $product;
                                $product = wc_get_product( get_the_ID() );
                                if ( ! $product ) continue;

                                $gallery_ids  = $product->get_gallery_image_ids();
                                $hover_img_id = $gallery_ids ? $gallery_ids[0] : 0;
                                $avg_rating   = (float) $product->get_average_rating();
                                $rating_count = (int) $product->get_rating_count();
                                $is_new       = ( strtotime( get_the_date( 'c' ) ) > strtotime( '-15 days' ) );
                                $discount_pct = 0;
                                if ( $product->is_on_sale() && $product->get_regular_price() > 0 ) {
                                    $discount_pct = round( ( ( $product->get_regular_price() - $product->get_sale_price() ) / $product->get_regular_price() ) * 100 );
                                }
                                $cats = wp_get_post_terms( get_the_ID(), 'product_cat', [ 'number' => 1 ] );
                            ?>
                                <article class="ltms-sf-card" itemscope itemtype="https://schema.org/Product">
                                    <div class="ltms-sf-card-img">
                                        <a href="<?php echo esc_url( get_permalink() ); ?>" class="ltms-sf-card-img-link" aria-label="<?php echo esc_attr( get_the_title() ); ?>">
                                            <?php if ( has_post_thumbnail() ) : ?>
                                                <?php echo wp_get_attachment_image( get_post_thumbnail_id(), 'woocommerce_thumbnail', false, [
                                                    'class'    => 'ltms-sf-img-main',
                                                    'itemprop' => 'image',
                                                    'loading'  => 'lazy',
                                                    'alt'      => esc_attr( get_the_title() ),
                                                ] ); ?>
                                            <?php else : ?>
                                                <div class="ltms-sf-card-no-img">Sin imagen</div>
                                            <?php endif; ?>
                                            <?php if ( $hover_img_id ) : ?>
                                                <?php echo wp_get_attachment_image( $hover_img_id, 'woocommerce_thumbnail', false, [
                                                    'class'   => 'ltms-sf-img-hover',
                                                    'loading' => 'eager',
                                                    'alt'     => '',
                                                ] ); ?>
                                            <?php endif; ?>
                                        </a>

                                        <!-- Badges -->
                                        <div class="ltms-sf-badges">
                                            <?php if ( $discount_pct > 0 ) : ?>
                                                <span class="ltms-badge ltms-badge--pct">-<?php echo esc_html( $discount_pct ); ?>%</span>
                                            <?php elseif ( $product->is_on_sale() ) : ?>
                                                <span class="ltms-badge ltms-badge--sale">OFERTA</span>
                                            <?php endif; ?>
                                            <?php if ( $is_new ) : ?>
                                                <span class="ltms-badge ltms-badge--new">NUEVO</span>
                                            <?php endif; ?>
                                            <?php if ( ! $product->is_in_stock() ) : ?>
                                                <span class="ltms-badge ltms-badge--soldout">AGOTADO</span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Acciones hover -->
                                        <div class="ltms-sf-card-actions">
                                            <button type="button" class="ltms-sf-action-btn ltms-sf-action-wishlist"
                                                    data-product-id="<?php echo esc_attr( get_the_ID() ); ?>"
                                                    aria-label="Favoritos">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                                            </button>
                                            <button type="button" class="ltms-sf-action-btn ltms-sf-action-quickview"
                                                    data-product-id="<?php echo esc_attr( get_the_ID() ); ?>"
                                                    aria-label="Vista rápida">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            </button>
                                        </div>
                                    </div><!-- .ltms-sf-card-img -->

                                    <div class="ltms-sf-card-body">
                                        <p class="ltms-sf-card-cat">
                                            <?php echo $cats ? esc_html( $cats[0]->name ) : esc_html( $vendor->name ); ?>
                                        </p>
                                        <h2 class="ltms-sf-card-name" itemprop="name">
                                            <a href="<?php echo esc_url( get_permalink() ); ?>"><?php echo esc_html( get_the_title() ); ?></a>
                                        </h2>
                                        <?php if ( $rating_count > 0 ) : ?>
                                            <div class="ltms-sf-card-rating" aria-label="<?php echo esc_attr( $avg_rating ); ?> de 5">
                                                <span class="ltms-sf-stars" style="--rating:<?php echo esc_attr( ( $avg_rating / 5 ) * 100 ); ?>%" aria-hidden="true">★★★★★</span>
                                                <span class="ltms-sf-rating-count">(<?php echo esc_html( $rating_count ); ?>)</span>
                                            </div>
                                        <?php endif; ?>
                                        <div class="ltms-sf-card-price" itemprop="offers" itemscope itemtype="https://schema.org/Offer">
                                            <meta itemprop="priceCurrency" content="COP">
                                            <?php echo wp_kses_post( $product->get_price_html() ); ?>
                                        </div>
                                        <?php if ( $product->is_purchasable() && ( $product->is_in_stock() || $product->backorders_allowed() ) ) : ?>
                                            <a href="<?php echo esc_url( $product->add_to_cart_url() ); ?>"
                                               data-quantity="1"
                                               class="ltms-sf-add-to-cart button ajax_add_to_cart add_to_cart_button"
                                               data-product_id="<?php echo esc_attr( $product->get_id() ); ?>"
                                               data-product_sku="<?php echo esc_attr( $product->get_sku() ); ?>"
                                               aria-label="Agregar al carrito"
                                               rel="nofollow">
                                                Agregar al carrito
                                            </a>
                                        <?php else : ?>
                                            <a href="<?php echo esc_url( get_permalink() ); ?>" class="ltms-sf-add-to-cart ltms-sf-view-product">
                                                Ver producto
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endwhile; wp_reset_postdata(); ?>
                        </div><!-- .ltms-sf-grid -->

                        <?php if ( $pages > 1 ) : ?>
                            <nav class="ltms-sf-pagination" aria-label="Paginación">
                                <?php if ( $paged > 1 ) : ?>
                                    <a href="<?php echo esc_url( add_query_arg( 'pg', $paged - 1, add_query_arg( $_GET, $base_url ) ) ); ?>" class="ltms-sf-page-btn ltms-sf-page-prev" aria-label="Anterior">‹</a>
                                <?php endif; ?>
                                <?php for ( $p = 1; $p <= $pages; $p++ ) :
                                    // Mostrar solo primeras 2, últimas 2, y alrededor de la actual
                                    $show = ( $p <= 2 || $p >= $pages - 1 || abs( $p - $paged ) <= 1 );
                                    if ( ! $show ) {
                                        if ( $p === 3 || $p === $pages - 2 ) echo '<span class="ltms-sf-page-ellipsis">…</span>';
                                        continue;
                                    }
                                ?>
                                    <a href="<?php echo esc_url( add_query_arg( [ 'pg' => $p, 'cat' => $cat_slug ?: null, 'order' => $orderby !== 'date' ? $orderby : null ], $base_url ) ); ?>"
                                       class="ltms-sf-page-btn <?php echo $p === $paged ? 'active' : ''; ?>"
                                       aria-label="Página <?php echo esc_attr( $p ); ?>"
                                       <?php echo $p === $paged ? 'aria-current="page"' : ''; ?>>
                                        <?php echo esc_html( $p ); ?>
                                    </a>
                                <?php endfor; ?>
                                <?php if ( $paged < $pages ) : ?>
                                    <a href="<?php echo esc_url( add_query_arg( 'pg', $paged + 1, add_query_arg( $_GET, $base_url ) ) ); ?>" class="ltms-sf-page-btn ltms-sf-page-next" aria-label="Siguiente">›</a>
                                <?php endif; ?>
                            </nav>
                        <?php endif; ?>

                    <?php else : ?>
                        <div class="ltms-sf-empty">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <p>No encontramos productos con esos filtros.</p>
                            <a href="<?php echo esc_url( $base_url ); ?>" class="ltms-sf-btn-clear">Ver todos los productos</a>
                        </div>
                    <?php endif; ?>

                </div><!-- .ltms-sf-content -->
            </div><!-- .ltms-sf-layout -->
        </div><!-- .ltms-storefront -->
        <?php
        self::print_foot();
    }

    private static function get_vendor_categories( int $vendor_id ): array {
        global $wpdb;
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT t.term_id, t.name, t.slug
             FROM {$wpdb->terms} t
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
             INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
             WHERE tt.taxonomy = 'product_cat'
               AND p.post_type = 'product'
               AND p.post_status = 'publish'
               AND p.post_author = %d
             ORDER BY t.name ASC",
            $vendor_id
        ) );
        return is_array( $results ) ? $results : [];
    }
}

// Auto-registrar si el kernel no booteo exitosamente.
// Mismo patrón que commission-writer y backfill-kyc.
add_action( 'plugins_loaded', function() {
    static $sf_done = false;
    if ( $sf_done ) return;
    $sf_done = true;
    if ( class_exists( 'LTMS_Vendor_Storefront' ) ) {
        LTMS_Vendor_Storefront::init();
    }
}, 20 );

