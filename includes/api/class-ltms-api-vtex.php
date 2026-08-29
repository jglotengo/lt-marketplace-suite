<?php
/**
 * API Client para VTEX (plataforma de comercio electrónico).
 *
 * Cada vendedor tiene su propia cuenta VTEX con un accountName, un appKey
 * y un appToken (credenciales de aplicación generadas en el admin VTEX →
 * Configuración de la cuenta → Credenciales de aplicación). Este cliente se
 * conecta a las APIs de VTEX para obtener el catálogo del vendor
 * (productos/SKUs, categorías, marcas, imágenes), sus precios (Pricing) y su
 * stock (Logistics/Inventory), y sincronizarlos hacia WooCommerce.
 *
 * Autenticación: headers X-VTEX-API-AppKey + X-VTEX-API-AppToken.
 * Base URL: https://{accountName}.{environment}.com.br
 *   (environment default: vtexcommercestable)
 *
 * Servicios cubiertos (alcance acordado Catalog + Pricing + Inventory):
 *   - Catalog API  (Search API de catálogo + endpoints PVT de producto/SKU)
 *   - Pricing API  (precio base por SKU)
 *   - Logistics API (inventario/stock por SKU y bodega)
 *
 * El sync engine usa el Search API de catálogo como fuente principal porque
 * en UNA respuesta por página devuelve catalog + precio + stock + imágenes
 * (el comertialOffer agrega Pricing e Inventory), lo que es ~50x más
 * eficiente que llamar catalog+pricing+inventory por SKU. Los endpoints
 * individuales PVT/Pricing/Inventory están implementados para test_connection,
 * enriquecimiento puntual y el árbol de categorías (UI de filtro).
 *
 * @package LTMS
 * @version 2.9.323
 * @since 2.9.323
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class LTMS_Api_Vtex {

    /** Environment default de VTEX (production). */
    const ENV_DEFAULT = 'vtexcommercestable';

    /** Timeout por defecto para requests HTTP (segundos). */
    const HTTP_TIMEOUT = 60;

    /**
     * Construye la URL base de una cuenta VTEX a partir de su accountName
     * y environment.
     *
     * El accountName se guarda en user_meta 'ltms_vtex_account_name'. La URL
     * resultante es https://{accountName}.{environment}.com.br.
     *
     * INTEGRATIONS-AUDIT P0 FIX (SSRF): se validan estrictamente accountName
     * y environment para que un vendor (o quien pueda escribir user_meta) no
     * pueda inyectar un hostname arbitrario que reciba el appToken.
     *
     * @param string $account_name Nombre de la cuenta VTEX.
     * @param string $environment  Environment VTEX (default vtexcommercestable).
     * @return string URL base, ej: 'https://mistienda.vtexcommercestable.com.br'.
     */
    public static function build_base_url( string $account_name, string $environment = '' ): string {
        $account_name = trim( strtolower( $account_name ) );
        $environment  = trim( strtolower( $environment ) );
        if ( empty( $environment ) ) {
            $environment = self::ENV_DEFAULT;
        }

        // Account name: subdominio de nivel superior, solo [a-z0-9-], sin dots.
        if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{0,63}$/', $account_name ) ) {
            return '';
        }
        // Environment: solo [a-z0-9-], sin dots ni slashes (SSRF guard).
        if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{0,63}$/', $environment ) ) {
            return '';
        }

        return 'https://' . $account_name . '.' . $environment . '.com.br';
    }

    /**
     * Realiza una petición a la API de VTEX.
     *
     * @param string $account_name Nombre de la cuenta VTEX.
     * @param string $app_key      X-VTEX-API-AppKey.
     * @param string $app_token    X-VTEX-API-AppToken.
     * @param string $path         Ruta del endpoint (ej: '/api/catalog_system/pub/products/search').
     * @param array  $query        Parámetros de query (GET).
     * @param string $method       Método HTTP (GET por defecto).
     * @param string $environment  Environment VTEX.
     * @return array{success: bool, data: mixed, error: string, status: int}
     */
    public static function request(
        string $account_name,
        string $app_key,
        string $app_token,
        string $path,
        array  $query = [],
        string $method = 'GET',
        string $environment = ''
    ): array {
        $base_url = self::build_base_url( $account_name, $environment );
        if ( '' === $base_url ) {
            return [
                'success' => false,
                'data'    => null,
                'error'   => 'Account VTEX inválido (posible intento de SSRF).',
                'status'  => 0,
            ];
        }
        if ( empty( $app_key ) || empty( $app_token ) ) {
            return [
                'success' => false,
                'data'    => null,
                'error'   => 'Faltan credenciales de aplicación VTEX (appKey/appToken).',
                'status'  => 0,
            ];
        }

        $url = $base_url . $path;
        if ( ! empty( $query ) ) {
            $url = add_query_arg( $query, $url );
        }

        $args = [
            'method'    => strtoupper( $method ),
            'timeout'   => self::HTTP_TIMEOUT,
            'headers'   => [
                'X-VTEX-API-AppKey'   => $app_key,
                'X-VTEX-API-AppToken' => $app_token,
                'Accept'              => 'application/json',
                'Content-Type'        => 'application/json',
                'User-Agent'          => 'LT-Marketplace-Suite/' . ( defined( 'LTMS_VERSION' ) ? LTMS_VERSION : '0.0.0' ),
            ],
            // CICLO33-P1-SSL invariant: sslverify explícito (un MITM podría inyectar
            // respuestas falseadas del catálogo o capturar el appToken).
            'sslverify' => ! ( defined( 'LTMS_DISABLE_SSL_VERIFY' ) && LTMS_DISABLE_SSL_VERIFY ),
        ];

        // Un reintento para 429 (rate limit) y 5xx — VTEX documenta rate limits
        // con header Retry-After (ver Pricing/Logistics API).
        $attempts = 0;
        $response = null;
        do {
            $attempts++;
            $response = wp_remote_request( $url, $args );

            if ( is_wp_error( $response ) ) {
                return [
                    'success' => false,
                    'data'    => null,
                    'error'   => $response->get_error_message(),
                    'status'  => 0,
                ];
            }

            $status_code = wp_remote_retrieve_response_code( $response );
            $body        = wp_remote_retrieve_body( $response );

            // Rate limit: respetar Retry-After y reintentar una vez.
            if ( $status_code === 429 && $attempts < 2 ) {
                $retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
                $sleep       = max( 1, min( 60, $retry_after ) );
                sleep( $sleep );
                continue;
            }
            // Error transitorio 5xx: reintentar una vez.
            if ( $status_code >= 500 && $attempts < 2 ) {
                sleep( 1 );
                continue;
            }
            break;
        } while ( true );

        // Respuesta vacía (algunos endpoints devuelven body vacío en 200).
        if ( '' === trim( (string) $body ) ) {
            return [
                'success' => $status_code >= 200 && $status_code < 300,
                'data'    => $status_code >= 200 && $status_code < 300 ? [] : null,
                'error'   => $status_code >= 200 && $status_code < 300 ? '' : 'HTTP ' . $status_code,
                'status'  => $status_code,
            ];
        }

        $data = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return [
                'success' => false,
                'data'    => null,
                'error'   => 'Respuesta no es JSON válido: ' . json_last_error_msg(),
                'status'  => $status_code,
            ];
        }

        if ( $status_code < 200 || $status_code >= 300 ) {
            $error_msg = is_array( $data ) ? self::pick_field( $data, [ 'message', 'error', 'detail', 'description' ] ) : '';
            if ( empty( $error_msg ) && is_string( $data ) ) {
                $error_msg = $data;
            }
            return [
                'success' => false,
                'data'    => $data,
                'error'   => $error_msg ?: ( 'HTTP ' . $status_code ),
                'status'  => $status_code,
            ];
        }

        return [
            'success' => true,
            'data'    => $data,
            'error'   => '',
            'status'  => $status_code,
            'raw'     => $data,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Catalog API — Search (fuente principal del sync)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Obtiene productos del catálogo VTEX con su contexto completo
     * (SKUs, precio de venta, stock disponible e imágenes) vía el Search API.
     *
     * GET /api/catalog_system/pub/products/search?from={from}&to={to}
     *
     * El commertialOffer del primer seller agrega el Pricing (Price/ListPrice)
     * y el Inventory (AvailableQuantity) en una sola respuesta por página.
     *
     * @param string $account_name Nombre de la cuenta.
     * @param string $app_key      AppKey.
     * @param string $app_token    AppToken.
     * @param int    $from         Índice inicial (paginación, 0-based).
     * @param int    $to           Índice final (máx 49 = 50 items por página).
     * @param string $environment  Environment VTEX.
     * @return array{success: bool, data: array, error: string, status: int}
     */
    public static function get_products_search(
        string $account_name,
        string $app_key,
        string $app_token,
        int $from = 0,
        int $to = 49,
        string $environment = ''
    ): array {
        $result = self::request(
            $account_name, $app_key, $app_token,
            '/api/catalog_system/pub/products/search',
            [ 'from' => max( 0, $from ), 'to' => max( 0, $to ), 'sc' => 1 ],
            'GET',
            $environment
        );

        if ( ! $result['success'] ) {
            return $result;
        }

        $products = is_array( $result['data'] ) ? $result['data'] : [];
        return [
            'success' => true,
            'data'    => $products,
            'error'   => '',
            'status'  => $result['status'],
            'raw'     => $result['data'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Catalog API — endpoints PVT (enriquecimiento puntual)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Obtiene un producto VTEX por su ID (contexto general).
     *
     * GET /api/catalog_system/pvt/products/productget/{productId}
     *
     * @param string $account_name Nombre de la cuenta.
     * @param string $app_key      AppKey.
     * @param string $app_token    AppToken.
     * @param string $product_id   ID del producto VTEX.
     * @return array{success: bool, data: array, error: string, status: int}
     */
    public static function get_product(
        string $account_name,
        string $app_key,
        string $app_token,
        string $product_id,
        string $environment = ''
    ): array {
        $path = '/api/catalog_system/pvt/products/productget/' . rawurlencode( $product_id );
        $result = self::request( $account_name, $app_key, $app_token, $path, [], 'GET', $environment );
        $result['data'] = is_array( $result['data'] ) ? $result['data'] : [];
        return $result;
    }

    /**
     * Obtiene un SKU VTEX por su ID.
     *
     * GET /api/catalog_system/pvt/sku/stockkeepingunitbyid/{skuId}
     *
     * @return array{success: bool, data: array, error: string, status: int}
     */
    public static function get_sku_by_id(
        string $account_name,
        string $app_key,
        string $app_token,
        string $sku_id,
        string $environment = ''
    ): array {
        $path = '/api/catalog_system/pvt/sku/stockkeepingunitbyid/' . rawurlencode( $sku_id );
        $result = self::request( $account_name, $app_key, $app_token, $path, [], 'GET', $environment );
        $result['data'] = is_array( $result['data'] ) ? $result['data'] : [];
        return $result;
    }

    /**
     * Obtiene las imágenes (archivos) de un SKU VTEX.
     *
     * GET /api/catalog/pvt/stockkeepingunit/{skuId}/file
     *
     * @return array{success: bool, data: array, error: string, status: int}
     */
    public static function get_sku_files(
        string $account_name,
        string $app_key,
        string $app_token,
        string $sku_id,
        string $environment = ''
    ): array {
        $path = '/api/catalog/pvt/stockkeepingunit/' . rawurlencode( $sku_id ) . '/file';
        $result = self::request( $account_name, $app_key, $app_token, $path, [], 'GET', $environment );
        $result['data'] = is_array( $result['data'] ) ? $result['data'] : [];
        return $result;
    }

    /**
     * Obtiene el árbol de categorías del catálogo VTEX.
     *
     * GET /api/catalog_system/pub/category/tree/{categoryLevels}
     *
     * Usado por la UI de filtro de categorías del vendor (checkbox list).
     *
     * @param int $levels Niveles de profundidad del árbol.
     * @return array{success: bool, data: array, error: string, status: int}
     */
    public static function get_category_tree(
        string $account_name,
        string $app_key,
        string $app_token,
        int $levels = 4,
        string $environment = ''
    ): array {
        $path = '/api/catalog_system/pub/category/tree/' . max( 1, min( 6, $levels ) );
        $result = self::request( $account_name, $app_key, $app_token, $path, [], 'GET', $environment );
        $result['data'] = is_array( $result['data'] ) ? $result['data'] : [];
        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pricing API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Obtiene el precio de un SKU.
     *
     * GET /api/pricing/prices/{itemId}
     *
     * @return array{success: bool, data: array, error: string, status: int}
     */
    public static function get_price(
        string $account_name,
        string $app_key,
        string $app_token,
        string $sku_id,
        string $environment = ''
    ): array {
        $path = '/api/pricing/prices/' . rawurlencode( $sku_id );
        $result = self::request( $account_name, $app_key, $app_token, $path, [], 'GET', $environment );
        $result['data'] = is_array( $result['data'] ) ? $result['data'] : [];
        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Logistics API — Inventory
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Obtiene el inventario de un SKU (stock por bodega).
     *
     * GET /api/logistics/pvt/inventory/skus/{skuId}
     *
     * @return array{success: bool, data: array, error: string, status: int}
     */
    public static function get_inventory_by_sku(
        string $account_name,
        string $app_key,
        string $app_token,
        string $sku_id,
        string $environment = ''
    ): array {
        $path = '/api/logistics/pvt/inventory/skus/' . rawurlencode( $sku_id );
        $result = self::request( $account_name, $app_key, $app_token, $path, [], 'GET', $environment );
        $result['data'] = is_array( $result['data'] ) ? $result['data'] : [];
        return $result;
    }

    /**
     * Obtiene la lista de bodegas (warehouses) de la cuenta VTEX.
     *
     * GET /api/logistics/pvt/configuration/warehouses
     *
     * @return array{success: bool, data: array, error: string, status: int}
     */
    public static function get_warehouses(
        string $account_name,
        string $app_key,
        string $app_token,
        string $environment = ''
    ): array {
        $result = self::request( $account_name, $app_key, $app_token, '/api/logistics/pvt/configuration/warehouses', [], 'GET', $environment );
        $result['data'] = is_array( $result['data'] ) ? $result['data'] : [];
        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Conexión y normalización
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Prueba la conexión con la cuenta VTEX usando las credenciales del vendor.
     *
     * Trae la primera página del Search API (hasta 10 productos) y cuenta los
     * SKUs. Si el appKey/appToken es inválido, VTEX responde 401/403 → la
     * request devuelve error.
     *
     * @return array{success: bool, message: string, products_count: int}
     */
    public static function test_connection(
        string $account_name,
        string $app_key,
        string $app_token,
        string $environment = ''
    ): array {
        $result = self::get_products_search( $account_name, $app_key, $app_token, 0, 9, $environment );

        if ( ! $result['success'] ) {
            return [
                'success'        => false,
                'message'        => $result['error'] ?: 'Error desconocido',
                'products_count' => 0,
            ];
        }

        $sku_count = 0;
        foreach ( $result['data'] as $product ) {
            $sku_count += is_array( $product['items'] ?? null ) ? count( $product['items'] ) : 0;
        }

        return [
            'success'        => true,
            'message'        => sprintf(
                /* translators: %d: número de productos */
                __( 'Conexión exitosa. Se encontraron %d productos en tu catálogo VTEX.', 'ltms' ),
                count( $result['data'] )
            ),
            'products_count' => count( $result['data'] ),
            'skus_count'     => $sku_count,
        ];
    }

    /**
     * Aplana el árbol de categorías de VTEX en una lista plana.
     *
     * @param array  $tree     Árbol de categorías (respuesta de get_category_tree).
     * @param string $prefix   Prefijo para indentación visual.
     * @return array Lista de ['id' => string, 'nombre' => string, 'count' => int].
     */
    public static function flatten_categories( array $tree, string $prefix = '' ): array {
        $flat = [];
        foreach ( $tree as $cat ) {
            $id     = (string) ( $cat['id'] ?? '' );
            $nombre = (string) ( $cat['name'] ?? '' );
            if ( '' === $id || '' === $nombre ) {
                continue;
            }
            $flat[] = [
                'id'     => $id,
                'nombre' => $prefix . $nombre,
                'count'  => 0,
            ];
            if ( ! empty( $cat['children'] ) && is_array( $cat['children'] ) ) {
                $flat = array_merge( $flat, self::flatten_categories( $cat['children'], $prefix . $nombre . ' / ' ) );
            }
        }
        return $flat;
    }

    /**
     * Normaliza un item (SKU) del Search API de VTEX a formato canónico.
     *
     * @param array $product Producto (padre) de la respuesta del Search API.
     * @param array $item    SKU (item) de la respuesta del Search API.
     * @return array Producto normalizado con claves estándar (mismas que PosGold).
     */
    public static function normalize_search_item( array $product, array $item ): array {
        $offer = $item['sellers'][0]['commertialOffer'] ?? [];
        if ( ! is_array( $offer ) ) {
            $offer = [];
        }

        $image_url = '';
        if ( ! empty( $item['images'] ) && is_array( $item['images'] ) ) {
            $image_url = (string) ( $item['images'][0]['imageUrl'] ?? '' );
        }

        // SKU canónico: RefId del vendor si existe, si no el itemId VTEX (único).
        $sku = (string) ( $item['refId'] ?? '' );
        if ( '' === trim( $sku ) ) {
            $sku = (string) ( $item['itemId'] ?? '' );
        }

        // Categoría jerárquica desde el path (ej: "/Moda/Jeans/Azules/").
        $categoria = '';
        $grupo     = '';
        $subgrupo  = '';
        if ( ! empty( $product['categories'] ) && is_array( $product['categories'] ) ) {
            $cat_path = (string) end( $product['categories'] );
            $segs     = array_values( array_filter( explode( '/', $cat_path ), static fn( $s ) => '' !== trim( $s ) ) );
            $categoria = $segs[0] ?? '';
            $grupo     = $segs[1] ?? '';
            $subgrupo  = $segs[2] ?? '';
        }

        $price = (float) ( $offer['Price'] ?? 0 );

        return [
            'codigo'           => $sku,
            'sku'              => $sku,
            'vtex_sku_id'      => (string) ( $item['itemId'] ?? '' ),
            'vtex_product_id'  => (string) ( $product['productId'] ?? '' ),
            'name'             => (string) ( $item['name'] ?? $product['productName'] ?? '' ),
            'descripcion'      => (string) ( $product['description'] ?? '' ),
            'precio'           => $price,
            'regular_price'    => $price,
            'list_price'       => (float) ( $offer['ListPrice'] ?? $price ),
            'stock'            => (float) ( $offer['AvailableQuantity'] ?? 0 ),
            'stock_quantity'   => (int)   ( $offer['AvailableQuantity'] ?? 0 ),
            'categoria'        => $categoria,
            'categoria_id'     => (string) ( $product['categoryId'] ?? '' ),
            'categoria_ids'    => is_array( $product['categoriesIds'] ?? null ) ? array_map( 'strval', $product['categoriesIds'] ) : [],
            'grupo'            => $grupo,
            'subgrupo'         => $subgrupo,
            'marca'            => (string) ( $product['brand'] ?? '' ),
            'modelo'           => '',
            'barcode'          => (string) ( $item['ean'] ?? '' ),
            'imagen_url'       => $image_url,
            'activo'           => ! empty( $offer['IsAvailable'] ) ? 'true' : 'false',
            'iva'              => 0.0,
            'unidad'           => '',
            '_raw'             => [ 'product' => $product, 'item' => $item ],
        ];
    }

    /**
     * Helper: busca un valor en múltiples claves posibles de un array.
     *
     * @param array $array Array a buscar.
     * @param array $keys  Claves candidatas en orden de prioridad.
     * @return mixed Primer valor no vacío encontrado, o '' si ninguno.
     */
    private static function pick_field( array $array, array $keys ) {
        foreach ( $keys as $key ) {
            if ( isset( $array[ $key ] ) && $array[ $key ] !== '' && $array[ $key ] !== null ) {
                return $array[ $key ];
            }
        }
        return '';
    }
}