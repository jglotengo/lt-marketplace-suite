<?php
/**
 * API Client para PosGold (sistema POS/inventarios colombiano).
 *
 * Cada vendedor tiene su propia instancia de PosGold con un subdominio
 * propio (ej: jugueteriataiwan.goldpos.com.co). Este cliente se conecta
 * a la API /apiGold/ de PosGold para obtener productos del catálogo del
 * vendor y sincronizarlos hacia WooCommerce.
 *
 * Autenticación: Bearer Token (JWT de larga duración).
 *
 * @package LTMS
 * @version 2.9.31
 * @since 2.9.31
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class LTMS_Api_PosGold {

    /**
     * Endpoint de productos (catálogo del vendor).
     */
    const ENDPOINT_PRODUCTS = '/apiGold/ProductoApi/GetProduct_V6';

    /**
     * Timeout por defecto para requests HTTP (segundos).
     */
    const HTTP_TIMEOUT = 60;

    /**
     * Obtiene la URL base del PosGold de un vendor a partir de su subdominio.
     *
     * El subdominio se guarda en user_meta 'ltms_posgold_subdomain' y se combina
     * con el dominio canónico goldpos.com.co.
     *
     * @param string $subdomain Ej: 'jugueteriataiwan'.
     * @return string URL base, ej: 'https://jugueteriataiwan.goldpos.com.co'.
     */
    public static function build_base_url( string $subdomain ): string {
        $subdomain = trim( strtolower( $subdomain ) );
        $subdomain = preg_replace( '/^https?:\/\//', '', $subdomain );
        $subdomain = rtrim( $subdomain, '/' );

        // Si el vendor ingresó el dominio completo, usarlo tal cual.
        if ( strpos( $subdomain, '.' ) !== false ) {
            return 'https://' . $subdomain;
        }

        // Si solo ingresó el slug, construir el subdominio canónico.
        return 'https://' . $subdomain . '.goldpos.com.co';
    }

    /**
     * Obtiene los productos del catálogo PosGold del vendor.
     *
     * @param string $subdomain Subdominio PosGold del vendor.
     * @param string $token     Bearer Token JWT.
     * @param array  $params    Parámetros de query (empresaid, usuarioid, etc.).
     * @param int    $page      Número de página (default 1).
     * @param int    $per_page  Items por página (default 50000 para traer todo de una vez).
     * @return array{success: bool, data: array, error: string, status: int}
     */
    public static function get_products(
        string $subdomain,
        string $token,
        array $params = [],
        int $page = 1,
        int $per_page = 50000
    ): array {
        $base_url   = self::build_base_url( $subdomain );
        $endpoint   = $base_url . self::ENDPOINT_PRODUCTS;

        $defaults = [
            'empresaid'        => 1,
            'usuarioid'        => 1,
            'codigo'           => '',
            'descripcion'      => '',
            'categoriaid'      => '',
            'grupoid'          => '',
            'inicial'          => 0,
            'items_x_pagina'   => $per_page,
            'pagina'           => $page,
            'subgrupoid'       => '',
            'precio_min'       => '',
            'precio_max'       => '',
            'bodegaid'         => 1,
            'orden'            => '',
            'api'              => '',
            'api2'             => '',
            'nombreFull'       => '',
            'ult_mov'          => '2000-01-01',
            'activo'           => 'true',
        ];

        $query_args = array_merge( $defaults, $params );
        $url        = add_query_arg( $query_args, $endpoint );

        $response = wp_remote_get( $url, [
            'timeout' => self::HTTP_TIMEOUT,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
                'User-Agent'    => 'LT-Marketplace-Suite/' . LTMS_VERSION,
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'success' => false,
                'data'    => [],
                'error'   => $response->get_error_message(),
                'status'  => 0,
            ];
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body        = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return [
                'success' => false,
                'data'    => [],
                'error'   => 'Respuesta no es JSON válido: ' . json_last_error_msg(),
                'status'  => $status_code,
            ];
        }

        if ( $status_code < 200 || $status_code >= 300 ) {
            $error_msg = is_array( $data ) && isset( $data['message'] )
                ? $data['message']
                : 'HTTP ' . $status_code;
            return [
                'success' => false,
                'data'    => [],
                'error'   => $error_msg,
                'status'  => $status_code,
            ];
        }

        // La API puede devolver el array de productos directamente o envuelto en un objeto.
        $products = self::extract_products_array( $data );

        return [
            'success' => true,
            'data'    => $products,
            'error'   => '',
            'status'  => $status_code,
            'raw'     => $data,
        ];
    }

    /**
     * Extrae el array de productos de la respuesta de PosGold.
     *
     * PosGold puede devolver los productos en distintas estructuras:
     * - Array directo: [{...}, {...}]
     * - Objeto con 'data': {data: [{...}, {...}]}
     * - Objeto con 'productos': {productos: [{...}, {...}]}
     * - Objeto con 'Items' o 'items': {Items: [{...}, {...}]}
     *
     * Este método maneja todos los casos conocidos.
     *
     * @param mixed $data Respuesta decodificada del JSON.
     * @return array Lista de productos.
     */
    private static function extract_products_array( $data ): array {
        if ( ! is_array( $data ) ) {
            return [];
        }

        // Caso 1: array indexado de productos directamente.
        if ( isset( $data[0] ) && is_array( $data[0] ) ) {
            return $data;
        }

        // Caso 2: buscar en claves conocidas.
        $keys_to_try = [ 'data', 'productos', 'Items', 'items', 'results', 'lista', 'List' ];
        foreach ( $keys_to_try as $key ) {
            if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
                return $data[ $key ];
            }
        }

        // Caso 3: si es un array asociativo con un solo producto, envolverlo.
        if ( ! empty( $data ) && isset( $data['codigo'] ) ) {
            return [ $data ];
        }

        return [];
    }

    /**
     * Normaliza un producto de PosGold a un formato estándar para sincronización.
     *
     * Maneja diferentes nombres de campos que PosGold puede usar (camelCase,
     * snake_case, PascalCase, etc.) y los normaliza a un formato canónico.
     *
     * @param array $raw Producto crudo desde la API de PosGold.
     * @return array Producto normalizado con claves estándar.
     */
    public static function normalize_product( array $raw ): array {
        // Helper: buscar un valor en múltiples claves posibles.
        $pick = static function ( array $keys ) use ( $raw ) {
            foreach ( $keys as $key ) {
                if ( isset( $raw[ $key ] ) && $raw[ $key ] !== '' && $raw[ $key ] !== null ) {
                    return $raw[ $key ];
                }
            }
            return '';
        };

        $codigo      = $pick( [ 'codigo', 'Codigo', 'CODIGO', 'code', 'sku', 'SKU' ] );
        $descripcion = $pick( [ 'descripcion', 'Descripcion', 'DESCRIPCION', 'name', 'nombre', 'Nombre' ] );
        $precio      = $pick( [ 'precio', 'Precio', 'PRECIO', 'price', 'precio_venta', 'PrecioVenta' ] );
        $stock       = $pick( [ 'stock', 'Stock', 'STOCK', 'existencia', 'Existencia', 'cantidad', 'Cantidad', 'saldo' ] );
        $categoria   = $pick( [ 'categoria', 'Categoria', 'categoria_nombre', 'CategoriaNombre' ] );
        $categoria_id = $pick( [ 'categoriaid', 'CategoriaId', 'categoria_id' ] );
        $grupo       = $pick( [ 'grupo', 'Grupo', 'grupo_nombre', 'GrupoNombre' ] );
        $grupo_id    = $pick( [ 'grupoid', 'GrupoId', 'grupo_id' ] );
        $subgrupo    = $pick( [ 'subgrupo', 'SubGrupo', 'subgrupo_nombre' ] );
        $subgrupo_id = $pick( [ 'subgrupoid', 'SubGrupoId' ] );
        $marca       = $pick( [ 'marca', 'Marca', 'MARCA', 'brand' ] );
        $modelo      = $pick( [ 'modelo', 'Modelo', 'MODELO', 'model' ] );
        $barcode     = $pick( [ 'barcode', 'Barcode', 'codigo_barras', 'CodigoBarras', 'ean', 'EAN' ] );
        $imagen_url  = $pick( [ 'imagen', 'Imagen', 'imagen_url', 'ImagenUrl', 'foto', 'Foto', 'image_url' ] );
        $activo      = $pick( [ 'activo', 'Activo', 'ACTIVO', 'active' ] );
        $iva         = $pick( [ 'iva', 'Iva', 'IVA', 'tax_rate' ] );
        $unidad      = $pick( [ 'unidad', 'Unidad', 'UNIDAD', 'unit' ] );

        return [
            'codigo'         => (string) $codigo,
            'sku'            => (string) $codigo,
            'descripcion'    => (string) $descripcion,
            'name'           => (string) $descripcion,
            'precio'         => (float) $precio,
            'regular_price'  => (float) $precio,
            'stock'          => (float) $stock,
            'stock_quantity' => (int) $stock,
            'categoria'      => (string) $categoria,
            'categoria_id'   => (string) $categoria_id,
            'grupo'          => (string) $grupo,
            'grupo_id'       => (string) $grupo_id,
            'subgrupo'       => (string) $subgrupo,
            'subgrupo_id'    => (string) $subgrupo_id,
            'marca'          => (string) $marca,
            'modelo'         => (string) $modelo,
            'barcode'        => (string) $barcode,
            'imagen_url'     => (string) $imagen_url,
            'activo'         => (string) $activo,
            'iva'            => (float) $iva,
            'unidad'         => (string) $unidad,
            '_raw'           => $raw,
        ];
    }

    /**
     * Prueba la conexión con PosGold usando las credenciales del vendor.
     *
     * @param string $subdomain Subdominio PosGold.
     * @param string $token     Bearer Token.
     * @param int    $empresaid Empresa ID.
     * @param int    $usuarioid Usuario ID.
     * @return array{success: bool, message: string, products_count: int}
     */
    public static function test_connection(
        string $subdomain,
        string $token,
        int $empresaid = 1,
        int $usuarioid = 1
    ): array {
        $result = self::get_products(
            $subdomain,
            $token,
            [
                'empresaid'  => $empresaid,
                'usuarioid'  => $usuarioid,
                'items_x_pagina' => 1,
                'pagina'     => 1,
            ],
            1,
            1
        );

        if ( ! $result['success'] ) {
            return [
                'success'        => false,
                'message'        => $result['error'] ?: 'Error desconocido',
                'products_count' => 0,
            ];
        }

        return [
            'success'        => true,
            'message'        => sprintf(
                /* translators: %d: número de productos */
                __( 'Conexión exitosa. Se encontraron %d productos en tu catálogo PosGold.', 'ltms' ),
                count( $result['data'] )
            ),
            'products_count' => count( $result['data'] ),
        ];
    }
}
