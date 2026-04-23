<?php
/**
 * PBE Template Loader
 *
 * Intercepts WordPress template resolution for the 'property' CPT and
 * serves templates from the plugin's /templates/ directory.
 *
 * Theme override: place files in your-theme/pbe-templates/ to override.
 *
 * Also registers "Property Listing" as a selectable WordPress Page Template
 * so editors can assign it to any WP Page from the admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PBE_Template_Loader {

    /** Directory inside the plugin that holds template files */
    const TEMPLATE_DIR = 'templates/';

    /** Directory inside a theme that overrides plugin templates */
    const THEME_OVERRIDE_DIR = 'pbe-templates/';

    /** Slug used to register the Page Template */
    const PAGE_TEMPLATE_SLUG = 'pbe-property-listing.php';

    public function __construct() {
        // Front-end template routing
        add_filter( 'template_include', array( $this, 'load_plugin_template' ), 99 );

        // Register page template in the WP admin dropdown
        add_filter( 'theme_page_templates',       array( $this, 'register_page_template' ) );
        add_filter( 'page_template',              array( $this, 'load_page_template' ) );

        // Enqueue plugin stylesheet on property-related pages
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

        // Enforce platform isolation (hide properties from inactive platforms)
        add_action( 'template_redirect', array( $this, 'enforce_platform_isolation' ) );
    }

    // ─────────────────────────────────────────────────────────────────
    // TEMPLATE ROUTING
    // ─────────────────────────────────────────────────────────────────

    /**
     * Intercepts template_include for the 'property' CPT.
     */
    public function load_plugin_template( string $template ): string {
        // Single property
        if ( is_singular( 'property' ) ) {
            $plugin_tpl = $this->locate_template( 'single-property.php' );
            if ( $plugin_tpl ) {
                return $plugin_tpl;
            }
        }


        return $template;
    }
    /**
     * Prevents direct URL access to properties from inactive platforms.
     * Redirects to the main Property Listing page if a property doesn't match the active platform.
     */
    public function enforce_platform_isolation() {
        if ( is_singular( 'property' ) ) {
            $active_platform   = get_option( 'pbe_active_platform', 'guesty' );
            $property_platform = get_post_meta( get_the_ID(), 'platform_source', true );

            // If the property has a source platform and it's not the active one, redirect.
            if ( $property_platform && $property_platform !== $active_platform ) {
                $redirect_url = $this->get_listing_page_url();
                wp_safe_redirect( $redirect_url );
                exit;
            }
        }
    }

    /**
     * Finds the URL of the page using the "Property Listing (PBE)" template.
     * Fallback to the site home URL.
     */
    private function get_listing_page_url() {
        $listing_query = new WP_Query( array(
            'post_type'      => 'page',
            'meta_key'       => '_wp_page_template',
            'meta_value'     => self::PAGE_TEMPLATE_SLUG,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ) );

        if ( ! empty( $listing_query->posts ) ) {
            return get_permalink( $listing_query->posts[0] );
        }

        return home_url( '/' );
    }

    /**
     * Locate a template, respecting theme overrides.
     * Returns the resolved absolute path or empty string.
     */
    private function locate_template( string $filename ): string {
        // 1. Check active theme's override directory
        $theme_file = get_stylesheet_directory() . '/' . self::THEME_OVERRIDE_DIR . $filename;
        if ( file_exists( $theme_file ) ) {
            return $theme_file;
        }

        // 2. Fall back to plugin's own template
        $plugin_file = PBE_PLUGIN_DIR . self::TEMPLATE_DIR . $filename;
        if ( file_exists( $plugin_file ) ) {
            return $plugin_file;
        }

        return '';
    }

    // ─────────────────────────────────────────────────────────────────
    // PAGE TEMPLATE REGISTRATION
    // ─────────────────────────────────────────────────────────────────

    /**
     * Add "Property Listing" to the Page Template dropdown in the WP editor.
     */
    public function register_page_template( array $templates ): array {
        $templates[ self::PAGE_TEMPLATE_SLUG ] = 'Property Listing (PBE)';
        return $templates;
    }

    /**
     * When a WP Page has the PBE page template selected, serve the plugin's
     * property-listing.php instead of looking in the active theme.
     */
    public function load_page_template( string $template ): string {
        if ( ! is_page() ) {
            return $template;
        }

        $assigned = get_page_template_slug( get_the_ID() );
        if ( $assigned !== self::PAGE_TEMPLATE_SLUG ) {
            return $template;
        }

        $plugin_tpl = $this->locate_template( 'property-listing.php' );
        return $plugin_tpl ?: $template;
    }

    // ─────────────────────────────────────────────────────────────────
    // FRONTEND ASSETS
    // ─────────────────────────────────────────────────────────────────

    public function enqueue_frontend_assets() {
        if ( ! $this->is_property_page() ) {
            return;
        }

        // Google Font — only if one of the preset font options is selected
        $font_family = PBE_Appearance::get( 'pbe_font_family' );
        $gfont_map   = array(
            'Inter'      => 'Inter:wght@400;600;700;800',
            'Roboto'     => 'Roboto:wght@400;500;700',
            'Outfit'     => 'Outfit:wght@400;600;700;800',
            'Poppins'    => 'Poppins:wght@400;600;700',
            'Lato'       => 'Lato:wght@400;700',
            'Open Sans'  => 'Open+Sans:wght@400;600;700',
            'Montserrat' => 'Montserrat:wght@400;600;700',
        );
        $font_name = strtok( $font_family, ',' );
        if ( isset( $gfont_map[ $font_name ] ) ) {
            wp_enqueue_style(
                'pbe-google-font',
                'https://fonts.googleapis.com/css2?family=' . $gfont_map[ $font_name ] . '&display=swap',
                array(),
                null
            );
        }

        wp_enqueue_style( 'dashicons' );
        // Main frontend stylesheet
        $css_ver = file_exists( PBE_PLUGIN_DIR . 'assets/css/pbe-frontend.css' ) ? filemtime( PBE_PLUGIN_DIR . 'assets/css/pbe-frontend.css' ) : '2.1.3';
        wp_enqueue_style(
            'pbe-frontend',
            PBE_PLUGIN_URL . 'assets/css/pbe-frontend.css',
            array(),
            $css_ver . '.3'
        );

        // Flatpickr — used ONLY for the global search form
        wp_enqueue_style( 'flatpickr-css', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css', array(), '4.6.13' );
        wp_enqueue_script( 'flatpickr-js', 'https://cdn.jsdelivr.net/npm/flatpickr', array(), '4.6.13', true );

        // Litepicker — premium range picker for the booking widget
        wp_enqueue_style( 'litepicker-css', 'https://cdn.jsdelivr.net/npm/litepicker/dist/css/litepicker.css', array(), '2.0.12' );
        wp_enqueue_script( 'litepicker-js', 'https://cdn.jsdelivr.net/npm/litepicker/dist/litepicker.js', array(), '2.0.12', true );

        // Swiper.js (Modern Slider)
        wp_enqueue_style( 'swiper-css', 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css', array(), '11.0.0' );
        wp_enqueue_script( 'swiper-js', 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js', array(), '11.0.0', true );

        // GLightbox (Lightweight Lightbox)
        wp_enqueue_style( 'glightbox-css', 'https://cdn.jsdelivr.net/npm/glightbox/dist/css/glightbox.min.css', array(), '3.3.0' );
        wp_enqueue_script( 'glightbox-js', 'https://cdn.jsdelivr.net/npm/glightbox/dist/js/glightbox.min.js', array(), '3.3.0', true );

        // Main frontend script (gallery, lightbox, search form, reviews)
        $js_ver = file_exists( PBE_PLUGIN_DIR . 'assets/js/pbe-frontend.js' ) ? filemtime( PBE_PLUGIN_DIR . 'assets/js/pbe-frontend.js' ) : '2.2.0';
        wp_enqueue_script(
            'pbe-frontend-js',
            PBE_PLUGIN_URL . 'assets/js/pbe-frontend.js',
            array( 'jquery', 'flatpickr-js' ),
            $js_ver,
            true
        );

        wp_localize_script( 'pbe-frontend-js', 'pbe_ajax', array(
            'url' => admin_url( 'admin-ajax.php' )
        ) );

        // Booking Widget — modular assets, only on single property pages
        if ( is_singular( 'property' ) ) {
            $widget_type = get_option('pbe_default_booking_widget', 'standard');

            if ( $widget_type === 'native' ) {
                // Native Widget Assets (registered in PBE_Shortcodes)
                wp_enqueue_style( 'litepicker-css' );
                wp_enqueue_style( 'pbe-native-booking' );
                wp_enqueue_script( 'litepicker-js' );
                wp_enqueue_script( 'pbe-native-booking-js' );

                $stripe_mode = get_option('pbe_stripe_mode', 'test');
                $pub_key = ($stripe_mode === 'live') ? get_option('pbe_stripe_live_pub') : get_option('pbe_stripe_test_pub');
                
                wp_localize_script( 'pbe-native-booking-js', 'pbe_ajax', array(
                    'url'        => admin_url( 'admin-ajax.php' ),
                    'stripe_key' => $pub_key,
                    'currency'   => get_post_meta( get_the_ID(), 'currency', true ) ?: 'USD'
                ) );
            } else {
                // Standard Widget Assets
                $bw_css_ver = file_exists( PBE_PLUGIN_DIR . 'assets/css/pbe-booking-widget.css' ) ? filemtime( PBE_PLUGIN_DIR . 'assets/css/pbe-booking-widget.css' ) : '1.0.0';
                wp_enqueue_style(
                    'pbe-booking-widget-css',
                    PBE_PLUGIN_URL . 'assets/css/pbe-booking-widget.css',
                    array( 'pbe-frontend', 'litepicker-css' ),
                    $bw_css_ver
                );

                $bw_js_ver = file_exists( PBE_PLUGIN_DIR . 'assets/js/pbe-booking-widget.js' ) ? filemtime( PBE_PLUGIN_DIR . 'assets/js/pbe-booking-widget.js' ) : '1.0.0';
                wp_enqueue_script(
                    'pbe-booking-widget-js',
                    PBE_PLUGIN_URL . 'assets/js/pbe-booking-widget.js',
                    array( 'jquery', 'litepicker-js' ),
                    $bw_js_ver,
                    true
                );

                wp_localize_script( 'pbe-booking-widget-js', 'pbe_ajax', array(
                    'url' => admin_url( 'admin-ajax.php' )
                ) );
            }
        }

        // Leaflet (map library) — CDN, loaded only on property pages
        wp_enqueue_style(
            'leaflet-css',
            'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
            array(),
            '1.9.4'
        );
        wp_enqueue_script(
            'leaflet-js',
            'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
            array(),
            '1.9.4',
            true
        );
 
        // Leaflet Fullscreen
        wp_enqueue_style(
            'leaflet-fullscreen-css',
            'https://unpkg.com/leaflet-fullscreen@1.0.2/dist/leaflet.fullscreen.css',
            array( 'leaflet-css' ),
            '1.0.2'
        );
        wp_enqueue_script(
            'leaflet-fullscreen-js',
            'https://unpkg.com/leaflet-fullscreen@1.0.2/dist/Leaflet.fullscreen.min.js',
            array( 'leaflet-js' ),
            '1.0.2',
            true
        );

        // Leaflet MarkerCluster
        wp_enqueue_style(
            'leaflet-cluster-css',
            'https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.css',
            array( 'leaflet-css' ),
            '1.4.1'
        );
        wp_enqueue_style(
            'leaflet-cluster-theme',
            'https://unpkg.com/leaflet.markercluster@1.4.1/dist/MarkerCluster.Default.css',
            array( 'leaflet-cluster-css' ),
            '1.4.1'
        );
        wp_enqueue_script(
            'leaflet-cluster-js',
            'https://unpkg.com/leaflet.markercluster@1.4.1/dist/leaflet.markercluster.js',
            array( 'leaflet-js' ),
            '1.4.1',
            true
        );

        // Plugin map script
        wp_enqueue_script(
            'pbe-map-js',
            PBE_PLUGIN_URL . 'assets/js/pbe-map.js',
            array( 'leaflet-js' ),
            '2.0.0',
            true
        );
    }

    /**
     * Returns true when we are on any PBE-owned page.
     */
    private function is_property_page(): bool {
        if ( is_singular( 'property' ) ) {
            return true;
        }
        
        global $post;
        if ( is_page() ) {
            // Case 1: Page template assigned
            $assigned = get_page_template_slug( get_the_ID() );
            if ( $assigned === self::PAGE_TEMPLATE_SLUG ) {
                return true;
            }
            
            // Case 2: Shortcodes in content
            if ( is_a( $post, 'WP_Post' ) ) {
                if ( has_shortcode( $post->post_content, 'property_search' ) || 
                     has_shortcode( $post->post_content, 'pbe_booking_widget' ) || 
                     has_shortcode( $post->post_content, 'pbe_native_booking_widget' ) || 
                     has_shortcode( $post->post_content, 'pbe_featured_properties' ) ) {
                    return true;
                }
            }
        }
        
        // Also check if we are on the home page and it's a static page
        if ( is_front_page() && is_a( $post, 'WP_Post' ) ) {
             if ( has_shortcode( $post->post_content, 'property_search' ) || 
                  has_shortcode( $post->post_content, 'pbe_featured_properties' ) ) {
                return true;
            }
        }

        return false;
    }
    /**
     * Load an SVG icon from the plugin assets.
     */
    public static function get_svg( string $name ): string {
        $file = PBE_PLUGIN_DIR . 'assets/images/icons/' . $name . '.svg';
        if ( file_exists( $file ) ) {
            return file_get_contents( $file );
        }
        return '';
    }
}
