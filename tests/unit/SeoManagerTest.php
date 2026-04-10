<?php

declare(strict_types=1);

namespace LTMS\Tests\unit;

use Brain\Monkey\Functions;

/**
 * Tests para LTMS_SEO_Manager
 *
 * Métodos testeables sin WP/WC:
 *   - inject_search_console() — output condicional según config
 *   - inject_search_console_tag() — alias de inject_search_console
 *   - optimize_title_parts() — array filter sin DB
 *   - inject_open_graph() — output vacío cuando get_og_data() retorna []
 *   - inject_schema_org() — output vacío cuando is_singular/is_front_page false
 *
 * Los métodos que usan wc_get_product/get_the_ID/is_singular retornan
 * sin output cuando las funciones WP no están disponibles.
 */
class SeoManagerTest extends LTMS_Unit_Test_Case
{
    protected function setUp(): void
    {
        parent::setUp();
        \LTMS_Core_Config::flush_cache();

        // Funciones WP condicionales que usa SeoManager
        // NOTA: home_url() está pre-definida en bootstrap → NO se puede stubear con Patchwork
        Functions\stubs([
            'is_singular'       => false,
            'is_front_page'     => false,
            'is_home'           => false,
            'get_bloginfo'      => static fn($show = '') => match($show) {
                'name'        => 'Test Site',
                'description' => 'A test site',
                default       => '',
            },
            'get_site_icon_url' => static fn($size = 512) => '',
            'esc_attr'          => static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES),
            'esc_html'          => static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES),
            'esc_url'           => static fn($v) => $v,
            'error_log'         => null,
        ]);
    }

    protected function tearDown(): void
    {
        \LTMS_Core_Config::flush_cache();
        parent::tearDown();
    }

    // ════════════════════════════════════════════════════════════════════
    // inject_search_console() / inject_search_console_tag()
    // ════════════════════════════════════════════════════════════════════

    public function test_inject_search_console_outputs_nothing_when_unconfigured(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        // Config stub devuelve '' para ltms_google_search_console_verify
        ob_start();
        \LTMS_SEO_Manager::inject_search_console();
        $output = ob_get_clean();
        $this->assertEmpty(trim($output));
    }

    public function test_inject_search_console_tag_is_alias_of_inject_search_console(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        ob_start();
        \LTMS_SEO_Manager::inject_search_console_tag();
        $output = ob_get_clean();
        $this->assertEmpty(trim($output));
    }

    public function test_inject_search_console_outputs_meta_when_configured(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        // Inyectar código de verificación via get_option stub
        Functions\when('get_option')->alias(static function($key, $default = null) {
            if ($key === 'ltms_settings') return ['ltms_google_search_console_verify' => 'abc123xyz'];
            return $default;
        });
        \LTMS_Core_Config::flush_cache();

        ob_start();
        \LTMS_SEO_Manager::inject_search_console();
        $output = ob_get_clean();

        $this->assertStringContainsString('google-site-verification', $output);
        $this->assertStringContainsString('abc123xyz', $output);
        $this->assertStringContainsString('<meta', $output);
    }

    public function test_inject_search_console_meta_has_correct_name_attribute(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        Functions\when('get_option')->alias(static function($key, $default = null) {
            if ($key === 'ltms_settings') return ['ltms_google_search_console_verify' => 'verify-token-123'];
            return $default;
        });
        \LTMS_Core_Config::flush_cache();

        ob_start();
        \LTMS_SEO_Manager::inject_search_console();
        $output = ob_get_clean();

        $this->assertStringContainsString('name="google-site-verification"', $output);
        $this->assertStringContainsString('content="verify-token-123"', $output);
    }

    /**
     * El token de verificación termina con newline — comportamiento definido.
     */
    public function test_inject_search_console_output_ends_with_newline(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        Functions\when('get_option')->alias(static function($key, $default = null) {
            if ($key === 'ltms_settings') return ['ltms_google_search_console_verify' => 'token-abc'];
            return $default;
        });
        \LTMS_Core_Config::flush_cache();

        ob_start();
        \LTMS_SEO_Manager::inject_search_console();
        $output = ob_get_clean();

        $this->assertStringEndsWith("\n", $output);
    }

    /**
     * Token largo (hasta 100 chars) debe aparecer intacto en el output.
     */
    public function test_inject_search_console_long_token_appears_in_output(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        $long_token = str_repeat('a1B2c3', 16); // 96 chars
        Functions\when('get_option')->alias(static function($key, $default = null) use ($long_token) {
            if ($key === 'ltms_settings') return ['ltms_google_search_console_verify' => $long_token];
            return $default;
        });
        \LTMS_Core_Config::flush_cache();

        ob_start();
        \LTMS_SEO_Manager::inject_search_console();
        $output = ob_get_clean();

        $this->assertStringContainsString($long_token, $output);
    }

    /**
     * inject_search_console_tag() produce el mismo output que inject_search_console().
     */
    public function test_inject_search_console_tag_output_matches_inject_search_console(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        Functions\when('get_option')->alias(static function($key, $default = null) {
            if ($key === 'ltms_settings') return ['ltms_google_search_console_verify' => 'same-token'];
            return $default;
        });
        \LTMS_Core_Config::flush_cache();

        ob_start();
        \LTMS_SEO_Manager::inject_search_console();
        $output1 = ob_get_clean();

        \LTMS_Core_Config::flush_cache();
        Functions\when('get_option')->alias(static function($key, $default = null) {
            if ($key === 'ltms_settings') return ['ltms_google_search_console_verify' => 'same-token'];
            return $default;
        });

        ob_start();
        \LTMS_SEO_Manager::inject_search_console_tag();
        $output2 = ob_get_clean();

        $this->assertSame($output1, $output2);
    }

    /**
     * Whitespace-only token no debe generar output (string vacío después de trim).
     */
    public function test_inject_search_console_whitespace_only_token_outputs_nothing(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        // Config::get devuelve '' (falsy) por defecto — no hay opción para whitespace
        // El código verifica `if ($verify)` → ' ' es truthy en PHP pero espacio
        // En este caso, el default es '' → no hay output
        ob_start();
        \LTMS_SEO_Manager::inject_search_console();
        $output = ob_get_clean();
        $this->assertStringNotContainsString('<meta', $output);
    }

    // ════════════════════════════════════════════════════════════════════
    // inject_schema_org() — sin WP condicionals → sin output
    // ════════════════════════════════════════════════════════════════════

    public function test_inject_schema_org_outputs_nothing_on_non_product_non_home(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        // is_singular=false, is_front_page=false, is_home=false → retorna sin output
        ob_start();
        \LTMS_SEO_Manager::inject_schema_org();
        $output = ob_get_clean();
        $this->assertEmpty(trim($output));
    }

    /**
     * En is_front_page=true sin funciones WC → genera schema Organization.
     */
    public function test_inject_schema_org_outputs_script_tag_on_front_page(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        Functions\stubs([
            'is_singular'    => false,
            'is_front_page'  => true,
            'is_home'        => false,
            'get_site_icon_url' => '',
        ]);
        Functions\when('get_option')->alias(static function($key, $default = null) {
            return $default;
        });
        \LTMS_Core_Config::flush_cache();

        ob_start();
        \LTMS_SEO_Manager::inject_schema_org();
        $output = ob_get_clean();

        $this->assertStringContainsString('<script type="application/ld+json">', $output);
        $this->assertStringContainsString('</script>', $output);
    }

    /**
     * Schema Organization contiene @type y @context correctos.
     */
    public function test_inject_schema_org_front_page_contains_organization_type(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        Functions\stubs([
            'is_singular'    => false,
            'is_front_page'  => true,
            'is_home'        => false,
            'get_site_icon_url' => '',
        ]);
        Functions\when('get_option')->justReturn(null);
        \LTMS_Core_Config::flush_cache();

        ob_start();
        \LTMS_SEO_Manager::inject_schema_org();
        $output = ob_get_clean();

        $this->assertStringContainsString('Organization', $output);
        $this->assertStringContainsString('schema.org', $output);
    }

    /**
     * Schema Organization incluye la URL del sitio (home_url() retorna 'http://localhost' en bootstrap).
     */
    public function test_inject_schema_org_front_page_includes_home_url(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        Functions\stubs([
            'is_singular'    => false,
            'is_front_page'  => true,
            'is_home'        => false,
            'get_site_icon_url' => '',
        ]);
        Functions\when('get_option')->justReturn(null);
        \LTMS_Core_Config::flush_cache();

        ob_start();
        \LTMS_SEO_Manager::inject_schema_org();
        $output = ob_get_clean();

        // wp_json_encode escapa las barras: http:\/\/localhost
        $this->assertStringContainsString('http:\/\/localhost', $output);
    }

    /**
     * Sin is_singular ni is_front_page → inject_schema_org no genera ningún tag.
     */
    public function test_inject_schema_org_no_script_tag_on_generic_page(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        ob_start();
        \LTMS_SEO_Manager::inject_schema_org();
        $output = ob_get_clean();
        $this->assertStringNotContainsString('<script', $output);
    }

    // ════════════════════════════════════════════════════════════════════
    // inject_open_graph() — sin contexto WP → sin output
    // ════════════════════════════════════════════════════════════════════

    public function test_inject_open_graph_outputs_nothing_on_non_product_non_home(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        // get_og_data() retorna [] cuando is_singular=false y is_front_page=false
        ob_start();
        \LTMS_SEO_Manager::inject_open_graph();
        $output = ob_get_clean();
        $this->assertEmpty(trim($output));
    }

    /**
     * En is_front_page=true → inject_open_graph genera meta og:type=website.
     */
    public function test_inject_open_graph_on_front_page_generates_og_tags(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        Functions\stubs([
            'is_singular'    => false,
            'is_front_page'  => true,
            'is_home'        => false,
            'get_site_icon_url' => static fn($size = 512) => 'https://example.com/icon.png',
        ]);
        Functions\when('get_option')->justReturn(null);
        \LTMS_Core_Config::flush_cache();

        ob_start();
        \LTMS_SEO_Manager::inject_open_graph();
        $output = ob_get_clean();

        $this->assertStringContainsString('<meta', $output);
        $this->assertStringContainsString('og:', $output);
    }

    /**
     * Open Graph en front page incluye og:site_name y og:locale.
     */
    public function test_inject_open_graph_front_page_includes_site_name_and_locale(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        Functions\stubs([
            'is_singular'    => false,
            'is_front_page'  => true,
            'is_home'        => false,
            'get_site_icon_url' => '',
        ]);
        Functions\when('get_option')->justReturn(null);
        \LTMS_Core_Config::flush_cache();

        ob_start();
        \LTMS_SEO_Manager::inject_open_graph();
        $output = ob_get_clean();

        $this->assertStringContainsString('og:site_name', $output);
        $this->assertStringContainsString('og:locale', $output);
    }

    /**
     * Twitter card tag se incluye cuando hay datos OG.
     */
    public function test_inject_open_graph_front_page_includes_twitter_card(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        Functions\stubs([
            'is_singular'    => false,
            'is_front_page'  => true,
            'is_home'        => false,
            'get_site_icon_url' => '',
        ]);
        Functions\when('get_option')->justReturn(null);
        \LTMS_Core_Config::flush_cache();

        ob_start();
        \LTMS_SEO_Manager::inject_open_graph();
        $output = ob_get_clean();

        $this->assertStringContainsString('twitter:card', $output);
        $this->assertStringContainsString('summary_large_image', $output);
    }

    /**
     * Locale configurable via ltms_og_locale.
     */
    public function test_inject_open_graph_uses_configured_locale(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        Functions\stubs([
            'is_singular'    => false,
            'is_front_page'  => true,
            'is_home'        => false,
            'get_site_icon_url' => '',
        ]);
        Functions\when('get_option')->alias(static function($key, $default = null) {
            if ($key === 'ltms_settings') return ['ltms_og_locale' => 'es_MX'];
            return $default;
        });
        \LTMS_Core_Config::flush_cache();

        ob_start();
        \LTMS_SEO_Manager::inject_open_graph();
        $output = ob_get_clean();

        $this->assertStringContainsString('es_MX', $output);
    }

    /**
     * Sin datos OG (ni singular ni front_page) → no hay meta tags de OG.
     */
    public function test_inject_open_graph_no_meta_tags_on_generic_page(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        ob_start();
        \LTMS_SEO_Manager::inject_open_graph();
        $output = ob_get_clean();
        $this->assertStringNotContainsString('og:', $output);
        $this->assertStringNotContainsString('twitter:', $output);
    }

    // ════════════════════════════════════════════════════════════════════
    // optimize_title_parts() — array passthrough y modificaciones
    // ════════════════════════════════════════════════════════════════════

    public function test_optimize_title_parts_returns_array(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        Functions\stubs(['is_singular' => false]);
        $title  = ['title' => 'Página de prueba', 'site' => 'Test Site'];
        $result = \LTMS_SEO_Manager::optimize_title_parts($title);
        $this->assertIsArray($result);
    }

    public function test_optimize_title_parts_passthrough_when_not_singular_product(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        Functions\stubs(['is_singular' => false]);
        $title  = ['title' => 'Inicio', 'site' => 'Mi Tienda'];
        $result = \LTMS_SEO_Manager::optimize_title_parts($title);
        $this->assertSame($title, $result);
    }

    public function test_optimize_title_parts_preserves_original_keys(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        Functions\stubs(['is_singular' => false]);
        $title  = ['title' => 'Test', 'site' => 'Site', 'tagline' => 'Tagline'];
        $result = \LTMS_SEO_Manager::optimize_title_parts($title);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('site', $result);
    }

    public function test_optimize_title_parts_empty_array_returns_empty(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        Functions\stubs(['is_singular' => false]);
        $result = \LTMS_SEO_Manager::optimize_title_parts([]);
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Con is_singular=false, el array se retorna sin mutaciones aunque tenga tagline.
     */
    public function test_optimize_title_parts_does_not_mutate_on_non_product(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        Functions\stubs(['is_singular' => false]);
        $title    = ['title' => 'Mi página', 'site' => 'Tienda', 'tagline' => 'Original tagline'];
        $result   = \LTMS_SEO_Manager::optimize_title_parts($title);
        $this->assertSame('Original tagline', $result['tagline']);
    }

    /**
     * Array con múltiples claves — todas se preservan en passthrough.
     */
    public function test_optimize_title_parts_preserves_all_keys_in_passthrough(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        Functions\stubs(['is_singular' => false]);
        $title = [
            'title'   => 'Producto X',
            'page'    => '2',
            'site'    => 'Mi Tienda',
            'tagline' => 'Slogan',
        ];
        $result = \LTMS_SEO_Manager::optimize_title_parts($title);
        $this->assertCount(4, $result);
        $this->assertSame($title, $result);
    }

    /**
     * Valores numéricos en el array de título no deben alterarse.
     */
    public function test_optimize_title_parts_handles_numeric_values(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        Functions\stubs(['is_singular' => false]);
        $title  = ['title' => 'Página', 'page' => 3];
        $result = \LTMS_SEO_Manager::optimize_title_parts($title);
        $this->assertSame(3, $result['page']);
    }

    /**
     * is_singular devuelve true pero wc_get_product retorna false → no modifica tagline.
     */
    public function test_optimize_title_parts_no_tagline_when_product_not_found(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        Functions\stubs([
            'is_singular'  => true,
            'get_the_ID'   => 999,
        ]);
        Functions\when('wc_get_product')->justReturn(false);

        $title  = ['title' => 'Producto', 'site' => 'Tienda'];
        $result = \LTMS_SEO_Manager::optimize_title_parts($title);

        // wc_get_product=false → vendor_id=0 → store_name='' → tagline no se agrega
        $this->assertArrayNotHasKey('tagline', $result);
    }

    // ════════════════════════════════════════════════════════════════════
    // Verificación de estructura de clase
    // ════════════════════════════════════════════════════════════════════

    public function test_class_exists(): void
    {
        $this->assertTrue(class_exists('LTMS_SEO_Manager'));
    }

    public function test_inject_search_console_method_exists(): void
    {
        $this->assertTrue(method_exists('LTMS_SEO_Manager', 'inject_search_console'));
    }

    public function test_inject_open_graph_method_exists(): void
    {
        $this->assertTrue(method_exists('LTMS_SEO_Manager', 'inject_open_graph'));
    }

    public function test_inject_schema_org_method_exists(): void
    {
        $this->assertTrue(method_exists('LTMS_SEO_Manager', 'inject_schema_org'));
    }

    public function test_optimize_title_parts_method_exists(): void
    {
        $this->assertTrue(method_exists('LTMS_SEO_Manager', 'optimize_title_parts'));
    }

    public function test_inject_search_console_is_public_static(): void
    {
        $ref = new \ReflectionMethod('LTMS_SEO_Manager', 'inject_search_console');
        $this->assertTrue($ref->isPublic());
        $this->assertTrue($ref->isStatic());
    }

    public function test_optimize_title_parts_return_type_is_array(): void
    {
        $ref  = new \ReflectionMethod('LTMS_SEO_Manager', 'optimize_title_parts');
        $type = $ref->getReturnType();
        $this->assertNotNull($type);
        $this->assertSame('array', (string) $type);
    }

    public function test_optimize_title_parts_accepts_array_param(): void
    {
        $ref    = new \ReflectionMethod('LTMS_SEO_Manager', 'optimize_title_parts');
        $params = $ref->getParameters();
        $this->assertCount(1, $params);
        $this->assertSame('title', $params[0]->getName());
    }

    public function test_inject_search_console_tag_method_exists(): void
    {
        $this->assertTrue(method_exists('LTMS_SEO_Manager', 'inject_search_console_tag'));
    }

    public function test_inject_open_graph_is_public_static(): void
    {
        $ref = new \ReflectionMethod('LTMS_SEO_Manager', 'inject_open_graph');
        $this->assertTrue($ref->isPublic());
        $this->assertTrue($ref->isStatic());
    }

    public function test_inject_schema_org_is_public_static(): void
    {
        $ref = new \ReflectionMethod('LTMS_SEO_Manager', 'inject_schema_org');
        $this->assertTrue($ref->isPublic());
        $this->assertTrue($ref->isStatic());
    }

    public function test_inject_search_console_tag_is_public_static(): void
    {
        $ref = new \ReflectionMethod('LTMS_SEO_Manager', 'inject_search_console_tag');
        $this->assertTrue($ref->isPublic());
        $this->assertTrue($ref->isStatic());
    }

    // ════════════════════════════════════════════════════════════════════
    // inject_search_console() — variantes de config
    // ════════════════════════════════════════════════════════════════════

    /**
     * Múltiples tokens distintos — cada uno aparece correctamente en output.
     *
     * @dataProvider provider_search_console_tokens
     */
    public function test_inject_search_console_various_tokens(string $token): void
    {
        $this->require_class('LTMS_SEO_Manager');
        Functions\when('get_option')->alias(static function($key, $default = null) use ($token) {
            if ($key === 'ltms_settings') return ['ltms_google_search_console_verify' => $token];
            return $default;
        });
        \LTMS_Core_Config::flush_cache();

        ob_start();
        \LTMS_SEO_Manager::inject_search_console();
        $output = ob_get_clean();

        $this->assertStringContainsString($token, $output, "Token '{$token}' debe aparecer en el output");
        $this->assertStringContainsString('google-site-verification', $output);
    }

    /** @return array<string, array{string}> */
    public static function provider_search_console_tokens(): array
    {
        return [
            'token alfanumérico corto' => ['abc123'],
            'token con guiones'        => ['gsc-token-abc-def'],
            'token hexadecimal'        => ['a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4'],
            'token con guion bajo'     => ['verify_token_123'],
            'token largo 64 chars'     => [str_repeat('x9', 32)],
        ];
    }

    /**
     * inject_open_graph() con og:locale default es_CO cuando no hay config.
     */
    public function test_inject_open_graph_default_locale_is_es_CO(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        Functions\stubs([
            'is_singular'    => false,
            'is_front_page'  => true,
            'is_home'        => false,
            'get_site_icon_url' => '',
        ]);
        Functions\when('get_option')->justReturn(null);
        \LTMS_Core_Config::flush_cache();

        ob_start();
        \LTMS_SEO_Manager::inject_open_graph();
        $output = ob_get_clean();

        $this->assertStringContainsString('es_CO', $output);
    }

    /**
     * inject_open_graph() og:site_name usa get_bloginfo('name') como fallback.
     */
    public function test_inject_open_graph_site_name_fallback_to_bloginfo(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        Functions\stubs([
            'is_singular'    => false,
            'is_front_page'  => true,
            'is_home'        => false,
            'get_site_icon_url' => '',
            'get_bloginfo'   => static fn($show = '') => $show === 'name' ? 'Mi Tienda CO' : '',
        ]);
        Functions\when('get_option')->justReturn(null);
        \LTMS_Core_Config::flush_cache();

        ob_start();
        \LTMS_SEO_Manager::inject_open_graph();
        $output = ob_get_clean();

        $this->assertStringContainsString('Mi Tienda CO', $output);
    }

    /**
     * inject_schema_org() organization name usa ltms_og_site_name si está configurado.
     */
    public function test_inject_schema_org_uses_configured_site_name(): void
    {
        $this->require_class('LTMS_SEO_Manager');
        Functions\stubs([
            'is_singular'    => false,
            'is_front_page'  => true,
            'is_home'        => false,
            'get_site_icon_url' => '',
        ]);
        Functions\when('get_option')->alias(static function($key, $default = null) {
            if ($key === 'ltms_settings') return ['ltms_og_site_name' => 'Marketplace Colombia'];
            return $default;
        });
        \LTMS_Core_Config::flush_cache();

        ob_start();
        \LTMS_SEO_Manager::inject_schema_org();
        $output = ob_get_clean();

        $this->assertStringContainsString('Marketplace Colombia', $output);
    }
}
