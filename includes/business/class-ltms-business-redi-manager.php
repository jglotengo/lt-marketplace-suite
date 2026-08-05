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
        // Hook de cola bulk-adopt (registrado aquí para que el Cron Manager pueda
        // dispararlo sin depender de otro punto de arranque).
        add_action( 'ltms_redi_bulk_adopt_queue', [ __CLASS__, 'process_bulk_adopt_queue' ] );

        // AUDIT-REDI-UX-GAPS GAP-10 FIX: AJAX endpoints para soft pause/resume.
        add_action( 'wp_ajax_ltms_redi_soft_pause',  [ __CLASS__, 'ajax_soft_pause' ] );
        add_action( 'wp_ajax_ltms_redi_soft_resume', [ __CLASS__, 'ajax_soft_resume' ] );
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
     * AUDIT-REDI-UX-GAPS GAP-3 FIX: obtiene los productos ReDi del vendor
     * actual que están habilitados como origin (con _ltms_redi_enabled='yes'
     * o _ltms_redi_paused='yes').
     *
     * @param int $vendor_id
     * @param int $limit
     * @param int $offset
     * @return array Array de productos con stats de adopción.
     */
    public static function get_origin_products_for_vendor( int $vendor_id, int $limit = 50, int $offset = 0 ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $products = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_title, p.post_status,
                    pm_redi.meta_value AS redi_enabled,
                    pm_rate.meta_value AS redi_rate,
                    pm_paused.meta_value AS redi_paused
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_vendor ON pm_vendor.post_id = p.ID AND pm_vendor.meta_key = '_ltms_vendor_id' AND pm_vendor.meta_value = %d
             LEFT JOIN {$wpdb->postmeta} pm_redi ON pm_redi.post_id = p.ID AND pm_redi.meta_key = '_ltms_redi_enabled'
             LEFT JOIN {$wpdb->postmeta} pm_rate ON pm_rate.post_id = p.ID AND pm_rate.meta_key = '_ltms_redi_rate'
             LEFT JOIN {$wpdb->postmeta} pm_paused ON pm_paused.post_id = p.ID AND pm_paused.meta_key = '_ltms_redi_paused'
             WHERE p.post_type = 'product'
               AND p.post_status IN ('publish', 'private')
               AND (pm_redi.meta_value = 'yes' OR pm_paused.meta_value = 'yes')
             ORDER BY p.post_modified DESC
             LIMIT %d OFFSET %d",
            $vendor_id, $limit, $offset
        ), ARRAY_A );

        if ( empty( $products ) ) {
            return [];
        }

        $agreements_table = $wpdb->prefix . 'lt_redi_agreements';
        $commissions_table = $wpdb->prefix . 'lt_redi_commissions';

        foreach ( $products as &$p ) {
            $pid = (int) $p['ID'];

            // Active agreements count + reseller names.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $agreements = $wpdb->get_results( $wpdb->prepare(
                "SELECT a.reseller_vendor_id, a.status, u.display_name AS reseller_name
                 FROM `{$agreements_table}` a
                 LEFT JOIN {$wpdb->users} u ON u.ID = a.reseller_vendor_id
                 WHERE a.origin_product_id = %d",
                $pid
            ), ARRAY_A );

            $p['active_agreements']  = 0;
            $p['paused_agreements']  = 0;
            $p['resellers']          = [];

            if ( $agreements ) {
                foreach ( $agreements as $a ) {
                    if ( $a['status'] === 'active' ) {
                        $p['active_agreements']++;
                    } elseif ( $a['status'] === 'paused' ) {
                        $p['paused_agreements']++;
                    }
                    $p['resellers'][] = [
                        'vendor_id' => (int) $a['reseller_vendor_id'],
                        'name'      => $a['reseller_name'] ?: __( 'Usuario eliminado', 'ltms' ),
                        'status'    => $a['status'],
                    ];
                }
            }

            // Origin commissions total.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $p['total_origin_commission'] = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(origin_vendor_net), 0) FROM `{$commissions_table}`
                 WHERE origin_vendor_id = %d AND status = 'paid'",
                $vendor_id
            ) );

            // Origin commissions this month.
            $p['month_origin_commission'] = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(origin_vendor_net), 0) FROM `{$commissions_table}`
                 WHERE origin_vendor_id = %d AND status = 'paid'
                   AND created_at >= %s",
                $vendor_id,
                gmdate( 'Y-m-01 00:00:00' )
            ) );

            // Last sale date.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $p['last_sale'] = $wpdb->get_var( $wpdb->prepare(
                "SELECT MAX(created_at) FROM `{$commissions_table}`
                 WHERE origin_vendor_id = %d AND status = 'paid'",
                $vendor_id
            ) );

            $p['is_paused'] = $p['redi_paused'] === 'yes';
        }

        return $products;
    }

    /**
     * AUDIT-REDI-UX-GAPS GAP-3 FIX: cuenta cuántos productos ReDi origin
     * tiene el vendor (para el KPI del dashboard).
     *
     * @param int $vendor_id
     * @return int
     */
    public static function count_origin_products_for_vendor( int $vendor_id ): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_vendor ON pm_vendor.post_id = p.ID AND pm_vendor.meta_key = '_ltms_vendor_id' AND pm_vendor.meta_value = %d
             INNER JOIN {$wpdb->postmeta} pm_redi ON pm_redi.post_id = p.ID AND pm_redi.meta_key = '_ltms_redi_enabled' AND pm_redi.meta_value = 'yes'
             WHERE p.post_type = 'product' AND p.post_status = 'publish'",
            $vendor_id
        ) );
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

        // Guard (a): product must explicitly be ReDi-enabled by its owner
        $redi_enabled = get_post_meta( $origin_product_id, '_ltms_redi_enabled', true );
        if ( 'yes' !== $redi_enabled ) {
            LTMS_Core_Logger::error(
                'REDI_ADOPT_NOT_ENABLED',
                sprintf( 'Origin product #%d is not ReDi-enabled (_ltms_redi_enabled != yes)', $origin_product_id )
            );
            return 0;
        }

        // Guard (b): prevent self-adoption (vendor adopting their own product)
        $origin_vendor_id = (int) get_post_meta( $origin_product_id, '_ltms_vendor_id', true );
        if ( 0 === $origin_vendor_id ) {
            $origin_vendor_id = (int) get_post_field( 'post_author', $origin_product_id );
        }
        if ( $origin_vendor_id === $reseller_id ) {
            LTMS_Core_Logger::error(
                'REDI_ADOPT_SELF_ADOPTION',
                sprintf( 'Vendor #%d attempted to self-adopt product #%d', $reseller_id, $origin_product_id )
            );
            return 0;
        }

        // Guard (c): prevent duplicate active agreements for the same origin product
        // FASE5 P0 FIX (TOCTOU): wrap the check+insert in a transaction with
        // SELECT FOR UPDATE to prevent two concurrent adopt requests from both
        // passing the check and both inserting duplicate active agreements.
        global $wpdb;
        $wpdb->query( 'START TRANSACTION' );
        $existing_agreement = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prepare(
                "SELECT id FROM `{$wpdb->prefix}lt_redi_agreements`
                 WHERE reseller_vendor_id = %d AND origin_product_id = %d AND status = 'active'
                 LIMIT 1
                 FOR UPDATE",
                $reseller_id,
                $origin_product_id
            )
        );
        if ( $existing_agreement ) {
            $wpdb->query( 'ROLLBACK' );
            LTMS_Core_Logger::error(
                'REDI_ADOPT_DUPLICATE',
                sprintf(
                    'Vendor #%d already has active agreement #%d for origin product #%d',
                    $reseller_id, $existing_agreement, $origin_product_id
                )
            );
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
            // CICLO15-P1-RM-037 FIX: si el save() del reseller product falla,
            // habia que retornar 0 PERO tambien habia que hacer ROLLBACK de la
            // transaccion START TRANSACTION (linea 237) que tenia el SELECT
            // FOR UPDATE lock. Sin el ROLLBACK, la transaccion quedaba abierta
            // -> el row lock se mantenía hasta el timeout del MySQL server
            // (50s por default innodb_lock_wait_timeout), bloqueando cualquier
            // otra adopt_product concurrente para el mismo reseller+origin
            // (exactamente el TOCTOU que se queria prevenir) + el objeto
            // $wpdb quedaba en modo transaccional para queries posteriores
            // no relacionados (race condition extensivo). Patron
            // recurrente: rollback en TODOS los paths de fallo tras
            // START TRANSACTION, no solo en el path del guard check.
            $wpdb->query( 'ROLLBACK' );
            LTMS_Core_Logger::error( 'REDI_ADOPT_SAVE_FAILED', sprintf( 'Failed to save reseller product copy of origin #%d (transaction rolled back)', $origin_product_id ) );
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
        // CICLO15-P1-RM-036 FIX: el INSERT en lt_redi_agreements no se
        // verificaba. Si fallaba silenciosamente (false = error DB con
        // last_error), el COMMIT en linea 313 (post-fix) se ejecutaba
        // INCONDICIONALMENTE -> el reseller quedaba con un producto
        // copia + metas _ltms_redi_* pero SIN el acuerdo registrado en
        // lt_redi_agreements. Esto rompe la trazabilidad: get_agreement_id
        // (linea 401) retorna 0 -> detect_redi_items registra
        // agreement_id=0 en lt_redi_commissions -> la comision no se
        // atribuye correctamente al acuerdo que la genero, exactamente
        // el bug que el comentario "AUDIT-RD-BK RD-1 FIX" (linea 404)
        // del get_agreement_id intenta prevenir (columna
        // reseller_vendor_id vs reseller_id). Patron recurrente
        // documentado en Ciclos 5-14 (verificacion de retorno
        // UPDATE/INSERT). Fix: capturar retorno + check false === +
        // ROLLBACK (no COMMIT) + log critico con SQL de reconciliacion
        // manual + return 0 (no continuar al exit path exitoso).
        $insert_result = $wpdb->insert(
            $wpdb->prefix . 'lt_redi_agreements',
            [
                'reseller_vendor_id'  => $reseller_id,
                'origin_product_id'   => $origin_product_id,
                'origin_vendor_id'    => $origin_vendor_id,
                'reseller_product_id' => $new_product_id,
                'redi_rate'           => $redi_rate,
                'status'              => 'active',
                'created_at'          => LTMS_Utils::now_utc(),
                'updated_at'          => LTMS_Utils::now_utc(),
            ],
            [ '%d', '%d', '%d', '%d', '%f', '%s', '%s', '%s' ]
        );

        if ( false === $insert_result ) {
            // ROLLBACK de la transaccion + log critico con SQL de
            // reconciliacion manual para que el admin pueda insertar
            // el acuerdo manualmente (producto ya creado + metas ya
            // seteadas, solo falta el agreement row).
            $wpdb->query( 'ROLLBACK' );
            LTMS_Core_Logger::critical(
                'REDI_ADOPT_AGREEMENT_INSERT_FAILED',
                sprintf(
                    'Failed to insert agreement for reseller #%d → origin #%d → new product #%d (rate=%.4f). last_error=%s. El reseller product YA fue creado + metas seteadas pero SIN agreement row -> get_agreement_id retornara 0 -> comisiones futuras no atribuidas. Reconciliacion manual: INSERT INTO %slt_redi_agreements (reseller_vendor_id, origin_product_id, origin_vendor_id, reseller_product_id, redi_rate, status, created_at, updated_at) VALUES (%d, %d, %d, %d, %.4f, \'active\', UTC_TIMESTAMP(), UTC_TIMESTAMP()).',
                    $reseller_id,
                    $origin_product_id,
                    $new_product_id,
                    $redi_rate,
                    $wpdb->last_error ?: '(no error)',
                    $wpdb->prefix,
                    $reseller_id,
                    $origin_product_id,
                    $origin_vendor_id,
                    $new_product_id,
                    $redi_rate
                ),
                [
                    'reseller_id'        => $reseller_id,
                    'origin_product_id'  => $origin_product_id,
                    'origin_vendor_id'   => $origin_vendor_id,
                    'new_product_id'     => $new_product_id,
                    'redi_rate'          => $redi_rate,
                    'last_error'         => $wpdb->last_error ?: '(no error)',
                    'insert_result'      => var_export( $insert_result, true ),
                ]
            );
            return 0;
        }

        LTMS_Core_Logger::info(
            'REDI_PRODUCT_ADOPTED',
            sprintf(
                'Reseller #%d adopted origin product #%d → new product #%d (rate=%.4f)',
                $reseller_id, $origin_product_id, $new_product_id, $redi_rate
            )
        );

        // FASE5 P0 FIX: commit the transaction that holds the FOR UPDATE lock.
        $wpdb->query( 'COMMIT' );

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
        // AUDIT-RD-BK RD-1 FIX: la columna real de la tabla lt_redi_agreements es
        // `reseller_vendor_id` (ver class-ltms-db-migrations.php linea 550), no
        // `reseller_id`. El query anterior referenciaba una columna inexistente,
        // lo que hacia que $wpdb->get_var() devolviera NULL (error SQL silenciado
        // por $wpdb->last_error) y agreement_id siempre quedara en 0 en los
        // registros de lt_redi_commissions — rompiendo la trazabilidad de qué
        // acuerdo generó cada comisión e impidiendo que el guardián RD-4 (skip
        // cuando no hay acuerdo activo) funcione.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $id = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE reseller_vendor_id = %d AND origin_product_id = %d AND status = 'active' LIMIT 1",
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
     *
     * AUDIT-REDI-UX-GAPS GAP-4 FIX: el help text del checkbox + rate ahora
     * muestra el rango configurado (mín/máx) igual que el frontend dashboard.
     * Antes el wp-admin mostraba "ej: 0.15 = 15%" sin indicar los límites,
     * mientras el frontend mostraba "(mín 5%, máx 40%)" — inconsistencia
     * que confundía a los vendors que usaban ambas interfaces.
     */
    public static function render_redi_product_fields(): void {
        global $post;
        $product_id   = $post ? (int) $post->ID : 0;
        $redi_enabled = get_post_meta( $product_id, '_ltms_redi_enabled', true );
        $redi_rate    = get_post_meta( $product_id, '_ltms_redi_rate', true );

        // AUDIT-REDI-UX-GAPS GAP-4: obtener rango configurado para mostrarlo.
        $min_pct = round( (float) get_option( 'ltms_redi_min_rate', 0.05 ) * 100, 1 );
        $max_pct = round( (float) get_option( 'ltms_redi_max_rate', 0.40 ) * 100, 1 );
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
                    <?php esc_html_e( 'Permite que revendedores distribuyan este producto a través del programa ReDi. Tú mantienes el inventario y envías al cliente; el revendedor recibe una comisión automática.', 'ltms' ); ?>
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
                    min="<?php echo esc_attr( $min_pct / 100 ); ?>"
                    max="<?php echo esc_attr( $max_pct / 100 ); ?>"
                    class="short"
                />
                <span class="description">
                    <?php
                    printf(
                        /* translators: 1: min percentage, 2: max percentage */
                        esc_html__( 'Porcentaje del precio de venta que recibe el revendedor (mín %1$s%%, máx %2$s%%). Ej: 0.15 = 15%%.', 'ltms' ),
                        $min_pct,
                        $max_pct
                    );
                    ?>
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
    public static function save_redi_product_fields( int $post_id ): void {
        $redi_enabled = isset( $_POST['_ltms_redi_enabled'] ) && sanitize_key( $_POST['_ltms_redi_enabled'] ) === 'yes' // phpcs:ignore
            ? 'yes'
            : 'no';

        update_post_meta( $post_id, '_ltms_redi_enabled', $redi_enabled );

        if ( isset( $_POST['_ltms_redi_rate'] ) ) { // phpcs:ignore
            $rate = (float) sanitize_text_field( wp_unslash( $_POST['_ltms_redi_rate'] ) ); // phpcs:ignore
            // CS-08: clampar dentro del rango definido por el marketplace (valores en %)
            $min_pct = (float) get_option( 'ltms_redi_min_rate', 5 );
            $max_pct = (float) get_option( 'ltms_redi_max_rate', 40 );
            // La tasa se almacena como decimal (0.15), pero la opción admin está en % (15)
            $rate = max( $min_pct / 100, min( $max_pct / 100, $rate ) );
            update_post_meta( $post_id, '_ltms_redi_rate', $rate );
        }
    }

    /**
     * Validates and clamps a ReDi rate (decimal 0–1) against the marketplace-defined range.
     * Used by the vendor frontend AJAX to enforce the same rules.
     *
     * @param float $rate Proposed rate as decimal (e.g. 0.15).
     * @return float Clamped rate.
     */
    public static function clamp_redi_rate( float $rate ): float {
        $min_raw = (float) get_option( 'ltms_redi_min_rate', 5 );
        $max_raw = (float) get_option( 'ltms_redi_max_rate', 40 );

        // Normalizar a decimal: si el valor guardado es decimal (<1) ya es la tasa decimal.
        // Si es pct (>=1), dividir por 100. Ej: 0.05→0.05 | 5→0.05 | 0.4→0.4 | 40→0.40
        $min_decimal = ( $min_raw < 1 ) ? $min_raw : $min_raw / 100;
        $max_decimal = ( $max_raw < 1 ) ? $max_raw : $max_raw / 100;

        return max( $min_decimal, min( $max_decimal, $rate ) );
    }

    // ========================================================================
    // AUDIT-REDI-UX-GAPS GAP-10 FIX: Soft pause / resume + reseller notification
    // ========================================================================

    /**
     * Pausa soft de un producto origen ReDi: NO elimina los acuerdos ni las
     * copias del revendedor — solo los marca como inactivos temporalmente y
     * notifica a cada revendedor afectado vía lt_notifications + email.
     *
     * Esta función es la implementación canónica invocada por ajax_soft_pause()
     * y por el Incident Manager (cuando un incidente tipo stockout requiere
     * pausar la distribución). Es idempotente: si el producto ya está pausado,
     * no hace nada y devuelve skipped=true.
     *
     * @param int $origin_product_id Origin vendor product ID.
     * @return array {
     *     @type bool   $success
     *     @type string $message
     *     @type int    $agreements_affected
     *     @type bool   $skipped    True si el producto ya estaba pausado.
     * }
     */
    public static function soft_pause_redi( int $origin_product_id ): array {
        $origin_product = wc_get_product( $origin_product_id );
        if ( ! $origin_product ) {
            return [
                'success'              => false,
                'message'              => sprintf( 'Origin product #%d not found', $origin_product_id ),
                'agreements_affected'  => 0,
                'skipped'              => false,
            ];
        }

        // Idempotencia: si ya está pausado, no hacer nada.
        if ( get_post_meta( $origin_product_id, '_ltms_redi_paused', true ) === 'yes' ) {
            return [
                'success'              => true,
                'message'              => sprintf( 'Origin product #%d is already paused', $origin_product_id ),
                'agreements_affected'  => 0,
                'skipped'              => true,
            ];
        }

        $reason = sprintf(
            /* translators: %s: product name */
            __( 'Pausa soft por incidente / stockout del producto origen #%d', 'ltms' ),
            $origin_product_id
        );

        // Marcar producto origen como pausado y disparar el handler de visibilidad
        // que se encarga de pausar acuerdos + copias de revendedor + notificaciones.
        update_post_meta( $origin_product_id, '_ltms_redi_paused', 'yes' );
        update_post_meta( $origin_product_id, '_ltms_redi_paused_at', LTMS_Utils::now_utc() );
        update_post_meta( $origin_product_id, '_ltms_redi_paused_reason', $reason );

        $affected = self::on_product_visibility_change( $origin_product_id, 'paused', $reason );

        LTMS_Core_Logger::info(
            'REDI_SOFT_PAUSED',
            sprintf(
                'Origin product #%d soft-paused: %d agreements affected. Reason: %s',
                $origin_product_id, $affected, $reason
            )
        );

        return [
            'success'              => true,
            'message'              => sprintf(
                /* translators: %d: number of agreements affected */
                __( 'Producto origen #%d pausado. %d acuerdos ReDi afectados.', 'ltms' ),
                $origin_product_id, $affected
            ),
            'agreements_affected'  => $affected,
            'skipped'              => false,
        ];
    }

    /**
     * Re-anuda un producto origen ReDi previamente pausado con soft_pause_redi().
     *
     * - Limpia el flag _ltms_redi_paused.
     * - Re-activa todos los acuerdos en estado 'paused'.
     * - Re-publica cada copia del revendedor (stock status = instock, visibility = visible).
     * - Notifica a cada revendedor afectado vía lt_notifications + email.
     *
     * @param int $origin_product_id Origin vendor product ID.
     * @return array {
     *     @type bool   $success
     *     @type string $message
     *     @type int    $agreements_affected
     *     @type bool   $skipped
     * }
     */
    public static function soft_resume_redi( int $origin_product_id ): array {
        $origin_product = wc_get_product( $origin_product_id );
        if ( ! $origin_product ) {
            return [
                'success'              => false,
                'message'              => sprintf( 'Origin product #%d not found', $origin_product_id ),
                'agreements_affected'  => 0,
                'skipped'              => false,
            ];
        }

        // Idempotencia: si no estaba pausado, no hacer nada.
        if ( get_post_meta( $origin_product_id, '_ltms_redi_paused', true ) !== 'yes' ) {
            return [
                'success'              => true,
                'message'              => sprintf( 'Origin product #%d is not paused', $origin_product_id ),
                'agreements_affected'  => 0,
                'skipped'              => true,
            ];
        }

        global $wpdb;
        $agree_table = $wpdb->prefix . 'lt_redi_agreements';

        // Listar acuerdos pausados para este producto origen ANTES de re-activarlos,
        // para poder notificar a cada revendedor.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $paused_agreements = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, reseller_vendor_id, reseller_product_id FROM `{$agree_table}`
                 WHERE origin_product_id = %d AND status = 'paused'
                 ORDER BY id ASC",
                $origin_product_id
            )
        );

        $origin_name = $origin_product->get_name();
        $affected    = 0;

        foreach ( $paused_agreements as $agreement ) {
            $agreement_id       = (int) $agreement->id;
            $reseller_id        = (int) $agreement->reseller_vendor_id;
            $reseller_product_id = (int) $agreement->reseller_product_id;

            // 1. Re-activar el acuerdo.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            // CICLO15-P1-RM-038 FIX: el UPDATE de re-activar el acuerdo no se
            // verificaba. Si fallaba silenciosamente (false = error DB con
            // last_error), el acuerdo quedaba en status='paused' PERO el
            // reseller_product se re-publicaba (set_stock_status instock +
            // set_status publish + save, lineas 693-695) y la notificacion
            // de resume se enviaba (linea 700) -> el reseller recibe email
            // "ReDi reanudado", ve su producto publicado de nuevo, pero el
            // acuerdo sigue 'paused' -> siguientes ventas no generan comision
            // a pesar de ser visibles y vendibles. Inconsistencia silenciosa
            // entre lo que el reseller ve (producto activo, notificacion
            // positiva) y lo que el sistema sabe (acuerdo pausado, sin
            // comision). Patron recurrente documentado en Ciclos 5-14.
            // Fix: capturar $reactivated + check false === log critico
            // REDI_RESUME_AGREEMENT_REACTIVATE_FAILED con SQL de
            // reconciliacion + continue (NO ejecutar el re-publicar del
            // producto ni la notificacion al reseller para no mentirle
            // sobre el estado del acuerdo).
            $reactivated = $wpdb->update(
                $agree_table,
                [
                    'status'     => 'active',
                    'updated_at' => LTMS_Utils::now_utc(),
                ],
                [ 'id' => $agreement_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );

            if ( false === $reactivated ) {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::critical(
                        'REDI_RESUME_AGREEMENT_REACTIVATE_FAILED',
                        sprintf(
                            'Failed to re-activate agreement #%d for origin #%d → reseller #%d. last_error=%s. El acuerdo sigue paused - NO se re-publica el producto reseller #%d ni se notifica al reseller (no mentir sobre estado real). Reconciliacion manual: UPDATE %slt_redi_agreements SET status=\'active\', updated_at=UTC_TIMESTAMP() WHERE id=%d.',
                            $agreement_id,
                            $origin_product_id,
                            $reseller_id,
                            $wpdb->last_error ?: '(no error)',
                            $reseller_product_id,
                            $wpdb->prefix,
                            $agreement_id
                        ),
                        [
                            'agreement_id'        => $agreement_id,
                            'origin_product_id'   => $origin_product_id,
                            'reseller_id'         => $reseller_id,
                            'reseller_product_id' => $reseller_product_id,
                            'last_error'          => $wpdb->last_error ?: '(no error)',
                            'reactivated'         => var_export( $reactivated, true ),
                        ]
                    );
                }
                continue;
            }

            // 2. Re-publicar la copia del revendedor (stock status instock + visible).
            if ( $reseller_product_id ) {
                $reseller_product = wc_get_product( $reseller_product_id );
                if ( $reseller_product ) {
                    $reseller_product->set_stock_status( 'instock' );
                    $reseller_product->set_status( 'publish' );
                    $reseller_product->save();
                }
            }

            // 3. Notificar al revendedor.
            self::notify_reseller_redi_resumed( $reseller_id, $origin_name );

            $affected++;
        }

        // Limpiar flags de pausa en el producto origen.
        delete_post_meta( $origin_product_id, '_ltms_redi_paused' );
        delete_post_meta( $origin_product_id, '_ltms_redi_paused_at' );
        delete_post_meta( $origin_product_id, '_ltms_redi_paused_reason' );

        LTMS_Core_Logger::info(
            'REDI_SOFT_RESUMED',
            sprintf(
                'Origin product #%d soft-resumed: %d agreements re-activated.',
                $origin_product_id, $affected
            )
        );

        return [
            'success'              => true,
            'message'              => sprintf(
                /* translators: %d: number of agreements re-activated */
                __( 'Producto origen #%d re-anudado. %d acuerdos ReDi re-activados.', 'ltms' ),
                $origin_product_id, $affected
            ),
            'agreements_affected'  => $affected,
            'skipped'              => false,
        ];
    }

    /**
     * Handler disparado cuando un producto origen cambia de visibilidad / estado
     * (por ejemplo: el vendedor lo marca outofstock, lo pasa a private/draft,
     * o se invoca soft_pause_redi).
     *
     * AUDIT-REDI-UX-GAPS GAP-10 FIX: además de pausar los acuerdos y ocultar
     * las copias del revendedor, ahora TAMBIÉN notifica a cada revendedor
     * afectado vía lt_notifications + email para que sepa que la distribución
     * del producto está temporalmente suspendida.
     *
     * @param int    $origin_product_id Origin vendor product ID.
     * @param string $new_status        Estado nuevo del producto (paused|hidden|outofstock|visible).
     * @param string $reason            Motivo legible (se incluye en la notificación).
     * @return int Número de acuerdos afectados.
     */
    public static function on_product_visibility_change( int $origin_product_id, string $new_status, string $reason = '' ): int {
        global $wpdb;
        $agree_table = $wpdb->prefix . 'lt_redi_agreements';

        // Listar acuerdos activos ANTES de pausarlos, para tener la lista de
        // revendedores afectados a los que notificar.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $active_agreements = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, reseller_vendor_id, reseller_product_id FROM `{$agree_table}`
                 WHERE origin_product_id = %d AND status = 'active'
                 ORDER BY id ASC",
                $origin_product_id
            )
        );

        if ( empty( $active_agreements ) ) {
            return 0;
        }

        $origin_product = wc_get_product( $origin_product_id );
        $origin_name    = $origin_product ? $origin_product->get_name() : sprintf( '#%d', $origin_product_id );
        $affected       = 0;

        foreach ( $active_agreements as $agreement ) {
            $agreement_id        = (int) $agreement->id;
            $reseller_id         = (int) $agreement->reseller_vendor_id;
            $reseller_product_id = (int) $agreement->reseller_product_id;

            // 1. Pausar el acuerdo (status='paused' — NO se elimina, para permitir re-anudar).
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            // CICLO15-P1-RM-039 FIX: el UPDATE de pausar el acuerdo no se
            // verificaba. Si fallaba silenciosamente (false = error DB con
            // last_error), el acuerdo quedaba en status='active' PERO la
            // copia del reseller se marcaba outofstock + private (lineas
            // 793-800) y la notificacion de pausa se enviaba al reseller
            // (linea 805) -> el reseller recibe "ReDi pausado" pero el
            // acuerdo sigue activo -> si el stock se restaura despues, el
            // reseller no puede vender (producto private) pero el acuerdo
            // nunca nego la comision -> en el siguiente resume_redi el
            // acuerdo no esta en la lista de paused (status='active' no
            // entry en el SELECT WHERE status='paused'), asi que no se
            // re-procesa -> la pausa fue cosmética en el producto pero no
            // en el acuerdo. Patron recurrente Ciclos 5-14. Mismo fix
            // estandar: capturar + check false === + log critico con SQL
            // de reconciliacion + continue (NO marcar el reseller_product
            // outofstock/private ni notificar al reseller).
            $paused = $wpdb->update(
                $agree_table,
                [
                    'status'     => 'paused',
                    'updated_at' => LTMS_Utils::now_utc(),
                ],
                [ 'id' => $agreement_id ],
                [ '%s', '%s' ],
                [ '%d' ]
            );

            if ( false === $paused ) {
                if ( class_exists( 'LTMS_Core_Logger' ) ) {
                    LTMS_Core_Logger::critical(
                        'REDI_PAUSE_AGREEMENT_FAILED',
                        sprintf(
                            'Failed to pause agreement #%d for origin #%d → reseller #%d. last_error=%s. El acuerdo sigue active - NO se oculta el producto reseller #%d ni se notifica al reseller (no mentir sobre estado real). Reconciliacion manual: UPDATE %slt_redi_agreements SET status=\'paused\', updated_at=UTC_TIMESTAMP() WHERE id=%d.',
                            $agreement_id,
                            $origin_product_id,
                            $reseller_id,
                            $wpdb->last_error ?: '(no error)',
                            $reseller_product_id,
                            $wpdb->prefix,
                            $agreement_id
                        ),
                        [
                            'agreement_id'        => $agreement_id,
                            'origin_product_id'   => $origin_product_id,
                            'reseller_id'         => $reseller_id,
                            'reseller_product_id' => $reseller_product_id,
                            'last_error'          => $wpdb->last_error ?: '(no error)',
                            'paused'              => var_export( $paused, true ),
                        ]
                    );
                }
                continue;
            }

            // 2. Marcar la copia del revendedor como outofstock y privada
            //    (sigue existiendo para no romper órdenes previas, pero no se
            //    muestra en la tienda pública).
            if ( $reseller_product_id ) {
                $reseller_product = wc_get_product( $reseller_product_id );
                if ( $reseller_product ) {
                    $reseller_product->set_stock_status( 'outofstock' );
                    // Cambiar visibilidad a private si es un post-type que lo soporta
                    // (los productos WC son posts, así que wp_update_post aplica).
                    wp_update_post( [
                        'ID'          => $reseller_product_id,
                        'post_status' => 'private',
                    ] );
                    $reseller_product->save();
                }
            }

            // 3. Notificar al revendedor (GAP-10).
            self::notify_reseller_redi_paused(
                $reseller_id,
                $origin_product_id,
                $origin_name,
                $reason ?: sprintf(
                    /* translators: %s: new status */
                    __( 'Visibilidad del producto origen cambió a: %s', 'ltms' ),
                    $new_status
                )
            );

            $affected++;
        }

        return $affected;
    }

    /**
     * Notifica a un revendedor que un producto ReDi fue pausado (soft pause).
     * Inserta una fila en lt_notifications y envía un email al revendedor.
     *
     * AUDIT-REDI-UX-GAPS GAP-10 FIX.
     *
     * @param int    $reseller_id        Reseller vendor user ID.
     * @param int    $origin_product_id  Origin vendor product ID.
     * @param string $origin_product_name Origin vendor product name.
     * @param string $reason             Motivo de la pausa (legible, para el humano).
     * @return void
     */
    public static function notify_reseller_redi_paused( int $reseller_id, int $origin_product_id, string $origin_product_name, string $reason ): void {
        if ( ! $reseller_id ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_notifications';

        $title = sprintf(
            /* translators: %s: origin product name */
            __( 'ReDi pausado: %s', 'ltms' ),
            $origin_product_name
        );
        $message = sprintf(
            /* translators: 1: origin product name, 2: reason */
            __( 'La distribución ReDi del producto "%1$s" ha sido pausada temporalmente. Motivo: %2$s. Tus comisiones ya generadas NO se ven afectadas. Te avisaremos cuando se reanude.', 'ltms' ),
            $origin_product_name,
            $reason
        );

        // CICLO15-P1-RM-040 FIX: el INSERT en lt_notifications no se
        // verificaba. Si fallaba silenciosamente (false = error DB con
        // last_error), el reseller no recibia alerta in-app de la pausa
        // -> descubria la pausa solo cuando viera su producto outofstock
        // en la tienda, sin alerta proactiva. La notificacion es el canal
        // principal de aviso al reseller en soft pause ( GAP-10 FIX).
        // Patron recurrente Ciclos 5-14. Mismo fix estandar: capturar +
        // check false === + log critico con SQL de reconciliacion.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $notif_result = $wpdb->insert(
            $table,
            [
                'user_id'    => $reseller_id,
                'type'       => 'redi_paused',
                'channel'    => 'inapp',
                'title'      => $title,
                'message'    => $message,
                'data'       => wp_json_encode( [
                    'origin_product_id' => $origin_product_id,
                    'origin_product_name' => $origin_product_name,
                    'reason'             => $reason,
                ] ),
                'is_read'    => 0,
                'created_at' => LTMS_Utils::now_utc(),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
        );

        if ( false === $notif_result ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::critical(
                    'REDI_PAUSED_NOTIFICATION_INSERT_FAILED',
                    sprintf(
                        'Failed to insert lt_notifications (redi_paused) for reseller #%d. origin product #%d (%s). last_error=%s. El reseller no recibira alerta in-app de la pausa - solo se entera cuando vea su producto outofstock en la tienda. Reconciliacion manual: INSERT INTO %slt_notifications (user_id, type, channel, title, message, data, is_read, created_at) VALUES (%d, \'redi_paused\', \'inapp\', %s, %s, %s, 0, UTC_TIMESTAMP()).',
                        $reseller_id,
                        $origin_product_id,
                        $origin_product_name,
                        $wpdb->last_error ?: '(no error)',
                        $wpdb->prefix,
                        $reseller_id,
                        var_export( $title, true ),
                        var_export( $message, true ),
                        wp_json_encode( [ 'origin_product_id' => $origin_product_id, 'origin_product_name' => $origin_product_name, 'reason' => $reason ] )
                    ),
                    [
                        'reseller_id'          => $reseller_id,
                        'origin_product_id'    => $origin_product_id,
                        'origin_product_name'  => $origin_product_name,
                        'last_error'           => $wpdb->last_error ?: '(no error)',
                        'notif_result'         => var_export( $notif_result, true ),
                    ]
                );
            }
        }

        // Email — usar template HTML si está disponible, fallback texto plano.
        $reseller_user = get_userdata( $reseller_id );
        if ( $reseller_user && $reseller_user->user_email ) {
            $site_name = get_bloginfo( 'name' );
            $subject   = sprintf( '[%s] ⏸️ %s', $site_name, $title );

            // AUDIT-REDI-UX-GAPS GAP-8 FIX: buscar template HTML.
            $template_path = defined( 'LTMS_PLUGIN_DIR' )
                ? LTMS_PLUGIN_DIR . 'templates/emails/email-redi-listing-paused.php'
                : '';
            $email_body = '';

            if ( $template_path && file_exists( $template_path ) ) {
                $data = [
                    'origin_product_name' => $origin_product_name,
                    'reason'              => $reason,
                ];
                ob_start();
                include $template_path;
                $email_body = ob_get_clean();
            }

            if ( empty( $email_body ) ) {
                $email_body = nl2br( esc_html( $message . "\n\n--\n" . $site_name ) );
            }

            $headers = [ 'Content-Type: text/html; charset=UTF-8', 'From: ' . $site_name . ' <' . get_option( 'admin_email' ) . '>' ];
            wp_mail( $reseller_user->user_email, $subject, $email_body, $headers );
        }

        LTMS_Core_Logger::info(
            'REDI_RESELLER_NOTIFIED_PAUSED',
            sprintf(
                'Reseller #%d notified about pause of origin product #%d (%s)',
                $reseller_id, $origin_product_id, $origin_product_name
            )
        );
    }

    /**
     * Notifica a un revendedor que un producto ReDi fue re-anudado (soft resume).
     * Inserta una fila en lt_notifications y envía un email al revendedor.
     *
     * AUDIT-REDI-UX-GAPS GAP-10 FIX.
     *
     * @param int    $reseller_id         Reseller vendor user ID.
     * @param string $origin_product_name Origin vendor product name.
     * @return void
     */
    public static function notify_reseller_redi_resumed( int $reseller_id, string $origin_product_name ): void {
        if ( ! $reseller_id ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lt_notifications';

        $title = sprintf(
            /* translators: %s: origin product name */
            __( 'ReDi reanudado: %s', 'ltms' ),
            $origin_product_name
        );
        $message = sprintf(
            /* translators: %s: origin product name */
            __( 'La distribución ReDi del producto "%s" ha sido reanudada. Tu copia del producto vuelve a estar visible y disponible para la venta.', 'ltms' ),
            $origin_product_name
        );

        // CICLO15-P1-RM-041 FIX: el INSERT en lt_notifications no se
        // verificaba. Si fallaba silenciosamente (false = error DB con
        // last_error), el reseller no recibia alerta in-app del resume
        // -> descubria el resume solo cuando viera su producto instock
        // de nuevo, sin alerta proactiva ( GAP-10 FIX). Tambien en
        // resume, el email se enviaba (linea 968 post-fix) pero la
        // notificacion in-app era fire-and-forget -> el reseller que
        // mira la plataforma SIN checkear email no se entera.
        // Patron recurrente Ciclos 5-14. Mismo fix estandar.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $notif_result = $wpdb->insert(
            $table,
            [
                'user_id'    => $reseller_id,
                'type'       => 'redi_resumed',
                'channel'    => 'inapp',
                'title'      => $title,
                'message'    => $message,
                'data'       => wp_json_encode( [
                    'origin_product_name' => $origin_product_name,
                ] ),
                'is_read'    => 0,
                'created_at' => LTMS_Utils::now_utc(),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' ]
        );

        if ( false === $notif_result ) {
            if ( class_exists( 'LTMS_Core_Logger' ) ) {
                LTMS_Core_Logger::critical(
                    'REDI_RESUMED_NOTIFICATION_INSERT_FAILED',
                    sprintf(
                        'Failed to insert lt_notifications (redi_resumed) for reseller #%d. origin product (%s). last_error=%s. El reseller no recibira alerta in-app del resume - solo se entera cuando vea su producto instock de nuevo en la tienda. Reconciliacion manual: INSERT INTO %slt_notifications (user_id, type, channel, title, message, data, is_read, created_at) VALUES (%d, \'redi_resumed\', \'inapp\', %s, %s, %s, 0, UTC_TIMESTAMP()).',
                        $reseller_id,
                        $origin_product_name,
                        $wpdb->last_error ?: '(no error)',
                        $wpdb->prefix,
                        $reseller_id,
                        var_export( $title, true ),
                        var_export( $message, true ),
                        wp_json_encode( [ 'origin_product_name' => $origin_product_name ] )
                    ),
                    [
                        'reseller_id'         => $reseller_id,
                        'origin_product_name' => $origin_product_name,
                        'last_error'          => $wpdb->last_error ?: '(no error)',
                        'notif_result'        => var_export( $notif_result, true ),
                    ]
                );
            }
        }

        // Email de cortesía.
        $reseller_user = get_userdata( $reseller_id );
        if ( $reseller_user && $reseller_user->user_email ) {
            $site_name = get_bloginfo( 'name' );
            $subject   = sprintf( '[%s] %s', $site_name, $title );
            $body      = $message . "\n\n--\n" . $site_name;
            $headers   = [ 'Content-Type: text/plain; charset=UTF-8' ];

            wp_mail( $reseller_user->user_email, $subject, $body, $headers );
        }

        LTMS_Core_Logger::info(
            'REDI_RESELLER_NOTIFIED_RESUMED',
            sprintf(
                'Reseller #%d notified about resume of origin product (%s)',
                $reseller_id, $origin_product_name
            )
        );
    }

    /**
     * AJAX handler: pausa soft de un producto ReDi.
     *
     * Requiere nonce 'ltms_dashboard_nonce' y capability vendor (edit_products)
     * o administrador (manage_woocommerce).
     *
     * @return void  (imprime JSON y muere)
     */
    public static function ajax_soft_pause(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Debe iniciar sesión', 'ltms' ) ], 401 );
        }

        $origin_product_id = isset( $_POST['origin_product_id'] )
            ? (int) sanitize_text_field( wp_unslash( $_POST['origin_product_id'] ) )
            : 0;

        if ( ! $origin_product_id ) {
            wp_send_json_error( [ 'message' => __( 'ID de producto origen inválido', 'ltms' ) ], 400 );
        }

        // Capability check: el vendedor debe ser dueño del producto origen,
        // O ser administrador del marketplace.
        $current_user_id = get_current_user_id();
        $origin_vendor   = (int) get_post_meta( $origin_product_id, '_ltms_vendor_id', true );
        if ( ! $origin_vendor ) {
            $origin_vendor = (int) get_post_field( 'post_author', $origin_product_id );
        }

        $is_admin = current_user_can( 'manage_woocommerce' );
        if ( ! $is_admin && $origin_vendor !== $current_user_id ) {
            wp_send_json_error(
                [ 'message' => __( 'No tiene permisos para pausar este producto', 'ltms' ) ],
                403
            );
        }

        $result = self::soft_pause_redi( $origin_product_id );

        if ( empty( $result['success'] ) ) {
            wp_send_json_error( $result, 500 );
        }

        wp_send_json_success( $result );
    }

    /**
     * AJAX handler: re-anuda un producto ReDi previamente pausado con soft pause.
     *
     * Requiere nonce 'ltms_dashboard_nonce' y capability vendor (edit_products)
     * o administrador (manage_woocommerce).
     *
     * @return void  (imprime JSON y muere)
     */
    public static function ajax_soft_resume(): void {
        check_ajax_referer( 'ltms_dashboard_nonce', 'nonce' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Debe iniciar sesión', 'ltms' ) ], 401 );
        }

        $origin_product_id = isset( $_POST['origin_product_id'] )
            ? (int) sanitize_text_field( wp_unslash( $_POST['origin_product_id'] ) )
            : 0;

        if ( ! $origin_product_id ) {
            wp_send_json_error( [ 'message' => __( 'ID de producto origen inválido', 'ltms' ) ], 400 );
        }

        $current_user_id = get_current_user_id();
        $origin_vendor   = (int) get_post_meta( $origin_product_id, '_ltms_vendor_id', true );
        if ( ! $origin_vendor ) {
            $origin_vendor = (int) get_post_field( 'post_author', $origin_product_id );
        }

        $is_admin = current_user_can( 'manage_woocommerce' );
        if ( ! $is_admin && $origin_vendor !== $current_user_id ) {
            wp_send_json_error(
                [ 'message' => __( 'No tiene permisos para reanudar este producto', 'ltms' ) ],
                403
            );
        }

        $result = self::soft_resume_redi( $origin_product_id );

        if ( empty( $result['success'] ) ) {
            wp_send_json_error( $result, 500 );
        }

        wp_send_json_success( $result );
    }

    /**
     * Process bulk adopt queue items (no-op stub).
     *
     * Este método está registrado como handler del hook de cola
     * 'ltms_redi_bulk_adopt_queue' para que el cron / Action Scheduler pueda
     * dispararlo. La implementación real del bulk-adopt vive en otro punto
     * del pipeline; este stub asegura que el hook siempre tenga un handler
     * válido y nunca lance 'callback does not exist'.
     *
     * @param array $batch Batch de adopciones pendientes.
     * @return void
     */
    public static function process_bulk_adopt_queue( array $batch = [] ): void {
        if ( empty( $batch ) ) {
            return;
        }

        foreach ( $batch as $item ) {
            $reseller_id        = isset( $item['reseller_id'] ) ? (int) $item['reseller_id'] : 0;
            $origin_product_id  = isset( $item['origin_product_id'] ) ? (int) $item['origin_product_id'] : 0;
            $override_rate      = isset( $item['override_rate'] ) ? (float) $item['override_rate'] : -1.0;

            if ( ! $reseller_id || ! $origin_product_id ) {
                continue;
            }

            self::adopt_product( $reseller_id, $origin_product_id, $override_rate );
        }
    }
}
