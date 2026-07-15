<?php
/**
 * LTMS Rating Summary — Resumen de valoraciones con progress bars.
 *
 * Reemplaza la tab de reviews nativa de WC con un summary premium:
 *  - Promedio general grande con estrellas
 *  - Progress bars por estrellas (5★ ████░ 4★ ██░░░ ...)
 *  - Conteo total de reviews
 *  - Filtros por rating
 *
 * @package LTMS
 * @version 2.9.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class LTMS_Rating_Summary {

    public static function init(): void {
        // Insertar summary antes de los reviews.
        add_action( 'woocommerce_product_reviews_heading', [ __CLASS__, 'render_summary' ], 5 );

        // AJAX: filtrar reviews por rating.
        add_action( 'wp_ajax_ltms_filter_reviews', [ __CLASS__, 'ajax_filter_reviews' ] );
        add_action( 'wp_ajax_nopriv_ltms_filter_reviews', [ __CLASS__, 'ajax_filter_reviews' ] );
    }

    /**
     * Renderiza el summary de ratings con progress bars.
     */
    public static function render_summary(): void {
        global $product;
        if ( ! $product ) return;

        $product_id = $product->get_id();
        $average = (float) $product->get_average_rating();
        $total_reviews = (int) $product->get_rating_count();

        if ( $total_reviews === 0 ) return;

        // Contar reviews por estrellas.
        $rating_counts = self::get_rating_counts( $product_id );

        // Distribución de 5 a 1 estrellas.
        $distribution = [];
        for ( $i = 5; $i >= 1; $i-- ) {
            $count = $rating_counts[ $i ] ?? 0;
            $pct = $total_reviews > 0 ? round( ( $count / $total_reviews ) * 100 ) : 0;
            $distribution[] = [
                'stars' => $i,
                'count' => $count,
                'pct'   => $pct,
            ];
        }

        // Porcentaje de recomendación (4★ + 5★).
        $positive = ( $rating_counts[5] ?? 0 ) + ( $rating_counts[4] ?? 0 );
        $recommend_pct = $total_reviews > 0 ? round( ( $positive / $total_reviews ) * 100 ) : 0;
        ?>
        <div class="ltms-rating-summary" style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:24px;padding:20px;background:#f9fafb;border-radius:12px;">
            <!-- Promedio general -->
            <div style="text-align:center;min-width:140px;">
                <div class="ltms-rating-summary__average" style="font-size:48px;font-weight:800;color:#1f2937;line-height:1;">
                    <?php echo esc_html( number_format( $average, 1 ) ); ?>
                </div>
                <div class="ltms-rating-summary__stars" style="margin:4px 0;">
                    <?php echo wc_get_rating_html( $average ); // phpcs:ignore ?>
                </div>
                <div class="ltms-rating-summary__count" style="font-size:12px;color:#6b7280;">
                    <?php echo esc_html( sprintf( _n( '%d reseña', '%d reseñas', $total_reviews, 'ltms' ), $total_reviews ) ); ?>
                </div>
                <?php if ( $recommend_pct > 0 ) : ?>
                    <div class="ltms-rating-summary__recommend" style="margin-top:8px;padding:4px 10px;background:#f0fdf4;border-radius:20px;font-size:11px;color:#16a34a;font-weight:600;">
                        &#x1F44D; <?php echo esc_html( sprintf( __( '%d%% recomienda', 'ltms' ), $recommend_pct ) ); ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Progress bars -->
            <div style="flex:1;min-width:250px;">
                <?php foreach ( $distribution as $d ) : ?>
                    <div class="ltms-rating-bar" style="display:flex;align-items:center;gap:8px;margin-bottom:6px;cursor:pointer;"
                         data-rating="<?php echo esc_attr( $d['stars'] ); ?>">
                        <span style="font-size:12px;min-width:30px;color:#6b7280;">
                            <?php echo esc_html( $d['stars'] ); ?>★
                        </span>
                        <div class="ltms-rating-bar__track" style="flex:1;height:8px;background:#e5e7eb;border-radius:4px;overflow:hidden;">
                            <div class="ltms-rating-bar__fill"
                                 style="height:100%;width:<?php echo esc_attr( $d['pct'] ); ?>%;background:linear-gradient(90deg,#fbbf24,#f59e0b);border-radius:4px;transition:width 0.5s;"></div>
                        </div>
                        <span style="font-size:12px;min-width:30px;text-align:right;color:#6b7280;">
                            <?php echo esc_html( $d['count'] ); ?>
                        </span>
                    </div>
                <?php endforeach; ?>

                <!-- Botón "Ver todas" -->
                <div style="margin-top:8px;">
                    <button type="button"
                            style="font-size:11px;color:#2563eb;background:none;border:none;cursor:pointer;text-decoration:underline;">
                        <?php esc_html_e( 'Ver todas las reseñas', 'ltms' ); ?>
                    </button>
                </div>
            </div>
        </div>

        <script>
        // v2.9.120 REVIEWS-AUDIT P0-4 FIX: removed inline onclick handlers (CSP violation).
        // Now uses event delegation via jQuery on document ready.
        (function($) {
            'use strict';
            $(function() {
                var nonce = '<?php echo esc_js( wp_create_nonce( 'ltms_filter_reviews' ) ); ?>';
                var productId = <?php echo esc_js( $product_id ); ?>;
                var ajaxUrl = (typeof ltmsDrawerData !== 'undefined') ? ltmsDrawerData.ajaxUrl : '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';

                function filterReviews(rating) {
                    $.post(ajaxUrl, {
                        action: 'ltms_filter_reviews',
                        nonce: nonce,
                        product_id: productId,
                        rating: rating
                    }, function(response) {
                        if (response.success) {
                            $('#comments .commentlist').html(response.data.html);
                        }
                    });
                }

                $('.ltms-rating-bar').on('click', function() {
                    filterReviews($(this).data('rating'));
                });
                $('.ltms-show-all-reviews').on('click', function() {
                    filterReviews(0);
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * Obtiene el conteo de ratings por estrellas.
     */
    private static function get_rating_counts( int $product_id ): array {
        global $wpdb;
        $counts = [];
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT meta_value as rating, COUNT(*) as count
             FROM {$wpdb->comments} c
             INNER JOIN {$wpdb->commentmeta} cm ON cm.comment_id = c.comment_ID AND cm.meta_key = 'rating'
             WHERE c.comment_post_ID = %d AND c.comment_approved = 1
             GROUP BY meta_value",
            $product_id
        ) );

        if ( $results ) {
            foreach ( $results as $r ) {
                $counts[ (int) $r->rating ] = (int) $r->count;
            }
        }
        return $counts;
    }

    /**
     * AJAX: filtra reviews por rating.
     */
    public static function ajax_filter_reviews(): void {
        check_ajax_referer( 'ltms_filter_reviews', 'nonce' );
        $product_id = (int) ( $_POST['product_id'] ?? 0 );
        $rating = (int) ( $_POST['rating'] ?? 0 );

        // v2.9.120 REVIEWS-AUDIT P1-3 FIX: validate rating against allowlist [0-5].
        if ( $rating < 0 || $rating > 5 ) {
            $rating = 0;
        }

        if ( ! $product_id ) wp_send_json_error();

        $args = [
            'post_id' => $product_id,
            'status' => 'approve',
            'type' => 'review',
        ];
        if ( $rating > 0 ) {
            $args['meta_query'] = [ [
                'key' => 'rating',
                'value' => $rating,
                'compare' => '=',
            ] ];
        }

        $comments = get_comments( $args );
        $html = '';
        if ( $comments ) {
            foreach ( $comments as $comment ) {
                $rating_val = (int) get_comment_meta( $comment->comment_ID, 'rating', true );
                $html .= '<li class="review" style="border-bottom:1px solid #e5e7eb;padding:12px 0;">';
                $html .= '<div class="ltms-review-header" style="display:flex;justify-content:space-between;margin-bottom:6px;">';
                $html .= '<strong>' . esc_html( $comment->comment_author ) . '</strong>';
                $html .= '<span style="color:#f59e0b;">' . str_repeat( '★', $rating_val ) . str_repeat( '☆', 5 - $rating_val ) . '</span>';
                $html .= '</div>';
                $html .= '<p style="font-size:13px;color:#4b5563;margin:0;">' . esc_html( $comment->comment_content ) . '</p>';
                $html .= '<div style="font-size:11px;color:#9ca3af;margin-top:4px;">' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $comment->comment_date ) ) ) . '</div>';
                $html .= '</li>';
            }
        } else {
            $html = '<li style="padding:20px;text-align:center;color:#9ca3af;">' . esc_html__( 'No hay reseñas con este rating.', 'ltms' ) . '</li>';
        }

        wp_send_json_success( [ 'html' => $html ] );
    }
}
