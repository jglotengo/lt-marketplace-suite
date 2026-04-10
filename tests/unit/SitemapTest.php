<?php

declare( strict_types=1 );

namespace LTMS\Tests\unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LTMS_Sitemap.
 *
 * Testeable sin WP/WC:
 *   - build_xml()       — generación XML pura (vía reflection, es private)
 *   - add_query_vars()  — array passthrough puro
 *
 * Fuera de scope unit (dependen de WP globals):
 *   - generate_sitemap_xml() — get_posts, get_users, get_option
 *   - handle_sitemap_request() — headers, exit
 *   - init() / register_rewrite_rules() — add_action, add_filter
 */
class SitemapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Monkey\Functions\stubs( [
            'esc_url'  => static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ),
            'esc_html' => static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ),
        ] );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ //
    //  Helper: invoke private static build_xml()
    // ------------------------------------------------------------------ //

    private function buildXml( array $urls ): string
    {
        $ref = new \ReflectionMethod( \LTMS_Sitemap::class, 'build_xml' );
        $ref->setAccessible( true );
        return $ref->invoke( null, $urls );
    }

    // ------------------------------------------------------------------ //
    //  add_query_vars
    // ------------------------------------------------------------------ //

    public function test_add_query_vars_appends_ltms_sitemap(): void
    {
        $result = \LTMS_Sitemap::add_query_vars( [] );
        $this->assertContains( 'ltms_sitemap', $result );
    }

    public function test_add_query_vars_preserves_existing_vars(): void
    {
        $existing = [ 'page', 'paged', 'custom_var' ];
        $result   = \LTMS_Sitemap::add_query_vars( $existing );
        foreach ( $existing as $var ) {
            $this->assertContains( $var, $result );
        }
    }

    public function test_add_query_vars_returns_array_with_one_extra_element(): void
    {
        $input  = [ 'a', 'b', 'c' ];
        $result = \LTMS_Sitemap::add_query_vars( $input );
        $this->assertCount( 4, $result );
    }

    // ------------------------------------------------------------------ //
    //  build_xml — structure
    // ------------------------------------------------------------------ //

    public function test_build_xml_returns_string(): void
    {
        $this->assertIsString( $this->buildXml( [] ) );
    }

    public function test_build_xml_starts_with_xml_declaration(): void
    {
        $xml = $this->buildXml( [] );
        $this->assertStringStartsWith( '<?xml version="1.0" encoding="UTF-8"?>', $xml );
    }

    public function test_build_xml_contains_urlset_opening_tag(): void
    {
        $xml = $this->buildXml( [] );
        $this->assertStringContainsString(
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
            $xml
        );
    }

    public function test_build_xml_closes_urlset(): void
    {
        $xml = $this->buildXml( [] );
        $this->assertStringEndsWith( '</urlset>', $xml );
    }

    public function test_build_xml_empty_urls_produces_valid_structure(): void
    {
        $xml = $this->buildXml( [] );
        $this->assertStringContainsString( '<urlset', $xml );
        $this->assertStringContainsString( '</urlset>', $xml );
        $this->assertStringNotContainsString( '<url>', $xml );
    }

    // ------------------------------------------------------------------ //
    //  build_xml — single URL, all fields
    // ------------------------------------------------------------------ //

    public function test_build_xml_single_url_contains_loc(): void
    {
        $xml = $this->buildXml( [ [
            'loc'        => 'https://example.com/producto/',
            'lastmod'    => '2024-01-15T10:00:00+00:00',
            'changefreq' => 'weekly',
            'priority'   => '0.8',
        ] ] );

        $this->assertStringContainsString( '<loc>', $xml );
        $this->assertStringContainsString( '</loc>', $xml );
        $this->assertStringContainsString( 'example.com/producto/', $xml );
    }

    public function test_build_xml_single_url_contains_lastmod(): void
    {
        $xml = $this->buildXml( [ [
            'loc'        => 'https://example.com/',
            'lastmod'    => '2024-06-01T00:00:00+00:00',
            'changefreq' => 'monthly',
            'priority'   => '0.5',
        ] ] );

        $this->assertStringContainsString( '<lastmod>', $xml );
        $this->assertStringContainsString( '2024-06-01', $xml );
    }

    public function test_build_xml_single_url_contains_changefreq(): void
    {
        $xml = $this->buildXml( [ [
            'loc'        => 'https://example.com/',
            'changefreq' => 'daily',
            'priority'   => '0.7',
        ] ] );

        $this->assertStringContainsString( '<changefreq>daily</changefreq>', $xml );
    }

    public function test_build_xml_single_url_contains_priority(): void
    {
        $xml = $this->buildXml( [ [
            'loc'        => 'https://example.com/',
            'changefreq' => 'weekly',
            'priority'   => '0.8',
        ] ] );

        $this->assertStringContainsString( '<priority>0.8</priority>', $xml );
    }

    public function test_build_xml_omits_lastmod_when_not_provided(): void
    {
        $xml = $this->buildXml( [ [
            'loc'        => 'https://example.com/',
            'changefreq' => 'weekly',
            'priority'   => '0.5',
        ] ] );

        $this->assertStringNotContainsString( '<lastmod>', $xml );
    }

    public function test_build_xml_omits_lastmod_when_empty_string(): void
    {
        $xml = $this->buildXml( [ [
            'loc'        => 'https://example.com/',
            'lastmod'    => '',
            'changefreq' => 'weekly',
            'priority'   => '0.5',
        ] ] );

        $this->assertStringNotContainsString( '<lastmod>', $xml );
    }

    // ------------------------------------------------------------------ //
    //  build_xml — multiple URLs
    // ------------------------------------------------------------------ //

    public function test_build_xml_multiple_urls_all_present(): void
    {
        $urls = [
            [ 'loc' => 'https://example.com/a/', 'changefreq' => 'weekly',  'priority' => '0.8' ],
            [ 'loc' => 'https://example.com/b/', 'changefreq' => 'daily',   'priority' => '0.7' ],
            [ 'loc' => 'https://example.com/c/', 'changefreq' => 'monthly', 'priority' => '0.5' ],
        ];
        $xml = $this->buildXml( $urls );

        $this->assertSame( 3, substr_count( $xml, '<url>' ) );
        $this->assertSame( 3, substr_count( $xml, '</url>' ) );
        $this->assertStringContainsString( 'example.com/a/', $xml );
        $this->assertStringContainsString( 'example.com/b/', $xml );
        $this->assertStringContainsString( 'example.com/c/', $xml );
    }

    public function test_build_xml_url_count_matches_input(): void
    {
        $urls = array_map(
            static fn( $i ) => [ 'loc' => "https://example.com/p{$i}/", 'changefreq' => 'weekly', 'priority' => '0.8' ],
            range( 1, 10 )
        );
        $xml = $this->buildXml( $urls );
        $this->assertSame( 10, substr_count( $xml, '<url>' ) );
    }

    // ------------------------------------------------------------------ //
    //  build_xml — XML validity (SimpleXML parse)
    // ------------------------------------------------------------------ //

    public function test_build_xml_is_valid_xml_when_empty(): void
    {
        $xml    = $this->buildXml( [] );
        $parsed = @simplexml_load_string( $xml );
        $this->assertNotFalse( $parsed, 'build_xml() debe producir XML válido con array vacío' );
    }

    public function test_build_xml_is_valid_xml_with_urls(): void
    {
        $urls = [
            [ 'loc' => 'https://example.com/tour/', 'lastmod' => '2024-03-01T00:00:00+00:00', 'changefreq' => 'weekly', 'priority' => '0.8' ],
            [ 'loc' => 'https://example.com/tienda/vendedor1/', 'changefreq' => 'daily', 'priority' => '0.7' ],
        ];
        $xml    = $this->buildXml( $urls );
        $parsed = @simplexml_load_string( $xml );
        $this->assertNotFalse( $parsed, 'build_xml() debe producir XML válido con URLs' );
    }

    public function test_build_xml_valid_xml_url_nodes_count(): void
    {
        $urls = [
            [ 'loc' => 'https://example.com/a/', 'changefreq' => 'weekly', 'priority' => '0.8' ],
            [ 'loc' => 'https://example.com/b/', 'changefreq' => 'daily',  'priority' => '0.7' ],
        ];
        $xml    = $this->buildXml( $urls );
        $parsed = simplexml_load_string( $xml );
        $this->assertCount( 2, $parsed->url );
    }

    // ------------------------------------------------------------------ //
    //  build_xml — escaping (XSS / injection)
    // ------------------------------------------------------------------ //

    public function test_build_xml_escapes_special_chars_in_changefreq(): void
    {
        $xml = $this->buildXml( [ [
            'loc'        => 'https://example.com/',
            'changefreq' => '<script>alert(1)</script>',
            'priority'   => '0.5',
        ] ] );

        $this->assertStringNotContainsString( '<script>', $xml );
    }

    public function test_build_xml_escapes_special_chars_in_priority(): void
    {
        $xml = $this->buildXml( [ [
            'loc'        => 'https://example.com/',
            'changefreq' => 'weekly',
            'priority'   => '"><script>',
        ] ] );

        $this->assertStringNotContainsString( '<script>', $xml );
    }

    // ------------------------------------------------------------------ //
    //  build_xml — changefreq valid values
    // ------------------------------------------------------------------ //

    /** @dataProvider changefreqValues */
    public function test_build_xml_supports_all_standard_changefreq_values( string $freq ): void
    {
        $xml = $this->buildXml( [ [
            'loc'        => 'https://example.com/',
            'changefreq' => $freq,
            'priority'   => '0.5',
        ] ] );

        $this->assertStringContainsString( "<changefreq>{$freq}</changefreq>", $xml );
    }

    public static function changefreqValues(): array
    {
        return [
            [ 'always' ],
            [ 'hourly' ],
            [ 'daily' ],
            [ 'weekly' ],
            [ 'monthly' ],
            [ 'yearly' ],
            [ 'never' ],
        ];
    }

    // ------------------------------------------------------------------ //
    //  build_xml — priority valid values
    // ------------------------------------------------------------------ //

    /** @dataProvider priorityValues */
    public function test_build_xml_supports_standard_priority_values( string $priority ): void
    {
        $xml = $this->buildXml( [ [
            'loc'        => 'https://example.com/',
            'changefreq' => 'weekly',
            'priority'   => $priority,
        ] ] );

        $this->assertStringContainsString( "<priority>{$priority}</priority>", $xml );
    }

    public static function priorityValues(): array
    {
        return [ [ '0.0' ], [ '0.1' ], [ '0.5' ], [ '0.7' ], [ '0.8' ], [ '1.0' ] ];
    }

    // ------------------------------------------------------------------ //
    //  build_xml — real-world LTMS scenarios
    // ------------------------------------------------------------------ //

    public function test_build_xml_product_entry_matches_ltms_defaults(): void
    {
        $xml = $this->buildXml( [ [
            'loc'        => 'https://ltmarketplace.co/producto/tour-cartagena/',
            'lastmod'    => '2024-09-15T08:30:00+00:00',
            'changefreq' => 'weekly',
            'priority'   => '0.8',
        ] ] );

        $this->assertStringContainsString( 'tour-cartagena', $xml );
        $this->assertStringContainsString( '<changefreq>weekly</changefreq>', $xml );
        $this->assertStringContainsString( '<priority>0.8</priority>', $xml );
        $this->assertStringContainsString( '<lastmod>', $xml );
    }

    public function test_build_xml_vendor_store_entry_matches_ltms_defaults(): void
    {
        $xml = $this->buildXml( [ [
            'loc'        => 'https://ltmarketplace.co/tienda/vendedor-bogota/',
            'changefreq' => 'daily',
            'priority'   => '0.7',
        ] ] );

        $this->assertStringContainsString( 'tienda/vendedor-bogota', $xml );
        $this->assertStringContainsString( '<changefreq>daily</changefreq>', $xml );
        $this->assertStringContainsString( '<priority>0.7</priority>', $xml );
        $this->assertStringNotContainsString( '<lastmod>', $xml );
    }

    public function test_build_xml_page_entry_matches_ltms_defaults(): void
    {
        $xml = $this->buildXml( [ [
            'loc'        => 'https://ltmarketplace.co/dashboard/',
            'changefreq' => 'monthly',
            'priority'   => '0.5',
        ] ] );

        $this->assertStringContainsString( '<changefreq>monthly</changefreq>', $xml );
        $this->assertStringContainsString( '<priority>0.5</priority>', $xml );
    }
}
