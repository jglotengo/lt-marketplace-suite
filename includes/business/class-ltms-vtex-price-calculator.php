<?php
/**
 * Motor de cálculo de precios para productos sincronizados desde VTEX.
 *
 * Reutiliza la MISMA lógica de reglas de negocio que la integración PosGold
 * (LTMS_PosGold_Price_Calculator): transporte, gasto publicitario, devoluciones
 * estimadas, margen de ganancia, comisión Lo Tengo, IVA, costo ReDi y redondeo
 * por encima al múltiplo. La fórmula de cálculo es idéntica — solo cambia el
 * meta prefix donde cada vendor guarda sus reglas (ltms_vtex_price_*) para que
 * las reglas de VTEX y PosGold sean independientes.
 *
 * @package LTMS
 * @version 2.9.323
 * @since 2.9.323
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class LTMS_Vtex_Price_Calculator {

    /** Meta key prefix para las reglas de precio del vendor (VTEX). */
    const META_PREFIX = 'ltms_vtex_price_';

    /**
     * Configuración default de reglas de precio (idénticas a PosGold).
     *
     * @return array
     */
    public static function get_defaults(): array {
        return LTMS_PosGold_Price_Calculator::get_defaults();
    }

    /**
     * Obtiene las reglas de precio configuradas por un vendor (VTEX).
     *
     * @param int $vendor_id ID del vendedor.
     * @return array Reglas de precio (merge de defaults + metas del vendor).
     */
    public static function get_vendor_rules( int $vendor_id ): array {
        $defaults = self::get_defaults();
        $rules    = [];

        foreach ( $defaults as $key => $default ) {
            $meta_key = self::META_PREFIX . $key;
            $value    = get_user_meta( $vendor_id, $meta_key, true );

            if ( $value === '' || $value === null ) {
                $rules[ $key ] = $default;
            } elseif ( is_bool( $default ) ) {
                $rules[ $key ] = ( $value === 'yes' || $value === '1' || $value === true );
            } elseif ( is_float( $default ) || is_int( $default ) ) {
                $rules[ $key ] = (float) $value;
            } else {
                $rules[ $key ] = $value;
            }
        }

        return $rules;
    }

    /**
     * Guarda las reglas de precio de un vendor (VTEX).
     *
     * @param int   $vendor_id ID del vendedor.
     * @param array $rules     Reglas a guardar.
     * @return void
     */
    public static function save_vendor_rules( int $vendor_id, array $rules ): void {
        $defaults = self::get_defaults();

        foreach ( $defaults as $key => $default ) {
            $meta_key = self::META_PREFIX . $key;
            $value    = $rules[ $key ] ?? $default;

            if ( is_bool( $default ) ) {
                update_user_meta( $vendor_id, $meta_key, $value ? 'yes' : 'no' );
            } else {
                update_user_meta( $vendor_id, $meta_key, (float) $value );
            }
        }
    }

    /**
     * Calcula el precio de venta final para un producto (delega en la misma
     * fórmula que PosGold — reglas de negocio idénticas).
     *
     * @param float $cost  Costo base del producto (precio VTEX).
     * @param array $rules Reglas de precio del vendor.
     * @return array{cost: float, price: float, breakdown: array}
     */
    public static function calculate( float $cost, array $rules ): array {
        return LTMS_PosGold_Price_Calculator::calculate( $cost, $rules );
    }

    /**
     * Redondea un número al múltiplo más cercano POR ENCIMA.
     *
     * @param float $value    Valor a redondear.
     * @param int   $multiple Múltiplo (ej: 1000).
     * @return float
     */
    public static function round_up_to_multiple( float $value, int $multiple ): float {
        return LTMS_PosGold_Price_Calculator::round_up_to_multiple( $value, $multiple );
    }

    /**
     * Genera el título SEO del producto según una plantilla configurable.
     * Los placeholders de PosGold ({nombre},{marca},{categoria},{modelo},{codigo})
     * aplican igual a los productos VTEX normalizados.
     *
     * @param array  $product  Producto normalizado de VTEX.
     * @param string $template Plantilla con placeholders.
     * @return string Título SEO optimizado.
     */
    public static function generate_seo_title( array $product, string $template = '' ): string {
        return LTMS_PosGold_Price_Calculator::generate_seo_title( $product, $template );
    }

    /**
     * Verifica si un producto VTEX tiene la información mínima requerida.
     *
     * @param array $product Producto normalizado.
     * @return array{complete: bool, missing: array}
     */
    public static function validate_product_completeness( array $product ): array {
        return LTMS_PosGold_Price_Calculator::validate_product_completeness( $product );
    }

    /**
     * Filtra productos por categoriaid de VTEX.
     *
     * Coincide si el categoryId del producto está en la selección O si alguno
     * de sus category ids ancestros (categoriesIds) está en la selección
     * (seleccionar "Moda" incluye todas sus subcategorías).
     *
     * @param array        $products     Lista de productos normalizados.
     * @param array|string $category_ids ID o array de IDs de categorías VTEX a incluir.
     * @return array Productos filtrados.
     */
    public static function filter_by_category( array $products, $category_ids ): array {
        if ( empty( $category_ids ) ) {
            return $products;
        }

        if ( is_string( $category_ids ) ) {
            $category_ids = array_filter( array_map( 'trim', explode( ',', $category_ids ) ) );
        }
        $category_ids = array_map( 'strval', $category_ids );

        return array_values( array_filter( $products, static function ( $p ) use ( $category_ids ) {
            $own = (string) ( $p['categoria_id'] ?? '' );
            if ( in_array( $own, $category_ids, true ) ) {
                return true;
            }
            $ancestors = is_array( $p['categoria_ids'] ?? null ) ? array_map( 'strval', $p['categoria_ids'] ) : [];
            foreach ( $ancestors as $anc ) {
                if ( in_array( $anc, $category_ids, true ) ) {
                    return true;
                }
            }
            return false;
        } ) );
    }

    /**
     * Depura productos duplicados por SKU, quedándose con el primero.
     *
     * @param array $products Lista de productos normalizados.
     * @return array{unique: array, duplicates: array}
     */
    public static function deduplicate_by_sku( array $products ): array {
        return LTMS_PosGold_Price_Calculator::deduplicate_by_sku( $products );
    }
}