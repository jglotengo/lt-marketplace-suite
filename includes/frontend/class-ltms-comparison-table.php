<?php
/**
 * LTMS Comparison Table — Tabla comparativa de productos/variantes.
 *
 * Dos modos:
 *  1. Variable products: auto-genera comparativa de atributos entre variaciones.
 *  2. Sibling products: vendor marca productos como "comparables" y se muestra
 *     la tabla en la página de producto con productos del mismo vendor + categoría.
 *
 * @package LTMS
 * @version 2.9.2
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Comparison_Table {

    public static function init(): void {
        // Tab en single product (después de la descripción).
        add_filter( 'woocommerce_product_tabs', [ __CLASS__, 'add_comparison_tab' ], 20 );

        // Admin: meta box para marcar productos comparables.
        add_action( 'woocommerce_product_options_related', [ __CLASS__, 'render_comparable_meta' ] );
        add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_comparable_meta' ] );
    }

    /**
     * Añade el tab "Comparar" al single product.
     */
    public static function add_comparison_tab( array $tabs ): array {
        global $product;
        if ( ! $product ) return $tabs;

        $has_data = false;
        if ( $product->is_type( 'variable' ) ) {
            $has_data = true; // Variables siempre tienen comparativa.
        } else {
            $comparable_group = (int) get_post_meta( $product->get_id(), '_ltms_comparable_group', true );
            if ( $comparable_group > 0 ) $has_data = true;
        }

        if ( ! $has_data ) return $tabs;

        $tabs['ltms_compare'] = [
            'title' => __( 'Comparar', 'ltms' ),
            'priority' => 25,
            'callback' => [ __CLASS__, 'render_comparison_tab' ],
        ];
        return $tabs;
    }

    /**
     * Renderiza el contenido del tab de comparación.
     */
    public static function render_comparison_tab(): void {
        global $product;
        if ( ! $product ) return;

        if ( $product->is_type( 'variable' ) ) {
            self::render_variable_comparison( $product );
        } else {
            self::render_sibling_comparison( $product );
        }
    }

    /**
     * Comparativa de variantes de un producto variable.
     */
    private static function render_variable_comparison( $product ): void {
        $variations = $product->get_children();
        if ( count( $variations ) < 2 ) return;

        $attributes = $product->get_variation_attributes();
        $all_attr_keys = array_keys( $attributes );
        // Añadir precio y stock como columnas fijas.
        $columns = array_merge( [ __( 'Característica', 'ltms' ) ], $all_attr_keys, [ __( 'Precio', 'ltms' ), __( 'Disponibilidad', 'ltms' ) ] );

        // Construir filas: cada fila es un atributo, cada columna es una variación.
        $var_data = [];
        foreach ( $variations as $var_id ) {
            $variation = wc_get_product( $var_id );
            if ( ! $variation ) continue;
            $var_attrs = $variation->get_attributes();
            $var_data[] = [
                'id' => $var_id,
                'attrs' => $var_attrs,
                'price' => $variation->get_price_html(),
                'stock' => $variation->get_stock_status() === 'instock' ? __( 'En stock', 'ltms' ) : __( 'Agotado', 'ltms' ),
                'permalink' => $variation->get_permalink(),
                'add_to_cart_url' => $variation->get_permalink() . '?add-to-cart=' . $var_id,
            ];
        }

        if ( count( $var_data ) < 2 ) return;
        ?>
        <div class="ltms-comparison-table-wrap" style="overflow-x:auto;">
            <table class="ltms-comparison-table" style="width:100%;border-collapse:collapse;font-size:14px;">
                <thead>
                    <tr>
                        <?php foreach ( $columns as $col ) : ?>
                            <th style="padding:12px 8px;text-align:left;border-bottom:2px solid #e5e7eb;background:#f9fafb;font-weight:600;">
                                <?php echo esc_html( $col ); ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <!-- Fila precio -->
                    <tr>
                        <td style="padding:10px 8px;border-bottom:1px solid #e5e7eb;font-weight:600;"><?php esc_html_e( 'Precio', 'ltms' ); ?></td>
                        <?php foreach ( $var_data as $vd ) : ?>
                            <td style="padding:10px 8px;border-bottom:1px solid #e5e7eb;font-weight:700;color:#16a34a;">
                                <?php echo $vd['price']; // phpcs:ignore ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <!-- Filas atributos -->
                    <?php foreach ( $all_attr_keys as $attr_key ) : $attr_name = wc_attribute_label( $attr_key ); ?>
                        <tr>
                            <td style="padding:10px 8px;border-bottom:1px solid #e5e7eb;font-weight:600;"><?php echo esc_html( $attr_name ); ?></td>
                            <?php foreach ( $var_data as $vd ) : ?>
                                <td style="padding:10px 8px;border-bottom:1px solid #e5e7eb;">
                                    <?php
                                    $val = $vd['attrs'][ $attr_key ] ?? '—';
                                    echo esc_html( $val === '' ? '—' : ucfirst( $val ) );
                                    ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    <!-- Fila stock -->
                    <tr>
                        <td style="padding:10px 8px;border-bottom:1px solid #e5e7eb;font-weight:600;"><?php esc_html_e( 'Disponibilidad', 'ltms' ); ?></td>
                        <?php foreach ( $var_data as $vd ) : ?>
                            <td style="padding:10px 8px;border-bottom:1px solid #e5e7eb;">
                                <span style="color:<?php echo strpos( $vd['stock'], 'stock' ) !== false ? '#16a34a' : '#dc2626'; ?>;">
                                    <?php echo esc_html( $vd['stock'] ); ?>
                                </span>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <!-- Fila add-to-cart -->
                    <tr>
                        <td style="padding:10px 8px;font-weight:600;"><?php esc_html_e( 'Acción', 'ltms' ); ?></td>
                        <?php foreach ( $var_data as $vd ) : ?>
                            <td style="padding:10px 8px;">
                                <a href="<?php echo esc_url( $vd['add_to_cart_url'] ); ?>"
                                   class="button alt ltms-compare-add-cart"
                                   style="font-size:12px;padding:6px 12px;">
                                    <?php esc_html_e( 'Agregar', 'ltms' ); ?>
                                </a>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Comparativa de productos hermanos (mismo vendor + categoría + grupo comparable).
     */
    private static function render_sibling_comparison( $product ): void {
        $group_id = (int) get_post_meta( $product->get_id(), '_ltms_comparable_group', true );
        if ( ! $group_id ) return;

        global $wpdb;
        // Buscar productos del mismo grupo comparable.
        $siblings = $wpdb->get_col( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_ltms_comparable_group' AND meta_value = %d
             AND post_id != %d
             LIMIT 5",
            $group_id, $product->get_id()
        ) );

        if ( empty( $siblings ) ) return;

        $all_products = array_merge( [ $product->get_id() ], $siblings );
        $products = array_filter( array_map( 'wc_get_product', $all_products ) );
        if ( count( $products ) < 2 ) return;

        // Atributos a comparar: precio, rating, stock + atributos comunes.
        ?>
        <div class="ltms-comparison-table-wrap" style="overflow-x:auto;">
            <table class="ltms-comparison-table" style="width:100%;border-collapse:collapse;font-size:14px;">
                <thead>
                    <tr>
                        <th style="padding:12px 8px;text-align:left;border-bottom:2px solid #e5e7eb;background:#f9fafb;"><?php esc_html_e( 'Producto', 'ltms' ); ?></th>
                        <?php foreach ( $products as $p ) : ?>
                            <th style="padding:12px 8px;text-align:center;border-bottom:2px solid #e5e7eb;background:#f9fafb;">
                                <a href="<?php echo esc_url( $p->get_permalink() ); ?>" style="text-decoration:none;color:#2563eb;">
                                    <?php echo esc_html( $p->get_name() ); ?>
                                </a>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="padding:10px 8px;border-bottom:1px solid #e5e7eb;font-weight:600;"><?php esc_html_e( 'Imagen', 'ltms' ); ?></td>
                        <?php foreach ( $products as $p ) : ?>
                            <td style="padding:10px 8px;border-bottom:1px solid #e5e7eb;text-align:center;">
                                <a href="<?php echo esc_url( $p->get_permalink() ); ?>">
                                    <?php echo $p->get_image( 'thumbnail' ); // phpcs:ignore ?>
                                </a>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <td style="padding:10px 8px;border-bottom:1px solid #e5e7eb;font-weight:600;"><?php esc_html_e( 'Precio', 'ltms' ); ?></td>
                        <?php foreach ( $products as $p ) : ?>
                            <td style="padding:10px 8px;border-bottom:1px solid #e5e7eb;text-align:center;font-weight:700;color:#16a34a;">
                                <?php echo $p->get_price_html(); // phpcs:ignore ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <td style="padding:10px 8px;border-bottom:1px solid #e5e7eb;font-weight:600;"><?php esc_html_e( 'Valoración', 'ltms' ); ?></td>
                        <?php foreach ( $products as $p ) : $rating = (float) $p->get_average_rating(); ?>
                            <td style="padding:10px 8px;border-bottom:1px solid #e5e7eb;text-align:center;">
                                <?php echo wc_get_rating_html( $rating ); // phpcs:ignore ?>
                                <span style="font-size:11px;color:#6b7280;">(<?php echo esc_html( $p->get_rating_count() ); ?>)</span>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <td style="padding:10px 8px;border-bottom:1px solid #e5e7eb;font-weight:600;"><?php esc_html_e( 'Disponibilidad', 'ltms' ); ?></td>
                        <?php foreach ( $products as $p ) : $in_stock = $p->is_in_stock(); ?>
                            <td style="padding:10px 8px;border-bottom:1px solid #e5e7eb;text-align:center;">
                                <span style="color:<?php echo $in_stock ? '#16a34a' : '#dc2626'; ?>;">
                                    <?php echo esc_html( $in_stock ? __( 'En stock', 'ltms' ) : __( 'Agotado', 'ltms' ) ); ?>
                                </span>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <td style="padding:10px 8px;font-weight:600;"><?php esc_html_e( 'Acción', 'ltms' ); ?></td>
                        <?php foreach ( $products as $p ) : ?>
                            <td style="padding:10px 8px;text-align:center;">
                                <?php if ( $p->is_type( 'variable' ) ) : ?>
                                    <a href="<?php echo esc_url( $p->get_permalink() ); ?>" class="button alt" style="font-size:12px;padding:6px 12px;"><?php esc_html_e( 'Ver opciones', 'ltms' ); ?></a>
                                <?php else : ?>
                                    <a href="?add-to-cart=<?php echo esc_attr( $p->get_id() ); ?>" class="button alt ltms-compare-add-cart" style="font-size:12px;padding:6px 12px;"><?php esc_html_e( 'Agregar', 'ltms' ); ?></a>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Admin: meta box para asignar grupo comparable.
     */
    public static function render_comparable_meta(): void {
        global $post;
        $group_id = (int) get_post_meta( $post->ID, '_ltms_comparable_group', true );
        ?>
        <div class="options_group">
            <p class="form-field">
                <label for="_ltms_comparable_group"><?php esc_html_e( 'Grupo comparable (ID)', 'ltms' ); ?></label>
                <input type="number" name="_ltms_comparable_group" id="_ltms_comparable_group"
                       value="<?php echo esc_attr( $group_id ); ?>" placeholder="0" min="0" style="width:120px;" />
                <?php echo wc_help_tip( __( 'Asigna el mismo ID a productos que quieres que aparezcan en la tabla comparativa. Ej: 3 modelos de audífonos = grupo 1.', 'ltms' ) ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Guarda el meta de grupo comparable.
     */
    public static function save_comparable_meta( int $post_id ): void {
        if ( ! current_user_can( 'edit_product', $post_id ) ) return;
        $group = (int) ( $_POST['_ltms_comparable_group'] ?? 0 );
        if ( $group > 0 ) {
            update_post_meta( $post_id, '_ltms_comparable_group', $group );
        } else {
            delete_post_meta( $post_id, '_ltms_comparable_group' );
        }
    }
}
