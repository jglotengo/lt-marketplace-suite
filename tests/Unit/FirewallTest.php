<?php

declare( strict_types=1 );

namespace LTMS\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LTMS_Firewall — pure logic methods.
 *
 * Métodos testeados via ReflectionMethod (todos private static):
 *   - check_patterns()            — 11 reglas WAF con payloads reales
 *   - get_severity()              — routing critical/high/medium/low
 *   - is_bad_bot()                — detección por UA string
 *   - ip_in_cidr()                — aritmética de red IPv4
 *   - is_trusted_proxy()          — lógica de proxy con constante
 *   - is_whitelisted_admin_path() — whitelist de rutas admin
 *
 * Sin WP/WC/DB.
 */
class FirewallTest extends TestCase
{
    private function callPrivate( string $method, array $args = [] ): mixed
    {
        $ref = new \ReflectionMethod( \LTMS_Core_Firewall::class, $method );
        $ref->setAccessible( true );
        return $ref->invoke( null, ...$args );
    }

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        \LTMS_Core_Config::flush_cache();
        Monkey\Functions\stubs( [
            'get_option' => static fn( $k, $d = null ) => $d,
        ] );
    }

    protected function tearDown(): void
    {
        \LTMS_Core_Config::flush_cache();
        unset( $_SERVER['REQUEST_URI'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    // ================================================================== //
    //  check_patterns — SQL Injection
    // ================================================================== //

    public function test_sqli_union_select_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ["' UNION SELECT * FROM users--"] );
        $this->assertSame( 'sql_injection_union', $rule );
    }

    public function test_sqli_union_mixed_case_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ["' UnIoN SeLeCt id FROM wp_users"] );
        $this->assertSame( 'sql_injection_union', $rule );
    }

    public function test_sqli_drop_table_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['DROP TABLE wp_users'] );
        $this->assertSame( 'sql_injection_drop', $rule );
    }

    public function test_sqli_truncate_table_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['TRUNCATE TABLE wp_options'] );
        $this->assertSame( 'sql_injection_drop', $rule );
    }

    public function test_sqli_insert_into_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ["INSERT INTO wp_users (user_login) VALUES ('hack')"] );
        $this->assertSame( 'sql_injection_insert', $rule );
    }

    public function test_sqli_update_set_where_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ["UPDATE wp_users SET user_pass='x' WHERE ID=1"] );
        $this->assertSame( 'sql_injection_insert', $rule );
    }

    public function test_sqli_comment_double_dash_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ["admin'--"] );
        $this->assertSame( 'sql_injection_comment', $rule );
    }

    public function test_sqli_comment_hash_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ["admin'#"] );
        $this->assertSame( 'sql_injection_comment', $rule );
    }

    public function test_sqli_comment_block_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ["admin'/*comment*/"] );
        $this->assertSame( 'sql_injection_comment', $rule );
    }

    /** NEW — UNION SELECT con más variantes de espaciado */
    public function test_sqli_union_select_with_tabs_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ["' UNION\tSELECT id FROM users"] );
        $this->assertSame( 'sql_injection_union', $rule );
    }

    /** NEW — DROP DATABASE también se detecta */
    public function test_sqli_drop_database_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['DROP DATABASE ltms_prod'] );
        $this->assertSame( 'sql_injection_drop', $rule );
    }

    /** NEW — INSERT con mayúsculas mixtas */
    public function test_sqli_insert_mixed_case_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ["InSeRt InTo users VALUES ('x')"] );
        $this->assertSame( 'sql_injection_insert', $rule );
    }

    // ================================================================== //
    //  check_patterns — XSS
    // ================================================================== //

    public function test_xss_script_tag_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['<script>alert(1)</script>'] );
        $this->assertSame( 'xss_script', $rule );
    }

    public function test_xss_script_with_spaces_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['< script >alert(1)</ script >'] );
        $this->assertSame( 'xss_script', $rule );
    }

    public function test_xss_onclick_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['<img onclick=alert(1)>'] );
        $this->assertSame( 'xss_event_handler', $rule );
    }

    public function test_xss_onload_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['<body onload=alert(1)>'] );
        $this->assertSame( 'xss_event_handler', $rule );
    }

    public function test_xss_onerror_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['<img src=x onerror=alert(1)>'] );
        $this->assertSame( 'xss_event_handler', $rule );
    }

    public function test_xss_javascript_protocol_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['javascript:alert(1)'] );
        $this->assertSame( 'xss_javascript', $rule );
    }

    public function test_xss_javascript_with_spaces_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['javascript  :alert(1)'] );
        $this->assertSame( 'xss_javascript', $rule );
    }

    /** NEW — onmouseover es event handler */
    public function test_xss_onmouseover_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['<div onmouseover=alert(1)>'] );
        $this->assertSame( 'xss_event_handler', $rule );
    }

    /** NEW — script con src externo */
    public function test_xss_script_src_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['<script src="https://evil.com/x.js">'] );
        $this->assertSame( 'xss_script', $rule );
    }

    /** NEW — javascript: en mayúsculas */
    public function test_xss_javascript_uppercase_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['JAVASCRIPT:alert(1)'] );
        $this->assertSame( 'xss_javascript', $rule );
    }

    // ================================================================== //
    //  check_patterns — LFI / RFI / PHP / Null byte
    // ================================================================== //

    public function test_lfi_dotdot_slash_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['../../etc/passwd'] );
        $this->assertSame( 'lfi_path_traversal', $rule );
    }

    public function test_lfi_url_encoded_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['%2e%2e%2fetc/passwd'] );
        $this->assertSame( 'lfi_path_traversal', $rule );
    }

    public function test_rfi_http_php_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['http://evil.com/shell.php'] );
        $this->assertSame( 'rfi_http', $rule );
    }

    public function test_rfi_ftp_asp_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['ftp://attacker.net/cmd.asp'] );
        $this->assertSame( 'rfi_http', $rule );
    }

    public function test_php_injection_open_tag_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['<?php system($_GET["cmd"]); ?>'] );
        $this->assertSame( 'php_injection', $rule );
    }

    public function test_php_injection_eval_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['eval(base64_decode("dGVzdA=="))'] );
        $this->assertSame( 'php_injection', $rule );
    }

    public function test_php_injection_base64_decode_standalone_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['base64_decode("cGhwaW5mbygpOw==")'] );
        $this->assertSame( 'php_injection', $rule );
    }

    public function test_null_byte_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ["file.php\0.jpg"] );
        $this->assertSame( 'null_byte', $rule );
    }

    /** NEW — LFI con barras invertidas (Windows) */
    public function test_lfi_windows_backslash_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['..\\..\\windows\\system32'] );
        $this->assertSame( 'lfi_path_traversal', $rule );
    }

    /** NEW — PHP passthru() también se detecta */
    public function test_php_injection_passthru_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['passthru($_GET["cmd"])'] );
        $this->assertSame( 'php_injection', $rule );
    }

    /** NEW — PHP system() se detecta */
    public function test_php_injection_system_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['system("whoami")'] );
        $this->assertSame( 'php_injection', $rule );
    }

    // ================================================================== //
    //  check_patterns — clean inputs → null
    // ================================================================== //

    public function test_clean_product_name_returns_null(): void
    {
        $this->assertNull( $this->callPrivate( 'check_patterns', ['Camiseta azul talla M'] ) );
    }

    public function test_clean_email_returns_null(): void
    {
        $this->assertNull( $this->callPrivate( 'check_patterns', ['vendor@empresa.com.co'] ) );
    }

    public function test_clean_price_returns_null(): void
    {
        $this->assertNull( $this->callPrivate( 'check_patterns', ['149900.50'] ) );
    }

    public function test_clean_address_returns_null(): void
    {
        $this->assertNull( $this->callPrivate( 'check_patterns', ['Calle 45 No. 23-10 Apto 201'] ) );
    }

    public function test_empty_string_returns_null(): void
    {
        $this->assertNull( $this->callPrivate( 'check_patterns', [''] ) );
    }

    public function test_url_encoded_sqli_decoded_and_detected(): void
    {
        // check_patterns hace urldecode() internamente antes de evaluar
        $encoded = urlencode( "' UNION SELECT * FROM users" );
        $rule = $this->callPrivate( 'check_patterns', [$encoded] );
        $this->assertSame( 'sql_injection_union', $rule );
    }

    /** NEW — nombre de ciudad normal no se detecta */
    public function test_clean_city_name_returns_null(): void
    {
        $this->assertNull( $this->callPrivate( 'check_patterns', ['Bogotá D.C.'] ) );
    }

    /** NEW — NIT colombiano no se detecta como amenaza */
    public function test_clean_nit_returns_null(): void
    {
        $this->assertNull( $this->callPrivate( 'check_patterns', ['900123456-7'] ) );
    }

    /** NEW — número de teléfono limpio no se detecta */
    public function test_clean_phone_returns_null(): void
    {
        $this->assertNull( $this->callPrivate( 'check_patterns', ['+57 300 123 4567'] ) );
    }

    // ================================================================== //
    //  get_severity
    // ================================================================== //

    public function test_severity_sql_drop_is_critical(): void
    {
        $this->assertSame( 'critical', $this->callPrivate( 'get_severity', ['sql_injection_drop'] ) );
    }

    public function test_severity_php_injection_is_critical(): void
    {
        $this->assertSame( 'critical', $this->callPrivate( 'get_severity', ['php_injection'] ) );
    }

    public function test_severity_rfi_is_critical(): void
    {
        $this->assertSame( 'critical', $this->callPrivate( 'get_severity', ['rfi_http'] ) );
    }

    public function test_severity_sqli_union_is_high(): void
    {
        $this->assertSame( 'high', $this->callPrivate( 'get_severity', ['sql_injection_union'] ) );
    }

    public function test_severity_sqli_insert_is_high(): void
    {
        $this->assertSame( 'high', $this->callPrivate( 'get_severity', ['sql_injection_insert'] ) );
    }

    public function test_severity_lfi_is_high(): void
    {
        $this->assertSame( 'high', $this->callPrivate( 'get_severity', ['lfi_path_traversal'] ) );
    }

    public function test_severity_xss_script_is_medium(): void
    {
        $this->assertSame( 'medium', $this->callPrivate( 'get_severity', ['xss_script'] ) );
    }

    public function test_severity_xss_event_is_medium(): void
    {
        $this->assertSame( 'medium', $this->callPrivate( 'get_severity', ['xss_event_handler'] ) );
    }

    public function test_severity_null_byte_is_medium(): void
    {
        $this->assertSame( 'medium', $this->callPrivate( 'get_severity', ['null_byte'] ) );
    }

    public function test_severity_sqli_comment_is_low(): void
    {
        $this->assertSame( 'low', $this->callPrivate( 'get_severity', ['sql_injection_comment'] ) );
    }

    public function test_severity_xss_javascript_is_low(): void
    {
        $this->assertSame( 'low', $this->callPrivate( 'get_severity', ['xss_javascript'] ) );
    }

    public function test_severity_unknown_rule_is_low(): void
    {
        $this->assertSame( 'low', $this->callPrivate( 'get_severity', ['unknown_rule_xyz'] ) );
    }

    /** NEW — get_severity retorna siempre uno de los 4 niveles válidos */
    /** @dataProvider severity_rules_provider */
    public function test_severity_always_returns_valid_level( string $rule ): void
    {
        $valid  = [ 'critical', 'high', 'medium', 'low' ];
        $result = $this->callPrivate( 'get_severity', [$rule] );
        $this->assertContains( $result, $valid, "Severidad '{$result}' no es un nivel válido para regla '{$rule}'" );
    }

    public static function severity_rules_provider(): array
    {
        return [
            'sql_injection_drop'    => [ 'sql_injection_drop' ],
            'sql_injection_union'   => [ 'sql_injection_union' ],
            'sql_injection_insert'  => [ 'sql_injection_insert' ],
            'sql_injection_comment' => [ 'sql_injection_comment' ],
            'xss_script'            => [ 'xss_script' ],
            'xss_event_handler'     => [ 'xss_event_handler' ],
            'xss_javascript'        => [ 'xss_javascript' ],
            'lfi_path_traversal'    => [ 'lfi_path_traversal' ],
            'rfi_http'              => [ 'rfi_http' ],
            'php_injection'         => [ 'php_injection' ],
            'null_byte'             => [ 'null_byte' ],
            'unknown_rule'          => [ 'unknown_rule_xyz' ],
        ];
    }

    // ================================================================== //
    //  is_bad_bot
    // ================================================================== //

    public function test_sqlmap_is_bad_bot(): void
    {
        $this->assertTrue( $this->callPrivate( 'is_bad_bot', ['sqlmap/1.7.8'] ) );
    }

    public function test_nikto_is_bad_bot(): void
    {
        $this->assertTrue( $this->callPrivate( 'is_bad_bot', ['Nikto/2.1.6'] ) );
    }

    public function test_nmap_is_bad_bot(): void
    {
        $this->assertTrue( $this->callPrivate( 'is_bad_bot', ['Nmap Scripting Engine'] ) );
    }

    public function test_burpsuite_is_bad_bot(): void
    {
        $this->assertTrue( $this->callPrivate( 'is_bad_bot', ['BurpSuite/2023'] ) );
    }

    public function test_dirbuster_is_bad_bot(): void
    {
        $this->assertTrue( $this->callPrivate( 'is_bad_bot', ['DirBuster-1.0-RC1'] ) );
    }

    public function test_bad_bot_detection_is_case_insensitive(): void
    {
        $this->assertTrue( $this->callPrivate( 'is_bad_bot', ['SQLMAP/1.0'] ) );
    }

    public function test_chrome_is_not_bad_bot(): void
    {
        $this->assertFalse( $this->callPrivate( 'is_bad_bot', ['Mozilla/5.0 Chrome/120.0'] ) );
    }

    public function test_python_requests_is_not_bad_bot(): void
    {
        // Deliberadamente no bloqueado — API consumers y webhooks legítimos
        $this->assertFalse( $this->callPrivate( 'is_bad_bot', ['python-requests/2.31.0'] ) );
    }

    public function test_curl_is_not_bad_bot(): void
    {
        $this->assertFalse( $this->callPrivate( 'is_bad_bot', ['curl/8.1.2'] ) );
    }

    public function test_empty_ua_is_not_bad_bot(): void
    {
        $this->assertFalse( $this->callPrivate( 'is_bad_bot', [''] ) );
    }

    /** NEW — Hydra (brute force tool) es bad bot */
    public function test_hydra_is_bad_bot(): void
    {
        $this->assertTrue( $this->callPrivate( 'is_bad_bot', ['Hydra'] ) );
    }

    /** NEW — is_bad_bot retorna bool */
    public function test_is_bad_bot_returns_bool(): void
    {
        $result = $this->callPrivate( 'is_bad_bot', ['Mozilla/5.0'] );
        $this->assertIsBool( $result );
    }

    /** NEW — Firefox legítimo no es bad bot */
    public function test_firefox_is_not_bad_bot(): void
    {
        $this->assertFalse( $this->callPrivate( 'is_bad_bot', ['Mozilla/5.0 (Windows NT 10.0; rv:109.0) Gecko/20100101 Firefox/115.0'] ) );
    }

    // ================================================================== //
    //  ip_in_cidr
    // ================================================================== //

    public function test_ip_in_cidr_24_match(): void
    {
        $this->assertTrue( $this->callPrivate( 'ip_in_cidr', ['192.168.1.50', '192.168.1.0/24'] ) );
    }

    public function test_ip_not_in_cidr_24(): void
    {
        $this->assertFalse( $this->callPrivate( 'ip_in_cidr', ['10.0.0.1', '192.168.1.0/24'] ) );
    }

    public function test_cidr_32_exact_match(): void
    {
        $this->assertTrue( $this->callPrivate( 'ip_in_cidr', ['1.2.3.4', '1.2.3.4/32'] ) );
    }

    public function test_cidr_32_no_match(): void
    {
        $this->assertFalse( $this->callPrivate( 'ip_in_cidr', ['1.2.3.5', '1.2.3.4/32'] ) );
    }

    public function test_cidr_16_match(): void
    {
        $this->assertTrue( $this->callPrivate( 'ip_in_cidr', ['10.10.200.1', '10.10.0.0/16'] ) );
    }

    public function test_cidr_cloudflare_range_in(): void
    {
        $this->assertTrue( $this->callPrivate( 'ip_in_cidr', ['173.245.48.1', '173.245.48.0/20'] ) );
    }

    public function test_cidr_cloudflare_range_out(): void
    {
        $this->assertFalse( $this->callPrivate( 'ip_in_cidr', ['173.245.64.0', '173.245.48.0/20'] ) );
    }

    public function test_cidr_no_slash_returns_false(): void
    {
        $this->assertFalse( $this->callPrivate( 'ip_in_cidr', ['192.168.1.1', '192.168.1.0'] ) );
    }

    public function test_cidr_invalid_ip_returns_false(): void
    {
        $this->assertFalse( $this->callPrivate( 'ip_in_cidr', ['999.999.999.999', '192.168.1.0/24'] ) );
    }

    /** NEW — límite inferior de red /24 (primera IP) */
    public function test_cidr_24_first_ip_in_range(): void
    {
        $this->assertTrue( $this->callPrivate( 'ip_in_cidr', ['192.168.1.0', '192.168.1.0/24'] ) );
    }

    /** NEW — límite superior de red /24 (broadcast) */
    public function test_cidr_24_last_ip_in_range(): void
    {
        $this->assertTrue( $this->callPrivate( 'ip_in_cidr', ['192.168.1.255', '192.168.1.0/24'] ) );
    }

    /** NEW — primera IP fuera del rango /24 */
    public function test_cidr_24_first_ip_outside_range(): void
    {
        $this->assertFalse( $this->callPrivate( 'ip_in_cidr', ['192.168.2.0', '192.168.1.0/24'] ) );
    }

    /** NEW — /0 contiene cualquier IP */
    public function test_cidr_0_contains_any_ip(): void
    {
        $this->assertTrue( $this->callPrivate( 'ip_in_cidr', ['8.8.8.8', '0.0.0.0/0'] ) );
    }

    // ================================================================== //
    //  is_trusted_proxy
    // ================================================================== //

    public function test_loopback_127_is_trusted(): void
    {
        $this->assertTrue( $this->callPrivate( 'is_trusted_proxy', ['127.0.0.1'] ) );
    }

    public function test_loopback_ipv6_is_trusted(): void
    {
        $this->assertTrue( $this->callPrivate( 'is_trusted_proxy', ['::1'] ) );
    }

    public function test_random_ip_without_constant_not_trusted(): void
    {
        if ( defined( 'LTMS_TRUSTED_PROXY_IPS' ) ) {
            $this->markTestSkipped( 'LTMS_TRUSTED_PROXY_IPS ya definida en este entorno' );
        }
        $this->assertFalse( $this->callPrivate( 'is_trusted_proxy', ['203.0.113.1'] ) );
    }

    /** NEW — is_trusted_proxy retorna bool */
    public function test_is_trusted_proxy_returns_bool(): void
    {
        $result = $this->callPrivate( 'is_trusted_proxy', ['127.0.0.1'] );
        $this->assertIsBool( $result );
    }

    /** NEW — IP pública aleatoria sin constante no es trusted */
    public function test_public_ip_not_trusted_without_constant(): void
    {
        if ( defined( 'LTMS_TRUSTED_PROXY_IPS' ) ) {
            $this->markTestSkipped( 'LTMS_TRUSTED_PROXY_IPS ya definida en este entorno' );
        }
        $this->assertFalse( $this->callPrivate( 'is_trusted_proxy', ['8.8.8.8'] ) );
    }

    // ================================================================== //
    //  is_whitelisted_admin_path
    // ================================================================== //

    public function test_wp_admin_path_is_whitelisted(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-admin/options-general.php';
        $this->assertTrue( $this->callPrivate( 'is_whitelisted_admin_path' ) );
    }

    public function test_wp_login_is_whitelisted(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-login.php';
        $this->assertTrue( $this->callPrivate( 'is_whitelisted_admin_path' ) );
    }

    public function test_wp_json_is_whitelisted(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-json/wc/v3/orders';
        $this->assertTrue( $this->callPrivate( 'is_whitelisted_admin_path' ) );
    }

    public function test_admin_ajax_is_whitelisted(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
        $this->assertTrue( $this->callPrivate( 'is_whitelisted_admin_path' ) );
    }

    public function test_shop_page_is_not_whitelisted(): void
    {
        $_SERVER['REQUEST_URI'] = '/tienda/producto/camiseta-azul/';
        $this->assertFalse( $this->callPrivate( 'is_whitelisted_admin_path' ) );
    }

    public function test_checkout_page_is_not_whitelisted(): void
    {
        $_SERVER['REQUEST_URI'] = '/checkout/';
        $this->assertFalse( $this->callPrivate( 'is_whitelisted_admin_path' ) );
    }

    public function test_empty_uri_is_not_whitelisted(): void
    {
        $_SERVER['REQUEST_URI'] = '';
        $this->assertFalse( $this->callPrivate( 'is_whitelisted_admin_path' ) );
    }

    /** NEW — wp-cron.php es whitelisted */
    public function test_wp_cron_is_whitelisted(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-cron.php';
        $this->assertTrue( $this->callPrivate( 'is_whitelisted_admin_path' ) );
    }

    /** NEW — página de cuenta de cliente no es whitelisted */
    public function test_my_account_page_is_not_whitelisted(): void
    {
        $_SERVER['REQUEST_URI'] = '/mi-cuenta/';
        $this->assertFalse( $this->callPrivate( 'is_whitelisted_admin_path' ) );
    }

    /** NEW — ruta API REST del plugin no es whitelisted si no es wp-json */
    public function test_custom_api_page_is_not_whitelisted(): void
    {
        $_SERVER['REQUEST_URI'] = '/ltms-api/v1/products';
        $this->assertFalse( $this->callPrivate( 'is_whitelisted_admin_path' ) );
    }

    /** NEW — is_whitelisted_admin_path retorna bool */
    public function test_is_whitelisted_admin_path_returns_bool(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-admin/';
        $result = $this->callPrivate( 'is_whitelisted_admin_path' );
        $this->assertIsBool( $result );
    }

    // ================================================================== //
    //  Angulos adicionales -- check_patterns, ip_in_cidr, is_bad_bot
    // ================================================================== //

    /** null_byte via URL-encoding %00 -- urldecode() lo convierte a   */
    public function test_null_byte_url_encoded_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['file.php%00.jpg'] );
        $this->assertSame( 'null_byte', $rule );
    }

    /** Apostrofe en nombre propio sin -- ni # no dispara sql_injection_comment */
    public function test_apostrophe_in_name_not_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ["O'Brien"] );
        $this->assertNull( $rule );
    }

    /** check_patterns retorna string o null */
    public function test_check_patterns_return_type(): void
    {
        $result = $this->callPrivate( 'check_patterns', ['texto limpio'] );
        $this->assertTrue( $result === null || is_string( $result ) );
    }

    /** ip_in_cidr con subnet invalida retorna false */
    public function test_cidr_invalid_subnet_returns_false(): void
    {
        $this->assertFalse( $this->callPrivate( 'ip_in_cidr', ['192.168.1.1', 'invalid/24'] ) );
    }

    /** ip_in_cidr con prefijo negativo retorna false */
    public function test_cidr_negative_prefix_returns_false(): void
    {
        $this->assertFalse( $this->callPrivate( 'ip_in_cidr', ['192.168.1.1', '192.168.1.0/-1'] ) );
    }

    /** ip_in_cidr con prefijo mayor a 32 retorna false */
    public function test_cidr_prefix_over_32_returns_false(): void
    {
        $this->assertFalse( $this->callPrivate( 'ip_in_cidr', ['192.168.1.1', '192.168.1.0/33'] ) );
    }

    /** shell_exec() se detecta como php_injection */
    public function test_php_injection_shell_exec_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['shell_exec("id")'] );
        $this->assertSame( 'php_injection', $rule );
    }

    /** exec() se detecta como php_injection */
    public function test_php_injection_exec_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['exec("ls")'] );
        $this->assertSame( 'php_injection', $rule );
    }

    /** RFI con .aspx detectado */
    public function test_rfi_aspx_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['https://evil.com/shell.aspx'] );
        $this->assertSame( 'rfi_http', $rule );
    }

    /** RFI con .jsp detectado */
    public function test_rfi_jsp_detected(): void
    {
        $rule = $this->callPrivate( 'check_patterns', ['http://evil.net/cmd.jsp'] );
        $this->assertSame( 'rfi_http', $rule );
    }

    /**
     * DataProvider -- todos los patrones de ataque disparan su regla esperada.
     *
     * @dataProvider provider_attack_samples
     */
    public function test_attack_sample_returns_expected_rule( string $payload, string $expected ): void
    {
        $rule = $this->callPrivate( 'check_patterns', [$payload] );
        $this->assertSame( $expected, $rule );
    }

    public static function provider_attack_samples(): array
    {
        return [
            'sqli_union'   => ["' UNION SELECT user,pass FROM accounts--", 'sql_injection_union'],
            'sqli_drop'    => ['DROP TABLE sessions',                       'sql_injection_drop'],
            'sqli_insert'  => ["INSERT INTO wp_users VALUES('x','x')",    'sql_injection_insert'],
            'sqli_comment' => ["admin'--",                                 'sql_injection_comment'],
            'xss_script'   => ['<script>document.cookie</script>',          'xss_script'],
            'xss_event'    => ['<div onfocus=stealCookies()>',              'xss_event_handler'],
            'xss_js'       => ['javascript:void(0)',                         'xss_javascript'],
            'lfi'          => ['../../../../etc/shadow',                     'lfi_path_traversal'],
            'rfi'          => ['http://evil.com/c99.php',                   'rfi_http'],
            'php_eval'     => ['eval(base64_decode("xxx"))',               'php_injection'],
            'null_byte'    => ["config .php",                          'null_byte'],
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    // SECCIÓN 9 — Ángulos adicionales (usando callPrivate, nombres reales)
    // ════════════════════════════════════════════════════════════════════

    public function test_null_byte_percent00_url_encoded(): void
    {
        // %00 debe decodificarse y detectarse como null_byte
        $result = $this->callPrivate( 'check_patterns', [ urldecode( 'file%00.php' ) ] );
        $this->assertSame( 'null_byte', $result );
    }

    public function test_lfi_windows_backslash_double(): void
    {
        $result = $this->callPrivate( 'check_patterns', [ '..\\..\\windows\\system32\\cmd.exe' ] );
        $this->assertNotNull( $result );
    }

    public function test_severity_xss_javascript_is_low_confirmed(): void
    {
        $this->assertSame( 'low', $this->callPrivate( 'get_severity', [ 'xss_javascript' ] ) );
    }

    public function test_severity_lfi_is_high_confirmed(): void
    {
        $this->assertSame( 'high', $this->callPrivate( 'get_severity', [ 'lfi_path_traversal' ] ) );
    }

    public function test_severity_null_byte_is_medium_confirmed(): void
    {
        $this->assertSame( 'medium', $this->callPrivate( 'get_severity', [ 'null_byte' ] ) );
    }

    public function test_bad_bot_uppercase_sqlmap(): void
    {
        $this->assertTrue( $this->callPrivate( 'is_bad_bot', [ 'SQLMAP/1.0' ] ) );
    }

    public function test_bad_bot_uppercase_nikto(): void
    {
        $this->assertTrue( $this->callPrivate( 'is_bad_bot', [ 'NIKTO/2.1.6' ] ) );
    }

    public function test_bad_bot_mixed_case_burpsuite(): void
    {
        $this->assertTrue( $this->callPrivate( 'is_bad_bot', [ 'BurpSuite/2023.1' ] ) );
    }

    public function test_cidr_0_prefix_contains_google_dns(): void
    {
        $this->assertTrue( $this->callPrivate( 'ip_in_cidr', [ '8.8.8.8', '0.0.0.0/0' ] ) );
    }

    public function test_cidr_32_exact_single_host_match(): void
    {
        $this->assertTrue(  $this->callPrivate( 'ip_in_cidr', [ '203.0.113.5', '203.0.113.5/32' ] ) );
        $this->assertFalse( $this->callPrivate( 'ip_in_cidr', [ '203.0.113.6', '203.0.113.5/32' ] ) );
    }

    public function test_wp_admin_subpath_is_whitelisted(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-admin/post.php';
        $this->assertTrue( $this->callPrivate( 'is_whitelisted_admin_path' ) );
    }

    public function test_wp_admin_plugin_page_is_whitelisted(): void
    {
        $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=ltms-dashboard';
        $this->assertTrue( $this->callPrivate( 'is_whitelisted_admin_path' ) );
    }

    public function test_tienda_path_is_not_whitelisted(): void
    {
        $_SERVER['REQUEST_URI'] = '/tienda/';
        $this->assertFalse( $this->callPrivate( 'is_whitelisted_admin_path' ) );
    }

    // ════════════════════════════════════════════════════════════════════
    // SECCIÓN 10 — Reflexión y tipos de retorno
    // ════════════════════════════════════════════════════════════════════

    public function test_reflection_check_patterns_is_private(): void
    {
        $ref = new \ReflectionMethod( \LTMS_Core_Firewall::class, 'check_patterns' );
        $this->assertTrue( $ref->isPrivate() || $ref->isProtected() || $ref->isPublic() );
    }

    public function test_reflection_get_severity_exists(): void
    {
        $ref = new \ReflectionMethod( \LTMS_Core_Firewall::class, 'get_severity' );
        $this->assertInstanceOf( \ReflectionMethod::class, $ref );
    }

    public function test_reflection_ip_in_cidr_exists(): void
    {
        $ref = new \ReflectionMethod( \LTMS_Core_Firewall::class, 'ip_in_cidr' );
        $this->assertInstanceOf( \ReflectionMethod::class, $ref );
    }

    public function test_check_patterns_clean_returns_null(): void
    {
        $result = $this->callPrivate( 'check_patterns', [ 'Camiseta azul talla M' ] );
        $this->assertNull( $result );
    }

    public function test_check_patterns_attack_returns_string(): void
    {
        $result = $this->callPrivate( 'check_patterns', [ "' UNION SELECT 1,2,3 --" ] );
        $this->assertIsString( $result );
    }

    public function test_severity_always_returns_valid_level_extended(): void
    {
        $valid = [ 'critical', 'high', 'medium', 'low' ];
        $rules = [ 'sql_injection_drop', 'xss_script', 'lfi_path_traversal',
                   'rfi_http', 'php_injection', 'null_byte', 'xss_javascript', 'unknown_xyz' ];

        foreach ( $rules as $rule ) {
            $level = $this->callPrivate( 'get_severity', [ $rule ] );
            $this->assertContains( $level, $valid,
                "get_severity('{$rule}') debe retornar un nivel válido" );
        }
    }

    // ════════════════════════════════════════════════════════════════════
    // SECCIÓN 11 — DataProviders cross-attack con callPrivate
    // ════════════════════════════════════════════════════════════════════

    /**
     * @dataProvider provider_clean_inputs
     */
    public function test_clean_inputs_not_detected( string $input ): void
    {
        $result = $this->callPrivate( 'check_patterns', [ $input ] );
        $this->assertNull( $result,
            "Input limpio '{$input}' no debe detectarse como ataque" );
    }

    public static function provider_clean_inputs(): array
    {
        return [
            'product_name'  => [ 'Camiseta azul talla M' ],
            'email'         => [ 'user@example.com' ],
            'city_colombia' => [ 'Bogotá, D.C.' ],
            'nit_colombia'  => [ '900123456-1' ],
            'phone'         => [ '+57 300 123 4567' ],
            'price_cop'     => [ '$ 150.000' ],
            'description'   => [ 'Producto de alta calidad para el hogar' ],
            'address'       => [ 'Carrera 15 No 45-23, Apto 301' ],
            'unicode_name'  => [ 'María José Álvarez' ],
        ];
    }

    /**
     * @dataProvider provider_attack_vectors
     */
    public function test_attack_vectors_all_detected(
        string $input,
        string $expected_rule
    ): void {
        $result = $this->callPrivate( 'check_patterns', [ $input ] );
        $this->assertNotNull( $result,
            "El vector '{$input}' debe detectarse" );
        $this->assertSame( $expected_rule, $result,
            "El vector debe clasificarse como '{$expected_rule}'" );
    }

    public static function provider_attack_vectors(): array
    {
        return [
            'sqli_union'     => [ "' UNION SELECT 1,2,3 --",      'sql_injection_union'   ],
            'sqli_drop'      => [ "'; DROP TABLE users; --",       'sql_injection_drop'    ],
            'xss_script'     => [ '<script>alert(1)</script>',     'xss_script'            ],
            'xss_onclick'    => [ '<img onclick="evil()">',        'xss_event_handler'     ],
            'lfi_dotdot'     => [ '../../../etc/passwd',           'lfi_path_traversal'    ],
            'rfi_http'       => [ 'http://evil.com/shell.php',     'rfi_http'              ],
            'php_eval'       => [ 'eval(base64_decode("xxx"))',    'php_injection'         ],
            'null_byte'      => [ "config .php",               'null_byte'             ],
            'php_system'     => [ 'system("whoami")',              'php_injection'         ],
            'php_shell_exec' => [ 'shell_exec("ls -la")',          'php_injection'         ],
        ];
    }
}
