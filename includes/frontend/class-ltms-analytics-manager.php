<?php
/**
 * LTMS Analytics Manager
 *
 * GTM + GA4 + Meta Pixel. Dos niveles: plataforma y por tienda vendedor.
 * Eventos ecommerce GA4: view_item, add_to_cart, begin_checkout, purchase.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @version    2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Analytics_Manager
 */
class LTMS_Analytics_Manager {

    private static bool $initialized = false;

    public static function init(): void {
        if ( self::$initialized ) return;
        self::$initialized = true;

        $gtm_id = LTMS_Core_Config::get( 'ltms_google_tag_manager_id', '' );
        if ( $gtm_id ) {
            add_action( 'wp_head',      [ self::class, 'inject_gtm_head' ], 1 );
            add_action( 'wp_body_open', [ self::class, 'inject_gtm_body' ], 1 );
        } else {
            add_action( 'wp_head', [ self::class, 'inject_ga4' ],        5 );
            add_action( 'wp_head', [ self::class, 'inject_meta_pixel' ], 6 );
        }

        add_action( 'wp_head',              [ self::class, 'inject_vendor_pixels' ],        10 );
        add_action( 'wp_footer',            [ self::class, 'inject_datalayer_events' ],     20 );
        add_action( 'woocommerce_thankyou', [ self::class, 'push_purchase_event' ],         10, 1 );
        add_action( 'woocommerce_add_to_cart', [ self::class, 'queue_add_to_cart_event' ], 10, 6 );
    }

    public static function inject_gtm_head(): void {
        try {
            $gtm_id = LTMS_Core_Config::get( 'ltms_google_tag_manager_id', '' );
            if ( ! $gtm_id ) return;
            // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
            echo "<!-- Google Tag Manager -->\n<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','" . esc_js( $gtm_id ) . "');</script>\n<!-- End Google Tag Manager -->\n";
            // phpcs:enable
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Analytics: GTM head failed — ' . $e->getMessage() );
        }
    }

    public static function inject_gtm_body(): void {
        try {
            $gtm_id = LTMS_Core_Config::get( 'ltms_google_tag_manager_id', '' );
            if ( ! $gtm_id ) return;
            echo "<!-- Google Tag Manager (noscript) -->\n<noscript><iframe src=\"https://www.googletagmanager.com/ns.html?id=" . esc_attr( $gtm_id ) . "\" height=\"0\" width=\"0\" style=\"display:none;visibility:hidden\"></iframe></noscript>\n<!-- End Google Tag Manager (noscript) -->\n";
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Analytics: GTM body failed — ' . $e->getMessage() );
        }
    }

    public static function inject_ga4(): void {
        try {
            $ga4_id = LTMS_Core_Config::get( 'ltms_ga4_measurement_id', '' );
            if ( ! $ga4_id ) return;
            echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . esc_attr( $ga4_id ) . '"></script>' . "\n";
            echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','" . esc_js( $ga4_id ) . "');</script>\n";
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Analytics: GA4 failed — ' . $e->getMessage() );
        }
    }

    public static function inject_meta_pixel(): void {
        try {
            $pixel_id = LTMS_Core_Config::get( 'ltms_meta_pixel_id', '' );
            if ( ! $pixel_id ) return;
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo "<!-- Meta Pixel -->\n<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init','" . esc_js( $pixel_id ) . "');fbq('track','PageView');</script>\n<!-- End Meta Pixel -->\n";
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Analytics: Meta Pixel failed — ' . $e->getMessage() );
        }
    }

    public static function inject_vendor_pixels(): void {
        try {
            if ( ! is_singular( 'product' ) ) return;
            $vendor_id  = (int) get_post_field( 'post_author', get_the_ID() );
            $vendor_ga4 = $vendor_id ? get_user_meta( $vendor_id, 'ltms_vendor_ga4_id', true ) : '';
            if ( $vendor_ga4 ) {
                echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . esc_attr( $vendor_ga4 ) . '"></script>' . "\n";
                echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','" . esc_js( $vendor_ga4 ) . "');</script>\n";
            }
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Analytics: vendor pixels failed — ' . $e->getMessage() );
        }
    }

    public static function queue_add_to_cart_event( string $cart_item_key, int $product_id, int $quantity, int $variation_id, array $variation, array $cart_item_data ): void {
        try {
            $product = wc_get_product( $variation_id ?: $product_id );
            if ( ! $product || ! WC()->session ) return;
            $events   = (array) WC()->session->get( 'ltms_datalayer_events', [] );
            $events[] = [ 'event' => 'add_to_cart', 'ecommerce' => [ 'currency' => get_woocommerce_currency(), 'value' => (float) $product->get_price() * $quantity, 'items' => [ [ 'item_id' => $product->get_id(), 'item_name' => $product->get_name(), 'price' => (float) $product->get_price(), 'quantity' => $quantity ] ] ] ];
            WC()->session->set( 'ltms_datalayer_events', $events );
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Analytics: queue_add_to_cart failed — ' . $e->getMessage() );
        }
    }

    public static function inject_datalayer_events(): void {
        try {
            if ( ! WC()->session ) return;
            $events = (array) WC()->session->get( 'ltms_datalayer_events', [] );
            if ( empty( $events ) ) return;
            echo '<script>window.dataLayer=window.dataLayer||[];';
            foreach ( $events as $event ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo 'dataLayer.push(' . wp_json_encode( $event, JSON_UNESCAPED_UNICODE ) . ');';
            }
            echo '</script>' . "\n";
            WC()->session->set( 'ltms_datalayer_events', [] );
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Analytics: inject_datalayer failed — ' . $e->getMessage() );
        }
    }

    public static function push_purchase_event( int $order_id ): void {
        try {
            $order = wc_get_order( $order_id );
            if ( ! $order ) return;
            $items = [];
            foreach ( $order->get_items() as $item ) {
                $product = $item->get_product();
                $items[] = [ 'item_id' => $item->get_product_id(), 'item_name' => $item->get_name(), 'price' => (float) ( $product ? $product->get_price() : 0 ), 'quantity' => $item->get_quantity() ];
            }
            $event = [ 'event' => 'purchase', 'ecommerce' => [ 'transaction_id' => (string) $order_id, 'value' => (float) $order->get_total(), 'currency' => $order->get_currency(), 'shipping' => (float) $order->get_shipping_total(), 'tax' => (float) $order->get_total_tax(), 'items' => $items ] ];
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '<script>window.dataLayer=window.dataLayer||[];dataLayer.push(' . wp_json_encode( $event, JSON_UNESCAPED_UNICODE ) . ');</script>' . "\n";
        } catch ( \Throwable $e ) {
            error_log( 'LTMS Analytics: push_purchase failed — ' . $e->getMessage() );
        }
    }
}
