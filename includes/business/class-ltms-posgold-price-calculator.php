<?php
/**
 * Motor de cálculo de precios para productos sincronizados desde PosGold.
 *
 * Calcula el precio de venta final aplicando reglas de negocio configurables
 * por vendor:
 *   - Costo del producto (desde PosGold)
 *   - Transporte (% o monto fijo)
 *   - Gasto publicitario (%)
 *   - % Devoluciones estimadas
 *   - Margen de ganancia del vendor (%)
 *   - Comisión Lo Tengo (% del marketplace)
 *   - Impuestos (IVA CO/MX según tipo de producto)
 *   - Costo ReDi (si el producto es ReDi)
 *
 * El precio final se redondea al múltiplo de 1000 más cercano POR ENCIMA
 * (ej: 45200 → 46000, 46001 → 47000).
 *
 * @package LTMS
 * @version 2.9.31
 * @since 2.9.31
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class LTMS_PosGold_Price_Calculator {

    /**
     * Meta key prefix para las reglas de precio del vendor.
     */
    const META_PREFIX = 'ltms_posgold_price_';

    /**
     * Configuración default de reglas de precio.
     * El vendor puede override todos estos valores desde su dashboard.
     *
     * @return array
     */
    public static function get_defaults(): array {
        return [
            // % del costo base (0-100)
            'transport_pct'         => 0.0,
            // % del costo base (0-100)
            'advertising_pct'       => 0.0,
            // % estimado de devoluciones sobre precio final (0-100)
            'returns_pct'           => 0.0,
            // % margen de ganancia del vendor sobre (costo + gastos) (0-100)
            'margin_pct'            => 30.0,
            // % comisión Lo Tengo sobre precio final (0-100)
            'lotengo_commission_pct' => 10.0,
            // % IVA a aplicar (0, 5, 19 para CO; 0, 16 para MX)
            'iva_pct'               => 19.0,
            // % costo ReDi si el producto es ReDi (0-100)
            'redi_cost_pct'         => 0.0,
            // Múltiplo para redondeo (1000 = redondea a miles)
            'round_multiple'        => 1000,
            // Si el producto es ReDi o no (default: no)
            'is_redi'               => false,
        ];
    }

    /**
     * Obtiene las reglas de precio configuradas por un vendor.
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
     * Guarda las reglas de precio de un vendor.
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
     * Calcula el precio de venta final para un producto.
     *
     * Fórmula:
     *   1. costo_base = precio PosGold
     *   2. transporte = costo_base * (transport_pct / 100)
     *   3. publicidad = costo_base * (advertising_pct / 100)
     *   4. redi_cost  = is_redi ? costo_base * (redi_cost_pct / 100) : 0
     *   5. subtotal_gastos = costo_base + transporte + publicidad + redi_cost
     *   6. margen = subtotal_gastos * (margin_pct / 100)
     *   7. subtotal_con_margin = subtotal_gastos + margen
     *   8. comision_lotengo = subtotal_con_margin / (1 - lotengo_commission_pct/100) - subtotal_con_margin
     *   9. devoluciones = (subtotal_con_margin + comision_lotengo) * (returns_pct / 100)
     *  10. base_iva = subtotal_con_margin + comision_lotengo + devoluciones
     *  11. iva = base_iva * (iva_pct / 100)
     *  12. precio_final = base_iva + iva
     *  13. precio_redondeado = redondear_por_encima(precio_final, round_multiple)
     *
     * @param float $cost  Costo base del producto (precio PosGold).
     * @param array $rules Reglas de precio del vendor.
     * @return array{cost: float, price: float, breakdown: array}
     */
    public static function calculate( float $cost, array $rules ): array {
        $breakdown = [];

        // 1. Costo base
        $breakdown['cost'] = $cost;

        // 2. Transporte
        $transport = $cost * ( $rules['transport_pct'] / 100 );
        $breakdown['transport'] = $transport;

        // 3. Publicidad
        $advertising = $cost * ( $rules['advertising_pct'] / 100 );
        $breakdown['advertising'] = $advertising;

        // 4. Costo ReDi (solo si el producto es ReDi)
        $redi_cost = $rules['is_redi'] ? $cost * ( $rules['redi_cost_pct'] / 100 ) : 0;
        $breakdown['redi_cost'] = $redi_cost;

        // 5. Subtotal de gastos
        $subtotal_gastos = $cost + $transport + $advertising + $redi_cost;
        $breakdown['subtotal_gastos'] = $subtotal_gastos;

        // 6. Margen de ganancia del vendor
        $margin = $subtotal_gastos * ( $rules['margin_pct'] / 100 );
        $breakdown['margin'] = $margin;

        // 7. Subtotal con margen
        $subtotal_con_margin = $subtotal_gastos + $margin;
        $breakdown['subtotal_con_margin'] = $subtotal_con_margin;

        // 8. Comisión Lo Tengo
        // La fórmula: si el vendor quiere X neto y la comisión es C% del precio final,
        // entonces precio_final = X / (1 - C/100). Comisión = precio_final - X.
        $commission_pct = $rules['lotengo_commission_pct'] / 100;
        if ( $commission_pct > 0 && $commission_pct < 1 ) {
            $price_with_commission = $subtotal_con_margin / ( 1 - $commission_pct );
            $commission = $price_with_commission - $subtotal_con_margin;
        } else {
            $commission = 0;
            $price_with_commission = $subtotal_con_margin;
        }
        $breakdown['lotengo_commission'] = $commission;

        // 9. Devoluciones estimadas
        $returns = $price_with_commission * ( $rules['returns_pct'] / 100 );
        $breakdown['returns'] = $returns;

        // 10. Base IVA
        $base_iva = $price_with_commission + $returns;
        $breakdown['base_iva'] = $base_iva;

        // 11. IVA
        $iva = $base_iva * ( $rules['iva_pct'] / 100 );
        $breakdown['iva'] = $iva;

        // 12. Precio final
        $price_final = $base_iva + $iva;
        $breakdown['price_final'] = $price_final;

        // 13. Redondeo por encima al múltiplo
        $round_multiple = (int) max( 1, $rules['round_multiple'] );
        $price_rounded = self::round_up_to_multiple( $price_final, $round_multiple );
        $breakdown['price_rounded'] = $price_rounded;
        $breakdown['round_multiple'] = $round_multiple;

        return [
            'cost'       => $cost,
            'price'      => $price_rounded,
            'breakdown'  => $breakdown,
        ];
    }

    /**
     * Redondea un número al múltiplo más cercano POR ENCIMA.
     *
     * Ejemplos con múltiplo=1000:
     *   45200 → 46000
     *   46000 → 46000 (ya es múltiplo, no cambia)
     *   46001 → 47000
     *   500   → 1000
     *   0     → 0
     *
     * @param float $value    Valor a redondear.
     * @param int   $multiple Múltiplo (ej: 1000).
     * @return float
     */
    public static function round_up_to_multiple( float $value, int $multiple ): float {
        if ( $multiple <= 0 || $value <= 0 ) {
            return 0.0;
        }

        $remainder = fmod( $value, $multiple );
        if ( $remainder > 0 ) {
            return $value + ( $multiple - $remainder );
        }
        return $value;
    }

    /**
     * Genera el título SEO del producto según una plantilla configurable.
     *
     * Plantillas soportadas (placeholders):
     *   {nombre}      — Nombre/descripción del producto
     *   {marca}       — Marca
     *   {categoria}   — Categoría
     *   {modelo}      — Modelo
     *   {codigo}      — Código PosGold
     *
     * Plantilla default: "{nombre} {marca} {categoria}"
     * Resultado: "Monopoly Hasbro Juegos de Mesa"
     *
     * @param array  $product     Producto normalizado de PosGold.
     * @param string $template    Plantilla con placeholders.
     * @return string Título SEO optimizado.
     */
    public static function generate_seo_title( array $product, string $template = '' ): string {
        if ( empty( $template ) ) {
            $template = '{nombre} {marca} {categoria}';
        }

        $replacements = [
            '{nombre}'     => trim( $product['name'] ?? '' ),
            '{marca}'      => trim( $product['marca'] ?? '' ),
            '{categoria}'  => trim( $product['categoria'] ?? '' ),
            '{modelo}'     => trim( $product['modelo'] ?? '' ),
            '{codigo}'     => trim( $product['codigo'] ?? '' ),
        ];

        $title = strtr( $template, $replacements );

        // Limpiar espacios múltiples y trim.
        $title = preg_replace( '/\s+/', ' ', $title );
        $title = trim( $title );

        // Si el título quedó vacío, usar el nombre original.
        if ( empty( $title ) ) {
            $title = $product['name'] ?? ( 'Producto ' . ( $product['codigo'] ?? '' ) );
        }

        // Capitalizar primera letra de cada palabra si el original estaba en mayúsculas.
        if ( strtoupper( $title ) === $title ) {
            $title = ucwords( strtolower( $title ) );
        }

        return $title;
    }

    /**
     * Verifica si un producto PosGold tiene la información mínima requerida.
     *
     * Criterios de completitud:
     *   - Tiene código (SKU)
     *   - Tiene nombre/descripción
     *   - Tiene precio > 0
     *   - Imagen es opcional (no bloquea)
     *
     * @param array $product Producto normalizado.
     * @return array{complete: bool, missing: array}
     */
    public static function validate_product_completeness( array $product ): array {
        $missing = [];

        if ( empty( $product['codigo'] ) ) {
            $missing[] = 'codigo';
        }
        if ( empty( $product['name'] ) ) {
            $missing[] = 'nombre';
        }
        if ( empty( $product['regular_price'] ) || $product['regular_price'] <= 0 ) {
            $missing[] = 'precio';
        }

        return [
            'complete' => empty( $missing ),
            'missing'  => $missing,
        ];
    }

    /**
     * Filtra productos por categoriaid de PosGold.
     *
     * @param array        $products         Lista de productos normalizados.
     * @param array|string $category_ids     ID o array de IDs de categorías PosGold a incluir.
     *                                       Si es string, puede ser comma-separated.
     * @return array Productos filtrados.
     */
    public static function filter_by_category( array $products, $category_ids ): array {
        if ( empty( $category_ids ) ) {
            return $products;
        }

        // Normalizar a array de strings.
        if ( is_string( $category_ids ) ) {
            $category_ids = array_filter( array_map( 'trim', explode( ',', $category_ids ) ) );
        }
        $category_ids = array_map( 'strval', $category_ids );

        return array_filter( $products, static function ( $p ) use ( $category_ids ) {
            $cat_id = (string) ( $p['categoria_id'] ?? '' );
            return in_array( $cat_id, $category_ids, true );
        } );
    }

    /**
     * Depura productos duplicados por SKU/código, quedándose con el primero.
     *
     * @param array $products Lista de productos normalizados.
     * @return array{unique: array, duplicates: array}
     */
    public static function deduplicate_by_sku( array $products ): array {
        $seen     = [];
        $unique   = [];
        $duplicates = [];

        foreach ( $products as $product ) {
            $sku = (string) ( $product['codigo'] ?? '' );
            if ( empty( $sku ) ) {
                // Productos sin SKU se incluyen sin depurar.
                $unique[] = $product;
                continue;
            }

            $sku_lower = strtolower( $sku );
            if ( isset( $seen[ $sku_lower ] ) ) {
                $duplicates[] = $product;
            } else {
                $seen[ $sku_lower ] = true;
                $unique[] = $product;
            }
        }

        return [
            'unique'     => $unique,
            'duplicates' => $duplicates,
        ];
    }
}
