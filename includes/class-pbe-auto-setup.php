<?php
/**
 * Auto Setup – No external plugin dependencies
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBE_Auto_Setup {

    public function __construct() {
        add_action( 'wp_ajax_pbe_manual_run_setup', array( $this, 'ajax_run_setup' ) );
    }

    public function ajax_run_setup() {
        check_ajax_referer( 'pbe_sync_nonce', 'security' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        self::run_activation_setup();
        wp_send_json_success( 'Auto-Setup complete! Core settings and Home page have been initialized.' );
    }

    /**
     * Auto sets up default options and the Home page on plugin activation.
     */
    public static function run_activation_setup() {

        // Ensure default settings exist
        if ( ! get_option( 'pbe_active_platform' ) ) {
            update_option( 'pbe_active_platform', 'guesty' );
        }
        if ( ! get_option( 'pbe_sync_source' ) ) {
            update_option( 'pbe_sync_source', 'all' );
        }
        if ( ! get_option( 'pbe_auto_sync_schedule' ) ) {
            update_option( 'pbe_auto_sync_schedule', 'manual' );
        }

        // Check if Home page already exists
        $home_page = get_page_by_title( 'Home' );
        $home_id   = 0;

        if ( ! $home_page ) {
            $home_content = "<!-- wp:paragraph -->\n<p>Welcome to our Vacation Rental platform.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n[property_search]\n<!-- /wp:shortcode -->\n\n<!-- wp:shortcode -->\n[pbe_featured_properties limit=\"6\"]\n<!-- /wp:shortcode -->";

            $page_args = array(
                'post_title'   => 'Home',
                'post_name'    => 'home',
                'post_content' => $home_content,
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_author'  => 1,
            );

            $home_id = wp_insert_post( $page_args );
        } else {
            $home_id = $home_page->ID;

            if ( strpos( $home_page->post_content, '[property_search]' ) === false ) {
                $updated_content = $home_page->post_content . "\n\n[property_search]";
                wp_update_post( array(
                    'ID'           => $home_id,
                    'post_content' => $updated_content,
                ) );
            }
        }

        // Set 'Home' as the Front Page
        if ( $home_id > 0 ) {
            update_option( 'show_on_front', 'page' );
            update_option( 'page_on_front', $home_id );
        }
    }
}
