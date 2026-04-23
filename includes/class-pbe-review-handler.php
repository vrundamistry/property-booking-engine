<?php
/**
 * Handles AJAX review submissions
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBE_Review_Handler {

    public function __construct() {
        add_action('wp_ajax_pbe_submit_review', array($this, 'handle_submit_review'));
        add_action('wp_ajax_nopriv_pbe_submit_review', array($this, 'handle_submit_review'));
        
        add_action('wp_ajax_pbe_get_reviews_page', array($this, 'handle_get_reviews_page'));
        add_action('wp_ajax_nopriv_pbe_get_reviews_page', array($this, 'handle_get_reviews_page'));
    }

    /**
     * AJAX: Get a specific page of reviews
     */
    public function handle_get_reviews_page() {
        $property_id = isset($_POST['property_id']) ? intval($_POST['property_id']) : 0;
        $page        = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page    = 5;

        if ( !$property_id ) {
            wp_send_json_error();
        }

        $reviews_query = new WP_Query(array(
            'post_type'      => 'pbe_review',
            'post_status'    => 'publish',
            'post_parent'    => $property_id,
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'date',
            'order'          => 'DESC'
        ));

        $html = '';
        if ( $reviews_query->have_posts() ) {
            foreach ( $reviews_query->posts as $rev ) {
                $html .= $this->render_review_card_html($rev);
            }
        }

        wp_send_json_success(array(
            'html'        => $html,
            'current'     => $page,
            'total_pages' => $reviews_query->max_num_pages
        ));
    }

    /**
     * Helper to render a single review card HTML
     */
    public function render_review_card_html($rev) {
        $rating = (float) get_post_meta($rev->ID, 'pbe_rating', true);
        $source = get_post_meta($rev->ID, 'pbe_source', true);
        $review_title = get_post_meta($rev->ID, 'pbe_review_title', true);
        $date   = get_the_date('F Y', $rev->ID);
        $title_initial = strtoupper(substr($rev->post_title, 0, 1));

        $stars_html = '';
        for($i=1; $i<=5; $i++) {
            $class = ($i <= round($rating)) ? 'filled' : '';
            $stars_html .= '<span class="pbe-star ' . $class . '">★</span>';
        }

        ob_start();
        ?>
        <div class="pbe-review-card">
            <div class="pbe-review-card-header">
                <div class="pbe-reviewer-info">
                    <div class="pbe-reviewer-avatar-placeholder"><?php echo $title_initial; ?></div>
                    <div>
                        <div class="pbe-reviewer-name"><?php echo esc_html($rev->post_title); ?></div>
                        <div class="pbe-reviewer-loc"><?php echo esc_html($date); ?> <?php echo $source ? '· ' . esc_html(ucfirst($source)) : ''; ?></div>
                    </div>
                </div>
                <div class="pbe-review-rating-stars">
                    <?php echo $stars_html; ?>
                </div>
            </div>
            <?php if ($review_title) : ?>
                <h4 class="pbe-review-card-title"><?php echo esc_html($review_title); ?></h4>
            <?php endif; ?>
            <?php $is_long = mb_strlen($rev->post_content) > 280; ?>
            <div class="pbe-review-text-wrap <?php echo $is_long ? 'pbe-review-text-truncated' : ''; ?>">
                <p class="pbe-review-text"><?php echo nl2br(esc_html($rev->post_content)); ?></p>
            </div>
            <?php if (mb_strlen($rev->post_content) > 280) : ?>
                <button type="button" class="pbe-review-read-more-btn">Read more</button>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function handle_submit_review() {
        check_ajax_referer('pbe_submit_review', 'pbe_review_nonce');

        $property_id  = isset($_POST['property_id']) ? intval($_POST['property_id']) : 0;
        $name         = isset($_POST['reviewer_name']) ? sanitize_text_field($_POST['reviewer_name']) : '';
        $email        = isset($_POST['reviewer_email']) ? sanitize_email($_POST['reviewer_email']) : '';
        $review_title = isset($_POST['review_title']) ? sanitize_text_field($_POST['review_title']) : '';
        $rating       = isset($_POST['pbe_rating']) ? floatval($_POST['pbe_rating']) : 0;
        $text         = isset($_POST['review_text']) ? wp_kses_post($_POST['review_text']) : '';

        if ( !$property_id || !$name || !$text || !$rating ) {
            wp_send_json_error(array('message' => 'Please fill in all required fields, including your rating.'));
        }

        $review_data = array(
            'post_title'   => $name,
            'post_content' => $text,
            'post_status'  => 'pending', // Requires admin approval
            'post_type'    => 'pbe_review',
            'post_parent'  => $property_id,
        );

        $review_id = wp_insert_post($review_data);

        if ( is_wp_error($review_id) ) {
            wp_send_json_error(array('message' => 'Failed to save review. Please try again.'));
        }

        update_post_meta($review_id, 'pbe_rating', $rating);
        update_post_meta($review_id, 'pbe_review_title', $review_title);
        update_post_meta($review_id, 'pbe_reviewer_email', $email);
        update_post_meta($review_id, 'pbe_source', 'Direct');
        
        $platform = isset($_POST['platform_source']) ? sanitize_text_field($_POST['platform_source']) : '';
        if ($platform) {
            update_post_meta($review_id, 'pbe_platform_source', $platform);
        }

        wp_send_json_success(array('message' => 'Thank you! Your review has been submitted and is awaiting approval.'));
    }
}
