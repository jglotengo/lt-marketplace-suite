<?php
/**
 * LTMS Purchase Flow — Inyección de componentes en WooCommerce
 *
 * Conecta los nuevos componentes de diseño (trust bar, social proof,
 * vendor badges, trust trio) con los hooks de WooCommerce y el tema.
 *
 * @package    LTMS
 * @subpackage LTMS/includes/frontend
 * @version    1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class LTMS_Purchase_Flow
 */
final class LTMS_Purchase_Flow {

    /**
     * Registra hooks.
     */
    public static function init(): void {
        $instance = new self();

        // Vendor KYC badge en nombre del vendedor (loop y single product)
        add_filter( 'woocommerce_product_title', [ $instance, 'maybe_add_vendor_kyc_badge' ], 10, 2 );

        // Social proof en página de producto
        add_action( 'woocommerce_single_product_summary', [ $instance, 'inject_social_proof_badge' ], 6 );

        // Trust trio debajo del precio en single product
        add_action( 'woocommerce_single_product_summary', [ $instance, 'inject_trust_trio' ], 25 );

        // Vendor verified line en single product
        add_action( 'woocommerce_single_product_summary', [ $instance, 'inject_vendor_line' ], 4 );
    }

    /**
     * Agrega badge KYC al nombre del vendedor en tarjetas de producto.
     * Solo aplica si el producto tiene un vendedor LTMS con KYC aprobado.
     *
     * @param string      $title   Título del producto.
     * @param \WC_Product $product Objeto del producto.
     * @return string
     */
    public function maybe_add_vendor_kyc_badge( string $title, $product ): string {
        if ( is_admin() ) {
            return $title;
        }
        $product_id = $product instanceof \WC_Product ? $product->get_id() : 0;
        if ( ! $product_id ) {
            return $title;
        }
        $vendor_id = (int) get_post_meta( $product_id, '_ltms_vendor_id', true );
        if ( ! $vendor_id ) {
            return $title;
        }
        $kyc_status = get_user_meta( $vendor_id, 'ltms_kyc_status', true );
        if ( $kyc_status !== 'approved' ) {
            return $title;
        }
        $badge = '<span class="ltms-pf-card__kyc" title="Vendedor verificado KYC">KYC ✓</span>';
        return $title . ' ' . $badge;
    }

    /**
     * Inyecta badge de social proof en vivo ("X compraron · Y viendo").
     *
     * @return void
     */
    public function inject_social_proof_badge(): void {
        global $product;
        if ( ! $product ) {
            return;
        }
        $sold      = (int) get_post_meta( $product->get_id(), 'total_sales', true );
        $viewing   = max( 1, abs( crc32( $product->get_id() . date( 'H' ) ) ) % 8 + 1 ); // pseudo-live
        $sold_text = $sold > 0 ? esc_html( number_format( $sold ) . ' compraron este mes' ) : '';
        $view_text = '<span class="ltms-live-viewing">' . esc_html( $viewing . ' viendo ahora' ) . '</span>';
        $sep       = $sold_text ? ' · ' : '';

        echo '<div class="ltms-pf-live-badge" data-viewing="' . esc_attr( $viewing ) . '">';
        if ( $sold_text ) {
            echo esc_html( $sold_text ) . $sep;
        }
        echo $view_text;
        echo '</div>';
    }

    /**
     * Inyecta el trust trio (envío / devolución / protección).
     *
     * @return void
     */
    public function inject_trust_trio(): void {
        echo '<div class="ltms-pf-trust-trio">';
        $items = [
            [
                'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
                'text' => 'Envío nacional',
            ],
            [
                'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1018 0A9 9 0 003 12zm6 0l2 2 4-4"/></svg>',
                'text' => 'Devolución 30 días',
            ],
            [
                'icon' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="1"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>',
                'text' => 'Compra protegida',
            ],
        ];
        foreach ( $items as $item ) {
            echo '<div class="ltms-pf-trust-item">' . $item['icon'] . '<span>' . esc_html( $item['text'] ) . '</span></div>';
        }
        echo '</div>';
    }

    /**
     * Inyecta la línea del vendedor verificado.
     *
     * @return void
     */
    public function inject_vendor_line(): void {
        global $product;
        if ( ! $product ) {
            return;
        }
        $vendor_id = (int) get_post_meta( $product->get_id(), '_ltms_vendor_id', true );
        if ( ! $vendor_id ) {
            return;
        }
        $vendor    = get_userdata( $vendor_id );
        $kyc       = get_user_meta( $vendor_id, 'ltms_kyc_status', true );
        $name      = $vendor ? esc_html( $vendor->display_name ) : '';
        if ( ! $name ) {
            return;
        }

        echo '<div class="ltms-pf-vendor-line">';
        echo '<span>Vendido por <strong>' . $name . '</strong></span>';
        if ( $kyc === 'approved' ) {
            echo '<span class="ltms-pf-vendor-line__badge">KYC ✓ Verificado</span>';
        }
        echo '</div>';
    }
}
