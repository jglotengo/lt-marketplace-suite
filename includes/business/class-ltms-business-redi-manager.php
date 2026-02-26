<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Class LTMS_Business_Redi_Manager
 * Manages the ReDi (Reseller Distribution) product model.
 * Allows resellers to adopt vendor products and earn commissions on sales.
 */
class LTMS_Business_Redi_Manager {

    use LTMS_Logger_Aware;

    /**
     * Registers WooCommerce product field hooks and saves handlers.
     */
    public static function init(): void {
        add_action( 'woocommerce_product_options_general_product_data', [ __CLASS__, 'render_redi_product_fields' ] );
        add_action( 'woocommerce_process_product_meta', [ __CLASS__, 'save_redi_product_fields' ] );
    }

    /**
     * Returns true if the given product is a ReDi reseller product
     * (i.e. it has an origin product linked to it).
     *
     * @param int $product_id WC product ID.
     * @return bool
     */
    public static function is_redi_product( int $product_id ): bool {
        return (bool) get_post_meta( $product_id, '_ltms_redi_origin_product_id', true );
    }

    /**
     * Returns the origin (vendor) product ID for a ReDi reseller product.
     *
     * @param int $reseller_product_id Reseller's WC product ID.
     * @return int Origin product ID, or 0 if not found.
     */
    public static function get_origin_product_id( int $reseller_product_id ): int {
        return (int) get_post_meta( $reseller_product_id, '_ltms_redi_origin_product_id', true );
    }

    /**
     * Returns the origin vendor user ID for a ReDi reseller product.
     *
     * @param int $reseller_product_id Reseller's WC product ID.
     * @return int Origin vendor user ID, or 0 if not found.
     */
    public static function get_origin_vendor_id( int $reseller_product_id ): int {
        return (int) get_post_meta( $reseller_product_id, '_ltms_redi_origin_vendor_id', true );
    }

    /**
     * Returns the configured ReDi commission rate for an origin product.
     *
     * @param int $origin_product_id Origin vendor's product ID.
     * @return float Commission rate as a decimal (e.g. 0.15 for 15%).
     */
    public static function get_redi_rate( int $origin_product_id ): float {
        return (float) get_post_meta( $origin_product_id, '_ltms_redi_rate', true );
    }

    /**
     * Creates a reseller copy of an origin product and registers the ReDi agreement.
     *
     * Duplicates the origin WC product, sets all ReDi meta on the new product,
     * assigns it to the reseller vendor, and inserts a row into lt_redi_agreements.
     *
     * @param int   $reseller_id       Reseller vendor user ID.
     * @param int   $origin_product_id Origin vendor's product ID.
     * @param float $override_rate     If >= 0, overrides the origin product's rate.
     * @return int New reseller product ID.
     */
    public static function adopt_product( int $reseller_id, int $origin_product_id, float $override_rate = -1.0 ): int {
        $origin_product = wc_get_product( $origin_product_id );
        if ( ! $origin_product ) {
            LTMS_Core_Logger::error( 'REDI_ADOPT_PRODUCT_NOT_FOUND', sprintf( 'Origin product #%d not found', $origin_product_id ) );
            return 0;
        }

        // Determine effective ReDi rate
        $redi_rate = ( $override_rate >= 0.0 )
            ? $override_rate
            : self::get_redi_rate( $origin_product_id );

        // Duplicate the product
        $new_product = clone $origin_product;
        $new_product->set_id( 0 );
        $new_product->set_status( 'publish' );
        $new_product->set_date_created( null );
        $new_product->set_date_modified( null );
        $new_product->set_name( $origin_product->get_name() );
        $new_product->set_slug( '' ); // Let WP generate a unique slug
        $new_product_id = $new_product->save();

        if ( ! $new_product_id ) {
            LTMS_Core_Logger::error( 'REDI_ADOPT_SAVE_FAILED', sprintf( 'Failed to save reseller product copy of origin #%d', $origin_product_id ) );
            return 0;
        }

        // Set ReDi meta on new product
        update_post_meta( $new_product_id, '_ltms_redi_origin_product_id', $origin_product_id );
        update_post_meta( $new_product_id, '_ltms_redi_origin_vendor_id', self::get_origin_vendor_id_from_product( $origin_product_id ) );
        update_post_meta( $new_product_id, '_ltms_redi_rate', $redi_rate );
        update_post_meta( $new_product_id, '_ltms_vendor_id', $reseller_id );

        // Insert into lt_redi_agreements
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $wpdb->prefix . 'lt_redi_agreements',
            [
                'reseller_id'       => $reseller_id,
                'origin_product_id' => $origin_product_id,
                'origin_vendor_id'  => self::get_origin_vendor_id_from_product( $origin_product_id ),
                'reseller_product_id' => $new_product_id,
                'redi_rate'         => $redi_rate,
                'status'            => 'active',
                'created_at'        => LTMS_Utils::now_utc(),
                'updated_at'        => LTMS_Utils::now_utc(),
            ],
            [ '%d', '%d', '%d', '%d', '%f', '%s', '%s', '%s' ]
        );

        LTMS_Core_Logger::info(
            'REDI_PRODUCT_ADOPTED',
            sprintf(
                'Reseller #%d adopted origin product #%d → new product #%d (rate=%.4f)',
                $reseller_id, $origin_product_id, $new_product_id, $redi_rate
            )
        );

        return $new_product_id;
    }

    /**
     * Detects all ReDi items in an order and returns structured data for each.
     *
     * @param \WC_Order $order The WooCommerce order to inspect.
     * @return array Array of ReDi item data arrays.
     */
    public static function detect_redi_items( \WC_Order $order ): array {
        $redi_items = [];

        foreach ( $order->get_items() as $item_id => $item ) {
            $product_id = (int) $item->get_product_id();

            $origin_product_id = (int) get_post_meta( $product_id, '_ltms_redi_origin_product_id', true );
            if ( ! $origin_product_id ) {
                continue;
            }

            $origin_vendor_id = (int) get_post_meta( $product_id, '_ltms_redi_origin_vendor_id', true );
            $redi_rate        = (float) get_post_meta( $product_id, '_ltms_redi_rate', true );
            $reseller_id      = (int) get_post_meta( $product_id, '_ltms_vendor_id', true );
            $gross            = (float) $item->get_total();
            $agreement_id     = self::get_agreement_id( $reseller_id, $origin_product_id );

            $redi_items[] = [
                'item_id'           => (int) $item_id,
                'product_id'        => $product_id,
                'gross'             => $gross,
                'reseller_id'       => $reseller_id,
                'origin_product_id' => $origin_product_id,
                'origin_vendor_id'  => $origin_vendor_id,
                'redi_rate'         => $redi_rate,
                'agreement_id'      => $agreement_id,
            ];
        }

        return $redi_items;
    }

    /**
     * Reduces origin product stock for each ReDi item in the order.
     *
     * @param \WC_Order $order The WooCommerce order.
     * @return void
     */
    public static function deduct_origin_stock( \WC_Order $order ): void {
        foreach ( $order->get_items() as $item ) {
            $product_id        = (int) $item->get_product_id();
            $origin_product_id = (int) get_post_meta( $product_id, '_ltms_redi_origin_product_id', true );

            if ( ! $origin_product_id ) {
                continue;
            }

            $origin_product = wc_get_product( $origin_product_id );
            if ( ! $origin_product || ! $origin_product->managing_stock() ) {
                continue;
            }

            $current_stock = (int) $origin_product->get_stock_quantity();
            $qty_sold      = (int) $item->get_quantity();
            $new_stock     = max( 0, $current_stock - $qty_sold );

            $origin_product->set_stock_quantity( $new_stock );
            $origin_product->save();

            LTMS_Core_Logger::info(
                'REDI_STOCK_DEDUCTED',
                sprintf(
                    'Origin product #%d stock: %d → %d (order #%d, qty=%d)',
                    $origin_product_id, $current_stock, $new_stock,
                    $order->get_id(), $qty_sold
                )
            );
        }
    }

    /**
     * Retrieves the active ReDi agreement ID for a reseller + origin product pair.
     *
     * @param int $reseller_id       Reseller vendor user ID.
     * @param int $origin_product_id Origin vendor's product ID.
     * @return int Agreement ID, or 0 if not found.
     */
    private static function get_agreement_id( int $reseller_id, int $origin_product_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'lt_redi_agreements';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE reseller_id = %d AND origin_product_id = %d AND status = 'active' LIMIT 1",
            $reseller_id,
            $origin_product_id
        ) );
        return (int) $id;
    }

    /**
     * Looks up the vendor user ID who owns an origin product via post meta.
     *
     * @param int $origin_product_id Origin product ID.
     * @return int Vendor user ID.
     */
    private static function get_origin_vendor_id_from_product( int $origin_product_id ): int {
        return (int) get_post_meta( $origin_product_id, '_ltms_vendor_id', true );
    }

    /**
     * Renders ReDi fields in the WooCommerce General product data tab.
     */
    private static function render_redi_product_fields(): void {
        global $post;
        $product_id   = $post ? (int) $post->ID : 0;
        $redi_enabled = get_post_meta( $product_id, '_ltms_redi_enabled', true );
        $redi_rate    = get_post_meta( $product_id, '_ltms_redi_rate', true );
        ?>
        <div class="options_group ltms-redi-options">
            <p class="form-field _ltms_redi_enabled_field">
                <label for="_ltms_redi_enabled">
                    <?php esc_html_e( 'Habilitar ReDi', 'ltms' ); ?>
                </label>
                <input
                    type="checkbox"
                    id="_ltms_redi_enabled"
                    name="_ltms_redi_enabled"
                    value="yes"
                    <?php checked( $redi_enabled, 'yes' ); ?>
                    style="width:auto;"
                />
                <span class="description">
                    <?php esc_html_e( 'Permite que revendedores distribuyan este producto a través del programa ReDi.', 'ltms' ); ?>
                </span>
            </p>
            <p class="form-field _ltms_redi_rate_field">
                <label for="_ltms_redi_rate">
                    <?php esc_html_e( 'Tasa de Comisión ReDi', 'ltms' ); ?>
                </label>
                <input
                    type="number"
                    id="_ltms_redi_rate"
                    name="_ltms_redi_rate"
                    value="<?php echo esc_attr( $redi_rate ); ?>"
                    placeholder="0.15"
                    step="0.01"
                    min="0"
                    max="1"
                    class="short"
                />
                <span class="description">
                    <?php esc_html_e( 'Porcentaje del precio de venta que recibirá el revendedor (ej: 0.15 = 15%).', 'ltms' ); ?>
                </span>
            </p>
        </div>
        <?php
    }

    /**
     * Saves ReDi product fields from the WooCommerce product data tab.
     *
     * @param int $post_id WC product post ID.
     */
    private static function save_redi_product_fields( int $post_id ): void {
        $redi_enabled = isset( $_POST['_ltms_redi_enabled'] ) && sanitize_key( $_POST['_ltms_redi_enabled'] ) === 'yes' // phpcs:ignore
            ? 'yes'
            : 'no';

        update_post_meta( $post_id, '_ltms_redi_enabled', $redi_enabled );

        if ( isset( $_POST['_ltms_redi_rate'] ) ) { // phpcs:ignore
            $rate = (float) sanitize_text_field( wp_unslash( $_POST['_ltms_redi_rate'] ) ); // phpcs:ignore
            $rate = max( 0.0, min( 1.0, $rate ) );
            update_post_meta( $post_id, '_ltms_redi_rate', $rate );
        }
    }
}
